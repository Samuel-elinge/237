<?php
/**
 * admin/marketplace-fulfilment.php — Marketplace fulfilment queue
 *
 * Shows all orders in READY_FOR_FULFILMENT or IN_FULFILMENT state.
 * Admin can:
 *  - Create fulfilment tasks for an order
 *  - Assign tasks to staff (users with admin/staff role)
 *  - Update task state
 *  - Advance order to IN_FULFILMENT / COMPLETED
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pdo = db();
function mpFmt(int $xaf): string { return number_format($xaf) . ' FCFA'; }

/* ── POST: fulfilment actions ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action']   ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);
    $taskId  = (int)($_POST['task_id']  ?? 0);

    if ($action === 'create_task' && $orderId) {
        $title   = trim($_POST['task_title']   ?? '');
        $desc    = trim($_POST['task_desc']    ?? '');
        $assign  = (int)($_POST['assignee_id'] ?? 0) ?: null;
        $slaDays = (int)(getSettingCached('mp_fulfilment_sla_days') ?: 5);
        $due     = date('Y-m-d', strtotime("+{$slaDays} days"));
        if ($title) {
            $pdo->prepare("
                INSERT INTO mp_fulfilment_tasks
                  (order_id, title, description, fulfilment_state, assignee_id, due_date, created_by_user_id)
                VALUES (?, ?, ?, 'ASSIGNED', ?, ?, ?)
            ")->execute([$orderId, $title, $desc ?: null, $assign, $due, $_SESSION['user_id']]);

            // Advance order to IN_FULFILMENT if still READY
            $pdo->prepare("UPDATE marketplace_orders SET order_state='IN_FULFILMENT', updated_at=NOW() WHERE id=? AND order_state='READY_FOR_FULFILMENT'")
                ->execute([$orderId]);

            flash('success', 'Task created.');
        }

    } elseif ($action === 'update_task' && $taskId) {
        $state   = $_POST['task_state'] ?? '';
        $note    = trim($_POST['task_note'] ?? '');
        $validStates = ['UNASSIGNED','ASSIGNED','IN_PROGRESS','WAITING_ON_CUSTOMER','COMPLETED','BLOCKED','CANCELLED'];
        if (in_array($state, $validStates)) {
            $pdo->prepare("UPDATE mp_fulfilment_tasks SET fulfilment_state=?, notes=?, updated_at=NOW() WHERE id=?")
                ->execute([$state, $note ?: null, $taskId]);

            // If all tasks for this order are completed, advance order
            if ($state === 'COMPLETED') {
                $stTasks = $pdo->prepare("SELECT COUNT(*) FROM mp_fulfilment_tasks WHERE order_id=(SELECT order_id FROM mp_fulfilment_tasks WHERE id=?) AND fulfilment_state NOT IN ('COMPLETED','CANCELLED')");
                $stTasks->execute([$taskId]);
                if ((int)$stTasks->fetchColumn() === 0) {
                    // All done — but admin still manually marks order complete from orders page
                    // Just set a flag for the UI
                }
            }
            flash('success', 'Task updated.');
        }

    } elseif ($action === 'assign_task' && $taskId) {
        $assign = (int)($_POST['assignee_id'] ?? 0) ?: null;
        $pdo->prepare("UPDATE mp_fulfilment_tasks SET assignee_id=?, fulfilment_state=IF(fulfilment_state='UNASSIGNED','ASSIGNED',fulfilment_state), updated_at=NOW() WHERE id=?")
            ->execute([$assign, $taskId]);
        flash('success', 'Task assigned.');
    }

    redirect(SITE_URL . '/admin/marketplace-fulfilment' . ($orderId ? '?order_id='.$orderId : ''));
}

/* ── Load staff list for assignment ──────────────────────────── */
$staff = $pdo->query("SELECT id, name FROM users WHERE role IN ('admin','staff','operator') ORDER BY name")->fetchAll();

/* ── Load active fulfilment orders ───────────────────────────── */
$filterOrderId = (int)($_GET['order_id'] ?? 0);

$stOrders = $pdo->prepare("
    SELECT o.id, o.ref, o.order_state, o.due_now_amount,
           o.created_at, o.payment_confirmed_at,
           l.title AS listing_title,
           u_partner.name AS partner_name,
           COUNT(ft.id) AS task_total,
           SUM(ft.fulfilment_state='COMPLETED') AS task_done,
           SUM(ft.fulfilment_state='BLOCKED') AS task_blocked,
           MIN(ft.due_date) AS earliest_due
    FROM marketplace_orders o
    JOIN listings l ON l.id = o.listing_id
    JOIN partner_profiles pp ON pp.id = o.partner_profile_id
    JOIN users u_partner ON u_partner.id = pp.user_id
    LEFT JOIN mp_fulfilment_tasks ft ON ft.order_id = o.id AND ft.fulfilment_state NOT IN ('CANCELLED')
    WHERE o.order_state IN ('READY_FOR_FULFILMENT','IN_FULFILMENT')
    GROUP BY o.id
    ORDER BY earliest_due ASC, o.payment_confirmed_at ASC
");
$stOrders->execute();
$fulfilOrders = $stOrders->fetchAll();

/* ── Load tasks for selected order ───────────────────────────── */
$selectedOrder = null;
$orderTasks    = [];
if ($filterOrderId) {
    $stSO = $pdo->prepare("
        SELECT o.*, l.title AS listing_title,
               u_partner.name AS partner_name, u_partner.email AS partner_email,
               u_owner.name AS owner_name
        FROM marketplace_orders o
        JOIN listings l ON l.id=o.listing_id
        JOIN partner_profiles pp ON pp.id=o.partner_profile_id
        JOIN users u_partner ON u_partner.id=pp.user_id
        JOIN users u_owner ON u_owner.id=l.user_id
        WHERE o.id=? AND o.order_state IN ('READY_FOR_FULFILMENT','IN_FULFILMENT','COMPLETED')
        LIMIT 1
    ");
    $stSO->execute([$filterOrderId]);
    $selectedOrder = $stSO->fetch();

    if ($selectedOrder) {
        $stT = $pdo->prepare("SELECT ft.*, u.name AS assignee_name FROM mp_fulfilment_tasks ft LEFT JOIN users u ON u.id=ft.assignee_id WHERE ft.order_id=? ORDER BY ft.created_at");
        $stT->execute([$filterOrderId]);
        $orderTasks = $stT->fetchAll();
    }
}

$taskStateBadge = fn($s) => match($s) {
    'COMPLETED'          => 'success',
    'IN_PROGRESS'        => 'primary',
    'ASSIGNED'           => 'info',
    'WAITING_ON_CUSTOMER'=> 'warning',
    'BLOCKED'            => 'danger',
    'CANCELLED'          => 'secondary',
    default              => 'secondary',
};

$slaColor = function(string $due): string {
    $days = (strtotime($due) - time()) / 86400;
    if ($days < 0) return 'danger';
    if ($days < 2) return 'warning';
    return 'success';
};

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="admin-wrap">
  <div class="admin-header">
    <h1>⚙️ Fulfilment Queue</h1>
    <div class="admin-header-actions">
      <a href="<?= SITE_URL ?>/admin/marketplace-orders" class="btn btn-sm btn-outline-primary">📋 Orders</a>
      <a href="<?= SITE_URL ?>/admin/marketplace-catalogue" class="btn btn-sm btn-outline-secondary">🛒 Catalogue</a>
    </div>
  </div>

  <?php showFlash(); ?>

  <div class="row g-4">

    <!-- Left: queue list -->
    <div class="col-lg-4">
      <h5 class="fw-bold mb-3">Active Orders (<?= count($fulfilOrders) ?>)</h5>
      <?php if (!$fulfilOrders): ?>
      <div class="card p-4 text-center text-muted">
        <div style="font-size:2rem;">✅</div>
        <div class="mt-2">No orders awaiting fulfilment.</div>
      </div>
      <?php endif; ?>
      <?php foreach ($fulfilOrders as $fo):
        $isSelected = $fo['id'] == $filterOrderId;
        $pct = $fo['task_total'] > 0 ? round($fo['task_done'] / $fo['task_total'] * 100) : 0;
      ?>
      <a href="?order_id=<?= $fo['id'] ?>"
        class="card shadow-sm mb-3 text-decoration-none <?= $isSelected ? 'border-primary' : '' ?>">
        <div class="card-body py-3">
          <div class="d-flex justify-content-between align-items-start mb-1">
            <code class="fw-bold"><?= e($fo['ref']) ?></code>
            <span class="badge bg-<?= $fo['order_state'] === 'READY_FOR_FULFILMENT' ? 'warning text-dark' : 'primary' ?> small">
              <?= $fo['order_state'] === 'READY_FOR_FULFILMENT' ? 'Ready' : 'In Progress' ?>
            </span>
          </div>
          <div class="text-muted small"><?= e($fo['listing_title']) ?></div>
          <div class="text-muted small mb-2"><?= e($fo['partner_name']) ?></div>
          <?php if ($fo['task_total'] > 0): ?>
          <div class="progress" style="height:4px;">
            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
          </div>
          <div class="d-flex justify-content-between mt-1" style="font-size:0.7rem;">
            <span class="text-muted"><?= (int)$fo['task_done'] ?>/<?= (int)$fo['task_total'] ?> tasks</span>
            <?php if ($fo['task_blocked']): ?><span class="text-danger">⚠ <?= (int)$fo['task_blocked'] ?> blocked</span><?php endif; ?>
          </div>
          <?php else: ?>
          <div class="text-warning small">⚠ No tasks created yet</div>
          <?php endif; ?>
          <?php if ($fo['earliest_due']): ?>
          <div class="mt-1 small text-<?= $slaColor($fo['earliest_due']) ?>">
            Due: <?= date('d M', strtotime($fo['earliest_due'])) ?>
          </div>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Right: task detail -->
    <div class="col-lg-8">
      <?php if ($selectedOrder): ?>

      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <strong><?= e($selectedOrder['ref']) ?></strong> —
            <?= e($selectedOrder['listing_title']) ?>
          </div>
          <span class="badge bg-success"><?= mpFmt((int)$selectedOrder['due_now_amount']) ?></span>
        </div>
        <div class="card-body small text-muted row g-2">
          <div class="col-sm-4">Partner: <strong class="text-dark"><?= e($selectedOrder['partner_name']) ?></strong></div>
          <div class="col-sm-4">Owner: <strong class="text-dark"><?= e($selectedOrder['owner_name']) ?></strong></div>
          <?php if ($selectedOrder['payment_confirmed_at']): ?>
          <div class="col-sm-4">Paid: <?= date('d M Y', strtotime($selectedOrder['payment_confirmed_at'])) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tasks -->
      <?php if ($orderTasks): ?>
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold small">Tasks (<?= count($orderTasks) ?>)</div>
        <?php foreach ($orderTasks as $task): ?>
        <div class="card-body border-bottom py-3">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <div class="fw-semibold"><?= e($task['title']) ?></div>
              <?php if ($task['description']): ?><div class="text-muted small"><?= e($task['description']) ?></div><?php endif; ?>
              <?php if ($task['assignee_name']): ?><div class="small">👤 <?= e($task['assignee_name']) ?></div><?php endif; ?>
              <?php if ($task['due_date']): ?>
              <div class="small text-<?= $slaColor($task['due_date']) ?>">Due: <?= date('d M Y', strtotime($task['due_date'])) ?></div>
              <?php endif; ?>
              <?php if ($task['notes']): ?><div class="small text-muted mt-1">📝 <?= e($task['notes']) ?></div><?php endif; ?>
            </div>
            <span class="badge bg-<?= $taskStateBadge($task['fulfilment_state']) ?>"><?= e($task['fulfilment_state']) ?></span>
          </div>

          <?php if ($task['fulfilment_state'] !== 'COMPLETED' && $task['fulfilment_state'] !== 'CANCELLED'): ?>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <!-- Assign -->
            <form method="POST" class="d-flex gap-1">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="assign_task">
              <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
              <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
              <select name="assignee_id" class="form-select form-select-sm" style="width:150px;">
                <option value="">Assign to…</option>
                <?php foreach ($staff as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $task['assignee_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary">Assign</button>
            </form>

            <!-- Update state -->
            <form method="POST" class="d-flex gap-1">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_task">
              <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
              <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
              <input type="text" name="task_note" class="form-control form-control-sm" style="width:140px;" placeholder="Note">
              <select name="task_state" class="form-select form-select-sm" style="width:150px;">
                <?php foreach (['ASSIGNED','IN_PROGRESS','WAITING_ON_CUSTOMER','BLOCKED','COMPLETED','CANCELLED'] as $ts): ?>
                <option value="<?= $ts ?>" <?= $task['fulfilment_state']===$ts?'selected':'' ?>><?= $ts ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-primary">Update</button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Create new task -->
      <?php if (!in_array($selectedOrder['order_state'], ['COMPLETED','CANCELLED'])): ?>
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold small">➕ Add Fulfilment Task</div>
        <div class="card-body">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_task">
            <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
            <div class="row g-2">
              <div class="col-md-6">
                <input type="text" name="task_title" class="form-control form-control-sm"
                  placeholder="Task title (e.g. Set up domain DNS)" required>
              </div>
              <div class="col-md-6">
                <select name="assignee_id" class="form-select form-select-sm">
                  <option value="">Assign to (optional)</option>
                  <?php foreach ($staff as $s): ?>
                  <option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <input type="text" name="task_desc" class="form-control form-control-sm"
                  placeholder="Description (optional)">
              </div>
              <div class="col-12">
                <button class="btn btn-primary btn-sm">Create Task</button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Quick task templates -->
      <div class="card shadow-sm mb-4">
        <div class="card-header small fw-bold">⚡ Quick Task Templates</div>
        <div class="card-body d-flex flex-wrap gap-2">
          <?php
          $templates = [
              'Set up domain DNS' => 'Configure domain records to point to hosting server',
              'Create hosting account' => 'Create cPanel/hosting account for the business',
              'Install WordPress / website' => 'Install and configure the website platform',
              'Set up email accounts' => 'Create and configure business email mailboxes',
              'Configure SSL certificate' => 'Install and verify HTTPS certificate',
              'Handover & training' => 'Walk client through their new service and hand over access',
          ];
          foreach ($templates as $title => $desc):
          ?>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_task">
            <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
            <input type="hidden" name="task_title" value="<?= e($title) ?>">
            <input type="hidden" name="task_desc" value="<?= e($desc) ?>">
            <button class="btn btn-sm btn-outline-secondary"><?= e($title) ?></button>
          </form>
          <?php endforeach; ?>
        </div>
      </div>

      <?php endif; ?>

      <!-- Mark order complete button (if all tasks done) -->
      <?php
      $allDone = $orderTasks && count(array_filter($orderTasks, fn($t) => !in_array($t['fulfilment_state'], ['COMPLETED','CANCELLED']))) === 0;
      if ($allDone && $selectedOrder['order_state'] !== 'COMPLETED'):
      ?>
      <div class="alert alert-success d-flex justify-content-between align-items-center">
        <span>✅ All tasks completed. Ready to close the order.</span>
        <form method="POST" action="<?= SITE_URL ?>/admin/marketplace-orders">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="mark_complete">
          <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
          <button class="btn btn-success btn-sm">Mark Order Complete</button>
        </form>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="card p-5 text-center text-muted">
        <div style="font-size:3rem;">👈</div>
        <div class="mt-2">Select an order from the queue to manage its tasks.</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
