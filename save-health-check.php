<?php
/**
 * 237biz Digital Health Check — save/submit endpoint
 */

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/health-check-questions.php';
require_once __DIR__ . '/includes/health-check-scoring.php';
$pdo = db();

function current_staff_id(): ?int {
    $user = currentStaffUser();
    return $user ? (int) $user['id'] : null;
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'create_share_link') {
    $staffId = current_staff_id();
    if (!$staffId) json_out(['error' => 'Staff login required'], 401);

    $code = generate_referral_code();
    // extremely unlikely collision, but check anyway
    $stmt = $pdo->prepare("SELECT id FROM health_assessments WHERE referral_code = ?");
    $stmt->execute([$code]);
    while ($stmt->fetch()) {
        $code = generate_referral_code();
        $stmt->execute([$code]);
    }

    $ins = $pdo->prepare("INSERT INTO health_assessments (referral_code, agent_id, source, status, created_at)
                           VALUES (?, ?, 'whatsapp', 'sent', NOW())");
    $ins->execute([$code, $staffId]);

    $shareUrl = 'https://237biz.net/health-check.php?ref=' . $code;
    $message = "Hello, we'd like to invite you to complete the free 237biz Business Digital Health Check. "
             . "It takes just a few minutes and will give you a score showing how strong your business's online presence is.\n\n"
             . $shareUrl;

    json_out([
        'referral_code' => $code,
        'share_url' => $shareUrl,
        'whatsapp_url' => 'https://wa.me/?text=' . rawurlencode($message),
    ]);
}

if ($action === 'save_step') {
    $ref = trim($_POST['ref'] ?? '');
    $step = (int) ($_POST['step'] ?? 0);
    $fields = json_decode($_POST['fields'] ?? '{}', true) ?: [];
    $answers = json_decode($_POST['answers'] ?? '{}', true) ?: [];
    $staffId = current_staff_id();

    if ($ref === '') {
        // Fresh agent-mode assessment — must be logged in
        if (!$staffId) json_out(['error' => 'Staff login required'], 401);
        $ref = generate_referral_code();
        $ins = $pdo->prepare("INSERT INTO health_assessments
            (referral_code, agent_id, source, status, started_at, created_at)
            VALUES (?, ?, 'agent', 'started', NOW(), NOW())");
        $ins->execute([$ref, $staffId]);
    } else {
        // Existing assessment (agent continuing, or public/WhatsApp respondent)
        $stmt = $pdo->prepare("SELECT * FROM health_assessments WHERE referral_code = ?");
        $stmt->execute([$ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['error' => 'Assessment not found'], 404);

        if ($row['status'] === 'sent') {
            $pdo->prepare("UPDATE health_assessments SET status = 'started', started_at = NOW() WHERE referral_code = ?")
                ->execute([$ref]);
        }
    }

    // Merge with any previously saved answers
    $existing = $pdo->prepare("SELECT answers_json FROM health_assessments WHERE referral_code = ?");
    $existing->execute([$ref]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
    $mergedAnswers = array_merge(
        $existingRow && $existingRow['answers_json'] ? json_decode($existingRow['answers_json'], true) : [],
        $answers
    );

    $sets = ['answers_json = ?', 'updated_at = NOW()'];
    $params = [json_encode($mergedAnswers)];

    // Business-info fields (step 1) get their own columns for easy listing/search later
    $allowedFields = ['business_name','business_category','city','contact_name','whatsapp_number','business_phone','years_in_business','preferred_language'];
    foreach ($allowedFields as $f) {
        if (isset($fields[$f])) {
            $sets[] = "$f = ?";
            $params[] = $fields[$f];
        }
    }
    $params[] = $ref;

    $pdo->prepare("UPDATE health_assessments SET " . implode(', ', $sets) . " WHERE referral_code = ?")
        ->execute($params);

    json_out(['ok' => true, 'ref' => $ref]);
}

if ($action === 'submit') {
    $ref = trim($_POST['ref'] ?? '');
    if ($ref === '') json_out(['error' => 'Missing assessment reference'], 400);

    $stmt = $pdo->prepare("SELECT * FROM health_assessments WHERE referral_code = ?");
    $stmt->execute([$ref]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_out(['error' => 'Assessment not found'], 404);

    $answers = $row['answers_json'] ? json_decode($row['answers_json'], true) : [];
    // allow final-step answers to be included in the same request
    $finalAnswers = json_decode($_POST['answers'] ?? '{}', true) ?: [];
    $answers = array_merge($answers, $finalAnswers);

    $scoring = calculate_health_check_score($answers);
    $opportunity = calculate_opportunity_score($answers, $scoring['total_score']);
    $cs = $scoring['category_scores'];

    $pdo->prepare("UPDATE health_assessments SET
            answers_json = ?,
            score_online_presence = ?, score_customer_discovery = ?, score_social_media = ?,
            score_customer_engagement = ?, score_digital_marketing = ?,
            total_score = ?, score_band = ?, opportunity_score = ?,
            event_interest = ?, status = 'completed', completed_at = NOW(), updated_at = NOW()
        WHERE referral_code = ?")
        ->execute([
            json_encode($answers),
            $cs['online_presence'], $cs['customer_discovery'], $cs['social_media'],
            $cs['customer_engagement'], $cs['digital_marketing'],
            $scoring['total_score'], $scoring['band'], $opportunity,
            $answers['event_interest'] ?? null,
            $ref,
        ]);

    json_out(['ok' => true, 'ref' => $ref, 'redirect' => 'health-check-results.php?ref=' . $ref]);
}

json_out(['error' => 'Unknown action'], 400);
