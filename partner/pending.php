<?php
/**
 * partner/pending.php — Application pending / suspended holding page
 */
require_once __DIR__ . '/../includes/config.php';
if (!isLoggedIn()) redirect(SITE_URL . '/login');

$user = currentUser();
$pdo  = db();
$st   = $pdo->prepare("SELECT * FROM partner_profiles WHERE user_id = ?");
$st->execute([$user['id']]);
$profile = $st->fetch();

$pageTitle = 'Partner Application — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div style="max-width:600px; margin:4rem auto; padding:2rem 1.5rem; text-align:center;">
  <?php if (!$profile): ?>
    <!-- No application yet -->
    <div style="font-size:4rem; margin-bottom:1rem;">🤝</div>
    <h1 style="font-family:'Fraunces',serif; font-size:2rem; margin-bottom:0.75rem;">Become a Growth Partner</h1>
    <p style="color:var(--muted); margin-bottom:2rem;">Help Cameroonian businesses grow their digital presence and earn commissions for your work.</p>
    <a href="<?= SITE_URL ?>/join?role=growth_partner" class="btn btn-primary" style="font-size:1rem; padding:0.75rem 2rem;">Apply Now →</a>

  <?php elseif (in_array($profile['status'], ['applicant','pending'])): ?>
    <!-- Application submitted -->
    <div style="font-size:4rem; margin-bottom:1rem;">⏳</div>
    <h1 style="font-family:'Fraunces',serif; font-size:2rem; margin-bottom:0.75rem;">Application Under Review</h1>
    <p style="color:var(--muted); margin-bottom:1rem;">
      Thank you for applying to become a 237Biz Growth Partner. Our team is reviewing your application.
    </p>
    <p style="color:var(--muted); margin-bottom:2rem;">
      You'll receive an email at <strong><?= e($user['email']) ?></strong> once a decision has been made.
      This usually takes 1–3 business days.
    </p>
    <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:1.5rem; text-align:left; margin-bottom:2rem;">
      <div style="font-size:0.8rem; color:var(--muted); margin-bottom:0.3rem;">Application submitted</div>
      <div style="font-weight:600;"><?= date('j F Y', strtotime($profile['created_at'])) ?></div>
      <?php if ($profile['organisation']): ?>
      <div style="margin-top:0.75rem; font-size:0.8rem; color:var(--muted);">Organisation</div>
      <div style="font-weight:600;"><?= e($profile['organisation']) ?></div>
      <?php endif; ?>
    </div>
    <a href="<?= SITE_URL ?>/dashboard" style="color:var(--primary); text-decoration:none;">← Back to Dashboard</a>

  <?php elseif ($profile['status'] === 'suspended'): ?>
    <!-- Suspended -->
    <div style="font-size:4rem; margin-bottom:1rem;">⊘</div>
    <h1 style="font-family:'Fraunces',serif; font-size:2rem; margin-bottom:0.75rem;">Account Suspended</h1>
    <p style="color:var(--muted); margin-bottom:2rem;">
      Your Growth Partner account has been suspended. Please contact our team for assistance.
    </p>
    <a href="mailto:partners@237biz.net" class="btn btn-primary">Contact Support</a>

  <?php elseif (in_array($profile['status'], ['inactive','terminated'])): ?>
    <!-- Inactive/terminated -->
    <div style="font-size:4rem; margin-bottom:1rem;">📭</div>
    <h1 style="font-family:'Fraunces',serif; font-size:2rem; margin-bottom:0.75rem;">Account Inactive</h1>
    <p style="color:var(--muted); margin-bottom:2rem;">
      Your Growth Partner account is no longer active. Please contact our team if you believe this is an error.
    </p>
    <a href="mailto:partners@237biz.net" class="btn btn-primary">Contact Support</a>

  <?php else: ?>
    <!-- Already active — redirect -->
    <?php redirect(SITE_URL . '/partner/dashboard'); ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
