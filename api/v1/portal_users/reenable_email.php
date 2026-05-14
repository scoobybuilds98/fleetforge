<?php
declare(strict_types=1);

/**
 * api/v1/portal_users/reenable_email.php
 *
 * S-USERS-CONSOLIDATE C3 — Mirror of api/v1/customers/reenable_email.php
 * for portal users. Clears email_disabled on a portal_user record.
 *
 * Background: S-PROD-2 added the email_disabled column to both
 * customers and portal_users when the SES bounce webhook auto-disables
 * a recipient address that hard-bounced. The admin must manually
 * re-enable after confirming the address is now deliverable (often via
 * a phone call or alternate channel to the customer). The legacy
 * settings/portal_users.php had NO re-enable surface — this endpoint
 * closes that gap.
 *
 * @method  POST
 * @body    JSON { id: int }
 * @auth    Session required; require_permission('settings','edit')
 * @returns 200 { id }
 *          422 VALIDATION_ERROR
 *          404 NOT_FOUND
 *
 * @session S-USERS-CONSOLIDATE / S-PROD-2 (origin)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_validation_error(['id' => 'Portal user ID is required.']);
}

$pu = db_row(
    "SELECT pu.id, pu.email, pu.email_disabled, pu.name, c.company_name
     FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id
     WHERE pu.id = ?",
    [$id]
);
if (!$pu) {
    json_error('NOT_FOUND', 'Portal user not found.', 404);
}

// Idempotent — if email already enabled, return success without
// touching the row or writing an audit entry.
if (!(int) $pu['email_disabled']) {
    json_success(['id' => $id]);
}

db_execute(
    "UPDATE portal_users
        SET email_disabled = 0,
            email_disabled_reason = NULL,
            email_disabled_at = NULL
      WHERE id = ?",
    [$id]
);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'update',
    'module'       => 'portal_users',
    'entity_type'  => 'portal_user',
    'entity_id'    => $id,
    'entity_label' => $pu['name'] . ' (' . $pu['company_name'] . ')',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'notes'        => 'email_disabled cleared manually for portal user ' . $pu['email'],
]);

json_success(['id' => $id]);
