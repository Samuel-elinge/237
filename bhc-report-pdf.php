<?php
/**
 * Business Health Check — PDF download endpoint (Phase 2, Stage 2)
 * Clean URL: /bhc-report-pdf?token=<raw_token>
 *
 * Phase 2 upgrades:
 *  - Per-category bands drive recommendation selection (not overall band)
 *  - Strengths / areas-to-improve derived from per-category pct
 *  - Business Improvement Action Plan (Immediate / 30d / 60d / 90d)
 *  - PDF download logged to bhc_reports table
 */

define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bhc-config.php';
require_once __DIR__ . '/includes/bhc-scoring.php';
require_once __DIR__ . '/includes/bhc-pdf.php';

// ── Validate token ────────────────────────────────────────────────────────────
$rawToken = trim($_GET['token'] ?? '');
$assessId = $rawToken ? bhc_validate_access_token($rawToken, 'report') : false;

if (!$assessId) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Invalid or expired link.';
    exit;
}

// ── Load assessment ───────────────────────────────────────────────────────────
$pdo  = db();
$stmt = $pdo->prepare("SELECT * FROM bhc_assessments WHERE id = :id");
$stmt->execute([':id' => $assessId]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$a || !in_array($a['status'], ['submitted', 'completed'])) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Report not found.';
    exit;
}

$lang  = $a['lang'] ?? 'en';
$isFr  = $lang === 'fr';
$total = (float)($a['total_score'] ?? 0);
$band  = $a['score_band'] ?? bhc_score_band($total);

// ── Load categories and compute per-category pct + bands ──────────────────────
$allCategories = bhc_load_categories();

$catScores = [
    'online_visibility'    => (float)($a['score_online_visibility']    ?? 0),
    'customer_acquisition' => (float)($a['score_customer_acquisition'] ?? 0),
    'customer_trust'       => (float)($a['score_customer_trust']       ?? 0),
    'digital_presence'     => (float)($a['score_digital_presence']     ?? 0),
    'customer_management'  => (float)($a['score_customer_management']  ?? 0),
    'marketing_growth'     => (float)($a['score_marketing_growth']     ?? 0),
    'business_operations'  => (float)($a['score_business_operations']  ?? 0),
];

// Per-category pct and bands (same logic as bhc-results.php)
$catPcts  = [];
$catBands = [];
foreach ($allCategories as $slug => $cat) {
    $weight = (float)($cat['weight'] ?? 0);
    $score  = $catScores[$slug] ?? 0.0;
    $pct    = $weight > 0 ? min(100.0, ($score / $weight) * 100.0) : 0.0;
    $catPcts[$slug]  = $pct;
    $catBands[$slug] = bhc_score_band($pct);
}

// ── Strengths and areas to improve ───────────────────────────────────────────
$sortedByPct = $catPcts;
arsort($sortedByPct);
$strengthSlugs  = array_keys(array_slice($sortedByPct, 0, 3, true));
$strengthSlugs  = array_filter($strengthSlugs, fn($s) => $catPcts[$s] >= 60);

$sortedAsc = $catPcts;
asort($sortedAsc);
$weaknessSlugs  = array_keys(array_slice($sortedAsc, 0, 3, true));
$weaknessSlugs  = array_filter($weaknessSlugs, fn($s) => $catPcts[$s] < 70);

// ── Load recommendations per-category ────────────────────────────────────────
$recommendations = [];
$recsShown = 0;
try {
    foreach ($allCategories as $slug => $cat) {
        $catBand = $catBands[$slug];
        $rStmt = $pdo->prepare(
            "SELECT r.id, r.priority, r.title_en, r.title_fr, r.body_en, r.body_fr,
                    r.score_band, c.slug AS category_slug
             FROM bhc_recommendations r
             JOIN bhc_categories c ON c.id = r.category_id
             WHERE c.slug = :slug AND r.score_band = :band AND r.is_active = 1
             ORDER BY r.priority ASC LIMIT 2"
        );
        $rStmt->execute([':slug' => $slug, ':band' => $catBand]);
        $recs = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recs as $rec) {
            $recommendations[$slug][] = $rec;
            $recsShown++;
        }
    }
} catch (Exception $e) {
    // Table not yet populated — fallback below
}

// Fallback if no seeded recommendations
if (empty($recommendations)) {
    arsort($catPcts);
    foreach (array_slice($catPcts, 0, 4, true) as $slug => $pct) {
        $cat = $allCategories[$slug] ?? [];
        if ($pct >= 80) continue;
        $recommendations[$slug][] = [
            'category_slug' => $slug,
            'title_en'      => 'Improve your ' . ($cat['label_en'] ?? $slug),
            'title_fr'      => 'Améliorez votre ' . ($cat['label_fr'] ?? $slug),
            'body_en'       => 'Your score in this area indicates room for improvement. Focus on consistent practices.',
            'body_fr'       => 'Votre score dans ce domaine indique une marge d\'amélioration.',
        ];
        $recsShown++;
    }
}

// ── Build action plan buckets ─────────────────────────────────────────────────
$actionPlan = ['immediate' => [], '30d' => [], '60d' => [], '90d' => []];
foreach ($allCategories as $slug => $cat) {
    $pct   = $catPcts[$slug];
    $recs  = $recommendations[$slug] ?? [];
    if (empty($recs)) continue;
    $entry = [
        'slug'  => $slug,
        'label' => $cat[$isFr ? 'label_fr' : 'label_en'] ?? $slug,
        'pct'   => $pct,
        'title' => $recs[0][$isFr ? 'title_fr' : 'title_en'] ?? '',
    ];
    if ($pct < 40)       $actionPlan['immediate'][] = $entry;
    elseif ($pct < 60)   $actionPlan['30d'][] = $entry;
    elseif ($pct < 80)   $actionPlan['60d'][] = $entry;
    else                 $actionPlan['90d'][] = $entry;
}

// ── Generate PDF ──────────────────────────────────────────────────────────────
$pdfBytes = bhc_generate_pdf(
    $a,
    $catScores,
    $catPcts,
    $catBands,
    $allCategories,
    $recommendations,
    $actionPlan,
    array_values($strengthSlugs),
    array_values($weaknessSlugs),
    $lang
);

// ── Log PDF download to bhc_reports ──────────────────────────────────────────
try {
    $logStmt = $pdo->prepare(
        "INSERT INTO bhc_reports
             (assessment_id, generation_type, lang, total_score, score_band, recs_shown)
         VALUES (:aid, 'pdf', :lang, :score, :band, :recs)"
    );
    $logStmt->execute([
        ':aid'   => $assessId,
        ':lang'  => $lang,
        ':score' => round($total, 2),
        ':band'  => $band,
        ':recs'  => $recsShown,
    ]);
} catch (Exception $e) {
    // bhc_reports table not yet created — silent fail
}

// ── Stream response ───────────────────────────────────────────────────────────
$filename = 'BHC-Report-' . preg_replace('/[^A-Z0-9\-]/', '', strtoupper($a['reference'] ?? 'report')) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfBytes));
header('Cache-Control: private, no-cache, no-store');
header('Pragma: no-cache');

echo $pdfBytes;
exit;
