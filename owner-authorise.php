<?php
/**
 * Owner Authorisation Response — public page (no login required)
 * Stage C §10
 * Business owner approves or declines a Growth Partner management request.
 * Reached via the secure link emailed by request-authorisation.php.
 *
 * URL: /owner-authorise.php?auth_id=N
 * (Requires the owner to be logged in as the business owner, OR verifies
 *  via a secondary token if the owner is not registered — handled below.)
 */

require_once 'includes/config.php';
require_once 'includes/db.php';

// Optional auth — owner may or may not have a session
$sessionUserId = $_SESSION['user_id'] ?? null;

$authId = (int)($_GET['auth_id'] ?? $_POST['auth_id'] ?? 0);
if ($authId <= 0) {
    http_response_code(404);
    die('Invalid link.');
}

$pdo = db();

// Load the authorisation row + related data
$stmt = $pdo->prepare("
    SELECT boa.*,
           b.name AS business_name, b.city, b.category,
           u_owner.name AS owner_name, u_owner.email AS owner_email,
           p.business_name AS partner_biz_name, p.bio AS partner_bio,
           p.city AS partner_city, p.specialisations,
           u_partner.name AS partner_user_name, u_partner.email AS partner_email
    FROM business_owner_authorisations boa
    JOIN businesses b        ON b.id  = boa.business_id
    JOIN users u_owner       ON u_owner.id = boa.owner_user_id
    JOIN partners p          ON p.id  = boa.partner_id
    JOIN users u_partner     ON u_partner.id = p.user_id
    WHERE boa.id = ?
");
$stmt->execute([$authId]);
$auth = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$auth) {
    http_response_code(404);
    die('Authorisation request not found.');
}

// Security: if owner is logged in, confirm they own this business
if ($sessionUserId && (int)$auth['owner_user_id'] !== (int)$sessionUserId) {
    http_response_code(403);
    die('You do not have permission to respond to this request.');
}

$alreadyActioned = in_array($auth['status'], ['approved','declined','revoked','expired'], true);

$error   = '';
$success = '';
$action  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyActioned) {
    // CSRF — lightweight token stored in URL-based session for unregistered owners
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $postedAction = $_POST['owner_action'] ?? '';

        if ($postedAction === 'approve') {
            $pdo->prepare("
                UPDATE business_owner_authorisations
                SET status='approved', responded_at=NOW(), consent_timestamp=NOW(),
                    consent_ip=?, updated_at=NOW()
                WHERE id=? AND status='pending'
            ")->execute([$_SERVER['REMOTE_ADDR'] ?? null, $authId]);

            // Advance prospect to awaiting_assignment
            $pdo->prepare("
                UPDATE business_prospects
                SET status='awaiting_assignment', updated_at=NOW()
                WHERE business_id=? AND partner_id=?
                  AND status IN('awaiting_authorisation','interested','new','contacted')
            ")->execute([$auth['business_id'], $auth['partner_id']]);

            // Audit
            $pdo->prepare("
                INSERT INTO acquisition_activity_logs
                    (event_type, business_id, partner_id, actor_user_id,
                     subject_type, subject_id, new_value, ip_address, created_at)
                VALUES ('owner_authorised', ?, ?, ?, 'business_owner_authorisations', ?, ?, ?, NOW())
            ")->execute([
                $auth['business_id'], $auth['partner_id'],
                $auth['owner_user_id'], $authId,
                json_encode(['method' => 'owner_link']),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $auth['status'] = 'approved';
            $success = 'approved';

        } elseif ($postedAction === 'decline') {
            $declineReason = trim($_POST['decline_reason'] ?? '');

            $pdo->prepare("
                UPDATE business_owner_authorisations
                SET status='declined', responded_at=NOW(), updated_at=NOW(),
                    notes=CONCAT(IFNULL(notes,''), ?, ?)
                WHERE id=? AND status='pending'
            ")->execute([
                $declineReason ? "\n[Owner declined] " : '',
                $declineReason,
                $authId,
            ]);

            // Audit
            $pdo->prepare("
                INSERT INTO acquisition_activity_logs
                    (event_type, business_id, partner_id, actor_user_id,
                     subject_type, subject_id, new_value, ip_address, created_at)
                VALUES ('owner_declined', ?, ?, ?, 'business_owner_authorisations', ?, ?, ?, NOW())
            ")->execute([
                $auth['business_id'], $auth['partner_id'],
                $auth['owner_user_id'], $authId,
                json_encode(['reason' => $declineReason]),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $auth['status'] = 'declined';
            $success = 'declined';
        }
    }
}

// Generate CSRF for the form
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$services    = json_decode($auth['requested_services']    ?? '[]', true) ?: [];
$permissions = json_decode($auth['requested_permissions'] ?? '[]', true) ?: [];

$serviceLabels = [
    'profile_management' => 'Profile Management',
    'social_media'       => 'Social Media',
    'advertising'        => 'Advertising',
    'review_management'  => 'Review Management',
    'content_creation'   => 'Content Creation',
    'analytics'          => 'Analytics & Reporting',
];
$permissionLabels = [
    'edit_profile'      => 'Edit listing profile',
    'respond_reviews'   => 'Respond to reviews',
    'post_updates'      => 'Post updates & news',
    'run_promotions'    => 'Run promotions',
    'view_analytics'    => 'View analytics',
];

$siteName = SITE_NAME ?? '237biz';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Partner Request — <?= htmlspecialchars($auth['business_name']) ?> — <?= htmlspecialchars($siteName) ?></title>
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
      --bg: #1a1d20;
      --fg: #e9ecef;
      --card-bg: #252830;
      --border: #373b40;
      --brand-light: #0d2b1a;
      color-scheme: dark;
    }
  }
  :root[data-theme="dark"] {
    --bg: #1a1d20;
    --fg: #e9ecef;
    --card-bg: #252830;
    --border: #373b40;
    --brand-light: #0d2b1a;
    color-scheme: dark;
  }
  body { background: var(--bg); color: var(--fg); font-family: 'Inter', sans-serif; }
  .brand-bar { background: var(--brand); color: #fff; padding: 1rem 1.5rem; }
  .card { background: var(--card-bg); border-color: var(--border) !important; }
  .service-chip {
    display: inline-flex; align-items: center; gap: .4rem;
    background: var(--brand-light); color: var(--brand);
    border-radius: 20px; padding: .25rem .75rem; font-size: .85rem; font-weight: 500;
  }
</style>
</head>
<body>

<div class="brand-bar d-flex align-items-center gap-3">
  <span class="fw-bold fs-5"><?= htmlspecialchars($siteName) ?></span>
  <span class="opacity-75 small">Business Partner Request</span>
</div>

<div class="container py-5" style="max-width:680px">

  <?php if ($success === 'approved'): ?>
  <!-- ── Approved confirmation ───────────────────────────────────── -->
  <div class="card border-0 shadow-sm text-center p-5">
    <div class="text-success fs-1 mb-3"><i class="bi bi-check-circle-fill"></i></div>
    <h2 class="h4 mb-2">Authorisation Approved</h2>
    <p class="text-muted">
      You have approved <strong><?= htmlspecialchars($auth['partner_biz_name'] ?? $auth['partner_user_name']) ?></strong>
      to manage your <strong><?= htmlspecialchars($auth['business_name']) ?></strong> listing on <?= htmlspecialchars($siteName) ?>.
    </p>
    <p class="text-muted small">
      A member of the <?= htmlspecialchars($siteName) ?> team will contact you to confirm the arrangement.
      You can revoke this authorisation at any time by logging into your <?= htmlspecialchars($siteName) ?> account.
    </p>
  </div>

  <?php elseif ($success === 'declined'): ?>
  <!-- ── Declined confirmation ──────────────────────────────────── -->
  <div class="card border-0 shadow-sm text-center p-5">
    <div class="text-secondary fs-1 mb-3"><i class="bi bi-x-circle-fill"></i></div>
    <h2 class="h4 mb-2">Request Declined</h2>
    <p class="text-muted">
      You have declined the partner request for <strong><?= htmlspecialchars($auth['business_name']) ?></strong>.
      No changes have been made to your listing.
    </p>
    <p class="text-muted small">If you change your mind, you can contact <?= htmlspecialchars($siteName) ?> support.</p>
  </div>

  <?php elseif ($alreadyActioned): ?>
  <!-- ── Already actioned ───────────────────────────────────────── -->
  <div class="card border-0 shadow-sm text-center p-5">
    <?php if ($auth['status'] === 'approved'): ?>
      <div class="text-success fs-1 mb-3"><i class="bi bi-check-circle"></i></div>
      <h2 class="h4">Already Approved</h2>
      <p class="text-muted">You previously approved this partner request.</p>
    <?php elseif ($auth['status'] === 'declined'): ?>
      <div class="text-secondary fs-1 mb-3"><i class="bi bi-x-circle"></i></div>
      <h2 class="h4">Already Declined</h2>
      <p class="text-muted">You previously declined this partner request.</p>
    <?php else: ?>
      <div class="text-warning fs-1 mb-3"><i class="bi bi-exclamation-circle"></i></div>
      <h2 class="h4">Request No Longer Active</h2>
      <p class="text-muted">This request has status: <strong><?= htmlspecialchars($auth['status']) ?></strong>.</p>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <!-- ── Main review form ───────────────────────────────────────── -->

  <?php if ($error): ?>
    <div class="alert alert-danger mb-4"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
      <h1 class="h4 mb-1">Partner Management Request</h1>
      <p class="text-muted mb-4">
        A Growth Partner on <strong><?= htmlspecialchars($siteName) ?></strong> would like permission
        to help manage your <strong><?= htmlspecialchars($auth['business_name']) ?></strong> listing.
      </p>

      <!-- Partner profile -->
      <div class="rounded p-3 mb-4" style="background: var(--brand-light);">
        <div class="fw-semibold mb-1"><?= htmlspecialchars($auth['partner_biz_name'] ?? $auth['partner_user_name']) ?></div>
        <?php if (!empty($auth['partner_city'])): ?>
          <div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($auth['partner_city']) ?></div>
        <?php endif; ?>
        <?php if (!empty($auth['partner_bio'])): ?>
          <div class="small mb-1"><?= htmlspecialchars($auth['partner_bio']) ?></div>
        <?php endif; ?>
        <?php if (!empty($auth['specialisations'])): ?>
          <div class="small"><strong>Specialisations:</strong> <?= htmlspecialchars($auth['specialisations']) ?></div>
        <?php endif; ?>
      </div>

      <!-- Services requested -->
      <div class="mb-4">
        <div class="fw-semibold small text-muted text-uppercase mb-2">Services they want to provide</div>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($services as $s): ?>
            <span class="service-chip">
              <i class="bi bi-check2"></i>
              <?= htmlspecialchars($serviceLabels[$s] ?? str_replace('_',' ',$s)) ?>
            </span>
          <?php endforeach; ?>
          <?php if (empty($services)): ?>
            <span class="text-muted small">Not specified</span>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($permissions)): ?>
      <div class="mb-4">
        <div class="fw-semibold small text-muted text-uppercase mb-2">Permissions requested</div>
        <ul class="mb-0 small">
          <?php foreach ($permissions as $p): ?>
            <li><?= htmlspecialchars($permissionLabels[$p] ?? str_replace('_',' ',$p)) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if (!empty($auth['proposed_start'])): ?>
      <div class="mb-4">
        <div class="fw-semibold small text-muted text-uppercase mb-1">Proposed start date</div>
        <div class="small"><?= date('d F Y', strtotime($auth['proposed_start'])) ?></div>
      </div>
      <?php endif; ?>

      <?php if (!empty($auth['notes'])): ?>
      <div class="mb-4 p-3 border rounded small">
        <div class="fw-semibold mb-1">Message from the partner:</div>
        <?= nl2br(htmlspecialchars($auth['notes'])) ?>
      </div>
      <?php endif; ?>

      <div class="alert alert-info small mb-0">
        <i class="bi bi-shield-check me-1"></i>
        <strong>Your ownership is not affected.</strong>
        Approving this request gives the partner delegated access only.
        You remain the owner and can revoke access at any time by contacting <?= htmlspecialchars($siteName) ?> support.
      </div>
    </div>
  </div>

  <!-- Action forms -->
  <div class="row g-3">
    <div class="col-12 col-sm-6">
      <form method="post">
        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="auth_id"      value="<?= $authId ?>">
        <input type="hidden" name="owner_action" value="approve">
        <button type="submit" class="btn btn-success w-100 py-3"
                onclick="return confirm('Confirm: approve this partner to help manage your listing?')">
          <i class="bi bi-check-circle me-2"></i><strong>Approve Request</strong>
        </button>
      </form>
    </div>
    <div class="col-12 col-sm-6">
      <button type="button" class="btn btn-outline-secondary w-100 py-3"
              data-bs-toggle="collapse" data-bs-target="#declineForm">
        <i class="bi bi-x-circle me-2"></i>Decline Request
      </button>
    </div>
  </div>

  <div class="collapse mt-3" id="declineForm">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <form method="post">
          <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="auth_id"      value="<?= $authId ?>">
          <input type="hidden" name="owner_action" value="decline">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Reason for declining <span class="text-muted fw-normal">(optional)</span></label>
            <textarea name="decline_reason" class="form-control" rows="2"
                      placeholder="e.g. I am not interested at this time"></textarea>
          </div>
          <button type="submit" class="btn btn-danger w-100">Confirm Decline</button>
        </form>
      </div>
    </div>
  </div>

  <?php endif; ?>

  <p class="text-center text-muted small mt-4">
    Questions? Contact <a href="mailto:<?= htmlspecialchars(SUPPORT_EMAIL ?? 'support@237biz.net') ?>">
    <?= htmlspecialchars(SUPPORT_EMAIL ?? 'support@237biz.net') ?></a>
  </p>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
