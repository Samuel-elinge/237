<?php
/**
 * One-time fix: create partner_profile for Tahirih Atanga (user_id=2)
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pdo = db();

// Check if profile already exists
$existing = $pdo->prepare("SELECT id FROM partner_profiles WHERE user_id=2");
$existing->execute();
$row = $existing->fetch();

if ($row) {
    echo "Profile already exists: id=" . $row['id'];
} else {
    $pdo->prepare("INSERT INTO partner_profiles (user_id, status, approved_by, approved_at) VALUES (2, 'approved', ?, NOW())")
        ->execute([$_SESSION['user_id']]);
    $newId = $pdo->lastInsertId();
    echo "Created partner_profile id=$newId for Tahirih Atanga (user_id=2). Now delete this file.";
}
