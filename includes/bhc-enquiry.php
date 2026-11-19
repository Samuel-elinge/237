<?php
/**
 * Business Health Check — Lead Generation & Enquiry Routing helpers
 *
 * Functions:
 *   bhc_generate_enquiry_ref(): string
 *   bhc_submit_enquiry(PDO, array $data): int|false
 *   bhc_get_enquiry(PDO, int $id): ?array
 *   bhc_get_enquiry_by_ref(PDO, string $ref): ?array
 *   bhc_get_enquiries(PDO, array $filters, int $limit, int $offset): array
 *   bhc_count_enquiries(PDO, array $filters): int
 *   bhc_update_enquiry_status(PDO, int $id, string $status, int $staffId): bool
 *   bhc_assign_enquiry(PDO, int $id, int $providerId, int $staffId): bool
 *   bhc_add_enquiry_note(PDO, int $enquiryId, int $staffId, string $note): int|false
 *   bhc_get_enquiry_notes(PDO, int $enquiryId): array
 *   bhc_route_enquiry(PDO, int $enquiryId): void
 *   bhc_send_enquiry_notifications(PDO, int $enquiryId, string $event): void
 *   bhc_record_notification(PDO, int $enquiryId, string $recipient, string $type, bool $ok, ?string $err): void
 */

defined('IN_237BIZ') or die('Direct access denied');

// ── Reference generation ───────────────────────────────────────────────────

/**
 * Generate a unique 12-char enquiry reference: BHC- + 8 uppercase alphanum chars.
 * Caller must check uniqueness in the DB if required, but collision probability
 * with 36^8 ≈ 2.8 trillion combinations is negligible for typical volumes.
 */
function bhc_generate_enquiry_ref(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // omit O/0 and I/1 to avoid confusion
    $code  = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return 'BHC-' . $code;
}

// ── Submit ─────────────────────────────────────────────────────────────────

/**
 * Insert a new enquiry record. Returns the new enquiry ID or false on failure.
 *
 * Required keys in $data:
 *   service_id, business_name, contact_name, email
 *
 * Optional keys:
 *   assessment_id, service_category_id, phone, location, preferred_contact,
 *   requirements, referral_source, gp_id, promo_code, lang,
 *   consent_sharing (bool), consent_marketing (bool), consent_ip
 */
function bhc_submit_enquiry(PDO $pdo, array $data): int|false
{
    // Generate unique ref
    $ref = bhc_generate_enquiry_ref();
    // Optionally verify uniqueness (loop up to 5 times)
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $check = $pdo->prepare('SELECT id FROM bhc_enquiries WHERE ref = :ref LIMIT 1');
        $check->execute([':ref' => $ref]);
        if (!$check->fetch()) break;
        $ref = bhc_generate_enquiry_ref();
    }

    $consentAt = (!empty($data['consent_sharing']) || !empty($data['consent_marketing']))
        ? date('Y-m-d H:i:s')
        : null;

    $stmt = $pdo->prepare(
        "INSERT INTO bhc_enquiries
           (ref, assessment_id, service_id, service_category_id,
            business_name, contact_name, email, phone, location,
            preferred_contact, requirements,
            referral_source, gp_id, promo_code, lang,
            consent_sharing, consent_marketing, consent_ip, consent_at)
         VALUES
           (:ref, :assessment_id, :service_id, :service_category_id,
            :business_name, :contact_name, :email, :phone, :location,
            :preferred_contact, :requirements,
            :referral_source, :gp_id, :promo_code, :lang,
            :consent_sharing, :consent_marketing, :consent_ip, :consent_at)"
    );

    $ok = $stmt->execute([
        ':ref'                => $ref,
        ':assessment_id'      => $data['assessment_id']      ?? null,
        ':service_id'         => (int)$data['service_id'],
        ':service_category_id'=> $data['service_category_id'] ?? null,
        ':business_name'      => trim($data['business_name']),
        ':contact_name'       => trim($data['contact_name']),
        ':email'              => strtolower(trim($data['email'])),
        ':phone'              => isset($data['phone'])       ? trim($data['phone'])    : null,
        ':location'           => isset($data['location'])    ? trim($data['location']) : null,
        ':preferred_contact'  => $data['preferred_contact']  ?? 'email',
        ':requirements'       => isset($data['requirements']) ? trim($data['requirements']) : null,
        ':referral_source'    => $data['referral_source']    ?? null,
        ':gp_id'              => $data['gp_id']              ?? null,
        ':promo_code'         => $data['promo_code']         ?? null,
        ':lang'               => in_array($data['lang'] ?? '', ['en','fr']) ? $data['lang'] : 'en',
        ':consent_sharing'    => empty($data['consent_sharing'])   ? 0 : 1,
        ':consent_marketing'  => empty($data['consent_marketing']) ? 0 : 1,
        ':consent_ip'         => $data['consent_ip']         ?? null,
        ':consent_at'         => $consentAt,
    ]);

    if (!$ok) return false;

    $enquiryId = (int)$pdo->lastInsertId();

    // Auto-route immediately after insert
    bhc_route_enquiry($pdo, $enquiryId);

    return $enquiryId;
}

// ── Fetch ──────────────────────────────────────────────────────────────────

function bhc_get_enquiry(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT e.*,
                s.name_en AS service_name_en, s.name_fr AS service_name_fr,
                c.name_en AS category_name_en, c.name_fr AS category_name_fr,
                p.business_name AS provider_name
         FROM bhc_enquiries e
         LEFT JOIN bhc_services s              ON s.id = e.service_id
         LEFT JOIN bhc_service_categories c    ON c.id = e.service_category_id
         LEFT JOIN bhc_service_providers p     ON p.id = e.assigned_provider_id
         WHERE e.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bhc_get_enquiry_by_ref(PDO $pdo, string $ref): ?array
{
    $stmt = $pdo->prepare(
        "SELECT e.*,
                s.name_en AS service_name_en, s.name_fr AS service_name_fr,
                p.business_name AS provider_name
         FROM bhc_enquiries e
         LEFT JOIN bhc_services s          ON s.id = e.service_id
         LEFT JOIN bhc_service_providers p ON p.id = e.assigned_provider_id
         WHERE e.ref = :ref"
    );
    $stmt->execute([':ref' => $ref]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Fetch a filtered list of enquiries for the admin panel.
 *
 * $filters keys (all optional):
 *   status, service_id, service_category_id, assigned_provider_id,
 *   gp_id, search (text search on ref/business_name/contact_name/email),
 *   date_from (Y-m-d), date_to (Y-m-d), unassigned (bool)
 */
function bhc_get_enquiries(PDO $pdo, array $filters = [], int $limit = 50, int $offset = 0): array
{
    [$where, $params] = _bhc_enquiry_filters($filters);

    $sql = "SELECT e.*,
                   s.name_en AS service_name_en, s.name_fr AS service_name_fr,
                   c.name_en AS category_name_en,
                   p.business_name AS provider_name
            FROM bhc_enquiries e
            LEFT JOIN bhc_services s              ON s.id = e.service_id
            LEFT JOIN bhc_service_categories c    ON c.id = e.service_category_id
            LEFT JOIN bhc_service_providers p     ON p.id = e.assigned_provider_id
            {$where}
            ORDER BY e.created_at DESC
            LIMIT :lim OFFSET :off";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bhc_count_enquiries(PDO $pdo, array $filters = []): int
{
    [$where, $params] = _bhc_enquiry_filters($filters);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bhc_enquiries e {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** @internal */
function _bhc_enquiry_filters(array $filters): array
{
    $conditions = [];
    $params     = [];

    if (!empty($filters['status'])) {
        $conditions[] = 'e.status = :status';
        $params[':status'] = $filters['status'];
    }
    if (!empty($filters['service_id'])) {
        $conditions[] = 'e.service_id = :service_id';
        $params[':service_id'] = (int)$filters['service_id'];
    }
    if (!empty($filters['service_category_id'])) {
        $conditions[] = 'e.service_category_id = :cat_id';
        $params[':cat_id'] = (int)$filters['service_category_id'];
    }
    if (!empty($filters['assigned_provider_id'])) {
        $conditions[] = 'e.assigned_provider_id = :prov_id';
        $params[':prov_id'] = (int)$filters['assigned_provider_id'];
    }
    if (!empty($filters['gp_id'])) {
        $conditions[] = 'e.gp_id = :gp_id';
        $params[':gp_id'] = (int)$filters['gp_id'];
    }
    if (!empty($filters['unassigned'])) {
        $conditions[] = 'e.assigned_provider_id IS NULL';
    }
    if (!empty($filters['date_from'])) {
        $conditions[] = 'DATE(e.created_at) >= :df';
        $params[':df'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $conditions[] = 'DATE(e.created_at) <= :dt';
        $params[':dt'] = $filters['date_to'];
    }
    if (!empty($filters['search'])) {
        $conditions[] = '(e.ref LIKE :s OR e.business_name LIKE :s OR e.contact_name LIKE :s OR e.email LIKE :s)';
        $params[':s'] = '%' . $filters['search'] . '%';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}

// ── Status update ──────────────────────────────────────────────────────────

function bhc_update_enquiry_status(PDO $pdo, int $id, string $status, int $staffId): bool
{
    $valid = ['new','awaiting_assignment','assigned','contacted',
              'quotation_provided','in_progress','converted','not_converted','closed'];
    if (!in_array($status, $valid)) return false;

    $stmt = $pdo->prepare(
        "UPDATE bhc_enquiries SET status = :s, updated_at = NOW()
         WHERE id = :id"
    );
    $ok = $stmt->execute([':s' => $status, ':id' => $id]);

    if ($ok) {
        bhc_add_enquiry_note($pdo, $id, $staffId, "Status changed to: {$status}");
    }
    return $ok;
}

// ── Provider assignment ────────────────────────────────────────────────────

/**
 * Assign an enquiry to a specific provider (admin-triggered).
 * Updates status to 'assigned' and sends the provider notification email.
 */
function bhc_assign_enquiry(PDO $pdo, int $id, int $providerId, int $staffId): bool
{
    $stmt = $pdo->prepare(
        "UPDATE bhc_enquiries
         SET assigned_provider_id = :pid,
             assigned_by          = :sid,
             assigned_at          = NOW(),
             status               = 'assigned',
             updated_at           = NOW()
         WHERE id = :id"
    );
    $ok = $stmt->execute([':pid' => $providerId, ':sid' => $staffId, ':id' => $id]);

    if ($ok) {
        bhc_add_enquiry_note($pdo, $id, $staffId, "Assigned to provider ID {$providerId}");
        bhc_send_enquiry_notifications($pdo, $id, 'assignment');
    }
    return $ok;
}

// ── Notes ──────────────────────────────────────────────────────────────────

function bhc_add_enquiry_note(PDO $pdo, int $enquiryId, int $staffId, string $note): int|false
{
    $stmt = $pdo->prepare(
        "INSERT INTO bhc_enquiry_notes (enquiry_id, staff_id, note)
         VALUES (:eid, :sid, :note)"
    );
    $ok = $stmt->execute([':eid' => $enquiryId, ':sid' => $staffId, ':note' => trim($note)]);
    return $ok ? (int)$pdo->lastInsertId() : false;
}

function bhc_get_enquiry_notes(PDO $pdo, int $enquiryId): array
{
    $stmt = $pdo->prepare(
        "SELECT n.*, u.name AS staff_name
         FROM bhc_enquiry_notes n
         LEFT JOIN users u ON u.id = n.staff_id
         WHERE n.enquiry_id = :eid
         ORDER BY n.created_at ASC"
    );
    $stmt->execute([':eid' => $enquiryId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Auto-routing ───────────────────────────────────────────────────────────

/**
 * Apply automatic routing rules immediately after a new enquiry is submitted.
 *
 * Rules (in order):
 * 1. If the enquiry's service has exactly one active provider with
 *    enquiry_routing='auto' → auto-assign and send emails.
 * 2. If multiple auto providers → set status 'awaiting_assignment' (admin picks).
 * 3. If no auto providers → set status 'awaiting_assignment'.
 *
 * Providers with enquiry_routing='admin_only' are never auto-assigned.
 * Providers with receives_enquiries=0 are excluded entirely.
 */
function bhc_route_enquiry(PDO $pdo, int $enquiryId): void
{
    $enquiry = bhc_get_enquiry($pdo, $enquiryId);
    if (!$enquiry) return;

    // Find active, eligible providers for this service
    $stmt = $pdo->prepare(
        "SELECT p.id, p.enquiry_routing
         FROM bhc_service_providers p
         JOIN bhc_provider_services ps ON ps.provider_id = p.id
         WHERE ps.service_id = :sid
           AND ps.is_active  = 1
           AND p.is_active   = 1
           AND p.receives_enquiries = 1
           AND p.enquiry_routing IN ('auto', 'manual')
         ORDER BY p.is_preferred DESC, p.id ASC"
    );
    $stmt->execute([':sid' => $enquiry['service_id']]);
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $autoProviders = array_filter($providers, fn($p) => $p['enquiry_routing'] === 'auto');
    $autoProviders = array_values($autoProviders);

    if (count($autoProviders) === 1) {
        // Single auto provider — assign directly
        $pdo->prepare(
            "UPDATE bhc_enquiries
             SET assigned_provider_id = :pid,
                 assigned_at          = NOW(),
                 status               = 'assigned',
                 updated_at           = NOW()
             WHERE id = :id"
        )->execute([':pid' => $autoProviders[0]['id'], ':id' => $enquiryId]);

        bhc_send_enquiry_notifications($pdo, $enquiryId, 'new_assigned');
    } else {
        // Multiple options or no auto provider — needs admin decision
        $pdo->prepare(
            "UPDATE bhc_enquiries
             SET status = 'awaiting_assignment', updated_at = NOW()
             WHERE id = :id"
        )->execute([':id' => $enquiryId]);

        bhc_send_enquiry_notifications($pdo, $enquiryId, 'new_unassigned');
    }
}

// ── Email notifications ────────────────────────────────────────────────────

/**
 * Send all relevant emails for an enquiry lifecycle event.
 *
 * $event values: 'new_assigned' | 'new_unassigned' | 'assignment'
 *
 * new_assigned:
 *   - confirmation → business owner
 *   - notification → admin
 *   - notification → provider
 *
 * new_unassigned:
 *   - confirmation → business owner
 *   - notification → admin
 *
 * assignment:
 *   - notification → provider (triggered when admin manually assigns)
 */
function bhc_send_enquiry_notifications(PDO $pdo, int $enquiryId, string $event): void
{
    $e = bhc_get_enquiry($pdo, $enquiryId);
    if (!$e) return;

    $lang        = $e['lang'] ?? 'en';
    $serviceName = $lang === 'fr' ? ($e['service_name_fr'] ?? $e['service_name_en'])
                                  : ($e['service_name_en'] ?? '');
    $ref         = $e['ref'];
    $biz         = htmlspecialchars($e['business_name']);
    $contact     = htmlspecialchars($e['contact_name']);

    // ── 1. Business owner confirmation ────────────────────────────────────
    if (in_array($event, ['new_assigned', 'new_unassigned'])) {
        $subject = $lang === 'fr'
            ? "Votre demande de service BHC — {$ref}"
            : "Your BHC Service Enquiry — {$ref}";

        $body = $lang === 'fr'
            ? _bhc_email_confirmation_fr($e, $serviceName)
            : _bhc_email_confirmation_en($e, $serviceName);

        $ok = bhc_send_email($e['email'], $contact, $subject, $body);
        bhc_record_notification($pdo, $enquiryId, $e['email'],
            'enquiry_confirmation_business', $ok, null);
    }

    // ── 2. Admin notification ──────────────────────────────────────────────
    if (in_array($event, ['new_assigned', 'new_unassigned'])) {
        $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : null;
        if ($adminEmail) {
            $assignedNote = ($event === 'new_assigned')
                ? "Auto-routed to: " . htmlspecialchars($e['provider_name'] ?? 'Provider #' . $e['assigned_provider_id'])
                : "⚠️ Awaiting manual assignment.";

            $adminSubject = "New BHC Enquiry [{$ref}] — {$biz}";
            $adminBody    = _bhc_email_admin_notification($e, $serviceName, $assignedNote);

            $ok = bhc_send_email($adminEmail, 'BHC Admin', $adminSubject, $adminBody);
            bhc_record_notification($pdo, $enquiryId, $adminEmail,
                'enquiry_notification_admin', $ok, null);
        }
    }

    // ── 3. Provider notification ───────────────────────────────────────────
    if (in_array($event, ['new_assigned', 'assignment']) && $e['assigned_provider_id']) {
        // Only send if consent_sharing is set
        if (!$e['consent_sharing']) return;

        $provider = bhc_get_provider_for_notification($pdo, (int)$e['assigned_provider_id']);
        if ($provider && $provider['email']) {
            $provSubject = "New enquiry assigned to you — {$ref}";
            $provBody    = _bhc_email_provider_notification($e, $serviceName, $provider);
            $ok = bhc_send_email($provider['email'], $provider['business_name'], $provSubject, $provBody);
            bhc_record_notification($pdo, $enquiryId, $provider['email'],
                'enquiry_notification_provider', $ok, null);
        }
    }
}

/** @internal — fetch provider email/name for notifications */
function bhc_get_provider_for_notification(PDO $pdo, int $providerId): ?array
{
    $stmt = $pdo->prepare('SELECT id, business_name, email FROM bhc_service_providers WHERE id = :id');
    $stmt->execute([':id' => $providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @internal — wrapper around config.php sendMail() */
function bhc_send_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    if (!function_exists('sendMail')) return false;
    try {
        return (bool)sendMail($toEmail, $toName, $subject, $htmlBody);
    } catch (\Throwable $e) {
        return false;
    }
}

function bhc_record_notification(
    PDO $pdo, int $enquiryId, string $recipient,
    string $type, bool $ok, ?string $err
): void {
    $pdo->prepare(
        "INSERT INTO bhc_enquiry_notifications
           (enquiry_id, recipient, type, status, sent_at, error_msg)
         VALUES (:eid, :rec, :type, :status, :sent_at, :err)"
    )->execute([
        ':eid'     => $enquiryId,
        ':rec'     => $recipient,
        ':type'    => $type,
        ':status'  => $ok ? 'sent' : 'failed',
        ':sent_at' => $ok ? date('Y-m-d H:i:s') : null,
        ':err'     => $err,
    ]);
}

// ── Email templates ────────────────────────────────────────────────────────

function _bhc_email_confirmation_en(array $e, string $serviceName): string
{
    $ref     = htmlspecialchars($e['ref']);
    $biz     = htmlspecialchars($e['business_name']);
    $contact = htmlspecialchars($e['contact_name']);
    $svc     = htmlspecialchars($serviceName);

    return <<<HTML
    <p>Dear {$contact},</p>
    <p>Thank you for submitting an enquiry through the Business Health Check platform.</p>
    <p><strong>Reference:</strong> {$ref}<br>
       <strong>Business:</strong> {$biz}<br>
       <strong>Service requested:</strong> {$svc}</p>
    <p>A specialist will be in touch with you shortly. Please keep your reference number safe.</p>
    <p>Best regards,<br>The 237 Business Network Team</p>
    HTML;
}

function _bhc_email_confirmation_fr(array $e, string $serviceName): string
{
    $ref     = htmlspecialchars($e['ref']);
    $biz     = htmlspecialchars($e['business_name']);
    $contact = htmlspecialchars($e['contact_name']);
    $svc     = htmlspecialchars($serviceName);

    return <<<HTML
    <p>Bonjour {$contact},</p>
    <p>Merci pour votre demande via la plateforme de Bilan de Santé d'Entreprise.</p>
    <p><strong>Référence :</strong> {$ref}<br>
       <strong>Entreprise :</strong> {$biz}<br>
       <strong>Service demandé :</strong> {$svc}</p>
    <p>Un spécialiste vous contactera prochainement. Conservez précieusement votre numéro de référence.</p>
    <p>Cordialement,<br>L'équipe 237 Business Network</p>
    HTML;
}

function _bhc_email_admin_notification(array $e, string $serviceName, string $assignedNote): string
{
    $ref     = htmlspecialchars($e['ref']);
    $biz     = htmlspecialchars($e['business_name']);
    $contact = htmlspecialchars($e['contact_name']);
    $email   = htmlspecialchars($e['email']);
    $phone   = htmlspecialchars($e['phone'] ?? '—');
    $svc     = htmlspecialchars($serviceName);
    $note    = htmlspecialchars($assignedNote);
    $req     = nl2br(htmlspecialchars($e['requirements'] ?? '—'));

    return <<<HTML
    <h2>New BHC Service Enquiry</h2>
    <table cellpadding="4" cellspacing="0">
      <tr><th align="left">Reference</th><td>{$ref}</td></tr>
      <tr><th align="left">Business</th><td>{$biz}</td></tr>
      <tr><th align="left">Contact</th><td>{$contact}</td></tr>
      <tr><th align="left">Email</th><td>{$email}</td></tr>
      <tr><th align="left">Phone</th><td>{$phone}</td></tr>
      <tr><th align="left">Service</th><td>{$svc}</td></tr>
      <tr><th align="left">Requirements</th><td>{$req}</td></tr>
      <tr><th align="left">Routing</th><td>{$note}</td></tr>
    </table>
    HTML;
}

function _bhc_email_provider_notification(array $e, string $serviceName, array $provider): string
{
    $ref     = htmlspecialchars($e['ref']);
    $biz     = htmlspecialchars($e['business_name']);
    $contact = htmlspecialchars($e['contact_name']);
    $email   = htmlspecialchars($e['email']);
    $phone   = htmlspecialchars($e['phone'] ?? '—');
    $pref    = htmlspecialchars(ucfirst($e['preferred_contact'] ?? 'email'));
    $svc     = htmlspecialchars($serviceName);
    $req     = nl2br(htmlspecialchars($e['requirements'] ?? '—'));
    $prov    = htmlspecialchars($provider['business_name']);

    return <<<HTML
    <p>Dear {$prov},</p>
    <p>A new service enquiry has been assigned to you through the 237 Business Network BHC platform.</p>
    <h3>Enquiry Details</h3>
    <table cellpadding="4" cellspacing="0">
      <tr><th align="left">Reference</th><td>{$ref}</td></tr>
      <tr><th align="left">Business</th><td>{$biz}</td></tr>
      <tr><th align="left">Contact</th><td>{$contact}</td></tr>
      <tr><th align="left">Email</th><td>{$email}</td></tr>
      <tr><th align="left">Phone</th><td>{$phone}</td></tr>
      <tr><th align="left">Preferred contact</th><td>{$pref}</td></tr>
      <tr><th align="left">Service</th><td>{$svc}</td></tr>
      <tr><th align="left">Requirements</th><td>{$req}</td></tr>
    </table>
    <p>Please contact the business owner within 2 working days and update the enquiry status via the admin panel.</p>
    <p>Best regards,<br>The 237 Business Network Team</p>
    HTML;
}
