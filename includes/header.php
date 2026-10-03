<?php
// includes/header.php — 237Biz Business Hub
// Handle lang switch before any output
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en','fr'])) {
    setcookie('lang', $_GET['lang'], time()+31536000, '/');
    $_SESSION['lang'] = $_GET['lang'];
    redirect(strtok($_SERVER['REQUEST_URI'], '?'));
}
$lang  = lang();
$isFr  = $lang === 'fr';
try {
    $cats = db()->query('SELECT * FROM categories ORDER BY sort_order')->fetchAll();
    $locs = db()->query('SELECT * FROM locations ORDER BY sort_order')->fetchAll();
} catch(Exception $e) { $cats = []; $locs = []; }
$cu     = isLoggedIn() ? currentUser() : null;
$cuRole = $cu['role'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($pageTitle ?? t('237Biz — Cameroon\'s Business Hub','237Biz — Le Hub des Entreprises du Cameroun')) ?></title>
<meta name="description" content="<?= e($pageDesc ?? t('Discover, connect and grow with Cameroon\'s Business Hub. Find businesses, list your company, or earn as a Sales Agent or Creator.','Découvrez, connectez et grandissez avec le Hub des Entreprises du Cameroun.')) ?>">

<!-- Open Graph / WhatsApp / Facebook -->
<meta property="og:type"        content="website">
<meta property="og:url"         content="<?= e(SITE_URL . ($_SERVER['REQUEST_URI'] ?? '/')) ?>">
<meta property="og:title"       content="<?= e($pageTitle ?? '237Biz — Cameroon\'s Business Hub') ?>">
<meta property="og:description" content="<?= e($pageDesc ?? t('Discover, connect and grow with Cameroon\'s Business Hub.','Le Hub des Entreprises du Cameroun.')) ?>">
<meta property="og:image"       content="<?= SITE_URL ?>/assets/images/og-image.jpg">
<meta property="og:locale"      content="<?= lang() === 'fr' ? 'fr_CM' : 'en_CM' ?>">
<meta property="og:site_name"   content="237Biz">

<!-- Twitter / X Card -->
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="<?= e($pageTitle ?? '237Biz — Cameroon\'s Business Hub') ?>">
<meta name="twitter:description" content="<?= e($pageDesc ?? t('Discover, connect and grow with Cameroon\'s Business Hub.','Le Hub des Entreprises du Cameroun.')) ?>">
<meta name="twitter:image"       content="<?= SITE_URL ?>/assets/images/og-image.jpg">

<!-- Canonical -->
<link rel="canonical" href="<?= e(SITE_URL . strtok($_SERVER['REQUEST_URI'] ?? '/', '?')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,700;0,9..144,900&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">
<?= $extraHead ?? '' ?>

<style>
/* ════════════════════════════════════════════
   NAVBAR BASE
════════════════════════════════════════════ */
#navbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 900;
  background: rgba(8,28,16,0.97);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(255,255,255,0.07);
  height: 64px;
  display: flex; align-items: center;
  padding: 0 24px;
  gap: 0;
}
.nav-brand {
  text-decoration: none; flex-shrink: 0; margin-right: 32px;
  display: flex; align-items: baseline; gap: 1px;
}
.brand-num  { font-family:'Fraunces',serif; font-weight:900; font-size:1.5rem; color:#fcd116; }
.brand-word { font-family:'Fraunces',serif; font-weight:900; font-size:1.5rem; color:#fff; }
.brand-tld  { font-size:0.65rem; color:rgba(255,255,255,0.4); margin-left:1px; }

/* ── Main nav items ── */
.nav-main { display:flex; align-items:center; gap:2px; flex:1; }
.nav-item  { position:relative; }
.nav-link  {
  display:flex; align-items:center; gap:4px;
  padding:0 12px; height:64px;
  font-size:13.5px; font-weight:500; color:rgba(255,255,255,0.75);
  text-decoration:none; white-space:nowrap; cursor:pointer;
  transition:color .15s; background:none; border:none; font-family:inherit;
}
.nav-link:hover, .nav-item:hover > .nav-link { color:#fff; }
.nav-link .caret { font-size:9px; opacity:.5; transition:transform .2s; }
.nav-item:hover > .nav-link .caret { transform:rotate(180deg); }

/* ── Mega menu panel ── */
.mega-menu {
  display: none; position: absolute; top: 64px; left: 50%;
  transform: translateX(-50%);
  background: #0a1f10;
  border: 1px solid rgba(255,255,255,0.1);
  border-top: 2px solid #00A878;
  border-radius: 0 0 14px 14px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.5);
  padding: 24px;
  min-width: 480px;
  z-index: 901;
}
.nav-item:hover .mega-menu { display: flex; }
/* Left-aligned menus */
.mega-menu.align-left  { left: 0; transform: none; }
.mega-menu.align-right { left: auto; right: 0; transform: none; }

.mega-col { flex:1; min-width:160px; }
.mega-col + .mega-col { border-left:1px solid rgba(255,255,255,0.06); padding-left:20px; margin-left:4px; }
.mega-col-title {
  font-size:10.5px; font-weight:700; text-transform:uppercase;
  letter-spacing:.08em; color:#00A878; margin-bottom:10px;
}
.mega-link {
  display:flex; align-items:center; gap:8px;
  padding:7px 10px; border-radius:8px;
  font-size:13px; color:rgba(255,255,255,0.72);
  text-decoration:none; transition:background .12s,color .12s;
  white-space:nowrap;
}
.mega-link:hover { background:rgba(255,255,255,0.06); color:#fff; }
.mega-link .icon { font-size:14px; width:20px; text-align:center; flex-shrink:0; }
.mega-link small { display:block; font-size:11px; color:rgba(255,255,255,0.38); margin-top:1px; line-height:1.2; }

/* ── Right side utilities ── */
.nav-utils { display:flex; align-items:center; gap:8px; flex-shrink:0; margin-left:16px; }
.lang-toggle { display:flex; gap:0; border-radius:7px; overflow:hidden; border:1px solid rgba(255,255,255,0.12); }
.lang-btn {
  padding:5px 10px; font-size:12px; font-weight:700;
  color:rgba(255,255,255,0.55); text-decoration:none; transition:all .15s;
}
.lang-btn.active { background:rgba(0,168,120,0.25); color:#00A878; }

.nav-signin {
  padding:6px 14px; font-size:13px; font-weight:600;
  color:rgba(255,255,255,0.75); text-decoration:none;
  border-radius:8px; transition:color .15s;
}
.nav-signin:hover { color:#fff; }
.btn-join {
  padding:7px 18px; font-size:13px; font-weight:700;
  background:#fcd116; color:#0A1A0F; border-radius:8px;
  text-decoration:none; white-space:nowrap; transition:background .15s;
}
.btn-join:hover { background:#ffe44d; }

/* ── User dropdown ── */
.nav-user-menu   { position:relative; }
.nav-user-btn    {
  display:flex; align-items:center; gap:5px;
  padding:6px 13px; border-radius:8px;
  background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1);
  color:#fff; font-size:13px; font-weight:600; cursor:pointer;
  text-decoration:none; transition:background .15s;
}
.nav-user-btn:hover { background:rgba(255,255,255,0.1); }
.nav-user-dropdown {
  display:none; position:absolute; top:calc(100% + 2px); right:0;
  background:#0a1f10; border:1px solid rgba(255,255,255,0.1);
  border-radius:12px; min-width:210px; padding:6px;
  box-shadow:0 12px 40px rgba(0,0,0,0.4); z-index:902;
}
/* Bridge the gap so mouse can travel from button into dropdown */
.nav-user-menu::after {
  content:''; position:absolute; top:100%; right:0;
  width:100%; height:10px;
}
.nav-user-menu:hover .nav-user-dropdown,
.nav-user-menu.open  .nav-user-dropdown { display:block; }
.nav-dd-item {
  display:flex; align-items:center; gap:9px;
  padding:9px 12px; border-radius:8px; font-size:13px;
  color:rgba(255,255,255,0.75); text-decoration:none; transition:background .12s;
}
.nav-dd-item:hover { background:rgba(255,255,255,0.06); color:#fff; }
.nav-dd-sep { height:1px; background:rgba(255,255,255,0.07); margin:4px 6px; }
.nav-dd-item.danger { color:#ff6b7a; }
.nav-dd-item.danger:hover { background:rgba(230,57,70,0.1); }

/* ── Mobile toggle ── */
.nav-mobile-toggle {
  display:none; background:none; border:none; color:#fff;
  font-size:1.3rem; cursor:pointer; margin-left:auto; padding:4px 8px;
}

/* ── Impersonation banner ── */
.impersonation-bar {
  position:fixed; bottom:0; left:0; right:0; z-index:9999;
  background:#1a1a2e; border-top:2px solid #8ab4f8;
  padding:10px 20px; display:flex; align-items:center;
  justify-content:space-between; gap:12px; font-size:13px;
}

/* ── Mobile ── */
@media(max-width:960px){
  #navbar { flex-wrap:wrap; height:auto; padding:12px 16px; }
  .nav-main { display:none; flex-direction:column; width:100%; padding:8px 0; gap:0; }
  #navbar.open .nav-main { display:flex; }
  .nav-link { height:auto; padding:10px 8px; }
  .nav-item { width:100%; }
  .mega-menu { position:static; transform:none; min-width:0; width:100%;
               border:none; border-top:1px solid rgba(255,255,255,0.07);
               border-radius:0; box-shadow:none; padding:8px 16px;
               display:none !important; }
  .nav-item:hover .mega-menu,
  .nav-item.open  .mega-menu { display:flex !important; flex-wrap:wrap; }
  .nav-mobile-toggle { display:block; }
  .nav-utils { margin-left:auto; }
}
</style>
</head>
<body>

<?php if (!empty($_SESSION['admin_original_id'])): ?>
<div class="impersonation-bar">
  <span style="color:#8ab4f8;">👁 <?= t('Viewing as','Connecté en tant que') ?> <strong><?= e($_SESSION['impersonating'] ?? '') ?></strong></span>
  <a href="<?= SITE_URL ?>/admin/login-as.php?return=1"
     style="background:rgba(138,180,248,0.2);border:1px solid rgba(138,180,248,0.5);color:#8ab4f8;border-radius:7px;padding:5px 16px;text-decoration:none;font-weight:700;">
    ← <?= t('Return to Admin','Retour Admin') ?>
  </a>
</div>
<?php endif; ?>

<nav id="navbar">

  <!-- Brand -->
  <a href="<?= SITE_URL ?>/" class="nav-brand">
    <span class="brand-num">237</span><span class="brand-word">Biz</span><span class="brand-tld">.net</span>
  </a>

  <!-- Main menu -->
  <div class="nav-main">

    <!-- Discover -->
    <div class="nav-item">
      <button class="nav-link">🔍 <?= t('Discover','Découvrir') ?> <span class="caret">▾</span></button>
      <div class="mega-menu align-left" style="min-width:520px;">
        <div class="mega-col">
          <div class="mega-col-title"><?= t('Browse','Parcourir') ?></div>
          <a href="<?= SITE_URL ?>/listings" class="mega-link"><span class="icon">🏪</span><span><?= t('All Businesses','Toutes les Entreprises') ?><small><?= t('The full directory','Le répertoire complet') ?></small></span></a>
          <a href="<?= SITE_URL ?>/listings?sort=featured" class="mega-link"><span class="icon">⭐</span><span><?= t('Featured Businesses','Entreprises Vedettes') ?><small><?= t('Top verified listings','Annonces vérifiées') ?></small></span></a>
          <a href="<?= SITE_URL ?>/listings?sort=newest" class="mega-link"><span class="icon">🆕</span><span><?= t('Recently Added','Ajoutées récemment') ?><small><?= t('New listings','Nouvelles annonces') ?></small></span></a>
          <a href="<?= SITE_URL ?>/listings?sort=popular" class="mega-link"><span class="icon">🔥</span><span><?= t('Popular Businesses','Entreprises Populaires') ?><small><?= t('Most viewed','Les plus vues') ?></small></span></a>
        </div>
        <div class="mega-col">
          <div class="mega-col-title"><?= t('By Category','Par Catégorie') ?></div>
          <?php foreach(array_slice($cats, 0, 6) as $cat): ?>
          <a href="<?= SITE_URL ?>/category/<?= e($cat['slug'] ?? strtolower(str_replace(' ','-',$cat['name_en']))) ?>" class="mega-link">
            <span class="icon"><?= $cat['icon'] ?></span>
            <?= e($isFr ? $cat['name_fr'] : $cat['name_en']) ?>
          </a>
          <?php endforeach; ?>
          <a href="<?= SITE_URL ?>/listings" class="mega-link" style="color:#00A878;margin-top:4px;"><span class="icon">→</span><?= t('All Categories','Toutes les catégories') ?></a>
        </div>
        <div class="mega-col">
          <div class="mega-col-title"><?= t('By Location','Par Ville') ?></div>
          <?php foreach(array_slice($locs, 0, 6) as $loc): ?>
          <a href="<?= SITE_URL ?>/location/<?= e($loc['slug'] ?? strtolower(str_replace(' ','-',$loc['name_en']))) ?>" class="mega-link">
            <span class="icon">📍</span>
            <?= e($loc['name_en']) ?>
          </a>
          <?php endforeach; ?>
          <a href="<?= SITE_URL ?>/listings" class="mega-link" style="color:#00A878;margin-top:4px;"><span class="icon">→</span><?= t('All Locations','Toutes les villes') ?></a>
        </div>
      </div>
    </div>

    <!-- Businesses -->
    <div class="nav-item">
      <button class="nav-link">🏪 <?= t('Businesses','Entreprises') ?> <span class="caret">▾</span></button>
      <div class="mega-menu" style="min-width:400px;">
        <div class="mega-col">
          <div class="mega-col-title"><?= t('Browse','Parcourir') ?></div>
          <a href="<?= SITE_URL ?>/listings" class="mega-link"><span class="icon">🏪</span><?= t('All Businesses','Toutes les entreprises') ?></a>
          <a href="<?= SITE_URL ?>/listings?sort=featured" class="mega-link"><span class="icon">⭐</span><?= t('Featured Businesses','Entreprises Vedettes') ?></a>
          <a href="<?= SITE_URL ?>/listings?verified=1" class="mega-link"><span class="icon">✅</span><?= t('Verified Businesses','Entreprises Vérifiées') ?></a>
        </div>
        <div class="mega-col">
          <div class="mega-col-title"><?= t('Filter','Filtrer') ?></div>
          <a href="<?= SITE_URL ?>/listings" class="mega-link"><span class="icon">📂</span><?= t('By Category','Par catégorie') ?></a>
          <a href="<?= SITE_URL ?>/listings" class="mega-link"><span class="icon">📍</span><?= t('By City','Par ville') ?></a>
          <a href="<?= SITE_URL ?>/listings?sort=reviews" class="mega-link"><span class="icon">⭐</span><?= t('Top Rated','Mieux notées') ?></a>
        </div>
      </div>
    </div>

    <!-- Services -->
    <div class="nav-item">
      <button class="nav-link">🛠️ <?= t('Services','Services') ?> <span class="caret">▾</span></button>
      <div class="mega-menu" style="min-width:380px;">
        <div class="mega-col">
          <div class="mega-col-title"><?= t('Browse Services','Parcourir les services') ?></div>
          <a href="<?= SITE_URL ?>/services" class="mega-link"><span class="icon">🛠️</span><?= t('All Services','Tous les services') ?></a>
          <?php foreach(array_slice($cats, 0, 5) as $cat): ?>
          <a href="<?= SITE_URL ?>/category/<?= e($cat['slug'] ?? '') ?>" class="mega-link">
            <span class="icon"><?= $cat['icon'] ?></span><?= e($isFr ? $cat['name_fr'] : $cat['name_en']) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Reviews -->
    <div class="nav-item">
      <button class="nav-link">⭐ <?= t('Reviews','Avis') ?> <span class="caret">▾</span></button>
      <div class="mega-menu" style="min-width:320px;">
        <div class="mega-col">
          <div class="mega-col-title"><?= t('Customer Reviews','Avis clients') ?></div>
          <a href="<?= SITE_URL ?>/reviews" class="mega-link"><span class="icon">⭐</span><?= t('Latest Reviews','Derniers avis') ?></a>
          <a href="<?= SITE_URL ?>/reviews?sort=top" class="mega-link"><span class="icon">🏆</span><?= t('Top Rated','Mieux notées') ?></a>
          <a href="<?= SITE_URL ?>/reviews?action=write" class="mega-link" style="color:#fcd116;"><span class="icon">✍️</span><?= t('Write a Review','Écrire un avis') ?></a>
        </div>
      </div>
    </div>

    <!-- Business Hub -->
    <div class="nav-item">
      <button class="nav-link">💼 <?= t('Business Hub','Business Hub') ?> <span class="caret">▾</span></button>
      <div class="mega-menu" style="min-width:560px;">
        <div class="mega-col">
          <div class="mega-col-title"><?= t('Get Started','Commencer') ?></div>
          <a href="<?= SITE_URL ?>/business-listing" class="mega-link"><span class="icon">💡</span><span><?= t('Why 237Biz?','Pourquoi 237Biz ?') ?><small><?= t('See how we help businesses','Comment nous aidons les entreprises') ?></small></span></a>
          <a href="<?= SITE_URL ?>/add-listing" class="mega-link"><span class="icon">➕</span><span><?= t('List Your Business','Lister votre Entreprise') ?><small><?= t('Free — takes 5 minutes','Gratuit — 5 minutes') ?></small></span></a>
          <a href="<?= SITE_URL ?>/claim-listing" class="mega-link"><span class="icon">🏴</span><span><?= t('Claim Your Business','Revendiquer votre Entreprise') ?><small><?= t('Already listed? Take ownership','Déjà listée ? Prenez le contrôle') ?></small></span></a>
        </div>
        <div class="mega-col">
          <div class="mega-col-title"><?= t('Grow Your Business','Développez-vous') ?></div>
          <a href="<?= SITE_URL ?>/business-listing#profile"  class="mega-link"><span class="icon">👤</span><?= t('Business Profile','Profil d\'entreprise') ?></a>
          <a href="<?= SITE_URL ?>/business-listing#reviews"  class="mega-link"><span class="icon">⭐</span><?= t('Reviews & Reputation','Avis & Réputation') ?></a>
          <a href="<?= SITE_URL ?>/business-listing#booking"  class="mega-link"><span class="icon">📅</span><?= t('Appointment Booking','Prise de rendez-vous') ?></a>
          <a href="<?= SITE_URL ?>/online-presence"           class="mega-link"><span class="icon">🌐</span><?= t('Website & Online Presence','Site web & Présence') ?></a>
          <a href="<?= SITE_URL ?>/business-listing#marketing" class="mega-link"><span class="icon">📣</span><?= t('Marketing','Marketing') ?></a>
          <a href="<?= SITE_URL ?>/business-listing#support"  class="mega-link"><span class="icon">🎧</span><?= t('SupportDesk','SupportDesk') ?></a>
        </div>
      </div>
    </div>

    <!-- Agents & Creators -->
    <div class="nav-item">
      <button class="nav-link" style="color:#fcd116;">🤝 <?= t('Partners','Partenaires') ?> <span class="caret" style="opacity:.7;">▾</span></button>
      <div class="mega-menu align-right" style="min-width:560px;">
        <div class="mega-col">
          <div class="mega-col-title" style="color:#8ab4f8;">👔 <?= t('Sales Agents','Agents de Vente') ?></div>
          <a href="<?= SITE_URL ?>/partners#agents" class="mega-link"><span class="icon">💰</span><span><?= t('Earn Commission','Gagner des commissions') ?><small><?= t('Get paid per business referral','Payé par parrainage') ?></small></span></a>
          <a href="<?= SITE_URL ?>/partners#agents" class="mega-link"><span class="icon">📋</span><span><?= t('How It Works','Comment ça marche') ?><small><?= t('Refer → Track → Earn','Parrainer → Suivre → Gagner') ?></small></span></a>
          <a href="<?= SITE_URL ?>/join?path=agent" class="mega-link" style="color:#8ab4f8;"><span class="icon">→</span><?= t('Become a Sales Agent','Devenir Agent de Vente') ?></a>
          <?php if ($cuRole === 'sales_staff'): ?>
          <a href="<?= SITE_URL ?>/agent/dashboard" class="mega-link" style="color:#00A878;"><span class="icon">📊</span><?= t('Agent Dashboard','Tableau Agent') ?></a>
          <?php else: ?>
          <a href="<?= SITE_URL ?>/login" class="mega-link"><span class="icon">🔐</span><?= t('Agent Login','Connexion Agent') ?></a>
          <?php endif; ?>
        </div>
        <div class="mega-col">
          <div class="mega-col-title" style="color:#e07be0;">🎬 <?= t('Content Creators','Créateurs de Contenu') ?></div>
          <a href="<?= SITE_URL ?>/partners#creators" class="mega-link"><span class="icon">🎁</span><span><?= t('Earn Rewards','Gagner des récompenses') ?><small><?= t('Share content, earn per signup','Partagez, gagnez par inscription') ?></small></span></a>
          <a href="<?= SITE_URL ?>/partners#creators" class="mega-link"><span class="icon">🔗</span><span><?= t('Unique Referral Link','Lien de parrainage unique') ?><small><?= t('Track every click & conversion','Suivez chaque clic') ?></small></span></a>
          <a href="<?= SITE_URL ?>/join?path=creator" class="mega-link" style="color:#e07be0;"><span class="icon">→</span><?= t('Become a Creator','Devenir Créateur') ?></a>
          <?php if ($cuRole === 'creator'): ?>
          <a href="<?= SITE_URL ?>/creator/dashboard" class="mega-link" style="color:#00A878;"><span class="icon">📊</span><?= t('Creator Dashboard','Tableau Créateur') ?></a>
          <?php else: ?>
          <a href="<?= SITE_URL ?>/login" class="mega-link"><span class="icon">🔐</span><?= t('Creator Login','Connexion Créateur') ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div><!-- /nav-main -->

  <!-- Utilities -->
  <div class="nav-utils">
    <div class="lang-toggle">
      <a href="?lang=en" class="lang-btn <?= !$isFr?'active':'' ?>">EN</a>
      <a href="?lang=fr" class="lang-btn <?= $isFr?'active':'' ?>">FR</a>
    </div>

    <?php if ($cu): ?>
    <div class="nav-user-menu">
      <button type="button" class="nav-user-btn" onclick="this.closest('.nav-user-menu').classList.toggle('open')" aria-expanded="false">
        👤 <?= e(explode(' ',$cu['name'])[0]) ?> ▾
      </button>
      <div class="nav-user-dropdown">
        <?php if ($cuRole === 'admin'): ?>
        <a href="<?= SITE_URL ?>/admin/" class="nav-dd-item">🔧 <?= t('Admin Panel','Panneau Admin') ?></a>
        <div class="nav-dd-sep"></div>
        <?php elseif ($cuRole === 'sales_staff'): ?>
        <a href="<?= SITE_URL ?>/agent/dashboard" class="nav-dd-item" style="color:#8ab4f8;">👔 <?= t('Agent Dashboard','Tableau Agent') ?></a>
        <div class="nav-dd-sep"></div>
        <?php elseif ($cuRole === 'growth_partner'): ?>
        <a href="<?= SITE_URL ?>/partner/dashboard" class="nav-dd-item" style="color:#00A878;">🤝 <?= t('Partner Centre','Centre Partenaire') ?></a>
        <div class="nav-dd-sep"></div>
        <?php elseif ($cuRole === 'creator'): ?>
        <a href="<?= SITE_URL ?>/creator/dashboard" class="nav-dd-item" style="color:#e07be0;">🎬 <?= t('Creator Dashboard','Tableau Créateur') ?></a>
        <a href="<?= SITE_URL ?>/creator/referrals"  class="nav-dd-item">🔗 <?= t('My Referrals','Mes Parrainages') ?></a>
        <a href="<?= SITE_URL ?>/creator/campaigns"  class="nav-dd-item">📣 <?= t('Campaigns','Campagnes') ?></a>
        <div class="nav-dd-sep"></div>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/dashboard"             class="nav-dd-item">🏠 <?= t('Dashboard','Tableau de bord') ?></a>
        <a href="<?= SITE_URL ?>/analytics"             class="nav-dd-item">📊 <?= t('Analytics','Analytiques') ?></a>
        <a href="<?= SITE_URL ?>/manage-announcements"  class="nav-dd-item">📢 <?= t('Announcements','Annonces') ?></a>
        <a href="<?= SITE_URL ?>/manage-bookings"       class="nav-dd-item">📅 <?= t('Bookings','Réservations') ?></a>
        <?php if (in_array($cuRole,['sales_staff','admin'])): ?>
        <div class="nav-dd-sep"></div>
        <a href="<?= SITE_URL ?>/agent-health-checks.php" class="nav-dd-item">🩺 <?= t('Digital Health Check','Bilan Numérique') ?></a>
        <?php endif; ?>
        <div class="nav-dd-sep"></div>
        <a href="<?= SITE_URL ?>/add-listing" class="nav-dd-item">➕ <?= t('Add Listing','Ajouter une annonce') ?></a>
        <div class="nav-dd-sep"></div>
        <a href="<?= SITE_URL ?>/logout" class="nav-dd-item danger">🚪 <?= t('Sign Out','Déconnexion') ?></a>
      </div>
    </div>
    <?php else: ?>
    <a href="<?= SITE_URL ?>/login" class="nav-signin"><?= t('Sign In','Connexion') ?></a>
    <a href="<?= SITE_URL ?>/join"  class="btn-join">+ <?= t('Join 237Biz','Rejoindre 237Biz') ?></a>
    <?php endif; ?>

    <button class="nav-mobile-toggle" onclick="document.getElementById('navbar').classList.toggle('open')">☰</button>
  </div>

</nav>
<script>
// Close user dropdown when clicking outside
document.addEventListener('click', function(e) {
  if (!e.target.closest('.nav-user-menu')) {
    document.querySelectorAll('.nav-user-menu.open').forEach(function(m) {
      m.classList.remove('open');
    });
  }
});
</script>

<!-- Navbar spacer -->
<div style="height:64px;"></div>

<?php
$flashSuccess = flash('success');
$flashError   = flash('error');
if ($flashSuccess): ?>
<div class="flash flash-success"><?= e($flashSuccess) ?></div>
<?php endif;
if ($flashError): ?>
<div class="flash flash-error"><?= e($flashError) ?></div>
<?php endif; ?>
