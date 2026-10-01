<?php
/**
 * reviews.php — 237Biz Customer Reviews
 * URL: /reviews
 * Schema: reviews(id, listing_id, user_id, name, rating, comment, status, created_at)
 */
require_once __DIR__ . '/includes/config.php';

$isFr   = lang() === 'fr';
$cu     = isLoggedIn() ? currentUser() : null;
$errors = [];
$done   = false;

// ── Write a Review POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'write_review') {
    verifyCsrf();

    $listingId  = (int)($_POST['listing_id'] ?? 0);
    $rating     = (int)($_POST['rating']     ?? 0);
    $comment    = trim($_POST['comment']     ?? '');
    $reviewName = trim($_POST['reviewer_name'] ?? ($cu['name'] ?? ''));

    if (!$listingId)                    $errors[] = t('Please select a business.','Veuillez sélectionner une entreprise.');
    if ($rating < 1 || $rating > 5)    $errors[] = t('Please select a rating (1–5 stars).','Veuillez sélectionner une note (1–5 étoiles).');
    if (strlen($comment) < 10)         $errors[] = t('Review must be at least 10 characters.','L\'avis doit comporter au moins 10 caractères.');
    if (!$reviewName)                  $errors[] = t('Please enter your name.','Veuillez entrer votre nom.');

    if (!$errors) {
        // Check listing exists and is approved
        $chk = db()->prepare("SELECT id, title FROM listings WHERE id=? AND status='approved'");
        $chk->execute([$listingId]);
        $listing = $chk->fetch();

        if (!$listing) {
            $errors[] = t('Business not found.','Entreprise introuvable.');
        } else {
            // Duplicate check — same user/name + listing in last 7 days
            $dupSql = $cu
                ? "SELECT id FROM reviews WHERE listing_id=? AND user_id=? AND created_at > DATE_SUB(NOW(),INTERVAL 7 DAY)"
                : "SELECT id FROM reviews WHERE listing_id=? AND name=? AND created_at > DATE_SUB(NOW(),INTERVAL 7 DAY)";
            $dup = db()->prepare($dupSql);
            $dup->execute($cu ? [$listingId, $cu['id']] : [$listingId, $reviewName]);

            if ($dup->fetch()) {
                $errors[] = t('You\'ve already reviewed this business recently. Please wait 7 days before reviewing again.','Vous avez déjà évalué cette entreprise récemment. Veuillez attendre 7 jours avant d\'évaluer à nouveau.');
            } else {
                db()->prepare("INSERT INTO reviews (listing_id, user_id, name, rating, comment, status)
                               VALUES (?, ?, ?, ?, ?, 'pending')")
                    ->execute([$listingId, $cu['id'] ?? null, $reviewName, $rating, $comment]);
                $done = true;
            }
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────
$sort       = in_array($_GET['sort'] ?? '', ['latest','top','rating_5','rating_4','rating_3']) ? $_GET['sort'] : 'latest';
$filterCat  = (int)($_GET['category']  ?? 0);
$filterCity = (int)($_GET['location']  ?? 0);
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 12;
$offset     = ($page - 1) * $perPage;

$where  = ["r.status='approved'"];
$params = [];
if ($filterCat)  { $where[] = "l.category_id=?";  $params[] = $filterCat; }
if ($filterCity) { $where[] = "l.location_id=?";  $params[] = $filterCity; }
if ($sort === 'rating_5') { $where[] = "r.rating=5"; }
if ($sort === 'rating_4') { $where[] = "r.rating=4"; }
if ($sort === 'rating_3') { $where[] = "r.rating<=3"; }
$whereStr = implode(' AND ', $where);

$orderBy = match($sort) {
    'top'               => 'r.rating DESC, r.created_at DESC',
    'rating_5','rating_4','rating_3' => 'r.created_at DESC',
    default             => 'r.created_at DESC',
};

// Reviews
$reviews = db()->prepare("
    SELECT r.id, r.name, r.rating, r.comment, r.created_at, r.user_id,
           l.id AS listing_id, l.title AS listing_title, l.slug AS listing_slug, l.logo,
           l.verified, l.featured,
           c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
           loc.name_en AS city
    FROM reviews r
    JOIN listings l   ON l.id = r.listing_id
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    WHERE {$whereStr}
    ORDER BY {$orderBy}
    LIMIT {$perPage} OFFSET {$offset}
");
$reviews->execute($params);
$reviews = $reviews->fetchAll();

// Total count for pagination
$countRow = db()->prepare("
    SELECT COUNT(*) FROM reviews r
    JOIN listings l ON l.id=r.listing_id
    WHERE {$whereStr}
");
$countRow->execute($params);
$totalReviews = (int)$countRow->fetchColumn();
$totalPages   = (int)ceil($totalReviews / $perPage);

// Stats
$stats = db()->query("
    SELECT
        COUNT(*) AS total,
        ROUND(AVG(rating),1) AS avg_rating,
        SUM(rating=5) AS five_star,
        SUM(rating=4) AS four_star,
        SUM(rating=3) AS three_star,
        SUM(rating=2) AS two_star,
        SUM(rating=1) AS one_star
    FROM reviews WHERE status='approved'
")->fetch();

// Top-rated listings (for sidebar)
$topListings = db()->query("
    SELECT l.title, l.slug, l.logo,
           c.icon AS cat_icon,
           loc.name_en AS city,
           ROUND(AVG(r.rating),1) AS avg_rating,
           COUNT(r.id) AS review_count
    FROM reviews r
    JOIN listings l   ON l.id=r.listing_id
    JOIN categories c ON c.id=l.category_id
    JOIN locations loc ON loc.id=l.location_id
    WHERE r.status='approved' AND l.status='approved'
    GROUP BY l.id
    HAVING review_count >= 1
    ORDER BY avg_rating DESC, review_count DESC
    LIMIT 5
")->fetchAll();

// Listings dropdown for write-review form
$allListings = db()->query("
    SELECT id, title, c.name_en AS cat_en, loc.name_en AS city
    FROM listings l
    JOIN categories c  ON c.id=l.category_id
    JOIN locations loc ON loc.id=l.location_id
    WHERE l.status='approved'
    ORDER BY l.featured DESC, l.title ASC
    LIMIT 500
")->fetchAll();

// Categories & locations for filter
$cats = db()->query("SELECT id, name_en, name_fr, icon FROM categories ORDER BY sort_order")->fetchAll();
$locs = db()->query("SELECT id, name_en FROM locations ORDER BY sort_order")->fetchAll();

$pageTitle = $isFr
    ? 'Avis Clients — Entreprises Camerounaises — 237Biz'
    : 'Customer Reviews — Cameroon Businesses — 237Biz';
$pageDesc = $isFr
    ? 'Lisez les vrais avis clients sur les entreprises camerounaises. Notez et évaluez les restaurants, hôtels, services et plus à Douala, Yaoundé, Limbe et partout au Cameroun.'
    : 'Read real customer reviews of Cameroonian businesses. Rate and review restaurants, hotels, services and more in Douala, Yaoundé, Limbe and across Cameroon.';

require_once __DIR__ . '/includes/header.php';
?>

<!-- SEO schema -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": "<?= e($pageTitle) ?>",
  "description": "<?= e($pageDesc) ?>",
  "url": "<?= SITE_URL ?>/reviews"
}
</script>

<style>
/* ── Page header ── */
.rv-hero {
  background:linear-gradient(150deg,#05280F,#081C10);
  padding:60px 0 48px; text-align:center;
}
.rv-hero h1 { font-family:'Fraunces',serif; font-weight:900; font-size:clamp(1.8rem,4vw,2.8rem); color:#fff; margin-bottom:10px; }
.rv-hero p  { color:rgba(255,255,255,0.6); font-size:15px; max-width:520px; margin:0 auto; }

/* ── Rating summary ── */
.rating-summary {
  display:flex; align-items:center; gap:32px; flex-wrap:wrap;
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:16px; padding:24px 28px; margin-bottom:28px;
}
.rating-big   { text-align:center; flex-shrink:0; }
.rating-big .num  { font-family:'Fraunces',serif; font-weight:900; font-size:3.5rem; color:#fcd116; line-height:1; }
.rating-big .stars { color:#fcd116; font-size:1.1rem; letter-spacing:2px; margin:4px 0; }
.rating-big .total { font-size:12px; color:rgba(255,255,255,0.5); }
.rating-bars  { flex:1; min-width:180px; display:flex; flex-direction:column; gap:7px; }
.rating-row   { display:flex; align-items:center; gap:8px; font-size:12.5px; }
.rating-row .label { color:rgba(255,255,255,0.6); white-space:nowrap; width:42px; }
.rating-row .bar   { flex:1; height:7px; background:rgba(255,255,255,0.07); border-radius:4px; overflow:hidden; }
.rating-row .fill  { height:100%; background:#fcd116; border-radius:4px; transition:width .4s; }
.rating-row .count { color:rgba(255,255,255,0.5); width:28px; text-align:right; }

/* ── Filters ── */
.rv-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
.rv-filter-btn {
  padding:7px 16px; border-radius:8px; font-size:13px; font-weight:600;
  text-decoration:none; border:1px solid rgba(255,255,255,0.12);
  color:rgba(255,255,255,0.65); transition:all .15s; background:rgba(255,255,255,0.03);
}
.rv-filter-btn:hover,
.rv-filter-btn.active { background:rgba(0,168,120,0.15); border-color:rgba(0,168,120,0.4); color:#00A878; }
.rv-select {
  padding:7px 12px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1);
  border-radius:8px; color:rgba(255,255,255,0.75); font-size:13px; font-family:inherit; cursor:pointer;
}
.rv-select option { background:#0e2a18; }

/* ── Review cards ── */
.rv-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:16px; }
.rv-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:16px; padding:22px 24px; display:flex; flex-direction:column; gap:10px;
  transition:border-color .2s;
}
.rv-card:hover { border-color:rgba(255,255,255,0.16); }
.rv-stars  { color:#fcd116; font-size:13px; letter-spacing:1px; }
.rv-text   { font-size:14px; color:rgba(255,255,255,0.8); line-height:1.7; font-style:italic; flex:1; }
.rv-text::before { content:'"'; }
.rv-text::after  { content:'"'; }
.rv-meta   { padding-top:10px; border-top:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; gap:10px; }
.rv-avatar { width:34px; height:34px; border-radius:50%; background:rgba(0,168,120,0.2); color:#00A878; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; flex-shrink:0; }
.rv-reviewer { font-size:13px; font-weight:700; color:#fff; }
.rv-biz-link { font-size:12px; color:rgba(255,255,255,0.45); text-decoration:none; transition:color .15s; display:block; margin-top:2px; }
.rv-biz-link:hover { color:#00A878; }
.rv-date { font-size:11px; color:rgba(255,255,255,0.3); margin-left:auto; flex-shrink:0; }

/* ── Write review form ── */
.rv-form-card {
  background:rgba(0,168,120,0.04); border:1px solid rgba(0,168,120,0.2);
  border-radius:18px; padding:32px;
}
.rv-form-card h2 { font-family:'Fraunces',serif; font-weight:900; font-size:1.4rem; margin-bottom:6px; }
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:600; color:rgba(255,255,255,0.7); margin-bottom:6px; }
.form-group input,
.form-group select,
.form-group textarea {
  width:100%; padding:11px 14px; background:rgba(255,255,255,0.05);
  border:1px solid rgba(255,255,255,0.1); border-radius:10px;
  color:#fff; font-size:14px; font-family:inherit; transition:border-color .15s;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline:none; border-color:rgba(0,168,120,0.5); background:rgba(255,255,255,0.07); }
.form-group select option { background:#0e2a18; }

/* Star picker */
.star-picker { display:flex; gap:4px; flex-direction:row-reverse; justify-content:flex-end; }
.star-picker input { display:none; }
.star-picker label {
  font-size:2rem; cursor:pointer; color:rgba(255,255,255,0.2); transition:color .15s;
  padding:0 2px; background:none; border:none; width:auto;
}
.star-picker label:hover,
.star-picker label:hover ~ label,
.star-picker input:checked ~ label { color:#fcd116; }

/* Sidebar */
.rv-sidebar { display:flex; flex-direction:column; gap:20px; }
.sidebar-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:14px; padding:20px;
}
.sidebar-card h3 { font-size:14px; font-weight:700; margin-bottom:14px; color:rgba(255,255,255,0.85); }
.top-biz-item { display:flex; gap:10px; align-items:center; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.05); }
.top-biz-item:last-child { border-bottom:none; }
.top-biz-logo { width:36px; height:36px; border-radius:8px; background:rgba(255,255,255,0.05); display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; overflow:hidden; }
.top-biz-logo img { width:100%; height:100%; object-fit:cover; border-radius:8px; }
.top-biz-name  { font-size:13px; font-weight:600; color:#fff; text-decoration:none; display:block; }
.top-biz-name:hover { color:#00A878; }
.top-biz-meta  { font-size:11.5px; color:rgba(255,255,255,0.45); }

/* Pagination */
.rv-pagination { display:flex; gap:6px; justify-content:center; flex-wrap:wrap; margin-top:28px; }
.rv-page-btn {
  padding:7px 14px; border-radius:8px; font-size:13px; font-weight:600;
  text-decoration:none; border:1px solid rgba(255,255,255,0.1);
  color:rgba(255,255,255,0.65); background:rgba(255,255,255,0.03); transition:all .15s;
}
.rv-page-btn:hover { border-color:rgba(0,168,120,0.4); color:#00A878; }
.rv-page-btn.active { background:rgba(0,168,120,0.15); border-color:rgba(0,168,120,0.5); color:#00A878; }

@media(max-width:720px){ .rv-layout { display:block !important; } .rv-sidebar { margin-top:28px; } }
</style>

<!-- Hero -->
<div class="rv-hero">
  <div class="container">
    <div style="display:inline-block;background:rgba(252,209,22,0.1);color:#fcd116;border:1px solid rgba(252,209,22,0.25);border-radius:99px;padding:4px 14px;font-size:12.5px;font-weight:700;margin-bottom:16px;">
      ⭐ <?= t('Customer Reviews','Avis Clients') ?>
    </div>
    <h1><?= t('See what customers are saying','Découvrez ce que disent les clients') ?></h1>
    <p><?= t('Real reviews from real customers across Cameroon. Honest, verified, helpful.','Vrais avis de vrais clients à travers le Cameroun. Honnêtes, vérifiés, utiles.') ?></p>
  </div>
</div>

<section class="page-section">
<div class="container">

<?php if ($done): ?>
<!-- Success message -->
<div style="background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.3);border-radius:12px;padding:20px 24px;margin-bottom:28px;display:flex;gap:14px;align-items:flex-start;">
  <span style="font-size:1.5rem;">✅</span>
  <div>
    <div style="font-weight:700;color:#fff;margin-bottom:4px;"><?= t('Review submitted — thank you!','Avis soumis — merci !') ?></div>
    <div style="font-size:13.5px;color:rgba(255,255,255,0.65);"><?= t('Your review is pending approval and will appear on the listing once our team verifies it. This usually takes less than 24 hours.','Votre avis est en attente d\'approbation et apparaîtra sur l\'annonce une fois que notre équipe l\'aura vérifié. Cela prend généralement moins de 24 heures.') ?></div>
  </div>
</div>
<?php endif; ?>

<div class="rv-layout" style="display:grid;grid-template-columns:1fr 320px;gap:32px;align-items:start;">

  <!-- MAIN COLUMN -->
  <div>

    <!-- Rating summary bar -->
    <?php if ($stats['total'] > 0): ?>
    <div class="rating-summary">
      <div class="rating-big">
        <div class="num"><?= $stats['avg_rating'] ?></div>
        <div class="stars">
          <?php for($i=1;$i<=5;$i++) echo $i <= round($stats['avg_rating']) ? '★' : '☆'; ?>
        </div>
        <div class="total"><?= number_format($stats['total']) ?> <?= t('reviews','avis') ?></div>
      </div>
      <div class="rating-bars">
        <?php foreach ([5,4,3,2,1] as $star):
          $count = (int)$stats[$star === 5 ? 'five_star' : ($star === 4 ? 'four_star' : ($star === 3 ? 'three_star' : ($star === 2 ? 'two_star' : 'one_star')))];
          $pct   = $stats['total'] ? round(($count / $stats['total']) * 100) : 0;
        ?>
        <div class="rating-row">
          <div class="label"><?= $star ?> ★</div>
          <div class="bar"><div class="fill" style="width:<?= $pct ?>%;"></div></div>
          <div class="count"><?= $count ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="GET" action="" style="margin-bottom:20px;">
      <div class="rv-filters">
        <?php foreach ([
          'latest'   => t('Latest','Récents'),
          'top'      => t('Top Rated','Mieux notés'),
          'rating_5' => '★★★★★',
          'rating_4' => '★★★★',
          'rating_3' => '★★★ ' . t('& below','& moins'),
        ] as $val => $label): ?>
        <a href="?sort=<?= $val ?><?= $filterCat?"&category={$filterCat}":'' ?><?= $filterCity?"&location={$filterCity}":'' ?>"
           class="rv-filter-btn <?= $sort===$val?'active':'' ?>"><?= $label ?></a>
        <?php endforeach; ?>

        <select class="rv-select" onchange="applyFilter(this,'category')" name="category">
          <option value=""><?= t('All Categories','Toutes catégories') ?></option>
          <?php foreach ($cats as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterCat==$c['id']?'selected':'' ?>><?= $c['icon'] ?> <?= e($isFr?$c['name_fr']:$c['name_en']) ?></option>
          <?php endforeach; ?>
        </select>

        <select class="rv-select" onchange="applyFilter(this,'location')" name="location">
          <option value=""><?= t('All Cities','Toutes villes') ?></option>
          <?php foreach ($locs as $l): ?>
          <option value="<?= $l['id'] ?>" <?= $filterCity==$l['id']?'selected':'' ?>>📍 <?= e($l['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <!-- Results count -->
    <div style="font-size:13px;color:rgba(255,255,255,0.45);margin-bottom:16px;">
      <?= number_format($totalReviews) ?> <?= t('reviews found','avis trouvés') ?>
    </div>

    <!-- Review cards -->
    <?php if (empty($reviews)): ?>
    <div style="text-align:center;padding:60px 20px;color:rgba(255,255,255,0.4);">
      <div style="font-size:2.5rem;margin-bottom:12px;">💬</div>
      <div style="font-size:15px;"><?= t('No reviews yet. Be the first!','Aucun avis pour le moment. Soyez le premier !') ?></div>
    </div>
    <?php else: ?>
    <div class="rv-grid">
      <?php foreach ($reviews as $rv):
        $initial = mb_strtoupper(mb_substr($rv['name'] ?? '?', 0, 1));
        $catName = $isFr ? ($rv['cat_fr'] ?? $rv['cat_en']) : $rv['cat_en'];
      ?>
      <div class="rv-card">
        <div class="rv-stars">
          <?php for($i=1;$i<=5;$i++) echo $i <= (int)$rv['rating'] ? '★' : '☆'; ?>
          <span style="font-size:11px;color:rgba(255,255,255,0.4);margin-left:4px;"><?= $rv['rating'] ?>/5</span>
        </div>
        <div class="rv-text"><?= e(mb_substr($rv['comment'], 0, 200)) ?><?= mb_strlen($rv['comment']) > 200 ? '…' : '' ?></div>
        <div class="rv-meta">
          <div class="rv-avatar"><?= $initial ?></div>
          <div>
            <div class="rv-reviewer"><?= e($rv['name'] ?? t('Anonymous','Anonyme')) ?></div>
            <a href="<?= SITE_URL ?>/listing/<?= e($rv['listing_slug']) ?>" class="rv-biz-link">
              <?= $rv['cat_icon'] ?> <?= e($rv['listing_title']) ?> · <?= e($rv['city']) ?>
            </a>
          </div>
          <div class="rv-date"><?= date('d M Y', strtotime($rv['created_at'])) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="rv-pagination">
      <?php if ($page > 1): ?>
      <a href="?sort=<?= $sort ?>&page=<?= $page-1 ?><?= $filterCat?"&category={$filterCat}":'' ?><?= $filterCity?"&location={$filterCity}":'' ?>" class="rv-page-btn">← <?= t('Prev','Préc') ?></a>
      <?php endif; ?>
      <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
      <a href="?sort=<?= $sort ?>&page=<?= $p ?><?= $filterCat?"&category={$filterCat}":'' ?><?= $filterCity?"&location={$filterCity}":'' ?>" class="rv-page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
      <a href="?sort=<?= $sort ?>&page=<?= $page+1 ?><?= $filterCat?"&category={$filterCat}":'' ?><?= $filterCity?"&location={$filterCity}":'' ?>" class="rv-page-btn"><?= t('Next','Suiv') ?> →</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </div>

  <!-- SIDEBAR -->
  <div class="rv-sidebar">

    <!-- Write a review form -->
    <div class="rv-form-card" id="write-review">
      <h2>✍️ <?= t('Write a Review','Écrire un Avis') ?></h2>
      <p style="font-size:13px;color:rgba(255,255,255,0.55);margin-bottom:20px;">
        <?= t('Share your experience with a Cameroonian business. Your review helps other customers make better decisions.','Partagez votre expérience. Votre avis aide d\'autres clients à faire de meilleurs choix.') ?>
      </p>

      <?php foreach ($errors as $err): ?>
      <div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:8px;padding:10px 14px;font-size:13px;color:#ff6b7a;margin-bottom:14px;"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="POST">
        <input type="hidden" name="csrf"   value="<?= csrf() ?>">
        <input type="hidden" name="action" value="write_review">

        <div class="form-group">
          <label><?= t('Your Name *','Votre Nom *') ?></label>
          <input type="text" name="reviewer_name" required
                 value="<?= e($cu['name'] ?? $_POST['reviewer_name'] ?? '') ?>"
                 placeholder="<?= e(t('e.g. Marie Ngo','ex. Marie Ngo')) ?>"
                 <?= $cu ? 'readonly style="opacity:.7;"' : '' ?>>
        </div>

        <div class="form-group">
          <label><?= t('Business *','Entreprise *') ?></label>
          <select name="listing_id" required>
            <option value=""><?= t('— Choose a business —','— Choisir une entreprise —') ?></option>
            <?php foreach ($allListings as $bl): ?>
            <option value="<?= $bl['id'] ?>" <?= ($_POST['listing_id'] ?? 0) == $bl['id'] ? 'selected' : '' ?>>
              <?= e($bl['title']) ?> (<?= e($bl['city']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label><?= t('Rating *','Note *') ?></label>
          <div class="star-picker">
            <?php for($i=5;$i>=1;$i--): ?>
            <input type="radio" name="rating" id="star<?= $i ?>" value="<?= $i ?>" <?= ($_POST['rating'] ?? 0) == $i ? 'checked' : '' ?> required>
            <label for="star<?= $i ?>" title="<?= $i ?> stars">★</label>
            <?php endfor; ?>
          </div>
          <div id="star-label" style="font-size:12px;color:rgba(255,255,255,0.4);margin-top:6px;"></div>
        </div>

        <div class="form-group">
          <label><?= t('Your Review *','Votre Avis *') ?> <small style="font-weight:400;color:rgba(255,255,255,0.4);">(<?= t('min. 10 characters','min. 10 caractères') ?>)</small></label>
          <textarea name="comment" rows="5" required minlength="10"
                    placeholder="<?= e(t('What was your experience like? What did you buy or use? Would you recommend this business?','Quelle était votre expérience ? Que pensez-vous de ce service ? Recommanderiez-vous cette entreprise ?')) ?>"><?= e($_POST['comment'] ?? '') ?></textarea>
        </div>

        <button type="submit" style="width:100%;padding:13px;background:#00A878;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s;">
          ⭐ <?= t('Submit Review','Soumettre l\'Avis') ?>
        </button>
        <p style="font-size:11.5px;color:rgba(255,255,255,0.35);margin-top:10px;text-align:center;">
          <?= t('Reviews are moderated before publishing — usually approved within 24 hours.','Les avis sont modérés avant publication — généralement approuvés dans les 24 heures.') ?>
        </p>
      </form>
    </div>

    <!-- Top rated businesses -->
    <?php if ($topListings): ?>
    <div class="sidebar-card">
      <h3>🏆 <?= t('Top Rated Businesses','Entreprises Mieux Notées') ?></h3>
      <?php foreach ($topListings as $tb): ?>
      <div class="top-biz-item">
        <div class="top-biz-logo">
          <?php if ($tb['logo']): ?>
          <img src="<?= SITE_URL ?>/uploads/<?= e($tb['logo']) ?>" alt="<?= e($tb['title']) ?>">
          <?php else: ?>
          <?= $tb['cat_icon'] ?>
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:0;">
          <a href="<?= SITE_URL ?>/listing/<?= e($tb['slug']) ?>" class="top-biz-name"><?= e(mb_substr($tb['title'],0,30)) ?><?= mb_strlen($tb['title'])>30?'…':'' ?></a>
          <div class="top-biz-meta">
            <span style="color:#fcd116;">★ <?= $tb['avg_rating'] ?></span>
            · <?= (int)$tb['review_count'] ?> <?= t('reviews','avis') ?>
            · <?= e($tb['city']) ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Quick links -->
    <div class="sidebar-card">
      <h3>🔗 <?= t('Quick Links','Liens rapides') ?></h3>
      <?php foreach ([
        [SITE_URL.'/listings',      '🏪', t('Browse All Businesses','Toutes les Entreprises')],
        [SITE_URL.'/add-listing',   '➕', t('List Your Business','Lister votre Entreprise')],
        [SITE_URL.'/business-hub',  '💼', t('Business Hub','Business Hub')],
        [SITE_URL.'/partners',      '🤝', t('Partner Programme','Programme Partenaires')],
      ] as [$url,$icon,$label]): ?>
      <a href="<?= $url ?>" style="display:flex;align-items:center;gap:10px;padding:9px 8px;border-radius:8px;font-size:13.5px;color:rgba(255,255,255,0.7);text-decoration:none;transition:all .15s;">
        <span><?= $icon ?></span><?= $label ?>
      </a>
      <?php endforeach; ?>
    </div>

  </div>
</div>
</div>
</section>

<script>
var starLabels = {
  5: '<?= t('Excellent','Excellent') ?>',
  4: '<?= t('Very Good','Très Bien') ?>',
  3: '<?= t('Average','Moyen') ?>',
  2: '<?= t('Poor','Médiocre') ?>',
  1: '<?= t('Terrible','Terrible') ?>',
};
document.querySelectorAll('.star-picker input').forEach(function(radio) {
  radio.addEventListener('change', function() {
    document.getElementById('star-label').textContent = starLabels[this.value] || '';
  });
});

function applyFilter(sel, key) {
  var url  = new URL(window.location.href);
  if (sel.value) url.searchParams.set(key, sel.value);
  else url.searchParams.delete(key);
  url.searchParams.delete('page');
  window.location.href = url.toString();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
