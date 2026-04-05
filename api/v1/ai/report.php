<?php
declare(strict_types=1);

/**
 * api/v1/ai/report.php
 *
 * Natural language report generation — user asks a question in
 * plain English, Claude uses tools to query data and generates
 * a formatted report response.
 *
 * POST /api/v1/ai/report
 *   Body: { query: "What is our revenue for Q1 2026?" }
 *   → { report: "...", data: {...}, tokens_used: N }
 *
 * Architecture:
 *   - Uses 'report' context tools (financial + query-focused subset)
 *   - Tool-calling loop same as chat, but with report-specific system prompt
 *   - Responses are more structured (tables, bullet points, summaries)
 *   - No session persistence — each report is standalone
 *
 * Permission: ai:view + reports:view
 *
 * @depends lib/AI/ClaudeClient.php, lib/AI/ToolRegistry.php
 * @session S027
 */

require_once __DIR__ . '/../../../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

require_auth_api();

if (!can('ai', 'view') || !can('reports', 'view')) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Invalid JSON body']);
    exit;
}

$query = trim($body['query'] ?? '');
if ($query === '') {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Query is required']);
    exit;
}

$userId   = (int) ($_SESSION['ff_user']['id'] ?? 0);
$userName = $_SESSION['ff_user']['name'] ?? 'User';

// ── Initialize AI ──────────────────────────────────────────
$ai = new \FleetForge\AI\ClaudeClient();
if (!$ai->isEnabled()) {
    http_response_code(503);
    echo json_encode(['error' => true, 'message' => 'AI features are not enabled.']);
    exit;
}

if (!\FleetForge\AI\TokenTracker::canSpend($userId)) {
    http_response_code(429);
    echo json_encode(['error' => true, 'message' => 'Daily AI token limit reached.']);
    exit;
}

// ── Build report-specific prompt ───────────────────────────
$today = date('Y-m-d');
$systemPrompt = <<<PROMPT
You are FleetForge AI Report Generator, creating data-driven reports for a trailer and equipment leasing company.

Current date: {$today}
User: {$userName}

Instructions:
- Use the available tools to query real data. Never fabricate numbers.
- Structure your response as a clear, professional report.
- Use markdown formatting: headers, bullet points, tables where appropriate.
- Always include the data range and any filters applied.
- Format monetary values with $ and two decimal places, include currency.
- Include totals and percentages where relevant.
- If the query is ambiguous, make reasonable assumptions and state them.
- Keep reports concise but comprehensive — aim for 200-400 words.
- End with key takeaways or recommendations when appropriate.
PROMPT;

// WHY: Report context gives Claude financial and query-focused tools
$tools    = \FleetForge\AI\ToolRegistry::getTools('report');
$messages = [['role' => 'user', 'content' => $query]];

// ── Tool-calling loop ──────────────────────────────────────
$iteration     = 0;
$maxIterations = \FleetForge\AI\ClaudeClient::MAX_TOOL_ITERATIONS;
$response      = null;
$totalTokens   = 0;

while ($iteration < $maxIterations) {
    $response = $ai->sendMessage(
        messages:     $messages,
        systemPrompt: $systemPrompt,
        tools:        $tools,
        maxTokens:    4096,
        userId:       $userId,
        queryType:    'report'
    );

    if ($response === null) {
        http_response_code(502);
        echo json_encode(['error' => true, 'message' => 'AI service unavailable.']);
        exit;
    }

    $totalTokens += ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

    if (!\FleetForge\AI\ClaudeClient::hasToolUse($response)) {
        break;
    }

    $toolBlocks = \FleetForge\AI\ClaudeClient::extractToolUseBlocks($response);
    $messages[] = ['role' => 'assistant', 'content' => $response['content']];

    $toolResults = [];
    foreach ($toolBlocks as $block) {
        $toolResult = \FleetForge\AI\ToolRegistry::execute(
            $block['name'],
            $block['input'] ?? [],
            $userId
        );

        $toolResults[] = [
            'type'        => 'tool_result',
            'tool_use_id' => $block['id'],
            'content'     => $toolResult,
        ];
    }

    $messages[] = ['role' => 'user', 'content' => $toolResults];
    $iteration++;
}

$reportText = \FleetForge\AI\ClaudeClient::extractTextContent($response);

if ($reportText === '') {
    $reportText = 'Unable to generate report. Please try rephrasing your question.';
}

// ── Log report query ───────────────────────────────────────
// WHY: Reports are one-shot (no session), but we still want audit trail
try {
    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'ai_report_generated',
        'entity_type' => 'report',
        'entity_id'   => 0,
        'details'     => json_encode(['query' => mb_substr($query, 0, 200), 'tokens' => $totalTokens]),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
} catch (\Throwable) {
    // Non-fatal
}

echo json_encode([
    'report'      => $reportText,
    'query'       => $query,
    'tokens_used' => $totalTokens,
], JSON_UNESCAPED_UNICODE);
