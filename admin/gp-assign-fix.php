<?php
/**
 * One-time: show listings owned by user_id=19 (Sam) and user_id=2 (Tahirih),
 * show existing assignments, and create missing ones.
 * DELETE after use.
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();

// Get Sam's listings (user_id=19, pp_id=5) and Tahirih's (user_id=2)
$listings = $pdo->query("
    SELECT l.id, l.business_name, l.user_id, u.name AS owner_name
    FROM listings l
    JOIN users u ON u.id = l.user_id
    WHERE l.user_id IN (2, 19)
    ORDER BY l.user_id, l.id
")->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Listings for Sam (uid=19) and Tahirih (uid=2)</h2><pre>";
print_r($listings);
echo "</pre>";

// Existing assignments for pp_id=5 (Sam)
$asgSam = $pdo->query("SELECT * FROM partner_business_assignments WHERE partner_id=5")->fetchAll(PDO::FETCH_ASSOC);
echo "<h2>Existing assignments for Sam (pp_id=5)</h2><pre>";
print_r($asgSam);
echo "</pre>";

// Tahirih's partner_profile
$tahProf = $pdo->query("SELECT * FROM partner_profiles WHERE user_id=2")->fetchAll(PDO::FETCH_ASSOC);
echo "<h2>Tahirih partner_profile</h2><pre>";
print_r($tahProf);
echo "</pre>";

// Also show Tahirih's assignments if she has a profile
if ($tahProf) {
    $ppId = $tahProf[0]['id'];
    $asgTah = $pdo->query("SELECT * FROM partner_business_assignments WHERE partner_id=$ppId")->fetchAll(PDO::FETCH_ASSOC);
    echo "<h2>Existing assignments for Tahirih (pp_id=$ppId)</h2><pre>";
    print_r($asgTah);
    echo "</pre>";
}
