<?php
declare(strict_types=1);

namespace FleetForge\AI;

/**
 * lib/AI/TokenTracker.php
 *
 * Tracks AI token usage per user per day and enforces the daily limit.
 * All methods are static — no instantiation needed.
 *
 * Data is stored in the ai_query_log table (already in schema).
 * Daily limit comes from settings table: ai.daily_token_limit
 *
 * NOTE on cost_usd: the dollar figures from getTodayUsage/getMonthUsage/
 * getUsageByUser are NON-AUTHORITATIVE display estimates summed from the stored
 * per-row cost_usd (itself now derived via the bcmath Pricing path in
 * ClaudeClient). The authoritative credit/spend figure is CreditEstimator, which
 * recomputes from token counts with bcmath and does NOT read this column.
 *
 * @depends includes/db.php (db_row, db_insert), includes/functions.php (settings_get)
 * @session S026
 */
class TokenTracker
{
    // ────────────────────────────────────────────────────────────
    // canSpend()
    //
    // Enforces a GLOBAL daily token budget (ai.daily_token_limit) summed across
    // ALL users — not a per-user cap. $userId is not a quota dimension here; the
    // caller (ClaudeClient) only consults this gate for user-attributed calls and
    // passes null for system/cron to bypass it. Kept in the signature for a
    // possible future per-user cap and to keep all call sites uniform. If limit
    // is 0, spending is unlimited. (S-AI-AUDIT-HIGH-FIX: doc corrected — the code
    // was always global; ClaudeClient's "per-user" wording was the inaccuracy.)
    //
    // @param  int|null $userId  Unused for the quota; see note above.
    // @return bool
    // ────────────────────────────────────────────────────────────
    public static function canSpend(?int $userId): bool
    {
        $limit = (int) settings_get('ai.daily_token_limit', 500000);
        if ($limit <= 0) return true; // 0 = unlimited

        // Global daily usage (all users combined) vs the shared daily budget.
        $row = db_row(
            "SELECT COALESCE(SUM(total_tokens), 0) AS used
             FROM ai_query_log
             WHERE DATE(created_at) = CURDATE()"
        );

        return ((int) ($row['used'] ?? 0)) < $limit;
    }

    // ────────────────────────────────────────────────────────────
    // record()
    //
    // Logs a completed AI API call to ai_query_log.
    //
    // @param  int|null $userId           User who triggered the call
    // @param  string   $queryType        Feature label (chat, summary, report, etc.)
    // @param  int      $promptTokens     Input tokens from API response
    // @param  int      $completionTokens Output tokens from API response
    // @param  int          $totalTokens   Total tokens (input + output)
    // @param  float|string $costUsd       Estimated cost in USD (ClaudeClient now
    //                                      passes a bcmath 6dp decimal string)
    // @param  int          $latencyMs     Request round-trip time
    // @param  bool         $wasCached     Whether result came from cache
    // ────────────────────────────────────────────────────────────
    public static function record(
        ?int         $userId,
        string       $queryType,
        int          $promptTokens,
        int          $completionTokens,
        int          $totalTokens,
        float|string $costUsd,
        int          $latencyMs,
        bool         $wasCached
    ): void {
        try {
            db_insert('ai_query_log', [
                'user_id'            => $userId,
                'query_type'         => $queryType,
                'prompt_tokens'      => $promptTokens,
                'completion_tokens'  => $completionTokens,
                'total_tokens'       => $totalTokens,
                // Already a 6dp bcmath string from ClaudeClient; normalize a float
                // caller to the same shape for the DECIMAL(8,6) column w/o drift.
                'cost_usd'           => is_string($costUsd) ? $costUsd : number_format($costUsd, 6, '.', ''),
                'latency_ms'         => $latencyMs,
                'was_cached'         => $wasCached ? 1 : 0,
            ]);
        } catch (\Throwable) {
            // Token tracking failure must never crash the application
        }
    }

    // ────────────────────────────────────────────────────────────
    // getTodayUsage()
    //
    // Returns usage stats for the current day.
    //
    // @param  int|null $userId  Filter to specific user (null = all)
    // @return array{tokens: int, cost: float, requests: int, limit: int, remaining: int}
    // ────────────────────────────────────────────────────────────
    public static function getTodayUsage(?int $userId = null): array
    {
        $limit = (int) settings_get('ai.daily_token_limit', 500000);

        $where = "WHERE DATE(created_at) = CURDATE()";
        $params = [];
        if ($userId !== null) {
            $where .= " AND user_id = ?";
            $params[] = $userId;
        }

        $row = db_row(
            "SELECT COALESCE(SUM(total_tokens), 0) AS tokens,
                    COALESCE(SUM(cost_usd), 0) AS cost,
                    COUNT(*) AS requests
             FROM ai_query_log {$where}",
            $params
        );

        $used = (int) ($row['tokens'] ?? 0);

        return [
            'tokens'    => $used,
            'cost'      => round((float) ($row['cost'] ?? 0), 4),
            'requests'  => (int) ($row['requests'] ?? 0),
            'limit'     => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }

    // ────────────────────────────────────────────────────────────
    // getMonthUsage()
    //
    // Returns usage stats for the current calendar month.
    //
    // @return array{tokens: int, cost: float, requests: int}
    // ────────────────────────────────────────────────────────────
    public static function getMonthUsage(): array
    {
        $row = db_row(
            "SELECT COALESCE(SUM(total_tokens), 0) AS tokens,
                    COALESCE(SUM(cost_usd), 0) AS cost,
                    COUNT(*) AS requests
             FROM ai_query_log
             WHERE YEAR(created_at) = YEAR(CURDATE())
               AND MONTH(created_at) = MONTH(CURDATE())"
        );

        return [
            'tokens'   => (int) ($row['tokens'] ?? 0),
            'cost'     => round((float) ($row['cost'] ?? 0), 4),
            'requests' => (int) ($row['requests'] ?? 0),
        ];
    }

    // ────────────────────────────────────────────────────────────
    // getUsageByUser()
    //
    // Returns per-user usage stats for the current month.
    //
    // @return array  [{ user_id, user_name, tokens, cost, requests }]
    // ────────────────────────────────────────────────────────────
    public static function getUsageByUser(): array
    {
        return db_select(
            "SELECT q.user_id,
                    u.name AS user_name,
                    SUM(q.total_tokens) AS tokens,
                    SUM(q.cost_usd) AS cost,
                    COUNT(*) AS requests
             FROM ai_query_log q
             LEFT JOIN users u ON u.id = q.user_id
             WHERE YEAR(q.created_at) = YEAR(CURDATE())
               AND MONTH(q.created_at) = MONTH(CURDATE())
             GROUP BY q.user_id, u.name
             ORDER BY tokens DESC"
        );
    }
}
