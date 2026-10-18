<?php
// Temporary diagnostic — DELETE after fixing
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

echo '<pre>';
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'Testing nav.php include...' . "\n";
require_once __DIR__ . '/../includes/nav.php';
echo 'nav.php loaded OK' . "\n";
ob_start();
adminNav('dashboard');
adminNavAssets();
$out = ob_get_clean();
echo 'adminNav() output length: ' . strlen($out) . "\n";
echo '</pre>';
echo '<hr>' . $out;
