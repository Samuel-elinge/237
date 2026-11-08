<?php
/**
 * TEMPORARY DEBUG — delete after diagnosis
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)($_SESSION['user_id'] ?? 0);
$pdo            = db();

$listingId = (int)($_GET['id'] ?? 0);
echo "<pre>partnerId=$partnerId userId=$userId listingId=$listingId\n";

echo "partnerCanAccessListing: ";
var_dump(partnerCanAccessListing($partnerId, $listingId));

if ($listingId) {
    echo "\ncalcHealthScore test:\n";
    $listingSt = $pdo->prepare("SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon, loc.name_en AS city FROM listings l JOIN categories c ON c.id=l.category_id JOIN locations loc ON loc.id=l.location_id WHERE l.id=?");
    $listingSt->execute([$listingId]);
    $listing = $listingSt->fetch();
    if ($listing) {
        echo "listing found: " . $listing['title'] . "\n";
        $h = calcHealthScore($listing);
        echo "health: " . $h['score'] . "\n";
        echo "profileCompletion: " . profileCompletion($listing) . "\n";

        echo "\ngetRecommendedActionsP2: ";
        $a = getRecommendedActionsP2($listing, $partnerId);
        echo count($a) . " actions\n";

        echo "\ngetCampaignStats: ";
        try { $cs = getCampaignStats($listingId, $partnerId); echo "OK\n"; }
        catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
    } else {
        echo "listing not found!\n";
    }
}
echo "</pre>";
