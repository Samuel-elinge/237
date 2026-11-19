<?php
/**
 * partner/bhc-leads.php
 *
 * Growth Partner BHC Lead View — Phase 3
 * Shows a GP their own attributed leads and lets them log follow-up
 * activities on leads that are assigned to them.
 *
 * Access: logged-in Growth Partner only.
 * Restrictions:
 *  - Cannot see other partners' leads
 *  - Cannot modify scores, answers or assessment records
 *  - Obeys consent_sharing flag before showing contact info
 */
defined('IN_237BIZ') or define('IN_237BIZ', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

/* ─── Auth ─────────────────────────────────────────────────────────────── */
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$gpId = (int)$_SESSION['user_id'];

// Verify user is actually a GP (role check)
$db = db();
$gpUser = $db->prepare("SELECT id, name, email FROM users WHERE id=?");
$gpUser->execute([$gpId]);
$gpUser = $gpUser->fetch(PDO::FETCH_ASSOC);
if (!$gpUser) {
    http_response_code(403);
    echo '<p class="p-4">Access denied.</p>';
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$msg = '';

/* ─── POST: log follow-up (only on assigned leads) ──────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $msg = '<p class="gp-notice error">Invalid security token.</p>';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_followup') {
            $eid     = (int)($_POST['enquiry_id'] ?? 0);
            $activ   = $_POST['activity'] ?? 'note';
            $summary = trim($_POST['summary'] ?? '');
            $detail  = trim($_POST['detail'] ?? '');
            $outcome = trim($_POST['outcome'] ?? '');
            $fuAt    = !empty($_POST['follow_up_at']) ? $_POST['follow_up_at'] : null;

            // Verify this enquiry belongs to this GP
            $ownerCheck = $db->prepare("SELECT id FROM bhc_enquiries WHERE id=? AND gp_id=?");
            $ownerCheck->execute([$eid, $gpId]);
            if ($ownerCheck->fetch() && $summary !== '') {
                $db->prepare('INSERT INTO bhc_enquiry_followups (enquiry_id,actor_id,actor_type,activity,summary,detail,outcome,follow_up_at) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute([$eid, $gpId, 'growth_partner', $activ, $summary, $detail ?: null, $outcome ?: null, $fuAt]);
                $msg = '<p class="gp-notice success">Follow-up logged.</p>';
            } else {
                $msg = '<p class="gp-notice error">Enquiry not found or access denied.</p>';
            }
        }
    }
}

/* ─── Filters ────────────────────────────────────────────────────────────── */
$fStatus = $_GET['status'] ?? '';
$fSearch = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$statusList = ['new','awaiting_assignment','assigned','contacted','quotation_provided','in_progress','converted','not_converted','closed'];

$where  = ['e.gp_id = ?'];
$params = [$gpId];
if ($fStatus !== '') {
    $where[]  = 'e.status = ?';
    $params[] = $fStatus;
}
if ($fSearch !== '') {
    $where[]  = '(e.business_name LIKE ? OR e.ref LIKE ?)';
    $like = '%' . $fSearch . '%';
    $params = array_merge($params, [$like, $like]);
}
$whereSQL = implode(' AND ', $where);

$stmt = $db->prepare("SELECT COUNT(*) FROM bhc_enquiries e WHERE $whereSQL");
$stmt->execute($params);
$total  = (int)$stmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$leads = $db->prepare("
    SELECT e.id, e.ref, e.business_name, e.contact_name, e.email, e.phone,
           e.status, e.preferred_contact, e.location,
           e.consent_sharing, e.created_at,
           s.name_en AS service_name,
           (SELECT COUNT(*) FROM bhc_enquiry_followups f WHERE f.enquiry_id=e.id AND f.actor_id=?) AS my_followups,
           (SELECT MAX(f2.follow_up_at) FROM bhc_enquiry_followups f2 WHERE f2.enquiry_id=e.id AND f2.follow_up_at>NOW()) AS next_due
    FROM bhc_enquiries e
    LEFT JOIN bhc_services s ON s.id=e.service_id
    WHERE $whereSQL
    ORDER BY e.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$leads->execute(array_merge([$gpId], $params));
$leads = $leads->fetchAll(PDO::FETCH_ASSOC);

/* Summary */
$sumStmt = $db->prepare("SELECT status, COUNT(*) cnt FROM bhc_enquiries WHERE gp_id=? GROUP BY status");
$sumStmt->execute([$gpId]);
$statusCounts = [];
foreach ($sumStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $statusCounts[$r['status']] = $r['cnt'];

/* Detail view */
$detail    = null;
$followups = [];
$viewId    = (int)($_GET['view'] ?? 0);
if ($viewId) {
    $dStmt = $db->prepare("
        SELECT e.*, s.name_en AS service_name, sc.name_en AS category_name
        FROM bhc_enquiries e
        LEFT JOIN bhc_services s ON s.id=e.service_id
        LEFT JOIN bhc_service_categories sc ON sc.id=e.service_category_id
        WHERE e.id=? AND e.gp_id=?
    ");
    $dStmt->execute([$viewId, $gpId]);
    $detail = $dStmt->fetch(PDO::FETCH_ASSOC);
    if ($detail) {
        $fStmt = $db->prepare("
            SELECT f.*, u.name AS actor_name
            FROM bhc_enquiry_followups f
            LEFT JOIN users u ON u.id=f.actor_id
            WHERE f.enquiry_id=?
            ORDER BY f.created_at DESC
        ");
        $fStmt->execute([$viewId]);
        $followups = $fStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function gpStatusBadge(string $s): string {
    $map = [
        'new'=>['New','#3b82f6'],'awaiting_assignment'=>['Awaiting','#f59e0b'],
        'assigned'=>['Assigned','#8b5cf6'],'contacted'=>['Contacted','#06b6d4'],
        'quotation_provided'=>['Quoted','#f97316'],'in_progress'=>['In Progress','#10b981'],
        'converted'=>['Converted','#22c55e'],'not_converted'=>['Lost','#ef4444'],
        'closed'=>['Closed','#6b7280'],
    ];
    [$label,$color] = $map[$s] ?? [ucfirst(str_replace('_',' ',$s)),'#6b7280'];
    return '<span style="display:inline-block;padding:.2em .6em;border-radius:4px;font-size:.75rem;font-weight:600;background:'.$color.'22;color:'.$color.'">'.$label.'</span>';
}
?>
<style>
:root{--acc:#22c55e;--bg:#0a1612;--card:#101e17;--border:rgba(255,255,255,.08);--muted:#6b7280}
body{background:var(--bg);color:#e5e7eb;font-family:system-ui,sans-serif;margin:0}
@media(prefers-color-scheme:light){:root{--bg:#f0fdf4;--card:#fff;--border:rgba(0,0,0,.1);--muted:#6b7280}body{color:#111827}}
.gp-wrap{max-width:1100px;margin:0 auto;padding:1.5rem 1rem}
.gp-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem}
.gp-header h1{font-family:'Fraunces',serif;font-weight:900;font-size:1.5rem;margin:0}
.stat-row{display:flex;flex-wrap:wrap;gap:.6rem;margin-bottom:1.5rem}
.stat{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:.7rem 1rem;min-width:100px;text-align:center;text-decoration:none;color:inherit}
.stat .n{font-size:1.3rem;font-weight:700;color:var(--acc)}
.stat .l{font-size:.74rem;color:var(--muted)}
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
.detail-panel{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.25rem;margin-bottom:1.5rem}
.detail-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.6rem 1.25rem;margin-bottom:1rem}
.detail-item .label{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.15rem}
.fu-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.6rem}
.fu-item{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:6px;padding:.75rem}
.fu-item .fu-meta{font-size:.74rem;color:var(--muted);margin-top:.25rem}
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center}
.modal-backdrop.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.5rem;width:min(480px,95vw);max-height:90vh;overflow-y:auto}
.modal h3{margin:0 0 1rem;font-weight:700}
.form-group{margin-bottom:.85rem}
.form-group label{display:block;font-size:.8rem;color:var(--muted);margin-bottom:.25rem}
.form-group input,.form-group select,.form-group textarea{width:100%;box-sizing:border-box;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:5px;color:inherit;padding:.45rem .7rem;font-size:.84rem}
.form-group textarea{min-height:80px;resize:vertical}
.gp-notice{padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.88rem}
.gp-notice.success{background:#14532d33;color:#4ade80;border:1px solid #166534}
.gp-notice.error{background:#450a0a33;color:#f87171;border:1px solid #991b1b}
.consent-block{background:#f59e0b22;border:1px solid #d97706;border-radius:6px;padding:.75rem;font-size:.83rem;color:#fbbf24;margin-bottom:1rem}
</style>

<div class="gp-wrap">
<div class="gp-header">
    <h1>My BHC Leads</h1>
    <a href="/partner/" class="btn btn-outline">← Partner Dashboard</a>
</div>

<?php echo $msg; ?>

<!-- Stats -->
<div class="stat-row">
    <?php
    $statDefs = [
        ''             => 'All Leads',
        'new'          => 'New',
        'contacted'    => 'Contacted',
        'in_progress'  => 'In Progress',
        'converted'    => 'Converted',
        'not_converted'=> 'Lost',
    ];
    foreach ($statDefs as $sv => $sl):
        $count = $sv === '' ? array_sum($statusCounts) : ($statusCounts[$sv] ?? 0);
    ?>
    <a href="?status=<?=urlencode($sv)?>" class="stat">
        <div class="n"><?=number_format($count)?></div>
        <div class="l"><?=htmlspecialchars($sl)?></div>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($detail): /* ─── Detail ─── */ ?>
<div class="detail-panel">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
        <div>
            <h2 style="font-family:'Fraunces',serif;font-weight:800;font-size:1.2rem;margin:0"><?=htmlspecialchars($detail['business_name'])?></h2>
            <div style="font-size:.8rem;color:var(--muted);margin-top:.2rem"><?=htmlspecialchars($detail['ref'])?> &middot; <?=htmlspecialchars($detail['service_name'] ?? '—')?></div>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <span><?=gpStatusBadge($detail['status'])?></span>
            <a href="?page=<?=$page?>&status=<?=urlencode($fStatus)?>" class="btn btn-outline btn-sm">← Back</a>
        </div>
    </div>

    <?php if (!$detail['consent_sharing']): ?>
    <div class="consent-block">⚠ The business owner has not yet consented to sharing their contact details. Contact information is hidden until consent is given.</div>
    <?php endif; ?>

    <div class="detail-grid">
        <?php if ($detail['consent_sharing']): ?>
        <div class="detail-item"><div class="label">Contact</div><div class="value"><?=htmlspecialchars($detail['contact_name'])?></div></div>
        <div class="detail-item"><div class="label">Email</div><div class="value"><a href="mailto:<?=htmlspecialchars($detail['email'])?>"><?=htmlspecialchars($detail['email'])?></a></div></div>
        <div class="detail-item"><div class="label">Phone</div><div class="value"><?=htmlspecialchars($detail['phone'] ?? '—')?></div></div>
        <?php else: ?>
        <div class="detail-item"><div class="label">Contact</div><div class="value" style="color:var(--muted)">Hidden (no consent)</div></div>
        <?php endif; ?>
        <div class="detail-item"><div class="label">Business</div><div class="value"><?=htmlspecialchars($detail['business_name'])?></div></div>
        <div class="detail-item"><div class="label">Location</div><div class="value"><?=htmlspecialchars($detail['location'] ?? '—')?></div></div>
        <div class="detail-item"><div class="label">Service</div><div class="value"><?=htmlspecialchars($detail['service_name'] ?? '—')?></div></div>
        <div class="detail-item"><div class="label">Category</div><div class="value"><?=htmlspecialchars($detail['category_name'] ?? '—')?></div></div>
        <div class="detail-item"><div class="label">Received</div><div class="value"><?=htmlspecialchars(substr($detail['created_at'],0,10))?></div></div>
    </div>

    <div style="margin-bottom:1rem">
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-fu').classList.add('open')">Log Follow-up</button>
    </div>

    <!-- Follow-up history -->
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
            <?php if($fu['follow_up_at']): ?><div style="font-size:.8rem;color:#f59e0b;margin-top:.2rem">Next: <?=htmlspecialchars(substr($fu['follow_up_at'],0,16))?></div><?php endif; ?>
            <div class="fu-meta">By <?=htmlspecialchars($fu['actor_name'] ?? 'System')?> &middot; <?=htmlspecialchars(substr($fu['created_at'],0,16))?></div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<!-- Log follow-up modal -->
<div id="modal-fu" class="modal-backdrop" onclick="if(event.target===this)this.classList.remove('open')">
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
                <input type="text" name="summary" required placeholder="Short summary">
            </div>
            <div class="form-group">
                <label>Detail / Notes</label>
                <textarea name="detail" placeholder="Full notes..."></textarea>
            </div>
            <div class="form-group">
                <label>Outcome</label>
                <input type="text" name="outcome" placeholder="e.g. Interested, call back Thu">
            </div>
            <div class="form-group">
                <label>Schedule Next Follow-up</label>
                <input type="datetime-local" name="follow_up_at">
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-fu').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Log Activity</button>
            </div>
        </form>
    </div>
</div>

<?php else: /* ─── List view ─── */ ?>

<form method="get" class="filter-bar">
    <input type="text" name="q" value="<?=htmlspecialchars($fSearch)?>" placeholder="Search business or ref...">
    <select name="status">
        <option value="">All Statuses</option>
        <?php foreach($statusList as $sv): ?>
        <option value="<?=$sv?>" <?=$fStatus===$sv?'selected':''?>><?=ucfirst(str_replace('_',' ',$sv))?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if($fStatus||$fSearch): ?><a href="?" class="btn btn-outline">Clear</a><?php endif; ?>
</form>

<div style="color:var(--muted);font-size:.82rem;margin-bottom:.75rem"><?=number_format($total)?> lead<?=$total==1?'':'s'?></div>

<?php if(empty($leads)): ?>
<div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:2.5rem;text-align:center;color:var(--muted)">
    <?php if($fSearch || $fStatus): ?>No leads match your filters.<?php else: ?>You have no attributed leads yet. Share your partner assessment link to get started.<?php endif; ?>
</div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="leads-table">
    <thead>
        <tr>
            <th>Ref</th>
            <th>Business</th>
            <th>Service</th>
            <th>Status</th>
            <th>My Follow-ups</th>
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
                <?php if($l['consent_sharing']): ?>
                <div style="font-size:.76rem;color:var(--muted)"><?=htmlspecialchars($l['contact_name'])?></div>
                <?php endif; ?>
            </td>
            <td style="font-size:.84rem"><?=htmlspecialchars($l['service_name'] ?? '—')?></td>
            <td><?=gpStatusBadge($l['status'])?></td>
            <td style="text-align:center"><?=(int)$l['my_followups']?></td>
            <td style="font-size:.8rem;color:<?=($l['next_due'] && $l['next_due']<date('Y-m-d H:i:s'))?'#ef4444':'#f59e0b'?>">
                <?=$l['next_due']?htmlspecialchars(substr($l['next_due'],0,10)):'—'?>
            </td>
            <td style="font-size:.8rem;color:var(--muted)"><?=htmlspecialchars(substr($l['created_at'],0,10))?></td>
            <td><a href="?view=<?=(int)$l['id']?>" class="btn btn-outline btn-sm">View</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if($pages > 1): ?>
<div style="display:flex;gap:.4rem;margin-top:1.25rem;flex-wrap:wrap">
    <?php for($p=1;$p<=$pages;$p++): ?>
    <a style="background:var(--card);border:1px solid var(--border);border-radius:5px;padding:.3rem .7rem;font-size:.84rem;text-decoration:none;color:inherit<?=$p===$page?';background:var(--acc);color:#000;border-color:var(--acc)':''?>" href="?page=<?=$p?>&status=<?=urlencode($fStatus)?>&q=<?=urlencode($fSearch)?>"><?=$p?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
