<?php
declare(strict_types=1);

/**
 * api/v1/accounting/disclosure/generate.php
 *
 * Auto-generate (or re-generate) the 9 ASPE disclosure notes for a fiscal
 * year. Manually edited notes (is_auto_generated=0) are preserved.
 *
 * @method  POST
 * @body    JSON: fiscal_year (required, 4-digit year)
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { notes: [...9 rows...] } | 422 invalid year
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.9
 * Session: S-ACCT-DISC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\DisclosureService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body       = json_body();
$fiscalYear = clean_int($body['fiscal_year'] ?? null);

if (!$fiscalYear || $fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year is required and must be a 4-digit year.', 422);
}

$notes = DisclosureService::generateAll($fiscalYear, current_user_id());

json_success(['fiscal_year' => $fiscalYear, 'notes' => $notes]);
