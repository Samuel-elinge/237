<?php
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$errors = [];

// ── Save sequence ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'sequence') {
    verifyCsrf();
    $name    = trim($_POST['name'] ?? '');
    $desc    = trim($_POST['description'] ?? '');
    $trigger = $_POST['trigger_event'] ?? 'manual';
    $exTag   = trim($_POST['exclude_tag'] ?? '') ?: null;
    $tgtTag  = trim($_POST['target_tag'] ?? '') ?: null;
    $active  = isset($_POST['active']) ? 1 : 0;
    $sid     = (int)($_POST['seq_id'] ?? 0);

    if (!$name) $errors[] = 'Sequence name required.';
    if (!$errors) {
        if ($sid) {
            db()->prepare("UPDATE automation_sequences SET name=?,description=?,trigger_event=?,exclude_tag=?,target_tag=?,active=? WHERE id=?")
                 ->execute([$name,$desc,$trigger,$exTag,$tgtTag,$active,$sid]);
        } else {
            db()->prepare("INSERT INTO automation_sequences (name,description,trigger_event,exclude_tag,target_tag,active) VALUES (?,?,?,?,?,?)")
                 ->execute([$name,$desc,$trigger,$exTag,$tgtTag,$active]);
            $sid = db()->lastInsertId();
        }
        flash('success', 'Sequence saved.');
        redirect(SITE_URL . '/admin/automation/sequences.php?action=edit&id=' . $sid);
    }
}

// ── Save step ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'step') {
    verifyCsrf();
    $seqId    = (int)($_POST['seq_id'] ?? 0);
    $tplId    = (int)($_POST['template_id'] ?? 0);
    $delay    = (int)($_POST['delay_days'] ?? 0);
    $stepId   = (int)($_POST['step_id'] ?? 0);

    if ($stepId) {
        db()->prepare("UPDATE automation_steps SET template_id=?,delay_days=? WHERE id=? AND sequence_id=?")
             ->execute([$tplId,$delay,$stepId,$seqId]);
    } else {
        $maxOrder = db()->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM automation_steps WHERE sequence_id=?");
        $maxOrder->execute([$seqId]);
        $sort = $maxOrder->fetchColumn();
        db()->prepare("INSERT INTO automation_steps (sequence_id,template_id,delay_days,sort_order) VALUES (?,?,?,?)")
             ->execute([$seqId,$tplId,$delay,$sort]);
    }
    flash('success', 'Step saved.');
    redirect(SITE_URL . '/admin/automation/sequences.php?action=edit&id=' . $seqId);
}

// ── Delete step ───────────────────────────────────────────
if ($action === 'delete-step') {
    $stepId = (int)($_GET['step'] ?? 0);
    $seqId  = (int)($_GET['seq'] ?? 0);
    db()->prepare("DELETE FROM automation_steps WHERE id=? AND sequence_id=?")->execute([$stepId,$seqId]);
    redirect(SITE_URL . '/admin/automation/sequences.php?action=edit&id=' . $seqId);
}

// ── Delete sequence ───────────────────────────────────────
if ($action === 'delete' && $id) {
    db()->prepare("DELETE FROM automation_sequences WHERE id=?")->execute([$id]);
    flash('success', 'Sequence deleted.');
    redirect(SITE_URL . '/admin/automation/sequences.php');
}

// ── Load for edit ─────────────────────────────────────────
$seq   = null;
$steps = [];
if (($action === 'edit') && $id) {
    $st = db()->prepare("SELECT * FROM automation_sequences WHERE id=?");
    $st->execute([$id]);
    $seq = $st->fetch();
    if (!$seq) redirect(SITE_URL . '/admin/automation/sequences.php');
    $steps = db()->prepare("SELECT s.*,t.name AS tpl_name,t.subject_en FROM automation_steps s JOIN automation_templates t ON t.id=s.template_id WHERE s.sequence_id=? ORDER BY s.sort_order")->execute([$id]) ? [] : [];
    $stSt = db()->prepare("SELECT s.*,t.name AS tpl_name,t.subject_en FROM automation_steps s JOIN automation_templates t ON t.id=s.template_id WHERE s.sequence_id=? ORDER BY s.sort_order");
    $stSt->execute([$id]);
    $steps = $stSt->fetchAll();
}

$sequences  = db()->query("SELECT s.*, (SELECT COUNT(*) FROM automation_steps WHERE sequence_id=s.id) AS step_count, (SELECT COUNT(*) FROM automation_enrollments WHERE sequence_id=s.id AND status='active') AS active_count FROM automation_sequences s ORDER BY s.id")->fetchAll();
$templates  = db()->query("SELECT id,name,subject_en FROM automation_templates ORDER BY name")->fetchAll();
$tags       = db()->query("SELECT name FROM automation_tags ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$triggerOpts = ['registration','listing_created','listing_approved','service_purchased','inactive_30d','enquiry_received','manual'];

// Enrollment management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'enroll') {
    verifyCsrf();
    $seqId  = (int)($_POST['seq_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($seqId && $userId) {
        // Get first step for next_send_at
        $firstStep = db()->prepare("SELECT delay_days FROM automation_steps WHERE sequence_id=? ORDER BY sort_order LIMIT 1");
        $firstStep->execute([$seqId]);
        $fs = $firstStep->fetch();
        $nextSend = $fs ? date('Y-m-d H:i:s', strtotime('+' . $fs['delay_days'] . ' days')) : date('Y-m-d H:i:s');
        db()->prepare("INSERT IGNORE INTO automation_enrollments (user_id,sequence_id,next_send_at) VALUES (?,?,?)")
             ->execute([$userId, $seqId, $nextSend]);
        flash('success', 'User enrolled.');
    }
    redirect(SITE_URL . '/admin/automation/sequences.php?action=edit&id=' . $seqId);
}

$pageTitle = 'Sequences — Admin 237Biz';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">🔗 Sequences</h1>
</div></div>

<section class="page-section"><div class="container">

  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
    <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
    <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
    <a href="<?= SITE_URL ?>/admin/automation/" class="filter-tab">🤖 Automation</a>
    <a href="<?= SITE_URL ?>/admin/automation/templates.php" class="filter-tab">✉️ Templates</a>
    <a href="<?= SITE_URL ?>/admin/automation/sequences.php" class="filter-tab active">🔗 Sequences</a>
    <a href="<?= SITE_URL ?>/admin/automation/segments.php" class="filter-tab">🏷️ Segments</a>
    <a href="<?= SITE_URL ?>/admin/automation/log.php" class="filter-tab">📊 Send Log</a>
    <a href="<?= SITE_URL ?>/dashboard" class="filter-tab">← Dashboard</a>
  </div>

  <?php if ($action === 'edit' && $seq): ?>
    <!-- EDIT SEQUENCE + STEPS -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:0.5rem;">
      <h4>Edit: <?= e($seq['name']) ?></h4>
      <a href="<?= SITE_URL ?>/admin/automation/sequences.php" class="btn btn-outline btn-sm">← All Sequences</a>
    </div>

    <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

    <!-- Sequence settings -->
    <div class="listing-widget" style="margin-bottom:1.5rem;">
      <h4 style="margin-bottom:1rem;">Sequence Settings</h4>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="form" value="sequence">
        <input type="hidden" name="seq_id" value="<?= $seq['id'] ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div class="form-group"><label>Name *</label><input type="text" name="name" required value="<?= e($seq['name']) ?>"></div>
          <div class="form-group">
            <label>Trigger Event</label>
            <select name="trigger_event">
              <?php foreach ($triggerOpts as $t): ?>
                <option value="<?= $t ?>" <?= $seq['trigger_event']==$t?'selected':'' ?>><?= ucwords(str_replace('_',' ',$t)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Description</label><input type="text" name="description" value="<?= e($seq['description'] ?? '') ?>"></div>
          <div class="form-group">
            <label>Exclude tag (don't enroll if user has this tag)</label>
            <select name="exclude_tag">
              <option value="">— None —</option>
              <?php foreach ($tags as $tag): ?><option value="<?= e($tag) ?>" <?= ($seq['exclude_tag']==$tag)?'selected':'' ?>><?= e($tag) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Target tag (only enroll if user has this tag)</label>
            <select name="target_tag">
              <option value="">— Any user —</option>
              <?php foreach ($tags as $tag): ?><option value="<?= e($tag) ?>" <?= ($seq['target_tag']==$tag)?'selected':'' ?>><?= e($tag) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:0.6rem;padding-top:1.5rem;">
            <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;font-size:0.85rem;color:var(--muted);">
              <input type="checkbox" name="active" <?= $seq['active']?'checked':'' ?> style="accent-color:var(--green);"> Active
            </label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:0.5rem;">Save Settings</button>
      </form>
    </div>

    <!-- Steps -->
    <div class="listing-widget" style="margin-bottom:1.5rem;">
      <h4 style="margin-bottom:1rem;">Email Steps (<?= count($steps) ?>)</h4>
      <?php if ($steps): ?>
        <div style="display:flex;flex-direction:column;gap:0.5rem;margin-bottom:1.25rem;">
          <?php foreach ($steps as $i => $step): ?>
            <div style="display:flex;align-items:center;gap:1rem;padding:0.85rem 1rem;background:rgba(255,255,255,0.03);border-radius:8px;border:1px solid var(--border);">
              <span style="width:24px;height:24px;border-radius:50%;background:rgba(0,168,120,0.15);border:1px solid rgba(0,168,120,0.3);display:flex;align-items:center;justify-content:center;font-size:0.75rem;color:var(--green);flex-shrink:0;"><?= $i+1 ?></span>
              <div style="flex:1;">
                <div style="font-size:0.85rem;color:var(--white);font-weight:500;"><?= e($step['tpl_name']) ?></div>
                <div style="font-size:0.75rem;color:var(--muted-2);">
                  <?= $step['delay_days'] == 0 ? 'Send immediately' : 'Send after ' . $step['delay_days'] . ' day' . ($step['delay_days']!=1?'s':'') ?>
                  · <?= e(mb_substr($step['subject_en'],0,60)) ?>
                </div>
              </div>
              <a href="?action=delete-step&step=<?= $step['id'] ?>&seq=<?= $seq['id'] ?>"
                 onclick="return confirm('Remove this step?')"
                 class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);flex-shrink:0;">✗</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p style="color:var(--muted);font-size:0.85rem;margin-bottom:1rem;">No steps yet — add the first email below.</p>
      <?php endif; ?>

      <form method="POST" style="border-top:1px solid var(--border);padding-top:1rem;">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="form" value="step">
        <input type="hidden" name="seq_id" value="<?= $seq['id'] ?>">
        <div style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
          <div class="form-group" style="flex:1;min-width:180px;margin-bottom:0;">
            <label>Email Template</label>
            <select name="template_id" required>
              <option value="">— Select —</option>
              <?php foreach ($templates as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?> — <?= e(mb_substr($t['subject_en'],0,40)) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="width:140px;margin-bottom:0;">
            <label>Delay (days after enroll)</label>
            <input type="number" name="delay_days" min="0" value="0">
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="height:42px;">+ Add Step</button>
        </div>
      </form>
    </div>

    <!-- Manual enroll -->
    <div class="listing-widget">
      <h4 style="margin-bottom:0.75rem;">Manually Enroll a User</h4>
      <form method="POST" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="form" value="enroll">
        <input type="hidden" name="seq_id" value="<?= $seq['id'] ?>">
        <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0;">
          <label>User ID or Email</label>
          <?php
          $users = db()->query("SELECT id, name, email FROM users ORDER BY name LIMIT 200")->fetchAll();
          ?>
          <select name="user_id" required>
            <option value="">— Select user —</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-outline btn-sm" style="height:42px;">Enroll →</button>
      </form>
    </div>

  <?php else: ?>
    <!-- LIST -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
      <p style="color:var(--muted);font-size:0.875rem;"><?= count($sequences) ?> sequences</p>
      <a href="?action=new" class="btn btn-primary btn-sm">+ New Sequence</a>
    </div>

    <?php if ($action === 'new'): ?>
      <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
      <div class="listing-widget" style="margin-bottom:1.5rem;border-color:rgba(0,168,120,0.25);">
        <h4 style="margin-bottom:1rem;">New Sequence</h4>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="form" value="sequence">
          <input type="hidden" name="seq_id" value="0">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div class="form-group"><label>Name *</label><input type="text" name="name" required placeholder="e.g. Welcome Series"></div>
            <div class="form-group">
              <label>Trigger Event</label>
              <select name="trigger_event">
                <?php foreach ($triggerOpts as $t): ?><option value="<?= $t ?>"><?= ucwords(str_replace('_',' ',$t)) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Description</label><input type="text" name="description"></div>
            <div class="form-group">
              <label>Exclude tag</label>
              <select name="exclude_tag"><option value="">— None —</option><?php foreach ($tags as $tag): ?><option value="<?= e($tag) ?>"><?= e($tag) ?></option><?php endforeach; ?></select>
            </div>
          </div>
          <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;margin-bottom:1rem;font-size:0.85rem;color:var(--muted);">
            <input type="checkbox" name="active" checked style="accent-color:var(--green);"> Active
          </label>
          <button type="submit" class="btn btn-primary btn-sm">Create Sequence</button>
        </form>
      </div>
    <?php endif; ?>

    <div style="display:flex;flex-direction:column;gap:0.75rem;">
      <?php foreach ($sequences as $s): ?>
        <div class="listing-widget" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;<?= !$s['active'] ? 'opacity:0.5;' : '' ?>">
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
              <span style="font-weight:500;color:var(--white);"><?= e($s['name']) ?></span>
              <?php if (!$s['active']): ?><span class="badge badge-rejected" style="font-size:0.65rem;">Inactive</span><?php endif; ?>
              <span style="font-size:0.72rem;background:rgba(0,168,120,0.1);color:var(--green);padding:0.15rem 0.6rem;border-radius:20px;"><?= str_replace('_',' ',$s['trigger_event']) ?></span>
            </div>
            <div style="font-size:0.78rem;color:var(--muted-2);margin-top:0.25rem;"><?= $s['step_count'] ?> step<?= $s['step_count']!=1?'s':'' ?> · <?= $s['active_count'] ?> active enrollments<?= $s['exclude_tag'] ? ' · excludes: '.$s['exclude_tag'] : '' ?></div>
          </div>
          <div style="display:flex;gap:0.4rem;flex-shrink:0;">
            <a href="?action=edit&id=<?= $s['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
            <a href="?action=delete&id=<?= $s['id'] ?>" onclick="return confirm('Delete this sequence?')" class="btn btn-sm" style="background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);">✗</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
