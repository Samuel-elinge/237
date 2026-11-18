<?php
/**
 * admin/bhc-assessments.php — List & detail view for all BHC assessments
 * Clean URL: /admin/bhc-assessments
 * ?id=N  → detail view for one assessment
 * ?band=  ?status=  ?q=  → filters (list view)
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
require_once __DIR__ . '/../includes/bhc-config.php';
require_once __DIR__ . '/../includes/bhc-scoring.php';
$pdo = db();

// ── Detail view ───────────────────────────────────────────────────────────────
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$detail   = null;
$detailAnswers = [];
if ($detailId) {
    try {
        $st = $pdo->prepare("SELECT * FROM bhc_assessments WHERE id = ?");
        $st->execute([$detailId]);
        $detail = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    if ($detail) {
        try {
            $st = $pdo->prepare("SELECT * FROM bhc_answers WHERE assessment_id = ? ORDER BY question_id");
            $st->execute([$detailId]);
            $detailAnswers = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
}

// ── List view filters ─────────────────────────────────────────────────────────
$filterBand   = $_GET['band']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterQ      = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;

$where  = [];
$params = [];
if ($filterBand)   { $where[] = 'score_band = ?';    $params[] = $filterBand; }
if ($filterStatus) { $where[] = 'status = ?';         $params[] = $filterStatus; }
if ($filterQ) {
    $where[] = '(business_name LIKE ? OR contact_email LIKE ? OR reference LIKE ? OR contact_name LIKE ?)';
    $like = '%' . $filterQ . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows = 0;
$rows      = [];
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM bhc_assessments {$whereClause}");
    $st->execute($params);
    $totalRows = (int)$st->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $st = $pdo->prepare("SELECT * FROM bhc_assessments {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$allCategories = bhc_load_categories();

// ── Page title ────────────────────────────────────────────────────────────────
$pageTitle = $detail
    ? 'Assessment ' . ($detail['reference'] ?? $detailId) . ' — Admin — 237Biz'
    : 'BHC Assessments — Admin — 237Biz';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('bhc-assessments'); adminNavAssets();
?>

<style>
.stat-card { background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:18px 20px; }
.stat-card .val { font-size:1.6rem;font-weight:900;font-family:'Fraunces',serif;line-height:1; }
.stat-card .lbl { font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px; }
.band-pill { display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700; }
.bhc-table { width:100%;border-collapse:collapse;font-size:13px; }
.bhc-table th { text-align:left;padding:8px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid rgba(255,255,255,0.08); }
.bhc-table td { padding:10px 12px;border-bottom:1px solid rgba(255,255,255,0.05); }
.bhc-table tr:last-child td { border-bottom:none; }
.bhc-table tr:hover td { background:rgba(255,255,255,0.02); }
.filter-btn { padding:6px 14px;border-radius:7px;font-size:12.5px;font-weight:600;text-decoration:none;border:1px solid rgba(255,255,255,0.1);color:var(--muted); }
.filter-btn.active { background:rgba(0,168,120,0.2);color:#00A878;border-color:rgba(0,168,120,0.4); }
.detail-row { display:flex;gap:8px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.05);flex-wrap:wrap;align-items:flex-start; }
.detail-row .dk { width:200px;flex-shrink:0;font-size:12px;color:var(--muted);padding-top:1px; }
.detail-row .dv { flex:1;font-size:13px; }
.answer-row { display:flex;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04);align-items:flex-start; }
.cat-bar-wrap { flex:1;min-width:60px;height:5px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden; }
</style>

<?php if ($detail): // ══ DETAIL VIEW ══════════════════════════════════════════ ?>

<?php
$band   = $detail['score_band'] ?? bhc_score_band((float)($detail['total_score'] ?? 0));
$col    = bhc_band_color($band);
$score  = $detail['total_score'] !== null ? round((float)$detail['total_score']) : '—';
$catScores = [
    'online_visibility'    => (float)($detail['score_online_visibility']    ?? 0),
    'customer_acquisition' => (float)($detail['score_customer_acquisition'] ?? 0),
    'customer_trust'       => (float)($detail['score_customer_trust']       ?? 0),
    'digital_presence'     => (float)($detail['score_digital_presence']     ?? 0),
    'customer_management'  => (float)($detail['score_customer_management']  ?? 0),
    'marketing_growth'     => (float)($detail['score_marketing_growth']     ?? 0),
    'business_operations'  => (float)($detail['score_business_operations']  ?? 0),
];
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin/">Admin</a> › <a href="<?= SITE_URL ?>/admin/bhc-overview">BHC</a> › <a href="<?= SITE_URL ?>/admin/bhc-assessments">Assessments</a> › <span><?= e($detail['reference'] ?? '#'.$detailId) ?></span></nav>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.3rem,3vw,1.8rem);margin:0;">&#x1FA7A; <?= e($detail['business_name'] ?? 'Assessment') ?></h1>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php if (!empty($detail['contact_email'])): ?>
        <a href="mailto:<?= e($detail['contact_email']) ?>" style="padding:8px 16px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;font-size:12.5px;color:var(--muted);text-decoration:none;">&#x2709; Email</a>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/admin/bhc-assessments" style="padding:8px 16px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;font-size:12.5px;color:var(--muted);text-decoration:none;">← Back to list</a>
      </div>
    </div>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <!-- Score hero + details -->
  <div style="display:grid;grid-template-columns:auto 1fr;gap:20px;align-items:start;margin-bottom:1.5rem;flex-wrap:wrap;">
    <div style="text-align:center;background:rgba(255,255,255,0.03);border:1px solid <?= $col ?>33;border-radius:16px;padding:24px 32px;">
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Overall Score</div>
      <div style="width:90px;height:90px;border-radius:50%;border:6px solid <?= $col ?>;display:flex;align-items:center;justify-content:center;margin:0 auto;">
        <span style="font-size:28px;font-weight:900;color:<?= $col ?>;"><?= $score ?></span>
      </div>
      <div style="margin-top:10px;"><span class="band-pill" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44;"><?= bhc_band_label($band) ?></span></div>
      <div style="margin-top:8px;font-size:11px;color:var(--muted);"><?= e($detail['reference'] ?? '') ?></div>
    </div>

    <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px;">
      <div class="detail-row"><span class="dk">Business Name</span><span class="dv" style="font-weight:600;"><?= e($detail['business_name'] ?? '—') ?></span></div>
      <div class="detail-row"><span class="dk">Contact Name</span><span class="dv"><?= e($detail['contact_name'] ?? '—') ?></span></div>
      <div class="detail-row"><span class="dk">Email</span><span class="dv"><?= e($detail['contact_email'] ?? '—') ?></span></div>
      <?php if (!empty($detail['phone'])): ?>
      <div class="detail-row"><span class="dk">Phone</span><span class="dv"><?= e($detail['phone']) ?></span></div>
      <?php endif; ?>
      <div class="detail-row"><span class="dk">City</span><span class="dv"><?= e(ucfirst($detail['city'] ?? '—')) ?></span></div>
      <div class="detail-row"><span class="dk">Status</span><span class="dv"><span style="color:<?= $detail['status']==='completed'||$detail['status']==='submitted'?'#00A878':'var(--muted)' ?>;"><?= ucfirst($detail['status'] ?? '—') ?></span></span></div>
      <div class="detail-row"><span class="dk">Language</span><span class="dv"><?= strtoupper($detail['lang'] ?? 'EN') ?></span></div>
      <div class="detail-row"><span class="dk">Started</span><span class="dv"><?= !empty($detail['created_at']) ? date('d M Y H:i', strtotime($detail['created_at'])) : '—' ?></span></div>
      <?php if (!empty($detail['completed_at'])): ?>
      <div class="detail-row"><span class="dk">Completed</span><span class="dv"><?= date('d M Y H:i', strtotime($detail['completed_at'])) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($detail['gp_id'])): ?>
      <div class="detail-row"><span class="dk">Growth Partner ID</span><span class="dv"><?= (int)$detail['gp_id'] ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Category scores -->
  <h2 style="font-size:1rem;font-weight:700;margin:1.5rem 0 1rem;">Category Scores</h2>
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 20px;margin-bottom:1.5rem;">
    <?php foreach ($catScores as $slug => $pct):
      $cat   = $allCategories[$slug] ?? [];
      $label = $cat['label_en'] ?? ucwords(str_replace('_',' ',$slug));
      $c2    = $pct >= 80 ? '#38a169' : ($pct >= 60 ? '#3182ce' : ($pct >= 40 ? '#d69e2e' : '#e53e3e'));
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04);">
      <div style="width:200px;font-size:13px;flex-shrink:0;"><?= htmlspecialchars($label) ?></div>
      <div class="cat-bar-wrap"><div style="height:5px;width:<?= min(100,$pct) ?>%;background:<?= $c2 ?>;border-radius:3px;"></div></div>
      <div style="width:40px;text-align:right;font-size:13px;font-weight:700;color:<?= $c2 ?>;"><?= round($pct) ?>%</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Report link if complete -->
  <?php if (in_array($detail['status'] ?? '', ['submitted','completed'])): ?>
  <?php
  // Load the report token for linking
  $rptToken = null;
  try {
      $tst = $pdo->prepare("SELECT raw_token_hint FROM bhc_access_tokens WHERE assessment_id=? AND token_type='report' AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
      $tst->execute([(int)$detail['id']]);
      // We only store the hash — no raw token hint available; link to assessment detail for now
  } catch (Exception $e) {}
  ?>
  <div style="margin-bottom:1.5rem;">
    <p style="font-size:13px;color:var(--muted);">To view or download the report, the lead needs their email link. You can re-send via the email icon above, or access the results page directly if you have the token.</p>
  </div>
  <?php endif; ?>

  <!-- Answers -->
  <?php if ($detailAnswers): ?>
  <h2 style="font-size:1rem;font-weight:700;margin:1.5rem 0 1rem;">Individual Answers</h2>
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;overflow:hidden;margin-bottom:2rem;">
    <table class="bhc-table">
      <thead>
        <tr>
          <th>Q#</th>
          <th>Category</th>
          <th>Answer</th>
          <th>Score</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($detailAnswers as $ans):
          $opt    = $ans['answer_value'] ?? '';
          $optDef = BHC_OPTIONS[$opt] ?? [];
          $aSc    = (int)($ans['score_given'] ?? ($optDef['score'] ?? 0));
          $optLbl = $optDef['en'] ?? ucfirst(str_replace('_',' ',$opt));
        ?>
        <tr>
          <td style="color:var(--muted);font-size:11px;"><?= (int)($ans['question_id'] ?? 0) ?></td>
          <td style="font-size:12px;color:var(--muted);"><?= e(ucwords(str_replace('_',' ',$ans['category_slug'] ?? ''))) ?></td>
          <td><?= e($optLbl) ?></td>
          <td style="font-weight:700;color:<?= $aSc >= 3 ? '#38a169' : ($aSc >= 2 ? '#d69e2e' : ($aSc < 0 ? 'var(--muted)' : '#e53e3e')) ?>;"><?= $aSc < 0 ? 'N/A' : $aSc . '/4' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
</section>

<?php else: // ══ LIST VIEW ══════════════════════════════════════════════════ ?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin/">Admin</a> › <a href="<?= SITE_URL ?>/admin/bhc-overview">BHC</a> › <span>Assessments</span></nav>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:4px;">
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);margin:0;">&#x1F4CB; Assessments</h1>
      <span style="font-size:13px;color:var(--muted);"><?= number_format($totalRows) ?> total</span>
    </div>
  </div>
</div>

<section class="page-section" style="padding-top:1rem;">
<div class="container">

  <!-- Filters -->
  <form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1.25rem;align-items:center;">
    <input type="text" name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="Search business, email, ref…" style="padding:8px 14px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:var(--text);font-size:13px;min-width:220px;">
    <select name="status" style="padding:8px 12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:var(--text);font-size:13px;">
      <option value="">All Statuses</option>
      <option value="started"   <?= $filterStatus==='started'   ?'selected':'' ?>>Started</option>
      <option value="submitted" <?= $filterStatus==='submitted' ?'selected':'' ?>>Submitted</option>
      <option value="completed" <?= $filterStatus==='completed' ?'selected':'' ?>>Completed</option>
    </select>
    <select name="band" style="padding:8px 12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:var(--text);font-size:13px;">
      <option value="">All Bands</option>
      <option value="strong_foundations" <?= $filterBand==='strong_foundations'?'selected':'' ?>>Strong Foundations</option>
      <option value="good_progress"      <?= $filterBand==='good_progress'?'selected':'' ?>>Good Progress</option>
      <option value="developing"         <?= $filterBand==='developing'?'selected':'' ?>>Developing</option>
      <option value="needs_attention"    <?= $filterBand==='needs_attention'?'selected':'' ?>>Needs Attention</option>
    </select>
    <button type="submit" style="padding:8px 18px;background:#00A878;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Filter</button>
    <?php if ($filterQ||$filterBand||$filterStatus): ?>
    <a href="<?= SITE_URL ?>/admin/bhc-assessments" style="padding:8px 14px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;font-size:13px;color:var(--muted);text-decoration:none;">Clear</a>
    <?php endif; ?>
  </form>

  <!-- Table -->
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;overflow:hidden;margin-bottom:1.5rem;">
    <table class="bhc-table">
      <thead>
        <tr>
          <th>Reference</th>
          <th>Business</th>
          <th>Contact</th>
          <th>Score</th>
          <th>Band</th>
          <th>Status</th>
          <th>Lang</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:32px;">No assessments found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row):
          $band  = $row['score_band'] ?? bhc_score_band((float)($row['total_score'] ?? 0));
          $col   = bhc_band_color($band);
          $score = $row['total_score'] !== null ? round((float)$row['total_score']) : '—';
        ?>
        <tr>
          <td><code style="font-size:11px;color:var(--muted);"><?= e($row['reference'] ?? '') ?></code></td>
          <td style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($row['business_name'] ?? '—') ?></td>
          <td style="font-size:12px;">
            <div><?= e($row['contact_name'] ?? '') ?></div>
            <div style="color:var(--muted);font-size:11px;"><?= e($row['contact_email'] ?? '') ?></div>
          </td>
          <td style="font-weight:700;color:<?= $col ?>;"><?= $score ?></td>
          <td><span class="band-pill" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44;"><?= bhc_band_label($band) ?></span></td>
          <td><span style="font-size:11px;color:<?= $row['status']==='completed'||$row['status']==='submitted'?'#00A878':'var(--muted)' ?>;"><?= ucfirst($row['status'] ?? '') ?></span></td>
          <td style="font-size:11px;color:var(--muted);"><?= strtoupper($row['lang'] ?? 'en') ?></td>
          <td style="font-size:12px;color:var(--muted);"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
          <td><a href="?id=<?= (int)$row['id'] ?>" style="font-size:12px;color:#00A878;white-space:nowrap;">View →</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin-bottom:2rem;">
    <?php
    $qs = array_filter(['q'=>$filterQ,'status'=>$filterStatus,'band'=>$filterBand]);
    for ($p = 1; $p <= $totalPages; $p++):
      $qstr = http_build_query(array_merge($qs, ['page'=>$p]));
    ?>
    <a href="?<?= $qstr ?>" style="padding:6px 12px;border-radius:7px;font-size:13px;text-decoration:none;<?= $p===$page?'background:#00A878;color:#fff;':'background:rgba(255,255,255,0.04);color:var(--muted);border:1px solid rgba(255,255,255,0.1);' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

</div>
</section>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
