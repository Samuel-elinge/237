<?php
/**
 * admin/marketplace-catalogue.php — Manage the Growth Partner Services Marketplace catalogue
 *
 * Allows admin to:
 *  - Enable/disable categories and products
 *  - Toggle orderable flag (makes product visible to partners)
 *  - View and edit prices
 *  - Add custom products / prices (future)
 *
 * This is the §14 configuration page — products remain orderable=0 until
 * admin reviews each one here and explicitly enables it.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/flash-helpers.php';
requireAdmin();

$pdo = db();

/* ── POST: toggle flags ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_product') {
        $pid   = (int)$_POST['product_id'];
        $field = $_POST['field'] === 'orderable' ? 'orderable' : 'active';
        $val   = (int)(bool)$_POST['value'];
        $pdo->prepare("UPDATE mp_service_products SET {$field} = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$val, $pid]);
        flash('success', "Product updated.");

    } elseif ($action === 'toggle_category') {
        $cid   = (int)$_POST['category_id'];
        $field = $_POST['field'] === 'orderable' ? 'orderable' : 'active';
        $val   = (int)(bool)$_POST['value'];
        $pdo->prepare("UPDATE mp_service_categories SET {$field} = ? WHERE id = ?")
            ->execute([$val, $cid]);
        flash('success', "Category updated.");

    } elseif ($action === 'toggle_price') {
        $prid = (int)$_POST['price_id'];
        $val  = (int)(bool)$_POST['value'];
        $pdo->prepare("UPDATE mp_service_prices SET active = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$val, $prid]);
        flash('success', "Price updated.");

    } elseif ($action === 'update_price') {
        $prid    = (int)$_POST['price_id'];
        $setup   = (int)$_POST['setup_amount'];
        $recur   = (int)$_POST['recurring_amount'];
        $pdo->prepare("UPDATE mp_service_prices SET setup_amount=?, recurring_amount=?, updated_at=NOW() WHERE id=?")
            ->execute([$setup, $recur, $prid]);
        flash('success', "Price saved.");

    } elseif ($action === 'enable_all') {
        // Enable all products for the marketplace at once (§14 bulk config)
        $pdo->exec("UPDATE mp_service_products SET active=1, orderable=1, updated_at=NOW()");
        $pdo->exec("UPDATE mp_service_categories SET active=1, orderable=1");
        $pdo->exec("UPDATE mp_service_prices SET active=1, updated_at=NOW()");
        flash('success', "All products enabled and made orderable.");

    } elseif ($action === 'update_setting') {
        $key = $_POST['setting_key'] ?? '';
        $val = trim($_POST['setting_value'] ?? '');
        $allowed = [
            'mp_approval_expiry_days',
            'mp_payment_instruction_en',
            'mp_payment_instruction_fr',
            'mp_commission_enabled',
            'mp_auto_renewal_enabled',
            'mp_fulfilment_sla_days',
        ];
        if (in_array($key, $allowed)) {
            $pdo->prepare("INSERT INTO site_settings (key, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?")
                ->execute([$key, $val, $val]);
            flash('success', "Setting saved.");
        }
    }

    redirect(SITE_URL . '/admin/marketplace-catalogue');
}

/* ── Load catalogue ──────────────────────────────────────────── */
$cats = $pdo->query("
    SELECT c.*,
           COUNT(DISTINCT p.id) AS product_count,
           SUM(p.orderable)     AS orderable_count
    FROM mp_service_categories c
    LEFT JOIN mp_service_products p ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY c.sort_order
")->fetchAll();

$products = $pdo->query("
    SELECT p.*, c.name_en AS cat_name, c.`key` AS cat_key
    FROM mp_service_products p
    JOIN mp_service_categories c ON c.id = p.category_id
    ORDER BY c.sort_order, p.sort_order
")->fetchAll();

$prices = $pdo->query("
    SELECT pr.*, p.name_en AS product_name, p.`key` AS product_key
    FROM mp_service_prices pr
    JOIN mp_service_products p ON p.id = pr.product_id
    ORDER BY p.sort_order, pr.price_type DESC, pr.id
")->fetchAll();

// Group prices by product_id
$pricesByProduct = [];
foreach ($prices as $pr) {
    $pricesByProduct[$pr['product_id']][] = $pr;
}

/* ── Load settings ───────────────────────────────────────────── */
$settingKeys = [
    'mp_approval_expiry_days',
    'mp_payment_instruction_en',
    'mp_payment_instruction_fr',
    'mp_commission_enabled',
    'mp_auto_renewal_enabled',
    'mp_fulfilment_sla_days',
];
$settings = [];
$stS = $pdo->query("SELECT `key`, value FROM site_settings WHERE `key` LIKE 'mp_%'");
foreach ($stS->fetchAll() as $row) $settings[$row['key']] = $row['value'];

/* ── Stats ───────────────────────────────────────────────────── */
$totalProducts  = count($products);
$orderableCount = count(array_filter($products, fn($p) => $p['orderable']));
$activeCount    = count(array_filter($products, fn($p) => $p['active']));

$orderCount = (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders")->fetchColumn();
$pendingPayCount = (int)$pdo->query("SELECT COUNT(*) FROM marketplace_orders WHERE order_state='PAYMENT_REVIEW'")->fetchColumn();

function mpFmt(int $xaf): string { return number_format($xaf) . ' FCFA'; }

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/admin/includes/mp-admin.css">

<div class="mp-wrap">

  <!-- Page header -->
  <div class="mp-page-header">
    <div>
      <h1 class="mp-page-title">🛒 Marketplace Catalogue</h1>
      <div class="mp-page-subtitle">Products, pricing &amp; marketplace configuration</div>
    </div>
    <div class="mp-nav-links">
      <a href="<?= SITE_URL ?>/admin/marketplace-orders" class="mp-btn primary">
        📋 Orders<?php if ($pendingPayCount): ?> <span class="mp-badge yellow"><?= $pendingPayCount ?></span><?php endif; ?>
      </a>
      <a href="<?= SITE_URL ?>/admin/marketplace-fulfilment" class="mp-btn secondary">⚙️ Fulfilment</a>
    </div>
  </div>

  <?php showFlash(); ?>

  <!-- Stats -->
  <div class="mp-stats">
    <div class="mp-stat-card">
      <div class="mp-stat-value"><?= $totalProducts ?></div>
      <div class="mp-stat-label">Total Products</div>
    </div>
    <div class="mp-stat-card">
      <div class="mp-stat-value green"><?= $orderableCount ?></div>
      <div class="mp-stat-label">Orderable</div>
    </div>
    <div class="mp-stat-card">
      <div class="mp-stat-value"><?= $activeCount ?></div>
      <div class="mp-stat-label">Active</div>
    </div>
    <div class="mp-stat-card">
      <div class="mp-stat-value"><?= $orderCount ?></div>
      <div class="mp-stat-label">Total Orders</div>
    </div>
    <div class="mp-stat-card">
      <div class="mp-stat-value yellow"><?= $pendingPayCount ?></div>
      <div class="mp-stat-label">Pending Review</div>
    </div>
  </div>

  <?php if ($orderableCount === 0): ?>
  <!-- §14 Config alert -->
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
  <?php endif; ?>

  <!-- Catalogue -->
  <?php foreach ($cats as $cat): ?>
  <div class="mp-category">
    <div class="mp-category-header">
      <div class="mp-category-title">
        <span><?= e($cat['icon']) ?></span>
        <?= e($cat['name_en']) ?>
        <span class="mp-badge grey"><?= (int)$cat['product_count'] ?> products</span>
        <?php if (!$cat['active']): ?><span class="mp-badge red">Inactive</span><?php endif; ?>
        <?php if (!$cat['orderable']): ?><span class="mp-badge yellow">Hidden</span><?php endif; ?>
      </div>
      <div class="mp-category-actions">
        <form method="POST" style="display:inline;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_category">
          <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
          <input type="hidden" name="field" value="active">
          <input type="hidden" name="value" value="<?= $cat['active'] ? 0 : 1 ?>">
          <button class="mp-toggle <?= $cat['active'] ? 'danger' : 'on' ?>">
            <?= $cat['active'] ? '✕ Deactivate' : '✓ Activate' ?>
          </button>
        </form>
        <form method="POST" style="display:inline;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_category">
          <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
          <input type="hidden" name="field" value="orderable">
          <input type="hidden" name="value" value="<?= $cat['orderable'] ? 0 : 1 ?>">
          <button class="mp-toggle <?= $cat['orderable'] ? 'off' : 'on' ?>">
            <?= $cat['orderable'] ? '👁 Hide from partners' : '👁 Show to partners' ?>
          </button>
        </form>
      </div>
    </div>

    <div style="overflow-x:auto;">
      <table class="mp-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Type</th>
            <th>Prices (XAF)</th>
            <th style="text-align:center;">Active</th>
            <th style="text-align:center;">Orderable</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $prod):
            if ($prod['category_id'] != $cat['id']) continue;
            $pPrices = $pricesByProduct[$prod['id']] ?? [];
          ?>
          <tr>
            <td>
              <div class="mp-product-name"><?= e($prod['name_en']) ?></div>
              <div class="mp-product-name-fr"><?= e($prod['name_fr']) ?></div>
              <div class="mp-product-key"><?= e($prod['key']) ?></div>
            </td>
            <td><span class="mp-badge grey"><?= e($prod['service_type']) ?></span></td>
            <td>
              <?php foreach ($pPrices as $pr): ?>
              <div class="mp-price-row">
                <span class="mp-price-label"><?= e($pr['variant_key'] ?: $pr['price_type']) ?>:</span>
                <form method="POST" style="display:inline-flex;gap:0.3rem;align-items:center;">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="update_price">
                  <input type="hidden" name="price_id" value="<?= $pr['id'] ?>">
                  <?php if ($pr['price_type'] === 'setup' || $pr['setup_amount'] > 0): ?>
                  <input type="number" name="setup_amount" value="<?= (int)$pr['setup_amount'] ?>"
                    class="form-control form-control-sm" style="width:100px;" placeholder="Setup">
                  <?php else: ?>
                  <input type="hidden" name="setup_amount" value="0">
                  <?php endif; ?>
                  <?php if ($pr['recurring_amount'] > 0 || $pr['price_type'] === 'recurring'): ?>
                  <input type="number" name="recurring_amount" value="<?= (int)$pr['recurring_amount'] ?>"
                    class="form-control form-control-sm" style="width:100px;" placeholder="Recur">
                  <?php else: ?>
                  <input type="hidden" name="recurring_amount" value="0">
                  <?php endif; ?>
                  <button class="mp-btn primary sm">Save</button>
                </form>
                <form method="POST" style="display:inline;">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_price">
                  <input type="hidden" name="price_id" value="<?= $pr['id'] ?>">
                  <input type="hidden" name="value" value="<?= $pr['active'] ? 0 : 1 ?>">
                  <button class="mp-toggle <?= $pr['active'] ? 'on' : 'off' ?>" style="padding:0.2rem 0.5rem;font-size:0.7rem;">
                    <?= $pr['active'] ? '✓' : 'Off' ?>
                  </button>
                </form>
              </div>
              <?php endforeach; ?>
            </td>
            <td style="text-align:center;">
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_product">
                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                <input type="hidden" name="field" value="active">
                <input type="hidden" name="value" value="<?= $prod['active'] ? 0 : 1 ?>">
                <button class="mp-toggle <?= $prod['active'] ? 'on' : 'off' ?>">
                  <?= $prod['active'] ? '✓ Yes' : 'No' ?>
                </button>
              </form>
            </td>
            <td style="text-align:center;">
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_product">
                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                <input type="hidden" name="field" value="orderable">
                <input type="hidden" name="value" value="<?= $prod['orderable'] ? 0 : 1 ?>">
                <button class="mp-toggle <?= $prod['orderable'] ? 'on' : 'off' ?>">
                  <?= $prod['orderable'] ? '✓ Yes' : 'No' ?>
                </button>
              </form>
            </td>
            <td>
              <?php if ($prod['billing_interval']): ?>
              <span class="mp-badge grey"><?= e($prod['billing_interval']) ?></span>
              <?php endif; ?>
              <?php if ($prod['mailbox_capable']): ?>
              <span class="mp-badge blue">mailbox</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- §14 Configuration settings -->
  <div class="mp-config">
    <div class="mp-config-header">⚙️ Marketplace Configuration (§14)</div>
    <div class="mp-config-body">
      <div class="mp-config-grid">

        <!-- Approval expiry -->
        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_approval_expiry_days">
            <label class="mp-config-label">Owner approval expiry (days)</label>
            <div class="input-group input-group-sm">
              <input type="number" name="setting_value" class="form-control"
                value="<?= e($settings['mp_approval_expiry_days'] ?? '7') ?>" min="1" max="30">
              <button class="btn btn-outline-success">Save</button>
            </div>
            <div class="mp-config-hint">0 = never expires.</div>
          </form>
        </div>

        <!-- Fulfilment SLA -->
        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_fulfilment_sla_days">
            <label class="mp-config-label">Fulfilment SLA (days)</label>
            <div class="input-group input-group-sm">
              <input type="number" name="setting_value" class="form-control"
                value="<?= e($settings['mp_fulfilment_sla_days'] ?? '5') ?>" min="1" max="60">
              <button class="btn btn-outline-success">Save</button>
            </div>
            <div class="mp-config-hint">Target days from payment confirmed to service delivered.</div>
          </form>
        </div>

        <!-- Payment instructions EN -->
        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_payment_instruction_en">
            <label class="mp-config-label">Payment instructions (English)</label>
            <textarea name="setting_value" class="form-control form-control-sm" rows="3"><?= e($settings['mp_payment_instruction_en'] ?? '') ?></textarea>
            <button class="mp-btn success sm" style="margin-top:0.5rem;">Save</button>
          </form>
        </div>

        <!-- Payment instructions FR -->
        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_payment_instruction_fr">
            <label class="mp-config-label">Instructions de paiement (Français)</label>
            <textarea name="setting_value" class="form-control form-control-sm" rows="3"><?= e($settings['mp_payment_instruction_fr'] ?? '') ?></textarea>
            <button class="mp-btn success sm" style="margin-top:0.5rem;">Save</button>
          </form>
        </div>

        <!-- Commission toggle -->
        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_commission_enabled">
            <label class="mp-config-label">Commission accrual</label>
            <select name="setting_value" class="form-select form-select-sm">
              <option value="0" <?= ($settings['mp_commission_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled — no commission until policy approved</option>
              <option value="1" <?= ($settings['mp_commission_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
            </select>
            <button class="mp-btn success sm" style="margin-top:0.5rem;">Save</button>
            <div class="mp-config-hint danger">Only enable once commercial terms are confirmed with partners.</div>
          </form>
        </div>

        <!-- Auto-renewal toggle -->
        <div class="mp-config-field">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_auto_renewal_enabled">
            <label class="mp-config-label">Automatic renewal collection</label>
            <select name="setting_value" class="form-select form-select-sm">
              <option value="0" <?= ($settings['mp_auto_renewal_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled — send reminders only</option>
              <option value="1" <?= ($settings['mp_auto_renewal_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
            </select>
            <button class="mp-btn success sm" style="margin-top:0.5rem;">Save</button>
            <div class="mp-config-hint">When disabled, renewal reminders are sent but no charge is initiated automatically.</div>
          </form>
        </div>

      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
