<?php
declare(strict_types=1);

/**
 * api/v1/accounting/cca/show.php
 *
 * Returns the persisted CCA Schedule 8 continuity for a fiscal year,
 * joined to class metadata + per-class asset list (additions, carry-overs,
 * dispositions). Does NOT recompute — call compute.php for that.
 *
 * @method  GET
 * @query   fiscal_year (required)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { fiscal_year, rows[], assets_by_class{classId:{assets,disposals}} }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.3
 * Session: S-ACCT-CCA-1
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\CcaService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$fiscalYear = clean_int($_GET['fiscal_year'] ?? null);
if (!$fiscalYear) {
    json_error('MISSING_REQUIRED', 'fiscal_year is required.', 422);
}

json_success(CcaService::getSchedule($fiscalYear));
