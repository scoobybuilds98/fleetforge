<?php
declare(strict_types=1);

/**
 * api/v1/accounting/periods/year_end.php
 *
 * Run the year-end close. Posts the closing JE, locks the 12 periods
 * of the fiscal year, seeds the next year's 12 periods, and generates
 * the year-end package (PDFs + manifest + ZIP).
 *
 * The optional `confirm_ar_drift_override` flag is honored only for
 * super_admin users (D-S037-YE-1). Non-super_admin users with AR drift
 * over tolerance are hard-blocked by the pre-flight check.
 *
 * @method  POST
 * @body    { fiscal_year, confirm_ar_drift_override? (bool) }
 * @auth    require_permission('journal_entries','create')
 * @session S037-YE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\YearEndService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

$body = json_body();
$input = !empty($body) ? $body : $_POST;

$fiscalYear = clean_int($input['fiscal_year'] ?? null);
if (!$fiscalYear || $fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year must be a 4-digit calendar year.', 422);
}

$isSuperAdmin = (current_user()['role_slug'] ?? '') === 'super_admin';
$arDriftOverride = !empty($input['confirm_ar_drift_override']);

// Defense in depth: a non-super_admin caller cannot pass the override.
if ($arDriftOverride && !$isSuperAdmin) {
    json_error('FORBIDDEN', 'AR drift override requires super_admin role.', 403);
}

try {
    $result = YearEndService::close($fiscalYear, current_user_id(), $isSuperAdmin, $arDriftOverride);
    json_success($result, 201);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'already closed')) {
        json_error('ALREADY_CLOSED', $msg, 409);
    }
    if (str_contains($msg, 'Pre-flight checks failed')) {
        json_error('PREFLIGHT_FAILED', $msg, 422);
    }
    if (str_contains($msg, 'super-admin override')) {
        json_error('OVERRIDE_REQUIRED', $msg, 422);
    }
    if (str_contains($msg, 'Retained earnings account not configured')) {
        json_error('CONFIG_INCOMPLETE', $msg, 422);
    }
    if (str_contains($msg, 'unbalanced')) {
        json_error('CLOSING_JE_UNBALANCED', $msg, 500);
    }
    json_error('YEAR_END_FAILED', $msg, 422);
}
