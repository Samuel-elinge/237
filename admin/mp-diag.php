<?php
/**
 * Temporary diagnostic — reads PHP error log and tests catalogue include chain
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
header('Content-Type: text/plain; charset=utf-8');

// Check error log
$errLog = ini_get('error_log');
echo "Error log path: $errLog\n";
if ($errLog && file_exists($errLog)) {
    $lines = file($errLog);
    $recent = array_slice($lines, -30);
    echo "Last 30 lines of error log:\n";
    foreach ($recent as $l) {
        if (stripos($l, 'marketplace') !== false || stripos($l, 'mpFmt') !== false || stripos($l, 'getSet') !== false || stripos($l, 'fatal') !== false) {
            echo $l;
        }
    }
} else {
    echo "No error log found or not readable\n";
}

// Try to actually output the stats bar like the catalogue does
$pdo = db();
$cats = $pdo->query("SELECT c.*, COUNT(DISTINCT p.id) AS product_count, SUM(p.orderable) AS orderable_count FROM mp_service_categories c LEFT JOIN mp_service_products p ON p.category_id = c.id GROUP BY c.id ORDER BY c.sort_order")->fetchAll();
$products = $pdo->query("SELECT p.*, c.name_en AS cat_name, c.`key` AS cat_key FROM mp_service_products p JOIN mp_service_categories c ON c.id = p.category_id ORDER BY c.sort_order, p.sort_order")->fetchAll();
$prices = $pdo->query("SELECT pr.*, p.name_en AS product_name, p.`key` AS product_key FROM mp_service_prices pr JOIN mp_service_products p ON p.id = pr.product_id ORDER BY p.sort_order, pr.price_type DESC, pr.id")->fetchAll();
$pricesByProduct = [];
foreach ($prices as $pr) $pricesByProduct[$pr['product_id']][] = $pr;
$totalProducts  = count($products);
$orderableCount = count(array_filter($products, fn($p) => $p['orderable']));
$orderCount     = (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders")->fetchColumn();
$pendingPayCount= (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders WHERE order_state='PAYMENT_REVIEW'")->fetchColumn();

echo "\ntotalProducts=$totalProducts orderableCount=$orderableCount orderCount=$orderCount pendingPayCount=$pendingPayCount\n";
echo "cats count=" . count($cats) . "\n";
echo "prices count=" . count($prices) . "\n";

// Check if there's a 'confirmed_by_user_id' column issue
echo "\nmarketplace_orders columns:\n";
foreach ($pdo->query("SHOW COLUMNS FROM marketplace_orders")->fetchAll() as $c) {
    echo "  " . $c['Field'] . "\n";
}

echo "\nmp_payment_attempts columns:\n";
foreach ($pdo->query("SHOW COLUMNS FROM mp_payment_attempts")->fetchAll() as $c) {
    echo "  " . $c['Field'] . "\n";
}

// Test if header.php causes any issues
echo "\nTesting header include...\n";
flush();
ob_start();
try {
    require_once __DIR__ . '/../includes/header.php';
    $h = ob_get_clean();
    echo "header.php OK — " . strlen($h) . " bytes\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "header.php FAILED: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}

// Test footer
echo "\nTesting footer include...\n";
ob_start();
try {
    require_once __DIR__ . '/../includes/footer.php';
    $f = ob_get_clean();
    echo "footer.php OK — " . strlen($f) . " bytes\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "footer.php FAILED: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
