<?php
/**
 * partners.php — Growth Partner landing page
 * Traffic from: Professional networks, LinkedIn, referrals
 * Primary CTA: /join?path=growth_partner
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';

$pageTitle = t('Become a Growth Partner — 237Biz', 'Devenez Partenaire de Croissance — 237Biz');
$pageDesc  = t(
    'Manage business accounts. Close deals. Earn 20–25% commission on every order — the highest tier on the platform.',
    'Gérez des comptes d\'entreprise. Concluez des deals. Gagnez 20–25% de commission sur chaque commande — le niveau le plus élevé de la plateforme.'
);
require_once __DIR__ . '/includes/header.php';
?>

<style>
:root {
  --gp: #00A878;
  --gp-dim: rgba(0,168,120,0.10);
  --gp-glow: rgba(0,168,120,0.22);
  --dark: #07190d;
  --card-bg: rgba(255,255,255,0.04);
  --card-border: rgba(255,255,255,0.08);
}
.lp { background: var(--dark); color: #fff; overflow-x: hidden; }

.hero {
  min-height: 92vh;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 80px 20px 60px;
  background: radial-gradient(ellipse 80% 60% at 50% 10%, rgba(0,168,120,0.14) 0%, transparent 70%),
              var(--dark);
}
.hero-eyebrow {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--gp-dim); border: 1px solid var(--gp-glow);
  border-radius: 20px; padding: 6px 16px;
  font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em;
  color: var(--gp); margin-bottom: 24px;
}
.hero h1 {
  font-family: 'Fraunces', serif; font-weight: 900;
  font-size: clamp(2rem, 7vw, 3.4rem);
  line-height: 1.1; margin: 0 0 20px; max-width: 700px;
}
.hero h1 em { color: var(--gp); font-style: normal; }
.hero-sub {
  font-size: clamp(15px, 2.5vw, 18px); color: rgba(255,255,255,0.65);
  line-height: 1.65; max-width: 560px; margin: 0 auto 36px;
}
.btn-primary {
  display: inline-flex; align-items: center; gap: 10px;
  background: var(--gp); color: #fff;
  padding: 16px 32px; border-radius: 14px;
  font-size: 16px; font-weight: 800; text-decoration: none;
  transition: transform .15s, box-shadow .15s;
  box-shadow: 0 0 32px rgba(0,168,120,0.35);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 40px rgba(0,168,120,0.5); }

.hero-visual {
  margin-top: 48px;
  background: var(--card-bg); border: 1px solid var(--card-border);
  border-radius: 20px; padding: 24px 28px;
  display: inline-flex; gap: 20px; align-items: center;
}
.hero-stat { text-align: center; }
.hero-stat .num { font-family: 'Fraunces', serif; font-size: 1.9rem; font-weight: 900; color: var(--gp); }
.hero-stat .lbl { font-size: 11px; color: rgba(255,255,255,0.45); text-transform: uppercase; letter-spacing: .07em; margin-top: 2px; }
.hero-divider { width: 1px; height: 40px; background: var(--card-border); }

.section { padding: 72px 20px; max-width: 900px; margin: 0 auto; }
.section-label {
  font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em;
  color: var(--gp); margin-bottom: 12px;
}
.section h2 {
  font-family: 'Fraunces', serif; font-weight: 900;
  font-size: clamp(1.6rem, 4vw, 2.4rem); margin: 0 0 14px;
}
.section-sub { font-size: 16px; color: rgba(255,255,255,0.55); line-height: 1.6; margin: 0 0 44px; }

.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; }
.card {
  background: var(--card-bg); border: 1px solid var(--card-border);
  border-radius: 16px; padding: 24px 20px; transition: border-color .2s;
}
.card:hover { border-color: var(--gp-glow); }
.card-icon { font-size: 2rem; margin-bottom: 14px; }
.card h3 { font-size: 16px; font-weight: 800; margin: 0 0 8px; }
.card p  { font-size: 14px; color: rgba(255,255,255,0.55); line-height: 1.6; margin: 0; }

.steps { display: flex; flex-direction: column; }
.step { display: flex; gap: 20px; align-items: flex-start; padding: 28px 0; border-bottom: 1px solid var(--card-border); }
.step:last-child { border-bottom: none; }
.step-num {
  flex-shrink: 0; width: 44px; height: 44px; border-radius: 50%;
  background: var(--gp-dim); border: 1px solid var(--gp-glow);
  display: flex; align-items: center; justify-content: center;
  font-family: 'Fraunces', serif; font-weight: 900; font-size: 1.1rem; color: var(--gp);
}
.step-body h3 { font-size: 16px; font-weight: 700; margin: 6px 0 6px; }
.step-body p  { font-size: 14px; color: rgba(255,255,255,0.55); line-height: 1.6; margin: 0; }

/* Commission tier table */
.tier-table {
  width: 100%; border-collapse: collapse; margin-top: 32px;
  font-size: 14px;
}
.tier-table th {
  text-align: left; padding: 12px 16px;
  background: var(--gp-dim); color: var(--gp);
  font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
  border-bottom: 1px solid var(--gp-glow);
}
.tier-table td { padding: 14px 16px; border-bottom: 1px solid var(--card-border); }
.tier-table tr:last-child td { border-bottom: none; }
.tier-table .tier-rate {
  font-family: 'Fraunces', serif; font-weight: 900; font-size: 1.3rem; color: var(--gp);
}
.tier-table tr.highlight td { background: var(--gp-dim); }

.testi-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
.testi {
  background: var(--gp-dim); border: 1px dashed var(--gp-glow);
  border-radius: 14px; padding: 20px 18px;
  font-size: 13.5px; color: rgba(255,255,255,0.4); font-style: italic; line-height: 1.6;
}

.footer-cta {
  background: linear-gradient(135deg, #031208 0%, #04170a 100%);
  border-top: 1px solid var(--gp-glow);
  text-align: center; padding: 72px 20px 80px;
}
.footer-cta h2 {
  font-family: 'Fraunces', serif; font-weight: 900;
  font-size: clamp(1.8rem, 4vw, 2.6rem); margin: 0 0 14px;
}
.footer-cta p { font-size: 16px; color: rgba(255,255,255,0.55); margin: 0 0 36px; }

.sticky-cta {
  display: none;
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 999;
  padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px));
  background: rgba(7,25,13,0.95); backdrop-filter: blur(12px);
  border-top: 1px solid var(--gp-glow);
}
.sticky-cta a {
  display: block; text-align: center;
  background: var(--gp); color: #fff;
  padding: 15px; border-radius: 12px;
  font-size: 15px; font-weight: 800; text-decoration: none;
}
@media (max-width: 768px) {
  .sticky-cta { display: block; }
  .testi-row { grid-template-columns: 1fr; }
  .footer-cta { padding-bottom: 100px; }
  .tier-table { font-size: 13px; }
  .tier-table th, .tier-table td { padding: 10px 12px; }
}
.divider { border: none; border-top: 1px solid var(--card-border); margin: 0; }
</style>

<div class="lp">

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<section class="hero">
  <div class="hero-eyebrow">🤝 <?= t('Growth Partner Program','Programme Partenaire de Croissance') ?></div>
  <h1><?= t('Manage businesses. Close deals. <em>Earn 25%.</em>','Gérez des entreprises. Concluez des deals. <em>Gagnez 25%.</em>') ?></h1>
  <p class="hero-sub">
    <?= t(
      'This isn\'t just affiliate marketing — it\'s B2B sales. Take ownership of business accounts, place orders on their behalf, and earn the highest commission tier on the platform.',
      'Ce n\'est pas du simple marketing d\'affiliation — c\'est de la vente B2B. Prenez en charge des comptes d\'entreprise, passez des commandes en leur nom, et gagnez le niveau de commission le plus élevé de la plateforme.'
    ) ?>
  </p>
  <a href="<?= SITE_URL ?>/join?path=growth_partner" class="btn-primary">
    <?= t('Apply as a Growth Partner →','Postuler comme Partenaire de Croissance →') ?>
  </a>

  <div class="hero-visual">
    <div class="hero-stat">
      <div class="num">20%</div>
      <div class="lbl"><?= t('New orders','Nouvelles commandes') ?></div>
    </div>
    <div class="hero-divider"></div>
    <div class="hero-stat">
      <div class="num">25%</div>
      <div class="lbl"><?= t('Above 1M XAF','Au-dessus de 1M XAF') ?></div>
    </div>
    <div class="hero-divider"></div>
    <div class="hero-stat">
      <div class="num">10%</div>
      <div class="lbl"><?= t('On renewals','Sur les renouvellements') ?></div>
    </div>
    <div class="hero-divider"></div>
    <div class="hero-stat">
      <div class="num">📱</div>
      <div class="lbl"><?= t('MoMo payout','Paiement MoMo') ?></div>
    </div>
  </div>
</section>

<hr class="divider">

<!-- ══ WHY 237BIZ ═════════════════════════════════════════════ -->
<section class="section">
  <div class="section-label"><?= t('Why join','Pourquoi rejoindre') ?></div>
  <h2><?= t('The highest-paid role on the platform.','Le rôle le mieux payé de la plateforme.') ?></h2>
  <p class="section-sub"><?= t('Growth Partners aren\'t just resellers — they\'re account managers who own their client relationships and earn recurring income.','Les Partenaires de Croissance ne sont pas de simples revendeurs — ce sont des gestionnaires de comptes qui possèdent leurs relations clients et gagnent des revenus récurrents.') ?></p>

  <div class="cards">
    <div class="card">
      <div class="card-icon">🤝</div>
      <h3><?= t('Account Ownership','Propriété des comptes') ?></h3>
      <p><?= t('You manage the businesses. You place their orders for websites, emails, and spotlight content — acting as their dedicated digital manager.','Vous gérez les entreprises. Vous passez leurs commandes pour les sites web, emails et contenu vedette — agissant comme leur gestionnaire numérique dédié.') ?></p>
    </div>
    <div class="card">
      <div class="card-icon">🏆</div>
      <h3><?= t('Highest Payouts','Les meilleurs paiements') ?></h3>
      <p><?= t('20% commission on new orders, jumping to 25% once you pass 1M XAF/month in managed sales. The more you grow your clients, the more you earn.','20% de commission sur les nouvelles commandes, passant à 25% une fois que vous dépassez 1M XAF/mois en ventes gérées.') ?></p>
    </div>
    <div class="card">
      <div class="card-icon">🔄</div>
      <h3><?= t('Renewal Income','Revenus de renouvellement') ?></h3>
      <p><?= t('Earn 10% every time your managed businesses renew their listings or services. Build a book of clients that generates monthly recurring income.','Gagnez 10% chaque fois que vos entreprises gérées renouvellent leurs annonces ou services. Constituez un portefeuille de clients qui génère des revenus récurrents mensuels.') ?></p>
    </div>
  </div>

  <!-- Commission tier table -->
  <div style="overflow-x:auto; margin-top: 40px;">
    <table class="tier-table">
      <thead>
        <tr>
          <th><?= t('Monthly Sales Volume','Volume de ventes mensuel') ?></th>
          <th><?= t('Commission Rate','Taux de commission') ?></th>
          <th><?= t('Type','Type') ?></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><?= t('Any amount','Tout montant') ?></td>
          <td class="tier-rate">20%</td>
          <td><?= t('New orders','Nouvelles commandes') ?></td>
        </tr>
        <tr class="highlight">
          <td><?= t('Above 1,000,000 XAF/mo','Au-dessus de 1 000 000 XAF/mois') ?></td>
          <td class="tier-rate">25%</td>
          <td><?= t('New orders','Nouvelles commandes') ?></td>
        </tr>
        <tr>
          <td><?= t('Any amount','Tout montant') ?></td>
          <td class="tier-rate">10%</td>
          <td><?= t('Renewals (recurring)','Renouvellements (récurrents)') ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</section>

<hr class="divider">

<!-- ══ HOW IT WORKS ══════════════════════════════════════════ -->
<section class="section">
  <div class="section-label"><?= t('Getting started','Comment commencer') ?></div>
  <h2><?= t('From your first client to recurring income.','De votre premier client aux revenus récurrents.') ?></h2>
  <p class="section-sub"><?= t('Growth Partners are approved individually — we want to make sure you\'re set up for success.','Les Partenaires de Croissance sont approuvés individuellement — nous voulons nous assurer que vous êtes prêt pour le succès.') ?></p>

  <div class="steps">
    <div class="step">
      <div class="step-num">1</div>
      <div class="step-body">
        <h3><?= t('Find local businesses that need an online presence','Trouvez des entreprises locales qui ont besoin d\'une présence en ligne') ?></h3>
        <p><?= t('Think restaurants, salons, schools, medical clinics — any business not yet on 237Biz. Your local knowledge is your biggest asset.','Pensez aux restaurants, salons, écoles, cliniques médicales — toute entreprise pas encore sur 237Biz. Votre connaissance locale est votre plus grand atout.') ?></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <div class="step-body">
        <h3><?= t('Create their 237Biz account and place orders on their behalf','Créez leur compte 237Biz et passez des commandes en leur nom') ?></h3>
        <p><?= t('As their Growth Partner, you manage everything — their listing, website, email, and any promotions. They get a great online presence; you earn on every order.','En tant que leur Partenaire de Croissance, vous gérez tout — leur annonce, site web, email et toute promotion. Ils obtiennent une excellente présence en ligne ; vous gagnez sur chaque commande.') ?></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <div class="step-body">
        <h3><?= t('Earn your commission instantly on your real-time dashboard','Gagnez votre commission instantanément sur votre tableau de bord en temps réel') ?></h3>
        <p><?= t('Watch your earnings grow as you build your managed-accounts portfolio. Payouts go directly to your MTN or Orange Money wallet monthly.','Regardez vos gains croître à mesure que vous construisez votre portefeuille de comptes gérés. Les paiements vont directement sur votre portefeuille MTN ou Orange Money mensuellement.') ?></p>
      </div>
    </div>
  </div>
</section>

<hr class="divider">

<!-- ══ SOCIAL PROOF ══════════════════════════════════════════ -->
<section class="section" style="padding-bottom: 20px;">
  <div class="section-label"><?= t('Partner stories','Témoignages de partenaires') ?></div>
  <h2><?= t('Partners already closing deals.','Des partenaires qui concluent déjà des deals.') ?></h2>
  <div class="testi-row">
    <div class="testi"><?= t('"Your testimonial here — coming soon as our partner network grows."','"Votre témoignage ici — bientôt disponible à mesure que notre réseau de partenaires grandit."') ?></div>
    <div class="testi"><?= t('"Share your 237Biz partner story and inspire the next generation of business developers."','"Partagez votre histoire de partenaire 237Biz et inspirez la prochaine génération de développeurs d\'affaires."') ?></div>
  </div>
</section>

<!-- ══ FOOTER CTA ═════════════════════════════════════════════ -->
<div class="footer-cta">
  <div class="section-label" style="margin-bottom:16px;"><?= t('Ready to start?','Prêt à commencer ?') ?></div>
  <h2><?= t('Join the Partner Program.','Rejoignez le Programme Partenaire.') ?></h2>
  <p><?= t('Applications reviewed within 48 hours. Limited spots per region.','Candidatures examinées sous 48 heures. Places limitées par région.') ?></p>
  <a href="<?= SITE_URL ?>/join?path=growth_partner" class="btn-primary">
    <?= t('Apply as a Growth Partner →','Postuler comme Partenaire de Croissance →') ?>
  </a>
</div>

</div><!-- /.lp -->

<div class="sticky-cta">
  <a href="<?= SITE_URL ?>/join?path=growth_partner">
    🤝 <?= t('Apply as a Growth Partner →','Postuler comme Partenaire →') ?>
  </a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
