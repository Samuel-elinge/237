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
