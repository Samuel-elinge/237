<?php
/**
 * help-article.php — 237Biz Knowledge Base article page
 * URL: /help/add-your-listing
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/kb-articles.php';

$slug = trim($_GET['slug'] ?? '');
$article = $slug ? kb_article($slug) : null;

if (!$article) {
    http_response_code(404);
    $pageTitle = t('Article Not Found — Help — 237Biz','Article introuvable — Aide — 237Biz');
    require_once __DIR__ . '/includes/header.php';
    echo '<div style="max-width:600px;margin:60px auto;text-align:center;padding:20px;">';
    echo '<div style="font-size:2.5rem;margin-bottom:14px;">❓</div>';
    echo '<h2 style="font-family:\'Fraunces\',serif;">' . t('Article not found','Article introuvable') . '</h2>';
    echo '<p style="color:var(--muted);">' . t('This article does not exist or may have moved.','Cet article n\'existe pas ou a peut-être été déplacé.') . '</p>';
    echo '<a href="' . SITE_URL . '/help" class="btn btn-primary" style="margin-top:16px;">' . t('← Back to Help Centre','← Centre d\'aide') . '</a>';
    echo '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$currentLang = lang();
$title       = $currentLang === 'fr' ? $article['title_fr'] : $article['title_en'];
$body        = $currentLang === 'fr' ? $article['body_fr']  : $article['body_en'];
$section     = $KB_SECTIONS[$article['section']] ?? [];

$pageTitle = e($title) . ' — ' . t('Help','Aide') . ' — 237Biz';
$pageDesc  = strip_tags(substr($body, 0, 160));

// Related articles (same section, excluding current)
$related = array_filter(kb_section_articles($article['section']), fn($a) => $a['slug'] !== $slug);
$related = array_values($related);

require_once __DIR__ . '/includes/header.php';
?>

<style>
.article-wrap { max-width:780px; margin:0 auto; padding:2rem 16px 4rem; }
.article-header { margin-bottom:2rem; }
.article-breadcrumb { font-size:12.5px; color:var(--muted); margin-bottom:12px; }
.article-breadcrumb a { color:var(--green); text-decoration:none; }
.article-title { font-family:'Fraunces',serif; font-weight:900; font-size:clamp(1.5rem,3vw,2rem); line-height:1.2; margin:0 0 14px; }
.article-meta { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.article-section-tag { font-size:12px; font-weight:600; padding:3px 11px; border-radius:99px; background:rgba(0,168,120,0.1); color:#00A878; border:1px solid rgba(0,168,120,0.3); }
.lang-switch a { font-size:12px; font-weight:700; padding:4px 12px; border-radius:6px; text-decoration:none; border:1px solid rgba(255,255,255,0.1); color:var(--muted); }
.lang-switch a.active { background:rgba(0,168,120,0.2); color:#00A878; border-color:rgba(0,168,120,0.4); }

/* Article body styles */
.article-body { line-height:1.85; font-size:15px; color:rgba(255,255,255,0.85); }
.article-body h2 { font-family:'Fraunces',serif; font-size:1.3rem; font-weight:700; margin:2rem 0 .75rem; color:#fff; border-bottom:1px solid rgba(255,255,255,0.07); padding-bottom:.5rem; }
.article-body h3 { font-size:1.05rem; font-weight:700; margin:1.5rem 0 .6rem; color:rgba(255,255,255,0.95); }
.article-body p  { margin:0 0 1rem; }
.article-body ul, .article-body ol { padding-left:1.4rem; margin:0 0 1rem; }
.article-body li { margin-bottom:.4rem; }
.article-body a  { color:#00A878; text-decoration:underline; }
.article-body a:hover { color:#6fcf97; }
.article-body strong { color:#fff; font-weight:700; }
.article-body em { color:rgba(255,255,255,0.75); font-style:italic; }

/* Sidebar / related */
.article-layout { display:grid; grid-template-columns:1fr 240px; gap:2rem; align-items:start; }
@media(max-width:700px){ .article-layout { grid-template-columns:1fr; } }
.article-sidebar { position:sticky; top:80px; }
.sidebar-widget { background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px 18px; margin-bottom:14px; }
.sidebar-widget h4 { font-size:13px; font-weight:700; margin-bottom:10px; color:rgba(255,255,255,0.7); }
.sidebar-widget ul { list-style:none; padding:0; margin:0; }
.sidebar-widget li { margin-bottom:7px; }
.sidebar-widget li a { font-size:12.5px; color:rgba(255,255,255,0.65); text-decoration:none; display:flex; gap:5px; line-height:1.4; }
.sidebar-widget li a::before { content:'→'; color:#00A878; flex-shrink:0; }
.sidebar-widget li a:hover { color:#fff; }
.sidebar-widget li.current a { color:#00A878; font-weight:600; }
.sidebar-widget li.current a::before { content:'●'; }
</style>

<div style="background:rgba(255,255,255,0.015);border-bottom:1px solid rgba(255,255,255,0.07);padding:10px 0;">
  <div class="container" style="max-width:780px;">
    <nav style="font-size:12.5px;color:var(--muted);">
      <a href="<?= SITE_URL ?>/help" style="color:var(--green);"><?= t('Help Centre','Centre d\'aide') ?></a>
      › <a href="<?= SITE_URL ?>/help?section=<?= $article['section'] ?>" style="color:var(--green);"><?= e($section[$currentLang] ?? $article['section']) ?></a>
      › <span style="color:rgba(255,255,255,0.5);"><?= e($title) ?></span>
    </nav>
  </div>
</div>

<div class="article-wrap">
  <div class="article-layout">

    <!-- Main content -->
    <div>
      <div class="article-header">
        <h1 class="article-title"><?= e($title) ?></h1>
        <div class="article-meta">
          <span class="article-section-tag"><?= $section['icon'] ?? '' ?> <?= e($section[$currentLang] ?? '') ?></span>
          <div class="lang-switch" style="display:flex;gap:5px;">
            <a href="<?= kb_url($slug, 'en') ?>" class="<?= $currentLang==='en'?'active':'' ?>">EN</a>
            <a href="<?= kb_url($slug, 'fr') ?>" class="<?= $currentLang==='fr'?'active':'' ?>">FR</a>
          </div>
        </div>
      </div>

      <div class="article-body">
        <?= $body ?>
      </div>

      <!-- Was this helpful? -->
      <div style="margin-top:2.5rem;padding:18px 20px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;text-align:center;">
        <p style="font-size:14px;font-weight:600;margin-bottom:10px;"><?= t('Was this article helpful?','Cet article a-t-il été utile ?') ?></p>
        <div style="display:flex;gap:8px;justify-content:center;">
          <a href="mailto:support@237biz.net?subject=Help+feedback+<?= urlencode($slug) ?>&body=This+article+was+helpful"
             style="padding:7px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;background:rgba(0,168,120,0.15);color:#00A878;border:1px solid rgba(0,168,120,0.3);">
            👍 <?= t('Yes, thanks','Oui, merci') ?>
          </a>
          <a href="mailto:support@237biz.net?subject=Help+feedback+<?= urlencode($slug) ?>&body=This+article+was+not+helpful+because:+"
             style="padding:7px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;background:rgba(255,255,255,0.04);color:var(--muted);border:1px solid rgba(255,255,255,0.1);">
            👎 <?= t('Not really','Pas vraiment') ?>
          </a>
        </div>
      </div>

      <!-- Back link -->
      <div style="margin-top:1.5rem;">
        <a href="<?= SITE_URL ?>/help" style="font-size:13px;color:var(--green);">← <?= t('Back to Help Centre','Retour au Centre d\'aide') ?></a>
      </div>
    </div>

    <!-- Sidebar -->
    <aside class="article-sidebar">

      <!-- Section navigation -->
      <div class="sidebar-widget">
        <h4><?= e($section[$currentLang] ?? '') ?></h4>
        <ul>
          <?php foreach (kb_section_articles($article['section']) as $a): ?>
          <li class="<?= $a['slug']===$slug?'current':'' ?>">
            <a href="<?= kb_url($a['slug']) ?>"><?= e(kb_title($a)) ?></a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Quick links -->
      <div class="sidebar-widget">
        <h4><?= t('Quick Links','Liens rapides') ?></h4>
        <ul>
          <li><a href="<?= SITE_URL ?>/dashboard"><?= t('My Dashboard','Tableau de bord') ?></a></li>
          <li><a href="<?= SITE_URL ?>/edit-listing"><?= t('Edit Listing','Modifier mon annonce') ?></a></li>
          <li><a href="<?= SITE_URL ?>/analytics"><?= t('Analytics','Analytiques') ?></a></li>
          <li><a href="<?= SITE_URL ?>/manage-bookings"><?= t('Manage Bookings','Réservations') ?></a></li>
          <li><a href="<?= SITE_URL ?>/manage-announcements"><?= t('Announcements','Annonces') ?></a></li>
        </ul>
      </div>

      <!-- Need help -->
      <div class="sidebar-widget" style="text-align:center;border-color:rgba(0,168,120,0.2);">
        <div style="font-size:1.5rem;margin-bottom:6px;">💬</div>
        <p style="font-size:12.5px;color:var(--muted);margin-bottom:10px;"><?= t('Still stuck? Contact our team.','Toujours bloqué ? Contactez notre équipe.') ?></p>
        <a href="mailto:support@237biz.net" style="font-size:12px;font-weight:700;color:#00A878;">support@237biz.net</a>
      </div>

    </aside>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
