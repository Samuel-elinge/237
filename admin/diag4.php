<?php
require_once '../includes/config.php';
requireLogin();
if (!isAdmin()) die('Admin only');

header('Content-Type: text/plain');

// Enquiries for listing 121
$stmt = db()->query("SELECT id, sender_name, sender_email, created_at FROM listing_enquiries WHERE listing_id=121 ORDER BY id");
$rows = $stmt->fetchAll();
echo "=== Enquiries for listing 121 (" . count($rows) . " total) ===\n";
foreach ($rows as $r) {
    echo "  #{$r['id']} {$r['sender_name']} <{$r['sender_email']}> @ {$r['created_at']}\n";
}

// Rate limit table
echo "\n=== enquiry_rate_limit table ===\n";
try {
    $stmt = db()->query("SELECT * FROM enquiry_rate_limit ORDER BY id DESC LIMIT 20");
    $rows = $stmt->fetchAll();
    echo "Rows: " . count($rows) . "\n";
    foreach ($rows as $r) {
        echo "  ip=" . substr($r['ip_hash'],0,16) . "... listing_id={$r['listing_id']} submitted_at={$r['submitted_at']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
