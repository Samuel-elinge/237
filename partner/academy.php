<?php
/**
 * partner/academy.php — Growth Partner Academy (Task 50)
 *
 * Training modules with progress tracking.
 * Partners browse modules by category, mark lessons complete, earn module badges.
 * Admins can add/edit modules and lessons.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)$_SESSION['user_id'];
$isAdmin        = isAdmin();

// ── POST: mark lesson complete / incomplete ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action   = $_POST['action'] ?? '';
    $lessonId = (int)($_POST['lesson_id'] ?? 0);

    if ($action === 'complete' && $lessonId) {
        // Upsert completion record
        $pdo->prepare("
            INSERT INTO partner_lesson_progress (partner_id, lesson_id, completed_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE completed_at = NOW()
        ")->execute([$partnerId, $lessonId]);

        // Check if module is now fully complete → award module badge
        $moduleId = $pdo->prepare("SELECT module_id FROM partner_lessons WHERE id=?");
        $moduleId->execute([$lessonId]);
        $moduleId = (int)($moduleId->fetchColumn() ?: 0);

        if ($moduleId) {
            $totalLessons = $pdo->prepare("SELECT COUNT(*) FROM partner_lessons WHERE module_id=? AND is_active=1");
            $totalLessons->execute([$moduleId]);
            $totalLessons = (int)$totalLessons->fetchColumn();

            $doneCount = $pdo->prepare("
                SELECT COUNT(*) FROM partner_lesson_progress plp
                JOIN partner_lessons pl ON pl.id = plp.lesson_id
                WHERE plp.partner_id=? AND pl.module_id=? AND pl.is_active=1
            ");
            $doneCount->execute([$partnerId, $moduleId]);
            $doneCount = (int)$doneCount->fetchColumn();

            if ($totalLessons > 0 && $doneCount >= $totalLessons) {
                $pdo->prepare("
                    INSERT INTO partner_module_completions (partner_id, module_id, completed_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE completed_at = NOW()
                ")->execute([$partnerId, $moduleId]);
                partnerAuditLog($partnerId, $userId, null, 'module_completed', "Module ID: $moduleId");
            }
        }

        partnerAuditLog($partnerId, $userId, null, 'lesson_completed', "Lesson ID: $lessonId");
        setFlash('success', 'Lesson marked as complete!');
    }

    if ($action === 'uncomplete' && $lessonId) {
        $pdo->prepare("DELETE FROM partner_lesson_progress WHERE partner_id=? AND lesson_id=?")
            ->execute([$partnerId, $lessonId]);

        // Remove module completion if previously earned
        $moduleId = $pdo->prepare("SELECT module_id FROM partner_lessons WHERE id=?");
        $moduleId->execute([$lessonId]);
        $moduleId = (int)($moduleId->fetchColumn() ?: 0);
        if ($moduleId) {
            $pdo->prepare("DELETE FROM partner_module_completions WHERE partner_id=? AND module_id=?")
                ->execute([$partnerId, $moduleId]);
        }
        setFlash('success', 'Lesson unmarked.');
    }

    redirect(SITE_URL . '/partner/academy' . (isset($_GET['module']) ? '?module=' . (int)$_GET['module'] : ''));
}

// ── Category / module filter ──────────────────────────────
$filterCat   = trim($_GET['cat'] ?? '');
$viewModule  = (int)($_GET['module'] ?? 0);

// ── Load all modules with progress ───────────────────────
$modWhere  = $isAdmin ? '' : 'WHERE pm.is_active = 1';
$catFilter = '';
$modParams = [];
if ($filterCat) {
    $catFilter  = $modWhere ? ' AND pm.category = ?' : 'WHERE pm.category = ?';
    $modParams[] = $filterCat;
}

$modules = $pdo->prepare("
    SELECT pm.*,
        COUNT(pl.id) AS total_lessons,
        SUM(CASE WHEN plp.partner_id = $partnerId THEN 1 ELSE 0 END) AS done_lessons,
        MAX(CASE WHEN pmc.partner_id = $partnerId THEN 1 ELSE 0 END) AS module_done
    FROM partner_modules pm
    LEFT JOIN partner_lessons pl ON pl.module_id = pm.id AND pl.is_active = 1
    LEFT JOIN partner_lesson_progress plp ON plp.lesson_id = pl.id AND plp.partner_id = $partnerId
    LEFT JOIN partner_module_completions pmc ON pmc.module_id = pm.id AND pmc.partner_id = $partnerId
    $modWhere $catFilter
    GROUP BY pm.id
    ORDER BY pm.sort_order ASC, pm.id ASC
");
$modules->execute($modParams);
$modules = $modules->fetchAll();

// ── If viewing a specific module, load its lessons ───────
$currentModule  = null;
$lessons        = [];
$completedLessons = [];

if ($viewModule) {
    $currentModule = $pdo->prepare("SELECT * FROM partner_modules WHERE id=?" . ($isAdmin ? '' : ' AND is_active=1'));
    $currentModule->execute([$viewModule]);
    $currentModule = $currentModule->fetch();

    if ($currentModule) {
        $lessonQ = $pdo->prepare("
            SELECT pl.*,
                   (plp.id IS NOT NULL) AS is_done
            FROM partner_lessons pl
            LEFT JOIN partner_lesson_progress plp ON plp.lesson_id = pl.id AND plp.partner_id = ?
            WHERE pl.module_id = ?" . ($isAdmin ? '' : ' AND pl.is_active = 1') . "
            ORDER BY pl.sort_order ASC, pl.id ASC
        ");
        $lessonQ->execute([$partnerId, $viewModule]);
        $lessons = $lessonQ->fetchAll();

        foreach ($lessons as $l) {
            if ($l['is_done']) $completedLessons[] = $l['id'];
        }
    }
}

// ── Distinct categories ───────────────────────────────────
$categories = $pdo->query("SELECT DISTINCT category FROM partner_modules WHERE is_active=1 ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN);

// ── Overall progress stats ────────────────────────────────
$stats = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM partner_modules WHERE is_active=1) AS total_modules,
        (SELECT COUNT(*) FROM partner_module_completions WHERE partner_id=?) AS done_modules,
        (SELECT COUNT(*) FROM partner_lessons pl JOIN partner_modules pm ON pm.id=pl.module_id WHERE pl.is_active=1 AND pm.is_active=1) AS total_lessons,
        (SELECT COUNT(*) FROM partner_lesson_progress plp JOIN partner_lessons pl ON pl.id=plp.lesson_id JOIN partner_modules pm ON pm.id=pl.module_id WHERE plp.partner_id=? AND pl.is_active=1 AND pm.is_active=1) AS done_lessons
");
$stats->execute([$partnerId, $partnerId]);
$stats = $stats->fetch();

$flash     = getFlash();
$pageTitle = 'Partner Academy';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:960px;margin:0 auto;padding:1.5rem}
.progress-banner{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;border-radius:.75rem;padding:1.25rem 1.5rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:2rem;flex-wrap:wrap}
.prog-stat{text-align:center}
.prog-stat .val{font-size:1.8rem;font-weight:800;line-height:1}
.prog-stat .lbl{font-size:.75rem;opacity:.75;margin-top:.15rem}
.prog-bar-wrap{flex:1;min-width:180px}
.prog-bar-label{font-size:.8rem;opacity:.85;margin-bottom:.3rem}
.prog-track{height:10px;background:rgba(255,255,255,.25);border-radius:99px;overflow:hidden}
.prog-fill{height:100%;background:#fcd34d;border-radius:99px;transition:width .4s}
.layout{display:grid;grid-template-columns:220px 1fr;gap:1.25rem}
@media(max-width:640px){.layout{grid-template-columns:1fr}}
.sidebar{}
.cat-nav{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden;margin-bottom:.75rem}
.cat-nav a{display:block;padding:.55rem 1rem;font-size:.83rem;color:#374151;text-decoration:none;border-bottom:1px solid #f3f4f6}
.cat-nav a:last-child{border-bottom:none}
.cat-nav a.active,.cat-nav a:hover{background:#eff6ff;color:#1d4ed8}
.cat-nav .cat-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;padding:.5rem 1rem .25rem}
.main{}
.module-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;margin-bottom:.75rem;overflow:hidden;text-decoration:none;display:block;color:inherit}
.module-card:hover{border-color:#93c5fd;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.module-card.done{border-color:#a7f3d0}
.module-header{display:flex;align-items:flex-start;gap:1rem;padding:1rem 1.1rem}
.module-icon{width:44px;height:44px;border-radius:.6rem;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
.module-meta{flex:1;min-width:0}
.module-title{font-weight:700;font-size:.95rem;color:#111827;margin:0 0 .2rem}
.module-desc{font-size:.8rem;color:#6b7280;margin:0}
.module-badges{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.4rem}
.badge{font-size:.68rem;font-weight:600;padding:.15rem .45rem;border-radius:99px}
.badge-found{background:#dbeafe;color:#1d4ed8}
.badge-prof{background:#ede9fe;color:#6d28d9}
.badge-adv{background:#fef3c7;color:#92400e}
.badge-exp{background:#fee2e2;color:#991b1b}
.badge-done{background:#d1fae5;color:#065f46}
.badge-locked{background:#f3f4f6;color:#9ca3af}
.module-prog{padding:.5rem 1.1rem 1rem;border-top:1px solid #f9fafb}
.mini-track{height:5px;background:#f3f4f6;border-radius:99px;overflow:hidden;margin-bottom:.3rem}
.mini-fill{height:100%;background:#3b82f6;border-radius:99px}
.mini-fill.done{background:#10b981}
.mini-label{font-size:.72rem;color:#9ca3af}
/* Lesson view */
.back-link{display:inline-flex;align-items:center;gap:.3rem;font-size:.83rem;color:#2563eb;text-decoration:none;margin-bottom:1rem}
.module-hero{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.25rem;margin-bottom:1.25rem;display:flex;gap:1rem;align-items:flex-start}
.module-hero-icon{width:56px;height:56px;border-radius:.75rem;display:flex;align-items:center;justify-content:center;font-size:1.8rem;flex-shrink:0}
.lesson-list{}
.lesson-item{background:#fff;border:1px solid #e5e7eb;border-radius:.65rem;margin-bottom:.5rem;display:flex;align-items:flex-start;gap:.75rem;padding:.85rem 1rem}
.lesson-item.done-item{border-color:#a7f3d0;background:#f0fdf4}
.lesson-check{width:22px;height:22px;border-radius:50%;border:2px solid #d1d5db;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.05rem;color:#fff;font-size:.75rem}
.lesson-check.done-check{background:#10b981;border-color:#10b981}
.lesson-content{flex:1;min-width:0}
.lesson-title{font-weight:600;font-size:.875rem;color:#111827;margin:0 0 .2rem}
.lesson-desc{font-size:.78rem;color:#6b7280}
.lesson-meta{font-size:.72rem;color:#9ca3af;margin-top:.25rem;display:flex;gap:.75rem}
.lesson-actions{display:flex;gap:.35rem;align-items:center}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-success{background:#059669;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.btn-sm{padding:.25rem .55rem;font-size:.72rem}
.btn:hover{opacity:.9}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.empty{text-align:center;padding:3rem;color:#9ca3af}
.section-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.1rem 1.25rem;margin-bottom:.75rem}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827">Partner Academy</h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280">Training modules to sharpen your Growth Partner skills</p>
        </div>
        <a href="<?= SITE_URL ?>/partner/dashboard" class="btn btn-detail">← Dashboard</a>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <!-- Progress banner -->
    <?php if ($stats['total_modules'] > 0): ?>
    <div class="progress-banner">
        <div class="prog-stat">
            <div class="val"><?= (int)$stats['done_modules'] ?>/<?= (int)$stats['total_modules'] ?></div>
            <div class="lbl">Modules Complete</div>
        </div>
        <div class="prog-stat">
            <div class="val"><?= (int)$stats['done_lessons'] ?>/<?= (int)$stats['total_lessons'] ?></div>
            <div class="lbl">Lessons Done</div>
        </div>
        <div class="prog-bar-wrap">
            <?php $pct = $stats['total_lessons'] > 0 ? round($stats['done_lessons']/$stats['total_lessons']*100) : 0 ?>
            <div class="prog-bar-label">Overall progress: <?= $pct ?>%</div>
            <div class="prog-track"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
        </div>
    </div>
    <?php endif ?>

    <?php if ($currentModule): ?>
    <!-- ── Lesson view ── -->
    <a href="<?= SITE_URL ?>/partner/academy<?= $filterCat ? '?cat='.urlencode($filterCat) : '' ?>" class="back-link">← Back to modules</a>

    <div class="module-hero">
        <div class="module-hero-icon" style="background:<?= e($currentModule['color'] ?? '#dbeafe') ?>">
            <?= e($currentModule['icon'] ?? '📚') ?>
        </div>
        <div style="flex:1">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem">
                <h2 style="margin:0;font-size:1.1rem;color:#111827"><?= e($currentModule['title']) ?></h2>
                <?php
                $lvl = $currentModule['level'] ?? 'foundation';
                $lvlCls = ['foundation'=>'badge-found','professional'=>'badge-prof','advanced'=>'badge-adv','expert'=>'badge-exp'][$lvl] ?? 'badge-found';
                ?>
                <span class="badge <?= $lvlCls ?>"><?= ucfirst($lvl) ?></span>
            </div>
            <p style="margin:0;font-size:.85rem;color:#374151"><?= e($currentModule['description'] ?? '') ?></p>
            <?php
            $totalL = count($lessons);
            $doneL  = count($completedLessons);
            $modPct = $totalL > 0 ? round($doneL/$totalL*100) : 0;
            ?>
            <div style="margin-top:.75rem">
                <div style="font-size:.78rem;color:#6b7280;margin-bottom:.25rem"><?= $doneL ?>/<?= $totalL ?> lessons · <?= $modPct ?>% complete</div>
                <div class="mini-track" style="height:7px;width:200px"><div class="mini-fill<?= $modPct==100?' done':'' ?>" style="width:<?= $modPct ?>%"></div></div>
            </div>
        </div>
    </div>

    <?php if (!$lessons): ?>
    <div class="empty">
        <div style="font-size:2rem;margin-bottom:.5rem">📖</div>
        <p>No lessons in this module yet. Check back soon!</p>
    </div>
    <?php else: ?>
    <div class="lesson-list">
        <?php foreach ($lessons as $lesson):
            $isDone = in_array($lesson['id'], $completedLessons);
        ?>
        <div class="lesson-item <?= $isDone ? 'done-item' : '' ?>">
            <div class="lesson-check <?= $isDone ? 'done-check' : '' ?>"><?= $isDone ? '✓' : '' ?></div>
            <div class="lesson-content">
                <div class="lesson-title"><?= e($lesson['title']) ?></div>
                <?php if ($lesson['description']): ?>
                <div class="lesson-desc"><?= e($lesson['description']) ?></div>
                <?php endif ?>
                <div class="lesson-meta">
                    <?php if ($lesson['duration_mins']): ?><span>⏱ <?= (int)$lesson['duration_mins'] ?> min</span><?php endif ?>
                    <?php if ($lesson['type']): ?><span>📄 <?= ucfirst($lesson['type']) ?></span><?php endif ?>
                    <?php if ($isDone && $lesson['completed_at'] ?? null): ?><span>✓ Done</span><?php endif ?>
                </div>
                <?php if ($lesson['content_url']): ?>
                <a href="<?= e($lesson['content_url']) ?>" target="_blank" class="btn btn-detail btn-sm" style="margin-top:.5rem">Open Lesson →</a>
                <?php endif ?>
            </div>
            <div class="lesson-actions">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="lesson_id" value="<?= $lesson['id'] ?>">
                    <input type="hidden" name="action" value="<?= $isDone ? 'uncomplete' : 'complete' ?>">
                    <?php if ($_GET['module'] ?? ''): ?>
                    <input type="hidden" name="module" value="<?= (int)$_GET['module'] ?>">
                    <?php endif ?>
                    <button type="submit" class="btn <?= $isDone ? 'btn-detail' : 'btn-success' ?> btn-sm">
                        <?= $isDone ? '✕ Unmark' : '✓ Complete' ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <?php else: ?>
    <!-- ── Module grid ── -->
    <div class="layout">
        <div class="sidebar">
            <div class="cat-nav">
                <div class="cat-label">Category</div>
                <a href="?<?= $viewModule ? 'module='.$viewModule.'&' : '' ?>" class="<?= !$filterCat ? 'active' : '' ?>">All modules</a>
                <?php foreach ($categories as $cat): ?>
                <a href="?cat=<?= urlencode($cat) ?>" class="<?= $filterCat === $cat ? 'active' : '' ?>"><?= e(ucfirst($cat)) ?></a>
                <?php endforeach ?>
            </div>
            <a href="<?= SITE_URL ?>/partner/certifications" class="btn btn-detail" style="width:100%;justify-content:center;margin-bottom:.5rem">My Certifications</a>
            <a href="<?= SITE_URL ?>/partner/resources" class="btn btn-detail" style="width:100%;justify-content:center">Resources →</a>
        </div>

        <div class="main">
            <?php if (!$modules): ?>
            <div class="empty">
                <div style="font-size:2.5rem;margin-bottom:.5rem">🎓</div>
                <p>No training modules available yet. Check back soon!</p>
            </div>
            <?php else: ?>
            <?php foreach ($modules as $m):
                $total   = (int)$m['total_lessons'];
                $done    = (int)$m['done_lessons'];
                $pct     = $total > 0 ? round($done/$total*100) : 0;
                $modDone = (bool)$m['module_done'];
                $lvl     = $m['level'] ?? 'foundation';
                $lvlCls  = ['foundation'=>'badge-found','professional'=>'badge-prof','advanced'=>'badge-adv','expert'=>'badge-exp'][$lvl] ?? 'badge-found';
                $bgColor = $m['color'] ?? '#dbeafe';
            ?>
            <a href="?module=<?= $m['id'] ?><?= $filterCat ? '&cat='.urlencode($filterCat) : '' ?>" class="module-card <?= $modDone ? 'done' : '' ?>">
                <div class="module-header">
                    <div class="module-icon" style="background:<?= e($bgColor) ?>"><?= e($m['icon'] ?? '📚') ?></div>
                    <div class="module-meta">
                        <div class="module-title"><?= e($m['title']) ?></div>
                        <div class="module-desc"><?= e(mb_strimwidth($m['description'] ?? '', 0, 100, '…')) ?></div>
                        <div class="module-badges">
                            <span class="badge <?= $lvlCls ?>"><?= ucfirst($lvl) ?></span>
                            <?php if ($modDone): ?><span class="badge badge-done">✓ Complete</span><?php endif ?>
                            <?php if (!$m['is_active']): ?><span class="badge badge-locked">Draft</span><?php endif ?>
                            <span class="badge" style="background:#f3f4f6;color:#6b7280"><?= $total ?> lesson<?= $total!=1?'s':'' ?></span>
                        </div>
                    </div>
                </div>
                <div class="module-prog">
                    <div class="mini-track"><div class="mini-fill <?= $pct==100?'done':'' ?>" style="width:<?= $pct ?>%"></div></div>
                    <div class="mini-label"><?= $done ?>/<?= $total ?> lessons · <?= $pct ?>%</div>
                </div>
            </a>
            <?php endforeach ?>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
