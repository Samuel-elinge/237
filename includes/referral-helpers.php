<?php
/**
 * includes/referral-helpers.php
 * Shared functions for referral tracking, commission creation, and attribution.
 */

// ── Referral link attribution ────────────────────────────────────────────

/**
 * Get or set the referral code cookie.
 * Cookie persists for the programme's cookie_days setting.
 */
function getReferralCodeFromCookie(): ?string {
    return $_COOKIE['ref_code'] ?? null;
}

function setReferralCookie(string $code, int $days = 30): void {
    setcookie('ref_code', $code, time() + ($days * 86400), '/', '', false, true);
}

function clearReferralCookie(): void {
    setcookie('ref_code', '', time() - 3600, '/');
}

/**
 * Record a referral click. Handles duplicate detection (same IP = not unique within 24h).
 */
function recordReferralClick(int $linkId, string $ipHash): int {
    $pdo = db();

    // Check if this IP has clicked this link in the last 24 hours
    $check = $pdo->prepare("
        SELECT id FROM referral_clicks
        WHERE link_id = ? AND ip_hash = ? AND clicked_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        LIMIT 1
    ");
    $check->execute([$linkId, $ipHash]);
    $isUnique = !$check->fetch();

    // Insert click
    $pdo->prepare("
        INSERT INTO referral_clicks (link_id, ip_hash, user_agent, referrer, is_unique)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $linkId,
        $ipHash,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
        $isUnique ? 1 : 0,
    ]);

    $clickId = (int)$pdo->lastInsertId();

    // Update totals on referral_links
    if ($isUnique) {
        $pdo->prepare("UPDATE referral_links SET clicks = clicks+1, unique_clicks = unique_clicks+1 WHERE id=?")->execute([$linkId]);
    } else {
        $pdo->prepare("UPDATE referral_links SET clicks = clicks+1 WHERE id=?")->execute([$linkId]);
    }

    return $clickId;
}

/**
 * Find the referral link record by code.
 */
function getLinkByCode(string $code): ?array {
    $st = db()->prepare("SELECT rl.*, u.name AS owner_name, u.role AS owner_role FROM referral_links rl JOIN users u ON u.id=rl.user_id WHERE rl.code=?");
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

// ── Commission creation ───────────────────────────────────────────────────

/**
 * Create a commission entry in the ledger.
 * Status starts as 'pending' — admin approves before it becomes payable.
 */
function createCommission(
    int    $userId,
    string $type,
    int    $amountXaf,
    string $description = '',
    ?int   $referenceId = null,
    string $referenceType = ''
): int {
    if ($amountXaf <= 0) return 0;

    db()->prepare("
        INSERT INTO commissions (user_id, type, amount_xaf, description, reference_id, reference_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$userId, $type, $amountXaf, $description, $referenceId, $referenceType]);

    return (int)db()->lastInsertId();
}

/**
 * Get commission totals for a user broken down by status.
 */
function getUserCommissionSummary(int $userId): array {
    $st = db()->prepare("
        SELECT
            SUM(CASE WHEN status='pending'  THEN amount_xaf ELSE 0 END) AS pending_xaf,
            SUM(CASE WHEN status='approved' THEN amount_xaf ELSE 0 END) AS approved_xaf,
            SUM(CASE WHEN status='paid'     THEN amount_xaf ELSE 0 END) AS paid_xaf,
            COUNT(CASE WHEN status='paid'   THEN 1 END) AS paid_count,
            COUNT(*) AS total_entries
        FROM commissions WHERE user_id=?
    ");
    $st->execute([$userId]);
    $row = $st->fetch();
    return [
        'pending_xaf'  => (int)($row['pending_xaf']  ?? 0),
        'approved_xaf' => (int)($row['approved_xaf'] ?? 0),
        'paid_xaf'     => (int)($row['paid_xaf']     ?? 0),
        'paid_count'   => (int)($row['paid_count']   ?? 0),
        'total_entries'=> (int)($row['total_entries'] ?? 0),
    ];
}

// ── Referral conversion attribution ──────────────────────────────────────

/**
 * Attribute a listing creation or upgrade to a referral code in the cookie.
 * Called from add-listing.php and upgrade-listing.php after successful save.
 *
 * $orderValueXaf is the underlying sale value (e.g. the featured-listing
 * package price) — used as the basis for a creator's monthly sales-volume
 * tier calculation (getCreatorMonthlyVolume / getCreatorTier). It is
 * separate from $commissionXaf-equivalent below, which stays driven by the
 * referral_programmes flat-rate fields for non-creator (affiliate) links.
 *
 * $conversionType accepts 'free_listing' | 'featured_listing' | 'upgrade'
 * (existing) plus 'renewal' (new — a subscription renewal from a
 * previously-referred business; flat 10% of $orderValueXaf, independent of
 * the referrer's tier).
 */
function attributeReferralConversion(
    int    $listingId,
    int    $newUserId,
    string $conversionType,  // 'free_listing' | 'featured_listing' | 'upgrade' | 'renewal'
    int    $orderValueXaf = 0
): void {
    $code = getReferralCodeFromCookie();
    if (!$code) return;

    $link = getLinkByCode($code);
    if (!$link) return;

    // Don't self-attribute
    if ((int)$link['user_id'] === $newUserId) return;

    // Renewals are allowed to repeat per listing; everything else is once-only.
    if ($conversionType !== 'renewal') {
        $check = db()->prepare("SELECT id FROM referral_conversions WHERE listing_id=? LIMIT 1");
        $check->execute([$listingId]);
        if ($check->fetch()) return;
    }

    $prog = db()->prepare("SELECT * FROM referral_programmes WHERE id=?");
    $prog->execute([$link['programme_id']]);
    $programme = $prog->fetch();
    if (!$programme) return;

    $isCreator = ($link['owner_role'] ?? '') === 'creator';

    if ($conversionType === 'renewal') {
        // Flat 10% renewal commission, regardless of the referrer's current tier.
        $commissionXaf = (int)round($orderValueXaf * 0.10);
    } elseif ($isCreator && $orderValueXaf > 0 && in_array($conversionType, ['featured_listing','upgrade'], true)) {
        // Creator tiered commission on sales volume.
        $tier = getCreatorTier((int)$link['user_id']);
        $commissionXaf = (int)round($orderValueXaf * $tier / 100);
    } else {
        // Flat-rate affiliate/legacy programme commission.
        $commissionField = [
            'free_listing'     => 'commission_free_listing',
            'featured_listing' => 'commission_featured_listing',
            'upgrade'          => 'commission_upgrade',
        ][$conversionType] ?? 'commission_free_listing';
        $commissionXaf = (int)($programme[$commissionField] ?? 0);
    }

    // Record conversion
    db()->prepare("
        INSERT INTO referral_conversions (link_id, referred_user_id, listing_id, conversion_type, order_value_xaf, commission_xaf, programme_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$link['id'], $newUserId, $listingId, $conversionType, $orderValueXaf, $commissionXaf, $link['programme_id']]);

    $conversionId = (int)db()->lastInsertId();

    // Mark recent click as converted
    db()->prepare("
        UPDATE referral_clicks SET converted=1
        WHERE link_id=? AND clicked_at > DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY clicked_at DESC LIMIT 1
    ")->execute([$link['id'], $programme['cookie_days']]);

    // Create commission entry (if amount > 0)
    if ($commissionXaf > 0) {
        $typeMap = [
            'free_listing'     => 'referral_free',
            'featured_listing' => 'referral_featured',
            'upgrade'          => 'referral_upgrade',
            'renewal'          => 'referral_renewal',
        ];
        createCommission(
            $link['user_id'],
            $typeMap[$conversionType] ?? 'referral_free',
            $commissionXaf,
            "Referral: {$conversionType} — Listing #{$listingId}",
            $conversionId,
            'conversion'
        );
    }

    // Clear cookie so it can't be re-used (renewals re-attribute from a fresh click
    // on the business's own dashboard flow, so this is safe to always clear).
    clearReferralCookie();
}

// ── Creator program: tiers, base rate, multi-link manager ───────────────

/** Monthly sales-volume tier thresholds → commission percentage. */
function getCreatorTierThresholds(): array {
    return [
        15 => 0,        // 0 – 499,999 XAF
        20 => 500000,   // 500,000 – 999,999 XAF
        25 => 1000000,  // 1,000,000+ XAF
    ];
}

/**
 * Total order value (sales volume) attributed to a creator's referral links
 * in a given calendar month, across new-sale conversion types only
 * (renewals don't count toward tier progress).
 */
function getCreatorMonthlyVolume(int $userId, ?string $yearMonth = null): int {
    $yearMonth = $yearMonth ?: date('Y-m');
    $st = db()->prepare("
        SELECT COALESCE(SUM(rc.order_value_xaf),0) AS vol
        FROM referral_conversions rc
        JOIN referral_links rl ON rl.id = rc.link_id
        WHERE rl.user_id = ?
          AND rc.conversion_type IN ('featured_listing','upgrade')
          AND DATE_FORMAT(rc.created_at,'%Y-%m') = ?
    ");
    $st->execute([$userId, $yearMonth]);
    return (int)$st->fetchColumn();
}

/**
 * Current commission tier (15/20/25) for a creator — manual admin override
 * takes precedence, otherwise computed from this month's sales volume.
 */
function getCreatorTier(int $userId, ?string $yearMonth = null): int {
    $prof = db()->prepare("SELECT tier_override FROM creator_profiles WHERE user_id=?");
    $prof->execute([$userId]);
    $override = $prof->fetchColumn();

    if ($override && $override !== 'auto') {
        return (int)$override;
    }

    $volume = getCreatorMonthlyVolume($userId, $yearMonth);
    $tier = 15;
    foreach (getCreatorTierThresholds() as $pct => $threshold) {
        if ($volume >= $threshold) $tier = $pct;
    }
    return $tier;
}

/**
 * Progress toward the next tier: [tier, volume, next_threshold|null, next_tier|null, pct_complete]
 */
function getCreatorTierProgress(int $userId): array {
    $volume = getCreatorMonthlyVolume($userId);
    $thresholds = getCreatorTierThresholds(); // 15=>0, 20=>500000, 25=>1000000
    $tier = getCreatorTier($userId);

    $ordered = [15, 20, 25];
    $idx = array_search($tier, $ordered, true);
    $nextTier = $ordered[$idx + 1] ?? null;
    $nextThreshold = $nextTier ? $thresholds[$nextTier] : null;
    $prevThreshold = $thresholds[$tier];

    if ($nextThreshold === null) {
        $pct = 100;
    } else {
        $span = max(1, $nextThreshold - $prevThreshold);
        $pct = min(100, (int)round((($volume - $prevThreshold) / $span) * 100));
    }

    return [
        'tier'           => $tier,
        'volume'         => $volume,
        'next_tier'      => $nextTier,
        'next_threshold' => $nextThreshold,
        'remaining'      => $nextThreshold ? max(0, $nextThreshold - $volume) : 0,
        'pct_complete'   => $pct,
    ];
}

/**
 * Ensure this month's base-rate commission exists for an active creator.
 * Idempotent — safe to call on every dashboard load. Uses reference_type
 * 'base_rate_month' + reference_id = YYYYMM (as int) to avoid duplicates.
 */
function ensureCreatorBaseRateCommission(int $userId): void {
    $prof = db()->prepare("SELECT base_rate_xaf, base_rate_active FROM creator_profiles WHERE user_id=?");
    $prof->execute([$userId]);
    $p = $prof->fetch();
    if (!$p || !$p['base_rate_active'] || (int)$p['base_rate_xaf'] <= 0) return;

    $monthKey = (int)date('Ym');
    $check = db()->prepare("SELECT id FROM commissions WHERE user_id=? AND type='base_rate' AND reference_type='base_rate_month' AND reference_id=?");
    $check->execute([$userId, $monthKey]);
    if ($check->fetch()) return;

    createCommission(
        $userId,
        'base_rate',
        (int)$p['base_rate_xaf'],
        'Creator base rate — ' . date('F Y'),
        $monthKey,
        'base_rate_month'
    );
}

/** Base rate + commission + total for the current calendar month (dashboard earnings widget). */
function getCreatorEarningsBreakdown(int $userId): array {
    $monthKey = (int)date('Ym');
    $st = db()->prepare("
        SELECT
          SUM(CASE WHEN type='base_rate' THEN amount_xaf ELSE 0 END) AS base_xaf,
          SUM(CASE WHEN type<>'base_rate' THEN amount_xaf ELSE 0 END) AS commission_xaf
        FROM commissions
        WHERE user_id=? AND (
            (type='base_rate' AND reference_type='base_rate_month' AND reference_id=?)
            OR (type<>'base_rate' AND DATE_FORMAT(created_at,'%Y%m')=?)
        )
    ");
    $st->execute([$userId, $monthKey, (string)$monthKey]);
    $row = $st->fetch() ?: [];
    $base = (int)($row['base_xaf'] ?? 0);
    $comm = (int)($row['commission_xaf'] ?? 0);
    return ['base_xaf' => $base, 'commission_xaf' => $comm, 'total_xaf' => $base + $comm];
}

/** Slugify helper for link codes: lowercase, alnum + hyphen only. */
function slugifyLinkPart(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-') ?: 'link';
}

/**
 * Create a new named referral link for a creator (Link Manager).
 * Code shape: username-linkname (deduplicated with a numeric suffix).
 */
function createCreatorLink(int $userId, string $label, ?int $campaignId = null, string $linkType = 'custom'): array {
    $pdo = db();
    $nameRow = $pdo->prepare("SELECT name FROM users WHERE id=?");
    $nameRow->execute([$userId]);
    $username = slugifyLinkPart($nameRow->fetchColumn() ?: 'creator');
    $slugLabel = slugifyLinkPart($label);

    $base = substr($username . '-' . $slugLabel, 0, 50);
    $code = $base;
    $i = 1;
    while (true) {
        $chk = $pdo->prepare("SELECT id FROM referral_links WHERE code=?");
        $chk->execute([$code]);
        if (!$chk->fetch()) break;
        $code = $base . '-' . $i++;
    }

    $pdo->prepare("INSERT INTO referral_links (user_id, programme_id, code, label, campaign_id, link_type) VALUES (?,1,?,?,?,?)")
        ->execute([$userId, $code, $label, $campaignId, $linkType]);

    $id = (int)$pdo->lastInsertId();
    return ['id' => $id, 'code' => $code, 'label' => $label];
}

/**
 * All of a creator's referral links with per-link stats:
 * clicks, conversions, revenue (order value), commission earned.
 */
function getCreatorLinks(int $userId): array {
    $st = db()->prepare("
        SELECT rl.*,
            (SELECT COUNT(*) FROM referral_conversions rc WHERE rc.link_id=rl.id) AS conversions,
            (SELECT COALESCE(SUM(rc.order_value_xaf),0) FROM referral_conversions rc WHERE rc.link_id=rl.id) AS revenue_xaf,
            (SELECT COALESCE(SUM(rc.commission_xaf),0) FROM referral_conversions rc WHERE rc.link_id=rl.id) AS commission_xaf,
            c.title AS campaign_title
        FROM referral_links rl
        LEFT JOIN campaigns c ON c.id = rl.campaign_id
        WHERE rl.user_id = ?
        ORDER BY rl.link_type='general' DESC, rl.created_at DESC
    ");
    $st->execute([$userId]);
    return $st->fetchAll();
}

/**
 * Ensure the standing "General 237Biz Promotion" campaign exists (created once,
 * visible to all creators), and that this creator has their own general link
 * tied to it. Call on creator dashboard / campaigns page load.
 */
function ensureGeneralCampaignAndLink(int $userId): array {
    $pdo = db();

    $gc = $pdo->query("SELECT * FROM campaigns WHERE is_general=1 LIMIT 1")->fetch();
    if (!$gc) {
        $pdo->prepare("INSERT INTO campaigns (title, description, brief, commission_xaf, bonus_xaf, status, is_general, audience, created_by)
            VALUES (?,?,?,?,?,?,1,'all',NULL)")
            ->execute([
                'General 237Biz Promotion',
                'Your always-on link for promoting any business on 237Biz — use it any time you don\'t have a specific campaign brief to follow.',
                'Share honestly — mention 237Biz.net, what it does (find & list verified Cameroon businesses), and your own experience with it. No fixed hashtags required.',
                0, 0, 'active',
            ]);
        $gcId = (int)$pdo->lastInsertId();
        $gc = ['id' => $gcId];
    } else {
        // Backfill in case an older row predates the is_general/audience columns.
        $pdo->prepare("UPDATE campaigns SET is_general=1, audience='all' WHERE id=? AND (is_general=0 OR audience<>'all')")
            ->execute([$gc['id']]);
    }

    $link = $pdo->prepare("SELECT * FROM referral_links WHERE user_id=? AND campaign_id=? AND link_type='general'");
    $link->execute([$userId, $gc['id']]);
    $link = $link->fetch();

    if (!$link) {
        $link = createCreatorLink($userId, 'General Promotion', (int)$gc['id'], 'general');
    }

    // Ensure this creator is a participant so submissions.php / dashboard treat them as joined.
    $part = $pdo->prepare("SELECT id FROM campaign_participants WHERE campaign_id=? AND user_id=?");
    $part->execute([$gc['id'], $userId]);
    if (!$part->fetch()) {
        $pdo->prepare("INSERT INTO campaign_participants (campaign_id, user_id, status) VALUES (?,?,'joined')")
            ->execute([$gc['id'], $userId]);
    }

    return ['campaign_id' => (int)$gc['id'], 'link' => $link];
}

// ── Formatting helpers ────────────────────────────────────────────────────

function formatXaf(int $amount): string {
    return number_format($amount) . ' XAF';
}

function commissionStatusBadge(string $status): string {
    $map = [
        'pending'   => ['⏳', 'rgba(252,209,22,0.15)',  '#fcd116'],
        'approved'  => ['✅', 'rgba(0,168,120,0.15)',   '#00A878'],
        'paid'      => ['💰', 'rgba(138,180,248,0.15)', '#8ab4f8'],
        'cancelled' => ['✕',  'rgba(206,17,38,0.12)',   '#ff6b7a'],
    ];
    [$icon, $bg, $color] = $map[$status] ?? ['?', 'rgba(255,255,255,0.05)', 'var(--muted)'];
    return "<span style=\"background:{$bg};color:{$color};border-radius:99px;padding:2px 10px;font-size:11.5px;font-weight:700;\">{$icon} " . ucfirst($status) . "</span>";
}

/**
 * Generate a unique referral code from a name/email.
 */
function generateReferralCode(string $name): string {
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    $base = substr($base, 0, 10);
    $code = $base;
    $i = 1;
    while (true) {
        $check = db()->prepare("SELECT id FROM referral_links WHERE code=?");
        $check->execute([$code]);
        if (!$check->fetch()) break;
        $code = $base . $i++;
    }
    return $code;
}
