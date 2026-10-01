<?php
require_once __DIR__ . '/includes/config.php';
if (isLoggedIn()) redirect(SITE_URL . '/dashboard');

$step   = 'request'; // request | sent | reset | done
$errors = [];
$token  = trim($_GET['token'] ?? '');
if ($token) $step = 'reset';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $step = $_POST['step'] ?? 'request';

    if ($step === 'request') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('Valid email required.', 'Email valide requis.');
        } else {
            $st = db()->prepare("SELECT * FROM users WHERE email=?");
            $st->execute([$email]);
            $user = $st->fetch();
            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                db()->prepare("UPDATE users SET reset_token=?, reset_expires=? WHERE id=?")
                     ->execute([$token, $expires, $user['id']]);
                $resetUrl = SITE_URL . '/reset-password?token=' . $token;
                sendMail($email,
                    t('Reset your 237Biz password', 'Réinitialisez votre mot de passe 237Biz'),
                    '<h2 style="color:#fff;font-family:Georgia,serif;">' . t('Password Reset', 'Réinitialisation du mot de passe') . '</h2>
                     <p style="color:rgba(255,255,255,0.8);">' . t('Hi', 'Bonjour') . ' ' . e($user['name']) . ',</p>
                     <p style="color:rgba(255,255,255,0.7);">' . t('Click the button below to reset your password. This link expires in 1 hour.', 'Cliquez sur le bouton ci-dessous pour réinitialiser votre mot de passe. Ce lien expire dans 1 heure.') . '</p>
                     <a href="' . $resetUrl . '" style="display:inline-block;margin:1.5rem 0;background:#00A878;color:#fff;padding:0.85rem 2rem;border-radius:6px;text-decoration:none;font-weight:500;">' . t('Reset My Password →', 'Réinitialiser mon mot de passe →') . '</a>
                     <p style="color:rgba(255,255,255,0.4);font-size:0.8rem;">' . t('If you did not request this, ignore this email.', 'Si vous n\'avez pas demandé ceci, ignorez cet email.') . '</p>'
                );
            }
            // Always show sent message (don't reveal if email exists)
            $step = 'sent';
        }

    } elseif ($step === 'reset') {
        $token   = trim($_POST['token'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $pass2   = $_POST['password2'] ?? '';

        if (strlen($pass) < 8)  $errors[] = t('Password must be at least 8 characters.', 'Mot de passe : 8 caractères minimum.');
        if (!preg_match('/[A-Z]/', $pass)) $errors[] = t('Must contain an uppercase letter.', 'Doit contenir une majuscule.');
        if (!preg_match('/[0-9]/', $pass)) $errors[] = t('Must contain a number.', 'Doit contenir un chiffre.');
        if ($pass !== $pass2)   $errors[] = t('Passwords do not match.', 'Les mots de passe ne correspondent pas.');

        if (!$errors) {
            $st = db()->prepare("SELECT * FROM users WHERE reset_token=? AND reset_expires > NOW()");
            $st->execute([$token]);
            $user = $st->fetch();
            if (!$user) {
                $errors[] = t('Invalid or expired reset link. Please request a new one.', 'Lien invalide ou expiré. Veuillez en demander un nouveau.');
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
                db()->prepare("UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL, login_attempts=0, locked_until=NULL WHERE id=?")
                     ->execute([$hash, $user['id']]);
                flash('success', t('Password updated! You can now sign in.', 'Mot de passe mis à jour ! Vous pouvez maintenant vous connecter.'));
                redirect(SITE_URL . '/login');
            }
        }
    }
}

$pageTitle = t('Reset Password — 237Biz', 'Réinitialisation — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>
<section style="min-height:80vh;display:flex;align-items:center;justify-content:center;padding:8rem 5vw 4rem;">
  <div style="width:100%;max-width:420px;">
    <div style="text-align:center;margin-bottom:2rem;">
      <a href="<?= SITE_URL ?>/" style="display:inline-flex;align-items:baseline;gap:2px;text-decoration:none;">
        <span class="brand-num">237</span><span class="brand-word">Biz</span><span class="brand-tld">.net</span>
      </a>
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;margin-top:0.75rem;">
        <?= t('Reset Password', 'Réinitialisation') ?>
      </h1>
    </div>

    <?php foreach ($errors as $e): ?>
      <div class="flash flash-error" style="margin-bottom:0.75rem;"><?= e($e) ?></div>
    <?php endforeach; ?>

    <?php if ($step === 'sent'): ?>
      <div class="form-card" style="text-align:center;">
        <div style="font-size:3rem;margin-bottom:1rem;">📧</div>
        <h3 style="font-family:'Fraunces',serif;margin-bottom:0.5rem;"><?= t('Check your email', 'Vérifiez votre email') ?></h3>
        <p style="color:var(--muted);font-size:0.875rem;"><?= t('If that email is registered, a reset link has been sent. Check your inbox and spam folder.', 'Si cet email est enregistré, un lien de réinitialisation a été envoyé. Vérifiez votre boîte de réception et les spams.') ?></p>
        <a href="<?= SITE_URL ?>/login" class="btn btn-outline btn-sm" style="margin-top:1rem;">← <?= t('Back to Sign In', 'Retour à la connexion') ?></a>
      </div>

    <?php elseif ($step === 'reset' && $token): ?>
      <div class="form-card">
        <form method="POST">
          <input type="hidden" name="csrf"  value="<?= csrf() ?>">
          <input type="hidden" name="step"  value="reset">
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="form-group">
            <label><?= t('New Password *', 'Nouveau mot de passe *') ?></label>
            <input type="password" name="password" required autocomplete="new-password" placeholder="<?= t('Min. 8 chars, 1 uppercase, 1 number', 'Min. 8 car., 1 majuscule, 1 chiffre') ?>">
          </div>
          <div class="form-group">
            <label><?= t('Confirm Password *', 'Confirmer le mot de passe *') ?></label>
            <input type="password" name="password2" required autocomplete="new-password" placeholder="••••••••">
          </div>
          <button type="submit" class="btn btn-primary btn-full"><?= t('Set New Password', 'Définir le nouveau mot de passe') ?></button>
        </form>
      </div>

    <?php else: ?>
      <div class="form-card">
        <p style="color:var(--muted);font-size:0.875rem;margin-bottom:1.5rem;"><?= t('Enter your email and we\'ll send you a reset link.', 'Entrez votre email et nous vous enverrons un lien de réinitialisation.') ?></p>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="step" value="request">
          <div class="form-group">
            <label><?= t('Email Address *', 'Adresse email *') ?></label>
            <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
          </div>
          <button type="submit" class="btn btn-primary btn-full"><?= t('Send Reset Link', 'Envoyer le lien') ?></button>
        </form>
        <p style="text-align:center;margin-top:1rem;font-size:0.83rem;color:var(--muted);">
          <a href="<?= SITE_URL ?>/login">← <?= t('Back to Sign In', 'Retour à la connexion') ?></a>
        </p>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
