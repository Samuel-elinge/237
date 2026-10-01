<?php
// validate-promo.php — AJAX endpoint for promo code validation
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['valid' => false, 'error' => 'Not logged in']); exit; }

$code    = strtoupper(trim($_POST['code'] ?? ''));
$type    = $_POST['applies_to'] ?? 'featured'; // 'featured' or 'services'
$amount  = (int)($_POST['amount'] ?? 0);

if (!$code) { echo json_encode(['valid' => false, 'error' => t('Enter a promo code','Entrez un code promo')]); exit; }

$st = db()->prepare("
    SELECT * FROM promo_codes
    WHERE code=? AND active=1
    AND (expires_at IS NULL OR expires_at > NOW())
    AND (max_uses IS NULL OR used_count < max_uses)
    AND (applies_to=? OR applies_to='both')
");
$st->execute([$code, $type]);
$promo = $st->fetch();

if (!$promo) {
    echo json_encode(['valid' => false, 'error' => t('Invalid or expired promo code.','Code promo invalide ou expiré.')]);
    exit;
}

// Calculate discount
$discount = 0;
if ($promo['discount_pct'] > 0) {
    $discount = (int) round($amount * ($promo['discount_pct'] / 100));
}
if ($promo['discount_xaf'] > 0) {
    $discount = max($discount, $promo['discount_xaf']);
}
$discount  = min($discount, $amount); // Can't discount more than amount
$finalAmt  = $amount - $discount;

echo json_encode([
    'valid'       => true,
    'code'        => $promo['code'],
    'discount'    => $discount,
    'final'       => $finalAmt,
    'discount_pct'=> $promo['discount_pct'],
    'message'     => ($promo['discount_pct'] > 0
        ? $promo['discount_pct'] . '% ' . t('discount applied!','de réduction appliqué !')
        : number_format($discount) . ' XAF ' . t('discount applied!','de réduction appliqué !')),
]);
