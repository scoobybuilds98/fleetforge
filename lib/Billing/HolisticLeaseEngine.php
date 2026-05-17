<?php
declare(strict_types=1);

/**
 * lib/Billing/HolisticLeaseEngine.php
 *
 * Running-reconciliation billing engine. Replaces ProRateCalculator
 * for leases marked engine_version='holistic' on the leases table.
 *
 * The core idea (spec §3): every invoice asks the same five questions —
 *   1. How many days has this lease been active in total (start_date
 *      through this invoice's period_end, inclusive)?
 *   2. Which tier does that total fall into? (same THE LAW thresholds
 *      as the old engine: 1-5 daily, 6-7 weekly_flat, 8-29 weekly_math,
 *      30+ monthly_math)
 *   3. What SHOULD the cumulative base_rental be at that tier?
 *   4. What has already been billed across all non-void prior invoices
 *      for this lease?
 *   5. delta = cumulative_correct - already_billed. That's THIS invoice's
 *      base_rental. Positive → emit base_rental line. Negative → emit
 *      base_rental_reconciliation_credit. Zero → no line.
 *
 * Activation special case (spec §17): when this is the FIRST invoice
 * for the lease (already_billed = '0.00'), apply "whichever pays more"
 * — bill the HIGHER of (current-period tier formula) vs (monthly-prorated
 * for these days). Protects the company against quick returns on
 * long-term-priced leases.
 *
 * Pure math + ONE read query (already_billed SUM); no DB writes (D3:
 * InvoiceGenerator is the sole DB writer in billing).
 *
 * Required by: lib/Billing/InvoiceGenerator.php
 * Requires:    includes/db.php (db_row), lib/Billing/BillingRateException.php
 * Defines:     FleetForge\Billing\HolisticLeaseEngine
 *
 * Decisions:   D14 (inclusive day counting), D16 (bcmath only),
 *              D20 (read uses FOR UPDATE caller-side), D132 (zero-rate
 *              backstop), K-16 (positive amount + is_credit=1 for credits)
 * Spec ref:    FleetForge_Holistic_Billing_Engine_Spec.docx §3, §5–§7,
 *              §11 (boss's worked example), §17 (whichever-pays-more),
 *              §19 (negative invoices), §30 (class shape)
 */

namespace FleetForge\Billing;

class HolisticLeaseEngine
{
    /**
     * Compute the base_rental amount for an invoice using running
     * reconciliation. Returns a structured array the caller (InvoiceGenerator)
     * uses to emit a single base_rental or base_rental_reconciliation_credit
     * line item plus the three audit-column values for the invoices row.
     *
     * Spec §7 — The Five-Step Calculation. Implemented exactly.
     *
     * @param array $params {
     *   lease_id:               int        Lease being billed
     *   start_date:             string     Lease start_date (Y-m-d)
     *   period_end:             string     This invoice's billing_period_end (Y-m-d)
     *   period_start:           string     This invoice's billing_period_start (Y-m-d)
     *   daily_rate:             string     bcmath
     *   weekly_rate:            string     bcmath
     *   monthly_rate:           string     bcmath
     *   is_activation_invoice:  bool       True iff already_billed === '0.00' for this lease.
     *                                       Caller (InvoiceGenerator) computes this from the
     *                                       SUM query the engine also runs, OR may pass true
     *                                       speculatively — the engine recomputes already_billed
     *                                       itself and the activation branch only fires when the
     *                                       SUM is 0. Spec §35.3.
     * }
     * @return array{
     *   delta: string,                  Signed amount, bcmath; sign drives line type
     *   line_item_type: string,         'base_rental' | 'base_rental_reconciliation_credit' | 'none'
     *   is_credit: int,                 0 | 1 — K-16 convention for the line item
     *   amount: string,                 Positive amount for the line (bcmath)
     *   tier: string,                   'none'|'daily'|'weekly_flat'|'weekly_math'|'weekly_capped'|'monthly_math'
     *   total_days_so_far: int,         Inclusive day count, start_date through period_end
     *   cumulative_correct: string,     Bcmath — what the lease total SHOULD be
     *   already_billed: string,         Bcmath — sum of prior non-void base_rental
     *   explanation: array<string>,     Human-readable trail (for invoice line item detail)
     *   audit_meta: array,              Full JSON-ready audit payload for audit_log new_values
     * }
     * @throws BillingRateException When a non-zero tier-formula result requires a zero rate
     *                              (mirrors ProRateCalculator::assertNonZero — D132 backstop).
     */
    public function calculateForInvoice(array $params): array
    {
        $leaseId      = (int)$params['lease_id'];
        $startDate    = (string)$params['start_date'];
        $periodEnd    = (string)$params['period_end'];
        $periodStart  = (string)$params['period_start'];
        $daily        = (string)$params['daily_rate'];
        $weekly       = (string)$params['weekly_rate'];
        $monthly      = (string)$params['monthly_rate'];
        $callerSaysActivation = (bool)($params['is_activation_invoice'] ?? false);

        // ── Step 1: total days so far (spec §7.1) ─────────────────
        // Inclusive day counting per D14. Lease starting Mar 28 and
        // generating an invoice with period_end Apr 30 = 34 days.
        $totalDays  = self::inclusiveDays($startDate, $periodEnd);

        // Period days drive the "whichever pays more" activation
        // computation; for non-activation invoices this is informational.
        $periodDays = self::inclusiveDays($periodStart, $periodEnd);

        // ── Step 3 (run early): already_billed query (spec §7.3) ──
        // Pulled before Step 2's tier math so the activation branch
        // (spec §17) can confirm "is this really invoice 1?" from
        // ground truth rather than trusting the caller's guess.
        //
        // Excludes void invoices and soft-deleted invoices. Includes
        // BOTH 'base_rental' and 'base_rental_reconciliation_credit'
        // lines — the reconciliation credit subtracts because it
        // carries is_credit=1 in the aggregator, so for the holistic
        // engine's purposes we treat already_billed as the NET base
        // rental contributed by prior invoices.
        $alreadyBilled = $this->sumAlreadyBilled($leaseId);

        // Spec §35.3: activation invoice = first invoice for the lease
        // where already_billed = '0.00'. The caller may pass
        // is_activation_invoice=true based on billing_type, but ground
        // truth is the SUM. Only run the "whichever pays more" branch
        // when both agree.
        $isActivation = $callerSaysActivation
            && bccomp($alreadyBilled, '0', 2) === 0;

        // ── Step 2: tier formula on cumulative days (spec §7.2) ──
        $tierResult = $this->applyTierFormula($totalDays, $daily, $weekly, $monthly);

        $cumulativeCorrect = $tierResult['amount'];
        $tier              = $tierResult['tier'];
        $explanation       = $tierResult['explanation'];

        // Spec §17 — Whichever Pays More (activation only).
        // Compute both options for the current period only, pick the
        // higher. Replaces $cumulativeCorrect for this single invoice
        // because already_billed = 0.00 and the lease's eventual
        // duration is unknown.
        $activationMeta = null;
        if ($isActivation) {
            $wpm = $this->whicheverPaysMore($periodDays, $daily, $weekly, $monthly);
            $activationMeta = $wpm;
            $cumulativeCorrect = $wpm['chosen_amount'];
            // The tier on the invoice reflects what we CHARGED, not
            // the tier-by-day-count (which for a 4-day activation
            // would be 'daily' — same answer here because daily-rate
            // wins for 4 days at $50/$700 anyway). For activation we
            // use the wpm-chosen tier so the line item description
            // tells the truth about how the amount was derived.
            $tier        = $wpm['chosen_tier'];
            $explanation = $wpm['explanation'];
        }

        // ── Step 4: delta (spec §7.4) ─────────────────────────────
        $delta = bcsub($cumulativeCorrect, $alreadyBilled, 2);

        // ── Step 5: emit line item (spec §7.5) ────────────────────
        $deltaSign = bccomp($delta, '0', 2);

        if ($deltaSign > 0) {
            // Positive — emit base_rental line
            $lineItemType = 'base_rental';
            $isCredit     = 0;
            $amount       = $delta;
        } elseif ($deltaSign === 0) {
            // Zero — emit nothing (spec §7.5). Rare: happens when total
            // days perfectly hit a clean cumulative AND prior invoices
            // summed to exactly that total.
            $lineItemType = 'none';
            $isCredit     = 0;
            $amount       = '0.00';
        } else {
            // Negative — emit base_rental_reconciliation_credit line.
            // K-16 convention: POSITIVE amount + is_credit=1 (aggregator
            // subtracts, per-line tax negates via bcmul sign-propagation
            // in TaxCalculator). Matches mileage_drawdown_credit shape.
            $lineItemType = 'base_rental_reconciliation_credit';
            $isCredit     = 1;
            $amount       = bcmul($delta, '-1', 2);  // abs(delta)
        }

        // ── D132 backstop (mirror ProRateCalculator::assertNonZero) ──
        // If the period has billable days AND the engine ended up
        // emitting a NON-NEGATIVE base_rental line of $0, that means
        // the cumulative_correct didn't progress despite a non-trivial
        // period — almost certainly a zero rate slipping past lease
        // create validation (D132). The InvoiceGenerator backstop
        // (line ~178) is the same check; this throw lets the engine
        // fail loud at its boundary so the caller logs include the
        // engine's own diagnostics.
        //
        // Skip when the line type is the reconciliation credit (a
        // legitimate $0 happens when previous invoices precisely
        // matched the new cumulative) and when periodDays <= 0
        // (mileage-only or zero-period adjustments).
        if ($periodDays > 0
            && $lineItemType === 'base_rental'
            && bccomp($amount, '0', 2) === 0
            && bccomp($cumulativeCorrect, '0', 2) === 0
        ) {
            throw new BillingRateException(
                sprintf(
                    'HolisticLeaseEngine refused to compute zero base_rental: tier=%s, total_days=%d, period_days=%d, daily=%s, weekly=%s, monthly=%s. '
                    . 'Upstream rate-tier-completeness invariant (D132) must be enforced at lease create — see api/v1/leases/create.php.',
                    $tier, $totalDays, $periodDays, $daily, $weekly, $monthly
                ),
                $tier, $periodDays, $daily, $weekly, $monthly,
                [
                    'lease_id'           => $leaseId,
                    'period_start'       => $periodStart,
                    'period_end'         => $periodEnd,
                    'total_days_so_far'  => $totalDays,
                    'cumulative_correct' => $cumulativeCorrect,
                    'already_billed'     => $alreadyBilled,
                ]
            );
        }

        // ── Audit meta: full payload for audit_log new_values JSON ──
        // Spec §20.3 + Appendix C. The InvoiceGenerator writes ONE
        // audit_log row per holistic-engine invoice using this payload.
        $auditMeta = [
            'engine'             => 'holistic',
            'lease_id'           => $leaseId,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'period_days'        => $periodDays,
            'total_days_so_far'  => $totalDays,
            'tier'               => $tier,
            'cumulative_correct' => $cumulativeCorrect,
            'already_billed'     => $alreadyBilled,
            'delta'              => $delta,
            'line_item_type'     => $lineItemType,
            'amount'             => $amount,
            'is_credit'          => $isCredit,
            'rule'               => $isActivation ? 'whichever_pays_more' : 'running_reconciliation',
            'rates'              => [
                'daily'   => $daily,
                'weekly'  => $weekly,
                'monthly' => $monthly,
            ],
        ];
        if ($activationMeta !== null) {
            $auditMeta['activation_meta'] = [
                'option_a_value' => $activationMeta['option_a_value'],
                'option_b_value' => $activationMeta['option_b_value'],
                'chosen_option'  => $activationMeta['chosen_option'],
                'chosen_tier'    => $activationMeta['chosen_tier'],
            ];
        }

        return [
            'delta'              => $delta,
            'line_item_type'     => $lineItemType,
            'is_credit'          => $isCredit,
            'amount'             => $amount,
            'tier'               => $tier,
            'total_days_so_far'  => $totalDays,
            'cumulative_correct' => $cumulativeCorrect,
            'already_billed'     => $alreadyBilled,
            'period_days'        => $periodDays,
            'explanation'        => $explanation,
            'audit_meta'         => $auditMeta,
        ];
    }

    /**
     * Apply the tier formula to a given day count + rates. Pure math,
     * no DB access, fully reusable. Used by calculateForInvoice() for
     * the cumulative_correct computation AND by whicheverPaysMore()
     * for Option A on the activation invoice.
     *
     * Formulas are IDENTICAL to ProRateCalculator::calculate (spec §5.2)
     * — only the input differs (cumulative days vs period days).
     *
     * @param int    $totalDays  Day count (1+; pass periodDays for
     *                            activation Option A)
     * @param string $daily      Daily rate (bcmath)
     * @param string $weekly     Weekly rate (bcmath)
     * @param string $monthly    Monthly rate (bcmath)
     * @return array{amount: string, tier: string, explanation: array<string>}
     */
    public function applyTierFormula(
        int $totalDays,
        string $daily,
        string $weekly,
        string $monthly
    ): array {
        if ($totalDays <= 0) {
            return [
                'amount'      => '0.00',
                'tier'        => 'none',
                'explanation' => ['0 cumulative days — no charge'],
            ];
        }

        // Tier 1 — daily (1-5 days)
        if ($totalDays <= 5) {
            $amount = bcround(bcmul($daily, (string)$totalDays, 6), 2);
            return [
                'amount'      => $amount,
                'tier'        => 'daily',
                'explanation' => ["{$totalDays} cumulative days × \${$daily}/day = \${$amount}"],
            ];
        }

        // Tier 2 — weekly_flat (6-7 days)
        if ($totalDays <= 7) {
            $amount = bcround($weekly, 2);
            return [
                'amount'      => $amount,
                'tier'        => 'weekly_flat',
                'explanation' => ["{$totalDays} cumulative days — weekly flat rate = \${$amount}"],
            ];
        }

        // Tier 3 — weekly_math (8-29 days), capped at monthly
        if ($totalDays <= 29) {
            $fullWeeks       = intdiv($totalDays, 7);
            $remainder       = $totalDays % 7;
            $dailyFromWeekly = bcdiv($weekly, '7', 6);
            $weeklyPart      = bcmul($weekly, (string)$fullWeeks, 6);
            $remainderPart   = bcmul($dailyFromWeekly, (string)$remainder, 6);
            $weeklyMath      = bcadd($weeklyPart, $remainderPart, 6);

            // Cap check (spec §5.3)
            if (bccomp($weeklyMath, $monthly, 6) > 0) {
                $amount      = bcround($monthly, 2);
                $explanation = [
                    "{$totalDays} cumulative days: weekly math = \$" . bcround($weeklyMath, 2)
                        . " exceeds monthly rate \${$monthly}",
                    "Capped at monthly rate: \${$amount}",
                ];
                return [
                    'amount'      => $amount,
                    'tier'        => 'weekly_capped',
                    'explanation' => $explanation,
                ];
            }

            $amount      = bcround($weeklyMath, 2);
            $explanation = [];
            if ($fullWeeks > 0) {
                $explanation[] = "{$fullWeeks} week(s) × \${$weekly}";
            }
            if ($remainder > 0) {
                $dailyFromWeeklyRounded = bcround($dailyFromWeekly, 4);
                $explanation[] = "{$remainder} remaining days × \${$dailyFromWeeklyRounded}/day (weekly/7)";
            }
            $explanation[] = "Cumulative {$totalDays} days = \${$amount} (weekly_math)";
            return [
                'amount'      => $amount,
                'tier'        => 'weekly_math',
                'explanation' => $explanation,
            ];
        }

        // Tier 4 — monthly_math (30+ days)
        $fullMonths        = intdiv($totalDays, 30);
        $remainderDays     = $totalDays % 30;
        $dailyFromMonthly  = bcdiv($monthly, '30', 6);
        $monthlyPart       = bcmul($monthly, (string)$fullMonths, 6);
        $monthRemainderPart = bcmul($dailyFromMonthly, (string)$remainderDays, 6);
        $monthlyMath       = bcadd($monthlyPart, $monthRemainderPart, 6);
        $amount            = bcround($monthlyMath, 2);

        $explanation = [];
        if ($fullMonths > 0) {
            $explanation[] = "{$fullMonths} full month(s) × \${$monthly}";
        }
        if ($remainderDays > 0) {
            $dailyFromMonthlyRounded = bcround($dailyFromMonthly, 4);
            $explanation[] = "{$remainderDays} remaining days × \${$dailyFromMonthlyRounded}/day (monthly/30)";
        }
        $explanation[] = "Cumulative {$totalDays} days = \${$amount} (monthly_math)";

        return [
            'amount'      => $amount,
            'tier'        => 'monthly_math',
            'explanation' => $explanation,
        ];
    }

    /**
     * "Whichever pays more" rule (spec §17) — applied ONLY at the
     * activation invoice. Compute both options for the current period,
     * pick the HIGHER, return rich metadata for the audit trail.
     *
     *   Option A: apply current tier formula to period_days alone
     *             (period-independent style — like the old engine)
     *   Option B: treat as if it'll be a long-term lease — monthly
     *             pro-rated for these days (period_days × monthly/30)
     *
     * Protects the company against quick returns on long-term-priced
     * leases. Spec §17.3 worked example.
     *
     * Public for unit-testability — pure math, no DB.
     *
     * @return array{
     *   chosen_amount: string,
     *   chosen_tier: string,
     *   chosen_option: 'A'|'B'|'tie',
     *   option_a_value: string,
     *   option_b_value: string,
     *   explanation: array<string>
     * }
     */
    public function whicheverPaysMore(
        int $periodDays,
        string $daily,
        string $weekly,
        string $monthly
    ): array {
        // Option A — current tier formula on period days alone
        $optionA       = $this->applyTierFormula($periodDays, $daily, $weekly, $monthly);
        $optionAValue  = $optionA['amount'];
        $optionATier   = $optionA['tier'];

        // Option B — monthly-prorated for these days
        $dailyFromMonthly = bcdiv($monthly, '30', 6);
        $optionBValue     = bcround(bcmul($dailyFromMonthly, (string)$periodDays, 6), 2);

        $cmp = bccomp($optionAValue, $optionBValue, 2);
        if ($cmp >= 0) {
            // A wins (or tie — pick A by convention; preserves the
            // "current tier formula" trail in the explanation)
            $chosenAmount = $optionAValue;
            $chosenTier   = $optionATier;
            $chosenOption = ($cmp === 0) ? 'tie' : 'A';
            $explanation  = array_merge(
                ['Whichever pays more (activation):'],
                ['  Option A (current tier): $' . $optionAValue . " (tier={$optionATier})"],
                ['  Option B (monthly-prorated): $' . $optionBValue . " ({$periodDays} × \$" . bcround($dailyFromMonthly, 4) . '/day)'],
                ['  Chosen: Option ' . ($cmp === 0 ? 'A (tie)' : 'A') . ' = $' . $chosenAmount],
            );
        } else {
            // B wins — bill the monthly-prorated amount. The tier
            // attribution is 'monthly_math' because that's the
            // economic basis of the charge.
            $chosenAmount = $optionBValue;
            $chosenTier   = 'monthly_math';
            $chosenOption = 'B';
            $explanation  = array_merge(
                ['Whichever pays more (activation):'],
                ['  Option A (current tier): $' . $optionAValue . " (tier={$optionATier})"],
                ['  Option B (monthly-prorated): $' . $optionBValue . " ({$periodDays} × \$" . bcround($dailyFromMonthly, 4) . '/day)'],
                ['  Chosen: Option B = $' . $chosenAmount . ' (monthly-prorated wins)'],
            );
        }

        return [
            'chosen_amount'  => $chosenAmount,
            'chosen_tier'    => $chosenTier,
            'chosen_option'  => $chosenOption,
            'option_a_value' => $optionAValue,
            'option_b_value' => $optionBValue,
            'explanation'    => $explanation,
        ];
    }

    /**
     * Inclusive day counting per D14 (spec §6.1).
     *
     * inclusiveDays('2026-03-28', '2026-03-28') = 1
     * inclusiveDays('2026-03-28', '2026-03-31') = 4
     * inclusiveDays('2026-03-28', '2026-04-30') = 34
     *
     * PHP's DateTime::diff handles leap years and variable month
     * lengths automatically (spec §6.3). Stored dates are DATE (not
     * DATETIME) so DST and time-of-day are irrelevant.
     *
     * Returns 0 if end < start (defensive — caller shouldn't pass
     * inverted ranges, but if it happens we don't want negative day
     * counts cascading through the tier formula).
     */
    public static function inclusiveDays(string $start, string $end): int
    {
        $startDt = new \DateTimeImmutable($start);
        $endDt   = new \DateTimeImmutable($end);
        if ($endDt < $startDt) {
            return 0;
        }
        return (int)$startDt->diff($endDt)->days + 1;
    }

    /**
     * Sum of base_rental from non-void prior invoices for a lease
     * (spec §7.3). Includes both 'base_rental' and
     * 'base_rental_reconciliation_credit' line types — the credit
     * subtracts because the aggregator treats is_credit=1 as negative.
     *
     * The SUM uses signed-amount semantics (is_credit flips the sign)
     * so the "already billed" figure is the NET base-rental contributed
     * by past invoices. Spec §7.3 wording referenced only 'base_rental'
     * which is correct for the typical case — but in scenarios where
     * a prior invoice already emitted a reconciliation credit, the
     * cumulative math needs that credit subtracted from already_billed
     * to avoid double-counting.
     *
     * The ONLY DB read the engine performs (D3 budget).
     */
    private function sumAlreadyBilled(int $leaseId): string
    {
        $row = db_row(
            "SELECT COALESCE(SUM(CASE WHEN li.is_credit = 1 THEN -li.amount ELSE li.amount END), '0.00') AS sum_amount
               FROM invoice_line_items li
               JOIN invoices i ON i.id = li.invoice_id
              WHERE i.lease_id = ?
                AND i.deleted_at IS NULL
                AND i.status <> 'void'
                AND li.item_type IN ('base_rental', 'base_rental_reconciliation_credit')",
            [$leaseId]
        );
        return (string)($row['sum_amount'] ?? '0.00');
    }
}
