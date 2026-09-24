<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/overview.php
 *
 * S-RATES-MODULE — the Rates home's headline numbers, its "needs a look"
 * list, and the equipment options its filters offer.
 *
 * kpis:      customers with their own prices, in-force customer / general
 *            cards, cards ending within 30 days, upcoming, equipment types
 *            with a standard price
 * attention: every card problem a person should act on, most urgent first —
 *            lines with no prices or no weekly price (they bill $0), empty
 *            cards, prices ending soon, customer prices that ended without a
 *            renewal while the customer still rents, customers on rent with no
 *            card, archived customers' cards, new prices starting soon
 * equipment: [{value:'c:dry_van'|'t:14', label, group}] for the filters
 *
 * Read-only; everything comes from lib/RateCards/RateInsights.
 *
 * @method  GET
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 { kpis, attention: {items, counts}, equipment, today }
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\RateCards\RateCardItems;
use FleetForge\RateCards\RateInsights;

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

$today = ff_today();

// Filter options: every category slug a line can carry + every equipment type.
$equipment = [];
$slugs = array_unique(array_merge(
    array_column(RateInsights::templates(), 'category'),
    array_column(db_select("SELECT DISTINCT equipment_type FROM rate_card_items"), 'equipment_type')
));
sort($slugs);
foreach ($slugs as $slug) {
    $equipment[] = ['value' => 'c:' . $slug, 'label' => RateCardItems::label((string) $slug), 'group' => 'Category'];
}
foreach (RateInsights::templates() as $id => $t) {
    $equipment[] = ['value' => 't:' . $id, 'label' => $t['name'], 'group' => 'Equipment type'];
}

json_success([
    'today'     => $today,
    'kpis'      => RateInsights::kpis($today),
    'attention' => RateInsights::attention($today),
    'equipment' => $equipment,
]);
