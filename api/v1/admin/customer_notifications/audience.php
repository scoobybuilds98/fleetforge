<?php
declare(strict_types=1);

/**
 * api/v1/admin/customer_notifications/audience.php
 *
 * Manage per-reminder customer targeting and the global do-not-email list
 * (table customer_notification_audience).
 *
 * GET  ?reminder_key=<key|*>
 *        → { include:[{customer_id,company_name}], exclude:[...], suppressed:[...] }
 *          ('include'/'exclude' are for the given key; 'suppressed' is the global
 *           '*' list, returned alongside for convenience.)
 *
 * POST { action:'add'|'remove', reminder_key:<key|'*'>, customer_id:int, mode:'include'|'exclude' }
 *        For reminder_key '*' the mode is forced to 'exclude' (suppression list).
 *
 * @session S-CUSTOMER-NOTIFICATIONS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Notifications\CustomerReminders;

require_auth_api();

/** Validate a reminder key against the registry, allowing the global '*' key. */
$validKey = static function (string $key): bool {
    return $key === '*' || CustomerReminders::meta($key) !== null;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_permission('settings_customer_notifications', 'view');
    $key = (string) ($_GET['reminder_key'] ?? '');
    if (!$validKey($key) || $key === '') {
        json_error('VALIDATION_ERROR', 'Unknown reminder_key.', 422);
    }

    $rows = db_select(
        "SELECT a.reminder_key, a.mode, a.customer_id, c.company_name
           FROM customer_notification_audience a
           JOIN customers c ON c.id = a.customer_id AND c.deleted_at IS NULL
          WHERE a.reminder_key IN (?, '*')
          ORDER BY c.company_name ASC",
        [$key]
    );

    $include = [];
    $exclude = [];
    $suppressed = [];
    foreach ($rows as $r) {
        $entry = ['customer_id' => (int) $r['customer_id'], 'company_name' => (string) $r['company_name']];
        if ((string) $r['reminder_key'] === '*') {
            $suppressed[] = $entry;
        } elseif ((string) $r['mode'] === 'include') {
            $include[] = $entry;
        } else {
            $exclude[] = $entry;
        }
    }
    json_success(['include' => $include, 'exclude' => $exclude, 'suppressed' => $suppressed]);
}

require_method('POST');
require_permission('settings_customer_notifications', 'edit');

$body   = json_body();
$action = (string) ($body['action'] ?? '');
$key    = (string) ($body['reminder_key'] ?? '');
$cid    = (int) ($body['customer_id'] ?? 0);
$mode   = (string) ($body['mode'] ?? 'include');

if (!in_array($action, ['add', 'remove'], true)) {
    json_error('VALIDATION_ERROR', 'action must be add or remove.', 422);
}
if (!$validKey($key) || $key === '') {
    json_error('VALIDATION_ERROR', 'Unknown reminder_key.', 422);
}
if ($cid <= 0) {
    json_error('VALIDATION_ERROR', 'customer_id required.', 422);
}
// The global suppression list only holds exclusions.
if ($key === '*') {
    $mode = 'exclude';
}
if (!in_array($mode, ['include', 'exclude'], true)) {
    json_error('VALIDATION_ERROR', 'mode must be include or exclude.', 422);
}

$cust = db_row("SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$cid]);
if (!$cust) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

$userId = (int) current_user_id();

if ($action === 'add') {
    // Upsert: (reminder_key, customer_id) is unique; flipping mode is allowed.
    db_execute(
        "INSERT INTO customer_notification_audience (reminder_key, customer_id, mode, created_by, created_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE mode = VALUES(mode)",
        [$key, $cid, $mode, $userId]
    );
} else {
    db_execute(
        "DELETE FROM customer_notification_audience WHERE reminder_key = ? AND customer_id = ?",
        [$key, $cid]
    );
}

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'update',
    'module'       => 'settings',
    'entity_type'  => 'customer_notification_audience',
    'entity_id'    => $cid,
    'entity_label' => (string) $cust['company_name'],
    'notes'        => "{$action} {$mode} for reminder '{$key}'",
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success([
    'action'       => $action,
    'reminder_key' => $key,
    'customer_id'  => $cid,
    'mode'         => $mode,
    'company_name' => (string) $cust['company_name'],
]);
