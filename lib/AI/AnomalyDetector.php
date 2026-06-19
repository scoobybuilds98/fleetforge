<?php
declare(strict_types=1);

namespace FleetForge\AI;

/**
 * lib/AI/AnomalyDetector.php
 *
 * Statistical anomaly detection for FleetForge data.
 * Runs as a nightly cron job to detect unusual patterns and
 * stores alerts in the ai_anomaly_alerts table.
 *
 * Detection categories:
 *   - payment_anomaly   — Late payments, unusual amounts, missed payments
 *   - utilization_drop  — Sudden drop in fleet utilization
 *   - revenue_anomaly   — Revenue significantly above/below average
 *   - compliance_risk   — Multiple compliance docs expiring simultaneously
 *   - maintenance_spike — Unusual maintenance cost spike for a unit
 *   - customer_risk     — Customer risk indicators (credit hold, high overdue)
 *
 * Architecture:
 *   - Pure SQL-based statistical detection (no ML dependencies)
 *   - Each detector runs independently, failures isolated
 *   - Results stored in ai_anomaly_alerts table
 *   - Optional AI enrichment: Claude explains detected anomalies
 *
 * @depends includes/db.php
 * @session S027
 */
class AnomalyDetector
{
    /** After an alert is acknowledged, stay quiet about the same condition this long. */
    private const ACK_COOLDOWN_DAYS = 7;
    /** Auto-acknowledge unacknowledged alerts older than this (stop re-surfacing stale ones). */
    private const RETENTION_AUTOACK_DAYS = 30;
    /** Hard-delete alerts older than this so the table can't grow unbounded. */
    private const RETENTION_DELETE_DAYS = 90;

    // ────────────────────────────────────────────────────────────
    // runAll()
    //
    // Executes all anomaly detection checks. Called by the
    // nightly cron job. Returns count of new alerts created.
    //
    // @param  int|null $userId  User who triggered (null = cron)
    // @return int               Number of new alerts created
    // ────────────────────────────────────────────────────────────
    public static function runAll(?int $userId = null): int
    {
        $alertCount = 0;

        // WHY: Each detector is independent — failures don't cascade
        $detectors = [
            'detectOverdueSpikes',
            'detectComplianceRisks',
            'detectMaintenanceSpikes',
            'detectCustomerRisks',
            'detectUtilizationDrop',
        ];

        foreach ($detectors as $method) {
            try {
                $alerts = self::$method();
                foreach ($alerts as $alert) {
                    // Count only genuinely-new inserts (storeAlert returns false
                    // when it de-dups/escalates-in-place) so the cron's "N new
                    // alerts" line is accurate.
                    if (self::storeAlert($alert, $userId)) {
                        $alertCount++;
                    }
                }
            } catch (\Throwable $e) {
                // WHY: Log but don't stop — other detectors should still run
                error_log("AnomalyDetector::{$method} failed: " . $e->getMessage());
            }
        }

        // Retention sweep — auto-ack stale open alerts + prune very old rows so a
        // chronic condition can't grow ai_anomaly_alerts unbounded. Isolated so a
        // sweep failure never masks the scan result.
        try {
            self::pruneStaleAlerts();
        } catch (\Throwable $e) {
            error_log('AnomalyDetector::pruneStaleAlerts failed: ' . $e->getMessage());
        }

        return $alertCount;
    }

    // ────────────────────────────────────────────────────────────
    // getRecentAlerts()
    //
    // Retrieves recent anomaly alerts for display.
    //
    // @param  int  $limit  Max alerts to return
    // @param  bool $unreadOnly  Only show unacknowledged alerts
    // @return array
    // ────────────────────────────────────────────────────────────
    public static function getRecentAlerts(int $limit = 20, bool $unreadOnly = false): array
    {
        $where = '1=1';
        if ($unreadOnly) {
            $where = 'acknowledged_at IS NULL';
        }

        return db_select(
            "SELECT id, alert_type, severity, title, description,
                    entity_type, entity_id, data_snapshot,
                    created_at, acknowledged_at, acknowledged_by
             FROM ai_anomaly_alerts
             WHERE {$where}
             ORDER BY created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    // ────────────────────────────────────────────────────────────
    // acknowledgeAlert()
    //
    // Marks an alert as acknowledged by a user.
    // ────────────────────────────────────────────────────────────
    public static function acknowledgeAlert(int $alertId, int $userId): void
    {
        db_update('ai_anomaly_alerts', [
            'acknowledged_at' => date('Y-m-d H:i:s'),
            'acknowledged_by' => $userId,
        ], 'id = ?', [$alertId]);
    }

    // ════════════════════════════════════════════════════════════
    //  DETECTORS
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // detectOverdueSpikes
    //
    // Finds customers with a sudden increase in overdue invoices
    // (3+ overdue invoices, or overdue > 60 days).
    // ────────────────────────────────────────────────────────────
    private static function detectOverdueSpikes(): array
    {
        $rows = db_select(
            "SELECT i.customer_id,
                    i.company_name_snapshot AS customer_name,
                    COUNT(*) AS overdue_count,
                    SUM(i.balance_due) AS total_overdue,
                    MAX(DATEDIFF(CURDATE(), i.due_date)) AS max_days_overdue
             FROM invoices i
             WHERE i.status = 'overdue'
               AND i.deleted_at IS NULL
             GROUP BY i.customer_id, i.company_name_snapshot
             HAVING overdue_count >= 3 OR max_days_overdue > 60
             ORDER BY total_overdue DESC"
        );

        $alerts = [];
        foreach ($rows as $row) {
            $severity = ((int) $row['max_days_overdue'] > 90) ? 'high' : 'medium';

            $alerts[] = [
                'alert_type'    => 'payment_anomaly',
                'severity'      => $severity,
                'title'         => "Overdue spike: {$row['customer_name']}",
                'description'   => sprintf(
                    '%s has %d overdue invoices totaling $%s. Oldest is %d days overdue.',
                    $row['customer_name'],
                    $row['overdue_count'],
                    number_format((float) $row['total_overdue'], 2),
                    $row['max_days_overdue']
                ),
                'entity_type'   => 'customer',
                'entity_id'     => (int) $row['customer_id'],
                'data_snapshot' => $row,
            ];
        }

        return $alerts;
    }

    // ────────────────────────────────────────────────────────────
    // detectComplianceRisks
    //
    // Flags units with multiple compliance docs expiring within
    // 14 days, or any already expired.
    // ────────────────────────────────────────────────────────────
    private static function detectComplianceRisks(): array
    {
        // WHY: compute the window bounds in SQL (CURDATE / DATE_ADD) so the date
        // comparison stays in the DB's timezone. Mixing PHP date() (APP_TIMEZONE,
        // America/Vancouver) with UTC DB date columns produced a one-day
        // divergence at the nightly cron's 04:30-UTC run time. `db_today` carries
        // CURDATE() back so the PHP-side `$expired` check uses the same clock.
        $rows = db_select(
            "SELECT eu.id AS unit_id, eu.unit_number, CURDATE() AS db_today,
                    SUM(CASE WHEN eu.cvi_expiry <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) AS cvi_expiring,
                    SUM(CASE WHEN eu.registration_expiry <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) AS reg_expiring,
                    SUM(CASE WHEN eu.mvi_expiry <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) AS mvi_expiring,
                    SUM(CASE WHEN eu.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) AS ins_expiring,
                    LEAST(
                        COALESCE(eu.cvi_expiry, '9999-12-31'),
                        COALESCE(eu.registration_expiry, '9999-12-31'),
                        COALESCE(eu.mvi_expiry, '9999-12-31'),
                        COALESCE(eu.insurance_expiry, '9999-12-31')
                    ) AS earliest_expiry
             FROM equipment_units eu
             WHERE eu.status NOT IN ('decommissioned', 'inactive')
               AND eu.deleted_at IS NULL
               AND (eu.cvi_expiry <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                    OR eu.registration_expiry <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                    OR eu.mvi_expiry <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                    OR eu.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 14 DAY))
             GROUP BY eu.id, eu.unit_number
             HAVING (cvi_expiring + reg_expiring + mvi_expiring + ins_expiring) >= 2
                OR earliest_expiry < CURDATE()"
        );

        $alerts = [];
        foreach ($rows as $row) {
            $expired = $row['earliest_expiry'] < $row['db_today'];
            $severity = $expired ? 'high' : 'medium';
            $expiringCount = (int) $row['cvi_expiring'] + (int) $row['reg_expiring']
                           + (int) $row['mvi_expiring'] + (int) $row['ins_expiring'];

            $alerts[] = [
                'alert_type'    => 'compliance_risk',
                'severity'      => $severity,
                'title'         => ($expired ? 'EXPIRED: ' : 'Expiring: ') . "Unit {$row['unit_number']}",
                'description'   => sprintf(
                    'Unit %s has %d compliance document(s) %s. Earliest: %s.',
                    $row['unit_number'],
                    $expiringCount,
                    $expired ? 'already expired' : 'expiring within 14 days',
                    $row['earliest_expiry']
                ),
                'entity_type'   => 'equipment_unit',
                'entity_id'     => (int) $row['unit_id'],
                'data_snapshot' => $row,
            ];
        }

        return $alerts;
    }

    // ────────────────────────────────────────────────────────────
    // detectMaintenanceSpikes
    //
    // Flags units with maintenance costs in the last 30 days
    // exceeding 2x their average monthly cost.
    // ────────────────────────────────────────────────────────────
    private static function detectMaintenanceSpikes(): array
    {
        // WHY (TZ): the 30-day window is computed in SQL (DATE_SUB(CURDATE(...)))
        // so it doesn't drift against UTC DB dates like a PHP date() value would.
        // WHY (baseline): the comparison must EXCLUDE the recent window —
        // eu.total_maintenance_cost is the monotonic all-time total that already
        // contains recent_cost, so the old "(lifetime/months)*2" baseline was
        // inflated by the spike itself (for a 1-month unit it reduced to
        // recent > recent*2 and never fired). Compare against the PRIOR-period
        // average: (lifetime − recent) / (months − 1), and require ≥2 months of
        // history so a brand-new unit with no baseline doesn't false-positive.
        $rows = db_select(
            "SELECT eu.id AS unit_id, eu.unit_number,
                    recent.recent_cost, recent.recent_count,
                    COALESCE(eu.total_maintenance_cost, 0) AS lifetime_cost,
                    GREATEST(TIMESTAMPDIFF(MONTH, eu.created_at, CURDATE()), 1) AS months_active,
                    GREATEST(COALESCE(eu.total_maintenance_cost, 0) - recent.recent_cost, 0)
                        / GREATEST(TIMESTAMPDIFF(MONTH, eu.created_at, CURDATE()) - 1, 1) AS prior_monthly_avg
             FROM equipment_units eu
             INNER JOIN (
                 SELECT wo.equipment_unit_id,
                        SUM(wo.total_cost) AS recent_cost,
                        COUNT(*) AS recent_count
                 FROM maintenance_work_orders wo
                 WHERE wo.completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   AND wo.status = 'completed'
                   AND wo.deleted_at IS NULL
                 GROUP BY wo.equipment_unit_id
             ) recent ON recent.equipment_unit_id = eu.id
             WHERE eu.deleted_at IS NULL
               AND TIMESTAMPDIFF(MONTH, eu.created_at, CURDATE()) >= 2
             HAVING recent_cost > prior_monthly_avg * 2
                AND recent_cost > 500"
        );

        $alerts = [];
        foreach ($rows as $row) {
            $priorAvg = (float) $row['prior_monthly_avg'];

            $alerts[] = [
                'alert_type'    => 'maintenance_spike',
                'severity'      => ((float) $row['recent_cost'] > $priorAvg * 3) ? 'high' : 'medium',
                'title'         => "Maintenance spike: Unit {$row['unit_number']}",
                'description'   => sprintf(
                    'Unit %s spent $%s on %d work orders in the last 30 days (prior monthly avg: $%s).',
                    $row['unit_number'],
                    number_format((float) $row['recent_cost'], 2),
                    $row['recent_count'],
                    number_format($priorAvg, 2)
                ),
                'entity_type'   => 'equipment_unit',
                'entity_id'     => (int) $row['unit_id'],
                'data_snapshot' => $row,
            ];
        }

        return $alerts;
    }

    // ────────────────────────────────────────────────────────────
    // detectCustomerRisks
    //
    // Flags customers with concerning risk indicators:
    // credit_hold or suspended status, high outstanding balance.
    // ────────────────────────────────────────────────────────────
    private static function detectCustomerRisks(): array
    {
        $rows = db_select(
            "SELECT c.id AS customer_id, c.company_name, c.status,
                    c.risk_score, c.outstanding_balance, c.credit_limit,
                    c.active_lease_count
             FROM customers c
             WHERE c.deleted_at IS NULL
               AND (
                   c.status IN ('credit_hold', 'suspended')
                   OR (c.outstanding_balance > 0 AND c.credit_limit > 0
                       AND c.outstanding_balance > c.credit_limit * 0.9)
                   OR c.risk_score = 'high'
               )
             ORDER BY c.outstanding_balance DESC
             LIMIT 50"
        );

        $alerts = [];
        foreach ($rows as $row) {
            $reasons = [];
            if (in_array($row['status'], ['credit_hold', 'suspended'])) {
                $reasons[] = "status is {$row['status']}";
            }
            if ($row['risk_score'] === 'high') {
                $reasons[] = 'high risk score';
            }
            if ((float) $row['credit_limit'] > 0 && (float) $row['outstanding_balance'] > (float) $row['credit_limit'] * 0.9) {
                $reasons[] = sprintf('at %.0f%% of credit limit', ((float) $row['outstanding_balance'] / (float) $row['credit_limit']) * 100);
            }

            $alerts[] = [
                'alert_type'    => 'customer_risk',
                'severity'      => in_array($row['status'], ['credit_hold', 'suspended']) ? 'high' : 'medium',
                'title'         => "Risk alert: {$row['company_name']}",
                'description'   => sprintf(
                    '%s — %s. Outstanding: $%s, Active leases: %d.',
                    $row['company_name'],
                    implode(', ', $reasons),
                    number_format((float) $row['outstanding_balance'], 2),
                    $row['active_lease_count']
                ),
                'entity_type'   => 'customer',
                'entity_id'     => (int) $row['customer_id'],
                'data_snapshot' => $row,
            ];
        }

        return $alerts;
    }

    // ────────────────────────────────────────────────────────────
    // detectUtilizationDrop
    //
    // Flags if fleet utilization has dropped below 50% or
    // if there are 10+ available units sitting idle.
    // ────────────────────────────────────────────────────────────
    private static function detectUtilizationDrop(): array
    {
        $row = db_row(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'on_lease' THEN 1 ELSE 0 END) AS on_lease,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available
             FROM equipment_units
             WHERE status NOT IN ('decommissioned', 'inactive')
               AND deleted_at IS NULL"
        );

        if (!$row || (int) $row['total'] === 0) return [];

        $total     = (int) $row['total'];
        $onLease   = (int) $row['on_lease'];
        $available  = (int) $row['available'];
        $utilization = $total > 0 ? round($onLease / $total * 100, 1) : 0;

        $alerts = [];

        if ($utilization < 50 && $total >= 5) {
            $alerts[] = [
                'alert_type'    => 'utilization_drop',
                'severity'      => $utilization < 30 ? 'high' : 'medium',
                'title'         => "Low fleet utilization: {$utilization}%",
                'description'   => sprintf(
                    'Fleet utilization is at %s%%. Only %d of %d active units are on lease. %d units are available.',
                    $utilization, $onLease, $total, $available
                ),
                'entity_type'   => 'fleet',
                'entity_id'     => 0,
                'data_snapshot' => $row,
            ];
        }

        return $alerts;
    }

    // ────────────────────────────────────────────────────────────
    // storeAlert()
    //
    // Persists an anomaly alert, de-duplicating so a chronic condition doesn't
    // flood the list on every nightly run. Returns true only when a genuinely
    // new row is inserted (false on de-dup / escalate-in-place / ack-cooldown).
    // ────────────────────────────────────────────────────────────
    private static function storeAlert(array $alert, ?int $userId): bool
    {
        // 1) An OPEN (unacknowledged) alert for this entity+type already covers
        //    the condition — don't insert a nightly duplicate. If THIS run is more
        //    severe, escalate the open alert in place rather than spawning a new
        //    one. (The old logic used a 24h window that re-alerted every night and
        //    was acknowledgment-blind, so acking never stopped the duplicate.)
        $open = db_row(
            "SELECT id, severity FROM ai_anomaly_alerts
              WHERE alert_type = ? AND entity_type = ? AND entity_id = ?
                AND acknowledged_at IS NULL
              ORDER BY id DESC LIMIT 1",
            [$alert['alert_type'], $alert['entity_type'], $alert['entity_id']]
        );
        if ($open) {
            if (self::severityRank((string) $alert['severity']) > self::severityRank((string) $open['severity'])) {
                db_update('ai_anomaly_alerts', [
                    'severity'      => $alert['severity'],
                    'title'         => $alert['title'],
                    'description'   => $alert['description'],
                    'data_snapshot' => json_encode($alert['data_snapshot'] ?? []),
                ], 'id = ?', [(int) $open['id']]);
            }
            return false;
        }

        // 2) The operator ACKNOWLEDGED this condition recently — stay quiet for a
        //    cooldown so a still-active chronic issue doesn't immediately re-alert.
        //    Once the cooldown lapses it re-surfaces as a reminder if still active.
        $ackedRecently = db_row(
            "SELECT id FROM ai_anomaly_alerts
              WHERE alert_type = ? AND entity_type = ? AND entity_id = ?
                AND acknowledged_at IS NOT NULL
                AND acknowledged_at > DATE_SUB(NOW(), INTERVAL " . self::ACK_COOLDOWN_DAYS . " DAY)
              ORDER BY id DESC LIMIT 1",
            [$alert['alert_type'], $alert['entity_type'], $alert['entity_id']]
        );
        if ($ackedRecently) {
            return false;
        }

        db_insert('ai_anomaly_alerts', [
            'alert_type'    => $alert['alert_type'],
            'severity'      => $alert['severity'],
            'title'         => $alert['title'],
            'description'   => $alert['description'],
            'entity_type'   => $alert['entity_type'],
            'entity_id'     => $alert['entity_id'],
            'data_snapshot' => json_encode($alert['data_snapshot'] ?? []),
            'generated_by'  => $userId,
        ]);
        return true;
    }

    /** low/medium/high → 1/2/3 for severity comparisons. */
    private static function severityRank(string $severity): int
    {
        return ['low' => 1, 'medium' => 2, 'high' => 3][$severity] ?? 0;
    }

    // ────────────────────────────────────────────────────────────
    // pruneStaleAlerts()
    //
    // Retention sweep run after each scan: auto-acknowledge unacknowledged alerts
    // older than RETENTION_AUTOACK_DAYS (a chronic condition shouldn't sit open
    // forever) and hard-delete alerts older than RETENTION_DELETE_DAYS so the
    // table can't grow without bound. acknowledged_by is left NULL (system-acked).
    // ────────────────────────────────────────────────────────────
    private static function pruneStaleAlerts(): void
    {
        db_execute(
            "UPDATE ai_anomaly_alerts
                SET acknowledged_at = NOW()
              WHERE acknowledged_at IS NULL
                AND created_at < DATE_SUB(NOW(), INTERVAL " . self::RETENTION_AUTOACK_DAYS . " DAY)"
        );
        db_execute(
            "DELETE FROM ai_anomaly_alerts
              WHERE created_at < DATE_SUB(NOW(), INTERVAL " . self::RETENTION_DELETE_DAYS . " DAY)"
        );
    }
}
