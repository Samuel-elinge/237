<?php
// TEMPORARY DIAGNOSTIC — DELETE AFTER USE
require_once __DIR__ . '/includes/config.php';

// Only allow from localhost or with a secret token
if ($_GET['t'] ?? '' !== 'diag237') { http_response_code(403); die('Forbidden'); }

header('Content-Type: text/plain');

// listings columns
$cols = db()->query('SHOW COLUMNS FROM listings')->fetchAll(PDO::FETCH_ASSOC);
echo "=== listings columns ===\n";
echo implode(', ', array_column($cols, 'Field')) . "\n\n";

// Check a specific listing's booking_enabled
$slug = $_GET['slug'] ?? 'data-analysis';
$row = db()->prepare("SELECT id, title, featured, booking_enabled FROM listings WHERE slug=? LIMIT 1");
try {
    $row->execute([$slug]);
    $r = $row->fetch();
    echo "=== listing: $slug ===\n";
    print_r($r);
} catch (Exception $e) {
    echo "booking_enabled column error: " . $e->getMessage() . "\n";
}

// events table
echo "\n=== events table ===\n";
try {
    $ev = db()->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_ASSOC);
    echo implode(', ', array_column($ev, 'Field')) . "\n";
    $cnt = db()->query('SELECT COUNT(*) FROM events')->fetchColumn();
    echo "Row count: $cnt\n";
} catch (Exception $e) { echo "MISSING: " . $e->getMessage() . "\n"; }

// offers table
echo "\n=== offers table ===\n";
try {
    $of = db()->query('SHOW COLUMNS FROM offers')->fetchAll(PDO::FETCH_ASSOC);
    echo implode(', ', array_column($of, 'Field')) . "\n";
    $cnt = db()->query('SELECT COUNT(*) FROM offers')->fetchColumn();
    echo "Row count: $cnt\n";
} catch (Exception $e) { echo "MISSING: " . $e->getMessage() . "\n"; }
