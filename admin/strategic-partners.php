<?php
/**
 * admin/strategic-partners.php — Strategic Partner Management (Task 61)
 *
 * Manage the top tier of Growth Partners: territory exclusivity,
 * strategic account targets, dedicated account manager assignment,
 * revenue commitments, and quarterly business review (QBR) scheduling.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
requireAdmin();

$errors  = [];
$success = '';

// ── POST handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action    = $_POST['action'] ?? '';
    $partnerId = (int)($_POST['partner_id'] ?? 0);

    // ── Promote / demote strategic status ─────────────────
    if ($action === 'set_strategic' && $partnerId) {
        $isStrategic  = (int)(bool)($_POST['is_strategic'] ?? 0);
        $exclusiveTerritory = trim($_POST['exclusive_territory'] ?? '');
        $revenueCommitment  = (float)($_POST['revenue_commitment'] ?? 0);
        $accountManagerId   = (int)($_POST['account_manager_id'] ?? 0) ?: null;
        $strategicNote      = trim($_POST['strategic_note'] ?? '');

        $pdo->prepare("
            UPDATE partner_profiles
            SET is_strategic          = ?,
                exclusive_territory   = ?,
                revenue_commitment    = ?,
                account_manager_id    = ?,
                strategic_note        = ?,
                strategic_since       = CASE WHEN ? = 1 AND (is_strategic = 0 OR is_strategic IS NULL) THEN NOW() ELSE strategic_since END,
                updated_at            = NOW()
            WHERE id = ?
        ")->execute([
            $isStrategic, $exclusiveTerritory, $revenueCommitment,
            $accountManagerId, $strategicNote, $isStrategic, $partnerId,
        ]);

        // Promote tier to platinum if not already
        if ($isStrategic) {
            $pdo->prepare("UPDATE partner_profiles SET tier='platinum' WHERE id=? AND tier NOT IN ('platinum')")->execute([$partnerId]);
        }

        $uid = (int)$pdo->query("SELECT user_id FROM partner_profiles WHERE id={$partnerId}")->fetchColumn();
        $msg = $isStrategic
            ? 'Congratulations! You have been designated as a Strategic Partner of 237biz. Your account manager will be in touch shortly.'
            : 'Your Strategic Partner designation has been updated.';
        if ($uid) pushNotification($uid, $msg, SITE_URL . '/partner/dashboard');
        partnerAuditLog($partnerId, $isStrategic ? 'promoted_strategic' : 'demoted_strategic', $strategicNote ?: 'Strategic status changed by admin');

        setFlash('success', $isStrategic ? 'Partner promoted to Strategic status.' : 'Strategic designation removed.');
        redirect(SITE_URL . '/admin/strategic-partners?selected=' . $partnerId);
    }

    // ── Schedule QBR ──────────────────────────────────────
    if ($action === 'schedule_qbr' && $partnerId) {
        $qbrDate  = trim($_POST['qbr_date'] ?? '');
        $qbrNotes = trim($_POST['qbr_notes'] ?? '');
        if (!$qbrDate) { $errors[] = 'QBR date required.'; }
        else {
            $pdo->prepare("
                INSERT INTO partner_qbrs (partner_id, scheduled_at, notes, status, created_by, created_at)
                VALUES (?, ?, ?, 'scheduled', ?, NOW())
            ")->execute([$partnerId, $qbrDate, $qbrNotes, $_SESSION['user_id']]);

            $uid = (int)$pdo->query("SELECT user_id FROM partner_profiles WHERE id={$partnerId}")->fetchColumn();
            if ($uid) pushNotification(
                $uid,
                'A Quarterly Business Review has been scheduled for ' . date('d M Y', strtotime($qbrDate)) . '. Please check your calendar.',
                SITE_URL . '/partner/dashboard'
            );
            partnerAuditLog($partnerId, 'qbr_scheduled', "QBR on {$qbrDate}");
            setFlash('success', 'QBR scheduled.');
            redirect(SITE_URL . '/admin/strategic-partners?selected=' . $partnerId);
        }
    }

    // ── Complete / cancel QBR ─────────────────────────────
    if (in_array($action, ['complete_qbr', 'cancel_qbr'])) {
        $qbrId  = (int)($_POST['qbr_id'] ?? 0);
        $status = $action === 'complete_qbr' ? 'completed' : 'cancelled';
        $outcome = trim($_POST['outcome'] ?? '');
        if ($qbrId) {
            $pdo->prepare("UPDATE partner_qbrs SET status=?, outcome=?, updated_at=NOW() WHERE id=?")
                ->execute([$status, $outcome, $qbrId]);
            // Get partner_id for audit
            $qbrPartnerId = (int)$pdo->query("SELECT partner_id FROM partner_qbrs WHERE id={$qbrId}")->fetchColumn();
            if ($qbrPartnerId) partnerAuditLog($qbrPartnerId, "qbr_{$status}", $outcome ?: "QBR {$status}");
            setFlash('success', 'QBR ' . $status . '.');
            redirect(SITE_URL . '/admin/strategic-partners?selected=' . ($qbrPartnerId ?: $partnerId));
        }
    }

    // ── Set revenue target ────────────────────────────────
    if ($action === 'set_target' && $partnerId) {
        $target = (float)($_POST['annual_target'] ?? 0);
        $period = trim($_POST['target_period'] ?? date('Y'));
        $pdo->prepare("
            INSERT INTO partner_revenue_targets (partner_id, period, target_amount, set_by, created_at)
            VALUES (?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE target_amount=VALUES(target_amount), set_by=VALUES(set_by), created_at=NOW()
        ")->execute([$partnerId, $period, $target, $_SESSION['user_id']]);
        partnerAuditLog($partnerId, 'revenue_target_set', "£" . number_format($target) . " for {$period}");
        setFlash('success', 'Revenue target saved.');
        redirect(SITE_URL . '/admin/strategic-partners?selected=' . $partnerId);
    }
}

// ── Strategic partner list ─────────────────────────────────
$strategicPartners = $pdo->query("
    SELECT pp.*,
           u.name AS user_name, u.email AS user_email,
           am.name AS account_manager_name,
           COUNT(DISTINCT pba.id) AS client_count,
           COALESCE(SUM(pl.commission_amount), 0) AS total_commission,
           (SELECT target_amount FROM partner_revenue_targets
            WHERE partner_id = pp.id AND period = YEAR(NOW()) LIMIT 1) AS current_target,
           (SELECT COUNT(*) FROM partner_qbrs WHERE partner_id = pp.id AND status='scheduled') AS pending_qbrs
    FROM partner_profiles pp
    JOIN users u ON u.id = pp.user_id
    LEFT JOIN users am ON am.id = pp.account_manager_id
    LEFT JOIN partner_business_assignments pba ON pba.partner_id = pp.id AND pba.status='active'
    LEFT JOIN partner_leads pl ON pl.partner_id = pp.id AND pl.status='converted'
    WHERE pp.is_strategic = 1
    GROUP BY pp.id
    ORDER BY pp.monthly_revenue DESC
")->fetchAll();

// ── Selected partner detail ────────────────────────────────
$selectedId = (int)($_GET['selected'] ?? ($strategicPartners[0]['id'] ?? 0));
$selected   = null;
$qbrs       = [];
$targets    = [];
$recentAudit = [];

if ($selectedId) {
    $selected = $pdo->prepare("
        SELECT pp.*, u.name AS user_name, u.email AS user_email, u.phone,
               u.created_at AS member_since,
               am.name AS account_manager_name,
               pr.name AS primary_region_name,
               (SELECT target_amount FROM partner_revenue_targets
                WHERE partner_id = pp.id AND period = YEAR(NOW()) LIMIT 1) AS current_target,
               (SELECT SUM(commission_amount) FROM partner_leads
                WHERE partner_id = pp.id AND status='converted'
                  AND created_at >= DATE_FORMAT(NOW(),'%Y-01-01')) AS ytd_revenue,
               (SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=pp.id AND status='active') AS active_clients,
               (SELECT COUNT(*) FROM partner_leads WHERE partner_id=pp.id AND status IN ('new','contacted','qualified','proposal','negotiation')) AS open_leads
        FROM partner_profiles pp
        JOIN users u ON u.id = pp.user_id
        LEFT JOIN users am ON am.id = pp.account_manager_id
        LEFT JOIN partner_regions pr ON pr.id = pp.primary_region_id
        WHERE pp.id = ?
    ")->execute([$selectedId]) ? null : null;
    $st = $pdo->prepare("
        SELECT pp.*, u.name AS user_name, u.email AS user_email, u.phone,
               u.created_at AS member_since,
               am.name AS account_manager_name,
               pr.name AS primary_region_name,
               (SELECT target_amount FROM partner_revenue_targets
                WHERE partner_id = pp.id AND period = YEAR(NOW()) LIMIT 1) AS current_target,
               (SELECT SUM(commission_amount) FROM partner_leads
                WHERE partner_id = pp.id AND status='converted'
                  AND created_at >= DATE_FORMAT(NOW(),'%Y-01-01')) AS ytd_revenue,
               (SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=pp.id AND status='active') AS active_clients,
               (SELECT COUNT(*) FROM partner_leads WHERE partner_id=pp.id AND status IN ('new','contacted','qualified','proposal','negotiation')) AS open_leads
        FROM partner_profiles pp
        JOIN users u ON u.id = pp.user_id
        LEFT JOIN users am ON am.id = pp.account_manager_id
        LEFT JOIN partner_regions pr ON pr.id = pp.primary_region_id
        WHERE pp.id = ?
    ");
    $st->execute([$selectedId]);
    $selected = $st->fetch() ?: null;

    if ($selected) {
        $qbrs = $pdo->prepare("
            SELECT q.*, u.name AS created_by_name
            FROM partner_qbrs q
            LEFT JOIN users u ON u.id = q.created_by
            WHERE q.partner_id = ?
            ORDER BY q.scheduled_at DESC
            LIMIT 10
        ")->execute([$selectedId]) ? [] : [];
        $qSt = $pdo->prepare("
            SELECT q.*, u.name AS created_by_name
            FROM partner_qbrs q
            LEFT JOIN users u ON u.id = q.created_by
            WHERE q.partner_id = ?
            ORDER BY q.scheduled_at DESC
            LIMIT 10
        ");
        $qSt->execute([$selectedId]);
        $qbrs = $qSt->fetchAll();

        $tSt = $pdo->prepare("
            SELECT * FROM partner_revenue_targets WHERE partner_id=? ORDER BY period DESC LIMIT 5
        ");
        $tSt->execute([$selectedId]);
        $targets = $tSt->fetchAll();

        $aSt = $pdo->prepare("
            SELECT * FROM partner_audit_logs WHERE partner_id=? ORDER BY created_at DESC LIMIT 15
        ");
        $aSt->execute([$selectedId]);
        $recentAudit = $aSt->fetchAll();
    }
}

// ── Eligible partners (for promotion search) ──────────────
$eligiblePartners = $pdo->query("
    SELECT pp.id, u.name, pp.tier, pp.monthly_revenue, pp.active_clients, pp.performance_score
    FROM partner_profiles pp
    JOIN users u ON u.id = pp.user_id
    WHERE pp.status IN ('approved','active') AND (pp.is_strategic IS NULL OR pp.is_strategic=0)
    ORDER BY pp.monthly_revenue DESC
    LIMIT 30
")->fetchAll();

// ── Admin users (for account manager assignment) ──────────
$adminUsers = $pdo->query("
    SELECT id, name FROM users WHERE role='admin' ORDER BY name
")->fetchAll();

$pageTitle = 'Strategic Partners';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:1300px;margin:0 auto;padding:1.5rem}
.layout{display:grid;grid-template-columns:320px 1fr;gap:1.25rem;align-items:start}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden}
.card-header{padding:.75rem 1.1rem;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.card-header h2{margin:0;font-size:.88rem;font-weight:700;color:#111827;text-transform:uppercase;letter-spacing:.04em}
.card-body{padding:1rem}
.partner-row{display:flex;align-items:center;gap:.75rem;padding:.65rem .9rem;border-bottom:1px solid #f3f4f6;cursor:pointer;transition:background .15s}
.partner-row:hover{background:#f9fafb}
.partner-row.active{background:#eff6ff;border-left:3px solid #2563eb}
.partner-row:last-child{border-bottom:none}
.partner-avatar{width:36px;height:36px;border-radius:50%;background:#dbeafe;display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;color:#1d4ed8;flex-shrink:0}
.partner-name{font-size:.85rem;font-weight:600;color:#111827}
.partner-meta{font-size:.72rem;color:#9ca3af}
.badge{font-size:.68rem;font-weight:600;padding:.12rem .4rem;border-radius:99px}
.badge-gold{background:#fef3c7;color:#92400e}
.badge-platinum{background:#ede9fe;color:#5b21b6}
.badge-pending{background:#fef9c3;color:#854d0e}
.badge-green{background:#d1fae5;color:#065f46}
.badge-red{background:#fee2e2;color:#991b1b}
.badge-grey{background:#f3f4f6;color:#6b7280}
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.25rem}
.kpi{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:.85rem 1rem}
.kpi-val{font-size:1.6rem;font-weight:800;color:#111827}
.kpi-lbl{font-size:.72rem;color:#9ca3af;margin-top:.15rem}
.kpi-sub{font-size:.72rem;color:#059669;font-weight:600}
.section-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin:.9rem 0 .45rem}
.info-row{display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid #f9fafb;font-size:.82rem}
.info-label{color:#9ca3af}
.info-val{color:#111827;font-weight:500;text-align:right;max-width:55%}
.qbr-row{display:flex;align-items:center;gap:.75rem;padding:.55rem 0;border-bottom:1px solid #f3f4f6;font-size:.82rem}
.qbr-row:last-child{border-bottom:none}
.qbr-date{width:90px;font-weight:600;color:#374151}
.qbr-status{flex:1}
.audit-row{padding:.35rem 0;border-bottom:1px solid #f9fafb;font-size:.78rem;color:#6b7280}
.audit-action{font-weight:600;color:#374151}
.progress-wrap{height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-top:.2rem}
.progress-bar{height:100%;border-radius:99px;background:#2563eb;transition:width .3s}
.tab-row{display:flex;gap:.25rem;border-bottom:1px solid #e5e7eb;margin-bottom:1rem}
.tab{padding:.5rem .85rem;font-size:.8rem;font-weight:600;color:#6b7280;border-bottom:2px solid transparent;cursor:pointer;text-decoration:none}
.tab.active{color:#2563eb;border-bottom-color:#2563eb}
.form-group{margin-bottom:.75rem}
.form-group label{display:block;font-size:.75rem;font-weight:600;color:#374151;margin-bottom:.25rem}
.form-group input,.form-group select,.form-group textarea{width:100%;border:1px solid #e5e7eb;border-radius:.4rem;padding:.4rem .6rem;font-size:.82rem;box-sizing:border-box}
.btn{padding:.35rem .75rem;border-radius:.4rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block}
.btn-primary{background:#2563eb;color:#fff}
.btn-danger{background:#dc2626;color:#fff}
.btn-ghost{background:#f3f4f6;color:#374151}
.btn-sm{padding:.22rem .55rem;font-size:.72rem}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:.85rem;padding:1.5rem;width:100%;max-width:480px;position:relative;max-height:90vh;overflow-y:auto}
.modal h3{margin:0 0 1rem;font-size:1rem}
.star-badge{display:inline-flex;align-items:center;gap:.3rem;background:#fef3c7;color:#92400e;font-size:.78rem;font-weight:700;padding:.25rem .65rem;border-radius:99px}
.empty-state{text-align:center;padding:2rem;color:#9ca3af;font-size:.83rem}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827">Strategic Partners</h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280">Platinum-tier accounts with exclusive territories and dedicated support</p>
        </div>
        <div style="display:flex;gap:.5rem">
            <button class="btn btn-primary" onclick="document.getElementById('promoteModal').classList.add('open')">+ Promote Partner</button>
            <a href="<?= SITE_URL ?>/admin" class="btn btn-ghost">← Admin</a>
        </div>
    </div>

    <?php if ($f = getFlash()): ?>
    <div style="background:<?= $f['type']==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $f['type']==='success'?'#065f46':'#991b1b' ?>;padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.85rem"><?= e($f['message']) ?></div>
    <?php endif ?>

    <?php if (empty($strategicPartners) && !$selected): ?>
    <div class="card">
        <div class="empty-state">
            <p style="font-size:2rem">⭐</p>
            <p style="font-weight:600;color:#374151">No strategic partners yet</p>
            <p>Promote top-performing Growth Partners to Strategic status to unlock territory exclusivity, dedicated account management, and quarterly business reviews.</p>
            <button class="btn btn-primary" onclick="document.getElementById('promoteModal').classList.add('open')">Promote First Strategic Partner</button>
        </div>
    </div>
    <?php else: ?>

    <div class="layout">
        <!-- Left: partner list -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h2>Strategic Partners <span style="font-weight:400;color:#9ca3af">(<?= count($strategicPartners) ?>)</span></h2>
                </div>
                <?php foreach ($strategicPartners as $sp): ?>
                <a href="?selected=<?= $sp['id'] ?>" style="text-decoration:none;display:block">
                <div class="partner-row <?= $sp['id']==$selectedId?'active':'' ?>">
                    <div class="partner-avatar"><?= strtoupper(substr($sp['user_name'],0,1)) ?></div>
                    <div style="flex:1;min-width:0">
                        <div class="partner-name"><?= e($sp['user_name']) ?></div>
                        <div class="partner-meta">
                            £<?= number_format($sp['total_commission']) ?> rev ·
                            <?= $sp['client_count'] ?> clients
                            <?php if ($sp['pending_qbrs']): ?>
                            · <span class="badge badge-pending"><?= $sp['pending_qbrs'] ?> QBR</span>
                            <?php endif ?>
                        </div>
                    </div>
                    <div>
                        <?php
                        $pct = $sp['current_target'] > 0 ? min(100, round($sp['total_commission']/$sp['current_target']*100)) : null;
                        if ($pct !== null): ?>
                        <div style="font-size:.72rem;color:#6b7280;text-align:right"><?= $pct ?>%</div>
                        <div style="width:56px"><div class="progress-wrap"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $pct>=100?'#059669':'#2563eb' ?>"></div></div></div>
                        <?php else: ?>
                        <span class="badge badge-grey" style="font-size:.65rem">No target</span>
                        <?php endif ?>
                    </div>
                </div>
                </a>
                <?php endforeach ?>
                <?php if (empty($strategicPartners)): ?>
                <div class="empty-state">No strategic partners yet.</div>
                <?php endif ?>
            </div>
        </div>

        <!-- Right: detail panel -->
        <div>
            <?php if ($selected): ?>

            <!-- KPIs -->
            <div class="kpi-row">
                <?php
                $ytd    = (float)($selected['ytd_revenue'] ?? 0);
                $target = (float)($selected['current_target'] ?? 0);
                $pctYtd = $target > 0 ? min(100, round($ytd/$target*100)) : null;
                ?>
                <div class="kpi">
                    <div class="kpi-val">£<?= number_format($ytd) ?></div>
                    <?php if ($pctYtd !== null): ?><div class="kpi-sub"><?= $pctYtd ?>% of target</div><?php endif ?>
                    <div class="kpi-lbl">YTD Revenue</div>
                </div>
                <div class="kpi">
                    <div class="kpi-val"><?= $selected['active_clients'] ?></div>
                    <div class="kpi-lbl">Active Clients</div>
                </div>
                <div class="kpi">
                    <div class="kpi-val"><?= $selected['open_leads'] ?></div>
                    <div class="kpi-lbl">Open Leads</div>
                </div>
                <div class="kpi">
                    <div class="kpi-val"><?= $selected['performance_score'] ?? '—' ?></div>
                    <div class="kpi-lbl">Performance Score</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="card">
                <div style="padding:.75rem 1.1rem;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between">
                    <div style="display:flex;align-items:center;gap:.65rem">
                        <div class="partner-avatar" style="width:44px;height:44px;font-size:1rem"><?= strtoupper(substr($selected['user_name'],0,1)) ?></div>
                        <div>
                            <div style="font-weight:700;color:#111827;display:flex;align-items:center;gap:.5rem">
                                <?= e($selected['user_name']) ?>
                                <span class="star-badge">⭐ Strategic</span>
                            </div>
                            <div style="font-size:.75rem;color:#9ca3af"><?= e($selected['user_email']) ?></div>
                        </div>
                    </div>
                    <div style="display:flex;gap:.4rem">
                        <button class="btn btn-ghost btn-sm" onclick="openEditModal()">Edit</button>
                        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('qbrModal').classList.add('open')">+ QBR</button>
                        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('targetModal').classList.add('open')">Set Target</button>
                    </div>
                </div>

                <div style="padding:.5rem 1.1rem 0">
                    <div class="tab-row">
                        <a class="tab active" id="tab-overview" onclick="showTab('overview')" href="#">Overview</a>
                        <a class="tab" id="tab-qbr" onclick="showTab('qbr')" href="#">QBRs (<?= count($qbrs) ?>)</a>
                        <a class="tab" id="tab-targets" onclick="showTab('targets')" href="#">Targets</a>
                        <a class="tab" id="tab-audit" onclick="showTab('audit')" href="#">Activity</a>
                    </div>
                </div>

                <!-- Overview tab -->
                <div id="tab-content-overview" class="card-body">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                        <div>
                            <div class="section-title">Account Details</div>
                            <div class="info-row"><span class="info-label">Account Manager</span><span class="info-val"><?= e($selected['account_manager_name'] ?? '—') ?></span></div>
                            <div class="info-row"><span class="info-label">Primary Region</span><span class="info-val"><?= e($selected['primary_region_name'] ?? '—') ?></span></div>
                            <div class="info-row"><span class="info-label">Exclusive Territory</span><span class="info-val"><?= e($selected['exclusive_territory'] ?? '—') ?></span></div>
                            <div class="info-row"><span class="info-label">Tier</span><span class="info-val"><span class="badge badge-platinum"><?= ucfirst($selected['tier'] ?? 'standard') ?></span></span></div>
                            <div class="info-row"><span class="info-label">Revenue Commitment</span><span class="info-val"><?= $selected['revenue_commitment'] ? '£' . number_format($selected['revenue_commitment']) . '/yr' : '—' ?></span></div>
                            <div class="info-row"><span class="info-label">Strategic Since</span><span class="info-val"><?= $selected['strategic_since'] ? date('d M Y', strtotime($selected['strategic_since'])) : '—' ?></span></div>
                        </div>
                        <div>
                            <div class="section-title">Partner Details</div>
                            <div class="info-row"><span class="info-label">Phone</span><span class="info-val"><?= e($selected['phone'] ?? '—') ?></span></div>
                            <div class="info-row"><span class="info-label">Business Name</span><span class="info-val"><?= e($selected['business_name'] ?? '—') ?></span></div>
                            <div class="info-row"><span class="info-label">Member Since</span><span class="info-val"><?= date('d M Y', strtotime($selected['member_since'])) ?></span></div>
                            <div class="info-row"><span class="info-label">Monthly Revenue</span><span class="info-val">£<?= number_format($selected['monthly_revenue'] ?? 0) ?></span></div>
                            <div class="info-row"><span class="info-label">Avg Rating</span><span class="info-val"><?= number_format($selected['average_rating'] ?? 0, 1) ?>/5</span></div>
                        </div>
                    </div>
                    <?php if ($selected['strategic_note']): ?>
                    <div style="margin-top:1rem;background:#fffbeb;border:1px solid #fde68a;border-radius:.5rem;padding:.65rem .85rem;font-size:.82rem;color:#78350f">
                        <strong>Note:</strong> <?= e($selected['strategic_note']) ?>
                    </div>
                    <?php endif ?>

                    <!-- YTD progress bar -->
                    <?php if ($target > 0): ?>
                    <div style="margin-top:1rem">
                        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#6b7280;margin-bottom:.3rem">
                            <span>YTD Progress vs Target</span>
                            <span>£<?= number_format($ytd) ?> / £<?= number_format($target) ?></span>
                        </div>
                        <div class="progress-wrap" style="height:12px">
                            <div class="progress-bar" style="width:<?= $pctYtd ?>%;background:<?= $pctYtd>=100?'#059669':($pctYtd>=75?'#f59e0b':'#2563eb') ?>"></div>
                        </div>
                    </div>
                    <?php endif ?>
                </div>

                <!-- QBR tab -->
                <div id="tab-content-qbr" class="card-body" style="display:none">
                    <?php if (!$qbrs): ?>
                    <div class="empty-state">No QBRs scheduled yet.<br><button class="btn btn-primary btn-sm" style="margin-top:.5rem" onclick="document.getElementById('qbrModal').classList.add('open')">Schedule First QBR</button></div>
                    <?php else: ?>
                    <?php foreach ($qbrs as $qbr): ?>
                    <div class="qbr-row">
                        <div class="qbr-date"><?= date('d M Y', strtotime($qbr['scheduled_at'])) ?></div>
                        <div class="qbr-status">
                            <?php
                            $cls = ['scheduled'=>'badge-pending','completed'=>'badge-green','cancelled'=>'badge-grey'][$qbr['status']] ?? 'badge-grey';
                            ?>
                            <span class="badge <?= $cls ?>"><?= ucfirst($qbr['status']) ?></span>
                            <?php if ($qbr['outcome']): ?>
                            <div style="font-size:.72rem;color:#6b7280;margin-top:.15rem"><?= e(substr($qbr['outcome'],0,80)) ?><?= strlen($qbr['outcome'])>80?'…':'' ?></div>
                            <?php endif ?>
                        </div>
                        <?php if ($qbr['status'] === 'scheduled'): ?>
                        <div style="display:flex;gap:.3rem">
                            <form method="post" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="complete_qbr">
                                <input type="hidden" name="qbr_id" value="<?= $qbr['id'] ?>">
                                <input type="hidden" name="outcome" value="">
                                <button class="btn btn-ghost btn-sm" type="submit">✓ Done</button>
                            </form>
                            <form method="post" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="cancel_qbr">
                                <input type="hidden" name="qbr_id" value="<?= $qbr['id'] ?>">
                                <input type="hidden" name="outcome" value="">
                                <button class="btn btn-ghost btn-sm" style="color:#dc2626" type="submit">Cancel</button>
                            </form>
                        </div>
                        <?php endif ?>
                    </div>
                    <?php endforeach ?>
                    <?php endif ?>
                </div>

                <!-- Targets tab -->
                <div id="tab-content-targets" class="card-body" style="display:none">
                    <?php if (!$targets): ?>
                    <div class="empty-state">No revenue targets set.<br><button class="btn btn-primary btn-sm" style="margin-top:.5rem" onclick="document.getElementById('targetModal').classList.add('open')">Set Target</button></div>
                    <?php else: ?>
                    <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                        <thead><tr style="font-size:.72rem;color:#9ca3af;text-transform:uppercase">
                            <th style="text-align:left;padding:.35rem .5rem;border-bottom:2px solid #e5e7eb">Period</th>
                            <th style="text-align:right;padding:.35rem .5rem;border-bottom:2px solid #e5e7eb">Target</th>
                            <th style="text-align:right;padding:.35rem .5rem;border-bottom:2px solid #e5e7eb">Set</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($targets as $t): ?>
                        <tr style="border-bottom:1px solid #f3f4f6">
                            <td style="padding:.45rem .5rem;font-weight:600"><?= e($t['period']) ?></td>
                            <td style="padding:.45rem .5rem;text-align:right">£<?= number_format($t['target_amount']) ?></td>
                            <td style="padding:.45rem .5rem;text-align:right;color:#9ca3af"><?= date('d M Y', strtotime($t['created_at'])) ?></td>
                        </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                    <?php endif ?>
                </div>

                <!-- Activity tab -->
                <div id="tab-content-audit" class="card-body" style="display:none">
                    <?php if (!$recentAudit): ?>
                    <div class="empty-state">No activity logged yet.</div>
                    <?php else: ?>
                    <?php foreach ($recentAudit as $log): ?>
                    <div class="audit-row">
                        <span class="audit-action"><?= e(str_replace('_',' ',ucfirst($log['action']))) ?></span>
                        <?php if ($log['note']): ?> — <?= e(substr($log['note'],0,80)) ?><?php endif ?>
                        <span style="float:right;color:#d1d5db"><?= date('d M H:i', strtotime($log['created_at'])) ?></span>
                    </div>
                    <?php endforeach ?>
                    <?php endif ?>
                </div>
            </div>

            <?php else: ?>
            <div class="card"><div class="empty-state">Select a strategic partner to view details.</div></div>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>
</div>

<!-- Promote modal -->
<div class="modal-overlay" id="promoteModal">
    <div class="modal">
        <h3>Promote to Strategic Partner</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_strategic">
            <input type="hidden" name="is_strategic" value="1">
            <div class="form-group">
                <label>Partner *</label>
                <select name="partner_id" required>
                    <option value="">— Select partner —</option>
                    <?php foreach ($eligiblePartners as $ep): ?>
                    <option value="<?= $ep['id'] ?>">
                        <?= e($ep['name']) ?> — <?= ucfirst($ep['tier']) ?> · £<?= number_format($ep['monthly_revenue']) ?>/mo
                    </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="form-group">
                <label>Exclusive Territory</label>
                <input type="text" name="exclusive_territory" placeholder="e.g. Greater Manchester, Sheffield City Region">
            </div>
            <div class="form-group">
                <label>Annual Revenue Commitment (£)</label>
                <input type="number" name="revenue_commitment" min="0" step="500" placeholder="e.g. 25000">
            </div>
            <div class="form-group">
                <label>Assign Account Manager</label>
                <select name="account_manager_id">
                    <option value="">— None —</option>
                    <?php foreach ($adminUsers as $au): ?>
                    <option value="<?= $au['id'] ?>"><?= e($au['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="form-group">
                <label>Internal Note</label>
                <textarea name="strategic_note" rows="2" placeholder="Reason for promotion, special conditions…"></textarea>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('promoteModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Promote to Strategic</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit strategic settings modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <h3>Edit Strategic Settings</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_strategic">
            <input type="hidden" name="partner_id" value="<?= $selectedId ?>">
            <input type="hidden" name="is_strategic" value="1">
            <div class="form-group">
                <label>Exclusive Territory</label>
                <input type="text" name="exclusive_territory" id="edit_territory" value="<?= e($selected['exclusive_territory'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Annual Revenue Commitment (£)</label>
                <input type="number" name="revenue_commitment" id="edit_commitment" min="0" step="500" value="<?= $selected['revenue_commitment'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>Assign Account Manager</label>
                <select name="account_manager_id" id="edit_am">
                    <option value="">— None —</option>
                    <?php foreach ($adminUsers as $au): ?>
                    <option value="<?= $au['id'] ?>" <?= ($selected['account_manager_id'] ?? '') == $au['id'] ? 'selected' : '' ?>><?= e($au['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="form-group">
                <label>Internal Note</label>
                <textarea name="strategic_note" rows="2"><?= e($selected['strategic_note'] ?? '') ?></textarea>
            </div>
            <div style="margin-top:.5rem;padding:.65rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:.4rem;font-size:.78rem;color:#9a3412">
                To <strong>remove</strong> strategic status, use the demote action below.
            </div>
            <div style="display:flex;gap:.5rem;justify-content:space-between;margin-top:1rem">
                <form method="post" style="display:inline" onsubmit="return confirm('Remove strategic status from this partner?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_strategic">
                    <input type="hidden" name="partner_id" value="<?= $selectedId ?>">
                    <input type="hidden" name="is_strategic" value="0">
                    <button type="submit" class="btn btn-danger btn-sm">Remove Strategic Status</button>
                </form>
                <div style="display:flex;gap:.4rem">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('editModal').classList.remove('open')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Schedule QBR modal -->
<div class="modal-overlay" id="qbrModal">
    <div class="modal">
        <h3>Schedule Quarterly Business Review</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="schedule_qbr">
            <input type="hidden" name="partner_id" value="<?= $selectedId ?>">
            <div class="form-group">
                <label>QBR Date & Time *</label>
                <input type="datetime-local" name="qbr_date" required min="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <div class="form-group">
                <label>Agenda / Notes</label>
                <textarea name="qbr_notes" rows="3" placeholder="Key topics, objectives, pre-reads…"></textarea>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('qbrModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Schedule QBR</button>
            </div>
        </form>
    </div>
</div>

<!-- Set revenue target modal -->
<div class="modal-overlay" id="targetModal">
    <div class="modal">
        <h3>Set Revenue Target</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_target">
            <input type="hidden" name="partner_id" value="<?= $selectedId ?>">
            <div class="form-group">
                <label>Period (Year) *</label>
                <input type="text" name="target_period" value="<?= date('Y') ?>" placeholder="2026" required>
            </div>
            <div class="form-group">
                <label>Annual Revenue Target (£) *</label>
                <input type="number" name="annual_target" min="0" step="500" placeholder="e.g. 30000" required>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('targetModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Target</button>
            </div>
        </form>
    </div>
</div>

<script>
function showTab(name) {
    ['overview','qbr','targets','audit'].forEach(t => {
        document.getElementById('tab-content-'+t).style.display = t===name?'':'none';
        const tab = document.getElementById('tab-'+t);
        if (tab) tab.classList.toggle('active', t===name);
    });
    return false;
}
function openEditModal() {
    document.getElementById('editModal').classList.add('open');
}
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});
document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', e => e.preventDefault()));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
