<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/leases.php
 *
 * S-RATES-MODULE — the active leases a rate card prices today, and which of
 * them carry different prices than the card.
 *
 * Leases copy their prices when created and keep no rate_card_id, so this is
 * RESOLVED, not stored: each active lease's customer + equipment type runs
 * through the same resolver the lease form uses, and the lease is listed when
 * this card wins. A lease with different prices is usually one created before
 * the card's prices changed — the card never reprices it; the lease's own
 * Amend rate does.
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; rates:view AND leases:view
 * @returns 200 { leases: [...], total, differ } · 404 NOT_FOUND
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\RateCards\RateInsights;

require_method('GET');
require_auth_api();
require_permission('rates', 'view');
require_permission('leases', 'view');

$id   = clean_int($_GET['id'] ?? null);
$card = $id ? db_row("SELECT id, customer_id FROM rate_cards WHERE id = ? AND deleted_at IS NULL", [$id]) : null;
if (!$card) {
    json_error('NOT_FOUND', 'Rate card not found.', 404);
}

json_success(RateInsights::leasesOnCard(
    (int) $card['id'],
    $card['customer_id'] !== null ? (int) $card['customer_id'] : null,
    ff_today()
));
