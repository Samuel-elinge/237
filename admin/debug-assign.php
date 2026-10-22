<?php
/**
 * Temporary diagnostic — DELETE after use
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireLogin();
if (!isAdmin()) { exit('Forbidden'); }

header('Content-Type: text/plain; charset=utf-8');

echo "=== POST handler diagnostics ===\n\n";

// 1. Check business_subscriptions columns
echo "--- business_subscriptions columns ---\n";
try {
    $r = db()->query("DESCRIBE business_subscriptions");
    $cols = [];
    foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[] = $row['Field'];
        echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    $needed = ['cancelled_at', 'cancellation_reason', 'admin_note', 'starts_at', 'ends_at', 'listing_id', 'user_id', 'plan_id'];
    echo "\nMissing columns: ";
    $missing = array_diff($needed, $cols);
    echo $missing ? implode(', ', $missing) : 'none' ;
    echo "\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

// 2. Simulate the cancel UPDATE (safe — no real listing_id=0 row)
echo "\n--- simulate cancel UPDATE (listing_id=0, no rows will match) ---\n";
try {
    $stmt = db()->prepare("
        UPDATE business_subscriptions
        SET status = 'cancelled', cancelled_at = NOW(),
            cancellation_reason = ?
        WHERE listing_id = ? AND status IN ('active','pending_payment','past_due')
    ");
    $stmt->execute(['Test cancel', 0]);
    echo "OK — rows affected: " . $stmt->rowCount() . "\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

// 3. Simulate the assign UPDATE (cancel existing)
echo "\n--- simulate assign cancel-existing UPDATE (listing_id=0) ---\n";
try {
    $stmt = db()->prepare("
        UPDATE business_subscriptions
        SET status = 'cancelled', cancelled_at = NOW(),
            cancellation_reason = 'Superseded by admin manual assignment'
        WHERE listing_id = ? AND status IN ('active','pending_payment','past_due')
    ");
    $stmt->execute([0]);
    echo "OK — rows affected: " . $stmt->rowCount() . "\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

// 4. Simulate the INSERT (listing_id=0 — will it fail on FK?)
echo "\n--- simulate INSERT (listing_id=0, expect FK error or success) ---\n";
try {
    $ins = db()->prepare("
        INSERT INTO business_subscriptions
            (listing_id, user_id, plan_id, status, starts_at, ends_at, admin_note, created_at, updated_at)
        VALUES (?, ?, ?, 'active', ?, ?, ?, NOW(), NOW())
    ");
    // Don't execute — just check prepare succeeds
    echo "prepare OK — column names accepted\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

// 5. Check subscription_plans has is_free column
echo "\n--- subscription_plans columns ---\n";
try {
    $r = db()->query("DESCRIBE subscription_plans");
    $cols = [];
    foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[] = $row['Field'];
        echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    echo "has is_free: " . (in_array('is_free', $cols) ? 'YES' : 'NO') . "\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

// 6. Check PHP error log last 20 lines (may not be accessible)
echo "\n--- PHP error log (last 20 lines if accessible) ---\n";
$logPaths = [
    ini_get('error_log'),
    '/var/log/php_errors.log',
    __DIR__ . '/../../logs/error_log',
    __DIR__ . '/../../error_log',
];
foreach ($logPaths as $lp) {
    if ($lp && file_exists($lp) && is_readable($lp)) {
        echo "Found: $lp\n";
        $lines = file($lp);
        echo implode('', array_slice($lines, -20));
        break;
    }
}
echo "(no readable error log found)\n";

echo "\nDone.\n";
