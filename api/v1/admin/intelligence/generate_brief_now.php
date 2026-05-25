<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/generate_brief_now.php
 *
 * Manual on-demand brief generation (F2). Calls the same logic as
 * cron/ai_fleet_brief.php but synchronously, with operator gating
 * + audit trail.
 *
 * Cost guardrails honored (D-INTEL-V2-3):
 *   - ai.enabled + ai.briefing_enabled must both be 1
 *   - ai.daily_token_limit check (refuse if projected total exceeds limit)
 *   - Anthropic API key must be configured
 *
 * Idempotency: if a non-stale cache exists from <60 minutes ago, the
 * endpoint returns 200 with cache_hit=true and skips the API call.
 * Override with ?force=1 to regenerate regardless (operator override).
 *
 * @method  POST
 * @auth    require_permission('settings', 'edit')
 * @body    JSON optional: { force: 0|1 }
 * @returns 200 { generated: bool, cache_hit: bool, brief_preview, tokens_used, cost_usd, cache_id }
 *
 * @session  S-INTEL-V2 Phase B
 * @decision D-INTEL-V2-3 (cost guardrails on manual generation)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

use FleetForge\AI\ClaudeClient;
use FleetForge\AI\TokenTracker;
use FleetForge\Notifications\MorningBriefingRenderer;

$body  = json_body();
$force = (int) ($body['force'] ?? 0) === 1;

try {
    // ── Gate 1: ai.enabled ──
    if ((string) settings_get('ai.enabled', '1') !== '1') {
        json_error('AI_DISABLED', 'AI is disabled (ai.enabled=0). Enable it in AI Core settings first.', 422);
    }
    // ── Gate 2: ai.briefing_enabled ──
    if ((string) settings_get('ai.briefing_enabled', '1') !== '1') {
        json_error('BRIEFING_DISABLED', 'Morning briefing is disabled (ai.briefing_enabled=0). Enable it in the Morning Briefing section first.', 422);
    }
    // ── Gate 3: cache idempotency ──
    // S-INTEL-FIX2: report_cache columns are generated_at + parameters_hash,
    // NOT created_at + cache_key. Stale schema references caused this whole
    // endpoint to 500. Aligned with cron/ai_fleet_brief.php's correct usage.
    if (!$force) {
        $existing = db_row(
            "SELECT id, result_data, generated_at
               FROM report_cache
              WHERE report_type = 'ai_fleet_brief' AND expires_at > NOW()
                AND generated_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
              ORDER BY id DESC LIMIT 1"
        );
        if ($existing) {
            $payload = json_decode((string) $existing['result_data'], true);
            $brief = (string) ($payload['brief'] ?? '');
            json_success([
                'generated'       => false,
                'cache_hit'       => true,
                'cache_id'        => (int) $existing['id'],
                'cached_at'       => (string) $existing['generated_at'],
                'brief_preview'   => mb_substr($brief, 0, 400),
                'brief_full'      => $brief,
                'tokens_used'     => 0,
                'cost_usd'        => 0,
                'message'         => 'Recent cached brief found (< 60 min old). Pass force=1 to regenerate.',
            ]);
        }
    }

    // ── Gate 4: token budget ──
    $limit = (int) settings_get('ai.daily_token_limit', 500000);
    if ($limit > 0) {
        $usage = TokenTracker::getTodayUsage(null);
        $projected = ($usage['tokens'] ?? 0) + 3000;
        if ($projected > $limit) {
            json_error(
                'BUDGET_EXCEEDED',
                "Skipping generation: today's projected usage ({$projected}) would exceed daily limit ({$limit}). Increase ai.daily_token_limit or wait until tomorrow.",
                422
            );
        }
    }

    // ── Gate 5: API key configured ──
    $apiKey = settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', '');
    if (empty($apiKey)) {
        json_error('NO_API_KEY', 'No Anthropic API key configured. Set ai.anthropic_api_key in AI Core settings.', 422);
    }

    // ── Gather metrics (same call the cron makes — uses the cron's
    // function once it's loaded; otherwise use the renderer payload). ──
    // The cron's gather_brief_metrics is defined in cron/ai_fleet_brief.php
    // and runs after its main body — we can't include it without running the
    // cron. So we duplicate the metric gathering inline here using a fresh
    // call to MorningBriefingRenderer::buildPayload which is the digest
    // shape (rich) — the AI brief gather is slightly different (more
    // fleet-ops-oriented). For now use the renderer payload as the
    // metrics input — same fields would summarize fine, and the brief
    // is the *narrative* over today's data anyway.
    $metrics = MorningBriefingRenderer::buildPayload();

    // ── Call Claude ──
    $model = (string) settings_get('ai.model', 'claude-sonnet-4-20250514');
    $system = "You are a fleet operations analyst writing a morning briefing for the fleet manager. "
            . "Write 200-300 words of plain prose (no bullets, no lists, no markdown headings). "
            . "Lead with the most important issue or trend. End with one clear, actionable recommendation. "
            . "Tone: direct, factual, no fluff. Treat all numbers as accurate; do not hedge with 'about' or 'roughly'.";
    $userMsg = "Here are this morning's fleet metrics. Generate the briefing.\n\n"
             . json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $client = new ClaudeClient(['model' => $model]);
    $start = microtime(true);
    // S-INTEL-FIX2: ClaudeClient::sendMessage() parameter is $systemPrompt,
    // NOT $system. The stale name made every call 500 with
    // "Unknown named parameter $system". Aligned with cron/ai_fleet_brief.php
    // which uses systemPrompt: correctly.
    $response = $client->sendMessage(
        messages:     [['role' => 'user', 'content' => $userMsg]],
        systemPrompt: $system,
        userId:       current_user_id(),
        queryType:    'manual_fleet_brief'
    );
    $latencyMs = (int) round((microtime(true) - $start) * 1000);

    if ($response === null) {
        $err = $client->getLastError();
        json_error(
            $err['code'] ?? 'AI_ERROR',
            $err['message'] ?? 'Claude API call failed (no details).',
            502
        );
    }

    $briefText = trim((string) ($response['content'][0]['text'] ?? ''));
    if ($briefText === '') {
        json_error('AI_EMPTY_RESPONSE', 'Claude returned an empty response.', 502);
    }

    // ── Cache the result (24h TTL same as cron) ──
    // S-INTEL-FIX2: report_cache columns are parameters_hash + generated_at,
    // NOT cache_key + created_at. Schema mismatch made every insert error
    // out, so even successful Claude calls would never be cached.
    $generatedAt = date('Y-m-d H:i:s');
    $expiresAt   = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $cachePayload = [
        'brief'        => $briefText,
        'generated_at' => $generatedAt,
        'expires_at'   => $expiresAt,
        'model'        => $model,
        'metrics'      => $metrics,
        'manual'       => true,
        'manual_by'    => current_user_id(),
    ];
    $cacheId = db_insert('report_cache', [
        'report_type'     => 'ai_fleet_brief',
        'parameters_hash' => sha1('ai_fleet_brief|manual|' . current_user_id() . '|' . $generatedAt),
        'parameters'      => json_encode(['scope' => 'manual', 'by' => current_user_id()]),
        'result_data'     => json_encode($cachePayload, JSON_UNESCAPED_SLASHES),
        'generated_at'    => $generatedAt,
        'expires_at'      => $expiresAt,
        'generated_by'    => current_user_id(),
    ]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'settings',
        'entity_type'  => 'ai_brief_generated_manual',
        'entity_id'    => (int) $cacheId,
        'entity_label' => 'Manual brief generation',
        'notes'        => sprintf(
            'Manual generation by operator. tokens=%d cost=$%.4f latency=%dms model=%s',
            (int) ($response['usage']['total_tokens'] ?? 0),
            (float) ($response['cost_usd'] ?? 0),
            $latencyMs,
            $model
        ),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success([
        'generated'       => true,
        'cache_hit'       => false,
        'cache_id'        => (int) $cacheId,
        'brief_preview'   => mb_substr($briefText, 0, 400),
        'brief_full'      => $briefText,
        'tokens_used'     => (int) ($response['usage']['total_tokens'] ?? 0),
        'cost_usd'        => (float) ($response['cost_usd'] ?? 0),
        'latency_ms'      => $latencyMs,
        'message'         => 'Brief generated. The next morning digest cron will email this brief to recipients.',
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Manual generation failed: ' . $e->getMessage(), 500);
}
