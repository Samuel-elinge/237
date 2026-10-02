<?php
/**
 * partner/opportunities.php — Available Opportunities (Task 52)
 *
 * Partners view open business opportunities they can express interest in.
 * Opportunities = businesses/listings that have requested a partner,
 * plus admin-posted opportunities. Partners can register interest.
 * Admins can post new opportunity notices.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)$_SESSION['user_id'];
$isAdmin        = isAdmin();

// ── POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Express interest in an opportunity
    if ($action === 'interest') {
        $oppId   = (int)($_POST['opportunity_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if (!$oppId) { setFlash('error', 'Invalid opportunity.'); redirect(SITE_URL . '/partner/opportunities'); }

        // Check not already expressed interest
        $exists = $pdo->prepare("SELECT id FROM partner_opportunity_interests WHERE opportunity_id=? AND partner_id=?");
        $exists->execute([$oppId, $partnerId]);
        if ($exists->fetchColumn()) {
            setFlash('error', 'You have already expressed interest in this opportunity.');
            redirect(SITE_URL . '/partner/opportunities');
        }

        $pdo->prepare("
            INSERT INTO partner_opportunity_interests (opportunity_id, partner_id, message, created_at)
            VALUES (?,?,?,NOW())
        ")->execute([$oppId, $partnerId, $message]);

        // Notify admins
        $adminIds = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($adminIds as $aid) {
            pushNotification((int)$aid, 'opportunity_interest', 'Partner Interest Registered',
                "{$partnerProfile['display_name']} has expressed interest in an opportunity.",
                SITE_URL . '/admin/partner-matching');
        }

        partnerAuditLog($partnerId, $userId, null, 'opportunity_interest', "Opportunity ID: $oppId");
        setFlash('success', 'Your interest has been registered. An admin will be in touch.');
        redirect(SITE_URL . '/partner/opportunities');
    }

    // Admin: post new opportunity
    if ($action === 'post_opportunity' && $isAdmin) {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $region      = trim($_POST['region'] ?? '');
        $category    = trim($_POST['category'] ?? '');
        $listingId   = (int)($_POST['listing_id'] ?? 0) ?: null;
        $expiresAt   = trim($_POST['expires_at'] ?? '') ?: null;
        $urgency     = in_array($_POST['urgency'] ?? '', ['low','medium','high']) ? $_POST['urgency'] : 'medium';

        if (!$title || !$description) { setFlash('error', 'Title and description are required.'); redirect(SITE_URL . '/partner/opportunities'); }

        $pdo->prepare("
            INSERT INTO partner_opportunities (title, description, region, category, listing_id, expires_at, urgency, posted_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())
        ")->execute([$title, $description, $region, $category, $listingId, $expiresAt, $urgency, $userId]);

        setFlash('success', 'Opportunity posted.');
        redirect(SITE_URL . '/partner/opportunities');
    }

    redirect(SITE_URL . '/partner/opportunities');
}

// ── Filters ───────────────────────────────────────────────
$filterRegion   = trim($_GET['region'] ?? '');
$filterUrgency  = trim($_GET['urgency'] ?? '');
$filterCat      = trim($_GET['cat'] ?? '');
$search         = trim($_GET['q'] ?? '');

$where  = ['po.is_active = 1', '(po.expires_at IS NULL OR po.expires_at >= CURDATE())'];
$params = [];

if ($filterRegion)  { $where[] = 'po.region = ?'; $params[] = $filterRegion; }
if ($filterUrgency) { $where[] = 'po.urgency = ?'; $params[] = $filterUrgency; }
if ($filterCat)     { $where[] = 'po.category = ?'; $params[] = $filterCat; }
if ($search)        { $where[] = '(po.title LIKE ? OR po.description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Opportunities with partner's interest status
$opps = $pdo->prepare("
    SELECT po.*,
           l.business_name, l.slug AS listing_slug,
           u.name AS posted_by_name,
           (SELECT COUNT(*) FROM partner_opportunity_interests poi WHERE poi.opportunity_id=po.id) AS interest_count,
           MAX(CASE WHEN poi2.partner_id=? THEN 1 ELSE 0 END) AS i_am_interested
    FROM partner_opportunities po
    LEFT JOIN listings l ON l.id = po.listing_id
    LEFT JOIN users u ON u.id = po.posted_by
    LEFT JOIN partner_opportunity_interests poi2 ON poi2.opportunity_id = po.id AND poi2.partner_id = ?
    $whereSQL
    GROUP BY po.id
    ORDER BY
        FIELD(po.urgency,'high','medium','low'),
        po.created_at DESC
");
$opps->execute(array_merge([$partnerId, $partnerId], $params));
$opps = $opps->fetchAll();

// Open partner requests visible as opportunities (businesses that asked for a partner)
$openRequests = $pdo->prepare("
    SELECT pr.*,
           l.business_name, l.slug AS listing_slug, l.category AS biz_category,
           u.name AS requester_name,
           (SELECT COUNT(*) FROM partner_opportunity_interests poi WHERE poi.opportunity_id IS NULL) AS dummy
    FROM partner_requests pr
    JOIN listings l ON l.id = pr.listing_id
    JOIN users u ON u.id = pr.requester_id
    WHERE pr.status='pending' AND pr.request_type='open'
    ORDER BY FIELD(pr.urgency,'high','medium','low'), pr.created_at DESC
    LIMIT 20
");
$openRequests->execute();
$openRequests = $openRequests->fetchAll();

// Filter values
$regions    = $pdo->query("SELECT DISTINCT region FROM partner_opportunities WHERE is_active=1 AND region IS NOT NULL ORDER BY region")->fetchAll(\PDO::FETCH_COLUMN);
$categories = $pdo->query("SELECT DISTINCT category FROM partner_opportunities WHERE is_active=1 AND category IS NOT NULL ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN);

$urgencyColors = ['high'=>['#fee2e2','#991b1b'],'medium'=>['#fef3c7','#92400e'],'low'=>['#d1fae5','#065f46']];

$flash     = getFlash();
$pageTitle = 'Opportunities';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:900px;margin:0 auto;padding:1.5rem}
.layout{display:grid;grid-template-columns:200px 1fr;gap:1.25rem}
@media(max-width:640px){.layout{grid-template-columns:1fr}}
.filter-box{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden;margin-bottom:.75rem}
.filter-box .filter-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;padding:.6rem 1rem .25rem}
.filter-box a{display:block;padding:.45rem 1rem;font-size:.82rem;color:#374151;text-decoration:none;border-top:1px solid #f9fafb}
.filter-box a.active,.filter-box a:hover{background:#eff6ff;color:#1d4ed8}
.opp-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;margin-bottom:.75rem;overflow:hidden}
.opp-header{padding:.9rem 1.1rem;border-bottom:1px solid #f9fafb;display:flex;align-items:flex-start;gap:.75rem}
.opp-urgency{width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:.35rem}
.opp-title{font-weight:700;font-size:.95rem;color:#111827;margin:0 0 .2rem}
.opp-sub{font-size:.78rem;color:#6b7280}
.opp-badges{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.35rem}
.badge{font-size:.68rem;font-weight:600;padding:.15rem .45rem;border-radius:99px}
.opp-body{padding:.7rem 1.1rem;font-size:.85rem;color:#374151;line-height:1.5}
.opp-footer{background:#f9fafb;border-top:1px solid #f3f4f6;padding:.6rem 1.1rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.interest-form{display:inline}
.interest-detail{font-size:.78rem;color:#6b7280;margin-left:auto}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:.75rem;padding:1.5rem;max-width:480px;width:90%;max-height:90vh;overflow-y:auto}
.modal h3{margin:0 0 1rem;font-size:1rem;color:#111827}
.form-group{margin-bottom:.75rem}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.25rem}
.form-group textarea,.form-group input{width:100%;border:1px solid #d1d5db;border-radius:.45rem;padding:.45rem .7rem;font-size:.875rem;box-sizing:border-box}
.form-group textarea{min-height:80px;resize:vertical}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-success{background:#059669;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.btn-sm{padding:.25rem .55rem;font-size:.72rem}
.btn:hover{opacity:.9}
.search-bar{display:flex;gap:.5rem;margin-bottom:1rem}
.search-bar input{flex:1;border:1px solid #d1d5db;border-radius:.5rem;padding:.45rem .75rem;font-size:.875rem}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.empty{text-align:center;padding:3rem;color:#9ca3af}
.section-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin:0 0 .6rem}
.admin-post-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.25rem;margin-bottom:1.25rem}
.urgency-pills{display:flex;gap:.5rem}
.urgency-pill{padding:.4rem .85rem;border-radius:99px;border:2px solid #e5e7eb;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s}
.urgency-pill.selected[data-u="high"]{border-color:#ef4444;background:#fee2e2;color:#991b1b}
.urgency-pill.selected[data-u="medium"]{border-color:#f59e0b;background:#fef3c7;color:#92400e}
.urgency-pill.selected[data-u="low"]{border-color:#10b981;background:#d1fae5;color:#065f46}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827">Opportunities</h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280">Businesses looking for a Growth Partner — register your interest</p>
        </div>
        <div style="display:flex;gap:.5rem">
            <?php if ($isAdmin): ?>
            <button onclick="document.getElementById('postModal').classList.add('open')" class="btn btn-primary">+ Post Opportunity</button>
            <?php endif ?>
            <a href="<?= SITE_URL ?>/partner/dashboard" class="btn btn-detail">← Dashboard</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <div class="layout">
        <div class="sidebar">
            <div class="filter-box">
                <div class="filter-title">Urgency</div>
                <a href="?<?= http_build_query(array_filter(['region'=>$filterRegion,'cat'=>$filterCat,'q'=>$search])) ?>" class="<?= !$filterUrgency ? 'active' : '' ?>">All</a>
                <?php foreach (['high'=>'🔴 High','medium'=>'🟡 Medium','low'=>'🟢 Low'] as $u => $lbl): ?>
                <a href="?<?= http_build_query(array_filter(['urgency'=>$u,'region'=>$filterRegion,'cat'=>$filterCat,'q'=>$search])) ?>" class="<?= $filterUrgency===$u ? 'active' : '' ?>"><?= $lbl ?></a>
                <?php endforeach ?>
            </div>

            <?php if ($regions): ?>
            <div class="filter-box">
                <div class="filter-title">Region</div>
                <a href="?<?= http_build_query(array_filter(['urgency'=>$filterUrgency,'cat'=>$filterCat,'q'=>$search])) ?>" class="<?= !$filterRegion ? 'active' : '' ?>">All regions</a>
                <?php foreach ($regions as $r): ?>
                <a href="?<?= http_build_query(array_filter(['region'=>$r,'urgency'=>$filterUrgency,'cat'=>$filterCat,'q'=>$search])) ?>" class="<?= $filterRegion===$r ? 'active' : '' ?>"><?= e($r) ?></a>
                <?php endforeach ?>
            </div>
            <?php endif ?>

            <?php if ($categories): ?>
            <div class="filter-box">
                <div class="filter-title">Category</div>
                <a href="?<?= http_build_query(array_filter(['urgency'=>$filterUrgency,'region'=>$filterRegion,'q'=>$search])) ?>" class="<?= !$filterCat ? 'active' : '' ?>">All</a>
                <?php foreach ($categories as $cat): ?>
                <a href="?<?= http_build_query(array_filter(['cat'=>$cat,'urgency'=>$filterUrgency,'region'=>$filterRegion,'q'=>$search])) ?>" class="<?= $filterCat===$cat ? 'active' : '' ?>"><?= e(ucfirst($cat)) ?></a>
                <?php endforeach ?>
            </div>
            <?php endif ?>
        </div>

        <div class="main">
            <form method="get" class="search-bar">
                <?php foreach (array_filter(['urgency'=>$filterUrgency,'region'=>$filterRegion,'cat'=>$filterCat]) as $k=>$v): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
                <?php endforeach ?>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search opportunities…">
                <button type="submit" class="btn btn-detail">Search</button>
                <?php if ($search): ?><a href="?<?= http_build_query(array_filter(['urgency'=>$filterUrgency,'region'=>$filterRegion,'cat'=>$filterCat])) ?>" class="btn btn-detail">Clear</a><?php endif ?>
            </form>

            <!-- Posted opportunities -->
            <?php if ($opps): ?>
            <p class="section-title">📢 Posted Opportunities (<?= count($opps) ?>)</p>
            <?php foreach ($opps as $o):
                [$urgBg, $urgCol] = $urgencyColors[$o['urgency'] ?? 'medium'] ?? $urgencyColors['medium'];
            ?>
            <div class="opp-card">
                <div class="opp-header">
                    <div class="opp-urgency" style="background:<?= $urgCol ?>"></div>
                    <div style="flex:1">
                        <div class="opp-title"><?= e($o['title']) ?></div>
                        <div class="opp-sub">
                            <?php if ($o['business_name']): ?>
                            <a href="<?= SITE_URL ?>/listing/<?= e($o['listing_slug']) ?>" target="_blank" style="color:#2563eb"><?= e($o['business_name']) ?></a> ·
                            <?php endif ?>
                            Posted <?= date('d M Y', strtotime($o['created_at'])) ?>
                            <?php if ($o['expires_at']): ?> · Expires <?= date('d M Y', strtotime($o['expires_at'])) ?><?php endif ?>
                        </div>
                        <div class="opp-badges">
                            <span class="badge" style="background:<?= $urgBg ?>;color:<?= $urgCol ?>"><?= ucfirst($o['urgency']) ?> urgency</span>
                            <?php if ($o['region']): ?><span class="badge" style="background:#f3f4f6;color:#374151">📍 <?= e($o['region']) ?></span><?php endif ?>
                            <?php if ($o['category']): ?><span class="badge" style="background:#f3f4f6;color:#374151"><?= e($o['category']) ?></span><?php endif ?>
                            <?php if ($o['i_am_interested']): ?><span class="badge" style="background:#d1fae5;color:#065f46">✓ Interested</span><?php endif ?>
                        </div>
                    </div>
                    <div style="font-size:.78rem;color:#9ca3af;text-align:right;white-space:nowrap"><?= (int)$o['interest_count'] ?> interested</div>
                </div>
                <div class="opp-body"><?= e($o['description']) ?></div>
                <div class="opp-footer">
                    <?php if ($o['i_am_interested']): ?>
                    <span style="color:#059669;font-size:.8rem;font-weight:600">✓ You've registered interest</span>
                    <?php else: ?>
                    <button onclick="openInterest(<?= $o['id'] ?>, '<?= addslashes(e($o['title'])) ?>')" class="btn btn-success btn-sm">Register Interest</button>
                    <?php endif ?>
                    <span class="interest-detail">Posted by <?= e($o['posted_by_name'] ?? 'Admin') ?></span>
                </div>
            </div>
            <?php endforeach ?>
            <?php endif ?>

            <!-- Open partner requests -->
            <?php if ($openRequests): ?>
            <p class="section-title" style="margin-top:1rem">🏢 Businesses Seeking a Partner (<?= count($openRequests) ?>)</p>
            <?php foreach ($openRequests as $r):
                [$urgBg, $urgCol] = $urgencyColors[$r['urgency'] ?? 'medium'] ?? $urgencyColors['medium'];
            ?>
            <div class="opp-card">
                <div class="opp-header">
                    <div class="opp-urgency" style="background:<?= $urgCol ?>"></div>
                    <div style="flex:1">
                        <div class="opp-title">
                            <a href="<?= SITE_URL ?>/listing/<?= e($r['listing_slug']) ?>" target="_blank" style="color:#111827;text-decoration:none"><?= e($r['business_name']) ?></a>
                        </div>
                        <div class="opp-sub">Requested <?= date('d M Y', strtotime($r['created_at'])) ?></div>
                        <div class="opp-badges">
                            <span class="badge" style="background:<?= $urgBg ?>;color:<?= $urgCol ?>"><?= ucfirst($r['urgency']) ?> urgency</span>
                            <?php if ($r['biz_category']): ?><span class="badge" style="background:#f3f4f6;color:#374151"><?= e($r['biz_category']) ?></span><?php endif ?>
                            <?php if ($r['region_preference']): ?><span class="badge" style="background:#f3f4f6;color:#374151">📍 <?= e($r['region_preference']) ?></span><?php endif ?>
                        </div>
                    </div>
                </div>
                <?php if ($r['message']): ?>
                <div class="opp-body"><?= e($r['message']) ?></div>
                <?php endif ?>
                <div class="opp-footer">
                    <a href="<?= SITE_URL ?>/listing/<?= e($r['listing_slug']) ?>" target="_blank" class="btn btn-detail btn-sm">View Business</a>
                    <span style="font-size:.78rem;color:#6b7280;margin-left:.5rem">Contact admin to express interest in this assignment.</span>
                </div>
            </div>
            <?php endforeach ?>
            <?php endif ?>

            <?php if (!$opps && !$openRequests): ?>
            <div class="empty">
                <div style="font-size:2.5rem;margin-bottom:.5rem">🔍</div>
                <p>No opportunities available right now. Check back soon or adjust your filters.</p>
            </div>
            <?php endif ?>
        </div>
    </div>
</div>

<!-- Interest modal -->
<div class="modal-overlay" id="interestModal">
    <div class="modal">
        <h3>Register Interest — <span id="interestOppName"></span></h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="interest">
            <input type="hidden" name="opportunity_id" id="interestOppId">
            <div class="form-group">
                <label>Why are you a good fit? (optional)</label>
                <textarea name="message" placeholder="Briefly explain your relevant experience and why you're interested in this opportunity…"></textarea>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-success">Register Interest</button>
                <button type="button" onclick="closeModals()" class="btn btn-detail">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Admin: Post opportunity modal -->
<?php if ($isAdmin): ?>
<div class="modal-overlay" id="postModal">
    <div class="modal">
        <h3>Post New Opportunity</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="post_opportunity">
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" required placeholder="e.g. Retail business seeking local partner in Manchester">
            </div>
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" required placeholder="Describe the opportunity, what's needed, what the partner will do…"></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group">
                    <label>Region</label>
                    <input type="text" name="region" placeholder="e.g. London">
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" name="category" placeholder="e.g. Retail">
                </div>
            </div>
            <div class="form-group">
                <label>Urgency</label>
                <div class="urgency-pills" id="postUrgencyPills">
                    <?php foreach (['low','medium','high'] as $u): ?>
                    <div class="urgency-pill <?= $u==='medium'?'selected':'' ?>" data-u="<?= $u ?>" onclick="selectPostUrgency(this)"><?= ucfirst($u) ?></div>
                    <?php endforeach ?>
                </div>
                <input type="hidden" name="urgency" id="postUrgencyInput" value="medium">
            </div>
            <div class="form-group">
                <label>Expires (optional)</label>
                <input type="date" name="expires_at">
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Post Opportunity</button>
                <button type="button" onclick="closeModals()" class="btn btn-detail">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif ?>

<script>
function openInterest(id, name) {
    document.getElementById('interestOppId').value = id;
    document.getElementById('interestOppName').textContent = name;
    document.getElementById('interestModal').classList.add('open');
}
function closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
}
function selectPostUrgency(el) {
    document.querySelectorAll('#postUrgencyPills .urgency-pill').forEach(p => p.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('postUrgencyInput').value = el.dataset.u;
}
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', function(e){ if(e.target===this) closeModals(); }));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
