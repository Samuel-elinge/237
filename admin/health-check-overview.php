<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();
require_once __DIR__ . '/../includes/health-check-questions.php';

$totalStmt = $pdo->query("SELECT
        COUNT(*) AS total,
        AVG(total_score) AS avg_score,
        SUM(opportunity_score='high') AS opp_high,
        SUM(opportunity_score='medium') AS opp_medium,
        SUM(opportunity_score='low') AS opp_low,
        SUM(event_interest IN ('definitely','probably')) AS event_yes
    FROM health_assessments WHERE status = 'completed'");
$totals = $totalStmt->fetch(PDO::FETCH_ASSOC);
$total = (int) ($totals['total'] ?? 0);

$rowsStmt = $pdo->query("SELECT answers_json, city, business_category FROM health_assessments WHERE status = 'completed'");
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

// Tally presence + challenges + needs + discovery channels in PHP (dataset is small — sales-research scale, not millions of rows)
$presence = ['has_website'=>0,'social_facebook'=>0,'social_tiktok'=>0,'social_instagram'=>0,'social_whatsapp_business'=>0,'google_presence'=>0,'whatsapp_contact'=>0];
$challengeTally = [];
$needsTally = [];
$channelTally = [];
$cityTally = [];
$categoryTally = [];

foreach ($rows as $r) {
    $ans = $r['answers_json'] ? json_decode($r['answers_json'], true) : [];
    if (($ans['has_website'] ?? '') === 'yes') $presence['has_website']++;
    if (($ans['social_facebook'] ?? '') === 'yes') $presence['social_facebook']++;
    if (($ans['social_tiktok'] ?? '') === 'yes') $presence['social_tiktok']++;
    if (($ans['social_instagram'] ?? '') === 'yes') $presence['social_instagram']++;
    if (($ans['social_whatsapp_business'] ?? '') === 'yes') $presence['social_whatsapp_business']++;
    if (($ans['google_presence'] ?? '') === 'yes') $presence['google_presence']++;
    if (($ans['whatsapp_contact'] ?? '') === 'yes') $presence['whatsapp_contact']++;

    foreach (($ans['biggest_challenge'] ?? []) as $c) $challengeTally[$c] = ($challengeTally[$c] ?? 0) + 1;
    foreach (($ans['marketing_needs'] ?? []) as $n) $needsTally[$n] = ($needsTally[$n] ?? 0) + 1;
    foreach (($ans['discovery_channels'] ?? []) as $c) $channelTally[$c] = ($channelTally[$c] ?? 0) + 1;

    if (!empty($r['city'])) $cityTally[$r['city']] = ($cityTally[$r['city']] ?? 0) + 1;
    if (!empty($r['business_category'])) $categoryTally[$r['business_category']] = ($categoryTally[$r['business_category']] ?? 0) + 1;
}
arsort($challengeTally); arsort($needsTally); arsort($channelTally); arsort($cityTally); arsort($categoryTally);

function pct($n, $total) { return $total > 0 ? round(($n / $total) * 100) : 0; }

$challengeLabels = ['finding_customers'=>'Finding new customers','getting_noticed'=>'Getting noticed online','competition'=>'Competition',
    'social_marketing'=>'Social media marketing','ad_costs'=>'Advertising costs','website'=>'Website','reviews'=>'Getting reviews',
    'retention'=>'Customer retention','time'=>'Lack of time','dont_know'=>"Don't know where to start",'other'=>'Other'];
$needsLabels = ['website'=>'Better website','social'=>'Better social media','customers'=>'More customers','google'=>'Better Google visibility',
    'advertising'=>'Online advertising','photos'=>'Better photos/videos','reviews'=>'Customer reviews','booking'=>'Online booking',
    'catalogue'=>'Online catalogue','listing'=>'Business listing','whatsapp_marketing'=>'WhatsApp marketing','ai'=>'AI tools','not_sure'=>'Not sure'];
$pageTitle = 'Market Overview — Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  :root{--brand:#0f8a5f;--bg:#f6f8f7;--card:#fff;--text:#1c2b26;--muted:#6b7b75;--border:#e2e8e5;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);}
  .wrap{max-width:960px;margin:0 auto;padding:20px 16px 60px;}
  h1{font-size:22px;margin-bottom:4px;}
  .subtitle{color:var(--muted);font-size:13.5px;margin-bottom:22px;}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:24px;}
  .stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;}
  .stat .num{font-size:26px;font-weight:800;color:var(--brand);}
  .stat .lbl{font-size:12px;color:var(--muted);margin-top:2px;}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px;}
  .card h3{margin-top:0;font-size:15px;}
  .bar-row{display:flex;align-items:center;gap:10px;margin-bottom:9px;font-size:13px;}
  .bar-row .label{width:190px;flex-shrink:0;color:var(--muted);}
  .bar-row .track{flex:1;height:8px;background:#eef1f0;border-radius:4px;overflow:hidden;}
  .bar-row .fill{height:100%;background:var(--brand);}
  .bar-row .pctval{width:40px;text-align:right;font-weight:600;}
  .two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
  @media (max-width:640px){ .two-col{grid-template-columns:1fr;} .bar-row .label{width:130px;} }
</style>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📊 Market Overview</h1>
</div></div>

<section class="page-section"><div class="container">
<div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
  <a href="<?= SITE_URL ?>/agent-health-checks.php" class="filter-tab">📋 Assessments</a>
  <a href="<?= SITE_URL ?>/admin/health-check-overview.php" class="filter-tab active">📊 Market Overview</a>
  <a href="<?= SITE_URL ?>/admin/health-check-questions.php" class="filter-tab">⚙️ Questions</a>
  <a href="<?= SITE_URL ?>/admin/health-check-staff.php" class="filter-tab">👥 Sales Staff</a>
</div>

<div class="wrap" style="padding:0;max-width:none;">
  <div class="subtitle">237biz Digital Health Check — aggregate insight from <?php echo $total; ?> completed assessments</div>

  <div class="grid">
    <div class="stat"><div class="num"><?php echo $total; ?></div><div class="lbl">Businesses Assessed</div></div>
    <div class="stat"><div class="num"><?php echo $totals['avg_score'] !== null ? round($totals['avg_score']) . '%' : '—'; ?></div><div class="lbl">Avg Digital Score</div></div>
    <div class="stat"><div class="num"><?php echo (int)($totals['opp_high'] ?? 0); ?></div><div class="lbl">🔥 High Opportunity</div></div>
    <div class="stat"><div class="num"><?php echo pct($totals['event_yes'] ?? 0, $total); ?>%</div><div class="lbl">Would Attend Event</div></div>
  </div>

  <div class="two-col">
    <div class="card">
      <h3>Online Presence</h3>
      <?php
      $presenceLabels = ['has_website'=>'Have a website','social_facebook'=>'Use Facebook','social_tiktok'=>'Use TikTok',
          'social_instagram'=>'Use Instagram','social_whatsapp_business'=>'Use WhatsApp Business','google_presence'=>'On Google',
          'whatsapp_contact'=>'WhatsApp contact enabled'];
      foreach ($presenceLabels as $key => $label):
          $p = pct($presence[$key], $total); ?>
        <div class="bar-row"><div class="label"><?php echo $label; ?></div>
          <div class="track"><div class="fill" style="width:<?php echo $p; ?>%"></div></div>
          <div class="pctval"><?php echo $p; ?>%</div></div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h3>Biggest Problems</h3>
      <?php $i=0; foreach ($challengeTally as $key => $count): if ($i++ >= 6) break;
          $p = pct($count, $total); ?>
        <div class="bar-row"><div class="label"><?php echo $challengeLabels[$key] ?? $key; ?></div>
          <div class="track"><div class="fill" style="width:<?php echo $p; ?>%"></div></div>
          <div class="pctval"><?php echo $p; ?>%</div></div>
      <?php endforeach; if (!$challengeTally): ?><div style="color:var(--muted);font-size:13px;">No data yet.</div><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h3>Top Requested Help (what would help their business most)</h3>
    <?php $i=0; foreach ($needsTally as $key => $count): if ($i++ >= 8) break;
        $p = pct($count, $total); ?>
      <div class="bar-row"><div class="label"><?php echo $needsLabels[$key] ?? $key; ?></div>
        <div class="track"><div class="fill" style="width:<?php echo $p; ?>%"></div></div>
        <div class="pctval"><?php echo $p; ?>%</div></div>
    <?php endforeach; if (!$needsTally): ?><div style="color:var(--muted);font-size:13px;">No data yet.</div><?php endif; ?>
  </div>

  <div class="two-col">
    <div class="card">
      <h3>By City</h3>
      <?php foreach ($cityTally as $city => $count): $p = pct($count, $total); ?>
        <div class="bar-row"><div class="label"><?php echo htmlspecialchars($city); ?></div>
          <div class="track"><div class="fill" style="width:<?php echo $p; ?>%"></div></div>
          <div class="pctval"><?php echo $count; ?></div></div>
      <?php endforeach; if (!$cityTally): ?><div style="color:var(--muted);font-size:13px;">No data yet.</div><?php endif; ?>
    </div>
    <div class="card">
      <h3>By Category</h3>
      <?php $i=0; foreach ($categoryTally as $cat => $count): if ($i++ >= 8) break; $p = pct($count, $total); ?>
        <div class="bar-row"><div class="label"><?php echo htmlspecialchars($cat); ?></div>
          <div class="track"><div class="fill" style="width:<?php echo $p; ?>%"></div></div>
          <div class="pctval"><?php echo $count; ?></div></div>
      <?php endforeach; if (!$categoryTally): ?><div style="color:var(--muted);font-size:13px;">No data yet.</div><?php endif; ?>
    </div>
  </div>
</div>
</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
