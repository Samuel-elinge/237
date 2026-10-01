<?php
/**
 * partners.php — 237Biz Partner Programme
 * URL: /partners
 */
require_once __DIR__ . '/includes/config.php';

$pageTitle = t('Partner Programme — 237Biz','Programme Partenaires — 237Biz');
$pageDesc  = t('Earn commissions and rewards by helping Cameroon businesses grow on 237Biz.','Gagnez des commissions en aidant les entreprises camerounaises à grandir sur 237Biz.');
require_once __DIR__ . '/includes/header.php';
?>

<style>
.partners-hero { background:linear-gradient(135deg,#081C10,#0d2f18); padding:80px 0 60px; text-align:center; }
.partners-hero h1 { font-family:'Fraunces',serif; font-weight:900; font-size:clamp(2rem,5vw,3.2rem); color:#fff; margin-bottom:12px; }
.partners-hero p  { font-size:1.05rem; color:rgba(255,255,255,0.65); max-width:560px; margin:0 auto 32px; line-height:1.7; }

.partner-tabs { display:flex; justify-content:center; gap:12px; margin-bottom:4px; }
.partner-tab  { padding:10px 28px; border-radius:10px; font-size:14px; font-weight:700; text-decoration:none; transition:all .2s; border:2px solid transparent; }
.partner-tab.agent   { background:rgba(138,180,248,0.15); color:#8ab4f8; border-color:rgba(138,180,248,0.3); }
.partner-tab.creator { background:rgba(224,123,224,0.15); color:#e07be0; border-color:rgba(224,123,224,0.3); }
.partner-tab:hover   { transform:translateY(-1px); }

/* Section wrappers */
.partner-section { padding:80px 0; }
.partner-section + .partner-section { border-top:1px solid rgba(255,255,255,0.06); }

/* Journey steps */
.journey { display:flex; align-items:flex-start; gap:0; flex-wrap:wrap; justify-content:center; margin:40px 0; }
.journey-step { flex:1; min-width:140px; max-width:200px; text-align:center; position:relative; padding:0 12px; }
.journey-step:not(:last-child)::after {
  content:'→'; position:absolute; right:-8px; top:22px;
  font-size:1.1rem; color:rgba(255,255,255,0.2);
}
.journey-icon { width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin:0 auto 10px; }
.journey-label { font-size:12.5px; color:rgba(255,255,255,0.7); line-height:1.4; }
.journey-title { font-size:13px; font-weight:700; color:#fff; margin-bottom:4px; }

/* Feature grid */
.feat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; margin-top:32px; }
.feat-card { background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:22px 24px; }
.feat-card .fi { font-size:1.5rem; margin-bottom:10px; }
.feat-card h3  { font-size:15px; font-weight:700; margin-bottom:6px; }
.feat-card p   { font-size:13.5px; color:rgba(255,255,255,0.6); line-height:1.6; margin:0; }

/* Commission table */
.comm-table { width:100%; border-collapse:collapse; margin-top:24px; }
.comm-table th { padding:10px 16px; text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); border-bottom:1px solid rgba(255,255,255,0.07); }
.comm-table td { padding:13px 16px; font-size:14px; border-bottom:1px solid rgba(255,255,255,0.05); }
.comm-table tr:hover td { background:rgba(255,255,255,0.02); }

/* CTA buttons */
.cta-pair { display:flex; gap:12px; flex-wrap:wrap; margin-top:32px; }
.btn-agent   { padding:13px 28px; background:#8ab4f8; color:#0a1a2e; border-radius:10px; font-size:14px; font-weight:700; text-decoration:none; display:inline-block; transition:all .2s; }
.btn-creator { padding:13px 28px; background:#e07be0; color:#0a1a2e; border-radius:10px; font-size:14px; font-weight:700; text-decoration:none; display:inline-block; transition:all .2s; }
.btn-agent:hover   { background:#bdd0fb; transform:translateY(-1px); }
.btn-creator:hover { background:#eda9ed; transform:translateY(-1px); }
.btn-outline-light { padding:12px 24px; background:transparent; border:2px solid rgba(255,255,255,0.2); color:rgba(255,255,255,0.75); border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; display:inline-block; transition:all .2s; }
.btn-outline-light:hover { border-color:rgba(255,255,255,0.5); color:#fff; }

/* FAQ */
.faq-item { border-bottom:1px solid rgba(255,255,255,0.06); }
.faq-q { padding:16px 0; font-size:14px; font-weight:600; cursor:pointer; display:flex; justify-content:space-between; align-items:center; color:rgba(255,255,255,0.85); }
.faq-a { padding:0 0 16px; font-size:13.5px; color:rgba(255,255,255,0.6); line-height:1.7; display:none; }
.faq-item.open .faq-a  { display:block; }
.faq-item.open .faq-q  { color:#fff; }
</style>

<!-- Hero -->
<div class="partners-hero">
  <div class="container">
    <div style="display:inline-block;background:rgba(252,209,22,0.12);border:1px solid rgba(252,209,22,0.3);border-radius:99px;padding:5px 16px;font-size:12.5px;font-weight:700;color:#fcd116;margin-bottom:20px;">
      🤝 237Biz <?= t('Partner Programme','Programme Partenaires') ?>
    </div>
    <h1><?= t('Earn by helping Cameroon businesses grow','Gagnez en aidant les entreprises camerounaises à grandir') ?></h1>
    <p><?= t('Join the 237Biz Partner Programme as a Sales Agent or Content Creator. Refer businesses, earn commissions, and grow with Cameroon\'s Business Hub.','Rejoignez le Programme Partenaires 237Biz en tant qu\'Agent de Vente ou Créateur de Contenu.') ?></p>
    <div class="partner-tabs">
      <a href="#agents"   class="partner-tab agent">👔 <?= t('Sales Agents','Agents de Vente') ?></a>
      <a href="#creators" class="partner-tab creator">🎬 <?= t('Content Creators','Créateurs de Contenu') ?></a>
    </div>
  </div>
</div>

<!-- Stats bar -->
<div style="background:#081C10;border-bottom:1px solid rgba(255,255,255,0.06);padding:24px 0;">
  <div class="container">
    <div style="display:flex;justify-content:center;gap:48px;flex-wrap:wrap;">
      <?php foreach ([
        ['🏪', t('Businesses listed','Entreprises listées'), '1,000+'],
        ['💰', t('Commission per referral','Commission par parrainage'), t('Up to 5,000 XAF','Jusqu\'à 5 000 XAF')],
        ['🍪', t('Referral cookie window','Fenêtre de parrainage'), t('30 days','30 jours')],
        ['💸', t('Minimum payout','Paiement minimum'), '10,000 XAF'],
      ] as [$icon, $label, $val]): ?>
      <div style="text-align:center;">
        <div style="font-size:1.4rem;font-weight:900;font-family:'Fraunces',serif;color:#fcd116;"><?= $val ?></div>
        <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-top:2px;"><?= $icon ?> <?= $label ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── SALES AGENTS SECTION ── -->
<div class="partner-section" id="agents" style="background:rgba(138,180,248,0.03);">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;" class="split-layout">

      <div>
        <div style="background:rgba(138,180,248,0.12);color:#8ab4f8;border-radius:99px;padding:4px 14px;font-size:12.5px;font-weight:700;display:inline-block;margin-bottom:16px;">
          👔 <?= t('SALES AGENTS','AGENTS DE VENTE') ?>
        </div>
        <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2.2rem);margin-bottom:14px;">
          <?= t('Find businesses. Refer them. Earn commission.','Trouvez des entreprises. Référez. Gagnez des commissions.') ?>
        </h2>
        <p style="color:rgba(255,255,255,0.65);font-size:15px;line-height:1.7;margin-bottom:24px;">
          <?= t('As a 237Biz Sales Agent, you actively approach local businesses, encourage them to join 237Biz, and earn a commission every time one of your referrals becomes a featured listing.','En tant qu\'Agent de Vente 237Biz, vous approchez activement les entreprises locales et gagnez une commission à chaque parrainage.') ?>
        </p>

        <!-- Journey -->
        <div class="journey">
          <?php
          $steps = [
              ['🔍','Find','Identify local businesses not yet on 237Biz'],
              ['💬','Refer','Share your referral link or personally onboard them'],
              ['✅','They Join','Business creates a featured listing'],
              ['📊','Track','See conversions in your dashboard'],
              ['💰','Earn','Commission paid via MoMo'],
          ];
          foreach ($steps as [$icon, $title, $desc]):
          ?>
          <div class="journey-step">
            <div class="journey-icon" style="background:rgba(138,180,248,0.15);color:#8ab4f8;"><?= $icon ?></div>
            <div class="journey-title"><?= $title ?></div>
            <div class="journey-label"><?= $desc ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="cta-pair">
          <a href="<?= SITE_URL ?>/join?path=agent" class="btn-agent">👔 <?= t('Become a Sales Agent','Devenir Agent de Vente') ?></a>
          <?php if (isLoggedIn() && (currentUser()['role'] ?? '') === 'sales_staff'): ?>
          <a href="<?= SITE_URL ?>/agent/dashboard" class="btn-outline-light">📊 <?= t('Agent Dashboard','Tableau Agent') ?></a>
          <?php else: ?>
          <a href="<?= SITE_URL ?>/login" class="btn-outline-light">🔐 <?= t('Agent Login','Connexion Agent') ?></a>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <h3 style="font-size:15px;font-weight:700;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px;"><?= t('What you get','Ce que vous obtenez') ?></h3>
        <div class="feat-grid" style="grid-template-columns:1fr;">
          <?php foreach ([
            ['🔗', t('Your unique referral link','Votre lien de parrainage unique'), t('Share 237biz.net/r/yourname anywhere — WhatsApp, in person, everywhere.','Partagez votre lien partout.')],
            ['📋', t('Assigned lead dashboard','Tableau de bord des leads assignés'), t('Admin assigns you hot leads directly. You see business name, location and contact.','L\'admin vous assigne des leads directement.')],
            ['💰', t('Commission per conversion','Commission par conversion'), t('Earn a flat XAF amount every time a referred business gets a featured listing.','Gagnez un montant XAF fixe par conversion.')],
            ['📅', t('Follow-up CRM built in','CRM de suivi intégré'), t('Log notes, set follow-up dates, track every lead status.','Consignez des notes, définissez des dates de relance.')],
            ['💸', t('Paid via MoMo','Payé via MoMo'), t('Earnings approved by admin and transferred to your MTN MoMo or Orange Money.','Gains approuvés et transférés sur votre MoMo.')],
          ] as [$fi, $ft, $fd]): ?>
          <div class="feat-card">
            <div class="fi"><?= $fi ?></div>
            <h3><?= $ft ?></h3>
            <p><?= $fd ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ── CREATORS SECTION ── -->
<div class="partner-section" id="creators">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;" class="split-layout">

      <div>
        <h3 style="font-size:15px;font-weight:700;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px;"><?= t('What you get','Ce que vous obtenez') ?></h3>
        <div class="feat-grid" style="grid-template-columns:1fr;">
          <?php foreach ([
            ['🔗', t('Unique referral link','Lien de parrainage unique'), t('Your personal link: 237biz.net/r/yourname. Perfect for TikTok bios, Instagram stories and WhatsApp status.','Votre lien personnel. Parfait pour TikTok, Instagram et WhatsApp.')],
            ['📊', t('Click & conversion tracking','Suivi des clics et conversions'), t('See exactly how many people clicked your link and how many became customers.','Voyez exactement combien de personnes ont cliqué et converti.')],
            ['📣', t('Paid campaigns with briefs','Campagnes payantes avec brief'), t('Join admin-created campaigns. Get a content brief, create your content, earn on approval.','Rejoignez des campagnes. Créez du contenu, gagnez à l\'approbation.')],
            ['🏆', t('Top performer bonuses','Bonus top performer'), t('Best performing creator per campaign earns an extra bonus on top of standard commission.','Le meilleur créateur de la campagne gagne un bonus supplémentaire.')],
            ['💸', t('Paid via MoMo','Payé via MoMo'), t('Earnings transferred to MTN MoMo or Orange Money once minimum threshold is reached.','Gains transférés sur votre MoMo une fois le seuil atteint.')],
          ] as [$fi, $ft, $fd]): ?>
          <div class="feat-card">
            <div class="fi"><?= $fi ?></div>
            <h3><?= $ft ?></h3>
            <p><?= $fd ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <div style="background:rgba(224,123,224,0.12);color:#e07be0;border-radius:99px;padding:4px 14px;font-size:12.5px;font-weight:700;display:inline-block;margin-bottom:16px;">
          🎬 <?= t('CONTENT CREATORS','CRÉATEURS DE CONTENU') ?>
        </div>
        <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2.2rem);margin-bottom:14px;">
          <?= t('Create content. Share your link. Earn rewards.','Créez du contenu. Partagez votre lien. Gagnez des récompenses.') ?>
        </h2>
        <p style="color:rgba(255,255,255,0.65);font-size:15px;line-height:1.7;margin-bottom:24px;">
          <?= t('Got an audience on TikTok, Instagram, Facebook or YouTube? Promote 237Biz to your followers using your unique referral link and earn rewards every time a business joins through you.','Vous avez une audience sur TikTok, Instagram, Facebook ou YouTube ? Promouvez 237Biz avec votre lien unique.') ?>
        </p>

        <!-- Journey -->
        <div class="journey">
          <?php
          $csteps = [
              ['🎬','Create','Make a video or post about 237Biz businesses'],
              ['🔗','Share','Include your referral link in bio or caption'],
              ['👆','Clicks','People click your link to discover businesses'],
              ['✅','Business Joins','They add a featured listing'],
              ['💰','Earn','You get rewarded automatically'],
          ];
          foreach ($csteps as [$icon, $title, $desc]):
          ?>
          <div class="journey-step">
            <div class="journey-icon" style="background:rgba(224,123,224,0.15);color:#e07be0;"><?= $icon ?></div>
            <div class="journey-title"><?= $title ?></div>
            <div class="journey-label"><?= $desc ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="cta-pair">
          <a href="<?= SITE_URL ?>/join?path=creator" class="btn-creator">🎬 <?= t('Become a Creator','Devenir Créateur') ?></a>
          <?php if (isLoggedIn() && (currentUser()['role'] ?? '') === 'creator'): ?>
          <a href="<?= SITE_URL ?>/creator/dashboard" class="btn-outline-light">📊 <?= t('Creator Dashboard','Tableau Créateur') ?></a>
          <?php else: ?>
          <a href="<?= SITE_URL ?>/login" class="btn-outline-light">🔐 <?= t('Creator Login','Connexion Créateur') ?></a>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Commission table -->
<div class="partner-section" style="background:rgba(255,255,255,0.01);">
  <div class="container" style="max-width:700px;">
    <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.6rem;text-align:center;margin-bottom:8px;"><?= t('Commission Structure','Structure des commissions') ?></h2>
    <p style="text-align:center;color:rgba(255,255,255,0.55);font-size:14px;margin-bottom:0;"><?= t('Applies to both Sales Agents and Content Creators.','Applicable aux Agents de Vente et Créateurs de Contenu.') ?></p>
    <table class="comm-table">
      <thead><tr><th><?= t('Conversion Type','Type de conversion') ?></th><th><?= t('Who qualifies','Qui est éligible') ?></th><th><?= t('Commission','Commission') ?></th></tr></thead>
      <tbody>
        <tr><td>🆓 <?= t('Free listing referral','Parrainage annonce gratuite') ?></td><td><?= t('Agents + Creators','Agents + Créateurs') ?></td><td style="color:#fcd116;font-weight:700;"><?= t('Admin-set (0–500 XAF)','Défini par admin') ?></td></tr>
        <tr><td>⭐ <?= t('Featured listing referral','Parrainage annonce vedette') ?></td><td><?= t('Agents + Creators','Agents + Créateurs') ?></td><td style="color:#00A878;font-weight:700;">5,000 XAF</td></tr>
        <tr><td>⬆️ <?= t('Free → Featured upgrade','Mise à niveau vers vedette') ?></td><td><?= t('Agents + Creators','Agents + Créateurs') ?></td><td style="color:#00A878;font-weight:700;">2,500 XAF</td></tr>
        <tr><td>📣 <?= t('Approved campaign content','Contenu de campagne approuvé') ?></td><td><?= t('Creators only','Créateurs uniquement') ?></td><td style="color:#e07be0;font-weight:700;"><?= t('Campaign-set amount','Montant de la campagne') ?></td></tr>
      </tbody>
    </table>
    <p style="font-size:12px;color:rgba(255,255,255,0.35);margin-top:12px;text-align:center;"><?= t('Minimum payout: 10,000 XAF. Payment via MTN MoMo or Orange Money. Commissions approved by admin before payment.','Paiement minimum : 10 000 XAF. Via MTN MoMo ou Orange Money. Commissions approuvées par l\'admin avant paiement.') ?></p>
  </div>
</div>

<!-- FAQ -->
<div class="partner-section">
  <div class="container" style="max-width:680px;">
    <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.6rem;text-align:center;margin-bottom:32px;"><?= t('Common Questions','Questions fréquentes') ?></h2>
    <?php
    $faqs = [
      [t('How long does my referral link stay active?','Combien de temps mon lien de parrainage reste-t-il actif ?'), t('Your referral link sets a 30-day cookie. If someone clicks your link and lists a business within 30 days, you get credited — even if they don\'t sign up immediately.','Votre lien définit un cookie de 30 jours. Si quelqu\'un clique et liste dans les 30 jours, vous êtes crédité.')],
      [t('When will I get paid?','Quand serai-je payé ?'), t('Commissions are approved by admin, then transferred to your MTN MoMo or Orange Money. The minimum payout threshold is 10,000 XAF.','Les commissions sont approuvées par l\'admin, puis transférées sur votre MoMo. Le seuil minimum est de 10 000 XAF.')],
      [t('Do I need a website or social media to be an Agent?','Ai-je besoin d\'un site web pour être Agent ?'), t('No. Agents approach businesses directly — in person, by phone, or via WhatsApp. Your referral link is just a bonus tracking tool.','Non. Les agents approchent les entreprises directement. Votre lien de parrainage est un outil de suivi bonus.')],
      [t('As a Creator, what platforms can I use?','En tant que Créateur, quelles plateformes puis-je utiliser ?'), t('TikTok, Instagram, Facebook, YouTube, Twitter/X — any platform where you can share a link. Add your referral link to your bio and mention 237Biz in your content.','TikTok, Instagram, Facebook, YouTube, Twitter/X. Ajoutez votre lien de parrainage dans votre bio.')],
      [t('Can I be both an Agent and a Creator?','Puis-je être à la fois Agent et Créateur ?'), t('Currently you register as one type. If you want to switch or expand, contact the admin team who can adjust your role.','Actuellement vous vous inscrivez comme un seul type. Contactez l\'équipe admin pour ajuster votre rôle.')],
    ];
    foreach ($faqs as [$q, $a]): ?>
    <div class="faq-item" onclick="this.classList.toggle('open')">
      <div class="faq-q"><?= $q ?> <span>+</span></div>
      <div class="faq-a"><?= $a ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Final CTA -->
<div style="background:linear-gradient(135deg,#08472F,#0e2f1a);padding:70px 0;text-align:center;">
  <div class="container">
    <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2.2rem);margin-bottom:12px;"><?= t('Ready to start earning?','Prêt à commencer à gagner ?') ?></h2>
    <p style="color:rgba(255,255,255,0.6);font-size:15px;margin-bottom:32px;"><?= t('Join 237Biz today as a Sales Agent or Content Creator.','Rejoignez 237Biz aujourd\'hui en tant qu\'Agent de Vente ou Créateur de Contenu.') ?></p>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/join?path=agent"   class="btn-agent"   style="font-size:15px;padding:14px 32px;">👔 <?= t('Become a Sales Agent','Devenir Agent de Vente') ?></a>
      <a href="<?= SITE_URL ?>/join?path=creator" class="btn-creator" style="font-size:15px;padding:14px 32px;">🎬 <?= t('Become a Creator','Devenir Créateur') ?></a>
    </div>
    <p style="color:rgba(255,255,255,0.3);font-size:13px;margin-top:20px;">
      <?= t('Already a partner?','Déjà partenaire ?') ?>
      <a href="<?= SITE_URL ?>/login" style="color:rgba(255,255,255,0.5);"><?= t('Sign in','Connectez-vous') ?></a>
    </p>
  </div>
</div>

<style>
@media(max-width:720px){ .split-layout { grid-template-columns:1fr !important; } .journey-step { min-width:80px; } }
</style>
<script>
document.querySelectorAll('.faq-item').forEach(function(el) {
  el.querySelector('.faq-q span').textContent = '+';
});
document.querySelectorAll('.faq-item').forEach(function(el) {
  el.addEventListener('click', function() {
    this.querySelector('.faq-q span').textContent = this.classList.contains('open') ? '−' : '+';
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
