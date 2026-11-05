<?php
/**
 * Business Discovery — Find eligible existing 237biz businesses
 * Stage A §5 — search, filters, eligibility rules
 */

require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];

$pdo = db();

// ── Filter inputs (whitelist everything) ──────────────────────────────────────
$search   = trim($_GET['q']       ?? '');
$city     = trim($_GET['city']    ?? '');
$region   = trim($_GET['region']  ?? '');
$category = trim($_GET['category'] ?? '');
$claimed  = $_GET['claimed'] ?? '';   // 'claimed' | 'unclaimed' | ''
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

// Allowed city list (§5 geographic search)
$allowedCities = ['Buea','Limbe','Douala','Yaoundé','Kumba','Bamenda','Kribi'];

// ── Build query ───────────────────────────────────────────────────────────────
// §5 eligibility: active businesses not already actively prospected by this partner
$whereClauses = [
    "b.status = 'active'",
    // Exclude businesses already in an active/non-terminal prospect for THIS partner
    "NOT EXISTS (
        SELECT 1 FROM business_prospects bp
        WHERE bp.business_id = b.id
          AND bp.partner_id = :partner_id
          AND bp.status NOT IN ('not_interested','unreachable','rejected','closed')
    )",
];
$params = [':partner_id' => $partnerId];

if ($search !== '') {
    $whereClauses[] = "(b.name LIKE :search OR b.description LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}
if ($city !== '' && in_array($city, $allowedCities, true)) {
    $whereClauses[] = "b.city = :city";
    $params[':city'] = $city;
}
if ($region !== '') {
    $whereClauses[] = "b.region = :region";
    $params[':region'] = $region;
}
if ($category !== '') {
    $whereClauses[] = "b.category = :category";
    $params[':category'] = $category;
}
// §5 — claimed/unclaimed filter: owner_user_id IS NULL = unclaimed
if ($claimed === 'unclaimed') {
    $whereClauses[] = "(b.owner_user_id IS NULL OR b.owner_user_id = 0)";
} elseif ($claimed === 'claimed') {
    $whereClauses[] = "(b.owner_user_id IS NOT NULL AND b.owner_user_id > 0)";
}

$where = implode(' AND ', $whereClauses);

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM businesses b WHERE $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

// Results — also show if business already has ANY partner (§5: show status, don't expose partner info)
$params[':limit']  = $perPage;
$params[':offset'] = $offset;

$sql = "
    SELECT
        b.id, b.name, b.category, b.city, b.region,
        b.phone, b.website,
        CASE WHEN b.owner_user_id IS NULL OR b.owner_user_id = 0 THEN 0 ELSE 1 END AS is_claimed,
        CASE WHEN pba.id IS NOT NULL THEN 1 ELSE 0 END AS has_active_partner
    FROM businesses b
    LEFT JOIN partner_business_assignments pba
           ON pba.business_id = b.id AND pba.status = 'active'
    WHERE $where
    GROUP BY b.id
    ORDER BY b.name ASC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
// PDO requires explicit int binding for LIMIT/OFFSET
foreach ($params as $k => $v) {
    if ($k === ':limit' || $k === ':offset') {
        $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($k, $v);
    }
}
$stmt->execute();
$businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Category list for filter dropdown — from actual data
$catStmt = $pdo->query("SELECT DISTINCT category FROM businesses WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Find Businesses';
require_once '../includes/partner-header.php';
?>

<div class="container-fluid px-4 py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h3 mb-0">Find Businesses</h1>
      <p class="text-muted mb-0">Discover existing 237biz businesses you can prospect and manage.</p>
    </div>
    <a href="acquisition-dashboard.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i> Dashboard
    </a>
  </div>

  <!-- Search & filter form -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
      <form method="get" action="find-businesses.php" class="row g-3 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold">Search</label>
          <input type="text" name="q" class="form-control" placeholder="Business name…"
                 value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small fw-semibold">City</label>
          <select name="city" class="form-select">
            <option value="">All cities</option>
            <?php foreach ($allowedCities as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>" <?= $city === $c ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small fw-semibold">Category</label>
          <select name="category" class="form-select">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small fw-semibold">Status</label>
          <select name="claimed" class="form-select">
            <option value="">All</option>
            <option value="unclaimed" <?= $claimed === 'unclaimed' ? 'selected' : '' ?>>Unclaimed</option>
            <option value="claimed"   <?= $claimed === 'claimed'   ? 'selected' : '' ?>>Claimed</option>
          </select>
        </div>
        <div class="col-6 col-md-2 d-flex gap-2">
          <button type="submit" class="btn btn-primary flex-grow-1">
            <i class="bi bi-search"></i> Search
          </button>
          <a href="find-businesses.php" class="btn btn-outline-secondary">
            <i class="bi bi-x"></i>
          </a>
        </div>
      </form>
    </div>
  </div>

  <!-- Results -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
      <span class="fw-semibold"><?= number_format($total) ?> business<?= $total !== 1 ? 'es' : '' ?> found</span>
      <?php if ($total > $perPage): ?>
        <span class="text-muted small">Page <?= $page ?> of <?= $pages ?></span>
      <?php endif; ?>
    </div>

    <?php if (empty($businesses)): ?>
      <div class="card-body text-center text-muted py-5">
        <i class="bi bi-building fs-1 d-block mb-2"></i>
        No businesses match your search.
      </div>
    <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($businesses as $b): ?>
      <div class="list-group-item list-group-item-action px-4 py-3">
        <div class="row align-items-center">
          <div class="col">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="fw-semibold"><?= htmlspecialchars($b['name']) ?></span>
              <?php if (!$b['is_claimed']): ?>
                <span class="badge bg-secondary">Unclaimed</span>
              <?php else: ?>
                <span class="badge bg-success">Claimed</span>
              <?php endif; ?>
              <?php if ($b['has_active_partner']): ?>
                <span class="badge bg-info text-dark">Has Partner</span>
              <?php endif; ?>
            </div>
            <div class="text-muted small">
              <?= htmlspecialchars($b['category'] ?? '') ?>
              <?php if ($b['city']): ?>
                · <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($b['city']) ?>
                <?= $b['region'] ? ', ' . htmlspecialchars($b['region']) : '' ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-auto">
            <form method="post" action="add-prospect.php">
              <input type="hidden" name="business_id" value="<?= (int)$b['id'] ?>">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
              <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Add to Prospects
              </button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white">
      <nav>
        <ul class="pagination pagination-sm justify-content-center mb-0">
          <?php
          $qBase = http_build_query(array_filter([
              'q' => $search, 'city' => $city, 'category' => $category, 'claimed' => $claimed
          ]));
          for ($i = 1; $i <= $pages; $i++):
          ?>
          <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="find-businesses.php?<?= $qBase ?>&page=<?= $i ?>"><?= $i ?></a>
          </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<?php require_once '../includes/partner-footer.php'; ?>
