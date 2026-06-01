<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/save_tax_rate_map.php
 *
 * Saves the F9 ITC tax-rate mapping + the bill tax-emission mode. The per-rate
 * bill-tax path (BillPusher::buildBillTaxDetail) is GATED default-off — this
 * endpoint lets an operator map each FF tax component (gst/pst/hst) to a QBO
 * TaxRate.Id and flip quickbooks.bill.tax_mode to 'per_rate'.
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'edit_credentials')
 * @body    JSON: { tax_mode?: 'override'|'per_rate',
 *                  components?: { gst:{id,name?,percent?}, pst:{...}, hst:{...} } }
 * @returns 200 { success: true, tax_mode, applied: [components] }
 *
 * @session  S-QBO-BILL-ITC-TAX-RATE (F9)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QuickBooksClient;

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body = json_body();

$errors = [];

// tax_mode (optional).
$taxMode = null;
if (array_key_exists('tax_mode', $body)) {
    $taxMode = (string) $body['tax_mode'];
    if (!in_array($taxMode, ['override', 'per_rate'], true)) {
        $errors['tax_mode'] = "Must be 'override' or 'per_rate'.";
    }
}

// components (optional map of gst/pst/hst → {id,name,percent}).
$components = is_array($body['components'] ?? null) ? $body['components'] : [];
$applied = [];
foreach (['gst', 'pst', 'hst'] as $comp) {
    if (!array_key_exists($comp, $components) || !is_array($components[$comp])) {
        continue;
    }
    $id   = trim((string) ($components[$comp]['id'] ?? ''));
    $name = trim((string) ($components[$comp]['name'] ?? ''));
    $pct  = $components[$comp]['percent'] ?? null;
    if ($id !== '' && strlen($id) > 50) {
        $errors["components.{$comp}.id"] = 'QBO TaxRate.Id too long (max 50).';
        continue;
    }
    $applied[$comp] = [
        'qbo_tax_rate_id'   => $id !== '' ? $id : null,
        'qbo_tax_rate_name' => $name !== '' ? $name : null,
        'qbo_tax_percent'   => ($pct !== null && $pct !== '') ? (string) $pct : null,
        'mapping_status'    => $id !== '' ? 'mapped' : 'unmapped',
    ];
}

if ($errors !== []) {
    json_validation_error($errors);
}

db_transaction(function () use ($taxMode, $applied): void {
    foreach ($applied as $comp => $fields) {
        $existing = db_row("SELECT id FROM acc_qbo_tax_rate_map WHERE ff_tax_component = ?", [$comp]);
        if ($existing) {
            db_update('acc_qbo_tax_rate_map', $fields, 'ff_tax_component = ?', [$comp]);
        } else {
            db_insert('acc_qbo_tax_rate_map', ['ff_tax_component' => $comp] + $fields);
        }
    }
    if ($taxMode !== null) {
        QuickBooksClient::settings_write_qbo('bill.tax_mode', $taxMode);
    }
    $user = current_user();
    db_insert('audit_log', [
        'user_id'     => $user['id'] ?? null,
        'user_name'   => $user['name'] ?? 'system',
        'action'      => 'update',
        'module'      => 'quickbooks',
        'entity_type' => 'qbo_tax_rate_map',
        'notes'       => 'QBO ITC tax-rate mapping updated: mode=' . ($taxMode ?? '(unchanged)') . ' components=' . json_encode(array_keys($applied)),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success([
    'tax_mode' => $taxMode ?? (string) settings_get('quickbooks.bill.tax_mode', 'override'),
    'applied'  => array_keys($applied),
]);
