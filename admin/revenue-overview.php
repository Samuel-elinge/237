<?php
/**
 * admin/revenue-overview.php — Phase 3 Module G+H
 * Revenue analytics + admin oversight dashboard for monetisation.
 * Shows subscription revenue, promotion revenue, commission liability,
 * payout status, and top-performing partners/listings.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin-header.php';

// ── Date filter ───────────────────────────────────────────────────────────────
$period  = $_GET['period'] ?? '30';
$periods = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'all' => 'All time'];
$dateClause = $period === 'all' ? '1=1' : "mo.payment_confirmed_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)";
$dateClauseRaw = $period === 'all' ? '1=1' : "payment_confirmed_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)";
$dateClausePc = $period === 'all' ? '1=1' : "pc.created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)";

// ── Revenue totals ─────────────────────────────────────────────────────────────
$rev = ['sub_xaf' => 0, 'promo_xaf' => 0, 'total_xaf' => 0, 'orders' => 0, 'pending_xaf' => 0];
try {
    $rq = db()->query("
        SELECT
            product_type,
            SUM(CASE WHEN payment_status='paid' THEN net_xaf ELSE 0 END) AS paid_xaf,
            SUM(CASE WHEN payment_status='pending' THEN net_xaf ELSE 0 END) AS pending_xaf,
            COUNT(*) AS cnt
        FROM monetisation_orders mo
        WHERE $dateClauseRaw
        GROUP BY product_type
    ");
    foreach ($rq->fetchAll() as $row) {
        if ($row['product_type'] === 'subscription') $rev['sub_xaf']   = (int)$row['paid_xaf'];
        if ($row['product_type'] === 'promotion')    $rev['promo_xaf'] = (int)$row['paid_xaf'];
        $rev['pending_xaf'] += (int)$row['pending_xaf'];
        $rev['orders']      += (int)$row['cnt'];
    }
    $rev['total_xaf'] = $rev['sub_xaf'] + $rev['promo_xaf'];
} catch (Exception $e) {}

// ── Commission liability ────────────────────────────────────────────────────────
$comm = ['pending_xaf' => 0, 'approved_xaf' => 0, 'paid_xaf' => 0, 'total_xaf' => 0];
try {
    $cq = db()->query("
        SELECT status,
               SUM(commission_amount_xaf) AS xaf
        FROM partner_commissions pc
        WHERE $dateClausePc AND reversed_at IS NULL
        GROUP BY status
    ");
    foreach ($cq->fetchAll() as $row) {
        if (isset($comm[$row['status'] . '_xaf'])) $comm[$row['status'] . '_xaf'] = (int)$row['xaf'];
        $comm['total_xaf'] += (int)$row['xaf'];
    }
} catch (Exception $e) {}

// ── Active subscriptions by plan ───────────────────────────────────────────────
$subsByPlan = [];
try {
    $sq = db()->query("
        SELECT sp.name_en, sp.price_xaf, COUNT(bs.id) AS cnt
        FROM business_subscriptions bs
        JOIN subscription_plans sp ON sp.id = bs.plan_id
        WHERE bs.status = 'active'
        GROUP BY bs.plan_id
        ORDER BY sp.sort_order
    ");
    $subsByPlan = $sq->fetchAll();
} catch (Exception $e) {}

$totalActiveSubs = array_sum(array_column($subsByPlan, 'cnt'));

// ── Pending items needing admin action ─────────────────────────────────────────
$pending = ['subs' => 0, 'promos' => 0, 'payouts' => 0, 'commissions' => 0];
try {
    $pending['subs']       = (int)db()->query("SELECT COUNT(*) FROM business_subscriptions WHERE status='pending_payment'")->fetchColumn();
    $pending['promos']     = (int)db()->query("SELECT COUNT(*) FROM promotion_orders WHERE promo_status='pending_approval'")->fetchColumn();
    $pending['payouts']    = (int)db()->query("SELECT COUNT(*) FROM partner_payout_batches WHERE status='approved'")->fetchColumn();
    $pending['commissions']= (int)db()->query("SELECT COUNT(*) FROM partner_commissions WHERE status='pending' AND reversed_at IS NULL")->fetchColumn();
} catch (Exception $e) {}

// ── Top 5 partners by commission earned ────────────────────────────────────────
$topPartners = [];
try {
    $tpq = db()->query("
        SELECT u.name, pp.commission_rate,
               SUM(pc.commission_amount_xaf) AS earned_xaf,
               COUNT(pc.id) AS orders
        FROM partner_commissions pc
        JOIN partner_profiles pp ON pp.id = pc.user_id
        JOIN users u ON u.id = pp.user_id
        WHERE pc.reversed_at IS NULL
        GROUP BY pc.user_id
        ORDER BY earned_xaf DESC
        LIMIT 5
    ");
    $topPartners = $tpq->fetchAll();
} catch (Exception $e) {}

// ── Top 5 listings by subscription revenue ─────────────────────────────────────
$topListings = [];
try {
    $tlq = db()->query("
        SELECT l.title, l.slug, sp.name_en AS plan,
               COUNT(mo.id) AS orders,
               SUM(mo.net_xaf) AS revenue_xaf
        FROM monetisation_orders mo
        JOIN listings l ON l.id = mo.listing_id
        LEFT JOIN business_subscriptions bs ON bs.id = mo.linked_id AND mo.product_type='subscription'
        LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
        WHERE mo.payment_status = 'paid' AND mo.product_type = 'subscription'
        GROUP BY mo.listing_id
        ORDER BY revenue_xaf DESC
        LIMIT 5
    ");
    $topListings = $tlq->fetchAll();
} catch (Exception $e) {}

// ── Monthly revenue trend (last 6 months) ─────────────────────────────────────
$trend = [];
try {
    $tq = db()->query("
        SELECT DATE_FORMAT(payment_confirmed_at, '%Y-%m') AS mo,
               SUM(CASE WHEN product_type='subscription' THEN net_xaf ELSE 0 END) AS sub_xaf,
               SUM(CASE WHEN product_type='promotion'    THEN net_xaf ELSE 0 END) AS promo_xaf
        FROM monetisation_orders
        WHERE payment_status = 'paid'
          AND payment_confirmed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY mo
        ORDER BY mo ASC
    ");
    $trend = $tq->fetchAll();
} catch (Exception $e) {}

// ── Recent paid orders ─────────────────────────────────────────────────────────
$recentOrders = [];
try {
    $roq = db()->query("
        SELECT mo.ref, mo.product_type, mo.net_xaf, mo.payment_confirmed_at,
               u.name AS user_name, l.title AS listing_title
        FROM monetisation_orders mo
        JOIN users u ON u.id = mo.user_id
        JOIN listings l ON l.id = mo.listing_id
        WHERE mo.payment_status = 'paid'
        ORDER BY mo.payment_confirmed_at DESC
        LIMIT 10
    ");
    $recentOrders = $roq->fetchAll();
} catch (Exception $e) {}
?>

<div class="admin-page-header">
  <div>
    <h1>💹 Revenue Overview</h1>
    <p>Monetisation analytics &amp; admin oversight — Phase 3</p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <?php foreach ($periods as $val => $label): ?>
    <a href="?period=<?= $val ?>"
       style="padding:.4rem .9rem;border-radius:6px;font-size:.8rem;font-weight:600;text-decoration:none;
              <?= $period === $val ? 'background:var(--accent);color:#fff;' : 'background:rgba(255,255,255,.06);color:var(--muted);border:1px solid var(--border);' ?>">
      <?= $label ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (array_sum($pending) > 0): ?>
<div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;">
  <strong style="color:#f59e0b;">⚠️ Action required:</strong>
  <?php if ($pending['subs']): ?>
    <a href="<?= SITE_URL ?>/admin/subscriptions?status=pending_payment" style="color:#f59e0b;font-weight:600;"><?= $pending['subs'] ?> subscription<?= $pending['subs'] > 1 ? 's' : '' ?> pending payment</a> ·
  <?php endif; ?>
  <?php if ($pending['promos']): ?>
    <a href="<?= SITE_URL ?>/admin/promotion-orders?status=pending_approval" style="color:#f59e0b;font-weight:600;"><?= $pending['promos'] ?> promo<?= $pending['promos'] > 1 ? 's' : '' ?> pending approval</a> ·
  <?php endif; ?>
  <?php if ($pending['commissions']): ?>
    <a href="<?= SITE_URL ?>/admin/commissions" style="color:#f59e0b;font-weight:600;"><?= $pending['commissions'] ?> commission<?= $pending['commissions'] > 1 ? 's' : '' ?> pending</a> ·
  <?php endif; ?>
  <?php if ($pending['payouts']): ?>
    <a href="<?= SITE_URL ?>/admin/commissions?tab=batches" style="color:#f59e0b;font-weight:600;"><?= $pending['payouts'] ?> payout batch<?= $pending['payouts'] > 1 ? 'es' : '' ?> ready</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Revenue KPI tiles ──────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem;">
  <?php
  $tiles = [
    ['Total Revenue', number_format($rev['total_xaf']) . ' XAF', '💹', '#00A878'],
    ['Subscriptions', number_format($rev['sub_xaf'])   . ' XAF', '⭐', '#3b82f6'],
    ['Promotions',    number_format($rev['promo_xaf']) . ' XAF', '📣', '#7c3aed'],
    ['Pending Income',number_format($rev['pending_xaf']). ' XAF','⏳', '#f59e0b'],
    ['Active Subs',   $totalActiveSubs . ' listings',            '🔄', '#00A878'],
    ['Total Orders',  $rev['orders'],                            '📦', 'var(--muted)'],
  ];
  foreach ($tiles as [$label, $val, $icon, $color]): ?>
  <div class="admin-card" style="text-align:center;padding:1.1rem;">
    <div style="font-size:1.4rem;margin-bottom:.3rem;"><?= $icon ?></div>
    <div style="font-size:1.25rem;font-weight:900;color:<?= $color ?>;font-family:'Fraunces',serif;"><?= $val ?></div>
    <div style="font-size:.72rem;color:var(--muted);margin-top:.2rem;"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Commission liability ───────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:2rem;">
  <?php
  $ctiles = [
    ['Commissions Pending', number_format($comm['pending_xaf'])  . ' XAF', '#f59e0b'],
    ['Approved (unpaid)',   number_format($comm['approved_xaf']) . ' XAF', '#3b82f6'],
    ['Paid Out',            number_format($comm['paid_xaf'])     . ' XAF', '#00A878'],
    ['Total Liability',     number_format($comm['total_xaf'])    . ' XAF', 'var(--fg)'],
  ];
  foreach ($ctiles as [$label, $val, $color]): ?>
  <div class="admin-card" style="padding:1rem;">
    <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:.4rem;">💰 <?= $label ?></div>
    <div style="font-size:1.15rem;font-weight:900;color:<?= $color ?>;"><?= $val ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:2rem;">

  <!-- Active subs by plan -->
  <div class="admin-card">
    <h3 style="margin:0 0 1rem;font-size:.95rem;">⭐ Active Subscriptions by Plan</h3>
    <?php if (!$subsByPlan): ?>
      <p style="color:var(--muted);font-size:.85rem;">No active subscriptions yet.</p>
    <?php else: ?>
    <table class="admin-table" style="font-size:.83rem;">
      <thead><tr><th>Plan</th><th>Price/mo</th><th style="text-align:right;">Active</th></tr></thead>
      <tbody>
      <?php foreach ($subsByPlan as $row): ?>
      <tr>
        <td><?= e($row['name_en']) ?></td>
        <td style="color:var(--muted);"><?= number_format($row['price_xaf']) ?> XAF</td>
        <td style="text-align:right;font-weight:700;"><?= (int)$row['cnt'] ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div style="margin-top:.75rem;">
      <a href="<?= SITE_URL ?>/admin/subscriptions" style="font-size:.8rem;color:var(--accent);">Manage subscriptions →</a>
    </div>
  </div>

  <!-- Top partners -->
  <div class="admin-card">
    <h3 style="margin:0 0 1rem;font-size:.95rem;">🏆 Top Partners by Earnings</h3>
    <?php if (!$topPartners): ?>
      <p style="color:var(--muted);font-size:.85rem;">No commission data yet.</p>
    <?php else: ?>
    <table class="admin-table" style="font-size:.83rem;">
      <thead><tr><th>Partner</th><th>Rate</th><th style="text-align:right;">Earned</th></tr></thead>
      <tbody>
      <?php foreach ($topPartners as $row): ?>
      <tr>
        <td><?= e($row['name']) ?></td>
        <td style="color:var(--muted);"><?= number_format($row['commission_rate'], 1) ?>%</td>
        <td style="text-align:right;font-weight:700;color:var(--accent);"><?= number_format($row['earned_xaf']) ?> XAF</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div style="margin-top:.75rem;">
      <a href="<?= SITE_URL ?>/admin/commissions" style="font-size:.8rem;color:var(--accent);">Manage commissions →</a>
    </div>
  </div>

</div>

<!-- Monthly revenue trend -->
<?php if ($trend): ?>
<div class="admin-card" style="margin-bottom:2rem;">
  <h3 style="margin:0 0 1rem;font-size:.95rem;">📈 Monthly Revenue (last 6 months)</h3>
  <table class="admin-table" style="font-size:.83rem;">
    <thead>
      <tr>
        <th>Month</th>
        <th style="text-align:right;">Subscriptions</th>
        <th style="text-align:right;">Promotions</th>
        <th style="text-align:right;">Total</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($trend as $row): ?>
    <tr>
      <td><?= date('M Y', strtotime($row['mo'] . '-01')) ?></td>
      <td style="text-align:right;"><?= number_format($row['sub_xaf']) ?> XAF</td>
      <td style="text-align:right;"><?= number_format($row['promo_xaf']) ?> XAF</td>
      <td style="text-align:right;font-weight:700;"><?= number_format($row['sub_xaf'] + $row['promo_xaf']) ?> XAF</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Top listings by sub revenue -->
<?php if ($topListings): ?>
<div class="admin-card" style="margin-bottom:2rem;">
  <h3 style="margin:0 0 1rem;font-size:.95rem;">📋 Top Listings by Subscription Revenue</h3>
  <table class="admin-table" style="font-size:.83rem;">
    <thead><tr><th>Listing</th><th>Plan</th><th style="text-align:right;">Orders</th><th style="text-align:right;">Revenue</th></tr></thead>
    <tbody>
    <?php foreach ($topListings as $row): ?>
    <tr>
      <td><a href="<?= SITE_URL ?>/listing/<?= e($row['slug']) ?>" target="_blank" style="color:var(--accent);"><?= e($row['title']) ?></a></td>
      <td style="color:var(--muted);"><?= e($row['plan'] ?? '—') ?></td>
      <td style="text-align:right;"><?= (int)$row['orders'] ?></td>
      <td style="text-align:right;font-weight:700;"><?= number_format($row['revenue_xaf']) ?> XAF</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Recent paid orders -->
<div class="admin-card" style="margin-bottom:2rem;">
  <h3 style="margin:0 0 1rem;font-size:.95rem;">🧾 Recent Paid Orders</h3>
  <?php if (!$recentOrders): ?>
    <p style="color:var(--muted);font-size:.85rem;">No paid orders yet. Run the Phase 3 migration and start accepting payments.</p>
  <?php else: ?>
  <table class="admin-table" style="font-size:.83rem;">
    <thead>
      <tr>
        <th>Ref</th>
        <th>Type</th>
        <th>Business</th>
        <th>User</th>
        <th style="text-align:right;">Amount</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($recentOrders as $row): ?>
    <tr>
      <td><code style="font-size:.75rem;"><?= e($row['ref']) ?></code></td>
      <td>
        <span style="padding:.15rem .5rem;border-radius:4px;font-size:.72rem;font-weight:600;
          <?= $row['product_type']==='subscription' ? 'background:rgba(59,130,246,.12);color:#3b82f6;' : 'background:rgba(109,40,217,.12);color:#7c3aed;' ?>">
          <?= ucfirst($row['product_type']) ?>
        </span>
      </td>
      <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($row['listing_title']) ?></td>
      <td style="color:var(--muted);"><?= e($row['user_name']) ?></td>
      <td style="text-align:right;font-weight:700;"><?= number_format($row['net_xaf']) ?> XAF</td>
      <td style="color:var(--muted);white-space:nowrap;"><?= $row['payment_confirmed_at'] ? date('d M Y', strtotime($row['payment_confirmed_at'])) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Quick links -->
<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:2rem;">
  <a href="<?= SITE_URL ?>/admin/subscriptions" class="btn btn-secondary btn-sm">⭐ Subscriptions</a>
  <a href="<?= SITE_URL ?>/admin/promotion-orders" class="btn btn-secondary btn-sm">📣 Promo Orders</a>
  <a href="<?= SITE_URL ?>/admin/commissions" class="btn btn-secondary btn-sm">💰 Commissions</a>
  <a href="<?= SITE_URL ?>/admin/subscription-plans" class="btn btn-secondary btn-sm">📋 Sub Plans</a>
  <a href="<?= SITE_URL ?>/admin/promotion-products" class="btn btn-secondary btn-sm">📦 Promo Products</a>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
