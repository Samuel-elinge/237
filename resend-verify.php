<?php
require_once __DIR__ . '/includes/config.php';

$errors = [];
$sent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!empty($_POST['website_url'])) redirect(SITE_URL . '/login.php'); // honeypot

    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('Please enter a valid email.','Veuillez entrer un email valide.');
    } else {
        $st = db()->prepare("SELECT * FROM users WHERE email = ? AND verified = 0");
        $st->execute([$email]);
        $user = $st->fetch();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            db()->prepare("UPDATE users SET verify_token=? WHERE id=?")->execute([$token, $user['id']]);
            $verifyUrl = SITE_URL . '/verify.php?token=' . $token;
            sendMail($email,
                t('Verify your 237Biz account','Vérifiez votre compte 237Biz'),
                '<h2 style="color:#fff;font-family:Georgia,serif;">'.t('Verify your email','Vérifiez votre email').'</h2>
                 <p style="color:rgba(255,255,255,0.7);">'.t('Click below to verify your email and activate your account.','Cliquez ci-dessous pour vérifier votre email et activer votre compte.').'</p>
                 <a href="'.$verifyUrl.'" style="display:inline-block;margin:1.5rem 0;background:#00A878;color:#fff;padding:0.85rem 2rem;border-radius:5px;text-decoration:none;">'.
                 t('Verify Email →','Vérifier Email →').'</a>'
            );
        }
        // Always show success (don't reveal if email exists)
        $sent = true;
    }
}

$pageTitle = t('Resend Verification — 237Biz','Renvoyer la vérification — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>
<section style="min-height:80vh;display:flex;align-items:center;justify-content:center;padding:8rem 5vw 4rem;">
  <div style="width:100%;max-width:420px;">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;margin-bottom:0.5rem;">
      📧 <?= t('Resend Verification Email','Renvoyer l\'Email de Vérification') ?>
    </h1>
    <p style="color:var(--muted);margin-bottom:2rem;font-size:0.9rem;">
      <?= t('Enter your email and we\'ll resend the verification link.','Entrez votre email et nous renverrons le lien de vérification.') ?>
    </p>
    <?php if ($sent): ?>
      <div class="flash flash-success">
        ✅ <?= t('If that email is registered and unverified, a new link has been sent. Check your inbox.','Si cet email est enregistré et non vérifié, un nouveau lien a été envoyé. Vérifiez votre boîte.') ?>
      </div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
    <div class="form-card">
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <div style="position:absolute;left:-9999px;opacity:0;height:0;" aria-hidden="true">
          <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
        </div>
        <div class="form-group">
          <label><?= t('Email Address','Adresse email') ?></label>
          <input type="email" name="email" required placeholder="you@example.com">
        </div>
        <button type="submit" class="btn btn-primary btn-full"><?= t('Resend Link','Renvoyer le lien') ?></button>
      </form>
    </div>
    <p style="text-align:center;margin-top:1rem;font-size:0.83rem;color:var(--muted);">
      <a href="<?= SITE_URL ?>/login.php"><?= t('← Back to Sign In','← Retour à la connexion') ?></a>
    </p>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
