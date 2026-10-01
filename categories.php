<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '');
        $icon   = trim($_POST['icon'] ?? '📋');
        $order  = (int)($_POST['sort_order'] ?? 0);
        if ($nameEn && $nameFr) {
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($nameEn));
            $slug = trim($slug, '-');
            // Ensure unique slug
            $exists = db()->prepare("SELECT id FROM categories WHERE slug=?");
            $exists->execute([$slug]);
            if ($exists->fetch()) $slug .= '-' . time();
            db()->prepare("INSERT INTO categories (name_en,name_fr,slug,icon,sort_order) VALUES (?,?,?,?,?)")
                 ->execute([$nameEn, $nameFr, $slug, $icon, $order]);
            flash('success', "Category '$nameEn' created.");
        }
    } elseif ($action === 'update') {
        $id     = (int)($_POST['cat_id'] ?? 0);
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '');
        $icon   = trim($_POST['icon'] ?? '📋');
        $order  = (int)($_POST['sort_order'] ?? 0);
        if ($id && $nameEn) {
            db()->prepare("UPDATE categories SET name_en=?,name_fr=?,icon=?,sort_order=? WHERE id=?")
                 ->execute([$nameEn, $nameFr, $icon, $order, $id]);
            flash('success', "Category updated.");
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['cat_id'] ?? 0);
        $count = db()->prepare("SELECT COUNT(*) FROM listings WHERE category_id=?");
        $count->execute([$id]);
        if ($count->fetchColumn() > 0) {
            flash('error', 'Cannot delete — category has listings assigned to it.');
        } else {
            db()->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
            flash('success', 'Category deleted.');
        }
    }
    redirect(SITE_URL . '/admin/categories.php');
}

$cats = db()->query("
    SELECT c.*, COUNT(l.id) AS listing_count
    FROM categories c
    LEFT JOIN listings l ON l.category_id=c.id AND l.status='approved'
    GROUP BY c.id ORDER BY c.sort_order, c.name_en
")->fetchAll();

$pageTitle = 'Categories — Admin 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📂 Categories</h1>
</div></div>

<section class="page-section"><div class="container">

  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
    <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
    <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
    <a href="<?= SITE_URL ?>/admin/categories.php" class="filter-tab active">📂 Categories</a>
    <a href="<?= SITE_URL ?>/admin/locations.php" class="filter-tab">📍 Locations</a>
    <a href="<?= SITE_URL ?>/admin/promos.php" class="filter-tab">🎁 Promos</a>
  </div>

  <!-- ADD NEW CATEGORY -->
  <div class="listing-widget" style="margin-bottom:2rem;">
    <h4 style="margin-bottom:1.25rem;">+ Add New Category</h4>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="create">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;align-items:end;">
        <div class="form-group" style="margin-bottom:0;">
          <label>Name (English) *</label>
          <input type="text" name="name_en" required placeholder="e.g. Hotels & Accommodation">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Name (French) *</label>
          <input type="text" name="name_fr" required placeholder="e.g. Hôtels & Hébergement">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Icon (emoji)</label>
          <input type="text" name="icon" value="📋" maxlength="5" style="font-size:1.2rem;text-align:center;">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Sort Order</label>
          <input type="number" name="sort_order" value="<?= count($cats) + 1 ?>" min="0">
        </div>
        <button type="submit" class="btn btn-primary" style="align-self:end;">+ Add Category</button>
      </div>
    </form>
  </div>

  <!-- CATEGORIES LIST -->
  <div class="listing-widget">
    <h4 style="margin-bottom:1.25rem;">All Categories (<?= count($cats) ?>)</h4>
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead><tr><th>Icon</th><th>English</th><th>French</th><th>Slug</th><th>Listings</th><th>Order</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($cats as $cat): ?>
            <tr id="cat-row-<?= $cat['id'] ?>">
              <td style="font-size:1.5rem;text-align:center;"><?= $cat['icon'] ?></td>
              <td style="color:var(--white);font-weight:500;"><?= e($cat['name_en']) ?></td>
              <td style="color:var(--muted);"><?= e($cat['name_fr']) ?></td>
              <td style="font-size:0.75rem;color:var(--muted-2);font-family:monospace;"><?= e($cat['slug']) ?></td>
              <td style="text-align:center;color:var(--white);"><?= $cat['listing_count'] ?></td>
              <td style="text-align:center;color:var(--muted);"><?= $cat['sort_order'] ?></td>
              <td>
                <button onclick="editCat(<?= $cat['id'] ?>, '<?= e(addslashes($cat['name_en'])) ?>', '<?= e(addslashes($cat['name_fr'])) ?>', '<?= e($cat['icon']) ?>', <?= $cat['sort_order'] ?>)"
                        class="btn btn-primary btn-sm">✏️ Edit</button>
                <?php if ($cat['listing_count'] == 0): ?>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Delete <?= e($cat['name_en']) ?>?')">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);margin-left:0.25rem;">✗</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div></section>

<!-- EDIT MODAL -->
<div id="edit-cat-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:500;align-items:center;justify-content:center;">
  <div style="background:#122B1C;border:1px solid var(--border);border-radius:16px;padding:2rem;width:100%;max-width:480px;margin:1rem;">
    <h3 style="font-family:'Fraunces',serif;font-weight:700;margin-bottom:1.5rem;">✏️ Edit Category</h3>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="cat_id" id="edit-cat-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group"><label>English Name *</label><input type="text" name="name_en" id="edit-name-en" required></div>
        <div class="form-group"><label>French Name *</label><input type="text" name="name_fr" id="edit-name-fr" required></div>
        <div class="form-group"><label>Icon</label><input type="text" name="icon" id="edit-icon" maxlength="5" style="font-size:1.2rem;text-align:center;"></div>
        <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" id="edit-sort" min="0"></div>
      </div>
      <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
        <button type="submit" class="btn btn-primary" style="flex:1;">Save Changes</button>
        <button type="button" onclick="document.getElementById('edit-cat-modal').style.display='none'" class="btn btn-outline" style="flex:1;">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function editCat(id, nameEn, nameFr, icon, order) {
  document.getElementById('edit-cat-id').value = id;
  document.getElementById('edit-name-en').value = nameEn;
  document.getElementById('edit-name-fr').value = nameFr;
  document.getElementById('edit-icon').value = icon;
  document.getElementById('edit-sort').value = order;
  document.getElementById('edit-cat-modal').style.display = 'flex';
}
document.getElementById('edit-cat-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
