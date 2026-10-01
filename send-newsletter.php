<?php
// send-newsletter.php — run weekly via cron:
// 0 9 * * 1 php /path/to/public_html/send-newsletter.php
// (every Monday at 9am)

require_once __DIR__ . '/includes/config.php';

// Only run via CLI or with secret key
if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== 'YOUR_CRON_SECRET_KEY') {
    http_response_code(403);
    die('Forbidden');
}

$since = date('Y-m-d H:i:s', strtotime('-7 days'));

// Get new approved listings this week
$newListings = db()->query("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon, loc.name_en AS loc_en
    FROM listings l
    JOIN categories c ON c.id=l.category_id
    JOIN locations loc ON loc.id=l.location_id
    WHERE l.status='approved' AND l.created_at >= '$since'
    ORDER BY l.featured DESC, l.created_at DESC
    LIMIT 10
")->fetchAll();

if (!$newListings) {
    echo "No new listings this week — newsletter skipped.\n";
    exit;
}

// Get confirmed subscribers
$subs = db()->query("SELECT * FROM subscribers WHERE confirmed=1 AND active=1")->fetchAll();
$locs = db()->query("SELECT * FROM locations")->fetchAll();
$locMap = array_column($locs, 'name_en', 'id');

echo "Sending newsletter to " . count($subs) . " subscribers...\n";
$sent = 0;

foreach ($subs as $sub) {
    // Filter by location if subscriber has one
    $listings = $sub['location_id']
        ? array_filter($newListings, fn($l) => $l['location_id'] == $sub['location_id'])
        : $newListings;
    $listings = array_values($listings);
    if (!$listings) continue;

    $unsubUrl = SITE_URL . '/subscribe?action=unsubscribe&token=' . $sub['token'];
    $greeting = $sub['name'] ? t('Hi ','Bonjour ') . $sub['name'] . ',' : t('Hello!','Bonjour !');
    $locLabel = $sub['location_id'] ? ($locMap[$sub['location_id']] ?? 'Cameroon') : 'Cameroon';

    $listingsHtml = '';
    foreach ($listings as $l) {
        $url = SITE_URL . '/listing/' . $l['slug'];
        $listingsHtml .= '
        <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:1.25rem;margin-bottom:1rem;">
          <div style="display:flex;gap:0.75rem;align-items:flex-start;">
            <span style="font-size:1.5rem;">' . $l['cat_icon'] . '</span>
            <div>
              <a href="' . $url . '" style="color:#F5C842;font-family:Georgia,serif;font-weight:700;font-size:1rem;text-decoration:none;">' . htmlspecialchars($l['title']) . '</a>
              <div style="font-size:0.72rem;color:#00A878;text-transform:uppercase;letter-spacing:0.08em;margin:0.15rem 0;">' . htmlspecialchars($l['cat_en']) . '</div>
              <div style="font-size:0.8rem;color:rgba(255,255,255,0.5);">' . htmlspecialchars(mb_substr($l['description'] ?? '', 0, 100)) . '...</div>
              <div style="font-size:0.75rem;color:rgba(255,255,255,0.4);margin-top:0.4rem;">📍 ' . htmlspecialchars($l['loc_en']) . '</div>
            </div>
          </div>
          <a href="' . $url . '" style="display:inline-block;margin-top:0.75rem;font-size:0.78rem;color:#00A878;">' . t('View listing →','Voir l\'annonce →') . '</a>
        </div>';
    }

    $body = '
    <h2 style="color:#fff;font-family:Georgia,serif;font-weight:900;">' . t('This week on 237Biz','Cette semaine sur 237Biz') . '</h2>
    <p style="color:rgba(255,255,255,0.6);">' . $greeting . '</p>
    <p style="color:rgba(255,255,255,0.6);">' . t('Here are the new businesses added to','Voici les nouvelles entreprises ajoutées à') . ' <strong style="color:#fff;">' . $locLabel . '</strong> ' . t('this week:','cette semaine :') . '</p>
    ' . $listingsHtml . '
    <div style="text-align:center;margin-top:1.5rem;">
      <a href="' . SITE_URL . '/listings" style="display:inline-block;background:#00A878;color:#fff;padding:0.75rem 2rem;border-radius:5px;text-decoration:none;font-weight:500;">' . t('Browse All Businesses','Voir toutes les entreprises') . '</a>
    </div>
    <div style="text-align:center;margin-top:1rem;">
      <a href="' . SITE_URL . '/add-listing" style="font-size:0.83rem;color:#F5C842;text-decoration:none;">+ ' . t('List your business free','Listez votre entreprise gratuitement') . '</a>
    </div>
    <hr style="border:none;border-top:1px solid rgba(255,255,255,0.08);margin:1.5rem 0;">
    <p style="font-size:0.72rem;color:rgba(255,255,255,0.3);text-align:center;">
      ' . t('You\'re receiving this because you subscribed on 237Biz.net.','Vous recevez ceci car vous vous êtes abonné sur 237Biz.net.') . '<br>
      <a href="' . $unsubUrl . '" style="color:rgba(255,255,255,0.3);">' . t('Unsubscribe','Se désabonner') . '</a>
    </p>';

    $result = sendMail(
        $sub['email'],
        t('🇨🇲 New businesses in ','🇨🇲 Nouvelles entreprises à ') . $locLabel . ' — 237Biz',
        $body
    );
    if ($result) $sent++;
    usleep(100000); // 100ms delay between emails
}

echo "Newsletter sent to $sent subscribers.\n";
