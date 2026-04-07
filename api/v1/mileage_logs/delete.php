<?php
declare(strict_types=1);

// ============================================================
// POST /api/v1/mileage_logs/delete
//
// Hard-deletes a mileage log entry.
// mileage_logs has no deleted_at column — hard delete is correct.
//
// BLOCKED for log_type = 'lease_start' or 'lease_end':
//   These entries are billing-critical (tied to invoice generation
//   at lease activation and close). Deletion would corrupt the
//   mileage reconciliation audit trail. Returns 422 IMMUTABLE_RECORD.
//
// Body:
//   id  INT  required
//
// Permission: maintenance delete
// ============================================================

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('POST');
require_auth_api();
require_permission('maintenance', 'delete');

$fields = [];
$id = clean_int($_POST['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Mileage log ID is required.';
}
if ($fields) {
    json_validation_error($fields);
}

$log = db_row(
    "SELECT id, log_type, equipment_unit_id, odometer_reading, mileage_unit, log_date
     FROM mileage_logs WHERE id = ?",
    [$id]
);

if (!$log) {
    json_error('NOT_FOUND', 'Mileage log not found.', 404);
}

// ── Block billing-critical system types
$protectedTypes = ['lease_start', 'lease_end'];
if (in_array($log['log_type'], $protectedTypes, true)) {
    json_error(
        'IMMUTABLE_RECORD',
        "Log type '{$log['log_type']}' is billing-critical and cannot be deleted. " .
        "These entries are created automatically at lease activation and close.",
        422,
        ['fields' => ['id' => "Cannot delete a {$log['log_type']} mileage log — it is tied to invoicing."]]
    );
}

// ── Hard delete + audit
db_transaction(function () use ($id, $log) {
    db_execute("DELETE FROM mileage_logs WHERE id = ?", [$id]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'maintenance',
        'entity_type'  => 'mileage_log',
        'entity_id'    => $id,
        'entity_label' => "Mileage log #{$id} — {$log['log_type']} {$log['odometer_reading']} {$log['mileage_unit']} on {$log['log_date']}",
        'old_values'   => json_encode($log),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['deleted' => true]);
