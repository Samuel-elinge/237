<?php
/**
 * Admin: Manually assign / override subscription plans for listings.
 * Monetisation → Assign Subscription
 * v4 — partner-helpers fix
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Forbidden'); }

$errors  = [];
$success = '';

// ── Handle form submission ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action     = $_POST['action']      ?? '';
    $listingId  = (int)($_POST['listing_id'] ?? 0);
    $planId     = (int)($_POST['plan_id']    ?? 0);
    $months     = (int)($_POST['months']     ?? 1);
    $adminNote  = trim($_POST['admin_note']  ?? '');

    if ($action === 'assign' && $listingId && $planId) {
        // Fetch listing owner
        $lst = db()->prepare("SELECT id, user_id, title AS business_name FROM listings WHERE id = ?");
        $lst->execute([$listingId]);
        $listing = $lst->fetch();

        if (!$listing) {
            $errors[] = 'Listing not found.';
        } else {
            // Fetch plan
            $pl = db()->prepare("SELECT * FROM subscription_plans WHERE id = ? AND active = 1");
            $pl->execute([$planId]);
            $plan = $pl->fetch();

            if (!$plan) {
                $errors[] = 'Plan not found or inactive.';
            } else {
                $startsAt = date('Y-m-d H:i:s');
                // Free plan = no expiry; paid = months from now
                $endsAt = ($plan['is_free'] || $months == 0)
                    ? null
                    : date('Y-m-d H:i:s', strtotime("+{$months} months"));

                // Cancel any existing active/pending subscriptions for this listing
                db()->prepare("
                    UPDATE business_subscriptions
                    SET status = 'cancelled', cancelled_at = NOW(),
                        cancellation_reason = 'Superseded by admin manual assignment'
                    WHERE listing_id = ? AND status IN ('active','pending_payment','past_due')
                ")->execute([$listingId]);

                // Insert new active subscription
                $ins = db()->prepare("
                    INSERT INTO business_subscriptions
                        (listing_id, user_id, plan_id, status, starts_at, ends_at, admin_note, created_at, updated_at)
                    VALUES (?, ?, ?, 'active', ?, ?, ?, NOW(), NOW())
                ");
                $ins->execute([
                    $listing['id'],
                    $listing['user_id'],
                    $planId,
                    $startsAt,
                    $endsAt,
                    $adminNote ?: 'Manually assigned by admin',
                ]);

                $success = 'Subscription assigned: <strong>' . e($plan['name_en']) . '</strong> → '
                         . e($listing['business_name'])
                         . ($endsAt ? ' (expires ' . date('d M Y', strtotime($endsAt)) . ')' : ' (no expiry)');
            }
        }
    }

    if ($action === 'cancel' && $listingId) {
        $rows = db()->prepare("
            UPDATE business_subscriptions
            SET status = 'cancelled', cancelled_at = NOW(),
                cancellation_reason = ?
            WHERE listing_id = ? AND status IN ('active','pending_payment','past_due')
        ");
        $rows->execute([$adminNote ?: 'Cancelled by admin', $listingId]);
        $success = 'Subscription(s) cancelled for listing #' . $listingId . '.';
    }
}

// ── Fetch all plans ──────────────────────────────────────────────────────────
$plansResult = db()->query("SELECT * FROM subscription_plans WHERE active = 1 ORDER BY sort_order ASC, id ASC");
$plans = $plansResult ? $plansResult->fetchAll() : [];

// ── Fetch listings with their current active sub ─────────────────────────────
$listingsResult = db()->query("
    SELECT l.id, l.title AS business_name, l.status AS listing_status,
           u.email AS owner_email,
           bs.id   AS sub_id,
           bs.status AS sub_status,
           bs.ends_at AS sub_ends,
           sp.name_en AS plan_name,
           sp.slug    AS plan_slug,
           sp.highlight_color AS plan_color
    FROM listings l
    LEFT JOIN users u ON u.id = l.user_id
    LEFT JOIN business_subscriptions bs
          ON bs.listing_id = l.id
         AND bs.status = 'active'
         AND (bs.ends_at IS NULL OR bs.ends_at > NOW())
    LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
    ORDER BY l.id ASC
");
$listings = $listingsResult ? $listingsResult->fetchAll() : [];

$planMap = [];
foreach ($plans as $p) { $planMap[$p['id']] = $p; }

// Debug: capture query errors to display as admin notices
$_dbErrors = [];
if (!$plansResult)   { $_dbErrors[] = 'Plans query failed: ' . implode(' ', db()->errorInfo()); }
if (!$listingsResult){ $_dbErrors[] = 'Listings query failed: ' . implode(' ', db()->errorInfo()); }

$pageTitle = 'Assign Subscription — Admin';
require_once __DIR__ . '/../includes/admin-header.php';
?>
<style>
.plan-badge{display:inline-block;padding:.15rem .55rem;border-radius:99px;font-size:.72rem;font-weight:700;background:rgba(255,255,255,.08);color:#ccc;}
.plan-badge.premium{background:rgba(245,200,66,.15);color:#F5C842;}
.plan-badge.growth{background:rgba(0,168,120,.15);color:#00A878;}
.plan-badge.free{background:rgba(107,114,128,.15);color:#9ca3af;}
.assign-form{background:var(--card-bg,rgba(255,255,255,.04));border:1px solid var(--border,rgba(255,255,255,.08));border-radius:12px;padding:1.5rem;margin-bottom:2rem;}
.assign-form h3{margin:0 0 1.25rem;}
.form-row{display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;}
.form-group{display:flex;flex-direction:column;gap:.35rem;flex:1;min-width:180px;}
.form-group label{font-size:.8rem;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
.form-group select,.form-group input,.form-group textarea{
    background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);
    border-radius:8px;padding:.55rem .75rem;color:#e8f5ee;font-size:.9rem;width:100%;}
.btn-assign{background:#00A878;color:#0A1A0F;border:none;border-radius:8px;padding:.6rem 1.4rem;font-weight:700;cursor:pointer;white-space:nowrap;}
.btn-assign:hover{background:#00c98f;}
.alert-success{background:rgba(0,168,120,.12);border:1px solid rgba(0,168,120,.3);color:#00A878;padding:.75rem 1rem;border-radius:8px;margin-bottom:1.5rem;}
.alert-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444;padding:.75rem 1rem;border-radius:8px;margin-bottom:1.5rem;}
table{width:100%;border-collapse:collapse;font-size:.875rem;}
th{text-align:left;padding:.6rem .75rem;color:#9ca3af;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid rgba(255,255,255,.08);}
td{padding:.65rem .75rem;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle;}
tr:hover td{background:rgba(255,255,255,.025);}
.quick-assign-btn{background:rgba(0,168,120,.12);color:#00A878;border:1px solid rgba(0,168,120,.25);border-radius:6px;padding:.25rem .6rem;font-size:.75rem;font-weight:600;cursor:pointer;}
.quick-assign-btn:hover{background:rgba(0,168,120,.22);}
</style>
<div class="admin-content" style="max-width:1100px;margin:0 auto;padding:2rem 1.25rem;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.75rem;">
    <div>
      <h2 style="margin:0;">📋 Assign Subscription</h2>
      <p style="margin:.3rem 0 0;color:#9ca3af;font-size:.9rem;">Manually assign or override a subscription plan for any listing.</p>
    </div>
    <a href="<?= SITE_URL ?>/admin/subscriptions.php" class="btn btn-secondary btn-sm">← Subscription Orders</a>
  </div>

  <?php if ($success): ?>
  <div class="alert-success"><?= $success ?></div>
  <?php endif; ?>
  <?php foreach ($errors as $err): ?>
  <div class="alert-error"><?= e($err) ?></div>
  <?php endforeach; ?>
  <?php foreach ($_dbErrors as $dbErr): ?>
  <div class="alert-error">⚠️ DB: <?= e($dbErr) ?></div>
  <?php endforeach; ?>

  <!-- Manual assignment form -->
  <div class="assign-form">
    <h3>Assign / Override Plan</h3>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="assign">
      <div class="form-row">
        <div class="form-group" style="flex:2;min-width:220px;">
          <label>Listing</label>
          <select name="listing_id" required>
            <option value="">— select listing —</option>
            <?php foreach ($listings as $l): ?>
            <option value="<?= $l['id'] ?>"
              <?= isset($_POST['listing_id']) && (int)$_POST['listing_id'] === (int)$l['id'] ? 'selected' : '' ?>>
              #<?= $l['id'] ?> — <?= e($l['business_name']) ?> (<?= e($l['owner_email']) ?>)
              <?= $l['plan_name'] ? '[' . $l['plan_name'] . ']' : '[Free]' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Plan</label>
          <select name="plan_id" required>
            <option value="">— select plan —</option>
            <?php foreach ($plans as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name_en']) ?> (<?= $p['price_xaf'] ? number_format($p['price_xaf']) . ' XAF/' . ($p['billing_interval'] === 'annual' ? 'yr' : ($p['billing_interval'] === 'one_off' ? 'once' : 'mo')) : 'Free' ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="max-width:110px;">
          <label>Duration (months)</label>
          <input type="number" name="months" value="12" min="1" max="120">
          <span style="font-size:.72rem;color:#6b7280;">0 = no expiry</span>
        </div>
        <div class="form-group" style="flex:2;">
          <label>Admin note (optional)</label>
          <input type="text" name="admin_note" placeholder="e.g. Founding partner — complimentary year">
        </div>
        <div>
          <button type="submit" class="btn-assign">✅ Assign Plan</button>
        </div>
      </div>
    </form>
  </div>

  <!-- Listings table with current plans -->
  <h3 style="margin-bottom:1rem;">All Listings &amp; Current Plans</h3>
  <div style="overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Business Name</th>
        <th>Owner</th>
        <th>Listing Status</th>
        <th>Current Plan</th>
        <th>Expires</th>
        <th>Quick Assign</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($listings as $l):
        $slug  = $l['plan_slug'] ?? 'free';
        $badge = in_array($slug, ['premium','growth','free']) ? $slug : '';
        $planLabel = $l['plan_name'] ?? 'Free Listing';
      ?>
      <tr>
        <td style="color:#6b7280;"><?= $l['id'] ?></td>
        <td><strong><?= e($l['business_name']) ?></strong></td>
        <td style="color:#9ca3af;font-size:.8rem;"><?= e($l['owner_email']) ?></td>
        <td><span style="font-size:.75rem;"><?= e($l['listing_status']) ?></span></td>
        <td>
          <?php if ($l['plan_color']): ?>
            <span class="plan-badge <?= $badge ?>" style="background:<?= e($l['plan_color']) ?>22;color:<?= e($l['plan_color']) ?>;"><?= e($planLabel) ?></span>
          <?php else: ?>
            <span class="plan-badge free"><?= e($planLabel) ?></span>
          <?php endif; ?>
        </td>
        <td style="color:#9ca3af;font-size:.8rem;">
          <?= $l['sub_ends'] ? date('d M Y', strtotime($l['sub_ends'])) : ($l['sub_id'] ? '∞ No expiry' : '—') ?>
        </td>
        <td>
          <?php foreach ($plans as $p): if ($p['is_free']) continue; ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action"     value="assign">
            <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
            <input type="hidden" name="plan_id"    value="<?= $p['id'] ?>">
            <input type="hidden" name="months"     value="12">
            <input type="hidden" name="admin_note" value="Quick-assigned by admin">
            <button type="submit" class="quick-assign-btn"
              style="<?= $p['highlight_color'] ? 'color:'.e($p['highlight_color']).';border-color:'.e($p['highlight_color']).'44;background:'.e($p['highlight_color']).'11;' : '' ?>">
              <?= e($p['name_en']) ?>
            </button>
          </form>
          <?php endforeach; ?>
          <?php if ($l['sub_id']): ?>
          <form method="POST" style="display:inline;margin-left:.25rem;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action"     value="cancel">
            <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
            <input type="hidden" name="admin_note" value="Cancelled by admin">
            <button type="submit" class="quick-assign-btn"
              style="color:#ef4444;border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08);">
              Cancel
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
