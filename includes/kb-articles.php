<?php
/**
 * includes/kb-articles.php
 * All 30 knowledge base articles in English and French.
 * Add new articles here — they automatically appear on help.php and help-article.php.
 */

define('KB_SITE_URL', defined('SITE_URL') ? SITE_URL : 'https://237biz.net');

$KB_SECTIONS = [
    'getting-started'  => ['en'=>'Getting Started',           'fr'=>'Premiers pas',                'icon'=>'🚀'],
    'managing-listing' => ['en'=>'Managing Your Listing',     'fr'=>'Gérer votre annonce',          'icon'=>'📋'],
    'booking'          => ['en'=>'Appointment Booking',       'fr'=>'Prises de rendez-vous',        'icon'=>'📅'],
    'analytics'        => ['en'=>'Analytics & Visibility',    'fr'=>'Analytiques & Visibilité',     'icon'=>'📊'],
    'announcements'    => ['en'=>'Promotions & Announcements','fr'=>'Promotions & Annonces',        'icon'=>'📢'],
    'customers'        => ['en'=>'For Customers',             'fr'=>'Pour les clients',             'icon'=>'👥'],
    'troubleshooting'  => ['en'=>'Troubleshooting',           'fr'=>'Dépannage',                   'icon'=>'🔧'],
];

$KB_ARTICLES = [

// ── SECTION 1: Getting Started ───────────────────────────────────────────

[
'slug'     => 'add-your-listing',
'section'  => 'getting-started',
'order'    => 1,
'title_en' => 'How to create your free listing',
'title_fr' => 'Comment créer votre annonce gratuite',
'body_en'  => '
<p>Adding your business to 237Biz is free and takes about 5 minutes. Here is how to do it.</p>

<h3>Step 1 — Create an account</h3>
<p>Go to <a href="' . KB_SITE_URL . '/register">237biz.net/register</a> and fill in your name, email address and a password. You will receive a confirmation email — click the link inside to verify your account.</p>

<h3>Step 2 — Add your listing</h3>
<p>Once logged in, click <strong>+ List Your Business</strong> in the top navigation or go to <a href="' . KB_SITE_URL . '/add-listing">237biz.net/add-listing</a>. Fill in your business name, category, city, description and contact details.</p>

<h3>Step 3 — Submit for review</h3>
<p>Click <strong>Submit Listing</strong>. Your listing will be reviewed by the 237Biz team within a few hours. You will receive an email when it goes live.</p>

<h3>Tips for a strong listing</h3>
<ul>
  <li>Write at least 80 words in your description — this helps Google find you</li>
  <li>Add your WhatsApp number so customers can contact you instantly</li>
  <li>Include your full address so you appear in "near me" searches</li>
  <li>Upload your logo — listings with a logo get 3× more clicks</li>
</ul>

<p>Once your listing is live, visit your <a href="' . KB_SITE_URL . '/dashboard">Dashboard</a> to continue filling in your profile and tracking your views.</p>
',
'body_fr'  => '
<p>Ajouter votre entreprise sur 237Biz est gratuit et prend environ 5 minutes. Voici comment procéder.</p>

<h3>Étape 1 — Créer un compte</h3>
<p>Rendez-vous sur <a href="' . KB_SITE_URL . '/register">237biz.net/register</a> et renseignez votre nom, votre adresse e-mail et un mot de passe. Vous recevrez un e-mail de confirmation — cliquez sur le lien pour vérifier votre compte.</p>

<h3>Étape 2 — Ajouter votre annonce</h3>
<p>Une fois connecté, cliquez sur <strong>+ Lister votre Entreprise</strong> dans la navigation ou rendez-vous sur <a href="' . KB_SITE_URL . '/add-listing">237biz.net/add-listing</a>. Renseignez le nom, la catégorie, la ville, la description et les coordonnées de votre entreprise.</p>

<h3>Étape 3 — Soumettre pour examen</h3>
<p>Cliquez sur <strong>Soumettre l\'annonce</strong>. Votre annonce sera examinée par l\'équipe 237Biz dans les quelques heures. Vous recevrez un e-mail dès qu\'elle sera en ligne.</p>

<h3>Conseils pour une annonce efficace</h3>
<ul>
  <li>Rédigez au moins 80 mots dans votre description — cela aide Google à vous trouver</li>
  <li>Ajoutez votre numéro WhatsApp pour que les clients vous contactent instantanément</li>
  <li>Indiquez votre adresse complète pour apparaître dans les recherches "près de moi"</li>
  <li>Téléchargez votre logo — les annonces avec logo reçoivent 3× plus de clics</li>
</ul>

<p>Une fois votre annonce en ligne, visitez votre <a href="' . KB_SITE_URL . '/dashboard">Tableau de bord</a> pour compléter votre profil et suivre vos vues.</p>
',
],

[
'slug'     => 'after-submitting',
'section'  => 'getting-started',
'order'    => 2,
'title_en' => 'What happens after you submit your listing?',
'title_fr' => 'Que se passe-t-il après avoir soumis votre annonce ?',
'body_en'  => '
<p>After you submit your listing, it enters a review queue. Here is what to expect at each stage.</p>

<h3>Pending review</h3>
<p>Your listing status will show as <strong>Pending</strong> on your dashboard. The 237Biz team reviews all new listings to ensure quality and accuracy. This usually takes a few hours, and never more than 24 hours.</p>

<h3>What we check</h3>
<ul>
  <li>That the business name and category are accurate</li>
  <li>That contact details (phone, WhatsApp) are valid Cameroonian numbers</li>
  <li>That the description is genuine and not spam</li>
  <li>That the listing belongs in the city selected</li>
</ul>

<h3>Approved</h3>
<p>You will receive an email notification when your listing is approved and live on the site. It will then appear in search results and category pages.</p>

<h3>Rejected</h3>
<p>If your listing is rejected, you will receive an email explaining why. Common reasons include incomplete information or an incorrect category. You can edit and resubmit from your <a href="' . KB_SITE_URL . '/dashboard">Dashboard</a>.</p>

<p><strong>Note:</strong> Editing an approved listing does not take it offline. Changes are applied immediately.</p>
',
'body_fr'  => '
<p>Après avoir soumis votre annonce, elle entre dans une file d\'attente de révision. Voici ce qui se passe à chaque étape.</p>

<h3>En attente de révision</h3>
<p>Le statut de votre annonce apparaîtra comme <strong>En attente</strong> sur votre tableau de bord. L\'équipe 237Biz examine toutes les nouvelles annonces pour garantir qualité et exactitude. Cela prend généralement quelques heures, et jamais plus de 24 heures.</p>

<h3>Ce que nous vérifions</h3>
<ul>
  <li>Que le nom et la catégorie de l\'entreprise sont exacts</li>
  <li>Que les coordonnées (téléphone, WhatsApp) sont des numéros camerounais valides</li>
  <li>Que la description est authentique et non du spam</li>
  <li>Que l\'annonce correspond bien à la ville sélectionnée</li>
</ul>

<h3>Approuvée</h3>
<p>Vous recevrez une notification par e-mail lorsque votre annonce sera approuvée et publiée sur le site. Elle apparaîtra alors dans les résultats de recherche et les pages de catégories.</p>

<h3>Rejetée</h3>
<p>Si votre annonce est rejetée, vous recevrez un e-mail expliquant pourquoi. Les raisons courantes incluent des informations incomplètes ou une catégorie incorrecte. Vous pouvez modifier et soumettre à nouveau depuis votre <a href="' . KB_SITE_URL . '/dashboard">Tableau de bord</a>.</p>

<p><strong>Remarque :</strong> Modifier une annonce approuvée ne la retire pas du site. Les modifications sont appliquées immédiatement.</p>
',
],

[
'slug'     => 'login-dashboard',
'section'  => 'getting-started',
'order'    => 3,
'title_en' => 'How to log in and access your dashboard',
'title_fr' => 'Comment se connecter et accéder à votre tableau de bord',
'body_en'  => '
<p>Your dashboard is the control centre for your 237Biz listing. Here is how to access it.</p>

<h3>Logging in</h3>
<p>Go to <a href="' . KB_SITE_URL . '/login">237biz.net/login</a> and enter your email address and password. If you have forgotten your password, click <strong>Forgot password?</strong> on the login page.</p>

<h3>Your dashboard</h3>
<p>After logging in, click <strong>My Account</strong> in the top navigation and select <strong>Dashboard</strong>. From here you can:</p>
<ul>
  <li>View and edit all your listings</li>
  <li>See your total views at a glance</li>
  <li>Access Analytics, Announcements and Bookings</li>
  <li>Download your QR code</li>
  <li>Add a new listing</li>
</ul>

<h3>Staying logged in</h3>
<p>Your session stays active for 30 days on the same device. If you are logged out, simply return to the login page and sign in again.</p>
',
'body_fr'  => '
<p>Votre tableau de bord est le centre de contrôle de votre annonce 237Biz. Voici comment y accéder.</p>

<h3>Se connecter</h3>
<p>Rendez-vous sur <a href="' . KB_SITE_URL . '/login">237biz.net/login</a> et entrez votre adresse e-mail et votre mot de passe. Si vous avez oublié votre mot de passe, cliquez sur <strong>Mot de passe oublié ?</strong> sur la page de connexion.</p>

<h3>Votre tableau de bord</h3>
<p>Après vous être connecté, cliquez sur <strong>Mon Compte</strong> dans la navigation et sélectionnez <strong>Tableau de bord</strong>. De là, vous pouvez :</p>
<ul>
  <li>Consulter et modifier toutes vos annonces</li>
  <li>Voir votre nombre total de vues d\'un coup d\'œil</li>
  <li>Accéder aux Analytiques, Annonces et Réservations</li>
  <li>Télécharger votre code QR</li>
  <li>Ajouter une nouvelle annonce</li>
</ul>

<h3>Rester connecté</h3>
<p>Votre session reste active 30 jours sur le même appareil. Si vous êtes déconnecté, retournez simplement sur la page de connexion et connectez-vous à nouveau.</p>
',
],

[
'slug'     => 'reset-password',
'section'  => 'getting-started',
'order'    => 4,
'title_en' => 'How to reset your password',
'title_fr' => 'Comment réinitialiser votre mot de passe',
'body_en'  => '
<p>If you cannot log in because you have forgotten your password, follow these steps to reset it.</p>

<h3>Reset your password</h3>
<ol>
  <li>Go to <a href="' . KB_SITE_URL . '/login">237biz.net/login</a></li>
  <li>Click <strong>Forgot password?</strong> below the login form</li>
  <li>Enter the email address associated with your account</li>
  <li>Click <strong>Send Reset Link</strong></li>
  <li>Check your inbox for an email from 237Biz — click the link inside</li>
  <li>Enter and confirm your new password</li>
</ol>

<h3>I did not receive the email</h3>
<ul>
  <li>Check your spam or junk folder</li>
  <li>Make sure you entered the same email address used when registering</li>
  <li>Wait a few minutes — email delivery can occasionally be delayed</li>
  <li>If you still have not received it, contact us at <a href="mailto:support@237biz.net">support@237biz.net</a></li>
</ul>
',
'body_fr'  => '
<p>Si vous ne pouvez pas vous connecter parce que vous avez oublié votre mot de passe, suivez ces étapes pour le réinitialiser.</p>

<h3>Réinitialiser votre mot de passe</h3>
<ol>
  <li>Rendez-vous sur <a href="' . KB_SITE_URL . '/login">237biz.net/login</a></li>
  <li>Cliquez sur <strong>Mot de passe oublié ?</strong> sous le formulaire de connexion</li>
  <li>Entrez l\'adresse e-mail associée à votre compte</li>
  <li>Cliquez sur <strong>Envoyer le lien de réinitialisation</strong></li>
  <li>Vérifiez votre boîte de réception pour un e-mail de 237Biz — cliquez sur le lien</li>
  <li>Entrez et confirmez votre nouveau mot de passe</li>
</ol>

<h3>Je n\'ai pas reçu l\'e-mail</h3>
<ul>
  <li>Vérifiez votre dossier spam ou courrier indésirable</li>
  <li>Assurez-vous d\'avoir entré la même adresse e-mail utilisée lors de l\'inscription</li>
  <li>Attendez quelques minutes — la livraison des e-mails peut parfois être retardée</li>
  <li>Si vous ne l\'avez toujours pas reçu, contactez-nous à <a href="mailto:support@237biz.net">support@237biz.net</a></li>
</ul>
',
],

// ── SECTION 2: Managing Your Listing ────────────────────────────────────

[
'slug'     => 'edit-your-listing',
'section'  => 'managing-listing',
'order'    => 1,
'title_en' => 'How to edit your listing',
'title_fr' => 'Comment modifier votre annonce',
'body_en'  => '
<p>You can update your listing information at any time. Changes are saved and published immediately.</p>

<h3>How to access the editor</h3>
<ol>
  <li>Log in and go to your <a href="' . KB_SITE_URL . '/dashboard">Dashboard</a></li>
  <li>Find the listing you want to edit</li>
  <li>Click the <strong>Edit</strong> button</li>
</ol>

<h3>The edit form is divided into sections</h3>
<ul>
  <li><strong>Business Identity</strong> — name, tagline, category, city</li>
  <li><strong>Your Story</strong> — your main description with formatting tools</li>
  <li><strong>Services</strong> — the services you offer (Featured listings only)</li>
  <li><strong>Contact Details</strong> — phone, WhatsApp, email, website, social links</li>
  <li><strong>SEO &amp; Discovery</strong> — keywords and FAQs (Featured only)</li>
  <li><strong>Hours &amp; Boosters</strong> — opening hours, call-to-action button, video</li>
  <li><strong>Appointment Booking</strong> — enable and configure bookings (Featured only)</li>
  <li><strong>Logo &amp; Media</strong> — your business logo or photo</li>
</ul>

<h3>Tips</h3>
<ul>
  <li>Click on a section header to expand it</li>
  <li>The form auto-saves a draft to your browser every 2 seconds</li>
  <li>Click <strong>Preview</strong> to see how your listing looks before saving</li>
  <li>Click <strong>Save Changes</strong> when done — changes go live immediately</li>
</ul>
',
'body_fr'  => '
<p>Vous pouvez mettre à jour les informations de votre annonce à tout moment. Les modifications sont enregistrées et publiées immédiatement.</p>

<h3>Comment accéder à l\'éditeur</h3>
<ol>
  <li>Connectez-vous et allez sur votre <a href="' . KB_SITE_URL . '/dashboard">Tableau de bord</a></li>
  <li>Trouvez l\'annonce que vous souhaitez modifier</li>
  <li>Cliquez sur le bouton <strong>Modifier</strong></li>
</ol>

<h3>Le formulaire de modification est divisé en sections</h3>
<ul>
  <li><strong>Identité de l\'entreprise</strong> — nom, accroche, catégorie, ville</li>
  <li><strong>Votre Histoire</strong> — votre description principale avec outils de mise en forme</li>
  <li><strong>Services</strong> — les services que vous proposez (annonces vedettes uniquement)</li>
  <li><strong>Coordonnées</strong> — téléphone, WhatsApp, e-mail, site web, réseaux sociaux</li>
  <li><strong>SEO &amp; Découverte</strong> — mots-clés et FAQ (vedettes uniquement)</li>
  <li><strong>Heures &amp; Visibilité</strong> — horaires, bouton d\'action, vidéo</li>
  <li><strong>Réservation</strong> — activer et configurer les réservations (vedettes uniquement)</li>
  <li><strong>Logo &amp; Médias</strong> — votre logo ou photo d\'entreprise</li>
</ul>

<h3>Conseils</h3>
<ul>
  <li>Cliquez sur l\'en-tête d\'une section pour la développer</li>
  <li>Le formulaire sauvegarde automatiquement un brouillon dans votre navigateur toutes les 2 secondes</li>
  <li>Cliquez sur <strong>Aperçu</strong> pour voir à quoi ressemble votre annonce avant d\'enregistrer</li>
  <li>Cliquez sur <strong>Enregistrer</strong> une fois terminé — les modifications sont publiées immédiatement</li>
</ul>
',
],

[
'slug'     => 'managing-services',
'section'  => 'managing-listing',
'order'    => 2,
'title_en' => 'Adding and managing your services',
'title_fr' => 'Ajouter et gérer vos services',
'body_en'  => '
<p>Adding services to your listing helps customers understand exactly what you offer — and helps Google index each service separately, giving you more visibility in search results.</p>

<h3>How to add a service</h3>
<ol>
  <li>Go to <strong>Edit Listing</strong> and open the <strong>Services</strong> section</li>
  <li>Click <strong>+ Add a Service</strong></li>
  <li>Enter the service name (e.g. "Website Design")</li>
  <li>Add a short description including price range and turnaround time if relevant</li>
  <li>Repeat for each service</li>
  <li>Click <strong>Save Changes</strong></li>
</ol>

<h3>Tips for writing service names</h3>
<ul>
  <li>Use the words your customers actually search for</li>
  <li>"Emergency Plumbing Limbe" ranks better than just "Plumbing"</li>
  <li>Be specific — "Company Registration Cameroon" is better than "Business Services"</li>
  <li>Businesses with 5 or more services get significantly more views</li>
</ul>

<h3>Removing a service</h3>
<p>Click the <strong>✕ Remove</strong> button next to the service you want to delete, then save.</p>
',
'body_fr'  => '
<p>Ajouter des services à votre annonce aide les clients à comprendre exactement ce que vous proposez — et aide Google à indexer chaque service séparément, augmentant votre visibilité dans les résultats de recherche.</p>

<h3>Comment ajouter un service</h3>
<ol>
  <li>Allez sur <strong>Modifier l\'annonce</strong> et ouvrez la section <strong>Services</strong></li>
  <li>Cliquez sur <strong>+ Ajouter un service</strong></li>
  <li>Entrez le nom du service (ex. "Conception de site web")</li>
  <li>Ajoutez une courte description incluant la fourchette de prix et le délai si pertinent</li>
  <li>Répétez pour chaque service</li>
  <li>Cliquez sur <strong>Enregistrer</strong></li>
</ol>

<h3>Conseils pour nommer vos services</h3>
<ul>
  <li>Utilisez les mots que vos clients recherchent réellement</li>
  <li>"Plomberie d\'urgence Limbe" se classe mieux que simplement "Plomberie"</li>
  <li>Soyez précis — "Immatriculation d\'entreprise Cameroun" est mieux que "Services aux entreprises"</li>
  <li>Les entreprises avec 5 services ou plus reçoivent nettement plus de vues</li>
</ul>

<h3>Supprimer un service</h3>
<p>Cliquez sur le bouton <strong>✕ Supprimer</strong> à côté du service que vous souhaitez supprimer, puis enregistrez.</p>
',
],

[
'slug'     => 'business-hours',
'section'  => 'managing-listing',
'order'    => 3,
'title_en' => 'Setting your business hours',
'title_fr' => 'Définir vos horaires d\'ouverture',
'body_en'  => '
<p>Setting your opening hours shows customers when you are available and makes your listing eligible for "open now" searches on Google.</p>

<h3>How to set your hours</h3>
<ol>
  <li>Go to <strong>Edit Listing</strong> and open the <strong>Hours &amp; Boosters</strong> section</li>
  <li>Check the box next to each day you are open</li>
  <li>Set the opening and closing time for each day</li>
  <li>Leave days unchecked to show "Closed" on that day</li>
  <li>Click <strong>Save Changes</strong></li>
</ol>

<h3>Why hours matter</h3>
<ul>
  <li>Customers can see at a glance if you are currently open</li>
  <li>Your listing becomes eligible for "open now" search filters</li>
  <li>Your appointment booking form uses your hours to show available slots</li>
</ul>

<h3>Different hours on different days</h3>
<p>You can set different times for each day. For example, Mon–Fri 8:00–17:00, Sat 9:00–14:00, and Sunday closed.</p>
',
'body_fr'  => '
<p>Définir vos horaires d\'ouverture montre aux clients quand vous êtes disponible et rend votre annonce éligible aux recherches "ouvert maintenant" sur Google.</p>

<h3>Comment définir vos horaires</h3>
<ol>
  <li>Allez sur <strong>Modifier l\'annonce</strong> et ouvrez la section <strong>Heures &amp; Visibilité</strong></li>
  <li>Cochez la case à côté de chaque jour où vous êtes ouvert</li>
  <li>Définissez l\'heure d\'ouverture et de fermeture pour chaque jour</li>
  <li>Laissez les jours décochés pour afficher "Fermé" ce jour-là</li>
  <li>Cliquez sur <strong>Enregistrer</strong></li>
</ol>

<h3>Pourquoi les horaires sont importants</h3>
<ul>
  <li>Les clients peuvent voir en un coup d\'œil si vous êtes actuellement ouvert</li>
  <li>Votre annonce devient éligible aux filtres de recherche "ouvert maintenant"</li>
  <li>Votre formulaire de réservation utilise vos horaires pour afficher les créneaux disponibles</li>
</ul>

<h3>Horaires différents selon les jours</h3>
<p>Vous pouvez définir des horaires différents pour chaque jour. Par exemple, Lun–Ven 8h00–17h00, Sam 9h00–14h00 et Dimanche fermé.</p>
',
],

[
'slug'     => 'logo-photos',
'section'  => 'managing-listing',
'order'    => 4,
'title_en' => 'Uploading your logo and photos',
'title_fr' => 'Télécharger votre logo et vos photos',
'body_en'  => '
<p>Listings with a logo receive significantly more clicks than those without. A clear, professional image builds trust with potential customers.</p>

<h3>How to upload your logo</h3>
<ol>
  <li>Go to <strong>Edit Listing</strong> and open the <strong>Logo &amp; Media</strong> section</li>
  <li>Click <strong>Choose File</strong> and select your image</li>
  <li>Click <strong>Save Changes</strong></li>
</ol>

<h3>Image requirements</h3>
<ul>
  <li>Formats accepted: JPG, PNG, WebP</li>
  <li>Maximum file size: 2MB</li>
  <li>Recommended size: at least 400×400 pixels</li>
  <li>Square images work best — they display cleanly in the directory listing</li>
</ul>

<h3>Tips for a good business image</h3>
<ul>
  <li>Use your actual business logo if you have one</li>
  <li>A clear photo of your shopfront or premises also works well</li>
  <li>Avoid blurry or dark photos — they reduce customer trust</li>
  <li>A white or light background makes your logo stand out</li>
</ul>

<h3>Replacing your logo</h3>
<p>To replace an existing logo, simply upload a new image in the same section and save. The old image will be replaced automatically.</p>
',
'body_fr'  => '
<p>Les annonces avec un logo reçoivent nettement plus de clics que celles sans. Une image claire et professionnelle inspire confiance aux clients potentiels.</p>

<h3>Comment télécharger votre logo</h3>
<ol>
  <li>Allez sur <strong>Modifier l\'annonce</strong> et ouvrez la section <strong>Logo &amp; Médias</strong></li>
  <li>Cliquez sur <strong>Choisir un fichier</strong> et sélectionnez votre image</li>
  <li>Cliquez sur <strong>Enregistrer</strong></li>
</ol>

<h3>Exigences pour les images</h3>
<ul>
  <li>Formats acceptés : JPG, PNG, WebP</li>
  <li>Taille maximale : 2 Mo</li>
  <li>Taille recommandée : au moins 400×400 pixels</li>
  <li>Les images carrées fonctionnent mieux — elles s\'affichent proprement dans le répertoire</li>
</ul>

<h3>Conseils pour une bonne image d\'entreprise</h3>
<ul>
  <li>Utilisez le vrai logo de votre entreprise si vous en avez un</li>
  <li>Une photo claire de votre façade ou de vos locaux fonctionne aussi très bien</li>
  <li>Évitez les photos floues ou sombres — elles réduisent la confiance des clients</li>
  <li>Un fond blanc ou clair fait ressortir votre logo</li>
</ul>

<h3>Remplacer votre logo</h3>
<p>Pour remplacer un logo existant, téléchargez simplement une nouvelle image dans la même section et enregistrez. L\'ancienne image sera remplacée automatiquement.</p>
',
],

[
'slug'     => 'description-seo',
'section'  => 'managing-listing',
'order'    => 5,
'title_en' => 'Writing a description that ranks on Google',
'title_fr' => 'Rédiger une description qui se classe sur Google',
'body_en'  => '
<p>Your business description is one of the most important parts of your listing for Google ranking. Here is how to write one that works.</p>

<h3>The basics</h3>
<ul>
  <li>Write at least 100 words — Google rewards longer, more detailed content</li>
  <li>Mention your city or neighbourhood (e.g. "based in Limbe, Southwest Region")</li>
  <li>Include your main services in natural language</li>
  <li>Explain what makes your business different</li>
</ul>

<h3>Use the formatting tools</h3>
<p>The description editor lets you format your text. Use these effectively:</p>
<ul>
  <li><strong>Heading</strong> — use for section titles like "Our Services" or "Why Choose Us"</li>
  <li><strong>Normal</strong> — use for body paragraphs</li>
  <li><strong>Bullet lists</strong> — great for listing services or features</li>
  <li><strong>Bold</strong> — highlight important words or phrases</li>
</ul>

<h3>What to include</h3>
<ol>
  <li>A brief overview of your business (2–3 sentences)</li>
  <li>Your main services or products</li>
  <li>Your location and the areas you serve</li>
  <li>Your experience or qualifications</li>
  <li>A call to action — e.g. "Contact us today on WhatsApp for a free quote"</li>
</ol>

<h3>What to avoid</h3>
<ul>
  <li>Keyword stuffing — do not repeat the same word unnaturally many times</li>
  <li>Copying from another website — Google penalises duplicate content</li>
  <li>Leaving the description blank or too short</li>
</ul>
',
'body_fr'  => '
<p>La description de votre entreprise est l\'une des parties les plus importantes de votre annonce pour le classement Google. Voici comment en rédiger une efficace.</p>

<h3>Les bases</h3>
<ul>
  <li>Rédigez au moins 100 mots — Google récompense un contenu plus long et détaillé</li>
  <li>Mentionnez votre ville ou quartier (ex. "basé à Limbe, Région du Sud-Ouest")</li>
  <li>Incluez vos principaux services en langage naturel</li>
  <li>Expliquez ce qui distingue votre entreprise</li>
</ul>

<h3>Utilisez les outils de mise en forme</h3>
<p>L\'éditeur de description vous permet de mettre en forme votre texte. Utilisez-les efficacement :</p>
<ul>
  <li><strong>Titre</strong> — pour les titres de sections comme "Nos Services" ou "Pourquoi nous choisir"</li>
  <li><strong>Normal</strong> — pour les paragraphes de corps</li>
  <li><strong>Listes à puces</strong> — idéal pour énumérer services ou fonctionnalités</li>
  <li><strong>Gras</strong> — mettez en évidence les mots ou phrases importants</li>
</ul>

<h3>Ce qu\'il faut inclure</h3>
<ol>
  <li>Un bref aperçu de votre entreprise (2–3 phrases)</li>
  <li>Vos principaux services ou produits</li>
  <li>Votre localisation et les zones que vous desservez</li>
  <li>Votre expérience ou vos qualifications</li>
  <li>Un appel à l\'action — ex. "Contactez-nous aujourd\'hui sur WhatsApp pour un devis gratuit"</li>
</ol>

<h3>Ce qu\'il faut éviter</h3>
<ul>
  <li>La surcharge de mots-clés — ne répétez pas le même mot de manière non naturelle</li>
  <li>Copier depuis un autre site — Google pénalise le contenu dupliqué</li>
  <li>Laisser la description vide ou trop courte</li>
</ul>
',
],

[
'slug'     => 'keywords-faqs',
'section'  => 'managing-listing',
'order'    => 6,
'title_en' => 'Adding keywords and FAQs for SEO',
'title_fr' => 'Ajouter des mots-clés et FAQ pour le SEO',
'body_en'  => '
<p>Keywords and FAQs are powerful tools for getting found on Google and AI search assistants like ChatGPT.</p>

<h3>Keywords</h3>
<p>Keywords are the search terms your customers type when looking for a business like yours. To add them:</p>
<ol>
  <li>Go to <strong>Edit Listing</strong> and open the <strong>SEO &amp; Discovery</strong> section</li>
  <li>Type a keyword in the field and press <strong>Enter</strong> or comma</li>
  <li>Add up to 10 keywords</li>
  <li>Save your listing</li>
</ol>
<p><strong>Good keyword examples:</strong> "electrician Limbe", "web hosting Cameroon", "emergency plumber Buea", "MTN MoMo integration"</p>

<h3>FAQs</h3>
<p>FAQs (Frequently Asked Questions) are especially powerful for AI search. When someone asks ChatGPT or Google AI "find me a plumber in Limbe", it pulls answers from FAQ sections on web pages.</p>
<ul>
  <li>Add at least 3 FAQs for best results</li>
  <li>Use real questions your customers ask you</li>
  <li>Include your location in answers where relevant</li>
  <li>Be specific — "Yes, we offer 24/7 emergency plumbing in Limbe and Buea" is better than "Yes"</li>
</ul>

<h3>Quick-add FAQ chips</h3>
<p>The editor shows suggested FAQ questions as clickable chips. Click any chip to pre-fill a question, then write your answer below it.</p>
',
'body_fr'  => '
<p>Les mots-clés et les FAQ sont des outils puissants pour être trouvé sur Google et les assistants de recherche IA comme ChatGPT.</p>

<h3>Mots-clés</h3>
<p>Les mots-clés sont les termes de recherche que vos clients tapent pour trouver une entreprise comme la vôtre. Pour les ajouter :</p>
<ol>
  <li>Allez sur <strong>Modifier l\'annonce</strong> et ouvrez la section <strong>SEO &amp; Découverte</strong></li>
  <li>Tapez un mot-clé dans le champ et appuyez sur <strong>Entrée</strong> ou virgule</li>
  <li>Ajoutez jusqu\'à 10 mots-clés</li>
  <li>Enregistrez votre annonce</li>
</ol>
<p><strong>Bons exemples de mots-clés :</strong> "électricien Limbe", "hébergement web Cameroun", "plombier urgence Buea", "intégration MTN MoMo"</p>

<h3>FAQ</h3>
<p>Les FAQ (Foire Aux Questions) sont particulièrement puissantes pour la recherche IA. Quand quelqu\'un demande à ChatGPT "trouver un plombier à Limbe", il extrait les réponses des sections FAQ des pages web.</p>
<ul>
  <li>Ajoutez au moins 3 FAQ pour de meilleurs résultats</li>
  <li>Utilisez de vraies questions que vos clients vous posent</li>
  <li>Incluez votre localisation dans les réponses si pertinent</li>
  <li>Soyez précis — "Oui, nous proposons la plomberie d\'urgence 24h/24 à Limbe et Buea" est mieux que "Oui"</li>
</ul>

<h3>Puces de FAQ rapides</h3>
<p>L\'éditeur affiche des questions FAQ suggérées sous forme de puces cliquables. Cliquez sur une puce pour pré-remplir une question, puis rédigez votre réponse en dessous.</p>
',
],

// ── SECTION 3: Booking ───────────────────────────────────────────────────

[
'slug'     => 'setup-booking',
'section'  => 'booking',
'order'    => 1,
'title_en' => 'Setting up appointment booking on your listing',
'title_fr' => 'Configurer la réservation de rendez-vous sur votre annonce',
'body_en'  => '
<p>The appointment booking system lets customers book directly from your listing page. Bookings arrive as requests that you confirm or decline — you stay in control.</p>

<h3>Requirements</h3>
<ul>
  <li>You must have a Featured listing to enable booking</li>
  <li>Your business hours must be set (the booking form uses them to show available times)</li>
</ul>

<h3>How to enable booking</h3>
<ol>
  <li>Go to <strong>Edit Listing</strong> and open the <strong>Appointment Booking</strong> section</li>
  <li>Check <strong>Enable Appointment Booking</strong></li>
  <li>Choose your slot duration (15, 30, 45 or 60 minutes)</li>
  <li>Add the services you want to offer for booking (optional)</li>
  <li>Set how many days ahead customers can book</li>
  <li>Click <strong>Save Changes</strong></li>
</ol>

<h3>What customers see</h3>
<p>A green <strong>Book an Appointment</strong> button will appear on your listing page. Clicking it takes them to a booking form where they choose a date and available time slot.</p>

<h3>Managing bookings</h3>
<p>All booking requests appear in <a href="' . KB_SITE_URL . '/manage-bookings">Manage Bookings</a>. New requests show as Pending until you confirm or decline them. See <a href="' . KB_SITE_URL . '/help/confirm-decline-booking">How to confirm or decline a booking</a>.</p>
',
'body_fr'  => '
<p>Le système de réservation de rendez-vous permet aux clients de réserver directement depuis votre page d\'annonce. Les réservations arrivent comme des demandes que vous confirmez ou refusez — vous gardez le contrôle.</p>

<h3>Conditions requises</h3>
<ul>
  <li>Vous devez avoir une annonce Vedette pour activer la réservation</li>
  <li>Vos horaires d\'ouverture doivent être définis (le formulaire de réservation les utilise pour afficher les créneaux disponibles)</li>
</ul>

<h3>Comment activer la réservation</h3>
<ol>
  <li>Allez sur <strong>Modifier l\'annonce</strong> et ouvrez la section <strong>Réservation de rendez-vous</strong></li>
  <li>Cochez <strong>Activer la prise de rendez-vous</strong></li>
  <li>Choisissez la durée de vos créneaux (15, 30, 45 ou 60 minutes)</li>
  <li>Ajoutez les services que vous souhaitez proposer à la réservation (optionnel)</li>
  <li>Définissez combien de jours à l\'avance les clients peuvent réserver</li>
  <li>Cliquez sur <strong>Enregistrer</strong></li>
</ol>

<h3>Ce que voient les clients</h3>
<p>Un bouton vert <strong>Prendre rendez-vous</strong> apparaîtra sur votre page d\'annonce. En cliquant, ils accèdent à un formulaire de réservation où ils choisissent une date et un créneau disponible.</p>

<h3>Gérer les réservations</h3>
<p>Toutes les demandes de réservation apparaissent dans <a href="' . KB_SITE_URL . '/manage-bookings">Gérer les réservations</a>. Les nouvelles demandes s\'affichent comme En attente jusqu\'à ce que vous les confirmiez ou refusiez.</p>
',
],

[
'slug'     => 'slot-duration',
'section'  => 'booking',
'order'    => 2,
'title_en' => 'Choosing your slot duration',
'title_fr' => 'Choisir la durée de vos créneaux',
'body_en'  => '
<p>Your slot duration determines how long each appointment is and how many slots are available per day.</p>

<h3>Available durations</h3>
<ul>
  <li><strong>15 minutes</strong> — good for quick consultations, haircuts, brief calls</li>
  <li><strong>30 minutes</strong> — the most common option, suitable for most services</li>
  <li><strong>45 minutes</strong> — good for longer consultations or beauty treatments</li>
  <li><strong>60 minutes</strong> — best for in-depth meetings, medical consultations, training sessions</li>
</ul>

<h3>How slots work</h3>
<p>The booking form generates time slots based on your opening hours and your chosen duration. For example, if you are open 8:00–17:00 with 30-minute slots, customers will see: 8:00, 8:30, 9:00, 9:30… and so on.</p>

<h3>Slots are automatically removed when booked</h3>
<p>Once a slot has a pending or confirmed booking, it is removed from the available options so no two customers can book the same time. You can also manually block times — see <a href="' . KB_SITE_URL . '/help/block-times">How to block unavailable dates and times</a>.</p>

<h3>Changing your slot duration</h3>
<p>You can change your slot duration at any time in the <strong>Appointment Booking</strong> section of Edit Listing. The change applies to future bookings only — existing bookings are not affected.</p>
',
'body_fr'  => '
<p>La durée de vos créneaux détermine la durée de chaque rendez-vous et le nombre de créneaux disponibles par jour.</p>

<h3>Durées disponibles</h3>
<ul>
  <li><strong>15 minutes</strong> — idéal pour les consultations rapides, coupes de cheveux, appels brefs</li>
  <li><strong>30 minutes</strong> — l\'option la plus courante, adaptée à la plupart des services</li>
  <li><strong>45 minutes</strong> — bon pour les consultations plus longues ou les soins de beauté</li>
  <li><strong>60 minutes</strong> — idéal pour les réunions approfondies, consultations médicales, sessions de formation</li>
</ul>

<h3>Comment fonctionnent les créneaux</h3>
<p>Le formulaire de réservation génère des créneaux horaires basés sur vos horaires d\'ouverture et la durée choisie. Par exemple, si vous êtes ouvert de 8h00 à 17h00 avec des créneaux de 30 minutes, les clients verront : 8h00, 8h30, 9h00, 9h30… et ainsi de suite.</p>

<h3>Les créneaux sont automatiquement retirés une fois réservés</h3>
<p>Dès qu\'un créneau a une réservation en attente ou confirmée, il est retiré des options disponibles afin que deux clients ne puissent pas réserver le même horaire. Vous pouvez également bloquer manuellement des créneaux.</p>

<h3>Modifier la durée de vos créneaux</h3>
<p>Vous pouvez modifier la durée de vos créneaux à tout moment dans la section <strong>Réservation de rendez-vous</strong> de Modifier l\'annonce. Le changement s\'applique uniquement aux futures réservations — les réservations existantes ne sont pas affectées.</p>
',
],

[
'slug'     => 'block-times',
'section'  => 'booking',
'order'    => 3,
'title_en' => 'How to block unavailable dates and times',
'title_fr' => 'Comment bloquer des dates et créneaux indisponibles',
'body_en'  => '
<p>Blocking times prevents customers from booking when you are unavailable — for example, during holidays, staff meetings or when you already have an existing appointment.</p>

<h3>How to block a time</h3>
<ol>
  <li>Go to <a href="' . KB_SITE_URL . '/manage-bookings">Manage Bookings</a></li>
  <li>Click the <strong>Block Times</strong> tab</li>
  <li>Select the date you want to block</li>
  <li>To block a specific time slot, select it from the time dropdown. To block the entire day, leave the time as "Whole day"</li>
  <li>Add an optional reason (for your own reference — customers do not see this)</li>
  <li>Click <strong>Block This Time</strong></li>
</ol>

<h3>Removing a block</h3>
<p>All upcoming blocked times are listed in the Block Times tab. Click <strong>Remove</strong> next to any block to make that time available again.</p>

<h3>How blocking affects the booking form</h3>
<p>Blocked times are automatically hidden from the customer-facing booking form. Customers will simply not see those slots as available — they will not know why a slot is unavailable.</p>

<h3>Tip</h3>
<p>Block the whole day for public holidays in Cameroon — 1 January, 11 February, 8 March, 1 May, 20 May, 15 August, 25 December — to avoid receiving booking requests you cannot honour.</p>
',
'body_fr'  => '
<p>Bloquer des créneaux empêche les clients de réserver quand vous n\'êtes pas disponible — par exemple pendant les congés, les réunions d\'équipe ou quand vous avez déjà un rendez-vous existant.</p>

<h3>Comment bloquer un créneau</h3>
<ol>
  <li>Allez sur <a href="' . KB_SITE_URL . '/manage-bookings">Gérer les réservations</a></li>
  <li>Cliquez sur l\'onglet <strong>Bloquer des créneaux</strong></li>
  <li>Sélectionnez la date que vous souhaitez bloquer</li>
  <li>Pour bloquer un créneau spécifique, sélectionnez-le dans le menu déroulant. Pour bloquer toute la journée, laissez l\'heure sur "Journée entière"</li>
  <li>Ajoutez une raison optionnelle (pour votre référence — les clients ne la voient pas)</li>
  <li>Cliquez sur <strong>Bloquer ce créneau</strong></li>
</ol>

<h3>Supprimer un blocage</h3>
<p>Tous les créneaux bloqués à venir sont listés dans l\'onglet Bloquer des créneaux. Cliquez sur <strong>Supprimer</strong> à côté de n\'importe quel blocage pour rendre ce créneau à nouveau disponible.</p>

<h3>Comment le blocage affecte le formulaire de réservation</h3>
<p>Les créneaux bloqués sont automatiquement masqués du formulaire de réservation côté client. Les clients ne verront tout simplement pas ces créneaux comme disponibles — ils ne sauront pas pourquoi un créneau est indisponible.</p>

<h3>Conseil</h3>
<p>Bloquez la journée entière pour les jours fériés au Cameroun — 1er janvier, 11 février, 8 mars, 1er mai, 20 mai, 15 août, 25 décembre — pour éviter de recevoir des demandes de réservation que vous ne pouvez pas honorer.</p>
',
],

[
'slug'     => 'confirm-decline-booking',
'section'  => 'booking',
'order'    => 4,
'title_en' => 'How to confirm or decline a booking request',
'title_fr' => 'Comment confirmer ou refuser une demande de réservation',
'body_en'  => '
<p>Every booking starts as a Pending request. You review it and either confirm or decline.</p>

<h3>Where to manage bookings</h3>
<p>Go to <a href="' . KB_SITE_URL . '/manage-bookings">Manage Bookings</a> from your dashboard or the My Account menu.</p>

<h3>Confirming a booking</h3>
<ol>
  <li>Find the booking in the <strong>Pending</strong> tab</li>
  <li>Optionally add a note (e.g. "Please arrive 5 minutes early")</li>
  <li>Click <strong>✅ Confirm</strong></li>
</ol>
<p>The booking moves to the Confirmed tab. You can also click the WhatsApp link to send the customer a confirmation message directly.</p>

<h3>Declining a booking</h3>
<ol>
  <li>Find the booking in the <strong>Pending</strong> tab</li>
  <li>Click <strong>✕ Decline</strong></li>
  <li>Confirm the decline in the popup</li>
</ol>

<h3>Contacting the customer</h3>
<p>Each booking card shows the customer\'s phone number as a WhatsApp link. Tap it to open a pre-filled WhatsApp message to that customer.</p>

<h3>Best practice</h3>
<p>Try to respond to booking requests within a few hours. Customers who have not heard back after 24 hours are likely to contact another business.</p>
',
'body_fr'  => '
<p>Chaque réservation commence comme une demande En attente. Vous la consultez et la confirmez ou la refusez.</p>

<h3>Où gérer les réservations</h3>
<p>Allez sur <a href="' . KB_SITE_URL . '/manage-bookings">Gérer les réservations</a> depuis votre tableau de bord ou le menu Mon Compte.</p>

<h3>Confirmer une réservation</h3>
<ol>
  <li>Trouvez la réservation dans l\'onglet <strong>En attente</strong></li>
  <li>Ajoutez optionnellement une note (ex. "Merci d\'arriver 5 minutes à l\'avance")</li>
  <li>Cliquez sur <strong>✅ Confirmer</strong></li>
</ol>
<p>La réservation passe dans l\'onglet Confirmées. Vous pouvez également cliquer sur le lien WhatsApp pour envoyer directement un message de confirmation au client.</p>

<h3>Refuser une réservation</h3>
<ol>
  <li>Trouvez la réservation dans l\'onglet <strong>En attente</strong></li>
  <li>Cliquez sur <strong>✕ Décliner</strong></li>
  <li>Confirmez le refus dans la fenêtre contextuelle</li>
</ol>

<h3>Contacter le client</h3>
<p>Chaque carte de réservation affiche le numéro de téléphone du client sous forme de lien WhatsApp. Appuyez dessus pour ouvrir un message WhatsApp pré-rempli à ce client.</p>

<h3>Bonne pratique</h3>
<p>Essayez de répondre aux demandes de réservation dans les quelques heures. Les clients qui n\'ont pas eu de réponse après 24 heures sont susceptibles de contacter une autre entreprise.</p>
',
],

[
'slug'     => 'how-customers-book',
'section'  => 'booking',
'order'    => 5,
'title_en' => 'How customers book an appointment (customer view)',
'title_fr' => 'Comment les clients prennent rendez-vous (côté client)',
'body_en'  => '
<p>This article explains the booking experience from the customer\'s point of view — useful for understanding what your customers see and for helping them if they have questions.</p>

<h3>The booking flow</h3>
<ol>
  <li>Customer visits your listing page on 237Biz</li>
  <li>They click the green <strong>Book an Appointment</strong> button</li>
  <li>They fill in their name, phone number, and optionally their email and a message</li>
  <li>They select a service (if you have set up bookable services)</li>
  <li>They pick a date — the available time slots load automatically</li>
  <li>They select a time slot and click <strong>Request Appointment</strong></li>
  <li>They receive a confirmation screen showing their booking reference (e.g. BK-A3F7X2)</li>
  <li>If they provided an email, they also receive a confirmation email</li>
</ol>

<h3>What the customer sees after booking</h3>
<p>The confirmation screen shows:</p>
<ul>
  <li>Their unique booking reference</li>
  <li>The date and time they chose</li>
  <li>Your business name and location</li>
  <li>A WhatsApp button to notify you directly</li>
</ul>

<h3>Slots that are already taken</h3>
<p>Booked and blocked time slots do not appear in the customer\'s time picker. If no slots are available on a date, the customer sees "No slots available on this date — please try another day."</p>
',
'body_fr'  => '
<p>Cet article explique l\'expérience de réservation du point de vue du client — utile pour comprendre ce que vos clients voient et pour les aider s\'ils ont des questions.</p>

<h3>Le processus de réservation</h3>
<ol>
  <li>Le client visite votre page d\'annonce sur 237Biz</li>
  <li>Il clique sur le bouton vert <strong>Prendre rendez-vous</strong></li>
  <li>Il renseigne son nom, numéro de téléphone, et optionnellement son e-mail et un message</li>
  <li>Il sélectionne un service (si vous avez configuré des services à réserver)</li>
  <li>Il choisit une date — les créneaux disponibles se chargent automatiquement</li>
  <li>Il sélectionne un créneau et clique sur <strong>Demander un rendez-vous</strong></li>
  <li>Il reçoit un écran de confirmation affichant sa référence de réservation (ex. BK-A3F7X2)</li>
  <li>S\'il a fourni un e-mail, il reçoit également un e-mail de confirmation</li>
</ol>

<h3>Ce que le client voit après la réservation</h3>
<p>L\'écran de confirmation affiche :</p>
<ul>
  <li>Sa référence de réservation unique</li>
  <li>La date et l\'heure choisies</li>
  <li>Le nom et la localisation de votre entreprise</li>
  <li>Un bouton WhatsApp pour vous notifier directement</li>
</ul>

<h3>Les créneaux déjà pris</h3>
<p>Les créneaux réservés et bloqués n\'apparaissent pas dans le sélecteur de créneaux du client. Si aucun créneau n\'est disponible pour une date, le client voit "Aucun créneau disponible ce jour — essayez un autre jour."</p>
',
],

// ── SECTION 4: Analytics ─────────────────────────────────────────────────

[
'slug'     => 'analytics-dashboard',
'section'  => 'analytics',
'order'    => 1,
'title_en' => 'Understanding your analytics dashboard',
'title_fr' => 'Comprendre votre tableau de bord analytique',
'body_en'  => '
<p>Your analytics dashboard gives you a clear picture of how your listing is performing. Go to <a href="' . KB_SITE_URL . '/analytics">237biz.net/analytics</a> to access it.</p>

<h3>The four stat cards</h3>
<ul>
  <li><strong>Total Views</strong> — how many times your listing page has been visited in total</li>
  <li><strong>Enquiries</strong> — how many contact forms have been submitted from your listing</li>
  <li><strong>Bookings</strong> — how many appointment requests you have received</li>
  <li><strong>Profile Score</strong> — how complete your listing is (see below)</li>
</ul>

<h3>Date range filter</h3>
<p>Use the <strong>Last 7 days / Last 30 days / Last 90 days</strong> buttons to change the time period for the stat cards and the views chart.</p>

<h3>The views chart</h3>
<p>The bar chart shows your daily views over the selected period. Hover over a bar to see the exact date and view count. A flat chart with no bars means no views were recorded in that period — this is normal for new listings.</p>

<h3>If you have multiple listings</h3>
<p>Use the dropdown at the top to switch between your listings. Each listing has its own analytics.</p>
',
'body_fr'  => '
<p>Votre tableau de bord analytique vous donne une image claire des performances de votre annonce. Allez sur <a href="' . KB_SITE_URL . '/analytics">237biz.net/analytics</a> pour y accéder.</p>

<h3>Les quatre cartes de statistiques</h3>
<ul>
  <li><strong>Vues totales</strong> — combien de fois votre page d\'annonce a été visitée au total</li>
  <li><strong>Demandes</strong> — combien de formulaires de contact ont été soumis depuis votre annonce</li>
  <li><strong>Réservations</strong> — combien de demandes de rendez-vous vous avez reçues</li>
  <li><strong>Score profil</strong> — dans quelle mesure votre annonce est complète (voir ci-dessous)</li>
</ul>

<h3>Filtre par période</h3>
<p>Utilisez les boutons <strong>7 derniers jours / 30 derniers jours / 90 derniers jours</strong> pour changer la période des cartes de statistiques et du graphique de vues.</p>

<h3>Le graphique de vues</h3>
<p>Le graphique à barres affiche vos vues quotidiennes sur la période sélectionnée. Survolez une barre pour voir la date exacte et le nombre de vues. Un graphique plat sans barres signifie qu\'aucune vue n\'a été enregistrée dans cette période — c\'est normal pour les nouvelles annonces.</p>

<h3>Si vous avez plusieurs annonces</h3>
<p>Utilisez le menu déroulant en haut pour basculer entre vos annonces. Chaque annonce a ses propres analytiques.</p>
',
],

[
'slug'     => 'what-counts-as-view',
'section'  => 'analytics',
'order'    => 2,
'title_en' => 'What counts as a view?',
'title_fr' => 'Qu\'est-ce qui compte comme une vue ?',
'body_en'  => '
<p>A view is counted each time someone visits your listing page at <strong>237biz.net/listing/your-business-name</strong>.</p>

<h3>What counts</h3>
<ul>
  <li>Any visit to your listing page from a web browser</li>
  <li>Visits from mobile phones, tablets and computers</li>
  <li>Visits from Google search results, social media links or direct URL</li>
</ul>

<h3>What does not count</h3>
<ul>
  <li>Your own visits when logged in as the listing owner</li>
  <li>Visits to the directory homepage or category pages</li>
  <li>Visits to your dashboard or edit page</li>
</ul>

<h3>Why views matter</h3>
<p>Views show you how visible your listing is. If your views are low, focus on completing your profile (higher profile scores rank better) and adding more services and keywords to attract search traffic.</p>

<h3>Total views vs period views</h3>
<p>The <strong>Total Views</strong> card shows your all-time views. The number below it shows views within the selected date range (7, 30 or 90 days). Use the period view to track recent trends.</p>
',
'body_fr'  => '
<p>Une vue est comptabilisée chaque fois que quelqu\'un visite votre page d\'annonce sur <strong>237biz.net/listing/nom-de-votre-entreprise</strong>.</p>

<h3>Ce qui compte</h3>
<ul>
  <li>Toute visite de votre page d\'annonce depuis un navigateur web</li>
  <li>Visites depuis des téléphones mobiles, tablettes et ordinateurs</li>
  <li>Visites depuis les résultats Google, liens de réseaux sociaux ou URL directe</li>
</ul>

<h3>Ce qui ne compte pas</h3>
<ul>
  <li>Vos propres visites lorsque vous êtes connecté en tant que propriétaire de l\'annonce</li>
  <li>Visites de la page d\'accueil du répertoire ou des pages de catégories</li>
  <li>Visites de votre tableau de bord ou de la page de modification</li>
</ul>

<h3>Pourquoi les vues sont importantes</h3>
<p>Les vues montrent la visibilité de votre annonce. Si vos vues sont faibles, concentrez-vous sur la complétion de votre profil (un score de profil plus élevé se classe mieux) et ajoutez plus de services et mots-clés pour attirer du trafic de recherche.</p>

<h3>Vues totales vs vues sur la période</h3>
<p>La carte <strong>Vues totales</strong> affiche vos vues de tous les temps. Le nombre en dessous affiche les vues dans la période sélectionnée (7, 30 ou 90 jours). Utilisez la vue de période pour suivre les tendances récentes.</p>
',
],

[
'slug'     => 'profile-score',
'section'  => 'analytics',
'order'    => 3,
'title_en' => 'Your profile completion score — and why it matters',
'title_fr' => 'Votre score de complétion de profil — et pourquoi il est important',
'body_en'  => '
<p>Your profile score is a percentage showing how complete your listing is. A higher score means more visibility and more trust from customers.</p>

<h3>How it is calculated</h3>
<p>Your score is based on 9 checks:</p>
<ul>
  <li>✅ Business name</li>
  <li>✅ Tagline (Featured listings)</li>
  <li>✅ Description (at least 50 words)</li>
  <li>✅ Phone or WhatsApp number</li>
  <li>✅ Services listed</li>
  <li>✅ Keywords added</li>
  <li>✅ Business hours set</li>
  <li>✅ Call-to-action button</li>
  <li>✅ Video link</li>
</ul>
<p>Each completed item adds approximately 11% to your score.</p>

<h3>Why it matters</h3>
<ul>
  <li>Listings with higher profile scores rank better in directory search results</li>
  <li>Customers trust detailed listings more and are more likely to make contact</li>
  <li>A complete profile with a logo and description gets significantly more enquiries</li>
</ul>

<h3>How to improve your score</h3>
<p>The analytics page shows a checklist of exactly which items are complete and which are missing, with direct links to the relevant section of your Edit Listing page. Click <strong>Add →</strong> next to any incomplete item to fix it immediately.</p>
',
'body_fr'  => '
<p>Votre score de profil est un pourcentage indiquant dans quelle mesure votre annonce est complète. Un score plus élevé signifie plus de visibilité et plus de confiance de la part des clients.</p>

<h3>Comment il est calculé</h3>
<p>Votre score est basé sur 9 vérifications :</p>
<ul>
  <li>✅ Nom de l\'entreprise</li>
  <li>✅ Accroche (annonces vedettes)</li>
  <li>✅ Description (au moins 50 mots)</li>
  <li>✅ Numéro de téléphone ou WhatsApp</li>
  <li>✅ Services listés</li>
  <li>✅ Mots-clés ajoutés</li>
  <li>✅ Horaires d\'ouverture définis</li>
  <li>✅ Bouton d\'appel à l\'action</li>
  <li>✅ Lien vidéo</li>
</ul>
<p>Chaque élément complété ajoute environ 11% à votre score.</p>

<h3>Pourquoi c\'est important</h3>
<ul>
  <li>Les annonces avec des scores de profil plus élevés se classent mieux dans les résultats de recherche du répertoire</li>
  <li>Les clients font davantage confiance aux annonces détaillées et sont plus susceptibles de prendre contact</li>
  <li>Un profil complet avec logo et description reçoit nettement plus de demandes</li>
</ul>

<h3>Comment améliorer votre score</h3>
<p>La page analytiques affiche une liste de contrôle montrant exactement quels éléments sont complets et lesquels manquent, avec des liens directs vers la section concernée de votre page de modification. Cliquez sur <strong>Ajouter →</strong> à côté de tout élément incomplet pour le corriger immédiatement.</p>
',
],

[
'slug'     => 'qr-code',
'section'  => 'analytics',
'order'    => 4,
'title_en' => 'Your QR code — how to download, print and share it',
'title_fr' => 'Votre code QR — comment le télécharger, imprimer et partager',
'body_en'  => '
<p>Every 237Biz listing has a unique QR code that links directly to your listing page. Customers scan it with their phone camera to go straight to your profile.</p>

<h3>Where to find your QR code</h3>
<ul>
  <li><strong>Analytics page</strong> — in the right sidebar at <a href="' . KB_SITE_URL . '/analytics">237biz.net/analytics</a></li>
  <li><strong>Dashboard</strong> — click the QR Code quick-action card</li>
  <li><strong>Your listing page</strong> — the owner bar at the bottom of your listing (visible only to you when logged in)</li>
</ul>

<h3>How to download</h3>
<p>Click <strong>⬇️ Download QR Code</strong>. A high-resolution PNG file (600×600 pixels) will download to your device, ready to print.</p>

<h3>How to use your QR code</h3>
<ul>
  <li><strong>Shop window</strong> — print and stick it on your door or window so passers-by can scan it</li>
  <li><strong>Business cards</strong> — add it to the back of your business card</li>
  <li><strong>Receipts and invoices</strong> — include it so customers can easily leave a review or rebook</li>
  <li><strong>WhatsApp status</strong> — screenshot your QR code and share it as a WhatsApp status</li>
  <li><strong>Printed flyers</strong> — add to any marketing material</li>
</ul>

<h3>The QR code never changes</h3>
<p>Your QR code always links to your listing URL. As long as your listing slug (the part after /listing/) stays the same, the QR code remains valid indefinitely.</p>
',
'body_fr'  => '
<p>Chaque annonce 237Biz a un code QR unique qui renvoie directement vers votre page d\'annonce. Les clients le scannent avec l\'appareil photo de leur téléphone pour accéder directement à votre profil.</p>

<h3>Où trouver votre code QR</h3>
<ul>
  <li><strong>Page analytiques</strong> — dans la barre latérale droite sur <a href="' . KB_SITE_URL . '/analytics">237biz.net/analytics</a></li>
  <li><strong>Tableau de bord</strong> — cliquez sur la carte d\'action rapide Code QR</li>
  <li><strong>Votre page d\'annonce</strong> — la barre propriétaire en bas de votre annonce (visible uniquement pour vous lorsque connecté)</li>
</ul>

<h3>Comment télécharger</h3>
<p>Cliquez sur <strong>⬇️ Télécharger le code QR</strong>. Un fichier PNG haute résolution (600×600 pixels) se téléchargera sur votre appareil, prêt à imprimer.</p>

<h3>Comment utiliser votre code QR</h3>
<ul>
  <li><strong>Vitrine</strong> — imprimez-le et collez-le sur votre porte ou fenêtre pour que les passants puissent le scanner</li>
  <li><strong>Cartes de visite</strong> — ajoutez-le au dos de votre carte de visite</li>
  <li><strong>Reçus et factures</strong> — incluez-le pour que les clients puissent facilement laisser un avis ou réserver à nouveau</li>
  <li><strong>Statut WhatsApp</strong> — faites une capture d\'écran de votre code QR et partagez-la comme statut WhatsApp</li>
  <li><strong>Flyers imprimés</strong> — ajoutez-le à tout support marketing</li>
</ul>

<h3>Le code QR ne change jamais</h3>
<p>Votre code QR renvoie toujours vers l\'URL de votre annonce. Tant que le slug de votre annonce (la partie après /listing/) reste le même, le code QR reste valide indéfiniment.</p>
',
],

// ── SECTION 5: Announcements ─────────────────────────────────────────────

[
'slug'     => 'create-announcement',
'section'  => 'announcements',
'order'    => 1,
'title_en' => 'Creating a promotional announcement',
'title_fr' => 'Créer une annonce promotionnelle',
'body_en'  => '
<p>Promotional announcements appear as a coloured banner at the top of your listing page, catching the attention of every visitor during the promotional period.</p>

<h3>How to create an announcement</h3>
<ol>
  <li>Go to <a href="' . KB_SITE_URL . '/manage-announcements">Manage Announcements</a> from your dashboard</li>
  <li>Choose an announcement type (Offer, Event, Info or Urgent)</li>
  <li>Enter a title (e.g. "25% off all haircuts this week!")</li>
  <li>Optionally add a badge text (e.g. "25% OFF")</li>
  <li>Optionally add a message with more detail</li>
  <li>Set the start and end date</li>
  <li>Make sure <strong>Active</strong> is checked</li>
  <li>Click <strong>Create Announcement</strong></li>
</ol>

<h3>Live preview</h3>
<p>As you type, the preview below the form updates in real time so you can see exactly how your announcement will look on your listing page before saving.</p>

<h3>Your announcement goes live immediately</h3>
<p>Once saved and active, the announcement banner appears on your listing page straight away. It disappears automatically when the end date passes.</p>
',
'body_fr'  => '
<p>Les annonces promotionnelles apparaissent comme une bannière colorée en haut de votre page d\'annonce, attirant l\'attention de chaque visiteur pendant la période promotionnelle.</p>

<h3>Comment créer une annonce</h3>
<ol>
  <li>Allez sur <a href="' . KB_SITE_URL . '/manage-announcements">Gérer les annonces</a> depuis votre tableau de bord</li>
  <li>Choisissez un type d\'annonce (Offre, Événement, Info ou Urgent)</li>
  <li>Entrez un titre (ex. "25% de réduction sur toutes les coupes cette semaine !")</li>
  <li>Ajoutez optionnellement un texte de badge (ex. "25% OFF")</li>
  <li>Ajoutez optionnellement un message avec plus de détails</li>
  <li>Définissez la date de début et de fin</li>
  <li>Assurez-vous que <strong>Actif</strong> est coché</li>
  <li>Cliquez sur <strong>Créer l\'annonce</strong></li>
</ol>

<h3>Aperçu en direct</h3>
<p>Pendant que vous tapez, l\'aperçu sous le formulaire se met à jour en temps réel pour que vous puissiez voir exactement à quoi ressemblera votre annonce sur votre page avant d\'enregistrer.</p>

<h3>Votre annonce est publiée immédiatement</h3>
<p>Une fois enregistrée et active, la bannière d\'annonce apparaît immédiatement sur votre page d\'annonce. Elle disparaît automatiquement lorsque la date de fin est passée.</p>
',
],

[
'slug'     => 'announcement-types',
'section'  => 'announcements',
'order'    => 2,
'title_en' => 'The four announcement types — offer, event, info, urgent',
'title_fr' => 'Les quatre types d\'annonces — offre, événement, info, urgent',
'body_en'  => '
<p>Each announcement type has a different colour and is suited to a different purpose.</p>

<h3>🏷️ Offer (yellow)</h3>
<p>Use for discounts, promotions and special deals.</p>
<p><strong>Examples:</strong> "End of month sale — 20% off all services", "Buy 2 get 1 free this week", "Free delivery on orders over 5,000 XAF"</p>

<h3>📅 Event (blue)</h3>
<p>Use for launches, open days, product releases or any time-limited event.</p>
<p><strong>Examples:</strong> "Grand opening — come visit us this Saturday", "New menu launching 1 September", "Live music every Friday evening"</p>

<h3>ℹ️ Info (green)</h3>
<p>Use for general updates, new services or important information.</p>
<p><strong>Examples:</strong> "We now accept MTN MoMo", "New branch opening in Buea next month", "Extended opening hours during the holiday season"</p>

<h3>🔔 Urgent (red)</h3>
<p>Use for time-sensitive or important notices that need immediate customer attention.</p>
<p><strong>Examples:</strong> "Closing early today at 14:00", "Emergency closure — will reopen Monday", "Last day for holiday orders — order by 5pm today"</p>

<h3>Only one announcement shows at a time</h3>
<p>If you have multiple active announcements, only the most recently created one appears on your listing page. Plan your announcements so the most important one is the latest one.</p>
',
'body_fr'  => '
<p>Chaque type d\'annonce a une couleur différente et convient à un objectif différent.</p>

<h3>🏷️ Offre (jaune)</h3>
<p>À utiliser pour les remises, promotions et offres spéciales.</p>
<p><strong>Exemples :</strong> "Soldes de fin de mois — 20% sur tous les services", "Achetez 2 obtenez 1 gratuit cette semaine", "Livraison gratuite pour les commandes supérieures à 5 000 XAF"</p>

<h3>📅 Événement (bleu)</h3>
<p>À utiliser pour les lancements, journées portes ouvertes, sorties de produits ou tout événement limité dans le temps.</p>
<p><strong>Exemples :</strong> "Grande ouverture — venez nous rendre visite ce samedi", "Nouveau menu le 1er septembre", "Musique live tous les vendredis soir"</p>

<h3>ℹ️ Info (vert)</h3>
<p>À utiliser pour les mises à jour générales, nouveaux services ou informations importantes.</p>
<p><strong>Exemples :</strong> "Nous acceptons désormais MTN MoMo", "Nouvelle succursale à Buea le mois prochain", "Horaires étendus pendant la saison des fêtes"</p>

<h3>🔔 Urgent (rouge)</h3>
<p>À utiliser pour les avis urgents ou importants nécessitant une attention immédiate des clients.</p>
<p><strong>Exemples :</strong> "Fermeture anticipée aujourd\'hui à 14h00", "Fermeture d\'urgence — réouverture lundi", "Dernier jour pour les commandes de fêtes — commandez avant 17h aujourd\'hui"</p>

<h3>Une seule annonce s\'affiche à la fois</h3>
<p>Si vous avez plusieurs annonces actives, seule la plus récemment créée apparaît sur votre page d\'annonce. Planifiez vos annonces de sorte que la plus importante soit la plus récente.</p>
',
],

[
'slug'     => 'announcement-scheduling',
'section'  => 'announcements',
'order'    => 3,
'title_en' => 'Scheduling a promotion with start and end dates',
'title_fr' => 'Planifier une promotion avec dates de début et de fin',
'body_en'  => '
<p>You can schedule announcements in advance so they appear and disappear automatically on the dates you choose.</p>

<h3>Setting start and end dates</h3>
<ul>
  <li><strong>Start date</strong> — when the announcement starts appearing on your listing. Defaults to now if left unchanged.</li>
  <li><strong>End date</strong> — when the announcement automatically stops showing. Required.</li>
</ul>

<h3>Example: scheduling a weekend sale</h3>
<ol>
  <li>Create the announcement now with type "Offer"</li>
  <li>Set the start date to Friday 18:00</li>
  <li>Set the end date to Sunday 23:59</li>
  <li>Save — the banner will appear automatically on Friday evening and disappear on Sunday night</li>
</ol>

<h3>Activating and deactivating manually</h3>
<p>You can toggle any announcement on or off at any time using the <strong>Deactivate / Activate</strong> button on the Manage Announcements page — without deleting it. This is useful if you want to pause a promotion and restart it later.</p>

<h3>Expired announcements</h3>
<p>When an announcement passes its end date it stops showing automatically. It remains in your list marked as Expired so you can reuse it later by editing the dates and reactivating it.</p>
',
'body_fr'  => '
<p>Vous pouvez planifier des annonces à l\'avance pour qu\'elles apparaissent et disparaissent automatiquement aux dates que vous choisissez.</p>

<h3>Définir les dates de début et de fin</h3>
<ul>
  <li><strong>Date de début</strong> — quand l\'annonce commence à apparaître sur votre annonce. Par défaut maintenant si non modifié.</li>
  <li><strong>Date de fin</strong> — quand l\'annonce arrête automatiquement de s\'afficher. Obligatoire.</li>
</ul>

<h3>Exemple : planifier une vente du week-end</h3>
<ol>
  <li>Créez l\'annonce maintenant avec le type "Offre"</li>
  <li>Définissez la date de début au vendredi à 18h00</li>
  <li>Définissez la date de fin au dimanche à 23h59</li>
  <li>Enregistrez — la bannière apparaîtra automatiquement vendredi soir et disparaîtra dimanche soir</li>
</ol>

<h3>Activer et désactiver manuellement</h3>
<p>Vous pouvez activer ou désactiver n\'importe quelle annonce à tout moment en utilisant le bouton <strong>Désactiver / Activer</strong> sur la page Gérer les annonces — sans la supprimer. C\'est utile si vous souhaitez mettre une promotion en pause et la relancer plus tard.</p>

<h3>Annonces expirées</h3>
<p>Lorsqu\'une annonce dépasse sa date de fin, elle arrête de s\'afficher automatiquement. Elle reste dans votre liste marquée comme Expirée pour que vous puissiez la réutiliser plus tard en modifiant les dates et en la réactivant.</p>
',
],

// ── SECTION 6: Customers ─────────────────────────────────────────────────

[
'slug'     => 'find-a-business',
'section'  => 'customers',
'order'    => 1,
'title_en' => 'How to find a business near you',
'title_fr' => 'Comment trouver une entreprise près de chez vous',
'body_en'  => '
<p>237Biz is a directory of Cameroonian businesses. Here is how to find what you are looking for.</p>

<h3>Search by category</h3>
<p>On the <a href="' . KB_SITE_URL . '">homepage</a>, browse the category icons or use the search bar. Click a category to see all businesses in that sector.</p>

<h3>Search by location</h3>
<p>Use the location filter to narrow results to your city — Limbe, Buea, Douala, Yaoundé and more.</p>

<h3>Search by keyword</h3>
<p>Type what you are looking for in the search bar — e.g. "plumber Limbe" or "web design Douala" — and the directory will show matching businesses.</p>

<h3>Reading a listing</h3>
<p>Each listing shows the business name, category, location, description, services and contact details. Featured listings (marked with ⭐) include more detail and are verified businesses.</p>

<h3>Filter by "open now"</h3>
<p>Use the Open Now filter to show only businesses that are currently open based on their listed hours.</p>
',
'body_fr'  => '
<p>237Biz est un répertoire d\'entreprises camerounaises. Voici comment trouver ce que vous cherchez.</p>

<h3>Rechercher par catégorie</h3>
<p>Sur la <a href="' . KB_SITE_URL . '">page d\'accueil</a>, parcourez les icônes de catégories ou utilisez la barre de recherche. Cliquez sur une catégorie pour voir toutes les entreprises de ce secteur.</p>

<h3>Rechercher par localisation</h3>
<p>Utilisez le filtre de localisation pour restreindre les résultats à votre ville — Limbe, Buea, Douala, Yaoundé et plus encore.</p>

<h3>Rechercher par mot-clé</h3>
<p>Tapez ce que vous cherchez dans la barre de recherche — ex. "plombier Limbe" ou "web design Douala" — et le répertoire affichera les entreprises correspondantes.</p>

<h3>Lire une annonce</h3>
<p>Chaque annonce affiche le nom de l\'entreprise, la catégorie, la localisation, la description, les services et les coordonnées. Les annonces vedettes (marquées ⭐) incluent plus de détails et sont des entreprises vérifiées.</p>

<h3>Filtrer par "ouvert maintenant"</h3>
<p>Utilisez le filtre Ouvert maintenant pour afficher uniquement les entreprises actuellement ouvertes selon leurs horaires indiqués.</p>
',
],

[
'slug'     => 'customer-book-appointment',
'section'  => 'customers',
'order'    => 2,
'title_en' => 'How to book an appointment with a business',
'title_fr' => 'Comment prendre rendez-vous avec une entreprise',
'body_en'  => '
<p>Some businesses on 237Biz accept appointment bookings directly through the site. Here is how to book.</p>

<h3>Step by step</h3>
<ol>
  <li>Find the business listing you want to book with</li>
  <li>Click the green <strong>Book an Appointment</strong> button on their listing page</li>
  <li>Fill in your name and phone number (required) and your email (optional)</li>
  <li>Select the service you want if the business has listed services</li>
  <li>Choose a date — the available time slots will load automatically</li>
  <li>Click a time slot to select it</li>
  <li>Add any notes or special requests in the message field</li>
  <li>Click <strong>Request Appointment</strong></li>
</ol>

<h3>Your booking reference</h3>
<p>After submitting you will see a confirmation screen with a unique booking reference (e.g. BK-A3F7X2). Keep this reference — you may need it if you contact the business.</p>

<h3>What happens next</h3>
<p>Your request is sent to the business. They will confirm or decline it. If you provided an email, you will receive a confirmation message. You can also tap the WhatsApp button on the confirmation screen to notify the business directly.</p>

<h3>If no time slots are available</h3>
<p>If the business has no available slots on a date, try a different date. You can also contact the business directly via WhatsApp or phone to arrange a time.</p>
',
'body_fr'  => '
<p>Certaines entreprises sur 237Biz acceptent les réservations directement via le site. Voici comment réserver.</p>

<h3>Étape par étape</h3>
<ol>
  <li>Trouvez la page d\'annonce de l\'entreprise avec laquelle vous souhaitez réserver</li>
  <li>Cliquez sur le bouton vert <strong>Prendre rendez-vous</strong> sur leur page d\'annonce</li>
  <li>Renseignez votre nom et numéro de téléphone (obligatoire) et votre e-mail (optionnel)</li>
  <li>Sélectionnez le service souhaité si l\'entreprise a listé des services</li>
  <li>Choisissez une date — les créneaux disponibles se chargeront automatiquement</li>
  <li>Cliquez sur un créneau pour le sélectionner</li>
  <li>Ajoutez des notes ou demandes spéciales dans le champ message</li>
  <li>Cliquez sur <strong>Demander un rendez-vous</strong></li>
</ol>

<h3>Votre référence de réservation</h3>
<p>Après soumission, vous verrez un écran de confirmation avec une référence unique (ex. BK-A3F7X2). Conservez cette référence — vous pourriez en avoir besoin si vous contactez l\'entreprise.</p>

<h3>Que se passe-t-il ensuite</h3>
<p>Votre demande est envoyée à l\'entreprise. Elle la confirmera ou la refusera. Si vous avez fourni un e-mail, vous recevrez un message de confirmation. Vous pouvez également appuyer sur le bouton WhatsApp sur l\'écran de confirmation pour notifier directement l\'entreprise.</p>

<h3>Si aucun créneau n\'est disponible</h3>
<p>Si l\'entreprise n\'a pas de créneaux disponibles à une date, essayez une autre date. Vous pouvez également contacter l\'entreprise directement via WhatsApp ou téléphone pour convenir d\'un horaire.</p>
',
],

[
'slug'     => 'send-enquiry',
'section'  => 'customers',
'order'    => 3,
'title_en' => 'How to send an enquiry to a business',
'title_fr' => 'Comment envoyer une demande à une entreprise',
'body_en'  => '
<p>You can send a message directly to a business through their 237Biz listing without needing their email address.</p>

<h3>How to send an enquiry</h3>
<ol>
  <li>Go to the business listing page</li>
  <li>Scroll down to the <strong>Send an Enquiry</strong> or <strong>Contact</strong> section</li>
  <li>Fill in your name, email and your message</li>
  <li>Click <strong>Send Enquiry</strong></li>
</ol>

<h3>What happens next</h3>
<p>Your message is sent directly to the business by email. Most businesses respond within a few hours during business hours. If you do not hear back within 24 hours, try contacting them via WhatsApp instead.</p>

<h3>Faster contact via WhatsApp</h3>
<p>For an instant response, click the green floating <strong>WhatsApp</strong> button on any listing page. This opens a pre-filled WhatsApp message — simply send it and wait for a reply.</p>
',
'body_fr'  => '
<p>Vous pouvez envoyer un message directement à une entreprise via sa page d\'annonce 237Biz sans avoir besoin de son adresse e-mail.</p>

<h3>Comment envoyer une demande</h3>
<ol>
  <li>Allez sur la page d\'annonce de l\'entreprise</li>
  <li>Faites défiler jusqu\'à la section <strong>Envoyer une demande</strong> ou <strong>Contact</strong></li>
  <li>Renseignez votre nom, e-mail et votre message</li>
  <li>Cliquez sur <strong>Envoyer la demande</strong></li>
</ol>

<h3>Que se passe-t-il ensuite</h3>
<p>Votre message est envoyé directement à l\'entreprise par e-mail. La plupart des entreprises répondent dans quelques heures pendant les heures ouvrables. Si vous n\'avez pas de réponse dans les 24 heures, essayez de les contacter via WhatsApp à la place.</p>

<h3>Contact plus rapide via WhatsApp</h3>
<p>Pour une réponse instantanée, cliquez sur le bouton flottant vert <strong>WhatsApp</strong> sur n\'importe quelle page d\'annonce. Cela ouvre un message WhatsApp pré-rempli — envoyez-le simplement et attendez une réponse.</p>
',
],

[
'slug'     => 'contact-on-whatsapp',
'section'  => 'customers',
'order'    => 4,
'title_en' => 'How to contact a business on WhatsApp',
'title_fr' => 'Comment contacter une entreprise sur WhatsApp',
'body_en'  => '
<p>WhatsApp is the fastest way to reach a business on 237Biz. Here is how to use the WhatsApp chat widget.</p>

<h3>The floating WhatsApp button</h3>
<p>On any listing page where the business has a WhatsApp number, you will see a green floating button in the bottom-right corner of the screen. Click or tap it to open WhatsApp with a pre-filled message.</p>

<h3>The pre-filled message</h3>
<p>The message reads: <em>"Hi, I found your business on 237Biz and would like to enquire about your services."</em></p>
<p>You can edit this message before sending to add more detail about what you need.</p>

<h3>WhatsApp not opening?</h3>
<ul>
  <li>Make sure WhatsApp is installed on your phone</li>
  <li>On a desktop computer, WhatsApp Web will open if you are logged in</li>
  <li>If the button is not visible, the business may not have a WhatsApp number listed — use the contact form instead</li>
</ul>

<h3>Contact details on the listing</h3>
<p>You can also find the business phone number, email and social media links in the contact section of their listing page.</p>
',
'body_fr'  => '
<p>WhatsApp est le moyen le plus rapide de contacter une entreprise sur 237Biz. Voici comment utiliser le widget de chat WhatsApp.</p>

<h3>Le bouton WhatsApp flottant</h3>
<p>Sur toute page d\'annonce où l\'entreprise a un numéro WhatsApp, vous verrez un bouton vert flottant dans le coin inférieur droit de l\'écran. Cliquez ou appuyez dessus pour ouvrir WhatsApp avec un message pré-rempli.</p>

<h3>Le message pré-rempli</h3>
<p>Le message dit : <em>"Bonjour, j\'ai trouvé votre entreprise sur 237Biz et je souhaite me renseigner sur vos services."</em></p>
<p>Vous pouvez modifier ce message avant de l\'envoyer pour ajouter plus de détails sur ce dont vous avez besoin.</p>

<h3>WhatsApp ne s\'ouvre pas ?</h3>
<ul>
  <li>Assurez-vous que WhatsApp est installé sur votre téléphone</li>
  <li>Sur un ordinateur de bureau, WhatsApp Web s\'ouvrira si vous êtes connecté</li>
  <li>Si le bouton n\'est pas visible, l\'entreprise n\'a peut-être pas de numéro WhatsApp listé — utilisez plutôt le formulaire de contact</li>
</ul>

<h3>Coordonnées sur l\'annonce</h3>
<p>Vous pouvez également trouver le numéro de téléphone, l\'e-mail et les liens de réseaux sociaux de l\'entreprise dans la section contact de sa page d\'annonce.</p>
',
],

// ── SECTION 7: Troubleshooting ───────────────────────────────────────────

[
'slug'     => 'listing-pending',
'section'  => 'troubleshooting',
'order'    => 1,
'title_en' => 'My listing says "Pending" — what does that mean?',
'title_fr' => 'Mon annonce indique "En attente" — que signifie cela ?',
'body_en'  => '
<p>A Pending status means your listing is waiting to be reviewed by the 237Biz team before it goes live.</p>

<h3>How long does it take?</h3>
<p>Reviews are usually completed within a few hours, and always within 24 hours on business days.</p>

<h3>Why might my listing stay pending?</h3>
<ul>
  <li>You submitted outside of business hours — it will be reviewed the next morning</li>
  <li>Your listing is missing important information such as a phone number or address</li>
  <li>Your description is too short or appears to be copy-pasted from another site</li>
  <li>The category or city you selected does not match the business</li>
</ul>

<h3>What you can do while pending</h3>
<p>You can still edit your listing while it is pending. Adding more detail to your description, uploading a logo and filling in all contact fields will speed up the approval process.</p>

<h3>If it has been more than 24 hours</h3>
<p>Contact us at <a href="mailto:support@237biz.net">support@237biz.net</a> with your business name and account email and we will check the status for you.</p>
',
'body_fr'  => '
<p>Un statut En attente signifie que votre annonce est en cours d\'examen par l\'équipe 237Biz avant d\'être publiée.</p>

<h3>Combien de temps cela prend-il ?</h3>
<p>Les examens sont généralement terminés dans quelques heures, et toujours dans les 24 heures les jours ouvrables.</p>

<h3>Pourquoi mon annonce reste-t-elle en attente ?</h3>
<ul>
  <li>Vous avez soumis en dehors des heures ouvrables — elle sera examinée le lendemain matin</li>
  <li>Votre annonce manque d\'informations importantes comme un numéro de téléphone ou une adresse</li>
  <li>Votre description est trop courte ou semble copiée d\'un autre site</li>
  <li>La catégorie ou la ville que vous avez sélectionnée ne correspond pas à l\'entreprise</li>
</ul>

<h3>Ce que vous pouvez faire pendant l\'attente</h3>
<p>Vous pouvez toujours modifier votre annonce pendant qu\'elle est en attente. Ajouter plus de détails à votre description, télécharger un logo et remplir tous les champs de contact accélérera le processus d\'approbation.</p>

<h3>Si cela fait plus de 24 heures</h3>
<p>Contactez-nous à <a href="mailto:support@237biz.net">support@237biz.net</a> avec le nom de votre entreprise et votre e-mail de compte et nous vérifierons le statut pour vous.</p>
',
],

[
'slug'     => 'listing-not-visible',
'section'  => 'troubleshooting',
'order'    => 2,
'title_en' => 'I can\'t see my listing on the site',
'title_fr' => 'Je ne vois pas mon annonce sur le site',
'body_en'  => '
<p>If you cannot find your listing in the directory, here are the most common reasons and how to fix them.</p>

<h3>Check the status on your dashboard</h3>
<p>Go to your <a href="' . KB_SITE_URL . '/dashboard">Dashboard</a> and look at the status column next to your listing. It will show one of:</p>
<ul>
  <li><strong>Approved</strong> — your listing is live and searchable</li>
  <li><strong>Pending</strong> — it is waiting for review. See <a href="' . KB_SITE_URL . '/help/listing-pending">My listing says Pending</a></li>
  <li><strong>Rejected</strong> — it was not approved. Check your email for the reason</li>
</ul>

<h3>Your listing is approved but you cannot find it in search</h3>
<ul>
  <li>New listings can take a few hours to appear in search results after approval</li>
  <li>Try searching by your exact business name</li>
  <li>Make sure you are looking in the right city filter</li>
  <li>Your listing URL is always: 237biz.net/listing/your-listing-slug</li>
</ul>

<h3>You cannot see your listing at all</h3>
<p>If your listing does not appear on your dashboard, make sure you are logged into the correct account. Check if you have another email address you might have registered with.</p>
',
'body_fr'  => '
<p>Si vous ne trouvez pas votre annonce dans le répertoire, voici les raisons les plus courantes et comment les résoudre.</p>

<h3>Vérifiez le statut sur votre tableau de bord</h3>
<p>Allez sur votre <a href="' . KB_SITE_URL . '/dashboard">Tableau de bord</a> et regardez la colonne statut à côté de votre annonce. Elle affichera l\'une des options suivantes :</p>
<ul>
  <li><strong>Approuvée</strong> — votre annonce est en ligne et consultable</li>
  <li><strong>En attente</strong> — elle attend une révision</li>
  <li><strong>Rejetée</strong> — elle n\'a pas été approuvée. Vérifiez votre e-mail pour la raison</li>
</ul>

<h3>Votre annonce est approuvée mais vous ne la trouvez pas dans la recherche</h3>
<ul>
  <li>Les nouvelles annonces peuvent prendre quelques heures à apparaître dans les résultats de recherche après approbation</li>
  <li>Essayez de rechercher par le nom exact de votre entreprise</li>
  <li>Assurez-vous de regarder dans le bon filtre de ville</li>
  <li>L\'URL de votre annonce est toujours : 237biz.net/listing/slug-de-votre-annonce</li>
</ul>

<h3>Vous ne voyez pas du tout votre annonce</h3>
<p>Si votre annonce n\'apparaît pas sur votre tableau de bord, assurez-vous d\'être connecté au bon compte. Vérifiez si vous avez une autre adresse e-mail avec laquelle vous vous êtes peut-être inscrit.</p>
',
],

[
'slug'     => 'not-receiving-emails',
'section'  => 'troubleshooting',
'order'    => 3,
'title_en' => 'I\'m not receiving email notifications',
'title_fr' => 'Je ne reçois pas les notifications par e-mail',
'body_en'  => '
<p>If you are not receiving emails from 237Biz (booking notifications, welcome emails, etc.), here is what to check.</p>

<h3>Check your spam folder</h3>
<p>Emails from 237Biz are sent from <strong>noreply@237biz.net</strong>. Check your spam or junk folder for emails from this address. If you find them there, mark them as "Not spam" to ensure future emails arrive in your inbox.</p>

<h3>Check your email address</h3>
<p>Log into your <a href="' . KB_SITE_URL . '/dashboard">Dashboard</a> and check that the email address shown is the one you are checking. If it is wrong, update it in your profile settings.</p>

<h3>Add 237Biz to your contacts</h3>
<p>Add <strong>noreply@237biz.net</strong> to your phone or email contacts. This helps prevent our emails from being filtered as spam in the future.</p>

<h3>For booking notifications specifically</h3>
<p>Booking notification emails are sent to the email address on your business listing — not necessarily your account email. Check that your listing has a valid email in the Contact Details section.</p>

<h3>Still not receiving emails?</h3>
<p>Contact us at <a href="mailto:support@237biz.net">support@237biz.net</a> and we will investigate.</p>
',
'body_fr'  => '
<p>Si vous ne recevez pas d\'e-mails de 237Biz (notifications de réservation, e-mails de bienvenue, etc.), voici ce qu\'il faut vérifier.</p>

<h3>Vérifiez votre dossier spam</h3>
<p>Les e-mails de 237Biz sont envoyés depuis <strong>noreply@237biz.net</strong>. Vérifiez votre dossier spam ou courrier indésirable pour les e-mails de cette adresse. Si vous les trouvez là, marquez-les comme "Pas du spam" pour que les futurs e-mails arrivent dans votre boîte de réception.</p>

<h3>Vérifiez votre adresse e-mail</h3>
<p>Connectez-vous à votre <a href="' . KB_SITE_URL . '/dashboard">Tableau de bord</a> et vérifiez que l\'adresse e-mail affichée est celle que vous consultez. Si elle est incorrecte, mettez-la à jour dans les paramètres de votre profil.</p>

<h3>Ajoutez 237Biz à vos contacts</h3>
<p>Ajoutez <strong>noreply@237biz.net</strong> à vos contacts téléphoniques ou e-mail. Cela évite que nos e-mails soient filtrés comme spam à l\'avenir.</p>

<h3>Pour les notifications de réservation spécifiquement</h3>
<p>Les e-mails de notification de réservation sont envoyés à l\'adresse e-mail de votre annonce commerciale — pas nécessairement votre e-mail de compte. Vérifiez que votre annonce a un e-mail valide dans la section Coordonnées.</p>

<h3>Toujours pas d\'e-mails ?</h3>
<p>Contactez-nous à <a href="mailto:support@237biz.net">support@237biz.net</a> et nous enquêterons.</p>
',
],

[
'slug'     => 'contact-support',
'section'  => 'troubleshooting',
'order'    => 4,
'title_en' => 'How to contact 237Biz support',
'title_fr' => 'Comment contacter le support 237Biz',
'body_en'  => '
<p>If you cannot find the answer to your question in this help centre, our team is here to help.</p>

<h3>Email support</h3>
<p>Send an email to <a href="mailto:support@237biz.net">support@237biz.net</a>. Include:</p>
<ul>
  <li>Your name and the email address you registered with</li>
  <li>Your business name (if applicable)</li>
  <li>A clear description of your issue or question</li>
</ul>
<p>We aim to respond within 24 hours on business days.</p>

<h3>WhatsApp support</h3>
<p>For faster responses, you can also reach us on WhatsApp. The number is listed on our <a href="' . KB_SITE_URL . '/contact">Contact page</a>.</p>

<h3>Before contacting us</h3>
<p>Please check the relevant help articles first — most common questions are answered here. The troubleshooting section is a good starting point:</p>
<ul>
  <li><a href="' . KB_SITE_URL . '/help/listing-pending">My listing says Pending</a></li>
  <li><a href="' . KB_SITE_URL . '/help/listing-not-visible">I can\'t see my listing</a></li>
  <li><a href="' . KB_SITE_URL . '/help/not-receiving-emails">I\'m not receiving emails</a></li>
  <li><a href="' . KB_SITE_URL . '/help/reset-password">How to reset my password</a></li>
</ul>
',
'body_fr'  => '
<p>Si vous ne trouvez pas la réponse à votre question dans ce centre d\'aide, notre équipe est là pour vous aider.</p>

<h3>Support par e-mail</h3>
<p>Envoyez un e-mail à <a href="mailto:support@237biz.net">support@237biz.net</a>. Incluez :</p>
<ul>
  <li>Votre nom et l\'adresse e-mail avec laquelle vous vous êtes inscrit</li>
  <li>Le nom de votre entreprise (si applicable)</li>
  <li>Une description claire de votre problème ou question</li>
</ul>
<p>Nous visons à répondre dans les 24 heures les jours ouvrables.</p>

<h3>Support WhatsApp</h3>
<p>Pour des réponses plus rapides, vous pouvez également nous rejoindre sur WhatsApp. Le numéro est indiqué sur notre <a href="' . KB_SITE_URL . '/contact">Page de contact</a>.</p>

<h3>Avant de nous contacter</h3>
<p>Veuillez d\'abord vérifier les articles d\'aide pertinents — la plupart des questions courantes y sont répondues. La section dépannage est un bon point de départ :</p>
<ul>
  <li><a href="' . KB_SITE_URL . '/help/listing-pending">Mon annonce indique En attente</a></li>
  <li><a href="' . KB_SITE_URL . '/help/listing-not-visible">Je ne vois pas mon annonce</a></li>
  <li><a href="' . KB_SITE_URL . '/help/not-receiving-emails">Je ne reçois pas les e-mails</a></li>
  <li><a href="' . KB_SITE_URL . '/help/reset-password">Comment réinitialiser mon mot de passe</a></li>
</ul>
',
],

]; // end $KB_ARTICLES

/**
 * Helper: get article by slug
 */
function kb_article(string $slug): ?array {
    global $KB_ARTICLES;
    foreach ($KB_ARTICLES as $a) {
        if ($a['slug'] === $slug) return $a;
    }
    return null;
}

/**
 * Helper: get articles by section
 */
function kb_section_articles(string $section): array {
    global $KB_ARTICLES;
    $out = array_filter($KB_ARTICLES, fn($a) => $a['section'] === $section);
    usort($out, fn($a,$b) => $a['order'] <=> $b['order']);
    return array_values($out);
}

/**
 * Helper: KB article URL
 */
function kb_url(string $slug, string $lang = ''): string {
    $url = KB_SITE_URL . '/help/' . $slug;
    if ($lang) $url .= '?lang=' . $lang;
    return $url;
}

/**
 * Helper: get article title in current language
 */
function kb_title(array $article): string {
    $lang = function_exists('lang') ? lang() : 'en';
    return $lang === 'fr' ? $article['title_fr'] : $article['title_en'];
}
