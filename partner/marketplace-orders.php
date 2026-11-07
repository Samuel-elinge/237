<?php
/**
 * partner/marketplace-orders.php — My Marketplace Orders list
 *
 * Shows all orders placed by this partner across all their businesses.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$user           = currentUser();
$pdo            = db();
$lang           = $user['lang'] ?? 'en';

function mpT(string $en, string $fr): string {
    global $lang;
    return $lang === 'fr' ? $fr : $en;
}
function mpFmt(int $xaf): string {
    return number_format($xaf) . ' FCFA';
}

/* ── Filters ───────────────────────────────────────────────────── */
$filterState = trim($_GET['state'] ?? '');
$allowedStates = ['PENDING_OWNER_APPROVAL','AWAITING_PAYMENT','PAYMENT_REVIEW','PAID',
                  'READY_FOR_FULFILMENT','IN_FULFILMENT','COMPLETED','CANCELLED','REJECTED'];
if ($filterState && !in_array($filterState, $allowedStates, true)) {
    $filterState = '';
}

/* ── Query ─────────────────────────────────────────────────────── */
$whereClauses = ['o.partner_profile_id = ?'];
$params       = [$partnerId];
if ($filterState) {
    $whereClauses[] = 'o.order_state = ?';
    $params[] = $filterState;
}
$where = implode(' AND ', $whereClauses);

$orders = $pdo->prepare("
    SELECT o.id, o.ref, o.order_state, o.due_now_amount, o.created_at,
           l.title AS listing_title,
           COUNT(oi.id) AS item_count,
           GROUP_CONCAT(
               COALESCE(
                   JSON_UNQUOTE(JSON_EXTRACT(oi.product_snapshot,'$.name_" . ($lang === 'fr' ? 'fr' : 'en') . "')),
                   oi.product_id
               )
               ORDER BY oi.id SEPARATOR ', '
           ) AS product_names
    FROM marketplace_orders o
    JOIN listings l ON l.id = o.listing_id
    LEFT JOIN marketplace_order_items oi ON oi.order_id = o.id
    WHERE $where
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 100
");
$orders->execute($params);
$allOrders = $orders->fetchAll();

/* ── Counts by state for the filter bar ───────────────────────── */
$stateCounts = [];
$stC = $pdo->prepare("SELECT order_state, COUNT(*) cnt FROM marketplace_orders WHERE partner_profile_id=? GROUP BY order_state");
$stC->execute([$partnerId]);
foreach ($stC->fetchAll() as $r) $stateCounts[$r['order_state']] = (int)$r['cnt'];
$totalOrders = array_sum($stateCounts);
$activeCount = 0;
foreach (['PENDING_OWNER_APPROVAL','AWAITING_PAYMENT','PAYMENT_REVIEW','PAID','READY_FOR_FULFILMENT','IN_FULFILMENT'] as $s) {
    $activeCount += $stateCounts[$s] ?? 0;
}

$pageTitle = 'My Orders';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
partnerNavBadges($partnerId, $pdo);
partnerNavAssets();
partnerNav('marketplace-order');
?>

<style>
:root { --g:#00A878; --gbg:#081410; }
.mo-wrap { max-width:1100px; margin:0 auto; padding:1.5rem 1rem 3rem; }
.mo-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.75rem; margin-bottom:1.25rem; }
.mo-title { margin:0; font-size:1.4rem; font-weight:800; color:#fff; }
.mo-sub { font-size:.8rem; color:#6c9e88; margin-top:.2rem; }

/* Filter tabs */
.mo-filters { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:1.25rem; }
.mo-filter-btn {
  display:inline-flex; align-items:center; gap:.35rem;
  padding:.3rem .8rem; border-radius:20px; font-size:.78rem; font-weight:600;
  border:1px solid rgba(0,168,120,.25); color:#8db8a8;
  text-decoration:none; transition:.15s;
}
.mo-filter-btn:hover { border-color:var(--g); color:var(--g); }
.mo-filter-btn.active { background:rgba(0,168,120,.15); border-color:var(--g); color:var(--g); }
.mo-filter-cnt { background:rgba(0,168,120,.2); color:#00d49a; border-radius:10px; padding:0 .4rem; font-size:.7rem; }

/* Table card */
.mo-card {
  background:#0a1a10; border:1px solid rgba(0,168,120,.18); border-radius:12px; overflow:hidden;
}
.mo-card-header {
  display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem;
  padding:.8rem 1.25rem; background:rgba(0,168,120,.07); border-bottom:1px solid rgba(0,168,120,.15);
  font-size:.8rem; font-weight:700; color:#c8ddd4; text-transform:uppercase; letter-spacing:.04em;
}
table.mo-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.mo-table th {
  padding:.5rem 1rem; font-size:.7rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase;
  color:#6c9e88; border-bottom:1px solid rgba(0,168,120,.12); white-space:nowrap;
}
.mo-table td {
  padding:.7rem 1rem; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; color:#c8ddd4;
}
.mo-table tr:last-child td { border-bottom:none; }
.mo-table tr:hover td { background:rgba(0,168,120,.03); }
.mo-ref { font-family:monospace; font-size:.8rem; font-weight:700; color:#00d49a; }
.mo-listing { font-weight:600; color:#e8f4ee; }
.mo-products { font-size:.75rem; color:#6c9e88; margin-top:.15rem; }
.mo-amount { font-weight:700; color:#e8f4ee; }
.mo-date { font-size:.75rem; color:#5a8878; white-space:nowrap; }

/* State badges */
.mo-badge {
  display:inline-block; padding:.2em .6em; font-size:.7rem; font-weight:700;
  border-radius:20px; white-space:nowrap;
}
.mo-badge.pending  { background:rgba(240,192,64,.18); color:#f0c040; }
.mo-badge.action   { background:rgba(231,76,60,.18);  color:#e74c3c; }
.mo-badge.review   { background:rgba(52,152,219,.18);  color:#5dade2; }
.mo-badge.paid     { background:rgba(0,168,120,.2);   color:#00d49a; }
.mo-badge.done     { background:rgba(0,168,120,.12);  color:#6c9e88; }
.mo-badge.dead     { background:rgba(255,255,255,.07); color:#5a8878; }

/* CTA banner */
.mo-cta {
  background:linear-gradient(135deg,rgba(0,168,120,.12),rgba(0,100,70,.08));
  border:1px solid rgba(0,168,120,.3); border-radius:12px;
  padding:1.25rem 1.5rem; display:flex; align-items:center; gap:1.25rem;
  flex-wrap:wrap; margin-bottom:1.25rem;
}
.mo-cta-icon { font-size:2rem; flex-shrink:0; }
.mo-cta h3 { margin:0 0 .3rem; font-size:1rem; font-weight:700; color:#e8f4ee; }
.mo-cta p { margin:0; font-size:.83rem; color:#8db8a8; }
.mo-cta-btn {
  margin-left:auto; display:inline-flex; align-items:center; gap:.4rem;
  padding:.5rem 1.1rem; background:rgba(0,168,120,.2); border:1px solid rgba(0,168,120,.5);
  border-radius:8px; color:#00d49a; font-weight:700; font-size:.85rem;
  text-decoration:none; white-space:nowrap; transition:.15s;
}
.mo-cta-btn:hover { background:#00A878; color:#000; }

/* Empty state */
.mo-empty { padding:3rem 2rem; text-align:center; color:#5a8878; }
.mo-empty-icon { font-size:2.5rem; margin-bottom:.75rem; }
.mo-empty h3 { margin:0 0 .5rem; font-size:1rem; color:#8db8a8; }
.mo-empty p { margin:0 0 1rem; font-size:.85rem; }

@media(max-width:700px) {
  .mo-table th:nth-child(4), .mo-table td:nth-child(4),
  .mo-table th:nth-child(5), .mo-table td:nth-child(5) { display:none; }
}
</style>

<div class="mo-wrap">

  <div class="mo-header">
    <div>
      <h1 class="mo-title">🛒 My Orders</h1>
      <div class="mo-sub"><?= $totalOrders ?> total order<?= $totalOrders !== 1 ? 's' : '' ?>
        <?php if ($activeCount > 0): ?>
          &nbsp;·&nbsp;<span style="color:#f0c040"><?= $activeCount ?> in progress</span>
        <?php endif; ?>
      </div>
    </div>
    <a href="<?= SITE_URL ?>/partner/marketplace" class="mo-cta-btn">
      ➕ <?= mpT('Order New Service', 'Commander un service') ?>
    </a>
  </div>

  <?php if ($totalOrders === 0): ?>
  <!-- First-time CTA -->
  <div class="mo-cta">
    <div class="mo-cta-icon">🛒</div>
    <div>
      <h3><?= mpT('Get services for your businesses', 'Obtenez des services pour vos entreprises') ?></h3>
      <p><?= mpT('Browse the marketplace to order web, marketing, and business services on behalf of your clients.', 'Parcourez la marketplace pour commander des services web, marketing et business pour vos clients.') ?></p>
    </div>
    <a href="<?= SITE_URL ?>/partner/marketplace" class="mo-cta-btn">
      🛍️ <?= mpT('Browse Marketplace', 'Voir la marketplace') ?>
    </a>
  </div>
  <?php else: ?>

  <!-- Filter tabs -->
  <div class="mo-filters">
    <a href="<?= SITE_URL ?>/partner/marketplace-orders" class="mo-filter-btn<?= $filterState === '' ? ' active' : '' ?>">
      All <span class="mo-filter-cnt"><?= $totalOrders ?></span>
    </a>
    <?php
    $filterDefs = [
        'PENDING_OWNER_APPROVAL' => ['Awaiting Approval', 'pending'],
        'AWAITING_PAYMENT'       => ['Action Required', 'action'],
        'PAYMENT_REVIEW'         => ['Payment Review', 'review'],
        'PAID'                   => ['Paid', 'paid'],
        'READY_FOR_FULFILMENT'   => ['Ready', 'paid'],
        'IN_FULFILMENT'          => ['In Progress', 'paid'],
        'COMPLETED'              => ['Completed', 'done'],
        'CANCELLED'              => ['Cancelled', 'dead'],
        'REJECTED'               => ['Rejected', 'dead'],
    ];
    foreach ($filterDefs as $state => [$label, $_]) {
        $cnt = $stateCounts[$state] ?? 0;
        if ($cnt === 0) continue;
        $isActive = $filterState === $state;
    ?>
    <a href="<?= SITE_URL ?>/partner/marketplace-orders?state=<?= urlencode($state) ?>"
       class="mo-filter-btn<?= $isActive ? ' active' : '' ?>">
      <?= htmlspecialchars($label) ?> <span class="mo-filter-cnt"><?= $cnt ?></span>
    </a>
    <?php } ?>
  </div>

  <!-- Orders table -->
  <div class="mo-card">
    <div class="mo-card-header">
      <span><?= $filterState ? htmlspecialchars($filterDefs[$filterState][0] ?? $filterState) : 'All Orders' ?></span>
      <span style="font-weight:400; color:#5a8878"><?= count($allOrders) ?> order<?= count($allOrders) !== 1 ? 's' : '' ?></span>
    </div>
    <?php if (empty($allOrders)): ?>
    <div class="mo-empty">
      <div class="mo-empty-icon">📭</div>
      <h3>No orders with this status</h3>
    </div>
    <?php else: ?>
    <table class="mo-table">
      <thead>
        <tr>
          <th>Ref</th>
          <th>Business</th>
          <th>Status</th>
          <th>Amount</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allOrders as $o):
            $state = $o['order_state'];
            [$badgeLabel, $badgeCls] = match($state) {
                'PENDING_OWNER_APPROVAL' => ['Awaiting Approval', 'pending'],
                'AWAITING_PAYMENT'       => ['Pay Now', 'action'],
                'PAYMENT_REVIEW'         => ['Payment Review', 'review'],
                'PAID'                   => ['Paid', 'paid'],
                'READY_FOR_FULFILMENT'   => ['Ready to Start', 'paid'],
                'IN_FULFILMENT'          => ['In Progress', 'paid'],
                'COMPLETED'              => ['Completed', 'done'],
                'CANCELLED'              => ['Cancelled', 'dead'],
                'REJECTED'               => ['Rejected', 'dead'],
                default                  => [$state, 'dead'],
            };
            $amount = (int)($o['due_now_amount'] ?? 0);
        ?>
        <tr>
          <td><span class="mo-ref"><?= e($o['ref']) ?></span></td>
          <td>
            <div class="mo-listing"><?= e($o['listing_title']) ?></div>
            <?php if ($o['product_names']): ?>
            <div class="mo-products"><?= e($o['product_names']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="mo-badge <?= $badgeCls ?>"><?= $badgeLabel ?></span></td>
          <td class="mo-amount"><?= $amount > 0 ? mpFmt($amount) : '—' ?></td>
          <td class="mo-date"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
          <td>
            <a href="<?= SITE_URL ?>/partner/marketplace-order?ref=<?= urlencode($o['ref']) ?>"
               style="display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .65rem;border-radius:6px;border:1px solid rgba(0,168,120,.3);color:#00d49a;font-size:.75rem;font-weight:600;text-decoration:none;transition:.15s"
               onmouseover="this.style.background='rgba(0,168,120,.15)'" onmouseout="this.style.background=''">
              View →
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
