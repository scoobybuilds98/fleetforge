<?php
declare(strict_types=1);

/**
 * api/v1/billing/charges/index.php
 *
 * S-BILLING-MODULE-2 — charges queued for leases' invoices (one-off or
 * monthly), with their billing state derived from live invoice lines:
 * pending / billed (with the invoice) / recurring / ended / cancelled.
 * See lib/Billing/Cycle/BillingCharges.php.
 *
 * @method  GET
 * @query   state = all | pending | billed | cancelled, month (YYYY-MM)?, lease_id?, customer_id?
 * @auth    Session required; invoices:view (amounts need can_view_financials)
 * @returns 200 { charges: [...], item_types: {key: label} }
 * @session S-BILLING-MODULE-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingCharges;

$state = (string) ($_GET['state'] ?? 'all');
if (!in_array($state, ['all', 'pending', 'billed', 'cancelled'], true)) {
    $state = 'all';
}
$rows = BillingCharges::list([
    'state'       => $state,
    'month'       => (string) ($_GET['month'] ?? ''),
    'lease_id'    => clean_int($_GET['lease_id'] ?? null),
    'customer_id' => clean_int($_GET['customer_id'] ?? null),
]);
if (!can_view_financials()) {
    $rows = array_map(static fn($r) => array_merge($r, ['unit_price' => null, 'amount' => null]), $rows);
}
json_success(['charges' => $rows, 'item_types' => BillingCharges::ITEM_TYPES]);
