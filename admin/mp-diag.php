<?php
/**
 * Temporary diagnostic — tests marketplace-fulfilment queries
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/flash-helpers.php';
requireAdmin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "=== Fulfilment diagnostic ===\n\n";

// 1. Check if mp_fulfilment_tasks exists
echo "1. mp_fulfilment_tasks table:\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM mp_fulfilment_tasks")->fetchAll();
    foreach ($cols as $c) echo "   " . $c['Field'] . " " . $c['Type'] . "\n";
} catch (Throwable $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// 2. Test staff query
echo "\n2. Staff query:\n";
try {
    $staff = $pdo->query("SELECT id, name FROM users WHERE role IN ('admin','staff','operator') ORDER BY name")->fetchAll();
    echo "   OK — " . count($staff) . " staff members\n";
} catch (Throwable $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// 3. Test main fulfilment orders query
echo "\n3. Fulfilment orders query:\n";
try {
    $st = $pdo->prepare("
        SELECT o.id, o.ref, o.order_state, o.due_now_amount,
               o.created_at, o.completed_at AS payment_confirmed_at,
               l.title AS listing_title,
               u_partner.name AS partner_name,
               COUNT(ft.id) AS task_total,
               SUM(ft.fulfilment_state='COMPLETED') AS task_done,
               SUM(ft.fulfilment_state='BLOCKED') AS task_blocked,
               MIN(ft.due_date) AS earliest_due
        FROM marketplace_orders o
        JOIN listings l ON l.id = o.listing_id
        JOIN partner_profiles pp ON pp.id = o.partner_profile_id
        JOIN users u_partner ON u_partner.id = pp.user_id
        LEFT JOIN mp_fulfilment_tasks ft ON ft.order_id = o.id AND ft.fulfilment_state NOT IN ('CANCELLED')
        WHERE o.order_state IN ('READY_FOR_FULFILMENT','IN_FULFILMENT')
        GROUP BY o.id
        ORDER BY earliest_due ASC, o.payment_confirmed_at ASC
    ");
    $st->execute();
    $rows = $st->fetchAll();
    echo "   OK — " . count($rows) . " orders\n";
} catch (Throwable $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// 4. Check error log for recent fulfilment errors
echo "\n4. Recent PHP errors mentioning fulfilment:\n";
$errLog = ini_get('error_log');
if ($errLog && file_exists($errLog)) {
    $lines = array_slice(file($errLog), -50);
    $found = 0;
    foreach ($lines as $l) {
        if (stripos($l, 'fulfilment') !== false || stripos($l, 'mp_fulfilment') !== false) {
            echo "   " . $l;
            $found++;
        }
    }
    if (!$found) {
        // show last 10 lines regardless
        echo "   (no fulfilment-specific lines; last 5 error log entries:)\n";
        foreach (array_slice($lines, -5) as $l) echo "   $l";
    }
} else {
    echo "   No error log readable at: $errLog\n";
}

echo "\nDone.\n";
