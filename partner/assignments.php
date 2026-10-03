<?php
/**
 * partner/assignments.php — Business Assignment Management (Task 48)
 *
 * Partners view all businesses assigned to them (active, completed, removed).
 * Shows assignment details, role, notes. Links to business listing.
 * Partners can mark assignments as completed or add notes.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$pdo = db();
$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];

// ── POST: add note / request completion ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $asgId  = (int)($_POST['assignment_id'] ?? 0);

    // Verify ownership
    $asg = $pdo->prepare("SELECT * FROM partner_business_assignments WHERE id=? AND partner_id=?");
    $asg->execute([$asgId, $partnerId]);
    $asg = $asg->fetch();

    if ($asg) {
        if ($action === 'add_note') {
            $note = trim($_POST['notes'] ?? '');
            if ($note) {
                $pdo->prepare("UPDATE partner_business_assignments SET notes=? WHERE id=?")
                    ->execute([$note, $asgId]);
                partnerAuditLog($partnerId, (int)$_SESSION['user_id'], $asg['listing_id'], 'assignment_note', 'Note updated');
                setFlash('success', 'Note saved.');
            }
        }
        if ($action === 'request_complete') {
            // Flag for admin review
            $pdo->prepare("UPDATE partner_business_assignments SET notes=CONCAT(IFNULL(notes,''),' [Completion requested by partner]') WHERE id=?")
                ->execute([$asgId]);
            // Notify admins
            $adminIds = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($adminIds as $aid) {
                pushNotification((int)$aid, 'assignment_complete_request', 'Assignment Completion Requested',
                    'A partner has requested completion of a business assignment.',
                    SITE_URL . '/admin/manage-growth-partners');
            }
            setFlash('success', 'Completion request sent to admin.');
        }
    }
    redirect(SITE_URL . '/partner/assignments?tab=' . ($_GET['tab'] ?? 'active'));
}

// ── Filters ───────────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['active','completed','removed','all']) ? $_GET['tab'] : 'active';
$search = trim($_GET['q'] ?? '');

$where  = ["pba.partner_id = ?"];
$params = [$partnerId];

if ($tab === 'active')    { $where[] = "pba.status = 'active'"; }
elseif ($tab === 'completed') { $where[] = "pba.status = 'completed'"; }
elseif ($tab === 'removed')   { $where[] = "pba.status = 'removed'"; }
// 'all' → no status filter

if ($search) {
    $where[]  = "l.business_name LIKE ?";
    $params[] = "%$search%";
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$assignments = $pdo->prepare("
    SELECT pba.id, pba.listing_id, pba.role, pba.status, pba.notes, pba.assigned_at, pba.removed_at,
           l.business_name, l.slug AS listing_slug, l.status AS listing_status,
           u.name AS assigned_by_name,
           (SELECT COUNT(*) FROM partner_leads pl WHERE pl.partner_id=pba.partner_id AND pl.listing_id=pba.listing_id) AS lead_count,
           (SELECT COUNT(*) FROM enquiries e WHERE e.listing_id=pba.listing_id AND DATE(e.created_at) >= DATE(pba.assigned_at)) AS enquiry_count
    FROM partner_business_assignments pba
    JOIN listings l ON l.id = pba.listing_id
    LEFT JOIN users u ON u.id = pba.assigned_by
    $whereSQL
    ORDER BY pba.status='active' DESC, pba.assigned_at DESC
");
$assignments->execute($params);
$assignments = $assignments->fetchAll();

// Counts per tab
$countQ = $pdo->prepare("SELECT status, COUNT(*) AS n FROM partner_business_assignments WHERE partner_id=? GROUP BY status");
$countQ->execute([$partnerId]);
$counts = $countQ->fetchAll(\PDO::FETCH_KEY_PAIR);

$flash = getFlash();
$pageTitle = pt('My Assignments');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.tabs{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:1.25rem}
.tab{padding:.55rem 1.1rem;font-size:.875rem;font-weight:500;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;white-space:nowrap}
.tab.active{color:#2563eb;border-color:#2563eb}
.tab .cnt{background:#e5e7eb;font-size:.68rem;border-radius:99px;padding:.1rem .4rem;margin-left:.25rem}
.tab.active .cnt{background:#2563eb;color:#fff}
.asg-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;margin-bottom:.75rem;overflow:hidden}
.asg-header{display:flex;align-items:flex-start;gap:.75rem;padding:.9rem 1.1rem;border-bottom:1px solid #f3f4f6}
.asg-biz{flex:1;min-width:0}
.asg-name{font-weight:700;font-size:.95rem;color:#111827;text-decoration:none}
.asg-name:hover{color:#2563eb}
.asg-sub{font-size:.78rem;color:#6b7280;margin-top:.15rem}
.badges{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.3rem}
.badge{font-size:.68rem;font-weight:600;padding:.15rem .45rem;border-radius:99px}
.role-primary{background:#dbeafe;color:#1d4ed8}
.role-supporting{background:#ede9fe;color:#6d28d9}
.status-active{background:#d1fae5;color:#065f46}
.status-completed{background:#dbeafe;color:#1d4ed8}
.status-removed{background:#fee2e2;color:#991b1b}
.asg-body{padding:.7rem 1.1rem;font-size:.85rem;color:#374151}
.asg-stats{display:flex;gap:1.25rem;margin-bottom:.6rem}
.asg-stat{text-align:center}
.asg-stat .val{font-size:1.1rem;font-weight:700;color:#111827}
.asg-stat .lbl{font-size:.7rem;color:#9ca3af}
.asg-actions{padding:.6rem 1.1rem;background:#f9fafb;border-top:1px solid #f3f4f6;display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-success{background:#059669;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.btn:hover{opacity:.9}
.note-form{margin-top:.6rem}
.note-form textarea{width:100%;border:1px solid #d1d5db;border-radius:.45rem;padding:.45rem .7rem;font-size:.83rem;resize:vertical;box-sizing:border-box}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.empty{text-align:center;padding:3rem;color:#9ca3af}
.search-bar{display:flex;gap:.5rem;margin-bottom:1rem}
.search-bar input{flex:1;border:1px solid #d1d5db;border-radius:.5rem;padding:.45rem .75rem;font-size:.875rem}
</style>

<div style="max-width:820px;margin:0 auto;padding:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827"><?= pt('My Assignments') ?></h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280"><?= pt('Businesses you\'re managing as a Growth Partner') ?></p>
        </div>
        <a href="<?= SITE_URL ?>/partner/dashboard" class="btn btn-detail">← <?= pt('Dashboard') ?></a>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <div class="tabs">
        <?php foreach (['active'=>pt('Active'),'completed'=>pt('Completed'),'removed'=>pt('Removed'),'all'=>pt('All')] as $t => $lbl): ?>
        <a href="?tab=<?= $t ?>" class="tab<?= $tab===$t ? ' active' : '' ?>">
            <?= $lbl ?>
            <?php if ($cnt = ($t === 'all' ? array_sum($counts) : ($counts[$t] ?? 0))): ?>
            <span class="cnt"><?= $cnt ?></span>
            <?php endif ?>
        </a>
        <?php endforeach ?>
    </div>

    <form method="get" class="search-bar">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= pt('Search by business name…') ?>">
        <button type="submit" class="btn btn-detail"><?= pt('Search') ?></button>
        <?php if ($search): ?><a href="?tab=<?= e($tab) ?>" class="btn btn-detail"><?= pt('Clear') ?></a><?php endif ?>
    </form>

    <?php if (!$assignments): ?>
    <div class="empty">
        <div style="font-size:2.5rem;margin-bottom:.5rem">🏢</div>
        <p><?= pt('No assignments found.') ?></p>
    </div>
    <?php else: ?>
    <?php foreach ($assignments as $a): ?>
    <div class="asg-card">
        <div class="asg-header">
            <div class="asg-biz">
                <a href="<?= SITE_URL ?>/listing/<?= e($a['listing_slug']) ?>" class="asg-name" target="_blank"><?= e($a['business_name']) ?></a>
                <div class="asg-sub"><?= pt('Assigned') ?> <?= date('d M Y', strtotime($a['assigned_at'])) ?><?= $a['assigned_by_name'] ? ' ' . pt('by') . ' ' . e($a['assigned_by_name']) : '' ?></div>
                <div class="badges">
                    <span class="badge role-<?= e($a['role']) ?>"><?= ucfirst($a['role']) ?></span>
                    <span class="badge status-<?= e($a['status']) ?>"><?= ucfirst($a['status']) ?></span>
                </div>
            </div>
            <div class="asg-stats">
                <div class="asg-stat"><div class="val"><?= (int)$a['lead_count'] ?></div><div class="lbl"><?= pt('Leads') ?></div></div>
                <div class="asg-stat"><div class="val"><?= (int)$a['enquiry_count'] ?></div><div class="lbl"><?= pt('Enquiries') ?></div></div>
            </div>
        </div>

        <?php if ($a['notes'] || $a['status'] === 'active'): ?>
        <div class="asg-body">
            <?php if ($a['notes']): ?>
            <p style="margin:0 0 .5rem;font-size:.82rem;color:#6b7280;background:#f9fafb;padding:.4rem .65rem;border-radius:.4rem;border-left:3px solid #d1d5db">
                <?= e($a['notes']) ?>
            </p>
            <?php endif ?>

            <?php if ($a['status'] === 'active'): ?>
            <details class="note-form">
                <summary style="font-size:.8rem;color:#2563eb;cursor:pointer;user-select:none"><?= pt('Add / update note') ?></summary>
                <form method="post" style="margin-top:.5rem">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_note">
                    <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                    <textarea name="notes" rows="3" placeholder="<?= pt('Notes about this business relationship…') ?>"><?= e($a['notes']) ?></textarea>
                    <button type="submit" class="btn btn-primary" style="margin-top:.35rem"><?= pt('Save Note') ?></button>
                </form>
            </details>
            <?php endif ?>
        </div>
        <?php endif ?>

        <div class="asg-actions">
            <a href="<?= SITE_URL ?>/listing/<?= e($a['listing_slug']) ?>" class="btn btn-detail" target="_blank"><?= pt('View Listing') ?></a>
            <a href="<?= SITE_URL ?>/partner/leads?listing=<?= $a['listing_id'] ?>" class="btn btn-detail"><?= pt('Leads') ?></a>
            <?php if ($a['status'] === 'active'): ?>
            <form method="post" style="margin:0">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="request_complete">
                <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                <button type="submit" class="btn btn-success" onclick="return confirm('<?= pt('Request completion of this assignment?') ?>')">✓ <?= pt('Request Completion') ?></button>
            </form>
            <?php endif ?>
            <?php if ($a['removed_at']): ?>
            <span style="font-size:.75rem;color:#9ca3af;margin-left:.5rem"><?= pt('Removed') ?> <?= date('d M Y', strtotime($a['removed_at'])) ?></span>
            <?php endif ?>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
