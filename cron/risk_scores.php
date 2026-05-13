<?php
declare(strict_types=1);

/**
 * cron/risk_scores.php
 *
 * Customer risk score recalculator — runs nightly at 03:30.
 *
 * Scans every active (non-deleted) customer and recomputes their risk_score
 * (low/medium/high) per FLEETFORGE_SPEC_FINAL.md §12. Writes to
 * customers.risk_score and audit_logs every transition.
 *
 * Risk formula (locked, S-CRON-3):
 *   Start: LOW.
 *   Promote to HIGH if ANY:
 *     - Any invoice overdue > 45 days
 *     - outstanding_balance > credit_limit (bcmath)
 *     - customer.status IN ('suspended','credit_hold')
 *     - customer.collection_status IN ('collections','legal')   [D-D]
 *     - 4+ invoices overdue in last 12 months
 *     - 2+ damage claims in last 12 months
 *   Else promote to MEDIUM if ANY:
 *     - Any invoice overdue > 15 days
 *     - 2+ invoices overdue in last 12 months
 *     - outstanding_balance > 50% of credit_limit (bcmath)
 *     - Any damage claim in last 6 months
 *   Else LOW.
 *
 * Outstanding-balance reads use Path B semantics (D45): customers.outstanding_balance
 * already excludes drafts and voids. We do NOT recompute it here.
 *
 * Crontab (production): 30 3 * * * php /var/www/fleetforge/cron/risk_scores.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/risk_scores.php
 *
 * @session S-CRON-3
 * @audit   #3 (missing crons — risk scoring)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// D21: Advisory lock prevents overlapping runs.
$lock = db_row("SELECT GET_LOCK('ff_cron_risk_scores', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$processed = 0;
$updated   = 0;
$promoted  = 0;
$demoted   = 0;
$errors    = 0;
$bandCounts = ['low' => 0, 'medium' => 0, 'high' => 0];

try {
    $today          = date('Y-m-d');
    $cutoff6months  = date('Y-m-d H:i:s', strtotime('-6 months'));
    $cutoff12months = date('Y-m-d H:i:s', strtotime('-12 months'));

    // -----------------------------------------------------------------------
    // Pull every customer + the precomputed risk-input booleans/counts.
    // WHY: subqueries inside the SELECT keep the per-customer loop pure
    // math, no extra round-trips per row. 2-3k customers in a row is fine
    // for one query under any realistic fleet size.
    //
    // outstanding_balance (Path B per D45) comes from the customers table
    // directly — this cron never recomputes counters.
    // -----------------------------------------------------------------------
    $customers = db_select(
        "SELECT
            c.id,
            c.company_name,
            c.status,
            c.collection_status,
            c.credit_limit,
            c.outstanding_balance,
            c.risk_score AS old_score,
            (SELECT MAX(DATEDIFF(CURDATE(), i.due_date))
                FROM invoices i
                WHERE i.customer_id = c.id
                  AND i.deleted_at IS NULL
                  AND i.status IN ('sent','overdue','partially_paid')
                  AND i.balance_due > 0
                  AND i.due_date < CURDATE()
            ) AS max_overdue_days,
            (SELECT COUNT(*)
                FROM invoices i
                WHERE i.customer_id = c.id
                  AND i.deleted_at IS NULL
                  AND i.due_date < CURDATE()
                  AND i.due_date >= ?
                  AND (
                      (i.status IN ('sent','overdue','partially_paid') AND i.balance_due > 0)
                      OR (i.status = 'paid' AND i.paid_date IS NOT NULL AND i.paid_date > i.due_date)
                  )
            ) AS overdue_count_12mo,
            (SELECT COUNT(*)
                FROM damage_claims dc
                JOIN leases l ON l.id = dc.lease_id
                WHERE l.customer_id = c.id
                  AND dc.deleted_at IS NULL
                  AND dc.created_at >= ?
            ) AS damage_count_6mo,
            (SELECT COUNT(*)
                FROM damage_claims dc
                JOIN leases l ON l.id = dc.lease_id
                WHERE l.customer_id = c.id
                  AND dc.deleted_at IS NULL
                  AND dc.created_at >= ?
            ) AS damage_count_12mo
         FROM customers c
         WHERE c.deleted_at IS NULL",
        [$cutoff12months, $cutoff6months, $cutoff12months]
    );

    foreach ($customers as $cust) {
        $custId      = (int)$cust['id'];
        $name        = $cust['company_name'];
        $oldScore    = $cust['old_score'] ?? 'low';
        $custStatus  = $cust['status'] ?? 'active';
        $collStatus  = $cust['collection_status'] ?? 'current';
        $creditLimit = $cust['credit_limit']; // string|null
        $outstanding = (string)($cust['outstanding_balance'] ?? '0.00');
        $maxOverdue  = $cust['max_overdue_days'] !== null ? (int)$cust['max_overdue_days'] : 0;
        $count12mo   = (int)$cust['overdue_count_12mo'];
        $damage6mo   = (int)$cust['damage_count_6mo'];
        $damage12mo  = (int)$cust['damage_count_12mo'];

        try {
            $newScore = compute_customer_risk_score(
                custStatus:    $custStatus,
                collStatus:    $collStatus,
                creditLimit:   $creditLimit,
                outstanding:   $outstanding,
                maxOverdue:    $maxOverdue,
                count12mo:     $count12mo,
                damage6mo:     $damage6mo,
                damage12mo:    $damage12mo,
            );

            $processed++;
            $bandCounts[$newScore]++;

            if ($oldScore !== $newScore) {
                db_update('customers', [
                    'risk_score' => $newScore,
                ], 'id = ?', [$custId]);

                $updated++;
                $direction = risk_rank($newScore) > risk_rank($oldScore) ? 'promoted' : 'demoted';
                if ($direction === 'promoted') $promoted++;
                else                            $demoted++;

                db_insert('audit_log', [
                    'user_id'      => null,
                    'user_name'    => 'system',
                    'action'       => 'status_change',
                    'module'       => 'customers',
                    'entity_type'  => 'customer',
                    'entity_id'    => $custId,
                    'entity_label' => (string)$name,
                    'old_values'   => json_encode(['risk_score' => $oldScore]),
                    'new_values'   => json_encode(['risk_score' => $newScore]),
                    'notes'        => "Risk recalc: {$oldScore} → {$newScore} (overdue_days={$maxOverdue}, "
                                    . "12mo_overdue={$count12mo}, 12mo_damage={$damage12mo}, "
                                    . "outstanding={$outstanding}, credit_limit=" . ($creditLimit ?? 'none')
                                    . ", status={$custStatus}, collection={$collStatus})",
                    'ip_address'   => '127.0.0.1',
                ]);

                // Notify only when promoted to HIGH. WHY: promotion to
                // medium is informational; HIGH triggers AR action.
                if ($newScore === 'high') {
                    \FleetForge\Notifications\NotificationService::notify(
                        type:       'customer.risk_high',
                        title:      "Customer risk: HIGH — {$name}",
                        message:    "{$name} promoted to HIGH risk (was {$oldScore}). "
                                  . "Max invoice overdue: {$maxOverdue} days. "
                                  . "Outstanding: {$outstanding}. "
                                  . "Status: {$custStatus} / {$collStatus}.",
                        entityType: 'customer',
                        entityId:   $custId,
                        url:        '/fleetforge/customers/show?id=' . $custId,
                        severity:   'critical'
                    );
                }
            }

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON risk_scores] Customer #{$custId} ({$name}): " . $e->getMessage());
        }
    }

    $summary = "risk_scores completed: processed={$processed} updated={$updated} "
             . "bands(low={$bandCounts['low']} medium={$bandCounts['medium']} high={$bandCounts['high']}) "
             . "promoted={$promoted} demoted={$demoted} errors={$errors}";
    error_log("[CRON] {$summary}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'customers',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'risk_scores',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log("[CRON risk_scores] Fatal: " . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'customers',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'risk_scores',
        'notes'        => 'risk_scores fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_risk_scores')", []);
}

/**
 * compute_customer_risk_score() — pure function.
 *
 * HIGH triggers evaluated first (any one promotes immediately). Otherwise
 * MEDIUM triggers. Otherwise LOW.
 *
 * Monetary comparisons use bcmath (credit_limit + outstanding_balance are
 * DECIMAL(12,2)). Float arithmetic on money would drift the threshold.
 */
function compute_customer_risk_score(
    string $custStatus,
    string $collStatus,
    ?string $creditLimit,
    string $outstanding,
    int $maxOverdue,
    int $count12mo,
    int $damage6mo,
    int $damage12mo,
): string {
    // -----------------------------------------------------------------------
    // HIGH triggers
    // -----------------------------------------------------------------------
    if ($maxOverdue > 45)                                             return 'high';
    if (in_array($custStatus, ['suspended', 'credit_hold'], true))    return 'high';
    if (in_array($collStatus, ['collections', 'legal'], true))        return 'high';   // D-D
    if ($count12mo >= 4)                                              return 'high';
    if ($damage12mo >= 2)                                             return 'high';
    if ($creditLimit !== null
        && bccomp($creditLimit, '0.00', 2) > 0
        && bccomp($outstanding, $creditLimit, 2) > 0)                 return 'high';

    // -----------------------------------------------------------------------
    // MEDIUM triggers
    // -----------------------------------------------------------------------
    if ($maxOverdue > 15)                                             return 'medium';
    if ($count12mo >= 2)                                              return 'medium';
    if ($damage6mo >= 1)                                              return 'medium';
    if ($creditLimit !== null && bccomp($creditLimit, '0.00', 2) > 0) {
        // 50% threshold = credit_limit / 2 in bcmath, scale 2.
        $half = bcdiv($creditLimit, '2', 2);
        if (bccomp($outstanding, $half, 2) > 0)                       return 'medium';
    }

    return 'low';
}

/**
 * risk_rank() — order risk levels for direction comparison.
 */
function risk_rank(string $score): int
{
    return match ($score) {
        'low'    => 0,
        'medium' => 1,
        'high'   => 2,
        default  => -1,
    };
}
