<?php
/**
 * One-shot: update Business Premium to 80,000 XAF
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();
if (!isAdmin()) { exit('Forbidden'); }

// Output buffering so header() works even if config.php sent output
ob_start();

header('Content-Type: text/plain; charset=utf-8');
ob_end_flush();

// 1. Show subscription_plans columns
echo "=== subscription_plans columns ===\n";
try {
    $cols = db()->query("DESCRIBE subscription_plans")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} ({$c['Type']})\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 2. Show all plans
echo "\n=== All plans ===\n";
try {
    $plans = db()->query("SELECT * FROM subscription_plans ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($plans as $p) {
        echo "  " . json_encode($p) . "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
