<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/asset-schedule.php
 *
 * Fixed-asset PP&E continuity schedule as of a date, grouped by
 * asset_class (the schema's category field on acc_fixed_assets).
 *
 * @method  GET
 * @query   as_of_date (required), category? (all|fleet_equipment|vehicles|
 *          office_equipment|leasehold_improvements|land|building|other),
 *          format? (json|pdf)
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §10 + §21.4
 * Session:  S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\ReportingService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$asOf     = clean_date($_GET['as_of_date'] ?? null);
$category = clean_string($_GET['category'] ?? 'all') ?: 'all';
$format   = strtolower((string) ($_GET['format'] ?? 'json'));

if (!$asOf) {
    json_error('MISSING_REQUIRED', 'as_of_date is required.', 422);
}

$report = ReportingService::assetSchedule($asOf, $category);

if ($format === 'pdf') {
    require_once FF_ROOT . '/lib/Accounting/ReportPdfRenderer.php';
    \FleetForge\Accounting\ReportPdfRenderer::assetSchedule($report);
    exit;
}

json_success($report);
