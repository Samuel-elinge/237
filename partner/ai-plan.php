<?php
/**
 * partner/ai-plan.php — AI Growth Plan Assistant (Phase 3B)
 *
 * Analyses the business's current state and generates a structured
 * 90-day growth plan (objectives + tasks) as a draft for partner review.
 * The partner reviews, edits, and activates — nothing is auto-activated.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = currentUser()['id'];
$pdo            = db();

// Listing selection — must be a listing the partner manages
$listingId = (int)($_GET['id'] ?? 0);
if (!$listingId || !partnerCanAccessListing($partnerId, $listingId)) {
    // Redirect to portfolio if no valid listing
    if (!$listingId) redirect(SITE_URL . '/partner/portfolio');
    redirect(SITE_URL . '/partner/portfolio');
}

$listingSt = $pdo->prepare("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon,
           loc.name_en AS city, u.name AS owner_name,
           (SELECT AVG(r.rating) FROM reviews r WHERE r.listing_id = l.id) AS avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.listing_id = l.id) AS review_count
    FROM listings l
    JOIN categories c  ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    LEFT JOIN users u  ON u.id = l.user_id
    WHERE l.id = ?
");
$listingSt->execute([$listingId]);
$listing = $listingSt->fetch();
if (!$listing) redirect(SITE_URL . '/partner/portfolio');

// ── POST handler ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // GENERATE a new AI plan draft
    if ($action === 'generate_plan') {
        $focus    = $_POST['focus'] ?? 'balanced';
        $duration = (int)($_POST['duration'] ?? 90);
        $notes    = trim($_POST['notes'] ?? '');
        if (!in_array($duration, [30, 60, 90])) $duration = 90;
        $validFocus = ['balanced','reviews','leads','content','campaigns','tasks'];
        if (!in_array($focus, $validFocus)) $focus = 'balanced';

        // Gather business intelligence
        $intel = gatherBusinessIntelligence($listingId, $partnerId, $pdo);

        // Generate plan using rule-based engine
        $planData = generateAIGrowthPlan($intel, $listing, $focus, $duration, $notes);

        // Store the plan as DRAFT
        $startDate = date('Y-m-d');
        $endDate   = date('Y-m-d', strtotime("+{$duration} days"));

        $pdo->prepare("INSERT INTO growth_plans
            (listing_id, partner_id, title, start_date, end_date, review_date, objectives, notes, status)
            VALUES (?,?,?,?,?,?,?,'AI-generated draft — review and activate when ready.','draft')")
            ->execute([
                $listingId, $partnerId,
                $planData['title'],
                $startDate, $endDate,
                date('Y-m-d', strtotime("+30 days")),
                $planData['objectives_text'],
            ]);
        $planId = (int)$pdo->lastInsertId();

        // Insert objectives
        $objStmt = $pdo->prepare("INSERT INTO growth_plan_objectives
            (plan_id, metric, target_value, current_value, sort_order) VALUES (?,?,?,?,?)");
        foreach ($planData['objectives'] as $i => $obj) {
            $objStmt->execute([$planId, $obj['metric'], $obj['target'], $obj['current'], $i]);
        }

        // Insert tasks
        $taskStmt = $pdo->prepare("INSERT INTO growth_tasks
            (listing_id, partner_id, plan_id, title, description, category, priority, due_date, notes, status)
            VALUES (?,?,?,?,?,?,?,?,?,'todo')");
        foreach ($planData['tasks'] as $task) {
            $dueDate = date('Y-m-d', strtotime('+' . ($task['days_out'] ?? 7) . ' days'));
            $taskStmt->execute([
                $listingId, $partnerId, $planId,
                $task['title'],
                $task['description'] ?? null,
                $task['category'] ?? 'other',
                $task['priority'] ?? 'medium',
                $dueDate,
                $task['notes'] ?? null,
            ]);
        }

        partnerAuditLog($partnerId, $userId, $listingId, 'ai_plan_generated', "AI plan: {$planData['title']}");
        logBusinessActivity($listingId, $partnerId, $userId, 'ai_plan_generated', "AI Growth Plan generated as draft", 'plan');

        setFlash('success', "Plan \"{$planData['title']}\" generated as a draft. Review and activate it in the Growth Plan tab.");
        redirect(SITE_URL . "/partner/business?id={$listingId}&tab=growth_plan");
    }

    redirect(SITE_URL . "/partner/ai-plan?id={$listingId}");
}

// ── Business intelligence snapshot ──────────────────────────────

/**
 * Gather the business's current state to inform plan generation.
 */
function gatherBusinessIntelligence(int $listingId, int $partnerId, \PDO $pdo): array {
    $intel = [];

    // Reviews
    $r = $pdo->prepare("SELECT COUNT(*) n, AVG(rating) avg FROM reviews WHERE listing_id=?");
    $r->execute([$listingId]);
    $rev = $r->fetch();
    $intel['review_count'] = (int)$rev['n'];
    $intel['avg_rating']   = $rev['avg'] ? round((float)$rev['avg'], 1) : null;

    // Open tasks
    $t = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE listing_id=? AND partner_id=? AND status NOT IN ('completed','cancelled')");
    $t->execute([$listingId, $partnerId]);
    $intel['open_tasks'] = (int)$t->fetchColumn();

    // Active campaigns
    $c = $pdo->prepare("SELECT COUNT(*) FROM partner_campaigns WHERE listing_id=? AND partner_id=? AND status='active'");
    $c->execute([$listingId, $partnerId]);
    $intel['active_campaigns'] = (int)$c->fetchColumn();

    // Recent content items (last 30 days)
    $ci = $pdo->prepare("SELECT COUNT(*) FROM content_items WHERE listing_id=? AND partner_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $ci->execute([$listingId, $partnerId]);
    $intel['recent_content'] = (int)$ci->fetchColumn();

    // Leads (active)
    $l = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE listing_id=? AND partner_id=? AND status IN ('new','contacted','follow_up','qualified')");
    $l->execute([$listingId, $partnerId]);
    $intel['active_leads'] = (int)$l->fetchColumn();

    // Existing active plan?
    $p = $pdo->prepare("SELECT COUNT(*) FROM growth_plans WHERE listing_id=? AND partner_id=? AND status='active'");
    $p->execute([$listingId, $partnerId]);
    $intel['has_active_plan'] = (int)$p->fetchColumn() > 0;

    // Profile completeness indicators
    $intel['has_tagline']  = !empty($_GET['_pf_tagline']);  // passed via context; default false
    $intel['has_services'] = !empty($_GET['_pf_services']);

    return $intel;
}

/**
 * Generate a structured growth plan using rule-based intelligence.
 * Returns: title, objectives_text, objectives[], tasks[]
 */
function generateAIGrowthPlan(array $intel, array $listing, string $focus, int $duration, string $notes): array {
    $name     = $listing['title'] ?? 'this business';
    $category = $listing['cat_en'] ?? 'local business';
    $city     = $listing['city'] ?? '';
    $tagline  = $listing['tagline'] ?? '';
    $services = $listing['services'] ?? '';

    $labelDuration = $duration === 30 ? '30-Day' : ($duration === 60 ? '60-Day' : '90-Day');

    // Plan title
    $focusTitles = [
        'balanced'  => "{$labelDuration} Growth Plan",
        'reviews'   => "{$labelDuration} Reputation & Reviews Plan",
        'leads'     => "{$labelDuration} Lead Generation Plan",
        'content'   => "{$labelDuration} Content & Visibility Plan",
        'campaigns' => "{$labelDuration} Campaign Activation Plan",
        'tasks'     => "{$labelDuration} Operations & Optimisation Plan",
    ];
    $title = ($focusTitles[$focus] ?? "{$labelDuration} Growth Plan") . " — {$name}";

    // Determine targets based on current state + focus
    $reviewTarget   = max(5, (int)ceil($intel['review_count'] * 1.4) + 3);
    $contentTarget  = $focus === 'content'   ? 12 : ($duration >= 90 ? 8 : 4);
    $campaignTarget = $focus === 'campaigns' ? 3  : 2;
    $leadTarget     = $focus === 'leads'     ? max(10, ($intel['active_leads'] * 2) + 5) : max(5, $intel['active_leads'] + 3);
    $taskTarget     = $focus === 'tasks'     ? 15 : 10;

    // Objectives
    $objectives = [];
    $objLines   = [];

    if (in_array($focus, ['balanced','reviews','leads'])) {
        $objectives[] = ['metric' => 'reviews',   'target' => $reviewTarget,   'current' => $intel['review_count']];
        $objLines[]   = "Grow total verified reviews to {$reviewTarget} (currently {$intel['review_count']})";
    }
    if (in_array($focus, ['balanced','leads'])) {
        $objectives[] = ['metric' => 'leads',     'target' => $leadTarget,     'current' => $intel['active_leads']];
        $objLines[]   = "Build active lead pipeline to {$leadTarget} (currently {$intel['active_leads']})";
    }
    if (in_array($focus, ['balanced','content','campaigns'])) {
        $objectives[] = ['metric' => 'content_items', 'target' => $contentTarget, 'current' => $intel['recent_content']];
        $objLines[]   = "Publish {$contentTarget} content items across channels";
    }
    if (in_array($focus, ['balanced','campaigns'])) {
        $objectives[] = ['metric' => 'campaigns', 'target' => $campaignTarget, 'current' => $intel['active_campaigns']];
        $objLines[]   = "Run {$campaignTarget} targeted marketing campaigns";
    }
    $objectives[] = ['metric' => 'tasks_completed', 'target' => $taskTarget, 'current' => 0];
    $objLines[]   = "Complete {$taskTarget} growth tasks across the period";

    $objectivesText = implode("\n", array_map(fn($l) => "• {$l}", $objLines));
    if ($notes) $objectivesText .= "\n\nPartner notes: {$notes}";

    // ── Task generation ──────────────────────────────────────────
    $tasks = [];
    $day   = 3;

    // === WEEK 1: Profile & Foundation ===
    if (!$tagline) {
        $tasks[] = [
            'title'       => 'Add a compelling tagline to the business profile',
            'description' => "Write a one-line tagline for {$name} that captures what makes the business stand out in {$category}.",
            'category'    => 'profile',
            'priority'    => 'high',
            'days_out'    => $day,
        ];
        $day += 2;
    }
    if (!$services) {
        $tasks[] = [
            'title'       => 'Add service descriptions to the business listing',
            'description' => "List the key services offered by {$name} on the 237biz profile page to improve discoverability.",
            'category'    => 'profile',
            'priority'    => 'high',
            'days_out'    => $day,
        ];
        $day += 2;
    }

    // === REVIEWS ===
    if (in_array($focus, ['balanced','reviews']) || $intel['review_count'] < 5) {
        $tasks[] = [
            'title'       => 'Send review requests to recent customers',
            'description' => "Contact customers who have recently used {$name} and ask them to leave an honest review on the 237biz listing. Use the Review Request template in Communication Templates.",
            'category'    => 'reviews',
            'priority'    => $intel['review_count'] < 3 ? 'urgent' : 'high',
            'days_out'    => $day,
        ];
        $day += 5;

        $tasks[] = [
            'title'       => 'Follow up on review requests sent (Week 2)',
            'description' => "Send a friendly follow-up to any customers who did not respond to the initial review request.",
            'category'    => 'reviews',
            'priority'    => 'medium',
            'days_out'    => $day + 7,
        ];
    }

    // === CONTENT ===
    if (in_array($focus, ['balanced','content','campaigns'])) {
        $tasks[] = [
            'title'       => "Create an introductory social media post for {$name}",
            'description' => "Use the AI Content Assistant to generate a professional introduction post for social media. Review and approve the content before publishing.",
            'category'    => 'social_media',
            'priority'    => 'high',
            'days_out'    => $day,
        ];
        $day += 4;

        $tasks[] = [
            'title'       => 'Schedule 4 social media posts for the month',
            'description' => "Create and schedule content items for the coming month — aim for at least one post per week across the business's active channels.",
            'category'    => 'content',
            'priority'    => 'medium',
            'days_out'    => $day,
        ];
        $day += 5;
    }

    // === CAMPAIGNS ===
    if (in_array($focus, ['balanced','campaigns']) || $intel['active_campaigns'] === 0) {
        $tasks[] = [
            'title'       => "Create a marketing campaign for {$name}",
            'description' => "Set up the first active marketing campaign in the Campaigns tab. Define the campaign name, objective, and call to action. Do not invent offers — use only real, business-approved details.",
            'category'    => 'campaign',
            'priority'    => $intel['active_campaigns'] === 0 ? 'high' : 'medium',
            'days_out'    => $day,
        ];
        $day += 6;
    }

    // === LEADS ===
    if (in_array($focus, ['balanced','leads'])) {
        $tasks[] = [
            'title'       => 'Review and follow up on all active leads',
            'description' => "Check the Leads tab for {$name} and send a follow-up message to any leads in 'new' or 'follow_up' status. Use the Lead Follow-Up template if available.",
            'category'    => 'leads',
            'priority'    => 'high',
            'days_out'    => $day,
        ];
        $day += 4;

        $tasks[] = [
            'title'       => 'Qualify all new leads (mark as contacted, qualified or lost)',
            'description' => "Update the status of all leads from 'new' to their correct stage so the pipeline stays accurate.",
            'category'    => 'leads',
            'priority'    => 'medium',
            'days_out'    => $day + 3,
        ];
    }

    // === MID-PERIOD REVIEW ===
    $midPoint = (int)round($duration / 2);
    $tasks[] = [
        'title'       => 'Mid-plan review — check progress against objectives',
        'description' => "Review the growth plan objectives at the halfway point. Update progress on reviews, leads and content. Adjust any remaining tasks based on what's working.",
        'category'    => 'other',
        'priority'    => 'medium',
        'days_out'    => $midPoint,
    ];

    // === CONTENT (additional, longer horizon) ===
    if ($duration >= 60) {
        $tasks[] = [
            'title'       => 'Generate and publish 4 further content pieces (Month 2)',
            'description' => "Continue content momentum in month 2. Use the AI Content Assistant to create a mix of social posts, WhatsApp messages, and any event-related content.",
            'category'    => 'content',
            'priority'    => 'medium',
            'days_out'    => 35,
        ];
    }
    if ($duration >= 90) {
        $tasks[] = [
            'title'       => 'Run a second review request campaign (Month 3)',
            'description' => "Send another round of review requests to customers who interacted with the business in months 1 and 2.",
            'category'    => 'reviews',
            'priority'    => 'medium',
            'days_out'    => 65,
        ];
        $tasks[] = [
            'title'       => 'Generate end-of-period growth report',
            'description' => "Use the Reports tab to generate a summary report of the full plan period, including reviews gained, leads handled, and content published.",
            'category'    => 'other',
            'priority'    => 'medium',
            'days_out'    => $duration - 3,
        ];
    }

    return [
        'title'           => $title,
        'objectives_text' => $objectivesText,
        'objectives'      => $objectives,
        'tasks'           => $tasks,
    ];
}

// ── Data for the form ────────────────────────────────────────────
// Business intelligence snapshot for display
$intel = gatherBusinessIntelligence($listingId, $partnerId, $pdo);
// Override profile flags from actual listing data
$intel['has_tagline']  = !empty($listing['tagline']);
$intel['has_services'] = !empty($listing['services']);

// Existing plans
$plansSt = $pdo->prepare("SELECT * FROM growth_plans WHERE listing_id=? AND partner_id=? ORDER BY created_at DESC LIMIT 5");
$plansSt->execute([$listingId, $partnerId]);
$existingPlans = $plansSt->fetchAll();

$successMsg = getFlash('success');

// Score-like health indicators for display
function healthClass(int|float $val, int $good, int $warn): string {
    if ($val >= $good) return '#059669';
    if ($val >= $warn) return '#d97706';
    return '#dc2626';
}

$pageTitle = 'AI Growth Plan — ' . e($listing['title']);
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.ai-plan-wrap { max-width: 960px; margin: 0 auto; padding: 1.5rem 1rem; }
.intel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .8rem; margin-bottom: 1.5rem; }
.intel-card { background: var(--card-bg,#fff); border: 1px solid var(--border); border-radius: 8px; padding: .75rem 1rem; text-align: center; }
.intel-card .val { font-size: 1.6rem; font-weight: 700; }
.intel-card .lbl { font-size: .73rem; color: var(--muted); margin-top: .2rem; }
.plan-form { background: var(--card-bg,#fff); border: 1px solid var(--border); border-radius: 10px; padding: 1.4rem; margin-bottom: 1.5rem; }
.plan-form label { font-size: .82rem; font-weight: 600; display: block; margin-bottom: .35rem; }
.plan-form select, .plan-form textarea {
    width: 100%; padding: .55rem .7rem; border: 1px solid var(--border);
    border-radius: 6px; background: var(--bg); color: var(--text);
    font-family: inherit; font-size: .88rem; box-sizing: border-box;
}
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
@media(max-width:600px) { .form-row-3 { grid-template-columns: 1fr; } }
.plan-hist-row { display: flex; justify-content: space-between; align-items: center; padding: .65rem 0; border-bottom: 1px solid var(--border); font-size: .85rem; }
.plan-hist-row:last-child { border-bottom: none; }
</style>

<div class="ai-plan-wrap">

  <!-- Breadcrumb -->
  <div style="font-size:.82rem; color:var(--muted); margin-bottom:1rem;">
    <a href="<?= SITE_URL ?>/partner/portfolio" style="color:var(--muted); text-decoration:none;">Portfolio</a> →
    <a href="<?= SITE_URL ?>/partner/business?id=<?= $listingId ?>" style="color:var(--muted); text-decoration:none;"><?= e($listing['title']) ?></a> →
    AI Growth Plan
  </div>

  <!-- Header -->
  <div style="margin-bottom:1.4rem;">
    <h1 style="margin:0 0 .3rem; font-size:1.4rem;">✦ AI Growth Plan Assistant</h1>
    <p style="margin:0; font-size:.87rem; color:var(--muted);">
      Generates a structured growth plan for <strong><?= e($listing['title']) ?></strong> based on its current state.
      The plan is saved as a draft — review and activate it in the Growth Plan tab.
    </p>
  </div>

  <?php if ($successMsg): ?>
  <div style="background:#d1fae5; border:1px solid #6ee7b7; border-radius:8px; padding:.8rem 1.1rem; margin-bottom:1.2rem; color:#065f46; font-size:.88rem;">
    ✅ <?= e($successMsg) ?>
    <a href="<?= SITE_URL ?>/partner/business?id=<?= $listingId ?>&tab=growth_plan" style="margin-left:1rem; color:#065f46; font-weight:600;">View Growth Plan →</a>
  </div>
  <?php endif; ?>

  <!-- Business intelligence snapshot -->
  <div style="margin-bottom:1.1rem;">
    <h3 style="font-size:.95rem; margin:0 0 .7rem; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; font-size:.78rem;">Current Business State</h3>
    <div class="intel-grid">
      <div class="intel-card">
        <div class="val" style="color:<?= healthClass($intel['review_count'], 10, 3) ?>;"><?= $intel['review_count'] ?></div>
        <div class="lbl">Reviews</div>
      </div>
      <div class="intel-card">
        <div class="val" style="color:<?= $intel['avg_rating'] ? healthClass($intel['avg_rating'], 4.0, 3.0) : 'var(--muted)' ?>;">
          <?= $intel['avg_rating'] ? number_format($intel['avg_rating'],1).'★' : '—' ?>
        </div>
        <div class="lbl">Avg Rating</div>
      </div>
      <div class="intel-card">
        <div class="val" style="color:<?= healthClass($intel['active_leads'], 5, 1) ?>;"><?= $intel['active_leads'] ?></div>
        <div class="lbl">Active Leads</div>
      </div>
      <div class="intel-card">
        <div class="val" style="color:<?= healthClass($intel['active_campaigns'], 2, 1) ?>;"><?= $intel['active_campaigns'] ?></div>
        <div class="lbl">Active Campaigns</div>
      </div>
      <div class="intel-card">
        <div class="val" style="color:<?= healthClass($intel['recent_content'], 4, 1) ?>;"><?= $intel['recent_content'] ?></div>
        <div class="lbl">Content (30 days)</div>
      </div>
      <div class="intel-card">
        <div class="val" style="color:<?= $intel['open_tasks'] > 10 ? '#dc2626' : ($intel['open_tasks'] > 0 ? '#d97706' : '#059669') ?>;"><?= $intel['open_tasks'] ?></div>
        <div class="lbl">Open Tasks</div>
      </div>
      <div class="intel-card">
        <div class="val" style="color:<?= $intel['has_tagline'] ? '#059669' : '#dc2626' ?>;"><?= $intel['has_tagline'] ? '✓' : '✗' ?></div>
        <div class="lbl">Tagline</div>
      </div>
      <div class="intel-card">
        <div class="val" style="color:<?= $intel['has_services'] ? '#059669' : '#dc2626' ?>;"><?= $intel['has_services'] ? '✓' : '✗' ?></div>
        <div class="lbl">Services Listed</div>
      </div>
    </div>
  </div>

  <!-- GENERATE FORM -->
  <div class="plan-form">
    <h3 style="margin:0 0 1rem;">Generate a Growth Plan</h3>
    <form method="POST" action="?id=<?= $listingId ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="generate_plan">

      <div class="form-row-3">
        <div>
          <label>Plan Duration</label>
          <select name="duration">
            <option value="30">30 Days (Quick Sprint)</option>
            <option value="60">60 Days (Short Term)</option>
            <option value="90" selected>90 Days (Quarter — Recommended)</option>
          </select>
        </div>
        <div>
          <label>Primary Focus</label>
          <select name="focus">
            <option value="balanced">Balanced (All Areas)</option>
            <option value="reviews">Reputation &amp; Reviews</option>
            <option value="leads">Lead Generation</option>
            <option value="content">Content &amp; Visibility</option>
            <option value="campaigns">Campaign Activation</option>
            <option value="tasks">Operations &amp; Optimisation</option>
          </select>
        </div>
        <div style="display:flex; align-items:flex-end;">
          <button type="submit" style="width:100%; background:var(--primary); color:#fff; border:none; border-radius:6px; padding:.65rem 1rem; cursor:pointer; font-weight:600; font-size:.95rem;">
            ✦ Generate Plan Draft
          </button>
        </div>
      </div>

      <div style="margin-top:.5rem;">
        <label>Additional Notes <span style="font-weight:400; color:var(--muted);">(optional — anything specific to focus on this period)</span></label>
        <textarea name="notes" rows="2" maxlength="500"
          placeholder="e.g. Focus on WhatsApp outreach, business is launching a new service in month 2..."></textarea>
      </div>

      <p style="font-size:.75rem; color:var(--muted); margin:.7rem 0 0;">
        The plan is saved as a <strong>Draft</strong> and does not activate automatically. All tasks are created as 'To Do' — review, edit and activate in the Growth Plan tab.
        Content within the plan uses only verified business information — no prices, offers, or statistics are fabricated.
      </p>
    </form>
  </div>

  <!-- WHAT GETS GENERATED panel -->
  <div style="background:var(--card-bg,#fff); border:1px solid var(--border); border-radius:10px; padding:1.2rem 1.4rem; margin-bottom:1.5rem;">
    <h4 style="margin:0 0 .7rem; font-size:.95rem;">What the assistant generates</h4>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; font-size:.85rem;">
      <div>
        <strong style="display:block; margin-bottom:.4rem;">📋 Growth Plan</strong>
        <ul style="padding-left:1.1rem; color:var(--muted); line-height:1.7; margin:0;">
          <li>Plan title and period (30 / 60 / 90 days)</li>
          <li>Measurable objectives (reviews, leads, content, campaigns)</li>
          <li>Baseline from current business state</li>
        </ul>
      </div>
      <div>
        <strong style="display:block; margin-bottom:.4rem;">✅ Growth Tasks</strong>
        <ul style="padding-left:1.1rem; color:var(--muted); line-height:1.7; margin:0;">
          <li>8–14 structured tasks linked to the plan</li>
          <li>Prioritised and spread across the period</li>
          <li>Covers reviews, content, leads, campaigns</li>
          <li>Mid-plan review task included</li>
        </ul>
      </div>
    </div>
  </div>

  <!-- EXISTING PLANS -->
  <?php if (!empty($existingPlans)): ?>
  <div style="background:var(--card-bg,#fff); border:1px solid var(--border); border-radius:10px; padding:1.2rem 1.4rem;">
    <h4 style="margin:0 0 .8rem;">Previous Plans</h4>
    <?php foreach ($existingPlans as $ep):
      $statusColour = match($ep['status']) {
        'active'    => '#059669',
        'draft'     => '#d97706',
        'completed' => '#3b82f6',
        'paused'    => '#9ca3af',
        'expired'   => '#6b7280',
        default     => '#6b7280',
      };
    ?>
    <div class="plan-hist-row">
      <div>
        <div style="font-weight:500;"><?= e($ep['title']) ?></div>
        <div style="font-size:.76rem; color:var(--muted);">
          <?= $ep['start_date'] ? date('j M Y', strtotime($ep['start_date'])) : '—' ?>
          <?= $ep['end_date'] ? ' → ' . date('j M Y', strtotime($ep['end_date'])) : '' ?>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:.8rem;">
        <span style="font-weight:600; font-size:.8rem; color:<?= $statusColour ?>;"><?= ucfirst($ep['status']) ?></span>
        <a href="<?= SITE_URL ?>/partner/business?id=<?= $listingId ?>&tab=growth_plan"
           style="font-size:.8rem; color:var(--primary); text-decoration:none; font-weight:500;">View →</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
