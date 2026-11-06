<?php
/**
 * Partner Pipeline — main acquisition working view
 * Combines overview stats + filterable prospect list in one page.
 * Replaces the old acquisition-dashboard.php + my-prospects.php
 */

require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$pdo            = db();

// ── Filter & pagination ───────────────────────────────────────────────────────
$filter  = $_GET['filter'] ?? 'active';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$filterMap = [
    'active'    => "bp.status NOT IN('closed','rejected','not_interested','unreachable','converted')",
    'new'       => "bp.status = 'new'",
    'contacted' => "bp.status IN('contacted','follow_up_required')",
    'interested'=> "bp.status = 'interested'",
    'awaiting'  => "bp.status IN('awaiting_authorisation','awaiting_assignment','awaiting_owner_response')",
    'converted' => "bp.status = 'converted'",
    'overdue'   => "bp.next_followup IS NOT NULL AND bp.next_followup < CURDATE() AND bp.status NOT IN('converted','closed','rejected','not_interested','unreachable')",
    'closed'    => "bp.status IN('closed','rejected','not_interested','unreachable')",
    'all'       => "1=1",
];
$whereFilter = $filterMap[$filter] ?? $filterMap['active'];
$countFilter = str_replace('bp.', '', $whereFilter);

// ── Stat counts ───────────────────────────────────────────────────────────────
$count = function(string $sql, array $params = []) use ($pdo): int {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return (int)$s->fetchColumn();
};
$pid = $partnerId;

$stats = [
    'active'    => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status NOT IN('closed','rejected','not_interested','unreachable','converted')", [$pid]),
    'new'       => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status='new'", [$pid]),
    'contacted' => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status IN('contacted','follow_up_required')", [$pid]),
    'interested'=> $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status='interested'", [$pid]),
    'awaiting'  => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status IN('awaiting_authorisation','awaiting_assignment','awaiting_owner_response')", [$pid]),
    'converted' => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND status='converted'", [$pid]),
    'overdue'   => $count("SELECT COUNT(*) FROM business_prospects WHERE partner_id=? AND next_followup IS NOT NULL AND next_followup < CURDATE() AND status NOT IN('converted','closed','rejected','not_interested','unreachable')", [$pid]),
    'available' => $count(
        "SELECT COUNT(*) FROM businesses b WHERE b.status='active'
         AND NOT EXISTS(SELECT 1 FROM business_prospects bp WHERE bp.business_id=b.id AND bp.partner_id=? AND bp.status NOT IN('not_interested','unreachable','rejected','closed'))",
        [$pid]
    ),
];

// ── Prospect list ─────────────────────────────────────────────────────────────
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM business_prospects WHERE partner_id = ? AND $countFilter");
$totalStmt->execute([$pid]);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT bp.id, bp.status, bp.next_followup, bp.last_contact, bp.updated_at,
           b.name AS business_name, b.category, b.city,
           CASE WHEN b.owner_user_id IS NULL OR b.owner_user_id = 0 THEN 0 ELSE 1 END AS is_claimed
    FROM business_prospects bp
    JOIN businesses b ON b.id = bp.business_id
    WHERE bp.partner_id = ? AND $whereFilter
    ORDER BY bp.next_followup IS NULL ASC, bp.next_followup ASC, bp.updated_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $pid,     PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, $offset,  PDO::PARAM_INT);
$stmt->execute();
$prospects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Status labels ─────────────────────────────────────────────────────────────
$statusLabels = [
    'new'                      => ['label' => 'New',                    'class' => 'bg-secondary'],
    'contacted'                => ['label' => 'Contacted',              'class' => 'bg-info text-dark'],
    'follow_up_required'       => ['label' => 'Follow-up Required',     'class' => 'bg-warning text-dark'],
    'interested'               => ['label' => 'Interested',             'class' => 'bg-success'],
    'invitation_sent'          => ['label' => 'Invitation Sent',        'class' => 'bg-primary'],
    'awaiting_owner_response'  => ['label' => 'Awaiting Owner',         'class' => 'bg-warning text-dark'],
    'awaiting_authorisation'   => ['label' => 'Awaiting Authorisation', 'class' => 'bg-warning text-dark'],
    'awaiting_assignment'      => ['label' => 'Awaiting Assignment',    'class' => 'bg-warning text-dark'],
    'converted'                => ['label' => 'Converted ✓',            'class' => 'bg-success'],
    'not_interested'           => ['label' => 'Not Interested',         'class' => 'bg-danger'],
    'unreachable'              => ['label' => 'Unreachable',            'class' => 'bg-danger'],
    'rejected'                 => ['label' => 'Rejected',               'class' => 'bg-danger'],
    'closed'                   => ['label' => 'Closed',                 'class' => 'bg-secondary'],
];

$tabs = [
    'active'    => ['Active',          $stats['active']],
    'new'       => ['New',             $stats['new']],
    'contacted' => ['Contacted',       $stats['contacted']],
    'interested'=> ['Interested',      $stats['interested']],
    'awaiting'  => ['Awaiting',        $stats['awaiting']],
    'converted' => ['Converted',       $stats['converted']],
    'overdue'   => ['⚠ Overdue',      $stats['overdue']],
    'closed'    => ['Closed',          null],
    'all'       => ['All',             null],
];

$pageTitle = 'My Pipeline';
require_once '../includes/partner-header.php';
?>

<div class="container-fluid px-4 py-4">

  <!-- Page header -->
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-0">My Pipeline</h1>
      <p class="text-muted mb-0">Your business acquisition pipeline — <?= number_format($stats['available']) ?> businesses available to prospect.</p>
    </div>
    <a href="find-businesses.php" class="btn btn-primary">
      <i class="bi bi-plus-circle me-1"></i> Find & Add Business
    </a>
  </div>

  <?php if ($stats['overdue'] > 0): ?>
  <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0"></i>
    <span>You have <strong><?= $stats['overdue'] ?></strong> overdue follow-up<?= $stats['overdue'] !== 1 ? 's' : '' ?> that need attention.</span>
    <a href="?filter=overdue" class="alert-link ms-2 text-nowrap">View them &rarr;</a>
  </div>
  <?php endif; ?>

  <!-- Stat strip -->
  <div class="row g-2 mb-4">
    <?php
    $statCards = [
        ['active',     'Active',     'text-dark',    '?filter=active'],
        ['contacted',  'Contacted',  'text-info',    '?filter=contacted'],
        ['interested', 'Interested', 'text-success', '?filter=interested'],
        ['awaiting',   'Awaiting',   'text-warning', '?filter=awaiting'],
        ['converted',  'Converted',  'text-success', '?filter=converted'],
    ];
    foreach ($statCards as [$key, $label, $color, $href]):
    ?>
    <div class="col">
      <a href="<?= $href ?>" class="card border-0 shadow-sm text-decoration-none h-100">
        <div class="card-body text-center py-3 px-2">
          <div class="fs-4 fw-bold <?= $color ?>"><?= $stats[$key] ?></div>
          <div class="text-muted small mt-1"><?= $label ?></div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filter tabs + prospect list -->
  <div class="card border-0 shadow-sm">

    <!-- Tabs -->
    <div class="card-header bg-white px-0 pb-0 pt-2" style="border-bottom:none;">
      <div style="overflow-x:auto;white-space:nowrap;scrollbar-width:none;padding:0 1rem;">
        <?php foreach ($tabs as $key => [$label, $cnt]): ?>
        <a href="?filter=<?= $key ?>"
           class="d-inline-block text-decoration-none py-2 px-3 small fw-semibold"
           style="border-bottom: 2px solid <?= $filter === $key ? '#0d6efd' : 'transparent' ?>;
                  color: <?= $filter === $key ? '#0d6efd' : '#6c757d' ?>;
                  margin-bottom: -1px;">
          <?= $label ?>
          <?php if ($cnt !== null && $cnt > 0): ?>
            <span class="ms-1 badge rounded-pill <?= $key === 'overdue' ? 'bg-danger' : 'bg-primary' ?>" style="font-size:.6rem"><?= $cnt ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
      <div style="border-bottom:1px solid #dee2e6;margin:0 1rem;"></div>
    </div>

    <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center border-top-0">
      <span class="text-muted small"><?= number_format($total) ?> prospect<?= $total !== 1 ? 's' : '' ?></span>
      <?php if ($pages > 1): ?>
        <span class="text-muted small">Page <?= $page ?> of <?= $pages ?></span>
      <?php endif; ?>
    </div>

    <?php if (empty($prospects)): ?>
      <div class="card-body text-center text-muted py-5">
        <i class="bi bi-clipboard-x fs-1 d-block mb-2"></i>
        <?php if ($filter === 'active'): ?>
          No active prospects yet. <a href="find-businesses.php">Find your first business &rarr;</a>
        <?php elseif ($filter === 'overdue'): ?>
          No overdue follow-ups — great work!
        <?php else: ?>
          No prospects in this view.
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Business</th>
            <th class="d-none d-md-table-cell">Category</th>
            <th class="d-none d-md-table-cell">City</th>
            <th>Status</th>
            <th>Next Follow-up</th>
            <th class="pe-3"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($prospects as $p):
            $sl = $statusLabels[$p['status']] ?? ['label' => $p['status'], 'class' => 'bg-secondary'];
            $overdue = $p['next_followup'] && $p['next_followup'] < date('Y-m-d');
          ?>
          <tr class="<?= $overdue ? 'table-warning' : '' ?>">
            <td class="ps-3">
              <div class="fw-semibold"><?= htmlspecialchars($p['business_name']) ?></div>
              <?php if (!$p['is_claimed']): ?>
                <span class="badge bg-secondary" style="font-size:.6rem">Unclaimed</span>
              <?php endif; ?>
            </td>
            <td class="d-none d-md-table-cell text-muted small"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
            <td class="d-none d-md-table-cell text-muted small"><?= htmlspecialchars($p['city'] ?? '—') ?></td>
            <td><span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span></td>
            <td class="<?= $overdue ? 'text-danger fw-semibold small' : 'text-muted small' ?>">
              <?= $p['next_followup'] ? date('d M Y', strtotime($p['next_followup'])) : '—' ?>
              <?= $overdue ? ' <i class="bi bi-exclamation-circle-fill"></i>' : '' ?>
            </td>
            <td class="text-end pe-3">
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
          <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $i ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
      </ul></nav>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<?php require_once '../includes/partner-footer.php'; ?>
