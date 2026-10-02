<?php
/**
 * includes/partner-helpers.php — 237Biz Growth Partner utilities
 * Shared functions for the partner portal.
 */

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
    return db()->prepare("
        SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon,
               loc.name_en AS city,
               pba.role AS assignment_role, pba.assigned_at,
               (SELECT COUNT(*) FROM growth_tasks gt WHERE gt.listing_id = l.id AND gt.partner_id = ? AND gt.status NOT IN ('completed','cancelled')) AS open_tasks,
               (SELECT COUNT(*) FROM partner_leads pl WHERE pl.listing_id = l.id AND pl.partner_id = ? AND pl.status IN ('new','contacted','follow_up')) AS active_leads,
               (SELECT COUNT(*) FROM growth_plans gp WHERE gp.listing_id = l.id AND gp.partner_id = ? AND gp.status = 'active') AS active_plans
        FROM listings l
        JOIN partner_business_assignments pba ON pba.listing_id = l.id AND pba.partner_id = ? AND pba.status = 'active'
        JOIN categories c ON c.id = l.category_id
        JOIN locations loc ON loc.id = l.location_id
        ORDER BY pba.assigned_at DESC
    ")->execute([$partnerId, $partnerId, $partnerId, $partnerId]) ? db()->prepare("
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
    ") : null;
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
               l.description, l.phone, l.whatsapp, l.website, l.hours,
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
               (SELECT COUNT(*) FROM campaigns c
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
    $campSt = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE listing_id=? AND partner_id=? AND status='active'");
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
        FROM campaigns WHERE listing_id=? AND partner_id=?
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
