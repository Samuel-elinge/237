<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "Step 1: PHP works<br>\n";
require_once __DIR__ . '/includes/config.php';
echo "Step 2: config.php OK<br>\n";
require_once __DIR__ . '/includes/entitlement-helpers.php';
echo "Step 3: entitlement-helpers.php OK<br>\n";
$pdo = db();
echo "Step 4: db() OK<br>\n";
$r = $pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn();
echo "Step 5: offers table OK, count=$r<br>\n";
$r = $pdo->query("SELECT COUNT(*) FROM discount_codes")->fetchColumn();
echo "Step 6: discount_codes OK, count=$r<br>\n";
$r = $pdo->query("SELECT COUNT(*) FROM offer_redemptions")->fetchColumn();
echo "Step 7: offer_redemptions OK, count=$r<br>\n";
echo "All OK!";
