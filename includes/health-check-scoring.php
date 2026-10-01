<?php
require_once __DIR__ . '/health-check-questions.php';

/**
 * Calculate scores from a flat answers array (question_id => value or [values]).
 *
 * Returns:
 *   [
 *     'category_scores' => ['online_presence' => 72.0, ...],  // 0-100 % per category
 *     'category_points'  => ['online_presence' => 18.0, ...], // raw points earned
 *     'total_score'      => 67.4,                              // 0-100 weighted
 *     'band'             => 'good_foundation',
 *   ]
 */
function calculate_health_check_score(array $answers): array {
    $questions = get_health_check_questions();
    $rawPoints = array_fill_keys(array_keys(CATEGORY_WEIGHTS), 0.0);

    foreach ($questions as $qid => $q) {
        if (!isset($answers[$qid])) continue;
        $cat = $q['category'];

        if ($q['type'] === 'single') {
            $val = $answers[$qid];
            if (isset($q['options'][$val])) {
                $rawPoints[$cat] += (float) $q['options'][$val][2];
            }
        } elseif ($q['type'] === 'scale') {
            $val = (int) $answers[$qid]; // expects 1-5
            if ($val >= 1 && $val <= 5) {
                $rawPoints[$cat] += (float) $q['scale_points'][$val - 1];
            }
        }
        // 'multi' questions carry no direct points — they feed recommendations/market intel
    }

    // Customer Discovery bonus (10 pts): rewards having the channels that make you findable
    $channels = $answers['discovery_channels'] ?? [];
    if (!is_array($channels)) $channels = [];
    if (in_array('website', $channels, true))  $rawPoints['customer_discovery'] += 4;
    if (in_array('google', $channels, true))   $rawPoints['customer_discovery'] += 3;
    if (in_array('237biz', $channels, true))   $rawPoints['customer_discovery'] += 3;

    $categoryScores = [];
    foreach (CATEGORY_WEIGHTS as $cat => $weight) {
        $pts = min($rawPoints[$cat], $weight); // clamp, safety
        $categoryScores[$cat] = $weight > 0 ? round(($pts / $weight) * 100, 1) : 0.0;
    }

    $totalScore = round(array_sum($rawPoints), 1);
    $totalScore = min($totalScore, 100.0);

    return [
        'category_scores' => $categoryScores,
        'category_points' => $rawPoints,
        'total_score'      => $totalScore,
        'band'             => get_score_band($totalScore),
    ];
}

function get_score_band(float $score): string {
    if ($score < 40) return 'needs_attention';
    if ($score < 60) return 'getting_started';
    if ($score < 80) return 'good_foundation';
    return 'strong';
}

function get_score_band_label(string $band, string $lang = 'en'): array {
    $labels = [
        'needs_attention' => [
            'emoji' => '🔴',
            'en' => ['Needs Attention', 'Your business has significant opportunities to improve its online presence.'],
            'fr' => ["Nécessite de l'attention", "Votre entreprise a d'importantes opportunités d'améliorer sa présence en ligne."],
        ],
        'getting_started' => [
            'emoji' => '🟠',
            'en' => ['Getting Started', 'You have some digital foundations, but there are several opportunities to improve visibility.'],
            'fr' => ['Débutant', "Vous avez quelques bases numériques, mais il existe plusieurs opportunités d'améliorer votre visibilité."],
        ],
        'good_foundation' => [
            'emoji' => '🟡',
            'en' => ['Good Foundation', 'Your business has a good digital foundation, with opportunities to improve customer reach and engagement.'],
            'fr' => ['Bonne base', "Votre entreprise a une bonne base numérique, avec des opportunités d'améliorer la portée et l'engagement client."],
        ],
        'strong' => [
            'emoji' => '🟢',
            'en' => ['Strong Digital Presence', 'Your business has a strong online foundation. The next step is to optimise and grow your digital reach.'],
            'fr' => ['Forte présence numérique', 'Votre entreprise a une base en ligne solide. La prochaine étape est d\'optimiser et de développer votre portée numérique.'],
        ],
    ];
    $entry = $labels[$band] ?? $labels['needs_attention'];
    return [
        'emoji' => $entry['emoji'],
        'title' => $entry[$lang][0],
        'description' => $entry[$lang][1],
    ];
}

/**
 * Internal 237biz Opportunity Score — separate from the public digital score.
 * HIGH: low digital score + wants more customers/visibility + open to 237biz/event
 * Returns 'low' | 'medium' | 'high'
 */
function calculate_opportunity_score(array $answers, float $totalScore): string {
    $points = 0;

    if ($totalScore < 60) $points += 2;
    elseif ($totalScore < 80) $points += 1;

    if (($answers['has_website'] ?? '') === 'no') $points += 1;
    if (($answers['google_presence'] ?? '') === 'no') $points += 1;

    $needs = $answers['marketing_needs'] ?? [];
    if (is_array($needs) && (in_array('customers', $needs, true) || in_array('google', $needs, true))) $points += 1;

    $interested = $answers['listing_interest'] ?? '';
    if (in_array($interested, ['definitely', 'probably'], true)) $points += 2;

    $eventInterest = $answers['event_interest'] ?? '';
    if (in_array($eventInterest, ['definitely', 'probably'], true)) $points += 1;

    $challenge = $answers['biggest_challenge'] ?? [];
    if (is_array($challenge) && (in_array('finding_customers', $challenge, true) || in_array('getting_noticed', $challenge, true))) $points += 1;

    if ($points >= 6) return 'high';
    if ($points >= 3) return 'medium';
    return 'low';
}

/**
 * Personalised recommendations, ordered by impact. Returns up to 3-5 items.
 */
function get_health_check_recommendations(array $answers, string $lang = 'en'): array {
    $catalog = [
        'no_website' => [
            'en' => ['Improve your website', 'Consider creating a professional website to give customers more information about your business.'],
            'fr' => ['Améliorez votre site web', "Envisagez de créer un site web professionnel pour donner plus d'informations aux clients."],
            'trigger' => fn($a) => ($a['has_website'] ?? '') === 'no',
        ],
        'no_google' => [
            'en' => ['Improve your Google visibility', 'Improve your Google/Maps visibility so customers can find your business when searching locally.'],
            'fr' => ['Améliorez votre visibilité Google', 'Améliorez votre visibilité Google/Maps pour que les clients vous trouvent lors de recherches locales.'],
            'trigger' => fn($a) => in_array($a['google_presence'] ?? '', ['no', 'not_sure'], true),
        ],
        'no_social' => [
            'en' => ['Build a social media presence', 'Establish a basic social media presence where your customers are already active.'],
            'fr' => ['Développez votre présence sur les réseaux sociaux', 'Établissez une présence de base là où vos clients sont déjà actifs.'],
            'trigger' => fn($a) => ($a['social_facebook'] ?? 'no') === 'no' && ($a['social_tiktok'] ?? 'no') === 'no' && ($a['social_instagram'] ?? 'no') === 'no',
        ],
        'no_reviews' => [
            'en' => ['Start collecting reviews', 'Start collecting customer reviews to build trust with potential customers.'],
            'fr' => ['Commencez à collecter des avis', 'Commencez à recueillir des avis clients pour instaurer la confiance.'],
            'trigger' => fn($a) => ($a['collects_reviews'] ?? '') === 'no',
        ],
        'no_listing' => [
            'en' => ['Create a 237biz profile', 'Create a 237biz profile so customers can discover your business.'],
            'fr' => ['Créez un profil 237biz', 'Créez un profil 237biz pour que les clients puissent découvrir votre entreprise.'],
            'trigger' => fn($a) => in_array($a['has_237biz_listing'] ?? '', ['no', 'not_sure'], true),
        ],
        'slow_response' => [
            'en' => ['Speed up your response time', 'Faster replies to enquiries build trust and convert more customers — aim for under a few hours.'],
            'fr' => ['Accélérez votre temps de réponse', 'Des réponses plus rapides renforcent la confiance et convertissent plus de clients.'],
            'trigger' => fn($a) => in_array($a['response_time'] ?? '', ['sometimes', 'rarely'], true),
        ],
        'no_whatsapp_contact' => [
            'en' => ['Enable WhatsApp contact', 'Most Cameroonian customers prefer WhatsApp — make sure they can reach you there directly.'],
            'fr' => ['Activez le contact WhatsApp', 'La plupart des clients camerounais préfèrent WhatsApp — assurez-vous qu\'ils puissent vous y joindre.'],
            'trigger' => fn($a) => ($a['whatsapp_contact'] ?? '') === 'no',
        ],
        'low_posting' => [
            'en' => ['Post more consistently', 'Regular posts keep your business top-of-mind — aim for at least a few times a week.'],
            'fr' => ['Publiez plus régulièrement', 'Des publications régulières maintiennent votre entreprise visible.'],
            'trigger' => fn($a) => in_array($a['posting_frequency'] ?? '', ['rarely', 'never'], true),
        ],
    ];

    $results = [];
    foreach ($catalog as $key => $item) {
        if ($item['trigger']($answers)) {
            $results[] = [
                'title' => $item[$lang][0],
                'description' => $item[$lang][1],
            ];
        }
        if (count($results) >= 5) break;
    }
    return $results;
}

function generate_referral_code(): string {
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}
