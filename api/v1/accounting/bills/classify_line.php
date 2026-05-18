<?php declare(strict_types=1);

/**
 * api/v1/accounting/bills/classify_line.php
 *
 * Mark a bill line as expensed (repair) — sets capitalize=0 + betterment_note
 * for audit trail. For the betterment (capitalize=1) path, see
 * /api/v1/accounting/fixed_assets/betterment.php which both stamps the line
 * AND adds to the asset's acquisition_cost. This endpoint is intentionally
 * limited to the repair side (no asset-cost mutation, no JE side effects).
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.5 (ASPE 3061.14)
 * Session: S-ACCT-COMP
 *
 * @method  POST
 * @body    JSON: line_id (required), note (required ≥ 5 chars)
 * @auth    Session required; require_permission('accounts_payable','edit')
 * @returns 200 { line_id, capitalize: 0, betterment_note }
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'edit');

$body   = json_body();
$lineId = clean_int($body['line_id'] ?? null);
$note   = is_string($body['note'] ?? null) ? trim($body['note']) : '';

$errors = [];
if (!$lineId) $errors['line_id'] = 'line_id is required.';
if (strlen($note) < 5) {
    $errors['note'] = 'Repair classification note is required (≥ 5 chars — ASPE 3061.14 justification).';
}
if ($errors) {
    json_validation_error($errors);
}

$line = db_row("SELECT id, asset_id, capitalize FROM acc_bill_lines WHERE id = ?", [$lineId]);
if (!$line) {
    json_error('NOT_FOUND', 'Bill line not found.', 404);
}
if ((int) $line['capitalize'] === 1) {
    json_error(
        'VALIDATION_ERROR',
        'Line is already capitalized — reclassification requires reversing the betterment first.',
        422
    );
}

db_update('acc_bill_lines', [
    'capitalize'      => 0,
    'betterment_note' => $note,
], 'id = ?', [$lineId]);

db_insert('audit_log', [
    'user_id'     => current_user_id(),
    'action'      => 'update',
    'module'      => 'accounting',
    'entity_type' => 'bill_line',
    'entity_id'   => $lineId,
    'notes'       => "Bill line #{$lineId} classified as REPAIR (expensed). Note: {$note}",
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success([
    'line_id'         => $lineId,
    'capitalize'      => 0,
    'betterment_note' => $note,
]);
