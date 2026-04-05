<?php
declare(strict_types=1);

/**
 * api/v1/ai/stream.php
 *
 * AI Chat — Server-Sent Events (SSE) streaming endpoint.
 * Provides typewriter-style response delivery for the chat UI.
 *
 * POST /api/v1/ai/stream
 *   Body: { session_id?, message, context_type?, context_id? }
 *   → SSE stream: data: {"type":"token","text":"..."}\n\n
 *                 data: {"type":"done","session_id":N,"message_id":N}\n\n
 *                 data: {"type":"tool_start","name":"..."}\n\n
 *                 data: {"type":"tool_end","name":"..."}\n\n
 *
 * Architecture:
 *   - Same logic as chat.php but streams text deltas via SSE
 *   - Tool calls happen server-side; client sees tool_start/tool_end events
 *   - Final message saved to DB same as non-streaming endpoint
 *   - Falls back to non-streaming if SSE isn't supported
 *
 * Permission: ai:view
 *
 * @depends lib/AI/ClaudeClient.php, lib/AI/ToolRegistry.php
 * @session S027
 */

require_once __DIR__ . '/../../../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

require_auth_api();

if (!can('ai', 'view')) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed']);
    exit;
}

// ── Parse input ────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Invalid JSON body']);
    exit;
}

$messageText = trim($body['message'] ?? '');
$sessionId   = (int) ($body['session_id'] ?? 0);
$contextType = trim($body['context_type'] ?? '');
$contextId   = (int) ($body['context_id'] ?? 0);

if ($messageText === '') {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Message is required']);
    exit;
}

$userId   = (int) ($_SESSION['ff_user']['id'] ?? 0);
$userName = $_SESSION['ff_user']['name'] ?? 'User';

// ── Initialize AI client ──────────────────────────────────
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

// ── Create or resume session (same as chat.php) ───────────
if ($sessionId <= 0) {
    $title = mb_strlen($messageText) > 100 ? mb_substr($messageText, 0, 97) . '...' : $messageText;
    $sessionId = db_insert('ai_chat_sessions', [
        'user_id'       => $userId,
        'session_title' => $title,
        'context_type'  => $contextType ?: null,
        'context_id'    => $contextId > 0 ? $contextId : null,
    ]);
} else {
    $session = db_row(
        "SELECT id FROM ai_chat_sessions WHERE id = ? AND user_id = ?",
        [$sessionId, $userId]
    );
    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => true, 'message' => 'Session not found']);
        exit;
    }
}

// ── Save user message ──────────────────────────────────────
db_insert('ai_chat_messages', [
    'session_id'  => $sessionId,
    'role'        => 'user',
    'content'     => $messageText,
    'tokens_used' => 0,
]);

// ── Load conversation history ──────────────────────────────
$history = db_select(
    "SELECT role, content FROM ai_chat_messages
     WHERE session_id = ?
     ORDER BY id ASC
     LIMIT 20",
    [$sessionId]
);

$messages = [];
foreach ($history as $msg) {
    if ($msg['role'] === 'system') continue;
    $messages[] = [
        'role'    => $msg['role'],
        'content' => $msg['content'],
    ];
}

// ── SSE headers ────────────────────────────────────────────
// WHY: SSE requires specific headers and no output buffering
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // nginx buffering off

// WHY: Disable PHP output buffering for real-time streaming
while (ob_get_level()) ob_end_flush();

/**
 * Send an SSE event to the client.
 */
function sendSSE(string $type, array $data = []): void
{
    $data['type'] = $type;
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

// ── Build system prompt and get tools ──────────────────────
$systemPrompt = buildStreamSystemPrompt($userName, $contextType, $contextId);
$tools = \FleetForge\AI\ToolRegistry::getTools('chat');

// ── Streaming tool-calling loop ────────────────────────────
$iteration     = 0;
$maxIterations = \FleetForge\AI\ClaudeClient::MAX_TOOL_ITERATIONS;
$finalText     = '';
$totalTokensAll = 0;

while ($iteration < $maxIterations) {
    // WHY: First iteration streams to client; subsequent iterations (tool loops) are non-streaming
    // because the client doesn't need to see intermediate tool processing text
    if ($iteration === 0) {
        $response = $ai->sendMessageStreaming(
            messages:     $messages,
            systemPrompt: $systemPrompt,
            tools:        $tools,
            maxTokens:    4096,
            userId:       $userId,
            queryType:    'chat',
            onChunk:      function (string $text) {
                sendSSE('token', ['text' => $text]);
            }
        );
    } else {
        $response = $ai->sendMessage(
            messages:     $messages,
            systemPrompt: $systemPrompt,
            tools:        $tools,
            maxTokens:    4096,
            userId:       $userId,
            queryType:    'chat'
        );
    }

    if ($response === null) {
        sendSSE('error', ['message' => 'AI service unavailable']);
        exit;
    }

    $totalTokensAll += ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

    // WHY: If no tool calls, we're done — Claude has given its final answer
    if (!\FleetForge\AI\ClaudeClient::hasToolUse($response)) {
        $finalText .= \FleetForge\AI\ClaudeClient::extractTextContent($response);
        break;
    }

    // Execute tool calls
    $toolBlocks = \FleetForge\AI\ClaudeClient::extractToolUseBlocks($response);
    $messages[] = ['role' => 'assistant', 'content' => $response['content']];

    $toolResults = [];
    foreach ($toolBlocks as $block) {
        sendSSE('tool_start', ['name' => $block['name']]);

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

        sendSSE('tool_end', ['name' => $block['name']]);
    }

    $messages[] = ['role' => 'user', 'content' => $toolResults];

    // WHY: After tool results, Claude's next response should stream to the client
    // We need to accumulate any text from intermediate responses
    $intermediateText = \FleetForge\AI\ClaudeClient::extractTextContent($response);
    if ($intermediateText !== '') {
        $finalText .= $intermediateText;
    }

    $iteration++;
}

if ($finalText === '') {
    $finalText = "I'm sorry, I wasn't able to generate a response. Please try rephrasing your question.";
}

// ── Save assistant message ─────────────────────────────────
$assistantMsgId = db_insert('ai_chat_messages', [
    'session_id'  => $sessionId,
    'role'        => 'assistant',
    'content'     => $finalText,
    'tokens_used' => $totalTokensAll,
]);

db_update('ai_chat_sessions', [
    'last_message_at' => date('Y-m-d H:i:s'),
], 'id = ?', [$sessionId]);

// ── Send done event ────────────────────────────────────────
sendSSE('done', [
    'session_id'  => $sessionId,
    'message_id'  => $assistantMsgId,
    'tokens_used' => $totalTokensAll,
]);

// ════════════════════════════════════════════════════════════
// Helper: system prompt (same as chat.php)
// ════════════════════════════════════════════════════════════
function buildStreamSystemPrompt(string $userName, string $contextType, int $contextId): string
{
    $today = date('Y-m-d');
    $prompt = <<<PROMPT
You are FleetForge AI, an intelligent assistant for a trailer and equipment leasing company. You help the team manage their fleet, customers, leases, invoices, and maintenance operations.

Current date: {$today}
User: {$userName}

Your capabilities:
- Search and look up customers, equipment, leases, invoices, and payments
- Provide fleet utilization stats and KPI summaries
- Generate financial summaries (revenue, AR aging, overdue invoices)
- Check compliance document expiry dates
- Review maintenance work order history
- Answer questions about FleetForge data using the tools available to you

Guidelines:
- Always use the available tools to look up real data before answering questions. Never guess or make up data.
- Be concise but thorough. Use bullet points and tables when presenting multiple data points.
- When discussing financial data, always include the currency (CAD/USD).
- If a question is outside your capabilities, say so clearly.
- If a tool returns an error or no results, explain that to the user helpfully.
- Format monetary values with dollar signs and two decimal places.
- When referencing dates, use a clear format like "January 15, 2026".
PROMPT;

    if ($contextType !== '' && $contextId > 0) {
        $prompt .= "\n\nContext: The user opened this chat from a {$contextType} page (ID: {$contextId}). "
                 . "When relevant, focus your answers on this specific {$contextType}.";
    }

    return $prompt;
}
