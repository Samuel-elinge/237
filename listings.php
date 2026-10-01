<?php
require_once __DIR__ . '/includes/config.php';

// ── FILTERS ──────────────────────────────────────────────
$q        = trim($_GET['q'] ?? '');
$catSlug  = trim($_GET['category'] ?? '');
$locSlug  = trim($_GET['location'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));

// Fetch filter data
$cats = db()->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();
$locs = db()->query("SELECT * FROM locations ORDER BY sort_order")->fetchAll();

// Build query
$where  = ["l.status = 'approved'"];
$params = [];

if ($q) {
    $where[]  = 'MATCH(l.title, l.description) AGAINST(? IN BOOLEAN MODE)';
    $params[] = $q . '*';
}
if ($catSlug) {
    $where[]  = 'c.slug = ?';
    $params[] = $catSlug;
}
if ($locSlug) {
    $where[]  = 'loc.slug = ?';
    $params[] = $locSlug;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Count
$countSQL = "SELECT COUNT(*) FROM listings l
             JOIN categories c ON c.id = l.category_id
             JOIN locations loc ON loc.id = l.location_id
             $whereSQL";
$st = db()->prepare($countSQL);
$st->execute($params);
$total = (int) $st->fetchColumn();

$pg = paginate($total, $page);

// Listings
$listSQL = "SELECT l.*, c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
                   loc.name_en AS loc_en
            FROM listings l
            JOIN categories c ON c.id = l.category_id
            JOIN locations loc ON loc.id = l.location_id
            $whereSQL
            ORDER BY l.featured DESC, l.created_at DESC
            LIMIT {$pg['perPage']} OFFSET {$pg['offset']}";
$st = db()->prepare($listSQL);
$st->execute($params);
$listings = $st->fetchAll();

// Active category/location names for title
$activeCat = $catSlug ? array_filter($cats, fn($c) => $c['slug'] === $catSlug) : [];
$activeCat = $activeCat ? array_values($activeCat)[0] : null;
$activeLoc = $locSlug ? array_filter($locs, fn($l) => $l['slug'] === $locSlug) : [];
$activeLoc = $activeLoc ? array_values($activeLoc)[0] : null;

// Look up custom SEO content for this category/location/combo, if an admin has written any
$seoContent = null;
if ($activeCat || $activeLoc) {
    $seoSt = db()->prepare("
        SELECT * FROM seo_content
        WHERE category_id <=> ? AND location_id <=> ?
    ");
    $seoSt->execute([$activeCat['id'] ?? null, $activeLoc['id'] ?? null]);
    $seoContent = $seoSt->fetch();
}
$seoIntro = $seoContent ? (lang() === 'fr' ? $seoContent['intro_fr'] : $seoContent['intro_en']) : null;

$pageTitle = t('All Businesses in Cameroon — 237Biz', 'Toutes les Entreprises au Cameroun — 237Biz');
if ($q) $pageTitle = t('Search results', 'Résultats') . ': ' . e($q) . ' — 237Biz';
if ($activeCat && $activeLoc) {
    $pageTitle = e($activeCat['name_en']) . ' ' . t('in','à') . ' ' . e($activeLoc['name_en']) . ' — 237Biz';
} elseif ($activeCat) {
    $pageTitle = e($activeCat['name_en']) . ' ' . t('Businesses','Entreprises') . ' — 237Biz';
} elseif ($activeLoc) {
    $pageTitle = t('Businesses in','Entreprises à') . ' ' . e($activeLoc['name_en']) . ' — 237Biz';
}

$pageDesc = t('Find verified businesses in Cameroon. Browse all categories — free directory listings for Cameroonian SMEs.', 'Trouvez des entreprises vérifiées au Cameroun. Annuaire gratuit pour les PME camerounaises.');
if ($activeCat && $activeLoc) {
    $pageDesc = e($activeCat['name_en']) . ' ' . t('businesses in','entreprises à') . ' ' . e($activeLoc['name_en']) . t(', Cameroon. Verified listings on 237Biz.', ', Cameroun. Annonces vérifiées sur 237Biz.');
} elseif ($activeCat) {
    $pageDesc = t('Find verified','Trouvez des') . ' ' . e($activeCat['name_en']) . ' ' . t('businesses in Cameroon on 237Biz — free directory.','entreprises au Cameroun sur 237Biz — annuaire gratuit.');
} elseif ($activeLoc) {
    $pageDesc = t('Verified businesses in','Entreprises vérifiées à') . ' ' . e($activeLoc['name_en']) . t(', Cameroon. Browse local companies on 237Biz.', ', Cameroun. Parcourez les entreprises locales sur 237Biz.');
}

// Admin-written content overrides the auto-generated title/description when present
if ($seoContent) {
    $customTitle = lang() === 'fr' ? $seoContent['meta_title_fr'] : $seoContent['meta_title_en'];
    $customDesc  = lang() === 'fr' ? $seoContent['meta_desc_fr']  : $seoContent['meta_desc_en'];
    if ($customTitle) $pageTitle = $customTitle;
    if ($customDesc)  $pageDesc  = $customDesc;
}

// Build query string helper
function buildQS(array $override = []): string {
    $params = array_merge([
        'q'        => $_GET['q'] ?? '',
        'category' => $_GET['category'] ?? '',
        'location' => $_GET['location'] ?? '',
    ], $override);
    $params = array_filter($params);
    return $params ? '?' . http_build_query($params) : '';
}

// Structured data — breadcrumb
$extraHead = '';
if (file_exists(__DIR__ . '/includes/schema.php')) {
    require_once __DIR__ . '/includes/schema.php';
    $crumbs = [['name' => t('Home','Accueil'), 'url' => SITE_URL . '/']];
    if ($activeCat) {
        $crumbs[] = ['name' => $activeCat['name_en']];
    } elseif ($activeLoc) {
        $crumbs[] = ['name' => $activeLoc['name_en']];
    } else {
        $crumbs[] = ['name' => t('All Businesses','Toutes les Entreprises')];
    }
    $extraHead = '<script type="application/ld+json">' . schemaBreadcrumb($crumbs) . '</script>' . "\n";
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home', 'Accueil') ?></a> ›
      <?php if ($activeCat && $activeLoc): ?>
        <a href="<?= SITE_URL ?>/listings"><?= t('All Listings', 'Toutes les annonces') ?></a> ›
        <a href="<?= SITE_URL ?>/listings?category=<?= e($catSlug) ?>"><?= e(lang()==='fr' ? $activeCat['name_fr'] : $activeCat['name_en']) ?></a> ›
        <span><?= e($activeLoc['name_en']) ?></span>
      <?php elseif ($activeCat): ?>
        <a href="<?= SITE_URL ?>/listings"><?= t('All Listings', 'Toutes les annonces') ?></a> ›
        <span><?= e(lang()==='fr' ? $activeCat['name_fr'] : $activeCat['name_en']) ?></span>
      <?php elseif ($activeLoc): ?>
        <a href="<?= SITE_URL ?>/listings"><?= t('All Listings', 'Toutes les annonces') ?></a> ›
        <span><?= e($activeLoc['name_en']) ?></span>
      <?php else: ?>
        <span><?= t('All Listings', 'Toutes les annonces') ?></span>
      <?php endif; ?>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(2rem,4vw,3rem);margin-bottom:0.5rem;">
      <?php if ($q): ?>
        <?= t('Results for', 'Résultats pour') ?> "<em style="color:var(--yellow)"><?= e($q) ?></em>"
      <?php elseif ($activeCat && $activeLoc): ?>
        <?= e(lang()==='fr' ? $activeCat['name_fr'] : $activeCat['name_en']) ?> <?= t('in','à') ?> <?= e($activeLoc['name_en']) ?>
      <?php elseif ($activeCat): ?>
        <?= e(lang()==='fr' ? $activeCat['name_fr'] : $activeCat['name_en']) ?>
      <?php elseif ($activeLoc): ?>
        <?= t('Businesses in', 'Entreprises à') ?> <?= e($activeLoc['name_en']) ?>
      <?php else: ?>
        <?= t('All Businesses in Cameroon', 'Toutes les Entreprises au Cameroun') ?>
      <?php endif; ?>
    </h1>
    <p style="color:var(--muted);font-size:0.9rem;"><?= $total ?> <?= t('businesses found', 'entreprises trouvées') ?></p>
    <?php if ($seoIntro): ?>
      <p style="color:var(--muted);font-size:0.95rem;line-height:1.8;max-width:760px;margin-top:1rem;"><?= nl2br(e($seoIntro)) ?></p>
    <?php endif; ?>
  </div>
</div>

<section class="page-section">
  <div class="container">
    <div style="display:grid;grid-template-columns:240px 1fr;gap:2rem;align-items:start;">

      <!-- SIDEBAR FILTERS -->
      <aside>
        <form action="<?= SITE_URL ?>/listings" method="GET">
          <div class="form-card" style="margin-bottom:1.25rem;">
            <h4 style="font-family:'Fraunces',serif;color:var(--white);margin-bottom:1rem;font-size:0.95rem;"><?= t('Search', 'Rechercher') ?></h4>
            <div class="form-group" style="margin-bottom:0.75rem;">
              <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= t('Business name...', 'Nom entreprise...') ?>">
            </div>
            <div class="form-group" style="margin-bottom:0.75rem;">
              <select name="category">
                <option value=""><?= t('All Categories', 'Toutes catégories') ?></option>
                <?php foreach ($cats as $cat): ?>
                  <option value="<?= e($cat['slug']) ?>" <?= $catSlug === $cat['slug'] ? 'selected' : '' ?>>
                    <?= $cat['icon'] ?> <?= e(lang()==='fr' ? $cat['name_fr'] : $cat['name_en']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:1rem;">
              <select name="location">
                <option value=""><?= t('All Cities', 'Toutes les villes') ?></option>
                <?php foreach ($locs as $loc): ?>
                  <option value="<?= e($loc['slug']) ?>" <?= $locSlug === $loc['slug'] ? 'selected' : '' ?>>
                    <?= e($loc['name_en']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-primary btn-full"><?= t('Search', 'Chercher') ?></button>
            <?php if ($q || $catSlug || $locSlug): ?>
              <a href="<?= SITE_URL ?>/listings" class="btn btn-outline btn-full" style="margin-top:0.5rem;"><?= t('Clear filters', 'Effacer filtres') ?></a>
            <?php endif; ?>
          </div>

          <!-- Categories list -->
          <div class="form-card">
            <h4 style="font-family:'Fraunces',serif;color:var(--white);margin-bottom:1rem;font-size:0.95rem;"><?= t('Categories', 'Catégories') ?></h4>
            <?php foreach ($cats as $cat): ?>
              <a href="<?= SITE_URL ?>/listings?category=<?= e($cat['slug']) ?>"
                 style="display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0;font-size:0.83rem;border-bottom:1px solid var(--border);color:<?= $catSlug===$cat['slug'] ? 'var(--green)' : 'var(--muted)' ?>;">
                <span><?= $cat['icon'] ?> <?= e(lang()==='fr' ? $cat['name_fr'] : $cat['name_en']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </form>
      </aside>

      <!-- LISTINGS -->
      <div>
        <?php if ($listings): ?>
          <div class="listings-grid">
            <?php foreach ($listings as $l): ?>
              <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>"
                 class="listing-card <?= $l['featured'] ? 'featured' : '' ?>"
                 style="text-decoration:none;color:inherit;">
                <div class="listing-top">
                  <div class="listing-logo">
                    <?php if ($l['logo']): ?>
                      <img src="<?= e(UPLOAD_URL . $l['logo']) ?>" alt="<?= e($l['title']) ?>">
                    <?php else: ?>
                      <?= $l['cat_icon'] ?>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div class="listing-name"><?= e($l['title']) ?></div>
                    <div class="listing-category"><?= e(lang()==='fr' ? $l['cat_fr'] : $l['cat_en']) ?></div>
                  </div>
                </div>
                <p class="listing-desc"><?= e(mb_substr($l['description'] ?? '', 0, 120)) ?>...</p>
                <div class="listing-meta">
                  <span class="listing-meta-item">📍 <?= e($l['loc_en']) ?></span>
                  <?php if ($l['phone']): ?><span class="listing-meta-item">📞 <?= e($l['phone']) ?></span><?php endif; ?>
                </div>
                <?php if ($l['verified']): ?>
                  <span class="listing-verified">✓ <?= t('Verified', 'Vérifié') ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>

          <!-- PAGINATION -->
          <?php if ($pg['pages'] > 1): ?>
            <div class="pagination">
              <?php if ($pg['page'] > 1): ?>
                <a href="<?= SITE_URL ?>/listings<?= buildQS(['page' => $pg['page']-1]) ?>">← <?= t('Prev', 'Préc') ?></a>
              <?php endif; ?>
              <?php for ($i = max(1, $pg['page']-2); $i <= min($pg['pages'], $pg['page']+2); $i++): ?>
                <?php if ($i === $pg['page']): ?>
                  <span class="current"><?= $i ?></span>
                <?php else: ?>
                  <a href="<?= SITE_URL ?>/listings<?= buildQS(['page' => $i]) ?>"><?= $i ?></a>
                <?php endif; ?>
              <?php endfor; ?>
              <?php if ($pg['page'] < $pg['pages']): ?>
                <a href="<?= SITE_URL ?>/listings<?= buildQS(['page' => $pg['page']+1]) ?>"><?= t('Next', 'Suiv') ?> →</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        <?php else: ?>
          <div style="text-align:center;padding:4rem 2rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
            <div style="font-size:3rem;margin-bottom:1rem;">🔍</div>
            <h3 style="font-family:'Fraunces',serif;margin-bottom:0.5rem;"><?= t('No businesses found', 'Aucune entreprise trouvée') ?></h3>
            <p style="color:var(--muted);margin-bottom:1.5rem;"><?= t('Try different search terms or browse all categories.', 'Essayez d\'autres termes ou parcourez toutes les catégories.') ?></p>
            <a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary">+ <?= t('Add your business', 'Ajouter votre entreprise') ?></a>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
