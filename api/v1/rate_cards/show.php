<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/show.php
 *
 * Return full detail for a single rate card including all its items.
 *
 * SOFT_DELETE: rate_cards has deleted_at — filter applied.
 * rate_card_items has no deleted_at (cascade delete via FK).
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 { rate_card: {..., items: [{...}] } }
 *          404 NOT_FOUND
 *
 * Decisions: D5 (soft delete), D7 (routing)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

// -----------------------------------------------------------------------
// 1. Resolve rate card
// -----------------------------------------------------------------------
$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$card = db_row(
    "SELECT
         rc.id, rc.name, rc.description, rc.is_default,
         rc.effective_from, rc.effective_to,
         rc.created_by, rc.created_at, rc.updated_at,
         u.name AS created_by_name
     FROM rate_cards rc
     LEFT JOIN users u ON u.id = rc.created_by AND u.deleted_at IS NULL
     WHERE rc.id = ? AND rc.deleted_at IS NULL",
    [$id]
);
if (!$card) {
    json_error('NOT_FOUND', 'Rate card not found.', 404);
}

// -----------------------------------------------------------------------
// 2. Load items
// -----------------------------------------------------------------------
$items = db_select(
    "SELECT id, rate_card_id, equipment_type,
            daily_rate, weekly_rate, monthly_rate,
            mileage_rate, mileage_unit, currency, notes,
            created_at, updated_at
     FROM rate_card_items
     WHERE rate_card_id = ?
     ORDER BY equipment_type ASC",
    [$id]
);

// -----------------------------------------------------------------------
// 3. Compute is_active flag
// -----------------------------------------------------------------------
$today = date('Y-m-d');
$card['is_active'] = (
    $card['effective_from'] <= $today &&
    ($card['effective_to'] === null || $card['effective_to'] >= $today)
) ? 1 : 0;

$card['items'] = $items;

json_success($card);
