<?php
/**
 * Business: Manage & Redeem Offer Codes
 * Accessible to listing owners (role = business or admin).
 * Lets them: view claimed codes per offer, validate a code, mark it redeemed.
 */
require_once __DIR__ . '/includes/config.php';

// ── Auth ──────────────────────────────────────────────────────────────────
if (!isLoggedIn()) {
    flash('info', t('Please sign in to manage your offer codes.',
                    'Connectez-vous pour gérer vos codes d\'offre.'));
    redirect(SITE_URL . '/login.php?mode=login&next=' . urlencode(SITE_URL . '/manage-offer-codes.php'));
    exit;
}
$me = currentUser();
if (!in_array($me['role'] ?? '', ['business','admin'])) {
    flash('error', t('Access denied.','Accès refusé.'));
    redirect(SITE_URL . '/dashboard');
    exit;
}

$userId = (int)$me['id'];
$msg    = '';
$msgType = 'success';

// ── Load owner's listings ─────────────────────────────────────────────────
$myListings = [];
try {
    $lq = db()->prepare("SELECT id, title FROM listings WHERE user_id = ? AND status = 'active' ORDER BY title");
    $lq->execute([$userId]);
    $myListings = $lq->fetchAll();
} catch (Exception $e) {}

// ── Selected listing ──────────────────────────────────────────────────────
$selectedListingId = (int)($_GET['listing_id'] ?? ($myListings[0]['id'] ?? 0));
$selectedListing   = null;
foreach ($myListings as $l) {
    if ((int)$l['id'] === $selectedListingId) { $selectedListing = $l; break; }
}

// ── POST: redeem a code ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf();

    if ($_POST['action'] === 'redeem_code') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        if (!$code) {
            $msg = t('Please enter a discount code.', 'Veuillez saisir un code de réduction.');
            $msgType = 'error';
        } else {
            try {
                // Verify code belongs to one of this owner's offers
                $vq = db()->prepare("
                    SELECT dc.id, dc.code, dc.status, dc.offer_id, dc.user_id AS customer_id,
                           o.title AS offer_title, o.title_fr AS offer_title_fr,
                           o.listing_id, l.title AS biz_title,
                           u.name AS customer_name, u.email AS customer_email
                    FROM discount_codes dc
                    JOIN offers o ON o.id = dc.offer_id
                    JOIN listings l ON l.id = o.listing_id
                    LEFT JOIN users u ON u.id = dc.user_id
                    WHERE dc.code = ? AND l.user_id = ?
                    LIMIT 1
                ");
                $vq->execute([$code, $userId]);
                $codeRow = $vq->fetch();

                if (!$codeRow) {
                    $msg = t('Code not found or does not belong to your listings.',
                             'Code introuvable ou n\'appartient pas à vos établissements.');
                    $msgType = 'error';
                } elseif ($codeRow['status'] === 'redeemed') {
                    $msg = t('This code has already been used.', 'Ce code a déjà été utilisé.');
                    $msgType = 'error';
                } elseif ($codeRow['status'] !== 'active') {
                    $msg = t('This code is no longer active.', 'Ce code n\'est plus actif.');
                    $msgType = 'error';
                } else {
                    // Mark redeemed
                    db()->prepare("
                        UPDATE discount_codes
                        SET status='redeemed', redeemed_at=NOW(), redeemed_by=?
                        WHERE id=?
                    ")->execute([$userId, $codeRow['id']]);
                    $offerTitle = ($isFr && !empty($codeRow['offer_title_fr']))
                        ? $codeRow['offer_title_fr'] : $codeRow['offer_title'];
                    $msg = t('✅ Code redeemed successfully!', '✅ Code validé avec succès !') . ' — '
                         . e($code) . ' (' . e($codeRow['customer_name'] ?: $codeRow['customer_email']) . ')';
                    $msgType = 'success';
                }
            } catch (Exception $e) {
                $msg = t('Error processing code. Please try again.', 'Erreur lors du traitement du code. Veuillez réessayer.');
                $msgType = 'error';
            }
        }
    }
}

// ── Load offers + claimed codes for selected listing ──────────────────────
$offersWithCodes = [];
if ($selectedListingId) {
    try {
        $ofq = db()->prepare("SELECT id, title, title_fr, slug, status, expires_at, max_redemptions, redemption_count FROM offers WHERE listing_id = ? ORDER BY created_at DESC");
        $ofq->execute([$selectedListingId]);
        $offers = $ofq->fetchAll();

        foreach ($offers as $offer) {
            $codesQ = db()->prepare("
                SELECT dc.code, dc.status, dc.created_at AS claimed_at, dc.redeemed_at,
                       u.name AS customer_name, u.email AS customer_email
                FROM discount_codes dc
                LEFT JOIN users u ON u.id = dc.user_id
                WHERE dc.offer_id = ?
                ORDER BY dc.created_at DESC
            ");
            $codesQ->execute([$offer['id']]);
            $codes = $codesQ->fetchAll();
            $offersWithCodes[] = ['offer' => $offer, 'codes' => $codes];
        }
    } catch (Exception $e) {}
}

// ── Page ──────────────────────────────────────────────────────────────────
$pageTitle = t('Manage Offer Codes', 'Gérer les codes d\'offre');
require_once __DIR__ . '/includes/header.php';
?>

<style>
.roc-wrap { max-width: 1000px; margin: 0 auto; padding: 2rem 1rem 4rem; }
.roc-header { margin-bottom: 1.75rem; }
.roc-header h1 { font-size: 1.6rem; font-weight: 700; color: var(--white); margin: 0 0 .35rem; }
.roc-header p  { color: var(--muted-2); margin: 0; font-size: .9rem; }

/* Redeem box */
.roc-redeem-box { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 1.5rem; margin-bottom: 2rem; }
.roc-redeem-box h3 { color: var(--white); font-size: 1.05rem; font-weight: 600; margin: 0 0 1rem; }
.roc-redeem-row { display: flex; gap: .75rem; flex-wrap: wrap; }
.roc-redeem-row input { flex: 1; min-width: 180px; background: var(--surface-2); border: 1px solid var(--border); color: var(--white); padding: .65rem 1rem; border-radius: 6px; font-size: .95rem; font-family: monospace; letter-spacing: .04em; text-transform: uppercase; }
.roc-redeem-row input::placeholder { text-transform: none; letter-spacing: 0; color: var(--muted-2); }
.roc-redeem-row input:focus { outline: none; border-color: #00A878; }
.roc-redeem-row button { background: #00A878; color: #fff; border: none; border-radius: 6px; padding: .65rem 1.5rem; font-size: .95rem; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background .15s; }
.roc-redeem-row button:hover { background: #008c65; }

/* Listing picker */
.roc-listing-picker { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.roc-listing-btn { background: var(--surface); border: 1px solid var(--border); color: var(--muted-2); padding: .4rem .9rem; border-radius: 20px; font-size: .85rem; cursor: pointer; text-decoration: none; transition: all .15s; }
.roc-listing-btn:hover, .roc-listing-btn.active { background: #00A878; border-color: #00A878; color: #fff; }

/* Offer section */
.roc-offer-section { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 1.25rem; overflow: hidden; }
.roc-offer-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.roc-offer-head h4 { color: var(--white); font-size: .95rem; font-weight: 600; margin: 0; }
.roc-offer-meta { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }

/* Codes table */
.roc-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.roc-table th { text-align: left; color: var(--muted-2); font-weight: 500; padding: .6rem 1.25rem; background: var(--surface-2); font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
.roc-table td { padding: .65rem 1.25rem; border-top: 1px solid var(--border); color: var(--white); vertical-align: middle; }
.roc-table tr:hover td { background: rgba(255,255,255,.02); }

code.roc-code { background: rgba(0,168,120,.1); border: 1px dashed rgba(0,168,120,.3); color: #00A878; padding: .2rem .5rem; border-radius: 4px; font-size: .88rem; letter-spacing: .04em; user-select: all; }

.badge-active   { background: rgba(0,168,120,.15); color: #00A878; border: 1px solid rgba(0,168,120,.3); }
.badge-redeemed { background: rgba(255,255,255,.08); color: var(--muted-2); border: 1px solid var(--border); }
.badge-expired  { background: rgba(220,80,80,.12); color: #e06060; border: 1px solid rgba(220,80,80,.25); }
.badge-cancelled{ background: rgba(255,255,255,.06); color: var(--muted-2); border: 1px solid var(--border); }

.roc-badge { display: inline-block; font-size: .72rem; font-weight: 600; padding: .18rem .55rem; border-radius: 12px; text-transform: uppercase; letter-spacing: .03em; }

.roc-empty { text-align: center; padding: 2rem; color: var(--muted-2); font-size: .9rem; }
.flash-success { background: rgba(0,168,120,.12); border: 1px solid rgba(0,168,120,.3); color: #00A878; border-radius: 8px; padding: .85rem 1.1rem; margin-bottom: 1.25rem; font-size: .9rem; }
.flash-error   { background: rgba(220,80,80,.1); border: 1px solid rgba(220,80,80,.25); color: #e06060; border-radius: 8px; padding: .85rem 1.1rem; margin-bottom: 1.25rem; font-size: .9rem; }
</style>

<section class="roc-wrap">
  <div class="roc-header">
    <h1>🎟️ <?= $pageTitle ?></h1>
    <p><?= t('Validate and track discount codes claimed by customers.',
             'Validez et suivez les codes de réduction réclamés par les clients.') ?></p>
  </div>

  <?php if ($msg): ?>
  <div class="flash-<?= $msgType === 'error' ? 'error' : 'success' ?>"><?= $msg ?></div>
  <?php endif; ?>

  <!-- ── Redeem a code ── -->
  <div class="roc-redeem-box">
    <h3>🔍 <?= t('Validate a Code','Valider un code') ?></h3>
    <p style="color:var(--muted-2);font-size:.85rem;margin:0 0 1rem;"><?= t('Enter the code the customer shows you to mark it as used.','Saisissez le code que le client vous présente pour le marquer comme utilisé.') ?></p>
    <form method="POST" action="<?= SITE_URL ?>/manage-offer-codes.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="redeem_code">
      <div class="roc-redeem-row">
        <input type="text" name="code" placeholder="237-XXXXXX" maxlength="20" pattern="237-[A-Fa-f0-9]{6}" title="Format: 237-XXXXXX" autocomplete="off" autofocus>
        <button type="submit">✅ <?= t('Mark as Used','Marquer comme utilisé') ?></button>
      </div>
    </form>
  </div>

  <!-- ── Listing selector ── -->
  <?php if (count($myListings) > 1): ?>
  <div class="roc-listing-picker">
    <?php foreach ($myListings as $l): ?>
    <a href="?listing_id=<?= $l['id'] ?>" class="roc-listing-btn <?= (int)$l['id'] === $selectedListingId ? 'active' : '' ?>"><?= e($l['title']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Offers + codes ── -->
  <?php if (!$selectedListing): ?>
    <div class="roc-empty"><?= t('No active listings found. Set up your business listing first.','Aucun établissement actif trouvé. Configurez votre fiche d\'abord.') ?></div>
  <?php elseif (!$offersWithCodes): ?>
    <div class="roc-empty">
      <?= t('No offers found for','Aucune offre pour') ?> <strong><?= e($selectedListing['title']) ?></strong>.
      <a href="<?= SITE_URL ?>/admin/offers" style="color:#00A878;margin-left:.5rem;"><?= t('Add an offer →','Ajouter une offre →') ?></a>
    </div>
  <?php else: ?>

    <?php foreach ($offersWithCodes as $row):
      $offer = $row['offer'];
      $codes = $row['codes'];
      $offerTitle = ($isFr && !empty($offer['title_fr'])) ? $offer['title_fr'] : $offer['title'];
      $activeCount   = count(array_filter($codes, fn($c) => $c['status'] === 'active'));
      $redeemedCount = count(array_filter($codes, fn($c) => $c['status'] === 'redeemed'));
      $totalCount    = count($codes);
    ?>
    <div class="roc-offer-section">
      <div class="roc-offer-head">
        <div>
          <h4><?= e($offerTitle) ?></h4>
          <div style="font-size:.78rem;color:var(--muted-2);margin-top:.2rem;">
            <?= $totalCount ?> <?= t('claimed','réclamé(s)') ?> &bull;
            <?= $redeemedCount ?> <?= t('used','utilisé(s)') ?> &bull;
            <?= $activeCount ?> <?= t('active','actif(s)') ?>
            <?php if (!empty($offer['expires_at'])): ?>
            &bull; <?= t('Expires','Expire') ?> <?= date('d M Y', strtotime($offer['expires_at'])) ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="roc-offer-meta">
          <span class="roc-badge badge-<?= $offer['status'] === 'active' ? 'active' : 'expired' ?>"><?= e($offer['status']) ?></span>
          <a href="<?= SITE_URL ?>/offer/<?= e($offer['slug']) ?>" class="btn btn-outline btn-sm" target="_blank"><?= t('View','Voir') ?></a>
        </div>
      </div>

      <?php if ($codes): ?>
      <div style="overflow-x:auto;">
        <table class="roc-table">
          <thead>
            <tr>
              <th><?= t('Code','Code') ?></th>
              <th><?= t('Customer','Client') ?></th>
              <th><?= t('Claimed','Réclamé') ?></th>
              <th><?= t('Status','Statut') ?></th>
              <th><?= t('Used on','Utilisé le') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($codes as $c):
              $statusKey = $c['status'];
              if ($statusKey === 'active' && !empty($offer['expires_at']) && strtotime($offer['expires_at']) < time()) $statusKey = 'expired';
              $statusLabel = match($statusKey) {
                'active'    => t('Active','Actif'),
                'redeemed'  => t('Used','Utilisé'),
                'expired'   => t('Expired','Expiré'),
                'cancelled' => t('Cancelled','Annulé'),
                default     => $statusKey,
              };
            ?>
            <tr>
              <td><code class="roc-code"><?= e($c['code']) ?></code></td>
              <td>
                <div style="font-size:.85rem;"><?= e($c['customer_name'] ?: '—') ?></div>
                <div style="font-size:.75rem;color:var(--muted-2);"><?= e($c['customer_email'] ?: '') ?></div>
              </td>
              <td style="color:var(--muted-2);white-space:nowrap;"><?= date('d M Y', strtotime($c['claimed_at'])) ?></td>
              <td><span class="roc-badge badge-<?= $statusKey ?>"><?= $statusLabel ?></span></td>
              <td style="color:var(--muted-2);white-space:nowrap;"><?= $c['redeemed_at'] ? date('d M Y', strtotime($c['redeemed_at'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="roc-empty"><?= t('No codes claimed yet for this offer.','Aucun code réclamé pour cette offre.') ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

  <?php endif; ?>

</section>

<script>
// Auto-uppercase code input
document.querySelector('input[name="code"]')?.addEventListener('input', function(){
    var pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
