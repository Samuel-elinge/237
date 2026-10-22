<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

/* ── WRITE ACTIONS ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'save') {
        $name_en         = trim($_POST['name_en'] ?? '');
        $name_fr         = trim($_POST['name_fr'] ?? '');
        $desc_en         = trim($_POST['description_en'] ?? '');
        $desc_fr         = trim($_POST['description_fr'] ?? '');
        $addon_type      = $_POST['addon_type'] ?? 'offers';
        $quantity        = max(1, (int)($_POST['quantity'] ?? 1));
        $price_xaf       = max(0, (int)($_POST['price_xaf'] ?? 0));
        $expiry_policy   = $_POST['expiry_policy'] ?? 'subscription_end';
        $expiry_days     = ($expiry_policy === 'fixed_days') ? max(1, (int)($_POST['expiry_days'] ?? 30)) : null;
        $max_per_listing = ($_POST['max_per_listing'] !== '') ? max(1, (int)$_POST['max_per_listing']) : null;
        $sort_order      = (int)($_POST['sort_order'] ?? 0);
        $active          = isset($_POST['active']) ? 1 : 0;

        // Eligible plan slugs — comma-separated input → JSON array; empty = all plans
        $rawSlugs = trim($_POST['eligible_plan_slugs'] ?? '');
        $eligible_plan_slugs = null;
        if ($rawSlugs !== '') {
            $slugArr = array_values(array_filter(array_map('trim', explode(',', $rawSlugs))));
            if ($slugArr) $eligible_plan_slugs = json_encode($slugArr);
        }

        if (!$name_en || !$name_fr || !in_array($addon_type, ['offers','events','products'])) {
            flash('error', 'English name, French name and add-on type are required.');
        } else {
            if ($id) {
                db()->prepare("UPDATE plan_addons SET
                    name_en=?, name_fr=?, description_en=?, description_fr=?,
                    addon_type=?, quantity=?, price_xaf=?,
                    eligible_plan_slugs=?, expiry_policy=?, expiry_days=?,
                    max_per_listing=?, active=?, sort_order=?
                    WHERE id=?")
                ->execute([$name_en, $name_fr, $desc_en ?: null, $desc_fr ?: null,
                    $addon_type, $quantity, $price_xaf,
                    $eligible_plan_slugs, $expiry_policy, $expiry_days,
                    $max_per_listing, $active, $sort_order, $id]);
                flash('success', 'Add-on updated.');
            } else {
                db()->prepare("INSERT INTO plan_addons
                    (name_en, name_fr, description_en, description_fr,
                     addon_type, quantity, price_xaf,
                     eligible_plan_slugs, expiry_policy, expiry_days,
                     max_per_listing, active, sort_order)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$name_en, $name_fr, $desc_en ?: null, $desc_fr ?: null,
                    $addon_type, $quantity, $price_xaf,
                    $eligible_plan_slugs, $expiry_policy, $expiry_days,
                    $max_per_listing, $active, $sort_order]);
                flash('success', 'Add-on created.');
            }
            redirect(SITE_URL . '/admin/addons.php');
        }
    }

    if ($action === 'toggle' && $id) {
        db()->prepare("UPDATE plan_addons SET active = 1-active WHERE id=?")->execute([$id]);
        flash('success', 'Add-on status toggled.');
        redirect(SITE_URL . '/admin/addons.php');
    }

    if ($action === 'delete' && $id) {
        // Block if any active orders reference this addon
        $count = db()->prepare("SELECT COUNT(*) FROM addon_orders WHERE addon_id=? AND payment_status='paid'");
        $count->execute([$id]);
        if ((int)$count->fetchColumn() > 0) {
            flash('error', 'Cannot delete — paid orders exist for this add-on.');
        } else {
            db()->prepare("DELETE FROM plan_addons WHERE id=?")->execute([$id]);
            flash('success', 'Add-on deleted.');
        }
        redirect(SITE_URL . '/admin/addons.php');
    }
}

/* ── READ ──────────────────────────────────────────────────── */
$addons = db()->query("
    SELECT pa.*,
        (SELECT COUNT(*) FROM addon_orders ao WHERE ao.addon_id=pa.id AND ao.payment_status='paid') AS paid_orders
    FROM plan_addons pa
    ORDER BY pa.addon_type, pa.sort_order, pa.id
")->fetchAll();

// Group by type for display
$byType = ['offers' => [], 'events' => [], 'products' => []];
foreach ($addons as $a) $byType[$a['addon_type']][] = $a;

$editing = null;
if (isset($_GET['edit']) && (int)$_GET['edit']) {
    $st = db()->prepare("SELECT * FROM plan_addons WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $editing = $st->fetch();
}

$pageTitle = 'Add-ons — Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.addon-card{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:.75rem;}
.addon-header{padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;}
.addon-body{padding:1rem 1.25rem;}
.addon-name{font-weight:700;font-size:.98rem;font-family:'Fraunces',serif;}
.addon-price{font-size:1.1rem;font-weight:700;color:var(--accent);}
.badge-pill{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
.badge-active{background:#dcfce7;color:#15803d;}
.badge-inactive{background:#f3f4f6;color:#6b7280;}
.badge-offers{background:#eff6ff;color:#1d4ed8;}
.badge-events{background:#fef3c7;color:#92400e;}
.badge-products{background:#f3e8ff;color:#6b21a8;}
.spec-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.4rem .75rem;margin:.5rem 0;}
.spec-item{font-size:.8rem;color:var(--muted);}
.spec-item strong{color:var(--fg);}
.form-section{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;}
.form-section h3{margin:0 0 1.25rem;font-family:'Fraunces',serif;font-size:1.05rem;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;}
.form-row-3{grid-template-columns:1fr 1fr 1fr;}
@media(max-width:600px){.form-row,.form-row-3{grid-template-columns:1fr;}}
.check-row{display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;}
.check-item{display:flex;align-items:center;gap:.4rem;font-size:.88rem;}
.type-section h2{font-family:'Fraunces',serif;font-size:1.1rem;margin:1.5rem 0 .75rem;padding-bottom:.4rem;border-bottom:2px solid var(--border);}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin">Admin</a> › <a href="<?= SITE_URL ?>/admin/addons.php">Add-ons</a></nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.4rem);">
      🛒 Add-on Catalogue
    </h1>
    <p style="color:var(--muted);">Extra capacity packs businesses can purchase on top of their plan.</p>
  </div>
</div>

<section class="page-section">
<div class="container">

<?php if ($flash = flash()): ?>
  <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<!-- ADD-ON LIST BY TYPE -->
<?php
$typeLabels = ['offers' => '🏷️ Offer Add-ons', 'events' => '📅 Event Add-ons', 'products' => '📦 Product Add-ons'];
foreach ($typeLabels as $type => $label):
    $items = $byType[$type];
?>
<div class="type-section">
  <h2><?= $label ?> <span style="color:var(--muted);font-weight:400;font-size:.85rem;">(<?= count($items) ?>)</span></h2>

  <?php if ($items): foreach ($items as $a): ?>
  <div class="addon-card">
    <div class="addon-header">
      <div>
        <span class="addon-name"><?= e($a['name_en']) ?></span>
        <span style="color:var(--muted);font-size:.85rem;margin-left:.5rem;">/ <?= e($a['name_fr']) ?></span>
      </div>
      <span class="badge-pill badge-<?= $type ?>"><?= $type ?></span>
      <span class="badge-pill <?= $a['active'] ? 'badge-active' : 'badge-inactive' ?>">
        <?= $a['active'] ? 'Active' : 'Inactive' ?>
      </span>
      <?php if ($a['paid_orders'] > 0): ?>
        <span style="font-size:.8rem;color:var(--muted);"><?= $a['paid_orders'] ?> paid order(s)</span>
      <?php endif; ?>
      <div style="margin-left:auto;display:flex;gap:.5rem;">
        <a href="?edit=<?= $a['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
        <form method="post" style="display:inline;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <button class="btn btn-sm btn-secondary"><?= $a['active'] ? 'Disable' : 'Enable' ?></button>
        </form>
        <?php if ($a['paid_orders'] == 0): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this add-on?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;">Delete</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="addon-body">
      <div class="spec-grid">
        <div class="spec-item"><strong>Price</strong><br><?= number_format($a['price_xaf']) ?> FCFA</div>
        <div class="spec-item"><strong>Capacity</strong><br>+<?= $a['quantity'] ?> <?= $type ?></div>
        <div class="spec-item"><strong>Expiry</strong><br><?= match($a['expiry_policy']) {
            'subscription_end' => 'With subscription',
            'fixed_days'       => $a['expiry_days'] . ' days',
            'never'            => 'Never expires',
        } ?></div>
        <div class="spec-item"><strong>Max/listing</strong><br><?= $a['max_per_listing'] ?? 'Unlimited' ?></div>
        <div class="spec-item"><strong>Eligible plans</strong><br>
          <?php if ($a['eligible_plan_slugs']): ?>
            <?= e(implode(', ', json_decode($a['eligible_plan_slugs'], true) ?: [])) ?>
          <?php else: ?>All plans<?php endif; ?>
        </div>
        <div class="spec-item"><strong>Sort order</strong><br><?= $a['sort_order'] ?></div>
      </div>
      <?php if ($a['description_en']): ?>
        <p style="font-size:.82rem;color:var(--muted);margin:.25rem 0 0;"><?= e($a['description_en']) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; else: ?>
    <p style="color:var(--muted);font-size:.9rem;">No <?= $type ?> add-ons yet.</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- CREATE / EDIT FORM -->
<div class="form-section" id="form" style="margin-top:2rem;">
  <h3><?= $editing ? '✏️ Edit Add-on' : '➕ Create Add-on' ?></h3>
  <form method="post" action="<?= SITE_URL ?>/admin/addons.php#form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editing): ?>
      <input type="hidden" name="id" value="<?= $editing['id'] ?>">
    <?php endif; ?>

    <div class="form-row">
      <div>
        <label class="form-label">Name (English) *</label>
        <input type="text" name="name_en" class="form-input" required
               value="<?= e($editing['name_en'] ?? '') ?>">
      </div>
      <div>
        <label class="form-label">Name (French) *</label>
        <input type="text" name="name_fr" class="form-input" required
               value="<?= e($editing['name_fr'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row">
      <div>
        <label class="form-label">Description (EN)</label>
        <input type="text" name="description_en" class="form-input"
               value="<?= e($editing['description_en'] ?? '') ?>">
      </div>
      <div>
        <label class="form-label">Description (FR)</label>
        <input type="text" name="description_fr" class="form-input"
               value="<?= e($editing['description_fr'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row form-row-3">
      <div>
        <label class="form-label">Add-on type *</label>
        <select name="addon_type" class="form-input" id="addonTypeSelect">
          <?php foreach (['offers','events','products'] as $t): ?>
            <option value="<?= $t ?>" <?= ($editing['addon_type'] ?? 'offers') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Capacity granted (qty)</label>
        <input type="number" name="quantity" class="form-input" min="1"
               value="<?= $editing['quantity'] ?? 1 ?>">
      </div>
      <div>
        <label class="form-label">Price (FCFA)</label>
        <input type="number" name="price_xaf" class="form-input" min="0"
               value="<?= $editing['price_xaf'] ?? 0 ?>">
      </div>
    </div>

    <div class="form-row form-row-3">
      <div>
        <label class="form-label">Expiry policy</label>
        <select name="expiry_policy" class="form-input" id="expiryPolicySelect"
                onchange="document.getElementById('expiryDaysRow').style.display=this.value==='fixed_days'?'block':'none'">
          <option value="subscription_end" <?= ($editing['expiry_policy'] ?? '') === 'subscription_end' ? 'selected' : '' ?>>Expires with subscription</option>
          <option value="fixed_days"       <?= ($editing['expiry_policy'] ?? '') === 'fixed_days'       ? 'selected' : '' ?>>Fixed number of days</option>
          <option value="never"            <?= ($editing['expiry_policy'] ?? '') === 'never'            ? 'selected' : '' ?>>Never expires</option>
        </select>
      </div>
      <div id="expiryDaysRow" style="display:<?= ($editing['expiry_policy'] ?? '') === 'fixed_days' ? 'block' : 'none' ?>;">
        <label class="form-label">Days (if fixed)</label>
        <input type="number" name="expiry_days" class="form-input" min="1"
               value="<?= $editing['expiry_days'] ?? 30 ?>">
      </div>
      <div>
        <label class="form-label">Max purchases/listing</label>
        <input type="number" name="max_per_listing" class="form-input" min="1" placeholder="blank = unlimited"
               value="<?= $editing['max_per_listing'] ?? '' ?>">
      </div>
    </div>

    <div class="form-row">
      <div>
        <label class="form-label">Eligible plan slugs <small style="color:var(--muted);">(comma-separated; blank = all)</small></label>
        <input type="text" name="eligible_plan_slugs" class="form-input"
               placeholder="e.g. growth,premium"
               value="<?= $editing ? e(implode(', ', json_decode($editing['eligible_plan_slugs'] ?? '[]', true) ?: [])) : '' ?>">
      </div>
      <div>
        <label class="form-label">Sort order</label>
        <input type="number" name="sort_order" class="form-input"
               value="<?= $editing['sort_order'] ?? 0 ?>">
      </div>
    </div>

    <div class="check-row" style="margin-bottom:1.25rem;">
      <label class="check-item">
        <input type="checkbox" name="active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
        Active (visible to businesses)
      </label>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Update Add-on' : 'Create Add-on' ?></button>
      <?php if ($editing): ?>
        <a href="<?= SITE_URL ?>/admin/addons.php" class="btn btn-secondary">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
