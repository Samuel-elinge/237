<?php
/**
 * Business Health Check — shared configuration
 * Loaded by all BHC-related pages.
 * DO NOT output anything from this file.
 */

defined('IN_237BIZ') or die('Direct access denied');

// ─── Score bands ─────────────────────────────────────────────────────────────
define('BHC_BAND_NEEDS',    [0,  39,  'needs_attention']);
define('BHC_BAND_DEV',      [40, 59,  'developing']);
define('BHC_BAND_GOOD',     [60, 79,  'good_progress']);
define('BHC_BAND_STRONG',   [80, 100, 'strong_foundations']);

// ─── Category weights (must sum to 100) ───────────────────────────────────────
const BHC_CATEGORY_WEIGHTS = [
    'online_visibility'    => 15,
    'customer_acquisition' => 20,
    'customer_trust'       => 15,
    'digital_presence'     => 10,
    'customer_management'  => 15,
    'marketing_growth'     => 15,
    'business_operations'  => 10,
];

// ─── Likert scale ─────────────────────────────────────────────────────────────
const BHC_OPTIONS = [
    'not_at_all'   => ['score' => 0, 'en' => 'Not at all',     'fr' => 'Pas du tout'],
    'rarely'       => ['score' => 1, 'en' => 'Rarely',          'fr' => 'Rarement'],
    'sometimes'    => ['score' => 2, 'en' => 'Sometimes',       'fr' => 'Parfois'],
    'mostly'       => ['score' => 3, 'en' => 'Mostly',          'fr' => 'Souvent'],
    'consistently' => ['score' => 4, 'en' => 'Consistently',    'fr' => 'Toujours'],
    'na'           => ['score' => -1,'en' => 'Not applicable',  'fr' => 'Non applicable'],
];

// Max score per question (0-4)
define('BHC_MAX_SCORE_PER_Q', 4);

// Questions per category (3 each × 7 categories = 21)
define('BHC_QUESTIONS_PER_CAT', 3);

// ─── Employee bands ───────────────────────────────────────────────────────────
const BHC_EMPLOYEE_BANDS = [
    'solo'    => ['en' => 'Just me (solo)',          'fr' => 'Juste moi (solo)'],
    '2_5'     => ['en' => '2–5 employees',           'fr' => '2–5 employés'],
    '6_10'    => ['en' => '6–10 employees',          'fr' => '6–10 employés'],
    '11_20'   => ['en' => '11–20 employees',         'fr' => '11–20 employés'],
    '20plus'  => ['en' => 'More than 20 employees',  'fr' => 'Plus de 20 employés'],
];

// ─── Business categories (reuses existing constant if already defined) ────────
if (!defined('HEALTH_CHECK_CATEGORIES')) {
    define('BHC_BUSINESS_CATEGORIES', [
        'retail'           => ['en' => 'Retail / Shop',               'fr' => 'Commerce de détail'],
        'food_beverage'    => ['en' => 'Food & Beverage',             'fr' => 'Alimentation & Boisson'],
        'professional'     => ['en' => 'Professional Services',       'fr' => 'Services professionnels'],
        'health_beauty'    => ['en' => 'Health & Beauty',             'fr' => 'Santé & Beauté'],
        'tech_digital'     => ['en' => 'Tech & Digital',              'fr' => 'Tech & Numérique'],
        'construction'     => ['en' => 'Construction & Real Estate',  'fr' => 'Construction & Immobilier'],
        'education'        => ['en' => 'Education & Training',        'fr' => 'Éducation & Formation'],
        'hospitality'      => ['en' => 'Hospitality & Tourism',       'fr' => 'Hôtellerie & Tourisme'],
        'transport'        => ['en' => 'Transport & Logistics',       'fr' => 'Transport & Logistique'],
        'agriculture'      => ['en' => 'Agriculture & Farming',       'fr' => 'Agriculture & Élevage'],
        'creative'         => ['en' => 'Creative & Media',            'fr' => 'Créatif & Médias'],
        'financial'        => ['en' => 'Financial Services',          'fr' => 'Services financiers'],
        'ngo'              => ['en' => 'NGO / Non-profit',            'fr' => 'ONG / Association'],
        'other'            => ['en' => 'Other',                        'fr' => 'Autre'],
    ]);
} else {
    define('BHC_BUSINESS_CATEGORIES', HEALTH_CHECK_CATEGORIES);
}

// ─── Cities ───────────────────────────────────────────────────────────────────
const BHC_CITIES = [
    'yaounde','douala','bafoussam','bamenda','garoua','maroua',
    'ngaoundere','bertoua','ebolowa','kumba','limbe','buea',
    'edea','nkongsamba','kribi','other',
];

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Return score band slug for a numeric total score.
 */
function bhc_score_band(float $score): string {
    if ($score >= 80) return 'strong_foundations';
    if ($score >= 60) return 'good_progress';
    if ($score >= 40) return 'developing';
    return 'needs_attention';
}

/**
 * Return translated band label.
 */
function bhc_band_label(string $band, string $lang = 'en'): string {
    $labels = [
        'needs_attention'    => ['en' => 'Needs Attention',    'fr' => 'Attention requise'],
        'developing'         => ['en' => 'Developing',         'fr' => 'En développement'],
        'good_progress'      => ['en' => 'Good Progress',      'fr' => 'Bonne progression'],
        'strong_foundations' => ['en' => 'Strong Foundations', 'fr' => 'Bases solides'],
    ];
    return $labels[$band][$lang] ?? $labels[$band]['en'] ?? $band;
}

/**
 * Band colour token (matches CSS custom properties).
 */
function bhc_band_color(string $band): string {
    return match($band) {
        'needs_attention'    => '#e53e3e',
        'developing'         => '#dd6b20',
        'good_progress'      => '#d69e2e',
        'strong_foundations' => '#38a169',
        default              => '#718096',
    };
}

/**
 * Generate a unique assessment reference: HC-YYYY-NNNNNN
 * Relies on the DB being available.
 */
function bhc_generate_reference(): string {
    $year = date('Y');
    $pdo  = db();
    // Find the highest sequence for this year
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(reference, '-', -1) AS UNSIGNED))
         FROM bhc_assessments WHERE reference LIKE :prefix"
    );
    $stmt->execute([':prefix' => "HC-{$year}-%"]);
    $max = (int) $stmt->fetchColumn();
    return sprintf('HC-%s-%06d', $year, $max + 1);
}

/**
 * Load questions for all categories, DB first → fallback to hardcoded.
 * Returns array keyed by category slug, each containing array of question rows:
 *   ['id', 'question_en', 'question_fr', 'description_en', 'description_fr', 'sort_order']
 */
function bhc_load_questions(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $pdo  = db();
        $stmt = $pdo->query(
            "SELECT q.id, q.question_en, q.question_fr,
                    q.description_en, q.description_fr, q.sort_order,
                    c.slug AS category_slug
             FROM bhc_questions q
             JOIN bhc_categories c ON c.id = q.category_id
             WHERE q.is_active = 1 AND c.is_active = 1
             ORDER BY c.sort_order, q.sort_order"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out = [];
            foreach ($rows as $r) {
                $out[$r['category_slug']][] = $r;
            }
            $cache = $out;
            return $cache;
        }
    } catch (Exception $e) {
        // fall through to defaults
    }

    // Hardcoded fallback (mirrors migration seed)
    $cache = bhc_default_questions();
    return $cache;
}

/**
 * Load a single flat array of all questions in order.
 */
function bhc_load_questions_flat(): array {
    $all = bhc_load_questions();
    $flat = [];
    foreach ($all as $qs) {
        foreach ($qs as $q) {
            $flat[] = $q;
        }
    }
    return $flat;
}

/**
 * Load category metadata from DB (or fallback to constants).
 */
function bhc_load_categories(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $pdo  = db();
        $stmt = $pdo->query(
            "SELECT slug, label_en, label_fr, weight, sort_order
             FROM bhc_categories WHERE is_active = 1
             ORDER BY sort_order"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $cache = [];
            foreach ($rows as $r) {
                $cache[$r['slug']] = $r;
            }
            return $cache;
        }
    } catch (Exception $e) {}

    // Fallback
    $cache = [
        'online_visibility'    => ['slug'=>'online_visibility',    'label_en'=>'Online Visibility',              'label_fr'=>'Visibilité en ligne',          'weight'=>15,'sort_order'=>1],
        'customer_acquisition' => ['slug'=>'customer_acquisition', 'label_en'=>'Customer Acquisition',           'label_fr'=>'Acquisition de clients',        'weight'=>20,'sort_order'=>2],
        'customer_trust'       => ['slug'=>'customer_trust',       'label_en'=>'Customer Trust and Reputation',  'label_fr'=>'Confiance et réputation',       'weight'=>15,'sort_order'=>3],
        'digital_presence'     => ['slug'=>'digital_presence',     'label_en'=>'Digital Presence and Technology','label_fr'=>'Présence numérique',            'weight'=>10,'sort_order'=>4],
        'customer_management'  => ['slug'=>'customer_management',  'label_en'=>'Customer Management',            'label_fr'=>'Gestion des clients',           'weight'=>15,'sort_order'=>5],
        'marketing_growth'     => ['slug'=>'marketing_growth',     'label_en'=>'Marketing and Business Growth',  'label_fr'=>'Marketing et croissance',       'weight'=>15,'sort_order'=>6],
        'business_operations'  => ['slug'=>'business_operations',  'label_en'=>'Business Operations',            'label_fr'=>'Opérations de l\'entreprise',   'weight'=>10,'sort_order'=>7],
    ];
    return $cache;
}

/**
 * Hardcoded question fallback (mirrors migration 010 seed).
 */
function bhc_default_questions(): array {
    return [
        'online_visibility' => [
            ['id'=>1,'category_slug'=>'online_visibility','sort_order'=>1,
             'question_en'=>'Does your business have a complete and up-to-date online business profile?',
             'question_fr'=>'Votre entreprise a-t-elle un profil commercial en ligne complet et à jour ?',
             'description_en'=>'This includes listings on directories like 237biz, Google Business, or similar platforms.',
             'description_fr'=>'Cela inclut les inscriptions sur des annuaires comme 237biz, Google Business ou des plateformes similaires.'],
            ['id'=>2,'category_slug'=>'online_visibility','sort_order'=>2,
             'question_en'=>'Can potential customers easily find your business when searching online?',
             'question_fr'=>'Les clients potentiels peuvent-ils facilement trouver votre entreprise lors de recherches en ligne ?',
             'description_en'=>'Think about whether your business appears in relevant search results or online directories.',
             'description_fr'=>'Réfléchissez à savoir si votre entreprise apparaît dans les résultats de recherche pertinents ou les annuaires en ligne.'],
            ['id'=>3,'category_slug'=>'online_visibility','sort_order'=>3,
             'question_en'=>'Do you regularly update your online presence with products, services, photos or business information?',
             'question_fr'=>'Mettez-vous régulièrement à jour votre présence en ligne avec des produits, services, photos ou informations commerciales ?',
             'description_en'=>'Regular updates help keep your business visible and relevant to customers.',
             'description_fr'=>'Les mises à jour régulières aident à garder votre entreprise visible et pertinente pour les clients.'],
        ],
        'customer_acquisition' => [
            ['id'=>4,'category_slug'=>'customer_acquisition','sort_order'=>1,
             'question_en'=>'Do you have a clear strategy for attracting new customers?',
             'question_fr'=>'Avez-vous une stratégie claire pour attirer de nouveaux clients ?',
             'description_en'=>'A strategy means you have defined steps or methods you consistently use to bring in new business.',
             'description_fr'=>'Une stratégie signifie que vous avez des étapes ou méthodes définies que vous utilisez régulièrement pour attirer de nouvelles affaires.'],
            ['id'=>5,'category_slug'=>'customer_acquisition','sort_order'=>2,
             'question_en'=>'Do you regularly use marketing channels such as WhatsApp, Facebook, TikTok or other platforms to reach potential customers?',
             'question_fr'=>'Utilisez-vous régulièrement des canaux marketing tels que WhatsApp, Facebook, TikTok ou d\'autres plateformes pour atteindre des clients potentiels ?',
             'description_en'=>'Consider how often you actively promote your business through digital or traditional channels.',
             'description_fr'=>'Réfléchissez à la fréquence à laquelle vous faites activement la promotion de votre entreprise via des canaux numériques ou traditionnels.'],
            ['id'=>6,'category_slug'=>'customer_acquisition','sort_order'=>3,
             'question_en'=>'Do you monitor where your customers come from and which activities generate enquiries or sales?',
             'question_fr'=>'Suivez-vous d\'où viennent vos clients et quelles activités génèrent des demandes ou des ventes ?',
             'description_en'=>'Tracking your sources helps you invest in what works best.',
             'description_fr'=>'Le suivi de vos sources vous aide à investir dans ce qui fonctionne le mieux.'],
        ],
        'customer_trust' => [
            ['id'=>7,'category_slug'=>'customer_trust','sort_order'=>1,
             'question_en'=>'Does your business have a professional and consistent business identity?',
             'question_fr'=>'Votre entreprise a-t-elle une identité commerciale professionnelle et cohérente ?',
             'description_en'=>'This includes a recognisable name, logo, colours and tone used consistently across all touchpoints.',
             'description_fr'=>'Cela inclut un nom reconnaissable, un logo, des couleurs et un ton utilisés de manière cohérente sur tous les points de contact.'],
            ['id'=>8,'category_slug'=>'customer_trust','sort_order'=>2,
             'question_en'=>'Do you actively collect customer reviews and feedback?',
             'question_fr'=>'Collectez-vous activement les avis et commentaires des clients ?',
             'description_en'=>'Reviews build trust and help new customers choose your business.',
             'description_fr'=>'Les avis renforcent la confiance et aident les nouveaux clients à choisir votre entreprise.'],
            ['id'=>9,'category_slug'=>'customer_trust','sort_order'=>3,
             'question_en'=>'Do you respond to customer feedback, complaints and enquiries professionally?',
             'question_fr'=>'Répondez-vous professionnellement aux commentaires, plaintes et demandes des clients ?',
             'description_en'=>'Timely and professional responses demonstrate that you value your customers.',
             'description_fr'=>'Des réponses rapides et professionnelles montrent que vous accordez de l\'importance à vos clients.'],
        ],
        'digital_presence' => [
            ['id'=>10,'category_slug'=>'digital_presence','sort_order'=>1,
             'question_en'=>'Does your business have a professional website or suitable online platform presenting its products and services?',
             'question_fr'=>'Votre entreprise dispose-t-elle d\'un site web professionnel ou d\'une plateforme en ligne adaptée présentant ses produits et services ?',
             'description_en'=>'A professional website or platform helps customers find out about your business at any time.',
             'description_fr'=>'Un site web professionnel ou une plateforme aide les clients à se renseigner sur votre entreprise à tout moment.'],
            ['id'=>11,'category_slug'=>'digital_presence','sort_order'=>2,
             'question_en'=>'Do you use a professional business email address rather than relying entirely on personal email accounts?',
             'question_fr'=>'Utilisez-vous une adresse e-mail professionnelle plutôt que de vous fier entièrement à des comptes e-mail personnels ?',
             'description_en'=>'A professional email address (e.g. info@yourbusiness.com) builds credibility with customers and partners.',
             'description_fr'=>'Une adresse e-mail professionnelle (ex. : info@votreentreprise.com) renforce votre crédibilité auprès des clients et des partenaires.'],
            ['id'=>12,'category_slug'=>'digital_presence','sort_order'=>3,
             'question_en'=>'Do you use digital tools that help customers communicate with your business and access your services?',
             'question_fr'=>'Utilisez-vous des outils numériques qui aident les clients à communiquer avec votre entreprise et à accéder à vos services ?',
             'description_en'=>'Examples include WhatsApp Business, online booking, payment links or a customer enquiry form.',
             'description_fr'=>'Exemples : WhatsApp Business, réservation en ligne, liens de paiement ou formulaire de demande client.'],
        ],
        'customer_management' => [
            ['id'=>13,'category_slug'=>'customer_management','sort_order'=>1,
             'question_en'=>'Do you have an organised way of recording customer details and enquiries?',
             'question_fr'=>'Avez-vous une façon organisée d\'enregistrer les coordonnées des clients et les demandes ?',
             'description_en'=>'This could be a spreadsheet, notebook, app or CRM system — anything that helps you track customers.',
             'description_fr'=>'Il peut s\'agir d\'une feuille de calcul, d\'un carnet, d\'une application ou d\'un CRM — tout ce qui vous aide à suivre vos clients.'],
            ['id'=>14,'category_slug'=>'customer_management','sort_order'=>2,
             'question_en'=>'Do you follow up with potential customers who have shown interest but have not purchased?',
             'question_fr'=>'Faites-vous un suivi auprès des clients potentiels qui ont manifesté de l\'intérêt mais n\'ont pas encore acheté ?',
             'description_en'=>'Following up with leads significantly increases your chances of converting interest into sales.',
             'description_fr'=>'Faire un suivi auprès des prospects augmente considérablement vos chances de convertir l\'intérêt en ventes.'],
            ['id'=>15,'category_slug'=>'customer_management','sort_order'=>3,
             'question_en'=>'Do you maintain relationships with existing customers and encourage repeat business?',
             'question_fr'=>'Entretenez-vous des relations avec vos clients existants et encouragez-vous les affaires répétées ?',
             'description_en'=>'Retaining existing customers is typically more cost-effective than acquiring new ones.',
             'description_fr'=>'Fidéliser les clients existants est généralement plus rentable que d\'en acquérir de nouveaux.'],
        ],
        'marketing_growth' => [
            ['id'=>16,'category_slug'=>'marketing_growth','sort_order'=>1,
             'question_en'=>'Does your business have a marketing plan or defined marketing objectives?',
             'question_fr'=>'Votre entreprise a-t-elle un plan marketing ou des objectifs marketing définis ?',
             'description_en'=>'A marketing plan helps you focus your efforts and budget on activities that support your goals.',
             'description_fr'=>'Un plan marketing vous aide à concentrer vos efforts et votre budget sur les activités qui soutiennent vos objectifs.'],
            ['id'=>17,'category_slug'=>'marketing_growth','sort_order'=>2,
             'question_en'=>'Do you regularly run campaigns, promotions, special offers or other activities to increase sales?',
             'question_fr'=>'Organisez-vous régulièrement des campagnes, promotions, offres spéciales ou autres activités pour augmenter les ventes ?',
             'description_en'=>'Regular promotional activity keeps your business visible and gives customers reasons to buy.',
             'description_fr'=>'Une activité promotionnelle régulière maintient votre entreprise visible et donne aux clients des raisons d\'acheter.'],
            ['id'=>18,'category_slug'=>'marketing_growth','sort_order'=>3,
             'question_en'=>'Do you measure the results of your marketing activities and make improvements based on those results?',
             'question_fr'=>'Mesurez-vous les résultats de vos activités marketing et apportez-vous des améliorations en fonction de ces résultats ?',
             'description_en'=>'Measuring results allows you to identify what works and allocate resources more effectively.',
             'description_fr'=>'Mesurer les résultats vous permet d\'identifier ce qui fonctionne et d\'allouer les ressources plus efficacement.'],
        ],
        'business_operations' => [
            ['id'=>19,'category_slug'=>'business_operations','sort_order'=>1,
             'question_en'=>'Do you have organised processes for managing daily business activities?',
             'question_fr'=>'Avez-vous des processus organisés pour gérer les activités commerciales quotidiennes ?',
             'description_en'=>'Organised processes reduce errors, save time and help your business run consistently.',
             'description_fr'=>'Des processus organisés réduisent les erreurs, font gagner du temps et aident votre entreprise à fonctionner de manière cohérente.'],
            ['id'=>20,'category_slug'=>'business_operations','sort_order'=>2,
             'question_en'=>'Do you maintain records of important business transactions, expenses and activities?',
             'question_fr'=>'Tenez-vous des registres des transactions commerciales importantes, des dépenses et des activités ?',
             'description_en'=>'Good record-keeping supports financial management, compliance and business planning.',
             'description_fr'=>'Une bonne tenue des registres soutient la gestion financière, la conformité et la planification commerciale.'],
            ['id'=>21,'category_slug'=>'business_operations','sort_order'=>3,
             'question_en'=>'Do you use tools or processes to reduce manual work, errors and missed opportunities?',
             'question_fr'=>'Utilisez-vous des outils ou des processus pour réduire le travail manuel, les erreurs et les opportunités manquées ?',
             'description_en'=>'Tools like scheduling apps, automated reminders or simple checklists can make a big difference.',
             'description_fr'=>'Des outils comme les applications de planification, les rappels automatiques ou les simples listes de contrôle peuvent faire une grande différence.'],
        ],
    ];
}
