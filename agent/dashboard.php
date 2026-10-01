<?php
/**
 * agent/dashboard.php — 237Biz Sales Agent Portal
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/referral-helpers.php';
requireStaff();
$u   = currentStaffUser();
$uid = (int)$u['id'];
$pdo = db();

// ── Commission summary ────────────────────────────────────────────────────
$commSummary = getUserCommissionSummary($uid);

// ── Referral link ─────────────────────────────────────────────────────────
$linkRow = $pdo->prepare("SELECT * FROM referral_links WHERE user_id=?");
$linkRow->execute([$uid]);
$link = $linkRow->fetch();

// Auto-create referral link if agent doesn't have one yet
if (!$link) {
    try {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $u['name']));
        $base = substr($base, 0, 10) ?: 'agent';
        $code = $base; $i = 1;
        while (true) {
            $chk = $pdo->prepare("SELECT id FROM referral_links WHERE code=?");
            $chk->execute([$code]);
            if (!$chk->fetch()) break;
            $code = $base . $i++;
        }
        $pdo->prepare("INSERT INTO referral_links (user_id, programme_id, code) VALUES (?, 1, ?)")
            ->execute([$uid, $code]);
        $linkRow = $pdo->prepare("SELECT * FROM referral_links WHERE user_id=?");
        $linkRow->execute([$uid]);
        $link = $linkRow->fetch();
    } catch (Exception $e) { $link = null; }
}

// ── Assigned leads ────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$whereStatus  = ($statusFilter !== 'all') ? "AND aa.status='{$statusFilter}'" : '';
$leads = $pdo->prepare("
    SELECT aa.*, l.title AS listing_title, l.slug AS listing_slug,
           loc.name_en AS city, l.phone AS listing_phone, l.whatsapp AS listing_whatsapp
    FROM agent_assignments aa
    JOIN listings l ON l.id = aa.listing_id
    LEFT JOIN locations loc ON loc.id = l.location_id
    WHERE aa.agent_id=? {$whereStatus}
    ORDER BY aa.status='follow_up' DESC, aa.next_followup ASC, aa.assigned_at DESC
");
$leads->execute([$uid]);
$leads = $leads->fetchAll();

// Lead counts by status
$counts = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM agent_assignments WHERE agent_id=? GROUP BY status");
$counts->execute([$uid]);
$statusCounts = [];
foreach ($counts->fetchAll() as $r) $statusCounts[$r['status']] = (int)$r['cnt'];

// Due follow-ups today
$dueToday = $pdo->prepare("SELECT COUNT(*) FROM agent_assignments WHERE agent_id=? AND next_followup <= CURDATE() AND status NOT IN ('converted','lost')");
$dueToday->execute([$uid]);
$dueCount = (int)$dueToday->fetchColumn();

// Commission ledger
$commissions = $pdo->prepare("SELECT * FROM commissions WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$commissions->execute([$uid]);
$commissions = $commissions->fetchAll();

// ── Referral conversion breakdown ─────────────────────────────────────────
$convBreakdown = ['free_listing'=>['cnt'=>0,'xaf'=>0],'featured_listing'=>['cnt'=>0,'xaf'=>0],'upgrade'=>['cnt'=>0,'xaf'=>0]];
try {
    $cb = $pdo->prepare("
        SELECT rc.conversion_type, COUNT(*) AS cnt, SUM(rc.commission_xaf) AS xaf
        FROM referral_conversions rc
        JOIN referral_links rl ON rl.id = rc.link_id
        WHERE rl.user_id = ?
        GROUP BY rc.conversion_type
    ");
    $cb->execute([$uid]);
    foreach ($cb->fetchAll() as $row) {
        if (isset($convBreakdown[$row['conversion_type']])) {
            $convBreakdown[$row['conversion_type']] = ['cnt'=>(int)$row['cnt'],'xaf'=>(int)$row['xaf']];
        }
    }
} catch (Exception $e) {}

// Update lead status (inline AJAX-style POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_lead') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['notes'] ?? '');
        $followup = trim($_POST['next_followup'] ?? '') ?: null;

        // Verify this lead belongs to this agent
        $check = $pdo->prepare("SELECT id FROM agent_assignments WHERE id=? AND agent_id=?");
        $check->execute([$aid, $uid]);
        if ($check->fetch() && in_array($status, ['assigned','contacted','follow_up','converted','lost'])) {
            $pdo->prepare("UPDATE agent_assignments SET status=?, notes=COALESCE(NULLIF(?,''),notes), next_followup=? WHERE id=?")
                ->execute([$status, $notes, $followup, $aid]);
            flash('success', 'Lead updated.');
        }
        redirect(SITE_URL . '/agent/dashboard?status=' . $statusFilter);
    }
}

$prog = $pdo->query("SELECT minimum_payout FROM referral_programmes WHERE id=1 LIMIT 1")->fetch();
$minPayout = (int)($prog['minimum_payout'] ?? 5000);
$refUrl = $link ? (SITE_URL . '/r/' . $link['code']) : null;

$pageTitle = 'My Dashboard — 237Biz Agent';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.agent-stat-card { background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 20px; }
.agent-stat-card .val { font-size:1.8rem;font-weight:900;font-family:'Fraunces',serif; }
.agent-stat-card .lbl { font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px; }
.lead-card { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:11px;padding:16px 18px;margin-bottom:8px; }
.lead-card.due { border-color:rgba(252,209,22,0.35); }
.ref-box { background:rgba(0,168,120,0.06);border:1px solid rgba(0,168,120,0.25);border-radius:12px;padding:18px 22px; }
.ref-url { font-family:monospace;font-size:14px;color:#00A878;background:rgba(0,0,0,0.2);border-radius:7px;padding:8px 14px;word-break:break-all;display:block;margin:10px 0; }
</style>

<div style="height:65px;"></div>

<div class="page-header">
  <div class="container">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,1.9rem);">👔 Agent Dashboard</h1>
    <p style="color:rgba(255,255,255,0.6);margin:4px 0 0;">Welcome back, <?= e($u['name']) ?></p>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <!-- Stats row -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:1.5rem;">
    <div class="agent-stat-card">
      <div class="lbl">Assigned leads</div>
      <div class="val"><?= array_sum($statusCounts) ?></div>
    </div>
    <div class="agent-stat-card" style="<?= $dueCount?'border-color:rgba(252,209,22,0.4);':'' ?>">
      <div class="lbl">Follow-ups due</div>
      <div class="val" style="<?= $dueCount?'color:#fcd116':'' ?>"><?= $dueCount ?></div>
    </div>
    <div class="agent-stat-card">
      <div class="lbl">Converted</div>
      <div class="val" style="color:#00A878;"><?= $statusCounts['converted'] ?? 0 ?></div>
    </div>
    <div class="agent-stat-card">
      <div class="lbl">Approved earnings</div>
      <div class="val" style="font-size:1.2rem;color:#fcd116;"><?= formatXaf($commSummary['approved_xaf']) ?></div>
    </div>
    <div class="agent-stat-card">
      <div class="lbl">Total paid out</div>
      <div class="val" style="font-size:1.2rem;color:#8ab4f8;"><?= formatXaf($commSummary['paid_xaf']) ?></div>
    </div>
    <?php if ($link): ?>
    <div class="agent-stat-card">
      <div class="lbl">Link clicks</div>
      <div class="val"><?= (int)$link['clicks'] ?> <span style="font-size:1rem;font-weight:400;color:var(--muted);">/ <?= (int)$link['unique_clicks'] ?> unique</span></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Referral conversion breakdown -->
  <?php $totalConv = array_sum(array_column($convBreakdown,'cnt')); ?>
  <?php if ($totalConv > 0 || $link): ?>
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:16px 20px;margin-bottom:1.5rem;">
    <h3 style="font-size:13.5px;font-weight:700;margin:0 0 12px;">🔗 Listings Referred via Your Link</h3>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
      <div style="background:rgba(255,255,255,0.04);border-radius:9px;padding:12px 14px;text-align:center;">
        <div style="font-size:1.4rem;font-weight:900;font-family:'Fraunces',serif;"><?= $convBreakdown['free_listing']['cnt'] ?></div>
        <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">🆓 Free listings</div>
        <?php if ($convBreakdown['free_listing']['xaf']): ?>
        <div style="font-size:11px;color:#00A878;margin-top:3px;"><?= formatXaf($convBreakdown['free_listing']['xaf']) ?></div>
        <?php endif; ?>
      </div>
      <div style="background:rgba(245,200,66,0.07);border-radius:9px;padding:12px 14px;text-align:center;border:1px solid rgba(245,200,66,0.15);">
        <div style="font-size:1.4rem;font-weight:900;font-family:'Fraunces',serif;color:#fcd116;"><?= $convBreakdown['featured_listing']['cnt'] ?></div>
        <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">⭐ Featured listings</div>
        <?php if ($convBreakdown['featured_listing']['xaf']): ?>
        <div style="font-size:11px;color:#00A878;margin-top:3px;"><?= formatXaf($convBreakdown['featured_listing']['xaf']) ?></div>
        <?php endif; ?>
      </div>
      <div style="background:rgba(0,168,120,0.06);border-radius:9px;padding:12px 14px;text-align:center;border:1px solid rgba(0,168,120,0.15);">
        <div style="font-size:1.4rem;font-weight:900;font-family:'Fraunces',serif;color:#00A878;"><?= $convBreakdown['upgrade']['cnt'] ?></div>
        <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">⬆️ Upgrades</div>
        <?php if ($convBreakdown['upgrade']['xaf']): ?>
        <div style="font-size:11px;color:#00A878;margin-top:3px;"><?= formatXaf($convBreakdown['upgrade']['xaf']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start;" class="agent-layout">

    <!-- Left: leads -->
    <div>
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:12px;">📋 My Leads</h2>

      <!-- Status filter tabs -->
      <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
        <?php
        $allStatuses = ['all'=>'All','assigned'=>'Assigned','contacted'=>'Contacted','follow_up'=>'Follow-up','converted'=>'Converted','lost'=>'Lost'];
        foreach ($allStatuses as $sv => $sl):
          $cnt = $sv === 'all' ? array_sum($statusCounts) : ($statusCounts[$sv] ?? 0);
        ?>
        <a href="?status=<?= $sv ?>"
           style="padding:5px 12px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;<?= $statusFilter===$sv?'background:rgba(0,168,120,0.2);color:#00A878;border:1px solid rgba(0,168,120,0.4);':'color:var(--muted);border:1px solid rgba(255,255,255,0.1);' ?>">
          <?= $sl ?> <?= $cnt ? "({$cnt})" : '' ?>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (empty($leads)): ?>
      <p style="color:var(--muted);padding:20px 0;">No leads in this category.</p>
      <?php else: ?>
      <?php foreach ($leads as $lead):
        $isDue = $lead['next_followup'] && $lead['next_followup'] <= date('Y-m-d') && !in_array($lead['status'],['converted','lost']);
      ?>
      <div class="lead-card <?= $isDue?'due':'' ?>">
        <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
          <div style="flex:1;min-width:180px;">
            <div style="font-weight:700;font-size:14px;"><?= e($lead['listing_title']) ?></div>
            <div style="font-size:12px;color:var(--muted);margin-bottom:6px;"><?= e($lead['city']) ?></div>
            <?php if ($lead['notes']): ?>
            <p style="font-size:12.5px;color:rgba(255,255,255,0.65);margin:0 0 6px;font-style:italic;">"<?= e(mb_substr($lead['notes'],0,100)) ?>"</p>
            <?php endif; ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <?php if ($lead['listing_whatsapp']): ?>
              <a href="https://wa.me/<?= preg_replace('/\D/','',$lead['listing_whatsapp']) ?>" target="_blank"
                 style="font-size:12px;padding:3px 10px;background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.3);color:#25d366;border-radius:6px;text-decoration:none;">💬 WhatsApp</a>
              <?php elseif ($lead['listing_phone']): ?>
              <a href="tel:<?= e($lead['listing_phone']) ?>" style="font-size:12px;color:var(--green);">📞 <?= e($lead['listing_phone']) ?></a>
              <?php endif; ?>
              <a href="<?= SITE_URL ?>/listing/<?= e($lead['listing_slug']) ?>" target="_blank" style="font-size:12px;color:var(--muted);">View listing →</a>
            </div>
            <?php if ($lead['next_followup']): ?>
            <div style="font-size:11.5px;margin-top:6px;color:<?= $isDue?'#fcd116':'var(--muted)' ?>;">
              <?= $isDue?'⚠️ Due today:':'📅' ?> Follow up <?= date('d M Y',strtotime($lead['next_followup'])) ?>
            </div>
            <?php endif; ?>
          </div>

          <!-- Update form -->
          <form method="POST" style="min-width:220px;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update_lead">
            <input type="hidden" name="assignment_id" value="<?= $lead['id'] ?>">
            <select name="status" style="width:100%;padding:6px 8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-size:13px;font-family:inherit;margin-bottom:6px;">
              <?php foreach (['assigned','contacted','follow_up','converted','lost'] as $s): ?>
              <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="date" name="next_followup" value="<?= e($lead['next_followup'] ?? '') ?>"
                   style="width:100%;padding:5px 8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-size:12.5px;margin-bottom:6px;">
            <textarea name="notes" rows="2" placeholder="Add a note…"
                      style="width:100%;padding:6px 8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-size:12.5px;font-family:inherit;resize:vertical;margin-bottom:6px;"></textarea>
            <button type="submit" style="width:100%;background:rgba(0,168,120,0.2);border:1px solid rgba(0,168,120,0.4);color:#00A878;border-radius:6px;padding:6px;font-size:12.5px;cursor:pointer;font-family:inherit;font-weight:600;">Save</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Right: referral link + commissions -->
    <div>
      <!-- Referral link -->
      <?php if ($link): ?>
      <div class="ref-box" style="margin-bottom:16px;">
        <h3 style="margin:0 0 6px;font-size:14px;font-weight:700;">🔗 Your Referral Link</h3>
        <p style="font-size:12.5px;color:var(--muted);margin-bottom:8px;">Share this link — you earn commission for every featured listing that comes through it.</p>
        <span class="ref-url"><?= SITE_URL ?>/r/<?= e($link['code']) ?></span>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/r/<?= e($link['code']) ?>').then(()=>this.textContent='✅ Copied!')"
                  style="background:rgba(0,168,120,0.2);border:1px solid rgba(0,168,120,0.4);color:#00A878;border-radius:7px;padding:7px 14px;font-size:12.5px;cursor:pointer;font-family:inherit;font-weight:600;">📋 Copy Link</button>
          <a href="https://wa.me/?text=<?= urlencode('Find Cameroon businesses on 237Biz — ' . SITE_URL . '/r/' . $link['code']) ?>" target="_blank"
             style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.3);color:#25d366;border-radius:7px;padding:7px 14px;font-size:12.5px;text-decoration:none;font-weight:600;">💬 Share on WhatsApp</a>
        </div>
        <div style="display:flex;gap:14px;margin-top:12px;">
          <div><div style="font-size:1.1rem;font-weight:900;font-family:'Fraunces',serif;"><?= (int)$link['clicks'] ?></div><div style="font-size:11px;color:var(--muted);">Total clicks</div></div>
          <div><div style="font-size:1.1rem;font-weight:900;font-family:'Fraunces',serif;"><?= (int)$link['unique_clicks'] ?></div><div style="font-size:11px;color:var(--muted);">Unique clicks</div></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Commission summary -->
      <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 18px;margin-bottom:12px;">
        <h3 style="margin:0 0 12px;font-size:14px;font-weight:700;">💰 Commissions</h3>
        <?php
        $minOk = $commSummary['approved_xaf'] >= $minPayout;
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
          <div style="background:rgba(252,209,22,0.08);border-radius:8px;padding:10px 12px;">
            <div style="font-size:11px;color:var(--muted);">Pending</div>
            <div style="font-size:1rem;font-weight:700;color:#fcd116;"><?= formatXaf($commSummary['pending_xaf']) ?></div>
          </div>
          <div style="background:rgba(0,168,120,0.08);border-radius:8px;padding:10px 12px;">
            <div style="font-size:11px;color:var(--muted);">Approved</div>
            <div style="font-size:1rem;font-weight:700;color:#00A878;"><?= formatXaf($commSummary['approved_xaf']) ?></div>
          </div>
          <div style="background:rgba(138,180,248,0.08);border-radius:8px;padding:10px 12px;">
            <div style="font-size:11px;color:var(--muted);">Paid out</div>
            <div style="font-size:1rem;font-weight:700;color:#8ab4f8;"><?= formatXaf($commSummary['paid_xaf']) ?></div>
          </div>
          <div style="background:rgba(255,255,255,0.04);border-radius:8px;padding:10px 12px;">
            <div style="font-size:11px;color:var(--muted);">Min payout</div>
            <div style="font-size:1rem;font-weight:700;color:<?= $minOk?'#00A878':'rgba(255,255,255,0.4)' ?>;"><?= formatXaf($minPayout) ?></div>
          </div>
        </div>
        <?php if ($minOk): ?>
        <div style="background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.3);border-radius:8px;padding:10px 12px;font-size:13px;color:#00A878;">
          ✅ You have reached the minimum payout threshold. Contact your admin to request payment.
        </div>
        <?php else: ?>
        <div style="font-size:12px;color:var(--muted);">
          <?= formatXaf($minPayout - $commSummary['approved_xaf']) ?> more needed to reach minimum payout.
        </div>
        <?php endif; ?>
      </div>

      <!-- Recent commission entries -->
      <?php if ($commissions): ?>
      <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 18px;">
        <h3 style="margin:0 0 10px;font-size:14px;font-weight:700;">Recent Earnings</h3>
        <?php foreach (array_slice($commissions,0,8) as $c): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(255,255,255,0.05);gap:8px;">
          <div>
            <div style="font-size:12.5px;font-weight:600;"><?= str_replace('_',' ',ucfirst($c['type'])) ?></div>
            <div style="font-size:11.5px;color:var(--muted);"><?= date('d M Y',strtotime($c['created_at'])) ?></div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:13px;font-weight:700;color:#00A878;">+<?= formatXaf((int)$c['amount_xaf']) ?></div>
            <?= commissionStatusBadge($c['status']) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</section>

<style>
@media(max-width:700px){.agent-layout{grid-template-columns:1fr!important;}}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>