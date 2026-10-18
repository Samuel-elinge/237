<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$total     = db()->query("SELECT COUNT(*) FROM subscribers WHERE active=1 AND confirmed=1")->fetchColumn();
$pending   = db()->query("SELECT COUNT(*) FROM subscribers WHERE active=1 AND confirmed=0")->fetchColumn();
$subs      = db()->query("SELECT s.*, l.name_en AS loc_name FROM subscribers s LEFT JOIN locations l ON l.id=s.location_id ORDER BY s.created_at DESC LIMIT 100")->fetchAll();

$pageTitle = 'Subscribers — Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav.php';
adminNav('subscribers'); adminNavAssets();
?>
<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📬 Newsletter Subscribers</h1>
</div></div>
<section class="page-section"><div class="container">

<div class="dash-stats-grid" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:2rem;">
    <div class="dash-stat"><strong><?= $total ?></strong><span>Confirmed</span></div>
    <div class="dash-stat"><strong style="color:var(--yellow);"><?= $pending ?></strong><span>Pending confirmation</span></div>
    <div class="dash-stat">
      <a href="<?= SITE_URL ?>/send-newsletter.php?key=YOUR_CRON_SECRET_KEY" class="btn btn-primary btn-sm" style="margin-top:0.5rem;" onclick="adminConfirm('Send newsletter now?', this.closest('form')); return false;" >📤 Send Now</a>
      <span style="display:block;margin-top:0.3rem;">Manual send</span>
    </div>
  </div>
  <div class="listing-widget"><div style="overflow-x:auto;">
    <table class="data-table">
      <thead><tr><th>Email</th><th>Name</th><th>City</th><th>Status</th><th>Joined</th></tr></thead>
      <tbody>
        <?php foreach ($subs as $s): ?>
          <tr>
            <td style="color:var(--white);"><?= e($s['email']) ?></td>
            <td style="font-size:0.83rem;color:var(--muted);"><?= e($s['name'] ?? '—') ?></td>
            <td style="font-size:0.83rem;color:var(--muted);"><?= e($s['loc_name'] ?? 'All Cameroon') ?></td>
            <td>
              <span class="badge badge-<?= $s['confirmed'] && $s['active'] ? 'approved' : ($s['active'] ? 'pending' : 'rejected') ?>">
                <?= !$s['active'] ? 'Unsubscribed' : ($s['confirmed'] ? 'Confirmed' : 'Pending') ?>
              </span>
            </td>
            <td style="font-size:0.78rem;color:var(--muted-2);"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
