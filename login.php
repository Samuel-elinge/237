<?php
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) redirect(SITE_URL . '/dashboard.php');

$mode   = $_GET['mode'] ?? 'login';
$errors = [];
$next = $_GET['next'] ?? '';
// Open redirect protection — only allow redirects within this site
if (!$next || !str_starts_with($next, SITE_URL)) {
    $next = SITE_URL . '/dashboard';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $mode = $_POST['mode'] ?? 'login';

    // ── HONEYPOT ─────────────────────────────────────────
    if (!empty($_POST['website_url'])) {
        sleep(2);
        redirect(SITE_URL . '/login.php?mode=' . $mode);
    }

    if ($mode === 'login') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';

        $st = db()->prepare("SELECT * FROM users WHERE email = ?");
        $st->execute([$email]);
        $user = $st->fetch();

        // ── RATE LIMITING ─────────────────────────────────
        if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $errors[] = t('Too many failed attempts. Account locked for 15 minutes.',
                          'Trop de tentatives. Compte verrouillé 15 minutes.');
        } elseif ($user && password_verify($pass, $user['password'])) {
            // ── EMAIL VERIFICATION CHECK ──────────────────
            if (!$user['verified']) {
                $errors[] = t('Please verify your email before logging in. Check your inbox.',
                              'Veuillez vérifier votre email avant de vous connecter. Vérifiez votre boîte mail.');
            } else {
                // Success — reset attempts + record login time
                db()->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, last_login_at=NOW() WHERE id=?")
                     ->execute([$user['id']]);
                // Regenerate session ID on login to prevent session fixation
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role']    = $user['role'];

                // ── Automation: update last login + language tag ──
                if (file_exists(__DIR__ . '/automation/helper.php')) {
                    require_once __DIR__ . '/automation/helper.php';
                    $lang = $_COOKIE['lang'] ?? $_SESSION['lang'] ?? 'en';
                    addUserTag($user['id'], $lang === 'fr' ? 'french' : 'english');
                    // Note: Welcome sequence enrollment is handled in verify.php only
                    // to prevent duplicate emails on every login
                }
                // ─────────────────────────────────────────

                flash('success', t('Welcome back, ', 'Bienvenue, ') . $user['name'] . '!');
                redirect($next);
            }
        } else {
            if ($user) {
                $attempts = ($user['login_attempts'] ?? 0) + 1;
                $lock = $attempts >= 5 ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
                db()->prepare("UPDATE users SET login_attempts=?, locked_until=? WHERE id=?")
                     ->execute([$attempts, $lock, $user['id']]);
            }
            $errors[] = t('Invalid email or password.', 'Email ou mot de passe incorrect.');
        }

    } elseif ($mode === 'register') {
        $name  = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';
        $phone = trim($_POST['phone'] ?? '');

        if (!$name)  $errors[] = t('Name is required.', 'Le nom est requis.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('Valid email required.', 'Email valide requis.');
        if (strlen($pass) < 8) $errors[] = t('Password must be at least 8 characters.', 'Mot de passe : 8 caractères minimum.');
        if ($pass !== $pass2) $errors[] = t('Passwords do not match.', 'Les mots de passe ne correspondent pas.');
        if (!preg_match('/[A-Z]/', $pass)) $errors[] = t('Password must contain an uppercase letter.', 'Le mot de passe doit contenir une majuscule.');
        if (!preg_match('/[0-9]/', $pass)) $errors[] = t('Password must contain a number.', 'Le mot de passe doit contenir un chiffre.');

        if (!$errors) {
            $chk = db()->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) $errors[] = t('Email already registered.', 'Email déjà enregistré.');
        }

        if (!$errors) {
            $token = bin2hex(random_bytes(32));
            $hash  = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $st = db()->prepare("INSERT INTO users (name,email,password,phone,verify_token,verified) VALUES (?,?,?,?,?,0)");
            $st->execute([$name, $email, $hash, $phone, $token]);
            $newUserId = (int)db()->lastInsertId();

            // ── Automation: tag language on registration ──
            if (file_exists(__DIR__ . '/automation/helper.php')) {
                require_once __DIR__ . '/automation/helper.php';
                $lang = $_COOKIE['lang'] ?? $_SESSION['lang'] ?? 'en';
                addUserTag($newUserId, $lang === 'fr' ? 'french' : 'english');
            }
            // ─────────────────────────────────────────────

            // Send verification email
            $verifyUrl = SITE_URL . '/verify.php?token=' . $token;
            sendMail($email,
                t('Verify your 237Biz account', 'Vérifiez votre compte 237Biz'),
                '<h2 style="color:#fff;font-family:Georgia,serif;">'. t('Welcome to 237Biz!','Bienvenue sur 237Biz !') .'</h2>
                 <p style="color:rgba(255,255,255,0.7);">'. t('Hi','Bonjour') .' ' . htmlspecialchars($name) . ',</p>
                 <p style="color:rgba(255,255,255,0.7);">'. t('Please click the button below to verify your email address and activate your account.','Cliquez sur le bouton ci-dessous pour vérifier votre adresse email et activer votre compte.') .'</p>
                 <a href="'.$verifyUrl.'" style="display:inline-block;margin:1.5rem 0;background:#00A878;color:#fff;padding:0.85rem 2rem;border-radius:5px;text-decoration:none;font-weight:500;">'.
                 t('Verify My Email →','Vérifier mon Email →').'</a>
                 <p style="color:rgba(255,255,255,0.4);font-size:0.8rem;">'.t('Link expires in 24 hours. If you did not register, ignore this email.','Lien expire dans 24 heures. Si vous n\'avez pas créé de compte, ignorez cet email.').'</p>'
            );

            flash('success', t('Account created! Please check your email to verify your account before logging in.',
                               'Compte créé ! Vérifiez votre email pour activer votre compte avant de vous connecter.'));
            redirect(SITE_URL . '/login.php?mode=login&verified=pending');
        }
    }
}

$pageTitle = $mode === 'register'
    ? t('Create Account — 237Biz', 'Créer un compte — 237Biz')
    : t('Sign In — 237Biz', 'Connexion — 237Biz');

require_once __DIR__ . '/includes/header.php';
?>

<section style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:8rem 5vw 4rem;">
  <div style="width:100%;max-width:440px;">

    <div style="text-align:center;margin-bottom:2rem;">
      <a href="<?= SITE_URL ?>/" style="display:inline-flex;align-items:baseline;gap:2px;text-decoration:none;">
        <span class="brand-num">237</span><span class="brand-word">Biz</span><span class="brand-tld">.net</span>
      </a>
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;margin-top:0.75rem;">
        <?= $mode === 'register' ? t('Create your account','Créer votre compte') : t('Welcome back','Bienvenue') ?>
      </h1>
      <p style="color:var(--muted);font-size:0.875rem;margin-top:0.35rem;">
        <?= $mode === 'register'
            ? t('List your business free. No spam, no hidden fees.','Listez votre entreprise gratuitement. Sans spam ni frais cachés.')
            : t('Sign in to manage your listings and orders.','Connectez-vous pour gérer vos annonces et commandes.') ?>
      </p>
    </div>

    <?php if (isset($_GET['verified']) && $_GET['verified'] === 'pending'): ?>
      <div class="flash flash-success" style="margin-bottom:1rem;">
        📧 <?= t('Check your email and click the verification link to activate your account.',
                  'Vérifiez votre email et cliquez sur le lien de vérification pour activer votre compte.') ?>
      </div>
    <?php endif; ?>
    <?php if (isset($_GET['verified']) && $_GET['verified'] === 'ok'): ?>
      <div class="flash flash-success" style="margin-bottom:1rem;">
        ✅ <?= t('Email verified! You can now sign in.','Email vérifié ! Vous pouvez maintenant vous connecter.') ?>
      </div>
    <?php endif; ?>

    <!-- TABS -->
    <div style="display:flex;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:4px;margin-bottom:1.5rem;">
      <a href="?mode=login&next=<?= urlencode($next) ?>"
         style="flex:1;text-align:center;padding:0.6rem;border-radius:6px;font-size:0.875rem;font-weight:500;transition:all 0.2s;text-decoration:none;<?= $mode==='login' ? 'background:var(--green);color:#fff;' : 'color:var(--muted);' ?>">
        <?= t('Sign In','Connexion') ?>
      </a>
      <a href="?mode=register"
         style="flex:1;text-align:center;padding:0.6rem;border-radius:6px;font-size:0.875rem;font-weight:500;transition:all 0.2s;text-decoration:none;<?= $mode==='register' ? 'background:var(--green);color:#fff;' : 'color:var(--muted);' ?>">
        <?= t('Register','S\'inscrire') ?>
      </a>
    </div>

    <?php foreach ($errors as $err): ?>
      <div class="flash flash-error" style="margin-bottom:0.75rem;margin-top:0;"><?= e($err) ?></div>
    <?php endforeach; ?>

    <div class="form-card">
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <!-- HONEYPOT -->
        <div style="position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden;" aria-hidden="true">
          <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
        </div>

        <?php if ($mode === 'register'): ?>
          <div class="form-group">
            <label><?= t('Full Name *','Nom complet *') ?></label>
            <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>" required
                   autocomplete="name" placeholder="<?= t('Your name','Votre nom') ?>">
          </div>
          <div class="form-group">
            <label><?= t('Phone','Téléphone') ?></label>
            <input type="tel" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+237 6XX XXX XXX">
          </div>
        <?php endif; ?>

        <div class="form-group">
          <label><?= t('Email Address *','Adresse email *') ?></label>
          <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required
                 autocomplete="email" placeholder="you@example.com">
        </div>

        <div class="form-group">
          <label><?= t('Password *','Mot de passe *') ?></label>
          <input type="password" name="password" required autocomplete="<?= $mode==='register' ? 'new-password' : 'current-password' ?>"
                 placeholder="<?= $mode==='register' ? t('Min. 8 chars, 1 uppercase, 1 number','Min. 8 car., 1 majuscule, 1 chiffre') : '••••••••' ?>">
        </div>

        <?php if ($mode === 'register'): ?>
          <div class="form-group">
            <label><?= t('Confirm Password *','Confirmer le mot de passe *') ?></label>
            <input type="password" name="password2" required autocomplete="new-password" placeholder="••••••••">
          </div>
          <p style="font-size:0.75rem;color:var(--muted-2);margin-bottom:1rem;line-height:1.5;">
            <?= t('✉️ A verification email will be sent to activate your account.',
                  '✉️ Un email de vérification sera envoyé pour activer votre compte.') ?>
          </p>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-full">
          <?= $mode === 'register' ? t('Create Account','Créer mon compte') : t('Sign In','Se connecter') ?>
        </button>
        <?php if ($mode === 'login'): ?>
          <p style="text-align:center;margin-top:0.75rem;font-size:0.8rem;">
            <a href="<?= SITE_URL ?>/reset-password.php" style="color:var(--muted-2);"><?= t('Forgot your password?','Mot de passe oublié ?') ?></a>
          </p>
        <?php endif; ?>
      </form>
    </div>

    <p style="text-align:center;margin-top:1rem;font-size:0.83rem;color:var(--muted);">
      <?php if ($mode === 'login'): ?>
        <?= t("Don't have an account?",'Pas encore de compte ?') ?>
        <a href="?mode=register"><?= t('Register free','Inscrivez-vous gratuitement') ?></a>
      <?php else: ?>
        <?= t('Already have an account?','Vous avez déjà un compte ?') ?>
        <a href="?mode=login"><?= t('Sign in','Connexion') ?></a>
      <?php endif; ?>
    </p>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
