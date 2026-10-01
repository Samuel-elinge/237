<?php
/**
 * Search existing business listings, for linking a completed Health Check to a
 * real 237biz listing (uses your `listings` table, matching admin/index.php).
 */
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
$pdo = db();

if (!currentStaffUser()) {
    http_response_code(401);
    echo json_encode(['error' => 'Staff login required']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$stmt = $pdo->prepare("SELECT id, title AS name FROM listings WHERE title LIKE ? ORDER BY title LIMIT 15");
$stmt->execute(['%' . $q . '%']);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
