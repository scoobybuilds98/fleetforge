<?php
declare(strict_types=1);

/**
 * api/v1/billing/charges/cancel.php
 *
 * S-BILLING-MODULE-2 — cancel a queued charge. Stops FUTURE billing only:
 * a charge already on an invoice stays there (a sent invoice is never
 * edited; take it off a draft with Edit Line Items, or credit a sent one).
 *
 * @method  POST
 * @body    { id, reason? }
 * @auth    Session required; invoices:edit
 * @returns 200 { cancelled: true }   409 when not active
 * @session S-BILLING-MODULE-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\Cycle\BillingCharges;

$body = json_body();
$id = require_id($body['id'] ?? null);
if (!BillingCharges::cancel($id, (string) (clean_string($body['reason'] ?? null, 500) ?? ''), current_user_id())) {
    json_error('INVALID_TRANSITION', 'That charge is not active (already cancelled, or not found).', 409);
}
json_success(['cancelled' => true]);
