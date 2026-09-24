<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/customer_prices.php
 *
 * S-RATES-MODULE — what ONE customer pays for every equipment type today:
 * the price a new lease would get (and whether it is their own card's, a
 * general card's or the equipment type's default), the standard price for
 * comparison, and the prices on their active leases. Also their cards.
 *
 * Used by the customer profile's Rates tab and the New rate card page (which
 * starts a customer's card from these prices, or from their lease prices).
 *
 * @method  GET
 * @query   customer_id (required), all_types? (1 = include inactive types)
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 { customer, prices: [...], cards: [...], own_prices, on_rent }
 *          404 NOT_FOUND
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\RateCards\RateInsights;

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
$customer   = $customerId
    ? db_row("SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$customerId])
    : null;
if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

json_success(
    ['customer' => ['id' => (int) $customer['id'], 'name' => $customer['company_name']]]
    + RateInsights::customerPrices((int) $customer['id'], ff_today(), empty($_GET['all_types']))
);
