<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$cats     = db()->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();
$locs     = db()->query("SELECT * FROM locations ORDER BY sort_order")->fetchAll();
$packages = db()->query("SELECT * FROM listing_packages WHERE active=1 ORDER BY sort_order")->fetchAll();

$errors = [];
$values = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $values = [
        'title'       => trim($_POST['title'] ?? ''),
        'category_id' => (int)($_POST['category_id'] ?? 0),
        'location_id' => (int)($_POST['location_id'] ?? 0),
        'description' => trim($_POST['description'] ?? ''),
        'address'     => trim($_POST['address'] ?? ''),
        'phone'       => trim($_POST['phone'] ?? ''),
        'email'       => trim($_POST['email'] ?? ''),
        'website'     => trim($_POST['website'] ?? ''),
        'whatsapp'    => trim($_POST['whatsapp'] ?? ''),
        'facebook'    => trim($_POST['facebook'] ?? ''),
        'tiktok'      => trim($_POST['tiktok'] ?? ''),
        'instagram'   => trim($_POST['instagram'] ?? ''),
    ];

    if (!$values['title'])       $errors[] = t('Business name is required.','Le nom est requis.');
    if (!$values['category_id']) $errors[] = t('Please select a category.','Veuillez choisir une catégorie.');
    if (!$values['location_id']) $errors[] = t('Please select a city.','Veuillez choisir une ville.');
    if (!$values['description']) $errors[] = t('Description is required.','La description est requise.');

    // Handle logo upload
    $logoPath = null;
    if (!empty($_FILES['logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
            $errors[] = t('Logo must be an image (JPG, PNG, WEBP).','Le logo doit être une image.');
        } elseif ($_FILES['logo']['size'] > MAX_UPLOAD) {
            $errors[] = t('Logo file too large (max 5MB).','Logo trop volumineux (max 5Mo).');
        } else {
            $logoPath = uniqid('logo_') . '.' . $ext;
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], UPLOAD_DIR . $logoPath)) {
                $errors[] = t('Failed to upload logo.','Échec du téléchargement du logo.');
                $logoPath = null;
            }
        }
    }

    if (!$errors) {

        // Handle paid package + proof
        $packageId = (int)($_POST['package_id'] ?? 1);
        $proofPath = null;
        if ($packageId > 1 && !empty($_FILES['payment_proof']['name'])) {
            $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
                $proofPath = uniqid('pay_') . '.' . $ext;
                if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], UPLOAD_DIR . $proofPath)) {
                    $proofPath = null;
                }
            }
        }

        $baseSlug = slug($values['title']);
        $finalSlug = $baseSlug;
        $i = 1;
        while (db()->prepare("SELECT id FROM listings WHERE slug = ?")->execute([$finalSlug]) &&
               db()->query("SELECT id FROM listings WHERE slug = '$finalSlug'")->fetchColumn()) {
            $finalSlug = $baseSlug . '-' . $i++;
        }

        $st = db()->prepare("
            INSERT INTO listings
            (user_id, category_id, location_id, title, slug, description,
             address, phone, email, website, whatsapp, facebook, tiktok, instagram, logo, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending')
        ");
        $st->execute([
            $_SESSION['user_id'],
            $values['category_id'], $values['location_id'],
            $values['title'], $finalSlug, $values['description'],
            $values['address'], $values['phone'], $values['email'],
            $values['website'], $values['whatsapp'], $values['facebook'],
            $values['tiktok'], $values['instagram'],
            $logoPath
        ]);

        $newListingId = db()->lastInsertId();

        // Save FAQs (paid packages only, max 4)
        if ($packageId > 1 && !empty($_POST['faq_question'])) {
            $qs = $_POST['faq_question'];
            $as = $_POST['faq_answer'];
            $faqCount = 0;
            foreach ($qs as $i => $q) {
                $q = trim($q);
                $a = trim($as[$i] ?? '');
                if ($q && $a && $faqCount < 4) {
                    db()->prepare("INSERT INTO listing_faqs (listing_id, question, answer, sort_order) VALUES (?,?,?,?)")
                         ->execute([$newListingId, $q, $a, $faqCount]);
                    $faqCount++;
                }
            }
        }

        // Handle gallery images
        if (!empty($_FILES['gallery']['name'][0])) {
            $galleryFiles = $_FILES['gallery'];
            $count = min(6, count($galleryFiles['name']));
            for ($i = 0; $i < $count; $i++) {
                if ($galleryFiles['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($galleryFiles['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;
                if ($galleryFiles['size'][$i] > MAX_UPLOAD) continue;
                $imgPath = uniqid('gallery_') . '.' . $ext;
                if (move_uploaded_file($galleryFiles['tmp_name'][$i], UPLOAD_DIR . $imgPath)) {
                    db()->prepare("INSERT INTO listing_images (listing_id, path, sort_order) VALUES (?,?,?)")
                         ->execute([$newListingId, $imgPath, $i]);
                }
            }
        }

        // Create payment record if paid package selected
        if ($packageId > 1) {
            $pkg = db()->prepare("SELECT * FROM listing_packages WHERE id=?");
            $pkg->execute([$packageId]);
            $pkg = $pkg->fetch();
            if ($pkg) {
                $ref = generateRef('FTR');
                db()->prepare("INSERT INTO listing_payments (listing_id,user_id,package_id,amount_xaf,ref,proof,status)
                               VALUES (?,?,?,?,?,?,'pending')")
                     ->execute([$newListingId,
                                $_SESSION['user_id'], $packageId, $pkg['price_xaf'], $ref, $proofPath]);
                // Notify admin
                sendMail(SITE_EMAIL, 'New Listing + Featured Payment — '.$ref,
                    '<p>New listing submitted with featured payment.<br>Ref: '.$ref.'</p>
                     <a href="'.SITE_URL.'/admin/orders.php" style="background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;">Review in Admin</a>');
            }
        }

        // ── Automation: tag as free-listing (upsell sequences fire on approval) ──
        if (file_exists(__DIR__ . '/automation/helper.php')) {
            require_once __DIR__ . '/automation/helper.php';
            $uid = (int)$_SESSION['user_id'];
            addUserTag($uid, 'free-listing');
            // Upsell sequences are enrolled when admin approves listing in admin/index.php
        }
        // ─────────────────────────────────────────────────────────────────────────

        // ── Referral attribution ──────────────────────────────────────────────────
        // If this visitor arrived via a referral link (/r/code), credit the
        // referring agent or creator with this conversion automatically.
        if (file_exists(__DIR__ . '/includes/referral-helpers.php')) {
            require_once __DIR__ . '/includes/referral-helpers.php';
            $convType = ($packageId > 1) ? 'featured_listing' : 'free_listing';
            attributeReferralConversion((int)$newListingId, (int)$_SESSION['user_id'], $convType);
        }
        // ─────────────────────────────────────────────────────────────────────────

        flash('success', t('Listing submitted! We will review and publish it within 24 hours.',
                           'Annonce soumise ! Nous la réviserons et la publierons dans les 24 heures.'));
        redirect(SITE_URL . '/dashboard');
    }
}

$pageTitle = t('Add Your Business — 237Biz', 'Ajouter votre Entreprise — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <span><?= t('Add Listing','Ajouter une annonce') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(2rem,4vw,3rem);">
      + <?= t('List Your Business Free','Lister votre Entreprise Gratuitement') ?>
    </h1>
    <p style="color:var(--muted);font-size:0.9rem;margin-top:0.5rem;">
      <?= t('Added within 24 hours · Free forever · No credit card needed',
            'Ajouté dans les 24h · Gratuit à vie · Aucune carte bancaire') ?>
    </p>
  </div>
</div>

<section class="page-section">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 320px;gap:3rem;align-items:start;">

      <div>
        <?php foreach ($errors as $e): ?>
          <div class="flash flash-error" style="margin-bottom:0.5rem;margin-top:0;"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">

          <div class="form-card">
            <h3 style="font-family:'Fraunces',serif;margin-bottom:1.5rem;font-size:1.2rem;"><?= t('Business Details','Détails de l\'entreprise') ?></h3>

            <div class="form-group">
              <label><?= t('Business Name *','Nom de l\'entreprise *') ?></label>
              <input type="text" name="title" value="<?= e($values['title'] ?? '') ?>" required placeholder="<?= t('e.g. Sabi Pot Restaurant','ex. Restaurant Sabi Pot') ?>">

            <div class="form-group">
              <label><?= t('Gallery Images (up to 6)','Images de la Galerie (jusqu\'à 6)') ?></label>
              <input type="file" name="gallery[]" accept="image/*" multiple style="color:var(--muted);padding:0.5rem 0;">
              <small style="color:var(--muted-2);font-size:0.75rem;"><?= t('JPG, PNG or WEBP · Max 5MB each · Up to 6 photos','JPG, PNG ou WEBP · Max 5Mo chacune · Jusqu\'à 6 photos') ?></small>
            </div>
          </div>

            <div class="form-row">
              <div class="form-group">
                <label><?= t('Category *','Catégorie *') ?></label>
                <select name="category_id" required>
                  <option value=""><?= t('Select category','Choisir catégorie') ?></option>
                  <?php foreach ($cats as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= ($values['category_id'] ?? 0) == $cat['id'] ? 'selected' : '' ?>>
                      <?= $cat['icon'] ?> <?= e(lang()==='fr' ? $cat['name_fr'] : $cat['name_en']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label><?= t('City *','Ville *') ?></label>
                <select name="location_id" required>
                  <option value=""><?= t('Select city','Choisir ville') ?></option>
                  <?php foreach ($locs as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= ($values['location_id'] ?? 0) == $loc['id'] ? 'selected' : '' ?>>
                      <?= e($loc['name_en']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-group">
              <label><?= t('Description *','Description *') ?></label>
              <textarea name="description" rows="5" required placeholder="<?= t('Describe your business, products and services...','Décrivez votre entreprise, produits et services...') ?>"><?= e($values['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
              <label><?= t('Street Address','Adresse') ?></label>
              <input type="text" name="address" value="<?= e($values['address'] ?? '') ?>" placeholder="<?= t('Street, district...','Rue, quartier...') ?>">
            </div>

            <div class="form-group">
              <label><?= t('Business Logo','Logo') ?></label>
              <input type="file" name="logo" accept="image/*" style="color:var(--muted);padding:0.5rem 0;">
              <small style="color:var(--muted-2);font-size:0.75rem;"><?= t('JPG, PNG or WEBP · Max 5MB','JPG, PNG ou WEBP · Max 5Mo') ?></small>
            </div>
          </div>

          <div class="form-card" style="margin-top:1.25rem;">
            <h3 style="font-family:'Fraunces',serif;margin-bottom:1.5rem;font-size:1.2rem;"><?= t('Contact Information','Informations de contact') ?></h3>

            <div class="form-row">
              <div class="form-group">
                <label><?= t('Phone','Téléphone') ?></label>
                <input type="tel" name="phone" value="<?= e($values['phone'] ?? '') ?>" placeholder="+237 6XX XXX XXX">
              </div>
              <div class="form-group">
                <label>WhatsApp</label>
                <input type="tel" name="whatsapp" value="<?= e($values['whatsapp'] ?? '') ?>" placeholder="+237 6XX XXX XXX">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label><?= t('Email','Email') ?></label>
                <input type="email" name="email" value="<?= e($values['email'] ?? '') ?>" placeholder="info@business.com">
              </div>
              <div class="form-group">
                <label><?= t('Website','Site web') ?></label>
                <input type="url" name="website" value="<?= e($values['website'] ?? '') ?>" placeholder="https://...">
              </div>
            </div>

            <div class="form-group">
              <label>Facebook</label>
              <input type="url" name="facebook" value="<?= e($values['facebook'] ?? '') ?>" placeholder="https://facebook.com/yourbusiness">
            </div>

            <div class="form-row" id="social-extra-fields" style="opacity:0.4;pointer-events:none;transition:opacity 0.2s;">
              <div class="form-group">
                <label>TikTok <span style="color:var(--yellow);font-size:0.7rem;">⭐ <?= t('Featured only','Vedette uniquement') ?></span></label>
                <input type="url" name="tiktok" value="<?= e($values['tiktok'] ?? '') ?>" placeholder="https://tiktok.com/@yourbusiness" disabled>
              </div>
              <div class="form-group">
                <label>Instagram <span style="color:var(--yellow);font-size:0.7rem;">⭐ <?= t('Featured only','Vedette uniquement') ?></span></label>
                <input type="url" name="instagram" value="<?= e($values['instagram'] ?? '') ?>" placeholder="https://instagram.com/yourbusiness" disabled>
              </div>
            </div>
          </div>

          <!-- FAQ BUILDER — featured packages only -->
          <div class="form-card" id="faq-builder-section" style="margin-top:1.25rem;border-color:rgba(245,200,66,0.2);background:rgba(245,200,66,0.02);opacity:0.4;pointer-events:none;transition:opacity 0.2s;">
            <h3 style="font-family:'Fraunces',serif;margin-bottom:0.35rem;font-size:1.1rem;">❓ <?= t('FAQ','FAQ') ?> <span style="color:var(--yellow);font-size:0.7rem;">⭐ <?= t('Featured only','Vedette uniquement') ?></span></h3>
            <p style="font-size:0.82rem;color:var(--muted-2);margin-bottom:1.25rem;"><?= t('Answer up to 4 common customer questions directly on your listing.','Répondez à 4 questions fréquentes directement sur votre annonce.') ?></p>

            <div id="faq-rows">
              <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="faq-row" style="margin-bottom:1rem;padding-bottom:1rem;<?= $i < 3 ? 'border-bottom:1px solid var(--border);' : '' ?>">
                  <div class="form-group">
                    <label><?= t('Question','Question') ?> <?= $i + 1 ?></label>
                    <input type="text" name="faq_question[]" maxlength="200" placeholder="<?= t('e.g. Do you deliver?','ex. Livrez-vous ?') ?>" disabled>
                  </div>
                  <div class="form-group">
                    <label><?= t('Answer','Réponse') ?></label>
                    <textarea name="faq_answer[]" rows="2" placeholder="<?= t('Your answer...','Votre réponse...') ?>" disabled></textarea>
                  </div>
                </div>
              <?php endfor; ?>
            </div>
          </div>

          <div class="form-card" style="margin-top:1.25rem;border-color:rgba(245,200,66,0.2);background:rgba(245,200,66,0.02);">
            <h3 style="font-family:'Fraunces',serif;margin-bottom:0.35rem;font-size:1.1rem;">⭐ <?= t('Listing Type','Type d\'Annonce') ?></h3>
            <p style="font-size:0.82rem;color:var(--muted-2);margin-bottom:1.25rem;"><?= t('Start free or get featured placement from day one.','Commencez gratuitement ou obtenez un placement vedette dès le premier jour.') ?>
              <a href="<?= SITE_URL ?>/business-listing" target="_blank" style="color:var(--yellow);"><?= t('Compare features →','Comparer les fonctionnalités →') ?></a>
            </p>
            <div style="display:flex;flex-direction:column;gap:0.75rem;">
              <?php foreach ($packages as $pkg): ?>
                <label style="cursor:pointer;display:block;">
                  <input type="radio" name="package_id" value="<?= $pkg['id'] ?>"
                         <?= $pkg['price_xaf']==0 ? 'checked' : '' ?>
                         onchange="togglePaymentSection()"
                         style="margin-right:0.5rem;">
                  <span style="font-weight:500;color:var(--white);"><?= e(lang()==='fr'?$pkg['name_fr']:$pkg['name_en']) ?></span>
                  <?php if ($pkg['price_xaf'] > 0): ?>
                    <span style="margin-left:0.5rem;font-family:'Fraunces',serif;font-weight:700;color:var(--yellow);"><?= number_format($pkg['price_xaf']) ?> XAF</span>
                    <span style="font-size:0.75rem;color:var(--muted-2);"> / <?= $pkg['duration_days'] ?> <?= t('days','jours') ?></span>
                    <?php if ($pkg['is_featured']): ?>
                      <span class="badge badge-approved" style="margin-left:0.5rem;">⭐ <?= t('Featured + Verified','Vedette + Vérifiée') ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span style="margin-left:0.5rem;font-size:0.82rem;color:var(--green);"><?= t('Free forever','Gratuit à vie') ?></span>
                  <?php endif; ?>
                </label>
              <?php endforeach; ?>
            </div>

            <!-- Payment section — shown only when paid package selected -->
            <div id="payment-section" style="display:none;margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--border);">
              <h4 style="font-family:'Fraunces',serif;font-size:0.95rem;margin-bottom:1rem;">💳 <?= t('Payment','Paiement') ?></h4>
              <div style="background:rgba(245,200,66,0.06);border:1px solid rgba(245,200,66,0.15);border-radius:8px;padding:1rem;margin-bottom:1rem;font-size:0.83rem;color:var(--muted);">
                <?= t('Send payment via <strong style="color:var(--white)">MTN MoMo or Orange Money</strong>, then upload your screenshot below.','Envoyez le paiement via <strong style="color:var(--white)">MTN MoMo ou Orange Money</strong>, puis téléchargez votre capture ci-dessous.') ?>
                <div style="margin-top:0.75rem;background:var(--card);border-radius:6px;padding:0.75rem;">
                  <div>📱 <strong>MTN MoMo:</strong> +237 XXX XXX XXX</div>
                  <div style="margin-top:0.25rem;">📱 <strong>Orange Money:</strong> +237 XXX XXX XXX</div>
                </div>
              </div>

              <!-- Promo code -->
              <div class="form-group">
                <label><?= t('Promo Code (optional)','Code promo (optionnel)') ?></label>
                <div style="display:flex;gap:0.5rem;">
                  <input type="text" id="promo-code" placeholder="e.g. TAHIRIH10" style="flex:1;text-transform:uppercase;">
                  <button type="button" id="promo-apply" class="btn btn-outline btn-sm" style="white-space:nowrap;"><?= t('Apply','Appliquer') ?></button>
                </div>
                <div id="promo-message" style="display:none;font-size:0.78rem;margin-top:0.4rem;"></div>
                <input type="hidden" name="promo_code" id="applied-promo-code">
                <input type="hidden" id="hidden-amount" value="0">
                <input type="hidden" id="promo-applies-to" value="featured">
                <input type="hidden" name="final_amount" id="final-amount">
              </div>

              <div class="form-group">
                <label><?= t('Payment Screenshot *','Capture d\'écran du paiement *') ?></label>
                <input type="file" name="payment_proof" accept="image/*,.pdf" id="payment-proof-input" style="color:var(--muted);padding:0.5rem 0;">
                <small style="color:var(--muted-2);font-size:0.75rem;"><?= t('JPG, PNG or PDF · Max 5MB','JPG, PNG ou PDF · Max 5Mo') ?></small>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary" style="margin-top:1.5rem;font-size:1rem;padding:1rem 2.5rem;">
            <?= t('Submit My Business →','Soumettre mon Entreprise →') ?>
          </button>
          <p style="color:var(--muted-2);font-size:0.78rem;margin-top:0.75rem;">
            <?= t('Your listing will be reviewed and published within 24 hours.',
                  'Votre annonce sera examinée et publiée dans les 24 heures.') ?>
          </p>
        </form>
      </div>

      <!-- SIDEBAR PERKS -->
      <aside>
        <div class="listing-widget">
          <h4><?= t('Why list on 237Biz?','Pourquoi lister sur 237Biz ?') ?></h4>
          <?php
          $perks = [
              ['✓', t('100% free — forever','100% gratuit — à vie')],
              ['✓', t('Listed within 24 hours','Listé dans les 24 heures')],
              ['✓', t('Visible to locals & diaspora','Visible locaux et diaspora')],
              ['✓', t('Edit your listing anytime','Modifiez votre annonce à tout moment')],
              ['✓', t('Bilingual EN & FR','Bilingue EN et FR')],
              ['✓', t('Upgrade to featured anytime','Passez à la vedette à tout moment')],
          ];
          foreach ($perks as [$icon, $text]): ?>
            <div style="display:flex;gap:0.6rem;align-items:flex-start;padding:0.5rem 0;border-bottom:1px solid var(--border);font-size:0.83rem;color:var(--muted);">
              <span style="color:var(--green);flex-shrink:0;"><?= $icon ?></span>
              <?= e($text) ?>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="listing-widget" style="background:rgba(0,168,120,0.05);border-color:rgba(0,168,120,0.2);">
          <p style="font-size:0.83rem;color:var(--muted);text-align:center;line-height:1.6;">
            🎁 <?= t('Want your listing to stand out? Upgrade to a <strong style="color:var(--yellow)">Featured listing</strong> after submission.',
                      'Vous voulez vous démarquer ? Passez à une <strong style="color:var(--yellow)">annonce vedette</strong> après soumission.') ?>
          </p>
        </div>
      </aside>
    </div>
  </div>
</section>

<script>
function togglePaymentSection() {
  const selected = document.querySelector('input[name="package_id"]:checked');
  const sec = document.getElementById('payment-section');
  const proofInput = document.getElementById('payment-proof-input');
  const hiddenAmt = document.getElementById('hidden-amount');
  const socialFields = document.getElementById('social-extra-fields');
  const faqSection = document.getElementById('faq-builder-section');
  if (!selected) return;

  const radios = document.querySelectorAll('input[name="package_id"]');
  let price = 0;
  radios.forEach(r => {
    if (r.checked && r.nextElementSibling) {
      const match = r.parentElement.textContent.match(/(\d[\d,]+)\s*XAF/);
      if (match) price = parseInt(match[1].replace(',',''));
    }
  });

  const isPaid = price > 0;

  if (isPaid) {
    sec.style.display = 'block';
    hiddenAmt.value = price;
    if (proofInput) proofInput.required = true;
  } else {
    sec.style.display = 'none';
    if (proofInput) proofInput.required = false;
  }

  [socialFields, faqSection].forEach(section => {
    if (!section) return;
    section.style.opacity = isPaid ? '1' : '0.4';
    section.style.pointerEvents = isPaid ? 'auto' : 'none';
    section.querySelectorAll('input, textarea').forEach(el => { el.disabled = !isPaid; });
  });
}
togglePaymentSection();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
