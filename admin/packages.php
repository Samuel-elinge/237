<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $id           = (int)($_POST['pkg_id'] ?? 0);
        $nameEn       = trim($_POST['name_en'] ?? '');
        $nameFr       = trim($_POST['name_fr'] ?? '');
        $price        = (int)($_POST['price_xaf'] ?? 0);
        $duration     = (int)($_POST['duration_days'] ?? 0);
        $wordLimit    = (int)($_POST['description_word_limit'] ?? 30);
        $isFeatured   = isset($_POST['is_featured']) ? 1 : 0;
        $isVerified   = isset($_POST['is_verified']) ? 1 : 0;
        $active       = isset($_POST['active']) ? 1 : 0;
        $sortOrder    = (int)($_POST['sort_order'] ?? 0);

        if ($id && $nameEn) {
            db()->prepare("
                UPDATE listing_packages SET
                name_en=?, name_fr=?, price_xaf=?, duration_days=?, description_word_limit=?,
                is_featured=?, is_verified=?, active=?, sort_order=?
                WHERE id=?
            ")->execute([$nameEn, $nameFr ?: $nameEn, $price, $duration, $wordLimit,
                         $isFeatured, $isVerified, $active, $sortOrder, $id]);
            flash('success', t('Package "','Forfait "') . $nameEn . t('" updated.','" mis à jour.'));
        }
    } elseif ($action === 'create') {
        $nameEn     = trim($_POST['name_en'] ?? '');
        $nameFr     = trim($_POST['name_fr'] ?? '');
        $price      = (int)($_POST['price_xaf'] ?? 0);
        $duration   = (int)($_POST['duration_days'] ?? 0);
        $wordLimit  = (int)($_POST['description_word_limit'] ?? 150);
        $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
        $isVerified = isset($_POST['is_verified']) ? 1 : 0;
        $sortOrder  = (int)($_POST['sort_order'] ?? 99);

        if ($nameEn) {
            db()->prepare("
                INSERT INTO listing_packages
                (name_en, name_fr, price_xaf, duration_days, description_word_limit, is_featured, is_verified, sort_order, active)
                VALUES (?,?,?,?,?,?,?,?,1)
            ")->execute([$nameEn, $nameFr ?: $nameEn, $price, $duration, $wordLimit, $isFeatured, $isVerified, $sortOrder]);
            flash('success', t('Package created.','Forfait créé.'));
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['pkg_id'] ?? 0);
        db()->prepare("UPDATE listing_packages SET active = !active WHERE id=?")->execute([$id]);
    } elseif ($action === 'delete') {
        $id = (int)($_POST['pkg_id'] ?? 0);
        // Block delete if any payments reference this package
        $inUse = db()->prepare("SELECT COUNT(*) FROM listing_payments WHERE package_id=?");
        $inUse->execute([$id]);
        if ($inUse->fetchColumn() > 0) {
            flash('error', t('Cannot delete — this package has payment history. Disable it instead.','Impossible de supprimer — ce forfait a un historique de paiements. Désactivez-le plutôt.'));
        } else {
            db()->prepare("DELETE FROM listing_packages WHERE id=?")->execute([$id]);
            flash('success', t('Package deleted.','Forfait supprimé.'));
        }
    }
    redirect(SITE_URL . '/admin/packages.php');
}

$packages = db()->query("
    SELECT p.*, (SELECT COUNT(*) FROM listing_payments WHERE package_id=p.id) AS use_count
    FROM listing_packages p ORDER BY p.sort_order, p.price_xaf
")->fetchAll();

$pageTitle = 'Listing Packages — Admin 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">💳 Listing Packages</h1>
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
    <a href="<?= SITE_URL ?>/admin/packages.php" class="filter-tab active">💳 Packages</a>
    <a href="<?= SITE_URL ?>/admin/seo-content.php" class="filter-tab">📝 SEO Content</a>
      <a href="<?= SITE_URL ?>/admin/emails.php"                class="filter-tab">✉️ Emails</a>
      <a href="<?= SITE_URL ?>/admin/automation/"              class="filter-tab">🤖 Automation</a>
      <a href="<?= SITE_URL ?>/admin/dashboard.php"            class="filter-tab">📊 Admin Dashboard</a>
      <a href="<?= SITE_URL ?>/admin/analytics-overview.php"   class="filter-tab">🌍 Analytics</a>
      <a href="<?= SITE_URL ?>/admin/health-check-overview.php" class="filter-tab">🩺 Health Check</a>
      <a href="<?= SITE_URL ?>/admin/manage-agents.php"        class="filter-tab">👔 Agents</a>
      <a href="<?= SITE_URL ?>/admin/manage-creators.php"      class="filter-tab">🎬 Creators</a>
      <a href="<?= SITE_URL ?>/admin/manage-campaigns.php"     class="filter-tab">📣 Campaigns</a>
      <a href="<?= SITE_URL ?>/admin/manage-referrals.php"     class="filter-tab">💰 Referrals</a>
    <a href="<?= SITE_URL ?>/dashboard" class="filter-tab">← Dashboard</a>
  </div>

  <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1.5rem;max-width:640px;">
    <?= t('Changes here apply to all future purchases immediately. Existing approved listings keep whatever duration/price they already paid for — this does not retroactively change live listings.',
          'Les modifications s\'appliquent immédiatement à tous les achats futurs. Les annonces déjà approuvées conservent la durée/prix déjà payés — cela ne modifie pas rétroactivement les annonces en cours.') ?>
  </p>

  <!-- EXISTING PACKAGES -->
  <div style="display:flex;flex-direction:column;gap:1rem;margin-bottom:2rem;">
    <?php foreach ($packages as $pkg): ?>
      <div class="listing-widget" style="<?= !$pkg['active'] ? 'opacity:0.5;' : '' ?>">
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="pkg_id" value="<?= $pkg['id'] ?>">

          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.5rem;">
            <h4 style="margin-bottom:0;">
              <?= e($pkg['name_en']) ?>
              <?php if ($pkg['is_featured']): ?><span class="badge badge-approved" style="margin-left:0.5rem;">⭐ Featured</span><?php endif; ?>
              <?php if (!$pkg['active']): ?><span class="badge badge-rejected" style="margin-left:0.5rem;">Inactive</span><?php endif; ?>
            </h4>
            <span style="font-size:0.78rem;color:var(--muted-2);"><?= $pkg['use_count'] ?> <?= t('purchases to date','achats à ce jour') ?></span>
          </div>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1rem;">
            <div class="form-group" style="margin-bottom:0;">
              <label><?= t('Name (English)','Nom (Anglais)') ?></label>
              <input type="text" name="name_en" value="<?= e($pkg['name_en']) ?>" required>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label><?= t('Name (French)','Nom (Français)') ?></label>
              <input type="text" name="name_fr" value="<?= e($pkg['name_fr']) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label><?= t('Price (XAF)','Prix (XAF)') ?></label>
              <input type="number" name="price_xaf" value="<?= $pkg['price_xaf'] ?>" min="0" step="500">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label><?= t('Duration (days)','Durée (jours)') ?></label>
              <input type="number" name="duration_days" value="<?= $pkg['duration_days'] ?>" min="0">
              <small style="color:var(--muted-2);font-size:0.7rem;">0 = <?= t('never expires','n\'expire jamais') ?></small>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label><?= t('Description Word Limit','Limite de mots') ?></label>
              <input type="number" name="description_word_limit" value="<?= $pkg['description_word_limit'] ?? 30 ?>" min="10">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label><?= t('Sort Order','Ordre') ?></label>
              <input type="number" name="sort_order" value="<?= $pkg['sort_order'] ?>" min="0">
            </div>
          </div>

          <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem;">
            <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.83rem;color:var(--muted);cursor:pointer;">
              <input type="checkbox" name="is_featured" <?= $pkg['is_featured'] ? 'checked' : '' ?> style="accent-color:var(--yellow);">
              ⭐ <?= t('Featured badge + top placement','Badge vedette + placement prioritaire') ?>
            </label>
            <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.83rem;color:var(--muted);cursor:pointer;">
              <input type="checkbox" name="is_verified" <?= $pkg['is_verified'] ? 'checked' : '' ?> style="accent-color:var(--green);">
              ✓ <?= t('Verified badge','Badge vérifié') ?>
            </label>
            <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.83rem;color:var(--muted);cursor:pointer;">
              <input type="checkbox" name="active" <?= $pkg['active'] ? 'checked' : '' ?> style="accent-color:var(--green);">
              <?= t('Active (visible to users)','Actif (visible aux utilisateurs)') ?>
            </label>
          </div>

          <div style="display:flex;gap:0.5rem;">
            <button type="submit" class="btn btn-primary btn-sm"><?= t('Save Changes','Enregistrer') ?></button>
          </div>
        </form>

        <?php if ($pkg['use_count'] == 0): ?>
          <form method="POST" style="margin-top:0.5rem;" onsubmit="return confirm('<?= t('Delete this package permanently?','Supprimer ce forfait définitivement ?') ?>')">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="pkg_id" value="<?= $pkg['id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);">✗ <?= t('Delete Package','Supprimer le Forfait') ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- CREATE NEW PACKAGE -->
  <div class="listing-widget" style="border-color:rgba(0,168,120,0.25);background:rgba(0,168,120,0.02);">
    <h4 style="margin-bottom:1.25rem;">+ <?= t('Add New Package','Ajouter un Nouveau Forfait') ?></h4>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="create">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1rem;">
        <div class="form-group" style="margin-bottom:0;">
          <label><?= t('Name (English) *','Nom (Anglais) *') ?></label>
          <input type="text" name="name_en" required placeholder="e.g. Featured — 180 days">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label><?= t('Name (French)','Nom (Français)') ?></label>
          <input type="text" name="name_fr" placeholder="ex. Vedette — 180 jours">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label><?= t('Price (XAF)','Prix (XAF)') ?></label>
          <input type="number" name="price_xaf" value="0" min="0" step="500">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label><?= t('Duration (days)','Durée (jours)') ?></label>
          <input type="number" name="duration_days" value="30" min="0">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label><?= t('Description Word Limit','Limite de mots') ?></label>
          <input type="number" name="description_word_limit" value="150" min="10">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label><?= t('Sort Order','Ordre') ?></label>
          <input type="number" name="sort_order" value="<?= count($packages) + 1 ?>" min="0">
        </div>
      </div>
      <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem;">
        <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.83rem;color:var(--muted);cursor:pointer;">
          <input type="checkbox" name="is_featured" style="accent-color:var(--yellow);"> ⭐ <?= t('Featured badge','Badge vedette') ?>
        </label>
        <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.83rem;color:var(--muted);cursor:pointer;">
          <input type="checkbox" name="is_verified" style="accent-color:var(--green);"> ✓ <?= t('Verified badge','Badge vérifié') ?>
        </label>
      </div>
      <button type="submit" class="btn btn-primary">+ <?= t('Create Package','Créer le Forfait') ?></button>
    </form>
  </div>

</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
