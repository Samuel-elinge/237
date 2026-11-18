<?php
/**
 * Admin — BHC Leads
 * View and manage leads/contacts captured from Business Health Check assessments.
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/bhc-config.php';
requireAdmin();

$pdo = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$band     = $_GET['band'] ?? '';
$status   = $_GET['status'] ?? '';
$city     = $_GET['city'] ?? '';
$lang     = $_GET['lang'] ?? '';
$range    = (int)($_GET['range'] ?? 90);
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 30;

$validBands   = ['needs_attention','developing','good_progress','strong_foundations'];
$validStatus  = ['started','submitted','completed'];
$validRanges  = [7, 30, 90, 365, 0];

if (!in_array($band, $validBands))   $band   = '';
if (!in_array($status, $validStatus)) $status = '';
if (!in_array($range, $validRanges))  $range  = 90;

// ── POST: export or notes update ─────────────────────────────────────────────
// (No destructive actions; leads are read-only view of bhc_assessments)

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = "(a.business_name LIKE :srch OR a.contact_name LIKE :srch OR a.contact_email LIKE :srch OR a.contact_phone LIKE :srch OR a.reference LIKE :srch)";
    $params[':srch'] = '%' . $search . '%';
}
if ($band) {
    $where[]  = "a.score_band = :band";
    $params[':band'] = $band;
}
if ($status) {
    $where[]  = "a.status = :status";
    $params[':status'] = $status;
}
if ($city) {
    $where[]  = "a.city = :city";
    $params[':city'] = $city;
}
if ($lang) {
    $where[]  = "a.lang = :lang";
    $params[':lang'] = $lang;
}
if ($range) {
    $where[]  = "a.created_at >= DATE_SUB(NOW(), INTERVAL :range DAY)";
    $params[':range'] = $range;
}

// Only include assessments that have contact info (they're our "leads")
$where[] = "(a.contact_email IS NOT NULL AND a.contact_email != '' OR a.contact_phone IS NOT NULL AND a.contact_phone != '')";

$whereSql = implode(' AND ', $where);

try {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM bhc_assessments a WHERE $whereSql");
    $totalStmt->execute($params);
    $total = (int) $totalStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare(
        "SELECT a.id, a.reference, a.business_name, a.contact_name, a.contact_email,
                a.contact_phone, a.city, a.lang, a.status, a.score_overall, a.score_band,
                a.business_category, a.employee_band, a.created_at, a.updated_at
         FROM bhc_assessments a
         WHERE $whereSql
         ORDER BY a.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary stats for current filter
    $statsStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            COUNT(CASE WHEN status='completed' THEN 1 END) AS completed,
            COUNT(CASE WHEN contact_email IS NOT NULL AND contact_email != '' THEN 1 END) AS with_email,
            COUNT(CASE WHEN contact_phone IS NOT NULL AND contact_phone != '' THEN 1 END) AS with_phone,
            AVG(CASE WHEN score_overall IS NOT NULL THEN score_overall END) AS avg_score
         FROM bhc_assessments a WHERE $whereSql"
    );
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Cities for filter dropdown
    $citiesStmt = $pdo->prepare(
        "SELECT city, COUNT(*) AS cnt FROM bhc_assessments
         WHERE (contact_email IS NOT NULL AND contact_email != '' OR contact_phone IS NOT NULL AND contact_phone != '')
         AND city IS NOT NULL AND city != ''
         GROUP BY city ORDER BY cnt DESC LIMIT 20"
    );
    $citiesStmt->execute();
    $availCities = $citiesStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $total  = 0;
    $leads  = [];
    $stats  = ['total'=>0,'completed'=>0,'with_email'=>0,'with_phone'=>0,'avg_score'=>null];
    $availCities = [];
}

$pages = $total > 0 ? (int) ceil($total / $perPage) : 1;

// Build QS for pagination links
function leadsQS(array $overrides = []): string {
    $qs = array_merge([
        'q'      => $_GET['q']      ?? '',
        'band'   => $_GET['band']   ?? '',
        'status' => $_GET['status'] ?? '',
        'city'   => $_GET['city']   ?? '',
        'lang'   => $_GET['lang']   ?? '',
        'range'  => $_GET['range']  ?? '90',
    ], $overrides);
    $parts = [];
    foreach ($qs as $k => $v) {
        if ($v !== '') $parts[] = urlencode($k) . '=' . urlencode($v);
    }
    return $parts ? '?' . implode('&', $parts) : '';
}

$bandColors = [
    'needs_attention'    => '#e53e3e',
    'developing'         => '#dd6b20',
    'good_progress'      => '#d69e2e',
    'strong_foundations' => '#38a169',
];

$pageTitle = 'BHC Leads';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('bhc-leads');
adminNavAssets();
?>
<style>
/* ── Layout ────────────────────────────────────────────── */
.bhcl-wrap { max-width:1300px; margin:0 auto; padding:1.5rem 1rem 3rem; }
.bhcl-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.bhcl-header h1 { font-size:1.4rem; font-weight:700; color:#fff; margin:0; }

/* stat cards */
.bhcl-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:.75rem; margin-bottom:1.5rem; }
.bhcl-stat { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:.85rem 1rem; }
.bhcl-stat .val { font-size:1.5rem; font-weight:700; color:#00A878; line-height:1; }
.bhcl-stat .lbl { font-size:.76rem; color:var(--muted); margin-top:.2rem; }

/* filters */
.bhcl-filters { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.25rem; display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end; }
.filter-group label { display:block; font-size:.74rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.3rem; }
.filter-group input,
.filter-group select { background:rgba(0,0,0,.35); border:1px solid rgba(0,168,120,.22); border-radius:7px; padding:.42rem .7rem; color:#e2e8f0; font-size:.82rem; font-family:inherit; }
.filter-group input { min-width:200px; }
.filter-group select { min-width:140px; }
.filter-group input:focus,
.filter-group select:focus { outline:none; border-color:#00A878; }
.btn-filter { background:#00A878; color:#fff; border:none; border-radius:7px; padding:.42rem 1rem; font-size:.82rem; font-weight:600; cursor:pointer; align-self:flex-end; }
.btn-filter:hover { background:#00c48f; }
.btn-clear { background:rgba(255,255,255,.07); color:#a0aec0; border:1px solid rgba(255,255,255,.12); border-radius:7px; padding:.42rem .8rem; font-size:.82rem; cursor:pointer; text-decoration:none; align-self:flex-end; }
.btn-clear:hover { background:rgba(255,255,255,.12); }
.range-tabs { display:flex; gap:.35rem; }
.range-tab { background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); color:#a0aec0; border-radius:6px; padding:.35rem .65rem; font-size:.78rem; cursor:pointer; text-decoration:none; }
.range-tab.active { background:rgba(0,168,120,.2); border-color:#00A878; color:#00A878; font-weight:700; }

/* table */
.bhcl-table-wrap { background:var(--card); border:1px solid var(--border); border-radius:12px; overflow:hidden; }
.bhcl-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.bhcl-table th { background:rgba(0,168,120,.07); color:var(--muted); font-size:.73rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; padding:.55rem 1rem; text-align:left; white-space:nowrap; }
.bhcl-table td { padding:.7rem 1rem; border-top:1px solid rgba(0,168,120,.08); vertical-align:top; color:var(--text); }
.bhcl-table tr:hover td { background:rgba(0,168,120,.04); }
.biz-name { font-weight:600; color:#e2e8f0; }
.contact-line { color:var(--muted); font-size:.8rem; margin-top:.2rem; }
.contact-email a { color:#63b3ed; text-decoration:none; }
.contact-email a:hover { text-decoration:underline; }
.contact-phone { color:#a0aec0; }
.band-pill { display:inline-block; border-radius:999px; padding:.18rem .6rem; font-size:.72rem; font-weight:700; white-space:nowrap; }
.score-num { font-size:1.1rem; font-weight:700; }
.status-pill { border-radius:999px; padding:.15rem .55rem; font-size:.71rem; font-weight:600; display:inline-block; white-space:nowrap; }
.status-started   { background:rgba(221,107,32,.15); color:#f6ad55; }
.status-submitted { background:rgba(66,153,225,.15); color:#63b3ed; }
.status-completed { background:rgba(56,161,105,.15); color:#68d391; }
.meta-line { font-size:.76rem; color:#718096; margin-top:.2rem; }
.btn-view { background:rgba(0,168,120,.12); color:#00A878; border:1px solid rgba(0,168,120,.25); border-radius:6px; padding:.28rem .65rem; font-size:.76rem; font-weight:600; text-decoration:none; white-space:nowrap; }
.btn-view:hover { background:rgba(0,168,120,.22); }
.empty-state { padding:3rem; text-align:center; color:var(--muted); }
.empty-icon { font-size:2.5rem; margin-bottom:.75rem; }

/* pagination */
.pagination { display:flex; gap:.35rem; align-items:center; justify-content:center; margin-top:1.25rem; flex-wrap:wrap; }
.pg-btn { background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.12); color:#a0aec0; border-radius:7px; padding:.38rem .75rem; font-size:.8rem; text-decoration:none; }
.pg-btn:hover { background:rgba(255,255,255,.13); }
.pg-btn.current { background:rgba(0,168,120,.2); border-color:#00A878; color:#00A878; font-weight:700; }
.pg-info { color:var(--muted); font-size:.8rem; }
</style>

<div class="bhcl-wrap">

  <div class="bhcl-header">
    <h1>&#x1F4DE; BHC Leads</h1>
    <div>
      <a href="<?= SITE_URL ?>/admin/bhc-overview" class="btn-clear">&#x2190; Overview</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="bhcl-stats">
    <div class="bhcl-stat">
      <div class="val"><?= number_format((int)($stats['total'] ?? 0)) ?></div>
      <div class="lbl">Total Leads</div>
    </div>
    <div class="bhcl-stat">
      <div class="val"><?= number_format((int)($stats['completed'] ?? 0)) ?></div>
      <div class="lbl">Completed</div>
    </div>
    <div class="bhcl-stat">
      <div class="val"><?= number_format((int)($stats['with_email'] ?? 0)) ?></div>
      <div class="lbl">With Email</div>
    </div>
    <div class="bhcl-stat">
      <div class="val"><?= number_format((int)($stats['with_phone'] ?? 0)) ?></div>
      <div class="lbl">With Phone</div>
    </div>
    <div class="bhcl-stat">
      <div class="val"><?= $stats['avg_score'] !== null ? number_format((float)$stats['avg_score'], 1) : '—' ?></div>
      <div class="lbl">Avg Score</div>
    </div>
  </div>

  <!-- Filters -->
  <form method="get" action="<?= SITE_URL ?>/admin/bhc-leads">
    <div class="bhcl-filters">
      <div class="filter-group">
        <label>Search</label>
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Name, email, phone, ref…">
      </div>
      <div class="filter-group">
        <label>Band</label>
        <select name="band">
          <option value="">All Bands</option>
          <option value="needs_attention"    <?= $band==='needs_attention'    ? 'selected':'' ?>>Needs Attention</option>
          <option value="developing"         <?= $band==='developing'         ? 'selected':'' ?>>Developing</option>
          <option value="good_progress"      <?= $band==='good_progress'      ? 'selected':'' ?>>Good Progress</option>
          <option value="strong_foundations" <?= $band==='strong_foundations' ? 'selected':'' ?>>Strong Foundations</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Status</label>
        <select name="status">
          <option value="">All Statuses</option>
          <option value="started"   <?= $status==='started'   ? 'selected':'' ?>>Started</option>
          <option value="submitted" <?= $status==='submitted' ? 'selected':'' ?>>Submitted</option>
          <option value="completed" <?= $status==='completed' ? 'selected':'' ?>>Completed</option>
        </select>
      </div>
      <?php if ($availCities): ?>
      <div class="filter-group">
        <label>City</label>
        <select name="city">
          <option value="">All Cities</option>
          <?php foreach ($availCities as $c): ?>
            <option value="<?= e($c['city']) ?>" <?= $city === $c['city'] ? 'selected':'' ?>>
              <?= e(ucfirst($c['city'])) ?> (<?= (int)$c['cnt'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="filter-group">
        <label>Language</label>
        <select name="lang">
          <option value="">Any</option>
          <option value="en" <?= $lang==='en' ? 'selected':'' ?>>English</option>
          <option value="fr" <?= $lang==='fr' ? 'selected':'' ?>>French</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Period</label>
        <div class="range-tabs">
          <?php foreach ([7=>'7d',30=>'30d',90=>'90d',365=>'1y',0=>'All'] as $r => $lbl): ?>
            <a href="<?= SITE_URL ?>/admin/bhc-leads<?= leadsQS(['range'=>$r,'page'=>1]) ?>"
               class="range-tab <?= $range === $r ? 'active' : '' ?>"><?= $lbl ?></a>
          <?php endforeach; ?>
          <input type="hidden" name="range" value="<?= $range ?>">
        </div>
      </div>
      <button type="submit" class="btn-filter">&#x1F50D; Filter</button>
      <?php if ($search || $band || $status || $city || $lang): ?>
        <a href="<?= SITE_URL ?>/admin/bhc-leads<?= leadsQS(['q'=>'','band'=>'','status'=>'','city'=>'','lang'=>'','page'=>1]) ?>" class="btn-clear">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Results -->
  <div class="bhcl-table-wrap">
    <?php if ($leads): ?>
    <table class="bhcl-table">
      <thead>
        <tr>
          <th>Business / Contact</th>
          <th>Contact Info</th>
          <th>Score</th>
          <th>Status</th>
          <th>City / Lang</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $lead): ?>
        <tr>
          <td>
            <div class="biz-name"><?= e($lead['business_name'] ?: '(unnamed)') ?></div>
            <?php if ($lead['contact_name']): ?>
              <div class="contact-line">&#x1F464; <?= e($lead['contact_name']) ?></div>
            <?php endif; ?>
            <?php if ($lead['reference']): ?>
              <div class="meta-line"><?= e($lead['reference']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($lead['contact_email']): ?>
              <div class="contact-email">
                <a href="mailto:<?= e($lead['contact_email']) ?>">&#x2709;&#xFE0F; <?= e($lead['contact_email']) ?></a>
              </div>
            <?php endif; ?>
            <?php if ($lead['contact_phone']): ?>
              <div class="contact-phone">&#x1F4F1; <?= e($lead['contact_phone']) ?></div>
            <?php endif; ?>
            <?php if ($lead['business_category']): ?>
              <div class="meta-line">&#x1F3E2; <?= e(ucwords(str_replace('_',' ',$lead['business_category']))) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($lead['score_overall'] !== null): ?>
              <?php $band2 = $lead['score_band'] ?: bhc_score_band((float)$lead['score_overall']); ?>
              <?php $col = $bandColors[$band2] ?? '#718096'; ?>
              <div class="score-num" style="color:<?= $col ?>"><?= number_format((float)$lead['score_overall'], 1) ?></div>
              <div>
                <span class="band-pill" style="background:<?= $col ?>20;color:<?= $col ?>">
                  <?= e(bhc_band_label($band2)) ?>
                </span>
              </div>
            <?php else: ?>
              <span style="color:var(--muted)">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="status-pill status-<?= e($lead['status'] ?? 'started') ?>">
              <?= ucfirst(e($lead['status'] ?? 'started')) ?>
            </span>
          </td>
          <td>
            <?php if ($lead['city']): ?>
              <div>&#x1F4CD; <?= e(ucfirst($lead['city'])) ?></div>
            <?php endif; ?>
            <div class="meta-line"><?= strtoupper(e($lead['lang'] ?? 'en')) ?></div>
          </td>
          <td>
            <div><?= e(date('d M Y', strtotime($lead['created_at']))) ?></div>
            <?php if ($lead['updated_at'] && $lead['updated_at'] !== $lead['created_at']): ?>
              <div class="meta-line">upd <?= e(date('d M', strtotime($lead['updated_at']))) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <a href="<?= SITE_URL ?>/admin/bhc-assessments?id=<?= (int)$lead['id'] ?>" class="btn-view">View →</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div style="padding:.75rem 1rem;border-top:1px solid var(--border)">
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="<?= SITE_URL ?>/admin/bhc-leads<?= leadsQS(['page'=>$page-1]) ?>" class="pg-btn">&#x2039; Prev</a>
        <?php endif; ?>

        <?php for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
          <a href="<?= SITE_URL ?>/admin/bhc-leads<?= leadsQS(['page'=>$i]) ?>"
             class="pg-btn <?= $i===$page ? 'current':'' ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($page < $pages): ?>
          <a href="<?= SITE_URL ?>/admin/bhc-leads<?= leadsQS(['page'=>$page+1]) ?>" class="pg-btn">Next &#x203A;</a>
        <?php endif; ?>

        <span class="pg-info">
          <?= number_format(($page-1)*$perPage+1) ?>–<?= number_format(min($page*$perPage,$total)) ?> of <?= number_format($total) ?>
        </span>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">&#x1F4DE;</div>
      <div style="font-size:1rem;color:#a0aec0;margin-bottom:.5rem">No leads found</div>
      <div style="font-size:.85rem">Leads are assessments where a contact email or phone was provided.</div>
      <?php if ($search || $band || $status || $city || $lang): ?>
        <a href="<?= SITE_URL ?>/admin/bhc-leads" style="color:#00A878;font-size:.85rem;margin-top:.75rem;display:inline-block">Clear filters</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
