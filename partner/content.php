<?php
/**
 * partner/content.php — 237Biz Growth Partner
 * Social / marketing content management with calendar view.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partner = requireGrowthPartner();
$pid     = (int)$partner['id'];
$pdo     = db();
$userId  = (int)($_SESSION['user_id'] ?? 0);

/* ── POST handlers ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_content') {
        $lid  = (int)($_POST['listing_id'] ?? 0);
        if (!partnerCanAccessListing($pid, $lid)) die('Forbidden');
        $type  = $_POST['content_type'] ?? 'social_post';
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        $media = trim($_POST['media_url'] ?? '');
        $plat  = trim($_POST['platform'] ?? '');
        $sched = $_POST['scheduled_date'] ?: null;
        $cid   = $_POST['campaign_id'] ? (int)$_POST['campaign_id'] : null;
        $status = $sched ? 'scheduled' : 'draft';
        $pdo->prepare("INSERT INTO content_items
                        (partner_id,listing_id,campaign_id,content_type,title,body,media_url,platform,scheduled_date,status)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$pid,$lid,$cid,$type,$title,$body,$media,$plat,$sched,$status]);
        $newId = (int)$pdo->lastInsertId();
        logBusinessActivity($lid,$pid,$userId,'content_created',"Content item '$title' ($type) created",'content',$newId);
        partnerAuditLog($pid,$userId,'content_created',"Content $newId for listing $lid",[]);
        setFlash('success','Content item saved.');
        redirect(SITE_URL.'/partner/content?lid='.$lid);
    }

    if ($action === 'update_content_status') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $allowed = ['draft','scheduled','published','archived'];
        if (!in_array($status,$allowed)) die('Invalid');
        $r = $pdo->prepare("SELECT * FROM content_items WHERE id=? AND partner_id=?");
        $r->execute([$itemId,$pid]);
        $item = $r->fetch();
        if (!$item) die('Forbidden');
        $pub = $status === 'published' ? date('Y-m-d H:i:s') : null;
        if ($pub) {
            $pdo->prepare("UPDATE content_items SET status=?, published_date=? WHERE id=?")->execute([$status,$pub,$itemId]);
        } else {
            $pdo->prepare("UPDATE content_items SET status=? WHERE id=?")->execute([$status,$itemId]);
        }
        logBusinessActivity($item['listing_id'],$pid,$userId,'content_updated',"Content '{$item['title']}' → $status",'content',$itemId);
        setFlash('success','Content status updated.');
        redirect(SITE_URL.'/partner/content?lid='.$item['listing_id']);
    }
}

/* ── Filters & view ────────────────────────────────────────────── */
$filterLid    = (int)($_GET['lid'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$filterPlat   = $_GET['platform'] ?? '';
$view         = $_GET['view'] ?? 'list';  // list | calendar
$calYear      = (int)($_GET['year'] ?? date('Y'));
$calMonth     = (int)($_GET['month'] ?? date('n'));
if ($calMonth < 1)  { $calMonth = 12; $calYear--; }
if ($calMonth > 12) { $calMonth = 1;  $calYear++; }

/* ── Listings ──────────────────────────────────────────────────── */
$listings = getPartnerListings($pid);
$listingMap = [];
foreach ($listings as $l) $listingMap[$l['id']] = $l;

/* ── Campaigns dropdown (filtered by listing) ──────────────────── */
$campaigns = [];
if ($filterLid) {
    $cs = $pdo->prepare("SELECT id,name FROM partner_campaigns WHERE partner_id=? AND listing_id=? ORDER BY name");
    $cs->execute([$pid,$filterLid]);
    $campaigns = $cs->fetchAll();
}

/* ── Content query ─────────────────────────────────────────────── */
$where  = ["ci.partner_id = $pid"];
$params = [];
if ($filterLid)    { $where[] = "ci.listing_id = ?";  $params[] = $filterLid; }
if ($filterStatus) { $where[] = "ci.status = ?";       $params[] = $filterStatus; }
if ($filterPlat)   { $where[] = "ci.platform = ?";     $params[] = $filterPlat; }

// Calendar view: restrict to month
if ($view === 'calendar') {
    $monthStart = sprintf('%04d-%02d-01', $calYear, $calMonth);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
    $where[] = "ci.scheduled_date BETWEEN ? AND ?";
    $params[] = $monthStart . ' 00:00:00';
    $params[] = $monthEnd . ' 23:59:59';
}

$sql = "SELECT ci.*, l.title AS biz_name, c.name AS camp_name
        FROM content_items ci
        JOIN listings l ON l.id = ci.listing_id
        LEFT JOIN campaigns c ON c.id = ci.campaign_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(ci.scheduled_date, ci.created_at) " . ($view==='calendar'?'ASC':'DESC');
$st = $pdo->prepare($sql);
$st->execute($params);
$items = $st->fetchAll();

/* ── Calendar grid build ───────────────────────────────────────── */
$calDays = [];
if ($view === 'calendar') {
    foreach ($items as $item) {
        if ($item['scheduled_date']) {
            $day = (int)date('j', strtotime($item['scheduled_date']));
            $calDays[$day][] = $item;
        }
    }
}

/* ── Status summary ────────────────────────────────────────────── */
$sumSt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM content_items WHERE partner_id=? GROUP BY status");
$sumSt->execute([$pid]);
$statusCounts = [];
foreach ($sumSt->fetchAll() as $r) $statusCounts[$r['status']] = (int)$r['cnt'];
$totalItems = array_sum($statusCounts);

/* ── Platform options ──────────────────────────────────────────── */
$platforms = ['facebook','instagram','tiktok','linkedin','twitter','whatsapp','youtube','other'];
$platformLabels = ['facebook'=>'Facebook','instagram'=>'Instagram','tiktok'=>'TikTok',
                   'linkedin'=>'LinkedIn','twitter'=>'Twitter/X','whatsapp'=>'WhatsApp',
                   'youtube'=>'YouTube','other'=>'Other'];

$pageTitle = 'Content Management';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/partner.css">
<style>
.cont-header{background:linear-gradient(135deg,#0891b2 0%,#0e7490 100%);color:#fff;padding:28px 32px;border-radius:12px;margin-bottom:24px}
.cont-header h1{margin:0 0 6px;font-size:1.6rem}
.cont-header p{margin:0;opacity:.85;font-size:.95rem}
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center}
.filter-bar select,.filter-bar input{padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem}
.view-toggle{display:flex;gap:0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-left:auto}
.view-toggle a{padding:7px 16px;font-size:.85rem;text-decoration:none;color:#374151;background:#fff}
.view-toggle a.active{background:#0891b2;color:#fff}
.summary-strip{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.sum-pill{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:6px 16px;font-size:.85rem;display:flex;align-items:center;gap:6px}
.sum-pill .dot{width:10px;height:10px;border-radius:50%;display:inline-block}
/* List view */
.content-list{display:flex;flex-direction:column;gap:12px}
.content-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;display:flex;gap:16px;align-items:flex-start}
.content-card-body{flex:1;min-width:0}
.content-title{font-weight:600;font-size:.95rem;color:#111827;margin:0 0 4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.content-meta{font-size:.8rem;color:#6b7280;display:flex;flex-wrap:wrap;gap:10px;margin-bottom:6px}
.content-body-preview{font-size:.85rem;color:#374151;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.content-actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.content-actions form{margin:0}
.plat-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:600;text-transform:capitalize}
.plat-facebook{background:#e7f3ff;color:#1877f2}
.plat-instagram{background:#fdf3f7;color:#e1306c}
.plat-tiktok{background:#f0fff4;color:#010101}
.plat-linkedin{background:#e8f4fb;color:#0077b5}
.plat-twitter{background:#e8f5fd;color:#1da1f2}
.plat-whatsapp{background:#e8f7ec;color:#25d366}
.plat-youtube{background:#fff0f0;color:#ff0000}
.plat-other{background:#f3f4f6;color:#374151}
.status-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:600}
.st-draft{background:#f3f4f6;color:#374151}
.st-scheduled{background:#dbeafe;color:#1e40af}
.st-published{background:#dcfce7;color:#166534}
.st-archived{background:#f5f3ff;color:#5b21b6}
.btn-xs{padding:5px 12px;font-size:.8rem;border-radius:5px;border:1px solid #d1d5db;background:#fff;cursor:pointer;color:#374151;text-decoration:none;display:inline-block}
.btn-xs:hover{background:#f3f4f6}
.btn-xs.primary{background:#0891b2;color:#fff;border-color:#0891b2}
.btn-xs.green{background:#16a34a;color:#fff;border-color:#16a34a}
/* Create form */
.create-form{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;margin-bottom:24px}
.create-form h3{margin:0 0 18px;font-size:1.1rem;color:#111827}
.f-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.f-full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group label{font-size:.85rem;font-weight:500;color:#374151}
.form-group input,.form-group select,.form-group textarea{padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;width:100%;box-sizing:border-box}
.form-group textarea{resize:vertical;min-height:80px}
/* Calendar */
.cal-nav{display:flex;align-items:center;gap:16px;margin-bottom:16px}
.cal-nav h2{margin:0;font-size:1.2rem;font-weight:600;color:#111827}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;background:#e5e7eb;border-radius:8px;overflow:hidden}
.cal-day-header{background:#f9fafb;padding:8px;text-align:center;font-size:.8rem;font-weight:600;color:#6b7280}
.cal-day{background:#fff;min-height:100px;padding:6px;vertical-align:top}
.cal-day.today{background:#fef9c3}
.cal-day.other-month{background:#f9fafb;opacity:.5}
.cal-day-num{font-size:.82rem;font-weight:600;color:#374151;margin-bottom:4px}
.cal-item{background:#dbeafe;border-left:3px solid #2563eb;border-radius:3px;padding:3px 6px;font-size:.72rem;margin-bottom:3px;cursor:pointer;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#1e3a8a}
.cal-item.published{background:#dcfce7;border-color:#16a34a;color:#166534}
.cal-item.draft{background:#f3f4f6;border-color:#9ca3af;color:#4b5563}
.empty-state{text-align:center;padding:48px 20px;color:#9ca3af}
.flash-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:12px 18px;border-radius:8px;margin-bottom:18px;font-size:.92rem}
.flash-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:12px 18px;border-radius:8px;margin-bottom:18px;font-size:.92rem}
</style>

<div class="container" style="max-width:1100px;margin:30px auto;padding:0 16px">

<?php if ($flash = getFlash('success')): ?>
<div class="flash-success">✓ <?= e($flash) ?></div>
<?php endif; ?>

<div class="cont-header">
    <h1>📅 Content Management</h1>
    <p><?= $totalItems ?> content item<?= $totalItems !== 1 ? 's' : '' ?> across your portfolio</p>
</div>

<?php if ($totalItems > 0): ?>
<div class="summary-strip">
    <?php
    $dots=['draft'=>'#9ca3af','scheduled'=>'#2563eb','published'=>'#16a34a','archived'=>'#7c3aed'];
    foreach ($statusCounts as $st => $cnt): ?>
    <span class="sum-pill"><span class="dot" style="background:<?= $dots[$st]??'#9ca3af' ?>"></span><?= $cnt ?> <?= ucfirst($st) ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filter bar -->
<div class="filter-bar">
    <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;flex:1">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <?php if ($view==='calendar'): ?>
        <input type="hidden" name="year"  value="<?= $calYear ?>">
        <input type="hidden" name="month" value="<?= $calMonth ?>">
        <?php endif; ?>
        <select name="lid" onchange="this.form.submit()">
            <option value="">All Businesses</option>
            <?php foreach ($listings as $l): ?>
            <option value="<?= $l['id'] ?>" <?= $filterLid==$l['id']?'selected':'' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <?php foreach (['draft','scheduled','published','archived'] as $s): ?>
            <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="platform" onchange="this.form.submit()">
            <option value="">All Platforms</option>
            <?php foreach ($platforms as $p): ?>
            <option value="<?= $p ?>" <?= $filterPlat===$p?'selected':'' ?>><?= $platformLabels[$p] ?></option>
            <?php endforeach; ?>
        </select>
        <a href="<?= SITE_URL ?>/partner/content?view=<?= $view ?>" class="btn-xs">Clear</a>
    </form>
    <div class="view-toggle">
        <a href="?lid=<?= $filterLid ?>&status=<?= $filterStatus ?>&platform=<?= $filterPlat ?>&view=list" class="<?= $view==='list'?'active':'' ?>">☰ List</a>
        <a href="?lid=<?= $filterLid ?>&status=<?= $filterStatus ?>&platform=<?= $filterPlat ?>&view=calendar&year=<?= $calYear ?>&month=<?= $calMonth ?>" class="<?= $view==='calendar'?'active':'' ?>">📅 Calendar</a>
    </div>
</div>

<!-- Create content form -->
<div class="create-form">
    <h3>➕ Add Content</h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_content">
        <div class="f-grid">
            <div class="form-group">
                <label>Business <span style="color:red">*</span></label>
                <select name="listing_id" required onchange="this.form.submit()">
                    <option value="">Select business…</option>
                    <?php foreach ($listings as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $filterLid==$l['id']?'selected':'' ?>><?= e($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Content Type <span style="color:red">*</span></label>
                <select name="content_type">
                    <?php foreach (['social_post'=>'Social Post','promotional_post'=>'Promotional Post','product_post'=>'Product Post','service_post'=>'Service Post','event_post'=>'Event Post','review_post'=>'Review Post','video'=>'Video','image'=>'Image','announcement'=>'Announcement'] as $v=>$lbl): ?>
                    <option value="<?= $v ?>"><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group f-full">
                <label>Title</label>
                <input type="text" name="title" placeholder="Post caption or title" maxlength="200">
            </div>
            <div class="form-group">
                <label>Platform</label>
                <select name="platform">
                    <option value="">Select platform…</option>
                    <?php foreach ($platforms as $p): ?>
                    <option value="<?= $p ?>"><?= $platformLabels[$p] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Link to Campaign</label>
                <select name="campaign_id">
                    <option value="">None</option>
                    <?php foreach ($campaigns as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Scheduled Date &amp; Time</label>
                <input type="datetime-local" name="scheduled_date">
            </div>
            <div class="form-group">
                <label>Media URL</label>
                <input type="url" name="media_url" placeholder="https://…">
            </div>
            <div class="form-group f-full">
                <label>Body / Caption</label>
                <textarea name="body" placeholder="Post text, caption, or description…"></textarea>
            </div>
        </div>
        <div style="margin-top:14px">
            <button type="submit" class="btn-xs primary" style="padding:8px 20px;font-size:.9rem">Save Content</button>
        </div>
    </form>
</div>

<?php if ($view === 'calendar'): ?>
<!-- ══════════════════════════════════════ -->
<!-- CALENDAR VIEW                          -->
<!-- ══════════════════════════════════════ -->
<?php
    $prevMonth = $calMonth - 1; $prevYear = $calYear;
    if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
    $nextMonth = $calMonth + 1; $nextYear = $calYear;
    if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
    $firstDay  = mktime(0,0,0,$calMonth,1,$calYear);
    $daysInMonth = (int)date('t', $firstDay);
    $startDow  = (int)date('N', $firstDay); // 1=Mon
    $today     = (int)date('j');
    $todayM    = (int)date('n');
    $todayY    = (int)date('Y');
    $monthName = date('F Y', $firstDay);
?>
<div class="cal-nav">
    <a href="?lid=<?= $filterLid ?>&view=calendar&year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="btn-xs">← Prev</a>
    <h2><?= $monthName ?></h2>
    <a href="?lid=<?= $filterLid ?>&view=calendar&year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="btn-xs">Next →</a>
    <a href="?lid=<?= $filterLid ?>&view=calendar&year=<?= date('Y') ?>&month=<?= date('n') ?>" class="btn-xs" style="margin-left:8px">Today</a>
</div>
<div class="cal-grid">
    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dh): ?>
    <div class="cal-day-header"><?= $dh ?></div>
    <?php endforeach; ?>
    <?php
    // empty cells before first day
    for ($i = 1; $i < $startDow; $i++) echo '<div class="cal-day other-month"></div>';
    // day cells
    for ($d = 1; $d <= $daysInMonth; $d++):
        $isToday = ($d === $today && $calMonth === $todayM && $calYear === $todayY);
    ?>
    <div class="cal-day <?= $isToday?'today':'' ?>">
        <div class="cal-day-num"><?= $d ?></div>
        <?php foreach ($calDays[$d] ?? [] as $item): ?>
        <div class="cal-item <?= $item['status'] ?>" title="<?= e($item['title']?:$item['body']) ?>"
             onclick="location.href='<?= SITE_URL ?>/partner/business?lid=<?= $item['listing_id'] ?>&tab=content'">
            <?= $item['platform'] ? '['.$item['platform'].'] ' : '' ?><?= e(mb_strimwidth($item['title']?:$item['body'],0,30,'…')) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endfor;
    // padding after last day
    $endDow = (int)date('N', mktime(0,0,0,$calMonth,$daysInMonth,$calYear));
    for ($i = $endDow; $i < 7; $i++) echo '<div class="cal-day other-month"></div>';
    ?>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════ -->
<!-- LIST VIEW                              -->
<!-- ══════════════════════════════════════ -->
<?php if (empty($items)): ?>
<div class="empty-state">
    <div style="font-size:3rem;margin-bottom:12px">📝</div>
    <p>No content found. Create your first content item above.</p>
</div>
<?php else: ?>
<div class="content-list">
<?php foreach ($items as $item): ?>
<div class="content-card">
    <div style="width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;background:#f0f9ff">
        <?php
        $icons=['social_post'=>'📱','promotional_post'=>'📣','product_post'=>'🛍️','service_post'=>'🔧',
                'event_post'=>'🎉','review_post'=>'⭐','video'=>'🎬','image'=>'🖼️','announcement'=>'📢'];
        echo $icons[$item['content_type']] ?? '📝';
        ?>
    </div>
    <div class="content-card-body">
        <div class="content-title"><?= e($item['title'] ?: '(no title)') ?></div>
        <div class="content-meta">
            <span>🏢 <?= e($item['biz_name']) ?></span>
            <?php if ($item['platform']): ?>
            <span class="plat-badge plat-<?= e($item['platform']) ?>"><?= ucfirst($item['platform']) ?></span>
            <?php endif; ?>
            <?php if ($item['camp_name']): ?>
            <span>📣 <?= e($item['camp_name']) ?></span>
            <?php endif; ?>
            <?php if ($item['scheduled_date']): ?>
            <span>🕐 <?= date('d M Y H:i', strtotime($item['scheduled_date'])) ?></span>
            <?php endif; ?>
            <?php if ($item['published_date']): ?>
            <span>✓ Published <?= date('d M Y', strtotime($item['published_date'])) ?></span>
            <?php endif; ?>
            <span class="status-badge st-<?= $item['status'] ?>"><?= ucfirst($item['status']) ?></span>
        </div>
        <?php if ($item['body']): ?>
        <div class="content-body-preview"><?= e($item['body']) ?></div>
        <?php endif; ?>
        <?php if ($item['media_url']): ?>
        <div style="margin-top:6px;font-size:.8rem;color:#6b7280">🔗 <a href="<?= e($item['media_url']) ?>" target="_blank" style="color:#0891b2">Media link</a></div>
        <?php endif; ?>
        <div class="content-actions">
            <a href="<?= SITE_URL ?>/partner/business?lid=<?= $item['listing_id'] ?>&tab=content" class="btn-xs">Edit</a>
            <?php if ($item['status'] !== 'published'): ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_content_status">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <input type="hidden" name="status" value="published">
                <button type="submit" class="btn-xs green">✓ Mark Published</button>
            </form>
            <?php endif; ?>
            <?php if ($item['status'] !== 'archived'): ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_content_status">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <input type="hidden" name="status" value="archived">
                <button type="submit" class="btn-xs" style="color:#6b7280">Archive</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; // end list/calendar toggle ?>

</div><!-- /container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
