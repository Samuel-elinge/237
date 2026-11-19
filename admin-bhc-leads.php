<?php
/**
 * admin-bhc-leads.php
 *
 * BHC Lead Pipeline — Phase 3
 * Full enquiry pipeline view with filtering, follow-up scheduling,
 * conversion tracking and Growth Partner attribution.
 *
 * Access: Super Admin + Operations Manager (read/manage).
 *         Operations Manager cannot delete records or view financials.
 */
defined('IN_237BIZ') or define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/admin-header.php';

/* ─── Auth ─────────────────────────────────────────────────────────────── */
if (empty($_SESSION['staff_id'])) {
    http_response_code(403);
    echo '<p class="p-4 text-red-400">Access denied — staff login required.</p>';
    exit;
}

$isSuperAdmin   = !empty($_SESSION['is_admin']);
$isOpsManager   = !$isSuperAdmin && !empty($_SESSION['is_ops_manager']);
$canManage      = $isSuperAdmin || $isOpsManager;

if (!$canManage) {
    http_response_code(403);
    echo '<p class="p-4 text-red-400">Access denied — insufficient permissions.</p>';
    exit;
}

/* ─── CSRF token ─────────────────────────────────────────────────────────*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ─── Helpers ───────────────────────────────────────────────────────────── */
function statusLabel(string $s, bool $html = true): string {
    $map = [
        'new'                 => ['New',                 '#3b82f6'],
        'awaiting_assignment' => ['Awaiting Assignment', '#f59e0b'],
        'assigned'            => ['Assigned',            '#8b5cf6'],
        'contacted'           => ['Contacted',           '#06b6d4'],
        'quotation_provided'  => ['Quotation Sent',      '#f97316'],
        'in_progress'         => ['In Progress',         '#10b981'],
        'converted'           => ['Converted',           '#22c55e'],
        'not_converted'       => ['Not Converted',       '#ef4444'],
        'closed'              => ['Closed',              '#6b7280'],
    ];
    [$label, $color] = $map[$s] ?? [ucfirst(str_replace('_', ' ', $s)), '#6b7280'];
    if (!$html) return $label;
    return '<span style="display:inline-block;padding:.2em .6em;border-radius:4px;font-size:.75rem;font-weight:600;background:' . $color . '22;color:' . $color . '">' . htmlspecialchars($label) . '</span>';
}

$statusList = [
    'new','awaiting_assignment','assigned','contacted',
    'quotation_provided','in_progress','converted','not_converted','closed'
];

/* ─── POST actions ───────────────────────────────────────────────────────── */
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $msg = '<p class="admin-notice error">Invalid security token. Please refresh and try again.</p>';
    } else {
        $action = $_POST['action'] ?? '';
        $db     = db();

        /* Update status */
        if ($action === 'update_status' && $canManage) {
            $eid    = (int)($_POST['enquiry_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if ($eid && in_array($status, $statusList)) {
                $db->prepare('UPDATE bhc_enquiries SET status=? WHERE id=?')
                   ->execute([$status, $eid]);
                // log the change
                $db->prepare('INSERT INTO bhc_enquiry_followups (enquiry_id,actor_id,actor_type,activity,summary) VALUES (?,?,?,?,?)')
                   ->execute([$eid, $_SESSION['staff_id'], 'staff', 'status_change', 'Status changed to: ' . $status]);
                $msg = '<p class="admin-notice success">Status updated.</p>';
            }
        }

        /* Assign provider */
        if ($action === 'assign_provider' && $canManage) {
            $eid = (int)($_POST['enquiry_id'] ?? 0);
            $pid = (int)($_POST['provider_id'] ?? 0);
            if ($eid) {
                $db->prepare('UPDATE bhc_enquiries SET assigned_provider_id=?,assigned_by=?,assigned_at=NOW(),status=IF(status="new","assigned",status) WHERE id=?')
                   ->execute([$pid ?: null, $_SESSION['staff_id'], $eid]);
                $msg = '<p class="admin-notice success">Provider assignment saved.</p>';
            }
        }

        /* Log follow-up */
        if ($action === 'add_followup' && $canManage) {
            $eid     = (int)($_POST['enquiry_id'] ?? 0);
            $activ   = $_POST['activity']  ?? 'note';
            $summary = trim($_POST['summary'] ?? '');
            $detail  = trim($_POST['detail'] ?? '');
            $outcome = trim($_POST['outcome'] ?? '');
            $fuAt    = !empty($_POST['follow_up_at']) ? $_POST['follow_up_at'] : null;
            if ($eid && $summary !== '') {
                $db->prepare('INSERT INTO bhc_enquiry_followups (enquiry_id,actor_id,actor_type,activity,summary,detail,outcome,follow_up_at) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute([$eid, $_SESSION['staff_id'], 'staff', $activ, $summary, $detail ?: null, $outcome ?: null, $fuAt]);
                $msg = '<p class="admin-notice success">Follow-up activity logged.</p>';
            }
        }

        /* Mark conversion */
        if ($action === 'mark_converted' && $isSuperAdmin) {
            $eid   = (int)($_POST['enquiry_id'] ?? 0);
            $val   = !empty($_POST['conversion_value']) ? (float)$_POST['conversion_value'] : null;
            $curr  = preg_replace('/[^A-Z]/', '', strtoupper($_POST['currency'] ?? 'GBP'));
            $notes = trim($_POST['notes'] ?? '');
            if ($eid) {
                // Insert conversion event
                $db->prepare('INSERT INTO bhc_conversion_events (enquiry_id,service_id,provider_id,converted_by,conversion_value,currency_code,notes) SELECT id,service_id,assigned_provider_id,?,?,?,? FROM bhc_enquiries WHERE id=?')
                   ->execute([$_SESSION['staff_id'], $val, $curr ?: 'GBP', $notes ?: null, $eid]);
                $db->prepare('UPDATE bhc_enquiries SET status="converted" WHERE id=?')
                   ->execute([$eid]);
                $msg = '<p class="admin-notice success">Enquiry marked as converted.</p>';
            }
        }
    }
}

/* ─── Filters ────────────────────────────────────────────────────────────── */
$db      = db();
$fStatus = $_GET['status'] ?? '';
$fGP     = (int)($_GET['gp_id'] ?? 0);
$fSearch = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$where  = ['1=1'];
$params = [];

if ($fStatus !== '') {
    $where[]  = 'e.status = ?';
    $params[] = $fStatus;
}
if ($fGP) {
    $where[]  = 'e.gp_id = ?';
    $params[] = $fGP;
}
if ($fSearch !== '') {
    $where[]  = '(e.business_name LIKE ? OR e.contact_name LIKE ? OR e.email LIKE ? OR e.ref LIKE ?)';
    $like = '%' . $fSearch . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$whereSQL = implode(' AND ', $where);

$total = (int)$db->prepare("SELECT COUNT(*) FROM bhc_enquiries e WHERE $whereSQL")
                 ->execute($params) ? $db->prepare("SELECT COUNT(*) FROM bhc_enquiries e WHERE $whereSQL")->execute($params) : 0;
// re-run properly
$stmt = $db->prepare("SELECT COUNT(*) FROM bhc_enquiries e WHERE $whereSQL");
$stmt->execute($params);
$total   = (int)$stmt->fetchColumn();
$pages   = max(1, (int)ceil($total / $perPage));
$offset  = ($page - 1) * $perPage;

$sql = "
SELECT  e.*,
        s.name_en                AS service_name,
        sc.name_en               AS category_name,
        sp.name                  AS provider_name,
        u.name                   AS gp_name,
        (SELECT COUNT(*) FROM bhc_enquiry_followups f WHERE f.enquiry_id = e.id) AS followup_count,
        (SELECT MAX(f2.created_at) FROM bhc_enquiry_followups f2 WHERE f2.enquiry_id = e.id) AS last_followup,
        (SELECT MAX(f3.follow_up_at) FROM bhc_enquiry_followups f3 WHERE f3.enquiry_id = e.id AND f3.follow_up_at > NOW()) AS next_followup
FROM    bhc_enquiries e
LEFT JOIN bhc_services s         ON s.id = e.service_id
LEFT JOIN bhc_service_categories sc ON sc.id = e.service_category_id
LEFT JOIN bhc_service_providers sp  ON sp.id = e.assigned_provider_id
LEFT JOIN users u                ON u.id = e.gp_id
WHERE $whereSQL
ORDER BY e.created_at DESC
LIMIT $perPage OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Summary counts */
$summaryStmt = $db->query("SELECT status, COUNT(*) cnt FROM bhc_enquiries GROUP BY status");
$statusCounts = [];
foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $statusCounts[$r['status']] = $r['cnt'];
}
$totalAll = array_sum($statusCounts);

/* GP list for filter */
$gpList = $db->query("SELECT DISTINCT u.id, u.name FROM users u INNER JOIN bhc_enquiries e ON e.gp_id = u.id ORDER BY u.name")
              ->fetchAll(PDO::FETCH_ASSOC);

/* Provider list for assignment dropdown */
$providers = $db->query("SELECT id, name FROM bhc_service_providers WHERE is_active=1 ORDER BY name")
                 ->fetchAll(PDO::FETCH_ASSOC);

/* Detail view */
$detail     = null;
$followups  = [];
$viewId     = (int)($_GET['view'] ?? 0);
if ($viewId) {
    $dStmt = $db->prepare("
        SELECT e.*,
               s.name_en AS service_name,
               sc.name_en AS category_name,
               sp.name AS provider_name,
               u.name AS gp_name
        FROM bhc_enquiries e
        LEFT JOIN bhc_services s ON s.id = e.service_id
        LEFT JOIN bhc_service_categories sc ON sc.id = e.service_category_id
        LEFT JOIN bhc_service_providers sp ON sp.id = e.assigned_provider_id
        LEFT JOIN users u ON u.id = e.gp_id
        WHERE e.id = ?
    ");
    $dStmt->execute([$viewId]);
    $detail = $dStmt->fetch(PDO::FETCH_ASSOC);

    $fStmt = $db->prepare("
        SELECT f.*, u.name AS actor_name
        FROM bhc_enquiry_followups f
        LEFT JOIN users u ON u.id = f.actor_id
        WHERE f.enquiry_id = ?
        ORDER BY f.created_at DESC
    ");
    $fStmt->execute([$viewId]);
    $followups = $fStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'BHC Lead Pipeline — Admin';
?>
<style>
:root{--acc:#22c55e;--bg:#0a1612;--card:#101e17;--border:rgba(255,255,255,.08);--muted:#6b7280}
body{background:var(--bg);color:#e5e7eb;font-family:system-ui,sans-serif;margin:0}
@media(prefers-color-scheme:light){:root{--bg:#f0fdf4;--card:#fff;--border:rgba(0,0,0,.1);--muted:#6b7280}body{color:#111827}}
.lp-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;padding:1.25rem 0;border-bottom:1px solid var(--border);margin-bottom:1.25rem}
.lp-header h1{font-family:'Fraunces',serif;font-weight:900;font-size:1.5rem;margin:0}
.stat-row{display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem}
.stat{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:.75rem 1rem;min-width:110px;text-align:center}
.stat .n{font-size:1.5rem;font-weight:700;color:var(--acc)}
.stat .l{font-size:.75rem;color:var(--muted);margin-top:.2rem}
.filter-bar{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;align-items:flex-end}
.filter-bar input,.filter-bar select{background:var(--card);border:1px solid var(--border);border-radius:6px;color:inherit;padding:.4rem .7rem;font-size:.84rem}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .9rem;border-radius:6px;border:none;cursor:pointer;font-size:.84rem;font-weight:600;text-decoration:none}
.btn-primary{background:var(--acc);color:#000}
.btn-sm{padding:.25rem .6rem;font-size:.78rem}
.btn-outline{background:transparent;border:1px solid var(--border);color:inherit}
.leads-table{width:100%;border-collapse:collapse;font-size:.84rem}
.leads-table thead tr{background:rgba(255,255,255,.03)}
.leads-table th{padding:.6rem 1rem;text-align:left;color:var(--muted);font-weight:600;white-space:nowrap}
.leads-table td{padding:.6rem 1rem;border-top:1px solid var(--border);vertical-align:middle}
.leads-table tbody tr:hover{background:rgba(255,255,255,.02)}
.pagination{display:flex;gap:.4rem;margin-top:1.25rem;flex-wrap:wrap}
.pag-btn{background:var(--card);border:1px solid var(--border);color:inherit;border-radius:5px;padding:.3rem .7rem;font-size:.84rem;text-decoration:none}
.pag-btn.active{background:var(--acc);color:#000;border-color:var(--acc)}
.admin-notice{padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.88rem}
.admin-notice.success{background:#14532d33;color:#4ade80;border:1px solid #166534}
.admin-notice.error{background:#450a0a33;color:#f87171;border:1px solid #991b1b}
/* Detail panel */
.detail-panel{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.25rem;margin-bottom:1.5rem}
.detail-panel h2{font-family:'Fraunces',serif;font-weight:800;font-size:1.2rem;margin:0 0 1rem}
.detail-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.6rem 1.25rem;margin-bottom:1.25rem}
.detail-item .label{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.15rem}
.detail-item .value{font-size:.88rem}
.fu-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.6rem}
.fu-item{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:6px;padding:.75rem}
.fu-item .fu-meta{font-size:.75rem;color:var(--muted);margin-top:.25rem}
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center}
.modal-backdrop.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.5rem;width:min(500px,95vw);max-height:90vh;overflow-y:auto}
.modal h3{margin:0 0 1rem;font-weight:700}
.form-group{margin-bottom:.85rem}
.form-group label{display:block;font-size:.8rem;color:var(--muted);margin-bottom:.25rem}
.form-group input,.form-group select,.form-group textarea{width:100%;box-sizing:border-box;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:5px;color:inherit;padding:.45rem .7rem;font-size:.84rem}
.form-group textarea{min-height:80px;resize:vertical}
@media(max-width:600px){.stat-row{gap:.5rem}.stat{min-width:80px;padding:.5rem}.lp-header{flex-direction:column;align-items:flex-start}}
</style>

<div class="lp-header">
    <h1>BHC Lead Pipeline</h1>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="admin-bhc-dashboard.php" class="btn btn-outline">← Dashboard</a>
        <a href="admin-bhc-enquiries.php" class="btn btn-outline">All Enquiries</a>
        <?php if($isSuperAdmin): ?>
        <a href="admin-bhc-gp-links.php" class="btn btn-outline">GP Links</a>
        <?php endif; ?>
    </div>
</div>

<?php echo $msg; ?>

<!-- Status summary tiles -->
<div class="stat-row">
    <div class="stat">
        <div class="n"><?= number_format($totalAll) ?></div>
        <div class="l">Total</div>
    </div>
    <?php foreach([
        'new'=>'New','awaiting_assignment'=>'Awaiting','assigned'=>'Assigned',
        'contacted'=>'Contacted','quotation_provided'=>'Quoted',
        'in_progress'=>'In Progress','converted'=>'Converted',
        'not_converted'=>'Lost','closed'=>'Closed'
    ] as $k=>$l): ?>
    <a href="?status=<?=urlencode($k)?>" style="text-decoration:none" class="stat">
        <div class="n"><?= number_format($statusCounts[$k] ?? 0) ?></div>
        <div class="l"><?=htmlspecialchars($l)?></div>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($detail): /* ─── Detail view ─────────────────────────────────── */ ?>
<div class="detail-panel">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem">
        <h2><?=htmlspecialchars($detail['business_name'])?> — <?=htmlspecialchars($detail['ref'])?></h2>
        <a href="?page=<?=$page?>&status=<?=urlencode($fStatus)?>&q=<?=urlencode($fSearch)?>" class="btn btn-outline btn-sm">← Back to list</a>
    </div>

    <div class="detail-grid">
        <div class="detail-item"><div class="label">Status</div><div class="value"><?=statusLabel($detail['status'])?></div></div>
        <div class="detail-item"><div class="label">Service</div><div class="value"><?=htmlspecialchars($detail['service_name'] ?? '—')?></div></div>
        <div class="detail-item"><div class="label">Category</div><div class="value"><?=htmlspecialchars($detail['category_name'] ?? '—')?></div></div>
        <div class="detail-item"><div class="label">Contact</div><div class="value"><?=htmlspecialchars($detail['contact_name'])?></div></div>
        <div class="detail-item"><div class="label">Email</div><div class="value"><a href="mailto:<?=htmlspecialchars($detail['email'])?>"><?=htmlspecialchars($detail['email'])?></a></div></div>
        <div class="detail-item"><div class="label">Phone</div><div class="value"><?=htmlspecialchars($detail['phone'] ?? '—')?></div></div>
        <div class="detail-item"><div class="label">Location</div><div class="value"><?=htmlspecialchars($detail['location'] ?? '—')?></div></div>
        <div class="detail-item"><div class="label">Preferred Contact</div><div class="value"><?=htmlspecialchars($detail['preferred_contact'])?></div></div>
        <div class="detail-item"><div class="label">Provider</div><div class="value"><?=htmlspecialchars($detail['provider_name'] ?? 'Unassigned')?></div></div>
        <div class="detail-item"><div class="label">Growth Partner</div><div class="value"><?=htmlspecialchars($detail['gp_name'] ?? '—')?></div></div>
        <div class="detail-item"><div class="label">Referral Source</div><div class="value"><?=htmlspecialchars($detail['referral_source'] ?? '—')?></div></div>
        <div class="detail-item"><div class="label">Received</div><div class="value"><?=htmlspecialchars(substr($detail['created_at'],0,16))?></div></div>
    </div>

    <?php if($detail['requirements']): ?>
    <div style="margin-bottom:1rem">
        <div class="label" style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Requirements</div>
        <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:6px;padding:.75rem;font-size:.88rem"><?=nl2br(htmlspecialchars($detail['requirements']))?></div>
    </div>
    <?php endif; ?>

    <!-- Actions row -->
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem">
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-status').classList.add('open')">Update Status</button>
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('modal-assign').classList.add('open')">Assign Provider</button>
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('modal-followup').classList.add('open')">Log Follow-up</button>
        <?php if($isSuperAdmin && $detail['status'] !== 'converted'): ?>
        <button class="btn btn-outline btn-sm" style="color:#22c55e;border-color:#22c55e" onclick="document.getElementById('modal-convert').classList.add('open')">Mark Converted</button>
        <?php endif; ?>
    </div>

    <!-- Follow-up history -->
    <div>
        <div style="font-weight:700;font-size:.9rem;margin-bottom:.75rem">Follow-up History (<?=count($followups)?>)</div>
        <?php if(empty($followups)): ?>
        <p style="color:var(--muted);font-size:.84rem">No follow-up activities logged yet.</p>
        <?php else: ?>
        <ul class="fu-list">
            <?php foreach($followups as $fu): ?>
            <li class="fu-item">
                <div style="font-weight:600;font-size:.85rem"><?=htmlspecialchars(str_replace('_',' ',ucfirst($fu['activity'])))?><?php if($fu['summary']): ?> — <?=htmlspecialchars($fu['summary'])?><?php endif; ?></div>
                <?php if($fu['detail']): ?><div style="font-size:.82rem;margin-top:.25rem;color:#d1d5db"><?=nl2br(htmlspecialchars($fu['detail']))?></div><?php endif; ?>
                <?php if($fu['outcome']): ?><div style="font-size:.8rem;color:var(--acc);margin-top:.2rem">Outcome: <?=htmlspecialchars($fu['outcome'])?></div><?php endif; ?>
                <?php if($fu['follow_up_at']): ?><div style="font-size:.8rem;color:#f59e0b;margin-top:.2rem">Next follow-up: <?=htmlspecialchars(substr($fu['follow_up_at'],0,16))?></div><?php endif; ?>
                <div class="fu-meta">By <?=htmlspecialchars($fu['actor_name'] ?? 'System')?> &middot; <?=htmlspecialchars(substr($fu['created_at'],0,16))?></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Update status -->
<div id="modal-status" class="modal-backdrop" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <h3>Update Status</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="enquiry_id" value="<?=(int)$viewId?>">
            <div class="form-group">
                <label>New Status</label>
                <select name="status" required>
                    <?php foreach($statusList as $sv): ?>
                    <option value="<?=$sv?>" <?=$detail['status']===$sv?'selected':''?>><?=statusLabel($sv,false)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-status').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Assign provider -->
<div id="modal-assign" class="modal-backdrop" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <h3>Assign Service Provider</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="action" value="assign_provider">
            <input type="hidden" name="enquiry_id" value="<?=(int)$viewId?>">
            <div class="form-group">
                <label>Provider</label>
                <select name="provider_id">
                    <option value="">— Unassigned —</option>
                    <?php foreach($providers as $p): ?>
                    <option value="<?=(int)$p['id']?>" <?=(int)$detail['assigned_provider_id']===(int)$p['id']?'selected':''?>><?=htmlspecialchars($p['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-assign').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Log follow-up -->
<div id="modal-followup" class="modal-backdrop" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <h3>Log Follow-up Activity</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="action" value="add_followup">
            <input type="hidden" name="enquiry_id" value="<?=(int)$viewId?>">
            <div class="form-group">
                <label>Activity Type</label>
                <select name="activity">
                    <option value="call_outbound">Outbound Call</option>
                    <option value="call_inbound">Inbound Call</option>
                    <option value="email_sent">Email Sent</option>
                    <option value="email_received">Email Received</option>
                    <option value="meeting_scheduled">Meeting Scheduled</option>
                    <option value="meeting_completed">Meeting Completed</option>
                    <option value="quotation_sent">Quotation Sent</option>
                    <option value="proposal_sent">Proposal Sent</option>
                    <option value="whatsapp_message">WhatsApp Message</option>
                    <option value="note">Internal Note</option>
                </select>
            </div>
            <div class="form-group">
                <label>Summary <span style="color:#ef4444">*</span></label>
                <input type="text" name="summary" required placeholder="Short summary of activity">
            </div>
            <div class="form-group">
                <label>Detail / Notes</label>
                <textarea name="detail" placeholder="Full notes, email content, call summary..."></textarea>
            </div>
            <div class="form-group">
                <label>Outcome</label>
                <input type="text" name="outcome" placeholder="e.g. Interested — call back Thu">
            </div>
            <div class="form-group">
                <label>Schedule Next Follow-up</label>
                <input type="datetime-local" name="follow_up_at">
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-followup').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Log Activity</button>
            </div>
        </form>
    </div>
</div>

<?php if($isSuperAdmin): ?>
<!-- Modal: Mark converted -->
<div id="modal-convert" class="modal-backdrop" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <h3>Mark as Converted</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="action" value="mark_converted">
            <input type="hidden" name="enquiry_id" value="<?=(int)$viewId?>">
            <div class="form-group">
                <label>Contract / Invoice Value (optional)</label>
                <input type="number" name="conversion_value" step="0.01" min="0" placeholder="e.g. 1200.00">
            </div>
            <div class="form-group">
                <label>Currency</label>
                <select name="currency">
                    <option value="GBP">GBP</option>
                    <option value="EUR">EUR</option>
                    <option value="USD">USD</option>
                    <option value="XAF">XAF</option>
                </select>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" placeholder="Any notes about this conversion..."></textarea>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-convert').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#22c55e">Confirm Conversion</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php else: /* ─── List view ──────────────────────────────────────────────── */ ?>

<!-- Filter bar -->
<form method="get" class="filter-bar">
    <input type="text" name="q" value="<?=htmlspecialchars($fSearch)?>" placeholder="Search business, name, email, ref...">
    <select name="status">
        <option value="">All Statuses</option>
        <?php foreach($statusList as $sv): ?>
        <option value="<?=$sv?>" <?=$fStatus===$sv?'selected':''?>><?=statusLabel($sv,false)?></option>
        <?php endforeach; ?>
    </select>
    <?php if (!empty($gpList)): ?>
    <select name="gp_id">
        <option value="">All Growth Partners</option>
        <?php foreach($gpList as $gp): ?>
        <option value="<?=(int)$gp['id']?>" <?=$fGP===(int)$gp['id']?'selected':''?>><?=htmlspecialchars($gp['name'])?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if($fStatus||$fSearch||$fGP): ?><a href="admin-bhc-leads.php" class="btn btn-outline">Clear</a><?php endif; ?>
</form>

<div style="color:var(--muted);font-size:.82rem;margin-bottom:.75rem"><?=number_format($total)?> lead<?=$total==1?'':'s'?></div>

<?php if(empty($leads)): ?>
<div class="admin-card" style="text-align:center;padding:2.5rem;color:var(--muted)">No leads found matching your filters.</div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="leads-table">
    <thead>
        <tr>
            <th>Ref</th>
            <th>Business</th>
            <th>Service</th>
            <th>Status</th>
            <th>Provider</th>
            <th>Growth Partner</th>
            <th>Follow-ups</th>
            <th>Next Due</th>
            <th>Received</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach($leads as $l): ?>
        <tr>
            <td style="font-family:monospace;font-size:.78rem"><?=htmlspecialchars($l['ref'])?></td>
            <td>
                <div style="font-weight:600;font-size:.87rem"><?=htmlspecialchars($l['business_name'])?></div>
                <div style="font-size:.76rem;color:var(--muted)"><?=htmlspecialchars($l['contact_name'])?></div>
            </td>
            <td>
                <div style="font-size:.85rem"><?=htmlspecialchars($l['service_name'] ?? '—')?></div>
                <div style="font-size:.76rem;color:var(--muted)"><?=htmlspecialchars($l['category_name'] ?? '')?></div>
            </td>
            <td><?=statusLabel($l['status'])?></td>
            <td style="font-size:.84rem"><?=htmlspecialchars($l['provider_name'] ?? '—')?></td>
            <td style="font-size:.84rem"><?=htmlspecialchars($l['gp_name'] ?? '—')?></td>
            <td style="text-align:center;font-size:.85rem"><?=(int)$l['followup_count']?></td>
            <td style="font-size:.8rem;color:<?=($l['next_followup'] && $l['next_followup'] < date('Y-m-d H:i:s'))? '#ef4444' : '#f59e0b'?>">
                <?=$l['next_followup'] ? htmlspecialchars(substr($l['next_followup'],0,10)) : '—'?>
            </td>
            <td style="font-size:.8rem;color:var(--muted)"><?=htmlspecialchars(substr($l['created_at'],0,10))?></td>
            <td>
                <a href="?view=<?=(int)$l['id']?>" class="btn btn-outline btn-sm">View</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Pagination -->
<?php if($pages > 1): ?>
<div class="pagination">
    <?php for($p=1;$p<=$pages;$p++): ?>
    <a class="pag-btn <?=$p===$page?'active':''?>" href="?page=<?=$p?>&status=<?=urlencode($fStatus)?>&q=<?=urlencode($fSearch)?>&gp_id=<?=$fGP?>"><?=$p?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
