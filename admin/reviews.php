<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $reviewId = (int)($_POST['review_id'] ?? 0);
    $action   = $_POST['action'] ?? '';
    if ($reviewId && in_array($action, ['approve','reject'])) {
        if ($action === 'approve') {
            db()->prepare("UPDATE reviews SET status='approved' WHERE id=?")->execute([$reviewId]);
            // Notify listing owner
            try {
                $rv = db()->prepare("SELECT r.*, l.title AS listing_title, l.slug, u.email AS owner_email, u.name AS owner_name FROM reviews r JOIN listings l ON l.id=r.listing_id LEFT JOIN users u ON u.id=l.user_id WHERE r.id=?");
                $rv->execute([$reviewId]);
                $rv = $rv->fetch();
                if ($rv && $rv['owner_email']) {
                    sendMail($rv['owner_email'],
                        t('New review on your listing — 237Biz', 'Nouvel avis sur votre annonce — 237Biz'),
                        '<h2 style="color:#fff;font-family:Georgia,serif;">⭐ ' . t('New Review Approved', 'Nouvel avis approuvé') . '</h2>
                         <p style="color:rgba(255,255,255,0.8);">' . t('Hi', 'Bonjour') . ' ' . e($rv['owner_name']) . ',</p>
                         <p style="color:rgba(255,255,255,0.7);">' . t('A customer left a review on your listing', 'Un client a laissé un avis sur votre annonce') . ' <strong style="color:#fff;">' . e($rv['listing_title']) . '</strong>.</p>
                         <div style="background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.2);border-radius:10px;padding:1rem;margin:1rem 0;">
                           <div style="color:#F5C842;margin-bottom:0.4rem;">' . str_repeat('⭐', (int)($rv['rating'] ?? 5)) . '</div>
                           <p style="color:rgba(255,255,255,0.8);margin:0;">' . e($rv['comment'] ?? '') . '</p>
                         </div>
                         <a href="' . SITE_URL . '/listing/' . e($rv['slug']) . '" style="display:inline-block;margin-top:1rem;background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:6px;text-decoration:none;">' . t('View Your Listing →', 'Voir votre annonce →') . '</a>'
                    );
                }
            } catch (Exception $e) {}
        } else {
            db()->prepare("DELETE FROM reviews WHERE id=?")->execute([$reviewId]);
        }
        flash('success', 'Review ' . ($action==='approve'?'approved':'deleted') . '.');
    }
    redirect(SITE_URL . '/admin/reviews.php');
}

$reviews = db()->query("
    SELECT r.*, l.title AS listing_title, l.slug AS listing_slug, u.name AS user_name
    FROM reviews r
    JOIN listings l ON l.id=r.listing_id
    LEFT JOIN users u ON u.id=r.user_id
    ORDER BY FIELD(r.status,'pending','approved'), r.created_at DESC
    LIMIT 100
")->fetchAll();

$pageTitle = 'Reviews — Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">⭐ Review Moderation</h1>
</div></div>
<section class="page-section"><div class="container">
    <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
      <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
      <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
      <a href="<?= SITE_URL ?>/admin/leads.php" class="filter-tab">🌐 Website Leads</a>
      <a href="<?= SITE_URL ?>/admin/enquiries.php" class="filter-tab">📬 Enquiries</a>
      <a href="<?= SITE_URL ?>/admin/claims.php" class="filter-tab">🏢 Claims</a>
      <a href="<?= SITE_URL ?>/admin/reviews.php" class="filter-tab active">⭐ Reviews</a>
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
  <div class="listing-widget"><div style="overflow-x:auto;">
    <table class="data-table">
      <thead><tr><th>Listing</th><th>Reviewer</th><th>Rating</th><th>Comment</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($reviews as $r): ?>
          <tr>
            <td><a href="<?= SITE_URL ?>/listing/<?= e($r['listing_slug']) ?>" style="color:var(--yellow);font-size:0.875rem;"><?= e(mb_substr($r['listing_title'],0,30)) ?></a></td>
            <td style="font-size:0.83rem;color:var(--white);"><?= e($r['name'] ?? $r['user_name'] ?? 'Anonymous') ?></td>
            <td style="color:var(--yellow);"><?= str_repeat('⭐',$r['rating']) ?></td>
            <td style="max-width:200px;font-size:0.8rem;color:var(--muted);"><?= e(mb_substr($r['comment']??'',0,80)) ?></td>
            <td><span class="badge badge-<?= $r['status']==='approved'?'approved':'pending' ?>"><?= ucfirst($r['status']) ?></span></td>
            <td style="font-size:0.78rem;color:var(--muted-2);"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:0.25rem;">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                <?php if ($r['status']==='pending'): ?>
                  <button name="action" value="approve" class="btn btn-primary btn-sm">✓</button>
                <?php endif; ?>
                <button name="action" value="reject" class="btn btn-outline btn-sm" style="border-color:rgba(230,50,50,0.3);color:#ff6b6b;" onclick="return confirm('Delete this review?')">✗</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
