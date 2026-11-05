<?php
/**
 * Send Claim Invitation — unclaimed business owner
 * Stage B §8
 * Growth Partner initiates a secure, expiring invitation token.
 * Does NOT create a new business listing.
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';

requirePermission('partner');

$partnerId = $_SESSION['partner_id'] ?? null;
$userId    = $_SESSION['user_id']    ?? null;
$prospectId = (int)($_GET['prospect_id'] ?? $_POST['prospect_id'] ?? 0);

if (!$partnerId || !$userId || $prospectId <= 0) {
    header('Location: my-prospects.php');
    exit;
}

$pdo = db();

// Load prospect — must belong to this partner
$stmt = $pdo->prepare("
    SELECT bp.*, b.name AS business_name, b.city, b.category,
           CASE WHEN b.owner_user_id IS NULL OR b.owner_user_id = 0 THEN 0 ELSE 1 END AS is_claimed
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    WHERE bp.id = ? AND bp.partner_id = ?
");
$stmt->execute([$prospectId, $partnerId]);
$prospect = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prospect) {
    header('Location: my-prospects.php');
    exit;
}

// Already claimed — no invitation needed
if ($prospect['is_claimed']) {
    $_SESSION['flash_error'] = 'This business has already been claimed. Use Request Authorisation instead.';
    header('Location: prospect-detail.php?id=' . $prospectId);
    exit;
}

// Check for a recent pending/sent invitation (prevent spam)
$existing = $pdo->prepare("
    SELECT id, token_expires FROM business_invitations
    WHERE business_id = ? AND partner_id = ?
      AND status IN ('pending','sent','delivered')
      AND token_expires > NOW()
    ORDER BY created_at DESC LIMIT 1
");
$existing->execute([$prospect['business_id'], $partnerId]);
$activeInvitation = $existing->fetch(PDO::FETCH_ASSOC);

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } elseif ($activeInvitation) {
        $error = 'An active invitation already exists for this business (expires ' .
                 date('d M Y', strtotime($activeInvitation['token_expires'])) . '). ' .
                 'Wait for it to expire or ask an admin to cancel it before resending.';
    } else {
        $method         = $_POST['delivery_method'] ?? 'email';
        $recipientEmail = trim($_POST['recipient_email'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');

        $allowedMethods = ['email','whatsapp','in_platform','other'];
        if (!in_array($method, $allowedMethods, true)) {
            $error = 'Please select a valid delivery method.';
        } elseif ($method === 'email' && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Generate a cryptographically secure token
            $token   = bin2hex(random_bytes(32));          // 64 hex chars
            $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

            $ins = $pdo->prepare("
                INSERT INTO business_invitations
                    (business_id, prospect_id, partner_id, initiated_by,
                     token, token_expires, delivery_method, recipient_email,
                     status, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
            ");
            $ins->execute([
                $prospect['business_id'], $prospectId, $partnerId, $userId,
                $token, $expires, $method,
                $method === 'email' ? $recipientEmail : null,
                $notes,
            ]);
            $invitationId = (int) $pdo->lastInsertId();

            // Build the claim URL
            $claimUrl = rtrim(SITE_URL ?? 'https://237biz.net', '/') . '/claim-listing.php?token=' . urlencode($token);

            // Mark as sent (email sending is handled by existing mailer / n8n webhook)
            $pdo->prepare("UPDATE business_invitations SET status='sent', sent_at=NOW() WHERE id=?")
                ->execute([$invitationId]);

            // Update prospect status
            $pdo->prepare("UPDATE business_prospects SET status='invitation_sent', updated_at=NOW() WHERE id=?")
                ->execute([$prospectId]);

            // Trigger email if method is email (hook into existing mail helper)
            if ($method === 'email' && function_exists('sendMail')) {
                $subject = 'Claim your ' . htmlspecialchars($prospect['business_name']) . ' listing on 237biz';
                $body    = "Hello,\n\nA Business Growth Partner has found your business listing on 237biz and would like to invite you to claim it.\n\nClaim your listing here (link expires in 7 days):\n{$claimUrl}\n\nIf you did not expect this invitation, you can safely ignore it.\n\n237biz Team";
                sendMail($recipientEmail, $subject, $body);
            }

            // Audit log
            $pdo->prepare("
                INSERT INTO acquisition_activity_logs
                    (event_type, business_id, partner_id, actor_user_id,
                     subject_type, subject_id, new_value, ip_address, created_at)
                VALUES ('invitation_sent', ?, ?, ?, 'business_invitations', ?, ?, ?, NOW())
            ")->execute([
                $prospect['business_id'], $partnerId, $userId, $invitationId,
                json_encode(['method' => $method, 'expires' => $expires]),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $success     = 'Invitation created successfully.';
            $claimUrlOut = $claimUrl;
        }
    }
}

$pageTitle = 'Send Claim Invitation';
require_once '../includes/partner-header.php';
?>

<div class="container-fluid px-4 py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <a href="prospect-detail.php?id=<?= $prospectId ?>" class="text-muted text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i> Back to <?= htmlspecialchars($prospect['business_name']) ?>
      </a>
      <h1 class="h3 mb-0 mt-1">Send Claim Invitation</h1>
      <p class="text-muted mb-0">Invite the owner of <strong><?= htmlspecialchars($prospect['business_name']) ?></strong> to claim their 237biz listing.</p>
    </div>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success">
    <strong>Invitation sent.</strong> <?= htmlspecialchars($success) ?>
    <?php if (!empty($claimUrlOut)): ?>
    <div class="mt-2">
      <span class="text-muted small">Claim link (share if needed):</span><br>
      <code class="user-select-all small"><?= htmlspecialchars($claimUrlOut) ?></code>
    </div>
    <?php endif; ?>
  </div>
  <a href="prospect-detail.php?id=<?= $prospectId ?>" class="btn btn-primary">Back to Prospect</a>
  <?php else: ?>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($activeInvitation): ?>
  <div class="alert alert-warning">
    <i class="bi bi-clock me-1"></i>
    An active invitation already exists for this business, expiring
    <strong><?= date('d M Y', strtotime($activeInvitation['token_expires'])) ?></strong>.
    You cannot send another until it expires or is cancelled by an admin.
  </div>
  <?php else: ?>

  <div class="row justify-content-center">
    <div class="col-12 col-md-7">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4">

          <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle me-1"></i>
            This invitation gives the business owner a secure link to <strong>claim their existing 237biz listing</strong>.
            It does <strong>not</strong> create a new listing, and it does not automatically give you management access.
            After claiming, you will need to request authorisation separately.
          </div>

          <form method="post">
            <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="prospect_id" value="<?= $prospectId ?>">

            <div class="mb-4">
              <label class="form-label fw-semibold">Delivery method</label>
              <div class="d-flex flex-wrap gap-3">
                <?php foreach (['email' => 'Email', 'whatsapp' => 'WhatsApp', 'in_platform' => 'In-platform', 'other' => 'Other'] as $val => $lbl): ?>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="delivery_method"
                         id="dm_<?= $val ?>" value="<?= $val ?>"
                         <?= ($val === 'email') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="dm_<?= $val ?>"><?= $lbl ?></label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="mb-4" id="email_field">
              <label class="form-label fw-semibold">Recipient email address</label>
              <input type="email" name="recipient_email" class="form-control"
                     placeholder="owner@example.com"
                     value="<?= htmlspecialchars($_POST['recipient_email'] ?? '') ?>">
              <div class="form-text">The claim link will be emailed to this address. Only use an address you have legitimately obtained.</div>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">Notes <span class="text-muted fw-normal">(optional)</span></label>
              <textarea name="notes" class="form-control" rows="2"
                        placeholder="e.g. Spoke to owner at their shop, agreed to send invite"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary w-100">
              <i class="bi bi-envelope-plus me-1"></i> Send Invitation
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>
  <?php endif; ?>

</div>

<script>
document.querySelectorAll('input[name="delivery_method"]').forEach(function(el) {
  el.addEventListener('change', function() {
    document.getElementById('email_field').style.display =
      this.value === 'email' ? '' : 'none';
  });
});
</script>

<?php require_once '../includes/partner-footer.php'; ?>
