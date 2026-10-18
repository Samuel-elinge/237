<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id     = (int)($_POST['lead_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id && in_array($status, ['new','contacted','converted','closed'])) {
        db()->prepare("UPDATE website_leads SET status=? WHERE id=?")->execute([$status, $id]);
        flash('success', 'Lead status updated.');
    }
    redirect(SITE_URL . '/admin/leads.php');
}

$leads = db()->query("SELECT * FROM website_leads ORDER BY FIELD(status,'new','contacted','converted','closed'), created_at DESC")->fetchAll();
$newCount = count(array_filter($leads, fn($l) => $l['status'] === 'new'));

$hasWebsiteLabels = [
    'none'        => 'No website',
    'social_only' => 'Social media only',
    'outdated'    => 'Outdated website',
];

$pageTitle = 'Website Leads — Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('leads'); adminNavAssets();
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">
    🌐 Website Leads <?php if ($newCount): ?><span class="badge badge-pending" style="font-size:0.9rem;vertical-align:middle;"><?= $newCount ?> new</span><?php endif; ?>
  </h1>
</div></div>

<section class="page-section"><div class="container">

<div style="display:flex;flex-direction:column;gap:1rem;">
    <?php foreach ($leads as $lead): ?>
      <div class="listing-widget" style="<?= $lead['status']==='new' ? 'border-color:rgba(245,200,66,0.3);background:rgba(245,200,66,0.03);' : '' ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem;">
          <div>
            <span class="badge badge-<?= $lead['status']==='new'?'pending':($lead['status']==='converted'?'approved':($lead['status']==='closed'?'rejected':'pending')) ?>" style="margin-bottom:0.4rem;display:inline-block;">
              <?= ucfirst($lead['status']) ?>
            </span><br>
            <span style="font-weight:500;color:var(--white);font-size:1rem;"><?= e($lead['name']) ?></span>
            <?php if ($lead['business_name']): ?>
              <span style="color:var(--muted-2);font-size:0.85rem;"> — <?= e($lead['business_name']) ?></span>
            <?php endif; ?>
            <?php if ($lead['business_type']): ?>
              <div style="font-size:0.78rem;color:var(--muted-2);"><?= e($lead['business_type']) ?></div>
            <?php endif; ?>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:0.78rem;color:var(--muted-2);"><?= date('d M Y H:i', strtotime($lead['created_at'])) ?></div>
            <span class="badge badge-pending" style="margin-top:0.3rem;"><?= e($hasWebsiteLabels[$lead['has_website']] ?? $lead['has_website']) ?></span>
          </div>
        </div>

        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:0.75rem;font-size:0.85rem;">
          <span style="color:var(--muted);">📞 <a href="tel:<?= e($lead['phone']) ?>" style="color:var(--white);"><?= e($lead['phone']) ?></a></span>
          <?php if ($lead['email']): ?>
            <span style="color:var(--muted);">✉️ <a href="mailto:<?= e($lead['email']) ?>" style="color:var(--white);"><?= e($lead['email']) ?></a></span>
          <?php endif; ?>
        </div>

        <?php if ($lead['notes']): ?>
          <p style="font-size:0.85rem;color:var(--muted);line-height:1.7;background:rgba(255,255,255,0.02);border-radius:8px;padding:0.75rem;margin-bottom:0.75rem;">
            <?= nl2br(e($lead['notes'])) ?>
          </p>
        <?php endif; ?>

        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
          <a href="https://wa.me/<?= preg_replace('/\D/', '', $lead['phone']) ?>" target="_blank"
             class="btn btn-sm" style="background:rgba(37,211,102,0.15);color:#25D366;border:1px solid rgba(37,211,102,0.3);">💬 WhatsApp</a>
          <a href="tel:<?= e($lead['phone']) ?>" class="btn btn-outline btn-sm">📞 Call</a>
          <?php if ($lead['email']): ?>
            <a href="mailto:<?= e($lead['email']) ?>" class="btn btn-outline btn-sm">✉️ Email</a>
          <?php endif; ?>

          <form method="POST" style="display:inline-flex;gap:0.4rem;align-items:center;margin-left:auto;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
            <select name="status" onchange="this.form.submit()"
                    style="background:var(--card);border:1px solid var(--border);border-radius:6px;color:var(--white);font-size:0.8rem;padding:0.4rem 0.6rem;">
              <option value="new" <?= $lead['status']==='new'?'selected':'' ?>>New</option>
              <option value="contacted" <?= $lead['status']==='contacted'?'selected':'' ?>>Contacted</option>
              <option value="converted" <?= $lead['status']==='converted'?'selected':'' ?>>Converted</option>
              <option value="closed" <?= $lead['status']==='closed'?'selected':'' ?>>Closed</option>
            </select>
          </form>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!$leads): ?>
      <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
        <div style="font-size:2.5rem;margin-bottom:0.75rem;">🌐</div>
        <p style="color:var(--muted);">No website leads yet.</p>
      </div>
    <?php endif; ?>
  </div>
</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
