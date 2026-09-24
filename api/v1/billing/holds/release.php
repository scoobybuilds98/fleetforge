<?php
declare(strict_types=1);

/**
 * api/v1/billing/holds/release.php
 *
 * S-BILLING-MODULE — release a billing hold. The lease(s) bill again from
 * the next generation; the monthly job catches up any held months itself.
 *
 * @method  POST
 * @body    { id, note? }
 * @auth    Session required; invoices:edit
 * @returns 200 { released: true }   409 when already released
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\Cycle\BillingHolds;

$body = json_body();
$id = require_id($body['id'] ?? null);
$note = (string) (clean_string($body['note'] ?? null, 500) ?? '');

if (!BillingHolds::release($id, $note, current_user_id())) {
    json_error('INVALID_TRANSITION', 'That hold is not active (already released, or not found).', 409);
}
json_success(['released' => true]);
