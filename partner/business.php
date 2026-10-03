<?php
/**
 * partner/business.php — Business Growth Workspace (Phase 2)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = currentUser()['id'];
$pdo            = db();

$listingId = (int)($_GET['id'] ?? 0);
if (!$listingId || !partnerCanAccessListing($partnerId, $listingId)) {
    redirect(SITE_URL . '/partner/portfolio');
}

$listingSt = $pdo->prepare("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon,
           loc.name_en AS city, u.name AS owner_name,
           (SELECT AVG(r.rating) FROM reviews r WHERE r.listing_id = l.id) AS avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.listing_id = l.id) AS review_count
    FROM listings l
    JOIN categories c   ON c.id = l.category_id
    JOIN locations loc  ON loc.id = l.location_id
    LEFT JOIN users u   ON u.id = l.user_id
    WHERE l.id = ?
");
$listingSt->execute([$listingId]);
$listing = $listingSt->fetch();
if (!$listing) redirect(SITE_URL . '/partner/portfolio');

$tab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview','growth_plan','tasks','leads','campaigns','content','reviews','activity','opportunities','reports','ai'];
if (!in_array($tab, $validTabs)) $tab = 'overview';

// ── POST handler ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // TASKS
    if ($action === 'add_task') {
        $title    = trim($_POST['title'] ?? '');
        $dueDate  = $_POST['due_date'] ?? null;
        $priority = in_array($_POST['priority']??'', ['low','medium','high','urgent']) ? $_POST['priority'] : 'medium';
        $notes    = trim($_POST['notes'] ?? '');
        if ($title) {
            $pdo->prepare("INSERT INTO growth_tasks (partner_id, listing_id, title, due_date, priority, notes) VALUES (?,?,?,?,?,?)")
                ->execute([$partnerId, $listingId, $title, $dueDate ?: null, $priority, $notes ?: null]);
            partnerAuditLog($partnerId, $userId, $listingId, 'task_created', "Task: {$title}");
            logBusinessActivity($listingId, $partnerId, $userId, 'task_created', "Task created: {$title}", 'task');
        }
    }

    // GROWTH PLAN
    if ($action === 'create_plan') {
        $name   = trim($_POST['plan_name'] ?? '');
        $start  = $_POST['start_date'] ?? date('Y-m-d');
        $end    = $_POST['end_date'] ?? null;
        $notes  = trim($_POST['plan_notes'] ?? '');
        if ($name) {
            $pdo->prepare("INSERT INTO growth_plans (partner_id, listing_id, name, start_date, end_date, notes, status) VALUES (?,?,?,?,?,?,'active')")
                ->execute([$partnerId, $listingId, $name, $start, $end ?: null, $notes ?: null]);
            partnerAuditLog($partnerId, $userId, $listingId, 'plan_created', "Plan: {$name}");
            logBusinessActivity($listingId, $partnerId, $userId, 'plan_created', "Growth plan created: {$name}", 'plan');
        }
    }

    // PLAN OBJECTIVE
    if ($action === 'add_objective') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $title  = trim($_POST['obj_title'] ?? '');
        $target = (int)($_POST['target'] ?? 0);
        $type   = trim($_POST['obj_type'] ?? 'other');
        if ($planId && $title) {
            // Verify plan belongs to this partner/listing
            $chk = $pdo->prepare("SELECT id FROM growth_plans WHERE id=? AND partner_id=? AND listing_id=?");
            $chk->execute([$planId, $partnerId, $listingId]);
            if ($chk->fetch()) {
                $pdo->prepare("INSERT INTO growth_plan_objectives (plan_id, title, target, current, type) VALUES (?,?,?,0,?)")
                    ->execute([$planId, $title, $target, $type]);
            }
        }
    }

    // LEADS
    if ($action === 'add_lead') {
        $name   = trim($_POST['customer_name'] ?? '');
        $email  = trim($_POST['customer_email'] ?? '');
        $phone  = trim($_POST['customer_phone'] ?? '');
        $source = trim($_POST['source'] ?? 'direct');
        $notes  = trim($_POST['notes'] ?? '');
        if ($name) {
            $pdo->prepare("INSERT INTO partner_leads (partner_id, listing_id, customer_name, customer_email, customer_phone, source, notes, status) VALUES (?,?,?,?,?,?,?,'new')")
                ->execute([$partnerId, $listingId, $name, $email ?: null, $phone ?: null, $source, $notes ?: null]);
            logBusinessActivity($listingId, $partnerId, $userId, 'lead_received', "New lead: {$name}", 'lead');
        }
    }

    // LEAD ACTIVITY
    if ($action === 'add_lead_activity') {
        $leadId  = (int)($_POST['lead_id'] ?? 0);
        $actType = $_POST['activity_type'] ?? 'note';
        $notes   = trim($_POST['act_notes'] ?? '');
        $fup     = $_POST['follow_up_date'] ?? null;
        $validTypes = ['created','contacted','email_sent','message_sent','phone_call','follow_up','appointment','booking','converted','lost','note'];
        if ($leadId && in_array($actType, $validTypes)) {
            $chk = $pdo->prepare("SELECT id FROM partner_leads WHERE id=? AND partner_id=? AND listing_id=?");
            $chk->execute([$leadId, $partnerId, $listingId]);
            if ($chk->fetch()) {
                $pdo->prepare("INSERT INTO lead_activities (lead_id, partner_id, activity_type, notes, follow_up_date, created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$leadId, $partnerId, $actType, $notes ?: null, $fup ?: null, $userId]);
                // Update lead status if activity implies change
                $statusMap = ['contacted'=>'contacted','converted'=>'converted','lost'=>'lost'];
                if (isset($statusMap[$actType])) {
                    $pdo->prepare("UPDATE partner_leads SET status=?, last_activity=NOW() WHERE id=?")->execute([$statusMap[$actType], $leadId]);
                } else {
                    $pdo->prepare("UPDATE partner_leads SET last_activity=NOW() WHERE id=?")->execute([$leadId]);
                }
                if ($fup) {
                    $pdo->prepare("UPDATE partner_leads SET follow_up_date=? WHERE id=?")->execute([$fup, $leadId]);
                }
            }
        }
    }

    // CAMPAIGNS
    if ($action === 'create_campaign') {
        $name   = trim($_POST['camp_name'] ?? '');
        $type   = $_POST['camp_type'] ?? 'business_promotion';
        $desc   = trim($_POST['camp_desc'] ?? '');
        $obj    = trim($_POST['camp_objective'] ?? '');
        $start  = $_POST['camp_start'] ?? null;
        $end    = $_POST['camp_end'] ?? null;
        $cta    = trim($_POST['camp_cta'] ?? '');
        $offer  = trim($_POST['camp_offer'] ?? '');
        $budget = (float)($_POST['camp_budget'] ?? 0);
        $validTypes = ['business_promotion','product_promotion','service_promotion','special_offer','event','review_campaign','social_media_campaign','seasonal_campaign'];
        if ($name && in_array($type, $validTypes)) {
            $pdo->prepare("INSERT INTO campaigns (partner_id, listing_id, name, campaign_type, description, objective, call_to_action, offer, budget, start_date, end_date, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'draft')")
                ->execute([$partnerId, $listingId, $name, $type, $desc ?: null, $obj ?: null, $cta ?: null, $offer ?: null, $budget ?: null, $start ?: null, $end ?: null]);
            partnerAuditLog($partnerId, $userId, $listingId, 'campaign_created', "Campaign: {$name}");
            logBusinessActivity($listingId, $partnerId, $userId, 'campaign_created', "Campaign created: {$name}", 'campaign');
        }
    }

    // CONTENT ITEM
    if ($action === 'add_content') {
        $title      = trim($_POST['ci_title'] ?? '');
        $body       = trim($_POST['ci_body'] ?? '');
        $type       = $_POST['ci_type'] ?? 'social_post';
        $platform   = trim($_POST['ci_platform'] ?? '');
        $scheduled  = $_POST['ci_scheduled'] ?? null;
        $campaignId = (int)($_POST['ci_campaign'] ?? 0);
        $validTypes = ['social_post','promotional_post','product_post','service_post','event_post','review_post','video','image','announcement'];
        if (($title || $body) && in_array($type, $validTypes)) {
            $pdo->prepare("INSERT INTO content_items (partner_id, listing_id, campaign_id, content_type, title, body, platform, scheduled_date, status) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$partnerId, $listingId, $campaignId ?: null, $type, $title ?: null, $body ?: null, $platform ?: null, $scheduled ?: null, $scheduled ? 'scheduled' : 'draft']);
            logBusinessActivity($listingId, $partnerId, $userId, 'content_created', "Content added: ".($title ?: 'untitled'), 'content');
        }
    }

    // REVIEW CAMPAIGN
    if ($action === 'create_review_campaign') {
        $name    = trim($_POST['rc_name'] ?? '');
        $target  = (int)($_POST['rc_target'] ?? 10);
        $msg     = trim($_POST['rc_message'] ?? '');
        $start   = $_POST['rc_start'] ?? null;
        $end     = $_POST['rc_end'] ?? null;
        if ($name) {
            $pdo->prepare("INSERT INTO review_campaigns (partner_id, listing_id, name, target_reviews, message_template, start_date, end_date, status) VALUES (?,?,?,?,?,?,?,'active')")
                ->execute([$partnerId, $listingId, $name, $target, $msg ?: null, $start ?: null, $end ?: null]);
            logBusinessActivity($listingId, $partnerId, $userId, 'review_campaign_created', "Review campaign: {$name}", 'review_campaign');
        }
    }

    // OPPORTUNITY
    if ($action === 'add_opportunity') {
        $service = trim($_POST['opp_service'] ?? '');
        $title   = trim($_POST['opp_title'] ?? '');
        $desc    = trim($_POST['opp_desc'] ?? '');
        if ($service && $title) {
            $pdo->prepare("INSERT INTO growth_opportunities (partner_id, listing_id, service_type, title, description, status) VALUES (?,?,?,?,?,'identified')")
                ->execute([$partnerId, $listingId, $service, $title, $desc ?: null]);
            partnerAuditLog($partnerId, $userId, $listingId, 'opportunity_created', "Opportunity: {$title}");
        }
    }

    // COMPLETE TASK
    if ($action === 'complete_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $pdo->prepare("UPDATE growth_tasks SET status='completed', completed_at=NOW() WHERE id=? AND partner_id=? AND listing_id=?")
            ->execute([$taskId, $partnerId, $listingId]);
        partnerAuditLog($partnerId, $userId, $listingId, 'task_completed', "Task #{$taskId}");
    }

    // AI CONTENT GENERATE
    if ($action === 'ai_generate_content') {
        $contentType = $_POST['ai_content_type'] ?? 'social_post';
        $platform    = trim($_POST['ai_platform'] ?? '');
        $style       = trim($_POST['ai_style'] ?? 'professional');
        $userNotes   = trim($_POST['ai_notes'] ?? '');
        $validTypes  = ['social_post','promotional_post','review_request','customer_followup','event_promotion','whatsapp_message','instagram_caption','tiktok_concept'];
        $validStyles = ['professional','friendly','short','promotional','tiktok','whatsapp'];
        if (in_array($contentType, $validTypes) && in_array($style, $validStyles)) {
            // Gather business context (verified data only — no fabrication)
            $ctx = [];
            $ctx['business']  = $listing['title'] ?? '';
            $ctx['category']  = $listing['cat_en'] ?? '';
            $ctx['city']      = $listing['city'] ?? '';
            $ctx['tagline']   = $listing['tagline'] ?? '';
            $ctx['services']  = $listing['services'] ?? '';
            $ctx['avg_rating'] = $listing['avg_rating'] ? round($listing['avg_rating'], 1) : null;
            $ctx['review_count'] = (int)($listing['review_count'] ?? 0);
            // Recent campaign for context
            $ctxCamp = $pdo->prepare("SELECT name, offer, call_to_action FROM partner_campaigns WHERE listing_id=? AND partner_id=? AND status='active' ORDER BY created_at DESC LIMIT 1");
            $ctxCamp->execute([$listingId, $partnerId]);
            $ctx['active_campaign'] = $ctxCamp->fetch(PDO::FETCH_ASSOC) ?: null;
            // Generate content using rule-based template engine
            $generated = generateAIContent($contentType, $style, $ctx, $userNotes);
            // Store in ai_generated_content
            $insCtx = json_encode(['style'=>$style,'platform'=>$platform,'notes'=>$userNotes,'context'=>$ctx]);
            $pdo->prepare("INSERT INTO ai_generated_content
                (partner_id, listing_id, user_id, content_type, platform, style, prompt_summary, generated_text, approval_status)
                VALUES (?,?,?,?,?,?,?,?,'pending')")
                ->execute([$partnerId, $listingId, $userId, $contentType, $platform ?: null, $style,
                           substr("$style $contentType for {$ctx['business']}", 0, 499),
                           $generated]);
            $newContentId = (int)$pdo->lastInsertId();
            // Audit log
            $pdo->prepare("INSERT INTO ai_audit_log
                (partner_id, listing_id, user_id, action_type, ref_type, ref_id, prompt_summary, generated_output, approval_status)
                VALUES (?,?,?,'content_generated','ai_generated_content',?,?,?,'pending')")
                ->execute([$partnerId, $listingId, $userId, $newContentId,
                           "Generate $style $contentType", $generated]);
            setFlash('ai_content', $generated . '|||' . $newContentId);
        }
    }

    // AI CONTENT APPROVE → save to content_items
    if ($action === 'ai_approve_content') {
        $genId  = (int)($_POST['gen_id'] ?? 0);
        $edited = trim($_POST['edited_text'] ?? '');
        if ($genId && $edited) {
            $chk = $pdo->prepare("SELECT * FROM ai_generated_content WHERE id=? AND partner_id=? AND listing_id=?");
            $chk->execute([$genId, $partnerId, $listingId]);
            $genRow = $chk->fetch();
            if ($genRow) {
                // Mark approved
                $pdo->prepare("UPDATE ai_generated_content SET edited_text=?, approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                    ->execute([$edited, $userId, $genId]);
                // Save as content item (draft)
                $pdo->prepare("INSERT INTO content_items (partner_id, listing_id, content_type, title, body, platform, status) VALUES (?,?,?,?,?,?,'draft')")
                    ->execute([$partnerId, $listingId, $genRow['content_type'], 'AI: '.$genRow['style'].' '.$genRow['content_type'], $edited, $genRow['platform']]);
                // Update audit log
                $pdo->prepare("UPDATE ai_audit_log SET approval_status='approved', approved_by=?, approved_at=NOW(), user_edits=?, final_version=? WHERE ref_type='ai_generated_content' AND ref_id=?")
                    ->execute([$userId, $edited !== $genRow['generated_text'] ? $edited : null, $edited, $genId]);
                logBusinessActivity($listingId, $partnerId, $userId, 'ai_content_approved', 'AI content approved and saved as draft', 'content');
                setFlash('success', 'Content approved and saved as a draft in the Content tab.');
            }
        }
    }

    // AI CONTENT REJECT
    if ($action === 'ai_reject_content') {
        $genId = (int)($_POST['gen_id'] ?? 0);
        if ($genId) {
            $pdo->prepare("UPDATE ai_generated_content SET approval_status='rejected', approved_by=?, approved_at=NOW() WHERE id=? AND partner_id=? AND listing_id=?")
                ->execute([$userId, $genId, $partnerId, $listingId]);
        }
    }

    header('Location: ?id=' . $listingId . '&tab=' . $tab);
    exit;
}

// ── Data for current tab ──────────────────────────────────────
$healthData  = calcHealthScore($listing);
$healthScore = $healthData['score'];
$completion  = profileCompletion($listing);
$actions     = getRecommendedActionsP2($listing, $partnerId);
$campStats   = getCampaignStats($listingId, $partnerId);

// Growth Plan
$planSt = $pdo->prepare("SELECT * FROM growth_plans WHERE listing_id=? AND partner_id=? ORDER BY status='active' DESC, created_at DESC LIMIT 1");
$planSt->execute([$listingId, $partnerId]);
$plan = $planSt->fetch();
$objectives = [];
if ($plan) {
    $objSt = $pdo->prepare("SELECT * FROM growth_plan_objectives WHERE plan_id=? ORDER BY sort_order,id");
    $objSt->execute([$plan['id']]);
    $objectives = $objSt->fetchAll();
}

// Leads summary
$leadSumSt = $pdo->prepare("
    SELECT status, COUNT(*) AS n FROM partner_leads
    WHERE listing_id=? AND partner_id=? GROUP BY status
");
$leadSumSt->execute([$listingId, $partnerId]);
$leadSummary = $leadSumSt->fetchAll(PDO::FETCH_KEY_PAIR);

// Tasks summary
$taskSumSt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status='completed') AS completed,
        SUM(status NOT IN ('completed','cancelled') AND (due_date IS NULL OR due_date >= CURDATE())) AS open,
        SUM(status NOT IN ('completed','cancelled') AND due_date < CURDATE()) AS overdue
    FROM growth_tasks WHERE listing_id=? AND partner_id=?
");
$taskSumSt->execute([$listingId, $partnerId]);
$taskSummary = $taskSumSt->fetch();

// Tab-specific data
$leads = $tasks = $campaigns = $contents = $reviewCampaigns = $opportunities = $activityFeed = $aiHistory = [];
switch ($tab) {
    case 'leads':
        $st = $pdo->prepare("SELECT pl.*, la.activity_type AS last_act FROM partner_leads pl LEFT JOIN lead_activities la ON la.id=(SELECT id FROM lead_activities WHERE lead_id=pl.id ORDER BY created_at DESC LIMIT 1) WHERE pl.listing_id=? AND pl.partner_id=? ORDER BY FIELD(pl.status,'new','follow_up','contacted','qualified','converted','lost','closed'), pl.last_activity DESC");
        $st->execute([$listingId, $partnerId]);
        $leads = $st->fetchAll();
        break;
    case 'tasks':
        $st = $pdo->prepare("SELECT * FROM growth_tasks WHERE listing_id=? AND partner_id=? ORDER BY FIELD(status,'in_progress','todo','completed','cancelled'), priority DESC, due_date ASC");
        $st->execute([$listingId, $partnerId]);
        $tasks = $st->fetchAll();
        break;
    case 'campaigns':
        $st = $pdo->prepare("SELECT * FROM partner_campaigns WHERE listing_id=? AND partner_id=? ORDER BY created_at DESC");
        $st->execute([$listingId, $partnerId]);
        $campaigns = $st->fetchAll();
        break;
    case 'content':
        $st = $pdo->prepare("SELECT ci.*, c.name AS campaign_name FROM content_items ci LEFT JOIN campaigns c ON c.id=ci.campaign_id WHERE ci.listing_id=? AND ci.partner_id=? ORDER BY ci.scheduled_date DESC, ci.created_at DESC");
        $st->execute([$listingId, $partnerId]);
        $contents = $st->fetchAll();
        break;
    case 'reviews':
        $st = $pdo->prepare("SELECT * FROM review_campaigns WHERE listing_id=? AND partner_id=? ORDER BY created_at DESC");
        $st->execute([$listingId, $partnerId]);
        $reviewCampaigns = $st->fetchAll();
        break;
    case 'opportunities':
        $st = $pdo->prepare("SELECT * FROM growth_opportunities WHERE listing_id=? AND partner_id=? ORDER BY created_at DESC");
        $st->execute([$listingId, $partnerId]);
        $opportunities = $st->fetchAll();
        break;
    case 'activity':
        $activityFeed = getBusinessActivity($listingId, 50);
        break;
    case 'reports':
        $st = $pdo->prepare("SELECT * FROM growth_reports WHERE listing_id=? AND partner_id=? ORDER BY created_at DESC");
        $st->execute([$listingId, $partnerId]);
        $reports = $st->fetchAll();
        break;
    case 'ai':
        $st = $pdo->prepare("SELECT * FROM ai_generated_content WHERE listing_id=? AND partner_id=? ORDER BY created_at DESC LIMIT 30");
        $st->execute([$listingId, $partnerId]);
        $aiHistory = $st->fetchAll();
        break;
}

// Tab-specific campaigns list for content form
$allCampaigns = $pdo->prepare("SELECT id, name FROM partner_campaigns WHERE listing_id=? AND partner_id=? AND status IN ('active','scheduled','draft') ORDER BY name");
$allCampaigns->execute([$listingId, $partnerId]);
$allCampaigns = $allCampaigns->fetchAll();

$pageTitle = e($listing['title']) . ' — Growth Workspace';
require_once __DIR__ . '/../includes/header.php';

// ─── Helpers ──────────────────────────────────────────────────
function healthColor(int $s): string {
    if ($s >= 80) return '#00A878';
    if ($s >= 60) return '#fcd116';
    if ($s >= 40) return '#ff9f1c';
    return '#e63946';
}
function tabUrl(string $t, int $lid): string {
    return SITE_URL . '/partner/business?id=' . $lid . '&tab=' . $t;
}
?>

<style>
:root { --ws-max:1300px; }
.ws-wrap  { max-width:var(--ws-max); margin:0 auto; padding:2rem 1.5rem; }

/* Business header */
.biz-header { background:var(--card); border:1px solid var(--border); border-radius:14px;
  padding:1.5rem; margin-bottom:1.5rem; display:flex; gap:1.25rem; align-items:flex-start; flex-wrap:wrap; }
.biz-icon  { font-size:2.5rem; line-height:1; }
.biz-meta  { flex:1; min-width:0; }
.biz-meta h1 { font-family:'Fraunces',serif; font-size:1.6rem; font-weight:900; margin:0 0 .3rem; }
.biz-meta .sub { font-size:.85rem; color:var(--muted); display:flex; gap:.75rem; flex-wrap:wrap; }
.health-pill { display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .85rem;
  border-radius:20px; font-size:.8rem; font-weight:700; }

/* Tab nav */
.ws-nav { display:flex; gap:.35rem; flex-wrap:wrap; margin-bottom:1.5rem;
  border-bottom:2px solid var(--border); padding-bottom:.75rem; }
.ws-tab { padding:.4rem .9rem; border-radius:8px 8px 0 0; font-size:.84rem; font-weight:600;
  text-decoration:none; color:var(--muted); transition:all .15s; }
.ws-tab:hover { color:var(--text); background:var(--card); }
.ws-tab.active { color:var(--primary); background:var(--card);
  border:1px solid var(--border); border-bottom:2px solid var(--card); margin-bottom:-2px; }

/* Grid layout */
.ws-grid { display:grid; grid-template-columns:1fr 320px; gap:1.25rem; align-items:start; }
@media(max-width:860px){ .ws-grid { grid-template-columns:1fr; } }

/* Cards */
.ws-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:1.25rem; margin-bottom:1rem; }
.ws-card h3 { font-size:.9rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em;
  color:var(--muted); margin:0 0 .85rem; }

/* Stat grid */
.stat-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:.75rem; margin-bottom:1.25rem; }
.stat-box { background:var(--card); border:1px solid var(--border); border-radius:10px;
  padding:.75rem 1rem; text-align:center; }
.stat-box .n { font-size:1.5rem; font-weight:800; font-family:'Fraunces',serif; }
.stat-box .l { font-size:.72rem; color:var(--muted); margin-top:.15rem; }

/* Priority actions */
.action-item { display:flex; align-items:flex-start; gap:.6rem; padding:.6rem 0;
  border-bottom:1px solid var(--border); font-size:.875rem; }
.action-item:last-child { border-bottom:none; }

/* Lead/task/campaign cards */
.item-card { background:var(--card); border:1px solid var(--border); border-radius:10px;
  padding:1rem 1.1rem; margin-bottom:.6rem; }
.badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.72rem; font-weight:700; }
.badge-high    { background:rgba(230,57,70,.13); color:#e63946; }
.badge-medium  { background:rgba(252,209,22,.2); color:#b8960f; }
.badge-low     { background:rgba(150,150,150,.15); color:var(--muted); }
.badge-active  { background:rgba(0,168,120,.15); color:#00A878; }
.badge-draft   { background:rgba(150,150,150,.12); color:var(--muted); }
.badge-completed { background:rgba(46,196,182,.15); color:#1a8f88; }
.badge-new     { background:rgba(252,209,22,.2); color:#b8960f; }
.badge-converted{ background:rgba(0,168,120,.25); color:#006e50; }

/* Health bar */
.health-bar { height:8px; border-radius:4px; background:var(--border); overflow:hidden; }
.health-fill { height:100%; border-radius:4px; transition:width .3s; }

/* Form row */
.form-row { display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-end; }
.form-row input, .form-row select, .form-row textarea {
  padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px;
  background:var(--bg); color:var(--text); font-size:.83rem; font-family:inherit; }
.form-row label { font-size:.78rem; color:var(--muted); display:block; margin-bottom:.2rem; }

/* Progress ring (plan) */
.plan-progress { display:flex; gap:.75rem; flex-wrap:wrap; }
.plan-obj { flex:1; min-width:130px; background:var(--bg); border:1px solid var(--border);
  border-radius:10px; padding:.75rem; }
.plan-obj .obj-n { font-size:1.2rem; font-weight:800; font-family:'Fraunces',serif; }
.plan-obj .obj-l { font-size:.72rem; color:var(--muted); }

/* Activity feed */
.activity-item { display:flex; gap:.75rem; padding:.6rem 0; border-bottom:1px solid var(--border); font-size:.85rem; }
.activity-item:last-child { border-bottom:none; }
.activity-dot { width:8px; height:8px; border-radius:50%; background:var(--primary); flex-shrink:0; margin-top:.35rem; }
.activity-date { font-size:.72rem; color:var(--muted); white-space:nowrap; }

/* Opportunity status */
.opp-identified { background:rgba(252,209,22,.2); color:#b8960f; }
.opp-discussing  { background:rgba(0,120,255,.12); color:#0078ff; }
.opp-proposal    { background:rgba(255,159,28,.2); color:#d4780f; }
.opp-won         { background:rgba(0,168,120,.2); color:#00A878; }
.opp-lost        { background:rgba(230,57,70,.12); color:#e63946; }
</style>

<div class="ws-wrap">

  <!-- Business Header -->
  <div class="biz-header">
    <div class="biz-icon"><?= e($listing['cat_icon'] ?? '🏢') ?></div>
    <div class="biz-meta">
      <h1><?= e($listing['title']) ?></h1>
      <div class="sub">
        <span><?= e($listing['cat_en']) ?></span>
        <span>📍 <?= e($listing['city']) ?></span>
        <?php if ($listing['owner_name']): ?>
        <span>👤 <?= e($listing['owner_name']) ?></span>
        <?php endif; ?>
        <span>Profile <?= $completion ?>% complete</span>
      </div>
    </div>
    <div style="text-align:right; flex-shrink:0;">
      <div class="health-pill" style="background:<?= healthColor($healthScore) ?>22; color:<?= healthColor($healthScore) ?>;">
        ❤️ <?= $healthScore ?>% Health
      </div>
      <div style="font-size:.75rem; color:var(--muted); margin-top:.3rem;">
        <?= $listing['status'] ?? 'Active' ?>
      </div>
      <a href="<?= SITE_URL ?>/partner/portfolio" style="font-size:.78rem; color:var(--muted); text-decoration:none; display:block; margin-top:.4rem;">← Portfolio</a>
    </div>
  </div>

  <!-- Tab navigation -->
  <nav class="ws-nav">
    <?php
    $tabs = [
        'overview'    => '📊 Overview',
        'growth_plan' => '🎯 Growth Plan',
        'tasks'       => '✅ Tasks' . ($taskSummary['overdue'] > 0 ? ' <span style="color:#e63946">('.(int)$taskSummary['overdue'].')</span>' : ''),
        'leads'       => '💬 Leads' . (($leadSummary['new'] ?? 0) > 0 ? ' <span style="color:#fcd116">('.($leadSummary['new']).')</span>' : ''),
        'campaigns'   => '📣 Campaigns',
        'content'     => '📝 Content',
        'reviews'     => '⭐ Reviews',
        'activity'    => '📅 Activity',
        'opportunities'=> '💡 Opportunities',
        'reports'     => '📈 Reports',
        'ai'          => '✦ AI Assistant',
    ];
    foreach ($tabs as $tk => $tl): ?>
    <a href="<?= tabUrl($tk, $listingId) ?>" class="ws-tab <?= $tab===$tk?'active':'' ?>"><?= $tl ?></a>
    <?php endforeach; ?>
  </nav>

  <?php // ═══ OVERVIEW ═══════════════════════════════════════════
  if ($tab === 'overview'): ?>
  <div class="ws-grid">
    <div>
      <!-- Performance summary -->
      <div class="ws-card">
        <h3>Performance</h3>
        <div class="stat-row">
          <?php
          $perfs = [
              ['Profile views',     $listing['views'] ?? null],
              ['Phone clicks',      $listing['phone_clicks'] ?? null],
              ['Website clicks',    $listing['website_clicks'] ?? null],
              ['WhatsApp clicks',   $listing['whatsapp_clicks'] ?? null],
              ['Enquiries/Leads',   array_sum($leadSummary)],
              ['Reviews',           (int)$listing['review_count']],
              ['Active Campaigns',  $campStats['active']],
          ];
          foreach ($perfs as [$label, $val]): ?>
          <div class="stat-box">
            <div class="n" <?= $val === null ? 'style="font-size:1rem;color:var(--muted)"' : '' ?>>
              <?= $val === null ? 'N/A' : number_format((int)$val) ?>
            </div>
            <div class="l"><?= $label ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Growth Plan progress -->
      <?php if ($plan): ?>
      <div class="ws-card">
        <h3><?= e(strtoupper($plan['name'])) ?></h3>
        <?php if ($objectives): ?>
        <div class="plan-progress">
          <?php
          $totalTarget = $totalCurrent = 0;
          foreach ($objectives as $obj):
            $pct = $obj['target'] > 0 ? min(100, round($obj['current']/$obj['target']*100)) : 0;
            $totalTarget  += $obj['target'];
            $totalCurrent += $obj['current'];
          ?>
          <div class="plan-obj">
            <div class="obj-n" style="color:<?= $pct>=100?'#00A878':'var(--text)' ?>">
              <?= $obj['current'] ?><small style="font-size:.6em;color:var(--muted)"> / <?= $obj['target'] ?></small>
            </div>
            <div class="obj-l"><?= e($obj['title']) ?></div>
            <div class="health-bar" style="margin-top:.4rem;">
              <div class="health-fill" style="width:<?= $pct ?>%; background:<?= healthColor($pct) ?>;"></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if ($totalTarget > 0):
            $overallPct = min(100, round($totalCurrent/$totalTarget*100));
          ?>
          <div class="plan-obj" style="border-color:var(--primary);">
            <div class="obj-n" style="color:var(--primary);"><?= $overallPct ?>%</div>
            <div class="obj-l">Overall Progress</div>
            <div class="health-bar" style="margin-top:.4rem;">
              <div class="health-fill" style="width:<?= $overallPct ?>%; background:var(--primary);"></div>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <p style="color:var(--muted); font-size:.85rem;">No objectives set. <a href="<?= tabUrl('growth_plan',$listingId) ?>">Add objectives →</a></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right column: Priority Actions + Health -->
    <div>
      <div class="ws-card">
        <h3>Priority Actions</h3>
        <?php foreach ($actions as $a): ?>
        <div class="action-item">
          <span><?= $a['icon'] ?></span>
          <span>
            <?= e($a['text']) ?>
            <?php if (!empty($a['link'])): ?>
            <a href="<?= tabUrl(ltrim(parse_url($a['link'],PHP_URL_QUERY),'?tab='), $listingId) ?>" style="font-size:.75rem; color:var(--primary); text-decoration:none; margin-left:.3rem;">→</a>
            <?php endif; ?>
          </span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($actions)): ?>
        <p style="color:#00A878; font-size:.85rem;">🟢 No urgent actions — business is on track.</p>
        <?php endif; ?>
      </div>

      <!-- Health Score breakdown -->
      <div class="ws-card">
        <h3>Health Score — <?= $healthScore ?>%</h3>
        <?php foreach ($healthData['components'] as $name => $comp): ?>
        <div style="margin-bottom:.75rem;">
          <div style="display:flex; justify-content:space-between; font-size:.8rem; margin-bottom:.3rem;">
            <span><?= $name ?></span>
            <span style="font-weight:700;"><?= $comp['score'] ?> / <?= $comp['max'] ?></span>
          </div>
          <div class="health-bar">
            <div class="health-fill" style="width:<?= round($comp['score']/$comp['max']*100) ?>%; background:<?= healthColor(round($comp['score']/$comp['max']*100)) ?>;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Task/Lead quick stats -->
      <div class="ws-card">
        <h3>Quick Stats</h3>
        <div style="font-size:.85rem; display:flex; flex-direction:column; gap:.4rem;">
          <div style="display:flex; justify-content:space-between;"><span>Open tasks</span><strong><?= (int)$taskSummary['open'] ?></strong></div>
          <?php if ($taskSummary['overdue'] > 0): ?>
          <div style="display:flex; justify-content:space-between; color:#e63946;"><span>Overdue tasks</span><strong><?= (int)$taskSummary['overdue'] ?></strong></div>
          <?php endif; ?>
          <div style="display:flex; justify-content:space-between;"><span>New leads</span><strong><?= (int)($leadSummary['new'] ?? 0) ?></strong></div>
          <div style="display:flex; justify-content:space-between;"><span>Active campaigns</span><strong><?= $campStats['active'] ?></strong></div>
          <div style="display:flex; justify-content:space-between;"><span>Avg rating</span><strong><?= $listing['avg_rating'] ? number_format((float)$listing['avg_rating'],1).'★' : 'N/A' ?></strong></div>
        </div>
      </div>
    </div>
  </div>

  <?php // ═══ GROWTH PLAN ════════════════════════════════════════
  elseif ($tab === 'growth_plan'): ?>

  <div class="ws-grid">
    <div>
      <?php if ($plan): ?>
      <div class="ws-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
          <div>
            <div style="font-size:1.1rem; font-weight:800; font-family:'Fraunces',serif;"><?= e($plan['name']) ?></div>
            <div style="font-size:.78rem; color:var(--muted);">
              <?= $plan['start_date'] ? date('j M Y', strtotime($plan['start_date'])) : '' ?>
              <?= $plan['end_date'] ? ' – ' . date('j M Y', strtotime($plan['end_date'])) : '' ?>
            </div>
          </div>
          <span class="badge badge-<?= $plan['status'] ?>"><?= $plan['status'] ?></span>
        </div>
        <?php if ($plan['notes']): ?><p style="font-size:.85rem; color:var(--muted);"><?= e($plan['notes']) ?></p><?php endif; ?>

        <!-- Objectives -->
        <?php foreach ($objectives as $obj):
          $pct = $obj['target'] > 0 ? min(100, round($obj['current']/$obj['target']*100)) : 0;
        ?>
        <div style="border:1px solid var(--border); border-radius:9px; padding:.85rem; margin-bottom:.6rem;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:.9rem;"><?= e($obj['title']) ?></strong>
            <span style="font-size:.85rem; font-family:'Fraunces',serif; font-weight:800;">
              <?= $obj['current'] ?> <span style="color:var(--muted); font-size:.75rem;">/ <?= $obj['target'] ?></span>
            </span>
          </div>
          <div class="health-bar" style="margin-top:.5rem;">
            <div class="health-fill" style="width:<?= $pct ?>%; background:<?= healthColor($pct) ?>;"></div>
          </div>
          <div style="font-size:.72rem; color:var(--muted); margin-top:.3rem;"><?= $pct ?>% complete</div>
        </div>
        <?php endforeach; ?>

        <!-- Add objective -->
        <details style="margin-top:.75rem;">
          <summary style="font-size:.82rem; color:var(--primary); cursor:pointer;">+ Add Objective</summary>
          <form method="POST" style="margin-top:.6rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_objective">
            <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
            <div class="form-row">
              <div><label>Objective</label><input type="text" name="obj_title" required placeholder="e.g. New Reviews" style="width:180px;"></div>
              <div><label>Target</label><input type="number" name="target" min="1" value="10" style="width:80px;"></div>
              <div><label>Type</label>
                <select name="obj_type">
                  <option value="reviews">Reviews</option>
                  <option value="leads">Leads</option>
                  <option value="campaigns">Campaigns</option>
                  <option value="tasks">Tasks</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div style="padding-top:1.2rem;"><button class="btn btn-primary" style="font-size:.82rem;">Add</button></div>
            </div>
          </form>
        </details>
      </div>
      <?php else: ?>
      <div class="ws-card" style="text-align:center; padding:3rem;">
        <div style="font-size:3rem;">🎯</div>
        <h3 style="font-family:'Fraunces',serif; font-weight:900;">No Active Growth Plan</h3>
        <p style="color:var(--muted);">Create a growth plan to define this month's objectives.</p>
      </div>
      <?php endif; ?>
    </div>
    <div>
      <!-- Create plan form -->
      <div class="ws-card">
        <h3><?= $plan ? 'New Plan' : 'Create Growth Plan' ?></h3>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="create_plan">
          <div style="display:flex; flex-direction:column; gap:.6rem;">
            <div><label style="font-size:.78rem; color:var(--muted);">Plan Name</label>
              <input type="text" name="plan_name" required placeholder="e.g. October Growth Plan" style="width:100%; padding:.4rem .6rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;"></div>
            <div class="form-row">
              <div><label>Start</label><input type="date" name="start_date" value="<?= date('Y-m-01') ?>"></div>
              <div><label>End</label><input type="date" name="end_date" value="<?= date('Y-m-t') ?>"></div>
            </div>
            <div><label style="font-size:.78rem; color:var(--muted);">Notes</label>
              <textarea name="plan_notes" rows="3" placeholder="Plan overview…" style="width:100%; padding:.4rem .6rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem; resize:vertical;"></textarea></div>
            <button class="btn btn-primary" style="font-size:.85rem;">Create Plan</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php // ═══ TASKS ══════════════════════════════════════════════
  elseif ($tab === 'tasks'): ?>

  <div class="ws-grid">
    <div>
      <?php if ($tasks): foreach ($tasks as $task):
        $isOverdue = $task['due_date'] && $task['due_date'] < date('Y-m-d') && !in_array($task['status'],['completed','cancelled']);
      ?>
      <div class="item-card" style="<?= $isOverdue ? 'border-color:#e63946;' : '' ?>">
        <div style="display:flex; justify-content:space-between; gap:.5rem; flex-wrap:wrap;">
          <div>
            <strong style="font-size:.9rem; <?= $task['status']==='completed'?'text-decoration:line-through;color:var(--muted)':'' ?>"><?= e($task['title']) ?></strong>
            <span class="badge badge-<?= $task['priority'] ?>" style="margin-left:.4rem;"><?= $task['priority'] ?></span>
            <?php if ($isOverdue): ?><span class="badge" style="background:rgba(230,57,70,.13);color:#e63946; margin-left:.3rem;">Overdue</span><?php endif; ?>
          </div>
          <?php if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled'): ?>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="complete_task">
            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
            <button class="btn btn-primary" style="font-size:.75rem; padding:.25rem .6rem;">✓ Done</button>
          </form>
          <?php endif; ?>
        </div>
        <div style="font-size:.78rem; color:var(--muted); margin-top:.3rem; display:flex; gap:.75rem; flex-wrap:wrap;">
          <?php if ($task['due_date']): ?><span>📅 <?= date('j M Y',strtotime($task['due_date'])) ?></span><?php endif; ?>
          <span class="badge badge-<?= str_replace('_','',$task['status']) ?>" style="font-size:.7rem;"><?= $task['status'] ?></span>
        </div>
        <?php if ($task['notes']): ?><div style="font-size:.8rem; color:var(--muted); margin-top:.3rem;"><?= e($task['notes']) ?></div><?php endif; ?>
      </div>
      <?php endforeach; else: ?>
      <div class="ws-card" style="text-align:center; padding:2.5rem; color:var(--muted);">✅ No tasks yet.</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ws-card">
        <h3>Add Task</h3>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_task">
          <div style="display:flex; flex-direction:column; gap:.55rem;">
            <input type="text" name="title" required placeholder="Task title…" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <select name="priority" style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
              <option value="low">Low Priority</option>
              <option value="medium" selected>Medium Priority</option>
              <option value="high">High Priority</option>
              <option value="urgent">Urgent</option>
            </select>
            <input type="date" name="due_date" style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
            <textarea name="notes" rows="2" placeholder="Notes…" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem; resize:vertical;"></textarea>
            <button class="btn btn-primary" style="font-size:.85rem;">Add Task</button>
          </div>
        </form>
      </div>
      <div class="ws-card">
        <h3>Summary</h3>
        <div style="font-size:.85rem; display:flex; flex-direction:column; gap:.35rem;">
          <div style="display:flex; justify-content:space-between;"><span>Open</span><strong><?= (int)$taskSummary['open'] ?></strong></div>
          <div style="display:flex; justify-content:space-between; color:#e63946;"><span>Overdue</span><strong><?= (int)$taskSummary['overdue'] ?></strong></div>
          <div style="display:flex; justify-content:space-between; color:#00A878;"><span>Completed</span><strong><?= (int)$taskSummary['completed'] ?></strong></div>
        </div>
      </div>
    </div>
  </div>

  <?php // ═══ LEADS ══════════════════════════════════════════════
  elseif ($tab === 'leads'): ?>

  <!-- Pipeline summary -->
  <div class="stat-row" style="margin-bottom:1.25rem;">
    <?php
    $lStages = ['new'=>'New','contacted'=>'Contacted','follow_up'=>'Follow-up','qualified'=>'Qualified','converted'=>'Converted','lost'=>'Lost'];
    foreach ($lStages as $sk => $sl): ?>
    <div class="stat-box">
      <div class="n"><?= (int)($leadSummary[$sk] ?? 0) ?></div>
      <div class="l"><?= $sl ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="ws-grid">
    <div>
      <?php if ($leads): foreach ($leads as $lead):
        $isOverdue = $lead['follow_up_date'] && $lead['follow_up_date'] < date('Y-m-d') && !in_array($lead['status'],['converted','lost','closed']);
      ?>
      <div class="item-card" style="<?= $isOverdue?'border-color:#e63946;':'' ?>">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:.5rem;">
          <div>
            <strong><?= e($lead['customer_name'] ?: 'Unknown') ?></strong>
            <span class="badge badge-<?= $lead['status'] ?>" style="margin-left:.4rem;"><?= str_replace('_',' ',$lead['status']) ?></span>
            <?php if ($isOverdue): ?><span class="badge" style="background:rgba(230,57,70,.13);color:#e63946; margin-left:.3rem;">Follow-up overdue</span><?php endif; ?>
          </div>
          <span style="font-size:.75rem; color:var(--muted);"><?= date('j M', strtotime($lead['created_at'])) ?></span>
        </div>
        <div style="font-size:.78rem; color:var(--muted); margin-top:.3rem; display:flex; gap:.75rem; flex-wrap:wrap;">
          <?php if ($lead['customer_email']): ?><span>✉ <?= e($lead['customer_email']) ?></span><?php endif; ?>
          <?php if ($lead['customer_phone']): ?><span>📞 <?= e($lead['customer_phone']) ?></span><?php endif; ?>
          <?php if ($lead['follow_up_date']): ?><span>📅 Follow-up: <?= date('j M Y',strtotime($lead['follow_up_date'])) ?></span><?php endif; ?>
        </div>
        <?php if ($lead['next_action']): ?><div style="font-size:.8rem; margin-top:.3rem;">→ <strong>Next:</strong> <?= e($lead['next_action']) ?></div><?php endif; ?>
        <?php if ($lead['notes']): ?><div style="font-size:.78rem; color:var(--muted); margin-top:.2rem;"><?= e(mb_strimwidth($lead['notes'],0,120,'…')) ?></div><?php endif; ?>

        <!-- Log activity -->
        <?php if (!in_array($lead['status'],['converted','lost','closed'])): ?>
        <details style="margin-top:.65rem;">
          <summary style="font-size:.78rem; color:var(--primary); cursor:pointer;">Log activity</summary>
          <form method="POST" style="margin-top:.5rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_lead_activity">
            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
            <div class="form-row">
              <div><label>Activity</label>
                <select name="activity_type" style="padding:.35rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text); font-size:.8rem;">
                  <option value="note">Note</option>
                  <option value="phone_call">Phone call</option>
                  <option value="email_sent">Email sent</option>
                  <option value="message_sent">Message sent</option>
                  <option value="follow_up">Follow-up</option>
                  <option value="appointment">Appointment</option>
                  <option value="converted">Converted</option>
                  <option value="lost">Lost</option>
                </select>
              </div>
              <div><label>Follow-up date</label><input type="date" name="follow_up_date" style="padding:.35rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text); font-size:.8rem;"></div>
            </div>
            <textarea name="act_notes" rows="2" placeholder="Notes…" style="margin-top:.4rem; width:100%; padding:.35rem .55rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text); font-size:.8rem; resize:vertical;"></textarea>
            <button class="btn btn-primary" style="font-size:.78rem; padding:.3rem .7rem; margin-top:.35rem;">Save</button>
          </form>
        </details>
        <?php endif; ?>
      </div>
      <?php endforeach; else: ?>
      <div class="ws-card" style="text-align:center; padding:2.5rem; color:var(--muted);">💬 No leads yet.</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ws-card">
        <h3>Add Lead</h3>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_lead">
          <div style="display:flex; flex-direction:column; gap:.55rem;">
            <input type="text" name="customer_name" required placeholder="Customer name" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <input type="email" name="customer_email" placeholder="Email (optional)" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <input type="tel" name="customer_phone" placeholder="Phone (optional)" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <select name="source" style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
              <option value="direct">Direct</option>
              <option value="referral">Referral</option>
              <option value="social">Social media</option>
              <option value="campaign">Campaign</option>
              <option value="walk_in">Walk-in</option>
              <option value="other">Other</option>
            </select>
            <textarea name="notes" rows="2" placeholder="Notes…" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem; resize:vertical;"></textarea>
            <button class="btn btn-primary" style="font-size:.85rem;">Add Lead</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php // ═══ CAMPAIGNS ══════════════════════════════════════════
  elseif ($tab === 'campaigns'): ?>

  <div class="ws-grid">
    <div>
      <?php if ($campaigns): foreach ($campaigns as $camp): ?>
      <div class="item-card">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:.5rem;">
          <div>
            <strong style="font-size:.95rem;"><?= e($camp['name']) ?></strong>
            <span class="badge badge-<?= $camp['status'] ?>" style="margin-left:.4rem;"><?= $camp['status'] ?></span>
            <span style="font-size:.75rem; color:var(--muted); margin-left:.4rem;"><?= ucwords(str_replace('_',' ',$camp['campaign_type'])) ?></span>
          </div>
          <span style="font-size:.75rem; color:var(--muted);">
            <?= $camp['start_date'] ? date('j M',strtotime($camp['start_date'])) : '' ?>
            <?= $camp['end_date'] ? ' – '.date('j M Y',strtotime($camp['end_date'])) : '' ?>
          </span>
        </div>
        <?php if ($camp['objective']): ?><div style="font-size:.82rem; color:var(--muted); margin-top:.35rem;"><?= e($camp['objective']) ?></div><?php endif; ?>
        <?php if ($camp['call_to_action']): ?><div style="margin-top:.3rem; font-size:.82rem;">CTA: <strong><?= e($camp['call_to_action']) ?></strong></div><?php endif; ?>
        <?php if ($camp['offer']): ?><div style="font-size:.82rem; color:var(--muted);">Offer: <?= e($camp['offer']) ?></div><?php endif; ?>
      </div>
      <?php endforeach; else: ?>
      <div class="ws-card" style="text-align:center; padding:2.5rem; color:var(--muted);">📣 No campaigns yet.</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ws-card">
        <h3>Create Campaign</h3>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="create_campaign">
          <div style="display:flex; flex-direction:column; gap:.55rem;">
            <input type="text" name="camp_name" required placeholder="Campaign name" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <select name="camp_type" style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
              <?php foreach (['business_promotion'=>'Business Promotion','product_promotion'=>'Product Promotion','service_promotion'=>'Service Promotion','special_offer'=>'Special Offer','event'=>'Event','review_campaign'=>'Review Campaign','social_media_campaign'=>'Social Media Campaign','seasonal_campaign'=>'Seasonal Campaign'] as $v=>$l): ?>
              <option value="<?= $v ?>"><?= $l ?></option>
              <?php endforeach; ?>
            </select>
            <textarea name="camp_objective" rows="2" placeholder="Objective…" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem; resize:vertical;"></textarea>
            <textarea name="camp_desc" rows="2" placeholder="Description…" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem; resize:vertical;"></textarea>
            <input type="text" name="camp_offer" placeholder="Offer/promotion" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <input type="text" name="camp_cta" placeholder="Call to action" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <div class="form-row">
              <div><label>Start</label><input type="date" name="camp_start"></div>
              <div><label>End</label><input type="date" name="camp_end"></div>
            </div>
            <button class="btn btn-primary" style="font-size:.85rem;">Create Campaign</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php // ═══ CONTENT ════════════════════════════════════════════
  elseif ($tab === 'content'): ?>

  <div class="ws-grid">
    <div>
      <?php if ($contents): foreach ($contents as $ci): ?>
      <div class="item-card">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:.5rem;">
          <div>
            <?php if ($ci['title']): ?><strong style="font-size:.9rem;"><?= e($ci['title']) ?></strong><?php endif; ?>
            <span class="badge badge-<?= $ci['status'] ?>" style="margin-left:.4rem;"><?= $ci['status'] ?></span>
            <span style="font-size:.75rem; color:var(--muted); margin-left:.3rem;"><?= ucwords(str_replace('_',' ',$ci['content_type'])) ?></span>
          </div>
          <span style="font-size:.75rem; color:var(--muted);"><?= $ci['scheduled_date'] ? date('j M Y',strtotime($ci['scheduled_date'])) : 'Not scheduled' ?></span>
        </div>
        <?php if ($ci['body']): ?><div style="font-size:.82rem; color:var(--muted); margin-top:.4rem;"><?= e(mb_strimwidth($ci['body'],0,160,'…')) ?></div><?php endif; ?>
        <?php if ($ci['platform']): ?><div style="font-size:.75rem; color:var(--muted); margin-top:.2rem;">Platform: <?= e(ucfirst($ci['platform'])) ?></div><?php endif; ?>
        <?php if ($ci['campaign_name']): ?><div style="font-size:.75rem; color:var(--muted);">Campaign: <?= e($ci['campaign_name']) ?></div><?php endif; ?>
      </div>
      <?php endforeach; else: ?>
      <div class="ws-card" style="text-align:center; padding:2.5rem; color:var(--muted);">📝 No content yet.</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ws-card">
        <h3>Add Content</h3>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_content">
          <div style="display:flex; flex-direction:column; gap:.55rem;">
            <select name="ci_type" style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
              <?php foreach (['social_post'=>'Social Post','promotional_post'=>'Promotional Post','product_post'=>'Product Post','service_post'=>'Service Post','event_post'=>'Event Post','review_post'=>'Review Post','video'=>'Video','image'=>'Image','announcement'=>'Announcement'] as $v=>$l): ?>
              <option value="<?= $v ?>"><?= $l ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="ci_title" placeholder="Title (optional)" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <textarea name="ci_body" rows="4" placeholder="Content text…" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem; resize:vertical;"></textarea>
            <select name="ci_platform" style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
              <option value="">Platform (optional)</option>
              <option value="facebook">Facebook</option>
              <option value="instagram">Instagram</option>
              <option value="tiktok">TikTok</option>
              <option value="linkedin">LinkedIn</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="other">Other</option>
            </select>
            <input type="datetime-local" name="ci_scheduled" style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
            <?php if ($allCampaigns): ?>
            <select name="ci_campaign" style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
              <option value="">Link to campaign (optional)</option>
              <?php foreach ($allCampaigns as $ac): ?>
              <option value="<?= $ac['id'] ?>"><?= e($ac['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button class="btn btn-primary" style="font-size:.85rem;">Add Content</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php // ═══ REVIEWS ════════════════════════════════════════════
  elseif ($tab === 'reviews'): ?>

  <!-- Review overview -->
  <div class="stat-row">
    <div class="stat-box">
      <div class="n"><?= (int)$listing['review_count'] ?></div><div class="l">Total Reviews</div>
    </div>
    <div class="stat-box">
      <div class="n"><?= $listing['avg_rating'] ? number_format((float)$listing['avg_rating'],1) : 'N/A' ?></div><div class="l">Avg Rating ★</div>
    </div>
    <?php
    $monthRevSt = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE listing_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
    $monthRevSt->execute([$listingId]);
    $monthRevs = (int)$monthRevSt->fetchColumn();
    ?>
    <div class="stat-box">
      <div class="n"><?= $monthRevs ?></div><div class="l">This Month</div>
    </div>
  </div>

  <div class="ws-grid">
    <div>
      <div class="ws-card">
        <h3>Review Campaigns</h3>
        <?php if ($reviewCampaigns): foreach ($reviewCampaigns as $rc):
          $pct = $rc['target_reviews'] > 0 ? min(100, round($rc['reviews_generated']/$rc['target_reviews']*100)) : 0;
        ?>
        <div style="border:1px solid var(--border); border-radius:9px; padding:.85rem; margin-bottom:.6rem;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong><?= e($rc['name']) ?></strong>
            <span class="badge badge-<?= $rc['status'] ?>"><?= $rc['status'] ?></span>
          </div>
          <div style="font-size:.8rem; color:var(--muted); margin:.3rem 0;">
            Target: <?= $rc['target_reviews'] ?> reviews · Generated: <?= $rc['reviews_generated'] ?>
          </div>
          <div class="health-bar"><div class="health-fill" style="width:<?= $pct ?>%; background:<?= healthColor($pct) ?>;"></div></div>
          <div style="font-size:.72rem; color:var(--muted); margin-top:.2rem;"><?= $pct ?>% complete</div>
        </div>
        <?php endforeach; else: ?>
        <p style="color:var(--muted); font-size:.85rem;">No review campaigns yet.</p>
        <?php endif; ?>
      </div>

      <!-- Existing reviews -->
      <?php
      $revSt = $pdo->prepare("SELECT * FROM reviews WHERE listing_id=? ORDER BY created_at DESC LIMIT 20");
      $revSt->execute([$listingId]);
      $revRows = $revSt->fetchAll();
      if ($revRows): ?>
      <div class="ws-card">
        <h3>Recent Reviews</h3>
        <?php foreach ($revRows as $rev): ?>
        <div style="padding:.6rem 0; border-bottom:1px solid var(--border);">
          <div style="display:flex; justify-content:space-between; font-size:.82rem;">
            <strong><?= e($rev['reviewer_name'] ?? 'Customer') ?></strong>
            <span style="color:#fcd116;"><?= str_repeat('★',(int)$rev['rating']) ?><?= str_repeat('☆',5-(int)$rev['rating']) ?></span>
          </div>
          <?php if ($rev['review_text']??''): ?><div style="font-size:.8rem; color:var(--muted); margin-top:.2rem;"><?= e(mb_strimwidth($rev['review_text'],0,200,'…')) ?></div><?php endif; ?>
          <div style="font-size:.72rem; color:var(--muted); margin-top:.2rem;"><?= date('j M Y',strtotime($rev['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ws-card">
        <h3>Create Review Campaign</h3>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="create_review_campaign">
          <div style="display:flex; flex-direction:column; gap:.55rem;">
            <input type="text" name="rc_name" required placeholder="Campaign name" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <input type="number" name="rc_target" min="1" value="10" placeholder="Target reviews" style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
            <textarea name="rc_message" rows="3" placeholder="Request message template…" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem; resize:vertical;"></textarea>
            <div class="form-row">
              <div><label>Start</label><input type="date" name="rc_start" value="<?= date('Y-m-d') ?>"></div>
              <div><label>End</label><input type="date" name="rc_end" value="<?= date('Y-m-t') ?>"></div>
            </div>
            <button class="btn btn-primary" style="font-size:.85rem;">Create Campaign</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php // ═══ ACTIVITY ════════════════════════════════════════════
  elseif ($tab === 'activity'): ?>

  <div style="max-width:760px;">
    <div class="ws-card">
      <h3>Activity Timeline</h3>
      <?php if ($activityFeed): foreach ($activityFeed as $act): ?>
      <div class="activity-item">
        <div class="activity-dot"></div>
        <div style="flex:1;">
          <div><?= e($act['description']) ?></div>
          <?php if ($act['actor_name']): ?><div style="font-size:.75rem; color:var(--muted);"><?= e($act['actor_name']) ?></div><?php endif; ?>
        </div>
        <div class="activity-date"><?= date('j M Y', strtotime($act['created_at'])) ?></div>
      </div>
      <?php endforeach; else: ?>
      <p style="color:var(--muted); text-align:center; padding:2rem;">No activity recorded yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php // ═══ OPPORTUNITIES ══════════════════════════════════════
  elseif ($tab === 'opportunities'): ?>

  <div class="ws-grid">
    <div>
      <?php if ($opportunities): foreach ($opportunities as $opp): ?>
      <div class="item-card">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:.5rem;">
          <div>
            <strong><?= e($opp['title']) ?></strong>
            <span class="badge opp-<?= $opp['status'] ?>" style="margin-left:.4rem; padding:2px 9px; border-radius:20px; font-size:.72rem; font-weight:700;"><?= ucfirst($opp['status']) ?></span>
          </div>
          <span style="font-size:.78rem; background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:.2rem .6rem;"><?= e($opp['service_type']) ?></span>
        </div>
        <?php if ($opp['description']): ?><div style="font-size:.82rem; color:var(--muted); margin-top:.35rem;"><?= e($opp['description']) ?></div><?php endif; ?>
        <div style="font-size:.72rem; color:var(--muted); margin-top:.3rem;"><?= date('j M Y', strtotime($opp['created_at'])) ?></div>
      </div>
      <?php endforeach; else: ?>
      <div class="ws-card" style="text-align:center; padding:2.5rem; color:var(--muted);">💡 No opportunities logged yet.</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ws-card">
        <h3>Log Opportunity</h3>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_opportunity">
          <div style="display:flex; flex-direction:column; gap:.55rem;">
            <select name="opp_service" required style="padding:.4rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem;">
              <option value="">Select service…</option>
              <?php foreach (['Website','Business Email','SEO','Hosting','SupportDesk','Digital Marketing','Social Media','Featured Listing','Advertising','Other'] as $svc): ?>
              <option value="<?= $svc ?>"><?= $svc ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="opp_title" required placeholder="Opportunity title" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.85rem;">
            <textarea name="opp_desc" rows="3" placeholder="Details…" style="padding:.4rem .65rem; border:1px solid var(--border); border-radius:7px; background:var(--bg); color:var(--text); font-size:.83rem; resize:vertical;"></textarea>
            <button class="btn btn-primary" style="font-size:.85rem;">Log Opportunity</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php // ═══ REPORTS ════════════════════════════════════════════
  elseif ($tab === 'reports'): ?>

  <div style="max-width:860px;">
    <div class="ws-card">
      <h3>Generate Report</h3>
      <form method="POST" action="<?= SITE_URL ?>/partner/reports?id=<?= $listingId ?>" target="_blank">
        <?= csrfField() ?>
        <div class="form-row">
          <div><label>Period start</label><input type="date" name="period_start" value="<?= date('Y-m-01') ?>"></div>
          <div><label>Period end</label><input type="date" name="period_end" value="<?= date('Y-m-t') ?>"></div>
          <div style="padding-top:1.2rem;"><button class="btn btn-primary">Preview Report →</button></div>
        </div>
      </form>
    </div>

    <?php if (!empty($reports)): ?>
    <div class="ws-card">
      <h3>Previous Reports</h3>
      <?php foreach ($reports as $rpt): ?>
      <div style="display:flex; justify-content:space-between; align-items:center; padding:.6rem 0; border-bottom:1px solid var(--border); font-size:.85rem;">
        <div>
          <strong><?= e($rpt['title']) ?></strong>
          <div style="font-size:.75rem; color:var(--muted);"><?= date('j M Y',strtotime($rpt['period_start'])) ?> – <?= date('j M Y',strtotime($rpt['period_end'])) ?></div>
        </div>
        <div style="display:flex; gap:.5rem; align-items:center;">
          <span class="badge badge-<?= $rpt['status'] ?>"><?= $rpt['status'] ?></span>
          <a href="<?= SITE_URL ?>/partner/reports?id=<?= $listingId ?>&report_id=<?= $rpt['id'] ?>" target="_blank" style="color:var(--primary); text-decoration:none; font-size:.8rem;">View →</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>

  <?php // ═══ AI CONTENT ASSISTANT ═══════════════════════════════
  if ($tab === 'ai'):
    // Retrieve flash: generated content + id
    $aiFlash      = getFlash('ai_content');
    $aiGenerated  = '';
    $aiGenId      = 0;
    if ($aiFlash) {
        $parts       = explode('|||', $aiFlash, 2);
        $aiGenerated = $parts[0] ?? '';
        $aiGenId     = (int)($parts[1] ?? 0);
    }
    $successMsg = getFlash('success');
  ?>
  <div class="ws-grid">
    <div style="flex:1 1 60%;">

      <?php if ($successMsg): ?>
      <div style="background:#d1fae5; border:1px solid #6ee7b7; border-radius:8px; padding:.9rem 1.2rem; margin-bottom:1.2rem; color:#065f46; font-size:.9rem;">
        ✅ <?= e($successMsg) ?>
      </div>
      <?php endif; ?>

      <!-- APPROVAL PANEL — shown immediately after generation -->
      <?php if ($aiGenerated && $aiGenId): ?>
      <div class="ws-card" style="border-left:4px solid var(--primary); margin-bottom:1.5rem;">
        <h3 style="margin-bottom:.8rem;">✦ AI Content Ready — Review &amp; Approve</h3>
        <p style="font-size:.82rem; color:var(--muted); margin-bottom:1rem;">
          Review and edit the content below before approving. Only approved content is saved to your Content tab.
          <strong>Do not approve content that contains inaccurate information about this business.</strong>
        </p>
        <form method="POST" action="?id=<?= $listingId ?>&tab=ai">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="ai_approve_content">
          <input type="hidden" name="gen_id" value="<?= $aiGenId ?>">
          <textarea name="edited_text" rows="8" style="width:100%; padding:.7rem; border:1px solid var(--border); border-radius:6px; font-family:inherit; font-size:.9rem; resize:vertical;"><?= e($aiGenerated) ?></textarea>
          <div style="display:flex; gap:.7rem; margin-top:.8rem; flex-wrap:wrap;">
            <button type="submit" style="background:var(--primary); color:#fff; border:none; border-radius:6px; padding:.55rem 1.3rem; cursor:pointer; font-weight:600;">✅ Approve &amp; Save Draft</button>
            <a href="?id=<?= $listingId ?>&tab=ai" style="display:inline-block; background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:.55rem 1.1rem; font-size:.88rem; color:var(--muted); text-decoration:none;">✕ Discard</a>
          </div>
        </form>
        <!-- Separate reject form -->
        <form method="POST" action="?id=<?= $listingId ?>&tab=ai" style="margin-top:.4rem;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="ai_reject_content">
          <input type="hidden" name="gen_id" value="<?= $aiGenId ?>">
          <button type="submit" style="background:none; border:none; color:#e63946; cursor:pointer; font-size:.82rem; padding:0; text-decoration:underline;">
            ✗ Reject this content
          </button>
        </form>
      </div>
      <?php endif; ?>

      <!-- GENERATE FORM -->
      <div class="ws-card">
        <h3 style="margin-bottom:.3rem;">Generate AI Content</h3>
        <p style="font-size:.82rem; color:var(--muted); margin-bottom:1.2rem;">
          Content is generated using verified information about this business — no prices, offers, or unverified claims are included.
          All content requires your review and approval before it is saved.
        </p>
        <form method="POST" action="?id=<?= $listingId ?>&tab=ai">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="ai_generate_content">

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
            <div>
              <label style="font-size:.82rem; font-weight:600; display:block; margin-bottom:.4rem;">Content Type</label>
              <select name="ai_content_type" style="width:100%; padding:.55rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text);">
                <optgroup label="Social Media">
                  <option value="social_post">Social Post (general)</option>
                  <option value="instagram_caption">Instagram Caption</option>
                  <option value="tiktok_concept">TikTok Concept / Script</option>
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
            <div>
              <label style="font-size:.82rem; font-weight:600; display:block; margin-bottom:.4rem;">Style / Tone</label>
              <select name="ai_style" style="width:100%; padding:.55rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text);">
                <option value="professional">Professional</option>
                <option value="friendly">Friendly</option>
                <option value="short">Short &amp; Sharp</option>
                <option value="promotional">Promotional</option>
                <option value="whatsapp">WhatsApp Friendly</option>
                <option value="tiktok">TikTok / Gen Z</option>
              </select>
            </div>
          </div>

          <div style="margin-bottom:1rem;">
            <label style="font-size:.82rem; font-weight:600; display:block; margin-bottom:.4rem;">Platform (optional)</label>
            <input type="text" name="ai_platform" placeholder="e.g. Facebook, Instagram, WhatsApp..." maxlength="100"
              style="width:100%; padding:.55rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text);">
          </div>

          <div style="margin-bottom:1.2rem;">
            <label style="font-size:.82rem; font-weight:600; display:block; margin-bottom:.4rem;">
              Additional Notes <span style="font-weight:400; color:var(--muted);">(optional — add context, key messages, or anything specific to include)</span>
            </label>
            <textarea name="ai_notes" rows="3" maxlength="500" placeholder="e.g. We're focusing on our plumbing repair services this week. Mention our free call-out policy."
              style="width:100%; padding:.55rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text); resize:vertical;"></textarea>
          </div>

          <button type="submit" style="background:var(--primary); color:#fff; border:none; border-radius:6px; padding:.65rem 1.6rem; cursor:pointer; font-weight:600; font-size:.95rem;">
            ✦ Generate Content
          </button>
          <p style="font-size:.75rem; color:var(--muted); margin-top:.7rem;">
            Content is generated using only verified business information from the 237biz listing.
            Prices, promotions, opening hours, and testimonials are never fabricated.
          </p>
        </form>
      </div>

    </div>

    <!-- RIGHT: Context summary -->
    <div style="flex:0 0 260px; min-width:220px;">
      <div class="ws-card" style="font-size:.83rem;">
        <h4 style="margin-bottom:.8rem; font-size:.9rem;">Business Context Used</h4>
        <div style="display:flex; flex-direction:column; gap:.5rem;">
          <div><span style="color:var(--muted);">Business:</span> <strong><?= e($listing['title']) ?></strong></div>
          <?php if ($listing['cat_en']): ?>
          <div><span style="color:var(--muted);">Category:</span> <?= e($listing['cat_en']) ?></div>
          <?php endif; ?>
          <?php if ($listing['city']): ?>
          <div><span style="color:var(--muted);">Location:</span> <?= e($listing['city']) ?></div>
          <?php endif; ?>
          <?php if ($listing['tagline']): ?>
          <div><span style="color:var(--muted);">Tagline:</span> <em><?= e($listing['tagline']) ?></em></div>
          <?php endif; ?>
          <?php if ($listing['avg_rating'] && (int)$listing['review_count'] >= 3): ?>
          <div><span style="color:var(--muted);">Rating:</span> ⭐ <?= number_format((float)$listing['avg_rating'],1) ?> (<?= (int)$listing['review_count'] ?> reviews)</div>
          <?php endif; ?>
        </div>
        <hr style="margin:.9rem 0; border:none; border-top:1px solid var(--border);">
        <p style="color:var(--muted); font-size:.78rem; line-height:1.5;">
          Only information from the verified business listing is used. Content is never fabricated — no invented prices, hours, testimonials, or statistics.
        </p>
      </div>

      <!-- Quick tips -->
      <div class="ws-card" style="font-size:.82rem; margin-top:1rem;">
        <h4 style="margin-bottom:.7rem; font-size:.88rem;">💡 Tips</h4>
        <ul style="padding-left:1.1rem; line-height:1.7; color:var(--muted);">
          <li>Use <strong>Additional Notes</strong> to add specific context for this piece of content</li>
          <li>Always review generated content before approving</li>
          <li>Approved content is saved as a <strong>Draft</strong> in the Content tab</li>
          <li>Activate content from the Content tab when ready to use</li>
        </ul>
      </div>
    </div>
  </div>

  <!-- HISTORY TABLE -->
  <?php if (!empty($aiHistory)): ?>
  <div class="ws-card" style="margin-top:1.5rem;">
    <h3 style="margin-bottom:1rem;">Previous Generations</h3>
    <div style="overflow-x:auto;">
      <table style="width:100%; border-collapse:collapse; font-size:.83rem;">
        <thead>
          <tr style="border-bottom:2px solid var(--border); text-align:left; color:var(--muted);">
            <th style="padding:.5rem .8rem;">Date</th>
            <th style="padding:.5rem .8rem;">Type</th>
            <th style="padding:.5rem .8rem;">Style</th>
            <th style="padding:.5rem .8rem;">Platform</th>
            <th style="padding:.5rem .8rem;">Status</th>
            <th style="padding:.5rem .8rem;">Preview</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($aiHistory as $gen):
            $statusColour = match($gen['approval_status']) {
                'approved' => '#059669',
                'rejected' => '#dc2626',
                'pending'  => '#d97706',
                default    => 'var(--muted)',
            };
          ?>
          <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:.5rem .8rem; white-space:nowrap; color:var(--muted);"><?= date('j M Y', strtotime($gen['created_at'])) ?></td>
            <td style="padding:.5rem .8rem;"><?= e(str_replace('_',' ', $gen['content_type'])) ?></td>
            <td style="padding:.5rem .8rem;"><?= e($gen['style'] ?? '—') ?></td>
            <td style="padding:.5rem .8rem;"><?= e($gen['platform'] ?? '—') ?></td>
            <td style="padding:.5rem .8rem;">
              <span style="font-weight:600; color:<?= $statusColour ?>;">
                <?= ucfirst($gen['approval_status']) ?>
              </span>
            </td>
            <td style="padding:.5rem .8rem; max-width:280px;">
              <span title="<?= e($gen['generated_text']) ?>" style="display:block; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; color:var(--muted);">
                <?= e(mb_substr($gen['generated_text'], 0, 80)) ?>…
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; // end ai tab ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
