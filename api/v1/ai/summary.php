<?php
declare(strict_types=1);

/**
 * api/v1/ai/summary.php
 *
 * AI-powered entity summaries — generate or retrieve cached
 * summaries for customers, leases, equipment, and fleet health.
 *
 * GET /api/v1/ai/summary?entity_type=X&entity_id=N&summary_type=X
 *   → { summary, generated_at, cached }
 *
 * POST /api/v1/ai/summary  (force regenerate)
 *   Body: { entity_type, entity_id, summary_type }
 *   → { summary, generated_at, cached: false }
 *
 * Permission: ai:view
 *
 * @depends lib/AI/SummaryEngine.php
 * @session S027
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

require_auth_api();

if (!can('ai', 'view')) {
    json_error('FORBIDDEN', 'Forbidden', 403);
}

// ── User-level rate limit (S-PROD-1A) ────────────────────────────────────────
$_rlCheck = \FleetForge\Security\RateLimiter::check(
    'ai:user:' . (int) ($_SESSION['ff_user']['id'] ?? 0),
    (int) settings_get('security.rate_limit.ai_user_threshold', 60),
    (int) settings_get('security.rate_limit.ai_user_window_minutes', 60)
);
if (!$_rlCheck['allowed']) {
    json_error('RATE_LIMITED', 'Too many AI requests. Try again in ' . $_rlCheck['retry_after_seconds'] . ' seconds.', 429);
}
unset($_rlCheck);

header('Content-Type: application/json');

$userId = (int) ($_SESSION['ff_user']['id'] ?? 0);

// ── Parse parameters (GET or POST body) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $forceRefresh = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $body = $_GET;
    $forceRefresh = false;
} else {
    json_error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
}

$entityType  = trim($body['entity_type'] ?? '');
$entityId    = (int) ($body['entity_id'] ?? 0);
$summaryType = trim($body['summary_type'] ?? '');
$reportContext = is_array($body['context'] ?? null) ? $body['context'] : [];

// S036: 'accounting' joins the entity_type whitelist for the 4 new
// narrative types. P&L / BS / CF use entity_id=0 (no specific entity);
// budget_variance uses entity_id = budget_id.
$validEntityTypes = ['customer', 'lease', 'equipment_unit', 'fleet', 'accounting'];
if (!in_array($entityType, $validEntityTypes, true)) {
    json_error('VALIDATION_ERROR', 'Invalid entity_type. Must be one of: ' . implode(', ', $validEntityTypes), 400);
}

// Validate summary_type
$validSummaryTypes = [
    'customer_insights', 'lease_summary', 'unit_analysis', 'fleet_health',
    'payment_risk', 'forecast', 'anomaly', 'accounting_overview',
    // S036 Phase B narratives
    'pl_narrative', 'bs_narrative', 'cashflow_narrative', 'budget_variance',
];
if (!in_array($summaryType, $validSummaryTypes, true)) {
    json_error('VALIDATION_ERROR', 'Invalid summary_type. Must be one of: ' . implode(', ', $validSummaryTypes), 400);
}

// Fleet-level + date-range summaries don't need an entity_id (they're not entity-bound).
// budget_variance is the exception — entity_id is required = budget_id.
$dateRangeTypes = ['pl_narrative', 'bs_narrative', 'cashflow_narrative'];
$fleetLevelTypes = array_merge(['fleet_health', 'accounting_overview'], $dateRangeTypes);
if ($summaryType === 'budget_variance' && $entityId <= 0) {
    json_error('VALIDATION_ERROR', 'entity_id (budget_id) is required for budget_variance', 400);
}
if (!in_array($summaryType, $fleetLevelTypes, true)
    && $summaryType !== 'budget_variance'
    && $entityType !== 'fleet'
    && $entityId <= 0) {
    json_error('VALIDATION_ERROR', 'entity_id is required', 400);
}

// Module-view gate (defense-in-depth): ai:view reaches this endpoint, but the
// underlying entity data still requires that module's view permission, so a
// custom role with ai:view but not e.g. customers:view can't read a customer
// summary. 'fleet' aggregates need no extra module gate. S-AI-AUDIT-HIGH-FIX.
$entityViewPerm = [
    'customer' => 'customers', 'lease' => 'leases',
    'equipment_unit' => 'equipment', 'accounting' => 'journal_entries',
];
$needPerm = $entityViewPerm[$entityType] ?? null;
if ($needPerm !== null && !can($needPerm, 'view')) {
    json_error('FORBIDDEN', 'You do not have permission to view this ' . $entityType . '.', 403);
}

// ── Check AI is enabled ────────────────────────────────────
$ai = new \FleetForge\AI\ClaudeClient();
if (!$ai->isEnabled()) {
    json_error('AI_DISABLED', 'AI features are not enabled.', 503);
}

// ── Generate or retrieve summary ───────────────────────────
$result = \FleetForge\AI\SummaryEngine::generate(
    entityType:    $entityType,
    entityId:      $entityId,
    summaryType:   $summaryType,
    userId:        $userId,
    forceRefresh:  $forceRefresh,
    reportContext: $reportContext
);

if ($result === null) {
    json_error('AI_ERROR', 'Failed to generate summary. Please try again.', 500);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
