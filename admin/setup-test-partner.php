<?php
/**
 * TEMPORARY: Create test growth partner — DELETE AFTER USE
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pdo = db();
$userId = 49; // Test Partner user

// Check if partner_profile already exists
$existing = $pdo->prepare("SELECT id FROM partner_profiles WHERE user_id=?");
$existing->execute([$userId]);
$pp = $existing->fetchColumn();

if (!$pp) {
    // Create partner profile
    $pdo->prepare("
        INSERT INTO partner_profiles (user_id, display_name, tagline, region, status, created_at)
        VALUES (?, 'Test Partner', 'Test account for admin impersonation', 'Centre', 'approved', NOW())
    ")->execute([$userId]);
    $pp = $pdo->lastInsertId();
    echo "✅ Created partner_profile ID: $pp<br>";
} else {
    echo "ℹ️ Partner profile already exists: $pp<br>";
}

// Update user role
$pdo->prepare("UPDATE users SET role='growth_partner' WHERE id=?")->execute([$userId]);
echo "✅ User $userId role set to growth_partner<br>";

echo "<br><strong>Done!</strong> <a href='/admin/manage-growth-partners.php'>Go to Manage Partners</a>";
echo "<br><br><em>⚠️ Delete this file: admin/setup-test-partner.php</em>";
