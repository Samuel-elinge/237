<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/entitlement-helpers.php';
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

// Pre-fetch entitlements for all listings (for usage indicators)
$listingEntitlements = [];
foreach ($myListings as $_ml) {
    if ($_ml['featured']) {
        try { $listingEntitlements[$_ml['id']] = getListingEntitlements($_ml['id']); } catch (Exception $e) {}
    }
}
unset($_ml);

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

// Total followers across all approved listings
$totalFollowers = 0;
try {
    $fq = db()->prepare("
        SELECT COUNT(*) FROM user_follows uf
        JOIN listings l ON l.id = uf.follow_id AND uf.follow_type='listing'
        WHERE l.user_id=? AND uf.unfollowed_at IS NULL
    ");
    $fq->execute([$u['id']]);
    $totalFollowers = (int)$fq->fetchColumn();
} catch (Exception $e) {}

// Profile completeness for first approved listing (quick check)
$dashProfilePct = 0;
$dashProfileLid = 0;
if ($firstApprovedId) {
    $dashProfileLid = $firstApprovedId;
    foreach ($myListings as $ml) {
        if ($ml['id'] === $firstApprovedId) {
            $checks = [
                !empty($ml['tagline']),
                str_word_count(strip_tags($ml['description']??'')) >= 50,
                !empty($ml['phone']) || !empty($ml['whatsapp'] ?? ''),
                !empty($ml['services_json']) && $ml['services_json']!=='[]',
                !empty($ml['keywords']),
                !empty($ml['hours_json']) && $ml['hours_json']!=='{}',
                !empty($ml['cta_url'] ?? ''),
                !empty($ml['video_url'] ?? ''),
            ];
            $done = count(array_filter($checks));
            $dashProfilePct = (int)round(($done + 1) / 9 * 100); // +1 for 'Business name' always true
            break;
        }
    }
}

// ── Phase 3: Subscription + Promotion quick-stats ───────────────────────────
$subStatus = null;   // active subscription status (or null)
$subPlanName = '';
$activePromos = 0;
$pendingPromos = 0;
try {
    // Latest active/pending subscription for this user
    $sq = db()->prepare("
        SELECT bs.status, sp.name_en AS plan_name
        FROM business_subscriptions bs
        JOIN subscription_plans sp ON sp.id = bs.plan_id
        WHERE bs.user_id = ? AND bs.status NOT IN ('cancelled','expired')
        ORDER BY bs.created_at DESC LIMIT 1
    ");
    $sq->execute([$u['id']]);
    $subRow = $sq->fetch();
    if ($subRow) { $subStatus = $subRow['status']; $subPlanName = $subRow['plan_name']; }
} catch (Exception $e) {}
try {
    $pq = db()->prepare("
        SELECT
          SUM(CASE WHEN po.status='active' THEN 1 ELSE 0 END) AS cnt_active,
          SUM(CASE WHEN po.status='pending' THEN 1 ELSE 0 END) AS cnt_pending
        FROM promotion_orders po
        JOIN listings l ON l.id = po.listing_id
        WHERE l.user_id = ?
    ");
    $pq->execute([$u['id']]);
    $pRow = $pq->fetch();
    $activePromos  = (int)($pRow['cnt_active']  ?? 0);
    $pendingPromos = (int)($pRow['cnt_pending'] ?? 0);
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
          <a href="<?= SITE_URL ?>/my-enquiries?listing_id=<?= $firstApprovedId ?>">📬 <?= t('Enquiries','Demandes') ?></a>
          <a href="<?= SITE_URL ?>/manage-announcements?listing_id=<?= $firstApprovedId ?>">📢 <?= t('Announcements','Annonces') ?><?php if ($activeAnnouncements): ?> <span style="background:#00A878;color:#0A1A0F;border-radius:99px;font-size:10px;padding:1px 7px;font-weight:700;margin-left:4px;"><?= $activeAnnouncements ?></span><?php endif; ?></a>
          <?php endif; ?>
          <?php if ($hasFeatured): ?>
          <a href="<?= SITE_URL ?>/manage-bookings?listing_id=<?= $firstFeaturedId ?>">📅 <?= t('Bookings','Réservations') ?><?php if ($pendingBookings): ?> <span style="background:#e63946;color:#fff;border-radius:99px;font-size:10px;padding:1px 7px;font-weight:700;margin-left:4px;"><?= $pendingBookings ?></span><?php endif; ?></a>
          <?php endif; ?>
          <a href="<?= SITE_URL ?>/my-subscription">💳 <?= t('Subscription','Abonnement') ?><?php if ($subStatus==='active'): ?> <span style="background:#00A878;color:#0A1A0F;border-radius:99px;font-size:10px;padding:1px 7px;font-weight:700;margin-left:4px;"><?= t('Active','Actif') ?></span><?php elseif ($subStatus==='pending_payment'): ?> <span style="background:rgba(245,200,66,0.85);color:#0A1A0F;border-radius:99px;font-size:10px;padding:1px 7px;font-weight:700;margin-left:4px;"><?= t('Pending','En attente') ?></span><?php endif; ?></a>
          <a href="<?= SITE_URL ?>/my-promotions">📣 <?= t('Promotions','Promotions') ?><?php if ($activePromos): ?> <span style="background:#00A878;color:#0A1A0F;border-radius:99px;font-size:10px;padding:1px 7px;font-weight:700;margin-left:4px;"><?= $activePromos ?></span><?php endif; ?></a>
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
          <div class="dash-stat">
            <strong style="color:#56b4d3;"><?= number_format($totalFollowers) ?></strong>
            <span><?= t('Followers','Abonnés') ?></span>
          </div>
          <?php if ($dashProfilePct > 0): ?>
          <div class="dash-stat">
            <strong style="color:<?= $dashProfilePct>=80?'#00A878':($dashProfilePct>=50?'var(--yellow)':'#e63946') ?>;"><?= $dashProfilePct ?>%</strong>
            <span><?= t('Profile Score','Score profil') ?></span>
          </div>
          <?php endif; ?>
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

        <!-- Phase 3: Subscription & Promotions quick-links -->
        <div class="feature-cards" style="margin-bottom:1.5rem;">
          <a href="<?= SITE_URL ?>/my-subscription" class="feature-card" style="<?= $subStatus==='active' ? 'border-color:rgba(0,168,120,0.35);' : '' ?>">
            <span class="feature-card-icon">💳</span>
            <div class="feature-card-text">
              <span class="feature-card-label"><?= t('My Subscription','Mon Abonnement') ?></span>
              <span class="feature-card-sub"><?php
                if ($subStatus === 'active')       echo '<span style="color:#00A878;">● </span>' . e($subPlanName) . ' ' . t('active','actif');
                elseif ($subStatus === 'pending_payment') echo t('Payment under review','Paiement en cours de vérification');
                elseif ($subStatus === 'pending')  echo t('Awaiting activation','En attente d\'activation');
                else                               echo t('Free — upgrade for more visibility','Gratuit — améliorez votre visibilité');
              ?></span>
            </div>
          </a>
          <a href="<?= SITE_URL ?>/my-promotions" class="feature-card" style="<?= $activePromos ? 'border-color:rgba(0,168,120,0.35);' : '' ?>">
            <span class="feature-card-icon">📣</span>
            <div class="feature-card-text">
              <span class="feature-card-label"><?= t('My Promotions','Mes Promotions') ?></span>
              <span class="feature-card-sub"><?php
                if ($activePromos && $pendingPromos)      echo $activePromos.' '.t('active','actives').', '.$pendingPromos.' '.t('pending','en attente');
                elseif ($activePromos)                    echo $activePromos.' '.t('active promotion(s)','promotion(s) active(s)');
                elseif ($pendingPromos)                   echo $pendingPromos.' '.t('pending review','en attente de validation');
                else                                      echo t('Boost your listing visibility','Boostez la visibilité de votre annonce');
              ?></span>
            </div>
            <?php if ($pendingPromos): ?><span class="feature-card-badge"><?= $pendingPromos ?></span><?php endif; ?>
          </a>
          <a href="<?= SITE_URL ?>/promote" class="feature-card">
            <span class="feature-card-icon">🚀</span>
            <div class="feature-card-text">
              <span class="feature-card-label"><?= t('Promote a Listing','Promouvoir une Annonce') ?></span>
              <span class="feature-card-sub"><?= t('Featured, event & offer slots','Emplacements vedette, événement & offre') ?></span>
            </div>
          </a>
          <?php if (!$subStatus || $subStatus === 'expired' || $subStatus === 'cancelled'): ?>
          <a href="<?= SITE_URL ?>/subscribe" class="feature-card" style="border-color:rgba(245,200,66,0.25);">
            <span class="feature-card-icon">⭐</span>
            <div class="feature-card-text">
              <span class="feature-card-label" style="color:var(--yellow);"><?= t('Upgrade Plan','Changer de Plan') ?></span>
              <span class="feature-card-sub"><?= t('Growth & Premium plans available','Plans Growth & Premium disponibles') ?></span>
            </div>
          </a>
          <?php endif; ?>
        </div>

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
                <?php if ((int)$l['featured'] === 1 && isset($listingEntitlements[$l['id']])): $ent = $listingEntitlements[$l['id']]; ?>
                <tr>
                  <td colspan="6" style="padding:0 0 0.6rem 1rem;border-top:none;">
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center;padding:0.5rem 0.75rem;background:rgba(0,168,120,0.04);border-radius:0 0 8px 8px;border:1px solid rgba(0,168,120,0.12);border-top:none;font-size:0.75rem;">
                      <span style="color:var(--muted);font-weight:600;letter-spacing:.04em;text-transform:uppercase;"><?= t('Limits','Limites') ?></span>
                      <?php
                      $usageDefs = [
                          ['label' => t('Offers','Offres'),    'icon' => '🏷️', 'used' => $ent['used_offers'],    'total' => $ent['total_offers'],    'link' => SITE_URL.'/offers?listing_id='.$l['id']],
                          ['label' => t('Events','Événements'),'icon' => '📅', 'used' => $ent['used_events'],    'total' => $ent['total_events'],    'link' => SITE_URL.'/events?listing_id='.$l['id']],
                          ['label' => t('Products','Produits'),'icon' => '🛍️', 'used' => $ent['used_products'],  'total' => $ent['total_products'],  'link' => SITE_URL.'/manage-products?listing_id='.$l['id']],
                      ];
                      foreach ($usageDefs as $ud):
                          $pct   = ($ud['total'] > 0) ? min(100, (int)round($ud['used'] / $ud['total'] * 100)) : 0;
                          $full  = ($ud['total'] > 0 && $ud['used'] >= $ud['total']);
                          $color = $full ? '#ef4444' : 'var(--accent)';
                      ?>
                      <a href="<?= $ud['link'] ?>" style="text-decoration:none;display:flex;align-items:center;gap:0.35rem;color:<?= $full ? '#ef4444' : 'var(--fg)' ?>;" title="<?= $ud['label'] ?>">
                        <span><?= $ud['icon'] ?></span>
                        <span style="white-space:nowrap;"><?= $ud['used'] ?>/<?= $ud['total'] ?: '∞' ?></span>
                        <?php if ($ud['total'] > 0): ?>
                        <div style="width:48px;height:5px;border-radius:99px;background:rgba(255,255,255,0.1);overflow:hidden;">
                          <div style="width:<?= $pct ?>%;height:100%;border-radius:99px;background:<?= $color ?>;"></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($full): ?><span style="color:#ef4444;font-size:.7rem;">⚠</span><?php endif; ?>
                      </a>
                      <?php endforeach; ?>
                      <?php if ($ent['addon_eligible']): ?>
                      <a href="<?= SITE_URL ?>/partner/buy-addon?listing_id=<?= $l['id'] ?>" style="margin-left:auto;font-size:0.72rem;color:var(--accent);text-decoration:none;white-space:nowrap;">+ <?= t('Add-ons','Extensions') ?></a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endif; ?>
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
