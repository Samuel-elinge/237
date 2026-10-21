<?php
// includes/header.php — 237Biz Business Hub
// Navigation v2 — Mobile-first redesign (21 Oct 2026)
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
<meta property="og:image"       content="<?= isset($pageImage) ? e($pageImage) : SITE_URL.'/assets/images/og-image.jpg' ?>">
<meta property="og:locale"      content="<?= lang() === 'fr' ? 'fr_CM' : 'en_CM' ?>">
<meta property="og:site_name"   content="237Biz">

<!-- Twitter / X Card -->
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="<?= e($pageTitle ?? '237Biz — Cameroon\'s Business Hub') ?>">
<meta name="twitter:description" content="<?= e($pageDesc ?? t('Discover, connect and grow with Cameroon\'s Business Hub.','Le Hub des Entreprises du Cameroun.')) ?>">
<meta name="twitter:image"       content="<?= isset($pageImage) ? e($pageImage) : SITE_URL.'/assets/images/og-image.jpg' ?>">

<!-- Canonical -->
<link rel="canonical" href="<?= e(SITE_URL . strtok($_SERVER['REQUEST_URI'] ?? '/', '?')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,700;0,9..144,900&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">
<?= $extraHead ?? '' ?>

<style>
/* ════════════════════════════════════════════
   NAVBAR — v2 Mobile-First
════════════════════════════════════════════ */

/* ── Base bar ── */
#navbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 900;
  background: rgba(8,28,16,0.97);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(255,255,255,0.07);
  height: 60px;
  display: flex; align-items: center;
  padding: 0 20px;
  gap: 0;
  position: relative;
}

/* ── Brand ── */
.nav-brand {
  text-decoration: none; flex-shrink: 0;
  display: flex; align-items: baseline; gap: 1px;
  position: relative; z-index: 1;
}
.brand-num  { font-family:'Fraunces',serif; font-weight:900; font-size:1.45rem; color:#fcd116; }
.brand-word { font-family:'Fraunces',serif; font-weight:900; font-size:1.45rem; color:#fff; }
.brand-tld  { font-size:0.6rem; color:rgba(255,255,255,0.4); margin-left:1px; }

/* ── Desktop nav (> 960px) — centred ── */
.nav-desktop {
  display: flex; align-items: center; gap: 2px;
  position: absolute; left: 50%; transform: translateX(-50%);
}

.nav-item  { position: relative; }
.nav-link  {
  display: flex; align-items: center; gap: 4px;
  padding: 0 11px; height: 60px;
  font-size: 13.5px; font-weight: 500; color: rgba(255,255,255,0.75);
  text-decoration: none; white-space: nowrap; cursor: pointer;
  transition: color .15s; background: none; border: none; font-family: inherit;
}
.nav-link:hover, .nav-item:hover > .nav-link { color: #fff; }
.nav-link .caret { font-size: 9px; opacity: .5; transition: transform .2s; }
.nav-item:hover > .nav-link .caret { transform: rotate(180deg); }

/* ── Mega menu ── */
.mega-menu {
  display: none; position: absolute; top: 60px; left: 50%;
  transform: translateX(-50%);
  background: #0a1f10;
  border: 1px solid rgba(255,255,255,0.1);
  border-top: 2px solid #00A878;
  border-radius: 0 0 14px 14px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.5);
  padding: 22px;
  min-width: 460px;
  z-index: 901;
}
.nav-item:hover .mega-menu { display: flex; }
.mega-menu.align-left  { left: 0; transform: none; }
.mega-menu.align-right { left: auto; right: 0; transform: none; }

.mega-col { flex: 1; min-width: 150px; }
.mega-col + .mega-col { border-left: 1px solid rgba(255,255,255,0.06); padding-left: 18px; margin-left: 4px; }
.mega-col-title {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: #00A878; margin-bottom: 10px;
}
.mega-link {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 10px; border-radius: 8px;
  font-size: 13px; color: rgba(255,255,255,0.72);
  text-decoration: none; transition: background .12s, color .12s;
  white-space: nowrap;
}
.mega-link:hover { background: rgba(255,255,255,0.06); color: #fff; }
.mega-link .icon { font-size: 14px; width: 20px; text-align: center; flex-shrink: 0; }
.mega-link small { display: block; font-size: 11px; color: rgba(255,255,255,0.38); margin-top: 1px; line-height: 1.2; }

/* ── Desktop right utilities ── */
.nav-utils {
  display: flex; align-items: center; gap: 8px; flex-shrink: 0; margin-left: auto; position: relative; z-index: 1;
}
.lang-toggle { display: flex; gap: 0; border-radius: 7px; overflow: hidden; border: 1px solid rgba(255,255,255,0.12); }
.lang-btn {
  padding: 5px 10px; font-size: 12px; font-weight: 700;
  color: rgba(255,255,255,0.55); text-decoration: none; transition: all .15s;
}
.lang-btn.active { background: rgba(0,168,120,0.25); color: #00A878; }

.nav-signin {
  padding: 6px 14px; font-size: 13px; font-weight: 600;
  color: rgba(255,255,255,0.75); text-decoration: none;
  border-radius: 8px; transition: color .15s;
}
.nav-signin:hover { color: #fff; }
.btn-list-biz {
  padding: 7px 16px; font-size: 13px; font-weight: 700;
  background: #fcd116; color: #0A1A0F; border-radius: 8px;
  text-decoration: none; white-space: nowrap; transition: background .15s;
}
.btn-list-biz:hover { background: #ffe44d; }

/* ── Notification Bell ── */
.nav-bell {
  position: relative;
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px;
  border-radius: 8px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.1);
  color: rgba(255,255,255,0.75);
  text-decoration: none; font-size: 16px;
  transition: background .15s, color .15s;
  flex-shrink: 0;
}
.nav-bell:hover { background: rgba(255,255,255,0.1); color: #fff; }
.nav-bell-badge {
  position: absolute; top: -5px; right: -5px;
  min-width: 16px; height: 16px;
  background: #e63946; color: #fff;
  font-size: 9.5px; font-weight: 800; font-family: inherit;
  border-radius: 99px; padding: 0 3px;
  display: flex; align-items: center; justify-content: center;
  border: 1.5px solid #081410;
  pointer-events: none;
}

/* ── User dropdown ── */
.nav-user-menu   { position: relative; }
.nav-user-btn    {
  display: flex; align-items: center; gap: 5px;
  padding: 6px 13px; border-radius: 8px;
  background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
  color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
  text-decoration: none; transition: background .15s;
}
.nav-user-btn:hover { background: rgba(255,255,255,0.1); }
.nav-user-dropdown {
  display: none; position: absolute; top: calc(100% + 4px); right: 0;
  background: #0a1f10; border: 1px solid rgba(255,255,255,0.1);
  border-radius: 12px; min-width: 210px; padding: 6px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.4); z-index: 902;
}
.nav-user-menu::after {
  content: ''; position: absolute; top: 100%; right: 0;
  width: 100%; height: 10px;
}
.nav-user-menu:hover .nav-user-dropdown,
.nav-user-menu.open  .nav-user-dropdown { display: block; }
.nav-dd-item {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 12px; border-radius: 8px; font-size: 13px;
  color: rgba(255,255,255,0.75); text-decoration: none; transition: background .12s;
}
.nav-dd-item:hover { background: rgba(255,255,255,0.06); color: #fff; }
.nav-dd-sep { height: 1px; background: rgba(255,255,255,0.07); margin: 4px 6px; }
.nav-dd-item.danger { color: #ff6b7a; }
.nav-dd-item.danger:hover { background: rgba(230,57,70,0.1); }

/* ── Mobile hamburger toggle ── */
.nav-hamburger {
  display: none;
  background: none; border: none; color: #fff;
  font-size: 1.4rem; cursor: pointer; padding: 6px 8px;
  margin-left: auto;
  line-height: 1;
  border-radius: 8px;
  transition: background .15s;
}
.nav-hamburger:hover { background: rgba(255,255,255,0.08); }

/* ── Impersonation banner ── */
.impersonation-bar {
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 9999;
  background: #1a1a2e; border-top: 2px solid #8ab4f8;
  padding: 10px 20px; display: flex; align-items: center;
  justify-content: space-between; gap: 12px; font-size: 13px;
}

/* ════════════════════════════════════════════
   HAMBURGER DRAWER OVERLAY
════════════════════════════════════════════ */
#nav-drawer {
  display: none;
  position: fixed; inset: 0; z-index: 950;
}
#nav-drawer.open { display: block; }

/* Backdrop */
.drawer-backdrop {
  position: absolute; inset: 0;
  background: rgba(0,0,0,0.55);
  backdrop-filter: blur(3px);
}

/* Panel */
.drawer-panel {
  position: absolute; top: 0; right: 0; bottom: 0;
  width: min(360px, 92vw);
  background: #07190d;
  border-left: 1px solid rgba(255,255,255,0.08);
  display: flex; flex-direction: column;
  overflow-y: auto;
  overscroll-behavior: contain;
}

/* Drawer header */
.drawer-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid rgba(255,255,255,0.07);
  flex-shrink: 0;
}
.drawer-brand {
  text-decoration: none;
  display: flex; align-items: baseline; gap: 1px;
}
.drawer-close {
  background: rgba(255,255,255,0.07); border: none; color: #fff;
  width: 36px; height: 36px; border-radius: 8px;
  font-size: 1.1rem; cursor: pointer; display: flex;
  align-items: center; justify-content: center;
  transition: background .15s;
}
.drawer-close:hover { background: rgba(255,255,255,0.13); }

/* Primary nav list */
.drawer-nav {
  list-style: none; margin: 0; padding: 12px 12px 4px;
  flex-shrink: 0;
}
.drawer-nav li + li { margin-top: 2px; }

.drawer-nav-item {
  display: flex; align-items: center; gap: 14px;
  padding: 13px 14px; border-radius: 10px;
  font-size: 15px; font-weight: 600; color: rgba(255,255,255,0.85);
  text-decoration: none; cursor: pointer;
  background: none; border: none; font-family: inherit;
  width: 100%; text-align: left;
  transition: background .13s, color .13s;
  min-height: 52px;
}
.drawer-nav-item:hover,
.drawer-nav-item.active { background: rgba(255,255,255,0.07); color: #fff; }
.drawer-nav-item .icon { font-size: 1.15rem; width: 26px; text-align: center; flex-shrink: 0; }
.drawer-nav-item .label { flex: 1; }
.drawer-nav-item .chevron { font-size: 11px; opacity: .45; transition: transform .2s; margin-left: auto; }
.drawer-nav-item.open .chevron { transform: rotate(90deg); }

/* Sub-panels inside drawer */
.drawer-sub {
  display: none;
  padding: 6px 12px 10px 52px;
  border-top: 1px solid rgba(255,255,255,0.05);
  margin: 0 12px;
  border-radius: 0 0 10px 10px;
}
.drawer-sub.open { display: block; }
.drawer-sub a {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 10px; border-radius: 8px;
  font-size: 13.5px; color: rgba(255,255,255,0.68);
  text-decoration: none; transition: background .12s, color .12s;
  min-height: 44px;
}
.drawer-sub a:hover { background: rgba(255,255,255,0.06); color: #fff; }
.drawer-sub a .icon { font-size: 13px; width: 18px; text-align: center; flex-shrink: 0; }
.drawer-sub-title {
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: #00A878;
  padding: 10px 10px 4px; margin-top: 2px;
}

/* ── List Your Business CTA ── */
.drawer-cta {
  margin: 8px 12px;
  flex-shrink: 0;
}
.drawer-cta a {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  padding: 14px; border-radius: 10px;
  background: #fcd116; color: #0A1A0F;
  font-size: 14px; font-weight: 800;
  text-decoration: none;
  transition: background .15s;
  min-height: 52px;
}
.drawer-cta a:hover { background: #ffe44d; }

/* ── Language selector in drawer ── */
.drawer-lang {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 20px;
  border-top: 1px solid rgba(255,255,255,0.07);
  flex-shrink: 0;
}
.drawer-lang span {
  font-size: 12px; color: rgba(255,255,255,0.4); font-weight: 600;
  text-transform: uppercase; letter-spacing: .07em;
}
.drawer-lang-toggle { display: flex; gap: 0; border-radius: 7px; overflow: hidden; border: 1px solid rgba(255,255,255,0.12); margin-left: auto; }
.drawer-lang a {
  padding: 6px 13px; font-size: 12px; font-weight: 700;
  color: rgba(255,255,255,0.55); text-decoration: none; transition: all .15s;
}
.drawer-lang a.active { background: rgba(0,168,120,0.25); color: #00A878; }

/* ── Footer links in drawer ── */
.drawer-footer {
  padding: 14px 20px 20px;
  border-top: 1px solid rgba(255,255,255,0.07);
  display: flex; flex-wrap: wrap; gap: 4px 16px;
  flex-shrink: 0;
}
.drawer-footer a {
  font-size: 12px; color: rgba(255,255,255,0.35);
  text-decoration: none; transition: color .12s;
  padding: 4px 0;
}
.drawer-footer a:hover { color: rgba(255,255,255,0.65); }

/* ════════════════════════════════════════════
   RESPONSIVE BREAKPOINTS
════════════════════════════════════════════ */
@media (max-width: 960px) {
  .nav-desktop { display: none; }
  .nav-hamburger { display: flex; }
  /* Hide desktop-only utilities on mobile; bell + user handled in drawer */
  .nav-utils .lang-toggle,
  .nav-utils .nav-signin,
  .nav-utils .btn-list-biz { display: none; }
  /* Keep bell visible on mobile for logged-in users */
  .nav-utils { margin-left: auto; }
}

@media (min-width: 961px) {
  .nav-hamburger { display: none; }
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

<!-- ══════════════════════════════════════════
     TOP BAR
══════════════════════════════════════════ -->
<nav id="navbar" role="navigation" aria-label="<?= t('Main navigation','Navigation principale') ?>">

  <!-- Brand -->
  <a href="<?= SITE_URL ?>/" class="nav-brand" aria-label="237Biz <?= t('Home','Accueil') ?>">
    <span class="brand-num">237</span><span class="brand-word">Biz</span><span class="brand-tld">.net</span>
  </a>

  <!-- ── DESKTOP NAV ── -->
  <div class="nav-desktop" aria-label="<?= t('Desktop navigation','Navigation bureau') ?>">

    <!-- Explore Businesses (consolidates Discover + Businesses + Services) -->
    <div class="nav-item">
      <button class="nav-link" aria-haspopup="true">🔍 <?= t('Explore','Explorer') ?> <span class="caret">▾</span></button>
      <div class="mega-menu align-left" style="min-width:540px;">
        <div class="mega-col">
          <div class="mega-col-title"><?= t('Browse','Parcourir') ?></div>
          <a href="<?= SITE_URL ?>/listings" class="mega-link"><span class="icon">🏪</span><span><?= t('All Businesses','Toutes les Entreprises') ?><small><?= t('Full directory','Répertoire complet') ?></small></span></a>
          <a href="<?= SITE_URL ?>/listings?sort=featured" class="mega-link"><span class="icon">⭐</span><span><?= t('Featured','Vedettes') ?><small><?= t('Top verified listings','Annonces vérifiées') ?></small></span></a>
          <a href="<?= SITE_URL ?>/listings?verified=1" class="mega-link"><span class="icon">✅</span><span><?= t('Verified Businesses','Entreprises Vérifiées') ?></span></a>
          <a href="<?= SITE_URL ?>/services" class="mega-link"><span class="icon">🛠️</span><span><?= t('Services','Services') ?><small><?= t('Bookable services','Services réservables') ?></small></span></a>
        </div>
        <div class="mega-col">
          <div class="mega-col-title"><?= t('By Category','Par Catégorie') ?></div>
          <?php foreach(array_slice($cats, 0, 6) as $cat): ?>
          <a href="<?= SITE_URL ?>/category/<?= e($cat['slug'] ?? strtolower(str_replace(' ','-',$cat['name_en']))) ?>" class="mega-link">
            <span class="icon"><?= $cat['icon'] ?></span>
            <?= e($isFr ? $cat['name_fr'] : $cat['name_en']) ?>
          </a>
          <?php endforeach; ?>
          <a href="<?= SITE_URL ?>/listings" class="mega-link" style="color:#00A878;margin-top:4px;"><span class="icon">→</span><?= t('All Categories','Toutes catégories') ?></a>
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

    <!-- Bookings -->
    <div class="nav-item">
      <a href="<?= SITE_URL ?>/booking" class="nav-link">📅 <?= t('Bookings','Réservations') ?></a>
    </div>

    <!-- Offers & Events -->
    <div class="nav-item">
      <button class="nav-link" aria-haspopup="true">🎁 <?= t('Offers & Events','Offres & Événements') ?> <span class="caret">▾</span></button>
      <div class="mega-menu" style="min-width:340px;">
        <div class="mega-col">
          <div class="mega-col-title"><?= t('Discover Deals','Découvrir') ?></div>
          <a href="<?= SITE_URL ?>/offers" class="mega-link"><span class="icon">🎁</span><span><?= t('Special Offers','Offres Spéciales') ?><small><?= t('Active deals from businesses','Offres actives') ?></small></span></a>
          <a href="<?= SITE_URL ?>/events" class="mega-link"><span class="icon">📅</span><span><?= t('Events','Événements') ?><small><?= t('Upcoming events near you','Événements à venir') ?></small></span></a>
          <a href="<?= SITE_URL ?>/marketplace" class="mega-link"><span class="icon">🛒</span><span><?= t('Marketplace','Marché') ?><small><?= t('Products & promotions','Produits & promotions') ?></small></span></a>
        </div>
      </div>
    </div>

    <!-- Partner Network (Sales Agents + Creators + Growth Partners) -->
    <div class="nav-item">
      <button class="nav-link" style="color:#fcd116;" aria-haspopup="true">🤝 <?= t('Partner Network','Réseau Partenaires') ?> <span class="caret" style="opacity:.7;">▾</span></button>
      <div class="mega-menu align-right" style="min-width:600px;">
        <div class="mega-col">
          <div class="mega-col-title" style="color:#8ab4f8;">👔 <?= t('Sales Agents','Agents de Vente') ?></div>
          <a href="<?= SITE_URL ?>/partners#agents" class="mega-link"><span class="icon">💰</span><span><?= t('Earn Commission','Gagner des commissions') ?><small><?= t('Get paid per business referral','Payé par parrainage') ?></small></span></a>
          <a href="<?= SITE_URL ?>/join?path=agent" class="mega-link" style="color:#8ab4f8;"><span class="icon">→</span><?= t('Become a Sales Agent','Devenir Agent de Vente') ?></a>
          <?php if ($cuRole === 'sales_staff'): ?>
          <a href="<?= SITE_URL ?>/agent/dashboard" class="mega-link" style="color:#00A878;"><span class="icon">📊</span><?= t('Agent Dashboard','Tableau Agent') ?></a>
          <?php endif; ?>
        </div>
        <div class="mega-col">
          <div class="mega-col-title" style="color:#e07be0;">🎬 <?= t('Content Creators','Créateurs') ?></div>
          <a href="<?= SITE_URL ?>/partners#creators" class="mega-link"><span class="icon">🎁</span><span><?= t('Earn Rewards','Gagner des récompenses') ?><small><?= t('Share content, earn per signup','Partagez, gagnez par inscription') ?></small></span></a>
          <a href="<?= SITE_URL ?>/join?path=creator" class="mega-link" style="color:#e07be0;"><span class="icon">→</span><?= t('Become a Creator','Devenir Créateur') ?></a>
          <?php if ($cuRole === 'creator'): ?>
          <a href="<?= SITE_URL ?>/creator/dashboard" class="mega-link" style="color:#00A878;"><span class="icon">📊</span><?= t('Creator Dashboard','Tableau Créateur') ?></a>
          <?php endif; ?>
        </div>
        <div class="mega-col">
          <div class="mega-col-title" style="color:#00A878;">🤝 <?= t('Growth Partners','Partenaires Croissance') ?></div>
          <a href="<?= SITE_URL ?>/partners" class="mega-link"><span class="icon">📈</span><span><?= t('Growth Partner Programme','Programme Partenaire Croissance') ?><small><?= t('Help businesses grow, earn together','Aidez les entreprises à croître') ?></small></span></a>
          <a href="<?= SITE_URL ?>/join?path=partner" class="mega-link" style="color:#00A878;"><span class="icon">→</span><?= t('Apply as Growth Partner','Devenir Partenaire Croissance') ?></a>
          <?php if ($cuRole === 'growth_partner'): ?>
          <a href="<?= SITE_URL ?>/partner/dashboard" class="mega-link" style="color:#00A878;"><span class="icon">📊</span><?= t('Partner Centre','Centre Partenaire') ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div><!-- /nav-desktop -->

  <!-- ── DESKTOP RIGHT UTILITIES ── -->
  <div class="nav-utils">
    <div class="lang-toggle">
      <a href="?lang=en" class="lang-btn <?= !$isFr?'active':'' ?>">EN</a>
      <a href="?lang=fr" class="lang-btn <?= $isFr?'active':'' ?>">FR</a>
    </div>

    <?php if ($cu): ?>
    <?php
    $_bellCount = 0;
    try {
        $_bs = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND channel='bell' AND is_read=0");
        $_bs->execute([(int)$cu['id']]);
        $_bellCount = (int)$_bs->fetchColumn();
    } catch (Exception $e) {}
    ?>
    <a href="<?= SITE_URL ?>/notifications" class="nav-bell"
       title="<?= t('Notifications','Notifications') ?>"
       aria-label="<?= t('Notifications','Notifications') ?><?= $_bellCount ? " ({$_bellCount} " . t('unread','non lues') . ')' : '' ?>">
      <span>🔔</span>
      <?php if ($_bellCount): ?>
      <span class="nav-bell-badge"><?= $_bellCount > 9 ? '9+' : $_bellCount ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-user-menu">
      <button type="button" class="nav-user-btn" onclick="this.closest('.nav-user-menu').classList.toggle('open')" aria-expanded="false">
        👤 <?= e(explode(' ',$cu['name'])[0]) ?> ▾
      </button>
      <div class="nav-user-dropdown">
        <?php if ($cuRole === 'admin'): ?>
        <a href="<?= SITE_URL ?>/admin/" class="nav-dd-item">🔧 <?= t('Admin Panel','Panneau Admin') ?></a>
        <a href="<?= SITE_URL ?>/admin/blog.php" class="nav-dd-item">📝 <?= t('Blog','Blog') ?></a>
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
        <a href="<?= SITE_URL ?>/customer-dashboard" class="nav-dd-item">👤 <?= t('My Account','Mon Compte') ?></a>
        <a href="<?= SITE_URL ?>/dashboard"           class="nav-dd-item">🏠 <?= t('Dashboard','Tableau de bord') ?></a>
        <a href="<?= SITE_URL ?>/analytics"           class="nav-dd-item">📊 <?= t('Analytics','Analytiques') ?></a>
        <a href="<?= SITE_URL ?>/manage-announcements" class="nav-dd-item">📢 <?= t('Announcements','Annonces') ?></a>
        <a href="<?= SITE_URL ?>/manage-bookings"     class="nav-dd-item">📅 <?= t('Bookings','Réservations') ?></a>
        <?php if (in_array($cuRole,['sales_staff','admin'])): ?>
        <div class="nav-dd-sep"></div>
        <a href="<?= SITE_URL ?>/agent-health-checks.php" class="nav-dd-item">🩺 <?= t('Digital Health Check','Bilan Numérique') ?></a>
        <?php endif; ?>
        <div class="nav-dd-sep"></div>
        <a href="<?= SITE_URL ?>/add-listing" class="nav-dd-item">➕ <?= t('Add Listing','Ajouter une annonce') ?></a>
        <div class="nav-dd-sep"></div>
        <a href="<?= SITE_URL ?>/notifications" class="nav-dd-item">
          🔔 <?= t('Notifications','Notifications') ?>
          <?php if ($_bellCount): ?>
          <span style="margin-left:auto;background:#e63946;color:#fff;font-size:9px;font-weight:800;border-radius:99px;padding:1px 5px;"><?= $_bellCount > 9 ? '9+' : $_bellCount ?></span>
          <?php endif; ?>
        </a>
        <a href="<?= SITE_URL ?>/customer-dashboard?tab=preferences" class="nav-dd-item">⚙️ <?= t('Notification Prefs','Préférences') ?></a>
        <div class="nav-dd-sep"></div>
        <a href="<?= SITE_URL ?>/logout" class="nav-dd-item danger">🚪 <?= t('Sign Out','Déconnexion') ?></a>
      </div>
    </div>
    <?php else: ?>
    <a href="<?= SITE_URL ?>/login" class="nav-signin"><?= t('Sign In','Connexion') ?></a>
    <a href="<?= SITE_URL ?>/add-listing" class="btn-list-biz">+ <?= t('List Your Business','Lister votre Entreprise') ?></a>
    <?php endif; ?>

    <!-- Hamburger — mobile only -->
    <button class="nav-hamburger" id="nav-hamburger-btn"
            aria-label="<?= t('Open menu','Ouvrir le menu') ?>"
            aria-expanded="false"
            aria-controls="nav-drawer">☰</button>
  </div>

</nav>

<!-- ══════════════════════════════════════════
     MOBILE HAMBURGER DRAWER
══════════════════════════════════════════ -->
<div id="nav-drawer" role="dialog" aria-label="<?= t('Navigation menu','Menu de navigation') ?>" aria-modal="true">
  <div class="drawer-backdrop" id="drawer-backdrop"></div>
  <div class="drawer-panel">

    <!-- Drawer header -->
    <div class="drawer-head">
      <a href="<?= SITE_URL ?>/" class="drawer-brand" onclick="closeDrawer()">
        <span class="brand-num">237</span><span class="brand-word">Biz</span><span class="brand-tld">.net</span>
      </a>
      <button class="drawer-close" id="drawer-close-btn" aria-label="<?= t('Close menu','Fermer le menu') ?>">✕</button>
    </div>

    <!-- Primary nav list -->
    <ul class="drawer-nav" role="list">

      <!-- 1. Explore Businesses -->
      <li>
        <button class="drawer-nav-item" aria-expanded="false" data-target="sub-explore">
          <span class="icon">🔍</span>
          <span class="label"><?= t('Explore Businesses','Explorer les Entreprises') ?></span>
          <span class="chevron">›</span>
        </button>
        <div class="drawer-sub" id="sub-explore">
          <div class="drawer-sub-title"><?= t('Browse','Parcourir') ?></div>
          <a href="<?= SITE_URL ?>/listings" onclick="closeDrawer()"><span class="icon">🏪</span><?= t('All Businesses','Toutes les Entreprises') ?></a>
          <a href="<?= SITE_URL ?>/listings?sort=featured" onclick="closeDrawer()"><span class="icon">⭐</span><?= t('Featured','Vedettes') ?></a>
          <a href="<?= SITE_URL ?>/listings?verified=1" onclick="closeDrawer()"><span class="icon">✅</span><?= t('Verified','Vérifiées') ?></a>
          <a href="<?= SITE_URL ?>/services" onclick="closeDrawer()"><span class="icon">🛠️</span><?= t('Services','Services') ?></a>
          <?php if (!empty($cats)): ?>
          <div class="drawer-sub-title"><?= t('Categories','Catégories') ?></div>
          <?php foreach(array_slice($cats, 0, 6) as $cat): ?>
          <a href="<?= SITE_URL ?>/category/<?= e($cat['slug'] ?? strtolower(str_replace(' ','-',$cat['name_en']))) ?>" onclick="closeDrawer()">
            <span class="icon"><?= $cat['icon'] ?></span><?= e($isFr ? $cat['name_fr'] : $cat['name_en']) ?>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
          <?php if (!empty($locs)): ?>
          <div class="drawer-sub-title"><?= t('Cities','Villes') ?></div>
          <?php foreach(array_slice($locs, 0, 5) as $loc): ?>
          <a href="<?= SITE_URL ?>/location/<?= e($loc['slug'] ?? strtolower(str_replace(' ','-',$loc['name_en']))) ?>" onclick="closeDrawer()">
            <span class="icon">📍</span><?= e($loc['name_en']) ?>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </li>

      <!-- 2. Bookings -->
      <li>
        <a href="<?= SITE_URL ?>/booking" class="drawer-nav-item" onclick="closeDrawer()">
          <span class="icon">📅</span>
          <span class="label"><?= t('Bookings','Réservations') ?></span>
        </a>
      </li>

      <!-- 3. Offers & Events -->
      <li>
        <button class="drawer-nav-item" aria-expanded="false" data-target="sub-offers">
          <span class="icon">🎁</span>
          <span class="label"><?= t('Offers & Events','Offres & Événements') ?></span>
          <span class="chevron">›</span>
        </button>
        <div class="drawer-sub" id="sub-offers">
          <a href="<?= SITE_URL ?>/offers" onclick="closeDrawer()"><span class="icon">🎁</span><?= t('Special Offers','Offres Spéciales') ?></a>
          <a href="<?= SITE_URL ?>/events" onclick="closeDrawer()"><span class="icon">📅</span><?= t('Events','Événements') ?></a>
          <a href="<?= SITE_URL ?>/marketplace" onclick="closeDrawer()"><span class="icon">🛒</span><?= t('Marketplace','Marché') ?></a>
        </div>
      </li>

      <!-- 4. Partner Network -->
      <li>
        <button class="drawer-nav-item" aria-expanded="false" data-target="sub-partners">
          <span class="icon">🤝</span>
          <span class="label"><?= t('Partner Network','Réseau Partenaires') ?></span>
          <span class="chevron">›</span>
        </button>
        <div class="drawer-sub" id="sub-partners">
          <div class="drawer-sub-title" style="color:#8ab4f8;">👔 <?= t('Sales Agents','Agents de Vente') ?></div>
          <a href="<?= SITE_URL ?>/partners#agents" onclick="closeDrawer()"><span class="icon">💰</span><?= t('Earn Commission','Gagner des commissions') ?></a>
          <a href="<?= SITE_URL ?>/join?path=agent" onclick="closeDrawer()" style="color:#8ab4f8;"><span class="icon">→</span><?= t('Become a Sales Agent','Devenir Agent de Vente') ?></a>
          <?php if ($cuRole === 'sales_staff'): ?>
          <a href="<?= SITE_URL ?>/agent/dashboard" onclick="closeDrawer()" style="color:#00A878;"><span class="icon">📊</span><?= t('Agent Dashboard','Tableau Agent') ?></a>
          <?php endif; ?>

          <div class="drawer-sub-title" style="color:#e07be0;">🎬 <?= t('Content Creators','Créateurs') ?></div>
          <a href="<?= SITE_URL ?>/partners#creators" onclick="closeDrawer()"><span class="icon">🎁</span><?= t('Earn Rewards','Gagner des récompenses') ?></a>
          <a href="<?= SITE_URL ?>/join?path=creator" onclick="closeDrawer()" style="color:#e07be0;"><span class="icon">→</span><?= t('Become a Creator','Devenir Créateur') ?></a>
          <?php if ($cuRole === 'creator'): ?>
          <a href="<?= SITE_URL ?>/creator/dashboard" onclick="closeDrawer()" style="color:#00A878;"><span class="icon">📊</span><?= t('Creator Dashboard','Tableau Créateur') ?></a>
          <?php endif; ?>

          <div class="drawer-sub-title" style="color:#00A878;">🤝 <?= t('Growth Partners','Partenaires Croissance') ?></div>
          <a href="<?= SITE_URL ?>/partners" onclick="closeDrawer()"><span class="icon">📈</span><?= t('Growth Partner Programme','Programme Partenaire') ?></a>
          <a href="<?= SITE_URL ?>/join?path=partner" onclick="closeDrawer()" style="color:#00A878;"><span class="icon">→</span><?= t('Apply as Growth Partner','Devenir Partenaire Croissance') ?></a>
          <?php if ($cuRole === 'growth_partner'): ?>
          <a href="<?= SITE_URL ?>/partner/dashboard" onclick="closeDrawer()" style="color:#00A878;"><span class="icon">📊</span><?= t('Partner Centre','Centre Partenaire') ?></a>
          <?php endif; ?>
        </div>
      </li>

      <!-- 5. My Account -->
      <li>
        <?php if ($cu): ?>
        <button class="drawer-nav-item" aria-expanded="false" data-target="sub-account">
          <span class="icon">👤</span>
          <span class="label"><?= e(explode(' ',$cu['name'])[0]) ?></span>
          <span class="chevron">›</span>
        </button>
        <div class="drawer-sub" id="sub-account">
          <?php if ($cuRole === 'admin'): ?>
          <a href="<?= SITE_URL ?>/admin/" onclick="closeDrawer()"><span class="icon">🔧</span><?= t('Admin Panel','Panneau Admin') ?></a>
          <a href="<?= SITE_URL ?>/admin/blog.php" onclick="closeDrawer()"><span class="icon">📝</span><?= t('Blog','Blog') ?></a>
          <?php elseif ($cuRole === 'sales_staff'): ?>
          <a href="<?= SITE_URL ?>/agent/dashboard" onclick="closeDrawer()" style="color:#8ab4f8;"><span class="icon">👔</span><?= t('Agent Dashboard','Tableau Agent') ?></a>
          <?php elseif ($cuRole === 'growth_partner'): ?>
          <a href="<?= SITE_URL ?>/partner/dashboard" onclick="closeDrawer()" style="color:#00A878;"><span class="icon">🤝</span><?= t('Partner Centre','Centre Partenaire') ?></a>
          <?php elseif ($cuRole === 'creator'): ?>
          <a href="<?= SITE_URL ?>/creator/dashboard" onclick="closeDrawer()" style="color:#e07be0;"><span class="icon">🎬</span><?= t('Creator Dashboard','Tableau Créateur') ?></a>
          <?php endif; ?>
          <div class="drawer-sub-title"><?= t('Account','Compte') ?></div>
          <a href="<?= SITE_URL ?>/customer-dashboard" onclick="closeDrawer()"><span class="icon">👤</span><?= t('My Account','Mon Compte') ?></a>
          <a href="<?= SITE_URL ?>/dashboard" onclick="closeDrawer()"><span class="icon">🏠</span><?= t('Dashboard','Tableau de bord') ?></a>
          <a href="<?= SITE_URL ?>/manage-bookings" onclick="closeDrawer()"><span class="icon">📅</span><?= t('Bookings','Réservations') ?></a>
          <a href="<?= SITE_URL ?>/notifications" onclick="closeDrawer()">
            <span class="icon">🔔</span><?= t('Notifications','Notifications') ?>
            <?php if (!empty($_bellCount) && $_bellCount > 0): ?>
            <span style="margin-left:auto;background:#e63946;color:#fff;font-size:9px;font-weight:800;border-radius:99px;padding:1px 5px;"><?= $_bellCount > 9 ? '9+' : $_bellCount ?></span>
            <?php endif; ?>
          </a>
          <div class="drawer-sub-title"><?= t('Quick Actions','Actions rapides') ?></div>
          <a href="<?= SITE_URL ?>/add-listing" onclick="closeDrawer()"><span class="icon">➕</span><?= t('Add Listing','Ajouter une annonce') ?></a>
          <a href="<?= SITE_URL ?>/logout" onclick="closeDrawer()" style="color:#ff6b7a;"><span class="icon">🚪</span><?= t('Sign Out','Déconnexion') ?></a>
        </div>
        <?php else: ?>
        <button class="drawer-nav-item" aria-expanded="false" data-target="sub-account">
          <span class="icon">👤</span>
          <span class="label"><?= t('My Account','Mon Compte') ?></span>
          <span class="chevron">›</span>
        </button>
        <div class="drawer-sub" id="sub-account">
          <a href="<?= SITE_URL ?>/login" onclick="closeDrawer()"><span class="icon">🔐</span><?= t('Sign In','Connexion') ?></a>
          <a href="<?= SITE_URL ?>/join" onclick="closeDrawer()"><span class="icon">✨</span><?= t('Create Account','Créer un compte') ?></a>
        </div>
        <?php endif; ?>
      </li>

    </ul><!-- /drawer-nav -->

    <!-- List Your Business CTA -->
    <div class="drawer-cta">
      <a href="<?= SITE_URL ?>/add-listing" onclick="closeDrawer()">
        ➕ <?= t('List Your Business','Lister votre Entreprise') ?>
      </a>
    </div>

    <!-- Language selector -->
    <div class="drawer-lang">
      <span><?= t('Language','Langue') ?></span>
      <div class="drawer-lang-toggle">
        <a href="?lang=en" class="<?= !$isFr?'active':'' ?>">EN</a>
        <a href="?lang=fr" class="<?= $isFr?'active':'' ?>">FR</a>
      </div>
    </div>

    <!-- Footer links -->
    <div class="drawer-footer">
      <a href="<?= SITE_URL ?>/help" onclick="closeDrawer()"><?= t('Help','Aide') ?></a>
      <a href="<?= SITE_URL ?>/contact" onclick="closeDrawer()"><?= t('Contact','Contact') ?></a>
      <a href="<?= SITE_URL ?>/privacy-policy" onclick="closeDrawer()"><?= t('Privacy','Confidentialité') ?></a>
      <a href="<?= SITE_URL ?>/terms" onclick="closeDrawer()"><?= t('Terms','Conditions') ?></a>
    </div>

  </div><!-- /drawer-panel -->
</div><!-- /nav-drawer -->

<script>
/* ── Navbar spacer height ── */
(function() {
  var bar = document.getElementById('navbar');
  if (bar) document.querySelector && document.documentElement.style.setProperty('--navbar-h', bar.offsetHeight + 'px');
})();

/* ── Drawer open / close ── */
var drawer = document.getElementById('nav-drawer');
var hamburgerBtn = document.getElementById('nav-hamburger-btn');
var drawerCloseBtn = document.getElementById('drawer-close-btn');
var backdrop = document.getElementById('drawer-backdrop');
var _lastFocus = null;

function openDrawer() {
  _lastFocus = document.activeElement;
  drawer.classList.add('open');
  hamburgerBtn && (hamburgerBtn.setAttribute('aria-expanded','true'));
  document.body.style.overflow = 'hidden';
  drawerCloseBtn && drawerCloseBtn.focus();
}

function closeDrawer() {
  drawer.classList.remove('open');
  hamburgerBtn && (hamburgerBtn.setAttribute('aria-expanded','false'));
  document.body.style.overflow = '';
  _lastFocus && _lastFocus.focus();
}

hamburgerBtn && hamburgerBtn.addEventListener('click', openDrawer);
drawerCloseBtn && drawerCloseBtn.addEventListener('click', closeDrawer);
backdrop && backdrop.addEventListener('click', closeDrawer);

/* Close on Escape */
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer();
});

/* Close when a direct link inside the drawer is followed (not a button toggle) */
drawer.querySelectorAll('a[href]').forEach(function(a) {
  a.addEventListener('click', closeDrawer);
});

/* ── Drawer sub-panel accordion ── */
document.querySelectorAll('.drawer-nav-item[data-target]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var targetId = btn.getAttribute('data-target');
    var sub = document.getElementById(targetId);
    var isOpen = sub && sub.classList.contains('open');
    /* Close all other subs */
    document.querySelectorAll('.drawer-sub.open').forEach(function(s) { s.classList.remove('open'); });
    document.querySelectorAll('.drawer-nav-item.open').forEach(function(b) { b.classList.remove('open'); b.setAttribute('aria-expanded','false'); });
    if (!isOpen && sub) {
      sub.classList.add('open');
      btn.classList.add('open');
      btn.setAttribute('aria-expanded','true');
    }
  });
});

/* ── Desktop: close user dropdown on outside click ── */
document.addEventListener('click', function(e) {
  if (!e.target.closest('.nav-user-menu')) {
    document.querySelectorAll('.nav-user-menu.open').forEach(function(m) {
      m.classList.remove('open');
    });
  }
});

/* ── Trap focus inside open drawer (accessibility) ── */
drawer.addEventListener('keydown', function(e) {
  if (e.key !== 'Tab' || !drawer.classList.contains('open')) return;
  var focusable = Array.from(drawer.querySelectorAll(
    'a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])'
  )).filter(function(el) { return el.offsetParent !== null; });
  if (!focusable.length) return;
  var first = focusable[0], last = focusable[focusable.length - 1];
  if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
  else            { if (document.activeElement === last)  { e.preventDefault(); first.focus(); } }
});
</script>

<!-- Navbar spacer -->
<div style="height:60px;"></div>

<?php
$flashSuccess = flash('success');
$flashError   = flash('error');
if ($flashSuccess): ?>
<div class="flash flash-success"><?= e($flashSuccess) ?></div>
<?php endif;
if ($flashError): ?>
<div class="flash flash-error"><?= e($flashError) ?></div>
<?php endif; ?>
