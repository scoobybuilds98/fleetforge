<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/revise.php
 *
 * S-RATES-MODULE — "Change prices from a date" for one card (the rate-card
 * page) or many (Rates home → select cards → Change prices).
 *
 * For each card: the old card ends the day before the new prices start, and a
 * new card with the same customer carries the new prices from that date — the
 * old lines raised / lowered by a percentage (rounded), or the lines the
 * caller sends in items_by_card. Old prices stay on record as history. Leases
 * already on rent are never repriced. See lib/RateCards/RateCardRevision.
 *
 * dry_run=1 returns the exact outcome (the same code, rolled back) so the
 * preview can never promise something Apply won't do. Apply is all-or-
 * nothing: when any card is refused, nothing is saved and every card's result
 * is returned with its reason.
 *
 * @method  POST
 * @body    JSON: card_ids[] (1..100, required), effective_from (Y-m-d, required),
 *               percent? (-90..500, default 0), round? ('0.01'|'1'|'5'),
 *               all_prices? (also mileage / hourly / GPS), effective_to?
 *               ('keep' default | null / 'open' | Y-m-d), items_by_card?
 *               {card_id: items[]}, names? {card_id: name}, note?, dry_run?
 * @auth    Session required; rates:create AND rates:edit
 * @returns 200 { ok, applied, results: [...] }   (ok=false → nothing saved)
 *          422 VALIDATION_ERROR (request-level fields)
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\RateCards\RateCardRevision;

require_method('POST');
require_auth_api();
require_permission('rates', 'create');
require_permission('rates', 'edit');

$body = json_body();

$ids = $body['card_ids'] ?? null;
if (!is_array($ids) || $ids === []) {
    json_validation_error(['card_ids' => 'Choose at least one rate card.']);
}
if (count($ids) > RateCardRevision::MAX_CARDS) {
    json_validation_error(['card_ids' => 'Change at most ' . RateCardRevision::MAX_CARDS . ' cards at a time.']);
}
$cardIds = [];
foreach ($ids as $raw) {
    $id = clean_int($raw);
    if (!$id) {
        json_validation_error(['card_ids' => 'Every card id must be a positive whole number.']);
    }
    $cardIds[] = $id;
}

try {
    $opts = RateCardRevision::options($body);
} catch (InvalidArgumentException $e) {
    $fields = json_decode($e->getMessage(), true) ?: ['effective_from' => 'Check the dates and percentage.'];
    json_validation_error($fields, implode(' ', $fields));
}

$user   = current_user();
$result = RateCardRevision::revise(
    $cardIds,
    $opts,
    !empty($body['dry_run']),
    current_user_id(),
    (string) ($user['name'] ?? 'system'),
    (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
);

if ($result['applied']) {
    invalidate_dashboard_cache();
}

json_success($result);
