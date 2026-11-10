<?php
/**
 * One-time setup: create audit_questions and audit_responses tables.
 * DELETE this file after running.
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();

$pdo->exec("
CREATE TABLE IF NOT EXISTS audit_questions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    section_title VARCHAR(120)     NOT NULL DEFAULT '',
    sort_order    SMALLINT         NOT NULL DEFAULT 0,
    question_text TEXT             NOT NULL,
    question_type ENUM('text','textarea','radio','checkbox') NOT NULL DEFAULT 'text',
    options_json  TEXT             NULL COMMENT 'JSON array of option strings for radio/checkbox',
    is_required   TINYINT(1)       NOT NULL DEFAULT 1,
    is_active     TINYINT(1)       NOT NULL DEFAULT 1,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Make partner_leads.listing_id nullable (audit leads have no listing)
try {
    $pdo->exec("ALTER TABLE partner_leads MODIFY listing_id INT UNSIGNED NULL COMMENT 'NULL for audit leads'");
} catch (Exception $e) { /* already nullable */ }

// Add slug column to partner_profiles if missing
try {
    $pdo->exec("ALTER TABLE partner_profiles ADD COLUMN slug VARCHAR(80) NULL UNIQUE AFTER display_name");
    echo "<p style='color:green'>✓ Added slug column to partner_profiles.</p>";
} catch (Exception $e) { /* column already exists */ }

$pdo->exec("
CREATE TABLE IF NOT EXISTS audit_responses (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id    INT UNSIGNED NOT NULL COMMENT 'partner_profiles.id',
    lead_id       INT UNSIGNED NULL     COMMENT 'partner_leads.id — set after lead row created',
    business_name VARCHAR(200) NOT NULL,
    owner_name    VARCHAR(200) NOT NULL,
    phone         VARCHAR(50)  NOT NULL,
    email         VARCHAR(200) NOT NULL DEFAULT '',
    submitted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answers_json  LONGTEXT     NOT NULL COMMENT 'JSON object: question_id => answer',
    ip            VARCHAR(45)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Seed questions
$existing = $pdo->query("SELECT COUNT(*) FROM audit_questions")->fetchColumn();
if (!$existing) {
    $sections = [
        1 => 'Know Your Business',
        2 => 'How Visible Is Your Business?',
        3 => 'Turn Visibility Into Customers',
        4 => 'Your Next Step',
    ];
    $qs = [
        [1,1,'Know Your Business',       'What product or service do you offer?',                              'textarea', null],
        [2,1,'Know Your Business',       'Who is your ideal customer?',                                        'textarea', null],
        [3,1,'Know Your Business',       'What makes your business different?',                                'textarea', null],
        [4,2,'How Visible Is Your Business?', 'Where can customers currently find your business? (tick all that apply)', 'checkbox',
            json_encode(['WhatsApp','Facebook','TikTok','Instagram','Google Search / Google Maps','Online business directory','Physical location / word of mouth only'])],
        [5,2,'How Visible Is Your Business?', 'If a new customer searches online for your product or service in your town, can they easily find you?', 'radio',
            json_encode(['Yes','No','I\'m not sure'])],
        [6,2,'How Visible Is Your Business?', 'Do you have a complete online business profile with your location, contact details, business description and photos?', 'radio',
            json_encode(['Yes','Partially','No'])],
        [7,2,'How Visible Is Your Business?', 'How often do you actively promote your business online?', 'radio',
            json_encode(['Daily','Several times a week','Occasionally','Almost never'])],
        [8,3,'Turn Visibility Into Customers', 'How do most of your customers discover your business?',       'textarea', null],
        [9,3,'Turn Visibility Into Customers', 'What is your biggest challenge? (tick all that apply)',        'checkbox',
            json_encode(['Not enough people know about my business','People see my business but rarely enquire','I struggle to create content consistently','I need better marketing and promotion','I struggle to turn enquiries into sales','I don\'t know which marketing strategy to use'])],
        [10,3,'Turn Visibility Into Customers','Do you have a clear way for interested customers to contact you, request a service or make a booking?', 'radio',
            json_encode(['Yes','Partially','No'])],
        [11,3,'Turn Visibility Into Customers','What have you tried so far to attract more customers? What worked, and what didn\'t?', 'textarea', null],
        [12,4,'Your Next Step', 'What is one thing you would like to improve about your business visibility in the next 30 days?', 'textarea', null],
        [13,4,'Your Next Step', 'Which kind of support would be most useful to you? (tick all that apply)', 'checkbox',
            json_encode(['Get my business listed online','Improve my online business profile','Create promotional videos and content','Get my business featured and promoted','Develop a marketing strategy','Get help with several of these areas'])],
        [14,4,'Your Next Step', 'Would you like to learn more about the relevant services offered by 237Biz.net or The Tahirih Effect?', 'radio',
            json_encode(['237Biz.net','The Tahirih Effect','Both','Not at the moment'])],
        [15,4,'Your Next Step', 'One practical action I will take this week is:', 'text', null],
    ];
    $ins = $pdo->prepare("INSERT INTO audit_questions (sort_order,section,section_title,question_text,question_type,options_json) VALUES (?,?,?,?,?,?)");
    foreach ($qs as $q) {
        $ins->execute($q);
    }
    echo "<p style='color:green'>✓ Tables created and " . count($qs) . " questions seeded.</p>";
} else {
    echo "<p style='color:orange'>Tables already exist ({$existing} questions found). No changes made.</p>";
}
echo "<p><a href='/admin/manage-growth-partners.php'>← Back to Growth Partners</a></p>";
