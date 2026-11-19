<?php
/**
 * Business Health Check — server-side scoring engine.
 * NEVER accept browser-submitted scores.
 * Input: array of [question_id => option_key]
 * Output: structured score result.
 */

defined('IN_237BIZ') or die('Direct access denied');

require_once __DIR__ . '/bhc-config.php';

/**
 * Calculate the full score from an answer map.
 *
 * @param  array $answers  Map of question_id (int) => option_key (string)
 * @return array {
 *   total_score: float,
 *   band: string,
 *   category_scores: array<slug, float>,   // weighted contribution per category (0-weight)
 *   category_pct:    array<slug, float>,   // 0-100 percentage within that category
 *   questions_answered: int,               // non-N/A answers
 *   questions_applicable: int,             // questions where N/A was not chosen
 * }
 */
function bhc_calculate_score(array $answers): array {
    $categories  = bhc_load_categories();
    $all_questions = bhc_load_questions();

    $category_raw   = [];  // slug => [sum, max]
    $q_answered     = 0;
    $q_applicable   = 0;

    foreach ($all_questions as $slug => $questions) {
        $sum = 0;
        $max = 0;
        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $key = $answers[$qid] ?? null;
            if ($key === null || $key === '') continue; // unanswered — skip

            $opt = BHC_OPTIONS[$key] ?? null;
            if ($opt === null) continue;

            if ($opt['score'] === -1) {
                // N/A — excluded from score, neither adds to sum nor max
                continue;
            }
            // applicable (not N/A)
            $sum += $opt['score'];
            $max += BHC_MAX_SCORE_PER_Q;
            $q_applicable++;
            $q_answered++;
        }
        $category_raw[$slug] = ['sum' => $sum, 'max' => $max];
    }

    // Build weighted total using N/A redistribution:
    // If some questions are N/A, the remaining applicable questions
    // carry the full weight of the category.
    $total      = 0.0;
    $cat_scores = [];
    $cat_pct    = [];

    foreach ($categories as $slug => $cat) {
        $weight = (float) $cat['weight'];
        $data   = $category_raw[$slug] ?? ['sum' => 0, 'max' => 0];

        if ($data['max'] === 0) {
            // All questions N/A — category excluded; weight redistributed proportionally
            $cat_scores[$slug] = 0.0;
            $cat_pct[$slug]    = 0.0;
            continue;
        }

        $pct              = ($data['sum'] / $data['max']) * 100.0;
        $weighted         = ($pct / 100.0) * $weight;
        $cat_scores[$slug] = round($weighted, 2);
        $cat_pct[$slug]    = round($pct, 2);
    }

    // Handle N/A-excluded categories: redistribute their weight proportionally
    // among categories that have at least one applicable answer.
    $excluded_weight = 0.0;
    $included_weight = 0.0;
    foreach ($categories as $slug => $cat) {
        $data = $category_raw[$slug] ?? ['max' => 0];
        if ($data['max'] === 0) {
            $excluded_weight += (float) $cat['weight'];
        } else {
            $included_weight += (float) $cat['weight'];
        }
    }

    // If some categories fully excluded, scale the included ones up
    if ($excluded_weight > 0 && $included_weight > 0) {
        $scale = 100.0 / $included_weight;
        foreach ($cat_scores as $slug => $ws) {
            $cat_scores[$slug] = round($ws * $scale, 2);
        }
    }

    $total = array_sum($cat_scores);
    $total = min(100.0, max(0.0, round($total, 2)));

    return [
        'total_score'          => $total,
        'band'                 => bhc_score_band($total),
        'category_scores'      => $cat_scores,   // weighted contribution
        'category_pct'         => $cat_pct,       // raw % within category
        'questions_answered'   => $q_answered,
        'questions_applicable' => $q_applicable,
    ];
}

/**
 * Persist scores to bhc_assessments and bhc_answers tables.
 * Returns true on success.
 */
function bhc_save_scores(int $assessment_id, array $answers, array $result): bool {
    $pdo = db();
    try {
        $pdo->beginTransaction();

        // Upsert individual answers
        $upsert = $pdo->prepare(
            "INSERT INTO bhc_answers (assessment_id, question_id, option_key, score)
             VALUES (:aid, :qid, :okey, :score)
             ON DUPLICATE KEY UPDATE option_key = VALUES(option_key), score = VALUES(score)"
        );
        foreach ($answers as $qid => $key) {
            $opt = BHC_OPTIONS[$key] ?? null;
            if ($opt === null) continue;
            $upsert->execute([
                ':aid'   => $assessment_id,
                ':qid'   => (int) $qid,
                ':okey'  => $key,
                ':score' => $opt['score'],
            ]);
        }

        // Update assessment score columns
        $cols = $result['category_scores'];
        $pdo->prepare(
            "UPDATE bhc_assessments SET
               score_online_visibility    = :s_ov,
               score_customer_acquisition = :s_ca,
               score_customer_trust       = :s_ct,
               score_digital_presence     = :s_dp,
               score_customer_management  = :s_cm,
               score_marketing_growth     = :s_mg,
               score_business_operations  = :s_bo,
               total_score                = :total,
               score_band                 = :band,
               questions_answered         = :qa,
               questions_applicable       = :qap,
               status                     = 'submitted',
               submitted_at               = NOW()
             WHERE id = :id"
        )->execute([
            ':s_ov'  => $cols['online_visibility']    ?? 0,
            ':s_ca'  => $cols['customer_acquisition'] ?? 0,
            ':s_ct'  => $cols['customer_trust']       ?? 0,
            ':s_dp'  => $cols['digital_presence']     ?? 0,
            ':s_cm'  => $cols['customer_management']  ?? 0,
            ':s_mg'  => $cols['marketing_growth']     ?? 0,
            ':s_bo'  => $cols['business_operations']  ?? 0,
            ':total' => $result['total_score'],
            ':band'  => $result['band'],
            ':qa'    => $result['questions_answered'],
            ':qap'   => $result['questions_applicable'],
            ':id'    => $assessment_id,
        ]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('bhc_save_scores error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Generate a secure access token for report/resume access.
 * Returns the RAW token (send in link); only the hash is stored.
 */
function bhc_create_access_token(int $assessment_id, string $type = 'report', ?DateTime $expires = null): string {
    $raw_token = bin2hex(random_bytes(32)); // 64 hex chars
    $hash      = hash('sha256', $raw_token);

    $pdo = db();
    $pdo->prepare(
        "INSERT INTO bhc_access_tokens (assessment_id, token_type, token_hash, expires_at)
         VALUES (:aid, :type, :hash, :exp)"
    )->execute([
        ':aid'  => $assessment_id,
        ':type' => $type,
        ':hash' => $hash,
        ':exp'  => $expires ? $expires->format('Y-m-d H:i:s') : null,
    ]);

    return $raw_token;
}

/**
 * Validate a raw token; returns assessment_id or false.
 */
function bhc_validate_access_token(string $raw_token, string $type = 'report') /* : int|false */ {
    $hash = hash('sha256', $raw_token);
    $pdo  = db();
    $stmt = $pdo->prepare(
        "SELECT id, assessment_id, expires_at, revoked
         FROM bhc_access_tokens
         WHERE token_hash = :hash AND token_type = :type"
    );
    $stmt->execute([':hash' => $hash, ':type' => $type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return false;
    if ($row['revoked']) return false;
    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) return false;

    // Record usage
    $pdo->prepare("UPDATE bhc_access_tokens SET used_at = NOW() WHERE id = :id")
        ->execute([':id' => $row['id']]);

    return (int) $row['assessment_id'];
}
