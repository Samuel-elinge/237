<?php
/**
 * Claim Listing — public token page
 * Stage C §8b
 * Business owner clicks the invitation link, verifies ownership,
 * and claims their existing 237biz listing.
 *
 * URL: /claim-listing.php?token=<64-char hex>
 */

require_once 'includes/config.php';
require_once 'includes/db.php';

$rawToken = $_GET['token'] ?? $_POST['token'] ?? '';
$token    = preg_replace('/[^a-f0-9]/i', '', $rawToken);

if (strlen($token) !== 64) {
    http_response_code(404);
    die('Invalid or missing invitation token.');
}

$pdo = db();

// Load invitation + business details
$stmt = $pdo->prepare("
    SELECT bi.*,
           b.name AS business_name, b.city, b.category, b.owner_user_id,
           p.business_name AS partner_biz_name, u_p.name AS partner_user_name
    FROM business_invitations bi
    JOIN businesses b   ON b.id = bi.business_id
    JOIN partners p     ON p.id = bi.partner_id
    JOIN users u_p      ON u_p.id = p.user_id
    WHERE bi.token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$inv) {
    http_response_code(404);
    die('Invitation not found.');
}

// Check expiry
$expired  = strtotime($inv['token_expires']) < time();
$accepted = $inv['status'] === 'accepted';
$cancelled= in_array($inv['status'], ['cancelled','expired'], true);
$alreadyClaimed = !empty($inv['owner_user_id']);

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$expired && !$accepted && !$cancelled) {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $claimAction = $_POST['claim_action'] ?? '';

        if ($claimAction === 'claim') {
            $ownerName  = trim($_POST['owner_name']  ?? '');
            $ownerEmail = trim($_POST['owner_email'] ?? '');
            $ownerPhone = trim($_POST['owner_phone'] ?? '');
            $password   = $_POST['password']         ?? '';
            $confirmPw  = $_POST['confirm_password'] ?? '';
            $existingId = (int)($_POST['existing_user_id'] ?? 0); // 0 = new account

            if (empty($ownerName)) {
                $error = 'Please enter your name.';
            } elseif (empty($ownerEmail) || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif ($existingId === 0 && strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($existingId === 0 && $password !== $confirmPw) {
                $error = 'Passwords do not match.';
            } else {
                $ownerUserId = $existingId;

                if ($ownerUserId === 0) {
                    // Check if email already registered
                    $check = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
                    $check->execute([$ownerEmail]);
                    $existing = $check->fetch();
                    if ($existing) {
                        $error = 'That email is already registered. Please log in first, then return to this link.';
                    } else {
                        // Create a new user account with role 'creator' (business owner)
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $ins  = $pdo->prepare("
                            INSERT INTO users (name, email, phone, password, role, status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, 'creator', 'active', NOW(), NOW())
                        ");
                        $ins->execute([$ownerName, $ownerEmail, $ownerPhone ?: null, $hash]);
                        $ownerUserId = (int) $pdo->lastInsertId();
                    }
                }

                if (!$error && $ownerUserId > 0) {
                    // Assign the owner to the business
                    $pdo->prepare("
                        UPDATE businesses
                        SET owner_user_id=?, updated_at=NOW()
                        WHERE id=? AND (owner_user_id IS NULL OR owner_user_id=0)
                    ")->execute([$ownerUserId, $inv['business_id']]);

                    // Mark invitation accepted
                    $pdo->prepare("
                        UPDATE business_invitations
                        SET status='accepted', accepted_at=NOW(), accepted_by=?, updated_at=NOW()
                        WHERE id=?
                    ")->execute([$ownerUserId, $inv['id']]);

                    // Advance prospect status (invitation_sent → awaiting_authorisation)
                    $pdo->prepare("
                        UPDATE business_prospects
                        SET status='awaiting_authorisation', updated_at=NOW()
                        WHERE business_id=? AND partner_id=? AND status='invitation_sent'
                    ")->execute([$inv['business_id'], $inv['partner_id']]);

                    // Audit log
                    $pdo->prepare("
                        INSERT INTO acquisition_activity_logs
                            (event_type, business_id, partner_id, actor_user_id,
                             subject_type, subject_id, new_value, ip_address, created_at)
                        VALUES ('listing_claimed', ?, ?, ?, 'business_invitations', ?, ?, ?, NOW())
                    ")->execute([
                        $inv['business_id'], $inv['partner_id'], $ownerUserId, $inv['id'],
                        json_encode(['new_owner_user_id' => $ownerUserId]),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);

                    $success = 'claimed';
                    $newUserId = $ownerUserId;
                }
            }
        }
    }
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$siteName   = SITE_NAME ?? '237biz';
$loginUrl   = rtrim(SITE_URL ?? 'https://237biz.net', '/') . '/login.php';
$dashUrl    = rtrim(SITE_URL ?? 'https://237biz.net', '/') . '/creator/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Claim Your Listing — <?= htmlspecialchars($inv['business_name']) ?> — <?= htmlspecialchars($siteName) ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<style>
  :root {
    --brand: #1a6b3c;
    --brand-light: #e8f5ef;
    --bg: #f8f9fa;
    --fg: #212529;
    --card-bg: #ffffff;
    --border: #dee2e6;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      --bg: #1a1d20; --fg: #e9ecef; --card-bg: #252830;
      --border: #373b40; --brand-light: #0d2b1a;
      color-scheme: dark;
    }
  }
  :root[data-theme="dark"] {
    --bg: #1a1d20; --fg: #e9ecef; --card-bg: #252830;
    --border: #373b40; --brand-light: #0d2b1a;
    color-scheme: dark;
  }
  body { background: var(--bg); color: var(--fg); font-family: 'Inter', sans-serif; }
  .brand-bar { background: var(--brand); color: #fff; padding: 1rem 1.5rem; }
  .card { background: var(--card-bg); border-color: var(--border) !important; }
  .step-num {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--brand); color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700; flex-shrink: 0;
  }
</style>
</head>
<body>

<div class="brand-bar d-flex align-items-center gap-3">
  <span class="fw-bold fs-5"><?= htmlspecialchars($siteName) ?></span>
  <span class="opacity-75 small">Claim Your Listing</span>
</div>

<div class="container py-5" style="max-width:640px">

  <?php if ($expired): ?>
  <!-- ── Expired ─────────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm text-center p-5">
    <div class="text-warning fs-1 mb-3"><i class="bi bi-clock-history"></i></div>
    <h2 class="h4 mb-2">Invitation Expired</h2>
    <p class="text-muted">
      This invitation link expired on
      <strong><?= date('d F Y', strtotime($inv['token_expires'])) ?></strong>.
      Please ask the Growth Partner to send you a new invitation.
    </p>
  </div>

  <?php elseif ($cancelled): ?>
  <div class="card border-0 shadow-sm text-center p-5">
    <div class="text-secondary fs-1 mb-3"><i class="bi bi-x-circle"></i></div>
    <h2 class="h4 mb-2">Invitation Cancelled</h2>
    <p class="text-muted">This invitation is no longer active.</p>
  </div>

  <?php elseif ($success === 'claimed'): ?>
  <!-- ── Success ─────────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm text-center p-5">
    <div class="text-success fs-1 mb-3"><i class="bi bi-check-circle-fill"></i></div>
    <h2 class="h4 mb-2">Listing Claimed!</h2>
    <p class="text-muted">
      You are now the registered owner of
      <strong><?= htmlspecialchars($inv['business_name']) ?></strong> on <?= htmlspecialchars($siteName) ?>.
    </p>
    <p class="text-muted small">
      The Growth Partner who found your listing will now send you a separate authorisation
      request if they would like to help manage it. That request is completely optional —
      you decide whether to approve it.
    </p>
    <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center mt-3">
      <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-success px-4">
        <i class="bi bi-box-arrow-in-right me-1"></i> Log In to Your Account
      </a>
    </div>
  </div>

  <?php elseif ($alreadyClaimed && $inv['status'] !== 'accepted'): ?>
  <div class="card border-0 shadow-sm text-center p-5">
    <div class="text-info fs-1 mb-3"><i class="bi bi-building-check"></i></div>
    <h2 class="h4 mb-2">Already Claimed</h2>
    <p class="text-muted">
      <strong><?= htmlspecialchars($inv['business_name']) ?></strong> has already been claimed by its owner.
    </p>
  </div>

  <?php elseif ($accepted): ?>
  <div class="card border-0 shadow-sm text-center p-5">
    <div class="text-success fs-1 mb-3"><i class="bi bi-check-circle"></i></div>
    <h2 class="h4 mb-2">Already Claimed</h2>
    <p class="text-muted">This listing has already been claimed using this invitation link.</p>
    <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-outline-primary mt-2">Log In</a>
  </div>

  <?php else: ?>
  <!-- ── Main claim form ─────────────────────────────────────────── -->

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
      <h1 class="h4 mb-1">Claim Your Business Listing</h1>
      <p class="text-muted mb-4">
        <strong><?= htmlspecialchars($inv['partner_biz_name'] ?? $inv['partner_user_name']) ?></strong>
        found your <strong><?= htmlspecialchars($inv['business_name']) ?></strong> listing on
        <?= htmlspecialchars($siteName) ?> and invited you to claim it.
      </p>

      <!-- Business card -->
      <div class="rounded p-3 mb-4" style="background: var(--brand-light);">
        <div class="fw-semibold"><?= htmlspecialchars($inv['business_name']) ?></div>
        <?php if (!empty($inv['category'])): ?>
          <div class="small text-muted"><?= htmlspecialchars($inv['category']) ?></div>
        <?php endif; ?>
        <?php if (!empty($inv['city'])): ?>
          <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($inv['city']) ?></div>
        <?php endif; ?>
      </div>

      <!-- How it works -->
      <div class="mb-4">
        <div class="fw-semibold small text-muted text-uppercase mb-3">How it works</div>
        <div class="d-flex gap-3 mb-2 align-items-start">
          <span class="step-num">1</span>
          <span class="small">Create a free <?= htmlspecialchars($siteName) ?> account (or log in if you already have one).</span>
        </div>
        <div class="d-flex gap-3 mb-2 align-items-start">
          <span class="step-num">2</span>
          <span class="small">Your business listing is linked to your account — you become the verified owner.</span>
        </div>
        <div class="d-flex gap-3 align-items-start">
          <span class="step-num">3</span>
          <span class="small">The Growth Partner who found you may send a separate request to help manage your listing — you approve or decline that separately.</span>
        </div>
      </div>

      <form method="post">
        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="token"        value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="claim_action" value="claim">

        <h5 class="mb-3">Create your account</h5>

        <div class="mb-3">
          <label class="form-label fw-semibold small">Full name <span class="text-danger">*</span></label>
          <input type="text" name="owner_name" class="form-control"
                 value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>"
                 placeholder="Your full name" required>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Email address <span class="text-danger">*</span></label>
          <input type="email" name="owner_email" class="form-control"
                 value="<?= htmlspecialchars($_POST['owner_email'] ?? $inv['recipient_email'] ?? '') ?>"
                 placeholder="you@example.com" required>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Phone number <span class="text-muted fw-normal">(optional)</span></label>
          <input type="tel" name="owner_phone" class="form-control"
                 value="<?= htmlspecialchars($_POST['owner_phone'] ?? '') ?>"
                 placeholder="+237 6xx xxx xxx">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Password <span class="text-danger">*</span></label>
          <input type="password" name="password" class="form-control"
                 placeholder="At least 8 characters" minlength="8" required>
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold small">Confirm password <span class="text-danger">*</span></label>
          <input type="password" name="confirm_password" class="form-control"
                 placeholder="Repeat your password" minlength="8" required>
        </div>

        <div class="mb-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="terms" required>
            <label class="form-check-label small" for="terms">
              I confirm that I am the owner or authorised representative of
              <strong><?= htmlspecialchars($inv['business_name']) ?></strong>
              and I agree to the <?= htmlspecialchars($siteName) ?> terms of service.
            </label>
          </div>
        </div>

        <button type="submit" class="btn btn-success w-100 py-2">
          <i class="bi bi-building-check me-2"></i><strong>Claim My Listing</strong>
        </button>
      </form>

      <hr class="my-4">
      <p class="text-center text-muted small mb-0">
        Already have an account?
        <a href="<?= htmlspecialchars($loginUrl) ?>?redirect=<?= urlencode('claim-listing.php?token=' . $token) ?>">
          Log in here
        </a>
      </p>
    </div>
  </div>

  <?php endif; ?>

  <p class="text-center text-muted small">
    Questions? Contact <a href="mailto:<?= htmlspecialchars(SUPPORT_EMAIL ?? 'support@237biz.net') ?>">
    <?= htmlspecialchars(SUPPORT_EMAIL ?? 'support@237biz.net') ?></a>
  </p>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
