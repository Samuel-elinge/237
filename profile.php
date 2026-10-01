<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u      = currentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'update_profile';

    // ── UPDATE PROFILE ────────────────────────────────────
    if ($action === 'update_profile') {
        $name  = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (!$name) $errors[] = t('Name is required.', 'Le nom est requis.');

        if (!$errors) {
            db()->prepare("UPDATE users SET name=?, phone=? WHERE id=?")
                 ->execute([$name, $phone ?: null, $u['id']]);
            flash('success', t('Profile updated successfully.', 'Profil mis à jour avec succès.'));
            redirect(SITE_URL . '/profile.php');
        }
    }

    // ── CHANGE PASSWORD ───────────────────────────────────
    elseif ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $u['password'])) {
            $errors[] = t('Current password is incorrect.', 'Le mot de passe actuel est incorrect.');
        }
        if (strlen($new) < 8) {
            $errors[] = t('New password must be at least 8 characters.', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
        }
        if (!preg_match('/[A-Z]/', $new)) {
            $errors[] = t('Password must contain an uppercase letter.', 'Le mot de passe doit contenir une majuscule.');
        }
        if (!preg_match('/[0-9]/', $new)) {
            $errors[] = t('Password must contain a number.', 'Le mot de passe doit contenir un chiffre.');
        }
        if ($new !== $confirm) {
            $errors[] = t('New passwords do not match.', 'Les nouveaux mots de passe ne correspondent pas.');
        }

        if (!$errors) {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]);
            db()->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $u['id']]);
            flash('success', t('Password changed successfully.', 'Mot de passe modifié avec succès.'));
            redirect(SITE_URL . '/profile.php');
        }
    }
}

// Stats
$listingCount = db()->prepare("SELECT COUNT(*) FROM listings WHERE user_id=?");
$listingCount->execute([$u['id']]);
$listingCount = $listingCount->fetchColumn();

$orderCount = db()->prepare("SELECT COUNT(*) FROM service_orders WHERE user_id=?");
$orderCount->execute([$u['id']]);
$orderCount = (int)($orderCount->fetchColumn());

$memberSince = date('F Y', strtotime($u['created_at']));

$pageTitle = t('My Profile — 237Biz', 'Mon Profil — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard', 'Tableau de bord') ?></a> ›
      <span><?= t('My Profile', 'Mon Profil') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.8rem,3vw,2.5rem);">
      👤 <?= t('My Profile', 'Mon Profil') ?>
    </h1>
  </div>
</div>

<section class="page-section">
  <div class="container">
    <div class="dashboard-grid">

      <!-- SIDEBAR -->
      <aside class="dash-sidebar">
        <!-- Avatar -->
        <div style="text-align:center;margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid var(--border);">
          <div style="width:72px;height:72px;border-radius:50%;background:var(--green);display:flex;align-items:center;justify-content:center;font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;color:#fff;margin:0 auto 0.75rem;">
            <?= strtoupper(mb_substr($u['name'], 0, 1)) ?>
          </div>
          <div style="font-family:'Fraunces',serif;font-weight:700;color:var(--white);font-size:1rem;"><?= e($u['name']) ?></div>
          <div style="font-size:0.75rem;color:var(--muted-2);margin-top:0.2rem;"><?= e($u['email']) ?></div>
          <span class="badge <?= $u['role']==='admin'?'badge-approved':'badge-pending' ?>" style="margin-top:0.5rem;display:inline-block;">
            <?= ucfirst($u['role']) ?>
          </span>
        </div>

        <!-- Stats -->
        <div style="display:flex;flex-direction:column;gap:0.75rem;margin-bottom:1.5rem;">
          <div style="display:flex;justify-content:space-between;font-size:0.82rem;">
            <span style="color:var(--muted);">📋 <?= t('Listings', 'Annonces') ?></span>
            <span style="color:var(--white);font-weight:500;"><?= $listingCount ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:0.82rem;">
            <span style="color:var(--muted);">🛒 <?= t('Orders', 'Commandes') ?></span>
            <span style="color:var(--white);font-weight:500;"><?= $orderCount ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:0.82rem;">
            <span style="color:var(--muted);">📅 <?= t('Member since', 'Membre depuis') ?></span>
            <span style="color:var(--white);font-weight:500;"><?= $memberSince ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:0.82rem;">
            <span style="color:var(--muted);">✉️ <?= t('Verified', 'Vérifié') ?></span>
            <span style="color:<?= $u['verified'] ? 'var(--green)' : '#ff6b6b' ?>;font-weight:500;">
              <?= $u['verified'] ? '✓ ' . t('Yes', 'Oui') : '✗ ' . t('No', 'Non') ?>
            </span>
          </div>
        </div>

        <!-- Nav -->
        <nav class="dash-nav">
          <a href="<?= SITE_URL ?>/dashboard">📋 <?= t('My Listings', 'Mes Annonces') ?></a>
          <a href="<?= SITE_URL ?>/add-listing">+ <?= t('Add Listing', 'Ajouter Annonce') ?></a>
          <a href="<?= SITE_URL ?>/profile.php" class="active">👤 <?= t('My Profile', 'Mon Profil') ?></a>
          <a href="<?= SITE_URL ?>/services"><?= t('Order Services', 'Commander Services') ?></a>
          <?php if (isAdmin()): ?>
            <a href="<?= SITE_URL ?>/admin/">🔧 <?= t('Admin Panel', 'Panneau Admin') ?></a>
          <?php endif; ?>
          <a href="<?= SITE_URL ?>/logout.php" style="margin-top:1rem;color:rgba(255,100,100,0.7);">⬅ <?= t('Sign Out', 'Déconnexion') ?></a>
        </nav>
      </aside>

      <!-- MAIN CONTENT -->
      <div>

        <!-- Error display -->
        <?php foreach ($errors as $err): ?>
          <div class="flash flash-error" style="margin-bottom:0.75rem;margin-top:0;"><?= e($err) ?></div>
        <?php endforeach; ?>

        <!-- UPDATE PROFILE -->
        <div class="listing-widget" style="margin-bottom:1.5rem;">
          <h4 style="margin-bottom:1.25rem;">✏️ <?= t('Personal Information', 'Informations Personnelles') ?></h4>
          <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Full Name *', 'Nom complet *') ?></label>
                <input type="text" name="name" value="<?= e($u['name']) ?>" required>
              </div>
              <div class="form-group">
                <label><?= t('Phone Number', 'Numéro de téléphone') ?></label>
                <input type="tel" name="phone" value="<?= e($u['phone'] ?? '') ?>" placeholder="+237 6XX XXX XXX">
              </div>
            </div>
            <div class="form-group">
              <label><?= t('Email Address', 'Adresse email') ?></label>
              <input type="email" value="<?= e($u['email']) ?>" disabled
                     style="opacity:0.5;cursor:not-allowed;">
              <small style="color:var(--muted-2);font-size:0.75rem;">
                <?= t('Email cannot be changed. Contact support if needed.', 'L\'email ne peut pas être modifié. Contactez le support si nécessaire.') ?>
              </small>
            </div>
            <button type="submit" class="btn btn-primary"><?= t('Save Changes', 'Enregistrer') ?></button>
          </form>
        </div>

        <!-- CHANGE PASSWORD -->
        <div class="listing-widget" style="margin-bottom:1.5rem;">
          <h4 style="margin-bottom:1.25rem;">🔑 <?= t('Change Password', 'Changer le mot de passe') ?></h4>
          <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
              <label><?= t('Current Password *', 'Mot de passe actuel *') ?></label>
              <input type="password" name="current_password" required autocomplete="current-password" placeholder="••••••••">
            </div>
            <div class="form-row">
              <div class="form-group">
                <label><?= t('New Password *', 'Nouveau mot de passe *') ?></label>
                <input type="password" name="new_password" required autocomplete="new-password"
                       placeholder="<?= t('Min. 8 chars, 1 uppercase, 1 number', 'Min. 8 car., 1 majuscule, 1 chiffre') ?>">
              </div>
              <div class="form-group">
                <label><?= t('Confirm New Password *', 'Confirmer le nouveau mot de passe *') ?></label>
                <input type="password" name="confirm_password" required autocomplete="new-password" placeholder="••••••••">
              </div>
            </div>
            <button type="submit" class="btn btn-primary"><?= t('Change Password', 'Changer le mot de passe') ?></button>
          </form>
        </div>

        <!-- ACCOUNT INFO -->
        <div class="listing-widget" style="background:rgba(255,255,255,0.02);">
          <h4 style="margin-bottom:1rem;">ℹ️ <?= t('Account Information', 'Informations du compte') ?></h4>
          <div style="display:flex;flex-direction:column;gap:0.6rem;">
            <div class="contact-item">
              <span class="contact-icon">🆔</span>
              <span><?= t('Account ID:', 'ID du compte :') ?> <span style="color:var(--white);font-family:monospace;">#<?= $u['id'] ?></span></span>
            </div>
            <div class="contact-item">
              <span class="contact-icon">📅</span>
              <span><?= t('Registered:', 'Inscrit le :') ?> <span style="color:var(--white);"><?= date('d F Y', strtotime($u['created_at'])) ?></span></span>
            </div>
            <div class="contact-item">
              <span class="contact-icon">✉️</span>
              <span><?= t('Email verified:', 'Email vérifié :') ?>
                <span style="color:<?= $u['verified'] ? 'var(--green)' : '#ff6b6b' ?>;">
                  <?= $u['verified'] ? '✓ ' . t('Verified', 'Vérifié') : '✗ ' . t('Not verified', 'Non vérifié') ?>
                </span>
              </span>
            </div>
            <?php if (!$u['verified']): ?>
              <div style="margin-top:0.5rem;">
                <a href="<?= SITE_URL ?>/resend-verify.php" class="btn btn-outline btn-sm">
                  📧 <?= t('Resend Verification Email', 'Renvoyer l\'email de vérification') ?>
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
