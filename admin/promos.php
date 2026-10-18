<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code        = strtoupper(trim($_POST['code'] ?? ''));
        $desc        = trim($_POST['description'] ?? '');
        $pct         = (int)($_POST['discount_pct'] ?? 0);
        $xaf         = (int)($_POST['discount_xaf'] ?? 0);
        $applies     = $_POST['applies_to'] ?? 'both';
        $maxUses     = (int)($_POST['max_uses'] ?? 0) ?: null;
        $partner     = trim($_POST['partner_name'] ?? '');
        $expires     = trim($_POST['expires_at'] ?? '') ?: null;

        if ($code && ($pct > 0 || $xaf > 0)) {
            db()->prepare("INSERT INTO promo_codes (code,description,discount_pct,discount_xaf,applies_to,max_uses,partner_name,expires_at)
                           VALUES (?,?,?,?,?,?,?,?)")
                 ->execute([$code,$desc,$pct,$xaf,$applies,$maxUses,$partner,$expires]);
            flash('success', 'Promo code created: ' . $code);
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['promo_id'] ?? 0);
        db()->prepare("UPDATE promo_codes SET active = !active WHERE id=?")->execute([$id]);
    } elseif ($action === 'delete') {
        $id = (int)($_POST['promo_id'] ?? 0);
        db()->prepare("DELETE FROM promo_codes WHERE id=?")->execute([$id]);
    }
    redirect(SITE_URL . '/admin/promos.php');
}

$promos = db()->query("SELECT p.*, (SELECT COUNT(*) FROM promo_uses WHERE code_id=p.id) AS actual_uses FROM promo_codes p ORDER BY p.created_at DESC")->fetchAll();

$pageTitle = 'Promo Codes — Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">🎁 Promo Codes</h1>
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
      <a href="<?= SITE_URL ?>/admin/promos.php" class="filter-tab active">🎁 Promo Codes</a>
      <a href="<?= SITE_URL ?>/admin/packages.php" class="filter-tab">💳 Packages</a>
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
  <!-- CREATE FORM -->
  <div class="listing-widget" style="margin-bottom:2rem;">
    <h4 style="margin-bottom:1.25rem;">+ Create Promo Code</h4>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="create">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div class="form-group"><label>Code *</label><input type="text" name="code" required placeholder="e.g. TAHIRIH10" style="text-transform:uppercase;"></div>
        <div class="form-group"><label>Discount % (or XAF)</label><input type="number" name="discount_pct" min="0" max="100" placeholder="10" value="0"></div>
        <div class="form-group"><label>Discount XAF (flat)</label><input type="number" name="discount_xaf" min="0" placeholder="0" value="0"></div>
        <div class="form-group"><label>Applies To</label>
          <select name="applies_to">
            <option value="both">Both</option>
            <option value="featured">Featured Listings</option>
            <option value="services">Services</option>
          </select>
        </div>
        <div class="form-group"><label>Max Uses</label><input type="number" name="max_uses" min="0" placeholder="Unlimited"></div>
        <div class="form-group"><label>Partner Name</label><input type="text" name="partner_name" placeholder="e.g. The Tahirih Effect"></div>
        <div class="form-group"><label>Expires At</label><input type="datetime-local" name="expires_at"></div>
        <div class="form-group"><label>Description</label><input type="text" name="description" placeholder="Internal note"></div>
      </div>
      <button type="submit" class="btn btn-primary">+ Create Code</button>
    </form>
  </div>

  <!-- PROMO LIST -->
  <div class="listing-widget"><div style="overflow-x:auto;">
    <table class="data-table">
      <thead><tr><th>Code</th><th>Discount</th><th>Applies To</th><th>Uses</th><th>Partner</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($promos as $p): ?>
          <tr>
            <td style="font-family:'Fraunces',serif;font-weight:700;color:var(--yellow);font-size:1rem;"><?= e($p['code']) ?></td>
            <td>
              <?php if ($p['discount_pct']): ?><span class="badge badge-approved"><?= $p['discount_pct'] ?>%</span><?php endif; ?>
              <?php if ($p['discount_xaf']): ?><span class="badge badge-pending"><?= number_format($p['discount_xaf']) ?> XAF</span><?php endif; ?>
            </td>
            <td style="font-size:0.82rem;color:var(--muted);"><?= ucfirst($p['applies_to']) ?></td>
            <td style="font-size:0.875rem;">
              <?= $p['actual_uses'] ?>
              <?php if ($p['max_uses']): ?> / <?= $p['max_uses'] ?><?php endif; ?>
            </td>
            <td style="font-size:0.82rem;color:var(--muted);"><?= e($p['partner_name'] ?? '—') ?></td>
            <td style="font-size:0.78rem;color:var(--muted-2);"><?= $p['expires_at'] ? date('d M Y', strtotime($p['expires_at'])) : '∞' ?></td>
            <td><span class="badge badge-<?= $p['active']?'approved':'rejected' ?>"><?= $p['active']?'Active':'Inactive' ?></span></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:0.25rem;">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="promo_id" value="<?= $p['id'] ?>">
                <button name="action" value="toggle" class="btn btn-outline btn-sm"><?= $p['active']?'Disable':'Enable' ?></button>
                <button name="action" value="delete" class="btn btn-outline btn-sm" style="color:#ff6b6b;border-color:rgba(230,50,50,0.3);" onclick="return confirm('Delete code <?= e($p['code']) ?>?')">✗</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
