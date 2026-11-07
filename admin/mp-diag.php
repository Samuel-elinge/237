<?php
/**
 * Temporary diagnostic script — DELETE after use
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$tables = [
    'mp_service_categories',
    'mp_service_products',
    'mp_service_prices',
    'marketplace_orders',
    'marketplace_order_items',
    'mp_order_approvals',
    'mp_payment_attempts',
    'mp_fulfilment_tasks',
    'mp_audit_events',
    'mp_notification_log',
    'site_settings',
];

foreach ($tables as $t) {
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "✅ $t — $n rows\n";
    } catch (Exception $e) {
        echo "❌ $t — " . $e->getMessage() . "\n";
    }
}

echo "\n--- Test catalogue queries ---\n";

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
    echo "cats query OK — " . count($cats) . " rows\n";
    foreach ($cats as $c) echo "  cat: {$c['id']} {$c['name_en']}\n";
} catch (Exception $e) { echo "cats FAILED: " . $e->getMessage() . "\n"; }

try {
    $products = $pdo->query("
        SELECT p.*, c.name_en AS cat_name, c.`key` AS cat_key
        FROM mp_service_products p
        JOIN mp_service_categories c ON c.id = p.category_id
        ORDER BY c.sort_order, p.sort_order
    ")->fetchAll();
    echo "products query OK — " . count($products) . " rows\n";
} catch (Exception $e) { echo "products FAILED: " . $e->getMessage() . "\n"; }

try {
    $prices = $pdo->query("
        SELECT pr.*, p.name_en AS product_name, p.`key` AS product_key
        FROM mp_service_prices pr
        JOIN mp_service_products p ON p.id = pr.product_id
        ORDER BY p.sort_order, pr.price_type DESC, pr.id
    ")->fetchAll();
    echo "prices query OK — " . count($prices) . " rows\n";
} catch (Exception $e) { echo "prices FAILED: " . $e->getMessage() . "\n"; }

try {
    $orderCount = (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders")->fetchColumn();
    echo "orderCount OK — $orderCount\n";
} catch (Exception $e) { echo "orderCount FAILED: " . $e->getMessage() . "\n"; }

try {
    $pendingPayCount = (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders WHERE order_state='PAYMENT_REVIEW'")->fetchColumn();
    echo "pendingPayCount OK — $pendingPayCount\n";
} catch (Exception $e) { echo "pendingPayCount FAILED: " . $e->getMessage() . "\n"; }

echo "\n--- marketplace_orders columns ---\n";
try {
    foreach ($pdo->query("SHOW COLUMNS FROM marketplace_orders")->fetchAll() as $c) {
        echo $c['Field'] . "\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n--- mp_payment_attempts columns ---\n";
try {
    foreach ($pdo->query("SHOW COLUMNS FROM mp_payment_attempts")->fetchAll() as $c) {
        echo $c['Field'] . "\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n--- site_settings mp_ keys ---\n";
try {
    foreach ($pdo->query("SELECT `key`, value FROM site_settings WHERE `key` LIKE 'mp_%'")->fetchAll() as $r) {
        echo $r['key'] . ' = ' . $r['value'] . "\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
