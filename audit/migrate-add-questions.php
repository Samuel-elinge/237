<?php
/**
 * One-time migration: add "Years in business" and "Number of staff" questions
 * Run once: https://237biz.net/audit/migrate-add-questions.php?key=run237migrate
 * DELETE this file after running.
 */
require_once __DIR__ . '/../includes/config.php';

$key = $_GET['key'] ?? '';
if ($key !== 'run237migrate') {
    http_response_code(403);
    echo 'Forbidden — pass ?key=run237migrate';
    exit;
}

$pdo = db();

// ── Find the "Know Your Business" section details ────────────────────────────
// We'll insert after sort_order 3 (What makes your business unique)
// First check what currently exists
$existing = $pdo->query("
    SELECT id, sort_order, section, section_title, question_text
    FROM audit_questions
    WHERE is_active = 1
    ORDER BY section, sort_order
")->fetchAll(PDO::FETCH_ASSOC);

echo '<pre>';
echo "Existing questions:\n";
foreach ($existing as $r) {
    echo "  section={$r['section']} sort={$r['sort_order']} id={$r['id']}: {$r['question_text']}\n";
}

// Find the section name and title for "Know Your Business"
$knowYourBizSection = null;
$knowYourBizTitle   = null;
$highestSortInSec1  = 0;
foreach ($existing as $r) {
    if (stripos($r['section_title'], 'Know Your Business') !== false
        || stripos($r['section_title'], 'business') !== false && $r['section'] == 1) {
        $knowYourBizSection = $r['section'];
        $knowYourBizTitle   = $r['section_title'];
        if ((int)$r['sort_order'] > $highestSortInSec1) {
            $highestSortInSec1 = (int)$r['sort_order'];
        }
    }
}

if (!$knowYourBizSection) {
    // Fall back: use section of first question
    $knowYourBizSection = $existing[0]['section'] ?? 1;
    $knowYourBizTitle   = $existing[0]['section_title'] ?? 'Know Your Business';
    foreach ($existing as $r) {
        if ($r['section'] == $knowYourBizSection && (int)$r['sort_order'] > $highestSortInSec1) {
            $highestSortInSec1 = (int)$r['sort_order'];
        }
    }
}

echo "\nTarget section: {$knowYourBizSection} — {$knowYourBizTitle}\n";
echo "Highest sort_order in that section: {$highestSortInSec1}\n\n";

// Check if already inserted
$already = $pdo->query("
    SELECT COUNT(*) FROM audit_questions
    WHERE question_text LIKE '%years in business%' OR question_text LIKE '%How many staff%'
")->fetchColumn();

if ($already > 0) {
    echo "Questions already exist — nothing to insert.\n";
    echo '</pre>';
    exit;
}

// ── Insert the two new questions ─────────────────────────────────────────────
$newSort1 = $highestSortInSec1 + 1;
$newSort2 = $highestSortInSec1 + 2;

// Also need to bump sort_order of subsequent questions in later sections
// (sort_order is global in the form, used for FR translation keying)
// Check if sort_order is global or per-section:
$globalMax = (int)$pdo->query("SELECT MAX(sort_order) FROM audit_questions WHERE is_active=1")->fetchColumn();
echo "Global max sort_order: {$globalMax}\n";

// If sort_order runs continuously we need to bump later questions
// Bump all questions whose sort_order >= $newSort1 and section > $knowYourBizSection
$pdo->prepare("
    UPDATE audit_questions
    SET sort_order = sort_order + 2
    WHERE is_active = 1
    AND sort_order >= ?
    AND section > ?
")->execute([$newSort1, $knowYourBizSection]);
echo "Bumped sort_order for later sections.\n";

// Insert question 1: Years in business
$stmt = $pdo->prepare("
    INSERT INTO audit_questions
        (section, section_title, question_text, question_type, options_json, sort_order, is_required, is_active)
    VALUES (?, ?, ?, ?, ?, ?, 1, 1)
");
$stmt->execute([
    $knowYourBizSection,
    $knowYourBizTitle,
    'How long have you been in business?',
    'radio',
    json_encode(['Less than 1 year', '1–3 years', '3–5 years', '5–10 years', 'More than 10 years']),
    $newSort1,
]);
$id1 = $pdo->lastInsertId();
echo "Inserted question {$id1}: 'How long have you been in business?' (sort {$newSort1})\n";

// Insert question 2: Number of staff
$stmt->execute([
    $knowYourBizSection,
    $knowYourBizTitle,
    'How many staff do you have (including yourself)?',
    'radio',
    json_encode(['Just me', '2–5', '6–10', '11–25', 'More than 25']),
    $newSort2,
]);
$id2 = $pdo->lastInsertId();
echo "Inserted question {$id2}: 'How many staff do you have?' (sort {$newSort2})\n";

echo "\n✅ Done. Delete this file now: audit/migrate-add-questions.php\n";
echo '</pre>';
