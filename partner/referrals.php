<?php
/**
 * partner/referrals.php — Partner Referral Tracking (Task 53)
 *
 * Partners track referrals generated through their unique referral code.
 * Shows referral links, clicks, conversions, and commission earned from referrals.
 * Distinct from leads (direct) — these are organic referral-link conversions.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$pdo = db();
$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)$_SESSION['user_id'];

$referralCode = $partnerProfile['referral_code'] ?? '';
$referralUrl  = $referralCode ? SITE_URL . '/r/' . $referralCode : '';

// ── Referral stats ────────────────────────────────────────
$stats = $pdo->prepare("
    SELECT
        COUNT(*) AS total_referrals,
        SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) AS converted,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_r,
        SUM(CASE WHEN status IN ('converted') THEN commission_amount ELSE 0 END) AS total_earned,
        SUM(CASE WHEN status IN ('paid') THEN commission_amount ELSE 0 END) AS total_paid,
        SUM(CASE WHEN status IN ('converted','pending') AND commission_amount > 0 THEN commission_amount ELSE 0 END) AS pending_payment
    FROM partner_referrals
    WHERE partner_id = ?
");
$stats->execute([$partnerId]);
$stats = $stats->fetch();

// Click stats from ref tracking
$clicks = $pdo->prepare("SELECT COUNT(*) FROM ref_clicks WHERE partner_id=?");
$clicks->execute([$partnerId]);
$totalClicks = (int)$clicks->fetchColumn();

// Conversion rate
$convRate = $totalClicks > 0 ? round(($stats['converted'] ?? 0) / $totalClicks * 100, 1) : 0;

// ── Filters ───────────────────────────────────────────────
$filterStatus = in_array($_GET['status'] ?? '', ['pending','converted','paid','all']) ? $_GET['status'] : 'all';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

$where  = ['pr.partner_id = ?'];
$params = [$partnerId];

if ($filterStatus !== 'all') { $where[] = 'pr.status = ?'; $params[] = $filterStatus; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM partner_referrals pr $whereSQL");
$total->execute($params);
$total = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$referrals = $pdo->prepare("
    SELECT pr.*,
           u.name AS referred_name, u.email AS referred_email,
           l.business_name, l.slug AS listing_slug
    FROM partner_referrals pr
    LEFT JOIN users u ON u.id = pr.referred_user_id
    LEFT JOIN listings l ON l.id = pr.listing_id
    $whereSQL
    ORDER BY pr.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$referrals->execute($params);
$referrals = $referrals->fetchAll();

// Status counts
$countQ = $pdo->prepare("SELECT status, COUNT(*) AS n FROM partner_referrals WHERE partner_id=? GROUP BY status");
$countQ->execute([$partnerId]);
$counts = $countQ->fetchAll(\PDO::FETCH_KEY_PAIR);
$counts['all'] = array_sum($counts);

// Recent 30-day chart data (clicks by day)
$chartData = $pdo->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM ref_clicks
    WHERE partner_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$chartData->execute([$partnerId]);
$chartRows = $chartData->fetchAll();

// Build sparse → dense chart array
$chartDays  = [];
$chartCnts  = [];
$startDate  = new DateTime('-29 days');
for ($i = 0; $i < 30; $i++) {
    $d = (clone $startDate)->modify("+$i days")->format('Y-m-d');
    $chartDays[] = (clone $startDate)->modify("+$i days")->format('d M');
    $chartCnts[$d] = 0;
}
foreach ($chartRows as $row) { $chartCnts[$row['day']] = (int)$row['cnt']; }
$chartValues = array_values($chartCnts);

$statusColors = ['pending'=>['#fef3c7','#92400e'],'converted'=>['#d1fae5','#065f46'],'paid'=>['#dbeafe','#1d4ed8'],'cancelled'=>['#fee2e2','#991b1b']];

$flash     = getFlash();
$pageTitle = pt('My Referrals');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:860px;margin:0 auto;padding:1.5rem}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-bottom:1.25rem}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:.9rem 1rem;text-align:center}
.stat-val{font-size:1.6rem;font-weight:800;color:#111827}
.stat-lbl{font-size:.72rem;color:#9ca3af;margin-top:.15rem}
.stat-val.green{color:#059669}
.stat-val.blue{color:#2563eb}
.ref-link-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
.ref-url{font-family:monospace;font-size:.875rem;color:#374151;background:#f9fafb;padding:.4rem .75rem;border-radius:.4rem;border:1px solid #e5e7eb;flex:1;word-break:break-all}
.chart-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1rem 1.25rem;margin-bottom:1.25rem}
.chart-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:.75rem}
.bar-chart{display:flex;align-items:flex-end;gap:2px;height:60px}
.bar{flex:1;background:#3b82f6;border-radius:2px 2px 0 0;min-height:2px;position:relative}
.bar:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);background:#111827;color:#fff;font-size:.65rem;padding:.15rem .35rem;border-radius:.3rem;white-space:nowrap;z-index:1}
.bar-labels{display:flex;margin-top:.25rem}
.bar-label{flex:1;text-align:center;font-size:.55rem;color:#9ca3af;overflow:hidden}
.bar-label:nth-child(5n+1){visibility:visible}
.bar-label:not(:nth-child(5n+1)){visibility:hidden}
.tabs{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:1.25rem}
.tab{padding:.55rem 1.1rem;font-size:.875rem;font-weight:500;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;white-space:nowrap}
.tab.active{color:#2563eb;border-color:#2563eb}
.tab .cnt{background:#e5e7eb;font-size:.68rem;border-radius:99px;padding:.1rem .4rem;margin-left:.25rem}
.tab.active .cnt{background:#2563eb;color:#fff}
.ref-table{width:100%;border-collapse:collapse;font-size:.83rem}
.ref-table th{text-align:left;padding:.55rem .75rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#9ca3af;border-bottom:2px solid #e5e7eb}
.ref-table td{padding:.6rem .75rem;border-bottom:1px solid #f3f4f6;color:#374151;vertical-align:middle}
.ref-table tr:hover td{background:#f9fafb}
.badge{font-size:.68rem;font-weight:600;padding:.15rem .45rem;border-radius:99px}
.pager{display:flex;gap:.35rem;justify-content:center;margin-top:1rem}
.pager a,.pager span{padding:.35rem .7rem;border-radius:.4rem;font-size:.8rem;text-decoration:none;border:1px solid #e5e7eb;color:#374151}
.pager .active-page{background:#2563eb;color:#fff;border-color:#2563eb}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.btn-sm{padding:.25rem .55rem;font-size:.72rem}
.btn:hover{opacity:.9}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.empty{text-align:center;padding:3rem;color:#9ca3af}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827"><?= pt('My Referrals') ?></h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280"><?= pt('Track referrals generated through your unique link') ?></p>
        </div>
        <a href="<?= SITE_URL ?>/partner/dashboard" class="btn btn-detail">← <?= pt('Dashboard') ?></a>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <!-- Referral link -->
    <?php if ($referralUrl): ?>
    <div class="ref-link-card">
        <div>
            <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:.3rem"><?= pt('Your Referral Link') ?></div>
            <div class="ref-url"><?= e($referralUrl) ?></div>
        </div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
            <button onclick="navigator.clipboard.writeText('<?= e($referralUrl) ?>').then(()=>{this.textContent='<?= pt('Copied!') ?>';setTimeout(()=>this.textContent='<?= pt('Copy Link') ?>',1500)})" class="btn btn-primary btn-sm"><?= pt('Copy Link') ?></button>
            <a href="<?= SITE_URL ?>/partner/profile?code=<?= urlencode($referralCode) ?>" target="_blank" class="btn btn-detail btn-sm"><?= pt('My Profile →') ?></a>
        </div>
    </div>
    <?php endif ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-val"><?= $totalClicks ?></div>
            <div class="stat-lbl"><?= pt('Link Clicks (30d)') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= (int)($stats['total_referrals'] ?? 0) ?></div>
            <div class="stat-lbl"><?= pt('Total Referrals') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-val green"><?= (int)($stats['converted'] ?? 0) ?></div>
            <div class="stat-lbl"><?= pt('Converted') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $convRate ?>%</div>
            <div class="stat-lbl"><?= pt('Conversion Rate') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-val green">£<?= number_format((float)($stats['total_earned'] ?? 0), 2) ?></div>
            <div class="stat-lbl"><?= pt('Total Earned') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-val blue">£<?= number_format((float)($stats['pending_payment'] ?? 0), 2) ?></div>
            <div class="stat-lbl"><?= pt('Pending Payment') ?></div>
        </div>
    </div>

    <!-- 30-day click chart -->
    <?php if (array_sum($chartValues) > 0): ?>
    <div class="chart-card">
        <div class="chart-title"><?= pt('Referral Link Clicks — Last 30 Days') ?></div>
        <?php $maxVal = max(1, max($chartValues)); ?>
        <div class="bar-chart">
            <?php foreach ($chartValues as $i => $v): ?>
            <div class="bar" style="height:<?= round($v/$maxVal*100) ?>%" data-tip="<?= e($chartDays[$i]) ?>: <?= $v ?>"></div>
            <?php endforeach ?>
        </div>
        <div class="bar-labels">
            <?php foreach ($chartDays as $d): ?>
            <div class="bar-label"><?= e($d) ?></div>
            <?php endforeach ?>
        </div>
    </div>
    <?php endif ?>

    <!-- Tabs -->
    <div class="tabs">
        <?php foreach (['all'=>pt('All'),'pending'=>pt('Pending'),'converted'=>pt('Converted'),'paid'=>pt('Paid')] as $s => $lbl): ?>
        <a href="?status=<?= $s ?>" class="tab<?= $filterStatus===$s ? ' active' : '' ?>">
            <?= $lbl ?>
            <?php if ($cnt = ($counts[$s] ?? 0)): ?><span class="cnt"><?= $cnt ?></span><?php endif ?>
        </a>
        <?php endforeach ?>
    </div>

    <!-- Table -->
    <?php if (!$referrals): ?>
    <div class="empty">
        <div style="font-size:2rem;margin-bottom:.5rem">🔗</div>
        <p><?= $filterStatus !== 'all' ? pt($filterStatus) . ' ' : '' ?><?= pt('referrals yet. Share your link to get started!') ?></p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="ref-table">
        <thead>
            <tr>
                <th><?= pt('Referred') ?></th>
                <th><?= pt('Business') ?></th>
                <th><?= pt('Status') ?></th>
                <th><?= pt('Commission') ?></th>
                <th><?= pt('Date') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($referrals as $ref):
            [$sBg, $sCol] = $statusColors[$ref['status'] ?? 'pending'] ?? $statusColors['pending'];
        ?>
        <tr>
            <td>
                <div style="font-weight:600;color:#111827"><?= $ref['referred_name'] ? e($ref['referred_name']) : '—' ?></div>
                <?php if ($ref['referred_email']): ?><div style="font-size:.72rem;color:#9ca3af"><?= e($ref['referred_email']) ?></div><?php endif ?>
            </td>
            <td>
                <?php if ($ref['business_name']): ?>
                <a href="<?= SITE_URL ?>/listing/<?= e($ref['listing_slug']) ?>" target="_blank" style="color:#2563eb;text-decoration:none"><?= e($ref['business_name']) ?></a>
                <?php else: ?>—<?php endif ?>
            </td>
            <td><span class="badge" style="background:<?= $sBg ?>;color:<?= $sCol ?>"><?= ucfirst($ref['status']) ?></span></td>
            <td><?= $ref['commission_amount'] ? '£'.number_format((float)$ref['commission_amount'], 2) : '—' ?></td>
            <td style="color:#9ca3af"><?= date('d M Y', strtotime($ref['created_at'])) ?></td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pager">
        <?php if ($page > 1): ?><a href="?status=<?= $filterStatus ?>&page=<?= $page-1 ?>"><?= pt('‹ Prev') ?></a><?php endif ?>
        <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
        <a href="?status=<?= $filterStatus ?>&page=<?= $p ?>" class="<?= $p===$page?'active-page':'' ?>"><?= $p ?></a>
        <?php endfor ?>
        <?php if ($page < $pages): ?><a href="?status=<?= $filterStatus ?>&page=<?= $page+1 ?>"><?= pt('Next ›') ?></a><?php endif ?>
    </div>
    <?php endif ?>
    <?php endif ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
