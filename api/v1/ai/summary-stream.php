<?php
declare(strict_types=1);

/**
 * api/v1/ai/summary-stream.php
 *
 * Streaming variant of the AI summary endpoint. Gathers context, builds the
 * prompt, then streams the Claude response via Server-Sent Events so text
 * appears token-by-token in the browser instead of after a multi-second wait.
 *
 * POST /api/v1/ai/summary-stream
 *   Body: { entity_type, entity_id, summary_type, context? }
 *
 * SSE event types:
 *   data: {"t":"tok","c":"text delta"}\n\n   — token chunk
 *   data: {"t":"done"}\n\n                   — generation complete (text cached)
 *   data: {"t":"err","m":"message"}\n\n      — failure (stream ends)
 *
 * @depends lib/AI/SummaryEngine.php, lib/AI/ClaudeClient.php
 * @session S-AI-STREAM-SUMMARY
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

require_auth_api();

if (!can('ai', 'view')) {
    http_response_code(403);
    echo 'data: ' . json_encode(['t' => 'err', 'm' => 'Forbidden']) . "\n\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'data: ' . json_encode(['t' => 'err', 'm' => 'Method not allowed']) . "\n\n";
    exit;
}

// ── Rate limit ────────────────────────────────────────────────────────────────
$_rlCheck = \FleetForge\Security\RateLimiter::check(
    'ai:user:' . (int) ($_SESSION['ff_user']['id'] ?? 0),
    (int) settings_get('security.rate_limit.ai_user_threshold', 60),
    (int) settings_get('security.rate_limit.ai_user_window_minutes', 60)
);
if (!$_rlCheck['allowed']) {
    http_response_code(429);
    echo 'data: ' . json_encode(['t' => 'err', 'm' => 'Too many AI requests. Try again in ' . $_rlCheck['retry_after_seconds'] . ' seconds.']) . "\n\n";
    exit;
}
unset($_rlCheck);

// ── Parse input ────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$entityType    = trim($body['entity_type'] ?? '');
$entityId      = (int) ($body['entity_id'] ?? 0);
$summaryType   = trim($body['summary_type'] ?? '');
$reportContext = is_array($body['context'] ?? null) ? $body['context'] : [];
$userId        = (int) ($_SESSION['ff_user']['id'] ?? 0);

$validEntityTypes = ['customer', 'lease', 'equipment_unit', 'fleet', 'accounting'];
$validSummaryTypes = [
    'customer_insights', 'lease_summary', 'unit_analysis', 'fleet_health',
    'payment_risk', 'forecast', 'anomaly', 'accounting_overview',
    'pl_narrative', 'bs_narrative', 'cashflow_narrative', 'budget_variance',
];

// ── SSE headers — set before any output ───────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// Disable output buffering so tokens reach the browser immediately
while (ob_get_level() > 0) { ob_end_clean(); }

$emitErr = static function(string $msg): never {
    echo 'data: ' . json_encode(['t' => 'err', 'm' => $msg]) . "\n\n";
    flush();
    exit;
};

// ── Validate ─────────────────────────────────────────────────────────────────
if (!in_array($entityType, $validEntityTypes, true)) {
    $emitErr('Invalid entity_type.');
}
if (!in_array($summaryType, $validSummaryTypes, true)) {
    $emitErr('Invalid summary_type.');
}
$fleetLevelTypes = ['fleet_health', 'accounting_overview', 'pl_narrative', 'bs_narrative', 'cashflow_narrative'];
if (!in_array($summaryType, $fleetLevelTypes, true) && $summaryType !== 'budget_variance' && $entityId <= 0) {
    $emitErr('entity_id is required.');
}

// ── AI enabled check ──────────────────────────────────────────────────────────
$ai = new \FleetForge\AI\ClaudeClient();
if (!$ai->isEnabled()) {
    $emitErr('AI features are not enabled. Configure your API key in Settings → Integrations.');
}

// ── Gather context ────────────────────────────────────────────────────────────
$context = \FleetForge\AI\SummaryEngine::gatherContext($entityType, $entityId, $summaryType, $userId, $reportContext);
if ($context === null) {
    $emitErr('Could not load data for this entity. Please try again.');
}

$prompt = \FleetForge\AI\SummaryEngine::buildPrompt($summaryType, $context);

// ── Stream via Claude ─────────────────────────────────────────────────────────
$fullText = '';

$response = $ai->sendMessageStreaming(
    messages:     [['role' => 'user', 'content' => $prompt]],
    systemPrompt: \FleetForge\AI\SummaryEngine::getSystemPrompt(),
    tools:        [],
    maxTokens:    4096,
    userId:       $userId,
    queryType:    'summary',
    onChunk:      static function(string $chunk) use (&$fullText): void {
        $fullText .= $chunk;
        echo 'data: ' . json_encode(['t' => 'tok', 'c' => $chunk]) . "\n\n";
        flush();
    }
);

if ($response === null) {
    $err = $ai->getLastError();
    $emitErr($err['message'] ?? 'Generation failed. Please try again.');
}

// ── Cache the completed summary ───────────────────────────────────────────────
$tokensUsed = ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);
\FleetForge\AI\SummaryEngine::cacheSummary(
    $entityType,
    $entityId,
    $summaryType,
    $fullText,
    $tokensUsed,
    $ai->getModel(),
    $userId
);

echo 'data: ' . json_encode(['t' => 'done']) . "\n\n";
flush();
