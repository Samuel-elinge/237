<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
/**
 * Admin — BHC Dashboard
 *
 * Stage 6 of Phase 2: Overview metrics tile row, conversion stats,
 * most-requested services, top-performing providers, and a live feed
 * of recent / new enquiries requiring attention.
 *
 * Auth: requires $_SESSION['staff_id'] + $_SESSION['is_admin']
 * Lang: English + French (admin UI stays in EN; data labels honour
 *       the row's stored language where surfaced).
 */

define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';

$staffId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$isAdmin = in_array($_SESSION['role'] ?? '', ['admin', 'operations_admin']);
if (!$staffId || !$isAdmin) {
    http_response_code(403);
    die('Admin login required.');
}

$pdo = db();

// ── Date window (default: last 30 days, selectable via GET) ───────────────
$validWindows = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months'];
$window       = isset($_GET['window']) && isset($validWindows[$_GET['window']])
                ? (int)$_GET['window'] : 30;
$since        = date('Y-m-d H:i:s', strtotime("-{$window} days"));

// ── Helper: single-value query ────────────────────────────────────────────
function bhc_dash_scalar(PDO $pdo, string $sql, array $p = []): int|string|null {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn() ?: 0;
}

// ═══════════════════════════════════════════════════════════════════════════
// METRIC TILES
// ═══════════════════════════════════════════════════════════════════════════

// 1. Assessments
$totalAssessments  = bhc_dash_scalar($pdo,
    'SELECT COUNT(*) FROM bhc_assessments');
$assessmentsWindow = bhc_dash_scalar($pdo,
    'SELECT COUNT(*) FROM bhc_assessments WHERE created_at >= :s',
    [':s' => $since]);
$avgScore          = $pdo->query(
    'SELECT ROUND(AVG(total_score),1) FROM bhc_assessments WHERE total_score IS NOT NULL'
)->fetchColumn() ?: '—';

// 2. Enquiries
$totalEnquiries  = bhc_dash_scalar($pdo, 'SELECT COUNT(*) FROM bhc_enquiries');
$newEnquiries    = bhc_dash_scalar($pdo,
    "SELECT COUNT(*) FROM bhc_enquiries WHERE status = 'new'");
$enquiriesWindow = bhc_dash_scalar($pdo,
    'SELECT COUNT(*) FROM bhc_enquiries WHERE created_at >= :s',
    [':s' => $since]);

// 3. Conversions (window)
$converted       = bhc_dash_scalar($pdo,
    "SELECT COUNT(*) FROM bhc_enquiries WHERE status = 'converted' AND created_at >= :s",
    [':s' => $since]);
$conversionRate  = $enquiriesWindow > 0
                   ? round(($converted / $enquiriesWindow) * 100, 1) . '%'
                   : '—';

// 4. Active providers
$activeProviders = bhc_dash_scalar($pdo,
    "SELECT COUNT(*) FROM bhc_service_providers WHERE is_active = 1");

// ═══════════════════════════════════════════════════════════════════════════
// SCORE CLASSIFICATION BREAKDOWN (all time)
// ═══════════════════════════════════════════════════════════════════════════
$scoreBreakdown = $pdo->query("
    SELECT score_band,
           COUNT(*) AS cnt,
           ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER (), 1) AS pct
      FROM (
            SELECT CASE
                     WHEN total_score >= 80 THEN 'Excellent (80–100)'
                     WHEN total_score >= 60 THEN 'Good (60–79)'
                     WHEN total_score >= 40 THEN 'Fair (40–59)'
                     ELSE                        'Needs Attention (<40)'
                   END AS score_band,
                   CASE
                     WHEN total_score >= 80 THEN 1
                     WHEN total_score >= 60 THEN 2
                     WHEN total_score >= 40 THEN 3
                     ELSE 4
                   END AS sort_order
              FROM bhc_assessments
             WHERE total_score IS NOT NULL
           ) t
  GROUP BY score_band, sort_order
  ORDER BY sort_order
")->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════
// ENQUIRY STATUS BREAKDOWN (window)
// ═══════════════════════════════════════════════════════════════════════════
$statusBreakdown = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt
      FROM bhc_enquiries
     WHERE created_at >= :s
  GROUP BY status
  ORDER BY cnt DESC
");
$statusBreakdown->execute([':s' => $since]);
$statusBreakdown = $statusBreakdown->fetchAll(PDO::FETCH_KEY_PAIR);

// ═══════════════════════════════════════════════════════════════════════════
// MOST REQUESTED SERVICES (window)
// ═══════════════════════════════════════════════════════════════════════════
$topServices = $pdo->prepare("
    SELECT s.name_en                        AS service_name,
           sc.name_en                       AS category_name,
           COUNT(e.id)                      AS enquiry_count,
           SUM(e.status = 'converted')      AS converted_count,
           ROUND(
             SUM(e.status = 'converted') * 100.0 / COUNT(e.id), 1
           )                                AS conv_rate
      FROM bhc_enquiries   e
      JOIN bhc_services     s  ON s.id  = e.service_id
      JOIN bhc_service_categories sc ON sc.id = s.category_id
     WHERE e.created_at >= :s
  GROUP BY s.id, s.name_en, sc.name_en
  ORDER BY enquiry_count DESC
     LIMIT 10
");
$topServices->execute([':s' => $since]);
$topServices = $topServices->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════
// TOP-PERFORMING PROVIDERS (window)
// ═══════════════════════════════════════════════════════════════════════════
$topProviders = $pdo->prepare("
    SELECT p.name                           AS provider_name,
           COUNT(e.id)                      AS assigned_count,
           SUM(e.status = 'converted')      AS converted_count,
           ROUND(
             SUM(e.status = 'converted') * 100.0 / NULLIF(COUNT(e.id), 0), 1
           )                                AS conv_rate,
           SUM(e.status IN ('new','awaiting_assignment','assigned'))
                                            AS active_count
      FROM bhc_service_providers  p
      JOIN bhc_enquiries           e ON e.assigned_provider_id = p.id
     WHERE e.created_at >= :s
  GROUP BY p.id, p.name
  ORDER BY converted_count DESC, assigned_count DESC
     LIMIT 10
");
$topProviders->execute([':s' => $since]);
$topProviders = $topProviders->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════
// RECENT ENQUIRIES (last 10, any status – for the live feed)
// ═══════════════════════════════════════════════════════════════════════════
$recentEnquiries = $pdo->query("
    SELECT e.id, e.ref, e.business_name, e.contact_name,
           e.status, e.created_at, e.lang,
           s.name_en AS service_name,
           p.name    AS provider_name
      FROM bhc_enquiries          e
      JOIN bhc_services            s ON s.id = e.service_id
 LEFT JOIN bhc_service_providers   p ON p.id = e.assigned_provider_id
  ORDER BY e.created_at DESC
     LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════
// DAILY ENQUIRY TREND (window, for sparkline)
// ═══════════════════════════════════════════════════════════════════════════
$trendRows = $pdo->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
      FROM bhc_enquiries
     WHERE created_at >= :s
  GROUP BY DATE(created_at)
  ORDER BY day
");
$trendRows->execute([':s' => $since]);
$trendData = $trendRows->fetchAll(PDO::FETCH_KEY_PAIR);  // ['2025-01-01' => 3, …]

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════
function statusBadge(string $status): string {
    $map = [
        'new'                  => ['New',               '#3b82f6'],
        'awaiting_assignment'  => ['Awaiting',          '#f59e0b'],
        'assigned'             => ['Assigned',          '#8b5cf6'],
        'contacted'            => ['Contacted',         '#06b6d4'],
        'quotation_provided'   => ['Quoted',            '#6366f1'],
        'in_progress'          => ['In Progress',       '#10b981'],
        'converted'            => ['Converted',         '#22c55e'],
        'not_converted'        => ['Not Converted',     '#ef4444'],
        'closed'               => ['Closed',            '#6b7280'],
    ];
    [$label, $color] = $map[$status] ?? [ucfirst($status), '#6b7280'];
    return "<span class='status-badge' style='--bc:{$color}'>{$label}</span>";
}

function scoreColor(float $pct): string {
    if ($pct >= 80) return '#22c55e';
    if ($pct >= 60) return '#10b981';
    if ($pct >= 40) return '#f59e0b';
    return '#ef4444';
}

// ── Render ────────────────────────────────────────────────────────────────
$pageTitle = 'BHC Dashboard — Admin';
require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
/* ── Dashboard layout ──────────────────────────────────────────────────── */
.bhcd-wrap          { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }

/* Top bar */
.bhcd-topbar        { display: flex; align-items: center; justify-content: space-between;
                      flex-wrap: wrap; gap: .75rem; margin-bottom: 1.75rem; }
.bhcd-topbar h1     { margin: 0; font-size: 1.55rem; display: flex; align-items: center; gap: .5rem; }
.bhcd-topbar h1 span{ font-size: 1rem; opacity: .6; font-weight: 400; }
.bhcd-window-sel    { display: flex; gap: .4rem; }
.bhcd-window-sel a  { padding: .3rem .75rem; border-radius: 999px; font-size: .82rem;
                      text-decoration: none; border: 1px solid var(--border,rgba(0,0,0,.12));
                      color: inherit; }
.bhcd-window-sel a.active { background: var(--accent,#16a34a); color: #fff; border-color: transparent; }

/* Metric tiles */
.bhcd-tiles         { display: grid;
                      grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
                      gap: 1rem; margin-bottom: 1.75rem; }
.bhcd-tile          { background: var(--card,#fff); border: 1px solid var(--border,rgba(0,0,0,.08));
                      border-radius: 10px; padding: 1.1rem 1.25rem; }
.bhcd-tile-label    { font-size: .75rem; font-weight: 600; letter-spacing: .05em;
                      text-transform: uppercase; color: var(--muted,#6b7280); margin-bottom: .4rem; }
.bhcd-tile-value    { font-size: 2rem; font-weight: 800; line-height: 1; }
.bhcd-tile-sub      { font-size: .8rem; color: var(--muted,#6b7280); margin-top: .3rem; }
.bhcd-tile-delta    { display: inline-block; font-size: .75rem; padding: .1rem .4rem;
                      border-radius: 999px; font-weight: 600; }
.bhcd-tile-delta.up { background: rgba(34,197,94,.12); color: #16a34a; }
.bhcd-tile-delta.neutral { background: rgba(107,114,128,.12); color: #6b7280; }

/* Two-column grid for tables */
.bhcd-grid2         { display: grid; grid-template-columns: 1fr 1fr;
                      gap: 1rem; margin-bottom: 1.75rem; }
@media (max-width: 860px) { .bhcd-grid2 { grid-template-columns: 1fr; } }

/* Cards */
.bhcd-card          { background: var(--card,#fff); border: 1px solid var(--border,rgba(0,0,0,.08));
                      border-radius: 10px; overflow: hidden; }
.bhcd-card-head     { padding: .9rem 1.1rem; font-weight: 700; font-size: .9rem;
                      border-bottom: 1px solid var(--border,rgba(0,0,0,.08));
                      display: flex; align-items: center; justify-content: space-between; }
.bhcd-card-head a   { font-size: .78rem; font-weight: 400; color: var(--accent,#16a34a); }

/* Bar-chart rows */
.bhcd-bar-row       { display: flex; align-items: center; gap: .6rem; padding: .55rem 1.1rem;
                      font-size: .84rem; border-bottom: 1px solid var(--border,rgba(0,0,0,.05)); }
.bhcd-bar-row:last-child { border-bottom: none; }
.bhcd-bar-label     { flex: 0 0 auto; min-width: 160px; white-space: nowrap;
                      overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
.bhcd-bar-track     { flex: 1; height: 8px; background: rgba(0,0,0,.06);
                      border-radius: 999px; overflow: hidden; }
.bhcd-bar-fill      { height: 100%; border-radius: 999px; transition: width .3s; }
.bhcd-bar-count     { flex: 0 0 2.5rem; text-align: right; font-weight: 600;
                      font-size: .82rem; }
.bhcd-bar-rate      { flex: 0 0 3.5rem; text-align: right; font-size: .78rem;
                      color: var(--muted,#6b7280); }

/* Score classification pills */
.bhcd-score-rows    { padding: .6rem 1.1rem; }
.bhcd-score-row     { display: flex; align-items: center; gap: .75rem; margin-bottom: .6rem; }
.bhcd-score-row:last-child { margin-bottom: 0; }
.bhcd-score-band    { flex: 0 0 190px; font-size: .84rem; }
.bhcd-score-track   { flex: 1; height: 10px; background: rgba(0,0,0,.06);
                      border-radius: 999px; overflow: hidden; }
.bhcd-score-fill    { height: 100%; border-radius: 999px; }
.bhcd-score-pct     { flex: 0 0 3rem; text-align: right; font-weight: 700; font-size: .84rem; }

/* Status breakdown pills */
.bhcd-status-grid   { display: flex; flex-wrap: wrap; gap: .4rem; padding: .9rem 1.1rem; }
.bhcd-status-pill   { display: flex; align-items: center; gap: .4rem;
                      padding: .3rem .7rem; border-radius: 999px;
                      font-size: .78rem; font-weight: 600;
                      border: 1px solid currentColor; }
.bhcd-status-pill .cnt { font-size: .9rem; }

/* Recent enquiries table */
.bhcd-enq-table     { width: 100%; border-collapse: collapse; font-size: .84rem; }
.bhcd-enq-table th  { background: rgba(0,0,0,.025); padding: .55rem .9rem;
                      text-align: left; font-weight: 600; white-space: nowrap;
                      color: var(--muted,#6b7280); font-size: .78rem; letter-spacing: .03em; }
.bhcd-enq-table td  { padding: .55rem .9rem;
                      border-top: 1px solid var(--border,rgba(0,0,0,.06)); }
.bhcd-enq-table tr:hover td { background: rgba(0,0,0,.02); }
.bhcd-enq-table a   { color: var(--accent,#16a34a); text-decoration: none; font-weight: 600; }
.bhcd-enq-table a:hover { text-decoration: underline; }

/* Full-width card */
.bhcd-full          { margin-bottom: 1.75rem; }

/* Provider table */
.bhcd-prov-table    { width: 100%; border-collapse: collapse; font-size: .84rem; }
.bhcd-prov-table th { background: rgba(0,0,0,.025); padding: .55rem .9rem;
                      text-align: left; font-weight: 600; white-space: nowrap;
                      color: var(--muted,#6b7280); font-size: .78rem; letter-spacing: .03em; }
.bhcd-prov-table td { padding: .55rem .9rem;
                      border-top: 1px solid var(--border,rgba(0,0,0,.06)); }
.bhcd-prov-table td.num { text-align: right; }

/* Trend sparkline */
.bhcd-spark-wrap    { padding: .75rem 1.1rem 1rem; }
.bhcd-spark         { width: 100%; height: 56px; }

/* Status badge */
.status-badge       { display: inline-block; padding: .15rem .5rem; border-radius: 999px;
                      font-size: .72rem; font-weight: 700; letter-spacing: .03em;
                      background: color-mix(in srgb, var(--bc) 15%, transparent);
                      color: var(--bc); }

/* Quick-links */
.bhcd-links         { display: flex; flex-wrap: wrap; gap: .6rem; margin-bottom: 1.75rem; }
.bhcd-links a       { padding: .4rem 1rem; border-radius: .4rem; font-size: .85rem;
                      font-weight: 600; text-decoration: none;
                      border: 1px solid var(--border,rgba(0,0,0,.12));
                      color: inherit; transition: background .15s; }
.bhcd-links a:hover { background: rgba(0,0,0,.04); }
.bhcd-links a .dot  { display: inline-block; width: 8px; height: 8px; border-radius: 50%;
                      margin-right: .35rem; vertical-align: middle; }

/* Responsive */
@media (max-width: 600px) {
  .bhcd-tile-value  { font-size: 1.5rem; }
  .bhcd-bar-label   { min-width: 120px; max-width: 140px; }
}

/* Dark-mode overrides */
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --card:   #101e17;
    --border: rgba(255,255,255,.08);
    --muted:  #9ca3af;
    --accent: #22c55e;
  }
  .bhcd-window-sel a.active { background: #22c55e; }
  .bhcd-bar-track, .bhcd-score-track { background: rgba(255,255,255,.08); }
  .bhcd-enq-table th, .bhcd-prov-table th { background: rgba(255,255,255,.03); }
  .bhcd-enq-table tr:hover td { background: rgba(255,255,255,.025); }
  .bhcd-links a:hover { background: rgba(255,255,255,.05); }
}
:root[data-theme="dark"] {
  --card:   #101e17;
  --border: rgba(255,255,255,.08);
  --muted:  #9ca3af;
  --accent: #22c55e;
}
</style>

<div class="bhcd-wrap">

  <!-- ── Top bar ─────────────────────────────────────────────────────────── -->
  <div class="bhcd-topbar">
    <h1>
      📊 BHC Dashboard
      <span>Business Health Check overview</span>
    </h1>
    <div class="bhcd-window-sel">
      <?php foreach ($validWindows as $days => $label): ?>
        <a href="?window=<?= $days ?>"
           class="<?= $window === (int)$days ? 'active' : '' ?>">
          <?= htmlspecialchars($label) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── Quick links ────────────────────────────────────────────────────── -->
  <div class="bhcd-links">
    <a href="admin-bhc-enquiries.php">
      <span class="dot" style="background:#3b82f6"></span>Enquiries
      <?php if ($newEnquiries > 0): ?>
        &nbsp;<strong>(<?= $newEnquiries ?> new)</strong>
      <?php endif; ?>
    </a>
    <a href="admin-bhc-catalogue.php">
      <span class="dot" style="background:#8b5cf6"></span>Catalogue
    </a>
    <a href="admin-bhc-commercial.php">
      <span class="dot" style="background:#f59e0b"></span>Commercial Terms
    </a>
  </div>

  <!-- ── Metric tiles ───────────────────────────────────────────────────── -->
  <div class="bhcd-tiles">

    <div class="bhcd-tile">
      <div class="bhcd-tile-label">Total Assessments</div>
      <div class="bhcd-tile-value"><?= number_format($totalAssessments) ?></div>
      <div class="bhcd-tile-sub">
        <span class="bhcd-tile-delta up">+<?= number_format($assessmentsWindow) ?></span>
        this period
      </div>
    </div>

    <div class="bhcd-tile">
      <div class="bhcd-tile-label">Avg BHC Score</div>
      <div class="bhcd-tile-value" style="color:<?= is_numeric($avgScore) ? scoreColor((float)$avgScore) : '#6b7280' ?>">
        <?= htmlspecialchars($avgScore) ?>
      </div>
      <div class="bhcd-tile-sub">out of 100 (all time)</div>
    </div>

    <div class="bhcd-tile">
      <div class="bhcd-tile-label">New Enquiries</div>
      <div class="bhcd-tile-value" style="<?= $newEnquiries > 0 ? 'color:#f59e0b' : '' ?>">
        <?= number_format($newEnquiries) ?>
      </div>
      <div class="bhcd-tile-sub">awaiting assignment</div>
    </div>

    <div class="bhcd-tile">
      <div class="bhcd-tile-label">Enquiries (period)</div>
      <div class="bhcd-tile-value"><?= number_format($enquiriesWindow) ?></div>
      <div class="bhcd-tile-sub">
        <span class="bhcd-tile-delta up"><?= $converted ?> converted</span>
      </div>
    </div>

    <div class="bhcd-tile">
      <div class="bhcd-tile-label">Conversion Rate</div>
      <div class="bhcd-tile-value"><?= htmlspecialchars($conversionRate) ?></div>
      <div class="bhcd-tile-sub">enquiry → converted (period)</div>
    </div>

    <div class="bhcd-tile">
      <div class="bhcd-tile-label">Active Providers</div>
      <div class="bhcd-tile-value"><?= number_format($activeProviders) ?></div>
      <div class="bhcd-tile-sub">in service catalogue</div>
    </div>

  </div><!-- /.bhcd-tiles -->

  <!-- ── Score classification + Status breakdown ────────────────────────── -->
  <div class="bhcd-grid2">

    <!-- Score bands -->
    <div class="bhcd-card">
      <div class="bhcd-card-head">
        Score Classification
        <span style="font-size:.78rem;font-weight:400;color:var(--muted,#6b7280)">all assessments</span>
      </div>
      <div class="bhcd-score-rows">
        <?php if (!$scoreBreakdown): ?>
          <p style="color:var(--muted,#6b7280);font-size:.85rem;padding:.5rem 0">No completed assessments yet.</p>
        <?php else: ?>
          <?php
          $bandColors = [
              'Excellent (80–100)'  => '#22c55e',
              'Good (60–79)'        => '#10b981',
              'Fair (40–59)'        => '#f59e0b',
              'Needs Attention (<40)'=> '#ef4444',
          ];
          foreach ($scoreBreakdown as $row):
            $color = $bandColors[$row['score_band']] ?? '#6b7280';
          ?>
          <div class="bhcd-score-row">
            <div class="bhcd-score-band"><?= htmlspecialchars($row['score_band']) ?></div>
            <div class="bhcd-score-track">
              <div class="bhcd-score-fill" style="width:<?= $row['pct'] ?>%;background:<?= $color ?>"></div>
            </div>
            <div class="bhcd-score-pct" style="color:<?= $color ?>"><?= $row['pct'] ?>%</div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Enquiry status breakdown -->
    <div class="bhcd-card">
      <div class="bhcd-card-head">
        Enquiry Status Mix
        <span style="font-size:.78rem;font-weight:400;color:var(--muted,#6b7280)">period</span>
      </div>
      <div class="bhcd-status-grid">
        <?php
        $statusColors = [
            'new'                 => '#3b82f6',
            'awaiting_assignment' => '#f59e0b',
            'assigned'            => '#8b5cf6',
            'contacted'           => '#06b6d4',
            'quotation_provided'  => '#6366f1',
            'in_progress'         => '#10b981',
            'converted'           => '#22c55e',
            'not_converted'       => '#ef4444',
            'closed'              => '#6b7280',
        ];
        if (!$statusBreakdown):
        ?>
          <p style="color:var(--muted,#6b7280);font-size:.85rem">No enquiries in this period.</p>
        <?php else:
            foreach ($statusBreakdown as $status => $cnt):
              $color = $statusColors[$status] ?? '#6b7280';
              $label = ucwords(str_replace('_', ' ', $status));
        ?>
          <div class="bhcd-status-pill" style="--bc:<?= $color ?>;color:<?= $color ?>;border-color:<?= $color ?>22">
            <span class="cnt"><?= $cnt ?></span>
            <span><?= htmlspecialchars($label) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div><!-- /.bhcd-grid2 -->

  <!-- ── Enquiry trend sparkline ────────────────────────────────────────── -->
  <div class="bhcd-full">
    <div class="bhcd-card">
      <div class="bhcd-card-head">
        Daily Enquiry Volume
        <span style="font-size:.78rem;font-weight:400;color:var(--muted,#6b7280)">period</span>
      </div>
      <div class="bhcd-spark-wrap">
        <canvas class="bhcd-spark" id="trendChart"></canvas>
      </div>
    </div>
  </div>

  <!-- ── Top services + Top providers ──────────────────────────────────── -->
  <div class="bhcd-grid2">

    <!-- Most requested services -->
    <div class="bhcd-card">
      <div class="bhcd-card-head">
        Most Requested Services
        <a href="admin-bhc-catalogue.php">View catalogue →</a>
      </div>
      <?php if (!$topServices): ?>
        <p style="padding:1rem;color:var(--muted,#6b7280);font-size:.85rem">No service enquiries in this period.</p>
      <?php else:
        $maxSvcEnq = max(array_column($topServices, 'enquiry_count'));
        foreach ($topServices as $svc):
          $barW = $maxSvcEnq > 0 ? round(($svc['enquiry_count'] / $maxSvcEnq) * 100) : 0;
      ?>
        <div class="bhcd-bar-row">
          <div class="bhcd-bar-label" title="<?= htmlspecialchars($svc['service_name']) ?>">
            <?= htmlspecialchars($svc['service_name']) ?>
            <div style="font-size:.7rem;color:var(--muted,#6b7280)"><?= htmlspecialchars($svc['category_name']) ?></div>
          </div>
          <div class="bhcd-bar-track">
            <div class="bhcd-bar-fill" style="width:<?= $barW ?>%;background:#3b82f6"></div>
          </div>
          <div class="bhcd-bar-count"><?= $svc['enquiry_count'] ?></div>
          <div class="bhcd-bar-rate"><?= $svc['conv_rate'] !== null ? $svc['conv_rate'] . '%' : '—' ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Top providers -->
    <div class="bhcd-card">
      <div class="bhcd-card-head">
        Top Providers by Conversion
        <a href="admin-bhc-catalogue.php">View providers →</a>
      </div>
      <?php if (!$topProviders): ?>
        <p style="padding:1rem;color:var(--muted,#6b7280);font-size:.85rem">No assigned enquiries in this period.</p>
      <?php else:
        $maxPrvConv = max(array_column($topProviders, 'converted_count'));
        foreach ($topProviders as $prov):
          $barW = $maxPrvConv > 0 ? round(($prov['converted_count'] / $maxPrvConv) * 100) : 0;
      ?>
        <div class="bhcd-bar-row">
          <div class="bhcd-bar-label" title="<?= htmlspecialchars($prov['provider_name']) ?>">
            <?= htmlspecialchars($prov['provider_name']) ?>
            <?php if ($prov['active_count'] > 0): ?>
              <div style="font-size:.7rem;color:#f59e0b"><?= $prov['active_count'] ?> active</div>
            <?php endif; ?>
          </div>
          <div class="bhcd-bar-track">
            <div class="bhcd-bar-fill" style="width:<?= $barW ?>%;background:#22c55e"></div>
          </div>
          <div class="bhcd-bar-count"><?= $prov['converted_count'] ?></div>
          <div class="bhcd-bar-rate"><?= $prov['conv_rate'] !== null ? $prov['conv_rate'] . '%' : '—' ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  </div><!-- /.bhcd-grid2 -->

  <!-- ── Recent enquiries feed ──────────────────────────────────────────── -->
  <div class="bhcd-full">
    <div class="bhcd-card">
      <div class="bhcd-card-head">
        Recent Enquiries
        <a href="admin-bhc-enquiries.php">View all →</a>
      </div>
      <?php if (!$recentEnquiries): ?>
        <p style="padding:1rem;color:var(--muted,#6b7280);font-size:.85rem">No enquiries yet.</p>
      <?php else: ?>
        <div style="overflow-x:auto">
          <table class="bhcd-enq-table">
            <thead>
              <tr>
                <th>Ref</th>
                <th>Business</th>
                <th>Service</th>
                <th>Provider</th>
                <th>Status</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentEnquiries as $enq): ?>
                <tr>
                  <td><a href="admin-bhc-enquiries.php?id=<?= $enq['id'] ?>"><?= htmlspecialchars($enq['ref']) ?></a></td>
                  <td>
                    <?= htmlspecialchars($enq['business_name']) ?>
                    <div style="font-size:.75rem;color:var(--muted,#6b7280)"><?= htmlspecialchars($enq['contact_name']) ?></div>
                  </td>
                  <td><?= htmlspecialchars($enq['service_name']) ?></td>
                  <td><?= $enq['provider_name'] ? htmlspecialchars($enq['provider_name']) : '<span style="color:var(--muted,#9ca3af)">Unassigned</span>' ?></td>
                  <td><?= statusBadge($enq['status']) ?></td>
                  <td style="white-space:nowrap;color:var(--muted,#6b7280);font-size:.78rem">
                    <?= date('d M Y H:i', strtotime($enq['created_at'])) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /.bhcd-wrap -->

<!-- ── Trend sparkline (Chart.js via CDN) ─────────────────────────────── -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
  var trendData = <?= json_encode($trendData, JSON_THROW_ON_ERROR) ?>;
  var since     = new Date();
  since.setDate(since.getDate() - <?= $window ?>);

  // Fill all days in window so gaps show as 0
  var labels = [], values = [];
  for (var d = new Date(since); d <= new Date(); d.setDate(d.getDate() + 1)) {
    var key = d.toISOString().slice(0, 10);
    labels.push(key);
    values.push(trendData[key] !== undefined ? parseInt(trendData[key]) : 0);
  }

  var ctx = document.getElementById('trendChart');
  if (!ctx) return;

  var isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  var accent = isDark ? '#22c55e' : '#16a34a';
  var gridColor = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Enquiries',
        data: values,
        backgroundColor: accent + '55',
        borderColor: accent,
        borderWidth: 1.5,
        borderRadius: 3,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: { legend: { display: false },
                 tooltip: { callbacks: {
                   title: function(i){ return i[0].label; },
                   label: function(i){ return i.raw + ' enquir' + (i.raw === 1 ? 'y' : 'ies'); }
                 }}},
      scales: {
        x: { ticks: { maxTicksLimit: 10, font: { size: 11 } },
             grid:  { color: gridColor } },
        y: { ticks: { stepSize: 1, font: { size: 11 } },
             grid:  { color: gridColor },
             beginAtZero: true }
      }
    }
  });
})();
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
