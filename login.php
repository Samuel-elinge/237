<?php
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    $r = currentUser()['role'] ?? '';
    if ($r === 'growth_partner')                        redirect(SITE_URL . '/partner/dashboard');
    elseif ($r === 'sales_staff')                       redirect(SITE_URL . '/agent/dashboard');
    elseif ($r === 'creator')                           redirect(SITE_URL . '/creator/dashboard');
    elseif (in_array($r, ['admin','operations_admin'])) redirect(SITE_URL . '/admin/');
    else                                                redirect(SITE_URL . '/dashboard.php');
}

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
                // Role-based default redirect (only when no ?next= was given)
                if ($next === SITE_URL . '/dashboard') {
                    if ($user['role'] === 'growth_partner') {
                        $next = SITE_URL . '/partner/dashboard';
                    } elseif ($user['role'] === 'sales_staff') {
                        $next = SITE_URL . '/agent/dashboard';
                    } elseif ($user['role'] === 'creator') {
                        $next = SITE_URL . '/creator/dashboard';
                    } elseif (in_array($user['role'], ['admin', 'operations_admin'])) {
                        $next = SITE_URL . '/admin/';
                    }
                }
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

        // Phone-only registration: allow signup with just phone (no real email)
        $phoneOnly = ($phone && !$email);
        if ($phoneOnly) {
            // Generate a stable placeholder email from phone so DB unique constraint is met
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            $email = 'phone+' . $cleanPhone . '@237biz.internal';
        }

        if (!$name)  $errors[] = t('Name is required.', 'Le nom est requis.');
        if (!$phoneOnly && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('Valid email required.', 'Email valide requis.');
        // Cameroon phone validation: strip country code (+237 / 237), must be 9 digits starting with 6
        if ($phoneOnly) {
            $localPhone = $cleanPhone ?? '';
            // Strip leading country code: 00237 or 237
            if (str_starts_with($localPhone, '00237')) $localPhone = substr($localPhone, 5);
            elseif (str_starts_with($localPhone, '237') && strlen($localPhone) === 12) $localPhone = substr($localPhone, 3);
            if (!preg_match('/^6[0-9]{8}$/', $localPhone)) {
                $errors[] = t('Please enter a valid Cameroon mobile number (e.g. 677 123 456).',
                              'Veuillez entrer un numéro mobile camerounais valide (ex. 677 123 456).');
            } else {
                // Normalise to local 9-digit form for storage
                $cleanPhone = $localPhone;
                $email = 'phone+' . $cleanPhone . '@237biz.internal';
            }
        }
        if (strlen($pass) < 8) $errors[] = t('Password must be at least 8 characters.', 'Mot de passe : 8 caractères minimum.');
        if ($pass !== $pass2) $errors[] = t('Passwords do not match.', 'Les mots de passe ne correspondent pas.');
        if (!preg_match('/[A-Z]/', $pass)) $errors[] = t('Password must contain an uppercase letter.', 'Le mot de passe doit contenir une majuscule.');
        if (!preg_match('/[0-9]/', $pass)) $errors[] = t('Password must contain a number.', 'Le mot de passe doit contenir un chiffre.');

        if (!$errors) {
            // Check for duplicate phone (for phone-only accounts)
            if ($phoneOnly) {
                $chkP = db()->prepare("SELECT id FROM users WHERE phone=? AND email LIKE 'phone+%@237biz.internal'");
                $chkP->execute([$phone]);
                if ($chkP->fetch()) $errors[] = t('Phone number already registered.', 'Ce numéro est déjà enregistré.');
            } else {
                $chk = db()->prepare("SELECT id FROM users WHERE email = ?");
                $chk->execute([$email]);
                if ($chk->fetch()) $errors[] = t('Email already registered.', 'Email déjà enregistré.');
            }
        }

        if (!$errors) {
            $token = bin2hex(random_bytes(32));
            $hash  = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            // Phone-only accounts are auto-verified (no email to verify)
            $verified = $phoneOnly ? 1 : 0;
            $st = db()->prepare("INSERT INTO users (name,email,password,phone,verify_token,verified) VALUES (?,?,?,?,?,?)");
            $st->execute([$name, $email, $hash, $phone, $token, $verified]);
            $newUserId = (int)db()->lastInsertId();

            // ── Automation: tag language on registration ──
            if (file_exists(__DIR__ . '/automation/helper.php')) {
                require_once __DIR__ . '/automation/helper.php';
                $lang = $_COOKIE['lang'] ?? $_SESSION['lang'] ?? 'en';
                addUserTag($newUserId, $lang === 'fr' ? 'french' : 'english');
            }
            // ─────────────────────────────────────────────

            if ($phoneOnly) {
                // Log straight in — no email to verify
                session_regenerate_id(true);
                $_SESSION['user_id']   = $newUserId;
                $_SESSION['user_name'] = $name;
                flash('success', t('Account created! You are now logged in.', 'Compte créé ! Vous êtes maintenant connecté.'));
                redirect($next ?: SITE_URL . '/dashboard');
            } else {
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
}

$pageTitle = $mode === 'register'
    ? t('Create Account — 237Biz', 'Créer un compte — 237Biz')
    : t('Sign In — 237Biz', 'Connexion — 237Biz');

// Minimal standalone head — no full navbar/footer on login/register pages
$lang = lang();
?><!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700;9..144,900&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">
<style>
/* Login page — minimal chrome */
body { margin:0; }
.login-wrap {
  min-height: 100svh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: clamp(1.5rem, 6vw, 3rem) max(16px, 5vw);
}
.login-inner { width: 100%; max-width: 440px; }
.login-logo-block { text-align: center; margin-bottom: 1.5rem; }
.login-logo-block h1 {
  font-family: 'Fraunces', serif;
  font-weight: 900;
  font-size: clamp(1.4rem, 5vw, 1.8rem);
  margin: 0.5rem 0 0.25rem;
  line-height: 1.2;
}
.login-logo-block p { color: var(--muted); font-size: 0.85rem; margin: 0; line-height: 1.5; }
.login-footer {
  margin-top: 2rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--border);
  text-align: center;
  font-size: 0.75rem;
  color: var(--muted-2);
  line-height: 1.8;
}
.login-footer a { color: var(--muted); text-decoration: none; }
.login-footer a:hover { text-decoration: underline; }
</style>
</head>
<body>

<div class="login-wrap">
  <div class="login-inner">

    <div class="login-logo-block">
      <a href="<?= SITE_URL ?>/" style="display:inline-flex;align-items:baseline;gap:2px;text-decoration:none;">
        <span class="brand-num">237</span><span class="brand-word">Biz</span><span class="brand-tld">.net</span>
      </a>
      <h1>
        <?= $mode === 'register' ? t('Create your account','Créer votre compte') : t('Welcome back','Bienvenue') ?>
      </h1>
      <p>
        <?= $mode === 'register'
            ? t('Free account — book appointments, claim offers &amp; leave reviews.','Compte gratuit — réservez, profitez des offres et laissez des avis.')
            : t('Sign in to book appointments, use offers &amp; manage your account.','Connectez-vous pour réserver, utiliser vos offres et gérer votre compte.') ?>
      </p>
    </div>

    <?php if (isset($_GET['verified']) && $_GET['verified'] === 'pending'): ?>
      <div class="flash flash-success" style="margin-bottom:1rem;">
        📧 <?= t('Check your email and click the verification link to activate your account.',
                  'Vérifiez votre email et cliquez sur le lien de vérification pour activer votre compte.') ?>
      </div>
    <?php endif; ?>
    <?php if (isset($_GET['pending_verify'])): ?>
      <div class="flash flash-success" style="margin-bottom:1rem;">
        📧 <?= t('Account created! Please check your email and verify your account before signing in.',
                  'Compte créé ! Vérifiez votre email et activez votre compte avant de vous connecter.') ?>
        <br><a href="<?= SITE_URL ?>/resend-verify.php" style="color:var(--green);font-size:0.85rem;"><?= t('Resend verification email','Renvoyer l\'email de vérification') ?></a>
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

    <?php if ($mode === 'register'): ?>
      <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.8rem;color:var(--muted);line-height:1.6;">
        <?= t('🗓️ <strong>Book appointments</strong> · 🎟️ <strong>Claim discount offers</strong> · ⭐ <strong>Write reviews</strong> — all with one free account.',
              '🗓️ <strong>Réserver</strong> · 🎟️ <strong>Profiter des offres</strong> · ⭐ <strong>Laisser des avis</strong> — avec un seul compte gratuit.') ?>
      </div>
    <?php endif; ?>

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
          <?php if (isset($_GET['reason']) && $_GET['reason'] === 'review'): ?>
          <div style="background:linear-gradient(135deg,#08472F,#0d6b47);color:#fff;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.82rem;line-height:1.5;">
            ⭐ <?= t('Create a free account to review <strong>' . e($_GET['biz'] ?? 'this business') . '</strong>',
                      'Créez un compte gratuit pour évaluer <strong>' . e($_GET['biz'] ?? 'cette entreprise') . '</strong>') ?>
          </div>
          <?php endif; ?>
          <div class="form-group">
            <label><?= t('Full Name *','Nom complet *') ?></label>
            <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>" required
                   autocomplete="name" placeholder="<?= t('Your name','Votre nom') ?>">
          </div>
          <!-- Phone OR Email — user needs only one -->
          <div style="margin-bottom:1rem;">
            <div style="font-size:.8rem;font-weight:600;color:var(--muted);margin-bottom:.5rem;">
              <?= t('Sign up with phone or email (choose one):','Inscrivez-vous avec téléphone ou email (choisissez un) :') ?>
            </div>
            <div class="form-group" style="margin-bottom:.5rem;">
              <label><?= t('📱 Phone Number','📱 Numéro de téléphone') ?></label>
              <input type="tel" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+237 6XX XXX XXX"
                     autocomplete="tel" id="reg-phone" oninput="toggleRegFields()">
            </div>
            <div style="text-align:center;font-size:.75rem;color:var(--muted);margin:.25rem 0;"><?= t('— or —','— ou —') ?></div>
            <div class="form-group" style="margin-bottom:0;" id="email-field-wrap">
              <label><?= t('✉️ Email Address','✉️ Adresse email') ?></label>
              <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"
                     autocomplete="email" placeholder="you@example.com" id="reg-email" oninput="toggleRegFields()">
            </div>
          </div>
        <?php else: ?>
        <div class="form-group">
          <label><?= t('Email Address *','Adresse email *') ?></label>
          <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required
                 autocomplete="email" placeholder="you@example.com">
        </div>
        <?php endif; ?>
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
          <p style="font-size:0.75rem;color:var(--muted-2);margin-bottom:1rem;line-height:1.5;" id="email-verify-note">
            <?= t('✉️ A verification email will be sent if you register with email.',
                  '✉️ Un email de vérification sera envoyé si vous vous inscrivez avec un email.') ?>
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

    <div class="login-footer">
      <div>
        <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> &middot;
        <a href="<?= SITE_URL ?>/about.php"><?= t('About','À propos') ?></a> &middot;
        <a href="<?= SITE_URL ?>/contact.php"><?= t('Contact','Contact') ?></a> &middot;
        <a href="<?= SITE_URL ?>/privacy.php"><?= t('Privacy','Confidentialité') ?></a>
      </div>
      <div style="margin-top:.4rem;">© <?= date('Y') ?> 237Biz. <?= t('All rights reserved.','Tous droits réservés.') ?></div>
    </div>

  </div>
</div>

<script>
// Phone-or-email toggle on registration form
function toggleRegFields() {
  var phone = document.getElementById('reg-phone');
  var email = document.getElementById('reg-email');
  var note  = document.getElementById('email-verify-note');
  if (!phone || !email) return;
  if (phone.value.trim()) {
    // Phone filled — email optional
    email.removeAttribute('required');
    if (note) note.style.display = 'none';
  } else if (email.value.trim()) {
    // Email filled — phone optional
    phone.removeAttribute('required');
    if (note) note.style.display = '';
  } else {
    // Neither filled — keep email note visible
    if (note) note.style.display = '';
  }
}
// Run once on page load (in case of back-button with pre-filled values)
document.addEventListener('DOMContentLoaded', toggleRegFields);
</script>
</body>
</html>
