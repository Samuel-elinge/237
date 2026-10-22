<?php
/**
 * partner/manage-offer-codes.php — In-Store Offer Code Redemption
 *
 * Mobile-optimised screen for business staff.
 * Staff enter or scan a customer's discount code → see offer details → confirm redemption.
 * Also shows a live log of today's redemptions for this listing.
 *
 * URL: /partner/manage-offer-codes?listing_id=N
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notify.php';
requireLogin();

$isFr   = lang() === 'fr';
$userId = (int)(currentUser()['id'] ?? 0);
$pdo    = db();

// ── Listings owned by this user ───────────────────────────────────────────────
$myListings = $pdo->prepare("
    SELECT id, title, slug
    FROM listings
    WHERE user_id = ? AND status IN ('approved','published')
    ORDER BY title
");
$myListings->execute([$userId]);
$myListings = $myListings->fetchAll();

if (!$myListings) {
    flash('error', t('You need an approved listing to use redemption.', 'Vous avez besoin d\'une annonce approuvée pour utiliser le rachat.'));
    redirect(SITE_URL . '/partner/dashboard');
}

$lid = (int)($_GET['listing_id'] ?? ($myListings[0]['id'] ?? 0));
$activeListing = null;
foreach ($myListings as $ml) {
    if ((int)$ml['id'] === $lid) { $activeListing = $ml; break; }
}
if (!$activeListing) { $lid = (int)$myListings[0]['id']; $activeListing = $myListings[0]; }

// ── Handle AJAX / POST redemption ─────────────────────────────────────────────
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || ($_GET['ajax'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $code   = strtoupper(trim($_POST['code'] ?? ''));

    // ── Look up code ──────────────────────────────────────────────────────────
    if ($action === 'lookup' && $code) {
        // Support both shared codes (stored in discount_codes) and unique vouchers
        $dc = $pdo->prepare("
            SELECT dc.*,
                   o.id AS offer_id, o.title, o.title_fr, o.slug,
                   o.discount_type, o.discount_value, o.min_spend, o.expires_at,
                   o.status AS offer_status, o.terms, o.listing_id AS offer_listing_id,
                   o.max_redemptions, o.redemption_count,
                   l.title AS biz_title
            FROM discount_codes dc
            JOIN offers o ON o.id = dc.offer_id
            JOIN listings l ON l.id = o.listing_id
            WHERE dc.code = ?
        ");
        $dc->execute([$code]);
        $row = $dc->fetch();

        if (!$row) {
            $result = ['ok' => false, 'error' => t('Code not found.', 'Code introuvable.')];
        } elseif ((int)$row['offer_listing_id'] !== $lid) {
            $result = ['ok' => false, 'error' => t('This code belongs to a different business.', 'Ce code appartient à une autre entreprise.')];
        } elseif ($row['offer_status'] !== 'active') {
            $result = ['ok' => false, 'error' => t('This offer is not currently active.', 'Cette offre n\'est pas actuellement active.') . ' (' . $row['offer_status'] . ')'];
        } elseif ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            $result = ['ok' => false, 'error' => t('This offer has expired.', 'Cette offre a expiré.')];
        } elseif ($row['max_redemptions'] && (int)$row['redemption_count'] >= (int)$row['max_redemptions']) {
            $result = ['ok' => false, 'error' => t('This offer has reached its redemption limit.', 'Cette offre a atteint sa limite d\'utilisation.')];
        } elseif ($row['code_type'] === 'unique' && $row['status'] !== 'active') {
            $statusMsg = [
                'redeemed'  => t('This code has already been redeemed.', 'Ce code a déjà été utilisé.'),
                'expired'   => t('This code has expired.', 'Ce code a expiré.'),
                'cancelled' => t('This code has been cancelled.', 'Ce code a été annulé.'),
            ][$row['status']] ?? t('This code is no longer valid.', 'Ce code n\'est plus valide.');
            $result = ['ok' => false, 'error' => $statusMsg];
        } else {
            $otitle = ($isFr && $row['title_fr']) ? $row['title_fr'] : $row['title'];
            $discStr = '';
            if ($row['discount_type'] === 'percent_off' && $row['discount_value'])
                $discStr = '-' . (int)$row['discount_value'] . '%';
            elseif ($row['discount_type'] === 'fixed_off' && $row['discount_value'])
                $discStr = '-' . number_format((float)$row['discount_value']) . ' XAF';
            elseif ($row['discount_type'] === 'fixed_price' && $row['discount_value'])
                $discStr = number_format((float)$row['discount_value']) . ' XAF';
            elseif ($row['discount_type'] === 'bogo')  $discStr = 'Buy 1 Get 1';
            elseif ($row['discount_type'] === 'free_item') $discStr = t('Free Item','Article Gratuit');
            elseif ($row['discount_type'] === 'bundle')    $discStr = t('Bundle','Pack');

            $result = [
                'ok'         => true,
                'code'       => $row['code'],
                'dc_id'      => $row['id'],
                'offer_id'   => $row['offer_id'],
                'offer_title'=> $otitle,
                'disc_str'   => $discStr,
                'code_type'  => $row['code_type'],
                'min_spend'  => $row['min_spend'] ? number_format((float)$row['min_spend']) . ' XAF' : null,
                'terms'      => $row['terms'] ?: null,
                'expires_at' => $row['expires_at'] ? date('d M Y', strtotime($row['expires_at'])) : null,
                'used'       => (int)$row['redemption_count'],
                'limit'      => $row['max_redemptions'] ? (int)$row['max_redemptions'] : null,
            ];
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }
    }

    // ── Confirm redemption ────────────────────────────────────────────────────
    if ($action === 'redeem') {
        $code  = strtoupper(trim($_POST['code'] ?? ''));
        $dcId  = (int)($_POST['dc_id'] ?? 0);
        $offerId = (int)($_POST['offer_id'] ?? 0);

        $ok = false; $msg = '';

        // Re-validate
        $dc = $pdo->prepare("
            SELECT dc.*, o.status AS offer_status, o.max_redemptions, o.redemption_count,
                   o.expires_at, o.listing_id AS offer_listing_id
            FROM discount_codes dc JOIN offers o ON o.id = dc.offer_id
            WHERE dc.id = ? AND dc.code = ?
        ");
        $dc->execute([$dcId, $code]);
        $row = $dc->fetch();

        if (!$row || (int)$row['offer_listing_id'] !== $lid) {
            $msg = t('Invalid code.', 'Code invalide.');
        } elseif ($row['offer_status'] !== 'active') {
            $msg = t('Offer is no longer active.', 'L\'offre n\'est plus active.');
        } elseif ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            $msg = t('Offer has expired.', 'L\'offre a expiré.');
        } elseif ($row['max_redemptions'] && (int)$row['redemption_count'] >= (int)$row['max_redemptions']) {
            $msg = t('Redemption limit reached.', 'Limite de rachat atteinte.');
        } elseif ($row['code_type'] === 'unique' && $row['status'] !== 'active') {
            $msg = t('This code has already been used or is cancelled.', 'Ce code a déjà été utilisé ou est annulé.');
        } else {
            try {
                $pdo->beginTransaction();

                // Mark unique code as redeemed
                if ($row['code_type'] === 'unique') {
                    $pdo->prepare("
                        UPDATE discount_codes
                        SET status='redeemed', redeemed_at=NOW()
                        WHERE id=? AND status='active'
                    ")->execute([$dcId]);
                }

                // Log redemption
                $pdo->prepare("
                    INSERT INTO offer_redemptions
                        (offer_id, discount_code_id, business_id, staff_user_id, status, redeemed_at)
                    VALUES (?, ?, ?, ?, 'confirmed', NOW())
                ")->execute([$offerId, $dcId, $userId, $userId]);

                // Increment counter
                $pdo->prepare("
                    UPDATE offers SET redemption_count = redemption_count + 1, updated_at=NOW()
                    WHERE id=?
                ")->execute([$offerId]);

                // Audit log
                try {
                    $pdo->prepare("
                        INSERT INTO offer_audit_logs
                            (actor_id, actor_role, action, entity_type, entity_id, notes, created_at)
                        VALUES (?, 'business', 'offer.redeem', 'discount_code', ?, ?, NOW())
                    ")->execute([$userId, $dcId, $code]);
                } catch (Exception $e) { /* non-fatal */ }

                $pdo->commit();
                $ok  = true;
                $msg = t('✅ Code redeemed successfully!', '✅ Code utilisé avec succès !');

                // Notify the business owner that a customer redeemed their code
                try {
                    $nSt = $pdo->prepare("
                        SELECT o.title, o.title_fr, o.slug, l.user_id AS owner_id
                        FROM offers o JOIN listings l ON l.id = o.listing_id
                        WHERE o.id = ?
                    ");
                    $nSt->execute([$offerId]);
                    $offerRow = $nSt->fetch();
                    if ($offerRow && $offerRow['owner_id'] && (int)$offerRow['owner_id'] !== $userId) {
                        $oTitle   = $offerRow['title'];
                        $oTitleFr = $offerRow['title_fr'] ?: $oTitle;
                        $oLink    = SITE_URL . '/partner/offer-analytics?id=' . $offerId;
                        dispatch_notification(
                            (int)$offerRow['owner_id'], 'bell',
                            '🎉 Code Redeemed',
                            '🎉 Code Utilisé',
                            "A customer just redeemed a code for your offer \"{$oTitle}\". Check your analytics for details.",
                            "Un client vient d'utiliser un code pour votre offre \"{$oTitleFr}\". Consultez vos statistiques pour les détails.",
                            $oLink, 'offer', $offerId, 'promo'
                        );
                    }
                } catch (Exception $ne) { /* non-fatal */ }

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg = t('Could not process redemption. Please try again.', 'Impossible de traiter le rachat. Veuillez réessayer.');
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $ok, 'message' => $msg]);
            exit;
        }

        flash($ok ? 'success' : 'error', $msg);
        redirect(SITE_URL . '/partner/manage-offer-codes?listing_id=' . $lid);
    }
}

// ── Today's redemptions for this listing ─────────────────────────────────────
$todayLog = $pdo->prepare("
    SELECT r.redeemed_at, dc.code, dc.code_type,
           o.title, o.title_fr, o.discount_type, o.discount_value,
           u.name AS redeemed_by_name
    FROM offer_redemptions r
    JOIN offers o          ON o.id  = r.offer_id
    LEFT JOIN discount_codes dc ON dc.id = r.discount_code_id
    LEFT JOIN users u      ON u.id  = r.staff_user_id
    WHERE o.listing_id = ?
      AND DATE(r.redeemed_at) = CURDATE()
      AND r.status = 'confirmed'
    ORDER BY r.redeemed_at DESC
    LIMIT 50
");
$todayLog->execute([$lid]);
$todayLog = $todayLog->fetchAll();

// Active offers for this listing (for reference display)
$activeOffers = $pdo->prepare("
    SELECT o.id, o.title, o.title_fr, o.discount_type, o.discount_value,
           o.expires_at, o.redemption_count, o.max_redemptions, o.code_type,
           (SELECT dc.code FROM discount_codes dc WHERE dc.offer_id=o.id AND dc.code_type='shared' LIMIT 1) AS shared_code,
           (SELECT COUNT(*) FROM discount_codes dc WHERE dc.offer_id=o.id AND dc.code_type='unique' AND dc.status='active') AS unique_remaining
    FROM offers o
    WHERE o.listing_id = ? AND o.status = 'active'
    ORDER BY o.created_at DESC
");
$activeOffers->execute([$lid]);
$activeOffers = $activeOffers->fetchAll();

$pageTitle = t('In-Store Redemption', 'Rachat en Boutique') . ' — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<style>
:root {
    --rc-bg:     #0d1f18;
    --rc-card:   rgba(255,255,255,0.035);
    --rc-border: rgba(0,168,120,0.18);
    --rc-green:  #00A878;
    --rc-text:   #e8f5ee;
    --rc-muted:  #5a8878;
    --rc-light:  #8db8a8;
}

* { box-sizing: border-box; }
.rc-wrap { max-width: 540px; margin: 0 auto; padding: 0 0 4rem; }

/* Top bar */
.rc-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.75rem 1rem; background: var(--rc-card);
    border-bottom: 1px solid var(--rc-border); gap: 0.5rem;
    position: sticky; top: 0; z-index: 10; backdrop-filter: blur(8px);
}
.rc-back { color: var(--rc-muted); text-decoration: none; font-size: 0.82rem; }
.rc-back:hover { color: var(--rc-green); }
.rc-biz-name { font-weight: 700; color: var(--rc-text); font-size: 0.9rem; text-align: center; flex: 1; }
.rc-listing-sel {
    font-size: 0.72rem; color: var(--rc-muted); background: none;
    border: none; cursor: pointer; padding: 0;
}

/* Scan / Input card */
.rc-scan-card {
    margin: 1rem; background: var(--rc-card);
    border: 1px solid var(--rc-border); border-radius: 14px;
    padding: 1.25rem; text-align: center;
}
.rc-scan-title {
    font-family: 'Fraunces', serif; font-weight: 900;
    font-size: 1.2rem; color: var(--rc-text); margin-bottom: 0.25rem;
}
.rc-scan-sub { font-size: 0.78rem; color: var(--rc-muted); margin-bottom: 1.1rem; }

.rc-code-input-row { display: flex; gap: 0.5rem; }
.rc-code-input {
    flex: 1; background: rgba(0,0,0,0.35);
    border: 2px solid var(--rc-border); color: var(--rc-text);
    border-radius: 10px; padding: 0.8rem 1rem;
    font-size: 1.1rem; font-family: monospace; font-weight: 700;
    letter-spacing: 0.1em; text-align: center; text-transform: uppercase;
    transition: border-color 0.15s;
}
.rc-code-input:focus { outline: none; border-color: var(--rc-green); }
.rc-code-input::placeholder { opacity: 0.3; letter-spacing: 0.05em; font-size: 0.88rem; }
.rc-lookup-btn {
    background: var(--rc-green); color: #fff;
    border: none; border-radius: 10px;
    padding: 0.8rem 1.1rem; font-size: 1.1rem; cursor: pointer;
    transition: background 0.15s; flex-shrink: 0;
}
.rc-lookup-btn:hover { background: #00c48f; }
.rc-lookup-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* Result card */
.rc-result {
    margin: 0 1rem 1rem; border-radius: 12px;
    border: 2px solid; padding: 1.1rem; display: none;
}
.rc-result.ok    { border-color: #4ade80; background: rgba(74,222,128,0.06); }
.rc-result.error { border-color: #f87171; background: rgba(248,113,113,0.06); }
.rc-result-title { font-weight: 800; font-size: 1rem; color: var(--rc-text); margin-bottom: 0.4rem; }
.rc-result-disc  {
    font-size: 1.5rem; font-weight: 900; color: #4ade80;
    font-family: 'Fraunces', serif; margin-bottom: 0.5rem;
}
.rc-result-meta  { font-size: 0.78rem; color: var(--rc-light); display: flex; flex-direction: column; gap: 0.2rem; }
.rc-result-terms { margin-top: 0.5rem; font-size: 0.73rem; color: var(--rc-muted);
                   background: rgba(0,0,0,0.2); border-radius: 6px; padding: 0.4rem 0.6rem; }
.rc-confirm-btn {
    width: 100%; margin-top: 1rem; padding: 0.85rem;
    background: #4ade80; color: #052e16;
    border: none; border-radius: 10px; font-size: 1rem; font-weight: 800;
    cursor: pointer; transition: background 0.15s; letter-spacing: 0.02em;
}
.rc-confirm-btn:hover { background: #6ee7a0; }
.rc-confirm-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.rc-error-msg { font-size: 0.88rem; color: #f87171; font-weight: 700; }

/* Success flash */
.rc-flash {
    margin: 0 1rem 1rem; padding: 1rem 1.1rem; border-radius: 12px;
    font-weight: 800; font-size: 1rem; text-align: center;
    display: none;
}
.rc-flash.success { background: rgba(74,222,128,0.1); border: 2px solid #4ade80; color: #4ade80; }
.rc-flash.error   { background: rgba(248,113,113,0.08); border: 2px solid #f87171; color: #f87171; }

/* Active offers section */
.rc-section { margin: 0 1rem 1rem; }
.rc-section-title {
    font-size: 0.68rem; font-weight: 800; letter-spacing: 0.1em;
    text-transform: uppercase; color: var(--rc-muted); margin-bottom: 0.5rem;
    padding-bottom: 0.3rem; border-bottom: 1px solid var(--rc-border);
}
.rc-offer-chip {
    background: var(--rc-card); border: 1px solid var(--rc-border);
    border-radius: 10px; padding: 0.7rem 0.85rem; margin-bottom: 0.5rem;
    display: flex; align-items: center; gap: 0.75rem; cursor: pointer;
    transition: border-color 0.15s;
}
.rc-offer-chip:hover { border-color: rgba(0,168,120,0.4); }
.rc-offer-disc { font-size: 1.1rem; font-weight: 900; color: #4ade80; min-width: 55px; }
.rc-offer-name { font-size: 0.82rem; color: var(--rc-text); font-weight: 600; flex: 1; }
.rc-offer-code-chip {
    font-family: monospace; font-size: 0.75rem; font-weight: 700; color: #00A878;
    background: rgba(0,168,120,0.1); border: 1px solid rgba(0,168,120,0.25);
    border-radius: 5px; padding: 0.15rem 0.45rem;
}
.rc-offer-usage { font-size: 0.7rem; color: var(--rc-muted); }

/* Today's log */
.rc-log-row {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.55rem 0; border-bottom: 1px solid rgba(0,168,120,0.06);
}
.rc-log-time   { font-size: 0.7rem; color: var(--rc-muted); white-space: nowrap; min-width: 42px; }
.rc-log-code   { font-family: monospace; font-size: 0.78rem; color: #4ade80; font-weight: 700; }
.rc-log-title  { font-size: 0.78rem; color: var(--rc-light); flex: 1; }
.rc-log-disc   { font-size: 0.75rem; color: #00A878; font-weight: 700; white-space: nowrap; }

.rc-count-badge {
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--rc-green); color: #fff; border-radius: 99px;
    font-size: 0.68rem; font-weight: 800; padding: 0.1rem 0.45rem; margin-left: 0.35rem;
}
.rc-empty { text-align: center; padding: 1rem; color: var(--rc-muted); font-size: 0.82rem; }
</style>

<?php flash('display'); ?>

<div class="rc-wrap">
  <!-- Top bar -->
  <div class="rc-topbar">
    <a href="<?= SITE_URL ?>/partner/offers?listing_id=<?= $lid ?>" class="rc-back">← <?= t('Offers', 'Offres') ?></a>
    <div class="rc-biz-name">🏪 <?= e($activeListing['title']) ?></div>
    <?php if (count($myListings) > 1): ?>
      <select class="rc-listing-sel" onchange="location.href='?listing_id='+this.value">
        <?php foreach ($myListings as $ml): ?>
          <option value="<?= $ml['id'] ?>" <?= $ml['id'] === $lid ? 'selected' : '' ?>>
            <?= e(mb_substr($ml['title'], 0, 18)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </div>

  <!-- ═══ Scan / Enter code ═══ -->
  <div class="rc-scan-card">
    <div class="rc-scan-title">🏷️ <?= t('Redeem a Code', 'Valider un Code') ?></div>
    <div class="rc-scan-sub"><?= t('Type the code from the customer\'s phone or printed voucher.', 'Tapez le code depuis le téléphone du client ou son bon imprimé.') ?></div>
    <div class="rc-code-input-row">
      <input type="text" id="rc-code" class="rc-code-input"
             maxlength="20" autocomplete="off" autocorrect="off" spellcheck="false"
             placeholder="<?= t('e.g. 7KMP4X9D', 'ex: 7KMP4X9D') ?>"
             oninput="this.value=this.value.toUpperCase()">
      <button type="button" class="rc-lookup-btn" id="rc-lookup-btn" onclick="lookupCode()">🔍</button>
    </div>
  </div>

  <!-- Flash -->
  <div class="rc-flash" id="rc-flash"></div>

  <!-- Result panel -->
  <div class="rc-result" id="rc-result">
    <div id="rc-result-inner"></div>
  </div>

  <!-- Active offers reference -->
  <?php if ($activeOffers): ?>
    <div class="rc-section">
      <div class="rc-section-title"><?= t('Active Offers', 'Offres Actives') ?></div>
      <?php foreach ($activeOffers as $o):
        $otitle = ($isFr && $o['title_fr']) ? $o['title_fr'] : $o['title'];
        $discStr = '';
        if ($o['discount_type'] === 'percent_off' && $o['discount_value'])
            $discStr = '-' . (int)$o['discount_value'] . '%';
        elseif ($o['discount_type'] === 'fixed_off' && $o['discount_value'])
            $discStr = '-' . number_format((float)$o['discount_value']) . ' XAF';
        elseif ($o['discount_type'] === 'fixed_price' && $o['discount_value'])
            $discStr = number_format((float)$o['discount_value']) . ' XAF';
        elseif ($o['discount_type'] === 'bogo')      $discStr = 'B1G1';
        elseif ($o['discount_type'] === 'free_item') $discStr = t('Free','Gratuit');
        elseif ($o['discount_type'] === 'bundle')    $discStr = t('Bundle','Pack');
        $used  = (int)$o['redemption_count'];
        $limit = $o['max_redemptions'] ? (int)$o['max_redemptions'] : null;
      ?>
        <div class="rc-offer-chip" <?= $o['shared_code'] ? 'onclick="fillCode(\''.e($o['shared_code']).'\')"' : '' ?>>
          <div class="rc-offer-disc"><?= e($discStr) ?></div>
          <div style="flex:1;">
            <div class="rc-offer-name"><?= e($otitle) ?></div>
            <div class="rc-offer-usage">
              <?php if ($o['code_type'] === 'shared' && $o['shared_code']): ?>
                <span class="rc-offer-code-chip"><?= e($o['shared_code']) ?></span>
              <?php elseif ($o['code_type'] === 'unique'): ?>
                🎟 <?= (int)$o['unique_remaining'] ?> <?= t('codes remaining', 'codes restants') ?>
              <?php endif; ?>
              · <?= $used ?><?= $limit ? '/'.$limit : '' ?> <?= t('used', 'utilisés') ?>
            </div>
          </div>
          <?php if ($o['expires_at']): ?>
            <div style="font-size:0.68rem;color:var(--rc-muted);">⏰ <?= date('d M', strtotime($o['expires_at'])) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="rc-section">
      <div class="rc-empty">
        <?= t('No active offers right now.', 'Aucune offre active pour le moment.') ?>
        <br><a href="<?= SITE_URL ?>/partner/offers?listing_id=<?= $lid ?>" style="color:var(--rc-green);">+ <?= t('Create one', 'En créer une') ?></a>
      </div>
    </div>
  <?php endif; ?>

  <!-- Today's redemptions -->
  <div class="rc-section">
    <div class="rc-section-title">
      <?= t('Today\'s Redemptions', 'Validations d\'Aujourd\'hui') ?>
      <?php if ($todayLog): ?><span class="rc-count-badge"><?= count($todayLog) ?></span><?php endif; ?>
    </div>
    <?php if ($todayLog): ?>
      <?php foreach ($todayLog as $r):
        $rtitle = ($isFr && $r['title_fr']) ? $r['title_fr'] : $r['title'];
        $rdisc  = '';
        if ($r['discount_type'] === 'percent_off' && $r['discount_value'])
            $rdisc = '-' . (int)$r['discount_value'] . '%';
        elseif ($r['discount_type'] === 'fixed_off' && $r['discount_value'])
            $rdisc = '-' . number_format((float)$r['discount_value']) . ' XAF';
      ?>
        <div class="rc-log-row">
          <div class="rc-log-time"><?= date('H:i', strtotime($r['redeemed_at'])) ?></div>
          <div class="rc-log-code"><?= e($r['code']) ?></div>
          <div class="rc-log-title"><?= e(mb_substr($rtitle, 0, 28)) ?></div>
          <?php if ($rdisc): ?><div class="rc-log-disc"><?= e($rdisc) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="rc-empty"><?= t('No redemptions yet today.', 'Aucune validation aujourd\'hui.') ?></div>
    <?php endif; ?>
  </div>
</div>

<!-- Hidden CSRF field for JS POSTs -->
<?php
// Ensure token is in session then output a named hidden field
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<input type="hidden" id="rc-csrf" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

<script>
const REDEEM_URL = '<?= SITE_URL ?>/partner/manage-offer-codes?listing_id=<?= $lid ?>&ajax=1';

function fillCode(code) {
    document.getElementById('rc-code').value = code;
    lookupCode();
}

function lookupCode() {
    const code = document.getElementById('rc-code').value.trim().toUpperCase();
    if (!code) return;

    const btn = document.getElementById('rc-lookup-btn');
    btn.disabled = true;
    btn.textContent = '…';
    hideFlash(); hideResult();

    const fd = new FormData();
    fd.append('action', 'lookup');
    fd.append('code', code);
    fd.append('csrf_token', document.getElementById('rc-csrf').value);

    fetch(REDEEM_URL, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false; btn.textContent = '🔍';
        if (data.ok) {
            showResult(data);
        } else {
            showError(data.error || '<?= t('An error occurred.','Une erreur est survenue.') ?>');
        }
    })
    .catch(() => {
        btn.disabled = false; btn.textContent = '🔍';
        showError('<?= t('Network error. Check connection.','Erreur réseau. Vérifiez la connexion.') ?>');
    });
}

function showResult(d) {
    const res = document.getElementById('rc-result');
    const inner = document.getElementById('rc-result-inner');

    const minSpend = d.min_spend ? `<div>⚠️ <?= t('Min spend:','Achat min :') ?> ${d.min_spend}</div>` : '';
    const expires  = d.expires_at ? `<div>⏰ <?= t('Expires:','Expire :') ?> ${d.expires_at}</div>` : '';
    const usageTxt = d.limit
        ? `${d.used}/${d.limit} <?= t('redemptions used','utilisations') ?>`
        : `${d.used} <?= t('redemptions so far','utilisations à ce jour') ?>`;
    const terms = d.terms ? `<div class="rc-result-terms">📋 ${d.terms}</div>` : '';
    const codeType = d.code_type === 'unique'
        ? `<div>🎟 <?= t('Unique code — will be marked used','Code unique — sera marqué utilisé') ?></div>`
        : `<div>🔗 <?= t('Shared code','Code partagé') ?></div>`;

    inner.innerHTML = `
        <div class="rc-result-disc">${d.disc_str || '✓'}</div>
        <div class="rc-result-title">${d.offer_title}</div>
        <div class="rc-result-meta">
            ${codeType}
            ${minSpend}
            ${expires}
            <div>📊 ${usageTxt}</div>
        </div>
        ${terms}
        <form onsubmit="confirmRedeem(event)" id="rc-confirm-form">
            <input type="hidden" name="code"     value="${d.code}">
            <input type="hidden" name="dc_id"    value="${d.dc_id}">
            <input type="hidden" name="offer_id" value="${d.offer_id}">
            <button type="submit" class="rc-confirm-btn" id="rc-confirm-btn">
                ✅ <?= t('Confirm Redemption','Confirmer le Rachat') ?>
            </button>
        </form>`;

    res.className = 'rc-result ok';
    res.style.display = '';
}

function showError(msg) {
    const res = document.getElementById('rc-result');
    document.getElementById('rc-result-inner').innerHTML =
        `<div class="rc-error-msg">❌ ${msg}</div>`;
    res.className = 'rc-result error';
    res.style.display = '';
}

function hideResult() { document.getElementById('rc-result').style.display = 'none'; }
function hideFlash()  { document.getElementById('rc-flash').style.display  = 'none'; }

function showFlash(type, msg) {
    const f = document.getElementById('rc-flash');
    f.className = 'rc-flash ' + type;
    f.textContent = msg;
    f.style.display = '';
    setTimeout(() => { f.style.display = 'none'; }, 5000);
}

function confirmRedeem(e) {
    e.preventDefault();
    const form = e.target;
    const btn  = document.getElementById('rc-confirm-btn');
    btn.disabled = true;
    btn.textContent = '<?= t('Processing…','Traitement…') ?>';

    const fd = new FormData(form);
    fd.append('action', 'redeem');
    fd.append('csrf_token', document.getElementById('rc-csrf').value);

    fetch(REDEEM_URL, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            hideResult();
            document.getElementById('rc-code').value = '';
            showFlash('success', data.message);
            // Reload after 2s to refresh today's log
            setTimeout(() => location.reload(), 2000);
        } else {
            showFlash('error', data.message);
            btn.disabled = false;
            btn.textContent = '✅ <?= t('Confirm Redemption','Confirmer le Rachat') ?>';
        }
    })
    .catch(() => {
        showFlash('error', '<?= t('Network error. Please try again.','Erreur réseau. Veuillez réessayer.') ?>');
        btn.disabled = false;
        btn.textContent = '✅ <?= t('Confirm Redemption','Confirmer le Rachat') ?>';
    });
}

// Allow Enter key to trigger lookup
document.getElementById('rc-code').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); lookupCode(); }
});

// Auto-focus the input on load
document.getElementById('rc-code').focus();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
