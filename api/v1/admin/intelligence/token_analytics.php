<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/token_analytics.php
 *
 * Token usage analytics for the Intelligence tab Token Analytics widget
 * (F1). Returns today / MTD / 7-day / 30-day rollups + per-feature
 * breakdown + 7-day daily chart. Pure aggregation; no Claude API calls.
 *
 * @method  GET
 * @auth    require_permission('settings', 'view')
 * @session S-INTEL-V2 Phase A
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('settings', 'view');

use FleetForge\AI\TokenBudgetMonitor;

try {
    $snapshot = TokenBudgetMonitor::snapshot();
    json_success($snapshot);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Token analytics failed: ' . $e->getMessage(), 500);
}
