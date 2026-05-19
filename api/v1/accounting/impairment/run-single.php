<?php
declare(strict_types=1);

/**
 * api/v1/accounting/impairment/run-single.php
 *
 * Run an ASPE 3063 impairment test for ONE asset. Used for event-
 * triggered tests (idle / damage / market_decline / adverse_legal /
 * other) and for mid-year operator-initiated tests.
 *
 * @method  POST
 * @body    JSON:
 *   - asset_id (required, int > 0)
 *   - fiscal_year (required, int 2000–2100)
 *   - triggering_event (required, one of: annual / idle / damage /
 *                                          market_decline / adverse_legal / other)
 *   - cf_override (optional, decimal string)
 *   - fair_value (optional, decimal string; required to complete step 2)
 *   - fair_value_basis (optional, string)
 *   - notes (optional, string)
 *   - triggering_event_notes (optional, string)
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { test_id, status, ... } see ImpairmentTestService::runTest
 *          422 invalid input / asset disposed / RBAC denial
 *          404 asset not found
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.8
 * Session: S-ACCT-LESSOR-6
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\ImpairmentTestService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body            = json_body();
$assetId         = clean_positive_int($body['asset_id'] ?? null);
$fiscalYear      = clean_positive_int($body['fiscal_year'] ?? null);
$triggeringEvent = (string) ($body['triggering_event'] ?? '');
$cfOverride      = isset($body['cf_override']) && $body['cf_override'] !== ''
    ? clean_decimal($body['cf_override']) : null;
$fairValue       = isset($body['fair_value']) && $body['fair_value'] !== ''
    ? clean_decimal($body['fair_value']) : null;
$fairValueBasis  = isset($body['fair_value_basis']) ? (string) $body['fair_value_basis'] : null;
$notes           = isset($body['notes']) ? (string) $body['notes'] : null;
$eventNotes      = isset($body['triggering_event_notes']) ? (string) $body['triggering_event_notes'] : null;

if ($assetId === null) {
    json_error('VALIDATION_ERROR', 'asset_id is required and must be a positive integer.', 422);
}
if ($fiscalYear === null || $fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year is required and must be between 2000 and 2100.', 422);
}
if ($triggeringEvent === '') {
    json_error('VALIDATION_ERROR', 'triggering_event is required.', 422);
}
if (isset($body['cf_override']) && $body['cf_override'] !== '' && $cfOverride === null) {
    json_error('VALIDATION_ERROR', 'cf_override must be a decimal value when provided.', 422);
}
if (isset($body['fair_value']) && $body['fair_value'] !== '' && $fairValue === null) {
    json_error('VALIDATION_ERROR', 'fair_value must be a decimal value when provided.', 422);
}

$user = current_user();
$role = (string) ($user['role_slug'] ?? '');

try {
    $result = ImpairmentTestService::runTest(
        $assetId, $fiscalYear, $triggeringEvent,
        current_user_id(), $role,
        $cfOverride, $fairValue, $fairValueBasis,
        $notes, $eventNotes
    );
} catch (\InvalidArgumentException $e) {
    $code = str_contains($e->getMessage(), 'not found') ? 404 : 422;
    json_error('VALIDATION_ERROR', $e->getMessage(), $code);
} catch (\RuntimeException $e) {
    // RBAC denial from FixedAssetService::impair() throws RuntimeException —
    // map to 403 when the message indicates role-gating.
    $code = (str_contains($e->getMessage(), 'Only ') || str_contains($e->getMessage(), 'managers'))
        ? 403 : 422;
    json_error('VALIDATION_ERROR', $e->getMessage(), $code);
}

json_success($result);
