<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();

$adminId = $_SESSION['user_id'];

// Sam pp_id=5 → listing 1 (Maroon Hosting)
// Tahirih pp_id=6 → listings 2 (The Tahirih Effect) + 105 (LFWT Perfumery)
$inserts = [
    [5, 1],
    [6, 2],
    [6, 105],
];

foreach ($inserts as [$ppId, $lid]) {
    $ex = $pdo->prepare("SELECT id FROM partner_business_assignments WHERE partner_id=? AND listing_id=?");
    $ex->execute([$ppId, $lid]);
    if ($ex->fetch()) {
        // already exists — ensure active
        $pdo->prepare("UPDATE partner_business_assignments SET status='active', removed_at=NULL WHERE partner_id=? AND listing_id=?")
            ->execute([$ppId, $lid]);
        echo "Updated pp=$ppId lid=$lid to active\n";
    } else {
        $pdo->prepare("INSERT INTO partner_business_assignments (partner_id, listing_id, role, assigned_by) VALUES (?,?,'primary',?)")
            ->execute([$ppId, $lid, $adminId]);
        echo "Inserted pp=$ppId lid=$lid\n";
    }
}

// Refresh cached count
foreach ([5, 6] as $ppId) {
    $pdo->prepare("UPDATE partner_profiles SET businesses_managed_count=(SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=? AND status='active') WHERE id=?")
        ->execute([$ppId, $ppId]);
}
echo "Done.";
