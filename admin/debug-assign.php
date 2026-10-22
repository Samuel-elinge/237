<?php
/**
 * Temporary diagnostic — DELETE after use
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();
if (!isAdmin()) { exit('Forbidden'); }

header('Content-Type: text/plain; charset=utf-8');

echo "=== assign-subscription diagnostics ===\n\n";

// 1. Test plans query
echo "--- subscription_plans ---\n";
try {
    $r = db()->query("SELECT id, name_en, active, sort_order, highlight_color FROM subscription_plans LIMIT 10");
    if (!$r) {
        echo "QUERY FAILED: " . print_r(db()->errorInfo(), true) . "\n";
    } else {
        $rows = $r->fetchAll(PDO::FETCH_ASSOC);
        echo "Rows: " . count($rows) . "\n";
        foreach ($rows as $row) echo "  " . json_encode($row) . "\n";
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\n--- listings (first 5) ---\n";
try {
    $r = db()->query("SELECT id, title, status, user_id FROM listings ORDER BY id ASC LIMIT 5");
    if (!$r) {
        echo "QUERY FAILED: " . print_r(db()->errorInfo(), true) . "\n";
    } else {
        $rows = $r->fetchAll(PDO::FETCH_ASSOC);
        echo "Rows: " . count($rows) . "\n";
        foreach ($rows as $row) echo "  " . json_encode($row) . "\n";
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\n--- users join test ---\n";
try {
    $r = db()->query("
        SELECT l.id, l.title, u.email
        FROM listings l
        LEFT JOIN users u ON u.id = l.user_id
        LIMIT 3
    ");
    if (!$r) {
        echo "QUERY FAILED: " . print_r(db()->errorInfo(), true) . "\n";
    } else {
        $rows = $r->fetchAll(PDO::FETCH_ASSOC);
        echo "Rows: " . count($rows) . "\n";
        foreach ($rows as $row) echo "  " . json_encode($row) . "\n";
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\n--- business_subscriptions columns ---\n";
try {
    $r = db()->query("DESCRIBE business_subscriptions");
    if (!$r) {
        echo "QUERY FAILED: " . print_r(db()->errorInfo(), true) . "\n";
    } else {
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\n--- full listings query ---\n";
try {
    $r = db()->query("
        SELECT l.id, l.title AS business_name, l.status AS listing_status,
               u.email AS owner_email,
               bs.id   AS sub_id,
               bs.status AS sub_status,
               bs.ends_at AS sub_ends,
               sp.name_en AS plan_name,
               sp.slug    AS plan_slug,
               sp.highlight_color AS plan_color
        FROM listings l
        LEFT JOIN users u ON u.id = l.user_id
        LEFT JOIN business_subscriptions bs
              ON bs.listing_id = l.id
             AND bs.status = 'active'
             AND (bs.ends_at IS NULL OR bs.ends_at > NOW())
        LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
        ORDER BY l.id ASC
        LIMIT 5
    ");
    if (!$r) {
        echo "QUERY FAILED: " . print_r(db()->errorInfo(), true) . "\n";
    } else {
        $rows = $r->fetchAll(PDO::FETCH_ASSOC);
        echo "Rows: " . count($rows) . "\n";
        foreach ($rows as $row) echo "  " . json_encode($row) . "\n";
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
