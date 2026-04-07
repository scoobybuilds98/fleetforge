<?php
declare(strict_types=1);

/**
 * storage/tmp/ai_audit.php
 *
 * One-shot test harness for the entire AI subsystem.
 * Bypasses HTTP/auth — calls the endpoint logic directly so we can
 * capture pass/fail + response excerpts for each module.
 *
 * Run: php storage/tmp/ai_audit.php [module]
 *   modules: chat, stream, summary, report, visual, all (default: all)
 */

chdir(dirname(__DIR__, 2));

require_once 'config/app.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'lib/AI/TokenTracker.php';
require_once 'lib/AI/ClaudeClient.php';
require_once 'lib/AI/Tools/FleetForgeTools.php';
require_once 'lib/AI/ToolRegistry.php';
require_once 'lib/AI/SummaryEngine.php';

use FleetForge\AI\ClaudeClient;
use FleetForge\AI\ToolRegistry;
use FleetForge\AI\SummaryEngine;

$module = $argv[1] ?? 'all';

// ────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────
function hr(string $title): void {
    echo "\n", str_repeat('═', 78), "\n  ", $title, "\n", str_repeat('═', 78), "\n";
}
function sub(string $title): void {
    echo "\n── ", $title, " ", str_repeat('─', max(0, 70 - mb_strlen($title))), "\n";
}
function excerpt(string $s, int $n = 220): string {
    $s = preg_replace('/\s+/', ' ', trim($s));
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 3) . '...' : $s;
}

// ────────────────────────────────────────────────────────────
// runChatLoop() — replicates api/v1/ai/chat.php loop
// ────────────────────────────────────────────────────────────
function runChatLoop(string $message, string $systemPrompt, array $tools, string $queryType): array {
    $ai = new ClaudeClient();
    $messages = [['role' => 'user', 'content' => $message]];
    $iter = 0;
    $maxIter = ClaudeClient::MAX_TOOL_ITERATIONS;
    $response = null;
    $totalTokens = 0;
    $toolsUsed = [];
    $apiError = null;
    $start = microtime(true);

    while ($iter < $maxIter) {
        $response = $ai->sendMessage(
            messages: $messages,
            systemPrompt: $systemPrompt,
            tools: $tools,
            maxTokens: 2048,
            userId: null,
            queryType: $queryType
        );

        if ($response === null) {
            $apiError = 'sendMessage returned null (see logs/ai.log)';
            break;
        }

        $totalTokens += ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

        if (!ClaudeClient::hasToolUse($response)) break;

        $blocks = ClaudeClient::extractToolUseBlocks($response);
        $messages[] = [
            'role' => 'assistant',
            'content' => ClaudeClient::normalizeContentForResend($response['content']),
        ];
        $results = [];
        foreach ($blocks as $b) {
            $toolsUsed[] = $b['name'];
            $r = ToolRegistry::execute($b['name'], $b['input'] ?? [], null);
            $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => $r];
        }
        $messages[] = ['role' => 'user', 'content' => $results];
        $iter++;
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    $text = $response ? ClaudeClient::extractTextContent($response) : '';

    return [
        'ok' => $apiError === null && $text !== '',
        'text' => $text,
        'tokens' => $totalTokens,
        'tools' => $toolsUsed,
        'iterations' => $iter,
        'latency_ms' => $latency,
        'error' => $apiError,
    ];
}

// ────────────────────────────────────────────────────────────
// runStreamLoop() — replicates api/v1/ai/stream.php loop
// (captures streamed text deltas to verify streaming actually works)
// ────────────────────────────────────────────────────────────
function runStreamLoop(string $message, string $systemPrompt, array $tools, string $queryType): array {
    $ai = new ClaudeClient();
    $messages = [['role' => 'user', 'content' => $message]];
    $iter = 0;
    $maxIter = ClaudeClient::MAX_TOOL_ITERATIONS;
    $finalText = '';
    $streamedTokens = '';
    $totalTokens = 0;
    $toolsUsed = [];
    $apiError = null;
    $start = microtime(true);
    $response = null;

    // WHY: Mirror production fix — every iteration uses sendMessageStreaming with a
    // shared onChunk that accumulates into BOTH $streamedTokens (what client sees) and
    // $finalText (what gets saved to DB).
    $onChunk = function (string $t) use (&$streamedTokens, &$finalText) {
        $streamedTokens .= $t;
        $finalText .= $t;
    };

    while ($iter < $maxIter) {
        $response = $ai->sendMessageStreaming(
            messages: $messages,
            systemPrompt: $systemPrompt,
            tools: $tools,
            maxTokens: 2048,
            userId: null,
            queryType: $queryType,
            onChunk: $onChunk
        );

        if ($response === null) { $apiError = 'sendMessageStreaming returned null'; break; }
        $totalTokens += ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

        if (!ClaudeClient::hasToolUse($response)) break;

        $blocks = ClaudeClient::extractToolUseBlocks($response);
        $messages[] = [
            'role' => 'assistant',
            'content' => ClaudeClient::normalizeContentForResend($response['content']),
        ];
        $results = [];
        foreach ($blocks as $b) {
            $toolsUsed[] = $b['name'];
            $r = ToolRegistry::execute($b['name'], $b['input'] ?? [], null);
            $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => $r];
        }
        $messages[] = ['role' => 'user', 'content' => $results];
        $iter++;
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    return [
        'ok' => $apiError === null && $finalText !== '',
        'final_text' => $finalText,
        'streamed_to_client' => $streamedTokens,
        'tokens' => $totalTokens,
        'tools' => $toolsUsed,
        'iterations' => $iter,
        'latency_ms' => $latency,
        'error' => $apiError,
    ];
}

// ────────────────────────────────────────────────────────────
// MODULE 1 — chat.php (non-streaming) with widget tools
// ────────────────────────────────────────────────────────────
if ($module === 'all' || $module === 'chat') {
    hr('MODULE 1 — chat.php (non-streaming, widget endpoint)');
    $tools = ToolRegistry::getTools('chat');
    $sys = "You are FleetForge AI for a leasing company. Use tools to answer concisely (1-2 sentences).";

    $prompts = [
        'How many trailers do we have in our fleet?',
        'Show me all overdue invoices',
        'List currently active leases',
        'Are any compliance documents expiring in the next 30 days?',
        'What are the dashboard KPIs?',
        'Search for customer ABC',
    ];

    foreach ($prompts as $i => $p) {
        sub("Prompt " . ($i + 1) . ": " . $p);
        $r = runChatLoop($p, $sys, $tools, 'audit_chat');
        echo "  status     : " . ($r['ok'] ? 'OK' : 'FAIL') . "\n";
        echo "  iterations : {$r['iterations']}\n";
        echo "  tokens     : {$r['tokens']}\n";
        echo "  latency_ms : {$r['latency_ms']}\n";
        echo "  tools used : " . (empty($r['tools']) ? '(none)' : implode(', ', $r['tools'])) . "\n";
        if ($r['error']) echo "  ERROR      : {$r['error']}\n";
        echo "  response   : " . excerpt($r['text']) . "\n";
    }
}

// ────────────────────────────────────────────────────────────
// MODULE 2 — stream.php (streaming) with chat tools
// ────────────────────────────────────────────────────────────
if ($module === 'all' || $module === 'stream') {
    hr('MODULE 2 — stream.php (SSE streaming, /ai page)');
    $tools = ToolRegistry::getTools('chat');
    $sys = "You are FleetForge AI for a leasing company. Use tools to answer concisely (1-2 sentences).";

    $prompts = [
        'How many trailers do we have in our fleet?',
        'Show me all overdue invoices',
        'List currently active leases',
        'Are any compliance documents expiring in the next 30 days?',
        'What are the dashboard KPIs?',
        'Tell me about customer 1',
    ];

    foreach ($prompts as $i => $p) {
        sub("Prompt " . ($i + 1) . ": " . $p);
        $r = runStreamLoop($p, $sys, $tools, 'audit_stream');
        echo "  status            : " . ($r['ok'] ? 'OK' : 'FAIL') . "\n";
        echo "  iterations        : {$r['iterations']}\n";
        echo "  tokens            : {$r['tokens']}\n";
        echo "  latency_ms        : {$r['latency_ms']}\n";
        echo "  tools used        : " . (empty($r['tools']) ? '(none)' : implode(', ', $r['tools'])) . "\n";
        if ($r['error']) echo "  ERROR             : {$r['error']}\n";
        $streamLen = mb_strlen($r['streamed_to_client']);
        $finalLen = mb_strlen($r['final_text']);
        $missing = $finalLen - $streamLen;
        echo "  client_streamed   : {$streamLen} chars (this is what client UI shows)\n";
        echo "  final_db_text     : {$finalLen} chars (this is saved to DB)\n";
        if ($missing > 50) {
            echo "  *** UI BUG ***    : {$missing} chars never reached client (lost between iterations)\n";
        }
        echo "  streamed excerpt  : " . excerpt($r['streamed_to_client'], 180) . "\n";
        echo "  final excerpt     : " . excerpt($r['final_text'], 180) . "\n";
    }
}

// ────────────────────────────────────────────────────────────
// MODULE 3 — summary.php (SummaryEngine, no tools, 1 round-trip)
// ────────────────────────────────────────────────────────────
if ($module === 'all' || $module === 'summary') {
    hr('MODULE 3 — summary.php (SummaryEngine — entity summaries)');
    // WHY: IDs chosen from non-soft-deleted records (deleted_at IS NULL).
    $cases = [
        ['customer',       1, 'customer_insights'],
        ['customer',       4, 'payment_risk'],
        ['lease',          2, 'lease_summary'],
        ['lease',          3, 'lease_summary'],
        ['equipment_unit', 4, 'unit_analysis'],
        ['fleet',          0, 'fleet_health'],
    ];
    foreach ($cases as $i => [$type, $id, $st]) {
        sub("Case " . ($i + 1) . ": entity={$type}#{$id} type={$st}");
        $start = microtime(true);
        $result = SummaryEngine::generate($type, $id, $st, null, true); // forceRefresh=true
        $latency = (int) round((microtime(true) - $start) * 1000);
        if ($result === null) {
            echo "  status   : FAIL (returned null — see logs/ai.log)\n";
            echo "  latency  : {$latency}ms\n";
            continue;
        }
        echo "  status   : OK\n";
        echo "  cached   : " . ($result['cached'] ? 'yes' : 'no') . "\n";
        echo "  latency  : {$latency}ms\n";
        echo "  summary  : " . excerpt($result['summary'], 280) . "\n";
    }
}

// ────────────────────────────────────────────────────────────
// MODULE 4 — report.php (report context tools, plain markdown)
// ────────────────────────────────────────────────────────────
if ($module === 'all' || $module === 'report') {
    hr('MODULE 4 — report.php (natural language reports)');
    $tools = ToolRegistry::getTools('report');
    $today = date('Y-m-d');
    $sys = "You are FleetForge AI Report Generator. Current date: {$today}. Use tools to query real data. Use markdown.";

    $queries = [
        'Generate a Q1 2026 revenue report',
        'Give me the AR aging breakdown',
        'Show all overdue invoices grouped by customer',
        'What is our fleet utilization right now?',
        'Show payment summary for the last 30 days',
        'Which compliance documents expire in the next 60 days?',
    ];

    foreach ($queries as $i => $q) {
        sub("Query " . ($i + 1) . ": " . $q);
        $r = runChatLoop($q, $sys, $tools, 'audit_report');
        echo "  status     : " . ($r['ok'] ? 'OK' : 'FAIL') . "\n";
        echo "  iterations : {$r['iterations']}\n";
        echo "  tokens     : {$r['tokens']}\n";
        echo "  tools used : " . (empty($r['tools']) ? '(none)' : implode(', ', $r['tools'])) . "\n";
        if ($r['error']) echo "  ERROR      : {$r['error']}\n";
        echo "  report     : " . excerpt($r['text'], 320) . "\n";
    }
}

// ────────────────────────────────────────────────────────────
// MODULE 5 — generate-visual.php (chart/table block parsing)
// ────────────────────────────────────────────────────────────
if ($module === 'all' || $module === 'visual') {
    hr('MODULE 5 — generate-visual.php (chart + table generator)');
    $tools = ToolRegistry::getTools('report');
    $today = date('Y-m-d');
    // Truncated system prompt — the real one is long; same instructions are provided
    $sys = <<<P
You are FleetForge AI Visualization Generator. Current date: {$today}.
Use tools to query real data. After gathering data, embed charts/tables using these EXACT delimiters:

:::chart
{"type":"bar","title":"...","categories":[...],"series":[{"name":"...","data":[...]}],"value_format":"currency"}
:::

:::table
{"title":"...","headers":[...],"rows":[[...]]}
:::

Always include at least one chart. Keep analysis to 2-4 sentences. Never fabricate numbers.
P;

    $prompts = [
        'Show fleet status as a pie chart',
        'Revenue trend by month for Q1 2026 as a line chart',
        'AR aging buckets as a bar chart with a supporting table',
        'Top customers by revenue this month as a horizontal bar chart',
        'Active leases distribution by status as a donut',
        'Maintenance summary as a table',
    ];

    foreach ($prompts as $i => $p) {
        sub("Prompt " . ($i + 1) . ": " . $p);
        $r = runChatLoop($p, $sys, $tools, 'audit_visual');
        echo "  status     : " . ($r['ok'] ? 'OK' : 'FAIL') . "\n";
        echo "  iterations : {$r['iterations']}\n";
        echo "  tokens     : {$r['tokens']}\n";
        echo "  tools used : " . (empty($r['tools']) ? '(none)' : implode(', ', $r['tools'])) . "\n";
        if ($r['error']) echo "  ERROR      : {$r['error']}\n";
        // Count visual blocks parsed using the same regex as the endpoint
        $segs = preg_split('/:::(chart|table)\s*\n/i', $r['text'], -1, PREG_SPLIT_DELIM_CAPTURE);
        $charts = 0; $tables = 0;
        for ($j = 0; $j < count($segs); $j++) {
            if (strtolower($segs[$j]) === 'chart') $charts++;
            if (strtolower($segs[$j]) === 'table') $tables++;
        }
        echo "  visuals    : charts={$charts} tables={$tables}\n";
        echo "  excerpt    : " . excerpt($r['text'], 280) . "\n";
    }
}

echo "\n", str_repeat('═', 78), "\n  AUDIT COMPLETE\n", str_repeat('═', 78), "\n";
