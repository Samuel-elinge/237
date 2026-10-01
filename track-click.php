<?php
// track-click.php — called via JS to record WhatsApp/phone/email/website clicks
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . SITE_URL);

$listingId = (int)($_POST['listing_id'] ?? 0);
$type      = $_POST['type'] ?? '';

$validTypes = ['whatsapp','phone','email','website','facebook'];
if (!$listingId || !in_array($type, $validTypes)) {
    echo json_encode(['ok' => false]);
    exit;
}

// Verify listing exists
$st = db()->prepare("SELECT id FROM listings WHERE id=? AND status='approved'");
$st->execute([$listingId]);
if (!$st->fetch()) { echo json_encode(['ok' => false]); exit; }

db()->prepare("INSERT INTO listing_clicks (listing_id, type) VALUES (?,?)")->execute([$listingId, $type]);
echo json_encode(['ok' => true]);
