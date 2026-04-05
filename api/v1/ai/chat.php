<?php
declare(strict_types=1);

/**
 * api/v1/ai/chat.php
 *
 * AI Chat — main endpoint for conversational AI with tool-calling.
 *
 * POST /api/v1/ai/chat
 *   Body: { session_id?, message, context_type?, context_id? }
 *   → { session_id, message_id, content, chart_data? }
 *
 * GET /api/v1/ai/chat
 *   → { sessions: [{id, title, context_type, last_message_at}] }
 *
 * Architecture:
 *   - Creates/resumes chat sessions stored in ai_chat_sessions
 *   - Messages stored in ai_chat_messages
 *   - Tool-calling loop: Claude requests tool → we execute → send result → repeat
 *   - Max 5 tool iterations per request (ClaudeClient::MAX_TOOL_ITERATIONS)
 *   - Financial tools gated by payments:view permission
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

header('Content-Type: application/json');

$userId   = (int) ($_SESSION['ff_user']['id'] ?? 0);
$userName = $_SESSION['ff_user']['name'] ?? 'User';

// ────────────────────────────────────────────────────────────
// GET — list user's chat sessions
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sessions = db_select(
        "SELECT id, session_title AS title, context_type, context_id,
                created_at, last_message_at
         FROM ai_chat_sessions
         WHERE user_id = ?
         ORDER BY last_message_at DESC
         LIMIT 50",
        [$userId]
    );

    echo json_encode(['sessions' => $sessions]);
    exit;
}

// ────────────────────────────────────────────────────────────
// POST — send a message and get AI response
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed']);
    exit;
}

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

// ── Initialize AI client ──────────────────────────────────
$ai = new \FleetForge\AI\ClaudeClient();
if (!$ai->isEnabled()) {
    http_response_code(503);
    echo json_encode(['error' => true, 'message' => 'AI features are not enabled. Configure your API key in Settings.']);
    exit;
}

// ── Check daily token limit ────────────────────────────────
if (!\FleetForge\AI\TokenTracker::canSpend($userId)) {
    http_response_code(429);
    echo json_encode(['error' => true, 'message' => 'Daily AI token limit reached. Try again tomorrow.']);
    exit;
}

// ── Create or resume session ───────────────────────────────
if ($sessionId <= 0) {
    // WHY: Auto-generate session title from first message (truncated to 100 chars)
    $title = mb_strlen($messageText) > 100 ? mb_substr($messageText, 0, 97) . '...' : $messageText;

    $sessionId = db_insert('ai_chat_sessions', [
        'user_id'        => $userId,
        'session_title'  => $title,
        'context_type'   => $contextType ?: null,
        'context_id'     => $contextId > 0 ? $contextId : null,
    ]);
} else {
    // WHY: Verify the session belongs to this user
    $session = db_row(
        "SELECT id FROM ai_chat_sessions WHERE id = ? AND user_id = ?",
        [$sessionId, $userId]
    );
    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => true, 'message' => 'Chat session not found']);
        exit;
    }
}

// ── Save user message ──────────────────────────────────────
$userMsgId = db_insert('ai_chat_messages', [
    'session_id'  => $sessionId,
    'role'        => 'user',
    'content'     => $messageText,
    'tokens_used' => 0,
]);

// ── Load conversation history ──────────────────────────────
// WHY: Load last 20 messages to keep context within token budget
$history = db_select(
    "SELECT role, content FROM ai_chat_messages
     WHERE session_id = ?
     ORDER BY id ASC
     LIMIT 20",
    [$sessionId]
);

// WHY: Build Anthropic messages array from stored conversation
$messages = [];
foreach ($history as $msg) {
    $role = $msg['role'];
    // System messages stored in DB are for reference only — skip in API calls
    if ($role === 'system') continue;

    $messages[] = [
        'role'    => $role,
        'content' => $msg['content'],
    ];
}

// ── Build system prompt ────────────────────────────────────
$systemPrompt = buildSystemPrompt($userName, $contextType, $contextId);

// ── Get tools for chat context ─────────────────────────────
$tools = \FleetForge\AI\ToolRegistry::getTools('chat');

// ── Tool-calling loop ──────────────────────────────────────
// WHY: Claude may request multiple tools before giving a final answer.
// We loop up to MAX_TOOL_ITERATIONS, executing each tool and sending
// results back. The loop ends when Claude responds with text (end_turn).
$iteration = 0;
$maxIterations = \FleetForge\AI\ClaudeClient::MAX_TOOL_ITERATIONS;
$response = null;

while ($iteration < $maxIterations) {
    $response = $ai->sendMessage(
        messages:     $messages,
        systemPrompt: $systemPrompt,
        tools:        $tools,
        maxTokens:    4096,
        userId:       $userId,
        queryType:    'chat'
    );

    if ($response === null) {
        http_response_code(502);
        echo json_encode(['error' => true, 'message' => 'AI service unavailable. Please try again.']);
        exit;
    }

    // WHY: If Claude doesn't want to use a tool, we're done
    if (!\FleetForge\AI\ClaudeClient::hasToolUse($response)) {
        break;
    }

    // Execute each tool_use block and build tool_result messages
    $toolBlocks = \FleetForge\AI\ClaudeClient::extractToolUseBlocks($response);

    // WHY: Append Claude's response (with tool_use blocks) to messages
    $messages[] = ['role' => 'assistant', 'content' => $response['content']];

    // Build tool_result content blocks
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

    // WHY: Send tool results back to Claude for interpretation
    $messages[] = ['role' => 'user', 'content' => $toolResults];

    $iteration++;
}

// ── Extract final text response ────────────────────────────
$assistantText = \FleetForge\AI\ClaudeClient::extractTextContent($response);

if ($assistantText === '') {
    $assistantText = "I'm sorry, I wasn't able to generate a response. Please try rephrasing your question.";
}

// ── Save assistant message ─────────────────────────────────
$totalTokens = ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

$assistantMsgId = db_insert('ai_chat_messages', [
    'session_id'  => $sessionId,
    'role'        => 'assistant',
    'content'     => $assistantText,
    'tokens_used' => $totalTokens,
]);

// ── Update session last_message_at ─────────────────────────
db_update('ai_chat_sessions', [
    'last_message_at' => date('Y-m-d H:i:s'),
], 'id = ?', [$sessionId]);

// ── Return response ────────────────────────────────────────
echo json_encode([
    'session_id'  => $sessionId,
    'message_id'  => $assistantMsgId,
    'content'     => $assistantText,
    'tokens_used' => $totalTokens,
]);

// ════════════════════════════════════════════════════════════
// Helper: Build the system prompt for FleetForge AI chat
// ════════════════════════════════════════════════════════════
function buildSystemPrompt(string $userName, string $contextType, int $contextId): string
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

    // WHY: Add context-specific instructions when chat is opened from an entity page
    if ($contextType !== '' && $contextId > 0) {
        $prompt .= "\n\nContext: The user opened this chat from a {$contextType} page (ID: {$contextId}). "
                 . "When relevant, focus your answers on this specific {$contextType}.";
    }

    return $prompt;
}
