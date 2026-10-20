<?php
/**
 * Listings search API — 237Biz
 * GET /api/listings?q=...&category=...&location=...&sort=...&page=...
 *
 * Returns JSON:
 *   { listings: [...], total: int, pages: int, page: int, per_page: int }
 *
 * Sort values: newest (default) | featured | alpha | reviewed
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Input sanitisation ────────────────────────────────────────────────────────
$q       = trim($_GET['q']        ?? '');
$catSlug = trim($_GET['category'] ?? '');
$locSlug = trim($_GET['location'] ?? '');
$sort    = in_array($_GET['sort'] ?? '', ['newest','featured','alpha','reviewed'])
           ? ($_GET['sort'] ?? 'newest') : 'newest';
$page    = max(1, (int)($_GET['page'] ?? 1));

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = ["l.status = 'approved'"];
$params = [];

if ($q !== '') {
    $where[]  = 'MATCH(l.title, l.description) AGAINST(? IN BOOLEAN MODE)';
    $params[] = $q . '*';
}
if ($catSlug !== '') {
    $where[]  = 'c.slug = ?';
    $params[] = $catSlug;
}
if ($locSlug !== '') {
    $where[]  = 'loc.slug = ?';
    $params[] = $locSlug;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── Sort ──────────────────────────────────────────────────────────────────────
$orderSQL = match ($sort) {
    'featured' => 'ORDER BY l.featured DESC, l.created_at DESC',
    'alpha'    => 'ORDER BY l.title ASC',
    'reviewed' => 'ORDER BY review_count DESC, l.featured DESC',
    default    => 'ORDER BY l.created_at DESC',   // newest
};

// ── Count ─────────────────────────────────────────────────────────────────────
try {
    $countSQL = "
        SELECT COUNT(*)
        FROM listings l
        JOIN categories c   ON c.id   = l.category_id
        JOIN locations  loc ON loc.id = l.location_id
        $whereSQL
    ";
    $cs = db()->prepare($countSQL);
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();
} catch (Exception $e) {
    jsonOut(['ok' => false, 'error' => 'DB error'], 500);
}

// ── Paginate ──────────────────────────────────────────────────────────────────
$pg = paginate($total, $page);

// ── Fetch listings ────────────────────────────────────────────────────────────
try {
    $listSQL = "
        SELECT l.id, l.title, l.slug, l.description, l.logo, l.phone,
               l.featured, l.verified,
               c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
               loc.name_en AS loc_en,
               (SELECT COUNT(*) FROM reviews r WHERE r.listing_id = l.id AND r.status = 'approved') AS review_count,
               (SELECT ROUND(AVG(r2.rating),1) FROM reviews r2 WHERE r2.listing_id = l.id AND r2.status = 'approved') AS avg_rating
        FROM listings l
        JOIN categories c   ON c.id   = l.category_id
        JOIN locations  loc ON loc.id = l.location_id
        $whereSQL
        $orderSQL
        LIMIT {$pg['perPage']} OFFSET {$pg['offset']}
    ";
    $ls = db()->prepare($listSQL);
    $ls->execute($params);
    $rows = $ls->fetchAll();
} catch (Exception $e) {
    jsonOut(['ok' => false, 'error' => 'DB error'], 500);
}

// ── Shape output ──────────────────────────────────────────────────────────────
$uploadUrl = defined('UPLOAD_URL') ? UPLOAD_URL : '';
$siteUrl   = defined('SITE_URL')   ? SITE_URL   : '';

$listings = array_map(function (array $l) use ($uploadUrl, $siteUrl): array {
    return [
        'id'          => (int)$l['id'],
        'title'       => $l['title'],
        'slug'        => $l['slug'],
        'description' => mb_substr($l['description'] ?? '', 0, 120),
        'logo'        => $l['logo'] ? $uploadUrl . $l['logo'] : null,
        'phone'       => $l['phone'],
        'featured'    => (bool)$l['featured'],
        'verified'    => (bool)$l['verified'],
        'cat_en'      => $l['cat_en'],
        'cat_fr'      => $l['cat_fr'],
        'cat_icon'    => $l['cat_icon'],
        'loc_en'      => $l['loc_en'],
        'review_count'=> (int)$l['review_count'],
        'avg_rating'  => $l['avg_rating'] ? (float)$l['avg_rating'] : null,
        'url'         => $siteUrl . '/listing/' . $l['slug'],
    ];
}, $rows);

jsonOut([
    'ok'       => true,
    'listings' => $listings,
    'total'    => $total,
    'pages'    => $pg['pages'],
    'page'     => $pg['page'],
    'per_page' => $pg['perPage'],
]);
