<?php
// TEMP DEBUG - remove after diagnosis
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * Business Health Check — public guest-first assessment
 * Clean URL: /business-health-check
 */

define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bhc-config.php';
require_once __DIR__ . '/includes/bhc-scoring.php';
require_once __DIR__ . '/includes/bhc-email.php';

$isFr   = lang() === 'fr';
$user   = current_user();
$userId = $user ? (int)$user['id'] : null;

// ─── Determine mode: landing | start | assess | done ─────────────────────────
$mode = $_GET['mode'] ?? 'landing';
$ref  = trim($_GET['ref'] ?? '');   // resume token

// GP / referral attribution
$gpRef = trim($_GET['gp'] ?? $_COOKIE['bhc_gp'] ?? '');
if ($gpRef) {
    setcookie('bhc_gp', $gpRef, time() + 86400 * 30, '/');
}

// ─── Handle resume token ──────────────────────────────────────────────────────
$resumeAssessment = null;
if ($ref) {
    $assessId = bhc_validate_access_token($ref, 'resume');
    if ($assessId) {
        $pdo  = db();
        $stmt = $pdo->prepare("SELECT * FROM bhc_assessments WHERE id = :id AND status = 'draft'");
        $stmt->execute([':id' => $assessId]);
        $resumeAssessment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($resumeAssessment) {
        $mode = 'assess';
    }
}

// ─── POST: create new assessment (business info step) ────────────────────────
$formErrors = [];
$posted = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gpVerifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'start') {
        $posted = [
            'business_name'     => trim($_POST['business_name']     ?? ''),
            'contact_name'      => trim($_POST['contact_name']       ?? ''),
            'business_category' => trim($_POST['business_category'] ?? ''),
            'city'              => trim($_POST['city']               ?? ''),
            'whatsapp'          => trim($_POST['whatsapp']           ?? ''),
            'email'             => trim($_POST['email']              ?? ''),
            'website'           => trim($_POST['website']            ?? ''),
            'social_url'        => trim($_POST['social_url']        ?? ''),
            'employee_band'     => trim($_POST['employee_band']      ?? ''),
            'lang_choice'       => in_array($_POST['lang_choice'] ?? '', ['en','fr']) ? $_POST['lang_choice'] : 'en',
            'consent_privacy'   => isset($_POST['consent_privacy'])    ? 1 : 0,
            'consent_237biz'    => isset($_POST['consent_237biz'])     ? 1 : 0,
            'consent_ms'        => isset($_POST['consent_ms'])         ? 1 : 0,
        ];

        // Validate
        if (!$posted['business_name'])   $formErrors[] = $isFr ? 'Nom de l\'entreprise requis.' : 'Business name is required.';
        if (!$posted['contact_name'])    $formErrors[] = $isFr ? 'Nom du contact requis.' : 'Contact name is required.';
        if (!$posted['whatsapp'] && !$posted['email'])
            $formErrors[] = $isFr ? 'WhatsApp ou email requis.' : 'WhatsApp or email is required.';
        if ($posted['email'] && !filter_var($posted['email'], FILTER_VALIDATE_EMAIL))
            $formErrors[] = $isFr ? 'Adresse email invalide.' : 'Invalid email address.';
        if (!$posted['consent_privacy'])
            $formErrors[] = $isFr ? 'Vous devez accepter la politique de confidentialité.' : 'You must accept the privacy notice.';

        if (!$formErrors) {
            $pdo  = db();
            $ref2 = bhc_generate_reference();
            $pdo->prepare(
                "INSERT INTO bhc_assessments
                   (reference, user_id, business_name, contact_name, business_category,
                    city, whatsapp, email, website, social_url, employee_band,
                    lang, status, referral_source, gp_ref_code,
                    consent_privacy, consent_237biz_mkt, consent_ms_mkt, consent_at,
                    ip_address, user_agent)
                 VALUES
                   (:ref, :uid, :bn, :cn, :bc,
                    :city, :wa, :email, :web, :soc, :emp,
                    :lang, 'draft', :rsrc, :gpref,
                    :cp, :c2, :cms, NOW(),
                    :ip, :ua)"
            )->execute([
                ':ref'   => $ref2,
                ':uid'   => $userId,
                ':bn'    => $posted['business_name'],
                ':cn'    => $posted['contact_name'],
                ':bc'    => $posted['business_category'],
                ':city'  => $posted['city'],
                ':wa'    => $posted['whatsapp'],
                ':email' => $posted['email'],
                ':web'   => $posted['website'] ?: null,
                ':soc'   => $posted['social_url'] ?: null,
                ':emp'   => $posted['employee_band'] ?: null,
                ':lang'  => $posted['lang_choice'],
                ':rsrc'  => $_SERVER['HTTP_REFERER'] ?? null,
                ':gpref' => $gpRef ?: null,
                ':cp'    => $posted['consent_privacy'],
                ':c2'    => $posted['consent_237biz'],
                ':cms'   => $posted['consent_ms'],
                ':ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Create resume token
            $resumeToken = bhc_create_access_token($newId, 'resume',
                new DateTime('+72 hours'));

            // Redirect to assessment with token
            header('Location: /business-health-check?mode=assess&ref=' . urlencode($resumeToken));
            exit;
        }
        $mode = 'start';
    }

    if ($action === 'save_answers' && $resumeAssessment) {
        // Auto-save answers
        $qAnswers = $_POST['answers'] ?? [];
        $pdo = db();
        $upsert = $pdo->prepare(
            "INSERT INTO bhc_answers (assessment_id, question_id, option_key, score)
             VALUES (:aid, :qid, :okey, :score)
             ON DUPLICATE KEY UPDATE option_key = VALUES(option_key), score = VALUES(score)"
        );
        foreach ($qAnswers as $qid => $key) {
            $opt = BHC_OPTIONS[$key] ?? null;
            if ($opt === null) continue;
            $upsert->execute([
                ':aid'   => $resumeAssessment['id'],
                ':qid'   => (int) $qid,
                ':okey'  => $key,
                ':score' => $opt['score'],
            ]);
        }
        // For XHR auto-saves
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    if ($action === 'submit' && $resumeAssessment) {
        $qAnswers = $_POST['answers'] ?? [];

        // Server-side scoring — never trust submitted scores
        $result = bhc_calculate_score($qAnswers);

        // Must have answered at least 15 of 21 questions (allow 6 N/A max)
        if ($result['questions_answered'] < 15) {
            $formErrors[] = $isFr
                ? 'Veuillez répondre à au moins 15 questions.'
                : 'Please answer at least 15 questions.';
        } else {
            $saved = bhc_save_scores((int)$resumeAssessment['id'], $qAnswers, $result);
            if ($saved) {
                // Create report access token
                $reportToken = bhc_create_access_token((int)$resumeAssessment['id'], 'report');

                // Create lead record
                try {
                    $pdo = db();
                    $gpId = null;
                    if ($resumeAssessment['gp_ref_code']) {
                        $gs = $pdo->prepare("SELECT id FROM users WHERE referral_code = :c LIMIT 1");
                        $gs->execute([':c' => $resumeAssessment['gp_ref_code']]);
                        $gpId = $gs->fetchColumn() ?: null;
                    }
                    $pdo->prepare(
                        "INSERT IGNORE INTO bhc_leads (assessment_id, gp_id) VALUES (:aid, :gp)"
                    )->execute([':aid' => $resumeAssessment['id'], ':gp' => $gpId]);
                } catch (Exception $e) {
                    // non-fatal
                }

                // Update assessment to completed
                $pdo = db();
                $pdo->prepare("UPDATE bhc_assessments SET status='completed', completed_at=NOW() WHERE id=:id")
                    ->execute([':id' => $resumeAssessment['id']]);

                // Send report email (non-fatal — don't block redirect on failure)
                try {
                    // Reload assessment with final scores for email
                    $eStmt = $pdo->prepare("SELECT * FROM bhc_assessments WHERE id = :id");
                    $eStmt->execute([':id' => $resumeAssessment['id']]);
                    $eAssessment = $eStmt->fetch(PDO::FETCH_ASSOC);
                    if ($eAssessment) {
                        $eLang = $eAssessment['lang'] ?? 'en';
                        bhc_send_report_email($eAssessment, $reportToken, $eLang);
                    }
                } catch (Exception $e) {
                    // Email failure is non-fatal — user still gets redirected to results
                    error_log('BHC email send failed: ' . $e->getMessage());
                }

                // Redirect to results
                header('Location: /bhc-results?token=' . urlencode($reportToken));
                exit;
            }
        }
    }
}

// ─── Load existing answers for resume ─────────────────────────────────────────
$existingAnswers = [];
if ($resumeAssessment) {
    $pdo  = db();
    $stmt = $pdo->prepare(
        "SELECT question_id, option_key FROM bhc_answers WHERE assessment_id = :id"
    );
    $stmt->execute([':id' => $resumeAssessment['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingAnswers[(int)$row['question_id']] = $row['option_key'];
    }
    $isFr = ($resumeAssessment['lang'] ?? 'en') === 'fr';
}

$allQuestions  = bhc_load_questions();
$allCategories = bhc_load_categories();
$catList       = array_values($allCategories);

// ─── Page title ───────────────────────────────────────────────────────────────
$pageTitle = $isFr ? 'Bilan de santé de votre entreprise' : 'Business Health Check';

$page_title = $pageTitle . ' | 237biz';
include __DIR__ . '/includes/header.php';
?>
<style>
/* ── BHC Wizard Styles ───────────────────────────────────────────────────── */
.bhc-hero {
  background: linear-gradient(135deg, #1a1f36 0%, #0d1117 100%);
  color: #fff;
  padding: 5rem 5vw 4rem;
  text-align: center;
}
.bhc-hero h1 { font-size: clamp(1.6rem, 4vw, 2.8rem); font-weight: 700; margin: 0 0 1rem; }
.bhc-hero p  { font-size: 1.1rem; opacity: .85; max-width: 600px; margin: 0 auto 2rem; }
.bhc-hero .hero-badges {
  display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; margin-top: 1.5rem;
}
.bhc-hero .badge {
  background: rgba(255,255,255,.12); border-radius: 20px;
  padding: .35rem .9rem; font-size: .85rem;
}
.bhc-features {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr));
  gap: 1.5rem; padding: 3rem 5vw; max-width: 900px; margin: 0 auto;
}
.bhc-feature-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 12px; padding: 1.5rem; text-align: center;
}
.bhc-feature-card .icon { font-size: 2rem; margin-bottom: .75rem; }
.bhc-feature-card h3 { font-size: 1rem; margin: 0 0 .5rem; }
.bhc-feature-card p  { font-size: .88rem; color: var(--muted); margin: 0; }
.bhc-cta-section { text-align: center; padding: 1rem 5vw 4rem; }
/* ── Form Styles ─────────────────────────────────────────────────────────── */
.bhc-form-wrap {
  max-width: 700px; margin: 0 auto; padding: 2rem 5vw 4rem;
}
.bhc-step-header { margin-bottom: 2rem; }
.bhc-step-header h2 { font-size: 1.6rem; margin: 0 0 .5rem; }
.bhc-step-header p  { color: var(--muted); margin: 0; }
.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: .4rem; }
.form-group input,
.form-group select,
.form-group textarea {
  width: 100%; padding: .65rem .9rem;
  border: 1px solid var(--border); border-radius: 8px;
  background: var(--card); color: var(--text);
  font-size: .95rem; box-sizing: border-box;
}
.form-group input:focus,
.form-group select:focus {
  outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb),.15);
}
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 540px) { .form-row { grid-template-columns: 1fr; } }
.form-hint { font-size: .82rem; color: var(--muted); margin-top: .3rem; }
.form-error-list {
  background: #fff5f5; border: 1px solid #feb2b2; border-radius: 8px;
  padding: 1rem 1.25rem; margin-bottom: 1.5rem; list-style: none;
}
.form-error-list li { color: #c53030; font-size: .9rem; margin-bottom: .3rem; }
.form-error-list li:last-child { margin-bottom: 0; }
.consent-row { display: flex; align-items: flex-start; gap: .6rem; margin-bottom: .75rem; }
.consent-row input[type=checkbox] { margin-top: .2rem; flex-shrink: 0; }
.consent-row label { font-weight: 400; font-size: .9rem; }
/* ── Assessment Wizard ───────────────────────────────────────────────────── */
.bhc-progress-bar {
  height: 4px; background: var(--border); border-radius: 2px; margin-bottom: 2rem;
}
.bhc-progress-bar .fill { height: 100%; background: var(--primary); border-radius: 2px; transition: width .3s; }
.bhc-category-header {
  display: flex; align-items: center; gap: .75rem;
  margin: 2.5rem 0 1.25rem;
}
.bhc-category-header .cat-num {
  width: 2rem; height: 2rem; border-radius: 50%;
  background: var(--primary); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .8rem; font-weight: 700; flex-shrink: 0;
}
.bhc-category-header h3 { font-size: 1.1rem; margin: 0; }
.bhc-question-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 10px; padding: 1.25rem 1.5rem; margin-bottom: 1rem;
}
.bhc-question-card .q-text { font-size: .95rem; font-weight: 600; margin-bottom: .4rem; }
.bhc-question-card .q-desc { font-size: .84rem; color: var(--muted); margin-bottom: 1rem; }
.likert-options {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem;
}
@media (max-width: 540px) { .likert-options { grid-template-columns: 1fr 1fr; } }
.likert-option { position: relative; }
.likert-option input { position: absolute; opacity: 0; width: 0; height: 0; }
.likert-option label {
  display: block; padding: .5rem .4rem; text-align: center;
  border: 1.5px solid var(--border); border-radius: 7px;
  font-size: .8rem; cursor: pointer; transition: all .15s;
  background: var(--bg);
}
.likert-option input:checked + label {
  border-color: var(--primary); background: var(--primary); color: #fff;
}
.likert-option label:hover { border-color: var(--primary); }
.bhc-submit-section { margin-top: 2.5rem; text-align: center; }
.bhc-submit-section .notice { font-size: .84rem; color: var(--muted); margin-top: .75rem; }
/* ── Answered count pill ─────────────────────────────────────────────────── */
#bhc-answer-count {
  position: sticky; bottom: 1rem;
  background: var(--primary); color: #fff;
  border-radius: 20px; padding: .5rem 1.25rem;
  font-size: .88rem; font-weight: 600; display: inline-block;
  box-shadow: 0 4px 12px rgba(0,0,0,.2);
}
.bhc-sticky-footer {
  position: sticky; bottom: 0; background: var(--bg);
  border-top: 1px solid var(--border); padding: .75rem 5vw;
  display: flex; align-items: center; justify-content: space-between;
  z-index: 10;
}
.lang-switcher { display: flex; gap: .5rem; justify-content: flex-end; margin-bottom: 1rem; }
.lang-btn { padding: .3rem .75rem; border-radius: 20px; border: 1px solid var(--border); background: none; cursor: pointer; font-size: .85rem; }
.lang-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
</style>

<?php if ($mode === 'landing'): ?>
<!-- ═══════════════════════════════════════ LANDING PAGE ═════════════════════ -->
<div class="bhc-hero">
  <h1><?= $isFr ? 'Bilan de santé gratuit de votre entreprise' : 'Free Business Health Check' ?></h1>
  <p><?= $isFr
    ? 'Évaluez vos performances numériques et commerciales en 10 minutes. Obtenez votre rapport personnalisé avec des recommandations concrètes.'
    : 'Assess your digital and business performance in under 10 minutes. Get a personalised report with actionable recommendations.'
  ?></p>
  <a href="/business-health-check?mode=start" class="btn btn-primary btn-lg">
    <?= $isFr ? 'Commencer mon bilan gratuit' : 'Start My Free Health Check' ?>
  </a>
  <div class="hero-badges">
    <span class="badge">✓ <?= $isFr ? '21 questions' : '21 questions' ?></span>
    <span class="badge">✓ <?= $isFr ? 'Score sur 100' : 'Score out of 100' ?></span>
    <span class="badge">✓ <?= $isFr ? 'Rapport PDF gratuit' : 'Free PDF report' ?></span>
    <span class="badge">✓ <?= $isFr ? 'Sans compte requis' : 'No account required' ?></span>
  </div>
</div>

<div class="bhc-features">
  <?php $features = $isFr ? [
    ['🔍','Visibilité en ligne','Êtes-vous facile à trouver ?'],
    ['🎯','Acquisition clients','Comment attirez-vous de nouveaux clients ?'],
    ['⭐','Confiance & réputation','Votre image est-elle professionnelle ?'],
    ['💻','Présence numérique','Vos outils sont-ils adaptés ?'],
    ['👥','Gestion clients','Suivez-vous bien vos clients ?'],
    ['📈','Marketing & croissance','Vos efforts marketing portent-ils leurs fruits ?'],
    ['⚙️','Opérations','Vos processus sont-ils efficaces ?'],
  ] : [
    ['🔍','Online Visibility','Are you easy to find online?'],
    ['🎯','Customer Acquisition','How well do you attract new customers?'],
    ['⭐','Trust & Reputation','Is your brand image professional?'],
    ['💻','Digital Presence','Are your digital tools fit for purpose?'],
    ['👥','Customer Management','Do you track and follow up with customers?'],
    ['📈','Marketing & Growth','Are your marketing efforts paying off?'],
    ['⚙️','Operations','Are your business processes efficient?'],
  ];
  foreach ($features as [$icon,$title,$desc]): ?>
  <div class="bhc-feature-card">
    <div class="icon"><?= $icon ?></div>
    <h3><?= e($title) ?></h3>
    <p><?= e($desc) ?></p>
  </div>
  <?php endforeach; ?>
</div>

<div class="bhc-cta-section">
  <a href="/business-health-check?mode=start" class="btn btn-primary btn-lg">
    <?= $isFr ? 'Démarrer maintenant — c\'est gratuit' : 'Get Started — It\'s Free' ?>
  </a>
  <p style="margin-top:1rem;color:var(--muted);font-size:.88rem;">
    <?= $isFr
      ? 'Votre rapport sera envoyé à votre email. Aucun compte requis.'
      : 'Your report will be sent to your email. No account needed.'
    ?>
  </p>
</div>

<?php elseif ($mode === 'start'): ?>
<!-- ════════════════════════════════════ BUSINESS INFO FORM ══════════════════ -->
<div class="bhc-form-wrap">
  <div class="lang-switcher">
    <button class="lang-btn <?= !$isFr ? 'active' : '' ?>" onclick="setLang('en')">EN</button>
    <button class="lang-btn <?= $isFr ? 'active' : '' ?>" onclick="setLang('fr')">FR</button>
  </div>
  <div class="bhc-step-header">
    <h2><?= $isFr ? 'Votre entreprise' : 'About Your Business' ?></h2>
    <p><?= $isFr
      ? 'Ces informations nous permettent de personnaliser votre rapport.'
      : 'This helps us personalise your report.'
    ?></p>
  </div>

  <?php if ($formErrors): ?>
  <ul class="form-error-list">
    <?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <form method="POST" action="/business-health-check?mode=start" id="bhc-info-form">
    <?= gpCsrfField() ?>
    <input type="hidden" name="action" value="start">
    <input type="hidden" name="lang_choice" id="lang_choice" value="<?= $isFr ? 'fr' : 'en' ?>">

    <div class="form-row">
      <div class="form-group">
        <label for="business_name"><?= $isFr ? 'Nom de l\'entreprise *' : 'Business Name *' ?></label>
        <input type="text" id="business_name" name="business_name" required
               value="<?= e($posted['business_name'] ?? '') ?>"
               placeholder="<?= $isFr ? 'Ex: Chez Marie' : 'e.g. Marie\'s Boutique' ?>">
      </div>
      <div class="form-group">
        <label for="contact_name"><?= $isFr ? 'Votre prénom *' : 'Your Name *' ?></label>
        <input type="text" id="contact_name" name="contact_name" required
               value="<?= e($posted['contact_name'] ?? '') ?>"
               placeholder="<?= $isFr ? 'Prénom et nom' : 'First and last name' ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="business_category"><?= $isFr ? 'Secteur d\'activité' : 'Business Category' ?></label>
        <select id="business_category" name="business_category">
          <option value=""><?= $isFr ? '— Sélectionner —' : '— Select —' ?></option>
          <?php foreach (BHC_BUSINESS_CATEGORIES as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= ($posted['business_category'] ?? '') === $k ? 'selected' : '' ?>>
            <?= e($v[$isFr ? 'fr' : 'en']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="city"><?= $isFr ? 'Ville' : 'City' ?></label>
        <select id="city" name="city">
          <option value=""><?= $isFr ? '— Sélectionner —' : '— Select —' ?></option>
          <?php foreach (BHC_CITIES as $c): ?>
          <option value="<?= e($c) ?>" <?= ($posted['city'] ?? '') === $c ? 'selected' : '' ?>>
            <?= e(ucfirst($c)) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="whatsapp"><?= $isFr ? 'Numéro WhatsApp' : 'WhatsApp Number' ?></label>
        <input type="tel" id="whatsapp" name="whatsapp"
               value="<?= e($posted['whatsapp'] ?? '') ?>"
               placeholder="+237 6XX XXX XXX">
      </div>
      <div class="form-group">
        <label for="email"><?= $isFr ? 'Adresse email' : 'Email Address' ?></label>
        <input type="email" id="email" name="email"
               value="<?= e($posted['email'] ?? '') ?>"
               placeholder="vous@exemple.com">
        <div class="form-hint"><?= $isFr ? 'Pour recevoir votre rapport' : 'Your report will be sent here' ?></div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="website"><?= $isFr ? 'Site web (optionnel)' : 'Website (optional)' ?></label>
        <input type="url" id="website" name="website"
               value="<?= e($posted['website'] ?? '') ?>"
               placeholder="https://...">
      </div>
      <div class="form-group">
        <label for="social_url"><?= $isFr ? 'Réseau social principal (optionnel)' : 'Main Social Media (optional)' ?></label>
        <input type="url" id="social_url" name="social_url"
               value="<?= e($posted['social_url'] ?? '') ?>"
               placeholder="https://facebook.com/...">
      </div>
    </div>

    <div class="form-group">
      <label for="employee_band"><?= $isFr ? 'Nombre d\'employés' : 'Number of Employees' ?></label>
      <select id="employee_band" name="employee_band">
        <option value=""><?= $isFr ? '— Sélectionner —' : '— Select —' ?></option>
        <?php foreach (BHC_EMPLOYEE_BANDS as $k => $v): ?>
        <option value="<?= e($k) ?>" <?= ($posted['employee_band'] ?? '') === $k ? 'selected' : '' ?>>
          <?= e($v[$isFr ? 'fr' : 'en']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <fieldset style="border:1px solid var(--border);border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.5rem;">
      <legend style="font-weight:600;padding:0 .5rem;"><?= $isFr ? 'Consentements' : 'Consents' ?></legend>
      <div class="consent-row">
        <input type="checkbox" id="consent_privacy" name="consent_privacy" value="1"
               <?= !empty($posted['consent_privacy']) ? 'checked' : '' ?> required>
        <label for="consent_privacy">
          <?= $isFr
            ? 'J\'accepte la <a href="/privacy-policy" target="_blank">politique de confidentialité</a> et consens au traitement de mes données. *'
            : 'I accept the <a href="/privacy-policy" target="_blank">privacy notice</a> and consent to my data being processed. *'
          ?>
        </label>
      </div>
      <div class="consent-row">
        <input type="checkbox" id="consent_237biz" name="consent_237biz" value="1"
               <?= !empty($posted['consent_237biz']) ? 'checked' : '' ?>>
        <label for="consent_237biz">
          <?= $isFr
            ? 'J\'accepte de recevoir des communications de 237biz (optionnel).'
            : 'I am happy to receive communications from 237biz (optional).'
          ?>
        </label>
      </div>
      <div class="consent-row">
        <input type="checkbox" id="consent_ms" name="consent_ms" value="1"
               <?= !empty($posted['consent_ms']) ? 'checked' : '' ?>>
        <label for="consent_ms">
          <?= $isFr
            ? 'J\'accepte de recevoir des communications de MS IT Solutions (optionnel).'
            : 'I am happy to receive communications from MS IT Solutions (optional).'
          ?>
        </label>
      </div>
    </fieldset>

    <button type="submit" class="btn btn-primary" style="width:100%;padding:.85rem;">
      <?= $isFr ? 'Continuer vers le questionnaire →' : 'Continue to Questions →' ?>
    </button>
  </form>
</div>

<?php elseif ($mode === 'assess' && $resumeAssessment): ?>
<!-- ════════════════════════════════════ QUESTIONS WIZARD ════════════════════ -->
<?php
$langQ   = $resumeAssessment['lang'] ?? 'en';
$isFrQ   = $langQ === 'fr';
$totalQ  = 21;
$answered = count($existingAnswers);
?>
<div class="bhc-form-wrap">
  <div class="bhc-step-header">
    <h2><?= $isFrQ ? 'Questionnaire d\'évaluation' : 'Assessment Questions' ?></h2>
    <p><?= $isFrQ
      ? 'Répondez honnêtement à chaque question. Sélectionnez « Non applicable » si la question ne vous concerne pas.'
      : 'Answer each question honestly. Select "Not applicable" if a question doesn\'t apply to your business.'
    ?></p>
    <div style="font-size:.85rem;color:var(--muted);margin-top:.5rem;">
      <?= $isFrQ ? 'Évaluation pour' : 'Assessment for' ?>: <strong><?= e($resumeAssessment['business_name']) ?></strong>
    </div>
  </div>

  <?php if ($formErrors): ?>
  <ul class="form-error-list">
    <?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div class="bhc-progress-bar">
    <div class="fill" id="progress-fill" style="width:<?= round(($answered / $totalQ) * 100) ?>%"></div>
  </div>

  <form method="POST" action="/business-health-check?mode=assess&ref=<?= urlencode($ref) ?>" id="bhc-assess-form">
    <?= gpCsrfField() ?>
    <input type="hidden" name="action" value="submit">

    <?php $catIdx = 0; foreach ($allCategories as $slug => $cat): $catIdx++; ?>
    <div class="bhc-category-header">
      <div class="cat-num"><?= $catIdx ?></div>
      <h3><?= e($cat[$isFrQ ? 'label_fr' : 'label_en']) ?>
        <span style="font-size:.8rem;font-weight:400;color:var(--muted);">(<?= $cat['weight'] ?>pts)</span>
      </h3>
    </div>

    <?php foreach ($allQuestions[$slug] ?? [] as $q): ?>
    <div class="bhc-question-card" data-qid="<?= $q['id'] ?>">
      <div class="q-text"><?= e($q[$isFrQ ? 'question_fr' : 'question_en']) ?></div>
      <?php if (!empty($q[$isFrQ ? 'description_fr' : 'description_en'])): ?>
      <div class="q-desc"><?= e($q[$isFrQ ? 'description_fr' : 'description_en']) ?></div>
      <?php endif; ?>
      <div class="likert-options">
        <?php foreach (BHC_OPTIONS as $key => $opt):
          $checked = ($existingAnswers[$q['id']] ?? '') === $key ? 'checked' : '';
        ?>
        <div class="likert-option">
          <input type="radio" name="answers[<?= $q['id'] ?>]"
                 id="q<?= $q['id'] ?>_<?= $key ?>"
                 value="<?= $key ?>" <?= $checked ?>>
          <label for="q<?= $q['id'] ?>_<?= $key ?>"><?= e($opt[$isFrQ ? 'fr' : 'en']) ?></label>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="bhc-submit-section">
      <button type="submit" class="btn btn-primary btn-lg" style="min-width:260px;">
        <?= $isFrQ ? 'Soumettre mon évaluation' : 'Submit My Assessment' ?>
      </button>
      <div class="notice">
        <?= $isFrQ
          ? 'Votre rapport sera calculé côté serveur et envoyé par email.'
          : 'Your report will be calculated on our servers and emailed to you.'
        ?>
      </div>
    </div>
  </form>
</div>

<div class="bhc-sticky-footer">
  <span style="font-size:.88rem;color:var(--muted);" id="answer-status">
    <span id="answered-count"><?= $answered ?></span>/<?= $totalQ ?>
    <?= $isFrQ ? 'réponses' : 'answered' ?>
  </span>
  <button onclick="document.getElementById('bhc-assess-form').dispatchEvent(new Event('autosave'))"
          class="btn btn-sm" style="font-size:.8rem;">
    <?= $isFrQ ? '💾 Sauvegarder' : '💾 Save progress' ?>
  </button>
</div>

<script>
(function(){
  const form      = document.getElementById('bhc-assess-form');
  const fill      = document.getElementById('progress-fill');
  const countEl   = document.getElementById('answered-count');
  const totalQ    = <?= $totalQ ?>;
  let saveTimer   = null;

  function countAnswered(){
    const names = new Set();
    form.querySelectorAll('input[type=radio]:checked').forEach(el => {
      const m = el.name.match(/answers\[(\d+)\]/);
      if (m) names.add(m[1]);
    });
    return names.size;
  }

  function updateProgress(){
    const n = countAnswered();
    countEl.textContent = n;
    fill.style.width = Math.round((n / totalQ) * 100) + '%';
  }

  function autoSave(){
    const fd = new FormData(form);
    fd.set('action','save_answers');
    fetch(form.action, {
      method: 'POST',
      headers: {'X-Requested-With':'XMLHttpRequest'},
      body: fd
    }).catch(() => {}); // best-effort
  }

  form.addEventListener('change', () => {
    updateProgress();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(autoSave, 1500);
  });

  form.addEventListener('autosave', autoSave);

  form.addEventListener('submit', e => {
    clearTimeout(saveTimer);
    // Confirm at least 15 answers
    const n = countAnswered();
    if (n < 15) {
      e.preventDefault();
      alert('<?= $isFrQ ? "Veuillez répondre à au moins 15 questions." : "Please answer at least 15 questions." ?>');
    }
  });

  updateProgress();
})();
</script>

<?php else: ?>
<!-- Fallback: redirect to landing -->
<?php header('Location: /business-health-check'); exit; ?>
<?php endif; ?>

<script>
function setLang(l){
  document.getElementById('lang_choice').value = l;
  const url = new URL(window.location.href);
  url.searchParams.set('lang', l);
  window.location.href = url.toString();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
