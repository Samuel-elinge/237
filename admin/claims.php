<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $claimId = (int)($_POST['claim_id'] ?? 0);
    $action  = $_POST['action'] ?? '';

    $st = db()->prepare("SELECT lc.*, u.email, u.name AS user_name, u.id AS uid, l.title AS listing_title, l.id AS lid FROM listing_claims lc JOIN users u ON u.id=lc.user_id JOIN listings l ON l.id=lc.listing_id WHERE lc.id=?");
    $st->execute([$claimId]);
    $claim = $st->fetch();

    if ($claim) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        db()->prepare("UPDATE listing_claims SET status=?, reviewed_at=NOW() WHERE id=?")->execute([$status, $claimId]);
        if ($action === 'approve') {
            // Transfer listing ownership
            db()->prepare("UPDATE listings SET user_id=? WHERE id=?")->execute([$claim['uid'], $claim['lid']]);
            sendMail($claim['email'],
                t('Your listing claim has been approved!','Votre demande de revendication a été approuvée !'),
                '<h2 style="color:#fff;font-family:Georgia,serif;">✅ '.t('Claim Approved!','Demande Approuvée !').'</h2>
                 <p style="color:rgba(255,255,255,0.7);">'.t('You now own and can manage','Vous êtes maintenant propriétaire et pouvez gérer').' <strong>'.e($claim['listing_title']).'</strong>.</p>
                 <a href="'.SITE_URL.'/dashboard" style="display:inline-block;margin:1rem 0;background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;">'.t('Go to Dashboard','Aller au tableau de bord').'</a>'
            );
        }
        flash('success', 'Claim ' . $status . '.');
    }
    redirect(SITE_URL . '/admin/claims.php');
}

$claims = db()->query("
    SELECT lc.*, u.name AS user_name, u.email, l.title AS listing_title, l.slug AS listing_slug
    FROM listing_claims lc
    JOIN users u ON u.id=lc.user_id
    JOIN listings l ON l.id=lc.listing_id
    ORDER BY FIELD(lc.status,'pending','approved','rejected'), lc.created_at DESC
")->fetchAll();

$pageTitle = 'Listing Claims — Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">🏢 Listing Claims</h1>
</div></div>
<section class="page-section"><div class="container">
    <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
      <a href="<?= SITE_URL ?>/agent-health-checks.php" class="filter-tab">🩺 Health Check</a>
      <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
      <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
      <a href="<?= SITE_URL ?>/admin/leads.php" class="filter-tab">🌐 Website Leads</a>
      <a href="<?= SITE_URL ?>/admin/enquiries.php" class="filter-tab">📬 Enquiries</a>
      <a href="<?= SITE_URL ?>/admin/claims.php" class="filter-tab active">🏢 Claims</a>
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
  <div class="listing-widget"><div style="overflow-x:auto;">
    <table class="data-table">
      <thead><tr><th>Listing</th><th>Claimed By</th><th>Message</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($claims as $c): ?>
          <tr>
            <td><a href="<?= SITE_URL ?>/listing/<?= e($c['listing_slug']) ?>" style="color:var(--yellow);"><?= e($c['listing_title']) ?></a></td>
            <td><div style="color:var(--white);font-size:0.875rem;"><?= e($c['user_name']) ?></div><div style="font-size:0.75rem;color:var(--muted-2);"><?= e($c['email']) ?></div></td>
            <td style="max-width:200px;font-size:0.8rem;color:var(--muted);"><?= e(mb_substr($c['message']??'',0,80)) ?>...</td>
            <td><span class="badge badge-<?= $c['status']==='approved'?'approved':($c['status']==='pending'?'pending':'rejected') ?>"><?= ucfirst($c['status']) ?></span></td>
            <td style="font-size:0.78rem;color:var(--muted-2);"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
            <td>
              <?php if ($c['proof']): ?><a href="<?= e(UPLOAD_URL.$c['proof']) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-bottom:0.3rem;">📄 Proof</a><br><?php endif; ?>
              <?php if ($c['status']==='pending'): ?>
                <form method="POST" style="display:inline-flex;gap:0.25rem;margin-top:0.25rem;">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="claim_id" value="<?= $c['id'] ?>">
                  <button name="action" value="approve" class="btn btn-primary btn-sm">✓ Approve</button>
                  <button name="action" value="reject" class="btn btn-outline btn-sm" style="border-color:rgba(230,50,50,0.3);color:#ff6b6b;">✗ Reject</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
