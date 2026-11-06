<?php
/**
 * Acquisition Performance Report
 * Stage D §13
 * Funnel conversion stats, activity breakdown, timeline,
 * and commission events for the partner's acquisition pipeline.
 */

require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)($_SESSION['user_id'] ?? 0);

$pdo = db();

// ── DATE RANGE ───────────────────────────────────────────────────────────────
$validRanges = ['30' => 'Last 30 days', '90' => 'Last 90 days', '180' => 'Last 6 months', '365' => 'Last year', 'all' => 'All time'];
$rangeKey    = $_GET['range'] ?? '90';
if (!isset($validRanges[$rangeKey])) $rangeKey = '90';

if ($rangeKey === 'all') {
    $dateFrom = '2020-01-01';
} else {
    $dateFrom = date('Y-m-d', strtotime('-' . $rangeKey . ' days'));
}
$dateTo = date('Y-m-d');

// ── FUNNEL STATS ─────────────────────────────────────────────────────────────
// Total prospects added in period
$stTotal = $pdo->prepare("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND created_at >= ?");
$stTotal->execute([$partnerId, $dateFrom]);
$totalAdded = (int)$stTotal->fetchColumn();

// Funnel counts (all-time, not just period — shows current pipeline state)
$stFunnel = $pdo->prepare("SELECT status, COUNT(*) cnt FROM business_prospects WHERE partner_id=? GROUP BY status");
$stFunnel->execute([$partnerId]);
$funnel = [];
foreach ($stFunnel->fetchAll(PDO::FETCH_ASSOC) as $r) $funnel[$r['status']] = (int)$r['cnt'];

$totalConverted = $funnel['converted'] ?? 0;
$totalLost      = ($funnel['not_interested'] ?? 0) + ($funnel['rejected'] ?? 0) + ($funnel['unreachable'] ?? 0) + ($funnel['closed'] ?? 0);
$totalActive    = array_sum($funnel) - $totalLost - $totalConverted;

// Conversion rate (of all closed: converted vs lost)
$closed = $totalConverted + $totalLost;
$convRate = $closed > 0 ? round(($totalConverted / $closed) * 100, 1) : 0;

// Invitations sent in period
$stInv = $pdo->prepare("SELECT COUNT(*) FROM business_invitations WHERE partner_id=? AND created_at >= ?");
$stInv->execute([$partnerId, $dateFrom]);
$invitationsSent = (int)$stInv->fetchColumn();

// Invitations accepted (claimed) in period
$stClaimed = $pdo->prepare("SELECT COUNT(*) FROM business_invitations WHERE partner_id=? AND status='accepted' AND updated_at >= ?");
$stClaimed->execute([$partnerId, $dateFrom]);
$invitationsClaimed = (int)$stClaimed->fetchColumn();

// Authorisation requests submitted in period
$stAuth = $pdo->prepare("SELECT COUNT(*) FROM business_owner_authorisations WHERE partner_id=? AND requested_at >= ?");
$stAuth->execute([$partnerId, $dateFrom]);
$authRequested = (int)$stAuth->fetchColumn();

// Authorisations approved in period
$stAuthApproved = $pdo->prepare("SELECT COUNT(*) FROM business_owner_authorisations WHERE partner_id=? AND status='approved' AND updated_at >= ?");
$stAuthApproved->execute([$partnerId, $dateFrom]);
$authApproved = (int)$stAuthApproved->fetchColumn();

// Contacts logged in period
$stContacts = $pdo->prepare("SELECT COUNT(*) FROM business_prospect_contacts WHERE partner_id=? AND contacted_at >= ?");
$stContacts->execute([$partnerId, $dateFrom]);
$contactsLogged = (int)$stContacts->fetchColumn();

// Active assignments (all time)
$stAssign = $pdo->prepare("SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=? AND status='active'");
$stAssign->execute([$partnerId]);
$activeAssignments = (int)$stAssign->fetchColumn();

// ── CONTACT METHOD BREAKDOWN ─────────────────────────────────────────────────
$stMethods = $pdo->prepare("
    SELECT contact_method, COUNT(*) cnt
    FROM business_prospect_contacts
    WHERE partner_id=? AND contacted_at >= ?
    GROUP BY contact_method ORDER BY cnt DESC
");
$stMethods->execute([$partnerId, $dateFrom]);
$contactMethods = $stMethods->fetchAll(PDO::FETCH_ASSOC);

// ── MONTHLY CONVERSION TREND (last 6 months regardless of range filter) ──────
$trendRows = $pdo->prepare("
    SELECT
        DATE_FORMAT(updated_at, '%Y-%m') AS ym,
        COUNT(*) AS cnt
    FROM business_prospects
    WHERE partner_id=? AND status='converted'
      AND updated_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY ym
    ORDER BY ym ASC
");
$trendRows->execute([$partnerId]);
$trendData = [];
// Fill all 6 months
for ($i = 5; $i >= 0; $i--) {
    $k = date('Y-m', strtotime("-$i months"));
    $trendData[$k] = 0;
}
foreach ($trendRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($trendData[$r['ym']])) $trendData[$r['ym']] = (int)$r['cnt'];
}

// ── RECENT ACTIVITY LOG ──────────────────────────────────────────────────────
$stLog = $pdo->prepare("
    SELECT aal.event_type, aal.created_at, aal.new_value,
           b.name AS business_name
    FROM acquisition_activity_logs aal
    LEFT JOIN businesses b ON b.id = aal.business_id
    WHERE aal.partner_id=? AND aal.created_at >= ?
    ORDER BY aal.created_at DESC
    LIMIT 30
");
$stLog->execute([$partnerId, $dateFrom]);
$recentActivity = $stLog->fetchAll(PDO::FETCH_ASSOC);

// ── TOP PROSPECTS BY CONTACTS ────────────────────────────────────────────────
$stTop = $pdo->prepare("
    SELECT bp.id, bp.status, b.name AS business_name, b.city,
           COUNT(bpc.id) AS contact_count,
           MAX(bpc.contacted_at) AS last_contact
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    LEFT JOIN business_prospect_contacts bpc ON bpc.prospect_id = bp.id
      AND bpc.contacted_at >= ?
    WHERE bp.partner_id=?
    GROUP BY bp.id
    HAVING contact_count > 0
    ORDER BY contact_count DESC
    LIMIT 10
");
$stTop->execute([$dateFrom, $partnerId]);
$topProspects = $stTop->fetchAll(PDO::FETCH_ASSOC);

// ── PAGE ─────────────────────────────────────────────────────────────────────
$pageTitle = 'Acquisition Report';
require_once '../includes/partner-header.php';

$eventLabels = [
    'prospect_added'           => 'Prospect Added',
    'status_updated'           => 'Status Updated',
    'contact_logged'           => 'Contact Logged',
    'invitation_sent'          => 'Invitation Sent',
    'invitation_accepted'      => 'Listing Claimed',
    'authorisation_requested'  => 'Auth Requested',
    'authorisation_approved'   => 'Auth Approved',
    'authorisation_declined'   => 'Auth Declined',
    'assignment_activated'     => 'Assignment Activated',
];
$eventIcons = [
    'prospect_added'           => '🏢',
    'status_updated'           => '🔄',
    'contact_logged'           => '📞',
    'invitation_sent'          => '✉️',
    'invitation_accepted'      => '✅',
    'authorisation_requested'  => '🔐',
    'authorisation_approved'   => '🎉',
    'authorisation_declined'   => '❌',
    'assignment_activated'     => '🚀',
];
?>

<div class="container-fluid px-4 py-4">

  <!-- HEADER -->
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Acquisition Report</h1>
      <p class="text-muted mb-0">Pipeline performance &amp; conversion analysis</p>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <!-- Date range selector -->
      <form method="get" class="d-flex gap-2 align-items-center">
        <select name="range" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:140px">
          <?php foreach ($validRanges as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $k===$rangeKey?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <a href="acquisition-insights.php" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-lightbulb me-1"></i> Insights
      </a>
    </div>
  </div>

  <!-- KPI STRIP -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-lg-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase mb-1">Prospects Added</div>
          <div class="fs-3 fw-bold"><?= $totalAdded ?></div>
          <div class="text-muted small"><?= $validRanges[$rangeKey] ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3 col-lg-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase mb-1">Active</div>
          <div class="fs-3 fw-bold text-primary"><?= $totalActive ?></div>
          <div class="text-muted small">in pipeline</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3 col-lg-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase mb-1">Converted</div>
          <div class="fs-3 fw-bold text-success"><?= $totalConverted ?></div>
          <div class="text-muted small">all time</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3 col-lg-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase mb-1">Conv. Rate</div>
          <div class="fs-3 fw-bold <?= $convRate >= 50 ? 'text-success' : ($convRate >= 25 ? 'text-warning' : 'text-muted') ?>">
            <?= $convRate ?>%
          </div>
          <div class="text-muted small">of closed</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3 col-lg-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase mb-1">Contacts Logged</div>
          <div class="fs-3 fw-bold"><?= $contactsLogged ?></div>
          <div class="text-muted small"><?= $validRanges[$rangeKey] ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3 col-lg-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body py-3 px-3">
          <div class="text-muted small fw-semibold text-uppercase mb-1">Active Mgmt</div>
          <div class="fs-3 fw-bold text-success"><?= $activeAssignments ?></div>
          <div class="text-muted small">assignments</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">

    <!-- LEFT: Funnel + Trend -->
    <div class="col-12 col-lg-7">

      <!-- FUNNEL TABLE -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0">Acquisition Funnel (All Time)</h6>
        </div>
        <div class="card-body p-0">
          <table class="table mb-0">
            <thead class="table-light">
              <tr>
                <th class="ps-3">Stage</th>
                <th class="text-end">Count</th>
                <th class="pe-3">Progress</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $funnelStages = [
                'new'                    => ['New',                   'secondary'],
                'contacted'              => ['Contacted',              'info'],
                'follow_up_required'     => ['Follow-up Required',    'warning'],
                'interested'             => ['Interested',             'primary'],
                'invitation_sent'        => ['Invitation Sent',        'warning'],
                'awaiting_owner_response'=> ['Awaiting Owner',         'warning'],
                'awaiting_authorisation' => ['Awaiting Authorisation', 'info'],
                'awaiting_assignment'    => ['Awaiting Assignment',    'info'],
                'converted'              => ['Converted ✓',            'success'],
                'not_interested'         => ['Not Interested',         'danger'],
                'rejected'               => ['Rejected',               'danger'],
                'unreachable'            => ['Unreachable',            'danger'],
                'closed'                 => ['Closed',                 'secondary'],
            ];
            $maxCount = max(array_values($funnel) ?: [1]);
            foreach ($funnelStages as $key => [$label, $color]):
                $cnt = $funnel[$key] ?? 0;
                if ($cnt === 0 && in_array($key, ['not_interested','rejected','unreachable','closed'])) continue;
                $pct = $maxCount > 0 ? round(($cnt / $maxCount) * 100) : 0;
            ?>
            <tr>
              <td class="ps-3 fw-semibold small"><?= $label ?></td>
              <td class="text-end fw-bold"><?= $cnt ?></td>
              <td class="pe-3" style="width:50%">
                <div class="progress" style="height:8px;">
                  <div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- CONVERSION TREND (last 6 months) -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0">Conversions — Last 6 Months</h6>
        </div>
        <div class="card-body">
          <?php
          $maxTrend = max(array_values($trendData) ?: [1]);
          $maxTrend = max($maxTrend, 1);
          ?>
          <div class="d-flex align-items-end gap-2" style="height:120px;">
            <?php foreach ($trendData as $ym => $cnt):
              $heightPct = round(($cnt / $maxTrend) * 100);
              $monthLabel = date('M', mktime(0,0,0,(int)substr($ym,5),1));
            ?>
            <div class="d-flex flex-column align-items-center flex-grow-1">
              <div class="text-muted small mb-1 fw-bold"><?= $cnt ?></div>
              <div class="rounded-top w-100 bg-success" style="height:<?= max($heightPct, $cnt > 0 ? 8 : 2) ?>%;min-height:2px;opacity:<?= $cnt > 0 ? '1' : '.2' ?>"></div>
              <div class="text-muted small mt-1" style="font-size:.7rem"><?= $monthLabel ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if (array_sum($trendData) === 0): ?>
          <p class="text-muted small text-center mt-2 mb-0">No conversions in this period yet.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- INVITATION + AUTH FUNNEL -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0">Invitation & Authorisation Funnel — <?= $validRanges[$rangeKey] ?></h6>
        </div>
        <div class="card-body">
          <div class="row g-3 text-center">
            <div class="col-3">
              <div class="p-3 rounded-3 bg-light">
                <div class="fs-4 fw-bold"><?= $invitationsSent ?></div>
                <div class="text-muted small">Invitations Sent</div>
              </div>
            </div>
            <div class="col-1 d-flex align-items-center justify-content-center text-muted">→</div>
            <div class="col-3">
              <div class="p-3 rounded-3" style="background:#e8f5ef">
                <div class="fs-4 fw-bold text-success"><?= $invitationsClaimed ?></div>
                <div class="text-muted small">Listings Claimed</div>
              </div>
            </div>
            <div class="col-1 d-flex align-items-center justify-content-center text-muted">→</div>
            <div class="col-4">
              <div class="p-3 rounded-3" style="background:#e8f0ff">
                <div class="fs-4 fw-bold" style="color:#1a6b3c"><?= $authApproved ?> / <?= $authRequested ?></div>
                <div class="text-muted small">Auth Approved / Requested</div>
              </div>
            </div>
          </div>
          <?php if ($invitationsSent > 0):
            $claimRate = round(($invitationsClaimed / $invitationsSent) * 100, 1);
          ?>
          <p class="text-muted small mt-3 mb-0 text-center">
            Claim rate: <strong><?= $claimRate ?>%</strong> of invitations resulted in a listing claim.
          </p>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- RIGHT: Activity + Contact Methods -->
    <div class="col-12 col-lg-5">

      <!-- CONTACT METHODS -->
      <?php if (!empty($contactMethods)): ?>
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0">Contact Methods — <?= $validRanges[$rangeKey] ?></h6>
        </div>
        <div class="card-body">
          <?php
          $totalContacts = array_sum(array_column($contactMethods, 'cnt'));
          $methodIcons = ['phone'=>'📞','email'=>'✉️','whatsapp'=>'💬','in_person'=>'🤝','social_media'=>'📲','other'=>'📋'];
          foreach ($contactMethods as $m):
            $pct = $totalContacts > 0 ? round(($m['cnt'] / $totalContacts) * 100) : 0;
            $icon = $methodIcons[$m['contact_method']] ?? '📋';
            $label = ucwords(str_replace('_', ' ', $m['contact_method']));
          ?>
          <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:24px;text-align:center;"><?= $icon ?></div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between small mb-1">
                <span class="fw-semibold"><?= $label ?></span>
                <span class="text-muted"><?= $m['cnt'] ?> (<?= $pct ?>%)</span>
              </div>
              <div class="progress" style="height:6px;">
                <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- TOP PROSPECTS BY ACTIVITY -->
      <?php if (!empty($topProspects)): ?>
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0">Most Contacted — <?= $validRanges[$rangeKey] ?></h6>
        </div>
        <ul class="list-group list-group-flush">
          <?php foreach (array_slice($topProspects, 0, 5) as $p): ?>
          <li class="list-group-item d-flex align-items-center gap-3 py-3">
            <div class="flex-grow-1 min-width-0">
              <div class="fw-semibold small"><?= htmlspecialchars($p['business_name']) ?></div>
              <?php if ($p['city']): ?>
                <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($p['city']) ?></div>
              <?php endif; ?>
            </div>
            <div class="text-end">
              <span class="badge text-bg-primary"><?= $p['contact_count'] ?> contacts</span>
              <div class="text-muted" style="font-size:.7rem;margin-top:2px;">
                <?= ucfirst(str_replace('_',' ',$p['status'])) ?>
              </div>
            </div>
            <a href="prospect-detail.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary">→</a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <!-- RECENT ACTIVITY -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0">Recent Activity Log</h6>
        </div>
        <?php if (empty($recentActivity)): ?>
        <div class="card-body text-center text-muted small py-4">
          No activity recorded in this period.
        </div>
        <?php else: ?>
        <ul class="list-group list-group-flush" style="max-height:360px;overflow-y:auto;">
          <?php foreach ($recentActivity as $log):
            $evLabel = $eventLabels[$log['event_type']] ?? ucwords(str_replace('_',' ',$log['event_type']));
            $evIcon  = $eventIcons[$log['event_type']] ?? '📌';
          ?>
          <li class="list-group-item py-2 px-3">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <span style="font-size:.85rem"><?= $evIcon ?> <strong><?= $evLabel ?></strong></span>
                <?php if (!empty($log['business_name'])): ?>
                  <div class="text-muted small"><?= htmlspecialchars($log['business_name']) ?></div>
                <?php endif; ?>
              </div>
              <div class="text-muted text-nowrap" style="font-size:.72rem">
                <?= date('d M', strtotime($log['created_at'])) ?>
              </div>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>

    </div>
  </div><!-- /row -->

  <!-- FOOTER LINKS -->
  <div class="mt-4 pt-2 border-top d-flex gap-3 flex-wrap">
    <a href="my-prospects.php" class="btn btn-sm btn-outline-primary">View All Prospects</a>
    <a href="acquisition-insights.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-lightbulb me-1"></i> Insights
    </a>
    <a href="acquisition-dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>
  </div>

</div>

<?php require_once '../includes/partner-footer.php'; ?>
