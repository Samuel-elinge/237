<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$cu = currentUser();
$listingId = (int)($_GET['listing_id'] ?? 0);

// Verify ownership — also accept admin
$st = db()->prepare("SELECT * FROM listings WHERE id=? AND (user_id=? OR ?='admin') AND featured=1");
$st->execute([$listingId, $cu['id'], $cu['role']]);
$listing = $st->fetch();
if (!$listing) {
    flash('error', t('Listing not found, not yours, or not a featured listing.', 'Annonce introuvable, non autorisée, ou non vedette.'));
    redirect(SITE_URL . '/dashboard');
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $prodId      = (int)($_POST['product_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $catTag      = trim($_POST['category_tag'] ?? '');
        $price       = (int)str_replace([',',' '], '', $_POST['price'] ?? '0');
        $salePrice   = trim($_POST['sale_price'] ?? '') !== '' ? (int)str_replace([',',' '], '', $_POST['sale_price']) : null;
        $inStock     = isset($_POST['in_stock']) ? 1 : 0;
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);

        // Validate
        $errors = [];
        if (!$name)  $errors[] = t('Product name is required.','Le nom du produit est requis.');
        if ($price <= 0) $errors[] = t('Price must be greater than 0.','Le prix doit être supérieur à 0.');
        if ($salePrice !== null && $salePrice >= $price) $errors[] = t('Sale price must be less than the regular price.','Le prix de vente doit être inférieur au prix normal.');

        // Handle image upload
        $imagePath = $prodId ? (db()->prepare("SELECT image FROM listing_products WHERE id=? AND listing_id=?")->execute([$prodId, $listingId]) ? db()->prepare("SELECT image FROM listing_products WHERE id=?")->execute([$prodId]) : null) : null;

        // Re-fetch existing image properly
        $existingImage = null;
        if ($prodId) {
            $imgSt = db()->prepare("SELECT image FROM listing_products WHERE id=? AND listing_id=?");
            $imgSt->execute([$prodId, $listingId]);
            $existingImage = $imgSt->fetchColumn() ?: null;
        }
        $imagePath = $existingImage;

        if (!empty($_FILES['image']['name'])) {
            $file     = $_FILES['image'];
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed  = ['jpg','jpeg','png','webp'];
            if (!in_array($ext, $allowed)) {
                $errors[] = t('Image must be JPG, PNG, or WebP.','L\'image doit être JPG, PNG ou WebP.');
            } elseif ($file['size'] > 3 * 1024 * 1024) {
                $errors[] = t('Image must be under 3MB.','L\'image doit faire moins de 3 Mo.');
            } else {
                $newPath  = 'products/' . uniqid('prod_') . '.' . $ext;
                @mkdir(UPLOAD_DIR . 'products', 0755, true);
                if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $newPath)) {
                    $imagePath = $newPath;
                }
            }
        }

        if (!$errors) {
            if ($prodId) {
                db()->prepare("UPDATE listing_products SET name=?,description=?,category_tag=?,price=?,sale_price=?,image=?,in_stock=?,sort_order=? WHERE id=? AND listing_id=?")
                     ->execute([$name, $description ?: null, $catTag ?: null, $price, $salePrice, $imagePath, $inStock, $sortOrder, $prodId, $listingId]);
                flash('success', t('Product updated.','Produit mis à jour.'));
            } else {
                db()->prepare("INSERT INTO listing_products (listing_id,name,description,category_tag,price,sale_price,image,in_stock,sort_order) VALUES (?,?,?,?,?,?,?,?,?)")
                     ->execute([$listingId, $name, $description ?: null, $catTag ?: null, $price, $salePrice, $imagePath, $inStock, $sortOrder]);
                flash('success', t('Product added.','Produit ajouté.'));
            }
            redirect(SITE_URL . '/manage-products?listing_id=' . $listingId);
        }

    } elseif ($action === 'delete') {
        $prodId = (int)($_POST['product_id'] ?? 0);
        // Delete image file too
        $imgSt = db()->prepare("SELECT image FROM listing_products WHERE id=? AND listing_id=?");
        $imgSt->execute([$prodId, $listingId]);
        $img = $imgSt->fetchColumn();
        if ($img && file_exists(UPLOAD_DIR . $img)) @unlink(UPLOAD_DIR . $img);
        db()->prepare("DELETE FROM listing_products WHERE id=? AND listing_id=?")->execute([$prodId, $listingId]);
        flash('success', t('Product deleted.','Produit supprimé.'));
        redirect(SITE_URL . '/manage-products?listing_id=' . $listingId);

    } elseif ($action === 'toggle_stock') {
        $prodId = (int)($_POST['product_id'] ?? 0);
        db()->prepare("UPDATE listing_products SET in_stock = !in_stock WHERE id=? AND listing_id=?")->execute([$prodId, $listingId]);
        redirect(SITE_URL . '/manage-products?listing_id=' . $listingId);
    }
}

// Load edit product if ?edit=
$editProduct = null;
if (isset($_GET['edit'])) {
    $epSt = db()->prepare("SELECT * FROM listing_products WHERE id=? AND listing_id=?");
    $epSt->execute([(int)$_GET['edit'], $listingId]);
    $editProduct = $epSt->fetch();
}

// Load all products for this listing
$products = db()->prepare("SELECT * FROM listing_products WHERE listing_id=? ORDER BY sort_order, created_at DESC");
$products->execute([$listingId]);
$products = $products->fetchAll();

$pageTitle = t('Manage Products/Services — ','Gérer les Produits/Services — ') . e($listing['title']);
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header"><div class="container">
  <nav class="breadcrumb">
    <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard','Tableau de bord') ?></a> ›
    <a href="<?= SITE_URL ?>/listing/<?= e($listing['slug']) ?>"><?= e($listing['title']) ?></a> ›
    <span><?= t('Manage Products/Services','Gérer les Produits/Services') ?></span>
  </nav>
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">
    🛍️ <?= t('Products & Services','Produits & Services') ?>
  </h1>
  <p style="color:var(--muted);margin-top:0.35rem;"><?= e($listing['title']) ?></p>
</div></div>

<section class="page-section"><div class="container">

  <!-- Add / Edit Form -->
  <div class="listing-widget" style="border-color:rgba(0,168,120,0.25);background:rgba(0,168,120,0.02);margin-bottom:2rem;">
    <h4 style="margin-bottom:1.25rem;">
      <?= $editProduct ? t('Edit Product','Modifier le Produit') : t('+ Add Product / Service','+ Ajouter Produit / Service') ?>
    </h4>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="product_id" value="<?= $editProduct ? $editProduct['id'] : 0 ?>">

      <div class="form-row">
        <div class="form-group">
          <label><?= t('Name *','Nom *') ?></label>
          <input type="text" name="name" required maxlength="200"
                 value="<?= e($editProduct['name'] ?? $_POST['name'] ?? '') ?>"
                 placeholder="<?= t('e.g. Web Design Package','ex. Pack Création de Site') ?>">
        </div>
        <div class="form-group">
          <label><?= t('Category Tag','Tag Catégorie') ?> <span style="font-size:0.72rem;color:var(--muted-2);">(<?= t('e.g. Food, Services, Electronics','ex. Nourriture, Services, Électronique') ?>)</span></label>
          <input type="text" name="category_tag" maxlength="100"
                 value="<?= e($editProduct['category_tag'] ?? $_POST['category_tag'] ?? '') ?>"
                 placeholder="<?= t('Tag for filtering','Tag pour filtre') ?>">
        </div>
      </div>

      <div class="form-group">
        <label><?= t('Description','Description') ?></label>
        <textarea name="description" rows="3"
                  placeholder="<?= t('Brief description of this product or service...','Brève description de ce produit ou service...') ?>"><?= e($editProduct['description'] ?? $_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label><?= t('Regular Price (XAF) *','Prix Normal (XAF) *') ?></label>
          <input type="number" name="price" required min="1" step="100"
                 value="<?= e($editProduct['price'] ?? $_POST['price'] ?? '') ?>"
                 placeholder="e.g. 15000">
        </div>
        <div class="form-group">
          <label>
            <?= t('Sale Price (XAF)','Prix de Vente (XAF)') ?>
            <span style="font-size:0.72rem;color:var(--muted-2);">(<?= t('must be less than regular price','doit être inférieur au prix normal') ?>)</span>
          </label>
          <input type="number" name="sale_price" min="1" step="100"
                 value="<?= e($editProduct['sale_price'] ?? $_POST['sale_price'] ?? '') ?>"
                 placeholder="<?= t('Leave blank if no sale','Laisser vide si pas de promo') ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label><?= t('Product Image','Image du Produit') ?></label>
          <?php if ($editProduct && $editProduct['image']): ?>
            <div style="margin-bottom:0.5rem;">
              <img src="<?= e(UPLOAD_URL . $editProduct['image']) ?>" alt=""
                   style="width:100px;height:70px;object-fit:cover;border-radius:6px;border:1px solid var(--border);">
            </div>
          <?php endif; ?>
          <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                 style="background:var(--card);border:1px solid var(--border);border-radius:6px;padding:0.5rem;color:var(--muted);width:100%;">
          <small style="color:var(--muted-2);font-size:0.72rem;"><?= t('JPG, PNG or WebP — max 3MB','JPG, PNG ou WebP — max 3 Mo') ?></small>
        </div>
        <div class="form-group">
          <label><?= t('Sort Order','Ordre d\'affichage') ?></label>
          <input type="number" name="sort_order" min="0" value="<?= e($editProduct['sort_order'] ?? count($products)) ?>">
          <div style="margin-top:0.75rem;">
            <label style="display:flex;align-items:center;gap:0.6rem;font-size:0.85rem;color:var(--muted);cursor:pointer;">
              <input type="checkbox" name="in_stock" <?= !isset($editProduct) || $editProduct['in_stock'] ? 'checked' : '' ?> style="accent-color:var(--green);">
              <?= t('In stock / available','En stock / disponible') ?>
            </label>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary"><?= $editProduct ? t('Update Product','Mettre à jour') : t('Add Product/Service','Ajouter Produit/Service') ?></button>
        <?php if ($editProduct): ?>
          <a href="<?= SITE_URL ?>/manage-products?listing_id=<?= $listingId ?>" class="btn btn-outline"><?= t('Cancel','Annuler') ?></a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Products list -->
  <h4 style="margin-bottom:1rem;"><?= t('Your Products & Services','Vos Produits & Services') ?> (<?= count($products) ?>)</h4>

  <?php if ($products): ?>
    <div style="display:flex;flex-direction:column;gap:0.75rem;">
      <?php foreach ($products as $prod): ?>
        <div class="listing-widget" style="display:grid;grid-template-columns:80px 1fr auto;gap:1rem;align-items:center;<?= !$prod['in_stock'] ? 'opacity:0.6;' : '' ?>">
          <!-- Image -->
          <div style="width:80px;height:60px;border-radius:8px;overflow:hidden;background:rgba(255,255,255,0.04);flex-shrink:0;">
            <?php if ($prod['image']): ?>
              <img src="<?= e(UPLOAD_URL . $prod['image']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
              <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;">🛍️</div>
            <?php endif; ?>
          </div>

          <!-- Details -->
          <div>
            <div style="font-weight:500;color:var(--white);font-size:0.9rem;"><?= e($prod['name']) ?></div>
            <?php if ($prod['category_tag']): ?>
              <span style="font-size:0.7rem;background:rgba(255,255,255,0.06);padding:0.15rem 0.5rem;border-radius:10px;color:var(--muted-2);"><?= e($prod['category_tag']) ?></span>
            <?php endif; ?>
            <div style="margin-top:0.25rem;display:flex;align-items:baseline;gap:0.5rem;">
              <?php if ($prod['sale_price']): ?>
                <span style="color:#00A878;font-weight:600;font-size:0.9rem;"><?= number_format($prod['sale_price']) ?> XAF</span>
                <span style="color:var(--muted-2);text-decoration:line-through;font-size:0.78rem;"><?= number_format($prod['price']) ?></span>
                <span style="font-size:0.7rem;color:#e63946;">-<?= round((1 - $prod['sale_price']/$prod['price'])*100) ?>%</span>
              <?php else: ?>
                <span style="color:var(--yellow);font-weight:600;font-size:0.9rem;"><?= number_format($prod['price']) ?> XAF</span>
              <?php endif; ?>
              <span style="font-size:0.72rem;color:<?= $prod['in_stock'] ? 'var(--green)' : '#ff6b6b' ?>;">
                <?= $prod['in_stock'] ? t('In stock','En stock') : t('Out of stock','Rupture') ?>
              </span>
            </div>
          </div>

          <!-- Actions -->
          <div style="display:flex;gap:0.4rem;flex-direction:column;">
            <a href="?listing_id=<?= $listingId ?>&edit=<?= $prod['id'] ?>" class="btn btn-outline btn-sm"><?= t('Edit','Modifier') ?></a>
            <form method="POST" style="margin:0;">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="toggle_stock">
              <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:rgba(255,255,255,0.05);color:var(--muted);border:1px solid var(--border);width:100%;">
                <?= $prod['in_stock'] ? t('Mark Out','Rupture') : t('Mark In','En stock') ?>
              </button>
            </form>
            <form method="POST" style="margin:0;" onsubmit="return confirm('<?= t('Delete this product?','Supprimer ce produit ?') ?>')">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);width:100%;">✗ <?= t('Delete','Supprimer') ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
      <div style="font-size:2.5rem;margin-bottom:0.75rem;">🛍️</div>
      <p style="color:var(--muted);"><?= t('No products or services yet. Add your first one above!','Aucun produit ou service encore. Ajoutez votre premier ci-dessus !') ?></p>
    </div>
  <?php endif; ?>

  <div style="margin-top:1.5rem;display:flex;gap:0.75rem;">
    <a href="<?= SITE_URL ?>/listing/<?= e($listing['slug']) ?>#products" class="btn btn-outline btn-sm">
      <?= t('View on listing page →','Voir sur la page annonce →') ?>
    </a>
    <a href="<?= SITE_URL ?>/dashboard" class="btn btn-outline btn-sm">← <?= t('Dashboard','Tableau de bord') ?></a>
  </div>

</div></section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
