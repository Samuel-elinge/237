<?php
/**
 * admin/partner-matching.php — Partner Matching Queue (Task 47)
 *
 * Admin view of open partner_requests. Match an open request to a partner,
 * accept/decline a specific request on behalf of the partner, or close a request.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireAdmin();

$pdo = db();

// ── POST actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action    = $_POST['action']     ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);

    if ($action === 'match' && $requestId) {
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        $adminNote = trim($_POST['admin_note'] ?? '');

        if (!$partnerId) { setFlash('error', 'Please select a partner.'); redirect(SITE_URL . '/admin/partner-matching'); }

        // Fetch request
        $req = $pdo->prepare("SELECT * FROM partner_requests WHERE id=?");
        $req->execute([$requestId]);
        $req = $req->fetch();

        if ($req) {
            $pdo->prepare("UPDATE partner_requests SET partner_id=?, status='matched', admin_note=?, matched_at=NOW(), matched_by=? WHERE id=?")
                ->execute([$partnerId, $adminNote, $_SESSION['user_id'], $requestId]);

            // Notify the partner
            $pOwner = $pdo->prepare("SELECT user_id FROM partner_profiles WHERE id=?");
            $pOwner->execute([$partnerId]);
            $pOwnerUid = (int)$pOwner->fetchColumn();
            if ($pOwnerUid) {
                pushNotification($pOwnerUid, 'partner_matched', 'You\'ve Been Matched',
                    'A business has been matched to you via an open partner request. Check your leads.',
                    SITE_URL . '/partner/leads', $partnerId);
            }

            // Notify the requester
            if ($req['requester_id']) {
                pushNotification((int)$req['requester_id'], 'request_matched', 'Partner Match Found',
                    'We\'ve matched you with a Growth Partner. They will be in touch soon.',
                    SITE_URL . '/dashboard');
            }

            setFlash('success', 'Request matched to partner.');
        }
        redirect(SITE_URL . '/admin/partner-matching');
    }

    if ($action === 'close' && $requestId) {
        $reason = trim($_POST['close_reason'] ?? '');
        $pdo->prepare("UPDATE partner_requests SET status='closed', admin_note=? WHERE id=?")
            ->execute([$reason, $requestId]);

        // Notify requester
        $req = $pdo->prepare("SELECT requester_id FROM partner_requests WHERE id=?");
        $req->execute([$requestId]);
        $req = $req->fetch();
        if ($req && $req['requester_id']) {
            pushNotification((int)$req['requester_id'], 'request_closed', 'Partner Request Update',
                'Your partner request has been closed.' . ($reason ? " Reason: $reason" : ''),
                SITE_URL . '/partners');
        }
        setFlash('success', 'Request closed.');
        redirect(SITE_URL . '/admin/partner-matching');
    }
}

// ── Filters ───────────────────────────────────────────────
$filterStatus = in_array($_GET['status'] ?? '', ['pending','matched','closed']) ? $_GET['status'] : 'pending';
$filterType   = in_array($_GET['type']   ?? '', ['specific','open','all']) ? $_GET['type'] : 'all';

$where  = [];
$params = [];
$where[]  = "pr.status = ?";
$params[] = $filterStatus;
if ($filterType !== 'all') {
    $where[]  = "pr.request_type = ?";
    $params[] = $filterType;
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$requests = $pdo->prepare("
    SELECT pr.*,
           u.name AS requester_name, u.email AS requester_email,
           l.business_name,
           pp.display_name AS partner_name,
           pp.referral_code AS partner_code
    FROM partner_requests pr
    JOIN users u ON u.id = pr.requester_id
    LEFT JOIN listings l ON l.id = pr.listing_id
    LEFT JOIN partner_profiles pp ON pp.id = pr.partner_id
    $whereSQL
    ORDER BY pr.created_at DESC
");
$requests->execute($params);
$requests = $requests->fetchAll();

// Counts
$counts = $pdo->query("SELECT status, COUNT(*) AS n FROM partner_requests GROUP BY status")->fetchAll(\PDO::FETCH_KEY_PAIR);

// Available partners for matching
$availablePartners = $pdo->query("
    SELECT pp.id, COALESCE(pp.display_name, u.name) AS name, pp.capacity_status,
           pp.max_businesses,
           (SELECT COUNT(*) FROM partner_business_assignments pba WHERE pba.partner_id=pp.id AND pba.status='active') AS active_count,
           (SELECT GROUP_CONCAT(pl.region ORDER BY pl.is_primary DESC SEPARATOR ', ') FROM partner_locations pl WHERE pl.partner_id=pp.id LIMIT 3) AS regions
    FROM partner_profiles pp
    JOIN users u ON u.id = pp.user_id
    WHERE pp.status IN ('approved','active') AND pp.capacity_status IN ('accepting','limited')
    ORDER BY pp.capacity_status='accepting' DESC, name
")->fetchAll();

$flash = getFlash();
$pageTitle = 'Partner Matching Queue';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.tabs{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:1.25rem}
.tab{padding:.55rem 1rem;font-size:.875rem;font-weight:500;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;white-space:nowrap}
.tab.active{color:#2563eb;border-color:#2563eb}
.tab .cnt{display:inline-block;background:#ef4444;color:#fff;font-size:.68rem;border-radius:99px;padding:.1rem .4rem;margin-left:.3rem}
.tab.active .cnt{background:#2563eb}
.req-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;margin-bottom:1rem;overflow:hidden}
.req-header{display:flex;align-items:flex-start;gap:1rem;padding:.9rem 1.1rem;border-bottom:1px solid #f3f4f6}
.req-type{font-size:.7rem;font-weight:700;padding:.2rem .55rem;border-radius:99px;flex-shrink:0}
.type-specific{background:#dbeafe;color:#1d4ed8}
.type-open{background:#fef3c7;color:#92400e}
.req-who{flex:1;min-width:0}
.req-name{font-weight:600;font-size:.92rem;color:#111827}
.req-sub{font-size:.78rem;color:#6b7280}
.urgency-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:.35rem}
.urg-low{background:#059669}
.urg-medium{background:#d97706}
.urg-high{background:#dc2626}
.req-body{padding:.75rem 1.1rem;font-size:.85rem;color:#374151;line-height:1.5}
.req-actions{padding:.6rem 1.1rem;background:#f9fafb;display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.4rem .85rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-match{background:#2563eb;color:#fff}
.btn-close{background:#dc2626;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.btn:hover{opacity:.9}
.label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;display:block;margin-bottom:.2rem}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.filters-row{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap}
.filter-btn{padding:.35rem .75rem;border-radius:.4rem;font-size:.78rem;font-weight:500;cursor:pointer;border:1px solid #e5e7eb;background:#fff;color:#6b7280;text-decoration:none}
.filter-btn.active{background:#2563eb;color:#fff;border-color:#2563eb}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:.75rem;padding:1.5rem;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{margin:0 0 .75rem;font-size:1rem;color:#111827}
.modal label{font-size:.82rem;font-weight:600;color:#374151;display:block;margin:.6rem 0 .2rem}
.modal select,.modal textarea,.modal input{width:100%;border:1px solid #d1d5db;border-radius:.45rem;padding:.45rem .7rem;font-size:.875rem;box-sizing:border-box}
.modal textarea{min-height:80px;resize:vertical}
.modal-actions{display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem}
.partner-option{display:flex;justify-content:space-between;font-size:.82rem}
.slot-bar{height:6px;border-radius:99px;background:#e5e7eb;overflow:hidden;margin-top:2px}
.slot-fill{height:100%;background:#2563eb;border-radius:99px}
</style>

<div style="max-width:900px;margin:0 auto;padding:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827">Partner Matching Queue</h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280">Review and match business partner requests</p>
        </div>
        <a href="<?= SITE_URL ?>/admin" class="btn btn-detail">← Admin</a>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <!-- Status Tabs -->
    <div class="tabs">
        <?php foreach (['pending'=>'Pending','matched'=>'Matched','closed'=>'Closed'] as $s => $lbl): ?>
        <a href="?status=<?= $s ?>&type=<?= e($filterType) ?>" class="tab<?= $filterStatus===$s ? ' active' : '' ?>">
            <?= $lbl ?>
            <?php if ($cnt = ($counts[$s] ?? 0)): ?><span class="cnt"><?= $cnt ?></span><?php endif ?>
        </a>
        <?php endforeach ?>
    </div>

    <!-- Type filter -->
    <div class="filters-row">
        <?php foreach (['all'=>'All','specific'=>'Specific','open'=>'Open'] as $t => $lbl): ?>
        <a href="?status=<?= e($filterStatus) ?>&type=<?= $t ?>" class="filter-btn<?= $filterType===$t ? ' active' : '' ?>"><?= $lbl ?></a>
        <?php endforeach ?>
    </div>

    <?php if (!$requests): ?>
    <div style="text-align:center;padding:3rem;color:#9ca3af">
        <div style="font-size:2.5rem;margin-bottom:.5rem">📋</div>
        <p>No <?= $filterStatus ?> requests.</p>
    </div>
    <?php else: ?>

    <?php foreach ($requests as $r): ?>
    <div class="req-card">
        <div class="req-header">
            <div class="urgency-dot urg-<?= e($r['urgency']) ?>"></div>
            <span class="req-type type-<?= e($r['request_type']) ?>"><?= ucfirst($r['request_type']) ?></span>
            <div class="req-who">
                <div class="req-name"><?= e($r['requester_name']) ?></div>
                <div class="req-sub">
                    <?= e($r['requester_email']) ?>
                    <?php if ($r['business_name']): ?> · <?= e($r['business_name']) ?><?php endif ?>
                    <?php if ($r['region_preference']): ?> · 📍 <?= e($r['region_preference']) ?><?php endif ?>
                </div>
            </div>
            <div style="font-size:.75rem;color:#9ca3af;white-space:nowrap"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
        </div>

        <div class="req-body">
            <?php if ($r['message']): ?>
            <div style="margin-bottom:.5rem"><?= e(mb_substr($r['message'], 0, 200)) ?><?= mb_strlen($r['message']) > 200 ? '…' : '' ?></div>
            <?php endif ?>
            <?php if ($r['needs']): ?>
            <div><span class="label">Needs</span><?= e($r['needs']) ?></div>
            <?php endif ?>
            <?php if ($r['partner_name']): ?>
            <div style="margin-top:.4rem"><span class="label">Matched to</span>
                <a href="<?= SITE_URL ?>/partner/profile?code=<?= urlencode($r['partner_code']) ?>" style="color:#2563eb;font-size:.875rem"><?= e($r['partner_name']) ?></a>
            </div>
            <?php endif ?>
            <?php if ($r['admin_note']): ?>
            <div style="margin-top:.4rem;color:#6b7280;font-size:.8rem"><em>Admin note: <?= e($r['admin_note']) ?></em></div>
            <?php endif ?>
        </div>

        <div class="req-actions">
            <?php if ($filterStatus === 'pending'): ?>
                <?php if ($r['request_type'] === 'open' || !$r['partner_id']): ?>
                <button class="btn btn-match" onclick="openMatch(<?= $r['id'] ?>, <?= e(json_encode($r['requester_name'])) ?>)">🔗 Match to Partner</button>
                <?php endif ?>
                <button class="btn btn-close" onclick="openClose(<?= $r['id'] ?>, <?= e(json_encode($r['requester_name'])) ?>)">✕ Close Request</button>
            <?php else: ?>
            <span style="font-size:.78rem;color:#9ca3af">
                <?= $filterStatus === 'matched' ? 'Matched ' . date('d M Y', strtotime($r['matched_at'] ?? $r['created_at'])) : 'Closed' ?>
            </span>
            <?php endif ?>
            <span style="margin-left:auto;font-size:.75rem;font-weight:600;color:<?= $r['urgency']==='high' ? '#dc2626' : ($r['urgency']==='medium' ? '#d97706' : '#059669') ?>">
                <?= ucfirst($r['urgency']) ?> urgency
            </span>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>
</div>

<!-- Match Modal -->
<div class="modal-overlay" id="modal-match">
    <div class="modal">
        <h3>Match to Partner</h3>
        <p id="match-requester" style="color:#6b7280;font-size:.875rem;margin:.25rem 0 .75rem"></p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="match">
            <input type="hidden" name="request_id" id="match-rid" value="">

            <label>Select Partner <span style="color:#dc2626">*</span></label>
            <select name="partner_id" required>
                <option value="">— Choose a partner —</option>
                <?php foreach ($availablePartners as $ap):
                    $slots = max(0, (int)$ap['max_businesses'] - (int)$ap['active_count']);
                    $pct   = $ap['max_businesses'] > 0 ? round($ap['active_count'] / $ap['max_businesses'] * 100) : 0;
                ?>
                <option value="<?= $ap['id'] ?>">
                    <?= e($ap['name']) ?> — <?= e($ap['capacity_status']) ?> — <?= $slots ?> slot<?= $slots!=1?'s':'' ?> free<?= $ap['regions'] ? ' — ' . e(mb_substr($ap['regions'],0,30)) : '' ?>
                </option>
                <?php endforeach ?>
            </select>
            <?php if (!$availablePartners): ?>
            <p style="color:#dc2626;font-size:.82rem;margin:.4rem 0 0">No partners currently accepting new businesses.</p>
            <?php endif ?>

            <label>Admin Note (optional)</label>
            <textarea name="admin_note" placeholder="Internal note about this match…"></textarea>

            <div class="modal-actions">
                <button type="button" class="btn btn-detail" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-match">Confirm Match</button>
            </div>
        </form>
    </div>
</div>

<!-- Close Modal -->
<div class="modal-overlay" id="modal-close">
    <div class="modal">
        <h3>Close Request</h3>
        <p id="close-requester" style="color:#6b7280;font-size:.875rem;margin:.25rem 0 .75rem"></p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="request_id" id="close-rid" value="">
            <label>Reason (sent to requester)</label>
            <textarea name="close_reason" placeholder="e.g. No available partner in requested region at this time…"></textarea>
            <div class="modal-actions">
                <button type="button" class="btn btn-detail" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-close">Close Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function openMatch(rid, name) {
    document.getElementById('match-rid').value = rid;
    document.getElementById('match-requester').textContent = 'Requester: ' + name;
    document.getElementById('modal-match').classList.add('open');
}
function openClose(rid, name) {
    document.getElementById('close-rid').value = rid;
    document.getElementById('close-requester').textContent = 'Requester: ' + name;
    document.getElementById('modal-close').classList.add('open');
}
function closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
}
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if (e.target===o) closeModals(); }));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
