<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

// ── Helpers ───────────────────────────────────────────────
function generateRefCode(string $name): string {
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    $base = substr($base, 0, 10) ?: 'user';
    $code = $base; $i = 1;
    while (true) {
        $chk = db()->prepare("SELECT id FROM referral_links WHERE code=?");
        $chk->execute([$code]);
        if (!$chk->fetch()) break;
        $code = $base . $i++;
    }
    return $code;
}

function roleBadgeStyle(string $role): string {
    $map = [
        'admin'       => 'background:rgba(0,168,120,0.15);color:#00A878;border:1px solid rgba(0,168,120,0.3);',
        'sales_staff' => 'background:rgba(138,180,248,0.15);color:#8ab4f8;border:1px solid rgba(138,180,248,0.3);',
        'creator'     => 'background:rgba(245,130,245,0.15);color:#e07be0;border:1px solid rgba(245,130,245,0.3);',
    ];
    return $map[$role] ?? 'background:rgba(255,255,255,0.07);color:rgba(255,255,255,0.6);border:1px solid rgba(255,255,255,0.1);';
}

function roleLabel(string $role): string {
    $map = [
        'admin'          => '🔑 Admin',
        'sales_staff'    => '👔 Agent',
        'creator'        => '🎬 Creator',
        'growth_partner' => '🤝 Partner',
    ];
    return $map[$role] ?? '👤 User';
}

// ── CSV Export ────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $users = db()->query("
        SELECT u.id, u.name, u.email, u.phone, u.role, u.verified, u.created_at,
               (SELECT title FROM listings WHERE user_id=u.id AND status='approved' LIMIT 1) AS listing,
               (SELECT COUNT(*) FROM listings WHERE user_id=u.id) AS listing_count
        FROM users u ORDER BY u.created_at DESC
    ")->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="237biz-users-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Name','Email','Phone','Role','Verified','Business','Listings','Joined']);
    foreach ($users as $u) {
        fputcsv($out, [$u['id'],$u['name'],$u['email'],$u['phone']??'',$u['role'],$u['verified']?'Yes':'No',$u['listing']??'',$u['listing_count'],date('d M Y',strtotime($u['created_at']))]);
    }
    fclose($out);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$errors = [];

// ── HANDLE ACTIONS ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $userId = (int)($_POST['user_id'] ?? 0);

    // Reset password
    if ($action === 'reset_password' && $userId) {
        $newPass = trim($_POST['new_password'] ?? '');
        if (strlen($newPass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } else {
            try {
                $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 10]);
                db()->prepare("UPDATE users SET password=?, login_attempts=0, locked_until=NULL WHERE id=?")
                     ->execute([$hash, $userId]);
                $st = db()->prepare("SELECT email, name FROM users WHERE id=?");
                $st->execute([$userId]);
                $u = $st->fetch();
                if ($u) {
                    sendMail($u['email'],
                        t('Your 237Biz password has been reset','Votre mot de passe 237Biz a été réinitialisé'),
                        '<h2 style="color:#fff;font-family:Georgia,serif;">🔑 Password Reset</h2>
                         <p style="color:rgba(255,255,255,0.7);">Hi '.e($u['name']).',</p>
                         <p style="color:rgba(255,255,255,0.7);">An administrator has reset your password. Your new password is:</p>
                         <div style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:1rem;margin:1rem 0;font-size:1.2rem;font-family:monospace;color:#F5C842;text-align:center;">'.e($newPass).'</div>
                         <p style="color:rgba(255,255,255,0.7);">Please log in and change your password immediately.</p>
                         <a href="'.SITE_URL.'/login" style="display:inline-block;margin:1rem 0;background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;">Sign In →</a>'
                    );
                }
                flash('success', 'Password reset and user notified by email.');
            } catch (Exception $e) { flash('error', 'Could not reset password.'); }
        }
    }

    // Toggle verified
    elseif ($action === 'toggle_verified' && $userId) {
        try { db()->prepare("UPDATE users SET verified = !verified WHERE id=?")->execute([$userId]); } catch (Exception $e) {}
        flash('success', 'Verification status updated.');
    }

    // Change role
    elseif ($action === 'change_role' && $userId) {
        $newRole = $_POST['new_role'] ?? 'user';
        if (!in_array($newRole, ['user','admin','sales_staff','creator','growth_partner'])) $newRole = 'user';
        if ($userId != $_SESSION['user_id']) {
            try { db()->prepare("UPDATE users SET role=? WHERE id=?")->execute([$newRole, $userId]); } catch (Exception $e) {}
            flash('success', 'Role updated to ' . $newRole . '.');
        }
    }

    // Unlock account
    elseif ($action === 'unlock' && $userId) {
        try { db()->prepare("UPDATE users SET login_attempts=0, locked_until=NULL WHERE id=?")->execute([$userId]); } catch (Exception $e) {}
        flash('success', 'Account unlocked.');
    }

    // Delete user
    elseif ($action === 'delete' && $userId) {
        try { db()->prepare("DELETE FROM users WHERE id=? AND role != 'admin'")->execute([$userId]); } catch (Exception $e) {}
        flash('success', 'User deleted.');
    }

    // Assign listing
    elseif ($action === 'assign_listing') {
        $listingId = (int)($_POST['listing_id'] ?? 0);
        if ($userId && $listingId) {
            try { db()->prepare("UPDATE listings SET user_id=? WHERE id=?")->execute([$userId, $listingId]); } catch (Exception $e) {}
            flash('success', 'Listing assigned.');
        }
    }

    // ── CREATE USER ───────────────────────────────────────
    elseif ($action === 'create_user') {
        $name        = trim($_POST['name']            ?? '');
        $email       = strtolower(trim($_POST['email'] ?? ''));
        $phone       = trim($_POST['phone']           ?? '');
        $role        = in_array($_POST['role'] ?? '', ['user','admin','sales_staff','creator']) ? $_POST['role'] : 'user';
        $refCode     = trim($_POST['ref_code']        ?? '');
        $sendWelcome = !empty($_POST['send_welcome']);
        $customPass  = trim($_POST['custom_password'] ?? '');
        $tempPass    = $customPass ?: ucfirst(substr(str_shuffle('abcdefghjkmnpqrstuvwxyz'), 0, 5)) . rand(100,999) . '!';

        if (!$name)  $errors[] = 'Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
        if ($customPass && strlen($customPass) < 8) $errors[] = 'Password must be at least 8 characters.';

        if (!$errors) {
            $chk = db()->prepare("SELECT id FROM users WHERE email=?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $errors[] = 'Email already registered.';
            } else {
                $hash = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 10]);
                db()->prepare("INSERT INTO users (name,email,password,phone,role,verified) VALUES (?,?,?,?,?,1)")
                     ->execute([$name, $email, $hash, $phone ?: null, $role]);
                $newUserId = (int)db()->lastInsertId();

                // Assign listing if selected
                $assignListingId = (int)($_POST['assign_listing_id'] ?? 0);
                if ($assignListingId) {
                    db()->prepare("UPDATE listings SET user_id=? WHERE id=?")->execute([$newUserId, $assignListingId]);
                }

                // ── Role-specific setup ──────────────────────────────
                if ($role === 'sales_staff') {
                    // Create referral link
                    $code = $refCode ?: generateRefCode($name);
                    try {
                        db()->prepare("INSERT INTO referral_links (user_id,programme_id,code) VALUES (?,1,?)")
                             ->execute([$newUserId, $code]);
                        $refNote = " Referral link: 237biz.net/r/{$code}";
                    } catch (Exception $e) { $refNote = ''; }
                } elseif ($role === 'creator') {
                    // Create pending creator profile
                    try {
                        db()->prepare("INSERT INTO creator_profiles (user_id,status) VALUES (?,'pending')")
                             ->execute([$newUserId]);
                        $refNote = ' Creator profile created (pending approval).';
                    } catch (Exception $e) { $refNote = ''; }
                } else {
                    $refNote = '';
                }
                // ────────────────────────────────────────────────────

                // Welcome email
                $roleLabel = ['sales_staff'=>'Sales Agent','creator'=>'Creator','admin'=>'Admin','user'=>'User'][$role] ?? 'User';
                if ($role === 'sales_staff') {
                    $portalLink = SITE_URL . '/agent/dashboard';
                } elseif ($role === 'creator') {
                    $portalLink = SITE_URL . '/creator/dashboard';
                } else {
                    $portalLink = SITE_URL . '/dashboard';
                }

                if ($sendWelcome) {
                    sendMail($email,
                        'Welcome to 237Biz — Your account details',
                        '<h2 style="color:#fff;font-family:Georgia,serif;">Welcome to 237Biz!</h2>
                         <p style="color:rgba(255,255,255,0.7);">Hi ' . e($name) . ',</p>
                         <p style="color:rgba(255,255,255,0.7);">Your <strong>' . $roleLabel . '</strong> account has been created. Here are your login details:</p>
                         <div style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:1.25rem;margin:1rem 0;">
                           <div style="color:rgba(255,255,255,0.5);font-size:0.82rem;margin-bottom:0.35rem;">Email</div>
                           <div style="color:#fff;font-family:monospace;margin-bottom:0.75rem;">' . e($email) . '</div>
                           <div style="color:rgba(255,255,255,0.5);font-size:0.82rem;margin-bottom:0.35rem;">Password</div>
                           <div style="color:#F5C842;font-family:monospace;font-size:1.1rem;">' . e($tempPass) . '</div>
                         </div>
                         <a href="' . $portalLink . '" style="display:inline-block;margin:1rem 0;background:#00A878;color:#fff;padding:0.85rem 2rem;border-radius:5px;text-decoration:none;font-weight:500;">Go to My Dashboard →</a>'
                    );
                    flash('success', "{$name} created as {$roleLabel} and welcome email sent.{$refNote}");
                } else {
                    flash('success', "{$name} created. Temp password: {$tempPass}{$refNote}");
                }
            }
        }
        if ($errors) flash('error', implode(' ', $errors));
    }

    redirect(SITE_URL . '/admin/users.php');
}

// ── FETCH USERS ───────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$where  = ['1=1'];
$params = [];
if ($search) { $where[] = "(u.name LIKE ? OR u.email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($roleFilter) { $where[] = "u.role=?"; $params[] = $roleFilter; }
$whereStr = implode(' AND ', $where);

// Main users query — try with referral_links subquery, fall back without it if table missing
$users = [];
try {
    $st = db()->prepare("
        SELECT u.*,
               (SELECT COUNT(*) FROM listings WHERE user_id=u.id) AS listing_count,
               IFNULL((SELECT COUNT(*) FROM service_orders WHERE user_id=u.id), 0) AS order_count,
               (SELECT code FROM referral_links WHERE user_id=u.id LIMIT 1) AS ref_code
        FROM users u WHERE {$whereStr}
        ORDER BY u.created_at DESC LIMIT 200
    ");
    $st->execute($params);
    $users = $st->fetchAll();
} catch (Exception $e) {
    // referral_links or service_orders may not exist — retry without them
    try {
        $st = db()->prepare("
            SELECT u.*, 0 AS listing_count, 0 AS order_count, NULL AS ref_code
            FROM users u WHERE {$whereStr}
            ORDER BY u.created_at DESC LIMIT 200
        ");
        $st->execute($params);
        $users = $st->fetchAll();
        // Add listing count safely
        foreach ($users as &$row) {
            $lc = db()->prepare("SELECT COUNT(*) FROM listings WHERE user_id=?");
            $lc->execute([$row['id']]);
            $row['listing_count'] = (int)$lc->fetchColumn();
        }
        unset($row);
    } catch (Exception $e2) { $users = []; }
}

$unownedListings = [];
try {
    $unownedListings = db()->query("
        SELECT l.id, l.title, loc.name_en AS loc_en
        FROM listings l
        JOIN locations loc ON loc.id = l.location_id
        WHERE l.status = 'approved'
        ORDER BY l.user_id IS NOT NULL ASC, l.title ASC
    ")->fetchAll();
} catch (Exception $e) { $unownedListings = []; }

try {
    $totalUsers   = (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalAdmins  = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    $totalAgents  = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='sales_staff'")->fetchColumn();
    $totalCreators= (int)db()->query("SELECT COUNT(*) FROM users WHERE role='creator'")->fetchColumn();
    $unverified   = (int)db()->query("SELECT COUNT(*) FROM users WHERE verified=0")->fetchColumn();
    $locked       = (int)db()->query("SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()")->fetchColumn();
} catch (Exception $e) {
    $totalUsers = $totalAdmins = $totalAgents = $totalCreators = $unverified = $locked = 0;
}

$pageTitle = 'Users — Admin 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">👤 User Management</h1>
  </div>
</div>

<section class="page-section">
<div class="container">

  <!-- Admin Nav -->
  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/"                        class="filter-tab">📋 Listings</a>
    <a href="<?= SITE_URL ?>/admin/users.php"               class="filter-tab active">👤 Users</a>
    <a href="<?= SITE_URL ?>/admin/orders.php"              class="filter-tab">📦 Orders</a>
    <a href="<?= SITE_URL ?>/admin/leads.php"               class="filter-tab">🌐 Website Leads</a>
    <a href="<?= SITE_URL ?>/admin/enquiries.php"           class="filter-tab">📬 Enquiries</a>
    <a href="<?= SITE_URL ?>/admin/claims.php"              class="filter-tab">🏢 Claims</a>
    <a href="<?= SITE_URL ?>/admin/reviews.php"             class="filter-tab">⭐ Reviews</a>
    <a href="<?= SITE_URL ?>/admin/categories.php"          class="filter-tab">📂 Categories</a>
    <a href="<?= SITE_URL ?>/admin/locations.php"           class="filter-tab">📍 Locations</a>
    <a href="<?= SITE_URL ?>/admin/subscribers.php"         class="filter-tab">📬 Newsletter</a>
    <a href="<?= SITE_URL ?>/admin/promos.php"              class="filter-tab">🎁 Promo Codes</a>
    <a href="<?= SITE_URL ?>/admin/packages.php"            class="filter-tab">💳 Packages</a>
    <a href="<?= SITE_URL ?>/admin/seo-content.php"         class="filter-tab">📝 SEO Content</a>
    <a href="<?= SITE_URL ?>/admin/emails.php"              class="filter-tab">✉️ Emails</a>
    <a href="<?= SITE_URL ?>/admin/automation/"             class="filter-tab">🤖 Automation</a>
    <a href="<?= SITE_URL ?>/admin/dashboard.php"           class="filter-tab">📊 Admin Dashboard</a>
    <a href="<?= SITE_URL ?>/admin/health-check-overview.php" class="filter-tab">🩺 Health Check</a>
    <a href="<?= SITE_URL ?>/admin/manage-agents.php"       class="filter-tab">👔 Agents</a>
    <a href="<?= SITE_URL ?>/admin/manage-creators.php"     class="filter-tab">🎬 Creators</a>
    <a href="<?= SITE_URL ?>/admin/manage-campaigns.php"    class="filter-tab">📣 Campaigns</a>
    <a href="<?= SITE_URL ?>/admin/manage-referrals.php"    class="filter-tab">💰 Referrals</a>
    <a href="<?= SITE_URL ?>/dashboard"                     class="filter-tab">← Dashboard</a>
  </div>

  <!-- Stats -->
  <div class="dash-stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:2rem;">
    <div class="dash-stat"><strong><?= $totalUsers ?></strong><span>Total</span></div>
    <div class="dash-stat"><strong><?= $totalAdmins ?></strong><span>Admins</span></div>
    <div class="dash-stat"><strong style="color:#8ab4f8;"><?= $totalAgents ?></strong><span>Agents</span></div>
    <div class="dash-stat"><strong style="color:#e07be0;"><?= $totalCreators ?></strong><span>Creators</span></div>
    <div class="dash-stat"><strong style="color:var(--yellow);"><?= $unverified ?></strong><span>Unverified</span></div>
    <div class="dash-stat"><strong style="color:#ff6b6b;"><?= $locked ?></strong><span>Locked</span></div>
  </div>

  <!-- ADD USER FORM -->
  <div class="listing-widget" style="margin-bottom:2rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
      <h4 style="margin-bottom:0;">+ Add New User</h4>
      <button onclick="document.getElementById('add-user-form').style.display=document.getElementById('add-user-form').style.display==='none'?'block':'none'"
              class="btn btn-primary btn-sm">+ Add User</button>
    </div>
    <div id="add-user-form" style="display:none;">
      <form method="POST" id="create-user-form">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="create_user">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
          <div class="form-group" style="margin-bottom:0;">
            <label>Full Name *</label>
            <input type="text" name="name" required placeholder="e.g. John Doe">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Email Address *</label>
            <input type="email" name="email" required placeholder="john@example.com">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Phone</label>
            <input type="tel" name="phone" placeholder="+237 6XX XXX XXX">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Role</label>
            <select name="role" id="role-select" onchange="handleRoleChange(this.value)">
              <option value="user">👤 User</option>
              <option value="sales_staff">👔 Sales Agent</option>
              <option value="creator">🎬 Creator</option>
              <option value="growth_partner">🤝 Growth Partner</option>
              <option value="admin">🔑 Admin</option>
            </select>
          </div>
          <!-- Referral code — shows for sales_staff only -->
          <div class="form-group" style="margin-bottom:0;display:none;" id="ref-code-wrap">
            <label>Referral Code <small style="color:var(--muted-2);font-weight:300;">(auto-generated if blank)</small></label>
            <input type="text" name="ref_code" placeholder="e.g. john237" pattern="[a-zA-Z0-9_-]+" maxlength="30">
            <small style="color:var(--muted-2);font-size:0.72rem;">Shared URL: 237biz.net/r/{code}</small>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Custom Password <small style="color:var(--muted-2);font-weight:300;">(leave blank to auto-generate)</small></label>
            <input type="text" name="custom_password" placeholder="Min. 8 characters" style="font-family:monospace;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Assign to Listing <small style="color:var(--muted-2);font-weight:300;">(optional)</small></label>
            <select name="assign_listing_id">
              <option value="">— No assignment —</option>
              <?php foreach ($unownedListings as $ul): ?>
              <option value="<?= $ul['id'] ?>"><?= e($ul['title']) ?> (<?= e($ul['loc_en']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <small style="color:var(--muted-2);font-size:0.72rem;">The user will become the owner and can edit this listing from their dashboard.</small>
          </div>
        </div>
        <!-- Role info banner -->
        <div id="role-info" style="display:none;margin-top:1rem;padding:10px 14px;border-radius:8px;font-size:13px;"></div>
        <div style="margin-top:1rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;color:var(--muted);cursor:pointer;">
            <input type="checkbox" name="send_welcome" checked style="accent-color:var(--green);">
            Send welcome email with login details
          </label>
          <button type="submit" class="btn btn-primary">Create User</button>
        </div>
        <p style="font-size:0.75rem;color:var(--muted-2);margin-top:0.75rem;">
          💡 If you don't send a welcome email, the generated password will be shown in the success message.
        </p>
      </form>
    </div>
  </div>

  <!-- Search + Role Filter -->
  <form action="" method="GET" style="display:flex;gap:0.5rem;margin-bottom:1.5rem;flex-wrap:wrap;">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name or email..."
           style="flex:1;min-width:200px;background:var(--card);border:1px solid var(--border);border-radius:6px;color:var(--white);font-size:0.9rem;padding:0.7rem 1rem;">
    <select name="role" style="padding:0.7rem 1rem;background:var(--card);border:1px solid var(--border);border-radius:6px;color:var(--white);font-size:0.9rem;">
      <option value="" <?= !$roleFilter?'selected':'' ?>>All roles</option>
      <option value="user"        <?= $roleFilter==='user'       ?'selected':'' ?>>👤 Users</option>
      <option value="sales_staff" <?= $roleFilter==='sales_staff'?'selected':'' ?>>👔 Agents</option>
      <option value="creator"     <?= $roleFilter==='creator'    ?'selected':'' ?>>🎬 Creators</option>
      <option value="admin"       <?= $roleFilter==='admin'      ?'selected':'' ?>>🔑 Admins</option>
    </select>
    <button type="submit" class="btn btn-primary">Search</button>
    <?php if ($search || $roleFilter): ?><a href="<?= SITE_URL ?>/admin/users.php" class="btn btn-outline">Clear</a><?php endif; ?>
    <a href="<?= SITE_URL ?>/admin/users.php?export=csv" class="btn btn-outline" style="margin-left:auto;">⬇️ CSV</a>
  </form>

  <!-- Users Table -->
  <div class="listing-widget">
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Role</th>
            <th>Status</th>
            <th>Listings</th>
            <th>Orders</th>
            <th>Joined</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <div style="color:var(--white);font-weight:500;font-size:0.875rem;"><?= e($u['name']) ?></div>
              <div style="font-size:0.75rem;color:var(--muted-2);"><?= e($u['email']) ?></div>
              <?php if ($u['phone']): ?><div style="font-size:0.72rem;color:var(--muted-2);"><?= e($u['phone']) ?></div><?php endif; ?>
              <?php if ($u['ref_code']): ?><div style="font-size:0.7rem;color:#00A878;margin-top:2px;">🔗 /r/<?= e($u['ref_code']) ?></div><?php endif; ?>
              <?php if ($u['locked_until'] && strtotime($u['locked_until']) > time()): ?>
              <span style="font-size:0.68rem;color:#ff6b6b;">🔒 Locked until <?= date('H:i d M', strtotime($u['locked_until'])) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <span style="display:inline-block;border-radius:99px;padding:3px 11px;font-size:11.5px;font-weight:700;<?= roleBadgeStyle($u['role']) ?>">
                <?= roleLabel($u['role']) ?>
              </span>
            </td>
            <td>
              <span class="badge <?= $u['verified'] ? 'badge-approved' : 'badge-rejected' ?>">
                <?= $u['verified'] ? '✓ Verified' : '✗ Unverified' ?>
              </span>
              <?php if (($u['login_attempts'] ?? 0) > 0): ?>
              <div style="font-size:0.68rem;color:var(--yellow);margin-top:0.2rem;"><?= $u['login_attempts'] ?> failed</div>
              <?php endif; ?>
            </td>
            <td style="text-align:center;color:var(--white);"><?= $u['listing_count'] ?></td>
            <td style="text-align:center;color:var(--white);"><?= $u['order_count'] ?></td>
            <td style="font-size:0.78rem;color:var(--muted-2);"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td>
              <div style="display:flex;flex-direction:column;gap:0.35rem;min-width:160px;">

                <!-- Reset Password -->
                <button onclick="openResetModal(<?= $u['id'] ?>, '<?= e($u['name']) ?>', '<?= e($u['email']) ?>')"
                        class="btn btn-primary btn-sm">🔑 Reset Password</button>

                <!-- Login as (non-admin only) -->
                <?php if ($u['role'] !== 'admin'): ?>
                <form method="POST" action="<?= SITE_URL ?>/admin/login-as.php" style="display:block;">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button type="submit" style="width:100%;background:rgba(138,180,248,0.12);border:1px solid rgba(138,180,248,0.3);color:#8ab4f8;border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;font-family:inherit;">👁 Login as</button>
                </form>
                <?php endif; ?>

                <!-- Unlock if locked -->
                <?php if ($u['locked_until'] && strtotime($u['locked_until']) > time()): ?>
                <form method="POST" style="display:block;">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button name="action" value="unlock" class="btn btn-outline btn-sm" style="width:100%;">🔓 Unlock</button>
                </form>
                <?php endif; ?>

                <!-- Toggle verified -->
                <form method="POST" style="display:block;">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button name="action" value="toggle_verified" class="btn btn-outline btn-sm" style="width:100%;">
                    <?= $u['verified'] ? '✗ Unverify' : '✓ Verify' ?>
                  </button>
                </form>

                <!-- Change role (not self) -->
                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                <form method="POST" style="display:flex;gap:4px;">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="action" value="change_role">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <select name="new_role" style="flex:1;padding:4px 6px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-size:12px;">
                    <?php foreach (['user'=>'👤 User','sales_staff'=>'👔 Agent','creator'=>'🎬 Creator','growth_partner'=>'🤝 Partner','admin'=>'🔑 Admin'] as $rv=>$rl): ?>
                    <option value="<?= $rv ?>" <?= $u['role']===$rv?'selected':'' ?>><?= $rl ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);color:rgba(255,255,255,0.7);border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;font-family:inherit;">Save</button>
                </form>

                <!-- Assign listing -->
                <button onclick="openAssignModal(<?= $u['id'] ?>, '<?= e(addslashes($u['name'])) ?>')"
                        class="btn btn-outline btn-sm" style="width:100%;color:var(--yellow);border-color:rgba(245,200,66,0.3);">
                  📋 Assign Listing
                </button>

                <!-- Delete -->
                <form method="POST" style="display:block;" onsubmit="return confirm('Delete <?= e($u['name']) ?>? This cannot be undone.')">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button name="action" value="delete" class="btn btn-sm" style="width:100%;background:rgba(230,50,50,0.1);color:#ff6b6b;border:1px solid rgba(230,50,50,0.3);">✗ Delete</button>
                </form>
                <?php endif; ?>

              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</section>

<!-- ASSIGN LISTING MODAL -->
<div id="assign-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:500;align-items:center;justify-content:center;">
  <div style="background:#122B1C;border:1px solid var(--border);border-radius:16px;padding:2rem;width:100%;max-width:460px;margin:1rem;">
    <h3 style="font-family:'Fraunces',serif;font-weight:700;margin-bottom:0.35rem;">📋 Assign Listing</h3>
    <p id="assign-user-info" style="font-size:0.83rem;color:var(--muted);margin-bottom:1.5rem;"></p>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="assign_listing">
      <input type="hidden" name="user_id" id="assign-user-id">
      <div class="form-group">
        <label>Select Listing</label>
        <select name="listing_id" required>
          <option value="">— Choose a listing —</option>
          <?php foreach ($unownedListings as $ul): ?>
          <option value="<?= $ul['id'] ?>"><?= e($ul['title']) ?> (<?= e($ul['loc_en']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:0.75rem;">
        <button type="submit" class="btn btn-primary" style="flex:1;">Assign Listing</button>
        <button type="button" onclick="document.getElementById('assign-modal').style.display='none'" class="btn btn-outline" style="flex:1;">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div id="reset-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:500;align-items:center;justify-content:center;">
  <div style="background:#122B1C;border:1px solid var(--border);border-radius:16px;padding:2rem;width:100%;max-width:420px;margin:1rem;">
    <h3 style="font-family:'Fraunces',serif;font-weight:700;margin-bottom:0.35rem;">🔑 Reset Password</h3>
    <p id="modal-user-info" style="font-size:0.83rem;color:var(--muted);margin-bottom:1.5rem;"></p>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="modal-user-id">
      <div class="form-group">
        <label>New Password *</label>
        <input type="text" name="new_password" id="modal-new-password" required
               placeholder="Min. 8 characters" style="font-family:monospace;"
               value="<?= substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#'), 0, 12) ?>">
      </div>
      <p style="font-size:0.75rem;color:var(--muted-2);margin-bottom:1rem;">📧 The user will be emailed their new password automatically.</p>
      <div style="display:flex;gap:0.75rem;">
        <button type="submit" class="btn btn-primary" style="flex:1;">Reset Password</button>
        <button type="button" onclick="document.getElementById('reset-modal').style.display='none'" class="btn btn-outline" style="flex:1;">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function handleRoleChange(role) {
  var refWrap  = document.getElementById('ref-code-wrap');
  var infoBox  = document.getElementById('role-info');
  refWrap.style.display = (role === 'sales_staff') ? 'block' : 'none';

  var msgs = {
    sales_staff: { text: '👔 A referral link (/r/code) will be created automatically. The agent can access their portal at /agent/dashboard.', bg: 'rgba(138,180,248,0.08)', color: '#8ab4f8', border: 'rgba(138,180,248,0.25)' },
    creator:     { text: '🎬 A creator profile (pending approval) will be created. Once approved by admin, they get a referral link at /creator/dashboard.', bg: 'rgba(245,130,245,0.08)', color: '#e07be0', border: 'rgba(245,130,245,0.25)' },
    admin:       { text: '🔑 This user will have full admin access to the control panel.', bg: 'rgba(206,17,38,0.08)', color: '#ff6b7a', border: 'rgba(206,17,38,0.25)' },
  };

  if (msgs[role]) {
    var m = msgs[role];
    infoBox.style.display = 'block';
    infoBox.style.background = m.bg;
    infoBox.style.color = m.color;
    infoBox.style.border = '1px solid ' + m.border;
    infoBox.style.borderRadius = '8px';
    infoBox.textContent = m.text;
  } else {
    infoBox.style.display = 'none';
  }
}

function openAssignModal(id, name) {
  document.getElementById('assign-user-id').value = id;
  document.getElementById('assign-user-info').textContent = 'Assigning a listing to: ' + name;
  document.getElementById('assign-modal').style.display = 'flex';
}
document.getElementById('assign-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});

function openResetModal(id, name, email) {
  document.getElementById('modal-user-id').value = id;
  document.getElementById('modal-user-info').textContent = name + ' (' + email + ')';
  document.getElementById('reset-modal').style.display = 'flex';
}
document.getElementById('reset-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>