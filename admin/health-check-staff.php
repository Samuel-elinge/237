<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_staff') {
    verifyCsrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($name === '') $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

    if (!$errors) {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $errors[] = 'A user with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            $pdo->prepare("INSERT INTO users (name, email, password, role, verified) VALUES (?, ?, ?, 'sales_staff', 1)")
                ->execute([$name, $email, $hash]);
            flash('success', 'Sales staff account created for ' . $name . '.');
            redirect(SITE_URL . '/admin/health-check-staff.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_staff') {
    verifyCsrf();
    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($userId) {
        // Revert to plain 'user' rather than deleting — keeps assessment history intact
        $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ? AND role = 'sales_staff'")->execute([$userId]);
        flash('success', 'Staff access revoked.');
    }
    redirect(SITE_URL . '/admin/health-check-staff.php');
}

$staffStmt = $pdo->query("SELECT id, name, email, role, created_at,
        (SELECT COUNT(*) FROM health_assessments ha WHERE ha.agent_id = users.id) AS assessment_count
    FROM users WHERE role IN ('sales_staff','admin') ORDER BY role DESC, name ASC");
$staff = $staffStmt->fetchAll();

$pageTitle = 'Sales Staff — Admin';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">👥 Sales Staff — Digital Health Check</h1>
</div></div>

<section class="page-section"><div class="container">
    <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/agent-health-checks.php" class="filter-tab">📋 Assessments</a>
      <a href="<?= SITE_URL ?>/admin/health-check-overview.php" class="filter-tab">📊 Market Overview</a>
      <a href="<?= SITE_URL ?>/admin/health-check-questions.php" class="filter-tab">⚙️ Questions</a>
      <a href="<?= SITE_URL ?>/admin/health-check-staff.php" class="filter-tab active">👥 Sales Staff</a>
    </div>

    <?php if ($errors): ?>
      <div class="badge badge-rejected" style="display:block;padding:0.75rem 1rem;margin-bottom:1rem;">
        <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #eee;border-radius:12px;padding:1.25rem;margin-bottom:1.5rem;max-width:520px;">
      <h3 style="margin-top:0;">Add Sales Staff</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="add_staff">
        <div style="display:flex;gap:10px;margin-bottom:10px;">
          <div style="flex:1;"><label style="font-size:0.8rem;color:#666;display:block;margin-bottom:4px;">Name</label>
            <input type="text" name="name" required style="width:100%;padding:0.6rem;border:1px solid #ddd;border-radius:6px;"></div>
          <div style="flex:1;"><label style="font-size:0.8rem;color:#666;display:block;margin-bottom:4px;">Email</label>
            <input type="email" name="email" required style="width:100%;padding:0.6rem;border:1px solid #ddd;border-radius:6px;"></div>
        </div>
        <div style="margin-bottom:12px;"><label style="font-size:0.8rem;color:#666;display:block;margin-bottom:4px;">Temporary Password (min 8 chars)</label>
          <input type="text" name="password" required minlength="8" style="width:100%;padding:0.6rem;border:1px solid #ddd;border-radius:6px;"></div>
        <button type="submit" class="btn btn-primary">Create Staff Account</button>
      </form>
    </div>

    <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden;">
      <thead><tr style="background:#f7f7f7;">
        <th style="padding:0.7rem;text-align:left;font-size:0.8rem;color:#666;">Name</th>
        <th style="padding:0.7rem;text-align:left;font-size:0.8rem;color:#666;">Email</th>
        <th style="padding:0.7rem;text-align:left;font-size:0.8rem;color:#666;">Role</th>
        <th style="padding:0.7rem;text-align:left;font-size:0.8rem;color:#666;">Assessments</th>
        <th style="padding:0.7rem;text-align:left;font-size:0.8rem;color:#666;">Joined</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($staff as $s): ?>
        <tr style="border-top:1px solid #eee;">
          <td style="padding:0.7rem;"><?= htmlspecialchars($s['name']) ?></td>
          <td style="padding:0.7rem;"><?= htmlspecialchars($s['email']) ?></td>
          <td style="padding:0.7rem;"><span class="badge <?= $s['role']==='admin'?'badge-approved':'badge-pending' ?>"><?= ucfirst(str_replace('_',' ',$s['role'])) ?></span></td>
          <td style="padding:0.7rem;"><?= (int) $s['assessment_count'] ?></td>
          <td style="padding:0.7rem;"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
          <td style="padding:0.7rem;">
            <?php if ($s['role'] === 'sales_staff'): ?>
            <form method="post" onsubmit="return confirm('Revoke staff access for <?= htmlspecialchars($s['name']) ?>? Their past assessments stay on record.');">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="revoke_staff">
              <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm">Revoke</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$staff): ?><tr><td colspan="6" style="padding:1.5rem;text-align:center;color:#999;">No sales staff yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
