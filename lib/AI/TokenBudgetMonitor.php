<?php
declare(strict_types=1);

/**
 * lib/AI/TokenBudgetMonitor.php
 *
 * Daily token-budget threshold monitor (F12). Checks today's
 * cumulative AI token usage against the configured limit
 * (`ai.daily_token_limit`) and emits a single email per threshold
 * crossing (`ai.budget_alert_thresholds`).
 *
 * Per-threshold dedup is stored in `ai.budget_alert_last_sent` as a
 * JSON map {threshold: 'YYYY-MM-DD'}. The check resets each calendar
 * day (UTC) so threshold N fires at most once per day per crossing.
 *
 * Called from two surfaces:
 *   1. cron/ai_budget_check.php — hourly, dedicated cron
 *   2. Inline after every AI Claude call (lib/AI/ClaudeClient.php
 *      already logs usage; an opt-in hook can call ::check() after
 *      logging) — provides faster reaction time. Left as a future
 *      hookpoint per D-INTEL-V2-1 (hourly cron is the primary path).
 *
 * @session  S-INTEL-V2 Phase A
 * @decision D-INTEL-V2-1 (token budget threshold semantics)
 */

namespace FleetForge\AI;

use FleetForge\Email\EmailService;
use FleetForge\Notifications\Mailer;

class TokenBudgetMonitor
{
    /**
     * Run the daily budget check. Returns the alerts emitted this run
     * (zero or more thresholds crossed since the last check).
     *
     * @return array{
     *     usage_tokens: int,
     *     limit_tokens: int,
     *     percent_used: float,
     *     alerts_sent: array<int, array{threshold: float, recipient_count: int}>,
     *     skipped_thresholds: array<int, array{threshold: float, reason: string}>
     * }
     */
    public static function check(): array
    {
        $usage = (int) db_count(
            "SELECT COALESCE(SUM(total_tokens), 0)
               FROM ai_query_log
              WHERE DATE(created_at) = CURDATE()"
        );
        $limit = (int) settings_get('ai.daily_token_limit', 500000);
        $percent = $limit > 0 ? $usage / $limit : 0.0;

        $thresholdsJson = (string) settings_get('ai.budget_alert_thresholds', '[0.5,0.8,1.0]');
        $thresholds = json_decode($thresholdsJson, true);
        if (!is_array($thresholds)) { $thresholds = [0.5, 0.8, 1.0]; }
        $thresholds = array_values(array_filter($thresholds, 'is_numeric'));
        sort($thresholds);

        $lastSentJson = (string) settings_get('ai.budget_alert_last_sent', '{}');
        $lastSent = json_decode($lastSentJson, true);
        if (!is_array($lastSent)) { $lastSent = []; }

        $today = date('Y-m-d');
        $alertsSent = [];
        $skipped = [];

        foreach ($thresholds as $t) {
            $key = (string) $t;
            if ($percent < (float) $t) {
                $skipped[] = ['threshold' => (float) $t, 'reason' => 'not_yet_reached'];
                continue;
            }
            if (($lastSent[$key] ?? null) === $today) {
                $skipped[] = ['threshold' => (float) $t, 'reason' => 'already_sent_today'];
                continue;
            }

            // Threshold crossed AND not yet alerted today → send.
            $recipientCount = self::sendAlert((float) $t, $usage, $limit, $percent);
            $alertsSent[] = ['threshold' => (float) $t, 'recipient_count' => $recipientCount];
            $lastSent[$key] = $today;
        }

        // Persist updated last_sent state.
        if (!empty($alertsSent)) {
            db_execute(
                "UPDATE settings SET `value` = ? WHERE `key` = 'ai.budget_alert_last_sent'",
                [json_encode($lastSent, JSON_UNESCAPED_SLASHES)]
            );
        }

        return [
            'usage_tokens'       => $usage,
            'limit_tokens'       => $limit,
            'percent_used'       => round($percent, 4),
            'alerts_sent'        => $alertsSent,
            'skipped_thresholds' => $skipped,
        ];
    }

    /**
     * Send the alert email to all users matching the recipient role
     * allow list. Returns number of recipients successfully sent to.
     */
    private static function sendAlert(float $threshold, int $usage, int $limit, float $percent): int
    {
        $rolesJson = (string) settings_get('ai.budget_alert_recipients', '["super_admin"]');
        $roles = json_decode($rolesJson, true);
        if (!is_array($roles) || empty($roles)) { $roles = ['super_admin']; }
        $roles = array_values(array_filter($roles, 'is_string'));
        if (empty($roles)) { return 0; }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $recipients = db_select(
            "SELECT u.id, u.name, u.email
               FROM users u
               JOIN user_roles ur ON ur.id = u.role_id
              WHERE u.deleted_at IS NULL AND u.status = 'active'
                AND ur.slug IN ({$placeholders})",
            $roles
        );

        $thresholdPct = (int) round($threshold * 100);
        $usagePct = (int) round($percent * 100);
        $remaining = max(0, $limit - $usage);

        $isOverLimit = $percent >= 1.0;
        $bodyColor = $isOverLimit ? '#dc2626' : ($percent >= 0.8 ? '#d97706' : '#1c1c1a');
        $headline = $isOverLimit
            ? 'AI Token Budget EXCEEDED'
            : "AI Token Budget — {$thresholdPct}% Threshold Reached";

        $sent = 0;
        foreach ($recipients as $r) {
            $email = (string) $r['email'];
            $name  = (string) ($r['name'] ?? 'Team');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

            $body  = '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="padding:24px;background:#ffffff;">';
            $body .= '<tr><td style="font-family:Arial,sans-serif;color:#1c1c1a;">';
            $body .= '<h1 style="margin:0 0 16px;font-size:18px;color:' . $bodyColor . ';">' . e($headline) . '</h1>';
            $body .= '<p style="font-size:13px;color:#555;margin:0 0 16px;">Hi ' . e($name) . ',</p>';
            $body .= '<p style="font-size:13px;margin:0 0 16px;">FleetForge daily AI token usage has reached <strong>' . $usagePct . '%</strong> of the configured limit.</p>';
            $body .= '<table cellpadding="6" cellspacing="0" border="0" style="font-size:12px;border-collapse:collapse;margin-bottom:16px;">';
            $body .= '<tr><td><strong>Used today:</strong></td><td>' . number_format($usage) . ' tokens</td></tr>';
            $body .= '<tr><td><strong>Daily limit:</strong></td><td>' . number_format($limit) . ' tokens</td></tr>';
            $body .= '<tr><td><strong>Remaining:</strong></td><td>' . number_format($remaining) . ' tokens</td></tr>';
            $body .= '<tr><td><strong>Threshold crossed:</strong></td><td>' . $thresholdPct . '%</td></tr>';
            $body .= '</table>';
            if ($isOverLimit) {
                $body .= '<p style="font-size:13px;background:#fef2f2;border-left:3px solid #dc2626;padding:10px;margin:0 0 16px;">';
                $body .= '<strong>Action required:</strong> AI features (morning briefing, anomaly scan, chat) will be skipped until tomorrow OR until the limit is raised. Review usage and either increase <code>ai.daily_token_limit</code> or pause non-essential AI features.';
                $body .= '</p>';
            }
            $body .= '<p style="font-size:12px;color:#666;margin:0;">Manage AI settings + view request log: <a href="' . e(rtrim((string) settings_get('app.url', ''), '/') . '/settings?tab=intelligence') . '">Intelligence settings</a></p>';
            $body .= '</td></tr></table>';

            $wrapped = EmailService::renderEmailHtml($body);
            $subject = $isOverLimit
                ? '[FleetForge] AI Token Budget EXCEEDED — action required'
                : '[FleetForge] AI Token Budget — ' . $thresholdPct . '% threshold reached';

            $ok = Mailer::send(toEmail: $email, toName: $name, subject: $subject, htmlBody: $wrapped);

            db_insert('notification_log', [
                'channel'           => 'email',
                'recipient'         => $email,
                'subject'           => $subject,
                'body'              => 'Token budget alert: ' . $usagePct . '% of ' . number_format($limit) . ' tokens used.',
                'entity_type'       => 'user',
                'entity_id'         => (int) $r['id'],
                'notification_type' => 'ai_budget_alert',
                'status'            => $ok ? 'sent' : 'failed',
                'sent_at'           => $ok ? date('Y-m-d H:i:s') : null,
            ]);

            if ($ok) $sent++;
        }

        return $sent;
    }

    /**
     * Return today's usage summary without emitting any alerts.
     * Used by the Token Analytics widget on the Intelligence tab.
     *
     * @return array{
     *     today: array{tokens: int, requests: int, cost_usd: float},
     *     mtd: array{tokens: int, requests: int, cost_usd: float},
     *     last_7d: array{tokens: int, requests: int, cost_usd: float},
     *     last_30d: array{tokens: int, requests: int, cost_usd: float},
     *     by_feature_today: array<int, array{query_type: string, tokens: int, requests: int}>,
     *     daily_7d_chart: array<int, array{date: string, tokens: int, cost_usd: float}>,
     *     limit_tokens: int,
     *     percent_used_today: float
     * }
     */
    public static function snapshot(): array
    {
        $today = db_row("SELECT COALESCE(SUM(total_tokens),0) AS tokens, COUNT(*) AS requests, COALESCE(SUM(cost_usd),0) AS cost FROM ai_query_log WHERE DATE(created_at) = CURDATE()");
        $mtd   = db_row("SELECT COALESCE(SUM(total_tokens),0) AS tokens, COUNT(*) AS requests, COALESCE(SUM(cost_usd),0) AS cost FROM ai_query_log WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
        $w7    = db_row("SELECT COALESCE(SUM(total_tokens),0) AS tokens, COUNT(*) AS requests, COALESCE(SUM(cost_usd),0) AS cost FROM ai_query_log WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        $d30   = db_row("SELECT COALESCE(SUM(total_tokens),0) AS tokens, COUNT(*) AS requests, COALESCE(SUM(cost_usd),0) AS cost FROM ai_query_log WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");

        $byFeature = db_select(
            "SELECT query_type, SUM(total_tokens) AS tokens, COUNT(*) AS requests
               FROM ai_query_log
              WHERE DATE(created_at) = CURDATE()
              GROUP BY query_type
              ORDER BY tokens DESC"
        );
        $daily = db_select(
            "SELECT DATE(created_at) AS d, SUM(total_tokens) AS tokens, SUM(cost_usd) AS cost
               FROM ai_query_log
              WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              GROUP BY DATE(created_at)
              ORDER BY d"
        );

        $limit = (int) settings_get('ai.daily_token_limit', 500000);
        $todayTokens = (int) ($today['tokens'] ?? 0);
        $pct = $limit > 0 ? $todayTokens / $limit : 0.0;

        return [
            'today'             => ['tokens' => $todayTokens, 'requests' => (int) ($today['requests'] ?? 0), 'cost_usd' => (float) ($today['cost'] ?? 0)],
            'mtd'               => ['tokens' => (int) ($mtd['tokens']   ?? 0), 'requests' => (int) ($mtd['requests']   ?? 0), 'cost_usd' => (float) ($mtd['cost']   ?? 0)],
            'last_7d'           => ['tokens' => (int) ($w7['tokens']    ?? 0), 'requests' => (int) ($w7['requests']    ?? 0), 'cost_usd' => (float) ($w7['cost']    ?? 0)],
            'last_30d'          => ['tokens' => (int) ($d30['tokens']   ?? 0), 'requests' => (int) ($d30['requests']   ?? 0), 'cost_usd' => (float) ($d30['cost']   ?? 0)],
            'by_feature_today'  => array_map(static fn($r) => ['query_type' => (string) $r['query_type'], 'tokens' => (int) $r['tokens'], 'requests' => (int) $r['requests']], $byFeature),
            'daily_7d_chart'    => array_map(static fn($r) => ['date' => (string) $r['d'], 'tokens' => (int) $r['tokens'], 'cost_usd' => (float) $r['cost']], $daily),
            'limit_tokens'      => $limit,
            'percent_used_today'=> round($pct, 4),
        ];
    }
}
