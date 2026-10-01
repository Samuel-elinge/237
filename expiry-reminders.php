<?php
// expiry-reminders.php — run daily via cron:
// 0 8 * * * php /path/to/public_html/expiry-reminders.php

require_once __DIR__ . '/includes/config.php';

if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== 'YOUR_CRON_SECRET_KEY') {
    http_response_code(403); die('Forbidden');
}

// ── EXPIRE FEATURED LISTINGS ──────────────────────────────
$expired = db()->query("
    SELECT l.*, u.email, u.name AS user_name
    FROM listings l
    JOIN users u ON u.id=l.user_id
    WHERE l.featured=1 AND l.featured_expires_at IS NOT NULL
    AND l.featured_expires_at < NOW()
")->fetchAll();

foreach ($expired as $l) {
    db()->prepare("UPDATE listings SET featured=0, verified=0 WHERE id=?")->execute([$l['id']]);
    sendMail($l['email'],
        t('Your featured listing has expired — 237Biz','Votre annonce vedette a expiré — 237Biz'),
        '<h2 style="color:#fff;font-family:Georgia,serif;">⭐ '.t('Featured Listing Expired','Annonce Vedette Expirée').'</h2>
         <p style="color:rgba(255,255,255,0.7);">'.t('Hi','Bonjour').' '.e($l['user_name']).',</p>
         <p style="color:rgba(255,255,255,0.7);">'.t('Your featured listing for','Votre annonce vedette pour').' <strong>'.e($l['title']).'</strong> '.t('has expired. Renew now to keep appearing at the top of search results.','a expiré. Renouvelez maintenant pour continuer à apparaître en tête des résultats.').'</p>
         <a href="'.SITE_URL.'/upgrade-listing?listing_id='.$l['id'].'" style="display:inline-block;margin:1rem 0;background:#F5C842;color:#0D1F16;padding:0.85rem 2rem;border-radius:5px;text-decoration:none;font-weight:500;">⭐ '.t('Renew Featured Listing','Renouveler l\'Annonce Vedette').'</a>'
    );
    echo "Expired: " . $l['title'] . "\n";
}

// ── SEND 7-DAY EXPIRY WARNINGS ────────────────────────────
$expiring = db()->query("
    SELECT l.*, u.email, u.name AS user_name
    FROM listings l
    JOIN users u ON u.id=l.user_id
    WHERE l.featured=1
    AND l.featured_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    AND NOT EXISTS (
        SELECT 1 FROM listing_payments lp
        WHERE lp.listing_id=l.id AND lp.status='pending'
        AND lp.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
    )
")->fetchAll();

foreach ($expiring as $l) {
    $days = ceil((strtotime($l['featured_expires_at']) - time()) / 86400);
    sendMail($l['email'],
        t('Your featured listing expires in ','Votre annonce vedette expire dans ') . $days . t(' days — 237Biz',' jours — 237Biz'),
        '<h2 style="color:#fff;font-family:Georgia,serif;">⏰ '.t('Listing Expiring Soon','Annonce Expire Bientôt').'</h2>
         <p style="color:rgba(255,255,255,0.7);">'.t('Hi','Bonjour').' '.e($l['user_name']).',</p>
         <p style="color:rgba(255,255,255,0.7);">'.t('Your featured listing for','Votre annonce vedette pour').' <strong>'.e($l['title']).'</strong> '.t('expires in','expire dans').' <strong style="color:#F5C842;">'.$days.' '.t('days','jours').'</strong>.</p>
         <p style="color:rgba(255,255,255,0.7);">'.t('Renew now to keep your featured placement and verified badge.','Renouvelez maintenant pour conserver votre placement vedette et badge vérifié.').'</p>
         <a href="'.SITE_URL.'/upgrade-listing?listing_id='.$l['id'].'" style="display:inline-block;margin:1rem 0;background:#F5C842;color:#0D1F16;padding:0.85rem 2rem;border-radius:5px;text-decoration:none;font-weight:500;">⭐ '.t('Renew Now →','Renouveler Maintenant →').'</a>'
    );
    echo "Warned: " . $l['title'] . " ($days days left)\n";
}

echo "Done. " . count($expired) . " expired, " . count($expiring) . " warnings sent.\n";
