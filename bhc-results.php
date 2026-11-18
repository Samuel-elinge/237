<?php
/**
 * Business Health Check — Results page
 * Clean URL: /bhc-results?token=<raw_token>
 */

define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bhc-config.php';
require_once __DIR__ . '/includes/bhc-scoring.php';

$isFr = lang() === 'fr';

// ─── Validate access token ────────────────────────────────────────────────────
$rawToken   = trim($_GET['token'] ?? '');
$assessId   = $rawToken ? bhc_validate_access_token($rawToken, 'report') : false;

if (!$assessId) {
    http_response_code(403);
    $page_title = ($isFr ? 'Lien invalide' : 'Invalid Link') . ' | 237biz';
    include __DIR__ . '/includes/header.php';
    echo '<div style="text-align:center;padding:5rem 5vw;">';
    echo '<h1 style="font-size:1.6rem;">' . ($isFr ? 'Lien invalide ou expiré' : 'Invalid or Expired Link') . '</h1>';
    echo '<p style="color:var(--muted);">' . ($isFr
        ? 'Ce lien de rapport n\'est plus valide. Vérifiez votre email ou refaites le bilan.'
        : 'This report link is no longer valid. Check your email or retake the assessment.') . '</p>';
    echo '<a href="/business-health-check" class="btn btn-primary">'
        . ($isFr ? 'Refaire le bilan' : 'Start New Assessment') . '</a>';
    echo '</div>';
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

// Build category answer summary
$catAnswers = [];
foreach ($answers as $ans) {
    $catAnswers[$ans['category_slug']][] = $ans;
}

// ─── Score data ───────────────────────────────────────────────────────────────
$total    = (float) ($a['total_score'] ?? 0);
$band     = $a['score_band'] ?? bhc_score_band($total);
$bandColor = bhc_band_color($band);
$bandLabel = bhc_band_label($band, $isFr ? 'fr' : 'en');

$catScores = [
    'online_visibility'    => (float)($a['score_online_visibility']    ?? 0),
    'customer_acquisition' => (float)($a['score_customer_acquisition'] ?? 0),
    'customer_trust'       => (float)($a['score_customer_trust']       ?? 0),
    'digital_presence'     => (float)($a['score_digital_presence']     ?? 0),
    'customer_management'  => (float)($a['score_customer_management']  ?? 0),
    'marketing_growth'     => (float)($a['score_marketing_growth']     ?? 0),
    'business_operations'  => (float)($a['score_business_operations']  ?? 0),
];

$allCategories = bhc_load_categories();

// ─── Recommendations (database-driven, falls back to defaults) ───────────────
$recommendations = [];
try {
    $rStmt = $pdo->prepare(
        "SELECT r.*, rc.slug as category_slug
         FROM bhc_recommendations r
         JOIN bhc_categories rc ON rc.id = r.category_id
         WHERE r.score_band = :band
         ORDER BY r.priority ASC, r.category_id ASC
         LIMIT 6"
    );
    $rStmt->execute([':band' => $band]);
    $recommendations = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table may not exist yet — use inline defaults
}

// Fallback: generate recommendations based on weakest categories
if (!$recommendations) {
    arsort($catScores); // highest first — we'll invert
    $weakCats = array_slice(array_reverse($catScores, true), 0, 3, true);
    foreach ($weakCats as $slug => $score) {
        $cat = $allCategories[$slug] ?? [];
        $recommendations[] = [
            'category_slug' => $slug,
            'title_en'      => 'Improve your ' . ($cat['label_en'] ?? $slug),
            'title_fr'      => 'Améliorez votre ' . ($cat['label_fr'] ?? $slug),
            'body_en'       => 'Your score in this area indicates room for improvement. Focus on consistent practices to build a stronger foundation.',
            'body_fr'       => 'Votre score dans ce domaine indique une marge d\'amélioration. Concentrez-vous sur des pratiques régulières pour construire une base plus solide.',
        ];
    }
}

$page_title = ($isFr ? 'Votre Bilan de Santé' : 'Your Business Health Check') . ' — ' . e($a['business_name']) . ' | 237biz';
include __DIR__ . '/includes/header.php';
?>
<style>
/* ── Results Page ────────────────────────────────────────────────────────── */
.bhc-results-hero {
  background: linear-gradient(135deg, #1a1f36 0%, #0d1117 100%);
  color: #fff; padding: 4rem 5vw 3rem; text-align: center;
}
.bhc-results-hero h1 { font-size: clamp(1.4rem, 3.5vw, 2.2rem); font-weight: 700; margin: 0 0 .5rem; }
.bhc-results-hero .sub { opacity: .75; font-size: 1rem; }
.score-circle-wrap { margin: 2rem auto; }
.score-circle {
  width: 160px; height: 160px; border-radius: 50%;
  background: conic-gradient(<?= $bandColor ?> <?= round($total * 3.6) ?>deg, rgba(255,255,255,.12) 0deg);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 1rem; position: relative;
}
.score-circle::before {
  content: ''; position: absolute; inset: 12px;
  border-radius: 50%; background: #1a1f36;
}
.score-inner {
  position: relative; z-index: 1; text-align: center;
}
.score-num { font-size: 2.4rem; font-weight: 800; color: <?= $bandColor ?>; line-height: 1; }
.score-denom { font-size: .9rem; opacity: .6; }
.band-pill {
  display: inline-block; padding: .4rem 1.2rem;
  border-radius: 20px; background: <?= $bandColor ?>22;
  color: <?= $bandColor ?>; font-weight: 700; font-size: .95rem;
  border: 1px solid <?= $bandColor ?>55;
}
/* ── Body Layout ─────────────────────────────────────────────────────────── */
.bhc-results-body { max-width: 820px; margin: 0 auto; padding: 2rem 5vw 4rem; }
.section-title { font-size: 1.25rem; font-weight: 700; margin: 2.5rem 0 1rem; padding-bottom: .5rem; border-bottom: 2px solid var(--border); }
/* ── Category Bars ───────────────────────────────────────────────────────── */
.cat-score-row { margin-bottom: 1rem; }
.cat-score-label {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: .35rem; font-size: .9rem;
}
.cat-score-label .name { font-weight: 600; }
.cat-score-label .val  { color: var(--muted); font-size: .85rem; }
.cat-bar { height: 10px; background: var(--border); border-radius: 5px; overflow: hidden; }
.cat-bar-fill { height: 100%; border-radius: 5px; transition: width .6s ease; }
/* ── Recommendation Cards ────────────────────────────────────────────────── */
.rec-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap: 1.25rem; }
.rec-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 10px; padding: 1.25rem;
}
.rec-card .rec-cat { font-size: .78rem; color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .5rem; }
.rec-card h4 { font-size: 1rem; margin: 0 0 .5rem; }
.rec-card p  { font-size: .88rem; color: var(--muted); margin: 0; line-height: 1.6; }
/* ── CTA ─────────────────────────────────────────────────────────────────── */
.bhc-cta-box {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 12px; padding: 2rem; text-align: center; margin-top: 2.5rem;
}
.bhc-cta-box h3 { margin: 0 0 .75rem; }
.bhc-cta-box p  { color: var(--muted); margin: 0 0 1.5rem; font-size: .95rem; }
.cta-buttons { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; }
/* ── Branding ────────────────────────────────────────────────────────────── */
.bhc-brand-footer {
  text-align: center; padding: 1.5rem 0 2rem;
  color: var(--muted); font-size: .82rem;
  border-top: 1px solid var(--border);
}
.bhc-brand-footer a { color: var(--primary); text-decoration: none; }
</style>

<!-- Results Hero -->
<div class="bhc-results-hero">
  <p class="sub"><?= $isFr ? 'Résultats de votre bilan — ' : 'Health Check Report — ' ?><?= e($a['business_name']) ?></p>
  <h1><?= $isFr ? 'Votre score global' : 'Your Overall Score' ?></h1>

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
    <p style="margin-top:1rem;opacity:.75;font-size:.9rem;">
      <?= $isFr
        ? 'Référence : ' . e($a['reference'])
        : 'Reference: ' . e($a['reference'])
      ?>
    </p>
  </div>
</div>

<div class="bhc-results-body">

  <!-- Category Breakdown -->
  <div class="section-title"><?= $isFr ? 'Performance par catégorie' : 'Performance by Category' ?></div>

  <?php foreach ($allCategories as $slug => $cat):
    $score   = $catScores[$slug] ?? 0;
    $weight  = (int) $cat['weight'];
    $pct     = $weight > 0 ? min(100, ($score / $weight) * 100) : 0;
    $colour  = $pct >= 80 ? '#22c55e' : ($pct >= 60 ? '#3b82f6' : ($pct >= 40 ? '#f59e0b' : '#ef4444'));
  ?>
  <div class="cat-score-row">
    <div class="cat-score-label">
      <span class="name"><?= e($cat[$isFr ? 'label_fr' : 'label_en']) ?></span>
      <span class="val"><?= round($score, 1) ?>/<?= $weight ?>pts</span>
    </div>
    <div class="cat-bar">
      <div class="cat-bar-fill" style="width:<?= round($pct) ?>%;background:<?= $colour ?>;"></div>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Recommendations -->
  <?php if ($recommendations): ?>
  <div class="section-title"><?= $isFr ? 'Recommandations prioritaires' : 'Priority Recommendations' ?></div>
  <div class="rec-cards">
    <?php foreach ($recommendations as $rec):
      $catSlug = $rec['category_slug'] ?? '';
      $catLabel = $allCategories[$catSlug][$isFr ? 'label_fr' : 'label_en'] ?? $catSlug;
    ?>
    <div class="rec-card">
      <div class="rec-cat"><?= e($catLabel) ?></div>
      <h4><?= e($rec[$isFr ? 'title_fr' : 'title_en'] ?? '') ?></h4>
      <p><?= e($rec[$isFr ? 'body_fr' : 'body_en'] ?? '') ?></p>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Next steps CTA -->
  <div class="bhc-cta-box">
    <h3><?= $isFr ? 'Passez à l\'action' : 'Take Action' ?></h3>
    <p>
      <?php if ($total >= 80): ?>
        <?= $isFr
          ? 'Excellente performance ! Continuez sur cette lancée et affinez votre stratégie de croissance.'
          : 'Outstanding performance! Keep the momentum going and refine your growth strategy.'
        ?>
      <?php elseif ($total >= 60): ?>
        <?= $isFr
          ? 'Bonne base ! Quelques améliorations ciblées peuvent faire passer votre entreprise au niveau supérieur.'
          : 'Good foundation! A few targeted improvements can take your business to the next level.'
        ?>
      <?php elseif ($total >= 40): ?>
        <?= $isFr
          ? 'Vous progressez. Des actions concrètes dans les domaines prioritaires vous permettront d\'accélérer votre croissance.'
          : 'You\'re making progress. Concrete actions in priority areas will help you accelerate growth.'
        ?>
      <?php else: ?>
        <?= $isFr
          ? 'Une attention immédiate dans plusieurs domaines clés peut rapidement transformer vos résultats.'
          : 'Immediate attention in several key areas can quickly transform your results.'
        ?>
      <?php endif; ?>
    </p>
    <div class="cta-buttons">
      <a href="/contact" class="btn btn-primary">
        <?= $isFr ? 'Parler à un expert 237biz' : 'Talk to a 237biz Expert' ?>
      </a>
      <a href="/business-health-check" class="btn" style="border:1px solid var(--border);">
        <?= $isFr ? 'Refaire le bilan' : 'Retake Assessment' ?>
      </a>
    </div>
  </div>

  <!-- Branding footer -->
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

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
