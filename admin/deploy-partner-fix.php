<?php
// Deploy script: fix 500 errors on content, campaigns, reports, automation pages
// Removes non-existent auth.php/helpers.php requires and fixes l.name -> l.title
if (!isset($_GET['key']) || $_GET['key'] !== 'deploy237fix') {
    http_response_code(403); exit('Forbidden');
}

echo "<pre>";

$files = [
    'content'    => __DIR__ . '/../partner/content.php',
    'campaigns'  => __DIR__ . '/../partner/campaigns.php',
    'reports'    => __DIR__ . '/../partner/reports.php',
    'automation' => __DIR__ . '/../partner/automation.php',
];

foreach ($files as $name => $path) {
    echo "\n=== $name.php ===\n";
    $src = file_get_contents($path);
    $fixed = $src;

    // Remove bad requires
    $fixed = str_replace("require_once __DIR__ . '/../includes/auth.php';\n", '', $fixed);
    $fixed = str_replace("require_once __DIR__ . '/../includes/helpers.php';\n", '', $fixed);

    // Fix l.name -> l.title in listings JOINs
    $fixed = str_replace('l.name AS biz_name', 'l.title AS biz_name', $fixed);
    $fixed = str_replace('l.name AS business_name', 'l.title AS business_name', $fixed);

    if ($fixed === $src) {
        echo "✅ Already clean — no changes needed\n";
    } else {
        $hasAuth    = strpos($src, "auth.php") !== false;
        $hasHelpers = strpos($src, "includes/helpers.php") !== false;
        $hasLName   = strpos($src, "l.name AS biz_name") !== false;
        echo ($hasAuth    ? "  Removed: auth.php require\n" : "");
        echo ($hasHelpers ? "  Removed: helpers.php require\n" : "");
        echo ($hasLName   ? "  Fixed: l.name -> l.title\n" : "");

        if (file_put_contents($path, $fixed) !== false) {
            echo "✅ Patched successfully\n";
        } else {
            echo "❌ Write failed — check permissions\n";
        }
    }

    // Verify
    $final = file_get_contents($path);
    echo (strpos($final, "includes/auth.php") === false ? "✅" : "❌") . " No auth.php require\n";
    echo (strpos($final, "includes/helpers.php") === false ? "✅" : "❌") . " No standalone helpers.php require\n";
    echo (strpos($final, "l.name AS biz_name") === false ? "✅" : "❌") . " No l.name AS biz_name\n";
}

echo "\n\n✅ Done! Test pages:\n";
echo "<a href='/partner/content'>Content</a>\n";
echo "<a href='/partner/campaigns'>Campaigns</a>\n";
echo "<a href='/partner/reports'>Reports</a>\n";
echo "<a href='/partner/automation'>Automation</a>\n";

// Self-delete
unlink(__FILE__);
echo "\n🗑️ This deploy script has been deleted.\n";
echo "</pre>";
