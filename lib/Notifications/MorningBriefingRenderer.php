<?php
declare(strict_types=1);

/**
 * lib/Notifications/MorningBriefingRenderer.php
 *
 * Shared library for the morning briefing payload + HTML body. Used by:
 *
 *   1. cron/notification_digest.php — daily 07:00 (local timezone) dispatch
 *      to opted-in users matching ai.briefing_recipient_roles per
 *      D-INTEL-2 three-gate filter.
 *   2. api/v1/admin/intelligence/test_briefing.php — operator's "Send me
 *      a test briefing now" button in the Intelligence settings tab.
 *      Per D-INTEL-3 the endpoint sends the CACHED brief (no Claude API
 *      call) wrapped through this same renderer for parity with the
 *      production morning email.
 *
 * Extracted from cron/notification_digest.php during S-INTEL-TAB so both
 * surfaces produce identical HTML output. No behavioral change vs. the
 * original cron-local functions — same data shape, same markup.
 *
 * @session S-INTEL-TAB (extraction); originally S-CRON-3 (logic source)
 */

namespace FleetForge\Notifications;

class MorningBriefingRenderer
{
    /**
     * Build the data payload rendered by the digest body. Heavy SQL —
     * computed once per cron run and reused across recipients; called
     * fresh per test-send invocation (test-send is rare, no caching
     * concern).
     *
     * @return array<string,mixed>
     */
    public static function buildPayload(): array
    {
        $cutoff24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $in7d      = date('Y-m-d', strtotime('+7 days'));

        // Today's overdue invoices.
        $overdueRow = db_row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(balance_due), 0) AS total
             FROM invoices
             WHERE deleted_at IS NULL
               AND status IN ('sent','overdue','partially_paid')
               AND balance_due > 0 AND due_date < CURDATE()"
        );

        $topOverdue = db_select(
            "SELECT c.company_name,
                    COALESCE(SUM(i.balance_due), 0) AS total,
                    MAX(DATEDIFF(CURDATE(), i.due_date)) AS max_days
             FROM customers c
             JOIN invoices i ON i.customer_id = c.id
             WHERE c.deleted_at IS NULL AND i.deleted_at IS NULL
               AND i.status IN ('sent','overdue','partially_paid')
               AND i.balance_due > 0 AND i.due_date < CURDATE()
             GROUP BY c.id, c.company_name
             ORDER BY total DESC
             LIMIT 5"
        );

        // Compliance expiring within 7 days.
        $complianceUnits = db_select(
            "SELECT id, unit_number,
                    LEAST(
                        IFNULL(cvi_expiry, '9999-12-31'),
                        IFNULL(registration_expiry, '9999-12-31'),
                        IFNULL(mvi_expiry, '9999-12-31'),
                        IFNULL(insurance_expiry, '9999-12-31')
                    ) AS earliest
             FROM equipment_units
             WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
               AND ((cvi_expiry IS NOT NULL AND cvi_expiry <= ?)
                 OR (registration_expiry IS NOT NULL AND registration_expiry <= ?)
                 OR (mvi_expiry IS NOT NULL AND mvi_expiry <= ?)
                 OR (insurance_expiry IS NOT NULL AND insurance_expiry <= ?))
             ORDER BY earliest ASC
             LIMIT 10",
            [$in7d, $in7d, $in7d, $in7d]
        );

        // Open damage claims.
        $damage = db_row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(estimated_repair_cost), 0) AS total_cost
             FROM damage_claims
             WHERE deleted_at IS NULL AND status NOT IN ('resolved','written_off')"
        );

        // Customers transitioned to HIGH risk overnight.
        $riskHighChanges = db_select(
            "SELECT a.entity_id, a.entity_label, a.notes
             FROM audit_log a
             WHERE a.action = 'status_change'
               AND a.module = 'customers'
               AND a.created_at >= ?
               AND JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.risk_score')) = 'high'
             ORDER BY a.created_at DESC
             LIMIT 10",
            [$cutoff24h]
        );

        // Equipment that dropped to red/orange overnight.
        $healthDrops = db_select(
            "SELECT a.entity_id, a.entity_label,
                    JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.color')) AS new_color,
                    JSON_UNQUOTE(JSON_EXTRACT(a.old_values, '$.color')) AS old_color,
                    JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.health_score')) AS new_score
             FROM audit_log a
             WHERE a.action = 'update'
               AND a.module = 'equipment'
               AND a.created_at >= ?
               AND JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.color')) IN ('orange','red')
               AND JSON_UNQUOTE(JSON_EXTRACT(a.old_values, '$.color')) IN ('green','yellow','unknown')
             ORDER BY a.created_at DESC
             LIMIT 10",
            [$cutoff24h]
        );

        // Yesterday's AI fleet brief (first paragraph).
        $brief = db_row(
            "SELECT result_data FROM report_cache
             WHERE report_type = 'ai_fleet_brief' AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $briefFirstPara = '';
        $briefStale     = false;
        if ($brief) {
            $payload = json_decode((string) $brief['result_data'], true);
            $text    = (string) ($payload['brief'] ?? '');
            $briefFirstPara = trim(explode("\n\n", $text, 2)[0] ?? '');
            $briefStale = !empty($payload['generated_at'])
                && strtotime((string) $payload['generated_at']) < strtotime('-20 hours');
        }

        return [
            'overdue' => [
                'count' => (int) ($overdueRow['n'] ?? 0),
                'total' => (string) ($overdueRow['total'] ?? '0.00'),
                'top'   => $topOverdue,
            ],
            'compliance' => $complianceUnits,
            'damage' => [
                'count'      => (int) ($damage['n'] ?? 0),
                'total_cost' => (string) ($damage['total_cost'] ?? '0.00'),
            ],
            'risk_high'    => $riskHighChanges,
            'health_drops' => $healthDrops,
            'brief'        => [
                'paragraph' => $briefFirstPara,
                'stale'     => $briefStale,
                'has_cache' => $brief !== null,
            ],
        ];
    }

    /**
     * Render the inline-styled HTML body for one digest email. Wrapped
     * by EmailService::renderEmailHtml() at send time (logo + footer
     * shell). Output is per-recipient (greeting carries the user's name).
     */
    public static function renderBody(string $userName, array $p): string
    {
        $sym    = (string) settings_get('company.currency_symbol', '$');
        $fmt    = fn(string $v) => $sym . number_format((float) $v, 2);
        $appUrl = rtrim((string) settings_get('app.url', ''), '/');

        $html = '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="padding:24px;background:#ffffff;">';
        $html .= '<tr><td style="font-family:Arial,sans-serif;color:#1c1c1a;">';

        $html .= '<h1 style="margin:0 0 16px;font-size:18px;">Good morning, ' . e($userName) . '</h1>';
        $html .= '<p style="font-size:13px;color:#555;margin:0 0 24px;">Here is your fleet briefing for ' . e(date('l, F j, Y')) . '.</p>';

        // Section 1 — overdue invoices.
        $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
               . 'Overdue Invoices</h2>';
        $html .= '<p style="font-size:13px;margin:0 0 8px;">'
               . '<strong>' . $p['overdue']['count'] . '</strong> invoice(s) past due totalling <strong>' . e($fmt($p['overdue']['total'])) . '</strong>.</p>';
        if (!empty($p['overdue']['top'])) {
            $html .= '<table cellpadding="6" cellspacing="0" border="0" style="font-size:12px;border-collapse:collapse;margin-bottom:20px;">';
            $html .= '<tr style="background:#f5f5f4;"><th align="left">Customer</th><th align="right">Owed</th><th align="right">Max Days</th></tr>';
            foreach ($p['overdue']['top'] as $row) {
                $html .= '<tr><td>' . e($row['company_name']) . '</td>'
                      .  '<td align="right">' . e($fmt((string) $row['total'])) . '</td>'
                      .  '<td align="right">' . (int) $row['max_days'] . '</td></tr>';
            }
            $html .= '</table>';
        }

        // Section 2 — compliance expiring this week.
        $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
               . 'Compliance Expiring This Week</h2>';
        if (!empty($p['compliance'])) {
            $count = count($p['compliance']);
            $html .= '<p style="font-size:13px;margin:0 0 8px;"><strong>' . $count . '</strong> unit(s) with documents expiring within 7 days:</p>';
            $html .= '<ul style="font-size:12px;margin:0 0 20px;padding-left:18px;">';
            foreach ($p['compliance'] as $u) {
                $html .= '<li>Unit ' . e($u['unit_number']) . ' — earliest expiry ' . e($u['earliest']) . '</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<p style="font-size:13px;margin:0 0 20px;color:#666;">No compliance documents expiring this week.</p>';
        }

        // Section 3 — open damage claims.
        $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
               . 'Open Damage Claims</h2>';
        $html .= '<p style="font-size:13px;margin:0 0 20px;">'
               . '<strong>' . $p['damage']['count'] . '</strong> open claim(s), estimated repair cost <strong>' . e($fmt($p['damage']['total_cost'])) . '</strong>.</p>';

        // Section 4 — customer risk to HIGH overnight.
        $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
               . 'Customer Risk Changes Overnight</h2>';
        if (!empty($p['risk_high'])) {
            $html .= '<ul style="font-size:12px;margin:0 0 20px;padding-left:18px;">';
            foreach ($p['risk_high'] as $r) {
                $html .= '<li>' . e($r['entity_label'] ?: ('Customer #' . (int) $r['entity_id'])) . ' — promoted to HIGH risk</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<p style="font-size:13px;margin:0 0 20px;color:#666;">No customers transitioned to HIGH risk overnight.</p>';
        }

        // Section 5 — equipment health drops.
        $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
               . 'Equipment Health Drops Overnight</h2>';
        if (!empty($p['health_drops'])) {
            $html .= '<ul style="font-size:12px;margin:0 0 20px;padding-left:18px;">';
            foreach ($p['health_drops'] as $d) {
                $html .= '<li>Unit ' . e($d['entity_label'] ?: ('#' . (int) $d['entity_id']))
                      .  ' — dropped to ' . e($d['new_color']) . ' (score ' . (int) $d['new_score'] . ')</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<p style="font-size:13px;margin:0 0 20px;color:#666;">No units dropped to orange/red overnight.</p>';
        }

        // Section 6 — AI fleet brief excerpt.
        $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
               . 'AI Fleet Brief</h2>';
        if (!empty($p['brief']['paragraph'])) {
            $html .= '<p style="font-size:13px;margin:0 0 8px;">' . e($p['brief']['paragraph']) . '</p>';
            if ($p['brief']['stale']) {
                $html .= '<p style="font-size:11px;color:#999;font-style:italic;margin:0 0 12px;">Brief is from a prior day and may be outdated.</p>';
            }
            if ($appUrl !== '') {
                $html .= '<p style="font-size:12px;margin:0 0 20px;"><a href="' . e($appUrl) . '/admin/dashboard" style="color:#1c1c1a;">View full briefing on the dashboard →</a></p>';
            }
        } else {
            $html .= '<p style="font-size:13px;margin:0 0 20px;color:#666;">No AI brief available yet today.</p>';
        }

        $html .= '</td></tr></table>';
        return $html;
    }

    /**
     * Returns true if there's a non-stale (<= 20h old) cached fleet
     * brief in report_cache. Used by the test-send endpoint to refuse
     * (with a clear message) when there's nothing to test against.
     *
     * Per D-INTEL-3: test-send NEVER triggers a Claude API call. If
     * the cache is empty, the endpoint returns 422 telling the operator
     * to wait until the next cron run (or check that ai.briefing_enabled
     * is on).
     */
    public static function hasCachedBrief(): bool
    {
        $row = db_row(
            "SELECT id FROM report_cache
             WHERE report_type = 'ai_fleet_brief' AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        return $row !== null;
    }
}
