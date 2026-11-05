<?php
/**
 * Admin — Acquisition Management
 * Stage B §11
 * Super Admin / Ops Admin view: all prospects, authorisation requests,
 * assignment requests; approve / reject workflow.
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';

requirePermission('operations_admin');   // admin and operations_admin both pass

$userId = $_SESSION['user_id'] ?? null;
$pdo    = db();

// ── POST: approve / reject authorisation or assignment request ─────────────────
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flashError = 'Invalid request token.';
    } else {
        $action     = $_POST['action']      ?? '';
        $subjectId  = (int)($_POST['subject_id'] ?? 0);
        $reason     = trim($_POST['reason'] ?? '');

        if ($action === 'approve_auth' && $subjectId > 0) {
            // Load the authorisation row
            $row = $pdo->prepare("SELECT * FROM business_owner_authorisations WHERE id=? AND status='pending'");
            $row->execute([$subjectId]);
            $auth = $row->fetch(PDO::FETCH_ASSOC);

            if ($auth) {
                $pdo->prepare("
                    UPDATE business_owner_authorisations
                    SET status='approved', responded_at=NOW(), updated_at=NOW()
                    WHERE id=?
                ")->execute([$subjectId]);

                // Auto-advance prospect status to awaiting_assignment
                $pdo->prepare("
                    UPDATE business_prospects
                    SET status='awaiting_assignment', updated_at=NOW()
                    WHERE business_id=? AND partner_id=? AND status IN('awaiting_authorisation','interested','new','contacted')
                ")->execute([$auth['business_id'], $auth['partner_id']]);

                // Audit
                $pdo->prepare("
                    INSERT INTO acquisition_activity_logs
                        (event_type, business_id, partner_id, actor_user_id,
                         subject_type, subject_id, new_value, ip_address, created_at)
                    VALUES ('authorisation_admin_approved', ?, ?, ?, 'business_owner_authorisations', ?, ?, ?, NOW())
                ")->execute([
                    $auth['business_id'], $auth['partner_id'], $userId, $subjectId,
                    json_encode(['approved_by_admin' => true]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $flashSuccess = 'Authorisation approved.';
            } else {
                $flashError = 'Authorisation request not found or already processed.';
            }
        }

        elseif ($action === 'reject_auth' && $subjectId > 0) {
            $row = $pdo->prepare("SELECT * FROM business_owner_authorisations WHERE id=? AND status='pending'");
            $row->execute([$subjectId]);
            $auth = $row->fetch(PDO::FETCH_ASSOC);

            if ($auth) {
                $pdo->prepare("
                    UPDATE business_owner_authorisations
                    SET status='declined', responded_at=NOW(), updated_at=NOW(), notes=CONCAT(IFNULL(notes,''), ?, ?)
                    WHERE id=?
                ")->execute([
                    $reason ? "\n[Admin rejection] " : '',
                    $reason,
                    $subjectId,
                ]);

                // Audit
                $pdo->prepare("
                    INSERT INTO acquisition_activity_logs
                        (event_type, business_id, partner_id, actor_user_id,
                         subject_type, subject_id, new_value, ip_address, created_at)
                    VALUES ('authorisation_admin_rejected', ?, ?, ?, 'business_owner_authorisations', ?, ?, ?, NOW())
                ")->execute([
                    $auth['business_id'], $auth['partner_id'], $userId, $subjectId,
                    json_encode(['reason' => $reason]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $flashSuccess = 'Authorisation request rejected.';
            } else {
                $flashError = 'Request not found or already processed.';
            }
        }

        elseif ($action === 'activate_assignment' && $subjectId > 0) {
            // subjectId = business_owner_authorisations.id (approved)
            $row = $pdo->prepare("SELECT * FROM business_owner_authorisations WHERE id=? AND status='approved'");
            $row->execute([$subjectId]);
            $auth = $row->fetch(PDO::FETCH_ASSOC);

            if ($auth) {
                // Create / activate partner_business_assignments row
                $existAssign = $pdo->prepare("
                    SELECT id FROM partner_business_assignments
                    WHERE business_id=? AND partner_id=? AND status='active' LIMIT 1
                ");
                $existAssign->execute([$auth['business_id'], $auth['partner_id']]);
                $assignId = (int) ($existAssign->fetchColumn() ?: 0);

                if (!$assignId) {
                    $insAssign = $pdo->prepare("
                        INSERT INTO partner_business_assignments
                            (business_id, partner_id, assigned_by, status, start_date, created_at, updated_at)
                        VALUES (?, ?, ?, 'active', CURDATE(), NOW(), NOW())
                    ");
                    $insAssign->execute([$auth['business_id'], $auth['partner_id'], $userId]);
                    $assignId = (int) $pdo->lastInsertId();
                }

                // Convert prospect
                $pdo->prepare("
                    UPDATE business_prospects
                    SET status='converted', conversion_outcome='partner_assigned', updated_at=NOW()
                    WHERE business_id=? AND partner_id=? AND status='awaiting_assignment'
                ")->execute([$auth['business_id'], $auth['partner_id']]);

                // Audit
                $pdo->prepare("
                    INSERT INTO acquisition_activity_logs
                        (event_type, business_id, partner_id, actor_user_id,
                         subject_type, subject_id, new_value, ip_address, created_at)
                    VALUES ('assignment_activated', ?, ?, ?, 'partner_business_assignments', ?, ?, ?, NOW())
                ")->execute([
                    $auth['business_id'], $auth['partner_id'], $userId, $assignId,
                    json_encode(['authorisation_id' => $subjectId]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $flashSuccess = 'Partner assignment activated successfully.';
            } else {
                $flashError = 'Approved authorisation not found.';
            }
        }
    }
}

// ── Tab / filter ───────────────────────────────────────────────────────────────
$tab  = $_GET['tab']  ?? 'pending_auth';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

// ── Data queries ───────────────────────────────────────────────────────────────
$rows  = [];
$total = 0;

if ($tab === 'pending_auth') {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM business_owner_authorisations WHERE status='pending'");
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT boa.*,
               b.name AS business_name, b.city,
               u_owner.name AS owner_name, u_owner.email AS owner_email,
               p.business_name AS partner_biz_name, u_partner.name AS partner_user_name,
               bp.id AS prospect_id
        FROM business_owner_authorisations boa
        JOIN businesses b       ON b.id = boa.business_id
        JOIN users u_owner      ON u_owner.id = boa.owner_user_id
        JOIN partners p         ON p.id = boa.partner_id
        JOIN users u_partner    ON u_partner.id = p.user_id
        LEFT JOIN business_prospects bp
               ON bp.business_id = boa.business_id AND bp.partner_id = boa.partner_id
        WHERE boa.status = 'pending'
        ORDER BY boa.requested_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($tab === 'approved_auth') {
    $countStmt = $pdo->query("
        SELECT COUNT(*) FROM business_owner_authorisations boa
        WHERE boa.status='approved'
          AND NOT EXISTS (
            SELECT 1 FROM partner_business_assignments pba
            WHERE pba.business_id=boa.business_id AND pba.partner_id=boa.partner_id AND pba.status='active'
          )
    ");
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT boa.*,
               b.name AS business_name, b.city,
               u_owner.name AS owner_name,
               p.business_name AS partner_biz_name, u_partner.name AS partner_user_name
        FROM business_owner_authorisations boa
        JOIN businesses b       ON b.id = boa.business_id
        JOIN users u_owner      ON u_owner.id = boa.owner_user_id
        JOIN partners p         ON p.id = boa.partner_id
        JOIN users u_partner    ON u_partner.id = p.user_id
        WHERE boa.status = 'approved'
          AND NOT EXISTS (
            SELECT 1 FROM partner_business_assignments pba
            WHERE pba.business_id=boa.business_id AND pba.partner_id=boa.partner_id AND pba.status='active'
          )
        ORDER BY boa.responded_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($tab === 'active') {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM partner_business_assignments WHERE status='active'");
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT pba.*,
               b.name AS business_name, b.city,
               p.business_name AS partner_biz_name, u.name AS partner_user_name
        FROM partner_business_assignments pba
        JOIN businesses b ON b.id = pba.business_id
        JOIN partners p   ON p.id = pba.partner_id
        JOIN users u      ON u.id = p.user_id
        WHERE pba.status = 'active'
        ORDER BY pba.start_date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($tab === 'prospects') {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM business_prospects");
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT bp.*,
               b.name AS business_name, b.city,
               p.business_name AS partner_biz_name, u.name AS partner_user_name
        FROM business_prospects bp
        JOIN businesses b ON b.id = bp.business_id
        JOIN partners p   ON p.id = bp.partner_id
        JOIN users u      ON u.id = p.user_id
        ORDER BY bp.updated_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pages = max(1, (int) ceil($total / $perPage));

// Summary stats
$stats = [];
$stats['pending_auth']  = (int) $pdo->query("SELECT COUNT(*) FROM business_owner_authorisations WHERE status='pending'")->fetchColumn();
$stats['approved_auth'] = (int) $pdo->query("
    SELECT COUNT(*) FROM business_owner_authorisations boa
    WHERE boa.status='approved'
      AND NOT EXISTS (SELECT 1 FROM partner_business_assignments pba WHERE pba.business_id=boa.business_id AND pba.partner_id=boa.partner_id AND pba.status='active')
")->fetchColumn();
$stats['active']        = (int) $pdo->query("SELECT COUNT(*) FROM partner_business_assignments WHERE status='active'")->fetchColumn();
$stats['prospects']     = (int) $pdo->query("SELECT COUNT(*) FROM business_prospects")->fetchColumn();

$pageTitle = 'Acquisition Management';
require_once '../includes/admin-header.php';
?>

<div class="container-fluid px-4 py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h3 mb-0">Acquisition Management</h1>
      <p class="text-muted mb-0">Manage partner prospect pipelines, owner authorisations, and assignments.</p>
    </div>
  </div>

  <?php if ($flashSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <?= htmlspecialchars($flashSuccess) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <?= htmlspecialchars($flashError) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Stat row -->
  <div class="row g-3 mb-4">
    <?php foreach ([
      ['pending_auth',  'Pending Authorisations', 'warning', 'person-exclamation'],
      ['approved_auth', 'Approved – Awaiting Assignment', 'info', 'person-check'],
      ['active',        'Active Assignments', 'success', 'briefcase'],
      ['prospects',     'Total Prospects', 'secondary', 'list-task'],
    ] as [$key, $label, $color, $icon]): ?>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="fs-2 text-<?= $color ?>"><i class="bi bi-<?= $icon ?>"></i></div>
        <div class="fs-4 fw-bold"><?= number_format($stats[$key]) ?></div>
        <div class="small text-muted"><?= $label ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-0">
    <?php foreach ([
      'pending_auth'  => 'Pending Authorisations' . ($stats['pending_auth'] ? ' <span class="badge bg-warning text-dark ms-1">' . $stats['pending_auth'] . '</span>' : ''),
      'approved_auth' => 'Ready to Activate' . ($stats['approved_auth'] ? ' <span class="badge bg-info ms-1">' . $stats['approved_auth'] . '</span>' : ''),
      'active'        => 'Active Assignments',
      'prospects'     => 'All Prospects',
    ] as $key => $label): ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab === $key ? 'active' : '' ?>"
         href="acquisition-management.php?tab=<?= $key ?>"><?= $label ?></a>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="card border-0 shadow-sm" style="border-top-left-radius:0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
      <span class="fw-semibold"><?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($rows)): ?>
      <div class="card-body text-center text-muted py-5">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
        Nothing here yet.
      </div>
    <?php else: ?>

    <?php if ($tab === 'pending_auth'): ?>
    <!-- Pending authorisations table -->
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Business</th>
            <th>Owner</th>
            <th>Partner</th>
            <th>Services Requested</th>
            <th>Requested</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($r['business_name']) ?></div>
              <div class="text-muted small"><?= htmlspecialchars($r['city'] ?? '') ?></div>
            </td>
            <td class="small"><?= htmlspecialchars($r['owner_name']) ?><br>
              <span class="text-muted"><?= htmlspecialchars($r['owner_email'] ?? '') ?></span></td>
            <td class="small"><?= htmlspecialchars($r['partner_biz_name'] ?? $r['partner_user_name']) ?></td>
            <td class="small">
              <?php $svcs = json_decode($r['requested_services'] ?? '[]', true) ?: []; ?>
              <?= htmlspecialchars(implode(', ', array_map(fn($s) => str_replace('_',' ',$s), $svcs))) ?>
            </td>
            <td class="text-muted small"><?= date('d M Y', strtotime($r['requested_at'])) ?></td>
            <td>
              <div class="d-flex gap-2">
                <!-- Approve -->
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                  <input type="hidden" name="action"      value="approve_auth">
                  <input type="hidden" name="subject_id"  value="<?= $r['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-success"
                          onclick="return confirm('Approve this authorisation request?')">
                    <i class="bi bi-check-lg"></i> Approve
                  </button>
                </form>
                <!-- Reject -->
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#rejectModal"
                        data-auth-id="<?= $r['id'] ?>"
                        data-business="<?= htmlspecialchars($r['business_name']) ?>">
                  <i class="bi bi-x-lg"></i> Reject
                </button>
              </div>
            </td>
          </tr>
          <?php if (!empty($r['notes'])): ?>
          <tr class="table-secondary">
            <td colspan="6" class="small text-muted ps-4">
              <i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($r['notes']) ?>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($tab === 'approved_auth'): ?>
    <!-- Approved, awaiting activation -->
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Business</th>
            <th>Owner</th>
            <th>Partner</th>
            <th>Services</th>
            <th>Approved</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($r['business_name']) ?></div>
              <div class="text-muted small"><?= htmlspecialchars($r['city'] ?? '') ?></div>
            </td>
            <td class="small"><?= htmlspecialchars($r['owner_name']) ?></td>
            <td class="small"><?= htmlspecialchars($r['partner_biz_name'] ?? $r['partner_user_name']) ?></td>
            <td class="small">
              <?php $svcs = json_decode($r['requested_services'] ?? '[]', true) ?: []; ?>
              <?= htmlspecialchars(implode(', ', array_map(fn($s) => str_replace('_',' ',$s), $svcs))) ?>
            </td>
            <td class="text-muted small"><?= date('d M Y', strtotime($r['responded_at'] ?? $r['created_at'])) ?></td>
            <td>
              <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action"      value="activate_assignment">
                <input type="hidden" name="subject_id"  value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-sm btn-primary"
                        onclick="return confirm('Activate partner assignment for <?= htmlspecialchars(addslashes($r['business_name'])) ?>?')">
                  <i class="bi bi-lightning-charge me-1"></i> Activate Assignment
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($tab === 'active'): ?>
    <!-- Active assignments -->
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Business</th>
            <th>Partner</th>
            <th>Start Date</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($r['business_name']) ?></div>
              <div class="text-muted small"><?= htmlspecialchars($r['city'] ?? '') ?></div>
            </td>
            <td class="small"><?= htmlspecialchars($r['partner_biz_name'] ?? $r['partner_user_name']) ?></td>
            <td class="text-muted small"><?= $r['start_date'] ? date('d M Y', strtotime($r['start_date'])) : '—' ?></td>
            <td><span class="badge bg-success">Active</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($tab === 'prospects'): ?>
    <!-- All prospects -->
    <?php
    $statusBadge = [
        'new'                      => 'bg-secondary',
        'contacted'                => 'bg-info text-dark',
        'follow_up_required'       => 'bg-warning text-dark',
        'interested'               => 'bg-success',
        'invitation_sent'          => 'bg-primary',
        'awaiting_owner_response'  => 'bg-warning text-dark',
        'awaiting_authorisation'   => 'bg-warning text-dark',
        'awaiting_assignment'      => 'bg-warning text-dark',
        'converted'                => 'bg-success',
        'not_interested'           => 'bg-danger',
        'unreachable'              => 'bg-danger',
        'rejected'                 => 'bg-danger',
        'closed'                   => 'bg-secondary',
    ];
    ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Business</th>
            <th>Partner</th>
            <th>Status</th>
            <th>Next Follow-up</th>
            <th>Last Updated</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $overdue = $r['next_followup'] && $r['next_followup'] < date('Y-m-d');
            $bc = $statusBadge[$r['status']] ?? 'bg-secondary';
          ?>
          <tr class="<?= $overdue ? 'table-warning' : '' ?>">
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($r['business_name']) ?></div>
              <div class="text-muted small"><?= htmlspecialchars($r['city'] ?? '') ?></div>
            </td>
            <td class="small"><?= htmlspecialchars($r['partner_biz_name'] ?? $r['partner_user_name']) ?></td>
            <td><span class="badge <?= $bc ?>"><?= ucwords(str_replace('_',' ',$r['status'])) ?></span></td>
            <td class="<?= $overdue ? 'text-danger fw-bold small' : 'text-muted small' ?>">
              <?= $r['next_followup'] ? date('d M Y', strtotime($r['next_followup'])) : '—' ?>
            </td>
            <td class="text-muted small"><?= date('d M Y', strtotime($r['updated_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white">
      <nav><ul class="pagination pagination-sm justify-content-center mb-0">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
          <a class="page-link" href="acquisition-management.php?tab=<?= $tab ?>&page=<?= $i ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
      </ul></nav>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action"     value="reject_auth">
        <input type="hidden" name="subject_id" id="rejectSubjectId">
        <div class="modal-header">
          <h5 class="modal-title">Reject Authorisation Request</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small" id="rejectBusinessLabel"></p>
          <div class="mb-3">
            <label class="form-label fw-semibold">Reason <span class="text-muted fw-normal">(optional)</span></label>
            <textarea name="reason" class="form-control" rows="3" placeholder="e.g. Partner does not meet requirements for this region"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Reject Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('rejectModal')?.addEventListener('show.bs.modal', function(e) {
  const btn = e.relatedTarget;
  document.getElementById('rejectSubjectId').value = btn.getAttribute('data-auth-id');
  document.getElementById('rejectBusinessLabel').textContent = 'Rejecting authorisation for: ' + btn.getAttribute('data-business');
});
</script>

<?php require_once '../includes/admin-footer.php'; ?>
