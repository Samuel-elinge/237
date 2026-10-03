<?php
/**
 * partner/agreements.php — Partner Agreement Management (Task 54)
 *
 * Partners view and sign their Growth Partner agreement.
 * Admins can issue new agreements, revoke existing ones, and view signing status.
 * Tracks version, signing IP, acceptance timestamp.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$pdo = db();
$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = (int)$_SESSION['user_id'];
$isAdmin        = isAdmin();

// ── POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Sign agreement
    if ($action === 'sign') {
        $agreementId = (int)($_POST['agreement_id'] ?? 0);
        if (!$agreementId) { setFlash('error', 'Invalid agreement.'); redirect(SITE_URL . '/partner/agreements'); }

        // Verify this agreement is assigned to this partner and unsigned
        $agr = $pdo->prepare("
            SELECT pa.*, pt.title AS template_title, pt.content AS template_content
            FROM partner_agreements pa
            JOIN partner_agreement_templates pt ON pt.id = pa.template_id
            WHERE pa.id=? AND pa.partner_id=? AND pa.signed_at IS NULL
        ");
        $agr->execute([$agreementId, $partnerId]);
        $agr = $agr->fetch();

        if (!$agr) { setFlash('error', 'Agreement not found or already signed.'); redirect(SITE_URL . '/partner/agreements'); }

        // Require explicit acknowledgement
        if (!isset($_POST['i_agree'])) { setFlash('error', 'You must tick the checkbox to accept the agreement.'); redirect(SITE_URL . '/partner/agreements?sign=' . $agreementId); }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $pdo->prepare("
            UPDATE partner_agreements
            SET signed_at = NOW(), signed_ip = ?, signed_name = ?
            WHERE id = ?
        ")->execute([$ip, $partnerProfile['display_name'] ?? '', $agreementId]);

        // Update partner profile
        $pdo->prepare("UPDATE partner_profiles SET agreement_signed_at=NOW(), agreement_version=? WHERE id=?")
            ->execute([$agr['version'] ?? '1.0', $partnerId]);

        partnerAuditLog($partnerId, $userId, null, 'agreement_signed', "Agreement ID: $agreementId, Version: " . ($agr['version'] ?? '1.0'));
        pushNotification($userId, 'agreement_signed', 'Agreement Signed', 'Your Growth Partner agreement has been signed successfully.', SITE_URL . '/partner/agreements');

        // Notify admin
        $adminIds = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($adminIds as $aid) {
            pushNotification((int)$aid, 'agreement_signed', 'Partner Signed Agreement',
                ($partnerProfile['display_name'] ?? 'A partner') . ' has signed their Growth Partner agreement.',
                SITE_URL . '/admin/manage-growth-partners');
        }

        setFlash('success', 'Agreement signed successfully. Welcome to the Growth Partner network!');
        redirect(SITE_URL . '/partner/agreements');
    }

    // Admin: issue agreement
    if ($action === 'issue' && $isAdmin) {
        $targetPid  = (int)($_POST['partner_id'] ?? $partnerId);
        $templateId = (int)($_POST['template_id'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');

        if (!$templateId) { setFlash('error', 'Please select a template.'); redirect(SITE_URL . '/partner/agreements'); }

        // Check template exists
        $tpl = $pdo->prepare("SELECT * FROM partner_agreement_templates WHERE id=?");
        $tpl->execute([$templateId]);
        $tpl = $tpl->fetch();
        if (!$tpl) { setFlash('error', 'Template not found.'); redirect(SITE_URL . '/partner/agreements'); }

        // Check no active unsigned agreement already exists
        $existsQ = $pdo->prepare("SELECT id FROM partner_agreements WHERE partner_id=? AND template_id=? AND signed_at IS NULL AND revoked_at IS NULL");
        $existsQ->execute([$targetPid, $templateId]);
        if ($existsQ->fetchColumn()) {
            setFlash('error', 'An unsigned agreement of this type already exists for this partner.');
            redirect(SITE_URL . '/partner/agreements');
        }

        $pdo->prepare("
            INSERT INTO partner_agreements (partner_id, template_id, version, issued_by, notes, issued_at)
            VALUES (?,?,?,?,?,NOW())
        ")->execute([$targetPid, $templateId, $tpl['version'], $userId, $notes]);

        // Notify partner
        $partnerUserId = $pdo->prepare("SELECT user_id FROM partner_profiles WHERE id=?");
        $partnerUserId->execute([$targetPid]);
        $puid = (int)$partnerUserId->fetchColumn();
        if ($puid) {
            pushNotification($puid, 'agreement_issued', 'New Agreement to Sign',
                'A new Growth Partner agreement has been issued for your signature.',
                SITE_URL . '/partner/agreements');
        }

        partnerAuditLog($targetPid, $userId, null, 'agreement_issued', "Template: {$tpl['title']}, Version: {$tpl['version']}");
        setFlash('success', 'Agreement issued successfully.');
        redirect(SITE_URL . '/partner/agreements');
    }

    // Admin: revoke agreement
    if ($action === 'revoke' && $isAdmin) {
        $agreementId = (int)($_POST['agreement_id'] ?? 0);
        $reason      = trim($_POST['reason'] ?? '');
        if (!$agreementId) { setFlash('error', 'Invalid agreement.'); redirect(SITE_URL . '/partner/agreements'); }

        $pdo->prepare("UPDATE partner_agreements SET revoked_at=NOW(), revoke_reason=? WHERE id=?")
            ->execute([$reason, $agreementId]);

        partnerAuditLog($partnerId, $userId, null, 'agreement_revoked', "Agreement ID: $agreementId");
        setFlash('success', 'Agreement revoked.');
        redirect(SITE_URL . '/partner/agreements');
    }

    redirect(SITE_URL . '/partner/agreements');
}

// ── Load agreements ───────────────────────────────────────
$signMode = (int)($_GET['sign'] ?? 0);

$agreements = $pdo->prepare("
    SELECT pa.*, pt.title AS template_title, pt.version, pt.content AS template_content,
           u.name AS issued_by_name
    FROM partner_agreements pa
    JOIN partner_agreement_templates pt ON pt.id = pa.template_id
    LEFT JOIN users u ON u.id = pa.issued_by
    WHERE pa.partner_id = ?
    ORDER BY pa.issued_at DESC
");
$agreements->execute([$partnerId]);
$agreements = $agreements->fetchAll();

// Unsigned agreements for signing
$unsigned = array_filter($agreements, fn($a) => !$a['signed_at'] && !$a['revoked_at']);
$signed   = array_filter($agreements, fn($a) => $a['signed_at'] && !$a['revoked_at']);
$revoked  = array_filter($agreements, fn($a) => $a['revoked_at']);

// Admin: all templates for issuing
$templates = [];
if ($isAdmin) {
    $templates = $pdo->query("SELECT * FROM partner_agreement_templates WHERE is_active=1 ORDER BY title")->fetchAll();
}

// Full agreement to sign
$signAgreement = null;
if ($signMode) {
    foreach ($unsigned as $a) {
        if ((int)$a['id'] === $signMode) { $signAgreement = $a; break; }
    }
}

$flash     = getFlash();
$pageTitle = pt('My Agreements');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:820px;margin:0 auto;padding:1.5rem}
.agr-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;margin-bottom:.75rem;overflow:hidden}
.agr-header{display:flex;align-items:flex-start;gap:.75rem;padding:.9rem 1.1rem;border-bottom:1px solid #f9fafb}
.agr-icon{font-size:1.5rem;flex-shrink:0}
.agr-meta{flex:1}
.agr-title{font-weight:700;font-size:.95rem;color:#111827;margin:0 0 .2rem}
.agr-sub{font-size:.78rem;color:#6b7280}
.agr-badges{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.35rem}
.badge{font-size:.68rem;font-weight:600;padding:.15rem .45rem;border-radius:99px}
.badge-unsigned{background:#fef3c7;color:#92400e}
.badge-signed{background:#d1fae5;color:#065f46}
.badge-revoked{background:#fee2e2;color:#991b1b}
.agr-actions{padding:.6rem 1.1rem;background:#f9fafb;border-top:1px solid #f3f4f6;display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
.sign-page{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden}
.sign-header{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;padding:1.5rem;text-align:center}
.sign-header h2{margin:0 0 .25rem;font-size:1.2rem}
.sign-header p{margin:0;opacity:.8;font-size:.85rem}
.agreement-text{padding:1.5rem;font-size:.875rem;color:#374151;line-height:1.7;max-height:400px;overflow-y:auto;border-bottom:1px solid #e5e7eb;white-space:pre-wrap}
.sign-form{padding:1.25rem}
.agree-check{display:flex;align-items:flex-start;gap:.6rem;margin-bottom:1rem;background:#f9fafb;padding:.75rem;border-radius:.5rem;border:1px solid #e5e7eb}
.agree-check input{flex-shrink:0;margin-top:.1rem;width:16px;height:16px}
.agree-check label{font-size:.85rem;color:#374151;cursor:pointer;line-height:1.45}
.meta-line{display:flex;gap:1.5rem;margin-bottom:1rem;font-size:.8rem;color:#6b7280}
.meta-val{font-weight:600;color:#374151}
.admin-form-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.25rem;margin-bottom:1.25rem}
.admin-form-card h3{margin:0 0 .75rem;font-size:.95rem;color:#111827}
.form-group{margin-bottom:.75rem}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.25rem}
.form-group select,.form-group textarea,.form-group input{width:100%;border:1px solid #d1d5db;border-radius:.45rem;padding:.45rem .7rem;font-size:.875rem;box-sizing:border-box}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:.75rem;padding:1.5rem;max-width:400px;width:90%}
.modal h3{margin:0 0 .75rem;font-size:1rem;color:#111827}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-primary{background:#2563eb;color:#fff}
.btn-success{background:#059669;color:#fff}
.btn-danger{background:#dc2626;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.btn:hover{opacity:.9}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.empty{text-align:center;padding:3rem;color:#9ca3af}
.section-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin:1rem 0 .5rem}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827"><?= pt('Partner Agreements') ?></h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280"><?= pt('Your Growth Partner agreement and signing history') ?></p>
        </div>
        <div style="display:flex;gap:.5rem">
            <?php if ($isAdmin && !$signMode): ?>
            <button onclick="document.getElementById('issueModal').classList.add('open')" class="btn btn-primary"><?= pt('+ Issue Agreement') ?></button>
            <?php endif ?>
            <a href="<?= $signMode ? SITE_URL.'/partner/agreements' : SITE_URL.'/partner/dashboard' ?>" class="btn btn-detail"><?= $signMode ? ('← ' . pt('Back')) : ('← ' . pt('Dashboard')) ?></a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <?php if ($signAgreement): ?>
    <!-- ── Sign view ── -->
    <div class="sign-page">
        <div class="sign-header">
            <div style="font-size:2rem;margin-bottom:.5rem">📄</div>
            <h2><?= e($signAgreement['template_title']) ?></h2>
            <p><?= pt('Version') ?> <?= e($signAgreement['version']) ?> · <?= pt('Please read carefully before signing') ?></p>
        </div>
        <div class="meta-line" style="padding:.75rem 1.5rem;border-bottom:1px solid #f3f4f6;margin:0">
            <div><?= pt('Issued') ?> <span class="meta-val"><?= date('d M Y', strtotime($signAgreement['issued_at'])) ?></span></div>
            <div><?= pt('By') ?> <span class="meta-val"><?= e($signAgreement['issued_by_name'] ?? 'Admin') ?></span></div>
            <?php if ($signAgreement['notes']): ?>
            <div><?= pt('Note:') ?> <span class="meta-val"><?= e($signAgreement['notes']) ?></span></div>
            <?php endif ?>
        </div>
        <div class="agreement-text"><?= e($signAgreement['template_content'] ?? 'Agreement content not available.') ?></div>
        <div class="sign-form">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="sign">
                <input type="hidden" name="agreement_id" value="<?= $signAgreement['id'] ?>">
                <div class="agree-check">
                    <input type="checkbox" name="i_agree" id="i_agree" value="1" required>
                    <label for="i_agree">
                        <?= pt('I,') ?> <strong><?= e($partnerProfile['display_name'] ?? '') ?></strong>, <?= pt('have read and understood the above agreement in full. I agree to be bound by its terms and conditions as a Growth Partner of 237biz.') ?>
                    </label>
                </div>
                <button type="submit" class="btn btn-success" style="padding:.6rem 1.5rem;font-size:.9rem">✓ <?= pt('Sign Agreement') ?></button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Agreement list ── -->

    <!-- Admin issue form -->
    <?php if ($isAdmin && $templates): ?>
    <div id="issueModal" class="modal-overlay">
        <div class="modal" style="max-width:480px">
            <h3><?= pt('Issue Agreement') ?></h3>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="issue">
                <input type="hidden" name="partner_id" value="<?= $partnerId ?>">
                <div class="form-group">
                    <label><?= pt('Agreement Template') ?></label>
                    <select name="template_id" required>
                        <option value="">— <?= pt('Select template') ?> —</option>
                        <?php foreach ($templates as $tpl): ?>
                        <option value="<?= $tpl['id'] ?>"><?= e($tpl['title']) ?> (v<?= e($tpl['version']) ?>)</option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><?= pt('Notes (optional)') ?></label>
                    <textarea name="notes" rows="2" placeholder="<?= pt('Any notes for the partner…') ?>"></textarea>
                </div>
                <div style="display:flex;gap:.5rem">
                    <button type="submit" class="btn btn-primary"><?= pt('Issue Agreement') ?></button>
                    <button type="button" onclick="closeModals()" class="btn btn-detail"><?= pt('Cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif ?>

    <!-- Unsigned agreements -->
    <?php if ($unsigned): ?>
    <div class="section-title" style="color:#92400e">⚠ <?= pt('Awaiting Your Signature') ?> (<?= count($unsigned) ?>)</div>
    <?php foreach ($unsigned as $a): ?>
    <div class="agr-card">
        <div class="agr-header">
            <div class="agr-icon">📋</div>
            <div class="agr-meta">
                <div class="agr-title"><?= e($a['template_title']) ?></div>
                <div class="agr-sub"><?= pt('Issued') ?> <?= date('d M Y', strtotime($a['issued_at'])) ?> <?= pt('by') ?> <?= e($a['issued_by_name'] ?? 'Admin') ?></div>
                <div class="agr-badges">
                    <span class="badge badge-unsigned"><?= pt('Action Required') ?></span>
                    <span class="badge" style="background:#f3f4f6;color:#374151"><?= pt('Version') ?> <?= e($a['version']) ?></span>
                </div>
            </div>
        </div>
        <?php if ($a['notes']): ?>
        <div style="padding:.5rem 1.1rem;font-size:.82rem;color:#374151;border-bottom:1px solid #f9fafb"><?= e($a['notes']) ?></div>
        <?php endif ?>
        <div class="agr-actions">
            <a href="?sign=<?= $a['id'] ?>" class="btn btn-success"><?= pt('Read & Sign') ?> →</a>
            <?php if ($isAdmin): ?>
            <button onclick="openRevoke(<?= $a['id'] ?>)" class="btn btn-danger"><?= pt('Revoke') ?></button>
            <?php endif ?>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>

    <!-- Signed agreements -->
    <?php if ($signed): ?>
    <div class="section-title">✓ <?= pt('Signed Agreements') ?></div>
    <?php foreach ($signed as $a): ?>
    <div class="agr-card">
        <div class="agr-header">
            <div class="agr-icon">✅</div>
            <div class="agr-meta">
                <div class="agr-title"><?= e($a['template_title']) ?></div>
                <div class="agr-sub"><?= pt('Signed') ?> <?= date('d M Y H:i', strtotime($a['signed_at'])) ?> · <?= pt('From IP') ?> <?= e($a['signed_ip'] ?? '') ?></div>
                <div class="agr-badges">
                    <span class="badge badge-signed"><?= pt('Signed') ?></span>
                    <span class="badge" style="background:#f3f4f6;color:#374151"><?= pt('Version') ?> <?= e($a['version']) ?></span>
                </div>
            </div>
        </div>
        <div class="agr-actions">
            <button onclick="this.closest('.agr-card').querySelector('.agr-content').style.display='block';this.remove()" class="btn btn-detail btn-sm"><?= pt('View Agreement Text') ?></button>
            <?php if ($isAdmin): ?>
            <button onclick="openRevoke(<?= $a['id'] ?>)" class="btn btn-danger btn-sm"><?= pt('Revoke') ?></button>
            <?php endif ?>
        </div>
        <div class="agr-content" style="display:none;padding:1rem 1.25rem;font-size:.83rem;color:#374151;line-height:1.65;max-height:300px;overflow-y:auto;border-top:1px solid #f3f4f6;white-space:pre-wrap"><?= e($a['template_content'] ?? '') ?></div>
    </div>
    <?php endforeach ?>
    <?php endif ?>

    <!-- Revoked agreements -->
    <?php if ($revoked): ?>
    <div class="section-title" style="color:#9ca3af"><?= pt('Revoked Agreements') ?></div>
    <?php foreach ($revoked as $a): ?>
    <div class="agr-card" style="opacity:.6">
        <div class="agr-header">
            <div class="agr-icon">❌</div>
            <div class="agr-meta">
                <div class="agr-title"><?= e($a['template_title']) ?></div>
                <div class="agr-sub"><?= pt('Revoked') ?> <?= date('d M Y', strtotime($a['revoked_at'])) ?><?= $a['revoke_reason'] ? ' — ' . e($a['revoke_reason']) : '' ?></div>
                <div class="agr-badges"><span class="badge badge-revoked"><?= pt('Revoked') ?></span></div>
            </div>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>

    <?php if (!$agreements): ?>
    <div class="empty">
        <div style="font-size:2.5rem;margin-bottom:.5rem">📄</div>
        <p><?= pt('No agreements yet. An admin will issue your Growth Partner agreement when your application is approved.') ?></p>
    </div>
    <?php endif ?>

    <?php endif ?>
</div>

<!-- Revoke modal -->
<?php if ($isAdmin): ?>
<div class="modal-overlay" id="revokeModal">
    <div class="modal">
        <h3><?= pt('Revoke Agreement') ?></h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="agreement_id" id="revokeAgreementId">
            <div class="form-group">
                <label><?= pt('Reason (optional)') ?></label>
                <textarea name="reason" rows="3" placeholder="<?= pt('Reason for revoking this agreement…') ?>"></textarea>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-danger"><?= pt('Revoke') ?></button>
                <button type="button" onclick="closeModals()" class="btn btn-detail"><?= pt('Cancel') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif ?>

<script>
function openRevoke(id) {
    document.getElementById('revokeAgreementId').value = id;
    document.getElementById('revokeModal').classList.add('open');
}
function closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
}
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', function(e){ if(e.target===this) closeModals(); }));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
