<?php
require_once __DIR__ . '/includes/config.php';
$isFr = lang() === 'fr';
$pageTitle = $isFr ? 'Politique de Confidentialité — 237Biz' : 'Privacy Policy — 237Biz';
$pageDesc  = $isFr ? 'Comment 237Biz collecte, utilise et protège vos données personnelles.' : 'How 237Biz collects, uses and protects your personal data.';
require_once __DIR__ . '/includes/header.php';
$updated = 'January 2025';
?>
<style>
.legal-wrap { max-width:820px; margin:0 auto; padding:60px 24px 80px; }
.legal-wrap h1 { font-family:'Fraunces',serif; font-weight:900; font-size:clamp(1.8rem,4vw,2.5rem); margin-bottom:8px; }
.legal-meta { font-size:13px; color:rgba(255,255,255,0.4); margin-bottom:40px; }
.legal-wrap h2 { font-family:'Fraunces',serif; font-weight:700; font-size:1.15rem; color:#fff; margin:36px 0 10px; }
.legal-wrap p, .legal-wrap li { font-size:14.5px; color:rgba(255,255,255,0.7); line-height:1.8; }
.legal-wrap ul { padding-left:20px; margin:10px 0; display:flex; flex-direction:column; gap:6px; }
.legal-wrap a { color:#00A878; }
.legal-divider { height:1px; background:rgba(255,255,255,0.07); margin:28px 0; }
</style>

<div class="legal-wrap">
  <div style="margin-bottom:12px;"><a href="<?= SITE_URL ?>/" style="font-size:13px;color:rgba(255,255,255,0.45);text-decoration:none;">← <?= t('Home','Accueil') ?></a></div>
  <h1><?= t('Privacy Policy','Politique de Confidentialité') ?></h1>
  <div class="legal-meta"><?= t('Last updated','Dernière mise à jour') ?>: <?= $updated ?> · 237Biz.net</div>

  <p><?= t('237Biz ("we", "us", "our") operates 237biz.net. This Privacy Policy explains how we collect, use, store and protect your personal information when you use our platform.','237Biz (« nous ») exploite 237biz.net. Cette politique explique comment nous collectons, utilisons, stockons et protégeons vos informations personnelles lorsque vous utilisez notre plateforme.') ?></p>

  <div class="legal-divider"></div>

  <h2>1. <?= t('Information We Collect','Informations que nous collectons') ?></h2>
  <p><?= t('We collect information you provide directly:','Nous collectons les informations que vous fournissez directement :') ?></p>
  <ul>
    <li><?= t('Account information: name, email address, phone number and password when you register','Informations de compte : nom, email, numéro de téléphone et mot de passe lors de l\'inscription') ?></li>
    <li><?= t('Business listing information: business name, description, address, contact details, photos and logo','Informations d\'annonce : nom, description, adresse, coordonnées, photos et logo de l\'entreprise') ?></li>
    <li><?= t('Reviews you submit about businesses on our platform','Avis que vous soumettez sur les entreprises de notre plateforme') ?></li>
    <li><?= t('Booking and appointment information when you use our booking system','Informations de réservation lorsque vous utilisez notre système de prise de rendez-vous') ?></li>
    <li><?= t('Payment proof screenshots when upgrading to a featured listing','Captures d\'écran de paiement lors du passage à une annonce vedette') ?></li>
    <li><?= t('Communications you send to us via our contact page or email','Communications que vous nous envoyez via notre page de contact ou par email') ?></li>
  </ul>
  <p><?= t('We also collect information automatically when you use 237Biz:','Nous collectons également des informations automatiquement lorsque vous utilisez 237Biz :') ?></p>
  <ul>
    <li><?= t('Log data: IP address, browser type, pages visited, time and date of visits','Données de journal : adresse IP, type de navigateur, pages visitées, heure et date des visites') ?></li>
    <li><?= t('Cookies and similar tracking technologies (see Section 5)','Cookies et technologies de suivi similaires (voir Section 5)') ?></li>
    <li><?= t('Referral tracking: if you arrived via a partner referral link, we track this for commission attribution','Suivi des parrainages : si vous êtes arrivé via un lien de parrainage partenaire, nous suivons cela pour l\'attribution des commissions') ?></li>
  </ul>

  <div class="legal-divider"></div>

  <h2>2. <?= t('How We Use Your Information','Comment nous utilisons vos informations') ?></h2>
  <ul>
    <li><?= t('To operate and provide the 237Biz platform and its features','Pour exploiter et fournir la plateforme 237Biz et ses fonctionnalités') ?></li>
    <li><?= t('To create and manage your account and business listings','Pour créer et gérer votre compte et vos annonces') ?></li>
    <li><?= t('To process and verify payments for featured listings','Pour traiter et vérifier les paiements pour les annonces vedettes') ?></li>
    <li><?= t('To send email verification links, booking confirmations and important account notices','Pour envoyer des liens de vérification, des confirmations de réservation et des avis importants') ?></li>
    <li><?= t('To calculate and track referral commissions for Sales Agents and Content Creators','Pour calculer et suivre les commissions de parrainage pour les Agents de Vente et Créateurs de Contenu') ?></li>
    <li><?= t('To improve and develop our services','Pour améliorer et développer nos services') ?></li>
    <li><?= t('To comply with legal obligations','Pour respecter nos obligations légales') ?></li>
  </ul>

  <div class="legal-divider"></div>

  <h2>3. <?= t('Sharing Your Information','Partage de vos informations') ?></h2>
  <p><?= t('We do not sell your personal information. We may share information in the following limited circumstances:','Nous ne vendons pas vos informations personnelles. Nous pouvons partager des informations dans les circonstances limitées suivantes :') ?></p>
  <ul>
    <li><?= t('Business listing information is publicly visible to any visitor on 237Biz as intended','Les informations d\'annonce commerciale sont publiquement visibles par tout visiteur sur 237Biz') ?></li>
    <li><?= t('With service providers who help us operate the platform (hosting, email delivery) under strict data protection agreements','Avec les prestataires de services qui nous aident à exploiter la plateforme (hébergement, livraison d\'emails)') ?></li>
    <li><?= t('With law enforcement or government authorities when required by law','Avec les forces de l\'ordre ou les autorités gouvernementales lorsque la loi l\'exige') ?></li>
  </ul>

  <div class="legal-divider"></div>

  <h2>4. <?= t('Data Retention','Conservation des données') ?></h2>
  <p><?= t('We retain your personal data for as long as your account is active or as needed to provide services. If you delete your account, we will delete your personal data within 30 days, except where we are required to retain it for legal or financial compliance purposes.','Nous conservons vos données personnelles aussi longtemps que votre compte est actif ou selon les besoins pour fournir des services. Si vous supprimez votre compte, nous supprimerons vos données personnelles dans les 30 jours.') ?></p>

  <div class="legal-divider"></div>

  <h2>5. <?= t('Cookies','Cookies') ?></h2>
  <p><?= t('We use the following cookies:','Nous utilisons les cookies suivants :') ?></p>
  <ul>
    <li><strong><?= t('Session cookies','Cookies de session') ?>:</strong> <?= t('Required for login and platform functionality. Expire when you close your browser.','Requis pour la connexion et les fonctionnalités de la plateforme. Expirent lorsque vous fermez votre navigateur.') ?></li>
    <li><strong><?= t('Language cookies','Cookies de langue') ?>:</strong> <?= t('Remember your preferred language (EN or FR). Last 1 year.','Mémorisent votre langue préférée (EN ou FR). Durent 1 an.') ?></li>
    <li><strong><?= t('Referral cookies','Cookies de parrainage') ?>:</strong> <?= t('Set when you arrive via a partner referral link. Last 30 days. Used to attribute commissions correctly.','Définis lorsque vous arrivez via un lien de parrainage partenaire. Durent 30 jours.') ?></li>
  </ul>

  <div class="legal-divider"></div>

  <h2>6. <?= t('Your Rights','Vos droits') ?></h2>
  <p><?= t('You have the right to:','Vous avez le droit de :') ?></p>
  <ul>
    <li><?= t('Access the personal data we hold about you','Accéder aux données personnelles que nous détenons sur vous') ?></li>
    <li><?= t('Correct inaccurate data via your account dashboard','Corriger les données inexactes via votre tableau de bord') ?></li>
    <li><?= t('Delete your account and associated personal data','Supprimer votre compte et les données personnelles associées') ?></li>
    <li><?= t('Object to processing of your data for marketing purposes','Vous opposer au traitement de vos données à des fins marketing') ?></li>
  </ul>
  <p><?= t('To exercise any of these rights, contact us at','Pour exercer l\'un de ces droits, contactez-nous à') ?> <a href="mailto:admin@237biz.net">admin@237biz.net</a>.</p>

  <div class="legal-divider"></div>

  <h2>7. <?= t('Security','Sécurité') ?></h2>
  <p><?= t('We implement appropriate technical and organisational measures to protect your personal data against unauthorised access, alteration, disclosure or destruction. Passwords are stored using strong one-way hashing (bcrypt). Payment proofs are stored securely on our servers operated by Maroon Hosting.','Nous mettons en œuvre des mesures techniques et organisationnelles appropriées pour protéger vos données personnelles. Les mots de passe sont stockés à l\'aide d\'un hachage unidirectionnel fort (bcrypt).') ?></p>

  <div class="legal-divider"></div>

  <h2>8. <?= t('Contact','Contact') ?></h2>
  <p><?= t('For any privacy-related questions or requests, contact us:','Pour toute question ou demande liée à la confidentialité, contactez-nous :') ?></p>
  <ul>
    <li><?= t('Email','Email') ?>: <a href="mailto:admin@237biz.net">admin@237biz.net</a></li>
    <li><?= t('Platform','Plateforme') ?>: <a href="<?= SITE_URL ?>/contact"><?= SITE_URL ?>/contact</a></li>
    <li><?= t('Operated by','Exploité par') ?>: MS IT Solutions / Maroon Hosting, <?= t('Cameroon','Cameroun') ?></li>
  </ul>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
