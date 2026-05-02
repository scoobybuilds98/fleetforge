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
    json_error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_error('INVALID_JSON', 'Invalid JSON body', 400);
}

$messageText = trim($body['message'] ?? '');
$sessionId   = (int) ($body['session_id'] ?? 0);
$contextType = trim($body['context_type'] ?? '');
$contextId   = (int) ($body['context_id'] ?? 0);

if ($messageText === '') {
    json_error('VALIDATION_ERROR', 'Message is required', 400);
}

// ── Initialize AI client ──────────────────────────────────
// Pre-flight checks: distinguishes "not enabled" from "no key"
// from "token limit hit" so the UI can branch on the code.
// emitErrorResponse() does the http_response_code + JSON write,
// we just need to call exit; afterwards. Done via setError() inside
// the client itself when sendMessage() runs, but we mirror the
// pre-flight checks here so the user gets an immediate message
// instead of going through a no-op call to Claude.
$ai = new \FleetForge\AI\ClaudeClient();
if (!$ai->isEnabled()) {
    // Trigger setError() by attempting a no-op call so getLastError() is populated
    $ai->sendMessage([['role' => 'user', 'content' => 'ping']], '', [], 1, $userId, 'preflight');
    \FleetForge\AI\ClaudeClient::emitErrorResponse($ai);
    exit;
}

// ── Check daily token limit ────────────────────────────────
if (!\FleetForge\AI\TokenTracker::canSpend($userId)) {
    json_error('TOKEN_LIMIT', 'Daily AI token limit reached. Try again tomorrow or raise the limit in settings.', 429);
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
        json_error('NOT_FOUND', 'Chat session not found', 404);
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
//
// SEARCH-2 fix: We accumulate ALL text fragments across iterations into
// $accumulatedText, not just the final iteration. Previously we extracted
// text only from the LAST $response — so any "Let me check..." preambles
// from earlier iterations were silently thrown away. When the loop fails
// part-way through (typically a 429 burst rate limit on iter 2-4), the
// accumulated text is now persisted to the DB so the user sees the partial
// answer instead of nothing.
$iteration = 0;
$maxIterations = \FleetForge\AI\ClaudeClient::MAX_TOOL_ITERATIONS;
$response = null;
$accumulatedText = '';
$totalTokensAll  = 0;

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
        // SEARCH-2: persist whatever Claude said in earlier iterations
        // before failing, so the user sees the partial answer rather
        // than the response disappearing entirely.
        if ($accumulatedText !== '') {
            $err  = $ai->getLastError();
            $note = match ($err['code'] ?? null) {
                'RATE_LIMIT' => 'Response cut off — AI rate limit hit mid-response. Try again in a moment.',
                'NETWORK'    => 'Response cut off — could not reach the AI service.',
                default      => 'Response cut off — ' . ($err['message'] ?? 'AI service error') . '.',
            };
            $partialMsgId = db_insert('ai_chat_messages', [
                'session_id'  => $sessionId,
                'role'        => 'assistant',
                'content'     => $accumulatedText . "\n\n_(" . $note . ")_",
                'tokens_used' => $totalTokensAll,
            ]);
            db_update('ai_chat_sessions', [
                'last_message_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$sessionId]);
            // Return 200 with the partial content so the widget renders
            // it the same way as a successful response. The content
            // already explains it was cut off.
            echo json_encode([
                'session_id'  => $sessionId,
                'message_id'  => $partialMsgId,
                'content'     => $accumulatedText . "\n\n_(" . $note . ")_",
                'tokens_used' => $totalTokensAll,
                'partial'     => true,
            ]);
            exit;
        }
        \FleetForge\AI\ClaudeClient::emitErrorResponse($ai);
        exit;
    }

    // SEARCH-2: capture any text in this response BEFORE checking tool_use
    // so preambles like "Let me check..." aren't lost when Claude chains
    // tool calls.
    $iterText = \FleetForge\AI\ClaudeClient::extractTextContent($response);
    if ($iterText !== '') {
        $accumulatedText .= ($accumulatedText === '' ? '' : "\n\n") . $iterText;
    }
    $totalTokensAll += ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

    // WHY: If Claude doesn't want to use a tool, we're done
    if (!\FleetForge\AI\ClaudeClient::hasToolUse($response)) {
        break;
    }

    // Execute each tool_use block and build tool_result messages
    $toolBlocks = \FleetForge\AI\ClaudeClient::extractToolUseBlocks($response);

    // WHY: Append Claude's response (with tool_use blocks) to messages.
    // normalizeContentForResend() fixes empty tool_use.input ([] → {}) — see
    // ClaudeClient::normalizeContentForResend() for the underlying PHP/JSON gotcha.
    $messages[] = [
        'role'    => 'assistant',
        'content' => \FleetForge\AI\ClaudeClient::normalizeContentForResend($response['content']),
    ];

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
// SEARCH-2: prefer the accumulated text (which captures every iteration)
// over single-iteration extractTextContent so chained-tool-call answers
// don't lose their preambles.
$assistantText = $accumulatedText !== ''
    ? $accumulatedText
    : \FleetForge\AI\ClaudeClient::extractTextContent($response);

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
You are FleetForge AI, an intelligent assistant for a trailer and equipment leasing company. You help the team manage their fleet, customers, leases, invoices, payments, accounting, maintenance, damage claims, inspections, and reservations.

Current date: {$today}
User: {$userName}

Your capabilities (use the matching tool — never guess):
- Customers — search_customers, get_customer_details, get_customer_leases, get_customer_invoices
- Equipment / fleet — get_fleet_summary, search_equipment, get_equipment_unit, get_yard_inventory, get_yards
- Leases & reservations — get_active_leases, get_lease_details, get_reservations, get_reservation_details
- Invoicing & AR — get_revenue_by_period, get_revenue_by_customer, get_overdue_invoices, get_ar_aging, get_payment_summary, get_recent_payments, get_credit_notes
- Rates & pricing — get_rate_cards, get_rate_card_items, get_customer_rates
- Maintenance & inspections — get_maintenance_summary, get_inspections, get_inspection_details
- Damage & mileage — get_damage_claims, get_damage_claim_details, get_mileage_logs
- Vendors & AP — search_vendors, get_vendor_details, get_vendor_bills, get_ap_aging
- Accounting (GL/banking/tax) — get_chart_of_accounts, get_journal_entries, get_trial_balance, get_account_balance, get_bank_accounts, get_bank_transactions, get_tax_filing_periods, get_accounting_periods, get_budgets
- Fixed assets & payoff — get_fixed_assets, get_fixed_asset_details, get_payoff_analysis, get_depreciation_summary, get_capex_requests
- Collections — get_promise_to_pay, get_collection_notes
- Compliance — get_expiring_documents
- Dashboard — get_dashboard_kpis, get_fleet_summary

Identifier patterns (very important):
- Equipment unit numbers look like CHS-001, RFR-002, FLT-001, DRY-014, CON-003 — these are EQUIPMENT UNITS (chassis, reefer, flatbed, dry van, container), NOT customers. Use get_equipment_unit, search_equipment, get_payoff_analysis, or get_fixed_asset_details with the unit_number parameter.
- Customer names are company names like "Acme Logistics" — use search_customers.
- Fixed asset numbers look like FA-2026-00007 — use get_fixed_asset_details.
- Invoice numbers look like INV-2026-... and lease numbers like LSE-2026-...
- If a user asks how long a unit (e.g. "CHS-001") will take to pay off, call get_payoff_analysis with unit_number — do NOT search customers first.

Guidelines:
- Always use the available tools to look up real data before answering questions. Never guess or make up data.
- If your first tool call returns "no results", consider whether the user meant a different entity type (unit vs customer vs asset) and retry with the right tool.
- Be concise but thorough. Use bullet points and tables when presenting multiple data points.
- When discussing financial data, always include the currency (CAD/USD) and format monetary values with dollar signs and two decimal places.
- If a question is outside your capabilities, say so clearly.
- If a tool returns an error, explain it to the user helpfully and suggest what to try next.
- When referencing dates, use a clear format like "January 15, 2026".
PROMPT;

    // WHY: Add context-specific instructions when chat is opened from an entity page
    if ($contextType !== '' && $contextId > 0) {
        $prompt .= "\n\nContext: The user opened this chat from a {$contextType} page (ID: {$contextId}). "
                 . "When relevant, focus your answers on this specific {$contextType}.";
    }

    return $prompt;
}
