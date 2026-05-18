<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/cash-flow.php
 *
 * Cash Flow Statement (indirect method, ASPE 1540) for a date range.
 *
 * @method  GET
 * @query   period_start (required), period_end (required), format? (json|pdf)
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §10 + §21.3
 * Session:  S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\ReportingService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$from   = clean_date($_GET['period_start'] ?? null);
$to     = clean_date($_GET['period_end'] ?? null);
$format = strtolower((string) ($_GET['format'] ?? 'json'));

if (!$from || !$to) {
    json_error('MISSING_REQUIRED', 'period_start and period_end are required.', 422);
}
if (strtotime($from) > strtotime($to)) {
    json_error('VALIDATION_ERROR', 'period_start must be on or before period_end.', 422);
}

$report = ReportingService::cashFlow($from, $to);

if ($format === 'pdf') {
    require_once FF_ROOT . '/lib/Accounting/ReportPdfRenderer.php';
    \FleetForge\Accounting\ReportPdfRenderer::cashFlow($report);
    exit;
}

json_success($report);
