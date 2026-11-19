<?php
/**
 * Business Health Check — Service Catalogue & Provider Network helpers
 *
 * Functions:
 *   bhc_get_service_categories(PDO, string $lang): array
 *   bhc_get_services(PDO, ?int $categoryId, string $lang, bool $activeOnly): array
 *   bhc_get_service(PDO, int $id, string $lang): ?array
 *   bhc_get_providers_for_service(PDO, int $serviceId): array
 *   bhc_get_provider(PDO, int $id): ?array
 *   bhc_get_triggered_services(PDO, array $assessment, array $answers, string $lang): array
 *   bhc_get_featured_services(PDO, string $lang, int $limit): array
 */

defined('IN_237BIZ') or die('Direct access denied');

// ── Service Categories ─────────────────────────────────────────────────────

function bhc_get_service_categories(PDO $pdo, string $lang = 'en'): array
{
    $nameCol = $lang === 'fr' ? 'name_fr' : 'name_en';
    $descCol = $lang === 'fr' ? 'description_fr' : 'description_en';

    $stmt = $pdo->query(
        "SELECT id, slug, `{$nameCol}` AS name, `{$descCol}` AS description,
                icon, sort_order
         FROM bhc_service_categories
         WHERE is_active = 1
         ORDER BY sort_order ASC, name ASC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Services ───────────────────────────────────────────────────────────────

function bhc_get_services(
    PDO $pdo,
    ?int $categoryId = null,
    string $lang = 'en',
    bool $activeOnly = true
): array {
    $nameCol  = $lang === 'fr' ? 'name_fr'        : 'name_en';
    $descCol  = $lang === 'fr' ? 'description_fr' : 'description_en';
    $probCol  = $lang === 'fr' ? 'problems_fr'    : 'problems_en';
    $benCol   = $lang === 'fr' ? 'benefits_fr'    : 'benefits_en';

    $where = [];
    $params = [];

    if ($activeOnly) {
        $where[] = 's.is_active = 1';
    }
    if ($categoryId !== null) {
        $where[] = 's.category_id = :cat_id';
        $params[':cat_id'] = $categoryId;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare(
        "SELECT s.id, s.slug, s.category_id,
                s.`{$nameCol}` AS name,
                s.`{$descCol}` AS description,
                s.`{$probCol}` AS problems,
                s.`{$benCol}`  AS benefits,
                s.pricing_info, s.pricing_type,
                s.is_featured, s.is_active, s.sort_order,
                c.`{$nameCol}` AS category_name, c.slug AS category_slug
         FROM bhc_services s
         JOIN bhc_service_categories c ON c.id = s.category_id
         {$whereSQL}
         ORDER BY s.sort_order ASC, s.name ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bhc_get_service(PDO $pdo, int $id, string $lang = 'en'): ?array
{
    $services = bhc_get_services($pdo, null, $lang, false);
    foreach ($services as $s) {
        if ((int)$s['id'] === $id) return $s;
    }
    return null;
}

// ── Providers ──────────────────────────────────────────────────────────────

function bhc_get_providers_for_service(PDO $pdo, int $serviceId): array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.slug, p.business_name, p.short_bio_en, p.short_bio_fr,
                p.logo_path, p.website_url, p.email, p.phone, p.whatsapp,
                p.location, p.areas_served, p.languages,
                p.is_verified, p.is_preferred,
                ps.pricing_detail, ps.is_primary
         FROM bhc_service_providers p
         JOIN bhc_provider_services ps ON ps.provider_id = p.id
         WHERE ps.service_id = :sid
           AND ps.is_active  = 1
           AND p.is_active   = 1
         ORDER BY p.is_preferred DESC, p.is_verified DESC, p.business_name ASC"
    );
    $stmt->execute([':sid' => $serviceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bhc_get_provider(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM bhc_service_providers WHERE id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bhc_get_all_providers(PDO $pdo, bool $activeOnly = true): array
{
    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    $stmt = $pdo->query(
        "SELECT id, slug, business_name, short_bio_en, short_bio_fr,
                logo_path, website_url, location, is_verified, is_preferred, is_active
         FROM bhc_service_providers {$where}
         ORDER BY is_preferred DESC, is_verified DESC, business_name ASC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Trigger-based service matching ─────────────────────────────────────────

/**
 * Given a completed assessment and its answers, return the services
 * triggered by this assessment's scores and answers, sorted by priority.
 *
 * @param  PDO    $pdo
 * @param  array  $assessment   Row from bhc_assessments (must have score_* columns + score_band)
 * @param  array  $answers      Array of ['question_id'=>N, 'option_key'=>'...'] rows
 * @param  string $lang
 * @return array  Services with extra key 'trigger_priority' and 'has_providers' (bool)
 */
function bhc_get_triggered_services(
    PDO $pdo,
    array $assessment,
    array $answers,
    string $lang = 'en'
): array {
    // Build lookup maps
    $answerMap = [];  // question_id → option_key
    foreach ($answers as $a) {
        $answerMap[(int)$a['question_id']] = $a['option_key'];
    }

    $categoryScores = [
        'online_visibility'    => (float)($assessment['score_online_visibility']    ?? 0),
        'customer_acquisition' => (float)($assessment['score_customer_acquisition'] ?? 0),
        'customer_trust'       => (float)($assessment['score_customer_trust']       ?? 0),
        'digital_presence'     => (float)($assessment['score_digital_presence']     ?? 0),
        'customer_management'  => (float)($assessment['score_customer_management']  ?? 0),
        'marketing_growth'     => (float)($assessment['score_marketing_growth']     ?? 0),
        'business_operations'  => (float)($assessment['score_business_operations']  ?? 0),
    ];

    $overallBand = $assessment['score_band'] ?? 'needs_attention';

    // Load all active triggers
    $triggers = $pdo->query(
        "SELECT t.*, s.id AS service_id
         FROM bhc_service_triggers t
         JOIN bhc_services s ON s.id = t.service_id AND s.is_active = 1
         WHERE t.is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    $triggeredServiceIds = [];  // service_id → highest priority score
    $priorityScore = ['high' => 3, 'medium' => 2, 'low' => 1];

    foreach ($triggers as $t) {
        $fired = false;

        switch ($t['trigger_type']) {
            case 'question_answer':
                $qid = (int)$t['question_id'];
                if (isset($answerMap[$qid]) && $answerMap[$qid] === $t['option_key']) {
                    $fired = true;
                }
                break;

            case 'category_band':
                $slug = $t['category_slug'];
                // Map category slug → band
                $catScore = $categoryScores[$slug] ?? null;
                if ($catScore !== null) {
                    $catBand = _bhc_score_to_band($catScore);
                    if ($catBand === $t['score_band']) {
                        $fired = true;
                    }
                }
                break;

            case 'category_pct':
                $slug = $t['category_slug'];
                $catScore = $categoryScores[$slug] ?? null;
                if ($catScore !== null) {
                    $pMin = (int)($t['pct_min'] ?? 0);
                    $pMax = (int)($t['pct_max'] ?? 100);
                    if ($catScore >= $pMin && $catScore <= $pMax) {
                        $fired = true;
                    }
                }
                break;
        }

        if ($fired) {
            $sid = (int)$t['service_id'];
            $score = $priorityScore[$t['priority']] ?? 2;
            if (!isset($triggeredServiceIds[$sid]) || $triggeredServiceIds[$sid] < $score) {
                $triggeredServiceIds[$sid] = $score;
            }
        }
    }

    if (!$triggeredServiceIds) {
        return [];
    }

    // Load matching services
    $ids = implode(',', array_map('intval', array_keys($triggeredServiceIds)));
    $nameCol = $lang === 'fr' ? 'name_fr' : 'name_en';
    $descCol = $lang === 'fr' ? 'description_fr' : 'description_en';

    $services = $pdo->query(
        "SELECT s.id, s.slug, s.`{$nameCol}` AS name, s.`{$descCol}` AS description,
                s.pricing_info, s.pricing_type, s.is_featured,
                c.`{$nameCol}` AS category_name, c.slug AS category_slug
         FROM bhc_services s
         JOIN bhc_service_categories c ON c.id = s.category_id
         WHERE s.id IN ({$ids}) AND s.is_active = 1 AND c.is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Annotate with priority + provider flag
    $providerCounts = bhc_get_provider_counts_for_services($pdo, array_keys($triggeredServiceIds));

    foreach ($services as &$svc) {
        $sid = (int)$svc['id'];
        $svc['trigger_priority_score'] = $triggeredServiceIds[$sid] ?? 1;
        $svc['trigger_priority'] = array_search(
            $triggeredServiceIds[$sid],
            $priorityScore
        ) ?: 'low';
        $svc['has_providers'] = ($providerCounts[$sid] ?? 0) > 0;
    }
    unset($svc);

    // Sort: priority score desc, then featured, then name
    usort($services, function ($a, $b) {
        if ($b['trigger_priority_score'] !== $a['trigger_priority_score']) {
            return $b['trigger_priority_score'] <=> $a['trigger_priority_score'];
        }
        if ($b['is_featured'] !== $a['is_featured']) {
            return $b['is_featured'] <=> $a['is_featured'];
        }
        return strcmp($a['name'], $b['name']);
    });

    return $services;
}

function bhc_get_featured_services(PDO $pdo, string $lang = 'en', int $limit = 6): array
{
    $nameCol = $lang === 'fr' ? 'name_fr' : 'name_en';
    $descCol = $lang === 'fr' ? 'description_fr' : 'description_en';

    $stmt = $pdo->prepare(
        "SELECT s.id, s.slug, s.`{$nameCol}` AS name, s.`{$descCol}` AS description,
                s.pricing_info, s.pricing_type,
                c.`{$nameCol}` AS category_name, c.slug AS category_slug
         FROM bhc_services s
         JOIN bhc_service_categories c ON c.id = s.category_id
         WHERE s.is_active = 1 AND s.is_featured = 1 AND c.is_active = 1
         ORDER BY s.sort_order ASC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Admin helpers ──────────────────────────────────────────────────────────

function bhc_get_provider_counts_for_services(PDO $pdo, array $serviceIds): array
{
    if (!$serviceIds) return [];
    $ids = implode(',', array_map('intval', $serviceIds));
    $rows = $pdo->query(
        "SELECT service_id, COUNT(*) AS cnt
         FROM bhc_provider_services ps
         JOIN bhc_service_providers p ON p.id = ps.provider_id AND p.is_active = 1
         WHERE ps.service_id IN ({$ids}) AND ps.is_active = 1
         GROUP BY service_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[(int)$r['service_id']] = (int)$r['cnt'];
    return $map;
}

function bhc_get_provider_services_list(PDO $pdo, int $providerId): array
{
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name_en, s.name_fr, s.slug, c.name_en AS cat_en,
                ps.is_primary, ps.is_active, ps.pricing_detail
         FROM bhc_provider_services ps
         JOIN bhc_services s ON s.id = ps.service_id
         JOIN bhc_service_categories c ON c.id = s.category_id
         WHERE ps.provider_id = :pid
         ORDER BY c.sort_order, s.sort_order"
    );
    $stmt->execute([':pid' => $providerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Internal ───────────────────────────────────────────────────────────────

function _bhc_score_to_band(float $score): string
{
    if ($score >= 80) return 'strong_foundations';
    if ($score >= 60) return 'good_progress';
    if ($score >= 40) return 'developing';
    return 'needs_attention';
}
