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
 */
function attributeReferralConversion(
    int    $listingId,
    int    $newUserId,
    string $conversionType  // 'free_listing' | 'featured_listing' | 'upgrade'
): void {
    $code = getReferralCodeFromCookie();
    if (!$code) return;

    $link = getLinkByCode($code);
    if (!$link) return;

    // Don't self-attribute
    if ((int)$link['user_id'] === $newUserId) return;

    // Check not already converted for this listing
    $check = db()->prepare("SELECT id FROM referral_conversions WHERE listing_id=? LIMIT 1");
    $check->execute([$listingId]);
    if ($check->fetch()) return;

    // Get commission amount from programme
    $prog = db()->prepare("SELECT * FROM referral_programmes WHERE id=?");
    $prog->execute([$link['programme_id']]);
    $programme = $prog->fetch();
    if (!$programme) return;

    $commissionField = [
        'free_listing'     => 'commission_free_listing',
        'featured_listing' => 'commission_featured_listing',
        'upgrade'          => 'commission_upgrade',
    ][$conversionType] ?? 'commission_free_listing';

    $commissionXaf = (int)($programme[$commissionField] ?? 0);

    // Record conversion
    db()->prepare("
        INSERT INTO referral_conversions (link_id, referred_user_id, listing_id, conversion_type, commission_xaf, programme_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$link['id'], $newUserId, $listingId, $conversionType, $commissionXaf, $link['programme_id']]);

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
        ];
        createCommission(
            $link['user_id'],
            $typeMap[$conversionType],
            $commissionXaf,
            "Referral: {$conversionType} — Listing #{$listingId}",
            $conversionId,
            'conversion'
        );
    }

    // Clear cookie so it can't be re-used
    clearReferralCookie();
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
