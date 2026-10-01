<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id = (int)($_POST['enquiry_id'] ?? 0);
    if ($id) {
        db()->prepare("UPDATE listing_enquiries SET status='read' WHERE id=?")->execute([$id]);
    }
    redirect(SITE_URL . '/admin/enquiries.php');
}

$enquiries = db()->query("
    SELECT e.*, l.title AS listing_title, l.slug AS listing_slug
    FROM listing_enquiries e
    JOIN listings l ON l.id = e.listing_id
    ORDER BY e.status ASC, e.created_at DESC
    LIMIT 100
")->fetchAll();

$unread = count(array_filter($enquiries, fn($e) => $e['status'] === 'unread'));

$pageTitle = 'Enquiries — Admin';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📬 Listing Enquiries <?php if ($unread): ?><span class="badge badge-pending" style="font-size:0.9rem;vertical-align:middle;"><?= $unread ?> new</span><?php endif; ?></h1>
</div></div>

<section class="page-section"><div class="container">
    <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
      <a href="<?= SITE_URL ?>/agent-health-checks.php" class="filter-tab">🩺 Health Check</a>
      <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
      <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
      <a href="<?= SITE_URL ?>/admin/leads.php" class="filter-tab">🌐 Website Leads</a>
      <a href="<?= SITE_URL ?>/admin/enquiries.php" class="filter-tab active">📬 Enquiries</a>
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
  <div style="display:flex;flex-direction:column;gap:1rem;">
    <?php foreach ($enquiries as $e): ?>
      <div class="listing-widget" style="<?= $e['status']==='unread' ? 'border-color:rgba(0,168,120,0.3);background:rgba(0,168,120,0.03);' : '' ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem;">
          <div>
            <?php if ($e['status']==='unread'): ?>
              <span class="badge badge-pending" style="margin-bottom:0.4rem;display:inline-block;">● New</span><br>
            <?php endif; ?>
            <span style="font-weight:500;color:var(--white);"><?= e($e['sender_name']) ?></span>
            <span style="color:var(--muted-2);font-size:0.82rem;margin-left:0.5rem;"><?= e($e['sender_email']) ?></span>
            <?php if ($e['sender_phone']): ?>
              <span style="color:var(--muted-2);font-size:0.82rem;margin-left:0.5rem;">· <?= e($e['sender_phone']) ?></span>
            <?php endif; ?>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:0.78rem;color:var(--muted-2);"><?= date('d M Y H:i', strtotime($e['created_at'])) ?></div>
            <a href="<?= SITE_URL ?>/listing/<?= e($e['listing_slug']) ?>" style="font-size:0.75rem;color:var(--green);">📋 <?= e($e['listing_title']) ?></a>
          </div>
        </div>
        <p style="font-size:0.875rem;color:var(--muted);line-height:1.7;background:rgba(255,255,255,0.02);border-radius:8px;padding:0.75rem;margin-bottom:0.75rem;">
          <?= nl2br(e($e['message'])) ?>
        </p>
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
          <a href="mailto:<?= e($e['sender_email']) ?>?subject=Re: your enquiry about <?= urlencode($e['listing_title']) ?> on 237Biz"
             class="btn btn-primary btn-sm">✉️ Reply by Email</a>
          <?php if ($e['sender_phone']): ?>
            <a href="https://wa.me/<?= preg_replace('/\D/', '', $e['sender_phone']) ?>" target="_blank"
               class="btn btn-sm" style="background:rgba(37,211,102,0.15);color:#25D366;border:1px solid rgba(37,211,102,0.3);">💬 WhatsApp</a>
          <?php endif; ?>
          <?php if ($e['status']==='unread'): ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="enquiry_id" value="<?= $e['id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm">✓ Mark Read</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$enquiries): ?>
      <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
        <div style="font-size:2.5rem;margin-bottom:0.75rem;">📭</div>
        <p style="color:var(--muted);">No enquiries yet.</p>
      </div>
    <?php endif; ?>
  </div>
</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
