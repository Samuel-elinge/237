<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireAdmin();

$isFr = lang() === 'fr';
$db   = db();

// ── Helpers ───────────────────────────────────────────────────
function slugify(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9\s-]/', '', $s);
    $s = preg_replace('/[\s-]+/', '-', trim($s));
    return $s;
}

function uniqueSlug(PDO $db, string $base, ?int $excludeId = null): string {
    $slug = $base; $i = 2;
    while (true) {
        $st = $db->prepare("SELECT id FROM blog_posts WHERE slug=?" . ($excludeId ? " AND id!=?" : ''));
        $args = [$slug]; if ($excludeId) $args[] = $excludeId;
        $st->execute($args);
        if (!$st->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

// ── Actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Create / Edit ────────────────────────────────────────
    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $titleEn  = trim($_POST['title_en'] ?? '');
        $titleFr  = trim($_POST['title_fr'] ?? '');
        $excEn    = trim($_POST['excerpt_en'] ?? '');
        $excFr    = trim($_POST['excerpt_fr'] ?? '');
        $bodyEn   = trim($_POST['body_en'] ?? '');
        $bodyFr   = trim($_POST['body_fr'] ?? '');
        $cat      = in_array($_POST['category'] ?? '', ['tips','news','spotlight','event']) ? $_POST['category'] : 'tips';
        $cover    = trim($_POST['cover_url'] ?? '');
        $author   = trim($_POST['author_name'] ?? '') ?: '237Biz Team';
        $status   = in_array($_POST['status'] ?? '', ['draft','published']) ? $_POST['status'] : 'draft';
        $metaKw   = trim($_POST['meta_keywords'] ?? '');
        $metaEn   = trim($_POST['meta_desc_en'] ?? '');
        $metaFr   = trim($_POST['meta_desc_fr'] ?? '');
        $pubAt    = $status === 'published' ? date('Y-m-d H:i:s') : null;

        if (!$titleEn) { flash('error', 'English title is required.'); redirect('/admin/blog.php'); }

        $baseSlug = slugify($titleEn ?: $titleFr);
        if (!$baseSlug) $baseSlug = 'post-' . time();

        if ($id) {
            // Edit: keep published_at unless newly publishing
            $existing = $db->prepare("SELECT status, published_at, slug FROM blog_posts WHERE id=?");
            $existing->execute([$id]);
            $row = $existing->fetch();
            if ($row && $row['status'] === 'published') $pubAt = $row['published_at'];
            elseif ($status === 'published' && (!$row || $row['status'] !== 'published')) $pubAt = date('Y-m-d H:i:s');

            $slug = uniqueSlug($db, $baseSlug, $id);
            $db->prepare("UPDATE blog_posts SET title_en=?,title_fr=?,excerpt_en=?,excerpt_fr=?,body_en=?,body_fr=?,category=?,cover_url=?,author_name=?,status=?,slug=?,published_at=?,meta_keywords=?,meta_desc_en=?,meta_desc_fr=? WHERE id=?")
               ->execute([$titleEn,$titleFr,$excEn,$excFr,$bodyEn,$bodyFr,$cat,$cover,$author,$status,$slug,$pubAt,$metaKw,$metaEn,$metaFr,$id]);
            flash('success', 'Post updated.');
        } else {
            $slug = uniqueSlug($db, $baseSlug);
            if ($status === 'published') $pubAt = date('Y-m-d H:i:s');
            $db->prepare("INSERT INTO blog_posts (slug,title_en,title_fr,excerpt_en,excerpt_fr,body_en,body_fr,category,cover_url,author_name,status,published_at,meta_keywords,meta_desc_en,meta_desc_fr) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$slug,$titleEn,$titleFr,$excEn,$excFr,$bodyEn,$bodyFr,$cat,$cover,$author,$status,$pubAt,$metaKw,$metaEn,$metaFr]);
            flash('success', 'Post created.');
        }
        redirect('/admin/blog.php');
    }

    // ── Toggle publish ────────────────────────────────────────
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $st = $db->prepare("SELECT status FROM blog_posts WHERE id=?");
            $st->execute([$id]); $row = $st->fetch();
            if ($row) {
                $newStatus = $row['status'] === 'published' ? 'draft' : 'published';
                $pubAt     = $newStatus === 'published' ? date('Y-m-d H:i:s') : null;
                $db->prepare("UPDATE blog_posts SET status=?, published_at=? WHERE id=?")
                   ->execute([$newStatus, $pubAt, $id]);
                flash('success', $newStatus === 'published' ? 'Post published.' : 'Post moved to draft.');
            }
        }
        redirect('/admin/blog.php');
    }

    // ── Delete ────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM blog_posts WHERE id=?")->execute([$id]);
            flash('success', 'Post deleted.');
        }
        redirect('/admin/blog.php');
    }
}

// ── Load edit target ──────────────────────────────────────────
$editPost = null;
if (isset($_GET['edit'])) {
    $st = $db->prepare("SELECT * FROM blog_posts WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $editPost = $st->fetch();
}

// ── List all posts ────────────────────────────────────────────
try {
    $posts = $db->query("SELECT id,slug,title_en,title_fr,category,status,views,published_at,created_at,author_name FROM blog_posts ORDER BY created_at DESC")->fetchAll();
} catch (Exception $e) { $posts = []; }

$pageTitle = 'Blog Manager — Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('blog'); adminNavAssets();
?>

<style>
/* Admin Blog — scoped to .ab- prefix */
.ab-wrap  { max-width: 1100px; margin: 0 auto; padding: 32px 20px 72px; }
.ab-hdr   { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 28px; }
.ab-hdr h1{ font-family: 'Fraunces', serif; font-size: 1.8rem; font-weight: 900; }

.ab-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer;
  font-size: 14px; font-weight: 700; text-decoration: none; transition: opacity .15s;
}
.ab-btn:hover { opacity: .85; }
.ab-btn-green  { background: #00A878; color: #fff; }
.ab-btn-yellow { background: #fcd116; color: #05160A; }
.ab-btn-red    { background: #e74c3c; color: #fff; }
.ab-btn-ghost  { background: transparent; border: 1.5px solid var(--border-color, #d0dfd5); color: inherit; }
.ab-btn-sm     { padding: 5px 12px; font-size: 12px; border-radius: 6px; }

/* Flash */
.ab-flash { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; }
.ab-flash.success { background: #e6faf4; color: #06734d; border: 1px solid #a7e8d4; }
.ab-flash.error   { background: #fef2f2; color: #b91c1c; border: 1px solid #f5b4b4; }

/* Table */
.ab-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border-color, #dde8e0); }
table.ab-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.ab-table th { padding: 10px 14px; text-align: left; font-weight: 700; font-size: 12px; letter-spacing: .05em; text-transform: uppercase; background: var(--th-bg, #f5faf7); color: var(--muted-fg, #5a6e5f); border-bottom: 1px solid var(--border-color, #dde8e0); white-space: nowrap; }
.ab-table td { padding: 11px 14px; border-bottom: 1px solid var(--border-color, #f0f5f2); vertical-align: middle; }
.ab-table tr:last-child td { border-bottom: none; }
.ab-table tr:hover td { background: var(--row-hover, #f9fdfb); }

.ab-pill { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 11px; font-weight: 800; color: #05160A; }
.ab-status-pub  { background: #00A878; color: #fff; }
.ab-status-draft{ background: #e5e7eb; color: #374151; }

/* Editor */
.ab-editor-card {
  border: 1.5px solid #00A878; border-radius: 14px;
  margin-bottom: 32px; overflow: hidden;
  background: var(--card-bg, #fff);
}
.ab-editor-head {
  background: #00A878; color: #fff; padding: 14px 20px;
  display: flex; align-items: center; justify-content: space-between;
}
.ab-editor-head h2 { font-family: 'Fraunces', serif; font-size: 1.1rem; font-weight: 800; }
.ab-editor-body { padding: 24px; background: #0e1e14; }

.ab-grid-2  { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.ab-grid-3  { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.ab-field label  { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--muted-fg, #5a6e5f); margin-bottom: 5px; }
.ab-field input,
.ab-field textarea,
.ab-field select {
  width: 100%; box-sizing: border-box;
  padding: 9px 12px; border-radius: 8px; font-size: 14px;
  border: 1.5px solid rgba(255,255,255,0.15);
  background: rgba(255,255,255,0.06); color: #fff;
  transition: border-color .15s;
}
.ab-field input::placeholder,
.ab-field textarea::placeholder { color: rgba(255,255,255,0.35); }
.ab-field input:focus,
.ab-field textarea:focus,
.ab-field select:focus { outline: none; border-color: #00A878; }
.ab-field textarea { resize: vertical; }

.ab-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--border-color, #dde8e0); margin-bottom: 16px; }
.ab-tab { padding: 8px 16px; font-size: 13px; font-weight: 700; cursor: pointer; border: none; background: none; color: var(--muted-fg, #7a8f80); border-bottom: 2px solid transparent; margin-bottom: -2px; }
.ab-tab.active { color: #00A878; border-bottom-color: #00A878; }

.ab-rte { display: none; }
.rte-wrap { border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; overflow: hidden; }
.rte-bar { display: flex; flex-wrap: wrap; gap: 2px; padding: 6px 8px; background: #071409; border-bottom: 1px solid rgba(255,255,255,0.1); }
.rte-bar button { background: none; border: 1px solid transparent; color: #8aab92; border-radius: 4px; padding: 3px 7px; font-size: 13px; cursor: pointer; line-height: 1.4; }
.rte-bar button:hover { background: rgba(0,168,120,0.15); border-color: rgba(0,168,120,0.3); color: #00A878; }
.rte-bar select { background: #071409; border: 1px solid transparent; color: #8aab92; border-radius: 4px; padding: 3px 6px; font-size: 13px; cursor: pointer; }
.rte-bar select:hover { border-color: rgba(0,168,120,0.3); color: #00A878; }
.rte-bar .rte-sep { width: 1px; background: rgba(255,255,255,0.1); margin: 2px 4px; }
.rte-body { min-height: 280px; padding: 14px 16px; background: #0e1e14; color: #d4e8d8; font-size: 15px; line-height: 1.7; outline: none; font-family: "DM Sans", sans-serif; }
.rte-body:empty::before { content: attr(data-placeholder); color: rgba(212,232,216,0.35); pointer-events: none; }
.rte-body h2 { font-size: 1.4em; margin: 1em 0 .4em; color: #fff; }
.rte-body h3 { font-size: 1.2em; margin: .9em 0 .3em; color: #e0f0e4; }
.rte-body a { color: #00A878; }
.rte-body blockquote { border-left: 3px solid #00A878; margin: .8em 0; padding-left: 14px; color: #9ab8a2; font-style: italic; }
.rte-body ul, .rte-body ol { padding-left: 24px; margin: .5em 0; }
.rte-link-bar { display: none; align-items: center; gap: 6px; padding: 6px 10px; background: #071409; border-top: 1px solid rgba(255,255,255,0.1); }
.rte-link-bar.open { display: flex; }
.rte-link-bar input { flex: 1; background: #0e1e14; border: 1px solid #1e3525; color: #d4e8d8; border-radius: 4px; padding: 4px 8px; font-size: 13px; }
.rte-link-bar button { background: #00A878; color: #fff; border: none; border-radius: 4px; padding: 4px 10px; font-size: 13px; cursor: pointer; }
.ab-editor-actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }

@media (max-width: 680px) {
  .ab-grid-2, .ab-grid-3 { grid-template-columns: 1fr; }
}
/* Dark theme — always applied since admin uses dark green */
.ab-editor-card { background: #0e1e14; border-color: #00A878; }
.ab-table-wrap  { background: #0e1e14; border-color: #1e3525; }
.ab-table th    { background: #071409; color: #8aab92; border-color: #1e3525; }
.ab-table td    { border-color: #1a2f20; color: #d4e8d8; }
.ab-table tr:hover td { background: #111d15; }
.ab-btn-ghost   { border-color: rgba(255,255,255,.25); color: #d4e8d8; }
.ab-flash.success { background: #0a2e1c; color: #34d399; border-color: #065f46; }
.ab-flash.error   { background: #2e0a0a; color: #f87171; border-color: #5f1111; }
.ab-tab { color: rgba(255,255,255,.5); }
.ab-tab.active { color: #00A878; }
.ab-tabs { border-color: #1e3525; }
</style>

<div class="ab-wrap">

  <div class="ab-hdr">
    <h1>📝 <?= t('Blog Manager','Gestion du Blog') ?></h1>
    <a href="/admin/blog.php?new=1" class="ab-btn ab-btn-green">+ <?= t('New Post','Nouvel Article') ?></a>
  </div>

  <?php if ($f = flash('success')): ?>
    <div class="ab-flash success">✅ <?= e($f) ?></div>
  <?php elseif ($f = flash('error')): ?>
    <div class="ab-flash error">⚠️ <?= e($f) ?></div>
  <?php endif; ?>

  <!-- ── Editor ──────────────────────────────────────────────── -->
  <?php if (isset($_GET['new']) || $editPost): ?>
  <?php $ep = $editPost ?? []; ?>
  <div class="ab-editor-card">
    <div class="ab-editor-head">
      <h2><?= $editPost ? t('Edit Post','Modifier l\'Article') : t('New Post','Nouvel Article') ?></h2>
      <a href="/admin/blog.php" class="ab-btn ab-btn-ghost ab-btn-sm" style="color:#fff;border-color:rgba(255,255,255,.4)">✕ <?= t('Cancel','Annuler') ?></a>
    </div>
    <div class="ab-editor-body">
      <form method="POST" action="/admin/blog.php">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="save">
        <?php if ($editPost): ?><input type="hidden" name="id" value="<?= $editPost['id'] ?>"><?php endif; ?>

        <div class="ab-grid-3" style="margin-bottom:18px">
          <div class="ab-field">
            <label><?= t('Category','Catégorie') ?></label>
            <select name="category">
              <?php foreach(['tips'=>t('Business Tips','Conseils'),'news'=>t('237Biz News','Actualités'),'spotlight'=>t('Partner Spotlight','Partenaire'),'event'=>t('Event','Événement')] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= ($ep['category'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ab-field">
            <label><?= t('Author','Auteur') ?></label>
            <input type="text" name="author_name" value="<?= e($ep['author_name'] ?? '237Biz Team') ?>">
          </div>
          <div class="ab-field">
            <label><?= t('Status','Statut') ?></label>
            <select name="status">
              <option value="draft"     <?= ($ep['status'] ?? '') === 'draft'     ? 'selected' : '' ?>><?= t('Draft','Brouillon') ?></option>
              <option value="published" <?= ($ep['status'] ?? '') === 'published' ? 'selected' : '' ?>><?= t('Published','Publié') ?></option>
            </select>
          </div>
        </div>

        <div class="ab-grid-2" style="margin-bottom:18px">
          <div class="ab-field">
            <label>🇬🇧 <?= t('Title (English)','Titre (Anglais)') ?> *</label>
            <input type="text" name="title_en" required value="<?= e($ep['title_en'] ?? '') ?>" placeholder="e.g. How to Grow Your Business Online">
          </div>
          <div class="ab-field">
            <label>🇫🇷 <?= t('Title (French)','Titre (Français)') ?></label>
            <input type="text" name="title_fr" value="<?= e($ep['title_fr'] ?? '') ?>" placeholder="ex. Comment développer votre entreprise en ligne">
          </div>
        </div>

        <div class="ab-grid-2" style="margin-bottom:18px">
          <div class="ab-field">
            <label>🇬🇧 <?= t('Excerpt (English)','Extrait (Anglais)') ?></label>
            <textarea name="excerpt_en" rows="2" placeholder="Short description for the blog listing…"><?= e($ep['excerpt_en'] ?? '') ?></textarea>
          </div>
          <div class="ab-field">
            <label>🇫🇷 <?= t('Excerpt (French)','Extrait (Français)') ?></label>
            <textarea name="excerpt_fr" rows="2" placeholder="Courte description pour la liste du blog…"><?= e($ep['excerpt_fr'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="ab-grid-2" style="margin-bottom:18px">
          <div class="ab-field">
            <label>🖼 <?= t('Cover Image URL','URL Image de Couverture') ?></label>
            <input type="url" name="cover_url" value="<?= e($ep['cover_url'] ?? '') ?>" placeholder="https://…">
          </div>
          <div class="ab-field">
            <label>🔑 <?= t('SEO Keywords','Mots-clés SEO') ?></label>
            <input type="text" name="meta_keywords" value="<?= e($ep['meta_keywords'] ?? '') ?>" placeholder="business tips, cameroon, 237biz">
          </div>
        </div>

        <div class="ab-grid-2" style="margin-bottom:18px">
          <div class="ab-field">
            <label>🇬🇧 <?= t('Meta Description (EN)','Méta Description (EN)') ?> <span style="font-weight:400;opacity:.6"><?= t('max 160 chars','max 160 car.') ?></span></label>
            <textarea name="meta_desc_en" rows="2" maxlength="320" placeholder="Short description for Google search results…"><?= e($ep['meta_desc_en'] ?? '') ?></textarea>
          </div>
          <div class="ab-field">
            <label>🇫🇷 <?= t('Meta Description (FR)','Méta Description (FR)') ?> <span style="font-weight:400;opacity:.6"><?= t('max 160 chars','max 160 car.') ?></span></label>
            <textarea name="meta_desc_fr" rows="2" maxlength="320" placeholder="Courte description pour les résultats Google…"><?= e($ep['meta_desc_fr'] ?? '') ?></textarea>
          </div>
        </div>

        <!-- Body tabs -->
        <div>
          <div class="ab-tabs">
            <button type="button" class="ab-tab active" onclick="showTab('en',this)">🇬🇧 English Body *</button>
            <button type="button" class="ab-tab" onclick="showTab('fr',this)">🇫🇷 Corps Français</button>
          </div>
          <div id="tab-en">
            <div class="ab-field">
              <div class="rte-wrap" id="rte-wrap-en"></div>
              <textarea name="body_en" id="body_en" class="ab-rte"><?= e($ep['body_en'] ?? '') ?></textarea>
            </div>
          </div>
          <div id="tab-fr" hidden>
            <div class="ab-field">
              <div class="rte-wrap" id="rte-wrap-fr"></div>
              <textarea name="body_fr" id="body_fr" class="ab-rte"><?= e($ep['body_fr'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <div class="ab-editor-actions">
          <button type="submit" class="ab-btn ab-btn-green">💾 <?= t('Save','Enregistrer') ?></button>
          <?php if (!empty($ep['slug'])): ?>
            <a href="<?= SITE_URL ?>/blog/<?= e($ep['slug']) ?>" target="_blank" class="ab-btn ab-btn-ghost">👁 <?= t('Preview','Aperçu') ?></a>
          <?php endif; ?>
          <a href="/admin/blog.php" class="ab-btn ab-btn-ghost"><?= t('Cancel','Annuler') ?></a>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Post list ───────────────────────────────────────────── -->
  <div class="ab-table-wrap">
    <table class="ab-table">
      <thead>
        <tr>
          <th><?= t('Title','Titre') ?></th>
          <th><?= t('Category','Catégorie') ?></th>
          <th><?= t('Status','Statut') ?></th>
          <th><?= t('Views','Vues') ?></th>
          <th><?= t('Date','Date') ?></th>
          <th><?= t('Actions','Actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($posts)): ?>
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted-fg,#7a8f80)"><?= t('No posts yet. Create one above.','Aucun article. Créez-en un ci-dessus.') ?></td></tr>
        <?php endif; ?>
        <?php foreach ($posts as $p):
          $t_ = $isFr && $p['title_fr'] ? $p['title_fr'] : $p['title_en'];
        ?>
        <tr>
          <td>
            <a href="/blog/<?= e($p['slug']) ?>" target="_blank" style="color:#00A878;font-weight:600"><?= e($t_) ?></a>
            <div style="font-size:11px;color:var(--muted-fg,#7a8f80);margin-top:2px"><?= e($p['slug']) ?></div>
          </td>
          <td><?= e(ucfirst($p['category'])) ?></td>
          <td>
            <span class="ab-pill <?= $p['status'] === 'published' ? 'ab-status-pub' : 'ab-status-draft' ?>">
              <?= $p['status'] === 'published' ? t('Published','Publié') : t('Draft','Brouillon') ?>
            </span>
          </td>
          <td><?= number_format($p['views']) ?></td>
          <td style="white-space:nowrap;font-size:12px">
            <?= $p['published_at'] ? date('j M Y', strtotime($p['published_at'])) : date('j M Y', strtotime($p['created_at'])) ?>
          </td>
          <td style="white-space:nowrap">
            <a href="<?= SITE_URL ?>/blog/<?= e($p['slug']) ?>" target="_blank" class="ab-btn ab-btn-ghost ab-btn-sm" title="<?= t('Preview','Aperçu') ?>">👁</a>
            <a href="/admin/blog.php?edit=<?= $p['id'] ?>" class="ab-btn ab-btn-yellow ab-btn-sm">✏️</a>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="ab-btn ab-btn-ghost ab-btn-sm" title="<?= $p['status'] === 'published' ? 'Unpublish' : 'Publish' ?>">
                <?= $p['status'] === 'published' ? '🔒' : '🚀' ?>
              </button>
            </form>
            <form method="POST" style="display:inline" data-confirm="<?= t('Delete this post?','Supprimer cet article ?') ?>">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="ab-btn ab-btn-red ab-btn-sm">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<script>
(function() {
  var savedRange = null;

  function buildRTE(wrapId, textareaId, placeholder) {
    var wrap = document.getElementById(wrapId);
    var textarea = document.getElementById(textareaId);
    if (!wrap || !textarea) return;

    // Toolbar HTML
    wrap.innerHTML =
      '<div class="rte-bar">' +
        '<select onchange="rteBlock(this,\''+textareaId+'\')">' +
          '<option value="p">Paragraph</option>' +
          '<option value="h2">Heading 2</option>' +
          '<option value="h3">Heading 3</option>' +
        '</select>' +
        '<span class="rte-sep"></span>' +
        '<button type="button" title="Bold" onclick="rteCmd(\'bold\',\''+textareaId+'\')"><b>B</b></button>' +
        '<button type="button" title="Italic" onclick="rteCmd(\'italic\',\''+textareaId+'\')"><i>I</i></button>' +
        '<button type="button" title="Underline" onclick="rteCmd(\'underline\',\''+textareaId+'\')"><u>U</u></button>' +
        '<button type="button" title="Strikethrough" onclick="rteCmd(\'strikeThrough\',\''+textareaId+'\')" style="text-decoration:line-through">S</button>' +
        '<span class="rte-sep"></span>' +
        '<button type="button" title="Bullet list" onclick="rteCmd(\'insertUnorderedList\',\''+textareaId+'\')">• List</button>' +
        '<button type="button" title="Numbered list" onclick="rteCmd(\'insertOrderedList\',\''+textareaId+'\')">1. List</button>' +
        '<span class="rte-sep"></span>' +
        '<button type="button" title="Blockquote" onclick="rteBlockquote(\''+textareaId+'\')">" Quote</button>' +
        '<span class="rte-sep"></span>' +
        '<button type="button" title="Insert link" onclick="rteShowLink(\''+textareaId+'\')">🔗 Link</button>' +
        '<button type="button" title="Remove formatting" onclick="rteCmd(\'removeFormat\',\''+textareaId+'\')">✕ Clear</button>' +
      '</div>' +
      '<div class="rte-link-bar" id="rte-link-'+textareaId+'">' +
        '<input type="text" id="rte-url-'+textareaId+'" placeholder="https://" />' +
        '<button type="button" onclick="rteInsertLink(\''+textareaId+'\')">Insert</button>' +
        '<button type="button" onclick="rteCancelLink(\''+textareaId+'\')" style="background:#333">Cancel</button>' +
      '</div>' +
      '<div class="rte-body" contenteditable="true" id="rte-'+textareaId+'" data-placeholder="'+placeholder+'"></div>';

    var editor = document.getElementById('rte-' + textareaId);

    // Load existing content
    if (textarea.value) {
      editor.innerHTML = textarea.value;
    }

    // Sync to textarea on input
    editor.addEventListener('input', function() {
      textarea.value = editor.innerHTML;
    });
    editor.addEventListener('blur', function() {
      textarea.value = editor.innerHTML;
    });
  }

  window.rteCmd = function(cmd, textareaId) {
    document.getElementById('rte-' + textareaId).focus();
    document.execCommand(cmd, false, null);
    document.getElementById(textareaId).value = document.getElementById('rte-' + textareaId).innerHTML;
  };

  window.rteBlock = function(sel, textareaId) {
    document.getElementById('rte-' + textareaId).focus();
    document.execCommand('formatBlock', false, '<' + sel.value + '>');
    document.getElementById(textareaId).value = document.getElementById('rte-' + textareaId).innerHTML;
    sel.value = 'p';
  };

  window.rteBlockquote = function(textareaId) {
    document.getElementById('rte-' + textareaId).focus();
    document.execCommand('formatBlock', false, '<blockquote>');
    document.getElementById(textareaId).value = document.getElementById('rte-' + textareaId).innerHTML;
  };

  window.rteShowLink = function(textareaId) {
    savedRange = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0).cloneRange() : null;
    var bar = document.getElementById('rte-link-' + textareaId);
    bar.classList.toggle('open');
    if (bar.classList.contains('open')) document.getElementById('rte-url-' + textareaId).focus();
  };

  window.rteInsertLink = function(textareaId) {
    var url = document.getElementById('rte-url-' + textareaId).value.trim();
    if (!url) return;
    var editor = document.getElementById('rte-' + textareaId);
    editor.focus();
    if (savedRange) {
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(savedRange);
    }
    document.execCommand('createLink', false, url);
    document.getElementById(textareaId).value = editor.innerHTML;
    rteCancelLink(textareaId);
  };

  window.rteCancelLink = function(textareaId) {
    var bar = document.getElementById('rte-link-' + textareaId);
    bar.classList.remove('open');
    document.getElementById('rte-url-' + textareaId).value = '';
  };

  document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('rte-wrap-en')) {
      buildRTE('rte-wrap-en', 'body_en', 'Write your post content here…');
    }
  });

  window.showTab = function(lang, btn) {
    document.getElementById('tab-en').hidden = lang !== 'en';
    document.getElementById('tab-fr').hidden = lang !== 'fr';
    document.querySelectorAll('.ab-tab').forEach(function(t) { t.classList.remove('active'); });
    btn.classList.add('active');
    if (lang === 'fr' && !document.getElementById('rte-body_fr')) {
      buildRTE('rte-wrap-fr', 'body_fr', 'Rédigez votre article ici…');
    }
  };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
