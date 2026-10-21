<?php
/**
 * admin/rollup-metrics.php — 237Biz Daily Metrics Rollup
 * Aggregates yesterday's marketplace_impressions + listing_enquiries
 * into listing_metrics_daily for fast dashboard queries.
 *
 * Run daily via cPanel cron:
 *   0 2 * * * php /home/USERNAME/public_html/admin/rollup-metrics.php >> /home/USERNAME/logs/rollup.log 2>&1
 *
 * Can also be triggered manually from the admin panel.
 * CLI or admin-only; exits silently if called via browser without auth.
 */
require_once __DIR__ . '/../includes/config.php';

// ── Auth: CLI always allowed; browser requires admin ─────────────────────────
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    if (!function_exists('isAdmin') || !isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    header('Content-Type: application/json');
}

function log_msg(string $msg): void {
    echo date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
}

// ── Target date: default = yesterday ─────────────────────────────────────────
$targetDate = $_GET['date'] ?? (new DateTime('yesterday'))->format('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    log_msg("ERROR: invalid date format. Use YYYY-MM-DD.");
    exit(1);
}

log_msg("Rolling up metrics for {$targetDate}...");

try {
    $pdo = db();

    // ── 1. Impressions / clicks from marketplace_impressions ──────────────────
    $impSt = $pdo->prepare("
        SELECT
            entity_id                                                          AS listing_id,
            COUNT(CASE WHEN (event_subtype IS NULL OR event_subtype='')  THEN 1 END) AS views,
            COUNT(CASE WHEN event_subtype='phone_click'     THEN 1 END)      AS phone_clicks,
            COUNT(CASE WHEN event_subtype='whatsapp_click'  THEN 1 END)      AS wa_clicks,
            COUNT(CASE WHEN event_subtype='website_click'   THEN 1 END)      AS web_clicks,
            COUNT(CASE WHEN event_subtype='offer_click'     THEN 1 END)      AS offer_views
        FROM marketplace_impressions
        WHERE entity_type = 'listing'
          AND DATE(created_at) = ?
        GROUP BY entity_id
    ");
    $impSt->execute([$targetDate]);
    $impressionRows = $impSt->fetchAll(PDO::FETCH_ASSOC);

    // ── 2. Enquiries from listing_enquiries ───────────────────────────────────
    $enqSt = $pdo->prepare("
        SELECT listing_id, COUNT(*) AS enquiries
        FROM listing_enquiries
        WHERE DATE(created_at) = ?
        GROUP BY listing_id
    ");
    $enqSt->execute([$targetDate]);
    $enquiryRows = $enqSt->fetchAll(PDO::FETCH_ASSOC);

    // Index enquiries by listing_id
    $enqMap = [];
    foreach ($enquiryRows as $r) {
        $enqMap[(int)$r['listing_id']] = (int)$r['enquiries'];
    }

    // ── 3. Bookings (if bookings table exists) ────────────────────────────────
    $bookingMap = [];
    try {
        $bkSt = $pdo->prepare("
            SELECT listing_id,
                   COUNT(*)                                          AS bookings_req,
                   COUNT(CASE WHEN status='confirmed' THEN 1 END)   AS bookings_conf
            FROM bookings
            WHERE DATE(created_at) = ?
            GROUP BY listing_id
        ");
        $bkSt->execute([$targetDate]);
        foreach ($bkSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bookingMap[(int)$r['listing_id']] = [
                'req'  => (int)$r['bookings_req'],
                'conf' => (int)$r['bookings_conf'],
            ];
        }
    } catch (Exception $e) {
        // bookings table may not exist
    }

    // ── 4. Upsert rows into listing_metrics_daily ─────────────────────────────
    $upsert = $pdo->prepare("
        INSERT INTO listing_metrics_daily
            (listing_id, metric_date, views, phone_clicks, wa_clicks, web_clicks,
             enquiries, bookings_req, bookings_conf, offer_views)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            views         = VALUES(views),
            phone_clicks  = VALUES(phone_clicks),
            wa_clicks     = VALUES(wa_clicks),
            web_clicks    = VALUES(web_clicks),
            enquiries     = VALUES(enquiries),
            bookings_req  = VALUES(bookings_req),
            bookings_conf = VALUES(bookings_conf),
            offer_views   = VALUES(offer_views)
    ");

    $upserted = 0;
    foreach ($impressionRows as $r) {
        $lid  = (int)$r['listing_id'];
        $bk   = $bookingMap[$lid] ?? ['req' => 0, 'conf' => 0];
        $enqs = $enqMap[$lid] ?? 0;

        $upsert->execute([
            $lid,
            $targetDate,
            (int)$r['views'],
            (int)$r['phone_clicks'],
            (int)$r['wa_clicks'],
            (int)$r['web_clicks'],
            $enqs,
            $bk['req'],
            $bk['conf'],
            (int)$r['offer_views'],
        ]);
        $upserted++;
    }

    // Listings with enquiries but no impression rows
    foreach ($enqMap as $lid => $enqs) {
        $alreadyDone = false;
        foreach ($impressionRows as $r) {
            if ((int)$r['listing_id'] === $lid) { $alreadyDone = true; break; }
        }
        if (!$alreadyDone) {
            $bk = $bookingMap[$lid] ?? ['req' => 0, 'conf' => 0];
            $upsert->execute([$lid, $targetDate, 0, 0, 0, 0, $enqs, $bk['req'], $bk['conf'], 0]);
            $upserted++;
        }
    }

    log_msg("Done. {$upserted} listing(s) rolled up for {$targetDate}.");

    // ── 5. Log to phase4_audit_log ────────────────────────────────────────────
    try {
        $pdo->prepare("INSERT INTO phase4_audit_log (actor_id, target_type, target_id, action, detail, ip) VALUES (?,?,?,?,?,?)")
            ->execute([0, 'metrics_rollup', 0, 'daily_rollup', json_encode(['date'=>$targetDate,'rows'=>$upserted]), $_SERVER['REMOTE_ADDR'] ?? 'cli']);
    } catch (Exception $e) {}

    if (!$isCli) {
        echo json_encode(['ok' => true, 'date' => $targetDate, 'rows' => $upserted]);
    }

} catch (Exception $e) {
    log_msg("ERROR: " . $e->getMessage());
    if (!$isCli) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}
