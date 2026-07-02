<?php
declare(strict_types=1);

/**
 * lib/Billing/InvoiceGenerator.php
 *
 * Invoice orchestrator — the ONLY billing class that reads/writes the database.
 * Calls HolisticLeaseEngine for rental math (running reconciliation — the SOLE
 * rental engine since S-DELETE-LEGACY-ENGINE removed the legacy period_independent
 * ProRateCalculator), TaxCalculator for tax, and LateFeeEngine for late fee math.
 * Handles invoice number generation (D15: gap-free, sequential, FOR UPDATE lock D20).
 *
 * Required by: api/v1/invoices/create.php, cron/invoice_generate_monthly.php,
 *              cron/late_fee_apply.php
 * Requires: includes/db.php, lib/Billing/HolisticLeaseEngine.php,
 *           lib/Billing/TaxCalculator.php, lib/Billing/LateFeeEngine.php
 * Defines: FleetForge\Billing\InvoiceGenerator
 *
 * Decisions: D3 (only DB writer in billing), D11 (tax at invoice time),
 *            D12 (immutability after send), D14 (inclusive days),
 *            D15 (sequential invoice numbers), D16 (bcmath), D20 (FOR UPDATE),
 *            D22 (granular tax exemptions)
 * Spec ref: §9 Invoice Generation Flow, §9 Invoice Calculation Order, §9 Late Fees
 *
 * ==========================================================================
 * PATH B — CANONICAL COUNTER TRUTH TABLE (S-FIX-2, 2026-05-02)
 * ==========================================================================
 * customers.outstanding_balance reflects SENT invoices only
 *   (sent / partially_paid / overdue). Drafts and voids do NOT count.
 * leases.total_invoiced continues to include drafts (lease-internal total,
 *   not a customer-facing AR figure).
 *
 *   Event                                 | OB delta             | total_invoiced
 *   --------------------------------------|----------------------|---------------
 *   Invoice created as draft              | unchanged            | += total_amount
 *   Draft -> sent (send.php)              | += balance_due       | unchanged
 *   Sent -> void                          | -= balance_due       | -= total_amount
 *   Sent -> paid (full payment)           | -= balance_due       | unchanged
 *   Sent -> partially_paid (partial pmt)  | -= allocated_amount  | unchanged
 *   Draft -> void                         | unchanged            | -= total_amount
 *   Sent -> deleted (super_admin)         | -= balance_due       | -= total_amount
 *   Draft -> deleted                      | unchanged            | -= total_amount
 *   Draft total_amount edited (update)    | unchanged            | += (new - old)
 *   Sent -> written_off                   | -= balance_due       | unchanged
 *   Payment del/reversed (sent invoice)   | += allocated_amount  | unchanged
 *   Payment del/reversed (now-void inv)   | unchanged (status grd)| unchanged
 *   NSF / bounced (sent invoice)          | += allocated_amount  | unchanged
 *   NSF / bounced (now-void invoice)      | unchanged (status grd)| unchanged
 *   Credit note applied                   | -= applied_amount    | unchanged
 *   AR deposit applied                    | -= applied_amount    | unchanged
 *   Late-fee invoice created (draft)      | unchanged            | += total_amount
 *
 * Every counter mutation site MUST match the row above for its event.
 * ==========================================================================
 */

namespace FleetForge\Billing;

class InvoiceGenerator
{
    private HolisticLeaseEngine   $holistic;
    private TaxCalculator         $taxCalc;
    private LateFeeEngine         $lateFee;

    public function __construct()
    {
        // S-DELETE-LEGACY-ENGINE: HolisticLeaseEngine (running reconciliation) is
        // the ONLY rental engine — the legacy ProRateCalculator / period_independent
        // path has been removed. Every lease bills holistically.
        $this->holistic = new HolisticLeaseEngine();
        $this->taxCalc  = new TaxCalculator();
        $this->lateFee  = new LateFeeEngine();
    }

    /**
     * Create a manual invoice for a lease.
     *
     * Follows the Invoice Calculation Order (spec §9):
     * 1. Base rental via HolisticLeaseEngine (running reconciliation)
     * 2. Mileage lines (pre-charge or reconciliation)
     * 3. Insurance/warranty/GPS add-ons
     * 4. subtotal = SUM(line_items)
     * 5. Apply discount
     * 6. subtotal_after_discount
     * 7. Apply tax (D11: looked up at invoice time)
     * 8. total_amount
     * 9. balance_due = total - credits - paid
     *
     * @param array $params {
     *   lease_id: int, period_start: string(Y-m-d), period_end: string(Y-m-d),
     *   billing_type: string, invoice_type: string, notes: ?string,
     *   internal_notes: ?string, po_number: ?string, created_by: ?int,
     *   extra_lines: ?array (manual line items),
     *   // SAMSARA-3: optional odometer + distance params
     *   odometer_at_period_start_km: ?string|float,
     *   odometer_at_period_end_km:   ?string|float,
     *   odometer_source:             ?string ('gps'|'manual'|'estimated'),
     *   odometer_fetched_at:         ?string (ISO 8601)
     * }
     * @return array{invoice_id: int, invoice_number: string}
     */
    public function createFromLease(array $params): array
    {
        $leaseId = (int)$params['lease_id'];
        $periodStart = $params['period_start'];
        $periodEnd = $params['period_end'];
        $billingType = $params['billing_type'];
        $invoiceType = $params['invoice_type'] ?? 'regular';

        // Wraps everything in a single transaction (Trap 6: denormalized counters)
        return db_transaction(function () use ($leaseId, $periodStart, $periodEnd, $billingType, $invoiceType, $params) {

            // Load lease with all rate/tax/customer data.
            // S-FIX-2 Bug #5: also pull customer exemption expiry dates so we can
            // re-evaluate exemption status at invoice creation time. The lease
            // record is NOT modified; only the invoice snapshots are demoted.
            $lease = db_row(
                "SELECT l.*, c.province, c.billing_address, c.email AS customer_email,
                        c.gst_exempt_expiry  AS customer_gst_exempt_expiry,
                        c.pst_exempt_expiry  AS customer_pst_exempt_expiry,
                        c.tax_exempt_expiry  AS customer_tax_exempt_expiry,
                        c.gst_exempt_number  AS customer_gst_exempt_number,
                        c.pst_exempt_number  AS customer_pst_exempt_number,
                        c.id                  AS customer_row_id,
                        c.company_name        AS customer_company_name,
                        eu.samsara_vehicle_id   AS samsara_vehicle_id,
                        eu.samsara_entity_type  AS samsara_entity_type,
                        et.category             AS equipment_category,
                        ec.enforce_minimum_billing_days AS category_enforce_min_days
                 FROM leases l
                 LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
                 LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
                 LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
                 -- S-EQTAX: resolve the equipment CATEGORY row that owns billing
                 -- rules. Prefer the authoritative category_id FK; fall back to
                 -- matching the legacy `category` slug mirror for rows not yet
                 -- backfilled. The OR's second arm is gated on category_id IS NULL
                 -- so at most one category row ever matches.
                 LEFT JOIN equipment_categories ec
                        ON ec.deleted_at IS NULL
                       AND (ec.id = et.category_id
                            OR (et.category_id IS NULL AND ec.slug = et.category))
                 WHERE l.id = ? AND l.deleted_at IS NULL",
                [$leaseId]
            );

            if (!$lease) {
                json_error('NOT_FOUND', 'Lease not found.', 404);
            }

            // Day counting: inclusive (D14)
            $startDt = new \DateTimeImmutable($periodStart);
            $endDt = new \DateTimeImmutable($periodEnd);
            $days = (int)$startDt->diff($endDt)->days + 1;

            // --- Step 1: Base rental ---
            // S-DELETE-LEGACY-ENGINE: HolisticLeaseEngine (running reconciliation) is
            // the SOLE rental engine — the legacy period_independent / ProRateCalculator
            // path was removed, so engine_version is no longer dispatched on here.
            //
            // ADV-BILL-1: 'mileage_only' (used by close.php for advance leases when
            // mileage overage needs its own invoice) skips the base rental entirely —
            // the only line items come from extra_lines.
            $lineItems = [];
            $sortOrder = 0;

            // Holistic-engine audit fields. Populated only on the holistic
            // path; remain NULL for the legacy path so the invoices row
            // truthfully reflects which engine generated it.
            $holisticTotalDays         = null;
            $holisticCumulativeCorrect = null;
            $holisticAlreadyBilled     = null;
            $holisticAuditMeta         = null;

            // Default values for the legacy path's existing variables.
            $rentalAmount = '0.00';
            $rateMethod   = 'none';
            $explanation  = [];
            $holisticLineItem = null;  // Set by holistic branch when delta != 0

            // S-LEASE-MIN-DAYS: establish the per-lease minimum-billing-days floor
            // once, defensively. The $lease array MAY NOT carry the
            // minimum_billing_days column depending on the caller's SELECT — the
            // load above uses l.* so it normally does, but a narrower caller
            // (cron/fixtures/direct SQL) might not. When the key is absent we
            // re-read it from the leases row so the floor is honoured regardless
            // of how the lease was loaded. This frozen-on-lease value is config
            // layer 2 (operator-overridable); layer 1 (rate_card_items.minimum_days)
            // and layer 3 (settings 'lease.minimum_billing_days') are resolved
            // upstream at lease creation into this single column, so by billing
            // time this is THE effective floor. 0/1/NULL → 0 here, which both
            // billing engines treat as a NO-OP.
            $minBillingDays = array_key_exists('minimum_billing_days', $lease)
                ? (int)($lease['minimum_billing_days'] ?? 0)
                : (int)(db_row('SELECT minimum_billing_days FROM leases WHERE id = ?', [$leaseId])['minimum_billing_days'] ?? 0);

            // S-LEASE-MIN-DAYS-CATEGORY (S-EQTAX): the short-lease minimum applies
            // ONLY to equipment categories that opt in via the per-category flag
            // equipment_categories.enforce_minimum_billing_days. This replaces the
            // old comma-separated 'lease.minimum_billing_days_categories' setting
            // (left vestigial for one release for clean rollback) with a row-level
            // flag the operator toggles from the taxonomy manage screen — and lets
            // a sub-type INHERIT its category's rule (Combo, now a Chassis
            // sub-category, gets the chassis minimum automatically). When this
            // lease's category does not opt in, force the floor to 0 so the
            // engine's >=2 binding guard (HolisticLeaseEngine::cumulativeCorrect)
            // makes it a no-op and a normal actual-days charge is billed. Resolved
            // at BILLING time so regenerate/reclose AUTO-CORRECTS existing draft
            // leases. A missing/NULL category (soft-deleted/unmapped template, or a
            // not-yet-backfilled row whose slug matches no category) is treated as
            // "do not enforce" — the floor must never silently apply to unknown
            // equipment. The N itself still comes from the frozen
            // leases.minimum_billing_days column (resolved above), unchanged.
            if ($minBillingDays >= 2) {
                $enforceMinDays = array_key_exists('category_enforce_min_days', $lease)
                    ? (int) ($lease['category_enforce_min_days'] ?? 0)
                    : 0;
                if ($enforceMinDays !== 1) {
                    $minBillingDays = 0;
                }
            }

            if ($billingType === 'mileage_only' || $billingType === 'adjustment') {
                // Both engines skip base_rental for mileage_only / adjustment
                // invoices — they carry only extra_lines (e.g. S-LEASE-SERVICE-
                // CHARGES closeout sweep/wash/fuel when no rental final invoice
                // is generated). 'adjustment' has no createFromLease callers
                // other than that closeout post-pass.
                $rentalAmount = '0.00';
                $rateMethod   = 'none';
                $explanation  = ['Adjustment / mileage-only — no base rental.'];
            } else {
                // S-DELETE-LEGACY-ENGINE: the holistic engine is the ONLY rental
                // engine now (period_independent / ProRateCalculator removed). Every
                // non-mileage_only invoice bills via running reconciliation.
                // ── Holistic engine path ────────────────────────────
                // D20: lock the lease row for the duration of the
                // already_billed read + later UPDATE counters. Mirrors
                // the drawdown emit pattern at lines ~543-547 so concurrent
                // invoice generation on the same lease serializes cleanly.
                db_row(
                    "SELECT id FROM leases WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                    [$leaseId]
                );

                // Revision 2 §6–§7: the engine bills on the lease's KNOWN
                // EXTENT E = actual_return (closed) · end_date (expected, if
                // set) · else this invoice's period_end (truly open — the
                // billing date IS the extent). E decides the tier and the
                // single-vs-spans split; the per-invoice cumulative still
                // sums through period_end. There is no longer an activation
                // "whichever pays more" branch (R2 supersedes §17) — the
                // single-month-flat rule + running reconciliation replace it.
                $extentEnd = $periodEnd;
                if (!empty($lease['actual_return_date'])) {
                    $extentEnd = (string)$lease['actual_return_date'];
                    // S-LEASE-RENTAL-DAY-TIME: apply time-of-day rule when
                    // both pickup and actual-return times are captured.
                    // NULL on either = legacy lease; fall back to inclusive
                    // date-only extent (no change in behaviour).
                    if (!empty($lease['actual_return_time']) && !empty($lease['start_time'])) {
                        $grace     = (int)(settings_get('lease.return_grace_minutes', '0') ?? '0');
                        $extentEnd = HolisticLeaseEngine::effectiveBillableEndDate(
                            $extentEnd,
                            (string)$lease['actual_return_time'],
                            (string)$lease['start_time'],
                            $grace,
                            (string)$lease['start_date']
                        );
                    }
                } elseif (!empty($lease['end_date'])) {
                    $extentEnd = (string)$lease['end_date'];
                }

                // S-LEASE-CLOSE-REMOVE-DAYS: subtract N billable days off the END
                // of the lease's billable extent at close. The holistic engine
                // RE-DERIVES its extent from actual_return_date (above), so a
                // reduced period_end alone would NOT shrink the holistic bill —
                // the subtraction MUST happen here, on the same extentEnd the
                // engine measures totalBillableDays against. Gated on
                // actual_return_date (a close, not an in-progress invoice) so a
                // mid-lease invoice is never short-billed. Clamped so the extent
                // never goes before start_date (min 1 billed day); the existing
                // S-LEASE-MIN-DAYS floor in HolisticLeaseEngine::cumulativeCorrect
                // then evaluates on this REDUCED total and still wins — removal
                // first, floor clamps the bottom (no engine change needed). This
                // clamp is byte-identical in logic to lease_billable_extent() in
                // api/v1/leases/_close_reconciliation.php so the overshoot clamp
                // and the final invoice cannot diverge (S-CLOSE-OVERSHOOT).
                $daysRemoved = (int)($lease['billing_days_removed'] ?? 0);
                if ($daysRemoved > 0 && !empty($lease['actual_return_date'])) {
                    $reduced   = (new \DateTimeImmutable($extentEnd))->modify("-{$daysRemoved} day")->format('Y-m-d');
                    $extentEnd = ($reduced < (string)$lease['start_date']) ? (string)$lease['start_date'] : $reduced;
                }

                $engineResult = $this->holistic->calculateForInvoice([
                    'lease_id'             => $leaseId,
                    'start_date'           => (string)$lease['start_date'],
                    'period_start'         => $periodStart,
                    'period_end'           => $periodEnd,
                    'extent_end'           => $extentEnd,
                    'daily_rate'           => (string)$lease['daily_rate'],
                    'weekly_rate'          => (string)$lease['weekly_rate'],
                    'monthly_rate'         => (string)$lease['monthly_rate'],
                    // S-LEASE-MIN-DAYS: per-lease short-lease floor. The engine
                    // measures totalBillableDays against the EXTENT (start..extent_end)
                    // and, when minDays>=2 AND totalBillableDays<minDays AND
                    // daily_rate>0, replaces the tier ladder with a flat
                    // minDays × daily charge. Computing against the extent (not the
                    // per-invoice period) is what makes the floor self-apply at
                    // close/reconciliation yet never bind for ongoing long leases.
                    'minimum_days'         => $minBillingDays,
                ]);

                // Capture audit-column values for the invoices INSERT and
                // the audit_log entry. Always populated on the holistic path
                // (even when the engine emits no line item, the audit
                // columns reflect the engine's evaluation).
                $holisticTotalDays         = $engineResult['total_days_so_far'];
                $holisticCumulativeCorrect = $engineResult['cumulative_correct'];
                $holisticAlreadyBilled     = $engineResult['already_billed'];
                $holisticAuditMeta         = $engineResult['audit_meta'];

                // S-LEASE-CLOSE-REMOVE-DAYS: stamp the day-removal as an
                // INTERNAL-ONLY note into the holistic audit_meta (detail_lines).
                // The admin invoice page renders detail_lines as a staff-only
                // "how this charge was calculated" panel; the customer portal
                // renders ONLY the line-item description (which already shows the
                // naturally reduced period — no removal note). So the customer
                // sees a normal reduced-period line; the operator sees why.
                if ($daysRemoved > 0 && !empty($lease['actual_return_date'])) {
                    $holisticAuditMeta['billing_days_removed'] = $daysRemoved;
                    $holisticAuditMeta['billing_days_removed_note'] =
                        "{$daysRemoved} billable day(s) removed at close — actual return {$lease['actual_return_date']}, billed through {$extentEnd}.";
                }

                // rate_method_used is constrained by the invoices.rate_method_used
                // ENUM ('daily','weekly','weekly_capped','monthly','none').
                // Map the engine's richer tier vocabulary to that column.
                $rateMethod = $this->mapTierToRateMethod($engineResult['tier']);
                // For the line item, also map via a separate helper so the
                // invoice_line_items.rate_method ENUM (same set + null) is
                // never assigned a value outside its domain.
                $lineRateMethod = $rateMethod === 'none' ? null : $rateMethod;
                $explanation    = $engineResult['explanation'];

                // For the legacy variable rentalAmount (used by lease_billing_periods
                // base_amount + the D132 zero-base backstop below): use the
                // engine's chosen amount when emitting base_rental, else $0.
                // The reconciliation-credit case legitimately ships $0 base_amount
                // on lease_billing_periods (the credit is on the new line item).
                if ($engineResult['line_item_type'] === 'base_rental') {
                    $rentalAmount = $engineResult['amount'];
                } else {
                    $rentalAmount = '0.00';
                }

                // Emit the holistic engine's chosen line (if any) — its own
                // append so the description and detail_lines reflect the running
                // reconciliation.
                if ($engineResult['line_item_type'] !== 'none') {
                    // ── Honest line-item presentation ───────────────────
                    // A positive base_rental delta can be one of two things:
                    //   (1) a FRESH charge for new days at the end of the
                    //       cumulative window (sequential billing) — the
                    //       "lease day X-Y" framing is correct; or
                    //   (2) a RECONCILIATION TOP-UP where this invoice's
                    //       period OVERLAPS days an earlier non-void invoice
                    //       already billed. Here the amount is just the
                    //       cumulative delta (e.g. $50), and presenting it as
                    //       "{N} billing days · Monthly rate · 1 period" is
                    //       misleading — it reads as a whole period billed for
                    //       a few dollars. Detect the overlap and label the
                    //       delta honestly instead, suppressing the per-line
                    //       day/rate subtitle (the cumulative basis lives in
                    //       the description + detail_lines).
                    $overlapInv = ($engineResult['line_item_type'] === 'base_rental')
                        ? self::findOverlappingInvoice($leaseId, $periodStart, $periodEnd)
                        : null;
                    $isReconcileTopUp = $overlapInv !== null
                        && bccomp($holisticAlreadyBilled, '0', 2) > 0;
                    // Initialised false here so it's defined for ALL line-item branches
                    // (the holisticLineItem billing_days reads it); set true only on the
                    // fresh-charge branch below when the short-lease minimum bound.
                    $minBound = false;

                    if ($engineResult['line_item_type'] !== 'base_rental') {
                        // Negative-delta reconciliation credit (unchanged wording).
                        $holisticDescription =
                            "Base rental reconciliation credit: prior periods overcharged at lower tier "
                            . "(lease day {$holisticTotalDays} cumulative \${$holisticCumulativeCorrect} "
                            . "vs already billed \${$holisticAlreadyBilled})";
                    } elseif ($isReconcileTopUp) {
                        // Honest reconciliation top-up wording.
                        $holisticDescription =
                            "Base rental reconciliation: cumulative \${$holisticCumulativeCorrect} "
                            . "for {$holisticTotalDays} days through {$periodEnd}; \${$holisticAlreadyBilled} "
                            . "already billed (overlaps {$overlapInv['invoice_number']}) "
                            . "\u{2192} +\${$engineResult['amount']} this invoice";
                    } else {
                        // Fresh sequential charge — original framing is correct.
                        //
                        // S-LEASE-CLOSE-ACTUAL-DATE: when the return-day-not-billed
                        // time-of-day rule trimmed the final day (customer returned
                        // at/before pickup time + grace), the engine bills through
                        // actual_return_date − 1, so $periodEnd is one day short of
                        // the REAL return. The trim is a billing calculation, not a
                        // shorter rental — the customer should still see the ACTUAL
                        // lease dates on the invoice. Show start .. actual_return_date
                        // with a note that the return day wasn't charged; the amount
                        // and the billed-day count stay the trimmed value. Excludes
                        // the operator "Remove N days" case (billing_days_removed > 0),
                        // which intentionally presents the reduced period to the
                        // customer (S-LEASE-CLOSE-REMOVE-DAYS). billing_period_end on
                        // the invoice stays the trimmed extent (it drives coverage /
                        // overshoot math) — only this customer-facing label changes.
                        $timeRuleTrimmed = !empty($lease['actual_return_date'])
                            && !empty($lease['actual_return_time'])
                            && !empty($lease['start_time'])
                            && (int) ($lease['billing_days_removed'] ?? 0) === 0
                            && (string) $lease['actual_return_date'] > (string) $periodEnd;

                        // S-LEASE-MIN-DAYS label: when the short-lease minimum bound,
                        // the AMOUNT is a flat minDays × daily floor, not {$days} × daily,
                        // so the line must say "billed {minDays} days ({minDays}-day
                        // minimum)" instead of the misleading "{$days} days". The engine
                        // owns the bind decision — tier 'daily_minimum' is the single
                        // source of truth (HolisticLeaseEngine::cumulativeCorrect); the
                        // billed count is $minBillingDays (what the engine was handed).
                        // After S-LEASE-MIN-DAYS-CATEGORY a non-chassis lease gets
                        // minBillingDays=0 → tier != 'daily_minimum' → $minBound false,
                        // so these branches correctly no-op for it.
                        $minBound = (($engineResult['tier'] ?? '') === 'daily_minimum');

                        // Unicode punctuation (en-dash / middot / em-dash) via double-quoted
                        // "\u{…}" — matches the → used elsewhere in this file's descriptions.
                        // Combined case FIRST so it wins when both conditions hold.
                        if ($timeRuleTrimmed && $minBound) {
                            $holisticDescription =
                                'Base rental: ' . $periodStart . " \u{2013} "
                                . (string) $lease['actual_return_date']
                                . " \u{00B7} billed " . $minBillingDays . ' days ('
                                . $minBillingDays . "-day minimum; return day not charged "
                                . "\u{2014} returned by pickup time)";
                        } elseif ($timeRuleTrimmed) {
                            $holisticDescription =
                                'Base rental: ' . $periodStart . " \u{2013} "
                                . (string) $lease['actual_return_date']
                                . " \u{00B7} billed " . $days . ' days '
                                . "(return day not charged \u{2014} returned by pickup time)";
                        } elseif ($minBound) {
                            $holisticDescription =
                                'Base rental: ' . $periodStart . ' to ' . $periodEnd
                                . ' (billed ' . $minBillingDays . " days \u{2014} "
                                . $minBillingDays . '-day minimum)';
                        } else {
                            $holisticDescription =
                                "Base rental: {$periodStart} to {$periodEnd} ({$days} days, lease day "
                                . max(1, $holisticTotalDays - $days + 1) . "-{$holisticTotalDays})";
                        }
                    }

                    $holisticLineItem = [
                        'sort_order'   => $sortOrder++,
                        'item_type'    => $engineResult['line_item_type'],
                        'description'  => $holisticDescription,
                        'detail_lines' => json_encode($holisticAuditMeta),
                        'quantity'     => '1.0000',
                        // Reconciliation top-ups are an adjustment, not a sold
                        // "period"; sequential charges keep the period unit.
                        'unit'         => $isReconcileTopUp ? 'adjustment' : 'period',
                        'unit_price'   => $engineResult['amount'],
                        'amount'       => $engineResult['amount'],
                        'is_credit'    => $engineResult['is_credit'],
                        'taxable'      => 1,
                        // Suppress the misleading "{N} billing days · {tier} rate"
                        // subtitle on reconciliation top-ups (the days/tier describe
                        // the cumulative basis, not this $delta line). When the
                        // short-lease minimum bound, show the BILLED minimum day count
                        // so the admin "{N} billing days" subtitle agrees with the
                        // "billed {minDays} days" description (the amount is minDays ×
                        // daily, not $days × daily).
                        'billing_days' => $isReconcileTopUp ? null : ($minBound ? $minBillingDays : $days),
                        'rate_method'  => $isReconcileTopUp ? null : $lineRateMethod,
                        'period_start' => $periodStart,
                        'period_end'   => $periodEnd,
                    ];
                    $lineItems[] = $holisticLineItem;
                }
                // If line_item_type === 'none' (delta = 0), emit no
                // base_rental line — spec §7.5.
            }

            // S-DELETE-LEGACY-ENGINE: the holistic engine is the sole rental engine.
            // It emits its own base_rental / reconciliation line above (or none, for
            // delta=0) and throws its own BillingRateException at the engine boundary
            // for the zero-rate-with-period-days case — so the former legacy-only $0
            // backstop and base_rental append (both gated on a non-holistic engine)
            // are gone. mileage_only / adjustment invoices carry only extra_lines.

            // ════════════════════════════════════════════════════════════════
            // S-MILEAGE-2A: Invoice 1 mileage_precharge emission point (SHIPPED 2026-05-12, e1918df).
            //
            // 2A emission gate (D-B + (b) cross-invoice uniqueness):
            //   precharge_enabled = 1                           (lease opted in)
            //   AND precharge_invoiced_at IS NULL               (Invoice 1 not yet sent)
            //   AND no prior non-void invoice on this lease carries a
            //       mileage_precharge line                      ((b) uniqueness gate)
            //   AND billing_type NOT IN (mileage_only,
            //                            adjustment, credit_note) (regular invoice type)
            //
            // The (b) uniqueness gate prevents duplicate emission across an
            // advance-batch (generateAdvanceBatch) where N+1 drafts materialize
            // in a single transaction with precharge_invoiced_at still NULL.
            // The D-D 409 PRECHARGE_ALREADY_BILLED in send.php is the
            // racy-concurrent-gen safety net; this gate is the emission-time
            // prevention so the operator never lands in a strip-N-drafts
            // workflow. Belt + suspenders mirroring the D-D shape.
            //
            // S-MILEAGE-2B drawdown emit (SHIPPED 2026-05-12, below): on
            // Invoice 2+ for Model B (full) leases AND every invoice for
            // Model B Lite leases (D135), emits `mileage_usage` + optional
            // `mileage_drawdown_credit` per the locked D-B math.
            //
            // S-MILEAGE-3 will add close-refund line types (cash refund /
            // credit_note); this engine block stays the precharge emission
            // anchor for Model B's Invoice 1 lifecycle.
            // ════════════════════════════════════════════════════════════════
            $prechargeEmit = (
                (int) ($lease['precharge_enabled'] ?? 0) === 1
                && ($lease['precharge_invoiced_at'] ?? null) === null
                && !in_array($billingType, ['mileage_only', 'adjustment', 'credit_note'], true)
            );
            if ($prechargeEmit) {
                $priorPrecharge = db_row(
                    "SELECT 1 AS hit
                       FROM invoice_line_items li
                       JOIN invoices i ON i.id = li.invoice_id
                      WHERE i.lease_id = ?
                        AND i.deleted_at IS NULL
                        AND i.status <> 'void'
                        AND li.item_type = 'mileage_precharge'
                      LIMIT 1",
                    [$leaseId]
                );
                if ($priorPrecharge) {
                    $prechargeEmit = false;
                }
            }
            if ($prechargeEmit) {
                $prechargeAmt = bcround((string) $lease['precharge_amount'], 2);
                $lineItems[] = [
                    'sort_order'  => $sortOrder++,
                    'item_type'   => 'mileage_precharge',
                    'description' => "Mileage Precharge: \${$prechargeAmt} (covers excess mileage charges throughout lease)",
                    'quantity'    => '1.0000',
                    'unit'        => 'precharge',
                    'unit_price'  => $prechargeAmt,
                    'amount'      => $prechargeAmt,
                    'is_credit'   => 0,
                    'taxable'     => 1,
                ];
            }

            // ════════════════════════════════════════════════════════════════
            // S-MILEAGE-2B C3 — Odometer & period_distance_km setup
            // (moved earlier in the flow vs the SAMSARA-3 location since the
            // drawdown emit below needs period_distance_km available before
            // the $lineItems subtotal aggregation at line ~356).
            //
            // Values come in from callers (api/v1/invoices/create.php or
            // api/v1/leases/close.php) via $params. They're optional — when
            // omitted, the four distance columns stay null and the invoice
            // behaves exactly like it did before SAMSARA-3.
            //
            // period_distance_km     = end - start (this period)
            // cumulative_distance_km = end - lease.odometer_start_km
            //                          (since lease start)
            //
            // D-C Samsara integration (S-MILEAGE-2B): when no caller-supplied
            // odometer values are present AND the lease has a samsara_vehicle_id
            // AND we have period start/end dates, optionally fetch distance
            // via SamsaraClient::getDistanceForPeriod (S-MILEAGE-1B contract).
            // Per project memory rule ("Samsara is source-of-truth-by-default,
            // never source-of-truth-by-force"), the caller path with explicit
            // odometer params takes precedence — Samsara fetch is the fallback
            // when callers don't pre-populate. Distance fields stay manually
            // editable post-emit.
            // ════════════════════════════════════════════════════════════════
            $odoStartKm = isset($params['odometer_at_period_start_km']) && $params['odometer_at_period_start_km'] !== ''
                ? (string) $params['odometer_at_period_start_km'] : null;
            $odoEndKm   = isset($params['odometer_at_period_end_km']) && $params['odometer_at_period_end_km'] !== ''
                ? (string) $params['odometer_at_period_end_km']   : null;

            // S-LEASE-MILEAGE-MODE: resolve this lease's mileage data source.
            // Default 'samsara' preserves the legacy auto-billing behavior for
            // any row where the column is somehow absent (real leases always
            // carry it via SELECT l.*). When the lease is 'off', mileage is not
            // tracked at all: drop any caller-supplied odometer so no snapshot
            // is written and no distance/mileage line can be computed below.
            $mileageMode = $lease['mileage_tracking_mode'] ?? 'samsara';
            if ($mileageMode === 'off') {
                $odoStartKm = null;
                $odoEndKm   = null;
            }

            // ════════════════════════════════════════════════════════════════
            // S-MILEAGE-MODE-OFF-WARN — billing-time net for the silent
            // "rate configured but tracking Off" misconfiguration.
            //
            // A lease that carries a mileage_rate_km > 0 but has
            // mileage_tracking_mode = 'off' will bill ZERO mileage on every
            // invoice: the off branch above nulls the odometer, and the
            // mileage_usage drawdown gate further requires mileageMode !== 'off'.
            // That is exactly the MTTS68 / INV-2026-00150 incident — an operator
            // set a $/km rate and entered a reading, but the mode stayed at its
            // 'off' column default, so the invoice silently shipped no mileage
            // line. Surface it LOUDLY but NON-BLOCKINGLY here so the
            // contradiction is caught no matter which path generated the invoice
            // (close.php, manual api/v1/invoices/create, or the monthly cron) —
            // the create-form guard only covers the interactive create path.
            // Mirrors the D-C soft-warning pattern further down (Sentry
            // 'warning' + audit_log row, never throws). Excludes
            // mileage_only/adjustment/credit_note, which legitimately never
            // carry a base mileage line.
            if ($mileageMode === 'off'
                && !in_array($billingType, ['mileage_only', 'adjustment', 'credit_note'], true)
                && bccomp((string) ($lease['mileage_rate_km'] ?? '0'), '0', 4) > 0
            ) {
                $offWarnMsg = sprintf(
                    'Lease #%d (%s) has a mileage rate configured (mileage_rate_km=%s) but mileage_tracking_mode is OFF — invoice %s..%s will bill $0 mileage. Set the lease to manual or samsara tracking to bill mileage.',
                    $leaseId,
                    (string) ($lease['contract_number'] ?? ''),
                    (string) $lease['mileage_rate_km'],
                    $periodStart, $periodEnd
                );
                try {
                    \FleetForge\Observability\Sentry::captureMessage($offWarnMsg, 'warning');
                } catch (\Throwable) {
                    // Observability MUST NOT block the billing path.
                }
                db_insert('audit_log', [
                    'user_id'      => $params['created_by'] ?? null,
                    'user_name'    => (function_exists('current_user') && current_user())
                                        ? (current_user()['name'] ?? 'system') : 'system',
                    'action'       => 'update',
                    'module'       => 'billing',
                    'entity_type'  => 'lease',
                    'entity_id'    => $leaseId,
                    'entity_label' => $lease['contract_number'] ?? null,
                    'notes'        => '[FLEETFORGE_BILLING_WARNING] ' . $offWarnMsg,
                    'old_values'   => null,
                    'new_values'   => json_encode([
                        'mileage_tracking_mode' => 'off',
                        'mileage_rate_km'       => (string) ($lease['mileage_rate_km'] ?? '0'),
                        'period_start'          => $periodStart,
                        'period_end'            => $periodEnd,
                    ]),
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
            }

            $periodDistanceKm     = null;
            $cumulativeDistanceKm = null;
            if ($odoStartKm !== null && $odoEndKm !== null) {
                // Only compute when both ends have a value — prevents nonsense
                // "cumulative since null" rows in the DB.
                $diff = bcsub($odoEndKm, $odoStartKm, 2);
                // Clamp negative period distance to 0 — negative readings
                // mean a bad user edit, not reality. The UI will already
                // have surfaced a warning, but defend the DB anyway.
                $periodDistanceKm = bccomp($diff, '0', 2) >= 0 ? $diff : '0.00';
            }
            if ($odoEndKm !== null && !empty($lease['odometer_start_km'])) {
                $cumDiff = bcsub($odoEndKm, (string) $lease['odometer_start_km'], 2);
                $cumulativeDistanceKm = bccomp($cumDiff, '0', 2) >= 0 ? $cumDiff : '0.00';
            }

            // ════════════════════════════════════════════════════════════════
            // S-LEASE-HOURLY-BILLING — engine/reefer-hours setup (manual only)
            // Parallel to the odometer block above but simpler: no source/mode,
            // no precharge. Caller supplies the period's start/end hours
            // readings; we snapshot them on the invoice and (if hourly_rate>0)
            // emit one hourly_usage line = period hours × hourly_rate below.
            // ════════════════════════════════════════════════════════════════
            $hoursStart = isset($params['engine_hours_at_period_start']) && $params['engine_hours_at_period_start'] !== ''
                ? (string) $params['engine_hours_at_period_start'] : null;
            $hoursEnd   = isset($params['engine_hours_at_period_end']) && $params['engine_hours_at_period_end'] !== ''
                ? (string) $params['engine_hours_at_period_end']   : null;

            $periodEngineHours = null;
            if ($hoursStart !== null && $hoursEnd !== null) {
                // Clamp a negative delta to 0 (bad manual edit) — same defensive
                // posture as period_distance_km above.
                $hDiff = bcsub($hoursEnd, $hoursStart, 2);
                $periodEngineHours = bccomp($hDiff, '0', 2) >= 0 ? $hDiff : '0.00';
            }

            $odometerSource = $params['odometer_source'] ?? null;
            if ($odometerSource !== null && !in_array($odometerSource, ['gps', 'manual', 'estimated'], true)) {
                $odometerSource = 'manual';
            }
            $odometerFetchedAt = null;
            if (!empty($params['odometer_fetched_at'])) {
                try {
                    $dt = new \DateTime((string) $params['odometer_fetched_at']);
                    $odometerFetchedAt = $dt->format('Y-m-d H:i:s');
                } catch (\Throwable) {
                    $odometerFetchedAt = null;
                }
            }

            // D-C Samsara fallback fetch — only when caller didn't pre-populate
            // distance AND lease has a samsara_vehicle_id AND we have valid
            // period dates. Test callers reach this block in two ways:
            //   (a) pre-populate odometer_at_period_*_km → first guard skips
            //   (b) create lease without samsara_vehicle_id → second guard skips
            // Either guard short-circuits, so no test-only opt-out param is
            // needed (post-C3 investigation 2026-05-12 — skip_samsara param
            // was introduced + removed in C4 first hunk per operator review).
            // S-LEASE-MILEAGE-MODE: only fall back to Samsara when this lease is
            // in 'samsara' mode. 'manual' leases bill from operator-entered
            // odometer only; 'off' leases never bill mileage. This is the gate
            // that stops auto-billing from overwriting a manual reading with a
            // GPS (or 0) value — the core of the reported bug.
            if ($periodDistanceKm === null
                && $mileageMode === 'samsara'
                && !empty($lease['samsara_vehicle_id'])
                && $billingType !== 'mileage_only'
            ) {
                try {
                    $samsara   = new \FleetForge\GPS\SamsaraClient();
                    $startDtUtc = (new \DateTimeImmutable($periodStart . ' 00:00:00', new \DateTimeZone('UTC')));
                    $endDtUtc   = (new \DateTimeImmutable($periodEnd   . ' 23:59:59', new \DateTimeZone('UTC')));
                    $samsaraResult = $samsara->getDistanceForPeriod(
                        (string) $lease['samsara_vehicle_id'],
                        $startDtUtc, $endDtUtc, 'km',
                        (string) ($lease['samsara_entity_type'] ?? 'vehicle')
                    );
                    if ($samsaraResult['distance'] !== null) {
                        $periodDistanceKm   = (string) $samsaraResult['distance'];
                        $odometerSource     = $samsaraResult['source'] ?? 'gps';
                        $odometerFetchedAt  = (new \DateTime())->format('Y-m-d H:i:s');
                    }
                    // audit_log row regardless of success/failure (D102/D123 pattern —
                    // action='cron' since action ENUM doesn't include
                    // 'samsara_history_query'; entity_type carries the descriptive value).
                    db_insert('audit_log', [
                        'user_id'      => $params['created_by'] ?? null,
                        'user_name'    => (function_exists('current_user') && current_user())
                                            ? (current_user()['name'] ?? 'system') : 'system',
                        'action'       => 'cron',
                        'module'       => 'samsara',
                        'entity_type'  => 'samsara_history_query',
                        'entity_id'    => $leaseId,
                        'entity_label' => $lease['contract_number'] ?? null,
                        'notes'        => sprintf(
                            'InvoiceGenerator Samsara distance fetch (S-MILEAGE-2B D-C): vehicle=%s period=%s..%s distance=%s source=%s reason=%s',
                            (string) $lease['samsara_vehicle_id'],
                            $periodStart, $periodEnd,
                            (string) ($samsaraResult['distance'] ?? 'null'),
                            (string) ($samsaraResult['source'] ?? 'none'),
                            (string) ($samsaraResult['reason'] ?? 'ok')
                        ),
                        'new_values'   => json_encode($samsaraResult),
                        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    ]);
                } catch (\Throwable $e) {
                    // Samsara fetch must NEVER block billing — log and proceed
                    // with caller-supplied (or null) distance.
                    error_log('[InvoiceGenerator Samsara fetch] ' . $e->getMessage());
                }
            }

            // ════════════════════════════════════════════════════════════════
            // S-MILEAGE-EST-DAILY — estimated daily mileage + running true-up.
            //
            // Replaces the former per-period "bill actual usage" model (the
            // mileage_usage drawdown emit) with a two-part scheme on every
            // normal invoice:
            //   (1) mileage_estimate = billable days × estimated_mileage_per_day
            //       × rate — predictable, accrues day-by-day; emitted whenever the
            //       lease carries a per-day estimate.
            //   (2) running true-up = reconcile CUMULATIVE actual distance × rate
            //       against EVERYTHING billed for mileage so far (estimates + prior
            //       true-ups + legacy usage lines). Posts a mileage_adjustment
            //       (charge) or mileage_credit (credit) for the delta, but only
            //       when an actual reading is available for this period.
            //
            // Cumulative actual (km) is authoritative from the odometer when a
            // closing reading is supplied (odoEnd − lease.odometer_start_km —
            // manual close / any mid-lease reading); otherwise the sum of prior
            // recorded period distances plus this period (Samsara per-period).
            // When neither is available (manual interim, no reading) only the
            // estimate bills and the true-up defers to a later invoice — naturally
            // the close invoice, which trues the whole lease up to actual. Net
            // lifetime mileage revenue therefore equals total actual × rate; the
            // estimate is a cash-flow smoother the true-up always corrects.
            //
            // Cutover: a legacy lease with per_day = 0 emits no estimate line, so
            // the true-up alone reconciles cumulative-actual vs its prior
            // mileage_usage lines → it keeps billing actual, just via adjustment
            // lines. No double-bill (mileage_usage is no longer emitted).
            //
            // Precharge (Model B, opt-in) is preserved orthogonally below: after
            // the estimate + true-up lines, any positive NET mileage charge on this
            // invoice draws down precharge_balance (mileage_drawdown_credit),
            // exactly as the old usage line did. is_credit=1 + positive amount →
            // the aggregator subtracts (K-16 signed-aggregator convention).
            // ════════════════════════════════════════════════════════════════
            $mileageRateKm = (string) ($lease['mileage_rate_km'] ?? '0');
            $perDayKm      = (string) ($lease['estimated_mileage_per_day_km'] ?? '0');
            $rateActive    = bccomp($mileageRateKm, '0', 4) > 0;
            // The recurring ESTIMATE line only rides regular rental invoices.
            $estimateEmitAllowed = (
                $rateActive
                && $mileageMode !== 'off'
                && !in_array($billingType, ['mileage_only', 'adjustment', 'credit_note'], true)
            );
            // The TRUE-UP (and precharge drawdown) additionally runs on the
            // close-time `mileage_only` carrier invoice — that is precisely where an
            // advance-billed lease reconciles actual distance against the estimates
            // already charged on its prepaid rental invoices. Only pure adjustment /
            // credit_note utility invoices are excluded.
            $trueUpAllowed = (
                $rateActive
                && $mileageMode !== 'off'
                && !in_array($billingType, ['adjustment', 'credit_note'], true)
            );

            // D-B HARD guard (per-day arm): a per-day estimate with no rate is a
            // data hole — we cannot price the estimate line. Fires independent of
            // distance (the estimate bills even with no reading). create.php's
            // rate-completeness gate is the primary guard; this is the billing-time
            // net covering close.php / manual-create / cron paths.
            if ($billingType !== 'mileage_only'
                && bccomp($perDayKm, '0', 4) > 0
                && !$rateActive
            ) {
                throw new BillingRateException(
                    sprintf(
                        'InvoiceGenerator refused to bill estimated mileage: lease_id=%d, period=%s..%s (%d days), '
                        . 'estimated_mileage_per_day_km=%s configured but mileage_rate_km=%s. '
                        . 'Set a mileage rate at lease create (rate-tier completeness — see api/v1/leases/create.php).',
                        $leaseId, $periodStart, $periodEnd, $days,
                        $perDayKm, $mileageRateKm
                    ),
                    'mileage_excess', $days,
                    (string) ($lease['daily_rate']   ?? '0'),
                    (string) ($lease['weekly_rate']  ?? '0'),
                    (string) ($lease['monthly_rate'] ?? '0'),
                    [
                        'lease_id'                    => $leaseId,
                        'period_start'                => $periodStart,
                        'period_end'                  => $periodEnd,
                        'estimated_mileage_per_day_km'=> $perDayKm,
                        'mileage_rate_km'             => $mileageRateKm,
                        'billing_type'                => $billingType,
                    ]
                );
            }

            // D-B HARD throw (D133 preserved from Model C era) — rate-zero
            // with allowance-intent. Fires regardless of drawdown gate since
            // it's a data-hole guard.
            if ($periodDistanceKm !== null
                && $billingType !== 'mileage_only'
                && bccomp((string) ($lease['estimated_mileage_km'] ?? '0'), '0', 2) > 0
                && bccomp((string) ($lease['mileage_rate_km'] ?? '0'), '0', 4) <= 0
            ) {
                throw new BillingRateException(
                    sprintf(
                        'InvoiceGenerator refused to write mileage line: lease_id=%d, period=%s..%s (%d days), '
                        . 'estimated_mileage_km=%s configured (period_distance_km=%s) but mileage_rate_km=%s. '
                        . 'Upstream rate-tier-completeness invariant (D133) must be enforced at lease create — see api/v1/leases/create.php.',
                        $leaseId, $periodStart, $periodEnd, $days,
                        (string) ($lease['estimated_mileage_km'] ?? '0'),
                        (string) $periodDistanceKm,
                        (string) ($lease['mileage_rate_km'] ?? '0')
                    ),
                    'mileage_excess', $days,
                    (string) ($lease['daily_rate']   ?? '0'),
                    (string) ($lease['weekly_rate']  ?? '0'),
                    (string) ($lease['monthly_rate'] ?? '0'),
                    [
                        'lease_id'             => $leaseId,
                        'period_start'         => $periodStart,
                        'period_end'           => $periodEnd,
                        'estimated_mileage_km' => (string) ($lease['estimated_mileage_km'] ?? '0'),
                        'mileage_rate_km'      => (string) ($lease['mileage_rate_km'] ?? '0'),
                        'period_distance_km'   => (string) $periodDistanceKm,
                        'billing_type'         => $billingType,
                    ]
                );
            }

            // D-C SOFT WARNING (D133 preserved) — distance>0 but no rate tier
            // configured at all. Sentry::captureMessage 'warning' + audit_log
            // row. Drawdown gate doesn't fire (no rate); warning surfaces the
            // misconfiguration without blocking the invoice.
            if ($periodDistanceKm !== null
                && $billingType !== 'mileage_only'
                && bccomp((string) $periodDistanceKm, '0', 2) > 0
                && bccomp((string) ($lease['estimated_mileage_km'] ?? '0'), '0', 2) <= 0
                && bccomp((string) ($lease['mileage_rate_km'] ?? '0'), '0', 4) <= 0
            ) {
                $warningMsg = sprintf(
                    'Lease #%d period %s..%s has period_distance_km=%s but mileage block skipped (no rate tier configured). Possible misconfiguration — verify lease intent.',
                    $leaseId, $periodStart, $periodEnd, (string) $periodDistanceKm
                );
                try {
                    \FleetForge\Observability\Sentry::captureMessage($warningMsg, 'warning');
                } catch (\Throwable) {
                    // Observability MUST NOT block the billing path
                }
                db_insert('audit_log', [
                    'user_id'      => $params['created_by'] ?? null,
                    'user_name'    => (function_exists('current_user') && current_user())
                                        ? (current_user()['name'] ?? 'system') : 'system',
                    'action'       => 'update',
                    'module'       => 'billing',
                    'entity_type'  => 'lease',
                    'entity_id'    => $leaseId,
                    'entity_label' => $lease['contract_number'] ?? null,
                    'notes'        => '[FLEETFORGE_BILLING_WARNING] ' . $warningMsg,
                    'old_values'   => null,
                    'new_values'   => json_encode([
                        'period_distance_km'   => (string) $periodDistanceKm,
                        'estimated_mileage_km' => (string) ($lease['estimated_mileage_km'] ?? '0'),
                        'mileage_rate_km'      => (string) ($lease['mileage_rate_km'] ?? '0'),
                        'period_start'         => $periodStart,
                        'period_end'           => $periodEnd,
                    ]),
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
            }

            // Precharge Invoice-1 discriminator (unchanged from the Model B gate):
            // on Invoice 1 of a precharge lease the upfront precharge line owns the
            // mileage charge, so BOTH the estimate/true-up path and the legacy
            // usage path stand down until precharge_invoiced_at is stamped (send).
            $prechargeGatePasses = (
                ($lease['precharge_invoiced_at'] ?? null) !== null
                || (int) ($lease['precharge_enabled'] ?? 0) === 0
            );

            // Two mutually-exclusive mileage models, selected per lease:
            //   • per-day estimate > 0  → estimate + running true-up (S-MILEAGE-EST-DAILY)
            //   • per-day estimate == 0 → legacy actual-usage line (distance × rate),
            //                             preserving the pre-estimate behavior + its
            //                             readable "N unit × $rate/unit" line + drawdown.
            $drawdownAuditMeta = null;
            if ($trueUpAllowed && $prechargeGatePasses && bccomp($perDayKm, '0', 4) > 0) {
                $rateKm = $mileageRateKm;

                // ── (1) Estimate line: billable days × per-day × rate ─────────
                // Computed from DISPLAY values (miles × $/mile for a miles lease,
                // km × $/km for a km lease) so quantity × unit_price == amount (the
                // QBO line invariant). $estAmount feeds the net-charge / drawdown
                // math below and the running true-up target. Skipped on the
                // `mileage_only` carrier (estimate already billed on rental invoices).
                $estAmount = '0.00';
                if ($estimateEmitAllowed && bccomp($perDayKm, '0', 4) > 0 && $days > 0) {
                    $estDistanceKm = bcmul($perDayKm, (string) $days, 4);
                    $eDisp     = ff_mileage_line_display($lease, $estDistanceKm, $rateKm);
                    $estAmount = bcround(bcmul((string) $eDisp['distance'], (string) $eDisp['rate'], 6), 2);
                    if (bccomp($estAmount, '0', 2) > 0) {
                        // Per-day value in the display unit (exact: total ÷ days).
                        $perDayDisp = bcdiv((string) $eDisp['distance'], (string) $days, 2);
                        $lineItems[] = [
                            'sort_order'       => $sortOrder++,
                            'item_type'        => 'mileage_estimate',
                            'description'      => "Estimated mileage: {$days} days × " . rtrim(rtrim($perDayDisp, '0'), '.') . " {$eDisp['unit']}/day × \$" . $eDisp['rate'] . "/{$eDisp['rate_unit']}",
                            'quantity'         => sprintf('%s', $eDisp['distance']),
                            'unit'             => $eDisp['unit'],
                            'unit_price'       => $eDisp['rate'],
                            'amount'           => $estAmount,
                            'is_credit'        => 0,
                            'taxable'          => 1,
                            'mileage_distance' => (string) $eDisp['distance'],
                            'mileage_rate'     => $eDisp['rate'],
                            'mileage_unit'     => $eDisp['unit'],
                            'period_start'     => $periodStart,
                            'period_end'       => $periodEnd,
                        ];
                    } else {
                        $estAmount = '0.00';
                    }
                }

                // ── (2) Running cumulative true-up against actual ─────────────
                // Cumulative actual distance (km) through this period end:
                //   • odometer-authoritative when a closing reading is present
                //     ($cumulativeDistanceKm = odoEnd − lease.odometer_start_km,
                //     computed in the odometer block above), else
                //   • sum of prior recorded period distances + this period
                //     (Samsara/operator per-period), else
                //   • null → no reading yet; only the estimate bills this period.
                $cumActualKm = null;
                if (isset($params['cumulative_actual_km'])
                    && $params['cumulative_actual_km'] !== ''
                    && $params['cumulative_actual_km'] !== null) {
                    // Caller-supplied lifetime distance (close.php: a manual estimate
                    // lease's mileage_at_end, converted to km). Top priority — it is
                    // the operator's authoritative closing reading.
                    $ovr = (string) $params['cumulative_actual_km'];
                    $cumActualKm = bccomp($ovr, '0', 4) >= 0 ? $ovr : '0';
                } elseif ($cumulativeDistanceKm !== null) {
                    $cumActualKm = (string) $cumulativeDistanceKm;
                } elseif ($periodDistanceKm !== null) {
                    $priorDist = db_row(
                        "SELECT COALESCE(SUM(period_distance_km), 0) AS s
                           FROM invoices
                          WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void'
                            AND period_distance_km IS NOT NULL",
                        [$leaseId]
                    );
                    $cumActualKm = bcadd((string) ($priorDist['s'] ?? '0'), (string) $periodDistanceKm, 4);
                }

                // Net signed mileage charge on THIS invoice (estimate ± true-up).
                // Drives the precharge drawdown below.
                $netMileageCharge = $estAmount;

                if ($cumActualKm !== null) {
                    // Everything billed for mileage so far (prior non-void invoices),
                    // signed: credits subtract. Legacy mileage_usage / mileage lines
                    // are included so an in-flight lease trues up continuously across
                    // the cutover. Precharge/drawdown are excluded — a prepayment, not
                    // mileage revenue being reconciled to actual.
                    $billedRow = db_row(
                        "SELECT COALESCE(SUM(CASE WHEN li.is_credit = 1 THEN -li.amount ELSE li.amount END), 0) AS billed
                           FROM invoice_line_items li
                           JOIN invoices i ON i.id = li.invoice_id
                          WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
                            AND li.item_type IN ('mileage_estimate','mileage_adjustment','mileage_credit','mileage_usage','mileage')",
                        [$leaseId]
                    );
                    // Include this invoice's estimate line (not yet persisted).
                    $billedToDate = bcadd((string) ($billedRow['billed'] ?? '0'), $estAmount, 2);

                    // Target = cumulative actual × rate, in the lease's unit.
                    $aDisp           = ff_mileage_line_display($lease, $cumActualKm, $rateKm);
                    $cumActualCharge = bcround(bcmul((string) $aDisp['distance'], (string) $aDisp['rate'], 6), 2);
                    $trueUp          = bcsub($cumActualCharge, $billedToDate, 2);

                    if (bccomp($trueUp, '0', 2) > 0) {
                        // Under-billed vs actual → additional charge.
                        $lineItems[] = [
                            'sort_order'   => $sortOrder++,
                            'item_type'    => 'mileage_adjustment',
                            'description'  => 'Mileage true-up: ' . number_format((float) $aDisp['distance'], 2) . " {$aDisp['unit']} actual to date × \$" . $aDisp['rate'] . "/{$aDisp['rate_unit']} — additional charge vs estimated",
                            'quantity'     => '1.0000',
                            'unit'         => 'adjustment',
                            'unit_price'   => $trueUp,
                            'amount'       => $trueUp,
                            'is_credit'    => 0,
                            'taxable'      => 1,
                            'period_start' => $periodStart,
                            'period_end'   => $periodEnd,
                        ];
                        $netMileageCharge = bcadd($netMileageCharge, $trueUp, 2);
                    } elseif (bccomp($trueUp, '0', 2) < 0) {
                        // Over-billed vs actual → credit back the difference.
                        $creditAmt = bcsub('0', $trueUp, 2);
                        $lineItems[] = [
                            'sort_order'   => $sortOrder++,
                            'item_type'    => 'mileage_credit',
                            'description'  => 'Mileage true-up credit: ' . number_format((float) $aDisp['distance'], 2) . " {$aDisp['unit']} actual to date — refund of over-estimated mileage",
                            'quantity'     => '1.0000',
                            'unit'         => 'adjustment',
                            'unit_price'   => $creditAmt,
                            'amount'       => $creditAmt,
                            'is_credit'    => 1,  // POSITIVE amount + is_credit=1 → aggregator subtracts
                            'taxable'      => 1,
                            'period_start' => $periodStart,
                            'period_end'   => $periodEnd,
                        ];
                        $netMileageCharge = bcsub($netMileageCharge, $creditAmt, 2);
                    }
                }

                // ── (3) Precharge drawdown (Model B, opt-in) ──────────────────
                // Any positive NET mileage charge on this invoice draws down the
                // prepaid precharge_balance. Gated to invoice 2+ (precharge_invoiced_at
                // set at Invoice 1 send) so the upfront precharge line owns Invoice 1.
                if (bccomp($netMileageCharge, '0', 2) > 0
                    && (int) ($lease['precharge_enabled'] ?? 0) === 1
                    && ($lease['precharge_invoiced_at'] ?? null) !== null
                ) {
                    // FOR UPDATE re-read of precharge_balance per D20.
                    $leaseLocked = db_row(
                        "SELECT id, precharge_balance
                           FROM leases WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                        [$leaseId]
                    );
                    $preBalance = $leaseLocked['precharge_balance'] !== null
                        ? (string) $leaseLocked['precharge_balance']
                        : '0.00';

                    if (bccomp($preBalance, '0', 2) > 0) {
                        $drawdownAmt = bccomp($netMileageCharge, $preBalance, 2) <= 0
                            ? $netMileageCharge
                            : $preBalance;
                        $postBalance = bcsub($preBalance, $drawdownAmt, 2);

                        $lineItems[] = [
                            'sort_order'  => $sortOrder++,
                            'item_type'   => 'mileage_drawdown_credit',
                            'description' => "Precharge credit applied: drawdown of \${$drawdownAmt} from precharge balance",
                            'quantity'    => '1.0000',
                            'unit'        => 'drawdown',
                            'unit_price'  => $drawdownAmt,
                            'amount'      => $drawdownAmt,
                            'is_credit'   => 1,
                            'taxable'     => 1,
                        ];

                        db_execute(
                            "UPDATE leases SET precharge_balance = ?, updated_at = NOW(), updated_by = ?
                               WHERE id = ?",
                            [$postBalance, $params['created_by'] ?? null, $leaseId]
                        );

                        $drawdownAuditMeta = [
                            'pre_balance'        => $preBalance,
                            'drawdown_amount'    => $drawdownAmt,
                            'post_balance'       => $postBalance,
                            'net_mileage_charge' => $netMileageCharge,
                            'period_distance_km' => $periodDistanceKm !== null ? (string) $periodDistanceKm : null,
                            'mileage_rate_km'    => $rateKm,
                        ];
                    }
                }
            } elseif ($estimateEmitAllowed && $prechargeGatePasses
                && $periodDistanceKm !== null
                && bccomp((string) $periodDistanceKm, '0', 2) > 0
            ) {
                // ═══ Legacy actual per-period usage (per-day estimate == 0) ═══
                // Restores the pre-S-MILEAGE-EST-DAILY behavior for leases that
                // bill raw actual distance each period: one mileage_usage line
                // (distance × rate, fully readable) + precharge drawdown against it.
                // This is the unchanged path for every lease that hasn't opted into
                // the estimated-daily model. Never runs on the mileage_only carrier
                // (excluded by $estimateEmitAllowed) — that close path bills via the
                // legacy overage / bridge extra-lines instead.
                $rateKm = $mileageRateKm;
                $mDisp  = ff_mileage_line_display($lease, (string) $periodDistanceKm, $rateKm);
                $periodCharge = bcround(bcmul((string) $mDisp['distance'], (string) $mDisp['rate'], 6), 2);

                $leaseLocked = db_row(
                    "SELECT id, precharge_balance FROM leases WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                    [$leaseId]
                );
                $preBalance = $leaseLocked['precharge_balance'] !== null
                    ? (string) $leaseLocked['precharge_balance'] : '0.00';

                $lineItems[] = [
                    'sort_order'       => $sortOrder++,
                    'item_type'        => 'mileage_usage',
                    'description'      => "Mileage usage: " . number_format((float) $mDisp['distance'], 2) . " {$mDisp['unit']} × \$" . $mDisp['rate'] . "/{$mDisp['rate_unit']}",
                    'quantity'         => sprintf('%s', $mDisp['distance']),
                    'unit'             => $mDisp['unit'],
                    'unit_price'       => $mDisp['rate'],
                    'amount'           => $periodCharge,
                    'is_credit'        => 0,
                    'taxable'          => 1,
                    'mileage_distance' => (string) $mDisp['distance'],
                    'mileage_rate'     => $mDisp['rate'],
                    'mileage_unit'     => $mDisp['unit'],
                    'period_start'     => $periodStart,
                    'period_end'       => $periodEnd,
                ];

                if (bccomp($preBalance, '0', 2) > 0) {
                    $drawdownAmt = bccomp($periodCharge, $preBalance, 2) <= 0 ? $periodCharge : $preBalance;
                    $postBalance = bcsub($preBalance, $drawdownAmt, 2);
                    $lineItems[] = [
                        'sort_order'  => $sortOrder++,
                        'item_type'   => 'mileage_drawdown_credit',
                        'description' => "Precharge credit applied: drawdown of \${$drawdownAmt} from precharge balance",
                        'quantity'    => '1.0000',
                        'unit'        => 'drawdown',
                        'unit_price'  => $drawdownAmt,
                        'amount'      => $drawdownAmt,
                        'is_credit'   => 1,
                        'taxable'     => 1,
                    ];
                    db_execute(
                        "UPDATE leases SET precharge_balance = ?, updated_at = NOW(), updated_by = ? WHERE id = ?",
                        [$postBalance, $params['created_by'] ?? null, $leaseId]
                    );
                    $drawdownAuditMeta = [
                        'pre_balance'        => $preBalance,
                        'drawdown_amount'    => $drawdownAmt,
                        'post_balance'       => $postBalance,
                        'net_mileage_charge' => $periodCharge,
                        'period_distance_km' => (string) $periodDistanceKm,
                        'mileage_rate_km'    => $rateKm,
                    ];
                }
            }

            // --- Step 2b: Engine/reefer-hours usage (S-LEASE-HOURLY-BILLING) ---
            // Gate: lease has an hourly rate AND positive period hours AND this
            // is a normal billing run. Independent of mileage — a lease may bill
            // both. Plain usage line (no precharge/drawdown); rate_method omitted
            // like mileage_usage to avoid the rate_method ENUM clamp trap.
            if (bccomp((string) ($lease['hourly_rate'] ?? '0'), '0', 4) > 0
                && $periodEngineHours !== null
                && bccomp((string) $periodEngineHours, '0', 2) > 0
                && !in_array($billingType, ['mileage_only', 'adjustment', 'credit_note'], true)
            ) {
                $rateHr      = (string) $lease['hourly_rate'];
                $hoursCharge = bcround(bcmul((string) $periodEngineHours, $rateHr, 6), 2);
                $lineItems[] = [
                    'sort_order'   => $sortOrder++,
                    'item_type'    => 'hourly_usage',
                    'description'  => 'Engine hours: ' . number_format((float) $periodEngineHours, 2)
                                      . ' hrs × $' . $rateHr . '/hr',
                    'quantity'     => sprintf('%s', $periodEngineHours),
                    'unit'         => 'hours',
                    'unit_price'   => $rateHr,
                    'amount'       => $hoursCharge,
                    'is_credit'    => 0,
                    'taxable'      => 1,
                    'period_start' => $periodStart,
                    'period_end'   => $periodEnd,
                ];
            }

            // --- Step 3: Insurance add-on ---
            // S-LEASE-CLOSE-ONE-INVOICE: exclude 'adjustment'/'credit_note' too (not
            // just mileage_only) — a close-out adjustment invoice is base-rental/add-on
            // free by design; it must never carry a flat insurance/warranty/GPS charge.
            if (!in_array($billingType, ['mileage_only', 'adjustment', 'credit_note'], true)
                && $lease['insurance_opt_in']
                && bccomp((string)$lease['insurance_cost'], '0', 2) > 0) {
                $lineItems[] = [
                    'sort_order'  => $sortOrder++,
                    'item_type'   => 'insurance',
                    'description' => 'Insurance coverage',
                    'quantity'    => '1.0000',
                    'unit_price'  => (string)$lease['insurance_cost'],
                    'amount'      => (string)$lease['insurance_cost'],
                    'is_credit'   => 0,
                    'taxable'     => 1,
                ];
            }

            // --- Step 3: Warranty add-on ---
            if (!in_array($billingType, ['mileage_only', 'adjustment', 'credit_note'], true)
                && $lease['warranty_opt_in']
                && bccomp((string)$lease['warranty_cost'], '0', 2) > 0) {
                $lineItems[] = [
                    'sort_order'  => $sortOrder++,
                    'item_type'   => 'warranty',
                    'description' => 'Warranty coverage',
                    'quantity'    => '1.0000',
                    'unit_price'  => (string)$lease['warranty_cost'],
                    'amount'      => (string)$lease['warranty_cost'],
                    'is_credit'   => 0,
                    'taxable'     => 1,
                ];
            }

            // --- Step 3: GPS tracking add-on (S-LEASE-GPS-COST) ---
            // Per-day billing — amount = gps_cost × $days. Diverges from
            // insurance/warranty (flat-per-period) because GPS is metered
            // service. S-LEASE-CLOSE-ONE-INVOICE: skipped on mileage_only AND
            // adjustment/credit_note — a close-out adjustment invoice (period =
            // actual return date) must not bill a per-day GPS charge for the
            // time-trimmed return day the rental itself doesn't charge.
            if (!in_array($billingType, ['mileage_only', 'adjustment', 'credit_note'], true)
                && $lease['gps_opt_in']
                && bccomp((string)$lease['gps_cost'], '0', 2) > 0) {
                $gpsAmount = bcmul((string)$lease['gps_cost'], (string)$days, 2);
                $lineItems[] = [
                    'sort_order'   => $sortOrder++,
                    'item_type'    => 'gps',
                    'description'  => "GPS tracking: {$periodStart} to {$periodEnd} ({$days} days)",
                    'quantity'     => sprintf('%d.0000', $days),
                    'unit'         => 'day',
                    'unit_price'   => (string)$lease['gps_cost'],
                    'amount'       => $gpsAmount,
                    'is_credit'    => 0,
                    'taxable'      => 1,
                    'billing_days' => $days,
                    'period_start' => $periodStart,
                    'period_end'   => $periodEnd,
                ];
            }

            // --- Extra manual line items (if provided) ---
            if (!empty($params['extra_lines']) && is_array($params['extra_lines'])) {
                foreach ($params['extra_lines'] as $line) {
                    $lineItems[] = [
                        'sort_order'  => $sortOrder++,
                        'item_type'   => $line['item_type'] ?? 'other',
                        'description' => $line['description'] ?? 'Manual adjustment',
                        'quantity'    => $line['quantity'] ?? '1.0000',
                        'unit'        => $line['unit'] ?? null,
                        'unit_price'  => $line['unit_price'] ?? '0.00',
                        'amount'      => $line['amount'] ?? '0.00',
                        'is_credit'   => (int)($line['is_credit'] ?? 0),
                        'taxable'     => (int)($line['taxable'] ?? 1),
                    ];
                }
            }

            // --- Step 4b: Cartage — one-time delivery charge (S-LEASE-SERVICE-CHARGES) ---
            // Entered at lease creation; bills exactly ONCE — on the first invoice
            // that actually STICKS. Excluded from mileage_only/adjustment/credit_note.
            //
            // LIVE-AWARE guard (S-ODO/charges fix): emit only when NO non-void invoice
            // already carries a cartage line — NOT a one-shot `cartage_billed_at` flag.
            // The flag left cartage stranded when its first invoice was voided +
            // reissued at close (overshoot reconciliation: adv_void_invoice() then a
            // fresh createFromLease) — the flag stayed set so cartage never re-billed
            // and the customer was silently undercharged (dev e2e: lease returned
            // within its first billing period). The FOR UPDATE on the lease row still
            // serializes concurrent generation (advance batch / parallel request) so a
            // live cartage line from an in-flight same-txn segment is seen before this
            // one emits — no double-bill. cartage_billed_at is still stamped (drives the
            // show-page badge + the edit-lock), but it no longer gates the emit.
            if (bccomp((string) ($lease['cartage_amount'] ?? '0'), '0', 2) > 0
                && !in_array($billingType, ['mileage_only', 'adjustment', 'credit_note'], true)
            ) {
                $cartLocked = db_row(
                    "SELECT cartage_amount FROM leases
                       WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                    [$leaseId]
                );
                $cartLive = db_row(
                    "SELECT 1 FROM invoice_line_items li
                       JOIN invoices i ON i.id = li.invoice_id
                      WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
                        AND li.item_type = 'cartage' LIMIT 1",
                    [$leaseId]
                );
                if ($cartLocked
                    && $cartLive === null
                    && bccomp((string) ($cartLocked['cartage_amount'] ?? '0'), '0', 2) > 0
                ) {
                    $cartAmt = (string) $cartLocked['cartage_amount'];
                    $lineItems[] = [
                        'sort_order'  => $sortOrder++,
                        'item_type'   => 'cartage',
                        'description' => 'Cartage (delivery)',
                        'quantity'    => '1.0000',
                        'unit'        => null,
                        'unit_price'  => $cartAmt,
                        'amount'      => $cartAmt,
                        'is_credit'   => 0,
                        'taxable'     => 1,
                    ];
                    db_execute(
                        "UPDATE leases SET cartage_billed_at = NOW(), updated_at = NOW(), updated_by = ?
                           WHERE id = ?",
                        [$params['created_by'] ?? null, $leaseId]
                    );
                }
            }

            // --- Step 5: Subtotal = SUM(line_items.amount), credits subtract ---
            $subtotal = '0.00';
            foreach ($lineItems as $item) {
                if ($item['is_credit']) {
                    $subtotal = bcsub($subtotal, $item['amount'], 2);
                } else {
                    $subtotal = bcadd($subtotal, $item['amount'], 2);
                }
            }

            // --- S-FIX-2 Bug #3 + S-BILLING-HOLISTIC-ENGINE: credit overflow cap ---
            // If the credit lines drive subtotal below $0, cap the first
            // cappable credit line (mileage_credit OR
            // base_rental_reconciliation_credit) so subtotal floors at $0
            // and route the remainder to a credit_notes row. Source:
            //   mileage_credit                       → 'mileage_overpayment'
            //   base_rental_reconciliation_credit    → 'base_rental_reconciliation_overflow'
            //
            // The credit_note is created AFTER the invoice INSERT below;
            // we capture the overflow + source here so the invoice rows
            // reflect the cap.
            //
            // S-BILLING-HOLISTIC-ENGINE extension: the holistic engine
            // can emit a base_rental_reconciliation_credit larger than
            // the rest of the invoice subtotal (e.g. when a single
            // big-tier-drop reconciliation lands on a low-add-on invoice).
            // The cap mechanism is identical to the mileage_credit path —
            // only the credit_notes.source differs so reports can
            // distinguish the two overflow sources.
            $mileageOverflow         = '0.00';   // legacy name retained for blast-radius minimality
            $mileageOverflowSource   = null;     // null | 'mileage_overpayment' | 'base_rental_reconciliation_overflow'
            if (bccomp($subtotal, '0', 2) < 0) {
                $mileageOverflow = bcmul($subtotal, '-1', 2); // abs($subtotal)

                // Cappable types in priority order. mileage_credit goes
                // first to preserve the pre-S-BILLING-HOLISTIC-ENGINE
                // behaviour byte-for-byte when both credit types are
                // present on the same invoice (vanishingly rare in
                // practice — the holistic engine emits a reconciliation
                // credit on an invoice that already carries a mileage
                // credit only in a contrived test scenario).
                $cappableTypes = ['mileage_credit', 'base_rental_reconciliation_credit'];

                $capApplied = false;
                foreach ($cappableTypes as $cappableType) {
                    foreach ($lineItems as &$capItem) {
                        if (($capItem['item_type'] ?? '') === $cappableType
                            && !empty($capItem['is_credit'])) {
                            $originalCredit = (string) $capItem['amount'];
                            $newCredit      = bcsub($originalCredit, $mileageOverflow, 2);
                            if (bccomp($newCredit, '0', 2) < 0) {
                                // The overflow exceeds this single line — not expected,
                                // but defensively cap to 0 and surface the residual.
                                $newCredit = '0.00';
                            }
                            $capItem['amount'] = $newCredit;

                            $detail = isset($capItem['detail_lines'])
                                ? json_decode((string) $capItem['detail_lines'], true)
                                : [];
                            if (!is_array($detail)) {
                                $detail = ['note' => (string) ($capItem['detail_lines'] ?? '')];
                            }
                            $detail['cap_applied']                    = true;
                            $detail['original_credit_amount']         = $originalCredit;
                            $detail['capped_to']                      = $newCredit;
                            $detail['overflow_routed_to_credit_note'] = $mileageOverflow;
                            $capItem['detail_lines'] = json_encode($detail);

                            $mileageOverflowSource = $cappableType === 'mileage_credit'
                                ? 'mileage_overpayment'
                                : 'base_rental_reconciliation_overflow';

                            $capApplied = true;
                            break 2;  // exit both loops
                        }
                    }
                    unset($capItem);
                }

                if (!$capApplied) {
                    // No mileage_credit or base_rental_reconciliation_credit
                    // line is present, yet the subtotal went negative. That
                    // means a different is_credit line (manual, goodwill,
                    // etc.) caused it — refuse rather than silently
                    // rewriting a deliberate adjustment. Caller should split.
                    json_error(
                        'VALIDATION_ERROR',
                        'Invoice subtotal is negative but no mileage_credit or base_rental_reconciliation_credit line is present to cap. Reduce other credit line items or split into a separate credit_note.',
                        422
                    );
                }

                // Recompute subtotal after the cap (should be exactly 0.00).
                $subtotal = '0.00';
                foreach ($lineItems as $item) {
                    if ($item['is_credit']) {
                        $subtotal = bcsub($subtotal, $item['amount'], 2);
                    } else {
                        $subtotal = bcadd($subtotal, $item['amount'], 2);
                    }
                }
            }

            // --- Step 6: Apply discount ---
            $discountType = $lease['discount_type'] ?? 'none';
            $discountValue = (string)($lease['discount_value'] ?? '0.0000');
            $discountAmount = '0.00';

            if ($discountType === 'percentage' && bccomp($discountValue, '0', 4) > 0) {
                $discountDecimal = bcdiv($discountValue, '100', 6);
                $discountAmount = bcround(bcmul($subtotal, $discountDecimal, 6), 2);
            } elseif ($discountType === 'flat' && bccomp($discountValue, '0', 4) > 0) {
                $discountAmount = bcround($discountValue, 2);
            }

            $subtotalAfterDiscount = bcsub($subtotal, $discountAmount, 2);

            // --- Step 7: Tax (D11: looked up at invoice time, D22: granular exemptions) ---
            $province = $lease['province'] ?? 'BC';
            $gstExempt = (bool)$lease['gst_exempt'];
            $pstExempt = (bool)$lease['pst_exempt'];

            // Legacy tax_exempt flag: if true, treat as both exempt
            if ($lease['tax_exempt']) {
                $gstExempt = true;
                $pstExempt = true;
            }

            // S-FIX-2 Bug #5: re-evaluate exemption status against expiry dates.
            // If the customer's exemption certificate has expired by invoice_date,
            // demote the exemption on THIS invoice only — the lease record stays
            // unchanged, so historical invoices keep their original tax treatment.
            // Audit log the demotion so accountants can see why a previously
            // exempt customer started getting taxed.
            // S-FIX-2 Bug #5 ordering fix: check exemption-certificate expiry
            // against THIS invoice's own date, not "today". $invoiceDate is
            // assigned further down (= $periodStart, the invoice_date for the
            // period), so referencing it here silently fell back to date('Y-m-d')
            // and evaluated expiry against the generation day. That over-taxed
            // backdated invoices (a cert valid during the past period but expired
            // by today was wrongly demoted) and under-taxed advance invoices (a
            // cert expiring before a future period was kept). Use $periodStart —
            // the canonical invoice date — to mirror lease-create's start_date
            // expiry check and keep historical/advance invoices CRA-correct.
            $expiryCheckDate = $periodStart;
            $expiryAuditEntries = [];

            if ($gstExempt && !empty($lease['customer_gst_exempt_expiry'])
                && (string) $lease['customer_gst_exempt_expiry'] < $expiryCheckDate) {
                $expiryAuditEntries[] = [
                    'tax'    => 'GST',
                    'expiry' => (string) $lease['customer_gst_exempt_expiry'],
                ];
                $gstExempt = false;
            }
            if ($pstExempt && !empty($lease['customer_pst_exempt_expiry'])
                && (string) $lease['customer_pst_exempt_expiry'] < $expiryCheckDate) {
                $expiryAuditEntries[] = [
                    'tax'    => 'PST',
                    'expiry' => (string) $lease['customer_pst_exempt_expiry'],
                ];
                $pstExempt = false;
            }

            // Only tax the taxable portion of the subtotal after discount
            $taxableSubtotal = $subtotalAfterDiscount;
            $tax = $this->taxCalc->calculate($taxableSubtotal, $province, $gstExempt, $pstExempt);

            // --- Step 8: Total ---
            $totalAmount = bcadd($subtotalAfterDiscount, $tax['total'], 2);

            // --- Step 9: Balance due (no payments or credits on new invoice) ---
            $balanceDue = $totalAmount;

            // --- Generate invoice number (D15: gap-free, D20: FOR UPDATE) ---
            // S-INVOICE-DRAFT-EDIT regenerate: when re-creating a draft in place
            // (api/v1/invoices/regenerate.php hard-deletes the old draft + its
            // number, then calls this), reuse the SAME number instead of minting
            // a new one — no counter bump, no gap. Only ever set for that path.
            $invoiceNumber = isset($params['force_invoice_number']) && $params['force_invoice_number'] !== ''
                ? (string) $params['force_invoice_number']
                : $this->generateInvoiceNumber();

            // S-INVOICE-DATING-FIX: issue/due derive from THIS invoice's own
            // billing period, NOT the generation timestamp. Advance-billing
            // cadence (lease.next_billing_date = period start; cron bills
            // [Y-m-01, Y-m-t] at period start) → issue_date = billing_period_start.
            // due_date = issue_date + payment-terms net days
            // (settings.invoice.due_days_default; customers.payment_terms is a
            // free-text field and intentionally NOT used here). created_at (DB
            // CURRENT_TIMESTAMP) stays the real generation time. This makes each
            // fanned-out segment carry its own period-appropriate dates, and the
            // GL/AR posting follows the period via D-GL-REVREC-1 (AutoEntryBridge).
            $dueDays     = (int)(settings_get('invoice.due_days_default', '30') ?? 30);
            $invoiceDate = $periodStart;
            $dueDate     = (new \DateTimeImmutable($periodStart))
                               ->modify("+{$dueDays} days")->format('Y-m-d');

            // Exchange rate for USD invoices — exchange_rates has no is_active/effective_date
            // columns, just from_currency/to_currency/rate/rate_date. Pull the latest rate by
            // rate_date DESC. If no row exists, leave exchange rate null (invoice still bills
            // in USD but has no CAD conversion snapshot).
            $exchangeRate = null;
            // CURRENCY-MARKUP-1: markup % frozen at creation — same immutability as bank rate.
            // Changing the setting later does NOT affect existing invoices (CRA defensibility).
            $markupPct = '0.0000';
            if ($lease['currency'] === 'USD') {
                $fxRow = db_row(
                    "SELECT rate FROM exchange_rates WHERE from_currency = 'USD' AND to_currency = 'CAD' ORDER BY rate_date DESC LIMIT 1",
                    []
                );
                $exchangeRate = $fxRow ? $fxRow['rate'] : null;
                $markupPct = (string) (settings_get('currency.usd_cad_markup_pct', '0.0000') ?? '0.0000');
            }

            // --- Insert invoice ---
            // S-MILEAGE-2B C4: Model C invoice-row columns DROPPED in
            // 202605120907_S-MILEAGE-2B_model_c_retirement.sql. excess_distance_km,
            // excess_charge_amount, mileage_review_status, mileage_override_amount,
            // mileage_reviewed_at, mileage_reviewed_by_user_id, mileage_review_notes
            // no longer exist on the invoices table. Removed from this INSERT
            // tuple at the same time as the migration.
            $invoiceId = db_insert('invoices', [
                'invoice_number'            => $invoiceNumber,
                'invoice_type'              => $invoiceType,
                'customer_id'               => $lease['customer_id'],
                'lease_id'                  => $leaseId,
                'customer_name_snapshot'     => $lease['customer_name_snapshot'],
                'company_name_snapshot'      => $lease['company_name_snapshot'],
                'contract_number_snapshot'   => $lease['contract_number'],
                'unit_number_invoice_snapshot' => $lease['unit_number_snapshot'],
                'billing_address_snapshot'   => $lease['billing_address'] ?? null,
                'province_snapshot'          => $province,
                'customer_email_snapshot'    => $lease['customer_email'] ?? null,
                'gst_exempt_snapshot'        => (int)$gstExempt,
                'pst_exempt_snapshot'        => (int)$pstExempt,
                'tax_exempt_snapshot'        => (int)$lease['tax_exempt'],
                'tax_exempt_number_snapshot' => null,
                'gst_exempt_number_snapshot' => $gstExempt ? ($lease['customer_gst_exempt_number'] ?: null) : null,
                'pst_exempt_number_snapshot' => $pstExempt ? ($lease['customer_pst_exempt_number'] ?: null) : null,
                'po_number'                 => $params['po_number'] ?? $lease['po_number'] ?? null,
                'currency'                  => $lease['currency'],
                'exchange_rate_to_cad'       => $exchangeRate,
                'currency_markup_pct'        => $markupPct,
                'billing_period_start'      => $periodStart,
                'billing_period_end'        => $periodEnd,
                'billing_period_days'       => $days,
                'billing_type'              => $billingType,
                'rate_method_used'          => $rateMethod ?? 'none',
                'rate_method_explanation'    => json_encode($explanation ?? []),
                'invoice_date'              => $invoiceDate,
                'due_date'                  => $dueDate,
                'status'                    => 'draft',
                'subtotal'                  => $subtotal,
                'discount_type'             => $discountType,
                'discount_value'            => $discountValue,
                'discount_amount'           => $discountAmount,
                'subtotal_after_discount'   => $subtotalAfterDiscount,
                'tax_gst_rate'              => $tax['gst_rate'],
                'tax_pst_rate'              => $tax['pst_rate'],
                'tax_hst_rate'              => $tax['hst_rate'],
                'tax_gst_amount'            => $tax['gst'],
                'tax_pst_amount'            => $tax['pst'],
                'tax_hst_amount'            => $tax['hst'],
                'tax_total'                 => $tax['total'],
                'total_amount'              => $totalAmount,
                'amount_paid'               => '0.00',
                'credits_applied'           => '0.00',
                'balance_due'               => $balanceDue,
                'notes'                     => $params['notes'] ?? null,
                'internal_notes'            => $params['internal_notes'] ?? null,
                // SAMSARA-3: per-invoice odometer + distance tracking
                'odometer_at_period_start_km' => $odoStartKm,
                'odometer_at_period_end_km'   => $odoEndKm,
                'period_distance_km'          => $periodDistanceKm,
                'cumulative_distance_km'      => $cumulativeDistanceKm,
                'odometer_source'             => $odometerSource,
                'odometer_fetched_at'         => $odometerFetchedAt,
                // S-LEASE-HOURLY-BILLING: per-period engine-hours snapshot.
                'engine_hours_at_period_start' => $hoursStart,
                'engine_hours_at_period_end'   => $hoursEnd,
                'period_engine_hours'          => $periodEngineHours,
                // S-BILLING-HOLISTIC-ENGINE: forensic audit columns. NULL
                // for period_independent leases; populated for every holistic
                // invoice so a future auditor can replay the math without
                // rebuilding it from line items.
                'total_days_at_period_end'    => $holisticTotalDays,
                'cumulative_correct_amount'   => $holisticCumulativeCorrect,
                'already_billed_before_this'  => $holisticAlreadyBilled,
                'auto_generated'            => (int)($params['auto_generated'] ?? 0),
                'generation_source'         => $params['generation_source'] ?? 'manual',
                'created_by'                => $params['created_by'] ?? null,
                'updated_by'                => $params['created_by'] ?? null,  // FIX #43
            ]);

            // S-FIX-2 Bug #5: write audit_log entries for any exemption
            // demotions detected above. entity is the customer (the certificate
            // expired on the customer record, not the lease) so accountants
            // searching by customer see why their tax treatment changed.
            foreach ($expiryAuditEntries as $entry) {
                db_insert('audit_log', [
                    'user_id'      => $params['created_by'] ?? null,
                    'user_name'    => (function_exists('current_user') && current_user())
                                        ? (current_user()['name'] ?? 'system') : 'system',
                    'action'       => 'update',
                    'module'       => 'billing',
                    'entity_type'  => 'customer',
                    'entity_id'    => (int) ($lease['customer_row_id'] ?? $lease['customer_id']),
                    'entity_label' => $lease['customer_company_name'] ?? '',
                    'notes'        => "{$entry['tax']} exempt expired {$entry['expiry']} — invoice {$invoiceNumber} billed at full rate (S-FIX-2 Bug #5).",
                    'old_values'   => json_encode([
                        $entry['tax'] === 'GST' ? 'gst_exempt_snapshot' : 'pst_exempt_snapshot' => 1,
                    ]),
                    'new_values'   => json_encode([
                        $entry['tax'] === 'GST' ? 'gst_exempt_snapshot' : 'pst_exempt_snapshot' => 0,
                        'expiry_on_certificate' => $entry['expiry'],
                        'invoice_id' => $invoiceId,
                    ]),
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
            }

            // ── S-MILEAGE-2B C3: audit precharge balance drawdown ──────
            // Fires when Branch A of the drawdown emit ran (precharge_balance
            // > 0 → mileage_usage + mileage_drawdown_credit emitted + balance
            // decremented). new_values JSON carries pre/post balance + drawdown
            // amount + period charge + distance + rate for searchable per-event
            // trail. entity_type='lease_precharge_balance_drawdown' searchable
            // alongside the 2A entity_types 'lease_precharge_balance_init'
            // (activation) + 'lease_precharge_invoiced_at_stamp' (Invoice 1 send).
            // D102/D123 workaround: action='update' (action ENUM doesn't include
            // 'drawdown'; descriptive value carried in entity_type + notes).
            if ($drawdownAuditMeta !== null) {
                db_insert('audit_log', [
                    'user_id'      => $params['created_by'] ?? null,
                    'user_name'    => (function_exists('current_user') && current_user())
                                        ? (current_user()['name'] ?? 'system') : 'system',
                    'action'       => 'update',
                    'module'       => 'billing',
                    'entity_type'  => 'lease_precharge_balance_drawdown',
                    'entity_id'    => $leaseId,
                    'entity_label' => $lease['contract_number'] ?? null,
                    'notes'        => sprintf(
                        'Precharge balance drawdown on invoice %s: pre=$%s, drawdown=$%s, post=$%s (net_mileage_charge=$%s, period_distance=%s km, rate=$%s/km)',
                        $invoiceNumber,
                        $drawdownAuditMeta['pre_balance'],
                        $drawdownAuditMeta['drawdown_amount'],
                        $drawdownAuditMeta['post_balance'],
                        $drawdownAuditMeta['net_mileage_charge'],
                        $drawdownAuditMeta['period_distance_km'] ?? 'n/a',
                        $drawdownAuditMeta['mileage_rate_km']
                    ),
                    'old_values'   => json_encode([
                        'precharge_balance' => $drawdownAuditMeta['pre_balance'],
                    ]),
                    'new_values'   => json_encode([
                        'precharge_balance'  => $drawdownAuditMeta['post_balance'],
                        'drawdown_amount'    => $drawdownAuditMeta['drawdown_amount'],
                        'net_mileage_charge' => $drawdownAuditMeta['net_mileage_charge'],
                        'period_distance_km' => $drawdownAuditMeta['period_distance_km'],
                        'mileage_rate_km'    => $drawdownAuditMeta['mileage_rate_km'],
                        'invoice_id'         => $invoiceId,
                        'invoice_number'     => $invoiceNumber,
                    ]),
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
            }

            // ── S-BILLING-HOLISTIC-ENGINE: audit holistic engine ────────
            // Fires for every invoice generated by the holistic engine
            // (including delta=0 invoices that emit no line item — the
            // audit trail still records that the engine evaluated this
            // period). Spec §20.3 + Appendix C.
            //
            // entity_type='invoice_holistic_reconciliation' searchable
            // alongside the other invoice-related entity_types. action
            // ENUM doesn't include 'reconcile'; carry descriptive value
            // in entity_type + notes per D102/D123 pattern. Single row
            // per invoice — the JSON payload (audit_meta) carries the
            // full forensic trail.
            if ($holisticAuditMeta !== null) {
                $auditNotes = sprintf(
                    'Holistic engine: tier=%s, total_days=%d, cumulative_correct=$%s, already_billed=$%s, delta=$%s (line_item_type=%s, rule=%s)',
                    $holisticAuditMeta['tier'],
                    $holisticAuditMeta['total_days_so_far'],
                    $holisticAuditMeta['cumulative_correct'],
                    $holisticAuditMeta['already_billed'],
                    $holisticAuditMeta['delta'],
                    $holisticAuditMeta['line_item_type'],
                    $holisticAuditMeta['rule']
                );
                db_insert('audit_log', [
                    'user_id'      => $params['created_by'] ?? null,
                    'user_name'    => (function_exists('current_user') && current_user())
                                        ? (current_user()['name'] ?? 'system') : 'system',
                    'action'       => 'create',
                    'module'       => 'billing',
                    'entity_type'  => 'invoice_holistic_reconciliation',
                    'entity_id'    => $invoiceId,
                    'entity_label' => $invoiceNumber,
                    'notes'        => $auditNotes,
                    'new_values'   => json_encode(array_merge(
                        $holisticAuditMeta,
                        [
                            'invoice_id'     => $invoiceId,
                            'invoice_number' => $invoiceNumber,
                        ]
                    )),
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
            }

            // --- Insert line items ---
            foreach ($lineItems as $item) {
                // Calculate per-line tax for line items that are taxable
                $lineTax = ['gst' => '0.00', 'pst' => '0.00', 'hst' => '0.00'];
                if ($item['taxable'] ?? true) {
                    $lineAmount = $item['is_credit'] ? bcsub('0', $item['amount'], 2) : $item['amount'];
                    $lineTax = $this->taxCalc->calculate($lineAmount, $province, $gstExempt, $pstExempt);
                }

                db_insert('invoice_line_items', [
                    'invoice_id'   => $invoiceId,
                    'sort_order'   => $item['sort_order'],
                    'item_type'    => $item['item_type'],
                    'description'  => $item['description'],
                    'detail_lines' => $item['detail_lines'] ?? null,
                    'quantity'     => $item['quantity'] ?? '1.0000',
                    'unit'         => $item['unit'] ?? null,
                    'unit_price'   => $item['unit_price'] ?? '0.00',
                    'amount'       => $item['amount'],
                    'is_credit'    => $item['is_credit'] ?? 0,
                    'taxable'      => $item['taxable'] ?? 1,
                    'tax_gst_amount' => $lineTax['gst'],
                    'tax_pst_amount' => $lineTax['pst'],
                    'tax_hst_amount' => $lineTax['hst'],
                    'billing_days' => $item['billing_days'] ?? null,
                    'rate_method'  => $item['rate_method'] ?? null,
                    'period_start' => $item['period_start'] ?? null,
                    'period_end'   => $item['period_end'] ?? null,
                ]);
            }

            // --- Insert billing period record ---
            // lease_billing_periods.period_type is a NARROW enum of base-rental
            // period shapes: partial_start / full_month / partial_end / single_period.
            // createFromLease, however, also accepts adjustment-style billing types
            // (mileage_only — used by the lease-close advance path; adjustment;
            // credit_note) that carry NO base-rental period. Writing those raw into
            // period_type truncates under STRICT_TRANS_TABLES (1265 "Data truncated"),
            // which aborted the ENTIRE lease-close transaction — a hard fatal when
            // closing an advance lease that has a mileage overage line. Prod runs
            // STRICT_TRANS_TABLES too, so this affected production. Map any non-period
            // billing type to 'single_period' (these invoices cover a single point/
            // date) so the ledger row is still recorded without truncating.
            // NOTE: period_type is write-only in this codebase (nothing reads it for
            // logic), so the mapped value is a faithful-enough ledger label.
            $periodTypeForLedger = in_array(
                $billingType,
                ['partial_start', 'full_month', 'partial_end', 'single_period'],
                true
            ) ? $billingType : 'single_period';

            // lease_billing_periods.rate_method is enum('daily','weekly',
            // 'weekly_capped','monthly') — note it has NO 'none' member (unlike the
            // wider invoices.rate_method_used). mileage_only sets $rateMethod='none'
            // (no base rental), and the holistic engine maps a 'none' tier to 'none'
            // too; either would truncate this enum under STRICT_TRANS_TABLES and abort
            // the transaction. Map any out-of-enum value to 'daily' for the ledger row.
            $rateMethodForLedger = in_array(
                $rateMethod ?? '',
                ['daily', 'weekly', 'weekly_capped', 'monthly'],
                true
            ) ? $rateMethod : 'daily';

            db_insert('lease_billing_periods', [
                'lease_id'            => $leaseId,
                'invoice_id'          => $invoiceId,
                'period_start'        => $periodStart,
                'period_end'          => $periodEnd,
                'period_days'         => $days,
                'period_type'         => $periodTypeForLedger,
                'rate_method'         => $rateMethodForLedger,
                'rate_method_explanation' => json_encode($explanation ?? []),
                'base_amount'         => $rentalAmount,
                'discount_amount'     => $discountAmount,
                'tax_amount'          => $tax['total'],
                'total_amount'        => $totalAmount,
                'daily_rate_used'     => (string)$lease['daily_rate'],
                'weekly_rate_used'    => (string)$lease['weekly_rate'],
                'monthly_rate_used'   => (string)$lease['monthly_rate'],
                'currency'            => $lease['currency'],
                'status'              => 'pending',
            ]);

            // --- Update denormalized lease totals in same transaction (Trap 6) ---
            // S-FIX-2 Path B: total_invoiced INCLUDES drafts (lease-internal total).
            // outstanding_balance on the lease is being kept in sync with the customer
            // counter for now — both increment only at draft -> sent transition (send.php).
            // last_billed_date must track BILLING COVERAGE (period_end), not the
            // creation date. close.php derives the final period as last_billed_date
            // + 1 day (api/v1/leases/close.php), so a creation-date anchor caused it
            // to re-bill or skip days whenever an invoice was created off-cycle.
            // GREATEST(COALESCE(...)) keeps it MONOTONIC — an out-of-order / earlier
            // correction invoice can never drag the coverage anchor backwards.
            db_execute(
                "UPDATE leases
                    SET total_invoiced = total_invoiced + ?,
                        last_billed_invoice_id = CASE
                            WHEN ? >= COALESCE(last_billed_date, '1900-01-01')
                            THEN ? ELSE last_billed_invoice_id END,
                        last_billed_date = GREATEST(COALESCE(last_billed_date, '1900-01-01'), ?),
                        updated_at = NOW()
                  WHERE id = ?",
                [$totalAmount, $periodEnd, $invoiceId, $periodEnd, $leaseId]
            );

            // S-FIX-2 Path B: drafts do NOT touch outstanding_balance.
            // The increment fires in api/v1/invoices/send.php on draft -> sent.
            // (Historical comment "Trap 6 customer outstanding_balance" removed —
            //  the customer counter now follows Path B canonical truth.)

            // S-FIX-2 Bug #3 + S-BILLING-HOLISTIC-ENGINE: route the credit
            // overflow (if any) to a credit_notes row. The source is chosen
            // above by the cap loop:
            //   'mileage_overpayment'                  — mileage_credit cap
            //   'base_rental_reconciliation_overflow'  — holistic reconciliation cap
            //
            // Created after the invoice INSERT so source_invoice_id can
            // reference the new invoice. Same db_transaction → JE rolls
            // back if either fails. No tax: a customer credit liability
            // is not a billable line.
            if (bccomp($mileageOverflow ?? '0', '0', 2) > 0) {
                $cnYear   = date('Y');
                $cnKey    = "credit_note.next_number.{$cnYear}";
                $cnRow    = db_row("SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE", [$cnKey]);
                $cnNext   = $cnRow ? (int) $cnRow['value'] : 1;
                $cnPrefix = settings_get('credit_note.prefix', 'CN-CR');
                $cnNumber = sprintf('%s-%s-%05d', $cnPrefix, $cnYear, $cnNext);
                if ($cnRow) {
                    db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [$cnNext + 1, $cnKey]);
                } else {
                    db_execute(
                        "INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, 'credit_notes')",
                        [$cnKey, $cnNext + 1]
                    );
                }

                // Branch the human-readable reason on the source. Defaults
                // to the legacy mileage wording when source wasn't set
                // (paranoia — the cap loop always sets it when overflow > 0).
                $effectiveSource = $mileageOverflowSource ?? 'mileage_overpayment';
                $cnReason = $effectiveSource === 'base_rental_reconciliation_overflow'
                    ? "Base rental reconciliation credit exceeded invoice subtotal — overflow routed to account credit (S-BILLING-HOLISTIC-ENGINE, invoice {$invoiceNumber})."
                    : "Mileage credit exceeded final invoice subtotal — overflow routed to account credit (S-FIX-2 Bug #3, invoice {$invoiceNumber}).";
                $cnAuditNote = $effectiveSource === 'base_rental_reconciliation_overflow'
                    ? "Auto-created from base_rental_reconciliation_credit overflow (S-BILLING-HOLISTIC-ENGINE) — invoice {$invoiceNumber}, overflow {$lease['currency']} {$mileageOverflow}."
                    : "Auto-created from mileage credit overflow (S-FIX-2 Bug #3) — invoice {$invoiceNumber}, overflow {$lease['currency']} {$mileageOverflow}.";

                $cnId = db_insert('credit_notes', [
                    'credit_note_number'      => $cnNumber,
                    'company_name_snapshot'   => $lease['company_name_snapshot']  ?? null,
                    'customer_name_snapshot'  => $lease['customer_name_snapshot'] ?? null,
                    'billing_address_snapshot' => $lease['billing_address']        ?? null,
                    'province_snapshot'        => $lease['province']                ?? null,
                    'customer_email_snapshot'  => $lease['customer_email']          ?? null,
                    'customer_id'             => $lease['customer_id'],
                    'lease_id'                => $leaseId,
                    'source'                  => $effectiveSource,
                    'source_invoice_id'       => $invoiceId,
                    'amount'                  => $mileageOverflow,
                    'currency'                => $lease['currency'],
                    'amount_remaining'        => $mileageOverflow,
                    'status'                  => 'active',
                    'reason'                  => $cnReason,
                    'created_by'              => $params['created_by'] ?? null,
                ]);

                db_insert('audit_log', [
                    'user_id'      => $params['created_by'] ?? null,
                    'user_name'    => (function_exists('current_user') && current_user())
                                        ? (current_user()['name'] ?? 'system') : 'system',
                    'action'       => 'create',
                    'module'       => 'invoices',
                    'entity_type'  => 'credit_note',
                    'entity_id'    => $cnId,
                    'entity_label' => $cnNumber,
                    'notes'        => $cnAuditNote,
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                // Auto-JE for the credit_note creation: DR Revenue / CR 2060
                // Customer Credits Liability. AR is unchanged (the invoice is
                // already $0 — no AR was raised).
                \FleetForge\Accounting\AutoEntryBridge::onCreditNoteIssued($cnId, $params['created_by'] ?? null);
            }

            return [
                'invoice_id'        => $invoiceId,
                'invoice_number'    => $invoiceNumber,
                'total_amount'      => $totalAmount,
                'balance_due'       => $balanceDue,
                'mileage_overflow'  => $mileageOverflow ?? '0.00',
            ];
        });
    }

    /**
     * Revision 2 §9 — calendar-month fan-out orchestrator for manual
     * generation. Given a lease and a requested start, emit the correct
     * SEQUENCE of invoices for the lease's known extent:
     *
     *   • short / weekly / single-calendar-month leases → ONE invoice for
     *     [fan_start, target] (billing_type single_period); no fan-out.
     *   • spanning monthly leases → one invoice PER calendar-month segment
     *     from fan_start to target (partial_start / full_month / partial_end),
     *     each reconciled against already_billed by the engine.
     *
     *   fan_start = the requested period_start (the create form pre-fills
     *               this as latest-non-void-period_end + 1, else lease.start).
     *   target    = the KNOWN EXTENT E = actual_return (closed) · end_date
     *               (set) · else the requested period_end (truly open).
     *
     * The whole run is ONE db_transaction (the nested createFromLease() and
     * findOverlappingInvoice() calls join it via the db.php nesting guard);
     * a mid-loop failure rolls back every segment (§11 Atomic batch). Each
     * createFromLease() takes FOR UPDATE on the lease row (holistic path) and
     * mints its number under the FOR UPDATE counter (D15). Integrity gates
     * (§11) are enforced per segment BEFORE any insert: duplicate-period
     * overlap (unless allow_overlap), void-before-regenerate (a non-void
     * overlap is immutable), and the inverted-date refusal (INV-27).
     *
     * Non-holistic (period_independent) and mileage_only leases never fan
     * out — they bill exactly the submitted [period_start, period_end] as one
     * invoice, honouring the submitted billing_type (legacy path untouched).
     *
     * @param array $params lease_id, period_start, period_end, billing_type,
     *   invoice_type, allow_overlap, po_number, notes, internal_notes,
     *   created_by, generation_source, and the optional odometer_* fields
     *   (attached to the FINAL segment only, mirroring the cron).
     * @return array{invoices: array<int,array>, count:int, fanned:bool,
     *               billing_type:string}
     */
    public function generateForLease(array $params): array
    {
        $leaseId        = (int)$params['lease_id'];
        $submittedStart = (string)$params['period_start'];
        $submittedEnd   = (string)$params['period_end'];

        return db_transaction(function () use ($leaseId, $submittedStart, $submittedEnd, $params) {
            // FOR UPDATE on the lease row for the whole batch (D20) — the
            // per-segment createFromLease() re-locks the same row harmlessly.
            $lease = db_row(
                "SELECT id, status, start_date, start_time, end_date, end_time,
                        actual_return_date, actual_return_time, engine_version,
                        daily_rate, weekly_rate, monthly_rate, billing_days_removed
                   FROM leases WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$leaseId]
            );
            if (!$lease) {
                json_error('NOT_FOUND', 'Lease not found.', 404);
            }

            $billingType   = (string)($params['billing_type'] ?? 'single_period');
            $invoiceType   = (string)($params['invoice_type'] ?? 'regular');
            $allowOverlap  = !empty($params['allow_overlap']);

            // ── INV-27 inverted-date refusal (§11) ──────────────────
            if ($submittedStart > $submittedEnd) {
                json_error(
                    'INVERTED_PERIOD',
                    "Period start ({$submittedStart}) is after period end ({$submittedEnd}).",
                    422,
                    ['fields' => ['period_end' => 'Due date cannot be before invoice date.']]
                );
            }

            // Passthrough params common to every emitted invoice.
            $common = [
                'lease_id'          => $leaseId,
                'po_number'         => $params['po_number'] ?? null,
                'notes'             => $params['notes'] ?? null,
                'internal_notes'    => $params['internal_notes'] ?? null,
                'created_by'        => $params['created_by'] ?? null,
                'generation_source' => $params['generation_source'] ?? 'manual',
            ];
            // Odometer + engine-hours are period-specific; attach only to the
            // final segment (the most recent period), mirroring the monthly cron.
            $odo = [
                'odometer_at_period_start_km' => $params['odometer_at_period_start_km'] ?? null,
                'odometer_at_period_end_km'   => $params['odometer_at_period_end_km']   ?? null,
                'odometer_source'             => $params['odometer_source']             ?? null,
                'odometer_fetched_at'         => $params['odometer_fetched_at']         ?? null,
                // S-LEASE-HOURLY-BILLING: manual engine-hours for this period.
                'engine_hours_at_period_start' => $params['engine_hours_at_period_start'] ?? null,
                'engine_hours_at_period_end'   => $params['engine_hours_at_period_end']   ?? null,
            ];

            // ── Single-segment mode (R2 §3.6 month picker) ──────────
            // The in-order month picker generates ONE calendar-month segment at
            // a time: bill exactly the submitted [period_start, period_end] and
            // do NOT fan out to the lease extent. The engine still reconciles
            // against already_billed (so a known span re-prorates correctly),
            // and the §11 overlap gate blocks re-billing an already-billed
            // month. Applies to every engine — for a holistic lease the segment
            // dates come straight from billable_months.php.
            if (!empty($params['single_segment'])) {
                $this->assertNoOverlap($leaseId, $submittedStart, $submittedEnd, $allowOverlap, $billingType);
                $inv = $this->createFromLease($common + $odo + [
                    'period_start' => $submittedStart,
                    'period_end'   => $submittedEnd,
                    'billing_type' => $billingType,
                    'invoice_type' => $invoiceType,
                ]);
                return ['invoices' => [$inv], 'count' => 1, 'fanned' => false, 'billing_type' => $billingType];
            }

            // ── mileage_only → single invoice, honour the form (never fans out) ──
            if ($billingType === 'mileage_only') {
                $this->assertNoOverlap($leaseId, $submittedStart, $submittedEnd, $allowOverlap, $billingType);
                $inv = $this->createFromLease($common + $odo + [
                    'period_start' => $submittedStart,
                    'period_end'   => $submittedEnd,
                    'billing_type' => $billingType,
                    'invoice_type' => $invoiceType,
                ]);
                return ['invoices' => [$inv], 'count' => 1, 'fanned' => false, 'billing_type' => $billingType];
            }

            // ── Holistic: resolve the known extent (target) ──────────
            $fanStart = $submittedStart;
            $target   = $submittedEnd;
            $extentDefinitive = false;
            if (!empty($lease['actual_return_date'])) {
                $target = (string)$lease['actual_return_date'];
                // S-LEASE-RENTAL-DAY-TIME: apply time-of-day rule to the
                // fan-out target (extent_end) when both times are captured.
                if (!empty($lease['actual_return_time']) && !empty($lease['start_time'])) {
                    $grace  = (int)(settings_get('lease.return_grace_minutes', '0') ?? '0');
                    $target = HolisticLeaseEngine::effectiveBillableEndDate(
                        $target,
                        (string)$lease['actual_return_time'],
                        (string)$lease['start_time'],
                        $grace,
                        (string)$lease['start_date']
                    );
                }
                $extentDefinitive = true;
            } elseif (!empty($lease['end_date'])) {
                $target = (string)$lease['end_date'];
                $extentDefinitive = true;
            }

            // S-LEASE-CLOSE-REMOVE-DAYS: subtract operator-removed days off the END
            // of the fan-out extent too. generateForLease is a SECOND holistic extent
            // authority (operator "create invoice" on a completed lease). Without this,
            // $target stays the un-reduced actual_return_date and becomes the invoice's
            // billing_period_end while createFromLease internally reduces only the
            // AMOUNT — the exact period_end-vs-amount divergence S-CLOSE-OVERSHOOT
            // exists to prevent. Same verbatim clamp as createFromLease / lease_billable_extent;
            // gated on actual_return_date (closed lease) and clamped to start_date.
            $daysRemoved = (int)($lease['billing_days_removed'] ?? 0);
            if ($daysRemoved > 0 && !empty($lease['actual_return_date'])) {
                $reduced = (new \DateTimeImmutable($target))->modify("-{$daysRemoved} day")->format('Y-m-d');
                $target  = ($reduced < (string)$lease['start_date']) ? (string)$lease['start_date'] : $reduced;
            }

            // Fully-billed / inverted: the requested start is past the extent.
            if ($target < $fanStart) {
                json_error(
                    'INVERTED_PERIOD',
                    "Nothing to bill: the requested start ({$fanStart}) is past the lease's billable extent ({$target}). "
                    . 'The lease is fully billed through ' . $target . '. To re-bill, void the relevant invoice and regenerate.',
                    422,
                    ['fields' => ['period_start' => "Past the lease extent ({$target})."]]
                );
            }

            // Classify the FULL lease on its known extent. basis tells us
            // whether monthly applies AND spans calendar months.
            $cls = $this->holistic->cumulativeCorrect(
                (string)$lease['start_date'], $target, $target,
                (string)$lease['daily_rate'], (string)$lease['weekly_rate'], (string)$lease['monthly_rate']
            );
            $spanning = ($cls['basis'] === 'monthly_multi_month');

            if (!$spanning) {
                // ONE invoice for the whole [fan_start, target] span.
                $this->assertNoOverlap($leaseId, $fanStart, $target, $allowOverlap, 'single_period');
                $inv = $this->createFromLease($common + $odo + [
                    'period_start' => $fanStart,
                    'period_end'   => $target,
                    'billing_type' => 'single_period',
                    'invoice_type' => $extentDefinitive ? 'final' : 'regular',
                ]);
                return ['invoices' => [$inv], 'count' => 1, 'fanned' => false, 'billing_type' => 'single_period'];
            }

            // ── Fan out per calendar-month segment ──────────────────
            $segments = $this->holistic->segmentsFor($fanStart, $target);
            if (empty($segments)) {
                json_error('INVERTED_PERIOD', "No billable calendar-month segments for {$fanStart}..{$target}.", 422);
            }

            $lastIdx  = count($segments) - 1;
            $created  = [];
            foreach ($segments as $i => $seg) {
                $this->assertNoOverlap($leaseId, $seg['period_start'], $seg['period_end'], $allowOverlap, $seg['billing_type']);
                $isLast    = ($i === $lastIdx);
                $segParams = $common + [
                    'period_start' => $seg['period_start'],
                    'period_end'   => $seg['period_end'],
                    'billing_type' => $seg['billing_type'],
                    'invoice_type' => ($isLast && $extentDefinitive) ? 'final' : 'regular',
                ];
                if ($isLast) {
                    $segParams += $odo; // odometer on the most-recent period only
                }
                $created[] = $this->createFromLease($segParams);
            }

            return [
                'invoices'     => $created,
                'count'        => count($created),
                'fanned'       => true,
                'billing_type' => 'multi_segment',
            ];
        });
    }

    /**
     * §11 duplicate-period + void-before-regenerate gate. Refuses (409) when
     * a NON-VOID invoice overlaps [start, end] unless allow_overlap is set.
     * The conflicting invoice's status drives the message: a sent / non-draft
     * invoice is immutable (D12/D14) and is named as such; a draft overlap is
     * the ordinary "would only bill the reconciliation difference" guard.
     * mileage_only never emits a base_rental, so it is not gated.
     */
    private function assertNoOverlap(
        int $leaseId,
        string $periodStart,
        string $periodEnd,
        bool $allowOverlap,
        string $billingType
    ): void {
        if ($allowOverlap || $billingType === 'mileage_only') {
            return;
        }
        $overlap = self::findOverlappingInvoice($leaseId, $periodStart, $periodEnd);
        if (!$overlap) {
            return;
        }
        $immutable = ($overlap['status'] !== 'draft');
        $msg = $immutable
            ? "This period ({$periodStart} to {$periodEnd}) overlaps invoice {$overlap['invoice_number']} "
              . "({$overlap['billing_period_start']} to {$overlap['billing_period_end']}, status {$overlap['status']}), "
              . 'which is immutable (D12/D14). Void it first to regenerate this period.'
            : "This period ({$periodStart} to {$periodEnd}) overlaps invoice {$overlap['invoice_number']} "
              . "({$overlap['billing_period_start']} to {$overlap['billing_period_end']}). "
              . 'Creating it would only bill the reconciliation difference, not the full period. '
              . 'Choose a non-overlapping period, void the conflict, or confirm to reconcile intentionally.';
        json_error('PERIOD_OVERLAP', $msg, 409, [
            'overlap' => [
                'invoice_id'           => (int)$overlap['id'],
                'invoice_number'       => $overlap['invoice_number'],
                'status'               => $overlap['status'],
                'billing_period_start' => $overlap['billing_period_start'],
                'billing_period_end'   => $overlap['billing_period_end'],
            ],
            'fields' => ['period_start' => "Overlaps invoice {$overlap['invoice_number']}."],
        ]);
    }

    /**
     * Generate an advance-billing batch for a lease at activation time.
     *
     * Produces Invoice 1 (current period — full_month if start is the 1st,
     * else partial_start) PLUS the lease's `advance_billing_periods` future
     * full-month invoices, all in a single transaction. All N+1 invoices
     * share the same invoice_date, FX snapshot, currency_markup_pct, and
     * tax rates — that's CRA-correct for a prepayment (tax rate in force at
     * the time of payment, not at the time of period). Sequential invoice
     * numbering is preserved by the existing FOR UPDATE counter in
     * generateInvoiceNumber() (called N+1 times inside this transaction).
     *
     * Side effects in the same transaction:
     *  - N+1 rows inserted into invoices (generation_source='advance')
     *  - line items + lease_billing_periods rows for each
     *  - Trap-6 counters (lease.total_invoiced/outstanding_balance,
     *    customer.outstanding_balance) accumulated by createFromLease()
     *  - lease.next_billing_date advanced past the final period
     *  - One audit_log row per generated invoice (module='billing')
     *
     * Caller responsibilities (NOT done here, by design):
     *  - Validate that lease.advance_billing_periods > 0 and billing_cycle = 'monthly'
     *  - Send the batched portal/staff notification (D-D)
     *
     * @param int      $leaseId    Lease to bill (must be 'pending' or 'active')
     * @param int|null $createdBy  Staff user_id, or null for system
     * @return array{
     *   invoices: array<int, array{invoice_id:int, invoice_number:string, total_amount:string, balance_due:string}>,
     *   next_billing_date: string,
     *   first_period_start: string,
     *   last_period_end: string,
     *   total_count: int,
     *   batch_total_amount: string
     * }
     */
    public function generateAdvanceBatch(int $leaseId, ?int $createdBy = null): array
    {
        return db_transaction(function () use ($leaseId, $createdBy) {
            $lease = db_row(
                "SELECT id, status, contract_number, start_date, billing_cycle,
                        advance_billing_periods
                 FROM leases WHERE id = ? AND deleted_at IS NULL",
                [$leaseId]
            );
            if (!$lease) {
                json_error('NOT_FOUND', 'Lease not found.', 404);
            }

            $advance = (int) $lease['advance_billing_periods'];
            if ($advance <= 0) {
                throw new \RuntimeException(
                    'generateAdvanceBatch called for lease #' . $leaseId
                    . ' with advance_billing_periods=0 — caller should branch on this.'
                );
            }
            if ($lease['billing_cycle'] !== 'monthly') {
                throw new \RuntimeException(
                    'Advance billing supported only for monthly billing_cycle (lease #' . $leaseId . ').'
                );
            }

            $totalCount = $advance + 1; // Invoice 1 + N future invoices
            $startDate  = (string) $lease['start_date'];
            $startDt    = new \DateTimeImmutable($startDate);
            $firstOfStartMonth = $startDt->format('Y-m-01');
            $lastOfStartMonth  = $startDt->format('Y-m-t');

            // Period 1: start_date → end of start month
            $periods = [[
                'start' => $startDate,
                'end'   => $lastOfStartMonth,
                'type'  => ($startDate === $firstOfStartMonth) ? 'full_month' : 'partial_start',
            ]];

            // Periods 2..N+1: each subsequent full month
            $cursor = new \DateTimeImmutable($lastOfStartMonth);
            for ($i = 2; $i <= $totalCount; $i++) {
                $cursor    = $cursor->modify('+1 day'); // first of next month
                $periodEnd = $cursor->format('Y-m-t');
                $periods[] = [
                    'start' => $cursor->format('Y-m-d'),
                    'end'   => $periodEnd,
                    'type'  => 'full_month',
                ];
                $cursor = new \DateTimeImmutable($periodEnd);
            }

            $invoices = [];
            $batchTotal = '0.00';
            $idx = 1;
            $userName = (function_exists('current_user') && current_user())
                ? (current_user()['name'] ?? 'system')
                : 'system';

            foreach ($periods as $p) {
                $note = sprintf(
                    'Advance billing %d of %d (lease %s)',
                    $idx, $totalCount, $lease['contract_number']
                );

                // Internal call — same transaction (db.php nesting guard).
                $inv = $this->createFromLease([
                    'lease_id'          => $leaseId,
                    'period_start'      => $p['start'],
                    'period_end'        => $p['end'],
                    'billing_type'      => $p['type'],
                    'invoice_type'      => 'regular',
                    'created_by'        => $createdBy,
                    'auto_generated'    => 1,
                    'generation_source' => 'advance',
                    'internal_notes'    => $note,
                ]);
                $invoices[]  = $inv;
                $batchTotal  = bcadd($batchTotal, (string) ($inv['total_amount'] ?? '0.00'), 2);

                db_insert('audit_log', [
                    'user_id'      => $createdBy,
                    'user_name'    => $userName,
                    'action'       => 'create',
                    'module'       => 'billing',
                    'entity_type'  => 'invoice',
                    'entity_id'    => $inv['invoice_id'],
                    'entity_label' => $inv['invoice_number'],
                    'notes'        => $note,
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
                $idx++;
            }

            // next_billing_date = day after the final advance period.
            // Cron skips this lease until that date, which is precisely the
            // intent: the customer has prepaid through the final period_end.
            $lastEnd = $periods[count($periods) - 1]['end'];
            $newNext = (new \DateTimeImmutable($lastEnd))
                ->modify('+1 day')
                ->format('Y-m-d');
            db_execute(
                "UPDATE leases SET next_billing_date = ?, updated_by = ? WHERE id = ?",
                [$newNext, $createdBy, $leaseId]
            );

            return [
                'invoices'           => $invoices,
                'next_billing_date'  => $newNext,
                'first_period_start' => $periods[0]['start'],
                'last_period_end'    => $lastEnd,
                'total_count'        => $totalCount,
                'batch_total_amount' => $batchTotal,
            ];
        });
    }

    /**
     * Generate a late-fee invoice for an overdue invoice.
     *
     * Called by cron/late_fee_apply.php. Handles the full flow:
     * 1. Load and FOR UPDATE lock the original invoice
     * 2. Find the applicable late_fee_rule (customer-specific, then global)
     * 3. Check grace period: skip if days overdue <= grace_days
     * 4. Delegate math to LateFeeEngine (D3: pure math lives there)
     * 5. Apply tax using same exemption snapshots as original invoice
     * 6. Create the late-fee invoice + line item in a single transaction
     * 7. Mark original invoice late_fee_applied=1 and update denormalized counters
     *
     * Returns a 'skipped' array (not an exception) for all non-error skip reasons
     * (no rule, grace period, already applied) — cron handles skips gracefully.
     *
     * D3:  InvoiceGenerator is the ONLY billing class that writes to DB.
     * D16: All monetary values stay as bcmath strings.
     * D20: FOR UPDATE on original invoice prevents concurrent double-fee.
     *
     * @param  int   $invoiceId  ID of the overdue invoice to charge a late fee on
     * @return array{invoice_id?: int, invoice_number?: string, fee_amount?: string,
     *               total_amount?: string, skipped: bool, reason?: string}
     */
    public function generateLateFeeInvoice(int $invoiceId): array
    {
        return db_transaction(function () use ($invoiceId) {

            // FOR UPDATE: prevents two concurrent cron runs from double-charging (D20)
            $orig = db_row(
                "SELECT * FROM invoices WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$invoiceId]
            );
            if (!$orig) {
                return ['skipped' => true, 'reason' => 'Invoice not found'];
            }
            // Already processed in a previous run
            if ($orig['late_fee_applied']) {
                return ['skipped' => true, 'reason' => 'Late fee already applied'];
            }
            if ($orig['status'] !== 'overdue') {
                return ['skipped' => true, 'reason' => 'Invoice is not overdue'];
            }

            // Find the applicable rule: customer-specific wins over global (NULL customer_id)
            $rule = db_row(
                "SELECT * FROM late_fee_rules
                 WHERE (customer_id = ? OR customer_id IS NULL)
                   AND is_active = 1
                 ORDER BY customer_id DESC
                 LIMIT 1",
                [$orig['customer_id']]
            );
            if (!$rule) {
                return ['skipped' => true, 'reason' => 'No active late fee rule'];
            }

            // Grace period: skip if invoice has not been overdue long enough
            // (grace_days = 0 means apply immediately once overdue)
            $daysOverdue = (int)floor(
                (strtotime(date('Y-m-d')) - strtotime($orig['due_date'])) / 86400
            );
            if ($daysOverdue <= (int)$rule['grace_days']) {
                return ['skipped' => true, 'reason' => 'Within grace period'];
            }

            // Delegate to LateFeeEngine (D3: pure math lives there, not here)
            $feeResult = $this->lateFee->calculate(
                (string)$orig['balance_due'],
                [
                    'fee_type'       => $rule['fee_type'],
                    'fee_value'      => (string)$rule['fee_value'],
                    'max_fee_amount' => $rule['max_fee_amount'] !== null
                        ? (string)$rule['max_fee_amount']
                        : null,
                ]
            );

            if (bccomp($feeResult['fee_amount'], '0.00', 2) <= 0) {
                return ['skipped' => true, 'reason' => 'Calculated fee is zero'];
            }

            // Province: use the snapshot frozen on the original invoice (avoids live JOIN
            // and ensures the late fee uses the same jurisdiction as the original charge).
            $province = !empty($orig['province_snapshot']) ? $orig['province_snapshot'] : 'BC';

            // Tax: use same exemption snapshots frozen on the original invoice (D22)
            $gstExempt = (bool)$orig['gst_exempt_snapshot'];
            $pstExempt = (bool)$orig['pst_exempt_snapshot'];
            $tax       = $this->taxCalc->calculate(
                $feeResult['fee_amount'],
                $province,
                $gstExempt,
                $pstExempt
            );

            $subtotal    = $feeResult['fee_amount'];
            $totalAmount = bcadd($subtotal, $tax['total'], 2);
            $today       = date('Y-m-d');
            $dueDays     = (int)(settings_get('invoice.due_days_default', '30') ?? 30);
            $dueDate     = date('Y-m-d', strtotime("+{$dueDays} days"));

            // Gap-free invoice number (D15, D20 — called inside this transaction)
            $invoiceNumber = $this->generateInvoiceNumber();

            // Describe the fee type in the line item for human readability
            $feeDesc = $rule['fee_type'] === 'percentage'
                ? sprintf(
                    'Late fee on %s (%.4f%% of $%s balance)',
                    $orig['invoice_number'],
                    (float)$rule['fee_value'] * 100,
                    number_format((float)$orig['balance_due'], 2)
                  )
                : sprintf(
                    'Late fee on %s (flat $%s)',
                    $orig['invoice_number'],
                    number_format((float)$rule['fee_value'], 2)
                  );

            // Insert the late-fee invoice — copy all snapshots from original (no DB joins needed)
            $newInvoiceId = db_insert('invoices', [
                'invoice_number'                => $invoiceNumber,
                'invoice_type'                  => 'late_fee',
                'customer_id'                   => $orig['customer_id'],
                'lease_id'                      => $orig['lease_id'],
                'customer_name_snapshot'         => $orig['customer_name_snapshot'],
                'company_name_snapshot'          => $orig['company_name_snapshot'],
                'contract_number_snapshot'       => $orig['contract_number_snapshot'],
                'unit_number_invoice_snapshot'   => $orig['unit_number_invoice_snapshot'],
                'billing_address_snapshot'       => $orig['billing_address_snapshot'],
                'province_snapshot'              => $orig['province_snapshot'] ?? null,
                'customer_email_snapshot'        => $orig['customer_email_snapshot'],
                'gst_exempt_snapshot'            => $orig['gst_exempt_snapshot'],
                'pst_exempt_snapshot'            => $orig['pst_exempt_snapshot'],
                'tax_exempt_snapshot'            => $orig['tax_exempt_snapshot'],
                'gst_exempt_number_snapshot'     => $orig['gst_exempt_number_snapshot'] ?? null,
                'pst_exempt_number_snapshot'     => $orig['pst_exempt_number_snapshot'] ?? null,
                'currency'                       => $orig['currency'],
                'exchange_rate_to_cad'           => $orig['exchange_rate_to_cad'],
                'currency_markup_pct'            => $orig['currency_markup_pct'] ?? '0.0000',
                'billing_period_start'           => $today,
                'billing_period_end'             => $today,
                'billing_period_days'            => 1,
                'billing_type'                   => 'adjustment',
                'rate_method_used'               => 'none',
                'invoice_date'                   => $today,
                'due_date'                       => $dueDate,
                'status'                         => 'draft',
                'subtotal'                       => $subtotal,
                'discount_type'                  => 'none',
                'discount_value'                 => '0.0000',
                'discount_amount'                => '0.00',
                'subtotal_after_discount'        => $subtotal,
                'tax_gst_rate'                   => $tax['gst_rate'],
                'tax_pst_rate'                   => $tax['pst_rate'],
                'tax_hst_rate'                   => $tax['hst_rate'],
                'tax_gst_amount'                 => $tax['gst'],
                'tax_pst_amount'                 => $tax['pst'],
                'tax_hst_amount'                 => $tax['hst'],
                'tax_total'                      => $tax['total'],
                'total_amount'                   => $totalAmount,
                'amount_paid'                    => '0.00',
                'credits_applied'                => '0.00',
                'balance_due'                    => $totalAmount,
                'notes'                          => "Late fee on invoice {$orig['invoice_number']}",
                'late_fee_rule_id'               => (int)$rule['id'],
                'late_fee_rule_snapshot'         => json_encode([
                    'fee_type'       => $rule['fee_type'],
                    'fee_value'      => (string)$rule['fee_value'],
                    'grace_days'     => (int)$rule['grace_days'],
                    'max_fee_amount' => $rule['max_fee_amount'] !== null ? (string)$rule['max_fee_amount'] : null,
                    'compound'       => (bool)$rule['compound'],
                    'customer_id'    => $rule['customer_id'],
                ]),
                'auto_generated'                 => 1,
                'generation_source'              => 'late_fee_cron',
                'created_by'                     => null,
                'updated_by'                     => null,
            ]);

            // Single line item for the late fee charge
            db_insert('invoice_line_items', [
                'invoice_id'     => $newInvoiceId,
                'sort_order'     => 0,
                'item_type'      => 'late_fee',
                'description'    => $feeDesc,
                'quantity'       => '1.0000',
                'unit'           => null,
                'unit_price'     => $subtotal,
                'amount'         => $subtotal,
                'is_credit'      => 0,
                'taxable'        => 1,
                'tax_gst_amount' => $tax['gst'],
                'tax_pst_amount' => $tax['pst'],
                'tax_hst_amount' => $tax['hst'],
            ]);

            // Mark the original invoice — prevents re-processing in future cron runs
            db_execute(
                "UPDATE invoices
                 SET late_fee_applied = 1,
                     late_fee_amount  = ?,
                     late_fee_date    = ?,
                     late_fee_invoice_id = ?,
                     updated_at       = NOW()
                 WHERE id = ?",
                [$feeResult['fee_amount'], $today, $newInvoiceId, $invoiceId]
            );

            // Update denormalized counters on the lease (Trap 6: same transaction)
            // S-FIX-2 Path B: late-fee invoices are created as 'draft' (status above
            // is 'draft' on line ~752). total_invoiced gets the increment (it includes
            // drafts), but outstanding_balance does NOT — that fires when staff sends
            // the late-fee draft via send.php.
            if ($orig['lease_id']) {
                db_execute(
                    "UPDATE leases
                     SET total_invoiced = total_invoiced + ?,
                         updated_at     = NOW()
                     WHERE id = ?",
                    [$totalAmount, $orig['lease_id']]
                );
            }

            // S-FIX-2 Path B: customer.outstanding_balance NOT touched at late-fee
            // creation — this counter only moves on draft -> sent (send.php) and on
            // payment/credit/void/etc. against a sent invoice.

            return [
                'invoice_id'     => $newInvoiceId,
                'invoice_number' => $invoiceNumber,
                'fee_amount'     => $feeResult['fee_amount'],
                'total_amount'   => $totalAmount,
                'skipped'        => false,
            ];
        });
    }

    /**
     * Find a non-void invoice whose billing period OVERLAPS [periodStart, periodEnd]
     * for the same lease.
     *
     * WHY: The holistic running-reconciliation engine keys off period_END only and
     * is BLIND to overlap by design — if a second invoice re-covers days an earlier
     * invoice already billed, the engine silently absorbs the overlap and bills only
     * the cumulative delta (e.g. a $50 top-up presented as "31 billing days"). That is
     * mathematically correct but confusing, and an overlapping MANUAL invoice is almost
     * always an operator mistake. This helper lets callers detect/guard the overlap and
     * lets the line-item presenter label a genuine reconciliation top-up honestly.
     *
     * Closed-interval overlap test (both ends inclusive, matching D14 inclusive
     * day counting — so adjacent periods like 06-30 / 07-01 do NOT overlap):
     *   existing.start <= new.end  AND  existing.end >= new.start
     *
     * Status filter intentionally mirrors HolisticLeaseEngine::sumAlreadyBilled —
     * exclude only 'void' (and soft-deleted). A written_off invoice STILL counts
     * as billed coverage (its amount remains in already_billed), so it must also
     * count as overlap; otherwise the presentation gate (isReconcileTopUp) and the
     * cumulative math would disagree and a written-off overlap would re-introduce
     * the misleading "fresh full-period" label.
     *
     * @param  int      $leaseId
     * @param  string   $periodStart        Y-m-d
     * @param  string   $periodEnd          Y-m-d
     * @param  int|null $excludeInvoiceId   Ignore this invoice id (e.g. self on edit)
     * @return array|null  The first overlapping invoice row, or null when none.
     */
    public static function findOverlappingInvoice(
        int $leaseId,
        string $periodStart,
        string $periodEnd,
        ?int $excludeInvoiceId = null
    ): ?array {
        return db_row(
            "SELECT id, invoice_number, status, billing_period_start, billing_period_end
               FROM invoices
              WHERE lease_id = ?
                AND deleted_at IS NULL
                AND status <> 'void'
                AND billing_period_start IS NOT NULL
                AND billing_period_end   IS NOT NULL
                AND billing_period_start <= ?
                AND billing_period_end   >= ?
                AND (? IS NULL OR id <> ?)
              ORDER BY billing_period_start ASC, id ASC
              LIMIT 1",
            [$leaseId, $periodEnd, $periodStart, $excludeInvoiceId, $excludeInvoiceId]
        );
    }

    /**
     * Generate a gap-free sequential invoice number.
     *
     * Uses atomic counter in settings table with FOR UPDATE lock (D15, D20).
     * Format: INV-YYYY-NNNNN (e.g., INV-2026-00001)
     * MUST be called inside a transaction.
     *
     * @return string The generated invoice number
     */
    public function generateInvoiceNumber(): string
    {
        $year = date('Y');
        $key = "invoice.next_number.{$year}";

        // WHY: prefix from settings so admin can rebrand without code change
        $prefix = settings_get('invoice.prefix', 'INV');

        // FOR UPDATE lock on the counter row (D20)
        $row = db_row(
            "SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE",
            [$key]
        );

        $next = $row ? (int)$row['value'] : 1;

        // ── Drift guard ────────────────────────────────────────────
        // Reconcile the counter against the highest invoice_number that
        // ACTUALLY exists for this prefix+year. The counter can fall behind
        // reality when invoices are inserted out-of-band — data imports,
        // migrations, or seed scripts that mint via the separate
        // generate_id() ('inv.next_number.*') counter rather than this one.
        // A behind counter would mint a duplicate invoice_number, hit the
        // UNIQUE(invoice_number) constraint, and abort the whole enclosing
        // transaction — e.g. lease activation surfacing as "failed to
        // activate lease". Taking max(counter, existingMax + 1) keeps
        // numbering gap-free in the normal case (where they're equal) and
        // self-heals when drift exists. The FOR UPDATE above still
        // serializes concurrent minters. SUBSTRING_INDEX(...,'-',-1) pulls
        // the trailing numeric run regardless of dashes in the prefix; we
        // count ALL rows (incl. soft-deleted) because the UNIQUE index does.
        $maxRow = db_row(
            "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) AS max_suffix
               FROM invoices
              WHERE invoice_number LIKE ?",
            ["{$prefix}-{$year}-%"]
        );
        $existingNext = (int)($maxRow['max_suffix'] ?? 0) + 1;
        if ($existingNext > $next) {
            $next = $existingNext;
        }

        $invoiceNumber = sprintf("%s-%s-%05d", $prefix, $year, $next);

        // Increment atomically
        if ($row) {
            db_execute(
                "UPDATE settings SET `value` = ? WHERE `key` = ?",
                [(string)($next + 1), $key]
            );
        } else {
            db_execute(
                "INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, ?)",
                [$key, (string)($next + 1), 'invoices']
            );
        }

        return $invoiceNumber;
    }

    /**
     * S-BILLING-HOLISTIC-ENGINE: map the holistic engine's tier
     * vocabulary to the invoices.rate_method_used ENUM domain
     * (daily / weekly / weekly_capped / monthly / none).
     *
     * The engine reports tiers as: 'none', 'daily', 'weekly_flat',
     * 'weekly_math', 'weekly_capped', 'monthly_math', and the
     * S-LEASE-MIN-DAYS short-lease floor 'daily_minimum'. The DB column
     * doesn't distinguish flat-vs-math weeklies (both bill weekly),
     * uses 'monthly' for any 30+ day tier, and records the flat
     * minimum-days floor under its economic basis 'daily'. Collapse accordingly.
     *
     * The same mapping is reused for invoice_line_items.rate_method,
     * which shares the ENUM with one nullable difference (caller wraps).
     */
    private function mapTierToRateMethod(string $tier): string
    {
        return match ($tier) {
            'daily'         => 'daily',
            'weekly_flat'   => 'weekly',
            'weekly_math'   => 'weekly',
            'weekly_capped' => 'weekly_capped',
            'monthly'       => 'monthly',  // R2: calendar-month flat / per-segment
            'monthly_math'  => 'monthly',  // legacy tier name (applyTierFormula)
            'daily_minimum' => 'daily',  // S-LEASE-MIN-DAYS: flat short-lease floor → economic basis 'daily'
            default         => 'none',  // 'none' or any unknown
        };
    }
}
