<?php
// admin/automation/index.php — Dashboard overview
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();

// ── Send monthly newsletter ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_newsletter') {
    verifyCsrf();
    require_once __DIR__ . '/../../automation/helper.php';

    $tplId = (int)($_POST['template_id'] ?? 0);
    $lang  = $_POST['lang'] ?? 'both';

    // Get all verified users (optionally filter by language tag)
    $users = db()->query("SELECT u.id FROM users u WHERE u.verified=1")->fetchAll(PDO::FETCH_COLUMN);

    $queued = 0;
    foreach ($users as $uid) {
        if ($tplId) {
            // Check language tag if filtering
            if ($lang !== 'both') {
                $hasLang = db()->prepare("SELECT 1 FROM user_tags ut JOIN automation_tags at ON at.id=ut.tag_id WHERE ut.user_id=? AND at.name=?");
                $hasLang->execute([(int)$uid, $lang]);
                if (!$hasLang->fetchColumn()) continue;
            }
            if (queueEmail((int)$uid, $tplId)) $queued++;
        }
    }
    flash('success', "Newsletter queued for $queued users. n8n will send within 15 minutes.");
    redirect(SITE_URL . '/admin/automation/');
}

$stats = [
    'templates'   => db()->query("SELECT COUNT(*) FROM automation_templates")->fetchColumn(),
    'sequences'   => db()->query("SELECT COUNT(*) FROM automation_sequences")->fetchColumn(),
    'enrolled'    => db()->query("SELECT COUNT(*) FROM automation_enrollments WHERE status='active'")->fetchColumn(),
    'queued'      => db()->query("SELECT COUNT(*) FROM automation_log WHERE status='queued'")->fetchColumn(),
    'sent_today'  => db()->query("SELECT COUNT(*) FROM automation_log WHERE status='sent' AND DATE(sent_at)=CURDATE()")->fetchColumn(),
    'total_sent'  => db()->query("SELECT COUNT(*) FROM automation_log WHERE status='sent'")->fetchColumn(),
    'tags'        => db()->query("SELECT COUNT(*) FROM automation_tags")->fetchColumn(),
    'tagged_users'=> db()->query("SELECT COUNT(DISTINCT user_id) FROM user_tags")->fetchColumn(),
];

// Recent send log
$recentLog = db()->query("
    SELECT al.*, u.name AS user_name, at.name AS tpl_name
    FROM automation_log al
    JOIN users u ON u.id = al.user_id
    JOIN automation_templates at ON at.id = al.template_id
    ORDER BY al.queued_at DESC LIMIT 20
")->fetchAll();

// Top sequences by enrollment
$topSeqs = db()->query("
    SELECT s.name, COUNT(e.id) AS cnt,
           SUM(e.status='active') AS active,
           SUM(e.status='completed') AS completed
    FROM automation_sequences s
    LEFT JOIN automation_enrollments e ON e.sequence_id = s.id
    GROUP BY s.id ORDER BY cnt DESC
")->fetchAll();

$pageTitle = 'Automation — Admin 237Biz';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">🤖 Customer Automation</h1>
</div></div>

<section class="page-section"><div class="container">

  <!-- Nav -->
  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
    <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
    <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
    <a href="<?= SITE_URL ?>/admin/automation/" class="filter-tab active">🤖 Automation</a>
    <a href="<?= SITE_URL ?>/admin/automation/templates.php" class="filter-tab">✉️ Templates</a>
    <a href="<?= SITE_URL ?>/admin/automation/sequences.php" class="filter-tab">🔗 Sequences</a>
    <a href="<?= SITE_URL ?>/admin/automation/segments.php" class="filter-tab">🏷️ Segments</a>
    <a href="<?= SITE_URL ?>/admin/automation/log.php" class="filter-tab">📊 Send Log</a>
    <a href="<?= SITE_URL ?>/dashboard" class="filter-tab">← Dashboard</a>
  </div>

  <!-- Stats -->
  <div class="dash-stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr));margin-bottom:2rem;">
    <div class="dash-stat"><strong><?= $stats['templates'] ?></strong><span>Templates</span></div>
    <div class="dash-stat"><strong><?= $stats['sequences'] ?></strong><span>Sequences</span></div>
    <div class="dash-stat"><strong style="color:var(--green);"><?= $stats['enrolled'] ?></strong><span>Active Enrollments</span></div>
    <div class="dash-stat"><strong style="color:var(--yellow);"><?= $stats['queued'] ?></strong><span>Queued</span></div>
    <div class="dash-stat"><strong style="color:var(--green);"><?= $stats['sent_today'] ?></strong><span>Sent Today</span></div>
    <div class="dash-stat"><strong><?= $stats['total_sent'] ?></strong><span>Total Sent</span></div>
    <div class="dash-stat">
      <strong style="color:var(--green);">
        <?php
          try {
              $opened = db()->query("SELECT COUNT(DISTINCT log_id) FROM automation_log WHERE opened_at IS NOT NULL")->fetchColumn();
              $sent   = max(1, $stats['total_sent']);
              echo round($opened / $sent * 100) . '%';
          } catch (Exception $e) { echo '—'; }
        ?>
      </strong>
      <span>Open Rate</span>
    </div>
    <div class="dash-stat"><strong><?= $stats['tags'] ?></strong><span>Tags</span></div>
    <div class="dash-stat"><strong><?= $stats['tagged_users'] ?></strong><span>Tagged Users</span></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

    <!-- Sequence performance -->
    <div class="listing-widget">
      <h4 style="margin-bottom:1rem;">🔗 Sequence Performance</h4>
      <?php foreach ($topSeqs as $seq): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.6rem 0;border-bottom:1px solid var(--border);font-size:0.85rem;">
          <span style="color:var(--white);"><?= e($seq['name']) ?></span>
          <div style="display:flex;gap:0.75rem;font-size:0.78rem;">
            <span style="color:var(--green);">✓ <?= $seq['completed'] ?></span>
            <span style="color:var(--yellow);">⏳ <?= $seq['active'] ?></span>
          </div>
        </div>
      <?php endforeach; ?>
      <a href="<?= SITE_URL ?>/admin/automation/sequences.php" class="btn btn-outline btn-sm" style="margin-top:1rem;">Manage Sequences →</a>
    </div>

    <!-- Recent sends -->
    <div class="listing-widget">
      <h4 style="margin-bottom:1rem;">📊 Recent Sends</h4>
      <?php foreach (array_slice($recentLog, 0, 8) as $log): ?>
        <div style="padding:0.5rem 0;border-bottom:1px solid var(--border);font-size:0.8rem;">
          <div style="display:flex;justify-content:space-between;">
            <span style="color:var(--white);"><?= e($log['user_name']) ?></span>
            <span class="badge badge-<?= $log['status'] === 'sent' ? 'approved' : ($log['status'] === 'queued' ? 'pending' : 'rejected') ?>" style="font-size:0.6rem;"><?= $log['status'] ?></span>
          </div>
          <div style="color:var(--muted-2);font-size:0.72rem;"><?= e($log['tpl_name']) ?> · <?= e($log['lang']) ?> · <?= timeAgo($log['queued_at']) ?></div>
        </div>
      <?php endforeach; ?>
      <a href="<?= SITE_URL ?>/admin/automation/log.php" class="btn btn-outline btn-sm" style="margin-top:1rem;">Full Log →</a>
    </div>

  </div>

  <!-- Quick actions -->
  <div style="display:flex;gap:0.75rem;margin-top:1.5rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/automation/templates.php?action=new" class="btn btn-primary btn-sm">+ New Template</a>
    <a href="<?= SITE_URL ?>/admin/automation/sequences.php?action=new" class="btn btn-outline btn-sm">+ New Sequence</a>
    <a href="<?= SITE_URL ?>/admin/automation/segments.php" class="btn btn-outline btn-sm">🏷️ Manage Tags</a>
    <a href="<?= SITE_URL ?>/automation/queue.php?token=237biz-automation-2026" target="_blank" class="btn btn-outline btn-sm" style="color:var(--yellow);border-color:rgba(245,200,66,0.3);">🔗 n8n Webhook URL</a>
  </div>

  <!-- Monthly newsletter send -->
  <?php
  $newsletterTpls = db()->query("SELECT id,name,subject_en FROM automation_templates ORDER BY name")->fetchAll();
  $verifiedCount  = db()->query("SELECT COUNT(*) FROM users WHERE verified=1")->fetchColumn();
  ?>
  <div class="listing-widget" style="margin-top:1.5rem;border-color:rgba(245,200,66,0.2);background:rgba(245,200,66,0.02);">
    <h4 style="margin-bottom:0.5rem;">📰 Send Newsletter / Broadcast</h4>
    <p style="font-size:0.82rem;color:var(--muted-2);margin-bottom:1rem;">Queue an email to all <?= number_format($verifiedCount) ?> verified users right now. n8n will send within 15 minutes.</p>
    <form method="POST" onsubmit="return confirm('Send this email to all verified users? This cannot be undone.')">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="send_newsletter">
      <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:200px;">
          <label style="font-size:0.78rem;">Email Template *</label>
          <select name="template_id" required style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.55rem 0.75rem;font-size:0.875rem;width:100%;">
            <option value="">— Select template —</option>
            <?php foreach ($newsletterTpls as $t): ?>
              <option value="<?= $t['id'] ?>"><?= e($t['name']) ?> — <?= e(mb_substr($t['subject_en'],0,50)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;width:160px;">
          <label style="font-size:0.78rem;">Language</label>
          <select name="lang" style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.55rem 0.75rem;font-size:0.875rem;width:100%;">
            <option value="both">All users (EN+FR)</option>
            <option value="english">English speakers only</option>
            <option value="french">French speakers only</option>
          </select>
        </div>
        <button type="submit" class="btn btn-sm" style="background:rgba(245,200,66,0.15);color:var(--yellow);border:1px solid rgba(245,200,66,0.3);height:42px;">
          📤 Queue Send
        </button>
      </div>
    </form>
  </div>

</div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
