<?php
/**
 * partner/feedback.php — Partner Feedback & Reviews (Task 49)
 *
 * Partners view feedback left by businesses they've worked with.
 * Admins can submit feedback on behalf of businesses.
 * Public-facing feedback appears on partner profile page.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$pdo = db();
$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];

// Admin can post feedback for a business
$isAdmin = isAdmin();

// ── POST: submit feedback (admin) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    verifyCsrf();
    $targetPid  = (int)($_POST['partner_id'] ?? $partnerId);
    $listingId  = (int)($_POST['listing_id'] ?? 0);
    $rating     = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $feedback   = trim($_POST['feedback'] ?? '');
    $isPublic   = isset($_POST['is_public']) ? 1 : 0;

    if (!$feedback) { setFlash('error', 'Feedback text is required.'); redirect(SITE_URL . '/partner/feedback'); }

    $pdo->prepare("INSERT INTO partner_feedback (partner_id, listing_id, rating, feedback, is_public, submitted_by)
                   VALUES (?,?,?,?,?,?)")
        ->execute([$targetPid, $listingId ?: null, $rating, $feedback, $isPublic, (int)$_SESSION['user_id']]);

    // Update partner rating aggregate
    $pdo->prepare("
        UPDATE partner_profiles SET
            rating = (SELECT AVG(rating) FROM partner_feedback WHERE partner_id=? AND is_public=1),
            rating_count = (SELECT COUNT(*) FROM partner_feedback WHERE partner_id=? AND is_public=1)
        WHERE id=?
    ")->execute([$targetPid, $targetPid, $targetPid]);

    partnerAuditLog($targetPid, (int)$_SESSION['user_id'], $listingId ?: null, 'feedback_added', "Rating: $rating/5");
    setFlash('success', 'Feedback submitted successfully.');
    redirect(SITE_URL . '/partner/feedback');
}

// ── Filters ───────────────────────────────────────────────
$filterVis = in_array($_GET['vis'] ?? '', ['public','private','all']) ? $_GET['vis'] : 'all';

$where  = ["pf.partner_id = ?"];
$params = [$partnerId];
if ($filterVis === 'public')  { $where[] = "pf.is_public = 1"; }
if ($filterVis === 'private') { $where[] = "pf.is_public = 0"; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$feedbackRows = $pdo->prepare("
    SELECT pf.*, l.business_name, u.name AS submitted_by_name
    FROM partner_feedback pf
    LEFT JOIN listings l ON l.id = pf.listing_id
    LEFT JOIN users u ON u.id = pf.submitted_by
    $whereSQL
    ORDER BY pf.created_at DESC
");
$feedbackRows->execute($params);
$feedbackRows = $feedbackRows->fetchAll();

// Rating summary
$summary = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        AVG(rating) AS avg_rating,
        SUM(is_public) AS public_count,
        SUM(rating=5) AS five_star,
        SUM(rating=4) AS four_star,
        SUM(rating=3) AS three_star,
        SUM(rating<=2) AS low_star
    FROM partner_feedback WHERE partner_id=?
");
$summary->execute([$partnerId]);
$summary = $summary->fetch();

// Partner's businesses for admin form
$adminBusinesses = [];
if ($isAdmin) {
    $adminBusinesses = $pdo->prepare("
        SELECT l.id, l.business_name FROM partner_business_assignments pba
        JOIN listings l ON l.id=pba.listing_id
        WHERE pba.partner_id=? AND pba.status='active'
        ORDER BY l.business_name
    ");
    $adminBusinesses->execute([$partnerId]);
    $adminBusinesses = $adminBusinesses->fetchAll();
}

$flash = getFlash();
$curRating = round((float)($partnerProfile['rating'] ?? 0));
$pageTitle = pt('Feedback & Reviews');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:820px;margin:0 auto;padding:1.5rem}
.summary-card{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;border-radius:.75rem;padding:1.5rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:2rem;flex-wrap:wrap}
.big-rating{font-size:3rem;font-weight:800;line-height:1}
.rating-stars{font-size:1.4rem;color:#fcd34d;letter-spacing:.05rem}
.rating-sub{font-size:.85rem;opacity:.8;margin-top:.2rem}
.rating-bars{flex:1;min-width:160px}
.bar-row{display:flex;align-items:center;gap:.5rem;font-size:.75rem;margin-bottom:.3rem}
.bar-label{width:2rem;text-align:right;opacity:.8}
.bar-track{flex:1;height:6px;background:rgba(255,255,255,.25);border-radius:99px;overflow:hidden}
.bar-fill{height:100%;background:#fcd34d;border-radius:99px}
.bar-count{width:1.5rem;opacity:.7}
.tabs{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:1.25rem}
.tab{padding:.55rem 1.1rem;font-size:.875rem;font-weight:500;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none}
.tab.active{color:#2563eb;border-color:#2563eb}
.fb-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1rem 1.25rem;margin-bottom:.75rem}
.fb-top{display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem}
.fb-stars{color:#f59e0b;font-size:.9rem}
.fb-biz{font-weight:600;font-size:.875rem;color:#111827}
.fb-date{font-size:.75rem;color:#9ca3af;margin-left:auto}
.fb-text{font-size:.875rem;color:#374151;line-height:1.5}
.fb-public{font-size:.7rem;font-weight:600;padding:.15rem .45rem;border-radius:99px}
.fb-public.yes{background:#d1fae5;color:#065f46}
.fb-public.no{background:#f3f4f6;color:#6b7280}
.admin-form-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.25rem;margin-bottom:1.25rem}
.admin-form-card h3{margin:0 0 .75rem;font-size:.95rem;color:#111827}
.form-group{margin-bottom:.75rem}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.25rem}
.form-group select,.form-group textarea{width:100%;border:1px solid #d1d5db;border-radius:.45rem;padding:.45rem .7rem;font-size:.875rem;box-sizing:border-box}
.form-group textarea{min-height:80px;resize:vertical}
.star-picker{display:flex;gap:.4rem;margin-bottom:.75rem}
.star-picker input[type=radio]{display:none}
.star-picker label{font-size:1.75rem;color:#e5e7eb;cursor:pointer;transition:color .1s}
.star-picker input:checked ~ label,.star-picker label:hover,.star-picker label:hover ~ label{color:#f59e0b}
/* reverse star trick */
.star-picker{flex-direction:row-reverse;justify-content:flex-end}
.star-picker label:hover,.star-picker label:hover ~ label,
.star-picker input:checked ~ label{color:#f59e0b}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .9rem;border-radius:.45rem;font-size:.8rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.empty{text-align:center;padding:3rem;color:#9ca3af}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827"><?= pt('Feedback & Reviews') ?></h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280"><?= pt('Business feedback about your Growth Partner work') ?></p>
        </div>
        <div style="display:flex;gap:.5rem">
            <a href="<?= SITE_URL ?>/partner/profile?code=<?= urlencode($partnerProfile['referral_code'] ?? '') ?>" class="btn btn-detail" target="_blank"><?= pt('My Profile →') ?></a>
            <a href="<?= SITE_URL ?>/partner/dashboard" class="btn btn-detail">← <?= pt('Dashboard') ?></a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <!-- Rating Summary -->
    <?php if ($summary['total'] > 0): ?>
    <div class="summary-card">
        <div>
            <div class="big-rating"><?= number_format((float)$summary['avg_rating'], 1) ?></div>
            <div class="rating-stars"><?= str_repeat('★', $curRating) . str_repeat('☆', 5 - $curRating) ?></div>
            <div class="rating-sub"><?= (int)$summary['total'] ?> <?= $summary['total'] != 1 ? pt('reviews') : pt('review') ?> · <?= (int)$summary['public_count'] ?> <?= pt('public') ?></div>
        </div>
        <div class="rating-bars">
            <?php
            $total = max(1, (int)$summary['total']);
            $bars  = [5=>$summary['five_star'],4=>$summary['four_star'],3=>$summary['three_star'],2=>0,1=>$summary['low_star']];
            foreach ($bars as $stars => $count): ?>
            <div class="bar-row">
                <div class="bar-label"><?= $stars ?>★</div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= round($count/$total*100) ?>%"></div></div>
                <div class="bar-count"><?= (int)$count ?></div>
            </div>
            <?php endforeach ?>
        </div>
    </div>
    <?php endif ?>

    <!-- Admin: Submit Feedback -->
    <?php if ($isAdmin): ?>
    <div class="admin-form-card">
        <h3><?= pt('Submit Feedback (Admin)') ?></h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="partner_id" value="<?= $partnerId ?>">

            <?php if ($adminBusinesses): ?>
            <div class="form-group">
                <label><?= pt('Business') ?></label>
                <select name="listing_id">
                    <option value=""><?= pt('— General / not linked to specific business —') ?></option>
                    <?php foreach ($adminBusinesses as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= e($b['business_name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php endif ?>

            <div class="form-group">
                <label><?= pt('Rating') ?></label>
                <div class="star-picker">
                    <?php for ($s = 5; $s >= 1; $s--): ?>
                    <input type="radio" name="rating" id="star<?= $s ?>" value="<?= $s ?>" <?= $s === 5 ? 'checked' : '' ?>>
                    <label for="star<?= $s ?>">★</label>
                    <?php endfor ?>
                </div>
            </div>

            <div class="form-group">
                <label><?= pt('Feedback') ?></label>
                <textarea name="feedback" placeholder="<?= pt('Describe the partner\'s performance, responsiveness, impact…') ?>" required></textarea>
            </div>

            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem">
                <input type="checkbox" name="is_public" id="is_public" value="1" checked>
                <label for="is_public" style="font-size:.83rem;color:#374151;cursor:pointer"><?= pt('Show on public profile') ?></label>
            </div>

            <button type="submit" class="btn btn-primary"><?= pt('Submit Feedback') ?></button>
        </form>
    </div>
    <?php endif ?>

    <!-- Filter tabs -->
    <div class="tabs">
        <?php foreach (['all'=>pt('All'),'public'=>pt('Public'),'private'=>pt('Private')] as $v => $lbl): ?>
        <a href="?vis=<?= $v ?>" class="tab<?= $filterVis===$v ? ' active' : '' ?>"><?= $lbl ?></a>
        <?php endforeach ?>
    </div>

    <!-- Feedback cards -->
    <?php if (!$feedbackRows): ?>
    <div class="empty">
        <div style="font-size:2rem;margin-bottom:.5rem">⭐</div>
        <p><?= pt('No feedback yet. Keep delivering great results and feedback will follow!') ?></p>
    </div>
    <?php else: ?>
    <?php foreach ($feedbackRows as $f):
        $stars = str_repeat('★', (int)$f['rating']) . str_repeat('☆', 5 - (int)$f['rating']);
    ?>
    <div class="fb-card">
        <div class="fb-top">
            <span class="fb-stars"><?= $stars ?></span>
            <span class="fb-biz"><?= $f['business_name'] ? e($f['business_name']) : pt('General Feedback') ?></span>
            <span class="fb-public <?= $f['is_public'] ? 'yes' : 'no' ?>"><?= $f['is_public'] ? pt('Public') : pt('Private') ?></span>
            <span class="fb-date"><?= date('d M Y', strtotime($f['created_at'])) ?></span>
        </div>
        <div class="fb-text"><?= e($f['feedback']) ?></div>
        <?php if ($f['submitted_by_name']): ?>
        <div style="font-size:.75rem;color:#9ca3af;margin-top:.4rem">— <?= e($f['submitted_by_name']) ?></div>
        <?php endif ?>
    </div>
    <?php endforeach ?>
    <?php endif ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
