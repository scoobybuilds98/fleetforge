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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
}

// ── Parse input ────────────────────────────────────────────
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

$userId   = (int) ($_SESSION['ff_user']['id'] ?? 0);
$userName = $_SESSION['ff_user']['name'] ?? 'User';

// ── Initialize AI client ──────────────────────────────────
$ai = new \FleetForge\AI\ClaudeClient();
if (!$ai->isEnabled()) {
    // SAMSARA-3 error parity: distinguish "no key" from "disabled".
    // Pre-flight check fires before SSE headers are sent so we can
    // emit a regular JSON error response (the front-end uses
    // EventSource for SSE but a 4xx/5xx prevents the stream from
    // even starting — handled in the widget's `error` handler).
    $ai->sendMessage([['role' => 'user', 'content' => 'ping']], '', [], 1, $userId, 'preflight');
    \FleetForge\AI\ClaudeClient::emitErrorResponse($ai);
    exit;
}

if (!\FleetForge\AI\TokenTracker::canSpend($userId)) {
    json_error('TOKEN_LIMIT', 'Daily AI token limit reached. Try again tomorrow or raise the limit in settings.', 429);
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
        json_error('NOT_FOUND', 'Session not found', 404);
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
$iteration      = 0;
$maxIterations  = \FleetForge\AI\ClaudeClient::MAX_TOOL_ITERATIONS;
$finalText      = '';
$totalTokensAll = 0;

// WHY: Single shared onChunk used by EVERY iteration. Without this, post-tool-call
// answers (iter 1+) generate but never reach the client UI — the user reported
// "I'll get the overdue invoices for you, then nothing happened." That happened
// because the original code only streamed iter 0; the actual answer (iter 1) used
// non-streaming sendMessage and its text never made it onto the SSE channel.
$onChunk = function (string $text) use (&$finalText) {
    $finalText .= $text;
    sendSSE('token', ['text' => $text]);
};

while ($iteration < $maxIterations) {
    $response = $ai->sendMessageStreaming(
        messages:     $messages,
        systemPrompt: $systemPrompt,
        tools:        $tools,
        maxTokens:    4096,
        userId:       $userId,
        queryType:    'chat',
        onChunk:      $onChunk
    );

    if ($response === null) {
        // SAMSARA-3 error parity: read structured error from ClaudeClient
        // and surface a code + human message via SSE so the chat widget
        // can branch on `code` (e.g. show "Configure API key" inline).
        //
        // SEARCH-2 root-cause fix for "AI stops mid-response" bug:
        // When the request fails AFTER some text was already streamed
        // (typical 429 burst-rate-limit on iter 2+ of a tool chain),
        // we now:
        //   1. Save the partial $finalText to ai_chat_messages so the
        //      user can see it on chat reload — previously the partial
        //      streamed text was discarded entirely on error.
        //   2. Include the partial text in the error event payload so
        //      the widget can preserve what was already shown instead
        //      of vanishing it when the streaming indicator hides.
        //   3. Append a (cut off) marker so the user knows the response
        //      was incomplete.
        $err = $ai->getLastError();
        $msg = match ($err['code'] ?? null) {
            'NO_KEY'      => 'AI is not configured. Set your Anthropic API key in Settings → Integrations.',
            'DISABLED'    => 'AI features are disabled. Enable them in Settings → AI / Machine Learning.',
            'TOKEN_LIMIT' => 'Daily AI token limit reached. Try again tomorrow or raise the limit in settings.',
            'RATE_LIMIT'  => 'Hit the AI rate limit mid-response. ' . ($finalText !== '' ? 'Above is what we got so far — try again in a moment to continue.' : 'Please wait a minute and try again.'),
            'NETWORK'     => 'Could not reach Anthropic. Check your internet connection and try again.',
            'API_ERROR'   => 'Anthropic returned an error: ' . ($err['message'] ?? 'unknown'),
            'PARSE_ERROR' => 'Anthropic returned an invalid response. Please try again.',
            default       => 'AI service unavailable. Please try again.',
        };

        // Persist whatever was successfully streamed before the failure
        // so a refresh / reload still shows the partial answer rather
        // than dropping it on the floor.
        $partialMsgId = null;
        if ($finalText !== '') {
            $partialMsgId = db_insert('ai_chat_messages', [
                'session_id'  => $sessionId,
                'role'        => 'assistant',
                'content'     => $finalText . "\n\n_(Response cut off — " . $msg . ")_",
                'tokens_used' => $totalTokensAll,
            ]);
            db_update('ai_chat_sessions', [
                'last_message_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$sessionId]);
        }

        sendSSE('error', [
            'message'      => $msg,
            'code'         => $err['code'] ?? null,
            'partial_text' => $finalText,
            'session_id'   => $sessionId,
            'message_id'   => $partialMsgId,
        ]);
        exit;
    }

    $totalTokensAll += ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

    // WHY: If no tool calls, we're done — Claude's final text has already been
    // streamed via $onChunk, so $finalText is up-to-date.
    if (!\FleetForge\AI\ClaudeClient::hasToolUse($response)) {
        break;
    }

    // Execute tool calls
    $toolBlocks = \FleetForge\AI\ClaudeClient::extractToolUseBlocks($response);
    // WHY: normalizeContentForResend fixes empty tool_use.input ([] → {}) before re-sending.
    $messages[] = [
        'role'    => 'assistant',
        'content' => \FleetForge\AI\ClaudeClient::normalizeContentForResend($response['content']),
    ];

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

    $iteration++;
}

// If we hit the tool-iteration cap while Claude still wanted another tool, the
// chain was cut short — stream a notice and persist it with the message instead
// of returning a half-synthesized answer with no explanation. S-AI-AUDIT-HIGH-FIX.
if ($iteration >= $maxIterations && \FleetForge\AI\ClaudeClient::hasToolUse($response)) {
    $onChunk("\n\n_(Stopped after {$maxIterations} data-lookup steps — please ask a narrower question for a complete answer.)_");
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

    if ($contextType !== '' && $contextId > 0) {
        $prompt .= "\n\nContext: The user opened this chat from a {$contextType} page (ID: {$contextId}). "
                 . "When relevant, focus your answers on this specific {$contextType}.";
    }

    return $prompt;
}
