<?php
/**
 * Acquisition Insights — rule-based intelligence for the acquisition pipeline
 * Stage D §12
 * Shows: overdue follow-ups, expiring invitations, stagnant prospects,
 * pending authorisations, and conversion nudges.
 */

require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)($_SESSION['user_id'] ?? 0);

$pdo = db();

// ── GENERATE INSIGHTS ────────────────────────────────────────────────────────
// Rule 1: Prospects with no contact in 7+ days (still active)
$overdueContacts = $pdo->prepare("
    SELECT bp.id, bp.status, bp.priority, bp.overdue_at, bp.created_at,
           b.name AS business_name, b.city,
           COALESCE(MAX(bpc.contacted_at), bp.created_at) AS last_contact,
           DATEDIFF(NOW(), COALESCE(MAX(bpc.contacted_at), bp.created_at)) AS days_since_contact
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    LEFT JOIN business_prospect_contacts bpc ON bpc.prospect_id = bp.id
    WHERE bp.partner_id = ?
      AND bp.status NOT IN ('converted','lost','on_hold')
    GROUP BY bp.id
    HAVING days_since_contact >= 7
    ORDER BY days_since_contact DESC
    LIMIT 20
");
$overdueContacts->execute([$partnerId]);
$overdueRows = $overdueContacts->fetchAll(PDO::FETCH_ASSOC);

// Rule 2: Invitations expiring within 48 hours
$expiringInvitations = $pdo->prepare("
    SELECT bi.id, bi.token_expires, bi.delivery_method, bi.created_at,
           b.name AS business_name, b.city,
           bp.id AS prospect_id,
           TIMESTAMPDIFF(HOUR, NOW(), bi.token_expires) AS hours_left
    FROM business_invitations bi
    JOIN businesses b ON b.id = bi.business_id
    JOIN business_prospects bp ON bp.id = bi.prospect_id
    WHERE bi.partner_id = ?
      AND bi.status IN ('pending','sent','delivered')
      AND bi.token_expires > NOW()
      AND bi.token_expires <= DATE_ADD(NOW(), INTERVAL 48 HOUR)
    ORDER BY bi.token_expires ASC
");
$expiringInvitations->execute([$partnerId]);
$expiringRows = $expiringInvitations->fetchAll(PDO::FETCH_ASSOC);

// Rule 3: Authorisation requests pending > 5 days
$stalePending = $pdo->prepare("
    SELECT boa.id, boa.requested_at, boa.requested_services,
           b.name AS business_name, b.city,
           bp.id AS prospect_id,
           DATEDIFF(NOW(), boa.requested_at) AS days_waiting
    FROM business_owner_authorisations boa
    JOIN businesses b ON b.id = boa.business_id
    JOIN business_prospects bp ON bp.business_id = boa.business_id AND bp.partner_id = boa.partner_id
    WHERE boa.partner_id = ?
      AND boa.status = 'pending'
      AND DATEDIFF(NOW(), boa.requested_at) >= 5
    ORDER BY days_waiting DESC
    LIMIT 10
");
$stalePending->execute([$partnerId]);
$stalePendingRows = $stalePending->fetchAll(PDO::FETCH_ASSOC);

// Rule 4: High-priority prospects stuck in initial_contact for 3+ days
$stuckProspects = $pdo->prepare("
    SELECT bp.id, bp.status, bp.priority, bp.created_at,
           b.name AS business_name, b.city,
           DATEDIFF(NOW(), bp.created_at) AS days_in_pipeline
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    WHERE bp.partner_id = ?
      AND bp.status = 'initial_contact'
      AND bp.priority IN ('high','urgent')
      AND DATEDIFF(NOW(), bp.created_at) >= 3
    ORDER BY bp.priority DESC, days_in_pipeline DESC
    LIMIT 10
");
$stuckProspects->execute([$partnerId]);
$stuckRows = $stuckProspects->fetchAll(PDO::FETCH_ASSOC);

// Rule 5: Prospects in awaiting_assignment > 3 days (admin may need a nudge)
$awaitingAssignment = $pdo->prepare("
    SELECT bp.id, bp.status, bp.updated_at,
           b.name AS business_name, b.city,
           DATEDIFF(NOW(), bp.updated_at) AS days_waiting
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    WHERE bp.partner_id = ?
      AND bp.status = 'awaiting_assignment'
      AND DATEDIFF(NOW(), bp.updated_at) >= 3
    ORDER BY days_waiting DESC
    LIMIT 10
");
$awaitingAssignment->execute([$partnerId]);
$awaitingRows = $awaitingAssignment->fetchAll(PDO::FETCH_ASSOC);

// ── SUMMARY COUNTS ───────────────────────────────────────────────────────────
$totalInsights = count($overdueRows) + count($expiringRows) + count($stalePendingRows)
               + count($stuckRows) + count($awaitingRows);

$urgentCount = count($expiringRows)   // expiring invitations are urgent
             + array_reduce($overdueRows, fn($c, $r) => $c + ($r['days_since_contact'] >= 14 ? 1 : 0), 0);

// ── PIPELINE QUICK STATS ─────────────────────────────────────────────────────
$stats = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM business_prospects
    WHERE partner_id = ? AND status NOT IN ('lost')
    GROUP BY status
");
$stats->execute([$partnerId]);
$pipeline = [];
foreach ($stats->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pipeline[$row['status']] = (int)$row['cnt'];
}
$totalActive = array_sum($pipeline);

$pageTitle = 'Acquisition Insights';
require_once '../includes/partner-header.php';
?>

<div class="container-fluid px-4 py-4">

  <!-- PAGE HEADER -->
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <h1 class="h3 mb-0">Acquisition Insights</h1>
        <span class="badge text-bg-primary rounded-pill" style="background:linear-gradient(135deg,#667eea,#764ba2)!important;">✦ Smart</span>
      </div>
      <p class="text-muted mb-0">Rule-based intelligence across your acquisition pipeline</p>
    </div>
    <a href="acquisition-dashboard.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-grid-1x2 me-1"></i> Dashboard
    </a>
  </div>

  <!-- STAT STRIP -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase">Total Insights</div>
          <div class="fs-3 fw-bold <?= $totalInsights > 0 ? 'text-warning' : 'text-success' ?>">
            <?= $totalInsights ?>
          </div>
          <div class="text-muted small">need attention</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase">Urgent</div>
          <div class="fs-3 fw-bold text-danger"><?= $urgentCount ?></div>
          <div class="text-muted small">act today</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase">Pipeline</div>
          <div class="fs-3 fw-bold"><?= $totalActive ?></div>
          <div class="text-muted small">active prospects</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase">Converted</div>
          <div class="fs-3 fw-bold text-success"><?= $pipeline['converted'] ?? 0 ?></div>
          <div class="text-muted small">all time</div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($totalInsights === 0): ?>
  <!-- ALL CLEAR -->
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
      <div class="fs-1 mb-3">🎉</div>
      <h5 class="mb-2">Pipeline looks healthy!</h5>
      <p class="text-muted mb-3">No overdue follow-ups, expiring invitations, or stagnant prospects right now.</p>
      <a href="my-prospects.php" class="btn btn-primary">View All Prospects</a>
    </div>
  </div>

  <?php else: ?>

  <!-- ── SECTION 1: EXPIRING INVITATIONS ──────────────────────── -->
  <?php if (!empty($expiringRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-danger border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-danger rounded-pill">Urgent</span>
      <h6 class="mb-0">⏳ Invitations Expiring Soon</h6>
      <span class="badge bg-danger ms-auto"><?= count($expiringRows) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Business</th>
              <th>Delivery</th>
              <th>Expires In</th>
              <th>Sent</th>
              <th class="pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($expiringRows as $row): ?>
            <tr>
              <td class="ps-3">
                <div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div>
                <?php if ($row['city']): ?>
                  <div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge text-bg-secondary">
                  <?= ucfirst(str_replace('_', ' ', $row['delivery_method'])) ?>
                </span>
              </td>
              <td>
                <span class="text-danger fw-semibold">
                  <?php
                  $h = (int)$row['hours_left'];
                  echo $h < 1 ? '< 1 hour' : ($h < 24 ? $h . ' hrs' : ceil($h/24) . ' days');
                  ?>
                </span>
              </td>
              <td class="text-muted small"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
              <td class="pe-3">
                <a href="prospect-detail.php?id=<?= $row['prospect_id'] ?>" class="btn btn-sm btn-outline-danger">
                  View Prospect →
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">
      💡 If the owner hasn't responded, consider following up directly or asking admin to resend after expiry.
    </div>
  </div>
  <?php endif; ?>

  <!-- ── SECTION 2: OVERDUE FOLLOW-UPS ────────────────────────── -->
  <?php if (!empty($overdueRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-warning border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-warning text-dark rounded-pill">Follow Up</span>
      <h6 class="mb-0">📞 Overdue Contact — No Touch in 7+ Days</h6>
      <span class="badge bg-warning text-dark ms-auto"><?= count($overdueRows) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Business</th>
              <th>Status</th>
              <th>Priority</th>
              <th>Last Contact</th>
              <th>Overdue</th>
              <th class="pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($overdueRows as $row):
            $days = (int)$row['days_since_contact'];
            $badgeClass = $days >= 14 ? 'bg-danger' : 'bg-warning text-dark';
          ?>
            <tr>
              <td class="ps-3">
                <div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div>
                <?php if ($row['city']): ?>
                  <div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge text-bg-secondary">
                  <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                </span>
              </td>
              <td>
                <?php
                $pColors = ['urgent'=>'danger','high'=>'warning text-dark','medium'=>'primary','low'=>'secondary'];
                $pLabel  = ucfirst($row['priority'] ?? 'medium');
                $pc      = $pColors[$row['priority'] ?? 'medium'] ?? 'secondary';
                ?>
                <span class="badge bg-<?= $pc ?>"><?= $pLabel ?></span>
              </td>
              <td class="text-muted small"><?= date('d M Y', strtotime($row['last_contact'])) ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= $days ?> days</span></td>
              <td class="pe-3">
                <a href="prospect-detail.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-warning">
                  Record Contact →
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">
      💡 Regular contact keeps prospects warm. Aim for at least one touch every 5–7 days on active prospects.
    </div>
  </div>
  <?php endif; ?>

  <!-- ── SECTION 3: HIGH-PRIORITY STUCK IN INITIAL CONTACT ────── -->
  <?php if (!empty($stuckRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-primary border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill">Move Forward</span>
      <h6 class="mb-0">🔥 High-Priority Prospects — Stuck at Initial Contact</h6>
      <span class="badge bg-primary ms-auto"><?= count($stuckRows) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Business</th>
              <th>Priority</th>
              <th>Days in Pipeline</th>
              <th class="pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($stuckRows as $row): ?>
            <tr>
              <td class="ps-3">
                <div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div>
                <?php if ($row['city']): ?>
                  <div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php
                $pColors = ['urgent'=>'danger','high'=>'warning text-dark','medium'=>'primary','low'=>'secondary'];
                $pc = $pColors[$row['priority'] ?? 'medium'] ?? 'secondary';
                ?>
                <span class="badge bg-<?= $pc ?>"><?= ucfirst($row['priority']) ?></span>
              </td>
              <td>
                <span class="badge text-bg-secondary"><?= (int)$row['days_in_pipeline'] ?> days</span>
              </td>
              <td class="pe-3">
                <a href="prospect-detail.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                  Update Status →
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">
      💡 These high-value prospects need a next step — log a contact, send an invitation, or update the status.
    </div>
  </div>
  <?php endif; ?>

  <!-- ── SECTION 4: STALE PENDING AUTHORISATIONS ──────────────── -->
  <?php if (!empty($stalePendingRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-info border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-info text-dark rounded-pill">Waiting</span>
      <h6 class="mb-0">🔐 Authorisation Requests — No Owner Response in 5+ Days</h6>
      <span class="badge bg-info text-dark ms-auto"><?= count($stalePendingRows) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Business</th>
              <th>Services Requested</th>
              <th>Sent</th>
              <th>Waiting</th>
              <th class="pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($stalePendingRows as $row):
            $services = json_decode($row['requested_services'] ?? '[]', true) ?: [];
          ?>
            <tr>
              <td class="ps-3">
                <div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div>
                <?php if ($row['city']): ?>
                  <div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small text-muted">
                <?= htmlspecialchars(implode(', ', array_map(fn($s) => ucwords(str_replace('_',' ',$s)), $services))) ?>
              </td>
              <td class="text-muted small"><?= date('d M Y', strtotime($row['requested_at'])) ?></td>
              <td><span class="badge text-bg-warning"><?= (int)$row['days_waiting'] ?> days</span></td>
              <td class="pe-3">
                <a href="prospect-detail.php?id=<?= $row['prospect_id'] ?>" class="btn btn-sm btn-outline-info">
                  View →
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">
      💡 If you've spoken with the owner, you can gently remind them to check their email for the approval link.
    </div>
  </div>
  <?php endif; ?>

  <!-- ── SECTION 5: AWAITING ADMIN ASSIGNMENT ─────────────────── -->
  <?php if (!empty($awaitingRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-success border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-success rounded-pill">Almost There</span>
      <h6 class="mb-0">✅ Awaiting Admin Assignment — Owner Has Approved</h6>
      <span class="badge bg-success ms-auto"><?= count($awaitingRows) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Business</th>
              <th>Owner Approved On</th>
              <th>Waiting</th>
              <th class="pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($awaitingRows as $row): ?>
            <tr>
              <td class="ps-3">
                <div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div>
                <?php if ($row['city']): ?>
                  <div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-muted small"><?= date('d M Y', strtotime($row['updated_at'])) ?></td>
              <td><span class="badge text-bg-secondary"><?= (int)$row['days_waiting'] ?> days</span></td>
              <td class="pe-3">
                <a href="prospect-detail.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-success">
                  View →
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">
      💡 These are ready to convert. Contact your admin if the assignment is taking longer than expected.
    </div>
  </div>
  <?php endif; ?>

  <?php endif; // end $totalInsights > 0 ?>

  <!-- PIPELINE OVERVIEW -->
  <div class="card border-0 shadow-sm mt-2">
    <div class="card-header bg-white py-3">
      <h6 class="mb-0">Pipeline Overview</h6>
    </div>
    <div class="card-body">
      <?php
      $stageLabels = [
          'initial_contact'      => 'Initial Contact',
          'interested'           => 'Interested',
          'invitation_sent'      => 'Invitation Sent',
          'awaiting_authorisation' => 'Awaiting Authorisation',
          'awaiting_assignment'  => 'Awaiting Assignment',
          'converted'            => 'Converted',
          'on_hold'              => 'On Hold',
      ];
      $stageColors = [
          'initial_contact'        => '#6c757d',
          'interested'             => '#0d6efd',
          'invitation_sent'        => '#fd7e14',
          'awaiting_authorisation' => '#ffc107',
          'awaiting_assignment'    => '#20c997',
          'converted'              => '#198754',
          'on_hold'                => '#adb5bd',
      ];
      ?>
      <div class="d-flex flex-wrap gap-3">
        <?php foreach ($stageLabels as $key => $label):
          $count = $pipeline[$key] ?? 0;
          $color = $stageColors[$key];
        ?>
        <div class="text-center" style="min-width:90px;">
          <div class="rounded-3 py-2 px-3 mb-1 fw-bold fs-5" style="background:<?= $color ?>20;color:<?= $color ?>">
            <?= $count ?>
          </div>
          <div class="text-muted" style="font-size:.72rem;"><?= $label ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-footer bg-white py-2 px-3">
      <a href="my-prospects.php" class="btn btn-sm btn-outline-primary me-2">View All Prospects</a>
      <a href="acquisition-report.php" class="btn btn-sm btn-outline-secondary">Full Report →</a>
    </div>
  </div>

</div>

<?php require_once '../includes/partner-footer.php'; ?>
