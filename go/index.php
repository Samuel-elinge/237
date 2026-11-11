<?php
/**
 * go/index.php — Tracking link redirect handler
 * URL: /go/{ref_code}
 * Routes via .htaccess: RewriteRule ^go/([A-Z0-9]+)$ go/index.php?ref=$1 [L,QSA]
 */
require_once __DIR__ . '/../includes/config.php';

$ref = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_GET['ref'] ?? $_GET['r'] ?? ''));

if (!$ref) {
    header('Location: ' . SITE_URL); exit;
}

$pdo = db();
$st  = $pdo->prepare("SELECT id, target_url, partner_id, listing_id FROM partner_tracking_links WHERE ref_code=?");
$st->execute([$ref]);
$link = $st->fetch();

if (!$link) {
    header('Location: ' . SITE_URL); exit;
}

// Increment click counter
$pdo->prepare("UPDATE partner_tracking_links SET clicks = clicks + 1 WHERE id=?")->execute([$link['id']]);

// Log to marketplace_impressions if for a listing
if ($link['listing_id']) {
    $ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $hash = hash('sha256', $ip . '|' . $ua);

    // 10-min dedup per ref/visitor
    $recent = $pdo->prepare("SELECT COUNT(*) FROM marketplace_impressions
        WHERE entity_type='listing' AND entity_id=? AND visitor_hash=? AND source_ref=?
        AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $recent->execute([$link['listing_id'], $hash, $ref]);

    if (!$recent->fetchColumn()) {
        $ins = $pdo->prepare("INSERT INTO marketplace_impressions
            (entity_type, entity_id, event_subtype, visitor_hash, source_ref, partner_id, page_context)
            VALUES ('listing',?,  'tracking_link_click', ?, ?, ?, 'partner_saleskit')");
        $ins->execute([$link['listing_id'], $hash, $ref, $link['partner_id']]);
    }
}

header('Location: ' . $link['target_url']);
exit;
