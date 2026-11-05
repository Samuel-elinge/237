<?php
/**
 * ONE-TIME: Approve gp.test@237biz.net as a Growth Partner
 * DELETE AFTER USE
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pdo = db();

// Find the user
$st = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = 'gp.test@237biz.net' LIMIT 1");
$st->execute();
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('❌ User gp.test@237biz.net not found.');
}

echo "✅ Found user: #{$user['id']} — {$user['name']} ({$user['email']}) / role={$user['role']}<br>";

// Ensure role is growth_partner
if ($user['role'] !== 'growth_partner') {
    $pdo->prepare("UPDATE users SET role='growth_partner' WHERE id=?")->execute([$user['id']]);
    echo "✅ Role updated to growth_partner<br>";
}

// Check partner_profiles
$st2 = $pdo->prepare("SELECT id, status FROM partner_profiles WHERE user_id=?");
$st2->execute([$user['id']]);
$pp = $st2->fetch(PDO::FETCH_ASSOC);

if (!$pp) {
    // Insert approved row
    $pdo->prepare("
        INSERT INTO partner_profiles (user_id, display_name, tagline, region, status, created_at)
        VALUES (?, 'Test Growth Partner', 'Test account', 'Centre', 'approved', NOW())
    ")->execute([$user['id']]);
    echo "✅ partner_profiles row created with status=approved (ID=" . $pdo->lastInsertId() . ")<br>";
} else {
    // Update status to approved
    $pdo->prepare("UPDATE partner_profiles SET status='approved' WHERE user_id=?")->execute([$user['id']]);
    echo "✅ partner_profiles row (ID={$pp['id']}) updated: status=approved (was: {$pp['status']})<br>";
}

echo "<br><strong>Done!</strong> Now <a href='/partner/acquisition-dashboard.php'>test the acquisition dashboard</a>.";
