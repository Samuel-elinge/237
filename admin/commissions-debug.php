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

// Test the actual failing query
echo "\nTesting summary query: ";
try {
    $r = db()->query("SELECT COUNT(*) FROM partner_commissions WHERE order_type IN ('subscription','promotion') AND reversed_at IS NULL");
    echo "OK, count=" . $r->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "FAILS — " . $e->getMessage() . "\n";
}
