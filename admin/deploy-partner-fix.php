<?php
// Deploy script: patch all 4 broken partner pages on the live server
// Removes non-existent requires + fixes column/table name mismatches
if (!isset($_GET['key']) || $_GET['key'] !== 'deploy237fix') {
    http_response_code(403); exit('Forbidden');
}
echo "<pre>";

$files = [
    'content'    => realpath(__DIR__ . '/../partner/content.php'),
    'campaigns'  => realpath(__DIR__ . '/../partner/campaigns.php'),
    'reports'    => realpath(__DIR__ . '/../partner/reports.php'),
    'automation' => realpath(__DIR__ . '/../partner/automation.php'),
];

foreach ($files as $name => $path) {
    echo "\n=== $name.php ===\n";
    $src = file_get_contents($path);
    $fixed = $src;

    // Remove dead requires
    $fixed = str_replace("require_once __DIR__ . '/../includes/auth.php';\n", '', $fixed);
    $fixed = str_replace("require_once __DIR__ . '/../includes/helpers.php';\n", '', $fixed);

    // Fix column names
    $fixed = str_replace('l.name AS biz_name',      'l.title AS biz_name',      $fixed);
    $fixed = str_replace('l.name AS business_name', 'l.title AS business_name', $fixed);

    // Fix table names
    $fixed = preg_replace('/\bFROM campaigns\b/',   'FROM partner_campaigns',   $fixed);
    $fixed = preg_replace('/\bINTO campaigns\b/',   'INTO partner_campaigns',   $fixed);
    $fixed = preg_replace('/\bUPDATE campaigns\b/', 'UPDATE partner_campaigns', $fixed);

    if ($fixed === $src) {
        echo "✅ Already clean, no changes\n";
    } else {
        file_put_contents($path, $fixed);
        echo "✅ Patched\n";
    }

    // Verify
    $verify = file_get_contents($path);
    $ok = strpos($verify, 'auth.php') === false
       && strpos($verify, 'includes/helpers.php') === false
       && strpos($verify, 'l.name AS biz_name') === false
       && !preg_match('/FROM campaigns[^_]/', $verify);
    echo ($ok ? "✅" : "❌") . " Verification\n";
}

echo "\n✅ Done! Testing pages:\n";
$urls = ['content','campaigns','reports','automation'];
foreach ($urls as $u) echo "  https://237biz.net/partner/$u\n";

unlink(__FILE__);
echo "\n🗑️ Deploy script deleted.\n";
echo "</pre>";
