<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

/* ── WRITE ACTIONS ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'save') {
        $slug         = trim($_POST['slug'] ?? '');
        $name_en      = trim($_POST['name_en'] ?? '');
        $name_fr      = trim($_POST['name_fr'] ?? '');
        $desc_en      = trim($_POST['description_en'] ?? '');
        $desc_fr      = trim($_POST['description_fr'] ?? '');
        $promo_type   = $_POST['promo_type'] ?? 'featured_listing';
        $price_xaf    = max(0, (int)($_POST['price_xaf'] ?? 0));
        $duration     = max(1, (int)($_POST['duration_days'] ?? 7));
        $max_conc     = trim($_POST['max_concurrent'] ?? '') !== '' ? max(1, (int)$_POST['max_concurrent']) : null;
        $eligible     = $_POST['eligible_content'] ?? [];
        $eligible_str = implode(',', array_filter($eligible, fn($v) => in_array($v, ['listing','offer','event'])));
        $min_plan     = trim($_POST['min_plan_slug'] ?? '') ?: null;
        $sort         = (int)($_POST['sort_order'] ?? 0);
        $active       = isset($_POST['active']) ? 1 : 0;

        if (!$slug || !$name_en || !$name_fr || !$eligible_str) {
            flash('error', 'Slug, names and at least one eligible content type are required.');
        } else {
            if ($id) {
                $st = db()->prepare("UPDATE promotion_products SET
                    slug=?, name_en=?, name_fr=?, description_en=?, description_fr=?,
                    promo_type=?, price_xaf=?, duration_days=?, max_concurrent=?,
                    eligible_content=?, min_plan_slug=?, sort_order=?, active=?
                    WHERE id=?");
                $st->execute([$slug, $name_en, $name_fr, $desc_en, $desc_fr,
                    $promo_type, $price_xaf, $duration, $max_conc,
                    $eligible_str, $min_plan, $sort, $active, $id]);
                flash('success', 'Product updated.');
            } else {
                $st = db()->prepare("INSERT INTO promotion_products
                    (slug, name_en, name_fr, description_en, description_fr,
                     promo_type, price_xaf, duration_days, max_concurrent,
                     eligible_content, min_plan_slug, sort_order, active)
                    VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?)");
                $st->execute([$slug, $name_en, $name_fr, $desc_en, $desc_fr,
                    $promo_type, $price_xaf, $duration, $max_conc,
                    $eligible_str, $min_plan, $sort, $active]);
                flash('success', 'Product created.');
            }
        }
        redirect(SITE_URL . '/admin/promotion-products.php');
    }

    if ($action === 'toggle' && $id) {
        db()->prepare("UPDATE promotion_products SET active = 1-active WHERE id=?")->execute([$id]);
        flash('success', 'Product toggled.');
        redirect(SITE_URL . '/admin/promotion-products.php');
    }

    if ($action === 'delete' && $id) {
        $inUse = db()->prepare("SELECT COUNT(*) FROM promotion_orders WHERE product_id=? AND promo_status IN ('active','pending_approval','scheduled')");
        $inUse->execute([$id]);
        if ($inUse->fetchColumn() > 0) {
            flash('error', 'Cannot delete — active or pending orders reference this product.');
        } else {
            db()->prepare("DELETE FROM promotion_products WHERE id=?")->execute([$id]);
            flash('success', 'Product deleted.');
        }
        redirect(SITE_URL . '/admin/promotion-products.php');
    }
}

/* ── READ ──────────────────────────────────────────────────── */
$products = db()->query("SELECT pp.*,
    (SELECT COUNT(*) FROM promotion_orders po WHERE po.product_id=pp.id AND po.promo_status='active') AS active_orders
    FROM promotion_products pp ORDER BY pp.sort_order, pp.id")->fetchAll();

$editing = null;
if (isset($_GET['edit']) && (int)$_GET['edit']) {
    $st = db()->prepare("SELECT * FROM promotion_products WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $editing = $st->fetch();
}

$planSlugs = db()->query("SELECT slug, name_en FROM subscription_plans WHERE active=1 ORDER BY sort_order")->fetchAll();

$promoTypes = [
    'featured_listing'   => 'Featured Listing',
    'featured_offer'     => 'Featured Offer',
    'promoted_event'     => 'Promoted Event',
    'category_placement' => 'Category Placement',
    'location_placement' => 'Location Placement',
    'homepage'           => 'Homepage Placement',
];

$pageTitle = 'Promotion Products — Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.prod-card{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.prod-header{padding:1.1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
.prod-body{padding:1.1rem 1.5rem;}
.prod-name{font-weight:700;font-family:'Fraunces',serif;}
.badge-pill{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
.badge-active{background:#dcfce7;color:#15803d;}
.badge-inactive{background:#f3f4f6;color:#6b7280;}
.spec-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.4rem .75rem;margin:.6rem 0;}
.spec-item{font-size:.82rem;color:var(--muted);}
.spec-item strong{color:var(--fg);}
.form-section{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;}
.form-section h3{margin:0 0 1.25rem;font-family:'Fraunces',serif;font-size:1.05rem;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;}
.form-row-3{grid-template-columns:1fr 1fr 1fr;}
@media(max-width:600px){.form-row,.form-row-3{grid-template-columns:1fr;}}
.check-row{display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;margin-bottom:1rem;}
.check-item{display:flex;align-items:center;gap:.4rem;font-size:.88rem;}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin">Admin</a> › Promotion Products</nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.4rem);">
      📣 Promotion Products
    </h1>
    <p style="color:var(--muted);">Configure paid promotion slots businesses can purchase.</p>
  </div>
</div>

<section class="page-section">
<div class="container">

<?php if ($flash = flash()): ?>
  <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<!-- PRODUCT LIST -->
<?php if ($products): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:2rem;">
  <?php foreach ($products as $p):
    $eligible_arr = array_filter(explode(',', $p['eligible_content']));
  ?>
  <div class="prod-card">
    <div class="prod-header">
      <div style="flex:1;min-width:0;">
        <div class="prod-name"><?= e($p['name_en']) ?></div>
        <div style="color:var(--muted);font-size:.83rem;"><?= e($p['name_fr']) ?></div>
        <div style="margin-top:.3rem;display:flex;gap:.4rem;flex-wrap:wrap;">
          <span class="badge-pill <?= $p['active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $p['active'] ? 'Active' : 'Inactive' ?></span>
          <span class="badge-pill" style="background:#ede9fe;color:#6d28d9;"><?= e($promoTypes[$p['promo_type']] ?? $p['promo_type']) ?></span>
        </div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:1.1rem;font-weight:700;color:var(--accent);"><?= number_format($p['price_xaf']) ?> XAF</div>
        <div style="font-size:.8rem;color:var(--muted);"><?= $p['duration_days'] ?> days</div>
      </div>
    </div>
    <div class="prod-body">
      <div class="spec-grid">
        <div class="spec-item">Slug: <strong><?= e($p['slug']) ?></strong></div>
        <div class="spec-item">Max concurrent: <strong><?= $p['max_concurrent'] ?? '∞' ?></strong></div>
        <div class="spec-item">Min plan: <strong><?= e($p['min_plan_slug'] ?? 'any') ?></strong></div>
        <div class="spec-item">Active orders: <strong><?= $p['active_orders'] ?></strong></div>
        <div class="spec-item">Sort: <strong><?= $p['sort_order'] ?></strong></div>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin:.6rem 0;">
        <?php foreach ($eligible_arr as $ec): ?>
          <span style="font-size:.76rem;background:var(--bg);border:1px solid var(--border);border-radius:5px;padding:1px 7px;"><?= e($ec) ?></span>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.9rem;">
        <a href="?edit=<?= $p['id'] ?>" class="btn btn-secondary" style="font-size:.82rem;padding:.35rem .8rem;">Edit</a>
        <form method="post" style="display:inline;">
          <?= csrf() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= $p['id'] ?>">
          <button class="btn btn-secondary" style="font-size:.82rem;padding:.35rem .8rem;"><?= $p['active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
        <?php if ($p['active_orders'] == 0): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this product?');">
          <?= csrf() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $p['id'] ?>">
          <button class="btn" style="font-size:.82rem;padding:.35rem .8rem;background:#fee2e2;color:#b91c1c;border:none;">Delete</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:12px;margin-bottom:2rem;">
  <div style="font-size:2.5rem;margin-bottom:.75rem;">📣</div>
  <p>No promotion products yet. Create one below.</p>
</div>
<?php endif; ?>

<!-- CREATE / EDIT FORM -->
<div class="form-section">
  <h3><?= $editing ? 'Edit: ' . e($editing['name_en']) : 'Create Promotion Product' ?></h3>
  <form method="post">
    <?= csrf() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>

    <div class="form-row">
      <div>
        <label>Slug <span style="color:red">*</span></label>
        <input type="text" name="slug" class="form-input" required value="<?= e($editing['slug'] ?? '') ?>" placeholder="e.g. featured-offer-7d">
      </div>
      <div>
        <label>Promotion Type <span style="color:red">*</span></label>
        <select name="promo_type" class="form-input" required>
          <?php foreach ($promoTypes as $val => $label): ?>
          <option value="<?= $val ?>" <?= ($editing['promo_type'] ?? 'featured_listing') === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-row">
      <div>
        <label>Name (English) <span style="color:red">*</span></label>
        <input type="text" name="name_en" class="form-input" required value="<?= e($editing['name_en'] ?? '') ?>">
      </div>
      <div>
        <label>Name (French) <span style="color:red">*</span></label>
        <input type="text" name="name_fr" class="form-input" required value="<?= e($editing['name_fr'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row">
      <div>
        <label>Description (EN)</label>
        <textarea name="description_en" class="form-input" rows="2"><?= e($editing['description_en'] ?? '') ?></textarea>
      </div>
      <div>
        <label>Description (FR)</label>
        <textarea name="description_fr" class="form-input" rows="2"><?= e($editing['description_fr'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="form-row form-row-3">
      <div>
        <label>Price (XAF) <span style="color:red">*</span></label>
        <input type="number" name="price_xaf" class="form-input" min="0" required value="<?= $editing['price_xaf'] ?? 5000 ?>">
      </div>
      <div>
        <label>Duration (days) <span style="color:red">*</span></label>
        <input type="number" name="duration_days" class="form-input" min="1" required value="<?= $editing['duration_days'] ?? 7 ?>">
      </div>
      <div>
        <label>Max Concurrent</label>
        <input type="number" name="max_concurrent" class="form-input" min="1" value="<?= $editing['max_concurrent'] ?? '' ?>" placeholder="unlimited">
        <small style="color:var(--muted);">Leave blank for unlimited</small>
      </div>
    </div>

    <div class="form-row">
      <div>
        <label>Min Subscription Plan</label>
        <select name="min_plan_slug" class="form-input">
          <option value="">Any plan</option>
          <?php foreach ($planSlugs as $pl): ?>
          <option value="<?= e($pl['slug']) ?>" <?= ($editing['min_plan_slug'] ?? '') === $pl['slug'] ? 'selected' : '' ?>>
            <?= e($pl['name_en']) ?> (<?= e($pl['slug']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
        <small style="color:var(--muted);">Require business to have this plan or higher</small>
      </div>
      <div>
        <label>Sort Order</label>
        <input type="number" name="sort_order" class="form-input" value="<?= $editing['sort_order'] ?? 0 ?>">
      </div>
    </div>

    <div style="margin-bottom:1rem;">
      <label style="display:block;margin-bottom:.5rem;">Eligible Content Types <span style="color:red">*</span></label>
      <?php
      $editingEligible = $editing ? array_filter(explode(',', $editing['eligible_content'])) : ['listing'];
      foreach (['listing','offer','event'] as $ct):
      ?>
      <label class="check-item" style="display:inline-flex;margin-right:1.5rem;">
        <input type="checkbox" name="eligible_content[]" value="<?= $ct ?>"
          <?= in_array($ct, $editingEligible) ? 'checked' : '' ?>>
        <?= ucfirst($ct) ?>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="check-row">
      <label class="check-item">
        <input type="checkbox" name="active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
        Active (purchasable by businesses)
      </label>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Update Product' : 'Create Product' ?></button>
      <?php if ($editing): ?><a href="?" class="btn btn-secondary">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

</div><!-- /container -->
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
