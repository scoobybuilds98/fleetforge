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
 *                  components?: { gst:{id,name?,percent?}, pst:{...}, hst:{...} },
 *                  invoice_tax_mode?: 'override'|'per_rate',
 *                  invoice_exempt_code_id?: QBO TaxCode.Id for tax-free invoice lines }
 * @returns 200 { success: true, tax_mode, invoice_tax_mode, applied: [components] }
 *
 * S-QBO-GOLIVE-AUDIT (F80): also carries the INVOICE tax mode — per-rate
 * sends each line with the QuickBooks tax code mapped to the invoice's FF
 * tax rate (InvoiceTaxPerRate), which a Canadian company needs.
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
// S-QBO-GOLIVE-AUDIT (F80): invoice tax mode + the tax-free code.
$invoiceTaxMode = null;
if (array_key_exists('invoice_tax_mode', $body)) {
    $invoiceTaxMode = (string) $body['invoice_tax_mode'];
    if (!in_array($invoiceTaxMode, ['override', 'per_rate'], true)) {
        $errors['invoice_tax_mode'] = "Must be 'override' or 'per_rate'.";
    }
}
$invoiceExempt = null;
if (array_key_exists('invoice_exempt_code_id', $body)) {
    $invoiceExempt = trim((string) $body['invoice_exempt_code_id']);
    if ($invoiceExempt !== '' && !db_row("SELECT 1 AS x FROM acc_qbo_tax_code_map WHERE qbo_tax_code_id = ?", [$invoiceExempt])) {
        $errors['invoice_exempt_code_id'] = 'Pick a code pulled from QuickBooks.';
    }
}
if ($invoiceTaxMode === 'per_rate'
    && ($invoiceExempt ?? (string) settings_get('quickbooks.invoice.tax_code_exempt', '')) === '') {
    $errors['invoice_exempt_code_id'] = 'Per-rate needs the QuickBooks code for tax-free lines.';
}

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

db_transaction(function () use ($taxMode, $applied, $invoiceTaxMode, $invoiceExempt): void {
    if ($invoiceTaxMode !== null) {
        QuickBooksClient::settings_write_qbo('invoice.tax_mode', $invoiceTaxMode);
    }
    if ($invoiceExempt !== null) {
        QuickBooksClient::settings_write_qbo('invoice.tax_code_exempt', $invoiceExempt);
    }
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
        'notes'       => 'QBO tax settings updated: bill mode=' . ($taxMode ?? '(unchanged)') . ' components=' . json_encode(array_keys($applied))
            . ' invoice mode=' . ($invoiceTaxMode ?? '(unchanged)') . ($invoiceExempt !== null ? " exempt_code={$invoiceExempt}" : ''),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

settings_cache_flush();
json_success([
    'tax_mode' => $taxMode ?? (string) settings_get('quickbooks.bill.tax_mode', 'override'),
    'invoice_tax_mode' => (string) settings_get('quickbooks.invoice.tax_mode', 'override'),
    'applied'  => array_keys($applied),
]);
