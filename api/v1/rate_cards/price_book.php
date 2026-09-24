<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/price_book.php
 *
 * S-RATES-MODULE — Rates home → "Standard prices": the price every equipment
 * type carries for a customer WITHOUT their own card (a general card's line,
 * else the equipment type's default prices), grouped by category, with how
 * many customer deals cover it and how many of its units are on rent; plus
 * the general cards themselves.
 *
 * The equipment types' own default prices are returned alongside so the page
 * can edit them in place (that write goes through
 * api/v1/equipment/templates/update.php, gated on equipment:edit).
 *
 * @method  GET
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 { groups: [{slug, label, types: [...]}], general_cards: [...] }
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\RateCards\RateInsights;

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

json_success(RateInsights::priceBook(ff_today()) + ['can_edit_types' => can('equipment', 'edit')]);
