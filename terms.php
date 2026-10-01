<?php
require_once __DIR__ . '/includes/config.php';
$isFr = lang() === 'fr';
$pageTitle = $isFr ? 'Conditions d\'Utilisation — 237Biz' : 'Terms of Use — 237Biz';
$pageDesc  = $isFr ? 'Conditions générales d\'utilisation de la plateforme 237Biz.' : 'Terms and conditions for using the 237Biz platform.';
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
  <h1><?= t('Terms of Use','Conditions d\'Utilisation') ?></h1>
  <div class="legal-meta"><?= t('Last updated','Dernière mise à jour') ?>: <?= $updated ?> · 237Biz.net</div>

  <p><?= t('By accessing or using 237Biz (237biz.net), you agree to be bound by these Terms of Use. If you do not agree, please do not use our platform.','En accédant ou en utilisant 237Biz (237biz.net), vous acceptez d\'être lié par ces Conditions d\'Utilisation. Si vous n\'acceptez pas, veuillez ne pas utiliser notre plateforme.') ?></p>

  <div class="legal-divider"></div>

  <h2>1. <?= t('The 237Biz Platform','La Plateforme 237Biz') ?></h2>
  <p><?= t('237Biz is an online business directory and growth platform for Cameroonian businesses. We provide tools including business listings, reviews, appointment booking, analytics, referral tracking, and associated services. We are operated by MS IT Solutions / Maroon Hosting, Cameroon.','237Biz est un annuaire en ligne et une plateforme de croissance pour les entreprises camerounaises. Nous sommes exploités par MS IT Solutions / Maroon Hosting, Cameroun.') ?></p>

  <div class="legal-divider"></div>

  <h2>2. <?= t('Account Registration','Inscription au Compte') ?></h2>
  <ul>
    <li><?= t('You must be at least 18 years old to create an account','Vous devez avoir au moins 18 ans pour créer un compte') ?></li>
    <li><?= t('You must provide accurate and complete information during registration','Vous devez fournir des informations exactes et complètes lors de l\'inscription') ?></li>
    <li><?= t('You are responsible for maintaining the security of your account password','Vous êtes responsable du maintien de la sécurité du mot de passe de votre compte') ?></li>
    <li><?= t('You must not share your account with others or create multiple accounts for the same person','Vous ne devez pas partager votre compte avec d\'autres personnes ni créer plusieurs comptes pour la même personne') ?></li>
    <li><?= t('You must verify your email address to activate your account','Vous devez vérifier votre adresse email pour activer votre compte') ?></li>
  </ul>

  <div class="legal-divider"></div>

  <h2>3. <?= t('Business Listings','Annonces Commerciales') ?></h2>
  <p><?= t('When you submit a business listing, you agree that:','Lorsque vous soumettez une annonce, vous acceptez que :') ?></p>
  <ul>
    <li><?= t('All information is accurate and up to date','Toutes les informations sont exactes et à jour') ?></li>
    <li><?= t('You are authorised to represent the business listed','Vous êtes autorisé à représenter l\'entreprise listée') ?></li>
    <li><?= t('You will not submit listings for businesses involved in illegal activities','Vous ne soumettrez pas d\'annonces pour des entreprises impliquées dans des activités illégales') ?></li>
    <li><?= t('You will not submit duplicate or spam listings','Vous ne soumettrez pas d\'annonces en double ou de spam') ?></li>
    <li><?= t('Uploaded photos and logos must not infringe third-party intellectual property rights','Les photos et logos téléchargés ne doivent pas porter atteinte aux droits de propriété intellectuelle de tiers') ?></li>
  </ul>
  <p><?= t('237Biz reserves the right to reject, remove or edit any listing that violates these terms or our content guidelines, without notice.','237Biz se réserve le droit de rejeter, supprimer ou modifier toute annonce qui enfreint ces conditions, sans préavis.') ?></p>

  <div class="legal-divider"></div>

  <h2>4. <?= t('Payments & Featured Listings','Paiements & Annonces Vedettes') ?></h2>
  <ul>
    <li><?= t('Featured listing payments are accepted via MTN MoMo and Orange Money','Les paiements pour les annonces vedettes sont acceptés via MTN MoMo et Orange Money') ?></li>
    <li><?= t('Payments are manually verified by our team within 24 hours of submission','Les paiements sont vérifiés manuellement par notre équipe dans les 24 heures suivant la soumission') ?></li>
    <li><?= t('Featured listing fees are non-refundable once the listing has been activated','Les frais d\'annonce vedette ne sont pas remboursables une fois l\'annonce activée') ?></li>
    <li><?= t('Featured listings expire after the purchased duration and revert to free listings','Les annonces vedettes expirent après la durée achetée et redeviennent des annonces gratuites') ?></li>
    <li><?= t('Fraudulent payment proof submissions will result in immediate account termination','La soumission de preuves de paiement frauduleuses entraînera la résiliation immédiate du compte') ?></li>
  </ul>

  <div class="legal-divider"></div>

  <h2>5. <?= t('Partner Programme (Agents & Creators)','Programme Partenaires (Agents & Créateurs)') ?></h2>
  <ul>
    <li><?= t('Commissions are calculated based on verified referral conversions as tracked by our platform','Les commissions sont calculées sur la base des conversions de parrainage vérifiées') ?></li>
    <li><?= t('Commissions must be approved by an administrator before payment is processed','Les commissions doivent être approuvées par un administrateur avant que le paiement soit traité') ?></li>
    <li><?= t('Minimum payout threshold is 10,000 XAF. Payments are made via MTN MoMo or Orange Money','Le seuil de paiement minimum est de 10 000 XAF. Les paiements sont effectués via MTN MoMo ou Orange Money') ?></li>
    <li><?= t('237Biz reserves the right to modify commission rates with 30 days notice','237Biz se réserve le droit de modifier les taux de commission avec un préavis de 30 jours') ?></li>
    <li><?= t('Self-referrals (referring your own business) are not eligible for commission','Les auto-parrainages (parrainer votre propre entreprise) ne sont pas éligibles à la commission') ?></li>
    <li><?= t('Fraudulent referral activity will result in account termination and forfeiture of pending commissions','L\'activité de parrainage frauduleuse entraînera la résiliation du compte et la perte des commissions en attente') ?></li>
  </ul>

  <div class="legal-divider"></div>

  <h2>6. <?= t('Reviews','Avis') ?></h2>
  <ul>
    <li><?= t('Reviews must be genuine and based on your own first-hand experience','Les avis doivent être authentiques et basés sur votre propre expérience directe') ?></li>
    <li><?= t('You must not submit fake, paid or incentivised reviews','Vous ne devez pas soumettre de faux avis, d\'avis payés ou d\'avis incentivés') ?></li>
    <li><?= t('Reviews must not contain defamatory, offensive or illegal content','Les avis ne doivent pas contenir de contenu diffamatoire, offensant ou illégal') ?></li>
    <li><?= t('All reviews are moderated before publication','Tous les avis sont modérés avant publication') ?></li>
    <li><?= t('237Biz reserves the right to remove reviews that violate these guidelines','237Biz se réserve le droit de supprimer les avis qui enfreignent ces directives') ?></li>
  </ul>

  <div class="legal-divider"></div>

  <h2>7. <?= t('Prohibited Conduct','Conduite Interdite') ?></h2>
  <p><?= t('You must not:','Vous ne devez pas :') ?></p>
  <ul>
    <li><?= t('Use the platform for any unlawful purpose or in violation of any applicable law','Utiliser la plateforme à des fins illégales ou en violation de toute loi applicable') ?></li>
    <li><?= t('Attempt to gain unauthorised access to any part of the platform','Tenter d\'obtenir un accès non autorisé à toute partie de la plateforme') ?></li>
    <li><?= t('Scrape, harvest or copy content from 237Biz without permission','Extraire, collecter ou copier du contenu de 237Biz sans autorisation') ?></li>
    <li><?= t('Interfere with or disrupt the platform or servers','Interférer avec ou perturber la plateforme ou les serveurs') ?></li>
    <li><?= t('Impersonate another person or entity','Usurper l\'identité d\'une autre personne ou entité') ?></li>
  </ul>

  <div class="legal-divider"></div>

  <h2>8. <?= t('Limitation of Liability','Limitation de Responsabilité') ?></h2>
  <p><?= t('237Biz provides the platform "as is" without warranties of any kind. We are not responsible for the accuracy of business listing information, the quality of services provided by listed businesses, or any transactions between users and businesses. Our liability is limited to the maximum extent permitted by Cameroonian law.','237Biz fournit la plateforme « telle quelle » sans garanties d\'aucune sorte. Nous ne sommes pas responsables de l\'exactitude des informations d\'annonce, de la qualité des services fournis par les entreprises listées, ou de toute transaction entre utilisateurs et entreprises.') ?></p>

  <div class="legal-divider"></div>

  <h2>9. <?= t('Changes to Terms','Modifications des Conditions') ?></h2>
  <p><?= t('We may update these Terms of Use from time to time. We will notify registered users of significant changes via email. Continued use of the platform after changes constitutes acceptance of the new terms.','Nous pouvons mettre à jour ces Conditions d\'Utilisation de temps en temps. Nous informerons les utilisateurs enregistrés des changements significatifs par email.') ?></p>

  <div class="legal-divider"></div>

  <h2>10. <?= t('Contact','Contact') ?></h2>
  <p><?= t('For questions about these terms, contact us at','Pour des questions sur ces conditions, contactez-nous à') ?> <a href="mailto:admin@237biz.net">admin@237biz.net</a> <?= t('or via','ou via') ?> <a href="<?= SITE_URL ?>/contact"><?= t('our contact page','notre page de contact') ?></a>.</p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
