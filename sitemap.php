<?php
/**
 * sitemap.php — 237Biz XML Sitemap
 * URL: /sitemap.xml (via .htaccess rewrite)
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';

$listings  = db()->query("SELECT slug, updated_at FROM listings WHERE status='approved' ORDER BY updated_at DESC")->fetchAll();
$cats      = db()->query("SELECT slug FROM categories")->fetchAll();
$locs      = db()->query("SELECT slug FROM locations")->fetchAll();

$today = date('Y-m-d');
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

  <!-- Static pages -->
  <?php foreach ([
    ['/', '1.0', 'daily'],
    ['/listings', '0.9', 'daily'],
    ['/join', '0.8', 'weekly'],
    ['/partners', '0.8', 'weekly'],
    ['/business-hub', '0.8', 'weekly'],
    ['/business-listing', '0.8', 'weekly'],
    ['/online-presence', '0.7', 'monthly'],
    ['/reviews', '0.7', 'daily'],
    ['/locations', '0.7', 'weekly'],
    ['/contact', '0.5', 'monthly'],
    ['/privacy-policy', '0.3', 'monthly'],
    ['/terms', '0.3', 'monthly'],
    ['/help', '0.6', 'weekly'],
  ] as [$url, $pri, $freq]): ?>
  <url>
    <loc><?= SITE_URL . $url ?></loc>
    <lastmod><?= $today ?></lastmod>
    <changefreq><?= $freq ?></changefreq>
    <priority><?= $pri ?></priority>
  </url>
  <?php endforeach; ?>

  <!-- Categories -->
  <?php foreach ($cats as $cat): $slug = $cat['slug'] ?? ''; if (!$slug) continue; ?>
  <url>
    <loc><?= SITE_URL ?>/category/<?= e($slug) ?></loc>
    <lastmod><?= $today ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.6</priority>
  </url>
  <?php endforeach; ?>

  <!-- Locations -->
  <?php foreach ($locs as $loc): $slug = $loc['slug'] ?? ''; if (!$slug) continue; ?>
  <url>
    <loc><?= SITE_URL ?>/location/<?= e($slug) ?></loc>
    <lastmod><?= $today ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.6</priority>
  </url>
  <?php endforeach; ?>

  <!-- Business listings -->
  <?php foreach ($listings as $l): ?>
  <url>
    <loc><?= SITE_URL ?>/listing/<?= e($l['slug']) ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($l['updated_at'])) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.5</priority>
  </url>
  <?php endforeach; ?>

</urlset>
