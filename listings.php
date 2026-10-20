<?php
require_once __DIR__ . '/includes/config.php';

// ── FILTERS ──────────────────────────────────────────────
$q        = trim($_GET['q'] ?? '');
$catSlug  = trim($_GET['category'] ?? '');
$locSlug  = trim($_GET['location'] ?? '');
$sort     = in_array($_GET['sort'] ?? '', ['newest','featured','alpha','reviewed'])
            ? ($_GET['sort'] ?? 'newest') : 'newest';
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

$orderSQL = match ($sort) {
    'featured' => 'l.featured DESC, l.created_at DESC',
    'alpha'    => 'l.title ASC',
    'reviewed' => '(SELECT COUNT(*) FROM reviews r WHERE r.listing_id = l.id AND r.status=\'approved\') DESC, l.featured DESC',
    default    => 'l.created_at DESC',
};

// Listings
$listSQL = "SELECT l.*, c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
                   loc.name_en AS loc_en
            FROM listings l
            JOIN categories c ON c.id = l.category_id
            JOIN locations loc ON loc.id = l.location_id
            $whereSQL
            ORDER BY $orderSQL
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
        'sort'     => $_GET['sort'] ?? '',
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

<style>
/* ── Listings layout ─────────────────────────────────── */
.listings-layout { display:grid; grid-template-columns:240px 1fr; gap:2rem; align-items:start; }
@media (max-width:768px) {
  .listings-layout { grid-template-columns:1fr; }
  .listings-sidebar { display:none; }
  .listings-sidebar.open { display:block; }
}
/* ── Sort bar ────────────────────────────────────────── */
.sort-bar { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.sort-bar-label { font-size:0.82rem; color:var(--muted); white-space:nowrap; }
.sort-options { display:flex; gap:0.4rem; flex-wrap:wrap; }
.sort-btn {
  font-size:0.8rem; padding:0.3rem 0.75rem; border-radius:20px; border:1px solid var(--border);
  background:transparent; color:var(--muted); cursor:pointer; text-decoration:none; transition:all .15s;
}
.sort-btn:hover { border-color:var(--green); color:var(--green); }
.sort-btn.active { background:var(--green); border-color:var(--green); color:#fff; font-weight:600; }
/* ── Mobile filter toggle ────────────────────────────── */
.btn-filter-toggle {
  display:none; width:100%; margin-bottom:1rem; padding:0.55rem 1rem; background:var(--card);
  border:1px solid var(--border); border-radius:8px; color:var(--white); cursor:pointer;
  font-size:0.9rem; text-align:left; gap:0.5rem; align-items:center;
}
@media (max-width:768px) { .btn-filter-toggle { display:flex; } }
</style>

<section class="page-section">
  <div class="container">

    <!-- Mobile: filter toggle -->
    <button class="btn-filter-toggle" id="filterToggle" aria-expanded="false" aria-controls="listingsSidebar">
      <span>⚙</span> <?= t('Filters & Search', 'Filtres & Recherche') ?>
      <span id="filterToggleArrow" style="margin-left:auto;">▼</span>
    </button>

    <div class="listings-layout">

      <!-- SIDEBAR FILTERS -->
      <aside class="listings-sidebar" id="listingsSidebar">
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
        <!-- Sort bar -->
        <?php
        $sortLabels = [
            'newest'   => t('Newest',   'Plus récents'),
            'featured' => t('Featured', 'À la une'),
            'alpha'    => t('A–Z',      'A–Z'),
            'reviewed' => t('Most Reviewed', 'Plus avis'),
        ];
        ?>
        <div class="sort-bar">
          <span class="sort-bar-label"><?= $total ?> <?= t('results', 'résultats') ?></span>
          <div class="sort-options">
            <?php foreach ($sortLabels as $key => $label): ?>
              <a href="<?= SITE_URL ?>/listings<?= buildQS(['sort' => $key, 'page' => 1]) ?>"
                 class="sort-btn <?= $sort === $key ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($listings): ?>
          <div class="listings-grid" id="listingsGrid">
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

    </div><!-- .listings-layout -->
  </div>
</section>

<script>
/* ── Mobile sidebar toggle ─────────────────────────── */
(function () {
  var toggle  = document.getElementById('filterToggle');
  var sidebar = document.getElementById('listingsSidebar');
  var arrow   = document.getElementById('filterToggleArrow');
  if (!toggle || !sidebar) return;
  toggle.addEventListener('click', function () {
    var open = sidebar.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open);
    arrow.textContent = open ? '▲' : '▼';
  });
})();

/* ── AJAX live filter + sort ───────────────────────── */
(function () {
  var API      = '<?= SITE_URL ?>/api/listings.php';
  var BASE_URL = '<?= SITE_URL ?>/listings';

  var grid       = document.getElementById('listingsGrid');
  var pagination = document.querySelector('.pagination');
  var sortBtns   = document.querySelectorAll('.sort-btn');
  var countEl    = document.querySelector('.sort-bar-label');

  if (!grid) return;

  // Gather current filter values from the form
  var form = document.querySelector('.listings-sidebar form');

  var state = {
    q:        '<?= addslashes($q) ?>',
    category: '<?= addslashes($catSlug) ?>',
    location: '<?= addslashes($locSlug) ?>',
    sort:     '<?= addslashes($sort) ?>',
    page:     1,
  };

  var debounceTimer = null;
  var currentXhr    = null;

  // ── Skeleton ──────────────────────────────────────
  function skeleton() {
    var html = '';
    for (var i = 0; i < 6; i++) {
      html += '<div class="listing-card" style="pointer-events:none;opacity:0.55;">'
            + '<div class="listing-top"><div class="listing-logo" style="background:var(--border);border-radius:6px;">&nbsp;</div>'
            + '<div><div style="height:14px;width:120px;background:var(--border);border-radius:4px;margin-bottom:8px;"></div>'
            + '<div style="height:11px;width:80px;background:var(--border);border-radius:4px;"></div></div></div>'
            + '<div style="height:12px;background:var(--border);border-radius:4px;margin:12px 0 6px;"></div>'
            + '<div style="height:12px;width:60%;background:var(--border);border-radius:4px;"></div>'
            + '</div>';
    }
    return html;
  }

  // ── Render one card ────────────────────────────────
  function renderCard(l) {
    var isFr = document.documentElement.lang === 'fr';
    var cat  = isFr ? l.cat_fr : l.cat_en;
    var logo = l.logo
      ? '<img src="' + l.logo + '" alt="' + esc(l.title) + '">'
      : l.cat_icon || '🏢';
    var verified = l.verified
      ? '<span class="listing-verified">✓ ' + (isFr ? 'Vérifié' : 'Verified') + '</span>'
      : '';
    var phone = l.phone
      ? '<span class="listing-meta-item">📞 ' + esc(l.phone) + '</span>'
      : '';
    var featured = l.featured ? ' featured' : '';
    return '<a href="' + esc(l.url) + '" class="listing-card' + featured + '" style="text-decoration:none;color:inherit;">'
      + '<div class="listing-top">'
      + '<div class="listing-logo">' + logo + '</div>'
      + '<div><div class="listing-name">' + esc(l.title) + '</div>'
      + '<div class="listing-category">' + esc(cat) + '</div></div>'
      + '</div>'
      + '<p class="listing-desc">' + esc(l.description) + '…</p>'
      + '<div class="listing-meta"><span class="listing-meta-item">📍 ' + esc(l.loc_en) + '</span>' + phone + '</div>'
      + verified
      + '</a>';
  }

  // ── Render pagination ──────────────────────────────
  function renderPagination(data) {
    if (data.pages <= 1) return '';
    var isFr = document.documentElement.lang === 'fr';
    var html = '<div class="pagination">';
    if (data.page > 1) {
      html += '<a href="#" data-page="' + (data.page - 1) + '">← ' + (isFr ? 'Préc' : 'Prev') + '</a>';
    }
    var from = Math.max(1, data.page - 2);
    var to   = Math.min(data.pages, data.page + 2);
    for (var i = from; i <= to; i++) {
      if (i === data.page) {
        html += '<span class="current">' + i + '</span>';
      } else {
        html += '<a href="#" data-page="' + i + '">' + i + '</a>';
      }
    }
    if (data.page < data.pages) {
      html += '<a href="#" data-page="' + (data.page + 1) + '">' + (isFr ? 'Suiv' : 'Next') + ' →</a>';
    }
    html += '</div>';
    return html;
  }

  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Fetch and render ───────────────────────────────
  function fetchListings() {
    if (currentXhr) { currentXhr.abort(); }

    grid.innerHTML = skeleton();
    if (pagination) pagination.remove();

    var params = new URLSearchParams({
      q:        state.q,
      category: state.category,
      location: state.location,
      sort:     state.sort,
      page:     state.page,
    });

    // Sync URL bar
    var urlParams = new URLSearchParams();
    if (state.q)        urlParams.set('q',        state.q);
    if (state.category) urlParams.set('category', state.category);
    if (state.location) urlParams.set('location', state.location);
    if (state.sort && state.sort !== 'newest') urlParams.set('sort', state.sort);
    if (state.page > 1) urlParams.set('page',     state.page);
    var qs = urlParams.toString();
    history.replaceState({}, '', BASE_URL + (qs ? '?' + qs : ''));

    // Sync sort button active state
    sortBtns.forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.sort === state.sort);
    });

    currentXhr = new XMLHttpRequest();
    currentXhr.open('GET', API + '?' + params.toString(), true);
    currentXhr.onload = function () {
      if (currentXhr.status !== 200) {
        grid.innerHTML = '<p style="color:var(--muted);text-align:center;padding:2rem;">'
          + (document.documentElement.lang === 'fr' ? 'Erreur de chargement.' : 'Error loading results.') + '</p>';
        return;
      }
      var data;
      try { data = JSON.parse(currentXhr.responseText); } catch (e) { return; }
      if (!data.ok) return;

      // Update count label
      if (countEl) {
        var isFr = document.documentElement.lang === 'fr';
        countEl.textContent = data.total + ' ' + (isFr ? 'résultats' : 'results');
      }

      if (!data.listings.length) {
        var isFr = document.documentElement.lang === 'fr';
        grid.innerHTML = '<div style="text-align:center;padding:4rem 2rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">'
          + '<div style="font-size:3rem;margin-bottom:1rem;">🔍</div>'
          + '<h3 style="font-family:\'Fraunces\',serif;margin-bottom:0.5rem;">' + (isFr ? 'Aucune entreprise trouvée' : 'No businesses found') + '</h3>'
          + '<p style="color:var(--muted);margin-bottom:1.5rem;">' + (isFr ? "Essayez d'autres termes." : 'Try different search terms or browse all categories.') + '</p>'
          + '<a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary">+ ' + (isFr ? 'Ajouter votre entreprise' : 'Add your business') + '</a>'
          + '</div>';
        return;
      }

      grid.innerHTML = data.listings.map(renderCard).join('');

      // Re-attach pagination
      var pgHtml = renderPagination(data);
      if (pgHtml) {
        var pgDiv = document.createElement('div');
        pgDiv.innerHTML = pgHtml;
        var pgEl = pgDiv.firstChild;
        grid.parentNode.insertBefore(pgEl, grid.nextSibling);
        pagination = pgEl;
        pgEl.addEventListener('click', function (e) {
          var a = e.target.closest('a[data-page]');
          if (!a) return;
          e.preventDefault();
          state.page = parseInt(a.dataset.page, 10);
          fetchListings();
          window.scrollTo({ top: grid.offsetTop - 80, behavior: 'smooth' });
        });
      } else {
        pagination = null;
      }
    };
    currentXhr.send();
  }

  // ── Wire form inputs ───────────────────────────────
  if (form) {
    var qInput   = form.querySelector('input[name="q"]');
    var catSel   = form.querySelector('select[name="category"]');
    var locSel   = form.querySelector('select[name="location"]');
    var clearBtn = form.querySelector('a[href$="/listings"]');

    function readForm() {
      state.q        = qInput  ? qInput.value.trim()  : '';
      state.category = catSel  ? catSel.value          : '';
      state.location = locSel  ? locSel.value          : '';
      state.page     = 1;
    }

    if (qInput) {
      qInput.addEventListener('input', function () {
        readForm();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchListings, 320);
      });
    }
    if (catSel) {
      catSel.addEventListener('change', function () { readForm(); fetchListings(); });
    }
    if (locSel) {
      locSel.addEventListener('change', function () { readForm(); fetchListings(); });
    }

    // Prevent full-page form submit; filter is already live
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      readForm();
      fetchListings();
    });

    // Clear button
    if (clearBtn) {
      clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (qInput)  qInput.value  = '';
        if (catSel)  catSel.value  = '';
        if (locSel)  locSel.value  = '';
        state.q = state.category = state.location = '';
        state.sort = 'newest';
        state.page = 1;
        fetchListings();
      });
    }
  }

  // ── Wire sort buttons ──────────────────────────────
  sortBtns.forEach(function (btn) {
    // Store the sort key on each button so the XHR callback can use it
    var href = btn.getAttribute('href') || '';
    var m = href.match(/sort=([^&]*)/);
    btn.dataset.sort = m ? m[1] : 'newest';

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      state.sort = btn.dataset.sort;
      state.page = 1;
      fetchListings();
    });
  });

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
