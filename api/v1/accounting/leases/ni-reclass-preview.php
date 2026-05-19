<?php
declare(strict_types=1);

/**
 * api/v1/accounting/leases/ni-reclass-preview.php
 *
 * Show what the next monthly reclass JE would post — without writing.
 * Used by the capital-lease admin show.php "Current vs Long-Term NI
 * Breakdown" card to surface upcoming reclass moves before the cron
 * actually fires on the 1st of the month.
 *
 * @method  GET
 * @query   lease_id (optional — when omitted, returns all active
 *                    capital leases)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { leases: [ <balances...>, ... ] }
 *          404 specific lease not found
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.6
 * Session: S-ACCT-LESSOR-5
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\LeaseNiReclassService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$singleLeaseId = isset($_GET['lease_id'])
    ? clean_positive_int($_GET['lease_id'])
    : null;

if (isset($_GET['lease_id']) && $singleLeaseId === null) {
    json_error('VALIDATION_ERROR', 'lease_id must be a positive integer when provided.', 422);
}

try {
    if ($singleLeaseId !== null) {
        $rows = [LeaseNiReclassService::computeBalancesForLease($singleLeaseId)];
    } else {
        $leases = db_select(
            "SELECT id FROM leases
              WHERE classification IN ('sales_type','direct_financing')
                AND status = 'active'
                AND deleted_at IS NULL
              ORDER BY id ASC"
        );
        $rows = [];
        foreach ($leases as $l) {
            try {
                $rows[] = LeaseNiReclassService::computeBalancesForLease((int) $l['id']);
            } catch (\Throwable $e) {
                $rows[] = [
                    'leaseId' => (int) $l['id'],
                    'error'   => $e->getMessage(),
                ];
            }
        }
    }
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success(['leases' => $rows]);
