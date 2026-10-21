<?php
/**
 * admin/notify-launch-checklist.php
 * Milestone 5 smoke-test checklist for the notification subscription module.
 * Confirms DB tables, helpers, cron protection, and key flows are ready.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notify-helpers.php';
requireAdmin();
$pdo = db();

$checks = [];

// ── Helper ────────────────────────────────────────────────────────────────────
function chk(string $label, bool $pass, string $detail = ''): array {
    return ['label' => $label, 'pass' => $pass, 'detail' => $detail];
}

// 1. DB tables exist
$tables = ['notification_subscribers','notification_preferences','notification_consent_events',
           'notification_verification_tokens','notification_suppressions',
           'notification_campaigns','notification_campaign_segments',
           'notification_campaign_recipients','notification_delivery_events',
           'notification_audit_logs'];
foreach ($tables as $t) {
    try {
        $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1");
        $checks[] = chk("Table `{$t}` exists", true);
    } catch (Throwable $e) {
        $checks[] = chk("Table `{$t}` exists", false, $e->getMessage());
    }
}

// 2. notify-helpers.php functions present
$helperFns = ['notifySubscribe','notifyUnsubscribe','notifyGetManageToken',
              'notifyCheckRateLimit','notifySendVerificationEmail','notifyAuditLog'];
foreach ($helperFns as $fn) {
    $checks[] = chk("Helper function {$fn}() defined", function_exists($fn));
}

// 3. SITE_URL defined
$checks[] = chk('SITE_URL defined', defined('SITE_URL') && SITE_URL !== '',
    defined('SITE_URL') ? SITE_URL : '(not set)');

// 4. Public pages reachable (file exists)
$publicPages = [
    'notify-subscribe.php'   => '../notify-subscribe.php',
    'notify-verify.php'      => '../notify-verify.php',
    'notify-unsubscribe.php' => '../notify-unsubscribe.php',
    'notify-preferences.php' => '../notify-preferences.php',
];
foreach ($publicPages as $name => $rel) {
    $path = __DIR__ . '/' . $rel;
    $checks[] = chk("Public page {$name} exists", file_exists($path));
}

// 5. Admin pages present
$adminPages = [
    'admin/notify-subscribers.php' => 'notify-subscribers.php',
    'admin/notify-campaigns.php'   => 'notify-campaigns.php',
    'admin/notify-report.php'      => 'notify-report.php',
];
foreach ($adminPages as $label => $file) {
    $checks[] = chk("Admin page {$label} exists", file_exists(__DIR__ . '/' . $file));
}

// 6. Cron script present
$checks[] = chk('cron/notify-send-scheduled.php exists',
    file_exists(__DIR__ . '/../cron/notify-send-scheduled.php'));

// 7. Cron .htaccess protection
$htaccessPath = __DIR__ . '/../cron/.htaccess';
$htExists = file_exists($htaccessPath);
$htHasDeny = $htExists && (stripos(file_get_contents($htaccessPath), 'deny') !== false
                        || stripos(file_get_contents($htaccessPath), 'Require all denied') !== false);
$checks[] = chk('cron/.htaccess denies HTTP access', $htHasDeny,
    $htExists ? '' : 'File missing — create cron/.htaccess');

// 8. At least one active subscriber (optional — info only)
$subCount = (int)$pdo->query("SELECT COUNT(*) FROM notification_subscribers WHERE status='active'")->fetchColumn();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM notification_subscribers WHERE status='pending'")->fetchColumn();
$checks[] = chk("Active subscribers: {$subCount} (pending: {$pendingCount})", true,
    $subCount === 0 ? 'No active subs yet — expected before launch.' : '');

// 9. No campaigns stuck in "sending"
$stuckCount = (int)$pdo->query("SELECT COUNT(*) FROM notification_campaigns WHERE status='sending'")->fetchColumn();
$checks[] = chk('No campaigns stuck in "sending" status', $stuckCount === 0,
    $stuckCount > 0 ? "{$stuckCount} campaign(s) stuck — reset status to 'scheduled' or 'completed' manually." : '');

// 10. Suppression table functional
try {
    $pdo->query("SELECT COUNT(*) FROM notification_suppressions WHERE active=1")->fetchColumn();
    $checks[] = chk('Suppression table queryable', true);
} catch (Throwable $e) {
    $checks[] = chk('Suppression table queryable', false, $e->getMessage());
}

$passed = count(array_filter($checks, fn($c) => $c['pass']));
$total  = count($checks);
$allGood = $passed === $total;

$pageTitle = 'Launch Checklist — Notification Module';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('notify-campaigns'); adminNavAssets();
?>
<style>
.chk-row{display:flex;align-items:flex-start;gap:0.75rem;padding:0.65rem 0;border-bottom:1px solid var(--border);font-size:0.85rem}
.chk-row:last-child{border-bottom:none}
.chk-icon{font-size:1.1rem;flex-shrink:0;margin-top:0.05rem}
.chk-label{flex:1;color:var(--white)}
.chk-detail{color:var(--muted);font-size:0.77rem;margin-top:0.15rem}
</style>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.9rem;margin:0">
    🚀 Notification Module — Launch Checklist
  </h1>
  <p style="color:var(--muted);margin:.4rem 0 0;font-size:0.88rem">
    Milestone 5 smoke test · <?= date('d M Y H:i') ?>
  </p>
</div></div>

<section class="page-section"><div class="container">

  <!-- Summary banner -->
  <div style="background:<?= $allGood ? 'rgba(0,168,120,0.12)' : 'rgba(255,107,107,0.12)' ?>;
              border:1px solid <?= $allGood ? '#00A878' : '#ff6b6b' ?>;
              border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem">
    <span style="font-size:2rem"><?= $allGood ? '✅' : '⚠️' ?></span>
    <div>
      <strong style="font-size:1rem;color:var(--white)"><?= $allGood ? 'All checks passed — ready to launch!' : "Action required — {$passed}/{$total} checks passed" ?></strong>
      <div style="font-size:0.82rem;color:var(--muted);margin-top:0.2rem">
        <?= $allGood
          ? 'Enable the cron job in cPanel, then run a real test subscription end-to-end.'
          : 'Resolve the failing checks before going live.' ?>
      </div>
    </div>
  </div>

  <!-- Check list -->
  <div class="listing-widget" style="padding:1.25rem;margin-bottom:1.5rem">
    <?php foreach ($checks as $c): ?>
    <div class="chk-row">
      <span class="chk-icon"><?= $c['pass'] ? '✅' : '❌' ?></span>
      <div>
        <div class="chk-label"><?= e($c['label']) ?></div>
        <?php if ($c['detail']): ?>
        <div class="chk-detail"><?= e($c['detail']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- cPanel cron setup instructions -->
  <div class="listing-widget" style="padding:1.25rem;margin-bottom:1.5rem">
    <h3 style="font-family:'Fraunces',serif;font-weight:900;margin:0 0 1rem">⏰ cPanel Cron Job Setup</h3>
    <ol style="color:var(--muted);font-size:0.88rem;line-height:1.9;padding-left:1.2rem;margin:0">
      <li>Log in to cPanel → <strong style="color:var(--white)">Cron Jobs</strong></li>
      <li>Set <strong style="color:var(--white)">Common Settings</strong> to <em>Once Per 5 Minutes</em><br>
          or enter manually: <code style="background:var(--bg);padding:0.1rem 0.4rem;border-radius:4px">*/5 * * * *</code></li>
      <li>In the <strong style="color:var(--white)">Command</strong> field, enter:<br>
          <code style="background:var(--bg);padding:0.2rem 0.6rem;border-radius:4px;word-break:break-all;display:inline-block;margin-top:0.3rem">
            php /home/<strong>YOUR_CPANEL_USER</strong>/public_html/cron/notify-send-scheduled.php &gt;&gt; /home/<strong>YOUR_CPANEL_USER</strong>/logs/notify-cron.log 2&gt;&amp;1
          </code></li>
      <li>Replace <strong style="color:var(--white)">YOUR_CPANEL_USER</strong> with your actual cPanel username</li>
      <li>Ensure <code>logs/</code> directory exists on the server (cPanel File Manager → create if missing)</li>
      <li>Click <strong style="color:var(--white)">Add New Cron Job</strong></li>
    </ol>
    <div style="margin-top:1rem;padding:0.75rem 1rem;background:rgba(255,165,0,0.08);border:1px solid rgba(255,165,0,0.3);border-radius:8px;font-size:0.82rem;color:var(--muted)">
      💡 <strong style="color:var(--white)">Manual pull reminder:</strong>
      LiteSpeed does NOT auto-deploy from GitHub. After every git push, go to
      <strong style="color:var(--white)">cPanel → Git™ Version Control → Manage → Pull or Deploy</strong> to bring changes live.
    </div>
  </div>

  <!-- End-to-end smoke test steps -->
  <div class="listing-widget" style="padding:1.25rem">
    <h3 style="font-family:'Fraunces',serif;font-weight:900;margin:0 0 1rem">🧪 End-to-End Smoke Test</h3>
    <ol style="color:var(--muted);font-size:0.88rem;line-height:2;padding-left:1.2rem;margin:0">
      <li><strong style="color:var(--white)">Subscribe:</strong> Visit <code><?= SITE_URL ?>/notify-subscribe.php</code> with a real email → submit form → check inbox for verification email</li>
      <li><strong style="color:var(--white)">Verify:</strong> Click the verification link in the email → confirm subscriber status shows <em>active</em> in <a href="<?= SITE_URL ?>/admin/notify-subscribers.php" style="color:#00A878">Admin → Notif. Subs</a></li>
      <li><strong style="color:var(--white)">Test campaign:</strong> Create a campaign in <a href="<?= SITE_URL ?>/admin/notify-campaigns.php" style="color:#00A878">Admin → Notif. Camps</a> → use "Send test to me" → verify email arrives with unsubscribe footer</li>
      <li><strong style="color:var(--white)">Send now:</strong> Set campaign to Ready → Send Now → check report → confirm subscriber received email</li>
      <li><strong style="color:var(--white)">Scheduled send:</strong> Set a campaign scheduled 5 min ahead → wait for cron → confirm status becomes <em>completed</em> in the report</li>
      <li><strong style="color:var(--white)">Unsubscribe:</strong> Click unsubscribe link in received email → confirm status changes to <em>unsubscribed</em></li>
      <li><strong style="color:var(--white)">Preferences:</strong> Use Preferences link → toggle interests → re-subscribe to one interest → confirm preferences saved</li>
      <li><strong style="color:var(--white)">Rate limit:</strong> Submit subscribe form 6+ times from same IP within a minute → confirm "Too many attempts" error appears</li>
      <li><strong style="color:var(--white)">Cron protection:</strong> Visit <code><?= SITE_URL ?>/cron/notify-send-scheduled.php</code> → confirm 403 Forbidden</li>
    </ol>
  </div>

</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
