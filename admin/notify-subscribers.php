<?php
/**
 * admin/notify-subscribers.php
 * Notification subscriber management — list, filter, suppress/unsuppress.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notify-helpers.php';
requireAdmin();
$pdo = db();
$u   = currentUser();

// ── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $subId  = (int)($_POST['sub_id'] ?? 0);

    if ($action === 'suppress' && $subId) {
        // Suppress: unsubscribe + add to suppressions
        $row = $pdo->prepare("SELECT email_normalised FROM notification_subscribers WHERE id=?");
        $row->execute([$subId]);
        $sub = $row->fetch();
        if ($sub) {
            notifyUnsubscribe($subId, 'admin_suppress');
            // Ensure suppression row exists (notifyUnsubscribe adds it, but set explicitly)
            $pdo->prepare("UPDATE notification_subscribers SET status='suppressed' WHERE id=?")
                ->execute([$subId]);
            notifyAuditLog('admin', 'suppress_subscriber', 'subscriber', $subId, null, (int)$u['id']);
            flash('success', 'Subscriber suppressed.');
        }
        redirect(SITE_URL . '/admin/notify-subscribers.php?' . http_build_query(['status' => $_POST['back_status'] ?? '', 'q' => $_POST['back_q'] ?? '']));
    }

    if ($action === 'unsuppress' && $subId) {
        $pdo->prepare("UPDATE notification_subscribers SET status='active', updated_at=NOW() WHERE id=? AND status IN ('suppressed','unsubscribed')")
            ->execute([$subId]);
        // Remove suppression record
        $row = $pdo->prepare("SELECT email_normalised FROM notification_subscribers WHERE id=?");
        $row->execute([$subId]);
        $sub = $row->fetch();
        if ($sub) {
            $pdo->prepare("DELETE FROM notification_suppressions WHERE email_normalised=?")
                ->execute([$sub['email_normalised']]);
        }
        notifyAuditLog('admin', 'unsuppress_subscriber', 'subscriber', $subId, null, (int)$u['id']);
        flash('success', 'Subscriber reactivated.');
        redirect(SITE_URL . '/admin/notify-subscribers.php?' . http_build_query(['status' => $_POST['back_status'] ?? '', 'q' => $_POST['back_q'] ?? '']));
    }

    redirect(SITE_URL . '/admin/notify-subscribers.php');
}

// ── Filters ───────────────────────────────────────────────────────────────
$statusFilter = in_array($_GET['status'] ?? '', ['', 'active', 'pending', 'unsubscribed', 'suppressed', 'bounced'])
    ? ($_GET['status'] ?? '') : '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;

// ── Stats ─────────────────────────────────────────────────────────────────
$stats = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM notification_subscribers
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);
$total = array_sum($stats);

// ── Query ─────────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($statusFilter !== '') {
    $where[]  = 'ns.status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $where[]  = '(ns.email_display LIKE ? OR ns.email_normalised LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countRow = $pdo->prepare("SELECT COUNT(*) FROM notification_subscribers ns $whereSQL");
$countRow->execute($params);
$filteredCount = (int)$countRow->fetchColumn();
$pages = max(1, (int)ceil($filteredCount / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = $pdo->prepare("
    SELECT ns.*,
           np.special_offers_enabled,
           np.events_enabled
    FROM notification_subscribers ns
    LEFT JOIN notification_preferences np ON np.subscriber_id = ns.id
    $whereSQL
    ORDER BY ns.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$rows->execute($params);
$subs = $rows->fetchAll();

// ── Render ────────────────────────────────────────────────────────────────
$pageTitle = 'Notification Subscribers — Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('notify-subscribers'); adminNavAssets();

function statusBadge(string $s): string {
    $map = [
        'active'       => 'approved',
        'pending'      => 'pending',
        'unsubscribed' => 'info',
        'suppressed'   => 'rejected',
        'bounced'      => 'rejected',
    ];
    $cls = $map[$s] ?? 'info';
    return '<span class="badge badge-' . $cls . '">' . ucfirst($s) . '</span>';
}
?>
<div class="page-header"><div class="container">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;margin:0">🔔 Notification Subscribers</h1>
    <a href="<?= SITE_URL ?>/admin/notify-campaigns.php" class="btn btn-primary btn-sm">📣 Campaigns →</a>
  </div>
</div></div>

<section class="page-section"><div class="container">

  <!-- Stats row -->
  <div class="dash-stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:2rem;">
    <?php foreach (['active'=>'Active','pending'=>'Pending','unsubscribed'=>'Unsubscribed','suppressed'=>'Suppressed','bounced'=>'Bounced'] as $s => $label): ?>
    <div class="dash-stat">
      <strong><?= number_format($stats[$s] ?? 0) ?></strong>
      <span><?= $label ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <form method="GET" style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search email…"
           class="form-input" style="max-width:260px;flex:1">
    <select name="status" class="form-input" style="width:auto">
      <option value="">All statuses</option>
      <?php foreach (['active','pending','unsubscribed','suppressed','bounced'] as $s): ?>
      <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($q || $statusFilter): ?>
    <a href="<?= SITE_URL ?>/admin/notify-subscribers.php" class="btn btn-sm" style="background:var(--card);border:1px solid var(--border)">Clear</a>
    <?php endif; ?>
    <span style="margin-left:auto;color:var(--muted);font-size:0.85rem"><?= number_format($filteredCount) ?> result<?= $filteredCount !== 1 ? 's' : '' ?></span>
  </form>

  <?php if ($flash = getFlash('success')): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= e($flash) ?></div>
  <?php endif; ?>

  <!-- Table -->
  <div class="listing-widget"><div style="overflow-x:auto">
    <table class="data-table">
      <thead>
        <tr>
          <th>Email</th>
          <th>Status</th>
          <th>Interests</th>
          <th>Lang</th>
          <th>Source</th>
          <th>Joined</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($subs)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:2rem">No subscribers found.</td></tr>
        <?php endif; ?>
        <?php foreach ($subs as $sub): ?>
        <tr>
          <td style="color:var(--white);word-break:break-all"><?= e($sub['email_display']) ?></td>
          <td><?= statusBadge($sub['status']) ?></td>
          <td style="font-size:0.82rem">
            <?php
            $interests = [];
            if ($sub['special_offers_enabled'] ?? false) $interests[] = '🏷️ Offers';
            if ($sub['events_enabled'] ?? false)         $interests[] = '📅 Events';
            echo $interests ? implode(', ', $interests) : '<span style="color:var(--muted)">—</span>';
            ?>
          </td>
          <td style="font-size:0.82rem;color:var(--muted)"><?= e(strtoupper($sub['language'] ?? 'en')) ?></td>
          <td style="font-size:0.78rem;color:var(--muted-2)"><?= e($sub['source'] ?? '—') ?></td>
          <td style="font-size:0.78rem;color:var(--muted-2);white-space:nowrap"><?= date('d M Y', strtotime($sub['created_at'])) ?></td>
          <td>
            <?php if ($sub['status'] === 'active' || $sub['status'] === 'pending'): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Suppress this subscriber? They will be permanently blocked from re-subscribing until unsuppressed.')">
              <?= csrf() ?>
              <input type="hidden" name="action"      value="suppress">
              <input type="hidden" name="sub_id"      value="<?= (int)$sub['id'] ?>">
              <input type="hidden" name="back_status" value="<?= e($statusFilter) ?>">
              <input type="hidden" name="back_q"      value="<?= e($q) ?>">
              <button type="submit" class="btn btn-sm" style="background:none;border:1px solid #ff6b6b;color:#ff6b6b;font-size:0.78rem">
                🚫 Suppress
              </button>
            </form>
            <?php elseif ($sub['status'] === 'suppressed' || $sub['status'] === 'unsubscribed'): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Reactivate this subscriber?')">
              <?= csrf() ?>
              <input type="hidden" name="action"      value="unsuppress">
              <input type="hidden" name="sub_id"      value="<?= (int)$sub['id'] ?>">
              <input type="hidden" name="back_status" value="<?= e($statusFilter) ?>">
              <input type="hidden" name="back_q"      value="<?= e($q) ?>">
              <button type="submit" class="btn btn-sm" style="background:none;border:1px solid #00A878;color:#00A878;font-size:0.78rem">
                ✓ Reactivate
              </button>
            </form>
            <?php else: ?>
            <span style="color:var(--muted-2);font-size:0.78rem">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:0.5rem;justify-content:center;margin-top:1.5rem;flex-wrap:wrap">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a href="?<?= http_build_query(['q' => $q, 'status' => $statusFilter, 'p' => $i]) ?>"
       class="btn btn-sm"
       style="<?= $i === $page ? 'background:#00A878;color:#fff;border-color:#00A878' : 'background:var(--card);border:1px solid var(--border)' ?>">
      <?= $i ?>
    </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
