<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireAdmin();

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

/* ── WRITE ACTIONS ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'save') {
        $slug            = trim($_POST['slug'] ?? '');
        $name_en         = trim($_POST['name_en'] ?? '');
        $name_fr         = trim($_POST['name_fr'] ?? '');
        $desc_en         = trim($_POST['description_en'] ?? '');
        $desc_fr         = trim($_POST['description_fr'] ?? '');
        $price_xaf       = max(0, (int)($_POST['price_xaf'] ?? 0));
        $interval        = $_POST['billing_interval'] ?? 'monthly';
        $duration        = max(0, (int)($_POST['duration_days'] ?? 30));
        $max_offers      = max(0, (int)($_POST['max_offers'] ?? 2));
        $max_events      = max(0, (int)($_POST['max_events'] ?? 1));
        $max_products    = max(0, (int)($_POST['max_products'] ?? 0));
        $analytics       = $_POST['analytics_level'] ?? 'basic';
        $sort            = (int)($_POST['sort_order'] ?? 0);
        $highlight       = trim($_POST['highlight_color'] ?? '') ?: null;
        $badge_en        = trim($_POST['badge_label_en'] ?? '') ?: null;
        $badge_fr        = trim($_POST['badge_label_fr'] ?? '') ?: null;
        $is_free         = isset($_POST['is_free']) ? 1 : 0;
        $active          = isset($_POST['active']) ? 1 : 0;
        $feat_eligible   = isset($_POST['featured_eligible']) ? 1 : 0;
        $promo_eligible  = isset($_POST['promotions_eligible']) ? 1 : 0;
        $verified_badge  = isset($_POST['verified_badge']) ? 1 : 0;
        $prio_support    = isset($_POST['priority_support']) ? 1 : 0;
        $premium_star    = isset($_POST['premium_star']) ? 1 : 0;
        $search_priority = max(0, (int)($_POST['search_priority'] ?? 0));
        $addon_eligible  = isset($_POST['addon_eligible']) ? 1 : 0;

        if (!$slug || !$name_en || !$name_fr) {
            flash('error', 'Slug, English name and French name are required.');
        } else {
            $adminId = currentUser()['id'];
            if ($id) {
                // Fetch current values for audit log
                $old = db()->prepare("SELECT * FROM subscription_plans WHERE id=?");
                $old->execute([$id]);
                $oldPlan = $old->fetch();

                $st = db()->prepare("UPDATE subscription_plans SET
                    slug=?, name_en=?, name_fr=?, description_en=?, description_fr=?,
                    price_xaf=?, billing_interval=?, duration_days=?,
                    max_offers=?, max_events=?, max_products=?, analytics_level=?,
                    featured_eligible=?, promotions_eligible=?, verified_badge=?, priority_support=?,
                    premium_star=?, search_priority=?, addon_eligible=?,
                    is_free=?, active=?, sort_order=?, highlight_color=?,
                    badge_label_en=?, badge_label_fr=?
                    WHERE id=?");
                $st->execute([$slug, $name_en, $name_fr, $desc_en, $desc_fr,
                    $price_xaf, $interval, $duration, $max_offers, $max_events, $max_products, $analytics,
                    $feat_eligible, $promo_eligible, $verified_badge, $prio_support,
                    $premium_star, $search_priority, $addon_eligible,
                    $is_free, $active, $sort, $highlight, $badge_en, $badge_fr, $id]);

                // Audit log for financially/entitlement-sensitive fields
                $auditFields = [
                    'price_xaf','max_offers','max_events','max_products',
                    'search_priority','premium_star','addon_eligible','active'
                ];
                $newVals = compact('price_xaf','max_offers','max_events','max_products',
                    'search_priority','premium_star','addon_eligible','active');
                $logSt = db()->prepare("INSERT INTO plan_audit_log (plan_id,admin_id,field_name,old_value,new_value) VALUES (?,?,?,?,?)");
                foreach ($auditFields as $f) {
                    if ((string)($oldPlan[$f] ?? '') !== (string)($newVals[$f] ?? '')) {
                        $logSt->execute([$id, $adminId, $f, $oldPlan[$f], $newVals[$f]]);
                    }
                }

                flash('success', 'Plan updated.');
            } else {
                $st = db()->prepare("INSERT INTO subscription_plans
                    (slug, name_en, name_fr, description_en, description_fr,
                     price_xaf, billing_interval, duration_days,
                     max_offers, max_events, max_products, analytics_level,
                     featured_eligible, promotions_eligible, verified_badge, priority_support,
                     premium_star, search_priority, addon_eligible,
                     is_free, active, sort_order, highlight_color, badge_label_en, badge_label_fr)
                    VALUES (?,?,?,?,?, ?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?,?,?)");
                $st->execute([$slug, $name_en, $name_fr, $desc_en, $desc_fr,
                    $price_xaf, $interval, $duration, $max_offers, $max_events, $max_products, $analytics,
                    $feat_eligible, $promo_eligible, $verified_badge, $prio_support,
                    $premium_star, $search_priority, $addon_eligible,
                    $is_free, $active, $sort, $highlight, $badge_en, $badge_fr]);
                flash('success', 'Plan created.');
            }
        }
        redirect(SITE_URL . '/admin/subscription-plans.php');
    }

    if ($action === 'toggle' && $id) {
        db()->prepare("UPDATE subscription_plans SET active = 1-active WHERE id=?")->execute([$id]);
        flash('success', 'Plan status toggled.');
        redirect(SITE_URL . '/admin/subscription-plans.php');
    }

    if ($action === 'delete' && $id) {
        // Block if any active subscriptions reference this plan
        $count = db()->prepare("SELECT COUNT(*) FROM business_subscriptions WHERE plan_id=? AND status='active'");
        $count->execute([$id]);
        if ($count->fetchColumn() > 0) {
            flash('error', 'Cannot delete — active subscriptions use this plan.');
        } else {
            db()->prepare("DELETE FROM subscription_plans WHERE id=?")->execute([$id]);
            flash('success', 'Plan deleted.');
        }
        redirect(SITE_URL . '/admin/subscription-plans.php');
    }
}

/* ── READ ──────────────────────────────────────────────────── */
$plans = db()->query("SELECT sp.*,
    (SELECT COUNT(*) FROM business_subscriptions bs WHERE bs.plan_id=sp.id AND bs.status='active') AS active_subs
    FROM subscription_plans sp ORDER BY sp.sort_order, sp.id")->fetchAll();

// Edit mode
$editing = null;
if (isset($_GET['edit']) && (int)$_GET['edit']) {
    $st = db()->prepare("SELECT * FROM subscription_plans WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $editing = $st->fetch();
}

$pageTitle = 'Subscription Plans — Admin';
require_once __DIR__ . '/../includes/admin-header.php';
?>
<style>
.plan-card{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.plan-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
.plan-body{padding:1.25rem 1.5rem;}
.plan-name{font-weight:700;font-size:1.05rem;font-family:'Fraunces',serif;}
.plan-price{font-size:1.25rem;font-weight:700;color:var(--accent);}
.badge-pill{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
.badge-active{background:#dcfce7;color:#15803d;}
.badge-inactive{background:#f3f4f6;color:#6b7280;}
.badge-free{background:#eff6ff;color:#1d4ed8;}
.spec-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.5rem .75rem;margin:.75rem 0;}
.spec-item{font-size:.82rem;color:var(--muted);}
.spec-item strong{color:var(--fg);}
.form-section{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;}
.form-section h3{margin:0 0 1.25rem;font-family:'Fraunces',serif;font-size:1.05rem;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;}
.form-row-3{grid-template-columns:1fr 1fr 1fr;}
@media(max-width:600px){.form-row,.form-row-3{grid-template-columns:1fr;}}
.check-row{display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;}
.check-item{display:flex;align-items:center;gap:.4rem;font-size:.88rem;}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin">Admin</a> › Subscription Plans</nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.4rem);">
      📦 Subscription Plans
    </h1>
    <p style="color:var(--muted);">Define the tiers businesses can subscribe to.</p>
  </div>
</div>

<section class="page-section">
<div class="container">

<?php if ($flash = flash()): ?>
  <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<!-- PLAN LIST -->
<?php if ($plans): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem;margin-bottom:2rem;">
  <?php foreach ($plans as $p): ?>
  <div class="plan-card">
    <div class="plan-header">
      <?php if ($p['highlight_color']): ?>
        <div style="width:10px;height:40px;border-radius:4px;background:<?= e($p['highlight_color']) ?>;flex-shrink:0;"></div>
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div class="plan-name"><?= e($p['name_en']) ?> <span style="color:var(--muted);font-weight:400;font-size:.85rem;">/<?= e($p['name_fr']) ?></span></div>
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:.25rem;">
          <span class="badge-pill <?= $p['active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $p['active'] ? 'Active' : 'Inactive' ?></span>
          <?php if ($p['is_free']): ?><span class="badge-pill badge-free">Free</span><?php endif; ?>
          <?php if ($p['badge_label_en']): ?><span class="badge-pill" style="background:#fef3c7;color:#92400e;"><?= e($p['badge_label_en']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="plan-price"><?= $p['is_free'] ? 'Free' : number_format($p['price_xaf']) . ' XAF' ?></div>
    </div>
    <div class="plan-body">
      <div class="spec-grid">
        <div class="spec-item">Slug: <strong><?= e($p['slug']) ?></strong></div>
        <div class="spec-item">Interval: <strong><?= e($p['billing_interval']) ?></strong></div>
        <div class="spec-item">Duration: <strong><?= $p['duration_days'] ?: '∞' ?> days</strong></div>
        <div class="spec-item">Offers: <strong><?= $p['max_offers'] ?></strong></div>
        <div class="spec-item">Events: <strong><?= $p['max_events'] ?></strong></div>
        <div class="spec-item">Products: <strong><?= $p['max_products'] ?? 0 ?></strong></div>
        <div class="spec-item">Analytics: <strong><?= e($p['analytics_level']) ?></strong></div>
        <div class="spec-item">Search priority: <strong><?= $p['search_priority'] ?? 0 ?></strong></div>
        <div class="spec-item">Active subs: <strong><?= $p['active_subs'] ?></strong></div>
        <div class="spec-item">Sort: <strong><?= $p['sort_order'] ?></strong></div>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin:.75rem 0;">
        <?php
        $flags = [];
        if ($p['premium_star'] ?? 0)    $flags[] = '⭐ Premium star';
        if ($p['featured_eligible'])   $flags[] = '🔝 Featured eligible';
        if ($p['promotions_eligible']) $flags[] = '📣 Promos eligible';
        if ($p['verified_badge'])      $flags[] = '✓ Verified badge';
        if ($p['priority_support'])    $flags[] = '🎧 Priority support';
        if ($p['addon_eligible'] ?? 0) $flags[] = '🛒 Add-ons allowed';
        foreach ($flags as $f):
        ?><span style="font-size:.78rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:2px 8px;"><?= $f ?></span><?php endforeach; ?>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem;">
        <a href="?edit=<?= $p['id'] ?>" class="btn btn-secondary" style="font-size:.82rem;padding:.35rem .8rem;">Edit</a>
        <form method="post" style="display:inline;">
          <?= csrf() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= $p['id'] ?>">
          <button class="btn btn-secondary" style="font-size:.82rem;padding:.35rem .8rem;"><?= $p['active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
        <?php if ($p['active_subs'] == 0): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this plan?');">
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
  <div style="font-size:2.5rem;margin-bottom:.75rem;">📦</div>
  <p>No subscription plans yet. Create your first plan below.</p>
</div>
<?php endif; ?>

<!-- CREATE / EDIT FORM -->
<div class="form-section">
  <h3><?= $editing ? 'Edit Plan: ' . e($editing['name_en']) : 'Create New Plan' ?></h3>
  <form method="post">
    <?= csrf() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>

    <div class="form-row">
      <div>
        <label>Slug <span style="color:red">*</span></label>
        <input type="text" name="slug" class="form-input" required
          value="<?= e($editing['slug'] ?? '') ?>" placeholder="e.g. growth">
        <small style="color:var(--muted);">URL-safe, lowercase, unique. Used in code.</small>
      </div>
      <div>
        <label>Sort Order</label>
        <input type="number" name="sort_order" class="form-input" value="<?= $editing['sort_order'] ?? 0 ?>">
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
        <label>Description (English)</label>
        <textarea name="description_en" class="form-input" rows="3"><?= e($editing['description_en'] ?? '') ?></textarea>
      </div>
      <div>
        <label>Description (French)</label>
        <textarea name="description_fr" class="form-input" rows="3"><?= e($editing['description_fr'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="form-row form-row-3">
      <div>
        <label>Price (XAF)</label>
        <input type="number" name="price_xaf" class="form-input" min="0" value="<?= $editing['price_xaf'] ?? 0 ?>">
        <small style="color:var(--muted);">0 for free plans</small>
      </div>
      <div>
        <label>Billing Interval</label>
        <select name="billing_interval" class="form-input">
          <?php foreach (['monthly','annual','one_off'] as $opt): ?>
          <option value="<?= $opt ?>" <?= ($editing['billing_interval'] ?? 'monthly') === $opt ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$opt)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Duration (days)</label>
        <input type="number" name="duration_days" class="form-input" min="0" value="<?= $editing['duration_days'] ?? 30 ?>">
        <small style="color:var(--muted);">0 = unlimited</small>
      </div>
    </div>

    <div class="form-row form-row-3">
      <div>
        <label>Max Active Offers</label>
        <input type="number" name="max_offers" class="form-input" min="0" value="<?= $editing['max_offers'] ?? 2 ?>">
        <small style="color:var(--muted);">0 = unlimited</small>
      </div>
      <div>
        <label>Max Active Events</label>
        <input type="number" name="max_events" class="form-input" min="0" value="<?= $editing['max_events'] ?? 1 ?>">
        <small style="color:var(--muted);">0 = unlimited</small>
      </div>
      <div>
        <label>Max Published Products</label>
        <input type="number" name="max_products" class="form-input" min="0" value="<?= $editing['max_products'] ?? 0 ?>">
        <small style="color:var(--muted);">0 = unlimited</small>
      </div>
    </div>

    <div class="form-row form-row-3">
      <div>
        <label>Analytics Level</label>
        <select name="analytics_level" class="form-input">
          <?php foreach (['basic','enhanced','advanced'] as $opt): ?>
          <option value="<?= $opt ?>" <?= ($editing['analytics_level'] ?? 'basic') === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Search Priority Weight</label>
        <input type="number" name="search_priority" class="form-input" min="0" value="<?= $editing['search_priority'] ?? 0 ?>">
        <small style="color:var(--muted);">Higher = appears first (Free=0, Growth=1, Premium=2)</small>
      </div>
      <div style="display:flex;align-items:center;padding-top:1.4rem;">
        <!-- spacer -->
      </div>
    </div>

    <div class="form-row">
      <div>
        <label>Highlight Colour (hex, optional)</label>
        <input type="text" name="highlight_color" class="form-input" maxlength="20"
          value="<?= e($editing['highlight_color'] ?? '') ?>" placeholder="#00A878">
      </div>
      <div>
        <label>Badge Label (EN / FR, optional)</label>
        <div style="display:flex;gap:.5rem;">
          <input type="text" name="badge_label_en" class="form-input" value="<?= e($editing['badge_label_en'] ?? '') ?>" placeholder="Most Popular">
          <input type="text" name="badge_label_fr" class="form-input" value="<?= e($editing['badge_label_fr'] ?? '') ?>" placeholder="Le Plus Populaire">
        </div>
      </div>
    </div>

    <div class="check-row" style="margin-bottom:1.25rem;">
      <label class="check-item"><input type="checkbox" name="premium_star" <?= ($editing['premium_star'] ?? 0) ? 'checked' : '' ?>> ⭐ Golden Premium star badge</label>
      <label class="check-item"><input type="checkbox" name="addon_eligible" <?= ($editing['addon_eligible'] ?? 0) ? 'checked' : '' ?>> 🛒 Add-ons available</label>
      <label class="check-item"><input type="checkbox" name="is_free" <?= ($editing['is_free'] ?? 0) ? 'checked' : '' ?>> Is free plan</label>
      <label class="check-item"><input type="checkbox" name="featured_eligible" <?= ($editing['featured_eligible'] ?? 0) ? 'checked' : '' ?>> Featured listing eligible</label>
      <label class="check-item"><input type="checkbox" name="promotions_eligible" <?= ($editing['promotions_eligible'] ?? 0) ? 'checked' : '' ?>> Promotions eligible</label>
      <label class="check-item"><input type="checkbox" name="verified_badge" <?= ($editing['verified_badge'] ?? 0) ? 'checked' : '' ?>> Verified badge</label>
      <label class="check-item"><input type="checkbox" name="priority_support" <?= ($editing['priority_support'] ?? 0) ? 'checked' : '' ?>> Priority support</label>
      <label class="check-item"><input type="checkbox" name="active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>> Active (visible to businesses)</label>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Update Plan' : 'Create Plan' ?></button>
      <?php if ($editing): ?><a href="?" class="btn btn-secondary">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

</div><!-- /container -->
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
