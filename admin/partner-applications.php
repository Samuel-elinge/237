<?php
/**
 * admin/partner-applications.php — Partner Application Review Queue (Task 42)
 *
 * Lists pending/all partner applications; allows approve, reject, request-info.
 * Approve: sets status→approved, user role→growth_partner, creates verification record.
 * Reject:  sets status→rejected, stores reason, notifies applicant.
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();

// ── POST actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['partner_id'] ?? 0);

    if ($pid && $action === 'approve') {
        // 1. Fetch application
        $app = $pdo->prepare("SELECT pp.*, u.email, u.name AS user_name FROM partner_profiles pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?");
        $app->execute([$pid]);
        $app = $app->fetch();

        if ($app && $app['status'] === 'pending') {
            $notes = trim($_POST['approval_notes'] ?? '');
            $tier  = (int)($_POST['tier_id'] ?? 1);

            $pdo->beginTransaction();
            try {
                // Update partner profile
                $pdo->prepare("UPDATE partner_profiles SET status='approved', approved_by=?, approved_at=NOW(), tier_id=? WHERE id=?")
                    ->execute([$_SESSION['user_id'], $tier ?: 1, $pid]);

                // Promote user role
                $pdo->prepare("UPDATE users SET role='growth_partner' WHERE id=?")
                    ->execute([$app['user_id']]);

                // Verification record
                $pdo->prepare("INSERT INTO partner_verifications (partner_id, verified_by, notes, status) VALUES (?,?,?,'approved') ON DUPLICATE KEY UPDATE verified_by=VALUES(verified_by), notes=VALUES(notes), status='approved', verified_at=NOW()")
                    ->execute([$pid, $_SESSION['user_id'], $notes]);

                $pdo->commit();

                // Audit + notification
                partnerAuditLog($pid, (int)$_SESSION['user_id'], null, 'approved', 'Application approved' . ($notes ? ": $notes" : ''));
                pushNotification((int)$app['user_id'], 'partner_approved', 'Application Approved 🎉',
                    'Congratulations! Your Growth Partner application has been approved. You can now access your partner dashboard.',
                    SITE_URL . '/partner/dashboard', $pid);

                setFlash('success', "Partner approved: {$app['user_name']}");
            } catch (\Throwable $e) {
                $pdo->rollBack();
                setFlash('error', 'Approval failed — please try again.');
            }
        }
        redirect(SITE_URL . '/admin/partner-applications');
    }

    if ($pid && $action === 'reject') {
        $app = $pdo->prepare("SELECT pp.*, u.email, u.name AS user_name FROM partner_profiles pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?");
        $app->execute([$pid]);
        $app = $app->fetch();

        if ($app && $app['status'] === 'pending') {
            $reason = trim($_POST['reject_reason'] ?? '');

            $pdo->prepare("UPDATE partner_profiles SET status='rejected' WHERE id=?")
                ->execute([$pid]);

            $pdo->prepare("INSERT INTO partner_verifications (partner_id, verified_by, notes, status) VALUES (?,?,?,'rejected') ON DUPLICATE KEY UPDATE verified_by=VALUES(verified_by), notes=VALUES(notes), status='rejected', verified_at=NOW()")
                ->execute([$pid, $_SESSION['user_id'], $reason]);

            partnerAuditLog($pid, (int)$_SESSION['user_id'], null, 'rejected', 'Application rejected' . ($reason ? ": $reason" : ''));
            pushNotification((int)$app['user_id'], 'partner_rejected', 'Application Update',
                'Thank you for applying. Unfortunately your application was not approved at this time.' . ($reason ? " Reason: $reason" : '') . ' You may re-apply in 30 days.',
                SITE_URL . '/partner/onboarding', $pid);

            setFlash('success', "Application rejected.");
        }
        redirect(SITE_URL . '/admin/partner-applications');
    }

    if ($pid && $action === 'request_info') {
        $app = $pdo->prepare("SELECT pp.user_id, u.name AS user_name FROM partner_profiles pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?");
        $app->execute([$pid]);
        $app = $app->fetch();

        if ($app) {
            $question = trim($_POST['info_request'] ?? '');
            if ($question) {
                pushNotification((int)$app['user_id'], 'partner_info_request', 'Additional Information Needed',
                    $question, SITE_URL . '/partner/onboarding', $pid);
                partnerAuditLog($pid, (int)$_SESSION['user_id'], null, 'info_requested', $question);
                setFlash('success', 'Information request sent.');
            }
        }
        redirect(SITE_URL . '/admin/partner-applications');
    }
}

// ── Filters ───────────────────────────────────────────────
$filterStatus = in_array($_GET['status'] ?? '', ['pending','approved','rejected','all']) ? $_GET['status'] : 'pending';
$search       = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

if ($filterStatus !== 'all') {
    $where[]  = "pp.status = ?";
    $params[] = $filterStatus;
}
if ($search) {
    $where[]  = "(u.name LIKE ? OR u.email LIKE ? OR pp.display_name LIKE ? OR pp.organisation LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s);
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$applications = $pdo->prepare("
    SELECT pp.id, pp.status, pp.display_name, pp.organisation, pp.tagline, pp.bio,
           pp.specialisms, pp.services_offered, pp.experience, pp.why_join, pp.areas_covered,
           pp.max_businesses, pp.referral_code, pp.created_at,
           u.name AS user_name, u.email,
           t.name AS tier_name,
           (SELECT COUNT(*) FROM partner_locations pl WHERE pl.partner_id=pp.id) AS location_count,
           pv.status AS verify_status, pv.notes AS verify_notes
    FROM partner_profiles pp
    JOIN users u ON u.id = pp.user_id
    LEFT JOIN partner_tiers t ON t.id = pp.tier_id
    LEFT JOIN partner_verifications pv ON pv.partner_id = pp.id
    $whereSQL
    ORDER BY pp.created_at DESC
");
$applications->execute($params);
$applications = $applications->fetchAll();

// Counts for tabs
$counts = $pdo->query("
    SELECT status, COUNT(*) AS n FROM partner_profiles GROUP BY status
")->fetchAll(\PDO::FETCH_KEY_PAIR);

// Tiers for approve modal
$tiers = $pdo->query("SELECT id, name, slug FROM partner_tiers ORDER BY id")->fetchAll();

// Flash
$flash = getFlash();

// ── Checklist items for the approval verification panel ───
$checklist = [
    'Identity verified (government ID or LinkedIn)',
    'Business location confirmed',
    'Experience matches claims',
    'No conflict of interest with existing partners',
    'Phone number reachable',
    'Agreed to Partner Agreement terms',
    'Capacity is realistic for their region',
];

$pageTitle = 'Partner Applications';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.app-tabs{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:1.5rem}
.app-tab{padding:.6rem 1.2rem;font-size:.875rem;font-weight:500;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;white-space:nowrap}
.app-tab.active{color:#2563eb;border-color:#2563eb}
.app-tab .badge{display:inline-block;background:#ef4444;color:#fff;font-size:.7rem;border-radius:99px;padding:.1rem .4rem;margin-left:.3rem}
.app-tab.active .badge{background:#2563eb}
.filters-bar{display:flex;gap:.75rem;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap}
.filters-bar input[type=text]{border:1px solid #d1d5db;border-radius:.5rem;padding:.45rem .75rem;font-size:.875rem;flex:1;min-width:180px}
.app-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;margin-bottom:1rem;overflow:hidden}
.app-card-header{display:flex;align-items:flex-start;gap:1rem;padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6}
.app-avatar{width:48px;height:48px;border-radius:50%;background:#2563eb;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem;flex-shrink:0}
.app-meta{flex:1;min-width:0}
.app-name{font-weight:600;font-size:1rem;color:#111827}
.app-sub{font-size:.8rem;color:#6b7280;margin-top:.15rem}
.app-badges{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.35rem}
.badge-status{font-size:.72rem;font-weight:600;padding:.15rem .55rem;border-radius:99px}
.badge-status.pending{background:#fef3c7;color:#92400e}
.badge-status.approved{background:#d1fae5;color:#065f46}
.badge-status.rejected{background:#fee2e2;color:#991b1b}
.app-date{font-size:.78rem;color:#9ca3af;white-space:nowrap}
.app-body{padding:1rem 1.25rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:640px){.app-body{grid-template-columns:1fr}}
.app-field label{font-size:.72rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.2rem}
.app-field p{font-size:.875rem;color:#374151;margin:0}
.pills{display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.25rem}
.pill{background:#eff6ff;color:#1d4ed8;font-size:.72rem;padding:.2rem .55rem;border-radius:99px}
.app-actions{padding:.75rem 1.25rem;background:#f9fafb;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .9rem;border-radius:.5rem;font-size:.8rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-approve{background:#059669;color:#fff}
.btn-reject{background:#dc2626;color:#fff}
.btn-info{background:#f59e0b;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.btn:hover{opacity:.9}
.checklist{list-style:none;padding:0;margin:.75rem 0 0}
.checklist li{display:flex;align-items:center;gap:.5rem;padding:.3rem 0;font-size:.875rem;color:#374151;border-bottom:1px solid #f3f4f6}
.checklist li:last-child{border:none}
.checklist input[type=checkbox]{width:16px;height:16px;accent-color:#059669}
.empty-state{text-align:center;padding:3rem 1rem;color:#9ca3af}
.empty-state .icon{font-size:2.5rem;margin-bottom:.5rem}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:.75rem;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);padding:1.5rem}
.modal h3{margin:0 0 1rem;font-size:1.05rem;color:#111827}
.modal label{font-size:.82rem;font-weight:600;color:#374151;display:block;margin:.75rem 0 .25rem}
.modal select,.modal textarea,.modal input{width:100%;border:1px solid #d1d5db;border-radius:.5rem;padding:.5rem .75rem;font-size:.875rem;box-sizing:border-box}
.modal textarea{min-height:90px;resize:vertical}
.modal-actions{display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
</style>

<div class="admin-wrap" style="max-width:900px;margin:0 auto;padding:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
        <div>
            <h1 style="margin:0;font-size:1.35rem;color:#111827">Partner Applications</h1>
            <p style="margin:.25rem 0 0;font-size:.85rem;color:#6b7280">Review and process Growth Partner applications</p>
        </div>
        <a href="<?= SITE_URL ?>/admin" class="btn btn-detail">← Admin</a>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <!-- Status Tabs -->
    <div class="app-tabs">
        <?php
        $tabs = ['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'];
        foreach ($tabs as $s => $label):
            $cnt = $s === 'all' ? array_sum($counts) : ($counts[$s] ?? 0);
            $active = ($filterStatus === $s) ? ' active' : '';
        ?>
        <a href="?status=<?= $s ?>&q=<?= urlencode($search) ?>" class="app-tab<?= $active ?>">
            <?= $label ?>
            <?php if ($cnt): ?><span class="badge"><?= $cnt ?></span><?php endif ?>
        </a>
        <?php endforeach ?>
    </div>

    <!-- Search -->
    <div class="filters-bar">
        <form method="get" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
            <input type="hidden" name="status" value="<?= e($filterStatus) ?>">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name, email, organisation…">
            <button type="submit" class="btn btn-detail" style="white-space:nowrap">Search</button>
            <?php if ($search): ?>
            <a href="?status=<?= e($filterStatus) ?>" class="btn btn-detail">Clear</a>
            <?php endif ?>
        </form>
    </div>

    <!-- Application Cards -->
    <?php if (!$applications): ?>
    <div class="empty-state">
        <div class="icon">📋</div>
        <p>No <?= $filterStatus !== 'all' ? $filterStatus : '' ?> applications found.</p>
    </div>
    <?php else: ?>

    <?php foreach ($applications as $a):
        $initials  = strtoupper(substr($a['display_name'] ?: $a['user_name'], 0, 1));
        $specs     = json_decode($a['specialisms'] ?? '[]', true) ?: [];
        $services  = json_decode($a['services_offered'] ?? '[]', true) ?: [];
    ?>
    <div class="app-card">
        <div class="app-card-header">
            <div class="app-avatar"><?= e($initials) ?></div>
            <div class="app-meta">
                <div class="app-name"><?= e($a['display_name'] ?: $a['user_name']) ?></div>
                <div class="app-sub"><?= e($a['email']) ?><?= $a['organisation'] ? ' · ' . e($a['organisation']) : '' ?></div>
                <div class="app-badges">
                    <span class="badge-status <?= e($a['status']) ?>"><?= ucfirst($a['status']) ?></span>
                    <?php if ($a['tier_name']): ?><span class="pill"><?= e($a['tier_name']) ?></span><?php endif ?>
                    <?php if ($a['location_count']): ?><span class="pill">📍 <?= (int)$a['location_count'] ?> location<?= $a['location_count'] != 1 ? 's' : '' ?></span><?php endif ?>
                </div>
            </div>
            <div class="app-date"><?= date('d M Y', strtotime($a['created_at'])) ?></div>
        </div>

        <div class="app-body">
            <?php if ($a['tagline']): ?>
            <div class="app-field" style="grid-column:1/-1">
                <label>Tagline</label>
                <p><?= e($a['tagline']) ?></p>
            </div>
            <?php endif ?>

            <?php if ($a['bio']): ?>
            <div class="app-field" style="grid-column:1/-1">
                <label>Bio / Background</label>
                <p style="white-space:pre-line"><?= e(mb_substr($a['bio'], 0, 300)) ?><?= mb_strlen($a['bio']) > 300 ? '…' : '' ?></p>
            </div>
            <?php endif ?>

            <?php if ($specs): ?>
            <div class="app-field">
                <label>Specialisms</label>
                <div class="pills"><?php foreach ($specs as $sp): ?><span class="pill"><?= e($sp) ?></span><?php endforeach ?></div>
            </div>
            <?php endif ?>

            <?php if ($services): ?>
            <div class="app-field">
                <label>Services Offered</label>
                <div class="pills"><?php foreach ($services as $sv): ?><span class="pill"><?= e($sv) ?></span><?php endforeach ?></div>
            </div>
            <?php endif ?>

            <?php if ($a['experience']): ?>
            <div class="app-field">
                <label>Experience</label>
                <p><?= e(mb_substr($a['experience'], 0, 200)) ?><?= mb_strlen($a['experience']) > 200 ? '…' : '' ?></p>
            </div>
            <?php endif ?>

            <?php if ($a['why_join']): ?>
            <div class="app-field">
                <label>Why Join?</label>
                <p><?= e(mb_substr($a['why_join'], 0, 200)) ?><?= mb_strlen($a['why_join']) > 200 ? '…' : '' ?></p>
            </div>
            <?php endif ?>

            <div class="app-field">
                <label>Capacity</label>
                <p>Up to <?= (int)$a['max_businesses'] ?> businesses</p>
            </div>

            <?php if ($a['referral_code']): ?>
            <div class="app-field">
                <label>Referral Code</label>
                <p style="font-family:monospace"><?= e($a['referral_code']) ?></p>
            </div>
            <?php endif ?>
        </div>

        <!-- Verification Checklist (pending only) -->
        <?php if ($a['status'] === 'pending'): ?>
        <div style="padding:.75rem 1.25rem;border-top:1px solid #f3f4f6">
            <p style="margin:0 0 .25rem;font-size:.78rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">Verification Checklist</p>
            <ul class="checklist">
                <?php foreach ($checklist as $i => $item): ?>
                <li>
                    <input type="checkbox" id="chk-<?= $a['id'] ?>-<?= $i ?>">
                    <label for="chk-<?= $a['id'] ?>-<?= $i ?>" style="margin:0;font-weight:400;font-size:.875rem;cursor:pointer"><?= e($item) ?></label>
                </li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>

        <!-- Actions -->
        <div class="app-actions">
            <?php if ($a['status'] === 'pending'): ?>
            <button class="btn btn-approve" onclick="openApprove(<?= $a['id'] ?>, <?= e(json_encode($a['display_name'] ?: $a['user_name'])) ?>)">✓ Approve</button>
            <button class="btn btn-reject" onclick="openReject(<?= $a['id'] ?>, <?= e(json_encode($a['display_name'] ?: $a['user_name'])) ?>)">✗ Reject</button>
            <button class="btn btn-info" onclick="openInfo(<?= $a['id'] ?>, <?= e(json_encode($a['display_name'] ?: $a['user_name'])) ?>)">? Request Info</button>
            <?php elseif ($a['status'] === 'approved'): ?>
            <a href="<?= SITE_URL ?>/admin/manage-growth-partners" class="btn btn-detail">Manage Partner →</a>
            <?php endif ?>
            <?php if ($a['verify_notes']): ?>
            <span style="font-size:.78rem;color:#6b7280;margin-left:.5rem">Note: <?= e(mb_substr($a['verify_notes'], 0, 80)) ?></span>
            <?php endif ?>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>
</div>

<!-- Approve Modal -->
<div class="modal-overlay" id="modal-approve">
    <div class="modal">
        <h3>Approve Application</h3>
        <p id="approve-name" style="margin:.25rem 0 .75rem;color:#6b7280;font-size:.875rem"></p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="partner_id" id="approve-pid" value="">

            <label>Assign Tier</label>
            <select name="tier_id">
                <?php foreach ($tiers as $t): ?>
                <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
                <?php endforeach ?>
            </select>

            <label>Approval Notes (optional)</label>
            <textarea name="approval_notes" placeholder="Any notes for the record…"></textarea>

            <div class="modal-actions">
                <button type="button" class="btn btn-detail" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-approve">Confirm Approval</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="modal-reject">
    <div class="modal">
        <h3>Reject Application</h3>
        <p id="reject-name" style="margin:.25rem 0 .75rem;color:#6b7280;font-size:.875rem"></p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="partner_id" id="reject-pid" value="">

            <label>Rejection Reason <span style="color:#9ca3af;font-weight:400">(sent to applicant)</span></label>
            <textarea name="reject_reason" placeholder="e.g. Insufficient experience in target region, or incomplete information…" required></textarea>

            <div class="modal-actions">
                <button type="button" class="btn btn-detail" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-reject">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- Request Info Modal -->
<div class="modal-overlay" id="modal-info">
    <div class="modal">
        <h3>Request Additional Information</h3>
        <p id="info-name" style="margin:.25rem 0 .75rem;color:#6b7280;font-size:.875rem"></p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="request_info">
            <input type="hidden" name="partner_id" id="info-pid" value="">

            <label>Your question or request</label>
            <textarea name="info_request" placeholder="e.g. Please provide more details about your experience in the Littoral Region…" required></textarea>

            <div class="modal-actions">
                <button type="button" class="btn btn-detail" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-info">Send Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApprove(pid, name) {
    document.getElementById('approve-pid').value = pid;
    document.getElementById('approve-name').textContent = 'Approving: ' + name;
    document.getElementById('modal-approve').classList.add('open');
}
function openReject(pid, name) {
    document.getElementById('reject-pid').value = pid;
    document.getElementById('reject-name').textContent = 'Rejecting: ' + name;
    document.getElementById('modal-reject').classList.add('open');
}
function openInfo(pid, name) {
    document.getElementById('info-pid').value = pid;
    document.getElementById('info-name').textContent = 'To: ' + name;
    document.getElementById('modal-info').classList.add('open');
}
function closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
}
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModals(); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
