<?php
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(SITE_URL . '/');
verifyCsrf();

if (!empty($_POST['website_url'])) redirect(SITE_URL . '/');

$listingId   = (int)($_POST['listing_id'] ?? 0);
$senderName  = trim($_POST['sender_name'] ?? '');
$senderEmail = trim($_POST['sender_email'] ?? '');
$senderPhone = trim($_POST['sender_phone'] ?? '');
$message     = trim($_POST['message'] ?? '');

$errors = [];
if (!$listingId)  $errors[] = 'Invalid listing.';
if (!$senderName) $errors[] = t('Your name is required.','Votre nom est requis.');
if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) $errors[] = t('Valid email required.','Email valide requis.');
if (strlen($message) < 10) $errors[] = t('Please write a message (min 10 characters).','Veuillez écrire un message (min 10 caractères).');

$st = db()->prepare("
    SELECT l.*, u.email AS owner_email, u.name AS owner_name, u.id AS owner_id
    FROM listings l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE l.id = ? AND l.status = 'approved'
");
$st->execute([$listingId]);
$listing = $st->fetch();
if (!$listing) $errors[] = 'Listing not found.';

if ($errors) {
    flash('error', implode(' ', $errors));
    redirect($_SERVER['HTTP_REFERER'] ?? SITE_URL . '/listings');
}

// Save to DB
try {
    db()->prepare("INSERT INTO listing_enquiries (listing_id,sender_name,sender_email,sender_phone,message) VALUES (?,?,?,?,?)")
         ->execute([$listingId, $senderName, $senderEmail, $senderPhone ?: null, $message]);
    $enquiryId = db()->lastInsertId();
} catch (Exception $e) {
    // Table may not exist yet
    $enquiryId = 0;
}

// ── Automation: tag listing owner as has-enquiry ──────────
if ($listing['owner_id'] && file_exists(__DIR__ . '/automation/helper.php')) {
    require_once __DIR__ . '/automation/helper.php';
    addUserTag((int)$listing['owner_id'], 'has-enquiry');
    // Queue enquiry-received notification
    $tplSt = db()->prepare("SELECT id FROM automation_templates WHERE name='enquiry-received' LIMIT 1");
    $tplSt->execute();
    $tplId = $tplSt->fetchColumn();
    if ($tplId) queueEmail((int)$listing['owner_id'], (int)$tplId);
}

// Email to listing owner
$ownerEmail = $listing['owner_email'] ?? $listing['email'];
if ($ownerEmail) {
    sendMail(
        $ownerEmail,
        t('New enquiry for ','Nouvelle demande pour ') . $listing['title'] . ' — 237Biz',
        '<h2 style="color:#fff;font-family:Georgia,serif;">📬 ' . t('New Enquiry','Nouvelle Demande') . '</h2>
         <p style="color:rgba(255,255,255,0.7);">' . t('Someone sent an enquiry about your listing on 237Biz:','Quelqu\'un a envoyé une demande pour votre annonce sur 237Biz :') . '</p>
         <div style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:1.25rem;margin:1rem 0;">
           <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
             <tr><td style="color:rgba(255,255,255,0.5);padding:0.35rem 0;width:30%;">' . t('From','De') . '</td><td style="color:#fff;font-weight:500;">' . e($senderName) . '</td></tr>
             <tr><td style="color:rgba(255,255,255,0.5);padding:0.35rem 0;">Email</td><td style="color:#fff;">' . e($senderEmail) . '</td></tr>
             ' . ($senderPhone ? '<tr><td style="color:rgba(255,255,255,0.5);padding:0.35rem 0;">' . t('Phone','Téléphone') . '</td><td style="color:#fff;">' . e($senderPhone) . '</td></tr>' : '') . '
             <tr><td style="color:rgba(255,255,255,0.5);padding:0.35rem 0;">' . t('Listing','Annonce') . '</td><td style="color:#F5C842;">' . e($listing['title']) . '</td></tr>
           </table>
         </div>
         <div style="background:rgba(0,168,120,0.08);border:1px solid rgba(0,168,120,0.2);border-radius:10px;padding:1.25rem;margin:1rem 0;">
           <div style="color:rgba(255,255,255,0.5);font-size:0.8rem;margin-bottom:0.5rem;">' . t('Message','Message') . '</div>
           <p style="color:#fff;line-height:1.7;margin:0;">' . nl2br(e($message)) . '</p>
         </div>
         <p style="color:rgba(255,255,255,0.5);font-size:0.8rem;">' . t('Reply directly to this email to respond.','Répondez directement à cet email pour répondre.') . '</p>
         <a href="' . SITE_URL . '/listing/' . $listing['slug'] . '" style="display:inline-block;margin-top:1rem;background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;font-size:0.875rem;">' . t('View Listing →','Voir l\'annonce →') . '</a>'
    );
}

// Confirmation to sender
sendMail(
    $senderEmail,
    t('Your enquiry has been sent — 237Biz','Votre demande a été envoyée — 237Biz'),
    '<h2 style="color:#fff;font-family:Georgia,serif;">✅ ' . t('Enquiry Sent!','Demande envoyée !') . '</h2>
     <p style="color:rgba(255,255,255,0.7);">' . t('Hi','Bonjour') . ' ' . e($senderName) . ',</p>
     <p style="color:rgba(255,255,255,0.7);">' . t('Your message has been sent to','Votre message a été envoyé à') . ' <strong>' . e($listing['title']) . '</strong>. ' . t('They will respond to you directly.','Ils vous répondront directement.') . '</p>
     <a href="' . SITE_URL . '/listing/' . $listing['slug'] . '" style="display:inline-block;margin-top:1rem;color:#00A878;font-size:0.875rem;">' . t('← Back to listing','← Retour à l\'annonce') . '</a>'
);

flash('success', t('Your message has been sent! The business will contact you directly.',
                   'Votre message a été envoyé ! L\'entreprise vous contactera directement.'));
redirect(SITE_URL . '/listing/' . $listing['slug']);
