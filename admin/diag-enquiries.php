<?php
/**
 * Temporary diagnostic — check listing_enquiries table for listing 121
 * DELETE AFTER USE
 */
require_once __DIR__ . '/../includes/config.php';
if (!isAdmin()) { http_response_code(403); die('Admin only'); }

header('Content-Type: text/plain; charset=utf-8');

// 1. Check the table columns
echo "=== COLUMNS in listing_enquiries ===\n";
$cols = db()->query("SHOW COLUMNS FROM listing_enquiries")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . ' (' . $c['Type'] . ')' . "\n";
}

// 2. Check rows for listing 121
echo "\n=== ROWS for listing_id=121 ===\n";
try {
    $rows = db()->query("SELECT id, listing_id, name, email, crm_status, status, created_at FROM listing_enquiries WHERE listing_id=121 ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "NO ROWS FOUND for listing_id=121\n";
        // Show all listing_ids in the table
        echo "\n=== ALL listing_ids in listing_enquiries ===\n";
        $all = db()->query("SELECT DISTINCT listing_id, COUNT(*) as cnt FROM listing_enquiries GROUP BY listing_id ORDER BY listing_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all as $r) {
            echo "listing_id={$r['listing_id']}: {$r['cnt']} rows\n";
        }
    } else {
        foreach ($rows as $r) {
            echo json_encode($r) . "\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 3. Check listing 121
echo "\n=== LISTING 121 ===\n";
$l = db()->query("SELECT id, user_id, title, slug, status FROM listings WHERE id=121")->fetch(PDO::FETCH_ASSOC);
echo json_encode($l) . "\n";

// 4. Check current user
echo "\n=== CURRENT USER ===\n";
$u = currentUser();
echo "id={$u['id']}, email={$u['email']}, is_admin=" . (isAdmin()?'yes':'no') . "\n";

// 5. Test what my-enquiries.php would query for this admin
echo "\n=== ADMIN LISTING QUERY ===\n";
$myListings = db()->query("SELECT id,title,slug FROM listings WHERE status='approved' ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
echo count($myListings) . " approved listings found\n";
foreach (array_slice($myListings,0,5) as $ml) {
    echo "  id={$ml['id']}: {$ml['title']}\n";
}
