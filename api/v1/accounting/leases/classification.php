<?php
declare(strict_types=1);

/**
 * api/v1/accounting/leases/classification.php
 *
 * Fetch the stored ASPE 3065 classification archive for a lease. Used
 * by the lease create/edit UI when re-loading a saved classification,
 * and by the capital-lease register's drill-down.
 *
 * @method  GET
 * @query   lease_id (required, int > 0)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { lease{id,contract_number,classification,...},
 *                archive{...acc_lease_classifications row, or null} }
 *          404 lease not found
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.2
 * Session: S-ACCT-LESSOR-1
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\LeaseClassificationService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$leaseId = clean_positive_int($_GET['lease_id'] ?? null);
if ($leaseId === null) {
    json_error('VALIDATION_ERROR', 'lease_id is required and must be a positive integer.', 422);
}

$payload = LeaseClassificationService::getClassification($leaseId);
if ($payload === null) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

json_success($payload);
