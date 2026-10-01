<?php
/**
 * 237biz Digital Health Check — admin-only question CRUD endpoint
 */
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config.php';
$pdo = db();
require_once __DIR__ . '/../includes/health-check-questions.php';

requireAdmin(); // your existing admin-only check (dies/redirects if not admin)

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'question_save') {
    $id = (int) ($_POST['id'] ?? 0);
    $key = trim($_POST['question_key'] ?? '');
    $step = (int) ($_POST['step'] ?? 2);
    $category = trim($_POST['category'] ?? '');
    $type = trim($_POST['type'] ?? 'single');
    $labelEn = trim($_POST['label_en'] ?? '');
    $labelFr = trim($_POST['label_fr'] ?? '');
    $optionsJson = $_POST['options_json'] ?? null;
    $scaleJson = $_POST['scale_points_json'] ?? null;
    $maxPoints = (float) ($_POST['max_points'] ?? 0);
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($key === '' || $labelEn === '' || $category === '') {
        json_out(['error' => 'question_key, label_en and category are required'], 400);
    }
    if (!array_key_exists($category, CATEGORY_WEIGHTS)) {
        json_out(['error' => 'Invalid category'], 400);
    }
    if ($optionsJson !== null && $optionsJson !== '' && json_decode($optionsJson) === null) {
        json_out(['error' => 'options_json is not valid JSON'], 400);
    }
    if ($scaleJson !== null && $scaleJson !== '' && json_decode($scaleJson) === null) {
        json_out(['error' => 'scale_points_json is not valid JSON'], 400);
    }

    if ($id > 0) {
        $pdo->prepare("UPDATE health_questions SET
                step=?, category=?, type=?, label_en=?, label_fr=?, options_json=?, scale_points_json=?,
                max_points=?, sort_order=?, updated_at=NOW()
            WHERE id=?")
            ->execute([$step, $category, $type, $labelEn, $labelFr, $optionsJson ?: null, $scaleJson ?: null, $maxPoints, $sortOrder, $id]);
    } else {
        $check = $pdo->prepare("SELECT id FROM health_questions WHERE question_key = ?");
        $check->execute([$key]);
        if ($check->fetch()) json_out(['error' => 'That question_key already exists'], 409);

        $pdo->prepare("INSERT INTO health_questions
                (question_key, step, category, type, label_en, label_fr, options_json, scale_points_json, max_points, sort_order, active)
                VALUES (?,?,?,?,?,?,?,?,?,?,1)")
            ->execute([$key, $step, $category, $type, $labelEn, $labelFr, $optionsJson ?: null, $scaleJson ?: null, $maxPoints, $sortOrder]);
    }
    json_out(['ok' => true]);
}

if ($action === 'question_toggle_active') {
    $id = (int) ($_POST['id'] ?? 0);
    $active = (int) ($_POST['active'] ?? 1);
    $pdo->prepare("UPDATE health_questions SET active = ?, updated_at = NOW() WHERE id = ?")->execute([$active, $id]);
    json_out(['ok' => true]);
}

json_out(['error' => 'Unknown action'], 400);
