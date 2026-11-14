<?php
/**
 * api/card-track.php — 237Biz Digital Card Event Tracker
 * Records card-specific engagement events into marketplace_impressions.
 * Called via fetch() from card.php; responds with JSON.
 *
 * POST body (JSON): { listing_id, event_type, source }
 * event_type: card_view, contact_download, phone_click, whatsapp_click,
 *             website_click, directions_click, social_click, profile_click,
 *             share_click, product_click, offer_click, event_click
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . SITE_URL);
header('Access-Control-Allow-Methods: POST');

$raw  = file_get_contents('php://input');
$json = $raw ? @json_decode($raw, true) : null;

$listingId = (int)($json['listing_id'] ?? $_POST['listing_id'] ?? 0);
$eventType = trim($json['event_type'] ?? $_POST['event_type'] ?? '');
$source    = trim($json['source']     ?? $_POST['source']     ?? 'direct');

$validTypes = [
    'card_view', 'contact_download', 'phone_click', 'whatsapp_click',
    'website_click', 'directions_click', 'social_click', 'profile_click',
    'share_click', 'product_click', 'offer_click', 'event_click',
];

if ($listingId <= 0 || !in_array($eventType, $validTypes)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

// Dedup hash — no PII stored
$ip          = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
$visitorHash = hash('sha256', $ip . '|' . $ua);

// Views: 30-min dedup; clicks: 10-min dedup
$isView      = ($eventType === 'card_view');
$dedupWindow = $isView ? 30 : 10;

try {
    $recent = db()->prepare("
        SELECT COUNT(*) FROM marketplace_impressions
        WHERE entity_type   = 'listing'
          AND entity_id     = ?
          AND visitor_hash  = ?
          AND event_subtype = ?
          AND created_at   >= DATE_SUB(NOW(), INTERVAL {$dedupWindow} MINUTE)
    ");
    $recent->execute([$listingId, $visitorHash, 'card_' . $eventType]);
    if ((int)$recent->fetchColumn() > 0) {
        echo json_encode(['ok' => true, 'deduped' => true]);
        exit;
    }

    db()->prepare("
        INSERT INTO marketplace_impressions
            (entity_type, entity_id, page_context, visitor_hash, event_subtype, created_at)
        VALUES ('listing', ?, ?, ?, ?, NOW())
    ")->execute([$listingId, 'card_' . $source, $visitorHash, 'card_' . $eventType]);

    // Bump cached views counter on card_view
    if ($isView) {
        db()->prepare("UPDATE listings SET views = views + 1 WHERE id = ?")
            ->execute([$listingId]);
    }

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error']);
}
