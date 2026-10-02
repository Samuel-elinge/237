<?php
/**
 * partner/communication.php — Partner Communication Hub (Task 55)
 *
 * Partners manage all communications: messages from businesses, admin messages,
 * internal notes on leads/assignments, and broadcast announcements from 237biz.
 * Supports threaded conversations, reply, and message status tracking.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)$_SESSION['user_id'];

// ── POST handlers ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Send message / reply
    if ($action === 'send') {
        $threadId    = (int)($_POST['thread_id'] ?? 0);
        $toUserId    = (int)($_POST['to_user_id'] ?? 0);
        $subject     = trim($_POST['subject'] ?? '');
        $body        = trim($_POST['body'] ?? '');
        $contextType = in_array($_POST['context_type'] ?? '', ['lead','assignment','general']) ? $_POST['context_type'] : 'general';
        $contextId   = (int)($_POST['context_id'] ?? 0);

        if (!$body) { setFlash('error', 'Message body is required.'); redirect(SITE_URL . '/partner/communication'); }

        if ($threadId) {
            // Reply to existing thread — verify partner is a participant
            $thread = $pdo->prepare("SELECT * FROM partner_message_threads WHERE id=? AND (partner_id=? OR to_partner_id=?)");
            $thread->execute([$threadId, $partnerId, $partnerId]);
            $thread = $thread->fetch();
            if (!$thread) { setFlash('error', 'Thread not found.'); redirect(SITE_URL . '/partner/communication'); }

            $pdo->prepare("INSERT INTO partner_messages (thread_id, sender_user_id, body, created_at) VALUES (?,?,?,NOW())")
                ->execute([$threadId, $userId, $body]);

            // Mark thread updated
            $pdo->prepare("UPDATE partner_message_threads SET updated_at=NOW(), last_message_at=NOW() WHERE id=?")
                ->execute([$threadId]);

            // Notify the other participant
            $recipientUserId = ($thread['created_by_user_id'] == $userId) ? $thread['to_user_id'] : $thread['created_by_user_id'];
            if ($recipientUserId) {
                pushNotification($recipientUserId, 'New reply in: ' . $thread['subject'], SITE_URL . '/partner/communication?thread=' . $threadId);
            }

            partnerAuditLog($partnerId, 'message_reply', "Replied to thread #{$threadId}");
            setFlash('success', 'Reply sent.');
            redirect(SITE_URL . '/partner/communication?thread=' . $threadId);
        } else {
            // New thread
            if (!$subject) { setFlash('error', 'Subject is required for a new message.'); redirect(SITE_URL . '/partner/communication'); }
            if (!$toUserId && !isAdmin()) { setFlash('error', 'Recipient is required.'); redirect(SITE_URL . '/partner/communication'); }

            // Determine to_partner_id if sending to another partner
            $toPartnerId = 0;
            if ($toUserId) {
                $tp = $pdo->prepare("SELECT id FROM partner_profiles WHERE user_id=?");
                $tp->execute([$toUserId]);
                $toPartnerId = (int)($tp->fetchColumn() ?: 0);
            }

            $pdo->prepare("
                INSERT INTO partner_message_threads
                    (partner_id, created_by_user_id, to_user_id, to_partner_id, subject, context_type, context_id, created_at, updated_at, last_message_at)
                VALUES (?,?,?,?,?,?,?,NOW(),NOW(),NOW())
            ")->execute([$partnerId, $userId, $toUserId ?: null, $toPartnerId ?: null, $subject, $contextType, $contextId ?: null]);

            $threadId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO partner_messages (thread_id, sender_user_id, body, created_at) VALUES (?,?,?,NOW())")
                ->execute([$threadId, $userId, $body]);

            if ($toUserId) {
                pushNotification($toUserId, 'New message: ' . $subject, SITE_URL . '/partner/communication?thread=' . $threadId);
            }

            partnerAuditLog($partnerId, 'message_sent', "Sent new thread #{$threadId}: {$subject}");
            setFlash('success', 'Message sent.');
            redirect(SITE_URL . '/partner/communication?thread=' . $threadId);
        }
    }

    // Mark thread as read
    if ($action === 'mark_read') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $pdo->prepare("
            UPDATE partner_messages pm
            JOIN partner_message_threads t ON t.id = pm.thread_id
            SET pm.read_at = NOW()
            WHERE pm.thread_id=? AND pm.read_at IS NULL AND pm.sender_user_id != ?
              AND (t.partner_id=? OR t.to_partner_id=?)
        ")->execute([$threadId, $userId, $partnerId, $partnerId]);
        redirect(SITE_URL . '/partner/communication?thread=' . $threadId);
    }

    // Archive thread
    if ($action === 'archive') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $pdo->prepare("
            UPDATE partner_message_threads
            SET archived_by_{$partnerId}=1
            WHERE id=? AND (partner_id=? OR to_partner_id=?)
        ")->execute([$threadId, $partnerId, $partnerId]);
        setFlash('success', 'Thread archived.');
        redirect(SITE_URL . '/partner/communication');
    }

    redirect(SITE_URL . '/partner/communication');
}

// ── Views ─────────────────────────────────────────────────
$view       = $_GET['view'] ?? 'inbox';  // inbox | sent | announcements
$threadId   = (int)($_GET['thread'] ?? 0);
$searchQ    = trim($_GET['q'] ?? '');

// Unread count (for nav badge)
$unreadCount = (int)$pdo->prepare("
    SELECT COUNT(*)
    FROM partner_messages pm
    JOIN partner_message_threads t ON t.id = pm.thread_id
    WHERE pm.read_at IS NULL AND pm.sender_user_id != ?
      AND (t.partner_id=? OR t.to_partner_id=?)
")->execute([$userId, $partnerId, $partnerId]) ? $pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

// Actually compute unread count properly
$unreadQ = $pdo->prepare("
    SELECT COUNT(*)
    FROM partner_messages pm
    JOIN partner_message_threads t ON t.id = pm.thread_id
    WHERE pm.read_at IS NULL AND pm.sender_user_id != ?
      AND (t.partner_id=? OR t.to_partner_id=?)
");
$unreadQ->execute([$userId, $partnerId, $partnerId]);
$unreadCount = (int)$unreadQ->fetchColumn();

// Announcements from admin (broadcast_messages table or partner_announcements)
$announcements = [];
$annQ = $pdo->prepare("
    SELECT ba.*, u.name AS sender_name
    FROM broadcast_messages ba
    LEFT JOIN users u ON u.id = ba.sender_user_id
    WHERE (ba.target_role = 'partner' OR ba.target_role = 'all')
      AND ba.is_published = 1
    ORDER BY ba.created_at DESC
    LIMIT 20
");
$annQ->execute();
$announcements = $annQ->fetchAll();

// ── Thread list ───────────────────────────────────────────
$threadWhere  = ['(t.partner_id=? OR t.to_partner_id=?)'];
$threadParams = [$partnerId, $partnerId];

if ($view === 'sent') {
    $threadWhere  = ['t.created_by_user_id=?'];
    $threadParams = [$userId];
}

if ($searchQ) {
    $threadWhere[]  = '(t.subject LIKE ? OR pm_last.body LIKE ?)';
    $threadParams[] = '%' . $searchQ . '%';
    $threadParams[] = '%' . $searchQ . '%';
}

$threadWhereSQL = 'WHERE ' . implode(' AND ', $threadWhere);

$threads = $pdo->prepare("
    SELECT t.*,
           u_from.name AS created_by_name,
           u_to.name   AS to_name,
           COUNT(pm.id) AS message_count,
           SUM(CASE WHEN pm.read_at IS NULL AND pm.sender_user_id != $userId THEN 1 ELSE 0 END) AS unread_count
    FROM partner_message_threads t
    LEFT JOIN users u_from ON u_from.id = t.created_by_user_id
    LEFT JOIN users u_to   ON u_to.id   = t.to_user_id
    LEFT JOIN partner_messages pm ON pm.thread_id = t.id
    $threadWhereSQL
    GROUP BY t.id
    ORDER BY t.last_message_at DESC
    LIMIT 50
");
$threads->execute($threadParams);
$threads = $threads->fetchAll();

// ── Active thread ──────────────────────────────────────────
$activeThread   = null;
$threadMessages = [];

if ($threadId) {
    $at = $pdo->prepare("
        SELECT t.*, u_from.name AS created_by_name, u_to.name AS to_name
        FROM partner_message_threads t
        LEFT JOIN users u_from ON u_from.id = t.created_by_user_id
        LEFT JOIN users u_to   ON u_to.id   = t.to_user_id
        WHERE t.id=? AND (t.partner_id=? OR t.to_partner_id=?)
    ");
    $at->execute([$threadId, $partnerId, $partnerId]);
    $activeThread = $at->fetch();

    if ($activeThread) {
        $msgs = $pdo->prepare("
            SELECT pm.*, u.name AS sender_name
            FROM partner_messages pm
            LEFT JOIN users u ON u.id = pm.sender_user_id
            WHERE pm.thread_id=?
            ORDER BY pm.created_at ASC
        ");
        $msgs->execute([$threadId]);
        $threadMessages = $msgs->fetchAll();

        // Mark as read
        $pdo->prepare("UPDATE partner_messages SET read_at=NOW() WHERE thread_id=? AND read_at IS NULL AND sender_user_id!=?")
            ->execute([$threadId, $userId]);
    }
}

// ── Admins list (for new message recipient) ───────────────
$admins = $pdo->prepare("SELECT id, name, email FROM users WHERE role='admin' ORDER BY name ASC");
$admins->execute();
$admins = $admins->fetchAll();

$flash     = getFlash();
$pageTitle = 'Communication Hub';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.comm-wrap{display:grid;grid-template-columns:280px 1fr;gap:0;max-width:1100px;margin:0 auto;height:calc(100vh - 120px);border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden;background:#fff}
.comm-sidebar{border-right:1px solid #e5e7eb;display:flex;flex-direction:column;overflow:hidden}
.comm-main{display:flex;flex-direction:column;overflow:hidden}
.sidebar-header{padding:1rem;border-bottom:1px solid #e5e7eb;flex-shrink:0}
.sidebar-header h2{margin:0;font-size:1rem;font-weight:700;color:#111827}
.sidebar-nav{display:flex;gap:.25rem;margin-top:.5rem}
.snav{padding:.3rem .6rem;border-radius:.4rem;font-size:.75rem;font-weight:600;text-decoration:none;color:#6b7280}
.snav.active{background:#2563eb;color:#fff}
.thread-list{overflow-y:auto;flex:1}
.thread-item{padding:.75rem 1rem;border-bottom:1px solid #f3f4f6;cursor:pointer;text-decoration:none;display:block;color:inherit}
.thread-item:hover,.thread-item.active{background:#f0f7ff}
.thread-item.unread .t-subject{font-weight:700;color:#111827}
.t-subject{font-size:.85rem;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.t-meta{font-size:.72rem;color:#9ca3af;margin-top:.15rem;display:flex;justify-content:space-between}
.t-badge{background:#ef4444;color:#fff;border-radius:99px;padding:.1rem .35rem;font-size:.65rem;font-weight:700;margin-left:.25rem}
.search-box{padding:.5rem .75rem;border:1px solid #e5e7eb;border-radius:.4rem;font-size:.8rem;width:100%;box-sizing:border-box}
.main-header{padding:.75rem 1.1rem;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:.5rem;flex-shrink:0;flex-wrap:wrap}
.main-title{font-size:.95rem;font-weight:700;color:#111827;flex:1}
.messages-area{flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:.75rem}
.msg-bubble{max-width:75%;padding:.65rem .9rem;border-radius:.75rem;font-size:.85rem;line-height:1.5}
.msg-bubble.mine{align-self:flex-end;background:#2563eb;color:#fff;border-bottom-right-radius:.2rem}
.msg-bubble.theirs{align-self:flex-start;background:#f3f4f6;color:#374151;border-bottom-left-radius:.2rem}
.msg-meta{font-size:.65rem;margin-top:.25rem;opacity:.7}
.reply-area{border-top:1px solid #e5e7eb;padding:.75rem 1rem;flex-shrink:0}
.reply-area textarea{width:100%;border:1px solid #e5e7eb;border-radius:.45rem;padding:.5rem .75rem;font-size:.85rem;resize:vertical;min-height:70px;box-sizing:border-box;font-family:inherit}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-sm{padding:.25rem .55rem;font-size:.72rem}
.btn-ghost{background:#f3f4f6;color:#374151}
.btn:hover{opacity:.9}
.empty-state{text-align:center;padding:3rem;color:#9ca3af;flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center}
.compose-panel{padding:1.25rem;overflow-y:auto;flex:1}
.form-group{margin-bottom:.9rem}
.form-group label{display:block;font-size:.75rem;font-weight:700;color:#374151;margin-bottom:.3rem}
.form-group input,.form-group select,.form-group textarea{width:100%;border:1px solid #e5e7eb;border-radius:.4rem;padding:.45rem .65rem;font-size:.85rem;box-sizing:border-box;font-family:inherit}
.ann-card{background:#f0f7ff;border:1px solid #bfdbfe;border-radius:.6rem;padding:.75rem 1rem;margin-bottom:.5rem}
.ann-title{font-size:.875rem;font-weight:700;color:#1e40af;margin-bottom:.25rem}
.ann-body{font-size:.82rem;color:#374151;line-height:1.5}
.ann-meta{font-size:.7rem;color:#93c5fd;margin-top:.3rem}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin:.75rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
@media(max-width:700px){.comm-wrap{grid-template-columns:1fr;height:auto}.comm-sidebar{border-right:none;border-bottom:1px solid #e5e7eb;max-height:300px}}
</style>

<div style="max-width:1100px;margin:0 auto;padding:1rem 1.5rem .5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.2rem;color:#111827">Communication Hub</h1>
            <p style="margin:.15rem 0 0;font-size:.8rem;color:#6b7280">Messages, announcements and partner comms</p>
        </div>
        <a href="<?= SITE_URL ?>/partner/dashboard" class="btn btn-ghost btn-sm">← Dashboard</a>
    </div>
</div>

<?php if ($flash): ?>
<div style="max-width:1100px;margin:0 auto;padding:0 1.5rem">
<div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
</div>
<?php endif ?>

<div style="max-width:1100px;margin:.5rem auto 1.5rem;padding:0 1.5rem">
<div class="comm-wrap">

    <!-- Sidebar -->
    <div class="comm-sidebar">
        <div class="sidebar-header">
            <h2>
                Messages
                <?php if ($unreadCount > 0): ?><span class="t-badge"><?= $unreadCount ?></span><?php endif ?>
            </h2>
            <div class="sidebar-nav">
                <a href="?view=inbox" class="snav<?= $view==='inbox'?' active':'' ?>">Inbox</a>
                <a href="?view=sent" class="snav<?= $view==='sent'?' active':'' ?>">Sent</a>
                <a href="?view=announcements" class="snav<?= $view==='announcements'?' active':'' ?>">Announcements</a>
            </div>
            <div style="margin-top:.5rem">
                <form method="get" action="">
                    <input type="hidden" name="view" value="<?= e($view) ?>">
                    <input class="search-box" type="search" name="q" placeholder="Search messages…" value="<?= e($searchQ) ?>">
                </form>
            </div>
        </div>

        <?php if ($view === 'announcements'): ?>
        <div class="thread-list" style="padding:.75rem">
            <?php if (!$announcements): ?>
            <p style="text-align:center;color:#9ca3af;font-size:.8rem;padding:1rem">No announcements yet.</p>
            <?php else: ?>
            <?php foreach ($announcements as $ann): ?>
            <div class="ann-card">
                <div class="ann-title"><?= e($ann['subject'] ?? $ann['title'] ?? 'Announcement') ?></div>
                <div class="ann-body"><?= nl2br(e(mb_strimwidth($ann['body'] ?? $ann['content'] ?? '', 0, 100, '…'))) ?></div>
                <div class="ann-meta">From 237biz · <?= date('d M Y', strtotime($ann['created_at'])) ?></div>
            </div>
            <?php endforeach ?>
            <?php endif ?>
        </div>

        <?php else: ?>
        <!-- Compose button -->
        <div style="padding:.5rem .75rem;border-bottom:1px solid #f3f4f6">
            <a href="?view=inbox&compose=1" class="btn btn-primary btn-sm" style="width:100%;justify-content:center">✏️ New Message</a>
        </div>
        <div class="thread-list">
            <?php if (!$threads): ?>
            <div style="text-align:center;padding:2rem;color:#9ca3af;font-size:.8rem">No messages yet.</div>
            <?php else: ?>
            <?php foreach ($threads as $t): ?>
            <a href="?view=<?= $view ?>&thread=<?= $t['id'] ?>" class="thread-item<?= $threadId==$t['id']?' active':'' ?><?= $t['unread_count']>0?' unread':'' ?>">
                <div class="t-subject" style="display:flex;align-items:center;gap:.3rem">
                    <?= e(mb_strimwidth($t['subject'], 0, 40, '…')) ?>
                    <?php if ($t['unread_count'] > 0): ?><span class="t-badge"><?= $t['unread_count'] ?></span><?php endif ?>
                </div>
                <div class="t-meta">
                    <span><?= e($view==='sent' ? ($t['to_name'] ?? 'Admin') : ($t['created_by_name'] ?? '237biz')) ?></span>
                    <span><?= $t['last_message_at'] ? date('d M', strtotime($t['last_message_at'])) : '' ?></span>
                </div>
            </a>
            <?php endforeach ?>
            <?php endif ?>
        </div>
        <?php endif ?>
    </div>

    <!-- Main panel -->
    <div class="comm-main">
        <?php if (isset($_GET['compose'])): ?>
        <!-- Compose new message -->
        <div class="main-header">
            <div class="main-title">New Message</div>
            <a href="?view=inbox" class="btn btn-ghost btn-sm">Cancel</a>
        </div>
        <div class="compose-panel">
            <form method="post" action="">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send">
                <div class="form-group">
                    <label>To</label>
                    <select name="to_user_id" required>
                        <option value="">— Select recipient —</option>
                        <optgroup label="237biz Admin">
                        <?php foreach ($admins as $adm): ?>
                        <option value="<?= $adm['id'] ?>"><?= e($adm['name']) ?></option>
                        <?php endforeach ?>
                        </optgroup>
                    </select>
                </div>
                <div class="form-group">
                    <label>Subject</label>
                    <input type="text" name="subject" maxlength="200" required placeholder="Message subject">
                </div>
                <div class="form-group">
                    <label>Context (optional)</label>
                    <select name="context_type">
                        <option value="general">General</option>
                        <option value="lead">Lead</option>
                        <option value="assignment">Assignment</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Message</label>
                    <textarea name="body" rows="6" required placeholder="Type your message…"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Send Message</button>
            </form>
        </div>

        <?php elseif ($activeThread): ?>
        <!-- Thread view -->
        <div class="main-header">
            <div class="main-title"><?= e($activeThread['subject']) ?></div>
            <form method="post" action="" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="thread_id" value="<?= $activeThread['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Archive</button>
            </form>
        </div>
        <div class="messages-area">
            <?php foreach ($threadMessages as $msg): ?>
            <?php $isMine = ($msg['sender_user_id'] == $userId); ?>
            <div style="display:flex;flex-direction:column;align-items:<?= $isMine ? 'flex-end' : 'flex-start' ?>">
                <div class="msg-bubble <?= $isMine ? 'mine' : 'theirs' ?>">
                    <?= nl2br(e($msg['body'])) ?>
                    <div class="msg-meta">
                        <?= e($msg['sender_name'] ?? 'Unknown') ?> · <?= date('d M Y H:i', strtotime($msg['created_at'])) ?>
                        <?php if ($isMine && $msg['read_at']): ?> · Read<?php endif ?>
                    </div>
                </div>
            </div>
            <?php endforeach ?>
        </div>
        <div class="reply-area">
            <form method="post" action="">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="thread_id" value="<?= $activeThread['id'] ?>">
                <textarea name="body" placeholder="Type your reply…" required></textarea>
                <div style="margin-top:.4rem;display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary btn-sm">Send Reply</button>
                </div>
            </form>
        </div>

        <?php elseif ($view !== 'announcements'): ?>
        <div class="empty-state">
            <div style="font-size:2.5rem;margin-bottom:.75rem">💬</div>
            <p style="margin:0;font-size:.9rem">Select a conversation or start a new one</p>
            <a href="?view=inbox&compose=1" class="btn btn-primary btn-sm" style="margin-top:.75rem">✏️ New Message</a>
        </div>
        <?php endif ?>
    </div>

</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
