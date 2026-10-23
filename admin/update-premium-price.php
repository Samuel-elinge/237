<?php
/**
 * One-shot: update Business Premium to 80,000 XAF / 12 months / 365 days
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();
if (!isAdmin()) { exit('Forbidden'); }

header('Content-Type: text/plain; charset=utf-8');

// Find the Business Premium plan
$plan = db()->query("SELECT id, name, price, billing_cycle, duration_days FROM subscription_plans WHERE name LIKE '%Premium%' OR name LIKE '%Business%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

echo "=== Current plans ===\n";
foreach ($plan as $p) {
    echo "  id={$p['id']} name={$p['name']} price={$p['price']} cycle={$p['billing_cycle']} days={$p['duration_days']}\n";
}
echo "\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    // Update Business Premium (id from above)
    $planId = (int)($_POST['plan_id'] ?? 0);
    if (!$planId) { exit("No plan_id posted\n"); }

    $stmt = db()->prepare("UPDATE subscription_plans SET price = 80000, billing_cycle = 'annual', duration_days = 365 WHERE id = ?");
    $stmt->execute([$planId]);
    echo "Updated plan id={$planId}: price=80000 billing_cycle=annual duration_days=365\n";
    echo "Rows affected: " . $stmt->rowCount() . "\n";
} else {
    echo "POST with confirm=yes&plan_id=<id> to apply the update.\n";
}
