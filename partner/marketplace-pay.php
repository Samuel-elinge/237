<?php
/**
 * partner/marketplace-pay.php — Submit payment proof for a marketplace order
 *
 * Partner uploads a screenshot of their MoMo/Orange Money transfer.
 * Creates a new mp_payment_attempts row; sets order to PAYMENT_REVIEW.
 * Never overwrites existing payment history — always a new attempt row.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$user           = currentUser();
$pdo            = db();
$lang           = $user['lang'] ?? 'en';

function mpT(string $en, string $fr): string {
    global $lang;
    return $lang === 'fr' ? $fr : $en;
}
function mpFmt(int $xaf): string {
    return number_format($xaf) . ' FCFA';
}

$ref = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
if (!$ref) redirect(SITE_URL . '/partner/marketplace');

/* ── Load order ───────────────────────────────────────────────── */
$stO = $pdo->prepare("
    SELECT o.*, l.title AS listing_title
    FROM marketplace_orders o
    JOIN listings l ON l.id = o.listing_id
    WHERE o.ref = ? AND o.partner_profile_id = ?
    LIMIT 1
");
$stO->execute([$ref, $partnerId]);
$order = $stO->fetch();

if (!$order || $order['order_state'] !== 'AWAITING_PAYMENT') {
    // Not in correct state — redirect back to order
    redirect(SITE_URL . '/partner/marketplace-order?ref=' . urlencode($ref));
}

$orderId = (int)$order['id'];
$errors  = [];
$success = false;

/* ── POST: upload proof ───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    verifyCsrfOrDie();

    $provider   = $_POST['provider']     ?? '';
    $provRef    = trim($_POST['prov_ref'] ?? '');
    $partnerNote = trim($_POST['partner_note'] ?? '');
    $amount     = (int)$order['due_now_amount'];

    // Validate provider
    if (!in_array($provider, ['MTN_MOMO', 'ORANGE_MONEY', 'OTHER'])) {
        $errors[] = mpT('Please select a payment method.', 'Veuillez sélectionner un mode de paiement.');
    }

    // Validate file
    if (empty($_FILES['proof']['name'])) {
        $errors[] = mpT('Please upload your payment screenshot or receipt.', 'Veuillez télécharger votre capture d\'écran ou reçu de paiement.');
    }

    $proofPath = null;
    if (!$errors && !empty($_FILES['proof']['name'])) {
        $origName = $_FILES['proof']['name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
            $errors[] = mpT('Only JPG, PNG or PDF files are accepted.', 'Seuls les fichiers JPG, PNG ou PDF sont acceptés.');
        } elseif ($_FILES['proof']['size'] > 8 * 1024 * 1024) {
            $errors[] = mpT('File must be under 8 MB.', 'Le fichier doit faire moins de 8 Mo.');
        } elseif ($_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = mpT('Upload failed. Please try again.', 'L\'envoi a échoué. Veuillez réessayer.');
        } else {
            $filename  = 'mktpay_' . uniqid() . '.' . $ext;
            $uploadDir = UPLOAD_DIR . 'marketplace/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            if (!move_uploaded_file($_FILES['proof']['tmp_name'], $uploadDir . $filename)) {
                $errors[] = mpT('Could not save the file. Please try again.', 'Impossible d\'enregistrer le fichier. Veuillez réessayer.');
            } else {
                $proofPath = 'marketplace/' . $filename;
            }
        }
    }

    if (!$errors) {
        // 1. Create payment attempt
        $pdo->prepare("
            INSERT INTO mp_payment_attempts
              (order_id, payment_state, provider, amount, currency,
               provider_ref, proof_path, submitted_by_user_id, partner_note)
            VALUES (?, 'PAYMENT_REVIEW', ?, ?, 'XAF', ?, ?, ?, ?)
        ")->execute([
            $orderId, $provider, $amount,
            $provRef ?: null, $proofPath,
            $user['id'], $partnerNote ?: null,
        ]);
        $attemptId = (int)$pdo->lastInsertId();

        // 2. Update order state
        $pdo->prepare("
            UPDATE marketplace_orders
            SET order_state = 'PAYMENT_REVIEW', payment_state = 'PAYMENT_REVIEW', updated_at = NOW()
            WHERE id = ?
        ")->execute([$orderId]);

        // 3. Audit
        $pdo->prepare("
            INSERT INTO mp_audit_events (entity_type, entity_id, actor_user_id, actor_role, action, after_state, ip_address)
            VALUES ('marketplace_orders', ?, ?, 'growth_partner', 'payment.proof_submitted',
                    JSON_OBJECT('order_state','PAYMENT_REVIEW','attempt_id',?), ?)
        ")->execute([$orderId, $user['id'], $attemptId, $_SERVER['REMOTE_ADDR'] ?? null]);

        // 4. Notify admin
        $productName = '';
        $stItem = $pdo->prepare("SELECT product_snapshot FROM marketplace_order_items WHERE order_id = ? LIMIT 1");
        $stItem->execute([$orderId]);
        $item = $stItem->fetch();
        if ($item) {
            $ps = json_decode($item['product_snapshot'], true) ?: [];
            $productName = $lang === 'fr' ? ($ps['name_fr'] ?? $ps['name_en'] ?? '') : ($ps['name_en'] ?? '');
        }

        $adminUrl = SITE_URL . '/admin/marketplace-orders?ref=' . $ref;
        sendMail(
            SITE_EMAIL,
            mpT("Payment Proof — {$ref}", "Preuve de paiement — {$ref}"),
            '<p>' . mpT('Payment proof submitted for order', 'Preuve de paiement soumise pour la commande') . ' <strong>' . e($ref) . '</strong>.</p>
             <p>' . mpT('Business:', 'Entreprise :') . ' ' . e($order['listing_title']) . '<br>
             ' . mpT('Service:', 'Service :') . ' ' . e($productName) . '<br>
             ' . mpT('Amount:', 'Montant :') . ' <strong>' . mpFmt($amount) . '</strong><br>
             ' . mpT('Method:', 'Méthode :') . ' ' . e($provider) . '</p>
             <p><a href="' . $adminUrl . '">' . mpT('Review in Admin →', 'Vérifier dans l\'Admin →') . '</a></p>'
        );

        $success = true;
    }
}

$mtnNum    = getSettingCached('mtn_momo_number')    ?: '';
$orangeNum = getSettingCached('orange_money_number') ?: '';
$payInstr  = getSettingCached($lang === 'fr' ? 'mp_payment_instruction_fr' : 'mp_payment_instruction_en') ?: '';

$pageTitle = mpT('Submit Payment', 'Soumettre le paiement') . ' — ' . e($ref);
require_once __DIR__ . '/../includes/partner-header.php';
?>

<div class="container py-4" style="max-width:720px;">

  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
      <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/partner/marketplace"><?= mpT('Marketplace', 'Marketplace') ?></a></li>
      <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/partner/marketplace-order?ref=<?= e($ref) ?>"><?= e($ref) ?></a></li>
      <li class="breadcrumb-item active"><?= mpT('Pay', 'Payer') ?></li>
    </ol>
  </nav>

  <h1 style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:900;margin-bottom:1.5rem;">
    💳 <?= mpT('Submit Payment', 'Soumettre le paiement') ?>
  </h1>

  <?php if ($success): ?>
  <div class="text-center py-5">
    <div style="font-size:4rem;">✅</div>
    <h2 class="mt-3"><?= mpT('Payment Proof Submitted!', 'Preuve de paiement soumise !') ?></h2>
    <p class="text-muted"><?= mpT(
        'Our team will review your payment and confirm within 1–2 business days.',
        'Notre équipe examinera votre paiement et confirmera sous 1 à 2 jours ouvrables.'
    ) ?></p>
    <a href="<?= SITE_URL ?>/partner/marketplace-order?ref=<?= e($ref) ?>" class="btn btn-success mt-2">
      <?= mpT('Track Order', 'Suivre la commande') ?>
    </a>
  </div>
  <?php else: ?>

  <!-- Payment instructions -->
  <div class="card shadow-sm mb-4" style="border-left:4px solid #00A878;">
    <div class="card-body">
      <h5 class="card-title"><?= mpT('How to Pay', 'Comment payer') ?></h5>
      <?php if ($payInstr): ?>
      <p><?= nl2br(e($payInstr)) ?></p>
      <?php else: ?>
      <p class="text-muted"><?= mpT(
          'Send the exact amount to one of the numbers below, then upload your payment screenshot.',
          'Envoyez le montant exact à l\'un des numéros ci-dessous, puis téléchargez votre capture d\'écran de paiement.'
      ) ?></p>
      <?php endif; ?>

      <div class="d-flex flex-wrap gap-3 mt-3">
        <?php if ($mtnNum): ?>
        <div class="border rounded p-3 d-flex align-items-center gap-2" style="min-width:200px;">
          <span style="font-size:1.6rem;">📱</span>
          <div>
            <div class="fw-bold">MTN MoMo</div>
            <div class="fs-5"><?= e($mtnNum) ?></div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($orangeNum): ?>
        <div class="border rounded p-3 d-flex align-items-center gap-2" style="min-width:200px;">
          <span style="font-size:1.6rem;">🍊</span>
          <div>
            <div class="fw-bold">Orange Money</div>
            <div class="fs-5"><?= e($orangeNum) ?></div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="mt-3 alert alert-info mb-0">
        <strong><?= mpT('Amount to send:', 'Montant à envoyer :') ?></strong>
        <span class="fs-5 ms-2 text-success fw-bold"><?= mpFmt((int)$order['due_now_amount']) ?></span>
        <div class="small text-muted mt-1"><?= mpT('Order reference:', 'Référence de commande :') ?> <code><?= e($ref) ?></code></div>
      </div>
    </div>
  </div>

  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <?php foreach ($errors as $err): ?><div>⚠ <?= e($err) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header fw-bold"><?= mpT('Upload Payment Proof', 'Télécharger la preuve de paiement') ?></div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="ref" value="<?= e($ref) ?>">
        <input type="hidden" name="submit_payment" value="1">

        <div class="mb-3">
          <label class="form-label fw-semibold"><?= mpT('Payment method', 'Mode de paiement') ?> <span class="text-danger">*</span></label>
          <div class="d-flex flex-wrap gap-3">
            <?php
            $methods = [
                'MTN_MOMO'     => '📱 MTN MoMo',
                'ORANGE_MONEY' => '🍊 Orange Money',
                'OTHER'        => mpT('Other', 'Autre'),
            ];
            foreach ($methods as $val => $label):
            ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="provider" id="prov_<?= $val ?>"
                value="<?= $val ?>" <?= ($_POST['provider'] ?? '') === $val ? 'checked' : '' ?> required>
              <label class="form-check-label" for="prov_<?= $val ?>"><?= $label ?></label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold"><?= mpT('Transaction ID / reference (optional)', 'ID de transaction / référence (facultatif)') ?></label>
          <input type="text" name="prov_ref" class="form-control"
            value="<?= e($_POST['prov_ref'] ?? '') ?>"
            placeholder="<?= mpT('e.g. 1234567890', 'ex. 1234567890') ?>">
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">
            <?= mpT('Payment screenshot or receipt', 'Capture d\'écran ou reçu de paiement') ?>
            <span class="text-danger">*</span>
          </label>
          <input type="file" name="proof" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
          <div class="form-text"><?= mpT('JPG, PNG or PDF — max 8 MB', 'JPG, PNG ou PDF — 8 Mo max') ?></div>
        </div>

        <div class="mb-4">
          <label class="form-label"><?= mpT('Note to admin (optional)', 'Note à l\'admin (facultatif)') ?></label>
          <textarea name="partner_note" class="form-control" rows="2"><?= e($_POST['partner_note'] ?? '') ?></textarea>
        </div>

        <div class="d-flex gap-3">
          <button type="submit" class="btn btn-success btn-lg flex-grow-1">
            <?= mpT('Submit Payment Proof', 'Soumettre la preuve de paiement') ?>
          </button>
          <a href="<?= SITE_URL ?>/partner/marketplace-order?ref=<?= e($ref) ?>" class="btn btn-outline-secondary">
            <?= mpT('Cancel', 'Annuler') ?>
          </a>
        </div>
      </form>
    </div>
  </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
