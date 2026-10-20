<?php
/**
 * cron/expire-products.php
 * Run every hour via cPanel Cron Jobs:
 *   php /home/youraccount/public_html/cron/expire-products.php
 *
 * 1. Expires active promotions whose ends_at < NOW()
 * 2. Expires active subscriptions whose ends_at < NOW()
 * 3. Cancels promotions that have been in 'scheduled' state past their starts_at
 *    (edge case: should go active — handled here instead)
 */

// CLI or web guard
if (PHP_SAPI !== 'cli' && !isset($_SERVER['CRON_SECRET'])) {
    http_response_code(403);
    exit('Forbidden');
}

define('CRON_RUNNING', true);
require_once __DIR__ . '/../includes/config.php';

$log = [];

/* ── 1. Expire active promotions ─────────────────────────── */
$expiredPromos = db()->query("SELECT id, ref, listing_id, user_id
    FROM promotion_orders
    WHERE promo_status='active' AND ends_at IS NOT NULL AND ends_at < NOW()")->fetchAll();

foreach ($expiredPromos as $p) {
    db()->prepare("UPDATE promotion_orders SET promo_status='completed' WHERE id=?")
        ->execute([$p['id']]);

    $log[] = "[PROMO EXPIRED] ref={$p['ref']} listing={$p['listing_id']}";
}

/* ── 2. Activate scheduled promotions ────────────────────── */
$scheduledPromos = db()->query("SELECT id, ref, listing_id
    FROM promotion_orders
    WHERE promo_status='scheduled' AND starts_at IS NOT NULL AND starts_at <= NOW()")->fetchAll();

foreach ($scheduledPromos as $p) {
    db()->prepare("UPDATE promotion_orders SET promo_status='active' WHERE id=?")
        ->execute([$p['id']]);
    $log[] = "[PROMO ACTIVATED] ref={$p['ref']} listing={$p['listing_id']}";
}

/* ── 3. Expire active subscriptions ──────────────────────── */
$expiredSubs = db()->query("SELECT bs.id, bs.listing_id, bs.user_id,
    mo.id AS mon_id, mo.ref, u.email, u.name
    FROM business_subscriptions bs
    LEFT JOIN monetisation_orders mo ON mo.linked_id = bs.id AND mo.product_type='subscription'
    LEFT JOIN users u ON u.id = bs.user_id
    WHERE bs.status='active' AND bs.ends_at IS NOT NULL AND bs.ends_at < NOW()")->fetchAll();

foreach ($expiredSubs as $s) {
    db()->prepare("UPDATE business_subscriptions SET status='expired' WHERE id=?")
        ->execute([$s['id']]);

    $log[] = "[SUB EXPIRED] listing={$s['listing_id']} user={$s['user_id']}";

    // Optional: send expiry reminder email
    if ($s['email'] && file_exists(__DIR__ . '/../includes/notify.php')) {
        require_once __DIR__ . '/../includes/notify.php';
        sendMail($s['email'],
            'Your 237Biz subscription has expired',
            '<h2 style="color:#fff;font-family:Georgia,serif;">Subscription Expired</h2>
             <p style="color:rgba(255,255,255,0.7);">Hi ' . htmlspecialchars($s['name'], ENT_QUOTES) . ',</p>
             <p style="color:rgba(255,255,255,0.7);">Your subscription has expired. Renew to keep your listing promoted.</p>
             <a href="' . SITE_URL . '/subscribe?listing_id=' . $s['listing_id'] . '" style="background:#00A878;color:#fff;padding:.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">Renew Now</a>'
        );
    }
}

/* ── 4. Flag past-due subscriptions (ends_at past, not yet expired) ── */
// Already handled above (status=expired). This catches any missed by edge cases:
$pastDue = db()->query("SELECT id FROM business_subscriptions
    WHERE status='cancellation_scheduled' AND ends_at IS NOT NULL AND ends_at < NOW()")->fetchAll();
foreach ($pastDue as $s) {
    db()->prepare("UPDATE business_subscriptions SET status='cancelled' WHERE id=?")->execute([$s['id']]);
    $log[] = "[SUB CANCELLED] id={$s['id']}";
}

/* ── Summary ─────────────────────────────────────────────── */
$summary = date('Y-m-d H:i:s') . " | expired_promos=" . count($expiredPromos)
    . " activated_promos=" . count($scheduledPromos)
    . " expired_subs=" . count($expiredSubs)
    . " cancelled_subs=" . count($pastDue);

// Write to log file
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/cron-expire.log';
$logLines = implode("\n", array_merge([$summary], $log));
file_put_contents($logFile, $logLines . "\n", FILE_APPEND | LOCK_EX);

if (PHP_SAPI === 'cli') {
    echo $summary . "\n";
    if ($log) echo implode("\n", $log) . "\n";
}
