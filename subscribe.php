<?php
require_once __DIR__ . '/includes/config.php';

$action = $_GET['action'] ?? 'subscribe';

// Unsubscribe
if ($action === 'unsubscribe') {
    $token = trim($_GET['token'] ?? '');
    if ($token) {
        db()->prepare("UPDATE subscribers SET active=0 WHERE token=?")->execute([$token]);
        flash('success', t('You have been unsubscribed from 237Biz emails.','Vous avez été désabonné des emails 237Biz.'));
    }
    redirect(SITE_URL . '/index.php');
}

// Confirm
if ($action === 'confirm') {
    $token = trim($_GET['token'] ?? '');
    if ($token) {
        $st = db()->prepare("SELECT * FROM subscribers WHERE token=?");
        $st->execute([$token]);
        $sub = $st->fetch();
        if ($sub) {
            db()->prepare("UPDATE subscribers SET confirmed=1 WHERE id=?")->execute([$sub['id']]);
            flash('success', t('Subscribed! You\'ll receive weekly updates about new Cameroon businesses.',
                               'Abonné ! Vous recevrez des mises à jour hebdomadaires sur les nouvelles entreprises camerounaises.'));
        }
    }
    redirect(SITE_URL . '/index.php');
}

$errors = [];
$sent   = false;
$locs   = db()->query("SELECT * FROM locations ORDER BY sort_order")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!empty($_POST['website_url'])) redirect(SITE_URL . '/subscribe'); // honeypot

    $email    = strtolower(trim($_POST['email'] ?? ''));
    $name     = trim($_POST['name'] ?? '');
    $locId    = (int)($_POST['location_id'] ?? 0) ?: null;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('Please enter a valid email address.','Veuillez entrer une adresse email valide.');
    }

    if (!$errors) {
        $token = bin2hex(random_bytes(32));
        // Upsert subscriber
        $existing = db()->prepare("SELECT id,active FROM subscribers WHERE email=?");
        $existing->execute([$email]);
        $sub = $existing->fetch();

        if ($sub) {
            if ($sub['active']) {
                $sent = true; // Already subscribed — silently succeed
            } else {
                db()->prepare("UPDATE subscribers SET name=?,location_id=?,token=?,confirmed=0,active=1 WHERE id=?")
                     ->execute([$name ?: null, $locId, $token, $sub['id']]);
                $sent = true;
            }
        } else {
            db()->prepare("INSERT INTO subscribers (email,name,location_id,token,confirmed) VALUES (?,?,?,?,0)")
                 ->execute([$email, $name ?: null, $locId, $token]);
            $sent = true;
        }

        if ($sent) {
            $confirmUrl = SITE_URL . '/subscribe?action=confirm&token=' . $token;
            $unsubUrl   = SITE_URL . '/subscribe?action=unsubscribe&token=' . $token;
            sendMail($email,
                t('Confirm your 237Biz subscription','Confirmez votre abonnement 237Biz'),
                '<h2 style="color:#fff;font-family:Georgia,serif;">'.t('Almost there!','Presque là !').'</h2>
                 <p style="color:rgba(255,255,255,0.7);">'.t('Confirm your subscription to get weekly updates about new businesses in Cameroon.','Confirmez votre abonnement pour recevoir des mises à jour hebdomadaires sur les nouvelles entreprises au Cameroun.').'</p>
                 <a href="'.$confirmUrl.'" style="display:inline-block;margin:1.5rem 0;background:#00A878;color:#fff;padding:0.85rem 2rem;border-radius:5px;text-decoration:none;font-weight:500;">'.t('Confirm Subscription →','Confirmer l\'abonnement →').'</a>
                 <p style="color:rgba(255,255,255,0.4);font-size:0.75rem;">'.t('Don\'t want emails from us?','Vous ne voulez pas d\'emails de notre part ?').' <a href="'.$unsubUrl.'" style="color:rgba(255,255,255,0.4);">'.t('Unsubscribe','Se désabonner').'</a></p>'
            );
        }
    }
}

$pageTitle = t('Subscribe — 237Biz Newsletter','Abonnement — Newsletter 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<section style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:8rem 5vw 4rem;">
  <div style="width:100%;max-width:480px;text-align:center;">
    <div style="font-size:3rem;margin-bottom:1rem;">📬</div>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;margin-bottom:0.5rem;">
      <?= t('Stay in the loop','Restez informé') ?>
    </h1>
    <p style="color:var(--muted);font-size:0.9rem;margin-bottom:2rem;line-height:1.7;">
      <?= t('Get a weekly digest of new businesses in Cameroon, straight to your inbox. Free, no spam.',
            'Recevez un résumé hebdomadaire des nouvelles entreprises au Cameroun, directement dans votre boîte. Gratuit, sans spam.') ?>
    </p>

    <?php if ($sent): ?>
      <div class="flash flash-success" style="text-align:left;margin-bottom:1rem;">
        📧 <?= t('Check your email and click the confirmation link to complete your subscription.',
                  'Vérifiez votre email et cliquez sur le lien de confirmation pour finaliser votre abonnement.') ?>
      </div>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?><div class="flash flash-error" style="text-align:left;"><?= e($err) ?></div><?php endforeach; ?>

    <div class="form-card" style="text-align:left;">
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <div style="position:absolute;left:-9999px;opacity:0;height:0;" aria-hidden="true">
          <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
        </div>
        <div class="form-group">
          <label>Email *</label>
          <input type="email" name="email" required placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label><?= t('Your Name (optional)','Votre nom (optionnel)') ?></label>
          <input type="text" name="name" placeholder="<?= t('First name','Prénom') ?>" value="<?= e($_POST['name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label><?= t('Preferred City (optional)','Ville préférée (optionnel)') ?></label>
          <select name="location_id">
            <option value=""><?= t('All of Cameroon','Tout le Cameroun') ?></option>
            <?php foreach ($locs as $loc): ?>
              <option value="<?= $loc['id'] ?>"><?= e($loc['name_en']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary btn-full"><?= t('Subscribe →','S\'abonner →') ?></button>
      </form>
    </div>

    <p style="font-size:0.78rem;color:var(--muted-2);margin-top:1rem;">
      <?= t('We send one email per week. Unsubscribe anytime.','Nous envoyons un email par semaine. Désabonnez-vous à tout moment.') ?>
    </p>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
