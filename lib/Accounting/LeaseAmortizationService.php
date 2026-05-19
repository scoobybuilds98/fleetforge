<?php
declare(strict_types=1);

/**
 * lib/Accounting/LeaseAmortizationService.php
 *
 * Effective-interest amortization engine for ASPE 3065 sales-type and
 * direct-financing leases (spec §24.3). Three primitives:
 *
 *   solveImplicitRate()  — Newton-Raphson rate solver. Equates PV of
 *                          gross investment (MLP + unguaranteed residual)
 *                          to fair value (less recoverable IDC for
 *                          direct-financing).
 *   buildSchedule()      — Compute the per-period schedule (in-memory).
 *                          Uses the wizard-supplied lease_term_months
 *                          from acc_lease_classifications per
 *                          D-LESSOR-1-TERM (locked 2026-05-19).
 *   generate() / preview() / getSchedule() — orchestration around the
 *                          two above + persistence into
 *                          acc_lease_amortization_schedules.
 *
 * Numeric work is bcmath end-to-end: scale=10 for intermediate
 * computations (PV factors, rate solver derivatives), scale=2 at the
 * storage boundary (DECIMAL(15,2) / (12,2) columns). PHP floats are
 * never used for monetary or rate arithmetic in this file.
 *
 * Posting (transition status='scheduled' → 'posted') is LESSOR-3/4
 * territory, not this session — the engine only builds the schedule.
 *
 * @session S-ACCT-LESSOR-2
 */

namespace FleetForge\Accounting;

class LeaseAmortizationService
{
    private const PV_SCALE        = 10;        // bcmath scale for PV intermediates
    private const STORAGE_SCALE   = 2;         // DECIMAL(15,2) / (12,2) columns
    private const RATE_SCALE_ANN  = 4;         // DECIMAL(7,4) for leases.implicit_rate
    private const MAX_ITERATIONS  = 100;
    private const TOLERANCE       = '0.000001';// 1e-6 in bcmath string form
    private const PERIOD_CAP      = 6000;      // 500 years — defensive bound on operator input

    // ============================================================
    // B1. solveImplicitRate — Newton-Raphson
    // ============================================================

    /**
     * Solve for the monthly implicit rate r such that:
     *   Σ[t=1..n] payment / (1+r)^t
     *     + (guaranteedResidual + unguaranteedResidual) / (1+r)^n
     *     = pvTarget
     *
     * pvTarget:
     *   sales_type        → fairValue
     *   direct_financing  → fairValue − initialDirectCosts
     *
     * The unguaranteed residual IS included in the rate calculation
     * per ASPE 3065 (gross investment = MLP + unguaranteed residual).
     * Sales-type IDC is expensed at inception (no rate adjustment);
     * direct-financing IDC reduces the receivable (which adjusts the
     * pvTarget to FV − IDC).
     *
     * @return string  Monthly rate as bcmath decimal string (10dp).
     * @throws \RuntimeException When the converged rate is negative
     *                            (payments exceed asset value sanity).
     */
    public static function solveImplicitRate(
        string $monthlyPayment,
        int    $termMonths,
        string $guaranteedResidual,
        string $unguaranteedResidual,
        string $fairValue,
        string $initialDirectCosts,
        string $classification
    ): string {
        if ($termMonths <= 0 || $termMonths > self::PERIOD_CAP) {
            throw new \InvalidArgumentException("termMonths out of bounds: {$termMonths}");
        }
        if (bccomp($fairValue, '0', 2) <= 0) {
            throw new \InvalidArgumentException('fairValue must be > 0.');
        }

        $pvTarget = $classification === 'direct_financing'
            ? bcsub($fairValue, $initialDirectCosts, self::PV_SCALE)
            : $fairValue;

        // Total residual that lands at period n.
        $totalResidual = bcadd($guaranteedResidual, $unguaranteedResidual, self::PV_SCALE);

        // Initial guess: monthly rate from the operator-supplied annual
        // (LESSOR-1 wizard) if present; else fallback setting / 12.
        $fallbackAnnual = (string) (AccountingService::setting(
            'accounting.lessor_fallback_borrowing_rate',
            '0.0650'
        ) ?? '0.0650');
        $r = bcdiv($fallbackAnnual, '12', self::PV_SCALE);

        $h = '0.000001';   // central-difference step size (matches TOLERANCE)
        $converged = false;
        $iters = 0;

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $iters++;

            $f      = bcsub(self::pv($r, $monthlyPayment, $termMonths, $totalResidual), $pvTarget, self::PV_SCALE);

            // Central-difference numerical derivative.
            $fPlus  = self::pv(bcadd($r, $h, self::PV_SCALE), $monthlyPayment, $termMonths, $totalResidual);
            $fMinus = self::pv(bcsub($r, $h, self::PV_SCALE), $monthlyPayment, $termMonths, $totalResidual);
            $fPrime = bcdiv(bcsub($fPlus, $fMinus, self::PV_SCALE), bcmul('2', $h, self::PV_SCALE), self::PV_SCALE);

            if (bccomp($fPrime, '0', self::PV_SCALE) === 0) {
                // Flat slope — Newton-Raphson divergence. Bail and use fallback.
                break;
            }

            $rNew = bcsub($r, bcdiv($f, $fPrime, self::PV_SCALE), self::PV_SCALE);

            // |rNew − r| < tolerance → converged.
            $delta = bcsub($rNew, $r, self::PV_SCALE);
            if ($delta[0] === '-') $delta = substr($delta, 1);
            if (bccomp($delta, self::TOLERANCE, self::PV_SCALE) < 0) {
                $r = $rNew;
                $converged = true;
                break;
            }

            $r = $rNew;
        }

        if (!$converged) {
            error_log(sprintf(
                '[LeaseAmortizationService] Newton-Raphson failed to converge in %d iterations. Using fallback %s annual.',
                self::MAX_ITERATIONS,
                $fallbackAnnual
            ));
            $r = bcdiv($fallbackAnnual, '12', self::PV_SCALE);
        }

        // STOP CONDITION: a negative rate means payments don't recover
        // the asset value — likely an operator-entry error (FV too high
        // or payments too low). Refuse to store; let the caller surface
        // a friendly 422.
        if ($r[0] === '-') {
            throw new \RuntimeException(
                'Implicit rate solver produced a negative rate. Check fair value '
                . 'vs payment inputs — payments may not recover the asset value.'
            );
        }

        return $r;
    }

    /**
     * PV of an annuity-immediate of $payment over $n periods at monthly
     * rate $r, plus a $residual lump sum at period $n. All bcmath.
     *
     * Rate = 0 edge case: degrades to nominal sum (no bcdiv-by-zero).
     */
    private static function pv(string $r, string $payment, int $n, string $residual): string
    {
        if (bccomp($r, '0', self::PV_SCALE) === 0) {
            return bcadd(
                bcmul($payment, (string) $n, self::PV_SCALE),
                $residual,
                self::PV_SCALE
            );
        }

        $onePlusR = bcadd('1', $r, self::PV_SCALE);
        $discountFactor = '1';
        $pv = '0';

        $iters = min($n, self::PERIOD_CAP);
        for ($t = 1; $t <= $iters; $t++) {
            $discountFactor = bcmul($discountFactor, $onePlusR, self::PV_SCALE);
            $pv = bcadd($pv, bcdiv($payment, $discountFactor, self::PV_SCALE), self::PV_SCALE);
        }

        // residual lands at period n — reuse the final discount factor.
        if (bccomp($residual, '0', self::PV_SCALE) !== 0) {
            $pv = bcadd($pv, bcdiv($residual, $discountFactor, self::PV_SCALE), self::PV_SCALE);
        }

        return $pv;
    }

    // ============================================================
    // B2. buildSchedule — in-memory amortization rows
    // ============================================================

    /**
     * Build the amortization schedule for a lease + monthly rate.
     * Does NOT touch the DB. Returns an array of period rows in the
     * shape that gets persisted (or returned by preview()).
     *
     * @return array<int,array<string,mixed>>
     * @throws \RuntimeException When the math produces a negative
     *                            closing NI on the final regular period
     *                            (operator-input sanity catch).
     */
    public static function buildSchedule(array $lease, string $monthlyRate): array
    {
        $classification = $lease['classification'] ?? 'operating';
        if (!in_array($classification, ['sales_type', 'direct_financing'], true)) {
            throw new \InvalidArgumentException(
                "Lease classification '{$classification}' does not use an amortization schedule. "
                . 'Only sales-type and direct-financing leases are amortized.'
            );
        }

        // Load wizard-supplied term — D-LESSOR-1-TERM: lease_term_months
        // is operator-supplied, NOT derived from start_date/end_date.
        $classRow = \db_row(
            "SELECT criterion_b_lease_term_months
               FROM acc_lease_classifications
              WHERE lease_id = ?",
            [(int) $lease['id']]
        );
        if (!$classRow || $classRow['criterion_b_lease_term_months'] === null) {
            throw new \RuntimeException(
                "Lease #{$lease['id']} has no classification record (or no lease_term_months). "
                . 'Run the ASPE 3065 wizard before generating the schedule.'
            );
        }
        $termMonths = (int) $classRow['criterion_b_lease_term_months'];
        if ($termMonths <= 0) {
            throw new \RuntimeException("Lease #{$lease['id']} has invalid lease_term_months.");
        }

        $fairValue   = (string) ($lease['initial_fair_value'] ?? '0');
        $idc         = (string) ($lease['initial_direct_costs'] ?? '0');
        $guarResid   = (string) ($lease['guaranteed_residual_value'] ?? '0');
        $unguarResid = (string) ($lease['unguaranteed_residual_value'] ?? '0');
        $payment     = (string) ($lease['monthly_rate'] ?? '0');
        $bpoAmount   = $lease['bargain_purchase_option_amount'] ?? null;

        // Initial net investment.
        //   sales_type:        NI₀ = FV  (selling profit recognized at inception;
        //                                  IDC expensed via separate JE not on the receivable)
        //   direct_financing:  NI₀ = FV − IDC  (IDC deferred as yield adjustment)
        $initialNi = $classification === 'direct_financing'
            ? bcsub($fairValue, $idc, self::STORAGE_SCALE)
            : bcadd($fairValue, '0', self::STORAGE_SCALE);   // normalize to 2dp

        $rows = [];
        $startTs = strtotime((string) $lease['start_date']);
        if ($startTs === false) {
            throw new \RuntimeException("Lease #{$lease['id']} has invalid start_date.");
        }

        $openingNi = $initialNi;

        for ($t = 1; $t <= $termMonths; $t++) {
            $periodDate = date('Y-m-d', strtotime("+{$t} months", $startTs));

            // finance_income = openingNi × monthlyRate, stored at 2dp.
            // bcmul truncates (does NOT round) at the given scale — matches
            // "floor" behavior the prompt specifies.
            $financeIncome = bcmul($openingNi, $monthlyRate, self::STORAGE_SCALE);

            $cashReceipt = bcadd($payment, '0', self::STORAGE_SCALE);

            // principal_reduction = cashReceipt − financeIncome.
            $principal = bcsub($cashReceipt, $financeIncome, self::STORAGE_SCALE);

            // Cap principal so closing_ni never goes negative mid-stream
            // (defensive — should not happen for a converged rate).
            if (bccomp($principal, $openingNi, self::STORAGE_SCALE) > 0) {
                $principal = $openingNi;
            }

            $closingNi = bcsub($openingNi, $principal, self::STORAGE_SCALE);

            $rows[] = [
                'period_number'          => $t,
                'period_date'            => $periodDate,
                'opening_net_investment' => $openingNi,
                'cash_receipt'           => $cashReceipt,
                'finance_income'         => $financeIncome,
                'principal_reduction'    => $principal,
                'closing_net_investment' => $closingNi,
            ];

            $openingNi = $closingNi;
        }

        // Rounding-tail correction on the final regular period: closing_ni
        // should equal the guaranteed_residual_value (the balance that
        // either becomes the BPO settlement target or rolls into LESSOR-2's
        // residual-review workflow). Apply the prompt's direct formula
        // (spec §24.3 Final-period adjustment):
        //   closing_corrected  = guaranteed_residual_value
        //   principal_corrected = opening_ni − guaranteed_residual_value
        // This preserves the telescoping invariant Σ principal[1..n] =
        // opening[1] − closing[n] exactly. The natural schedule (which
        // amortizes at the implicit rate solved against MLP + unguaranteed
        // residual) lands slightly above target by the unguaranteed-
        // residual portion; absorbing it into principal[n] here keeps
        // the receivable amortizing to guaranteed residual per §24.7's
        // annual residual review workflow.
        $lastIdx       = count($rows) - 1;
        $openingLast   = $rows[$lastIdx]['opening_net_investment'];
        $targetClosing = bcadd($guarResid, '0', self::STORAGE_SCALE);
        $principalLast = bcsub($openingLast, $targetClosing, self::STORAGE_SCALE);

        // STOP CONDITION: a negative corrected principal means the
        // natural closing was below guaranteed residual — payments don't
        // recover even the guaranteed residual amount.
        if (bccomp($principalLast, '0', self::STORAGE_SCALE) < 0) {
            throw new \RuntimeException(
                "Schedule computation error — negative principal on period {$termMonths}. "
                . 'Payments likely do not recover the guaranteed residual.'
            );
        }

        $rows[$lastIdx]['principal_reduction']    = $principalLast;
        $rows[$lastIdx]['closing_net_investment'] = $targetClosing;

        // BPO settlement period — one extra row past termMonths if a BPO
        // amount was captured. The opening_ni equals the guaranteed
        // residual the regular schedule lands on; the cash_receipt is the
        // BPO; finance_income is zero (no time has passed beyond period n
        // for purposes of this lump-sum exercise).
        if ($bpoAmount !== null && bccomp((string) $bpoAmount, '0', 2) > 0) {
            $bpoPeriodDate = date('Y-m-d', strtotime('+' . ($termMonths + 1) . ' months', $startTs));
            $rows[] = [
                'period_number'          => $termMonths + 1,
                'period_date'            => $bpoPeriodDate,
                'opening_net_investment' => $targetClosing,
                'cash_receipt'           => bcadd((string) $bpoAmount, '0', self::STORAGE_SCALE),
                'finance_income'         => '0.00',
                'principal_reduction'    => $targetClosing,
                'closing_net_investment' => '0.00',
            ];
        }

        return $rows;
    }

    // ============================================================
    // B3. generate — persist
    // ============================================================

    /**
     * Generate + persist the amortization schedule for a lease.
     *
     * Idempotency:
     *   - If schedule already exists AND $regenerate=false → return existing.
     *   - If schedule exists AND $regenerate=true:
     *       Any status='posted' rows: throw 422 (do NOT orphan posted JEs).
     *       Otherwise: DELETE existing rows and rebuild.
     *
     * @throws \RuntimeException 422-class errors (operating lease,
     *                            posted rows blocking regen, etc.).
     */
    public static function generate(int $leaseId, int $userId, bool $regenerate = false): array
    {
        return \db_transaction(function () use ($leaseId, $userId, $regenerate): array {
            // Advisory lock — prevents two concurrent generate calls for
            // the same lease from racing into duplicate-key violations.
            \db_execute("SELECT GET_LOCK(?, 10) AS got", ["ff_lease_amort_{$leaseId}"]);

            try {
                $lease = \db_row(
                    "SELECT id, classification, start_date, monthly_rate,
                            initial_fair_value, initial_direct_costs,
                            guaranteed_residual_value, unguaranteed_residual_value,
                            implicit_rate, bargain_purchase_option_amount,
                            deleted_at
                       FROM leases WHERE id = ? FOR UPDATE",
                    [$leaseId]
                );
                if (!$lease || $lease['deleted_at'] !== null) {
                    throw new \InvalidArgumentException("Lease #{$leaseId} not found.");
                }

                if ($lease['classification'] === 'operating') {
                    throw new \RuntimeException(
                        'Operating leases do not use amortization schedules. '
                        . 'Re-classify via the ASPE 3065 wizard first.'
                    );
                }

                // Existing-rows guard.
                $existing = \db_select(
                    "SELECT id, period_number, status
                       FROM acc_lease_amortization_schedules
                      WHERE lease_id = ?
                      ORDER BY period_number ASC",
                    [$leaseId]
                );
                if (!empty($existing)) {
                    if (!$regenerate) {
                        return self::buildResultPayload($leaseId);
                    }
                    $posted = array_filter($existing, static fn(array $r): bool => $r['status'] === 'posted');
                    if (!empty($posted)) {
                        throw new \RuntimeException(
                            'Cannot regenerate schedule — ' . count($posted)
                            . ' period(s) already posted. Reverse posted JEs first.'
                        );
                    }
                    \db_execute(
                        "DELETE FROM acc_lease_amortization_schedules WHERE lease_id = ?",
                        [$leaseId]
                    );
                }

                // Solve rate, persist annualized form to leases.implicit_rate,
                // build schedule.
                $monthlyRate = self::solveImplicitRate(
                    (string) $lease['monthly_rate'],
                    (int) self::loadTermMonths($leaseId),
                    (string) $lease['guaranteed_residual_value'],
                    (string) $lease['unguaranteed_residual_value'],
                    (string) $lease['initial_fair_value'],
                    (string) $lease['initial_direct_costs'],
                    (string) $lease['classification']
                );

                $annualRate = bcmul($monthlyRate, '12', self::RATE_SCALE_ANN);
                \db_execute(
                    "UPDATE leases SET implicit_rate = ? WHERE id = ?",
                    [$annualRate, $leaseId]
                );
                // Refresh lease row so buildSchedule sees the updated rate
                // (not strictly needed since buildSchedule takes monthlyRate
                // as a parameter, but keeps the in-memory object honest).
                $lease['implicit_rate'] = $annualRate;

                $rows = self::buildSchedule($lease, $monthlyRate);

                // Bulk INSERT — one query, not N. Build placeholders +
                // flat params array.
                $placeholders = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?, ?)'));
                $params = [];
                foreach ($rows as $r) {
                    $params[] = $leaseId;
                    $params[] = $r['period_number'];
                    $params[] = $r['period_date'];
                    $params[] = $r['opening_net_investment'];
                    $params[] = $r['cash_receipt'];
                    $params[] = $r['finance_income'];
                    $params[] = $r['principal_reduction'];
                    $params[] = $r['closing_net_investment'];
                }
                \db_execute(
                    "INSERT INTO acc_lease_amortization_schedules
                        (lease_id, period_number, period_date,
                         opening_net_investment, cash_receipt, finance_income,
                         principal_reduction, closing_net_investment)
                     VALUES {$placeholders}",
                    $params
                );

                // Audit.
                $termMonths = self::loadTermMonths($leaseId);
                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'action'      => 'create',
                    'module'      => 'accounting',
                    'entity_type' => 'lease',
                    'entity_id'   => $leaseId,
                    'notes'       => sprintf(
                        'Amortization schedule generated: %d periods, implicit rate=%s p.a., initial NI=%s, regenerate=%s.',
                        $termMonths,
                        $annualRate,
                        $rows[0]['opening_net_investment'],
                        $regenerate ? 'true' : 'false'
                    ),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                return self::buildResultPayload($leaseId);
            } finally {
                \db_execute("SELECT RELEASE_LOCK(?)", ["ff_lease_amort_{$leaseId}"]);
            }
        });
    }

    // ============================================================
    // B4. preview — no writes
    // ============================================================

    /**
     * Build the schedule in memory and return it without persisting
     * (used by the admin UI's "Preview" affordance + by smoke tests).
     */
    public static function preview(int $leaseId): array
    {
        $lease = \db_row(
            "SELECT id, classification, start_date, monthly_rate,
                    initial_fair_value, initial_direct_costs,
                    guaranteed_residual_value, unguaranteed_residual_value,
                    implicit_rate, bargain_purchase_option_amount, deleted_at
               FROM leases WHERE id = ?",
            [$leaseId]
        );
        if (!$lease || $lease['deleted_at'] !== null) {
            throw new \InvalidArgumentException("Lease #{$leaseId} not found.");
        }
        if ($lease['classification'] === 'operating') {
            throw new \RuntimeException(
                'Operating leases do not use amortization schedules.'
            );
        }

        $monthlyRate = self::solveImplicitRate(
            (string) $lease['monthly_rate'],
            (int) self::loadTermMonths($leaseId),
            (string) $lease['guaranteed_residual_value'],
            (string) $lease['unguaranteed_residual_value'],
            (string) $lease['initial_fair_value'],
            (string) $lease['initial_direct_costs'],
            (string) $lease['classification']
        );

        $rows = self::buildSchedule($lease, $monthlyRate);

        $annualRate = bcmul($monthlyRate, '12', self::RATE_SCALE_ANN);
        return [
            'lease_id'         => $leaseId,
            'classification'   => $lease['classification'],
            'monthly_rate'     => $monthlyRate,
            'annual_rate'      => $annualRate,
            'term_months'      => self::loadTermMonths($leaseId),
            'periods'          => $rows,
            'summary'          => self::summarize($rows),
            'persisted'        => false,
        ];
    }

    // ============================================================
    // B5. getSchedule — read existing
    // ============================================================

    public static function getSchedule(int $leaseId): array
    {
        $rows = \db_select(
            "SELECT id, lease_id, period_number, period_date,
                    opening_net_investment, cash_receipt, finance_income,
                    principal_reduction, closing_net_investment,
                    posted_je_id, status, created_at
               FROM acc_lease_amortization_schedules
              WHERE lease_id = ?
              ORDER BY period_number ASC",
            [$leaseId]
        );

        $lease = \db_row(
            "SELECT id, contract_number, classification, implicit_rate,
                    initial_fair_value
               FROM leases WHERE id = ?",
            [$leaseId]
        );

        return [
            'lease_id'   => $leaseId,
            'lease'      => $lease,
            'periods'    => $rows,
            'summary'    => self::summarize($rows),
            'persisted'  => !empty($rows),
        ];
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Load the operator-supplied term from the wizard archive. Throws
     * when no row exists so the caller can surface a 422 directing the
     * operator to run the classification wizard first.
     */
    private static function loadTermMonths(int $leaseId): int
    {
        $row = \db_row(
            "SELECT criterion_b_lease_term_months
               FROM acc_lease_classifications
              WHERE lease_id = ?",
            [$leaseId]
        );
        if (!$row || $row['criterion_b_lease_term_months'] === null) {
            throw new \RuntimeException(
                "Lease #{$leaseId} has no classification record. "
                . 'Run the ASPE 3065 wizard before generating the schedule.'
            );
        }
        return (int) $row['criterion_b_lease_term_months'];
    }

    /**
     * Compute totals across a schedule (used by getSchedule and preview).
     * Operates on whatever array shape is provided — the persisted rows
     * carry status + posted_je_id, the preview rows don't.
     */
    private static function summarize(array $rows): array
    {
        $totalFin = '0';
        $totalPrin = '0';
        $postedCount = 0;

        foreach ($rows as $r) {
            $totalFin  = bcadd($totalFin,  (string) $r['finance_income'],      self::STORAGE_SCALE);
            $totalPrin = bcadd($totalPrin, (string) $r['principal_reduction'], self::STORAGE_SCALE);
            if (($r['status'] ?? 'scheduled') === 'posted') $postedCount++;
        }

        return [
            'period_count'         => count($rows),
            'posted_count'         => $postedCount,
            'total_finance_income' => $totalFin,
            'total_principal'      => $totalPrin,
            'initial_ni'           => $rows[0]['opening_net_investment']             ?? '0.00',
            'final_closing_ni'     => $rows[count($rows) - 1]['closing_net_investment'] ?? '0.00',
        ];
    }

    /**
     * Build the canonical { rows + summary } payload after persistence
     * (re-reads from DB so the response reflects the row IDs MySQL
     * assigned, not the in-memory rows).
     */
    private static function buildResultPayload(int $leaseId): array
    {
        $rows = \db_select(
            "SELECT id, lease_id, period_number, period_date,
                    opening_net_investment, cash_receipt, finance_income,
                    principal_reduction, closing_net_investment,
                    posted_je_id, status, created_at
               FROM acc_lease_amortization_schedules
              WHERE lease_id = ?
              ORDER BY period_number ASC",
            [$leaseId]
        );
        $lease = \db_row(
            "SELECT classification, implicit_rate FROM leases WHERE id = ?",
            [$leaseId]
        );
        return [
            'lease_id'       => $leaseId,
            'classification' => $lease['classification'] ?? null,
            'annual_rate'    => $lease['implicit_rate']  ?? null,
            'periods'        => $rows,
            'summary'        => self::summarize($rows),
            'persisted'      => true,
        ];
    }

    // ============================================================
    // D1. regeneratePartial — preserve posted rows, rebuild scheduled
    // ============================================================

    /**
     * Regenerate the unposted portion of an amortization schedule, used
     * by LeaseResidualService after a downward residual revision (and
     * potentially by future flows that need partial regen without
     * touching posted history).
     *
     * Contract (D-LESSOR-5-PARTIAL-REGEN):
     *   - Posted schedule rows are NEVER touched.
     *   - Only status='scheduled' rows are deleted and rebuilt.
     *   - implicit_rate is NOT re-solved — ASPE 3065 locks the rate
     *     at inception. Residual revisions are a write-down event,
     *     not a re-pricing event.
     *   - Opening NI for the regen is the closing_net_investment of
     *     the last POSTED row (or initial_fair_value if none posted).
     *     If a residual impairment JE was posted after the last
     *     period JE, its credit-to-NI-long-term amount is SUBTRACTED
     *     from that opening — keeps the schedule projection aligned
     *     with the on-books NI that the period JEs will reduce.
     *
     * Per D-LESSOR-4-PERIOD-PRINCIPAL-DERIVATION: the regenerated
     * rows' principal_reduction column remains a UI projection (last
     * regular period gets the rounding-tail correction, just like
     * the initial buildSchedule). Period JE construction continues
     * to derive principal = cash − finance at JE time.
     *
     * @return array { regenerated_periods, new_opening_ni,
     *                 start_period, last_posted_period }
     * @throws \RuntimeException when classification is not capital,
     *                            no schedule exists, or term ≤ posted
     */
    public static function regeneratePartial(int $leaseId, int $userId): array
    {
        return \db_transaction(function () use ($leaseId, $userId): array {
            \db_execute("SELECT GET_LOCK(?, 10) AS got", ["ff_lease_amort_{$leaseId}"]);

            try {
                $lease = \db_row(
                    "SELECT id, contract_number, classification, start_date,
                            monthly_rate, initial_fair_value, initial_direct_costs,
                            guaranteed_residual_value, unguaranteed_residual_value,
                            implicit_rate, bargain_purchase_option_amount,
                            bargain_purchase_option_date, deleted_at
                       FROM leases WHERE id = ? FOR UPDATE",
                    [$leaseId]
                );
                if (!$lease || $lease['deleted_at'] !== null) {
                    throw new \InvalidArgumentException("Lease #{$leaseId} not found.");
                }
                if (!in_array($lease['classification'], ['sales_type', 'direct_financing'], true)) {
                    throw new \RuntimeException(
                        'regeneratePartial: lease classification must be capital ('
                        . "sales_type / direct_financing); got '{$lease['classification']}'."
                    );
                }
                if (!$lease['implicit_rate']) {
                    throw new \RuntimeException(
                        "Lease #{$leaseId} has no stored implicit_rate. "
                        . 'Run full generate() before partial regen.'
                    );
                }

                $termMonths = self::loadTermMonths($leaseId);

                // ── Identify last posted period ─────────────────────
                $postedSummary = \db_row(
                    "SELECT MAX(period_number) AS last_period,
                            COUNT(*) AS posted_count
                       FROM acc_lease_amortization_schedules
                      WHERE lease_id = ? AND status = 'posted'",
                    [$leaseId]
                );
                $lastPostedPeriod = (int) ($postedSummary['last_period'] ?? 0);
                $postedCount      = (int) ($postedSummary['posted_count'] ?? 0);
                $startFromPeriod  = $lastPostedPeriod + 1;

                // ── Compute opening NI for the regen ────────────────
                if ($lastPostedPeriod > 0) {
                    $lastPostedRow = \db_row(
                        "SELECT closing_net_investment, posted_je_id
                           FROM acc_lease_amortization_schedules
                          WHERE lease_id = ? AND period_number = ?",
                        [$leaseId, $lastPostedPeriod]
                    );
                    $openingNi = (string) $lastPostedRow['closing_net_investment'];
                } else {
                    // No posted rows yet — full regen from inception NI.
                    // For DF leases: initial NI = FV (already reflects
                    // FV − IDC per LESSOR-2 solveImplicitRate target).
                    $openingNi = (string) $lease['initial_fair_value'];
                }

                // ── Adjust opening for any residual impairment posted
                //    AFTER the last period JE but BEFORE this regen.
                //    The impairment JE credits NI Long-Term; the
                //    schedule's projected opening must drop by the
                //    same amount to stay aligned with on-books NI.
                $niLongTermAcct = (int) AccountingService::setting('accounting.lessor_ni_longterm_account_id', 0);
                if ($niLongTermAcct > 0 && $lastPostedPeriod > 0) {
                    $impRow = \db_row(
                        "SELECT COALESCE(SUM(jel.credit), '0.00') AS imp
                           FROM acc_journal_entry_lines jel
                           JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                          WHERE je.source_id   = ?
                            AND je.source_type = 'lease_residual_impairment'
                            AND je.status      = 'posted'
                            AND jel.account_id = ?
                            AND je.entry_date >= (
                                SELECT COALESCE(MAX(je2.entry_date), '1970-01-01')
                                  FROM acc_journal_entries je2
                                 WHERE je2.source_id   = ?
                                   AND je2.source_type = 'lease_period'
                                   AND je2.status      = 'posted'
                            )",
                        [$leaseId, $niLongTermAcct, $leaseId]
                    );
                    $recentImpairment = (string) ($impRow['imp'] ?? '0.00');
                    if (bccomp($recentImpairment, '0', 2) > 0) {
                        $openingNi = bcsub($openingNi, $recentImpairment, 2);
                    }
                } elseif ($lastPostedPeriod === 0 && $niLongTermAcct > 0) {
                    // Pre-activation impairment is unusual but possible
                    // (operator revises residual before any period posts).
                    $impRow = \db_row(
                        "SELECT COALESCE(SUM(jel.credit), '0.00') AS imp
                           FROM acc_journal_entry_lines jel
                           JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                          WHERE je.source_id   = ?
                            AND je.source_type = 'lease_residual_impairment'
                            AND je.status      = 'posted'
                            AND jel.account_id = ?",
                        [$leaseId, $niLongTermAcct]
                    );
                    $recentImpairment = (string) ($impRow['imp'] ?? '0.00');
                    if (bccomp($recentImpairment, '0', 2) > 0) {
                        $openingNi = bcsub($openingNi, $recentImpairment, 2);
                    }
                }

                // ── Early exit: fully posted, nothing to regenerate ─
                $bpoPresent = $lease['bargain_purchase_option_amount'] !== null
                    && bccomp((string) $lease['bargain_purchase_option_amount'], '0', 2) > 0;
                $maxPeriod = $bpoPresent ? $termMonths + 1 : $termMonths;
                if ($startFromPeriod > $maxPeriod) {
                    error_log(sprintf(
                        '[LeaseAmortizationService::regeneratePartial] lease #%d fully posted (last_period=%d, max=%d) — no regen needed.',
                        $leaseId, $lastPostedPeriod, $maxPeriod
                    ));
                    return [
                        'regenerated_periods' => 0,
                        'new_opening_ni'      => $openingNi,
                        'start_period'        => $startFromPeriod,
                        'last_posted_period'  => $lastPostedPeriod,
                    ];
                }

                // ── Delete existing scheduled rows ─────────────────
                $deletedRows = \db_execute(
                    "DELETE FROM acc_lease_amortization_schedules
                      WHERE lease_id = ? AND status = 'scheduled'",
                    [$leaseId]
                );

                // ── Build regenerated rows ─────────────────────────
                // Walk from startFromPeriod..termMonths using the locked
                // implicit rate. Same math as buildSchedule but
                // starting mid-schedule + with the (possibly lower)
                // opening NI from above.
                $monthlyRate = bcdiv((string) $lease['implicit_rate'], '12', 10);
                $payment     = (string) $lease['monthly_rate'];
                $guarResid   = (string) $lease['guaranteed_residual_value'];
                $bpoAmount   = $lease['bargain_purchase_option_amount'];

                $startTs = strtotime((string) $lease['start_date']);
                if ($startTs === false) {
                    throw new \RuntimeException("Lease #{$leaseId} has invalid start_date.");
                }

                $rows = [];
                $opening = $openingNi;
                for ($t = $startFromPeriod; $t <= $termMonths; $t++) {
                    $periodDate = date('Y-m-d', strtotime("+{$t} months", $startTs));
                    // bcmul truncates at scale 2 — matches buildSchedule.
                    $finance = bcmul($opening, $monthlyRate, 2);
                    $cash    = bcadd($payment, '0', 2);
                    $principal = bcsub($cash, $finance, 2);
                    if (bccomp($principal, $opening, 2) > 0) {
                        $principal = $opening;
                    }
                    $closing = bcsub($opening, $principal, 2);

                    $rows[] = [
                        'period_number'          => $t,
                        'period_date'            => $periodDate,
                        'opening_net_investment' => $opening,
                        'cash_receipt'           => $cash,
                        'finance_income'         => $finance,
                        'principal_reduction'    => $principal,
                        'closing_net_investment' => $closing,
                    ];
                    $opening = $closing;
                }

                // Rounding-tail correction on the new last regular period.
                // Same UI-projection logic as buildSchedule (per
                // D-LESSOR-4-PERIOD-PRINCIPAL-DERIVATION, the GL JE later
                // derives principal naturally; this is display only).
                if (!empty($rows)) {
                    $lastIdx       = count($rows) - 1;
                    $openingLast   = $rows[$lastIdx]['opening_net_investment'];
                    $targetClosing = bcadd($guarResid, '0', 2);
                    $principalLast = bcsub($openingLast, $targetClosing, 2);

                    if (bccomp($principalLast, '0', 2) < 0) {
                        // Opening already below guaranteed residual after
                        // impairment write-down — cannot land at original
                        // target. Set closing to opening (zero principal
                        // for this period) and leave residual on books.
                        // Operator should follow up with another residual
                        // review or use onLeaseTermination's write-off.
                        error_log(sprintf(
                            '[regeneratePartial] lease #%d: opening NI (%s) below guaranteed residual (%s) after impairment; flattening last-period principal.',
                            $leaseId, $openingLast, $targetClosing
                        ));
                        $rows[$lastIdx]['principal_reduction']    = '0.00';
                        $rows[$lastIdx]['closing_net_investment'] = $openingLast;
                    } else {
                        $rows[$lastIdx]['principal_reduction']    = $principalLast;
                        $rows[$lastIdx]['closing_net_investment'] = $targetClosing;
                    }
                }

                // BPO row when BPO > 0 and we're regenerating that period too.
                if ($bpoPresent && $startFromPeriod <= $termMonths + 1) {
                    $bpoPeriod = $termMonths + 1;
                    if ($bpoPeriod >= $startFromPeriod) {
                        $bpoDate = date('Y-m-d', strtotime('+' . $bpoPeriod . ' months', $startTs));
                        $rows[] = [
                            'period_number'          => $bpoPeriod,
                            'period_date'            => $bpoDate,
                            'opening_net_investment' => $rows ? $rows[count($rows) - 1]['closing_net_investment'] : $openingNi,
                            'cash_receipt'           => bcadd((string) $bpoAmount, '0', 2),
                            'finance_income'         => '0.00',
                            'principal_reduction'    => $rows ? $rows[count($rows) - 1]['closing_net_investment'] : $openingNi,
                            'closing_net_investment' => '0.00',
                        ];
                    }
                }

                // ── Bulk INSERT new rows ────────────────────────────
                if (!empty($rows)) {
                    $placeholders = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?, ?)'));
                    $params = [];
                    foreach ($rows as $r) {
                        $params[] = $leaseId;
                        $params[] = $r['period_number'];
                        $params[] = $r['period_date'];
                        $params[] = $r['opening_net_investment'];
                        $params[] = $r['cash_receipt'];
                        $params[] = $r['finance_income'];
                        $params[] = $r['principal_reduction'];
                        $params[] = $r['closing_net_investment'];
                    }
                    \db_execute(
                        "INSERT INTO acc_lease_amortization_schedules
                            (lease_id, period_number, period_date,
                             opening_net_investment, cash_receipt, finance_income,
                             principal_reduction, closing_net_investment)
                         VALUES {$placeholders}",
                        $params
                    );
                }

                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'action'      => 'update',
                    'module'      => 'accounting',
                    'entity_type' => 'lease',
                    'entity_id'   => $leaseId,
                    'entity_label' => $lease['contract_number'],
                    'notes'       => sprintf(
                        'Partial schedule regen: deleted %d scheduled rows, built %d new rows from period %d. Last posted=%d. Opening NI=%s.',
                        $deletedRows, count($rows), $startFromPeriod, $lastPostedPeriod, $openingNi
                    ),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                return [
                    'regenerated_periods' => count($rows),
                    'new_opening_ni'      => $openingNi,
                    'start_period'        => $startFromPeriod,
                    'last_posted_period'  => $lastPostedPeriod,
                    'deleted_scheduled'   => $deletedRows,
                ];
            } finally {
                \db_execute("SELECT RELEASE_LOCK(?)", ["ff_lease_amort_{$leaseId}"]);
            }
        });
    }
}
