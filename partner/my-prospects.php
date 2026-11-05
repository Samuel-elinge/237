<?php
/**
 * My Prospects — pipeline view with status filter
 * Stage A §6
 */

require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];

$pdo = db();

$filter = $_GET['filter'] ?? 'active';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$filterMap = [
    'active'    => "status NOT IN('closed','rejected','not_interested','unreachable','converted')",
    'contacted' => "status IN('contacted','follow_up_required')",
    'interested'=> "status = 'interested'",
    'awaiting'  => "status IN('awaiting_authorisation','awaiting_assignment','awaiting_owner_response')",
    'converted' => "status = 'converted'",
    'overdue'   => "next_followup IS NOT NULL AND next_followup < CURDATE() AND status NOT IN('converted','closed','rejected','not_interested','unreachable')",
    'closed'    => "status IN('closed','rejected','not_interested','unreachable')",
    'all'       => "1=1",
];
$whereFilter = $filterMap[$filter] ?? $filterMap['active'];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM business_prospects WHERE partner_id = ? AND $whereFilter");
$countStmt->execute([$partnerId]);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT bp.id, bp.status, bp.next_followup, bp.last_contact, bp.notes, bp.updated_at,
           b.name AS business_name, b.category, b.city,
           CASE WHEN b.owner_user_id IS NULL OR b.owner_user_id = 0 THEN 0 ELSE 1 END AS is_claimed
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    WHERE bp.partner_id = ? AND $whereFilter
    ORDER BY bp.next_followup IS NULL ASC, bp.next_followup ASC, bp.updated_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$partnerId, $perPage, $offset]);
$prospects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'new'                      => ['label' => 'New',                     'class' => 'bg-secondary'],
    'contacted'                => ['label' => 'Contacted',               'class' => 'bg-info text-dark'],
    'follow_up_required'       => ['label' => 'Follow-up Required',      'class' => 'bg-warning text-dark'],
    'interested'               => ['label' => 'Interested',              'class' => 'bg-success'],
    'invitation_sent'          => ['label' => 'Invitation Sent',         'class' => 'bg-primary'],
    'awaiting_owner_response'  => ['label' => 'Awaiting Owner',          'class' => 'bg-warning text-dark'],
    'awaiting_authorisation'   => ['label' => 'Awaiting Authorisation',  'class' => 'bg-warning text-dark'],
    'awaiting_assignment'      => ['label' => 'Awaiting Assignment',     'class' => 'bg-warning text-dark'],
    'converted'                => ['label' => 'Converted ✓',             'class' => 'bg-success'],
    'not_interested'           => ['label' => 'Not Interested',          'class' => 'bg-danger'],
    'unreachable'              => ['label' => 'Unreachable',             'class' => 'bg-danger'],
    'rejected'                 => ['label' => 'Rejected',                'class' => 'bg-danger'],
    'closed'                   => ['label' => 'Closed',                  'class' => 'bg-secondary'],
];

$tabs = [
    'active'    => 'Active',
    'contacted' => 'Contacted',
    'interested'=> 'Interested',
    'awaiting'  => 'Awaiting Approval',
    'converted' => 'Converted',
    'overdue'   => 'Overdue',
    'closed'    => 'Closed',
    'all'       => 'All',
];

$pageTitle = 'My Prospects';
require_once '../includes/partner-header.php';
?>

<div class="container-fluid px-4 py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h3 mb-0">My Prospects</h1>
      <p class="text-muted mb-0">Your business acquisition pipeline.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="find-businesses.php" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Add Prospect
      </a>
      <a href="acquisition-dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-speedometer2 me-1"></i> Dashboard
      </a>
    </div>
  </div>

  <!-- Flash messages -->
  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($_SESSION['flash_success']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($_SESSION['flash_error']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <!-- Filter tabs -->
  <ul class="nav nav-tabs mb-0" style="border-bottom: none;">
    <?php foreach ($tabs as $key => $label): ?>
    <li class="nav-item">
      <a class="nav-link <?= $filter === $key ? 'active' : '' ?>"
         href="my-prospects.php?filter=<?= $key ?>">
        <?= $label ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="card border-0 shadow-sm" style="border-top-left-radius:0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
      <span class="fw-semibold"><?= number_format($total) ?> prospect<?= $total !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($prospects)): ?>
      <div class="card-body text-center text-muted py-5">
        <i class="bi bi-clipboard-x fs-1 d-block mb-2"></i>
        No prospects in this view.
        <?php if ($filter === 'active'): ?>
          <a href="find-businesses.php">Find your first business &rarr;</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Business</th>
            <th class="d-none d-md-table-cell">Category</th>
            <th class="d-none d-md-table-cell">City</th>
            <th>Status</th>
            <th>Next Follow-up</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($prospects as $p):
            $sl = $statusLabels[$p['status']] ?? ['label' => $p['status'], 'class' => 'bg-secondary'];
            $overdue = $p['next_followup'] && $p['next_followup'] < date('Y-m-d');
          ?>
          <tr class="<?= $overdue ? 'table-warning' : '' ?>">
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($p['business_name']) ?></div>
              <?php if (!$p['is_claimed']): ?>
                <span class="badge bg-secondary" style="font-size:.65rem">Unclaimed</span>
              <?php endif; ?>
            </td>
            <td class="d-none d-md-table-cell text-muted small"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
            <td class="d-none d-md-table-cell text-muted small"><?= htmlspecialchars($p['city'] ?? '—') ?></td>
            <td><span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span></td>
            <td class="<?= $overdue ? 'text-danger fw-bold small' : 'text-muted small' ?>">
              <?= $p['next_followup'] ? date('d M Y', strtotime($p['next_followup'])) : '—' ?>
            </td>
            <td class="text-end">
              <a href="prospect-detail.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white">
      <nav><ul class="pagination pagination-sm justify-content-center mb-0">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
          <a class="page-link" href="my-prospects.php?filter=<?= $filter ?>&page=<?= $i ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
      </ul></nav>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<?php require_once '../includes/partner-footer.php'; ?>
