<?php
// ONE-TIME: Enable booking_enabled for all featured listings
// DELETE after running
require_once __DIR__ . '/../includes/config.php';
if (($_GET['t'] ?? '') !== 'eb237') { http_response_code(403); die('Forbidden'); }
header('Content-Type: text/plain');
$n = db()->exec("UPDATE listings SET booking_enabled = 1 WHERE featured = 1 AND booking_enabled = 0");
echo "Updated $n listing(s) — booking_enabled set to 1 for all featured listings.\n";
