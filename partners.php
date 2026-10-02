<?php
/**
 * partners.php — Public Partner Discovery Marketplace (Task 45)
 *
 * Publicly browsable directory of approved Growth Partners.
 * Filter by region, specialism, capacity, tier.
 * Links to individual partner profile pages.
 */
require_once __DIR__ . '/includes/config.php';

$pdo = db();

// ── Filters ───────────────────────────────────────────────
$filterRegion   = trim($_GET['region']   ?? '');
$filterSpecial  = trim($_GET['specialism'] ?? '');
$filterTier     = trim($_GET['tier']     ?? '');
$filterCapacity = trim($_GET['capacity'] ?? '');
$search         = trim($_GET['q']        ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 12;
$offset         = ($page - 1) * $perPage;

// ── Build query ───────────────────────────────────────────
$where  = ["pp.status IN ('approved','active')", "pp.public_profile = 1"];
$params = [];

if ($filterRegion) {
    $where[]  = "EXISTS (SELECT 1 FROM partner_locations pl WHERE pl.partner_id=pp.id AND pl.region=?)";
    $params[] = $filterRegion;
}
if ($filterSpecial) {
    $where[]  = "JSON_SEARCH(pp.specialisms, 'one', ?) IS NOT NULL";
    $params[] = $filterSpecial;
}
if ($filterTier) {
    $where[]  = "t.slug = ?";
    $params[] = $filterTier;
}
if ($filterCapacity === 'available') {
    $where[]  = "pp.capacity_status IN ('accepting','limited')";
}
if ($search) {
    $where[]  = "(pp.display_name LIKE ? OR pp.tagline LIKE ? OR pp.organisation LIKE ? OR pp.specialisms LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s);
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Count
$countStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT pp.id)
    FROM partner_profiles pp
    LEFT JOIN partner_tiers t ON t.id = pp.tier_id
    $whereSQL
");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Partners
$stmt = $pdo->prepare("
    SELECT pp.id, pp.display_name, pp.avatar_url, pp.tagline, pp.specialisms,
           pp.referral_code, pp.capacity_status, pp.max_businesses, pp.rating, pp.rating_count,
           pp.verified, pp.profile_views,
           t.name AS tier_name, t.slug AS tier_slug,
           (SELECT GROUP_CONCAT(pl.region ORDER BY pl.is_primary DESC SEPARATOR ', ')
            FROM partner_locations pl WHERE pl.partner_id=pp.id LIMIT 3) AS regions,
           (SELECT COUNT(*) FROM partner_business_assignments pba WHERE pba.partner_id=pp.id AND pba.status='active') AS active_biz,
           (SELECT COUNT(*) FROM partner_cert_awards pca WHERE pca.partner_id=pp.id AND pca.revoked_at IS NULL) AS cert_count
    FROM partner_profiles pp
    LEFT JOIN partner_tiers t ON t.id = pp.tier_id
    $whereSQL
    ORDER BY pp.rating DESC, pp.profile_views DESC, pp.id ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$partners = $stmt->fetchAll();

// Filter options
$regions = $pdo->query("
    SELECT DISTINCT region FROM partner_locations
    WHERE partner_id IN (SELECT id FROM partner_profiles WHERE status IN ('approved','active') AND public_profile=1)
    ORDER BY region
")->fetchAll(\PDO::FETCH_COLUMN);

$tiers = $pdo->query("SELECT id, name, slug FROM partner_tiers ORDER BY id")->fetchAll();

// All specialisms (aggregate from JSON columns)
$allSpecs = [];
$specRows = $pdo->query("SELECT specialisms FROM partner_profiles WHERE status IN ('approved','active') AND public_profile=1 AND specialisms IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN);
foreach ($specRows as $row) {
    $arr = json_decode($row, true) ?: [];
    foreach ($arr as $s) {
        $s = trim($s);
        if ($s) $allSpecs[$s] = ($allSpecs[$s] ?? 0) + 1;
    }
}
arsort($allSpecs);
$topSpecs = array_slice(array_keys($allSpecs), 0, 20);

$pageTitle = 'Find a Growth Partner';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.hero{background:linear-gradient(135deg,#1e40af 0%,#3b82f6 100%);color:#fff;padding:3rem 1rem 2rem;text-align:center}
.hero h1{margin:0 0 .5rem;font-size:2rem;font-weight:800}
.hero p{margin:0 0 1.5rem;opacity:.85;font-size:1rem}
.hero-search{display:flex;gap:.5rem;max-width:520px;margin:0 auto}
.hero-search input{flex:1;border:none;border-radius:.5rem;padding:.65rem 1rem;font-size:.95rem}
.hero-search button{background:#f59e0b;color:#fff;border:none;border-radius:.5rem;padding:.65rem 1.25rem;font-size:.9rem;font-weight:700;cursor:pointer;white-space:nowrap}
.main-wrap{max-width:1100px;margin:0 auto;padding:1.5rem 1rem;display:grid;grid-template-columns:220px 1fr;gap:1.5rem}
@media(max-width:760px){.main-wrap{grid-template-columns:1fr}}
.sidebar{position:sticky;top:1rem;align-self:start}
.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1rem;margin-bottom:1rem}
.filter-card h3{margin:0 0 .75rem;font-size:.85rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.05em}
.filter-option{display:flex;align-items:center;gap:.4rem;padding:.25rem 0;font-size:.82rem;color:#374151;cursor:pointer}
.filter-option input[type=radio]{accent-color:#2563eb}
.filter-option label{cursor:pointer}
.filter-link{display:block;padding:.2rem 0;font-size:.82rem;color:#6b7280;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.filter-link:hover,.filter-link.active{color:#2563eb}
.filter-link.active{font-weight:600}
.results-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem}
.results-count{font-size:.875rem;color:#6b7280}
.partner-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem}
.partner-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden;transition:box-shadow .15s;text-decoration:none;display:flex;flex-direction:column}
.partner-card:hover{box-shadow:0 6px 24px rgba(0,0,0,.1);border-color:#d1d5db}
.card-top{padding:1.25rem 1.25rem .75rem;flex:1}
.card-avatar{width:56px;height:56px;border-radius:50%;background:#2563eb;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.25rem;font-weight:700;margin-bottom:.75rem;flex-shrink:0;overflow:hidden}
.card-avatar img{width:100%;height:100%;object-fit:cover}
.card-name{font-size:.95rem;font-weight:700;color:#111827;margin-bottom:.15rem}
.card-tagline{font-size:.8rem;color:#6b7280;margin-bottom:.5rem;line-height:1.4}
.card-badges{display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:.5rem}
.badge{font-size:.68rem;font-weight:600;padding:.15rem .5rem;border-radius:99px}
.badge-tier-standard{background:#dbeafe;color:#1d4ed8}
.badge-tier-silver{background:#e5e7eb;color:#374151}
.badge-tier-gold{background:#fef3c7;color:#92400e}
.badge-tier-platinum{background:#ede9fe;color:#6d28d9}
.badge-verified{background:#d1fae5;color:#065f46}
.badge-capacity-accepting{background:#d1fae5;color:#065f46}
.badge-capacity-limited{background:#fef3c7;color:#92400e}
.badge-capacity-full{background:#fee2e2;color:#991b1b}
.badge-capacity-paused{background:#f3f4f6;color:#6b7280}
.card-specs{display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:.5rem}
.spec-pill{background:#eff6ff;color:#1d4ed8;font-size:.68rem;padding:.1rem .4rem;border-radius:99px}
.card-meta{display:flex;gap:.75rem;font-size:.75rem;color:#9ca3af}
.card-bottom{padding:.6rem 1.25rem;background:#f9fafb;border-top:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between}
.btn-view{font-size:.78rem;font-weight:600;color:#2563eb;text-decoration:none}
.stars{color:#f59e0b;font-size:.75rem}
.pagination{display:flex;gap:.35rem;justify-content:center;margin-top:1.5rem;flex-wrap:wrap}
.pag-btn{padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:.4rem;font-size:.8rem;color:#374151;text-decoration:none;background:#fff}
.pag-btn.active{background:#2563eb;color:#fff;border-color:#2563eb}
.pag-btn:hover:not(.active){background:#f9fafb}
.empty{text-align:center;padding:3rem;color:#9ca3af}
.empty .icon{font-size:2.5rem;margin-bottom:.5rem}
.active-filters{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.75rem}
.af-pill{display:inline-flex;align-items:center;gap:.3rem;background:#eff6ff;color:#1d4ed8;font-size:.75rem;padding:.2rem .5rem;border-radius:99px;font-weight:500}
.af-pill a{color:#1d4ed8;text-decoration:none;font-weight:700}
</style>

<!-- Hero -->
<div class="hero">
    <h1>Find a Growth Partner</h1>
    <p>Connect with verified local experts who grow businesses across Cameroon</p>
    <form method="get" class="hero-search">
        <?php foreach (['region','specialism','tier','capacity'] as $f):
            if (!empty($_GET[$f])): ?>
        <input type="hidden" name="<?= $f ?>" value="<?= e($_GET[$f]) ?>">
        <?php endif; endforeach ?>
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name, specialism…">
        <button type="submit">Search</button>
    </form>
</div>

<div class="main-wrap">
    <!-- Sidebar Filters -->
    <aside class="sidebar" style="display:none;display:block">
        <div class="filter-card">
            <h3>Region</h3>
            <a href="?<?= http_build_query(array_merge($_GET, ['region'=>'','page'=>1])) ?>"
               class="filter-link<?= !$filterRegion ? ' active' : '' ?>">All Regions</a>
            <?php foreach ($regions as $r): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['region'=>$r,'page'=>1])) ?>"
               class="filter-link<?= $filterRegion===$r ? ' active' : '' ?>"><?= e($r) ?></a>
            <?php endforeach ?>
        </div>

        <div class="filter-card">
            <h3>Tier</h3>
            <a href="?<?= http_build_query(array_merge($_GET, ['tier'=>'','page'=>1])) ?>"
               class="filter-link<?= !$filterTier ? ' active' : '' ?>">All Tiers</a>
            <?php foreach ($tiers as $t): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['tier'=>$t['slug'],'page'=>1])) ?>"
               class="filter-link<?= $filterTier===$t['slug'] ? ' active' : '' ?>"><?= e($t['name']) ?></a>
            <?php endforeach ?>
        </div>

        <div class="filter-card">
            <h3>Availability</h3>
            <a href="?<?= http_build_query(array_merge($_GET, ['capacity'=>'','page'=>1])) ?>"
               class="filter-link<?= !$filterCapacity ? ' active' : '' ?>">Any</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['capacity'=>'available','page'=>1])) ?>"
               class="filter-link<?= $filterCapacity==='available' ? ' active' : '' ?>">Available now</a>
        </div>

        <?php if ($topSpecs): ?>
        <div class="filter-card">
            <h3>Specialism</h3>
            <a href="?<?= http_build_query(array_merge($_GET, ['specialism'=>'','page'=>1])) ?>"
               class="filter-link<?= !$filterSpecial ? ' active' : '' ?>">All</a>
            <?php foreach ($topSpecs as $sp): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['specialism'=>$sp,'page'=>1])) ?>"
               class="filter-link<?= $filterSpecial===$sp ? ' active' : '' ?>"><?= e($sp) ?></a>
            <?php endforeach ?>
        </div>
        <?php endif ?>

        <?php if ($filterRegion || $filterTier || $filterCapacity || $filterSpecial || $search): ?>
        <a href="/partners" style="display:block;text-align:center;font-size:.8rem;color:#dc2626;text-decoration:none;padding:.5rem">✕ Clear all filters</a>
        <?php endif ?>
    </aside>

    <!-- Results -->
    <main>
        <div class="results-header">
            <div class="results-count">
                <?= number_format($totalCount) ?> partner<?= $totalCount != 1 ? 's' : '' ?> found
            </div>
        </div>

        <!-- Active filters display -->
        <?php
        $activeFilters = [];
        if ($search)        $activeFilters[] = ['Search: ' . e($search), 'q'];
        if ($filterRegion)  $activeFilters[] = [e($filterRegion), 'region'];
        if ($filterSpecial) $activeFilters[] = [e($filterSpecial), 'specialism'];
        if ($filterTier)    $activeFilters[] = [e($filterTier), 'tier'];
        if ($filterCapacity) $activeFilters[] = ['Available now', 'capacity'];
        ?>
        <?php if ($activeFilters): ?>
        <div class="active-filters">
            <?php foreach ($activeFilters as [$label, $param]): ?>
            <span class="af-pill">
                <?= $label ?>
                <a href="?<?= http_build_query(array_merge($_GET, [$param=>'','page'=>1])) ?>">✕</a>
            </span>
            <?php endforeach ?>
        </div>
        <?php endif ?>

        <?php if (!$partners): ?>
        <div class="empty">
            <div class="icon">🔍</div>
            <p>No partners match your filters. Try adjusting your search.</p>
            <a href="/partners" style="color:#2563eb;font-size:.875rem">Clear filters →</a>
        </div>
        <?php else: ?>
        <div class="partner-grid">
            <?php foreach ($partners as $p):
                $initials   = strtoupper(substr($p['display_name'] ?? 'P', 0, 1));
                $specs      = array_slice(json_decode($p['specialisms'] ?? '[]', true) ?: [], 0, 3);
                $tierSlug   = $p['tier_slug'] ?? 'standard';
                $tierLabel  = $p['tier_name'] ?? 'Standard';
                $capStatus  = $p['capacity_status'] ?? 'accepting';
                $stars      = $p['rating'] ? str_repeat('★', round($p['rating'])) . str_repeat('☆', 5 - round($p['rating'])) : '';
            ?>
            <a href="<?= SITE_URL ?>/partner/profile?code=<?= urlencode($p['referral_code']) ?>" class="partner-card">
                <div class="card-top">
                    <div class="card-avatar">
                        <?php if ($p['avatar_url']): ?>
                        <img src="<?= e($p['avatar_url']) ?>" alt="<?= e($p['display_name']) ?>">
                        <?php else: ?>
                        <?= e($initials) ?>
                        <?php endif ?>
                    </div>
                    <div class="card-name"><?= e($p['display_name']) ?></div>
                    <?php if ($p['tagline']): ?>
                    <div class="card-tagline"><?= e(mb_substr($p['tagline'], 0, 80)) ?></div>
                    <?php endif ?>
                    <div class="card-badges">
                        <span class="badge badge-tier-<?= e($tierSlug) ?>"><?= e($tierLabel) ?></span>
                        <?php if ($p['verified']): ?>
                        <span class="badge badge-verified">✓ Verified</span>
                        <?php endif ?>
                        <span class="badge badge-capacity-<?= e($capStatus) ?>"><?= ucfirst($capStatus) ?></span>
                    </div>
                    <?php if ($specs): ?>
                    <div class="card-specs">
                        <?php foreach ($specs as $sp): ?><span class="spec-pill"><?= e($sp) ?></span><?php endforeach ?>
                    </div>
                    <?php endif ?>
                    <div class="card-meta">
                        <?php if ($p['regions']): ?><span>📍 <?= e(mb_substr($p['regions'], 0, 40)) ?></span><?php endif ?>
                        <?php if ($p['active_biz']): ?><span>🏢 <?= (int)$p['active_biz'] ?> businesses</span><?php endif ?>
                        <?php if ($p['cert_count']): ?><span>🏅 <?= (int)$p['cert_count'] ?> certs</span><?php endif ?>
                    </div>
                </div>
                <div class="card-bottom">
                    <?php if ($stars): ?>
                    <span class="stars"><?= $stars ?> <span style="color:#9ca3af">(<?= (int)$p['rating_count'] ?>)</span></span>
                    <?php else: ?>
                    <span style="font-size:.75rem;color:#9ca3af">No reviews yet</span>
                    <?php endif ?>
                    <span class="btn-view">View Profile →</span>
                </div>
            </a>
            <?php endforeach ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$page-1])) ?>" class="pag-btn">‹</a>
            <?php endif ?>
            <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$p])) ?>"
               class="pag-btn<?= $p===$page ? ' active' : '' ?>"><?= $p ?></a>
            <?php endfor ?>
            <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$page+1])) ?>" class="pag-btn">›</a>
            <?php endif ?>
        </div>
        <?php endif ?>
        <?php endif ?>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
