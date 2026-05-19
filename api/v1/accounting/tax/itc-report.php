<?php
declare(strict_types=1);

/**
 * api/v1/accounting/tax/itc-report.php
 *
 * ITC documentation compliance + per-customer tax detail in one response
 * per spec §23.7. Used for CRA audit defensibility — flags bills missing
 * the documentation required by ETA §169(4) at the three threshold tiers
 * (<$30, $30–$149.99, ≥$150) AND surfaces per-customer tax totals with
 * POS-mismatch flags.
 *
 * @method  GET
 * @query   period_id (required)
 * @auth    Session required; require_permission('tax_management','view')
 * @returns 200 { documentation, by_customer }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.7
 * Session: S-ACCT-GST34
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\Gst34Service;

require_method('GET');
require_auth_api();
require_permission('tax_management', 'view');

$periodId = clean_int($_GET['period_id'] ?? null);
if (!$periodId) {
    json_error('MISSING_REQUIRED', 'period_id is required.', 422);
}

try {
    $report = [
        'documentation' => Gst34Service::itcDocumentationReport($periodId),
        'by_customer'   => Gst34Service::taxDetailByCustomer($periodId),
    ];
} catch (\RuntimeException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
}

json_success($report);
