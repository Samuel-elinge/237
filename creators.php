<?php
/**
 * creators.php — Content Creator landing page
 * Traffic from: TikTok/IG bios, WhatsApp, SEO
 * Primary CTA: /join?path=creator
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';

$pageTitle = t('Become a Creator — Earn with 237Biz', 'Devenez Créateur — Gagnez avec 237Biz');
$pageDesc  = t(
    'Turn your content into a monthly paycheck. Promote real Cameroonian businesses and earn a 50,000 XAF base + 15% commission on every sale.',
    'Transformez votre contenu en revenu mensuel. Promouvez de vraies entreprises camerounaises et gagnez une base de 50 000 XAF + 15% de commission sur chaque vente.'
);
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── Variables ───────────────────────────────── */
:root {
  --cr: #e07be0;          /* creator accent */
  --cr-dim: rgba(224,123,224,0.12);
  --cr-glow: rgba(224,123,224,0.25);
  --dark: #07190d;
  --card-bg: rgba(255,255,255,0.04);
  --card-border: rgba(255,255,255,0.08);
}

/* ── Page shell ──────────────────────────────── */
.lp { background: var(--dark); color: #fff; overflow-x: hidden; }

/* ── HERO ────────────────────────────────────── */
.hero {
  min-height: 92vh;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding: 80px 20px 60px;
  position: relative;
  background: radial-gradient(ellipse 80% 60% at 50% 10%, rgba(224,123,224,0.15) 0%, transparent 70%),
              var(--dark);
}
.hero-eyebrow {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--cr-dim); border: 1px solid var(--cr-glow);
  border-radius: 20px; padding: 6px 16px;
  font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em;
  color: var(--cr); margin-bottom: 24px;
}
.hero h1 {
  font-family: 'Fraunces', serif; font-weight: 900;
  font-size: clamp(2rem, 7vw, 3.4rem);
  line-height: 1.1; margin: 0 0 20px; max-width: 700px;
}
.hero h1 em { color: var(--cr); font-style: normal; }
.hero-sub {
  font-size: clamp(15px, 2.5vw, 18px); color: rgba(255,255,255,0.65);
  line-height: 1.65; max-width: 560px; margin: 0 auto 36px;
}
.btn-primary {
  display: inline-flex; align-items: center; gap: 10px;
  background: var(--cr); color: #fff;
  padding: 16px 32px; border-radius: 14px;
  font-size: 16px; font-weight: 800; text-decoration: none;
  transition: transform .15s, box-shadow .15s;
  box-shadow: 0 0 32px rgba(224,123,224,0.35);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 40px rgba(224,123,224,0.5); }

.hero-visual {
  margin-top: 48px;
  background: rgba(255,255,255,0.04); border: 1px solid var(--card-border);
  border-radius: 20px; padding: 24px 28px;
  display: inline-flex; gap: 20px; align-items: center;
  font-size: 14px;
}
.hero-stat { text-align: center; }
.hero-stat .num { font-family: 'Fraunces', serif; font-size: 1.9rem; font-weight: 900; color: var(--cr); }
.hero-stat .lbl { font-size: 11px; color: rgba(255,255,255,0.45); text-transform: uppercase; letter-spacing: .07em; margin-top: 2px; }
.hero-divider { width: 1px; height: 40px; background: var(--card-border); }

/* ── Sections ────────────────────────────────── */
.section { padding: 72px 20px; max-width: 900px; margin: 0 auto; }
.section-label {
  font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em;
  color: var(--cr); margin-bottom: 12px;
}
.section h2 {
  font-family: 'Fraunces', serif; font-weight: 900;
  font-size: clamp(1.6rem, 4vw, 2.4rem); margin: 0 0 14px;
}
.section-sub { font-size: 16px; color: rgba(255,255,255,0.55); line-height: 1.6; margin: 0 0 44px; }

/* ── Why cards ───────────────────────────────── */
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; }
.card {
  background: var(--card-bg); border: 1px solid var(--card-border);
  border-radius: 16px; padding: 24px 20px;
  transition: border-color .2s;
}
.card:hover { border-color: var(--cr-glow); }
.card-icon { font-size: 2rem; margin-bottom: 14px; }
.card h3 { font-size: 16px; font-weight: 800; margin: 0 0 8px; }
.card p  { font-size: 14px; color: rgba(255,255,255,0.55); line-height: 1.6; margin: 0; }

/* ── Steps ───────────────────────────────────── */
.steps { display: flex; flex-direction: column; gap: 0; }
.step {
  display: flex; gap: 20px; align-items: flex-start;
  padding: 28px 0;
  border-bottom: 1px solid var(--card-border);
}
.step:last-child { border-bottom: none; }
.step-num {
  flex-shrink: 0; width: 44px; height: 44px; border-radius: 50%;
  background: var(--cr-dim); border: 1px solid var(--cr-glow);
  display: flex; align-items: center; justify-content: center;
  font-family: 'Fraunces', serif; font-weight: 900; font-size: 1.1rem; color: var(--cr);
}
.step-body h3 { font-size: 16px; font-weight: 700; margin: 6px 0 6px; }
.step-body p  { font-size: 14px; color: rgba(255,255,255,0.55); line-height: 1.6; margin: 0; }

/* ── Testimonial placeholder ─────────────────── */
.testi-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
.testi {
  background: var(--cr-dim); border: 1px dashed var(--cr-glow);
  border-radius: 14px; padding: 20px 18px;
  font-size: 13.5px; color: rgba(255,255,255,0.4);
  font-style: italic; line-height: 1.6;
}

/* ── Footer CTA band ─────────────────────────── */
.footer-cta {
  background: linear-gradient(135deg, #18042a 0%, #120325 100%);
  border-top: 1px solid var(--cr-glow);
  text-align: center; padding: 72px 20px 80px;
}
.footer-cta h2 {
  font-family: 'Fraunces', serif; font-weight: 900;
  font-size: clamp(1.8rem, 4vw, 2.6rem); margin: 0 0 14px;
}
.footer-cta p { font-size: 16px; color: rgba(255,255,255,0.55); margin: 0 0 36px; }

/* ── Sticky CTA (mobile) ─────────────────────── */
.sticky-cta {
  display: none;
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 999;
  padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px));
  background: rgba(7,25,13,0.95); backdrop-filter: blur(12px);
  border-top: 1px solid var(--cr-glow);
}
.sticky-cta a {
  display: block; text-align: center;
  background: var(--cr); color: #fff;
  padding: 15px; border-radius: 12px;
  font-size: 15px; font-weight: 800; text-decoration: none;
}
@media (max-width: 768px) {
  .sticky-cta { display: block; }
  .testi-row { grid-template-columns: 1fr; }
  /* extra bottom padding so content clears the sticky bar */
  .footer-cta { padding-bottom: 100px; }
}

/* ── Divider ─────────────────────────────────── */
.divider { border: none; border-top: 1px solid var(--card-border); margin: 0; }
</style>

<div class="lp">

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<section class="hero">
  <div class="hero-eyebrow">🎬 <?= t('Creator Program','Programme Créateur') ?></div>
  <h1><?= t('<em>Turn your content</em> into a monthly paycheck.','<em>Transformez votre contenu</em> en revenu mensuel.') ?></h1>
  <p class="hero-sub">
    <?= t(
      'Stop creating for free. Promote real Cameroonian businesses on TikTok, IG, and YouTube. Get a guaranteed monthly base + cash for every sale you drive.',
      'Arrêtez de créer gratuitement. Promouvez de vraies entreprises camerounaises sur TikTok, IG et YouTube. Obtenez une base mensuelle garantie + des commissions sur chaque vente.'
    ) ?>
  </p>
  <a href="<?= SITE_URL ?>/join?path=creator" class="btn-primary">
    <?= t('Apply to be a Creator →','Postuler comme Créateur →') ?>
  </a>

  <!-- Stats strip -->
  <div class="hero-visual">
    <div class="hero-stat">
      <div class="num">50K</div>
      <div class="lbl"><?= t('XAF / month base','Base XAF / mois') ?></div>
    </div>
    <div class="hero-divider"></div>
    <div class="hero-stat">
      <div class="num">15%</div>
      <div class="lbl"><?= t('Base commission','Commission de base') ?></div>
    </div>
    <div class="hero-divider"></div>
    <div class="hero-stat">
      <div class="num">25%</div>
      <div class="lbl"><?= t('Top tier rate','Taux top niveau') ?></div>
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
  <div class="section-label"><?= t('Why creators choose us','Pourquoi les créateurs nous choisissent') ?></div>
  <h2><?= t('Built for Cameroonian creators.','Conçu pour les créateurs camerounais.') ?></h2>
  <p class="section-sub"><?= t('No middlemen. No delays. Real businesses, real commissions, real Mobile Money payouts.','Pas d\'intermédiaires. Pas de délais. De vraies entreprises, de vraies commissions, de vrais paiements Mobile Money.') ?></p>

  <div class="cards">
    <div class="card">
      <div class="card-icon">💰</div>
      <h3><?= t('Guaranteed Base Pay','Salaire de base garanti') ?></h3>
      <p><?= t('Qualified creators start with a 50,000 XAF/month base — before commissions. You earn even when you\'re still building your audience.','Les créateurs qualifiés commencent avec une base de 50 000 XAF/mois — avant les commissions. Vous gagnez même pendant que vous construisez votre audience.') ?></p>
    </div>
    <div class="card">
      <div class="card-icon">📈</div>
      <h3><?= t('Tiered Commissions','Commissions progressives') ?></h3>
      <p><?= t('Start at 15% on every sale your content drives. Hit 500K XAF in monthly sales and jump to 20%. Hit 1M XAF and earn 25% flat.','Commencez à 15% sur chaque vente générée. Atteignez 500K XAF en ventes mensuelles pour passer à 20%. Atteignez 1M XAF et gagnez 25% fixe.') ?></p>
    </div>
    <div class="card">
      <div class="card-icon">📊</div>
      <h3><?= t('Live Dashboard','Tableau de bord en temps réel') ?></h3>
      <p><?= t('See exactly which video drove which sale in real-time. No guessing, no waiting — just data you can act on immediately.','Voyez exactement quelle vidéo a généré quelle vente en temps réel. Pas de suppositions, pas d\'attente.') ?></p>
    </div>
  </div>
</section>

<hr class="divider">

<!-- ══ HOW IT WORKS ══════════════════════════════════════════ -->
<section class="section">
  <div class="section-label"><?= t('Getting started','Comment commencer') ?></div>
  <h2><?= t('Three steps to your first payout.','Trois étapes vers votre premier paiement.') ?></h2>
  <p class="section-sub"><?= t('No experience needed. If you have a phone and an audience, you\'re ready.','Aucune expérience requise. Si vous avez un téléphone et une audience, vous êtes prêt.') ?></p>

  <div class="steps">
    <div class="step">
      <div class="step-num">1</div>
      <div class="step-body">
        <h3><?= t('Sign up and get your unique referral link','Inscrivez-vous et obtenez votre lien de parrainage unique') ?></h3>
        <p><?= t('Create your creator account in under 2 minutes. You\'ll get a personal referral link to track every sale you generate.','Créez votre compte créateur en moins de 2 minutes. Vous obtiendrez un lien de parrainage personnel pour suivre chaque vente que vous générez.') ?></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <div class="step-body">
        <h3><?= t('Create videos promoting businesses on 237Biz','Créez des vidéos promouvant des entreprises sur 237Biz') ?></h3>
        <p><?= t('Pick any listed business — a restaurant, a salon, a tech shop — and make content your audience will love. Drop your link in bio or caption.','Choisissez n\'importe quelle entreprise listée — un restaurant, un salon, une boutique tech — et créez du contenu que votre audience adorera.') ?></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <div class="step-body">
        <h3><?= t('Track your sales and get paid via MoMo','Suivez vos ventes et soyez payé via MoMo') ?></h3>
        <p><?= t('Every click and conversion shows on your dashboard. Payouts go straight to your MTN or Orange Money wallet — no bank account needed.','Chaque clic et conversion s\'affiche sur votre tableau de bord. Les paiements vont directement sur votre portefeuille MTN ou Orange Money.') ?></p>
      </div>
    </div>
  </div>
</section>

<hr class="divider">

<!-- ══ SOCIAL PROOF (placeholder) ═══════════════════════════ -->
<section class="section" style="padding-bottom: 20px;">
  <div class="section-label"><?= t('Creator stories','Témoignages de créateurs') ?></div>
  <h2><?= t('Creators already earning.','Des créateurs qui gagnent déjà.') ?></h2>
  <div class="testi-row">
    <div class="testi"><?= t('"Your testimonial here — coming soon as our creator community grows."','"Votre témoignage ici — bientôt disponible à mesure que notre communauté de créateurs grandit."') ?></div>
    <div class="testi"><?= t('"Share your 237Biz creator story and inspire the next wave of earners."','"Partagez votre histoire de créateur 237Biz et inspirez la prochaine vague de gagnants."') ?></div>
  </div>
</section>

<!-- ══ FOOTER CTA ═════════════════════════════════════════════ -->
<div class="footer-cta">
  <div class="section-label" style="margin-bottom:16px;"><?= t('Ready to start?','Prêt à commencer ?') ?></div>
  <h2><?= t('Become a Creator Today.','Devenez Créateur dès aujourd\'hui.') ?></h2>
  <p><?= t('Applications take 2 minutes. Approvals within 48 hours.','Les candidatures prennent 2 minutes. Approbation sous 48 heures.') ?></p>
  <a href="<?= SITE_URL ?>/join?path=creator" class="btn-primary">
    <?= t('Apply to be a Creator →','Postuler comme Créateur →') ?>
  </a>
</div>

</div><!-- /.lp -->

<!-- ══ STICKY MOBILE CTA ══════════════════════════════════════ -->
<div class="sticky-cta">
  <a href="<?= SITE_URL ?>/join?path=creator">
    🎬 <?= t('Apply to be a Creator →','Postuler comme Créateur →') ?>
  </a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
