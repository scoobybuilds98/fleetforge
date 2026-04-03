<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/show.php
 *
 * Return full detail for one maintenance work order.
 *
 * Includes:
 *   - All WO fields
 *   - equipment_unit (unit_number, year, brand, model) via JOIN
 *   - vendor (name, contact_name, phone) via LEFT JOIN
 *   - created_by user name, assigned_to user name, completed_by user name
 *   - line_items[] array from maintenance_line_items
 *
 * SOFT_DELETE: mwo.deleted_at IS NULL.
 *
 * @method  GET
 * @param   id (integer, required)
 * @auth    Session required; require_permission('maintenance','view')
 * @returns 200 { work_order, line_items[] } | 404 NOT_FOUND
 *
 * Decisions: D5 (soft delete), D7 (routing)
 * Session: S015
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// -----------------------------------------------------------------------
// 1. Load work order with JOINs
// -----------------------------------------------------------------------
$wo = db_row(
    "SELECT
         mwo.*,
         eu.unit_number, eu.year AS unit_year, eu.status AS unit_status,
         et.brand, et.model, et.category AS unit_category,
         v.name AS vendor_name, v.contact_name AS vendor_contact, v.phone AS vendor_phone,
         uc.name AS created_by_name,
         ua.name AS assigned_to_name,
         ucb.name AS completed_by_name
     FROM maintenance_work_orders mwo
     JOIN equipment_units eu ON eu.id = mwo.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     LEFT JOIN vendors v ON v.id = mwo.vendor_id AND v.deleted_at IS NULL
     LEFT JOIN users uc ON uc.id = mwo.created_by AND uc.deleted_at IS NULL
     LEFT JOIN users ua ON ua.id = mwo.assigned_to AND ua.deleted_at IS NULL
     LEFT JOIN users ucb ON ucb.id = mwo.completed_by AND ucb.deleted_at IS NULL
     WHERE mwo.id = ? AND mwo.deleted_at IS NULL",
    [$id]
);

if (!$wo) {
    json_error('NOT_FOUND', 'Work order not found.', 404);
}

// -----------------------------------------------------------------------
// 2. Load line items
// -----------------------------------------------------------------------
$lineItems = db_select(
    "SELECT id, item_type, description, quantity, unit_cost, total_cost, part_number, created_at
     FROM maintenance_line_items
     WHERE work_order_id = ?
     ORDER BY id ASC",
    [$id]
);

$wo['line_items'] = $lineItems;

json_success($wo);
