<?php
/**
 * Business Health Check — Results page (Phase 2, Stage 1)
 * Clean URL: /bhc-results?token=<raw_token>
 *
 * Phase 2 enhancements:
 *  - Per-category personalised recommendations (driven by each category's own band)
 *  - Key strengths section (top 2–3 categories)
 *  - Areas requiring improvement (bottom 2–3 categories)
 *  - Business Improvement Action Plan (Immediate / 30 / 60 / 90 days)
 *  - Priority tiers: High (<40%) / Medium (40–59%) / Low (60–79%)
 *  - Report view logged to bhc_reports table
 *  - EN + FR throughout
 */

define('IN_237BIZ', true);
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bhc-config.php';
require_once __DIR__ . '/includes/bhc-scoring.php';
require_once __DIR__ . '/includes/bhc-email.php';

$isFr = lang() === 'fr';

// ─── Handle resend-by-email POST ──────────────────────────────────────────────
$resendResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bhc_resend') {
    $resendEmail = trim($_POST['resend_email'] ?? '');
    if ($resendEmail && filter_var($resendEmail, FILTER_VALIDATE_EMAIL)) {
        $resendResult = bhc_resend_report_by_email($resendEmail);
    } else {
        $resendResult = 'invalid';
    }
}

// ─── Validate access token ────────────────────────────────────────────────────
$rawToken = trim($_GET['token'] ?? '');
$assessId = $rawToken ? bhc_validate_access_token($rawToken, 'report') : false;

if (!$assessId) {
    http_response_code(403);
    $page_title = ($isFr ? 'Lien invalide' : 'Invalid Link') . ' | 237biz';
    include __DIR__ . '/includes/header.php';
    ?>
    <div style="max-width:520px;margin:5rem auto;padding:0 5vw;text-align:center;">
      <h1 style="font-size:1.6rem;margin-bottom:.75rem;">
        <?= $isFr ? 'Lien invalide ou expiré' : 'Invalid or Expired Link' ?>
      </h1>
      <p style="color:var(--muted);margin-bottom:2rem;">
        <?= $isFr
          ? 'Ce lien de rapport n\'est plus valide. Entrez votre adresse email pour recevoir un nouveau lien vers votre dernier rapport.'
          : 'This report link is no longer valid. Enter your email address to receive a new link to your most recent report.' ?>
      </p>

      <?php if ($resendResult === 'sent'): ?>
        <div style="background:var(--card);border:1px solid #22c55e55;border-radius:10px;padding:1.5rem;color:#22c55e;margin-bottom:1.5rem;">
          <?= $isFr ? '✅ Email envoyé ! Vérifiez votre boîte de réception.' : '✅ Email sent! Check your inbox.' ?>
        </div>
      <?php elseif ($resendResult === 'not_found'): ?>
        <div style="background:var(--card);border:1px solid #ef444455;border-radius:10px;padding:1.5rem;color:#ef4444;margin-bottom:1.5rem;">
          <?= $isFr ? 'Aucun rapport trouvé pour cette adresse email.' : 'No report found for that email address.' ?>
        </div>
      <?php elseif ($resendResult === 'error' || $resendResult === 'invalid'): ?>
        <div style="background:var(--card);border:1px solid #ef444455;border-radius:10px;padding:1.5rem;color:#ef4444;margin-bottom:1.5rem;">
          <?= $isFr ? 'Une erreur s\'est produite. Veuillez réessayer.' : 'Something went wrong. Please try again.' ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/bhc-results" style="display:flex;flex-direction:column;gap:.75rem;max-width:380px;margin:0 auto;">
        <input type="hidden" name="action" value="bhc_resend">
        <input type="email" name="resend_email" required
               placeholder="<?= $isFr ? 'Votre adresse email' : 'Your email address' ?>"
               style="padding:.75rem 1rem;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--fg);font-size:.95rem;">
        <button type="submit" class="btn btn-primary" style="padding:.75rem;">
          <?= $isFr ? 'Renvoyer mon rapport' : 'Resend My Report' ?>
        </button>
      </form>

      <p style="margin-top:2rem;">
        <a href="/business-health-check" style="color:var(--primary);font-size:.9rem;">
          <?= $isFr ? '← Faire un nouveau bilan' : '← Start a New Assessment' ?>
        </a>
      </p>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ─── Load assessment ──────────────────────────────────────────────────────────
$pdo  = db();
$stmt = $pdo->prepare("SELECT * FROM bhc_assessments WHERE id = :id");
$stmt->execute([':id' => $assessId]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$a || !in_array($a['status'], ['submitted', 'completed'])) {
    header('Location: /business-health-check');
    exit;
}

// Use the assessment's language
$isFr = ($a['lang'] ?? 'en') === 'fr';
$lang = $isFr ? 'fr' : 'en';

// ─── Load answers ─────────────────────────────────────────────────────────────
$stmt2 = $pdo->prepare(
    "SELECT ba.question_id, ba.option_key, ba.score,
            bq.question_en, bq.question_fr, bq.category_slug
     FROM bhc_answers ba
     JOIN bhc_questions bq ON bq.id = ba.question_id
     WHERE ba.assessment_id = :id"
);
$stmt2->execute([':id' => $assessId]);
$answers = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// ─── Core score data ──────────────────────────────────────────────────────────
$total     = (float) ($a['total_score'] ?? 0);
$band      = $a['score_band'] ?? bhc_score_band($total);
$bandColor = bhc_band_color($band);
$bandLabel = bhc_band_label($band, $lang);

$allCategories = bhc_load_categories();

// Category weighted scores (points contributed to 100-pt total)
$catScores = [
    'online_visibility'    => (float)($a['score_online_visibility']    ?? 0),
    'customer_acquisition' => (float)($a['score_customer_acquisition'] ?? 0),
    'customer_trust'       => (float)($a['score_customer_trust']       ?? 0),
    'digital_presence'     => (float)($a['score_digital_presence']     ?? 0),
    'customer_management'  => (float)($a['score_customer_management']  ?? 0),
    'marketing_growth'     => (float)($a['score_marketing_growth']     ?? 0),
    'business_operations'  => (float)($a['score_business_operations']  ?? 0),
];

// Convert weighted scores → percentage within each category (0–100%)
// category_pct = (weighted_score / weight) * 100
$catPcts = [];
foreach ($catScores as $slug => $score) {
    $weight = (float)($allCategories[$slug]['weight'] ?? 1);
    $catPcts[$slug] = $weight > 0 ? min(100.0, ($score / $weight) * 100.0) : 0.0;
}

// ─── Derive per-category bands from category pct ─────────────────────────────
$catBands = [];
foreach ($catPcts as $slug => $pct) {
    $catBands[$slug] = bhc_score_band($pct);
}

// ─── Strengths and weaknesses ─────────────────────────────────────────────────
$sortedByPct = $catPcts;
arsort($sortedByPct); // highest first

$strengthSlugs  = array_keys(array_slice($sortedByPct, 0, 3, true)); // top 3
$weaknessSlugs  = array_keys(array_slice(array_reverse($sortedByPct, true), 0, 3, true)); // bottom 3

// Only include as "strength" if pct >= 60; only "weakness" if pct < 70
$strengthSlugs = array_filter($strengthSlugs, fn($s) => $catPcts[$s] >= 60);
$weaknessSlugs = array_filter($weaknessSlugs, fn($s) => $catPcts[$s] < 70);

// ─── Per-category personalised recommendations ───────────────────────────────
// For each category, query recommendations using THAT CATEGORY'S band,
// so a business with 85 overall can still get targeted recs for a 25% category.
$allCatRecs = []; // keyed by slug → array of rec rows
$recCount   = 0;

try {
    foreach ($allCategories as $slug => $cat) {
        $catBand = $catBands[$slug];
        $rStmt   = $pdo->prepare(
            "SELECT r.id, r.priority, r.title_en, r.title_fr, r.body_en, r.body_fr,
                    r.score_band, c.slug AS category_slug
             FROM bhc_recommendations r
             JOIN bhc_categories c ON c.id = r.category_id
             WHERE c.slug = :slug AND r.score_band = :band AND r.is_active = 1
             ORDER BY r.priority ASC
             LIMIT 2"
        );
        $rStmt->execute([':slug' => $slug, ':band' => $catBand]);
        $recs = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($recs) {
            $allCatRecs[$slug] = $recs;
            $recCount += count($recs);
        }
    }
} catch (Exception $e) {
    // bhc_recommendations may not exist — will use fallback below
}

// ─── Fallback recommendations if DB has none ─────────────────────────────────
if ($recCount === 0) {
    foreach ($weaknessSlugs as $slug) {
        $cat = $allCategories[$slug] ?? [];
        $pct = $catPcts[$slug];
        $allCatRecs[$slug] = [[
            'category_slug' => $slug,
            'priority'      => $pct < 40 ? 1 : ($pct < 60 ? 2 : 3),
            'title_en'      => 'Strengthen your ' . ($cat['label_en'] ?? $slug),
            'title_fr'      => 'Renforcez : ' . ($cat['label_fr'] ?? $slug),
            'body_en'       => 'Your score in this area indicates significant room for improvement. Focus on consistent practices to build a stronger foundation.',
            'body_fr'       => 'Votre score indique une marge d\'amélioration importante. Concentrez-vous sur des pratiques régulières pour construire une base solide.',
            'score_band'    => $catBands[$slug],
        ]];
        $recCount++;
    }
}

// ─── Build action plan (Immediate / 30 / 60 / 90 days) ───────────────────────
// Assign each category to a timeline bucket based on its pct:
//   Immediate  = pct < 40 (needs_attention)
//   30 days    = pct 40–59 (developing)
//   60 days    = pct 60–79 (good_progress)
//   90 days    = pct >= 80 (strategic growth)
$actionPlan = ['immediate' => [], '30d' => [], '60d' => [], '90d' => []];
foreach ($catPcts as $slug => $pct) {
    $cat = $allCategories[$slug] ?? [];
    $entry = [
        'slug'  => $slug,
        'label' => $cat['label_' . $lang] ?? $slug,
        'pct'   => round($pct),
        'recs'  => $allCatRecs[$slug] ?? [],
    ];
    if ($pct < 40) {
        $actionPlan['immediate'][] = $entry;
    } elseif ($pct < 60) {
        $actionPlan['30d'][] = $entry;
    } elseif ($pct < 80) {
        $actionPlan['60d'][] = $entry;
    } else {
        $actionPlan['90d'][] = $entry;
    }
}

// ─── Priority tiers for the recommendations summary ───────────────────────────
// High = pct < 40, Medium = 40–59, Low = 60–79
$priorityGroups = ['high' => [], 'medium' => [], 'low' => []];
foreach ($catPcts as $slug => $pct) {
    if ($pct < 40)       $priorityGroups['high'][]   = $slug;
    elseif ($pct < 60)   $priorityGroups['medium'][] = $slug;
    elseif ($pct < 80)   $priorityGroups['low'][]    = $slug;
}

// ─── Log this report view ─────────────────────────────────────────────────────
try {
    $logStmt = $pdo->prepare(
        "INSERT INTO bhc_reports
            (assessment_id, generation_type, lang, total_score, score_band, recs_shown)
         VALUES (:aid, 'view', :lang, :score, :band, :recs)"
    );
    $logStmt->execute([
        ':aid'   => $assessId,
        ':lang'  => $lang,
        ':score' => round($total, 2),
        ':band'  => $band,
        ':recs'  => $recCount,
    ]);
} catch (Exception $e) {
    // Table may not exist yet — silent
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function pct_color(float $pct): string {
    if ($pct >= 80) return '#22c55e';
    if ($pct >= 60) return '#3b82f6';
    if ($pct >= 40) return '#f59e0b';
    return '#ef4444';
}

function priority_badge(float $pct, bool $isFr): string {
    if ($pct < 40) {
        $lbl = $isFr ? 'Priorité haute' : 'High Priority';
        return '<span class="priority-badge priority-high">' . $lbl . '</span>';
    }
    if ($pct < 60) {
        $lbl = $isFr ? 'Priorité moyenne' : 'Medium Priority';
        return '<span class="priority-badge priority-medium">' . $lbl . '</span>';
    }
    if ($pct < 80) {
        $lbl = $isFr ? 'Priorité basse' : 'Low Priority';
        return '<span class="priority-badge priority-low">' . $lbl . '</span>';
    }
    $lbl = $isFr ? 'Force' : 'Strength';
    return '<span class="priority-badge priority-strength">' . $lbl . '</span>';
}

$page_title = ($isFr ? 'Votre Bilan de Santé' : 'Your Business Health Check') . ' — ' . e($a['business_name']) . ' | 237biz';
include __DIR__ . '/includes/header.php';
?>
<style>
/* ═══════════════════════════════════════════════════════════════════════════════
   BHC Results — Phase 2 Stage 1
   ═══════════════════════════════════════════════════════════════════════════════ */

/* ── Hero ──────────────────────────────────────────────────────────────────── */
.bhc-hero {
  background: linear-gradient(135deg, #1a1f36 0%, #0d1117 100%);
  color: #fff;
  padding: 3rem max(5vw, 1rem) 2.5rem;
  text-align: center;
}
.bhc-hero .sub    { opacity: .7; font-size: .95rem; margin: 0 0 .4rem; }
.bhc-hero h1      { font-size: clamp(1.4rem, 3.5vw, 2.1rem); font-weight: 700; margin: 0 0 1.75rem; }

/* Score circle */
.score-circle-wrap { margin: 0 auto 1.25rem; }
.score-circle {
  width: 150px; height: 150px; border-radius: 50%;
  background: conic-gradient(<?= $bandColor ?> <?= round($total * 3.6) ?>deg, rgba(255,255,255,.12) 0deg);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto .9rem; position: relative;
}
.score-circle::before {
  content: ''; position: absolute; inset: 11px;
  border-radius: 50%; background: #1a1f36;
}
.score-inner { position: relative; z-index: 1; text-align: center; }
.score-num   { font-size: 2.4rem; font-weight: 800; color: <?= $bandColor ?>; line-height: 1; }
.score-denom { font-size: .85rem; opacity: .6; }
.band-pill {
  display: inline-block; padding: .4rem 1.2rem;
  border-radius: 20px; background: <?= $bandColor ?>22;
  color: <?= $bandColor ?>; font-weight: 700; font-size: .9rem;
  border: 1px solid <?= $bandColor ?>55; margin-bottom: .6rem;
}
.score-ref { opacity: .6; font-size: .82rem; }

/* ── Page body ─────────────────────────────────────────────────────────────── */
.bhc-body {
  max-width: 860px; margin: 0 auto;
  padding: 2rem max(5vw, 1rem) 4rem;
}

/* ── Section header ────────────────────────────────────────────────────────── */
.sec-title {
  font-size: 1.15rem; font-weight: 700;
  margin: 2.75rem 0 1rem;
  padding-bottom: .5rem;
  border-bottom: 2px solid var(--border);
  display: flex; align-items: center; gap: .5rem;
}
.sec-icon { font-size: 1.1rem; }

/* ── Strengths / Weaknesses grid ───────────────────────────────────────────── */
.sw-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.25rem;
  margin-bottom: .5rem;
}
@media (max-width: 600px) { .sw-grid { grid-template-columns: 1fr; } }

.sw-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 1.25rem;
}
.sw-card.strength-card { border-color: #22c55e44; }
.sw-card.weakness-card { border-color: #ef444444; }

.sw-card h3 {
  font-size: .85rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .06em; margin: 0 0 .9rem;
}
.strength-card h3 { color: #22c55e; }
.weakness-card h3 { color: #ef4444; }

.sw-item {
  display: flex; align-items: center; justify-content: space-between;
  gap: .75rem; padding: .5rem 0;
  border-bottom: 1px solid var(--border);
  font-size: .88rem;
}
.sw-item:last-child { border-bottom: none; }
.sw-item .label   { font-weight: 600; flex: 1; }
.sw-item .pct-val { font-size: .82rem; color: var(--muted); white-space: nowrap; }
.sw-pct-bar { width: 60px; height: 6px; background: var(--border); border-radius: 3px; flex-shrink: 0; }
.sw-pct-fill { height: 100%; border-radius: 3px; }

/* ── Category bars ─────────────────────────────────────────────────────────── */
.cat-row { margin-bottom: 1rem; }
.cat-row-header {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: .35rem; gap: .5rem;
}
.cat-row-label { font-weight: 600; font-size: .9rem; }
.cat-row-meta  { display: flex; align-items: center; gap: .5rem; }
.cat-bar { height: 10px; background: var(--border); border-radius: 5px; overflow: hidden; }
.cat-bar-fill { height: 100%; border-radius: 5px; }

/* ── Priority badges ───────────────────────────────────────────────────────── */
.priority-badge {
  font-size: .7rem; font-weight: 700;
  padding: .2rem .55rem; border-radius: 20px;
  text-transform: uppercase; letter-spacing: .04em;
  white-space: nowrap;
}
.priority-high     { background: #ef444422; color: #ef4444; border: 1px solid #ef444444; }
.priority-medium   { background: #f59e0b22; color: #f59e0b; border: 1px solid #f59e0b44; }
.priority-low      { background: #3b82f622; color: #3b82f6; border: 1px solid #3b82f644; }
.priority-strength { background: #22c55e22; color: #22c55e; border: 1px solid #22c55e44; }

/* ── Recommendation cards ──────────────────────────────────────────────────── */
.rec-section { margin-bottom: 1.5rem; }
.rec-section-label {
  font-size: .78rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--muted);
  margin: 1.25rem 0 .6rem;
  padding-left: .75rem;
  border-left: 3px solid var(--border);
}

.rec-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px,1fr)); gap: 1rem; }

.rec-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 1.1rem 1.25rem 1.25rem;
  display: flex; flex-direction: column; gap: .4rem;
}
.rec-card-top {
  display: flex; align-items: flex-start;
  justify-content: space-between; gap: .5rem;
  margin-bottom: .2rem;
}
.rec-cat   { font-size: .75rem; color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.rec-title { font-size: .95rem; font-weight: 700; margin: 0; line-height: 1.35; }
.rec-body  { font-size: .85rem; color: var(--muted); margin: 0; line-height: 1.6; flex: 1; }

/* ── Action plan ───────────────────────────────────────────────────────────── */
.action-plan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px,1fr));
  gap: 1rem;
}

.ap-col {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}
.ap-col-header {
  padding: .75rem 1rem;
  font-size: .78rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .06em;
}
.ap-col.ap-immediate .ap-col-header { background: #ef444422; color: #ef4444; border-bottom: 1px solid #ef444433; }
.ap-col.ap-30d       .ap-col-header { background: #f59e0b22; color: #f59e0b; border-bottom: 1px solid #f59e0b33; }
.ap-col.ap-60d       .ap-col-header { background: #3b82f622; color: #3b82f6; border-bottom: 1px solid #3b82f633; }
.ap-col.ap-90d       .ap-col-header { background: #22c55e22; color: #22c55e; border-bottom: 1px solid #22c55e33; }

.ap-col-body { padding: .75rem 1rem; }
.ap-item {
  padding: .5rem 0;
  border-bottom: 1px solid var(--border);
  font-size: .85rem;
}
.ap-item:last-child   { border-bottom: none; }
.ap-item .ap-label    { font-weight: 600; margin-bottom: .2rem; }
.ap-item .ap-rec-text { font-size: .78rem; color: var(--muted); line-height: 1.5; }
.ap-empty { font-size: .82rem; color: var(--muted); font-style: italic; padding: .5rem 0; }

/* ── CTA box ───────────────────────────────────────────────────────────────── */
.bhc-cta-box {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 2rem;
  text-align: center;
  margin-top: 2.5rem;
}
.bhc-cta-box h3 { margin: 0 0 .75rem; font-size: 1.25rem; }
.bhc-cta-box p  { color: var(--muted); margin: 0 0 1.5rem; font-size: .95rem; max-width: 520px; margin-left: auto; margin-right: auto; }
.cta-buttons { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; }

/* ── Resend + branding footer ──────────────────────────────────────────────── */
.bhc-resend-hint {
  text-align: center; padding: 1.5rem 0;
  border-top: 1px solid var(--border);
  margin-top: 1rem;
}
.bhc-resend-hint p { font-size: .82rem; color: var(--muted); margin: 0 0 .3rem; }
.bhc-brand-footer {
  text-align: center; padding: 1.5rem 0 2rem;
  color: var(--muted); font-size: .82rem;
  border-top: 1px solid var(--border);
}
.bhc-brand-footer a { color: var(--primary); text-decoration: none; }
</style>

<!-- ═══════ HERO ═══════ -->
<div class="bhc-hero">
  <p class="sub"><?= $isFr ? 'Résultats de votre bilan — ' : 'Health Check Report — ' ?><?= e($a['business_name']) ?></p>
  <h1><?= $isFr ? 'Votre Bilan de Santé Numérique' : 'Your Digital Business Health Check' ?></h1>

  <div class="score-circle-wrap">
    <div class="score-circle">
      <div class="score-inner">
        <div class="score-num"><?= round($total) ?></div>
        <div class="score-denom">/100</div>
      </div>
    </div>
    <div>
      <span class="band-pill"><?= e($bandLabel) ?></span>
    </div>
    <p class="score-ref">
      <?= $isFr ? 'Réf :' : 'Ref:' ?> <?= e($a['reference']) ?>
      &nbsp;·&nbsp;
      <?= $isFr ? 'Complété le' : 'Completed' ?> <?= date('d/m/Y', strtotime($a['submitted_at'] ?? $a['created_at'])) ?>
    </p>
  </div>
</div>

<!-- ═══════ BODY ═══════ -->
<div class="bhc-body">

  <!-- ── 1. Key Strengths & Areas to Improve ── -->
  <div class="sec-title"><span class="sec-icon">🔍</span>
    <?= $isFr ? 'Forces clés &amp; axes d\'amélioration' : 'Key Strengths &amp; Areas to Improve' ?>
  </div>

  <div class="sw-grid">
    <!-- Strengths -->
    <div class="sw-card strength-card">
      <h3><?= $isFr ? '✅ Points forts' : '✅ Key Strengths' ?></h3>
      <?php if ($strengthSlugs): foreach ($strengthSlugs as $slug):
        $pct = $catPcts[$slug];
        $cat = $allCategories[$slug] ?? []; ?>
        <div class="sw-item">
          <span class="label"><?= e($cat['label_' . $lang] ?? $slug) ?></span>
          <div class="sw-pct-bar"><div class="sw-pct-fill" style="width:<?= round($pct) ?>%;background:<?= pct_color($pct) ?>;"></div></div>
          <span class="pct-val"><?= round($pct) ?>%</span>
        </div>
      <?php endforeach; else: ?>
        <p style="font-size:.85rem;color:var(--muted);margin:0;">
          <?= $isFr ? 'Continuez à développer toutes les catégories pour faire apparaître vos points forts.' : 'Keep building across all categories to reveal your strengths.' ?>
        </p>
      <?php endif; ?>
    </div>

    <!-- Areas to Improve -->
    <div class="sw-card weakness-card">
      <h3><?= $isFr ? '⚠️ À améliorer en priorité' : '⚠️ Needs Improvement' ?></h3>
      <?php if ($weaknessSlugs): foreach ($weaknessSlugs as $slug):
        $pct = $catPcts[$slug];
        $cat = $allCategories[$slug] ?? []; ?>
        <div class="sw-item">
          <span class="label"><?= e($cat['label_' . $lang] ?? $slug) ?></span>
          <div class="sw-pct-bar"><div class="sw-pct-fill" style="width:<?= round($pct) ?>%;background:<?= pct_color($pct) ?>;"></div></div>
          <span class="pct-val"><?= round($pct) ?>%</span>
        </div>
      <?php endforeach; else: ?>
        <p style="font-size:.85rem;color:var(--muted);margin:0;">
          <?= $isFr ? 'Excellente performance dans toutes les catégories !' : 'Great performance across all categories!' ?>
        </p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── 2. Category Breakdown ── -->
  <div class="sec-title"><span class="sec-icon">📊</span>
    <?= $isFr ? 'Performance par catégorie' : 'Performance by Category' ?>
  </div>

  <?php foreach ($allCategories as $slug => $cat):
    $pct    = $catPcts[$slug];
    $colour = pct_color($pct);
    $weight = (int)($cat['weight'] ?? 0);
    $pts    = $catScores[$slug] ?? 0;
  ?>
  <div class="cat-row">
    <div class="cat-row-header">
      <span class="cat-row-label"><?= e($cat['label_' . $lang] ?? $slug) ?></span>
      <div class="cat-row-meta">
        <?= priority_badge($pct, $isFr) ?>
        <span style="font-size:.8rem;color:var(--muted);"><?= round($pct) ?>% &nbsp;(<?= round($pts,1) ?>/<?= $weight ?>pts)</span>
      </div>
    </div>
    <div class="cat-bar">
      <div class="cat-bar-fill" style="width:<?= round($pct) ?>%;background:<?= $colour ?>;"></div>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- ── 3. Priority Recommendations ── -->
  <?php if ($recCount > 0): ?>
  <div class="sec-title"><span class="sec-icon">💡</span>
    <?= $isFr ? 'Recommandations personnalisées' : 'Personalised Recommendations' ?>
  </div>
  <p style="font-size:.88rem;color:var(--muted);margin:-.5rem 0 1.25rem;">
    <?= $isFr
      ? 'Ces recommandations sont basées sur les scores de chaque catégorie, et non uniquement sur votre score global.'
      : 'These recommendations are based on each category\'s individual score, not just your overall score.' ?>
  </p>

  <?php
  // Group recs by priority tier for display
  $highRecs   = [];
  $mediumRecs = [];
  $lowRecs    = [];

  foreach ($allCatRecs as $slug => $recs) {
      $pct = $catPcts[$slug];
      foreach ($recs as $rec) {
          $rec['_slug'] = $slug;
          if ($pct < 40)      $highRecs[]   = $rec;
          elseif ($pct < 60)  $mediumRecs[] = $rec;
          else                $lowRecs[]    = $rec;
      }
  }
  ?>

  <?php if ($highRecs): ?>
  <div class="rec-section-label">
    🔴 <?= $isFr ? 'HAUTE PRIORITÉ — Action immédiate requise' : 'HIGH PRIORITY — Immediate action required' ?>
  </div>
  <div class="rec-cards" style="margin-bottom:1.25rem;">
    <?php foreach ($highRecs as $rec):
      $slug     = $rec['_slug'];
      $catLabel = $allCategories[$slug]['label_' . $lang] ?? $slug; ?>
      <div class="rec-card" style="border-top:3px solid #ef4444;">
        <div class="rec-card-top">
          <div class="rec-cat"><?= e($catLabel) ?></div>
          <span class="priority-badge priority-high"><?= $isFr ? 'Haute' : 'High' ?></span>
        </div>
        <h4 class="rec-title"><?= e($rec['title_' . $lang] ?? $rec['title_en'] ?? '') ?></h4>
        <p class="rec-body"><?= e($rec['body_' . $lang] ?? $rec['body_en'] ?? '') ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($mediumRecs): ?>
  <div class="rec-section-label">
    🟡 <?= $isFr ? 'PRIORITÉ MOYENNE — Améliorations à court terme' : 'MEDIUM PRIORITY — Short-term improvements' ?>
  </div>
  <div class="rec-cards" style="margin-bottom:1.25rem;">
    <?php foreach ($mediumRecs as $rec):
      $slug     = $rec['_slug'];
      $catLabel = $allCategories[$slug]['label_' . $lang] ?? $slug; ?>
      <div class="rec-card" style="border-top:3px solid #f59e0b;">
        <div class="rec-card-top">
          <div class="rec-cat"><?= e($catLabel) ?></div>
          <span class="priority-badge priority-medium"><?= $isFr ? 'Moyenne' : 'Medium' ?></span>
        </div>
        <h4 class="rec-title"><?= e($rec['title_' . $lang] ?? $rec['title_en'] ?? '') ?></h4>
        <p class="rec-body"><?= e($rec['body_' . $lang] ?? $rec['body_en'] ?? '') ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($lowRecs): ?>
  <div class="rec-section-label">
    🔵 <?= $isFr ? 'PRIORITÉ BASSE — Opportunités de croissance' : 'LOW PRIORITY — Growth opportunities' ?>
  </div>
  <div class="rec-cards" style="margin-bottom:1.25rem;">
    <?php foreach ($lowRecs as $rec):
      $slug     = $rec['_slug'];
      $catLabel = $allCategories[$slug]['label_' . $lang] ?? $slug; ?>
      <div class="rec-card" style="border-top:3px solid #3b82f6;">
        <div class="rec-card-top">
          <div class="rec-cat"><?= e($catLabel) ?></div>
          <span class="priority-badge priority-low"><?= $isFr ? 'Basse' : 'Low' ?></span>
        </div>
        <h4 class="rec-title"><?= e($rec['title_' . $lang] ?? $rec['title_en'] ?? '') ?></h4>
        <p class="rec-body"><?= e($rec['body_' . $lang] ?? $rec['body_en'] ?? '') ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php endif; // recCount > 0 ?>

  <!-- ── 4. Business Improvement Action Plan ── -->
  <div class="sec-title"><span class="sec-icon">🗓️</span>
    <?= $isFr ? 'Plan d\'amélioration des activités' : 'Business Improvement Action Plan' ?>
  </div>
  <p style="font-size:.88rem;color:var(--muted);margin:-.5rem 0 1.25rem;">
    <?= $isFr
      ? 'Chaque catégorie est classée selon l\'urgence d\'action — basée sur votre score réel dans ce domaine.'
      : 'Each category is placed on a timeline based on its actual score, not your overall result.' ?>
  </p>

  <div class="action-plan-grid">

    <!-- Immediate -->
    <div class="ap-col ap-immediate">
      <div class="ap-col-header">
        ⚡ <?= $isFr ? 'Actions immédiates' : 'Immediate Actions' ?>
      </div>
      <div class="ap-col-body">
        <?php if ($actionPlan['immediate']): foreach ($actionPlan['immediate'] as $entry): ?>
          <div class="ap-item">
            <div class="ap-label"><?= e($entry['label']) ?> <span style="color:#ef4444;font-weight:400;">(<?= $entry['pct'] ?>%)</span></div>
            <?php if ($entry['recs']): $r = $entry['recs'][0]; ?>
              <div class="ap-rec-text"><?= e($r['title_' . $lang] ?? $r['title_en'] ?? '') ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; else: ?>
          <div class="ap-empty"><?= $isFr ? 'Aucune action immédiate requise.' : 'No immediate actions needed.' ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 30 days -->
    <div class="ap-col ap-30d">
      <div class="ap-col-header">
        📅 <?= $isFr ? '30 jours' : '30 Days' ?>
      </div>
      <div class="ap-col-body">
        <?php if ($actionPlan['30d']): foreach ($actionPlan['30d'] as $entry): ?>
          <div class="ap-item">
            <div class="ap-label"><?= e($entry['label']) ?> <span style="color:#f59e0b;font-weight:400;">(<?= $entry['pct'] ?>%)</span></div>
            <?php if ($entry['recs']): $r = $entry['recs'][0]; ?>
              <div class="ap-rec-text"><?= e($r['title_' . $lang] ?? $r['title_en'] ?? '') ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; else: ?>
          <div class="ap-empty"><?= $isFr ? 'Aucune action à 30 jours.' : 'No 30-day actions needed.' ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 60 days -->
    <div class="ap-col ap-60d">
      <div class="ap-col-header">
        📆 <?= $isFr ? '60 jours' : '60 Days' ?>
      </div>
      <div class="ap-col-body">
        <?php if ($actionPlan['60d']): foreach ($actionPlan['60d'] as $entry): ?>
          <div class="ap-item">
            <div class="ap-label"><?= e($entry['label']) ?> <span style="color:#3b82f6;font-weight:400;">(<?= $entry['pct'] ?>%)</span></div>
            <?php if ($entry['recs']): $r = $entry['recs'][0]; ?>
              <div class="ap-rec-text"><?= e($r['title_' . $lang] ?? $r['title_en'] ?? '') ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; else: ?>
          <div class="ap-empty"><?= $isFr ? 'Aucune action à 60 jours.' : 'No 60-day actions needed.' ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 90 days -->
    <div class="ap-col ap-90d">
      <div class="ap-col-header">
        🏆 <?= $isFr ? '90 jours / Croissance' : '90 Days / Growth' ?>
      </div>
      <div class="ap-col-body">
        <?php if ($actionPlan['90d']): foreach ($actionPlan['90d'] as $entry): ?>
          <div class="ap-item">
            <div class="ap-label"><?= e($entry['label']) ?> <span style="color:#22c55e;font-weight:400;">(<?= $entry['pct'] ?>%)</span></div>
            <?php if ($entry['recs']): $r = $entry['recs'][0]; ?>
              <div class="ap-rec-text"><?= e($r['title_' . $lang] ?? $r['title_en'] ?? '') ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; else: ?>
          <div class="ap-empty"><?= $isFr ? 'Travaillez d\'abord sur les priorités hautes.' : 'Focus on higher-priority areas first.' ?></div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /action-plan-grid -->

  <!-- ── 5. CTA ── -->
  <div class="bhc-cta-box">
    <h3><?= $isFr ? 'Passez à l\'étape suivante' : 'Take the Next Step' ?></h3>
    <p>
      <?php if ($total >= 80): ?>
        <?= $isFr
          ? 'Vos bases sont solides. Travaillez avec un expert 237biz pour affiner votre stratégie de croissance et maximiser vos résultats.'
          : 'Your foundations are strong. Work with a 237biz expert to refine your growth strategy and maximise your results.' ?>
      <?php elseif ($total >= 60): ?>
        <?= $isFr
          ? 'Vous êtes sur la bonne voie. Quelques améliorations ciblées peuvent propulser votre entreprise vers de meilleures performances.'
          : 'You\'re on the right track. A few targeted improvements can push your business to higher performance.' ?>
      <?php elseif ($total >= 40): ?>
        <?= $isFr
          ? 'Des actions concrètes dans les domaines prioritaires vous permettront d\'accélérer votre croissance dès maintenant.'
          : 'Concrete actions in priority areas will help you accelerate growth right now.' ?>
      <?php else: ?>
        <?= $isFr
          ? 'Une attention immédiate dans plusieurs domaines clés peut rapidement transformer vos résultats commerciaux.'
          : 'Immediate attention across several key areas can quickly transform your business results.' ?>
      <?php endif; ?>
    </p>
    <div class="cta-buttons">
      <a href="/contact" class="btn btn-primary">
        <?= $isFr ? '🤝 Parler à un expert 237biz' : '🤝 Talk to a 237biz Expert' ?>
      </a>
      <a href="/business-health-check" class="btn" style="border:1px solid var(--border);">
        <?= $isFr ? '🔄 Refaire le bilan' : '🔄 Retake Assessment' ?>
      </a>
    </div>
  </div>

  <!-- ── Resend hint ── -->
  <div class="bhc-resend-hint">
    <p><?= $isFr ? 'Besoin de retrouver ce rapport plus tard ?' : 'Need to find this report again?' ?></p>
    <p>
      <?= $isFr
        ? 'Allez sur <a href="/bhc-results" style="color:var(--primary);">237biz.net/bhc-results</a> et entrez votre email pour recevoir un nouveau lien.'
        : 'Visit <a href="/bhc-results" style="color:var(--primary);">237biz.net/bhc-results</a> and enter your email to receive a new link.' ?>
    </p>
  </div>

  <!-- ── Brand footer ── -->
  <div class="bhc-brand-footer">
    <p>
      <?= $isFr ? 'Rapport généré par' : 'Report generated by' ?>
      <strong><a href="https://237biz.net">237biz</a></strong>
      <?= $isFr ? '— La plateforme numérique des entreprises camerounaises' : '— The digital platform for Cameroonian businesses' ?>
    </p>
    <p style="margin-top:.4rem;">
      © <?= date('Y') ?> 237biz / MS IT Solutions · <a href="/privacy-policy"><?= $isFr ? 'Confidentialité' : 'Privacy' ?></a>
    </p>
  </div>

</div><!-- /bhc-body -->

<?php include __DIR__ . '/includes/footer.php'; ?>
