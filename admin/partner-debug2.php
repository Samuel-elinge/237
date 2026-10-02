<?php
if (!isset($_GET['key']) || $_GET['key'] !== 'debug237b') {
    http_response_code(403); exit('Forbidden');
}
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre>";

// Show live dashboard.php requires section
$dashFile = realpath(__DIR__ . '/../partner/dashboard.php');
echo "=== Live dashboard.php path: $dashFile ===\n\n";

$lines = file($dashFile);
echo "=== First 15 lines ===\n";
foreach (array_slice($lines, 0, 15) as $i => $l) echo ($i+1).": $l";

echo "\n=== All require/include lines ===\n";
foreach ($lines as $i => $l) {
    if (preg_match('/require|include/', $l)) echo ($i+1).": $l";
}

echo "\n=== All l.name and bad column references ===\n";
foreach ($lines as $i => $l) {
    if (preg_match('/l\.name|recorded_at|FROM campaigns[^_]|helpers\.php|auth\.php/', $l))
        echo ($i+1).": $l";
}

echo "\n=== Simulating dashboard boot ===\n";
// Fake the session/login so requireGrowthPartner works
require_once __DIR__ . '/../includes/config.php';
$pdo = db();

// Get a real partner user_id
$row = $pdo->query("SELECT pp.*, pp.user_id FROM partner_profiles pp WHERE pp.status='approved' LIMIT 1")->fetch();
if (!$row) { echo "No approved partner!\n"; exit; }

$_SESSION['user_id'] = $row['user_id'];
echo "Set session user_id={$row['user_id']}\n";

// Now try to load the dashboard file and catch errors
ob_start();
try {
    // Manually do what dashboard.php does at the top
    require_once __DIR__ . '/../includes/partner-helpers.php';
    echo "✅ partner-helpers loaded\n";

    $partner = requireGrowthPartner();
    echo "✅ requireGrowthPartner() ok, partner id={$partner['id']}\n";

    $user = currentUser();
    echo "✅ currentUser() ok, user id={$user['id']}\n";

} catch (Throwable $e) {
    ob_end_clean();
    echo "❌ " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . " line " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit;
}
ob_end_clean();

echo "\n✅ All boot checks passed — error must be later in dashboard.php\n";
echo "Run full dashboard include test next.\n";
echo "</pre>";
