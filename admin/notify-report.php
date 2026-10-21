<?php
/**
 * admin/notify-report.php
 * Delivery report for a single notification campaign.
 * ?id=<campaign_id>
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notify-helpers.php';
requireAdmin();
$pdo = db();

$cid = (int)($_GET['id'] ?? 0);
if (!$cid) {
    redirect(SITE_URL . '/admin/notify-campaigns.php');
}

$r = $pdo->prepare("SELECT * FROM notification_campaigns WHERE id=?");
$r->execute([$cid]);
$camp = $r->fetch();
if (!$camp) {
    redirect(SITE_URL . '/admin/notify-campaigns.php');
}

// ── Recipient status counts ────────────────────────────────────────────────
$rcCounts = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM   notification_campaign_recipients
    WHERE  campaign_id = ?
    GROUP  BY status
");
$rcCounts->execute([$cid]);
$counts = $rcCounts->fetchAll(PDO::FETCH_KEY_PAIR);

$totalRc  = array_sum($counts);
$sentCnt  = (int)($counts['sent']         ?? 0);
$failCnt  = (int)($counts['failed']       ?? 0);
$queuedCnt= (int)($counts['queued']       ?? 0);
$skippedCnt=(int)($counts['skipped']      ?? 0);
$unsubCnt = (int)($counts['unsubscribed'] ?? 0);
$delivRate = $totalRc > 0 ? round($sentCnt / $totalRc * 100, 1) : 0;

// ── Delivery events summary ────────────────────────────────────────────────
$evCounts = $pdo->prepare("
    SELECT event_type, COUNT(*) AS cnt
    FROM   notification_delivery_events
    WHERE  campaign_id = ?
    GROUP  BY event_type
");
$evCounts->execute([$cid]);
$evStats = $evCounts->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Recent delivery events (last 50) ──────────────────────────────────────
$evLog = $pdo->prepare("
    SELECT nde.event_type, nde.detail, nde.occurred_at,
           ncr.email
    FROM   notification_delivery_events nde
    JOIN   notification_campaign_recipients ncr ON ncr.id = nde.recipient_id
    WHERE  nde.campaign_id = ?
    ORDER  BY nde.occurred_at DESC
    LIMIT  50
");
$evLog->execute([$cid]);
$events = $evLog->fetchAll();

// ── Audit log for this campaign ───────────────────────────────────────────
$auditRows = $pdo->prepare("
    SELECT nal.*, u.name AS actor_name
    FROM   notification_audit_logs nal
    LEFT JOIN users u ON u.id = nal.actor_id
    WHERE  nal.object_type = 'campaign'
      AND  nal.object_id   = ?
    ORDER  BY nal.occurred_at DESC
    LIMIT  30
");
$auditRows->execute([$cid]);
$auditLog = $auditRows->fetchAll();

// ── Failed recipients (first 100) ─────────────────────────────────────────
$failRows = $pdo->prepare("
    SELECT email, created_at
    FROM   notification_campaign_recipients
    WHERE  campaign_id = ? AND status = 'failed'
    ORDER  BY created_at DESC
    LIMIT  100
");
$failRows->execute([$cid]);
$failures = $failRows->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────
function rptBadge(string $s): string {
    $map = ['draft'=>'pending','ready'=>'pending','scheduled'=>'info','sending'=>'info',
            'completed'=>'approved','paused'=>'info','cancelled'=>'rejected','failed'=>'rejected'];
    return '<span class="badge badge-' . ($map[$s] ?? 'info') . '">' . ucfirst($s) . '</span>';
}
function evIcon(string $t): string {
    return ['sent'=>'📤','delivered'=>'✅','opened'=>'👁','clicked'=>'🔗',
            'bounced'=>'↩️','complained'=>'🚩','unsubscribed'=>'🔕'][$t] ?? '•';
}

$pageTitle = 'Campaign Report — ' . $camp['name'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('notify-campaigns'); adminNavAssets();
?>
<style>
.rpt-stat{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.25rem 1rem;text-align:center}
.rpt-stat strong{display:block;font-size:1.9rem;font-weight:900;font-family:'Fraunces',serif;line-height:1}
.rpt-stat span{display:block;font-size:0.78rem;color:var(--muted);margin-top:0.3rem;text-transform:uppercase;letter-spacing:0.04em}
.rpt-bar{height:10px;border-radius:5px;background:var(--border);overflow:hidden;margin-top:0.5rem}
.rpt-bar-fill{height:100%;border-radius:5px;background:#00A878;transition:width .4s}
.ev-chip{display:inline-block;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.75rem;font-weight:600;
  background:rgba(0,168,120,0.12);color:#00A878}
.ev-chip.bounced,.ev-chip.complained,.ev-chip.failed{background:rgba(255,107,107,0.12);color:#ff6b6b}
.ev-chip.unsubscribed{background:rgba(255,165,0,0.12);color:#ffa500}
</style>

<div class="page-header"><div class="container">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
    <div>
      <div style="font-size:0.82rem;color:var(--muted);margin-bottom:0.3rem">
        <a href="<?= SITE_URL ?>/admin/notify-campaigns.php" style="color:var(--muted);text-decoration:none">📣 Campaigns</a> ›
      </div>
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.9rem;margin:0">
        📊 <?= e($camp['name']) ?>
      </h1>
      <div style="display:flex;gap:0.75rem;align-items:center;margin-top:0.4rem;flex-wrap:wrap">
        <?= rptBadge($camp['status']) ?>
        <span style="font-size:0.82rem;color:var(--muted)"><?= ucfirst(str_replace('_',' ',$camp['type'])) ?> · <?= strtoupper($camp['language']) ?></span>
        <?php if ($camp['sent_at']): ?>
        <span style="font-size:0.82rem;color:var(--muted)">Sent <?= date('d M Y H:i', strtotime($camp['sent_at'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <a href="<?= SITE_URL ?>/admin/notify-campaigns.php?edit=<?= $cid ?>"
       class="btn btn-sm" style="background:var(--card);border:1px solid var(--border)">← Back to campaign</a>
  </div>
</div></div>

<section class="page-section"><div class="container">

  <!-- Subject line -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:0.9rem">
    <strong style="color:var(--muted);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em">Subject</strong>
    <div style="color:var(--white);margin-top:0.25rem;font-weight:500"><?= e($camp['subject']) ?></div>
  </div>

  <!-- ═══ Stats grid ═══════════════════════════════════════════════════════ -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:1rem;margin-bottom:2rem">
    <div class="rpt-stat">
      <strong><?= number_format($camp['recipient_count'] ?? $totalRc) ?></strong>
      <span>Recipients</span>
    </div>
    <div class="rpt-stat">
      <strong style="color:#00A878"><?= number_format($sentCnt) ?></strong>
      <span>Sent</span>
      <div class="rpt-bar"><div class="rpt-bar-fill" style="width:<?= $delivRate ?>%"></div></div>
      <div style="font-size:0.75rem;color:var(--muted);margin-top:0.2rem"><?= $delivRate ?>% delivery</div>
    </div>
    <div class="rpt-stat">
      <strong style="color:<?= $failCnt > 0 ? '#ff6b6b' : 'var(--muted)' ?>"><?= number_format($failCnt) ?></strong>
      <span>Failed</span>
    </div>
    <?php if ($queuedCnt > 0): ?>
    <div class="rpt-stat">
      <strong style="color:#ffa500"><?= number_format($queuedCnt) ?></strong>
      <span>Queued</span>
    </div>
    <?php endif; ?>
    <?php if ($unsubCnt > 0): ?>
    <div class="rpt-stat">
      <strong style="color:var(--muted)"><?= number_format($unsubCnt) ?></strong>
      <span>Unsubscribed</span>
    </div>
    <?php endif; ?>
    <?php if (!empty($evStats)): ?>
    <?php foreach (['opened'=>['👁','Opens'],'clicked'=>['🔗','Clicks'],'bounced'=>['↩️','Bounces'],'complained'=>['🚩','Complaints']] as $k=>[$icon,$label]): ?>
    <?php if (isset($evStats[$k])): ?>
    <div class="rpt-stat">
      <strong style="color:<?= in_array($k,['bounced','complained']) ? '#ff6b6b' : '#00A878' ?>"><?= number_format($evStats[$k]) ?></strong>
      <span><?= $icon ?> <?= $label ?></span>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

    <!-- ═══ Failed recipients ════════════════════════════════════════════ -->
    <?php if ($failures): ?>
    <div class="listing-widget" style="padding:1.25rem">
      <h3 style="font-family:'Fraunces',serif;font-weight:900;margin:0 0 1rem">✗ Failed deliveries (<?= count($failures) ?>)</h3>
      <div style="overflow-y:auto;max-height:300px">
        <table class="data-table" style="font-size:0.8rem">
          <thead><tr><th>Email</th><th>Time</th></tr></thead>
          <tbody>
          <?php foreach ($failures as $f): ?>
          <tr>
            <td style="word-break:break-all;color:var(--white)"><?= e($f['email']) ?></td>
            <td style="white-space:nowrap;color:var(--muted-2)"><?= date('d M H:i', strtotime($f['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- ═══ Delivery events log ══════════════════════════════════════════ -->
    <div class="listing-widget" style="padding:1.25rem">
      <h3 style="font-family:'Fraunces',serif;font-weight:900;margin:0 0 1rem">📡 Delivery events</h3>
      <?php if (empty($events)): ?>
      <p style="color:var(--muted);font-size:0.85rem">No delivery events recorded yet. Events are written by your SMTP provider's webhook.</p>
      <?php else: ?>
      <div style="overflow-y:auto;max-height:300px">
        <table class="data-table" style="font-size:0.8rem">
          <thead><tr><th>Event</th><th>Email</th><th>Time</th></tr></thead>
          <tbody>
          <?php foreach ($events as $ev): ?>
          <tr>
            <td><span class="ev-chip <?= $ev['event_type'] ?>"><?= evIcon($ev['event_type']) ?> <?= ucfirst($ev['event_type']) ?></span></td>
            <td style="word-break:break-all;color:var(--white)"><?= e($ev['email']) ?></td>
            <td style="white-space:nowrap;color:var(--muted-2)"><?= date('d M H:i', strtotime($ev['occurred_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- ═══ Audit log ═══════════════════════════════════════════════════════ -->
  <div class="listing-widget" style="padding:1.25rem;margin-top:1.5rem">
    <h3 style="font-family:'Fraunces',serif;font-weight:900;margin:0 0 1rem">📋 Activity log</h3>
    <?php if (empty($auditLog)): ?>
    <p style="color:var(--muted);font-size:0.85rem">No activity recorded.</p>
    <?php else: ?>
    <table class="data-table" style="font-size:0.8rem">
      <thead><tr><th>Action</th><th>Actor</th><th>Detail</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach ($auditLog as $al): ?>
      <tr>
        <td style="font-weight:600;color:var(--white)"><?= e(str_replace('_',' ',$al['action'])) ?></td>
        <td style="color:var(--muted)"><?= $al['actor_name'] ? e($al['actor_name']) : ucfirst($al['actor_type']) ?></td>
        <td style="color:var(--muted-2);font-size:0.77rem"><?= $al['detail'] ? e($al['detail']) : '—' ?></td>
        <td style="white-space:nowrap;color:var(--muted-2)"><?= date('d M Y H:i', strtotime($al['occurred_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
