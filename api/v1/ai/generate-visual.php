<?php
declare(strict_types=1);

/**
 * api/v1/ai/generate-visual.php
 *
 * AI-powered visualization generator — user describes what they want
 * in plain English, Claude queries real data and returns analysis text
 * with embedded chart and table specifications.
 *
 * POST /api/v1/ai/generate-visual
 *   Body: { prompt: "Revenue by customer for Q1 2026 as a bar chart" }
 *   Response: {
 *     content:     "Full response with :::chart and :::table blocks",
 *     visuals:     [ {type:'chart'|'table', ...config} ],
 *     text:        "Markdown text with visual blocks stripped",
 *     tokens_used: N
 *   }
 *
 * Visual block format (embedded in Claude's response):
 *   :::chart
 *   {"type":"bar","title":"...","categories":[...],"series":[{"name":"...","data":[...]}],"value_format":"currency"}
 *   :::
 *
 *   :::table
 *   {"title":"...","headers":["Col1","Col2"],"rows":[["val1","val2"]]}
 *   :::
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

$prompt = trim($body['prompt'] ?? '');
if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Prompt is required']);
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

// ── Build visualization-specific system prompt ────────────────
$today = date('Y-m-d');
$systemPrompt = <<<PROMPT
You are FleetForge AI Visualization Generator. You create data-driven charts, tables, and analysis for a trailer and equipment leasing company.

Current date: {$today}
User: {$userName}

CRITICAL INSTRUCTIONS:
1. Use the available tools to query real data. NEVER fabricate numbers.
2. After gathering data, present your findings with embedded visualizations.
3. Write a brief analysis in markdown around the visuals.

VISUALIZATION FORMAT:
When presenting data visually, embed chart or table blocks using these exact markers:

For charts — use :::chart and ::: delimiters with a JSON object on a single line between them:
:::chart
{"type":"bar","title":"Revenue by Customer","categories":["Alpha Corp","Beta Inc","Gamma Ltd"],"series":[{"name":"Revenue","data":[45000,32000,28000]}],"value_format":"currency"}
:::

For tables — use :::table and ::: delimiters with a JSON object on a single line between them:
:::table
{"title":"Customer Revenue Detail","headers":["Customer","Revenue","Invoices","Avg Invoice"],"rows":[["Alpha Corp","$45,000.00","12","$3,750.00"],["Beta Inc","$32,000.00","8","$4,000.00"]]}
:::

CHART TYPES AVAILABLE:
- "bar" — comparison across categories (horizontal or grouped)
- "line" — trends over time
- "area" — trends with volume emphasis
- "pie" — proportional breakdown (use for 2-7 categories max)
- "donut" — same as pie with center label
- "radialBar" — progress/gauge style

CHART JSON FIELDS:
- type: (required) chart type
- title: (required) descriptive title
- categories: (required) array of x-axis labels
- series: (required) array of {name, data} — data array must match categories length
- value_format: "currency" | "number" | "percent" (controls axis/tooltip formatting)
- stacked: true|false (for bar/area charts, optional)
- colors: array of hex colors (optional — system defaults are used if omitted)

TABLE JSON FIELDS:
- title: (optional) table heading
- headers: (required) column name array
- rows: (required) array of arrays — each inner array is one row of string values
- footer: (optional) array of footer/total values — same length as headers

GUIDELINES:
- Always include at least one chart when data is visual in nature.
- Include a supporting data table when exact numbers matter.
- Choose the chart type that best fits the data (bar for comparisons, line for time series, pie for proportions).
- For time-series data, use categories like ["Jan 2026","Feb 2026","Mar 2026"].
- Limit pie/donut charts to 7 slices max — group small values as "Other".
- Format monetary values with $ and two decimals in tables.
- Keep analysis concise — 2-4 sentences of insight per visual.
- If the query is ambiguous, make reasonable assumptions and state them.
- If the user asks for something not in the data, explain what IS available.
PROMPT;

// WHY: Report context gives Claude financial and query-focused tools
$tools    = \FleetForge\AI\ToolRegistry::getTools('report');
$messages = [['role' => 'user', 'content' => $prompt]];

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
        queryType:    'report_visual'
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
    // WHY: normalizeContentForResend fixes empty tool_use.input ([] → {}) — without
    // this, the next API call crashes with "Input should be a valid dictionary" the
    // moment Claude emits a tool_use with no parameters.
    $messages[] = [
        'role'    => 'assistant',
        'content' => \FleetForge\AI\ClaudeClient::normalizeContentForResend($response['content']),
    ];

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

$fullContent = \FleetForge\AI\ClaudeClient::extractTextContent($response);

if ($fullContent === '') {
    $fullContent = 'Unable to generate visualization. Please try rephrasing your request.';
}

// ── Parse visual blocks from response ──────────────────────
// WHY: Extract :::chart and :::table blocks so frontend can render them
$visuals   = [];
$textParts = [];
$segments  = preg_split('/:::(chart|table)\s*\n/i', $fullContent, -1, PREG_SPLIT_DELIM_CAPTURE);

for ($i = 0; $i < count($segments); $i++) {
    $seg = $segments[$i];

    if (strtolower($seg) === 'chart' || strtolower($seg) === 'table') {
        $vType = strtolower($seg);
        // Next segment is the JSON + closing :::
        if (isset($segments[$i + 1])) {
            $jsonBlock = $segments[$i + 1];
            // Remove closing ::: delimiter
            $jsonBlock = preg_replace('/\n?:::\s*$/', '', $jsonBlock);
            // Also handle case where ::: is on its own line
            $jsonBlock = preg_replace('/\n?:::\s*/', '', $jsonBlock);
            $jsonBlock = trim($jsonBlock);

            $parsed = json_decode($jsonBlock, true);
            if (is_array($parsed)) {
                $parsed['_type'] = $vType;
                $visuals[] = $parsed;
                // Add placeholder in text so frontend knows where to render
                $textParts[] = '<!--visual:' . (count($visuals) - 1) . '-->';
            } else {
                // Failed to parse — keep as raw text
                $textParts[] = "```\n{$jsonBlock}\n```";
            }
            $i++; // Skip the JSON segment (already consumed)
        }
    } else {
        $textParts[] = $seg;
    }
}

$textOnly = trim(implode("\n", $textParts));

// ── Audit log ─────────────────────────────────────────────
try {
    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'ai_visual_generated',
        'entity_type' => 'report',
        'entity_id'   => 0,
        'details'     => json_encode([
            'prompt'  => mb_substr($prompt, 0, 200),
            'tokens'  => $totalTokens,
            'visuals' => count($visuals),
        ]),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
} catch (\Throwable) {
    // Non-fatal
}

echo json_encode([
    'content'     => $fullContent,
    'visuals'     => $visuals,
    'text'        => $textOnly,
    'tokens_used' => $totalTokens,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
