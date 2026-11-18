<?php
/**
 * Business Health Check — PDF download endpoint
 * Clean URL: /bhc-report-pdf?token=<raw_token>
 *
 * Validates the report access token, generates the PDF, and streams it.
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

$lang = $a['lang'] ?? 'en';

// ── Load category scores ──────────────────────────────────────────────────────
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

// ── Load recommendations ──────────────────────────────────────────────────────
$recommendations = [];
$band = $a['score_band'] ?? bhc_score_band((float)($a['total_score'] ?? 0));
try {
    $rStmt = $pdo->prepare(
        "SELECT r.*, rc.slug as category_slug
         FROM bhc_recommendations r
         JOIN bhc_categories rc ON rc.id = r.category_id
         WHERE r.score_band = :band
         ORDER BY r.priority ASC LIMIT 4"
    );
    $rStmt->execute([':band' => $band]);
    $recommendations = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table may not exist yet — use inline fallback
}

if (!$recommendations) {
    $sortedCat = $catScores;
    asort($sortedCat); // lowest first
    foreach (array_slice($sortedCat, 0, 3, true) as $slug => $score) {
        $cat = $allCategories[$slug] ?? [];
        $recommendations[] = [
            'category_slug' => $slug,
            'title_en'      => 'Improve your ' . ($cat['label_en'] ?? $slug),
            'title_fr'      => 'Améliorez votre ' . ($cat['label_fr'] ?? $slug),
            'body_en'       => 'Your score in this area indicates room for improvement. Focus on consistent practices.',
            'body_fr'       => 'Votre score dans ce domaine indique une marge d\'amélioration.',
        ];
    }
}

// ── Generate PDF ──────────────────────────────────────────────────────────────
$pdfBytes = bhc_generate_pdf($a, $catScores, $allCategories, $recommendations, $lang);

$filename = 'BHC-Report-' . preg_replace('/[^A-Z0-9\-]/', '', strtoupper($a['reference'] ?? 'report')) . '.pdf';

// ── Stream response ───────────────────────────────────────────────────────────
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfBytes));
header('Cache-Control: private, no-cache, no-store');
header('Pragma: no-cache');

echo $pdfBytes;
exit;
