<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partner-lang.php';

$pageTitle = t('Get Your Business Seen — Register', 'Faites Voir Votre Entreprise — Inscription');
$pageDesc  = t(
    'Register for the exclusive Get Your Business Seen event on 29 October 2026 and claim your free 237Biz starter pack.',
    'Inscrivez-vous à l\'événement exclusif du 29 octobre 2026 et obtenez votre pack de démarrage 237Biz gratuit.'
);

$extraHead = <<<'HTML'
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&display=swap" rel="stylesheet">
<style>
/* ── Event page tokens ── */
:root {
  --ev-dark:   #0d2b1a;
  --ev-mid:    #1a3d26;
  --ev-gold:   #f5b800;
  --ev-gold-dk:#d9a200;
  --ev-off:    #f7f4ee;
  --ev-card:   #ffffff;
  --ev-muted:  #5a6e5f;
  --ev-border: #dde8e0;
  --ev-input:  #f7f4ee;
  --ev-radius: 10px;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --ev-off:   #0f1a13;
    --ev-card:  #162a1e;
    --ev-muted: #8aab92;
    --ev-border:#2a4030;
    --ev-input: #0f1f15;
  }
}
:root[data-theme="dark"] {
  --ev-off:   #0f1a13;
  --ev-card:  #162a1e;
  --ev-muted: #8aab92;
  --ev-border:#2a4030;
  --ev-input: #0f1f15;
}

/* ── Page wrapper (push below fixed navbar) ── */
.ev-page { background: var(--ev-off); padding-top: 64px; min-height: 100vh; }

/* ── Organiser bar ── */
.ev-bar {
  background: var(--ev-dark);
  color: rgba(255,255,255,.7);
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
  padding: 9px 0;
}
.ev-bar-inner {
  max-width: 960px; margin: 0 auto;
  padding: 0 max(16px, env(safe-area-inset-left,0px));
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
}
.ev-bar-orgs span { color: var(--ev-gold); margin: 0 7px; }
.ev-bar-tag {
  background: var(--ev-gold); color: var(--ev-dark);
  font-size: 10px; font-weight: 800; letter-spacing: .08em;
  padding: 2px 9px; border-radius: 4px;
}

/* ── Hero ── */
.ev-hero { background: var(--ev-dark); color: #fff; padding: 44px 0 40px; }
.ev-hero-inner {
  max-width: 960px; margin: 0 auto;
  padding: 0 max(16px, env(safe-area-inset-left,0px));
}
.ev-hero h1 {
  font-family: 'Syne', sans-serif;
  font-size: clamp(2.2rem,5.5vw,3.6rem);
  font-weight: 800; line-height: 1.06; text-wrap: balance;
}
.ev-hero h1 em { color: var(--ev-gold); font-style: normal; }
.ev-hero-sub {
  margin-top: 12px; font-size: 1rem; opacity: .8; max-width: 520px; line-height: 1.6;
}
.ev-chips { margin-top: 20px; display: flex; flex-wrap: wrap; gap: 10px; }
.ev-chip {
  display: flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.15);
  border-radius: 99px; padding: 5px 14px; font-size: 13px; font-weight: 500;
}

/* ── Two-column body ── */
.ev-body {
  max-width: 960px; margin: 0 auto;
  padding: 36px max(16px, env(safe-area-inset-left,0px));
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 28px;
  align-items: start;
}
@media (max-width: 720px) { .ev-body { grid-template-columns: 1fr; } }

/* ── Form card ── */
.ev-form-card {
  background: var(--ev-card);
  border: 1px solid var(--ev-border);
  border-radius: 16px;
  padding: 32px;
}
.ev-form-card h2 {
  font-family: 'Syne', sans-serif;
  font-size: 1.4rem; font-weight: 800; margin-bottom: 4px;
}
.ev-lead { font-size: 13.5px; color: var(--ev-muted); margin-bottom: 24px; }

.ev-price {
  background: var(--ev-mid); color: #fff;
  border-radius: var(--ev-radius); padding: 14px 16px;
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; margin-bottom: 22px;
}
.ev-price-label { font-size: 11px; opacity: .7; text-transform: uppercase; letter-spacing: .06em; }
.ev-price-amt   { font-family: 'Syne',sans-serif; font-size: 1.6rem; font-weight: 800; color: var(--ev-gold); }
.ev-price-per   { font-size: 12px; opacity: .7; }

.ev-row { display: flex; flex-direction: column; gap: 5px; margin-bottom: 18px; }
.ev-row label { font-size: 13px; font-weight: 600; }
.ev-row input,
.ev-row select,
.ev-row textarea {
  background: var(--ev-input); border: 1.5px solid var(--ev-border);
  border-radius: var(--ev-radius); padding: 10px 13px;
  font-size: 14.5px; color: inherit; width: 100%;
  outline: none; transition: border-color .15s;
  font-family: inherit;
}
.ev-row input:focus,
.ev-row select:focus,
.ev-row textarea:focus { border-color: var(--ev-gold-dk); }
.ev-row textarea { resize: vertical; min-height: 80px; }
.ev-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 480px) { .ev-pair { grid-template-columns: 1fr; } }

.ev-check-row { flex-direction: row; align-items: center; gap: 9px; margin-bottom: 18px; }
.ev-check-row input  { width: auto; accent-color: var(--ev-gold-dk); }
.ev-check-row label  { font-size: 13px; font-weight: 400; cursor: pointer; }

.ev-btn {
  width: 100%; background: var(--ev-gold); color: var(--ev-dark);
  font-family: 'Syne', sans-serif; font-size: 1rem; font-weight: 800;
  border: none; border-radius: var(--ev-radius); padding: 14px 20px;
  cursor: pointer; letter-spacing: .03em;
  transition: background .15s, transform .1s;
}
.ev-btn:hover  { background: var(--ev-gold-dk); }
.ev-btn:active { transform: scale(.98); }

.ev-wa-note { margin-top: 14px; text-align: center; font-size: 12.5px; color: var(--ev-muted); }
.ev-wa-note strong { color: inherit; opacity: .9; }

/* ── Success ── */
.ev-success { text-align: center; padding: 40px 20px; }
.ev-success-icon { font-size: 3rem; margin-bottom: 12px; }
.ev-success h3 {
  font-family: 'Syne', sans-serif;
  font-size: 1.4rem; font-weight: 800; margin-bottom: 8px;
}
.ev-success p   { color: var(--ev-muted); font-size: 14px; }
.ev-success-wa  {
  margin-top: 18px; background: var(--ev-input);
  border-radius: var(--ev-radius); padding: 14px 16px;
  font-size: 15px; font-weight: 600;
}

/* ── Benefits sidebar ── */
.ev-aside { display: flex; flex-direction: column; gap: 16px; }

.ev-aside-hdr {
  background: var(--ev-dark); color: #fff;
  border-radius: 14px; padding: 22px 20px;
}
.ev-aside-eyebrow {
  font-size: 11px; font-weight: 700; letter-spacing: .12em;
  text-transform: uppercase; color: var(--ev-gold); margin-bottom: 6px;
}
.ev-aside-hdr h3 {
  font-family: 'Syne', sans-serif;
  font-size: 1.15rem; font-weight: 800; line-height: 1.25; text-wrap: balance;
}
.ev-aside-hdr p { margin-top: 8px; font-size: 13px; opacity: .75; }

.ev-benefit {
  background: var(--ev-card); border: 1px solid var(--ev-border);
  border-radius: 14px; padding: 18px 18px 18px 16px;
  display: flex; gap: 14px; align-items: flex-start;
}
.ev-bicon {
  width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: 1.25rem;
}
.ev-bicon.g { background: #d6f0e2; }
.ev-bicon.a { background: #fef3cc; }
.ev-bicon.b { background: #ddeeff; }
.ev-bicon.p { background: #ede8fb; }
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) .ev-bicon.g { background: #1a3d2a; }
  :root:not([data-theme="light"]) .ev-bicon.a { background: #3a2e00; }
  :root:not([data-theme="light"]) .ev-bicon.b { background: #102240; }
  :root:not([data-theme="light"]) .ev-bicon.p { background: #28193e; }
}
:root[data-theme="dark"] .ev-bicon.g { background: #1a3d2a; }
:root[data-theme="dark"] .ev-bicon.a { background: #3a2e00; }
:root[data-theme="dark"] .ev-bicon.b { background: #102240; }
:root[data-theme="dark"] .ev-bicon.p { background: #28193e; }

.ev-btitle { font-weight: 700; font-size: 14px; margin-bottom: 3px; }
.ev-bdesc  { font-size: 13px; color: var(--ev-muted); line-height: 1.5; }
.ev-btag {
  display: inline-block; margin-top: 6px;
  background: var(--ev-gold); color: var(--ev-dark);
  font-size: 10.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  padding: 2px 8px; border-radius: 99px;
}

.ev-hr { border: none; border-top: 1px solid var(--ev-border); margin-block: 4px; }
.ev-hint { font-size: 12.5px; color: var(--ev-muted); padding: 0 4px; line-height: 1.6; }

/* ── Footer strip ── */
.ev-footer {
  background: var(--ev-dark); color: rgba(255,255,255,.5);
  text-align: center; font-size: 12px;
  padding: 18px max(16px, env(safe-area-inset-left,0px));
  margin-top: 20px;
}
.ev-footer a { color: var(--ev-gold); text-decoration: none; }
</style>
HTML;

require_once __DIR__ . '/includes/header.php';
?>

<div class="ev-page">

  <!-- Organiser bar -->
  <div class="ev-bar">
    <div class="ev-bar-inner">
      <div class="ev-bar-orgs">
        The Tahirih Effect <span>×</span> Sabi Pot <span>×</span> 237Biz.net
      </div>
      <div class="ev-bar-tag"><?= t('Exclusive · Business Owners','Exclusif · Propriétaires d\'Entreprise') ?></div>
    </div>
  </div>

  <!-- Hero -->
  <div class="ev-hero">
    <div class="ev-hero-inner">
      <h1><?= t('Get your business <em>seen.</em>','Faites voir votre <em>entreprise.</em>') ?></h1>
      <p class="ev-hero-sub">
        <?= t(
          'An exclusive 2-hour gathering bringing business owners together to grow visibility, win customers and build valuable connections.',
          'Un rassemblement exclusif de 2 heures réunissant les entrepreneurs pour développer leur visibilité, attirer des clients et tisser des liens précieux.'
        ) ?>
      </p>
      <div class="ev-chips">
        <div class="ev-chip">📅 <?= t('Thursday 29 October 2026','Jeudi 29 octobre 2026') ?></div>
        <div class="ev-chip">🕓 4pm – 6pm</div>
        <div class="ev-chip">📍 Lumpsom, Adjacent Kontri Man</div>
      </div>
    </div>
  </div>

  <!-- Two-column body -->
  <div class="ev-body">

    <!-- Registration form -->
    <div class="ev-form-card" id="ev-form-wrap">
      <h2><?= t('Register your business','Inscrivez votre entreprise') ?></h2>
      <p class="ev-lead">
        <?= t(
          'Secure your spot — only 5,000 FCFA per business. Fill in the details below and we\'ll confirm via WhatsApp.',
          'Réservez votre place — seulement 5 000 FCFA par entreprise. Remplissez le formulaire ci-dessous et nous confirmerons via WhatsApp.'
        ) ?>
      </p>

      <div class="ev-price">
        <div>
          <div class="ev-price-label"><?= t('Participation fee · per business','Frais de participation · par entreprise') ?></div>
          <div class="ev-price-amt">5 000 <span style="font-size:1rem;font-weight:600">FCFA</span></div>
        </div>
        <div class="ev-price-per"><?= t('Paid on arrival','Payé à l\'arrivée') ?></div>
      </div>

      <form id="ev-form" novalidate>
        <div class="ev-pair">
          <div class="ev-row">
            <label for="ev-fname"><?= t('First name *','Prénom *') ?></label>
            <input type="text" id="ev-fname" name="fname" placeholder="<?= t('e.g. Marie','ex. Marie') ?>" required>
          </div>
          <div class="ev-row">
            <label for="ev-lname"><?= t('Last name *','Nom *') ?></label>
            <input type="text" id="ev-lname" name="lname" placeholder="<?= t('e.g. Njoya','ex. Njoya') ?>" required>
          </div>
        </div>

        <div class="ev-row">
          <label for="ev-phone"><?= t('WhatsApp number *','Numéro WhatsApp *') ?></label>
          <input type="tel" id="ev-phone" name="phone" placeholder="<?= t('e.g. 670 673 339','ex. 670 673 339') ?>" required>
        </div>

        <div class="ev-row">
          <label for="ev-biz"><?= t('Business name *','Nom de l\'entreprise *') ?></label>
          <input type="text" id="ev-biz" name="bizname" placeholder="<?= t('Your business name','Votre nom d\'entreprise') ?>" required>
        </div>

        <div class="ev-pair">
          <div class="ev-row">
            <label for="ev-sector"><?= t('Sector / industry *','Secteur / industrie *') ?></label>
            <select id="ev-sector" name="sector" required>
              <option value=""><?= t('— Select —','— Choisir —') ?></option>
              <option><?= t('Food &amp; Beverages','Alimentation &amp; Boissons') ?></option>
              <option><?= t('Fashion &amp; Beauty','Mode &amp; Beauté') ?></option>
              <option><?= t('Technology','Technologie') ?></option>
              <option><?= t('Health &amp; Wellness','Santé &amp; Bien-être') ?></option>
              <option><?= t('Education &amp; Training','Éducation &amp; Formation') ?></option>
              <option><?= t('Construction &amp; Real Estate','Construction &amp; Immobilier') ?></option>
              <option><?= t('Agriculture','Agriculture') ?></option>
              <option><?= t('Transport &amp; Logistics','Transport &amp; Logistique') ?></option>
              <option><?= t('Retail &amp; Trade','Commerce &amp; Négoce') ?></option>
              <option><?= t('Creative &amp; Media','Créatif &amp; Médias') ?></option>
              <option><?= t('Finance &amp; Insurance','Finance &amp; Assurance') ?></option>
              <option><?= t('Other','Autre') ?></option>
            </select>
          </div>
          <div class="ev-row">
            <label for="ev-loc"><?= t('Business location *','Localisation *') ?></label>
            <input type="text" id="ev-loc" name="location" placeholder="<?= t('e.g. Douala, Akwa','ex. Douala, Akwa') ?>" required>
          </div>
        </div>

        <div class="ev-row">
          <label for="ev-goal"><?= t('What do you hope to gain from the event?','Qu\'espérez-vous tirer de cet événement ?') ?></label>
          <textarea id="ev-goal" name="goal" placeholder="<?= t('More customers, partnerships, online visibility…','Plus de clients, des partenariats, de la visibilité en ligne…') ?>"></textarea>
        </div>

        <div class="ev-row ev-check-row">
          <input type="checkbox" id="ev-agree" name="agree237" style="width:auto;accent-color:var(--ev-gold-dk)">
          <label for="ev-agree">
            <?= t(
              'I would like a <strong>free 6-month listing</strong> on 237Biz.net',
              'Je souhaite un <strong>listing gratuit de 6 mois</strong> sur 237Biz.net'
            ) ?>
          </label>
        </div>

        <button type="submit" class="ev-btn">
          <?= t('Reserve my spot →','Réserver ma place →') ?>
        </button>
      </form>

      <p class="ev-wa-note">
        <?= t(
          'Prefer to register directly? WhatsApp <strong>670 673 339</strong> or <strong>670 522 785</strong>',
          'Préférez-vous vous inscrire directement ? WhatsApp <strong>670 673 339</strong> ou <strong>670 522 785</strong>'
        ) ?>
      </p>
    </div>

    <!-- Benefits sidebar -->
    <aside class="ev-aside">

      <div class="ev-aside-hdr">
        <div class="ev-aside-eyebrow"><?= t('Free with registration','Gratuit avec l\'inscription') ?></div>
        <h3><?= t('Your 237Biz.net starter pack — included at the event','Votre pack de démarrage 237Biz.net — inclus à l\'événement') ?></h3>
        <p><?= t(
          'Every attending business gets these tools set up on the day, at no extra cost.',
          'Chaque entreprise présente bénéficie de ces outils configurés sur place, sans frais supplémentaires.'
        ) ?></p>
      </div>

      <div class="ev-benefit">
        <div class="ev-bicon g">📋</div>
        <div>
          <div class="ev-btitle"><?= t('Free Paid Listing — 6 months','Annonce Payante Gratuite — 6 mois') ?></div>
          <div class="ev-bdesc">
            <?= t(
              'Your business appears in the 237Biz.net directory, visible to thousands of customers searching across Cameroon.',
              'Votre entreprise apparaît dans le répertoire 237Biz.net, visible par des milliers de clients au Cameroun.'
            ) ?>
          </div>
          <span class="ev-btag"><?= t('Worth 15,000 FCFA','Valeur 15 000 FCFA') ?></span>
        </div>
      </div>

      <div class="ev-benefit">
        <div class="ev-bicon a">📅</div>
        <div>
          <div class="ev-btitle"><?= t('Free Appointment Booking','Prise de Rendez-vous Gratuite') ?></div>
          <div class="ev-bdesc">
            <?= t(
              'Customers can book directly through your listing page — no missed calls, no chasing. Your schedule, managed online.',
              'Les clients peuvent réserver directement via votre page — plus d\'appels manqués. Votre planning, géré en ligne.'
            ) ?>
          </div>
          <span class="ev-btag"><?= t('Free','Gratuit') ?></span>
        </div>
      </div>

      <div class="ev-benefit">
        <div class="ev-bicon b">📷</div>
        <div>
          <div class="ev-btitle"><?= t('Product Gallery with Photos','Galerie de Produits avec Photos') ?></div>
          <div class="ev-bdesc">
            <?= t(
              'Showcase what you sell with a photo gallery on your profile. Let customers see your products before they visit.',
              'Mettez en valeur vos produits avec une galerie photos sur votre profil. Que les clients voient ce que vous vendez.'
            ) ?>
          </div>
          <span class="ev-btag"><?= t('Free','Gratuit') ?></span>
        </div>
      </div>

      <div class="ev-benefit">
        <div class="ev-bicon p">🔳</div>
        <div>
          <div class="ev-btitle"><?= t('Free QR Code','QR Code Gratuit') ?></div>
          <div class="ev-bdesc">
            <?= t(
              'A printed QR code linking directly to your 237Biz profile. Put it on your door, receipt or packaging.',
              'Un QR code imprimé menant directement à votre profil 237Biz. Affichez-le sur votre porte, reçu ou emballage.'
            ) ?>
          </div>
          <span class="ev-btag"><?= t('Free','Gratuit') ?></span>
        </div>
      </div>

      <hr class="ev-hr">

      <p class="ev-hint">
        <?= t(
          'Benefits are activated at the event. Bring your phone and photos of your products. Our team will set everything up with you on the day.',
          'Les avantages sont activés lors de l\'événement. Apportez votre téléphone et des photos de vos produits. Notre équipe vous configurera tout sur place.'
        ) ?>
      </p>

    </aside>
  </div><!-- /.ev-body -->

  <div class="ev-footer">
    <a href="https://thetahiriheffect.com">thetahiriheffect.com</a> &nbsp;·&nbsp;
    <a href="https://237biz.net">237biz.net</a>
  </div>

</div><!-- /.ev-page -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
(function() {
  var form = document.getElementById('ev-form');
  var wrap = document.getElementById('ev-form-wrap');
  if (!form) return;

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var required = form.querySelectorAll('[required]');
    var valid = true;
    required.forEach(function(el) {
      el.style.borderColor = '';
      if (!el.value.trim()) { el.style.borderColor = '#e53935'; valid = false; }
    });
    if (!valid) return;

    var fname   = document.getElementById('ev-fname').value.trim();
    var bizname = document.getElementById('ev-biz').value.trim();

    wrap.innerHTML =
      '<div class="ev-success">' +
      '<div class="ev-success-icon">🎉</div>' +
      '<h3><?= t("You're registered,","Vous êtes inscrit(e),") ?> ' + fname + '!</h3>' +
      '<p><?= t("We\'ve got your spot for","Votre place pour") ?> <strong>' + bizname + '</strong>.<br>' +
      '<?= t("Our team will confirm via WhatsApp shortly.","Notre équipe confirmera via WhatsApp très prochainement.") ?></p>' +
      '<div class="ev-success-wa">📲 <?= t("WhatsApp us if you have questions:","Contactez-nous sur WhatsApp :") ?><br>' +
      '<span>670 673 339 &nbsp;·&nbsp; 670 522 785</span></div>' +
      '<p style="margin-top:18px;font-size:12.5px;color:var(--ev-muted);">' +
      '<?= t("See you Thursday 29 October · 4pm · Lumpsom","À jeudi 29 octobre · 16h · Lumpsom") ?></p>' +
      '</div>';
  });
})();
</script>
