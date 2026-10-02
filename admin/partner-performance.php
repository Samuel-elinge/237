<?php
/**
 * admin/partner-performance.php — Individual Partner Performance (Task 59)
 *
 * Detailed per-partner performance view: KPIs, lead pipeline, commissions,
 * referrals, activity timeline, and admin score override tools.
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

    // Update performance score manually
    if ($action === 'set_score' && $partnerId) {
        $score = max(0, min(100, (int)($_POST['score'] ?? 0)));
        $pdo->prepare("UPDATE partner_profiles SET performance_score=?, score_updated_at=NOW(), score_note=? WHERE id=?")
            ->execute([$score, trim($_POST['score_note'] ?? ''), $partnerId]);
        setFlash('success', 'Performance score updated.');
        redirect(SITE_URL . '/admin/partner-performance?id=' . $partnerId);
    }

    // Update tier
    if ($action === 'set_tier' && $partnerId) {
        $tier = in_array($_POST['tier'] ?? '', ['bronze','silver','gold','platinum']) ? $_POST['tier'] : 'bronze';
        $pdo->prepare("UPDATE partner_profiles SET tier=?, tier_updated_at=NOW() WHERE id=?")
            ->execute([$tier, $partnerId]);
        partnerAuditLog($partnerId, 'tier_changed', "Tier set to {$tier} by admin");
        pushNotification(
            $pdo->query("SELECT user_id FROM partner_profiles WHERE id={$partnerId}")->fetchColumn(),
            "Your partner tier has been updated to " . ucfirst($tier) . "!",
            SITE_URL . '/partner/dashboard'
        );
        setFlash('success', 'Tier updated.');
        redirect(SITE_URL . '/admin/partner-performance?id=' . $partnerId);
    }

    // Add performance note
    if ($action === 'add_note' && $partnerId) {
        $note = trim($_POST['note'] ?? '');
        if ($note) {
            $pdo->prepare("INSERT INTO partner_performance_notes (partner_id, admin_user_id, note, created_at) VALUES (?,?,?,NOW())")
                ->execute([$partnerId, $_SESSION['user_id'], $note]);
            setFlash('success', 'Note added.');
        }
        redirect(SITE_URL . '/admin/partner-performance?id=' . $partnerId);
    }

    redirect(SITE_URL . '/admin/partner-performance');
}

// ── Partner list (for sidebar) ────────────────────────────
$partnerList = $pdo->query("
    SELECT id, display_name, tier, status, performance_score, active_clients, monthly_revenue
    FROM partner_profiles
    WHERE status IN ('active','approved','suspended')
    ORDER BY monthly_revenue DESC, display_name ASC
")->fetchAll();

// ── Selected partner ──────────────────────────────────────
$selectedId = (int)($_GET['id'] ?? 0);
$partner    = null;
$pStats     = [];
$leadFunnel = [];
$commSummary = [];
$recentActivity = [];
$perfNotes  = [];

if ($selectedId) {
    $pq = $pdo->prepare("
        SELECT pp.*, u.name AS user_name, u.email, u.created_at AS joined_at
        FROM partner_profiles pp
        JOIN users u ON u.id = pp.user_id
        WHERE pp.id=?
    ");
    $pq->execute([$selectedId]);
    $partner = $pq->fetch();

    if ($partner) {
        // KPI stats
        $pStats = $pdo->prepare("
            SELECT
                COUNT(DISTINCT pl.id) AS total_leads,
                SUM(CASE WHEN pl.status='converted' THEN 1 ELSE 0 END) AS converted_leads,
                SUM(CASE WHEN pl.status='lost' THEN 1 ELSE 0 END) AS lost_leads,
                COUNT(DISTINCT pr.id) AS total_referrals,
                SUM(CASE WHEN pr.status='converted' THEN pr.commission_amount ELSE 0 END) AS ref_earned,
                COUNT(DISTINCT pa.id) AS total_assignments,
                SUM(CASE WHEN pa.status='completed' THEN 1 ELSE 0 END) AS completed_assignments
            FROM partner_profiles pp
            LEFT JOIN partner_leads pl ON pl.partner_id = pp.id
            LEFT JOIN partner_referrals pr ON pr.partner_id = pp.id
            LEFT JOIN partner_assignments pa ON pa.partner_id = pp.id
            WHERE pp.id=?
        ");
        $pStats->execute([$selectedId]);
        $pStats = $pStats->fetch();

        // Lead funnel
        $lf = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM partner_leads WHERE partner_id=? GROUP BY status");
        $lf->execute([$selectedId]);
        $leadFunnel = $lf->fetchAll(\PDO::FETCH_KEY_PAIR);

        // Commission summary
        $cs = $pdo->prepare("
            SELECT status, COUNT(*) AS cnt, SUM(amount) AS total
            FROM partner_commissions WHERE partner_id=?
            GROUP BY status
        ");
        $cs->execute([$selectedId]);
        $commSummary = $cs->fetchAll();

        // Recent activity (audit log)
        $ra = $pdo->prepare("
            SELECT * FROM partner_audit_logs WHERE partner_id=? ORDER BY created_at DESC LIMIT 20
        ");
        $ra->execute([$selectedId]);
        $recentActivity = $ra->fetchAll();

        // Performance notes
        $pn = $pdo->prepare("
            SELECT pn.*, u.name AS admin_name
            FROM partner_performance_notes pn
            LEFT JOIN users u ON u.id = pn.admin_user_id
            WHERE pn.partner_id=?
            ORDER BY pn.created_at DESC
            LIMIT 10
        ");
        $pn->execute([$selectedId]);
        $perfNotes = $pn->fetchAll();
    }
}

$scoreColors = ['0'=>'#ef4444','25'=>'#f59e0b','50'=>'#3b82f6','75'=>'#10b981'];
$tierColors  = ['bronze'=>'#cd7f32','silver'=>'#9ca3af','gold'=>'#f59e0b','platinum'=>'#8b5cf6'];

$flash     = getFlash();
$pageTitle = 'Partner Performance';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{display:grid;grid-template-columns:260px 1fr;gap:0;max-width:1200px;margin:0 auto;min-height:80vh}
.sidebar{border-right:1px solid #e5e7eb;overflow-y:auto;background:#fafafa}
.sidebar-header{padding:.75rem 1rem;border-bottom:1px solid #e5e7eb;font-size:.85rem;font-weight:700;color:#111827;position:sticky;top:0;background:#fafafa}
.partner-item{display:flex;align-items:center;gap:.5rem;padding:.55rem .85rem;border-bottom:1px solid #f3f4f6;text-decoration:none;color:#374151;font-size:.82rem}
.partner-item:hover,.partner-item.active{background:#f0f7ff}
.partner-item .p-name{font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.partner-item .p-meta{font-size:.7rem;color:#9ca3af}
.main{padding:1.5rem;overflow-y:auto}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.65rem;margin-bottom:1.25rem}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:.65rem;padding:.7rem .9rem;text-align:center}
.stat-val{font-size:1.5rem;font-weight:800;color:#111827}
.stat-lbl{font-size:.68rem;color:#9ca3af}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;margin-bottom:1rem;overflow:hidden}
.card-header{padding:.65rem 1rem;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.card-header h2{margin:0;font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280}
.card-body{padding:.85rem 1rem}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
.funnel-row{display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem;font-size:.8rem}
.funnel-name{width:85px;text-align:right;color:#374151}
.funnel-track{flex:1;height:16px;background:#f3f4f6;border-radius:.2rem;overflow:hidden}
.funnel-fill{height:100%;border-radius:.2rem}
.funnel-cnt{width:36px;text-align:right;font-weight:700;font-size:.82rem}
.activity-row{display:flex;gap:.5rem;padding:.4rem 0;border-bottom:1px solid #f3f4f6;font-size:.78rem}
.activity-row:last-child{border-bottom:none}
.activity-time{color:#9ca3af;white-space:nowrap;width:95px;flex-shrink:0}
.activity-action{color:#374151}
.score-ring{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:6px solid;font-size:1.4rem;font-weight:800}
.badge{font-size:.7rem;font-weight:600;padding:.15rem .45rem;border-radius:99px}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-ghost{background:#f3f4f6;color:#374151}
.btn-sm{padding:.25rem .55rem;font-size:.72rem}
.btn:hover{opacity:.9}
.form-group label{display:block;font-size:.72rem;font-weight:700;color:#374151;margin-bottom:.25rem}
.form-group input,.form-group select,.form-group textarea{width:100%;border:1px solid #e5e7eb;border-radius:.4rem;padding:.4rem .6rem;font-size:.83rem;box-sizing:border-box}
.form-group{margin-bottom:.65rem}
.note-item{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;font-size:.8rem}
.note-item:last-child{border-bottom:none}
.note-text{color:#374151}
.note-meta{font-size:.7rem;color:#9ca3af;margin-top:.15rem}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.empty{text-align:center;padding:3rem;color:#9ca3af}
</style>

<div style="max-width:1200px;margin:0 auto;padding:1rem 1.5rem .5rem;display:flex;align-items:center;justify-content:space-between">
    <div>
        <h1 style="margin:0;font-size:1.2rem;color:#111827">Partner Performance</h1>
        <p style="margin:.15rem 0 0;font-size:.8rem;color:#6b7280">Individual partner KPIs and management tools</p>
    </div>
    <a href="<?= SITE_URL ?>/admin" class="btn btn-ghost btn-sm">← Admin</a>
</div>

<?php if ($flash): ?>
<div style="max-width:1200px;margin:0 auto;padding:0 1.5rem">
<div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
</div>
<?php endif ?>

<div style="max-width:1200px;margin:.5rem auto 2rem;padding:0 1.5rem">
<div class="page-wrap" style="border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden">

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">Partners (<?= count($partnerList) ?>)</div>
        <?php foreach ($partnerList as $pl): ?>
        <a href="?id=<?= $pl['id'] ?>" class="partner-item<?= $selectedId==$pl['id']?' active':'' ?>">
            <div style="flex:1;min-width:0">
                <div class="p-name"><?= e($pl['display_name']) ?></div>
                <div class="p-meta"><?= ucfirst($pl['tier'] ?? 'std') ?> · <?= $pl['active_clients'] ?> clients</div>
            </div>
            <?php $sc = $pl['performance_score'] ?? 0; ?>
            <div style="font-size:.75rem;font-weight:700;color:<?= $sc>=75?'#059669':($sc>=50?'#2563eb':($sc>=25?'#d97706':'#dc2626')) ?>"><?= $sc ?></div>
        </a>
        <?php endforeach ?>
    </div>

    <!-- Main panel -->
    <div class="main">
        <?php if (!$partner): ?>
        <div class="empty">
            <div style="font-size:2.5rem;margin-bottom:.75rem">📊</div>
            <p style="margin:0;font-size:.9rem">Select a partner to view their performance</p>
        </div>

        <?php else: ?>

        <!-- Partner header -->
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap">
            <?php $score = $partner['performance_score'] ?? 0;
                  $scoreColor = $score>=75?'#10b981':($score>=50?'#3b82f6':($score>=25?'#f59e0b':'#ef4444')); ?>
            <div class="score-ring" style="border-color:<?= $scoreColor ?>;color:<?= $scoreColor ?>"><?= $score ?></div>
            <div style="flex:1">
                <div style="font-size:1.2rem;font-weight:700;color:#111827"><?= e($partner['display_name']) ?></div>
                <div style="font-size:.83rem;color:#6b7280"><?= e($partner['email']) ?></div>
                <div style="display:flex;gap:.5rem;margin-top:.3rem;flex-wrap:wrap">
                    <span class="badge" style="background:<?= $tierColors[$partner['tier'] ?? 'bronze'] ?? '#cd7f32' ?>22;color:<?= $tierColors[$partner['tier'] ?? 'bronze'] ?? '#cd7f32' ?>"><?= ucfirst($partner['tier'] ?? 'Bronze') ?></span>
                    <span class="badge" style="background:<?= $partner['status']==='active'?'#d1fae5':'#fee2e2' ?>;color:<?= $partner['status']==='active'?'#065f46':'#991b1b' ?>"><?= ucfirst($partner['status']) ?></span>
                    <span class="badge" style="background:#f3f4f6;color:#6b7280">Joined <?= date('M Y', strtotime($partner['joined_at'])) ?></span>
                </div>
            </div>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                <a href="<?= SITE_URL ?>/admin/manage-growth-partners?action=view&id=<?= $selectedId ?>" class="btn btn-ghost btn-sm">View Profile</a>
            </div>
        </div>

        <!-- KPIs -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-val"><?= $partner['active_clients'] ?></div>
                <div class="stat-lbl">Active Clients</div>
            </div>
            <div class="stat-card">
                <div class="stat-val">£<?= number_format($partner['monthly_revenue']/1000,1) ?>k</div>
                <div class="stat-lbl">Monthly Rev</div>
            </div>
            <div class="stat-card">
                <div class="stat-val"><?= $pStats['total_leads'] ?></div>
                <div class="stat-lbl">Total Leads</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:#059669"><?= $pStats['converted_leads'] ?></div>
                <div class="stat-lbl">Converted</div>
            </div>
            <div class="stat-card">
                <div class="stat-val"><?= $pStats['total_referrals'] ?></div>
                <div class="stat-lbl">Referrals</div>
            </div>
            <div class="stat-card">
                <div class="stat-val"><?= $pStats['completed_assignments'] ?>/<?= $pStats['total_assignments'] ?></div>
                <div class="stat-lbl">Assignments</div>
            </div>
        </div>

        <div class="grid-2">
            <!-- Lead funnel -->
            <div class="card">
                <div class="card-header"><h2>Lead Funnel</h2></div>
                <div class="card-body">
                    <?php $fMax = $leadFunnel ? max(array_values($leadFunnel)) : 1;
                          foreach (['new','contacted','qualified','proposal','negotiation','converted','lost'] as $s):
                            $cnt = $leadFunnel[$s] ?? 0; ?>
                    <div class="funnel-row">
                        <div class="funnel-name"><?= ucfirst($s) ?></div>
                        <div class="funnel-track">
                            <div class="funnel-fill" style="width:<?= $fMax>0?round($cnt/$fMax*100):0 ?>%;background:<?= $s==='converted'?'#10b981':($s==='lost'?'#ef4444':'#3b82f6') ?>"></div>
                        </div>
                        <div class="funnel-cnt"><?= $cnt ?></div>
                    </div>
                    <?php endforeach ?>
                </div>
            </div>

            <!-- Commission summary -->
            <div class="card">
                <div class="card-header"><h2>Commissions</h2></div>
                <div class="card-body">
                    <?php if ($commSummary): ?>
                    <?php foreach ($commSummary as $cs): ?>
                    <div style="display:flex;justify-content:space-between;font-size:.83rem;margin-bottom:.5rem">
                        <span style="text-transform:capitalize;color:#374151"><?= e($cs['status']) ?></span>
                        <span><?= $cs['cnt'] ?> payments</span>
                        <span style="font-weight:700;color:#059669">£<?= number_format($cs['total']) ?></span>
                    </div>
                    <?php endforeach ?>
                    <?php else: ?><p style="color:#9ca3af;font-size:.8rem;text-align:center">No commission records.</p><?php endif ?>
                </div>
            </div>
        </div>

        <!-- Admin tools -->
        <div class="grid-2">
            <!-- Score override -->
            <div class="card">
                <div class="card-header"><h2>Performance Score</h2></div>
                <div class="card-body">
                    <form method="post" action="">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_score">
                        <input type="hidden" name="partner_id" value="<?= $selectedId ?>">
                        <div class="form-group">
                            <label>Score (0–100)</label>
                            <input type="number" name="score" min="0" max="100" value="<?= $partner['performance_score'] ?? 0 ?>">
                        </div>
                        <div class="form-group">
                            <label>Note (optional)</label>
                            <input type="text" name="score_note" placeholder="Reason for manual override" value="<?= e($partner['score_note'] ?? '') ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Update Score</button>
                    </form>
                </div>
            </div>

            <!-- Tier override -->
            <div class="card">
                <div class="card-header"><h2>Partner Tier</h2></div>
                <div class="card-body">
                    <form method="post" action="">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_tier">
                        <input type="hidden" name="partner_id" value="<?= $selectedId ?>">
                        <div class="form-group">
                            <label>Tier</label>
                            <select name="tier">
                                <?php foreach (['bronze','silver','gold','platinum'] as $t): ?>
                                <option value="<?= $t ?>" <?= ($partner['tier']??'bronze')===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Update Tier</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="grid-2">
            <!-- Recent activity -->
            <div class="card">
                <div class="card-header"><h2>Recent Activity</h2></div>
                <div class="card-body" style="max-height:280px;overflow-y:auto;padding:.5rem 0">
                    <?php if ($recentActivity): ?>
                    <?php foreach ($recentActivity as $ra): ?>
                    <div class="activity-row">
                        <div class="activity-time"><?= date('d M H:i', strtotime($ra['created_at'])) ?></div>
                        <div class="activity-action"><strong><?= e($ra['action']) ?></strong> <?= e(mb_strimwidth($ra['description'] ?? '', 0, 60, '…')) ?></div>
                    </div>
                    <?php endforeach ?>
                    <?php else: ?><div style="padding:1rem;text-align:center;color:#9ca3af;font-size:.8rem">No activity logged.</div><?php endif ?>
                </div>
            </div>

            <!-- Admin notes -->
            <div class="card">
                <div class="card-header"><h2>Admin Notes</h2></div>
                <div style="max-height:200px;overflow-y:auto">
                    <?php if ($perfNotes): ?>
                    <?php foreach ($perfNotes as $pn): ?>
                    <div class="note-item">
                        <div class="note-text"><?= nl2br(e($pn['note'])) ?></div>
                        <div class="note-meta"><?= e($pn['admin_name'] ?? 'Admin') ?> · <?= date('d M Y', strtotime($pn['created_at'])) ?></div>
                    </div>
                    <?php endforeach ?>
                    <?php else: ?><div style="padding:1rem;text-align:center;color:#9ca3af;font-size:.8rem">No notes yet.</div><?php endif ?>
                </div>
                <div style="padding:.65rem .85rem;border-top:1px solid #e5e7eb">
                    <form method="post" action="" style="display:flex;gap:.4rem">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="add_note">
                        <input type="hidden" name="partner_id" value="<?= $selectedId ?>">
                        <input type="text" name="note" placeholder="Add a note…" required style="flex:1;border:1px solid #e5e7eb;border-radius:.35rem;padding:.35rem .6rem;font-size:.8rem">
                        <button type="submit" class="btn btn-primary btn-sm">Add</button>
                    </form>
                </div>
            </div>
        </div>

        <?php endif ?>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
