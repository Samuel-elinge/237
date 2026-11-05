<?php
/**
 * Business Acquisition Dashboard
 * Stage A – 237biz Business Acquisition Module
 * Role: partner
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';

requirePermission('partner');

$partnerId = $_SESSION['partner_id'] ?? null;
if (!$partnerId) {
    header('Location: /partner/dashboard.php');
    exit;
}

// ── Live stats ────────────────────────────────────────────────────────────────
$pdo = db();

// Count of all eligible businesses (not already assigned to THIS partner,
// not restricted by admin – simplified: businesses with no active assignment
// to this partner)
$availableCount = (int) $pdo->prepare("
    SELECT COUNT(*) FROM businesses b
    WHERE b.status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM business_prospects bp
          WHERE bp.business_id = b.id
            AND bp.partner_id = :pid
            AND bp.status NOT IN ('not_interested','unreachable','rejected','closed')
      )
")->execute([':pid' => $partnerId]) ? $pdo->query("
    SELECT COUNT(*) FROM businesses b
    WHERE b.status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM business_prospects bp
          WHERE bp.business_id = b.id
            AND bp.partner_id = :pid
            AND bp.status NOT IN ('not_interested','unreachable','rejected','closed')
      )
")->fetchColumn() : 0;

// Use a helper closure for simple counts
$count = function(string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};

$pid = $partnerId;

$stats = [
    'available'   => $count(
        "SELECT COUNT(*) FROM businesses b WHERE b.status='active'
         AND NOT EXISTS(SELECT 1 FROM business_prospects bp WHERE bp.business_id=b.id AND bp.partner_id=? AND bp.status NOT IN('not_interested','unreachable','rejected','closed'))",
        [$pid]
    ),
    'prospects'   => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status NOT IN('closed','rejected','not_interested','unreachable')", [$pid]),
    'contacted'   => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status IN('contacted','follow_up_required','interested','invitation_sent','awaiting_owner_response','awaiting_authorisation','awaiting_assignment')", [$pid]),
    'interested'  => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status='interested'", [$pid]),
    'awaiting'    => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status IN('awaiting_authorisation','awaiting_assignment')", [$pid]),
    'converted'   => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status='converted'", [$pid]),
    'unsuccessful'=> $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status IN('not_interested','unreachable','rejected','closed')", [$pid]),
    'overdue'     => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND next_followup IS NOT NULL AND next_followup < CURDATE() AND status NOT IN('converted','closed','rejected','not_interested','unreachable')", [$pid]),
];

// Recent prospects needing attention
$stmt = $pdo->prepare("
    SELECT bp.id, bp.status, bp.next_followup, bp.last_contact,
           b.name AS business_name, b.city, b.category
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    WHERE bp.partner_id = ?
      AND bp.status NOT IN('closed','rejected','not_interested','unreachable','converted')
    ORDER BY bp.next_followup IS NULL, bp.next_followup ASC, bp.updated_at DESC
    LIMIT 10
");
$stmt->execute([$pid]);
$recentProspects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'new'                      => ['label' => 'New',                     'class' => 'badge-secondary'],
    'contacted'                => ['label' => 'Contacted',               'class' => 'badge-info'],
    'follow_up_required'       => ['label' => 'Follow-up Required',      'class' => 'badge-warning'],
    'interested'               => ['label' => 'Interested',              'class' => 'badge-success'],
    'invitation_sent'          => ['label' => 'Invitation Sent',         'class' => 'badge-primary'],
    'awaiting_owner_response'  => ['label' => 'Awaiting Owner',          'class' => 'badge-warning'],
    'awaiting_authorisation'   => ['label' => 'Awaiting Authorisation',  'class' => 'badge-warning'],
    'awaiting_assignment'      => ['label' => 'Awaiting Assignment',     'class' => 'badge-warning'],
    'converted'                => ['label' => 'Converted',               'class' => 'badge-success'],
    'not_interested'           => ['label' => 'Not Interested',          'class' => 'badge-danger'],
    'unreachable'              => ['label' => 'Unreachable',             'class' => 'badge-danger'],
    'rejected'                 => ['label' => 'Rejected',                'class' => 'badge-danger'],
    'closed'                   => ['label' => 'Closed',                  'class' => 'badge-secondary'],
];

$pageTitle = 'Business Acquisition';
require_once '../includes/partner-header.php';
?>

<div class="container-fluid px-4 py-4">

  <!-- Page header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h3 mb-0">Business Acquisition</h1>
      <p class="text-muted mb-0">Find, prospect and convert existing 237biz businesses into managed clients.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="find-businesses.php" class="btn btn-primary">
        <i class="bi bi-search me-1"></i> Find Businesses
      </a>
      <a href="my-prospects.php" class="btn btn-outline-secondary">
        <i class="bi bi-list-task me-1"></i> My Prospects
      </a>
    </div>
  </div>

  <?php if ($stats['overdue'] > 0): ?>
  <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    You have <strong class="mx-1"><?= $stats['overdue'] ?></strong> overdue follow-up<?= $stats['overdue'] !== 1 ? 's' : '' ?>.
    <a href="my-prospects.php?filter=overdue" class="alert-link ms-2">View them &rarr;</a>
  </div>
  <?php endif; ?>

  <!-- Stat cards -->
  <div class="row g-3 mb-4">

    <div class="col-6 col-md-4 col-xl-2">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body text-center">
          <div class="display-6 fw-bold text-primary"><?= number_format($stats['available']) ?></div>
          <div class="small text-muted mt-1">Available Businesses</div>
          <a href="find-businesses.php" class="stretched-link"></a>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-4 col-xl-2">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body text-center">
          <div class="display-6 fw-bold text-dark"><?= $stats['prospects'] ?></div>
          <div class="small text-muted mt-1">My Prospects</div>
          <a href="my-prospects.php" class="stretched-link"></a>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-4 col-xl-2">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body text-center">
          <div class="display-6 fw-bold text-info"><?= $stats['contacted'] ?></div>
          <div class="small text-muted mt-1">Contacted</div>
          <a href="my-prospects.php?filter=contacted" class="stretched-link"></a>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-4 col-xl-2">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body text-center">
          <div class="display-6 fw-bold text-success"><?= $stats['interested'] ?></div>
          <div class="small text-muted mt-1">Interested</div>
          <a href="my-prospects.php?filter=interested" class="stretched-link"></a>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-4 col-xl-2">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body text-center">
          <div class="display-6 fw-bold text-warning"><?= $stats['awaiting'] ?></div>
          <div class="small text-muted mt-1">Awaiting Approval</div>
          <a href="my-prospects.php?filter=awaiting" class="stretched-link"></a>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-4 col-xl-2">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body text-center">
          <div class="display-6 fw-bold text-success"><?= $stats['converted'] ?></div>
          <div class="small text-muted mt-1">Converted</div>
          <a href="my-prospects.php?filter=converted" class="stretched-link"></a>
        </div>
      </div>
    </div>

  </div>

  <!-- Prospects needing attention -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
      <h5 class="mb-0">Prospects Needing Attention</h5>
      <a href="my-prospects.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body p-0">
      <?php if (empty($recentProspects)): ?>
        <div class="text-center text-muted py-5">
          <i class="bi bi-inbox fs-1 d-block mb-2"></i>
          No active prospects yet.
          <a href="find-businesses.php">Find your first business &rarr;</a>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Business</th>
              <th>Location</th>
              <th>Status</th>
              <th>Next Follow-up</th>
              <th>Last Contact</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentProspects as $p):
              $sl = $statusLabels[$p['status']] ?? ['label' => $p['status'], 'class' => 'badge-secondary'];
              $overdue = $p['next_followup'] && $p['next_followup'] < date('Y-m-d');
            ?>
            <tr class="<?= $overdue ? 'table-warning' : '' ?>">
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($p['business_name']) ?></div>
                <div class="small text-muted"><?= htmlspecialchars($p['category'] ?? '') ?></div>
              </td>
              <td class="text-muted small"><?= htmlspecialchars($p['city'] ?? '—') ?></td>
              <td><span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span></td>
              <td class="<?= $overdue ? 'text-danger fw-bold' : '' ?>">
                <?= $p['next_followup'] ? date('d M Y', strtotime($p['next_followup'])) : '—' ?>
                <?= $overdue ? ' <i class="bi bi-exclamation-circle-fill"></i>' : '' ?>
              </td>
              <td class="text-muted small">
                <?= $p['last_contact'] ? date('d M Y', strtotime($p['last_contact'])) : 'Never' ?>
              </td>
              <td class="text-end">
                <a href="prospect-detail.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Quick action buttons (mobile-friendly) -->
  <div class="row g-3">
    <div class="col-6 col-md-3">
      <a href="find-businesses.php" class="card text-center text-decoration-none border-0 shadow-sm h-100 p-3">
        <i class="bi bi-search fs-2 text-primary mb-2"></i>
        <div class="small fw-semibold">Find Businesses</div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="my-prospects.php" class="card text-center text-decoration-none border-0 shadow-sm h-100 p-3">
        <i class="bi bi-person-lines-fill fs-2 text-secondary mb-2"></i>
        <div class="small fw-semibold">My Prospects</div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="my-prospects.php?action=record-contact" class="card text-center text-decoration-none border-0 shadow-sm h-100 p-3">
        <i class="bi bi-telephone-plus fs-2 text-info mb-2"></i>
        <div class="small fw-semibold">Record Contact</div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="acquisition-performance.php" class="card text-center text-decoration-none border-0 shadow-sm h-100 p-3">
        <i class="bi bi-graph-up-arrow fs-2 text-success mb-2"></i>
        <div class="small fw-semibold">My Performance</div>
      </a>
    </div>
  </div>

</div>

<?php require_once '../includes/partner-footer.php'; ?>
