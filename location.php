<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
$st = db()->prepare("SELECT * FROM locations WHERE slug = ?");
$st->execute([$slug]);
$currentLoc = $st->fetch();
if (!$currentLoc) redirect(SITE_URL . '/listings');

$listings = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon
    FROM listings l
    JOIN categories c ON c.id = l.category_id
    WHERE l.location_id = ? AND l.status = 'approved'
    ORDER BY l.featured DESC, l.created_at DESC
    LIMIT 18
");
$listings->execute([$currentLoc['id']]);
$listings = $listings->fetchAll();

// Custom SEO content for this location (location-only row: category_id IS NULL)
$seoSt = db()->prepare("SELECT * FROM seo_content WHERE location_id = ? AND category_id IS NULL");
$seoSt->execute([$currentLoc['id']]);
$seoContent = $seoSt->fetch();
$seoIntro = $seoContent ? (lang() === 'fr' ? $seoContent['intro_fr'] : $seoContent['intro_en']) : null;

$pageTitle = $currentLoc['name_en'] . ' Business Directory — 237Biz';
$pageDesc  = 'Find verified businesses in ' . $currentLoc['name_en'] . ', Cameroon. Free listings on 237Biz.';

if ($seoContent) {
    $customTitle = lang() === 'fr' ? $seoContent['meta_title_fr'] : $seoContent['meta_title_en'];
    $customDesc  = lang() === 'fr' ? $seoContent['meta_desc_fr']  : $seoContent['meta_desc_en'];
    if ($customTitle) $pageTitle = $customTitle;
    if ($customDesc)  $pageDesc  = $customDesc;
}

$extraHead = '';
if (file_exists(__DIR__ . '/includes/schema.php')) {
    require_once __DIR__ . '/includes/schema.php';
    $extraHead = '<script type="application/ld+json">' . schemaBreadcrumb([
        ['name' => t('Home','Accueil'), 'url' => SITE_URL . '/'],
        ['name' => $currentLoc['name_en']],
    ]) . '</script>' . "\n";
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <span><?= e($currentLoc['name_en']) ?> <?= t('Directory','Annuaire') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(2rem,4vw,3rem);">
      📍 <?= e($currentLoc['name_en']) ?> <?= t('Business Directory','Annuaire des Entreprises') ?>
    </h1>
    <p style="color:var(--muted);margin-top:0.5rem;"><?= count($listings) ?> <?= t('businesses found','entreprises trouvées') ?></p>
    <?php if ($seoIntro): ?>
      <p style="color:var(--muted);font-size:0.95rem;line-height:1.8;max-width:760px;margin-top:1rem;"><?= nl2br(e($seoIntro)) ?></p>
    <?php endif; ?>
  </div>
</div>

<section class="page-section">
  <div class="container">
    <?php if ($listings): ?>
      <div class="listings-grid">
        <?php foreach ($listings as $l): ?>
          <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>"
             class="listing-card <?= $l['featured'] ? 'featured' : '' ?>"
             style="text-decoration:none;color:inherit;">
            <div class="listing-top">
              <div class="listing-logo"><?= $l['cat_icon'] ?></div>
              <div>
                <div class="listing-name"><?= e($l['title']) ?></div>
                <div class="listing-category"><?= e(lang()==='fr' ? $l['cat_fr'] : $l['cat_en']) ?></div>
              </div>
            </div>
            <p class="listing-desc"><?= e(mb_substr($l['description'] ?? '', 0, 120)) ?>...</p>
            <div class="listing-meta">
              <span class="listing-meta-item">📍 <?= e($currentLoc['name_en']) ?></span>
              <?php if ($l['phone']): ?><span class="listing-meta-item">📞 <?= e($l['phone']) ?></span><?php endif; ?>
            </div>
            <?php if ($l['verified']): ?><span class="listing-verified">✓ <?= t('Verified','Vérifié') ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div style="text-align:center;padding:4rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
        <div style="font-size:3rem;margin-bottom:1rem;">🔍</div>
        <h3 style="font-family:'Fraunces',serif;margin-bottom:0.5rem;"><?= t('No listings yet in','Aucune annonce à') ?> <?= e($currentLoc['name_en']) ?></h3>
        <a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary" style="margin-top:1rem;">+ <?= t('Be the first to list','Soyez le premier à lister') ?></a>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
