<?php
/**
 * admin-bhc-gp-links.php
 *
 * Growth Partner Assessment Link Manager — Phase 3
 * Super Admin only. Create / manage partner-specific assessment URLs
 * so referrals are auto-attributed when assessment starts via ?gp=TOKEN.
 */
defined('IN_237BIZ') or define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/admin-header.php';

/* ─── Auth: Super Admin only ────────────────────────────────────────────── */
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<p class="p-4 text-red-400">Access denied — Super Admin required.</p>';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$db  = db();
$msg = '';

/* ─── POST actions ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $msg = '<p class="admin-notice error">Invalid security token.</p>';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_link') {
            $gpId  = (int)($_POST['gp_id'] ?? 0);
            $label = trim($_POST['label'] ?? '');
            $exp   = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            if ($gpId) {
                $token = bin2hex(random_bytes(16)); // 32-char hex
                $db->prepare('INSERT INTO bhc_gp_links (gp_id,token,label,expires_at) VALUES (?,?,?,?)')
                   ->execute([$gpId, $token, $label ?: null, $exp]);
                $msg = '<p class="admin-notice success">Link created.</p>';
            }
        }

        if ($action === 'toggle_link') {
            $lid = (int)($_POST['link_id'] ?? 0);
            if ($lid) {
                $db->prepare('UPDATE bhc_gp_links SET is_active = 1 - is_active WHERE id=?')
                   ->execute([$lid]);
                $msg = '<p class="admin-notice success">Link status toggled.</p>';
            }
        }
    }
}

/* ─── Load GPs ───────────────────────────────────────────────────────────── */
$gps = $db->query("SELECT id, name, email FROM users WHERE is_partner=1 OR role='growth_partner' ORDER BY name")
           ->fetchAll(PDO::FETCH_ASSOC);
if (empty($gps)) {
    // fallback: users who have attributed enquiries
    $gps = $db->query("SELECT DISTINCT u.id, u.name, u.email FROM users u INNER JOIN bhc_enquiries e ON e.gp_id=u.id ORDER BY u.name")
               ->fetchAll(PDO::FETCH_ASSOC);
}

/* ─── Load links ─────────────────────────────────────────────────────────── */
$links = $db->query("
    SELECT gl.*, u.name AS gp_name, u.email AS gp_email,
           COUNT(e.id) AS attributed_leads,
           SUM(CASE WHEN e.status='converted' THEN 1 ELSE 0 END) AS conversions
    FROM bhc_gp_links gl
    LEFT JOIN users u ON u.id = gl.gp_id
    LEFT JOIN bhc_enquiries e ON e.gp_id = gl.gp_id
    GROUP BY gl.id
    ORDER BY gl.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* Build base URL */
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
         . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com')
         . '/business-health-check.php?gp=';

$pageTitle = 'BHC GP Assessment Links — Admin';
?>
<style>
:root{--acc:#22c55e;--bg:#0a1612;--card:#101e17;--border:rgba(255,255,255,.08);--muted:#6b7280}
body{background:var(--bg);color:#e5e7eb;font-family:system-ui,sans-serif;margin:0}
@media(prefers-color-scheme:light){:root{--bg:#f0fdf4;--card:#fff;--border:rgba(0,0,0,.1);--muted:#6b7280}body{color:#111827}}
.lp-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;padding:1.25rem 0;border-bottom:1px solid var(--border);margin-bottom:1.25rem}
.lp-header h1{font-family:'Fraunces',serif;font-weight:900;font-size:1.5rem;margin:0}
.admin-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.25rem;margin-bottom:1.25rem}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .9rem;border-radius:6px;border:none;cursor:pointer;font-size:.84rem;font-weight:600;text-decoration:none}
.btn-primary{background:var(--acc);color:#000}
.btn-sm{padding:.25rem .6rem;font-size:.78rem}
.btn-outline{background:transparent;border:1px solid var(--border);color:inherit}
.admin-table{width:100%;border-collapse:collapse;font-size:.84rem}
.admin-table thead tr{background:rgba(255,255,255,.03)}
.admin-table th{padding:.6rem 1rem;text-align:left;color:var(--muted);font-weight:600;white-space:nowrap}
.admin-table td{padding:.6rem 1rem;border-top:1px solid var(--border);vertical-align:middle}
.admin-table tbody tr:hover{background:rgba(255,255,255,.02)}
.admin-notice{padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.88rem}
.admin-notice.success{background:#14532d33;color:#4ade80;border:1px solid #166534}
.admin-notice.error{background:#450a0a33;color:#f87171;border:1px solid #991b1b}
.form-group{margin-bottom:.85rem}
.form-group label{display:block;font-size:.8rem;color:var(--muted);margin-bottom:.25rem}
.form-group input,.form-group select{width:100%;box-sizing:border-box;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:5px;color:inherit;padding:.45rem .7rem;font-size:.84rem}
.link-code{font-family:monospace;font-size:.78rem;background:rgba(255,255,255,.05);padding:.2em .5em;border-radius:3px;word-break:break-all}
.badge-active{background:#14532d44;color:#4ade80;border:1px solid #166534;padding:.15em .5em;border-radius:3px;font-size:.74rem}
.badge-inactive{background:#450a0a44;color:#f87171;border:1px solid #991b1b;padding:.15em .5em;border-radius:3px;font-size:.74rem}
</style>

<div class="lp-header">
    <h1>Growth Partner Assessment Links</h1>
    <div style="display:flex;gap:.5rem">
        <a href="admin-bhc-leads.php" class="btn btn-outline">← Lead Pipeline</a>
        <a href="admin-bhc-dashboard.php" class="btn btn-outline">Dashboard</a>
    </div>
</div>

<?php echo $msg; ?>

<!-- Create new link -->
<div class="admin-card">
    <h2 style="font-family:'Fraunces',serif;font-weight:800;font-size:1.1rem;margin:0 0 1rem">Create Partner Link</h2>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;align-items:end">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="action" value="create_link">
        <div class="form-group" style="margin:0">
            <label>Growth Partner <span style="color:#ef4444">*</span></label>
            <select name="gp_id" required>
                <option value="">— Select Partner —</option>
                <?php foreach($gps as $gp): ?>
                <option value="<?=(int)$gp['id']?>"><?=htmlspecialchars($gp['name'])?> (<?=htmlspecialchars($gp['email'])?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label>Link Label (optional)</label>
            <input type="text" name="label" placeholder="e.g. LinkedIn Q4 Campaign">
        </div>
        <div class="form-group" style="margin:0">
            <label>Expires At (optional)</label>
            <input type="date" name="expires_at">
        </div>
        <div>
            <button type="submit" class="btn btn-primary">Generate Link</button>
        </div>
    </form>
</div>

<!-- Links table -->
<?php if(empty($links)): ?>
<div class="admin-card" style="text-align:center;padding:2.5rem;color:var(--muted)">No GP links created yet.</div>
<?php else: ?>
<div class="admin-card" style="padding:0;overflow:hidden">
    <div style="overflow-x:auto">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Partner</th>
                <th>Label</th>
                <th>Assessment Link</th>
                <th>Leads</th>
                <th>Conversions</th>
                <th>Clicks</th>
                <th>Expires</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($links as $lk): ?>
            <tr>
                <td>
                    <div style="font-weight:600;font-size:.87rem"><?=htmlspecialchars($lk['gp_name'] ?? '—')?></div>
                    <div style="font-size:.76rem;color:var(--muted)"><?=htmlspecialchars($lk['gp_email'] ?? '')?></div>
                </td>
                <td style="font-size:.84rem"><?=htmlspecialchars($lk['label'] ?? '—')?></td>
                <td>
                    <div class="link-code"><?=htmlspecialchars($baseUrl . $lk['token'])?></div>
                    <button class="btn btn-outline btn-sm" style="margin-top:.25rem" onclick="navigator.clipboard.writeText('<?=htmlspecialchars($baseUrl . $lk['token'], ENT_QUOTES)?>').then(()=>this.textContent='Copied!').catch(()=>{})">Copy</button>
                </td>
                <td style="text-align:center"><?=(int)$lk['attributed_leads']?></td>
                <td style="text-align:center;color:var(--acc)"><?=(int)$lk['conversions']?></td>
                <td style="text-align:center"><?=(int)$lk['click_count']?></td>
                <td style="font-size:.82rem;color:var(--muted)"><?=$lk['expires_at'] ? htmlspecialchars(substr($lk['expires_at'],0,10)) : 'Never'?></td>
                <td><?=$lk['is_active'] ? '<span class="badge-active">Active</span>' : '<span class="badge-inactive">Inactive</span>'?></td>
                <td>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                        <input type="hidden" name="action" value="toggle_link">
                        <input type="hidden" name="link_id" value="<?=(int)$lk['id']?>">
                        <button type="submit" class="btn btn-outline btn-sm"><?=$lk['is_active']?'Deactivate':'Activate'?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="admin-card" style="color:var(--muted);font-size:.84rem;line-height:1.6">
    <strong style="color:inherit">How GP links work:</strong>
    When a business clicks a partner link (<code>?gp=TOKEN</code>), the Growth Partner's ID is captured at the start of their assessment and stored on the resulting enquiry. Attribution is preserved through completion. Partners can view their attributed leads in the partner portal.
</div>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
