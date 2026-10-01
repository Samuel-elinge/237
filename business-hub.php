<?php
/**
 * business-hub.php — 237Biz Business Hub
 * SEO-optimised landing page for business owners
 * URL: /business-hub
 */
require_once __DIR__ . '/includes/config.php';

$isFr = lang() === 'fr';

$pageTitle = $isFr
    ? '237Biz Business Hub — Développez votre entreprise au Cameroun'
    : '237Biz Business Hub — Grow Your Business in Cameroon';
$pageDesc  = $isFr
    ? 'Listez votre entreprise gratuitement sur 237Biz. Obtenez des avis clients, acceptez des réservations, améliorez votre présence en ligne et accédez aux outils de croissance pour les entreprises camerounaises.'
    : 'List your business free on 237Biz. Get customer reviews, accept bookings, improve your online presence and access growth tools built for Cameroonian businesses.';

// Live stats
try {
    $totalListings = (int)db()->query("SELECT COUNT(*) FROM listings WHERE status='approved'")->fetchColumn();
    $totalReviews  = (int)db()->query("SELECT COUNT(*) FROM reviews WHERE status='approved'")->fetchColumn();
    $totalCities   = (int)db()->query("SELECT COUNT(*) FROM locations")->fetchColumn();
} catch(Exception $e) { $totalListings = $totalReviews = $totalCities = 0; }

require_once __DIR__ . '/includes/header.php';
?>

<!-- SEO structured data -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": "<?= $isFr ? '237Biz Business Hub' : '237Biz Business Hub' ?>",
  "description": "<?= e($pageDesc) ?>",
  "url": "<?= SITE_URL ?>/business-hub",
  "publisher": {
    "@type": "Organization",
    "name": "237Biz",
    "url": "<?= SITE_URL ?>"
  }
}
</script>

<style>
/* ── Hero ── */
.bh-hero {
  background:linear-gradient(150deg,#05280F,#0a1f12,#081C10);
  padding:90px 0 70px; text-align:center; position:relative; overflow:hidden;
}
.bh-hero::before {
  content:''; position:absolute; inset:0;
  background:radial-gradient(ellipse 70% 50% at 50% -10%,rgba(252,209,22,0.08),transparent);
  pointer-events:none;
}
.bh-badge {
  display:inline-block; background:rgba(252,209,22,0.1); color:#fcd116;
  border:1px solid rgba(252,209,22,0.25); border-radius:99px;
  padding:5px 16px; font-size:12.5px; font-weight:700; margin-bottom:22px;
}
.bh-hero h1 {
  font-family:'Fraunces',serif; font-weight:900;
  font-size:clamp(2rem,5vw,3.5rem); color:#fff;
  line-height:1.1; margin-bottom:16px; max-width:800px; margin-inline:auto;
}
.bh-hero p {
  font-size:clamp(15px,2vw,17px); color:rgba(255,255,255,0.65);
  max-width:580px; margin:0 auto 36px; line-height:1.75;
}
.bh-cta-row { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }
.btn-primary-lg {
  padding:14px 32px; background:#00A878; color:#fff; border-radius:10px;
  font-size:15px; font-weight:700; text-decoration:none; transition:all .2s;
  display:inline-block;
}
.btn-primary-lg:hover { background:#008f67; transform:translateY(-1px); }
.btn-outline-lg {
  padding:13px 28px; border:2px solid rgba(255,255,255,0.2); color:rgba(255,255,255,0.8);
  border-radius:10px; font-size:15px; font-weight:600; text-decoration:none;
  transition:all .2s; display:inline-block;
}
.btn-outline-lg:hover { border-color:rgba(255,255,255,0.5); color:#fff; }

/* Stats row */
.bh-stats {
  display:flex; justify-content:center; gap:48px; flex-wrap:wrap;
  padding:28px 0; background:rgba(255,255,255,0.02);
  border-bottom:1px solid rgba(255,255,255,0.06);
}
.bh-stat .num { font-family:'Fraunces',serif; font-weight:900; font-size:2rem; color:#fcd116; }
.bh-stat .lbl { font-size:12px; color:rgba(255,255,255,0.5); margin-top:2px; }

/* Sections */
.bh-section { padding:80px 0; }
.bh-section+.bh-section { border-top:1px solid rgba(255,255,255,0.05); }
.section-eyebrow {
  display:inline-block; font-size:12px; font-weight:700;
  text-transform:uppercase; letter-spacing:.08em; color:#00A878;
  background:rgba(0,168,120,0.1); border:1px solid rgba(0,168,120,0.2);
  border-radius:99px; padding:4px 14px; margin-bottom:14px;
}
.bh-title {
  font-family:'Fraunces',serif; font-weight:900;
  font-size:clamp(1.6rem,3.5vw,2.4rem); color:#fff; margin-bottom:14px;
}
.bh-sub { font-size:15px; color:rgba(255,255,255,0.6); line-height:1.75; max-width:600px; }

/* Feature grid */
.feature-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:18px; margin-top:36px; }
.feature-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:16px; padding:28px 26px; transition:all .2s;
}
.feature-card:hover { background:rgba(0,168,120,0.05); border-color:rgba(0,168,120,0.25); transform:translateY(-2px); }
.feature-card .fc-icon { font-size:2rem; margin-bottom:14px; }
.feature-card h3 { font-size:16px; font-weight:700; color:#fff; margin-bottom:8px; }
.feature-card p  { font-size:13.5px; color:rgba(255,255,255,0.6); line-height:1.65; margin:0; }

/* Steps */
.steps { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; margin-top:36px; counter-reset:steps; }
.step-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:14px; padding:24px 22px; position:relative; counter-increment:steps;
}
.step-card::before {
  content:counter(steps); position:absolute; top:20px; right:20px;
  width:28px; height:28px; border-radius:50%;
  background:rgba(0,168,120,0.15); color:#00A878;
  font-size:13px; font-weight:900; display:flex; align-items:center; justify-content:center;
  font-family:'Fraunces',serif;
}
.step-card .si { font-size:1.6rem; margin-bottom:10px; }
.step-card h3  { font-size:14.5px; font-weight:700; color:#fff; margin-bottom:6px; }
.step-card p   { font-size:13px; color:rgba(255,255,255,0.55); line-height:1.6; margin:0; }

/* Plan cards */
.plan-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:18px; margin-top:36px; }
.plan-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.1);
  border-radius:18px; padding:30px 28px; display:flex; flex-direction:column; gap:8px;
}
.plan-card.featured-plan {
  background:linear-gradient(135deg,rgba(252,209,22,0.08),rgba(0,168,120,0.05));
  border-color:rgba(252,209,22,0.3); position:relative;
}
.plan-badge {
  position:absolute; top:-11px; left:50%; transform:translateX(-50%);
  background:#fcd116; color:#0A1A0F; font-size:11px; font-weight:800;
  padding:3px 14px; border-radius:99px; white-space:nowrap;
}
.plan-name  { font-size:18px; font-weight:800; color:#fff; }
.plan-price { font-family:'Fraunces',serif; font-weight:900; font-size:2.2rem; color:#fcd116; line-height:1; }
.plan-price small { font-size:14px; color:rgba(255,255,255,0.5); font-family:'DM Sans',sans-serif; font-weight:400; }
.plan-features { list-style:none; padding:0; margin:12px 0; display:flex; flex-direction:column; gap:8px; }
.plan-features li { font-size:13.5px; color:rgba(255,255,255,0.75); display:flex; align-items:flex-start; gap:8px; }
.plan-features li span { color:#00A878; flex-shrink:0; }

/* FAQ */
.faq-item  { border-bottom:1px solid rgba(255,255,255,0.07); }
.faq-q {
  padding:18px 0; font-size:14.5px; font-weight:600; color:rgba(255,255,255,0.85);
  cursor:pointer; display:flex; justify-content:space-between; align-items:center; gap:12px;
  transition:color .15s;
}
.faq-q:hover { color:#fff; }
.faq-a { padding:0 0 18px; font-size:13.5px; color:rgba(255,255,255,0.6); line-height:1.75; display:none; }
.faq-item.open .faq-a { display:block; }
.faq-item.open .faq-q { color:#fff; }

/* Split layout */
.split { display:grid; grid-template-columns:1fr 1fr; gap:60px; align-items:center; }
@media(max-width:740px){
  .split { grid-template-columns:1fr !important; gap:32px; }
  .bh-stats { gap:24px; }
}
</style>

<!-- Hero -->
<div class="bh-hero">
  <div class="container">
    <div class="bh-badge">💼 237Biz Business Hub</div>
    <h1><?= $isFr
      ? 'Tout ce dont votre entreprise camerounaise a besoin pour grandir en ligne'
      : 'Everything Your Cameroonian Business Needs to Grow Online' ?>
    </h1>
    <p><?= $isFr
      ? 'Listez votre entreprise gratuitement, collectez des avis, acceptez des réservations et accédez à des outils de croissance — tout en un seul endroit, à des prix XAF.'
      : 'List your business free, collect reviews, accept bookings and access growth tools — all in one place, at XAF prices.' ?>
    </p>
    <div class="bh-cta-row">
      <a href="<?= SITE_URL ?>/add-listing"   class="btn-primary-lg">+ <?= t('List Your Business Free','Lister votre Entreprise Gratuitement') ?></a>
      <a href="<?= SITE_URL ?>/claim-listing" class="btn-outline-lg">🏴 <?= t('Claim Your Business','Revendiquer votre Entreprise') ?></a>
    </div>
  </div>
</div>

<!-- Stats -->
<div class="bh-stats container">
  <?php foreach ([
    [$totalListings . '+', t('Businesses Listed','Entreprises Listées')],
    [$totalReviews  . '+', t('Customer Reviews','Avis Clients')],
    [$totalCities,          t('Cities Covered','Villes Couvertes')],
    ['24h',                 t('Listing approved in','Annonce approuvée en')],
    ['🆓',                  t('Free forever','Gratuit à vie')],
  ] as [$num,$lbl]): ?>
  <div class="bh-stat" style="text-align:center;">
    <div class="num"><?= $num ?></div>
    <div class="lbl"><?= $lbl ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Why 237Biz -->
<section class="bh-section" id="why">
  <div class="container">
    <div class="split">
      <div>
        <span class="section-eyebrow">💡 <?= t('WHY 237BIZ','POURQUOI 237BIZ') ?></span>
        <h2 class="bh-title"><?= t('Built for Cameroon. Priced in XAF.','Conçu pour le Cameroun. Prix en XAF.') ?></h2>
        <p class="bh-sub">
          <?= t('237Biz is the only business directory and growth platform built specifically for Cameroon. We understand the local market, support both English and French, and price everything in CFA Francs (XAF) — no dollar conversions, no surprises.',
                '237Biz est la seule plateforme de répertoire et de croissance construite spécifiquement pour le Cameroun. Nous comprenons le marché local, supportons le français et l\'anglais, et tout est tarifé en FCFA — sans conversion en dollars.') ?>
        </p>
        <div style="margin-top:24px;display:flex;flex-direction:column;gap:10px;">
          <?php foreach ([
            t('100% free to list — no trial, no expiry','100% gratuit pour lister — aucun essai, aucune expiration'),
            t('Bilingual platform — English & French','Plateforme bilingue — Anglais & Français'),
            t('All payments via MTN MoMo & Orange Money','Tous les paiements via MTN MoMo & Orange Money'),
            t('Listed businesses visible on Google','Entreprises listées visibles sur Google'),
            t('WhatsApp-first communication tools','Outils de communication WhatsApp en priorité'),
            t('Support team based in Cameroon','Équipe de support basée au Cameroun'),
          ] as $point): ?>
          <div style="display:flex;align-items:center;gap:10px;font-size:14px;color:rgba(255,255,255,0.8);">
            <span style="color:#00A878;font-size:1rem;">✓</span><?= $point ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <div style="background:rgba(0,168,120,0.05);border:1px solid rgba(0,168,120,0.15);border-radius:20px;padding:32px;">
          <h3 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.2rem;margin-bottom:20px;color:#fff;">
            <?= t('What other directories don\'t offer:','Ce que les autres répertoires n\'offrent pas :') ?>
          </h3>
          <?php foreach ([
            ['🌍', t('Made for Cameroon','Fait pour le Cameroun'),     t('Not a global template with Cameroon data pasted in.','Pas un modèle global avec des données camerounaises collées.')],
            ['💬', t('WhatsApp First','WhatsApp en premier'),           t('Direct WhatsApp button on every listing.','Bouton WhatsApp direct sur chaque annonce.')],
            ['📅', t('Online Booking','Réservation en ligne'),          t('Real appointment booking built in — not an afterthought.','Réservation réelle intégrée — pas une réflexion après coup.')],
            ['📊', t('Business Dashboard','Tableau de bord Entreprise'), t('Analytics, announcements, reviews and more in one place.','Analytiques, annonces, avis et plus en un seul endroit.')],
            ['🤝', t('Partner Network','Réseau de Partenaires'),        t('Sales Agents and Creators actively promote your business.','Des Agents et Créateurs font activement la promotion de votre entreprise.')],
          ] as [$icon,$title,$desc]): ?>
          <div style="display:flex;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.06);">
            <span style="font-size:1.2rem;flex-shrink:0;"><?= $icon ?></span>
            <div>
              <div style="font-size:13.5px;font-weight:700;color:#fff;margin-bottom:2px;"><?= $title ?></div>
              <div style="font-size:12.5px;color:rgba(255,255,255,0.5);"><?= $desc ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Features -->
<section class="bh-section" id="features" style="background:rgba(255,255,255,0.01);">
  <div class="container">
    <div style="text-align:center;margin-bottom:48px;">
      <span class="section-eyebrow">⚡ <?= t('FEATURES','FONCTIONNALITÉS') ?></span>
      <h2 class="bh-title"><?= t('Everything in one Business Hub','Tout dans un seul Business Hub') ?></h2>
      <p class="bh-sub" style="margin:0 auto;"><?= t('One listing. Multiple tools. All working together to bring customers to your door.','Une annonce. Plusieurs outils. Tous travaillant ensemble pour amener des clients à votre porte.') ?></p>
    </div>

    <div class="feature-grid">
      <?php
      $features = [
        ['👤', t('Business Profile','Profil d\'Entreprise'),
               t('Full listing with logo, description, gallery images, opening hours, address, phone, WhatsApp, website, Facebook and more. Looks great on mobile and desktop.','Annonce complète avec logo, description, galerie, horaires, adresse, téléphone, WhatsApp, site web, Facebook et plus. Beau sur mobile et ordinateur.')],
        ['⭐', t('Reviews & Reputation','Avis & Réputation'),
               t('Customers leave star ratings and written reviews directly on your listing. Build social proof and stand out from competitors who have no reviews.','Les clients laissent des évaluations et des avis directement sur votre annonce. Construisez une preuve sociale et démarquez-vous.')],
        ['📅', t('Appointment Booking','Prise de Rendez-vous'),
               t('Let customers book appointments directly from your 237Biz listing. You approve, decline or reschedule from your dashboard. Confirmation emails sent automatically.','Laissez les clients réserver des rendez-vous directement. Vous approuvez, refusez ou reprogrammez depuis votre tableau de bord.')],
        ['💬', t('WhatsApp Chat Widget','Widget WhatsApp'),
               t('Every listing has a WhatsApp button. When a customer clicks it, a pre-written message is sent to your WhatsApp number — instant, direct enquiry.','Chaque annonce a un bouton WhatsApp. Quand un client clique dessus, un message pré-écrit est envoyé à votre WhatsApp.')],
        ['📊', t('Analytics Dashboard','Tableau Analytique'),
               t('See how many people viewed your listing, clicked your WhatsApp, called your phone number, visited your website or booked an appointment — all tracked.','Voyez combien de personnes ont vu votre annonce, cliqué sur votre WhatsApp, appelé ou réservé — tout est suivi.')],
        ['📢', t('Announcements & Promotions','Annonces & Promotions'),
               t('Post special offers, new arrivals, events or important notices directly to your listing page. Customers who visit see your latest news immediately.','Publiez des offres spéciales, nouveautés, événements ou avis importants directement sur votre page.')],
        ['📱', t('QR Code','Code QR'),
               t('Every listing gets a downloadable QR code. Print it on business cards, receipts, packaging or posters. Customers scan it to reach your full profile instantly.','Chaque annonce obtient un code QR téléchargeable. Imprimez-le sur des cartes de visite, reçus ou emballages.')],
        ['❓', t('FAQ Builder','Constructeur de FAQ'),
               t('Add up to 4 frequently asked questions directly on your listing. Answer common customer questions before they even need to contact you.','Ajoutez jusqu\'à 4 questions fréquentes directement sur votre annonce. Répondez aux questions courantes avant même qu\'ils vous contactent.')],
        ['🌐', t('Online Presence','Présence en Ligne'),
               t('Upgrade to get a professional website, business email, SEO setup and Google Business Profile optimisation — all handled by the 237Biz team.','Passez à la version supérieure pour obtenir un site web professionnel, un email professionnel, la configuration SEO et l\'optimisation du profil Google.')],
      ];
      foreach ($features as [$icon,$title,$desc]): ?>
      <div class="feature-card">
        <div class="fc-icon"><?= $icon ?></div>
        <h3><?= $title ?></h3>
        <p><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- How to get listed -->
<section class="bh-section" id="get-started">
  <div class="container">
    <div style="text-align:center;margin-bottom:48px;">
      <span class="section-eyebrow">🚀 <?= t('GET STARTED','COMMENCER') ?></span>
      <h2 class="bh-title"><?= t('Listed in 3 simple steps','Listé en 3 étapes simples') ?></h2>
      <p class="bh-sub" style="margin:0 auto;"><?= t('Getting your business on 237Biz takes less than 5 minutes and costs nothing.','Mettre votre entreprise sur 237Biz prend moins de 5 minutes et ne coûte rien.') ?></p>
    </div>

    <div class="steps" style="grid-template-columns:repeat(3,1fr);">
      <div class="step-card">
        <div class="si">✍️</div>
        <h3><?= t('Create Your Listing','Créez votre annonce') ?></h3>
        <p><?= t('Fill in your business name, category, city, description, contact details and logo. Takes about 5 minutes. No account needed to start.','Remplissez le nom de votre entreprise, catégorie, ville, description, coordonnées et logo. Environ 5 minutes.') ?></p>
      </div>
      <div class="step-card">
        <div class="si">✅</div>
        <h3><?= t('We Review & Publish','Nous examinons & publions') ?></h3>
        <p><?= t('Our team reviews every listing to ensure quality. Most listings are approved and published within 24 hours. You\'ll be notified by email.','Notre équipe examine chaque annonce pour garantir la qualité. La plupart sont approuvées dans les 24 heures.') ?></p>
      </div>
      <div class="step-card">
        <div class="si">📈</div>
        <h3><?= t('Manage & Grow','Gérez & Grandissez') ?></h3>
        <p><?= t('Log in to your dashboard to edit your listing, view analytics, respond to bookings, post announcements and upgrade to a featured listing anytime.','Connectez-vous à votre tableau de bord pour modifier votre annonce, voir les analytiques, gérer les réservations et publier des annonces.') ?></p>
      </div>
    </div>

    <div style="text-align:center;margin-top:36px;">
      <a href="<?= SITE_URL ?>/add-listing" class="btn-primary-lg">+ <?= t('Start — It\'s Free','Commencer — C\'est gratuit') ?></a>
    </div>
  </div>
</section>

<!-- Pricing -->
<section class="bh-section" id="pricing" style="background:rgba(255,255,255,0.01);">
  <div class="container">
    <div style="text-align:center;margin-bottom:48px;">
      <span class="section-eyebrow">💳 <?= t('PRICING','TARIFS') ?></span>
      <h2 class="bh-title"><?= t('Simple pricing. No surprises.','Tarifs simples. Sans surprises.') ?></h2>
      <p class="bh-sub" style="margin:0 auto;"><?= t('Start free forever. Upgrade when you\'re ready to grow faster.','Commencez gratuitement pour toujours. Passez à la version supérieure quand vous êtes prêt à grandir plus vite.') ?></p>
    </div>

    <div class="plan-grid">
      <!-- Free -->
      <div class="plan-card">
        <div class="plan-name">🆓 <?= t('Free Listing','Annonce Gratuite') ?></div>
        <div class="plan-price">0 XAF <small><?= t('/ forever','/ pour toujours') ?></small></div>
        <ul class="plan-features">
          <?php foreach ([
            t('Business name, category & city','Nom, catégorie & ville'),
            t('Description & contact details','Description & coordonnées'),
            t('WhatsApp & phone button','Bouton WhatsApp & téléphone'),
            t('Customer reviews','Avis clients'),
            t('Appear in search results','Apparaître dans les résultats'),
            t('Analytics dashboard','Tableau de bord analytique'),
          ] as $f): ?>
          <li><span>✓</span> <?= $f ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="<?= SITE_URL ?>/add-listing" class="btn-outline-lg" style="text-align:center;margin-top:auto;">
          <?= t('Get Listed Free','Être listé gratuitement') ?>
        </a>
      </div>

      <!-- Featured -->
      <div class="plan-card featured-plan">
        <div class="plan-badge">⭐ <?= t('Most Popular','Plus Populaire') ?></div>
        <div class="plan-name">⭐ <?= t('Featured Listing','Annonce Vedette') ?></div>
        <div class="plan-price"><?= t('From','À partir de') ?> <small style="color:#fcd116;font-family:'Fraunces',serif;font-size:1.4rem;font-weight:900;">5,000</small> <small style="color:rgba(255,255,255,0.5);font-family:'DM Sans',sans-serif;font-weight:400;font-size:14px;">XAF</small></div>
        <ul class="plan-features">
          <?php foreach ([
            t('Everything in Free','Tout dans Gratuit'),
            t('Featured badge & top placement','Badge vedette & placement prioritaire'),
            t('Verified tick on your listing','Coche vérifiée sur votre annonce'),
            t('Logo & gallery images (up to 6)','Logo & galerie d\'images (jusqu\'à 6)'),
            t('Appointment booking system','Système de prise de rendez-vous'),
            t('Announcements & promotions','Annonces & promotions'),
            t('FAQ builder (up to 4 questions)','Constructeur FAQ (jusqu\'à 4 questions)'),
            t('QR code download','Téléchargement code QR'),
            t('Instagram & TikTok links','Liens Instagram & TikTok'),
            t('Priority in search results','Priorité dans les résultats'),
          ] as $f): ?>
          <li><span>✓</span> <?= $f ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="<?= SITE_URL ?>/add-listing" class="btn-primary-lg" style="text-align:center;margin-top:auto;">
          <?= t('Get Featured','Devenir Vedette') ?>
        </a>
      </div>

      <!-- Online Presence -->
      <div class="plan-card">
        <div class="plan-name">🌐 <?= t('Online Presence','Présence en Ligne') ?></div>
        <div class="plan-price"><?= t('Custom','Sur mesure') ?> <small><?= t('/ XAF pricing','/ prix en XAF') ?></small></div>
        <ul class="plan-features">
          <?php foreach ([
            t('Everything in Featured','Tout dans Vedette'),
            t('Professional website','Site web professionnel'),
            t('Business email address','Adresse email professionnelle'),
            t('Google Business Profile setup','Configuration Profil Google Business'),
            t('SEO optimisation','Optimisation SEO'),
            t('WhatsApp Business setup','Configuration WhatsApp Business'),
            t('Social media setup','Configuration réseaux sociaux'),
            t('Dedicated support manager','Responsable support dédié'),
          ] as $f): ?>
          <li><span>✓</span> <?= $f ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="<?= SITE_URL ?>/online-presence" class="btn-outline-lg" style="text-align:center;margin-top:auto;">
          <?= t('Learn More','En savoir plus') ?>
        </a>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="bh-section" id="faq">
  <div class="container" style="max-width:720px;">
    <div style="text-align:center;margin-bottom:44px;">
      <span class="section-eyebrow">❓ <?= t('FAQ','FAQ') ?></span>
      <h2 class="bh-title"><?= t('Common Questions','Questions fréquentes') ?></h2>
    </div>
    <?php
    $faqs = [
      [
        t('Is listing my business really free?','Lister mon entreprise est vraiment gratuit ?'),
        t('Yes — 100% free, forever. No trial period, no credit card, no hidden fees. Your free listing never expires. We make money when businesses choose to upgrade to Featured listings or purchase additional services.','Oui — 100% gratuit, pour toujours. Aucune période d\'essai, aucune carte bancaire, aucuns frais cachés. Votre annonce gratuite n\'expire jamais.'),
      ],
      [
        t('How long does it take to get listed?','Combien de temps faut-il pour être listé ?'),
        t('Most listings are reviewed and approved within 24 hours of submission. We review each listing manually to ensure quality. You\'ll receive an email notification as soon as your listing is live.','La plupart des annonces sont examinées et approuvées dans les 24 heures. Nous examinons chaque annonce manuellement pour garantir la qualité.'),
      ],
      [
        t('Can I edit my listing after it\'s published?','Puis-je modifier mon annonce après sa publication ?'),
        t('Yes. Log into your dashboard at any time to update your business information, add photos, change opening hours, post announcements or respond to reviews.','Oui. Connectez-vous à votre tableau de bord à tout moment pour mettre à jour vos informations, ajouter des photos, changer les horaires ou publier des annonces.'),
      ],
      [
        t('What\'s the difference between Free and Featured?','Quelle est la différence entre Gratuit et Vedette ?'),
        t('Free listings are included in search results and have core contact features. Featured listings get top placement in search results, a verified badge, gallery images, appointment booking, FAQ section, QR code, announcements and more. Featured listings are significantly more visible.','Les annonces gratuites sont incluses dans les résultats de recherche. Les annonces vedettes obtiennent le placement prioritaire, un badge vérifié, une galerie d\'images, la prise de rendez-vous et plus.'),
      ],
      [
        t('How do I pay for a Featured listing?','Comment payer pour une annonce vedette ?'),
        t('Payment is done offline via MTN MoMo or Orange Money. You send the payment, take a screenshot, and upload it when submitting your listing. Our team verifies the payment and activates your featured listing within 24 hours.','Le paiement se fait hors ligne via MTN MoMo ou Orange Money. Vous envoyez le paiement, prenez une capture d\'écran et la téléchargez lors de la soumission.'),
      ],
      [
        t('My business is already listed — how do I claim it?','Mon entreprise est déjà listée — comment la revendiquer ?'),
        t('If someone else listed your business (or we added it from public data), you can claim ownership. Click "Claim Your Business" and follow the verification steps. Once verified, you take full control of the listing.','Si quelqu\'un d\'autre a listé votre entreprise, vous pouvez revendiquer la propriété. Cliquez sur "Revendiquer votre Entreprise" et suivez les étapes de vérification.'),
      ],
    ];
    foreach ($faqs as [$q, $a]): ?>
    <div class="faq-item" onclick="this.classList.toggle('open')">
      <div class="faq-q"><?= $q ?> <span style="flex-shrink:0;color:rgba(255,255,255,0.4);font-size:1.2rem;transition:transform .2s;" class="faq-caret">+</span></div>
      <div class="faq-a"><?= $a ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- Final CTA -->
<section style="background:linear-gradient(135deg,#08472F,#081C10);padding:80px 0;text-align:center;">
  <div class="container">
    <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.4rem);margin-bottom:12px;">
      <?= t('Ready to grow your Cameroon business?','Prêt à développer votre entreprise camerounaise ?') ?>
    </h2>
    <p style="color:rgba(255,255,255,0.6);font-size:15px;margin-bottom:32px;max-width:480px;margin-inline:auto;line-height:1.7;">
      <?= t('Join businesses already connecting with customers through 237Biz. Free to start, no credit card required.','Rejoignez des entreprises qui connectent déjà avec des clients via 237Biz. Gratuit pour commencer.') ?>
    </p>
    <div class="bh-cta-row">
      <a href="<?= SITE_URL ?>/add-listing"   class="btn-primary-lg">+ <?= t('List Your Business Free','Lister votre Entreprise Gratuitement') ?></a>
      <a href="<?= SITE_URL ?>/claim-listing" class="btn-outline-lg">🏴 <?= t('Claim Your Business','Revendiquer votre Entreprise') ?></a>
    </div>
  </div>
</section>

<script>
document.querySelectorAll('.faq-item').forEach(function(el) {
  el.addEventListener('click', function() {
    var caret = this.querySelector('.faq-caret');
    if (caret) caret.textContent = this.classList.contains('open') ? '−' : '+';
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
