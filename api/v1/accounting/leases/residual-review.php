<?php
declare(strict_types=1);

/**
 * api/v1/accounting/leases/residual-review.php
 *
 * Record the annual residual review for a sales-type or direct-
 * financing lease. Downward revisions post an impairment JE + regen
 * the unposted schedule; upward revisions are hard-rejected per ASPE
 * 3065 §24.7.
 *
 * @method  POST
 * @body    JSON:
 *   - lease_id (required, int > 0)
 *   - fiscal_year (required, int 2000–2100)
 *   - revised_residual_value (required, decimal string ≥ 0)
 *   - notes (optional, string)
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { review, je|null, regen|null, direction, delta }
 *          422 invalid input / upward revision / non-capital lease
 *          404 lease not found
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.7
 * Session: S-ACCT-LESSOR-5
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\LeaseResidualService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body       = json_body();
$leaseId    = clean_positive_int($body['lease_id']    ?? null);
$fiscalYear = clean_positive_int($body['fiscal_year'] ?? null);
$revised    = clean_decimal($body['revised_residual_value'] ?? null);
$notes      = (string) ($body['notes'] ?? '');

if ($leaseId === null) {
    json_error('VALIDATION_ERROR', 'lease_id is required and must be a positive integer.', 422);
}
if ($fiscalYear === null || $fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year is required and must be between 2000 and 2100.', 422);
}
if ($revised === null || bccomp($revised, '0', 2) < 0) {
    json_error('VALIDATION_ERROR', 'revised_residual_value is required and cannot be negative.', 422);
}

try {
    $result = LeaseResidualService::reviewResidual(
        $leaseId, $fiscalYear, $revised, $notes, current_user_id()
    );
} catch (\InvalidArgumentException $e) {
    // Upward-revision block + lease state errors map to 422 with the
    // service's friendly message preserved (operator-facing).
    $code = str_contains($e->getMessage(), 'not found') ? 404 : 422;
    json_error('VALIDATION_ERROR', $e->getMessage(), $code);
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($result);
