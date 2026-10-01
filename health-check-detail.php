<?php
require_once __DIR__ . '/includes/config.php';
$staffUser = requireStaff();
$pdo = db();
require_once __DIR__ . '/includes/health-check-questions.php';
require_once __DIR__ . '/includes/health-check-scoring.php';

$staffId = (int) $staffUser['id'];
$isAdmin = $staffUser['role'] === 'admin';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM health_assessments WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$a) { http_response_code(404); die('Assessment not found.'); }

// Sales staff can only view businesses they added themselves — admins see all
if (!$isAdmin && (int) $a['agent_id'] !== $staffId) {
    http_response_code(403);
    die('You can only view assessments you created.');
}

$answers = $a['answers_json'] ? json_decode($a['answers_json'], true) : [];
$questions = get_health_check_questions();

$notesStmt = $pdo->prepare("SELECT * FROM health_assessment_notes WHERE assessment_id = ? ORDER BY created_at DESC");
$notesStmt->execute([$id]);
$notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

$linkedBusiness = null;
if ($a['business_id']) {
    $bStmt = $pdo->prepare("SELECT id, title AS name FROM listings WHERE id = ?");
    $bStmt->execute([$a['business_id']]);
    $linkedBusiness = $bStmt->fetch(PDO::FETCH_ASSOC);
}

$statusOptions = ['draft','sent','started','completed','contacted','follow_up','converted','not_interested'];
function fmt_answer($qid, $val, $questions) {
    if (!isset($questions[$qid])) return is_array($val) ? implode(', ', $val) : $val;
    $q = $questions[$qid];
    if ($q['type'] === 'single' && isset($q['options'][$val])) {
        $opt = $q['options'][$val];
        return is_array($opt) ? $opt[0] : $opt;
    }
    if ($q['type'] === 'multi' && is_array($val)) {
        return implode(', ', array_map(fn($v) => isset($q['options'][$v]) ? $q['options'][$v][0] : $v, $val));
    }
    return $val;
}

$pageTitle = ($a['business_name'] ?: 'Assessment') . ' — Health Check';
require_once __DIR__ . '/includes/header.php';
?>
<style>
  :root{--brand:#0f8a5f;--bg:#f6f8f7;--card:#fff;--text:#1c2b26;--muted:#6b7b75;--border:#e2e8e5;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);}
  .wrap{max-width:760px;margin:0 auto;padding:20px 16px 60px;}
  a.back{color:var(--muted);text-decoration:none;font-size:13px;}
  h1{font-size:21px;margin:10px 0 2px;}
  .meta{color:var(--muted);font-size:13px;margin-bottom:16px;}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px;}
  .card h3{margin-top:0;font-size:15px;}
  .score-row{display:flex;align-items:center;gap:16px;}
  .score-big{font-size:34px;font-weight:800;color:var(--brand);}
  select,input[type=date],textarea{width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;font-size:13.5px;}
  .row{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;}
  .row > div{flex:1;min-width:160px;}
  label{font-size:12px;color:var(--muted);display:block;margin-bottom:4px;}
  .btn{padding:9px 14px;border-radius:8px;border:none;background:var(--brand);color:#fff;font-size:13.5px;font-weight:600;cursor:pointer;}
  .qa{border-bottom:1px solid var(--border);padding:8px 0;font-size:13.5px;}
  .qa:last-child{border-bottom:none;}
  .qa .qtext{color:var(--muted);}
  .qa .aval{font-weight:600;margin-top:2px;}
  .note{background:#f6f8f7;border-radius:8px;padding:10px;margin-top:8px;font-size:13px;}
  .note .meta{margin:0 0 4px;font-size:11px;}
  .link-result{margin-top:8px;font-size:13px;}
  #businessResults{position:relative;}
  #businessResults .opt{position:absolute;background:#fff;border:1px solid var(--border);border-radius:8px;width:100%;max-height:180px;overflow:auto;z-index:5;}
  #businessResults .opt div{padding:8px 10px;cursor:pointer;font-size:13px;}
  #businessResults .opt div:hover{background:#f0f4f2;}
</style>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.7rem;"><?php echo htmlspecialchars($a['business_name'] ?: '(untitled)'); ?></h1>
</div></div>

<section class="page-section"><div class="container">
<div class="wrap" style="padding:0;max-width:760px;">
  <a class="back" href="<?= SITE_URL ?>/agent-health-checks.php">← <?php echo $isAdmin ? 'All Assessments' : 'My Assessments'; ?></a>
  <div class="meta">
    <?php echo htmlspecialchars($a['city'] ?: ''); ?> · <?php echo htmlspecialchars($a['business_category'] ?: ''); ?>
    · Ref: <?php echo htmlspecialchars($a['referral_code']); ?> · Source: <?php echo htmlspecialchars($a['source']); ?>
  </div>

  <?php if ($a['status'] === 'completed'): ?>
  <div class="card">
    <div class="score-row">
      <div class="score-big"><?php echo (int) round($a['total_score']); ?></div>
      <div>
        <div style="font-weight:700;"><?php $b = get_score_band_label($a['score_band']); echo $b['emoji'] . ' ' . htmlspecialchars($b['title']); ?></div>
        <div style="font-size:12.5px;color:var(--muted);">Opportunity: <?php echo strtoupper($a['opportunity_score'] ?? '—'); ?></div>
      </div>
    </div>
    <a href="health-check-results.php?ref=<?php echo urlencode($a['referral_code']); ?>" style="font-size:13px;" target="_blank">View full results screen →</a>
  </div>
  <?php else: ?>
  <div class="card">This assessment is not completed yet (status: <?php echo htmlspecialchars($a['status']); ?>).
    <a href="health-check.php?ref=<?php echo urlencode($a['referral_code']); ?>">Continue it →</a></div>
  <?php endif; ?>

  <div class="card">
    <h3>Status & Follow-up</h3>
    <div class="row">
      <div>
        <label>Status</label>
        <select id="statusSelect">
          <?php foreach ($statusOptions as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo $a['status']===$s?'selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$s)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Follow-up date</label>
        <input type="date" id="followUpDate" value="<?php echo htmlspecialchars($a['follow_up_date'] ?? ''); ?>">
      </div>
    </div>
  </div>

  <div class="card">
    <h3>Linked Business Listing</h3>
    <?php if ($linkedBusiness): ?>
      <div class="link-result">Linked to: <strong><?php echo htmlspecialchars($linkedBusiness['name']); ?></strong>
        <button class="btn" style="background:#eef1f0;color:#1c2b26;margin-left:8px;" id="unlinkBtn">Unlink</button></div>
    <?php else: ?>
      <div id="businessResults">
        <input type="text" id="businessSearch" placeholder="Search existing listings by name...">
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Notes</h3>
    <textarea id="noteText" rows="3" placeholder="Add a note about this lead..."></textarea>
    <button class="btn" style="margin-top:8px;" id="addNoteBtn">Add Note</button>
    <div id="notesList">
      <?php foreach ($notes as $n): ?>
        <div class="note"><div class="meta"><?php echo date('d M Y H:i', strtotime($n['created_at'])); ?></div><?php echo nl2br(htmlspecialchars($n['note'])); ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($answers): ?>
  <div class="card">
    <h3>Full Answers</h3>
    <?php foreach ($answers as $qid => $val):
        if (!isset($questions[$qid])) continue;
        $label = $questions[$qid]['label_en']; ?>
      <div class="qa">
        <div class="qtext"><?php echo htmlspecialchars($label); ?></div>
        <div class="aval"><?php echo htmlspecialchars(fmt_answer($qid, $val, $questions)); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
const ASSESSMENT_ID = <?php echo (int) $a['id']; ?>;

async function postAction(action, extra) {
  const body = new URLSearchParams(Object.assign({ action, assessment_id: ASSESSMENT_ID }, extra));
  const res = await fetch('health-check-admin-actions.php', { method: 'POST', body });
  return res.json();
}

document.getElementById('statusSelect').addEventListener('change', (e) => {
  postAction('update_status', { status: e.target.value });
});
document.getElementById('followUpDate').addEventListener('change', (e) => {
  postAction('set_follow_up', { follow_up_date: e.target.value });
});
document.getElementById('addNoteBtn').addEventListener('click', async () => {
  const ta = document.getElementById('noteText');
  if (!ta.value.trim()) return;
  const data = await postAction('add_note', { note: ta.value.trim() });
  if (data.ok) {
    const div = document.createElement('div');
    div.className = 'note';
    div.innerHTML = `<div class="meta">just now</div>${ta.value.replace(/</g,'&lt;')}`;
    document.getElementById('notesList').prepend(div);
    ta.value = '';
  }
});

const unlinkBtn = document.getElementById('unlinkBtn');
if (unlinkBtn) unlinkBtn.addEventListener('click', async () => {
  await postAction('unlink_business', {});
  location.reload();
});

const searchInput = document.getElementById('businessSearch');
if (searchInput) {
  let t;
  searchInput.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(async () => {
      const q = searchInput.value.trim();
      const box = document.getElementById('businessResults');
      let existing = box.querySelector('.opt');
      if (existing) existing.remove();
      if (q.length < 2) return;
      const res = await fetch('search-businesses.php?q=' + encodeURIComponent(q));
      const results = await res.json();
      const opt = document.createElement('div');
      opt.className = 'opt';
      if (!results.length) {
        opt.innerHTML = '<div style="color:#6b7b75;">No matches</div>';
      } else {
        results.forEach(r => {
          const d = document.createElement('div');
          d.textContent = r.name;
          d.addEventListener('click', async () => {
            await postAction('link_business', { business_id: r.id });
            location.reload();
          });
          opt.appendChild(d);
        });
      }
      box.appendChild(opt);
    }, 300);
  });
}
</script>
</div>
</div></section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
