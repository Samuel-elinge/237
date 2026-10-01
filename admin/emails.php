<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

// ── NAV DEFINITION ────────────────────────────────────────
$NAV = [
    ['href' => SITE_URL . '/admin/',                  'label' => '📋 Listings'],
    ['href' => SITE_URL . '/admin/users.php',         'label' => '👤 Users'],
    ['href' => SITE_URL . '/admin/orders.php',        'label' => '📦 Orders'],
    ['href' => SITE_URL . '/admin/leads.php',         'label' => '🌐 Website Leads'],
    ['href' => SITE_URL . '/admin/enquiries.php',     'label' => '📬 Enquiries'],
    ['href' => SITE_URL . '/admin/claims.php',        'label' => '🏢 Claims'],
    ['href' => SITE_URL . '/admin/reviews.php',       'label' => '⭐ Reviews'],
    ['href' => SITE_URL . '/admin/categories.php',   'label' => '📂 Categories'],
    ['href' => SITE_URL . '/admin/locations.php',    'label' => '📍 Locations'],
    ['href' => SITE_URL . '/admin/subscribers.php',  'label' => '📬 Newsletter'],
    ['href' => SITE_URL . '/admin/promos.php',       'label' => '🎁 Promo Codes'],
    ['href' => SITE_URL . '/admin/packages.php',     'label' => '💳 Packages'],
    ['href' => SITE_URL . '/admin/seo-content.php',  'label' => '📝 SEO Content'],
    ['href' => SITE_URL . '/admin/emails.php',       'label' => '✉️ Emails'],
    ['href' => SITE_URL . '/admin/automation/',      'label' => '🤖 Automation'],
    ['href' => SITE_URL . '/dashboard',              'label' => '← Dashboard'],
];

$flash_success = flash('success');
$flash_error   = flash('error');

// ── HANDLE SEND EMAIL ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $toType  = $_POST['to_type'] ?? 'user';   // user | all_users | all_featured | all_listing_owners | custom
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $lang    = $_POST['lang'] ?? 'en';
        $userId  = (int)($_POST['user_id'] ?? 0);
        $custom  = trim($_POST['custom_email'] ?? '');

        if (!$subject || !$body) {
            flash('error', 'Subject and body are required.');
        } else {
            $recipients = [];

            if ($toType === 'user' && $userId) {
                $u = db()->prepare("SELECT id, name, email FROM users WHERE id=?");
                $u->execute([$userId]);
                $r = $u->fetch();
                if ($r) $recipients[] = $r;

            } elseif ($toType === 'custom' && $custom) {
                $recipients[] = ['id' => 0, 'name' => 'Customer', 'email' => $custom];

            } elseif ($toType === 'all_users') {
                $recipients = db()->query("SELECT id, name, email FROM users WHERE verified=1 ORDER BY name")->fetchAll();

            } elseif ($toType === 'all_featured') {
                $recipients = db()->query("
                    SELECT DISTINCT u.id, u.name, u.email
                    FROM users u
                    JOIN listings l ON l.user_id = u.id
                    WHERE l.featured = 1 AND l.status = 'approved' AND u.verified = 1
                    ORDER BY u.name
                ")->fetchAll();

            } elseif ($toType === 'all_listing_owners') {
                $recipients = db()->query("
                    SELECT DISTINCT u.id, u.name, u.email
                    FROM users u
                    JOIN listings l ON l.user_id = u.id
                    WHERE l.status = 'approved' AND u.verified = 1
                    ORDER BY u.name
                ")->fetchAll();
            }

            $sent = 0; $failed = 0;
            foreach ($recipients as $r) {
                // Resolve merge tags in subject + body
                $listingUrl = SITE_URL . '/dashboard';
                if ($r['id']) {
                    $lSt = db()->prepare("SELECT slug FROM listings WHERE user_id=? AND status='approved' LIMIT 1");
                    $lSt->execute([$r['id']]);
                    $lSlug = $lSt->fetchColumn();
                    if ($lSlug) $listingUrl = SITE_URL . '/listing/' . $lSlug;
                }
                $bizSt = db()->prepare("SELECT title FROM listings WHERE user_id=? AND status='approved' LIMIT 1");
                $bizSt->execute([$r['id'] ?? 0]);
                $bizName = $bizSt->fetchColumn() ?: $r['name'];

                $resolvedSubject = str_replace(
                    ['{{name}}','{{business_name}}','{{site_url}}','{{listing_url}}'],
                    [$r['name'], $bizName, SITE_URL, $listingUrl],
                    $subject
                );
                $resolvedBody = str_replace(
                    ['{{name}}','{{business_name}}','{{site_url}}','{{listing_url}}'],
                    [$r['name'], $bizName, SITE_URL, $listingUrl],
                    $body
                );

                $result = sendMail($r['email'], $resolvedSubject, $resolvedBody);

                // Log it
                try {
                    db()->prepare("
                        INSERT INTO admin_email_log
                        (to_email, to_name, user_id, subject, body, lang, status, sent_by, sent_at)
                        VALUES (?,?,?,?,?,?,?,?,NOW())
                    ")->execute([
                        $r['email'], $r['name'], $r['id'] ?: null,
                        $resolvedSubject, $resolvedBody, $lang,
                        $result ? 'sent' : 'failed',
                        $_SESSION['user_id']
                    ]);
                } catch (Exception $e) { /* log table may not exist yet */ }

                $result ? $sent++ : $failed++;
            }

            if ($sent > 0 && $failed === 0) {
                flash('success', "✅ Sent to $sent recipient" . ($sent > 1 ? 's' : '') . " successfully.");
            } elseif ($sent > 0) {
                flash('success', "⚠️ Sent: $sent — Failed: $failed.");
            } else {
                flash('error', "❌ All $failed email(s) failed. Check SMTP settings.");
            }
        }
        redirect(SITE_URL . '/admin/emails.php');
    }
}

// ── FILTERS ───────────────────────────────────────────────
$search      = trim($_GET['q'] ?? '');
$statusF     = in_array($_GET['status'] ?? '', ['sent','failed']) ? $_GET['status'] : '';
$userIdF     = (int)($_GET['user_id'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 30;
$offset      = ($page - 1) * $perPage;

// Try to load email log — table may not exist yet
$logs        = [];
$total       = 0;
$totalPages  = 1;
$tableExists = false;

try {
    db()->query("SELECT 1 FROM admin_email_log LIMIT 1");
    $tableExists = true;
} catch (Exception $e) { /* table not created yet */ }

if ($tableExists) {
    $where  = ['1=1'];
    $params = [];
    if ($search) {
        $where[] = "(al.to_email LIKE ? OR al.to_name LIKE ? OR al.subject LIKE ?)";
        $params  = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
    }
    if ($statusF) { $where[] = "al.status=?"; $params[] = $statusF; }
    if ($userIdF) { $where[] = "al.user_id=?"; $params[] = $userIdF; }
    $ws = implode(' AND ', $where);

    $cntSt = db()->prepare("SELECT COUNT(*) FROM admin_email_log al WHERE $ws");
    $cntSt->execute($params);
    $total      = (int)$cntSt->fetchColumn();
    $totalPages = max(1, ceil($total / $perPage));

    $logSt = db()->prepare("
        SELECT al.*, u.name AS user_name, u.email AS user_email,
               ab.name AS sent_by_name
        FROM admin_email_log al
        LEFT JOIN users u  ON u.id  = al.user_id
        LEFT JOIN users ab ON ab.id = al.sent_by
        WHERE $ws
        ORDER BY al.sent_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $logSt->execute($params);
    $logs = $logSt->fetchAll();
}

// Load users for compose dropdown
$allUsers = db()->query("
    SELECT u.id, u.name, u.email,
           (SELECT title FROM listings WHERE user_id=u.id AND status='approved' LIMIT 1) AS biz_name
    FROM users u WHERE u.verified=1 ORDER BY u.name LIMIT 500
")->fetchAll();

// Stats
$stats = ['sent' => 0, 'failed' => 0, 'total' => 0];
if ($tableExists) {
    $s = db()->query("SELECT status, COUNT(*) AS c FROM admin_email_log GROUP BY status")->fetchAll();
    foreach ($s as $row) {
        $stats[$row['status']] = $row['c'];
        $stats['total'] += $row['c'];
    }
}

$filterUser = $userIdF ? db()->prepare("SELECT name, email FROM users WHERE id=?")->execute([$userIdF]) ? null : null : null;
if ($userIdF) {
    $fuSt = db()->prepare("SELECT name, email FROM users WHERE id=?");
    $fuSt->execute([$userIdF]);
    $filterUser = $fuSt->fetch();
}

$pageTitle = 'Email History — Admin 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">✉️ Email Manager</h1>
</div></div>

<section class="page-section"><div class="container">

  <!-- Nav -->
  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <?php foreach ($NAV as $n): ?>
      <a href="<?= $n['href'] ?>" class="filter-tab <?= strpos($n['href'], 'emails') !== false ? 'active' : '' ?>"><?= $n['label'] ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($flash_success): ?><div class="flash flash-success" style="margin-bottom:1rem;"><?= e($flash_success) ?></div><?php endif; ?>
  <?php if ($flash_error):   ?><div class="flash flash-error"   style="margin-bottom:1rem;"><?= e($flash_error) ?></div><?php endif; ?>

  <!-- Stats -->
  <div class="dash-stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:2rem;">
    <div class="dash-stat"><strong><?= number_format($stats['total']) ?></strong><span>Total Sent</span></div>
    <div class="dash-stat"><strong style="color:var(--green);"><?= number_format($stats['sent'] ?? 0) ?></strong><span>Delivered</span></div>
    <div class="dash-stat"><strong style="color:#ff6b6b;"><?= number_format($stats['failed'] ?? 0) ?></strong><span>Failed</span></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;">

    <!-- ── EMAIL HISTORY ── -->
    <div>
      <h4 style="margin-bottom:1rem;">📋 Email History</h4>

      <!-- Search + filter -->
      <form method="GET" style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;">
        <div style="position:relative;flex:1;min-width:180px;">
          <input type="text" name="q" value="<?= e($search) ?>"
                 placeholder="Search email, name, subject..."
                 style="width:100%;background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.55rem 0.75rem 0.55rem 2.2rem;font-size:0.875rem;">
          <span style="position:absolute;left:0.7rem;top:50%;transform:translateY(-50%);color:var(--muted);">🔍</span>
        </div>
        <select name="status" style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.55rem 0.75rem;font-size:0.875rem;">
          <option value="">All statuses</option>
          <option value="sent"   <?= $statusF==='sent'   ?'selected':'' ?>>Sent</option>
          <option value="failed" <?= $statusF==='failed' ?'selected':'' ?>>Failed</option>
        </select>
        <?php if ($userIdF): ?><input type="hidden" name="user_id" value="<?= $userIdF ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search || $statusF || $userIdF): ?>
          <a href="<?= SITE_URL ?>/admin/emails.php" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <?php if ($userIdF && $filterUser): ?>
        <div style="background:rgba(0,168,120,0.08);border:1px solid rgba(0,168,120,0.2);border-radius:8px;padding:0.65rem 1rem;margin-bottom:1rem;font-size:0.82rem;color:var(--muted);">
          Showing emails for: <strong style="color:var(--white);"><?= e($filterUser['name']) ?></strong> (<?= e($filterUser['email']) ?>)
          <a href="<?= SITE_URL ?>/admin/emails.php" style="margin-left:0.5rem;color:var(--green);">Show all</a>
        </div>
      <?php endif; ?>

      <?php if (!$tableExists): ?>
        <div style="background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.2);border-radius:10px;padding:1.25rem;margin-bottom:1rem;">
          <strong style="color:var(--yellow);">⚠️ Email log table not set up yet.</strong>
          <p style="color:var(--muted);font-size:0.82rem;margin-top:0.4rem;">Run the SQL below in phpMyAdmin to enable email history tracking.</p>
          <code style="display:block;margin-top:0.75rem;background:rgba(0,0,0,0.3);padding:0.75rem;border-radius:6px;font-size:0.75rem;color:rgba(255,255,255,0.8);white-space:pre-wrap;">CREATE TABLE IF NOT EXISTS `admin_email_log` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `to_email`   VARCHAR(255) NOT NULL,
  `to_name`    VARCHAR(200) DEFAULT NULL,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `subject`    VARCHAR(255) NOT NULL,
  `body`       TEXT NOT NULL,
  `lang`       ENUM('en','fr') DEFAULT 'en',
  `status`     ENUM('sent','failed') DEFAULT 'sent',
  `sent_by`    INT UNSIGNED DEFAULT NULL,
  `sent_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user`   (`user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_sent`   (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</code>
        </div>
      <?php endif; ?>

      <p style="font-size:0.78rem;color:var(--muted-2);margin-bottom:0.75rem;"><?= number_format($total) ?> emails<?= $search ? ' matching "'.e($search).'"' : '' ?></p>

      <?php if ($logs): ?>
        <div class="listing-widget" style="padding:0;overflow:hidden;">
          <div style="overflow-x:auto;">
            <table class="data-table" style="min-width:700px;">
              <thead>
                <tr>
                  <th>Recipient</th>
                  <th>Subject</th>
                  <th>Status</th>
                  <th>Sent by</th>
                  <th>Date</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($logs as $log): ?>
                  <tr>
                    <td>
                      <div style="color:var(--white);font-size:0.85rem;"><?= e($log['to_name'] ?: $log['to_email']) ?></div>
                      <div style="font-size:0.72rem;color:var(--muted-2);"><?= e($log['to_email']) ?></div>
                    </td>
                    <td style="font-size:0.82rem;color:var(--muted);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($log['subject']) ?></td>
                    <td><span class="badge badge-<?= $log['status']==='sent'?'approved':'rejected' ?>"><?= $log['status'] ?></span></td>
                    <td style="font-size:0.78rem;color:var(--muted-2);"><?= e($log['sent_by_name'] ?? 'System') ?></td>
                    <td style="font-size:0.75rem;color:var(--muted-2);white-space:nowrap;"><?= date('d M Y H:i', strtotime($log['sent_at'])) ?></td>
                    <td>
                      <div style="display:flex;gap:0.3rem;">
                        <?php if ($log['user_id']): ?>
                          <a href="?user_id=<?= $log['user_id'] ?>" class="btn btn-outline btn-sm" style="font-size:0.7rem;" title="All emails to this user">👤</a>
                        <?php endif; ?>
                        <button onclick="document.getElementById('preview-<?= $log['id'] ?>').style.display=document.getElementById('preview-<?= $log['id'] ?>').style.display==='none'?'block':'none'"
                                class="btn btn-outline btn-sm" style="font-size:0.7rem;">👁️</button>
                      </div>
                      <div id="preview-<?= $log['id'] ?>" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:9999;display:none;align-items:center;justify-content:center;"
                           onclick="this.style.display='none'">
                        <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.5rem;max-width:600px;width:90%;max-height:80vh;overflow-y:auto;" onclick="event.stopPropagation()">
                          <div style="display:flex;justify-content:space-between;margin-bottom:1rem;">
                            <strong style="color:var(--white);">Email Preview</strong>
                            <button onclick="this.closest('[id^=preview]').style.display='none'" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.2rem;">×</button>
                          </div>
                          <div style="font-size:0.78rem;color:var(--muted-2);margin-bottom:0.25rem;"><strong>To:</strong> <?= e($log['to_email']) ?></div>
                          <div style="font-size:0.78rem;color:var(--muted-2);margin-bottom:0.75rem;"><strong>Subject:</strong> <?= e($log['subject']) ?></div>
                          <div style="font-size:0.82rem;border-top:1px solid var(--border);padding-top:0.75rem;"><?= $log['body'] ?></div>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
          <div style="display:flex;justify-content:center;gap:0.5rem;flex-wrap:wrap;margin-top:1.25rem;">
            <?php for ($i=1; $i<=$totalPages; $i++): ?>
              <?php $qs = array_filter(['q'=>$search,'status'=>$statusF,'user_id'=>$userIdF,'page'=>$i>1?$i:'']); ?>
              <a href="?<?= http_build_query($qs) ?>" class="btn btn-sm <?= $i===$page?'btn-primary':'btn-outline' ?>"><?= $i ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>

      <?php elseif ($tableExists): ?>
        <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
          <div style="font-size:2.5rem;margin-bottom:0.75rem;">📭</div>
          <p style="color:var(--muted);">No emails sent yet — compose your first one →</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── COMPOSE PANEL ── -->
    <div>
      <div class="listing-widget" style="border-color:rgba(0,168,120,0.25);background:rgba(0,168,120,0.02);position:sticky;top:100px;">
        <h4 style="margin-bottom:1.25rem;">📝 Compose Email</h4>

        <!-- Merge tag reference -->
        <div style="background:rgba(245,200,66,0.06);border:1px solid rgba(245,200,66,0.15);border-radius:8px;padding:0.65rem 0.85rem;margin-bottom:1rem;font-size:0.72rem;color:var(--muted);">
          <strong style="color:var(--yellow);">Merge tags:</strong>
          <?php foreach (['{{name}}','{{business_name}}','{{site_url}}','{{listing_url}}'] as $mt): ?>
            <code onclick="navigator.clipboard.writeText('<?= $mt ?>')"
                  title="Click to copy"
                  style="background:rgba(255,255,255,0.07);padding:0.1rem 0.4rem;border-radius:4px;margin:0 0.15rem;cursor:pointer;color:var(--white);"><?= $mt ?></code>
          <?php endforeach; ?>
        </div>

        <form method="POST" id="compose-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="send">

          <!-- To -->
          <div class="form-group">
            <label style="font-size:0.8rem;">Send to</label>
            <select name="to_type" id="to-type" onchange="toggleRecipient(this.value)"
                    style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.6rem 0.75rem;font-size:0.875rem;width:100%;margin-bottom:0.5rem;">
              <option value="user">Specific user</option>
              <option value="custom">Custom email address</option>
              <option value="all_featured">All featured listing owners</option>
              <option value="all_listing_owners">All listing owners</option>
              <option value="all_users">All verified users</option>
            </select>

            <!-- Specific user picker -->
            <div id="user-picker">
              <select name="user_id" style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.6rem 0.75rem;font-size:0.82rem;width:100%;">
                <option value="">— Select user —</option>
                <?php foreach ($allUsers as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= $userIdF==$u['id']?'selected':'' ?>>
                    <?= e($u['name']) ?><?= $u['biz_name'] ? ' — '.e(mb_substr($u['biz_name'],0,30)) : '' ?> (<?= e($u['email']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Custom email -->
            <div id="custom-picker" style="display:none;">
              <input type="email" name="custom_email" placeholder="email@example.com"
                     style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.6rem 0.75rem;font-size:0.875rem;width:100%;">
            </div>

            <!-- Bulk recipient count -->
            <div id="bulk-info" style="display:none;font-size:0.75rem;padding:0.4rem 0;color:var(--muted-2);"></div>
          </div>

          <div class="form-group">
            <label style="font-size:0.8rem;">Language</label>
            <select name="lang" style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.55rem 0.75rem;font-size:0.875rem;width:100%;">
              <option value="en">English</option>
              <option value="fr">French</option>
            </select>
          </div>

          <div class="form-group">
            <label style="font-size:0.8rem;">Subject *</label>
            <input type="text" name="subject" required placeholder="e.g. Important update for {{name}}"
                   style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.6rem 0.75rem;font-size:0.875rem;width:100%;">
          </div>

          <div class="form-group">
            <label style="font-size:0.8rem;">Message * <span style="font-size:0.7rem;color:var(--muted-2);">(HTML supported)</span></label>
            <textarea name="body" required rows="8"
                      placeholder="Hi {{name}},&#10;&#10;Your message here...&#10;&#10;Best regards,&#10;237Biz Team"
                      style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.6rem 0.75rem;font-size:0.82rem;width:100%;font-family:monospace;resize:vertical;"></textarea>
          </div>

          <!-- Bulk send warning -->
          <div id="bulk-warning" style="display:none;background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.2);border-radius:8px;padding:0.65rem 0.85rem;margin-bottom:0.75rem;font-size:0.78rem;color:var(--yellow);">
            ⚠️ This will send to <strong id="bulk-count">multiple</strong> recipients. Make sure your message is ready before sending.
          </div>

          <button type="submit" class="btn btn-primary btn-full" onclick="return confirmSend()">
            📤 Send Email
          </button>
        </form>
      </div>
    </div>
  </div>

</div></section>

<script>
const bulkCounts = {
    'all_featured':        <?= db()->query("SELECT COUNT(DISTINCT l.user_id) FROM listings l JOIN users u ON u.id=l.user_id WHERE l.featured=1 AND l.status='approved' AND u.verified=1")->fetchColumn() ?>,
    'all_listing_owners':  <?= db()->query("SELECT COUNT(DISTINCT l.user_id) FROM listings l JOIN users u ON u.id=l.user_id WHERE l.status='approved' AND u.verified=1")->fetchColumn() ?>,
    'all_users':           <?= db()->query("SELECT COUNT(*) FROM users WHERE verified=1")->fetchColumn() ?>,
};

function toggleRecipient(val) {
    document.getElementById('user-picker').style.display   = val === 'user'   ? 'block' : 'none';
    document.getElementById('custom-picker').style.display = val === 'custom' ? 'block' : 'none';
    const bulkInfo    = document.getElementById('bulk-info');
    const bulkWarning = document.getElementById('bulk-warning');
    const bulkCount   = document.getElementById('bulk-count');
    const isBulk      = bulkCounts[val] !== undefined;
    bulkInfo.style.display    = isBulk ? 'block' : 'none';
    bulkWarning.style.display = isBulk ? 'block' : 'none';
    if (isBulk) {
        const n = bulkCounts[val];
        bulkInfo.textContent = `Will send to approximately ${n} recipient${n !== 1 ? 's' : ''}.`;
        bulkCount.textContent = n;
    }
}

function confirmSend() {
    const type = document.getElementById('to-type').value;
    if (bulkCounts[type] !== undefined) {
        const n = bulkCounts[type];
        return confirm(`Send to ${n} recipient${n !== 1 ? 's' : ''}? This cannot be undone.`);
    }
    return true;
}

// Preview modal close fix
document.querySelectorAll('[id^="preview-"]').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
