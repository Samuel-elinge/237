<?php
/**
 * admin/market-penetration.php — Market Penetration Dashboard (Task 57)
 *
 * Strategic view of 237biz market penetration: active listings vs total addressable
 * market by region and category; gap analysis; partner coverage vs business density.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
requireAdmin();

// ── Filters ───────────────────────────────────────────────
$filterRegion   = trim($_GET['region'] ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$period         = in_array($_GET['period'] ?? '30', ['7','30','90','365']) ? $_GET['period'] : '30';

// ── Overall platform stats ─────────────────────────────────
$platformStats = $pdo->query("
    SELECT
        COUNT(*) AS total_listings,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_listings,
        SUM(CASE WHEN status='active' AND created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY) THEN 1 ELSE 0 END) AS new_listings,
        COUNT(DISTINCT city) AS cities_covered,
        COUNT(DISTINCT category_id) AS categories_covered
    FROM listings
")->fetch();

$partnerStats = $pdo->query("
    SELECT
        COUNT(*) AS total_partners,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_partners,
        SUM(active_clients) AS total_clients,
        SUM(monthly_revenue) AS total_revenue
    FROM partner_profiles
")->fetch();

// ── Category penetration ───────────────────────────────────
$categoryPen = $pdo->query("
    SELECT c.name AS category,
           COUNT(l.id) AS listings_count,
           SUM(CASE WHEN l.status='active' THEN 1 ELSE 0 END) AS active_count,
           ROUND(AVG(CASE WHEN l.status='active' THEN 1 ELSE 0 END)*100,1) AS active_pct
    FROM categories c
    LEFT JOIN listings l ON l.category_id = c.id
    GROUP BY c.id, c.name
    ORDER BY active_count DESC
    LIMIT 20
")->fetchAll();

// ── Regional penetration ───────────────────────────────────
$regionalPen = $pdo->query("
    SELECT
        COALESCE(l.county, l.city, 'Unknown') AS region,
        COUNT(l.id) AS listings_count,
        SUM(CASE WHEN l.status='active' THEN 1 ELSE 0 END) AS active_count,
        COUNT(DISTINCT pra.partner_id) AS partner_count
    FROM listings l
    LEFT JOIN partner_regions pr ON pr.name = COALESCE(l.county, l.city)
    LEFT JOIN partner_region_assignments pra ON pra.region_id = pr.id
    GROUP BY COALESCE(l.county, l.city)
    ORDER BY active_count DESC
    LIMIT 20
")->fetchAll();

// ── Growth trend (new listings by day/week) ────────────────
$trendSQL = $period <= 30
    ? "SELECT DATE(created_at) AS period_label, COUNT(*) AS cnt FROM listings WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY) AND status='active' GROUP BY DATE(created_at) ORDER BY period_label ASC"
    : "SELECT DATE_FORMAT(created_at,'%Y-%u') AS period_label, MIN(DATE(created_at)) AS period_start, COUNT(*) AS cnt FROM listings WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY) AND status='active' GROUP BY DATE_FORMAT(created_at,'%Y-%u') ORDER BY period_label ASC";

$trendRows = $pdo->query($trendSQL)->fetchAll();
$trendLabels = array_map(fn($r) => $r['period_label'], $trendRows);
$trendValues = array_map(fn($r) => (int)$r['cnt'], $trendRows);

// ── Uncovered areas (regions with partner demand but no/few partners) ──
$gaps = $pdo->query("
    SELECT r.name AS region, r.target_partners,
           COUNT(DISTINCT pra.partner_id) AS assigned,
           (r.target_partners - COUNT(DISTINCT pra.partner_id)) AS gap
    FROM partner_regions r
    LEFT JOIN partner_region_assignments pra ON pra.region_id = r.id
    WHERE r.is_active = 1 AND r.target_partners > 0
    GROUP BY r.id
    HAVING gap > 0
    ORDER BY gap DESC
    LIMIT 10
")->fetchAll();

// ── Partner-to-business density ────────────────────────────
$density = $pdo->query("
    SELECT c.name AS category,
           COUNT(DISTINCT l.id) AS businesses,
           COUNT(DISTINCT pp.id) AS partners,
           CASE WHEN COUNT(DISTINCT pp.id) > 0 THEN ROUND(COUNT(DISTINCT l.id)/COUNT(DISTINCT pp.id),1) ELSE NULL END AS biz_per_partner
    FROM categories c
    LEFT JOIN listings l ON l.category_id = c.id AND l.status = 'active'
    LEFT JOIN partner_profiles pp ON pp.status = 'active'
        AND JSON_CONTAINS(pp.specialisms, JSON_QUOTE(c.slug))
    GROUP BY c.id, c.name
    HAVING businesses > 0
    ORDER BY businesses DESC
    LIMIT 15
")->fetchAll();

$pageTitle = 'Market Penetration';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:1200px;margin:0 auto;padding:1.5rem}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;margin-bottom:1.5rem}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:.9rem 1.1rem}
.stat-val{font-size:1.75rem;font-weight:800;color:#111827}
.stat-sub{font-size:.78rem;color:#059669;font-weight:600}
.stat-lbl{font-size:.72rem;color:#9ca3af;margin-top:.1rem}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden}
.card-header{padding:.75rem 1.1rem;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.card-header h2{margin:0;font-size:.88rem;font-weight:700;color:#111827;text-transform:uppercase;letter-spacing:.04em}
.card-body{padding:1rem}
.pen-row{display:flex;align-items:center;gap:.6rem;padding:.4rem 0;border-bottom:1px solid #f3f4f6;font-size:.82rem}
.pen-row:last-child{border-bottom:none}
.pen-name{flex:1;color:#374151;font-weight:500}
.pen-bar-wrap{width:120px;height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden}
.pen-bar{height:100%;border-radius:99px;background:#3b82f6}
.pen-pct{width:42px;text-align:right;font-size:.75rem;color:#6b7280}
.pen-count{width:40px;text-align:right;font-size:.75rem;color:#9ca3af}
.gap-row{display:flex;align-items:center;padding:.45rem .75rem;border-bottom:1px solid #f3f4f6;font-size:.82rem}
.gap-row:last-child{border-bottom:none}
.gap-region{flex:1;font-weight:500;color:#374151}
.gap-number{font-size:1rem;font-weight:700;color:#dc2626}
.gap-label{font-size:.72rem;color:#9ca3af}
.chart-wrap{padding:.75rem 1rem}
.chart-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:.5rem}
.sparkline{display:flex;align-items:flex-end;gap:2px;height:70px}
.sp-bar{flex:1;background:#3b82f6;border-radius:2px 2px 0 0;min-height:2px;position:relative}
.sp-bar:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);background:#111827;color:#fff;font-size:.65rem;padding:.15rem .35rem;border-radius:.3rem;white-space:nowrap;z-index:1}
.filter-bar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:1.25rem;background:#fff;border:1px solid #e5e7eb;border-radius:.6rem;padding:.6rem .85rem}
.filter-bar label{font-size:.75rem;font-weight:700;color:#6b7280}
.filter-bar select{border:1px solid #e5e7eb;border-radius:.4rem;padding:.3rem .6rem;font-size:.8rem}
.btn{padding:.35rem .75rem;border-radius:.4rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block}
.btn-primary{background:#2563eb;color:#fff}
.btn-ghost{background:#f3f4f6;color:#374151}
.badge{font-size:.68rem;font-weight:600;padding:.12rem .4rem;border-radius:99px}
.badge-red{background:#fee2e2;color:#991b1b}
.density-table{width:100%;border-collapse:collapse;font-size:.8rem}
.density-table th{text-align:left;padding:.45rem .7rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#9ca3af;border-bottom:2px solid #e5e7eb}
.density-table td{padding:.5rem .7rem;border-bottom:1px solid #f3f4f6;color:#374151}
.density-table tr:hover td{background:#f9fafb}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827">Market Penetration</h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280">Platform coverage, growth trends and gap analysis</p>
        </div>
        <a href="<?= SITE_URL ?>/admin" class="btn btn-ghost">← Admin</a>
    </div>

    <!-- Period filter -->
    <form method="get" action="" class="filter-bar">
        <label>Period:</label>
        <?php foreach (['7'=>'7 days','30'=>'30 days','90'=>'90 days','365'=>'1 year'] as $v => $l): ?>
        <a href="?period=<?= $v ?>" class="btn <?= $period==$v?'btn-primary':'btn-ghost' ?>"><?= $l ?></a>
        <?php endforeach ?>
    </form>

    <!-- Platform KPIs -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-val"><?= number_format($platformStats['active_listings']) ?></div>
            <div class="stat-sub">+<?= $platformStats['new_listings'] ?> this period</div>
            <div class="stat-lbl">Active Listings</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $platformStats['cities_covered'] ?></div>
            <div class="stat-lbl">Cities Covered</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $platformStats['categories_covered'] ?></div>
            <div class="stat-lbl">Categories Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $partnerStats['active_partners'] ?></div>
            <div class="stat-lbl">Active Partners</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $partnerStats['total_clients'] ?></div>
            <div class="stat-lbl">Clients Managed</div>
        </div>
        <div class="stat-card">
            <div class="stat-val">£<?= number_format($partnerStats['total_revenue']/1000, 1) ?>k</div>
            <div class="stat-lbl">Partner Revenue</div>
        </div>
    </div>

    <!-- Growth trend chart -->
    <?php if ($trendRows): ?>
    <div class="card" style="margin-bottom:1.25rem">
        <div class="chart-wrap">
            <div class="chart-title">New Active Listings — Last <?= $period ?> Days</div>
            <?php $maxTrend = max(1, max($trendValues)); ?>
            <div class="sparkline">
                <?php foreach ($trendValues as $i => $v): ?>
                <div class="sp-bar" style="height:<?= round($v/$maxTrend*100) ?>%" data-tip="<?= e($trendLabels[$i]) ?>: <?= $v ?>"></div>
                <?php endforeach ?>
            </div>
        </div>
    </div>
    <?php endif ?>

    <div class="grid-2">
        <!-- Category penetration -->
        <div class="card">
            <div class="card-header"><h2>By Category</h2><span style="font-size:.72rem;color:#9ca3af">Active / Total</span></div>
            <div class="card-body">
                <?php foreach ($categoryPen as $row): ?>
                <div class="pen-row">
                    <div class="pen-name"><?= e($row['category']) ?></div>
                    <div class="pen-count"><?= $row['active_count'] ?></div>
                    <div class="pen-bar-wrap"><div class="pen-bar" style="width:<?= min(100,$row['active_pct']) ?>%"></div></div>
                    <div class="pen-pct"><?= $row['active_pct'] ?>%</div>
                </div>
                <?php endforeach ?>
            </div>
        </div>

        <!-- Regional penetration -->
        <div class="card">
            <div class="card-header"><h2>By Region / City</h2><span style="font-size:.72rem;color:#9ca3af">Active listings</span></div>
            <div class="card-body">
                <?php foreach ($regionalPen as $row): ?>
                <div class="pen-row">
                    <div class="pen-name">
                        <?= e($row['region']) ?>
                        <?php if ($row['partner_count'] == 0): ?>
                        <span class="badge badge-red" style="margin-left:.3rem">No partner</span>
                        <?php endif ?>
                    </div>
                    <div class="pen-count"><?= $row['active_count'] ?></div>
                    <?php $maxReg = max(1, max(array_column($regionalPen,'active_count'))); ?>
                    <div class="pen-bar-wrap"><div class="pen-bar" style="width:<?= round($row['active_count']/$maxReg*100) ?>%"></div></div>
                    <div class="pen-pct"><?= $row['partner_count'] ?>p</div>
                </div>
                <?php endforeach ?>
            </div>
        </div>
    </div>

    <div class="grid-2">
        <!-- Gap analysis -->
        <div class="card">
            <div class="card-header"><h2>Partner Gaps</h2><span style="font-size:.72rem;color:#9ca3af">Regions below target</span></div>
            <?php if (!$gaps): ?>
            <div style="padding:2rem;text-align:center;color:#9ca3af;font-size:.83rem">All regions at or above target. 🎉</div>
            <?php else: ?>
            <?php foreach ($gaps as $gap): ?>
            <div class="gap-row">
                <div class="gap-region"><?= e($gap['region']) ?></div>
                <div style="text-align:right">
                    <div class="gap-number">-<?= $gap['gap'] ?></div>
                    <div class="gap-label"><?= $gap['assigned'] ?>/<?= $gap['target_partners'] ?> partners</div>
                </div>
            </div>
            <?php endforeach ?>
            <?php endif ?>
        </div>

        <!-- Partner-to-business density -->
        <div class="card">
            <div class="card-header"><h2>Biz-to-Partner Density</h2></div>
            <div style="overflow-x:auto">
            <table class="density-table">
                <thead><tr><th>Category</th><th>Businesses</th><th>Partners</th><th>Ratio</th></tr></thead>
                <tbody>
                <?php foreach ($density as $row): ?>
                <tr>
                    <td><?= e($row['category']) ?></td>
                    <td><?= $row['businesses'] ?></td>
                    <td><?= $row['partners'] ?: '—' ?></td>
                    <td>
                        <?php if ($row['biz_per_partner']): ?>
                        <span style="color:<?= $row['biz_per_partner'] > 20 ? '#dc2626' : ($row['biz_per_partner'] > 10 ? '#d97706' : '#059669') ?>;font-weight:700">
                            <?= $row['biz_per_partner'] ?>:1
                        </span>
                        <?php else: ?>—<?php endif ?>
                    </td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
