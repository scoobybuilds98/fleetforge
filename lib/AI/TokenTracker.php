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
 * @depends includes/db.php (db_row, db_insert), includes/functions.php (settings_get)
 * @session S026
 */
class TokenTracker
{
    // ────────────────────────────────────────────────────────────
    // canSpend()
    //
    // Returns true if the user (or global total) has not exceeded
    // the daily token limit. If limit is 0, spending is unlimited.
    //
    // @param  int|null $userId  User ID (null = system/cron)
    // @return bool
    // ────────────────────────────────────────────────────────────
    public static function canSpend(?int $userId): bool
    {
        $limit = (int) settings_get('ai.daily_token_limit', 500000);
        if ($limit <= 0) return true; // 0 = unlimited

        // Check global daily usage (all users combined)
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
    // @param  int      $totalTokens      Total tokens (input + output)
    // @param  float    $costUsd          Estimated cost in USD
    // @param  int      $latencyMs        Request round-trip time
    // @param  bool     $wasCached        Whether result came from cache
    // ────────────────────────────────────────────────────────────
    public static function record(
        ?int   $userId,
        string $queryType,
        int    $promptTokens,
        int    $completionTokens,
        int    $totalTokens,
        float  $costUsd,
        int    $latencyMs,
        bool   $wasCached
    ): void {
        try {
            db_insert('ai_query_log', [
                'user_id'            => $userId,
                'query_type'         => $queryType,
                'prompt_tokens'      => $promptTokens,
                'completion_tokens'  => $completionTokens,
                'total_tokens'       => $totalTokens,
                'cost_usd'           => round($costUsd, 6),
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
