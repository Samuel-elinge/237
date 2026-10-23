<?php
/**
 * One-shot: update Premium (id=3) to 80,000 XAF / annual / 365 days
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();
if (!isAdmin()) { exit('Forbidden'); }

header('Content-Type: text/plain; charset=utf-8');

try {
    $stmt = db()->prepare("
        UPDATE subscription_plans
        SET price_xaf = 80000,
            billing_interval = 'annual',
            duration_days = 365,
            updated_at = NOW()
        WHERE id = 3
    ");
    $stmt->execute();
    echo "Done. Rows affected: " . $stmt->rowCount() . "\n";

    $row = db()->query("SELECT id, name_en, price_xaf, billing_interval, duration_days FROM subscription_plans WHERE id = 3")->fetch(PDO::FETCH_ASSOC);
    echo "Verified: " . json_encode($row) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
