<?php
/**
 * admin/quality-monitoring.php — Partner Quality Monitoring (Task 60)
 *
 * Quality assurance dashboard: client satisfaction scores, complaint tracking,
 * SLA compliance, response time metrics, and flagged partners.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
requireAdmin();

// ── POST handlers ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action    = $_POST['action'] ?? '';
    $partnerId = (int)($_POST['partner_id'] ?? 0);

    // Resolve complaint
    if ($action === 'resolve_complaint') {
        $complaintId = (int)($_POST['complaint_id'] ?? 0);
        $resolution  = trim($_POST['resolution'] ?? '');
        if ($complaintId && $resolution) {
            $pdo->prepare("
                UPDATE partner_complaints SET status='resolved', resolution=?, resolved_at=NOW(), resolved_by=?
                WHERE id=?
            ")->execute([$resolution, $_SESSION['user_id'], $complaintId]);
            partnerAuditLog($partnerId, 'complaint_resolved', "Complaint #{$complaintId} resolved");
            setFlash('success', 'Complaint marked as resolved.');
        }
        redirect(SITE_URL . '/admin/quality-monitoring');
    }

    // Flag partner for review
    if ($action === 'flag_partner' && $partnerId) {
        $reason = trim($_POST['reason'] ?? '');
        $pdo->prepare("UPDATE partner_profiles SET flagged=1, flag_reason=?, flagged_at=NOW() WHERE id=?")
            ->execute([$reason, $partnerId]);
        $uid = (int)$pdo->query("SELECT user_id FROM partner_profiles WHERE id={$partnerId}")->fetchColumn();
        pushNotification($uid, 'Your account has been flagged for review. Please contact support.', SITE_URL . '/partner/dashboard');
        partnerAuditLog($partnerId, 'partner_flagged', "Flagged: {$reason}");
        setFlash('success', 'Partner flagged for review.');
        redirect(SITE_URL . '/admin/quality-monitoring');
    }

    // Unflag partner
    if ($action === 'unflag_partner' && $partnerId) {
        $pdo->prepare("UPDATE partner_profiles SET flagged=0, flag_reason=NULL, flagged_at=NULL WHERE id=?")->execute([$partnerId]);
        partnerAuditLog($partnerId, 'partner_unflagged', "Flag removed by admin");
        setFlash('success', 'Partner unflagged.');
        redirect(SITE_URL . '/admin/quality-monitoring');
    }

    redirect(SITE_URL . '/admin/quality-monitoring');
}

// ── Quality KPIs ──────────────────────────────────────────
$kpis = $pdo->query("
    SELECT
        (SELECT AVG(rating) FROM partner_feedback WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS avg_rating_30d,
        (SELECT COUNT(*) FROM partner_complaints WHERE status='open') AS open_complaints,
        (SELECT COUNT(*) FROM partner_complaints WHERE status='resolved' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS resolved_30d,
        (SELECT COUNT(*) FROM partner_profiles WHERE flagged=1) AS flagged_count,
        (SELECT COUNT(*) FROM partner_profiles WHERE status='active') AS active_partners,
        (SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, first_response_at)) FROM partner_leads WHERE first_response_at IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS avg_response_hours
")->fetch();

// ── Rating distribution ───────────────────────────────────
$ratingDist = $pdo->query("
    SELECT rating, COUNT(*) AS cnt
    FROM partner_feedback
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY rating
    ORDER BY rating DESC
")->fetchAll(\PDO::FETCH_KEY_PAIR);
$ratingMax = $ratingDist ? max(array_values($ratingDist)) : 1;

// ── Open complaints ───────────────────────────────────────
$openComplaints = $pdo->query("
    SELECT pc.*, pp.display_name AS partner_name, u.name AS complainant_name, u.email AS complainant_email
    FROM partner_complaints pc
    LEFT JOIN partner_profiles pp ON pp.id = pc.partner_id
    LEFT JOIN users u ON u.id = pc.complainant_user_id
    WHERE pc.status = 'open'
    ORDER BY pc.created_at ASC
")->fetchAll();

// ── Partner satisfaction scores (sorted worst first) ──────
$partnerSat = $pdo->query("
    SELECT pp.id, pp.display_name, pp.tier, pp.flagged, pp.status,
           AVG(pf.rating) AS avg_rating,
           COUNT(pf.id) AS rating_count,
           COUNT(pc.id) AS complaint_count
    FROM partner_profiles pp
    LEFT JOIN partner_feedback pf ON pf.partner_id = pp.id AND pf.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    LEFT JOIN partner_complaints pc ON pc.partner_id = pp.id AND pc.status = 'open'
    WHERE pp.status IN ('active','suspended')
    GROUP BY pp.id
    ORDER BY avg_rating ASC, complaint_count DESC
    LIMIT 25
")->fetchAll();

// ── SLA compliance (response within 24h) ─────────────────
$slaData = $pdo->query("
    SELECT pp.display_name,
           COUNT(pl.id) AS total_leads,
           SUM(CASE WHEN pl.first_response_at IS NOT NULL AND TIMESTAMPDIFF(HOUR, pl.created_at, pl.first_response_at) <= 24 THEN 1 ELSE 0 END) AS within_sla,
           AVG(TIMESTAMPDIFF(HOUR, pl.created_at, pl.first_response_at)) AS avg_hours
    FROM partner_profiles pp
    LEFT JOIN partner_leads pl ON pl.partner_id = pp.id
        AND pl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND pl.first_response_at IS NOT NULL
    WHERE pp.status = 'active'
    GROUP BY pp.id
    HAVING total_leads > 0
    ORDER BY avg_hours DESC
    LIMIT 15
")->fetchAll();

// ── Flagged partners ──────────────────────────────────────
$flaggedPartners = $pdo->query("
    SELECT pp.id, pp.display_name, pp.flag_reason, pp.flagged_at, pp.tier, u.email
    FROM partner_profiles pp
    JOIN users u ON u.id = pp.user_id
    WHERE pp.flagged = 1
    ORDER BY pp.flagged_at DESC
")->fetchAll();

$flash     = getFlash();
$pageTitle = 'Quality Monitoring';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:1200px;margin:0 auto;padding:1.5rem}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;margin-bottom:1.5rem}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:.85rem 1rem;text-align:center}
.stat-val{font-size:1.6rem;font-weight:800;color:#111827}
.stat-lbl{font-size:.7rem;color:#9ca3af;margin-top:.1rem}
.stat-val.red{color:#dc2626}
.stat-val.green{color:#059669}
.stat-val.amber{color:#d97706}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;margin-bottom:1.25rem}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden;margin-bottom:1.25rem}
.card-header{padding:.65rem 1rem;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.card-header h2{margin:0;font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280}
.complaint-row{padding:.7rem 1rem;border-bottom:1px solid #f3f4f6;font-size:.82rem}
.complaint-row:last-child{border-bottom:none}
.complaint-subject{font-weight:600;color:#111827;margin-bottom:.2rem}
.complaint-meta{font-size:.72rem;color:#9ca3af}
.sat-row{display:flex;align-items:center;gap:.5rem;padding:.4rem .75rem;border-bottom:1px solid #f3f4f6;font-size:.8rem}
.sat-row:last-child{border-bottom:none}
.sat-name{flex:1;font-weight:500;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.star-bar{width:80px;height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden}
.star-fill{height:100%;background:#f59e0b;border-radius:99px}
.sla-table{width:100%;border-collapse:collapse;font-size:.8rem}
.sla-table th{text-align:left;padding:.45rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#9ca3af;border-bottom:2px solid #e5e7eb}
.sla-table td{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6}
.sla-table tr:hover td{background:#f9fafb}
.rating-bar{display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem;font-size:.8rem}
.rb-label{width:30px;text-align:right;color:#374151;font-weight:600}
.rb-track{flex:1;height:14px;background:#f3f4f6;border-radius:.2rem;overflow:hidden}
.rb-fill{height:100%;background:#f59e0b;border-radius:.2rem}
.rb-cnt{width:35px;text-align:right;color:#9ca3af}
.flag-row{display:flex;align-items:center;gap:.6rem;padding:.55rem .85rem;border-bottom:1px solid #f3f4f6;font-size:.82rem}
.flag-row:last-child{border-bottom:none}
.flag-name{flex:1;font-weight:600;color:#dc2626}
.flag-reason{font-size:.75rem;color:#6b7280}
.badge{font-size:.68rem;font-weight:600;padding:.12rem .4rem;border-radius:99px}
.badge-red{background:#fee2e2;color:#991b1b}
.badge-green{background:#d1fae5;color:#065f46}
.badge-amber{background:#fef3c7;color:#92400e}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-ghost{background:#f3f4f6;color:#374151}
.btn-danger{background:#fee2e2;color:#991b1b}
.btn-sm{padding:.25rem .55rem;font-size:.72rem}
.btn:hover{opacity:.9}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:.75rem;padding:1.5rem;max-width:460px;width:90%}
.modal h3{margin:0 0 1rem;font-size:1rem}
.form-group{margin-bottom:.7rem}
.form-group label{display:block;font-size:.75rem;font-weight:700;color:#374151;margin-bottom:.3rem}
.form-group input,.form-group select,.form-group textarea{width:100%;border:1px solid #e5e7eb;border-radius:.4rem;padding:.42rem .65rem;font-size:.83rem;box-sizing:border-box;font-family:inherit}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827">Quality Monitoring</h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280">Client satisfaction, complaints and SLA compliance</p>
        </div>
        <a href="<?= SITE_URL ?>/admin" class="btn btn-ghost">← Admin</a>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <!-- KPIs -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-val <?= ($kpis['avg_rating_30d']??0)<3.5?'red':($kpis['avg_rating_30d']>=4?'green':'amber') ?>">
                <?= $kpis['avg_rating_30d'] ? number_format($kpis['avg_rating_30d'],1) : '—' ?>
            </div>
            <div class="stat-lbl">Avg Rating (30d)</div>
        </div>
        <div class="stat-card">
            <div class="stat-val <?= $kpis['open_complaints']>0?'red':'' ?>"><?= $kpis['open_complaints'] ?></div>
            <div class="stat-lbl">Open Complaints</div>
        </div>
        <div class="stat-card">
            <div class="stat-val green"><?= $kpis['resolved_30d'] ?></div>
            <div class="stat-lbl">Resolved (30d)</div>
        </div>
        <div class="stat-card">
            <div class="stat-val <?= $kpis['flagged_count']>0?'red':'' ?>"><?= $kpis['flagged_count'] ?></div>
            <div class="stat-lbl">Flagged Partners</div>
        </div>
        <div class="stat-card">
            <div class="stat-val <?= ($kpis['avg_response_hours']??0)>24?'red':($kpis['avg_response_hours']<=12?'green':'amber') ?>">
                <?= $kpis['avg_response_hours'] ? round($kpis['avg_response_hours'],1).'h' : '—' ?>
            </div>
            <div class="stat-lbl">Avg Response Time</div>
        </div>
    </div>

    <div class="grid-2">
        <!-- Rating distribution -->
        <div class="card">
            <div class="card-header"><h2>Rating Distribution (90d)</h2></div>
            <div style="padding:.75rem 1rem">
                <?php for ($r=5;$r>=1;$r--): $cnt = $ratingDist[$r] ?? 0; ?>
                <div class="rating-bar">
                    <div class="rb-label"><?= $r ?>★</div>
                    <div class="rb-track"><div class="rb-fill" style="width:<?= $ratingMax>0?round($cnt/$ratingMax*100):0 ?>%"></div></div>
                    <div class="rb-cnt"><?= $cnt ?></div>
                </div>
                <?php endfor ?>
            </div>
        </div>

        <!-- Partner satisfaction ranking -->
        <div class="card">
            <div class="card-header"><h2>Partner Satisfaction (Lowest First)</h2></div>
            <?php if (!$partnerSat): ?>
            <div style="padding:1.5rem;text-align:center;color:#9ca3af;font-size:.83rem">No feedback data yet.</div>
            <?php else: ?>
            <?php foreach (array_slice($partnerSat,0,10) as $ps): ?>
            <div class="sat-row">
                <?php if ($ps['flagged']): ?><span title="Flagged" style="color:#dc2626">🚩</span><?php endif ?>
                <div class="sat-name"><?= e($ps['display_name']) ?></div>
                <?php $avgR = round($ps['avg_rating'] ?? 0, 1); ?>
                <div class="star-bar"><div class="star-fill" style="width:<?= $avgR/5*100 ?>%"></div></div>
                <div style="width:36px;text-align:right;font-weight:700;font-size:.82rem;color:<?= $avgR<3?'#dc2626':($avgR>=4?'#059669':'#d97706') ?>"><?= $avgR ?: '—' ?></div>
                <div style="font-size:.72rem;color:#9ca3af;width:28px;text-align:right"><?= $ps['complaint_count'] ?>⚠</div>
                <button onclick="openFlagModal(<?= $ps['id'] ?>, '<?= e(addslashes($ps['display_name'])) ?>', <?= $ps['flagged'] ?>)" class="btn btn-sm <?= $ps['flagged']?'btn-ghost':'btn-danger' ?>"><?= $ps['flagged']?'Unflag':'Flag' ?></button>
            </div>
            <?php endforeach ?>
            <?php endif ?>
        </div>
    </div>

    <!-- Open complaints -->
    <?php if ($openComplaints): ?>
    <div class="card">
        <div class="card-header">
            <h2>Open Complaints (<?= count($openComplaints) ?>)</h2>
        </div>
        <?php foreach ($openComplaints as $c): ?>
        <div class="complaint-row">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem">
                <div>
                    <div class="complaint-subject"><?= e($c['subject'] ?? 'Complaint') ?></div>
                    <div class="complaint-meta">
                        Against: <strong><?= e($c['partner_name'] ?? '—') ?></strong> ·
                        From: <?= e($c['complainant_name'] ?? '—') ?> (<?= e($c['complainant_email'] ?? '') ?>) ·
                        <?= date('d M Y', strtotime($c['created_at'])) ?>
                    </div>
                    <?php if ($c['description'] ?? ''): ?>
                    <p style="margin:.3rem 0 0;font-size:.8rem;color:#374151"><?= nl2br(e(mb_strimwidth($c['description'], 0, 200, '…'))) ?></p>
                    <?php endif ?>
                </div>
                <button onclick="openResolveModal(<?= $c['id'] ?>, <?= $c['partner_id'] ?>)" class="btn btn-primary btn-sm">Resolve</button>
            </div>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <!-- SLA compliance -->
    <?php if ($slaData): ?>
    <div class="card">
        <div class="card-header"><h2>SLA Compliance — Lead Response (30d)</h2><span style="font-size:.72rem;color:#9ca3af">SLA = 24h first response</span></div>
        <div style="overflow-x:auto">
        <table class="sla-table">
            <thead><tr><th>Partner</th><th>Leads</th><th>Within SLA</th><th>SLA %</th><th>Avg Hours</th></tr></thead>
            <tbody>
            <?php foreach ($slaData as $row):
                $slaPct = $row['total_leads']>0 ? round($row['within_sla']/$row['total_leads']*100) : 0; ?>
            <tr>
                <td style="font-weight:600"><?= e($row['display_name']) ?></td>
                <td><?= $row['total_leads'] ?></td>
                <td><?= $row['within_sla'] ?></td>
                <td>
                    <span style="color:<?= $slaPct>=90?'#059669':($slaPct>=70?'#d97706':'#dc2626') ?>;font-weight:700"><?= $slaPct ?>%</span>
                </td>
                <td style="color:<?= $row['avg_hours']<=12?'#059669':($row['avg_hours']<=24?'#d97706':'#dc2626') ?>">
                    <?= round($row['avg_hours'],1) ?>h
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif ?>

    <!-- Flagged partners -->
    <?php if ($flaggedPartners): ?>
    <div class="card">
        <div class="card-header"><h2>Flagged Partners (<?= count($flaggedPartners) ?>)</h2></div>
        <?php foreach ($flaggedPartners as $fp): ?>
        <div class="flag-row">
            <div style="flex:1">
                <div class="flag-name">🚩 <?= e($fp['display_name']) ?></div>
                <div class="flag-reason"><?= e($fp['email']) ?> · Flagged: <?= date('d M Y', strtotime($fp['flagged_at'])) ?></div>
                <?php if ($fp['flag_reason']): ?><div style="font-size:.78rem;color:#dc2626;margin-top:.15rem"><?= e($fp['flag_reason']) ?></div><?php endif ?>
            </div>
            <form method="post" action="">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="unflag_partner">
                <input type="hidden" name="partner_id" value="<?= $fp['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Remove flag from this partner?')">Unflag</button>
            </form>
            <a href="<?= SITE_URL ?>/admin/partner-performance?id=<?= $fp['id'] ?>" class="btn btn-ghost btn-sm">View</a>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</div>

<!-- Resolve complaint modal -->
<div class="modal-overlay" id="resolveModal">
    <div class="modal">
        <h3>Resolve Complaint</h3>
        <form method="post" action="">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="resolve_complaint">
            <input type="hidden" name="complaint_id" id="resolveComplaintId" value="">
            <input type="hidden" name="partner_id" id="resolvePartnerId" value="">
            <div class="form-group">
                <label>Resolution *</label>
                <textarea name="resolution" rows="4" required placeholder="Describe how this complaint was resolved…"></textarea>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end">
                <button type="button" onclick="document.getElementById('resolveModal').classList.remove('open')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary">Mark Resolved</button>
            </div>
        </form>
    </div>
</div>

<!-- Flag partner modal -->
<div class="modal-overlay" id="flagModal">
    <div class="modal">
        <h3 id="flagModalTitle">Flag Partner</h3>
        <form method="post" action="">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="flagAction" value="flag_partner">
            <input type="hidden" name="partner_id" id="flagPartnerId" value="">
            <div class="form-group" id="flagReasonGroup">
                <label>Reason for flagging *</label>
                <textarea name="reason" rows="3" placeholder="Describe the issue…"></textarea>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end">
                <button type="button" onclick="document.getElementById('flagModal').classList.remove('open')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-danger" id="flagSubmitBtn">Flag Partner</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResolveModal(cid, pid) {
    document.getElementById('resolveComplaintId').value = cid;
    document.getElementById('resolvePartnerId').value = pid;
    document.getElementById('resolveModal').classList.add('open');
}
function openFlagModal(pid, name, isFlagged) {
    document.getElementById('flagPartnerId').value = pid;
    document.getElementById('flagAction').value = isFlagged ? 'unflag_partner' : 'flag_partner';
    document.getElementById('flagModalTitle').textContent = isFlagged ? 'Unflag: ' + name : 'Flag: ' + name;
    document.getElementById('flagReasonGroup').style.display = isFlagged ? 'none' : 'block';
    document.getElementById('flagSubmitBtn').textContent = isFlagged ? 'Confirm Unflag' : 'Flag Partner';
    document.getElementById('flagSubmitBtn').className = 'btn ' + (isFlagged ? 'btn-ghost' : 'btn-danger');
    document.getElementById('flagModal').classList.add('open');
}
['resolveModal','flagModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
