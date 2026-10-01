<?php
require_once __DIR__ . '/includes/config.php';
$staffUser = requireStaff();
$pdo = db();
$staffId = (int) $staffUser['id'];
$isAdmin = $staffUser['role'] === 'admin';

// Sales staff only see businesses they added; admins see everyone's (adjust
// the WHERE clause below if you'd rather admins also default to "my own").
$scopeWhere = $isAdmin ? "1=1" : "agent_id = ?";
$scopeParams = $isAdmin ? [] : [$staffId];

// Stats for this agent (or everyone, for admins)
$statsStmt = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(status = 'completed') AS completed,
        SUM(status IN ('draft','sent','started')) AS pending,
        AVG(CASE WHEN status='completed' THEN total_score END) AS avg_score,
        SUM(opportunity_score = 'high') AS high_opportunity,
        SUM(event_interest IN ('definitely','probably')) AS event_interested
    FROM health_assessments WHERE $scopeWhere");
$statsStmt->execute($scopeParams);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// List
$listStmt = $pdo->prepare("SELECT id, referral_code, business_name, status, total_score, opportunity_score,
                                   event_interest, follow_up_date, source, created_at
                            FROM health_assessments WHERE $scopeWhere ORDER BY created_at DESC LIMIT 200");
$listStmt->execute($scopeParams);
$assessments = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'draft' => 'Draft', 'sent' => 'Sent', 'started' => 'Started', 'completed' => 'Completed',
    'contacted' => 'Contacted', 'follow_up' => 'Follow-up', 'converted' => 'Converted', 'not_interested' => 'Not interested',
];
$oppEmoji = ['high' => '🔥 High', 'medium' => 'Medium', 'low' => 'Low'];

$pageTitle = ($isAdmin ? 'All' : 'My') . ' Assessments — Digital Health Check';
require_once __DIR__ . '/includes/header.php';
?>
<style>
  :root{--brand:#0f8a5f;--bg:#f6f8f7;--card:#fff;--text:#1c2b26;--muted:#6b7b75;--border:#e2e8e5;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);}
  .wrap{max-width:1000px;margin:0 auto;padding:20px 16px 60px;}
  h1{font-size:22px;margin:0 0 4px;}
  .subtitle{color:var(--muted);font-size:14px;margin-bottom:20px;}
  .actions{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
  .btn{padding:11px 18px;border-radius:9px;border:none;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;}
  .btn-primary{background:var(--brand);color:#fff;}
  .btn-secondary{background:#eef1f0;color:var(--text);}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:24px;}
  .stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;}
  .stat .num{font-size:24px;font-weight:800;color:var(--brand);}
  .stat .lbl{font-size:12px;color:var(--muted);margin-top:2px;}
  table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
  th,td{padding:11px 12px;text-align:left;font-size:13.5px;border-bottom:1px solid var(--border);}
  th{background:#f0f4f2;color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;}
  tr:last-child td{border-bottom:none;}
  a.rowlink{color:var(--text);text-decoration:none;font-weight:600;}
  .badge{padding:3px 9px;border-radius:20px;font-size:11.5px;font-weight:600;background:#eef1f0;}
  .badge.completed{background:#e2f6ec;color:#0f8a5f;}
  .badge.follow_up{background:#fff3e0;color:#b06a00;}
  .badge.not_interested{background:#fbe9e9;color:#a33;}
  .empty{padding:40px;text-align:center;color:var(--muted);}
  /* Share modal */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);align-items:center;justify-content:center;z-index:50;}
  .modal-overlay.open{display:flex;}
  .modal{background:#fff;border-radius:14px;padding:24px;max-width:420px;width:90%;}
  .modal h3{margin-top:0;}
  .modal input{width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;margin-top:8px;font-size:13px;}
  .modal .close{float:right;cursor:pointer;color:var(--muted);}
  @media (max-width:640px){ table, thead, tbody, th, td, tr{display:block;} thead{display:none;}
    tr{border-bottom:1px solid var(--border);padding:10px 12px;}
    td{border:none;padding:3px 0;} td:before{content:attr(data-label) ": ";font-weight:600;color:var(--muted);} }
</style>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;"><?php echo $isAdmin ? '📋 All Assessments' : '📋 My Assessments'; ?></h1>
</div></div>

<section class="page-section"><div class="container">
<div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
  <a href="<?= SITE_URL ?>/agent-health-checks.php" class="filter-tab active">📋 Assessments</a>
  <?php if ($isAdmin): ?>
  <a href="<?= SITE_URL ?>/admin/health-check-overview.php" class="filter-tab">📊 Market Overview</a>
  <a href="<?= SITE_URL ?>/admin/health-check-questions.php" class="filter-tab">⚙️ Questions</a>
  <a href="<?= SITE_URL ?>/admin/health-check-staff.php" class="filter-tab">👥 Sales Staff</a>
  <?php endif; ?>
</div>

<div class="wrap" style="padding:0;max-width:none;">
  <div class="subtitle">237biz Digital Health Check — <?php echo $isAdmin ? "every agent's leads and follow-ups" : 'your leads and follow-ups'; ?></div>

  <div class="actions">
    <a class="btn btn-primary" href="<?= SITE_URL ?>/health-check.php">+ New Assessment</a>
    <button class="btn btn-secondary" id="shareBtn">📱 Share Assessment on WhatsApp</button>
  </div>

  <div class="stats">
    <div class="stat"><div class="num"><?php echo (int)($stats['total'] ?? 0); ?></div><div class="lbl">Total</div></div>
    <div class="stat"><div class="num"><?php echo (int)($stats['completed'] ?? 0); ?></div><div class="lbl">Completed</div></div>
    <div class="stat"><div class="num"><?php echo (int)($stats['pending'] ?? 0); ?></div><div class="lbl">Pending</div></div>
    <div class="stat"><div class="num"><?php echo $stats['avg_score'] !== null ? round($stats['avg_score']) . '%' : '—'; ?></div><div class="lbl">Avg Digital Score</div></div>
    <div class="stat"><div class="num"><?php echo (int)($stats['high_opportunity'] ?? 0); ?></div><div class="lbl">High Opportunity</div></div>
    <div class="stat"><div class="num"><?php echo (int)($stats['event_interested'] ?? 0); ?></div><div class="lbl">Event Interested</div></div>
  </div>

  <table>
    <thead><tr><th>Business</th><th>Score</th><th>Opportunity</th><th>Event</th><th>Status</th><th>Created</th></tr></thead>
    <tbody>
    <?php if (!$assessments): ?>
      <tr><td colspan="6" class="empty">No assessments yet — start one with "+ New Assessment" or share a link on WhatsApp.</td></tr>
    <?php else: foreach ($assessments as $a): ?>
      <tr>
        <td data-label="Business"><a class="rowlink" href="health-check-detail.php?id=<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['business_name'] ?: '(untitled — ' . $a['referral_code'] . ')'); ?></a></td>
        <td data-label="Score"><?php echo $a['total_score'] !== null ? round($a['total_score']) . '/100' : '—'; ?></td>
        <td data-label="Opportunity"><?php echo $oppEmoji[$a['opportunity_score']] ?? '—'; ?></td>
        <td data-label="Event"><?php echo ucfirst($a['event_interest'] ?? '—'); ?></td>
        <td data-label="Status"><span class="badge <?php echo $a['status']; ?>"><?php echo $statusLabels[$a['status']] ?? $a['status']; ?></span></td>
        <td data-label="Created"><?php echo date('d M Y', strtotime($a['created_at'])); ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="modal-overlay" id="shareModal">
  <div class="modal">
    <span class="close" id="closeModal">✕</span>
    <h3>Share Assessment</h3>
    <p style="font-size:13.5px;color:#6b7b75;">Generating a link the business owner can complete themselves...</p>
    <div id="shareResult" style="display:none;">
      <label style="font-size:12px;color:#6b7b75;display:block;margin-bottom:4px;">Web Link (send by SMS, email, anywhere)</label>
      <input type="text" id="shareUrl" readonly onclick="this.select()">
      <button type="button" class="btn btn-secondary" style="margin-top:8px;width:100%;" id="copyLinkBtn">📋 Copy Link</button>
      <a class="btn btn-primary" style="margin-top:8px;width:100%;text-align:center;display:block;" id="waLink" target="_blank">💬 Open in WhatsApp</a>
      <div id="copyConfirm" style="font-size:12px;color:#0f8a5f;margin-top:6px;text-align:center;display:none;">Link copied!</div>
    </div>
  </div>
</div>

<script>
document.getElementById('shareBtn').addEventListener('click', async () => {
  const modal = document.getElementById('shareModal');
  modal.classList.add('open');
  document.getElementById('shareResult').style.display = 'none';
  document.getElementById('copyConfirm').style.display = 'none';
  const body = new URLSearchParams();
  body.set('action', 'create_share_link');
  const res = await fetch('save-health-check.php', { method: 'POST', body });
  const data = await res.json();
  if (data.share_url) {
    document.getElementById('shareUrl').value = data.share_url;
    document.getElementById('waLink').href = data.whatsapp_url;
    document.getElementById('shareResult').style.display = 'block';
  }
});
document.getElementById('copyLinkBtn').addEventListener('click', async () => {
  const input = document.getElementById('shareUrl');
  try {
    await navigator.clipboard.writeText(input.value);
  } catch (e) {
    input.select();
    document.execCommand('copy');
  }
  const confirmEl = document.getElementById('copyConfirm');
  confirmEl.style.display = 'block';
  setTimeout(() => confirmEl.style.display = 'none', 2000);
});
document.getElementById('closeModal').addEventListener('click', () => {
  document.getElementById('shareModal').classList.remove('open');
});
</script>
</div>
</div></section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
