<?php
/**
 * Temporary diagnostic script — DELETE after use
 * This version simulates the full catalogue page logic to find the exact crash point
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "=== SIMULATING marketplace-catalogue.php ===\n\n";

echo "Step 1: cats query\n";
try {
    $cats = $pdo->query("
        SELECT c.*,
               COUNT(DISTINCT p.id) AS product_count,
               SUM(p.orderable)     AS orderable_count
        FROM mp_service_categories c
        LEFT JOIN mp_service_products p ON p.category_id = c.id
        GROUP BY c.id
        ORDER BY c.sort_order
    ")->fetchAll();
    echo "OK — " . count($cats) . " cats\n";
} catch (Throwable $e) { echo "FAIL: " . $e->getMessage() . "\n"; exit; }

echo "Step 2: products query\n";
try {
    $products = $pdo->query("
        SELECT p.*, c.name_en AS cat_name, c.`key` AS cat_key
        FROM mp_service_products p
        JOIN mp_service_categories c ON c.id = p.category_id
        ORDER BY c.sort_order, p.sort_order
    ")->fetchAll();
    echo "OK — " . count($products) . " products\n";
} catch (Throwable $e) { echo "FAIL: " . $e->getMessage() . "\n"; exit; }

echo "Step 3: prices query\n";
try {
    $prices = $pdo->query("
        SELECT pr.*, p.name_en AS product_name, p.`key` AS product_key
        FROM mp_service_prices pr
        JOIN mp_service_products p ON p.id = pr.product_id
        ORDER BY p.sort_order, pr.price_type DESC, pr.id
    ")->fetchAll();
    echo "OK — " . count($prices) . " prices\n";
} catch (Throwable $e) { echo "FAIL: " . $e->getMessage() . "\n"; exit; }

echo "Step 4: pricesByProduct grouping\n";
$pricesByProduct = [];
foreach ($prices as $pr) {
    $pricesByProduct[$pr['product_id']][] = $pr;
}
echo "OK — " . count($pricesByProduct) . " product groups\n";

echo "Step 5: settings query\n";
try {
    $settings = [];
    $stS = $pdo->query("SELECT `key`, value FROM site_settings WHERE `key` LIKE 'mp_%'");
    foreach ($stS->fetchAll() as $row) $settings[$row['key']] = $row['value'];
    echo "OK — " . count($settings) . " settings\n";
} catch (Throwable $e) { echo "FAIL: " . $e->getMessage() . "\n"; exit; }

echo "Step 6: stats\n";
$totalProducts  = count($products);
$orderableCount = count(array_filter($products, fn($p) => $p['orderable']));
$activeCount    = count(array_filter($products, fn($p) => $p['active']));
echo "totalProducts=$totalProducts orderableCount=$orderableCount activeCount=$activeCount\n";

echo "Step 7: order count\n";
try {
    $orderCount = (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders")->fetchColumn();
    echo "OK — $orderCount\n";
} catch (Throwable $e) { echo "FAIL: " . $e->getMessage() . "\n"; exit; }

echo "Step 8: pending pay count\n";
try {
    $pendingPayCount = (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders WHERE order_state='PAYMENT_REVIEW'")->fetchColumn();
    echo "OK — $pendingPayCount\n";
} catch (Throwable $e) { echo "FAIL: " . $e->getMessage() . "\n"; exit; }

echo "Step 9: mpFmt function\n";
function mpFmt(int $xaf): string { return number_format($xaf) . ' FCFA'; }
echo "OK\n";

echo "Step 10: getSettingCached calls\n";
try {
    $v1 = getSettingCached('mp_approval_expiry_days', '7');
    $v2 = getSettingCached('mp_commission_enabled', '0');
    $v3 = getSettingCached('mp_auto_renewal_enabled', '0');
    $v4 = getSettingCached('mp_fulfilment_sla_days', '5');
    echo "OK — expiry=$v1 commission=$v2 renewal=$v3 sla=$v4\n";
} catch (Throwable $e) { echo "FAIL: " . $e->getMessage() . "\n"; exit; }

echo "\n=== ALL STEPS PASSED — checking header include ===\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Error log: " . ini_get('error_log') . "\n";

// Check if header include itself errors
echo "\nAbout to include header.php...\n";
flush();
ob_start();
try {
    require_once __DIR__ . '/../includes/header.php';
    $headerOut = ob_get_clean();
    echo "header.php included OK — " . strlen($headerOut) . " bytes output\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "header.php FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
