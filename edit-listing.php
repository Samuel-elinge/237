<?php
/**
 * edit-listing.php — 237Biz
 * Clean rewrite: no PHP in JS, no CDN, data-attribute driven, bilingual EN/FR
 */
require_once __DIR__ . '/includes/config.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$u  = currentUser();

$st = db()->prepare("SELECT * FROM listings WHERE id = ?");
$st->execute([$id]);
$l = $st->fetch();

if (!$l || ($l['user_id'] != $u['id'] && !isAdmin())) {
    flash('error', t('Listing not found.', 'Annonce introuvable.'));
    redirect(SITE_URL . '/dashboard');
}

$cats   = db()->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();
$locs   = db()->query("SELECT * FROM locations ORDER BY sort_order")->fetchAll();
$errors = [];

// Decode JSON fields
$services        = json_decode($l['services_json']    ?? '[]', true) ?: [];
$hours           = json_decode($l['hours_json']       ?? '{}', true) ?: [];
$keywords        = array_filter(array_map('trim', explode(',', $l['keywords'] ?? '')));
$bookingEnabled  = !empty($l['booking_enabled']);
$bookingServices = json_decode($l['booking_services'] ?? '[]', true) ?: [];
$bookingAdvance  = (int)($l['booking_advance'] ?? 30);
$bookingSlotDur  = (int)($l['booking_slot_duration'] ?? 30);
if (!in_array($bookingSlotDur, [15,30,45,60])) $bookingSlotDur = 30;
$days            = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title       = trim($_POST['title']       ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $location_id = (int)($_POST['location_id'] ?? 0);
    $address     = trim($_POST['address']     ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $email       = trim($_POST['email']       ?? '');
    $website     = trim($_POST['website']     ?? '');
    $whatsapp    = trim($_POST['whatsapp']    ?? '');
    $facebook    = trim($_POST['facebook']    ?? '');
    $tiktok      = trim($_POST['tiktok']      ?? '');
    $instagram   = trim($_POST['instagram']   ?? '');
    $tagline     = mb_substr(trim($_POST['tagline'] ?? ''), 0, 160);
    $kw          = trim($_POST['keywords']    ?? '');
    $video_url   = trim($_POST['video_url']   ?? '');
    $cta_label   = trim($_POST['cta_label']   ?? '');
    $cta_url     = trim($_POST['cta_url']     ?? '');
    $svc_json        = $_POST['services_json']        ?? '[]';
    $hrs_json        = $_POST['hours_json']           ?? '{}';
    $booking_enabled = isset($_POST['booking_enabled']) ? 1 : 0;
    $booking_svc     = $_POST['booking_services_json'] ?? '[]';
    $booking_advance = max(1, min(365, (int)($_POST['booking_advance'] ?? 30)));
    $booking_slot    = in_array((int)($_POST['booking_slot_duration']??30),[15,30,45,60]) ? (int)$_POST['booking_slot_duration'] : 30;

    // Description — sanitise HTML from the rich text editor
    $raw_html    = trim($_POST['description_html'] ?? '');
    $description = $raw_html
        ? strip_tags($raw_html, '<p><br><h2><h3><strong><em><u><ul><ol><li><a>')
        : trim($_POST['description'] ?? '');

    if (!$title)       $errors[] = t('Business name is required.', 'Le nom est requis.');
    if (!$category_id) $errors[] = t('Please select a category.',  'Veuillez choisir une catégorie.');
    if (!$location_id) $errors[] = t('Please select a city.',       'Veuillez choisir une ville.');

    // Logo upload
    $logoPath = $l['logo'];
    if (!empty($_FILES['logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif']) && $_FILES['logo']['size'] <= MAX_UPLOAD) {
            $newPath = uniqid('logo_') . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], UPLOAD_DIR . $newPath)) {
                if ($logoPath && file_exists(UPLOAD_DIR . $logoPath)) unlink(UPLOAD_DIR . $logoPath);
                $logoPath = $newPath;
            }
        }
    }

    if (!$errors) {
        db()->prepare("
            UPDATE listings SET
              title=?, category_id=?, location_id=?, description=?, address=?,
              phone=?, email=?, website=?, whatsapp=?, facebook=?,
              tiktok=?, instagram=?, logo=?,
              tagline=?, keywords=?, video_url=?, cta_label=?, cta_url=?,
              services_json=?, hours_json=?,
              booking_enabled=?, booking_services=?, booking_advance=?, booking_slot_duration=?,
              updated_at=NOW()
            WHERE id=?
        ")->execute([
            $title, $category_id, $location_id, $description, $address,
            $phone, $email, $website, $whatsapp, $facebook,
            $tiktok, $instagram, $logoPath,
            $tagline, $kw, $video_url, $cta_label, $cta_url,
            $svc_json, $hrs_json,
            $booking_enabled, $booking_svc, $booking_advance, $booking_slot,
            $id
        ]);

        // FAQs (featured only, up to 8)
        if ($l['featured'] && !empty($_POST['faq_q'])) {
            db()->prepare("DELETE FROM listing_faqs WHERE listing_id=?")->execute([$id]);
            $n = 0;
            foreach ($_POST['faq_q'] as $i => $q) {
                $q = trim($q); $a = trim($_POST['faq_a'][$i] ?? '');
                if ($q && $a && $n < 8) {
                    db()->prepare("INSERT INTO listing_faqs (listing_id,question,answer,sort_order) VALUES (?,?,?,?)")
                         ->execute([$id, $q, $a, $n++]);
                }
            }
        }

        flash('success', t('Listing updated successfully.', 'Annonce mise à jour avec succès.'));
        redirect(SITE_URL . '/dashboard');
    }
}

// Existing FAQs
$faqs = [];
if ($l['featured']) {
    try {
        $fq = db()->prepare("SELECT * FROM listing_faqs WHERE listing_id=? ORDER BY sort_order LIMIT 8");
        $fq->execute([$id]);
        $faqs = $fq->fetchAll();
    } catch (Exception $e) {}
}

// FAQ SEO count
$faqCount = count($faqs);

// Section completion map
$done = [
    'identity' => !empty($l['title']) && (!$l['featured'] || !empty($l['tagline'])),
    'story'    => !empty($l['description']) && str_word_count(strip_tags($l['description'])) >= 30,
    'services' => !empty($services),
    'contact'  => !empty($l['phone']) || !empty($l['whatsapp']),
    'seo'      => !empty($l['keywords']) && $faqCount >= 2,
    'hours'    => !empty($hours),
    'media'    => !empty($l['logo']),
];
$totalSections = count($done);
$doneSections  = count(array_filter($done));
$pct           = (int)round($doneSections / $totalSections * 100);

// First incomplete section (to auto-open)
$firstOpen = 'identity';
foreach ($done as $sec => $isDone) { if (!$isDone) { $firstOpen = $sec; break; } }

// SEO score (0–100)
$seoScore = 0;
if (!empty($l['tagline']))    $seoScore += 20;
if (str_word_count(strip_tags($l['description'] ?? '')) >= 80) $seoScore += 25;
if (!empty($l['keywords']))   $seoScore += 20;
if (!empty($services))        $seoScore += 15;
if ($faqCount >= 3)           $seoScore += 20;

$pageTitle = t('Edit Listing — 237Biz', 'Modifier l\'annonce — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<?php if (!empty($errors)): ?>
<div class="container" style="max-width:820px;margin-top:1rem;">
  <?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
/* ── Progress bars ── */
.progress-bar-bg { height:7px; background:rgba(255,255,255,0.08); border-radius:99px; overflow:hidden; margin:5px 0; }
.progress-bar-fill { height:100%; border-radius:99px; transition:width .5s; }

/* ── Sections ── */
.esec { border:1px solid rgba(255,255,255,0.08); border-radius:14px; margin-bottom:10px; }
.esec.done { border-color:rgba(0,168,120,0.35); }
.esec-head {
  display:flex; align-items:center; gap:12px; padding:14px 20px;
  cursor:pointer; user-select:none;
  background:rgba(255,255,255,0.015); border-radius:14px;
}
.esec-head:hover { background:rgba(255,255,255,0.04); }
.esec-icon { font-size:1.25rem; flex-shrink:0; }
.esec-title { font-weight:700; font-size:0.95rem; margin:0; }
.esec-sub { font-size:0.73rem; color:var(--muted); margin-top:1px; }
.esec-status { margin-left:auto; font-size:0.75rem; font-weight:600; color:var(--muted); white-space:nowrap; }
.esec.done .esec-status { color:#00A878; }
.esec-chev { font-size:0.75rem; color:var(--muted); transition:transform .25s; flex-shrink:0; }
.esec-chev.open { transform:rotate(180deg); }
/* KEY: overflow:hidden on body ONLY, not on .esec parent */
.esec-body { max-height:0; overflow:hidden; transition:max-height .38s ease; }
.esec-body.open { max-height:3000px; }
.esec-inner { padding:4px 20px 22px; }

/* ── SEO tip ── */
.seo-tip {
  display:flex; gap:8px; background:rgba(245,200,66,0.05);
  border:1px solid rgba(245,200,66,0.18); border-radius:8px;
  padding:10px 12px; margin-bottom:14px;
  font-size:0.78rem; color:rgba(255,255,255,0.75); line-height:1.55;
}
.seo-tip b { color:var(--yellow); }
.field-hint { font-size:0.72rem; color:var(--muted-2); margin-top:4px; line-height:1.5; }

/* ── Rich text editor ── */
.rte-wrap { border:2px solid rgba(0,168,120,0.45); border-radius:10px; overflow:hidden; }
.rte-toolbar {
  display:flex; flex-wrap:wrap; gap:3px; padding:8px 10px;
  background:rgba(0,168,120,0.07); border-bottom:1px solid rgba(0,168,120,0.3);
}
.rte-toolbar button {
  background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);
  color:rgba(255,255,255,0.85); border-radius:6px; padding:5px 11px;
  cursor:pointer; font-size:13px; font-family:inherit; transition:all .15s;
}
.rte-toolbar button:hover { background:rgba(0,168,120,0.25); border-color:#00A878; color:#fff; }
.rte-toolbar button.rte-active { background:rgba(0,168,120,0.4); border-color:#00A878; color:#00A878; font-weight:700; }
.rte-toolbar .rte-sep { width:1px; background:rgba(255,255,255,0.12); margin:3px 4px; }
.rte-editor {
  min-height:220px; max-height:500px; overflow-y:auto;
  padding:14px 16px; outline:none;
  font-size:14px; line-height:1.85; color:rgba(255,255,255,0.9);
  background:rgba(255,255,255,0.025);
  cursor:text;
}
.rte-editor:focus { background:rgba(255,255,255,0.035); }
.rte-editor:empty::before {
  content:attr(data-ph);
  color:rgba(255,255,255,0.28); pointer-events:none; display:block;
}
.rte-editor h2 { font-size:1.1rem; font-weight:700; margin:14px 0 6px; color:#fff; }
.rte-editor h3 { font-size:0.95rem; font-weight:600; margin:12px 0 5px; color:rgba(255,255,255,0.9); }
.rte-editor p  { margin:0 0 8px; }
.rte-editor ul, .rte-editor ol { padding-left:22px; margin:5px 0 8px; }
.rte-editor li { margin-bottom:3px; }
.rte-editor a  { color:#00A878; }
.rte-editor strong { font-weight:700; }
.rte-footer {
  padding:6px 14px; background:rgba(0,0,0,0.18);
  font-size:11.5px; color:rgba(255,255,255,0.35);
  display:flex; justify-content:space-between; align-items:center;
}
.rte-count-good { color:#00A878; }
.rte-count-warn { color:#fcd116; }
.rte-instructions {
  font-size:12px; color:rgba(0,168,120,0.8);
  padding:6px 0 4px; text-align:center;
}

/* ── Services ── */
.svc-item {
  border:1px solid rgba(255,255,255,0.08); border-radius:8px;
  padding:12px; margin-bottom:8px; background:rgba(255,255,255,0.02);
}
.svc-item input, .svc-item textarea {
  width:100%; box-sizing:border-box; margin-bottom:6px;
  background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1);
  border-radius:6px; padding:8px 11px; color:var(--white);
  font-size:13.5px; font-family:inherit;
}
.svc-item input:focus, .svc-item textarea:focus { outline:none; border-color:rgba(0,168,120,0.5); }
.svc-item textarea { resize:vertical; margin-bottom:4px; }
.btn-svc-remove { background:none; border:none; color:var(--muted); font-size:12px; cursor:pointer; padding:0; font-family:inherit; }
.btn-svc-remove:hover { color:#e63946; }
.btn-add-svc {
  width:100%; border:1.5px dashed rgba(0,168,120,0.4);
  background:none; color:#00A878; border-radius:8px;
  padding:10px; font-size:13px; font-weight:600; cursor:pointer;
  font-family:inherit; transition:background .15s;
}
.btn-add-svc:hover { background:rgba(0,168,120,0.07); }

/* ── Tags ── */
.tags-wrap {
  border:1.5px solid rgba(255,255,255,0.1); border-radius:8px;
  padding:7px 10px; background:rgba(255,255,255,0.03);
  display:flex; flex-wrap:wrap; gap:5px; align-items:center; min-height:42px; cursor:text;
}
.tags-wrap:focus-within { border-color:rgba(0,168,120,0.5); }
.tag-chip {
  display:inline-flex; align-items:center; gap:4px;
  background:rgba(0,168,120,0.18); color:#00A878;
  border:1px solid rgba(0,168,120,0.3); border-radius:99px;
  padding:3px 10px; font-size:12px; font-weight:500;
}
.tag-chip button { background:none; border:none; color:rgba(0,168,120,0.7); cursor:pointer; font-size:15px; line-height:1; padding:0; }
.tag-input-field { border:none; background:none; color:var(--white); outline:none; font-size:13px; min-width:130px; flex:1; font-family:inherit; }

/* ── Hours ── */
.hours-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:7px; }
.hours-label { display:flex; align-items:center; gap:6px; min-width:125px; cursor:pointer; }
.hours-label input[type=checkbox] { accent-color:#00A878; width:15px; height:15px; }
.hours-times { display:flex; align-items:center; gap:7px; }
.hours-times input[type=time] {
  padding:5px 8px; border:1px solid rgba(255,255,255,0.1);
  border-radius:6px; background:rgba(255,255,255,0.05);
  color:var(--white); font-size:13px;
}
.hours-closed { font-size:12px; color:var(--muted); }

/* ── Save bar ── */
.save-bar { display:flex; gap:10px; margin-top:20px; flex-wrap:wrap; align-items:center; }
.autosave-txt { font-size:11.5px; color:var(--muted); margin-left:auto; }
.autosave-txt.saved { color:#00A878; }

/* ── FAQ suggestions ── */
.faq-chips { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:12px; }
.faq-chip-lbl { font-size:11px; color:var(--muted); align-self:center; margin-right:3px; }
.faq-chip {
  border:1px solid rgba(245,200,66,0.2); background:rgba(245,200,66,0.04);
  color:rgba(255,255,255,0.65); border-radius:99px; padding:4px 11px;
  font-size:11.5px; cursor:pointer; font-family:inherit; transition:background .15s;
}
.faq-chip:hover { background:rgba(245,200,66,0.12); color:#fff; }
.faq-block { border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:12px; margin-bottom:12px; }
.faq-block:last-child { border-bottom:none; margin-bottom:0; padding-bottom:0; }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard','Tableau de bord') ?></a> ›
      <span><?= t('Edit Listing','Modifier') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.2rem);">
      ✏️ <?= t('Edit Listing','Modifier l\'annonce') ?>
    </h1>
  </div>
</div>

<!-- ── Dual progress bar ───────────────────────────────────────────────── -->
<div style="background:#0A1F12;border-bottom:1px solid rgba(255,255,255,0.06);padding:12px 0;">
  <div class="container" style="max-width:820px;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
      <div>
        <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
          <span style="font-size:12px;font-weight:700;color:rgba(255,255,255,0.7);">📋 <?= t('Profile','Profil') ?></span>
          <span style="font-size:11px;color:var(--muted);"><?= $doneSections ?>/<?= $totalSections ?></span>
        </div>
        <div class="progress-bar-bg">
          <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:linear-gradient(90deg,#007A5E,#00A878);"></div>
        </div>
      </div>
      <?php if ($l['featured']): ?>
      <div>
        <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
          <span style="font-size:12px;font-weight:700;color:rgba(255,255,255,0.7);">🔍 SEO</span>
          <span style="font-size:11px;color:<?= $seoScore >= 80 ? '#00A878' : ($seoScore >= 50 ? '#fcd116' : '#e63946') ?>;"><?= $seoScore ?>%</span>
        </div>
        <div class="progress-bar-bg">
          <div class="progress-bar-fill" style="width:<?= $seoScore ?>%;background:<?= $seoScore >= 80 ? 'linear-gradient(90deg,#00A878,#6fcf97)' : ($seoScore >= 50 ? 'linear-gradient(90deg,#B8860B,#fcd116)' : 'linear-gradient(90deg,#c0392b,#e63946)') ?>;"></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
  <div class="container" style="max-width:820px;">
    <form method="POST" enctype="multipart/form-data" id="listingForm">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">

      <!-- ══ 1. BUSINESS IDENTITY ══════════════════════════════════════════ -->
      <div class="esec <?= $done['identity'] ? 'done' : '' ?>" id="sec-identity">
        <div class="esec-head" data-toggle="identity">
          <span class="esec-icon">🏢</span>
          <div>
            <p class="esec-title"><?= t('Business Identity','Identité de l\'entreprise') ?></p>
            <p class="esec-sub"><?= t('Name, tagline, category — what Google shows first','Nom, accroche, catégorie — ce que Google affiche en premier') ?></p>
          </div>
          <span class="esec-status"><?= $done['identity'] ? '✓ '.t('Done','Fait') : '○ '.t('Required','Requis') ?></span>
          <span class="esec-chev" id="chev-identity">▾</span>
        </div>
        <div class="esec-body" id="body-identity">
          <div class="esec-inner">

            <div class="seo-tip">
              <span>🔍</span>
              <span><b><?= t('SEO:','SEO :') ?></b> <?= t('Your business name and tagline are the first things Google shows in search results. Include your main service and city in the tagline.','Votre nom et accroche sont les premières choses que Google affiche. Incluez votre service principal et votre ville dans l\'accroche.') ?></span>
            </div>

            <div class="form-group">
              <label><?= t('Business Name *','Nom de l\'entreprise *') ?></label>
              <input type="text" name="title" value="<?= e($l['title']) ?>" required
                     placeholder="<?= e(t('e.g. Mama Ngozi Restaurant','ex. Restaurant Mama Ngozi')) ?>">
            </div>

            <?php if ($l['featured']): ?>
            <div class="form-group">
              <label>
                📣 <?= t('Tagline','Accroche') ?>
                <span style="font-size:11px;color:var(--muted);font-weight:400;margin-left:5px;"><?= t('Max 160 chars — shown in Google search snippets','Max 160 car. — affiché dans les résultats Google') ?></span>
              </label>
              <div style="position:relative;">
                <input type="text" name="tagline" id="taglineInput" maxlength="160"
                       value="<?= e($l['tagline'] ?? '') ?>"
                       placeholder="<?= e(t("Limbe's most trusted electrician — 24/7 emergency service","Électricien le plus fiable de Limbe — service d'urgence 24h/24")) ?>">
                <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--muted);pointer-events:none;"><span id="taglineCount"><?= mb_strlen($l['tagline'] ?? '') ?></span>/160</span>
              </div>
              <!-- Live Google preview -->
              <div style="margin-top:10px;background:#1a1a2e;border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:12px 14px;">
                <div style="font-size:11px;color:rgba(255,255,255,0.3);margin-bottom:4px;"><?= t('Google preview:','Aperçu Google :') ?></div>
                <div style="font-size:14px;color:#8ab4f8;margin-bottom:2px;" id="serpTitle"><?= e($l['title']) ?></div>
                <div style="font-size:11px;color:#8ab4f8;margin-bottom:4px;">237biz.net › listing › <?= e($l['slug'] ?? '') ?></div>
                <div style="font-size:12px;color:#bdc1c6;line-height:1.5;" id="serpDesc"><?= e($l['tagline'] ?? t('Add a tagline above to preview how your listing appears in Google.','Ajoutez une accroche ci-dessus pour prévisualiser votre annonce dans Google.')) ?></div>
              </div>
            </div>
            <?php endif; ?>

            <div class="form-row">
              <div class="form-group">
                <label><?= t('Category *','Catégorie *') ?></label>
                <select name="category_id" required>
                  <?php foreach ($cats as $cat): ?>
                  <option value="<?= $cat['id'] ?>" <?= $l['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                    <?= $cat['icon'] ?> <?= e(lang()==='fr' ? $cat['name_fr'] : $cat['name_en']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label><?= t('City *','Ville *') ?></label>
                <select name="location_id" required>
                  <?php foreach ($locs as $loc): ?>
                  <option value="<?= $loc['id'] ?>" <?= $l['location_id'] == $loc['id'] ? 'selected' : '' ?>>
                    <?= e($loc['name_en']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- ══ 2. DESCRIPTION ════════════════════════════════════════════════ -->
      <div class="esec <?= $done['story'] ? 'done' : '' ?>" id="sec-story">
        <div class="esec-head" data-toggle="story">
          <span class="esec-icon">📝</span>
          <div>
            <p class="esec-title"><?= t('Your Story','Votre Histoire') ?></p>
            <p class="esec-sub"><?= t('Description — the heart of your SEO ranking','Description — le cœur de votre classement SEO') ?></p>
          </div>
          <span class="esec-status"><?= $done['story'] ? '✓ '.t('Done','Fait') : '○ '.t('Add description','Ajouter') ?></span>
          <span class="esec-chev" id="chev-story">▾</span>
        </div>
        <div class="esec-body" id="body-story">
          <div class="esec-inner">

            <div class="seo-tip">
              <span>🔍</span>
              <span><b><?= t('SEO:','SEO :') ?></b> <?= t('Write at least 100 words. Mention your city, services and what makes you unique. Use Headings to structure your content — Google reads the structure.','Rédigez au moins 100 mots. Mentionnez votre ville, vos services et ce qui vous distingue. Utilisez les Titres pour structurer votre contenu — Google lit la structure.') ?></span>
            </div>

            <label><?= t('Description','Description') ?></label>

            <?php if ($l['featured']): ?>
            <!-- Rich text editor — built-in, no CDN needed -->
            <p class="rte-instructions">
              ↓ <?= t('Click inside the green box below to type. Select text then click a toolbar button to format it.','Cliquez dans le cadre vert ci-dessous pour écrire. Sélectionnez du texte puis cliquez sur un bouton pour le formater.') ?>
            </p>
            <div class="rte-wrap">
              <div class="rte-toolbar" id="rteToolbar">
                <button type="button" data-cmd="formatBlock" data-val="p"><?= t('Normal','Normal') ?></button>
                <button type="button" data-cmd="formatBlock" data-val="h2"><?= t('Heading','Titre') ?></button>
                <button type="button" data-cmd="formatBlock" data-val="h3"><?= t('Subheading','Sous-titre') ?></button>
                <div class="rte-sep"></div>
                <button type="button" data-cmd="bold"><b>B</b></button>
                <button type="button" data-cmd="italic"><i>I</i></button>
                <button type="button" data-cmd="underline"><u>U</u></button>
                <div class="rte-sep"></div>
                <button type="button" data-cmd="insertUnorderedList"><?= t('• Bullets','• Puces') ?></button>
                <button type="button" data-cmd="insertOrderedList"><?= t('1. Numbers','1. Numéros') ?></button>
                <div class="rte-sep"></div>
                <button type="button" id="rteLinkBtn">🔗 <?= t('Link','Lien') ?></button>
                <button type="button" data-cmd="removeFormat">✕ <?= t('Clear','Effacer') ?></button>
              </div>
              <div class="rte-editor" id="rteEditor"
                   contenteditable="true"
                   data-ph="<?= e(t('Click here and start writing your business description...','Cliquez ici et commencez à rédiger votre description...')) ?>">
              </div>
              <div class="rte-footer">
                <span id="rteWordCount">0 <?= t('words','mots') ?></span>
                <span><?= t('Select text, then click a format button above','Sélectionnez du texte, puis cliquez sur un bouton') ?></span>
              </div>
            </div>
            <!-- Hidden input carries the HTML to PHP POST -->
            <input type="hidden" name="description_html" id="descHtmlInput" value="<?= e($l['description'] ?? '') ?>">
            <input type="hidden" name="description" id="descPlainInput">
            <div class="field-hint"><?= t('Tip: Use Heading for section titles (e.g. "Our Services"), Normal for body text, Bullets for lists.','Conseil : Utilisez Titre pour les titres de section (ex. "Nos Services"), Normal pour le corps du texte, Puces pour les listes.') ?></div>

            <?php else: ?>
            <!-- Free listing — plain textarea, 300 word limit -->
            <textarea name="description" rows="7" id="freeDesc"
                      placeholder="<?= e(t('Describe your business — what you offer, where you are, who you serve...','Décrivez votre entreprise — ce que vous offrez, où vous êtes, qui vous servez...')) ?>"><?= e($l['description']) ?></textarea>
            <div style="display:flex;justify-content:space-between;margin-top:4px;">
              <p class="field-hint">
                <?= t('Free listings: 300 word limit.','Annonces gratuites : limite de 300 mots.') ?>
                <a href="<?= SITE_URL ?>/upgrade-listing?listing_id=<?= $l['id'] ?>" style="color:var(--yellow);"><?= t('Upgrade for unlimited →','Améliorer pour illimité →') ?></a>
              </p>
              <span id="freeWc" style="font-size:11px;color:var(--muted);">0/300</span>
            </div>
            <?php endif; ?>

          </div>
        </div>
      </div>

      <?php if ($l['featured']): ?>

      <!-- ══ 3. SERVICES ═══════════════════════════════════════════════════ -->
      <div class="esec <?= $done['services'] ? 'done' : '' ?>" id="sec-services">
        <div class="esec-head" data-toggle="services">
          <span class="esec-icon">🛠️</span>
          <div>
            <p class="esec-title"><?= t('Services','Services') ?></p>
            <p class="esec-sub"><?= t('Each service is indexed separately by Google','Chaque service est indexé séparément par Google') ?></p>
          </div>
          <span class="esec-status"><?= $done['services'] ? '✓ '.count($services).' '.t('added','ajoutés') : '○ '.t('Add services','Ajouter') ?></span>
          <span class="esec-chev" id="chev-services">▾</span>
        </div>
        <div class="esec-body" id="body-services">
          <div class="esec-inner">

            <div class="seo-tip">
              <span>🔍</span>
              <span><b><?= t('SEO:','SEO :') ?></b> <?= t('Name services the way customers search. "Emergency plumbing Limbe" ranks better than just "Plumbing". Businesses with 5+ services get 3× more views.','Nommez les services comme les clients les cherchent. "Plomberie d\'urgence Limbe" se classe mieux que juste "Plomberie". Les entreprises avec 5+ services obtiennent 3× plus de vues.') ?></span>
            </div>

            <div id="servicesList">
              <?php foreach ($services as $svc): ?>
              <div class="svc-item">
                <input type="text" class="svc-name"
                       placeholder="<?= e(t('Service name — e.g. Website Design','Nom du service — ex. Conception de site web')) ?>"
                       value="<?= e($svc['name'] ?? '') ?>">
                <textarea class="svc-desc" rows="2"
                          placeholder="<?= e(t('Short description, price range, turnaround...','Courte description, fourchette de prix, délai...')) ?>"><?= e($svc['description'] ?? '') ?></textarea>
                <button type="button" class="btn-svc-remove">✕ <?= t('Remove','Supprimer') ?></button>
              </div>
              <?php endforeach; ?>
            </div>

            <button type="button" class="btn-add-svc" id="addSvcBtn"
                    data-name-ph="<?= e(t('Service name — e.g. Website Design','Nom du service — ex. Conception de site web')) ?>"
                    data-desc-ph="<?= e(t('Short description, price range, turnaround...','Courte description, fourchette de prix, délai...')) ?>"
                    data-remove-txt="<?= e(t('Remove','Supprimer')) ?>">
              + <?= t('Add a Service','Ajouter un service') ?>
            </button>
            <input type="hidden" name="services_json" id="servicesJson" value="<?= e(json_encode($services)) ?>">

          </div>
        </div>
      </div>

      <?php endif; ?>

      <!-- ══ 4. CONTACT DETAILS ════════════════════════════════════════════ -->
      <div class="esec <?= $done['contact'] ? 'done' : '' ?>" id="sec-contact">
        <div class="esec-head" data-toggle="contact">
          <span class="esec-icon">📞</span>
          <div>
            <p class="esec-title"><?= t('Contact Details','Coordonnées') ?></p>
            <p class="esec-sub"><?= t('How customers reach you — WhatsApp is most important','Comment les clients vous contactent — WhatsApp est essentiel') ?></p>
          </div>
          <span class="esec-status"><?= $done['contact'] ? '✓ '.t('Done','Fait') : '○ '.t('Add contact','Ajouter') ?></span>
          <span class="esec-chev" id="chev-contact">▾</span>
        </div>
        <div class="esec-body" id="body-contact">
          <div class="esec-inner">

            <div class="seo-tip">
              <span>💬</span>
              <span><b>WhatsApp:</b> <?= t('Include the country code 237 so the tap-to-chat link works. e.g. 237674073852','Incluez l\'indicatif 237 pour que le lien fonctionne. ex. 237674073852') ?></span>
            </div>

            <div class="form-group">
              <label>📍 <?= t('Address','Adresse') ?></label>
              <input type="text" name="address" value="<?= e($l['address'] ?? '') ?>"
                     placeholder="<?= e(t('e.g. Commercial Avenue, Limbe','ex. Avenue Commerciale, Limbe')) ?>">
              <p class="field-hint"><?= t('A specific address helps Google show you in "near me" searches.','Une adresse spécifique aide Google à vous afficher dans les recherches "près de moi".') ?></p>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>📞 <?= t('Phone','Téléphone') ?></label>
                <input type="tel" name="phone" value="<?= e($l['phone'] ?? '') ?>" placeholder="674 073 852">
              </div>
              <div class="form-group">
                <label>💬 WhatsApp</label>
                <input type="tel" name="whatsapp" value="<?= e($l['whatsapp'] ?? '') ?>" placeholder="237674073852">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>✉️ Email</label>
                <input type="email" name="email" value="<?= e($l['email'] ?? '') ?>" placeholder="you@business.com">
              </div>
              <div class="form-group">
                <label>🌐 <?= t('Website','Site web') ?></label>
                <input type="url" name="website" value="<?= e($l['website'] ?? '') ?>" placeholder="https://yourbusiness.com">
              </div>
            </div>
            <div class="form-group">
              <label>📘 Facebook</label>
              <input type="url" name="facebook" value="<?= e($l['facebook'] ?? '') ?>" placeholder="https://facebook.com/yourbusiness">
            </div>
            <?php if ($l['featured']): ?>
            <div class="form-row">
              <div class="form-group">
                <label>🎵 TikTok</label>
                <input type="url" name="tiktok" value="<?= e($l['tiktok'] ?? '') ?>" placeholder="https://tiktok.com/@yourbusiness">
              </div>
              <div class="form-group">
                <label>📸 Instagram</label>
                <input type="url" name="instagram" value="<?= e($l['instagram'] ?? '') ?>" placeholder="https://instagram.com/yourbusiness">
              </div>
            </div>
            <?php else: ?>
            <div style="background:rgba(245,200,66,0.05);border:1px solid rgba(245,200,66,0.2);border-radius:8px;padding:10px 12px;font-size:13px;color:var(--muted);">
              ⭐ <?= t('TikTok and Instagram links are available on Featured Listings.','Les liens TikTok et Instagram sont disponibles sur les Annonces Vedettes.') ?>
              <a href="<?= SITE_URL ?>/upgrade-listing?listing_id=<?= $l['id'] ?>" style="color:var(--yellow);"><?= t('Upgrade →','Améliorer →') ?></a>
            </div>
            <?php endif; ?>

          </div>
        </div>
      </div>

      <?php if ($l['featured']): ?>

      <!-- ══ 5. SEO & DISCOVERY ════════════════════════════════════════════ -->
      <div class="esec <?= $done['seo'] ? 'done' : '' ?>" id="sec-seo">
        <div class="esec-head" data-toggle="seo">
          <span class="esec-icon">🔑</span>
          <div>
            <p class="esec-title"><?= t('SEO & Discovery','SEO & Découverte') ?></p>
            <p class="esec-sub"><?= t('Keywords + FAQs — how Google and AI find you','Mots-clés + FAQ — comment Google et l\'IA vous trouvent') ?></p>
          </div>
          <span class="esec-status"><?= $done['seo'] ? '✓ '.t('Done','Fait') : '○ '.t('High impact','Impact élevé') ?></span>
          <span class="esec-chev" id="chev-seo">▾</span>
        </div>
        <div class="esec-body" id="body-seo">
          <div class="esec-inner">

            <!-- Keywords -->
            <div class="seo-tip">
              <span>🔍</span>
              <span><b><?= t('Keywords:','Mots-clés :') ?></b> <?= t('Think about what customers type into Google to find you. Include your services, your city, and specialities. Up to 10 keywords.','Pensez à ce que les clients tapent dans Google pour vous trouver. Incluez vos services, votre ville et vos spécialités. Jusqu\'à 10 mots-clés.') ?></span>
            </div>
            <div class="form-group">
              <label>🔑 <?= t('Keywords','Mots-clés') ?> — <?= t('type and press Enter','tapez et appuyez sur Entrée') ?></label>
              <div class="tags-wrap" id="tagsWrap">
                <input type="text" class="tag-input-field" id="tagInputField"
                       placeholder="<?= e(t('e.g. web hosting, Cameroon, SSL...','ex. hébergement web, Cameroun, SSL...')) ?>">
              </div>
              <div style="text-align:right;font-size:11px;color:var(--muted);margin-top:3px;"><span id="tagCount">0</span>/10</div>
            </div>
            <input type="hidden" name="keywords" id="keywordsHidden" value="<?= e($l['keywords'] ?? '') ?>">

            <!-- FAQs -->
            <div style="border-top:1px solid rgba(255,255,255,0.05);margin:16px 0;"></div>
            <div class="seo-tip">
              <span>🤖</span>
              <span><b><?= t('FAQs = AI visibility:','FAQ = visibilité IA :') ?></b> <?= t('When someone asks ChatGPT or Google AI "find me a plumber in Limbe", it pulls answers from FAQ sections. Add at least 3 for best results.','Quand quelqu\'un demande à ChatGPT "trouver un plombier à Limbe", il extrait les réponses des sections FAQ. Ajoutez au moins 3 pour de meilleurs résultats.') ?></span>
            </div>
            <label>❓ <?= t('Frequently Asked Questions','Questions Fréquentes') ?></label>

            <!-- Quick-add chips (PHP data only, no JS strings) -->
            <div class="faq-chips">
              <span class="faq-chip-lbl"><?= t('Quick add:','Ajout rapide :') ?></span>
              <?php
              $faqSuggestions = [
                t('Do you offer emergency service?','Proposez-vous un service d\'urgence ?'),
                t('Do you deliver in Limbe?','Livrez-vous à Limbe ?'),
                t('Do you accept MTN Mobile Money?','Acceptez-vous MTN Mobile Money ?'),
                t('Are you open on weekends?','Êtes-vous ouvert le week-end ?'),
                t('Do you offer free quotes?','Offrez-vous des devis gratuits ?'),
              ];
              foreach ($faqSuggestions as $sug): ?>
              <button type="button" class="faq-chip" data-q="<?= e($sug) ?>"><?= e($sug) ?></button>
              <?php endforeach; ?>
            </div>

            <?php for ($i = 0; $i < 8; $i++):
              $q = $faqs[$i]['question'] ?? '';
              $a = $faqs[$i]['answer']   ?? '';
            ?>
            <div class="faq-block">
              <div class="form-group" style="margin-bottom:6px;">
                <input type="text" name="faq_q[]" maxlength="200" value="<?= e($q) ?>"
                       placeholder="<?= e($i < 3
                         ? t('Question','Question').' '.($i+1).' ('.t('required','requis').')'
                         : t('Question','Question').' '.($i+1).' ('.t('optional','optionnel').')') ?>">
              </div>
              <textarea name="faq_a[]" rows="2"
                        placeholder="<?= e(t('Your answer — be specific and mention your location','Votre réponse — soyez précis et mentionnez votre localisation')) ?>"
                        style="width:100%;box-sizing:border-box;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;padding:8px 11px;color:var(--white);font-size:13px;font-family:inherit;resize:vertical;"><?= e($a) ?></textarea>
            </div>
            <?php endfor; ?>

          </div>
        </div>
      </div>

      <!-- ══ 6. HOURS & BOOSTERS ═══════════════════════════════════════════ -->
      <div class="esec <?= $done['hours'] ? 'done' : '' ?>" id="sec-hours">
        <div class="esec-head" data-toggle="hours">
          <span class="esec-icon">🕐</span>
          <div>
            <p class="esec-title"><?= t('Hours & Boosters','Heures & Visibilité') ?></p>
            <p class="esec-sub"><?= t('Opening hours, CTA button, video embed','Heures d\'ouverture, bouton d\'action, vidéo') ?></p>
          </div>
          <span class="esec-status"><?= $done['hours'] ? '✓ '.t('Done','Fait') : '○ '.t('Add hours','Ajouter') ?></span>
          <span class="esec-chev" id="chev-hours">▾</span>
        </div>
        <div class="esec-body" id="body-hours">
          <div class="esec-inner">

            <div class="seo-tip">
              <span>🔍</span>
              <span><b><?= t('Open Now:','Ouvert maintenant :') ?></b> <?= t('Setting your hours makes your listing eligible for "open now" searches and shows an Open Now badge to visitors.','Définir vos heures rend votre annonce éligible aux recherches "ouvert maintenant" et affiche un badge Ouvert maintenant aux visiteurs.') ?></span>
            </div>

            <label style="margin-bottom:10px;display:block;">🕐 <?= t('Business Hours','Heures d\'ouverture') ?></label>
            <?php foreach ($days as $day):
              $d = $hours[$day] ?? ['open'=>false,'from'=>'08:00','to'=>'17:00'];
            ?>
            <div class="hours-row">
              <label class="hours-label">
                <input type="checkbox" class="hrs-check" data-day="<?= $day ?>" <?= !empty($d['open']) ? 'checked' : '' ?>>
                <span style="font-size:13.5px;font-weight:500;"><?= t($day, $day) ?></span>
              </label>
              <div class="hours-times" style="<?= empty($d['open']) ? 'opacity:.3;pointer-events:none;' : '' ?>">
                <input type="time" class="hrs-from" data-day="<?= $day ?>" value="<?= e($d['from'] ?? '08:00') ?>">
                <span style="font-size:12px;color:var(--muted);"><?= t('to','à') ?></span>
                <input type="time" class="hrs-to"   data-day="<?= $day ?>" value="<?= e($d['to']   ?? '17:00') ?>">
              </div>
              <span class="hours-closed" style="<?= !empty($d['open']) ? 'display:none;' : '' ?>"><?= t('Closed','Fermé') ?></span>
            </div>
            <?php endforeach; ?>
            <input type="hidden" name="hours_json" id="hoursJson">

            <div style="border-top:1px solid rgba(255,255,255,0.05);margin:16px 0;"></div>

            <!-- CTA Button -->
            <label>🎯 <?= t('Call-to-Action Button','Bouton d\'appel à l\'action') ?></label>
            <p class="field-hint" style="margin-bottom:8px;"><?= t('A prominent button shown at the top of your listing. Link to your WhatsApp for best conversion.','Un bouton proéminent en haut de votre annonce. Liez à votre WhatsApp pour le meilleur taux de conversion.') ?></p>
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Button Label','Libellé') ?></label>
                <select name="cta_label">
                  <?php foreach (['Book Now','Get a Quote','Order on WhatsApp','Contact Us','Visit Website','Call Now','Reserve a Table','Get Directions'] as $opt): ?>
                  <option value="<?= $opt ?>" <?= ($l['cta_label'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label><?= t('Button URL','URL') ?></label>
                <input type="url" name="cta_url" value="<?= e($l['cta_url'] ?? '') ?>" placeholder="https://wa.me/237...">
              </div>
            </div>

            <div style="border-top:1px solid rgba(255,255,255,0.05);margin:16px 0;"></div>

            <!-- Video -->
            <label>🎬 <?= t('Video','Vidéo') ?></label>
            <p class="field-hint" style="margin-bottom:8px;"><?= t('Paste a YouTube or TikTok link — it will be embedded on your listing. If Tahirih created a video for you, add it here!','Collez un lien YouTube ou TikTok — il sera intégré sur votre annonce. Si Tahirih a créé une vidéo pour vous, ajoutez-la ici !') ?></p>
            <input type="url" name="video_url" value="<?= e($l['video_url'] ?? '') ?>"
                   placeholder="https://youtube.com/watch?v=... or https://tiktok.com/...">

          </div>
        </div>
      </div>

      <?php endif; // end featured sections ?>

      <!-- ══ 7. BOOKING SETTINGS ══════════════════════════════════════════ -->
      <?php if ($l['featured']): ?>
      <div class="esec <?= $bookingEnabled ? 'done' : '' ?>" id="sec-booking">
        <div class="esec-head" data-toggle="booking">
          <span class="esec-icon">📅</span>
          <div>
            <p class="esec-title"><?= t('Appointment Booking','Réservation de rendez-vous') ?></p>
            <p class="esec-sub"><?= t('Let customers book appointments directly from your listing','Permettez aux clients de réserver depuis votre annonce') ?></p>
          </div>
          <span class="esec-status"><?= $bookingEnabled ? '✓ '.t('Active','Actif') : '○ '.t('Disabled','Désactivé') ?></span>
          <span class="esec-chev" id="chev-booking">▾</span>
        </div>
        <div class="esec-body" id="body-booking">
          <div class="esec-inner">

            <!-- Enable toggle -->
            <div style="display:flex;align-items:center;gap:14px;padding:14px;background:rgba(0,168,120,0.05);border:1px solid rgba(0,168,120,0.2);border-radius:10px;margin-bottom:16px;">
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer;flex:1;margin:0;">
                <input type="checkbox" name="booking_enabled" id="bookingToggle" value="1"
                       <?= $bookingEnabled ? 'checked' : '' ?>
                       style="accent-color:#00A878;width:18px;height:18px;cursor:pointer;">
                <div>
                  <div style="font-weight:700;font-size:14px;"><?= t('Enable Appointment Booking','Activer la prise de rendez-vous') ?></div>
                  <div style="font-size:12px;color:var(--muted);"><?= t('A "Book an Appointment" button appears on your listing.','Un bouton "Prendre rendez-vous" apparaît sur votre annonce.') ?></div>
                </div>
              </label>
              <?php if ($bookingEnabled && !empty($l['slug'])): ?>
              <a href="<?= SITE_URL ?>/manage-bookings?listing_id=<?= $l['id'] ?>" style="font-size:12px;color:var(--green);white-space:nowrap;">
                <?= t('View bookings →','Voir les réservations →') ?>
              </a>
              <?php endif; ?>
            </div>

            <div id="bookingSettings" style="<?= !$bookingEnabled ? 'opacity:.4;pointer-events:none;' : '' ?>">

              <!-- Slot duration -->
              <div class="form-group">
                <label>⏱️ <?= t('Appointment Slot Duration','Durée des créneaux de rendez-vous') ?></label>
                <p class="field-hint" style="margin-bottom:8px;"><?= t('How long is each appointment slot? Customers will book in these intervals.','Quelle est la durée de chaque créneau ? Les clients réserveront par ces intervalles.') ?></p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                  <?php foreach ([15,30,45,60] as $mins): ?>
                  <label style="display:flex;align-items:center;gap:6px;cursor:pointer;background:rgba(255,255,255,0.04);border:1.5px solid <?= $bookingSlotDur===$mins ? '#00A878' : 'rgba(255,255,255,0.1)' ?>;border-radius:8px;padding:8px 16px;font-size:13.5px;font-weight:<?= $bookingSlotDur===$mins?'700':'500' ?>;color:<?= $bookingSlotDur===$mins?'#00A878':'rgba(255,255,255,0.7)' ?>;"
                        id="slotLbl<?= $mins ?>">
                    <input type="radio" name="booking_slot_duration" value="<?= $mins ?>"
                           <?= $bookingSlotDur===$mins ? 'checked' : '' ?>
                           style="accent-color:#00A878;"
                           onchange="document.querySelectorAll('[id^=slotLbl]').forEach(function(l){l.style.borderColor='rgba(255,255,255,0.1)';l.style.color='rgba(255,255,255,0.7)';l.style.fontWeight='500';}); this.closest('label').style.borderColor='#00A878'; this.closest('label').style.color='#00A878'; this.closest('label').style.fontWeight='700';">
                    <?= $mins ?> <?= t('min','min') ?>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Bookable services -->
              <div class="form-group">
                <label><?= t('Services available to book','Services disponibles à la réservation') ?>
                  <span style="font-size:11px;font-weight:400;color:var(--muted);margin-left:5px;"><?= t('(optional — leave empty to allow any booking)','(optionnel — laissez vide pour accepter toute réservation)') ?></span>
                </label>
                <div class="tags-wrap" id="bkServicesWrap"
                     style="min-height:42px;cursor:text;"
                     data-input="bkServiceInput">
                  <input type="text" class="tag-input-field" id="bkServiceInput"
                         placeholder="<?= e(t('Type a service, press Enter...','Tapez un service, appuyez sur Entrée...')) ?>">
                </div>
                <p class="field-hint"><?= t('Customers choose from this list when booking. e.g. "Web Design", "Business Email Setup"','Les clients choisissent dans cette liste lors de la réservation. ex. "Web Design", "Configuration Email"') ?></p>
                <input type="hidden" name="booking_services_json" id="bkServicesJson" value="<?= e(json_encode($bookingServices)) ?>">
              </div>

              <!-- Advance days -->
              <div class="form-group">
                <label>📆 <?= t('How far ahead can customers book?','Combien de jours à l\'avance les clients peuvent-ils réserver ?') ?></label>
                <div style="display:flex;align-items:center;gap:10px;">
                  <input type="number" name="booking_advance" value="<?= (int)$bookingAdvance ?>" min="1" max="365"
                         style="width:90px;padding:8px 12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-family:inherit;font-size:14px;">
                  <span style="font-size:13px;color:var(--muted);"><?= t('days','jours') ?></span>
                </div>
                <p class="field-hint"><?= t('Default: 30 days.','Par défaut : 30 jours.') ?></p>
              </div>

            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ══ 8. LOGO & MEDIA ═══════════════════════════════════════════════ -->
      <div class="esec <?= $done['media'] ? 'done' : '' ?>" id="sec-media">
        <div class="esec-head" data-toggle="media">
          <span class="esec-icon">📸</span>
          <div>
            <p class="esec-title"><?= t('Logo & Media','Logo & Médias') ?></p>
            <p class="esec-sub"><?= t('Listings with a logo get significantly more clicks','Les annonces avec logo obtiennent nettement plus de clics') ?></p>
          </div>
          <span class="esec-status"><?= $done['media'] ? '✓ '.t('Done','Fait') : '○ '.t('Add logo','Ajouter logo') ?></span>
          <span class="esec-chev" id="chev-media">▾</span>
        </div>
        <div class="esec-body" id="body-media">
          <div class="esec-inner">

            <div class="seo-tip">
              <span>📸</span>
              <span><?= t('Listings with a logo are 3× more likely to receive an enquiry. Use a square image at least 200×200px.','Les annonces avec logo ont 3× plus de chances de recevoir une demande. Utilisez une image carrée d\'au moins 200×200px.') ?></span>
            </div>

            <div class="form-group">
              <label><?= t('Business Logo / Profile Photo','Logo / Photo de profil') ?></label>
              <?php if ($l['logo']): ?>
              <div style="display:flex;align-items:center;gap:14px;margin-bottom:10px;">
                <img src="<?= e(UPLOAD_URL . $l['logo']) ?>" alt="logo" style="width:72px;height:72px;object-fit:cover;border-radius:10px;border:1px solid var(--border);">
                <div>
                  <p style="font-size:13px;color:#00A878;margin-bottom:2px;">✓ <?= t('Logo uploaded','Logo téléchargé') ?></p>
                  <p class="field-hint"><?= t('Upload a new image below to replace it.','Téléchargez une nouvelle image ci-dessous pour la remplacer.') ?></p>
                </div>
              </div>
              <?php endif; ?>
              <input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif" style="color:var(--muted);padding:6px 0;">
              <p class="field-hint">JPG, PNG, WebP · <?= t('Square recommended','Carré recommandé') ?> · Max 2MB</p>
            </div>

          </div>
        </div>
      </div>

      <!-- ── Save bar ──────────────────────────────────────────────────── -->
      <div class="save-bar">
        <button type="submit" class="btn btn-primary">💾 <?= t('Save Changes','Enregistrer') ?> →</button>
        <?php if (!empty($l['slug'])): ?>
        <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>?preview=1" class="btn btn-outline" target="_blank">
          👁 <?= t('Preview','Aperçu') ?>
        </a>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/dashboard" class="btn btn-outline"><?= t('Cancel','Annuler') ?></a>
        <span class="autosave-txt" id="autosaveTxt"></span>
      </div>
      <p style="color:var(--muted-2);font-size:12px;margin-top:8px;">
        💡 <?= t('Changes are saved and published immediately.','Les modifications sont enregistrées et publiées immédiatement.') ?>
      </p>

    </form>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT — zero PHP interpolation in JS strings
     All dynamic values come from data-* attributes or element values
══════════════════════════════════════════════════════════════════════════ -->
<script>
(function() {
'use strict';

// ── 1. ACCORDION ─────────────────────────────────────────────────────────
// Uses data-toggle="sectionid" on headers, id="body-sectionid" on bodies
// No onclick attributes — CSP safe
function toggleSection(id) {
  var body = document.getElementById('body-' + id);
  var chev = document.getElementById('chev-' + id);
  if (!body) return;
  var nowOpen = body.classList.toggle('open');
  if (chev) chev.classList.toggle('open', nowOpen);
}

document.querySelectorAll('[data-toggle]').forEach(function(head) {
  head.addEventListener('click', function() {
    toggleSection(this.getAttribute('data-toggle'));
  });
});

// Open first section that is NOT done
var opened = false;
document.querySelectorAll('.esec').forEach(function(sec) {
  if (!opened && !sec.classList.contains('done')) {
    var head = sec.querySelector('[data-toggle]');
    if (head) { toggleSection(head.getAttribute('data-toggle')); opened = true; }
  }
});
// If all done, open the first one anyway
if (!opened) {
  var first = document.querySelector('[data-toggle]');
  if (first) toggleSection(first.getAttribute('data-toggle'));
}


// ── 2. TAGLINE counter + live SERP preview ───────────────────────────────
var taglineInput = document.getElementById('taglineInput');
var taglineCount = document.getElementById('taglineCount');
var serpTitle    = document.getElementById('serpTitle');
var serpDesc     = document.getElementById('serpDesc');
var titleInput   = document.querySelector('input[name="title"]');

if (taglineInput) {
  taglineInput.addEventListener('input', function() {
    if (taglineCount) taglineCount.textContent = this.value.length;
    if (serpDesc)     serpDesc.textContent = this.value || serpDesc.getAttribute('data-default') || '';
  });
  // Set data-default from current text so we can restore it
  if (serpDesc) serpDesc.setAttribute('data-default', serpDesc.textContent);
}
if (titleInput && serpTitle) {
  titleInput.addEventListener('input', function() {
    serpTitle.textContent = this.value || serpTitle.getAttribute('data-default') || '';
  });
  serpTitle.setAttribute('data-default', serpTitle.textContent);
}


// ── 3. RICH TEXT EDITOR (no CDN — uses built-in execCommand) ─────────────
var rteEditor    = document.getElementById('rteEditor');
var descHtmlInput = document.getElementById('descHtmlInput');
var descPlainInput = document.getElementById('descPlainInput');
var rteWordCount = document.getElementById('rteWordCount');
var rteToolbar   = document.getElementById('rteToolbar');

if (rteEditor) {
  // Load existing content
  var initial = descHtmlInput ? descHtmlInput.value : '';
  if (initial && initial.trim()) {
    // Check if it contains HTML tags
    var hasHtml = /<(p|h[23]|strong|em|ul|ol|li|br)\b/i.test(initial);
    if (hasHtml) {
      rteEditor.innerHTML = initial;
    } else {
      // Plain text — wrap each line in <p>
      rteEditor.innerHTML = initial.split('\n').map(function(line) {
        var safe = line.trim().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        return safe ? '<p>' + safe + '</p>' : '';
      }).filter(Boolean).join('') || '<p>' + initial.replace(/&/g,'&amp;') + '</p>';
    }
  }

  function syncRte() {
    if (descHtmlInput)  descHtmlInput.value  = rteEditor.innerHTML;
    if (descPlainInput) descPlainInput.value = rteEditor.innerText || '';
    if (rteWordCount) {
      var wc = (rteEditor.innerText || '').trim().split(/\s+/).filter(function(w){ return w.length > 0; }).length;
      var label = rteWordCount.getAttribute('data-words') || 'words';
      rteWordCount.textContent = wc + ' ' + label;
      rteWordCount.className = wc >= 150 ? 'rte-count-good' : wc >= 50 ? 'rte-count-warn' : '';
    }
  }

  // Store the words label from the element (set by PHP, not JS string)
  if (rteWordCount) rteWordCount.setAttribute('data-words', rteWordCount.textContent.split(' ').pop());

  rteEditor.addEventListener('input', syncRte);

  // Toolbar buttons — use mousedown to prevent losing editor focus
  if (rteToolbar) {
    rteToolbar.querySelectorAll('[data-cmd]').forEach(function(btn) {
      btn.addEventListener('mousedown', function(e) {
        e.preventDefault(); // CRITICAL: prevents editor losing focus
        var cmd = this.getAttribute('data-cmd');
        var val = this.getAttribute('data-val') || null;
        document.execCommand(cmd, false, val);
        rteEditor.focus();
        syncRte();
        updateToolbarState();
      });
    });
  }

  // Link button
  var rteLinkBtn = document.getElementById('rteLinkBtn');
  if (rteLinkBtn) {
    rteLinkBtn.addEventListener('mousedown', function(e) {
      e.preventDefault();
      var sel = window.getSelection();
      var selectedText = sel ? sel.toString() : '';
      var url = window.prompt('URL:', 'https://');
      if (url && url !== 'https://') {
        if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
        document.execCommand('createLink', false, url);
        rteEditor.focus();
        syncRte();
      }
    });
  }

  // Highlight active format buttons
  function updateToolbarState() {
    if (!rteToolbar) return;
    rteToolbar.querySelectorAll('[data-cmd]').forEach(function(btn) {
      var cmd = btn.getAttribute('data-cmd');
      var val = btn.getAttribute('data-val');
      try {
        if (val && cmd === 'formatBlock') {
          var current = document.queryCommandValue('formatBlock').toLowerCase();
          btn.classList.toggle('rte-active', current === val);
        } else if (cmd) {
          btn.classList.toggle('rte-active', document.queryCommandState(cmd));
        }
      } catch(err) {}
    });
  }

  rteEditor.addEventListener('keyup',   updateToolbarState);
  rteEditor.addEventListener('mouseup', updateToolbarState);

  syncRte(); // initial sync
}


// ── 4. FREE LISTING word counter ─────────────────────────────────────────
var freeDesc = document.getElementById('freeDesc');
var freeWc   = document.getElementById('freeWc');
if (freeDesc && freeWc) {
  function countFreeWords() {
    var wc = freeDesc.value.trim().split(/\s+/).filter(function(w){ return w.length > 0; }).length;
    freeWc.textContent = wc + '/300';
    freeWc.style.color = wc > 300 ? '#e63946' : wc >= 150 ? '#00A878' : '';
  }
  freeDesc.addEventListener('input', countFreeWords);
  countFreeWords();
}


// ── 5. SERVICES — add / remove / serialize ───────────────────────────────
var servicesList = document.getElementById('servicesList');
var addSvcBtn    = document.getElementById('addSvcBtn');
var servicesJson = document.getElementById('servicesJson');

function serializeServices() {
  if (!servicesList || !servicesJson) return;
  var data = [];
  servicesList.querySelectorAll('.svc-item').forEach(function(item) {
    var name = (item.querySelector('.svc-name') || {}).value || '';
    var desc = (item.querySelector('.svc-desc') || {}).value || '';
    if (name.trim()) data.push({ name: name.trim(), description: desc.trim() });
  });
  servicesJson.value = JSON.stringify(data);
}

function attachSvcListeners(item) {
  var rm = item.querySelector('.btn-svc-remove');
  if (rm) rm.addEventListener('click', function() { item.remove(); serializeServices(); });
  var ni = item.querySelector('.svc-name');
  var di = item.querySelector('.svc-desc');
  if (ni) ni.addEventListener('input', serializeServices);
  if (di) di.addEventListener('input', serializeServices);
}

// Attach to PHP-rendered items
if (servicesList) {
  servicesList.querySelectorAll('.svc-item').forEach(attachSvcListeners);
}

if (addSvcBtn && servicesList) {
  // Read placeholders from data attributes — NO JS strings with apostrophes
  var namePh    = addSvcBtn.getAttribute('data-name-ph')    || '';
  var descPh    = addSvcBtn.getAttribute('data-desc-ph')    || '';
  var removeTxt = addSvcBtn.getAttribute('data-remove-txt') || 'Remove';

  addSvcBtn.addEventListener('click', function() {
    var div = document.createElement('div');
    div.className = 'svc-item';
    var nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.className = 'svc-name';
    nameInput.placeholder = namePh;
    var descInput = document.createElement('textarea');
    descInput.className = 'svc-desc';
    descInput.rows = 2;
    descInput.placeholder = descPh;
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn-svc-remove';
    removeBtn.textContent = '✕ ' + removeTxt;
    div.appendChild(nameInput);
    div.appendChild(descInput);
    div.appendChild(removeBtn);
    servicesList.appendChild(div);
    attachSvcListeners(div);
    nameInput.focus();
    serializeServices();
  });
}
serializeServices();


// ── 6. KEYWORDS / TAGS ───────────────────────────────────────────────────
var tagsWrap      = document.getElementById('tagsWrap');
var tagInputField = document.getElementById('tagInputField');
var tagCount      = document.getElementById('tagCount');
var keywordsHidden = document.getElementById('keywordsHidden');
var tags = [];

if (keywordsHidden && keywordsHidden.value.trim()) {
  tags = keywordsHidden.value.split(',').map(function(t) { return t.trim(); }).filter(Boolean);
}

function renderTags() {
  if (!tagsWrap) return;
  tagsWrap.querySelectorAll('.tag-chip').forEach(function(c) { c.remove(); });
  tags.forEach(function(tag, i) {
    var chip = document.createElement('span');
    chip.className = 'tag-chip';
    var txt = document.createTextNode(tag + ' ');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = '×';
    btn.setAttribute('data-i', i);
    btn.addEventListener('click', function() {
      tags.splice(parseInt(this.getAttribute('data-i')), 1);
      renderTags();
      if (keywordsHidden) keywordsHidden.value = tags.join(', ');
    });
    chip.appendChild(txt);
    chip.appendChild(btn);
    if (tagInputField) tagsWrap.insertBefore(chip, tagInputField);
    else tagsWrap.appendChild(chip);
  });
  if (tagCount) tagCount.textContent = tags.length;
  if (tagInputField) tagInputField.style.display = tags.length >= 10 ? 'none' : '';
  if (keywordsHidden) keywordsHidden.value = tags.join(', ');
}

if (tagInputField) {
  tagInputField.addEventListener('keydown', function(e) {
    if ((e.key === 'Enter' || e.key === ',') && this.value.trim() && tags.length < 10) {
      e.preventDefault();
      var val = this.value.trim().replace(/,$/, '');
      if (val && !tags.includes(val)) tags.push(val);
      renderTags();
      this.value = '';
    }
  });
  if (tagsWrap) {
    tagsWrap.addEventListener('click', function() { tagInputField.focus(); });
  }
}
renderTags();


// ── 7. BUSINESS HOURS ────────────────────────────────────────────────────
function serializeHours() {
  var data = {};
  document.querySelectorAll('.hrs-check').forEach(function(cb) {
    var day = cb.getAttribute('data-day');
    var row = cb.closest('.hours-row');
    if (!row) return;
    data[day] = {
      open: cb.checked,
      from: (row.querySelector('.hrs-from') || {}).value || '08:00',
      to:   (row.querySelector('.hrs-to')   || {}).value || '17:00'
    };
  });
  var el = document.getElementById('hoursJson');
  if (el) el.value = JSON.stringify(data);
}

document.querySelectorAll('.hrs-check').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var row   = cb.closest('.hours-row');
    var times = row ? row.querySelector('.hours-times') : null;
    var lbl   = row ? row.querySelector('.hours-closed') : null;
    if (times) { times.style.opacity = cb.checked ? '1' : '.3'; times.style.pointerEvents = cb.checked ? '' : 'none'; }
    if (lbl)   lbl.style.display = cb.checked ? 'none' : '';
    serializeHours();
  });
});
document.querySelectorAll('.hrs-from, .hrs-to').forEach(function(el) {
  el.addEventListener('change', serializeHours);
});
serializeHours();


// ── 8. FAQ suggestion chips ───────────────────────────────────────────────
document.querySelectorAll('.faq-chip').forEach(function(chip) {
  chip.addEventListener('click', function() {
    var q = this.getAttribute('data-q');
    var inputs = document.querySelectorAll('input[name="faq_q[]"]');
    for (var i = 0; i < inputs.length; i++) {
      if (!inputs[i].value.trim()) {
        inputs[i].value = q;
        inputs[i].focus();
        inputs[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
        break;
      }
    }
  });
});


// ── 9. AUTO-SAVE to localStorage ─────────────────────────────────────────
var listingForm  = document.getElementById('listingForm');
var autosaveTxt  = document.getElementById('autosaveTxt');
var autoTimer    = null;
var DRAFT_KEY    = 'biz237_draft_' + (listingForm ? listingForm.getAttribute('data-id') || '' : '');

function saveDraft() {
  try {
    var d = {};
    listingForm.querySelectorAll('input:not([type=file]):not([type=hidden]), textarea, select').forEach(function(el) {
      if (el.name) d[el.name] = el.value;
    });
    localStorage.setItem(DRAFT_KEY, JSON.stringify(d));
    if (autosaveTxt) {
      autosaveTxt.className = 'autosave-txt saved';
      var now = new Date();
      autosaveTxt.textContent = '✓ ' + now.getHours() + ':' + String(now.getMinutes()).padStart(2,'0');
    }
  } catch(e) {}
}

if (listingForm) {
  listingForm.setAttribute('data-id', '<?= (int)$id ?>');
  listingForm.addEventListener('input', function() {
    clearTimeout(autoTimer);
    autoTimer = setTimeout(saveDraft, 2000);
  });
}


// ── 10. FORM SUBMIT — final sync before POST ──────────────────────────────
if (listingForm) {
  listingForm.addEventListener('submit', function() {
    // Sync rich text editor
    if (rteEditor && descHtmlInput) descHtmlInput.value = rteEditor.innerHTML;
    if (rteEditor && descPlainInput) descPlainInput.value = rteEditor.innerText || '';
    // Sync services
    serializeServices();
    // Sync hours
    serializeHours();
    // Sync keywords
    if (keywordsHidden) keywordsHidden.value = tags.join(', ');
  });
}

// ── 11. BOOKING SETTINGS ─────────────────────────────────────────────────
var bookingToggle   = document.getElementById('bookingToggle');
var bookingSettings = document.getElementById('bookingSettings');
var bkServicesWrap  = document.getElementById('bkServicesWrap');
var bkServiceInput  = document.getElementById('bkServiceInput');
var bkServicesJson  = document.getElementById('bkServicesJson');
var bkTags = [];

try { bkTags = JSON.parse((bkServicesJson || {}).value || '[]') || []; } catch(e) {}

function renderBkTags() {
  if (!bkServicesWrap) return;
  bkServicesWrap.querySelectorAll('.tag-chip').forEach(function(c) { c.remove(); });
  bkTags.forEach(function(tag, i) {
    var chip = document.createElement('span');
    chip.className = 'tag-chip';
    var txt = document.createTextNode(tag + ' ');
    var btn = document.createElement('button');
    btn.type = 'button'; btn.textContent = '×';
    btn.setAttribute('data-i', i);
    btn.addEventListener('click', function() {
      bkTags.splice(parseInt(this.getAttribute('data-i')), 1);
      renderBkTags();
      if (bkServicesJson) bkServicesJson.value = JSON.stringify(bkTags);
    });
    chip.appendChild(txt); chip.appendChild(btn);
    if (bkServiceInput) bkServicesWrap.insertBefore(chip, bkServiceInput);
    else bkServicesWrap.appendChild(chip);
  });
}

if (bkServiceInput) {
  bkServiceInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && this.value.trim()) {
      e.preventDefault();
      var val = this.value.trim();
      if (!bkTags.includes(val)) bkTags.push(val);
      renderBkTags();
      if (bkServicesJson) bkServicesJson.value = JSON.stringify(bkTags);
      this.value = '';
    }
  });
}

if (bookingToggle && bookingSettings) {
  bookingToggle.addEventListener('change', function() {
    bookingSettings.style.opacity       = this.checked ? '1' : '.4';
    bookingSettings.style.pointerEvents = this.checked ? '' : 'none';
  });
}

renderBkTags();


})(); // end IIFE
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
