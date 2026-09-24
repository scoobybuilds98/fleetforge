<?php
declare(strict_types=1);

/**
 * api/v1/billing/settings.php
 *
 * S-BILLING-MODULE — Billing → Settings: the cycle schedule, review
 * thresholds, close rule, default owner and the batch-approval pair (moved
 * here from Settings → General; same keys, so every reader is unchanged).
 *
 * Only the keys in BILLING_SETTING_KEYS can be written, each validated to
 * its type and range. Writes need the same permission the approval pair
 * needed on the General tab (settings_general:edit) — changing who may
 * approve billing is an administrator decision, not a biller's.
 *
 * @method  GET | POST
 * @body    POST { key: value, ... }
 * @auth    GET invoices:view; POST settings_general:edit
 * @returns 200 { settings: {key: value}, users: [{id, name}] (GET), can_edit }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET', 'POST');
require_auth_api();
require_permission('invoices', 'view');

const BILLING_SETTING_KEYS = [
    'billing_cycle.mode'                  => ['type' => 'enum', 'values' => ['arrears', 'advance'], 'default' => 'arrears'],
    'billing_cycle.open_day'              => ['type' => 'int', 'min' => 1, 'max' => 28, 'default' => '1'],
    'billing_cycle.bill_by_days'          => ['type' => 'int', 'min' => 0, 'max' => 60, 'default' => '3'],
    'billing_cycle.send_by_days'          => ['type' => 'int', 'min' => 0, 'max' => 60, 'default' => '5'],
    'billing_cycle.variance_pct'          => ['type' => 'decimal', 'min' => '0', 'max' => '1000', 'default' => '25'],
    'billing_cycle.variance_min_amount'   => ['type' => 'decimal', 'min' => '0', 'max' => '1000000', 'default' => '100'],
    'billing_cycle.close_requires_review' => ['type' => 'bool', 'default' => '0'],
    'billing_cycle.owner_user_id'         => ['type' => 'user', 'default' => ''],
    'billing_cycle.due_date_basis'        => ['type' => 'enum', 'values' => ['send_date', 'invoice_date'], 'default' => 'send_date'],
    'invoices.approval_required'          => ['type' => 'bool', 'default' => '0'],
    'invoices.approval_allow_self'        => ['type' => 'bool', 'default' => '1'],
];

$read = static function (): array {
    $out = [];
    foreach (BILLING_SETTING_KEYS as $k => $def) {
        $out[$k] = (string) settings_get($k, $def['default']);
    }
    return $out;
};

if (strtoupper($_SERVER['REQUEST_METHOD']) === 'GET') {
    json_success([
        'settings' => $read(),
        'users'    => db_select(
            "SELECT u.id, u.name FROM users u WHERE u.deleted_at IS NULL AND u.status = 'active' ORDER BY u.name",
            []
        ),
        'can_edit' => can('settings_general', 'edit'),
    ]);
}

if (!can('settings_general', 'edit')) {
    json_error('FORBIDDEN', 'Changing billing settings needs the Settings (General) edit permission.', 403);
}

$body = json_body();
$fields = [];
$write = [];
foreach ($body as $k => $v) {
    if (!isset(BILLING_SETTING_KEYS[$k])) continue;
    $def = BILLING_SETTING_KEYS[$k];
    switch ($def['type']) {
        case 'enum':
            if (!in_array((string) $v, $def['values'], true)) { $fields[$k] = 'Choose one of the options.'; break; }
            $write[$k] = (string) $v;
            break;
        case 'int':
            $i = clean_int($v);
            if ($i === null || $i < $def['min'] || $i > $def['max']) { $fields[$k] = "Enter a whole number from {$def['min']} to {$def['max']}."; break; }
            $write[$k] = (string) $i;
            break;
        case 'decimal':
            $d = clean_non_negative_decimal($v);
            if ($d === null || bccomp($d, $def['max'], 2) > 0) { $fields[$k] = "Enter a number from {$def['min']} to {$def['max']}."; break; }
            $write[$k] = bcadd($d, '0', 2);
            break;
        case 'bool':
            $write[$k] = (!empty($v) && $v !== '0' && $v !== 'false') ? '1' : '0';
            break;
        case 'user':
            $u = clean_int($v);
            if ($u && !db_row("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$u])) { $fields[$k] = 'Pick a user.'; break; }
            $write[$k] = $u ? (string) $u : '';
            break;
    }
}
if ((int) ($write['billing_cycle.send_by_days'] ?? settings_get('billing_cycle.send_by_days', '5'))
    < (int) ($write['billing_cycle.bill_by_days'] ?? settings_get('billing_cycle.bill_by_days', '3'))) {
    $fields['billing_cycle.send_by_days'] = 'Sending cannot be due before reviewing.';
}
if ($fields) json_validation_error($fields);
if (!$write) json_error('MISSING_REQUIRED', 'Nothing to change.', 422);

$before = $read();
db_transaction(static function () use ($write, $before): void {
    foreach ($write as $k => $v) {
        // Rows are seeded by the S-BILLING-MODULE migration; UPDATE keeps
        // their label/type/group. The fallback INSERT covers a missing row.
        $n = db_execute("UPDATE settings SET `value` = ?, updated_by = ? WHERE `key` = ?", [$v, current_user_id(), $k]);
        if ($n === 0 && !db_row("SELECT id FROM settings WHERE `key` = ?", [$k])) {
            db_insert('settings', ['key' => $k, 'value' => $v, 'value_type' => 'string', 'group_name' => 'billing_cycle', 'updated_by' => current_user_id()]);
        }
    }
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'billing',
        'entity_type'  => 'settings',
        'entity_id'    => null,
        'entity_label' => 'Billing settings',
        'old_values'   => json_encode(array_intersect_key($before, $write)),
        'new_values'   => json_encode($write),
        'notes'        => 'Billing settings changed: ' . implode(', ', array_keys($write)) . '.',
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});
// (No cache flush needed: db_execute/db_insert on `settings` bump the
// settings_get() cache generation themselves — includes/db.php.)

json_success(['settings' => $read(), 'can_edit' => true]);
