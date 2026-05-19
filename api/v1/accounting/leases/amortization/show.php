<?php
declare(strict_types=1);

/**
 * api/v1/accounting/leases/amortization/show.php
 *
 * Read the persisted amortization schedule for a lease. Returns the same
 * shape as preview.php / generate.php so the admin UI can render off a
 * single envelope regardless of which path produced it.
 *
 * Returns an empty `periods` array (not 404) when no schedule has been
 * generated yet — let the UI decide whether to show "Preview" vs
 * "Generate" affordances.
 *
 * @method  GET
 * @query   lease_id (required, int > 0)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { lease_id, lease, periods[], summary, persisted }
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

$result = LeaseAmortizationService::getSchedule($leaseId);

json_success($result);
