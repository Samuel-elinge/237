<?php
if (!isset($_GET['key']) || $_GET['key'] !== 'debug237') {
    http_response_code(403); exit('Forbidden');
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/config.php';
$pdo = db();
echo "<pre>";

// Check listings columns
echo "=== listings columns ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM listings")->fetchAll() as $c)
    echo $c['Field'] . " (" . $c['Type'] . ")\n";

// Check campaigns columns
echo "\n=== campaigns columns ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM campaigns")->fetchAll() as $c)
    echo $c['Field'] . " (" . $c['Type'] . ")\n";

// Check growth_score_history columns
echo "\n=== growth_score_history columns ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM growth_score_history")->fetchAll() as $c)
    echo $c['Field'] . " (" . $c['Type'] . ")\n";

// Check content_items columns
echo "\n=== content_items columns ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM content_items")->fetchAll() as $c)
    echo $c['Field'] . " (" . $c['Type'] . ")\n";

// Check partner_audit_log columns
echo "\n=== partner_audit_log columns ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM partner_audit_log")->fetchAll() as $c)
    echo $c['Field'] . " (" . $c['Type'] . ")\n";

echo "</pre>";
