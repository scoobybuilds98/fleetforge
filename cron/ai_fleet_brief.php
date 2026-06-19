<?php
declare(strict_types=1);

/**
 * cron/ai_fleet_brief.php
 *
 * Nightly AI fleet brief — runs at 04:00 (after health_scores 03:00 and
 * risk_scores 03:30, so the brief sees today's freshly-scored data).
 *
 * Generates a 200-300 word natural-language morning briefing using Claude
 * (settings.ai.model, default claude-sonnet-4-6). The brief is
 * cached in report_cache (report_type='ai_fleet_brief', expires 24h) so
 * the dashboard's "AI Insights" widget can render it without making real-
 * time API calls during business hours.
 *
 * Cost guardrail (D-E):
 *   - settings.ai.enabled = false → skip silently with audit_log entry
 *   - settings.ai.daily_token_limit exceeded → skip with audit_log
 *   - Both checks happen BEFORE the API call, never after
 *
 * Failure handling:
 *   - API timeout / refusal / error → audit_log, exit 0, leave previous
 *     cache row untouched (dashboard shows yesterday's brief with badge)
 *
 * Crontab (production): 0 4 * * * php /var/www/fleetforge/cron/ai_fleet_brief.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/ai_fleet_brief.php
 *
 * @session S-CRON-3
 * @audit   #3 (missing crons — AI fleet brief)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// D21: Advisory lock prevents overlapping runs.
$lock = db_row("SELECT GET_LOCK('ff_cron_ai_fleet_brief', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

try {
    // -----------------------------------------------------------------------
    // Gate 1: ai.enabled. Settings stores '1'/'0' as string.
    // -----------------------------------------------------------------------
    if ((string) settings_get('ai.enabled', '1') !== '1') {
        log_brief_audit('AI disabled, brief not generated.');
        exit(0);
    }

    // -----------------------------------------------------------------------
    // Gate 1b (S-INTEL-TAB / D-INTEL-2): ai.briefing_enabled. Separate
    // toggle so operator can pause the morning briefing without disabling
    // AI chat or anomaly scan. If 0, we don't even touch the Claude API.
    // -----------------------------------------------------------------------
    if ((string) settings_get('ai.briefing_enabled', '1') !== '1') {
        log_brief_audit('Briefing disabled (ai.briefing_enabled=0), brief not generated.');
        exit(0);
    }

    // -----------------------------------------------------------------------
    // Gate 2: daily token budget. ClaudeClient enforces this per-user, but
    // userId=null bypasses the check inside sendMessage — so we re-check here.
    //
    // Estimated brief cost: ~3000 tokens (prompt ~2400 in + 600 out). We
    // refuse to start the call if today's running total + 3000 would exceed
    // the limit.
    // -----------------------------------------------------------------------
    $limit = (int) settings_get('ai.daily_token_limit', 500000);
    if ($limit > 0) {
        $usage     = \FleetForge\AI\TokenTracker::getTodayUsage(null);
        $projected = $usage['tokens'] + 3000;
        if ($projected > $limit) {
            log_brief_audit("Token budget exceeded ({$usage['tokens']}/{$limit} used + 3000 projected). Brief skipped.");
            exit(0);
        }
    }

    // -----------------------------------------------------------------------
    // Gather metrics. Single batch via independent SELECTs — total ~8 round-
    // trips, all small. We do not cache these reads; they're for one query.
    // -----------------------------------------------------------------------
    $metrics = gather_brief_metrics();

    // -----------------------------------------------------------------------
    // Compose prompt. Keep system prompt small; user message carries the
    // JSON metric payload.
    // -----------------------------------------------------------------------
    $system = "You are a fleet operations analyst writing a morning briefing for the fleet manager. "
            . "Write 200-300 words of plain prose (no bullets, no lists, no markdown headings). "
            . "Lead with the most important issue or trend. End with one clear, actionable recommendation. "
            . "Tone: direct, factual, no fluff. Treat all numbers as accurate; do not hedge with 'about' or 'roughly'.";

    $userMessage = "Here are this morning's fleet metrics. Generate the briefing.\n\n"
                 . json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $messages = [
        ['role' => 'user', 'content' => $userMessage],
    ];

    // -----------------------------------------------------------------------
    // Call Claude. ClaudeClient handles retries, error logging, and token
    // recording inside ai_query_log automatically.
    // -----------------------------------------------------------------------
    $client = new \FleetForge\AI\ClaudeClient();
    if (!$client->isEnabled()) {
        log_brief_audit('ClaudeClient::isEnabled() returned false (no API key?). Brief skipped.');
        exit(0);
    }

    $response = $client->sendMessage(
        messages:     $messages,
        systemPrompt: $system,
        tools:        [],
        maxTokens:    600,
        userId:       null,
        queryType:    'fleet_brief',
    );

    if ($response === null) {
        $err = $client->getLastError();
        $errCode = $err['code'] ?? 'UNKNOWN';
        $errMsg  = $err['message'] ?? 'unknown error';
        log_brief_audit("Brief generation failed: {$errCode} — {$errMsg}. Previous cache left in place.");
        exit(0);
    }

    $briefText = trim(\FleetForge\AI\ClaudeClient::extractTextContent($response));
    if ($briefText === '') {
        log_brief_audit('Brief returned empty text. Previous cache left in place.');
        exit(0);
    }

    $usage           = $response['usage'] ?? [];
    $promptTokens    = (int) ($usage['input_tokens'] ?? 0);
    $completionTokens = (int) ($usage['output_tokens'] ?? 0);
    $totalTokens     = $promptTokens + $completionTokens;
    $generatedAt     = date('Y-m-d H:i:s');
    $expiresAt       = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $cachePayload = [
        'brief'        => $briefText,
        'word_count'   => str_word_count($briefText),
        'generated_at' => $generatedAt,
        'expires_at'   => $expiresAt,
        'metrics'      => $metrics,
        'tokens'       => [
            'prompt'     => $promptTokens,
            'completion' => $completionTokens,
            'total'      => $totalTokens,
        ],
        'model' => $client->getModel(),
    ];

    // -----------------------------------------------------------------------
    // Upsert into report_cache. Use a deterministic parameters_hash so
    // back-to-back runs overwrite the same row instead of stacking.
    //
    // D-F: report_cache (existing infra) over a settings row — keeps the
    // cache_cleanup cron's TTL handling consistent.
    // -----------------------------------------------------------------------
    $paramsHash = sha1('ai_fleet_brief|morning');

    $existing = db_row(
        "SELECT id FROM report_cache
         WHERE report_type = 'ai_fleet_brief' AND parameters_hash = ?",
        [$paramsHash]
    );

    if ($existing) {
        db_update('report_cache', [
            'parameters'   => json_encode(['scope' => 'morning']),
            'result_data'  => json_encode($cachePayload, JSON_UNESCAPED_SLASHES),
            'generated_at' => $generatedAt,
            'expires_at'   => $expiresAt,
            'generated_by' => null,
        ], 'id = ?', [(int)$existing['id']]);
    } else {
        db_insert('report_cache', [
            'report_type'     => 'ai_fleet_brief',
            'parameters_hash' => $paramsHash,
            'parameters'      => json_encode(['scope' => 'morning']),
            'result_data'     => json_encode($cachePayload, JSON_UNESCAPED_SLASHES),
            'generated_at'    => $generatedAt,
            'expires_at'      => $expiresAt,
            'generated_by'    => null,
        ]);
    }

    log_brief_audit(sprintf(
        'Fleet brief generated. metrics=%d tokens=%d (in=%d out=%d) words=%d cache_expires=%s',
        count($metrics),
        $totalTokens,
        $promptTokens,
        $completionTokens,
        str_word_count($briefText),
        $expiresAt
    ));

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log('[CRON ai_fleet_brief] Fatal: ' . $e->getMessage());
    log_brief_audit('ai_fleet_brief fatal error: ' . $e->getMessage(), isError: true);
    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_ai_fleet_brief')", []);
}

/**
 * gather_brief_metrics() — pull all the numbers the brief prompt needs.
 *
 * @return array<string,mixed>
 */
function gather_brief_metrics(): array
{
    $today      = date('Y-m-d');
    $yesterday  = date('Y-m-d', strtotime('-1 day'));
    $sevenDays  = date('Y-m-d', strtotime('-7 days'));
    $in7d       = date('Y-m-d', strtotime('+7 days'));
    $in30d      = date('Y-m-d', strtotime('+30 days'));

    // Fleet snapshot
    $fleet = db_row(
        "SELECT
            COUNT(*) AS total_units,
            SUM(status='on_lease') AS on_lease,
            SUM(status='available') AS available,
            SUM(status='maintenance') AS maintenance,
            SUM(status='reserved') AS reserved
         FROM equipment_units
         WHERE deleted_at IS NULL AND status NOT IN ('decommissioned')"
    );
    $totalUnits = (int)($fleet['total_units'] ?? 0);
    $onLease    = (int)($fleet['on_lease'] ?? 0);
    $util       = $totalUnits > 0 ? round(($onLease / $totalUnits) * 100, 1) : 0.0;

    // Lease counts
    $activeLeases = (int)(db_row(
        "SELECT COUNT(*) AS n FROM leases WHERE deleted_at IS NULL AND status='active'"
    )['n'] ?? 0);

    // Yesterday's revenue (paid invoices) vs prior 7-day avg
    $rev = db_row(
        "SELECT
            COALESCE(SUM(CASE WHEN paid_date = ? THEN amount_paid ELSE 0 END), 0) AS yesterday,
            COALESCE(SUM(CASE WHEN paid_date >= ? AND paid_date < ? THEN amount_paid ELSE 0 END), 0) AS prior_7d
         FROM invoices
         WHERE deleted_at IS NULL AND status IN ('paid','partially_paid')",
        [$yesterday, $sevenDays, $yesterday]
    );
    $yRev      = (string)($rev['yesterday'] ?? '0.00');
    $priorAvg  = bcdiv((string)($rev['prior_7d'] ?? '0.00'), '7', 2);

    // Open damage claims
    $damage = db_row(
        "SELECT COUNT(*) AS n, COALESCE(SUM(estimated_repair_cost), 0) AS total_cost
         FROM damage_claims
         WHERE deleted_at IS NULL AND status NOT IN ('resolved','written_off')"
    );

    // Compliance expiring 7d / 30d
    $exp7 = (int)(db_row(
        "SELECT COUNT(DISTINCT id) AS n FROM equipment_units
         WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
           AND ((cvi_expiry IS NOT NULL AND cvi_expiry <= ?)
             OR (registration_expiry IS NOT NULL AND registration_expiry <= ?)
             OR (mvi_expiry IS NOT NULL AND mvi_expiry <= ?)
             OR (insurance_expiry IS NOT NULL AND insurance_expiry <= ?))",
        [$in7d, $in7d, $in7d, $in7d]
    )['n'] ?? 0);

    $exp30 = (int)(db_row(
        "SELECT COUNT(DISTINCT id) AS n FROM equipment_units
         WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
           AND ((cvi_expiry IS NOT NULL AND cvi_expiry <= ?)
             OR (registration_expiry IS NOT NULL AND registration_expiry <= ?)
             OR (mvi_expiry IS NOT NULL AND mvi_expiry <= ?)
             OR (insurance_expiry IS NOT NULL AND insurance_expiry <= ?))",
        [$in30d, $in30d, $in30d, $in30d]
    )['n'] ?? 0);

    // AR
    $ar = db_row(
        "SELECT
            COALESCE(SUM(balance_due), 0) AS total_outstanding,
            SUM(due_date < CURDATE() AND balance_due > 0) AS overdue_count,
            COALESCE(MAX(CASE WHEN due_date < CURDATE() AND balance_due > 0
                              THEN DATEDIFF(CURDATE(), due_date) END), 0) AS oldest_overdue_days
         FROM invoices
         WHERE deleted_at IS NULL AND status IN ('sent','overdue','partially_paid')"
    );

    // Top 3 customers with worst risk score (HIGH first, then MEDIUM)
    $topRisk = db_select(
        "SELECT id, company_name, risk_score, outstanding_balance
         FROM customers
         WHERE deleted_at IS NULL AND risk_score IN ('high','medium')
         ORDER BY FIELD(risk_score,'high','medium','low'), outstanding_balance DESC
         LIMIT 3"
    );

    // Top 3 equipment units with the worst health score
    $worstHealth = db_select(
        "SELECT id, unit_number, status, health_score
         FROM equipment_units
         WHERE deleted_at IS NULL AND status NOT IN ('decommissioned')
           AND health_score IS NOT NULL
         ORDER BY health_score ASC, unit_number ASC
         LIMIT 3"
    );

    // Open work orders
    $wo = db_row(
        "SELECT COUNT(*) AS n,
                SUM(created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)) AS old_count
         FROM maintenance_work_orders
         WHERE deleted_at IS NULL AND status NOT IN ('completed','cancelled')"
    );

    return [
        'date'              => $today,
        'fleet' => [
            'total_units' => $totalUnits,
            'on_lease'    => $onLease,
            'available'   => (int)($fleet['available'] ?? 0),
            'maintenance' => (int)($fleet['maintenance'] ?? 0),
            'reserved'    => (int)($fleet['reserved'] ?? 0),
            'utilization_pct' => $util,
        ],
        'leases' => [
            'active_count' => $activeLeases,
        ],
        'revenue' => [
            'yesterday'      => $yRev,
            'prior_7day_avg' => $priorAvg,
        ],
        'damage_claims' => [
            'open_count'      => (int)($damage['n'] ?? 0),
            'total_estimated' => (string)($damage['total_cost'] ?? '0.00'),
        ],
        'compliance' => [
            'expiring_7d'  => $exp7,
            'expiring_30d' => $exp30,
        ],
        'ar' => [
            'total_outstanding'   => (string)($ar['total_outstanding'] ?? '0.00'),
            'overdue_count'       => (int)($ar['overdue_count'] ?? 0),
            'oldest_overdue_days' => (int)($ar['oldest_overdue_days'] ?? 0),
        ],
        'top_risk_customers' => array_map(fn($c) => [
            'company_name'        => $c['company_name'],
            'risk_score'          => $c['risk_score'],
            'outstanding_balance' => $c['outstanding_balance'],
        ], $topRisk),
        'worst_health_units' => array_map(fn($u) => [
            'unit_number'  => $u['unit_number'],
            'status'       => $u['status'],
            'health_score' => (int)$u['health_score'],
            'health_color' => equipment_health_color((int)$u['health_score']),
        ], $worstHealth),
        'work_orders' => [
            'open_count'        => (int)($wo['n'] ?? 0),
            'old_count_over14d' => (int)($wo['old_count'] ?? 0),
        ],
    ];
}

/**
 * log_brief_audit() — single-line audit_log writer for this cron.
 */
function log_brief_audit(string $notes, bool $isError = false): void
{
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'ai',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'ai_fleet_brief',
        'notes'        => $notes,
        'ip_address'   => '127.0.0.1',
    ]);
    error_log(($isError ? '[CRON ai_fleet_brief ERROR] ' : '[CRON ai_fleet_brief] ') . $notes);
}
