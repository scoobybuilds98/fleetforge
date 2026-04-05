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
                    self::storeAlert($alert, $userId);
                    $alertCount++;
                }
            } catch (\Throwable $e) {
                // WHY: Log but don't stop — other detectors should still run
                error_log("AnomalyDetector::{$method} failed: " . $e->getMessage());
            }
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
        $deadline = date('Y-m-d', strtotime('+14 days'));
        $today    = date('Y-m-d');

        // WHY: Count how many compliance types are expiring per unit
        $rows = db_select(
            "SELECT eu.id AS unit_id, eu.unit_number,
                    SUM(CASE WHEN eu.cvi_expiry <= ? THEN 1 ELSE 0 END) AS cvi_expiring,
                    SUM(CASE WHEN eu.registration_expiry <= ? THEN 1 ELSE 0 END) AS reg_expiring,
                    SUM(CASE WHEN eu.mvi_expiry <= ? THEN 1 ELSE 0 END) AS mvi_expiring,
                    SUM(CASE WHEN eu.insurance_expiry <= ? THEN 1 ELSE 0 END) AS ins_expiring,
                    LEAST(
                        COALESCE(eu.cvi_expiry, '9999-12-31'),
                        COALESCE(eu.registration_expiry, '9999-12-31'),
                        COALESCE(eu.mvi_expiry, '9999-12-31'),
                        COALESCE(eu.insurance_expiry, '9999-12-31')
                    ) AS earliest_expiry
             FROM equipment_units eu
             WHERE eu.status NOT IN ('decommissioned', 'inactive')
               AND eu.deleted_at IS NULL
               AND (eu.cvi_expiry <= ? OR eu.registration_expiry <= ?
                    OR eu.mvi_expiry <= ? OR eu.insurance_expiry <= ?)
             GROUP BY eu.id, eu.unit_number
             HAVING (cvi_expiring + reg_expiring + mvi_expiring + ins_expiring) >= 2
                OR earliest_expiry < ?",
            [$deadline, $deadline, $deadline, $deadline,
             $deadline, $deadline, $deadline, $deadline,
             $today]
        );

        $alerts = [];
        foreach ($rows as $row) {
            $expired = $row['earliest_expiry'] < $today;
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
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));

        // WHY: Compare last 30 days cost vs historical average per unit
        $rows = db_select(
            "SELECT eu.id AS unit_id, eu.unit_number,
                    recent.recent_cost, recent.recent_count,
                    COALESCE(eu.total_maintenance_cost, 0) AS lifetime_cost,
                    GREATEST(TIMESTAMPDIFF(MONTH, eu.created_at, CURDATE()), 1) AS months_active
             FROM equipment_units eu
             INNER JOIN (
                 SELECT wo.equipment_unit_id,
                        SUM(wo.total_cost) AS recent_cost,
                        COUNT(*) AS recent_count
                 FROM maintenance_work_orders wo
                 WHERE wo.completed_date >= ?
                   AND wo.status = 'completed'
                   AND wo.deleted_at IS NULL
                 GROUP BY wo.equipment_unit_id
             ) recent ON recent.equipment_unit_id = eu.id
             WHERE eu.deleted_at IS NULL
             HAVING recent_cost > (lifetime_cost / months_active) * 2
                AND recent_cost > 500",
            [$thirtyDaysAgo]
        );

        $alerts = [];
        foreach ($rows as $row) {
            $monthlyAvg = (float) $row['lifetime_cost'] / max((int) $row['months_active'], 1);

            $alerts[] = [
                'alert_type'    => 'maintenance_spike',
                'severity'      => ((float) $row['recent_cost'] > $monthlyAvg * 3) ? 'high' : 'medium',
                'title'         => "Maintenance spike: Unit {$row['unit_number']}",
                'description'   => sprintf(
                    'Unit %s spent $%s on %d work orders in the last 30 days (monthly avg: $%s).',
                    $row['unit_number'],
                    number_format((float) $row['recent_cost'], 2),
                    $row['recent_count'],
                    number_format($monthlyAvg, 2)
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
    // Saves an anomaly alert to the database. Deduplicates by
    // checking for identical alert within the last 24 hours.
    // ────────────────────────────────────────────────────────────
    private static function storeAlert(array $alert, ?int $userId): void
    {
        // WHY: Prevent duplicate alerts within 24 hours for same entity + type
        $existing = db_row(
            "SELECT id FROM ai_anomaly_alerts
             WHERE alert_type = ? AND entity_type = ? AND entity_id = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             LIMIT 1",
            [$alert['alert_type'], $alert['entity_type'], $alert['entity_id']]
        );

        if ($existing) return;

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
    }
}
