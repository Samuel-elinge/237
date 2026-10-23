<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
header('Content-Type: text/plain');

$tables = ['partner_commissions','partner_payout_batches','monetisation_orders'];
foreach ($tables as $t) {
    try {
        $r = db()->query("SELECT COUNT(*) FROM `$t`");
        echo "$t: EXISTS, rows=" . $r->fetchColumn() . "\n";
    } catch (Exception $e) {
        echo "$t: MISSING — " . $e->getMessage() . "\n";
    }
}

// Also check the quote() call that may cause the 500
echo "\nTesting db()->quote(): ";
try {
    $q = db()->quote('pending');
    echo "works, returns $q\n";
} catch (Exception $e) {
    echo "FAILS — " . $e->getMessage() . "\n";
}

// Test each query from commissions.php in order
echo "\nQuery 1 - summary stats: ";
try {
    $r = db()->query("SELECT SUM(CASE WHEN status='pending' THEN commission_amount_xaf ELSE 0 END) AS pending_xaf, COUNT(*) AS total FROM partner_commissions WHERE order_type IN ('subscription','promotion') AND reversed_at IS NULL");
    $row = $r->fetch();
    echo "OK — " . json_encode($row) . "\n";
} catch (Exception $e) { echo "FAILS — " . $e->getMessage() . "\n"; }

echo "\nQuery 2 - cfWhere with quote(): ";
try {
    $cfFilter = 'pending';
    $cfWhere = "pc.order_type IN ('subscription','promotion') AND pc.reversed_at IS NULL AND pc.status=" . db()->quote($cfFilter);
    $r = db()->query("SELECT COUNT(*) FROM partner_commissions pc WHERE $cfWhere");
    echo "OK, count=" . $r->fetchColumn() . "\n";
} catch (Exception $e) { echo "FAILS — " . $e->getMessage() . "\n"; }

echo "\nQuery 3 - commissions JOIN: ";
try {
    $cfWhere = "pc.order_type IN ('subscription','promotion') AND pc.reversed_at IS NULL AND pc.status=" . db()->quote('pending');
    $r = db()->query("SELECT pc.*, u.name AS partner_name, u.email AS partner_email, l.title AS listing_title, mo.ref AS order_ref FROM partner_commissions pc JOIN users u ON u.id = pc.user_id LEFT JOIN listings l ON l.id = pc.listing_id LEFT JOIN monetisation_orders mo ON mo.id = pc.order_id WHERE $cfWhere ORDER BY pc.created_at DESC LIMIT 30 OFFSET 0");
    echo "OK, rows=" . count($r->fetchAll()) . "\n";
} catch (Exception $e) { echo "FAILS — " . $e->getMessage() . "\n"; }

echo "\nQuery 4 - batches JOIN: ";
try {
    $r = db()->query("SELECT ppb.*, u.name AS partner_name, u.email AS partner_email, creator.name AS created_by_name FROM partner_payout_batches ppb JOIN users u ON u.id = ppb.partner_id LEFT JOIN users creator ON creator.id = ppb.created_by ORDER BY ppb.created_at DESC LIMIT 50");
    echo "OK, rows=" . count($r->fetchAll()) . "\n";
} catch (Exception $e) { echo "FAILS — " . $e->getMessage() . "\n"; }

echo "\nChecking partner_commissions columns: ";
try {
    $r = db()->query("DESCRIBE partner_commissions");
    $cols = array_column($r->fetchAll(), 'Field');
    echo implode(', ', $cols) . "\n";
} catch (Exception $e) { echo "FAILS — " . $e->getMessage() . "\n"; }

echo "\nChecking partner_payout_batches columns: ";
try {
    $r = db()->query("DESCRIBE partner_payout_batches");
    $cols = array_column($r->fetchAll(), 'Field');
    echo implode(', ', $cols) . "\n";
} catch (Exception $e) { echo "FAILS — " . $e->getMessage() . "\n"; }

echo "\nChecking monetisation_orders columns: ";
try {
    $r = db()->query("DESCRIBE monetisation_orders");
    $cols = array_column($r->fetchAll(), 'Field');
    echo implode(', ', $cols) . "\n";
} catch (Exception $e) { echo "FAILS — " . $e->getMessage() . "\n"; }

echo "\nChecking admin-header.php include: ";
echo file_exists(__DIR__ . '/../includes/admin-header.php') ? "EXISTS\n" : "MISSING\n";
echo "Checking admin-footer.php include: ";
echo file_exists(__DIR__ . '/../includes/admin-footer.php') ? "EXISTS\n" : "MISSING\n";
