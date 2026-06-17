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
         rc.customer_id, c.company_name AS customer_name,
         rc.created_by, rc.created_at, rc.updated_at,
         u.name AS created_by_name
     FROM rate_cards rc
     LEFT JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users u ON u.id = rc.created_by AND u.deleted_at IS NULL
     WHERE rc.id = ? AND rc.deleted_at IS NULL",
    [$id]
);
if (!$card) {
    json_error('NOT_FOUND', 'Rate card not found.', 404);
}

// -----------------------------------------------------------------------
// 2. Load items — S-RATE-CARD-TEMPLATE-ITEM: join template name
// -----------------------------------------------------------------------
$items = db_select(
    "SELECT rci.id, rci.rate_card_id, rci.equipment_type,
            rci.equipment_template_id, et.name AS equipment_template_name,
            rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
            rci.mileage_rate, rci.mileage_unit, rci.hourly_rate, rci.gps_price,
            rci.currency, rci.notes,
            rci.created_at, rci.updated_at
     FROM rate_card_items rci
     LEFT JOIN equipment_templates et ON et.id = rci.equipment_template_id AND et.deleted_at IS NULL
     WHERE rci.rate_card_id = ?
     ORDER BY rci.equipment_type ASC, ISNULL(rci.equipment_template_id) DESC, et.name ASC",
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
