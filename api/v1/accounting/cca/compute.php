<?php
declare(strict_types=1);

/**
 * api/v1/accounting/cca/compute.php
 *
 * Compute T2 Schedule 8 (Capital Cost Allowance) continuity for a fiscal
 * year. Idempotent: if rows exist and none are locked, ?recompute=1
 * deletes and recomputes; otherwise returns the existing rows.
 *
 * @method  POST
 * @body    JSON: fiscal_year (required), recompute (bool, default false)
 * @auth    Session required; require_permission('journal_entries','edit')
 *          (CCA compute writes acc_cca_continuity rows; using the same
 *          journal_entries module as the rest of accounting per K-22 catch.)
 * @returns 200 { fiscal_year, rows[], computed: bool } | 422 | 423 LOCKED
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.3
 * Session: S-ACCT-CCA-1
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\CcaService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body       = json_body();
$fiscalYear = clean_int($body['fiscal_year'] ?? null);
$recompute  = !empty($body['recompute']);

if (!$fiscalYear || $fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year is required and must be a 4-digit year.', 422);
}

try {
    $result = CcaService::compute($fiscalYear, current_user_id(), $recompute);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'locked') !== false) {
        json_error('LOCKED', $msg, 423);
    }
    json_error('VALIDATION_ERROR', $msg, 422);
}

json_success($result);
