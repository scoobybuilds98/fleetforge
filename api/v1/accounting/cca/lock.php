<?php
declare(strict_types=1);

/**
 * api/v1/accounting/cca/lock.php
 *
 * Lock all CCA continuity rows for a fiscal year (sign-off). Subsequent
 * compute calls return 423 LOCKED until super_admin unlocks.
 *
 * @method  POST
 * @body    JSON: fiscal_year (required)
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { fiscal_year, locked: true }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.3
 * Session: S-ACCT-CCA-1
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\CcaService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body       = json_body();
$fiscalYear = clean_int($body['fiscal_year'] ?? null);
if (!$fiscalYear) {
    json_error('MISSING_REQUIRED', 'fiscal_year is required.', 422);
}

try {
    CcaService::lock($fiscalYear, current_user_id());
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success(['fiscal_year' => $fiscalYear, 'locked' => true]);
