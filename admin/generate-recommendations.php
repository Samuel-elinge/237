<?php
/**
 * admin/generate-recommendations.php
 * Phase 4 — Business-owner Growth Recommendations & Profile Scoring
 *
 * Runs rule-based checks across all approved listings and:
 *  1. Calculates profile completeness score → listing_profile_scores
 *  2. Generates / refreshes business-owner tips  → growth_recommendations
 *
 * Designed to be called:
 *  a) Via browser (admin only)            /admin/generate-recommendations
 *  b) Via cron / CLI (php this-file.php)
 *
 * Safe to run repeatedly — uses rule_key + rec_status dedup so existing
 * open/completed/dismissed recs are never duplicated.
 */

define('PHASE4_GEN', true);   // guard so config doesn't redirect

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/auth.php';
    requireAdmin();                      // only admins can run via browser
    header('Content-Type: text/plain; charset=utf-8');
}  else {
    // CLI: config path relative to script
    require_once __DIR__ . '/../includes/config.php';
}

$pdo      = db();
$siteUrl  = defined('SITE_URL') ? SITE_URL : '';
$now      = date('Y-m-d H:i:s');
$log      = [];
$totalRec = 0;
$totalScored = 0;

// ─── Helper: log line ─────────────────────────────────────────────────────
function xlog(string $msg): void {
    global $log;
    $log[] = $msg;
    if (PHP_SAPI === 'cli') {
        echo $msg . "\n";
    }
}

// ─── Profile completeness: fields and weights ──────────────────────────────
//  Returns [score_pct (0-100), missing_fields[]]
function calcProfileScore(array $l): array {
    $fields = [
        // field key          weight   label for missing_fields
        ['description',      15, 'description'],
        ['phone',            10, 'phone'],
        ['email',            10, 'email'],
        ['address',          10, 'address'],
        ['website',           8, 'website'],
        ['whatsapp',          7, 'whatsapp'],
        ['hours',             8, 'opening_hours'],
        ['logo',             12, 'logo'],
        ['cover_image',       8, 'cover_image'],
        ['lat',               5, 'location_pin'],  // lat implies lng
        ['facebook',          4, 'facebook'],
        ['instagram',         3, 'instagram'],
    ];

    $totalWeight = array_sum(array_column($fields, 1));
    $earned      = 0;
    $missing     = [];

    foreach ($fields as [$key, $weight, $label]) {
        if (!empty($l[$key])) {
            $earned += $weight;
        } else {
            $missing[] = $label;
        }
    }

    $pct = $totalWeight > 0 ? (int)round($earned / $totalWeight * 100) : 0;
    return [$pct, $missing];
}

// ─── Fetch all approved listings ───────────────────────────────────────────
try {
    $listings = $pdo->query("
        SELECT l.*,
               (SELECT COUNT(*) FROM reviews WHERE listing_id = l.id) AS review_count,
               (SELECT AVG(rating) FROM reviews WHERE listing_id = l.id) AS avg_rating,
               (SELECT COUNT(*) FROM listing_enquiries WHERE listing_id = l.id
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS enquiries_30d,
               (SELECT COUNT(*) FROM listing_enquiries
                  WHERE listing_id = l.id
                  AND COALESCE(crm_status,'new') = 'new'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_enquiries_30d
        FROM listings l
        WHERE l.status = 'approved'
        ORDER BY l.id ASC
    ")->fetchAll();
} catch (Exception $e) {
    // Fallback without enquiry cols (pre-migration)
    $listings = $pdo->query("
        SELECT l.*,
               (SELECT COUNT(*) FROM reviews WHERE listing_id = l.id) AS review_count,
               (SELECT AVG(rating) FROM reviews WHERE listing_id = l.id) AS avg_rating,
               0 AS enquiries_30d,
               0 AS new_enquiries_30d
        FROM listings l
        WHERE l.status = 'approved'
        ORDER BY l.id ASC
    ")->fetchAll();
}

xlog("Found " . count($listings) . " approved listings");
xlog(str_repeat('─', 60));

foreach ($listings as $listing) {
    $lid  = (int)$listing['id'];
    $name = $listing['name'] ?? $listing['title'] ?? "Listing #$lid";

    // ══════════════════════════════════════════════════════════════
    // 1. PROFILE COMPLETENESS SCORE
    // ══════════════════════════════════════════════════════════════
    [$scorePct, $missingFields] = calcProfileScore($listing);
    $missingJson = json_encode($missingFields, JSON_UNESCAPED_UNICODE);

    try {
        $pdo->prepare("
            INSERT INTO listing_profile_scores (listing_id, score_pct, missing_fields, last_calculated)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                score_pct        = VALUES(score_pct),
                missing_fields   = VALUES(missing_fields),
                last_calculated  = NOW()
        ")->execute([$lid, $scorePct, $missingJson]);
        $totalScored++;
    } catch (Exception $e) {
        xlog("  [WARN] Could not save profile score for #$lid: " . $e->getMessage());
    }

    // ══════════════════════════════════════════════════════════════
    // 2. GROWTH RECOMMENDATIONS
    // ══════════════════════════════════════════════════════════════

    // Helper: does an open rec with this rule_key already exist?
    $exists = function(string $ruleKey) use ($pdo, $lid): bool {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM growth_recommendations
            WHERE listing_id = ? AND rule_key = ? AND rec_status = 'open'
        ");
        $st->execute([$lid, $ruleKey]);
        return (bool)$st->fetchColumn();
    };

    // Helper: insert a new recommendation
    $insert = function(array $r) use ($pdo, $lid, &$totalRec): void {
        // priority: numeric — lower = more urgent (matches ORDER BY priority ASC in queries)
        $priorityMap = ['urgent'=>10,'high'=>20,'medium'=>50,'low'=>80];
        $pNum = $priorityMap[$r['priority']] ?? 50;
        $pdo->prepare("
            INSERT INTO growth_recommendations
                (listing_id, rule_key, priority, title_en, title_fr,
                 body_en, body_fr, action_url, rec_status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())
        ")->execute([
            $lid,
            $r['rule_key'],
            $pNum,
            $r['title_en'],
            $r['title_fr'],
            $r['body_en']  ?? null,
            $r['body_fr']  ?? null,
            $r['action_url'] ?? null,
        ]);
        $totalRec++;
    };

    $thirtyDays = date('Y-m-d', strtotime('-30 days'));
    $sevenDays  = date('Y-m-d', strtotime('-7 days'));
    $recCount   = (int)($listing['review_count'] ?? 0);
    $avgRating  = (float)($listing['avg_rating']  ?? 0);
    $views      = (int)($listing['views'] ?? 0);
    $enq30      = (int)($listing['enquiries_30d'] ?? 0);
    $newEnq30   = (int)($listing['new_enquiries_30d'] ?? 0);

    // ── Rule: incomplete_profile ──────────────────────────────────
    if ($scorePct < 70 && !$exists('incomplete_profile')) {
        $missingStr = implode(', ', array_slice($missingFields, 0, 3));
        $insert([
            'rule_key'   => 'incomplete_profile',
            'priority'   => $scorePct < 40 ? 'urgent' : 'high',
            'title_en'   => "Complete your business profile ($scorePct% done)",
            'title_fr'   => "Complétez votre profil d'entreprise ($scorePct% fait)",
            'body_en'    => "A complete profile gets up to 3× more views. Missing: $missingStr" . (count($missingFields) > 3 ? ' and more.' : '.'),
            'body_fr'    => "Un profil complet obtient jusqu'à 3× plus de vues. Manquant : $missingStr" . (count($missingFields) > 3 ? ' et plus.' : '.'),
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: no_logo ─────────────────────────────────────────────
    if (empty($listing['logo']) && !$exists('no_logo')) {
        $insert([
            'rule_key'   => 'no_logo',
            'priority'   => 'high',
            'title_en'   => 'Add a logo to your listing',
            'title_fr'   => 'Ajoutez un logo à votre annonce',
            'body_en'    => 'Listings with a logo get 60% more clicks. Upload your business logo to stand out.',
            'body_fr'    => 'Les annonces avec logo obtiennent 60% de clics en plus. Téléchargez le logo de votre entreprise.',
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: no_cover_image ──────────────────────────────────────
    if (empty($listing['cover_image']) && !$exists('no_cover_image')) {
        $insert([
            'rule_key'   => 'no_cover_image',
            'priority'   => 'medium',
            'title_en'   => 'Add a cover photo to your listing',
            'title_fr'   => 'Ajoutez une photo de couverture',
            'body_en'    => 'A cover image makes your listing more appealing and helps customers recognise your business.',
            'body_fr'    => 'Une photo de couverture rend votre annonce plus attrayante.',
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: no_reviews ─────────────────────────────────────────
    if ($recCount === 0 && !$exists('no_reviews')) {
        $insert([
            'rule_key'   => 'no_reviews',
            'priority'   => 'high',
            'title_en'   => 'You have no reviews yet',
            'title_fr'   => "Vous n'avez pas encore d'avis",
            'body_en'    => 'Ask your first customers to leave a review. Businesses with reviews get up to 5× more enquiries.',
            'body_fr'    => "Demandez à vos premiers clients de laisser un avis. Les entreprises avec des avis reçoivent jusqu'à 5× plus de demandes.",
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: low_rating ─────────────────────────────────────────
    if ($recCount >= 3 && $avgRating < 3.5 && !$exists('low_rating')) {
        $avg = number_format($avgRating, 1);
        $insert([
            'rule_key'   => 'low_rating',
            'priority'   => 'urgent',
            'title_en'   => "Your average rating is $avg — let's improve it",
            'title_fr'   => "Votre note moyenne est $avg — améliorons cela",
            'body_en'    => 'Respond to recent reviews and encourage satisfied customers to leave positive feedback.',
            'body_fr'    => 'Répondez aux avis récents et encouragez les clients satisfaits à laisser des commentaires positifs.',
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: no_location_pin ─────────────────────────────────────
    if (empty($listing['lat']) && !$exists('no_location_pin')) {
        $insert([
            'rule_key'   => 'no_location_pin',
            'priority'   => 'medium',
            'title_en'   => 'Add your location pin',
            'title_fr'   => 'Ajoutez votre épingle de localisation',
            'body_en'    => 'A map pin helps customers find you. Listings with a location pin appear in "near me" searches.',
            'body_fr'    => 'Une épingle de localisation aide les clients à vous trouver et vous fait apparaître dans les recherches « près de moi ».',
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: no_contact_info ─────────────────────────────────────
    if (empty($listing['phone']) && empty($listing['whatsapp']) && empty($listing['email']) && !$exists('no_contact_info')) {
        $insert([
            'rule_key'   => 'no_contact_info',
            'priority'   => 'urgent',
            'title_en'   => 'Add contact information to your listing',
            'title_fr'   => 'Ajoutez vos coordonnées à votre annonce',
            'body_en'    => 'Customers cannot reach you without contact details. Add at least a phone number or WhatsApp.',
            'body_fr'    => 'Les clients ne peuvent pas vous contacter sans coordonnées. Ajoutez au moins un numéro de téléphone ou WhatsApp.',
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: no_opening_hours ────────────────────────────────────
    if (empty($listing['hours']) && !$exists('no_opening_hours')) {
        $insert([
            'rule_key'   => 'no_opening_hours',
            'priority'   => 'low',
            'title_en'   => 'Add your opening hours',
            'title_fr'   => "Ajoutez vos horaires d'ouverture",
            'body_en'    => 'Customers want to know when you are open. Adding hours reduces missed enquiries.',
            'body_fr'    => 'Les clients veulent savoir quand vous êtes ouvert. Ajouter vos horaires réduit les demandes manquées.',
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: no_social_media ─────────────────────────────────────
    if (empty($listing['facebook']) && empty($listing['instagram']) && !$exists('no_social_media')) {
        $insert([
            'rule_key'   => 'no_social_media',
            'priority'   => 'low',
            'title_en'   => 'Link your social media profiles',
            'title_fr'   => 'Liez vos profils de réseaux sociaux',
            'body_en'    => 'Adding Facebook or Instagram links helps customers follow you and builds trust.',
            'body_fr'    => 'Ajouter Facebook ou Instagram aide les clients à vous suivre et renforce la confiance.',
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: high_views_low_enquiries ────────────────────────────
    if ($views >= 50 && $enq30 === 0 && !$exists('high_views_low_enquiries')) {
        $insert([
            'rule_key'   => 'high_views_low_enquiries',
            'priority'   => 'high',
            'title_en'   => 'Many views but no enquiries — check your profile',
            'title_fr'   => 'Beaucoup de vues mais aucune demande — vérifiez votre profil',
            'body_en'    => "Your listing has been viewed $views times but received no enquiries recently. Make sure your contact details and description are clear and up to date.",
            'body_fr'    => "Votre annonce a été vue $views fois mais n'a reçu aucune demande récemment. Assurez-vous que vos coordonnées et description sont claires et à jour.",
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    // ── Rule: unread_enquiries ────────────────────────────────────
    if ($newEnq30 >= 3 && !$exists('unread_enquiries')) {
        $insert([
            'rule_key'   => 'unread_enquiries',
            'priority'   => 'urgent',
            'title_en'   => "$newEnq30 new enquiries need a response",
            'title_fr'   => "$newEnq30 nouvelles demandes nécessitent une réponse",
            'body_en'    => "You have $newEnq30 unread enquiries in the last 30 days. Responding quickly increases conversion rates significantly.",
            'body_fr'    => "Vous avez $newEnq30 demandes non lues au cours des 30 derniers jours. Répondre rapidement augmente considérablement les taux de conversion.",
            'action_url' => "$siteUrl/my-enquiries?listing_id=$lid",
        ]);
    }

    // ── Rule: no_description ──────────────────────────────────────
    if (empty($listing['description']) && !$exists('no_description')) {
        $insert([
            'rule_key'   => 'no_description',
            'priority'   => 'high',
            'title_en'   => 'Write a business description',
            'title_fr'   => "Rédigez une description d'entreprise",
            'body_en'    => 'A description helps customers understand what you offer and improves your search ranking on 237Biz.',
            'body_fr'    => 'Une description aide les clients à comprendre ce que vous proposez et améliore votre classement dans les recherches.',
            'action_url' => "$siteUrl/dashboard",
        ]);
    }

    xlog(sprintf("  #%d %-40s score=%3d%%  recs_added=%d",
        $lid, mb_strimwidth($name, 0, 40), $scorePct, 0));  // individual count not tracked per-listing; totals at end
}

// ─── Clean up completed/dismissed recs for listings no longer approved ─────
try {
    $pdo->exec("
        UPDATE growth_recommendations gr
        LEFT JOIN listings l ON l.id = gr.listing_id
        SET gr.rec_status = 'dismissed'
        WHERE (l.id IS NULL OR l.status != 'approved')
          AND gr.rec_status = 'open'
    ");
} catch (Exception $e) {
    xlog("[WARN] Could not clean stale recs: " . $e->getMessage());
}

xlog(str_repeat('─', 60));
xlog("Done. Scored: $totalScored listings | New recommendations inserted: $totalRec");
xlog("Run at: " . date('Y-m-d H:i:s'));

if (!$isCli): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Generate Recommendations</title>
<style>
  body { font-family: monospace; background: #111; color: #cfc; padding: 2rem; }
  h2   { color: #7bf; }
  pre  { white-space: pre-wrap; }
</style>
</head>
<body>
<h2>Phase 4 — Generate Recommendations &amp; Profile Scores</h2>
<pre><?= implode("\n", array_map('htmlspecialchars', $log)) ?></pre>
</body>
</html>
<?php endif;
