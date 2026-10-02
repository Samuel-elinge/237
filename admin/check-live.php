<?php
if (!isset($_GET['key']) || $_GET['key'] !== 'checklive237') {
    http_response_code(403); exit('Forbidden');
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/config.php';
$pdo = db();

// Set up session as approved partner
$row = $pdo->query("SELECT * FROM partner_profiles WHERE status='approved' LIMIT 1")->fetch();
$_SESSION['user_id'] = $row['user_id'];

echo "<pre>";

$pages = ['content','campaigns','reports','automation'];
foreach ($pages as $page) {
    $path = __DIR__ . "/../partner/{$page}.php";
    echo "\n=== $page.php ===\n";
    if (!file_exists($path)) { echo "FILE NOT FOUND\n"; continue; }

    $src = file_get_contents($path);
    echo "auth.php require: "    . (strpos($src, 'auth.php') !== false ? "❌ PRESENT" : "✅ gone") . "\n";
    echo "helpers.php require: " . (strpos($src, 'includes/helpers.php') !== false ? "❌ PRESENT" : "✅ gone") . "\n";
    echo "l.name AS biz_name: "  . (strpos($src, 'l.name AS biz_name') !== false ? "❌ PRESENT" : "✅ gone") . "\n";
    echo "FROM campaigns: "      . (preg_match('/FROM campaigns[^_]/', $src) ? "❌ PRESENT" : "✅ gone") . "\n";

    // Try including it and catch error
    ob_start();
    try {
        include $path;
        $out = ob_get_clean();
        echo "Include result: ✅ loaded (" . strlen($out) . " bytes)\n";
    } catch (Throwable $e) {
        ob_end_clean();
        echo "Include result: ❌ " . $e->getMessage() . " (line " . $e->getLine() . " of " . basename($e->getFile()) . ")\n";
    }
}
echo "</pre>";
