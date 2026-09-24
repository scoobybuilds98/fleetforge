<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/customer_pricing.php
 *
 * S-RATES-MODULE — Rates home → "Customer prices": one row per customer with
 * their own rate card(s), carrying every line (equipment, prices, card,
 * status, and how it compares to the standard price).
 *
 * Keeps the grouped-by-customer view the operator asked to keep
 * (S-RATES-CONSOLIDATE-v2) while showing the prices themselves instead of a
 * bare card count.
 *
 * @method  GET
 * @query   q? (customer or card name), status? (in_force|ending|upcoming|expired|all,
 *          default in_force), equipment? (c:{slug}|t:{template id}),
 *          sort? (name|ending|leases), page?, per_page? (5..100, default 20)
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 json_paginated([customer rows])
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\RateCards\RateInsights;

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

$result = RateInsights::customerPricing(
    [
        'q'         => clean_string($_GET['q'] ?? null, 255),
        'status'    => clean_string($_GET['status'] ?? null, 20),
        'equipment' => clean_string($_GET['equipment'] ?? null, 60),
        'sort'      => clean_string($_GET['sort'] ?? null, 20),
    ],
    max(1, clean_int($_GET['page'] ?? 1) ?? 1),
    clean_int($_GET['per_page'] ?? 20) ?? 20,
    ff_today()
);

json_paginated($result['rows'], $result['total'], $result['page'], $result['per_page']);
