<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $order  = (int)($_POST['sort_order'] ?? 0);
        if ($nameEn) {
            $nameFr = $nameFr ?: $nameEn;
            $slug   = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8','ASCII//TRANSLIT',$nameEn)));
            $slug   = trim($slug, '-');
            $exists = db()->prepare("SELECT id FROM locations WHERE slug=?");
            $exists->execute([$slug]);
            if ($exists->fetch()) $slug .= '-' . time();
            db()->prepare("INSERT INTO locations (name_en,name_fr,slug,region,sort_order) VALUES (?,?,?,?,?)")
                 ->execute([$nameEn, $nameFr, $slug, $region, $order]);
            flash('success', "Location '$nameEn' added.");
        }
    } elseif ($action === 'update') {
        $id     = (int)($_POST['loc_id'] ?? 0);
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $order  = (int)($_POST['sort_order'] ?? 0);
        if ($id && $nameEn) {
            db()->prepare("UPDATE locations SET name_en=?,name_fr=?,region=?,sort_order=? WHERE id=?")
                 ->execute([$nameEn, $nameFr ?: $nameEn, $region, $order, $id]);
            flash('success', 'Location updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['loc_id'] ?? 0);
        $count = db()->prepare("SELECT COUNT(*) FROM listings WHERE location_id=?");
        $count->execute([$id]);
        if ($count->fetchColumn() > 0) {
            flash('error', 'Cannot delete — location has listings assigned to it.');
        } else {
            db()->prepare("DELETE FROM locations WHERE id=?")->execute([$id]);
            flash('success', 'Location deleted.');
        }
    }
    redirect(SITE_URL . '/admin/locations.php');
}

$locs = db()->query("
    SELECT loc.*, COUNT(l.id) AS listing_count
    FROM locations loc
    LEFT JOIN listings l ON l.location_id=loc.id AND l.status='approved'
    GROUP BY loc.id ORDER BY loc.sort_order, loc.name_en
")->fetchAll();

$pageTitle = 'Locations — Admin 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📍 Locations</h1>
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
      <a href="<?= SITE_URL ?>/admin/locations.php" class="filter-tab active">📍 Locations</a>
      <a href="<?= SITE_URL ?>/admin/subscribers.php" class="filter-tab">📬 Newsletter</a>
      <a href="<?= SITE_URL ?>/admin/promos.php" class="filter-tab">🎁 Promo Codes</a>
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
  <!-- ADD NEW LOCATION -->
  <div class="listing-widget" style="margin-bottom:2rem;">
    <h4 style="margin-bottom:1.25rem;">+ Add New Location</h4>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="create">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;align-items:end;">
        <div class="form-group" style="margin-bottom:0;">
          <label>City Name (English) *</label>
          <input type="text" name="name_en" required placeholder="e.g. Kribi">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>City Name (French)</label>
          <input type="text" name="name_fr" placeholder="e.g. Kribi (same if identical)">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Region</label>
          <input type="text" name="region" placeholder="e.g. South Region">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Sort Order</label>
          <input type="number" name="sort_order" value="<?= count($locs) + 1 ?>" min="0">
        </div>
        <button type="submit" class="btn btn-primary" style="align-self:end;">+ Add Location</button>
      </div>
    </form>
  </div>

  <!-- LOCATIONS LIST -->
  <div class="listing-widget">
    <h4 style="margin-bottom:1.25rem;">All Locations (<?= count($locs) ?>)</h4>
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead><tr><th>City (EN)</th><th>City (FR)</th><th>Region</th><th>Slug</th><th>Listings</th><th>Order</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($locs as $loc): ?>
            <tr>
              <td style="color:var(--white);font-weight:500;"><?= e($loc['name_en']) ?></td>
              <td style="color:var(--muted);"><?= e($loc['name_fr']) ?></td>
              <td style="font-size:0.8rem;color:var(--muted-2);"><?= e($loc['region'] ?? '—') ?></td>
              <td style="font-size:0.75rem;color:var(--muted-2);font-family:monospace;"><?= e($loc['slug']) ?></td>
              <td style="text-align:center;color:var(--white);"><?= $loc['listing_count'] ?></td>
              <td style="text-align:center;color:var(--muted);"><?= $loc['sort_order'] ?></td>
              <td>
                <button onclick="editLoc(<?= $loc['id'] ?>, '<?= e(addslashes($loc['name_en'])) ?>', '<?= e(addslashes($loc['name_fr'])) ?>', '<?= e(addslashes($loc['region'] ?? '')) ?>', <?= $loc['sort_order'] ?>)"
                        class="btn btn-primary btn-sm">✏️ Edit</button>
                <?php if ($loc['listing_count'] == 0): ?>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Delete <?= e($loc['name_en']) ?>?')">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="loc_id" value="<?= $loc['id'] ?>">
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
<div id="edit-loc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:500;align-items:center;justify-content:center;">
  <div style="background:#122B1C;border:1px solid var(--border);border-radius:16px;padding:2rem;width:100%;max-width:480px;margin:1rem;">
    <h3 style="font-family:'Fraunces',serif;font-weight:700;margin-bottom:1.5rem;">✏️ Edit Location</h3>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="loc_id" id="edit-loc-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group"><label>English Name *</label><input type="text" name="name_en" id="edit-loc-name-en" required></div>
        <div class="form-group"><label>French Name</label><input type="text" name="name_fr" id="edit-loc-name-fr"></div>
        <div class="form-group"><label>Region</label><input type="text" name="region" id="edit-loc-region"></div>
        <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" id="edit-loc-sort" min="0"></div>
      </div>
      <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
        <button type="submit" class="btn btn-primary" style="flex:1;">Save Changes</button>
        <button type="button" onclick="document.getElementById('edit-loc-modal').style.display='none'" class="btn btn-outline" style="flex:1;">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function editLoc(id, nameEn, nameFr, region, order) {
  document.getElementById('edit-loc-id').value = id;
  document.getElementById('edit-loc-name-en').value = nameEn;
  document.getElementById('edit-loc-name-fr').value = nameFr;
  document.getElementById('edit-loc-region').value = region;
  document.getElementById('edit-loc-sort').value = order;
  document.getElementById('edit-loc-modal').style.display = 'flex';
}
document.getElementById('edit-loc-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
