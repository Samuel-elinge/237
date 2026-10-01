<?php
require_once __DIR__ . '/includes/config.php';

$services = db()->query("SELECT * FROM services WHERE active=1 ORDER BY sort_order")->fetchAll();
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!empty($_POST['website_url'])) redirect(SITE_URL . '/services'); // honeypot

    requireLogin();
    $u = currentUser();

    $serviceId    = (int)($_POST['service_id'] ?? 0);
    $type         = in_array($_POST['type'] ?? '', ['setup','monthly','both']) ? $_POST['type'] : 'both';
    $contactName  = trim($_POST['contact_name'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? $u['email']);
    $bizName      = trim($_POST['business_name'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    $svc = null;
    foreach ($services as $s) { if ($s['id'] == $serviceId) { $svc = $s; break; } }

    if (!$svc) $errors[] = t('Please select a service.','Veuillez choisir un service.');
    if (!$contactName) $errors[] = t('Your name is required.','Votre nom est requis.');
    if (!$contactPhone && !$contactEmail) $errors[] = t('Phone or email required.','Téléphone ou email requis.');

    // Proof upload
    $proofPath = null;
    if (!empty($_FILES['proof']['name'])) {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
            $proofPath = uniqid('svc_proof_') . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], UPLOAD_DIR . $proofPath);
        }
    }

    if (!$errors) {
        $amount = 0;
        if ($type === 'setup')   $amount = $svc['setup_xaf'];
        if ($type === 'monthly') $amount = $svc['monthly_xaf'];
        if ($type === 'both')    $amount = $svc['setup_xaf'] + $svc['monthly_xaf'];

        $ref = generateRef('SVC');
        $st = db()->prepare("INSERT INTO service_orders
            (user_id,service_id,ref,amount_xaf,type,proof,notes,contact_name,contact_phone,contact_email,business_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$u['id'], $serviceId, $ref, $amount, $type, $proofPath, $notes,
                      $contactName, $contactPhone, $contactEmail, $bizName]);

        // Admin notification
        sendMail(SITE_EMAIL,
            'New Service Order — ' . $ref,
            '<p>New service order from <strong>'.e($contactName).'</strong></p>
             <table style="color:rgba(255,255,255,0.7);font-size:0.875rem;border-collapse:collapse;width:100%;">
               <tr><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">Service</td><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);"><strong>'.e($svc['name_en']).'</strong></td></tr>
               <tr><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">Type</td><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">'.ucfirst($type).'</td></tr>
               <tr><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">Amount</td><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">'.number_format($amount).' XAF</td></tr>
               <tr><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">Business</td><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">'.e($bizName).'</td></tr>
               <tr><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">Phone</td><td style="padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">'.e($contactPhone).'</td></tr>
               <tr><td style="padding:0.4rem 0;">Email</td><td style="padding:0.4rem 0;">'.e($contactEmail).'</td></tr>
             </table>
             <p style="color:rgba(255,255,255,0.7);margin-top:1rem;font-size:0.83rem;"><strong>Notes:</strong> '.nl2br(e($notes)).'</p>
             <p style="color:rgba(255,255,255,0.7);margin-top:0.5rem;font-size:0.83rem;"><strong>Ref:</strong> '.$ref.'</p>
             <a href="'.SITE_URL.'/admin/orders.php" style="background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">Review Order in Admin</a>'
        );

        // Customer notification
        sendMail($contactEmail,
            t('Service Order Received — 237Biz','Commande de Service Reçue — 237Biz'),
            '<h2 style="color:#fff;font-family:Georgia,serif;">'.t('Order Received!','Commande Reçue !').'</h2>
             <p style="color:rgba(255,255,255,0.7);">'.t('Hi','Bonjour').' '.e($contactName).',</p>
             <p style="color:rgba(255,255,255,0.7);">'.t('Thank you for your order. Our team will review your payment and contact you within 24 hours to get started.','Merci pour votre commande. Notre équipe examinera votre paiement et vous contactera dans les 24 heures pour commencer.').'</p>
             <div style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:1rem;margin:1rem 0;">
               <div style="color:var(--white);font-size:0.875rem;"><strong>'.t('Reference','Référence').':</strong> <span style="color:#F5C842;">'.$ref.'</span></div>
               <div style="color:rgba(255,255,255,0.6);font-size:0.875rem;margin-top:0.25rem;"><strong>'.t('Service','Service').':</strong> '.e(lang()==='fr'?$svc['name_fr']:$svc['name_en']).'</div>
             </div>
             <p style="color:rgba(255,255,255,0.5);font-size:0.78rem;">'.t('Questions? Email us at','Des questions ? Écrivez-nous à').' <a href="mailto:'.SITE_EMAIL.'" style="color:#00A878;">'.SITE_EMAIL.'</a></p>'
        );

        flash('success', t('Order submitted! Reference: '.$ref.'. We\'ll contact you within 24 hours.',
                           'Commande soumise ! Référence : '.$ref.'. Nous vous contacterons dans les 24 heures.'));
        redirect(SITE_URL . '/dashboard');
    }
}

$selectedService = (int)($_GET['service'] ?? 0);
$pageTitle = t('Business Services — 237Biz','Services Entreprises — 237Biz');
$pageDesc  = t('Digital services for Cameroonian businesses — website, email, chatbot, invoicing and more. XAF pricing.','Services numériques pour les entreprises camerounaises — site web, email, chatbot, facturation et plus. Prix en XAF.');

require_once __DIR__ . '/includes/header.php';
?>

<!-- HERO -->
<section style="padding:8rem 5vw 4rem;background:linear-gradient(180deg,rgba(0,168,120,0.05) 0%,transparent 60%);border-bottom:1px solid var(--border);">
  <div class="container">
    <span class="section-label">🇨🇲 <?= t('Digital Services for Cameroonian Businesses','Services Numériques pour les Entreprises Camerounaises') ?></span>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(2rem,5vw,3.5rem);line-height:1.05;letter-spacing:-0.025em;margin-bottom:1rem;">
      <?= t('Everything your business needs to','Tout ce dont votre entreprise a besoin pour') ?>
      <em style="font-style:italic;color:var(--yellow);"><?= t('go digital','être numérique') ?></em>
    </h1>
    <p style="font-size:1rem;color:var(--muted);max-width:560px;line-height:1.8;font-weight:300;">
      <?= t('From getting online to automating your customer support — complete digital toolkit for Cameroonian businesses, priced in XAF.',
            'De la mise en ligne à l\'automatisation du support client — boîte à outils numérique complète pour les entreprises camerounaises, en XAF.') ?>
    </p>
    <div style="display:flex;gap:2.5rem;flex-wrap:wrap;margin-top:2rem;">
      <div><strong style="font-family:'Fraunces',serif;font-size:1.6rem;color:var(--yellow);">7</strong><span style="display:block;font-size:0.75rem;color:var(--muted);"><?= t('Services','Services') ?></span></div>
      <div><strong style="font-family:'Fraunces',serif;font-size:1.6rem;color:var(--yellow);">XAF</strong><span style="display:block;font-size:0.75rem;color:var(--muted);"><?= t('Local pricing','Prix locaux') ?></span></div>
      <div><strong style="font-family:'Fraunces',serif;font-size:1.6rem;color:var(--yellow);">48h</strong><span style="display:block;font-size:0.75rem;color:var(--muted);"><?= t('Avg setup','Mise en place moy.') ?></span></div>
      <div><strong style="font-family:'Fraunces',serif;font-size:1.6rem;color:var(--yellow);">EN+FR</strong><span style="display:block;font-size:0.75rem;color:var(--muted);"><?= t('Bilingual','Bilingue') ?></span></div>
    </div>
  </div>
</section>

<!-- SERVICES LIST -->
<section class="page-section">
  <div class="container">
    <div style="display:flex;flex-direction:column;gap:1.5rem;">
      <?php foreach ($services as $svc): $isOnlinePresence = ($svc['name_en'] === 'Online Presence'); ?>
        <div id="service-<?= $svc['id'] ?>"
             style="background:var(--card);border:1px solid <?= $isOnlinePresence ? 'rgba(245,200,66,0.25)' : 'var(--border)' ?>;border-radius:16px;padding:2rem;display:grid;grid-template-columns:60px 1fr auto;gap:2rem;align-items:center;transition:border-color 0.2s;position:relative;<?= $isOnlinePresence ? 'cursor:pointer;' : '' ?>"
             <?php if ($isOnlinePresence): ?>onclick="window.location.href='<?= SITE_URL ?>/online-presence'"<?php endif; ?>
             onmouseover="this.style.borderColor='<?= $isOnlinePresence ? 'rgba(245,200,66,0.5)' : 'rgba(0,168,120,0.25)' ?>'"
             onmouseout="this.style.borderColor='<?= $isOnlinePresence ? 'rgba(245,200,66,0.25)' : 'var(--border)' ?>'">
          <?php if ($isOnlinePresence): ?>
            <span style="position:absolute;top:-10px;left:1.5rem;background:var(--yellow);color:var(--dark);font-size:0.65rem;font-weight:600;padding:0.2rem 0.75rem;border-radius:20px;text-transform:uppercase;letter-spacing:0.04em;">
              ⭐ <?= t('Most Popular','Le Plus Populaire') ?>
            </span>
          <?php endif; ?>
          <div style="width:60px;height:60px;border-radius:14px;background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.2);display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0;">
            <?= $svc['icon'] ?>
          </div>
          <div>
            <h3 style="font-family:'Fraunces',serif;font-weight:700;font-size:1.15rem;margin-bottom:0.25rem;">
              <?= e(lang()==='fr' ? $svc['name_fr'] : $svc['name_en']) ?>
            </h3>
            <p style="font-size:0.875rem;color:var(--muted);line-height:1.65;max-width:560px;margin-top:0.25rem;">
              <?= e(lang()==='fr' ? $svc['desc_fr'] : $svc['desc_en']) ?>
            </p>
          </div>
          <div style="text-align:right;flex-shrink:0;min-width:180px;">
            <?php if ($svc['setup_xaf'] > 0): ?>
              <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted-2);margin-bottom:0.2rem;"><?= t('One-off setup','Frais uniques') ?></div>
              <div style="font-family:'Fraunces',serif;font-weight:900;font-size:1.3rem;"><?= number_format($svc['setup_xaf']) ?></div>
              <div style="font-size:0.7rem;color:var(--muted-2);margin-bottom:0.5rem;">XAF</div>
            <?php else: ?>
              <div style="font-family:'Fraunces',serif;font-weight:900;font-size:1.1rem;color:var(--green);margin-bottom:0.5rem;"><?= t('Free setup','Configuration gratuite') ?></div>
            <?php endif; ?>
            <div style="width:100%;height:1px;background:var(--border);margin:0.4rem 0;"></div>
            <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted-2);margin-bottom:0.2rem;"><?= t('Then monthly','Puis mensuel') ?></div>
            <div style="font-family:'Fraunces',serif;font-weight:700;font-size:1rem;color:var(--green);"><?= number_format($svc['monthly_xaf']) ?></div>
            <div style="font-size:0.7rem;color:var(--muted-2);margin-bottom:0.85rem;">XAF / <?= t('month','mois') ?></div>
            <?php if ($isOnlinePresence): ?>
              <a href="<?= SITE_URL ?>/online-presence" onclick="event.stopPropagation()"
                 style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.78rem;color:var(--yellow);font-weight:500;">
                <?= t('Learn More →','En Savoir Plus →') ?>
              </a>
            <?php else: ?>
              <a href="#order-form" onclick="selectService(<?= $svc['id'] ?>)"
                 style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.78rem;color:var(--green);font-weight:500;">
                <?= t('Order →','Commander →') ?>
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ORDER FORM -->
<section class="page-section" id="order-form" style="border-top:1px solid var(--border);background:rgba(255,255,255,0.01);">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 360px;gap:4rem;align-items:start;">

      <div>
        <span class="section-label"><?= t('Place Your Order','Passer votre Commande') ?></span>
        <h2 class="section-title"><?= t('Get started today','Commencez aujourd\'hui') ?></h2>
        <p class="section-sub" style="margin-bottom:2rem;">
          <?= t('Offline payments only. Send your payment proof and our team will set everything up within 48 hours.',
                'Paiements hors ligne uniquement. Envoyez votre preuve de paiement et notre équipe configurera tout dans les 48 heures.') ?>
        </p>

        <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

        <?php if (!isLoggedIn()): ?>
          <div class="listing-widget" style="background:rgba(0,168,120,0.05);border-color:rgba(0,168,120,0.2);margin-bottom:1.5rem;text-align:center;">
            <p style="color:var(--muted);margin-bottom:1rem;">
              <?= t('You need an account to order services.','Vous avez besoin d\'un compte pour commander des services.') ?>
            </p>
            <a href="<?= SITE_URL ?>/login?mode=register&next=<?= urlencode(SITE_URL.'/services#order-form') ?>" class="btn btn-primary">
              <?= t('Create Free Account →','Créer un Compte Gratuit →') ?>
            </a>
            <p style="margin-top:0.75rem;font-size:0.83rem;color:var(--muted-2);">
              <?= t('Already have an account?','Vous avez déjà un compte ?') ?>
              <a href="<?= SITE_URL ?>/login?next=<?= urlencode(SITE_URL.'/services#order-form') ?>"><?= t('Sign in','Connexion') ?></a>
            </p>
          </div>
        <?php else: ?>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <div style="position:absolute;left:-9999px;opacity:0;height:0;" aria-hidden="true">
              <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
            </div>

            <div class="form-card">
              <div class="form-group">
                <label><?= t('Select Service *','Sélectionner le Service *') ?></label>
                <select name="service_id" id="service-select" required>
                  <option value=""><?= t('-- Choose a service --','-- Choisissez un service --') ?></option>
                  <?php foreach ($services as $svc): ?>
                    <option value="<?= $svc['id'] ?>"
                            data-setup="<?= $svc['setup_xaf'] ?>"
                            data-monthly="<?= $svc['monthly_xaf'] ?>"
                            <?= $selectedService === $svc['id'] ? 'selected' : '' ?>>
                      <?= $svc['icon'] ?> <?= e(lang()==='fr' ? $svc['name_fr'] : $svc['name_en']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label><?= t('Payment Type *','Type de Paiement *') ?></label>
                <select name="type" id="payment-type" required>
                  <option value="both"><?= t('Setup + First Month','Installation + Premier Mois') ?></option>
                  <option value="setup"><?= t('Setup fee only','Frais d\'installation uniquement') ?></option>
                  <option value="monthly"><?= t('Monthly only','Mensuel uniquement') ?></option>
                </select>
              </div>

              <div id="price-display" style="background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.2);border-radius:8px;padding:1rem;margin-bottom:1rem;display:none;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                  <span style="font-size:0.875rem;color:var(--muted);"><?= t('Amount due:','Montant dû :') ?></span>
                  <span id="price-amount" style="font-family:'Fraunces',serif;font-weight:900;font-size:1.4rem;color:var(--yellow);"></span>
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label><?= t('Your Name *','Votre Nom *') ?></label>
                  <input type="text" name="contact_name" value="<?= e(currentUser()['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label><?= t('Business Name','Nom de l\'Entreprise') ?></label>
                  <input type="text" name="business_name" value="<?= e($_POST['business_name'] ?? '') ?>" placeholder="<?= t('Optional','Optionnel') ?>">
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label><?= t('Phone *','Téléphone *') ?></label>
                  <input type="tel" name="contact_phone" value="<?= e($_POST['contact_phone'] ?? currentUser()['phone'] ?? '') ?>" placeholder="+237 6XX XXX XXX">
                </div>
                <div class="form-group">
                  <label>Email</label>
                  <input type="email" name="contact_email" value="<?= e(currentUser()['email'] ?? '') ?>">
                </div>
              </div>

              <div class="form-group">
                <label><?= t('Additional Notes','Notes supplémentaires') ?></label>
                <textarea name="notes" rows="3" placeholder="<?= t('Any specific requirements or questions...','Exigences spécifiques ou questions...') ?>"></textarea>
              </div>

              <div class="form-group">
                <label><?= t('Payment Proof *','Preuve de Paiement *') ?></label>
                <input type="file" name="proof" accept="image/*,.pdf" style="color:var(--muted);padding:0.5rem 0;" required>
                <small style="color:var(--muted-2);font-size:0.75rem;"><?= t('Screenshot of Mobile Money or bank transfer confirmation','Capture d\'écran de confirmation Mobile Money ou virement') ?></small>
              </div>

              <button type="submit" class="btn btn-primary btn-full" style="margin-top:0.5rem;font-size:1rem;">
                <?= t('Submit Order →','Soumettre la Commande →') ?>
              </button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <!-- HOW IT WORKS -->
      <aside>
        <div class="listing-widget">
          <h4><?= t('How it works','Comment ça marche') ?></h4>
          <?php
          $steps = [
              ['1', t('Select a service and payment type','Sélectionnez un service et type de paiement')],
              ['2', t('Send payment via MTN MoMo or Orange Money','Envoyez le paiement via MTN MoMo ou Orange Money')],
              ['3', t('Upload your payment screenshot','Téléchargez votre capture de paiement')],
              ['4', t('Admin approves within 24 hours','Admin approuve dans les 24 heures')],
              ['5', t('We set everything up within 48 hours','Nous configurons tout dans les 48 heures')],
          ];
          foreach ($steps as [$num, $text]): ?>
            <div style="display:flex;gap:0.75rem;align-items:flex-start;padding:0.6rem 0;border-bottom:1px solid var(--border);font-size:0.83rem;color:var(--muted);">
              <span style="width:22px;height:22px;border-radius:50%;background:rgba(0,168,120,0.15);border:1px solid rgba(0,168,120,0.3);display:flex;align-items:center;justify-content:center;font-size:0.68rem;color:var(--green);flex-shrink:0;font-weight:600;"><?= $num ?></span>
              <?= e($text) ?>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="listing-widget" style="margin-top:1.25rem;">
          <h4>💳 <?= t('Payment Methods','Modes de Paiement') ?></h4>
          <div style="display:flex;flex-direction:column;gap:0.6rem;">
            <div style="background:rgba(255,200,0,0.08);border:1px solid rgba(255,200,0,0.2);border-radius:8px;padding:0.75rem;font-size:0.83rem;">
              <div style="color:var(--white);font-weight:500;margin-bottom:0.2rem;">📱 MTN Mobile Money</div>
              <div style="color:var(--muted-2);font-size:0.78rem;">+237 XXX XXX XXX</div>
            </div>
            <div style="background:rgba(255,100,0,0.08);border:1px solid rgba(255,100,0,0.2);border-radius:8px;padding:0.75rem;font-size:0.83rem;">
              <div style="color:var(--white);font-weight:500;margin-bottom:0.2rem;">📱 Orange Money</div>
              <div style="color:var(--muted-2);font-size:0.78rem;">+237 XXX XXX XXX</div>
            </div>
          </div>
          <p style="font-size:0.75rem;color:var(--muted-2);margin-top:0.75rem;">
            <?= t('No card payments currently. Offline verification only.','Pas de paiement par carte actuellement. Vérification hors ligne uniquement.') ?>
          </p>
        </div>

        <div class="listing-widget" style="margin-top:1.25rem;background:rgba(0,168,120,0.05);border-color:rgba(0,168,120,0.2);">
          <p style="font-size:0.83rem;color:var(--muted);line-height:1.6;">
            ❓ <?= t('Questions? Email us at','Des questions ? Écrivez-nous à') ?>
            <a href="mailto:<?= SITE_EMAIL ?>" style="color:var(--green);"><?= SITE_EMAIL ?></a>
          </p>
        </div>
      </aside>
    </div>
  </div>
</section>

<script>
function selectService(id) {
  document.getElementById('service-select').value = id;
  updatePrice();
}

function updatePrice() {
  const sel = document.getElementById('service-select');
  const opt = sel.options[sel.selectedIndex];
  const type = document.getElementById('payment-type').value;
  const setup = parseInt(opt.dataset.setup || 0);
  const monthly = parseInt(opt.dataset.monthly || 0);
  let amount = 0;
  if (type === 'setup')   amount = setup;
  if (type === 'monthly') amount = monthly;
  if (type === 'both')    amount = setup + monthly;
  const display = document.getElementById('price-display');
  if (amount > 0 && sel.value) {
    display.style.display = 'block';
    document.getElementById('price-amount').textContent = amount.toLocaleString() + ' XAF';
  } else {
    display.style.display = 'none';
  }
}

document.getElementById('service-select')?.addEventListener('change', updatePrice);
document.getElementById('payment-type')?.addEventListener('change', updatePrice);
<?php if ($selectedService): ?>
document.addEventListener('DOMContentLoaded', () => { selectService(<?= $selectedService ?>); updatePrice(); });
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
