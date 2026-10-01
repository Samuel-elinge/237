<?php
// test-listing.php — TEMPORARY DELETE AFTER USE
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Step 1: Starting<br>";

require_once __DIR__ . '/includes/config.php';
echo "Step 2: Config loaded<br>";

$slug = 'maroon-hosting';
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
echo "Step 3: Listing fetched — " . ($l ? $l['title'] : 'NOT FOUND') . "<br>";

$cu = currentUser();
echo "Step 4: currentUser OK — " . ($cu ? 'logged in' : 'not logged in') . "<br>";

echo "Step 5: About to load header...<br>";
flush();

$pageTitle = 'Test';
$pageDesc  = 'Test';
require_once __DIR__ . '/includes/header.php';

echo "Step 6: Header loaded OK<br>";

echo "<hr><strong>All good — the issue is in listing.php template rendering.</strong><br>";
echo "Check that listing.php uploaded correctly — file size should be around 14KB.<br>";

require_once __DIR__ . '/includes/footer.php';
