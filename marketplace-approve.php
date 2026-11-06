<?php
/**
 * marketplace-approve.php — Business owner approves or rejects a marketplace order
 *
 * Accessed via email link. Owner must be logged in as the listing owner.
 * Does NOT require growth_partner role — business owners use this page.
 *
 * URL: /marketplace-approve?ref=MPO-XXXXX
 */
require_once __DIR__ . '/includes/config.php';

$user = currentUser();
if (!$user) {
    // Store intended URL and redirect to login
    $_SESSION['redirect_after_login'] = SITE_URL . '/marketplace-approve?ref=' . urlencode($_GET['ref'] ?? '');
    redirect(SITE_URL . '/login');
}

$pdo  = db();
$lang = $user['lang'] ?? 'en';

function mpT(string $en, string $fr): string {
    global $lang;
    return $lang === 'fr' ? $fr : $en;
}
function mpFmt(int $xaf): string {
    return number_format($xaf) . ' FCFA';
}

$ref = trim($_GET['ref'] ?? '');
if (!$ref) redirect(SITE_URL . '/dashboard');

/* ── Load order — user must own the listing ──────────────────── */
$stO = $pdo->prepare("
    SELECT o.*,
           l.title AS listing_title, l.user_id AS owner_id,
           pp.id AS partner_profile_id,
           u_partner.name AS partner_name, u_partner.email AS partner_email,
           oa.id AS approval_id, oa.decision AS approval_decision,
           oa.decided_at, oa.reviewer_note, oa.expires_at
    FROM marketplace_orders o
    JOIN listings l ON l.id = o.listing_id
    JOIN partner_profiles pp ON pp.id = o.partner_profile_id
    JOIN users u_partner ON u_partner.id = pp.user_id
    LEFT JOIN mp_order_approvals oa ON oa.order_id = o.id
    WHERE o.ref = ? AND l.user_id = ?
    LIMIT 1
");
$stO->execute([$ref, $user['id']]);
$order = $stO->fetch();

if (!$order) {
    // Not found or not this owner
    redirect(SITE_URL . '/dashboard');
}

$orderId    = (int)$order['id'];
$approvalId = (int)$order['approval_id'];
$alreadyDecided = $order['approval_decision'] !== 'PENDING';

/* ── POST: decision ───────────────────────────────────────────── */
$errors  = [];
$done    = false;
$decision = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyDecided) {
    verifyCsrfOrDie();

    $decision = $_POST['decision'] ?? '';
    $note     = trim($_POST['reviewer_note'] ?? '');

    if (!in_array($decision, ['APPROVED', 'REJECTED'])) {
        $errors[] = mpT('Please choose Approve or Reject.', 'Veuillez choisir Approuver ou Rejeter.');
    }

    // Check expiry
    if (!$errors && $order['expires_at'] && strtotime($order['expires_at']) < time()) {
        $errors[] = mpT('This approval request has expired.', 'Cette demande d\'approbation a expiré.');
    }

    if (!$errors) {
        $newOrderState = $decision === 'APPROVED' ? 'AWAITING_PAYMENT' : 'REJECTED';

        // 1. Update approval record
        $pdo->prepare("
            UPDATE mp_order_approvals
            SET decision = ?, decided_at = NOW(), decided_by_user_id = ?, reviewer_note = ?
            WHERE id = ?
        ")->execute([$decision, $user['id'], $note ?: null, $approvalId]);

        // 2. Update order state
        $pdo->prepare("
            UPDATE marketplace_orders
            SET order_state = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$newOrderState, $orderId]);

        // 3. Audit
        $pdo->prepare("
            INSERT INTO mp_audit_events (entity_type, entity_id, actor_user_id, actor_role, action, after_state, ip_address)
            VALUES ('marketplace_orders', ?, ?, 'business_owner', ?,
                    JSON_OBJECT('order_state', ?, 'note', ?), ?)
        ")->execute([
            $orderId, $user['id'],
            'approval.' . strtolower($decision),
            $newOrderState, $note,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        // 4. Load items for notification
        $stItem = $pdo->prepare("SELECT product_snapshot FROM marketplace_order_items WHERE order_id = ? LIMIT 1");
        $stItem->execute([$orderId]);
        $item = $stItem->fetch();
        $productName = '';
        if ($item) {
            $ps = json_decode($item['product_snapshot'], true) ?: [];
            $productName = $lang === 'fr' ? ($ps['name_fr'] ?? $ps['name_en'] ?? '') : ($ps['name_en'] ?? '');
        }

        // 5. Notify partner
        $orderUrl = SITE_URL . '/partner/marketplace-order?ref=' . $ref;
        if ($decision === 'APPROVED') {
            $subj = mpT("Order Approved — {$ref}", "Commande approuvée — {$ref}");
            $body = '<p>' . mpT('Great news! The business owner has approved your service request.', 'Bonne nouvelle ! Le propriétaire de l\'entreprise a approuvé votre demande de service.') . '</p>'
                  . '<p><strong>' . e($productName) . '</strong> ' . mpT('for', 'pour') . ' ' . e($order['listing_title']) . '</p>'
                  . '<p>' . mpT('Please submit your payment to proceed.', 'Veuillez soumettre votre paiement pour continuer.') . '</p>'
                  . '<p><a href="' . $orderUrl . '" style="background:#00A878;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;">' . mpT('Submit Payment →', 'Soumettre le paiement →') . '</a></p>';
        } else {
            $subj = mpT("Order Rejected — {$ref}", "Commande rejetée — {$ref}");
            $body = '<p>' . mpT('The business owner has rejected your service request.', 'Le propriétaire de l\'entreprise a rejeté votre demande de service.') . '</p>'
                  . '<p><strong>' . e($productName) . '</strong> ' . mpT('for', 'pour') . ' ' . e($order['listing_title']) . '</p>'
                  . ($note ? '<p>' . mpT('Reason:', 'Raison :') . ' ' . e($note) . '</p>' : '')
                  . '<p><a href="' . $orderUrl . '">' . mpT('View order', 'Voir la commande') . '</a></p>';
        }
        sendMail($order['partner_email'], $subj, $body);

        $done = true;
        $alreadyDecided = true;
    }
}

/* ── Load order items for display ────────────────────────────── */
$stItems = $pdo->prepare("
    SELECT oi.*,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.product_snapshot,'$.name_en')), '') AS display_name_en,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.product_snapshot,'$.name_fr')), '') AS display_name_fr
    FROM marketplace_order_items oi
    WHERE oi.order_id = ?
");
$stItems->execute([$orderId]);
$orderItems = $stItems->fetchAll();

$pageTitle = mpT('Review Service Request', 'Examiner la demande de service') . ' — ' . e($ref);
require_once __DIR__ . '/includes/header.php';
?>

<style>
.approval-wrap { max-width:720px; margin:3rem auto; padding:0 1rem; }
</style>

<div class="approval-wrap">

  <div class="text-center mb-4">
    <div style="font-size:3rem;">🛒</div>
    <h1 style="font-family:'Fraunces',serif;font-size:2rem;font-weight:900;margin:0.5rem 0;">
      <?= mpT('Service Request', 'Demande de service') ?>
    </h1>
    <p class="text-muted"><?= mpT('from your Growth Partner', 'de votre Partenaire de Croissance') ?>
      · <strong><?= e($order['partner_name']) ?></strong></p>
  </div>

  <?php if ($done): ?>
  <div class="text-center py-5 card shadow-sm">
    <div class="card-body">
      <?php if ($decision === 'APPROVED'): ?>
      <div style="font-size:4rem;">✅</div>
      <h2 class="mt-3"><?= mpT('Order Approved', 'Commande approuvée') ?></h2>
      <p class="text-muted"><?= mpT(
          'Your Growth Partner has been notified and will submit payment.',
          'Votre Partenaire de Croissance a été notifié et soumettra le paiement.'
      ) ?></p>
      <?php else: ?>
      <div style="font-size:4rem;">❌</div>
      <h2 class="mt-3"><?= mpT('Order Rejected', 'Commande rejetée') ?></h2>
      <p class="text-muted"><?= mpT('Your Growth Partner has been notified.', 'Votre Partenaire de Croissance a été notifié.') ?></p>
      <?php endif; ?>
      <a href="<?= SITE_URL ?>/dashboard" class="btn btn-outline-secondary mt-3">
        <?= mpT('Back to Dashboard', 'Retour au tableau de bord') ?>
      </a>
    </div>
  </div>

  <?php elseif ($alreadyDecided): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-body text-center py-4">
      <div style="font-size:3rem;"><?= $order['approval_decision'] === 'APPROVED' ? '✅' : '❌' ?></div>
      <h3 class="mt-2"><?= mpT('Already decided', 'Déjà décidé') ?></h3>
      <p class="text-muted">
        <?= mpT('This request was', 'Cette demande a été') ?>
        <strong><?= $order['approval_decision'] === 'APPROVED' ? mpT('approved', 'approuvée') : mpT('rejected', 'rejetée') ?></strong>
        <?= mpT('on', 'le') ?> <?= date('d M Y H:i', strtotime($order['decided_at'])) ?>.
      </p>
      <?php if ($order['reviewer_note']): ?>
      <p class="text-muted small"><?= mpT('Note:', 'Note :') ?> <?= e($order['reviewer_note']) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <?php else: ?>

  <!-- Order details -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-bold"><?= mpT('Order Details', 'Détails de la commande') ?></div>
    <div class="card-body">
      <dl class="row mb-0">
        <dt class="col-sm-4"><?= mpT('Reference', 'Référence') ?></dt>
        <dd class="col-sm-8"><code><?= e($ref) ?></code></dd>
        <dt class="col-sm-4"><?= mpT('Business', 'Entreprise') ?></dt>
        <dd class="col-sm-8"><?= e($order['listing_title']) ?></dd>
        <dt class="col-sm-4"><?= mpT('Requested by', 'Demandé par') ?></dt>
        <dd class="col-sm-8"><?= e($order['partner_name']) ?></dd>
        <dt class="col-sm-4"><?= mpT('Date', 'Date') ?></dt>
        <dd class="col-sm-8"><?= date('d M Y', strtotime($order['created_at'])) ?></dd>
      </dl>
    </div>
  </div>

  <!-- Items -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-bold"><?= mpT('Services Requested', 'Services demandés') ?></div>
    <div class="list-group list-group-flush">
      <?php foreach ($orderItems as $item):
        $name = $lang === 'fr' ? ($item['display_name_fr'] ?: $item['display_name_en']) : $item['display_name_en'];
        $ps = json_decode($item['price_snapshot'] ?? '{}', true) ?: [];
      ?>
      <div class="list-group-item d-flex justify-content-between align-items-start">
        <div>
          <strong><?= e($name) ?></strong>
          <?php if ($ps['setup_amount'] > 0 || ($ps['recurring_amount'] ?? 0) > 0): ?>
          <div class="text-muted small">
            <?php if ($ps['setup_amount'] > 0): ?>
            <?= mpT('Setup', 'Mise en place') ?>: <?= mpFmt((int)$ps['setup_amount']) ?>
            <?php endif; ?>
            <?php if (($ps['recurring_amount'] ?? 0) > 0): ?>
            + <?= mpFmt((int)$ps['recurring_amount']) ?>/<?= strtolower($ps['billing_interval'] ?? '') ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <strong class="text-success"><?= mpFmt((int)$item['amount_due_now']) ?></strong>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="card-footer d-flex justify-content-between fw-bold">
      <span><?= mpT('Total due now', 'Total à payer maintenant') ?></span>
      <span class="text-success"><?= mpFmt((int)$order['due_now_amount']) ?></span>
    </div>
  </div>

  <?php if ($order['partner_note']): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-bold"><?= mpT('Note from Partner', 'Note du partenaire') ?></div>
    <div class="card-body"><?= nl2br(e($order['partner_note'])) ?></div>
  </div>
  <?php endif; ?>

  <div class="alert alert-info mb-4">
    <?= mpT(
        '⚠ You are not being charged now. If you approve, your Growth Partner will arrange payment.',
        '⚠ Vous n\'êtes pas facturé maintenant. Si vous approuvez, votre Partenaire de Croissance organisera le paiement.'
    ) ?>
    <?php if ($order['expires_at']): ?>
    <div class="mt-1 small"><?= mpT('This request expires on', 'Cette demande expire le') ?> <?= date('d M Y', strtotime($order['expires_at'])) ?>.</div>
    <?php endif; ?>
  </div>

  <?php if ($errors): ?>
  <div class="alert alert-danger mb-3">
    <?php foreach ($errors as $err): ?><div>⚠ <?= e($err) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Decision form -->
  <div class="card shadow-sm">
    <div class="card-header fw-bold"><?= mpT('Your Decision', 'Votre décision') ?></div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?>

        <div class="mb-3">
          <label class="form-label"><?= mpT('Note (optional)', 'Note (facultatif)') ?></label>
          <textarea name="reviewer_note" class="form-control" rows="3"
            placeholder="<?= mpT('Add a reason or message to your partner…', 'Ajoutez une raison ou un message à votre partenaire…') ?>"><?= e($_POST['reviewer_note'] ?? '') ?></textarea>
        </div>

        <div class="d-flex gap-3">
          <button type="submit" name="decision" value="APPROVED"
            class="btn btn-success btn-lg flex-grow-1">
            ✅ <?= mpT('Approve', 'Approuver') ?>
          </button>
          <button type="submit" name="decision" value="REJECTED"
            class="btn btn-danger btn-lg flex-grow-1">
            ❌ <?= mpT('Reject', 'Rejeter') ?>
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
