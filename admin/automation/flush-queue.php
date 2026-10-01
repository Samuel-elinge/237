<?php
/**
 * admin/automation/flush-queue.php
 * Processes all queued emails immediately from the admin panel.
 * Accessible at: /admin/automation/flush-queue.php
 * DELETE or restrict this file after initial use if desired.
 */
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();

$results  = [];
$processed = 0;
$sent     = 0;
$failed   = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'flush') {
    verifyCsrf();

    // Fetch all queued emails
    $queued = db()->query("
        SELECT al.id, al.user_id, al.template_id, al.sequence_id, al.step_id,
               al.to_email, al.subject, al.lang,
               at.body_en, at.body_fr,
               u.name AS user_name
        FROM automation_log al
        JOIN automation_templates at ON at.id = al.template_id
        JOIN users u ON u.id = al.user_id
        WHERE al.status = 'queued'
        ORDER BY al.queued_at ASC
        LIMIT 100
    ")->fetchAll();

    foreach ($queued as $row) {
        $processed++;
        $body = $row['lang'] === 'fr'
            ? ($row['body_fr'] ?: $row['body_en'])
            : $row['body_en'];

        // Resolve merge tags
        $uDetailed = db()->prepare("
            SELECT u.name, u.email, l.title AS business_name, l.slug AS listing_slug
            FROM users u
            LEFT JOIN listings l ON l.user_id=u.id AND l.status='approved'
            WHERE u.id=? LIMIT 1
        ");
        $uDetailed->execute([$row['user_id']]);
        $uData = $uDetailed->fetch();

        $firstName = explode(' ', $uData['name'] ?? '')[0];
        $find    = ['{{name}}','{{first_name}}','{{email}}','{{business_name}}','{{listing_url}}','{{site_url}}','{{dashboard_url}}'];
        $replace = [
            $uData['name']          ?? '',
            $firstName,
            $uData['email']         ?? '',
            $uData['business_name'] ?? ($uData['name'] ?? ''),
            isset($uData['listing_slug']) ? SITE_URL.'/listing/'.$uData['listing_slug'] : SITE_URL,
            SITE_URL,
            SITE_URL.'/dashboard',
        ];

        $subject = str_replace($find, $replace, $row['subject']);
        $body    = str_replace($find, $replace, $body ?? '');

        try {
            sendMail($row['to_email'], $subject, $body);
            db()->prepare("UPDATE automation_log SET status='sent', sent_at=NOW() WHERE id=?")
                 ->execute([$row['id']]);
            $results[] = ['ok', $row['user_name'], $row['to_email'], $subject];
            $sent++;
        } catch (Exception $e) {
            db()->prepare("UPDATE automation_log SET status='failed' WHERE id=?")
                 ->execute([$row['id']]);
            $results[] = ['fail', $row['user_name'], $row['to_email'], $e->getMessage()];
            $failed++;
        }
    }
}

// Count current queue
$queueCount = (int)db()->query("SELECT COUNT(*) FROM automation_log WHERE status='queued'")->fetchColumn();

$pageTitle = 'Flush Email Queue — Admin';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/admin/">Admin</a> ›
      <a href="<?= SITE_URL ?>/admin/automation/">Automation</a> ›
      <span>Flush Queue</span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📤 Flush Email Queue</h1>
  </div>
</div>

<section class="page-section">
<div class="container" style="max-width:760px;">

  <?php if ($processed > 0): ?>
  <!-- Results -->
  <div style="background:rgba(0,168,120,0.08);border:1px solid rgba(0,168,120,0.25);border-radius:14px;padding:20px 24px;margin-bottom:24px;">
    <h3 style="margin:0 0 12px;font-size:1rem;">
      ✅ Done — <?= $sent ?> sent, <?= $failed ?> failed out of <?= $processed ?> processed
    </h3>
    <?php foreach ($results as [$status,$name,$email,$detail]): ?>
    <div style="display:flex;gap:10px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px;">
      <span><?= $status==='ok' ? '✅' : '❌' ?></span>
      <span style="min-width:160px;color:rgba(255,255,255,0.8);"><?= e($name) ?></span>
      <span style="color:rgba(255,255,255,0.5);min-width:180px;"><?= e($email) ?></span>
      <span style="color:<?= $status==='ok'?'rgba(255,255,255,0.5)':'#ff6b7a' ?>;"><?= e(mb_substr($detail,0,60)) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Queue status card -->
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:16px;padding:28px;margin-bottom:20px;text-align:center;">
    <div style="font-size:3rem;font-weight:900;font-family:'Fraunces',serif;color:<?= $queueCount>0?'#fcd116':'#00A878' ?>;">
      <?= $queueCount ?>
    </div>
    <div style="font-size:14px;color:rgba(255,255,255,0.55);margin-top:4px;">
      <?= $queueCount === 1 ? 'email waiting in queue' : 'emails waiting in queue' ?>
    </div>

    <?php if ($queueCount > 0): ?>
    <form method="POST" style="margin-top:20px;">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="flush">
      <button type="submit"
              onclick="return confirm('Send all <?= $queueCount ?> queued email<?= $queueCount===1?'':'s' ?> now?')"
              style="padding:13px 32px;background:#00A878;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;">
        📤 Send All <?= $queueCount ?> Queued Email<?= $queueCount===1?'':'s' ?> Now
      </button>
    </form>
    <?php else: ?>
    <p style="color:#00A878;font-size:14px;margin-top:12px;">✅ Queue is empty — all emails have been sent.</p>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/automation/log.php" style="padding:10px 20px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:rgba(255,255,255,0.7);text-decoration:none;font-size:13.5px;">
      📋 View Send Log
    </a>
    <a href="<?= SITE_URL ?>/admin/automation/" style="padding:10px 20px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:rgba(255,255,255,0.7);text-decoration:none;font-size:13.5px;">
      ← Automation
    </a>
  </div>

</div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
