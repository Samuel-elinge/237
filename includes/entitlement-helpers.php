<?php
/**
 * Entitlement helpers for 237biz subscription limits.
 *
 * Usage:
 *   $ent = getListingEntitlements($listingId);
 *   if (!$ent['can_publish_offer'])  { ... show error ... }
 */

/**
 * Return the active plan + add-on entitlements for a listing.
 *
 * Returns an array:
 *   plan_id, plan_slug, plan_name_en, plan_name_fr,
 *   max_offers, max_events, max_products,
 *   premium_star, search_priority, addon_eligible,
 *   addon_offers, addon_events, addon_products,   ← total add-on capacity
 *   total_offers, total_events, total_products,   ← plan + addons
 *   used_offers, used_events, used_products,
 *   can_publish_offer, can_publish_event, can_publish_product,
 *   subscription_status, ends_at
 */
function getListingEntitlements(int $listingId): array
{
    $db = db();

    // ── Active subscription + plan ───────────────────────────────────
    $st = $db->prepare("
        SELECT bs.id AS sub_id, bs.status AS subscription_status,
               bs.starts_at, bs.ends_at,
               sp.id AS plan_id, sp.slug AS plan_slug,
               sp.name_en AS plan_name_en, sp.name_fr AS plan_name_fr,
               COALESCE(sp.max_offers,   0) AS max_offers,
               COALESCE(sp.max_events,   0) AS max_events,
               COALESCE(sp.max_products, 0) AS max_products,
               COALESCE(sp.premium_star, 0) AS premium_star,
               COALESCE(sp.search_priority, 0) AS search_priority,
               COALESCE(sp.addon_eligible, 0) AS addon_eligible,
               COALESCE(sp.is_free, 0) AS is_free
        FROM business_subscriptions bs
        JOIN subscription_plans sp ON sp.id = bs.plan_id
        WHERE bs.listing_id = ?
          AND bs.status = 'active'
          AND (bs.ends_at IS NULL OR bs.ends_at > NOW())
        ORDER BY sp.search_priority DESC, bs.id DESC
        LIMIT 1
    ");
    $st->execute([$listingId]);
    $plan = $st->fetch();

    // Defaults when no active subscription (treat as free/no plan)
    if (!$plan) {
        // Try to find the free plan defaults
        $free = $db->query("SELECT * FROM subscription_plans WHERE is_free=1 AND active=1 LIMIT 1")->fetch();
        $plan = [
            'sub_id'               => null,
            'subscription_status'  => 'none',
            'starts_at'            => null,
            'ends_at'              => null,
            'plan_id'              => $free['id'] ?? null,
            'plan_slug'            => $free['slug'] ?? 'free',
            'plan_name_en'         => $free['name_en'] ?? 'Free',
            'plan_name_fr'         => $free['name_fr'] ?? 'Gratuit',
            'max_offers'           => (int)($free['max_offers']   ?? 0),
            'max_events'           => (int)($free['max_events']   ?? 0),
            'max_products'         => (int)($free['max_products'] ?? 0),
            'premium_star'         => 0,
            'search_priority'      => 0,
            'addon_eligible'       => 0,
            'is_free'              => 1,
        ];
    }

    // ── Active add-on capacity ───────────────────────────────────────
    $addonSt = $db->prepare("
        SELECT ae.addon_type, SUM(ae.capacity) AS total_capacity
        FROM addon_entitlements ae
        WHERE ae.listing_id = ?
          AND (ae.expires_at IS NULL OR ae.expires_at > NOW())
        GROUP BY ae.addon_type
    ");
    $addonSt->execute([$listingId]);
    $addons = ['offers' => 0, 'events' => 0, 'products' => 0];
    foreach ($addonSt->fetchAll() as $row) {
        $addons[$row['addon_type']] = (int)$row['total_capacity'];
    }

    $totalOffers   = (int)$plan['max_offers']   + $addons['offers'];
    $totalEvents   = (int)$plan['max_events']   + $addons['events'];
    $totalProducts = (int)$plan['max_products'] + $addons['products'];

    // ── Current usage ────────────────────────────────────────────────
    // Active/scheduled/paused offers count toward the slot limit.
    // Paused is included per spec §3 to prevent bypass-by-pause.
    $oSt = $db->prepare("SELECT COUNT(*) FROM offers WHERE listing_id = ? AND status IN ('active','scheduled','paused')");
    $oSt->execute([$listingId]);
    $usedOffers = (int)$oSt->fetchColumn();

    $eSt = $db->prepare("SELECT COUNT(*) FROM events WHERE listing_id = ? AND status IN ('active','upcoming','ongoing') AND (end_date IS NULL OR end_date >= CURDATE())");
    $eSt->execute([$listingId]);
    $usedEvents = (int)$eSt->fetchColumn();

    // Published products (listing_products table; in_stock=1 counts as "active/published")
    $pSt = $db->prepare("SELECT COUNT(*) FROM listing_products WHERE listing_id = ?");
    $pSt->execute([$listingId]);
    $usedProducts = (int)$pSt->fetchColumn();

    // ── Can publish? (0 = unlimited) ────────────────────────────────
    $canOffer   = ($totalOffers   === 0) || ($usedOffers   < $totalOffers);
    $canEvent   = ($totalEvents   === 0) || ($usedEvents   < $totalEvents);
    $canProduct = ($totalProducts === 0) || ($usedProducts < $totalProducts);

    return array_merge($plan, [
        'addon_offers'         => $addons['offers'],
        'addon_events'         => $addons['events'],
        'addon_products'       => $addons['products'],
        'total_offers'         => $totalOffers,
        'total_events'         => $totalEvents,
        'total_products'       => $totalProducts,
        'used_offers'          => $usedOffers,
        'used_events'          => $usedEvents,
        'used_products'        => $usedProducts,
        'can_publish_offer'    => $canOffer,
        'can_publish_event'    => $canEvent,
        'can_publish_product'  => $canProduct,
    ]);
}

/**
 * Lightweight check — does this listing have capacity for one more offer/event/product?
 * Use at save time for a quick server-side guard.
 *
 * @param string $type  'offers' | 'events' | 'products'
 * @param int    $excludeId  The ID of an existing record being edited (so editing ≠ new slot)
 */
function checkEntitlement(int $listingId, string $type, int $excludeId = 0): bool
{
    $ent = getListingEntitlements($listingId);
    $total = (int)$ent["total_{$type}"];
    $used  = (int)$ent["used_{$type}"];

    // If unlimited, always allowed
    if ($total === 0) return true;

    // If editing an existing record it already occupies a slot — don't double-count
    if ($excludeId > 0) {
        $typeTable = ['offers' => 'offers', 'events' => 'events', 'products' => 'listing_products'][$type] ?? $type;
        $statusCol = ['offers' => "status IN ('active','scheduled','paused')", 'events' => "status IN ('active','upcoming','ongoing') AND (end_date IS NULL OR end_date >= CURDATE())", 'products' => "1=1"][$type];
        $chk = db()->prepare("SELECT COUNT(*) FROM {$typeTable} WHERE id = ? AND listing_id = ? AND {$statusCol}");
        $chk->execute([$excludeId, $listingId]);
        if ((int)$chk->fetchColumn() > 0) {
            // Record already counted in used — editing is always OK
            return true;
        }
    }

    return $used < $total;
}
