<?php
/**
 * manage-announcements.php — 237Biz
 * Business owner creates and manages promotional announcements
 * that appear as banners on their listing page.
 */
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u = currentUser();

$myListings = [];
try {
    if (isAdmin()) {
        $myListings = db()->query("SELECT id,title,slug,featured FROM listings WHERE status='approved' ORDER BY title")->fetchAll();
    } else {
        $q = db()->prepare("SELECT id,title,slug,featured FROM listings WHERE user_id=? AND status='approved' ORDER BY title");
        $q->execute([$u['id']]);
        $myListings = $q->fetchAll();
    }
} catch (Exception $e) {}

$lid = (int)($_GET['listing_id'] ?? ($myListings[0]['id'] ?? 0));
$activeListing = null;
foreach ($myListings as $ml) { if ($ml['id']===$lid) { $activeListing=$ml; break; } }
if (!$activeListing && !empty($myListings)) { $activeListing=$myListings[0]; $lid=$activeListing['id']; }

$errors = [];
$editAnn = null; // announcement being edited

// Load announcement for editing
if (isset($_GET['edit'])) {
    try {
        $ea = db()->prepare("SELECT * FROM listing_announcements WHERE id=? AND listing_id=?");
        $ea->execute([(int)$_GET['edit'], $lid]);
        $editAnn = $ea->fetch();
    } catch (Exception $e) {}
}

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Delete
    if ($action === 'delete' && !empty($_POST['ann_id'])) {
        try {
            db()->prepare("DELETE FROM listing_announcements WHERE id=? AND listing_id=?")->execute([(int)$_POST['ann_id'], $lid]);
            flash('success', t('Announcement deleted.','Annonce supprimée.'));
        } catch (Exception $e) { flash('error', t('Could not delete.','Impossible de supprimer.')); }
        redirect(SITE_URL . '/manage-announcements?listing_id=' . $lid);
    }

    // Toggle active
    if ($action === 'toggle' && !empty($_POST['ann_id'])) {
        try {
            db()->prepare("UPDATE listing_announcements SET active=NOT active WHERE id=? AND listing_id=?")->execute([(int)$_POST['ann_id'], $lid]);
        } catch (Exception $e) {}
        redirect(SITE_URL . '/manage-announcements?listing_id=' . $lid);
    }

    // Create or Update
    if (in_array($action, ['create','update'])) {
        $title      = trim($_POST['title']      ?? '');
        $body       = trim($_POST['body']       ?? '');
        $type       = in_array($_POST['type']??'', ['offer','event','info','urgent']) ? $_POST['type'] : 'info';
        $badge      = mb_substr(trim($_POST['badge_text'] ?? ''), 0, 40);
        $starts_at  = trim($_POST['starts_at']  ?? date('Y-m-d\TH:i'));
        $ends_at    = trim($_POST['ends_at']    ?? '');
        $active     = isset($_POST['active']) ? 1 : 0;

        if (!$title)   $errors[] = t('Title is required.','Le titre est requis.');
        if (!$ends_at) $errors[] = t('End date is required.','La date de fin est requise.');
        if ($ends_at && strtotime($ends_at) <= strtotime($starts_at))
            $errors[] = t('End date must be after start date.','La date de fin doit être après la date de début.');

        if (!$errors) {
            try {
                if ($action === 'create') {
                    db()->prepare("INSERT INTO listing_announcements (listing_id,title,body,type,badge_text,starts_at,ends_at,active) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$lid, $title, $body, $type, $badge?:null, $starts_at, $ends_at, $active]);
                    flash('success', t('Announcement created!','Annonce créée !'));
                } else {
                    $annId = (int)($_POST['ann_id'] ?? 0);
                    db()->prepare("UPDATE listing_announcements SET title=?,body=?,type=?,badge_text=?,starts_at=?,ends_at=?,active=?,updated_at=NOW() WHERE id=? AND listing_id=?")
                       ->execute([$title, $body, $type, $badge?:null, $starts_at, $ends_at, $active, $annId, $lid]);
                    flash('success', t('Announcement updated!','Annonce mise à jour !'));
                }
                redirect(SITE_URL . '/manage-announcements?listing_id=' . $lid);
            } catch (Exception $e) {
                $errors[] = t('Could not save. Make sure the announcements table exists.','Impossible d\'enregistrer. Vérifiez que la table existe.');
            }
        }
    }
}

// Load announcements
$announcements = [];
if ($activeListing) {
    try {
        $ann = db()->prepare("SELECT * FROM listing_announcements WHERE listing_id=? ORDER BY created_at DESC");
        $ann->execute([$lid]);
        $announcements = $ann->fetchAll();
    } catch (Exception $e) {}
}

$pageTitle = t('Manage Announcements','Gérer les annonces') . ' — 237Biz';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.ma-wrap   { max-width:820px;margin:0 auto;padding:24px 16px 60px; }
.ma-header { display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px; }
.ann-card  { border:1px solid rgba(255,255,255,0.08);border-radius:12px;margin-bottom:10px;overflow:hidden; }
.ann-type-offer  { border-left:4px solid #fcd116; }
.ann-type-event  { border-left:4px solid #8ab4f8; }
.ann-type-info   { border-left:4px solid #00A878; }
.ann-type-urgent { border-left:4px solid #e63946; }
.ann-head  { display:flex;align-items:center;gap:10px;padding:12px 16px;flex-wrap:wrap; }
.ann-badge { font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px; }
.badge-offer  { background:rgba(252,209,22,0.2);color:var(--yellow);border:1px solid rgba(252,209,22,0.4); }
.badge-event  { background:rgba(138,180,248,0.2);color:#8ab4f8;border:1px solid rgba(138,180,248,0.4); }
.badge-info   { background:rgba(0,168,120,0.2);color:#00A878;border:1px solid rgba(0,168,120,0.4); }
.badge-urgent { background:rgba(230,57,70,0.2);color:#e63946;border:1px solid rgba(230,57,70,0.4); }
.ann-body  { padding:0 16px 12px;font-size:13px;color:var(--muted); }
.ann-actions { display:flex;gap:7px;flex-wrap:wrap;margin-top:8px; }
.btn-sm-ann { border:1px solid rgba(255,255,255,0.12);background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.7);border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block; }
.btn-sm-ann:hover { border-color:rgba(0,168,120,0.4);color:#00A878; }
.btn-danger { border-color:rgba(230,57,70,0.3);color:#e63946; }
/* Form */
.ann-form  { background:rgba(255,255,255,0.02);border:1px solid rgba(0,168,120,0.25);border-radius:14px;padding:22px 24px;margin-bottom:20px; }
.form-row2 { display:grid;grid-template-columns:1fr 1fr;gap:12px; }
.type-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:4px; }
.type-opt  { display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 6px;border:1.5px solid rgba(255,255,255,0.1);border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;text-align:center;transition:all .15s; }
.type-opt input { display:none; }
.type-opt:has(input:checked) { border-color:currentColor; }
.type-offer.checked  { border-color:#fcd116;color:#fcd116;background:rgba(252,209,22,0.08); }
.type-event.checked  { border-color:#8ab4f8;color:#8ab4f8;background:rgba(138,180,248,0.08); }
.type-info.checked   { border-color:#00A878;color:#00A878;background:rgba(0,168,120,0.08); }
.type-urgent.checked { border-color:#e63946;color:#e63946;background:rgba(230,57,70,0.08); }
/* Preview banner */
.ann-preview { border-radius:10px;padding:12px 16px;margin-top:10px;display:flex;align-items:center;gap:12px; }
.preview-offer  { background:rgba(252,209,22,0.1); border:1px solid rgba(252,209,22,0.3); }
.preview-event  { background:rgba(138,180,248,0.1); border:1px solid rgba(138,180,248,0.3); }
.preview-info   { background:rgba(0,168,120,0.1);   border:1px solid rgba(0,168,120,0.3); }
.preview-urgent { background:rgba(230,57,70,0.1);   border:1px solid rgba(230,57,70,0.3); }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard','Tableau de bord') ?></a> ›
      <a href="<?= SITE_URL ?>/analytics?listing_id=<?= $lid ?>">Analytics</a> ›
      <span><?= t('Announcements','Annonces') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,2rem);">📢 <?= t('Promotional Announcements','Annonces promotionnelles') ?></h1>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="ma-wrap">

<?php if (empty($myListings)): ?>
<div style="text-align:center;padding:60px 20px;color:var(--muted);">
  <p style="font-size:15px;color:var(--white);font-weight:600;"><?= t('No listings found','Aucune annonce trouvée') ?></p>
  <a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary" style="margin-top:14px;">+ <?= t('Add a listing','Ajouter une annonce') ?></a>
</div>
<?php else: ?>

<?php foreach ($errors as $err): ?>
<div class="flash flash-error" style="margin-bottom:10px;"><?= e($err) ?></div>
<?php endforeach; ?>

<!-- Listing selector + actions -->
<div class="ma-header">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <?php if (count($myListings) > 1): ?>
    <select style="padding:7px 12px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:7px;color:var(--white);font-family:inherit;font-size:13px;" onchange="location.href='/manage-announcements?listing_id='+this.value">
      <?php foreach ($myListings as $ml): ?>
      <option value="<?= $ml['id'] ?>" <?= $ml['id']===$lid?'selected':'' ?>><?= e($ml['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <strong style="font-size:15px;"><?= e($activeListing['title']) ?></strong>
    <?php endif; ?>
    <a href="<?= SITE_URL ?>/listing/<?= e($activeListing['slug']) ?>" style="font-size:12px;color:var(--green);" target="_blank"><?= t('View listing','Voir l\'annonce') ?> →</a>
  </div>
  <a href="#annForm" class="btn btn-primary" style="font-size:13px;padding:8px 18px;">+ <?= t('New Announcement','Nouvelle annonce') ?></a>
</div>

<!-- Create / Edit form -->
<div class="ann-form" id="annForm">
  <h3 style="font-family:'Fraunces',serif;font-size:1.05rem;margin-bottom:14px;">
    <?= $editAnn ? '✏️ '.t('Edit Announcement','Modifier l\'annonce') : '+ '.t('New Announcement','Nouvelle annonce') ?>
  </h3>

  <form method="POST">
    <input type="hidden" name="csrf"   value="<?= csrf() ?>">
    <input type="hidden" name="action" value="<?= $editAnn ? 'update' : 'create' ?>">
    <?php if ($editAnn): ?><input type="hidden" name="ann_id" value="<?= $editAnn['id'] ?>"><?php endif; ?>

    <!-- Type selector -->
    <div class="form-group" style="margin-bottom:14px;">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px;"><?= t('Announcement Type','Type d\'annonce') ?> *</label>
      <div class="type-grid" id="typeGrid">
        <?php
        $types = [
          'offer'  => ['🏷️', t('Offer / Discount','Offre / Remise'),   'type-offer'],
          'event'  => ['📅', t('Event / Launch','Événement / Lancement'),  'type-event'],
          'info'   => ['ℹ️', t('Info / Update','Info / Mise à jour'),    'type-info'],
          'urgent' => ['🔔', t('Urgent / Alert','Urgent / Alerte'),      'type-urgent'],
        ];
        $selType = $editAnn['type'] ?? 'offer';
        foreach ($types as $val => [$icon, $lbl, $cls]): ?>
        <label class="type-opt <?= $cls ?> <?= $selType===$val?'checked':'' ?>" id="typeLbl_<?= $val ?>">
          <input type="radio" name="type" value="<?= $val ?>" <?= $selType===$val?'checked':'' ?>>
          <span style="font-size:1.3rem;"><?= $icon ?></span>
          <span><?= $lbl ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-row2" style="margin-bottom:12px;">
      <div class="form-group">
        <label><?= t('Title','Titre') ?> *</label>
        <input type="text" name="title" maxlength="100" required
               value="<?= e($editAnn['title'] ?? '') ?>"
               placeholder="<?= e(t('e.g. 10% off this week!','ex. 10% de réduction cette semaine !')) ?>"
               id="annTitle">
      </div>
      <div class="form-group">
        <label><?= t('Badge Text','Texte du badge') ?> <span style="font-size:11px;font-weight:400;color:var(--muted);"><?= t('(optional, e.g. "10% OFF")','(optionnel, ex. "10% OFF")') ?></span></label>
        <input type="text" name="badge_text" maxlength="40"
               value="<?= e($editAnn['badge_text'] ?? '') ?>"
               placeholder="10% OFF"
               id="annBadge">
      </div>
    </div>

    <div class="form-group" style="margin-bottom:12px;">
      <label><?= t('Message','Message') ?> <span style="font-size:11px;font-weight:400;color:var(--muted);"><?= t('(optional)','(optionnel)') ?></span></label>
      <textarea name="body" rows="2"
                placeholder="<?= e(t('Details about your promotion, event or update...','Détails sur votre promotion, événement ou mise à jour...')) ?>"
                id="annBody"><?= e($editAnn['body'] ?? '') ?></textarea>
    </div>

    <div class="form-row2" style="margin-bottom:12px;">
      <div class="form-group">
        <label>📅 <?= t('Start Date & Time','Début') ?></label>
        <input type="datetime-local" name="starts_at"
               value="<?= e($editAnn ? date('Y-m-d\TH:i',strtotime($editAnn['starts_at'])) : date('Y-m-d\TH:i')) ?>">
      </div>
      <div class="form-group">
        <label>📅 <?= t('End Date & Time','Fin') ?> *</label>
        <input type="datetime-local" name="ends_at" required
               value="<?= e($editAnn ? date('Y-m-d\TH:i',strtotime($editAnn['ends_at'])) : date('Y-m-d\TH:i',strtotime('+7 days'))) ?>">
      </div>
    </div>

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
        <input type="checkbox" name="active" value="1" <?= (!$editAnn || $editAnn['active']) ? 'checked' : '' ?>
               style="accent-color:#00A878;width:15px;height:15px;">
        <?= t('Active (visible on listing page)','Actif (visible sur la page de l\'annonce)') ?>
      </label>
    </div>

    <!-- Live preview -->
    <div style="font-size:12px;color:var(--muted);margin-bottom:6px;"><?= t('Preview:','Aperçu :') ?></div>
    <div class="ann-preview preview-offer" id="annPreview">
      <span style="font-size:1.5rem;" id="previewIcon">🏷️</span>
      <div style="flex:1;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <strong style="font-size:14px;" id="previewTitle"><?= e($editAnn['title'] ?? t('Your announcement title','Titre de votre annonce')) ?></strong>
          <span id="previewBadge" style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:rgba(252,209,22,0.2);color:#fcd116;<?= empty($editAnn['badge_text'])&&!$editAnn?'display:none;':'' ?>"><?= e($editAnn['badge_text'] ?? '') ?></span>
        </div>
        <div style="font-size:12.5px;color:rgba(255,255,255,0.65);margin-top:2px;" id="previewBody"><?= e($editAnn['body'] ?? '') ?></div>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary">
        <?= $editAnn ? '💾 '.t('Save Changes','Enregistrer') : '+ '.t('Create Announcement','Créer l\'annonce') ?>
      </button>
      <?php if ($editAnn): ?>
      <a href="<?= SITE_URL ?>/manage-announcements?listing_id=<?= $lid ?>" class="btn btn-outline"><?= t('Cancel','Annuler') ?></a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- Existing announcements -->
<h3 style="font-size:14px;font-weight:700;margin-bottom:12px;color:rgba(255,255,255,0.7);">
  <?= t('Your Announcements','Vos annonces') ?>
  <?php if (!empty($announcements)): ?><span style="font-size:12px;color:var(--muted);font-weight:400;margin-left:6px;">(<?= count($announcements) ?>)</span><?php endif; ?>
</h3>

<?php if (empty($announcements)): ?>
<div style="text-align:center;padding:30px;border:1px dashed rgba(255,255,255,0.1);border-radius:10px;color:var(--muted);font-size:13.5px;">
  <?= t('No announcements yet. Create your first one above.','Aucune annonce encore. Créez la vôtre ci-dessus.') ?>
</div>
<?php else: ?>
<?php foreach ($announcements as $ann):
  $isActive = $ann['active'] && strtotime($ann['ends_at']) > time() && strtotime($ann['starts_at']) <= time();
  $isPast   = strtotime($ann['ends_at']) <= time();
  $isFuture = strtotime($ann['starts_at']) > time();
  $colors   = ['offer'=>'#fcd116','event'=>'#8ab4f8','info'=>'#00A878','urgent'=>'#e63946'];
  $icons    = ['offer'=>'🏷️','event'=>'📅','info'=>'ℹ️','urgent'=>'🔔'];
  $col      = $colors[$ann['type']] ?? '#00A878';
?>
<div class="ann-card ann-type-<?= $ann['type'] ?>">
  <div class="ann-head">
    <span style="font-size:1.2rem;"><?= $icons[$ann['type']] ?? 'ℹ️' ?></span>
    <span class="ann-badge badge-<?= $ann['type'] ?>"><?= t(ucfirst($ann['type']),ucfirst($ann['type'])) ?></span>
    <?php if ($ann['badge_text']): ?>
    <span style="font-size:12px;font-weight:700;background:rgba(255,255,255,0.08);border-radius:6px;padding:2px 8px;color:<?= $col ?>;"><?= e($ann['badge_text']) ?></span>
    <?php endif; ?>
    <strong style="font-size:13.5px;flex:1;min-width:0;"><?= e($ann['title']) ?></strong>
    <span style="font-size:11px;font-weight:600;color:<?= $isActive?$col:($isPast?'var(--muted)':'#8ab4f8') ?>;">
      <?= $isActive ? '● '.t('Live','En direct') : ($isPast ? '✕ '.t('Expired','Expiré') : '◷ '.t('Scheduled','Planifié')) ?>
    </span>
    <span style="font-size:11px;color:var(--muted);"><?= number_format($ann['views']) ?> <?= t('views','vues') ?></span>
  </div>
  <div class="ann-body">
    <?php if ($ann['body']): ?><p style="margin:0 0 6px;"><?= e(mb_substr($ann['body'],0,120)) ?><?= mb_strlen($ann['body'])>120?'…':'' ?></p><?php endif; ?>
    <div style="font-size:11.5px;">
      📅 <?= date('d M Y H:i',strtotime($ann['starts_at'])) ?> → <?= date('d M Y H:i',strtotime($ann['ends_at'])) ?>
    </div>
    <div class="ann-actions">
      <a href="?listing_id=<?= $lid ?>&edit=<?= $ann['id'] ?>#annForm" class="btn-sm-ann">✏️ <?= t('Edit','Modifier') ?></a>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="csrf"   value="<?= csrf() ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
        <button type="submit" class="btn-sm-ann"><?= $ann['active'] ? '⏸ '.t('Deactivate','Désactiver') : '▶ '.t('Activate','Activer') ?></button>
      </form>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="csrf"   value="<?= csrf() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
        <button type="submit" class="btn-sm-ann btn-danger" onclick="return confirm('<?= e(t('Delete this announcement?','Supprimer cette annonce ?')) ?>')"><?= t('Delete','Supprimer') ?></button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>
</div>
</section>

<script>
(function() {
  var typeInputs  = document.querySelectorAll('input[name="type"]');
  var preview     = document.getElementById('annPreview');
  var previewIcon = document.getElementById('previewIcon');
  var previewTitle = document.getElementById('previewTitle');
  var previewBadge = document.getElementById('previewBadge');
  var previewBody  = document.getElementById('previewBody');
  var titleInput   = document.getElementById('annTitle');
  var badgeInput   = document.getElementById('annBadge');
  var bodyInput    = document.getElementById('annBody');

  var typeConfig = {
    'offer' : { icon:'🏷️', cls:'preview-offer',  lbl_cls:'ann-type-offer'  },
    'event' : { icon:'📅', cls:'preview-event',  lbl_cls:'ann-type-event'  },
    'info'  : { icon:'ℹ️', cls:'preview-info',   lbl_cls:'ann-type-info'   },
    'urgent': { icon:'🔔', cls:'preview-urgent', lbl_cls:'ann-type-urgent' },
  };

  function updatePreview() {
    var selected = document.querySelector('input[name="type"]:checked');
    if (!selected || !preview) return;
    var cfg = typeConfig[selected.value] || typeConfig['info'];
    preview.className = 'ann-preview ' + cfg.cls;
    if (previewIcon)  previewIcon.textContent  = cfg.icon;
    if (previewTitle) previewTitle.textContent = titleInput ? (titleInput.value || '...') : '';
    if (previewBadge) {
      previewBadge.textContent = badgeInput ? badgeInput.value : '';
      previewBadge.style.display = (badgeInput && badgeInput.value) ? '' : 'none';
    }
    if (previewBody)  previewBody.textContent  = bodyInput ? bodyInput.value : '';
  }

  function updateTypeLabels() {
    document.querySelectorAll('.type-opt').forEach(function(lbl) {
      lbl.classList.remove('checked');
    });
    var sel = document.querySelector('input[name="type"]:checked');
    if (sel) {
      var parentLbl = sel.closest('.type-opt');
      if (parentLbl) parentLbl.classList.add('checked');
    }
  }

  typeInputs.forEach(function(inp) {
    inp.addEventListener('change', function() { updateTypeLabels(); updatePreview(); });
  });
  if (titleInput) titleInput.addEventListener('input', updatePreview);
  if (badgeInput) badgeInput.addEventListener('input', updatePreview);
  if (bodyInput)  bodyInput.addEventListener('input', updatePreview);

  updateTypeLabels();
  updatePreview();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
