<?php
declare(strict_types=1);

/**
 * api/v1/accounting/tax/pos-audit-report.php
 *
 * Audit a fiscal period: re-derives POS province per invoice and flags
 * mismatches where the applied province (customer.province at invoice
 * time) differs from what the POS engine now derives.
 *
 * @method  GET
 * @query   period_id (required)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { period, total_invoices, mismatch_count, mismatches[] }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.6
 * Session: S-ACCT-POS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\PlaceOfSupplyService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$periodId = clean_int($_GET['period_id'] ?? null);
if (!$periodId) {
    json_error('MISSING_REQUIRED', 'period_id is required.', 422);
}

try {
    $report = PlaceOfSupplyService::auditReport($periodId);
} catch (\RuntimeException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
}

json_success($report);
