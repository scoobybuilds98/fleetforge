<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/insights.php
 *
 * Aggregate endpoint backing the AI Insights surface (F7). Composes
 * data from multiple sources for a one-shot fetch by the Insights
 * dashboard widget.
 *
 * @method  GET
 * @auth    require_permission('ai', 'view')
 * @session S-INTEL-V2 Phase F
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('ai', 'view');

use FleetForge\AI\TokenBudgetMonitor;
use FleetForge\Notifications\MorningBriefingRenderer;

try {
    $tokens = TokenBudgetMonitor::snapshot();

    $latestBrief = db_row(
        "SELECT id, created_at, result_data FROM report_cache
          WHERE report_type = 'ai_fleet_brief'
          ORDER BY id DESC LIMIT 1"
    );
    $briefText = '';
    $briefGeneratedAt = null;
    if ($latestBrief) {
        $p = json_decode((string) $latestBrief['result_data'], true);
        $briefText = is_array($p) ? (string) ($p['brief'] ?? '') : '';
        $briefGeneratedAt = is_array($p) ? (string) ($p['generated_at'] ?? $latestBrief['created_at']) : (string) $latestBrief['created_at'];
    }

    $recentRequests = db_select(
        "SELECT q.id, q.query_type, q.total_tokens, q.cost_usd, q.latency_ms, q.created_at,
                u.name AS user_name
           FROM ai_query_log q
           LEFT JOIN users u ON u.id = q.user_id
          ORDER BY q.id DESC
          LIMIT 10"
    );

    // Recent anomaly alerts. ai_anomaly_alerts uses alert_type/title/description —
    // the previous query selected nonexistent `category`/`summary` columns, so it
    // threw 42S22 and the broad catch left this permanently empty (the Insights
    // panel always showed zero anomalies). S-AI-AUDIT-HIGH-FIX.
    $anomalies = [];
    try {
        $anomalies = db_select(
            "SELECT id, alert_type, severity, title, description, created_at, acknowledged_at
               FROM ai_anomaly_alerts
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              ORDER BY id DESC LIMIT 20"
        );
    } catch (\PDOException $e) {
        // 42S02 = table genuinely absent (fresh/partial DB) → degrade quietly.
        // Any other error (e.g. a future column rename) must surface, not be
        // silently swallowed into a zero-anomaly result.
        if ($e->getCode() !== '42S02') {
            throw $e;
        }
    }

    $weeklyPayload = MorningBriefingRenderer::buildWeeklyPayload();

    json_success([
        'tokens'             => $tokens,
        'latest_brief'       => [
            'cache_id'     => $latestBrief ? (int) $latestBrief['id'] : null,
            'generated_at' => $briefGeneratedAt,
            'text'         => $briefText,
        ],
        'recent_requests'    => $recentRequests,
        'anomalies'          => $anomalies,
        'weekly_summary'     => $weeklyPayload,
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Insights load failed: ' . $e->getMessage(), 500);
}
