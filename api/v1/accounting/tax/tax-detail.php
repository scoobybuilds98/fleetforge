<?php
declare(strict_types=1);

/**
 * api/v1/accounting/tax/tax-detail.php
 *
 * Per-province (tax code) + per-customer tax detail for the period.
 *
 * @method  GET
 * @query   period_id (required)
 * @auth    Session required; require_permission('tax_management','view')
 * @returns 200 { by_code, by_customer }
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
    json_success([
        'by_code'     => Gst34Service::taxDetailByCode($periodId),
        'by_customer' => Gst34Service::taxDetailByCustomer($periodId),
    ]);
} catch (\RuntimeException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
}
