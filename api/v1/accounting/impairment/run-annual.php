<?php
declare(strict_types=1);

/**
 * api/v1/accounting/impairment/run-annual.php
 *
 * Run the ASPE 3063 annual fleet impairment test (batch) for all
 * active fleet_equipment assets in the given fiscal year. Two-pass:
 * first call without `fair_value_inputs` returns the step 1 verdicts
 * (preview); second call provides FV for each failing asset to
 * complete step 2 + post impairment JEs.
 *
 * @method  POST
 * @body    JSON:
 *   - fiscal_year (required, int 2000–2100)
 *   - cf_overrides (object, optional) { "assetId": "decimal_string" }
 *   - fair_value_inputs (object, optional) {
 *       "assetId": { "fair_value": "decimal_string", "basis": "text" }
 *     }
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 full result envelope with per-test rows + summary counts
 *          422 invalid input
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.8
 * Session: S-ACCT-LESSOR-6
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\ImpairmentTestService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body = json_body();
$fiscalYear = clean_positive_int($body['fiscal_year'] ?? null);
if ($fiscalYear === null || $fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year is required and must be between 2000 and 2100.', 422);
}

$cfOverrides     = is_array($body['cf_overrides'] ?? null) ? $body['cf_overrides'] : [];
$fairValueInputs = is_array($body['fair_value_inputs'] ?? null) ? $body['fair_value_inputs'] : [];

$user = current_user();
$role = (string) ($user['role_slug'] ?? '');

try {
    $result = ImpairmentTestService::runAnnual(
        $fiscalYear,
        current_user_id(),
        $role,
        $cfOverrides,
        $fairValueInputs
    );
} catch (\InvalidArgumentException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($result);
