<?php
declare(strict_types=1);

/**
 * FleetForge — Yard Delete (Deactivate) API
 *
 * @file        api/v1/yards/delete.php
 * @description Deactivates a yard (sets is_active = 0).
 *              Yards have no deleted_at column — deactivation is the removal mechanism.
 *              A deactivated yard is hidden from all dropdowns but its historical
 *              data (reservation yard_location snapshots) is preserved.
 *
 *              Guard: cannot deactivate a yard that has upcoming confirmed
 *              reservations (pickup_date >= today, status pending/confirmed).
 *
 * @method      POST
 * @body        JSON — id (required)
 * @auth        Session required; super_admin or manager role
 * @returns     200 { id } | 422 HAS_ACTIVE_RESERVATIONS | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @session     S018-EXT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
if (!in_array(current_user()['role_slug'] ?? '', ['super_admin', 'manager'])) {
    json_error('FORBIDDEN', 'Insufficient permissions to deactivate yards.', 403);
}

$body = json_body();
$id   = clean_int($body['id'] ?? null);
if (!$id) json_error('MISSING_REQUIRED', 'id is required.', 422);

$yard = db_row("SELECT id, name, is_active FROM yards WHERE id = ?", [$id]);
if (!$yard) json_error('NOT_FOUND', 'Yard not found.', 404);

if (!$yard['is_active']) {
    json_error('VALIDATION_ERROR', "Yard '{$yard['name']}' is already inactive.", 422);
}

// Guard: upcoming reservations at this yard
$today = date('Y-m-d');
$upcomingCount = db_count(
    "SELECT COUNT(*) FROM reservations
     WHERE yard_location = ?
       AND pickup_date >= ?
       AND status IN ('pending','confirmed')
       AND deleted_at IS NULL",
    [$yard['name'], $today]
);
if ($upcomingCount > 0) {
    json_error('VALIDATION_ERROR',
        "Cannot deactivate '{$yard['name']}' — it has {$upcomingCount} upcoming reservation(s). " .
        "Cancel or move those reservations first.",
        422
    );
}

db_execute("UPDATE yards SET is_active = 0 WHERE id = ?", [$id]);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'delete',
    'module'       => 'settings',
    'entity_type'  => 'yard',
    'entity_id'    => $id,
    'entity_label' => $yard['name'],
    'notes'        => "Yard '{$yard['name']}' deactivated",
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['id' => $id]);
