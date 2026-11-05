<?php
/**
 * Add Business to My Prospects
 * POST handler — redirects back with success/error flash
 */

require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: find-businesses.php');
    exit;
}

// CSRF
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Invalid request. Please try again.';
    header('Location: find-businesses.php');
    exit;
}

$partnerId  = $_SESSION['partner_id']  ?? null;
$userId     = $_SESSION['user_id']     ?? null;
$businessId = (int)($_POST['business_id'] ?? 0);

if (!$partnerId || !$userId || $businessId <= 0) {
    $_SESSION['flash_error'] = 'Invalid request.';
    header('Location: find-businesses.php');
    exit;
}

$pdo = db();

// Verify business is active and not already an active prospect for this partner
$bizStmt = $pdo->prepare("SELECT id, name FROM businesses WHERE id = ? AND status = 'active'");
$bizStmt->execute([$businessId]);
$business = $bizStmt->fetch(PDO::FETCH_ASSOC);

if (!$business) {
    $_SESSION['flash_error'] = 'Business not found or not eligible.';
    header('Location: find-businesses.php');
    exit;
}

// Check for existing active prospect
$dupStmt = $pdo->prepare("
    SELECT id FROM business_prospects
    WHERE business_id = ? AND partner_id = ?
      AND status NOT IN ('not_interested','unreachable','rejected','closed')
");
$dupStmt->execute([$businessId, $partnerId]);
if ($dupStmt->fetch()) {
    $_SESSION['flash_error'] = htmlspecialchars($business['name']) . ' is already in your active prospects.';
    header('Location: find-businesses.php');
    exit;
}

// Insert prospect
$ins = $pdo->prepare("
    INSERT INTO business_prospects
        (business_id, partner_id, added_by, status, acquisition_source, created_at, updated_at)
    VALUES
        (?, ?, ?, 'new', 'partner_search', NOW(), NOW())
");
$ins->execute([$businessId, $partnerId, $userId]);
$prospectId = (int) $pdo->lastInsertId();

// Audit log
$log = $pdo->prepare("
    INSERT INTO acquisition_activity_logs
        (event_type, business_id, partner_id, actor_user_id, subject_type, subject_id,
         new_value, ip_address, created_at)
    VALUES
        ('prospect_added', ?, ?, ?, 'business_prospect', ?, ?, ?, NOW())
");
$log->execute([
    $businessId,
    $partnerId,
    $userId,
    $prospectId,
    json_encode(['business_id' => $businessId, 'status' => 'new']),
    $_SERVER['REMOTE_ADDR'] ?? null,
]);

$_SESSION['flash_success'] = htmlspecialchars($business['name']) . ' added to your prospects.';
header('Location: prospect-detail.php?id=' . $prospectId);
exit;
