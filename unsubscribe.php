<?php
require_once __DIR__ . '/includes/config.php';

$uid   = (int)($_GET['uid'] ?? 0);
$done  = false;
$error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid) {
        // Cancel all active automation enrollments
        db()->prepare("UPDATE automation_enrollments SET status='cancelled' WHERE user_id=?")->execute([$uid]);
        // Clear queued emails
        db()->prepare("UPDATE automation_log SET status='skipped' WHERE user_id=? AND status='queued'")->execute([$uid]);
        // Add unsubscribed tag
        try {
            $tagSt = db()->prepare("SELECT id FROM automation_tags WHERE name='unsubscribed'");
            $tagSt->execute();
            $tagId = $tagSt->fetchColumn();
            if (!$tagId) {
                db()->prepare("INSERT INTO automation_tags (name,colour,description) VALUES ('unsubscribed','#dc3545','Opted out of marketing emails')")->execute();
                $tagId = db()->lastInsertId();
            }
            db()->prepare("INSERT IGNORE INTO user_tags (user_id,tag_id) VALUES (?,?)")->execute([$uid,$tagId]);
        } catch (Exception $e) {}
        $done = true;
    } else {
        $error = true;
    }
}

$pageTitle = t('Unsubscribe — 237Biz', 'Désabonnement — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>
<section style="min-height:80vh;display:flex;align-items:center;justify-content:center;padding:8rem 5vw 4rem;">
  <div style="width:100%;max-width:460px;text-align:center;">
    <?php if ($done): ?>
      <div style="font-size:3rem;margin-bottom:1rem;">✅</div>
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;margin-bottom:0.75rem;"><?= t("You've been unsubscribed", 'Vous avez été désabonné') ?></h2>
      <p style="color:var(--muted);margin-bottom:1.5rem;"><?= t("You won't receive any more automated emails from 237Biz. You can still log in and use your account normally.", "Vous ne recevrez plus d'emails automatisés de 237Biz. Vous pouvez toujours vous connecter et utiliser votre compte normalement.") ?></p>
      <a href="<?= SITE_URL ?>/" class="btn btn-outline"><?= t('← Back to 237Biz', '← Retour à 237Biz') ?></a>

    <?php elseif ($error): ?>
      <div style="font-size:3rem;margin-bottom:1rem;">❌</div>
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;margin-bottom:0.75rem;"><?= t('Something went wrong', 'Une erreur est survenue') ?></h2>
      <p style="color:var(--muted);margin-bottom:1.5rem;"><?= t('Please use the unsubscribe link from your email.', 'Veuillez utiliser le lien de désabonnement dans votre email.') ?></p>

    <?php else: ?>
      <div style="font-size:3rem;margin-bottom:1rem;">📧</div>
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;margin-bottom:0.75rem;"><?= t('Unsubscribe from emails', 'Se désabonner des emails') ?></h2>
      <p style="color:var(--muted);margin-bottom:1.5rem;"><?= t("You'll stop receiving automated marketing emails from 237Biz. You can still log in and use your account.", "Vous cesserez de recevoir des emails marketing automatisés de 237Biz. Vous pouvez toujours vous connecter et utiliser votre compte.") ?></p>
      <?php if ($uid): ?>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="uid"  value="<?= $uid ?>">
          <button type="submit" class="btn btn-primary" style="background:#dc3545;border-color:#dc3545;"><?= t('Confirm Unsubscribe', 'Confirmer le désabonnement') ?></button>
          <a href="<?= SITE_URL ?>/" class="btn btn-outline" style="margin-left:0.5rem;"><?= t('Cancel', 'Annuler') ?></a>
        </form>
      <?php else: ?>
        <p style="color:#ff6b6b;"><?= t('Invalid unsubscribe link. Please use the link from your email.', 'Lien de désabonnement invalide. Veuillez utiliser le lien de votre email.') ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
