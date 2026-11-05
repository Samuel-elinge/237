<?php
/**
 * Prospect Detail — view, update status, and record contacts
 * Stage A §6, §7
 */

require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)($_SESSION['user_id'] ?? 0);
$id             = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: my-prospects.php');
    exit;
}

$pdo = db();

// Load prospect — must belong to this partner
$stmt = $pdo->prepare("
    SELECT bp.*, b.name AS business_name, b.category, b.city, b.region,
           b.phone AS business_phone, b.website,
           CASE WHEN b.owner_user_id IS NULL OR b.owner_user_id = 0 THEN 0 ELSE 1 END AS is_claimed
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    WHERE bp.id = ? AND bp.partner_id = ?
");
$stmt->execute([$id, $partnerId]);
$prospect = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prospect) {
    header('Location: my-prospects.php');
    exit;
}

// ── POST handlers ──────────────────────────────────────────────────────────────
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_status') {
            $allowedStatuses = [
                'new','contacted','follow_up_required','interested',
                'invitation_sent','awaiting_owner_response','awaiting_authorisation',
                'awaiting_assignment','not_interested','unreachable','rejected','closed'
            ];
            $newStatus   = $_POST['status']       ?? '';
            $notes       = trim($_POST['notes']   ?? '');
            $followup    = trim($_POST['next_followup'] ?? '') ?: null;

            if (!in_array($newStatus, $allowedStatuses, true)) {
                $error = 'Invalid status.';
            } else {
                $oldStatus = $prospect['status'];
                $upd = $pdo->prepare("
                    UPDATE business_prospects
                    SET status = ?, notes = ?, next_followup = ?, updated_at = NOW()
                    WHERE id = ? AND partner_id = ?
                ");
                $upd->execute([$newStatus, $notes, $followup, $id, $partnerId]);

                // Audit
                $pdo->prepare("
                    INSERT INTO acquisition_activity_logs
                        (event_type, business_id, partner_id, actor_user_id, subject_type, subject_id,
                         old_value, new_value, ip_address, created_at)
                    VALUES ('prospect_status_changed', ?, ?, ?, 'business_prospect', ?, ?, ?, ?, NOW())
                ")->execute([
                    $prospect['business_id'], $partnerId, $userId, $id,
                    json_encode(['status' => $oldStatus]),
                    json_encode(['status' => $newStatus]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $success = 'Prospect updated.';
                $prospect['status']        = $newStatus;
                $prospect['notes']         = $notes;
                $prospect['next_followup'] = $followup;
            }
        }

        elseif ($action === 'record_contact') {
            $method  = $_POST['contact_method']  ?? '';
            $outcome = $_POST['contact_outcome'] ?? '';
            $cnotes  = trim($_POST['contact_notes'] ?? '');
            $followup= trim($_POST['next_followup'] ?? '') ?: null;

            $allowedMethods  = ['telephone','whatsapp','email','in_person','social_media','other'];
            $allowedOutcomes = ['no_answer','spoke_to_owner','interested','not_interested',
                                'requested_more_info','appointment_arranged','follow_up_required'];

            if (!in_array($method, $allowedMethods, true) || !in_array($outcome, $allowedOutcomes, true)) {
                $error = 'Please select valid contact method and outcome.';
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO business_prospect_contacts
                        (prospect_id, business_id, partner_id, contacted_at, contact_method,
                         outcome, notes, next_followup, created_by, created_at)
                    VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([
                    $id, $prospect['business_id'], $partnerId,
                    $method, $outcome, $cnotes, $followup, $userId,
                ]);

                // Update last_contact and next_followup on the prospect
                $pdo->prepare("
                    UPDATE business_prospects
                    SET last_contact = NOW(), next_followup = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$followup, $id]);

                // Auto-advance status if spoke to owner → contacted
                if ($outcome === 'spoke_to_owner' && $prospect['status'] === 'new') {
                    $pdo->prepare("UPDATE business_prospects SET status='contacted', updated_at=NOW() WHERE id=?")
                        ->execute([$id]);
                    $prospect['status'] = 'contacted';
                }
                if ($outcome === 'interested') {
                    $pdo->prepare("UPDATE business_prospects SET status='interested', updated_at=NOW() WHERE id=?")
                        ->execute([$id]);
                    $prospect['status'] = 'interested';
                }

                $prospect['last_contact']  = date('Y-m-d H:i:s');
                $prospect['next_followup'] = $followup;

                // Audit
                $pdo->prepare("
                    INSERT INTO acquisition_activity_logs
                        (event_type, business_id, partner_id, actor_user_id, subject_type, subject_id,
                         new_value, ip_address, created_at)
                    VALUES ('contact_recorded', ?, ?, ?, 'business_prospect_contacts', LAST_INSERT_ID(), ?, ?, NOW())
                ")->execute([
                    $prospect['business_id'], $partnerId, $userId,
                    json_encode(['method' => $method, 'outcome' => $outcome]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $success = 'Contact recorded.';
            }
        }
    }
}

// Contact history
$contacts = $pdo->prepare("
    SELECT * FROM business_prospect_contacts
    WHERE prospect_id = ?
    ORDER BY contacted_at DESC
    LIMIT 50
");
$contacts->execute([$id]);
$contactLog = $contacts->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'new' => 'New', 'contacted' => 'Contacted', 'follow_up_required' => 'Follow-up Required',
    'interested' => 'Interested', 'invitation_sent' => 'Invitation Sent',
    'awaiting_owner_response' => 'Awaiting Owner Response',
    'awaiting_authorisation' => 'Awaiting Authorisation',
    'awaiting_assignment' => 'Awaiting Assignment',
    'converted' => 'Converted', 'not_interested' => 'Not Interested',
    'unreachable' => 'Unreachable', 'rejected' => 'Rejected', 'closed' => 'Closed',
];

$pageTitle = 'Prospect: ' . htmlspecialchars($prospect['business_name']);
require_once '../includes/partner-header.php';
?>

<div class="container-fluid px-4 py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <a href="my-prospects.php" class="text-muted text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i> My Prospects
      </a>
      <h1 class="h3 mb-0 mt-1"><?= htmlspecialchars($prospect['business_name']) ?></h1>
      <div class="text-muted small">
        <?= htmlspecialchars($prospect['category'] ?? '') ?>
        <?= $prospect['city'] ? ' · ' . htmlspecialchars($prospect['city']) : '' ?>
        · <?= $prospect['is_claimed'] ? '<span class="badge bg-success">Claimed</span>' : '<span class="badge bg-secondary">Unclaimed</span>' ?>
      </div>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- Left: status + notes -->
    <div class="col-12 col-lg-5">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3"><h5 class="mb-0">Update Status</h5></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="update_status">
            <div class="mb-3">
              <label class="form-label small fw-semibold">Status</label>
              <select name="status" class="form-select">
                <?php foreach ($statusLabels as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= $prospect['status'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Next Follow-up</label>
              <input type="date" name="next_followup" class="form-control"
                     value="<?= htmlspecialchars($prospect['next_followup'] ?? '') ?>">
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Notes</label>
              <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($prospect['notes'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary w-100">Save Update</button>
          </form>
        </div>
      </div>

      <!-- Stage B action buttons (if claimed) -->
      <?php if ($prospect['is_claimed'] && in_array($prospect['status'], ['interested','awaiting_authorisation','awaiting_assignment'], true)): ?>
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3"><h5 class="mb-0">Stage B Actions</h5></div>
        <div class="card-body d-grid gap-2">
          <a href="request-authorisation.php?prospect_id=<?= $id ?>" class="btn btn-success">
            <i class="bi bi-person-check me-1"></i> Request Owner Authorisation
          </a>
        </div>
      </div>
      <?php elseif (!$prospect['is_claimed'] && in_array($prospect['status'], ['new','contacted','interested'], true)): ?>
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3"><h5 class="mb-0">Claim Invitation</h5></div>
        <div class="card-body d-grid">
          <a href="send-invitation.php?prospect_id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="bi bi-envelope me-1"></i> Send Claim Invitation
          </a>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right: record contact + contact history -->
    <div class="col-12 col-lg-7">

      <!-- Record contact form -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3"><h5 class="mb-0">Record Contact Attempt</h5></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="record_contact">
            <div class="row g-3">
              <div class="col-6">
                <label class="form-label small fw-semibold">Method</label>
                <select name="contact_method" class="form-select" required>
                  <option value="">Choose…</option>
                  <option value="telephone">Telephone</option>
                  <option value="whatsapp">WhatsApp</option>
                  <option value="email">Email</option>
                  <option value="in_person">In-person</option>
                  <option value="social_media">Social Media</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Outcome</label>
                <select name="contact_outcome" class="form-select" required>
                  <option value="">Choose…</option>
                  <option value="no_answer">No answer</option>
                  <option value="spoke_to_owner">Spoke to owner</option>
                  <option value="interested">Interested</option>
                  <option value="not_interested">Not interested</option>
                  <option value="requested_more_info">Requested more info</option>
                  <option value="appointment_arranged">Appointment arranged</option>
                  <option value="follow_up_required">Follow-up required</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold">Notes</label>
                <textarea name="contact_notes" class="form-control" rows="2" placeholder="What was discussed…"></textarea>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold">Schedule Follow-up</label>
                <input type="date" name="next_followup" class="form-control"
                       min="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-12 col-md-6 d-flex align-items-end">
                <button type="submit" class="btn btn-info text-white w-100">
                  <i class="bi bi-telephone-plus me-1"></i> Save Contact
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Contact history -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h5 class="mb-0">Contact History</h5>
        </div>
        <?php if (empty($contactLog)): ?>
          <div class="card-body text-center text-muted py-4">
            <i class="bi bi-telephone-x d-block fs-2 mb-2"></i>
            No contact attempts recorded yet.
          </div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($contactLog as $c): ?>
          <li class="list-group-item px-4 py-3">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <span class="badge bg-primary me-1"><?= ucfirst(str_replace('_',' ',$c['contact_method'])) ?></span>
                <span class="badge bg-secondary"><?= ucfirst(str_replace('_',' ',$c['outcome'])) ?></span>
                <?php if ($c['notes']): ?>
                  <div class="mt-1 small text-muted"><?= htmlspecialchars($c['notes']) ?></div>
                <?php endif; ?>
                <?php if ($c['next_followup']): ?>
                  <div class="mt-1 small"><i class="bi bi-calendar me-1"></i>Follow-up: <?= date('d M Y', strtotime($c['next_followup'])) ?></div>
                <?php endif; ?>
              </div>
              <div class="text-muted small text-end">
                <?= date('d M Y H:i', strtotime($c['contacted_at'])) ?>
              </div>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>

    </div>
  </div>

</div>

<?php require_once '../includes/partner-footer.php'; ?>
