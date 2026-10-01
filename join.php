<?php
/**
 * join.php — 237Biz Join Page
 * Split-screen: left = path selector + form, right = live benefit panel
 * URL: /join  (optionally ?path=agent|creator|list|find)
 */
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) { redirect(SITE_URL . '/dashboard'); }

$defaultPath = in_array($_GET['path']??'', ['find','list','agent','creator']) ? $_GET['path'] : 'find';
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $path     = in_array($_POST['path']??'', ['find','list','agent','creator']) ? $_POST['path'] : 'find';
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
            $role = ['agent'=>'sales_staff','creator'=>'creator','list'=>'user','find'=>'user'][$path];
            $hash = password_hash($password, PASSWORD_BCRYPT);
            db()->prepare("INSERT INTO users (name,email,password,role,verified) VALUES (?,?,?,?,1)")
                ->execute([$name, $email, $hash, $role]);
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

            // Log them in
            $_SESSION['user_id'] = $uid;

            // Welcome email
            $welcomeBody = '<h2 style="color:#fff;font-family:Georgia,serif;">'.t('Welcome to 237Biz!','Bienvenue sur 237Biz !').'</h2>
              <p style="color:rgba(255,255,255,0.7);">'.t('Hi','Bonjour').' '.e($name).',</p>
              <p style="color:rgba(255,255,255,0.7);">'.t('Your account has been created. You can now access your dashboard.','Votre compte a été créé.').'</p>
              <a href="'.SITE_URL.'/dashboard" style="display:inline-block;margin:1rem 0;background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;">'.t('Go to Dashboard →','Aller au Tableau de bord →').'</a>';
            try { sendMail($email, t('Welcome to 237Biz','Bienvenue sur 237Biz'), $welcomeBody); } catch(Exception $e){}

            // Redirect based on path
            $destinations = [
                'agent'   => SITE_URL . '/agent/dashboard',
                'creator' => SITE_URL . '/creator/profile?setup=1',
                'list'    => SITE_URL . '/add-listing',
                'find'    => SITE_URL . '/listings',
            ];
            // ── Referral attribution ────────────────────────────────────────
            // If the new user arrived via a partner referral link, credit it now
            if (file_exists(__DIR__ . '/includes/referral-helpers.php')) {
                require_once __DIR__ . '/includes/referral-helpers.php';
                // Attribute as free_listing signup (the user just joined, no listing yet)
                // The listing conversion fires separately when they add a listing
                // But we track the registration so the agent sees click→signup
                if (function_exists('attributeReferralConversion')) {
                    attributeReferralConversion(0, $uid, 'free_listing');
                }
            }
            // ─────────────────────────────────────────────────────────────────

            flash('success', t('Welcome to 237Biz! Your account is ready.','Bienvenue ! Votre compte est prêt.'));
            redirect($destinations[$path]);
        }
    }
}

$pageTitle = t('Join 237Biz — Cameroon\'s Business Hub','Rejoindre 237Biz — Business Hub du Cameroun');
require_once __DIR__ . '/includes/header.php';

// Benefit panels per path
$benefits = [
    'find' => [
        'icon'    => '🔍',
        'title'   => t('Find Businesses Near You','Trouvez des entreprises près de vous'),
        'color'   => '#00A878',
        'tagline' => t('Discover the best of Cameroon business.','Découvrez le meilleur des entreprises camerounaises.'),
        'items'   => [
            ['🏪', t('Browse 1,000+ Cameroonian businesses','Parcourez +1000 entreprises camerounaises')],
            ['📍', t('Find businesses in your city','Trouvez des entreprises dans votre ville')],
            ['⭐', t('Read verified customer reviews','Lisez des avis clients vérifiés')],
            ['💬', t('Contact businesses via WhatsApp','Contactez via WhatsApp')],
            ['📅', t('Book appointments instantly','Réservez des rendez-vous instantanément')],
            ['🆓', t('Completely free — no subscription','Totalement gratuit')],
        ],
    ],
    'list' => [
        'icon'    => '🏪',
        'title'   => t('List Your Business','Listez votre Entreprise'),
        'color'   => '#fcd116',
        'tagline' => t('Put your business in front of thousands of customers.','Mettez votre entreprise devant des milliers de clients.'),
        'items'   => [
            ['✅', t('Free listing — published within 24h','Annonce gratuite — publiée dans les 24h')],
            ['📊', t('Analytics dashboard to track views','Tableau analytique pour suivre vos vues')],
            ['📅', t('Accept online appointment bookings','Acceptez les prises de rendez-vous')],
            ['📢', t('Post promotions and announcements','Publiez des promotions et annonces')],
            ['📱', t('WhatsApp chat widget on your listing','Widget WhatsApp sur votre annonce')],
            ['📱', t('Downloadable QR code for your listing','Code QR téléchargeable')],
        ],
    ],
    'agent' => [
        'icon'    => '👔',
        'title'   => t('Become a Sales Agent','Devenez Agent de Vente'),
        'color'   => '#8ab4f8',
        'tagline' => t('Earn commission by helping Cameroon businesses grow.','Gagnez des commissions en aidant les entreprises à grandir.'),
        'items'   => [
            ['💰', t('Earn commission per featured listing referral','Gagnez des commissions par parrainage')],
            ['🔗', t('Your unique referral link — /r/yourname','Lien de parrainage unique')],
            ['📊', t('Dashboard: clicks, conversions, earnings','Tableau : clics, conversions, gains')],
            ['📋', t('Get leads assigned directly by admin','Recevez des leads assignés par l\'admin')],
            ['📅', t('Track follow-ups with built-in CRM','Suivez vos relances avec CRM intégré')],
            ['💸', t('Paid via MTN MoMo or Orange Money','Payé via MTN MoMo ou Orange Money')],
        ],
    ],
    'creator' => [
        'icon'    => '🎬',
        'title'   => t('Become a Creator','Devenez Créateur'),
        'color'   => '#e07be0',
        'tagline' => t('Create content. Share your link. Earn rewards.','Créez du contenu. Partagez votre lien. Gagnez des récompenses.'),
        'items'   => [
            ['🎵', t('Works for TikTok, Instagram, Facebook & YouTube','TikTok, Instagram, Facebook & YouTube')],
            ['🔗', t('Unique referral link to share anywhere','Lien de parrainage unique à partager')],
            ['📊', t('Track every click and conversion in real time','Suivez chaque clic et conversion')],
            ['🎯', t('Join paid campaigns with content briefs','Rejoignez des campagnes payantes')],
            ['💰', t('Earn per featured listing signup via your link','Gagnez par inscription vedette')],
            ['🏆', t('Top performer bonuses on campaigns','Bonus top performer sur les campagnes')],
        ],
    ],
];
?>

<style>
.join-wrap { display:grid; grid-template-columns:1fr 1fr; min-height:calc(100vh - 64px); }
.join-left  { padding:48px 48px; display:flex; flex-direction:column; justify-content:center; max-width:540px; margin:0 auto; width:100%; }
.join-right { position:sticky; top:64px; height:calc(100vh - 64px); overflow:hidden;
              background:linear-gradient(145deg,#08472F,#0e2f1a);
              display:flex; flex-direction:column; justify-content:center; padding:48px; }

/* Path selector */
.path-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:28px; }
.path-card {
  border:2px solid rgba(255,255,255,0.1); border-radius:12px;
  padding:14px 16px; cursor:pointer; text-align:center;
  transition:all .2s; background:rgba(255,255,255,0.02);
  font-size:13px; color:rgba(255,255,255,0.7); font-weight:600;
  display:flex; flex-direction:column; align-items:center; gap:4px;
}
.path-card:hover { border-color:rgba(255,255,255,0.3); color:#fff; background:rgba(255,255,255,0.04); }
.path-card.selected { color:#fff; }
.path-card .path-icon { font-size:1.5rem; }
.path-card .path-label { font-size:12px; }

/* Right panel */
.benefit-icon  { font-size:3rem; margin-bottom:16px; }
.benefit-title { font-family:'Fraunces',serif; font-weight:900; font-size:clamp(1.5rem,2.5vw,2rem); color:#fff; margin-bottom:8px; }
.benefit-tag   { font-size:14px; color:rgba(255,255,255,0.65); margin-bottom:28px; line-height:1.5; }
.benefit-list  { display:flex; flex-direction:column; gap:12px; }
.benefit-item  { display:flex; align-items:flex-start; gap:12px; }
.benefit-item .bi { font-size:1.1rem; flex-shrink:0; margin-top:1px; }
.benefit-item .bt { font-size:14px; color:rgba(255,255,255,0.85); line-height:1.45; }
.benefit-bar   { height:3px; border-radius:2px; margin-bottom:28px; width:48px; }

/* Form */
.join-title { font-family:'Fraunces',serif; font-weight:900; font-size:1.7rem; margin-bottom:6px; }
.join-sub   { color:var(--muted); font-size:14px; margin-bottom:28px; }
.join-field { margin-bottom:16px; }
.join-field label { display:block; font-size:13px; font-weight:600; color:rgba(255,255,255,0.75); margin-bottom:6px; }
.join-field input { width:100%; padding:12px 14px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:10px; color:#fff; font-size:14px; font-family:inherit; transition:border-color .15s; }
.join-field input:focus { outline:none; border-color:rgba(0,168,120,0.5); background:rgba(255,255,255,0.07); }
.join-submit { width:100%; padding:14px; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; border:none; transition:all .2s; font-family:inherit; margin-top:6px; }
.join-footer { text-align:center; font-size:13px; color:var(--muted); margin-top:16px; }
.join-footer a { color:#00A878; }

@media(max-width:860px){
  .join-wrap  { grid-template-columns:1fr; }
  .join-right { position:static; height:auto; padding:32px 24px; }
  .join-left  { padding:32px 24px; }
}
</style>

<div class="join-wrap">

  <!-- LEFT: Form -->
  <div class="join-left">

    <div style="margin-bottom:6px;">
      <a href="<?= SITE_URL ?>/" style="font-size:13px;color:var(--muted);text-decoration:none;">← <?= t('Back to home','Retour à l\'accueil') ?></a>
    </div>

    <h1 class="join-title"><?= t('Join 237Biz','Rejoindre 237Biz') ?></h1>
    <p class="join-sub"><?= t('Choose your path to get started','Choisissez votre parcours pour commencer') ?></p>

    <!-- Path selector -->
    <div class="path-grid" id="path-grid">
      <?php
      $paths = [
          'find'    => ['🔍', t('Find Businesses','Trouver des entreprises')],
          'list'    => ['🏪', t('List My Business','Lister mon Entreprise')],
          'agent'   => ['👔', t('Become an Agent','Devenir Agent')],
          'creator' => ['🎬', t('Become a Creator','Devenir Créateur')],
      ];
      foreach ($paths as $key => [$icon, $label]):
      ?>
      <div class="path-card <?= $defaultPath===$key?'selected':'' ?>" data-path="<?= $key ?>" onclick="selectPath('<?= $key ?>')">
        <div class="path-icon"><?= $icon ?></div>
        <div class="path-label"><?= $label ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Errors -->
    <?php foreach ($errors as $err): ?>
    <div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:8px;padding:10px 14px;font-size:13.5px;color:#ff6b7a;margin-bottom:14px;"><?= e($err) ?></div>
    <?php endforeach; ?>

    <!-- Registration form -->
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
        <label><?= t('Password','Mot de passe') ?> * <span style="font-size:11px;color:var(--muted);font-weight:400;">(<?= t('min. 8 characters','min. 8 caractères') ?>)</span></label>
        <input type="password" name="password" required minlength="8" placeholder="••••••••">
      </div>

      <button type="submit" class="join-submit" id="join-btn"
              style="background:#00A878;color:#fff;">
        <?= t('Create My Account →','Créer mon compte →') ?>
      </button>
    </form>

    <div class="join-footer">
      <?= t('Already have an account?','Déjà un compte ?') ?>
      <a href="<?= SITE_URL ?>/login"><?= t('Sign in','Connectez-vous') ?></a>
    </div>

  </div>

  <!-- RIGHT: Benefit panel -->
  <div class="join-right" id="benefit-panel">
    <?php $b = $benefits[$defaultPath]; ?>
    <div class="benefit-icon" id="b-icon"><?= $b['icon'] ?></div>
    <div class="benefit-bar"  id="b-bar"  style="background:<?= $b['color'] ?>;"></div>
    <div class="benefit-title" id="b-title"><?= $b['title'] ?></div>
    <div class="benefit-tag"   id="b-tag"><?= $b['tagline'] ?></div>
    <div class="benefit-list"  id="b-list">
      <?php foreach ($b['items'] as [$icon, $text]): ?>
      <div class="benefit-item">
        <span class="bi"><?= $icon ?></span>
        <span class="bt"><?= $text ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:32px;padding-top:24px;border-top:1px solid rgba(255,255,255,0.1);">
      <div style="font-size:12px;color:rgba(255,255,255,0.4);">
        🔒 <?= t('237Biz is free to join. No credit card required.','237Biz est gratuit. Aucune carte bancaire requise.') ?>
      </div>
    </div>
  </div>

</div>

<script>
// All benefit data injected from PHP
var benefits = <?= json_encode($benefits, JSON_UNESCAPED_UNICODE) ?>;

var btnColors = {
  find:    '#00A878',
  list:    '#c9a700',
  agent:   '#3d6fbf',
  creator: '#9b4fad',
};
var btnLabels = {
  find:    '<?= t('Find Businesses →','Trouver des entreprises →') ?>',
  list:    '<?= t('Create Account & List Business →','Créer un compte et lister →') ?>',
  agent:   '<?= t('Become a Sales Agent →','Devenir Agent de Vente →') ?>',
  creator: '<?= t('Become a Creator →','Devenir Créateur →') ?>',
};

function selectPath(path) {
  // Update hidden input
  document.getElementById('path-input').value = path;

  // Update card selected state
  document.querySelectorAll('.path-card').forEach(function(c) {
    var isSelected = c.dataset.path === path;
    c.classList.toggle('selected', isSelected);
    c.style.borderColor = isSelected ? benefits[path].color : '';
    c.style.background  = isSelected ? 'rgba(255,255,255,0.05)' : '';
    c.style.color       = isSelected ? '#fff' : '';
  });

  // Update right panel
  var b = benefits[path];
  document.getElementById('b-icon').textContent  = b.icon;
  document.getElementById('b-bar').style.background = b.color;
  document.getElementById('b-title').textContent = b.title;
  document.getElementById('b-tag').textContent   = b.tagline;

  var list = document.getElementById('b-list');
  list.innerHTML = '';
  b.items.forEach(function(item) {
    var div = document.createElement('div');
    div.className = 'benefit-item';
    div.innerHTML = '<span class="bi">'+item[0]+'</span><span class="bt">'+item[1]+'</span>';
    list.appendChild(div);
  });

  // Update submit button
  var btn = document.getElementById('join-btn');
  btn.style.background = b.color;
  btn.textContent = btnLabels[path] || '<?= t('Create My Account →','Créer mon compte →') ?>';
}

// Init
selectPath('<?= e($defaultPath) ?>');
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
