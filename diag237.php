<?php
/**
 * admin/diag-listing.php — temporary diagnostic for listing 500 errors
 * DELETE after use. Restricted to admin only.
 */
require_once __DIR__ . '/../includes/config.php';
if (!isAdmin()) die('Admin only');

header('Content-Type: text/plain');

echo "=== 237Biz Listing Diagnostic ===\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Test listing_packages table
echo "--- listing_packages ---\n";
try {
    $r = db()->query("SELECT COUNT(*) FROM listing_packages")->fetchColumn();
    echo "OK: $r rows\n";
    $cols = db()->query("SHOW COLUMNS FROM listing_packages")->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns: " . implode(', ', $cols) . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n--- listings table ---\n";
try {
    $r = db()->query("SELECT COUNT(*) FROM listings WHERE status='approved'")->fetchColumn();
    echo "OK: $r approved listings\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n--- listing_views table ---\n";
try {
    db()->query("SELECT 1 FROM listing_views LIMIT 1");
    echo "OK: table exists\n";
} catch (Exception $e) {
    echo "MISSING (non-fatal): " . $e->getMessage() . "\n";
}

echo "\n--- review_votes table ---\n";
try {
    db()->query("SELECT 1 FROM review_votes LIMIT 1");
    echo "OK: table exists\n";
} catch (Exception $e) {
    echo "MISSING: " . $e->getMessage() . "\n";
}

echo "\n--- Test full listing query for a slug ---\n";
try {
    $st = db()->prepare("
        SELECT l.*, c.name_en AS cat_en FROM listings l
        JOIN categories c ON c.id = l.category_id
        JOIN locations loc ON loc.id = l.location_id
        WHERE l.status = 'approved' LIMIT 1
    ");
    $st->execute();
    $row = $st->fetch();
    if ($row) {
        echo "OK: found listing id={$row['id']} slug={$row['slug']}\n";
        echo "Test URL: " . SITE_URL . "/listing/{$row['slug']}\n";
    } else {
        echo "No approved listings found\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n--- Phase 3 tables (optional) ---\n";
foreach (['business_subscriptions','promotion_orders','partner_commissions','monetisation_orders'] as $tbl) {
    try {
        db()->query("SELECT 1 FROM $tbl LIMIT 1");
        echo "OK: $tbl exists\n";
    } catch (Exception $e) {
        echo "MISSING: $tbl — " . $e->getMessage() . "\n";
    }
}

echo "\n=== Done ===\n";
