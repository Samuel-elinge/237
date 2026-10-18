<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$cats = db()->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();
$locs = db()->query("SELECT * FROM locations ORDER BY sort_order")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $introEn    = trim($_POST['intro_en'] ?? '');
    $introFr    = trim($_POST['intro_fr'] ?? '');
    $metaTitleEn = trim($_POST['meta_title_en'] ?? '');
    $metaTitleFr = trim($_POST['meta_title_fr'] ?? '');
    $metaDescEn  = trim($_POST['meta_desc_en'] ?? '');
    $metaDescFr  = trim($_POST['meta_desc_fr'] ?? '');

    if (!$categoryId && !$locationId) {
        flash('error', t('Select a category, a location, or both.','Sélectionnez une catégorie, une ville, ou les deux.'));
        redirect(SITE_URL . '/admin/seo-content.php');
    }

    // Upsert — unique key on (category_id, location_id) handles the combo logic.
    // NULL-NULL pairs aren't unique-constrained in MySQL the way you'd expect,
    // so we check existence manually instead of relying purely on ON DUPLICATE KEY.
    $existing = db()->prepare("
        SELECT id FROM seo_content
        WHERE category_id <=> ? AND location_id <=> ?
    ");
    $existing->execute([$categoryId, $locationId]);
    $existingId = $existing->fetchColumn();

    if ($existingId) {
        db()->prepare("
            UPDATE seo_content SET
            intro_en=?, intro_fr=?, meta_title_en=?, meta_title_fr=?, meta_desc_en=?, meta_desc_fr=?
            WHERE id=?
        ")->execute([$introEn, $introFr, $metaTitleEn, $metaTitleFr, $metaDescEn, $metaDescFr, $existingId]);
    } else {
        db()->prepare("
            INSERT INTO seo_content
            (category_id, location_id, intro_en, intro_fr, meta_title_en, meta_title_fr, meta_desc_en, meta_desc_fr)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([$categoryId, $locationId, $introEn, $introFr, $metaTitleEn, $metaTitleFr, $metaDescEn, $metaDescFr]);
    }

    flash('success', t('Content saved.','Contenu enregistré.'));
    redirect(SITE_URL . '/admin/seo-content.php');
}

// Delete
if (isset($_GET['delete'])) {
    db()->prepare("DELETE FROM seo_content WHERE id=?")->execute([(int)$_GET['delete']]);
    flash('success', t('Entry deleted.','Entrée supprimée.'));
    redirect(SITE_URL . '/admin/seo-content.php');
}

// Edit — load existing entry into form
$editEntry = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare("SELECT * FROM seo_content WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $editEntry = $st->fetch();
}

// List all existing entries with readable labels
$entries = db()->query("
    SELECT s.*, c.name_en AS cat_name, l.name_en AS loc_name
    FROM seo_content s
    LEFT JOIN categories c ON c.id = s.category_id
    LEFT JOIN locations l ON l.id = s.location_id
    ORDER BY s.updated_at DESC
")->fetchAll();

$pageTitle = 'SEO Content Editor — Admin 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📝 SEO Content Editor</h1>
</div></div>

<section class="page-section"><div class="container">

  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
    <a href="<?= SITE_URL ?>/agent-health-checks.php" class="filter-tab">🩺 Health Check</a>
    <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
    <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
    <a href="<?= SITE_URL ?>/admin/leads.php" class="filter-tab">🌐 Website Leads</a>
    <a href="<?= SITE_URL ?>/admin/enquiries.php" class="filter-tab">📬 Enquiries</a>
    <a href="<?= SITE_URL ?>/admin/claims.php" class="filter-tab">🏢 Claims</a>
    <a href="<?= SITE_URL ?>/admin/reviews.php" class="filter-tab">⭐ Reviews</a>
    <a href="<?= SITE_URL ?>/admin/categories.php" class="filter-tab">📂 Categories</a>
    <a href="<?= SITE_URL ?>/admin/locations.php" class="filter-tab">📍 Locations</a>
    <a href="<?= SITE_URL ?>/admin/subscribers.php" class="filter-tab">📬 Newsletter</a>
    <a href="<?= SITE_URL ?>/admin/promos.php" class="filter-tab">🎁 Promo Codes</a>
    <a href="<?= SITE_URL ?>/admin/packages.php" class="filter-tab">💳 Packages</a>
    <a href="<?= SITE_URL ?>/admin/seo-content.php" class="filter-tab active">📝 SEO Content</a>
    <a href="<?= SITE_URL ?>/dashboard" class="filter-tab">← Dashboard</a>
  </div>

  <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1.5rem;max-width:680px;">
    <?= t('Write a short intro paragraph and custom page title/description for any category, location, or specific combination (e.g. "Restaurants in Limbe"). This text appears at the top of the matching search page and helps it rank in Google. Leave location blank for a category-wide intro, leave category blank for a city-wide intro, or set both for a specific combo page.',
          'Rédigez un court paragraphe d\'introduction et un titre/description de page personnalisés pour toute catégorie, ville, ou combinaison spécifique (ex. "Restaurants à Limbe"). Ce texte apparaît en haut de la page de recherche correspondante et aide au référencement Google. Laissez la ville vide pour une intro générale à la catégorie, laissez la catégorie vide pour une intro générale à la ville, ou définissez les deux pour une page de combo spécifique.') ?>
  </p>

  <!-- FORM -->
  <div class="listing-widget" style="border-color:rgba(0,168,120,0.25);background:rgba(0,168,120,0.02);margin-bottom:2rem;">
    <h4 style="margin-bottom:1.25rem;"><?= $editEntry ? t('Edit Entry','Modifier l\'Entrée') : t('+ New Entry','+ Nouvelle Entrée') ?></h4>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">

      <div class="form-row">
        <div class="form-group">
          <label><?= t('Category','Catégorie') ?> <span style="color:var(--muted-2);font-weight:400;">(<?= t('optional','optionnel') ?>)</span></label>
          <select name="category_id">
            <option value=""><?= t('— None —','— Aucune —') ?></option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= $c['id'] ?>" <?= ($editEntry && $editEntry['category_id'] == $c['id']) ? 'selected' : '' ?>>
                <?= $c['icon'] ?> <?= e($c['name_en']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label><?= t('Location','Ville') ?> <span style="color:var(--muted-2);font-weight:400;">(<?= t('optional','optionnel') ?>)</span></label>
          <select name="location_id">
            <option value=""><?= t('— None —','— Aucune —') ?></option>
            <?php foreach ($locs as $l): ?>
              <option value="<?= $l['id'] ?>" <?= ($editEntry && $editEntry['location_id'] == $l['id']) ? 'selected' : '' ?>>
                <?= e($l['name_en']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label><?= t('Intro Paragraph (English)','Paragraphe d\'Intro (Anglais)') ?></label>
        <textarea name="intro_en" rows="4" placeholder="<?= t('e.g. Looking for the best restaurants in Limbe? Browse verified eateries, from local Cameroonian cuisine to international dining...','ex. À la recherche des meilleurs restaurants à Limbe ? Parcourez des établissements vérifiés...') ?>"><?= e($editEntry['intro_en'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label><?= t('Intro Paragraph (French)','Paragraphe d\'Intro (Français)') ?></label>
        <textarea name="intro_fr" rows="4"><?= e($editEntry['intro_fr'] ?? '') ?></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label><?= t('Page Title (English)','Titre de Page (Anglais)') ?> <span style="color:var(--muted-2);font-weight:400;">(<?= t('optional override','remplacement optionnel') ?>)</span></label>
          <input type="text" name="meta_title_en" maxlength="255" value="<?= e($editEntry['meta_title_en'] ?? '') ?>" placeholder="<?= t('Leave blank to auto-generate','Laisser vide pour auto-générer') ?>">
        </div>
        <div class="form-group">
          <label><?= t('Page Title (French)','Titre de Page (Français)') ?></label>
          <input type="text" name="meta_title_fr" maxlength="255" value="<?= e($editEntry['meta_title_fr'] ?? '') ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label><?= t('Meta Description (English)','Méta Description (Anglais)') ?></label>
          <textarea name="meta_desc_en" rows="2" maxlength="320"><?= e($editEntry['meta_desc_en'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label><?= t('Meta Description (French)','Méta Description (Français)') ?></label>
          <textarea name="meta_desc_fr" rows="2" maxlength="320"><?= e($editEntry['meta_desc_fr'] ?? '') ?></textarea>
        </div>
      </div>

      <div style="display:flex;gap:0.75rem;">
        <button type="submit" class="btn btn-primary"><?= $editEntry ? t('Update Entry','Mettre à Jour') : t('Save Entry','Enregistrer') ?></button>
        <?php if ($editEntry): ?>
          <a href="<?= SITE_URL ?>/admin/seo-content.php" class="btn btn-outline"><?= t('Cancel Edit','Annuler') ?></a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- EXISTING ENTRIES LIST -->
  <h4 style="margin-bottom:1rem;"><?= t('Existing Entries','Entrées Existantes') ?> (<?= count($entries) ?>)</h4>
  <?php if (!$entries): ?>
    <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
      <div style="font-size:2.5rem;margin-bottom:0.75rem;">📝</div>
      <p style="color:var(--muted);"><?= t('No custom content yet — add your first entry above.','Aucun contenu personnalisé pour l\'instant — ajoutez votre première entrée ci-dessus.') ?></p>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:0.75rem;">
      <?php foreach ($entries as $entry): ?>
        <div class="listing-widget" style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
          <div style="flex:1;min-width:240px;">
            <div style="font-weight:500;color:var(--white);font-size:0.9rem;margin-bottom:0.3rem;">
              <?php if ($entry['cat_name'] && $entry['loc_name']): ?>
                <?= e($entry['cat_name']) ?> <?= t('in','à') ?> <?= e($entry['loc_name']) ?>
              <?php elseif ($entry['cat_name']): ?>
                <?= e($entry['cat_name']) ?> <span style="color:var(--muted-2);font-weight:400;font-size:0.78rem;">(<?= t('all cities','toutes villes') ?>)</span>
              <?php elseif ($entry['loc_name']): ?>
                <?= e($entry['loc_name']) ?> <span style="color:var(--muted-2);font-weight:400;font-size:0.78rem;">(<?= t('all categories','toutes catégories') ?>)</span>
              <?php endif; ?>
            </div>
            <p style="font-size:0.8rem;color:var(--muted);line-height:1.5;">
              <?= e(mb_substr($entry['intro_en'] ?? '', 0, 140)) ?><?= mb_strlen($entry['intro_en'] ?? '') > 140 ? '...' : '' ?>
            </p>
            <span style="font-size:0.7rem;color:var(--muted-2);"><?= t('Updated','Mis à jour') ?> <?= timeAgo($entry['updated_at']) ?></span>
          </div>
          <div style="display:flex;gap:0.5rem;flex-shrink:0;">
            <a href="?edit=<?= $entry['id'] ?>" class="btn btn-outline btn-sm"><?= t('Edit','Modifier') ?></a>
            <a href="?delete=<?= $entry['id'] ?>" class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);"
               onclick="return confirm('<?= t('Delete this entry?','Supprimer cette entrée ?') ?>')">✗</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
