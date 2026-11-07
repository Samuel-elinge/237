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
require_once __DIR__ . '/../includes/flash-helpers.php';
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
        $slaDays = (int)($pdo->query("SELECT value FROM site_settings WHERE `key`='mp_fulfilment_sla_days' LIMIT 1")->fetchColumn() ?: 5);
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
           o.created_at, o.completed_at AS payment_confirmed_at,
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
<link rel="stylesheet" href="<?= SITE_URL ?>/admin/includes/mp-admin.css">

<div class="mp-wrap">

  <!-- Page header -->
  <div class="mp-page-header">
    <div>
      <h1 class="mp-page-title">⚙️ Fulfilment Queue</h1>
      <div class="mp-page-subtitle"><?= count($fulfilOrders) ?> active order<?= count($fulfilOrders) !== 1 ? 's' : '' ?></div>
    </div>
    <div class="mp-nav-links">
      <a href="<?= SITE_URL ?>/admin/marketplace-orders" class="mp-btn primary">📋 Orders</a>
      <a href="<?= SITE_URL ?>/admin/marketplace-catalogue" class="mp-btn secondary">🛒 Catalogue</a>
    </div>
  </div>

  <?php showFlash(); ?>

  <!-- Two-column layout -->
  <div class="mp-fulfilment-grid" style="display:grid;grid-template-columns:320px 1fr;gap:1.25rem;align-items:start;">

    <!-- Left: queue list -->
    <div>
      <?php if (!$fulfilOrders): ?>
      <div class="mp-detail-card" style="text-align:center;padding:2rem;">
        <div style="font-size:2.5rem;">✅</div>
        <div style="color:#6c9e88;margin-top:0.5rem;font-size:0.85rem;">No orders awaiting fulfilment.</div>
      </div>
      <?php endif; ?>

      <?php foreach ($fulfilOrders as $fo):
        $isSelected = $fo['id'] == $filterOrderId;
        $pct = $fo['task_total'] > 0 ? round($fo['task_done'] / $fo['task_total'] * 100) : 0;
        $stateLabel = $fo['order_state'] === 'READY_FOR_FULFILMENT' ? 'Ready' : 'In Progress';
        $stateCol   = $fo['order_state'] === 'READY_FOR_FULFILMENT' ? 'yellow' : 'green';
      ?>
      <a href="?order_id=<?= $fo['id'] ?>" class="mp-queue-card <?= $isSelected ? 'selected' : '' ?>" style="display:block;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.4rem;">
          <span class="mp-ref"><?= e($fo['ref']) ?></span>
          <span class="mp-badge <?= $stateCol ?>"><?= $stateLabel ?></span>
        </div>
        <div style="font-size:0.8rem;color:#c8ddd4;margin-bottom:0.1rem;"><?= e($fo['listing_title']) ?></div>
        <div style="font-size:0.75rem;color:#6c9e88;margin-bottom:0.5rem;"><?= e($fo['partner_name']) ?></div>
        <?php if ($fo['task_total'] > 0): ?>
        <div class="mp-progress"><div class="mp-progress-bar" style="width:<?= $pct ?>%"></div></div>
        <div style="display:flex;justify-content:space-between;font-size:0.7rem;margin-top:0.2rem;">
          <span style="color:#6c9e88;"><?= (int)$fo['task_done'] ?>/<?= (int)$fo['task_total'] ?> tasks</span>
          <?php if ($fo['task_blocked']): ?><span style="color:#e74c3c;">⚠ <?= (int)$fo['task_blocked'] ?> blocked</span><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="font-size:0.75rem;color:#f0c040;">⚠ No tasks yet</div>
        <?php endif; ?>
        <?php if ($fo['earliest_due']): ?>
        <?php $dueCol = $slaColor($fo['earliest_due']); $dueCSS = $dueCol==='danger'?'#e74c3c':($dueCol==='warning'?'#f0c040':'#00A878'); ?>
        <div style="font-size:0.72rem;color:<?= $dueCSS ?>;margin-top:0.3rem;">Due: <?= date('d M', strtotime($fo['earliest_due'])) ?></div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Right: task detail -->
    <div>
      <?php if ($selectedOrder): ?>

      <!-- Order header card -->
      <div class="mp-detail-card" style="margin-bottom:1rem;">
        <div class="mp-detail-card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <span style="display:flex;align-items:center;gap:0.6rem;">
            <span class="mp-ref"><?= e($selectedOrder['ref']) ?></span>
            <span style="color:#9ab8a8;font-weight:400;">— <?= e($selectedOrder['listing_title']) ?></span>
          </span>
          <span style="color:#00A878;font-weight:700;"><?= mpFmt((int)$selectedOrder['due_now_amount']) ?></span>
        </div>
        <div class="mp-detail-card-body">
          <dl class="mp-dl">
            <dt>Partner</dt><dd><?= e($selectedOrder['partner_name']) ?></dd>
            <dt>Owner</dt><dd><?= e($selectedOrder['owner_name']) ?></dd>
            <?php if ($selectedOrder['payment_confirmed_at']): ?>
            <dt>Paid</dt><dd><?= date('d M Y', strtotime($selectedOrder['payment_confirmed_at'])) ?></dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>

      <!-- Tasks list -->
      <?php if ($orderTasks): ?>
      <div class="mp-detail-card" style="margin-bottom:1rem;">
        <div class="mp-detail-card-header">Tasks (<?= count($orderTasks) ?>)</div>
        <div class="mp-detail-card-body" style="padding:0.75rem;">
          <?php foreach ($orderTasks as $task):
            $tsBadge = match($task['fulfilment_state']) {
                'COMPLETED' => 'green', 'IN_PROGRESS' => 'blue', 'ASSIGNED' => 'grey',
                'WAITING_ON_CUSTOMER' => 'yellow', 'BLOCKED' => 'red', default => 'grey'
            };
            $dueCol2 = $task['due_date'] ? $slaColor($task['due_date']) : '';
            $dueCSS2 = $dueCol2==='danger'?'#e74c3c':($dueCol2==='warning'?'#f0c040':'#6c9e88');
          ?>
          <div class="mp-task-row">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;margin-bottom:0.4rem;">
              <div>
                <div style="font-weight:600;color:#e8f4ee;font-size:0.85rem;"><?= e($task['title']) ?></div>
                <?php if ($task['description']): ?><div style="font-size:0.75rem;color:#6c9e88;"><?= e($task['description']) ?></div><?php endif; ?>
                <?php if ($task['assignee_name']): ?><div style="font-size:0.75rem;color:#9ab8a8;">👤 <?= e($task['assignee_name']) ?></div><?php endif; ?>
                <?php if ($task['due_date']): ?><div style="font-size:0.72rem;color:<?= $dueCSS2 ?>;">Due: <?= date('d M Y', strtotime($task['due_date'])) ?></div><?php endif; ?>
                <?php if ($task['notes']): ?><div style="font-size:0.75rem;color:#6c9e88;margin-top:0.2rem;">📝 <?= e($task['notes']) ?></div><?php endif; ?>
              </div>
              <span class="mp-badge <?= $tsBadge ?>" style="white-space:nowrap;"><?= e($task['fulfilment_state']) ?></span>
            </div>

            <?php if ($task['fulfilment_state'] !== 'COMPLETED' && $task['fulfilment_state'] !== 'CANCELLED'): ?>
            <div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-top:0.5rem;">
              <!-- Assign form -->
              <form method="POST" style="display:flex;gap:0.3rem;align-items:center;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign_task">
                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
                <select name="assignee_id" class="form-select form-select-sm" style="width:140px;">
                  <option value="">Assign to…</option>
                  <?php foreach ($staff as $sv): ?>
                  <option value="<?= $sv['id'] ?>" <?= $task['assignee_id'] == $sv['id'] ? 'selected' : '' ?>><?= e($sv['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="mp-btn primary sm">Assign</button>
              </form>
              <!-- Update state form -->
              <form method="POST" style="display:flex;gap:0.3rem;align-items:center;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_task">
                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
                <input type="text" name="task_note" class="form-control form-control-sm" style="width:120px;" placeholder="Note">
                <select name="task_state" class="form-select form-select-sm" style="width:140px;">
                  <?php foreach (['ASSIGNED','IN_PROGRESS','WAITING_ON_CUSTOMER','BLOCKED','COMPLETED','CANCELLED'] as $ts): ?>
                  <option value="<?= $ts ?>" <?= $task['fulfilment_state']===$ts?'selected':'' ?>><?= $ts ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="mp-btn success sm">Update</button>
              </form>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Add task -->
      <?php if (!in_array($selectedOrder['order_state'], ['COMPLETED','CANCELLED'])): ?>
      <div class="mp-action-card success" style="margin-bottom:1rem;">
        <div class="mp-action-header">➕ Add Fulfilment Task</div>
        <div class="mp-action-body">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_task">
            <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.5rem;">
              <input type="text" name="task_title" class="form-control form-control-sm"
                placeholder="Task title (e.g. Set up domain DNS)" required>
              <select name="assignee_id" class="form-select form-select-sm">
                <option value="">Assign to (optional)</option>
                <?php foreach ($staff as $sv): ?>
                <option value="<?= $sv['id'] ?>"><?= e($sv['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="margin-bottom:0.5rem;">
              <input type="text" name="task_desc" class="form-control form-control-sm"
                placeholder="Description (optional)">
            </div>
            <button class="mp-btn success sm">Create Task</button>
          </form>
        </div>
      </div>

      <!-- Quick templates -->
      <div class="mp-detail-card" style="margin-bottom:1rem;">
        <div class="mp-detail-card-header">⚡ Quick Task Templates</div>
        <div class="mp-detail-card-body" style="display:flex;flex-wrap:wrap;gap:0.4rem;">
          <?php
          $templates = [
              'Set up domain DNS'          => 'Configure domain records to point to hosting server',
              'Create hosting account'     => 'Create cPanel/hosting account for the business',
              'Install WordPress / website'=> 'Install and configure the website platform',
              'Set up email accounts'      => 'Create and configure business email mailboxes',
              'Configure SSL certificate'  => 'Install and verify HTTPS certificate',
              'Handover & training'        => 'Walk client through their new service and hand over access',
          ];
          foreach ($templates as $tplTitle => $tplDesc):
          ?>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_task">
            <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
            <input type="hidden" name="task_title" value="<?= e($tplTitle) ?>">
            <input type="hidden" name="task_desc" value="<?= e($tplDesc) ?>">
            <button class="mp-btn secondary sm"><?= e($tplTitle) ?></button>
          </form>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- All done banner -->
      <?php
      $allDone = $orderTasks && count(array_filter($orderTasks, fn($t) => !in_array($t['fulfilment_state'], ['COMPLETED','CANCELLED']))) === 0;
      if ($allDone && $selectedOrder['order_state'] !== 'COMPLETED'):
      ?>
      <div class="mp-action-card success">
        <div class="mp-action-header">✅ All Tasks Complete</div>
        <div class="mp-action-body" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;">
          <span style="font-size:0.82rem;color:#6c9e88;">All tasks are done. Mark the order as complete to close it out.</span>
          <form method="POST" action="<?= SITE_URL ?>/admin/marketplace-orders">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_complete">
            <input type="hidden" name="order_id" value="<?= $selectedOrder['id'] ?>">
            <button class="mp-btn success">Mark Order Complete</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="mp-detail-card" style="text-align:center;padding:3rem;">
        <div style="font-size:3rem;">👈</div>
        <div style="color:#6c9e88;margin-top:0.75rem;font-size:0.85rem;">Select an order from the queue to manage its tasks.</div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /two-column -->

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
