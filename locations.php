<?php
/**
 * locations.php — 237Biz Browse by Location
 * URL: /locations
 */
require_once __DIR__ . '/includes/config.php';

$isFr = lang() === 'fr';
$pageTitle = $isFr ? 'Parcourir par Ville — 237Biz Cameroun' : 'Browse by City — 237Biz Cameroon';
$pageDesc  = $isFr
    ? 'Trouvez des entreprises dans toutes les villes du Cameroun — Douala, Yaoundé, Limbe, Buea, Bafoussam et plus. Annuaire local gratuit.'
    : 'Find businesses in every city across Cameroon — Douala, Yaoundé, Limbe, Buea, Bafoussam and more. Free local business directory.';

// Locations with counts
$locations = db()->query("
    SELECT loc.*,
           COUNT(DISTINCT l.id)        AS total,
           SUM(l.featured = 1)         AS featured,
           SUM(l.verified = 1)         AS verified,
           ROUND(AVG(r.rating), 1)     AS avg_rating,
           COUNT(DISTINCT r.id)        AS reviews
    FROM locations loc
    LEFT JOIN listings l  ON l.location_id = loc.id AND l.status = 'approved'
    LEFT JOIN reviews r   ON r.listing_id  = l.id   AND r.status = 'approved'
    GROUP BY loc.id
    ORDER BY total DESC
")->fetchAll();

// Top categories across all locations
$topCats = db()->query("
    SELECT c.id, c.name_en, c.name_fr, c.icon, c.slug,
           COUNT(l.id) AS cnt
    FROM categories c
    LEFT JOIN listings l ON l.category_id=c.id AND l.status='approved'
    GROUP BY c.id ORDER BY cnt DESC LIMIT 8
")->fetchAll();

$totalListings = array_sum(array_column($locations, 'total'));
$totalCities   = count($locations);

require_once __DIR__ . '/includes/header.php';
?>

<!-- SEO structured data -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": "<?= e($pageTitle) ?>",
  "description": "<?= e($pageDesc) ?>",
  "url": "<?= SITE_URL ?>/locations"
}
</script>

<style>
.loc-hero {
  background:linear-gradient(150deg,#05280F,#081C10);
  padding:64px 0 52px; text-align:center;
}
.loc-hero h1 { font-family:'Fraunces',serif; font-weight:900; font-size:clamp(1.8rem,4vw,2.8rem); color:#fff; margin-bottom:10px; }
.loc-hero p  { color:rgba(255,255,255,0.6); font-size:15px; max-width:500px; margin:0 auto 28px; line-height:1.65; }

/* Stats strip */
.loc-stats { display:flex; justify-content:center; gap:40px; flex-wrap:wrap; padding:20px 0;
             border-bottom:1px solid rgba(255,255,255,0.06); margin-bottom:40px; }
.loc-stat .num { font-family:'Fraunces',serif; font-weight:900; font-size:1.6rem; color:#fcd116; }
.loc-stat .lbl { font-size:12px; color:rgba(255,255,255,0.45); margin-top:2px; }

/* City grid */
.city-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; }
.city-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:16px; padding:24px; text-decoration:none;
  transition:all .2s; display:flex; flex-direction:column; gap:8px; position:relative; overflow:hidden;
}
.city-card::before {
  content:''; position:absolute; inset:0;
  background:linear-gradient(135deg,rgba(0,168,120,0.06),transparent);
  opacity:0; transition:opacity .2s;
}
.city-card:hover { border-color:rgba(0,168,120,0.35); transform:translateY(-2px); box-shadow:0 8px 32px rgba(0,0,0,0.25); }
.city-card:hover::before { opacity:1; }

.city-name  { font-family:'Fraunces',serif; font-weight:900; font-size:1.15rem; color:#fff; }
.city-count { font-size:1.5rem; font-weight:900; color:#fcd116; font-family:'Fraunces',serif; line-height:1; }
.city-sub   { font-size:13px; color:rgba(255,255,255,0.5); }
.city-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:4px; }
.city-chip  { font-size:11px; font-weight:700; padding:2px 9px; border-radius:99px; }
.chip-ft    { background:rgba(252,209,22,0.1); color:#fcd116; border:1px solid rgba(252,209,22,0.2); }
.chip-vr    { background:rgba(0,168,120,0.1); color:#00A878; border:1px solid rgba(0,168,120,0.2); }
.chip-rv    { background:rgba(138,180,248,0.1); color:#8ab4f8; border:1px solid rgba(138,180,248,0.2); }
.city-arrow { margin-top:auto; font-size:13px; color:#00A878; font-weight:700; }

/* Category filter */
.cat-filter { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:28px; }
.cat-pill {
  padding:7px 16px; border-radius:99px; font-size:13px; font-weight:600;
  text-decoration:none; border:1px solid rgba(255,255,255,0.1);
  color:rgba(255,255,255,0.6); background:rgba(255,255,255,0.03); transition:all .15s;
}
.cat-pill:hover { background:rgba(0,168,120,0.1); border-color:rgba(0,168,120,0.35); color:#00A878; }

/* Empty state */
.city-empty { opacity:.5; pointer-events:none; }

@media(max-width:600px){ .loc-stats { gap:20px; } }
</style>

<!-- Hero -->
<div class="loc-hero">
  <div class="container">
    <div style="display:inline-block;background:rgba(0,168,120,0.1);color:#00A878;border:1px solid rgba(0,168,120,0.25);border-radius:99px;padding:4px 14px;font-size:12.5px;font-weight:700;margin-bottom:16px;">
      📍 <?= t('Browse by Location','Parcourir par Ville') ?>
    </div>
    <h1><?= t('Find Businesses Across Cameroon','Trouvez des Entreprises à Travers le Cameroun') ?></h1>
    <p><?= t('Discover local businesses in every city and region of Cameroon. From Douala to Limbe, Yaoundé to Buea — find what you need near you.','Découvrez des entreprises locales dans chaque ville et région du Cameroun. De Douala à Limbe, Yaoundé à Buea — trouvez ce dont vous avez besoin près de chez vous.') ?></p>

    <!-- Search shortcut -->
    <form action="<?= SITE_URL ?>/listings" method="GET" style="display:flex;max-width:480px;margin:0 auto;gap:0;background:rgba(255,255,255,0.06);border:1.5px solid rgba(255,255,255,0.12);border-radius:12px;overflow:hidden;">
      <input type="text" name="q" placeholder="<?= e(t('Search businesses...','Rechercher des entreprises...')) ?>"
             style="flex:1;padding:13px 16px;background:none;border:none;color:#fff;font-size:14px;font-family:inherit;outline:none;">
      <button type="submit" style="padding:0 20px;background:#00A878;color:#fff;border:none;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;">
        🔍 <?= t('Search','Rechercher') ?>
      </button>
    </form>
  </div>
</div>

<section class="page-section">
<div class="container">

  <!-- Stats -->
  <div class="loc-stats">
    <div class="loc-stat" style="text-align:center;">
      <div class="num"><?= number_format($totalListings) ?>+</div>
      <div class="lbl"><?= t('Businesses listed','Entreprises listées') ?></div>
    </div>
    <div class="loc-stat" style="text-align:center;">
      <div class="num"><?= $totalCities ?></div>
      <div class="lbl"><?= t('Cities & regions','Villes & régions') ?></div>
    </div>
    <div class="loc-stat" style="text-align:center;">
      <div class="num">🆓</div>
      <div class="lbl"><?= t('Free to list','Gratuit pour lister') ?></div>
    </div>
  </div>

  <!-- Browse by category quick links -->
  <div style="margin-bottom:32px;">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,0.4);margin-bottom:12px;">
      <?= t('Filter by category','Filtrer par catégorie') ?>
    </div>
    <div class="cat-filter">
      <a href="<?= SITE_URL ?>/listings" class="cat-pill">🏪 <?= t('All','Tous') ?></a>
      <?php foreach ($topCats as $cat):
        $slug = $cat['slug'] ?? strtolower(preg_replace('/[^a-z0-9]/i','-',$cat['name_en']));
      ?>
      <a href="<?= SITE_URL ?>/category/<?= e($slug) ?>" class="cat-pill">
        <?= $cat['icon'] ?> <?= e($isFr ? $cat['name_fr'] : $cat['name_en']) ?>
        <span style="color:rgba(255,255,255,0.35);font-size:11px;">(<?= (int)$cat['cnt'] ?>)</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- City grid -->
  <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.4rem;margin-bottom:20px;">
    📍 <?= t('All Cities & Regions','Toutes les Villes & Régions') ?>
  </h2>

  <div class="city-grid">
    <?php foreach ($locations as $loc):
      $locSlug = $loc['slug'] ?? strtolower(preg_replace('/[^a-z0-9]/i','-',$loc['name_en']));
      $hasListings = (int)$loc['total'] > 0;
    ?>
    <a href="<?= SITE_URL ?>/location/<?= e($locSlug) ?>"
       class="city-card <?= !$hasListings?'city-empty':'' ?>">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
        <div class="city-name">📍 <?= e($loc['name_en']) ?></div>
        <div class="city-count"><?= number_format((int)$loc['total']) ?></div>
      </div>
      <div class="city-sub">
        <?= (int)$loc['total'] ?> <?= t('businesses','entreprises') ?>
        <?php if ((int)$loc['reviews'] > 0): ?>
          · <?= (int)$loc['reviews'] ?> <?= t('reviews','avis') ?>
        <?php endif; ?>
      </div>
      <div class="city-chips">
        <?php if ((int)$loc['featured'] > 0): ?>
        <span class="city-chip chip-ft">⭐ <?= (int)$loc['featured'] ?> <?= t('featured','vedettes') ?></span>
        <?php endif; ?>
        <?php if ((int)$loc['verified'] > 0): ?>
        <span class="city-chip chip-vr">✅ <?= (int)$loc['verified'] ?> <?= t('verified','vérifiées') ?></span>
        <?php endif; ?>
        <?php if ($loc['avg_rating']): ?>
        <span class="city-chip chip-rv">★ <?= $loc['avg_rating'] ?></span>
        <?php endif; ?>
      </div>
      <?php if ($hasListings): ?>
      <div class="city-arrow"><?= t('Browse businesses','Voir les entreprises') ?> →</div>
      <?php else: ?>
      <div style="font-size:12px;color:rgba(255,255,255,0.3);margin-top:4px;"><?= t('No listings yet','Aucune annonce') ?></div>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- CTA -->
  <div style="text-align:center;margin-top:56px;padding:48px 24px;background:rgba(0,168,120,0.04);border:1px solid rgba(0,168,120,0.15);border-radius:20px;">
    <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.5rem;margin-bottom:10px;">
      <?= t('Your business not listed?','Votre entreprise n\'est pas listée ?') ?>
    </h2>
    <p style="color:rgba(255,255,255,0.6);font-size:14px;margin-bottom:24px;max-width:440px;margin-inline:auto;">
      <?= t('Add your business for free. Published within 24 hours. Visible to customers searching in your city.','Ajoutez votre entreprise gratuitement. Publiée dans les 24 heures. Visible pour les clients cherchant dans votre ville.') ?>
    </p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/add-listing" style="padding:12px 28px;background:#00A878;color:#fff;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;">
        + <?= t('List Your Business Free','Lister votre Entreprise Gratuitement') ?>
      </a>
      <a href="<?= SITE_URL ?>/listings" style="padding:12px 24px;border:2px solid rgba(255,255,255,0.15);color:rgba(255,255,255,0.75);border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;">
        🔍 <?= t('Browse All Businesses','Voir Toutes les Entreprises') ?>
      </a>
    </div>
  </div>

</div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
