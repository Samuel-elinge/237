<?php
require_once __DIR__ . '/includes/config.php';

$errors = [];
$sent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!empty($_POST['website_url'])) redirect(SITE_URL . '/online-presence'); // honeypot

    $name       = trim($_POST['name'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $bizName    = trim($_POST['business_name'] ?? '');
    $bizType    = trim($_POST['business_type'] ?? '');
    $hasWebsite = in_array($_POST['has_website'] ?? '', ['none','outdated','social_only']) ? $_POST['has_website'] : 'none';
    $notes      = trim($_POST['notes'] ?? '');

    if (!$name)  $errors[] = t('Your name is required.', 'Votre nom est requis.');
    if (!$phone) $errors[] = t('Phone number is required so we can reach you.', 'Le numéro de téléphone est requis pour vous contacter.');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('Please enter a valid email.', 'Veuillez entrer un email valide.');

    if (!$errors) {
        db()->prepare("INSERT INTO website_leads
            (name, phone, email, business_name, business_type, has_website, notes, source)
            VALUES (?,?,?,?,?,?,?,'online_presence_landing')")
            ->execute([$name, $phone, $email ?: null, $bizName ?: null, $bizType ?: null, $hasWebsite, $notes ?: null]);

        // Notify admin
        sendMail(SITE_EMAIL,
            'New Website Lead — ' . $name,
            '<h2 style="color:#fff;font-family:Georgia,serif;">🌐 New Online Presence Lead</h2>
             <table style="width:100%;border-collapse:collapse;font-size:0.875rem;color:rgba(255,255,255,0.8);">
               <tr><td style="padding:0.4rem 0;color:rgba(255,255,255,0.5);width:35%;">Name</td><td><strong>' . e($name) . '</strong></td></tr>
               <tr><td style="padding:0.4rem 0;color:rgba(255,255,255,0.5);">Phone</td><td>' . e($phone) . '</td></tr>
               <tr><td style="padding:0.4rem 0;color:rgba(255,255,255,0.5);">Email</td><td>' . e($email ?: '—') . '</td></tr>
               <tr><td style="padding:0.4rem 0;color:rgba(255,255,255,0.5);">Business</td><td>' . e($bizName ?: '—') . '</td></tr>
               <tr><td style="padding:0.4rem 0;color:rgba(255,255,255,0.5);">Type</td><td>' . e($bizType ?: '—') . '</td></tr>
               <tr><td style="padding:0.4rem 0;color:rgba(255,255,255,0.5);">Current online presence</td><td>' . e($hasWebsite) . '</td></tr>
             </table>
             ' . ($notes ? '<p style="margin-top:1rem;color:rgba(255,255,255,0.7);"><strong>Notes:</strong><br>' . nl2br(e($notes)) . '</p>' : '') . '
             <a href="' . SITE_URL . '/admin/leads.php" style="display:inline-block;margin-top:1rem;background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;">View in Admin →</a>'
        );

        // Confirmation to lead
        if ($email) {
            sendMail($email,
                t('We received your request — 237Biz', 'Nous avons reçu votre demande — 237Biz'),
                '<h2 style="color:#fff;font-family:Georgia,serif;">✅ ' . t('Thanks, ', 'Merci, ') . e($name) . '!</h2>
                 <p style="color:rgba(255,255,255,0.7);">' . t('We\'ve received your request for a business website. Our team will call or WhatsApp you on', 'Nous avons reçu votre demande de site web professionnel. Notre équipe vous appellera ou vous contactera sur WhatsApp au') . ' <strong style="color:#fff;">' . e($phone) . '</strong> ' . t('within 24 hours to discuss your project.', 'dans les 24 heures pour discuter de votre projet.') . '</p>
                 <p style="color:rgba(255,255,255,0.6);font-size:0.85rem;">' . t('In a hurry? Message us directly on WhatsApp using the button on the page.', 'Pressé ? Envoyez-nous un message directement sur WhatsApp avec le bouton sur la page.') . '</p>'
            );
        }

        $sent = true;
    }
}

$pageTitle = t('Get a Professional Website for Your Business — 237Biz', 'Obtenez un Site Web Professionnel pour votre Entreprise — 237Biz');
$pageDesc  = t('Custom business websites for Cameroonian SMEs. Mobile-friendly, fast, built in days. From 150,000 XAF.', 'Sites web professionnels pour les PME camerounaises. Compatible mobile, rapide, livré en quelques jours. À partir de 150 000 XAF.');

require_once __DIR__ . '/includes/header.php';

$whatsappNumber  = '447557794546'; // ← update with real WhatsApp business number
$whatsappMessage = urlencode(t('Hi! I\'m interested in getting a website for my business via 237Biz.', 'Bonjour ! Je suis intéressé(e) par un site web pour mon entreprise via 237Biz.'));
?>

<!-- HERO -->
<section style="padding:8rem 5vw 5rem;background:radial-gradient(ellipse at top,rgba(0,168,120,0.12) 0%,transparent 55%);border-bottom:1px solid var(--border);position:relative;overflow:hidden;">
  <div class="container" style="display:grid;grid-template-columns:1.1fr 0.9fr;gap:3rem;align-items:center;">
    <div>
      <span class="section-label">🌐 <?= t('Online Presence','Présence en Ligne') ?></span>
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(2rem,4.5vw,3.2rem);line-height:1.08;letter-spacing:-0.02em;margin-bottom:1.25rem;">
        <?= t('A real website for your business —','Un vrai site web pour votre entreprise —') ?>
        <em style="font-style:italic;color:var(--yellow);"><?= t('not just a Facebook page','pas juste une page Facebook') ?></em>
      </h1>
      <p style="font-size:1.05rem;color:var(--muted);max-width:520px;line-height:1.8;font-weight:300;margin-bottom:2rem;">
        <?= t('A fast, mobile-friendly website that makes your business look established — built for you, in days, not months. No tech skills needed.',
              'Un site web rapide et compatible mobile qui donne à votre entreprise une image établie — créé pour vous, en quelques jours, pas en mois. Aucune compétence technique requise.') ?>
      </p>

      <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:2.5rem;">
        <a href="#lead-form" class="btn btn-primary" style="font-size:1rem;padding:0.9rem 2rem;">
          <?= t('Get My Website →','Obtenir mon Site →') ?>
        </a>
        <a href="https://wa.me/<?= $whatsappNumber ?>?text=<?= $whatsappMessage ?>" target="_blank" rel="noopener"
           class="btn" style="background:rgba(37,211,102,0.12);color:#25D366;border:1px solid rgba(37,211,102,0.3);font-size:1rem;padding:0.9rem 2rem;display:inline-flex;align-items:center;gap:0.6rem;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
          <?= t('Chat on WhatsApp','Discuter sur WhatsApp') ?>
        </a>
      </div>

      <div style="display:flex;gap:2rem;flex-wrap:wrap;">
        <div><strong style="font-family:'Fraunces',serif;font-size:1.5rem;color:var(--yellow);">48-72h</strong><span style="display:block;font-size:0.75rem;color:var(--muted);"><?= t('Typical delivery','Livraison typique') ?></span></div>
        <div><strong style="font-family:'Fraunces',serif;font-size:1.5rem;color:var(--yellow);">150K</strong><span style="display:block;font-size:0.75rem;color:var(--muted);">XAF <?= t('starting price','prix de départ') ?></span></div>
        <div><strong style="font-family:'Fraunces',serif;font-size:1.5rem;color:var(--yellow);">100%</strong><span style="display:block;font-size:0.75rem;color:var(--muted);"><?= t('Mobile-friendly','Compatible mobile') ?></span></div>
      </div>
    </div>

    <!-- Visual mock -->
    <div style="position:relative;">
      <div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.25rem;box-shadow:0 30px 60px -20px rgba(0,0,0,0.5);">
        <div style="display:flex;gap:0.4rem;margin-bottom:1rem;">
          <span style="width:10px;height:10px;border-radius:50%;background:#ff5f56;"></span>
          <span style="width:10px;height:10px;border-radius:50%;background:#ffbd2e;"></span>
          <span style="width:10px;height:10px;border-radius:50%;background:#27c93f;"></span>
        </div>
        <div style="background:linear-gradient(135deg,#0D2E1A,#1A3A26);border-radius:10px;padding:1.5rem;margin-bottom:0.75rem;">
          <div style="width:60%;height:10px;background:rgba(245,200,66,0.4);border-radius:4px;margin-bottom:0.6rem;"></div>
          <div style="width:90%;height:7px;background:rgba(255,255,255,0.15);border-radius:4px;margin-bottom:0.4rem;"></div>
          <div style="width:75%;height:7px;background:rgba(255,255,255,0.15);border-radius:4px;margin-bottom:1rem;"></div>
          <div style="width:120px;height:32px;background:var(--green);border-radius:6px;"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
          <div style="background:rgba(255,255,255,0.04);border-radius:8px;height:60px;"></div>
          <div style="background:rgba(255,255,255,0.04);border-radius:8px;height:60px;"></div>
        </div>
      </div>
      <div style="position:absolute;bottom:-1rem;right:-1rem;background:var(--yellow);color:var(--dark);padding:0.6rem 1rem;border-radius:10px;font-weight:600;font-size:0.8rem;box-shadow:0 10px 30px rgba(0,0,0,0.3);">
        ✓ <?= t('Live on Google','En ligne sur Google') ?>
      </div>
    </div>
  </div>
</section>

<!-- PROBLEM / WHY IT MATTERS -->
<section class="page-section">
  <div class="container">
    <div style="text-align:center;max-width:680px;margin:0 auto 3rem;">
      <span class="section-label"><?= t('Why it matters','Pourquoi c\'est important') ?></span>
      <h2 class="section-title"><?= t('Customers judge your business in seconds','Les clients jugent votre entreprise en quelques secondes') ?></h2>
      <p class="section-sub">
        <?= t('A WhatsApp number and a Facebook page aren\'t enough anymore. A real website builds trust the moment someone searches your name.',
              'Un numéro WhatsApp et une page Facebook ne suffisent plus. Un vrai site web inspire confiance dès qu\'on recherche votre nom.') ?>
      </p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.5rem;">
      <?php
      $reasons = [
          ['🔍', t('Found on Google','Trouvé sur Google'), t('Show up when customers search for businesses like yours.', 'Apparaissez quand les clients recherchent des entreprises comme la vôtre.')],
          ['📱', t('Looks great on phone','Beau sur téléphone'), t('Most Cameroonians browse on mobile — your site will too.', 'La plupart des Camerounais naviguent sur mobile — votre site aussi.')],
          ['💼', t('Looks established','Image établie'), t('A real website signals you\'re a serious, trustworthy business.', 'Un vrai site web montre que vous êtes une entreprise sérieuse et fiable.')],
          ['⚡', t('Built fast','Construit rapidement'), t('Most sites go live within 48-72 hours of our call.', 'La plupart des sites sont en ligne 48-72 heures après notre appel.')],
      ];
      foreach ($reasons as [$icon, $title, $desc]): ?>
        <div class="listing-widget" style="text-align:center;">
          <div style="font-size:2rem;margin-bottom:0.75rem;"><?= $icon ?></div>
          <h4 style="margin-bottom:0.5rem;"><?= $title ?></h4>
          <p style="font-size:0.83rem;color:var(--muted);line-height:1.6;"><?= $desc ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- WHAT'S INCLUDED -->
<section class="page-section" style="background:rgba(255,255,255,0.01);border-top:1px solid var(--border);border-bottom:1px solid var(--border);">
  <div class="container">
    <div style="text-align:center;max-width:680px;margin:0 auto 3rem;">
      <span class="section-label"><?= t('What you get','Ce que vous obtenez') ?></span>
      <h2 class="section-title"><?= t('Everything included, nothing extra to figure out','Tout est inclus, rien à deviner') ?></h2>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.25rem;max-width:900px;margin:0 auto;">
      <?php
      $includes = [
          t('Custom design matching your brand','Design personnalisé à l\'image de votre marque'),
          t('Up to 5 pages (Home, About, Services, Gallery, Contact)','Jusqu\'à 5 pages (Accueil, À propos, Services, Galerie, Contact)'),
          t('Mobile & tablet optimized','Optimisé mobile et tablette'),
          t('WhatsApp click-to-chat button','Bouton WhatsApp cliquable'),
          t('Google Maps location embed','Carte Google Maps intégrée'),
          t('Contact form to your email','Formulaire de contact vers votre email'),
          t('Free domain setup guidance','Aide à la configuration du domaine'),
          t('Hosting included for first year','Hébergement inclus la première année'),
          t('Basic SEO so people can find you','SEO de base pour être trouvé'),
      ];
      foreach ($includes as $item): ?>
        <div style="display:flex;align-items:flex-start;gap:0.6rem;padding:0.5rem 0;">
          <span style="color:var(--green);flex-shrink:0;font-size:1rem;">✓</span>
          <span style="font-size:0.875rem;color:var(--muted);line-height:1.5;"><?= $item ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section class="page-section">
  <div class="container">
    <div style="text-align:center;max-width:680px;margin:0 auto 3rem;">
      <span class="section-label"><?= t('Simple process','Processus simple') ?></span>
      <h2 class="section-title"><?= t('From request to live website','De la demande au site en ligne') ?></h2>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.5rem;max-width:1000px;margin:0 auto;">
      <?php
      $steps = [
          ['1', t('Tell us about your business','Parlez-nous de votre entreprise'), t('Fill the form below or message us on WhatsApp.','Remplissez le formulaire ou contactez-nous sur WhatsApp.')],
          ['2', t('We call to confirm details','Nous appelons pour confirmer'), t('A quick call to understand what you need and agree pricing.','Un appel rapide pour comprendre vos besoins et fixer le prix.')],
          ['3', t('We build your site','Nous créons votre site'), t('Your website is designed and built within 48-72 hours.','Votre site est conçu et créé en 48-72 heures.')],
          ['4', t('You go live','Vous êtes en ligne'), t('We launch your site and show you how to keep it updated.','Nous lançons votre site et vous montrons comment le mettre à jour.')],
      ];
      foreach ($steps as [$num, $title, $desc]): ?>
        <div style="text-align:center;">
          <div style="width:48px;height:48px;border-radius:50%;background:rgba(0,168,120,0.12);border:1px solid rgba(0,168,120,0.3);display:flex;align-items:center;justify-content:center;font-family:'Fraunces',serif;font-weight:700;color:var(--green);margin:0 auto 1rem;">
            <?= $num ?>
          </div>
          <h4 style="margin-bottom:0.4rem;font-size:0.95rem;"><?= $title ?></h4>
          <p style="font-size:0.8rem;color:var(--muted);line-height:1.6;"><?= $desc ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- LEAD FORM -->
<section class="page-section" id="lead-form" style="border-top:1px solid var(--border);background:linear-gradient(180deg,rgba(0,168,120,0.04) 0%,transparent 100%);">
  <div class="container" style="max-width:640px;">
    <div style="text-align:center;margin-bottom:2rem;">
      <span class="section-label"><?= t('Get Started','Commencer') ?></span>
      <h2 class="section-title"><?= t('Tell us about your business','Parlez-nous de votre entreprise') ?></h2>
      <p class="section-sub"><?= t('We\'ll call or WhatsApp you within 24 hours — no obligation.','Nous vous appellerons ou contacterons sur WhatsApp dans les 24 heures — sans engagement.') ?></p>
    </div>

    <?php if ($sent): ?>
      <div class="flash flash-success" style="text-align:center;padding:1.5rem;">
        ✅ <?= t('Thank you! We\'ve received your request and will contact you within 24 hours.','Merci ! Nous avons reçu votre demande et vous contacterons dans les 24 heures.') ?>
      </div>
      <div style="text-align:center;margin-top:1.5rem;">
        <a href="https://wa.me/<?= $whatsappNumber ?>?text=<?= $whatsappMessage ?>" target="_blank" rel="noopener"
           class="btn" style="background:rgba(37,211,102,0.12);color:#25D366;border:1px solid rgba(37,211,102,0.3);display:inline-flex;align-items:center;gap:0.6rem;">
          <?= t('Or message us now on WhatsApp →','Ou contactez-nous maintenant sur WhatsApp →') ?>
        </a>
      </div>
    <?php else: ?>

      <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

      <div class="form-card">
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <div style="position:absolute;left:-9999px;opacity:0;height:0;" aria-hidden="true">
            <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label><?= t('Your Name *','Votre Nom *') ?></label>
              <input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>" placeholder="<?= t('Full name','Nom complet') ?>">
            </div>
            <div class="form-group">
              <label><?= t('Phone Number *','Numéro de Téléphone *') ?></label>
              <input type="tel" name="phone" required value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+237 6XX XXX XXX">
            </div>
          </div>

          <div class="form-group">
            <label><?= t('Email (optional)','Email (optionnel)') ?></label>
            <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@example.com">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label><?= t('Business Name','Nom de l\'Entreprise') ?></label>
              <input type="text" name="business_name" value="<?= e($_POST['business_name'] ?? '') ?>" placeholder="<?= t('Optional','Optionnel') ?>">
            </div>
            <div class="form-group">
              <label><?= t('Type of Business','Type d\'Entreprise') ?></label>
              <input type="text" name="business_type" value="<?= e($_POST['business_type'] ?? '') ?>" placeholder="<?= t('e.g. Restaurant, Hotel, Shop','ex. Restaurant, Hôtel, Boutique') ?>">
            </div>
          </div>

          <div class="form-group">
            <label><?= t('Do you currently have a website?','Avez-vous actuellement un site web ?') ?></label>
            <select name="has_website">
              <option value="none"><?= t('No website at all','Aucun site web') ?></option>
              <option value="social_only"><?= t('Only Facebook/Instagram','Seulement Facebook/Instagram') ?></option>
              <option value="outdated"><?= t('Yes, but it\'s outdated','Oui, mais il est obsolète') ?></option>
            </select>
          </div>

          <div class="form-group">
            <label><?= t('Tell us more (optional)','Dites-nous en plus (optionnel)') ?></label>
            <textarea name="notes" rows="3" placeholder="<?= t('What would you like your website to do for your business?','Que voulez-vous que votre site web fasse pour votre entreprise ?') ?>"><?= e($_POST['notes'] ?? '') ?></textarea>
          </div>

          <button type="submit" class="btn btn-primary btn-full" style="font-size:1rem;margin-top:0.5rem;">
            <?= t('Request My Website →','Demander mon Site →') ?>
          </button>
          <p style="font-size:0.75rem;color:var(--muted-2);margin-top:0.75rem;text-align:center;">
            <?= t('No payment required now. We\'ll discuss pricing on the call.','Aucun paiement requis maintenant. Nous discuterons du prix lors de l\'appel.') ?>
          </p>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- STICKY WHATSAPP (mobile-friendly) -->
<a href="https://wa.me/<?= $whatsappNumber ?>?text=<?= $whatsappMessage ?>" target="_blank" rel="noopener"
   style="position:fixed;bottom:1.5rem;right:1.5rem;width:56px;height:56px;border-radius:50%;background:#25D366;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(37,211,102,0.4);z-index:90;text-decoration:none;">
  <svg width="28" height="28" viewBox="0 0 24 24" fill="white"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
</a>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
