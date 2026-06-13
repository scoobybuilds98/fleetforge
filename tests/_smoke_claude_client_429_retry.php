<?php
declare(strict_types=1);

/**
 * tests/_smoke_claude_client_429_retry.php
 *
 * WAVE 4 [07] — ClaudeClient does not retry a transient 429.
 *
 * makeRequest() treated HTTP 429 like any non-200: single shot, return null,
 * no backoff, no Retry-After read. The unattended daily AI brief crons
 * (ai_fleet_brief / ai_weekly_brief) then exit 0 on the first rate-limit blip,
 * so the dashboard silently shows yesterday's brief — and prod logs already
 * show 429s. The streaming path already retried; the buffered path didn't.
 *
 * Fix: makeRequest() now wraps an isolated httpPost() transport seam in a
 * bounded retry loop (MAX_RETRIES_429), honoring Retry-After (capped at
 * RETRY_AFTER_CAP_SECONDS). A buffered request is always safe to retry (no
 * half-streamed text). sendMessage() delegates to makeRequest(), so every AI
 * caller — crons included — inherits the retry.
 *
 * This drives the REAL makeRequest() (via reflection) with a transport double
 * that overrides httpPost() (the network) and sleepSeconds() (the backoff):
 *   1. 429-then-200      → returns the 200 body; slept exactly once.
 *   2. 429 every attempt → returns null after MAX_RETRIES_429 retries; RATE_LIMIT.
 *   3. 500 (non-429)     → returns null with NO retry (slept 0×); API_ERROR.
 *   4. Retry-After honored + capped at RETRY_AFTER_CAP_SECONDS.
 *
 * PRE-FIX  : makeRequest has no retry → case 1 returns null, case 3 nothing to
 *            compare; the suite fails on the no-retry behavior.
 * POST-FIX : all pass.
 *
 * Run:  php tests/_smoke_claude_client_429_retry.php   Exit 0/1.
 *
 * @session WAVE-4-CLAUDE-429-RETRY
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\AI\ClaudeClient;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "WAVE 4 [07] CLAUDE CLIENT 429 RETRY — bounded Retry-After backoff\n";
echo str_repeat('─', 72) . "\n";

/**
 * Transport double: a queue of httpPost() responses + a no-op sleep that
 * records every backoff duration. Lets the real makeRequest() retry loop run
 * with zero network and zero wall-clock.
 */
$double = new class extends ClaudeClient {
    /** @var array<int,array{body:string|false,code:int,error:string,headers:array}> */
    public array $responses = [];
    public array $slept     = [];     // seconds passed to each sleepSeconds()
    public int   $calls     = 0;      // httpPost() invocations

    protected function httpPost(string $jsonPayload): array
    {
        $this->calls++;
        // Last queued response repeats once the queue is drained.
        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }
    protected function sleepSeconds(int $seconds): void
    {
        $this->slept[] = $seconds;    // never actually sleep in the test
    }
    public function reset(array $responses): void
    {
        $this->responses = $responses;
        $this->slept     = [];
        $this->calls     = 0;
    }
};

// Reach the private makeRequest() — the real retry logic under test.
$ref = new ReflectionMethod(ClaudeClient::class, 'makeRequest');
$ref->setAccessible(true);
$call = static fn() => $ref->invoke($double, ['model' => 'x', 'max_tokens' => 16, 'messages' => []]);

$ok = static fn(string $body, array $headers = []): array => ['body' => $body, 'code' => 200, 'error' => '', 'headers' => $headers];
$rl = static fn(array $headers = []): array => ['body' => '{"error":{"message":"rate limit"}}', 'code' => 429, 'error' => '', 'headers' => $headers];

// ── CASE 1: 429 then 200 → success after one retry ──────────────────────────
$double->reset([$rl(['retry-after' => '1']), $ok('{"id":"msg_1","content":[{"type":"text","text":"hi"}]}')]);
$r = $call();
if (is_array($r) && ($r['id'] ?? '') === 'msg_1' && count($double->slept) === 1) {
    $pass("1 transient 429 — retried once and returned the 200 body (slept " . count($double->slept) . "×)");
} else {
    $fail("1 transient 429 — got " . json_encode($r) . " slept=" . json_encode($double->slept));
}

// ── CASE 2: persistent 429 → null after MAX_RETRIES_429, RATE_LIMIT ─────────
$double->reset([$rl(['retry-after' => '0'])]);   // always 429
$r = $call();
$err = $double->getLastError();
if ($r === null && count($double->slept) === ClaudeClient::MAX_RETRIES_429 && ($err['code'] ?? '') === 'RATE_LIMIT') {
    $pass("2 persistent 429 — null after " . count($double->slept) . " retries; lastError=RATE_LIMIT (http " . ($err['http_code'] ?? '?') . ")");
} else {
    $fail("2 persistent 429 — r=" . json_encode($r) . " slept=" . count($double->slept) . " err=" . json_encode($err));
}

// ── CASE 3: 500 (non-429) → no retry, API_ERROR ─────────────────────────────
$double->reset([['body' => '{"error":{"message":"boom"}}', 'code' => 500, 'error' => '', 'headers' => []]]);
$r = $call();
$err = $double->getLastError();
if ($r === null && $double->slept === [] && ($err['code'] ?? '') === 'API_ERROR') {
    $pass("3 non-429 error — returned null with NO retry (slept 0×); lastError=API_ERROR");
} else {
    $fail("3 non-429 error — r=" . json_encode($r) . " slept=" . json_encode($double->slept) . " err=" . json_encode($err));
}

// ── CASE 4: Retry-After honored and capped ──────────────────────────────────
$double->reset([$rl(['retry-after' => '999']), $ok('{"id":"msg_2","content":[]}')]);
$r = $call();
$sleptVal = $double->slept[0] ?? -1;
if (is_array($r) && $sleptVal === ClaudeClient::RETRY_AFTER_CAP_SECONDS) {
    $pass("4 Retry-After — header 999s capped to {$sleptVal}s before the successful retry");
} else {
    $fail("4 Retry-After — slept={$sleptVal}s (expected capped 1..30) r=" . json_encode($r));
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("CLAUDE CLIENT 429 RETRY — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
