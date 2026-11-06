<?php
/**
 * Request Owner Authorisation — claimed business
 * Stage B §9
 * Partner requests explicit owner approval to manage a claimed business.
 * Inserts into business_owner_authorisations, notifies owner.
 */

require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)($_SESSION['user_id'] ?? 0);
$prospectId     = (int)($_GET['prospect_id'] ?? $_POST['prospect_id'] ?? 0);

if ($prospectId <= 0) {
    header('Location: my-prospects.php');
    exit;
}

$pdo = db();

// Load prospect — must belong to this partner
$stmt = $pdo->prepare("
    SELECT bp.*, b.name AS business_name, b.city, b.category,
           b.owner_user_id,
           u.name AS owner_name, u.email AS owner_email
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    LEFT JOIN users u ON u.id = b.owner_user_id
    WHERE bp.id = ? AND bp.partner_id = ?
");
$stmt->execute([$prospectId, $partnerId]);
$prospect = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prospect) {
    header('Location: my-prospects.php');
    exit;
}

// Must be a claimed business
if (empty($prospect['owner_user_id'])) {
    $_SESSION['flash_error'] = 'This business is unclaimed. Send a Claim Invitation instead.';
    header('Location: prospect-detail.php?id=' . $prospectId);
    exit;
}

// Check for an already-pending authorisation request
$existing = $pdo->prepare("
    SELECT id, status, requested_at FROM business_owner_authorisations
    WHERE business_id = ? AND partner_id = ? AND status IN ('pending','approved')
    ORDER BY requested_at DESC LIMIT 1
");
$existing->execute([$prospect['business_id'], $partnerId]);
$activeAuth = $existing->fetch(PDO::FETCH_ASSOC);

// Partner profile for the owner to review
$partnerStmt = $pdo->prepare("
    SELECT p.business_name, p.bio, p.city, p.specialisations,
           u.name AS partner_user_name
    FROM partner_profiles p
    JOIN users u ON u.id = p.user_id
    WHERE p.id = ?
");
$partnerStmt->execute([$partnerId]);
$partnerProfile = $partnerStmt->fetch(PDO::FETCH_ASSOC);

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } elseif ($activeAuth && $activeAuth['status'] === 'pending') {
        $error = 'You already have a pending authorisation request for this business.';
    } elseif ($activeAuth && $activeAuth['status'] === 'approved') {
        $error = 'The owner has already authorised you to manage this business.';
    } else {
        $requestedServices     = $_POST['requested_services']     ?? [];
        $requestedPermissions  = $_POST['requested_permissions']  ?? [];
        $proposedStart         = trim($_POST['proposed_start']    ?? '') ?: null;
        $notes                 = trim($_POST['notes']             ?? '');
        $termsAgreed           = !empty($_POST['terms_agreed'])   ? 1 : 0;

        $allowedServices = [
            'profile_management', 'social_media', 'advertising',
            'review_management', 'content_creation', 'analytics',
        ];
        $allowedPermissions = [
            'edit_profile', 'respond_reviews', 'post_updates',
            'run_promotions', 'view_analytics',
        ];

        $services    = array_values(array_intersect((array)$requestedServices,    $allowedServices));
        $permissions = array_values(array_intersect((array)$requestedPermissions, $allowedPermissions));

        if (empty($services)) {
            $error = 'Please select at least one service you would like to provide.';
        } elseif (!$termsAgreed) {
            $error = 'You must confirm that you have the owner\'s prior verbal consent.';
        } else {
            $ins = $pdo->prepare("
                INSERT INTO business_owner_authorisations
                    (business_id, owner_user_id, partner_id, requested_by,
                     status, requested_services, requested_permissions,
                     proposed_start, terms_agreed, notes, requested_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
            ");
            $ins->execute([
                $prospect['business_id'],
                $prospect['owner_user_id'],
                $partnerId,
                $userId,
                json_encode($services),
                json_encode($permissions),
                $proposedStart,
                $termsAgreed,
                $notes,
            ]);
            $authId = (int) $pdo->lastInsertId();

            // Update prospect status
            $pdo->prepare("UPDATE business_prospects SET status='awaiting_authorisation', updated_at=NOW() WHERE id=?")
                ->execute([$prospectId]);

            // Notify owner — hook into existing notifications table if present
            // Build the owner-facing URL for them to approve/decline
            $ownerUrl = rtrim(SITE_URL ?? 'https://237biz.net', '/') . '/owner-authorise.php?auth_id=' . $authId;

            if (function_exists('sendMail') && !empty($prospect['owner_email'])) {
                $partnerName = $partnerProfile['business_name'] ?? $partnerProfile['partner_user_name'] ?? 'A Growth Partner';
                $subject = $partnerName . ' is requesting permission to manage your ' . $prospect['business_name'] . ' listing';
                $body    = "Hello {$prospect['owner_name']},\n\n"
                         . "{$partnerName} has requested authorisation to help manage your business listing on 237biz.\n\n"
                         . "Services requested: " . implode(', ', $services) . "\n\n"
                         . "Review and respond here:\n{$ownerUrl}\n\n"
                         . "You can approve or decline at any time. This request does not change anything about your listing until you explicitly approve it.\n\n"
                         . "237biz Team";
                sendMail($prospect['owner_email'], $subject, $body);
            }

            // Audit log
            $pdo->prepare("
                INSERT INTO acquisition_activity_logs
                    (event_type, business_id, partner_id, actor_user_id,
                     subject_type, subject_id, new_value, ip_address, created_at)
                VALUES ('authorisation_requested', ?, ?, ?, 'business_owner_authorisations', ?, ?, ?, NOW())
            ")->execute([
                $prospect['business_id'], $partnerId, $userId, $authId,
                json_encode(['services' => $services, 'permissions' => $permissions]),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $success     = 'Authorisation request sent to the business owner.';
            $ownerUrlOut = $ownerUrl;
        }
    }
}

$pageTitle = 'Request Owner Authorisation';
require_once '../includes/partner-header.php';
?>

<div class="container-fluid px-4 py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <a href="prospect-detail.php?id=<?= $prospectId ?>" class="text-muted text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i> Back to <?= htmlspecialchars($prospect['business_name']) ?>
      </a>
      <h1 class="h3 mb-0 mt-1">Request Owner Authorisation</h1>
      <p class="text-muted mb-0">Ask the owner of <strong><?= htmlspecialchars($prospect['business_name']) ?></strong> for permission to manage their listing.</p>
    </div>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success">
    <strong>Request sent.</strong> The owner has been notified and will receive a link to approve or decline your request.
    <?php if (!empty($ownerUrlOut)): ?>
    <div class="mt-2 small text-muted">Owner approval link (for reference): <code class="user-select-all"><?= htmlspecialchars($ownerUrlOut) ?></code></div>
    <?php endif; ?>
  </div>
  <a href="prospect-detail.php?id=<?= $prospectId ?>" class="btn btn-primary">Back to Prospect</a>

  <?php else: ?>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($activeAuth && $activeAuth['status'] === 'pending'): ?>
  <div class="alert alert-warning">
    <i class="bi bi-clock me-1"></i>
    You already have a pending authorisation request submitted on
    <strong><?= date('d M Y', strtotime($activeAuth['requested_at'])) ?></strong>.
    Wait for the owner to respond.
  </div>
  <?php elseif ($activeAuth && $activeAuth['status'] === 'approved'): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle me-1"></i>
    The owner has already approved your request to manage this business.
  </div>
  <?php else: ?>

  <div class="row g-4">
    <!-- Left: form -->
    <div class="col-12 col-md-7">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4">

          <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle me-1"></i>
            This request will be sent to <strong><?= htmlspecialchars($prospect['owner_name'] ?? 'the business owner') ?></strong>.
            They must approve before you can manage this listing. The business owner retains full ownership
            and can revoke access at any time.
          </div>

          <form method="post">
            <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="prospect_id" value="<?= $prospectId ?>">

            <div class="mb-4">
              <label class="form-label fw-semibold">Services you want to provide <span class="text-danger">*</span></label>
              <div class="row g-2">
                <?php foreach ([
                    'profile_management' => 'Profile Management',
                    'social_media'       => 'Social Media',
                    'advertising'        => 'Advertising',
                    'review_management'  => 'Review Management',
                    'content_creation'   => 'Content Creation',
                    'analytics'          => 'Analytics & Reporting',
                ] as $val => $lbl): ?>
                <div class="col-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox"
                           name="requested_services[]" value="<?= $val ?>"
                           id="svc_<?= $val ?>"
                           <?= in_array($val, (array)($_POST['requested_services'] ?? []), true) ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="svc_<?= $val ?>"><?= $lbl ?></label>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">Permissions needed <span class="text-muted fw-normal">(optional)</span></label>
              <div class="row g-2">
                <?php foreach ([
                    'edit_profile'      => 'Edit listing profile',
                    'respond_reviews'   => 'Respond to reviews',
                    'post_updates'      => 'Post updates & news',
                    'run_promotions'    => 'Run promotions',
                    'view_analytics'    => 'View analytics',
                ] as $val => $lbl): ?>
                <div class="col-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox"
                           name="requested_permissions[]" value="<?= $val ?>"
                           id="perm_<?= $val ?>"
                           <?= in_array($val, (array)($_POST['requested_permissions'] ?? []), true) ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="perm_<?= $val ?>"><?= $lbl ?></label>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">Proposed start date <span class="text-muted fw-normal">(optional)</span></label>
              <input type="date" name="proposed_start" class="form-control"
                     min="<?= date('Y-m-d') ?>"
                     value="<?= htmlspecialchars($_POST['proposed_start'] ?? '') ?>">
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">Message to owner <span class="text-muted fw-normal">(optional)</span></label>
              <textarea name="notes" class="form-control" rows="3"
                        placeholder="e.g. We spoke at your shop on Monday and I explained how I can help grow your online presence."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <div class="mb-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="terms_agreed" value="1" id="terms_agreed"
                       <?= !empty($_POST['terms_agreed']) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="terms_agreed">
                  <strong>I confirm</strong> that I have already spoken to the business owner and obtained their verbal consent to submit this request.
                  I understand that this request must be explicitly approved by the owner before any management access is granted.
                </label>
              </div>
            </div>

            <button type="submit" class="btn btn-success w-100">
              <i class="bi bi-person-check me-1"></i> Send Authorisation Request
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Right: partner profile preview -->
    <div class="col-12 col-md-5">
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0">Your profile as the owner will see it</h6>
        </div>
        <div class="card-body">
          <?php if ($partnerProfile): ?>
          <p class="mb-1 fw-semibold"><?= htmlspecialchars($partnerProfile['business_name'] ?? $partnerProfile['partner_user_name'] ?? '') ?></p>
          <?php if (!empty($partnerProfile['city'])): ?>
          <p class="text-muted small mb-2"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($partnerProfile['city']) ?></p>
          <?php endif; ?>
          <?php if (!empty($partnerProfile['bio'])): ?>
          <p class="small mb-2"><?= htmlspecialchars($partnerProfile['bio']) ?></p>
          <?php endif; ?>
          <?php if (!empty($partnerProfile['specialisations'])): ?>
          <div class="small text-muted">
            <strong>Specialisations:</strong> <?= htmlspecialchars($partnerProfile['specialisations']) ?>
          </div>
          <?php endif; ?>
          <?php else: ?>
          <p class="text-muted small mb-0">Profile information unavailable.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 bg-light">
        <div class="card-body p-3">
          <h6 class="mb-2 small fw-semibold text-muted text-uppercase">What happens next</h6>
          <ol class="small mb-0 ps-3">
            <li class="mb-1">The owner receives an email with a secure link to your request.</li>
            <li class="mb-1">They review your profile and the services you are proposing.</li>
            <li class="mb-1">If they approve, your prospect moves to <em>Awaiting Assignment</em>.</li>
            <li>An admin then activates your partner assignment.</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>
  <?php endif; ?>

</div>

<?php require_once '../includes/partner-footer.php'; ?>
