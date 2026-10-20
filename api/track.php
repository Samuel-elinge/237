<?php
/**
 * api/track.php — 237Biz Impression Tracker
 * Records a view of a listing, offer or event into marketplace_impressions.
 * Called via fetch() from public pages; responds with JSON.
 * URL: /api/track
 *
 * POST body (JSON or form): { entity_type, entity_id, page_context }
 * or GET:                   ?type=offer&id=5&ctx=offers
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . SITE_URL);
header('Access-Control-Allow-Methods: POST, GET');

// ── Parse input ───────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$json = $raw ? @json_decode($raw, true) : null;

$entityType  = trim($json['entity_type']  ?? $_POST['entity_type']  ?? $_GET['type']    ?? '');
$entityId    = (int)($json['entity_id']   ?? $_POST['entity_id']    ?? $_GET['id']      ?? 0);
$pageContext = trim($json['page_context'] ?? $_POST['page_context'] ?? $_GET['ctx']     ?? '');

// ── Validate ──────────────────────────────────────────────────────────────
$validTypes = ['listing', 'offer', 'event'];
if (!in_array($entityType, $validTypes) || $entityId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

// ── Dedup hash: SHA256(ip + user_agent) — no PII stored ──────────────────
$ip          = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
$visitorHash = hash('sha256', $ip . '|' . $ua);

// ── Rate-limit: skip if same visitor saw same entity in last 30 min ───────
try {
    $recent = db()->prepare("
        SELECT COUNT(*) FROM marketplace_impressions
        WHERE entity_type = ?
          AND entity_id   = ?
          AND visitor_hash = ?
          AND created_at  >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $recent->execute([$entityType, $entityId, $visitorHash]);
    if ((int)$recent->fetchColumn() > 0) {
        echo json_encode(['ok' => true, 'deduped' => true]);
        exit;
    }

    db()->prepare("
        INSERT INTO marketplace_impressions
            (entity_type, entity_id, page_context, visitor_hash, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ")->execute([$entityType, $entityId, $pageContext ?: null, $visitorHash]);

    // Also bump the cached view counter on listings table
    if ($entityType === 'listing') {
        db()->prepare("UPDATE listings SET views = views + 1 WHERE id = ?")
            ->execute([$entityId]);
    }

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error']);
}
