<?php
/**
 * partner-request.php — Business Partner Request Form (Task 46)
 *
 * Allows a logged-in listing owner (or admin) to request a specific partner,
 * or submit an open request for admin to match.
 * Writes to partner_requests table.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partner-helpers.php';

$pdo = db();

// Must be logged in
if (!isLoggedIn()) {
    setFlash('info', 'Please log in to request a Growth Partner.');
    redirect(SITE_URL . '/login?next=' . urlencode('/partner-request' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')));
}

$userId = (int)$_SESSION['user_id'];

// Pre-fill specific partner from URL
$partnerCode = trim($_GET['partner'] ?? '');
$prePartner  = null;
if ($partnerCode) {
    $prePartner = $pdo->prepare("
        SELECT pp.id, pp.display_name, pp.referral_code, pp.capacity_status, pp.tagline,
               t.name AS tier_name
        FROM partner_profiles pp
        LEFT JOIN partner_tiers t ON t.id = pp.tier_id
        WHERE pp.referral_code = ? AND pp.status IN ('approved','active') AND pp.public_profile = 1
    ");
    $prePartner->execute([$partnerCode]);
    $prePartner = $prePartner->fetch();
}

// User's listings (for assignment context)
$listings = $pdo->prepare("SELECT id, business_name FROM listings WHERE user_id=? AND status='approved' ORDER BY business_name");
$listings->execute([$userId]);
$listings = $listings->fetchAll();

// Cameroon regions for "coverage needed" field
$regions = [
    'Adamawa','Centre','East','Far North','Littoral',
    'North','North West','South','South West','West',
];

// ── POST: submit request ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $requestType = $_POST['request_type'] ?? 'specific'; // specific | open
    $partnerId   = (int)($_POST['partner_id'] ?? 0);
    $listingId   = (int)($_POST['listing_id'] ?? 0);
    $message     = trim($_POST['message'] ?? '');
    $needs       = trim($_POST['needs'] ?? '');
    $regionPref  = trim($_POST['region_preference'] ?? '');
    $urgency     = in_array($_POST['urgency'] ?? '', ['low','medium','high']) ? $_POST['urgency'] : 'medium';

    $errors = [];

    if (!$message) $errors[] = 'Please describe what you need from a Growth Partner.';
    if ($requestType === 'specific' && !$partnerId) $errors[] = 'Please select a partner.';
    if ($listingId && !in_array($listingId, array_column($listings, 'id'))) {
        $listingId = 0; // safety
    }

    // Check capacity if specific partner
    if (!$errors && $requestType === 'specific' && $partnerId) {
        $cap = $pdo->prepare("SELECT capacity_status FROM partner_profiles WHERE id=? AND status IN ('approved','active')");
        $cap->execute([$partnerId]);
        $cap = $cap->fetch();
        if (!$cap) $errors[] = 'The selected partner could not be found.';
        elseif ($cap['capacity_status'] === 'full') $errors[] = 'This partner is currently full. Please choose another or submit an open request.';
        elseif ($cap['capacity_status'] === 'paused') $errors[] = 'This partner is temporarily unavailable.';
    }

    // Duplicate check (one pending request per user/partner combo)
    if (!$errors && $requestType === 'specific' && $partnerId) {
        $dup = $pdo->prepare("SELECT id FROM partner_requests WHERE requester_id=? AND partner_id=? AND status='pending'");
        $dup->execute([$userId, $partnerId]);
        if ($dup->fetch()) $errors[] = 'You already have a pending request for this partner.';
    }

    if (!$errors) {
        $pdo->prepare("
            INSERT INTO partner_requests
                (requester_id, listing_id, partner_id, request_type, message, needs, region_preference, urgency)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $userId,
            $listingId ?: null,
            $requestType === 'specific' ? $partnerId : null,
            $requestType,
            $message,
            $needs,
            $regionPref,
            $urgency,
        ]);

        $newId = $pdo->lastInsertId();

        // Notify partner (if specific)
        if ($requestType === 'specific' && $partnerId) {
            $pOwner = $pdo->prepare("SELECT user_id FROM partner_profiles WHERE id=?");
            $pOwner->execute([$partnerId]);
            $pOwnerUid = (int)($pOwner->fetchColumn());
            if ($pOwnerUid) {
                pushNotification($pOwnerUid, 'partner_request', 'New Business Request',
                    'A business has requested you as their Growth Partner. Check your leads for details.',
                    SITE_URL . '/partner/leads', $partnerId);
            }
        }

        // Notify admins for open requests
        if ($requestType === 'open') {
            $adminIds = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($adminIds as $aid) {
                pushNotification((int)$aid, 'partner_request_open', 'New Open Partner Request',
                    'A business submitted an open partner request that needs matching.',
                    SITE_URL . '/admin/partner-matching');
            }
        }

        setFlash('success', $requestType === 'specific'
            ? 'Your request has been sent to the partner. They will be in touch shortly.'
            : 'Your open request has been submitted. Our team will match you with the best partner for your needs.');
        redirect(SITE_URL . '/partner-request?submitted=1');
    }
}

$flash    = getFlash();
$submitted = isset($_GET['submitted']);
$pageTitle = 'Request a Growth Partner';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.req-wrap{max-width:680px;margin:0 auto;padding:2rem 1rem}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.5rem;margin-bottom:1.25rem}
.card-title{font-size:1rem;font-weight:700;color:#111827;margin:0 0 1rem}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.3rem}
.form-group input,.form-group select,.form-group textarea{width:100%;border:1px solid #d1d5db;border-radius:.5rem;padding:.5rem .75rem;font-size:.875rem;box-sizing:border-box}
.form-group textarea{min-height:100px;resize:vertical}
.form-group .hint{font-size:.75rem;color:#9ca3af;margin-top:.2rem}
.type-tabs{display:flex;gap:0;border:1px solid #e5e7eb;border-radius:.5rem;overflow:hidden;margin-bottom:1.25rem}
.type-tab{flex:1;padding:.6rem;text-align:center;font-size:.85rem;font-weight:600;cursor:pointer;border:none;background:#fff;color:#6b7280;transition:all .15s}
.type-tab.active{background:#2563eb;color:#fff}
.partner-preview{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:.5rem;padding:.75rem 1rem;display:flex;align-items:center;gap:.75rem;margin-bottom:1rem}
.partner-avatar{width:44px;height:44px;border-radius:50%;background:#059669;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem}
.urgency-pills{display:flex;gap:.5rem}
.urgency-pill{flex:1;text-align:center;padding:.45rem;border:2px solid #e5e7eb;border-radius:.5rem;font-size:.8rem;font-weight:600;cursor:pointer}
.urgency-pill input[type=radio]{display:none}
.urgency-pill.low.selected{border-color:#059669;background:#d1fae5;color:#065f46}
.urgency-pill.medium.selected{border-color:#d97706;background:#fef3c7;color:#92400e}
.urgency-pill.high.selected{border-color:#dc2626;background:#fee2e2;color:#991b1b}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1.2rem;border-radius:.5rem;font-size:.875rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff;width:100%;justify-content:center;font-size:.95rem}
.btn-detail{background:#e5e7eb;color:#374151}
.btn:hover{opacity:.9}
.error-list{background:#fee2e2;border:1px solid #fca5a5;border-radius:.5rem;padding:.75rem 1rem;margin-bottom:1rem}
.error-list ul{margin:0;padding-left:1.25rem}
.error-list li{font-size:.875rem;color:#991b1b}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.info{background:#dbeafe;color:#1d4ed8}
.success-state{text-align:center;padding:2rem 1rem}
.success-icon{font-size:3rem;margin-bottom:.75rem}
</style>

<div class="req-wrap">
    <a href="<?= SITE_URL ?>/partners" style="font-size:.82rem;color:#6b7280;text-decoration:none;display:inline-flex;align-items:center;gap:.25rem;margin-bottom:1rem">← Back to Partners</a>

    <h1 style="font-size:1.5rem;font-weight:800;color:#111827;margin:0 0 .25rem">Request a Growth Partner</h1>
    <p style="margin:0 0 1.5rem;color:#6b7280;font-size:.9rem">Connect with an expert who will help grow your business in Cameroon</p>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <?php if ($submitted): ?>
    <div class="card">
        <div class="success-state">
            <div class="success-icon">✅</div>
            <h2 style="margin:0 0 .5rem;font-size:1.2rem;color:#111827">Request Submitted!</h2>
            <p style="color:#6b7280;margin:0 0 1.25rem;font-size:.9rem">
                <?php if ($partnerCode && $prePartner): ?>
                Your request has been sent to <strong><?= e($prePartner['display_name']) ?></strong>. They will review your request and get in touch soon.
                <?php else: ?>
                Our team will review your needs and match you with the most suitable Growth Partner for your region and business type.
                <?php endif ?>
            </p>
            <div style="display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap">
                <a href="<?= SITE_URL ?>/dashboard" class="btn btn-primary" style="width:auto">Go to Dashboard</a>
                <a href="<?= SITE_URL ?>/partners" class="btn btn-detail">Browse More Partners</a>
            </div>
        </div>
    </div>
    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="error-list">
        <ul><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach ?></ul>
    </div>
    <?php endif ?>

    <?php if ($prePartner && $prePartner['capacity_status'] === 'full'): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:.5rem;padding:.75rem 1rem;margin-bottom:1rem;font-size:.875rem;color:#991b1b">
        ⚠️ <strong><?= e($prePartner['display_name']) ?></strong> is currently full. You can still submit an open request and our team will match you with a suitable partner.
    </div>
    <?php endif ?>

    <form method="post" id="req-form">
        <?= csrfField() ?>

        <!-- Request type -->
        <div class="card">
            <p class="card-title">Request Type</p>
            <div class="type-tabs">
                <button type="button" class="type-tab<?= (!$prePartner || ($prePartner['capacity_status'] === 'full')) ? '' : ' active' ?>"
                        onclick="setType('specific')" id="tab-specific">Request a Specific Partner</button>
                <button type="button" class="type-tab<?= (!$prePartner || ($prePartner['capacity_status'] === 'full')) ? ' active' : '' ?>"
                        onclick="setType('open')" id="tab-open">Open Request (Admin matches)</button>
            </div>
            <input type="hidden" name="request_type" id="request_type"
                   value="<?= ($prePartner && $prePartner['capacity_status'] !== 'full') ? 'specific' : 'open' ?>">

            <!-- Specific partner section -->
            <div id="section-specific" style="display:<?= ($prePartner && $prePartner['capacity_status'] !== 'full') ? 'block' : 'none' ?>">
                <?php if ($prePartner && $prePartner['capacity_status'] !== 'full'): ?>
                <div class="partner-preview">
                    <div class="partner-avatar"><?= strtoupper(substr($prePartner['display_name'], 0, 1)) ?></div>
                    <div>
                        <div style="font-weight:700;color:#111827"><?= e($prePartner['display_name']) ?></div>
                        <div style="font-size:.8rem;color:#6b7280"><?= e($prePartner['tier_name']) ?> Partner<?= $prePartner['tagline'] ? ' · ' . e(mb_substr($prePartner['tagline'], 0, 60)) : '' ?></div>
                    </div>
                    <a href="<?= SITE_URL ?>/partner/profile?code=<?= urlencode($prePartner['referral_code']) ?>"
                       style="margin-left:auto;font-size:.78rem;color:#2563eb;text-decoration:none">View Profile →</a>
                </div>
                <input type="hidden" name="partner_id" value="<?= $prePartner['id'] ?>">
                <?php else: ?>
                <div class="form-group">
                    <label>Select a Partner</label>
                    <select name="partner_id">
                        <option value="">— Choose from directory —</option>
                        <?php
                        $allPartners = $pdo->query("
                            SELECT pp.id, pp.display_name, pp.capacity_status, t.name AS tier_name
                            FROM partner_profiles pp
                            LEFT JOIN partner_tiers t ON t.id=pp.tier_id
                            WHERE pp.status IN ('approved','active') AND pp.public_profile=1
                            ORDER BY pp.display_name
                        ")->fetchAll();
                        foreach ($allPartners as $ap):
                            $disabled = in_array($ap['capacity_status'], ['full','paused']);
                        ?>
                        <option value="<?= $ap['id'] ?>" <?= $disabled ? 'disabled' : '' ?>>
                            <?= e($ap['display_name']) ?> (<?= e($ap['tier_name']) ?>)<?= $disabled ? ' — ' . ucfirst($ap['capacity_status']) : '' ?>
                        </option>
                        <?php endforeach ?>
                    </select>
                    <div class="hint">Or <a href="<?= SITE_URL ?>/partners" style="color:#2563eb">browse the directory</a> to find a partner</div>
                </div>
                <?php endif ?>
            </div>

            <!-- Open request section -->
            <div id="section-open" style="display:<?= ($prePartner && $prePartner['capacity_status'] !== 'full') ? 'none' : 'block' ?>">
                <div class="form-group">
                    <label>Preferred Region</label>
                    <select name="region_preference">
                        <option value="">Any region</option>
                        <?php foreach ($regions as $r): ?>
                        <option value="<?= e($r) ?>" <?= ($_POST['region_preference'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Business context -->
        <div class="card">
            <p class="card-title">Your Business</p>
            <?php if ($listings): ?>
            <div class="form-group">
                <label>Which of your businesses is this for?</label>
                <select name="listing_id">
                    <option value="">— Not linked to a specific listing —</option>
                    <?php foreach ($listings as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= (int)($_POST['listing_id'] ?? 0) === $l['id'] ? 'selected' : '' ?>>
                        <?= e($l['business_name']) ?>
                    </option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php endif ?>

            <div class="form-group">
                <label>What do you need help with? <span style="color:#dc2626">*</span></label>
                <textarea name="message" placeholder="e.g. I need help growing my restaurant's online presence and attracting more customers in Douala…" required><?= e($_POST['message'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Specific services needed <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
                <input type="text" name="needs" placeholder="e.g. Social media, customer acquisition, product listings"
                       value="<?= e($_POST['needs'] ?? '') ?>">
                <div class="hint">Comma-separated list of services you're looking for</div>
            </div>

            <div class="form-group">
                <label>Urgency</label>
                <div class="urgency-pills">
                    <?php
                    $currentUrgency = $_POST['urgency'] ?? 'medium';
                    foreach (['low'=>'Low — no rush','medium'=>'Medium — within a month','high'=>'High — urgent'] as $u => $label):
                    ?>
                    <label class="urgency-pill <?= $u ?><?= $currentUrgency===$u ? ' selected' : '' ?>">
                        <input type="radio" name="urgency" value="<?= $u ?>"
                               <?= $currentUrgency===$u ? 'checked' : '' ?>
                               onchange="document.querySelectorAll('.urgency-pill').forEach(p=>p.classList.remove('selected'));this.closest('.urgency-pill').classList.add('selected')">
                        <?= $label ?>
                    </label>
                    <?php endforeach ?>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Submit Partner Request</button>
        <p style="font-size:.75rem;color:#9ca3af;text-align:center;margin-top:.75rem">
            By submitting you agree to our <a href="<?= SITE_URL ?>/terms" style="color:#6b7280">Terms of Service</a>
        </p>
    </form>
    <?php endif ?>
</div>

<script>
function setType(type) {
    document.getElementById('request_type').value = type;
    const isSpecific = type === 'specific';
    document.getElementById('section-specific').style.display = isSpecific ? 'block' : 'none';
    document.getElementById('section-open').style.display     = isSpecific ? 'none' : 'block';
    document.getElementById('tab-specific').classList.toggle('active', isSpecific);
    document.getElementById('tab-open').classList.toggle('active', !isSpecific);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
