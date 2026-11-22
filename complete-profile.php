<?php
/**
 * complete-profile.php — Post-signup profile completion
 * Step 2 after /join: captures phone + role-specific details
 * Required before email verification completes onboarding
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';

// Must have a fresh join session
$joinPath = $_SESSION['join_path'] ?? null;
$joinUid  = (int)($_SESSION['join_uid'] ?? 0);

if (!$joinPath || !$joinUid) {
    // No active join session — redirect to join or dashboard
    if (isLoggedIn()) {
        redirect(SITE_URL . '/dashboard');
    } else {
        redirect(SITE_URL . '/join');
    }
    exit;
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $phone = trim($_POST['phone'] ?? '');
    if (!$phone) $errors[] = t('Phone number is required for payouts.','Le numéro de téléphone est requis pour les paiements.');

    if (!$errors) {
        try {
            // Update phone on user record
            db()->prepare("UPDATE users SET phone=? WHERE id=?")
               ->execute([$phone, $joinUid]);

            // Role-specific extras
            if ($joinPath === 'creator') {
                $tiktok      = trim($_POST['tiktok'] ?? '');
                $instagram   = trim($_POST['instagram'] ?? '');
                $facebook    = trim($_POST['facebook'] ?? '');
                $youtube     = trim($_POST['youtube'] ?? '');
                $followers   = (int)($_POST['follower_count'] ?? 0);
                // Store in creator_profiles (upsert)
                try {
                    db()->prepare("UPDATE creator_profiles SET tiktok_handle=?, instagram_handle=?, facebook_handle=?, youtube_handle=?, follower_count=? WHERE user_id=?")
                       ->execute([$tiktok ?: null, $instagram ?: null, $facebook ?: null, $youtube ?: null, $followers ?: null, $joinUid]);
                } catch(Exception $e) {}
            }

            if ($joinPath === 'growth_partner') {
                $salesExp = trim($_POST['sales_experience'] ?? '');
                try {
                    // Insert or update partner_profiles with experience
                    $exists = db()->prepare("SELECT id FROM partner_profiles WHERE user_id=?");
                    $exists->execute([$joinUid]);
                    if ($exists->fetch()) {
                        db()->prepare("UPDATE partner_profiles SET experience=?, phone=?, status='pending' WHERE user_id=?")
                           ->execute([$salesExp ?: null, $phone, $joinUid]);
                    } else {
                        db()->prepare("INSERT INTO partner_profiles (user_id, status, phone, experience) VALUES (?,?,?,?)")
                           ->execute([$joinUid, 'pending', $phone, $salesExp ?: null]);
                    }
                } catch(Exception $e) {}
            }

            if ($joinPath === 'agent') {
                $shareMethod = trim($_POST['share_method'] ?? '');
                // Store on user meta or referral record note
                try {
                    db()->prepare("UPDATE referral_links SET notes=? WHERE user_id=?")
                       ->execute([$shareMethod ?: null, $joinUid]);
                } catch(Exception $e) {}
            }

            // Clear join session vars
            unset($_SESSION['join_path'], $_SESSION['join_uid']);

            flash('success', t(
                'Profile saved! Please check your email to verify your account before signing in.',
                'Profil enregistré ! Veuillez vérifier votre email pour activer votre compte avant de vous connecter.'
            ));
            redirect(SITE_URL . '/login.php?mode=login&pending_verify=1');
            exit;

        } catch (Exception $e) {
            $errors[] = t('Something went wrong. Please try again.','Une erreur s\'est produite. Veuillez réessayer.');
        }
    }
}

// Role display info
$roleInfo = [
    'list'           => ['🏪', t('Business Owner','Propriétaire d\'entreprise'), '#fcd116'],
    'agent'          => ['👔', t('Affiliate','Affilié'), '#8ab4f8'],
    'creator'        => ['🎬', t('Creator','Créateur'), '#e07be0'],
    'growth_partner' => ['🤝', t('Growth Partner','Partenaire de Croissance'), '#00A878'],
];
$ri = $roleInfo[$joinPath] ?? ['✨', 'Member', '#00A878'];

$pageTitle = t('Complete Your Profile — 237Biz','Complétez votre profil — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<style>
.cp-page {
  min-height: calc(100vh - 64px);
  display: flex; align-items: flex-start; justify-content: center;
  padding: 40px 20px 60px;
  background: #07190d;
}
.cp-inner { width: 100%; max-width: 480px; }

.cp-step-badge {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
  border-radius: 20px; padding: 6px 14px; font-size: 12px;
  color: rgba(255,255,255,0.5); margin-bottom: 20px;
}
.cp-step-badge .step-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: <?= $ri[2] ?>;
}

.cp-title { font-family: 'Fraunces', serif; font-weight: 900; font-size: clamp(1.4rem,4vw,1.8rem); color: #fff; margin-bottom: 6px; }
.cp-sub   { font-size: 14px; color: rgba(255,255,255,0.45); margin-bottom: 28px; }

.cp-role-tag {
  display: inline-flex; align-items: center; gap: 7px;
  background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px; padding: 7px 12px; margin-bottom: 24px;
  font-size: 13px; color: rgba(255,255,255,0.75); font-weight: 600;
}

.cp-section-label {
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: <?= $ri[2] ?>;
  margin: 22px 0 12px;
}

.cp-field { margin-bottom: 14px; }
.cp-field label { display: block; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.75); margin-bottom: 6px; }
.cp-field input, .cp-field textarea, .cp-field select {
  width: 100%; padding: 12px 14px;
  background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
  border-radius: 10px; color: #fff; font-size: 14px; font-family: inherit;
  transition: border-color .15s; box-sizing: border-box;
}
.cp-field input:focus, .cp-field textarea:focus, .cp-field select:focus {
  outline: none; border-color: rgba(0,168,120,0.5); background: rgba(255,255,255,0.07);
}
.cp-field .input-prefix {
  position: relative;
}
.cp-field .input-prefix span {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  color: rgba(255,255,255,0.4); font-size: 14px; pointer-events: none;
}
.cp-field .input-prefix input { padding-left: 28px; }

.cp-hint { font-size: 12px; color: rgba(255,255,255,0.3); margin-top: 5px; }

.cp-submit {
  width: 100%; padding: 14px; border-radius: 10px;
  font-size: 15px; font-weight: 700; cursor: pointer; border: none;
  background: <?= $ri[2] ?>; color: <?= $joinPath === 'list' ? '#1a1200' : '#fff' ?>;
  transition: opacity .2s; font-family: inherit; margin-top: 8px;
}
.cp-submit:hover { opacity: .88; }

.cp-skip {
  display: block; text-align: center; margin-top: 14px;
  font-size: 13px; color: rgba(255,255,255,0.3); text-decoration: none;
  transition: color .15s;
}
.cp-skip:hover { color: rgba(255,255,255,0.6); }

.cp-progress {
  display: flex; gap: 6px; margin-bottom: 28px;
}
.cp-progress span {
  flex: 1; height: 3px; border-radius: 2px;
  background: rgba(255,255,255,0.1);
}
.cp-progress span.done { background: <?= $ri[2] ?>; }
</style>

<div class="cp-page">
  <div class="cp-inner">

    <!-- Progress -->
    <div class="cp-progress">
      <span class="done"></span>
      <span class="done"></span>
      <span></span>
    </div>

    <div class="cp-step-badge">
      <span class="step-dot"></span>
      <?= t('Step 2 of 3 — Complete Your Profile','Étape 2 sur 3 — Complétez votre profil') ?>
    </div>

    <h1 class="cp-title"><?= t('One more step','Encore une étape') ?></h1>
    <p class="cp-sub"><?= t('We need a few more details to set up your account and process payouts.','Il nous faut quelques détails supplémentaires pour configurer votre compte et traiter vos paiements.') ?></p>

    <div class="cp-role-tag">
      <?= $ri[0] ?> <?= t('Joining as','Vous rejoignez en tant que') ?>: <strong><?= $ri[1] ?></strong>
    </div>

    <?php foreach ($errors as $err): ?>
    <div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:8px;padding:10px 14px;font-size:13.5px;color:#ff6b7a;margin-bottom:14px;"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="POST" id="cp-form">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">

      <!-- Phone — ALL roles -->
      <div class="cp-section-label"><?= t('Payout Details','Détails de paiement') ?></div>
      <div class="cp-field">
        <label><?= t('Phone Number','Numéro de téléphone') ?> * <span style="font-size:11px;color:rgba(255,255,255,0.3);font-weight:400;"><?= t('(MTN MoMo / Orange Money)','(MTN MoMo / Orange Money)') ?></span></label>
        <input type="tel" name="phone" required placeholder="+237 6XX XXX XXX" value="<?= e($_POST['phone'] ?? '') ?>">
        <p class="cp-hint"><?= t('Used to send your earnings via Mobile Money.','Utilisé pour envoyer vos gains via Mobile Money.') ?></p>
      </div>

      <?php if ($joinPath === 'creator'): ?>
      <!-- Creator: social handles + follower count -->
      <div class="cp-section-label"><?= t('Your Social Media','Vos Réseaux Sociaux') ?></div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
        <div class="cp-field">
          <label><?= t('TikTok','TikTok') ?></label>
          <div class="input-prefix" style="position:relative;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,0.3);font-size:13px;">@</span>
            <input type="text" name="tiktok" placeholder="username" value="<?= e($_POST['tiktok'] ?? '') ?>" style="padding-left:26px;">
          </div>
        </div>
        <div class="cp-field">
          <label><?= t('Instagram','Instagram') ?></label>
          <div class="input-prefix" style="position:relative;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,0.3);font-size:13px;">@</span>
            <input type="text" name="instagram" placeholder="username" value="<?= e($_POST['instagram'] ?? '') ?>" style="padding-left:26px;">
          </div>
        </div>
        <div class="cp-field">
          <label><?= t('Facebook','Facebook') ?></label>
          <div class="input-prefix" style="position:relative;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,0.3);font-size:13px;">@</span>
            <input type="text" name="facebook" placeholder="page or username" value="<?= e($_POST['facebook'] ?? '') ?>" style="padding-left:26px;">
          </div>
        </div>
        <div class="cp-field">
          <label><?= t('YouTube','YouTube') ?></label>
          <div class="input-prefix" style="position:relative;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,0.3);font-size:13px;">@</span>
            <input type="text" name="youtube" placeholder="channel" value="<?= e($_POST['youtube'] ?? '') ?>" style="padding-left:26px;">
          </div>
        </div>
      </div>
      <div class="cp-field">
        <label><?= t('Total Follower Count (across all platforms)','Nombre total d\'abonnés (toutes plateformes)') ?></label>
        <input type="number" name="follower_count" min="0" placeholder="<?= t('e.g. 5000','ex. 5000') ?>" value="<?= e($_POST['follower_count'] ?? '') ?>">
        <p class="cp-hint"><?= t('Approximate total — helps us match you to the right campaigns.','Total approximatif — nous aide à vous trouver les bonnes campagnes.') ?></p>
      </div>

      <?php elseif ($joinPath === 'growth_partner'): ?>
      <!-- Growth Partner: sales experience -->
      <div class="cp-section-label"><?= t('Your Background','Votre Parcours') ?></div>
      <div class="cp-field">
        <label><?= t('Describe your sales experience or business network','Décrivez votre expérience commerciale ou réseau d\'affaires') ?></label>
        <textarea name="sales_experience" rows="4" placeholder="<?= t('e.g. I\'ve worked in sales for 3 years, manage a network of 200+ SMEs in Douala and Bafoussam…','ex. J\'ai travaillé dans la vente pendant 3 ans, je gère un réseau de 200+ PME à Douala et Bafoussam…') ?>"><?= e($_POST['sales_experience'] ?? '') ?></textarea>
        <p class="cp-hint"><?= t('Help us understand how you\'ll bring new clients to 237Biz.','Aidez-nous à comprendre comment vous allez amener de nouveaux clients à 237Biz.') ?></p>
      </div>

      <?php elseif ($joinPath === 'agent'): ?>
      <!-- Affiliate: how will you share your link? -->
      <div class="cp-section-label"><?= t('Your Outreach Plan','Votre Plan de Partage') ?></div>
      <div class="cp-field">
        <label><?= t('How do you plan to share your referral link?','Comment comptez-vous partager votre lien de parrainage ?') ?></label>
        <textarea name="share_method" rows="3" placeholder="<?= t('e.g. WhatsApp groups, Facebook page, word of mouth at markets…','ex. Groupes WhatsApp, page Facebook, bouche à oreille sur les marchés…') ?>"><?= e($_POST['share_method'] ?? '') ?></textarea>
        <p class="cp-hint"><?= t('Optional — helps us assign the right leads to you.','Optionnel — nous aide à vous assigner les bons leads.') ?></p>
      </div>

      <?php endif; ?>

      <button type="submit" class="cp-submit">
        <?php if ($joinPath === 'growth_partner'): ?>
          <?= t('Submit Application →','Soumettre ma candidature →') ?>
        <?php elseif ($joinPath === 'list'): ?>
          <?= t('Save & Continue →','Enregistrer & Continuer →') ?>
        <?php else: ?>
          <?= t('Complete Setup →','Terminer la configuration →') ?>
        <?php endif; ?>
      </button>

    </form>

    <a href="<?= SITE_URL ?>/login.php?mode=login&pending_verify=1" class="cp-skip">
      <?= t('Skip for now — I\'ll complete later','Passer pour l\'instant — Je compléterai plus tard') ?>
    </a>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
