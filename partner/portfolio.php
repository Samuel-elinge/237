<?php
/**
 * partner/portfolio.php — My Portfolio (all assigned businesses)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

// Build query
$where  = ["pba.partner_id = ?", "pba.status = 'active'"];
$params = [$partnerId];

if ($search) {
    $where[] = "(l.title LIKE ? OR c.name_en LIKE ? OR loc.name_en LIKE ? OR l.id = ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $params[] = is_numeric($search) ? (int)$search : 0;
}

$listings = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon, loc.name_en AS city,
           pba.role AS assignment_role, pba.assigned_at,
           (SELECT COUNT(*) FROM growth_tasks gt WHERE gt.listing_id = l.id AND gt.partner_id = {$partnerId} AND gt.status NOT IN ('completed','cancelled')) AS open_tasks,
           (SELECT COUNT(*) FROM partner_leads pl WHERE pl.listing_id = l.id AND pl.partner_id = {$partnerId} AND pl.status IN ('new','contacted','follow_up')) AS active_leads,
           (SELECT COUNT(*) FROM growth_plans gp WHERE gp.listing_id = l.id AND gp.partner_id = {$partnerId} AND gp.status = 'active') AS active_plans,
           (SELECT COUNT(*) FROM reviews r WHERE r.listing_id = l.id AND r.status = 'approved') AS review_count,
           (SELECT AVG(r.rating) FROM reviews r WHERE r.listing_id = l.id AND r.status = 'approved') AS avg_rating
    FROM listings l
    JOIN partner_business_assignments pba ON pba.listing_id = l.id
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY open_tasks DESC, l.title ASC
");
$listings->execute($params);
$businesses = $listings->fetchAll();

// Client-side filter (by status flags computed per business)
$pageTitle = 'My Portfolio — Partner Centre';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.partner-wrap { max-width:1280px; margin:0 auto; padding:2rem 1.5rem; }
.partner-nav { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:2rem; }
.partner-nav a { padding:0.45rem 1rem; border-radius:9px; font-size:0.875rem; font-weight:600;
  text-decoration:none; background:var(--card); border:1px solid var(--border); color:var(--text); transition:all .15s; }
.partner-nav a:hover, .partner-nav a.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.portfolio-controls { display:flex; gap:1rem; flex-wrap:wrap; align-items:center; margin-bottom:1.5rem; }
.portfolio-controls input { flex:1; min-width:220px; padding:0.5rem 1rem; border-radius:9px;
  border:1px solid var(--border); background:var(--card); color:var(--text); font-size:0.9rem; }
.filter-tabs { display:flex; gap:0.3rem; flex-wrap:wrap; }
.filter-tab { padding:0.35rem 0.9rem; border-radius:20px; border:1px solid var(--border);
  font-size:0.8rem; cursor:pointer; background:var(--card); color:var(--text); text-decoration:none; }
.filter-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.biz-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:1.25rem; }
.biz-card { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.25rem;
  text-decoration:none; color:inherit; display:block; transition:border-color .15s, box-shadow .15s; }
.biz-card:hover { border-color:var(--primary); box-shadow:0 4px 20px rgba(0,168,120,0.1); }
.biz-card-top { display:flex; align-items:flex-start; gap:0.75rem; margin-bottom:0.75rem; }
.biz-icon { font-size:1.8rem; flex-shrink:0; }
.biz-name { font-weight:700; font-size:1rem; margin-bottom:0.15rem; }
.biz-meta { font-size:0.78rem; color:var(--muted); }
.biz-stats { display:flex; gap:1rem; flex-wrap:wrap; margin-top:0.75rem; }
.biz-stat { text-align:center; flex:1; min-width:60px; }
.biz-stat .n { font-weight:700; font-size:1.1rem; }
.biz-stat .l { font-size:0.7rem; color:var(--muted); }

.health-bar { height:6px; background:var(--border); border-radius:3px; margin:0.75rem 0 0.5rem; }
.health-bar-fill { height:100%; border-radius:3px; transition:width .3s; }

.tag-primary { background:rgba(0,168,120,0.15); color:#00A878; border-radius:20px; padding:2px 8px; font-size:0.7rem; font-weight:700; }
.tag-needs-attention { background:rgba(230,57,70,0.15); color:#e63946; border-radius:20px; padding:2px 8px; font-size:0.7rem; font-weight:700; }
.tag-onboarding { background:rgba(252,209,22,0.2); color:#b8960f; border-radius:20px; padding:2px 8px; font-size:0.7rem; font-weight:700; }
</style>

<div class="partner-wrap">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
    <h1 style="font-family:'Fraunces',serif; font-size:2rem; font-weight:900; margin:0;">📋 My Portfolio</h1>
    <span style="color:var(--muted);"><?= count($businesses) ?> business<?= count($businesses) !== 1 ? 'es' : '' ?></span>
  </div>

  <nav class="partner-nav">
    <a href="<?= SITE_URL ?>/partner/dashboard">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/partner/portfolio" class="active">📋 Portfolio</a>
    <a href="<?= SITE_URL ?>/partner/tasks">✅ Tasks</a>
    <a href="<?= SITE_URL ?>/partner/leads">💬 Leads</a>
    <a href="<?= SITE_URL ?>/partner/commissions">💰 Commissions</a>
  </nav>

  <div class="portfolio-controls">
    <form method="GET" style="display:contents;">
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name, category, city or ID…">
      <button type="submit" class="btn btn-primary" style="white-space:nowrap;">🔍 Search</button>
    </form>
    <div class="filter-tabs">
      <?php
      $filters = ['all'=>'All','active'=>'Active','needs_attention'=>'Needs Attention','no_plan'=>'No Growth Plan'];
      foreach ($filters as $fk => $fl):
      ?>
      <a href="?filter=<?= $fk ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="filter-tab <?= $filter===$fk?'active':'' ?>"><?= $fl ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$businesses): ?>
    <div style="text-align:center;padding:4rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
      <div style="font-size:3rem;margin-bottom:1rem;">📭</div>
      <h3 style="font-family:'Fraunces',serif;">No businesses assigned yet</h3>
      <p style="color:var(--muted);">Your portfolio is empty. An admin will assign businesses to you.</p>
    </div>
  <?php else: ?>
    <div class="biz-grid">
      <?php foreach ($businesses as $biz):
        $health = calcHealthScore($biz);
        $healthScore = $health['score'];
        $needsAttention = $biz['open_tasks'] > 5 || $healthScore < 40;
        $isOnboarding = strtotime($biz['assigned_at']) > strtotime('-30 days');
        $healthColor = $healthScore >= 70 ? '#00A878' : ($healthScore >= 40 ? '#fcd116' : '#e63946');

        // Apply filter
        if ($filter === 'active' && $needsAttention) continue;
        if ($filter === 'needs_attention' && !$needsAttention) continue;
        if ($filter === 'no_plan' && $biz['active_plans'] > 0) continue;
      ?>
      <a href="<?= SITE_URL ?>/partner/business?id=<?= $biz['id'] ?>" class="biz-card">
        <div class="biz-card-top">
          <div class="biz-icon"><?= $biz['cat_icon'] ?></div>
          <div style="flex:1; min-width:0;">
            <div class="biz-name"><?= e($biz['title']) ?></div>
            <div class="biz-meta">
              <?= e($biz['cat_en']) ?> · <?= e($biz['city']) ?>
              <?php if ($biz['verified']): ?> · ✓ Verified<?php endif; ?>
            </div>
            <div style="margin-top:0.35rem; display:flex; gap:0.4rem; flex-wrap:wrap;">
              <?php if ($isOnboarding): ?><span class="tag-onboarding">🆕 Onboarding</span><?php endif; ?>
              <?php if ($needsAttention): ?><span class="tag-needs-attention">⚠ Attention</span><?php endif; ?>
              <?php if ($biz['active_plans'] > 0): ?><span class="tag-primary">📈 Plan Active</span><?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Health bar -->
        <div class="health-bar">
          <div class="health-bar-fill" style="width:<?= $healthScore ?>%;background:<?= $healthColor ?>;"></div>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:var(--muted); margin-bottom:0.5rem;">
          <span>Health Score</span><span style="color:<?= $healthColor ?>; font-weight:700;"><?= $healthScore ?>%</span>
        </div>

        <div class="biz-stats">
          <div class="biz-stat">
            <div class="n"><?= (int)$biz['open_tasks'] ?></div>
            <div class="l">Open Tasks</div>
          </div>
          <div class="biz-stat">
            <div class="n"><?= (int)$biz['active_leads'] ?></div>
            <div class="l">Leads</div>
          </div>
          <div class="biz-stat">
            <div class="n"><?= (int)$biz['review_count'] ?></div>
            <div class="l">Reviews</div>
          </div>
          <div class="biz-stat">
            <div class="n"><?= $biz['avg_rating'] ? number_format($biz['avg_rating'],1) : '—' ?></div>
            <div class="l">Rating</div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
