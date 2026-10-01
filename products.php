<?php
require_once __DIR__ . '/includes/config.php';

// Filters
$search  = trim($_GET['q'] ?? '');
$tag     = trim($_GET['tag'] ?? '');
$locSlug = trim($_GET['location'] ?? '');
$sort    = in_array($_GET['sort'] ?? '', ['newest','price_asc','price_desc','sale']) ? $_GET['sort'] : 'newest';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$locs = db()->query("SELECT * FROM locations ORDER BY sort_order")->fetchAll();
$activeLoc = $locSlug ? array_values(array_filter($locs, fn($l) => $l['slug'] === $locSlug))[0] ?? null : null;

// Build query
$where  = ["p.in_stock = 1", "l.status = 'approved'", "l.featured = 1"];
$params = [];

if ($search) {
    $where[] = "(p.name LIKE ? OR p.description LIKE ? OR p.category_tag LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($tag) {
    $where[] = "p.category_tag = ?";
    $params[] = $tag;
}
if ($activeLoc) {
    $where[] = "l.location_id = ?";
    $params[] = $activeLoc['id'];
}

$whereStr = implode(' AND ', $where);

$orderBy = match($sort) {
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'sale'       => 'p.sale_price IS NOT NULL DESC, p.sale_price ASC',
    default      => 'p.created_at DESC',
};

$totalSt = db()->prepare("
    SELECT COUNT(*) FROM listing_products p
    JOIN listings l ON l.id = p.listing_id
    WHERE $whereStr
");
$totalSt->execute($params);
$total = (int)$totalSt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$st = db()->prepare("
    SELECT p.*,
           l.title AS biz_name, l.slug AS biz_slug, l.featured AS biz_featured,
           loc.name_en AS loc_name, loc.slug AS loc_slug,
           c.name_en AS cat_name, c.icon AS cat_icon
    FROM listing_products p
    JOIN listings l ON l.id = p.listing_id
    JOIN locations loc ON loc.id = l.location_id
    JOIN categories c ON c.id = l.category_id
    WHERE $whereStr
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
");
$st->execute($params);
$products = $st->fetchAll();

// All distinct tags for filter pills
$tags = db()->query("
    SELECT DISTINCT p.category_tag
    FROM listing_products p
    JOIN listings l ON l.id = p.listing_id
    WHERE p.category_tag IS NOT NULL AND p.category_tag != '' AND l.status='approved' AND l.featured=1
    ORDER BY p.category_tag
")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = t('Products & Services — 237Biz', 'Produits & Services — 237Biz');
$pageDesc  = t('Browse products and services from verified Cameroonian businesses on 237Biz.', 'Parcourez les produits et services des entreprises camerounaises vérifiées sur 237Biz.');
if ($search) $pageTitle = t('Search: ','Recherche : ') . e($search) . ' — 237Biz';

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <span><?= t('Products & Services','Produits & Services') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(2rem,4vw,3rem);">
      🛍️ <?= t('Products & Services','Produits & Services') ?>
    </h1>
    <p style="color:var(--muted);margin-top:0.5rem;">
      <?= $total ?> <?= t('items from featured businesses','articles d\'entreprises vedettes') ?>
    </p>
  </div>
</div>

<section class="page-section">
  <div class="container">

    <!-- Search + filters bar -->
    <form method="GET" action="<?= SITE_URL ?>/products" style="margin-bottom:1.5rem;">
      <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
        <div style="flex:1;min-width:200px;position:relative;">
          <input type="text" name="q" value="<?= e($search) ?>"
                 placeholder="<?= t('Search products & services...','Rechercher produits & services...') ?>"
                 style="width:100%;background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.65rem 0.75rem 0.65rem 2.4rem;font-size:0.9rem;">
          <span style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--muted);">🔍</span>
        </div>
        <select name="location" style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.65rem 0.75rem;font-size:0.875rem;">
          <option value=""><?= t('All Locations','Toutes les villes') ?></option>
          <?php foreach ($locs as $l): ?>
            <option value="<?= e($l['slug']) ?>" <?= $locSlug === $l['slug'] ? 'selected' : '' ?>><?= e($l['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="sort" style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.65rem 0.75rem;font-size:0.875rem;">
          <option value="newest" <?= $sort==='newest' ? 'selected' : '' ?>><?= t('Newest','Plus récent') ?></option>
          <option value="price_asc" <?= $sort==='price_asc' ? 'selected' : '' ?>><?= t('Price: Low to High','Prix : croissant') ?></option>
          <option value="price_desc" <?= $sort==='price_desc' ? 'selected' : '' ?>><?= t('Price: High to Low','Prix : décroissant') ?></option>
          <option value="sale" <?= $sort==='sale' ? 'selected' : '' ?>><?= t('On Sale','En promotion') ?></option>
        </select>
        <?php if ($tag): ?><input type="hidden" name="tag" value="<?= e($tag) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm"><?= t('Search','Rechercher') ?></button>
        <?php if ($search || $tag || $locSlug || $sort !== 'newest'): ?>
          <a href="<?= SITE_URL ?>/products" class="btn btn-outline btn-sm"><?= t('Clear','Effacer') ?></a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Tag pills -->
    <?php if ($tags): ?>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1.5rem;">
        <a href="<?= SITE_URL ?>/products<?= $search ? '?q=' . urlencode($search) : '' ?>"
           class="btn btn-sm <?= !$tag ? 'btn-primary' : 'btn-outline' ?>" style="font-size:0.78rem;">
          <?= t('All','Tout') ?>
        </a>
        <?php foreach ($tags as $t): ?>
          <a href="<?= SITE_URL ?>/products?tag=<?= urlencode($t) ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
             class="btn btn-sm <?= $tag === $t ? 'btn-primary' : 'btn-outline' ?>" style="font-size:0.78rem;">
            <?= e($t) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Product grid -->
    <?php if ($products): ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1.25rem;margin-bottom:2rem;">
        <?php foreach ($products as $prod): ?>
          <a href="<?= SITE_URL ?>/product/<?= $prod['id'] ?>"
             style="text-decoration:none;color:inherit;display:flex;flex-direction:column;background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:border-color 0.2s;"
             onmouseover="this.style.borderColor='rgba(0,168,120,0.3)'" onmouseout="this.style.borderColor='var(--border)'">

            <!-- Image -->
            <div style="height:180px;background:rgba(255,255,255,0.04);position:relative;overflow:hidden;flex-shrink:0;">
              <?php if ($prod['image']): ?>
                <img src="<?= e(UPLOAD_URL . $prod['image']) ?>" alt="<?= e($prod['name']) ?>"
                     style="width:100%;height:100%;object-fit:cover;">
              <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:3rem;color:var(--muted-2);">🛍️</div>
              <?php endif; ?>
              <?php if ($prod['sale_price']): ?>
                <span style="position:absolute;top:10px;left:10px;background:#e63946;color:#fff;font-size:0.65rem;font-weight:600;padding:0.2rem 0.6rem;border-radius:20px;text-transform:uppercase;letter-spacing:0.05em;">
                  SALE
                </span>
              <?php endif; ?>
              <?php if ($prod['category_tag']): ?>
                <span style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,0.55);color:rgba(255,255,255,0.8);font-size:0.65rem;padding:0.2rem 0.6rem;border-radius:20px;">
                  <?= e($prod['category_tag']) ?>
                </span>
              <?php endif; ?>
            </div>

            <!-- Content -->
            <div style="padding:1rem;flex:1;display:flex;flex-direction:column;">
              <div style="font-weight:500;color:var(--white);font-size:0.9rem;margin-bottom:0.35rem;line-height:1.3;">
                <?= e($prod['name']) ?>
              </div>
              <?php if ($prod['description']): ?>
                <p style="font-size:0.78rem;color:var(--muted);line-height:1.5;flex:1;margin-bottom:0.75rem;">
                  <?= e(mb_substr($prod['description'], 0, 90)) ?><?= mb_strlen($prod['description']) > 90 ? '...' : '' ?>
                </p>
              <?php endif; ?>

              <!-- Price -->
              <div style="display:flex;align-items:baseline;gap:0.5rem;margin-bottom:0.6rem;">
                <?php if ($prod['sale_price']): ?>
                  <span style="font-family:'Fraunces',serif;font-weight:700;font-size:1.1rem;color:#00A878;">
                    <?= number_format($prod['sale_price']) ?> XAF
                  </span>
                  <span style="font-size:0.8rem;color:var(--muted-2);text-decoration:line-through;">
                    <?= number_format($prod['price']) ?>
                  </span>
                  <span style="font-size:0.7rem;color:#e63946;font-weight:500;">
                    -<?= round((1 - $prod['sale_price'] / $prod['price']) * 100) ?>%
                  </span>
                <?php else: ?>
                  <span style="font-family:'Fraunces',serif;font-weight:700;font-size:1.1rem;color:var(--yellow);">
                    <?= number_format($prod['price']) ?> XAF
                  </span>
                <?php endif; ?>
              </div>

              <!-- Business link -->
              <div style="font-size:0.75rem;color:var(--muted-2);display:flex;align-items:center;gap:0.35rem;">
                <span><?= e($prod['cat_icon']) ?></span>
                <span><?= e($prod['biz_name']) ?></span>
                <span>·</span>
                <span>📍 <?= e($prod['loc_name']) ?></span>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div style="display:flex;justify-content:center;gap:0.5rem;flex-wrap:wrap;">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php
            $pqs = array_filter(['q' => $search, 'tag' => $tag, 'location' => $locSlug, 'sort' => $sort !== 'newest' ? $sort : '', 'page' => $i > 1 ? $i : '']);
            $pqs = array_filter($pqs);
            ?>
            <a href="<?= SITE_URL ?>/products<?= $pqs ? '?' . http_build_query($pqs) : '' ?>"
               class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <div style="text-align:center;padding:4rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
        <div style="font-size:3rem;margin-bottom:1rem;">🛍️</div>
        <h3 style="font-family:'Fraunces',serif;margin-bottom:0.5rem;"><?= t('No products found','Aucun produit trouvé') ?></h3>
        <p style="color:var(--muted);font-size:0.875rem;"><?= t('Try a different search or browse all listings.','Essayez une autre recherche ou parcourez toutes les annonces.') ?></p>
        <a href="<?= SITE_URL ?>/listings" class="btn btn-primary" style="margin-top:1.25rem;"><?= t('Browse Businesses','Parcourir les Entreprises') ?></a>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
