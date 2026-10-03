<?php
/**
 * partner/certifications.php — Partner Certifications (Task 43)
 *
 * Partners: view available certifications, their awarded certs, progress.
 * Admins (via ?admin=1&pid=X): award or revoke certs for a specific partner.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$pdo = db();

// ── Admin mode: manage another partner's certs ────────────
$adminMode  = false;
$targetPid  = 0;
$targetName = '';

if (isset($_GET['admin']) && isAdmin()) {
    $adminMode = true;
    $targetPid = (int)($_GET['pid'] ?? 0);
    if ($targetPid) {
        $tgt = $pdo->prepare("SELECT pp.id, COALESCE(pp.display_name, u.name) AS dname FROM partner_profiles pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?");
        $tgt->execute([$targetPid]);
        $tgt = $tgt->fetch();
        $targetName = $tgt ? $tgt['dname'] : '';
        if (!$tgt) { setFlash('error', 'Partner not found.'); redirect(SITE_URL . '/admin'); }
    }
    $partnerId = $targetPid;
} else {
    requireGrowthPartner();
    $partnerRow = $pdo->prepare("SELECT id FROM partner_profiles WHERE user_id=? AND status IN ('approved','active')");
    $partnerRow->execute([$_SESSION['user_id']]);
    $partnerRow = $partnerRow->fetch();
    if (!$partnerRow) { setFlash('error', 'Partner profile not found.'); redirect(SITE_URL . '/partner/dashboard'); }
    $partnerId = (int)$partnerRow['id'];
}

// ── POST: award / revoke (admin only) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminMode) {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $certId  = (int)($_POST['cert_id'] ?? 0);
    $pid     = (int)($_POST['pid'] ?? 0);

    if ($action === 'award' && $certId && $pid) {
        $expires = $_POST['expires_at'] ?? null;
        $expires = ($expires && $expires !== '') ? $expires : null;
        // Insert or re-activate
        $pdo->prepare("INSERT INTO partner_cert_awards (partner_id, cert_id, awarded_by, expires_at)
                        VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE awarded_by=VALUES(awarded_by), awarded_at=NOW(), expires_at=VALUES(expires_at), revoked_at=NULL")
            ->execute([$pid, $certId, $_SESSION['user_id'], $expires]);
        partnerAuditLog($pid, (int)$_SESSION['user_id'], null, 'cert_awarded', "Cert ID $certId awarded");
        pushNotification(
            (int)$pdo->query("SELECT user_id FROM partner_profiles WHERE id=$pid")->fetchColumn(),
            'cert_awarded', 'New Certification Awarded 🏅',
            'You have been awarded a certification. Visit your certifications page to view it.',
            SITE_URL . '/partner/certifications', $pid
        );
        setFlash('success', 'Certification awarded.');
    }

    if ($action === 'revoke' && $certId && $pid) {
        $pdo->prepare("UPDATE partner_cert_awards SET revoked_at=NOW() WHERE partner_id=? AND cert_id=?")
            ->execute([$pid, $certId]);
        partnerAuditLog($pid, (int)$_SESSION['user_id'], null, 'cert_revoked', "Cert ID $certId revoked");
        setFlash('success', 'Certification revoked.');
    }

    redirect(SITE_URL . '/partner/certifications?admin=1&pid=' . $pid);
}

// ── Load all certifications ───────────────────────────────
$allCerts = $pdo->query("SELECT * FROM partner_certifications ORDER BY level, name")->fetchAll();

// ── Load this partner's awarded certs ────────────────────
$awarded = $pdo->prepare("
    SELECT pca.cert_id, pca.awarded_at, pca.expires_at, pca.revoked_at,
           pc.name, pc.slug, pc.description, pc.level, pc.badge_icon
    FROM partner_cert_awards pca
    JOIN partner_certifications pc ON pc.id = pca.cert_id
    WHERE pca.partner_id = ?
    ORDER BY pca.awarded_at DESC
");
$awarded->execute([$partnerId]);
$awarded = $awarded->fetchAll();
$awardedIds = array_column($awarded, 'cert_id');

// Flash
$flash = getFlash();

$pageTitle = $adminMode ? pt('Certifications') . ' — ' . $targetName : pt('My Certifications');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.cert-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;margin-bottom:2rem}
.cert-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.25rem;position:relative;transition:box-shadow .15s}
.cert-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
.cert-card.awarded{border-color:#059669;background:#f0fdf4}
.cert-card.revoked{opacity:.5;border-color:#e5e7eb}
.cert-icon{font-size:2rem;margin-bottom:.5rem}
.cert-name{font-weight:700;font-size:.95rem;color:#111827;margin-bottom:.25rem}
.cert-desc{font-size:.8rem;color:#6b7280;margin-bottom:.75rem;line-height:1.4}
.cert-level{display:inline-block;font-size:.7rem;font-weight:600;padding:.15rem .5rem;border-radius:99px;margin-bottom:.5rem}
.level-foundation{background:#dbeafe;color:#1d4ed8}
.level-professional{background:#ede9fe;color:#6d28d9}
.level-advanced{background:#fef3c7;color:#92400e}
.level-expert{background:#fee2e2;color:#991b1b}
.cert-badge{position:absolute;top:.75rem;right:.75rem;font-size:.75rem;font-weight:600;padding:.2rem .55rem;border-radius:99px}
.cert-badge.awarded{background:#059669;color:#fff}
.cert-badge.revoked{background:#9ca3af;color:#fff}
.cert-date{font-size:.75rem;color:#6b7280;margin-top:.5rem}
.cert-actions{display:flex;gap:.4rem;margin-top:.75rem;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .8rem;border-radius:.45rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none}
.btn-award{background:#059669;color:#fff}
.btn-revoke{background:#dc2626;color:#fff}
.btn-detail{background:#e5e7eb;color:#374151}
.flash{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.875rem}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error{background:#fee2e2;color:#991b1b}
.section-title{font-size:1rem;font-weight:700;color:#111827;margin:0 0 .75rem}
.empty-hint{text-align:center;padding:2rem;color:#9ca3af;font-size:.875rem}
/* Award modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:.75rem;padding:1.5rem;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{margin:0 0 .75rem;font-size:1rem}
.modal label{font-size:.82rem;font-weight:600;color:#374151;display:block;margin:.6rem 0 .2rem}
.modal input{width:100%;border:1px solid #d1d5db;border-radius:.45rem;padding:.45rem .7rem;font-size:.875rem;box-sizing:border-box}
.modal-actions{display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem}
</style>

<div style="max-width:860px;margin:0 auto;padding:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827"><?= $adminMode ? pt('Certifications') . ' — ' . e($targetName) : pt('My Certifications') ?></h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280">
                <?= $adminMode ? pt('Award or revoke certifications for this partner') : pt('Track your certifications and professional development') ?>
            </p>
        </div>
        <?php if ($adminMode): ?>
        <a href="<?= SITE_URL ?>/admin/manage-growth-partners" class="btn btn-detail"><?= pt('← Partners') ?></a>
        <?php else: ?>
        <a href="<?= SITE_URL ?>/partner/dashboard" class="btn btn-detail">← <?= pt('Dashboard') ?></a>
        <?php endif ?>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>

    <!-- Awarded Certs -->
    <?php if ($awarded): ?>
    <p class="section-title"><?= pt('Awarded Certifications') ?> (<?= count($awarded) ?>)</p>
    <div class="cert-grid">
        <?php foreach ($awarded as $c):
            $isRevoked  = !empty($c['revoked_at']);
            $isExpired  = $c['expires_at'] && strtotime($c['expires_at']) < time();
            $cardClass  = $isRevoked ? 'revoked' : 'awarded';
            $badgeLabel = $isRevoked ? pt('Revoked') : ($isExpired ? pt('Expired') : pt('Awarded'));
            $badgeClass = $isRevoked || $isExpired ? 'revoked' : 'awarded';
        ?>
        <div class="cert-card <?= $cardClass ?>">
            <span class="cert-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
            <div class="cert-icon"><?= e($c['badge_icon'] ?: '🏅') ?></div>
            <span class="cert-level level-<?= e($c['level']) ?>"><?= ucfirst($c['level']) ?></span>
            <div class="cert-name"><?= e($c['name']) ?></div>
            <div class="cert-desc"><?= e($c['description']) ?></div>
            <div class="cert-date">
                <?= pt('Awarded') ?> <?= date('d M Y', strtotime($c['awarded_at'])) ?>
                <?php if ($c['expires_at']): ?>· <?= pt('Expires') ?> <?= date('d M Y', strtotime($c['expires_at'])) ?><?php endif ?>
            </div>
            <?php if ($adminMode && !$isRevoked): ?>
            <div class="cert-actions">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="cert_id" value="<?= $c['cert_id'] ?>">
                    <input type="hidden" name="pid" value="<?= $partnerId ?>">
                    <button type="submit" class="btn btn-revoke" onclick="return confirm('<?= pt('Revoke this certification?') ?>')"><?= pt('Revoke') ?></button>
                </form>
            </div>
            <?php endif ?>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <!-- Available Certs -->
    <p class="section-title"><?= pt('Available Certifications') ?></p>
    <?php if (!$allCerts): ?>
    <div class="empty-hint"><?= pt('No certifications configured yet.') ?></div>
    <?php else: ?>
    <div class="cert-grid">
        <?php foreach ($allCerts as $c):
            $alreadyAwarded = in_array($c['id'], $awardedIds);
        ?>
        <div class="cert-card <?= $alreadyAwarded ? 'awarded' : '' ?>">
            <?php if ($alreadyAwarded): ?><span class="cert-badge awarded"><?= pt('Awarded') ?></span><?php endif ?>
            <div class="cert-icon"><?= e($c['badge_icon'] ?: '🎖️') ?></div>
            <span class="cert-level level-<?= e($c['level']) ?>"><?= ucfirst($c['level']) ?></span>
            <div class="cert-name"><?= e($c['name']) ?></div>
            <div class="cert-desc"><?= e($c['description']) ?></div>
            <?php if ($adminMode && !$alreadyAwarded): ?>
            <div class="cert-actions">
                <button class="btn btn-award" onclick="openAward(<?= $c['id'] ?>, <?= e(json_encode($c['name'])) ?>)"><?= pt('Award') ?></button>
            </div>
            <?php endif ?>
            <?php if (!$adminMode && !$alreadyAwarded): ?>
            <div class="cert-date" style="color:#9ca3af"><?= pt('Complete requirements to earn this') ?></div>
            <?php endif ?>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</div>

<?php if ($adminMode): ?>
<!-- Award Modal -->
<div class="modal-overlay" id="modal-award">
    <div class="modal">
        <h3><?= pt('Award Certification') ?></h3>
        <p id="award-cert-name" style="color:#6b7280;font-size:.875rem;margin:.25rem 0 .75rem"></p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="award">
            <input type="hidden" name="cert_id" id="award-cert-id" value="">
            <input type="hidden" name="pid" value="<?= $partnerId ?>">

            <label><?= pt('Expiry Date') ?> <span style="color:#9ca3af;font-weight:400"><?= pt('(leave blank = no expiry)') ?></span></label>
            <input type="date" name="expires_at" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">

            <div class="modal-actions">
                <button type="button" class="btn btn-detail" onclick="closeModal()"><?= pt('Cancel') ?></button>
                <button type="submit" class="btn btn-award"><?= pt('Award Certification') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
function openAward(id, name) {
    document.getElementById('award-cert-id').value = id;
    document.getElementById('award-cert-name').textContent = name;
    document.getElementById('modal-award').classList.add('open');
}
function closeModal() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
}
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if (e.target===o) closeModal(); }));
</script>
<?php endif ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
