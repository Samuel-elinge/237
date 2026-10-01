<?php
/**
 * 237biz Digital Health Check — staff actions endpoint
 * Handles: assessment notes, status changes, follow-up dates, business linking.
 * Sales staff may only act on assessments they created; admins may act on any.
 */
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
$pdo = db();

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$staffUser = currentStaffUser();
if (!$staffUser) json_out(['error' => 'Staff login required'], 401);
$staffId = (int) $staffUser['id'];
$isAdmin = $staffUser['role'] === 'admin';

$action = $_POST['action'] ?? '';
$assessmentId = (int) ($_POST['assessment_id'] ?? 0);

// Ownership check shared by every action below
if ($assessmentId > 0 && !$isAdmin) {
    $check = $pdo->prepare("SELECT agent_id FROM health_assessments WHERE id = ?");
    $check->execute([$assessmentId]);
    $owner = $check->fetch(PDO::FETCH_ASSOC);
    if (!$owner || (int) $owner['agent_id'] !== $staffId) {
        json_out(['error' => 'You can only manage assessments you created'], 403);
    }
}

if ($action === 'add_note') {
    $note = trim($_POST['note'] ?? '');
    if ($assessmentId <= 0 || $note === '') json_out(['error' => 'assessment_id and note are required'], 400);

    $pdo->prepare("INSERT INTO health_assessment_notes (assessment_id, agent_id, note, created_at) VALUES (?,?,?,NOW())")
        ->execute([$assessmentId, $staffId, $note]);
    json_out(['ok' => true]);
}

if ($action === 'update_status') {
    $status = trim($_POST['status'] ?? '');
    $validStatuses = ['draft','sent','started','completed','contacted','follow_up','converted','not_interested'];
    if (!in_array($status, $validStatuses, true)) json_out(['error' => 'Invalid status'], 400);

    $pdo->prepare("UPDATE health_assessments SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $assessmentId]);
    json_out(['ok' => true]);
}

if ($action === 'set_follow_up') {
    $date = trim($_POST['follow_up_date'] ?? '');
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_out(['error' => 'Invalid date'], 400);

    $pdo->prepare("UPDATE health_assessments SET follow_up_date = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$date ?: null, $assessmentId]);
    json_out(['ok' => true]);
}

if ($action === 'link_business') {
    $businessId = (int) ($_POST['business_id'] ?? 0);
    if ($assessmentId <= 0 || $businessId <= 0) json_out(['error' => 'assessment_id and business_id are required'], 400);

    $pdo->prepare("UPDATE health_assessments SET business_id = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$businessId, $assessmentId]);
    json_out(['ok' => true]);
}

if ($action === 'unlink_business') {
    $pdo->prepare("UPDATE health_assessments SET business_id = NULL, updated_at = NOW() WHERE id = ?")->execute([$assessmentId]);
    json_out(['ok' => true]);
}

json_out(['error' => 'Unknown action'], 400);
