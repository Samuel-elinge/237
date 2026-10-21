<?php
/**
 * cron/notify-send-scheduled.php
 * Sends campaigns whose scheduled_at has passed and status = 'scheduled'.
 *
 * cPanel cron entry (run every 5 minutes):
 *   * /5 * * * * php /home/<cpanel_user>/public_html/cron/notify-send-scheduled.php >> /home/<cpanel_user>/logs/notify-cron.log 2>&1
 *
 * The script is idempotent: a campaign transitions scheduled → sending → completed/failed.
 * If it crashes mid-send, the next cron run skips it (status='sending') to avoid double-sends.
 * An operator can reset a stuck 'sending' campaign via the admin UI.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────
define('CRON_RUN', true); // guard against direct HTTP access in config.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notify-helpers.php';

// Safety valve: reject HTTP requests
if (php_sapi_name() !== 'cli' && !defined('CRON_HTTP_ALLOW')) {
    http_response_code(403);
    exit('CLI only.');
}

$pdo  = db();
$now  = date('Y-m-d H:i:s');
$log  = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('notify-send-scheduled: starting');

// ── Lock: prevent parallel runs clobbering each other ─────────────────────
$lockFile = sys_get_temp_dir() . '/notify_send_scheduled.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('Another instance is running — exiting.');
    exit(0);
}

// ── Pick campaigns due for sending ────────────────────────────────────────
$due = $pdo->prepare("
    SELECT id, name, type, language, subject, body_html
    FROM   notification_campaigns
    WHERE  status = 'scheduled'
      AND  scheduled_at <= ?
    ORDER  BY scheduled_at ASC
    LIMIT  10
");
$due->execute([$now]);
$campaigns = $due->fetchAll();

if (empty($campaigns)) {
    $log('No campaigns due.');
    flock($lock, LOCK_UN);
    exit(0);
}

$log(count($campaigns) . ' campaign(s) due.');

// ── Send each ─────────────────────────────────────────────────────────────
foreach ($campaigns as $camp) {
    $cid  = (int)$camp['id'];
    $log("Campaign #{$cid} "{$camp['name']}": starting");

    // Claim it: scheduled → sending (atomic; skips if another process beat us)
    $claimed = $pdo->prepare("
        UPDATE notification_campaigns
        SET    status='sending', sent_at=NOW(), updated_at=NOW()
        WHERE  id=? AND status='scheduled'
    ");
    $claimed->execute([$cid]);
    if ($claimed->rowCount() === 0) {
        $log("Campaign #{$cid}: already claimed by another process — skipping.");
        continue;
    }

    // Build audience
    $subSQL = "
        SELECT ns.id, ns.email_display, ns.language
        FROM   notification_subscribers ns
        JOIN   notification_preferences np ON np.subscriber_id = ns.id
        WHERE  ns.status = 'active'
    ";
    if ($camp['type'] === 'special_offers') {
        $subSQL .= " AND np.special_offers_enabled = 1";
    } elseif ($camp['type'] === 'events') {
        $subSQL .= " AND np.events_enabled = 1";
    }
    $subRows = $pdo->query($subSQL)->fetchAll();

    // Update recipient_count
    $pdo->prepare("UPDATE notification_campaigns SET recipient_count=? WHERE id=?")
        ->execute([count($subRows), $cid]);

    $sent = 0; $failed = 0;

    foreach ($subRows as $sub) {
        $subId = (int)$sub['id'];

        // Skip if already queued (idempotent re-run)
        $exists = $pdo->prepare("SELECT id FROM notification_campaign_recipients WHERE campaign_id=? AND subscriber_id=?");
        $exists->execute([$cid, $subId]);
        if ($exists->fetch()) continue;

        $lang     = $camp['language'];
        $subject  = $camp['subject'];
        $body     = $camp['body_html'];

        // Append unsubscribe / prefs footer
        $manageToken = notifyGetManageToken($subId);
        $unsubUrl    = SITE_URL . '/notify-unsubscribe.php?token='  . urlencode($manageToken);
        $prefUrl     = SITE_URL . '/notify-preferences.php?token='  . urlencode($manageToken);
        $footer = '<div style="text-align:center;margin-top:2rem;padding-top:1rem;border-top:1px solid #333;font-size:11px;color:#888">'
            . ($lang === 'fr'
                ? '<a href="' . $prefUrl  . '" style="color:#888">Préférences</a> · <a href="' . $unsubUrl . '" style="color:#888">Se désabonner</a>'
                : '<a href="' . $prefUrl  . '" style="color:#888">Preferences</a> · <a href="' . $unsubUrl . '" style="color:#888">Unsubscribe</a>'
              ) . '</div>';

        try {
            $ok = sendMail($sub['email_display'], $subject, $body . $footer);
            $status = $ok ? 'sent' : 'failed';
            $ok ? $sent++ : $failed++;
        } catch (Throwable $e) {
            $status = 'failed';
            $failed++;
            $log("  ✗ {$sub['email_display']}: " . $e->getMessage());
        }

        $pdo->prepare("
            INSERT INTO notification_campaign_recipients (campaign_id, subscriber_id, email, status, created_at)
            VALUES (?,?,?,?,NOW())
        ")->execute([$cid, $subId, $sub['email_display'], $status]);
    }

    // Finalise
    $finalStatus = ($failed > 0 && $sent === 0) ? 'failed' : 'completed';
    $pdo->prepare("UPDATE notification_campaigns SET status=?, updated_at=NOW() WHERE id=?")
        ->execute([$finalStatus, $cid]);

    notifyAuditLog('system', 'cron_send_campaign', 'campaign', $cid,
        "sent={$sent} failed={$failed} status={$finalStatus}", null);

    $log("Campaign #{$cid}: {$finalStatus} — sent={$sent} failed={$failed}");
}

flock($lock, LOCK_UN);
$log('notify-send-scheduled: done');
exit(0);
