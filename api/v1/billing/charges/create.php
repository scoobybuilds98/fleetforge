<?php
declare(strict_types=1);

/**
 * api/v1/billing/charges/create.php
 *
 * S-BILLING-MODULE-2 — queue a charge on a lease: billed on its next rental
 * invoice (once) or on one invoice every month (monthly), by every
 * generation path. Positive amounts only — credits go on a credit note.
 *
 * @method  POST
 * @body    { lease_id, description, item_type, quantity?, unit_price, taxable?,
 *            recurrence: once|monthly, bill_from?, bill_until?, notes? }
 * @auth    Session required; invoices:create + can_view_financials() (it is an amount)
 * @returns 201 { id }
 * @session S-BILLING-MODULE-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');
if (!can_view_financials()) {
    json_error('FORBIDDEN', 'Adding a charge sets an amount, which your role cannot see.', 403);
}

use FleetForge\Billing\Cycle\BillingCharges;

try {
    $id = BillingCharges::create(json_body(), current_user_id());
} catch (\InvalidArgumentException $e) {
    $f = json_decode($e->getMessage(), true);
    json_validation_error(is_array($f) ? $f : ['description' => $e->getMessage()]);
}
json_success(['id' => $id], 201);
