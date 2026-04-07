<?php declare(strict_types=1);

/**
 * api/v1/accounting/settings/index.php
 *
 * List all accounting settings grouped by category.
 * Returns key-value pairs the settings UI needs.
 *
 * Dependencies: bootstrap.php, AccountingService
 *
 * @method  GET
 * @auth    Session required; require_permission('accounting_settings','view')
 * @returns 200 { settings: { key: value, ... } }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §17
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('accounting_settings', 'view');

use FleetForge\Accounting\AccountingService;

// ── Load all accounting settings ────────────────────────────
$rows = db_select(
    "SELECT `key`, `value`, value_type, group_name, label, description
     FROM settings
     WHERE `key` LIKE 'accounting.%'
     ORDER BY group_name, `key`",
    []
);

$settings = [];
foreach ($rows as $r) {
    $parsed = match ($r['value_type']) {
        'integer' => (int) $r['value'],
        'decimal' => $r['value'],
        'boolean' => $r['value'] === 'true' || $r['value'] === '1',
        'json'    => json_decode($r['value'], true),
        default   => $r['value'],
    };

    $settings[$r['key']] = [
        'value'       => $parsed,
        'raw'         => $r['value'],
        'value_type'  => $r['value_type'],
        'group_name'  => $r['group_name'],
        'label'       => $r['label'],
        'description' => $r['description'],
    ];
}

json_success(['settings' => $settings]);
