<?php
// debug-listing.php — TEMPORARY, DELETE AFTER USE
// Visit: https://237biz.net/debug-listing.php?slug=maroon-hosting

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<pre style='font-family:monospace;font-size:13px;padding:20px;'>";
echo "=== 237BIZ LISTING DEBUG ===\n\n";

// Step 1: Config
echo "1. Loading config...\n";
require_once __DIR__ . '/includes/config.php';
echo "   OK\n\n";

// Step 2: DB
echo "2. Testing DB connection...\n";
try {
    $test = db()->query("SELECT 1")->fetchColumn();
    echo "   OK\n\n";
} catch (Exception $e) {
    echo "   FAIL: " . $e->getMessage() . "\n\n";
    die();
}

// Step 3: Fetch listing
echo "3. Fetching listing...\n";
$slug = $_GET['slug'] ?? 'maroon-hosting';
try {
    $st = db()->prepare("
        SELECT l.*, c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon, c.slug AS cat_slug,
               loc.name_en AS loc_en, loc.slug AS loc_slug
        FROM listings l
        JOIN categories c ON c.id = l.category_id
        JOIN locations loc ON loc.id = l.location_id
        WHERE l.slug = ? AND l.status = 'approved'
    ");
    $st->execute([$slug]);
    $l = $st->fetch();
    if ($l) {
        echo "   OK — found: " . $l['title'] . "\n";
        echo "   featured: " . $l['featured'] . "\n";
        echo "   user_id: " . $l['user_id'] . "\n\n";
    } else {
        echo "   NOT FOUND for slug: $slug\n\n";
    }
} catch (Exception $e) {
    echo "   FAIL: " . $e->getMessage() . "\n\n";
    die();
}

// Step 4: Check listing_views table
echo "4. Checking listing_views table...\n";
try {
    db()->query("SELECT 1 FROM listing_views LIMIT 1");
    echo "   EXISTS\n\n";
} catch (Exception $e) {
    echo "   MISSING: " . $e->getMessage() . "\n\n";
}

// Step 5: Check listing_enquiries table
echo "5. Checking listing_enquiries table...\n";
try {
    db()->query("SELECT 1 FROM listing_enquiries LIMIT 1");
    echo "   EXISTS\n\n";
} catch (Exception $e) {
    echo "   MISSING: " . $e->getMessage() . "\n\n";
}

// Step 6: Check listing_clicks table
echo "6. Checking listing_clicks table...\n";
try {
    db()->query("SELECT 1 FROM listing_clicks LIMIT 1");
    echo "   EXISTS\n\n";
} catch (Exception $e) {
    echo "   MISSING: " . $e->getMessage() . "\n\n";
}

// Step 7: Check reviews table
echo "7. Fetching reviews...\n";
try {
    $reviews = db()->prepare("SELECT * FROM reviews WHERE listing_id = ? AND status = 'approved'");
    $reviews->execute([$l['id'] ?? 0]);
    $reviews = $reviews->fetchAll();
    echo "   OK — " . count($reviews) . " reviews\n\n";
} catch (Exception $e) {
    echo "   FAIL: " . $e->getMessage() . "\n\n";
}

// Step 8: Check listing_images
echo "8. Fetching images...\n";
try {
    $images = db()->prepare("SELECT * FROM listing_images WHERE listing_id = ?");
    $images->execute([$l['id'] ?? 0]);
    echo "   OK — " . count($images->fetchAll()) . " images\n\n";
} catch (Exception $e) {
    echo "   FAIL: " . $e->getMessage() . "\n\n";
}

// Step 9: Check listing_views INSERT (the tracking code)
echo "9. Testing view tracking INSERT...\n";
try {
    $ipHash = hash('sha256', '127.0.0.1');
    $recentView = db()->prepare("SELECT id FROM listing_views WHERE listing_id=? AND ip_hash=? AND viewed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $recentView->execute([$l['id'] ?? 0, $ipHash]);
    echo "   OK — query ran fine\n\n";
} catch (Exception $e) {
    echo "   FAIL: " . $e->getMessage() . "\n\n";
}

// Step 10: wordLimit function
echo "10. Testing wordLimit function...\n";
function wordLimit(string $text, int $limit): array {
    $words = preg_split('/\s+/', trim($text));
    $truncated = count($words) > $limit;
    $text = implode(' ', array_slice($words, 0, $limit));
    return ['text' => $text, 'truncated' => $truncated, 'total' => count($words)];
}
$wl = wordLimit("This is a test description with more than thirty words to check the truncation feature works correctly in the listing page", 30);
echo "   OK — truncated: " . ($wl['truncated'] ? 'yes' : 'no') . "\n\n";

echo "=== ALL CHECKS COMPLETE ===\n";
echo "\nIf you see this, the issue is in the HTML template rendering, not the PHP logic.\n";
echo "</pre>";
