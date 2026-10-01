<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$slug = trim($_GET['slug'] ?? '');
$u    = currentUser();

$st = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, loc.name_en AS loc_en
    FROM listings l
    JOIN categories c ON c.id=l.category_id
    JOIN locations loc ON loc.id=l.location_id
    WHERE l.slug=? AND l.status='approved'
");
$st->execute([$slug]);
$listing = $st->fetch();
if (!$listing) { flash('error', t('Listing not found.','Annonce introuvable.')); redirect(SITE_URL . '/listings'); }

// Already owner
if ($listing['user_id'] == $u['id']) {
    flash('error', t('You already own this listing.','Vous êtes déjà propriétaire de cette annonce.'));
    redirect(SITE_URL . '/listing/' . $slug);
}

// Already claimed
$existing = db()->prepare("SELECT * FROM listing_claims WHERE listing_id=? AND user_id=?");
$existing->execute([$listing['id'], $u['id']]);
$existingClaim = $existing->fetch();

$errors = [];
$sent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existingClaim) {
    verifyCsrf();
    $message = trim($_POST['message'] ?? '');
    if (strlen($message) < 20) $errors[] = t('Please describe your connection to this business (min 20 characters).','Veuillez décrire votre lien avec cette entreprise (min 20 caractères).');

    $proofPath = null;
    if (!empty($_FILES['proof']['name'])) {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
            $proofPath = uniqid('claim_') . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], UPLOAD_DIR . $proofPath);
        }
    }

    if (!$errors) {
        db()->prepare("INSERT INTO listing_claims (listing_id,user_id,message,proof) VALUES (?,?,?,?)")
             ->execute([$listing['id'], $u['id'], $message, $proofPath]);

        sendMail(SITE_EMAIL,
            'New Listing Claim — ' . $listing['title'],
            '<p><strong>'.e($u['name']).'</strong> ('. e($u['email']) .') wants to claim:<br>
             <strong>'.e($listing['title']).'</strong></p>
             <p><strong>Message:</strong><br>'.nl2br(e($message)).'</p>
             <a href="'.SITE_URL.'/admin/claims.php" style="background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">Review Claim</a>'
        );

        sendMail($u['email'],
            t('Claim submitted — 237Biz','Demande de revendication soumise — 237Biz'),
            '<h2 style="color:#fff;font-family:Georgia,serif;">'.t('Claim Submitted!','Demande Soumise !').'</h2>
             <p style="color:rgba(255,255,255,0.7);">'.t('Your claim for','Votre demande pour').' <strong>'.e($listing['title']).'</strong> '.t('has been received. We\'ll review and respond within 48 hours.','a été reçue. Nous examinerons et répondrons dans les 48 heures.').'</p>'
        );
        $sent = true;
    }
}

$pageTitle = t('Claim Listing — 237Biz','Revendiquer l\'Annonce — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/listing/<?= e($slug) ?>"><?= e($listing['title']) ?></a> ›
      <span><?= t('Claim this listing','Revendiquer cette annonce') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.8rem,3vw,2.5rem);">
      🏢 <?= t('Claim Your Business','Revendiquer votre Entreprise') ?>
    </h1>
  </div>
</div>

<section class="page-section">
  <div class="container" style="max-width:680px;">

    <div class="listing-widget" style="margin-bottom:1.5rem;display:flex;gap:1rem;align-items:center;">
      <div style="font-size:2rem;"><?php // cat icon would go here ?></div>
      <div>
        <div style="font-family:'Fraunces',serif;font-weight:700;font-size:1.1rem;"><?= e($listing['title']) ?></div>
        <div style="font-size:0.8rem;color:var(--muted);">📍 <?= e($listing['loc_en']) ?> · <?= e($listing['cat_en']) ?></div>
      </div>
    </div>

    <?php if ($sent): ?>
      <div class="flash flash-success">
        ✅ <?= t('Claim submitted! We\'ll review and contact you within 48 hours.','Demande soumise ! Nous examinerons et vous contacterons dans les 48 heures.') ?>
      </div>
      <a href="<?= SITE_URL ?>/listing/<?= e($slug) ?>" class="btn btn-outline" style="margin-top:1rem;">← <?= t('Back to listing','Retour à l\'annonce') ?></a>
    <?php elseif ($existingClaim): ?>
      <div class="flash flash-success">
        ⏳ <?= t('You have already submitted a claim for this listing. Status:','Vous avez déjà soumis une demande pour cette annonce. Statut :') ?>
        <strong><?= ucfirst($existingClaim['status']) ?></strong>
      </div>
    <?php else: ?>
      <div class="listing-widget" style="background:rgba(245,200,66,0.05);border-color:rgba(245,200,66,0.2);margin-bottom:1.5rem;">
        <p style="font-size:0.875rem;color:var(--muted);line-height:1.65;">
          <?= t('Is this your business? Claim it to manage your listing details, respond to reviews, and upgrade to a featured listing.',
                'Est-ce votre entreprise ? Revendiquez-la pour gérer vos informations, répondre aux avis et passer à une annonce vedette.') ?>
        </p>
      </div>

      <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

      <div class="form-card">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <div class="form-group">
            <label><?= t('How are you connected to this business? *','Comment êtes-vous lié à cette entreprise ? *') ?></label>
            <textarea name="message" rows="4" required
                      placeholder="<?= t('e.g. I am the owner/manager. I can provide proof of business registration or photos of the premises...','ex. Je suis le propriétaire/gérant. Je peux fournir une preuve d\'inscription ou des photos des locaux...') ?>"><?= e($_POST['message'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label><?= t('Proof of Ownership (optional but recommended)','Preuve de propriété (optionnel mais recommandé)') ?></label>
            <input type="file" name="proof" accept="image/*,.pdf" style="color:var(--muted);padding:0.5rem 0;">
            <small style="color:var(--muted-2);font-size:0.75rem;"><?= t('Business card, registration certificate, photo of premises etc.','Carte de visite, certificat d\'enregistrement, photo des locaux etc.') ?></small>
          </div>
          <button type="submit" class="btn btn-primary btn-full"><?= t('Submit Claim →','Soumettre la demande →') ?></button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
