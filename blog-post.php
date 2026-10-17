<?php
require_once __DIR__ . '/includes/config.php';

// ── Resolve slug ──────────────────────────────────────────────
$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: /blog'); exit; }

$isFr = lang() === 'fr';

try {
    $st = db()->prepare(
        "SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' LIMIT 1"
    );
    $st->execute([$slug]);
    $post = $st->fetch();
} catch (Exception $e) { $post = null; }

if (!$post) {
    http_response_code(404);
    $pageTitle = t('Post not found','Article introuvable');
    require_once __DIR__ . '/includes/header.php';
    echo '<div style="text-align:center;padding:80px 20px">
            <h2 style="font-family:Fraunces,serif;font-size:2rem">404</h2>
            <p>' . t('This post does not exist or has been removed.','Cet article n\'existe pas ou a été supprimé.') . '</p>
            <a href="/blog" style="color:#00A878">' . t('← Back to Blog','← Retour au Blog') . '</a>
          </div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Increment views (fire-and-forget)
try { db()->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?")->execute([$post['id']]); }
catch (Exception $e) {}

// ── Related posts ─────────────────────────────────────────────
try {
    $rel = db()->prepare(
        "SELECT id,slug,title_en,title_fr,category,cover_url,published_at
         FROM blog_posts WHERE status='published' AND category=? AND id!=?
         ORDER BY published_at DESC LIMIT 3"
    );
    $rel->execute([$post['category'], $post['id']]);
    $related = $rel->fetchAll();
} catch (Exception $e) { $related = []; }

$title   = $isFr && $post['title_fr']   ? $post['title_fr']   : $post['title_en'];
$excerpt = $isFr && $post['excerpt_fr'] ? $post['excerpt_fr'] : $post['excerpt_en'];
$body    = $isFr && $post['body_fr']    ? $post['body_fr']    : $post['body_en'];
$dateStr = $post['published_at'] ? date('j F Y', strtotime($post['published_at'])) : '';

$catLabels = [
    'tips'      => t('Business Tips',       'Conseils Entreprise'),
    'news'      => t('237Biz News',         'Actualités 237Biz'),
    'spotlight' => t('Partner Spotlight',   'Portrait Partenaire'),
    'event'     => t('Events',              'Événements'),
];
$catColors = ['tips'=>'#00A878','news'=>'#fcd116','spotlight'=>'#8ab4f8','event'=>'#f5b800'];

$pageTitle = e($title) . ' — 237Biz Blog';
$metaDesc  = $isFr && $post['meta_desc_fr'] ? $post['meta_desc_fr'] : ($post['meta_desc_en'] ?: ($excerpt ? strip_tags($excerpt) : ''));
$pageDesc  = e(mb_strimwidth($metaDesc, 0, 160, '…'));
$extraHead = ($post['meta_keywords'] ? '<meta name="keywords" content="' . e($post['meta_keywords']) . '">' . "\n" : '')
           . '<meta property="og:title" content="' . e($title) . '">' . "\n"
           . '<meta property="og:description" content="' . $pageDesc . '">' . "\n"
           . ($post['cover_url'] ? '<meta property="og:image" content="' . e($post['cover_url']) . '">' . "\n" : '');

require_once __DIR__ . '/includes/header.php';
?>

<style>
.bp-hero {
  background: linear-gradient(135deg, #05160A, #0a2a15);
  color: #fff; padding: 72px 0 48px;
}
.bp-hero-inner { max-width: 780px; margin: 0 auto; padding: 0 20px; }
.bp-cat-pill {
  display: inline-block; padding: 3px 12px; border-radius: 99px;
  font-size: 11px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase;
  color: #05160A; margin-bottom: 14px;
}
.bp-title {
  font-family: 'Fraunces', serif;
  font-size: clamp(1.8rem, 5vw, 2.8rem); font-weight: 900; line-height: 1.15;
  text-wrap: balance; margin-bottom: 16px;
}
.bp-meta { color: rgba(255,255,255,.6); font-size: 13.5px; display: flex; gap: 16px; flex-wrap: wrap; }
.bp-meta span { display: flex; align-items: center; gap: 5px; }

.bp-cover {
  max-width: 780px; margin: 0 auto; padding: 0 20px;
  position: relative; top: -28px;
}
.bp-cover img {
  width: 100%; border-radius: 16px; max-height: 420px; object-fit: cover;
  box-shadow: 0 8px 40px rgba(0,0,0,.18);
}

.bp-layout { max-width: 780px; margin: 0 auto; padding: 0 20px 72px; }

.bp-body {
  font-size: 1.05rem; line-height: 1.8; color: var(--body-fg, #1a2a1e);
}
.bp-body h2, .bp-body h3 {
  font-family: 'Fraunces', serif; font-weight: 800; margin-top: 2em; margin-bottom: .6em; line-height: 1.2;
}
.bp-body h2 { font-size: 1.6rem; }
.bp-body h3 { font-size: 1.25rem; }
.bp-body p  { margin-bottom: 1.2em; }
.bp-body ul, .bp-body ol { margin: 0 0 1.2em 1.5em; }
.bp-body li { margin-bottom: .4em; }
.bp-body blockquote {
  border-left: 4px solid #00A878; padding: 12px 20px; margin: 1.5em 0;
  background: rgba(0,168,120,.05); border-radius: 0 8px 8px 0;
  font-style: italic; color: var(--muted-fg, #4a6050);
}
.bp-body img { border-radius: 10px; margin: 1.5em 0; max-width: 100%; }
.bp-body a { color: #00A878; }

.bp-divider { border: none; border-top: 1.5px solid var(--border-color, #e0ece4); margin: 48px 0 36px; }

.bp-related-title {
  font-family: 'Fraunces', serif; font-size: 1.4rem; font-weight: 800; margin-bottom: 20px;
}
.bp-related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
.bp-rel-card {
  display: flex; flex-direction: column; text-decoration: none; color: inherit;
  border: 1px solid var(--border-color, #e0ece4); border-radius: 12px; overflow: hidden;
  transition: transform .15s, box-shadow .15s;
}
.bp-rel-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.09); }
.bp-rel-card-img {
  height: 130px; background: linear-gradient(135deg,#0a2a15,#1a4d2e);
  display: flex; align-items: center; justify-content: center; font-size: 1.8rem;
}
.bp-rel-card-img img { width: 100%; height: 100%; object-fit: cover; }
.bp-rel-card-body { padding: 14px; flex: 1; }
.bp-rel-card-title { font-weight: 700; font-size: 14px; line-height: 1.35; text-wrap: balance; }
.bp-rel-card-date  { font-size: 12px; color: var(--muted-fg, #7a8f80); margin-top: 6px; }

.bp-back { margin-bottom: 24px; }
.bp-back a { color: #00A878; text-decoration: none; font-weight: 600; font-size: 14px; }
.bp-back a:hover { text-decoration: underline; }

@media (prefers-color-scheme: dark) {
  .bp-body { --body-fg: #d4e8d8; }
  .bp-rel-card { --border-color: #2a4030; }
  .bp-divider { border-color: #2a4030; }
}
</style>

<!-- Hero -->
<div class="bp-hero">
  <div class="bp-hero-inner">
    <div class="bp-back">
      <a href="/blog">← <?= t('Blog','Blog') ?></a>
    </div>
    <span class="bp-cat-pill" style="background:<?= $catColors[$post['category']] ?? '#00A878' ?>">
      <?= $catLabels[$post['category']] ?? ucfirst($post['category']) ?>
    </span>
    <h1 class="bp-title"><?= e($title) ?></h1>
    <div class="bp-meta">
      <span>✍️ <?= e($post['author_name']) ?></span>
      <?php if ($dateStr): ?><span>📅 <?= $dateStr ?></span><?php endif; ?>
      <span>👁 <?= number_format($post['views']) ?> <?= t('views','vues') ?></span>
    </div>
  </div>
</div>

<!-- Cover image -->
<?php if ($post['cover_url']): ?>
<div class="bp-cover">
  <img src="<?= e($post['cover_url']) ?>" alt="<?= e($title) ?>">
</div>
<?php endif; ?>

<!-- Body -->
<div class="bp-layout" <?= !$post['cover_url'] ? 'style="padding-top:40px"' : '' ?>>
  <div class="bp-body">
    <?= $body /* admin-entered HTML — trusted */ ?>
  </div>

  <?php if (!empty($related)): ?>
  <hr class="bp-divider">
  <div class="bp-related-title"><?= t('More from the blog','Plus du blog') ?></div>
  <div class="bp-related-grid">
    <?php foreach ($related as $r):
      $rt = $isFr && $r['title_fr'] ? $r['title_fr'] : $r['title_en'];
      $rd = $r['published_at'] ? date('j M Y', strtotime($r['published_at'])) : '';
    ?>
    <a href="/blog/<?= e($r['slug']) ?>" class="bp-rel-card">
      <div class="bp-rel-card-img">
        <?php if ($r['cover_url']): ?>
          <img src="<?= e($r['cover_url']) ?>" alt="<?= e($rt) ?>" loading="lazy">
        <?php else: ?>
          <?= $r['category'] === 'tips' ? '💡' : ($r['category'] === 'spotlight' ? '⭐' : ($r['category'] === 'event' ? '📅' : '📢')) ?>
        <?php endif; ?>
      </div>
      <div class="bp-rel-card-body">
        <div class="bp-rel-card-title"><?= e($rt) ?></div>
        <?php if ($rd): ?><div class="bp-rel-card-date"><?= $rd ?></div><?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
