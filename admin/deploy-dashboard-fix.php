<?php
// One-time deploy script — deletes itself after running
if (!isset($_GET['key']) || $_GET['key'] !== 'deploy237fix') {
    http_response_code(403); exit('Forbidden');
}

$targetFile = __DIR__ . '/../partner/dashboard.php';
$backupFile = __DIR__ . '/../partner/dashboard.php.bak';

// Read current file to check version
$current = file_get_contents($targetFile);
$hasAuthRequire = strpos($current, "require_once __DIR__ . '/../includes/auth.php'") !== false;
$hasHelpersRequire = strpos($current, "require_once __DIR__ . '/../includes/helpers.php'") !== false;

echo "<pre>";
echo "Current dashboard.php status:\n";
echo ($hasAuthRequire ? "❌ HAS auth.php require (needs removing)\n" : "✅ No auth.php require\n");
echo ($hasHelpersRequire ? "❌ HAS helpers.php require (needs removing)\n" : "✅ No helpers.php require\n");

if ($hasAuthRequire || $hasHelpersRequire) {
    // Make backup
    copy($targetFile, $backupFile);
    echo "\n✅ Backed up to dashboard.php.bak\n";

    // Patch: remove the two bad require lines
    $fixed = $current;
    $fixed = str_replace("require_once __DIR__ . '/../includes/auth.php';\n", '', $fixed);
    $fixed = str_replace("require_once __DIR__ . '/../includes/helpers.php';\n", '', $fixed);

    // Also fix l.name -> l.title, recorded_at -> computed_at, campaigns -> partner_campaigns
    // (in case this is an older version)
    $fixed = str_replace("l.name AS biz_name", "l.title AS biz_name", $fixed);
    $fixed = str_replace("l.name AS business_name", "l.title AS business_name", $fixed);
    $fixed = str_replace("gsh.recorded_at", "gsh.computed_at", $fixed);

    // Fix campaigns table references (but not partner_campaigns)
    $fixed = preg_replace('/\bFROM campaigns\b(?!\s+c\b)/', 'FROM partner_campaigns', $fixed);
    $fixed = str_replace("FROM campaigns c ", "FROM partner_campaigns c ", $fixed);
    $fixed = str_replace("FROM campaigns WHERE", "FROM partner_campaigns WHERE", $fixed);

    // Fix NULLS LAST (MySQL doesn't support it)
    $fixed = str_replace("NULLS LAST, ", "", $fixed);

    if (file_put_contents($targetFile, $fixed) !== false) {
        echo "✅ dashboard.php patched successfully!\n";
    } else {
        echo "❌ Failed to write dashboard.php — check permissions\n";
    }
} else {
    echo "\n✅ dashboard.php already clean — no changes needed\n";
}

// Also patch partner-helpers.php
$helpersFile = __DIR__ . '/../includes/partner-helpers.php';
$helpers = file_get_contents($helpersFile);
$helpersFixed = $helpers;
$helpersFixed = str_replace("l.name AS biz_name", "l.title AS biz_name", $helpersFixed);
$helpersFixed = str_replace("l.name AS business_name", "l.title AS business_name", $helpersFixed);
$helpersFixed = str_replace(", l.hours,", ",", $helpersFixed);
$helpersFixed = str_replace(", l.hours", "", $helpersFixed);
$helpersFixed = preg_replace('/\bFROM campaigns\b/', 'FROM partner_campaigns', $helpersFixed);
$helpersFixed = str_replace("FROM campaigns WHERE", "FROM partner_campaigns WHERE", $helpersFixed);

if ($helpersFixed !== $helpers) {
    file_put_contents($helpersFile, $helpersFixed);
    echo "\n✅ partner-helpers.php patched\n";
} else {
    echo "\n✅ partner-helpers.php already clean\n";
}

echo "\n--- Verifying final state ---\n";
$final = file_get_contents($targetFile);
echo (strpos($final, 'auth.php') === false ? "✅" : "❌") . " No auth.php require\n";
echo (strpos($final, "includes/helpers.php") === false ? "✅" : "❌") . " No helpers.php require\n";
echo (strpos($final, "l.name AS biz_name") === false ? "✅" : "❌") . " No l.name references\n";
echo (strpos($final, "gsh.recorded_at") === false ? "✅" : "❌") . " No recorded_at references\n";

echo "\n✅ Done! Now test: <a href='/partner/dashboard.php'>partner/dashboard.php</a>\n";

// Self-delete
unlink(__FILE__);
echo "🗑️ This deploy script has been deleted.\n";
echo "</pre>";
