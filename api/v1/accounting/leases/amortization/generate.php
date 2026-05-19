<?php
declare(strict_types=1);

/**
 * api/v1/accounting/leases/amortization/generate.php
 *
 * Persist (or regenerate) the effective-interest amortization schedule
 * for a sales-type or direct-financing lease. The schedule lives in
 * acc_lease_amortization_schedules — one row per period. Regenerating
 * a schedule with any status='posted' rows is blocked (LESSOR-3/4 will
 * flip rows to posted via JE posting).
 *
 * @method  POST
 * @body    JSON: lease_id (required, int > 0)
 *                regenerate (bool, default false) — delete + rebuild if
 *                  no posted rows exist
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { lease_id, classification, annual_rate, periods, summary,
 *                persisted: true }
 *          422 invalid input / operating lease / posted rows blocking
 *          404 lease not found
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.3
 * Session: S-ACCT-LESSOR-2
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

use FleetForge\Accounting\LeaseAmortizationService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body    = json_body();
$leaseId = clean_positive_int($body['lease_id'] ?? null);
if ($leaseId === null) {
    json_error('VALIDATION_ERROR', 'lease_id is required and must be a positive integer.', 422);
}
$regenerate = (bool) ($body['regenerate'] ?? false);

try {
    $result = LeaseAmortizationService::generate($leaseId, current_user_id(), $regenerate);
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($result);
