<?php
/**
 * admin/marketplace-catalogue.php — Manage the Growth Partner Services Marketplace catalogue
 * Full CRUD: create/edit categories, products, prices + toggle flags + settings.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/flash-helpers.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireAdmin();

$pdo = db();

/* ── Helpers ─────────────────────────────────────────────────── */
function mpFmt(int $xaf): string { return number_format($xaf) . ' FCFA'; }
function slugify(string $s): string {
    return preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($s)));
}

/* ── POST handlers ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
    $action = $_POST['action'] ?? '';

    /* ── CATEGORY: create ── */
    if ($action === 'create_category') {
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '') ?: $nameEn;
        $key    = slugify($nameEn);
        $icon   = trim($_POST['icon'] ?? '🏷️') ?: '🏷️';
        $sort   = (int)($_POST['sort_order'] ?? 0);
        if ($nameEn) {
            // ensure unique key
            $base = $key; $i = 1;
            while ($pdo->prepare("SELECT id FROM mp_service_categories WHERE `key`=?")->execute([$key]) && $pdo->query("SELECT id FROM mp_service_categories WHERE `key`='$key'")->fetchColumn()) {
                $key = $base . '_' . $i++;
            }
            $pdo->prepare("INSERT INTO mp_service_categories (`key`,name_en,name_fr,icon,sort_order,active,orderable) VALUES (?,?,?,?,?,1,0)")
                ->execute([$key, $nameEn, $nameFr, $icon, $sort]);
            flash('success', "Category '{$nameEn}' created.");
        } else {
            flash('error', 'Category name is required.');
        }

    /* ── CATEGORY: edit ── */
    } elseif ($action === 'edit_category') {
        $cid    = (int)$_POST['category_id'];
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '') ?: $nameEn;
        $icon   = trim($_POST['icon'] ?? '🏷️') ?: '🏷️';
        $sort   = (int)($_POST['sort_order'] ?? 0);
        if ($nameEn && $cid) {
            $pdo->prepare("UPDATE mp_service_categories SET name_en=?,name_fr=?,icon=?,sort_order=? WHERE id=?")
                ->execute([$nameEn, $nameFr, $icon, $sort, $cid]);
            flash('success', "Category updated.");
        }

    /* ── CATEGORY: delete ── */
    } elseif ($action === 'delete_category') {
        $cid = (int)$_POST['category_id'];
        $cnt = (int)$pdo->prepare("SELECT COUNT(*) FROM mp_service_products WHERE category_id=?")->execute([$cid]) ? $pdo->query("SELECT COUNT(*) FROM mp_service_products WHERE category_id=$cid")->fetchColumn() : 0;
        if ($cnt > 0) {
            flash('error', "Cannot delete: category has {$cnt} product(s). Delete or move products first.");
        } else {
            $pdo->prepare("DELETE FROM mp_service_categories WHERE id=?")->execute([$cid]);
            flash('success', "Category deleted.");
        }

    /* ── CATEGORY: toggle ── */
    } elseif ($action === 'toggle_category') {
        $cid   = (int)$_POST['category_id'];
        $field = $_POST['field'] === 'orderable' ? 'orderable' : 'active';
        $val   = (int)(bool)$_POST['value'];
        $pdo->prepare("UPDATE mp_service_categories SET {$field}=? WHERE id=?")->execute([$val, $cid]);
        flash('success', "Category updated.");

    /* ── PRODUCT: create ── */
    } elseif ($action === 'create_product') {
        $catId  = (int)$_POST['category_id'];
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '') ?: $nameEn;
        $descEn = trim($_POST['description_en'] ?? '');
        $descFr = trim($_POST['description_fr'] ?? '') ?: $descEn;
        $type   = $_POST['service_type'] ?? 'website_setup';
        $termsEn= trim($_POST['terms_en'] ?? '');
        $termsFr= trim($_POST['terms_fr'] ?? '') ?: $termsEn;
        $sort       = (int)($_POST['sort_order'] ?? 0);
        $commRate   = min(100, max(0, (float)($_POST['partner_commission_rate'] ?? 0)));
        $key        = slugify($nameEn);
        if ($nameEn && $catId) {
            $base = $key; $i = 1;
            while ($pdo->query("SELECT id FROM mp_service_products WHERE `key`='$key'")->fetchColumn()) {
                $key = $base . '_' . $i++;
            }
            $pdo->prepare("INSERT INTO mp_service_products (category_id,`key`,name_en,name_fr,description_en,description_fr,service_type,terms_en,terms_fr,sort_order,active,orderable,partner_commission_rate) VALUES (?,?,?,?,?,?,?,?,?,?,1,0,?)")
                ->execute([$catId, $key, $nameEn, $nameFr, $descEn, $descFr, $type, $termsEn, $termsFr, $sort, $commRate]);
            flash('success', "Product '{$nameEn}' created.");
        } else {
            flash('error', 'Product name and category are required.');
        }

    /* ── PRODUCT: edit ── */
    } elseif ($action === 'edit_product') {
        $pid    = (int)$_POST['product_id'];
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '') ?: $nameEn;
        $descEn = trim($_POST['description_en'] ?? '');
        $descFr = trim($_POST['description_fr'] ?? '') ?: $descEn;
        $type   = $_POST['service_type'] ?? 'website_setup';
        $termsEn= trim($_POST['terms_en'] ?? '');
        $termsFr= trim($_POST['terms_fr'] ?? '') ?: $termsEn;
        $sort       = (int)($_POST['sort_order'] ?? 0);
        $catId      = (int)$_POST['category_id'];
        $commRate   = min(100, max(0, (float)($_POST['partner_commission_rate'] ?? 0)));
        if ($nameEn && $pid) {
            $pdo->prepare("UPDATE mp_service_products SET category_id=?,name_en=?,name_fr=?,description_en=?,description_fr=?,service_type=?,terms_en=?,terms_fr=?,sort_order=?,partner_commission_rate=?,updated_at=NOW() WHERE id=?")
                ->execute([$catId, $nameEn, $nameFr, $descEn, $descFr, $type, $termsEn, $termsFr, $sort, $commRate, $pid]);
            flash('success', "Product updated.");
        }

    /* ── PRODUCT: delete ── */
    } elseif ($action === 'delete_product') {
        $pid = (int)$_POST['product_id'];
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders WHERE listing_id IN (SELECT id FROM listings) AND id IN (SELECT order_id FROM marketplace_order_items WHERE product_id=$pid)")->fetchColumn();
        if ($cnt > 0) {
            flash('error', "Cannot delete: product has {$cnt} order(s).");
        } else {
            $pdo->prepare("DELETE FROM mp_service_prices WHERE product_id=?")->execute([$pid]);
            $pdo->prepare("DELETE FROM mp_service_products WHERE id=?")->execute([$pid]);
            flash('success', "Product deleted.");
        }

    /* ── PRODUCT: toggle ── */
    } elseif ($action === 'toggle_product') {
        $pid   = (int)$_POST['product_id'];
        $field = $_POST['field'] === 'orderable' ? 'orderable' : 'active';
        $val   = (int)(bool)$_POST['value'];
        $pdo->prepare("UPDATE mp_service_products SET {$field}=?,updated_at=NOW() WHERE id=?")->execute([$val, $pid]);
        flash('success', "Product updated.");

    /* ── PRICE: create ── */
    } elseif ($action === 'create_price') {
        $pid        = (int)$_POST['product_id'];
        $variantKey = trim($_POST['variant_key'] ?? '');
        $priceType  = $_POST['price_type'] ?? 'one_off';
        $setup      = (int)$_POST['setup_amount'];
        $recur      = (int)$_POST['recurring_amount'];
        $interval   = $_POST['billing_interval'] ?? 'NONE';
        $mailbox    = ($_POST['mailbox_limit'] !== '' && $_POST['mailbox_limit'] !== null) ? (int)$_POST['mailbox_limit'] : null;
        $effFrom    = $_POST['effective_from'] ?? date('Y-m-d');
        $effTo      = trim($_POST['effective_to'] ?? '') ?: null;
        if (!$variantKey) $variantKey = $priceType;
        if ($pid) {
            $pdo->prepare("INSERT INTO mp_service_prices (product_id,variant_key,price_type,setup_amount,recurring_amount,currency,billing_interval,mailbox_limit,effective_from,effective_to,active) VALUES (?,?,?,?,?,'XAF',?,?,?,?,1)")
                ->execute([$pid, $variantKey, $priceType, $setup, $recur, $interval, $mailbox, $effFrom, $effTo]);
            flash('success', "Price added.");
        }

    /* ── PRICE: update ── */
    } elseif ($action === 'update_price') {
        $prid       = (int)$_POST['price_id'];
        $variantKey = trim($_POST['variant_key'] ?? '');
        $priceType  = $_POST['price_type'] ?? 'one_off';
        $setup      = (int)$_POST['setup_amount'];
        $recur      = (int)$_POST['recurring_amount'];
        $interval   = $_POST['billing_interval'] ?? 'NONE';
        $mailbox    = ($_POST['mailbox_limit'] !== '' && isset($_POST['mailbox_limit'])) ? (int)$_POST['mailbox_limit'] : null;
        $effFrom    = $_POST['effective_from'] ?? date('Y-m-d');
        $effTo      = trim($_POST['effective_to'] ?? '') ?: null;
        $pdo->prepare("UPDATE mp_service_prices SET variant_key=?,price_type=?,setup_amount=?,recurring_amount=?,billing_interval=?,mailbox_limit=?,effective_from=?,effective_to=?,updated_at=NOW() WHERE id=?")
            ->execute([$variantKey, $priceType, $setup, $recur, $interval, $mailbox, $effFrom, $effTo, $prid]);
        flash('success', "Price saved.");

    /* ── PRICE: delete ── */
    } elseif ($action === 'delete_price') {
        $prid = (int)$_POST['price_id'];
        $pdo->prepare("DELETE FROM mp_service_prices WHERE id=?")->execute([$prid]);
        flash('success', "Price deleted.");

    /* ── PRICE: toggle ── */
    } elseif ($action === 'toggle_price') {
        $prid = (int)$_POST['price_id'];
        $val  = (int)(bool)$_POST['value'];
        $pdo->prepare("UPDATE mp_service_prices SET active=?,updated_at=NOW() WHERE id=?")->execute([$val, $prid]);
        flash('success', "Price updated.");

    /* ── enable all ── */
    } elseif ($action === 'enable_all') {
        $pdo->exec("UPDATE mp_service_products SET active=1,orderable=1,updated_at=NOW()");
        $pdo->exec("UPDATE mp_service_categories SET active=1,orderable=1");
        $pdo->exec("UPDATE mp_service_prices SET active=1,updated_at=NOW()");
        flash('success', "All products enabled and made orderable.");

    /* ── settings ── */
    } elseif ($action === 'update_setting') {
        $key = $_POST['setting_key'] ?? '';
        $val = trim($_POST['setting_value'] ?? '');
        $allowed = ['mp_approval_expiry_days','mp_payment_instruction_en','mp_payment_instruction_fr',
                    'mp_commission_enabled','mp_auto_renewal_enabled','mp_fulfilment_sla_days',
                    'mtn_momo_number','orange_money_number'];
        if (in_array($key, $allowed)) {
            $pdo->prepare("INSERT INTO site_settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?")
                ->execute([$key, $val, $val]);
            flash('success', "Setting saved.");
        }
    }

    redirect(SITE_URL . '/admin/marketplace-catalogue');
}

/* ── Load data ───────────────────────────────────────────────── */
$migrationNeeded = false;
try {
    $cats = $pdo->query("
        SELECT c.*, COUNT(DISTINCT p.id) AS product_count, SUM(p.orderable) AS orderable_count
        FROM mp_service_categories c
        LEFT JOIN mp_service_products p ON p.category_id=c.id
        GROUP BY c.id ORDER BY c.sort_order, c.name_en
    ")->fetchAll();

    $products = $pdo->query("
        SELECT p.*, c.name_en AS cat_name
        FROM mp_service_products p
        JOIN mp_service_categories c ON c.id=p.category_id
        ORDER BY c.sort_order, p.sort_order, p.name_en
    ")->fetchAll();

    $prices = $pdo->query("
        SELECT pr.*, p.name_en AS product_name
        FROM mp_service_prices pr
        JOIN mp_service_products p ON p.id=pr.product_id
        ORDER BY p.sort_order, pr.price_type DESC, pr.id
    ")->fetchAll();

    $stS = $pdo->query("SELECT `key`,value FROM site_settings WHERE `key` LIKE 'mp_%' OR `key` IN ('mtn_momo_number','orange_money_number')");
    $settings = [];
    foreach ($stS->fetchAll() as $r) $settings[$r['key']] = $r['value'];

    $orderCount     = (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders")->fetchColumn();
    $pendingPayCount= (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders WHERE order_state='PAYMENT_REVIEW'")->fetchColumn();
} catch (\Throwable $e) {
    $migrationNeeded = true;
    $cats = $products = $prices = $settings = [];
    $orderCount = $pendingPayCount = 0;
}

$pricesByProduct = [];
foreach ($prices as $pr) $pricesByProduct[$pr['product_id']][] = $pr;

$totalProducts  = count($products);
$orderableCount = count(array_filter($products, fn($p) => $p['orderable']));
$activeCount    = count(array_filter($products, fn($p) => $p['active']));

$catOptions = '';
foreach ($cats as $c) $catOptions .= '<option value="' . $c['id'] . '">' . htmlspecialchars($c['name_en'], ENT_QUOTES) . '</option>';

$serviceTypes = ['website_setup','website_renewal','business_email','listing_subscription'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/admin/includes/mp-admin.css">
<style>
/* ── CRUD modal overlay ── */
.mp-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9000; align-items:center; justify-content:center; }
.mp-modal-backdrop.open { display:flex; }
.mp-modal { background:#0f1f17; border:1px solid rgba(0,168,120,.25); border-radius:12px; padding:1.5rem; width:min(640px,96vw); max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.7); }
.mp-modal h3 { color:#00d49a; margin:0 0 1.2rem; font-size:1.1rem; }
.mp-modal label { font-size:.82rem; color:#8db8a8; margin-bottom:.2rem; display:block; }
.mp-modal input, .mp-modal select, .mp-modal textarea { width:100%; background:#081410; border:1px solid rgba(0,168,120,.2); color:#e8f4ee; border-radius:6px; padding:.4rem .6rem; font-size:.88rem; margin-bottom:.75rem; }
.mp-modal input:focus, .mp-modal select:focus, .mp-modal textarea:focus { outline:none; border-color:#00A878; }
.mp-modal .row2 { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
.mp-modal .row3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:.75rem; }
.mp-modal .modal-footer { display:flex; gap:.5rem; justify-content:flex-end; margin-top:.5rem; padding-top:1rem; border-top:1px solid rgba(0,168,120,.12); }
.mp-modal .btn-cancel { background:none; border:1px solid rgba(0,168,120,.2); color:#8db8a8; padding:.4rem 1rem; border-radius:6px; cursor:pointer; }
.mp-modal .btn-save { background:#00A878; color:#fff; border:none; padding:.4rem 1.2rem; border-radius:6px; cursor:pointer; font-weight:600; }
.mp-modal .btn-save:hover { background:#00d49a; }
/* product rows in table */
.prod-row td { vertical-align:top; }
.price-chip { display:inline-flex; align-items:center; gap:.3rem; background:rgba(0,168,120,.12); border:1px solid rgba(0,168,120,.18); border-radius:5px; padding:.15rem .5rem; font-size:.78rem; color:#00d49a; margin:.15rem 0; }
.price-chip .edit-price-btn { cursor:pointer; opacity:.6; font-size:.7rem; }
.price-chip .edit-price-btn:hover { opacity:1; }
.mp-add-btn { background:none; border:1px dashed rgba(0,168,120,.35); color:#00A878; border-radius:6px; padding:.2rem .6rem; font-size:.78rem; cursor:pointer; }
.mp-add-btn:hover { background:rgba(0,168,120,.1); }
.mp-edit-icon { cursor:pointer; color:#00A878; opacity:.6; font-size:.8rem; margin-left:.4rem; }
.mp-edit-icon:hover { opacity:1; }
.mp-del-icon { cursor:pointer; color:#ef4444; opacity:.5; font-size:.8rem; margin-left:.3rem; }
.mp-del-icon:hover { opacity:1; }
</style>

<div class="mp-wrap">
<?php if ($migrationNeeded): ?>
<div style="background:#1a0a0a;border:1px solid #c0392b;border-radius:10px;padding:1.5rem 2rem;margin-bottom:2rem;color:#e8d0d0;">
  <strong style="color:#e74c3c;font-size:1.05rem;">⚠️ Database tables not found</strong><br>
  <p style="margin:.6rem 0 1rem;">The marketplace database tables have not been created yet. Run the migration SQL to set up the marketplace.</p>
  <p style="font-size:.9rem;color:#b08080;">Go to <strong>cPanel → phpMyAdmin → biz97h_app</strong>, then run the contents of <code>admin/marketplace-migration.sql</code> from the repository.</p>
</div>
<?php endif; ?>

  <!-- Page header -->
  <div class="mp-page-header">
    <div>
      <h1 class="mp-page-title">🛒 Marketplace Catalogue</h1>
      <div class="mp-page-subtitle">Products, pricing &amp; marketplace configuration</div>
    </div>
    <div class="mp-nav-links">
      <button class="mp-btn success" onclick="openModal('modal-add-category')">➕ Add Category</button>
      <a href="<?= SITE_URL ?>/admin/marketplace-orders" class="mp-btn primary">
        📋 Orders<?php if ($pendingPayCount): ?> <span class="mp-badge yellow"><?= $pendingPayCount ?></span><?php endif; ?>
      </a>
      <a href="<?= SITE_URL ?>/admin/marketplace-fulfilment" class="mp-btn secondary">⚙️ Fulfilment</a>
    </div>
  </div>

  <?php showFlash(); ?>

  <!-- Stats -->
  <div class="mp-stats">
    <div class="mp-stat-card"><div class="mp-stat-value"><?= count($cats) ?></div><div class="mp-stat-label">Categories</div></div>
    <div class="mp-stat-card"><div class="mp-stat-value"><?= $totalProducts ?></div><div class="mp-stat-label">Products</div></div>
    <div class="mp-stat-card"><div class="mp-stat-value green"><?= $orderableCount ?></div><div class="mp-stat-label">Orderable</div></div>
    <div class="mp-stat-card"><div class="mp-stat-value"><?= $orderCount ?></div><div class="mp-stat-label">Total Orders</div></div>
    <div class="mp-stat-card"><div class="mp-stat-value yellow"><?= $pendingPayCount ?></div><div class="mp-stat-label">Pending Review</div></div>
  </div>

  <?php if ($orderableCount === 0 && $totalProducts > 0): ?>
  <div class="mp-alert">
    <span class="mp-alert-icon">⚠️</span>
    <div>
      <strong>Marketplace not yet open to partners.</strong>
      All products are currently disabled. Enable individual products below, or use the button to enable everything at once.
      <form method="POST" style="display:inline;margin-top:0.5rem;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="enable_all">
        <button class="mp-btn success" style="margin-top:0.5rem;" onclick="return confirm('Enable ALL products and make them orderable?')">
          🚀 Enable All Products Now
        </button>
      </form>
    </div>
  </div>
  <?php elseif ($totalProducts === 0): ?>
  <div class="mp-alert">
    <span class="mp-alert-icon">📭</span>
    <div><strong>No products yet.</strong> Click <strong>Add Category</strong> to get started, then add products inside it.</div>
  </div>
  <?php endif; ?>

  <!-- Catalogue -->
  <?php foreach ($cats as $cat): ?>
  <?php $catProds = array_filter($products, fn($p) => $p['category_id'] == $cat['id']); ?>
  <div class="mp-category">
    <div class="mp-category-header">
      <div class="mp-category-title">
        <span><?= e($cat['icon']) ?></span>
        <?= e($cat['name_en']) ?>
        <?php if ($cat['name_fr'] !== $cat['name_en']): ?>
          <span style="color:#5a8878;font-size:.8rem;">(<?= e($cat['name_fr']) ?>)</span>
        <?php endif; ?>
        <span class="mp-badge grey"><?= count($catProds) ?> products</span>
        <?php if (!$cat['active']): ?><span class="mp-badge red">Inactive</span><?php endif; ?>
        <?php if (!$cat['orderable']): ?><span class="mp-badge yellow">Hidden</span><?php endif; ?>
        <!-- edit / delete category -->
        <span class="mp-edit-icon" title="Edit category"
          onclick="openEditCategory(<?= $cat['id'] ?>,<?= htmlspecialchars(json_encode($cat['name_en']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($cat['name_fr']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($cat['icon']),ENT_QUOTES) ?>,<?= (int)$cat['sort_order'] ?>)">✏️</span>
        <span class="mp-del-icon" title="Delete category"
          onclick="confirmDeleteCategory(<?= $cat['id'] ?>,<?= htmlspecialchars(json_encode($cat['name_en']),ENT_QUOTES) ?>)">🗑</span>
      </div>
      <div class="mp-category-actions">
        <form method="POST" style="display:inline;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_category">
          <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
          <input type="hidden" name="field" value="active">
          <input type="hidden" name="value" value="<?= $cat['active'] ? 0 : 1 ?>">
          <button class="mp-toggle <?= $cat['active'] ? 'danger' : 'on' ?>"><?= $cat['active'] ? '✕ Deactivate' : '✓ Activate' ?></button>
        </form>
        <form method="POST" style="display:inline;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_category">
          <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
          <input type="hidden" name="field" value="orderable">
          <input type="hidden" name="value" value="<?= $cat['orderable'] ? 0 : 1 ?>">
          <button class="mp-toggle <?= $cat['orderable'] ? 'off' : 'on' ?>"><?= $cat['orderable'] ? '👁 Hide' : '👁 Show to partners' ?></button>
        </form>
        <button class="mp-btn success sm" onclick="openAddProduct(<?= $cat['id'] ?>,<?= htmlspecialchars(json_encode($cat['name_en']),ENT_QUOTES) ?>)">➕ Add Product</button>
      </div>
    </div>

    <?php if (empty($catProds)): ?>
    <div style="padding:1rem 1.5rem;color:#5a8878;font-size:.88rem;">No products yet — click <strong>Add Product</strong> to create one.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="mp-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Type</th>
            <th>Prices</th>
            <th style="text-align:center;">Active</th>
            <th style="text-align:center;">Orderable</th>
            <th style="width:70px;"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($catProds as $prod):
          $pPrices = $pricesByProduct[$prod['id']] ?? [];
        ?>
          <tr class="prod-row">
            <td>
              <div class="mp-product-name"><?= e($prod['name_en']) ?></div>
              <?php if ($prod['name_fr'] && $prod['name_fr'] !== $prod['name_en']): ?>
              <div class="mp-product-name-fr"><?= e($prod['name_fr']) ?></div>
              <?php endif; ?>
              <?php if ($prod['description_en']): ?>
              <div style="color:#5a8878;font-size:.75rem;margin-top:.15rem;"><?= e(mb_strimwidth($prod['description_en'],0,60,'…')) ?></div>
              <?php endif; ?>
              <div class="mp-product-key"><?= e($prod['key']) ?></div>
            </td>
            <td><span class="mp-badge grey"><?= e(str_replace('_',' ',$prod['service_type'])) ?></span></td>
            <td>
              <?php foreach ($pPrices as $pr): ?>
              <div class="price-chip">
                <span><?= e($pr['variant_key'] ?: $pr['price_type']) ?>:
                  <?php if ($pr['setup_amount']>0): ?><?= mpFmt($pr['setup_amount']) ?> setup<?php endif; ?>
                  <?php if ($pr['recurring_amount']>0): ?><?= mpFmt($pr['recurring_amount']) ?>/<?= strtolower($pr['billing_interval']) ?><?php endif; ?>
                  <?php if (!$pr['active']): ?><em style="color:#ef4444">(off)</em><?php endif; ?>
                </span>
                <span class="edit-price-btn" title="Edit price"
                  onclick="openEditPrice(<?= $pr['id'] ?>,<?= $prod['id'] ?>,
                    <?= htmlspecialchars(json_encode($pr['variant_key']),ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode($pr['price_type']),ENT_QUOTES) ?>,
                    <?= (int)$pr['setup_amount'] ?>,<?= (int)$pr['recurring_amount'] ?>,
                    <?= htmlspecialchars(json_encode($pr['billing_interval']),ENT_QUOTES) ?>,
                    <?= $pr['mailbox_limit'] !== null ? (int)$pr['mailbox_limit'] : 'null' ?>,
                    <?= htmlspecialchars(json_encode($pr['effective_from']),ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode($pr['effective_to'] ?? ''),ENT_QUOTES) ?>)">✏️</span>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this price?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_price">
                  <input type="hidden" name="price_id" value="<?= $pr['id'] ?>">
                  <button type="submit" style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:.7rem;opacity:.5;padding:0;" title="Delete price">✕</button>
                </form>
              </div>
              <?php endforeach; ?>
              <div style="margin-top:.3rem;">
                <button class="mp-add-btn" onclick="openAddPrice(<?= $prod['id'] ?>,<?= htmlspecialchars(json_encode($prod['name_en']),ENT_QUOTES) ?>)">+ Add price</button>
              </div>
            </td>
            <td style="text-align:center;">
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_product">
                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                <input type="hidden" name="field" value="active">
                <input type="hidden" name="value" value="<?= $prod['active'] ? 0 : 1 ?>">
                <button class="mp-toggle <?= $prod['active'] ? 'on' : 'off' ?>"><?= $prod['active'] ? '✓' : 'No' ?></button>
              </form>
            </td>
            <td style="text-align:center;">
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_product">
                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                <input type="hidden" name="field" value="orderable">
                <input type="hidden" name="value" value="<?= $prod['orderable'] ? 0 : 1 ?>">
                <button class="mp-toggle <?= $prod['orderable'] ? 'on' : 'off' ?>"><?= $prod['orderable'] ? '✓' : 'No' ?></button>
              </form>
            </td>
            <td style="text-align:right;white-space:nowrap;">
              <span class="mp-edit-icon" title="Edit product"
                onclick="openEditProduct(<?= $prod['id'] ?>,<?= $prod['category_id'] ?>,
                  <?= htmlspecialchars(json_encode($prod['name_en']),ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($prod['name_fr']),ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($prod['description_en'] ?? ''),ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($prod['description_fr'] ?? ''),ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($prod['service_type']),ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($prod['terms_en'] ?? ''),ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($prod['terms_fr'] ?? ''),ENT_QUOTES) ?>,
                  <?= (int)$prod['sort_order'] ?>,
                  <?= (float)($prod['partner_commission_rate'] ?? 0) ?>)">✏️ Edit</span>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this product and all its prices?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_product">
                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                <button type="submit" class="mp-del-icon" style="background:none;border:none;" title="Delete product">🗑</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <!-- §14 Configuration settings -->
  <div class="mp-config">
    <div class="mp-config-header">⚙️ Marketplace Configuration</div>
    <div class="mp-config-body">
      <div class="mp-config-grid">

        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_approval_expiry_days">
            <label class="mp-config-label">Owner approval expiry (days)</label>
            <div class="input-group input-group-sm">
              <input type="number" name="setting_value" class="form-control" value="<?= e($settings['mp_approval_expiry_days'] ?? '7') ?>" min="0" max="30">
              <button class="btn btn-outline-success">Save</button>
            </div>
            <div class="mp-config-hint">0 = never expires.</div>
          </form>
        </div>

        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_fulfilment_sla_days">
            <label class="mp-config-label">Fulfilment SLA (days)</label>
            <div class="input-group input-group-sm">
              <input type="number" name="setting_value" class="form-control" value="<?= e($settings['mp_fulfilment_sla_days'] ?? '5') ?>" min="1" max="60">
              <button class="btn btn-outline-success">Save</button>
            </div>
          </form>
        </div>

        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mtn_momo_number">
            <label class="mp-config-label">MTN MoMo number</label>
            <div class="input-group input-group-sm">
              <input type="text" name="setting_value" class="form-control" value="<?= e($settings['mtn_momo_number'] ?? '') ?>" placeholder="e.g. 237670000000">
              <button class="btn btn-outline-success">Save</button>
            </div>
          </form>
        </div>

        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="orange_money_number">
            <label class="mp-config-label">Orange Money number</label>
            <div class="input-group input-group-sm">
              <input type="text" name="setting_value" class="form-control" value="<?= e($settings['orange_money_number'] ?? '') ?>" placeholder="e.g. 237690000000">
              <button class="btn btn-outline-success">Save</button>
            </div>
          </form>
        </div>

        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_payment_instruction_en">
            <label class="mp-config-label">Payment instructions (English)</label>
            <textarea name="setting_value" class="form-control form-control-sm" rows="3"><?= e($settings['mp_payment_instruction_en'] ?? '') ?></textarea>
            <button class="mp-btn success sm" style="margin-top:.4rem;">Save</button>
          </form>
        </div>

        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_payment_instruction_fr">
            <label class="mp-config-label">Instructions de paiement (Français)</label>
            <textarea name="setting_value" class="form-control form-control-sm" rows="3"><?= e($settings['mp_payment_instruction_fr'] ?? '') ?></textarea>
            <button class="mp-btn success sm" style="margin-top:.4rem;">Save</button>
          </form>
        </div>

        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_commission_enabled">
            <label class="mp-config-label">Commission accrual</label>
            <select name="setting_value" class="form-select form-select-sm">
              <option value="0" <?= ($settings['mp_commission_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled</option>
              <option value="1" <?= ($settings['mp_commission_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
            </select>
            <button class="mp-btn success sm" style="margin-top:.4rem;">Save</button>
            <div class="mp-config-hint danger">Only enable once commercial terms are confirmed with partners.</div>
          </form>
        </div>

        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_auto_renewal_enabled">
            <label class="mp-config-label">Automatic renewal collection</label>
            <select name="setting_value" class="form-select form-select-sm">
              <option value="0" <?= ($settings['mp_auto_renewal_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled — reminders only</option>
              <option value="1" <?= ($settings['mp_auto_renewal_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
            </select>
            <button class="mp-btn success sm" style="margin-top:.4rem;">Save</button>
          </form>
        </div>

      </div>
    </div>
  </div>

</div>

<!-- ══════════ MODALS ══════════ -->

<!-- Add Category -->
<div class="mp-modal-backdrop" id="modal-add-category">
  <div class="mp-modal">
    <h3>➕ New Category</h3>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_category">
      <div class="row2">
        <div>
          <label>Name (English) *</label>
          <input type="text" name="name_en" required placeholder="e.g. Web Services">
        </div>
        <div>
          <label>Name (Français)</label>
          <input type="text" name="name_fr" placeholder="e.g. Services Web">
        </div>
      </div>
      <div class="row2">
        <div>
          <label>Icon (emoji)</label>
          <input type="text" name="icon" value="🏷️" maxlength="4">
        </div>
        <div>
          <label>Sort order</label>
          <input type="number" name="sort_order" value="0" min="0">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modal-add-category')">Cancel</button>
        <button type="submit" class="btn-save">Create Category</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Category -->
<div class="mp-modal-backdrop" id="modal-edit-category">
  <div class="mp-modal">
    <h3>✏️ Edit Category</h3>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_category">
      <input type="hidden" name="category_id" id="edit-cat-id">
      <div class="row2">
        <div>
          <label>Name (English) *</label>
          <input type="text" name="name_en" id="edit-cat-name-en" required>
        </div>
        <div>
          <label>Name (Français)</label>
          <input type="text" name="name_fr" id="edit-cat-name-fr">
        </div>
      </div>
      <div class="row2">
        <div>
          <label>Icon (emoji)</label>
          <input type="text" name="icon" id="edit-cat-icon" maxlength="4">
        </div>
        <div>
          <label>Sort order</label>
          <input type="number" name="sort_order" id="edit-cat-sort" min="0">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modal-edit-category')">Cancel</button>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Category (hidden form) -->
<form method="POST" id="form-delete-category" style="display:none;">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete_category">
  <input type="hidden" name="category_id" id="del-cat-id">
</form>

<!-- Add Product -->
<div class="mp-modal-backdrop" id="modal-add-product">
  <div class="mp-modal">
    <h3>➕ New Product</h3>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_product">
      <input type="hidden" name="category_id" id="add-prod-cat-id">
      <div style="background:rgba(0,168,120,.08);border-radius:6px;padding:.5rem .75rem;font-size:.8rem;color:#8db8a8;margin-bottom:.75rem;">
        Adding to: <strong id="add-prod-cat-name"></strong>
      </div>
      <div class="row2">
        <div>
          <label>Name (English) *</label>
          <input type="text" name="name_en" required placeholder="e.g. Website Setup">
        </div>
        <div>
          <label>Name (Français)</label>
          <input type="text" name="name_fr" placeholder="e.g. Création de site">
        </div>
      </div>
      <div>
        <label>Description (English)</label>
        <textarea name="description_en" rows="2" placeholder="Short description shown on the marketplace card"></textarea>
      </div>
      <div>
        <label>Description (Français)</label>
        <textarea name="description_fr" rows="2" placeholder="Description courte"></textarea>
      </div>
      <div class="row2">
        <div>
          <label>Service type *</label>
          <select name="service_type">
            <option value="website_setup">Website Setup</option>
            <option value="website_renewal">Website Renewal</option>
            <option value="business_email">Business Email</option>
            <option value="listing_subscription">Listing Subscription</option>
          </select>
        </div>
        <div>
          <label>Sort order</label>
          <input type="number" name="sort_order" value="0" min="0">
        </div>
      </div>
      <div>
        <label>Terms &amp; conditions (English)</label>
        <textarea name="terms_en" rows="2" placeholder="Optional service terms shown before ordering"></textarea>
      </div>
      <div>
        <label>Conditions générales (Français)</label>
        <textarea name="terms_fr" rows="2"></textarea>
      </div>
      <div>
        <label>Partner commission rate (%)</label>
        <input type="number" name="partner_commission_rate" value="0" min="0" max="100" step="0.01"
               placeholder="e.g. 15 for 15%"
               style="max-width:160px;">
        <span style="font-size:.78rem;color:#8db8a8;display:block;margin-top:.25rem;">
          Percentage of the setup amount paid to the Growth Partner on completion.
        </span>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modal-add-product')">Cancel</button>
        <button type="submit" class="btn-save">Create Product</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Product -->
<div class="mp-modal-backdrop" id="modal-edit-product">
  <div class="mp-modal">
    <h3>✏️ Edit Product</h3>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_product">
      <input type="hidden" name="product_id" id="edit-prod-id">
      <div>
        <label>Category</label>
        <select name="category_id" id="edit-prod-cat">
          <?= $catOptions ?>
        </select>
      </div>
      <div class="row2">
        <div>
          <label>Name (English) *</label>
          <input type="text" name="name_en" id="edit-prod-name-en" required>
        </div>
        <div>
          <label>Name (Français)</label>
          <input type="text" name="name_fr" id="edit-prod-name-fr">
        </div>
      </div>
      <div>
        <label>Description (English)</label>
        <textarea name="description_en" id="edit-prod-desc-en" rows="2"></textarea>
      </div>
      <div>
        <label>Description (Français)</label>
        <textarea name="description_fr" id="edit-prod-desc-fr" rows="2"></textarea>
      </div>
      <div class="row2">
        <div>
          <label>Service type *</label>
          <select name="service_type" id="edit-prod-type">
            <option value="website_setup">Website Setup</option>
            <option value="website_renewal">Website Renewal</option>
            <option value="business_email">Business Email</option>
            <option value="listing_subscription">Listing Subscription</option>
          </select>
        </div>
        <div>
          <label>Sort order</label>
          <input type="number" name="sort_order" id="edit-prod-sort" min="0">
        </div>
      </div>
      <div>
        <label>Terms &amp; conditions (English)</label>
        <textarea name="terms_en" id="edit-prod-terms-en" rows="2"></textarea>
      </div>
      <div>
        <label>Conditions générales (Français)</label>
        <textarea name="terms_fr" id="edit-prod-terms-fr" rows="2"></textarea>
      </div>
      <div>
        <label>Partner commission rate (%)</label>
        <input type="number" name="partner_commission_rate" id="edit-prod-commission" value="0" min="0" max="100" step="0.01"
               placeholder="e.g. 15 for 15%"
               style="max-width:160px;">
        <span style="font-size:.78rem;color:#8db8a8;display:block;margin-top:.25rem;">
          Percentage of the setup amount paid to the Growth Partner on completion.
        </span>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modal-edit-product')">Cancel</button>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Price -->
<div class="mp-modal-backdrop" id="modal-add-price">
  <div class="mp-modal">
    <h3>➕ Add Price</h3>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_price">
      <input type="hidden" name="product_id" id="add-price-prod-id">
      <div style="background:rgba(0,168,120,.08);border-radius:6px;padding:.5rem .75rem;font-size:.8rem;color:#8db8a8;margin-bottom:.75rem;">
        Product: <strong id="add-price-prod-name"></strong>
      </div>
      <div class="row2">
        <div>
          <label>Variant label *</label>
          <input type="text" name="variant_key" required placeholder="e.g. standard, pro, basic">
        </div>
        <div>
          <label>Price type *</label>
          <select name="price_type" id="add-price-type" onchange="togglePriceFields('add')">
            <option value="setup">Setup (one-time + optional recurring)</option>
            <option value="recurring">Recurring only</option>
            <option value="one_off">One-off</option>
          </select>
        </div>
      </div>
      <div class="row2">
        <div id="add-setup-wrap">
          <label>Setup amount (FCFA)</label>
          <input type="number" name="setup_amount" value="0" min="0">
        </div>
        <div id="add-recur-wrap">
          <label>Recurring amount (FCFA)</label>
          <input type="number" name="recurring_amount" value="0" min="0">
        </div>
      </div>
      <div class="row3">
        <div>
          <label>Billing interval</label>
          <select name="billing_interval">
            <option value="NONE">None</option>
            <option value="MONTH">Monthly</option>
            <option value="YEAR">Yearly</option>
          </select>
        </div>
        <div>
          <label>Mailbox limit (optional)</label>
          <input type="number" name="mailbox_limit" value="" min="1" placeholder="Leave blank if N/A">
        </div>
        <div>
          <label>Effective from</label>
          <input type="date" name="effective_from" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div>
        <label>Effective to (optional — leave blank for no expiry)</label>
        <input type="date" name="effective_to" value="">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modal-add-price')">Cancel</button>
        <button type="submit" class="btn-save">Add Price</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Price -->
<div class="mp-modal-backdrop" id="modal-edit-price">
  <div class="mp-modal">
    <h3>✏️ Edit Price</h3>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_price">
      <input type="hidden" name="price_id" id="edit-price-id">
      <div class="row2">
        <div>
          <label>Variant label *</label>
          <input type="text" name="variant_key" id="edit-price-variant" required>
        </div>
        <div>
          <label>Price type *</label>
          <select name="price_type" id="edit-price-type" onchange="togglePriceFields('edit')">
            <option value="setup">Setup (one-time + optional recurring)</option>
            <option value="recurring">Recurring only</option>
            <option value="one_off">One-off</option>
          </select>
        </div>
      </div>
      <div class="row2">
        <div>
          <label>Setup amount (FCFA)</label>
          <input type="number" name="setup_amount" id="edit-price-setup" value="0" min="0">
        </div>
        <div>
          <label>Recurring amount (FCFA)</label>
          <input type="number" name="recurring_amount" id="edit-price-recur" value="0" min="0">
        </div>
      </div>
      <div class="row3">
        <div>
          <label>Billing interval</label>
          <select name="billing_interval" id="edit-price-interval">
            <option value="NONE">None</option>
            <option value="MONTH">Monthly</option>
            <option value="YEAR">Yearly</option>
          </select>
        </div>
        <div>
          <label>Mailbox limit</label>
          <input type="number" name="mailbox_limit" id="edit-price-mailbox" value="" min="1" placeholder="Leave blank if N/A">
        </div>
        <div>
          <label>Effective from</label>
          <input type="date" name="effective_from" id="edit-price-from">
        </div>
      </div>
      <div>
        <label>Effective to (blank = no expiry)</label>
        <input type="date" name="effective_to" id="edit-price-to">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modal-edit-price')">Cancel</button>
        <button type="submit" class="btn-save">Save Price</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close on backdrop click
document.querySelectorAll('.mp-modal-backdrop').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

function openEditCategory(id, nameEn, nameFr, icon, sort) {
  document.getElementById('edit-cat-id').value = id;
  document.getElementById('edit-cat-name-en').value = nameEn;
  document.getElementById('edit-cat-name-fr').value = nameFr;
  document.getElementById('edit-cat-icon').value = icon;
  document.getElementById('edit-cat-sort').value = sort;
  openModal('modal-edit-category');
}

function confirmDeleteCategory(id, name) {
  if (!confirm('Delete category "' + name + '"? All products must be removed first.')) return;
  document.getElementById('del-cat-id').value = id;
  document.getElementById('form-delete-category').submit();
}

function openAddProduct(catId, catName) {
  document.getElementById('add-prod-cat-id').value = catId;
  document.getElementById('add-prod-cat-name').textContent = catName;
  openModal('modal-add-product');
}

function openEditProduct(id, catId, nameEn, nameFr, descEn, descFr, type, termsEn, termsFr, sort, commissionRate) {
  document.getElementById('edit-prod-id').value = id;
  document.getElementById('edit-prod-name-en').value = nameEn;
  document.getElementById('edit-prod-name-fr').value = nameFr;
  document.getElementById('edit-prod-desc-en').value = descEn;
  document.getElementById('edit-prod-desc-fr').value = descFr;
  document.getElementById('edit-prod-terms-en').value = termsEn;
  document.getElementById('edit-prod-terms-fr').value = termsFr;
  document.getElementById('edit-prod-sort').value = sort;
  document.getElementById('edit-prod-type').value = type;
  document.getElementById('edit-prod-commission').value = commissionRate ?? 0;
  const catSel = document.getElementById('edit-prod-cat');
  if (catSel) catSel.value = catId;
  openModal('modal-edit-product');
}

function openAddPrice(prodId, prodName) {
  document.getElementById('add-price-prod-id').value = prodId;
  document.getElementById('add-price-prod-name').textContent = prodName;
  openModal('modal-add-price');
}

function openEditPrice(priceId, prodId, variant, type, setup, recur, interval, mailbox, effFrom, effTo) {
  document.getElementById('edit-price-id').value = priceId;
  document.getElementById('edit-price-variant').value = variant;
  document.getElementById('edit-price-type').value = type;
  document.getElementById('edit-price-setup').value = setup;
  document.getElementById('edit-price-recur').value = recur;
  document.getElementById('edit-price-interval').value = interval;
  document.getElementById('edit-price-mailbox').value = mailbox !== null ? mailbox : '';
  document.getElementById('edit-price-from').value = effFrom;
  document.getElementById('edit-price-to').value = effTo || '';
  openModal('modal-edit-price');
}

function togglePriceFields(prefix) {
  // Could show/hide setup vs recurring fields based on type — currently all visible
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
