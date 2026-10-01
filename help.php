<?php
/**
 * help.php — 237Biz Knowledge Base homepage
 * URL: /help
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/kb-articles.php';

$pageTitle = t('Help Centre — 237Biz', 'Centre d\'aide — 237Biz');
$pageDesc  = t('Find answers to your questions about listing your business, managing bookings, analytics and more on 237Biz.',
               'Trouvez des réponses à vos questions sur la gestion de votre annonce, réservations, analytiques et plus encore.');

$search = trim($_GET['q'] ?? '');
$currentLang = lang();

// Search
$searchResults = [];
if ($search && strlen($search) >= 2) {
    foreach ($KB_ARTICLES as $art) {
        $haystack = strtolower($art['title_en'] . ' ' . $art['body_en'] . ' ' . $art['title_fr'] . ' ' . $art['body_fr']);
        if (str_contains($haystack, strtolower($search))) {
            $searchResults[] = $art;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.help-hero { background:linear-gradient(135deg,#08472F,#0e6645); padding:3rem 0 2rem; text-align:center; }
.help-hero h1 { font-family:'Fraunces',serif; font-weight:900; font-size:clamp(1.8rem,4vw,2.8rem); margin-bottom:.5rem; }
.help-hero p  { color:rgba(255,255,255,0.75); font-size:1rem; margin-bottom:1.5rem; }
.help-search  { display:flex; max-width:500px; margin:0 auto; gap:0; border-radius:10px; overflow:hidden; border:2px solid rgba(255,255,255,0.2); }
.help-search input  { flex:1; padding:12px 16px; background:rgba(255,255,255,0.1); border:none; color:#fff; font-size:15px; font-family:inherit; outline:none; }
.help-search input::placeholder { color:rgba(255,255,255,0.5); }
.help-search button { padding:12px 20px; background:var(--yellow); color:#0A1A0F; font-weight:700; border:none; cursor:pointer; font-family:inherit; font-size:14px; }
.help-wrap { max-width:880px; margin:0 auto; padding:2rem 16px 4rem; }
.section-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; margin-bottom:2.5rem; }
.section-card { background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:20px; transition:border-color .15s,background .15s; }
.section-card:hover { border-color:rgba(0,168,120,0.35); background:rgba(0,168,120,0.04); }
.section-card h3 { font-size:1rem; font-weight:700; margin:8px 0 12px; }
.section-card ul { list-style:none; padding:0; margin:0; }
.section-card li { margin-bottom:7px; }
.section-card li a { font-size:13px; color:rgba(255,255,255,0.7); text-decoration:none; display:flex; align-items:flex-start; gap:6px; line-height:1.4; }
.section-card li a::before { content:'→'; color:#00A878; flex-shrink:0; margin-top:1px; }
.section-card li a:hover { color:#fff; }
.section-icon { font-size:1.5rem; }
.search-results { margin-bottom:2rem; }
.search-result-item { padding:14px 18px; border:1px solid rgba(255,255,255,0.08); border-radius:10px; margin-bottom:8px; text-decoration:none; display:block; transition:border-color .15s,background .15s; }
.search-result-item:hover { border-color:rgba(0,168,120,0.4); background:rgba(0,168,120,0.04); }
.search-result-item h4 { font-size:14px; font-weight:700; margin:0 0 4px; color:rgba(255,255,255,0.9); }
.search-result-item p  { font-size:12.5px; color:var(--muted); margin:0; }
.help-contact { text-align:center; padding:24px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.07); border-radius:12px; margin-top:2rem; }
</style>

<!-- Hero + Search -->
<div class="help-hero">
  <div class="container">
    <h1>🆘 <?= t('Help Centre','Centre d\'aide') ?></h1>
    <p><?= t('Find answers, guides and troubleshooting help for 237Biz.','Trouvez des réponses, guides et aide au dépannage pour 237Biz.') ?></p>
    <form class="help-search" method="GET" action="/help">
      <input type="text" name="q" placeholder="<?= e(t('Search articles...','Rechercher des articles...')) ?>" value="<?= e($search) ?>" autocomplete="off">
      <button type="submit">🔍 <?= t('Search','Rechercher') ?></button>
    </form>
  </div>
</div>

<div class="help-wrap">

  <!-- Language toggle -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:8px;">
    <a href="<?= SITE_URL ?>/dashboard" style="font-size:13px;color:var(--green);">← <?= t('Back to Dashboard','Tableau de bord') ?></a>
    <div style="display:flex;gap:6px;">
      <a href="?<?= http_build_query(array_merge($_GET,['lang'=>'en'])) ?>"
         style="padding:5px 14px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;<?= $currentLang==='en'?'background:rgba(0,168,120,0.2);color:#00A878;border:1px solid rgba(0,168,120,0.4);':'color:var(--muted);border:1px solid rgba(255,255,255,0.1);' ?>">EN</a>
      <a href="?<?= http_build_query(array_merge($_GET,['lang'=>'fr'])) ?>"
         style="padding:5px 14px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;<?= $currentLang==='fr'?'background:rgba(0,168,120,0.2);color:#00A878;border:1px solid rgba(0,168,120,0.4);':'color:var(--muted);border:1px solid rgba(255,255,255,0.1);' ?>">FR</a>
    </div>
  </div>

  <?php if ($search): ?>
  <!-- Search results -->
  <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:14px;">
    <?= count($searchResults) ?> <?= t('results for','résultats pour') ?> "<?= e($search) ?>"
  </h2>
  <?php if ($searchResults): ?>
  <div class="search-results">
    <?php foreach ($searchResults as $art): ?>
    <a href="<?= kb_url($art['slug']) ?>" class="search-result-item">
      <h4><?= e(kb_title($art)) ?></h4>
      <p><?= e($KB_SECTIONS[$art['section']][$currentLang] ?? $art['section']) ?></p>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p style="color:var(--muted);"><?= t('No articles found. Try different keywords or browse the sections below.','Aucun article trouvé. Essayez d\'autres mots-clés ou parcourez les sections ci-dessous.') ?></p>
  <?php endif; ?>
  <hr style="border-color:rgba(255,255,255,0.07);margin:2rem 0;">
  <?php endif; ?>

  <!-- Sections -->
  <div class="section-grid">
    <?php foreach ($KB_SECTIONS as $sectionKey => $sectionMeta): ?>
    <?php $articles = kb_section_articles($sectionKey); ?>
    <?php if (empty($articles)) continue; ?>
    <div class="section-card">
      <div class="section-icon"><?= $sectionMeta['icon'] ?></div>
      <h3><?= e($sectionMeta[$currentLang]) ?></h3>
      <ul>
        <?php foreach ($articles as $art): ?>
        <li>
          <a href="<?= kb_url($art['slug']) ?>">
            <?= e(kb_title($art)) ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Contact support -->
  <div class="help-contact">
    <div style="font-size:1.5rem;margin-bottom:8px;">💬</div>
    <h3 style="font-size:1rem;font-weight:700;margin-bottom:6px;"><?= t('Still need help?','Besoin d\'aide supplémentaire ?') ?></h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:14px;"><?= t('Our team is available to help you.','Notre équipe est disponible pour vous aider.') ?></p>
    <a href="mailto:support@237biz.net" class="btn btn-outline" style="margin-right:8px;">✉️ support@237biz.net</a>
    <a href="<?= SITE_URL ?>/help/contact-support" class="btn btn-primary" style="font-size:13px;">📖 <?= t('Contact Support','Contacter le support') ?></a>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
