<?php
/**
 * BHC Service Enquiry — Public-facing enquiry submission form
 *
 * URL parameters:
 *   sid  = service ID (required)
 *   aid  = assessment ID (optional — pre-fills from assessment)
 *   lang = en|fr (optional — defaults to session/browser)
 *
 * Rate limit: max 5 submissions per IP per hour (tracked in session).
 */

define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';

// ── Language ───────────────────────────────────────────────────────────────
$lang = 'en';
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en','fr'])) {
    $lang = $_GET['lang'];
} elseif (isset($_SESSION['lang']) && in_array($_SESSION['lang'], ['en','fr'])) {
    $lang = $_SESSION['lang'];
}

// ── Load helpers ───────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/bhc-catalogue.php';
require_once __DIR__ . '/includes/bhc-enquiry.php';

// ── Resolve service ────────────────────────────────────────────────────────
$serviceId = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
if (!$serviceId) {
    http_response_code(400);
    die('Service not specified.');
}

$pdo     = db();
$service = bhc_get_service($pdo, $serviceId, $lang);
if (!$service) {
    http_response_code(404);
    die('Service not found.');
}

// ── Pre-fill from assessment (if provided) ─────────────────────────────────
$assessmentId = isset($_GET['aid']) ? (int)$_GET['aid'] : null;
$prefill      = [];

if ($assessmentId) {
    $aStmt = $pdo->prepare(
        "SELECT a.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
         FROM bhc_assessments a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.id = :aid"
    );
    $aStmt->execute([':aid' => $assessmentId]);
    $assessment = $aStmt->fetch(PDO::FETCH_ASSOC);

    if ($assessment) {
        $prefill = [
            'business_name'    => $assessment['business_name']     ?? '',
            'contact_name'     => $assessment['user_name']         ?? '',
            'email'            => $assessment['user_email']        ?? '',
            'phone'            => $assessment['user_phone']        ?? '',
            'location'         => $assessment['business_location'] ?? '',
            'referral_source'  => $assessment['referral_source']   ?? '',
            'gp_id'            => $assessment['gp_id']             ?? null,
        ];
    }
}

// ── Rate limit ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['bhc_enq_submissions'])) {
    $_SESSION['bhc_enq_submissions'] = [];
}
// Purge entries older than 1 hour
$oneHourAgo = time() - 3600;
$_SESSION['bhc_enq_submissions'] = array_filter(
    $_SESSION['bhc_enq_submissions'],
    fn($t) => $t > $oneHourAgo
);
$rateLimitHit = count($_SESSION['bhc_enq_submissions']) >= 5;

// ── Handle POST ────────────────────────────────────────────────────────────
$errors       = [];
$successRef   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rateLimitHit) {

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['bhc_enq_csrf'] ?? '')) {
        $errors[] = $lang === 'fr' ? 'Jeton de sécurité invalide.' : 'Invalid security token.';
    }

    if (!$errors) {
        $business_name   = trim($_POST['business_name']   ?? '');
        $contact_name    = trim($_POST['contact_name']    ?? '');
        $email           = strtolower(trim($_POST['email'] ?? ''));
        $phone           = trim($_POST['phone']           ?? '');
        $location        = trim($_POST['location']        ?? '');
        $pref_contact    = trim($_POST['preferred_contact'] ?? 'email');
        $requirements    = trim($_POST['requirements']    ?? '');
        $consent_sharing = !empty($_POST['consent_sharing']);
        $consent_mkt     = !empty($_POST['consent_marketing']);

        // Validation
        if (!$business_name) $errors[] = $lang === 'fr' ? 'Nom de l\'entreprise requis.' : 'Business name is required.';
        if (!$contact_name)  $errors[] = $lang === 'fr' ? 'Nom du contact requis.'       : 'Contact name is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $lang === 'fr' ? 'Adresse e-mail invalide.' : 'A valid email address is required.';
        }
        if (!$consent_sharing) {
            $errors[] = $lang === 'fr'
                ? 'Vous devez accepter le partage de vos coordonnées.'
                : 'You must agree to share your details with a provider.';
        }
        if (!in_array($pref_contact, ['email','phone','whatsapp','any'])) {
            $pref_contact = 'email';
        }

        if (!$errors) {
            $data = [
                'service_id'          => $serviceId,
                'service_category_id' => $service['category_id'] ?? null,
                'assessment_id'       => $assessmentId,
                'business_name'       => $business_name,
                'contact_name'        => $contact_name,
                'email'               => $email,
                'phone'               => $phone ?: null,
                'location'            => $location ?: null,
                'preferred_contact'   => $pref_contact,
                'requirements'        => $requirements ?: null,
                'referral_source'     => $_POST['referral_source'] ?? ($prefill['referral_source'] ?? null),
                'gp_id'               => $assessmentId ? ($prefill['gp_id'] ?? null) : null,
                'lang'                => $lang,
                'consent_sharing'     => $consent_sharing,
                'consent_marketing'   => $consent_mkt,
                'consent_ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            ];

            $newId = bhc_submit_enquiry($pdo, $data);

            if ($newId) {
                // Record rate-limit timestamp
                $_SESSION['bhc_enq_submissions'][] = time();

                $enq = bhc_get_enquiry($pdo, $newId);
                $successRef = $enq['ref'] ?? null;
            } else {
                $errors[] = $lang === 'fr'
                    ? 'Une erreur est survenue. Veuillez réessayer.'
                    : 'An error occurred. Please try again.';
            }
        }
    }
}

// Generate fresh CSRF token
$_SESSION['bhc_enq_csrf'] = bin2hex(random_bytes(16));

// ── Strings ────────────────────────────────────────────────────────────────
$s = $lang === 'fr' ? [
    'page_title'         => 'Demande de service',
    'heading'            => 'Demander ce service',
    'intro'              => 'Remplissez ce formulaire pour qu\'un prestataire spécialisé vous contacte.',
    'service_label'      => 'Service demandé',
    'business_name'      => 'Nom de l\'entreprise *',
    'contact_name'       => 'Nom du contact *',
    'email'              => 'Adresse e-mail *',
    'phone'              => 'Téléphone',
    'location'           => 'Localisation',
    'preferred_contact'  => 'Mode de contact préféré',
    'requirements'       => 'Décrivez vos besoins',
    'consent_sharing'    => 'J\'accepte que mes coordonnées soient partagées avec un prestataire sélectionné. *',
    'consent_marketing'  => 'J\'accepte de recevoir des informations sur d\'autres services pertinents.',
    'submit'             => 'Envoyer ma demande',
    'success_heading'    => 'Demande envoyée !',
    'success_msg'        => 'Votre demande a bien été enregistrée. Référence :',
    'success_note'       => 'Un spécialiste vous contactera dans les 2 jours ouvrables.',
    'rate_limit'         => 'Vous avez atteint la limite d\'envois. Veuillez réessayer dans une heure.',
    'pref_email'         => 'E-mail',
    'pref_phone'         => 'Téléphone',
    'pref_whatsapp'      => 'WhatsApp',
    'pref_any'           => 'Peu importe',
] : [
    'page_title'         => 'Service Enquiry',
    'heading'            => 'Enquire About This Service',
    'intro'              => 'Complete this form and a specialist will be in touch.',
    'service_label'      => 'Service requested',
    'business_name'      => 'Business name *',
    'contact_name'       => 'Contact name *',
    'email'              => 'Email address *',
    'phone'              => 'Phone number',
    'location'           => 'Location',
    'preferred_contact'  => 'Preferred contact method',
    'requirements'       => 'Describe your requirements',
    'consent_sharing'    => 'I agree to have my contact details shared with a matched service provider. *',
    'consent_marketing'  => 'I would like to receive information about other relevant services.',
    'submit'             => 'Submit enquiry',
    'success_heading'    => 'Enquiry submitted!',
    'success_msg'        => 'Your enquiry has been received. Reference:',
    'success_note'       => 'A specialist will contact you within 2 working days.',
    'rate_limit'         => 'You have reached the submission limit. Please try again in an hour.',
    'pref_email'         => 'Email',
    'pref_phone'         => 'Phone',
    'pref_whatsapp'      => 'WhatsApp',
    'pref_any'           => 'Any',
];

// ── Helper to safely restore form values ──────────────────────────────────
function fv(string $key, array $prefill = []): string
{
    if (isset($_POST[$key])) return htmlspecialchars(trim($_POST[$key]));
    if (isset($prefill[$key])) return htmlspecialchars(trim($prefill[$key]));
    return '';
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="bhce-wrap">
  <div class="bhce-card">

    <?php if ($successRef): ?>

      <div class="bhce-success">
        <div class="bhce-success__icon">✓</div>
        <h1><?= htmlspecialchars($s['success_heading']) ?></h1>
        <p><?= htmlspecialchars($s['success_msg']) ?>
           <strong><?= htmlspecialchars($successRef) ?></strong></p>
        <p class="bhce-success__note"><?= htmlspecialchars($s['success_note']) ?></p>
        <a href="business-health-check.php" class="btn btn-primary">
          <?= $lang === 'fr' ? 'Retour au bilan' : 'Back to Health Check' ?>
        </a>
      </div>

    <?php else: ?>

      <h1 class="bhce-heading"><?= htmlspecialchars($s['heading']) ?></h1>
      <p class="bhce-intro"><?= htmlspecialchars($s['intro']) ?></p>

      <div class="bhce-service-badge">
        <span class="bhce-service-badge__label"><?= htmlspecialchars($s['service_label']) ?>:</span>
        <strong><?= htmlspecialchars($service['name']) ?></strong>
      </div>

      <?php if ($rateLimitHit): ?>
        <div class="bhce-alert bhce-alert--warning">
          <?= htmlspecialchars($s['rate_limit']) ?>
        </div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="bhce-alert bhce-alert--error">
          <ul>
            <?php foreach ($errors as $err): ?>
              <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if (!$rateLimitHit): ?>
      <form method="POST" action="" class="bhce-form" novalidate>
        <input type="hidden" name="csrf_token"      value="<?= htmlspecialchars($_SESSION['bhc_enq_csrf']) ?>">
        <input type="hidden" name="referral_source" value="<?= htmlspecialchars($prefill['referral_source'] ?? ($_GET['utm_source'] ?? '')) ?>">

        <div class="bhce-form__row">
          <label for="business_name"><?= htmlspecialchars($s['business_name']) ?></label>
          <input type="text" id="business_name" name="business_name"
                 value="<?= fv('business_name', $prefill) ?>"
                 autocomplete="organization" required>
        </div>

        <div class="bhce-form__row">
          <label for="contact_name"><?= htmlspecialchars($s['contact_name']) ?></label>
          <input type="text" id="contact_name" name="contact_name"
                 value="<?= fv('contact_name', $prefill) ?>"
                 autocomplete="name" required>
        </div>

        <div class="bhce-form__row">
          <label for="email"><?= htmlspecialchars($s['email']) ?></label>
          <input type="email" id="email" name="email"
                 value="<?= fv('email', $prefill) ?>"
                 autocomplete="email" required>
        </div>

        <div class="bhce-form__row">
          <label for="phone"><?= htmlspecialchars($s['phone']) ?></label>
          <input type="tel" id="phone" name="phone"
                 value="<?= fv('phone', $prefill) ?>"
                 autocomplete="tel">
        </div>

        <div class="bhce-form__row">
          <label for="location"><?= htmlspecialchars($s['location']) ?></label>
          <input type="text" id="location" name="location"
                 value="<?= fv('location', $prefill) ?>"
                 autocomplete="address-level2">
        </div>

        <div class="bhce-form__row">
          <label for="preferred_contact"><?= htmlspecialchars($s['preferred_contact']) ?></label>
          <select id="preferred_contact" name="preferred_contact">
            <?php foreach (['email','phone','whatsapp','any'] as $opt): ?>
              <option value="<?= $opt ?>"
                <?= (($_POST['preferred_contact'] ?? 'email') === $opt) ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['pref_' . $opt]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="bhce-form__row">
          <label for="requirements"><?= htmlspecialchars($s['requirements']) ?></label>
          <textarea id="requirements" name="requirements" rows="4"><?= fv('requirements') ?></textarea>
        </div>

        <div class="bhce-form__consent">
          <label class="bhce-checkbox">
            <input type="checkbox" name="consent_sharing" value="1"
                   <?= !empty($_POST['consent_sharing']) ? 'checked' : '' ?> required>
            <span><?= htmlspecialchars($s['consent_sharing']) ?></span>
          </label>
          <label class="bhce-checkbox">
            <input type="checkbox" name="consent_marketing" value="1"
                   <?= !empty($_POST['consent_marketing']) ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($s['consent_marketing']) ?></span>
          </label>
        </div>

        <button type="submit" class="btn btn-primary btn-block">
          <?= htmlspecialchars($s['submit']) ?>
        </button>
      </form>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</main>

<style>
.bhce-wrap      { max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
.bhce-card      { background: var(--card-bg, #fff); border-radius: .75rem;
                  padding: 2rem; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
.bhce-heading   { margin: 0 0 .5rem; font-size: 1.5rem; }
.bhce-intro     { color: var(--text-muted, #666); margin: 0 0 1.5rem; }
.bhce-service-badge { background: var(--accent-light, #e8f4fd);
                       border-radius: .4rem; padding: .6rem 1rem;
                       margin-bottom: 1.5rem; font-size: .9rem; }
.bhce-service-badge__label { color: var(--text-muted,#555); margin-right:.4rem; }
.bhce-alert     { border-radius: .4rem; padding: .75rem 1rem; margin-bottom: 1rem; }
.bhce-alert--error   { background:#fde8e8; color:#a00; border: 1px solid #f5c5c5; }
.bhce-alert--warning { background:#fff3cd; color:#856404; border:1px solid #ffc107; }
.bhce-alert ul  { margin: 0; padding-left: 1.25rem; }
.bhce-form__row { margin-bottom: 1.1rem; }
.bhce-form__row label { display: block; font-weight: 600;
                         margin-bottom: .3rem; font-size: .9rem; }
.bhce-form__row input,
.bhce-form__row select,
.bhce-form__row textarea {
  width: 100%; box-sizing: border-box;
  border: 1px solid var(--border,#ddd); border-radius: .4rem;
  padding: .55rem .75rem; font-size: 1rem; font-family: inherit; }
.bhce-form__row textarea { resize: vertical; }
.bhce-form__consent { margin: 1.25rem 0; }
.bhce-checkbox  { display: flex; align-items: flex-start; gap: .6rem;
                  margin-bottom: .75rem; cursor: pointer; font-size: .9rem; }
.bhce-checkbox input { margin-top: .2rem; flex-shrink: 0; }
.btn-block      { width: 100%; padding: .75rem; font-size: 1rem; }
.bhce-success   { text-align: center; padding: 1rem 0; }
.bhce-success__icon { font-size: 3rem; color: #22c55e; margin-bottom: .5rem; }
.bhce-success h1 { margin: .5rem 0; }
.bhce-success__note { color: var(--text-muted,#555); font-size: .9rem; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
