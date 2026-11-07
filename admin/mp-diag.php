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

echo "\n--- mp_service_categories columns ---\n";
try {
    foreach ($pdo->query("SHOW COLUMNS FROM mp_service_categories")->fetchAll() as $c) {
        echo $c['Field'] . ' ' . $c['Type'] . "\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n--- marketplace_orders columns ---\n";
try {
    foreach ($pdo->query("SHOW COLUMNS FROM marketplace_orders")->fetchAll() as $c) {
        echo $c['Field'] . ' ' . $c['Type'] . "\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n--- site_settings mp_ keys ---\n";
try {
    foreach ($pdo->query("SELECT `key`, value FROM site_settings WHERE `key` LIKE 'mp_%'")->fetchAll() as $r) {
        echo $r['key'] . ' = ' . $r['value'] . "\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
