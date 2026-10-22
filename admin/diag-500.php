<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "Step 1: PHP works<br>\n";
require_once __DIR__ . '/../includes/config.php';
echo "Step 2: config.php OK<br>\n";
require_once __DIR__ . '/../includes/entitlement-helpers.php';
echo "Step 3: entitlement-helpers.php OK<br>\n";
$pdo = db();
echo "Step 4: db() OK<br>\n";
$r = $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn();
echo "Step 5: offers table OK, count=$r<br>\n";
$r = $pdo->query("SELECT COUNT(*) FROM discount_codes")->fetchColumn();
echo "Step 6: discount_codes OK, count=$r<br>\n";
$r = $pdo->query("SELECT COUNT(*) FROM offer_redemptions")->fetchColumn();
echo "Step 7: offer_redemptions OK, count=$r<br>\n";

// Check columns of offer_redemptions
$cols = $pdo->query("DESCRIBE offer_redemptions")->fetchAll(PDO::FETCH_COLUMN);
echo "offer_redemptions columns: " . implode(', ', $cols) . "<br>\n";

// Check columns of discount_codes
$cols2 = $pdo->query("DESCRIBE discount_codes")->fetchAll(PDO::FETCH_COLUMN);
echo "discount_codes columns: " . implode(', ', $cols2) . "<br>\n";

// Check columns of offers
$cols3 = $pdo->query("DESCRIBE offers")->fetchAll(PDO::FETCH_COLUMN);
echo "offers columns: " . implode(', ', $cols3) . "<br>\n";

// Check if offer_audit_logs exists
try {
    $r2 = $pdo->query("SELECT COUNT(*) FROM offer_audit_logs")->fetchColumn();
    echo "offer_audit_logs OK, count=$r2<br>\n";
} catch(Exception $e) {
    echo "offer_audit_logs MISSING: " . $e->getMessage() . "<br>\n";
}

echo "All OK!";
