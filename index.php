<?php
/**
 * index.php — 237Biz Business Hub Homepage
 */
require_once __DIR__ . '/includes/config.php';

// ── Data queries (identical logic to original) ────────────────────────────
$cats = db()->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();
$locs = db()->query("SELECT * FROM locations ORDER BY sort_order")->fetchAll();

// Featured listings
$featured = db()->query("
    SELECT l.*, c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
           loc.name_en AS loc_en,
           (SELECT AVG(r.rating) FROM reviews r WHERE r.listing_id=l.id AND r.status='approved') AS avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.listing_id=l.id AND r.status='approved') AS review_count
    FROM listings l
    JOIN categories c  ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    WHERE l.status='approved' AND l.featured=1
    ORDER BY l.views DESC
    LIMIT 8
")->fetchAll();

// Recent reviews
$reviews = [];
try {
    $reviews = db()->query("
        SELECT r.*, l.title AS listing_title, l.slug AS listing_slug,
               c.icon AS cat_icon
        FROM reviews r
        JOIN listings l ON l.id = r.listing_id
        JOIN categories c ON c.id = l.category_id
        WHERE r.status='approved'
        ORDER BY r.created_at DESC
        LIMIT 6
    ")->fetchAll();
} catch(Exception $e) {}

// City listing counts
$cityCounts = [];
try {
    $cc = db()->query("
        SELECT loc.id, loc.name_en, loc.slug,
               COUNT(l.id) AS cnt,
               SUM(l.featured) AS featured_cnt
        FROM locations loc
        LEFT JOIN listings l ON l.location_id=loc.id AND l.status='approved'
        GROUP BY loc.id ORDER BY cnt DESC LIMIT 8
    ")->fetchAll();
    foreach ($cc as $row) $cityCounts[$row['id']] = $row;
} catch(Exception $e) {}

// Stats
$totalListings  = (int)db()->query("SELECT COUNT(*) FROM listings WHERE status='approved'")->fetchColumn();
$totalFeatured  = (int)db()->query("SELECT COUNT(*) FROM listings WHERE status='approved' AND featured=1")->fetchColumn();
$totalCities    = count($locs);

$pageTitle = t("237Biz — Cameroon's Business Hub","237Biz — Le Hub des Entreprises du Cameroun");
$pageDesc  = t("Discover, connect and grow with Cameroon's Business Hub. Find local businesses, list your company, or earn as a Sales Agent or Creator.",
               "Découvrez, connectez et grandissez avec le Hub des Entreprises du Cameroun.");

$isFr = lang() === 'fr';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ══════════════════════════════════════════
   HOMEPAGE STYLES
══════════════════════════════════════════ */

/* ── Hero ── */
.hero {
  position:relative; overflow:hidden;
  background:linear-gradient(160deg,#05280F 0%,#081C10 55%,#0e2a18 100%);
  padding:90px 0 80px;
  text-align:center;
}
.hero::before {
  content:''; position:absolute; inset:0;
  background:radial-gradient(ellipse 80% 60% at 50% 0%,rgba(0,168,120,0.12),transparent);
  pointer-events:none;
}
.hero-label {
  display:inline-flex; align-items:center; gap:7px;
  background:rgba(252,209,22,0.1); border:1px solid rgba(252,209,22,0.25);
  border-radius:99px; padding:5px 16px; font-size:12.5px; font-weight:700;
  color:#fcd116; margin-bottom:22px; letter-spacing:.03em;
}
.hero h1 {
  font-family:'Fraunces',serif; font-weight:900;
  font-size:clamp(2.2rem,6vw,4rem); color:#fff;
  line-height:1.1; margin-bottom:14px;
}
.hero-tagline {
  font-size:clamp(1rem,2vw,1.25rem); color:rgba(255,255,255,0.6);
  margin-bottom:40px; letter-spacing:.02em;
}
.hero-tagline span { color:#00A878; font-weight:600; }

/* Search bar */
.hero-search {
  display:flex; max-width:720px; margin:0 auto 32px;
  background:rgba(255,255,255,0.06); border:1.5px solid rgba(255,255,255,0.12);
  border-radius:14px; overflow:hidden; backdrop-filter:blur(8px);
  transition:border-color .2s;
}
.hero-search:focus-within { border-color:rgba(0,168,120,0.5); }
.hero-search input, .hero-search select {
  background:none; border:none; color:#fff; font-family:inherit;
  font-size:15px; padding:16px 18px; flex:1; min-width:0; outline:none;
}
.hero-search input::placeholder { color:rgba(255,255,255,0.4); }
.hero-search select option { background:#0e2a18; color:#fff; }
.hero-search .search-div { width:1px; background:rgba(255,255,255,0.1); margin:12px 0; flex-shrink:0; }
.hero-search button {
  padding:0 28px; background:#00A878; color:#fff; border:none;
  font-size:15px; font-weight:700; cursor:pointer; font-family:inherit;
  transition:background .2s; white-space:nowrap; flex-shrink:0;
}
.hero-search button:hover { background:#008f67; }

/* Secondary CTAs */
.hero-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
.hero-action {
  padding:9px 20px; border-radius:9px; font-size:13.5px; font-weight:600;
  text-decoration:none; border:1.5px solid rgba(255,255,255,0.15);
  color:rgba(255,255,255,0.8); transition:all .2s;
}
.hero-action:hover { border-color:rgba(255,255,255,0.4); color:#fff; background:rgba(255,255,255,0.05); }
.hero-action.primary { background:#fcd116; color:#0A1A0F; border-color:#fcd116; }
.hero-action.primary:hover { background:#ffe44d; border-color:#ffe44d; }

/* Stats strip */
.stats-strip {
  background:rgba(255,255,255,0.02); border-top:1px solid rgba(255,255,255,0.06);
  border-bottom:1px solid rgba(255,255,255,0.06); padding:20px 0;
}
.stats-inner { display:flex; justify-content:center; gap:48px; flex-wrap:wrap; }
.stat-pill { text-align:center; }
.stat-pill .num { font-family:'Fraunces',serif; font-weight:900; font-size:1.5rem; color:#fcd116; }
.stat-pill .lbl { font-size:12px; color:rgba(255,255,255,0.5); margin-top:2px; }

/* ── Sections ── */
.hp-section { padding:72px 0; }
.hp-section + .hp-section { border-top:1px solid rgba(255,255,255,0.05); }
.section-header { text-align:center; margin-bottom:40px; }
.section-label {
  display:inline-block; background:rgba(0,168,120,0.1); color:#00A878;
  border:1px solid rgba(0,168,120,0.25); border-radius:99px;
  padding:4px 14px; font-size:12px; font-weight:700; margin-bottom:12px;
  text-transform:uppercase; letter-spacing:.06em;
}
.section-title {
  font-family:'Fraunces',serif; font-weight:900;
  font-size:clamp(1.5rem,3vw,2.2rem); color:#fff; margin-bottom:10px;
}
.section-sub { color:rgba(255,255,255,0.55); font-size:15px; max-width:520px; margin:0 auto; }

/* ── Categories ── */
.cat-grid {
  display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:12px;
}
.cat-card {
  background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08);
  border-radius:14px; padding:20px 14px; text-align:center; text-decoration:none;
  transition:all .2s; display:flex; flex-direction:column; align-items:center; gap:8px;
}
.cat-card:hover { background:rgba(0,168,120,0.08); border-color:rgba(0,168,120,0.3); transform:translateY(-2px); }
.cat-card .ci { font-size:1.8rem; }
.cat-card .cn { font-size:13px; font-weight:600; color:rgba(255,255,255,0.8); }
.cat-card .cc { font-size:11.5px; color:rgba(255,255,255,0.4); }

/* ── City cards ── */
.city-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; }
.city-card {
  background:linear-gradient(135deg,rgba(0,168,120,0.08),rgba(0,168,120,0.03));
  border:1px solid rgba(0,168,120,0.15); border-radius:14px;
  padding:20px 18px; text-decoration:none; transition:all .2s;
  display:flex; flex-direction:column; gap:6px;
}
.city-card:hover { background:rgba(0,168,120,0.12); border-color:rgba(0,168,120,0.35); transform:translateY(-2px); }
.city-card .cn   { font-size:15px; font-weight:700; color:#fff; }
.city-card .cc   { font-size:13px; color:rgba(255,255,255,0.5); }
.city-card .cf   { font-size:11.5px; color:#00A878; font-weight:600; }

/* ── Business cards ── */
.biz-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
.biz-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:16px; overflow:hidden; transition:all .2s; display:flex; flex-direction:column;
}
.biz-card:hover { border-color:rgba(0,168,120,0.3); transform:translateY(-2px); box-shadow:0 8px 32px rgba(0,0,0,0.3); }
.biz-card-img {
  width:100%; aspect-ratio:16/9; object-fit:cover; background:#0a2015;
  display:flex; align-items:center; justify-content:center; font-size:2.5rem;
}
.biz-card-img img { width:100%; height:100%; object-fit:cover; }
.biz-card-body { padding:16px 18px; flex:1; display:flex; flex-direction:column; gap:6px; }
.biz-card-name { font-size:15px; font-weight:700; color:#fff; }
.biz-card-meta { display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-size:12.5px; color:rgba(255,255,255,0.5); }
.biz-badge { display:inline-flex; align-items:center; gap:3px; padding:2px 8px; border-radius:99px; font-size:11.5px; font-weight:700; }
.badge-featured { background:rgba(252,209,22,0.12); color:#fcd116; border:1px solid rgba(252,209,22,0.2); }
.badge-verified { background:rgba(0,168,120,0.12); color:#00A878; border:1px solid rgba(0,168,120,0.2); }
.biz-rating { display:flex; align-items:center; gap:4px; font-size:12.5px; color:#fcd116; }
.biz-card-actions { display:flex; gap:8px; margin-top:auto; padding-top:10px; }
.biz-btn {
  flex:1; padding:8px 10px; border-radius:8px; font-size:12.5px; font-weight:600;
  text-align:center; text-decoration:none; transition:all .15s; cursor:pointer; border:none; font-family:inherit;
}
.biz-btn-wa  { background:rgba(37,211,102,0.12); color:#25d366; border:1px solid rgba(37,211,102,0.2); }
.biz-btn-wa:hover { background:rgba(37,211,102,0.22); }
.biz-btn-tel { background:rgba(138,180,248,0.1); color:#8ab4f8; border:1px solid rgba(138,180,248,0.2); }
.biz-btn-tel:hover { background:rgba(138,180,248,0.2); }
.biz-btn-view { background:rgba(0,168,120,0.12); color:#00A878; border:1px solid rgba(0,168,120,0.2); }
.biz-btn-view:hover { background:rgba(0,168,120,0.22); }

/* ── Review cards ── */
.review-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.review-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:14px; padding:20px 22px; display:flex; flex-direction:column; gap:10px;
}
.review-stars { color:#fcd116; font-size:13px; letter-spacing:1px; }
.review-text  { font-size:13.5px; color:rgba(255,255,255,0.75); line-height:1.65; font-style:italic; }
.review-meta  { display:flex; align-items:center; gap:8px; font-size:12.5px; }
.review-author { font-weight:600; color:#fff; }
.review-biz    { color:var(--muted); font-size:12px; }

/* ── Business Owner section ── */
.owner-section { background:linear-gradient(135deg,rgba(8,28,16,0.9),rgba(14,46,24,0.9)); }
.owner-grid { display:grid; grid-template-columns:1fr 1fr; gap:60px; align-items:center; }
.benefit-list { list-style:none; padding:0; margin:20px 0 28px; display:flex; flex-direction:column; gap:10px; }
.benefit-list li { display:flex; align-items:flex-start; gap:10px; font-size:14px; color:rgba(255,255,255,0.78); line-height:1.5; }
.benefit-list li span { color:#00A878; font-size:1.1rem; flex-shrink:0; margin-top:1px; }

/* ── Growth tools ── */
.tools-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px; }
.tool-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:14px; padding:22px 20px; transition:all .2s;
}
.tool-card:hover { background:rgba(255,255,255,0.04); border-color:rgba(0,168,120,0.25); }
.tool-icon { font-size:1.6rem; margin-bottom:10px; }
.tool-name { font-size:14px; font-weight:700; color:#fff; margin-bottom:5px; }
.tool-desc { font-size:12.5px; color:rgba(255,255,255,0.55); line-height:1.55; }

/* ── Partner section ── */
.partner-split { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.partner-card {
  border-radius:18px; padding:36px 32px;
  display:flex; flex-direction:column; gap:0;
}
.partner-card.agent   { background:linear-gradient(135deg,rgba(61,111,191,0.15),rgba(61,111,191,0.05)); border:1px solid rgba(138,180,248,0.2); }
.partner-card.creator { background:linear-gradient(135deg,rgba(155,79,173,0.15),rgba(155,79,173,0.05)); border:1px solid rgba(224,123,224,0.2); }
.partner-card h3 { font-family:'Fraunces',serif; font-weight:900; font-size:1.35rem; margin:10px 0 6px; }
.partner-card p  { font-size:13.5px; color:rgba(255,255,255,0.6); margin-bottom:18px; line-height:1.6; }
.journey-mini { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:22px; }
.journey-mini-step { font-size:12px; font-weight:600; color:rgba(255,255,255,0.7); }
.journey-mini-arrow { font-size:10px; color:rgba(255,255,255,0.25); }

/* View all link */
.view-all {
  display:inline-flex; align-items:center; gap:6px;
  font-size:13.5px; font-weight:700; color:#00A878; text-decoration:none;
  padding:8px 18px; border:1.5px solid rgba(0,168,120,0.35); border-radius:8px;
  transition:all .2s;
}
.view-all:hover { background:rgba(0,168,120,0.08); border-color:rgba(0,168,120,0.6); }

@media(max-width:720px){
  .owner-grid  { grid-template-columns:1fr !important; }
  .partner-split { grid-template-columns:1fr !important; }
  .hero { padding:60px 0 50px; }
  .hero-search { flex-direction:column; border-radius:12px; }
  .hero-search .search-div { width:100%; height:1px; margin:0 16px; }
  .hero-search button { padding:14px; }
  .stats-inner { gap:24px; }
}
</style>

<!-- ══ HERO ══════════════════════════════════════════ -->
<section class="hero">
  <div class="container">
    <div class="hero-label">
      🇨🇲 <?= t('Cameroon\'s Business Hub','Le Hub des Entreprises du Cameroun') ?>
    </div>
    <h1><?= t('Discover. Connect. Grow.','Découvrez. Connectez. Grandissez.') ?></h1>
    <p class="hero-tagline">
      <span><?= t('Find','Trouvez') ?></span> <?= t('local businesses ·','des entreprises locales ·') ?>
      <span><?= t('Connect','Connectez') ?></span> <?= t('via WhatsApp ·','via WhatsApp ·') ?>
      <span><?= t('Grow','Grandissez') ?></span> <?= t('your presence in Cameroon','votre présence au Cameroun') ?>
    </p>

    <!-- Search form — submits to /listings (same as original) -->
    <form action="<?= SITE_URL ?>/listings" method="GET" class="hero-search">
      <input type="text" name="q" placeholder="<?= e(t('What are you looking for?','Que recherchez-vous ?')) ?>" value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off">
      <div class="search-div"></div>
      <select name="location_id">
        <option value=""><?= t('All Cities','Toutes les villes') ?></option>
        <?php foreach ($locs as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= ($_GET['location_id'] ?? '') == $loc['id'] ? 'selected' : '' ?>>
          📍 <?= e($loc['name_en']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <button type="submit">🔍 <?= t('Search','Rechercher') ?></button>
    </form>

    <div class="hero-actions">
      <a href="<?= SITE_URL ?>/listings" class="hero-action">🏪 <?= t('Browse Businesses','Parcourir les Entreprises') ?></a>
      <a href="<?= SITE_URL ?>/add-listing" class="hero-action primary">+ <?= t('List Your Business','Lister votre Entreprise') ?></a>
      <a href="<?= SITE_URL ?>/partners" class="hero-action">🤝 <?= t('Become a Partner','Devenir Partenaire') ?></a>
    </div>
  </div>
</section>

<!-- Stats strip -->
<div class="stats-strip">
  <div class="container">
    <div class="stats-inner">
      <?php foreach ([
        [$totalListings . '+', t('Businesses Listed','Entreprises Listées')],
        [$totalCities,          t('Cities Covered','Villes couvertes')],
        [$totalFeatured . '+',  t('Featured & Verified','Vedettes & Vérifiées')],
        ['🆓',                  t('Free to List','Gratuit pour lister')],
      ] as [$num, $lbl]): ?>
      <div class="stat-pill">
        <div class="num"><?= $num ?></div>
        <div class="lbl"><?= $lbl ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ══ CATEGORIES ══════════════════════════════════════ -->
<section class="hp-section">
  <div class="container">
    <div class="section-header">
      <div class="section-label">📂 <?= t('Categories','Catégories') ?></div>
      <h2 class="section-title"><?= t('Browse by Category','Parcourir par Catégorie') ?></h2>
      <p class="section-sub"><?= t('From restaurants to tech services — find every type of business in Cameroon.','Des restaurants aux services tech — trouvez chaque type d\'entreprise.') ?></p>
    </div>

    <div class="cat-grid">
      <?php foreach ($cats as $cat):
        $slug = $cat['slug'] ?? strtolower(preg_replace('/[^a-z0-9]/i','-',$cat['name_en']));
        $catName = $isFr ? $cat['name_fr'] : $cat['name_en'];
      ?>
      <a href="<?= SITE_URL ?>/category/<?= e($slug) ?>" class="cat-card">
        <div class="ci"><?= $cat['icon'] ?></div>
        <div class="cn"><?= e($catName) ?></div>
      </a>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin-top:28px;">
      <a href="<?= SITE_URL ?>/listings" class="view-all"><?= t('View All Categories','Toutes les catégories') ?> →</a>
    </div>
  </div>
</section>

<!-- ══ EXPLORE CITIES ══════════════════════════════════ -->
<section class="hp-section" style="background:rgba(255,255,255,0.01);">
  <div class="container">
    <div class="section-header">
      <div class="section-label">📍 <?= t('Locations','Localités') ?></div>
      <h2 class="section-title"><?= t('Explore Cameroon','Explorer le Cameroun') ?></h2>
      <p class="section-sub"><?= t('Discover businesses in major cities across Cameroon.','Découvrez des entreprises dans les principales villes du Cameroun.') ?></p>
    </div>

    <div class="city-grid">
      <?php foreach ($locs as $loc):
        $data = $cityCounts[$loc['id']] ?? ['cnt'=>0,'featured_cnt'=>0];
        $locSlug = $loc['slug'] ?? strtolower(preg_replace('/[^a-z0-9]/i','-',$loc['name_en']));
      ?>
      <a href="<?= SITE_URL ?>/location/<?= e($locSlug) ?>" class="city-card">
        <div class="cn">📍 <?= e($loc['name_en']) ?></div>
        <div class="cc"><?= (int)$data['cnt'] ?> <?= t('businesses','entreprises') ?></div>
        <?php if ((int)$data['featured_cnt'] > 0): ?>
        <div class="cf">⭐ <?= (int)$data['featured_cnt'] ?> <?= t('featured','vedettes') ?></div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin-top:28px;">
      <a href="<?= SITE_URL ?>/listings" class="view-all"><?= t('Explore All Locations','Explorer toutes les villes') ?> →</a>
    </div>
  </div>
</section>

<!-- ══ FEATURED BUSINESSES ═════════════════════════════ -->
<?php if ($featured): ?>
<section class="hp-section">
  <div class="container">
    <div class="section-header">
      <div class="section-label">⭐ <?= t('Featured','Vedettes') ?></div>
      <h2 class="section-title"><?= t('Featured Businesses','Entreprises Vedettes') ?></h2>
      <p class="section-sub"><?= t('Verified, trusted businesses standing out in their communities.','Entreprises vérifiées et de confiance qui se démarquent dans leurs communautés.') ?></p>
    </div>

    <div class="biz-grid">
      <?php foreach ($featured as $biz):
        $catName = $isFr ? $biz['cat_fr'] : $biz['cat_en'];
        $rating  = $biz['avg_rating'] ? round((float)$biz['avg_rating'], 1) : null;
        $stars   = $rating ? str_repeat('★', (int)round($rating)) . str_repeat('☆', 5-(int)round($rating)) : null;
        $waNum   = preg_replace('/\D/', '', $biz['whatsapp'] ?? '');
        $waMsg   = rawurlencode(t('Hi, I found your business on 237Biz and would like to enquire about your services.','Bonjour, j\'ai trouvé votre entreprise sur 237Biz et je souhaite me renseigner.'));
      ?>
      <div class="biz-card">
        <!-- Logo / Image -->
        <div class="biz-card-img">
          <?php if (!empty($biz['logo'])): ?>
          <img src="<?= SITE_URL ?>/uploads/<?= e($biz['logo']) ?>" alt="<?= e($biz['title']) ?>" loading="lazy">
          <?php else: ?>
          <span><?= $biz['cat_icon'] ?></span>
          <?php endif; ?>
        </div>

        <div class="biz-card-body">
          <!-- Badges -->
          <div style="display:flex;gap:5px;flex-wrap:wrap;">
            <?php if ($biz['featured']): ?><span class="biz-badge badge-featured">⭐ <?= t('Featured','Vedette') ?></span><?php endif; ?>
            <?php if ($biz['verified'] ?? false): ?><span class="biz-badge badge-verified">✅ <?= t('Verified','Vérifiée') ?></span><?php endif; ?>
          </div>

          <div class="biz-card-name"><?= e($biz['title']) ?></div>

          <div class="biz-card-meta">
            <span><?= $biz['cat_icon'] ?> <?= e($catName) ?></span>
            <span>·</span>
            <span>📍 <?= e($biz['loc_en']) ?></span>
          </div>

          <?php if ($rating): ?>
          <div class="biz-rating">
            <span><?= $stars ?></span>
            <span style="color:rgba(255,255,255,0.5);font-size:12px;"><?= $rating ?> (<?= (int)$biz['review_count'] ?>)</span>
          </div>
          <?php endif; ?>

          <!-- Action buttons -->
          <div class="biz-card-actions">
            <?php if ($waNum): ?>
            <a href="https://wa.me/<?= $waNum ?>?text=<?= $waMsg ?>" target="_blank" class="biz-btn biz-btn-wa">💬 WhatsApp</a>
            <?php endif; ?>
            <?php if (!empty($biz['phone'])): ?>
            <a href="tel:<?= e($biz['phone']) ?>" class="biz-btn biz-btn-tel">📞 <?= t('Call','Appel') ?></a>
            <?php endif; ?>
            <a href="<?= SITE_URL ?>/listing/<?= e($biz['slug']) ?>" class="biz-btn biz-btn-view">👁 <?= t('View','Voir') ?></a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin-top:32px;">
      <a href="<?= SITE_URL ?>/listings?sort=featured" class="view-all">
        <?= t('View All Featured Businesses','Voir toutes les entreprises vedettes') ?> →
      </a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ══ CUSTOMER REVIEWS ════════════════════════════════ -->
<?php if ($reviews): ?>
<section class="hp-section" style="background:rgba(255,255,255,0.01);">
  <div class="container">
    <div class="section-header">
      <div class="section-label">⭐ <?= t('Reviews','Avis') ?></div>
      <h2 class="section-title"><?= t('What customers are saying','Ce que disent les clients') ?></h2>
      <p class="section-sub"><?= t('Real reviews from real customers across Cameroon.','Vrais avis de vrais clients à travers le Cameroun.') ?></p>
    </div>

    <div class="review-grid">
      <?php foreach (array_slice($reviews, 0, 6) as $rev): ?>
      <div class="review-card">
        <div class="review-stars">
          <?= str_repeat('★', (int)$rev['rating']) ?><?= str_repeat('☆', 5-(int)$rev['rating']) ?>
        </div>
        <div class="review-text">
          "<?= e(mb_substr($rev['comment'] ?? '', 0, 160)) ?><?= mb_strlen($rev['comment'] ?? '') > 160 ? '…' : '' ?>"
        </div>
        <div class="review-meta">
          <div>
            <div class="review-author"><?= e($rev['reviewer_name'] ?? 'Customer') ?></div>
            <a href="<?= SITE_URL ?>/listing/<?= e($rev['listing_slug']) ?>" class="review-biz" style="text-decoration:none;color:var(--muted);">
              <?= $rev['cat_icon'] ?> <?= e($rev['listing_title']) ?>
            </a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin-top:32px;">
      <a href="<?= SITE_URL ?>/reviews" class="view-all"><?= t('Explore All Reviews','Explorer tous les avis') ?> →</a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ══ BUSINESS OWNER CTA ══════════════════════════════ -->
<section class="hp-section owner-section">
  <div class="container">
    <div class="owner-grid">
      <div>
        <div class="section-label" style="text-align:left;">🏪 <?= t('FOR BUSINESSES','POUR LES ENTREPRISES') ?></div>
        <h2 class="section-title" style="text-align:left;margin-top:10px;">
          <?= t('Is your business on 237Biz?','Votre entreprise est-elle sur 237Biz ?') ?>
        </h2>
        <p style="color:rgba(255,255,255,0.6);font-size:15px;line-height:1.7;margin-bottom:4px;">
          <?= t('Join thousands of Cameroonian businesses already connecting with customers through 237Biz.','Rejoignez des milliers d\'entreprises camerounaises qui connectent déjà avec des clients via 237Biz.') ?>
        </p>
        <ul class="benefit-list">
          <?php foreach ([
            t('Get discovered by local customers','Soyez découvert par les clients locaux'),
            t('Manage your business profile 24/7','Gérez votre profil d\'entreprise 24h/24'),
            t('Collect verified customer reviews','Collectez des avis clients vérifiés'),
            t('Receive WhatsApp enquiries directly','Recevez des demandes WhatsApp directement'),
            t('Accept online appointment bookings','Acceptez les prises de rendez-vous en ligne'),
            t('Post promotions and announcements','Publiez des promotions et annonces'),
            t('Improve your online presence & SEO','Améliorez votre présence en ligne et SEO'),
            t('Connect with SupportDesk for growth','Connectez avec SupportDesk pour croître'),
          ] as $benefit): ?>
          <li><span>✓</span> <?= $benefit ?></li>
          <?php endforeach; ?>
        </ul>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
          <a href="<?= SITE_URL ?>/claim-listing" class="view-all" style="color:#fcd116;border-color:rgba(252,209,22,0.3);">🏴 <?= t('Claim My Business','Revendiquer mon Entreprise') ?></a>
          <a href="<?= SITE_URL ?>/add-listing"   class="view-all">+ <?= t('List My Business','Lister mon Entreprise') ?></a>
        </div>
      </div>

      <div style="position:relative;">
        <!-- Visual feature cards -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <?php foreach ([
            ['📊', t('Analytics Dashboard','Tableau Analytique'),   t('Track views, enquiries and bookings','Suivez vues, demandes et réservations')],
            ['📅', t('Appointment Booking','Prise de Rendez-vous'), t('Let customers book directly','Permettez aux clients de réserver')],
            ['📢', t('Announcements','Annonces'),                   t('Post offers and promotions','Publiez offres et promotions')],
            ['💬', t('WhatsApp Widget','Widget WhatsApp'),          t('Instant customer contact','Contact client instantané')],
            ['📱', t('QR Code','Code QR'),                         t('Print and share your listing','Imprimez et partagez votre annonce')],
            ['⭐', t('Reviews','Avis'),                            t('Build your reputation','Construisez votre réputation')],
          ] as [$icon, $name, $desc]): ?>
          <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px;">
            <div style="font-size:1.4rem;margin-bottom:6px;"><?= $icon ?></div>
            <div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:3px;"><?= $name ?></div>
            <div style="font-size:12px;color:rgba(255,255,255,0.5);"><?= $desc ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ BUSINESS GROWTH TOOLS ═══════════════════════════ -->
<section class="hp-section">
  <div class="container">
    <div class="section-header">
      <div class="section-label">💼 <?= t('BUSINESS HUB','BUSINESS HUB') ?></div>
      <h2 class="section-title"><?= t('More than a listing','Plus qu\'une annonce') ?></h2>
      <p class="section-sub"><?= t('237Biz gives businesses the tools to attract customers, manage their reputation and grow online.','237Biz donne aux entreprises les outils pour attirer des clients et grandir en ligne.') ?></p>
    </div>

    <div class="tools-grid">
      <?php foreach ([
        ['👤', t('Business Profile','Profil d\'Entreprise'),      t('Complete listing with logo, description, services, hours, gallery and FAQs.','Annonce complète avec logo, description, services, horaires et FAQ.')],
        ['⭐', t('Reviews & Reputation','Avis & Réputation'),     t('Collect customer reviews and build a trusted reputation online.','Collectez des avis clients et construisez une réputation de confiance.')],
        ['📅', t('Appointment Booking','Prise de Rendez-vous'),   t('Let customers book directly from your listing. Confirm or decline in one click.','Permettez aux clients de réserver. Confirmez ou refusez en un clic.')],
        ['🌐', t('Website & Online Presence','Site Web & Présence'),t('Get a professional website and improve your Google visibility.','Obtenez un site web professionnel et améliorez votre visibilité Google.')],
        ['🔍', t('SEO & Discovery','SEO & Découverte'),           t('Rank in local search results and get found by customers searching on Google.','Classez-vous dans les résultats de recherche locaux.')],
        ['📣', t('Marketing','Marketing'),                        t('Promotions, announcements, WhatsApp campaigns and more.','Promotions, annonces, campagnes WhatsApp et plus encore.')],
        ['🎧', t('SupportDesk','SupportDesk'),                    t('Get dedicated support to help your business grow on 237Biz.','Obtenez un soutien dédié pour aider votre entreprise à grandir.')],
      ] as [$icon, $name, $desc]): ?>
      <div class="tool-card">
        <div class="tool-icon"><?= $icon ?></div>
        <div class="tool-name"><?= $name ?></div>
        <div class="tool-desc"><?= $desc ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin-top:32px;">
      <a href="<?= SITE_URL ?>/business-listing" class="view-all">
        <?= t('Explore Business Solutions','Explorer les solutions Business') ?> →
      </a>
    </div>
  </div>
</section>

<!-- ══ AGENTS & CREATORS ═══════════════════════════════ -->
<section class="hp-section" style="background:rgba(252,209,22,0.02);border-top:1px solid rgba(252,209,22,0.08) !important;">
  <div class="container">
    <div class="section-header">
      <div class="section-label" style="background:rgba(252,209,22,0.1);color:#fcd116;border-color:rgba(252,209,22,0.25);">
        🤝 <?= t('PARTNER PROGRAMME','PROGRAMME PARTENAIRES') ?>
      </div>
      <h2 class="section-title"><?= t('Earn by helping Cameroon businesses grow','Gagnez en aidant les entreprises camerounaises à grandir') ?></h2>
      <p class="section-sub"><?= t('Join as a Sales Agent or Content Creator and earn commissions every time a business joins through you.','Rejoignez en tant qu\'Agent de Vente ou Créateur de Contenu et gagnez des commissions.') ?></p>
    </div>

    <div class="partner-split">
      <!-- Agent card -->
      <div class="partner-card agent">
        <div style="font-size:2.5rem;margin-bottom:8px;">👔</div>
        <h3 style="color:#8ab4f8;"><?= t('Sales Agents','Agents de Vente') ?></h3>
        <p><?= t('Actively approach local businesses, encourage them to join 237Biz and earn a commission for every featured listing you refer.','Approchez activement les entreprises locales et gagnez une commission pour chaque annonce vedette que vous référez.') ?></p>
        <div class="journey-mini">
          <?php foreach ([t('Find Businesses','Trouvez'),t('Refer','Référez'),t('They Join','Ils Rejoignent'),t('Track','Suivez'),t('Earn','Gagnez')] as $i => $step): ?>
          <?php if ($i > 0): ?><span class="journey-mini-arrow">→</span><?php endif; ?>
          <span class="journey-mini-step"><?= $step ?></span>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:auto;">
          <a href="<?= SITE_URL ?>/join?path=agent" style="padding:11px 22px;background:#8ab4f8;color:#0a1a2e;border-radius:9px;font-size:13.5px;font-weight:700;text-decoration:none;">
            <?= t('Become a Sales Agent','Devenir Agent de Vente') ?>
          </a>
          <a href="<?= SITE_URL ?>/login" style="padding:11px 22px;border:1.5px solid rgba(138,180,248,0.3);color:#8ab4f8;border-radius:9px;font-size:13.5px;font-weight:600;text-decoration:none;">
            <?= t('Agent Login','Connexion Agent') ?>
          </a>
        </div>
      </div>

      <!-- Creator card -->
      <div class="partner-card creator">
        <div style="font-size:2.5rem;margin-bottom:8px;">🎬</div>
        <h3 style="color:#e07be0;"><?= t('Content Creators','Créateurs de Contenu') ?></h3>
        <p><?= t('TikTok, Instagram, Facebook or YouTube creator? Share your referral link, create content about 237Biz, and earn rewards every time a business joins through you.','Créateur TikTok, Instagram, Facebook ou YouTube ? Partagez votre lien de parrainage et gagnez des récompenses.') ?></p>
        <div class="journey-mini">
          <?php foreach ([t('Create Content','Créez'),t('Share Link','Partagez'),t('Business Joins','Ils Rejoignent'),t('Earn Rewards','Gagnez')] as $i => $step): ?>
          <?php if ($i > 0): ?><span class="journey-mini-arrow">→</span><?php endif; ?>
          <span class="journey-mini-step"><?= $step ?></span>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:auto;">
          <a href="<?= SITE_URL ?>/join?path=creator" style="padding:11px 22px;background:#e07be0;color:#0a1a2e;border-radius:9px;font-size:13.5px;font-weight:700;text-decoration:none;">
            <?= t('Become a Creator','Devenir Créateur') ?>
          </a>
          <a href="<?= SITE_URL ?>/login" style="padding:11px 22px;border:1.5px solid rgba(224,123,224,0.3);color:#e07be0;border-radius:9px;font-size:13.5px;font-weight:600;text-decoration:none;">
            <?= t('Creator Login','Connexion Créateur') ?>
          </a>
        </div>
      </div>
    </div>

    <div style="text-align:center;margin-top:28px;">
      <a href="<?= SITE_URL ?>/partners" class="view-all" style="color:#fcd116;border-color:rgba(252,209,22,0.3);">
        <?= t('Learn more about the Partner Programme','En savoir plus sur le Programme Partenaires') ?> →
      </a>
    </div>
  </div>
</section>

<!-- ══ FOOTER CTA ══════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#08472F,#081C10);padding:70px 0;text-align:center;">
  <div class="container">
    <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2.2rem);margin-bottom:10px;">
      <?= t('Ready to get started?','Prêt à commencer ?') ?>
    </h2>
    <p style="color:rgba(255,255,255,0.6);font-size:15px;margin-bottom:32px;max-width:500px;margin-left:auto;margin-right:auto;">
      <?= t('Join Cameroon\'s Business Hub today — free for businesses, customers and partners.','Rejoignez le Hub des Entreprises du Cameroun — gratuit pour les entreprises, clients et partenaires.') ?>
    </p>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/join" style="padding:14px 32px;background:#fcd116;color:#0A1A0F;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none;">+ <?= t('Join 237Biz','Rejoindre 237Biz') ?></a>
      <a href="<?= SITE_URL ?>/listings" style="padding:14px 32px;border:2px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.8);border-radius:10px;font-size:15px;font-weight:600;text-decoration:none;">🔍 <?= t('Browse Businesses','Parcourir les Entreprises') ?></a>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
