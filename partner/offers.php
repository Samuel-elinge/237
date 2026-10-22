<?php
/**
 * partner/offers.php — Business Owner: Manage Offers & Discounts (v2)
 * URL: /partner/offers (or /partner/offers?listing_id=N)
 *
 * Spec changes vs v1:
 *  - Renamed "Deals & Offers" → "Offers & Discounts"
 *  - Removed promo_code text input — codes are server-generated only
 *  - Added code_type selector (shared / unique)
 *  - Added discount_type (replaces/mirrors offer_type)
 *  - Added min_spend, max_discount, batch_size, combinable, eligible_items
 *  - Saves to new discount_type column; keeps offer_type for backward compat
 *  - Status list expanded: draft, scheduled, active, paused, expired, archived, rejected
 *  - Paused offers count toward slot limit (handled in entitlement-helpers.php)
 *  - Shared code generated server-side on create; unique codes batch-queued
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/entitlement-helpers.php';
requireLogin();

$isFr   = lang() === 'fr';
$userId = (int)(currentUser()['id'] ?? 0);
$pdo    = db();

// ── Helpers ───────────────────────────────────────────────────────────────────
/**
 * Generate a cryptographically random uppercase alphanumeric code,
 * excluding confusing characters (0, O, I, 1).
 */
function generateOfferCode(int $length = 8): string {
    $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0,O,I,1
    $result = '';
    $max    = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, $max)];
    }
    return $result;
}

/**
 * Generate a unique shared campaign code, retry on collision.
 */
function generateSharedCode(PDO $pdo, int $length = 8): string {
    $attempts = 0;
    do {
        $code = generateOfferCode($length);
        $chk  = $pdo->prepare("SELECT id FROM discount_codes WHERE code = ?");
        $chk->execute([$code]);
        $attempts++;
        if ($attempts > 20) throw new RuntimeException('Code generation collision limit reached');
    } while ($chk->fetch());
    return $code;
}

// ── Listings owned by this user ───────────────────────────────────────────────
$myListings = $pdo->prepare("
    SELECT id, title, slug, status
    FROM listings
    WHERE user_id = ? AND status = 'approved'
    ORDER BY title
");
$myListings->execute([$userId]);
$myListings = $myListings->fetchAll();

if (!$myListings) {
    flash('error', t(
        'You need an approved listing to create offers. <a href="'.SITE_URL.'/add-listing">Add your business</a>.',
        'Vous avez besoin d\'une annonce approuvée pour créer des offres. <a href="'.SITE_URL.'/add-listing">Ajoutez votre entreprise</a>.'
    ));
    redirect(SITE_URL . '/partner/dashboard');
}

$lid = (int)($_GET['listing_id'] ?? ($_POST['listing_id'] ?? ($myListings[0]['id'] ?? 0)));
$activeListing = null;
foreach ($myListings as $ml) {
    if ($ml['id'] === $lid) { $activeListing = $ml; break; }
}
if (!$activeListing) {
    $lid = (int)$myListings[0]['id'];
    $activeListing = $myListings[0];
}

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();
    $action = $_POST['action'] ?? '';

    // ── Create / Update offer ─────────────────────────────────────────────────
    if (in_array($action, ['create', 'update'])) {
        $oid         = $action === 'update' ? (int)($_POST['offer_id'] ?? 0) : 0;
        $title       = trim($_POST['title']    ?? '');
        $titleFr     = trim($_POST['title_fr'] ?? '');
        $desc        = trim($_POST['description']    ?? '');
        $descFr      = trim($_POST['description_fr'] ?? '');
        $discType    = $_POST['discount_type'] ?? 'percent_off';
        $discVal     = (float)($_POST['discount_value'] ?? 0);
        $origPx      = ($_POST['original_price'] ?? '') !== '' ? (float)$_POST['original_price'] : null;
        $minSpend    = ($_POST['min_spend'] ?? '') !== '' ? (float)$_POST['min_spend'] : null;
        $maxDiscount = ($_POST['max_discount'] ?? '') !== '' ? (float)$_POST['max_discount'] : null;
        $codeType    = in_array($_POST['code_type'] ?? '', ['shared','unique']) ? $_POST['code_type'] : 'shared';
        $batchSize   = (int)($_POST['batch_size'] ?? 0);
        $combinable  = isset($_POST['combinable']) ? 1 : 0;
        $eligItems   = trim($_POST['eligible_items'] ?? '');
        $startsAt    = $_POST['starts_at'] ?? date('Y-m-d H:i:s');
        $expiresAt   = ($_POST['expires_at'] ?? '') !== '' ? $_POST['expires_at'] : null;
        $terms       = trim($_POST['terms'] ?? '');
        $maxRed      = ($_POST['max_redemptions'] ?? '') !== '' ? (int)$_POST['max_redemptions'] : null;
        $maxClaimsPerUser = ($_POST['max_claims_per_user'] ?? '') !== '' ? max(1, (int)$_POST['max_claims_per_user']) : 1;

        // Determine if starts in future (auto-schedule)
        $isScheduled  = $startsAt && strtotime($startsAt) > time();
        $targetStatus = $isScheduled ? 'scheduled' : 'pending'; // pending = awaiting admin approval

        $validTypes = ['percent_off','fixed_off','fixed_price','bogo','free_item','bundle'];

        if (!$title) {
            flash('error', t('Title is required.','Le titre est requis.'));
        } elseif (!in_array($discType, $validTypes)) {
            flash('error', t('Invalid discount type.','Type de réduction invalide.'));
        } elseif ($discType === 'percent_off' && ($discVal <= 0 || $discVal > 100)) {
            flash('error', t('Percentage must be 1–100.','Le pourcentage doit être entre 1 et 100.'));
        } elseif ($discType === 'fixed_off' && $discVal <= 0) {
            flash('error', t('Please enter the discount amount in XAF.','Veuillez saisir le montant de réduction en XAF.'));
        } elseif ($codeType === 'unique' && $action === 'create' && $batchSize < 1) {
            flash('error', t('For unique codes, please specify a batch size of at least 1.','Pour les codes uniques, veuillez indiquer une taille de lot d\'au moins 1.'));
        } else {
            // ── Entitlement check on create ──────────────────────────────────
            if ($action === 'create' && !checkEntitlement($lid, 'offers')) {
                $ent = getListingEntitlements($lid);
                flash('error', t(
                    'You have reached your active offer limit ('.$ent['total_offers'].'). Purchase additional offer slots or upgrade your plan.',
                    'Vous avez atteint votre limite d\'offres ('.$ent['total_offers'].'). Achetez des créneaux supplémentaires ou passez à un plan supérieur.'
                ));
                redirect(SITE_URL . '/partner/offers?listing_id=' . $lid);
            }

            // Build slug (on create only)
            if ($action === 'create') {
                $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
                $slug = rtrim($base, '-') . '-' . substr(md5(uniqid()), 0, 6);
            }

            // Handle image upload
            $image = $_POST['existing_image'] ?? null;
            if (!empty($_FILES['image']['tmp_name'])) {
                $ext     = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','webp'];
                if (!in_array($ext, $allowed)) {
                    flash('error', t('Images must be JPG, PNG or WebP.','Les images doivent être JPG, PNG ou WebP.'));
                    redirect(SITE_URL . '/partner/offers?listing_id=' . $lid);
                }
                $fname = 'offer-' . $lid . '-' . time() . '.' . $ext;
                $dest  = __DIR__ . '/../uploads/offers/' . $fname;
                if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
                if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                    $image = 'offers/' . $fname;
                }
            }

            try {
                if ($action === 'create') {
                    $pdo->beginTransaction();

                    $st = $pdo->prepare("
                        INSERT INTO offers
                            (listing_id, title, title_fr, slug, description, description_fr,
                             offer_type, discount_type, discount_value, original_price, currency,
                             min_spend, max_discount, code_type, batch_size, combinable, eligible_items,
                             image, starts_at, expires_at, status, terms, max_redemptions,
                             max_claims_per_user, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ");
                    $st->execute([
                        $lid, $title, $titleFr ?: null, $slug,
                        $desc ?: null, $descFr ?: null,
                        $discType,          // offer_type (kept for backward compat)
                        $discType,          // discount_type (new column)
                        in_array($discType,['percent_off','fixed_off','fixed_price']) ? $discVal : null,
                        $origPx, 'XAF',
                        $minSpend, $maxDiscount,
                        $codeType,
                        $codeType === 'unique' ? $batchSize : 0,
                        $combinable,
                        $eligItems ?: null,
                        $image, $startsAt, $expiresAt,
                        $targetStatus,
                        $terms ?: null, $maxRed, $maxClaimsPerUser,
                    ]);
                    $offerId = (int)$pdo->lastInsertId();

                    // ── Generate shared code now (Type A) ────────────────────
                    if ($codeType === 'shared') {
                        $sharedCode = generateSharedCode($pdo, 8);
                        $pdo->prepare("
                            INSERT INTO discount_codes
                                (code, offer_id, business_id, code_type, usage_limit,
                                 usage_count, claim_id, user_id, status, created_at)
                            VALUES (?, ?, ?, 'shared', 0, 0, NULL, NULL, 'active', NOW())
                        ")->execute([$sharedCode, $offerId, $userId]);
                    }
                    // Type B (unique) codes are generated in a separate batch job
                    // triggered by the admin when approving the offer.

                    // Audit log
                    $pdo->prepare("
                        INSERT INTO offer_audit_logs
                            (actor_id, actor_role, business_id, action, entity_type, entity_id,
                             new_value, ip_address, created_at)
                        VALUES (?, 'business', ?, 'offer.create', 'offer', ?, ?, ?, NOW())
                    ")->execute([
                        $userId, $userId, $offerId,
                        json_encode(['code_type' => $codeType, 'status' => $targetStatus]),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);

                    $pdo->commit();
                    flash('success', t(
                        'Offer submitted for review — it will go live once approved.',
                        'Offre soumise pour révision — elle sera publiée une fois approuvée.'
                    ));

                } else {
                    // ── Update ────────────────────────────────────────────────
                    $own = $pdo->prepare("SELECT id, status, code_type FROM offers WHERE id=? AND listing_id=?");
                    $own->execute([$oid, $lid]);
                    $existing = $own->fetch();
                    if (!$existing) {
                        flash('error', t('Offer not found.','Offre introuvable.'));
                    } else {
                        // Cannot change code_type after creation
                        if ($existing['code_type'] !== null) {
                            $codeType = $existing['code_type'];
                        }
                        $pdo->prepare("
                            UPDATE offers
                            SET title=?, title_fr=?, description=?, description_fr=?,
                                offer_type=?, discount_type=?, discount_value=?,
                                original_price=?, min_spend=?, max_discount=?,
                                combinable=?, eligible_items=?,
                                starts_at=?, expires_at=?, terms=?,
                                max_redemptions=?, max_claims_per_user=?,
                                image = COALESCE(?,image),
                                status = IF(status IN ('rejected'), 'pending', status),
                                updated_at=NOW()
                            WHERE id=? AND listing_id=?
                        ")->execute([
                            $title, $titleFr ?: null, $desc ?: null, $descFr ?: null,
                            $discType, $discType,
                            in_array($discType,['percent_off','fixed_off','fixed_price']) ? $discVal : null,
                            $origPx, $minSpend, $maxDiscount,
                            $combinable, $eligItems ?: null,
                            $startsAt, $expiresAt, $terms ?: null,
                            $maxRed, $maxClaimsPerUser,
                            $image, $oid, $lid,
                        ]);
                        flash('success', t('Offer updated.','Offre mise à jour.'));
                    }
                }
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', t('Could not save offer. Please try again.','Impossible d\'enregistrer l\'offre. Veuillez réessayer.'));
            }
        }

    // ── Pause / Resume ────────────────────────────────────────────────────────
    } elseif ($action === 'pause') {
        $oid = (int)($_POST['offer_id'] ?? 0);
        $own = $pdo->prepare("SELECT status FROM offers WHERE id=? AND listing_id=?");
        $own->execute([$oid, $lid]);
        $row = $own->fetch();
        if ($row && $row['status'] === 'active') {
            $pdo->prepare("UPDATE offers SET status='paused', updated_at=NOW() WHERE id=? AND listing_id=?")->execute([$oid, $lid]);
            flash('success', t('Offer paused.','Offre mise en pause.'));
        }

    } elseif ($action === 'resume') {
        $oid = (int)($_POST['offer_id'] ?? 0);
        $own = $pdo->prepare("SELECT status FROM offers WHERE id=? AND listing_id=?");
        $own->execute([$oid, $lid]);
        $row = $own->fetch();
        if ($row && $row['status'] === 'paused') {
            // Re-check entitlement (paused still counts, so resuming is always ok if still within limit)
            $pdo->prepare("UPDATE offers SET status='active', updated_at=NOW() WHERE id=? AND listing_id=?")->execute([$oid, $lid]);
            flash('success', t('Offer resumed.','Offre reprise.'));
        }

    // ── Archive ───────────────────────────────────────────────────────────────
    } elseif ($action === 'archive') {
        $oid = (int)($_POST['offer_id'] ?? 0);
        $own = $pdo->prepare("SELECT status FROM offers WHERE id=? AND listing_id=?");
        $own->execute([$oid, $lid]);
        $row = $own->fetch();
        if ($row && in_array($row['status'], ['paused','expired'])) {
            $pdo->prepare("UPDATE offers SET status='archived', updated_at=NOW() WHERE id=? AND listing_id=?")->execute([$oid, $lid]);
            flash('success', t('Offer archived.','Offre archivée.'));
        }

    // ── Delete / Expire ───────────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $oid = (int)($_POST['offer_id'] ?? 0);
        $own = $pdo->prepare("SELECT status FROM offers WHERE id=? AND listing_id=?");
        $own->execute([$oid, $lid]);
        $row = $own->fetch();
        if (!$row) {
            flash('error', t('Offer not found.','Offre introuvable.'));
        } elseif ($row['status'] === 'active') {
            $pdo->prepare("UPDATE offers SET status='expired', updated_at=NOW() WHERE id=? AND listing_id=?")->execute([$oid, $lid]);
            flash('success', t('Offer expired.','Offre expirée.'));
        } elseif ($row['status'] === 'suspended_admin') {
            flash('error', t('This offer has been suspended by an administrator and cannot be deleted.','Cette offre a été suspendue par un administrateur et ne peut pas être supprimée.'));
        } else {
            $pdo->prepare("DELETE FROM offers WHERE id=? AND listing_id=? AND status NOT IN ('active','suspended_admin')")->execute([$oid, $lid]);
            flash('success', t('Offer deleted.','Offre supprimée.'));
        }
    }

    redirect(SITE_URL . '/partner/offers?listing_id=' . $lid);
}

// ── Load current offers for this listing ──────────────────────────────────────
$offers = $pdo->prepare("
    SELECT o.*,
           (SELECT dc.code FROM discount_codes dc WHERE dc.offer_id = o.id AND dc.code_type='shared' LIMIT 1) AS shared_code,
           (SELECT COUNT(*) FROM discount_codes dc WHERE dc.offer_id = o.id AND dc.code_type='unique') AS unique_codes_issued,
           (SELECT COUNT(*) FROM offer_redemptions r WHERE r.offer_id = o.id AND r.status='confirmed') AS confirmed_redemptions
    FROM offers o
    WHERE o.listing_id = ?
    ORDER BY
        FIELD(o.status,'active','scheduled','paused','pending','draft','expired','rejected','archived','suspended_admin'),
        o.created_at DESC
");
$offers->execute([$lid]);
$offers = $offers->fetchAll();

// Editing a specific offer?
$editing = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($offers as $o) {
        if ((int)$o['id'] === $eid) { $editing = $o; break; }
    }
}

// Entitlement info for slot display
$ent = getListingEntitlements($lid);

// ── Page ──────────────────────────────────────────────────────────────────────
$pageTitle = t('My Offers & Discounts','Mes Offres & Réductions') . ' — 237Biz';
require_once __DIR__ . '/../includes/header.php';

$statusLabels = [
    'active'          => ['#4ade80', t('Active','Actif')],
    'scheduled'       => ['#60a5fa', t('Scheduled','Planifié')],
    'paused'          => ['#fbbf24', t('Paused','En Pause')],
    'pending'         => ['#f59e0b', t('In Review','En Révision')],
    'draft'           => ['#8db8a8', t('Draft','Brouillon')],
    'expired'         => ['#6b7280', t('Expired','Expiré')],
    'rejected'        => ['#f87171', t('Rejected','Rejeté')],
    'archived'        => ['#4b5563', t('Archived','Archivé')],
    'suspended_admin' => ['#dc2626', t('Suspended','Suspendu')],
];

$discTypeLabels = [
    'percent_off'  => t('% Off','% de Réduction'),
    'fixed_off'    => t('Fixed Amount Off','Montant Fixe'),
    'fixed_price'  => t('Fixed Price','Prix Fixe'),
    'bogo'         => t('Buy 1 Get 1','Achetez-en 1, obtenez-en 1'),
    'free_item'    => t('Free Item','Article Gratuit'),
    'bundle'       => t('Bundle Deal','Pack Spécial'),
];
?>
<style>
.po-wrap { max-width:960px; margin:2rem auto; padding:0 1rem; }
.po-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; }
.po-title  { font-family:'Fraunces',serif; font-weight:900; font-size:1.6rem; color:#f0faf5; }
.po-back   { font-size:0.82rem; color:#5a8878; text-decoration:none; display:block; margin-bottom:0.25rem; }
.po-back:hover { color:#00A878; }

/* Entitlement bar */
.po-ent-bar {
  display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
  background:rgba(0,168,120,0.05); border:1px solid rgba(0,168,120,0.15);
  border-radius:10px; padding:0.7rem 1rem; margin-bottom:1.5rem; font-size:0.82rem;
}
.po-ent-bar .slot-used { color:#4ade80; font-weight:700; }
.po-ent-bar .slot-lim  { color:#5a8878; }
.po-ent-bar .slot-warn { color:#fbbf24; font-weight:700; }

/* Listing switcher */
.po-listing-bar {
  display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;
  background:rgba(0,0,0,0.25); border:1px solid rgba(0,168,120,0.12);
  border-radius:10px; padding:0.6rem 0.9rem; margin-bottom:1.5rem; font-size:0.82rem;
}
.po-listing-bar label { color:#5a8878; }
.po-listing-bar select {
  background:rgba(0,0,0,0.3); border:1px solid rgba(0,168,120,0.25);
  color:#e8f5ee; border-radius:6px; padding:0.3rem 0.6rem; font-size:0.82rem;
}

/* Form */
.po-form-card {
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.2);
  border-radius:12px; padding:1.5rem; margin-bottom:2rem;
}
.po-form-section { margin-bottom:1.5rem; }
.po-form-section-title {
  font-size:0.7rem; font-weight:800; letter-spacing:0.1em;
  text-transform:uppercase; color:#5a8878; margin-bottom:0.75rem;
  padding-bottom:0.4rem; border-bottom:1px solid rgba(0,168,120,0.1);
}
.po-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.9rem; }
@media(max-width:560px) { .po-form-grid { grid-template-columns:1fr; } }
.po-form-group { display:flex; flex-direction:column; gap:0.3rem; }
.po-form-group.full { grid-column:1/-1; }
.po-form-group label { font-size:0.78rem; color:#8db8a8; font-weight:600; letter-spacing:0.04em; }
.po-form-group input,
.po-form-group select,
.po-form-group textarea {
  background:rgba(0,0,0,0.3); border:1px solid rgba(0,168,120,0.2);
  color:#e8f5ee; border-radius:8px; padding:0.55rem 0.75rem; font-size:0.88rem;
  transition:border-color 0.15s;
}
.po-form-group input:focus,
.po-form-group select:focus,
.po-form-group textarea:focus { outline:none; border-color:rgba(0,168,120,0.5); }
.po-form-group textarea { resize:vertical; min-height:80px; }
.po-form-hint { font-size:0.72rem; color:#5a8878; margin-top:0.2rem; }
.po-form-checkbox { display:flex; align-items:center; gap:0.5rem; padding:0.55rem 0; }
.po-form-checkbox input[type=checkbox] { width:1rem; height:1rem; accent-color:#00A878; }
.po-form-checkbox label { font-size:0.84rem; color:#8db8a8; cursor:pointer; }
.po-form-actions { display:flex; gap:0.75rem; margin-top:1.2rem; flex-wrap:wrap; }

/* Code type callout */
.code-type-info {
  background:rgba(0,168,120,0.05); border:1px solid rgba(0,168,120,0.15);
  border-radius:8px; padding:0.75rem 1rem; font-size:0.82rem; color:#8db8a8; margin-top:0.5rem;
}
.code-type-info strong { color:#e8f5ee; }
.code-type-info code {
  background:rgba(0,0,0,0.3); border-radius:4px; padding:0.1rem 0.4rem;
  font-family:monospace; color:#4ade80; font-size:0.88rem;
}

/* Offer list */
.po-offers { display:flex; flex-direction:column; gap:1rem; }
.po-offer-row {
  display:grid; grid-template-columns:auto 1fr auto;
  align-items:start; gap:1rem;
  background:rgba(255,255,255,0.025); border:1px solid rgba(0,168,120,0.12);
  border-radius:10px; padding:1rem;
}
@media(max-width:600px) { .po-offer-row { grid-template-columns:1fr; } }
.po-offer-img { width:64px; height:64px; object-fit:cover; border-radius:8px; flex-shrink:0; }
.po-offer-placeholder {
  width:64px; height:64px; border-radius:8px; flex-shrink:0;
  background:rgba(0,168,120,0.08); display:flex; align-items:center;
  justify-content:center; font-size:1.6rem;
}
.po-offer-title { font-weight:700; color:#e8f5ee; font-size:0.9rem; margin-bottom:0.25rem; }
.po-offer-meta  { font-size:0.75rem; color:#5a8878; display:flex; gap:0.6rem; flex-wrap:wrap; }
.po-status-pill {
  display:inline-block; padding:0.2rem 0.6rem; border-radius:99px;
  font-size:0.68rem; font-weight:700; color:#fff; letter-spacing:0.04em;
}
.po-code-chip {
  display:inline-flex; align-items:center; gap:0.35rem;
  background:rgba(0,168,120,0.1); border:1px solid rgba(0,168,120,0.25);
  border-radius:6px; padding:0.2rem 0.55rem;
  font-size:0.75rem; font-weight:700; color:#4ade80; font-family:monospace;
}
.po-offer-actions { display:flex; gap:0.4rem; flex-wrap:wrap; flex-shrink:0; align-items:flex-start; }
.po-offer-actions a,
.po-offer-actions button {
  font-size:0.76rem; padding:0.3rem 0.6rem; border-radius:6px;
  cursor:pointer; text-decoration:none; white-space:nowrap; line-height:1.4;
}
.act-view   { background:rgba(0,168,120,0.08); color:#00A878; border:1px solid rgba(0,168,120,0.2); }
.act-edit   { background:rgba(0,168,120,0.06); color:#8db8a8; border:1px solid rgba(0,168,120,0.15); }
.act-pause  { background:rgba(251,191,36,0.08); color:#fbbf24; border:1px solid rgba(251,191,36,0.25); }
.act-resume { background:rgba(74,222,128,0.08); color:#4ade80; border:1px solid rgba(74,222,128,0.25); }
.act-archive{ background:rgba(107,114,128,0.08); color:#6b7280; border:1px solid rgba(107,114,128,0.25); }
.act-del    { background:transparent; color:#f87171; border:1px solid rgba(248,113,113,0.25); }
.po-admin-note {
  margin-top:0.5rem; font-size:0.75rem; color:#fbbf24;
  background:rgba(251,191,36,0.08); border-left:2px solid #fbbf24;
  padding:0.3rem 0.6rem; border-radius:0 4px 4px 0;
}
.po-suspend-note {
  margin-top:0.5rem; font-size:0.75rem; color:#f87171;
  background:rgba(248,113,113,0.08); border-left:2px solid #f87171;
  padding:0.3rem 0.6rem; border-radius:0 4px 4px 0;
}
.po-empty { text-align:center; padding:3rem 1rem; color:#5a8878; }
.po-empty big { font-size:2.5rem; display:block; margin-bottom:0.75rem; }
.po-btn { padding:0.6rem 1.2rem; border-radius:8px; font-weight:700; font-size:0.88rem;
  border:none; cursor:pointer; text-decoration:none; display:inline-block; transition:all 0.15s; }
.po-btn-primary { background:#00A878; color:#fff; }
.po-btn-primary:hover { background:#00c48f; }
.po-btn-outline { background:transparent; border:1px solid rgba(0,168,120,0.3); color:#00A878; }
.po-btn-add {
  display:inline-flex; align-items:center; gap:0.4rem;
  background:#00A878; color:#fff; padding:0.55rem 1rem;
  border-radius:8px; font-weight:700; font-size:0.85rem; text-decoration:none;
}
.po-btn-add:hover { background:#00c48f; }
</style>

<?php flash('display'); ?>

<div class="po-wrap">
  <!-- Header -->
  <div class="po-header">
    <div>
      <a href="<?= SITE_URL ?>/partner/dashboard" class="po-back">← <?= t('Partner Dashboard','Tableau de Bord Partenaire') ?></a>
      <h1 class="po-title">🏷️ <?= t('Offers & Discounts','Offres & Réductions') ?></h1>
    </div>
    <?php if (!$editing && !isset($_GET['new'])): ?>
      <?php if ($ent['can_publish_offer']): ?>
        <a href="?listing_id=<?= $lid ?>&new=1" class="po-btn-add">+ <?= t('Create Offer','Créer une Offre') ?></a>
      <?php else: ?>
        <span style="font-size:0.82rem;color:#fbbf24;">⚠️ <?= t('Offer slot limit reached','Limite d\'offres atteinte') ?></span>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Entitlement bar -->
  <div class="po-ent-bar">
    <span>
      <?= t('Plan:','Plan :') ?>
      <strong style="color:#e8f5ee;"><?= e($isFr ? $ent['plan_name_fr'] : $ent['plan_name_en']) ?></strong>
    </span>
    <span class="slot-lim">|</span>
    <span>
      <?= t('Active offer slots:','Créneaux d\'offres :') ?>
      <?php $slotClass = $ent['used_offers'] >= $ent['total_offers'] && $ent['total_offers'] > 0 ? 'slot-warn' : 'slot-used'; ?>
      <span class="<?= $slotClass ?>"><?= $ent['used_offers'] ?></span>
      <span class="slot-lim"> / <?= $ent['total_offers'] > 0 ? $ent['total_offers'] : t('Unlimited','Illimité') ?></span>
    </span>
    <?php if (!$ent['can_publish_offer'] && $ent['total_offers'] > 0): ?>
      <span class="slot-warn">— <?= t('Limit reached. Archive a paused/expired offer to free a slot.','Limite atteinte. Archivez une offre en pause/expirée pour libérer un créneau.') ?></span>
    <?php endif; ?>
    <a href="<?= SITE_URL ?>/partner/manage-offer-codes?listing_id=<?= $lid ?>" style="margin-left:auto;font-size:0.78rem;color:#00A878;">
      🏪 <?= t('In-Store Redemption','Rachat en Boutique') ?>
    </a>
  </div>

  <!-- Listing switcher -->
  <?php if (count($myListings) > 1): ?>
    <div class="po-listing-bar">
      <label for="ls-sel"><?= t('Business:','Entreprise :') ?></label>
      <select id="ls-sel" onchange="location.href='<?= SITE_URL ?>/partner/offers?listing_id='+this.value">
        <?php foreach ($myListings as $ml): ?>
          <option value="<?= $ml['id'] ?>" <?= $ml['id'] === $lid ? 'selected' : '' ?>>
            <?= htmlspecialchars($ml['title'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>

  <!-- ═══════ CREATE / EDIT FORM ═══════ -->
  <?php if ($editing || isset($_GET['new'])): ?>
    <div class="po-form-card">
      <h2 style="font-family:'Fraunces',serif;font-weight:800;font-size:1.1rem;color:#e8f5ee;margin-bottom:1.5rem;">
        <?= $editing ? t('Edit Offer','Modifier l\'Offre') : t('New Offer','Nouvelle Offre') ?>
      </h2>
      <form method="POST" action="?listing_id=<?= $lid ?>" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action"     value="<?= $editing ? 'update' : 'create' ?>">
        <input type="hidden" name="listing_id" value="<?= $lid ?>">
        <?php if ($editing): ?>
          <input type="hidden" name="offer_id"       value="<?= $editing['id'] ?>">
          <input type="hidden" name="existing_image"  value="<?= htmlspecialchars($editing['image'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>

        <!-- ── Section 1: Offer Details ─────────────────────────────────── -->
        <div class="po-form-section">
          <div class="po-form-section-title"><?= t('1 · Offer Details','1 · Détails de l\'Offre') ?></div>
          <div class="po-form-grid">
            <div class="po-form-group">
              <label>🇬🇧 <?= t('Title (English)*','Titre (Anglais)*') ?></label>
              <input type="text" name="title" required maxlength="160"
                     value="<?= htmlspecialchars($editing['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="<?= t('e.g. 20% Off All Services','ex: 20% de réduction sur tous les services') ?>">
            </div>
            <div class="po-form-group">
              <label>🇫🇷 <?= t('Title (French)','Titre (Français)') ?></label>
              <input type="text" name="title_fr" maxlength="160"
                     value="<?= htmlspecialchars($editing['title_fr'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="<?= t('e.g. 20% de réduction','ex: 20% de réduction') ?>">
            </div>
            <div class="po-form-group full">
              <label>🇬🇧 <?= t('Description (English)','Description (Anglais)') ?></label>
              <textarea name="description" placeholder="<?= t('What do customers get?','Que reçoivent les clients ?') ?>"><?= htmlspecialchars($editing['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="po-form-group full">
              <label>🇫🇷 <?= t('Description (French)','Description (Français)') ?></label>
              <textarea name="description_fr" placeholder="<?= t('Décrivez l\'offre en français...','Describe the offer in French...') ?>"><?= htmlspecialchars($editing['description_fr'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="po-form-group">
              <label><?= t('Start Date*','Date de Début*') ?></label>
              <input type="datetime-local" name="starts_at" required
                     value="<?= $editing ? date('Y-m-d\TH:i', strtotime($editing['starts_at'])) : date('Y-m-d\TH:i') ?>">
            </div>
            <div class="po-form-group">
              <label><?= t('Expiry Date','Date d\'Expiration') ?></label>
              <input type="datetime-local" name="expires_at"
                     value="<?= $editing && $editing['expires_at'] ? date('Y-m-d\TH:i', strtotime($editing['expires_at'])) : '' ?>">
              <span class="po-form-hint"><?= t('Leave blank for no expiry.','Laisser vide pour sans expiration.') ?></span>
            </div>
            <div class="po-form-group">
              <label><?= t('Offer Image','Image de l\'Offre') ?></label>
              <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
              <?php if ($editing && $editing['image']): ?>
                <span class="po-form-hint">✅ <?= t('Image saved — upload to replace','Image enregistrée — téléchargez pour remplacer') ?></span>
              <?php endif; ?>
            </div>
            <div class="po-form-group">
              <label><?= t('Eligible Products / Services','Produits / Services éligibles') ?></label>
              <input type="text" name="eligible_items" maxlength="255"
                     value="<?= htmlspecialchars($editing['eligible_items'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="<?= t('e.g. All haircuts, Large pizza only','ex: Toutes les coupes, Grande pizza uniquement') ?>">
              <span class="po-form-hint"><?= t('Leave blank to apply to all products.','Laisser vide pour tous les produits.') ?></span>
            </div>
          </div>
        </div>

        <!-- ── Section 2: Discount Rules ────────────────────────────────── -->
        <div class="po-form-section">
          <div class="po-form-section-title"><?= t('2 · Discount Rules','2 · Règles de Réduction') ?></div>
          <div class="po-form-grid">
            <div class="po-form-group">
              <label><?= t('Discount Type*','Type de Réduction*') ?></label>
              <select name="discount_type" id="disc-type-sel" onchange="updateDiscountFields()">
                <?php $selType = $editing['discount_type'] ?? $editing['offer_type'] ?? 'percent_off'; ?>
                <option value="percent_off"  <?= $selType==='percent_off' ?'selected':'' ?>><?= t('Percentage Off (e.g. 20%)','Pourcentage (ex: 20%)') ?></option>
                <option value="fixed_off"    <?= $selType==='fixed_off'  ?'selected':'' ?>><?= t('Fixed Amount Off (XAF)','Montant Fixe (XAF)') ?></option>
                <option value="fixed_price"  <?= $selType==='fixed_price'?'selected':'' ?>><?= t('Fixed Promo Price (XAF)','Prix Promo Fixe (XAF)') ?></option>
                <option value="bogo"         <?= $selType==='bogo'       ?'selected':'' ?>><?= t('Buy 1 Get 1 Free','Achetez-en 1, obtenez-en 1') ?></option>
                <option value="free_item"    <?= $selType==='free_item'  ?'selected':'' ?>><?= t('Free Item Included','Article Gratuit Inclus') ?></option>
                <option value="bundle"       <?= $selType==='bundle'     ?'selected':'' ?>><?= t('Bundle Deal','Pack Spécial') ?></option>
              </select>
            </div>
            <div class="po-form-group" id="disc-val-group">
              <label id="disc-val-lbl"><?= t('Value*','Valeur*') ?></label>
              <input type="number" name="discount_value" id="disc-val-inp"
                     value="<?= htmlspecialchars($editing['discount_value'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     min="0" step="0.01" placeholder="20">
            </div>
            <div class="po-form-group" id="max-disc-group">
              <label><?= t('Max Discount Cap (XAF)','Plafond de Réduction (XAF)') ?></label>
              <input type="number" name="max_discount" min="0" step="1"
                     value="<?= htmlspecialchars($editing['max_discount'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="<?= t('e.g. 10000','ex: 10000') ?>">
              <span class="po-form-hint"><?= t('Applies to % off only. Leave blank for no cap.','Pour les % seulement. Laisser vide pour pas de plafond.') ?></span>
            </div>
            <div class="po-form-group">
              <label><?= t('Original Price (XAF)','Prix Original (XAF)') ?></label>
              <input type="number" name="original_price" min="0" step="1"
                     value="<?= htmlspecialchars($editing['original_price'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="50000">
              <span class="po-form-hint"><?= t('Optional — helps customers see the saving.','Optionnel — aide les clients à voir l\'économie.') ?></span>
            </div>
            <div class="po-form-group">
              <label><?= t('Minimum Spend (XAF)','Achat Minimum (XAF)') ?></label>
              <input type="number" name="min_spend" min="0" step="1"
                     value="<?= htmlspecialchars($editing['min_spend'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="<?= t('e.g. 5000','ex: 5000') ?>">
              <span class="po-form-hint"><?= t('Leave blank for no minimum.','Laisser vide pour sans minimum.') ?></span>
            </div>
            <div class="po-form-group" style="justify-content:flex-end;">
              <div class="po-form-checkbox">
                <input type="checkbox" name="combinable" id="combinable-chk"
                       <?= ($editing['combinable'] ?? 0) ? 'checked' : '' ?>>
                <label for="combinable-chk"><?= t('Combinable with other offers','Cumulable avec d\'autres offres') ?></label>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Section 3: Usage Limits ───────────────────────────────────── -->
        <div class="po-form-section">
          <div class="po-form-section-title"><?= t('3 · Usage Limits','3 · Limites d\'Utilisation') ?></div>
          <div class="po-form-grid">
            <div class="po-form-group">
              <label><?= t('Max Redemptions (Total)','Utilisations Maximales (Total)') ?></label>
              <input type="number" name="max_redemptions" min="1"
                     value="<?= htmlspecialchars($editing['max_redemptions'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="100">
              <span class="po-form-hint"><?= t('Leave blank for unlimited.','Laisser vide pour illimité.') ?></span>
            </div>
            <div class="po-form-group">
              <label><?= t('Max Redemptions Per Customer','Utilisations Maximum par Client') ?></label>
              <input type="number" name="max_claims_per_user" min="1" max="99"
                     value="<?= htmlspecialchars($editing['max_claims_per_user'] ?? '1', ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="1">
            </div>
          </div>
        </div>

        <!-- ── Section 4: Code Generation ───────────────────────────────── -->
        <div class="po-form-section">
          <div class="po-form-section-title"><?= t('4 · Discount Code','4 · Code de Réduction') ?></div>

          <?php if ($editing && $editing['code_type']): ?>
            <!-- Code type is locked after creation -->
            <div class="code-type-info">
              🔒 <?= t('Code type is locked after the offer is created.','Le type de code est verrouillé après la création.') ?>
              <strong><?= $editing['code_type'] === 'shared'
                ? t('Type A — Shared Campaign Code','Type A — Code de Campagne Partagé')
                : t('Type B — Unique Voucher Codes','Type B — Codes Uniques') ?></strong>
              <?php if ($editing['shared_code']): ?>
                <br><span style="margin-top:0.5rem;display:block;"><?= t('Your code:','Votre code :') ?> <code><?= e($editing['shared_code']) ?></code></span>
              <?php elseif ($editing['unique_codes_issued']): ?>
                <br><span style="margin-top:0.5rem;display:block;"><?= $editing['unique_codes_issued'] ?> <?= t('unique codes issued','codes uniques générés') ?></span>
              <?php endif; ?>
            </div>
            <input type="hidden" name="code_type" value="<?= e($editing['code_type']) ?>">
          <?php else: ?>
            <div class="po-form-grid">
              <div class="po-form-group full">
                <label><?= t('Code Generation Mode','Mode de Génération des Codes') ?></label>
                <select name="code_type" id="code-type-sel" onchange="updateCodeTypeInfo()">
                  <option value="shared"><?= t('Type A — Shared Campaign Code (one code, multiple customers)','Type A — Code de Campagne Partagé (un code, plusieurs clients)') ?></option>
                  <option value="unique"><?= t('Type B — Unique Voucher Codes (one code per customer)','Type B — Codes Uniques (un code par client)') ?></option>
                </select>
                <span class="po-form-hint">⚠️ <?= t('237biz generates all codes automatically — you cannot set the code string.','237biz génère tous les codes automatiquement — vous ne pouvez pas choisir le code.') ?></span>
              </div>
              <div class="po-form-group" id="batch-size-group" style="display:none;">
                <label><?= t('Initial Batch Size (Unique Codes)','Taille du Lot Initial (Codes Uniques)') ?></label>
                <input type="number" name="batch_size" min="1" max="10000" placeholder="100"
                       value="<?= htmlspecialchars($editing['batch_size'] ?? '100', ENT_QUOTES, 'UTF-8') ?>">
                <span class="po-form-hint"><?= t('How many unique vouchers to pre-generate.','Combien de codes uniques à pré-générer.') ?></span>
              </div>
            </div>
            <div class="code-type-info" id="code-type-info-box">
              <strong><?= t('Type A — Shared Campaign Code','Type A — Code de Campagne Partagé') ?></strong><br>
              <?= t('One code (e.g. <code>7KMP4X9D</code>) published on your offer. All customers use the same code until the redemption limit is reached.','Un code (ex: <code>7KMP4X9D</code>) publié sur votre offre. Tous les clients utilisent le même code jusqu\'à la limite.') ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- ── Section 5: Terms ──────────────────────────────────────────── -->
        <div class="po-form-section">
          <div class="po-form-section-title"><?= t('5 · Terms & Preview','5 · Conditions et Aperçu') ?></div>
          <div class="po-form-group">
            <label><?= t('Terms & Conditions','Termes et Conditions') ?></label>
            <textarea name="terms" placeholder="<?= t('e.g. Valid on weekdays only. Cannot be combined with other offers. In-store only.','ex: Valable en semaine uniquement. Non cumulable. En boutique uniquement.') ?>"><?= htmlspecialchars($editing['terms'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
          <?php if (!$editing): ?>
            <p style="font-size:0.75rem;color:#5a8878;margin-top:0.75rem;">
              ℹ️ <?= t('Offers are reviewed by our team before going live (usually within 24 hours). If your offer starts in the future it will be automatically scheduled.','Les offres sont examinées par notre équipe avant publication (généralement sous 24 heures). Si votre offre commence dans le futur, elle sera automatiquement planifiée.') ?>
            </p>
          <?php endif; ?>
        </div>

        <div class="po-form-actions">
          <button type="submit" class="po-btn po-btn-primary">
            <?= $editing ? t('Save Changes','Enregistrer les Modifications') : t('Submit for Review','Soumettre pour Révision') ?>
          </button>
          <a href="?listing_id=<?= $lid ?>" class="po-btn po-btn-outline"><?= t('Cancel','Annuler') ?></a>
        </div>
      </form>
    </div>

  <?php else: ?>
    <!-- ═══════ OFFERS LIST ═══════ -->
    <?php if ($offers): ?>
      <div class="po-offers">
        <?php foreach ($offers as $o):
          $oTitle = $isFr && $o['title_fr'] ? $o['title_fr'] : $o['title'];
          [$scol, $slbl] = $statusLabels[$o['status']] ?? ['#8db8a8', $o['status']];
          $discLabel = $discTypeLabels[$o['discount_type'] ?? $o['offer_type']] ?? '';
          if ($o['discount_type'] === 'percent_off' && $o['discount_value'])
              $discLabel = '-' . (int)$o['discount_value'] . '% ' . t('off','rabais');
          elseif ($o['discount_type'] === 'fixed_off' && $o['discount_value'])
              $discLabel = number_format($o['discount_value']) . ' XAF ' . t('off','rabais');
          elseif ($o['discount_type'] === 'fixed_price' && $o['discount_value'])
              $discLabel = number_format($o['discount_value']) . ' XAF';
          $canEdit   = in_array($o['status'], ['draft','pending','scheduled','rejected']);
          $canPause  = $o['status'] === 'active';
          $canResume = $o['status'] === 'paused';
          $canArchive= in_array($o['status'], ['paused','expired']);
          $canDelete = !in_array($o['status'], ['active','suspended_admin']);
          $isSuspended = $o['status'] === 'suspended_admin';
        ?>
          <div class="po-offer-row">
            <!-- Thumbnail -->
            <div>
              <?php if ($o['image']): ?>
                <img src="<?= UPLOAD_URL . htmlspecialchars($o['image'], ENT_QUOTES, 'UTF-8') ?>"
                     alt="offer" class="po-offer-img">
              <?php else: ?>
                <div class="po-offer-placeholder">🏷️</div>
              <?php endif; ?>
            </div>

            <!-- Info -->
            <div>
              <div class="po-offer-title"><?= htmlspecialchars($oTitle, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="po-offer-meta">
                <span class="po-status-pill" style="background:<?= $scol ?>;"><?= $slbl ?></span>
                <?php if ($discLabel): ?><span>· <?= htmlspecialchars($discLabel, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                <?php if ($o['expires_at']): ?><span>· ⏰ <?= date('d M Y', strtotime($o['expires_at'])) ?></span><?php endif; ?>
                <?php if ($o['confirmed_redemptions']): ?><span>· 🎫 <?= (int)$o['confirmed_redemptions'] ?> <?= t('redeemed','rachetés') ?></span><?php endif; ?>
                <?php if ($o['code_type'] === 'shared' && $o['shared_code']): ?>
                  <span class="po-code-chip">🔑 <?= htmlspecialchars($o['shared_code'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php elseif ($o['code_type'] === 'unique'): ?>
                  <span class="po-code-chip">🎟 <?= (int)$o['unique_codes_issued'] ?> <?= t('codes','codes') ?></span>
                <?php endif; ?>
              </div>

              <?php if ($o['status'] === 'rejected' && $o['admin_notes']): ?>
                <div class="po-admin-note">⚠️ <?= htmlspecialchars($o['admin_notes'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
              <?php if ($isSuspended && $o['admin_notes']): ?>
                <div class="po-suspend-note">🚫 <?= t('Suspended by admin:','Suspendu par l\'admin :') ?> <?= htmlspecialchars($o['admin_notes'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="po-offer-actions">
              <?php if (in_array($o['status'], ['active','scheduled'])): ?>
                <a href="<?= SITE_URL ?>/offer/<?= htmlspecialchars($o['slug'], ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" class="act-view">👁 <?= t('View','Voir') ?></a>
              <?php endif; ?>

              <?php if ($canEdit): ?>
                <a href="?listing_id=<?= $lid ?>&edit=<?= $o['id'] ?>" class="act-edit">✏ <?= t('Edit','Modifier') ?></a>
              <?php endif; ?>

              <?php if ($canPause): ?>
                <form method="POST" action="?listing_id=<?= $lid ?>" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"     value="pause">
                  <input type="hidden" name="listing_id" value="<?= $lid ?>">
                  <input type="hidden" name="offer_id"   value="<?= $o['id'] ?>">
                  <button type="submit" class="act-pause">⏸ <?= t('Pause','Pauser') ?></button>
                </form>
              <?php endif; ?>

              <?php if ($canResume): ?>
                <form method="POST" action="?listing_id=<?= $lid ?>" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"     value="resume">
                  <input type="hidden" name="listing_id" value="<?= $lid ?>">
                  <input type="hidden" name="offer_id"   value="<?= $o['id'] ?>">
                  <button type="submit" class="act-resume">▶ <?= t('Resume','Reprendre') ?></button>
                </form>
              <?php endif; ?>

              <?php if ($canArchive): ?>
                <form method="POST" action="?listing_id=<?= $lid ?>" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"     value="archive">
                  <input type="hidden" name="listing_id" value="<?= $lid ?>">
                  <input type="hidden" name="offer_id"   value="<?= $o['id'] ?>">
                  <button type="submit" class="act-archive">📦 <?= t('Archive','Archiver') ?></button>
                </form>
              <?php endif; ?>

              <?php if ($canDelete || $o['status'] === 'active'): ?>
                <form method="POST" action="?listing_id=<?= $lid ?>" style="display:inline"
                      onsubmit="return confirm('<?= $o['status'] === 'active'
                        ? t('This will mark the offer as expired. Continue?','Cela marquera l\'offre comme expirée. Continuer ?')
                        : t('Delete this offer? This cannot be undone.','Supprimer cette offre ? Cette action est irréversible.') ?>')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"     value="delete">
                  <input type="hidden" name="listing_id" value="<?= $lid ?>">
                  <input type="hidden" name="offer_id"   value="<?= $o['id'] ?>">
                  <button type="submit" class="act-del">
                    <?= $o['status'] === 'active' ? t('Expire','Expirer') : t('Delete','Supprimer') ?>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <div class="po-empty">
        <big>🏷️</big>
        <p><?= t('No offers yet. Create your first discount to attract more customers!','Aucune offre pour l\'instant. Créez votre première réduction pour attirer plus de clients !') ?></p>
        <?php if ($ent['can_publish_offer']): ?>
          <a href="?listing_id=<?= $lid ?>&new=1" class="po-btn-add">+ <?= t('Create First Offer','Créer ma Première Offre') ?></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Footer links -->
  <p style="margin-top:2rem;font-size:0.78rem;color:#5a8878;text-align:center;">
    <a href="<?= SITE_URL ?>/offers" style="color:#00A878;">🏷️ <?= t('Browse All Offers','Toutes les Offres') ?></a>
    &nbsp;·&nbsp;
    <a href="<?= SITE_URL ?>/partner/manage-offer-codes?listing_id=<?= $lid ?>" style="color:#8db8a8;">🏪 <?= t('In-Store Redemption','Rachat en Boutique') ?></a>
    &nbsp;·&nbsp;
    <a href="<?= SITE_URL ?>/partner/events?listing_id=<?= $lid ?>" style="color:#8db8a8;">📅 <?= t('Events','Événements') ?></a>
  </p>
</div>

<script>
function updateDiscountFields() {
    const type  = document.getElementById('disc-type-sel').value;
    const grp   = document.getElementById('disc-val-group');
    const lbl   = document.getElementById('disc-val-lbl');
    const inp   = document.getElementById('disc-val-inp');
    const capGrp= document.getElementById('max-disc-group');
    const noVal = ['bogo','free_item','bundle'];

    if (noVal.includes(type)) {
        grp.style.opacity = '0.4'; inp.disabled = true; inp.required = false;
        capGrp.style.display = 'none';
    } else {
        grp.style.opacity = '1'; inp.disabled = false;
        if (type === 'percent_off') {
            lbl.textContent = '<?= t('Discount %*','% de Réduction*') ?>';
            inp.placeholder = '20'; inp.max = '100'; inp.required = true;
            capGrp.style.display = '';
        } else if (type === 'fixed_off') {
            lbl.textContent = '<?= t('Amount Off (XAF)*','Montant (XAF)*') ?>';
            inp.placeholder = '5000'; inp.max = ''; inp.required = true;
            capGrp.style.display = 'none';
        } else if (type === 'fixed_price') {
            lbl.textContent = '<?= t('Promo Price (XAF)*','Prix Promo (XAF)*') ?>';
            inp.placeholder = '25000'; inp.max = ''; inp.required = true;
            capGrp.style.display = 'none';
        }
    }
}

function updateCodeTypeInfo() {
    const sel = document.getElementById('code-type-sel');
    const box = document.getElementById('code-type-info-box');
    const bsg = document.getElementById('batch-size-group');
    if (!sel) return;
    const batchSizeInput = document.querySelector('[name="batch_size"]');

    if (sel.value === 'shared') {
        box.innerHTML = '<strong><?= t('Type A — Shared Campaign Code','Type A — Code de Campagne Partagé') ?></strong><br><?= t('One code (e.g. <code>7KMP4X9D</code>) published on your offer. All customers use the same code until the redemption limit is reached.','Un code (ex: <code>7KMP4X9D</code>) publié sur votre offre. Tous les clients utilisent le même code jusqu\'à la limite.') ?>';
        bsg.style.display = 'none';
        if (batchSizeInput) batchSizeInput.required = false;
    } else {
        box.innerHTML = '<strong><?= t('Type B — Unique Voucher Codes','Type B — Codes Uniques') ?></strong><br><?= t('Each customer receives their own code (e.g. <code>Q8M4T7XP2K</code>). Great for private campaigns and customer rewards.','Chaque client reçoit son propre code (ex: <code>Q8M4T7XP2K</code>). Idéal pour les campagnes privées et les récompenses clients.') ?>';
        bsg.style.display = '';
        if (batchSizeInput) batchSizeInput.required = true;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateDiscountFields();
    updateCodeTypeInfo();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
