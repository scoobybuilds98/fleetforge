<?php
declare(strict_types=1);

/**
 * lib/Billing/HolisticLeaseEngine.php
 *
 * Running-reconciliation billing engine. Replaces ProRateCalculator
 * for leases marked engine_version='holistic' on the leases table.
 *
 * The core idea (Revision 2 §6–§7): every invoice asks the same questions —
 *   1. Known extent E = actual_return (closed) · end_date (set) · else the
 *      billing date. total = inclusiveDays(start_date, E).
 *   2. Classify on total: ≤7 → cheaper-of daily/weekly ladder; weeklyMath ≤
 *      monthly → weekly math; weeklyMath > monthly → MONTHLY applies.
 *   3. cumulative_correct THROUGH this invoice's period_end:
 *        sub-month tier → the whole-lease amount;
 *        monthly, single calendar month → flat monthly (28/29/30/31);
 *        monthly, spans → Σ calendar-month segments (complete months flat,
 *        partial start/end months at days × monthly÷30).
 *   4. already_billed = NET base_rental of all non-void prior invoices.
 *   5. delta = cumulative_correct − already_billed. Positive → base_rental;
 *      negative → base_rental_reconciliation_credit; zero → no line.
 *
 * Revision 2 (2026-06-07) supersedes the original spec's month math and §17
 * "whichever pays more" at activation. A full calendar month bills flat at
 * the monthly rate regardless of length (the 30-day-block math that charged
 * a 31-day month an extra day is removed). There is NO special first-invoice
 * branch: the single-calendar-month-flat rule plus the running
 * reconciliation give the same protection — a 22–30 day single-month lease
 * pays the flat month; a lease known to span prorates its partial
 * immediately; a span discovered later re-prorates the past month down to
 * its /30 value via a reconciliation credit ("prorate in the past").
 *
 * Pure math + ONE read query (already_billed SUM); no DB writes (D3:
 * InvoiceGenerator is the sole DB writer in billing). applyTierFormula() and
 * whicheverPaysMore() remain as pure-math utilities (unit-tested directly)
 * but are NOT on the calculateForInvoice() path post-Revision-2.
 *
 * Required by: lib/Billing/InvoiceGenerator.php
 * Requires:    includes/db.php (db_row), lib/Billing/BillingRateException.php
 * Defines:     FleetForge\Billing\HolisticLeaseEngine
 *
 * Decisions:   D14 (inclusive day counting), D16 (bcmath only),
 *              D20 (read uses FOR UPDATE caller-side), D132 (zero-rate
 *              backstop), K-16 (positive amount + is_credit=1 for credits),
 *              D-R2-1..7 (Revision 2 locked decisions)
 * Spec ref:    FleetForge_Holistic_Billing_Engine_Spec_Revision2_2026-06-07
 *              §3 (ladder), §4 (two monthly cases), §6 (reconciliation),
 *              §7 (computation), §9 (fan-out)
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

        // Revision 2 §6–§7: bill on the lease's KNOWN EXTENT E.
        //   E = actual_return (closed) · end_date (expected, if set) ·
        //       else the billing/period date (truly open).
        // The caller (InvoiceGenerator) resolves E from the lease row and
        // passes it as extent_end; a missing extent_end defaults to
        // period_end — the truly-open case where the billing date IS the
        // extent, which preserves the legacy single-invoice behaviour.
        $extentEnd = (isset($params['extent_end']) && $params['extent_end'] !== null && $params['extent_end'] !== '')
            ? (string)$params['extent_end']
            : $periodEnd;

        // ── Step 1: day counts (R2 §7.1, D14 inclusive) ───────────
        // total_days_at_period_end is the forensic "days elapsed at this
        // invoice's period end" — days from start through period_end. The
        // tier/single-vs-spans REGIME is decided separately, inside
        // cumulativeCorrect(), by the duration through the known extent E.
        $totalDays  = self::inclusiveDays($startDate, $periodEnd);

        // Period days are informational for the line-item subtitle.
        $periodDays = self::inclusiveDays($periodStart, $periodEnd);

        // ── Step 3 (run early): already_billed (R2 §7.4 / D20) ────
        // Excludes void + soft-deleted invoices. Includes BOTH
        // 'base_rental' and 'base_rental_reconciliation_credit' lines —
        // the credit subtracts (is_credit=1) so already_billed is the NET
        // base rental contributed by prior invoices. Caller holds FOR
        // UPDATE on the lease row across this read + the later counter
        // writes (D20).
        $alreadyBilled = $this->sumAlreadyBilled($leaseId);

        // ── Step 2: cumulative_correct THROUGH this invoice's period_end ──
        // R2 §4 + §7: classify on E, then —
        //   • sub-month tiers (daily / weekly) → the whole-lease amount;
        //   • monthly, single calendar month → flat monthly (capped);
        //   • monthly, spans → the SUM of calendar-month segments from
        //     start through period_end (complete months flat at monthly,
        //     partial start/end months at days × monthly÷30).
        // Revision 2 supersedes the original §17 "whichever pays more" at
        // activation: there is no special first-invoice branch — the
        // single-month-flat rule plus this running reconciliation give the
        // same protection (a 22–30 day single-month lease pays the flat
        // month; a known span prorates immediately).
        $cc = $this->cumulativeCorrect(
            $startDate, $periodEnd, $extentEnd, $daily, $weekly, $monthly
        );
        $cumulativeCorrect = $cc['amount'];
        $tier              = $cc['tier'];
        $explanation       = $cc['explanation'];

        // ── Step 4: delta (R2 §7.5) ───────────────────────────────
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
        // R2 §20.3 + Appendix C. The InvoiceGenerator writes ONE audit_log
        // row per holistic-engine invoice using this payload. The added
        // extent_end / basis fields let a future auditor replay the
        // calendar-month classification without rebuilding it.
        $auditMeta = [
            'engine'             => 'holistic',
            'lease_id'           => $leaseId,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'period_days'        => $periodDays,
            'extent_end'         => $extentEnd,
            'total_days_so_far'  => $totalDays,
            'tier'               => $tier,
            'basis'              => $cc['basis'],
            'cumulative_correct' => $cumulativeCorrect,
            'already_billed'     => $alreadyBilled,
            'delta'              => $delta,
            'line_item_type'     => $lineItemType,
            'amount'             => $amount,
            'is_credit'          => $isCredit,
            'rule'               => 'running_reconciliation',
            'rates'              => [
                'daily'   => $daily,
                'weekly'  => $weekly,
                'monthly' => $monthly,
            ],
        ];
        if (!empty($cc['segments'])) {
            // For the spanning-monthly case, the per-segment breakdown that
            // summed to cumulative_correct through this period_end.
            $auditMeta['segments'] = $cc['segments'];
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
     * Revision 2 §4 + §7 — the cumulative_correct amount for a lease
     * billed from start_date through $throughDate, given its known extent
     * $extentEnd (E). Pure math; the only economic source of truth for the
     * holistic engine post-Revision-2.
     *
     * Classification is on the TOTAL duration through E:
     *   total ≤ 7       → cheaper-of ladder via the preserved sub-month tiers
     *   weeklyMath ≤ M  → weekly math (full weeks × weekly + leftover × weekly÷7)
     *   weeklyMath > M  → MONTHLY applies:
     *        single calendar month (start & E in one month) → flat monthly
     *        spans multiple months                          → Σ segments
     *           complete calendar month  → flat monthly
     *           partial start/end month  → days × (monthly ÷ 30)
     *
     * The crossover from weekly to monthly is rate-driven (weeklyMath vs
     * monthly), never a hardcoded day count (R2 §3). The daily figure used
     * for partial months is always monthly ÷ 30 (D-R2-5), independent of
     * the calendar month's length.
     *
     * $throughDate is clamped to E — an invoice never bills past the known
     * extent even if a caller passes a period_end beyond it (e.g. the
     * monthly cron's Y-m-t end on a lease that returns mid-month).
     *
     * @return array{amount:string, tier:string, basis:string,
     *               explanation:array<string>, segments:array}
     */
    public function cumulativeCorrect(
        string $start,
        string $throughDate,
        string $extentEnd,
        string $daily,
        string $weekly,
        string $monthly
    ): array {
        // Clamp the cumulative window to the known extent (never bill past E).
        $through = (self::ymd($throughDate) > self::ymd($extentEnd))
            ? $extentEnd : $throughDate;

        // The REGIME (sub-month ladder vs monthly) and the single-vs-spans
        // split are decided by the lease's TOTAL duration through E (R2 §7.1).
        // The cumulative AMOUNT is for the span billed so far — start through
        // period_end (R2 §7.3).
        $total       = self::inclusiveDays($start, $extentEnd);
        $daysThrough = self::inclusiveDays($start, $through);
        if ($total <= 0) {
            return ['amount' => '0.00', 'tier' => 'none', 'basis' => 'none',
                    'explanation' => ['0 cumulative days — no charge'], 'segments' => []];
        }

        // Monthly applies iff weekly math over the full extent exceeds the
        // monthly rate (R2 §3, rate-driven crossover — never a fixed day count).
        // S-BILLING-RATE-FIX: a monthly_rate of 0 means "no monthly tier
        // offered" (legitimate for daily/weekly-only or on_close_only leases) —
        // it must NEVER trigger the monthly regime, which would bill a flat $0
        // (single calendar month) or $0 segments (spanning) and trip the D132
        // zero-base backstop, fatally aborting billing / lease close. Require a
        // positive monthly rate before monthly can apply; otherwise the lease
        // stays on the sub-month weekly ladder.
        $monthlyApplies = ($total > 7)
            && bccomp($monthly, '0', 6) > 0
            && bccomp($this->weeklyMath($total, $weekly), $monthly, 6) > 0;

        if (!$monthlyApplies) {
            // ── Sub-month ladder (R2 §3, preserved) ──────────────
            // The ladder grows per accrued days: cumulative through period_end
            // uses the day count THROUGH period_end, so a short lease billed in
            // pieces (activation then close) reconciles correctly. Because the
            // regime is sub-month, weeklyMath(daysThrough) ≤ weeklyMath(total)
            // ≤ monthly — the monthly cap never trips here.
            $n = max(1, $daysThrough);
            if ($n <= 5) {
                $amount = bcround(bcmul($daily, (string)$n, 6), 2);
                return ['amount' => $amount, 'tier' => 'daily', 'basis' => 'daily',
                        'explanation' => ["{$n} cumulative days × \${$daily}/day = \${$amount}"],
                        'segments' => []];
            }
            if ($n <= 7) {
                $amount = bcround($weekly, 2);
                return ['amount' => $amount, 'tier' => 'weekly_flat', 'basis' => 'weekly_flat',
                        'explanation' => ["{$n} cumulative days — weekly flat rate = \${$amount}"],
                        'segments' => []];
            }
            $wm        = $this->weeklyMath($n, $weekly);
            $amount    = bcround($wm, 2);
            $fullWeeks = intdiv($n, 7);
            $remainder = $n % 7;
            $explanation = [];
            if ($fullWeeks > 0) {
                $explanation[] = "{$fullWeeks} week(s) × \${$weekly}";
            }
            if ($remainder > 0) {
                $explanation[] = "{$remainder} remaining days × \$" . bcround(bcdiv($weekly, '7', 6), 4) . "/day (weekly÷7)";
            }
            $explanation[] = "Cumulative {$n} days = \${$amount} (weekly_math)";
            return ['amount' => $amount, 'tier' => 'weekly_math', 'basis' => 'weekly_math',
                    'explanation' => $explanation, 'segments' => []];
        }

        $weeklyMath = $this->weeklyMath($total, $weekly);

        // ── Monthly applies (weeklyMath > monthly) ───────────────
        if (self::sameCalendarMonth($start, $extentEnd)) {
            // Single calendar month — flat monthly, capped (R2 §4.1). A
            // 22–30 day lease inside one month bills the flat month, never
            // prorated.
            $amount = bcround($monthly, 2);
            return ['amount' => $amount, 'tier' => 'monthly', 'basis' => 'monthly_single_month',
                    'explanation' => [
                        "{$total} cumulative days, single calendar month: weekly math \$" . bcround($weeklyMath, 2)
                            . " exceeds monthly — flat monthly rate = \${$amount}",
                    ],
                    'segments' => []];
        }

        // Spans multiple calendar months — Σ segments from start through
        // the (clamped) period_end (R2 §4.2 + §7.3).
        $seg = $this->sumSegments($start, $through, $monthly);
        return ['amount' => $seg['amount'], 'tier' => 'monthly', 'basis' => 'monthly_multi_month',
                'explanation' => $seg['explanation'], 'segments' => $seg['segments']];
    }

    /**
     * weeklyMath(n) per R2 §3 — full weeks at the weekly rate plus leftover
     * days at weekly ÷ 7. Mathematically n × (weekly ÷ 7); split so the full
     * weeks carry the exact weekly figure and only the remainder rounds.
     * Returns scale-6 bcmath (caller rounds the final amount to 2dp, D16).
     */
    public function weeklyMath(int $days, string $weekly): string
    {
        if ($days <= 0) {
            return '0.000000';
        }
        $fullWeeks      = intdiv($days, 7);
        $remainder      = $days % 7;
        $dailyFromWeekly = bcdiv($weekly, '7', 6);
        $weeklyPart     = bcmul($weekly, (string)$fullWeeks, 6);
        $remainderPart  = bcmul($dailyFromWeekly, (string)$remainder, 6);
        return bcadd($weeklyPart, $remainderPart, 6);
    }

    /**
     * Sum the calendar-month segments of [start, through] (R2 §4.2):
     *   complete calendar month (1st..last fully inside the window) → flat monthly
     *   partial month                                               → days × (monthly ÷ 30)
     *
     * "Complete" is judged against the [start, through] window: a month is
     * complete only when both its 1st and last day fall within it. The role
     * of E is solely in the tier/single-vs-spans decision upstream — this
     * sum walks the window it is given.
     *
     * Pure math, no DB. Returns the 2dp amount plus a per-segment breakdown
     * for the audit trail.
     *
     * @return array{amount:string, explanation:array<string>, segments:array}
     */
    private function sumSegments(string $start, string $through, string $monthly): array
    {
        $monthlyDaily = bcdiv($monthly, '30', 6);
        $startDt   = new \DateTimeImmutable($start);
        $throughDt = new \DateTimeImmutable($through);

        $sum         = '0';
        $segments    = [];
        $explanation = [];

        // Iterate calendar months from the start month's 1st up to through.
        $monthFirst = $startDt->modify('first day of this month')->setTime(0, 0, 0);
        $throughDay = $throughDt->setTime(0, 0, 0);

        while ($monthFirst <= $throughDay) {
            $monthLast = $monthFirst->modify('last day of this month');
            $segStart  = ($startDt > $monthFirst)   ? $startDt   : $monthFirst;
            $segEnd    = ($throughDt < $monthLast)   ? $throughDt : $monthLast;

            if ($segStart <= $segEnd) {
                $isComplete = ($segStart->format('Y-m-d') === $monthFirst->format('Y-m-d'))
                           && ($segEnd->format('Y-m-d')   === $monthLast->format('Y-m-d'));
                $segDays = self::inclusiveDays($segStart->format('Y-m-d'), $segEnd->format('Y-m-d'));

                if ($isComplete) {
                    $segAmount = bcround($monthly, 2);
                    $sum = bcadd($sum, $monthly, 6);
                    $explanation[] = $monthFirst->format('M Y') . " — complete month = \${$segAmount}";
                } else {
                    $segAmount = bcround(bcmul($monthlyDaily, (string)$segDays, 6), 2);
                    $sum = bcadd($sum, bcmul($monthlyDaily, (string)$segDays, 6), 6);
                    $explanation[] = $segStart->format('M j') . '–' . $segEnd->format('j')
                        . " ({$segDays} days × \$" . bcround($monthlyDaily, 4) . "/day) = \${$segAmount}";
                }

                $segments[] = [
                    'period_start' => $segStart->format('Y-m-d'),
                    'period_end'   => $segEnd->format('Y-m-d'),
                    'days'         => $segDays,
                    'complete'     => $isComplete,
                    'amount'       => $segAmount,
                ];
            }

            $monthFirst = $monthFirst->modify('first day of next month');
        }

        return [
            'amount'      => bcround($sum, 2),
            'explanation' => $explanation,
            'segments'    => $segments,
        ];
    }

    /**
     * Revision 2 §9 — calendar-month fan-out plan. Walks the calendar-month
     * segments of [fanStart, target], one entry per month, for the
     * InvoiceGenerator orchestrator to materialise one invoice per segment.
     *
     * fanStart = (latest non-void invoice's period_end + 1, else lease.start)
     * target   = return / end_date / today (the known extent)
     *
     * billing_type per segment:
     *   complete calendar month             → full_month
     *   starts after the 1st (start partial) → partial_start
     *   starts on the 1st but ends mid-month → partial_end
     *
     * Never emits a segment with period_start > period_end (INV-27 class):
     * an inverted [fanStart, target] returns []. The caller refuses loudly.
     *
     * @return array<array{period_start:string, period_end:string,
     *                     billing_type:string, complete:bool}>
     */
    public function segmentsFor(string $fanStart, string $target): array
    {
        $startDt  = new \DateTimeImmutable($fanStart);
        $targetDt = new \DateTimeImmutable($target);
        if ($targetDt < $startDt) {
            return [];
        }

        $segments   = [];
        $monthFirst = $startDt->modify('first day of this month')->setTime(0, 0, 0);
        $targetDay  = $targetDt->setTime(0, 0, 0);

        while ($monthFirst <= $targetDay) {
            $monthLast = $monthFirst->modify('last day of this month');
            $segStart  = ($startDt  > $monthFirst) ? $startDt  : $monthFirst;
            $segEnd    = ($targetDt < $monthLast)  ? $targetDt : $monthLast;

            if ($segStart <= $segEnd) {
                $startsOnFirst = ($segStart->format('Y-m-d') === $monthFirst->format('Y-m-d'));
                $endsOnLast    = ($segEnd->format('Y-m-d')   === $monthLast->format('Y-m-d'));
                if ($startsOnFirst && $endsOnLast) {
                    $btype = 'full_month';
                } elseif (!$startsOnFirst) {
                    $btype = 'partial_start';
                } else {
                    $btype = 'partial_end';
                }
                $segments[] = [
                    'period_start' => $segStart->format('Y-m-d'),
                    'period_end'   => $segEnd->format('Y-m-d'),
                    'billing_type' => $btype,
                    'complete'     => $startsOnFirst && $endsOnLast,
                ];
            }
            $monthFirst = $monthFirst->modify('first day of next month');
        }

        return $segments;
    }

    /** True when both dates fall in the same calendar month + year. */
    public static function sameCalendarMonth(string $a, string $b): bool
    {
        return (new \DateTimeImmutable($a))->format('Y-m')
            === (new \DateTimeImmutable($b))->format('Y-m');
    }

    /** Normalise a date string to Y-m-d for safe lexical comparison. */
    private static function ymd(string $d): string
    {
        return (new \DateTimeImmutable($d))->format('Y-m-d');
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
     * S-LEASE-RENTAL-DAY-TIME — Compute the effective billable end date
     * from the raw return date + time-of-day comparison.
     *
     * Rule (locked with operator):
     *   if (return_time > pickup_time + grace_minutes) → bill the return day
     *                                                     return $returnDate
     *   else (on-time or within grace)                 → final day not billed
     *                                                     return $returnDate − 1 day
     *
     * Minimum: $startDate — the result is never before the lease start, so
     * a same-day pickup + on-time return still produces at least 1 billed day
     * (inclusiveDays($startDate, $startDate) = 1, D14).
     *
     * Times are compared as integer minutes-since-midnight; no float math.
     * Called only when BOTH $returnTime and $pickupTime are non-empty strings
     * (caller is responsible for the NULL guard — NULL → legacy fall-back).
     *
     * @param string $returnDate   Y-m-d — raw actual_return_date
     * @param string $returnTime   HH:MM or HH:MM:SS — actual_return_time
     * @param string $pickupTime   HH:MM or HH:MM:SS — start_time
     * @param int    $graceMinutes lease.return_grace_minutes setting (default 0)
     * @param string $startDate    Y-m-d — lease start_date (min-1-day guard)
     * @return string  Y-m-d effective extent end
     */
    public static function effectiveBillableEndDate(
        string $returnDate,
        string $returnTime,
        string $pickupTime,
        int    $graceMinutes,
        string $startDate
    ): string {
        $returnMin = self::timeToMinutes($returnTime);
        $pickupMin = self::timeToMinutes($pickupTime);
        $deadline  = $pickupMin + $graceMinutes;

        if ($returnMin > $deadline) {
            // Late return: bill the return day as a full extent day.
            return $returnDate;
        }

        // On-time or within grace: the return day is not billed.
        $dt       = new \DateTimeImmutable($returnDate);
        $adjusted = $dt->modify('-1 day')->format('Y-m-d');

        // Min 1 billed day: never return a date before the lease start.
        return ($adjusted < $startDate) ? $startDate : $adjusted;
    }

    /**
     * Convert a HH:MM or HH:MM:SS time string to integer minutes since
     * midnight. Used for the time-of-day billing comparison (integer-only,
     * no float arithmetic per D16 spirit).
     */
    private static function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
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
