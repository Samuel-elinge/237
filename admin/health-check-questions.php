<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();
require_once __DIR__ . '/../includes/health-check-questions.php';

try {
    $qStmt = $pdo->query("SELECT * FROM health_questions ORDER BY step, sort_order, id");
    $dbQuestions = $qStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    die('health_questions table not found — run schema-v2.sql and seed-health-questions.php first.');
}

$byStep = [];
foreach ($dbQuestions as $q) { $byStep[$q['step']][] = $q; }
ksort($byStep);

$pageTitle = 'Manage Questions — Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  :root{--brand:#0f8a5f;--bg:#f6f8f7;--card:#fff;--text:#1c2b26;--muted:#6b7b75;--border:#e2e8e5;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);}
  .wrap{max-width:860px;margin:0 auto;padding:20px 16px 60px;}
  h1{font-size:22px;margin-bottom:4px;}
  .subtitle{color:var(--muted);font-size:13.5px;margin-bottom:20px;}
  .btn{padding:9px 16px;border-radius:8px;border:none;font-size:13.5px;font-weight:600;cursor:pointer;}
  .btn-primary{background:var(--brand);color:#fff;}
  .btn-secondary{background:#eef1f0;color:var(--text);}
  .btn-danger{background:#fbe9e9;color:#a33;}
  .step-block{margin-bottom:22px;}
  .step-title{font-size:14px;font-weight:700;color:var(--muted);margin-bottom:8px;text-transform:uppercase;}
  .qcard{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:flex-start;gap:10px;}
  .qcard.inactive{opacity:.5;}
  .qcard .qkey{font-size:11px;color:var(--muted);font-family:monospace;}
  .qcard .qlabel{font-weight:600;font-size:14px;margin-top:2px;}
  .qcard .qmeta{font-size:12px;color:var(--muted);margin-top:4px;}
  .qcard .actions{display:flex;gap:6px;flex-shrink:0;}
  .qcard .actions button{padding:6px 10px;font-size:12px;}
  /* Editor modal */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);align-items:flex-start;justify-content:center;z-index:50;overflow:auto;}
  .modal-overlay.open{display:flex;}
  .modal{background:#fff;border-radius:14px;padding:22px;max-width:560px;width:92%;margin:30px auto;}
  .modal h3{margin-top:0;}
  .field{margin-bottom:12px;}
  .field label{display:block;font-size:12px;color:var(--muted);margin-bottom:4px;}
  .field input,.field select,.field textarea{width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;font-size:13.5px;}
  .row2{display:flex;gap:10px;}
  .row2 > div{flex:1;}
  .opt-row{display:flex;gap:6px;margin-bottom:6px;align-items:center;}
  .opt-row input{flex:1;padding:7px;font-size:13px;border:1px solid var(--border);border-radius:6px;}
  .opt-row input.points{flex:0 0 60px;}
  .opt-row button{flex:0 0 auto;background:#fbe9e9;color:#a33;border:none;border-radius:6px;padding:6px 8px;cursor:pointer;}
  .close{float:right;cursor:pointer;color:var(--muted);}
</style>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">⚙️ Manage Health Check Questions</h1>
</div></div>

<section class="page-section"><div class="container">
<div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
  <a href="<?= SITE_URL ?>/agent-health-checks.php" class="filter-tab">📋 Assessments</a>
  <a href="<?= SITE_URL ?>/admin/health-check-overview.php" class="filter-tab">📊 Market Overview</a>
  <a href="<?= SITE_URL ?>/admin/health-check-questions.php" class="filter-tab active">⚙️ Questions</a>
  <a href="<?= SITE_URL ?>/admin/health-check-staff.php" class="filter-tab">👥 Sales Staff</a>
</div>

<div class="wrap" style="padding:0;max-width:none;">
  <div class="subtitle">Changes apply site-wide to the assessment wizard immediately.</div>
  <button class="btn btn-primary" id="addBtn">+ Add Question</button>

  <?php foreach (HEALTH_CHECK_STEPS as $stepNum => $stepInfo): if ($stepNum == 1 || $stepNum == 7) continue; ?>
    <div class="step-block">
      <div class="step-title">Step <?php echo $stepNum; ?> — <?php echo htmlspecialchars($stepInfo['label_en']); ?></div>
      <?php foreach (($byStep[$stepNum] ?? []) as $q): ?>
        <div class="qcard <?php echo $q['active'] ? '' : 'inactive'; ?>" data-q='<?php echo htmlspecialchars(json_encode($q), ENT_QUOTES); ?>'>
          <div>
            <div class="qkey"><?php echo htmlspecialchars($q['question_key']); ?> · <?php echo htmlspecialchars($q['type']); ?> · <?php echo htmlspecialchars($q['category']); ?></div>
            <div class="qlabel"><?php echo htmlspecialchars($q['label_en']); ?></div>
            <div class="qmeta">Max points: <?php echo $q['max_points']; ?> · Order: <?php echo $q['sort_order']; ?> · <?php echo $q['active'] ? 'Active' : 'Inactive'; ?></div>
          </div>
          <div class="actions">
            <button class="btn btn-secondary edit-btn">Edit</button>
            <button class="btn btn-danger toggle-btn" data-id="<?php echo $q['id']; ?>" data-active="<?php echo $q['active']; ?>">
              <?php echo $q['active'] ? 'Deactivate' : 'Activate'; ?>
            </button>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($byStep[$stepNum])): ?><div style="color:var(--muted);font-size:13px;">No questions in this step yet.</div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal-overlay" id="editorModal">
  <div class="modal">
    <span class="close" id="closeEditor">✕</span>
    <h3 id="editorTitle">Add Question</h3>
    <form id="qForm">
      <input type="hidden" id="f_id" value="">
      <div class="row2">
        <div class="field"><label>Question Key (unique, no spaces)</label><input type="text" id="f_key" required></div>
        <div class="field"><label>Step</label>
          <select id="f_step">
            <option value="2">2 — Online Presence</option>
            <option value="3">3 — Social Media</option>
            <option value="4">4 — Customer Discovery</option>
            <option value="5">5 — Customer Engagement</option>
            <option value="6">6 — Digital Marketing</option>
          </select></div>
      </div>
      <div class="row2">
        <div class="field"><label>Category (scoring)</label>
          <select id="f_category">
            <option value="online_presence">Online Presence</option>
            <option value="customer_discovery">Customer Discovery</option>
            <option value="social_media">Social Media</option>
            <option value="customer_engagement">Customer Engagement</option>
            <option value="digital_marketing">Digital Marketing</option>
          </select></div>
        <div class="field"><label>Type</label>
          <select id="f_type">
            <option value="single">Single choice (scored)</option>
            <option value="multi">Multi-select (info only)</option>
            <option value="scale">1-5 scale (scored)</option>
          </select></div>
      </div>
      <div class="field"><label>Label (English)</label><input type="text" id="f_label_en" required></div>
      <div class="field"><label>Label (French)</label><input type="text" id="f_label_fr" required></div>

      <div class="field" id="optionsBlock">
        <label>Options (label EN / label FR / points if scored)</label>
        <div id="optionRows"></div>
        <button type="button" class="btn btn-secondary" id="addOptRow">+ Add Option</button>
      </div>

      <div class="field" id="scaleBlock" style="display:none;">
        <label>Points for scale answers 1,2,3,4,5 (comma-separated)</label>
        <input type="text" id="f_scale_points" placeholder="0,3,6,8,10">
      </div>

      <div class="row2">
        <div class="field"><label>Max points (category cap)</label><input type="number" step="0.5" id="f_max_points" value="0"></div>
        <div class="field"><label>Sort order</label><input type="number" id="f_sort_order" value="10"></div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;">Save Question</button>
    </form>
  </div>
</div>

<script>
function optRow(valKey, en, fr, points, isScored) {
  const row = document.createElement('div');
  row.className = 'opt-row';
  row.innerHTML = `
    <input placeholder="value_key" class="opt-key" value="${valKey||''}">
    <input placeholder="Label EN" class="opt-en" value="${en||''}">
    <input placeholder="Label FR" class="opt-fr" value="${fr||''}">
    ${isScored ? `<input placeholder="pts" type="number" class="points opt-pts" value="${points||0}">` : ''}
    <button type="button" onclick="this.parentElement.remove()">✕</button>`;
  return row;
}

function refreshOptionInputsForType() {
  const type = document.getElementById('f_type').value;
  document.getElementById('optionsBlock').style.display = (type === 'single' || type === 'multi') ? 'block' : 'none';
  document.getElementById('scaleBlock').style.display = (type === 'scale') ? 'block' : 'none';
  // rebuild existing rows to add/remove the points field
  const rows = Array.from(document.querySelectorAll('#optionRows .opt-row'));
  const data = rows.map(r => ({
    key: r.querySelector('.opt-key').value,
    en: r.querySelector('.opt-en').value,
    fr: r.querySelector('.opt-fr').value,
    pts: r.querySelector('.opt-pts') ? r.querySelector('.opt-pts').value : 0,
  }));
  document.getElementById('optionRows').innerHTML = '';
  data.forEach(d => document.getElementById('optionRows').appendChild(optRow(d.key, d.en, d.fr, d.pts, type === 'single')));
}
document.getElementById('f_type').addEventListener('change', refreshOptionInputsForType);

document.getElementById('addOptRow').addEventListener('click', () => {
  const type = document.getElementById('f_type').value;
  document.getElementById('optionRows').appendChild(optRow('', '', '', 0, type === 'single'));
});

function openEditor(q) {
  document.getElementById('editorModal').classList.add('open');
  document.getElementById('optionRows').innerHTML = '';
  if (!q) {
    document.getElementById('editorTitle').textContent = 'Add Question';
    document.getElementById('f_id').value = '';
    document.getElementById('f_key').value = '';
    document.getElementById('f_key').disabled = false;
    document.getElementById('f_step').value = '2';
    document.getElementById('f_category').value = 'online_presence';
    document.getElementById('f_type').value = 'single';
    document.getElementById('f_label_en').value = '';
    document.getElementById('f_label_fr').value = '';
    document.getElementById('f_scale_points').value = '';
    document.getElementById('f_max_points').value = 0;
    document.getElementById('f_sort_order').value = 10;
    refreshOptionInputsForType();
    return;
  }
  document.getElementById('editorTitle').textContent = 'Edit Question';
  document.getElementById('f_id').value = q.id;
  document.getElementById('f_key').value = q.question_key;
  document.getElementById('f_key').disabled = true; // keying changes would orphan historical answers
  document.getElementById('f_step').value = q.step;
  document.getElementById('f_category').value = q.category;
  document.getElementById('f_type').value = q.type;
  document.getElementById('f_label_en').value = q.label_en;
  document.getElementById('f_label_fr').value = q.label_fr;
  document.getElementById('f_max_points').value = q.max_points;
  document.getElementById('f_sort_order').value = q.sort_order;
  refreshOptionInputsForType();

  if ((q.type === 'single' || q.type === 'multi') && q.options_json) {
    const opts = JSON.parse(q.options_json);
    Object.entries(opts).forEach(([key, val]) => {
      const en = val[0], fr = val[1], pts = val[2] || 0;
      document.getElementById('optionRows').appendChild(optRow(key, en, fr, pts, q.type === 'single'));
    });
  }
  if (q.type === 'scale' && q.scale_points_json) {
    document.getElementById('f_scale_points').value = JSON.parse(q.scale_points_json).join(',');
  }
}

document.getElementById('addBtn').addEventListener('click', () => openEditor(null));
document.getElementById('closeEditor').addEventListener('click', () => document.getElementById('editorModal').classList.remove('open'));

document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const q = JSON.parse(btn.closest('.qcard').dataset.q);
    openEditor(q);
  });
});

document.querySelectorAll('.toggle-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id = btn.dataset.id;
    const nextActive = btn.dataset.active === '1' ? 0 : 1;
    const body = new URLSearchParams({ action: 'question_toggle_active', id, active: nextActive });
    await fetch('health-check-question-actions.php', { method: 'POST', body });
    location.reload();
  });
});

document.getElementById('qForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const type = document.getElementById('f_type').value;
  let optionsJson = null, scaleJson = null;

  if (type === 'single' || type === 'multi') {
    const opts = {};
    document.querySelectorAll('#optionRows .opt-row').forEach(r => {
      const key = r.querySelector('.opt-key').value.trim();
      if (!key) return;
      const en = r.querySelector('.opt-en').value.trim();
      const fr = r.querySelector('.opt-fr').value.trim();
      if (type === 'single') {
        const pts = parseFloat(r.querySelector('.opt-pts').value || 0);
        opts[key] = [en, fr, pts];
      } else {
        opts[key] = [en, fr];
      }
    });
    optionsJson = JSON.stringify(opts);
  } else if (type === 'scale') {
    const parts = document.getElementById('f_scale_points').value.split(',').map(v => parseFloat(v.trim()) || 0);
    scaleJson = JSON.stringify(parts);
  }

  const body = new URLSearchParams({
    action: 'question_save',
    id: document.getElementById('f_id').value,
    question_key: document.getElementById('f_key').value.trim(),
    step: document.getElementById('f_step').value,
    category: document.getElementById('f_category').value,
    type,
    label_en: document.getElementById('f_label_en').value.trim(),
    label_fr: document.getElementById('f_label_fr').value.trim(),
    options_json: optionsJson || '',
    scale_points_json: scaleJson || '',
    max_points: document.getElementById('f_max_points').value,
    sort_order: document.getElementById('f_sort_order').value,
  });
  const res = await fetch('health-check-question-actions.php', { method: 'POST', body });
  const data = await res.json();
  if (data.ok) { location.reload(); } else { alert(data.error || 'Save failed'); }
});
</script>
</div>
</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
