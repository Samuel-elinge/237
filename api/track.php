<?php
/**
 * api/track.php — 237Biz Impression & Click Tracker
 * Records views and click events into marketplace_impressions.
 * Called via fetch() from public pages; responds with JSON.
 * URL: /api/track
 *
 * POST body (JSON): { entity_type, entity_id, page_context, event_subtype }
 * event_subtype: null/omit = page view; or phone_click, whatsapp_click,
 *                website_click, offer_click, booking_click
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . SITE_URL);
header('Access-Control-Allow-Methods: POST, GET');

// ── Parse input ───────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$json = $raw ? @json_decode($raw, true) : null;

$entityType   = trim($json['entity_type']   ?? $_POST['entity_type']   ?? $_GET['type']    ?? '');
$entityId     = (int)($json['entity_id']    ?? $_POST['entity_id']     ?? $_GET['id']      ?? 0);
$pageContext  = trim($json['page_context']  ?? $_POST['page_context']  ?? $_GET['ctx']     ?? '');
$eventSubtype = trim($json['event_subtype'] ?? $_POST['event_subtype'] ?? $_GET['sub']     ?? '');

// ── Validate ──────────────────────────────────────────────────────────────
$validTypes    = ['listing', 'offer', 'event'];
$validSubtypes = ['', 'phone_click', 'whatsapp_click', 'website_click', 'offer_click', 'booking_click'];
if (!in_array($entityType, $validTypes) || $entityId <= 0 || !in_array($eventSubtype, $validSubtypes)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

// ── Dedup hash: SHA256(ip + user_agent) — no PII stored ──────────────────
$ip          = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
$visitorHash = hash('sha256', $ip . '|' . $ua);

// ── Click events: lighter dedup (10 min per subtype) ─────────────────────
// View events: 30-min dedup per visitor/entity
$isClick     = $eventSubtype !== '';
$dedupWindow = $isClick ? 10 : 30;

try {
    $subtypeClause = $isClick ? 'AND event_subtype = ?' : 'AND (event_subtype IS NULL OR event_subtype = \'\')';
    $params = $isClick
        ? [$entityType, $entityId, $visitorHash, $eventSubtype]
        : [$entityType, $entityId, $visitorHash];

    $recentQ = "
        SELECT COUNT(*) FROM marketplace_impressions
        WHERE entity_type   = ?
          AND entity_id     = ?
          AND visitor_hash  = ?
          $subtypeClause
          AND created_at   >= DATE_SUB(NOW(), INTERVAL {$dedupWindow} MINUTE)
    ";
    $recent = db()->prepare($recentQ);
    $recent->execute($params);
    if ((int)$recent->fetchColumn() > 0) {
        echo json_encode(['ok' => true, 'deduped' => true]);
        exit;
    }

    // Insert impression / click row
    $subtypeVal = $eventSubtype ?: null;
    try {
        // Try with event_subtype column (Phase 4 migration run)
        db()->prepare("
            INSERT INTO marketplace_impressions
                (entity_type, entity_id, page_context, visitor_hash, event_subtype, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$entityType, $entityId, $pageContext ?: null, $visitorHash, $subtypeVal]);
    } catch (Exception $colErr) {
        // Fallback: column not yet added — insert without it
        db()->prepare("
            INSERT INTO marketplace_impressions
                (entity_type, entity_id, page_context, visitor_hash, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$entityType, $entityId, $pageContext ?: null, $visitorHash]);
    }

    // Bump cached view counter on listings (views only, not clicks)
    if ($entityType === 'listing' && !$isClick) {
        db()->prepare("UPDATE listings SET views = views + 1 WHERE id = ?")
            ->execute([$entityId]);
    }

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error']);
}
