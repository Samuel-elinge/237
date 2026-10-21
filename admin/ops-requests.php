<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ops-admin-helpers.php';
requireAnyAdmin();

$u = currentUser();

// ── Super Admin: review (approve/reject) a request ───────────────────────────
$msg = '';
if (isSuperAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $reqId  = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['decision'] === 'approve' ? 'approved' : 'rejected';
    $note   = trim($_POST['review_note'] ?? '');

    try {
        $req = db()->prepare("SELECT * FROM ops_admin_requests WHERE id=? AND status='pending'")->execute([$reqId])
               ? db()->prepare("SELECT * FROM ops_admin_requests WHERE id=? AND status='pending'")->execute([$reqId]) && false
               : null;
        // Fetch fresh
        $st = db()->prepare("SELECT * FROM ops_admin_requests WHERE id=? AND status='pending'");
        $st->execute([$reqId]);
        $req = $st->fetch();

        if ($req) {
            db()->prepare("
                UPDATE ops_admin_requests
                SET status=?, reviewed_by=?, reviewed_at=NOW(), review_note=?
                WHERE id=?
            ")->execute([$action, $u['id'], $note ?: null, $reqId]);

            opsAuditLog(
                "ops_request.$action",
                'ops_admin_request',
                $reqId,
                "Action: {$req['action']} on {$req['record_type']} #{$req['record_id']}. Note: $note"
            );

            flash('success', "Request #{$reqId} {$action}.");
        } else {
            flash('error', 'Request not found or already reviewed.');
        }
    } catch (Exception $e) {
        flash('error', 'Error processing request: ' . $e->getMessage());
    }
    redirect(SITE_URL . '/admin/ops-requests.php');
}

// ── Ops Admin: submit a new request ──────────────────────────────────────────
if (isOpsAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    verifyCsrf();
    requirePermission('request.submit');
    $recordType = trim($_POST['record_type'] ?? '');
    $recordId   = (int)($_POST['record_id'] ?? 0);
    $action     = trim($_POST['action'] ?? '');
    $reason     = trim($_POST['reason'] ?? '');

    if (!$recordType || !$recordId || !$action || strlen($reason) < 10) {
        flash('error', 'All fields are required (reason min 10 chars).');
    } else {
        $id = submitOpsRequest($recordType, $recordId, $action, $reason);
        if ($id) {
            opsAuditLog('ops_request.submitted', $recordType, $recordId, "Requested: $action. Reason: $reason");
            flash('success', "Request #{$id} submitted for Super Admin approval.");
        } else {
            flash('error', 'Could not submit request.');
        }
    }
    redirect(SITE_URL . '/admin/ops-requests.php');
}

// ── Load requests ─────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'pending';
$allowedStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($statusFilter, $allowedStatuses)) $statusFilter = 'pending';

try {
    $where = $statusFilter !== 'all' ? "WHERE r.status='{$statusFilter}'" : '';
    if (isOpsAdmin()) {
        $where = $statusFilter !== 'all'
            ? "WHERE r.status='{$statusFilter}' AND r.requester_id={$u['id']}"
            : "WHERE r.requester_id={$u['id']}";
    }
    $requests = db()->query("
        SELECT r.*, u.name AS requester_name,
               a.name AS reviewer_name
        FROM ops_admin_requests r
        JOIN users u ON u.id = r.requester_id
        LEFT JOIN users a ON a.id = r.reviewed_by
        $where
        ORDER BY r.created_at DESC
        LIMIT 100
    ")->fetchAll();
} catch (Exception $e) { $requests = []; }

// Review modal request
$reviewReq = null;
if (isSuperAdmin() && isset($_GET['review'])) {
    $rrId = (int)$_GET['review'];
    try {
        $st = db()->prepare("SELECT r.*, u.name AS requester_name FROM ops_admin_requests r JOIN users u ON u.id=r.requester_id WHERE r.id=? AND r.status='pending'");
        $st->execute([$rrId]);
        $reviewReq = $st->fetch();
    } catch (Exception $e) {}
}

require_once __DIR__ . '/../includes/admin-header.php';
?>
<style>
.ops-section{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.5rem;margin-bottom:1.5rem}
table.ops-table{width:100%;border-collapse:collapse;font-size:0.83rem}
table.ops-table th{text-align:left;padding:0.5rem 0.75rem;color:var(--muted);border-bottom:1px solid var(--border);font-weight:500}
table.ops-table td{padding:0.6rem 0.75rem;border-bottom:1px solid rgba(255,255,255,0.04);vertical-align:top}
.badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600}
.badge-pending{background:rgba(245,200,66,0.15);color:#F5C842;border:1px solid rgba(245,200,66,0.3)}
.badge-approved{background:rgba(0,168,120,0.15);color:#00A878;border:1px solid rgba(0,168,120,0.3)}
.badge-rejected{background:rgba(255,107,107,0.15);color:#ff6b6b;border:1px solid rgba(255,107,107,0.3)}
.tab-bar{display:flex;gap:0.5rem;margin-bottom:1.5rem}
.tab-bar a{padding:0.45rem 1rem;border-radius:6px;text-decoration:none;font-size:0.85rem;border:1px solid var(--border);color:var(--muted)}
.tab-bar a.active{background:#00A878;color:#fff;border-color:#00A878}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;z-index:1000;padding:1rem}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:2rem;max-width:520px;width:100%}
</style>

<div style="max-width:1100px;margin:0 auto;padding:1.5rem 1rem">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
    <div>
      <a href="<?= SITE_URL ?>/admin/ops-dashboard.php" style="color:var(--muted);font-size:0.83rem;text-decoration:none">← Dashboard</a>
      <h1 style="font-size:1.3rem;font-weight:700;margin:0.25rem 0 0">
        <?= isSuperAdmin() ? '📋 Approval Requests' : '📋 My Requests' ?>
      </h1>
    </div>
    <?php if (isOpsAdmin()): ?>
    <button onclick="document.getElementById('submit-modal').style.display='flex'"
            style="padding:0.6rem 1.2rem;background:#00A878;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;font-weight:600">
      + New Request
    </button>
    <?php endif; ?>
  </div>

  <div class="tab-bar">
    <?php foreach (['pending'=>'⏳ Pending','approved'=>'✓ Approved','rejected'=>'✗ Rejected','all'=>'All'] as $s=>$l): ?>
    <a href="?status=<?= $s ?>" class="<?= $statusFilter===$s?'active':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>

  <div class="ops-section">
    <?php if (empty($requests)): ?>
    <p style="color:var(--muted);text-align:center;padding:2rem">No requests found.</p>
    <?php else: ?>
    <table class="ops-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Requester</th>
          <th>Action</th>
          <th>Record</th>
          <th>Reason</th>
          <th>Status</th>
          <th>Submitted</th>
          <th>Reviewer</th>
          <?php if (isSuperAdmin()): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $req): ?>
        <tr>
          <td style="color:var(--muted)">#<?= $req['id'] ?></td>
          <td><?= e($req['requester_name']) ?></td>
          <td><code style="font-size:11px"><?= e($req['action']) ?></code></td>
          <td style="color:var(--muted)"><?= e($req['record_type']) ?> #<?= $req['record_id'] ?></td>
          <td><?= e(mb_strimwidth($req['reason'], 0, 80, '…')) ?></td>
          <td><span class="badge badge-<?= $req['status'] ?>"><?= ucfirst($req['status']) ?></span></td>
          <td style="color:var(--muted);white-space:nowrap"><?= date('d M Y H:i', strtotime($req['created_at'])) ?></td>
          <td style="color:var(--muted)"><?= $req['reviewer_name'] ? e($req['reviewer_name']) : '—' ?></td>
          <?php if (isSuperAdmin() && $req['status']==='pending'): ?>
          <td><a href="?review=<?= $req['id'] ?>&status=<?= $statusFilter ?>" style="color:#00A878;font-size:0.8rem">Review</a></td>
          <?php elseif (isSuperAdmin()): ?>
          <td></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<!-- Review modal (Super Admin) -->
<?php if ($reviewReq): ?>
<div class="modal-overlay" id="review-modal">
  <div class="modal-box">
    <h2 style="margin:0 0 1rem;font-size:1.1rem">Review Request #<?= $reviewReq['id'] ?></h2>
    <table style="width:100%;font-size:0.85rem;margin-bottom:1rem">
      <tr><td style="color:var(--muted);padding:0.3rem 0;width:35%">Requester</td><td><?= e($reviewReq['requester_name']) ?></td></tr>
      <tr><td style="color:var(--muted);padding:0.3rem 0">Action</td><td><code><?= e($reviewReq['action']) ?></code></td></tr>
      <tr><td style="color:var(--muted);padding:0.3rem 0">Record</td><td><?= e($reviewReq['record_type']) ?> #<?= $reviewReq['record_id'] ?></td></tr>
      <tr><td style="color:var(--muted);padding:0.3rem 0;vertical-align:top">Reason</td><td><?= nl2br(e($reviewReq['reason'])) ?></td></tr>
      <tr><td style="color:var(--muted);padding:0.3rem 0">Submitted</td><td><?= date('d M Y H:i', strtotime($reviewReq['created_at'])) ?></td></tr>
    </table>
    <form method="POST" action="">
      <?= csrf() ?>
      <input type="hidden" name="request_id" value="<?= $reviewReq['id'] ?>">
      <label style="display:block;color:var(--muted);font-size:0.83rem;margin-bottom:0.4rem">Review note (optional)</label>
      <textarea name="review_note" rows="3" style="width:100%;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--white);padding:0.6rem;font-size:0.85rem;box-sizing:border-box"></textarea>
      <div style="display:flex;gap:0.75rem;margin-top:1rem">
        <button type="submit" name="decision" value="approve"
                style="flex:1;padding:0.7rem;background:#00A878;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600">
          ✓ Approve
        </button>
        <button type="submit" name="decision" value="reject"
                style="flex:1;padding:0.7rem;background:#ff6b6b;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600">
          ✗ Reject
        </button>
        <a href="?status=<?= $statusFilter ?>" style="padding:0.7rem 1rem;background:var(--card);border:1px solid var(--border);border-radius:6px;color:var(--white);text-decoration:none;font-size:0.85rem">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Submit new request modal (Ops Admin) -->
<?php if (isOpsAdmin()): ?>
<div class="modal-overlay" id="submit-modal" style="display:none">
  <div class="modal-box">
    <h2 style="margin:0 0 1rem;font-size:1.1rem">Submit Restricted-Action Request</h2>
    <form method="POST" action="">
      <?= csrf() ?>
      <input type="hidden" name="submit_request" value="1">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem">
        <div>
          <label style="display:block;color:var(--muted);font-size:0.8rem;margin-bottom:0.3rem">Record type</label>
          <select name="record_type" required style="width:100%;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--white);padding:0.55rem;font-size:0.85rem">
            <option value="">Select…</option>
            <option value="user">User</option>
            <option value="listing">Listing</option>
            <option value="invoice">Invoice</option>
            <option value="commission">Commission</option>
            <option value="subscription">Subscription</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div>
          <label style="display:block;color:var(--muted);font-size:0.8rem;margin-bottom:0.3rem">Record ID</label>
          <input type="number" name="record_id" min="1" required style="width:100%;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--white);padding:0.55rem;font-size:0.85rem;box-sizing:border-box">
        </div>
      </div>
      <div style="margin-bottom:0.75rem">
        <label style="display:block;color:var(--muted);font-size:0.8rem;margin-bottom:0.3rem">Requested action</label>
        <select name="action" required style="width:100%;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--white);padding:0.55rem;font-size:0.85rem">
          <option value="">Select…</option>
          <option value="delete_record">Delete record</option>
          <option value="transfer_ownership">Transfer ownership</option>
          <option value="override_payment_status">Override payment status</option>
          <option value="adjust_commission">Adjust commission</option>
          <option value="issue_refund">Issue refund</option>
          <option value="modify_invoice">Modify invoice</option>
          <option value="other">Other (describe in reason)</option>
        </select>
      </div>
      <div style="margin-bottom:1rem">
        <label style="display:block;color:var(--muted);font-size:0.8rem;margin-bottom:0.3rem">Reason (required)</label>
        <textarea name="reason" rows="4" required minlength="10" placeholder="Explain why this action is needed…"
                  style="width:100%;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--white);padding:0.6rem;font-size:0.85rem;box-sizing:border-box"></textarea>
      </div>
      <div style="display:flex;gap:0.75rem">
        <button type="submit" style="flex:1;padding:0.7rem;background:#00A878;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600">Submit Request</button>
        <button type="button" onclick="document.getElementById('submit-modal').style.display='none'"
                style="padding:0.7rem 1rem;background:var(--card);border:1px solid var(--border);border-radius:6px;color:var(--white);cursor:pointer;font-size:0.85rem">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
