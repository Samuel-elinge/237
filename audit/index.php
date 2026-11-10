<?php
/**
 * audit/index.php — Business Visibility Audit
 * URL: /audit/[partner-slug]  (e.g. /audit/tahirih)
 *
 * partner-slug = partner_profiles.slug  OR  users.email prefix  OR  pp.id
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();

// ── Resolve partner from URL slug ─────────────────────────────────────────
$slug = trim($_GET['p'] ?? basename($_SERVER['REQUEST_URI'] ?? ''), '/ ');
// Strip query string if any
if (($q = strpos($slug, '?')) !== false) $slug = substr($slug, 0, $q);

$partner = null;
if ($slug) {
    // Try slug column first, then id, then user email prefix
    $partner = $pdo->prepare("
        SELECT pp.id AS pp_id, pp.display_name, pp.slug, pp.status,
               u.name AS user_name, u.email AS user_email
        FROM partner_profiles pp
        JOIN users u ON u.id = pp.user_id
        WHERE pp.slug = ? OR pp.id = ? OR u.email LIKE ?
        LIMIT 1
    ");
    $partner->execute([$slug, is_numeric($slug) ? (int)$slug : 0, $slug . '@%']);
    $partner = $partner->fetch();
}

if (!$partner || $partner['status'] !== 'approved') {
    http_response_code(404);
    echo "<!doctype html><html><head><title>Not Found</title></head><body style='font-family:sans-serif;padding:2rem;'><h2>Audit link not found</h2><p>Please ask your partner for the correct link.</p></body></html>";
    exit;
}

$partnerId    = (int)$partner['pp_id'];
$partnerName  = $partner['display_name'] ?: $partner['user_name'];
$partnerEmail = $partner['user_email'];

// ── Load published blog posts (marketing tips) ───────────────────────────
$blogPosts = [];
try {
    $blogPosts = $pdo->query("
        SELECT id, slug, title_en, title_fr, category, published_at
        FROM blog_posts
        WHERE status = 'published'
        ORDER BY published_at DESC
        LIMIT 6
    ")->fetchAll();
} catch (Exception $e) { /* table may not exist on dev */ }

// ── Load active questions ─────────────────────────────────────────────────
$questions = $pdo->query("
    SELECT * FROM audit_questions
    WHERE is_active = 1
    ORDER BY section ASC, sort_order ASC, id ASC
")->fetchAll();

// Group by section
$sections = [];
foreach ($questions as $q) {
    $sections[$q['section']]['title'] = $q['section_title'];
    $sections[$q['section']]['questions'][] = $q;
}

// ── Handle POST submission ────────────────────────────────────────────────
$submitted = false;
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bizName   = trim($_POST['business_name'] ?? '');
    $ownerName = trim($_POST['owner_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');

    if (!$bizName)   $errors[] = 'Business name is required.';
    if (!$ownerName) $errors[] = 'Your name is required.';
    if (!$phone)     $errors[] = 'Phone number is required.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

    // Collect answers
    $answers = [];
    foreach ($questions as $q) {
        $key = 'q' . $q['id'];
        if ($q['question_type'] === 'checkbox') {
            $val = $_POST[$key] ?? [];
            $answers[$q['id']] = is_array($val) ? $val : [];
        } else {
            $answers[$q['id']] = trim($_POST[$key] ?? '');
        }
        if ($q['is_required'] && empty($answers[$q['id']])) {
            // Don't block on empty answers for open questions — only flag truly blank required ones
            // (keep UX friendly for paper-to-digital transition)
        }
    }

    if (!$errors) {
        // 1. Save audit response
        $pdo->prepare("
            INSERT INTO audit_responses (partner_id, business_name, owner_name, phone, email, answers_json, ip)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$partnerId, $bizName, $ownerName, $phone, $email, json_encode($answers), $_SERVER['REMOTE_ADDR'] ?? null]);
        $responseId = (int)$pdo->lastInsertId();

        // 2. Create partner_lead row
        $pdo->prepare("
            INSERT INTO partner_leads (partner_id, customer_name, customer_email, customer_phone,
                                       status, source, notes, created_at)
            VALUES (?,?,?,?,'new','audit',?,NOW())
        ")->execute([
            $partnerId,
            $ownerName,
            $email ?: null,
            $phone,
            'Business: ' . $bizName . "\n\nQ12 (30-day goal): " . ($answers[12] ?? '') . "\n\nQ15 (action this week): " . ($answers[15] ?? ''),
        ]);
        $leadId = (int)$pdo->lastInsertId();

        // 3. Link lead back to response
        $pdo->prepare("UPDATE audit_responses SET lead_id=? WHERE id=?")->execute([$leadId, $responseId]);

        // 4. Email the partner
        try {
            $summaryRows = '';
            foreach ($questions as $q) {
                $ans = $answers[$q['id']] ?? '';
                if (is_array($ans)) $ans = implode(', ', $ans);
                if ($ans === '') continue;
                $summaryRows .= "<tr>
                    <td style='padding:8px 10px;border-bottom:1px solid #eee;color:#555;font-size:13px;vertical-align:top;width:220px;'>" . htmlspecialchars($q['question_text']) . "</td>
                    <td style='padding:8px 10px;border-bottom:1px solid #eee;color:#111;font-size:13px;'>" . nl2br(htmlspecialchars($ans)) . "</td>
                </tr>";
            }
            $emailBody = "
<div style='font-family:Arial,sans-serif;background:#fff;color:#111;padding:24px;max-width:640px;margin:0 auto;'>
  <div style='background:#00A878;padding:16px 24px;border-radius:8px 8px 0 0;'>
    <span style='color:#fff;font-size:18px;font-weight:bold;'>237Biz.net</span>
    <span style='color:rgba(255,255,255,0.8);font-size:13px;margin-left:8px;'>New Audit Lead</span>
  </div>
  <div style='border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;padding:24px;'>
    <h2 style='color:#00A878;font-size:17px;margin:0 0 4px;'>🎉 New lead from your audit link!</h2>
    <p style='color:#333;font-size:14px;margin:0 0 16px;'>Someone has completed the Business Visibility Audit through your link.</p>
    <table style='width:100%;border-collapse:collapse;margin-bottom:20px;background:#f9f9f9;border-radius:6px;overflow:hidden;'>
      <tr><td style='padding:10px;font-weight:bold;color:#555;font-size:13px;width:120px;'>Business</td><td style='padding:10px;font-size:14px;color:#111;'>" . htmlspecialchars($bizName) . "</td></tr>
      <tr><td style='padding:10px;font-weight:bold;color:#555;font-size:13px;'>Name</td><td style='padding:10px;font-size:14px;'>" . htmlspecialchars($ownerName) . "</td></tr>
      <tr><td style='padding:10px;font-weight:bold;color:#555;font-size:13px;'>Phone</td><td style='padding:10px;font-size:14px;'><a href='tel:" . htmlspecialchars($phone) . "' style='color:#00A878;'>" . htmlspecialchars($phone) . "</a></td></tr>
      " . ($email ? "<tr><td style='padding:10px;font-weight:bold;color:#555;font-size:13px;'>Email</td><td style='padding:10px;font-size:14px;'><a href='mailto:" . htmlspecialchars($email) . "' style='color:#00A878;'>" . htmlspecialchars($email) . "</a></td></tr>" : '') . "
    </table>
    <h3 style='font-size:14px;color:#333;margin:0 0 10px;'>Their Answers:</h3>
    <table style='width:100%;border-collapse:collapse;'>$summaryRows</table>
    <div style='margin-top:24px;'>
      <a href='" . SITE_URL . "/partner/leads.php' style='display:inline-block;background:#00A878;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:bold;'>View in Partner Dashboard →</a>
    </div>
  </div>
</div>";
            sendMail($partnerEmail, "🎉 New Audit Lead: " . $bizName, $emailBody);
        } catch (Exception $e) { /* non-fatal */ }

        // 5. Send confirmation to lead if they gave email
        if ($email) {
            try {
                $confirmBody = "
<div style='font-family:Arial,sans-serif;background:#fff;color:#111;padding:24px;max-width:600px;margin:0 auto;'>
  <div style='background:#00A878;padding:16px 24px;border-radius:8px 8px 0 0;'>
    <span style='color:#fff;font-size:18px;font-weight:bold;'>237Biz.net</span>
  </div>
  <div style='border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;padding:24px;'>
    <h2 style='color:#00A878;font-size:17px;margin:0 0 12px;'>✅ Your Business Visibility Audit is complete!</h2>
    <p style='color:#333;font-size:14px;margin:0 0 12px;'>Hi " . htmlspecialchars($ownerName) . ",</p>
    <p style='color:#333;font-size:14px;margin:0 0 12px;'>Thank you for completing the Business Visibility Audit. " . htmlspecialchars($partnerName) . " will be in touch with you soon.</p>
    <div style='background:#f5f5f5;border-left:3px solid #00A878;padding:12px 16px;border-radius:0 6px 6px 0;margin:16px 0;'>
      <p style='color:#333;font-size:13px;margin:0;'><strong>Your 30-day goal:</strong> " . htmlspecialchars($answers[12] ?? 'Not provided') . "</p>
    </div>
    <p style='color:#555;font-size:13px;'>In the meantime, you can list your business for free on 237Biz.net to increase your online visibility.</p>
    <a href='" . SITE_URL . "/register' style='display:inline-block;background:#00A878;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:14px;'>List My Business Free →</a>
  </div>
</div>";
                sendMail($email, "Your Business Visibility Audit — 237Biz.net", $confirmBody);
            } catch (Exception $e) { /* non-fatal */ }
        }

        $submitted = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Business Visibility Audit — 237Biz.net</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,700;1,400&family=Inter:wght@400;500;600&display=swap">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --green:#00A878;--green-dark:#008a63;--bg:#f4f7f5;
  --card:#fff;--border:#dde8e3;--text:#1a1a1a;--muted:#6b7c74;
  --radius:12px;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-bottom:3rem;}

/* Header */
.audit-header{background:#0a1e15;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;}
.audit-header .logo{color:#fff;font-family:'Fraunces',serif;font-size:1.3rem;text-decoration:none;}
.audit-header .logo span{color:var(--green);}
.audit-header .partner-tag{font-size:0.75rem;color:rgba(255,255,255,0.55);border-left:1px solid rgba(255,255,255,0.15);padding-left:1rem;margin-left:auto;}

/* Lang toggle */
.lang-toggle{display:flex;gap:0;border:1px solid rgba(255,255,255,0.2);border-radius:6px;overflow:hidden;flex-shrink:0;}
.lang-btn{background:transparent;color:rgba(255,255,255,0.55);border:none;padding:4px 10px;font-size:0.75rem;font-weight:700;cursor:pointer;transition:background .15s,color .15s;font-family:'Inter',sans-serif;}
.lang-btn.active{background:var(--green);color:#fff;}

/* Hero */
.audit-hero{background:linear-gradient(135deg,#0a1e15 0%,#0d2b1d 100%);padding:2.5rem 1.5rem 2rem;text-align:center;}
.audit-hero h1{font-family:'Fraunces',serif;font-size:1.8rem;color:#fff;margin-bottom:0.5rem;line-height:1.2;}
.audit-hero .tagline{color:var(--green);font-style:italic;font-size:0.85rem;margin-top:0.5rem;}

/* Form */
.audit-wrap{max-width:680px;margin:0 auto;padding:1.5rem;}
.audit-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;margin-bottom:1.25rem;box-shadow:0 2px 8px rgba(0,0,0,0.04);}
.section-heading{font-family:'Fraunces',serif;font-size:1.05rem;color:var(--green);font-weight:700;margin-bottom:1.25rem;padding-bottom:0.5rem;border-bottom:2px solid var(--border);}
.section-num{display:inline-block;background:var(--green);color:#fff;width:24px;height:24px;border-radius:50%;font-size:0.75rem;text-align:center;line-height:24px;margin-right:0.5rem;font-family:'Inter',sans-serif;font-weight:700;}

.q-block{margin-bottom:1.25rem;}
.q-block:last-child{margin-bottom:0;}
.q-label{font-size:0.875rem;font-weight:600;color:var(--text);margin-bottom:0.5rem;display:block;line-height:1.4;}
.q-num{color:var(--muted);font-weight:400;margin-right:0.3rem;}

input[type=text],input[type=email],input[type=tel],textarea,select{
  width:100%;padding:0.65rem 0.85rem;border:1.5px solid var(--border);border-radius:8px;
  font-family:'Inter',sans-serif;font-size:0.9rem;color:var(--text);background:#fff;
  transition:border-color .15s;
}
input:focus,textarea:focus,select:focus{outline:none;border-color:var(--green);}
textarea{resize:vertical;min-height:80px;}

.opt-list{display:flex;flex-direction:column;gap:0.5rem;}
.opt-item{display:flex;align-items:flex-start;gap:0.6rem;cursor:pointer;padding:0.5rem 0.75rem;border:1.5px solid var(--border);border-radius:8px;transition:border-color .15s,background .15s;}
.opt-item:hover{border-color:var(--green);background:#f0faf6;}
.opt-item input{width:16px;height:16px;margin-top:1px;accent-color:var(--green);flex-shrink:0;}
.opt-item span{font-size:0.875rem;color:var(--text);line-height:1.4;}
.opt-item.checked{border-color:var(--green);background:#f0faf6;}

/* Contact block */
.contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
@media(max-width:500px){.contact-grid{grid-template-columns:1fr;}}
.form-group label{display:block;font-size:0.8rem;font-weight:600;color:var(--muted);margin-bottom:0.3rem;}
.required-star{color:#e74c3c;}

/* Submit */
.submit-btn{width:100%;padding:0.9rem;background:var(--green);color:#fff;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:background .15s;letter-spacing:0.01em;}
.submit-btn:hover{background:var(--green-dark);}

/* Errors */
.error-box{background:#fff0f0;border:1px solid #f5c6c6;border-radius:8px;padding:0.85rem 1rem;margin-bottom:1rem;font-size:0.875rem;color:#c0392b;}

/* Thank you */
.thankyou{text-align:center;padding:3rem 1.5rem;}
.thankyou .icon{font-size:3.5rem;margin-bottom:1rem;}
.thankyou h2{font-family:'Fraunces',serif;font-size:1.6rem;color:var(--green);margin-bottom:0.75rem;}
.thankyou p{color:var(--muted);font-size:0.95rem;max-width:420px;margin:0 auto 1rem;}
.thankyou .wa-btn{display:inline-flex;align-items:center;gap:0.5rem;background:#25D366;color:#fff;padding:0.8rem 1.5rem;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.9rem;margin-top:0.5rem;}

/* Blog tips section */
.tips-section{max-width:680px;margin:0 auto;padding:0 1.5rem 2rem;}
.tips-heading{font-family:'Fraunces',serif;font-size:1.2rem;color:var(--text);font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;}
.tips-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
@media(max-width:500px){.tips-grid{grid-template-columns:1fr;}}
.tip-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1rem 1.1rem;text-decoration:none;display:block;transition:border-color .15s,box-shadow .15s;}
.tip-card:hover{border-color:var(--green);box-shadow:0 4px 12px rgba(0,168,120,0.1);}
.tip-cat{font-size:0.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--green);font-weight:700;margin-bottom:0.35rem;}
.tip-title{font-size:0.875rem;font-weight:600;color:var(--text);line-height:1.4;}
.tip-date{font-size:0.72rem;color:var(--muted);margin-top:0.4rem;}

/* Footer */
.audit-footer{text-align:center;padding:1.5rem;font-size:0.75rem;color:var(--muted);}
.audit-footer a{color:var(--green);text-decoration:none;}

/* Lang hide/show */
.fr{display:none;}.en{display:block;}
body.lang-fr .fr{display:block;}body.lang-fr .en{display:none;}
/* ── Marketing pitch section ── */
.pitch-section{background:linear-gradient(160deg,#001a10 0%,#002e1a 60%,#003d22 100%);padding:2.5rem 1.5rem 3rem;margin-top:0;}
.pitch-inner{max-width:720px;margin:0 auto;}
.pitch-heading{font-family:'Fraunces',serif;font-size:1.5rem;font-weight:700;color:#fff;text-align:center;margin-bottom:0.5rem;}
.pitch-sub{text-align:center;color:#8db8a8;font-size:0.9rem;margin-bottom:2rem;line-height:1.6;}
.pitch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:2rem;}
.pitch-card{background:rgba(255,255,255,0.05);border:1px solid rgba(0,168,120,0.25);border-radius:12px;padding:1.25rem 1rem;position:relative;}
.pitch-card-featured{background:rgba(0,168,120,0.1);border-color:var(--green);}
.pitch-badge{position:absolute;top:-10px;right:12px;background:var(--green);color:#fff;font-size:0.65rem;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:20px;letter-spacing:.05em;}
.pitch-icon{font-size:1.6rem;margin-bottom:0.6rem;}
.pitch-card-title{font-weight:700;color:#e8f4ee;font-size:0.95rem;margin-bottom:0.75rem;}
.pitch-list{list-style:none;padding:0;margin:0;}
.pitch-list li{color:#8db8a8;font-size:0.82rem;line-height:1.5;padding:0.2rem 0 0.2rem 1.2rem;position:relative;}
.pitch-list li::before{content:'✓';position:absolute;left:0;color:var(--green);font-weight:700;}
.pitch-cta{text-align:center;}
.pitch-cta p{color:#8db8a8;font-size:0.88rem;margin-bottom:1rem;line-height:1.6;}
.pitch-wa-btn{display:inline-flex;align-items:center;gap:8px;background:#25D366;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-weight:700;font-size:0.88rem;}
.pitch-wa-btn:hover{background:#1ebe5d;}
</style>
</head>
<body id="audit-body">

<div class="audit-header">
  <a class="logo" href="<?= SITE_URL ?>"><span>237</span>Biz.net</a>
  <div class="partner-tag">Shared by <?= htmlspecialchars($partnerName) ?></div>
  <div class="lang-toggle" style="margin-left:1rem;">
    <button class="lang-btn active" id="btn-en" onclick="setLang('en')">EN</button>
    <button class="lang-btn" id="btn-fr" onclick="setLang('fr')">FR</button>
  </div>
</div>

<div class="audit-hero">
  <h1 class="en">The Business Visibility Audit</h1>
  <h1 class="fr">L'Audit de Visibilité de Votre Entreprise</h1>
  <p class="tagline en">Your business cannot attract customers who cannot find you.</p>
  <p class="tagline fr">Votre entreprise ne peut pas attirer des clients qui ne peuvent pas vous trouver.</p>
</div>

<div class="audit-wrap">

<?php if ($submitted): ?>
<div class="audit-card thankyou">
  <div class="icon">🎉</div>
  <h2 class="en">Thank you, <?= htmlspecialchars($ownerName) ?>!</h2>
  <h2 class="fr">Merci, <?= htmlspecialchars($ownerName) ?> !</h2>
  <p class="en">Your Business Visibility Audit has been received. <strong><?= htmlspecialchars($partnerName) ?></strong> will be in touch with you soon to discuss how to make your business easier to find online.</p>
  <p class="fr">Votre Audit de Visibilité a bien été reçu. <strong><?= htmlspecialchars($partnerName) ?></strong> vous contactera bientôt pour vous aider à améliorer la visibilité de votre entreprise en ligne.</p>
  <?php if ($email): ?>
  <p class="en" style="font-size:0.85rem;">A confirmation has been sent to <strong><?= htmlspecialchars($email) ?></strong>.</p>
  <p class="fr" style="font-size:0.85rem;">Une confirmation a été envoyée à <strong><?= htmlspecialchars($email) ?></strong>.</p>
  <?php endif; ?>
  <a class="wa-btn" href="https://wa.me/<?= preg_replace('/\D/', '', $phone) ?>?text=<?= urlencode("Hi " . $ownerName . "! I've received your Business Visibility Audit for " . $bizName . ". I'll be in touch shortly.") ?>" target="_blank">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
    <span class="en">Chat on WhatsApp</span><span class="fr">Discuter sur WhatsApp</span>
  </a>
  <div style="margin-top:1.5rem;">
    <a href="<?= SITE_URL ?>" style="color:var(--green);font-size:0.875rem;text-decoration:none;">← Visit 237Biz.net</a>
  </div>
</div>

<?php else: ?>

<?php if ($errors): ?>
<div class="error-box">
  <?php foreach ($errors as $e): ?>
  <div>⚠ <?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" id="audit-form">
  <?= gpCsrfField() ?>

  <!-- Contact info -->
  <div class="audit-card">
    <div class="section-heading en">Your Details</div>
    <div class="section-heading fr">Vos Coordonnées</div>
    <div class="contact-grid">
      <div class="form-group">
        <label><span class="en">Business name</span><span class="fr">Nom de l'entreprise</span> <span class="required-star">*</span></label>
        <input type="text" name="business_name" value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" placeholder="e.g. Nyaga Store Kids" required>
      </div>
      <div class="form-group">
        <label><span class="en">Your name</span><span class="fr">Votre nom</span> <span class="required-star">*</span></label>
        <input type="text" name="owner_name" value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>" placeholder="e.g. Penga Geraldine" required>
      </div>
      <div class="form-group">
        <label><span class="en">Phone / WhatsApp</span><span class="fr">Téléphone / WhatsApp</span> <span class="required-star">*</span></label>
        <input type="tel" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="e.g. 693848431" required>
      </div>
      <div class="form-group">
        <label><span class="en">Email</span><span class="fr">E-mail</span> <span style="color:var(--muted);font-weight:400;" class="en">(optional)</span><span style="color:var(--muted);font-weight:400;" class="fr">(facultatif)</span></label>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="yourname@email.com">
      </div>
    </div>
  </div>

  <?php
  // French section titles
  $frSectionTitles = [
    'Know Your Business'              => 'Votre Entreprise',
    'How Visible Is Your Business?'   => 'Quelle est la Visibilité de Votre Entreprise ?',
    'Turn Visibility Into Customers'  => 'Transformer la Visibilité en Clients',
    'Your Next Step'                  => 'Votre Prochaine Étape',
  ];
  // French question texts (keyed by sort_order)
  // sort_order 1-3 = original "Know Your Business" questions
  // sort_order 4-5 = new questions (years in business, number of staff)
  // sort_order 6+ = original questions 4-15 (bumped +2 by migration)
  $frQuestions = [
    1  => 'Quel produit ou service proposez-vous ?',
    2  => 'Qui est votre client idéal ?',
    3  => 'Qu\'est-ce qui distingue votre entreprise ?',
    4  => 'Depuis combien de temps êtes-vous en activité ?',
    5  => 'Combien d\'employés avez-vous (vous y compris) ?',
    6  => 'Où les clients peuvent-ils actuellement trouver votre entreprise ? (cochez tout ce qui s\'applique)',
    7  => 'Si un nouveau client cherche votre produit ou service en ligne dans votre ville, peut-il facilement vous trouver ?',
    8  => 'Avez-vous un profil d\'entreprise en ligne complet avec votre adresse, vos coordonnées, une description et des photos ?',
    9  => 'À quelle fréquence faites-vous la promotion active de votre entreprise en ligne ?',
    10 => 'Comment la plupart de vos clients découvrent-ils votre entreprise ?',
    11 => 'Quel est votre plus grand défi ? (cochez tout ce qui s\'applique)',
    12 => 'Avez-vous un moyen clair pour les clients intéressés de vous contacter, de demander un service ou de faire une réservation ?',
    13 => 'Qu\'avez-vous essayé jusqu\'à présent pour attirer plus de clients ? Qu\'est-ce qui a fonctionné, et qu\'est-ce qui n\'a pas fonctionné ?',
    14 => 'Quelle est la seule chose que vous aimeriez améliorer concernant la visibilité de votre entreprise dans les 30 prochains jours ?',
    15 => 'Quel type d\'aide vous serait le plus utile ? (cochez tout ce qui s\'applique)',
    16 => 'Souhaitez-vous en savoir plus sur les services proposés par 237Biz.net ?',
    17 => 'Une action concrète que je prendrai cette semaine est :',
  ];
  // French options (keyed by sort_order)
  $frOptions = [
    4  => ['Moins d\'1 an', '1–3 ans', '3–5 ans', '5–10 ans', 'Plus de 10 ans'],
    5  => ['Juste moi', '2–5', '6–10', '11–25', 'Plus de 25'],
    6  => ['WhatsApp','Facebook','TikTok','Instagram','Recherche Google / Google Maps','Annuaire en ligne','Emplacement physique / bouche-à-oreille uniquement'],
    7  => ['Oui','Non','Je ne suis pas sûr(e)'],
    8  => ['Oui','Partiellement','Non'],
    9  => ['Tous les jours','Plusieurs fois par semaine','Occasionnellement','Presque jamais'],
    11 => ['Pas assez de gens connaissent mon entreprise','Les gens voient mon entreprise mais me contactent rarement','Je peine à créer du contenu régulièrement','J\'ai besoin d\'un meilleur marketing et d\'une meilleure promotion','J\'ai du mal à transformer les demandes en ventes','Je ne sais pas quelle stratégie marketing utiliser'],
    12 => ['Oui','Partiellement','Non'],
    15 => ['Référencer mon entreprise en ligne','Améliorer mon profil d\'entreprise en ligne','Créer des vidéos et du contenu promotionnel','Faire connaître et promouvoir mon entreprise','Développer une stratégie marketing','Être aidé sur plusieurs de ces points'],
    16 => ['237Biz.net','Oui, les deux','Pas pour l\'instant'],
  ];

  $sectionNum = 1;
  foreach ($sections as $secId => $sec):
    $frTitle = $frSectionTitles[$sec['title']] ?? $sec['title'];
  ?>
  <div class="audit-card">
    <div class="section-heading">
      <span class="section-num"><?= $sectionNum ?></span>
      <span class="en"><?= htmlspecialchars($sec['title']) ?></span>
      <span class="fr"><?= htmlspecialchars($frTitle) ?></span>
    </div>
    <?php foreach ($sec['questions'] as $q):
      $qKey   = 'q' . $q['id'];
      $opts   = $q['options_json'] ? json_decode($q['options_json'], true) : [];
      $frOpts = $frOptions[$q['sort_order']] ?? $opts;
      $prevVal = $_POST[$qKey] ?? null;
      $frQText = $frQuestions[$q['sort_order']] ?? $q['question_text'];
    ?>
    <div class="q-block">
      <label class="q-label" for="<?= $qKey ?>">
        <span class="q-num"><?= $q['sort_order'] ?>.</span>
        <span class="en"><?= htmlspecialchars($q['question_text']) ?></span>
        <span class="fr"><?= htmlspecialchars($frQText) ?></span>
        <?php if ($q['is_required']): ?><span class="required-star"> *</span><?php endif; ?>
      </label>

      <?php if ($q['question_type'] === 'text'): ?>
        <input type="text" id="<?= $qKey ?>" name="<?= $qKey ?>"
               value="<?= htmlspecialchars($prevVal ?? '') ?>">

      <?php elseif ($q['question_type'] === 'textarea'): ?>
        <textarea id="<?= $qKey ?>" name="<?= $qKey ?>" rows="3"><?= htmlspecialchars($prevVal ?? '') ?></textarea>

      <?php elseif ($q['question_type'] === 'radio'): ?>
        <div class="opt-list" role="radiogroup">
          <?php foreach ($opts as $i => $opt): ?>
          <label class="opt-item<?= ($prevVal === $opt) ? ' checked' : '' ?>">
            <input type="radio" name="<?= $qKey ?>" value="<?= htmlspecialchars($opt) ?>"
                   <?= ($prevVal === $opt) ? 'checked' : '' ?>>
            <span class="en"><?= htmlspecialchars($opt) ?></span>
            <span class="fr"><?= htmlspecialchars($frOpts[$i] ?? $opt) ?></span>
          </label>
          <?php endforeach; ?>
        </div>

      <?php elseif ($q['question_type'] === 'checkbox'): ?>
        <div class="opt-list" role="group">
          <?php
          $prevArr = is_array($prevVal) ? $prevVal : [];
          foreach ($opts as $i => $opt):
            $checked = in_array($opt, $prevArr);
          ?>
          <label class="opt-item<?= $checked ? ' checked' : '' ?>">
            <input type="checkbox" name="<?= $qKey ?>[]" value="<?= htmlspecialchars($opt) ?>"
                   <?= $checked ? 'checked' : '' ?>>
            <span class="en"><?= htmlspecialchars($opt) ?></span>
            <span class="fr"><?= htmlspecialchars($frOpts[$i] ?? $opt) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php $sectionNum++; endforeach; ?>

  <div class="audit-card" style="text-align:center;border-color:var(--green);">
    <p class="en" style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;">By submitting, you agree that your information may be shared with <?= htmlspecialchars($partnerName) ?> to help you improve your business visibility.</p>
    <p class="fr" style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;">En soumettant ce formulaire, vous acceptez que vos informations soient partagées avec <?= htmlspecialchars($partnerName) ?> afin d'améliorer la visibilité de votre entreprise.</p>
    <button type="submit" class="submit-btn">
      <span class="en">Submit My Audit →</span>
      <span class="fr">Soumettre Mon Audit →</span>
    </button>
  </div>
</form>

<?php endif; ?>

</div><!-- .audit-wrap -->

<!-- ── Why Your Business Needs a Digital Presence ── -->
<div class="pitch-section">
  <div class="pitch-inner">

    <div class="pitch-heading">
      <span class="en">Your Business Deserves to Be Found</span>
      <span class="fr">Votre Entreprise Mérite d'Être Trouvée</span>
    </div>
    <p class="pitch-sub en">Every day, potential customers search online for exactly what you offer — and go to whoever shows up first. Here's how we can help.</p>
    <p class="pitch-sub fr">Chaque jour, des clients potentiels cherchent en ligne exactement ce que vous proposez — et vont chez celui qui apparaît en premier. Voici comment nous pouvons vous aider.</p>

    <div class="pitch-grid">

      <!-- Website -->
      <div class="pitch-card">
        <div class="pitch-icon">🌐</div>
        <div class="pitch-card-title en">Professional Website</div>
        <div class="pitch-card-title fr">Site Web Professionnel</div>
        <ul class="pitch-list en">
          <li>Open for business 24/7 — even when you sleep</li>
          <li>Looks great on every phone and screen</li>
          <li>Customers find you on Google Maps &amp; Search</li>
          <li>Build trust before the first call</li>
        </ul>
        <ul class="pitch-list fr">
          <li>Ouvert 24h/24, 7j/7 — même pendant votre sommeil</li>
          <li>Beau sur chaque téléphone et écran</li>
          <li>Les clients vous trouvent sur Google Maps et Search</li>
          <li>Inspirez confiance avant le premier appel</li>
        </ul>
      </div>

      <!-- Business Email -->
      <div class="pitch-card">
        <div class="pitch-icon">✉️</div>
        <div class="pitch-card-title en">Business Email</div>
        <div class="pitch-card-title fr">Email Professionnel</div>
        <ul class="pitch-list en">
          <li>yourname@yourbusiness.com — not Gmail or Yahoo</li>
          <li>Looks professional to every client &amp; supplier</li>
          <li>Separate business and personal easily</li>
          <li>Builds brand trust with every message you send</li>
        </ul>
        <ul class="pitch-list fr">
          <li>votrenam@votreentreprise.com — pas Gmail ni Yahoo</li>
          <li>Sérieux aux yeux de chaque client et fournisseur</li>
          <li>Séparez facilement le pro du personnel</li>
          <li>Renforcez la confiance à chaque message envoyé</li>
        </ul>
      </div>

      <!-- Chatbot -->
      <div class="pitch-card pitch-card-featured">
        <div class="pitch-badge en">New</div>
        <div class="pitch-badge fr">Nouveau</div>
        <div class="pitch-icon">🤖</div>
        <div class="pitch-card-title en">AI Chatbot on Your Website</div>
        <div class="pitch-card-title fr">Chatbot IA sur Votre Site</div>
        <ul class="pitch-list en">
          <li>Answers customer questions instantly — day and night</li>
          <li>Works perfectly on mobile (WhatsApp-style chat)</li>
          <li>Collects leads while you focus on your work</li>
          <li>Speaks English and French — just like your customers</li>
        </ul>
        <ul class="pitch-list fr">
          <li>Répond aux questions des clients instantanément — jour et nuit</li>
          <li>Fonctionne parfaitement sur mobile (style WhatsApp)</li>
          <li>Collecte des prospects pendant que vous travaillez</li>
          <li>Parle anglais et français — comme vos clients</li>
        </ul>
      </div>

    </div><!-- .pitch-grid -->

    <div class="pitch-cta">
      <p class="en">Interested? <?= htmlspecialchars($partnerName) ?> will walk you through the right options for your business after reviewing your audit.</p>
      <p class="fr">Intéressé(e) ? <?= htmlspecialchars($partnerName) ?> vous présentera les meilleures options pour votre entreprise après avoir examiné votre audit.</p>
      <a href="https://wa.me/?text=<?= urlencode('Hi! I just completed the Business Visibility Audit and I\'d like to know more about getting a website, business email or chatbot.') ?>" target="_blank" class="pitch-wa-btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        <span class="en">Ask about these services</span>
        <span class="fr">Renseignez-vous sur ces services</span>
      </a>
    </div>

  </div>
</div>

<?php if (!empty($blogPosts)): ?>
<!-- ── Marketing Tips ── -->
<div class="tips-section">
  <div class="tips-heading">
    <span>💡</span>
    <span class="en">Marketing Tips</span>
    <span class="fr">Conseils Marketing</span>
  </div>
  <div class="tips-grid">
    <?php foreach ($blogPosts as $post):
      $title = trim($post['title_en'] ?? '');
      $titleFr = trim($post['title_fr'] ?? '');
      if (!$titleFr) $titleFr = $title;
      $url = SITE_URL . '/blog/' . urlencode($post['slug']);
      $date = $post['published_at'] ? date('j M Y', strtotime($post['published_at'])) : '';
    ?>
    <a class="tip-card" href="<?= htmlspecialchars($url) ?>" target="_blank">
      <?php if ($post['category']): ?>
      <div class="tip-cat"><?= htmlspecialchars($post['category']) ?></div>
      <?php endif; ?>
      <div class="tip-title en"><?= htmlspecialchars($title) ?></div>
      <div class="tip-title fr"><?= htmlspecialchars($titleFr) ?></div>
      <?php if ($date): ?><div class="tip-date"><?= $date ?></div><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
  <div style="text-align:center;margin-top:1rem;">
    <a href="<?= SITE_URL ?>/blog" target="_blank" style="color:var(--green);font-size:0.85rem;font-weight:600;text-decoration:none;">
      <span class="en">Read all articles →</span>
      <span class="fr">Lire tous les articles →</span>
    </a>
  </div>
</div>
<?php endif; ?>

<div class="audit-footer">
  <p class="en">237Biz.net helps customers discover businesses across Africa and the diaspora.</p>
  <p class="fr">237Biz.net aide les clients à découvrir des entreprises en Afrique et dans la diaspora.</p>
  <p style="margin-top:0.5rem;"><a href="<?= SITE_URL ?>">Visit 237Biz.net</a></p>
</div>

<script>
function setLang(lang) {
  document.getElementById('audit-body').className = lang === 'fr' ? 'lang-fr' : '';
  document.getElementById('btn-en').classList.toggle('active', lang === 'en');
  document.getElementById('btn-fr').classList.toggle('active', lang === 'fr');
  try { localStorage.setItem('audit_lang', lang); } catch(e){}
}
// Restore saved preference
(function(){ try { var l = localStorage.getItem('audit_lang'); if (l === 'fr') setLang('fr'); } catch(e){} })();

// Visual feedback on option select
document.querySelectorAll('.opt-item input').forEach(function(inp) {
  inp.addEventListener('change', function() {
    var list = this.closest('.opt-list');
    if (this.type === 'radio') {
      list.querySelectorAll('.opt-item').forEach(function(el) { el.classList.remove('checked'); });
    }
    this.closest('.opt-item').classList.toggle('checked', this.checked);
  });
});
</script>
</body>
</html>
