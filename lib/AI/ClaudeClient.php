<?php
declare(strict_types=1);

namespace FleetForge\AI;

/**
 * lib/AI/ClaudeClient.php
 *
 * Core Anthropic Messages API client for FleetForge.
 * All AI features route through this single class.
 *
 * Follows the same patterns as lib/GPS/SamsaraClient.php:
 *   - curl-based HTTP, no external SDK dependency
 *   - Dev mode: returns null when API key is blank
 *   - Non-blocking: logs failures, never throws to callers
 *   - Token tracking: every call logged to ai_query_log
 *
 * Usage:
 *   $ai = new ClaudeClient();
 *   if (!$ai->isEnabled()) return;
 *   $response = $ai->sendMessage($messages, $system, $tools, 4096, $userId);
 *
 * Credentials lookup order [INT-1]:
 *   1. settings table FIRST  — settings_get('ai.anthropic_api_key')
 *   2. .env file  SECOND     — env('AI_ANTHROPIC_API_KEY')
 * Same for the enabled toggle, model, and daily token limit.
 *
 * @depends config/app.php (AI_ANTHROPIC_API_KEY, AI_ENABLED, AI_DAILY_TOKEN_LIMIT)
 * @session S026, S028 (INT-1)
 */
class ClaudeClient
{
    /** Anthropic Messages API endpoint */
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** API version header — required by Anthropic */
    private const API_VERSION = '2023-06-01';

    /** HTTP timeout for standard requests (seconds) */
    private const TIMEOUT_SECONDS = 120;

    /** Path to AI log file (relative to project root) */
    private const LOG_FILE = 'logs/ai.log';

    /** Max tool-use iterations to prevent infinite loops */
    public const MAX_TOOL_ITERATIONS = 5;

    private string $apiKey;
    private string $model;
    private bool $enabled;
    private string $projectRoot;

    public function __construct()
    {
        // INT-1: settings table FIRST, then .env fallback. The Settings →
        // Integrations UI saves into ai.* rows, so users can rotate the
        // key without redeploying. Empty/missing setting → fall through.
        $this->apiKey = (string) (
            settings_get('ai.anthropic_api_key')
            ?: env('AI_ANTHROPIC_API_KEY', '')
        );

        // ai.enabled is stored as '1'/'0' string in settings; coerce safely.
        $settingEnabled = settings_get('ai.enabled');
        if ($settingEnabled !== null && $settingEnabled !== '') {
            $this->enabled = (string) $settingEnabled === '1'
                          || strtolower((string) $settingEnabled) === 'true';
        } else {
            $this->enabled = (bool) env('AI_ENABLED', false);
        }

        $this->model = (string) (
            settings_get('ai.model')
            ?: env('AI_MODEL', 'claude-sonnet-4-20250514')
        );
        $this->projectRoot = dirname(__DIR__, 2); // lib/AI/ → project root
    }

    // ────────────────────────────────────────────────────────────
    // isEnabled()
    //
    // Returns true only when both conditions are met:
    //   1. AI is toggled on (env or settings)
    //   2. API key is configured (non-empty)
    // ────────────────────────────────────────────────────────────
    public function isEnabled(): bool
    {
        return $this->enabled && $this->apiKey !== '';
    }

    // ────────────────────────────────────────────────────────────
    // sendMessage()
    //
    // Sends a messages request to the Anthropic API and returns
    // the parsed response. Handles token tracking, daily limits,
    // and error logging.
    //
    // Does NOT automatically execute tool_use responses — the
    // caller decides whether to run tools and loop. This keeps
    // the client stateless and reusable across features.
    //
    // @param  array   $messages     Anthropic messages array [{role, content}]
    // @param  string  $systemPrompt System prompt ('' for none)
    // @param  array   $tools        Tool definitions (Anthropic format)
    // @param  int     $maxTokens    Max response tokens (default 4096)
    // @param  int|null $userId      Current user ID (for token tracking)
    // @param  string  $queryType    Label for ai_query_log (e.g. 'chat', 'summary')
    // @return array|null            Parsed API response, or null on failure
    // ────────────────────────────────────────────────────────────
    public function sendMessage(
        array   $messages,
        string  $systemPrompt = '',
        array   $tools = [],
        int     $maxTokens = 4096,
        ?int    $userId = null,
        string  $queryType = 'chat'
    ): ?array {
        if (!$this->isEnabled()) {
            $this->log('AI_DISABLED', 'AI features not enabled or API key missing.');
            return null;
        }

        // WHY: Check daily token limit before making the API call
        if ($userId !== null && !TokenTracker::canSpend($userId)) {
            $this->log('AI_LIMIT_REACHED', "User $userId hit daily token limit.");
            return null;
        }

        // Build request payload
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        $startTime = microtime(true);
        $response = $this->makeRequest($payload);
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($response === null) {
            return null;
        }

        // Log token usage to ai_query_log
        $usage = $response['usage'] ?? [];
        $promptTokens     = (int) ($usage['input_tokens'] ?? 0);
        $completionTokens = (int) ($usage['output_tokens'] ?? 0);
        $totalTokens      = $promptTokens + $completionTokens;

        // WHY: Cost estimate based on Claude Sonnet pricing (~$3/M input, ~$15/M output)
        $costUsd = ($promptTokens * 3.0 / 1_000_000) + ($completionTokens * 15.0 / 1_000_000);

        TokenTracker::record(
            userId:           $userId,
            queryType:        $queryType,
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            totalTokens:      $totalTokens,
            costUsd:          $costUsd,
            latencyMs:        $latencyMs,
            wasCached:        false
        );

        $this->log('AI_SUCCESS', sprintf(
            'type=%s user=%s tokens=%d (in=%d out=%d) cost=$%.4f latency=%dms model=%s',
            $queryType, $userId ?? 'system', $totalTokens,
            $promptTokens, $completionTokens, $costUsd, $latencyMs, $this->model
        ));

        return $response;
    }

    // ────────────────────────────────────────────────────────────
    // sendMessageStreaming()
    //
    // Streams a response via Server-Sent Events. Calls $onChunk
    // with each text delta as it arrives. Returns the final
    // complete response (including any tool_use blocks) when done.
    //
    // @param  array    $messages     Anthropic messages array
    // @param  string   $systemPrompt System prompt
    // @param  array    $tools        Tool definitions
    // @param  int      $maxTokens    Max response tokens
    // @param  int|null $userId       Current user ID
    // @param  string   $queryType    Label for logging
    // @param  callable $onChunk      fn(string $text) called per delta
    // @return array|null             Complete response after stream ends
    // ────────────────────────────────────────────────────────────
    public function sendMessageStreaming(
        array    $messages,
        string   $systemPrompt,
        array    $tools,
        int      $maxTokens,
        ?int     $userId,
        string   $queryType,
        callable $onChunk
    ): ?array {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($userId !== null && !TokenTracker::canSpend($userId)) {
            return null;
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
            'stream'     => true,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        $startTime = microtime(true);

        // WHY: For streaming we use a custom curl callback to process SSE events
        $ch = curl_init(self::API_URL);
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        // Accumulate the full response for post-processing
        $fullText       = '';
        $toolUseBlocks  = [];
        $inputTokens    = 0;
        $outputTokens   = 0;
        $stopReason     = '';
        $currentBlock   = null;

        // WHY: CURLOPT_WRITEFUNCTION processes each chunk as it arrives from Anthropic
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (
                &$fullText, &$toolUseBlocks, &$inputTokens, &$outputTokens,
                &$stopReason, &$currentBlock, $onChunk
            ): int {
                // Parse SSE events from the chunk
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, ':')) continue;

                    if (str_starts_with($line, 'data: ')) {
                        $json = substr($line, 6);
                        if ($json === '[DONE]') continue;

                        $event = json_decode($json, true);
                        if (!is_array($event)) continue;

                        $type = $event['type'] ?? '';

                        if ($type === 'message_start') {
                            $inputTokens = $event['message']['usage']['input_tokens'] ?? 0;
                        } elseif ($type === 'content_block_start') {
                            $currentBlock = $event['content_block'] ?? null;
                        } elseif ($type === 'content_block_delta') {
                            $delta = $event['delta'] ?? [];
                            if (($delta['type'] ?? '') === 'text_delta') {
                                $text = $delta['text'] ?? '';
                                $fullText .= $text;
                                $onChunk($text);
                            } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                                // Accumulate tool input JSON
                                if ($currentBlock && ($currentBlock['type'] ?? '') === 'tool_use') {
                                    $currentBlock['_partial_json'] = ($currentBlock['_partial_json'] ?? '') . ($delta['partial_json'] ?? '');
                                }
                            }
                        } elseif ($type === 'content_block_stop') {
                            if ($currentBlock && ($currentBlock['type'] ?? '') === 'tool_use') {
                                $input = json_decode($currentBlock['_partial_json'] ?? '{}', true) ?: [];
                                $toolUseBlocks[] = [
                                    'type'  => 'tool_use',
                                    'id'    => $currentBlock['id'] ?? '',
                                    'name'  => $currentBlock['name'] ?? '',
                                    'input' => $input,
                                ];
                            }
                            $currentBlock = null;
                        } elseif ($type === 'message_delta') {
                            $stopReason   = $event['delta']['stop_reason'] ?? '';
                            $outputTokens = $event['usage']['output_tokens'] ?? 0;
                        }
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($err !== '' || $code !== 200) {
            $this->log('AI_STREAM_ERROR', "HTTP=$code error=$err");
            return null;
        }

        $totalTokens = $inputTokens + $outputTokens;
        $costUsd = ($inputTokens * 3.0 / 1_000_000) + ($outputTokens * 15.0 / 1_000_000);

        TokenTracker::record(
            userId:           $userId,
            queryType:        $queryType,
            promptTokens:     $inputTokens,
            completionTokens: $outputTokens,
            totalTokens:      $totalTokens,
            costUsd:          $costUsd,
            latencyMs:        $latencyMs,
            wasCached:        false
        );

        // Build a normalized response matching the non-streaming format
        $content = [];
        if ($fullText !== '') {
            $content[] = ['type' => 'text', 'text' => $fullText];
        }
        foreach ($toolUseBlocks as $tb) {
            $content[] = $tb;
        }

        return [
            'role'        => 'assistant',
            'content'     => $content,
            'stop_reason' => $stopReason,
            'usage'       => [
                'input_tokens'  => $inputTokens,
                'output_tokens' => $outputTokens,
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────
    // testConnection()
    //
    // Verifies the Anthropic API key works by sending a minimal
    // request. Used by Settings → Integrations test button.
    //
    // @return array{success: bool, message: string, details: array}
    // ────────────────────────────────────────────────────────────
    public function testConnection(): array
    {
        // INT-1: explicit "not configured" message points the user at
        // the right place to fix it instead of a vague network error.
        if ($this->apiKey === '') {
            return [
                'success' => false,
                'message' => 'API key not configured. Please add your Anthropic API key in Settings → Integrations.',
                'details' => [],
            ];
        }

        // WHY: Make a raw curl call instead of using makeRequest() so we can
        // capture and surface the actual Anthropic error body (e.g. low credits,
        // invalid key, rate limit) rather than a generic "connection failed" message.
        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => 10,
            'messages'   => [['role' => 'user', 'content' => 'Hello']],
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return [
                'success' => false,
                'message' => 'Network error: ' . ($err ?: 'Could not reach Anthropic API.'),
                'details' => ['model' => $this->model],
            ];
        }

        $body = json_decode((string) $raw, true);

        if ($code !== 200) {
            // INT-1: extract Anthropic's own error message; fall back
            // to the most useful generic for the HTTP code.
            $anthropicMsg = $body['error']['message'] ?? null;
            if ($anthropicMsg) {
                $message = 'Anthropic API error: ' . $anthropicMsg;
            } elseif ($code === 401 || $code === 403) {
                $message = 'API key is invalid. Please check your Anthropic API key in Settings → Integrations.';
            } else {
                $message = "Connection failed (HTTP {$code}). Check API key.";
            }

            return [
                'success' => false,
                'message' => $message,
                'details' => ['http_code' => $code, 'model' => $this->model],
            ];
        }

        return [
            'success' => true,
            'message' => 'Connected to Anthropic API successfully.',
            'details' => [
                'model'  => $body['model'] ?? $this->model,
                'tokens' => ($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0),
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────
    // getModel() — returns the configured model name
    // ────────────────────────────────────────────────────────────
    public function getModel(): string
    {
        return $this->model;
    }

    // ────────────────────────────────────────────────────────────
    // extractTextContent()
    //
    // Helper to extract plain text from an API response's content
    // blocks. Filters out tool_use blocks, concatenates text blocks.
    //
    // @param  array $response  Full API response
    // @return string           Concatenated text content
    // ────────────────────────────────────────────────────────────
    public static function extractTextContent(array $response): string
    {
        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        return $text;
    }

    // ────────────────────────────────────────────────────────────
    // extractToolUseBlocks()
    //
    // Returns all tool_use content blocks from a response.
    //
    // @param  array $response  Full API response
    // @return array             Array of tool_use blocks
    // ────────────────────────────────────────────────────────────
    public static function extractToolUseBlocks(array $response): array
    {
        $blocks = [];
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

    // ────────────────────────────────────────────────────────────
    // hasToolUse()
    //
    // @param  array $response  Full API response
    // @return bool             True if response contains tool_use blocks
    // ────────────────────────────────────────────────────────────
    public static function hasToolUse(array $response): bool
    {
        return ($response['stop_reason'] ?? '') === 'tool_use';
    }

    // ────────────────────────────────────────────────────────────
    // normalizeContentForResend()
    //
    // Prepares assistant content blocks for being sent back to the
    // Anthropic API in a follow-up message (tool-calling loop).
    //
    // WHY: json_decode($json, true) decodes {} as [] (empty PHP array).
    // When re-encoded, [] becomes [] (JSON array), but Anthropic requires
    // tool_use.input to be a dictionary ({}). For zero-arg tools like
    // get_fleet_summary, Claude sends "input": {} which decodes to [],
    // and the API rejects the round-tripped message with:
    //   "messages.X.content.Y.tool_use.input: Input should be a valid dictionary"
    // Cast empty inputs to stdClass so they re-encode as {}.
    //
    // @param  array $content  Raw content blocks from API response
    // @return array           Same blocks, safe to re-send
    // ────────────────────────────────────────────────────────────
    public static function normalizeContentForResend(array $content): array
    {
        foreach ($content as &$block) {
            if (($block['type'] ?? '') !== 'tool_use') continue;
            $input = $block['input'] ?? null;
            if ($input === null || (is_array($input) && $input === [])) {
                $block['input'] = (object) [];
            }
        }
        return $content;
    }

    // ────────────────────────────────────────────────────────────
    // makeRequest() — raw HTTP POST to Anthropic API
    // Returns parsed JSON or null on any failure.
    // ────────────────────────────────────────────────────────────
    private function makeRequest(array $payload): ?array
    {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            $this->log('AI_CURL_ERROR', "error={$err}");
            return null;
        }

        if ($code !== 200) {
            $this->log('AI_HTTP_ERROR', "HTTP={$code} body=" . substr((string)$raw, 0, 500));
            return null;
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $this->log('AI_PARSE_ERROR', 'Invalid JSON response');
            return null;
        }

        return $data;
    }

    // ────────────────────────────────────────────────────────────
    // buildHeaders() — Anthropic API request headers
    // ────────────────────────────────────────────────────────────
    private function buildHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
        ];
    }

    // ────────────────────────────────────────────────────────────
    // log() — append timestamped line to logs/ai.log
    // Never throws — logging failure is swallowed silently.
    // ────────────────────────────────────────────────────────────
    private function log(string $level, string $message): void
    {
        try {
            $logPath = $this->projectRoot . '/' . self::LOG_FILE;
            $line    = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
            file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logging failure must never crash the application
        }
    }
}
