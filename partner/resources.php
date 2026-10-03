<?php
/**
 * partner/resources.php — Partner Resource Library (Task 51)
 *
 * Partners browse downloadable/viewable resources: templates, guides, toolkits.
 * Admins can add/manage resources. Download/view counts tracked.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$pdo = db();
$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)$_SESSION['user_id'];
$isAdmin        = isAdmin();

// ── POST: track download ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action     = $_POST['action'] ?? '';
    $resourceId = (int)($_POST['resource_id'] ?? 0);

    if ($action === 'download' && $resourceId) {
        $res = $pdo->prepare("SELECT * FROM partner_resources WHERE id=? AND is_active=1");
        $res->execute([$resourceId]);
        $res = $res->fetch();

        if ($res) {
            // Log download
            $pdo->prepare("
                INSERT INTO partner_resource_downloads (resource_id, partner_id, downloaded_at)
                VALUES (?,?,NOW())
            ")->execute([$resourceId, $partnerId]);

            // Increment download counter
            $pdo->prepare("UPDATE partner_resources SET download_count = download_count + 1 WHERE id=?")
                ->execute([$resourceId]);

            partnerAuditLog($partnerId, $userId, null, 'resource_downloaded', "Resource: {$res['title']}");

            // Redirect to file
            if ($res['file_url']) {
                header('Location: ' . $res['file_url']);
                exit;
            }
        }
        setFlash('error', 'Resource not available.');
        redirect(SITE_URL . '/partner/resources');
    }
    redirect(SITE_URL . '/partner/resources');
}

// ── Filters ───────────────────────────────────────────────
$filterCat  = trim($_GET['cat'] ?? '');
$filterType = trim($_GET['type'] ?? '');
$search     = trim($_GET['q'] ?? '');

$where  = ['pr.is_active = 1'];
$params = [];

if ($filterCat)  { $where[] = 'pr.category = ?';    $params[] = $filterCat; }
if ($filterType) { $where[] = 'pr.resource_type = ?'; $params[] = $filterType; }
if ($search)     { $where[] = '(pr.title LIKE ? OR pr.description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$resources = $pdo->prepare("
    SELECT pr.*,
           (SELECT COUNT(*) FROM partner_resource_downloads prd WHERE prd.resource_id=pr.id AND prd.partner_id=?) AS my_downloads
    FROM partner_resources pr
    $whereSQL
    ORDER BY pr.is_featured DESC, pr.created_at DESC
");
$resources->execute(array_merge([$partnerId], $params));
$resources = $resources->fetchAll();

// Distinct categories and types for filters
$cats  = $pdo->query("SELECT DISTINCT category FROM partner_resources WHERE is_active=1 AND category IS NOT NULL ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN);
$types = $pdo->query("SELECT DISTINCT resource_type FROM partner_resources WHERE is_active=1 AND resource_type IS NOT NULL ORDER BY resource_type")->fetchAll(\PDO::FETCH_COLUMN);

// Featured / recently downloaded
$featured = array_filter($resources, fn($r) => $r['is_featured']);
$myRecent = $pdo->prepare("
    SELECT pr.*, prd.downloaded_at
    FROM partner_resource_downloads prd
    JOIN partner_resources pr ON pr.id = prd.resource_id
    WHERE prd.partner_id = ?
    ORDER BY prd.downloaded_at DESC
    LIMIT 5
");
$myRecent->execute([$partnerId]);
$myRecent = $myRecent->fetchAll();

$typeIcons = [
    'guide'    => '📖',
    'template' => '📋',
    'toolkit'  => '🧰',
    'video'    => '🎬',
    'checklist'=> '✅',
    'script'   => '💬',
    'report'   => '📊',
];

$flash     = getFlash();
$pageTitle = pt('Resource Library');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:960px;margin:0 auto;padding:1.5rem}
.layout{display:grid;grid-template-columns:200px 1fr;gap:1.25rem}
@media(max-width:640px){.layout{grid-template-columns:1fr}}
.sidebar{}
.filter-box{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden;margin-bottom:.75rem}
.filter-box .filter-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;padding:.6rem 1rem .25rem;border-bottom:1px solid #f3f4f6}
.filter-box a{display:block;padding:.45rem 1rem;font-size:.82rem;color:#374151;text-decoration:none;border-bottom:1px solid #f9fafb}
.filter-box a:last-child{border-bottom:none}
.filter-box a.active,.filter-box a:hover{background:#eff6ff;color:#1d4ed8}
.main{}
.search-bar{display:flex;gap:.5rem;margin-bottom:1rem}
.search-bar input{flex:1;border:1px solid #d1d5db;border-radius:.5rem;padding:.45rem .75rem;font-size:.875rem}
.active-filters{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem}
.active-chip{background:#eff6ff;color:#1d4ed8;font-size:.75rem;padding:.2rem .55rem;border-radius:99px;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem}
.active-chip .x{opacity:.6;font-size:.9em}
.resource-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.85rem;margin-bottom:1.5rem}
.resource-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.1rem;display:flex;flex-direction:column;gap:.6rem;position:relative}
.resource-card.featured{border-color:#fbbf24;background:#fffbeb}
.res-type-icon{font-size:1.6rem;line-height:1}
.res-title{font-weight:700;font-size:.9rem;color:#111827;margin:0}
.res-desc{font-size:.78rem;color:#6b7280;line-height:1.45;flex:1}
.res-meta{display:flex;align-items:center;gap:.5rem;font-size:.72rem;color:#9ca3af;flex-wrap:wrap}
.badge{font-size:.68rem;font-weight:600;padding:.15rem .45rem;border-radius:99px}
.badge-guide{background:#dbeafe;color:#1d4ed8}
.badge-template{background:#ede9fe;color:#6d28d9}
.badge-toolkit{background:#d1fae5;color:#065f46}
.badge-video{background:#fee2e2;color:#991b1b}
.badge-checklist{background:#fef9c3;color:#92400e}
.badge-script{background:#f3f4f6;color:#374151}
.badge-report{background:#f0fdf4;color:#15803d}
.badge-featured{background:#fef3c7;color:#92400e;position:absolute;top:.65rem;right:.75rem}
.res-actions{margin-top:auto}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.btn-sm{padding:.25rem .55rem;font-size:.72rem}
.btn:hover{opacity:.9}
.section-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin:0 0 .6rem}
.recent-card{background:#fff;border:1px solid #e5e7eb;border-radius:.65rem;padding:.7rem 1rem;display:flex;align-items:center;gap:.75rem;margin-bottom:.4rem}
.recent-icon{font-size:1.2rem}
.recent-meta{flex:1}
.recent-title{font-size:.83rem;font-weight:600;color:#111827}
.recent-date{font-size:.72rem;color:#9ca3af}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.empty{text-align:center;padding:3rem;color:#9ca3af}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827"><?= pt('Resource Library') ?></h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280"><?= pt('Templates, guides and tools to help you succeed') ?></p>
        </div>
        <div style="display:flex;gap:.5rem">
            <a href="<?= SITE_URL ?>/partner/academy" class="btn btn-detail"><?= pt('Academy') ?></a>
            <a href="<?= SITE_URL ?>/partner/dashboard" class="btn btn-detail">← <?= pt('Dashboard') ?></a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <div class="layout">
        <div class="sidebar">
            <?php if ($myRecent): ?>
            <div class="filter-box" style="margin-bottom:.75rem">
                <div class="filter-title"><?= pt('Recently Accessed') ?></div>
                <?php foreach ($myRecent as $r): ?>
                <div style="padding:.45rem 1rem;border-bottom:1px solid #f9fafb">
                    <div style="font-size:.78rem;font-weight:600;color:#111827"><?= e(mb_strimwidth($r['title'],0,35,'…')) ?></div>
                    <div style="font-size:.7rem;color:#9ca3af"><?= date('d M', strtotime($r['downloaded_at'])) ?></div>
                </div>
                <?php endforeach ?>
            </div>
            <?php endif ?>

            <div class="filter-box">
                <div class="filter-title"><?= pt('Category') ?></div>
                <a href="?<?= $filterType ? 'type='.urlencode($filterType).'&' : '' ?><?= $search ? 'q='.urlencode($search).'&' : '' ?>" class="<?= !$filterCat ? 'active' : '' ?>"><?= pt('All') ?></a>
                <?php foreach ($cats as $cat): ?>
                <a href="?cat=<?= urlencode($cat) ?><?= $filterType ? '&type='.urlencode($filterType) : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="<?= $filterCat===$cat ? 'active' : '' ?>"><?= e(ucfirst($cat)) ?></a>
                <?php endforeach ?>
            </div>

            <div class="filter-box" style="margin-top:.75rem">
                <div class="filter-title"><?= pt('Type') ?></div>
                <a href="?<?= $filterCat ? 'cat='.urlencode($filterCat).'&' : '' ?><?= $search ? 'q='.urlencode($search).'&' : '' ?>" class="<?= !$filterType ? 'active' : '' ?>"><?= pt('All types') ?></a>
                <?php foreach ($types as $type): ?>
                <a href="?type=<?= urlencode($type) ?><?= $filterCat ? '&cat='.urlencode($filterCat) : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="<?= $filterType===$type ? 'active' : '' ?>"><?= ($typeIcons[$type] ?? '📄') . ' ' . e(ucfirst($type)) ?></a>
                <?php endforeach ?>
            </div>
        </div>

        <div class="main">
            <form method="get" class="search-bar">
                <?php if ($filterCat): ?><input type="hidden" name="cat" value="<?= e($filterCat) ?>"><?php endif ?>
                <?php if ($filterType): ?><input type="hidden" name="type" value="<?= e($filterType) ?>"><?php endif ?>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= pt('Search resources…') ?>">
                <button type="submit" class="btn btn-detail"><?= pt('Search') ?></button>
                <?php if ($search): ?><a href="?<?= $filterCat ? 'cat='.urlencode($filterCat) : '' ?><?= $filterType ? '&type='.urlencode($filterType) : '' ?>" class="btn btn-detail"><?= pt('Clear') ?></a><?php endif ?>
            </form>

            <?php if ($filterCat || $filterType || $search): ?>
            <div class="active-filters">
                <?php if ($filterCat): ?>
                <a href="?<?= $filterType ? 'type='.urlencode($filterType) : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="active-chip">📂 <?= e(ucfirst($filterCat)) ?> <span class="x">×</span></a>
                <?php endif ?>
                <?php if ($filterType): ?>
                <a href="?<?= $filterCat ? 'cat='.urlencode($filterCat) : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="active-chip"><?= ($typeIcons[$filterType] ?? '📄') ?> <?= e(ucfirst($filterType)) ?> <span class="x">×</span></a>
                <?php endif ?>
                <?php if ($search): ?>
                <a href="?<?= $filterCat ? 'cat='.urlencode($filterCat) : '' ?><?= $filterType ? '&type='.urlencode($filterType) : '' ?>" class="active-chip">🔍 "<?= e($search) ?>" <span class="x">×</span></a>
                <?php endif ?>
            </div>
            <?php endif ?>

            <?php if (!$resources): ?>
            <div class="empty">
                <div style="font-size:2.5rem;margin-bottom:.5rem">📂</div>
                <p><?= pt('No resources found') ?><?= ($filterCat || $filterType || $search) ? ' ' . pt('for your filters') : '' ?>.</p>
                <?php if ($filterCat || $filterType || $search): ?>
                <a href="<?= SITE_URL ?>/partner/resources" class="btn btn-detail" style="margin-top:.5rem"><?= pt('Clear filters') ?></a>
                <?php endif ?>
            </div>
            <?php else: ?>

            <?php if (!$filterCat && !$filterType && !$search && $featured): ?>
            <p class="section-title"><?= pt('⭐ Featured Resources') ?></p>
            <div class="resource-grid">
                <?php foreach ($featured as $r):
                    $typeBadge = 'badge-' . ($r['resource_type'] ?? 'guide');
                    $typeIcon  = $typeIcons[$r['resource_type'] ?? 'guide'] ?? '📄';
                ?>
                <div class="resource-card featured">
                    <span class="badge badge-featured"><?= pt('⭐ Featured') ?></span>
                    <div class="res-type-icon"><?= $typeIcon ?></div>
                    <h3 class="res-title"><?= e($r['title']) ?></h3>
                    <p class="res-desc"><?= e($r['description'] ?? '') ?></p>
                    <div class="res-meta">
                        <span class="badge <?= $typeBadge ?>"><?= e(ucfirst($r['resource_type'] ?? 'guide')) ?></span>
                        <?php if ($r['file_size']): ?><span><?= e($r['file_size']) ?></span><?php endif ?>
                        <span>⬇ <?= (int)$r['download_count'] ?></span>
                        <?php if ($r['my_downloads']): ?><span style="color:#059669"><?= pt('✓ Downloaded') ?></span><?php endif ?>
                    </div>
                    <div class="res-actions">
                        <form method="post" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="download">
                            <input type="hidden" name="resource_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-primary btn-sm"><?= pt('⬇ Download') ?></button>
                        </form>
                    </div>
                </div>
                <?php endforeach ?>
            </div>
            <p class="section-title" style="margin-top:1rem"><?= pt('All Resources') ?></p>
            <?php endif ?>

            <div class="resource-grid">
                <?php foreach ($resources as $r):
                    if (!$filterCat && !$filterType && !$search && $r['is_featured']) continue;
                    $typeBadge = 'badge-' . ($r['resource_type'] ?? 'guide');
                    $typeIcon  = $typeIcons[$r['resource_type'] ?? 'guide'] ?? '📄';
                ?>
                <div class="resource-card">
                    <div class="res-type-icon"><?= $typeIcon ?></div>
                    <h3 class="res-title"><?= e($r['title']) ?></h3>
                    <p class="res-desc"><?= e($r['description'] ?? '') ?></p>
                    <div class="res-meta">
                        <span class="badge <?= $typeBadge ?>"><?= e(ucfirst($r['resource_type'] ?? 'guide')) ?></span>
                        <?php if ($r['file_size']): ?><span><?= e($r['file_size']) ?></span><?php endif ?>
                        <span>⬇ <?= (int)$r['download_count'] ?></span>
                        <?php if ($r['my_downloads']): ?><span style="color:#059669"><?= pt('✓ Downloaded') ?></span><?php endif ?>
                    </div>
                    <div class="res-actions">
                        <form method="post" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="download">
                            <input type="hidden" name="resource_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-primary btn-sm"><?= pt('⬇ Download') ?></button>
                        </form>
                    </div>
                </div>
                <?php endforeach ?>
            </div>
            <?php endif ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
