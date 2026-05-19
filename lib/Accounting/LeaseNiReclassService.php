<?php
declare(strict_types=1);

/**
 * lib/Accounting/LeaseNiReclassService.php
 *
 * ASPE 3065.54 NI Balance Sheet presentation: the net investment in
 * lease receivable is segregated between current portion (principal
 * expected within the next 12 months) and long-term portion (beyond
 * 12 months). At inception, the split is captured by
 * AutoEntryBridge::onLeaseInception_* (DR 1090 NI Current + DR 1600
 * NI Long-Term per the schedule). As time progresses, principal that
 * was long-term at inception moves into the next-12-months window —
 * this service computes the rebalancing delta and posts the reclass
 * JE per lease, monthly via the cron at
 * cron/accounting_lease_ni_reclass.php.
 *
 * Key design choice (D-LESSOR-5-NI-RECLASS-PER-LEASE): per-lease
 * balances are computed from the JE trail — no separate per-lease
 * ledger table. The GL DEBIT-SUM minus CREDIT-SUM on each account
 * filtered by source_id=leaseId across all lease_* source_types is
 * the current on-books balance for that lease on that account. Target
 * balances come from the schedule's remaining principal_reduction
 * partitioned at the 12-month period_date boundary.
 *
 * Idempotency (D-LESSOR-5-RECLASS-IDEMPOTENT): the reclass JE
 * reference includes YYYY-MM. Re-running the cron on the same day or
 * within the same month is a no-op (existing JE returned). The cron
 * is scheduled for the first of each month at 05:00 (after period
 * postings at 04:00 from accounting_recurring_entries.php).
 *
 * @session S-ACCT-LESSOR-5
 */

namespace FleetForge\Accounting;

class LeaseNiReclassService
{
    /** Per-row data-integrity tolerance for delta1090 + delta1600 sum. */
    private const INTEGRITY_TOLERANCE = '0.02';

    // ============================================================
    // B1. computeBalancesForLease — per-lease NI snapshot
    // ============================================================

    /**
     * Compute current GL balances + target balances + deltas for one
     * capital lease. Returns the shape consumed by reclassLease() and
     * by the admin "next reclass preview" UI.
     *
     * @return array{
     *   leaseId:int, contract_number:string,
     *   currentBalance1090:string, currentBalance1600:string,
     *   target1090:string, target1600:string,
     *   delta1090:string, delta1600:string,
     *   reclass_needed:bool, integrity_drift:string
     * }
     */
    public static function computeBalancesForLease(int $leaseId): array
    {
        $lease = \db_row(
            "SELECT id, contract_number, classification, status
               FROM leases WHERE id = ? AND deleted_at IS NULL",
            [$leaseId]
        );
        if (!$lease) {
            throw new \InvalidArgumentException("Lease #{$leaseId} not found.");
        }

        $niCurrentAcct  = (int) AccountingService::setting('accounting.lessor_ni_current_account_id', 0);
        $niLongTermAcct = (int) AccountingService::setting('accounting.lessor_ni_longterm_account_id', 0);
        if ($niCurrentAcct <= 0 || $niLongTermAcct <= 0) {
            throw new \RuntimeException(
                'LeaseNiReclassService: NI account ids not configured. '
                . 'Run S-ACCT-LESSOR-1 migration before using the reclass service.'
            );
        }

        $current1090 = self::glBalanceForLeaseAccount($leaseId, $niCurrentAcct);
        $current1600 = self::glBalanceForLeaseAccount($leaseId, $niLongTermAcct);
        $currentTotal = bcadd($current1090, $current1600, 2);

        // Target split derived from the schedule's TIMING, applied to the
        // current on-books NI total. We don't use the schedule's absolute
        // sums directly because they can diverge from on-books NI for two
        // legitimate reasons:
        //   (a) DF leases — schedule built from FV − IDC while inception
        //       NI posts at FV (the Deferred IDC contra accounts for the
        //       gap separately, amortizing via period JEs per D-LESSOR-4-
        //       PERIOD-EXTENDED). Schedule Σ principal < on-books NI by
        //       remaining unamortized IDC.
        //   (b) LESSOR-2's rounding-tail correction (D-LESSOR-4-PERIOD-
        //       PRINCIPAL-DERIVATION) — last regular period's stored
        //       principal can exceed (cash − finance) on disk, while the
        //       posted JE used derived principal. Cumulative drift here
        //       is typically pennies but real.
        // The TIMING (what fraction of remaining principal falls in the
        // next 12 months vs beyond) is correct in the schedule regardless
        // of either absolute-amount discrepancy. So: compute the
        // proportion, apply to current_total.
        $today = date('Y-m-d');
        $twelveMonthsOut = date('Y-m-d', strtotime('+12 months', strtotime($today)));

        $rowCurrent = \db_row(
            "SELECT COALESCE(SUM(principal_reduction), '0.00') AS total
               FROM acc_lease_amortization_schedules
              WHERE lease_id = ? AND status = 'scheduled'
                AND period_date <= ?",
            [$leaseId, $twelveMonthsOut]
        );
        $rowLongTerm = \db_row(
            "SELECT COALESCE(SUM(principal_reduction), '0.00') AS total
               FROM acc_lease_amortization_schedules
              WHERE lease_id = ? AND status = 'scheduled'
                AND period_date > ?",
            [$leaseId, $twelveMonthsOut]
        );
        $scheduledNext12  = (string) ($rowCurrent['total']  ?? '0.00');
        $scheduledBeyond  = (string) ($rowLongTerm['total'] ?? '0.00');
        $scheduledTotal   = bcadd($scheduledNext12, $scheduledBeyond, 2);

        if (bccomp($scheduledTotal, '0', 2) <= 0 || bccomp($currentTotal, '0', 2) <= 0) {
            // Fully amortized OR no on-books NI — nothing to reclass.
            $target1090 = $current1090;
            $target1600 = $current1600;
        } else {
            // Proportion of remaining principal in next 12 months,
            // scaled to on-books NI total. bcmath scale=10 for the
            // ratio to keep penny precision after the multiply.
            $currentRatio = bcdiv($scheduledNext12, $scheduledTotal, 10);
            $target1090   = bcmul($currentTotal, $currentRatio, 2);
            $target1600   = bcsub($currentTotal, $target1090, 2);
        }

        $delta1090 = bcsub($target1090, $current1090, 2);
        $delta1600 = bcsub($target1600, $current1600, 2);

        // Integrity: by construction target1090 + target1600 = currentTotal,
        // so delta1090 + delta1600 = currentTotal − (current1090 + current1600)
        // = 0. Anything > $0.02 is a pure bcmath rounding artifact in the
        // ratio multiply.
        $sumDelta = bcadd($delta1090, $delta1600, 2);
        $absSum = $sumDelta[0] === '-' ? substr($sumDelta, 1) : $sumDelta;

        $reclassNeeded = bccomp($delta1090, '0', 2) !== 0;

        return [
            'leaseId'             => $leaseId,
            'contract_number'     => $lease['contract_number'],
            'classification'      => $lease['classification'],
            'currentBalance1090'  => $current1090,
            'currentBalance1600'  => $current1600,
            'target1090'          => $target1090,
            'target1600'          => $target1600,
            'delta1090'           => $delta1090,
            'delta1600'           => $delta1600,
            'reclass_needed'      => $reclassNeeded,
            'integrity_drift'     => $absSum,
        ];
    }

    // ============================================================
    // B2. reclassLease — post the reclass JE for one lease
    // ============================================================

    /**
     * Post the monthly reclass JE for one lease. Idempotent within a
     * month: re-calling returns the existing JE without double-posting.
     *
     * Returns null when no reclass is needed (delta1090 = 0) — typical
     * when the lease is brand new (still has 12+ months of long-term
     * principal that won't roll forward yet) or has fully matured.
     *
     * @param int  $leaseId
     * @param int  $userId
     * @param string|null $asOfYearMonth  YYYY-MM (default: current month);
     *   used in the JE reference for idempotency tagging.
     * @throws \RuntimeException on integrity drift > $0.02
     */
    public static function reclassLease(
        int $leaseId,
        int $userId,
        ?string $asOfYearMonth = null
    ): ?array {
        if (!self::isBridgeEnabled())       return null;
        if (!self::isLessorModuleEnabled()) {
            error_log("[LeaseNiReclassService] lessor module disabled — lease #{$leaseId} skipped");
            return null;
        }

        $balances = self::computeBalancesForLease($leaseId);

        $month = $asOfYearMonth ?? date('Y-m');
        // Lease contract number may contain '/' or other URL-unsafe chars;
        // keep it as-is in the reference since acc_journal_entries.reference
        // is a free-text varchar.
        $reference = "LSE-NI-RECLASS-{$balances['contract_number']}-{$month}";

        // Idempotency BEFORE reclass_needed gate — if we already posted
        // this month's reclass, return that JE even when balances now
        // match target (the prior post already moved them). Lets the
        // operator and the cron's "already done" report identify the
        // JE for this month at a glance.
        $existing = \db_row(
            "SELECT * FROM acc_journal_entries
              WHERE source_type = 'lease_ni_reclass'
                AND source_id   = ?
                AND reference   = ?
              LIMIT 1",
            [$leaseId, $reference]
        );
        if ($existing) {
            return ['je' => $existing, 'reclass_amount' => '0.00', 'direction' => 'idempotent_noop'];
        }

        if (!$balances['reclass_needed']) {
            return null;
        }

        // Integrity drift guard. With the proportion-based target,
        // this should only trip on bcmath rounding artifacts >$0.02
        // (extremely rare since the ratio is computed at scale=10).
        if (bccomp($balances['integrity_drift'], self::INTEGRITY_TOLERANCE, 2) > 0) {
            throw new \RuntimeException(sprintf(
                'NI reclass integrity drift for lease #%d (%s): delta1090=%s + delta1600=%s = %s '
                . '(tolerance %s). Schedule projection vs GL balance out of sync.',
                $leaseId, $balances['contract_number'],
                $balances['delta1090'], $balances['delta1600'],
                $balances['integrity_drift'], self::INTEGRITY_TOLERANCE
            ));
        }

        return \db_transaction(function () use ($leaseId, $userId, $balances, $reference, $month): array {
            \db_execute("SELECT GET_LOCK(?, 10) AS got", ["ff_lease_ni_reclass_{$leaseId}"]);

            try {
                $delta1090 = $balances['delta1090'];

                $niCurrentAcct  = (int) AccountingService::setting('accounting.lessor_ni_current_account_id');
                $niLongTermAcct = (int) AccountingService::setting('accounting.lessor_ni_longterm_account_id');

                // Direction: delta1090 > 0 means more principal becomes
                // current — DR 1090 / CR 1600. Reverse for the unusual
                // negative case (e.g. an early payment shrank current
                // beyond expectation between cron runs).
                if (bccomp($delta1090, '0', 2) > 0) {
                    $direction = 'lt_to_current';
                    $amount    = $delta1090;
                    $drAcct    = $niCurrentAcct;
                    $crAcct    = $niLongTermAcct;
                } else {
                    $direction = 'current_to_lt';
                    $amount    = bcmul($delta1090, '-1', 2);
                    $drAcct    = $niLongTermAcct;
                    $crAcct    = $niCurrentAcct;
                }

                $jeLines = [
                    [
                        'account_id' => $drAcct,
                        'debit'      => $amount,
                        'credit'     => '0.00',
                        'description' => $direction === 'lt_to_current'
                            ? "Reclass NI to current — {$balances['contract_number']} {$month}"
                            : "Reclass NI to long-term — {$balances['contract_number']} {$month}",
                    ],
                    [
                        'account_id' => $crAcct,
                        'debit'      => '0.00',
                        'credit'     => $amount,
                        'description' => $direction === 'lt_to_current'
                            ? "Reclass NI from long-term — {$balances['contract_number']} {$month}"
                            : "Reclass NI from current — {$balances['contract_number']} {$month}",
                    ],
                ];

                // Period resolution uses today — the reclass JE is a current
                // accounting-period event, not back-dated.
                $periodInfo = AccountingService::periodForDate(date('Y-m-d'));
                $entryDate = $periodInfo && $periodInfo['status'] === 'open'
                    ? date('Y-m-d')
                    : AccountingService::currentOpenPeriod()['start_date'];

                $je = JournalEntryService::create([
                    'entry_date'       => $entryDate,
                    'description'      => "Lease NI reclass — {$balances['contract_number']} {$month}",
                    'entry_type'       => 'system',
                    'reference'        => $reference,
                    'source_type'      => 'lease_ni_reclass',
                    'source_id'        => $leaseId,
                    'post_immediately' => true,
                ], $jeLines, $userId);

                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'action'      => 'create',
                    'module'      => 'accounting',
                    'entity_type' => 'lease',
                    'entity_id'   => $leaseId,
                    'entity_label' => $balances['contract_number'],
                    'notes'       => sprintf(
                        'NI reclass JE %s: direction=%s, amount=%s, target_1090=%s, target_1600=%s, prior_1090=%s, prior_1600=%s.',
                        $je['entry_number'], $direction, $amount,
                        $balances['target1090'], $balances['target1600'],
                        $balances['currentBalance1090'], $balances['currentBalance1600']
                    ),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                return [
                    'je'              => $je,
                    'reclass_amount'  => $amount,
                    'direction'       => $direction,
                    'balances_before' => $balances,
                ];
            } finally {
                \db_execute("SELECT RELEASE_LOCK(?)", ["ff_lease_ni_reclass_{$leaseId}"]);
            }
        });
    }

    // ============================================================
    // B3. reclassAllActive — batch processor for cron
    // ============================================================

    /**
     * Iterate all active capital leases and post each one's reclass JE.
     * Per-lease exceptions are caught and recorded; one failure does
     * NOT abort the batch (same pattern as recurring-entries cron).
     *
     * @return array { processed, posted, skipped, failed, errors[] }
     */
    public static function reclassAllActive(int $userId, ?string $asOfYearMonth = null): array
    {
        $leases = \db_select(
            "SELECT id, contract_number FROM leases
              WHERE classification IN ('sales_type','direct_financing')
                AND status = 'active'
                AND deleted_at IS NULL
              ORDER BY id ASC"
        );

        $results = [
            'processed' => 0,
            'posted'    => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        foreach ($leases as $lease) {
            try {
                $r = self::reclassLease((int) $lease['id'], $userId, $asOfYearMonth);
                $results['processed']++;
                if ($r) {
                    if (($r['direction'] ?? '') === 'idempotent_noop') {
                        $results['skipped']++;
                    } else {
                        $results['posted']++;
                    }
                } else {
                    $results['skipped']++;
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $msg = sprintf('lease=%d %s: %s',
                    (int) $lease['id'], $lease['contract_number'], $e->getMessage()
                );
                $results['errors'][] = $msg;
                error_log("LeaseNiReclassService::reclassAllActive: {$msg}");
                if (class_exists('\FleetForge\Observability\Sentry')) {
                    \FleetForge\Observability\Sentry::captureException($e);
                }
            }
        }

        return $results;
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Sum DEBIT − CREDIT for one lease on one account, across all
     * posted lease_* JEs. Returns bcmath string (scale=2).
     */
    private static function glBalanceForLeaseAccount(int $leaseId, int $accountId): string
    {
        $row = \db_row(
            "SELECT COALESCE(SUM(jel.debit) - SUM(jel.credit), '0.00') AS bal
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
              WHERE je.source_id = ?
                AND je.source_type IN ('lease_inception','lease_period',
                                       'lease_ni_reclass','lease_termination',
                                       'lease_residual_impairment')
                AND je.status     = 'posted'
                AND jel.account_id = ?",
            [$leaseId, $accountId]
        );
        return bcadd((string) ($row['bal'] ?? '0.00'), '0', 2);
    }

    private static function isBridgeEnabled(): bool
    {
        return (bool) AccountingService::setting('accounting.enabled', false);
    }

    private static function isLessorModuleEnabled(): bool
    {
        return (string) AccountingService::setting('accounting.lessor_module_enabled', '0') === '1';
    }
}
