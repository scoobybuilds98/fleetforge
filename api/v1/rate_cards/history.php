<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/history.php
 *
 * S-RATES-MODULE — a rate card's price history:
 *   events    its change log (who, when, what) with per-line price diffs
 *             wherever the audit row carries line snapshots (every save since
 *             S-RATES-MODULE; older rows show header changes only)
 *   timeline  each of its lines across every card of the same customer (or
 *             every general card), oldest first — the price over time
 *
 * Purpose-built instead of the generic Activity card (api/v1/audit/history)
 * so a price change reads as "Dry Van daily $45.00 → $50.00" rather than a
 * JSON diff. Gated on rates:view — the same people who see the prices on the
 * card page itself.
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 { events: [...], timeline: [...] } · 404 NOT_FOUND
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\RateCards\RateInsights;

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id || !db_exists('rate_cards', 'id = ? AND deleted_at IS NULL', [$id])) {
    json_error('NOT_FOUND', 'Rate card not found.', 404);
}

json_success(RateInsights::history($id, ff_today()));
