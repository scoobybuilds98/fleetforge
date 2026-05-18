<?php
declare(strict_types=1);

/**
 * api/v1/accounting/saved-reports/create.php
 *
 * Save a report configuration (name + parameters JSON) for later recall.
 * Owned by the current user; is_pinned defaults to 0.
 *
 * NOTE: The schema-on-disk table `saved_reports` has no `is_shared`
 * column — the prompt's "shared" flag does not exist. is_pinned acts as
 * the only flag the schema supports.
 *
 * @method  POST
 * @body    { name, report_type, parameters (object), is_pinned? }
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Session: S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'view');

$body = json_body();
$input = !empty($body) ? $body : $_POST;

$name       = trim((string) ($input['name'] ?? ''));
$reportType = trim((string) ($input['report_type'] ?? ''));
$parameters = $input['parameters'] ?? null;
$isPinned   = !empty($input['is_pinned']);

if ($name === '') {
    json_error('MISSING_REQUIRED', 'name is required.', 422);
}
if ($reportType === '') {
    json_error('MISSING_REQUIRED', 'report_type is required.', 422);
}

$allowedTypes = ['profit_loss', 'balance_sheet', 'cash_flow', 'trial_balance',
                 'ar_aging', 'ap_aging', 'asset_schedule', 'budget_variance'];
if (!in_array($reportType, $allowedTypes, true)) {
    json_error('VALIDATION_ERROR', 'report_type must be one of: ' . implode(', ', $allowedTypes), 422);
}

if (is_string($parameters)) {
    $decoded = json_decode($parameters, true);
    $parameters = is_array($decoded) ? $decoded : [];
}
if (!is_array($parameters)) {
    $parameters = [];
}

$id = db_insert('saved_reports', [
    'user_id'     => current_user_id(),
    'name'        => $name,
    'report_type' => $reportType,
    'parameters'  => json_encode($parameters),
    'is_pinned'   => $isPinned ? 1 : 0,
]);

json_success([
    'id'          => $id,
    'name'        => $name,
    'report_type' => $reportType,
    'parameters'  => $parameters,
    'is_pinned'   => $isPinned ? 1 : 0,
], 201);
