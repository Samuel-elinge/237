<?php
/**
 * partner/capacity.php — Partner Capacity & Availability Management (Task 44)
 *
 * Partners can set their availability status, adjust max_businesses,
 * and view their capacity history log.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$pdo = db();
requireGrowthPartner();

$partnerRow = $pdo->prepare("
    SELECT pp.*, COALESCE(pp.display_name, u.name) AS dname
    FROM partner_profiles pp
    JOIN users u ON u.id = pp.user_id
    WHERE pp.user_id = ? AND pp.status IN ('approved','active')
");
$partnerRow->execute([$_SESSION['user_id']]);
$partner = $partnerRow->fetch();
if (!$partner) { setFlash('error', 'Partner profile not found.'); redirect(SITE_URL . '/partner/dashboard'); }

$partnerId = (int)$partner['id'];

// ── POST: update capacity settings ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $newStatus = $_POST['capacity_status'] ?? '';
    $validStatuses = ['accepting', 'limited', 'full', 'paused'];
    if (!in_array($newStatus, $validStatuses)) $newStatus = $partner['capacity_status'] ?? 'accepting';

    $newMax    = max(1, min(50, (int)($_POST['max_businesses'] ?? $partner['max_businesses'])));
    $notes     = trim($_POST['capacity_notes'] ?? '');

    // Current active count (can't set max below active)
    $activeCount = (int)$pdo->prepare("SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=? AND status='active'")
        ->execute([$partnerId]) ? $pdo->prepare("SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=? AND status='active'")->execute([$partnerId]) : 0;
    // Re-query properly
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=? AND status='active'");
    $stmt->execute([$partnerId]);
    $activeCount = (int)$stmt->fetchColumn();

    if ($newMax < $activeCount) {
        setFlash('error', "Cannot set maximum below your current active businesses ($activeCount).");
        redirect(SITE_URL . '/partner/capacity');
    }

    // Log the change
    $pdo->prepare("INSERT INTO partner_capacity_log (partner_id, previous_status, new_status, previous_max, new_max, notes, changed_by)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([
            $partnerId,
            $partner['capacity_status'] ?? 'accepting',
            $newStatus,
            $partner['max_businesses'],
            $newMax,
            $notes,
            (int)$_SESSION['user_id']
        ]);

    // Update profile
    $pdo->prepare("UPDATE partner_profiles SET capacity_status=?, max_businesses=? WHERE id=?")
        ->execute([$newStatus, $newMax, $partnerId]);

    partnerAuditLog($partnerId, (int)$_SESSION['user_id'], null, 'capacity_updated',
        "Status: {$partner['capacity_status']} → $newStatus | Max: {$partner['max_businesses']} → $newMax");

    setFlash('success', 'Capacity settings updated.');
    redirect(SITE_URL . '/partner/capacity');
}

// ── Load data ─────────────────────────────────────────────
// Re-fetch after possible redirect
$partner = $pdo->prepare("SELECT pp.*, COALESCE(pp.display_name, u.name) AS dname FROM partner_profiles pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?")->execute([$partnerId]) ? $pdo->prepare("SELECT pp.*, COALESCE(pp.display_name, u.name) AS dname FROM partner_profiles pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?") : null;
$partner = $pdo->prepare("SELECT pp.*, COALESCE(pp.display_name, u.name) AS dname FROM partner_profiles pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?");
$partner->execute([$partnerId]);
$partner = $partner->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=? AND status='active'");
$stmt->execute([$partnerId]);
$activeCount = (int)$stmt->fetchColumn();

$maxBiz    = (int)($partner['max_businesses'] ?? 10);
$fillPct   = $maxBiz > 0 ? min(100, round($activeCount / $maxBiz * 100)) : 0;
$curStatus = $partner['capacity_status'] ?? 'accepting';

// Capacity log (last 20)
$log = $pdo->prepare("
    SELECT pcl.*, COALESCE(u.name, 'System') AS changed_by_name
    FROM partner_capacity_log pcl
    LEFT JOIN users u ON u.id = pcl.changed_by
    WHERE pcl.partner_id = ?
    ORDER BY pcl.changed_at DESC
    LIMIT 20
");
$log->execute([$partnerId]);
$log = $log->fetchAll();

// Recent businesses
$businesses = $pdo->prepare("
    SELECT l.business_name, l.slug, pba.role, pba.assigned_at, pba.status AS assign_status
    FROM partner_business_assignments pba
    JOIN listings l ON l.id = pba.listing_id
    WHERE pba.partner_id = ?
    ORDER BY pba.status='active' DESC, pba.assigned_at DESC
    LIMIT 10
");
$businesses->execute([$partnerId]);
$businesses = $businesses->fetchAll();

$flash = getFlash();

$statusMeta = [
    'accepting' => ['label'=>'Accepting',  'color'=>'#059669','bg'=>'#d1fae5','desc'=>'Actively taking on new businesses'],
    'limited'   => ['label'=>'Limited',    'color'=>'#d97706','bg'=>'#fef3c7','desc'=>'Taking on a few more with care'],
    'full'      => ['label'=>'Full',       'color'=>'#dc2626','bg'=>'#fee2e2','desc'=>'No new businesses at this time'],
    'paused'    => ['label'=>'Paused',     'color'=>'#6b7280','bg'=>'#f3f4f6','desc'=>'Temporarily unavailable'],
];

$pageTitle = pt('Capacity & Availability');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.capacity-grid{display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;align-items:start}
@media(max-width:680px){.capacity-grid{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.25rem;margin-bottom:1rem}
.card-title{font-size:.95rem;font-weight:700;color:#111827;margin:0 0 1rem}
.status-pills{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem}
.status-pill{padding:.5rem 1rem;border-radius:99px;font-size:.82rem;font-weight:600;cursor:pointer;border:2px solid transparent;transition:all .15s}
.status-pill input[type=radio]{display:none}
.status-pill.selected{border-color:currentColor}
label.status-pill{display:inline-flex;align-items:center;gap:.3rem}
.meter-wrap{margin:1rem 0}
.meter-label{display:flex;justify-content:space-between;font-size:.8rem;color:#6b7280;margin-bottom:.35rem}
.meter-bar{height:12px;background:#e5e7eb;border-radius:99px;overflow:hidden}
.meter-fill{height:100%;border-radius:99px;transition:width .4s}
.fill-low{background:#059669}
.fill-mid{background:#d97706}
.fill-high{background:#dc2626}
.range-wrap{margin:1rem 0}
.range-wrap input[type=range]{width:100%;accent-color:#2563eb}
.range-display{text-align:center;font-size:1.5rem;font-weight:700;color:#111827;margin:.25rem 0}
.range-sub{text-align:center;font-size:.78rem;color:#9ca3af}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.5rem 1.1rem;border-radius:.5rem;font-size:.875rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.btn:hover{opacity:.9}
.log-item{display:flex;align-items:flex-start;gap:.75rem;padding:.6rem 0;border-bottom:1px solid #f3f4f6;font-size:.82rem}
.log-item:last-child{border:none}
.log-dot{width:8px;height:8px;border-radius:50%;background:#d1d5db;flex-shrink:0;margin-top:.3rem}
.log-dot.up{background:#059669}
.log-dot.down{background:#dc2626}
.log-dot.same{background:#d97706}
.log-meta{color:#9ca3af;font-size:.75rem;margin-top:.1rem}
.biz-row{display:flex;align-items:center;gap:.5rem;padding:.4rem 0;border-bottom:1px solid #f9fafb;font-size:.82rem}
.biz-row:last-child{border:none}
.biz-name{flex:1;color:#111827;font-weight:500}
.biz-tag{font-size:.7rem;padding:.1rem .45rem;border-radius:99px}
.biz-tag.active{background:#d1fae5;color:#065f46}
.biz-tag.inactive{background:#f3f4f6;color:#6b7280}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
</style>

<div style="max-width:860px;margin:0 auto;padding:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827"><?= pt('Capacity & Availability') ?></h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280"><?= pt('Manage how many businesses you can take on and your availability status') ?></p>
        </div>
        <a href="<?= SITE_URL ?>/partner/dashboard" class="btn btn-detail">← <?= pt('Dashboard') ?></a>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <div class="capacity-grid">
        <!-- Left: Settings form -->
        <div>
            <div class="card">
                <p class="card-title"><?= pt('Availability Status') ?></p>

                <?php
                $sm = $statusMeta[$curStatus] ?? $statusMeta['accepting'];
                ?>
                <div style="padding:.75rem 1rem;background:<?= $sm['bg'] ?>;border-radius:.5rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem">
                    <span style="font-size:.85rem;font-weight:700;color:<?= $sm['color'] ?>"><?= $sm['label'] ?></span>
                    <span style="font-size:.82rem;color:<?= $sm['color'] ?>">— <?= $sm['desc'] ?></span>
                </div>

                <form method="post" id="capacity-form">
                    <?= csrfField() ?>

                    <p style="font-size:.82rem;font-weight:600;color:#374151;margin:0 0 .5rem"><?= pt('Change Status') ?></p>
                    <div class="status-pills">
                        <?php foreach ($statusMeta as $s => $meta): ?>
                        <label class="status-pill<?= $curStatus === $s ? ' selected' : '' ?>"
                               style="color:<?= $meta['color'] ?>;background:<?= $meta['bg'] ?>">
                            <input type="radio" name="capacity_status" value="<?= $s ?>"
                                   <?= $curStatus === $s ? 'checked' : '' ?>
                                   onchange="document.querySelectorAll('.status-pill').forEach(p=>p.classList.remove('selected'));this.closest('.status-pill').classList.add('selected')">
                            <?= $meta['label'] ?>
                        </label>
                        <?php endforeach ?>
                    </div>

                    <!-- Capacity meter -->
                    <div class="meter-wrap">
                        <div class="meter-label">
                            <span><?= pt('Businesses managed') ?></span>
                            <span><?= $activeCount ?> / <?= $maxBiz ?></span>
                        </div>
                        <div class="meter-bar">
                            <div class="meter-fill <?= $fillPct >= 90 ? 'fill-high' : ($fillPct >= 60 ? 'fill-mid' : 'fill-low') ?>"
                                 style="width:<?= $fillPct ?>%"></div>
                        </div>
                    </div>

                    <!-- Max businesses slider -->
                    <p style="font-size:.82rem;font-weight:600;color:#374151;margin:1rem 0 .25rem"><?= pt('Maximum Businesses') ?></p>
                    <div class="range-wrap">
                        <input type="range" name="max_businesses" id="max-slider"
                               min="1" max="50" value="<?= $maxBiz ?>"
                               oninput="document.getElementById('max-display').textContent=this.value">
                        <div class="range-display" id="max-display"><?= $maxBiz ?></div>
                        <div class="range-sub"><?= pt('businesses maximum') ?><?= $activeCount ? ' (' . pt('currently managing') . ' ' . $activeCount . ')' : '' ?></div>
                    </div>

                    <label style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin:.75rem 0 .25rem">
                        <?= pt('Note (optional)') ?>
                    </label>
                    <textarea name="capacity_notes" rows="2" placeholder="<?= pt('e.g. On leave in August, reduced capacity…') ?>"
                              style="width:100%;border:1px solid #d1d5db;border-radius:.5rem;padding:.5rem .75rem;font-size:.875rem;box-sizing:border-box;resize:vertical"></textarea>

                    <button type="submit" class="btn btn-primary" style="margin-top:1rem;width:100%;justify-content:center">
                        <?= pt('Save Capacity Settings') ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Right: Stats + businesses -->
        <div>
            <!-- Quick stats -->
            <div class="card">
                <p class="card-title"><?= pt('Snapshot') ?></p>
                <?php
                $stats = [
                    [pt('Active businesses'), $activeCount, '🏢'],
                    [pt('Max capacity'), $maxBiz, '🎯'],
                    [pt('Slots available'), max(0, $maxBiz - $activeCount), '✅'],
                    [pt('Fill rate'), $fillPct . '%', '📊'],
                ];
                foreach ($stats as [$lbl, $val, $icon]): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid #f3f4f6;font-size:.85rem">
                    <span style="color:#6b7280"><?= $icon ?> <?= $lbl ?></span>
                    <span style="font-weight:700;color:#111827"><?= $val ?></span>
                </div>
                <?php endforeach ?>
            </div>

            <!-- Managed businesses -->
            <?php if ($businesses): ?>
            <div class="card">
                <p class="card-title"><?= pt('Managed Businesses') ?></p>
                <?php foreach ($businesses as $b): ?>
                <div class="biz-row">
                    <span class="biz-name"><?= e($b['business_name']) ?></span>
                    <span class="biz-tag <?= $b['assign_status'] === 'active' ? 'active' : 'inactive' ?>"><?= ucfirst($b['assign_status']) ?></span>
                </div>
                <?php endforeach ?>
            </div>
            <?php endif ?>
        </div>
    </div>

    <!-- Capacity Log -->
    <?php if ($log): ?>
    <div class="card">
        <p class="card-title"><?= pt('Capacity Change History') ?></p>
        <?php foreach ($log as $l):
            $dot = $l['new_status'] === 'accepting' ? 'up' : ($l['new_status'] === 'full' || $l['new_status'] === 'paused' ? 'down' : 'same');
        ?>
        <div class="log-item">
            <div class="log-dot <?= $dot ?>"></div>
            <div>
                <div>
                    <?= pt('Status') ?> <strong><?= e($l['previous_status']) ?></strong> → <strong><?= e($l['new_status']) ?></strong>
                    <?php if ($l['previous_max'] !== $l['new_max']): ?>
                    · <?= pt('Max') ?> <?= (int)$l['previous_max'] ?> → <?= (int)$l['new_max'] ?>
                    <?php endif ?>
                </div>
                <?php if ($l['notes']): ?><div style="color:#6b7280"><?= e($l['notes']) ?></div><?php endif ?>
                <div class="log-meta"><?= date('d M Y, H:i', strtotime($l['changed_at'])) ?> · <?= e($l['changed_by_name']) ?></div>
            </div>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
