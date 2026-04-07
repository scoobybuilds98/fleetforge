<?php declare(strict_types=1);

/**
 * api/v1/accounting/tax/periods/show.php
 *
 * Return a single tax filing period along with its remittance history
 * and per-transaction drill-down (invoices and bills that contributed
 * to total_tax_collected and total_itc respectively).
 *
 * Spec ref: §9 — "Report shows every transaction making up each line —
 * full drill-down."
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('tax_management','view')
 * @returns 200 { period, remittances, invoices, bills } | 404
 *
 * @depends api/bootstrap.php, TaxFilingService
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

use FleetForge\Accounting\TaxFilingService;

require_method('GET');
require_auth_api();
require_permission('tax_management', 'view');

$id = require_id($_GET['id'] ?? null);

$detail = TaxFilingService::getPeriodDetail($id);
if (!$detail) {
    json_error('NOT_FOUND', 'Tax filing period not found.', 404);
}

json_success($detail);
