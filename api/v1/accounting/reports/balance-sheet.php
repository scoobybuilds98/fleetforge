<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/balance-sheet.php
 *
 * Balance sheet as of a date with optional comparison. Returns JSON;
 * ?format=pdf renders an mPDF document inline.
 *
 * @method  GET
 * @query   as_of_date (required), comparison? (none|prior_period|prior_year),
 *          format? (json|pdf)
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §10 + §21.2
 * Session:  S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\ReportingService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$asOf       = clean_date($_GET['as_of_date'] ?? null);
$comparison = clean_string($_GET['comparison'] ?? 'none') ?: 'none';
$format     = strtolower((string) ($_GET['format'] ?? 'json'));

$valid = ['none', 'prior_period', 'prior_year'];
if (!in_array($comparison, $valid, true)) {
    json_error('VALIDATION_ERROR', 'comparison must be one of: ' . implode(', ', $valid), 422);
}
if (!$asOf) {
    json_error('MISSING_REQUIRED', 'as_of_date is required.', 422);
}

$report = ReportingService::balanceSheet($asOf, $comparison);

if ($format === 'pdf') {
    require_once FF_ROOT . '/lib/Accounting/ReportPdfRenderer.php';
    \FleetForge\Accounting\ReportPdfRenderer::balanceSheet($report);
    exit;
}

json_success($report);
