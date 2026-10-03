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

$pages = [
    'dashboard','portfolio','tasks','leads','campaigns','content','reports',
    'recommendations','alerts','automation','opportunities','assignments',
    'commissions','resources','templates','agreements','academy','ai-plan',
    'business','onboarding','profile','feedback','communication','referrals',
    'capacity','certifications','pending'
];

$passCount = 0; $failCount = 0;

foreach ($pages as $page) {
    $path = __DIR__ . "/../partner/{$page}.php";
    echo "\n=== $page.php ===\n";
    if (!file_exists($path)) { echo "FILE NOT FOUND\n"; $failCount++; continue; }

    $src = file_get_contents($path);
    $checks = [
        'auth.php require'    => strpos($src, "require_once __DIR__ . '/../includes/auth.php'") === false,
        'helpers.php require' => strpos($src, "require_once __DIR__ . '/../includes/helpers.php'") === false,
        'l.name AS biz_name'  => strpos($src, 'l.name AS biz_name') === false,
        'FROM campaigns bare' => !preg_match('/FROM campaigns[^_]/', $src),
        'partner-lang loaded' => strpos($src, 'partner-lang.php') !== false,
    ];
    foreach ($checks as $label => $ok) {
        echo ($ok ? "✅" : "❌") . " $label\n";
    }

    // Try including it and catch error
    ob_start();
    try {
        include $path;
        $out = ob_get_clean();
        echo "✅ Loaded (" . number_format(strlen($out)) . " bytes)\n";
        $passCount++;
    } catch (Throwable $e) {
        ob_end_clean();
        echo "❌ " . $e->getMessage() . " (line " . $e->getLine() . " of " . basename($e->getFile()) . ")\n";
        $failCount++;
    }
}

echo "\n\n========================================\n";
echo "SUMMARY: $passCount passed, $failCount failed\n";
echo "========================================\n";
echo "</pre>";
