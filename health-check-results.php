<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
$pdo = db();
require_once __DIR__ . '/includes/health-check-questions.php';
require_once __DIR__ . '/includes/health-check-scoring.php';

$ref = trim($_GET['ref'] ?? '');
if ($ref === '') { http_response_code(400); die('Missing reference.'); }

$stmt = $pdo->prepare("SELECT * FROM health_assessments WHERE referral_code = ?");
$stmt->execute([$ref]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$a) { http_response_code(404); die('Assessment not found.'); }

if ($a['status'] !== 'completed') {
    header('Location: health-check.php?ref=' . urlencode($ref));
    exit;
}

// Language: explicit ?lang= wins, else the assessment's stored preference, else English
$lang = $_GET['lang'] ?? ($a['preferred_language'] ?? 'en');
$lang = in_array($lang, ['en','fr'], true) ? $lang : 'en';
$otherLang = $lang === 'en' ? 'fr' : 'en';

$answers = $a['answers_json'] ? json_decode($a['answers_json'], true) : [];
$bandInfo = get_score_band_label($a['score_band'], $lang);
$recommendations = get_health_check_recommendations($answers, $lang);

$t = function($en, $fr) use ($lang) { return $lang === 'en' ? $en : $fr; };

$categoryLabels = [
    'online_presence' => $t('Online Presence', 'Présence en ligne'),
    'customer_discovery' => $t('Customer Discovery', 'Découverte client'),
    'social_media' => $t('Social Media', 'Réseaux sociaux'),
    'customer_engagement' => $t('Customer Engagement', 'Engagement client'),
    'digital_marketing' => $t('Digital Marketing', 'Marketing digital'),
];
$categoryScores = [
    'online_presence' => $a['score_online_presence'],
    'customer_discovery' => $a['score_customer_discovery'],
    'social_media' => $a['score_social_media'],
    'customer_engagement' => $a['score_customer_engagement'],
    'digital_marketing' => $a['score_digital_marketing'],
];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $t('Your Digital Business Score', 'Votre score numérique'); ?> — 237biz</title>
<style>
  :root{--brand:#0f8a5f;--bg:#f6f8f7;--card:#fff;--text:#1c2b26;--muted:#6b7b75;--border:#e2e8e5;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);}
  .wrap{max-width:640px;margin:0 auto;padding:20px 16px 40px;}
  .score-hero{background:var(--brand);color:#fff;border-radius:16px;padding:28px 20px;text-align:center;}
  .score-hero .score{font-size:52px;font-weight:800;line-height:1;}
  .score-hero .outof{font-size:16px;opacity:.85;}
  .score-hero .band{margin-top:10px;font-size:18px;font-weight:700;}
  .score-hero .band-desc{font-size:13.5px;opacity:.9;margin-top:6px;max-width:420px;margin-left:auto;margin-right:auto;}
  .cards{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:20px;}
  .cat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;}
  .cat-card .label{font-size:12.5px;color:var(--muted);}
  .cat-card .pct{font-size:22px;font-weight:700;margin-top:4px;}
  .bar-track{height:6px;background:#e2e8e5;border-radius:3px;margin-top:8px;overflow:hidden;}
  .bar-fill{height:100%;background:var(--brand);}
  h3{margin-top:28px;margin-bottom:10px;font-size:17px;}
  .rec{background:var(--card);border:1px solid var(--border);border-left:4px solid var(--brand);border-radius:10px;padding:14px 16px;margin-bottom:10px;}
  .rec .rtitle{font-weight:700;font-size:14.5px;}
  .rec .rdesc{font-size:13.5px;color:var(--muted);margin-top:4px;}
  .cta{margin-top:26px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;text-align:center;}
  .cta a{display:inline-block;margin-top:10px;background:var(--brand);color:#fff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;}
  .lang-switch{text-align:right;font-size:12.5px;margin-bottom:6px;}
  .lang-switch a{color:var(--muted);text-decoration:none;}
</style>
</head>
<body>
<div class="wrap">
  <div class="lang-switch"><a href="?ref=<?php echo urlencode($ref); ?>&lang=<?php echo $otherLang; ?>"><?php echo $otherLang === 'fr' ? 'Français' : 'English'; ?></a></div>
  <div class="score-hero">
    <div class="score"><?php echo (int) round($a['total_score']); ?></div>
    <div class="outof">/ 100 — <?php echo $t('Digital Business Score', 'Score numérique'); ?></div>
    <div class="band"><?php echo $bandInfo['emoji']; ?> <?php echo htmlspecialchars($bandInfo['title']); ?></div>
    <div class="band-desc"><?php echo htmlspecialchars($bandInfo['description']); ?></div>
  </div>

  <div class="cards">
    <?php foreach ($categoryLabels as $key => $label): $pct = (int) round($categoryScores[$key] ?? 0); ?>
    <div class="cat-card">
      <div class="label"><?php echo htmlspecialchars($label); ?></div>
      <div class="pct"><?php echo $pct; ?>%</div>
      <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($recommendations)): ?>
  <h3><?php echo $t('Your biggest opportunities', 'Vos plus grandes opportunités'); ?></h3>
  <?php foreach ($recommendations as $rec): ?>
    <div class="rec">
      <div class="rtitle"><?php echo htmlspecialchars($rec['title']); ?></div>
      <div class="rdesc"><?php echo htmlspecialchars($rec['description']); ?></div>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <div class="cta">
    <div><?php echo $t('Want help improving your score?', 'Besoin d\'aide pour améliorer votre score ?'); ?></div>
    <a href="https://wa.me/?text=<?php echo rawurlencode($t(
        'Hi, I just completed the 237biz Digital Health Check and would like help improving my score.',
        "Bonjour, je viens de terminer le bilan numérique 237biz et j'aimerais de l'aide pour améliorer mon score."
    )); ?>"><?php echo $t('Chat with 237biz on WhatsApp', 'Discuter avec 237biz sur WhatsApp'); ?></a>
  </div>
</div>
</body>
</html>
