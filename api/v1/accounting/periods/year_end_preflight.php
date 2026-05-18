<?php
declare(strict_types=1);

/**
 * api/v1/accounting/periods/year_end_preflight.php
 *
 * Read-only year-end pre-flight check. Returns per-check pass/fail
 * status without making any writes.
 *
 * @method  GET
 * @query   fiscal_year (required)
 * @auth    require_permission('journal_entries','view')
 * @session S037-YE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\YearEndService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$fiscalYear = clean_int($_GET['fiscal_year'] ?? null);
if (!$fiscalYear || $fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year must be a 4-digit calendar year.', 422);
}

$isSuperAdmin = (current_user()['role_slug'] ?? '') === 'super_admin';
$result = YearEndService::preflightCheck($fiscalYear, $isSuperAdmin);

json_success($result);
