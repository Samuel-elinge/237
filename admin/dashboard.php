<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u = currentUser();

// User's listings
$myListings = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon, loc.name_en AS loc_en
    FROM listings l
    JOIN categories c  ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    WHERE l.user_id = ?
    ORDER BY l.created_at DESC
");
$myListings->execute([$u['id']]);
$myListings = $myListings->fetchAll();

// Pending upgrade payments
$pendingPaymentsByListing = [];
try {
    $pp = db()->prepare("SELECT listing_id FROM listing_payments WHERE user_id=? AND status='pending'");
    $pp->execute([$u['id']]);
    foreach ($pp->fetchAll() as $row) $pendingPaymentsByListing[$row['listing_id']] = true;
} catch (Exception $e) {}

$totalViews    = array_sum(array_column($myListings, 'views'));
$totalApproved = count(array_filter($myListings, fn($l) => $l['status'] === 'approved'));
$totalPending  = count(array_filter($myListings, fn($l) => $l['status'] === 'pending'));

// Total enquiries received
$totalEnquiries = 0;
try {
    $eq = db()->prepare("SELECT COUNT(*) FROM listing_enquiries WHERE listing_id IN (SELECT id FROM listings WHERE user_id=?)");
    $eq->execute([$u['id']]);
    $totalEnquiries = (int)$eq->fetchColumn();
} catch (Exception $e) {}

// Announcements count
$announcementCount = 0;
try {
    $an = db()->prepare("SELECT COUNT(*) FROM announcements WHERE user_id=? AND active=1");
    $an->execute([$u['id']]);
    $announcementCount = (int)$an->fetchColumn();
} catch (Exception $e) {}

// Bookings count
$bookingCount = 0;
try {
    $bk = db()->prepare("SELECT COUNT(*) FROM bookings WHERE listing_id IN (SELECT id FROM listings WHERE user_id=?) AND status='pending'");
    $bk->execute([$u['id']]);
    $bookingCount = (int)$bk->fetchColumn();
} catch (Exception $e) {}

// Service orders
$myOrders = db()->prepare("
    SELECT so.*, s.name_en AS svc_name, s.icon
    FROM service_orders so JOIN services s ON s.id=so.service_id
    WHERE so.user_id=? ORDER BY so.created_at DESC LIMIT 10
");
$myOrders->execute([$u['id']]);
$myOrders = $myOrders->fetchAll();

$pageTitle = t('My Dashboard — 237Biz', 'Mon Tableau de Bord — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

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
          <a href="<?= SITE_URL ?>/dashboard" class="<?= !isset($_GET['page']) ? 'active' : '' ?>">
            📋 <?= t('My Listings','Mes Annonces') ?>
          </a>
          <a href="<?= SITE_URL ?>/add-listing">
            + <?= t('Add Listing','Ajouter Annonce') ?>
          </a>
          <a href="<?= SITE_URL ?>/dashboard?page=analytics">
            📊 <?= t('Analytics','Analytiques') ?>
          </a>
          <a href="<?= SITE_URL ?>/dashboard?page=announcements" <?= isset($_GET['page']) && $_GET['page']==='announcements' ? 'class="active"' : '' ?>>
            📣 <?= t('Announcements','Annonces') ?>
            <?php if ($announcementCount > 0): ?>
              <span style="background:var(--green);color:#fff;font-size:0.65rem;padding:0.1rem 0.4rem;border-radius:20px;margin-left:auto;"><?= $announcementCount ?></span>
            <?php endif; ?>
          </a>
          <a href="<?= SITE_URL ?>/dashboard?page=bookings" <?= isset($_GET['page']) && $_GET['page']==='bookings' ? 'class="active"' : '' ?>>
            📅 <?= t('Bookings','Réservations') ?>
            <?php if ($bookingCount > 0): ?>
              <span style="background:var(--yellow);color:#0D1F16;font-size:0.65rem;padding:0.1rem 0.4rem;border-radius:20px;margin-left:auto;"><?= $bookingCount ?></span>
            <?php endif; ?>
          </a>
          <a href="<?= SITE_URL ?>/dashboard?page=qrcode" <?= isset($_GET['page']) && $_GET['page']==='qrcode' ? 'class="active"' : '' ?>>
            🔲 <?= t('QR Code','Code QR') ?>
          </a>
          <a href="<?= SITE_URL ?>/profile.php">
            👤 <?= t('My Profile','Mon Profil') ?>
          </a>
          <?php if (isAdmin()): ?>
            <a href="<?= SITE_URL ?>/admin/">🔧 <?= t('Admin Panel','Panneau Admin') ?></a>
          <?php endif; ?>
          <a href="<?= SITE_URL ?>/logout" style="margin-top:1rem;color:rgba(255,100,100,0.7);">
            ⬅ <?= t('Sign Out','Déconnexion') ?>
          </a>
        </nav>
      </aside>

      <!-- MAIN CONTENT -->
      <div>

        <?php $page = $_GET['page'] ?? 'listings'; ?>

        <?php if ($page === 'listings'): ?>
        <!-- ══ MY LISTINGS ══ -->
        <div class="dash-stats-grid" style="margin-bottom:1.5rem;">
          <div class="dash-stat"><strong><?= count($myListings) ?></strong><span><?= t('Total Listings','Annonces totales') ?></span></div>
          <div class="dash-stat"><strong><?= $totalApproved ?></strong><span><?= t('Published','Publiées') ?></span></div>
          <div class="dash-stat"><strong><?= number_format($totalViews) ?></strong><span><?= t('Total Views','Vues totales') ?></span></div>
          <div class="dash-stat"><strong><?= $totalEnquiries ?></strong><span><?= t('Enquiries','Demandes') ?></span></div>
        </div>

        <div class="listing-widget">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
            <h4 style="margin:0;"><?= t('My Listings','Mes Annonces') ?></h4>
            <a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary btn-sm">+ <?= t('Add New','Ajouter') ?></a>
          </div>

          <?php if ($myListings): ?>
            <div style="overflow-x:auto;">
              <table class="data-table">
                <thead><tr>
                  <th><?= t('Business','Entreprise') ?></th>
                  <th><?= t('Category','Catégorie') ?></th>
                  <th><?= t('Location','Ville') ?></th>
                  <th><?= t('Status','Statut') ?></th>
                  <th><?= t('Views','Vues') ?></th>
                  <th><?= t('Actions','Actions') ?></th>
                </tr></thead>
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
                      <td style="font-size:0.82rem;color:var(--muted);"><?= e($l['cat_en']) ?></td>
                      <td style="font-size:0.82rem;color:var(--muted);">📍 <?= e($l['loc_en']) ?></td>
                      <td>
                        <span class="badge badge-<?= $l['status'] ?>">
                          <?= t(ucfirst($l['status']), ['approved'=>'Approuvé','pending'=>'En attente','rejected'=>'Rejeté'][$l['status']] ?? $l['status']) ?>
                        </span>
                      </td>
                      <td style="font-size:0.82rem;"><?= number_format($l['views']) ?></td>
                      <td>
                        <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                          <?php if ($l['status'] === 'approved'): ?>
                            <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>" class="btn btn-outline btn-sm"><?= t('View','Voir') ?></a>
                          <?php endif; ?>
                          <a href="<?= SITE_URL ?>/edit-listing?id=<?= $l['id'] ?>" class="btn btn-primary btn-sm"><?= t('Edit','Modifier') ?></a>
                          <?php if ((int)$l['featured'] === 1): ?>
                            <a href="<?= SITE_URL ?>/manage-products?listing_id=<?= $l['id'] ?>" class="btn btn-sm" style="background:rgba(0,168,120,0.12);color:var(--green);border:1px solid rgba(0,168,120,0.25);">🛍️</a>
                          <?php elseif (isset($pendingPaymentsByListing[$l['id']])): ?>
                            <span class="btn btn-sm" style="background:rgba(255,255,255,0.05);color:var(--muted-2);border:1px solid var(--border);cursor:default;">⏳</span>
                          <?php else: ?>
                            <a href="<?= SITE_URL ?>/upgrade-listing?listing_id=<?= $l['id'] ?>" class="btn btn-sm" style="background:rgba(245,200,66,0.15);color:var(--yellow);border:1px solid rgba(245,200,66,0.3);">⭐ <?= t('Upgrade','Améliorer') ?></a>
                          <?php endif; ?>
                          <a href="<?= SITE_URL ?>/dashboard?page=analytics&listing_id=<?= $l['id'] ?>" class="btn btn-sm btn-outline" title="Analytics">📊</a>
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
        <?php if ($myOrders): ?>
        <div class="listing-widget" style="margin-top:1.5rem;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
            <h4 style="margin:0;">🛒 <?= t('My Service Orders','Mes Commandes') ?></h4>
            <a href="<?= SITE_URL ?>/services" class="btn btn-outline btn-sm"><?= t('Order a Service','Commander un Service') ?></a>
          </div>
          <table class="data-table">
            <thead><tr>
              <th><?= t('Ref','Réf') ?></th>
              <th><?= t('Service','Service') ?></th>
              <th><?= t('Amount','Montant') ?></th>
              <th><?= t('Status','Statut') ?></th>
              <th><?= t('Date','Date') ?></th>
            </tr></thead>
            <tbody>
              <?php foreach ($myOrders as $o): ?>
                <tr>
                  <td style="font-size:0.78rem;color:var(--yellow);"><?= e($o['ref']) ?></td>
                  <td><?= $o['icon'] ?> <?= e($o['svc_name']) ?></td>
                  <td style="font-family:'Fraunces',serif;font-weight:700;"><?= number_format($o['amount_xaf']) ?> XAF</td>
                  <td><span class="badge badge-<?= in_array($o['status'],['active','approved'])?'approved':($o['status']==='pending'?'pending':'rejected') ?>"><?= ucfirst($o['status']) ?></span></td>
                  <td style="font-size:0.78rem;color:var(--muted-2);"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <?php elseif ($page === 'analytics'): ?>
        <!-- ══ ANALYTICS ══ -->
        <h4 style="margin-bottom:1.25rem;">📊 <?= t('Analytics','Analytiques') ?></h4>
        <?php if ($myListings): ?>
          <?php foreach ($myListings as $l): if ($l['status'] !== 'approved') continue; ?>
          <div class="listing-widget" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;">
              <div>
                <h5 style="margin:0;color:var(--white);"><?= e($l['cat_icon']) ?> <?= e($l['title']) ?></h5>
                <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>" style="font-size:0.75rem;color:var(--green);">View listing →</a>
              </div>
              <a href="<?= SITE_URL ?>/analytics?id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">Full Analytics →</a>
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;">
              <div class="dash-stat">
                <strong style="font-size:1.4rem;"><?= number_format($l['views']) ?></strong>
                <span><?= t('Total Views','Vues totales') ?></span>
              </div>
              <div class="dash-stat">
                <?php
                try {
                    $eq2 = db()->prepare("SELECT COUNT(*) FROM listing_enquiries WHERE listing_id=?");
                    $eq2->execute([$l['id']]);
                    $enqCount = $eq2->fetchColumn();
                } catch (Exception $e) { $enqCount = 0; }
                ?>
                <strong style="font-size:1.4rem;"><?= $enqCount ?></strong>
                <span><?= t('Enquiries','Demandes') ?></span>
              </div>
              <div class="dash-stat">
                <strong style="font-size:1.4rem;color:<?= (int)$l['featured'] ? 'var(--yellow)' : 'var(--muted-2)' ?>;">
                  <?= (int)$l['featured'] ? '⭐ '.t('Featured','Vedette') : t('Free','Gratuit') ?>
                </strong>
                <span><?= t('Plan','Forfait') ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
            <p style="color:var(--muted);"><?= t('No approved listings to show analytics for.','Aucune annonce approuvée pour afficher les analytiques.') ?></p>
          </div>
        <?php endif; ?>

        <?php elseif ($page === 'announcements'): ?>
        <!-- ══ ANNOUNCEMENTS ══ -->
        <?php
        // Handle save announcement
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'announcement') {
            verifyCsrf();
            $listingId = (int)($_POST['listing_id'] ?? 0);
            $title     = trim($_POST['ann_title'] ?? '');
            $content   = trim($_POST['ann_content'] ?? '');
            $type      = in_array($_POST['ann_type'] ?? '', ['promotion','event','update','offer']) ? $_POST['ann_type'] : 'update';
            $expiresAt = trim($_POST['expires_at'] ?? '') ?: null;
            $annId     = (int)($_POST['ann_id'] ?? 0);

            // Verify listing belongs to user
            $chk = db()->prepare("SELECT id FROM listings WHERE id=? AND user_id=?");
            $chk->execute([$listingId, $u['id']]);
            if ($chk->fetchColumn() && $title && $content) {
                try {
                    if ($annId) {
                        db()->prepare("UPDATE announcements SET listing_id=?,title=?,content=?,type=?,expires_at=? WHERE id=? AND user_id=?")
                             ->execute([$listingId,$title,$content,$type,$expiresAt,$annId,$u['id']]);
                    } else {
                        db()->prepare("INSERT INTO announcements (user_id,listing_id,title,content,type,expires_at) VALUES (?,?,?,?,?,?)")
                             ->execute([$u['id'],$listingId,$title,$content,$type,$expiresAt]);
                    }
                    flash('success', t('Announcement saved!','Annonce enregistrée !'));
                } catch (Exception $e) {
                    // Create table if not exists
                    db()->exec("CREATE TABLE IF NOT EXISTS `announcements` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `user_id` INT UNSIGNED NOT NULL,
                        `listing_id` INT UNSIGNED NOT NULL,
                        `title` VARCHAR(200) NOT NULL,
                        `content` TEXT NOT NULL,
                        `type` ENUM('promotion','event','update','offer') DEFAULT 'update',
                        `active` TINYINT(1) DEFAULT 1,
                        `expires_at` DATETIME DEFAULT NULL,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    db()->prepare("INSERT INTO announcements (user_id,listing_id,title,content,type,expires_at) VALUES (?,?,?,?,?,?)")
                         ->execute([$u['id'],$listingId,$title,$content,$type,$expiresAt]);
                    flash('success', t('Announcement saved!','Annonce enregistrée !'));
                }
                redirect(SITE_URL . '/dashboard?page=announcements');
            }
        }

        if ($_GET['delete_ann'] ?? '') {
            $annId = (int)$_GET['delete_ann'];
            try { db()->prepare("DELETE FROM announcements WHERE id=? AND user_id=?")->execute([$annId, $u['id']]); } catch (Exception $e) {}
            redirect(SITE_URL . '/dashboard?page=announcements');
        }

        $announcements = [];
        try {
            $anSt = db()->prepare("SELECT a.*, l.title AS listing_title FROM announcements a LEFT JOIN listings l ON l.id=a.listing_id WHERE a.user_id=? ORDER BY a.created_at DESC");
            $anSt->execute([$u['id']]);
            $announcements = $anSt->fetchAll();
        } catch (Exception $e) {}

        $fs = flash('success');
        ?>
        <?php if ($fs): ?><div class="flash flash-success" style="margin-bottom:1rem;">✅ <?= e($fs) ?></div><?php endif; ?>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:0.5rem;">
          <h4 style="margin:0;">📣 <?= t('Announcements','Annonces & Promotions') ?></h4>
        </div>

        <!-- New announcement form -->
        <div class="listing-widget" style="margin-bottom:1.5rem;border-color:rgba(0,168,120,0.25);">
          <h5 style="margin-bottom:1rem;">+ <?= t('New Announcement','Nouvelle Annonce') ?></h5>
          <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="form" value="announcement">
            <input type="hidden" name="ann_id" value="0">
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Title *','Titre *') ?></label>
                <input type="text" name="ann_title" required placeholder="<?= t('e.g. 20% off this weekend!','ex. 20% de réduction ce week-end !') ?>">
              </div>
              <div class="form-group">
                <label><?= t('Type','Type') ?></label>
                <select name="ann_type" style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.6rem 0.75rem;width:100%;">
                  <option value="promotion">🏷️ <?= t('Promotion','Promotion') ?></option>
                  <option value="offer">🎁 <?= t('Special Offer','Offre Spéciale') ?></option>
                  <option value="event">📅 <?= t('Event','Événement') ?></option>
                  <option value="update">📢 <?= t('Update','Mise à jour') ?></option>
                </select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Listing','Annonce') ?></label>
                <select name="listing_id" required style="background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.6rem 0.75rem;width:100%;">
                  <option value="">— <?= t('Select listing','Sélectionner une annonce') ?> —</option>
                  <?php foreach ($myListings as $l): if ($l['status'] !== 'approved') continue; ?>
                    <option value="<?= $l['id'] ?>"><?= e($l['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label><?= t('Expires (optional)','Expire le (optionnel)') ?></label>
                <input type="datetime-local" name="expires_at">
              </div>
            </div>
            <div class="form-group">
              <label><?= t('Content *','Contenu *') ?></label>
              <textarea name="ann_content" required rows="3" placeholder="<?= t('Describe your promotion, event, or update...','Décrivez votre promotion, événement ou mise à jour...') ?>"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><?= t('Post Announcement','Publier l\'annonce') ?></button>
          </form>
        </div>

        <!-- Existing announcements -->
        <?php if ($announcements): ?>
          <div style="display:flex;flex-direction:column;gap:0.75rem;">
            <?php foreach ($announcements as $ann): ?>
              <?php
              $typeColors = ['promotion'=>'#F5C842','offer'=>'#00A878','event'=>'#00bcd4','update'=>'#aaa'];
              $typeEmojis = ['promotion'=>'🏷️','offer'=>'🎁','event'=>'📅','update'=>'📢'];
              $expired = $ann['expires_at'] && strtotime($ann['expires_at']) < time();
              ?>
              <div class="listing-widget" style="<?= $expired ? 'opacity:0.5;' : '' ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                  <div style="flex:1;">
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.35rem;flex-wrap:wrap;">
                      <span style="font-size:0.72rem;background:rgba(255,255,255,0.06);color:<?= $typeColors[$ann['type']] ?? '#aaa' ?>;padding:0.15rem 0.6rem;border-radius:20px;">
                        <?= $typeEmojis[$ann['type']] ?? '📢' ?> <?= ucfirst($ann['type']) ?>
                      </span>
                      <?php if ($expired): ?>
                        <span style="font-size:0.68rem;color:#ff6b6b;">Expired</span>
                      <?php elseif ($ann['expires_at']): ?>
                        <span style="font-size:0.68rem;color:var(--muted-2);">Expires <?= date('d M Y', strtotime($ann['expires_at'])) ?></span>
                      <?php endif; ?>
                      <span style="font-size:0.68rem;color:var(--muted-2);"><?= e($ann['listing_title']) ?></span>
                    </div>
                    <div style="font-weight:500;color:var(--white);"><?= e($ann['title']) ?></div>
                    <div style="font-size:0.82rem;color:var(--muted);margin-top:0.25rem;"><?= e(mb_substr($ann['content'],0,120)) ?><?= mb_strlen($ann['content'])>120?'…':'' ?></div>
                  </div>
                  <div style="display:flex;gap:0.35rem;flex-shrink:0;">
                    <a href="?page=announcements&delete_ann=<?= $ann['id'] ?>" onclick="return confirm('<?= t('Delete this announcement?','Supprimer cette annonce ?') ?>')"
                       class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);">✗ <?= t('Delete','Supprimer') ?></a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
            <div style="font-size:2.5rem;margin-bottom:0.75rem;">📣</div>
            <p style="color:var(--muted);"><?= t('No announcements yet. Post your first promotion above!','Aucune annonce encore. Publiez votre première promotion ci-dessus !') ?></p>
          </div>
        <?php endif; ?>

        <?php elseif ($page === 'bookings'): ?>
        <!-- ══ BOOKINGS ══ -->
        <?php
        // Handle booking action
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'booking_action') {
            verifyCsrf();
            $bookingId = (int)($_POST['booking_id'] ?? 0);
            $action    = $_POST['booking_action'] ?? '';
            if (in_array($action, ['confirm','decline','complete'])) {
                try {
                    $statusMap = ['confirm'=>'confirmed','decline'=>'declined','complete'=>'completed'];
                    db()->prepare("UPDATE bookings SET status=? WHERE id=? AND listing_id IN (SELECT id FROM listings WHERE user_id=?)")
                         ->execute([$statusMap[$action], $bookingId, $u['id']]);
                    flash('success', t('Booking updated.','Réservation mise à jour.'));
                } catch (Exception $e) {}
            }
            redirect(SITE_URL . '/dashboard?page=bookings');
        }

        $bookings = [];
        try {
            $bkSt = db()->prepare("
                SELECT b.*, l.title AS listing_title
                FROM bookings b
                JOIN listings l ON l.id = b.listing_id
                WHERE l.user_id = ?
                ORDER BY b.booking_date DESC, b.created_at DESC
                LIMIT 50
            ");
            $bkSt->execute([$u['id']]);
            $bookings = $bkSt->fetchAll();
        } catch (Exception $e) {}

        $bkFs = flash('success');
        ?>
        <?php if ($bkFs): ?><div class="flash flash-success" style="margin-bottom:1rem;">✅ <?= e($bkFs) ?></div><?php endif; ?>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
          <h4 style="margin:0;">📅 <?= t('Appointment Bookings','Demandes de Rendez-vous') ?></h4>
          <?php if ($bookingCount > 0): ?>
            <span style="background:rgba(245,200,66,0.15);color:var(--yellow);border:1px solid rgba(245,200,66,0.3);border-radius:20px;padding:0.25rem 0.75rem;font-size:0.78rem;">
              <?= $bookingCount ?> <?= t('pending','en attente') ?>
            </span>
          <?php endif; ?>
        </div>

        <?php if ($bookings): ?>
          <div style="display:flex;flex-direction:column;gap:0.75rem;">
            <?php foreach ($bookings as $bk): ?>
              <?php $statusColors = ['pending'=>'pending','confirmed'=>'approved','declined'=>'rejected','completed'=>'approved']; ?>
              <div class="listing-widget">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                  <div style="flex:1;">
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.35rem;flex-wrap:wrap;">
                      <span class="badge badge-<?= $statusColors[$bk['status']] ?? 'pending' ?>"><?= ucfirst($bk['status']) ?></span>
                      <span style="font-size:0.72rem;color:var(--muted-2);"><?= e($bk['listing_title']) ?></span>
                    </div>
                    <div style="font-weight:500;color:var(--white);"><?= e($bk['customer_name'] ?? 'Customer') ?></div>
                    <div style="font-size:0.82rem;color:var(--muted);margin-top:0.25rem;">
                      📧 <?= e($bk['customer_email'] ?? '') ?>
                      <?php if (!empty($bk['customer_phone'])): ?> · 📞 <?= e($bk['customer_phone']) ?><?php endif; ?>
                    </div>
                    <div style="font-size:0.78rem;color:var(--yellow);margin-top:0.25rem;">
                      📅 <?= !empty($bk['booking_date']) ? date('d M Y', strtotime($bk['booking_date'])) : '' ?>
                      <?php if (!empty($bk['booking_time'])): ?> at <?= e($bk['booking_time']) ?><?php endif; ?>
                    </div>
                    <?php if (!empty($bk['notes'])): ?>
                      <div style="font-size:0.78rem;color:var(--muted-2);margin-top:0.2rem;"><?= e(mb_substr($bk['notes'],0,100)) ?></div>
                    <?php endif; ?>
                  </div>
                  <?php if ($bk['status'] === 'pending'): ?>
                    <div style="display:flex;gap:0.35rem;flex-shrink:0;">
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                        <input type="hidden" name="form" value="booking_action">
                        <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
                        <button name="booking_action" value="confirm" class="btn btn-sm" style="background:rgba(0,168,120,0.15);color:var(--green);border:1px solid rgba(0,168,120,0.3);">✓ <?= t('Confirm','Confirmer') ?></button>
                        <button name="booking_action" value="decline" class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);">✗ <?= t('Decline','Refuser') ?></button>
                      </form>
                    </div>
                  <?php elseif ($bk['status'] === 'confirmed'): ?>
                    <form method="POST">
                      <input type="hidden" name="csrf" value="<?= csrf() ?>">
                      <input type="hidden" name="form" value="booking_action">
                      <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
                      <button name="booking_action" value="complete" class="btn btn-sm btn-outline">✓ <?= t('Mark Complete','Marquer complet') ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
            <div style="font-size:2.5rem;margin-bottom:0.75rem;">📅</div>
            <h4 style="font-family:'Fraunces',serif;"><?= t('No bookings yet','Aucune réservation encore') ?></h4>
            <p style="color:var(--muted);font-size:0.85rem;"><?= t('When customers book appointments through your listing, they will appear here.','Quand des clients réservent des rendez-vous via votre annonce, ils apparaîtront ici.') ?></p>
          </div>
        <?php endif; ?>

        <?php elseif ($page === 'qrcode'): ?>
        <!-- ══ QR CODE ══ -->
        <h4 style="margin-bottom:1.5rem;">🔲 <?= t('QR Codes','Codes QR') ?></h4>
        <?php if ($myListings): ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem;">
            <?php foreach ($myListings as $l): if ($l['status'] !== 'approved') continue; ?>
              <?php $listingUrl = SITE_URL . '/listing/' . $l['slug']; ?>
              <div class="listing-widget" style="text-align:center;">
                <div style="font-size:1rem;font-weight:500;color:var(--white);margin-bottom:1rem;"><?= e($l['cat_icon']) ?> <?= e($l['title']) ?></div>
                <!-- QR using Google Charts API -->
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($listingUrl) ?>&bgcolor=0D1F16&color=00A878&margin=10"
                     alt="QR Code for <?= e($l['title']) ?>"
                     style="width:180px;height:180px;border-radius:10px;border:1px solid var(--border);">
                <div style="margin-top:1rem;display:flex;gap:0.5rem;justify-content:center;flex-wrap:wrap;">
                  <a href="https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=<?= urlencode($listingUrl) ?>&bgcolor=0D1F16&color=00A878&margin=10"
                     download="qr-<?= e($l['slug']) ?>.png"
                     class="btn btn-primary btn-sm">⬇️ <?= t('Download','Télécharger') ?></a>
                  <button onclick="navigator.clipboard.writeText('<?= e($listingUrl) ?>').then(function(){this.textContent='✓ Copied!';var b=this;setTimeout(function(){b.textContent='🔗 Copy URL'},2000)}.bind(this))"
                          class="btn btn-outline btn-sm">🔗 <?= t('Copy URL','Copier URL') ?></button>
                </div>
                <p style="font-size:0.72rem;color:var(--muted-2);margin-top:0.75rem;"><?= t('Print this QR code and display it in your shop so customers can find you on 237Biz.','Imprimez ce code QR et affichez-le dans votre boutique pour que les clients vous trouvent sur 237Biz.') ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
            <div style="font-size:2.5rem;margin-bottom:0.75rem;">🔲</div>
            <p style="color:var(--muted);"><?= t('You need an approved listing to generate a QR code.','Vous avez besoin d\'une annonce approuvée pour générer un code QR.') ?></p>
            <a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary" style="margin-top:1rem;">+ <?= t('Add Listing','Ajouter Annonce') ?></a>
          </div>
        <?php endif; ?>

        <?php endif; ?>

      </div><!-- end main -->
    </div><!-- end dashboard-grid -->
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
