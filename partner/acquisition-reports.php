<?php
/**
 * Acquisition Reports — Insights + Performance Report in one tabbed page
 * Replaces acquisition-insights.php + acquisition-report.php
 */

require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$pdo            = db();
$pid            = $partnerId;

$activeTab = $_GET['tab'] ?? 'insights';
if (!in_array($activeTab, ['insights', 'performance'])) $activeTab = 'insights';

// ── DATE RANGE (performance tab) ──────────────────────────────────────────────
$validRanges = ['30'=>'Last 30 days','90'=>'Last 90 days','180'=>'Last 6 months','365'=>'Last year','all'=>'All time'];
$rangeKey    = $_GET['range'] ?? '90';
if (!isset($validRanges[$rangeKey])) $rangeKey = '90';
$dateFrom    = $rangeKey === 'all' ? '2020-01-01' : date('Y-m-d', strtotime('-'.$rangeKey.' days'));

// ══════════════════════════════════════════════════════════════════════════════
// INSIGHTS DATA
// ══════════════════════════════════════════════════════════════════════════════

// Rule 1: Overdue contact (7+ days no touch)
$stOverdue = $pdo->prepare("
    SELECT bp.id, bp.status, bp.created_at,
           b.name AS business_name, b.city,
           COALESCE(MAX(bpc.contacted_at), bp.created_at) AS last_contact,
           DATEDIFF(NOW(), COALESCE(MAX(bpc.contacted_at), bp.created_at)) AS days_since_contact
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    LEFT JOIN business_prospect_contacts bpc ON bpc.prospect_id = bp.id
    WHERE bp.partner_id = ?
      AND bp.status NOT IN ('converted','closed','rejected','not_interested','unreachable')
    GROUP BY bp.id
    HAVING days_since_contact >= 7
    ORDER BY days_since_contact DESC
    LIMIT 20
");
$stOverdue->execute([$pid]);
$overdueRows = $stOverdue->fetchAll(PDO::FETCH_ASSOC);

// Rule 2: Invitations expiring within 48 hours
$stExpiring = $pdo->prepare("
    SELECT bi.id, bi.token_expires, bi.delivery_method, bi.created_at,
           b.name AS business_name, b.city, bp.id AS prospect_id,
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
$stExpiring->execute([$pid]);
$expiringRows = $stExpiring->fetchAll(PDO::FETCH_ASSOC);

// Rule 3: Authorisation requests pending > 5 days
$stStale = $pdo->prepare("
    SELECT boa.id, boa.requested_at, boa.requested_services,
           b.name AS business_name, b.city, bp.id AS prospect_id,
           DATEDIFF(NOW(), boa.requested_at) AS days_waiting
    FROM business_owner_authorisations boa
    JOIN businesses b ON b.id = boa.business_id
    JOIN business_prospects bp ON bp.business_id = boa.business_id AND bp.partner_id = boa.partner_id
    WHERE boa.partner_id = ? AND boa.status = 'pending'
      AND DATEDIFF(NOW(), boa.requested_at) >= 5
    ORDER BY days_waiting DESC LIMIT 10
");
$stStale->execute([$pid]);
$stalePendingRows = $stStale->fetchAll(PDO::FETCH_ASSOC);

// Rule 4: New prospects stuck for 3+ days
$stStuck = $pdo->prepare("
    SELECT bp.id, bp.status, bp.created_at,
           b.name AS business_name, b.city,
           DATEDIFF(NOW(), bp.created_at) AS days_in_pipeline
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    WHERE bp.partner_id = ? AND bp.status = 'new'
      AND DATEDIFF(NOW(), bp.created_at) >= 3
    ORDER BY days_in_pipeline DESC LIMIT 10
");
$stStuck->execute([$pid]);
$stuckRows = $stStuck->fetchAll(PDO::FETCH_ASSOC);

// Rule 5: Awaiting admin assignment > 3 days
$stAwaiting = $pdo->prepare("
    SELECT bp.id, bp.status, bp.updated_at,
           b.name AS business_name, b.city,
           DATEDIFF(NOW(), bp.updated_at) AS days_waiting
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    WHERE bp.partner_id = ? AND bp.status = 'awaiting_assignment'
      AND DATEDIFF(NOW(), bp.updated_at) >= 3
    ORDER BY days_waiting DESC LIMIT 10
");
$stAwaiting->execute([$pid]);
$awaitingRows = $stAwaiting->fetchAll(PDO::FETCH_ASSOC);

$totalInsights = count($overdueRows) + count($expiringRows) + count($stalePendingRows) + count($stuckRows) + count($awaitingRows);
$urgentCount   = count($expiringRows) + array_reduce($overdueRows, fn($c, $r) => $c + ($r['days_since_contact'] >= 14 ? 1 : 0), 0);

// Pipeline quick counts for insights header
$stPipeline = $pdo->prepare("SELECT status, COUNT(*) cnt FROM business_prospects WHERE partner_id=? AND status NOT IN('closed','rejected','not_interested','unreachable') GROUP BY status");
$stPipeline->execute([$pid]);
$pipeline = [];
foreach ($stPipeline->fetchAll(PDO::FETCH_ASSOC) as $r) $pipeline[$r['status']] = (int)$r['cnt'];
$totalActive = array_sum($pipeline);

// ══════════════════════════════════════════════════════════════════════════════
// PERFORMANCE DATA
// ══════════════════════════════════════════════════════════════════════════════

$stTotal = $pdo->prepare("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND created_at >= ?");
$stTotal->execute([$pid, $dateFrom]);
$totalAdded = (int)$stTotal->fetchColumn();

$stFunnel = $pdo->prepare("SELECT status, COUNT(*) cnt FROM business_prospects WHERE partner_id=? GROUP BY status");
$stFunnel->execute([$pid]);
$funnel = [];
foreach ($stFunnel->fetchAll(PDO::FETCH_ASSOC) as $r) $funnel[$r['status']] = (int)$r['cnt'];

$totalConverted = $funnel['converted'] ?? 0;
$totalLost      = ($funnel['not_interested'] ?? 0) + ($funnel['rejected'] ?? 0) + ($funnel['unreachable'] ?? 0) + ($funnel['closed'] ?? 0);
$totalActivePipe = array_sum($funnel) - $totalLost - $totalConverted;
$closed   = $totalConverted + $totalLost;
$convRate = $closed > 0 ? round(($totalConverted / $closed) * 100, 1) : 0;

$stInv = $pdo->prepare("SELECT COUNT(*) FROM business_invitations WHERE partner_id=? AND created_at >= ?");
$stInv->execute([$pid, $dateFrom]);
$invitationsSent = (int)$stInv->fetchColumn();

$stClaimed = $pdo->prepare("SELECT COUNT(*) FROM business_invitations WHERE partner_id=? AND status='accepted' AND updated_at >= ?");
$stClaimed->execute([$pid, $dateFrom]);
$invitationsClaimed = (int)$stClaimed->fetchColumn();

$stAuth = $pdo->prepare("SELECT COUNT(*) FROM business_owner_authorisations WHERE partner_id=? AND requested_at >= ?");
$stAuth->execute([$pid, $dateFrom]);
$authRequested = (int)$stAuth->fetchColumn();

$stAuthApproved = $pdo->prepare("SELECT COUNT(*) FROM business_owner_authorisations WHERE partner_id=? AND status='approved' AND updated_at >= ?");
$stAuthApproved->execute([$pid, $dateFrom]);
$authApproved = (int)$stAuthApproved->fetchColumn();

$stContacts = $pdo->prepare("SELECT COUNT(*) FROM business_prospect_contacts WHERE partner_id=? AND contacted_at >= ?");
$stContacts->execute([$pid, $dateFrom]);
$contactsLogged = (int)$stContacts->fetchColumn();

$stAssign = $pdo->prepare("SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=? AND status='active'");
$stAssign->execute([$pid]);
$activeAssignments = (int)$stAssign->fetchColumn();

// Contact methods
$stMethods = $pdo->prepare("SELECT contact_method, COUNT(*) cnt FROM business_prospect_contacts WHERE partner_id=? AND contacted_at >= ? GROUP BY contact_method ORDER BY cnt DESC");
$stMethods->execute([$pid, $dateFrom]);
$contactMethods = $stMethods->fetchAll(PDO::FETCH_ASSOC);

// Conversion trend (last 6 months)
$stTrend = $pdo->prepare("SELECT DATE_FORMAT(updated_at,'%Y-%m') AS ym, COUNT(*) cnt FROM business_prospects WHERE partner_id=? AND status='converted' AND updated_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY ym ORDER BY ym ASC");
$stTrend->execute([$pid]);
$trendData = [];
for ($i = 5; $i >= 0; $i--) $trendData[date('Y-m', strtotime("-$i months"))] = 0;
foreach ($stTrend->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($trendData[$r['ym']])) $trendData[$r['ym']] = (int)$r['cnt'];

// Recent activity
$stLog = $pdo->prepare("SELECT aal.event_type, aal.created_at, b.name AS business_name FROM acquisition_activity_logs aal LEFT JOIN businesses b ON b.id = aal.business_id WHERE aal.partner_id=? AND aal.created_at >= ? ORDER BY aal.created_at DESC LIMIT 30");
$stLog->execute([$pid, $dateFrom]);
$recentActivity = $stLog->fetchAll(PDO::FETCH_ASSOC);

// Top prospects
$stTop = $pdo->prepare("SELECT bp.id, bp.status, b.name AS business_name, b.city, COUNT(bpc.id) AS contact_count, MAX(bpc.contacted_at) AS last_contact FROM business_prospects bp JOIN businesses b ON b.id = bp.business_id LEFT JOIN business_prospect_contacts bpc ON bpc.prospect_id = bp.id AND bpc.contacted_at >= ? WHERE bp.partner_id=? GROUP BY bp.id HAVING contact_count > 0 ORDER BY contact_count DESC LIMIT 5");
$stTop->execute([$dateFrom, $pid]);
$topProspects = $stTop->fetchAll(PDO::FETCH_ASSOC);

$eventLabels = ['prospect_added'=>'Prospect Added','status_updated'=>'Status Updated','contact_logged'=>'Contact Logged','invitation_sent'=>'Invitation Sent','invitation_accepted'=>'Listing Claimed','authorisation_requested'=>'Auth Requested','authorisation_approved'=>'Auth Approved','authorisation_declined'=>'Auth Declined','assignment_activated'=>'Assignment Activated'];
$eventIcons  = ['prospect_added'=>'🏢','status_updated'=>'🔄','contact_logged'=>'📞','invitation_sent'=>'✉️','invitation_accepted'=>'✅','authorisation_requested'=>'🔐','authorisation_approved'=>'🎉','authorisation_declined'=>'❌','assignment_activated'=>'🚀'];

$pageTitle = 'Reports';
require_once '../includes/partner-header.php';
?>

<div class="container-fluid px-4 py-4">

  <!-- Header + tab switcher -->
  <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-0">Reports</h1>
      <p class="text-muted mb-0">Pipeline intelligence &amp; performance analysis</p>
    </div>
    <?php if ($activeTab === 'performance'): ?>
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="tab" value="performance">
      <select name="range" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:140px">
        <?php foreach ($validRanges as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $k===$rangeKey?'selected':'' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <!-- Tab switcher -->
  <div class="d-flex gap-0 mb-4" style="border-bottom:2px solid #dee2e6;">
    <a href="?tab=insights"
       class="text-decoration-none px-4 py-2 fw-semibold small"
       style="border-bottom:3px solid <?= $activeTab==='insights' ? '#0d6efd' : 'transparent' ?>;
              color:<?= $activeTab==='insights' ? '#0d6efd' : '#6c757d' ?>;
              margin-bottom:-2px;">
      <i class="bi bi-lightbulb me-1"></i>Insights
      <?php if ($totalInsights > 0): ?>
        <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem"><?= $totalInsights ?></span>
      <?php endif; ?>
    </a>
    <a href="?tab=performance&range=<?= $rangeKey ?>"
       class="text-decoration-none px-4 py-2 fw-semibold small"
       style="border-bottom:3px solid <?= $activeTab==='performance' ? '#0d6efd' : 'transparent' ?>;
              color:<?= $activeTab==='performance' ? '#0d6efd' : '#6c757d' ?>;
              margin-bottom:-2px;">
      <i class="bi bi-graph-up-arrow me-1"></i>Performance
    </a>
  </div>

  <?php if ($activeTab === 'insights'): ?>
  <!-- ══════════════════════════════════════════════════════════════
       INSIGHTS TAB
  ══════════════════════════════════════════════════════════════ -->

  <!-- Stat strip -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase">Needs Attention</div>
          <div class="fs-3 fw-bold <?= $totalInsights > 0 ? 'text-warning' : 'text-success' ?>"><?= $totalInsights ?></div>
          <div class="text-muted small">total insights</div>
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
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
      <div class="fs-1 mb-3">🎉</div>
      <h5 class="mb-2">Pipeline looks healthy!</h5>
      <p class="text-muted mb-3">No overdue follow-ups, expiring invitations, or stagnant prospects right now.</p>
      <a href="acquisition-dashboard.php" class="btn btn-primary">Back to Pipeline</a>
    </div>
  </div>
  <?php else: ?>

  <?php if (!empty($expiringRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-danger border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-danger rounded-pill">Urgent</span>
      <h6 class="mb-0">⏳ Invitations Expiring Soon</h6>
      <span class="badge bg-danger ms-auto"><?= count($expiringRows) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th class="ps-3">Business</th><th>Delivery</th><th>Expires In</th><th>Sent</th><th class="pe-3">Action</th></tr></thead>
        <tbody>
        <?php foreach ($expiringRows as $row): ?>
          <tr>
            <td class="ps-3"><div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div><?php if ($row['city']): ?><div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div><?php endif; ?></td>
            <td><span class="badge text-bg-secondary"><?= ucfirst(str_replace('_',' ',$row['delivery_method'])) ?></span></td>
            <td><span class="text-danger fw-semibold"><?php $h=(int)$row['hours_left']; echo $h<1?'< 1 hour':($h<24?$h.' hrs':ceil($h/24).' days'); ?></span></td>
            <td class="text-muted small"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
            <td class="pe-3"><a href="prospect-detail.php?id=<?= $row['prospect_id'] ?>" class="btn btn-sm btn-outline-danger">View →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">💡 If the owner hasn't responded, consider following up directly or asking admin to resend after expiry.</div>
  </div>
  <?php endif; ?>

  <?php if (!empty($overdueRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-warning border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-warning text-dark rounded-pill">Follow Up</span>
      <h6 class="mb-0">📞 No Contact in 7+ Days</h6>
      <span class="badge bg-warning text-dark ms-auto"><?= count($overdueRows) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th class="ps-3">Business</th><th>Status</th><th>Last Contact</th><th>Overdue</th><th class="pe-3">Action</th></tr></thead>
        <tbody>
        <?php foreach ($overdueRows as $row):
          $days=(int)$row['days_since_contact'];
          $bc=$days>=14?'bg-danger':'bg-warning text-dark';
        ?>
          <tr>
            <td class="ps-3"><div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div><?php if ($row['city']): ?><div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div><?php endif; ?></td>
            <td><span class="badge text-bg-secondary"><?= ucfirst(str_replace('_',' ',$row['status'])) ?></span></td>
            <td class="text-muted small"><?= date('d M Y', strtotime($row['last_contact'])) ?></td>
            <td><span class="badge <?= $bc ?>"><?= $days ?> days</span></td>
            <td class="pe-3"><a href="prospect-detail.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-warning">Record Contact →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">💡 Aim for at least one touch every 5–7 days on active prospects.</div>
  </div>
  <?php endif; ?>

  <?php if (!empty($stuckRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-primary border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill">Move Forward</span>
      <h6 class="mb-0">🔥 New Prospects — No Action for 3+ Days</h6>
      <span class="badge bg-primary ms-auto"><?= count($stuckRows) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th class="ps-3">Business</th><th>Days in Pipeline</th><th class="pe-3">Action</th></tr></thead>
        <tbody>
        <?php foreach ($stuckRows as $row): ?>
          <tr>
            <td class="ps-3"><div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div><?php if ($row['city']): ?><div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div><?php endif; ?></td>
            <td><span class="badge text-bg-secondary"><?= (int)$row['days_in_pipeline'] ?> days</span></td>
            <td class="pe-3"><a href="prospect-detail.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">Update Status →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">💡 Log a contact, send an invitation, or update the status to keep these moving.</div>
  </div>
  <?php endif; ?>

  <?php if (!empty($stalePendingRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-info border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-info text-dark rounded-pill">Waiting</span>
      <h6 class="mb-0">🔐 Authorisation Requests — No Owner Response in 5+ Days</h6>
      <span class="badge bg-info text-dark ms-auto"><?= count($stalePendingRows) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th class="ps-3">Business</th><th>Services Requested</th><th>Sent</th><th>Waiting</th><th class="pe-3">Action</th></tr></thead>
        <tbody>
        <?php foreach ($stalePendingRows as $row):
          $svcs=json_decode($row['requested_services']??'[]',true)?:[];
        ?>
          <tr>
            <td class="ps-3"><div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div><?php if ($row['city']): ?><div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div><?php endif; ?></td>
            <td class="small text-muted"><?= htmlspecialchars(implode(', ', array_map(fn($s)=>ucwords(str_replace('_',' ',$s)),$svcs))) ?></td>
            <td class="text-muted small"><?= date('d M Y', strtotime($row['requested_at'])) ?></td>
            <td><span class="badge text-bg-warning"><?= (int)$row['days_waiting'] ?> days</span></td>
            <td class="pe-3"><a href="prospect-detail.php?id=<?= $row['prospect_id'] ?>" class="btn btn-sm btn-outline-info">View →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">💡 Remind the owner to check their email for the approval link.</div>
  </div>
  <?php endif; ?>

  <?php if (!empty($awaitingRows)): ?>
  <div class="card border-0 shadow-sm mb-4 border-start border-success border-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
      <span class="badge bg-success rounded-pill">Almost There</span>
      <h6 class="mb-0">✅ Awaiting Admin Assignment — Owner Has Approved</h6>
      <span class="badge bg-success ms-auto"><?= count($awaitingRows) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th class="ps-3">Business</th><th>Owner Approved On</th><th>Waiting</th><th class="pe-3">Action</th></tr></thead>
        <tbody>
        <?php foreach ($awaitingRows as $row): ?>
          <tr>
            <td class="ps-3"><div class="fw-semibold"><?= htmlspecialchars($row['business_name']) ?></div><?php if ($row['city']): ?><div class="text-muted small"><?= htmlspecialchars($row['city']) ?></div><?php endif; ?></td>
            <td class="text-muted small"><?= date('d M Y', strtotime($row['updated_at'])) ?></td>
            <td><span class="badge text-bg-secondary"><?= (int)$row['days_waiting'] ?> days</span></td>
            <td class="pe-3"><a href="prospect-detail.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-success">View →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-white text-muted small py-2 px-3">💡 Contact your admin if the assignment is taking longer than expected.</div>
  </div>
  <?php endif; ?>

  <?php endif; // totalInsights > 0 ?>

  <?php else: ?>
  <!-- ══════════════════════════════════════════════════════════════
       PERFORMANCE TAB
  ══════════════════════════════════════════════════════════════ -->

  <!-- KPI strip -->
  <div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['Prospects Added',  $totalAdded,       '',            $validRanges[$rangeKey]],
        ['Active',           $totalActivePipe,  'text-primary', 'in pipeline'],
        ['Converted',        $totalConverted,   'text-success', 'all time'],
        ['Conv. Rate',       $convRate.'%',     $convRate>=50?'text-success':($convRate>=25?'text-warning':'text-muted'), 'of closed'],
        ['Contacts Logged',  $contactsLogged,   '',            $validRanges[$rangeKey]],
        ['Active Mgmt',      $activeAssignments,'text-success', 'assignments'],
    ];
    foreach ($kpis as [$label, $val, $color, $sub]):
    ?>
    <div class="col-6 col-md-3 col-lg-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase mb-1"><?= $label ?></div>
          <div class="fs-3 fw-bold <?= $color ?>"><?= $val ?></div>
          <div class="text-muted small"><?= $sub ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-4">
    <!-- Left column -->
    <div class="col-12 col-lg-7">

      <!-- Funnel -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3"><h6 class="mb-0">Acquisition Funnel (All Time)</h6></div>
        <div class="card-body p-0">
          <table class="table mb-0">
            <thead class="table-light"><tr><th class="ps-3">Stage</th><th class="text-end">Count</th><th class="pe-3">Progress</th></tr></thead>
            <tbody>
            <?php
            $funnelStages = ['new'=>['New','secondary'],'contacted'=>['Contacted','info'],'follow_up_required'=>['Follow-up Required','warning'],'interested'=>['Interested','primary'],'invitation_sent'=>['Invitation Sent','warning'],'awaiting_owner_response'=>['Awaiting Owner','warning'],'awaiting_authorisation'=>['Awaiting Authorisation','info'],'awaiting_assignment'=>['Awaiting Assignment','info'],'converted'=>['Converted ✓','success'],'not_interested'=>['Not Interested','danger'],'rejected'=>['Rejected','danger'],'unreachable'=>['Unreachable','danger'],'closed'=>['Closed','secondary']];
            $maxCount = max(array_values($funnel) ?: [1]);
            foreach ($funnelStages as $key => [$label, $color]):
              $cnt = $funnel[$key] ?? 0;
              if ($cnt === 0 && in_array($key, ['not_interested','rejected','unreachable','closed'])) continue;
              $pct = $maxCount > 0 ? round(($cnt / $maxCount) * 100) : 0;
            ?>
            <tr>
              <td class="ps-3 fw-semibold small"><?= $label ?></td>
              <td class="text-end fw-bold"><?= $cnt ?></td>
              <td class="pe-3" style="width:50%"><div class="progress" style="height:8px;"><div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div></div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Trend chart -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3"><h6 class="mb-0">Conversions — Last 6 Months</h6></div>
        <div class="card-body">
          <?php $maxTrend = max(max(array_values($trendData) ?: [1]), 1); ?>
          <div class="d-flex align-items-end gap-2" style="height:120px;">
            <?php foreach ($trendData as $ym => $cnt):
              $hPct = round(($cnt/$maxTrend)*100);
              $mo = date('M', mktime(0,0,0,(int)substr($ym,5),1));
            ?>
            <div class="d-flex flex-column align-items-center flex-grow-1">
              <div class="text-muted small mb-1 fw-bold"><?= $cnt ?></div>
              <div class="rounded-top w-100 bg-success" style="height:<?= max($hPct,$cnt>0?8:2) ?>%;min-height:2px;opacity:<?= $cnt>0?'1':'.2' ?>"></div>
              <div class="text-muted small mt-1" style="font-size:.7rem"><?= $mo ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if (!array_sum($trendData)): ?><p class="text-muted small text-center mt-2 mb-0">No conversions yet.</p><?php endif; ?>
        </div>
      </div>

      <!-- Inv & auth funnel -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h6 class="mb-0">Invitation & Authorisation Funnel — <?= $validRanges[$rangeKey] ?></h6></div>
        <div class="card-body">
          <div class="row g-3 text-center">
            <div class="col-3"><div class="p-3 rounded-3 bg-light"><div class="fs-4 fw-bold"><?= $invitationsSent ?></div><div class="text-muted small">Invitations Sent</div></div></div>
            <div class="col-1 d-flex align-items-center justify-content-center text-muted">→</div>
            <div class="col-3"><div class="p-3 rounded-3" style="background:#e8f5ef"><div class="fs-4 fw-bold text-success"><?= $invitationsClaimed ?></div><div class="text-muted small">Listings Claimed</div></div></div>
            <div class="col-1 d-flex align-items-center justify-content-center text-muted">→</div>
            <div class="col-4"><div class="p-3 rounded-3" style="background:#e8f0ff"><div class="fs-4 fw-bold" style="color:#1a6b3c"><?= $authApproved ?> / <?= $authRequested ?></div><div class="text-muted small">Auth Approved / Requested</div></div></div>
          </div>
          <?php if ($invitationsSent > 0): $cr=round(($invitationsClaimed/$invitationsSent)*100,1); ?>
          <p class="text-muted small mt-3 mb-0 text-center">Claim rate: <strong><?= $cr ?>%</strong> of invitations resulted in a listing claim.</p>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- Right column -->
    <div class="col-12 col-lg-5">

      <?php if (!empty($contactMethods)): ?>
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3"><h6 class="mb-0">Contact Methods — <?= $validRanges[$rangeKey] ?></h6></div>
        <div class="card-body">
          <?php
          $totalCt = array_sum(array_column($contactMethods,'cnt'));
          $methodIcons=['phone'=>'📞','email'=>'✉️','whatsapp'=>'💬','in_person'=>'🤝','social_media'=>'📲','other'=>'📋'];
          foreach ($contactMethods as $m):
            $pct2=$totalCt>0?round(($m['cnt']/$totalCt)*100):0;
            $icon=$methodIcons[$m['contact_method']]??'📋';
            $lbl=ucwords(str_replace('_',' ',$m['contact_method']));
          ?>
          <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:24px;text-align:center;"><?= $icon ?></div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between small mb-1"><span class="fw-semibold"><?= $lbl ?></span><span class="text-muted"><?= $m['cnt'] ?> (<?= $pct2 ?>%)</span></div>
              <div class="progress" style="height:6px;"><div class="progress-bar bg-primary" style="width:<?= $pct2 ?>%"></div></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($topProspects)): ?>
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3"><h6 class="mb-0">Most Contacted — <?= $validRanges[$rangeKey] ?></h6></div>
        <ul class="list-group list-group-flush">
          <?php foreach ($topProspects as $p): ?>
          <li class="list-group-item d-flex align-items-center gap-3 py-3">
            <div class="flex-grow-1" style="min-width:0">
              <div class="fw-semibold small"><?= htmlspecialchars($p['business_name']) ?></div>
              <?php if ($p['city']): ?><div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($p['city']) ?></div><?php endif; ?>
            </div>
            <div class="text-end">
              <span class="badge text-bg-primary"><?= $p['contact_count'] ?> contacts</span>
              <div class="text-muted" style="font-size:.7rem;margin-top:2px"><?= ucfirst(str_replace('_',' ',$p['status'])) ?></div>
            </div>
            <a href="prospect-detail.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary">→</a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h6 class="mb-0">Recent Activity</h6></div>
        <?php if (empty($recentActivity)): ?>
        <div class="card-body text-center text-muted small py-4">No activity recorded in this period.</div>
        <?php else: ?>
        <ul class="list-group list-group-flush" style="max-height:360px;overflow-y:auto;">
          <?php foreach ($recentActivity as $log):
            $evLabel=$eventLabels[$log['event_type']]??ucwords(str_replace('_',' ',$log['event_type']));
            $evIcon=$eventIcons[$log['event_type']]??'📌';
          ?>
          <li class="list-group-item py-2 px-3">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <span style="font-size:.85rem"><?= $evIcon ?> <strong><?= $evLabel ?></strong></span>
                <?php if (!empty($log['business_name'])): ?><div class="text-muted small"><?= htmlspecialchars($log['business_name']) ?></div><?php endif; ?>
              </div>
              <div class="text-muted text-nowrap" style="font-size:.72rem"><?= date('d M', strtotime($log['created_at'])) ?></div>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <?php endif; // performance tab ?>

</div>

<?php require_once '../includes/partner-footer.php'; ?>
