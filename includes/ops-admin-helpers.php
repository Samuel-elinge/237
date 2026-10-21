<?php
/**
 * Operations Admin — helpers
 * Included by any page that needs ops-admin auth or permission checks.
 * Requires config.php (and its db(), currentUser(), isAdmin() helpers) to be loaded first.
 */

// ── Role checks ───────────────────────────────────────────────────────────────

/** True when the current user is the Super Admin (full admin). */
function isSuperAdmin(): bool {
    $u = currentUser();
    return $u && $u['role'] === 'admin';
}

/** True when the current user is an Operations Admin. */
function isOpsAdmin(): bool {
    $u = currentUser();
    return $u && $u['role'] === 'operations_admin';
}

/** True when the current user has any admin-level access (super OR ops). */
function isAnyAdmin(): bool {
    return isSuperAdmin() || isOpsAdmin();
}

/**
 * Redirect non-admin users away.
 * $level = 'super' → only Super Admin allowed
 * $level = 'any'   → Super Admin or Ops Admin allowed (default)
 */
function requireAnyAdmin(string $level = 'any'): void {
    requireLogin();
    if ($level === 'super' && !isSuperAdmin()) {
        flash('error', 'This action requires Super Admin access.');
        redirect(SITE_URL . '/admin/ops-dashboard.php');
    }
    if ($level === 'any' && !isAnyAdmin()) {
        flash('error', 'Admin access required.');
        redirect(SITE_URL . '/login');
    }
}

// ── Permission matrix ─────────────────────────────────────────────────────────
/**
 * Returns true if the current ops-admin (or super-admin) may perform $action.
 *
 * Super Admin can always do everything.
 * Ops Admin is granted only the listed permitted actions.
 */
function canOpsAdmin(string $action): bool {
    if (isSuperAdmin()) return true;
    if (!isOpsAdmin())  return false;

    static $permitted = [
        // Users
        'user.view'            => true,
        'user.search'          => true,
        'user.edit_basic'      => true,   // name, phone, verified flag
        'user.suspend'         => true,   // set locked_until
        'user.unsuspend'       => true,
        'user.reset_password'  => true,   // trigger reset email
        'user.view_activity'   => true,

        // Listings
        'listing.view'         => true,
        'listing.edit'         => true,   // profile fields
        'listing.approve'      => true,
        'listing.reject'       => true,
        'listing.suspend'      => true,

        // Enquiries / bookings / events
        'enquiry.view'         => true,
        'enquiry.manage'       => true,
        'booking.view'         => true,
        'booking.manage'       => true,
        'event.view'           => true,
        'event.manage'         => true,

        // Content moderation
        'review.view'          => true,
        'review.approve'       => true,
        'review.reject'        => true,
        'report.view'          => true,
        'report.resolve'       => true,
        'offer.view'           => true,
        'offer.moderate'       => true,

        // Agents / creators / partners
        'agent.view'           => true,
        'agent.manage'         => true,
        'creator.view'         => true,
        'creator.manage'       => true,
        'partner.view'         => true,
        'partner.manage'       => true,

        // Financial — read only
        'subscription.view'    => true,
        'invoice.view'         => true,
        'commission.view'      => true,
        'referral.view'        => true,
        'payout.view'          => true,

        // Reports
        'report.analytics'     => true,
        'report.operational'   => true,

        // Support
        'support.view'         => true,
        'support.handle'       => true,

        // Restricted-action requests
        'request.submit'       => true,
        'request.view_own'     => true,

        // Audit log — own actions only
        'audit.view_own'       => true,
    ];

    return !empty($permitted[$action]);
}

/**
 * Deny access with a JSON or redirect error if $action is not permitted.
 * Call at the top of any endpoint that ops-admin can reach but super-admin gates.
 */
function requirePermission(string $action): void {
    if (!canOpsAdmin($action)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json', true, 403);
            echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
            exit;
        }
        flash('error', 'You do not have permission to perform this action.');
        redirect(SITE_URL . '/admin/ops-dashboard.php');
    }
}

// ── Audit logging ─────────────────────────────────────────────────────────────

/**
 * Write a row to ops_admin_audit_log.
 * Call after every state-changing action by an ops-admin or super-admin.
 */
function opsAuditLog(string $action, string $recordType = '', int $recordId = 0, string $detail = ''): void {
    $u = currentUser();
    if (!$u) return;
    try {
        db()->prepare("
            INSERT INTO ops_admin_audit_log (actor_id, actor_role, action, record_type, record_id, detail, ip)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $u['id'],
            $u['role'],
            $action,
            $recordType ?: null,
            $recordId ?: null,
            $detail    ?: null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // Non-fatal — never block the action because audit failed
    }
}

// ── Restricted-action requests ────────────────────────────────────────────────

/**
 * Submit a restricted-action request for Super Admin approval.
 * Returns the new request id on success, false on failure.
 */
function submitOpsRequest(string $recordType, int $recordId, string $action, string $reason): int|false {
    $u = currentUser();
    if (!$u || !isOpsAdmin()) return false;
    try {
        db()->prepare("
            INSERT INTO ops_admin_requests (requester_id, record_type, record_id, action, reason)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$u['id'], $recordType, $recordId, $action, $reason]);
        return (int)db()->lastInsertId();
    } catch (Exception $e) {
        return false;
    }
}
