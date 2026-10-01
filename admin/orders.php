<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $type   = $_POST['type'] ?? '';

    if ($type === 'service_order') {
        $id = (int)($_POST['order_id'] ?? 0);
        $st = db()->prepare("SELECT so.*, u.email, u.name AS user_name, s.name_en AS svc_name
                             FROM service_orders so JOIN users u ON u.id=so.user_id
                             JOIN services s ON s.id=so.service_id WHERE so.id=?");
        $st->execute([$id]);
        $order = $st->fetch();
        if ($order) {
            $status = $action === 'approve' ? 'active' : 'rejected';
            db()->prepare("UPDATE service_orders SET status=?, approved_at=NOW() WHERE id=?")
                 ->execute([$status, $id]);
            // Notify customer
            if ($action === 'approve') {
                sendMail($order['email'],
                    t('Your service order has been approved!','Votre commande de service a été approuvée !'),
                    '<h2 style="color:#fff;font-family:Georgia,serif;">✅ '.t('Order Approved!','Commande Approuvée !').'</h2>
                     <p style="color:rgba(255,255,255,0.7);">'.t('Hi','Bonjour').' '.e($order['user_name']).',</p>
                     <p style="color:rgba(255,255,255,0.7);">'.t('Your order for <strong>','Votre commande pour <strong>').e($order['svc_name']).'</strong> '.t('has been approved. Our team will contact you shortly to set everything up.','a été approuvée. Notre équipe vous contactera bientôt pour tout configurer.').'</p>
                     <p style="color:rgba(255,255,255,0.6);font-size:0.83rem;">'.t('Reference','Référence').': <strong style="color:#F5C842;">'.e($order['ref']).'</strong></p>'
                );
            }
            // ── Automation: tag service customer ─────────────
            if ($action === 'approve' && file_exists(__DIR__ . '/../automation/helper.php')) {
                require_once __DIR__ . '/../automation/helper.php';
                $uid = (int)$order['user_id'];
                $svcSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($order['svc_name'])));
                if (str_contains($svcSlug, 'website') || str_contains($svcSlug, 'web')) {
                    addUserTag($uid, 'website-customer');
                    removeUserTag($uid, 'online-presence-lead');
                } elseif (str_contains($svcSlug, 'email')) {
                    addUserTag($uid, 'email-customer');
                } elseif (str_contains($svcSlug, 'crm')) {
                    addUserTag($uid, 'crm-customer');
                } elseif (str_contains($svcSlug, 'host')) {
                    addUserTag($uid, 'hosting-customer');
                } elseif (str_contains($svcSlug, 'seo')) {
                    addUserTag($uid, 'seo-customer');
                }
            }
            // ─────────────────────────────────────────────────
            flash('success', 'Order ' . $status . '.');
        }
    } elseif ($type === 'listing_payment') {
        $id = (int)($_POST['payment_id'] ?? 0);
        $st = db()->prepare("SELECT lp.*, u.email, u.name AS user_name, l.title AS listing_title, l.id AS lid,
                             pkg.is_featured, pkg.is_verified, pkg.duration_days
                             FROM listing_payments lp JOIN users u ON u.id=lp.user_id
                             JOIN listings l ON l.id=lp.listing_id
                             JOIN listing_packages pkg ON pkg.id=lp.package_id
                             WHERE lp.id=?");
        $st->execute([$id]);
        $pay = $st->fetch();
        if ($pay) {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            db()->prepare("UPDATE listing_payments SET status=?, approved_at=NOW() WHERE id=?")
                 ->execute([$status, $id]);
            if ($action === 'approve') {
                // Activate featured on listing
                db()->prepare("UPDATE listings SET featured=?, verified=?, status='approved' WHERE id=?")
                     ->execute([$pay['is_featured'], $pay['is_verified'], $pay['lid']]);
                sendMail($pay['email'],
                    t('Featured listing activated!','Annonce vedette activée !'),
                    '<h2 style="color:#fff;font-family:Georgia,serif;">⭐ '.t('Featured Listing Activated!','Annonce Vedette Activée !').'</h2>
                     <p style="color:rgba(255,255,255,0.7);">'.t('Hi','Bonjour').' '.e($pay['user_name']).',</p>
                     <p style="color:rgba(255,255,255,0.7);">'.t('Your listing <strong>','Votre annonce <strong>').e($pay['listing_title']).'</strong> '.t('is now featured and verified.','est maintenant en vedette et vérifiée.').'</p>
                     <a href="'.SITE_URL.'/listing.php?id='.$pay['lid'].'" style="display:inline-block;margin:1rem 0;background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;">'.t('View My Listing','Voir mon Annonce').'</a>'
                );
            }
            // ── Automation: tag premium listing customer ─────
            if ($action === 'approve' && file_exists(__DIR__ . '/../automation/helper.php')) {
                require_once __DIR__ . '/../automation/helper.php';
                $uid = (int)$pay['user_id'];
                addUserTag($uid, 'premium-listing');
                removeUserTag($uid, 'free-listing');
                // Cancel listing upsell — they already upgraded
                db()->prepare("UPDATE automation_enrollments SET status='cancelled' WHERE user_id=? AND sequence_id=(SELECT id FROM automation_sequences WHERE name='Listing Upsell' LIMIT 1)")->execute([$uid]);
            }
            // ─────────────────────────────────────────────────
            flash('success', 'Payment ' . $status . '.');
        }
    }
    redirect(SITE_URL . '/admin/orders.php');
}

// Fetch pending service orders
$serviceOrders = db()->query("
    SELECT so.*, u.name AS user_name, u.email, s.name_en AS svc_name, s.icon
    FROM service_orders so
    JOIN users u ON u.id=so.user_id
    JOIN services s ON s.id=so.service_id
    ORDER BY FIELD(so.status,'pending','active','approved','rejected'), so.created_at DESC
    LIMIT 50
")->fetchAll();

// Fetch listing payments
$listingPayments = db()->query("
    SELECT lp.*, u.name AS user_name, u.email, l.title AS listing_title,
           pkg.name_en AS pkg_name, pkg.is_featured
    FROM listing_payments lp
    JOIN users u ON u.id=lp.user_id
    JOIN listings l ON l.id=lp.listing_id
    JOIN listing_packages pkg ON pkg.id=lp.package_id
    ORDER BY FIELD(lp.status,'pending','approved','rejected'), lp.created_at DESC
    LIMIT 50
")->fetchAll();

$pageTitle = 'Orders & Payments — Admin 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📦 Orders & Payments</h1>
  </div>
</div>

<section class="page-section">
  <div class="container">

    <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
      <a href="<?= SITE_URL ?>/agent-health-checks.php" class="filter-tab">🩺 Health Check</a>
      <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
      <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab active">📦 Orders</a>
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
    <!-- SERVICE ORDERS -->
    <div class="listing-widget" style="margin-bottom:2rem;">
      <h4 style="margin-bottom:1rem;">🛒 Service Orders (<?= count($serviceOrders) ?>)</h4>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Ref</th><th>Service</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($serviceOrders as $o): ?>
              <tr>
                <td style="font-size:0.78rem;color:var(--yellow);font-weight:500;"><?= e($o['ref']) ?></td>
                <td><?= $o['icon'] ?> <?= e($o['svc_name']) ?><br><span style="font-size:0.75rem;color:var(--muted-2);"><?= ucfirst($o['type']) ?></span></td>
                <td>
                  <div style="color:var(--white);font-size:0.875rem;"><?= e($o['user_name']) ?></div>
                  <div style="font-size:0.75rem;color:var(--muted-2);"><?= e($o['contact_phone'] ?: $o['email']) ?></div>
                  <?php if ($o['business_name']): ?><div style="font-size:0.75rem;color:var(--muted-2);"><?= e($o['business_name']) ?></div><?php endif; ?>
                </td>
                <td style="font-family:'Fraunces',serif;font-weight:700;color:var(--yellow);"><?= number_format($o['amount_xaf']) ?> XAF</td>
                <td><span class="badge badge-<?= $o['status']==='active'||$o['status']==='approved' ? 'approved' : ($o['status']==='pending' ? 'pending' : 'rejected') ?>"><?= ucfirst($o['status']) ?></span></td>
                <td style="font-size:0.78rem;color:var(--muted-2);"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                <td>
                  <?php if ($o['proof']): ?>
                    <a href="<?= e(UPLOAD_URL . $o['proof']) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-bottom:0.3rem;">📄 Proof</a><br>
                  <?php endif; ?>
                  <?php if ($o['status'] === 'pending'): ?>
                    <form method="POST" style="display:inline-flex;gap:0.25rem;margin-top:0.25rem;">
                      <input type="hidden" name="csrf" value="<?= csrf() ?>">
                      <input type="hidden" name="type" value="service_order">
                      <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                      <button name="action" value="approve" class="btn btn-primary btn-sm">✓ Approve</button>
                      <button name="action" value="reject" class="btn btn-outline btn-sm" style="border-color:rgba(230,50,50,0.3);color:#ff6b6b;">✗ Reject</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($o['notes']): ?><div style="font-size:0.72rem;color:var(--muted-2);margin-top:0.25rem;">📝 <?= e(mb_substr($o['notes'],0,60)) ?></div><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- LISTING PAYMENTS -->
    <div class="listing-widget">
      <h4 style="margin-bottom:1rem;">⭐ Featured Listing Payments (<?= count($listingPayments) ?>)</h4>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Ref</th><th>Listing</th><th>Package</th><th>User</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($listingPayments as $p): ?>
              <tr>
                <td style="font-size:0.78rem;color:var(--yellow);font-weight:500;"><?= e($p['ref']) ?></td>
                <td style="color:var(--white);font-size:0.875rem;"><?= e($p['listing_title']) ?></td>
                <td><?= e($p['pkg_name']) ?><?= $p['is_featured'] ? ' ⭐' : '' ?></td>
                <td>
                  <div style="font-size:0.875rem;color:var(--white);"><?= e($p['user_name']) ?></div>
                  <div style="font-size:0.75rem;color:var(--muted-2);"><?= e($p['email']) ?></div>
                </td>
                <td style="font-family:'Fraunces',serif;font-weight:700;color:var(--yellow);"><?= number_format($p['amount_xaf']) ?> XAF</td>
                <td><span class="badge badge-<?= $p['status']==='approved'?'approved':($p['status']==='pending'?'pending':'rejected') ?>"><?= ucfirst($p['status']) ?></span></td>
                <td>
                  <?php if ($p['proof']): ?>
                    <a href="<?= e(UPLOAD_URL . $p['proof']) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-bottom:0.3rem;">📄 Proof</a><br>
                  <?php endif; ?>
                  <?php if ($p['status'] === 'pending'): ?>
                    <form method="POST" style="display:inline-flex;gap:0.25rem;margin-top:0.25rem;">
                      <input type="hidden" name="csrf" value="<?= csrf() ?>">
                      <input type="hidden" name="type" value="listing_payment">
                      <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                      <button name="action" value="approve" class="btn btn-primary btn-sm">✓ Approve</button>
                      <button name="action" value="reject" class="btn btn-outline btn-sm" style="border-color:rgba(230,50,50,0.3);color:#ff6b6b;">✗ Reject</button>
                    </form>
                  <?php endif; ?>
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
