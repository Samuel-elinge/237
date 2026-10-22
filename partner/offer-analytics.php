<?php
/**
 * partner/offer-analytics.php — Per-offer analytics for business owners
 * URL: /partner/offer-analytics?id={offer_id}
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$isFr   = lang() === 'fr';
$userId = (int)(currentUser()['id'] ?? 0);
$pdo    = db();

// ── Resolve offer ─────────────────────────────────────────────────────────────
$oid = (int)($_GET['id'] ?? 0);
if (!$oid) { redirect(SITE_URL . '/partner/offers'); }

// Load offer — must belong to this user's listing
$offer = null;
try {
    $st = $pdo->prepare("
        SELECT o.*, l.title AS biz_name, l.user_id AS owner_id, l.slug AS biz_slug
        FROM offers o
        JOIN listings l ON l.id = o.listing_id
        WHERE o.id = ? AND l.user_id = ?
    ");
    $st->execute([$oid, $userId]);
    $offer = $st->fetch();
} catch (Exception $e) {}

if (!$offer) { redirect(SITE_URL . '/partner/offers'); }

$offerTitle = ($isFr && $offer['title_fr']) ? $offer['title_fr'] : $offer['title'];

// ── Metrics ───────────────────────────────────────────────────────────────────

// 1. Page views (from page_views / analytics table if present)
$viewCount = 0;
try {
    $vSt = $pdo->prepare("
        SELECT COUNT(*) FROM page_views
        WHERE entity_type = 'offer' AND entity_id = ?
    ");
    $vSt->execute([$oid]);
    $viewCount = (int)$vSt->fetchColumn();
} catch (Exception $e) {
    // page_views table may not exist or have different schema; fall back to 0
    try {
        $vSt2 = $pdo->prepare("
            SELECT COUNT(*) FROM analytics
            WHERE entity_type = 'offer' AND entity_id = ?
        ");
        $vSt2->execute([$oid]);
        $viewCount = (int)$vSt2->fetchColumn();
    } catch (Exception $e2) {}
}

// 2. Claims (from offer_claims)
$claimCount = 0;
try {
    $cSt = $pdo->prepare("SELECT COUNT(*) FROM offer_claims WHERE offer_id = ?");
    $cSt->execute([$oid]);
    $claimCount = (int)$cSt->fetchColumn();
} catch (Exception $e) {}

// Fallback: use redemption_count stored on offer row
if ($claimCount === 0 && isset($offer['redemption_count'])) {
    $claimCount = (int)$offer['redemption_count'];
}

// 3. Redemptions (codes actually used at point of sale, from offer_redemptions)
$redemptionCount = 0;
try {
    $rSt = $pdo->prepare("
        SELECT COUNT(*) FROM offer_redemptions or2
        JOIN discount_codes dc ON dc.id = or2.discount_code_id
        WHERE dc.offer_id = ?
    ");
    $rSt->execute([$oid]);
    $redemptionCount = (int)$rSt->fetchColumn();
} catch (Exception $e) {}

// 4. Active codes outstanding
$activeCodes = 0;
try {
    $acSt = $pdo->prepare("
        SELECT COUNT(*) FROM discount_codes
        WHERE offer_id = ? AND status = 'active'
    ");
    $acSt->execute([$oid]);
    $activeCodes = (int)$acSt->fetchColumn();
} catch (Exception $e) {}

// 5. Code type details
$codeType = $offer['code_type'] ?? 'unique';
$sharedCode = null;
if ($codeType === 'shared') {
    try {
        $scSt = $pdo->prepare("
            SELECT code, status FROM discount_codes
            WHERE offer_id = ? AND code_type = 'shared' LIMIT 1
        ");
        $scSt->execute([$oid]);
        $scRow = $scSt->fetch();
        if ($scRow) $sharedCode = $scRow['code'];
    } catch (Exception $e) {}
}

// 6. Recent claims (last 10, unique code type only)
$recentClaims = [];
if ($codeType === 'unique') {
    try {
        $rcSt = $pdo->prepare("
            SELECT oc.created_at, u.name AS user_name,
                   dc.code, dc.status AS code_status
            FROM offer_claims oc
            JOIN users u ON u.id = oc.user_id
            LEFT JOIN discount_codes dc ON dc.claim_id = oc.id
            WHERE oc.offer_id = ?
            ORDER BY oc.created_at DESC
            LIMIT 10
        ");
        $rcSt->execute([$oid]);
        $recentClaims = $rcSt->fetchAll();
    } catch (Exception $e) {}
}

// 7. Recent redemptions (last 10)
$recentRedemptions = [];
try {
    $rrSt = $pdo->prepare("
        SELECT or2.created_at, dc.code,
               su.name AS staff_name
        FROM offer_redemptions or2
        JOIN discount_codes dc ON dc.id = or2.discount_code_id
        LEFT JOIN users su ON su.id = or2.staff_user_id
        WHERE dc.offer_id = ?
        ORDER BY or2.created_at DESC
        LIMIT 10
    ");
    $rrSt->execute([$oid]);
    $recentRedemptions = $rrSt->fetchAll();
} catch (Exception $e) {}

// 8. Views over time (last 30 days bucketed by day) — best-effort
$viewsByDay = [];
try {
    $vdSt = $pdo->prepare("
        SELECT DATE(created_at) AS day, COUNT(*) AS cnt
        FROM page_views
        WHERE entity_type = 'offer' AND entity_id = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $vdSt->execute([$oid]);
    $viewsByDay = $vdSt->fetchAll();
} catch (Exception $e) {}

// Conversion rates
$conversionRate   = $viewCount > 0 ? round($claimCount / $viewCount * 100, 1) : null;
$redemptionRate   = $claimCount > 0 ? round($redemptionCount / $claimCount * 100, 1) : null;

// Status badge
$statusColors = [
    'pending'         => '#f59e0b',
    'scheduled'       => '#60a5fa',
    'active'          => '#4ade80',
    'paused'          => '#fbbf24',
    'suspended_admin' => '#dc2626',
    'rejected'        => '#f87171',
    'expired'         => '#6b7280',
    'archived'        => '#4b5563',
    'draft'           => '#8db8a8',
];
$statusColor = $statusColors[$offer['status']] ?? '#8db8a8';

$pageTitle = t('Offer Analytics', 'Analyse des Offres') . ' — ' . e($offerTitle);
require_once __DIR__ . '/../includes/header.php';
?>
<style>
:root {
    --oa-accent:#00A878; --oa-muted:#5a8878; --oa-border:rgba(0,168,120,0.15);
    --oa-card:rgba(0,0,0,0.22); --oa-card2:rgba(255,255,255,0.03);
}
.oa-wrap { max-width:1020px; margin:0 auto; padding:1.5rem 1rem 3rem; }
.oa-back  { display:inline-flex; align-items:center; gap:0.4rem; color:var(--oa-muted);
            font-size:0.82rem; text-decoration:none; margin-bottom:1rem; }
.oa-back:hover { color:#e8f5ee; }
.oa-header { display:flex; gap:1rem; align-items:flex-start; flex-wrap:wrap; margin-bottom:1.5rem; }
.oa-title  { font-family:'Fraunces',serif; font-weight:900; font-size:1.6rem; color:#f0faf5;
             flex:1; min-width:0; }
.oa-pill   { display:inline-block; padding:0.2rem 0.6rem; border-radius:99px;
             font-size:0.7rem; font-weight:700; color:#fff; white-space:nowrap; margin-top:0.35rem; }

/* Stat cards */
.oa-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:0.85rem; margin-bottom:1.5rem; }
.oa-stat  { background:var(--oa-card); border:1px solid var(--oa-border); border-radius:10px;
            padding:1rem 1.1rem; }
.oa-stat-label { font-size:0.68rem; font-weight:700; letter-spacing:0.06em; color:var(--oa-muted); text-transform:uppercase; }
.oa-stat-val   { font-size:2rem; font-weight:900; color:#e8f5ee; line-height:1.1; margin:0.25rem 0 0.1rem; }
.oa-stat-sub   { font-size:0.72rem; color:var(--oa-muted); }
.oa-stat.highlight { border-color:rgba(0,168,120,0.4); }
.oa-stat.highlight .oa-stat-val { color:var(--oa-accent); }

/* Code display */
.oa-code-box { background:rgba(0,168,120,0.06); border:1px solid rgba(0,168,120,0.2);
               border-radius:8px; padding:0.85rem 1rem; margin-bottom:1.5rem;
               display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; }
.oa-code-lbl { font-size:0.7rem; font-weight:700; color:var(--oa-muted); text-transform:uppercase; }
.oa-code-val { font-family:monospace; font-size:1.1rem; color:var(--oa-accent); font-weight:700; letter-spacing:0.08em; }

/* Chart */
.oa-chart-wrap { background:var(--oa-card); border:1px solid var(--oa-border); border-radius:10px;
                 padding:1.1rem 1.1rem 0.6rem; margin-bottom:1.5rem; }
.oa-chart-title { font-size:0.78rem; font-weight:700; color:var(--oa-muted); text-transform:uppercase;
                  letter-spacing:0.05em; margin-bottom:0.75rem; }
.oa-chart { display:flex; align-items:flex-end; gap:3px; height:80px; }
.oa-bar   { background:rgba(0,168,120,0.35); border-radius:2px 2px 0 0; flex:1;
            position:relative; min-width:4px; transition:background 0.15s; }
.oa-bar:hover { background:rgba(0,168,120,0.7); }
.oa-bar-tip { position:absolute; bottom:calc(100% + 4px); left:50%; transform:translateX(-50%);
              background:rgba(0,0,0,0.8); color:#e8f5ee; font-size:0.65rem; padding:2px 5px;
              border-radius:4px; white-space:nowrap; pointer-events:none; display:none; }
.oa-bar:hover .oa-bar-tip { display:block; }
.oa-chart-labels { display:flex; gap:3px; margin-top:4px; }
.oa-chart-label  { flex:1; font-size:0.55rem; color:var(--oa-muted); text-align:center; overflow:hidden; }
.oa-no-chart { text-align:center; padding:1.5rem; color:var(--oa-muted); font-size:0.82rem; }

/* Tables */
.oa-section { margin-bottom:1.5rem; }
.oa-section-title { font-size:0.78rem; font-weight:700; color:var(--oa-muted); text-transform:uppercase;
                    letter-spacing:0.05em; margin-bottom:0.65rem; }
.oa-table-wrap { overflow-x:auto; }
.oa-table { width:100%; border-collapse:collapse; font-size:0.8rem; }
.oa-table th { text-align:left; color:var(--oa-muted); font-weight:700; font-size:0.68rem;
               letter-spacing:0.04em; border-bottom:1px solid var(--oa-border); padding:0.4rem 0.6rem; }
.oa-table td { padding:0.6rem 0.6rem; border-bottom:1px solid rgba(0,168,120,0.05); color:#c4ddd4; }
.oa-table tr:last-child td { border-bottom:none; }
.oa-mono { font-family:monospace; color:var(--oa-accent); font-size:0.82rem; }
.oa-empty { text-align:center; color:var(--oa-muted); padding:1.5rem; font-size:0.8rem; }

/* Info row */
.oa-meta { display:flex; gap:1.5rem; flex-wrap:wrap; margin-bottom:1.5rem; font-size:0.79rem;
           color:var(--oa-muted); background:var(--oa-card2); border:1px solid var(--oa-border);
           border-radius:8px; padding:0.8rem 1rem; }
.oa-meta strong { color:#c4ddd4; }
</style>

<div class="oa-wrap">

  <!-- Back link -->
  <a href="<?= SITE_URL ?>/partner/offers" class="oa-back">← <?= t('Back to Offers', 'Retour aux Offres') ?></a>

  <!-- Header -->
  <div class="oa-header">
    <div>
      <div class="oa-title">📊 <?= e($offerTitle) ?></div>
      <span class="oa-pill" style="background:<?= $statusColor ?>;">
        <?= e(ucfirst(str_replace('_', ' ', $offer['status']))) ?>
      </span>
      <?php if ($codeType): ?>
        <span style="font-size:0.7rem;color:var(--oa-muted);margin-left:0.5rem;">
          <?= $codeType === 'shared' ? '🔗 ' . t('Shared Code','Code Partagé') : '🎫 ' . t('Unique Codes','Codes Uniques') ?>
        </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Offer meta -->
  <div class="oa-meta">
    <?php if ($offer['starts_at']): ?>
      <span><?= t('Start','Début') ?>: <strong><?= date('d M Y', strtotime($offer['starts_at'])) ?></strong></span>
    <?php endif; ?>
    <?php if ($offer['expires_at']): ?>
      <span><?= t('Expires','Expire') ?>: <strong><?= date('d M Y', strtotime($offer['expires_at'])) ?></strong></span>
    <?php else: ?>
      <span><?= t('No expiry','Sans expiration') ?></span>
    <?php endif; ?>
    <?php if ($offer['max_redemptions']): ?>
      <span><?= t('Cap','Limite') ?>: <strong><?= (int)$offer['max_redemptions'] ?></strong></span>
    <?php endif; ?>
    <?php if ($offer['discount_value']): ?>
      <span><?= t('Value','Valeur') ?>: <strong>
        <?= $offer['offer_type'] === 'percent_off' ? (int)$offer['discount_value'].'%' : number_format($offer['discount_value']).' XAF' ?>
      </strong></span>
    <?php endif; ?>
    <span><a href="<?= SITE_URL ?>/offer/<?= e($offer['slug']) ?>" target="_blank"
             style="color:var(--oa-accent);text-decoration:none;"><?= t('View public page →','Voir page publique →') ?></a></span>
  </div>

  <!-- Shared code display -->
  <?php if ($codeType === 'shared' && $sharedCode): ?>
    <div class="oa-code-box">
      <div>
        <div class="oa-code-lbl"><?= t('Shared Promo Code','Code Promo Partagé') ?></div>
        <div class="oa-code-val"><?= e($sharedCode) ?></div>
      </div>
      <button onclick="navigator.clipboard.writeText('<?= e($sharedCode) ?>').then(()=>this.textContent='✓ Copied!').catch(()=>{})"
              style="background:rgba(0,168,120,0.12);border:1px solid rgba(0,168,120,0.3);color:#00A878;
                     padding:0.35rem 0.8rem;border-radius:6px;font-size:0.78rem;cursor:pointer;">
        <?= t('Copy','Copier') ?>
      </button>
      <div style="font-size:0.72rem;color:var(--oa-muted);">
        <?= t('This code is public — anyone can use it.','Ce code est public — tout le monde peut l\'utiliser.') ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Stat cards -->
  <div class="oa-stats">
    <?php if ($viewCount > 0): ?>
    <div class="oa-stat">
      <div class="oa-stat-label">👁 <?= t('Page Views','Vues de Page') ?></div>
      <div class="oa-stat-val"><?= number_format($viewCount) ?></div>
      <div class="oa-stat-sub"><?= t('total impressions','impressions totales') ?></div>
    </div>
    <?php endif; ?>

    <div class="oa-stat highlight">
      <div class="oa-stat-label">🎟️ <?= t('Codes Claimed','Codes Réclamés') ?></div>
      <div class="oa-stat-val"><?= number_format($claimCount) ?></div>
      <div class="oa-stat-sub">
        <?php if ($offer['max_redemptions']): ?>
          <?= t('of','sur') ?> <?= (int)$offer['max_redemptions'] ?> <?= t('cap','limite') ?>
        <?php else: ?>
          <?= $codeType === 'shared' ? t('uses (shared code)','utilisations (code partagé)') : t('unique claims','réclamations uniques') ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="oa-stat">
      <div class="oa-stat-label">✅ <?= t('Redemptions','Utilisations') ?></div>
      <div class="oa-stat-val"><?= number_format($redemptionCount) ?></div>
      <div class="oa-stat-sub"><?= t('scanned at POS','scannés en caisse') ?></div>
    </div>

    <?php if ($codeType === 'unique'): ?>
    <div class="oa-stat">
      <div class="oa-stat-label">🔓 <?= t('Active Codes','Codes Actifs') ?></div>
      <div class="oa-stat-val"><?= number_format($activeCodes) ?></div>
      <div class="oa-stat-sub"><?= t('not yet redeemed','non encore utilisés') ?></div>
    </div>
    <?php endif; ?>

    <?php if ($conversionRate !== null): ?>
    <div class="oa-stat">
      <div class="oa-stat-label">📈 <?= t('Claim Rate','Taux de Réclamation') ?></div>
      <div class="oa-stat-val"><?= $conversionRate ?>%</div>
      <div class="oa-stat-sub"><?= t('views → claims','vues → réclamations') ?></div>
    </div>
    <?php endif; ?>

    <?php if ($redemptionRate !== null): ?>
    <div class="oa-stat">
      <div class="oa-stat-label">💸 <?= t('Redemption Rate','Taux d\'Usage') ?></div>
      <div class="oa-stat-val"><?= $redemptionRate ?>%</div>
      <div class="oa-stat-sub"><?= t('claims → redeemed','réclamés → utilisés') ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Views over time chart -->
  <?php if ($viewsByDay): ?>
  <div class="oa-chart-wrap">
    <div class="oa-chart-title">📅 <?= t('Daily Views — Last 30 Days','Vues Quotidiennes — 30 Derniers Jours') ?></div>
    <?php
      $maxV = max(array_column($viewsByDay, 'cnt'));
      // Build a map day→count for last 30 days
      $dayMap = [];
      foreach ($viewsByDay as $row) $dayMap[$row['day']] = (int)$row['cnt'];
      $days30 = [];
      for ($i = 29; $i >= 0; $i--) $days30[] = date('Y-m-d', strtotime("-{$i} days"));
    ?>
    <div class="oa-chart">
      <?php foreach ($days30 as $d):
        $cnt = $dayMap[$d] ?? 0;
        $pct = $maxV > 0 ? max(2, round($cnt / $maxV * 100)) : 2;
      ?>
        <div class="oa-bar" style="height:<?= $pct ?>%;">
          <?php if ($cnt > 0): ?><span class="oa-bar-tip"><?= date('d M', strtotime($d)) ?>: <?= $cnt ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="oa-chart-labels">
      <?php foreach ($days30 as $i => $d): ?>
        <span class="oa-chart-label"><?= ($i % 5 === 0) ? date('d/m', strtotime($d)) : '' ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent claims table (unique only) -->
  <?php if ($codeType === 'unique'): ?>
  <div class="oa-section">
    <div class="oa-section-title">🎫 <?= t('Recent Claims','Réclamations Récentes') ?></div>
    <?php if ($recentClaims): ?>
    <div class="oa-table-wrap">
      <table class="oa-table">
        <thead>
          <tr>
            <th><?= t('DATE','DATE') ?></th>
            <th><?= t('CUSTOMER','CLIENT') ?></th>
            <th><?= t('CODE','CODE') ?></th>
            <th><?= t('CODE STATUS','STATUT CODE') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentClaims as $row): ?>
            <tr>
              <td style="white-space:nowrap;"><?= date('d M Y H:i', strtotime($row['created_at'])) ?></td>
              <td><?= e($row['user_name']) ?></td>
              <td><span class="oa-mono"><?= e($row['code'] ?? '—') ?></span></td>
              <td>
                <?php
                  $cs = $row['code_status'] ?? '';
                  $csColor = $cs === 'used' ? '#4ade80' : ($cs === 'active' ? '#60a5fa' : '#6b7280');
                ?>
                <span style="color:<?= $csColor ?>;font-size:0.74rem;font-weight:700;">
                  <?= e(ucfirst($cs ?: '—')) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <p class="oa-empty"><?= t('No claims yet.','Aucune réclamation pour l\'instant.') ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Recent redemptions table -->
  <div class="oa-section">
    <div class="oa-section-title">✅ <?= t('Recent Redemptions','Utilisations Récentes') ?></div>
    <?php if ($recentRedemptions): ?>
    <div class="oa-table-wrap">
      <table class="oa-table">
        <thead>
          <tr>
            <th><?= t('DATE','DATE') ?></th>
            <th><?= t('CODE USED','CODE UTILISÉ') ?></th>
            <th><?= t('STAFF','PERSONNEL') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentRedemptions as $row): ?>
            <tr>
              <td style="white-space:nowrap;"><?= date('d M Y H:i', strtotime($row['created_at'])) ?></td>
              <td><span class="oa-mono"><?= e($row['code']) ?></span></td>
              <td><?= e($row['staff_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <p class="oa-empty"><?= t('No redemptions recorded yet.','Aucune utilisation enregistrée pour l\'instant.') ?></p>
    <?php endif; ?>
  </div>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
