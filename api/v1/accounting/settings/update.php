<?php declare(strict_types=1);

/**
 * api/v1/accounting/settings/update.php
 *
 * Update one or more accounting settings.
 * Accepts a JSON body with { settings: { key: value, ... } }.
 *
 * Dependencies: bootstrap.php, AccountingService
 *
 * @method  POST
 * @body    { settings: { "accounting.ar_account_id": 5, ... } }
 * @auth    Session required; require_permission('accounting_settings','edit')
 * @returns 200 { updated: int }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §17
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounting_settings', 'edit');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;
$settingsToUpdate = $input['settings'] ?? [];

// Allow a form-encoded "settings" JSON string
if (is_string($settingsToUpdate)) {
    $settingsToUpdate = json_decode($settingsToUpdate, true) ?? [];
}

if (empty($settingsToUpdate) || !is_array($settingsToUpdate)) {
    json_validation_error(['settings' => 'No settings provided.'], 'No settings provided.');
}

// ── Whitelist of allowed setting keys ────────────────────────
// WHY: Prevent updating arbitrary settings via the accounting endpoint
$allowedPrefixes = [
    'accounting.',
];

$userId = $_SESSION['user_id'] ?? null;
$updated = 0;
$oldValues = [];
$newValues = [];

db_transaction(function () use ($settingsToUpdate, $allowedPrefixes, $userId, &$updated, &$oldValues, &$newValues) {
    foreach ($settingsToUpdate as $key => $value) {
        // Validate key prefix
        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \RuntimeException("Setting key '{$key}' is not an accounting setting.");
        }

        // Get current value
        $current = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);

        // Serialize the value
        $strValue = is_array($value) ? json_encode($value) : (string) $value;

        if ($current) {
            $oldValues[$key] = $current['value'];
            $newValues[$key] = $strValue;

            db_execute(
                "UPDATE settings SET `value` = ? WHERE `key` = ?",
                [$strValue, $key]
            );
        } else {
            // Insert new setting (shouldn't normally happen — seeds create all)
            $newValues[$key] = $strValue;

            $valueType = is_array($value) ? 'json'
                : (is_numeric($value) && str_contains((string) $value, '.') ? 'decimal'
                    : (is_numeric($value) ? 'integer' : 'string'));

            db_execute(
                "INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, is_public)
                 VALUES (?, ?, ?, 'accounting', ?, '', 0)",
                [$key, $strValue, $valueType, $key]
            );
        }

        $updated++;
    }

    // Audit log
    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'settings',
        'entity_id'   => 0,
        'notes'       => "Updated {$updated} accounting setting(s)",
        'old_values'  => json_encode($oldValues),
        'new_values'  => json_encode($newValues),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['updated' => $updated]);
