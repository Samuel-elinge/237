<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();

// Sam uid=19 pp_id=5, Tahirih uid=2
$listings = $pdo->query("
    SELECT l.id, l.business_name, l.organiser_id, u.name AS owner_name
    FROM listings l
    JOIN users u ON u.id = l.organiser_id
    WHERE l.organiser_id IN (2, 19)
    ORDER BY l.organiser_id, l.id
")->fetchAll(PDO::FETCH_ASSOC);
echo "<h2>Listings</h2><pre>"; print_r($listings); echo "</pre>";

$asgSam = $pdo->query("SELECT * FROM partner_business_assignments WHERE partner_id=5")->fetchAll(PDO::FETCH_ASSOC);
echo "<h2>Sam assignments (pp_id=5)</h2><pre>"; print_r($asgSam); echo "</pre>";

$tahProf = $pdo->query("SELECT * FROM partner_profiles WHERE user_id=2")->fetchAll(PDO::FETCH_ASSOC);
echo "<h2>Tahirih profile</h2><pre>"; print_r($tahProf); echo "</pre>";

if ($tahProf) {
    $ppId = $tahProf[0]['id'];
    $asgTah = $pdo->query("SELECT * FROM partner_business_assignments WHERE partner_id=$ppId")->fetchAll(PDO::FETCH_ASSOC);
    echo "<h2>Tahirih assignments (pp_id=$ppId)</h2><pre>"; print_r($asgTah); echo "</pre>";
}
