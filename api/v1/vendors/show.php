<?php
declare(strict_types=1);

/**
 * api/v1/vendors/show.php
 *
 * Single vendor detail.
 * Includes work_order_count and recent work order summary.
 *
 * SOFT_DELETE: vendors has deleted_at — 404 if deleted_at IS NOT NULL.
 *
 * @method  GET
 * @param   id (int, query string) — vendor ID
 * @auth    Session required; require_permission('maintenance','view')
 * @returns 200 { vendor fields, work_order_count, recent_work_orders[] }
 *          404 NOT_FOUND if vendor does not exist or is soft-deleted
 *
 * Decisions: D5 (soft delete), D7 (routing)
 * Session: S014
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

// -----------------------------------------------------------------------
// 1. Resolve vendor ID
// -----------------------------------------------------------------------
$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// -----------------------------------------------------------------------
// 2. Fetch vendor (soft-delete safe)
// -----------------------------------------------------------------------
$vendor = db_row(
    "SELECT
         v.id, v.name, v.vendor_type, v.contact_name, v.email, v.phone,
         v.address, v.city, v.state, v.specializations, v.hourly_rate,
         v.rating, v.notes, v.is_preferred, v.total_spent,
         v.created_by, v.created_at, v.updated_at,
         u.name AS created_by_name
     FROM vendors v
     LEFT JOIN users u ON u.id = v.created_by AND u.deleted_at IS NULL
     WHERE v.id = ? AND v.deleted_at IS NULL",
    [$id]
);

if (!$vendor) {
    json_error('NOT_FOUND', 'Vendor not found.', 404);
}

// Decode JSON specializations
$vendor['specializations'] = $vendor['specializations']
    ? json_decode($vendor['specializations'], true)
    : [];

// -----------------------------------------------------------------------
// 3. Work order summary
// -----------------------------------------------------------------------
$vendor['work_order_count'] = db_count(
    "SELECT COUNT(*) FROM maintenance_work_orders
     WHERE vendor_id = ? AND deleted_at IS NULL",
    [$id]
);

// Last 5 work orders for the detail panel (most recent first)
$vendor['recent_work_orders'] = db_select(
    "SELECT
         mwo.id, mwo.work_order_number, mwo.work_type, mwo.status,
         mwo.title, mwo.total_cost, mwo.scheduled_date, mwo.completed_date,
         eu.unit_number
     FROM maintenance_work_orders mwo
     LEFT JOIN equipment_units eu ON eu.id = mwo.equipment_unit_id AND eu.deleted_at IS NULL
     WHERE mwo.vendor_id = ? AND mwo.deleted_at IS NULL
     ORDER BY mwo.created_at DESC
     LIMIT 5",
    [$id]
);

json_success($vendor);
