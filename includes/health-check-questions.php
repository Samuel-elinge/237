<?php
/**
 * 237biz Digital Health Check — Question Bank (V1)
 *
 * Not yet admin-configurable (that's planned for V2, same idea as
 * kb-articles.php: this array is the single source of truth site-wide).
 *
 * Each question has:
 *   id       - unique key, used in answers_json
 *   category - one of: online_presence, customer_discovery, social_media,
 *              customer_engagement, digital_marketing (must match CATEGORY_WEIGHTS)
 *   type     - single | multi | scale | text
 *   options  - value => [label_en, label_fr, points]   (points omitted for text/multi-info-only)
 *   max      - max points this question can contribute to its category
 */

const CATEGORY_WEIGHTS = [
    'online_presence'      => 25,
    'customer_discovery'   => 20,
    'social_media'         => 20,
    'customer_engagement'  => 20,
    'digital_marketing'    => 15,
];

const HEALTH_CHECK_STEPS = [
    1 => ['key' => 'business_info',      'label_en' => 'Business Information', 'label_fr' => 'Informations sur l\'entreprise'],
    2 => ['key' => 'online_presence',    'label_en' => 'Online Presence',      'label_fr' => 'Présence en ligne'],
    3 => ['key' => 'social_media',       'label_en' => 'Social Media',         'label_fr' => 'Réseaux sociaux'],
    4 => ['key' => 'customer_discovery', 'label_en' => 'Customer Discovery',   'label_fr' => 'Découverte client'],
    5 => ['key' => 'customer_engagement','label_en' => 'Customer Engagement',  'label_fr' => 'Engagement client'],
    6 => ['key' => 'digital_marketing',  'label_en' => 'Digital Marketing',    'label_fr' => 'Marketing digital'],
    7 => ['key' => 'event_interest',     'label_en' => '237biz & Event',       'label_fr' => '237biz et événement'],
];

const BUSINESS_CATEGORIES = [
    'retail'        => ['Retail', 'Vente au détail'],
    'restaurant'    => ['Restaurant / Food', 'Restaurant / Alimentation'],
    'beauty'        => ['Beauty / Cosmetics', 'Beauté / Cosmétiques'],
    'fashion'       => ['Fashion', 'Mode'],
    'professional'  => ['Professional Services', 'Services professionnels'],
    'construction'  => ['Construction', 'Construction'],
    'technology'    => ['Technology', 'Technologie'],
    'education'     => ['Education', 'Éducation'],
    'health'        => ['Health', 'Santé'],
    'transport'     => ['Transport', 'Transport'],
    'hospitality'   => ['Hospitality', 'Hôtellerie'],
    'agriculture'   => ['Agriculture', 'Agriculture'],
    'finance'       => ['Finance', 'Finance'],
    'real_estate'   => ['Real Estate', 'Immobilier'],
    'other'         => ['Other', 'Autre'],
];

const HEALTH_CHECK_CITIES = [
    'Douala' => ['Douala','Douala'], 'Yaoundé' => ['Yaoundé','Yaoundé'], 'Buea' => ['Buea','Buea'],
    'Bamenda' => ['Bamenda','Bamenda'], 'Limbe' => ['Limbe','Limbe'], 'Kribi' => ['Kribi','Kribi'],
    'Bafoussam' => ['Bafoussam','Bafoussam'], 'Garoua' => ['Garoua','Garoua'], 'Maroua' => ['Maroua','Maroua'],
    'Other' => ['Other', 'Autre'],
];

const YEARS_IN_BUSINESS = [
    'lt1' => ['Less than 1 year', "Moins d'un an"],
    '1_3' => ['1–3 years', '1 à 3 ans'],
    '3_5' => ['3–5 years', '3 à 5 ans'],
    '5_10' => ['5–10 years', '5 à 10 ans'],
    '10plus' => ['10+ years', '10 ans ou plus'],
];

/**
 * Which step (1-7) each question belongs to — used by the admin question editor
 * and to keep DB-loaded questions grouped correctly in the wizard.
 */
const QUESTION_STEP_MAP = [
    'has_website' => 2, 'google_presence' => 2, 'google_business_profile' => 2, 'has_237biz_listing' => 2,
    'social_facebook' => 3, 'social_tiktok' => 3, 'social_instagram' => 3, 'social_whatsapp_business' => 3,
    'posting_frequency' => 3, 'promo_content' => 3,
    'discovery_channels' => 4, 'biggest_challenge' => 4, 'visibility_satisfaction' => 4,
    'whatsapp_contact' => 5, 'response_time' => 5, 'collects_reviews' => 5, 'displays_reviews' => 5,
    'location_findability' => 5,
    'invests_marketing' => 6, 'marketing_channels' => 6, 'marketing_rating' => 6, 'marketing_needs' => 6,
];

/**
 * Questions are DB-configurable (see admin-health-check-questions.php / health_questions
 * table). If the table is empty or unreachable, we fall back to the hard-coded defaults
 * below so the assessment never breaks. Cached per-request in $GLOBALS to avoid repeat
 * queries across questions.php/scoring.php includes.
 */
function get_health_check_questions(): array {
    if (isset($GLOBALS['__health_check_questions_cache'])) {
        return $GLOBALS['__health_check_questions_cache'];
    }

    $fromDb = load_health_check_questions_from_db();
    $result = $fromDb !== null ? $fromDb : get_default_health_check_questions();
    $GLOBALS['__health_check_questions_cache'] = $result;
    return $result;
}

/**
 * Returns null if the DB table doesn't exist / isn't reachable / is empty,
 * so the caller falls back to defaults.
 */
function load_health_check_questions_from_db(): ?array {
    global $pdo;
    if (!isset($pdo)) return null;

    try {
        $stmt = $pdo->query("SELECT * FROM health_questions WHERE active = 1 ORDER BY step, sort_order, id");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null; // table missing or not migrated yet — use defaults
    }
    if (!$rows) return null;

    $questions = [];
    foreach ($rows as $r) {
        $q = [
            'category' => $r['category'],
            'type' => $r['type'],
            'label_en' => $r['label_en'],
            'label_fr' => $r['label_fr'],
            'max' => (float) $r['max_points'],
        ];
        if ($r['type'] === 'single') {
            $q['options'] = json_decode($r['options_json'], true) ?: [];
        } elseif ($r['type'] === 'multi') {
            $q['options'] = json_decode($r['options_json'], true) ?: [];
        } elseif ($r['type'] === 'scale') {
            $q['scale_points'] = json_decode($r['scale_points_json'], true) ?: [0,0,0,0,0];
        }
        $questions[$r['question_key']] = $q;
    }
    return $questions;
}

/**
 * Hard-coded V1 defaults — used to seed health_questions the first time
 * (see seed-health-questions.php) and as a safety fallback thereafter.
 */
function get_default_health_check_questions(): array {
    return [
        // ---------------- ONLINE PRESENCE (25 pts) ----------------
        'has_website' => [
            'category' => 'online_presence',
            'type' => 'single',
            'label_en' => 'Do you have a business website?',
            'label_fr' => 'Avez-vous un site web pour votre entreprise ?',
            'options' => [
                'yes'        => ['Yes', 'Oui', 10],
                'developing' => ['Currently developing one', 'En cours de développement', 5],
                'no'         => ['No', 'Non', 0],
            ],
            'max' => 10,
        ],
        'google_presence' => [
            'category' => 'online_presence',
            'type' => 'single',
            'label_en' => 'Does your business appear on Google Search or Google Maps?',
            'label_fr' => 'Votre entreprise apparaît-elle sur Google Search ou Google Maps ?',
            'options' => [
                'yes'      => ['Yes', 'Oui', 8],
                'not_sure' => ['Not sure', 'Pas sûr', 4],
                'no'       => ['No', 'Non', 0],
            ],
            'max' => 8,
        ],
        'google_business_profile' => [
            'category' => 'online_presence',
            'type' => 'single',
            'label_en' => 'Do you have a Google Business Profile?',
            'label_fr' => 'Avez-vous un profil Google Business ?',
            'options' => [
                'yes'      => ['Yes', 'Oui', 4],
                'not_sure' => ['Not sure', 'Pas sûr', 2],
                'no'       => ['No', 'Non', 0],
            ],
            'max' => 4,
        ],
        'has_237biz_listing' => [
            'category' => 'online_presence',
            'type' => 'single',
            'label_en' => 'Does your business have a 237biz listing?',
            'label_fr' => 'Votre entreprise a-t-elle une fiche 237biz ?',
            'options' => [
                'yes'      => ['Yes', 'Oui', 3],
                'not_sure' => ['Not sure', 'Pas sûr', 1],
                'no'       => ['No', 'Non', 0],
            ],
            'max' => 3,
        ],

        // ---------------- SOCIAL MEDIA (20 pts) ----------------
        'social_facebook' => ['category' => 'social_media', 'type' => 'single',
            'label_en' => 'Active on Facebook?', 'label_fr' => 'Actif sur Facebook ?',
            'options' => ['yes' => ['Yes','Oui',2], 'no' => ['No','Non',0]], 'max' => 2],
        'social_tiktok' => ['category' => 'social_media', 'type' => 'single',
            'label_en' => 'Active on TikTok?', 'label_fr' => 'Actif sur TikTok ?',
            'options' => ['yes' => ['Yes','Oui',2], 'no' => ['No','Non',0]], 'max' => 2],
        'social_instagram' => ['category' => 'social_media', 'type' => 'single',
            'label_en' => 'Active on Instagram?', 'label_fr' => 'Actif sur Instagram ?',
            'options' => ['yes' => ['Yes','Oui',2], 'no' => ['No','Non',0]], 'max' => 2],
        'social_whatsapp_business' => ['category' => 'social_media', 'type' => 'single',
            'label_en' => 'Active on WhatsApp Business?', 'label_fr' => 'Actif sur WhatsApp Business ?',
            'options' => ['yes' => ['Yes','Oui',2], 'no' => ['No','Non',0]], 'max' => 2],
        'posting_frequency' => [
            'category' => 'social_media', 'type' => 'single',
            'label_en' => 'How often does your business post?',
            'label_fr' => 'À quelle fréquence votre entreprise publie-t-elle ?',
            'options' => [
                'daily'       => ['Daily', 'Quotidiennement', 6],
                'weekly_plus' => ['Several times a week', 'Plusieurs fois par semaine', 5],
                'weekly'      => ['Weekly', 'Hebdomadaire', 3],
                'occasional'  => ['Occasionally', 'Occasionnellement', 2],
                'rarely'      => ['Rarely', 'Rarement', 1],
                'never'       => ['Never', 'Jamais', 0],
            ],
            'max' => 6,
        ],
        'promo_content' => [
            'category' => 'social_media', 'type' => 'single',
            'label_en' => 'Do you create photos/videos specifically to promote your business?',
            'label_fr' => 'Créez-vous des photos/vidéos spécifiquement pour promouvoir votre entreprise ?',
            'options' => [
                'regularly' => ['Regularly', 'Régulièrement', 6],
                'sometimes' => ['Sometimes', 'Parfois', 4],
                'rarely'    => ['Rarely', 'Rarement', 2],
                'never'     => ['Never', 'Jamais', 0],
            ],
            'max' => 6,
        ],

        // ---------------- CUSTOMER DISCOVERY (20 pts) ----------------
        // discovery_channels is informational/multi (no points) — used for market intel & recommendations
        'discovery_channels' => [
            'category' => 'customer_discovery', 'type' => 'multi',
            'label_en' => 'How do new customers normally find you?',
            'label_fr' => 'Comment les nouveaux clients vous trouvent-ils habituellement ?',
            'options' => [
                'word_of_mouth' => ['Word of mouth', 'Bouche à oreille'],
                'whatsapp'      => ['WhatsApp', 'WhatsApp'],
                'facebook'      => ['Facebook', 'Facebook'],
                'tiktok'        => ['TikTok', 'TikTok'],
                'instagram'     => ['Instagram', 'Instagram'],
                'google'        => ['Google', 'Google'],
                'website'       => ['Website', 'Site web'],
                'physical'      => ['Physical location', 'Emplacement physique'],
                'advertising'   => ['Advertising', 'Publicité'],
                '237biz'        => ['237biz', '237biz'],
                'other'         => ['Other', 'Autre'],
            ],
            'max' => 0,
        ],
        'biggest_challenge' => [
            'category' => 'customer_discovery', 'type' => 'multi',
            'label_en' => 'What is your biggest challenge?',
            'label_fr' => 'Quel est votre plus grand défi ?',
            'options' => [
                'finding_customers'  => ['Finding new customers', 'Trouver de nouveaux clients'],
                'getting_noticed'    => ['Getting noticed online', 'Se faire remarquer en ligne'],
                'competition'        => ['Competition', 'Concurrence'],
                'social_marketing'   => ['Social media marketing', 'Marketing sur les réseaux sociaux'],
                'ad_costs'           => ['Advertising costs', "Coûts publicitaires"],
                'website'            => ['Website', 'Site web'],
                'reviews'            => ['Getting reviews', 'Obtenir des avis'],
                'retention'          => ['Customer retention', 'Fidélisation client'],
                'time'               => ['Lack of time', 'Manque de temps'],
                'dont_know'          => ["Don't know where to start", 'Ne sait pas par où commencer'],
                'other'              => ['Other', 'Autre'],
            ],
            'max' => 0,
        ],
        'visibility_satisfaction' => [
            'category' => 'customer_discovery', 'type' => 'scale',
            'label_en' => 'How satisfied are you with your current online visibility? (1=Very dissatisfied, 5=Very satisfied)',
            'label_fr' => "Êtes-vous satisfait de votre visibilité en ligne actuelle ? (1=Très insatisfait, 5=Très satisfait)",
            'scale_points' => [0, 3, 6, 8, 10],   // index 0 => answer "1", etc. (score out of 10)
            'max' => 10,
        ],
        // Remaining 10 pts of this category come from cross-referencing discovery_channels
        // (website/google/237biz present) — see scoring.php:score_customer_discovery()

        // ---------------- CUSTOMER ENGAGEMENT (20 pts) ----------------
        'whatsapp_contact' => ['category' => 'customer_engagement', 'type' => 'single',
            'label_en' => 'Can customers contact you through WhatsApp?',
            'label_fr' => 'Les clients peuvent-ils vous contacter via WhatsApp ?',
            'options' => ['yes' => ['Yes','Oui',5], 'no' => ['No','Non',0]], 'max' => 5],
        'response_time' => [
            'category' => 'customer_engagement', 'type' => 'single',
            'label_en' => 'Do you respond to customer enquiries online?',
            'label_fr' => 'Répondez-vous aux demandes des clients en ligne ?',
            'options' => [
                'immediate'  => ['Usually immediately', 'Généralement immédiatement', 5],
                'few_hours'  => ['Within a few hours', 'Dans les quelques heures', 4],
                '24h'        => ['Within 24 hours', 'Dans les 24 heures', 3],
                'sometimes'  => ['Sometimes', 'Parfois', 1],
                'rarely'     => ['Rarely', 'Rarement', 0],
            ],
            'max' => 5,
        ],
        'collects_reviews' => [
            'category' => 'customer_engagement', 'type' => 'single',
            'label_en' => 'Do you collect customer reviews?',
            'label_fr' => "Recueillez-vous les avis des clients ?",
            'options' => [
                'regularly' => ['Yes, regularly', 'Oui, régulièrement', 4],
                'sometimes' => ['Sometimes', 'Parfois', 2],
                'no'        => ['No', 'Non', 0],
            ],
            'max' => 4,
        ],
        'displays_reviews' => [
            'category' => 'customer_engagement', 'type' => 'single',
            'label_en' => 'Do you display customer reviews online?',
            'label_fr' => 'Affichez-vous les avis clients en ligne ?',
            'options' => [
                'yes'      => ['Yes', 'Oui', 3],
                'not_sure' => ['Not sure', 'Pas sûr', 1],
                'no'       => ['No', 'Non', 0],
            ],
            'max' => 3,
        ],
        'location_findability' => [
            'category' => 'customer_engagement', 'type' => 'single',
            'label_en' => 'Do you offer customers an easy way to find your location?',
            'label_fr' => 'Offrez-vous aux clients un moyen facile de trouver votre emplacement ?',
            'options' => [
                'google_maps' => ['Google Maps', 'Google Maps', 3],
                'website'     => ['Website', 'Site web', 3],
                'social'      => ['Social media', 'Réseaux sociaux', 2],
                'whatsapp'    => ['WhatsApp location', 'Localisation WhatsApp', 2],
                'no'          => ['No', 'Non', 0],
            ],
            'max' => 3,
        ],

        // ---------------- DIGITAL MARKETING (15 pts) ----------------
        'invests_marketing' => [
            'category' => 'digital_marketing', 'type' => 'single',
            'label_en' => 'Are you currently investing in online marketing?',
            'label_fr' => "Investissez-vous actuellement dans le marketing en ligne ?",
            'options' => [
                'regularly'  => ['Regularly', 'Régulièrement', 6],
                'occasional' => ['Occasionally', 'Occasionnellement', 3],
                'no'         => ['No', 'Non', 0],
            ],
            'max' => 6,
        ],
        'marketing_channels' => [
            'category' => 'digital_marketing', 'type' => 'multi',
            'label_en' => 'Where do you market online?',
            'label_fr' => 'Où faites-vous votre marketing en ligne ?',
            'options' => [
                'facebook' => ['Facebook','Facebook'], 'instagram' => ['Instagram','Instagram'],
                'tiktok' => ['TikTok','TikTok'], 'google' => ['Google','Google'],
                'website' => ['Website','Site web'], 'influencers' => ['Influencers','Influenceurs'],
                'other' => ['Other','Autre'],
            ],
            'max' => 0,
        ],
        'marketing_rating' => [
            'category' => 'digital_marketing', 'type' => 'scale',
            'label_en' => 'How would you rate your digital marketing? (1-5)',
            'label_fr' => 'Comment évalueriez-vous votre marketing digital ? (1-5)',
            'scale_points' => [0, 2, 4, 6, 9],
            'max' => 9,
        ],
        'marketing_needs' => [
            'category' => 'digital_marketing', 'type' => 'multi',
            'label_en' => 'What would help your business most?',
            'label_fr' => 'Qu\'est-ce qui aiderait le plus votre entreprise ?',
            'options' => [
                'website' => ['Better website','Meilleur site web'],
                'social' => ['Better social media','Meilleurs réseaux sociaux'],
                'customers' => ['More customers','Plus de clients'],
                'google' => ['Better Google visibility','Meilleure visibilité Google'],
                'advertising' => ['Online advertising','Publicité en ligne'],
                'photos' => ['Better photos/videos','Meilleures photos/vidéos'],
                'reviews' => ['Customer reviews','Avis clients'],
                'booking' => ['Online booking','Réservation en ligne'],
                'catalogue' => ['Online catalogue','Catalogue en ligne'],
                'listing' => ['Business listing','Fiche entreprise'],
                'whatsapp_marketing' => ['WhatsApp marketing','Marketing WhatsApp'],
                'ai' => ['AI tools','Outils IA'],
                'not_sure' => ['Not sure','Pas sûr'],
            ],
            'max' => 0,
        ],
    ];
}
