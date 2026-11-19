<?php
/**
 * BHC Commercial Terms — helper functions
 * Stage 5 of Phase 2: configurable commercial arrangements.
 *
 * All functions require a PDO connection from config.php db().
 * Guard: must be included from within the 237biz application.
 */
defined('IN_237BIZ') or die('Direct access denied');

// ─── Read ────────────────────────────────────────────────────────────────────

/**
 * Get all commercial terms for a provider (optionally filtered to one service).
 */
function bhc_get_commercial_terms(PDO $pdo, int $providerId, ?int $serviceId = null): array
{
    $sql = '
        SELECT t.*,
               s.name_en AS service_name_en, s.name_fr AS service_name_fr,
               p.business_name AS provider_name
          FROM bhc_service_commercial_terms t
          JOIN bhc_service_providers p ON p.id = t.provider_id
     LEFT JOIN bhc_services          s ON s.id = t.service_id
         WHERE t.provider_id = :pid
    ';
    $params = [':pid' => $providerId];

    if ($serviceId !== null) {
        $sql   .= ' AND (t.service_id = :sid OR t.service_id IS NULL)';
        $params[':sid'] = $serviceId;
    }
    $sql .= ' ORDER BY t.is_active DESC, t.created_at DESC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a single commercial term by ID.
 */
function bhc_get_commercial_term(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('
        SELECT t.*,
               s.name_en AS service_name_en, s.name_fr AS service_name_fr,
               p.business_name AS provider_name
          FROM bhc_service_commercial_terms t
          JOIN bhc_service_providers p ON p.id = t.provider_id
     LEFT JOIN bhc_services          s ON s.id = t.service_id
         WHERE t.id = :id
    ');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * List all active terms (admin overview), optionally filtered.
 *
 * @param array $filters  Keys: provider_id, service_id, model, active_only
 */
function bhc_list_commercial_terms(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array
{
    [$where, $params] = _bhc_commercial_filters($filters);

    $sql = '
        SELECT t.*,
               s.name_en AS service_name_en, s.name_fr AS service_name_fr,
               p.business_name AS provider_name
          FROM bhc_service_commercial_terms t
          JOIN bhc_service_providers p ON p.id = t.provider_id
     LEFT JOIN bhc_services          s ON s.id = t.service_id
    ' . $where . '
      ORDER BY p.business_name ASC, s.name_en ASC, t.model ASC
         LIMIT :lim OFFSET :off
    ';
    $params[':lim'] = $limit;
    $params[':off'] = $offset;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bhc_count_commercial_terms(PDO $pdo, array $filters = []): int
{
    [$where, $params] = _bhc_commercial_filters($filters);
    $st = $pdo->prepare('
        SELECT COUNT(*) FROM bhc_service_commercial_terms t ' . $where
    );
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/** @internal */
function _bhc_commercial_filters(array $filters): array
{
    $clauses = [];
    $params  = [];

    if (!empty($filters['provider_id'])) {
        $clauses[]              = 't.provider_id = :pid';
        $params[':pid']         = (int)$filters['provider_id'];
    }
    if (!empty($filters['service_id'])) {
        $clauses[]              = 't.service_id = :sid';
        $params[':sid']         = (int)$filters['service_id'];
    }
    if (!empty($filters['model'])) {
        $clauses[]              = 't.model = :model';
        $params[':model']       = $filters['model'];
    }
    if (!isset($filters['active_only']) || $filters['active_only']) {
        $clauses[]              = 't.is_active = 1';
    }

    $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
    return [$where, $params];
}

// ─── Write ───────────────────────────────────────────────────────────────────

/**
 * Create a new commercial term.
 *
 * @param array $data  Keys: provider_id, service_id, model, rate_pct,
 *                           flat_amount, currency, billing_cycle,
 *                           valid_from, valid_until, notes, is_active
 * @return int|false   New row ID or false on failure
 */
function bhc_create_commercial_term(PDO $pdo, array $data, int $staffId): int|false
{
    try {
        $st = $pdo->prepare('
            INSERT INTO bhc_service_commercial_terms
                   (provider_id, service_id, model, rate_pct, flat_amount,
                    currency, billing_cycle, valid_from, valid_until,
                    notes, is_active, created_by)
            VALUES (:pid, :sid, :model, :rate, :flat,
                    :cur, :cycle, :vfrom, :vuntil,
                    :notes, :active, :staff)
        ');
        $st->execute([
            ':pid'    => (int)$data['provider_id'],
            ':sid'    => isset($data['service_id']) && $data['service_id'] !== '' ? (int)$data['service_id'] : null,
            ':model'  => $data['model'],
            ':rate'   => isset($data['rate_pct'])   && $data['rate_pct']   !== '' ? $data['rate_pct']   : null,
            ':flat'   => isset($data['flat_amount']) && $data['flat_amount'] !== '' ? $data['flat_amount'] : null,
            ':cur'    => $data['currency']     ?? 'XAF',
            ':cycle'  => $data['billing_cycle'] ?? null,
            ':vfrom'  => $data['valid_from']   ?? null,
            ':vuntil' => $data['valid_until']  ?? null,
            ':notes'  => $data['notes']        ?? null,
            ':active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            ':staff'  => $staffId,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('bhc_create_commercial_term: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update an existing commercial term.
 */
function bhc_update_commercial_term(PDO $pdo, int $id, array $data): bool
{
    try {
        $st = $pdo->prepare('
            UPDATE bhc_service_commercial_terms
               SET service_id    = :sid,
                   model         = :model,
                   rate_pct      = :rate,
                   flat_amount   = :flat,
                   currency      = :cur,
                   billing_cycle = :cycle,
                   valid_from    = :vfrom,
                   valid_until   = :vuntil,
                   notes         = :notes,
                   is_active     = :active
             WHERE id = :id
        ');
        $st->execute([
            ':id'     => $id,
            ':sid'    => isset($data['service_id']) && $data['service_id'] !== '' ? (int)$data['service_id'] : null,
            ':model'  => $data['model'],
            ':rate'   => isset($data['rate_pct'])   && $data['rate_pct']   !== '' ? $data['rate_pct']   : null,
            ':flat'   => isset($data['flat_amount']) && $data['flat_amount'] !== '' ? $data['flat_amount'] : null,
            ':cur'    => $data['currency']     ?? 'XAF',
            ':cycle'  => $data['billing_cycle'] ?? null,
            ':vfrom'  => $data['valid_from']   ?? null,
            ':vuntil' => $data['valid_until']  ?? null,
            ':notes'  => $data['notes']        ?? null,
            ':active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);
        return $st->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('bhc_update_commercial_term: ' . $e->getMessage());
        return false;
    }
}

/**
 * Soft-delete (deactivate) a commercial term.
 */
function bhc_deactivate_commercial_term(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('UPDATE bhc_service_commercial_terms SET is_active = 0 WHERE id = :id');
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

/**
 * Hard-delete a commercial term (admin only).
 */
function bhc_delete_commercial_term(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('DELETE FROM bhc_service_commercial_terms WHERE id = :id');
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Human-readable label for a commercial model (EN).
 */
function bhc_commercial_model_label(string $model): string
{
    return match($model) {
        'referral'      => 'Referral Commission',
        'fee_per_lead'  => 'Fee Per Lead',
        'subscription'  => 'Subscription',
        'revenue_share' => 'Revenue Share',
        'featured'      => 'Featured Placement',
        default         => ucfirst($model),
    };
}

/**
 * Human-readable label for a billing cycle.
 */
function bhc_billing_cycle_label(?string $cycle): string
{
    if ($cycle === null) return '—';
    return match($cycle) {
        'one_off'  => 'One-off',
        'monthly'  => 'Monthly',
        'annual'   => 'Annual',
        'per_lead' => 'Per lead',
        default    => ucfirst($cycle),
    };
}

/**
 * Format the rate / amount for display.
 */
function bhc_commercial_rate_display(array $term): string
{
    if ($term['rate_pct'] !== null) {
        return $term['rate_pct'] . '%';
    }
    if ($term['flat_amount'] !== null) {
        return number_format((float)$term['flat_amount'], 0) . ' ' . $term['currency'];
    }
    return '—';
}
