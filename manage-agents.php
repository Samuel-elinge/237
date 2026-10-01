<?php
/**
 * admin/manage-agents.php — 237Biz
 * Create agents, assign leads, view performance, manage referral links.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/referral-helpers.php';
requireAdmin();
$pdo = db();

$tab = $_GET['tab'] ?? 'agents';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Create agent account
    if ($action === 'create_agent') {
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        $refCode  = trim($_POST['ref_code'] ?? '');

        if (!$name)  $errors[] = 'Name required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
        if (strlen($password) < 8) $errors[] = 'Password min 8 characters.';

        if (!$errors) {
            $exists = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $exists->execute([$email]);
            if ($exists->fetch()) {
                $errors[] = 'Email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO users (name,email,password,role,verified) VALUES (?,?,?,'sales_staff',1)")
                    ->execute([$name, $email, $hash]);
                $uid = (int)$pdo->lastInsertId();

                // Create referral link
                $code = $refCode ?: generateReferralCode($name);
                $pdo->prepare("INSERT INTO referral_links (user_id,programme_id,code) VALUES (?,1,?)")
                    ->execute([$uid, $code]);

                flash('success', "Agent {$name} created. Referral code: {$code}");
                redirect(SITE_URL . '/admin/manage-agents?tab=agents');
            }
        }
    }

    // Assign lead to agent
    if ($action === 'assign_lead') {
        $agentId = (int)($_POST['agent_id'] ?? 0);
        $listingId = (int)($_POST['listing_id'] ?? 0);
        $u = currentUser();
        if ($agentId && $listingId) {
            // Check not already assigned
            $check = $pdo->prepare("SELECT id FROM agent_assignments WHERE agent_id=? AND listing_id=?");
            $check->execute([$agentId, $listingId]);
            if (!$check->fetch()) {
                $pdo->prepare("INSERT INTO agent_assignments (agent_id,listing_id,assigned_by) VALUES (?,?,?)")
                    ->execute([$agentId, $listingId, $u['id']]);
                flash('success', 'Lead assigned.');
            }
        }
        redirect(SITE_URL . '/admin/manage-agents?tab=leads');
    }

    // Update assignment status
    if ($action === 'update_assignment') {
        $aid    = (int)($_POST['assignment_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['notes'] ?? '');
        $followup = trim($_POST['next_followup'] ?? '') ?: null;
        if ($aid && in_array($status, ['assigned','contacted','follow_up','converted','lost'])) {
            $pdo->prepare("UPDATE agent_assignments SET status=?, notes=COALESCE(NULLIF(?,''),notes), next_followup=? WHERE id=?")
                ->execute([$status, $notes, $followup, $aid]);
            flash('success', 'Assignment updated.');
        }
        redirect(SITE_URL . '/admin/manage-agents?tab=leads');
    }
}

// ── Data ─────────────────────────────────────────────────────────────────
$agents = [];
try {
    $agents = $pdo->query("
        SELECT u.id, u.name, u.email, u.created_at,
            rl.code AS ref_code, rl.clicks, rl.unique_clicks,
            (SELECT COUNT(*) FROM agent_assignments aa WHERE aa.agent_id=u.id) AS total_leads,
            (SELECT COUNT(*) FROM agent_assignments aa WHERE aa.agent_id=u.id AND aa.status='converted') AS converted,
            (SELECT SUM(c.amount_xaf) FROM commissions c WHERE c.user_id=u.id AND c.status='approved') AS approved_xaf,
            (SELECT SUM(c.amount_xaf) FROM commissions c WHERE c.user_id=u.id AND c.status='paid') AS paid_xaf
        FROM users u
        LEFT JOIN referral_links rl ON rl.user_id=u.id
        WHERE u.role='sales_staff'
        ORDER BY u.name
    ")->fetchAll();
} catch (Exception $e) { $agents = []; }

// Leads (assignments)
$agentFilter = (int)($_GET['agent_id'] ?? 0);
$assignSQL = $agentFilter ? "AND aa.agent_id={$agentFilter}" : '';
$assignments = [];
try {
    $assignments = $pdo->query("
        SELECT aa.*, u.name AS agent_name, l.title AS listing_title, l.slug AS listing_slug,
               loc.name_en AS city
        FROM agent_assignments aa
        JOIN users u    ON u.id  = aa.agent_id
        JOIN listings l ON l.id  = aa.listing_id
        LEFT JOIN locations loc ON loc.id = l.location_id
        WHERE 1=1 {$assignSQL}
        ORDER BY aa.status='follow_up' DESC, aa.next_followup ASC, aa.assigned_at DESC
        LIMIT 200
    ")->fetchAll();
} catch (Exception $e) { $assignments = []; }

// Listings for lead assignment
$listings = [];
try {
    $listings = $pdo->query("SELECT l.id, l.title, loc.name_en AS city FROM listings l LEFT JOIN locations loc ON loc.id=l.location_id WHERE l.status='approved' ORDER BY l.title LIMIT 500")->fetchAll();
} catch (Exception $e) { $listings = []; }

$pageTitle = 'Manage Agents — Admin — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.agent-card { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:18px 20px;margin-bottom:10px;display:flex;align-items:center;gap:16px;flex-wrap:wrap; }
.agent-stat { text-align:center;min-width:70px; }
.agent-stat .val { font-size:1.1rem;font-weight:900;font-family:'Fraunces',serif; }
.agent-stat .lbl { font-size:11px;color:var(--muted); }
.ref-code { font-family:monospace;background:rgba(0,168,120,0.1);color:#00A878;border:1px solid rgba(0,168,120,0.3);border-radius:6px;padding:3px 10px;font-size:13px;font-weight:700; }
.assign-row { padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.05);display:flex;align-items:center;gap:12px;flex-wrap:wrap; }
.status-pill { font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px; }
.s-assigned   { background:rgba(252,209,22,0.12);color:#fcd116; }
.s-contacted  { background:rgba(138,180,248,0.12);color:#8ab4f8; }
.s-follow_up  { background:rgba(245,200,66,0.15);color:var(--yellow); }
.s-converted  { background:rgba(0,168,120,0.15);color:#00A878; }
.s-lost       { background:rgba(206,17,38,0.1);color:#ff6b7a; }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin/">Admin</a> › <span>Manage Agents</span></nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);">👔 Manage Sales Agents</h1>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <?php foreach ($errors as $err): ?>
  <div class="flash flash-error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <div class="filter-tabs" style="margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="?tab=agents" class="filter-tab <?= $tab==='agents'?'active':'' ?>">👔 Agents (<?= count($agents) ?>)</a>
    <a href="?tab=leads"  class="filter-tab <?= $tab==='leads' ?'active':'' ?>">📋 Lead Assignments</a>
    <a href="?tab=create" class="filter-tab <?= $tab==='create'?'active':'' ?>">+ New Agent</a>
  </div>

  <?php if ($tab === 'create'): ?>
  <!-- Create agent form -->
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:24px;max-width:520px;">
    <h3 style="margin-top:0;font-size:1.05rem;">+ New Sales Agent</h3>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="create_agent">
      <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Name *</label><input type="text" name="name" required></div>
        <div class="form-group"><label>Email *</label><input type="email" name="email" required></div>
      </div>
      <div class="form-group"><label>Password * (min 8 chars)</label><input type="text" name="password" required minlength="8"></div>
      <div class="form-group">
        <label>Referral Code <span style="font-size:11px;color:var(--muted);">(optional — auto-generated from name if blank)</span></label>
        <input type="text" name="ref_code" placeholder="e.g. john237" pattern="[a-zA-Z0-9_-]+" maxlength="30">
        <p class="field-hint">Shared URL: 237biz.net/r/{code}</p>
      </div>
      <button type="submit" class="btn btn-primary">Create Agent</button>
    </form>
  </div>

  <?php elseif ($tab === 'leads'): ?>
  <!-- Lead assignment -->
  <!-- Assign form -->
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:18px 20px;margin-bottom:20px;max-width:600px;">
    <h3 style="margin-top:0;font-size:14px;">Assign a Lead to an Agent</h3>
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="assign_lead">
      <div class="form-group" style="flex:1;min-width:160px;">
        <label>Agent</label>
        <select name="agent_id" required style="width:100%;padding:8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:7px;color:var(--white);font-family:inherit;">
          <option value="">— Select agent —</option>
          <?php foreach ($agents as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:2;min-width:200px;">
        <label>Listing / Business</label>
        <select name="listing_id" required style="width:100%;padding:8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:7px;color:var(--white);font-family:inherit;">
          <option value="">— Select listing —</option>
          <?php foreach ($listings as $ls): ?><option value="<?= $ls['id'] ?>"><?= e($ls['title']) ?> (<?= e($ls['city']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary" style="height:38px;">Assign</button>
    </form>
  </div>

  <!-- Filter by agent -->
  <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
    <a href="?tab=leads" style="font-size:12px;padding:4px 12px;border-radius:6px;text-decoration:none;<?= !$agentFilter?'background:rgba(0,168,120,0.2);color:#00A878;':'color:var(--muted);border:1px solid rgba(255,255,255,0.1);' ?>">All agents</a>
    <?php foreach ($agents as $a): ?>
    <a href="?tab=leads&agent_id=<?= $a['id'] ?>" style="font-size:12px;padding:4px 12px;border-radius:6px;text-decoration:none;<?= $agentFilter===$a['id']?'background:rgba(0,168,120,0.2);color:#00A878;':'color:var(--muted);border:1px solid rgba(255,255,255,0.1);' ?>"><?= e($a['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Assignments list -->
  <?php if (empty($assignments)): ?>
  <p style="color:var(--muted);">No assignments yet.</p>
  <?php else: ?>
  <?php foreach ($assignments as $ass): ?>
  <div class="assign-row">
    <div style="flex:1;min-width:180px;">
      <div style="font-weight:600;font-size:14px;"><?= e($ass['listing_title']) ?></div>
      <div style="font-size:12px;color:var(--muted);"><?= e($ass['city']) ?> · assigned to <strong><?= e($ass['agent_name']) ?></strong></div>
      <?php if ($ass['next_followup']): ?><div style="font-size:11.5px;color:var(--yellow);">📅 Follow up: <?= date('d M Y',strtotime($ass['next_followup'])) ?></div><?php endif; ?>
    </div>
    <span class="status-pill s-<?= $ass['status'] ?>"><?= ucfirst(str_replace('_',' ',$ass['status'])) ?></span>
    <a href="<?= SITE_URL ?>/listing/<?= e($ass['listing_slug']) ?>" target="_blank" style="font-size:12px;color:var(--green);">View →</a>
    <!-- Inline update -->
    <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="update_assignment">
      <input type="hidden" name="assignment_id" value="<?= $ass['id'] ?>">
      <select name="status" style="padding:5px 8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-size:12.5px;font-family:inherit;">
        <?php foreach (['assigned','contacted','follow_up','converted','lost'] as $s): ?>
        <option value="<?= $s ?>" <?= $ass['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="next_followup" value="<?= e($ass['next_followup'] ?? '') ?>" style="padding:5px 8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-size:12.5px;">
      <button type="submit" style="background:rgba(0,168,120,0.2);border:1px solid rgba(0,168,120,0.4);color:#00A878;border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;font-family:inherit;">Save</button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php else: ?>
  <!-- Agents list -->
  <?php if (empty($agents)): ?>
  <div style="text-align:center;padding:40px;color:var(--muted);">
    <div style="font-size:2.5rem;margin-bottom:12px;">👔</div>
    <p>No agents yet. <a href="?tab=create" style="color:var(--green);">Create your first agent →</a></p>
  </div>
  <?php else: ?>
  <?php foreach ($agents as $a): ?>
  <div class="agent-card">
    <div style="flex:1;min-width:160px;">
      <div style="font-weight:700;font-size:15px;"><?= e($a['name']) ?></div>
      <div style="font-size:12.5px;color:var(--muted);"><?= e($a['email']) ?></div>
      <?php if ($a['ref_code']): ?>
      <div style="margin-top:6px;">
        <span class="ref-code">/r/<?= e($a['ref_code']) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <div class="agent-stat">
      <div class="val"><?= (int)$a['clicks'] ?></div>
      <div class="lbl">Clicks</div>
    </div>
    <div class="agent-stat">
      <div class="val"><?= (int)$a['unique_clicks'] ?></div>
      <div class="lbl">Unique</div>
    </div>
    <div class="agent-stat">
      <div class="val"><?= (int)$a['total_leads'] ?></div>
      <div class="lbl">Leads</div>
    </div>
    <div class="agent-stat">
      <div class="val" style="color:#00A878;"><?= (int)$a['converted'] ?></div>
      <div class="lbl">Converted</div>
    </div>
    <div class="agent-stat">
      <div class="val" style="font-size:1rem;color:#fcd116;"><?= formatXaf((int)$a['approved_xaf']) ?></div>
      <div class="lbl">Approved</div>
    </div>
    <div class="agent-stat">
      <div class="val" style="font-size:1rem;color:#8ab4f8;"><?= formatXaf((int)$a['paid_xaf']) ?></div>
      <div class="lbl">Paid out</div>
    </div>
    <a href="?tab=leads&agent_id=<?= $a['id'] ?>" style="font-size:12px;color:var(--green);">Leads →</a>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php endif; ?>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
