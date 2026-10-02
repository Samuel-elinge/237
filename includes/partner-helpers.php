<?php
/**
 * includes/partner-helpers.php — 237Biz Growth Partner utilities
 * Shared functions for the partner portal.
 */

/**
 * Store a flash message in the session.
 */
if (!function_exists('setFlash')) {
    function setFlash(string $type, string $message): void {
        if (!isset($_SESSION)) session_start();
        $_SESSION['_flash'][$type] = $message;
    }
}

/**
 * Retrieve and clear a flash message from the session.
 */
if (!function_exists('getFlash')) {
    function getFlash(string $type = 'success'): ?string {
        if (!isset($_SESSION)) session_start();
        $msg = $_SESSION['_flash'][$type] ?? null;
        unset($_SESSION['_flash'][$type]);
        return $msg;
    }
}

/**
 * Require an active/approved growth_partner. Redirect if not.
 */
function requireGrowthPartner(): array {
    if (!isLoggedIn()) redirect(SITE_URL . '/login');
    $user = currentUser();
    if ($user['role'] !== 'growth_partner' && $user['role'] !== 'admin') {
        redirect(SITE_URL . '/dashboard');
    }
    $pdo = db();
    $st  = $pdo->prepare("SELECT * FROM partner_profiles WHERE user_id = ?");
    $st->execute([$user['id']]);
    $profile = $st->fetch();
    if (!$profile || !in_array($profile['status'], ['approved','active'])) {
        // Has profile but pending/suspended
        redirect(SITE_URL . '/partner/pending');
    }
    return $profile;
}

/**
 * Get partner profile for logged-in user (or null).
 */
function getPartnerProfile(?int $userId = null): ?array {
    $userId = $userId ?? ($_SESSION['user_id'] ?? 0);
    if (!$userId) return null;
    $st = db()->prepare("SELECT * FROM partner_profiles WHERE user_id = ?");
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

/**
 * Get all listings assigned to a partner (active assignments only).
 */
function getPartnerListings(int $partnerId): array {
    $st = db()->prepare("
        SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon,
               loc.name_en AS city,
               pba.role AS assignment_role, pba.assigned_at,
               (SELECT COUNT(*) FROM growth_tasks gt WHERE gt.listing_id = l.id AND gt.partner_id = :pid1 AND gt.status NOT IN ('completed','cancelled')) AS open_tasks,
               (SELECT COUNT(*) FROM partner_leads pl WHERE pl.listing_id = l.id AND pl.partner_id = :pid2 AND pl.status IN ('new','contacted','follow_up')) AS active_leads,
               (SELECT COUNT(*) FROM growth_plans gp WHERE gp.listing_id = l.id AND gp.partner_id = :pid3 AND gp.status = 'active') AS active_plans
        FROM listings l
        JOIN partner_business_assignments pba ON pba.listing_id = l.id AND pba.partner_id = :pid4 AND pba.status = 'active'
        JOIN categories c ON c.id = l.category_id
        JOIN locations loc ON loc.id = l.location_id
        ORDER BY pba.assigned_at DESC
    ");
    $st->execute([':pid1' => $partnerId, ':pid2' => $partnerId, ':pid3' => $partnerId, ':pid4' => $partnerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch partner portfolio listings properly.
 */
function fetchPartnerListings(int $partnerId): array {
    $st = db()->prepare("
        SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon,
               loc.name_en AS city,
               pba.role AS assignment_role, pba.assigned_at
        FROM listings l
        JOIN partner_business_assignments pba ON pba.listing_id = l.id AND pba.partner_id = ? AND pba.status = 'active'
        JOIN categories c ON c.id = l.category_id
        JOIN locations loc ON loc.id = l.location_id
        ORDER BY l.title ASC
    ");
    $st->execute([$partnerId]);
    return $st->fetchAll();
}

/**
 * Check partner has access to a specific listing.
 */
function partnerCanAccessListing(int $partnerId, int $listingId): bool {
    $st = db()->prepare("SELECT id FROM partner_business_assignments WHERE partner_id = ? AND listing_id = ? AND status = 'active'");
    $st->execute([$partnerId, $listingId]);
    return (bool)$st->fetch();
}

/**
 * Calculate Business Health Score for a listing.
 * Returns array: ['score'=>int, 'components'=>[...]]
 */
function calcHealthScore(array $listing): array {
    $components = [];

    // Profile (25 points)
    $profile = 0;
    if (!empty($listing['description']) && strlen($listing['description']) > 50) $profile += 5;
    if (!empty($listing['phone']))      $profile += 4;
    if (!empty($listing['email']))      $profile += 3;
    if (!empty($listing['address']))    $profile += 3;
    if (!empty($listing['website']))    $profile += 3;
    if (!empty($listing['whatsapp']))   $profile += 4;
    if (!empty($listing['hours']))      $profile += 3;
    $components['Profile'] = ['score' => $profile, 'max' => 25];

    // Engagement (25 points) — from views/clicks
    $views = (int)($listing['views'] ?? 0);
    $engagement = 0;
    if ($views > 100) $engagement += 10;
    elseif ($views > 20) $engagement += 5;
    elseif ($views > 5) $engagement += 2;
    if (!empty($listing['phone_clicks'])) $engagement += min(8, (int)$listing['phone_clicks']);
    if (!empty($listing['website_clicks'])) $engagement += min(7, (int)$listing['website_clicks']);
    $components['Engagement'] = ['score' => min($engagement, 25), 'max' => 25];

    // Reviews (25 points)
    $revCount  = (int)($listing['review_count']  ?? 0);
    $avgRating = (float)($listing['avg_rating']  ?? 0);
    $reputationScore = 0;
    if ($revCount >= 10)     $reputationScore += 15;
    elseif ($revCount >= 5)  $reputationScore += 10;
    elseif ($revCount >= 1)  $reputationScore += 5;
    if ($avgRating >= 4.5)   $reputationScore += 10;
    elseif ($avgRating >= 4) $reputationScore += 7;
    elseif ($avgRating >= 3) $reputationScore += 3;
    $components['Reviews'] = ['score' => min($reputationScore, 25), 'max' => 25];

    // Marketing (25 points)
    $marketing = 0;
    if (!empty($listing['featured']) && $listing['featured']) $marketing += 10;
    if ($listing['verified'] ?? false) $marketing += 10;
    // Offer/campaign check (simplified — 5pts if anything active)
    $marketing += 5; // placeholder; extend when campaigns table has data
    $components['Marketing'] = ['score' => min($marketing, 25), 'max' => 25];

    $total = array_sum(array_column($components, 'score'));
    return ['score' => $total, 'components' => $components];
}

/**
 * Generate rule-based recommended actions for a listing.
 */
function getRecommendedActions(array $listing): array {
    $actions = [];
    if (empty($listing['description']) || strlen($listing['description']) < 50)
        $actions[] = ['type'=>'warning', 'text'=>'Add a full business description'];
    if (empty($listing['phone']))
        $actions[] = ['type'=>'warning', 'text'=>'Add a phone number'];
    if (empty($listing['whatsapp']))
        $actions[] = ['type'=>'warning', 'text'=>'Add WhatsApp contact number'];
    if (empty($listing['website']))
        $actions[] = ['type'=>'info',    'text'=>'Add your website URL'];
    if (empty($listing['hours']))
        $actions[] = ['type'=>'warning', 'text'=>'Add opening hours'];
    if (($listing['review_count'] ?? 0) < 3)
        $actions[] = ['type'=>'warning', 'text'=>'Request reviews from customers'];
    if (($listing['review_count'] ?? 0) < 10)
        $actions[] = ['type'=>'info',    'text'=>'Aim for 10+ reviews to build trust'];
    if (!($listing['verified'] ?? false))
        $actions[] = ['type'=>'info',    'text'=>'Get the business verified'];
    if (!($listing['featured'] ?? false))
        $actions[] = ['type'=>'info',    'text'=>'Consider a featured listing upgrade'];
    return $actions;
}

/**
 * Log a partner action to the audit trail.
 */
function partnerAuditLog(int $partnerId, int $userId, ?int $listingId, string $action, string $description = ''): void {
    try {
        db()->prepare("INSERT INTO partner_audit_log (partner_id, user_id, listing_id, action, description, ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$partnerId, $userId, $listingId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) { /* non-fatal */ }
}

// ═══════════════════════════════════════════════════════════════
//  Phase 2 Helpers
// ═══════════════════════════════════════════════════════════════

/**
 * Attention queue: portfolio businesses that need action.
 * Returns array of ['listing_id','title','reasons'[]] sorted by urgency.
 */
function getAttentionQueue(int $partnerId): array {
    $pdo   = db();
    $items = [];

    // Fetch all active assignments
    $st = $pdo->prepare("
        SELECT l.id, l.title,
               l.description, l.phone, l.whatsapp, l.website,
               l.verified, l.featured,
               (SELECT COUNT(*) FROM partner_leads pl
                WHERE pl.listing_id = l.id AND pl.partner_id = :p1
                  AND pl.status IN ('new','contacted','follow_up')) AS active_leads,
               (SELECT COUNT(*) FROM partner_leads pl2
                WHERE pl2.listing_id = l.id AND pl2.partner_id = :p2
                  AND pl2.follow_up_date <= CURDATE() AND pl2.status NOT IN ('converted','lost','closed')) AS overdue_leads,
               (SELECT COUNT(*) FROM growth_tasks gt
                WHERE gt.listing_id = l.id AND gt.partner_id = :p3
                  AND gt.due_date < CURDATE() AND gt.status NOT IN ('completed','cancelled')) AS overdue_tasks,
               (SELECT MAX(ba.created_at) FROM business_activity ba
                WHERE ba.listing_id = l.id) AS last_activity,
               (SELECT COUNT(*) FROM partner_campaigns c
                WHERE c.listing_id = l.id AND c.partner_id = :p4 AND c.status = 'active') AS active_campaigns,
               (SELECT MIN(gp.end_date) FROM growth_plans gp
                WHERE gp.listing_id = l.id AND gp.partner_id = :p5 AND gp.status = 'active') AS plan_end_date
        FROM listings l
        JOIN partner_business_assignments pba ON pba.listing_id = l.id AND pba.partner_id = :p6 AND pba.status = 'active'
    ");
    $st->execute([':p1'=>$partnerId,':p2'=>$partnerId,':p3'=>$partnerId,
                  ':p4'=>$partnerId,':p5'=>$partnerId,':p6'=>$partnerId]);
    $rows = $st->fetchAll();

    foreach ($rows as $row) {
        $reasons = [];
        if ((int)$row['overdue_leads'] > 0)
            $reasons[] = ['severity'=>'high',   'text'=> $row['overdue_leads'] . ' overdue lead follow-up' . ($row['overdue_leads']>1?'s':'')];
        if ((int)$row['overdue_tasks'] > 0)
            $reasons[] = ['severity'=>'high',   'text'=> $row['overdue_tasks'] . ' overdue task' . ($row['overdue_tasks']>1?'s':'')];
        if ((int)$row['active_leads'] > 0)
            $reasons[] = ['severity'=>'medium', 'text'=> $row['active_leads'] . ' active lead' . ($row['active_leads']>1?'s':'') . ' need attention'];
        if ($row['plan_end_date'] && strtotime($row['plan_end_date']) < strtotime('+3 days'))
            $reasons[] = ['severity'=>'medium', 'text'=>'Growth plan expires '.date('j M',strtotime($row['plan_end_date']))];
        if ($row['last_activity'] && strtotime($row['last_activity']) < strtotime('-14 days'))
            $reasons[] = ['severity'=>'low',    'text'=>'No activity for '.ceil((time()-strtotime($row['last_activity']))/86400).' days'];
        if (empty($row['phone']))
            $reasons[] = ['severity'=>'low',    'text'=>'Profile missing phone number'];
        if (empty($reasons)) continue;

        // Severity sort: high=0, medium=1, low=2
        $topSeverity = ($reasons[0]['severity'] === 'high') ? 0 : (($reasons[0]['severity'] === 'medium') ? 1 : 2);
        $items[] = ['listing_id'=>$row['id'], 'title'=>$row['title'], 'reasons'=>$reasons, '_sort'=>$topSeverity];
    }
    usort($items, fn($a,$b) => $a['_sort'] <=> $b['_sort']);
    return $items;
}

/**
 * Get activity timeline for a single business.
 */
function getBusinessActivity(int $listingId, int $limit = 30): array {
    $st = db()->prepare("
        SELECT ba.*, u.name AS actor_name
        FROM business_activity ba
        LEFT JOIN users u ON u.id = ba.actor_id
        WHERE ba.listing_id = ?
        ORDER BY ba.created_at DESC
        LIMIT ?
    ");
    $st->execute([$listingId, $limit]);
    return $st->fetchAll();
}

/**
 * Log to business_activity feed.
 */
function logBusinessActivity(int $listingId, ?int $partnerId, ?int $actorId, string $type, string $desc, ?string $refType = null, ?int $refId = null): void {
    try {
        db()->prepare("INSERT INTO business_activity (listing_id, partner_id, actor_id, activity_type, description, ref_type, ref_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$listingId, $partnerId, $actorId, $type, $desc, $refType, $refId]);
    } catch (Exception $e) { /* non-fatal */ }
}

/**
 * Extended recommended actions (Phase 2) — includes campaign/lead/review checks.
 */
function getRecommendedActionsP2(array $listing, int $partnerId): array {
    $pdo     = db();
    $lid     = (int)$listing['id'];
    $actions = [];

    // Profile completeness
    $missing = [];
    if (empty($listing['description']) || strlen($listing['description']) < 50) $missing[] = 'description';
    if (empty($listing['phone']))    $missing[] = 'phone';
    if (empty($listing['whatsapp'])) $missing[] = 'WhatsApp';
    if (empty($listing['website']))  $missing[] = 'website URL';
    if (empty($listing['hours']))    $missing[] = 'opening hours';
    if ($missing) $actions[] = ['level'=>'high','icon'=>'🔴','text'=>'Complete business profile: missing '.implode(', ',$missing),'link'=>'?tab=overview'];

    // Leads
    $pendingLeads = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE listing_id=? AND partner_id=? AND status IN ('new','contacted','follow_up')");
    $pendingLeads->execute([$lid, $partnerId]);
    $lc = (int)$pendingLeads->fetchColumn();
    if ($lc > 0) $actions[] = ['level'=>'high','icon'=>'🔴','text'=>$lc.' enquir'.($lc===1?'y':'ies').' require follow-up','link'=>'?tab=leads'];

    // Overdue leads
    $overdueLeads = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE listing_id=? AND partner_id=? AND follow_up_date <= CURDATE() AND status NOT IN ('converted','lost','closed')");
    $overdueLeads->execute([$lid, $partnerId]);
    $olc = (int)$overdueLeads->fetchColumn();
    if ($olc > 0) $actions[] = ['level'=>'high','icon'=>'🔴','text'=>$olc.' lead follow-up'.($olc===1?'':'s').' overdue','link'=>'?tab=leads'];

    // Reviews
    $revCount = (int)($listing['review_count'] ?? 0);
    $lastReview = null;
    $lrSt = $pdo->prepare("SELECT MAX(created_at) FROM reviews WHERE listing_id=?");
    $lrSt->execute([$lid]);
    $lastReview = $lrSt->fetchColumn();
    if (!$lastReview || strtotime($lastReview) < strtotime('-30 days'))
        $actions[] = ['level'=>'medium','icon'=>'🟠','text'=>'No new review in the last 30 days — consider a review campaign','link'=>'?tab=reviews'];
    if ($revCount < 5)
        $actions[] = ['level'=>'medium','icon'=>'🟠','text'=>'Only '.$revCount.' review'.($revCount===1?'':'s').' — aim for at least 10','link'=>'?tab=reviews'];

    // Active campaign
    $campSt = $pdo->prepare("SELECT COUNT(*) FROM partner_campaigns WHERE listing_id=? AND partner_id=? AND status='active'");
    $campSt->execute([$lid, $partnerId]);
    if (!(int)$campSt->fetchColumn())
        $actions[] = ['level'=>'medium','icon'=>'🟠','text'=>'No active campaign — '.date('F').' promotion not created','link'=>'?tab=campaigns'];

    // Growth plan
    $planSt = $pdo->prepare("SELECT COUNT(*) FROM growth_plans WHERE listing_id=? AND partner_id=? AND status='active'");
    $planSt->execute([$lid, $partnerId]);
    if (!(int)$planSt->fetchColumn())
        $actions[] = ['level'=>'medium','icon'=>'🟠','text'=>'No active growth plan','link'=>'?tab=growth_plan'];

    // Positive
    if (empty($missing))         $actions[] = ['level'=>'good','icon'=>'🟢','text'=>'Business profile is complete'];
    if ($revCount >= 10)         $actions[] = ['level'=>'good','icon'=>'🟢','text'=>'Good review count ('.$revCount.')'];
    if ($listing['verified']??0) $actions[] = ['level'=>'good','icon'=>'🟢','text'=>'Business is verified'];

    return $actions;
}

/**
 * Push a notification for a user.
 */
function pushNotification(int $userId, string $type, string $title, string $body = '', string $actionUrl = '', ?int $partnerId = null, ?int $listingId = null): void {
    try {
        db()->prepare("INSERT INTO partner_notifications (user_id, partner_id, listing_id, type, title, body, action_url) VALUES (?,?,?,?,?,?,?)")
            ->execute([$userId, $partnerId, $listingId, $type, $title, $body, $actionUrl]);
    } catch (Exception $e) { /* non-fatal */ }
}

/**
 * Get unread notification count for a user.
 */
function getUnreadNotifications(int $userId): int {
    $st = db()->prepare("SELECT COUNT(*) FROM partner_notifications WHERE user_id=? AND is_read=0");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/**
 * Get campaign summary stats for a listing.
 */
function getCampaignStats(int $listingId, int $partnerId): array {
    $st = db()->prepare("
        SELECT status, COUNT(*) AS n
        FROM partner_campaigns WHERE listing_id=? AND partner_id=?
        GROUP BY status
    ");
    $st->execute([$listingId, $partnerId]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'active'    => (int)($rows['active']    ?? 0),
        'scheduled' => (int)($rows['scheduled'] ?? 0),
        'completed' => (int)($rows['completed'] ?? 0),
        'draft'     => (int)($rows['draft']     ?? 0),
        'total'     => array_sum($rows),
    ];
}

/**
 * Profile completion percentage (0–100).
 */
function profileCompletion(array $listing): int {
    $fields = ['description','phone','email','address','website','whatsapp','hours'];
    $filled = 0;
    foreach ($fields as $f) {
        if (!empty($listing[$f])) $filled++;
    }
    return (int)round($filled / count($fields) * 100);
}

// =============================================================
// PHASE 3A — INTELLIGENCE LAYER
// =============================================================

/**
 * Compute the 237biz Growth Score (0–100) for a listing.
 * This is a 237biz activity/growth indicator — not an objective
 * measure of business quality or financial performance.
 *
 * Stores a row in growth_score_history each time it is called.
 */
function computeGrowthScore(int $listingId, int $partnerId, \PDO $pdo): array {
    $now = date('Y-m-d');
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $sevenDaysAgo  = date('Y-m-d', strtotime('-7 days'));

    // 1. Profile score (0–20): based on completeness
    $st = $pdo->prepare("SELECT * FROM listings WHERE id = ?");
    $st->execute([$listingId]);
    $listing = $st->fetch() ?: [];
    $profilePct    = profileCompletion($listing);
    $profileScore  = (int)round($profilePct / 100 * 20);

    // 2. Review score (0–15): recent reviews + avg rating
    $st = $pdo->prepare("SELECT COUNT(*) cnt, AVG(rating) avg_r FROM reviews
                          WHERE listing_id = ? AND created_at >= ?");
    $st->execute([$listingId, $thirtyDaysAgo]);
    $rev = $st->fetch();
    $reviewScore = min(15, (int)($rev['cnt'] ?? 0) * 3 + (int)(($rev['avg_r'] ?? 0) >= 4 ? 3 : 0));

    // 3. Lead score (0–20): leads + conversion rate
    $st = $pdo->prepare("SELECT COUNT(*) total,
        SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) converted
        FROM partner_leads WHERE listing_id = ? AND partner_id = ? AND created_at >= ?");
    $st->execute([$listingId, $partnerId, $thirtyDaysAgo]);
    $leads = $st->fetch();
    $totalLeads = (int)($leads['total'] ?? 0);
    $convRate   = $totalLeads > 0 ? ($leads['converted'] / $totalLeads) : 0;
    $leadScore  = min(20, $totalLeads * 2 + (int)round($convRate * 10));

    // 4. Campaign score (0–15): active/recent campaigns
    $st = $pdo->prepare("SELECT COUNT(*) cnt FROM partner_campaigns
                          WHERE listing_id = ? AND partner_id = ?
                          AND status IN ('active','completed')
                          AND (end_date IS NULL OR end_date >= ?)");
    $st->execute([$listingId, $partnerId, $thirtyDaysAgo]);
    $campScore = min(15, (int)$st->fetchColumn() * 5);

    // 5. Content score (0–10): published content in last 30 days
    $st = $pdo->prepare("SELECT COUNT(*) FROM content_items
                          WHERE listing_id = ? AND partner_id = ?
                          AND status = 'published' AND published_date >= ?");
    $st->execute([$listingId, $partnerId, $thirtyDaysAgo]);
    $contentScore = min(10, (int)$st->fetchColumn() * 2);

    // 6. Task score (0–10): tasks completed in last 30 days
    $st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks
                          WHERE listing_id = ? AND partner_id = ?
                          AND status = 'completed' AND updated_at >= ?");
    $st->execute([$listingId, $partnerId, $thirtyDaysAgo]);
    $taskScore = min(10, (int)$st->fetchColumn() * 2);

    // 7. Engagement score (0–10): profile views trend (stub — uses 0 if no analytics table)
    $engagementScore = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM listing_views
                              WHERE listing_id = ? AND viewed_at >= ?");
        $st->execute([$listingId, $sevenDaysAgo]);
        $views = (int)$st->fetchColumn();
        $engagementScore = min(10, (int)round($views / 5));
    } catch (\Exception $e) { /* table may not exist */ }

    $total = $profileScore + $reviewScore + $leadScore + $campScore
           + $contentScore + $taskScore + $engagementScore;
    $total = min(100, max(0, $total));

    $breakdown = [
        'profile'    => $profileScore,
        'reviews'    => $reviewScore,
        'leads'      => $leadScore,
        'campaigns'  => $campScore,
        'content'    => $contentScore,
        'tasks'      => $taskScore,
        'engagement' => $engagementScore,
    ];

    // Store in history
    try {
        $pdo->prepare("INSERT INTO growth_score_history
            (listing_id, partner_id, score, profile_score, engagement_score,
             lead_score, review_score, campaign_score, content_score, task_score, score_breakdown)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $listingId, $partnerId, $total,
            $profileScore, $engagementScore, $leadScore, $reviewScore,
            $campScore, $contentScore, $taskScore,
            json_encode($breakdown)
        ]);
    } catch (\Exception $e) { /* non-fatal */ }

    return ['score' => $total, 'breakdown' => $breakdown];
}

/**
 * Get latest growth score for a listing.
 * Returns ['score'=>int, 'prev_score'=>int, 'delta'=>int] or null.
 */
function getLatestGrowthScore(int $listingId, \PDO $pdo): ?array {
    $st = $pdo->prepare("SELECT score, computed_at FROM growth_score_history
                          WHERE listing_id = ?
                          ORDER BY computed_at DESC LIMIT 2");
    $st->execute([$listingId]);
    $rows = $st->fetchAll();
    if (!$rows) return null;
    $current = (int)$rows[0]['score'];
    $prev    = isset($rows[1]) ? (int)$rows[1]['score'] : $current;
    return ['score' => $current, 'prev_score' => $prev, 'delta' => $current - $prev,
            'computed_at' => $rows[0]['computed_at']];
}

/**
 * Generate rule-based recommendations for a partner.
 * Checks each listing and inserts new recommendations where rules fire.
 * Existing 'new' recommendations for the same rule+listing are not duplicated.
 */
function generateRecommendations(int $partnerId, \PDO $pdo): int {
    $created = 0;
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';

    // Get all active listings for this partner
    $st = $pdo->prepare("SELECT l.* FROM listings l
        JOIN partner_business_assignments pp ON pp.listing_id = l.id
        WHERE pp.partner_id = ? AND pp.status = 'active'");
    $st->execute([$partnerId]);
    $listings = $st->fetchAll();

    foreach ($listings as $listing) {
        $lid = (int)$listing['id'];

        // Helper: check if recommendation already exists (new/viewed)
        $exists = function(string $ruleKey) use ($pdo, $partnerId, $lid): bool {
            $st = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations
                WHERE partner_id=? AND listing_id=? AND rule_key=?
                AND status IN ('new','viewed')");
            $st->execute([$partnerId, $lid, $ruleKey]);
            return (bool)$st->fetchColumn();
        };

        $insert = function(array $r) use ($pdo, $partnerId, $lid, &$created) {
            $pdo->prepare("INSERT INTO ai_recommendations
                (partner_id, listing_id, rule_key, title, description, priority,
                 recommended_action, action_type, action_url, objective, expires_at)
                VALUES (?,?,?,?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
            ->execute([
                $partnerId, $lid, $r['rule_key'], $r['title'], $r['description'],
                $r['priority'], $r['recommended_action'], $r['action_type'],
                $r['action_url'], $r['objective'] ?? null,
            ]);
            $created++;
        };

        $thirtyDays = date('Y-m-d', strtotime('-30 days'));
        $sevenDays  = date('Y-m-d', strtotime('-7 days'));
        $name = htmlspecialchars($listing['name'] ?? 'this business');

        // Rule: no_review_activity — no reviews in 30 days
        if (!$exists('no_review_activity')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM reviews
                WHERE listing_id = ? AND created_at >= ?");
            $st->execute([$lid, $thirtyDays]);
            if ((int)$st->fetchColumn() === 0) {
                // Also check if no review request sent recently
                $st2 = $pdo->prepare("SELECT COUNT(*) FROM review_requests
                    WHERE listing_id = ? AND created_at >= ?");
                $st2->execute([$lid, $thirtyDays]);
                if ((int)$st2->fetchColumn() === 0) {
                    $insert([
                        'rule_key'          => 'no_review_activity',
                        'title'             => "No review activity for $name",
                        'description'       => 'No new reviews or review requests have been recorded in the last 30 days. Review activity helps improve visibility and credibility.',
                        'priority'          => 'medium',
                        'recommended_action'=> 'Launch a Review Campaign',
                        'action_type'       => 'create_review_campaign',
                        'action_url'        => "$siteUrl/partner/campaigns?listing_id=$lid&type=review_campaign",
                        'objective'         => 'Increase review activity',
                    ]);
                }
            }
        }

        // Rule: overdue_leads — leads with overdue follow-up
        if (!$exists('overdue_leads')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads
                WHERE listing_id = ? AND partner_id = ?
                AND follow_up_date < CURDATE()
                AND status NOT IN ('converted','lost','closed')");
            $st->execute([$lid, $partnerId]);
            $overdueCount = (int)$st->fetchColumn();
            if ($overdueCount > 0) {
                $insert([
                    'rule_key'          => 'overdue_leads',
                    'title'             => "$overdueCount overdue lead" . ($overdueCount > 1 ? 's' : '') . " for $name",
                    'description'       => "$overdueCount lead" . ($overdueCount > 1 ? 's have' : ' has') . " a follow-up date that has passed without contact. Early follow-up significantly improves conversion rates.",
                    'priority'          => 'urgent',
                    'recommended_action'=> 'Review Overdue Leads',
                    'action_type'       => 'open_leads',
                    'action_url'        => "$siteUrl/partner/leads?listing_id=$lid&filter=overdue",
                    'objective'         => 'Convert outstanding leads',
                ]);
            }
        }

        // Rule: incomplete_profile — profile below 70%
        if (!$exists('incomplete_profile')) {
            $pct = profileCompletion($listing);
            if ($pct < 70) {
                $insert([
                    'rule_key'          => 'incomplete_profile',
                    'title'             => "$name profile is $pct% complete",
                    'description'       => "An incomplete profile reduces visibility in search results and customer trust. Key missing fields should be completed to improve engagement.",
                    'priority'          => 'high',
                    'recommended_action'=> 'Complete Business Profile',
                    'action_type'       => 'complete_profile',
                    'action_url'        => "$siteUrl/partner/business?listing_id=$lid&tab=profile",
                    'objective'         => 'Improve profile completeness',
                ]);
            }
        }

        // Rule: no_campaign_activity — no active/recent campaigns in 60 days
        if (!$exists('no_campaign_activity')) {
            $sixtyDays = date('Y-m-d', strtotime('-60 days'));
            $st = $pdo->prepare("SELECT COUNT(*) FROM partner_campaigns
                WHERE listing_id = ? AND partner_id = ?
                AND status IN ('active','completed') AND created_at >= ?");
            $st->execute([$lid, $partnerId, $sixtyDays]);
            if ((int)$st->fetchColumn() === 0) {
                $insert([
                    'rule_key'          => 'no_campaign_activity',
                    'title'             => "No campaigns running for $name",
                    'description'       => 'No marketing campaigns have been run in the last 60 days. Regular campaigns help maintain customer engagement and generate leads.',
                    'priority'          => 'medium',
                    'recommended_action'=> 'Create a Campaign',
                    'action_type'       => 'create_campaign',
                    'action_url'        => "$siteUrl/partner/campaigns?listing_id=$lid",
                    'objective'         => 'Drive customer engagement',
                ]);
            }
        }

        // Rule: no_content_activity — no published content in 14 days
        if (!$exists('no_content_activity')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM content_items
                WHERE listing_id = ? AND partner_id = ?
                AND status = 'published' AND published_date >= ?");
            $st->execute([$lid, $partnerId, $sevenDays]);
            if ((int)$st->fetchColumn() === 0) {
                $insert([
                    'rule_key'          => 'no_content_activity',
                    'title'             => "No content published for $name in 14 days",
                    'description'       => 'Regular social media content keeps the business visible and engaged with customers. Consider scheduling some content this week.',
                    'priority'          => 'low',
                    'recommended_action'=> 'Create Content',
                    'action_type'       => 'create_content',
                    'action_url'        => "$siteUrl/partner/content?listing_id=$lid",
                    'objective'         => 'Maintain social media presence',
                ]);
            }
        }

        // Rule: no_tasks — no active tasks in last 30 days
        if (!$exists('no_tasks')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks
                WHERE listing_id = ? AND partner_id = ?
                AND status NOT IN ('completed','cancelled')");
            $st->execute([$lid, $partnerId]);
            if ((int)$st->fetchColumn() === 0) {
                $insert([
                    'rule_key'          => 'no_tasks',
                    'title'             => "No active growth tasks for $name",
                    'description'       => 'This business has no active growth tasks. Adding structured tasks helps track progress and ensure regular attention.',
                    'priority'          => 'low',
                    'recommended_action'=> 'Create a Growth Task',
                    'action_type'       => 'create_task',
                    'action_url'        => "$siteUrl/partner/tasks?listing_id=$lid",
                    'objective'         => 'Maintain structured growth activity',
                ]);
            }
        }

        // Rule: low_rating — avg rating below 3.5
        if (!$exists('low_rating')) {
            $st = $pdo->prepare("SELECT COUNT(*) cnt, AVG(rating) avg_r FROM reviews
                WHERE listing_id = ?");
            $st->execute([$lid]);
            $revRow = $st->fetch();
            if ((int)($revRow['cnt'] ?? 0) >= 3 && (float)($revRow['avg_r'] ?? 5) < 3.5) {
                $avg = number_format((float)$revRow['avg_r'], 1);
                $insert([
                    'rule_key'          => 'low_rating',
                    'title'             => "$name average rating is $avg",
                    'description'       => "The average review rating is below 3.5. A targeted review campaign can help gather more positive reviews and improve the overall score.",
                    'priority'          => 'high',
                    'recommended_action'=> 'Launch Review Campaign',
                    'action_type'       => 'create_review_campaign',
                    'action_url'        => "$siteUrl/partner/campaigns?listing_id=$lid&type=review_campaign",
                    'objective'         => 'Improve review score',
                ]);
            }
        }
    }

    // Expire old recommendations past their expiry date
    $pdo->prepare("UPDATE ai_recommendations
        SET status='expired'
        WHERE partner_id=? AND status IN ('new','viewed')
        AND expires_at IS NOT NULL AND expires_at < NOW()")
    ->execute([$partnerId]);

    return $created;
}

/**
 * Get active recommendations for a partner (optionally for one listing).
 */
function getActiveRecommendations(int $partnerId, ?\PDO $pdo = null, ?int $listingId = null, int $limit = 10): array {
    if (!$pdo) $pdo = db();
    $sql = "SELECT r.*, l.title AS business_name FROM ai_recommendations r
            LEFT JOIN listings l ON l.id = r.listing_id
            WHERE r.partner_id = ? AND r.status IN ('new','viewed')";
    $params = [$partnerId];
    if ($listingId) { $sql .= " AND r.listing_id = ?"; $params[] = $listingId; }
    $sql .= " ORDER BY FIELD(r.priority,'urgent','high','medium','low'), r.created_at DESC LIMIT $limit";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Generate growth alerts for a partner's portfolio.
 * Inserts new alerts; does not duplicate existing new/viewed ones.
 */
function generateGrowthAlerts(int $partnerId, \PDO $pdo): int {
    $created = 0;
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';

    $exists = function(string $alertType, ?int $lid) use ($pdo, $partnerId): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts
            WHERE partner_id=? AND alert_type=?
            AND (listing_id=? OR listing_id IS NULL)
            AND status IN ('new','viewed')
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $st->execute([$partnerId, $alertType, $lid]);
        return (bool)$st->fetchColumn();
    };

    $insert = function(array $a) use ($pdo, $partnerId, &$created) {
        $pdo->prepare("INSERT INTO growth_alerts
            (partner_id, listing_id, alert_type, priority, title, body, action_label, action_url, expires_at)
            VALUES (?,?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 14 DAY))")
        ->execute([
            $partnerId, $a['listing_id'] ?? null, $a['alert_type'], $a['priority'],
            $a['title'], $a['body'], $a['action_label'] ?? null, $a['action_url'] ?? null,
        ]);
        $created++;
    };

    // Portfolio-level: overdue tasks count
    if (!$exists('portfolio_overdue_tasks', null)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks
            WHERE partner_id=? AND status='pending'
            AND due_date IS NOT NULL AND due_date < CURDATE()");
        $st->execute([$partnerId]);
        $count = (int)$st->fetchColumn();
        if ($count >= 3) {
            $insert([
                'listing_id'   => null,
                'alert_type'   => 'portfolio_overdue_tasks',
                'priority'     => 'attention',
                'title'        => "$count overdue tasks across your portfolio",
                'body'         => "$count growth tasks have passed their due date without completion. Review and reschedule to keep your businesses on track.",
                'action_label' => 'View Tasks',
                'action_url'   => "$siteUrl/partner/tasks",
            ]);
        }
    }

    // Portfolio-level: uncontacted leads
    if (!$exists('uncontacted_leads', null)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads
            WHERE partner_id=? AND status='new'
            AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)");
        $st->execute([$partnerId]);
        $count = (int)$st->fetchColumn();
        if ($count > 0) {
            $insert([
                'listing_id'   => null,
                'alert_type'   => 'uncontacted_leads',
                'priority'     => 'urgent',
                'title'        => "$count leads have not been contacted",
                'body'         => "$count new " . ($count === 1 ? 'lead has' : 'leads have') . " been waiting more than 48 hours without contact. Quick follow-up improves conversion rates significantly.",
                'action_label' => 'Follow Up Now',
                'action_url'   => "$siteUrl/partner/leads?filter=new",
            ]);
        }
    }

    // Per-listing alerts
    $st = $pdo->prepare("SELECT l.* FROM listings l
        JOIN partner_business_assignments pp ON pp.listing_id = l.id
        WHERE pp.partner_id = ? AND pp.status = 'active'");
    $st->execute([$partnerId]);
    $listings = $st->fetchAll();

    foreach ($listings as $listing) {
        $lid  = (int)$listing['id'];
        $name = htmlspecialchars($listing['name'] ?? 'A business');

        // Plan expiring within 5 days
        if (!$exists('plan_expiring', $lid)) {
            $st = $pdo->prepare("SELECT * FROM growth_plans
                WHERE listing_id=? AND partner_id=? AND status='active'
                AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)");
            $st->execute([$lid, $partnerId]);
            $plan = $st->fetch();
            if ($plan) {
                $daysLeft = (int)ceil((strtotime($plan['end_date']) - time()) / 86400);
                $insert([
                    'listing_id'   => $lid,
                    'alert_type'   => 'plan_expiring',
                    'priority'     => 'urgent',
                    'title'        => "Growth Plan for $name expires in $daysLeft " . ($daysLeft === 1 ? 'day' : 'days'),
                    'body'         => "The active Growth Plan for {$name} is ending soon. Renew or create a new plan to maintain momentum.",
                    'action_label' => 'Renew Plan',
                    'action_url'   => "$siteUrl/partner/business?listing_id=$lid&tab=plans",
                ]);
            }
        }

        // No activity in 14 days
        if (!$exists('no_activity', $lid)) {
            $fourteenDays = date('Y-m-d', strtotime('-14 days'));
            $st = $pdo->prepare("SELECT MAX(created_at) last
                FROM business_activity WHERE listing_id=? AND partner_id=?");
            $st->execute([$lid, $partnerId]);
            $lastActivity = $st->fetchColumn();
            if (!$lastActivity || $lastActivity < $fourteenDays) {
                $insert([
                    'listing_id'   => $lid,
                    'alert_type'   => 'no_activity',
                    'priority'     => 'attention',
                    'title'        => "No marketing activity recorded for $name in 14 days",
                    'body'         => 'Regular activity keeps the business visible and engaged. Consider creating a task, campaign or content item.',
                    'action_label' => 'View Business',
                    'action_url'   => "$siteUrl/partner/business?listing_id=$lid",
                ]);
            }
        }
    }

    return $created;
}

/**
 * Get active growth alerts for a partner.
 */
function getGrowthAlerts(int $partnerId, ?\PDO $pdo = null, int $limit = 20): array {
    if (!$pdo) $pdo = db();
    $st = $pdo->prepare("SELECT a.*, l.title AS business_name FROM growth_alerts a
        LEFT JOIN listings l ON l.id = a.listing_id
        WHERE a.partner_id = ? AND a.status IN ('new','viewed')
        AND (a.expires_at IS NULL OR a.expires_at > NOW())
        ORDER BY FIELD(a.priority,'urgent','attention','opportunity','informational'),
                 a.created_at DESC
        LIMIT $limit");
    $st->execute([$partnerId]);
    return $st->fetchAll();
}

/**
 * Generate AI content using verified business context only.
 * Never fabricates prices, offers, hours, testimonials, stats, or promotions.
 *
 * @param string $contentType  social_post|promotional_post|review_request|customer_followup|event_promotion|whatsapp_message|instagram_caption|tiktok_concept
 * @param string $style        professional|friendly|short|promotional|tiktok|whatsapp
 * @param array  $ctx          Verified business context (name, category, city, tagline, services, avg_rating, review_count, active_campaign)
 * @param string $userNotes    Additional notes/instructions from the partner
 * @return string Generated content
 */
function generateAIContent(string $contentType, string $style, array $ctx, string $userNotes = ''): string {
    $name     = $ctx['business'] ?? 'this business';
    $category = $ctx['category'] ?? '';
    $city     = $ctx['city'] ?? '';
    $tagline  = $ctx['tagline'] ?? '';
    $services = $ctx['services'] ?? '';
    $campaign = $ctx['active_campaign'] ?? null;

    // Build a context snippet we can weave in
    $locationStr = $city ? " in {$city}" : '';
    $categoryStr = $category ? " ({$category})" : '';
    $taglineStr  = $tagline ? " — \"{$tagline}\"" : '';

    // Rating line (only when reviews exist and rating is verified)
    $ratingLine = '';
    if (!empty($ctx['avg_rating']) && !empty($ctx['review_count']) && (int)$ctx['review_count'] >= 3) {
        $ratingLine = number_format((float)$ctx['avg_rating'], 1) . '★ rated by ' . (int)$ctx['review_count'] . ' customers';
    }

    // Campaign reference (only name + CTA — never fabricated offer text)
    $campaignRef = '';
    if ($campaign && !empty($campaign['name'])) {
        $cta = $campaign['call_to_action'] ?? '';
        $campaignRef = $campaign['name'] . ($cta ? " — {$cta}" : '');
    }

    // User notes hint
    $notesHint = $userNotes ? "\n\nPartner notes: {$userNotes}" : '';

    // ── Template library ────────────────────────────────────────
    switch ($contentType) {

        case 'social_post':
            switch ($style) {
                case 'professional':
                    return trim("📣 Spotlight: {$name}{$categoryStr}{$locationStr}{$taglineStr}\n\n"
                        . ($services ? "We specialise in: {$services}\n\n" : '')
                        . ($ratingLine ? "{$ratingLine}\n\n" : '')
                        . "Looking for trusted {$category} support? Get in touch today."
                        . ($campaignRef ? "\n\n🔗 {$campaignRef}" : '')
                        . $notesHint);

                case 'friendly':
                    return trim("Hey! 👋 If you're looking for reliable {$category} services{$locationStr}, "
                        . "{$name} might be just what you need{$taglineStr}.\n\n"
                        . ($services ? "They cover: {$services}\n\n" : '')
                        . ($ratingLine ? "⭐ {$ratingLine}\n\n" : '')
                        . "Drop them a message and see how they can help!"
                        . ($campaignRef ? "\n\n👉 {$campaignRef}" : '')
                        . $notesHint);

                case 'short':
                    return trim("✅ {$name}{$locationStr} — trusted {$category} services."
                        . ($ratingLine ? " {$ratingLine}." : '')
                        . ($campaignRef ? " {$campaignRef}." : '')
                        . $notesHint);

                case 'promotional':
                    return trim("🌟 Discover {$name}{$locationStr}!\n\n"
                        . ($tagline ? "\"{$tagline}\"\n\n" : '')
                        . ($services ? "Services: {$services}\n\n" : '')
                        . ($ratingLine ? "⭐ {$ratingLine}\n\n" : '')
                        . "Ready to work with a trusted local {$category} provider? Reach out now!"
                        . ($campaignRef ? "\n\n👉 {$campaignRef}" : '')
                        . $notesHint);

                default:
                    return trim("📍 {$name}{$locationStr}{$taglineStr}\n"
                        . ($ratingLine ? "{$ratingLine}\n" : '')
                        . ($campaignRef ? "{$campaignRef}\n" : '')
                        . $notesHint);
            }

        case 'instagram_caption':
            switch ($style) {
                case 'tiktok':
                case 'friendly':
                    return trim("✨ {$name}{$locationStr} is HERE for you! 🙌\n\n"
                        . ($tagline ? "\"{$tagline}\" 💬\n\n" : '')
                        . ($services ? "What they do: {$services}\n\n" : '')
                        . ($ratingLine ? "⭐ {$ratingLine}\n\n" : '')
                        . "Save this post if you need reliable {$category} services{$locationStr} 📌\n\n"
                        . ($campaignRef ? "👉 {$campaignRef}\n\n" : '')
                        . "#local #support #{$category} #smallbusiness #community"
                        . $notesHint);

                default:
                    return trim("📸 {$name}{$categoryStr}{$locationStr}{$taglineStr}\n\n"
                        . ($services ? "{$services}\n\n" : '')
                        . ($ratingLine ? "⭐ {$ratingLine}\n\n" : '')
                        . ($campaignRef ? "👉 {$campaignRef}\n\n" : '')
                        . "#business #local #{$category} #community"
                        . $notesHint);
            }

        case 'tiktok_concept':
            return trim("🎬 TikTok Content Idea for {$name}\n\n"
                . "HOOK (0–3 sec): \"Did you know there's a {$category} business{$locationStr} that can help you today?\"\n\n"
                . "MIDDLE (3–20 sec):\n"
                . "- Introduce {$name}{$taglineStr}\n"
                . ($services ? "- Highlight: {$services}\n" : '')
                . ($ratingLine ? "- Show social proof: {$ratingLine}\n" : '')
                . "\nCALL TO ACTION:\n"
                . ($campaignRef ? "- {$campaignRef}\n" : "- \"Follow for more local business tips!\"\n")
                . "\n💡 Suggested style: upbeat, fast cuts, on-screen text overlays"
                . $notesHint);

        case 'whatsapp_message':
            switch ($style) {
                case 'friendly':
                case 'whatsapp':
                    return trim("Hi there! 👋\n\n"
                        . "Just wanted to share — {$name}{$locationStr} offers great {$category} services"
                        . ($tagline ? " \"{$tagline}\")" : '') . ".\n\n"
                        . ($services ? "They can help with: {$services}\n\n" : '')
                        . ($ratingLine ? "⭐ {$ratingLine}\n\n" : '')
                        . ($campaignRef ? "👉 {$campaignRef}\n\n" : '')
                        . "Hope this is helpful! 😊"
                        . $notesHint);

                default:
                    return trim("Hello,\n\nI'd like to bring {$name}{$locationStr} to your attention."
                        . " As a trusted {$category} provider{$taglineStr}, they may be able to assist you.\n\n"
                        . ($services ? "Services include: {$services}\n\n" : '')
                        . ($ratingLine ? "Customer rating: {$ratingLine}\n\n" : '')
                        . ($campaignRef ? "{$campaignRef}\n\n" : '')
                        . "Please feel free to reach out for more information."
                        . $notesHint);
            }

        case 'review_request':
            return trim("Hi,\n\nThank you for choosing {$name}{$locationStr}! "
                . "We really appreciate your support.\n\n"
                . "If you've had a positive experience with us, we'd love to hear about it. "
                . "Your honest feedback helps other customers find trusted {$category} services{$locationStr} and helps us keep improving.\n\n"
                . "It only takes a minute — and it means a lot to us.\n\n"
                . "Thank you again,\nThe {$name} Team"
                . $notesHint);

        case 'customer_followup':
            return trim("Hi,\n\nJust checking in to see how things are going after your recent experience with {$name}{$locationStr}.\n\n"
                . "We hope everything went well! If you have any questions, feedback, or need further assistance, "
                . "please don't hesitate to get in touch — we're always happy to help.\n\n"
                . ($campaignRef ? "Also, you might be interested in: {$campaignRef}\n\n" : '')
                . "Thank you for your continued support.\n\nWarm regards,\nThe {$name} Team"
                . $notesHint);

        case 'promotional_post':
            return trim("🎯 Looking for trusted {$category} services{$locationStr}?\n\n"
                . "{$name} is ready to help{$taglineStr}.\n\n"
                . ($services ? "What we offer:\n" . implode("\n", array_map(fn($s) => "• " . trim($s), explode(',', $services))) . "\n\n" : '')
                . ($ratingLine ? "⭐ {$ratingLine}\n\n" : '')
                . ($campaignRef ? "📌 Current focus: {$campaignRef}\n\n" : '')
                . "Get in touch today to find out how we can help you."
                . $notesHint);

        case 'event_promotion':
            return trim("📅 {$name}{$locationStr} — Upcoming Activity\n\n"
                . ($userNotes ? "{$userNotes}\n\n" : "We have something exciting coming up!\n\n")
                . ($tagline ? "\"{$tagline}\"\n\n" : '')
                . ($ratingLine ? "⭐ {$ratingLine}\n\n" : '')
                . "Stay tuned for more details, or get in touch to find out more.\n\n"
                . ($campaignRef ? "👉 {$campaignRef}" : "We look forward to seeing you!"));
    }

    return "Content generated for {$name}{$locationStr}{$taglineStr}.";
}
