<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

// Counts
$pending  = db()->query("SELECT COUNT(*) FROM listings WHERE status='pending'")->fetchColumn();
$approved = db()->query("SELECT COUNT(*) FROM listings WHERE status='approved'")->fetchColumn();
$users    = db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
$reviews  = db()->query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn();

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $listingId = (int)($_POST['listing_id'] ?? 0);
    $action    = $_POST['action'] ?? '';
    if ($listingId && in_array($action, ['approve','reject'])) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        db()->prepare("UPDATE listings SET status=? WHERE id=?")->execute([$status, $listingId]);
        flash('success', 'Listing ' . $status . '.');
        redirect(SITE_URL . '/admin');
    }
}

// Pending listings
$pendingListings = db()->query("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon, loc.name_en AS loc_en,
           u.name AS user_name, u.email AS user_email
    FROM listings l
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    LEFT JOIN users u ON u.id = l.user_id
    WHERE l.status = 'pending'
    ORDER BY l.created_at ASC
")->fetchAll();

// Recent approved
$recentListings = db()->query("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon, loc.name_en AS loc_en
    FROM listings l
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    WHERE l.status = 'approved'
    ORDER BY l.updated_at DESC LIMIT 10
")->fetchAll();

$pageTitle = 'Admin Panel — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">🔧 Admin Panel</h1>
  </div>
</div>

<section class="page-section">
  <div class="container">

    <!-- Stats -->
    <div class="dash-stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:2.5rem;">
      <div class="dash-stat"><strong style="color:var(--yellow);"><?= $pending ?></strong><span>Pending Review</span></div>
      <div class="dash-stat"><strong><?= $approved ?></strong><span>Published</span></div>
      <div class="dash-stat"><strong><?= $users ?></strong><span>Users</span></div>
      <div class="dash-stat"><strong style="color:var(--yellow);"><?= $reviews ?></strong><span>Pending Reviews</span></div>
    </div>

    <!-- Admin nav -->
    <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/admin/" class="filter-tab active">📋 Listings</a>
      <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
      <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
      <a href="<?= SITE_URL ?>/admin/leads.php" class="filter-tab">🌐 Website Leads</a>
      <a href="<?= SITE_URL ?>/admin/enquiries.php" class="filter-tab">📬 Enquiries</a>
      <a href="<?= SITE_URL ?>/admin/claims.php" class="filter-tab">🏢 Claims</a>
      <a href="<?= SITE_URL ?>/admin/reviews.php" class="filter-tab">⭐ Reviews</a>
      <a href="<?= SITE_URL ?>/admin/categories.php" class="filter-tab">📂 Categories</a>
      <a href="<?= SITE_URL ?>/admin/locations.php" class="filter-tab">📍 Locations</a>
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
    <!-- PENDING LISTINGS -->
    <?php if ($pendingListings): ?>
      <div class="listing-widget" style="margin-bottom:2rem;">
        <h4 style="margin-bottom:1rem;">⏳ Pending Approval (<?= count($pendingListings) ?>)</h4>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Business</th><th>Category</th><th>City</th><th>Submitted By</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($pendingListings as $l): ?>
                <tr>
                  <td>
                    <div style="color:var(--white);font-weight:500;"><?= $l['cat_icon'] ?> <?= e($l['title']) ?></div>
                    <?php if ($l['phone']): ?><div style="font-size:0.75rem;color:var(--muted-2);"><?= e($l['phone']) ?></div><?php endif; ?>
                  </td>
                  <td><?= e($l['cat_en']) ?></td>
                  <td><?= e($l['loc_en']) ?></td>
                  <td style="font-size:0.8rem;"><?= e($l['user_name'] ?? 'Guest') ?><br><span style="color:var(--muted-2);"><?= e($l['user_email'] ?? '') ?></span></td>
                  <td style="font-size:0.8rem;color:var(--muted-2);"><?= date('d M Y', strtotime($l['created_at'])) ?></td>
                  <td>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= csrf() ?>">
                      <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
                      <button name="action" value="approve" class="btn btn-primary btn-sm">✓ Approve</button>
                      <button name="action" value="reject" class="btn btn-outline btn-sm" style="border-color:rgba(230,50,50,0.3);color:#ff6b6b;margin-left:0.25rem;">✗ Reject</button>
                    </form>
                    <a href="<?= SITE_URL ?>/edit-listing.php?id=<?= $l['id'] ?>" class="btn btn-outline btn-sm" style="margin-left:0.25rem;">Edit</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php else: ?>
      <div class="listing-widget" style="text-align:center;padding:2rem;margin-bottom:2rem;">
        <p style="color:var(--green);">✓ No listings pending review</p>
      </div>
    <?php endif; ?>

    <!-- RECENT APPROVED -->
    <div class="listing-widget">
      <h4 style="margin-bottom:1rem;">✅ Recently Approved</h4>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Business</th><th>Category</th><th>City</th><th>Views</th><th>Featured</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($recentListings as $l): ?>
              <tr>
                <td><div style="color:var(--white);font-weight:500;"><?= $l['cat_icon'] ?> <?= e($l['title']) ?></div></td>
                <td><?= e($l['cat_en']) ?></td>
                <td><?= e($l['loc_en']) ?></td>
                <td><?= number_format($l['views']) ?></td>
                <td>
                  <?php if ($l['featured']): ?>
                    <span class="badge badge-approved">⭐ Yes</span>
                  <?php else: ?>
                    <span style="color:var(--muted-2);font-size:0.8rem;">No</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="<?= SITE_URL ?>/listing.php?slug=<?= e($l['slug']) ?>" class="btn btn-outline btn-sm">View</a>
                  <a href="<?= SITE_URL ?>/edit-listing.php?id=<?= $l['id'] ?>" class="btn btn-primary btn-sm" style="margin-left:0.25rem;">Edit</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
