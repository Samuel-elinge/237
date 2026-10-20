<?php
/**
 * offers.php — 237Biz Browse All Deals & Special Offers
 */
require_once __DIR__ . '/includes/config.php';

$isFr = lang() === 'fr';

// ── Filters ───────────────────────────────────────────────────────────────
$catSlug  = trim($_GET['category'] ?? '');
$locSlug  = trim($_GET['location'] ?? '');
$type     = trim($_GET['type'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 12;

// Build WHERE clauses
$where  = ["o.status = 'active'", "o.starts_at <= NOW()", "(o.expires_at IS NULL OR o.expires_at > NOW())"];
$params = [];

if ($catSlug) {
    $where[]  = 'c.slug = ?';
    $params[] = $catSlug;
}
if ($locSlug) {
    $where[]  = 'loc.slug = ?';
    $params[] = $locSlug;
}
if ($type && in_array($type, ['percent_off','fixed_off','bogo','free_item','bundle'])) {
    $where[]  = 'o.offer_type = ?';
    $params[] = $type;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── Categories & locations for filters ───────────────────────────────────
try {
    $cats = db()->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();
    $locs = db()->query("SELECT * FROM locations  ORDER BY sort_order")->fetchAll();
} catch (Exception $e) { $cats = []; $locs = []; }

// ── Count ─────────────────────────────────────────────────────────────────
$total = 0;
try {
    $cStmt = db()->prepare("
        SELECT COUNT(*) FROM offers o
        JOIN listings  l   ON l.id  = o.listing_id
        JOIN categories c  ON c.id  = l.category_id
        JOIN locations  loc ON loc.id = l.location_id
        $whereSQL
    ");
    $cStmt->execute($params);
    $total = (int)$cStmt->fetchColumn();
} catch (Exception $e) {}

$totalPages = $total ? (int)ceil($total / $perPage) : 0;
$offset     = ($page - 1) * $perPage;

// ── Offers ────────────────────────────────────────────────────────────────
$offers = [];
try {
    $stmt = db()->prepare("
        SELECT o.*, l.title AS biz_name, l.slug AS biz_slug, l.logo,
               c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
               c.slug AS cat_slug,
               loc.name_en AS loc_en, loc.name_fr AS loc_fr, loc.slug AS loc_slug
        FROM offers o
        JOIN listings  l   ON l.id  = o.listing_id
        JOIN categories c  ON c.id  = l.category_id
        JOIN locations  loc ON loc.id = l.location_id
        $whereSQL
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $offers = $stmt->fetchAll();
} catch (Exception $e) { $offers = []; }

// ── Page meta ─────────────────────────────────────────────────────────────
$pageTitle = t('Deals & Special Offers in Cameroon — 237Biz',
               'Offres Spéciales au Cameroun — 237Biz');
$pageDesc  = t('Browse exclusive deals, discounts and special offers from verified Cameroonian businesses.',
               'Découvrez des offres exclusives et des réductions de commerces camerounais vérifiés.');

require_once __DIR__ . '/includes/header.php';

// Helper: build filter URL
function offersUrl(array $overrides = []): string {
    global $catSlug, $locSlug, $type;
    $base = ['category' => $catSlug, 'location' => $locSlug, 'type' => $type];
    $p    = array_filter(array_merge($base, $overrides));
    return SITE_URL . '/offers' . ($p ? '?' . http_build_query($p) : '');
}
?>
<style>
.offers-page-hero {
  background: linear-gradient(160deg,#04220c,#081410);
  padding: 2.5rem 0 2rem; text-align: center;
}
.offers-page-hero h1 {
  font-family:'Fraunces',serif; font-weight:900;
  font-size:clamp(1.6rem,4vw,2.4rem); color:#f0faf5; margin-bottom:0.4rem;
}
.offers-page-hero p { color:#8db8a8; font-size:0.95rem; }

/* filter bar */
.offers-filters {
  background: rgba(0,168,120,0.04);
  border-bottom: 1px solid rgba(0,168,120,0.12);
  padding: 0.75rem 0; overflow-x: auto;
}
.offers-filters-inner {
  display: flex; gap: 0.5rem; align-items: center;
  min-width: max-content; padding: 0 1rem;
}
.filter-label { font-size:0.72rem; font-weight:700; letter-spacing:0.05em;
  color:#5a8878; text-transform:uppercase; padding-right:0.25rem; }
.filter-pill {
  padding:0.3rem 0.8rem; border-radius:99px;
  border:1px solid rgba(0,168,120,0.2);
  color:#8db8a8; font-size:0.78rem; font-weight:600;
  text-decoration:none; white-space:nowrap; transition:all 0.15s;
}
.filter-pill:hover, .filter-pill.active {
  background:rgba(0,168,120,0.12); border-color:rgba(0,168,120,0.5); color:#00A878;
}
.filter-sep { color:rgba(0,168,120,0.2); padding:0 0.1rem; }

/* grid */
.offers-grid {
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(270px,1fr));
  gap:16px; padding:2rem 0;
}
/* reuse offer-card styles from marketplace.php */
.offer-card {
  background:rgba(255,255,255,0.03);
  border:1px solid rgba(0,168,120,0.15); border-radius:12px;
  overflow:hidden; text-decoration:none; display:flex; flex-direction:column;
  transition:border-color 0.15s, transform 0.12s;
}
.offer-card:hover { border-color:rgba(0,168,120,0.4); transform:translateY(-2px); }
.offer-img { aspect-ratio:16/7; overflow:hidden;
  background:linear-gradient(135deg,rgba(0,168,120,0.12),rgba(0,168,120,0.04)); position:relative; }
.offer-img img { width:100%; height:100%; object-fit:cover; }
.offer-badge { position:absolute; top:8px; left:8px;
  background:#00A878; color:#fff; font-size:0.72rem; font-weight:800;
  padding:0.2rem 0.55rem; border-radius:99px; }
.offer-badge.bogo   { background:#7c3aed; }
.offer-badge.bundle { background:#0369a1; }
.offer-body { padding:0.9rem 1rem; flex:1; display:flex; flex-direction:column; gap:0.3rem; }
.offer-biz  { font-size:0.74rem; color:#5a8878; }
.offer-title { font-weight:700; color:#e8f5ee; font-size:0.95rem; line-height:1.3; }
.offer-desc  { font-size:0.8rem; color:#8db8a8; flex:1; line-height:1.45; }
.offer-meta  { display:flex; align-items:center; justify-content:space-between; margin-top:0.5rem; }
.offer-expires { font-size:0.72rem; color:#5a8878; }
.offer-cta  { font-size:0.78rem; font-weight:700; color:#00A878;
  background:rgba(0,168,120,0.1); border-radius:99px; padding:0.2rem 0.7rem; }

/* pagination */
.mp-pagination { display:flex; justify-content:center; gap:0.4rem; padding:1rem 0 2rem; flex-wrap:wrap; }
.mp-pagination a, .mp-pagination span {
  padding:0.4rem 0.85rem; border-radius:8px;
  border:1px solid rgba(0,168,120,0.2);
  font-size:0.85rem; font-weight:600;
  text-decoration:none; color:#8db8a8;
}
.mp-pagination a:hover { border-color:rgba(0,168,120,0.5); color:#00A878; }
.mp-pagination .cur { background:rgba(0,168,120,0.15); border-color:#00A878; color:#00A878; }
.mp-pagination .disabled { opacity:0.4; pointer-events:none; }

.mp-empty { text-align:center; padding:4rem 1rem; color:#5a8878; }
.mp-empty span { font-size:2.5rem; display:block; margin-bottom:0.75rem; }
.mp-empty h3 { font-family:'Fraunces',serif; font-weight:700; color:#8db8a8; margin-bottom:0.5rem; }
</style>

<!-- Hero -->
<div class="offers-page-hero">
  <div class="container">
    <h1>🏷️ <?= t('Deals & Special Offers','Offres Spéciales') ?></h1>
    <p><?= t('Exclusive discounts from verified Cameroonian businesses.',
             'Réductions exclusives d\'entreprises camerounaises vérifiées.') ?></p>
  </div>
</div>

<!-- Filters -->
<div class="offers-filters">
  <div class="offers-filters-inner">
    <span class="filter-label"><?= t('Type:','Type :') ?></span>
    <a href="<?= offersUrl(['type'=>'']) ?>"          class="filter-pill <?= !$type ? 'active':'' ?>"><?= t('All','Tous') ?></a>
    <a href="<?= offersUrl(['type'=>'percent_off']) ?>" class="filter-pill <?= $type==='percent_off'?'active':'' ?>">% <?= t('Off','Rabais') ?></a>
    <a href="<?= offersUrl(['type'=>'fixed_off']) ?>"  class="filter-pill <?= $type==='fixed_off'?'active':'' ?>">XAF <?= t('Discount','Réduction') ?></a>
    <a href="<?= offersUrl(['type'=>'bogo']) ?>"       class="filter-pill <?= $type==='bogo'?'active':'' ?>">BOGO</a>
    <a href="<?= offersUrl(['type'=>'free_item']) ?>"  class="filter-pill <?= $type==='free_item'?'active':'' ?>"><?= t('Free Item','Cadeau') ?></a>
    <a href="<?= offersUrl(['type'=>'bundle']) ?>"     class="filter-pill <?= $type==='bundle'?'active':'' ?>">Bundle</a>

    <span class="filter-sep">|</span>
    <span class="filter-label"><?= t('City:','Ville :') ?></span>
    <a href="<?= offersUrl(['location'=>'']) ?>" class="filter-pill <?= !$locSlug?'active':'' ?>"><?= t('All','Toutes') ?></a>
    <?php foreach ($locs as $loc): ?>
      <a href="<?= offersUrl(['location'=>$loc['slug']]) ?>"
         class="filter-pill <?= $locSlug===$loc['slug']?'active':'' ?>">
        <?= htmlspecialchars($isFr ? $loc['name_fr'] : $loc['name_en'], ENT_QUOTES, 'UTF-8') ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="container">
  <!-- result count -->
  <?php if ($total): ?>
    <p style="font-size:0.82rem;color:#5a8878;padding-top:1.25rem;">
      <?= $total ?> <?= t('deal(s) found','offre(s) trouvée(s)') ?>
      <?php if ($catSlug || $locSlug || $type): ?>
        — <a href="<?= SITE_URL ?>/offers" style="color:#00A878;"><?= t('Clear filters','Effacer les filtres') ?></a>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if ($offers): ?>
    <div class="offers-grid">
    <?php foreach ($offers as $o):
      $offerTitle = $isFr && $o['title_fr'] ? $o['title_fr'] : $o['title'];
      $offerDesc  = $isFr && $o['description_fr'] ? $o['description_fr'] : ($o['description'] ?? '');
      $badgeLabel = ''; $badgeClass = '';
      switch ($o['offer_type']) {
          case 'percent_off': $badgeLabel = '-'.(int)$o['discount_value'].'%'; break;
          case 'fixed_off':   $badgeLabel = number_format($o['discount_value']).' XAF '.t('off','rabais'); break;
          case 'bogo':        $badgeLabel = 'BOGO'; $badgeClass = 'bogo'; break;
          case 'free_item':   $badgeLabel = t('Free Item','Cadeau'); break;
          case 'bundle':      $badgeLabel = 'Bundle'; $badgeClass = 'bundle'; break;
      }
    ?>
      <a href="<?= SITE_URL ?>/offer/<?= htmlspecialchars($o['slug'], ENT_QUOTES, 'UTF-8') ?>" class="offer-card">
        <div class="offer-img">
          <?php if ($o['image']): ?>
            <img src="<?= UPLOAD_URL . htmlspecialchars($o['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($offerTitle, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
          <?php endif; ?>
          <?php if ($badgeLabel): ?>
            <span class="offer-badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>
        <div class="offer-body">
          <div class="offer-biz"><?= htmlspecialchars($o['biz_name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="offer-title"><?= htmlspecialchars($offerTitle, ENT_QUOTES, 'UTF-8') ?></div>
          <?php if ($offerDesc): ?>
            <div class="offer-desc"><?= htmlspecialchars(mb_substr(strip_tags($offerDesc), 0, 80), ENT_QUOTES, 'UTF-8') ?>…</div>
          <?php endif; ?>
          <div class="offer-meta">
            <span class="offer-expires">
              <?php if ($o['expires_at']): ?>
                ⏰ <?= t('Ends','Fin') ?> <?= date('d M Y', strtotime($o['expires_at'])) ?>
              <?php else: ?>
                ✅ <?= t('No expiry','Sans expiration') ?>
              <?php endif; ?>
            </span>
            <span class="offer-cta"><?= t('View Deal','Voir l\'offre') ?> →</span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="mp-pagination">
        <?php if ($page > 1): ?>
          <a href="<?= offersUrl(['page' => $page - 1]) ?>">‹ <?= t('Prev','Préc.') ?></a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <?php if (abs($p - $page) <= 2 || $p == 1 || $p == $totalPages): ?>
            <?php if (abs($p - $page) > 2 && $p != 1): ?>
              <span class="disabled">…</span>
            <?php endif; ?>
            <?php if ($p == $page): ?>
              <span class="cur"><?= $p ?></span>
            <?php else: ?>
              <a href="<?= offersUrl(['page' => $p]) ?>"><?= $p ?></a>
            <?php endif; ?>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="<?= offersUrl(['page' => $page + 1]) ?>"><?= t('Next','Suiv.') ?> ›</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <div class="mp-empty">
      <span>🏷️</span>
      <h3><?= t('No deals match your filters','Aucune offre ne correspond') ?></h3>
      <p><a href="<?= SITE_URL ?>/offers" style="color:#00A878;"><?= t('Clear filters','Effacer les filtres') ?></a></p>
    </div>
  <?php endif; ?>

  <!-- Partner CTA -->
  <div style="background:rgba(0,168,120,0.04);border:1px solid rgba(0,168,120,0.15);border-radius:12px;padding:1.5rem;text-align:center;margin-bottom:2rem;">
    <strong style="color:#e8f5ee;"><?= t('Running a promotion?','Vous avez une promotion ?') ?></strong>
    <span style="color:#8db8a8;margin:0 0.5rem;">·</span>
    <a href="<?= SITE_URL ?>/partner/offers" style="color:#00A878;font-weight:700;">
      + <?= t('Post your deal free →','Publiez votre offre gratuitement →') ?>
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
