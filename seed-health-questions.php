<?php
/**
 * Run this ONCE after creating the health_questions table (schema-v2.sql) to
 * populate it from the V1 hard-coded question bank. Safe to re-run — it skips
 * any question_key that already exists.
 *
 * Delete this file from the server after running it once.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
$pdo = db();
require_once __DIR__ . '/includes/health-check-questions.php';

requireAdmin(); // admin-only — this seeds the whole question bank

$defaults = get_default_health_check_questions();
$stepMap = QUESTION_STEP_MAP;

$check = $pdo->prepare("SELECT id FROM health_questions WHERE question_key = ?");
$insert = $pdo->prepare("INSERT INTO health_questions
    (question_key, step, category, type, label_en, label_fr, options_json, scale_points_json, max_points, sort_order, active)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

$inserted = 0;
$skipped = 0;
$order = 0;

foreach ($defaults as $key => $q) {
    $order += 10;
    $check->execute([$key]);
    if ($check->fetch()) { $skipped++; continue; }

    $step = $stepMap[$key] ?? 2;
    $optionsJson = isset($q['options']) ? json_encode($q['options']) : null;
    $scaleJson = isset($q['scale_points']) ? json_encode($q['scale_points']) : null;

    $insert->execute([
        $key, $step, $q['category'], $q['type'],
        $q['label_en'], $q['label_fr'],
        $optionsJson, $scaleJson, $q['max'], $order,
    ]);
    $inserted++;
}

echo "Seeding complete. Inserted: $inserted, skipped (already existed): $skipped.\n";
echo "You can now delete this file (seed-health-questions.php) from the server.\n";
