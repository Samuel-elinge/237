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

/* Hero */
.audit-hero{background:linear-gradient(135deg,#0a1e15 0%,#0d2b1d 100%);padding:2.5rem 1.5rem 2rem;text-align:center;}
.audit-hero h1{font-family:'Fraunces',serif;font-size:1.8rem;color:#fff;margin-bottom:0.5rem;line-height:1.2;}
.audit-hero p{color:rgba(255,255,255,0.65);font-size:0.9rem;max-width:480px;margin:0 auto;}
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

/* Footer */
.audit-footer{text-align:center;padding:1.5rem;font-size:0.75rem;color:var(--muted);}
.audit-footer a{color:var(--green);text-decoration:none;}
</style>
</head>
<body>

<div class="audit-header">
  <a class="logo" href="<?= SITE_URL ?>"><span>237</span>Biz.net</a>
  <div class="partner-tag">Shared by <?= htmlspecialchars($partnerName) ?></div>
</div>

<div class="audit-hero">
  <h1>The Business Visibility Audit</h1>
  <p>237Biz.net × The Tahirih Effect</p>
  <p class="tagline">Your business cannot attract customers who cannot find you.</p>
</div>

<div class="audit-wrap">

<?php if ($submitted): ?>
<div class="audit-card thankyou">
  <div class="icon">🎉</div>
  <h2>Thank you, <?= htmlspecialchars($ownerName) ?>!</h2>
  <p>Your Business Visibility Audit has been received. <strong><?= htmlspecialchars($partnerName) ?></strong> will be in touch with you soon to discuss how to make your business easier to find online.</p>
  <?php if ($email): ?>
  <p style="font-size:0.85rem;">A confirmation has been sent to <strong><?= htmlspecialchars($email) ?></strong>.</p>
  <?php endif; ?>
  <a class="wa-btn" href="https://wa.me/<?= preg_replace('/\D/', '', $phone) ?>?text=<?= urlencode("Hi " . $ownerName . "! I've received your Business Visibility Audit for " . $bizName . ". I'll be in touch shortly.") ?>" target="_blank">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
    Chat on WhatsApp
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
    <div class="section-heading">Your Details</div>
    <div class="contact-grid">
      <div class="form-group">
        <label>Business name <span class="required-star">*</span></label>
        <input type="text" name="business_name" value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" placeholder="e.g. Nyaga Store Kids" required>
      </div>
      <div class="form-group">
        <label>Your name <span class="required-star">*</span></label>
        <input type="text" name="owner_name" value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>" placeholder="e.g. Penga Geraldine" required>
      </div>
      <div class="form-group">
        <label>Phone / WhatsApp <span class="required-star">*</span></label>
        <input type="tel" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="e.g. 693848431" required>
      </div>
      <div class="form-group">
        <label>Email <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="yourname@email.com">
      </div>
    </div>
  </div>

  <?php
  $sectionNum = 1;
  foreach ($sections as $secId => $sec):
  ?>
  <div class="audit-card">
    <div class="section-heading">
      <span class="section-num"><?= $sectionNum ?></span>
      <?= htmlspecialchars($sec['title']) ?>
    </div>
    <?php foreach ($sec['questions'] as $q):
      $qKey = 'q' . $q['id'];
      $opts = $q['options_json'] ? json_decode($q['options_json'], true) : [];
      $prevVal = $_POST[$qKey] ?? null;
    ?>
    <div class="q-block">
      <label class="q-label" for="<?= $qKey ?>">
        <span class="q-num"><?= $q['sort_order'] ?>.</span>
        <?= htmlspecialchars($q['question_text']) ?>
        <?php if ($q['is_required']): ?><span class="required-star"> *</span><?php endif; ?>
      </label>

      <?php if ($q['question_type'] === 'text'): ?>
        <input type="text" id="<?= $qKey ?>" name="<?= $qKey ?>"
               value="<?= htmlspecialchars($prevVal ?? '') ?>">

      <?php elseif ($q['question_type'] === 'textarea'): ?>
        <textarea id="<?= $qKey ?>" name="<?= $qKey ?>" rows="3"><?= htmlspecialchars($prevVal ?? '') ?></textarea>

      <?php elseif ($q['question_type'] === 'radio'): ?>
        <div class="opt-list" role="radiogroup">
          <?php foreach ($opts as $opt): ?>
          <label class="opt-item<?= ($prevVal === $opt) ? ' checked' : '' ?>">
            <input type="radio" name="<?= $qKey ?>" value="<?= htmlspecialchars($opt) ?>"
                   <?= ($prevVal === $opt) ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($opt) ?></span>
          </label>
          <?php endforeach; ?>
        </div>

      <?php elseif ($q['question_type'] === 'checkbox'): ?>
        <div class="opt-list" role="group">
          <?php
          $prevArr = is_array($prevVal) ? $prevVal : [];
          foreach ($opts as $opt):
            $checked = in_array($opt, $prevArr);
          ?>
          <label class="opt-item<?= $checked ? ' checked' : '' ?>">
            <input type="checkbox" name="<?= $qKey ?>[]" value="<?= htmlspecialchars($opt) ?>"
                   <?= $checked ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($opt) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php $sectionNum++; endforeach; ?>

  <div class="audit-card" style="text-align:center;border-color:var(--green);">
    <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;">By submitting, you agree that your information may be shared with <?= htmlspecialchars($partnerName) ?> to help you improve your business visibility.</p>
    <button type="submit" class="submit-btn">Submit My Audit →</button>
  </div>
</form>

<?php endif; ?>

</div><!-- .audit-wrap -->

<div class="audit-footer">
  <p>237Biz.net helps customers discover businesses. The Tahirih Effect supports business visibility through marketing and content services.</p>
  <p style="margin-top:0.5rem;"><a href="<?= SITE_URL ?>">Visit 237Biz.net</a></p>
</div>

<script>
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
