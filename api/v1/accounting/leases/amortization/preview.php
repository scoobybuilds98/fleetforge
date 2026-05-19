<?php
declare(strict_types=1);

/**
 * api/v1/accounting/leases/amortization/preview.php
 *
 * Compute the amortization schedule WITHOUT writing to the DB. Used by
 * the lease detail page's "Preview Schedule" affordance so the operator
 * can inspect the period-by-period table before committing.
 *
 * @method  GET
 * @query   lease_id (required, int > 0)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { lease_id, classification, monthly_rate, annual_rate,
 *                term_months, periods[], summary, persisted: false }
 *          404 lease not found
 *          422 operating lease / no classification record
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.3
 * Session: S-ACCT-LESSOR-2
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

use FleetForge\Accounting\LeaseAmortizationService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$leaseId = clean_positive_int($_GET['lease_id'] ?? null);
if ($leaseId === null) {
    json_error('VALIDATION_ERROR', 'lease_id is required and must be a positive integer.', 422);
}

try {
    $result = LeaseAmortizationService::preview($leaseId);
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($result);
