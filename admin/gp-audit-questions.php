<?php
/**
 * admin/gp-audit-questions.php
 * Manage Business Visibility Audit questions
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireAdmin();
$pdo = db();

$msg = '';
$err = '';

// ── Handle actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gpVerifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_question') {
        $id          = (int)($_POST['id'] ?? 0);
        $section     = max(1, (int)($_POST['section'] ?? 1));
        $secTitle    = trim($_POST['section_title'] ?? '');
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);
        $qText       = trim($_POST['question_text'] ?? '');
        $qType       = in_array($_POST['question_type'] ?? '', ['text','textarea','radio','checkbox']) ? $_POST['question_type'] : 'text';
        $isRequired  = isset($_POST['is_required']) ? 1 : 0;
        $isActive    = isset($_POST['is_active']) ? 1 : 0;
        // Options: one per line
        $rawOpts     = trim($_POST['options_text'] ?? '');
        $opts        = $rawOpts ? array_filter(array_map('trim', explode("\n", $rawOpts))) : null;
        $optsJson    = ($opts && in_array($qType, ['radio','checkbox'])) ? json_encode(array_values($opts)) : null;

        if (!$qText) { $err = 'Question text is required.'; }
        elseif (!$secTitle) { $err = 'Section title is required.'; }
        else {
            if ($id) {
                $pdo->prepare("UPDATE audit_questions SET section=?,section_title=?,sort_order=?,question_text=?,question_type=?,options_json=?,is_required=?,is_active=? WHERE id=?")
                    ->execute([$section,$secTitle,$sortOrder,$qText,$qType,$optsJson,$isRequired,$isActive,$id]);
                $msg = 'Question updated.';
            } else {
                $pdo->prepare("INSERT INTO audit_questions (section,section_title,sort_order,question_text,question_type,options_json,is_required,is_active) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$section,$secTitle,$sortOrder,$qText,$qType,$optsJson,$isRequired,$isActive]);
                $msg = 'Question added.';
            }
        }
    } elseif ($action === 'toggle_active') {
        $id  = (int)$_POST['id'];
        $val = (int)$_POST['val'];
        $pdo->prepare("UPDATE audit_questions SET is_active=? WHERE id=?")->execute([$val,$id]);
        $msg = $val ? 'Question activated.' : 'Question deactivated.';
    } elseif ($action === 'delete_question') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM audit_questions WHERE id=?")->execute([$id]);
        $msg = 'Question deleted.';
    } elseif ($action === 'reorder') {
        $ids = $_POST['ids'] ?? [];
        foreach ($ids as $i => $qid) {
            $pdo->prepare("UPDATE audit_questions SET sort_order=? WHERE id=?")->execute([$i+1,(int)$qid]);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true]);
        exit;
    }
}

// Edit mode
$editing = null;
if (isset($_GET['edit'])) {
    $editing = $pdo->prepare("SELECT * FROM audit_questions WHERE id=?")->execute([(int)$_GET['edit']]) ? null : null;
    $st = $pdo->prepare("SELECT * FROM audit_questions WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $editing = $st->fetch();
}

// Load all questions
$questions = $pdo->query("SELECT * FROM audit_questions ORDER BY section ASC, sort_order ASC, id ASC")->fetchAll();

// Section colours
$secColors = [1=>'#1a4731',2=>'#1a3147',3=>'#471a34',4=>'#473d1a'];

// Admin layout helper
function adminLayout(string $title, string $body): string {
    return $body; // We'll output full page below
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Audit Questions — Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#0a1e15;color:#e0e0e0;min-height:100vh;}
.admin-header{background:#061410;border-bottom:1px solid #1a3328;padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;}
.admin-header h1{font-size:1.1rem;color:#00A878;}
.admin-header a{color:#a0c8b0;font-size:0.85rem;text-decoration:none;}
.wrap{max-width:1100px;margin:0 auto;padding:1.5rem;}
.flash-ok{background:#1a4731;border:1px solid #00A878;color:#a0ffcc;padding:0.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:0.875rem;}
.flash-err{background:#471a1a;border:1px solid #c0392b;color:#ffc0c0;padding:0.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:0.875rem;}
.two-col{display:grid;grid-template-columns:1fr 380px;gap:1.5rem;}
@media(max-width:800px){.two-col{grid-template-columns:1fr;}}

/* Question list */
.q-section{margin-bottom:1.5rem;}
.section-label{font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#00A878;padding:0.4rem 0.75rem;background:#0d2b1d;border-radius:4px;display:inline-block;margin-bottom:0.5rem;}
.q-row{background:#0d2b1d;border:1px solid #1a3328;border-radius:8px;padding:0.75rem 1rem;margin-bottom:0.5rem;display:flex;align-items:flex-start;gap:0.75rem;cursor:grab;}
.q-row.inactive{opacity:0.45;}
.q-row:active{cursor:grabbing;}
.drag-handle{color:#2a4a3a;font-size:1.1rem;padding-top:2px;user-select:none;}
.q-body{flex:1;min-width:0;}
.q-text{font-size:0.875rem;color:#e0e0e0;line-height:1.4;margin-bottom:0.3rem;}
.q-meta{font-size:0.75rem;color:#6b8c7a;}
.q-actions{display:flex;gap:0.5rem;flex-shrink:0;}
.btn{display:inline-block;padding:0.35rem 0.75rem;border-radius:5px;font-size:0.8rem;cursor:pointer;border:none;font-family:inherit;text-decoration:none;}
.btn-green{background:#00A878;color:#fff;}
.btn-green:hover{background:#008a63;}
.btn-grey{background:#1a3328;color:#a0c8b0;border:1px solid #2a4a3a;}
.btn-grey:hover{background:#243d33;}
.btn-red{background:#471a1a;color:#ffc0c0;border:1px solid #c0392b;}
.btn-red:hover{background:#5a2020;}
.btn-sm{padding:0.25rem 0.5rem;font-size:0.75rem;}

/* Form */
.form-card{background:#0d2b1d;border:1px solid #1a3328;border-radius:10px;padding:1.25rem;}
.form-card h3{font-size:1rem;color:#00A878;margin-bottom:1rem;}
.fg{margin-bottom:1rem;}
.fg label{display:block;font-size:0.8rem;color:#a0c8b0;margin-bottom:0.3rem;}
.fg input[type=text],.fg input[type=number],.fg select,.fg textarea{
  width:100%;padding:0.55rem 0.75rem;background:#061410;border:1.5px solid #1a3328;
  border-radius:6px;color:#e0e0e0;font-family:inherit;font-size:0.875rem;
}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:#00A878;}
.fg textarea{resize:vertical;min-height:70px;}
.fg small{color:#6b8c7a;font-size:0.75rem;display:block;margin-top:0.25rem;}
.check-row{display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;margin-top:0.5rem;}
.check-row input{accent-color:#00A878;width:16px;height:16px;}
.type-hint{display:none;background:#061410;border:1px solid #1a3328;border-radius:5px;padding:0.5rem 0.75rem;font-size:0.8rem;color:#a0c8b0;margin-top:0.5rem;}

/* Stats bar */
.stats{display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;}
.stat{background:#0d2b1d;border:1px solid #1a3328;border-radius:8px;padding:0.65rem 1rem;text-align:center;min-width:100px;}
.stat .n{font-size:1.5rem;font-weight:700;color:#00A878;}
.stat .l{font-size:0.75rem;color:#6b8c7a;}
</style>
</head>
<body>
<div class="admin-header">
  <div>
    <h1>🎯 Audit Questions</h1>
  </div>
  <div style="margin-left:auto;display:flex;gap:1rem;align-items:center;">
    <a href="/audit/<?= htmlspecialchars($pdo->query("SELECT slug FROM partner_profiles WHERE slug IS NOT NULL LIMIT 1")->fetchColumn() ?: '1') ?>" target="_blank">Preview audit form ↗</a>
    <a href="/admin/">← Admin</a>
    <a href="/admin/manage-growth-partners.php">Partners</a>
  </div>
</div>

<div class="wrap">

<?php if ($msg): ?><div class="flash-ok">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="flash-err">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php
// Stats
$total   = count($questions);
$active  = count(array_filter($questions, fn($q) => $q['is_active']));
$resps   = $pdo->query("SELECT COUNT(*) FROM audit_responses")->fetchColumn();
$leads   = $pdo->query("SELECT COUNT(*) FROM audit_responses WHERE lead_id IS NOT NULL")->fetchColumn();
?>
<div class="stats">
  <div class="stat"><div class="n"><?= $total ?></div><div class="l">Total questions</div></div>
  <div class="stat"><div class="n"><?= $active ?></div><div class="l">Active</div></div>
  <div class="stat"><div class="n"><?= $resps ?></div><div class="l">Responses</div></div>
  <div class="stat"><div class="n"><?= $leads ?></div><div class="l">Leads created</div></div>
</div>

<div class="two-col">

<!-- Left: question list -->
<div>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
    <h2 style="font-size:1rem;color:#a0c8b0;">Questions <span style="color:#6b8c7a;font-weight:400;">(drag to reorder)</span></h2>
    <a class="btn btn-green btn-sm" href="?new=1">+ Add question</a>
  </div>

  <?php
  // Group by section
  $bySec = [];
  foreach ($questions as $q) {
    $bySec[$q['section']]['title'] = $q['section_title'];
    $bySec[$q['section']]['qs'][]  = $q;
  }
  foreach ($bySec as $secId => $sec):
  ?>
  <div class="q-section">
    <div class="section-label">Section <?= $secId ?>: <?= htmlspecialchars($sec['title']) ?></div>
    <div class="sortable-list" data-section="<?= $secId ?>">
    <?php foreach ($sec['qs'] as $q): ?>
    <div class="q-row<?= $q['is_active'] ? '' : ' inactive' ?>" data-id="<?= $q['id'] ?>">
      <span class="drag-handle">⠿</span>
      <div class="q-body">
        <div class="q-text"><?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 110, '…')) ?></div>
        <div class="q-meta">
          Type: <?= $q['question_type'] ?> ·
          <?= $q['is_required'] ? 'Required' : 'Optional' ?> ·
          Sort: <?= $q['sort_order'] ?> ·
          <?= $q['is_active'] ? '<span style="color:#00A878">Active</span>' : '<span style="color:#c0392b">Inactive</span>' ?>
        </div>
      </div>
      <div class="q-actions">
        <a class="btn btn-grey btn-sm" href="?edit=<?= $q['id'] ?>">Edit</a>
        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this question?')">
          <?= gpCsrfField() ?>
          <input type="hidden" name="action" value="delete_question">
          <input type="hidden" name="id" value="<?= $q['id'] ?>">
          <button class="btn btn-red btn-sm" type="submit">Del</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Right: add/edit form -->
<div>
  <div class="form-card">
    <h3><?= $editing ? '✏️ Edit Question' : '＋ Add Question' ?></h3>
    <form method="post" action="">
      <?= gpCsrfField() ?>
      <input type="hidden" name="action" value="save_question">
      <?php if ($editing): ?>
      <input type="hidden" name="id" value="<?= $editing['id'] ?>">
      <?php endif; ?>

      <div class="fg">
        <label>Section number</label>
        <select name="section" id="sec-sel" onchange="updateSecTitle(this.value)">
          <option value="1" <?= ($editing['section'] ?? 1)==1?'selected':'' ?>>1 — Know Your Business</option>
          <option value="2" <?= ($editing['section'] ?? 1)==2?'selected':'' ?>>2 — How Visible?</option>
          <option value="3" <?= ($editing['section'] ?? 1)==3?'selected':'' ?>>3 — Turn Visibility Into Customers</option>
          <option value="4" <?= ($editing['section'] ?? 1)==4?'selected':'' ?>>4 — Your Next Step</option>
          <option value="5" <?= ($editing['section'] ?? 1)==5?'selected':'' ?>>5 — Custom</option>
        </select>
      </div>

      <div class="fg">
        <label>Section title <small>(shown as heading on form)</small></label>
        <input type="text" name="section_title" id="sec-title"
               value="<?= htmlspecialchars($editing['section_title'] ?? 'Know Your Business') ?>" required>
      </div>

      <div class="fg">
        <label>Sort order <small>(within section)</small></label>
        <input type="number" name="sort_order" value="<?= $editing['sort_order'] ?? (count($questions)+1) ?>" min="0" max="99">
      </div>

      <div class="fg">
        <label>Question text</label>
        <textarea name="question_text" rows="3" required><?= htmlspecialchars($editing['question_text'] ?? '') ?></textarea>
      </div>

      <div class="fg">
        <label>Question type</label>
        <select name="question_type" id="q-type" onchange="toggleOpts(this.value)">
          <option value="text"     <?= ($editing['question_type']??'text')==='text'?'selected':'' ?>>Short text</option>
          <option value="textarea" <?= ($editing['question_type']??'')==='textarea'?'selected':'' ?>>Long text (paragraph)</option>
          <option value="radio"    <?= ($editing['question_type']??'')==='radio'?'selected':'' ?>>Multiple choice (pick one)</option>
          <option value="checkbox" <?= ($editing['question_type']??'')==='checkbox'?'selected':'' ?>>Checkboxes (pick many)</option>
        </select>
      </div>

      <div class="fg" id="opts-fg" style="<?= in_array($editing['question_type']??'',['radio','checkbox'])?'':'display:none' ?>">
        <label>Options <small>(one per line)</small></label>
        <textarea name="options_text" rows="5" placeholder="Option A&#10;Option B&#10;Option C"><?php
          if ($editing && $editing['options_json']) {
            echo htmlspecialchars(implode("\n", json_decode($editing['options_json'], true)));
          }
        ?></textarea>
      </div>

      <div class="check-row">
        <input type="checkbox" name="is_required" id="req" <?= ($editing['is_required']??1)?'checked':'' ?>>
        <label for="req">Required</label>
      </div>
      <div class="check-row">
        <input type="checkbox" name="is_active" id="act" <?= ($editing['is_active']??1)?'checked':'' ?>>
        <label for="act">Active (show on form)</label>
      </div>

      <div style="margin-top:1.25rem;display:flex;gap:0.75rem;">
        <button type="submit" class="btn btn-green"><?= $editing ? 'Save changes' : 'Add question' ?></button>
        <?php if ($editing): ?>
        <a href="?" class="btn btn-grey">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Partner audit link helper -->
  <div class="form-card" style="margin-top:1rem;">
    <h3>🔗 Partner Audit Links</h3>
    <p style="font-size:0.8rem;color:#a0c8b0;margin-bottom:0.75rem;">Each growth partner gets a unique link to share via WhatsApp:</p>
    <?php
    $partners = $pdo->query("SELECT pp.id, pp.slug, pp.display_name, u.name FROM partner_profiles pp JOIN users u ON u.id=pp.user_id WHERE pp.status='approved' ORDER BY u.name")->fetchAll();
    foreach ($partners as $p):
      $slug = $p['slug'] ?: $p['id'];
      $link = 'https://237biz.net/audit/' . htmlspecialchars($slug);
    ?>
    <div style="margin-bottom:0.5rem;">
      <div style="font-size:0.8rem;color:#e0e0e0;"><?= htmlspecialchars($p['display_name'] ?: $p['name']) ?></div>
      <div style="font-size:0.75rem;color:#00A878;word-break:break-all;"><?= $link ?></div>
    </div>
    <?php endforeach; ?>
    <p style="font-size:0.75rem;color:#6b8c7a;margin-top:0.5rem;">Partners without a slug use their numeric ID.</p>
  </div>

  <!-- Responses summary -->
  <?php
  $recent = $pdo->query("SELECT ar.*, pp.display_name AS partner_display, u.name AS partner_name FROM audit_responses ar JOIN partner_profiles pp ON pp.id=ar.partner_id JOIN users u ON u.id=pp.user_id ORDER BY ar.submitted_at DESC LIMIT 10")->fetchAll();
  if ($recent):
  ?>
  <div class="form-card" style="margin-top:1rem;">
    <h3>📋 Recent Responses</h3>
    <?php foreach ($recent as $r): ?>
    <div style="border-bottom:1px solid #1a3328;padding:0.65rem 0;font-size:0.8rem;">
      <div style="color:#e0e0e0;font-weight:600;"><?= htmlspecialchars($r['business_name']) ?></div>
      <div style="color:#a0c8b0;"><?= htmlspecialchars($r['owner_name']) ?> · <?= htmlspecialchars($r['phone']) ?></div>
      <div style="color:#6b8c7a;">via <?= htmlspecialchars($r['partner_display']??$r['partner_name']) ?> · <?= date('d M Y H:i', strtotime($r['submitted_at'])) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (!$recent): ?>
    <p style="color:#6b8c7a;font-size:0.85rem;">No responses yet.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

</div><!-- .two-col -->
</div><!-- .wrap -->

<script>
// Toggle options textarea
function toggleOpts(type) {
  document.getElementById('opts-fg').style.display = (type==='radio'||type==='checkbox') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function() {
  toggleOpts(document.getElementById('q-type').value);
});

// Section title auto-fill
const sectionTitles = {
  '1':'Know Your Business','2':'How Visible Is Your Business?',
  '3':'Turn Visibility Into Customers','4':'Your Next Step','5':''
};
function updateSecTitle(v) {
  var t = document.getElementById('sec-title');
  if (sectionTitles[v] !== undefined && sectionTitles[v] !== '') t.value = sectionTitles[v];
}

// Drag-to-reorder (simple, no library)
document.querySelectorAll('.sortable-list').forEach(function(list) {
  var dragging = null;
  list.querySelectorAll('.q-row').forEach(function(row) {
    row.setAttribute('draggable','true');
    row.addEventListener('dragstart', function() { dragging = this; this.style.opacity='0.4'; });
    row.addEventListener('dragend', function() { this.style.opacity=''; saveOrder(list); });
    row.addEventListener('dragover', function(e) {
      e.preventDefault();
      var after = getDragAfterElement(list, e.clientY);
      if (after == null) list.appendChild(dragging);
      else list.insertBefore(dragging, after);
    });
  });
});
function getDragAfterElement(list, y) {
  var els = [...list.querySelectorAll('.q-row:not(.dragging)')];
  return els.reduce(function(closest, child) {
    var box = child.getBoundingClientRect();
    var offset = y - box.top - box.height/2;
    if (offset < 0 && offset > closest.offset) return {offset:offset,element:child};
    return closest;
  }, {offset:Number.NEGATIVE_INFINITY}).element;
}
function saveOrder(list) {
  var ids = [...list.querySelectorAll('.q-row')].map(function(r){return r.dataset.id;});
  var fd = new FormData();
  fd.append('action','reorder');
  ids.forEach(function(id){ fd.append('ids[]',id); });
  fd.append('csrf_token', document.querySelector('input[name=csrf_token]').value);
  fetch('', {method:'POST',body:fd});
}
</script>
</body>
</html>
