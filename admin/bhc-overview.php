<?php
/**
 * admin/bhc-overview.php — Business Health Check admin overview
 * Clean URL: /admin/bhc-overview
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
require_once __DIR__ . '/../includes/bhc-config.php';
$pdo = db();

// ── Date range ────────────────────────────────────────────────────────────────
$range = $_GET['range'] ?? '30';
$days  = in_array($range, ['7','30','90','365']) ? (int)$range : 30;
$since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

// ── Overall stats ─────────────────────────────────────────────────────────────
$total = (int)$pdo->query("SELECT COUNT(*) FROM bhc_assessments")->fetchColumn();
$periodCount = 0; $avgScore = 0; $completed = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM bhc_assessments WHERE created_at >= ?");
    $st->execute([$since]); $periodCount = (int)$st->fetchColumn();
} catch (Exception $e) {}
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM bhc_assessments WHERE status IN ('submitted','completed')");
    $st->execute(); $completed = (int)$st->fetchColumn();
} catch (Exception $e) {}
try {
    $st = $pdo->prepare("SELECT AVG(total_score) FROM bhc_assessments WHERE status IN ('submitted','completed') AND total_score IS NOT NULL");
    $st->execute(); $avgScore = round((float)$st->fetchColumn(), 1);
} catch (Exception $e) {}

// ── Band breakdown ────────────────────────────────────────────────────────────
$bands = ['needs_attention'=>0,'developing'=>0,'good_progress'=>0,'strong_foundations'=>0];
try {
    $st = $pdo->query("SELECT score_band, COUNT(*) AS n FROM bhc_assessments WHERE score_band IS NOT NULL GROUP BY score_band");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($bands[$row['score_band']])) $bands[$row['score_band']] = (int)$row['n'];
    }
} catch (Exception $e) {}

// ── Category averages ─────────────────────────────────────────────────────────
$catAvg = [];
$catCols = ['online_visibility','customer_acquisition','customer_trust','digital_presence','customer_management','marketing_growth','business_operations'];
try {
    $cols = implode(',', array_map(fn($c) => "AVG(score_{$c}) AS {$c}", $catCols));
    $row = $pdo->query("SELECT {$cols} FROM bhc_assessments WHERE status IN ('submitted','completed')")->fetch(PDO::FETCH_ASSOC);
    foreach ($catCols as $c) $catAvg[$c] = round((float)($row[$c] ?? 0), 1);
} catch (Exception $e) { foreach ($catCols as $c) $catAvg[$c] = 0; }

// ── Recent assessments ────────────────────────────────────────────────────────
$recent = [];
try {
    $st = $pdo->query("SELECT id,reference,business_name,contact_name,contact_email,total_score,score_band,status,lang,created_at FROM bhc_assessments ORDER BY created_at DESC LIMIT 10");
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── City breakdown ────────────────────────────────────────────────────────────
$cityCounts = [];
try {
    $st = $pdo->query("SELECT city, COUNT(*) AS n FROM bhc_assessments WHERE city IS NOT NULL AND city != '' GROUP BY city ORDER BY n DESC LIMIT 8");
    $cityCounts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Pending (started but not submitted) ──────────────────────────────────────
$pending = 0;
try {
    $st = $pdo->query("SELECT COUNT(*) FROM bhc_assessments WHERE status='started'");
    $pending = (int)$st->fetchColumn();
} catch (Exception $e) {}

$allCategories = bhc_load_categories();

$pageTitle = 'BHC Overview — Admin — 237Biz';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('bhc-overview'); adminNavAssets();
?>

<style>
.stat-card { background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:18px 20px; }
.stat-card .val { font-size:1.9rem;font-weight:900;font-family:'Fraunces',serif;line-height:1; }
.stat-card .lbl { font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px; }
.stat-card .sub { font-size:12px;color:var(--muted);margin-top:4px; }
.section-title { font-size:1rem;font-weight:700;margin:2rem 0 1rem; }
.band-pill { display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700; }
.bhc-table { width:100%;border-collapse:collapse;font-size:13px; }
.bhc-table th { text-align:left;padding:8px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid rgba(255,255,255,0.08); }
.bhc-table td { padding:10px 12px;border-bottom:1px solid rgba(255,255,255,0.05); }
.bhc-table tr:last-child td { border-bottom:none; }
.bhc-table tr:hover td { background:rgba(255,255,255,0.02); }
.cat-bar-wrap { flex:1;min-width:60px;height:6px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden; }
.cat-bar-fill { height:100%;border-radius:3px; }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin/">Admin</a> › <span>Business Health Check</span></nav>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);margin:0;">&#x1FA7A; BHC Overview</h1>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php foreach (['7'=>'7 days','30'=>'30 days','90'=>'90 days','365'=>'1 year'] as $v=>$l): ?>
        <a href="?range=<?= $v ?>" style="padding:6px 14px;border-radius:7px;font-size:12.5px;font-weight:600;text-decoration:none;<?= $range===$v?'background:rgba(0,168,120,0.2);color:#00A878;border:1px solid rgba(0,168,120,0.4);':'color:var(--muted);border:1px solid rgba(255,255,255,0.1);' ?>"><?= $l ?></a>
        <?php endforeach; ?>
        <a href="<?= SITE_URL ?>/admin/bhc-assessments" style="padding:6px 14px;border-radius:7px;font-size:12.5px;font-weight:600;text-decoration:none;background:#00A878;color:#fff;">All Assessments →</a>
      </div>
    </div>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <!-- Top stats -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:1.5rem;">
    <div class="stat-card">
      <div class="lbl">Total Assessments</div>
      <div class="val"><?= number_format($total) ?></div>
      <div class="sub">+<?= $periodCount ?> in period</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Completed</div>
      <div class="val" style="color:#00A878;"><?= number_format($completed) ?></div>
      <div class="sub"><?= $total ? round($completed/$total*100) : 0 ?>% completion</div>
    </div>
    <div class="stat-card <?= $pending > 0 ? 'style="border-color:rgba(252,209,22,0.3);"' : '' ?>">
      <div class="lbl">In Progress</div>
      <div class="val" style="color:#fcd116;"><?= number_format($pending) ?></div>
      <div class="sub">Started, not submitted</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Avg Score</div>
      <div class="val" style="color:<?= bhc_band_color(bhc_score_band($avgScore)) ?>;"><?= $avgScore ?></div>
      <div class="sub">Out of 100</div>
    </div>
  </div>

  <!-- Band breakdown -->
  <h2 class="section-title">Score Band Distribution</h2>
  <?php $bandTotal = max(1, array_sum($bands)); ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:2rem;">
    <?php foreach ([
      'strong_foundations' => 'Strong Foundations',
      'good_progress'      => 'Good Progress',
      'developing'         => 'Developing',
      'needs_attention'    => 'Needs Attention',
    ] as $slug => $label):
      $col = bhc_band_color($slug);
      $pct = round($bands[$slug] / $bandTotal * 100);
    ?>
    <div class="stat-card" style="border-color:<?= $col ?>33;">
      <div class="lbl" style="color:<?= $col ?>;"><?= $label ?></div>
      <div class="val" style="color:<?= $col ?>;"><?= number_format($bands[$slug]) ?></div>
      <div style="margin-top:8px;height:4px;background:rgba(255,255,255,0.06);border-radius:2px;overflow:hidden;">
        <div style="height:100%;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:2px;"></div>
      </div>
      <div class="sub" style="margin-top:4px;"><?= $pct ?>%</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Category averages -->
  <h2 class="section-title">Category Performance Averages</h2>
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 20px;margin-bottom:2rem;">
    <?php foreach ($catCols as $c):
      $cat   = $allCategories[$c] ?? [];
      $label = $cat['label_en'] ?? ucwords(str_replace('_',' ',$c));
      $avg   = $catAvg[$c] ?? 0;
      $pct   = min(100, round($avg));
      $col   = $avg >= 80 ? '#38a169' : ($avg >= 60 ? '#3182ce' : ($avg >= 40 ? '#d69e2e' : '#e53e3e'));
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);">
      <div style="width:170px;font-size:13px;flex-shrink:0;"><?= htmlspecialchars($label) ?></div>
      <div class="cat-bar-wrap">
        <div class="cat-bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>;"></div>
      </div>
      <div style="width:40px;text-align:right;font-size:13px;font-weight:700;color:<?= $col ?>;"><?= $avg ?>%</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- City breakdown -->
  <?php if ($cityCounts): ?>
  <h2 class="section-title">Top Cities</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:2rem;">
    <?php foreach ($cityCounts as $row): ?>
    <div class="stat-card" style="padding:12px 16px;">
      <div class="lbl"><?= htmlspecialchars(ucfirst($row['city'])) ?></div>
      <div class="val" style="font-size:1.5rem;"><?= (int)$row['n'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Recent assessments -->
  <h2 class="section-title">Recent Assessments</h2>
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;overflow:hidden;margin-bottom:2rem;">
    <table class="bhc-table">
      <thead>
        <tr>
          <th>Reference</th>
          <th>Business</th>
          <th>Contact</th>
          <th>Score</th>
          <th>Band</th>
          <th>Status</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$recent): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px;">No assessments yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $row):
          $band  = $row['score_band'] ?? bhc_score_band((float)($row['total_score'] ?? 0));
          $col   = bhc_band_color($band);
          $score = $row['total_score'] !== null ? round((float)$row['total_score']) : '—';
        ?>
        <tr>
          <td><code style="font-size:11px;color:var(--muted);"><?= e($row['reference'] ?? '') ?></code></td>
          <td style="font-weight:600;"><?= e($row['business_name'] ?? '—') ?></td>
          <td style="font-size:12px;color:var(--muted);"><?= e($row['contact_name'] ?? '') ?></td>
          <td style="font-weight:700;color:<?= $col ?>;"><?= $score ?></td>
          <td><span class="band-pill" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44;"><?= bhc_band_label($band) ?></span></td>
          <td><span style="font-size:11px;color:<?= $row['status']==='completed'||$row['status']==='submitted'?'#00A878':'var(--muted)' ?>;"><?= htmlspecialchars(ucfirst($row['status'] ?? '')) ?></span></td>
          <td style="font-size:12px;color:var(--muted);"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
          <td><a href="<?= SITE_URL ?>/admin/bhc-assessments?id=<?= (int)$row['id'] ?>" style="font-size:12px;color:#00A878;">View →</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Quick links -->
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:2rem;">
    <a href="<?= SITE_URL ?>/admin/bhc-assessments" style="padding:10px 20px;background:rgba(0,168,120,0.1);color:#00A878;border:1px solid rgba(0,168,120,0.3);border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;">&#x1F4CB; All Assessments</a>
    <a href="<?= SITE_URL ?>/admin/bhc-recommendations" style="padding:10px 20px;background:rgba(255,255,255,0.04);color:var(--text);border:1px solid rgba(255,255,255,0.1);border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;">&#x1F4A1; Manage Recommendations</a>
    <a href="<?= SITE_URL ?>/business-health-check" target="_blank" style="padding:10px 20px;background:rgba(255,255,255,0.04);color:var(--muted);border:1px solid rgba(255,255,255,0.1);border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">&#x1F517; Public BHC Page ↗</a>
  </div>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
