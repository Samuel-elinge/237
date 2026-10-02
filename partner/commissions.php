<?php
/**
 * partner/commissions.php — Commission Records
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$pdo            = db();

$statusFilter = $_GET['status'] ?? 'all';
$year         = (int)($_GET['year'] ?? date('Y'));
$month        = (int)($_GET['month'] ?? 0);

// Summary stats
$summaryStats = $pdo->prepare("
    SELECT
        COALESCE(SUM(amount),0)                                        AS total_earned,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount END),0)       AS total_paid,
        COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0)    AS pending,
        COALESCE(SUM(CASE WHEN status='approved' THEN amount END),0)   AS approved,
        COUNT(*)                                                        AS total_records,
        COALESCE(SUM(CASE WHEN MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) THEN amount END),0) AS this_month
    FROM partner_commissions
    WHERE partner_id=?
");
$summaryStats->execute([$partnerId]);
$stats = $summaryStats->fetch();

// Build query
$where  = ["pc.partner_id = ?"];
$params = [$partnerId];

if (in_array($statusFilter, ['pending','approved','paid','cancelled','disputed'])) {
    $where[] = "pc.status = ?";
    $params[] = $statusFilter;
}
if ($year) {
    $where[] = "YEAR(pc.created_at) = ?";
    $params[] = $year;
}
if ($month) {
    $where[] = "MONTH(pc.created_at) = ?";
    $params[] = $month;
}

$st = $pdo->prepare("
    SELECT pc.*, l.title AS listing_title, cr.name AS rule_name
    FROM partner_commissions pc
    LEFT JOIN listings l ON l.id = pc.listing_id
    LEFT JOIN commission_rules cr ON cr.id = pc.rule_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pc.created_at DESC
");
$st->execute($params);
$commissions = $st->fetchAll();

// Monthly breakdown for current year
$monthly = $pdo->prepare("
    SELECT MONTH(created_at) AS m, SUM(amount) AS total, COUNT(*) AS count, currency
    FROM partner_commissions
    WHERE partner_id=? AND YEAR(created_at)=? AND status IN ('approved','paid')
    GROUP BY MONTH(created_at), currency
    ORDER BY m ASC
");
$monthly->execute([$partnerId, $year]);
$monthlyData = $monthly->fetchAll();

$pageTitle = 'Commissions — Partner Centre';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.partner-wrap { max-width:1280px; margin:0 auto; padding:2rem 1.5rem; }
.partner-nav { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:2rem; }
.partner-nav a { padding:0.45rem 1rem; border-radius:9px; font-size:0.875rem; font-weight:600;
  text-decoration:none; background:var(--card); border:1px solid var(--border); color:var(--text); transition:all .15s; }
.partner-nav a:hover, .partner-nav a.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:1rem; margin-bottom:2rem; }
.stat-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:1rem 1.25rem; text-align:center; }
.stat-card .n { font-size:1.5rem; font-weight:800; font-family:'Fraunces',serif; }
.stat-card .l { font-size:0.75rem; color:var(--muted); margin-top:0.2rem; }

.status-tab { padding:0.35rem 0.9rem; border-radius:20px; border:1px solid var(--border);
  font-size:0.8rem; cursor:pointer; background:var(--card); color:var(--text); text-decoration:none; }
.status-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.comm-table { width:100%; border-collapse:collapse; }
.comm-table th, .comm-table td { padding:0.75rem 1rem; text-align:left; border-bottom:1px solid var(--border); font-size:0.875rem; }
.comm-table th { font-size:0.75rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); background:var(--card); }

.cs-pending  { background:rgba(252,209,22,.2); color:#b8960f; padding:2px 8px; border-radius:20px; font-size:0.72rem; font-weight:700; }
.cs-approved { background:rgba(0,168,120,.15); color:#00A878; padding:2px 8px; border-radius:20px; font-size:0.72rem; font-weight:700; }
.cs-paid     { background:rgba(46,196,182,.15); color:#1a8f88; padding:2px 8px; border-radius:20px; font-size:0.72rem; font-weight:700; }
.cs-cancelled{ background:rgba(150,150,150,.15); color:var(--muted); padding:2px 8px; border-radius:20px; font-size:0.72rem; font-weight:700; }
.cs-disputed { background:rgba(230,57,70,.12); color:#e63946; padding:2px 8px; border-radius:20px; font-size:0.72rem; font-weight:700; }

.monthly-bar { display:flex; align-items:flex-end; gap:4px; height:80px; margin-top:0.75rem; }
.month-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:2px; }
.month-col .bar { width:100%; background:var(--primary); border-radius:3px 3px 0 0; min-height:2px; }
.month-col .lbl { font-size:0.6rem; color:var(--muted); }
</style>

<div class="partner-wrap">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
    <h1 style="font-family:'Fraunces',serif; font-size:2rem; font-weight:900; margin:0;">💰 Commissions</h1>
  </div>

  <nav class="partner-nav">
    <a href="<?= SITE_URL ?>/partner/dashboard">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/partner/portfolio">📋 Portfolio</a>
    <a href="<?= SITE_URL ?>/partner/tasks">✅ Tasks</a>
    <a href="<?= SITE_URL ?>/partner/leads">💬 Leads</a>
    <a href="<?= SITE_URL ?>/partner/commissions" class="active">💰 Commissions</a>
  </nav>

  <!-- Summary stats -->
  <div class="stat-grid">
    <div class="stat-card" style="border-color:#00A878;">
      <div class="n" style="color:#00A878;"><?= number_format($stats['this_month']) ?></div>
      <div class="l">This Month (XAF)</div>
    </div>
    <div class="stat-card">
      <div class="n"><?= number_format($stats['total_paid']) ?></div>
      <div class="l">Total Paid (XAF)</div>
    </div>
    <div class="stat-card" style="border-color:#fcd116;">
      <div class="n" style="color:#b8960f;"><?= number_format($stats['pending']) ?></div>
      <div class="l">Pending (XAF)</div>
    </div>
    <div class="stat-card">
      <div class="n" style="color:#2ec4b6;"><?= number_format($stats['approved']) ?></div>
      <div class="l">Approved (XAF)</div>
    </div>
    <div class="stat-card">
      <div class="n"><?= (int)$stats['total_records'] ?></div>
      <div class="l">Total Records</div>
    </div>
  </div>

  <!-- Monthly bar chart (current year) -->
  <?php if ($monthlyData): ?>
  <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:1.25rem; margin-bottom:1.5rem;">
    <div style="font-weight:700; font-size:0.9rem; margin-bottom:0.75rem;">📊 Monthly Earnings <?= $year ?></div>
    <?php
    $maxMonthly = max(array_column($monthlyData, 'total')) ?: 1;
    $monthByNum = [];
    foreach ($monthlyData as $md) $monthByNum[(int)$md['m']] = (float)$md['total'];
    $months = ['J','F','M','A','M','J','J','A','S','O','N','D'];
    ?>
    <div class="monthly-bar">
      <?php for ($m = 1; $m <= 12; $m++):
        $val = $monthByNum[$m] ?? 0;
        $pct = $val ? round(($val / $maxMonthly) * 100) : 0;
      ?>
      <div class="month-col">
        <div class="bar" style="height:<?= $pct ?>%; opacity:<?= $val ? '1' : '0.15' ?>;" title="<?= number_format($val) ?> XAF"></div>
        <div class="lbl"><?= $months[$m-1] ?></div>
      </div>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Filters -->
  <div style="display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:1rem;">
    <?php
    $tabs = ['all'=>'All','pending'=>'Pending','approved'=>'Approved','paid'=>'Paid','disputed'=>'Disputed'];
    foreach ($tabs as $tk => $tl): ?>
    <a href="?status=<?= $tk ?>&year=<?= $year ?><?= $month ? '&month='.$month : '' ?>" class="status-tab <?= $statusFilter===$tk?'active':'' ?>"><?= $tl ?></a>
    <?php endforeach; ?>
  </div>

  <form method="GET" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <select name="year" style="padding:0.4rem; border:1px solid var(--border); border-radius:8px; background:var(--card); color:var(--text); font-size:0.875rem;">
      <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
      <option value="<?= $y ?>" <?= $year===$y?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <select name="month" style="padding:0.4rem; border:1px solid var(--border); border-radius:8px; background:var(--card); color:var(--text); font-size:0.875rem;">
      <option value="0">All Months</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?= $m ?>" <?= $month===$m?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
      <?php endfor; ?>
    </select>
    <button type="submit" class="btn btn-primary" style="font-size:0.85rem;">Filter</button>
  </form>

  <!-- Commission table -->
  <div style="background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden;">
    <div style="overflow-x:auto;">
      <table class="comm-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Description</th>
            <th>Business</th>
            <th>Type</th>
            <th style="text-align:right;">Amount</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($commissions): ?>
            <?php foreach ($commissions as $c): ?>
            <tr>
              <td style="white-space:nowrap;"><?= date('j M Y', strtotime($c['created_at'])) ?></td>
              <td>
                <?= e($c['source'] ?: $c['rule_name'] ?: 'Commission') ?>
                <?php if ($c['transaction_ref']): ?>
                  <div style="font-size:0.75rem; color:var(--muted);">Ref: <?= e($c['transaction_ref']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= $c['listing_title'] ? e($c['listing_title']) : '—' ?></td>
              <td style="font-size:0.8rem;"><?= e(ucfirst(str_replace('_',' ',$c['commission_type']))) ?></td>
              <td style="text-align:right; font-weight:700; font-family:'Fraunces',serif;">
                <?= number_format($c['amount']) ?> <?= e($c['currency']) ?>
              </td>
              <td><span class="cs-<?= $c['status'] ?>"><?= $c['status'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" style="text-align:center; padding:3rem; color:var(--muted);">No commission records found.</td></tr>
          <?php endif; ?>
        </tbody>
        <?php if ($commissions): ?>
        <tfoot>
          <tr style="font-weight:700; background:var(--card);">
            <td colspan="4" style="padding:0.75rem 1rem;">Total shown</td>
            <td style="text-align:right; padding:0.75rem 1rem; font-family:'Fraunces',serif;">
              <?= number_format(array_sum(array_column($commissions, 'amount'))) ?> XAF
            </td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
