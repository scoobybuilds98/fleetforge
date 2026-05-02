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

// Validate entity_type
$validEntityTypes = ['customer', 'lease', 'equipment_unit', 'fleet'];
if (!in_array($entityType, $validEntityTypes, true)) {
    json_error('VALIDATION_ERROR', 'Invalid entity_type. Must be one of: ' . implode(', ', $validEntityTypes), 400);
}

// Validate summary_type
$validSummaryTypes = ['customer_insights', 'lease_summary', 'unit_analysis', 'fleet_health', 'payment_risk', 'forecast', 'anomaly', 'accounting_overview'];
if (!in_array($summaryType, $validSummaryTypes, true)) {
    json_error('VALIDATION_ERROR', 'Invalid summary_type. Must be one of: ' . implode(', ', $validSummaryTypes), 400);
}

// fleet-level summaries (fleet_health, accounting_overview) don't need an entity_id
$fleetLevelTypes = ['fleet_health', 'accounting_overview'];
if (!in_array($summaryType, $fleetLevelTypes, true) && $entityType !== 'fleet' && $entityId <= 0) {
    json_error('VALIDATION_ERROR', 'entity_id is required', 400);
}

// ── Check AI is enabled ────────────────────────────────────
$ai = new \FleetForge\AI\ClaudeClient();
if (!$ai->isEnabled()) {
    json_error('AI_DISABLED', 'AI features are not enabled.', 503);
}

// ── Generate or retrieve summary ───────────────────────────
$result = \FleetForge\AI\SummaryEngine::generate(
    entityType:   $entityType,
    entityId:     $entityId,
    summaryType:  $summaryType,
    userId:       $userId,
    forceRefresh: $forceRefresh
);

if ($result === null) {
    json_error('AI_ERROR', 'Failed to generate summary. Please try again.', 500);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
