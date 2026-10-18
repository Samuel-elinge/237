<?php
require_once __DIR__ . '/includes/config.php';

$pageTitle = t('Blog — Business Tips & News','Blog — Conseils & Actualités');
$pageDesc  = t(
    'Business tips, partner spotlights and the latest 237Biz news — helping Cameroon businesses grow online.',
    'Conseils d\'entreprise, portraits de partenaires et actualités 237Biz — aider les entreprises camerounaises à se développer en ligne.'
);

// ── Filters ──────────────────────────────────────────────────
$validCats = ['tips','news','spotlight','event'];
$catFilter = in_array($_GET['cat'] ?? '', $validCats) ? $_GET['cat'] : '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 9;
$offset    = ($page - 1) * $perPage;
$isFr      = lang() === 'fr';

// ── Fetch posts ───────────────────────────────────────────────
$where  = ["status = 'published'"];
$params = [];
if ($catFilter) { $where[] = "category = ?"; $params[] = $catFilter; }
$whereSQL = implode(' AND ', $where);

try {
    $total = (int) db()->prepare("SELECT COUNT(*) FROM blog_posts WHERE $whereSQL")
                       ->execute($params) ? db()->prepare("SELECT COUNT(*) FROM blog_posts WHERE $whereSQL") : 0;
    // re-run cleanly
    $countSt = db()->prepare("SELECT COUNT(*) FROM blog_posts WHERE $whereSQL");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $baseCols = "id,slug,title_en,title_fr,excerpt_en,excerpt_fr,category,cover_url,author_name,views,published_at";
    try {
        // includes image alt text (columns are added automatically from the blog admin)
        $st = db()->prepare("SELECT $baseCols,cover_alt_en,cover_alt_fr
                             FROM blog_posts WHERE $whereSQL ORDER BY published_at DESC LIMIT $perPage OFFSET $offset");
        $st->execute($params);
    } catch (Exception $e2) {
        $st = db()->prepare("SELECT $baseCols
                             FROM blog_posts WHERE $whereSQL ORDER BY published_at DESC LIMIT $perPage OFFSET $offset");
        $st->execute($params);
    }
    $posts = $st->fetchAll();
} catch (Exception $e) {
    $posts = [];
    $total = 0;
}

$totalPages = $total ? (int)ceil($total / $perPage) : 1;

$catLabels = [
    'tips'      => t('Business Tips',       'Conseils Entreprise'),
    'news'      => t('237Biz News',         'Actualités 237Biz'),
    'spotlight' => t('Partner Spotlight',   'Portrait Partenaire'),
    'event'     => t('Events',              'Événements'),
];
$catColors = [
    'tips'      => '#00A878',
    'news'      => '#fcd116',
    'spotlight' => '#8ab4f8',
    'event'     => '#f5b800',
];

require_once __DIR__ . '/includes/header.php';
?>

<style>
.blog-hero {
  background: linear-gradient(135deg, #05160A 0%, #0a2a15 100%);
  color: #fff;
  padding: 72px 0 52px;
}
.blog-hero h1 {
  font-family: 'Fraunces', serif;
  font-size: clamp(2rem,5vw,3.2rem);
  font-weight: 900;
  line-height: 1.1;
  text-wrap: balance;
}
.blog-hero h1 span { color: #fcd116; }
.blog-hero p { color: rgba(255,255,255,.65); margin-top: 10px; font-size: 1rem; max-width: 520px; }

.blog-cats {
  display: flex; flex-wrap: wrap; gap: 8px; margin-top: 24px;
}
.blog-cat-pill {
  display: inline-block;
  padding: 6px 16px;
  border-radius: 99px;
  font-size: 13px; font-weight: 600;
  text-decoration: none;
  border: 1.5px solid rgba(255,255,255,.2);
  color: rgba(255,255,255,.7);
  transition: border-color .15s, color .15s, background .15s;
}
.blog-cat-pill:hover,
.blog-cat-pill.active {
  border-color: #00A878; color: #00A878; background: rgba(0,168,120,.08);
}
.blog-cat-pill.active { background: rgba(0,168,120,.15); }

.blog-main { max-width: 1100px; margin: 0 auto; padding: 48px 20px 60px; }

.blog-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 24px;
}

.blog-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border-color, #e8f0eb);
  border-radius: 14px;
  overflow: hidden;
  display: flex; flex-direction: column;
  transition: transform .18s, box-shadow .18s;
  text-decoration: none; color: inherit;
}
.blog-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,.1); }

.blog-card-img {
  width: 100%; height: 190px; object-fit: cover;
  background: linear-gradient(135deg,#0a2a15,#1a4d2e);
  display: flex; align-items: center; justify-content: center;
  font-size: 2.5rem;
}
.blog-card-img img { width: 100%; height: 100%; object-fit: cover; }

.blog-card-body { padding: 20px; flex: 1; display: flex; flex-direction: column; }
.blog-card-cat {
  display: inline-block;
  font-size: 10.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase;
  padding: 2px 9px; border-radius: 99px; margin-bottom: 10px;
  color: #05160A;
}
.blog-card-title {
  font-family: 'Fraunces', serif;
  font-size: 1.1rem; font-weight: 700; line-height: 1.35;
  margin-bottom: 8px; text-wrap: balance;
}
.blog-card-excerpt { font-size: 13.5px; color: var(--muted-fg, #5a6e5f); line-height: 1.6; flex: 1; }
.blog-card-meta {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: 14px; padding-top: 12px;
  border-top: 1px solid var(--border-color, #e8f0eb);
  font-size: 12px; color: var(--muted-fg, #7a8f80);
}
.blog-card-views { display: flex; align-items: center; gap: 4px; }

.blog-empty {
  text-align: center; padding: 80px 20px; color: var(--muted-fg, #7a8f80);
}
.blog-empty .icon { font-size: 3rem; margin-bottom: 12px; }

.blog-pagination { display: flex; gap: 8px; justify-content: center; margin-top: 40px; flex-wrap: wrap; }
.blog-pagination a, .blog-pagination span {
  display: inline-flex; align-items: center; justify-content: center;
  width: 38px; height: 38px; border-radius: 8px;
  font-size: 14px; font-weight: 600; text-decoration: none;
  border: 1.5px solid var(--border-color, #dde8e0);
  color: var(--body-fg, #1a2a1e);
  transition: background .15s, border-color .15s;
}
.blog-pagination a:hover { background: #f0faf5; border-color: #00A878; }
.blog-pagination span.current { background: #00A878; border-color: #00A878; color: #fff; }

/* Site is dark-themed: always use dark card colours */
.blog-card { --card-bg: #162a1e; --border-color: #2a4030; color: #d4e8d8; }
.blog-card-meta { --border-color: #2a4030; --muted-fg: #8aab92; }
.blog-card-excerpt { --muted-fg: #a9c7b0; }
.blog-pagination a, .blog-pagination span { --border-color: #2a4030; --body-fg: #d4e8d8; }
.blog-pagination a:hover { background: #162a1e; }
</style>

<!-- Hero -->
<div class="blog-hero">
  <div class="container">
    <h1><?= t('The <span>237Biz</span> Blog','Le Blog <span>237Biz</span>') ?></h1>
    <p><?= t('Business tips, partner spotlights and the latest news from Cameroon\'s Business Hub.','Conseils, portraits de partenaires et actualités du Hub des Entreprises du Cameroun.') ?></p>

    <div class="blog-cats">
      <a href="/blog" class="blog-cat-pill <?= !$catFilter ? 'active' : '' ?>">
        <?= t('All','Tous') ?>
      </a>
      <?php foreach ($catLabels as $key => $label): ?>
      <a href="/blog?cat=<?= $key ?>" class="blog-cat-pill <?= $catFilter === $key ? 'active' : '' ?>">
        <?= $label ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Posts grid -->
<div class="blog-main">

  <?php if (empty($posts)): ?>
  <div class="blog-empty">
    <div class="icon">✍️</div>
    <h3><?= t('No posts yet','Aucun article pour l\'instant') ?></h3>
    <p><?= t('Check back soon — great content is on its way.','Revenez bientôt — du contenu de qualité arrive.') ?></p>
  </div>
  <?php else: ?>

  <div class="blog-grid">
    <?php foreach ($posts as $p):
      $title   = $isFr && $p['title_fr']   ? $p['title_fr']   : $p['title_en'];
      $excerpt = $isFr && $p['excerpt_fr']  ? $p['excerpt_fr'] : $p['excerpt_en'];
      $catLabel= $catLabels[$p['category']] ?? ucfirst($p['category']);
      $catColor= $catColors[$p['category']] ?? '#00A878';
      $dateStr = $p['published_at'] ? date('j M Y', strtotime($p['published_at'])) : '';
    ?>
    <a href="/blog/<?= e($p['slug']) ?>" class="blog-card">
      <div class="blog-card-img">
        <?php if ($p['cover_url']): ?>
          <?php $coverAlt = trim(($isFr ? ($p['cover_alt_fr'] ?? '') : '') ?: ($p['cover_alt_en'] ?? '')); ?>
          <img src="<?= e($p['cover_url']) ?>" alt="<?= e($coverAlt !== '' ? $coverAlt : $title) ?>" loading="lazy">
        <?php else: ?>
          <?= $p['category'] === 'tips' ? '💡' : ($p['category'] === 'spotlight' ? '⭐' : ($p['category'] === 'event' ? '📅' : '📢')) ?>
        <?php endif; ?>
      </div>
      <div class="blog-card-body">
        <span class="blog-card-cat" style="background:<?= $catColor ?>"><?= $catLabel ?></span>
        <div class="blog-card-title"><?= e($title) ?></div>
        <?php if ($excerpt): ?>
        <div class="blog-card-excerpt"><?= e(mb_strimwidth(strip_tags($excerpt), 0, 120, '…')) ?></div>
        <?php endif; ?>
        <div class="blog-card-meta">
          <span><?= e($p['author_name']) ?> <?= $dateStr ? '· '.$dateStr : '' ?></span>
          <span class="blog-card-views">👁 <?= number_format($p['views']) ?></span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="blog-pagination">
    <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>">‹</a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>">›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
