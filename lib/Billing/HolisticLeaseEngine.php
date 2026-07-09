<?php
declare(strict_types=1);

/**
 * lib/Billing/HolisticLeaseEngine.php
 *
 * Running-reconciliation billing engine. Replaces ProRateCalculator
 * for EVERY lease — the sole rental engine since S-DELETE-LEGACY-ENGINE
 * (leases.engine_version is a vestigial column, no longer dispatched on).
 *
 * The core idea (Revision 2 §6–§7): every invoice asks the same questions —
 *   1. Known extent E = actual_return (closed) · end_date (set) · else the
 *      billing date. total = inclusiveDays(start_date, E).
 *   2. Classify on total: ≤7 → cheaper-of daily/weekly ladder; weeklyMath ≤
 *      monthly → weekly math; weeklyMath > monthly → MONTHLY applies.
 *   3. cumulative_correct THROUGH this invoice's period_end:
 *        sub-month tier → the whole-lease amount;
 *        monthly, total extent ≤ one CALENDAR month → flat monthly,
 *          whether inside one calendar month OR straddling a boundary
 *          (S-MONTHLY-SHORT-FLAT — the monthly rate is the cap for any
 *          ≤1-month monthly-tier lease; calendar-aware so 28/29/30/31-day
 *          months all count as one month, via withinOneCalendarMonth();
 *          basis 'monthly_single_month' in-month, 'monthly_short_flat' straddle);
 *        monthly, extent > one calendar month → Σ calendar-month segments
 *          (complete months flat, partial start/end months at days × monthly÷30).
 *   4. already_billed = NET base_rental of all non-void prior invoices.
 *   5. delta = cumulative_correct − already_billed. Positive → base_rental;
 *      negative → base_rental_reconciliation_credit; zero → no line.
 *
 * Revision 2 (2026-06-07) supersedes the original spec's month math and §17
 * "whichever pays more" at activation. A full calendar month bills flat at
 * the monthly rate regardless of length (the 30-day-block math that charged
 * a 31-day month an extra day is removed). There is NO special first-invoice
 * branch: the ≤1-month-flat rule plus the running reconciliation give the
 * same protection — any monthly-tier lease that is at most one calendar month
 * pays the flat month (inside one calendar month OR straddling a boundary,
 * 28/29/30/31-day months alike, S-MONTHLY-SHORT-FLAT);
 * a lease known to run LONGER than a month prorates its partial start month
 * immediately; a longer span discovered later re-prorates the past month down
 * to its /30 value via a reconciliation credit ("prorate in the past").
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
     *   (is_activation_invoice: RETIRED — Revision 2 removed the activation
     *                                       branch; the body never reads this
     *                                       key. S-AUDIT-LIFECYCLE-1.)
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

        // S-LEASE-MIN-DAYS — short-lease floor (N days). Resolved upstream from
        // the three config layers (rate_card_items.minimum_days · leases
        // .minimum_billing_days · settings 'lease.minimum_billing_days') and
        // passed in as 'minimum_days'. 0 (absent / operator-disabled) is a
        // no-op; cumulativeCorrect() applies the >=2 / total<minDays / daily>0
        // binding rules.
        $minDays = (int)($params['minimum_days'] ?? 0);

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
        // Pass this invoice's period_end so future-period invoices are excluded
        // (S-HOLISTIC-ALREADY-BILLED-ORDER): makes the reconciliation order-independent
        // so regenerating an earlier invoice no longer counts later ones as already
        // billed. No-op for in-order generation (later invoices don't exist yet).
        $alreadyBilled = $this->sumAlreadyBilled($leaseId, $periodEnd);

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
        // S-LEASE-MIN-DAYS — $minDays passed as the trailing arg; the engine
        // floors the cumulative amount to a flat $minDays × daily when the
        // total billable duration is below the configured minimum.
        $cc = $this->cumulativeCorrect(
            $startDate, $periodEnd, $extentEnd, $daily, $weekly, $monthly, $minDays
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
            // S-LEASE-MIN-DAYS — record the resolved floor so an auditor can
            // replay whether the daily_minimum override could have bound.
            'minimum_days'       => $minDays,
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
     * S-LEASE-MIN-DAYS — $minDays is the short-lease floor: when the lease's
     * TOTAL billable duration (start through E) is shorter than $minDays, the
     * normal tier ladder is overridden by a FLAT $minDays × daily_rate charge
     * (tier='daily_minimum'). Resolved by the caller from the three config
     * layers (rate card item · lease · global setting); 0/1/NULL disables it.
     *
     * @param string $start       Lease start_date (Y-m-d)
     * @param string $throughDate This invoice's billing_period_end (Y-m-d)
     * @param string $extentEnd   Known extent E (return / end_date / billing date)
     * @param string $daily       Daily rate (bcmath)
     * @param string $weekly      Weekly rate (bcmath)
     * @param string $monthly     Monthly rate (bcmath)
     * @param int    $minDays     S-LEASE-MIN-DAYS short-lease floor in days;
     *                            binds only when >= 2 AND total < minDays AND
     *                            daily_rate > 0. 0/1 (or default) = no floor.
     * @return array{amount:string, tier:string, basis:string,
     *               explanation:array<string>, segments:array}
     */
    public function cumulativeCorrect(
        string $start,
        string $throughDate,
        string $extentEnd,
        string $daily,
        string $weekly,
        string $monthly,
        int $minDays = 0
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

        // S-LEASE-MIN-DAYS — short-lease floor. When the lease's TOTAL billable
        // duration through E is shorter than the configured minimum, bill a FLAT
        // $minDays × daily_rate, overriding the entire tier ladder below. We key
        // on $total (start..E), NOT $daysThrough (this invoice's period), so the
        // floor SELF-APPLIES at close/reconciliation (E shrinks to the actual
        // return) and NEVER binds for an ongoing long lease (total grows past
        // minDays). Guards: minDays>=2 (0/1/NULL = operator disabled the floor),
        // total<minDays (long enough → normal ladder), and a positive daily rate
        // (bccomp scale 6) so a daily-rate of 0 is a no-op and never floors to $0.
        if ($minDays >= 2 && $total < $minDays && bccomp($daily, '0', 6) > 0) {
            $amount = bcround(bcmul($daily, (string)$minDays, 6), 2);
            return ['amount' => $amount, 'tier' => 'daily_minimum', 'basis' => 'daily_minimum',
                    'explanation' => [
                        "{$total} billable day(s) is below the {$minDays}-day minimum — "
                            . "flat {$minDays} × \${$daily}/day = \${$amount} (daily_minimum)",
                    ],
                    'segments' => []];
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

        // S-MONTHLY-SHORT-FLAT — a monthly-tier lease whose TOTAL extent is at
        // most ONE CALENDAR MONTH but which STRADDLES a calendar-month boundary
        // still bills the FLAT monthly rate, exactly like the single-calendar-
        // month case above — NOT the sum of two partial-month prorations.
        //
        // Without this, a 22-day lease wholly inside July bills the flat $monthly
        // (the branch above), while the same 22 days across Jul 24–Aug 14 billed
        // only 22 × (monthly÷30) — a silent discount handed out purely for where
        // the lease falls on the calendar. (This reverses the original R2 §4.2/§5
        // "commit longer, better per-day" behaviour for the ≤1-month spanning
        // case — operator policy decision 2026-06-24: once the monthly tier
        // applies, a ≤1-month lease IS the monthly rate, straddle or not. Prod
        // INV-2026-00171 / lease MTTS73 surfaced it.)
        //
        // "One month" is CALENDAR-AWARE, NOT a fixed day count. The flat cap
        // applies when EITHER condition holds (their UNION):
        //   (a) total ≤ 30 days — the engine's monthly÷30 day-basis (D-R2-5);
        //   (b) withinOneCalendarMonth(start, extentEnd) — the span ends before
        //       start's monthiversary (start + 1 calendar month).
        // (b) is what makes a 31-day rolling month flat: a 31-day straddle
        // (Jul 24 → Aug 23) is never penalised vs a 30-day one — operator
        // 2026-06-24: "the engine should know what a month is — 30- and 31-day
        // months are both one month." (a) is REQUIRED ALONGSIDE (b) to keep the
        // charge MONOTONIC across SHORT (February) rolling months: a 28-day Feb
        // straddle (Feb 15 → Mar 14) is within one month → flat $monthly, but the
        // 29-day version (Feb 15 → Mar 15) passes the monthiversary and would
        // prorate to LESS than the flat month (29 × monthly÷30 < monthly) — a
        // longer lease costing less. The ≤30 arm catches it so it also flats,
        // and the first day truly BEYOND ~a month (total ≥ 31 and not a single
        // rolling month) re-prorates UP via the spanning path below. Like §4.1
        // this is the WHOLE-lease cap and ignores $through (an interim invoice
        // past the weekly→monthly crossover bills the flat month; close
        // reconciles to it).
        if ($total <= 30 || self::withinOneCalendarMonth($start, $extentEnd)) {
            $amount = bcround($monthly, 2);
            return ['amount' => $amount, 'tier' => 'monthly', 'basis' => 'monthly_short_flat',
                    'explanation' => [
                        "{$total} cumulative days (≤ one calendar month) spanning a boundary: weekly math \$"
                            . bcround($weeklyMath, 2) . " exceeds monthly — flat monthly rate = \${$amount}",
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

    /**
     * True when the inclusive span [start, end] is at most ONE CALENDAR MONTH
     * long — i.e. `end` falls strictly before `start`'s monthiversary (start +
     * 1 calendar month). Calendar-aware so 28/29/30/31-day months ALL count as
     * one month: a lease that runs exactly one month bills the flat monthly rate
     * whether that month is February (28d) or July (31d), and a 31-day rolling
     * month is never penalised over a 30-day one (S-MONTHLY-SHORT-FLAT).
     *
     * The monthiversary is the same day-of-month next month, CLAMPED to that
     * month's length for end-of-month starts (Jan 31 → Feb 28 by the standard
     * anniversary convention). `end == monthiversary` is one month + 0 days
     * (e.g. Jul 24 → Aug 24 = 32 inclusive days) and is NOT within one month.
     * Pure date math, no DB; D14 inclusive-day semantics preserved.
     */
    public static function withinOneCalendarMonth(string $start, string $end): bool
    {
        $s = new \DateTimeImmutable(self::ymd($start));
        $e = new \DateTimeImmutable(self::ymd($end));
        if ($e <= $s) {
            return true; // degenerate / inverted — a single point is ≤ one month
        }
        // Monthiversary = the start's day-of-month in the next month, clamped to
        // that month's length so DateTime's +1 month can't overflow (Jan 31 must
        // map to Feb 28, never to Mar 3).
        $firstOfNext   = $s->modify('first day of next month');
        $monthDay      = min((int) $s->format('d'), (int) $firstOfNext->format('t'));
        $monthiversary = $firstOfNext->modify('+' . ($monthDay - 1) . ' days');
        return $e < $monthiversary;
    }

    /** Normalise a date string to Y-m-d for safe lexical comparison. */
    private static function ymd(string $d): string
    {
        return (new \DateTimeImmutable($d))->format('Y-m-d');
    }

    /**
     * @internal TEST-ONLY — NOT the live billing ladder (S-AUDIT-LIFECYCLE-1).
     * Since Revision 2, calculateForInvoice() uses cumulativeCorrect(), whose
     * rate-driven weekly→monthly crossover CONTRADICTS this method's fixed
     * 8-29-day weekly band + monthly cap. Its only callers are the stress /
     * tier-conformance tests (and whicheverPaysMore below, also test-only).
     * Do NOT reuse it on a billing path — reuse cumulativeCorrect().
     *
     * Formulas match the retired ProRateCalculator::calculate (spec §5.2)
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
     * @internal TEST-ONLY — the §17 rule is RETIRED (S-AUDIT-LIFECYCLE-1).
     * Revision 2 replaced "whichever pays more" with the single-month-flat
     * rule + running reconciliation; NO billing path calls this method (only
     * the unit/stress tests do). Kept as a documented pure-math artifact.
     *
     * "Whichever pays more" rule (spec §17) — was applied ONLY at the
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
    /**
     * NET base rental already billed on this lease's OTHER invoices.
     *
     * @param int          $leaseId
     * @param string|null  $throughPeriodEnd  This invoice's period_end. When given,
     *   ONLY invoices whose period STARTS on or before it are counted — i.e. invoices
     *   for strictly-FUTURE periods (billing_period_start > throughPeriodEnd) are
     *   excluded. This is what makes the running reconciliation ORDER-INDEPENDENT:
     *   regenerating an EARLIER invoice while LATER ones already exist must not count
     *   the later invoices as "already billed" (that would zero the earlier one).
     *   During in-order generation later invoices don't exist yet, so the filter is a
     *   no-op there — sequential behaviour is unchanged; it only corrects regenerate /
     *   out-of-order recompute. null = legacy unfiltered (count every non-void invoice).
     */
    private function sumAlreadyBilled(int $leaseId, ?string $throughPeriodEnd = null): string
    {
        $sql = "SELECT COALESCE(SUM(CASE WHEN li.is_credit = 1 THEN -li.amount ELSE li.amount END), '0.00') AS sum_amount
                  FROM invoice_line_items li
                  JOIN invoices i ON i.id = li.invoice_id
                 WHERE i.lease_id = ?
                   AND i.deleted_at IS NULL
                   AND i.status <> 'void'
                   AND li.item_type IN ('base_rental', 'base_rental_reconciliation_credit')";
        $params = [$leaseId];
        if ($throughPeriodEnd !== null && $throughPeriodEnd !== '') {
            $sql .= " AND i.billing_period_start <= ?";
            $params[] = $throughPeriodEnd;
        }
        $row = db_row($sql, $params);
        return (string)($row['sum_amount'] ?? '0.00');
    }
}
