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
    $action = $_POST['action'] ?? '';

    // ── Bulk actions ──────────────────────────────────────
    if (in_array($action, ['bulk_approve','bulk_reject','bulk_delete']) && !empty($_POST['listing_ids'])) {
        $ids = array_map('intval', (array)$_POST['listing_ids']);
        $ids = array_filter($ids); // remove zeros
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'bulk_approve') {
                db()->prepare("UPDATE listings SET status='approved' WHERE id IN ($ph)")->execute($ids);
                flash('success', count($ids) . ' listing(s) approved.');
            } elseif ($action === 'bulk_reject') {
                db()->prepare("UPDATE listings SET status='rejected' WHERE id IN ($ph)")->execute($ids);
                flash('success', count($ids) . ' listing(s) rejected.');
            } elseif ($action === 'bulk_delete') {
                db()->prepare("DELETE FROM listings WHERE id IN ($ph)")->execute($ids);
                flash('success', count($ids) . ' listing(s) deleted.');
            }
        }
        redirect(SITE_URL . '/admin/?' . http_build_query(array_filter([
            'status'   => $_GET['status'] ?? '',
            'q'        => $_GET['q'] ?? '',
            'featured' => $_GET['featured'] ?? '',
            'page'     => $_GET['page'] ?? '',
        ])));
    }
    // ── Single-item actions ───────────────────────────────
    $lid = (int)($_POST['listing_id'] ?? 0);

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

// Listings (with quality score)
$st = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon,
           loc.name_en AS loc_en,
           u.name AS user_name, u.email AS user_email,
           (SELECT COUNT(*) FROM listing_products p WHERE p.listing_id=l.id) AS product_count,
           (SELECT COUNT(*) FROM reviews r WHERE r.listing_id=l.id AND r.status='approved') AS review_count,
           /* Quality score 0-100 */
           (
               (l.logo IS NOT NULL AND l.logo != '') * 15 +
               (l.cover_image IS NOT NULL AND l.cover_image != '') * 15 +
               (CHAR_LENGTH(COALESCE(l.description,'')) > 100) * 15 +
               (l.phone IS NOT NULL AND l.phone != '') * 10 +
               (l.address IS NOT NULL AND l.address != '') * 10 +
               (l.website IS NOT NULL AND l.website != '') * 10 +
               (
                   (l.facebook IS NOT NULL AND l.facebook != '') OR
                   (l.instagram IS NOT NULL AND l.instagram != '') OR
                   (l.whatsapp IS NOT NULL AND l.whatsapp != '') OR
                   (l.tiktok IS NOT NULL AND l.tiktok != '')
               ) * 10 +
               (SELECT COUNT(*) > 0 FROM reviews r2 WHERE r2.listing_id=l.id AND r2.status='approved') * 10 +
               (l.views > 10) * 5
           ) AS quality_score
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

// Low-quality listings widget (approved, score < 40, limit 5)
$lowQuality = [];
try {
    $lq = db()->query("
        SELECT l.id, l.title, l.slug, l.logo,
               c.icon AS cat_icon, loc.name_en AS loc_en,
               u.name AS user_name,
               (
                   (l.logo IS NOT NULL AND l.logo != '') * 15 +
                   (l.cover_image IS NOT NULL AND l.cover_image != '') * 15 +
                   (CHAR_LENGTH(COALESCE(l.description,'')) > 100) * 15 +
                   (l.phone IS NOT NULL AND l.phone != '') * 10 +
                   (l.address IS NOT NULL AND l.address != '') * 10 +
                   (l.website IS NOT NULL AND l.website != '') * 10 +
                   (
                       (l.facebook IS NOT NULL AND l.facebook != '') OR
                       (l.instagram IS NOT NULL AND l.instagram != '') OR
                       (l.whatsapp IS NOT NULL AND l.whatsapp != '') OR
                       (l.tiktok IS NOT NULL AND l.tiktok != '')
                   ) * 10 +
                   (SELECT COUNT(*) > 0 FROM reviews r2 WHERE r2.listing_id=l.id AND r2.status='approved') * 10 +
                   (l.views > 10) * 5
               ) AS quality_score
        FROM listings l
        JOIN categories c  ON c.id = l.category_id
        JOIN locations loc ON loc.id = l.location_id
        LEFT JOIN users u  ON u.id = l.user_id
        WHERE l.status = 'approved'
        HAVING quality_score < 40
        ORDER BY quality_score ASC, l.created_at DESC
        LIMIT 5
    ");
    $lowQuality = $lq->fetchAll();
} catch (Exception $e) {}

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
require_once __DIR__ . '/includes/nav.php';
adminNav('index'); adminNavAssets();
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

  <!-- Low-quality listings widget -->
  <?php if ($lowQuality): ?>
  <div style="background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.25);border-radius:12px;padding:1.25rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.9rem;flex-wrap:wrap;gap:0.5rem;">
      <h3 style="font-size:0.95rem;font-weight:600;color:#f87171;margin:0;">⚠️ Needs Attention — Low Quality Listings</h3>
      <a href="<?= SITE_URL ?>/admin/?status=approved" style="font-size:0.78rem;color:var(--muted);text-decoration:none;">View all approved →</a>
    </div>
    <div style="display:flex;flex-direction:column;gap:0.5rem;">
      <?php foreach ($lowQuality as $lq): ?>
        <?php
          $qs = (int)$lq['quality_score'];
          $qColor = $qs < 20 ? '#ef4444' : ($qs < 40 ? '#f97316' : '#eab308');
        ?>
        <div style="display:flex;align-items:center;gap:0.75rem;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:0.6rem 0.9rem;">
          <div style="font-size:1.3rem;flex-shrink:0;"><?= $lq['cat_icon'] ?></div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:0.85rem;font-weight:500;color:var(--white);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($lq['title']) ?></div>
            <div style="font-size:0.72rem;color:var(--muted);"><?= e($lq['user_name'] ?? '—') ?> · <?= e($lq['loc_en']) ?></div>
          </div>
          <!-- Quality score bar -->
          <div style="flex-shrink:0;text-align:right;min-width:80px;">
            <div style="font-size:0.72rem;color:<?= $qColor ?>;font-weight:700;margin-bottom:3px;"><?= $qs ?>%</div>
            <div style="width:80px;height:5px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden;">
              <div style="height:100%;width:<?= $qs ?>%;background:<?= $qColor ?>;border-radius:3px;transition:width .3s;"></div>
            </div>
          </div>
          <a href="<?= SITE_URL ?>/edit-listing?id=<?= $lq['id'] ?>" class="btn btn-sm btn-outline" style="flex-shrink:0;font-size:0.75rem;padding:0.25rem 0.65rem;">Edit</a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>


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

  <!-- Bulk action bar -->
  <form method="POST" id="bulk-form">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <div id="bulk-bar" style="display:none;align-items:center;gap:0.75rem;background:#0a1a10;border:1px solid rgba(0,168,120,0.25);border-radius:10px;padding:0.65rem 1rem;margin-bottom:0.75rem;flex-wrap:wrap;">
      <span id="bulk-count" style="font-size:0.82rem;color:#8db8a8;white-space:nowrap;">0 selected</span>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <button type="submit" name="action" value="bulk_approve"
                class="btn btn-sm" style="background:rgba(0,168,120,0.15);color:#00A878;border:1px solid rgba(0,168,120,0.3);"
                onclick="return bulkConfirm('approve')">✓ Approve</button>
        <button type="submit" name="action" value="bulk_reject"
                class="btn btn-sm" style="background:rgba(245,180,0,0.12);color:#f5b400;border:1px solid rgba(245,180,0,0.3);"
                onclick="return bulkConfirm('reject')">✗ Reject</button>
        <button type="submit" name="action" value="bulk_delete"
                class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);"
                onclick="return bulkConfirm('delete')">🗑 Delete</button>
      </div>
      <button type="button" onclick="clearBulk()" style="margin-left:auto;background:none;border:none;color:#5a8878;cursor:pointer;font-size:0.8rem;">✕ Clear</button>
    </div>

  <!-- Listings table -->
  <div class="listing-widget" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
      <table class="data-table" style="min-width:900px;">
        <thead>
          <tr>
            <th style="width:36px;text-align:center;padding-left:1rem;">
              <input type="checkbox" id="select-all" title="Select all on this page"
                     style="width:15px;height:15px;cursor:pointer;accent-color:#00A878;">
            </th>
            <th style="width:28%;">Business</th>
            <th>Owner</th>
            <th>Category</th>
            <th>City</th>
            <th>Status</th>
            <th title="Profile completeness + activity score">Quality</th>
            <th>Products</th>
            <th>Views</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listings as $l): ?>
            <tr>
              <td style="text-align:center;padding-left:1rem;vertical-align:middle;">
                <input type="checkbox" name="listing_ids[]" value="<?= $l['id'] ?>"
                       class="row-check"
                       style="width:15px;height:15px;cursor:pointer;accent-color:#00A878;">
              </td>
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

              <!-- Quality score -->
              <td style="text-align:center;">
                <?php
                  $qs = (int)($l['quality_score'] ?? 0);
                  $qc = $qs >= 70 ? '#00a878' : ($qs >= 40 ? '#eab308' : '#ef4444');
                ?>
                <div style="display:inline-flex;flex-direction:column;align-items:center;gap:2px;" title="Quality score: <?= $qs ?>% (logo, cover, description, phone, address, website, social, reviews, views)">
                  <span style="font-size:0.75rem;font-weight:700;color:<?= $qc ?>;"><?= $qs ?>%</span>
                  <div style="width:40px;height:4px;background:rgba(255,255,255,0.08);border-radius:2px;overflow:hidden;">
                    <div style="height:100%;width:<?= $qs ?>%;background:<?= $qc ?>;border-radius:2px;"></div>
                  </div>
                </div>
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
                  <form method="POST" style="display:inline;" data-confirm="Delete this listing permanently?">
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
  </form><!-- /bulk-form -->

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

<script>
(function() {
  var form    = document.getElementById('bulk-form');
  var bar     = document.getElementById('bulk-bar');
  var countEl = document.getElementById('bulk-count');
  var selAll  = document.getElementById('select-all');

  function updateBar() {
    var checked = form.querySelectorAll('.row-check:checked');
    var n = checked.length;
    if (n > 0) {
      bar.style.display = 'flex';
      countEl.textContent = n + ' listing' + (n === 1 ? '' : 's') + ' selected';
    } else {
      bar.style.display = 'none';
    }
    var total = form.querySelectorAll('.row-check').length;
    selAll.indeterminate = n > 0 && n < total;
    selAll.checked = n === total && total > 0;
  }

  // Select-all toggle
  selAll.addEventListener('change', function() {
    form.querySelectorAll('.row-check').forEach(function(cb) {
      cb.checked = selAll.checked;
    });
    updateBar();
  });

  // Individual checkbox change
  form.addEventListener('change', function(e) {
    if (e.target.classList.contains('row-check')) updateBar();
  });

  // Highlight selected rows
  form.addEventListener('change', function(e) {
    if (e.target.classList.contains('row-check')) {
      var row = e.target.closest('tr');
      if (row) row.style.background = e.target.checked ? 'rgba(0,168,120,0.07)' : '';
    }
  });

  window.clearBulk = function() {
    form.querySelectorAll('.row-check').forEach(function(cb) { cb.checked = false; });
    selAll.checked = false;
    selAll.indeterminate = false;
    form.querySelectorAll('tr').forEach(function(r) { r.style.background = ''; });
    bar.style.display = 'none';
  };

  window.bulkConfirm = function(type) {
    var n = form.querySelectorAll('.row-check:checked').length;
    if (n === 0) { alert('Select at least one listing first.'); return false; }
    var msgs = {
      approve: 'Approve ' + n + ' listing(s)?',
      reject:  'Reject '  + n + ' listing(s)?',
      delete:  'Permanently delete ' + n + ' listing(s)? This cannot be undone.'
    };
    return window.confirm(msgs[type] || 'Are you sure?');
  };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>