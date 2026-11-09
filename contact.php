<?php
require_once __DIR__ . '/includes/config.php';
$isFr = lang() === 'fr';
$pageTitle = $isFr ? 'Contactez-nous — 237Biz' : 'Contact Us — 237Biz';
$pageDesc  = $isFr ? 'Contactez l\'équipe 237Biz pour toute question, support ou partenariat.' : 'Contact the 237Biz team for any question, support or partnership.';

$sent = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $type    = $_POST['type'] ?? 'general';

    if (!$name)                             $errors[] = t('Name is required.','Le nom est requis.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('Valid email required.','Email valide requis.');
    if (!$subject)                          $errors[] = t('Subject is required.','L\'objet est requis.');
    if (strlen($message) < 20)             $errors[] = t('Message must be at least 20 characters.','Le message doit comporter au moins 20 caractères.');

    if (!$errors) {
        $typeLabel = [
            'general'     => 'General Enquiry',
            'listing'     => 'Listing Support',
            'payment'     => 'Payment Issue',
            'partnership' => 'Partnership / Agent / Creator',
            'technical'   => 'Technical Support',
            'report'      => 'Report a Listing',
        ][$type] ?? 'General';

        $body = "
<div style='font-family:Arial,sans-serif;background:#ffffff;color:#111111;padding:24px;max-width:600px;margin:0 auto;'>
  <div style='background:#00A878;padding:16px 24px;border-radius:8px 8px 0 0;'>
    <span style='color:#fff;font-size:18px;font-weight:bold;'>237Biz.net</span>
    <span style='color:rgba(255,255,255,0.75);font-size:13px;margin-left:8px;'>New Contact Form Submission</span>
  </div>
  <div style='border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;padding:24px;'>
    <table style='border-collapse:collapse;width:100%;color:#111111;font-size:14px;'>
      <tr><td style='padding:10px 8px;border-bottom:1px solid #eee;font-weight:bold;width:100px;color:#555;'>Type</td><td style='padding:10px 8px;border-bottom:1px solid #eee;'>" . htmlspecialchars($typeLabel) . "</td></tr>
      <tr><td style='padding:10px 8px;border-bottom:1px solid #eee;font-weight:bold;color:#555;'>Name</td><td style='padding:10px 8px;border-bottom:1px solid #eee;'>" . htmlspecialchars($name) . "</td></tr>
      <tr><td style='padding:10px 8px;border-bottom:1px solid #eee;font-weight:bold;color:#555;'>Email</td><td style='padding:10px 8px;border-bottom:1px solid #eee;'><a href='mailto:" . htmlspecialchars($email) . "' style='color:#00A878;'>" . htmlspecialchars($email) . "</a></td></tr>
      <tr><td style='padding:10px 8px;border-bottom:1px solid #eee;font-weight:bold;color:#555;'>Subject</td><td style='padding:10px 8px;border-bottom:1px solid #eee;'>" . htmlspecialchars($subject) . "</td></tr>
      <tr><td style='padding:10px 8px;font-weight:bold;color:#555;vertical-align:top;'>Message</td><td style='padding:10px 8px;line-height:1.6;'>" . nl2br(htmlspecialchars($message)) . "</td></tr>
    </table>
  </div>
</div>";

        try {
            sendMail(SITE_EMAIL, "[237Biz Contact] {$typeLabel}: " . htmlspecialchars($subject), $body);
            // Auto-reply to sender
            $autoReply = '
<div style="font-family:Arial,sans-serif;background:#ffffff;color:#111111;padding:24px;max-width:600px;margin:0 auto;">
  <div style="background:#00A878;padding:16px 24px;border-radius:8px 8px 0 0;">
    <span style="color:#fff;font-size:18px;font-weight:bold;">237Biz.net</span>
  </div>
  <div style="border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
    <h2 style="color:#00A878;font-size:18px;margin:0 0 16px;">✅ ' . t('Message received','Message reçu') . '</h2>
    <p style="color:#333;margin:0 0 12px;">' . t('Hi','Bonjour') . ' ' . htmlspecialchars($name) . ',</p>
    <p style="color:#333;margin:0 0 12px;">' . t('Thank you for contacting 237Biz. We have received your message and will respond within 24–48 hours.','Merci de contacter 237Biz. Nous avons bien reçu votre message et vous répondrons dans les 24–48 heures.') . '</p>
    <div style="background:#f5f5f5;border-left:3px solid #00A878;padding:12px 16px;margin:16px 0;border-radius:0 6px 6px 0;">
      <p style="color:#555;font-size:13px;margin:0 0 8px;font-weight:bold;">' . t('Your message','Votre message') . ':</p>
      <p style="color:#333;font-size:14px;margin:0;line-height:1.6;">' . nl2br(htmlspecialchars($message)) . '</p>
    </div>
    <a href="' . SITE_URL . '" style="display:inline-block;margin-top:16px;background:#00A878;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:14px;">← 237Biz.net</a>
  </div>
</div>';
            sendMail($email, t('We received your message — 237Biz', 'Nous avons reçu votre message — 237Biz'), $autoReply);
            $sent = true;
        } catch(Exception $e) {
            $errors[] = t('Could not send message. Please email us directly at admin@237biz.net','Impossible d\'envoyer le message. Veuillez nous envoyer un email directement à admin@237biz.net');
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.contact-wrap { max-width:900px; margin:0 auto; padding:60px 24px 80px; }
.contact-grid { display:grid; grid-template-columns:1fr 380px; gap:48px; align-items:start; }
.contact-wrap h1 { font-family:'Fraunces',serif; font-weight:900; font-size:clamp(1.8rem,4vw,2.5rem); margin-bottom:8px; }
.contact-wrap p  { color:rgba(255,255,255,0.65); font-size:15px; line-height:1.7; }
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:600; color:rgba(255,255,255,0.7); margin-bottom:6px; }
.form-group input, .form-group select, .form-group textarea {
  width:100%; padding:12px 14px; background:rgba(255,255,255,0.05);
  border:1px solid rgba(255,255,255,0.1); border-radius:10px;
  color:#fff; font-size:14px; font-family:inherit; transition:border-color .15s;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:none; border-color:rgba(0,168,120,0.5); }
.form-group select option { background:#0e2a18; }
.contact-card {
  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
  border-radius:14px; padding:22px 20px; display:flex; gap:14px; align-items:flex-start;
  transition:border-color .2s;
}
.contact-card:hover { border-color:rgba(0,168,120,0.25); }
.contact-card .ci { font-size:1.4rem; flex-shrink:0; margin-top:2px; }
.contact-card h3 { font-size:14px; font-weight:700; color:#fff; margin-bottom:4px; }
.contact-card p  { font-size:13px; color:rgba(255,255,255,0.55); margin:0; line-height:1.5; }
.contact-card a  { color:#00A878; font-weight:600; text-decoration:none; }
@media(max-width:720px){ .contact-grid { grid-template-columns:1fr !important; } }
</style>

<div class="contact-wrap">
  <div style="margin-bottom:12px;"><a href="<?= SITE_URL ?>/" style="font-size:13px;color:rgba(255,255,255,0.45);text-decoration:none;">← <?= t('Home','Accueil') ?></a></div>
  <h1><?= t('Contact Us','Contactez-nous') ?></h1>
  <p style="margin-bottom:40px;"><?= t('Have a question, need support, or want to partner with 237Biz? We\'re here to help. Send us a message and we\'ll respond within 24–48 hours.','Vous avez une question, besoin d\'assistance ou souhaitez vous associer à 237Biz ? Nous sommes là pour aider.') ?></p>

  <div class="contact-grid">

    <!-- Form -->
    <div>
      <?php if ($sent): ?>
      <div style="background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.3);border-radius:14px;padding:24px;text-align:center;margin-bottom:24px;">
        <div style="font-size:2rem;margin-bottom:8px;">✅</div>
        <h3 style="font-family:'Fraunces',serif;margin-bottom:6px;"><?= t('Message sent!','Message envoyé !') ?></h3>
        <p style="font-size:13.5px;color:rgba(255,255,255,0.6);margin:0;"><?= t('We\'ve received your message and sent you a confirmation email. We\'ll respond within 24–48 hours.','Nous avons reçu votre message et vous avons envoyé un email de confirmation. Nous répondrons dans les 24–48 heures.') ?></p>
      </div>
      <?php endif; ?>

      <?php foreach ($errors as $err): ?>
      <div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:8px;padding:10px 14px;font-size:13.5px;color:#ff6b7a;margin-bottom:14px;"><?= e($err) ?></div>
      <?php endforeach; ?>

      <?php if (!$sent): ?>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">

        <div class="form-group">
          <label><?= t('What is this about?','De quoi s\'agit-il ?') ?></label>
          <select name="type">
            <option value="general"><?= t('General Enquiry','Demande générale') ?></option>
            <option value="listing"><?= t('Listing Support','Support annonce') ?></option>
            <option value="payment"><?= t('Payment Issue','Problème de paiement') ?></option>
            <option value="partnership"><?= t('Partnership / Agent / Creator','Partenariat / Agent / Créateur') ?></option>
            <option value="technical"><?= t('Technical Support','Support technique') ?></option>
            <option value="report"><?= t('Report a Listing','Signaler une annonce') ?></option>
          </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="form-group" style="margin:0;">
            <label><?= t('Your Name *','Votre Nom *') ?></label>
            <input type="text" name="name" required value="<?= e($_POST['name'] ?? (isLoggedIn() ? currentUser()['name'] : '')) ?>" placeholder="<?= e(t('Full name','Nom complet')) ?>">
          </div>
          <div class="form-group" style="margin:0;">
            <label><?= t('Email Address *','Adresse email *') ?></label>
            <input type="email" name="email" required value="<?= e($_POST['email'] ?? (isLoggedIn() ? currentUser()['email'] : '')) ?>" placeholder="you@example.com">
          </div>
        </div>

        <div class="form-group" style="margin-top:14px;">
          <label><?= t('Subject *','Objet *') ?></label>
          <input type="text" name="subject" required value="<?= e($_POST['subject'] ?? '') ?>" placeholder="<?= e(t('e.g. Question about my listing','ex. Question concernant mon annonce')) ?>">
        </div>

        <div class="form-group">
          <label><?= t('Message *','Message *') ?> <small style="font-weight:400;color:rgba(255,255,255,0.35);">(<?= t('min. 20 characters','min. 20 caractères') ?>)</small></label>
          <textarea name="message" rows="6" required minlength="20" placeholder="<?= e(t('Describe your question or issue in detail...','Décrivez votre question ou problème en détail...')) ?>"><?= e($_POST['message'] ?? '') ?></textarea>
        </div>

        <button type="submit" style="width:100%;padding:14px;background:#00A878;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s;">
          <?= t('Send Message →','Envoyer le message →') ?>
        </button>
        <p style="font-size:12px;color:rgba(255,255,255,0.35);text-align:center;margin-top:10px;">
          <?= t('We respond within 24–48 hours.','Nous répondons dans les 24–48 heures.') ?>
        </p>
      </form>
      <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div style="display:flex;flex-direction:column;gap:12px;">
      <div class="contact-card">
        <div class="ci">📧</div>
        <div>
          <h3><?= t('Email','Email') ?></h3>
          <p><a href="mailto:admin@237biz.net">admin@237biz.net</a><br><span style="font-size:12px;"><?= t('For general queries','Pour les demandes générales') ?></span></p>
        </div>
      </div>

      <div class="contact-card">
        <div class="ci">💬</div>
        <div>
          <h3>WhatsApp</h3>
          <p><?= t('For quick support, reach us on WhatsApp.','Pour une assistance rapide, contactez-nous sur WhatsApp.') ?><br>
          <a href="https://wa.me/23767407352" target="_blank" rel="noopener">+237 674 073 52</a></p>
        </div>
      </div>

      <div class="contact-card">
        <div class="ci">👔</div>
        <div>
          <h3><?= t('Become a Partner','Devenir Partenaire') ?></h3>
          <p><?= t('Interested in joining as a Sales Agent or Content Creator?','Intéressé à rejoindre en tant qu\'Agent de Vente ou Créateur de Contenu ?') ?><br>
          <a href="<?= SITE_URL ?>/partners"><?= t('See Partner Programme','Voir Programme Partenaires') ?> →</a></p>
        </div>
      </div>

      <div class="contact-card">
        <div class="ci">🏪</div>
        <div>
          <h3><?= t('List Your Business','Lister votre Entreprise') ?></h3>
          <p><?= t('Want to add your business to 237Biz for free?','Vous voulez ajouter votre entreprise gratuitement ?') ?><br>
          <a href="<?= SITE_URL ?>/add-listing"><?= t('Add your listing →','Ajouter votre annonce →') ?></a></p>
        </div>
      </div>

      <div class="contact-card">
        <div class="ci">❓</div>
        <div>
          <h3><?= t('Help Centre','Centre d\'Aide') ?></h3>
          <p><?= t('Find answers to common questions in our Help Centre.','Trouvez des réponses aux questions courantes dans notre Centre d\'Aide.') ?><br>
          <a href="<?= SITE_URL ?>/help"><?= t('Browse Help Articles →','Parcourir les Articles d\'Aide →') ?></a></p>
        </div>
      </div>

      <div style="background:rgba(0,168,120,0.05);border:1px solid rgba(0,168,120,0.15);border-radius:12px;padding:16px 18px;font-size:13px;color:rgba(255,255,255,0.55);line-height:1.6;">
        🇨🇲 <?= t('237Biz is operated by MS IT Solutions / Maroon Hosting, Cameroon.','237Biz est exploité par MS IT Solutions / Maroon Hosting, Cameroun.') ?>
      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
