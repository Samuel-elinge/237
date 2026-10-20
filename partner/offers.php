<?php
/**
 * partner/offers.php — Business Owner: Manage Marketplace Deals
 * URL: /partner/offers (or /partner/offers?listing_id=N)
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$isFr   = lang() === 'fr';
$userId = (int)(currentUser()['id'] ?? 0);
$pdo    = db();

// ── Listings owned by this user ───────────────────────────────────────────
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
        'You need an approved listing to create marketplace offers. <a href="'.SITE_URL.'/add-listing">Add your business</a>.',
        'Vous avez besoin d\'une annonce approuvée pour créer des offres. <a href="'.SITE_URL.'/add-listing">Ajoutez votre entreprise</a>.'
    ));
    redirect(SITE_URL . '/partner/dashboard');
}

$lid = (int)($_GET['listing_id'] ?? ($_POST['listing_id'] ?? ($myListings[0]['id'] ?? 0)));
// Ensure user owns the selected listing
$activeListing = null;
foreach ($myListings as $ml) {
    if ($ml['id'] === $lid) { $activeListing = $ml; break; }
}
if (!$activeListing) {
    $lid           = (int)$myListings[0]['id'];
    $activeListing = $myListings[0];
}

// ── Handle actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Create / Update offer ─────────────────────────────────────────────
    if (in_array($action, ['create', 'update'])) {
        $oid      = $action === 'update' ? (int)($_POST['offer_id'] ?? 0) : 0;
        $title    = trim($_POST['title']    ?? '');
        $titleFr  = trim($_POST['title_fr'] ?? '');
        $desc     = trim($_POST['description']    ?? '');
        $descFr   = trim($_POST['description_fr'] ?? '');
        $offerType = $_POST['offer_type'] ?? 'percent_off';
        $discVal  = (float)($_POST['discount_value'] ?? 0);
        $origPx   = $_POST['original_price'] !== '' ? (float)$_POST['original_price'] : null;
        $promoCode= trim($_POST['promo_code'] ?? '');
        $startsAt = $_POST['starts_at'] ?? date('Y-m-d H:i:s');
        $expiresAt= $_POST['expires_at'] !== '' ? $_POST['expires_at'] : null;
        $terms    = trim($_POST['terms'] ?? '');
        $maxRed   = $_POST['max_redemptions'] !== '' ? (int)$_POST['max_redemptions'] : null;

        $validTypes = ['percent_off','fixed_off','bogo','free_item','bundle'];
        if (!$title)                              { flash('error', t('Title is required.','Le titre est requis.')); }
        elseif (!in_array($offerType, $validTypes)){ flash('error', t('Invalid offer type.','Type d\'offre invalide.')); }
        elseif ($offerType === 'percent_off' && ($discVal <= 0 || $discVal > 100)) {
            flash('error', t('Percentage must be 1–100.','Le pourcentage doit être entre 1 et 100.'));
        }
        else {
            // Build slug (update keeps existing)
            if ($action === 'create') {
                $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
                $slug = $base . '-' . substr(md5(uniqid()), 0, 6);
            }

            // Handle image upload
            $image = $_POST['existing_image'] ?? null;
            if (!empty($_FILES['image']['tmp_name'])) {
                $ext    = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed= ['jpg','jpeg','png','webp'];
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
                    $st = $pdo->prepare("
                        INSERT INTO offers
                            (listing_id, title, title_fr, slug, description, description_fr,
                             offer_type, discount_value, original_price, promo_code, image,
                             starts_at, expires_at, status, terms, max_redemptions)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)
                    ");
                    $st->execute([
                        $lid, $title, $titleFr ?: null, $slug, $desc ?: null, $descFr ?: null,
                        $offerType, $discVal > 0 ? $discVal : null, $origPx, $promoCode ?: null,
                        $image, $startsAt, $expiresAt, $terms ?: null, $maxRed
                    ]);
                    flash('success', t('Offer submitted for review — it will go live once approved.','Offre soumise pour révision — elle sera publiée une fois approuvée.'));
                } else {
                    // Verify ownership
                    $own = $pdo->prepare("SELECT id FROM offers WHERE id=? AND listing_id=?");
                    $own->execute([$oid, $lid]);
                    if (!$own->fetch()) { flash('error', t('Offer not found.','Offre introuvable.')); }
                    else {
                        $st = $pdo->prepare("
                            UPDATE offers
                            SET title=?, title_fr=?, description=?, description_fr=?,
                                offer_type=?, discount_value=?, original_price=?, promo_code=?,
                                starts_at=?, expires_at=?, terms=?, max_redemptions=?,
                                image = COALESCE(?,image),
                                status = IF(status IN ('rejected'), 'pending', status),
                                updated_at=NOW()
                            WHERE id=? AND listing_id=?
                        ");
                        $st->execute([
                            $title, $titleFr ?: null, $desc ?: null, $descFr ?: null,
                            $offerType, $discVal > 0 ? $discVal : null, $origPx, $promoCode ?: null,
                            $startsAt, $expiresAt, $terms ?: null, $maxRed,
                            $image,
                            $oid, $lid
                        ]);
                        flash('success', t('Offer updated.','Offre mise à jour.'));
                    }
                }
            } catch (Exception $e) {
                flash('error', t('Could not save offer. Please try again.','Impossible d\'enregistrer l\'offre. Veuillez réessayer.'));
            }
        }

    // ── Delete / expire ───────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $oid = (int)($_POST['offer_id'] ?? 0);
        $own = $pdo->prepare("SELECT status FROM offers WHERE id=? AND listing_id=?");
        $own->execute([$oid, $lid]);
        $row = $own->fetch();
        if (!$row) { flash('error', t('Offer not found.','Offre introuvable.')); }
        elseif ($row['status'] === 'active') {
            $pdo->prepare("UPDATE offers SET status='expired', updated_at=NOW() WHERE id=? AND listing_id=?")->execute([$oid, $lid]);
            flash('success', t('Offer expired.','Offre expirée.'));
        } else {
            $pdo->prepare("DELETE FROM offers WHERE id=? AND listing_id=? AND status NOT IN ('active')")->execute([$oid, $lid]);
            flash('success', t('Offer deleted.','Offre supprimée.'));
        }
    }

    redirect(SITE_URL . '/partner/offers?listing_id=' . $lid);
}

// ── Load current offers for this listing ─────────────────────────────────
$offers = $pdo->prepare("
    SELECT * FROM offers
    WHERE listing_id = ?
    ORDER BY
        FIELD(status,'active','pending','draft','expired','rejected'),
        created_at DESC
");
$offers->execute([$lid]);
$offers = $offers->fetchAll();

// Editing a specific offer?
$editing = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($offers as $o) {
        if ($o['id'] === $eid) { $editing = $o; break; }
    }
}

// ── Page ──────────────────────────────────────────────────────────────────
$pageTitle = t('My Deals & Offers','Mes Offres et Promotions') . ' — 237Biz';
require_once __DIR__ . '/../includes/header.php';

$statusLabels = [
    'active'   => ['#4ade80', t('Active','Actif')],
    'pending'  => ['#fbbf24', t('In Review','En Révision')],
    'draft'    => ['#8db8a8', t('Draft','Brouillon')],
    'expired'  => ['#6b7280', t('Expired','Expiré')],
    'rejected' => ['#f87171', t('Rejected','Rejeté')],
];
?>
<style>
.po-wrap { max-width:900px; margin:2rem auto; padding:0 1rem; }
.po-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; }
.po-title  { font-family:'Fraunces',serif; font-weight:900; font-size:1.6rem; color:#f0faf5; }
.po-back   { font-size:0.82rem; color:#5a8878; text-decoration:none; }
.po-back:hover { color:#00A878; }

/* Listing switcher */
.po-listing-bar {
  display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;
  background:rgba(0,0,0,0.25); border:1px solid rgba(0,168,120,0.12);
  border-radius:10px; padding:0.6rem 0.9rem; margin-bottom:1.5rem;
  font-size:0.82rem;
}
.po-listing-bar label { color:#5a8878; }
.po-listing-bar select {
  background:rgba(0,0,0,0.3); border:1px solid rgba(0,168,120,0.25);
  color:#e8f5ee; border-radius:6px; padding:0.3rem 0.6rem; font-size:0.82rem;
}

/* Offer form */
.po-form-card {
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.2);
  border-radius:12px; padding:1.5rem; margin-bottom:2rem;
}
.po-form-card h2 {
  font-family:'Fraunces',serif; font-weight:800; font-size:1.1rem;
  color:#e8f5ee; margin-bottom:1.2rem;
}
.po-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.9rem; }
@media(max-width:560px) { .po-form-grid { grid-template-columns:1fr; } }
.po-form-group { display:flex; flex-direction:column; gap:0.3rem; }
.po-form-group.full { grid-column:1/-1; }
.po-form-group label { font-size:0.78rem; color:#5a8878; font-weight:600; letter-spacing:0.04em; }
.po-form-group input,
.po-form-group select,
.po-form-group textarea {
  background:rgba(0,0,0,0.3); border:1px solid rgba(0,168,120,0.2);
  color:#e8f5ee; border-radius:8px; padding:0.55rem 0.75rem; font-size:0.88rem;
  transition:border-color 0.15s;
}
.po-form-group input:focus,
.po-form-group select:focus,
.po-form-group textarea:focus {
  outline:none; border-color:rgba(0,168,120,0.5);
}
.po-form-group textarea { resize:vertical; min-height:80px; }
.po-form-hint { font-size:0.72rem; color:#5a8878; margin-top:0.2rem; }
.po-form-actions { display:flex; gap:0.75rem; margin-top:1.2rem; flex-wrap:wrap; }
.po-btn {
  padding:0.6rem 1.2rem; border-radius:8px; font-weight:700; font-size:0.88rem;
  border:none; cursor:pointer; text-decoration:none; display:inline-block;
  transition:all 0.15s;
}
.po-btn-primary  { background:#00A878; color:#fff; }
.po-btn-primary:hover { background:#00c48f; }
.po-btn-outline  { background:transparent; border:1px solid rgba(0,168,120,0.3); color:#00A878; }
.po-btn-outline:hover { background:rgba(0,168,120,0.08); }
.po-btn-danger   { background:transparent; border:1px solid rgba(248,113,113,0.35); color:#f87171; }
.po-btn-danger:hover { background:rgba(248,113,113,0.08); }
.po-btn-add {
  display:inline-flex; align-items:center; gap:0.4rem;
  background:#00A878; color:#fff; padding:0.55rem 1rem;
  border-radius:8px; font-weight:700; font-size:0.85rem; text-decoration:none;
}
.po-btn-add:hover { background:#00c48f; }

/* Offers list */
.po-offers { display:flex; flex-direction:column; gap:1rem; }
.po-offer-row {
  display:grid; grid-template-columns:auto 1fr auto;
  align-items:center; gap:1rem;
  background:rgba(255,255,255,0.025); border:1px solid rgba(0,168,120,0.12);
  border-radius:10px; padding:1rem;
}
@media(max-width:560px) { .po-offer-row { grid-template-columns:1fr; } }
.po-offer-img {
  width:64px; height:64px; object-fit:cover; border-radius:8px;
  background:rgba(0,168,120,0.08);
}
.po-offer-placeholder {
  width:64px; height:64px; border-radius:8px;
  background:rgba(0,168,120,0.08); display:flex; align-items:center;
  justify-content:center; font-size:1.6rem;
}
.po-offer-title { font-weight:700; color:#e8f5ee; font-size:0.9rem; margin-bottom:0.15rem; }
.po-offer-meta  { font-size:0.75rem; color:#5a8878; }
.po-status-pill {
  display:inline-block; padding:0.2rem 0.65rem; border-radius:99px;
  font-size:0.7rem; font-weight:700; color:#fff; letter-spacing:0.04em;
}
.po-offer-actions { display:flex; gap:0.5rem; flex-shrink:0; }
.po-offer-actions a, .po-offer-actions button {
  font-size:0.78rem; padding:0.3rem 0.65rem; border-radius:6px;
  cursor:pointer; text-decoration:none;
}
.po-offer-actions .edit-btn  { background:rgba(0,168,120,0.1); color:#00A878; border:1px solid rgba(0,168,120,0.2); }
.po-offer-actions .del-btn   { background:transparent; color:#f87171; border:1px solid rgba(248,113,113,0.25); }
.po-admin-note {
  margin-top:0.5rem; font-size:0.75rem; color:#fbbf24;
  background:rgba(251,191,36,0.08); border-left:2px solid #fbbf24;
  padding:0.3rem 0.5rem; border-radius:0 4px 4px 0;
}
.po-empty { text-align:center; padding:3rem 1rem; color:#5a8878; }
.po-empty big { font-size:2.5rem; display:block; margin-bottom:0.75rem; }
</style>

<?php flash('display'); ?>

<div class="po-wrap">
  <!-- Header -->
  <div class="po-header">
    <div>
      <a href="<?= SITE_URL ?>/partner/dashboard" class="po-back">← <?= t('Partner Dashboard','Tableau de Bord Partenaire') ?></a>
      <h1 class="po-title"><?= t('Deals & Offers','Offres et Promotions') ?></h1>
    </div>
    <?php if (!$editing): ?>
      <a href="?listing_id=<?= $lid ?>&new=1" class="po-btn-add">+ <?= t('Create Offer','Créer une Offre') ?></a>
    <?php endif; ?>
  </div>

  <!-- Listing switcher -->
  <?php if (count($myListings) > 1): ?>
    <div class="po-listing-bar">
      <label for="ls-sel"><?= t('Business:','Entreprise :') ?></label>
      <select id="ls-sel" onchange="location.href='/partner/offers?listing_id='+this.value">
        <?php foreach ($myListings as $ml): ?>
          <option value="<?= $ml['id'] ?>" <?= $ml['id'] === $lid ? 'selected' : '' ?>>
            <?= htmlspecialchars($ml['title'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>

  <!-- CREATE / EDIT FORM -->
  <?php if ($editing || isset($_GET['new'])): ?>
    <div class="po-form-card">
      <h2><?= $editing ? t('Edit Offer','Modifier l\'Offre') : t('New Offer','Nouvelle Offre') ?></h2>
      <form method="POST" action="?listing_id=<?= $lid ?>" enctype="multipart/form-data">
        <?= csrf() ?>
        <input type="hidden" name="action"     value="<?= $editing ? 'update' : 'create' ?>">
        <input type="hidden" name="listing_id" value="<?= $lid ?>">
        <?php if ($editing): ?>
          <input type="hidden" name="offer_id" value="<?= $editing['id'] ?>">
          <input type="hidden" name="existing_image" value="<?= htmlspecialchars($editing['image'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>

        <div class="po-form-grid">
          <!-- Titles -->
          <div class="po-form-group">
            <label>🇬🇧 <?= t('Title (English)*','Titre (Anglais)*') ?></label>
            <input type="text" name="title" value="<?= htmlspecialchars($editing['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required placeholder="e.g. 20% Off All Services">
          </div>
          <div class="po-form-group">
            <label>🇫🇷 <?= t('Title (French)','Titre (Français)') ?></label>
            <input type="text" name="title_fr" value="<?= htmlspecialchars($editing['title_fr'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="ex: 20% de réduction sur tous les services">
          </div>

          <!-- Offer type + value -->
          <div class="po-form-group">
            <label><?= t('Offer Type*','Type d\'Offre*') ?></label>
            <select name="offer_type" id="offer-type-sel" onchange="updateDiscountField()">
              <?php $selType = $editing['offer_type'] ?? 'percent_off'; ?>
              <option value="percent_off" <?= $selType==='percent_off'?'selected':'' ?>><?= t('% Off','% de Réduction') ?></option>
              <option value="fixed_off"   <?= $selType==='fixed_off'  ?'selected':'' ?>><?= t('Fixed Amount Off (XAF)','Montant Fixe (XAF)') ?></option>
              <option value="bogo"        <?= $selType==='bogo'       ?'selected':'' ?>><?= t('Buy 1 Get 1 Free','Achetez-en 1, obtenez-en 1') ?></option>
              <option value="free_item"   <?= $selType==='free_item'  ?'selected':'' ?>><?= t('Free Item Included','Article Gratuit Inclus') ?></option>
              <option value="bundle"      <?= $selType==='bundle'     ?'selected':'' ?>><?= t('Bundle Deal','Pack Spécial') ?></option>
            </select>
          </div>
          <div class="po-form-group" id="disc-val-group">
            <label id="disc-val-lbl"><?= t('Discount Value*','Valeur de Réduction*') ?></label>
            <input type="number" name="discount_value" id="disc-val-input"
                   value="<?= htmlspecialchars($editing['discount_value'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   min="0" step="0.01"
                   placeholder="<?= $selType === 'percent_off' ? '20' : '5000' ?>">
          </div>

          <!-- Prices + promo code -->
          <div class="po-form-group">
            <label><?= t('Original Price (XAF)','Prix Original (XAF)') ?></label>
            <input type="number" name="original_price" value="<?= htmlspecialchars($editing['original_price'] ?? '', ENT_QUOTES, 'UTF-8') ?>" min="0" step="1" placeholder="50000">
            <span class="po-form-hint"><?= t('Optional: helps customers see the saving.','Optionnel : aide les clients à voir l\'économie.') ?></span>
          </div>
          <div class="po-form-group">
            <label><?= t('Promo Code','Code Promo') ?></label>
            <input type="text" name="promo_code" value="<?= htmlspecialchars($editing['promo_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="SAVE20" maxlength="50" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
            <span class="po-form-hint"><?= t('Leave blank if no code needed.','Laisser vide si aucun code n\'est requis.') ?></span>
          </div>

          <!-- Dates -->
          <div class="po-form-group">
            <label><?= t('Start Date*','Date de Début*') ?></label>
            <input type="datetime-local" name="starts_at" value="<?= $editing ? date('Y-m-d\TH:i', strtotime($editing['starts_at'])) : date('Y-m-d\TH:i') ?>" required>
          </div>
          <div class="po-form-group">
            <label><?= t('Expiry Date','Date d\'Expiration') ?></label>
            <input type="datetime-local" name="expires_at" value="<?= $editing && $editing['expires_at'] ? date('Y-m-d\TH:i', strtotime($editing['expires_at'])) : '' ?>">
            <span class="po-form-hint"><?= t('Leave blank for no expiry.','Laisser vide pour sans expiration.') ?></span>
          </div>

          <!-- Max redemptions -->
          <div class="po-form-group">
            <label><?= t('Max Redemptions','Utilisations Maximales') ?></label>
            <input type="number" name="max_redemptions" value="<?= htmlspecialchars($editing['max_redemptions'] ?? '', ENT_QUOTES, 'UTF-8') ?>" min="1" placeholder="100">
            <span class="po-form-hint"><?= t('Leave blank for unlimited.','Laisser vide pour illimité.') ?></span>
          </div>
          <!-- Image -->
          <div class="po-form-group">
            <label><?= t('Offer Image','Image de l\'Offre') ?></label>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
            <?php if ($editing && $editing['image']): ?>
              <span class="po-form-hint">✅ <?= t('Image on file','Image enregistrée') ?> — <?= t('upload to replace','télécharger pour remplacer') ?></span>
            <?php endif; ?>
          </div>

          <!-- Descriptions -->
          <div class="po-form-group full">
            <label>🇬🇧 <?= t('Description (English)','Description (Anglais)') ?></label>
            <textarea name="description" placeholder="Describe what customers get..."><?= htmlspecialchars($editing['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
          <div class="po-form-group full">
            <label>🇫🇷 <?= t('Description (French)','Description (Français)') ?></label>
            <textarea name="description_fr" placeholder="Décrivez ce que les clients obtiennent..."><?= htmlspecialchars($editing['description_fr'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
          <div class="po-form-group full">
            <label><?= t('Terms & Conditions','Termes et Conditions') ?></label>
            <textarea name="terms" placeholder="<?= t('E.g. Valid on weekdays only. Cannot be combined...','Ex: Valable en semaine uniquement. Non cumulable...') ?>"><?= htmlspecialchars($editing['terms'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
        </div>

        <div class="po-form-actions">
          <button type="submit" class="po-btn po-btn-primary">
            <?= $editing ? t('Save Changes','Enregistrer les Modifications') : t('Submit for Review','Soumettre pour Révision') ?>
          </button>
          <a href="?listing_id=<?= $lid ?>" class="po-btn po-btn-outline"><?= t('Cancel','Annuler') ?></a>
        </div>

        <?php if (!$editing): ?>
          <p style="font-size:0.75rem;color:#5a8878;margin-top:0.75rem;">
            ℹ️ <?= t('Offers are reviewed by our team before going live (usually within 24 hours).','Les offres sont examinées par notre équipe avant publication (généralement sous 24 heures).') ?>
          </p>
        <?php endif; ?>
      </form>
    </div>

  <?php else: ?>
    <!-- OFFERS LIST -->
    <?php if ($offers): ?>
      <div class="po-offers">
        <?php foreach ($offers as $o):
          $otitle = $isFr && $o['title_fr'] ? $o['title_fr'] : $o['title'];
          [$scol, $slbl] = $statusLabels[$o['status']] ?? ['#8db8a8', $o['status']];
          $isExpired = $o['expires_at'] && strtotime($o['expires_at']) < time();
          $canEdit   = in_array($o['status'], ['draft','pending','rejected']);
          $canDelete = $o['status'] !== 'active';
        ?>
          <div class="po-offer-row">
            <div>
              <?php if ($o['image']): ?>
                <img src="<?= UPLOAD_URL . htmlspecialchars($o['image'], ENT_QUOTES, 'UTF-8') ?>"
                     alt="offer" class="po-offer-img">
              <?php else: ?>
                <div class="po-offer-placeholder">🏷️</div>
              <?php endif; ?>
            </div>
            <div>
              <div class="po-offer-title"><?= htmlspecialchars($otitle, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="po-offer-meta">
                <span class="po-status-pill" style="background:<?= $scol ?>;"><?= $slbl ?></span>
                &nbsp;
                <?php
                  switch ($o['offer_type']) {
                    case 'percent_off': echo e('-'.(int)$o['discount_value'].'%'); break;
                    case 'fixed_off':   echo e(number_format($o['discount_value']).' XAF '.t('off','rabais')); break;
                    case 'bogo':        echo 'Buy 1 Get 1'; break;
                    case 'free_item':   echo t('Free Item','Article Gratuit'); break;
                    case 'bundle':      echo t('Bundle','Pack'); break;
                  }
                ?>
                <?php if ($o['expires_at']): ?>
                  &nbsp;·&nbsp; ⏰ <?= date('d M Y', strtotime($o['expires_at'])) ?>
                <?php endif; ?>
                <?php if ($o['redemption_count']): ?>
                  &nbsp;·&nbsp; 🎫 <?= $o['redemption_count'] ?> <?= t('used','utilisé(s)') ?>
                <?php endif; ?>
              </div>
              <?php if ($o['status'] === 'rejected' && $o['admin_notes']): ?>
                <div class="po-admin-note">⚠️ <?= htmlspecialchars($o['admin_notes'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </div>
            <div class="po-offer-actions">
              <?php if ($o['status'] === 'active'): ?>
                <a href="<?= SITE_URL ?>/offer/<?= htmlspecialchars($o['slug'], ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" class="edit-btn">👁 <?= t('View','Voir') ?></a>
              <?php endif; ?>
              <a href="?listing_id=<?= $lid ?>&edit=<?= $o['id'] ?>" class="edit-btn">✏️ <?= t('Edit','Modifier') ?></a>
              <form method="POST" action="?listing_id=<?= $lid ?>" style="display:inline"
                    onsubmit="return confirm('<?= t('Delete or expire this offer?','Supprimer ou expirer cette offre ?') ?>')">
                <?= csrf() ?>
                <input type="hidden" name="action"   value="delete">
                <input type="hidden" name="listing_id" value="<?= $lid ?>">
                <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                <button type="submit" class="del-btn">
                  <?= $o['status'] === 'active' ? t('Expire','Expirer') : t('Delete','Supprimer') ?>
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <div class="po-empty">
        <big>🏷️</big>
        <p><?= t('No offers yet. Create your first deal to attract more customers!','Aucune offre pour l\'instant. Créez votre première promotion pour attirer plus de clients !') ?></p>
        <a href="?listing_id=<?= $lid ?>&new=1" class="po-btn-add">+ <?= t('Create First Offer','Créer ma Première Offre') ?></a>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Marketplace link -->
  <p style="margin-top:2rem;font-size:0.78rem;color:#5a8878;text-align:center;">
    <a href="<?= SITE_URL ?>/marketplace" style="color:#00A878;">🛍 <?= t('View the Marketplace','Voir le Marketplace') ?></a>
    &nbsp;·&nbsp;
    <a href="<?= SITE_URL ?>/partner/events?listing_id=<?= $lid ?>" style="color:#8db8a8;">📅 <?= t('Manage Events','Gérer les Événements') ?></a>
  </p>
</div>

<script>
function updateDiscountField() {
    const type  = document.getElementById('offer-type-sel').value;
    const grp   = document.getElementById('disc-val-group');
    const lbl   = document.getElementById('disc-val-lbl');
    const inp   = document.getElementById('disc-val-input');
    const noVal = ['bogo','free_item','bundle'];
    if (noVal.includes(type)) {
        grp.style.opacity = '0.4';
        inp.disabled = true;
        inp.required = false;
    } else {
        grp.style.opacity = '1';
        inp.disabled = false;
        inp.required = true;
        if (type === 'percent_off') {
            lbl.textContent = '<?= t('Discount %*','% de Réduction*') ?>';
            inp.placeholder = '20';
            inp.max = '100';
        } else {
            lbl.textContent = '<?= t('Amount Off (XAF)*','Montant (XAF)*') ?>';
            inp.placeholder = '5000';
            inp.max = '';
        }
    }
}
// Run on page load
document.addEventListener('DOMContentLoaded', updateDiscountField);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
