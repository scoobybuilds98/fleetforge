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

    /**
     * Bounded retry on a transient 429 for the non-streaming path. The daily
     * AI brief crons run unattended, so a single rate-limit blip must not skip
     * the whole brief — mirror the streaming path's Retry-After retry. Capped
     * so total added latency (MAX_RETRIES_429 × RETRY_AFTER_CAP_SECONDS) stays
     * well under TIMEOUT_SECONDS.
     */
    public const MAX_RETRIES_429        = 2;
    public const RETRY_AFTER_CAP_SECONDS = 30;

    /** Path to AI log file (relative to project root) */
    private const LOG_FILE = 'logs/ai.log';

    /** Max tool-use iterations to prevent infinite loops */
    public const MAX_TOOL_ITERATIONS = 5;

    private string $apiKey;
    private string $model;
    private bool $enabled;
    private string $projectRoot;

    /**
     * Last failure reason, set whenever sendMessage/sendMessageStreaming
     * /makeRequest returns null. Consumers call getLastError() to
     * surface a human-readable, mode-specific error to the UI instead
     * of the generic "AI service unavailable" blanket message.
     *
     * Shape: ['code' => string, 'message' => string, 'http_code' => ?int]
     * Codes: NO_KEY | DISABLED | TOKEN_LIMIT | RATE_LIMIT |
     *        NETWORK  | API_ERROR | PARSE_ERROR
     */
    private ?array $lastError = null;

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
            ?: env('AI_MODEL', 'claude-sonnet-4-6')
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
    // getLastError()
    //
    // Returns the structured failure reason for the most recent
    // null-returning call. Endpoints use this to map failure modes
    // to user-facing error messages instead of showing the generic
    // "AI service unavailable" blanket. Returns null if the last
    // call succeeded or no call has been made yet.
    //
    // Shape: ['code' => string, 'message' => string, 'http_code' => ?int]
    // Codes:
    //   NO_KEY       — API key is empty in both settings and .env
    //   DISABLED     — ai.enabled toggle is off
    //   TOKEN_LIMIT  — per-user daily token budget exhausted
    //   RATE_LIMIT   — Anthropic API returned HTTP 429
    //   NETWORK      — curl error / timeout / DNS failure
    //   API_ERROR    — Anthropic API returned non-200 (not 429)
    //   PARSE_ERROR  — Anthropic response body was not valid JSON
    // ────────────────────────────────────────────────────────────
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    // Internal helper so every null-returning code path records
    // the same structured failure reason. Also clears on success.
    private function setError(string $code, string $message, ?int $httpCode = null): void
    {
        $this->lastError = [
            'code'      => $code,
            'message'   => $message,
            'http_code' => $httpCode,
        ];
    }

    private function clearError(): void
    {
        $this->lastError = null;
    }

    // ────────────────────────────────────────────────────────────
    // emitErrorResponse()
    //
    // Helper for HTTP endpoints — call this when sendMessage()
    // returns null. Reads getLastError() and emits a JSON error
    // response with the right HTTP status + a human-readable
    // message keyed off the failure code. Always exits/returns;
    // caller still has to call exit; for safety.
    //
    // Codes → HTTP status + user-facing message:
    //   NO_KEY      → 503 "AI is not configured. Set your Anthropic
    //                       API key in Settings → Integrations."
    //   DISABLED    → 503 "AI features are disabled. Enable in
    //                       Settings → AI / Machine Learning."
    //   TOKEN_LIMIT → 429 "Daily AI token limit reached. Try again
    //                       tomorrow or raise the limit in settings."
    //   RATE_LIMIT  → 429 "Too many requests. Wait a minute and
    //                       try again."
    //   NETWORK     → 502 "Could not reach Anthropic. Check your
    //                       internet and try again."
    //   API_ERROR   → 502 "Anthropic returned an error: {message}"
    //   PARSE_ERROR → 502 "Anthropic returned an invalid response."
    //   (no error)  → 502 "AI service unavailable."  (legacy fallback)
    //
    // The response body always contains {error, message, code} so
    // the front-end can branch on `code` for inline UI handling
    // (e.g. show a "Configure API key" button for NO_KEY).
    // ────────────────────────────────────────────────────────────
    public static function emitErrorResponse(self $client): void
    {
        $err  = $client->getLastError();
        $code = $err['code'] ?? null;

        $statusMap = [
            'NO_KEY'       => 503,
            'DISABLED'     => 503,
            'TOKEN_LIMIT'  => 429,
            'RATE_LIMIT'   => 429,
            'NETWORK'      => 502,
            'API_ERROR'    => 502,
            'PARSE_ERROR'  => 502,
        ];
        $messageMap = [
            'NO_KEY'      => 'AI is not configured. Set your Anthropic API key in Settings → Integrations.',
            'DISABLED'    => 'AI features are disabled. Enable them in Settings → AI / Machine Learning.',
            'TOKEN_LIMIT' => 'Daily AI token limit reached. Try again tomorrow or raise the limit in settings.',
            'RATE_LIMIT'  => 'Too many requests. Please wait a minute and try again.',
            'NETWORK'     => 'Could not reach Anthropic. Check your internet connection and try again.',
            'API_ERROR'   => 'Anthropic returned an error: ' . ($err['message'] ?? 'unknown'),
            'PARSE_ERROR' => 'Anthropic returned an invalid response. Please try again.',
        ];

        $httpStatus = $statusMap[$code]   ?? 502;
        $userMsg    = $messageMap[$code]  ?? 'AI service unavailable. Please try again.';

        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'error'   => true,
            'message' => $userMsg,
            'code'    => $code,
        ]);
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
        $this->clearError();

        if (!$this->enabled) {
            $this->setError('DISABLED', 'AI features are disabled in settings.');
            $this->log('AI_DISABLED', 'AI features toggled off.');
            return null;
        }
        if ($this->apiKey === '') {
            $this->setError('NO_KEY', 'Anthropic API key is not configured.');
            $this->log('AI_NO_KEY', 'Anthropic API key missing from settings and .env.');
            return null;
        }

        // WHY: Check daily token limit before making the API call
        if ($userId !== null && !TokenTracker::canSpend($userId)) {
            $this->setError('TOKEN_LIMIT', 'Daily AI token budget exhausted.');
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
        $this->clearError();

        if (!$this->enabled) {
            $this->setError('DISABLED', 'AI features are disabled in settings.');
            return null;
        }
        if ($this->apiKey === '') {
            $this->setError('NO_KEY', 'Anthropic API key is not configured.');
            return null;
        }

        if ($userId !== null && !TokenTracker::canSpend($userId)) {
            $this->setError('TOKEN_LIMIT', 'Daily AI token budget exhausted.');
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
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        // SEARCH-1 follow-up SEARCH-2: One-shot retry on 429 to absorb the
        // most common failure mode — Anthropic's 30K-input-tokens-per-minute
        // organisation rate limit. We honour the Retry-After header (capped
        // at 30s so the request doesn't hang the SSE channel forever).
        // The retry only fires when NO text has been streamed yet for THIS
        // particular request — once a single token has been emitted via
        // $onChunk we can't safely re-issue the request without showing the
        // user duplicated text.
        $maxRetries = 1;
        $attempt    = 0;

        retry_loop:

        // Accumulate the full response for post-processing. Reset on each
        // attempt because a 429 retry restarts streaming from scratch.
        $fullText       = '';
        $toolUseBlocks  = [];
        $inputTokens    = 0;
        $outputTokens   = 0;
        $stopReason     = '';
        $currentBlock   = null;
        $responseHeaders = [];

        // WHY: For streaming we use a custom curl callback to process SSE events
        $ch = curl_init(self::API_URL);

        // WHY: CURLOPT_WRITEFUNCTION processes each chunk as it arrives from Anthropic
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RETURNTRANSFER => false,
            // SEARCH-2: capture response headers so we can read Retry-After
            // when Anthropic returns 429. Headers are appended one per call.
            CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $parts = explode(':', trim($headerLine), 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
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
            // SEARCH-2: one-shot retry on 429 — only safe to retry if NO text
            // was streamed yet (otherwise the user would see duplicated text).
            // Honours Retry-After header from Anthropic (capped at 30s).
            if ($code === 429 && $fullText === '' && $attempt < $maxRetries) {
                $retryAfter = (int) ($responseHeaders['retry-after'] ?? 5);
                if ($retryAfter < 1)  $retryAfter = 1;
                if ($retryAfter > 30) $retryAfter = 30;
                $this->log('AI_STREAM_RETRY', "HTTP=429 sleeping {$retryAfter}s before retry (attempt " . ($attempt + 1) . "/{$maxRetries})");
                sleep($retryAfter);
                $attempt++;
                goto retry_loop;
            }

            // SAMSARA-3 error parity: streaming path now sets structured
            // error codes the same way the non-streaming sendMessage does,
            // so emitErrorResponse() / stream.php's match-statement gives
            // the right user-facing message instead of "AI service unavailable".
            //
            // We can't easily parse Anthropic's error.message field here
            // because the response body was consumed by the WRITEFUNCTION
            // chunked callback above (which expects SSE, not a single JSON
            // error blob). So we just key off the HTTP status / curl error
            // and emit a code; the endpoint maps that to a friendly message.
            if ($err !== '') {
                $this->setError('NETWORK', 'Could not reach Anthropic API: ' . $err);
            } elseif ($code === 429) {
                $retryAfter = (int) ($responseHeaders['retry-after'] ?? 0);
                $msg = $retryAfter > 0
                    ? "Anthropic rate limit hit. Try again in {$retryAfter}s."
                    : 'Anthropic rate limit hit. Wait a minute and try again.';
                $this->setError('RATE_LIMIT', $msg, 429);
            } else {
                $this->setError('API_ERROR', "Anthropic API returned HTTP {$code}.", $code);
            }
            $this->log('AI_STREAM_ERROR', "HTTP=$code error=$err attempt=$attempt");
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

        // Bounded retry on a transient 429, honoring Retry-After. Unlike the
        // streaming path this request is buffered, so a retry is always safe
        // (no half-streamed text to duplicate). Other failures fall straight
        // through to the error handling below on the first attempt.
        $attempt = 0;
        while (true) {
            $resp = $this->httpPost($jsonPayload);
            $raw  = $resp['body'];
            $code = $resp['code'];
            $err  = $resp['error'];

            if ($code === 429 && $err === '' && $attempt < self::MAX_RETRIES_429) {
                $retryAfter = (int) ($resp['headers']['retry-after'] ?? 1);
                if ($retryAfter < 1) $retryAfter = 1;
                if ($retryAfter > self::RETRY_AFTER_CAP_SECONDS) $retryAfter = self::RETRY_AFTER_CAP_SECONDS;
                $attempt++;
                $this->log('AI_HTTP_RETRY', "HTTP=429 sleeping {$retryAfter}s before retry (attempt {$attempt}/" . self::MAX_RETRIES_429 . ')');
                $this->sleepSeconds($retryAfter);
                continue;
            }
            break;
        }

        if ($raw === false || $err !== '') {
            $this->setError('NETWORK', 'Could not reach Anthropic API: ' . ($err ?: 'unknown network error'));
            $this->log('AI_CURL_ERROR', "error={$err}");
            return null;
        }

        if ($code !== 200) {
            // 429 = rate-limit, distinguished from other API errors so the
            // UI can suggest "wait a minute and try again" rather than the
            // generic "service unavailable" message. Body usually contains
            // a human-readable error.message field from Anthropic.
            $bodyPreview = substr((string)$raw, 0, 500);
            $apiMessage  = '';
            $decoded     = json_decode((string)$raw, true);
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $apiMessage = (string) $decoded['error']['message'];
            }

            if ($code === 429) {
                $this->setError(
                    'RATE_LIMIT',
                    $apiMessage !== '' ? $apiMessage : 'Anthropic rate limit hit. Wait a minute and try again.',
                    $code
                );
            } else {
                $this->setError(
                    'API_ERROR',
                    $apiMessage !== '' ? $apiMessage : "Anthropic API returned HTTP {$code}.",
                    $code
                );
            }
            $this->log('AI_HTTP_ERROR', "HTTP={$code} body={$bodyPreview}");
            return null;
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $this->setError('PARSE_ERROR', 'Anthropic returned an invalid response body.');
            $this->log('AI_PARSE_ERROR', 'Invalid JSON response');
            return null;
        }

        return $data;
    }

    // ────────────────────────────────────────────────────────────
    // httpPost() — single buffered HTTP POST to the Messages API.
    //
    // Isolated transport seam: makeRequest()'s retry loop drives it, and
    // tests override it to inject a 429-then-200 transport double without a
    // live network. Captures response headers so the caller can read
    // Retry-After on a 429.
    //
    // @return array{body: string|false, code: int, error: string, headers: array<string,string>}
    // ────────────────────────────────────────────────────────────
    protected function httpPost(string $jsonPayload): array
    {
        $headers = [];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            // Capture response headers (one line per call) so makeRequest can
            // honor Retry-After on a 429 — same approach as the streaming path.
            CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (&$headers): int {
                $parts = explode(':', trim($headerLine), 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($headerLine);
            },
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return ['body' => $raw, 'code' => $code, 'error' => $err, 'headers' => $headers];
    }

    // ────────────────────────────────────────────────────────────
    // sleepSeconds() — wraps sleep() so the 429 backoff is overridable in
    // tests (a no-op double keeps the retry smoke instant).
    // ────────────────────────────────────────────────────────────
    protected function sleepSeconds(int $seconds): void
    {
        sleep($seconds);
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
    // WHY pre-check: Sentry's global error handler intercepts PHP
    // warnings (like "Permission denied") BEFORE try/catch can
    // suppress them, producing spurious Sentry events. By checking
    // writability first we avoid calling file_put_contents when it
    // would fail, so no warning fires and no Sentry noise is emitted.
    // ────────────────────────────────────────────────────────────
    private function log(string $level, string $message): void
    {
        try {
            $logPath = $this->projectRoot . '/' . self::LOG_FILE;
            // Only attempt the write when the path is actually writable.
            $canWrite = file_exists($logPath)
                ? is_writable($logPath)
                : is_writable(dirname($logPath));
            if (!$canWrite) {
                return;
            }
            $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
            file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logging failure must never crash the application
        }
    }
}
