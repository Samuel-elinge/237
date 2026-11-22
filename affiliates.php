<?php
/**
 * affiliates.php — Affiliate landing page
 * Traffic from: WhatsApp, Facebook, SEO
 * Primary CTA: /join?path=agent
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';

$pageTitle = t('Become an Affiliate — Earn with 237Biz', 'Devenez Affilié — Gagnez avec 237Biz');
$pageDesc  = t(
    'Share a link. Earn 10% on every sale. Zero startup cost — free to join and earn via MTN or Orange Money.',
    'Partagez un lien. Gagnez 10% sur chaque vente. Zéro coût de démarrage — gratuit pour rejoindre et gagner via MTN ou Orange Money.'
);
require_once __DIR__ . '/includes/header.php';
?>

<style>
:root {
  --af: #8ab4f8;
  --af-dim: rgba(138,180,248,0.10);
  --af-glow: rgba(138,180,248,0.22);
  --dark: #07190d;
  --card-bg: rgba(255,255,255,0.04);
  --card-border: rgba(255,255,255,0.08);
}
.lp { background: var(--dark); color: #fff; overflow-x: hidden; }

.hero {
  min-height: 92vh;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 80px 20px 60px;
  background: radial-gradient(ellipse 80% 60% at 50% 10%, rgba(138,180,248,0.12) 0%, transparent 70%),
              var(--dark);
}
.hero-eyebrow {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--af-dim); border: 1px solid var(--af-glow);
  border-radius: 20px; padding: 6px 16px;
  font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em;
  color: var(--af); margin-bottom: 24px;
}
.hero h1 {
  font-family: 'Fraunces', serif; font-weight: 900;
  font-size: clamp(2rem, 7vw, 3.4rem);
  line-height: 1.1; margin: 0 0 20px; max-width: 680px;
}
.hero h1 em { color: var(--af); font-style: normal; }
.hero-sub {
  font-size: clamp(15px, 2.5vw, 18px); color: rgba(255,255,255,0.65);
  line-height: 1.65; max-width: 540px; margin: 0 auto 36px;
}
.btn-primary {
  display: inline-flex; align-items: center; gap: 10px;
  background: var(--af); color: #03110a;
  padding: 16px 32px; border-radius: 14px;
  font-size: 16px; font-weight: 800; text-decoration: none;
  transition: transform .15s, box-shadow .15s;
  box-shadow: 0 0 32px rgba(138,180,248,0.3);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 40px rgba(138,180,248,0.45); }

.hero-visual {
  margin-top: 48px;
  background: var(--card-bg); border: 1px solid var(--card-border);
  border-radius: 20px; padding: 24px 28px;
  display: inline-flex; gap: 20px; align-items: center;
}
.hero-stat { text-align: center; }
.hero-stat .num { font-family: 'Fraunces', serif; font-size: 1.9rem; font-weight: 900; color: var(--af); }
.hero-stat .lbl { font-size: 11px; color: rgba(255,255,255,0.45); text-transform: uppercase; letter-spacing: .07em; margin-top: 2px; }
.hero-divider { width: 1px; height: 40px; background: var(--card-border); }

.section { padding: 72px 20px; max-width: 900px; margin: 0 auto; }
.section-label {
  font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em;
  color: var(--af); margin-bottom: 12px;
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
.card:hover { border-color: var(--af-glow); }
.card-icon { font-size: 2rem; margin-bottom: 14px; }
.card h3 { font-size: 16px; font-weight: 800; margin: 0 0 8px; }
.card p  { font-size: 14px; color: rgba(255,255,255,0.55); line-height: 1.6; margin: 0; }

.steps { display: flex; flex-direction: column; }
.step { display: flex; gap: 20px; align-items: flex-start; padding: 28px 0; border-bottom: 1px solid var(--card-border); }
.step:last-child { border-bottom: none; }
.step-num {
  flex-shrink: 0; width: 44px; height: 44px; border-radius: 50%;
  background: var(--af-dim); border: 1px solid var(--af-glow);
  display: flex; align-items: center; justify-content: center;
  font-family: 'Fraunces', serif; font-weight: 900; font-size: 1.1rem; color: var(--af);
}
.step-body h3 { font-size: 16px; font-weight: 700; margin: 6px 0 6px; }
.step-body p  { font-size: 14px; color: rgba(255,255,255,0.55); line-height: 1.6; margin: 0; }

.testi-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
.testi {
  background: var(--af-dim); border: 1px dashed var(--af-glow);
  border-radius: 14px; padding: 20px 18px;
  font-size: 13.5px; color: rgba(255,255,255,0.4); font-style: italic; line-height: 1.6;
}

.footer-cta {
  background: linear-gradient(135deg, #030f1f 0%, #021020 100%);
  border-top: 1px solid var(--af-glow);
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
  border-top: 1px solid var(--af-glow);
}
.sticky-cta a {
  display: block; text-align: center;
  background: var(--af); color: #03110a;
  padding: 15px; border-radius: 12px;
  font-size: 15px; font-weight: 800; text-decoration: none;
}
@media (max-width: 768px) {
  .sticky-cta { display: block; }
  .testi-row { grid-template-columns: 1fr; }
  .footer-cta { padding-bottom: 100px; }
}
.divider { border: none; border-top: 1px solid var(--card-border); margin: 0; }
</style>

<div class="lp">

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<section class="hero">
  <div class="hero-eyebrow">🔗 <?= t('Affiliate Program','Programme Affilié') ?></div>
  <h1><?= t('Share a link. Earn <em>10% on every sale.</em>','Partagez un lien. Gagnez <em>10% sur chaque vente.</em>') ?></h1>
  <p class="hero-sub">
    <?= t(
      'Know business owners? Have a WhatsApp group? Share your unique 237Biz link and earn cash every time someone buys a listing, website, or business email.',
      'Vous connaissez des chefs d\'entreprise ? Vous avez un groupe WhatsApp ? Partagez votre lien unique 237Biz et gagnez de l\'argent chaque fois que quelqu\'un achète une annonce, un site web ou un email professionnel.'
    ) ?>
  </p>
  <a href="<?= SITE_URL ?>/join?path=agent" class="btn-primary">
    <?= t('Start Earning as an Affiliate →','Commencer à gagner comme Affilié →') ?>
  </a>

  <div class="hero-visual">
    <div class="hero-stat">
      <div class="num">10%</div>
      <div class="lbl"><?= t('Flat commission','Commission fixe') ?></div>
    </div>
    <div class="hero-divider"></div>
    <div class="hero-stat">
      <div class="num">0 XAF</div>
      <div class="lbl"><?= t('To join','Pour rejoindre') ?></div>
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
  <h2><?= t('Passive income, no tech skills needed.','Revenus passifs, aucune compétence tech requise.') ?></h2>
  <p class="section-sub"><?= t('If you can share a link, you can earn. It really is that simple.','Si vous pouvez partager un lien, vous pouvez gagner. C\'est vraiment aussi simple que ça.') ?></p>

  <div class="cards">
    <div class="card">
      <div class="card-icon">🔗</div>
      <h3><?= t('Zero Startup Cost','Zéro coût de démarrage') ?></h3>
      <p><?= t('Free to join. No website, no inventory, no upfront investment. Just your phone and your network.','Gratuit pour rejoindre. Pas de site web, pas de stock, pas d\'investissement initial. Juste votre téléphone et votre réseau.') ?></p>
    </div>
    <div class="card">
      <div class="card-icon">💸</div>
      <h3><?= t('10% Flat Commission','10% de Commission Fixe') ?></h3>
      <p><?= t('Earn on every listing, website package, and business email sold through your link. No caps, no minimums.','Gagnez sur chaque annonce, pack site web et email professionnel vendu via votre lien. Pas de plafond, pas de minimum.') ?></p>
    </div>
    <div class="card">
      <div class="card-icon">📱</div>
      <h3><?= t('Built-in CRM','CRM intégré') ?></h3>
      <p><?= t('Track your clicks, conversions, and earnings right from your phone. Follow up with leads without leaving the platform.','Suivez vos clics, conversions et gains directement depuis votre téléphone. Relancez les prospects sans quitter la plateforme.') ?></p>
    </div>
  </div>
</section>

<hr class="divider">

<!-- ══ HOW IT WORKS ══════════════════════════════════════════ -->
<section class="section">
  <div class="section-label"><?= t('Getting started','Comment commencer') ?></div>
  <h2><?= t('Three steps to passive income.','Trois étapes vers les revenus passifs.') ?></h2>
  <p class="section-sub"><?= t('From sign-up to first payout in days, not months.','De l\'inscription au premier paiement en jours, pas en mois.') ?></p>

  <div class="steps">
    <div class="step">
      <div class="step-num">1</div>
      <div class="step-body">
        <h3><?= t('Sign up and get your /r/yourname link','Inscrivez-vous et obtenez votre lien /r/votrenom') ?></h3>
        <p><?= t('Create a free account in under 2 minutes. You\'ll receive your personal referral link immediately — no waiting, no approval process.','Créez un compte gratuit en moins de 2 minutes. Vous recevrez votre lien de parrainage personnel immédiatement.') ?></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <div class="step-body">
        <h3><?= t('Share it on WhatsApp, Facebook, or in person','Partagez-le sur WhatsApp, Facebook ou en personne') ?></h3>
        <p><?= t('Drop your link in WhatsApp groups, Facebook, or give it directly to business owners you know who need an online presence.','Partagez votre lien dans des groupes WhatsApp, sur Facebook, ou donnez-le directement aux chefs d\'entreprise que vous connaissez.') ?></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <div class="step-body">
        <h3><?= t('Earn 10% deposited to your MoMo wallet','Gagnez 10% déposé sur votre portefeuille MoMo') ?></h3>
        <p><?= t('Every purchase made through your link earns you 10%. Paid directly to your MTN or Orange Money wallet — no bank account required.','Chaque achat effectué via votre lien vous rapporte 10%. Payé directement sur votre portefeuille MTN ou Orange Money.') ?></p>
      </div>
    </div>
  </div>
</section>

<hr class="divider">

<!-- ══ SOCIAL PROOF ══════════════════════════════════════════ -->
<section class="section" style="padding-bottom: 20px;">
  <div class="section-label"><?= t('Affiliate stories','Témoignages d\'affiliés') ?></div>
  <h2><?= t('Affiliates already earning.','Des affiliés qui gagnent déjà.') ?></h2>
  <div class="testi-row">
    <div class="testi"><?= t('"Your testimonial here — coming soon as our affiliate network grows."','"Votre témoignage ici — bientôt disponible à mesure que notre réseau d\'affiliés grandit."') ?></div>
    <div class="testi"><?= t('"Share your 237Biz affiliate story and inspire others to earn."','"Partagez votre histoire d\'affilié 237Biz et inspirez les autres à gagner."') ?></div>
  </div>
</section>

<!-- ══ FOOTER CTA ═════════════════════════════════════════════ -->
<div class="footer-cta">
  <div class="section-label" style="margin-bottom:16px;"><?= t('Ready to start?','Prêt à commencer ?') ?></div>
  <h2><?= t('Get Your Referral Link.','Obtenez votre lien de parrainage.') ?></h2>
  <p><?= t('Free to join. Takes 2 minutes. First commission possible today.','Gratuit pour rejoindre. Prend 2 minutes. Première commission possible aujourd\'hui.') ?></p>
  <a href="<?= SITE_URL ?>/join?path=agent" class="btn-primary">
    <?= t('Start Earning as an Affiliate →','Commencer à gagner comme Affilié →') ?>
  </a>
</div>

</div><!-- /.lp -->

<div class="sticky-cta">
  <a href="<?= SITE_URL ?>/join?path=agent">
    🔗 <?= t('Get Your Referral Link →','Obtenez votre lien →') ?>
  </a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
