<?php
require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';
requireGrowthPartner();
$pdo = db();
header('Content-Type: text/plain');

// Check tables
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "=== TABLES ===\n";
foreach ($tables as $t) echo "  $t\n";

// Check businesses columns
echo "\n=== businesses columns ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM businesses")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  " . $c['Field'] . " " . $c['Type'] . "\n";

// Check partner_business_assignments columns if exists
if (in_array('partner_business_assignments', $tables)) {
    echo "\n=== partner_business_assignments columns ===\n";
    $cols = $pdo->query("SHOW COLUMNS FROM partner_business_assignments")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  " . $c['Field'] . " " . $c['Type'] . "\n";
} else {
    echo "\n!! partner_business_assignments does NOT exist\n";
}

// Check business_prospects columns if exists
if (in_array('business_prospects', $tables)) {
    echo "\n=== business_prospects columns ===\n";
    $cols = $pdo->query("SHOW COLUMNS FROM business_prospects")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  " . $c['Field'] . " " . $c['Type'] . "\n";
} else {
    echo "\n!! business_prospects does NOT exist\n";
}
