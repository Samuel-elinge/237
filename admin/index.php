<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

// ── Counts for stats bar ──────────────────────────────────
$stats = db()->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='pending')  AS pending,
        SUM(status='approved') AS approved,
        SUM(status='rejected') AS rejected,
        SUM(featured=1)        AS featured
    FROM listings
")->fetch();
$userCount = db()->query("SELECT COUNT(*) FROM users")->fetchColumn();

// ── Handle approve / reject / delete ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $lid    = (int)($_POST['listing_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($lid) {
        if ($action === 'approve') {
            db()->prepare("UPDATE listings SET status='approved', featured=featured WHERE id=?")->execute([$lid]);

            // ── Automation triggers ───────────────────────────
            if (file_exists(__DIR__ . '/../automation/helper.php')) {
                require_once __DIR__ . '/../automation/helper.php';
                // Get listing owner
                $ownerSt = db()->prepare("SELECT user_id FROM listings WHERE id=?");
                $ownerSt->execute([$lid]);
                $ownerId = (int)$ownerSt->fetchColumn();
                if ($ownerId) {
                    // Queue listing-approved notification email
                    $tplSt = db()->prepare("SELECT id FROM automation_templates WHERE name='listing-approved' LIMIT 1");
                    $tplSt->execute();
                    $tplId = $tplSt->fetchColumn();
                    if ($tplId) queueEmail($ownerId, (int)$tplId);
                    // Enroll in post-approval sequence
                    enrollUser($ownerId, 'Post-Approval Onboarding');
                    enrollUser($ownerId, 'Long-Term Nurture');
                    // Tag appropriately
                    addUserTag($ownerId, 'free-listing');
                }
            }
            // ─────────────────────────────────────────────────

            flash('success', 'Listing approved.');
        } elseif ($action === 'reject') {
            db()->prepare("UPDATE listings SET status='rejected' WHERE id=?")->execute([$lid]);
            flash('success', 'Listing rejected.');
        } elseif ($action === 'delete') {
            db()->prepare("DELETE FROM listings WHERE id=?")->execute([$lid]);
            flash('success', 'Listing deleted.');
        } elseif ($action === 'toggle_featured') {
            db()->prepare("UPDATE listings SET featured = !featured WHERE id=?")->execute([$lid]);
            flash('success', 'Featured status toggled.');
        }
    }
    redirect(SITE_URL . '/admin/?' . http_build_query(array_filter([
        'status'   => $_GET['status'] ?? '',
        'q'        => $_GET['q'] ?? '',
        'featured' => $_GET['featured'] ?? '',
        'page'     => $_GET['page'] ?? '',
    ])));
}

// ── Filters ───────────────────────────────────────────────
$statusFilter   = in_array($_GET['status'] ?? '', ['pending','approved','rejected']) ? $_GET['status'] : '';
$featuredFilter = ($_GET['featured'] ?? '') === '1' ? 1 : null;
$search         = trim($_GET['q'] ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 25;
$offset         = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($statusFilter) { $where[] = "l.status = ?"; $params[] = $statusFilter; }
if ($featuredFilter !== null) { $where[] = "l.featured = ?"; $params[] = $featuredFilter; }
if ($search) {
    $where[] = "(l.title LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR l.phone LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
$whereStr = implode(' AND ', $where);

// Total count
$cntSt = db()->prepare("
    SELECT COUNT(*) FROM listings l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE $whereStr
");
$cntSt->execute($params);
$total      = (int)$cntSt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// Listings
$st = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon,
           loc.name_en AS loc_en,
           u.name AS user_name, u.email AS user_email,
           (SELECT COUNT(*) FROM listing_products p WHERE p.listing_id=l.id) AS product_count
    FROM listings l
    JOIN categories c   ON c.id = l.category_id
    JOIN locations loc  ON loc.id = l.location_id
    LEFT JOIN users u   ON u.id = l.user_id
    WHERE $whereStr
    ORDER BY l.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$st->execute($params);
$listings = $st->fetchAll();

// ── Helper: build filter URL ──────────────────────────────
function adminUrl(array $overrides = []): string {
    $base = array_filter([
        'status'   => $_GET['status'] ?? '',
        'q'        => $_GET['q'] ?? '',
        'featured' => $_GET['featured'] ?? '',
    ]);
    $qs = array_merge($base, $overrides);
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null);
    return '?' . ($qs ? http_build_query($qs) : '');
}

$pageTitle = 'All Listings — Admin 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">🔧 All Listings</h1>
</div></div>

<section class="page-section"><div class="container">

  <!-- Stats bar -->
  <div class="dash-stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:2rem;">
    <div class="dash-stat"><strong><?= $stats['total'] ?></strong><span>Total</span></div>
    <div class="dash-stat"><strong style="color:var(--yellow);"><?= $stats['pending'] ?></strong><span>Pending</span></div>
    <div class="dash-stat"><strong style="color:var(--green);"><?= $stats['approved'] ?></strong><span>Approved</span></div>
    <div class="dash-stat"><strong style="color:#ff6b6b;"><?= $stats['rejected'] ?></strong><span>Rejected</span></div>
    <div class="dash-stat"><strong style="color:var(--yellow);"><?= $stats['featured'] ?></strong><span>Featured</span></div>
    <div class="dash-stat"><strong><?= $userCount ?></strong><span>Users</span></div>
  </div>

  <!-- Admin nav -->
  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/"                        class="filter-tab active">📋 Listings</a>
    <a href="<?= SITE_URL ?>/admin/users.php"               class="filter-tab">👤 Users</a>
    <a href="<?= SITE_URL ?>/admin/orders.php"              class="filter-tab">📦 Orders</a>
    <a href="<?= SITE_URL ?>/admin/leads.php"               class="filter-tab">🌐 Website Leads</a>
    <a href="<?= SITE_URL ?>/admin/enquiries.php"           class="filter-tab">📬 Enquiries</a>
    <a href="<?= SITE_URL ?>/admin/claims.php"              class="filter-tab">🏢 Claims</a>
    <a href="<?= SITE_URL ?>/admin/reviews.php"             class="filter-tab">⭐ Reviews</a>
    <a href="<?= SITE_URL ?>/admin/categories.php"          class="filter-tab">📂 Categories</a>
    <a href="<?= SITE_URL ?>/admin/locations.php"           class="filter-tab">📍 Locations</a>
    <a href="<?= SITE_URL ?>/admin/subscribers.php"         class="filter-tab">📬 Newsletter</a>
    <a href="<?= SITE_URL ?>/admin/promos.php"              class="filter-tab">🎁 Promo Codes</a>
    <a href="<?= SITE_URL ?>/admin/packages.php"            class="filter-tab">💳 Packages</a>
    <a href="<?= SITE_URL ?>/admin/seo-content.php"         class="filter-tab">📝 SEO Content</a>
    <a href="<?= SITE_URL ?>/admin/emails.php"              class="filter-tab">✉️ Emails</a>
    <a href="<?= SITE_URL ?>/admin/automation/"             class="filter-tab">🤖 Automation</a>
    <a href="<?= SITE_URL ?>/admin/dashboard.php"           class="filter-tab">📊 Admin Dashboard</a>
    <a href="<?= SITE_URL ?>/admin/health-check-overview.php" class="filter-tab">🩺 Health Check</a>
    <a href="<?= SITE_URL ?>/admin/manage-agents.php"       class="filter-tab">👔 Agents</a>
    <a href="<?= SITE_URL ?>/admin/manage-creators.php"     class="filter-tab">🎬 Creators</a>
    <a href="<?= SITE_URL ?>/admin/manage-campaigns.php"    class="filter-tab">📣 Campaigns</a>
    <a href="<?= SITE_URL ?>/admin/manage-referrals.php"    class="filter-tab">💰 Referrals</a>
    <a href="<?= SITE_URL ?>/dashboard"                     class="filter-tab">← Dashboard</a>
  </div>

  <!-- Search + filter bar -->
  <form method="GET" style="margin-bottom:1.25rem;display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
    <div style="position:relative;flex:1;min-width:200px;">
      <input type="text" name="q" value="<?= e($search) ?>"
             placeholder="Search business name, owner, email, phone..."
             style="width:100%;background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.6rem 0.75rem 0.6rem 2.2rem;font-size:0.875rem;">
      <span style="position:absolute;left:0.7rem;top:50%;transform:translateY(-50%);color:var(--muted);">🔍</span>
    </div>
    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
    <?php if ($featuredFilter !== null): ?><input type="hidden" name="featured" value="1"><?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <?php if ($search || $statusFilter || $featuredFilter !== null): ?>
      <a href="<?= SITE_URL ?>/admin/" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
  </form>

  <!-- Status + featured filter tabs -->
  <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center;">
    <a href="<?= adminUrl(['status' => '', 'featured' => '']) ?>"
       class="btn btn-sm <?= !$statusFilter && $featuredFilter === null ? 'btn-primary' : 'btn-outline' ?>"
       style="font-size:0.78rem;">All (<?= $stats['total'] ?>)</a>
    <a href="<?= adminUrl(['status' => 'pending', 'page' => '']) ?>"
       class="btn btn-sm <?= $statusFilter==='pending' ? 'btn-primary' : 'btn-outline' ?>"
       style="font-size:0.78rem;<?= $stats['pending'] > 0 ? 'color:var(--yellow);border-color:rgba(245,200,66,0.4);' : '' ?>">
       ⏳ Pending (<?= $stats['pending'] ?>)</a>
    <a href="<?= adminUrl(['status' => 'approved', 'page' => '']) ?>"
       class="btn btn-sm <?= $statusFilter==='approved' ? 'btn-primary' : 'btn-outline' ?>"
       style="font-size:0.78rem;">✓ Approved (<?= $stats['approved'] ?>)</a>
    <a href="<?= adminUrl(['status' => 'rejected', 'page' => '']) ?>"
       class="btn btn-sm <?= $statusFilter==='rejected' ? 'btn-primary' : 'btn-outline' ?>"
       style="font-size:0.78rem;">✗ Rejected (<?= $stats['rejected'] ?>)</a>
    <a href="<?= adminUrl(['featured' => '1', 'status' => '', 'page' => '']) ?>"
       class="btn btn-sm <?= $featuredFilter === 1 ? 'btn-primary' : 'btn-outline' ?>"
       style="font-size:0.78rem;">⭐ Featured (<?= $stats['featured'] ?>)</a>
  </div>

  <!-- Results info -->
  <p style="font-size:0.82rem;color:var(--muted-2);margin-bottom:1rem;">
    Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $total)) ?> of <?= number_format($total) ?> listings
    <?= $search ? ' matching "' . e($search) . '"' : '' ?>
  </p>

  <!-- Listings table -->
  <div class="listing-widget" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
      <table class="data-table" style="min-width:900px;">
        <thead>
          <tr>
            <th style="width:32%;">Business</th>
            <th>Owner</th>
            <th>Category</th>
            <th>City</th>
            <th>Status</th>
            <th>Products</th>
            <th>Views</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listings as $l): ?>
            <tr>
              <!-- Business name + badges -->
              <td>
                <div style="font-weight:500;color:var(--white);font-size:0.875rem;">
                  <?= $l['cat_icon'] ?> <?= e($l['title']) ?>
                </div>
                <div style="display:flex;gap:0.35rem;flex-wrap:wrap;margin-top:0.25rem;">
                  <?php if ((int)$l['featured']): ?>
                    <span class="badge badge-approved" style="font-size:0.6rem;padding:0.1rem 0.4rem;">⭐ Featured</span>
                  <?php endif; ?>
                  <?php if ($l['verified']): ?>
                    <span class="badge badge-approved" style="font-size:0.6rem;padding:0.1rem 0.4rem;">✓ Verified</span>
                  <?php endif; ?>
                </div>
                <?php if ($l['phone']): ?>
                  <div style="font-size:0.72rem;color:var(--muted-2);margin-top:0.2rem;">📞 <?= e($l['phone']) ?></div>
                <?php endif; ?>
              </td>

              <!-- Owner -->
              <td style="font-size:0.8rem;">
                <?php if ($l['user_name']): ?>
                  <div style="color:var(--white);"><?= e($l['user_name']) ?></div>
                  <div style="color:var(--muted-2);font-size:0.72rem;"><?= e($l['user_email']) ?></div>
                <?php else: ?>
                  <span style="color:var(--muted-2);">—</span>
                <?php endif; ?>
              </td>

              <td style="font-size:0.82rem;color:var(--muted);"><?= e($l['cat_en']) ?></td>
              <td style="font-size:0.82rem;color:var(--muted);">📍 <?= e($l['loc_en']) ?></td>

              <!-- Status -->
              <td>
                <span class="badge badge-<?= $l['status'] ?>">
                  <?= ucfirst($l['status']) ?>
                </span>
              </td>

              <!-- Products count -->
              <td style="font-size:0.82rem;color:var(--muted);text-align:center;">
                <?php if ($l['product_count'] > 0): ?>
                  <a href="<?= SITE_URL ?>/manage-products?listing_id=<?= $l['id'] ?>" style="color:var(--green);">
                    🛍️ <?= $l['product_count'] ?>
                  </a>
                <?php else: ?>
                  <span style="color:var(--muted-2);">—</span>
                <?php endif; ?>
              </td>

              <td style="font-size:0.82rem;color:var(--muted);text-align:center;"><?= number_format($l['views']) ?></td>
              <td style="font-size:0.75rem;color:var(--muted-2);white-space:nowrap;"><?= date('d M Y', strtotime($l['created_at'])) ?></td>

              <!-- Actions -->
              <td>
                <div style="display:flex;gap:0.3rem;flex-wrap:wrap;">
                  <!-- View -->
                  <?php if ($l['status'] === 'approved'): ?>
                    <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>" target="_blank" class="btn btn-outline btn-sm">View</a>
                  <?php endif; ?>

                  <!-- Edit -->
                  <a href="<?= SITE_URL ?>/edit-listing?id=<?= $l['id'] ?>" class="btn btn-primary btn-sm">Edit</a>

                  <!-- Approve / Reject -->
                  <?php if ($l['status'] === 'pending'): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= csrf() ?>">
                      <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
                      <button name="action" value="approve" class="btn btn-sm" style="background:rgba(0,168,120,0.15);color:var(--green);border:1px solid rgba(0,168,120,0.3);">✓</button>
                      <button name="action" value="reject" class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);">✗</button>
                    </form>
                  <?php elseif ($l['status'] === 'approved'): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= csrf() ?>">
                      <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
                      <button name="action" value="reject" class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);" title="Reject">✗</button>
                    </form>
                  <?php elseif ($l['status'] === 'rejected'): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= csrf() ?>">
                      <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
                      <button name="action" value="approve" class="btn btn-sm" style="background:rgba(0,168,120,0.15);color:var(--green);border:1px solid rgba(0,168,120,0.3);" title="Re-approve">✓</button>
                    </form>
                  <?php endif; ?>

                  <!-- Toggle featured -->
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
                    <button name="action" value="toggle_featured" class="btn btn-sm"
                            style="background:rgba(245,200,66,0.1);color:var(--yellow);border:1px solid rgba(245,200,66,0.3);"
                            title="<?= (int)$l['featured'] ? 'Remove Featured' : 'Make Featured' ?>">
                      <?= (int)$l['featured'] ? '★' : '☆' ?>
                    </button>
                  </form>

                  <!-- Delete -->
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this listing permanently?')">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
                    <button name="action" value="delete" class="btn btn-sm"
                            style="background:rgba(230,50,50,0.08);color:#ff6b6b;border:1px solid rgba(230,50,50,0.2);">🗑</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!$listings): ?>
    <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;margin-top:1rem;">
      <div style="font-size:2.5rem;margin-bottom:0.75rem;">🔍</div>
      <p style="color:var(--muted);">No listings match your filters.</p>
    </div>
  <?php endif; ?>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:0.5rem;flex-wrap:wrap;margin-top:1.5rem;">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="<?= adminUrl(['page' => $i > 1 ? $i : '']) ?>"
           class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>