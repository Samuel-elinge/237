<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u = currentUser();

// User's listings
$myListings = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon, loc.name_en AS loc_en
    FROM listings l
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    WHERE l.user_id = ?
    ORDER BY l.created_at DESC
");
$myListings->execute([$u['id']]);
$myListings = $myListings->fetchAll();

$pendingPaymentsByListing = [];
try {
    $pp = db()->prepare("SELECT listing_id FROM listing_payments WHERE user_id = ? AND status = 'pending'");
    $pp->execute([$u['id']]);
    foreach ($pp->fetchAll() as $row) $pendingPaymentsByListing[$row['listing_id']] = true;
} catch (Exception $e) {}

$totalViews    = array_sum(array_column($myListings, 'views'));
$totalApproved = count(array_filter($myListings, fn($l) => $l['status'] === 'approved'));
$totalPending  = count(array_filter($myListings, fn($l) => $l['status'] === 'pending'));
$hasFeatured   = count(array_filter($myListings, fn($l) => $l['featured'])) > 0;
$firstFeaturedId = 0;
foreach ($myListings as $ml) { if ($ml['featured']) { $firstFeaturedId = $ml['id']; break; } }
$firstApprovedId  = 0;
$firstApprovedSlug = '';
foreach ($myListings as $ml) { if ($ml['status']==='approved') { $firstApprovedId=$ml['id']; $firstApprovedSlug=$ml['slug']; break; } }

// Total bookings (pending)
$pendingBookings = 0;
try {
    $bq = db()->prepare("
        SELECT COUNT(*) FROM listing_bookings b
        JOIN listings l ON l.id=b.listing_id
        WHERE l.user_id=? AND b.status='pending'
    ");
    $bq->execute([$u['id']]);
    $pendingBookings = (int)$bq->fetchColumn();
} catch (Exception $e) {}

// Active announcements count
$activeAnnouncements = 0;
try {
    $aq = db()->prepare("
        SELECT COUNT(*) FROM listing_announcements a
        JOIN listings l ON l.id=a.listing_id
        WHERE l.user_id=? AND a.active=1 AND a.ends_at >= NOW()
    ");
    $aq->execute([$u['id']]);
    $activeAnnouncements = (int)$aq->fetchColumn();
} catch (Exception $e) {}

$pageTitle = t('My Dashboard — 237Biz', 'Mon Tableau de Bord — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Feature cards */
.feature-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-bottom:1.5rem; }
.feature-card  {
  display:flex; align-items:center; gap:12px;
  padding:14px 16px; border-radius:12px;
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  text-decoration:none; transition:all .15s;
}
.feature-card:hover { background:rgba(255,255,255,0.05); border-color:rgba(0,168,120,0.3); }
.feature-card-icon { font-size:1.6rem; flex-shrink:0; }
.feature-card-text { min-width:0; }
.feature-card-label { font-size:13px; font-weight:700; color:rgba(255,255,255,0.9); display:block; }
.feature-card-sub   { font-size:11.5px; color:var(--muted); margin-top:1px; }
.feature-card-badge {
  margin-left:auto; font-size:11px; font-weight:700;
  background:rgba(206,17,38,0.2); color:#e63946;
  border:1px solid rgba(206,17,38,0.35); border-radius:99px;
  padding:2px 8px; white-space:nowrap; flex-shrink:0;
}
</style>

<div class="page-header">
  <div class="container">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.8rem,3vw,2.5rem);">
      <?= t('Welcome back,', 'Bienvenue,') ?> <?= e(explode(' ', $u['name'])[0]) ?> 👋
    </h1>
    <p style="color:var(--muted);margin-top:0.35rem;"><?= e($u['email']) ?></p>
  </div>
</div>

<section class="page-section">
  <div class="container">
    <div class="dashboard-grid">

      <!-- SIDEBAR -->
      <aside class="dash-sidebar">
        <nav class="dash-nav">
          <a href="<?= SITE_URL ?>/dashboard" class="active">📋 <?= t('My Listings','Mes Annonces') ?></a>
          <a href="<?= SITE_URL ?>/add-listing">➕ <?= t('Add Listing','Ajouter Annonce') ?></a>
          <?php if ($firstApprovedId): ?>
          <a href="<?= SITE_URL ?>/analytics?listing_id=<?= $firstApprovedId ?>">📊 <?= t('Analytics','Analytiques') ?></a>
          <a href="<?= SITE_URL ?>/manage-announcements?listing_id=<?= $firstApprovedId ?>">📢 <?= t('Announcements','Annonces') ?><?php if ($activeAnnouncements): ?> <span style="background:#00A878;color:#0A1A0F;border-radius:99px;font-size:10px;padding:1px 7px;font-weight:700;margin-left:4px;"><?= $activeAnnouncements ?></span><?php endif; ?></a>
          <?php endif; ?>
          <?php if ($hasFeatured): ?>
          <a href="<?= SITE_URL ?>/manage-bookings?listing_id=<?= $firstFeaturedId ?>">📅 <?= t('Bookings','Réservations') ?><?php if ($pendingBookings): ?> <span style="background:#e63946;color:#fff;border-radius:99px;font-size:10px;padding:1px 7px;font-weight:700;margin-left:4px;"><?= $pendingBookings ?></span><?php endif; ?></a>
          <?php endif; ?>
          <a href="<?= SITE_URL ?>/profile.php">👤 <?= t('My Profile','Mon Profil') ?></a>
          <?php if (isAdmin()): ?>
          <a href="<?= SITE_URL ?>/admin/">🔧 <?= t('Admin Panel','Panneau Admin') ?></a>
          <?php endif; ?>
          <a href="<?= SITE_URL ?>/logout" style="margin-top:1rem;color:rgba(255,100,100,0.7);">🚪 <?= t('Sign Out','Déconnexion') ?></a>
        </nav>
      </aside>

      <!-- MAIN -->
      <div>

        <!-- Stats -->
        <div class="dash-stats-grid">
          <div class="dash-stat">
            <strong><?= count($myListings) ?></strong>
            <span><?= t('Total Listings','Annonces totales') ?></span>
          </div>
          <div class="dash-stat">
            <strong><?= $totalApproved ?></strong>
            <span><?= t('Published','Publiées') ?></span>
          </div>
          <div class="dash-stat">
            <strong><?= number_format($totalViews) ?></strong>
            <span><?= t('Total Views','Vues totales') ?></span>
          </div>
          <?php if ($hasFeatured): ?>
          <div class="dash-stat" style="<?= $pendingBookings ? 'border-color:rgba(206,17,38,0.4);' : '' ?>">
            <strong style="<?= $pendingBookings ? 'color:#e63946;' : '' ?>"><?= $pendingBookings ?></strong>
            <span><?= t('Pending Bookings','Réservations en attente') ?></span>
          </div>
          <?php endif; ?>
        </div>

        <!-- Feature quick-access cards (shown for approved listings) -->
        <?php if ($firstApprovedId): ?>
        <div class="feature-cards">
          <a href="<?= SITE_URL ?>/analytics?listing_id=<?= $firstApprovedId ?>" class="feature-card">
            <span class="feature-card-icon">📊</span>
            <div class="feature-card-text">
              <span class="feature-card-label"><?= t('Analytics','Analytiques') ?></span>
              <span class="feature-card-sub"><?= t('Views, enquiries & profile score','Vues, demandes & score profil') ?></span>
            </div>
          </a>
          <a href="<?= SITE_URL ?>/manage-announcements?listing_id=<?= $firstApprovedId ?>" class="feature-card">
            <span class="feature-card-icon">📢</span>
            <div class="feature-card-text">
              <span class="feature-card-label"><?= t('Announcements','Annonces') ?></span>
              <span class="feature-card-sub"><?= $activeAnnouncements ? $activeAnnouncements.' '.t('active','actives') : t('Promotions & events','Promotions & événements') ?></span>
            </div>
          </a>
          <?php if ($hasFeatured): ?>
          <a href="<?= SITE_URL ?>/manage-bookings?listing_id=<?= $firstFeaturedId ?>" class="feature-card">
            <span class="feature-card-icon">📅</span>
            <div class="feature-card-text">
              <span class="feature-card-label"><?= t('Bookings','Réservations') ?></span>
              <span class="feature-card-sub"><?= $pendingBookings ? $pendingBookings.' '.t('need attention','à traiter') : t('Appointment requests','Demandes de rendez-vous') ?></span>
            </div>
            <?php if ($pendingBookings): ?><span class="feature-card-badge"><?= $pendingBookings ?> new</span><?php endif; ?>
          </a>
          <?php endif; ?>
          <?php if ($firstApprovedSlug): ?>
          <a href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=<?= urlencode(SITE_URL.'/listing/'.$firstApprovedSlug) ?>&color=08472F&bgcolor=ffffff&format=png"
             download="<?= e($firstApprovedSlug) ?>-qr.png" target="_blank" class="feature-card">
            <span class="feature-card-icon">📱</span>
            <div class="feature-card-text">
              <span class="feature-card-label"><?= t('QR Code','Code QR') ?></span>
              <span class="feature-card-sub"><?= t('Download & print for your business','Télécharger & imprimer') ?></span>
            </div>
          </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Listings table -->
        <div class="listing-widget">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
            <h4 style="margin-bottom:0;"><?= t('My Listings','Mes Annonces') ?></h4>
            <a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary btn-sm">+ <?= t('Add New','Ajouter') ?></a>
          </div>

          <?php if ($myListings): ?>
          <div style="overflow-x:auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= t('Business','Entreprise') ?></th>
                  <th><?= t('Category','Catégorie') ?></th>
                  <th><?= t('Location','Ville') ?></th>
                  <th><?= t('Status','Statut') ?></th>
                  <th><?= t('Views','Vues') ?></th>
                  <th><?= t('Actions','Actions') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($myListings as $l): ?>
                <tr>
                  <td>
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                      <span style="font-size:1.2rem;"><?= $l['cat_icon'] ?></span>
                      <div>
                        <div style="color:var(--white);font-weight:500;font-size:0.875rem;"><?= e($l['title']) ?></div>
                        <?php if ((int)$l['featured'] === 1): ?>
                        <span class="badge badge-approved">⭐ <?= t('Featured','Vedette') ?></span>
                        <?php elseif (isset($pendingPaymentsByListing[$l['id']])): ?>
                        <span class="badge badge-pending">⏳ <?= t('Upgrade Pending','Mise à niveau en attente') ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td><?= e($l['cat_en']) ?></td>
                  <td><?= e($l['loc_en']) ?></td>
                  <td>
                    <span class="badge badge-<?= $l['status'] ?>">
                      <?= t(ucfirst($l['status']), [
                          'approved'=>'Approuvé','pending'=>'En attente','rejected'=>'Rejeté',
                      ][$l['status']] ?? $l['status']) ?>
                    </span>
                  </td>
                  <td><?= number_format($l['views']) ?></td>
                  <td>
                    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                      <?php if ($l['status'] === 'approved'): ?>
                      <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>" class="btn btn-outline btn-sm"><?= t('View','Voir') ?></a>
                      <?php endif; ?>
                      <a href="<?= SITE_URL ?>/edit-listing?id=<?= $l['id'] ?>" class="btn btn-primary btn-sm"><?= t('Edit','Modifier') ?></a>
                      <?php if ($l['status']==='approved'): ?>
                      <a href="<?= SITE_URL ?>/analytics?listing_id=<?= $l['id'] ?>" class="btn btn-sm" style="background:rgba(0,168,120,0.1);color:#00A878;border:1px solid rgba(0,168,120,0.25);">📊</a>
                      <?php endif; ?>
                      <?php if ((int)$l['featured'] !== 1): ?>
                        <?php if (isset($pendingPaymentsByListing[$l['id']])): ?>
                        <span class="btn btn-sm" style="background:rgba(255,255,255,0.05);color:var(--muted-2);border:1px solid var(--border);cursor:default;">⏳ <?= t('Pending Review','En Révision') ?></span>
                        <?php else: ?>
                        <a href="<?= SITE_URL ?>/upgrade-listing?listing_id=<?= $l['id'] ?>" class="btn btn-sm" style="background:rgba(245,200,66,0.15);color:var(--yellow);border:1px solid rgba(245,200,66,0.3);">⭐ <?= t('Upgrade','Améliorer') ?></a>
                        <?php endif; ?>
                      <?php else: ?>
                      <a href="<?= SITE_URL ?>/manage-products?listing_id=<?= $l['id'] ?>" class="btn btn-sm" style="background:rgba(0,168,120,0.12);color:var(--green);border:1px solid rgba(0,168,120,0.25);">🛍️ <?= t('Products','Produits') ?></a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <div style="text-align:center;padding:3rem;background:rgba(255,255,255,0.02);border-radius:10px;">
            <div style="font-size:2.5rem;margin-bottom:1rem;">📋</div>
            <h3 style="font-family:'Fraunces',serif;margin-bottom:0.5rem;"><?= t('No listings yet','Aucune annonce') ?></h3>
            <p style="color:var(--muted);font-size:0.875rem;margin-bottom:1.5rem;"><?= t('Add your first business listing for free.','Ajoutez votre première annonce gratuitement.') ?></p>
            <a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary">+ <?= t('Add Your Business','Ajouter votre Entreprise') ?></a>
          </div>
          <?php endif; ?>
        </div>

        <!-- Service orders -->
        <?php
        try {
            $myOrders = db()->prepare("SELECT so.*, s.name_en AS svc_name, s.icon FROM service_orders so JOIN services s ON s.id=so.service_id WHERE so.user_id=? ORDER BY so.created_at DESC LIMIT 10");
            $myOrders->execute([$u['id']]);
            $myOrders = $myOrders->fetchAll();
        } catch (Exception $e) { $myOrders = []; }
        ?>
        <?php if ($myOrders): ?>
        <div class="listing-widget" style="margin-top:1.5rem;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
            <h4 style="margin-bottom:0;">🛒 <?= t('My Service Orders','Mes Commandes') ?></h4>
            <a href="<?= SITE_URL ?>/services" class="btn btn-outline btn-sm"><?= t('Order a Service','Commander un Service') ?></a>
          </div>
          <table class="data-table">
            <thead><tr><th><?= t('Ref','Réf') ?></th><th><?= t('Service','Service') ?></th><th><?= t('Amount','Montant') ?></th><th><?= t('Status','Statut') ?></th><th><?= t('Date','Date') ?></th></tr></thead>
            <tbody>
              <?php foreach ($myOrders as $o): ?>
              <tr>
                <td style="font-size:0.78rem;color:var(--yellow);"><?= e($o['ref']) ?></td>
                <td><?= $o['icon'] ?> <?= e($o['svc_name']) ?></td>
                <td style="font-family:'Fraunces',serif;font-weight:700;"><?= number_format($o['amount_xaf']) ?> XAF</td>
                <td><span class="badge badge-<?= in_array($o['status'],['active','approved'])?'approved':($o['status']==='pending'?'pending':'rejected') ?>"><?= ucfirst($o['status']) ?></span></td>
                <td style="font-size:0.78rem;color:var(--muted-2);"><?= date('d M Y',strtotime($o['created_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

      </div><!-- /main -->
    </div><!-- /grid -->
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
