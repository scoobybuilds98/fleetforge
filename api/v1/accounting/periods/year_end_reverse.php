<?php
declare(strict_types=1);

/**
 * api/v1/accounting/periods/year_end_reverse.php
 *
 * Super-admin reversal of a posted year-end close. Reverses the
 * closing JE, drops the periods back to 'closed' (not 'open' — they
 * remain non-editable unless the operator explicitly re-opens them),
 * and flips the closure row to 'reversed'.
 *
 * @method  POST
 * @body    { fiscal_year, reason }
 * @auth    super_admin only
 * @session S037-YE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\YearEndService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

if ((current_user()['role_slug'] ?? '') !== 'super_admin') {
    json_error('FORBIDDEN', 'Year-end reversal is restricted to super_admin.', 403);
}

$body = json_body();
$input = !empty($body) ? $body : $_POST;

$fiscalYear = clean_int($input['fiscal_year'] ?? null);
$reason     = trim((string) ($input['reason'] ?? ''));
if (!$fiscalYear) {
    json_error('MISSING_REQUIRED', 'fiscal_year is required.', 422);
}
if ($reason === '') {
    json_error('MISSING_REQUIRED', 'reason is required for year-end reversal.', 422);
}

try {
    $result = YearEndService::reverse($fiscalYear, current_user_id(), $reason);
    json_success($result);
} catch (\RuntimeException $e) {
    json_error('YEAR_END_REVERSE_FAILED', $e->getMessage(), 422);
}
