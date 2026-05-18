<?php
declare(strict_types=1);

/**
 * api/v1/accounting/fx-revaluations/preview.php
 *
 * Read-only — compute the revaluation snapshot without writing anything.
 * If manual_rate is supplied, it overrides the rate-source setting.
 *
 * @method  GET
 * @query   period_id (required), manual_rate? (optional override)
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Session: S037-FX
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FxRevaluationService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$periodId   = clean_int($_GET['period_id'] ?? null);
$manualRate = clean_string($_GET['manual_rate'] ?? null);
if (!$periodId) {
    json_error('MISSING_REQUIRED', 'period_id is required.', 422);
}
if ($manualRate !== null && $manualRate !== '' && !is_numeric($manualRate)) {
    json_error('VALIDATION_ERROR', 'manual_rate must be numeric.', 422);
}

try {
    $snapshot = FxRevaluationService::preview($periodId, $manualRate ?: null);
    json_success($snapshot);
} catch (\RuntimeException $e) {
    // Surface rate-fetch / configuration errors to the UI so it can
    // prompt for a manual rate instead. 422 = caller-actionable.
    json_error('FX_RATE_UNAVAILABLE', $e->getMessage(), 422);
}
