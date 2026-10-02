<?php
/**
 * admin/regional-management.php — Regional Partner Management (Task 56)
 *
 * Admins manage geographic regions, assign partners to regions,
 * set coverage targets, and view regional performance metrics.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
requireAdmin();

// ── POST handlers ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Create / update region
    if ($action === 'save_region') {
        $regionId   = (int)($_POST['region_id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $slug       = trim($_POST['slug'] ?? '');
        $parentId   = (int)($_POST['parent_id'] ?? 0) ?: null;
        $targetPartners = (int)($_POST['target_partners'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        if (!$name) { setFlash('error', 'Region name is required.'); redirect(SITE_URL . '/admin/regional-management'); }
        if (!$slug) { $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)); }

        if ($regionId) {
            $pdo->prepare("
                UPDATE partner_regions SET name=?, slug=?, parent_id=?, target_partners=?, notes=?, is_active=?, updated_at=NOW()
                WHERE id=?
            ")->execute([$name, $slug, $parentId, $targetPartners, $notes, $isActive, $regionId]);
            setFlash('success', "Region '{$name}' updated.");
        } else {
            $pdo->prepare("
                INSERT INTO partner_regions (name, slug, parent_id, target_partners, notes, is_active, created_at, updated_at)
                VALUES (?,?,?,?,?,?,NOW(),NOW())
            ")->execute([$name, $slug, $parentId, $targetPartners, $notes, $isActive]);
            setFlash('success', "Region '{$name}' created.");
        }
        redirect(SITE_URL . '/admin/regional-management');
    }

    // Assign partner to region
    if ($action === 'assign_partner') {
        $regionId  = (int)($_POST['region_id'] ?? 0);
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

        if (!$regionId || !$partnerId) { setFlash('error', 'Region and partner are required.'); redirect(SITE_URL . '/admin/regional-management'); }

        // Upsert
        $pdo->prepare("
            INSERT INTO partner_region_assignments (region_id, partner_id, is_primary, assigned_at)
            VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE is_primary=VALUES(is_primary), assigned_at=NOW()
        ")->execute([$regionId, $partnerId, $isPrimary]);

        // Update partner profile primary region if flagged
        if ($isPrimary) {
            $pdo->prepare("UPDATE partner_profiles SET primary_region_id=? WHERE id=?")
                ->execute([$regionId, $partnerId]);
        }

        // Get partner name for audit
        $pn = $pdo->prepare("SELECT display_name FROM partner_profiles WHERE id=?");
        $pn->execute([$partnerId]);
        $pName = $pn->fetchColumn();
        partnerAuditLog($partnerId, 'region_assigned', "Assigned to region #{$regionId}" . ($isPrimary ? ' (primary)' : ''));

        setFlash('success', "Partner '{$pName}' assigned to region.");
        redirect(SITE_URL . '/admin/regional-management?region=' . $regionId);
    }

    // Remove partner from region
    if ($action === 'remove_assignment') {
        $regionId  = (int)($_POST['region_id'] ?? 0);
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        $pdo->prepare("DELETE FROM partner_region_assignments WHERE region_id=? AND partner_id=?")
            ->execute([$regionId, $partnerId]);
        setFlash('success', 'Assignment removed.');
        redirect(SITE_URL . '/admin/regional-management?region=' . $regionId);
    }

    // Toggle region active
    if ($action === 'toggle_active') {
        $regionId = (int)($_POST['region_id'] ?? 0);
        $pdo->prepare("UPDATE partner_regions SET is_active = NOT is_active WHERE id=?")->execute([$regionId]);
        redirect(SITE_URL . '/admin/regional-management');
    }

    redirect(SITE_URL . '/admin/regional-management');
}

// ── Data ──────────────────────────────────────────────────
$selectedRegionId = (int)($_GET['region'] ?? 0);

// All regions with stats
$regions = $pdo->prepare("
    SELECT r.*,
           rp.name AS parent_name,
           COUNT(DISTINCT pra.partner_id) AS assigned_count,
           SUM(pp.active_clients) AS total_clients,
           SUM(pp.monthly_revenue) AS total_revenue
    FROM partner_regions r
    LEFT JOIN partner_regions rp ON rp.id = r.parent_id
    LEFT JOIN partner_region_assignments pra ON pra.region_id = r.id
    LEFT JOIN partner_profiles pp ON pp.id = pra.partner_id AND pp.status = 'active'
    GROUP BY r.id
    ORDER BY r.is_active DESC, r.name ASC
");
$regions->execute();
$regions = $regions->fetchAll();

// Selected region detail
$selectedRegion    = null;
$regionAssignments = [];
$unassignedPartners = [];

if ($selectedRegionId) {
    $sr = $pdo->prepare("SELECT * FROM partner_regions WHERE id=?");
    $sr->execute([$selectedRegionId]);
    $selectedRegion = $sr->fetch();

    if ($selectedRegion) {
        $ra = $pdo->prepare("
            SELECT pra.*, pp.display_name, pp.status AS partner_status, pp.tier,
                   pp.active_clients, pp.monthly_revenue, u.email
            FROM partner_region_assignments pra
            JOIN partner_profiles pp ON pp.id = pra.partner_id
            JOIN users u ON u.id = pp.user_id
            WHERE pra.region_id=?
            ORDER BY pra.is_primary DESC, pp.display_name ASC
        ");
        $ra->execute([$selectedRegionId]);
        $regionAssignments = $ra->fetchAll();

        // Partners not yet in this region
        $assignedIds = array_column($regionAssignments, 'partner_id');
        $placeholders = $assignedIds ? implode(',', array_fill(0, count($assignedIds), '?')) : '0';
        $up = $pdo->prepare("
            SELECT id, display_name, tier, status
            FROM partner_profiles
            WHERE status IN ('active','approved') AND id NOT IN ($placeholders)
            ORDER BY display_name ASC
        ");
        $up->execute($assignedIds ?: [0]);
        $unassignedPartners = $up->fetchAll();
    }
}

// Parent regions for select
$parentOptions = array_filter($regions, fn($r) => !$r['parent_id']);

// Coverage summary
$totalRegions  = count($regions);
$activeRegions = count(array_filter($regions, fn($r) => $r['is_active']));
$totalAssigned = array_sum(array_column($regions, 'assigned_count'));

$flash     = getFlash();
$pageTitle = 'Regional Management';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:1200px;margin:0 auto;padding:1.5rem}
.layout{display:grid;grid-template-columns:320px 1fr;gap:1.25rem;align-items:start}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden}
.card-header{padding:.75rem 1rem;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.card-header h2{margin:0;font-size:.9rem;font-weight:700;color:#111827}
.card-body{padding:1rem}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1.25rem}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:.65rem;padding:.75rem 1rem;text-align:center}
.stat-val{font-size:1.5rem;font-weight:800;color:#111827}
.stat-lbl{font-size:.7rem;color:#9ca3af}
.region-list{list-style:none;margin:0;padding:0}
.region-item{display:flex;align-items:center;gap:.5rem;padding:.55rem .75rem;border-bottom:1px solid #f3f4f6;font-size:.83rem}
.region-item:last-child{border-bottom:none}
.region-item a{color:#2563eb;text-decoration:none;flex:1;font-weight:500}
.region-item a:hover{text-decoration:underline}
.badge{font-size:.68rem;font-weight:600;padding:.15rem .45rem;border-radius:99px}
.badge-green{background:#d1fae5;color:#065f46}
.badge-grey{background:#f3f4f6;color:#6b7280}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.coverage-bar{height:6px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-top:.2rem}
.coverage-fill{height:100%;background:#2563eb;border-radius:99px}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-danger{background:#fee2e2;color:#991b1b}
.btn-ghost{background:#f3f4f6;color:#374151}
.btn-sm{padding:.25rem .55rem;font-size:.72rem}
.btn:hover{opacity:.9}
.form-group{margin-bottom:.75rem}
.form-group label{display:block;font-size:.75rem;font-weight:700;color:#374151;margin-bottom:.3rem}
.form-group input,.form-group select,.form-group textarea{width:100%;border:1px solid #e5e7eb;border-radius:.4rem;padding:.45rem .65rem;font-size:.85rem;box-sizing:border-box}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.assignment-row{display:flex;align-items:center;gap:.5rem;padding:.55rem .75rem;border-bottom:1px solid #f3f4f6;font-size:.83rem}
.assignment-row:last-child{border-bottom:none}
.partner-info{flex:1}
.partner-name{font-weight:600;color:#111827}
.partner-meta{font-size:.72rem;color:#9ca3af}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:.75rem;padding:1.5rem;max-width:480px;width:90%;max-height:90vh;overflow-y:auto}
.modal h3{margin:0 0 1rem;font-size:1rem;color:#111827}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827">Regional Management</h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280">Manage geographic regions and partner assignments</p>
        </div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
            <button onclick="document.getElementById('regionModal').classList.add('open')" class="btn btn-primary btn-sm">+ New Region</button>
            <a href="<?= SITE_URL ?>/admin" class="btn btn-ghost btn-sm">← Admin</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <!-- Summary stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-val"><?= $totalRegions ?></div>
            <div class="stat-lbl">Total Regions</div>
        </div>
        <div class="stat-card">
            <div class="stat-val" style="color:#059669"><?= $activeRegions ?></div>
            <div class="stat-lbl">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-val" style="color:#2563eb"><?= $totalAssigned ?></div>
            <div class="stat-lbl">Partners Assigned</div>
        </div>
    </div>

    <div class="layout">

        <!-- Region list -->
        <div class="card">
            <div class="card-header">
                <h2>All Regions</h2>
            </div>
            <?php if (!$regions): ?>
            <div style="padding:2rem;text-align:center;color:#9ca3af;font-size:.85rem">No regions yet. Create one to get started.</div>
            <?php else: ?>
            <ul class="region-list">
                <?php foreach ($regions as $region): ?>
                <li class="region-item<?= $selectedRegionId==$region['id']?' ' :'' ?>" style="<?= $selectedRegionId==$region['id']?'background:#f0f7ff;':'' ?>">
                    <div style="flex:1">
                        <a href="?region=<?= $region['id'] ?>"><?= e($region['name']) ?></a>
                        <?php if ($region['parent_name']): ?><span style="font-size:.7rem;color:#9ca3af"> › <?= e($region['parent_name']) ?></span><?php endif ?>
                        <div style="display:flex;align-items:center;gap:.5rem;margin-top:.2rem">
                            <span style="font-size:.72rem;color:#6b7280"><?= $region['assigned_count'] ?> / <?= $region['target_partners'] ?: '?' ?> partners</span>
                            <?php if ($region['target_partners'] > 0): ?>
                            <div class="coverage-bar" style="width:60px">
                                <div class="coverage-fill" style="width:<?= min(100, round($region['assigned_count']/$region['target_partners']*100)) ?>%"></div>
                            </div>
                            <?php endif ?>
                        </div>
                    </div>
                    <span class="badge <?= $region['is_active'] ? 'badge-green' : 'badge-grey' ?>"><?= $region['is_active'] ? 'Active' : 'Off' ?></span>
                    <button onclick="openEditRegion(<?= htmlspecialchars(json_encode($region), ENT_QUOTES) ?>)" class="btn btn-ghost btn-sm" title="Edit">✏️</button>
                </li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
        </div>

        <!-- Right panel -->
        <div>
            <?php if ($selectedRegion): ?>
            <!-- Region detail -->
            <div class="card" style="margin-bottom:1rem">
                <div class="card-header">
                    <h2><?= e($selectedRegion['name']) ?></h2>
                    <div style="display:flex;gap:.35rem">
                        <form method="post" action="" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="region_id" value="<?= $selectedRegion['id'] ?>">
                            <button class="btn btn-ghost btn-sm"><?= $selectedRegion['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($selectedRegion['notes']): ?>
                    <p style="font-size:.83rem;color:#6b7280;margin-top:0"><?= nl2br(e($selectedRegion['notes'])) ?></p>
                    <?php endif ?>
                    <div style="display:flex;gap:1rem;font-size:.8rem;color:#374151;margin-bottom:.75rem">
                        <span><strong><?= count($regionAssignments) ?></strong> assigned</span>
                        <span><strong><?= $selectedRegion['target_partners'] ?: '—' ?></strong> target</span>
                    </div>

                    <!-- Assigned partners -->
                    <?php if ($regionAssignments): ?>
                    <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;margin-bottom:.4rem">Assigned Partners</div>
                    <div style="border:1px solid #e5e7eb;border-radius:.5rem;overflow:hidden;margin-bottom:.75rem">
                    <?php foreach ($regionAssignments as $ra): ?>
                    <div class="assignment-row">
                        <div class="partner-info">
                            <div class="partner-name">
                                <?= e($ra['display_name']) ?>
                                <?php if ($ra['is_primary']): ?><span class="badge badge-blue" style="margin-left:.3rem">Primary</span><?php endif ?>
                            </div>
                            <div class="partner-meta"><?= e($ra['email']) ?> · <?= ucfirst($ra['tier'] ?? 'standard') ?> · <?= $ra['active_clients'] ?> clients</div>
                        </div>
                        <form method="post" action="" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove_assignment">
                            <input type="hidden" name="region_id" value="<?= $selectedRegion['id'] ?>">
                            <input type="hidden" name="partner_id" value="<?= $ra['partner_id'] ?>">
                            <button class="btn btn-danger btn-sm" onclick="return confirm('Remove this assignment?')">Remove</button>
                        </form>
                    </div>
                    <?php endforeach ?>
                    </div>
                    <?php endif ?>

                    <!-- Assign partner form -->
                    <?php if ($unassignedPartners): ?>
                    <form method="post" action="">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="assign_partner">
                        <input type="hidden" name="region_id" value="<?= $selectedRegion['id'] ?>">
                        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                            <select name="partner_id" required style="flex:1;border:1px solid #e5e7eb;border-radius:.4rem;padding:.4rem .6rem;font-size:.82rem">
                                <option value="">— Add partner —</option>
                                <?php foreach ($unassignedPartners as $up): ?>
                                <option value="<?= $up['id'] ?>"><?= e($up['display_name']) ?> (<?= ucfirst($up['tier'] ?? 'standard') ?>)</option>
                                <?php endforeach ?>
                            </select>
                            <label style="font-size:.78rem;display:flex;align-items:center;gap:.3rem;white-space:nowrap">
                                <input type="checkbox" name="is_primary"> Primary
                            </label>
                            <button type="submit" class="btn btn-primary btn-sm">Assign</button>
                        </div>
                    </form>
                    <?php else: ?>
                    <p style="font-size:.8rem;color:#9ca3af;margin:0">All active partners are already assigned to this region.</p>
                    <?php endif ?>
                </div>
            </div>

            <?php else: ?>
            <div class="card">
                <div class="card-body" style="text-align:center;padding:3rem;color:#9ca3af">
                    <div style="font-size:2.5rem;margin-bottom:.75rem">🗺️</div>
                    <p style="margin:0;font-size:.9rem">Select a region to manage its partners</p>
                </div>
            </div>
            <?php endif ?>
        </div>
    </div>
</div>

<!-- Create / Edit Region Modal -->
<div class="modal-overlay" id="regionModal">
    <div class="modal">
        <h3 id="regionModalTitle">New Region</h3>
        <form method="post" action="">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_region">
            <input type="hidden" name="region_id" id="editRegionId" value="0">
            <div class="form-row">
                <div class="form-group">
                    <label>Region Name *</label>
                    <input type="text" name="name" id="editRegionName" required placeholder="e.g. North West England">
                </div>
                <div class="form-group">
                    <label>Slug</label>
                    <input type="text" name="slug" id="editRegionSlug" placeholder="auto-generated">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Parent Region</label>
                    <select name="parent_id" id="editRegionParent">
                        <option value="">— None (top-level) —</option>
                        <?php foreach ($parentOptions as $pr): ?>
                        <option value="<?= $pr['id'] ?>"><?= e($pr['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Target Partners</label>
                    <input type="number" name="target_partners" id="editRegionTarget" min="0" placeholder="0">
                </div>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" id="editRegionNotes" rows="3" placeholder="Internal notes about this region…"></textarea>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:normal">
                    <input type="checkbox" name="is_active" id="editRegionActive" checked> Active
                </label>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end">
                <button type="button" onclick="document.getElementById('regionModal').classList.remove('open')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Region</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditRegion(r) {
    document.getElementById('regionModalTitle').textContent = 'Edit Region';
    document.getElementById('editRegionId').value = r.id;
    document.getElementById('editRegionName').value = r.name;
    document.getElementById('editRegionSlug').value = r.slug;
    document.getElementById('editRegionParent').value = r.parent_id || '';
    document.getElementById('editRegionTarget').value = r.target_partners || 0;
    document.getElementById('editRegionNotes').value = r.notes || '';
    document.getElementById('editRegionActive').checked = r.is_active == 1;
    document.getElementById('regionModal').classList.add('open');
}
document.getElementById('regionModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
