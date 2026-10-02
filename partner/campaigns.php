<?php
/**
 * partner/campaigns.php — 237Biz Growth Partner
 * Campaign management across partner portfolio.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partner = requireGrowthPartner();
$pid     = (int)$partner['id'];
$pdo     = db();
$userId  = (int)($_SESSION['user_id'] ?? 0);

/* ── POST handlers ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Create campaign ──────────────────────────────────────────
    if ($action === 'create_campaign') {
        $lid    = (int)($_POST['listing_id'] ?? 0);
        if (!partnerCanAccessListing($pid, $lid)) die('Forbidden');
        $name   = trim($_POST['name'] ?? '');
        $type   = $_POST['campaign_type'] ?? 'business_promotion';
        $desc   = trim($_POST['description'] ?? '');
        $obj    = trim($_POST['objective'] ?? '');
        $offer  = trim($_POST['offer'] ?? '');
        $cta    = trim($_POST['call_to_action'] ?? '');
        $ta     = trim($_POST['target_audience'] ?? '');
        $budget = $_POST['budget'] !== '' ? (float)$_POST['budget'] : null;
        $start  = $_POST['start_date'] ?: null;
        $end    = $_POST['end_date'] ?: null;
        $status = 'draft';
        if ($start && $start <= date('Y-m-d')) $status = 'active';
        if ($start && $start > date('Y-m-d'))  $status = 'scheduled';
        $pdo->prepare("INSERT INTO campaigns (partner_id,listing_id,name,campaign_type,description,objective,
                         offer,call_to_action,target_audience,budget,start_date,end_date,status)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$pid,$lid,$name,$type,$desc,$obj,$offer,$cta,$ta,$budget,$start,$end,$status]);
        $cid = (int)$pdo->lastInsertId();
        logBusinessActivity($lid,$pid,$userId,'campaign_created',"Campaign '$name' created",'campaign',$cid);
        partnerAuditLog($pid,$userId,'campaign_created',"Campaign $cid: $name",['listing_id'=>$lid]);
        setFlash('success','Campaign created.');
        redirect(SITE_URL.'/partner/campaigns?lid='.$lid);
    }

    // ── Update campaign status ───────────────────────────────────
    if ($action === 'update_status') {
        $cid    = (int)($_POST['campaign_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $allowed = ['draft','scheduled','active','paused','completed','cancelled'];
        if (!in_array($status,$allowed)) die('Invalid');
        // verify ownership
        $row = $pdo->prepare("SELECT * FROM campaigns WHERE id=? AND partner_id=?");
        $row->execute([$cid,$pid]);
        $camp = $row->fetch();
        if (!$camp) die('Forbidden');
        $pdo->prepare("UPDATE campaigns SET status=? WHERE id=?")->execute([$status,$cid]);
        logBusinessActivity($camp['listing_id'],$pid,$userId,'campaign_updated',"Campaign '{$camp['name']}' status → $status",'campaign',$cid);
        setFlash('success','Campaign status updated.');
        redirect(SITE_URL.'/partner/campaigns?lid='.$camp['listing_id']);
    }

    // ── Log metrics ─────────────────────────────────────────────
    if ($action === 'log_metrics') {
        $cid  = (int)($_POST['campaign_id'] ?? 0);
        $row  = $pdo->prepare("SELECT * FROM campaigns WHERE id=? AND partner_id=?");
        $row->execute([$cid,$pid]);
        $camp = $row->fetch();
        if (!$camp) die('Forbidden');
        $date = $_POST['metric_date'] ?: date('Y-m-d');
        $src  = $_POST['source'] === 'partner_reported' ? 'partner_reported' : 'platform';
        $impr = (int)($_POST['impressions'] ?? 0);
        $pv   = (int)($_POST['profile_visits'] ?? 0);
        $cl   = (int)($_POST['clicks'] ?? 0);
        $wc   = (int)($_POST['website_clicks'] ?? 0);
        $wa   = (int)($_POST['whatsapp_clicks'] ?? 0);
        $calls= (int)($_POST['calls'] ?? 0);
        $leads= (int)($_POST['leads'] ?? 0);
        $book = (int)($_POST['bookings'] ?? 0);
        $conv = (int)($_POST['conversions'] ?? 0);
        $notes= trim($_POST['notes'] ?? '');
        $pdo->prepare("INSERT INTO campaign_metrics
                        (campaign_id,metric_date,source,impressions,profile_visits,clicks,
                         website_clicks,whatsapp_clicks,calls,leads,bookings,conversions,notes,recorded_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE
                        impressions=VALUES(impressions), profile_visits=VALUES(profile_visits),
                        clicks=VALUES(clicks), website_clicks=VALUES(website_clicks),
                        whatsapp_clicks=VALUES(whatsapp_clicks), calls=VALUES(calls),
                        leads=VALUES(leads), bookings=VALUES(bookings), conversions=VALUES(conversions),
                        notes=VALUES(notes), recorded_by=VALUES(recorded_by)")
            ->execute([$cid,$date,$src,$impr,$pv,$cl,$wc,$wa,$calls,$leads,$book,$conv,$notes,$userId]);
        partnerAuditLog($pid,$userId,'metrics_logged',"Campaign $cid metrics $date [$src]",[]);
        setFlash('success','Metrics saved.');
        redirect(SITE_URL.'/partner/campaigns?lid='.$camp['listing_id'].'&view=metrics&cid='.$cid);
    }

    // ── Campaign AI content generate ────────────────────────────────
    if ($action === 'campaign_ai_generate') {
        $cid         = (int)($_POST['campaign_id'] ?? 0);
        $contentType = $_POST['content_type'] ?? 'social_post';
        $style       = $_POST['style'] ?? 'professional';
        $platform    = trim($_POST['platform'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');

        $campRow = $pdo->prepare("SELECT c.*, l.title AS biz_name, l.tagline, l.services,
                                  cat.name_en AS cat_name, loc.name_en AS city
                                  FROM campaigns c
                                  JOIN listings l ON l.id=c.listing_id
                                  JOIN categories cat ON cat.id=l.category_id
                                  JOIN locations loc ON loc.id=l.location_id
                                  WHERE c.id=? AND c.partner_id=?");
        $campRow->execute([$cid, $pid]);
        $camp = $campRow->fetch();
        if (!$camp) { setFlash('error','Campaign not found.'); redirect(SITE_URL.'/partner/campaigns'); }

        // Build context — campaign name+CTA injected as active_campaign
        $ctx = [
            'business'        => $camp['biz_name'],
            'category'        => $camp['cat_name'],
            'city'            => $camp['city'],
            'tagline'         => $camp['tagline'] ?? '',
            'services'        => $camp['services'] ?? '',
            'avg_rating'      => null,
            'review_count'    => 0,
            'active_campaign' => $camp['name'] . ($camp['call_to_action'] ? ' — ' . $camp['call_to_action'] : ''),
        ];
        // Fetch rating only if sufficient reviews
        $rr = $pdo->prepare("SELECT COUNT(*) AS rc, ROUND(AVG(rating),1) AS ar FROM reviews WHERE listing_id=? AND status='approved'");
        $rr->execute([$camp['listing_id']]);
        $rrRow = $rr->fetch();
        if ((int)$rrRow['rc'] >= 3) { $ctx['avg_rating'] = $rrRow['ar']; $ctx['review_count'] = (int)$rrRow['rc']; }

        $validTypes  = ['social_post','instagram_caption','tiktok_concept','whatsapp_message','review_request','customer_followup','promotional_post','event_promotion'];
        $validStyles = ['professional','friendly','short','promotional','whatsapp','tiktok'];
        if (!in_array($contentType, $validTypes))  $contentType = 'social_post';
        if (!in_array($style, $validStyles))        $style = 'professional';

        $generated = generateAIContent($contentType, $style, $ctx, $notes);

        $ins = $pdo->prepare("INSERT INTO ai_generated_content
                              (partner_id, listing_id, user_id, campaign_id, content_type, style, platform, generated_text, prompt_summary, approval_status, created_at)
                              VALUES (?,?,?,?,?,?,?,?,?,'pending',NOW())");
        $ins->execute([$pid, $camp['listing_id'], $userId, $cid, $contentType, $style, $platform, $generated, $notes]);
        $newId = (int)$pdo->lastInsertId();

        partnerAuditLog($pid,$userId,'ai_content_generated',"Campaign AI $contentType for campaign $cid",[]);
        setFlash('ai_campaign_content', $generated . '|||' . $newId . '|||' . $cid);
        redirect(SITE_URL.'/partner/campaigns?view=ai&cid='.$cid);
    }

    // ── Campaign AI content approve ─────────────────────────────────
    if ($action === 'campaign_ai_approve') {
        $newId   = (int)($_POST['content_id'] ?? 0);
        $edited  = trim($_POST['edited_text'] ?? '');
        $cid     = (int)($_POST['campaign_id'] ?? 0);
        if ($newId && $edited) {
            $pdo->prepare("UPDATE ai_generated_content SET approval_status='approved', edited_text=?, approved_by=?, approved_at=NOW() WHERE id=? AND partner_id=?")
                ->execute([$edited, $userId, $newId, $pid]);
            // Also create a content_items draft
            $campRow2 = $pdo->prepare("SELECT listing_id FROM campaigns WHERE id=? AND partner_id=?");
            $campRow2->execute([$cid, $pid]);
            $cr2 = $campRow2->fetch();
            if ($cr2) {
                $pdo->prepare("INSERT INTO content_items (listing_id,partner_id,campaign_id,content_type,platform,body,status,created_at)
                               VALUES (?,?,?,'social_post',?,?,'draft',NOW())")
                    ->execute([$cr2['listing_id'], $pid, $cid, '', $edited]);
            }
            partnerAuditLog($pid,$userId,'ai_content_approved',"Campaign AI content $newId approved",[]);
            setFlash('success','Content approved and saved as a draft in the Content Calendar.');
        }
        redirect(SITE_URL.'/partner/campaigns?view=ai&cid='.$cid);
    }

    // ── Campaign AI content reject ──────────────────────────────────
    if ($action === 'campaign_ai_reject') {
        $newId = (int)($_POST['content_id'] ?? 0);
        $cid   = (int)($_POST['campaign_id'] ?? 0);
        if ($newId) {
            $pdo->prepare("UPDATE ai_generated_content SET approval_status='rejected', approved_by=?, approved_at=NOW() WHERE id=? AND partner_id=?")
                ->execute([$userId, $newId, $pid]);
            partnerAuditLog($pid,$userId,'ai_content_rejected',"Campaign AI content $newId rejected",[]);
        }
        redirect(SITE_URL.'/partner/campaigns?view=ai&cid='.$cid);
    }
}

/* ── Filters ─────────────────────────────────────────────────────── */
$filterLid    = (int)($_GET['lid'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type'] ?? '';
$view         = $_GET['view'] ?? 'list';   // list | metrics | ai
$viewCid      = (int)($_GET['cid'] ?? 0);

/* ── Partner listings dropdown ───────────────────────────────────── */
$listings = getPartnerListings($pid);
$listingMap = [];
foreach ($listings as $l) $listingMap[$l['id']] = $l;

/* ── Campaigns query ─────────────────────────────────────────────── */
$where = ["c.partner_id = $pid"];
$params = [];
if ($filterLid)    { $where[] = "c.listing_id = ?";     $params[] = $filterLid; }
if ($filterStatus) { $where[] = "c.status = ?";          $params[] = $filterStatus; }
if ($filterType)   { $where[] = "c.campaign_type = ?";   $params[] = $filterType; }

$sql = "SELECT c.*, l.title AS biz_name, loc.name_en AS city
        FROM campaigns c
        JOIN listings l ON l.id = c.listing_id
        JOIN locations loc ON loc.id = l.location_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.created_at DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$campaigns = $st->fetchAll();

/* ── Metrics view ────────────────────────────────────────────────── */
$campaignDetail = null;
$metrics        = [];
if (($view === 'metrics' || $view === 'ai') && $viewCid) {
    $r = $pdo->prepare("SELECT c.*, l.title AS biz_name, l.tagline, l.services, cat.name_en AS cat_name, loc.name_en AS city
                        FROM campaigns c
                        JOIN listings l ON l.id=c.listing_id
                        JOIN categories cat ON cat.id=l.category_id
                        JOIN locations loc ON loc.id=l.location_id
                        WHERE c.id=? AND c.partner_id=?");
    $r->execute([$viewCid, $pid]);
    $campaignDetail = $r->fetch();
    if ($campaignDetail && $view === 'metrics') {
        $m = $pdo->prepare("SELECT * FROM campaign_metrics WHERE campaign_id=? ORDER BY metric_date DESC LIMIT 60");
        $m->execute([$viewCid]);
        $metrics = $m->fetchAll();
    }
}

/* ── AI view data ─────────────────────────────────────────────────── */
$aiHistory = [];
if ($view === 'ai' && $campaignDetail) {
    $ah = $pdo->prepare("SELECT * FROM ai_generated_content WHERE campaign_id=? AND partner_id=? ORDER BY created_at DESC LIMIT 30");
    $ah->execute([$viewCid, $pid]);
    $aiHistory = $ah->fetchAll();
}

/* ── Summary counts ──────────────────────────────────────────────── */
$sumSt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM campaigns WHERE partner_id=? GROUP BY status");
$sumSt->execute([$pid]);
$statusCounts = [];
foreach ($sumSt->fetchAll() as $r) $statusCounts[$r['status']] = (int)$r['cnt'];
$totalCampaigns = array_sum($statusCounts);

$pageTitle = 'Campaign Management';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/partner.css">
<style>
.camp-header{background:linear-gradient(135deg,#1a56db 0%,#1e40af 100%);color:#fff;padding:28px 32px;border-radius:12px;margin-bottom:24px}
.camp-header h1{margin:0 0 6px;font-size:1.6rem}
.camp-header p{margin:0;opacity:.85;font-size:.95rem}
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center}
.filter-bar select,.filter-bar input{padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem}
.filter-bar .btn-sm{padding:7px 14px;font-size:.9rem}
.summary-strip{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.sum-pill{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:6px 16px;font-size:.85rem;display:flex;align-items:center;gap:6px}
.sum-pill .dot{width:10px;height:10px;border-radius:50%;display:inline-block}
.camp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px}
.camp-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;transition:.2s}
.camp-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08);border-color:#6366f1}
.camp-card-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px}
.camp-name{font-weight:600;font-size:1rem;color:#111827;margin:0 0 4px}
.camp-biz{font-size:.82rem;color:#6b7280}
.status-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:600}
.st-draft{background:#f3f4f6;color:#374151}
.st-scheduled{background:#dbeafe;color:#1e40af}
.st-active{background:#dcfce7;color:#166534}
.st-paused{background:#fef9c3;color:#854d0e}
.st-completed{background:#ede9fe;color:#5b21b6}
.st-cancelled{background:#fee2e2;color:#991b1b}
.camp-meta{display:flex;gap:12px;flex-wrap:wrap;margin:8px 0;font-size:.82rem;color:#6b7280}
.camp-meta span{display:flex;align-items:center;gap:4px}
.camp-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center}
.camp-actions form{margin:0}
.btn-xs{padding:4px 10px;font-size:.8rem;border-radius:5px;border:1px solid #d1d5db;background:#fff;cursor:pointer;color:#374151;text-decoration:none}
.btn-xs:hover{background:#f3f4f6}
.btn-xs.primary{background:#1a56db;color:#fff;border-color:#1a56db}
.btn-xs.primary:hover{background:#1e40af}
.btn-xs.green{background:#16a34a;color:#fff;border-color:#16a34a}
.btn-xs.red{background:#dc2626;color:#fff;border-color:#dc2626}
.create-form{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;margin-bottom:24px}
.create-form h3{margin:0 0 18px;font-size:1.1rem;color:#111827}
.f-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.f-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.f-full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group label{font-size:.85rem;font-weight:500;color:#374151}
.form-group input,.form-group select,.form-group textarea{padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;width:100%;box-sizing:border-box}
.form-group textarea{resize:vertical;min-height:70px}
.section-title{font-size:1.1rem;font-weight:600;color:#111827;margin:0 0 16px;padding-bottom:10px;border-bottom:1px solid #f3f4f6}
.metrics-table{width:100%;border-collapse:collapse;font-size:.88rem}
.metrics-table th{background:#f9fafb;padding:8px 12px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb}
.metrics-table td{padding:8px 12px;border-bottom:1px solid #f3f4f6;color:#374151}
.metrics-table tr:hover td{background:#fafafa}
.src-platform{background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:600}
.src-reported{background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:600}
.back-link{display:inline-flex;align-items:center;gap:6px;color:#6b7280;font-size:.9rem;text-decoration:none;margin-bottom:16px}
.back-link:hover{color:#1a56db}
.empty-state{text-align:center;padding:48px 20px;color:#9ca3af}
.empty-state .icon{font-size:3rem;margin-bottom:12px}
.empty-state p{font-size:1rem;color:#6b7280}
.flash-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:12px 18px;border-radius:8px;margin-bottom:18px;font-size:.92rem}
.flash-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:12px 18px;border-radius:8px;margin-bottom:18px;font-size:.92rem}
</style>

<div class="container" style="max-width:1100px;margin:30px auto;padding:0 16px">

<?php if ($flash = getFlash('success')): ?>
<div class="flash-success">✓ <?= e($flash) ?></div>
<?php endif; ?>
<?php if ($flash = getFlash('error')): ?>
<div class="flash-error">✗ <?= e($flash) ?></div>
<?php endif; ?>

<?php if ($view === 'ai' && $campaignDetail): ?>
<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- CAMPAIGN AI CONTENT VIEW                                         -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<a href="<?= SITE_URL ?>/partner/campaigns" class="back-link">← Back to Campaigns</a>

<div class="camp-header">
    <h1>✦ AI Content: <?= e($campaignDetail['name']) ?></h1>
    <p><?= e($campaignDetail['biz_name']) ?> · <?= ucwords(str_replace('_',' ',$campaignDetail['campaign_type'])) ?> · <?= ucfirst($campaignDetail['status']) ?></p>
</div>

<?php $aiCampaignFlash = getFlash('ai_campaign_content');
if ($aiCampaignFlash):
    [$genText, $genId, $genCid] = array_pad(explode('|||', $aiCampaignFlash, 3), 3, '');
    $genId  = (int)$genId;
    $genCid = (int)$genCid;
?>
<div style="background:#f5f3ff;border:2px solid #7c3aed;border-radius:12px;padding:20px;margin-bottom:24px">
    <h3 style="color:#5b21b6;margin:0 0 12px">✦ Generated — Review Before Using</h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="campaign_ai_approve">
        <input type="hidden" name="content_id" value="<?= $genId ?>">
        <input type="hidden" name="campaign_id" value="<?= $genCid ?>">
        <textarea name="edited_text" rows="6" style="width:100%;border:1px solid #ddd6fe;border-radius:8px;padding:12px;font-size:.95rem;resize:vertical;box-sizing:border-box"><?= e($genText) ?></textarea>
        <div style="display:flex;gap:10px;margin-top:10px;align-items:center">
            <button type="submit" class="btn-xs primary" style="padding:8px 18px;font-size:.9rem">✓ Approve &amp; Save Draft</button>
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="campaign_ai_reject">
                <input type="hidden" name="content_id" value="<?= $genId ?>">
                <input type="hidden" name="campaign_id" value="<?= $genCid ?>">
                <button type="submit" class="btn-xs red">✗ Reject</button>
            </form>
            <a href="<?= SITE_URL ?>/partner/campaigns?view=ai&cid=<?= $campaignDetail['id'] ?>" style="font-size:.85rem;color:#6b7280;margin-left:4px">Discard</a>
        </div>
    </form>
    <p style="font-size:.8rem;color:#7c3aed;margin:10px 0 0">⚠ Review carefully — approved content saves as a Draft in your Content Calendar.</p>
</div>
<?php endif; ?>

<?php if ($flash2 = getFlash('success')): ?>
<div class="flash success"><?= e($flash2) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start">
<!-- Generate Form -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px">
    <h3 style="margin:0 0 16px;color:#1f2937">Generate Campaign Content</h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="campaign_ai_generate">
        <input type="hidden" name="campaign_id" value="<?= $campaignDetail['id'] ?>">

        <div style="margin-bottom:14px">
            <label style="display:block;font-size:.87rem;font-weight:600;color:#374151;margin-bottom:5px">Content Type</label>
            <select name="content_type" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.9rem">
                <optgroup label="Social Media">
                    <option value="social_post">Social Post (general)</option>
                    <option value="instagram_caption">Instagram Caption</option>
                    <option value="tiktok_concept">TikTok Concept</option>
                    <option value="promotional_post">Promotional Post</option>
                </optgroup>
                <optgroup label="Messaging">
                    <option value="whatsapp_message">WhatsApp Message</option>
                    <option value="review_request">Review Request</option>
                    <option value="customer_followup">Customer Follow-Up</option>
                </optgroup>
                <optgroup label="Events">
                    <option value="event_promotion">Event Promotion</option>
                </optgroup>
            </select>
        </div>

        <div style="margin-bottom:14px">
            <label style="display:block;font-size:.87rem;font-weight:600;color:#374151;margin-bottom:5px">Style</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                <?php foreach (['professional'=>'Professional','friendly'=>'Friendly','short'=>'Short & Punchy','promotional'=>'Promotional','whatsapp'=>'WhatsApp','tiktok'=>'TikTok'] as $sv=>$sl): ?>
                <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.85rem">
                    <input type="radio" name="style" value="<?= $sv ?>" <?= $sv==='professional'?'checked':'' ?>>
                    <?= $sl ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="margin-bottom:14px">
            <label style="display:block;font-size:.87rem;font-weight:600;color:#374151;margin-bottom:5px">Platform (optional)</label>
            <input type="text" name="platform" placeholder="e.g. Instagram, Facebook, WhatsApp" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.9rem;box-sizing:border-box">
        </div>

        <div style="margin-bottom:16px">
            <label style="display:block;font-size:.87rem;font-weight:600;color:#374151;margin-bottom:5px">Extra Notes <span style="font-weight:400;color:#9ca3af">(optional)</span></label>
            <textarea name="notes" rows="3" placeholder="Any specific angle, tone, or context for this piece…" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.9rem;resize:vertical;box-sizing:border-box"></textarea>
        </div>

        <button type="submit" style="background:#7c3aed;color:#fff;border:none;padding:10px 22px;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer;width:100%">✦ Generate Content</button>
    </form>
</div>

<!-- Campaign Context Panel -->
<div>
<div style="background:#faf5ff;border:1px solid #ddd6fe;border-radius:12px;padding:18px;margin-bottom:16px">
    <h4 style="color:#5b21b6;margin:0 0 10px;font-size:.95rem">📌 Campaign Context Used</h4>
    <p style="font-size:.82rem;color:#4b5563;margin:0 0 8px">AI uses only verified business + campaign data:</p>
    <ul style="font-size:.82rem;color:#374151;margin:0;padding-left:16px;line-height:1.8">
        <li><strong>Business:</strong> <?= e($campaignDetail['biz_name']) ?></li>
        <li><strong>Category:</strong> <?= e($campaignDetail['cat_name']) ?></li>
        <li><strong>City:</strong> <?= e($campaignDetail['city']) ?></li>
        <?php if ($campaignDetail['tagline']): ?><li><strong>Tagline:</strong> <?= e($campaignDetail['tagline']) ?></li><?php endif; ?>
        <li><strong>Campaign:</strong> <?= e($campaignDetail['name']) ?></li>
        <?php if ($campaignDetail['call_to_action']): ?><li><strong>CTA:</strong> <?= e($campaignDetail['call_to_action']) ?></li><?php endif; ?>
    </ul>
    <p style="font-size:.78rem;color:#7c3aed;margin:10px 0 0">✓ AI never invents prices, offers, hours, reviews, or statistics.</p>
</div>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px">
    <h4 style="margin:0 0 8px;font-size:.88rem;color:#374151">💡 Tips</h4>
    <ul style="font-size:.82rem;color:#6b7280;margin:0;padding-left:14px;line-height:1.7">
        <li>Add notes for a specific angle or hook</li>
        <li>Always review before approving</li>
        <li>Approved → Draft in Content Calendar</li>
        <li>Generate multiple styles to compare</li>
    </ul>
</div>
</div>
</div>

<?php if ($aiHistory): ?>
<div style="margin-top:28px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px">
    <h3 style="margin:0 0 14px;color:#1f2937">Previous Generations for This Campaign</h3>
    <table style="width:100%;border-collapse:collapse;font-size:.85rem">
        <thead>
            <tr style="background:#f9fafb">
                <th style="padding:8px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb">Date</th>
                <th style="padding:8px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb">Type</th>
                <th style="padding:8px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb">Style</th>
                <th style="padding:8px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb">Status</th>
                <th style="padding:8px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb">Preview</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($aiHistory as $ai): ?>
        <tr>
            <td style="padding:8px 10px;border-bottom:1px solid #f3f4f6;color:#6b7280"><?= date('d M Y', strtotime($ai['created_at'])) ?></td>
            <td style="padding:8px 10px;border-bottom:1px solid #f3f4f6"><?= ucwords(str_replace('_',' ',$ai['content_type'])) ?></td>
            <td style="padding:8px 10px;border-bottom:1px solid #f3f4f6"><?= ucfirst($ai['style']) ?></td>
            <td style="padding:8px 10px;border-bottom:1px solid #f3f4f6">
                <?php $sc=['approved'=>'#059669','rejected'=>'#dc2626','pending'=>'#ca8a04']; ?>
                <span style="color:<?= $sc[$ai['approval_status']]??'#6b7280' ?>;font-weight:600;font-size:.8rem"><?= ucfirst($ai['approval_status']) ?></span>
            </td>
            <td style="padding:8px 10px;border-bottom:1px solid #f3f4f6;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#374151">
                <?= e(mb_substr($ai['generated_text'], 0, 100)) ?>…
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php elseif ($view === 'metrics' && $campaignDetail): ?>
<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- METRICS VIEW                                                     -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<a href="<?= SITE_URL ?>/partner/campaigns?lid=<?= $campaignDetail['listing_id'] ?>" class="back-link">← Back to Campaigns</a>

<div class="camp-header">
    <h1>📊 <?= e($campaignDetail['name']) ?></h1>
    <p><?= e($campaignDetail['biz_name']) ?> · <?= ucwords(str_replace('_',' ',$campaignDetail['campaign_type'])) ?></p>
</div>

<!-- Log Metrics Form -->
<div class="create-form" style="margin-bottom:24px">
    <h3>Log Performance Metrics</h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="log_metrics">
        <input type="hidden" name="campaign_id" value="<?= $campaignDetail['id'] ?>">
        <div class="f-grid">
            <div class="form-group">
                <label>Date</label>
                <input type="date" name="metric_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Source</label>
                <select name="source">
                    <option value="platform">Platform (verified)</option>
                    <option value="partner_reported">Partner Reported</option>
                </select>
            </div>
            <div class="form-group">
                <label>Impressions</label>
                <input type="number" name="impressions" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Profile Visits</label>
                <input type="number" name="profile_visits" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Clicks</label>
                <input type="number" name="clicks" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Website Clicks</label>
                <input type="number" name="website_clicks" value="0" min="0">
            </div>
            <div class="form-group">
                <label>WhatsApp Clicks</label>
                <input type="number" name="whatsapp_clicks" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Phone Calls</label>
                <input type="number" name="calls" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Leads</label>
                <input type="number" name="leads" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Bookings</label>
                <input type="number" name="bookings" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Conversions</label>
                <input type="number" name="conversions" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Notes</label>
                <input type="text" name="notes" placeholder="Optional notes">
            </div>
        </div>
        <div style="margin-top:14px">
            <button type="submit" class="btn-xs primary" style="padding:8px 20px;font-size:.9rem">Save Metrics</button>
        </div>
    </form>
</div>

<!-- Metrics History -->
<div class="create-form">
    <p class="section-title">Metrics History</p>
    <?php if (empty($metrics)): ?>
    <div class="empty-state"><p>No metrics logged yet for this campaign.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="metrics-table">
        <thead>
            <tr>
                <th>Date</th><th>Source</th><th>Impr</th><th>Visits</th>
                <th>Clicks</th><th>Web</th><th>WA</th><th>Calls</th>
                <th>Leads</th><th>Book</th><th>Conv</th><th>Notes</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($metrics as $m): ?>
        <tr>
            <td><?= e($m['metric_date']) ?></td>
            <td><?php if ($m['source']==='platform'): ?>
                <span class="src-platform">Platform</span>
                <?php else: ?>
                <span class="src-reported">Reported</span>
                <?php endif; ?></td>
            <td><?= number_format($m['impressions']) ?></td>
            <td><?= number_format($m['profile_visits']) ?></td>
            <td><?= number_format($m['clicks']) ?></td>
            <td><?= number_format($m['website_clicks']) ?></td>
            <td><?= number_format($m['whatsapp_clicks']) ?></td>
            <td><?= number_format($m['calls']) ?></td>
            <td><?= number_format($m['leads']) ?></td>
            <td><?= number_format($m['bookings']) ?></td>
            <td><?= number_format($m['conversions']) ?></td>
            <td style="max-width:160px;white-space:normal;font-size:.8rem;color:#6b7280"><?= e($m['notes']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- CAMPAIGN LIST VIEW                                               -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="camp-header">
    <h1>📣 Campaign Management</h1>
    <p><?= $totalCampaigns ?> campaign<?= $totalCampaigns !== 1 ? 's' : '' ?> across your portfolio</p>
</div>

<!-- Summary strip -->
<?php if ($totalCampaigns > 0): ?>
<div class="summary-strip">
    <?php
    $dots = ['active'=>'#16a34a','scheduled'=>'#2563eb','paused'=>'#ca8a04','draft'=>'#9ca3af','completed'=>'#7c3aed','cancelled'=>'#dc2626'];
    foreach ($statusCounts as $st => $cnt): ?>
    <span class="sum-pill"><span class="dot" style="background:<?= $dots[$st]??'#9ca3af' ?>"></span><?= $cnt ?> <?= ucfirst($st) ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filter bar -->
<div class="filter-bar">
    <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;width:100%">
        <select name="lid" onchange="this.form.submit()">
            <option value="">All Businesses</option>
            <?php foreach ($listings as $l): ?>
            <option value="<?= $l['id'] ?>" <?= $filterLid==$l['id']?'selected':'' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <?php foreach (['draft','scheduled','active','paused','completed','cancelled'] as $s): ?>
            <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type" onchange="this.form.submit()">
            <option value="">All Types</option>
            <?php foreach (['business_promotion','product_promotion','service_promotion','special_offer','event','review_campaign','social_media_campaign','seasonal_campaign'] as $t): ?>
            <option value="<?= $t ?>" <?= $filterType===$t?'selected':'' ?>><?= ucwords(str_replace('_',' ',$t)) ?></option>
            <?php endforeach; ?>
        </select>
        <a href="<?= SITE_URL ?>/partner/campaigns" class="btn-xs">Clear</a>
    </form>
</div>

<!-- Create campaign form -->
<div class="create-form">
    <h3>➕ Create Campaign</h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_campaign">
        <div class="f-grid">
            <div class="form-group">
                <label>Business <span style="color:red">*</span></label>
                <select name="listing_id" required>
                    <option value="">Select business…</option>
                    <?php foreach ($listings as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $filterLid==$l['id']?'selected':'' ?>><?= e($l['name']) ?> — <?= e($l['city']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Campaign Type <span style="color:red">*</span></label>
                <select name="campaign_type">
                    <?php foreach (['business_promotion'=>'Business Promotion','product_promotion'=>'Product Promotion','service_promotion'=>'Service Promotion','special_offer'=>'Special Offer','event'=>'Event','review_campaign'=>'Review Campaign','social_media_campaign'=>'Social Media Campaign','seasonal_campaign'=>'Seasonal Campaign'] as $v => $lbl): ?>
                    <option value="<?= $v ?>"><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group f-full">
                <label>Campaign Name <span style="color:red">*</span></label>
                <input type="text" name="name" placeholder="e.g. Ramadan Special Offer" required maxlength="200">
            </div>
            <div class="form-group">
                <label>Objective</label>
                <input type="text" name="objective" placeholder="e.g. Increase foot traffic by 20%" maxlength="200">
            </div>
            <div class="form-group">
                <label>Target Audience</label>
                <input type="text" name="target_audience" placeholder="e.g. Women 25-45 in Yaounde" maxlength="300">
            </div>
            <div class="form-group f-full">
                <label>Offer / Promotion</label>
                <input type="text" name="offer" placeholder="e.g. 20% off all services this week" maxlength="500">
            </div>
            <div class="form-group">
                <label>Call to Action</label>
                <input type="text" name="call_to_action" placeholder="e.g. Book now on WhatsApp" maxlength="200">
            </div>
            <div class="form-group">
                <label>Budget (XAF)</label>
                <input type="number" name="budget" min="0" step="100" placeholder="Optional">
            </div>
            <div class="form-group">
                <label>Start Date</label>
                <input type="date" name="start_date">
            </div>
            <div class="form-group">
                <label>End Date</label>
                <input type="date" name="end_date">
            </div>
            <div class="form-group f-full">
                <label>Description</label>
                <textarea name="description" placeholder="Campaign details, notes…"></textarea>
            </div>
        </div>
        <div style="margin-top:14px">
            <button type="submit" class="btn-xs primary" style="padding:8px 20px;font-size:.9rem">Create Campaign</button>
        </div>
    </form>
</div>

<!-- Campaign grid -->
<?php if (empty($campaigns)): ?>
<div class="empty-state">
    <div class="icon">📣</div>
    <p>No campaigns found. Create your first campaign above.</p>
</div>
<?php else: ?>
<div class="camp-grid">
<?php foreach ($campaigns as $c):
    $badge = 'st-' . $c['status'];
    $typeLabel = ucwords(str_replace('_',' ',$c['campaign_type']));
?>
<div class="camp-card">
    <div class="camp-card-header">
        <div>
            <div class="camp-name"><?= e($c['name']) ?></div>
            <div class="camp-biz">🏢 <?= e($c['biz_name']) ?> · <?= e($c['city']) ?></div>
        </div>
        <span class="status-badge <?= $badge ?>"><?= ucfirst($c['status']) ?></span>
    </div>
    <div style="font-size:.83rem;color:#6366f1;font-weight:500;margin-bottom:8px"><?= $typeLabel ?></div>
    <?php if ($c['offer']): ?>
    <div style="background:#f0fdf4;border-left:3px solid #16a34a;padding:6px 10px;border-radius:4px;font-size:.85rem;color:#166534;margin-bottom:8px">
        🎁 <?= e($c['offer']) ?>
    </div>
    <?php endif; ?>
    <?php if ($c['objective']): ?>
    <div style="font-size:.83rem;color:#374151;margin-bottom:6px">🎯 <?= e($c['objective']) ?></div>
    <?php endif; ?>
    <div class="camp-meta">
        <?php if ($c['start_date']): ?><span>📅 <?= date('d M Y', strtotime($c['start_date'])) ?></span><?php endif; ?>
        <?php if ($c['end_date']): ?><span>→ <?= date('d M Y', strtotime($c['end_date'])) ?></span><?php endif; ?>
        <?php if ($c['budget']): ?><span>💰 <?= number_format($c['budget'],0) ?> XAF</span><?php endif; ?>
    </div>
    <?php if ($c['call_to_action']): ?>
    <div style="font-size:.82rem;color:#6b7280;margin-top:4px">CTA: <?= e($c['call_to_action']) ?></div>
    <?php endif; ?>
    <div class="camp-actions">
        <a href="<?= SITE_URL ?>/partner/business?lid=<?= $c['listing_id'] ?>&tab=campaigns" class="btn-xs">Edit</a>
        <a href="<?= SITE_URL ?>/partner/campaigns?view=metrics&cid=<?= $c['id'] ?>" class="btn-xs primary">📊 Metrics</a>
        <a href="<?= SITE_URL ?>/partner/campaigns?view=ai&cid=<?= $c['id'] ?>" class="btn-xs" style="border-color:#7c3aed;color:#7c3aed">✦ AI Content</a>
        <!-- Status actions -->
        <?php if ($c['status'] === 'draft' || $c['status'] === 'paused'): ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
            <input type="hidden" name="status" value="active">
            <button type="submit" class="btn-xs green">▶ Activate</button>
        </form>
        <?php elseif ($c['status'] === 'active'): ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
            <input type="hidden" name="status" value="paused">
            <button type="submit" class="btn-xs" style="border-color:#ca8a04;color:#854d0e">⏸ Pause</button>
        </form>
        <?php endif; ?>
        <?php if (!in_array($c['status'],['completed','cancelled'])): ?>
        <form method="post" onsubmit="return confirm('Mark as completed?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
            <input type="hidden" name="status" value="completed">
            <button type="submit" class="btn-xs">✓ Complete</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; // end list view ?>

</div><!-- /container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
