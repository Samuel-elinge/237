<?php
/**
 * join.php — 237Biz Join Page
 * Mobile-first: Tabs → Dynamic Description → Form
 * URL: /join  (optionally ?path=agent|creator|list|growth_partner)
 */
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) { redirect(SITE_URL . '/dashboard'); }

$defaultPath = in_array($_GET['path']??'', ['list','agent','creator','growth_partner']) ? $_GET['path'] : 'list';
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $path     = in_array($_POST['path']??'', ['list','agent','creator','growth_partner']) ? $_POST['path'] : 'list';
    $name     = trim($_POST['name']  ?? '');
    $email    = strtolower(trim($_POST['email']  ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (!$name)  $errors[] = t('Name is required.','Le nom est requis.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('Valid email required.','Email valide requis.');
    if (strlen($password) < 8) $errors[] = t('Password must be at least 8 characters.','Mot de passe min. 8 caractères.');

    if (!$errors) {
        $chk = db()->prepare("SELECT id FROM users WHERE email=?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $errors[] = t('Email already registered. Sign in instead.','Email déjà enregistré. Connectez-vous.');
        } else {
            $role = ['agent'=>'sales_staff','creator'=>'creator','list'=>'user','growth_partner'=>'growth_partner'][$path];
            $hash        = password_hash($password, PASSWORD_BCRYPT);
            $verifyToken = bin2hex(random_bytes(32));
            try {
                db()->prepare("INSERT INTO users (name,email,password,role,verified,verify_token,verify_token_created_at) VALUES (?,?,?,?,0,?,NOW())")
                    ->execute([$name, $email, $hash, $role, $verifyToken]);
            } catch (Exception $e) {
                db()->prepare("INSERT INTO users (name,email,password,role,verified,verify_token) VALUES (?,?,?,?,0,?)")
                    ->execute([$name, $email, $hash, $role, $verifyToken]);
            }
            $uid = (int)db()->lastInsertId();

            // Role-specific setup
            if ($role === 'creator') {
                try {
                    db()->prepare("INSERT INTO creator_profiles (user_id,status) VALUES (?,'pending')")->execute([$uid]);
                } catch(Exception $e) {}
            }
            if ($role === 'sales_staff') {
                try {
                    $base = strtolower(preg_replace('/[^a-z0-9]/i','',$name));
                    $base = substr($base,0,10) ?: 'agent';
                    $code = $base; $i=1;
                    while(true){
                        $c2 = db()->prepare("SELECT id FROM referral_links WHERE code=?");
                        $c2->execute([$code]);
                        if(!$c2->fetch()) break;
                        $code=$base.$i++;
                    }
                    db()->prepare("INSERT INTO referral_links (user_id,programme_id,code) VALUES (?,1,?)")->execute([$uid,$code]);
                } catch(Exception $e) {}
            }

            // Store path in session for complete-profile step
            $_SESSION['join_path'] = $path;
            $_SESSION['join_uid']  = $uid;

            // Verification email
            $verifyUrl  = SITE_URL . '/verify.php?token=' . $verifyToken;
            $verifyBody = '<h2 style="color:#fff;font-family:Georgia,serif;">'.t('Verify your email','Vérifiez votre email').'</h2>
              <p style="color:rgba(255,255,255,0.7);">'.t('Hi','Bonjour').' '.e($name).',</p>
              <p style="color:rgba(255,255,255,0.7);">'.t('Thanks for joining 237Biz! Click below to verify your email and activate your account.','Merci d\'avoir rejoint 237Biz ! Cliquez ci-dessous pour vérifier votre email et activer votre compte.').'</p>
              <a href="'.$verifyUrl.'" style="display:inline-block;margin:1.5rem 0;background:#00A878;color:#fff;padding:0.85rem 2rem;border-radius:5px;text-decoration:none;">'.t('Verify Email →','Vérifier Email →').'</a>
              <p style="color:rgba(255,255,255,0.5);font-size:0.8rem;">'.t('This link expires in 48 hours.','Ce lien expire dans 48 heures.').'</p>';
            try { sendMail($email, t('Verify your 237Biz account','Vérifiez votre compte 237Biz'), $verifyBody); } catch(Exception $e){}

            // Redirect to Complete Profile step
            redirect(SITE_URL . '/complete-profile.php');
            exit;
        }
    }
}

$pageTitle = t('Join 237Biz — Cameroon\'s Business Hub','Rejoindre 237Biz — Business Hub du Cameroun');
require_once __DIR__ . '/includes/header.php';

// Benefit panels per path — updated copy per spec
$benefits = [
    'list' => [
        'icon'    => '🪪',
        'title'   => t('Your Electronic Business Card','Votre Carte de Visite Électronique'),
        'color'   => '#fcd116',
        'tagline' => t('Get found by thousands of customers — start free, grow with paid features.','Soyez trouvé par des milliers de clients — commencez gratuitement, évoluez avec les fonctionnalités payantes.'),
        'items'   => [
            ['🆓', t('FREE — Your listing goes live within 24 hours','GRATUIT — Votre annonce est en ligne dans les 24h')],
            ['📱', t('WhatsApp button so customers reach you instantly','Bouton WhatsApp pour que les clients vous contactent')],
            ['📲', t('Downloadable QR code — print it, share it anywhere','QR code téléchargeable — imprimez-le, partagez-le')],
            ['📊', t('Analytics: see how many people view your listing','Analytiques : voyez combien de personnes visitent votre annonce')],
            ['⬆️', t('PAID: Appear higher in search results','PAYANT : Apparaître plus haut dans les résultats')],
            ['📅', t('PAID: Accept online appointment bookings','PAYANT : Acceptez les réservations en ligne')],
            ['📝', t('PAID: Longer description — tell your full story','PAYANT : Description longue — racontez toute votre histoire')],
            ['🛍️', t('PAID: Showcase your products & services with photos','PAYANT : Présentez vos produits & services avec photos')],
        ],
    ],
    'agent' => [
        'icon'    => '👔',
        'title'   => t('Become an Affiliate','Devenez Affilié'),
        'color'   => '#8ab4f8',
        'tagline' => t('Earn 10% commission by helping Cameroon businesses grow.','Gagnez 10% de commission en aidant les entreprises à grandir.'),
        'items'   => [
            ['💰', t('Earn 10% commission on every IT product sale (listings, websites, emails) you refer.','Gagnez 10% sur chaque vente de produit IT (annonces, sites, emails) que vous référencez.')],
            ['🔗', t('Your unique referral link — /r/yourname','Votre lien de parrainage unique — /r/votrenom')],
            ['📊', t('Dashboard: Track clicks, conversions, and earnings in real-time.','Tableau : Suivez vos clics, conversions et gains en temps réel.')],
            ['📋', t('Get leads assigned directly by admin.','Recevez des leads assignés directement par l\'admin.')],
            ['💸', t('Paid via MTN MoMo or Orange Money.','Payé via MTN MoMo ou Orange Money.')],
        ],
    ],
    'creator' => [
        'icon'    => '🎬',
        'title'   => t('Become a Creator','Devenez Créateur'),
        'color'   => '#e07be0',
        'tagline' => t('Create content. Share your link. Earn rewards.','Créez du contenu. Partagez votre lien. Gagnez des récompenses.'),
        'items'   => [
            ['💰', t('50,000 XAF Monthly Base + 15% Commission (Tiers up to 25%).','50 000 XAF de Base Mensuelle + 15% de Commission (jusqu\'à 25%).')],
            ['📱', t('Works for TikTok, Instagram, Facebook & YouTube.','Compatible TikTok, Instagram, Facebook & YouTube.')],
            ['🔗', t('Unique referral link to share anywhere.','Lien de parrainage unique à partager partout.')],
            ['📊', t('Track every click and conversion in real time.','Suivez chaque clic et conversion en temps réel.')],
            ['🎯', t('Join paid campaigns with content briefs.','Rejoignez des campagnes payantes avec des briefs créatifs.')],
            ['💸', t('Paid via MTN MoMo or Orange Money.','Payé via MTN MoMo ou Orange Money.')],
        ],
    ],
    'growth_partner' => [
        'icon'    => '🤝',
        'title'   => t('Become a Growth Partner','Devenez Partenaire de Croissance'),
        'color'   => '#00A878',
        'tagline' => t('Manage business accounts. Close deals. Earn the highest commissions.','Gérez des comptes entreprises. Concluez des deals. Gagnez les meilleures commissions.'),
        'items'   => [
            ['💰', t('20% - 25% Commission on all new orders (No base, pure upside).','20% - 25% de Commission sur toutes les nouvelles commandes (Sans base fixe, que du bonus).')],
            ['💼', t('Manage business accounts and place orders (websites, emails, listings) on their behalf.','Gérez des comptes entreprises et passez des commandes (sites, emails, annonces) pour leur compte.')],
            ['🔄', t('Earn 10% on all account renewals.','Gagnez 10% sur tous les renouvellements de comptes.')],
            ['📊', t('Real-time dashboard to track your managed accounts and earnings.','Tableau de bord en temps réel pour suivre vos comptes gérés et vos gains.')],
            ['💸', t('Paid via MTN MoMo or Orange Money.','Payé via MTN MoMo ou Orange Money.')],
        ],
    ],
];
?>

<style>
/* ── Join page — single-column, mobile-first ── */
.join-page {
  min-height: calc(100vh - 64px);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 40px 20px 60px;
  background: #07190d;
}

.join-inner {
  width: 100%;
  max-width: 520px;
}

/* Back link */
.join-back { font-size:13px; color:rgba(255,255,255,0.4); text-decoration:none; display:inline-block; margin-bottom:20px; transition:color .15s; }
.join-back:hover { color:rgba(255,255,255,0.75); }

/* Headline */
.join-title { font-family:'Fraunces',serif; font-weight:900; font-size:clamp(1.5rem,4vw,2rem); color:#fff; margin-bottom:4px; }
.join-sub   { color:rgba(255,255,255,0.45); font-size:14px; margin-bottom:24px; }

/* ── Path selector grid ── */
.path-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 20px;
}
.path-card {
  border: 2px solid rgba(255,255,255,0.1);
  border-radius: 12px;
  padding: 14px 12px;
  cursor: pointer;
  text-align: center;
  transition: all .2s;
  background: rgba(255,255,255,0.02);
  display: flex; flex-direction: column; align-items: center; gap: 5px;
}
.path-card:hover { border-color: rgba(255,255,255,0.3); background: rgba(255,255,255,0.04); }
.path-card.selected { color: #fff; }
.path-card .path-icon  { font-size: 1.5rem; }
.path-card .path-label { font-size: 11.5px; font-weight: 600; color: rgba(255,255,255,0.7); line-height: 1.3; }
.path-card.selected .path-label { color: #fff; }

/* ── Dynamic description panel ── */
.desc-panel {
  border-radius: 14px;
  background: linear-gradient(145deg,#08472F,#0e2f1a);
  padding: 24px 22px;
  margin-bottom: 24px;
  transition: all .25s ease;
}
.desc-icon  { font-size: 2rem; margin-bottom: 10px; }
.desc-bar   { height: 3px; border-radius: 2px; width: 40px; margin-bottom: 14px; }
.desc-title { font-family: 'Fraunces', serif; font-weight: 900; font-size: 1.25rem; color: #fff; margin-bottom: 6px; }
.desc-tag   { font-size: 13.5px; color: rgba(255,255,255,0.6); margin-bottom: 18px; line-height: 1.5; }
.desc-list  { display: flex; flex-direction: column; gap: 10px; }
.desc-item  { display: flex; align-items: flex-start; gap: 10px; }
.desc-item .di  { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
.desc-item .dt  { font-size: 13.5px; color: rgba(255,255,255,0.82); line-height: 1.45; }

/* PAID badge */
.bi-paid { font-size: 0.6rem; background: #fcd116; color: #1a1200; border-radius: 3px; padding: 1px 5px; font-weight: 800; vertical-align: middle; margin-right: 3px; letter-spacing: .02em; }
.desc-item.paid-feature .dt { color: rgba(255,255,255,0.55); }

/* Upsell nudge */
.desc-upsell {
  margin-top: 16px; padding: 12px 14px;
  background: rgba(252,209,22,0.08); border: 1px solid rgba(252,209,22,0.25);
  border-radius: 10px; font-size: 12.5px; color: rgba(255,255,255,0.7); line-height: 1.5;
  display: none;
}

/* ── Form ── */
.join-form-wrap { }
.join-field { margin-bottom: 15px; }
.join-field label { display: block; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.75); margin-bottom: 6px; }
.join-field input, .join-field textarea {
  width: 100%; padding: 12px 14px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 10px; color: #fff; font-size: 14px; font-family: inherit;
  transition: border-color .15s; box-sizing: border-box;
}
.join-field input:focus, .join-field textarea:focus {
  outline: none; border-color: rgba(0,168,120,0.5);
  background: rgba(255,255,255,0.07);
}
.join-submit {
  width: 100%; padding: 14px; border-radius: 10px;
  font-size: 15px; font-weight: 700; cursor: pointer;
  border: none; transition: all .2s; font-family: inherit; margin-top: 4px;
}
.join-footer { text-align: center; font-size: 13px; color: rgba(255,255,255,0.4); margin-top: 16px; }
.join-footer a { color: #00A878; }
.join-trust { text-align: center; font-size: 12px; color: rgba(255,255,255,0.3); margin-top: 20px; }
</style>

<div class="join-page">
  <div class="join-inner">

    <a href="<?= SITE_URL ?>/" class="join-back">← <?= t('Back to home','Retour à l\'accueil') ?></a>

    <h1 class="join-title"><?= t('Join 237Biz','Rejoindre 237Biz') ?></h1>
    <p class="join-sub"><?= t('Choose your path to get started','Choisissez votre parcours pour commencer') ?></p>

    <!-- 1. PATH SELECTOR TABS -->
    <div class="path-grid" id="path-grid">
      <?php
      $paths = [
          'list'           => ['🏪', t('List My Business','Lister mon Entreprise')],
          'agent'          => ['👔', t('Become an Affiliate','Devenir Affilié')],
          'creator'        => ['🎬', t('Become a Creator','Devenir Créateur')],
          'growth_partner' => ['🤝', t('Growth Partner','Partenaire de Croissance')],
      ];
      foreach ($paths as $key => [$icon, $label]):
      ?>
      <div class="path-card <?= $defaultPath===$key?'selected':'' ?>" data-path="<?= $key ?>" onclick="selectPath('<?= $key ?>')">
        <div class="path-icon"><?= $icon ?></div>
        <div class="path-label"><?= $label ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- 2. DYNAMIC DESCRIPTION PANEL -->
    <?php $b = $benefits[$defaultPath]; ?>
    <div class="desc-panel" id="desc-panel">
      <div class="desc-icon" id="d-icon"><?= $b['icon'] ?></div>
      <div class="desc-bar"  id="d-bar"  style="background:<?= $b['color'] ?>;"></div>
      <div class="desc-title" id="d-title"><?= $b['title'] ?></div>
      <div class="desc-tag"   id="d-tag"><?= $b['tagline'] ?></div>
      <div class="desc-list"  id="d-list">
        <?php foreach ($b['items'] as [$icon, $text]):
          $isPaid = str_starts_with($text, 'PAID') || str_starts_with($text, 'PAYANT');
          $cleanText = preg_replace('/^(PAID|PAYANT):\s*/','',$text);
        ?>
        <div class="desc-item<?= $isPaid ? ' paid-feature' : '' ?>">
          <span class="di"><?= $icon ?></span>
          <span class="dt"><?= $isPaid ? '<span class="bi-paid">PAID</span> ' : '' ?><?= $cleanText ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div id="d-upsell" class="desc-upsell" <?= $defaultPath === 'list' ? 'style="display:block"' : '' ?>>
        💡 <?= t('Start free — upgrade anytime to unlock paid features and get more customers.','Commencez gratuitement — passez à la version payante à tout moment pour débloquer plus de fonctionnalités.') ?>
      </div>
    </div>

    <!-- 3. SIGNUP FORM -->
    <?php foreach ($errors as $err): ?>
    <div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:8px;padding:10px 14px;font-size:13.5px;color:#ff6b7a;margin-bottom:14px;"><?= e($err) ?></div>
    <?php endforeach; ?>

    <div class="join-form-wrap">
      <form method="POST" id="join-form">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="path" id="path-input" value="<?= e($defaultPath) ?>">

        <div class="join-field">
          <label><?= t('Full Name','Nom complet') ?> *</label>
          <input type="text" name="name" required placeholder="<?= t('e.g. Jean-Paul Mbarga','ex. Jean-Paul Mbarga') ?>" value="<?= e($_POST['name'] ?? '') ?>">
        </div>
        <div class="join-field">
          <label><?= t('Email Address','Adresse e-mail') ?> *</label>
          <input type="email" name="email" required placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="join-field">
          <label><?= t('Password','Mot de passe') ?> * <span style="font-size:11px;color:rgba(255,255,255,0.3);font-weight:400;">(<?= t('min. 8 characters','min. 8 caractères') ?>)</span></label>
          <input type="password" name="password" required minlength="8" placeholder="••••••••">
        </div>

        <button type="submit" class="join-submit" id="join-btn" style="background:#00A878;color:#fff;">
          <?= t('Create My Account →','Créer mon compte →') ?>
        </button>
      </form>
    </div>

    <div class="join-footer">
      <?= t('Already have an account?','Déjà un compte ?') ?>
      <a href="<?= SITE_URL ?>/login"><?= t('Sign in','Connectez-vous') ?></a>
    </div>

    <div class="join-trust">🔒 <?= t('237Biz is free to join. No credit card required.','237Biz est gratuit. Aucune carte bancaire requise.') ?></div>

  </div>
</div>

<script>
var benefits = <?= json_encode($benefits, JSON_UNESCAPED_UNICODE) ?>;

var btnColors = {
  list:           '#c9a700',
  agent:          '#3d6fbf',
  creator:        '#9b4fad',
  growth_partner: '#00A878',
};
var btnLabels = {
  list:           '<?= t('Create Account & List My Business →','Créer un compte et lister →') ?>',
  agent:          '<?= t('Become an Affiliate →','Devenir Affilié →') ?>',
  creator:        '<?= t('Become a Creator →','Devenir Créateur →') ?>',
  growth_partner: '<?= t('Apply as Growth Partner →','Postuler comme Partenaire de Croissance →') ?>',
};

function selectPath(path) {
  document.getElementById('path-input').value = path;

  // Update card styles
  document.querySelectorAll('.path-card').forEach(function(c) {
    var isSelected = c.dataset.path === path;
    c.classList.toggle('selected', isSelected);
    c.style.borderColor = isSelected ? benefits[path].color : '';
    c.style.background  = isSelected ? 'rgba(255,255,255,0.05)' : '';
  });

  // Update description panel
  var b = benefits[path];
  document.getElementById('d-icon').textContent   = b.icon;
  document.getElementById('d-bar').style.background = b.color;
  document.getElementById('d-title').textContent  = b.title;
  document.getElementById('d-tag').textContent    = b.tagline;

  var list = document.getElementById('d-list');
  list.innerHTML = '';
  b.items.forEach(function(item) {
    var div = document.createElement('div');
    var isPaid = item[1].indexOf('PAID') === 0 || item[1].indexOf('PAYANT') === 0;
    div.className = 'desc-item' + (isPaid ? ' paid-feature' : '');
    var text  = item[1].replace(/^(PAID|PAYANT):\s*/,'');
    var badge = isPaid ? '<span class="bi-paid">PAID</span> ' : '';
    div.innerHTML = '<span class="di">'+item[0]+'</span><span class="dt">'+badge+text+'</span>';
    list.appendChild(div);
  });

  // Upsell nudge for list path only
  var upsell = document.getElementById('d-upsell');
  if (upsell) upsell.style.display = (path === 'list') ? 'block' : 'none';

  // Submit button
  var btn = document.getElementById('join-btn');
  btn.style.background = b.color;
  btn.textContent = btnLabels[path] || '<?= t('Create My Account →','Créer mon compte →') ?>';
}

// Init on load
selectPath('<?= e($defaultPath) ?>');
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
