<?php
declare(strict_types=1);

/**
 * api/v1/accounting/impairment/show.php
 *
 * Read endpoint for `acc_impairment_tests` rows. Two modes:
 *
 *   - GET ?test_id=N  → single test row with full joined asset + JE
 *                       + tester user details (used by the detail page).
 *   - GET ?fiscal_year=YYYY → all tests for that fiscal year (used by
 *                              the index page's history table).
 *
 * @method  GET
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.8
 * Session: S-ACCT-LESSOR-6
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\ImpairmentTestService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$testId     = isset($_GET['test_id'])     ? clean_positive_int($_GET['test_id'])     : null;
$fiscalYear = isset($_GET['fiscal_year']) ? clean_positive_int($_GET['fiscal_year']) : null;

if ($testId === null && $fiscalYear === null) {
    json_error('VALIDATION_ERROR', 'Provide either test_id or fiscal_year.', 422);
}

if ($testId !== null) {
    $row = ImpairmentTestService::getTest($testId);
    if (!$row) {
        json_error('NOT_FOUND', "Impairment test #{$testId} not found.", 404);
    }
    // Decode the JSON breakdown for the UI.
    if (!empty($row['step_1_cf_breakdown_json'])) {
        $row['step_1_cf_breakdown'] = json_decode($row['step_1_cf_breakdown_json'], true);
    }
    json_success(['test' => $row]);
}

if ($fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year must be between 2000 and 2100.', 422);
}
$rows = ImpairmentTestService::listForYear($fiscalYear);
json_success(['fiscal_year' => $fiscalYear, 'tests' => $rows]);
