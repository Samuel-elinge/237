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

<div class="admin-wrap">
  <div class="admin-header">
    <h1>🛒 Marketplace Catalogue</h1>
    <div class="admin-header-actions">
      <a href="<?= SITE_URL ?>/admin/marketplace-orders" class="btn btn-sm btn-outline-primary">
        📋 Orders <?php if ($pendingPayCount): ?><span class="badge bg-warning text-dark"><?= $pendingPayCount ?></span><?php endif; ?>
      </a>
      <a href="<?= SITE_URL ?>/admin/marketplace-fulfilment" class="btn btn-sm btn-outline-secondary">⚙️ Fulfilment</a>
    </div>
  </div>

  <?php showFlash(); ?>

  <!-- Stats bar -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card text-center p-3">
        <div class="fs-2 fw-bold"><?= $totalProducts ?></div>
        <div class="text-muted small">Products</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card text-center p-3">
        <div class="fs-2 fw-bold text-success"><?= $orderableCount ?></div>
        <div class="text-muted small">Orderable</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card text-center p-3">
        <div class="fs-2 fw-bold"><?= $orderCount ?></div>
        <div class="text-muted small">Total Orders</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card text-center p-3">
        <div class="fs-2 fw-bold text-warning"><?= $pendingPayCount ?></div>
        <div class="text-muted small">Pending Payment Review</div>
      </div>
    </div>
  </div>

  <?php if ($orderableCount === 0): ?>
  <!-- §14 Config alert -->
  <div class="alert alert-warning d-flex align-items-start gap-3 mb-4">
    <span style="font-size:1.5rem;">⚠️</span>
    <div>
      <strong>Marketplace not yet open to partners.</strong>
      All products are currently disabled. Enable individual products below, or use the button to enable everything at once.
      <form method="POST" class="mt-2 d-inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="enable_all">
        <button class="btn btn-warning btn-sm" onclick="return confirm('Enable ALL products and make them orderable?')">
          🚀 Enable All Products Now
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Catalogue -->
  <?php foreach ($cats as $cat): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <span style="font-size:1.3rem;"><?= e($cat['icon']) ?></span>
        <strong><?= e($cat['name_en']) ?></strong>
        <span class="badge bg-secondary"><?= (int)$cat['product_count'] ?> products</span>
        <?php if (!$cat['active']): ?><span class="badge bg-danger">Inactive</span><?php endif; ?>
        <?php if (!$cat['orderable']): ?><span class="badge bg-warning text-dark">Not orderable</span><?php endif; ?>
      </div>
      <div class="d-flex gap-2">
        <form method="POST" class="d-inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_category">
          <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
          <input type="hidden" name="field" value="active">
          <input type="hidden" name="value" value="<?= $cat['active'] ? 0 : 1 ?>">
          <button class="btn btn-sm btn-<?= $cat['active'] ? 'outline-danger' : 'outline-success' ?>">
            <?= $cat['active'] ? 'Deactivate' : 'Activate' ?>
          </button>
        </form>
        <form method="POST" class="d-inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_category">
          <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
          <input type="hidden" name="field" value="orderable">
          <input type="hidden" name="value" value="<?= $cat['orderable'] ? 0 : 1 ?>">
          <button class="btn btn-sm btn-<?= $cat['orderable'] ? 'outline-secondary' : 'outline-primary' ?>">
            <?= $cat['orderable'] ? 'Hide from partners' : 'Show to partners' ?>
          </button>
        </form>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Product</th>
            <th>Service Type</th>
            <th>Prices</th>
            <th>Active</th>
            <th>Orderable</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $prod):
            if ($prod['category_id'] != $cat['id']) continue;
            $pPrices = $pricesByProduct[$prod['id']] ?? [];
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($prod['name_en']) ?></div>
              <div class="text-muted small"><?= e($prod['name_fr']) ?></div>
              <div class="text-muted small font-monospace"><?= e($prod['key']) ?></div>
            </td>
            <td><span class="badge bg-secondary"><?= e($prod['service_type']) ?></span></td>
            <td>
              <?php foreach ($pPrices as $pr): ?>
              <div class="d-flex align-items-center gap-2 mb-1">
                <form method="POST" class="d-inline-flex gap-1 align-items-center" style="min-width:0;">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="update_price">
                  <input type="hidden" name="price_id" value="<?= $pr['id'] ?>">
                  <small class="text-muted me-1"><?= e($pr['variant_key'] ?: $pr['price_type']) ?>:</small>
                  <?php if ($pr['price_type'] === 'setup' || $pr['setup_amount'] > 0): ?>
                  <input type="number" name="setup_amount" value="<?= (int)$pr['setup_amount'] ?>"
                    class="form-control form-control-sm" style="width:110px;" placeholder="Setup XAF">
                  <?php else: ?>
                  <input type="hidden" name="setup_amount" value="0">
                  <?php endif; ?>
                  <?php if ($pr['recurring_amount'] > 0 || $pr['price_type'] === 'recurring'): ?>
                  <input type="number" name="recurring_amount" value="<?= (int)$pr['recurring_amount'] ?>"
                    class="form-control form-control-sm" style="width:110px;" placeholder="Recur XAF">
                  <?php else: ?>
                  <input type="hidden" name="recurring_amount" value="0">
                  <?php endif; ?>
                  <button class="btn btn-sm btn-outline-primary py-0">Save</button>
                </form>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_price">
                  <input type="hidden" name="price_id" value="<?= $pr['id'] ?>">
                  <input type="hidden" name="value" value="<?= $pr['active'] ? 0 : 1 ?>">
                  <button class="btn btn-sm btn-<?= $pr['active'] ? 'success' : 'outline-secondary' ?> py-0">
                    <?= $pr['active'] ? '✓' : 'Off' ?>
                  </button>
                </form>
              </div>
              <?php endforeach; ?>
            </td>
            <td>
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_product">
                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                <input type="hidden" name="field" value="active">
                <input type="hidden" name="value" value="<?= $prod['active'] ? 0 : 1 ?>">
                <button class="btn btn-sm btn-<?= $prod['active'] ? 'success' : 'outline-secondary' ?>">
                  <?= $prod['active'] ? 'Yes' : 'No' ?>
                </button>
              </form>
            </td>
            <td>
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_product">
                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                <input type="hidden" name="field" value="orderable">
                <input type="hidden" name="value" value="<?= $prod['orderable'] ? 0 : 1 ?>">
                <button class="btn btn-sm btn-<?= $prod['orderable'] ? 'success' : 'outline-secondary' ?>">
                  <?= $prod['orderable'] ? 'Yes' : 'No' ?>
                </button>
              </form>
            </td>
            <td class="text-muted small">
              <?= e($prod['billing_interval'] ?? '') ?>
              <?php if ($prod['mailbox_capable']): ?><span class="badge bg-info">mailbox</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- §14 Configuration settings -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-bold">⚙️ Marketplace Configuration (§14)</div>
    <div class="card-body">
      <div class="row g-3">

        <!-- Approval expiry -->
        <div class="col-md-6">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_approval_expiry_days">
            <label class="form-label fw-semibold">Owner approval expiry (days)</label>
            <div class="input-group">
              <input type="number" name="setting_value" class="form-control"
                value="<?= e($settings['mp_approval_expiry_days'] ?? '7') ?>" min="1" max="30">
              <button class="btn btn-outline-primary">Save</button>
            </div>
            <div class="form-text">0 = never expires.</div>
          </form>
        </div>

        <!-- Fulfilment SLA -->
        <div class="col-md-6">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_fulfilment_sla_days">
            <label class="form-label fw-semibold">Fulfilment SLA (days)</label>
            <div class="input-group">
              <input type="number" name="setting_value" class="form-control"
                value="<?= e($settings['mp_fulfilment_sla_days'] ?? '5') ?>" min="1" max="60">
              <button class="btn btn-outline-primary">Save</button>
            </div>
            <div class="form-text">Target days from payment confirmed to service delivered.</div>
          </form>
        </div>

        <!-- Payment instructions EN -->
        <div class="col-md-6">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_payment_instruction_en">
            <label class="form-label fw-semibold">Payment instructions (English)</label>
            <textarea name="setting_value" class="form-control" rows="3"><?= e($settings['mp_payment_instruction_en'] ?? '') ?></textarea>
            <button class="btn btn-outline-primary btn-sm mt-2">Save</button>
          </form>
        </div>

        <!-- Payment instructions FR -->
        <div class="col-md-6">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_payment_instruction_fr">
            <label class="form-label fw-semibold">Instructions de paiement (Français)</label>
            <textarea name="setting_value" class="form-control" rows="3"><?= e($settings['mp_payment_instruction_fr'] ?? '') ?></textarea>
            <button class="btn btn-outline-primary btn-sm mt-2">Save</button>
          </form>
        </div>

        <!-- Commission toggle -->
        <div class="col-md-6">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_commission_enabled">
            <label class="form-label fw-semibold">Commission accrual</label>
            <select name="setting_value" class="form-select">
              <option value="0" <?= ($settings['mp_commission_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled (default — no commission until policy approved)</option>
              <option value="1" <?= ($settings['mp_commission_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
            </select>
            <button class="btn btn-outline-primary btn-sm mt-2">Save</button>
            <div class="form-text text-danger">Only enable once commercial terms are confirmed with partners.</div>
          </form>
        </div>

        <!-- Auto-renewal toggle -->
        <div class="col-md-6">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_setting">
            <input type="hidden" name="setting_key" value="mp_auto_renewal_enabled">
            <label class="form-label fw-semibold">Automatic renewal collection</label>
            <select name="setting_value" class="form-select">
              <option value="0" <?= ($settings['mp_auto_renewal_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled (send reminders only)</option>
              <option value="1" <?= ($settings['mp_auto_renewal_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
            </select>
            <button class="btn btn-outline-primary btn-sm mt-2">Save</button>
            <div class="form-text">When disabled, renewal reminders are sent but no charge is initiated automatically.</div>
          </form>
        </div>

      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
