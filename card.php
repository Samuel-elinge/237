<?php
/**
 * card.php — 237Biz Digital Business Card
 * URL: /card/{slug}
 * Mobile-first digital business card for QR scanning.
 */
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) redirect(SITE_URL . '/listings');

// Source tracking (QR scan vs direct link vs share)
$source = trim($_GET['src'] ?? $_GET['utm_source'] ?? 'direct');
$source = preg_replace('/[^a-z0-9_-]/', '', $source);
if (!$source) $source = 'direct';

// Fetch listing with plan info
$st = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon, c.slug AS cat_slug,
           loc.name_en AS loc_en, loc.name_fr AS loc_fr, loc.slug AS loc_slug,
           COALESCE(sp.name, 'free') AS plan_name,
           COALESCE(sp.premium_star, 0) AS premium_star
    FROM listings l
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    LEFT JOIN business_subscriptions bs
        ON bs.listing_id = l.id
        AND bs.status = 'active'
        AND (bs.ends_at IS NULL OR bs.ends_at > NOW())
    LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
    WHERE l.slug = ?
");
$st->execute([$slug]);
$l = $st->fetch();

// Suspended / not found
if (!$l) {
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Business Not Found – 237biz</title>
<style>
body{margin:0;font-family:system-ui,sans-serif;background:#f4f4f4;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem}
.box{background:#fff;border-radius:16px;padding:2.5rem 2rem;text-align:center;max-width:380px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.icon{font-size:3rem;margin-bottom:1rem}
h1{margin:0 0 .5rem;font-size:1.3rem;color:#222}
p{color:#666;margin:.5rem 0 1.5rem}
a{display:inline-block;background:#00A878;color:#fff;padding:.65rem 1.5rem;border-radius:50px;text-decoration:none;font-size:.9rem;font-weight:600}
</style>
</head>
<body>
<div class="box">
  <div class="icon">🔍</div>
  <h1>Business Not Found</h1>
  <p>This business card is no longer available or may have been removed.</p>
  <a href="<?= SITE_URL ?>/listings">Browse Businesses</a>
</div>
</body>
</html>
<?php exit; }

if ($l['status'] !== 'approved') {
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Business Unavailable – 237biz</title>
<style>
body{margin:0;font-family:system-ui,sans-serif;background:#f4f4f4;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem}
.box{background:#fff;border-radius:16px;padding:2.5rem 2rem;text-align:center;max-width:380px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.icon{font-size:3rem;margin-bottom:1rem}
h1{margin:0 0 .5rem;font-size:1.3rem;color:#222}
p{color:#666;margin:.5rem 0 1.5rem}
a{display:inline-block;background:#00A878;color:#fff;padding:.65rem 1.5rem;border-radius:50px;text-decoration:none;font-size:.9rem;font-weight:600}
</style>
</head>
<body>
<div class="box">
  <div class="icon">⏸️</div>
  <h1><?= t('Business Unavailable','Entreprise Indisponible') ?></h1>
  <p><?= t('This business is currently unavailable. Please check back later.','Cette entreprise est temporairement indisponible. Veuillez réessayer plus tard.') ?></p>
  <a href="<?= SITE_URL ?>/listings"><?= t('Browse Businesses','Parcourir les Entreprises') ?></a>
</div>
</body>
</html>
<?php exit; }

// Products (up to 6)
$products = [];
try {
    $pSt = db()->prepare("SELECT * FROM listing_products WHERE listing_id = ? ORDER BY sort_order ASC, id ASC LIMIT 6");
    $pSt->execute([$l['id']]);
    $products = $pSt->fetchAll();
} catch (Exception $e) {}

// Active offers (up to 4)
$offers = [];
try {
    $oSt = db()->prepare("
        SELECT * FROM offers
        WHERE listing_id = ? AND status = 'active'
          AND (valid_until IS NULL OR valid_until >= CURDATE())
        ORDER BY created_at DESC LIMIT 4
    ");
    $oSt->execute([$l['id']]);
    $offers = $oSt->fetchAll();
} catch (Exception $e) {}

// Upcoming events (up to 3)
$events = [];
try {
    $eSt = db()->prepare("
        SELECT * FROM events
        WHERE listing_id = ? AND status IN ('active','upcoming')
          AND (ends_at IS NULL OR ends_at >= CURDATE())
        ORDER BY starts_at ASC LIMIT 3
    ");
    $eSt->execute([$l['id']]);
    $events = $eSt->fetchAll();
} catch (Exception $e) {}

// Reviews summary
$avgRating   = 0;
$reviewCount = 0;
try {
    $rSt = db()->prepare("SELECT ROUND(AVG(rating),1) AS avg, COUNT(*) AS cnt FROM reviews WHERE listing_id = ? AND status = 'approved'");
    $rSt->execute([$l['id']]);
    $rRow = $rSt->fetch();
    $avgRating   = (float)($rRow['avg'] ?? 0);
    $reviewCount = (int)($rRow['cnt'] ?? 0);
} catch (Exception $e) {}

// Format phone for WhatsApp (strip non-digits, add +237 if needed)
$rawPhone = preg_replace('/[^0-9+]/', '', $l['phone'] ?? '');
$waPhone  = '';
if ($rawPhone) {
    if (str_starts_with($rawPhone, '+')) {
        $waPhone = $rawPhone;
    } elseif (str_starts_with($rawPhone, '00')) {
        $waPhone = '+' . substr($rawPhone, 2);
    } elseif (strlen($rawPhone) === 9 && str_starts_with($rawPhone, '6')) {
        $waPhone = '+237' . $rawPhone;
    } else {
        $waPhone = $rawPhone;
    }
    $waPhone = ltrim($waPhone, '+');
}

$cardUrl  = SITE_URL . '/card/' . e($l['slug']);
$catName  = e(lang($l['cat_en'], $l['cat_fr']));
$locName  = e(lang($l['loc_en'], $l['loc_fr']));

// Validate external URLs
function safe_url(?string $url): string {
    if (!$url) return '';
    $url = trim($url);
    if (!preg_match('/^https?:\/\//i', $url)) $url = 'https://' . $url;
    return filter_var($url, FILTER_VALIDATE_URL) ? e($url) : '';
}
?><!DOCTYPE html>
<html lang="<?= lang('en','fr') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5">
<meta name="theme-color" content="#00A878">
<title><?= e($l['title']) ?> – <?= t('Digital Card','Carte Digitale') ?> | 237biz</title>
<meta name="description" content="<?= e(mb_substr(strip_tags($l['description'] ?? ''), 0, 160)) ?>">
<meta property="og:title" content="<?= e($l['title']) ?>">
<meta property="og:description" content="<?= e(mb_substr(strip_tags($l['description'] ?? ''), 0, 160)) ?>">
<?php if ($l['logo']): ?>
<meta property="og:image" content="<?= e(UPLOAD_URL . $l['logo']) ?>">
<?php endif; ?>
<meta property="og:url" content="<?= $cardUrl ?>">
<meta property="og:type" content="profile">
<link rel="canonical" href="<?= $cardUrl ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── Reset & tokens ──────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --primary:#00A878;
  --primary-dark:#008060;
  --accent:#FF6B2C;
  --bg:#f0f4f2;
  --card-bg:#ffffff;
  --text:#1a1a1a;
  --muted:#666;
  --border:#e8ede9;
  --radius:16px;
  --shadow:0 2px 16px rgba(0,0,0,.08);
}
html{scroll-behavior:smooth}
body{
  font-family:'Inter',system-ui,sans-serif;
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
  -webkit-font-smoothing:antialiased;
}

/* ── Hero header ─────────────────────────────────────── */
.card-hero{
  background:linear-gradient(135deg,var(--primary-dark) 0%,var(--primary) 100%);
  color:#fff;
  padding:2rem 1.25rem 3.5rem;
  text-align:center;
  position:relative;
}
.card-hero::after{
  content:'';
  position:absolute;
  bottom:-1px;left:0;right:0;
  height:2rem;
  background:var(--bg);
  border-radius:50% 50% 0 0 / 100% 100% 0 0;
}
.hero-logo{
  width:88px;height:88px;
  border-radius:50%;
  border:3px solid rgba(255,255,255,.8);
  object-fit:cover;
  margin-bottom:.9rem;
  background:#fff;
}
.hero-initials{
  width:88px;height:88px;
  border-radius:50%;
  border:3px solid rgba(255,255,255,.8);
  background:rgba(255,255,255,.2);
  display:flex;align-items:center;justify-content:center;
  font-size:2rem;font-weight:700;color:#fff;
  margin:0 auto .9rem;
}
.hero-name{font-size:1.5rem;font-weight:700;line-height:1.2;margin-bottom:.35rem}
.hero-cat{font-size:.9rem;opacity:.88;display:flex;align-items:center;justify-content:center;gap:.3rem}
.hero-loc{font-size:.82rem;opacity:.75;margin-top:.25rem}
.hero-badges{display:flex;gap:.4rem;justify-content:center;margin-top:.6rem;flex-wrap:wrap}
.badge{
  display:inline-flex;align-items:center;gap:.3rem;
  font-size:.72rem;font-weight:600;padding:.25rem .6rem;
  border-radius:50px;background:rgba(255,255,255,.2);color:#fff;
}
.badge-verified{background:rgba(255,255,255,.3)}
.rating-stars{color:#FFD700}

/* ── Action buttons ─────────────────────────────────── */
.actions-wrap{
  padding:0 1rem;
  margin-top:-1.5rem;
  position:relative;z-index:1;
}
.actions-grid{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:.6rem;
  background:var(--card-bg);
  border-radius:var(--radius);
  padding:1rem .75rem;
  box-shadow:var(--shadow);
}
.action-btn{
  display:flex;flex-direction:column;align-items:center;gap:.35rem;
  text-decoration:none;
  padding:.6rem .25rem;
  border-radius:12px;
  border:none;cursor:pointer;background:transparent;
  transition:background .15s;
  -webkit-tap-highlight-color:transparent;
}
.action-btn:hover,.action-btn:focus{background:var(--border)}
.action-btn:active{background:var(--border);transform:scale(.97)}
.action-icon{
  width:48px;height:48px;
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:1.3rem;
}
.action-label{font-size:.7rem;font-weight:600;color:var(--text);text-align:center;line-height:1.2}
.btn-save .action-icon{background:#e8f5f1;color:var(--primary)}
.btn-call .action-icon{background:#e8f5e8;color:#2d8a2d}
.btn-wa   .action-icon{background:#e8f8ef;color:#25D366}
.btn-web  .action-icon{background:#eef3ff;color:#4062d8}
.btn-dir  .action-icon{background:#fff3e8;color:#e07000}
.btn-share.action-icon{background:#f5e8ff;color:#8a2be2}
.btn-share .action-icon{background:#f5e8ff;color:#8a2be2}
.btn-view .action-icon{background:#fff8e1;color:#c9820a}

/* ── Info card ───────────────────────────────────────── */
.section-card{
  background:var(--card-bg);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  margin:1rem 1rem 0;
  padding:1.1rem;
  overflow:hidden;
}
.section-title{
  font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
  color:var(--muted);margin-bottom:.85rem;
  display:flex;align-items:center;gap:.4rem;
}
.info-row{
  display:flex;align-items:flex-start;gap:.75rem;
  padding:.6rem 0;
  border-bottom:1px solid var(--border);
  font-size:.88rem;
}
.info-row:last-child{border-bottom:none}
.info-icon{font-size:1rem;flex-shrink:0;margin-top:.05rem;width:1.3rem;text-align:center}
.info-val{color:var(--text);word-break:break-word}
.info-val a{color:var(--primary);text-decoration:none;font-weight:500}
.info-val a:hover{text-decoration:underline}

/* ── Description ─────────────────────────────────────── */
.desc-text{font-size:.88rem;line-height:1.65;color:#333}

/* ── Social links ────────────────────────────────────── */
.social-row{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.25rem}
.social-link{
  display:inline-flex;align-items:center;gap:.35rem;
  text-decoration:none;font-size:.8rem;font-weight:600;
  padding:.35rem .75rem;border-radius:50px;
  transition:opacity .15s;
}
.social-link:hover{opacity:.8}
.social-fb{background:#e8f0fb;color:#1877f2}
.social-ig{background:#fce4f3;color:#c13584}
.social-tt{background:#e8f8fa;color:#010101}

/* ── Products / Offers / Events ──────────────────────── */
.items-scroll{
  display:flex;gap:.75rem;
  overflow-x:auto;
  padding-bottom:.5rem;
  -webkit-overflow-scrolling:touch;
  scrollbar-width:none;
}
.items-scroll::-webkit-scrollbar{display:none}
.item-card{
  flex:0 0 160px;
  border:1px solid var(--border);
  border-radius:12px;
  overflow:hidden;
  background:#fafafa;
  text-decoration:none;color:var(--text);
  transition:box-shadow .15s;
}
.item-card:hover{box-shadow:0 2px 12px rgba(0,0,0,.12)}
.item-img{width:100%;height:110px;object-fit:cover;background:var(--border)}
.item-img-placeholder{
  width:100%;height:110px;
  background:linear-gradient(135deg,var(--border),#d4e8e0);
  display:flex;align-items:center;justify-content:center;
  font-size:1.8rem;
}
.item-body{padding:.5rem .6rem}
.item-name{font-size:.8rem;font-weight:600;line-height:1.3;margin-bottom:.2rem}
.item-price{font-size:.78rem;color:var(--primary);font-weight:700}
.item-code{font-size:.7rem;color:var(--accent);font-family:monospace;font-weight:700}
.item-date{font-size:.7rem;color:var(--muted)}

/* ── Full profile link ───────────────────────────────── */
.profile-link-wrap{
  margin:1rem 1rem 0;
}
.profile-link{
  display:flex;align-items:center;justify-content:center;gap:.5rem;
  background:var(--card-bg);
  border:2px solid var(--primary);
  color:var(--primary);
  text-decoration:none;
  font-weight:700;font-size:.92rem;
  padding:.85rem 1rem;
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  transition:background .15s,color .15s;
  -webkit-tap-highlight-color:transparent;
}
.profile-link:hover,.profile-link:focus{background:var(--primary);color:#fff}

/* ── Powered-by footer ───────────────────────────────── */
.powered-by{
  text-align:center;padding:1.5rem 1rem 2rem;
  font-size:.78rem;color:var(--muted);
}
.powered-by a{color:var(--primary);text-decoration:none;font-weight:600}
.powered-by a:hover{text-decoration:underline}

/* ── Share toast ─────────────────────────────────────── */
.toast{
  position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);
  background:#222;color:#fff;border-radius:50px;
  padding:.6rem 1.2rem;font-size:.82rem;font-weight:500;
  opacity:0;pointer-events:none;transition:opacity .25s;
  white-space:nowrap;z-index:999;
}
.toast.show{opacity:1}

/* ── Responsive ──────────────────────────────────────── */
@media(min-width:480px){
  .actions-grid{grid-template-columns:repeat(4,1fr)}
}
@media(min-width:600px){
  .card-hero{padding:2.5rem 1.5rem 4rem}
  .actions-wrap,.section-card,.profile-link-wrap{margin-left:auto;margin-right:auto;max-width:520px}
  .actions-wrap{padding:0}
  .hero-name{font-size:1.8rem}
}
</style>
</head>
<body>

<!-- Hero header -->
<div class="card-hero">
  <?php if ($l['logo']): ?>
    <img class="hero-logo" src="<?= e(UPLOAD_URL . $l['logo']) ?>" alt="<?= e($l['title']) ?>">
  <?php else: ?>
    <div class="hero-initials">
      <?= e(mb_strtoupper(mb_substr($l['title'], 0, 1))) ?>
    </div>
  <?php endif; ?>

  <h1 class="hero-name"><?= e($l['title']) ?></h1>
  <div class="hero-cat"><?= $l['cat_icon'] ?? '🏢' ?> <?= $catName ?></div>
  <?php if ($locName): ?>
    <div class="hero-loc">📍 <?= $locName ?></div>
  <?php endif; ?>

  <div class="hero-badges">
    <?php if ($l['verified']): ?>
      <span class="badge badge-verified">✓ <?= t('Verified','Vérifié') ?></span>
    <?php endif; ?>
    <?php if ($avgRating > 0): ?>
      <span class="badge">
        <span class="rating-stars">★</span>
        <?= number_format($avgRating, 1) ?> (<?= $reviewCount ?>)
      </span>
    <?php endif; ?>
    <?php if ($l['premium_star']): ?>
      <span class="badge">⭐ <?= t('Premium','Premium') ?></span>
    <?php endif; ?>
  </div>
</div>

<!-- Action buttons -->
<div class="actions-wrap">
  <div class="actions-grid">

    <!-- Save Contact -->
    <a href="<?= SITE_URL ?>/vcard/<?= e($l['slug']) ?>?source=<?= $source ?>"
       class="action-btn btn-save"
       data-track="contact_download"
       onclick="trackEvent('contact_download')">
      <div class="action-icon">💾</div>
      <span class="action-label"><?= t('Save Contact','Enregistrer') ?></span>
    </a>

    <!-- Call -->
    <?php if ($l['phone']): ?>
    <a href="tel:<?= e($l['phone']) ?>"
       class="action-btn btn-call"
       onclick="trackEvent('phone_click')">
      <div class="action-icon">📞</div>
      <span class="action-label"><?= t('Call','Appeler') ?></span>
    </a>
    <?php endif; ?>

    <!-- WhatsApp -->
    <?php if ($waPhone): ?>
    <a href="https://wa.me/<?= e($waPhone) ?>"
       target="_blank" rel="noopener"
       class="action-btn btn-wa"
       onclick="trackEvent('whatsapp_click')">
      <div class="action-icon">💬</div>
      <span class="action-label">WhatsApp</span>
    </a>
    <?php endif; ?>

    <!-- Website -->
    <?php $webUrl = safe_url($l['website'] ?? ''); ?>
    <?php if ($webUrl): ?>
    <a href="<?= $webUrl ?>"
       target="_blank" rel="noopener noreferrer"
       class="action-btn btn-web"
       onclick="trackEvent('website_click')">
      <div class="action-icon">🌐</div>
      <span class="action-label"><?= t('Website','Site Web') ?></span>
    </a>
    <?php endif; ?>

    <!-- Directions -->
    <?php if ($l['lat'] && $l['lng']): ?>
    <a href="https://www.google.com/maps/dir/?api=1&destination=<?= e($l['lat']) ?>,<?= e($l['lng']) ?>"
       target="_blank" rel="noopener"
       class="action-btn btn-dir"
       onclick="trackEvent('directions_click')">
      <div class="action-icon">🗺️</div>
      <span class="action-label"><?= t('Directions','Itinéraire') ?></span>
    </a>
    <?php elseif ($l['address']): ?>
    <a href="https://www.google.com/maps/search/<?= urlencode($l['address']) ?>"
       target="_blank" rel="noopener"
       class="action-btn btn-dir"
       onclick="trackEvent('directions_click')">
      <div class="action-icon">🗺️</div>
      <span class="action-label"><?= t('Directions','Itinéraire') ?></span>
    </a>
    <?php endif; ?>

    <!-- Share -->
    <button type="button"
            class="action-btn btn-share"
            onclick="shareCard()">
      <div class="action-icon">📤</div>
      <span class="action-label"><?= t('Share','Partager') ?></span>
    </button>

  </div>
</div>

<!-- Description -->
<?php if (!empty($l['description'])): ?>
<div class="section-card">
  <div class="section-title">ℹ️ <?= t('About','À propos') ?></div>
  <p class="desc-text"><?= nl2br(e(mb_substr(strip_tags($l['description']), 0, 400))) ?></p>
</div>
<?php endif; ?>

<!-- Contact info -->
<?php
$hasInfo = $l['email'] || $l['address'] || $l['phone'];
if ($hasInfo):
?>
<div class="section-card">
  <div class="section-title">📋 <?= t('Contact Details','Coordonnées') ?></div>

  <?php if ($l['phone']): ?>
  <div class="info-row">
    <span class="info-icon">📞</span>
    <span class="info-val"><a href="tel:<?= e($l['phone']) ?>" onclick="trackEvent('phone_click')"><?= e($l['phone']) ?></a></span>
  </div>
  <?php endif; ?>

  <?php if ($l['email']): ?>
  <div class="info-row">
    <span class="info-icon">✉️</span>
    <span class="info-val"><?= e($l['email']) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($l['address']): ?>
  <div class="info-row">
    <span class="info-icon">📍</span>
    <span class="info-val"><?= e($l['address']) ?></span>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Social links -->
<?php
$hasSocial = !empty($l['facebook']) || !empty($l['instagram']) || !empty($l['tiktok']);
if ($hasSocial):
?>
<div class="section-card">
  <div class="section-title">🔗 <?= t('Follow Us','Suivez-nous') ?></div>
  <div class="social-row">
    <?php if (!empty($l['facebook'])): ?>
      <a href="<?= safe_url($l['facebook']) ?>" target="_blank" rel="noopener"
         class="social-link social-fb" onclick="trackEvent('social_click')">
        f Facebook
      </a>
    <?php endif; ?>
    <?php if (!empty($l['instagram'])): ?>
      <a href="<?= safe_url($l['instagram']) ?>" target="_blank" rel="noopener"
         class="social-link social-ig" onclick="trackEvent('social_click')">
        📸 Instagram
      </a>
    <?php endif; ?>
    <?php if (!empty($l['tiktok'])): ?>
      <a href="<?= safe_url($l['tiktok']) ?>" target="_blank" rel="noopener"
         class="social-link social-tt" onclick="trackEvent('social_click')">
        🎵 TikTok
      </a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Products -->
<?php if (!empty($products)): ?>
<div class="section-card">
  <div class="section-title">🛍️ <?= t('Products','Produits') ?></div>
  <div class="items-scroll">
    <?php foreach ($products as $prod): ?>
    <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>#products"
       class="item-card" onclick="trackEvent('product_click')">
      <?php if (!empty($prod['image'])): ?>
        <img class="item-img" src="<?= e(UPLOAD_URL . $prod['image']) ?>" alt="<?= e($prod['name'] ?? '') ?>" loading="lazy">
      <?php else: ?>
        <div class="item-img-placeholder">🛍️</div>
      <?php endif; ?>
      <div class="item-body">
        <div class="item-name"><?= e($prod['name'] ?? '') ?></div>
        <?php if (!empty($prod['price'])): ?>
          <div class="item-price">XAF <?= number_format((float)$prod['price']) ?></div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Active offers -->
<?php if (!empty($offers)): ?>
<div class="section-card">
  <div class="section-title">🎟️ <?= t('Offers & Discounts','Offres & Réductions') ?></div>
  <div class="items-scroll">
    <?php foreach ($offers as $off): ?>
    <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>#offers"
       class="item-card" onclick="trackEvent('offer_click')">
      <?php if (!empty($off['image'])): ?>
        <img class="item-img" src="<?= e(UPLOAD_URL . $off['image']) ?>" alt="<?= e($off['title'] ?? '') ?>" loading="lazy">
      <?php else: ?>
        <div class="item-img-placeholder">🎟️</div>
      <?php endif; ?>
      <div class="item-body">
        <div class="item-name"><?= e($off['title'] ?? '') ?></div>
        <?php if (!empty($off['code'])): ?>
          <div class="item-code"><?= e($off['code']) ?></div>
        <?php elseif (!empty($off['discount_value'])): ?>
          <div class="item-price">-<?= e($off['discount_value']) ?><?= $off['discount_type'] === 'percent' ? '%' : ' XAF' ?></div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Upcoming events -->
<?php if (!empty($events)): ?>
<div class="section-card">
  <div class="section-title">📅 <?= t('Events','Événements') ?></div>
  <div class="items-scroll">
    <?php foreach ($events as $ev): ?>
    <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>#events"
       class="item-card" onclick="trackEvent('event_click')">
      <?php if (!empty($ev['image'])): ?>
        <img class="item-img" src="<?= e(UPLOAD_URL . $ev['image']) ?>" alt="<?= e($ev['title'] ?? '') ?>" loading="lazy">
      <?php else: ?>
        <div class="item-img-placeholder">📅</div>
      <?php endif; ?>
      <div class="item-body">
        <div class="item-name"><?= e($ev['title'] ?? '') ?></div>
        <?php if (!empty($ev['starts_at'])): ?>
          <div class="item-date"><?= date('d M', strtotime($ev['starts_at'])) ?></div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- View full profile -->
<div class="profile-link-wrap">
  <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>"
     class="profile-link"
     onclick="trackEvent('profile_click')">
    <?= t('View Full Business Profile','Voir le Profil Complet') ?> →
  </a>
</div>

<!-- Powered by -->
<div class="powered-by">
  <?= t('Powered by','Propulsé par') ?>
  <a href="<?= SITE_URL ?>" target="_blank" rel="noopener">237biz.net</a>
  &nbsp;·&nbsp;
  <a href="<?= SITE_URL ?>/add-listing" target="_blank" rel="noopener">
    <?= t('Get your digital card','Obtenez votre carte digitale') ?>
  </a>
</div>

<!-- Share toast -->
<div class="toast" id="toast"></div>

<script>
const LISTING_ID = <?= (int)$l['id'] ?>;
const CARD_URL   = <?= json_encode($cardUrl) ?>;
const BIZ_NAME   = <?= json_encode($l['title']) ?>;
const BIZ_DESC   = <?= json_encode(mb_substr(strip_tags($l['description'] ?? ''), 0, 120)) ?>;
const TRACK_URL  = <?= json_encode(SITE_URL . '/api/card-track') ?>;
const SOURCE     = <?= json_encode($source) ?>;

// ── Event tracking ──────────────────────────────────────────────────────────
function trackEvent(type) {
  try {
    navigator.sendBeacon(TRACK_URL, JSON.stringify({
      listing_id: LISTING_ID,
      event_type: type,
      source: SOURCE,
    }));
  } catch(e) {}
}

// Fire card_view on load
document.addEventListener('DOMContentLoaded', function() {
  trackEvent('card_view');
});

// ── Share ───────────────────────────────────────────────────────────────────
function shareCard() {
  trackEvent('share_click');

  if (navigator.share) {
    navigator.share({
      title: BIZ_NAME,
      text:  BIZ_DESC,
      url:   CARD_URL,
    }).catch(function() {});
  } else {
    // Fallback: copy to clipboard
    if (navigator.clipboard) {
      navigator.clipboard.writeText(CARD_URL).then(function() {
        showToast(<?= json_encode(t('Link copied!','Lien copié !')) ?>);
      }).catch(function() {
        showToast(CARD_URL);
      });
    } else {
      showToast(CARD_URL);
    }
  }
}

function showToast(msg) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(function() { t.classList.remove('show'); }, 2500);
}
</script>
</body>
</html>
