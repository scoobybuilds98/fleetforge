<?php
declare(strict_types=1);

/**
 * lib/Billing/InvoiceGenerator.php
 *
 * Invoice orchestrator — the ONLY billing class that reads/writes the database.
 * Calls ProRateCalculator for rental math, TaxCalculator for tax, and LateFeeEngine
 * for late fee math. Handles invoice number generation (D15: gap-free, sequential,
 * FOR UPDATE lock D20).
 *
 * Required by: api/v1/invoices/create.php, cron/invoice_generate_monthly.php,
 *              cron/late_fee_apply.php
 * Requires: includes/db.php, lib/Billing/ProRateCalculator.php,
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
    private ProRateCalculator $proRate;
    private TaxCalculator     $taxCalc;
    private LateFeeEngine     $lateFee;

    public function __construct()
    {
        $this->proRate = new ProRateCalculator();
        $this->taxCalc = new TaxCalculator();
        $this->lateFee = new LateFeeEngine();
    }

    /**
     * Create a manual invoice for a lease.
     *
     * Follows the Invoice Calculation Order (spec §9):
     * 1. Base rental via ProRateCalculator
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
                        c.id                  AS customer_row_id,
                        c.company_name        AS customer_company_name,
                        eu.samsara_vehicle_id AS samsara_vehicle_id
                 FROM leases l
                 LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
                 LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
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

            // --- Step 1: Base rental via ProRateCalculator ---
            // ADV-BILL-1: 'mileage_only' (used by close.php for advance leases when
            // mileage overage needs its own invoice) skips the base rental entirely —
            // the only line items come from extra_lines.
            $lineItems = [];
            $sortOrder = 0;

            if ($billingType === 'mileage_only') {
                $rentalAmount = '0.00';
                $rateMethod   = 'none';
                $explanation  = ['Mileage-only adjustment — no base rental.'];
            } elseif ($billingType === 'full_month') {
                // Full month = flat monthly rate, no formula
                $rentalAmount = bcround((string)$lease['monthly_rate'], 2);
                $rateMethod = 'monthly';
                $explanation = ['Full month — flat monthly rate: $' . $rentalAmount];
            } else {
                // Partial period — use THE LAW
                $result = $this->proRate->calculate(
                    $days,
                    (string)$lease['daily_rate'],
                    (string)$lease['weekly_rate'],
                    (string)$lease['monthly_rate']
                );
                $rentalAmount = $result['amount'];
                $rateMethod = $result['method'];
                $explanation = $result['explanation'];
            }

            // ════════════════════════════════════════════════════════════════
            // S-BILLING-RATE-FIX D-E (D132 backstop) — refuse $0 base_rental
            //
            // ProRateCalculator now throws on its own zero-compute paths, but
            // the full_month shortcut above bypasses ProRateCalculator and uses
            // monthly_rate directly. If a lease ever sneaks past D132 with
            // monthly_rate=0 (cron, fixtures, direct SQL), the full_month path
            // would silently ship a $0 base_rental. Mirror the calculator's
            // fail-loud behaviour here. mileage_only / adjustment / credit_note
            // legitimately carry $0 base_rental and are exempt.
            // ════════════════════════════════════════════════════════════════
            $zeroAllowed = ['mileage_only', 'adjustment', 'credit_note'];
            if (!in_array($billingType, $zeroAllowed, true)
                && bccomp($rentalAmount, '0', 2) <= 0) {
                throw new BillingRateException(
                    sprintf(
                        'InvoiceGenerator refused to write $0 base_rental: lease_id=%d, period=%s..%s (%d days), billing_type=%s, rate_method=%s, daily=%s, weekly=%s, monthly=%s. '
                        . 'Upstream rate-tier-completeness invariant (D132) must be enforced at lease create — see api/v1/leases/create.php.',
                        (int)$lease['id'], $periodStart, $periodEnd, $days, $billingType, $rateMethod,
                        (string)$lease['daily_rate'], (string)$lease['weekly_rate'], (string)$lease['monthly_rate']
                    ),
                    $rateMethod, $days,
                    (string)$lease['daily_rate'], (string)$lease['weekly_rate'], (string)$lease['monthly_rate'],
                    [
                        'lease_id'     => (int)$lease['id'],
                        'period_start' => $periodStart,
                        'period_end'   => $periodEnd,
                        'billing_type' => $billingType,
                    ]
                );
            }

            // ADV-BILL-1: skip the base_rental and insurance/warranty lines on
            // mileage_only adjustment invoices — they only carry mileage extra_lines.
            if ($billingType !== 'mileage_only') {
                $lineItems[] = [
                    'sort_order'   => $sortOrder++,
                    'item_type'    => 'base_rental',
                    'description'  => "Base rental: {$periodStart} to {$periodEnd} ({$days} days)",
                    'detail_lines' => json_encode($explanation),
                    'quantity'     => '1.0000',
                    'unit'         => 'period',
                    'unit_price'   => $rentalAmount,
                    'amount'       => $rentalAmount,
                    'is_credit'    => 0,
                    'taxable'      => 1,
                    'billing_days' => $days,
                    'rate_method'  => $rateMethod,
                    'period_start' => $periodStart,
                    'period_end'   => $periodEnd,
                ];
            }

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
            if ($periodDistanceKm === null
                && !empty($lease['samsara_vehicle_id'])
                && $billingType !== 'mileage_only'
            ) {
                try {
                    $samsara   = new \FleetForge\GPS\SamsaraClient();
                    $startDtUtc = (new \DateTimeImmutable($periodStart . ' 00:00:00', new \DateTimeZone('UTC')));
                    $endDtUtc   = (new \DateTimeImmutable($periodEnd   . ' 23:59:59', new \DateTimeZone('UTC')));
                    $samsaraResult = $samsara->getDistanceForPeriod(
                        (string) $lease['samsara_vehicle_id'],
                        $startDtUtc, $endDtUtc, 'km'
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
            // S-MILEAGE-2B C3 — Model B drawdown emit
            //
            // Gate (locked D-B + K-16 clarification — precharge_invoiced_at
            // discriminator + Model B Lite passthrough via precharge_enabled=0):
            //   period_distance_km > 0
            //   AND mileage_rate_km > 0 (D135 intent signal)
            //   AND billing_type NOT IN (mileage_only, adjustment, credit_note)
            //   AND (precharge_invoiced_at IS NOT NULL OR precharge_enabled = 0)
            //       — Model B (full) Invoice 1: blocked (precharge emit owns it)
            //       — Model B (full) Invoice 2+: drawdown emits (post-stamp)
            //       — Model B Lite (precharge_enabled=0): drawdown emits (no
            //         precharge concept; balance=NULL → falls to second branch,
            //         emits only mileage_usage)
            //
            // K-16 spec clarification vs locked D-B wording:
            //   - D-B's "negative amount" emit shape collides with
            //     InvoiceGenerator's signed-aggregator convention at
            //     lines ~357-362 (POSITIVE amount + is_credit=1 → bcsub).
            //   - Resolution: emit mileage_drawdown_credit with POSITIVE
            //     amount + is_credit=1. The aggregator subtracts; per-line
            //     tax negates internally (line ~856). Financial result is
            //     identical to D-B's stated intent; matches existing convention
            //     (mileage_credit, early_return_credit, account_credit_applied).
            //   - TaxCalculator handles negatives via bcmul sign-propagation
            //     (lines 67/72/78); D-D spike confirmed no TaxCalculator
            //     change needed.
            //
            // Math (bcmath only per D16):
            //   period_charge    = bcround(bcmul(distance, rate, 6), 2)
            //   drawdown_amount  = min(period_charge, precharge_balance)
            //                     (computed via bccomp + ternary)
            //   remaining_charge = bcsub(period_charge, drawdown_amount, 2)
            //
            // Emission:
            //   Branch A — precharge_balance > 0:
            //     mileage_usage line, amount = period_charge, is_credit = 0
            //     mileage_drawdown_credit line, amount = drawdown_amount
            //                                  (positive), is_credit = 1
            //     UPDATE leases.precharge_balance -= drawdown_amount inside
            //       this transaction; D20 FOR UPDATE on lease row;
            //       audit_log entity_type='lease_precharge_balance_drawdown'
            //   Branch B — precharge_balance == 0 or NULL:
            //     mileage_usage line only; no balance update
            //
            // Engine markers — replaces the S-LEASE-MILEAGE per-period excess
            // block at lib/Billing/InvoiceGenerator.php:559-713 (DELETED below)
            // + the duplicate odometer setup at 515-557 (DELETED below).
            // ════════════════════════════════════════════════════════════════
            $drawdownGate = (
                $periodDistanceKm !== null
                && bccomp((string) $periodDistanceKm, '0', 2) > 0
                && bccomp((string) ($lease['mileage_rate_km'] ?? '0'), '0', 4) > 0
                && !in_array($billingType, ['mileage_only', 'adjustment', 'credit_note'], true)
                && (
                    ($lease['precharge_invoiced_at'] ?? null) !== null
                    || (int) ($lease['precharge_enabled'] ?? 0) === 0
                )
            );

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

            // Drawdown emit (Branch A or B per D-B math).
            $drawdownAuditMeta = null;
            if ($drawdownGate) {
                $rateKm        = (string) $lease['mileage_rate_km'];
                $periodCharge  = bcround(bcmul((string) $periodDistanceKm, $rateKm, 6), 2);

                // FOR UPDATE re-read of the lease's precharge_balance per D20.
                // The outer transaction already holds the row implicitly through
                // its updates, but an explicit FOR UPDATE here makes the
                // serialization point obvious and survives future refactors.
                $leaseLocked = db_row(
                    "SELECT id, precharge_balance, precharge_enabled
                       FROM leases WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                    [$leaseId]
                );
                $preBalance = $leaseLocked['precharge_balance'] !== null
                    ? (string) $leaseLocked['precharge_balance']
                    : '0.00';

                // mileage_usage line (Branch A and B both emit this).
                $lineItems[] = [
                    'sort_order'       => $sortOrder++,
                    'item_type'        => 'mileage_usage',
                    'description'      => "Mileage usage: " . number_format((float) $periodDistanceKm, 2) . " km × \$" . $rateKm . "/km",
                    'quantity'         => sprintf('%s', $periodDistanceKm),
                    'unit'             => 'km',
                    'unit_price'       => $rateKm,
                    'amount'           => $periodCharge,
                    'is_credit'        => 0,
                    'taxable'          => 1,
                    'mileage_distance' => (string) $periodDistanceKm,
                    'mileage_rate'     => $rateKm,
                    'mileage_unit'     => 'km',
                    'period_start'     => $periodStart,
                    'period_end'       => $periodEnd,
                ];

                if (bccomp($preBalance, '0', 2) > 0) {
                    // Branch A — precharge_balance > 0: emit drawdown credit + decrement.
                    $drawdownAmt = bccomp($periodCharge, $preBalance, 2) <= 0
                        ? $periodCharge
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
                        'is_credit'   => 1,  // POSITIVE amount + is_credit=1 → aggregator subtracts (K-16 convention)
                        'taxable'     => 1,
                    ];

                    db_execute(
                        "UPDATE leases SET precharge_balance = ?, updated_at = NOW(), updated_by = ?
                           WHERE id = ?",
                        [$postBalance, $params['created_by'] ?? null, $leaseId]
                    );

                    $drawdownAuditMeta = [
                        'pre_balance'    => $preBalance,
                        'drawdown_amount'=> $drawdownAmt,
                        'post_balance'   => $postBalance,
                        'period_charge'  => $periodCharge,
                        'period_distance_km' => (string) $periodDistanceKm,
                        'mileage_rate_km'    => $rateKm,
                    ];
                }
                // Branch B — precharge_balance == 0 or NULL: no credit line,
                // no balance update. mileage_usage line above stands alone.
            }

            // --- Step 3: Insurance add-on ---
            if ($billingType !== 'mileage_only'
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
            if ($billingType !== 'mileage_only'
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
            // service. Skipped on mileage_only just like the others.
            if ($billingType !== 'mileage_only'
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

            // --- Step 5: Subtotal = SUM(line_items.amount), credits subtract ---
            $subtotal = '0.00';
            foreach ($lineItems as $item) {
                if ($item['is_credit']) {
                    $subtotal = bcsub($subtotal, $item['amount'], 2);
                } else {
                    $subtotal = bcadd($subtotal, $item['amount'], 2);
                }
            }

            // --- S-FIX-2 Bug #3: mileage_credit overflow cap ---
            // If the credit lines drive subtotal below $0, cap the first
            // mileage_credit line so subtotal floors at $0 and route the
            // remainder to a credit_notes row (source='mileage_overpayment').
            // The credit_note is created AFTER the invoice INSERT below; we
            // capture the overflow here so the invoice rows reflect the cap.
            $mileageOverflow = '0.00';
            if (bccomp($subtotal, '0', 2) < 0) {
                $mileageOverflow = bcmul($subtotal, '-1', 2); // abs($subtotal)

                $capApplied = false;
                foreach ($lineItems as &$capItem) {
                    if (($capItem['item_type'] ?? '') === 'mileage_credit'
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

                        $capApplied = true;
                        break;
                    }
                }
                unset($capItem);

                if (!$capApplied) {
                    // No mileage_credit line is present, yet the subtotal went
                    // negative. That means a different is_credit line (manual,
                    // goodwill, etc.) caused it — refuse rather than silently
                    // rewriting a deliberate adjustment. Caller should split.
                    json_error(
                        'VALIDATION_ERROR',
                        'Invoice subtotal is negative but no mileage_credit line is present to cap. Reduce other credit line items or split into a separate credit_note.',
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
            $expiryCheckDate = $invoiceDate ?? date('Y-m-d');
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
            $invoiceNumber = $this->generateInvoiceNumber();

            // Due date from settings
            $dueDays = (int)(settings_get('invoice.due_days_default', '30') ?? 30);
            $invoiceDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime("+{$dueDays} days"));

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
                'customer_email_snapshot'    => $lease['customer_email'] ?? null,
                'gst_exempt_snapshot'        => (int)$gstExempt,
                'pst_exempt_snapshot'        => (int)$pstExempt,
                'tax_exempt_snapshot'        => (int)$lease['tax_exempt'],
                'tax_exempt_number_snapshot' => null,
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
                        'Precharge balance drawdown on invoice %s: pre=$%s, drawdown=$%s, post=$%s (period_charge=$%s on %s km × $%s/km)',
                        $invoiceNumber,
                        $drawdownAuditMeta['pre_balance'],
                        $drawdownAuditMeta['drawdown_amount'],
                        $drawdownAuditMeta['post_balance'],
                        $drawdownAuditMeta['period_charge'],
                        $drawdownAuditMeta['period_distance_km'],
                        $drawdownAuditMeta['mileage_rate_km']
                    ),
                    'old_values'   => json_encode([
                        'precharge_balance' => $drawdownAuditMeta['pre_balance'],
                    ]),
                    'new_values'   => json_encode([
                        'precharge_balance'  => $drawdownAuditMeta['post_balance'],
                        'drawdown_amount'    => $drawdownAuditMeta['drawdown_amount'],
                        'period_charge'      => $drawdownAuditMeta['period_charge'],
                        'period_distance_km' => $drawdownAuditMeta['period_distance_km'],
                        'mileage_rate_km'    => $drawdownAuditMeta['mileage_rate_km'],
                        'invoice_id'         => $invoiceId,
                        'invoice_number'     => $invoiceNumber,
                    ]),
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
            db_insert('lease_billing_periods', [
                'lease_id'            => $leaseId,
                'invoice_id'          => $invoiceId,
                'period_start'        => $periodStart,
                'period_end'          => $periodEnd,
                'period_days'         => $days,
                'period_type'         => $billingType,
                'rate_method'         => $rateMethod ?? 'daily',
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
            db_execute(
                "UPDATE leases SET total_invoiced = total_invoiced + ?, last_billed_date = ?, last_billed_invoice_id = ?, updated_at = NOW() WHERE id = ?",
                [$totalAmount, $invoiceDate, $invoiceId, $leaseId]
            );

            // S-FIX-2 Path B: drafts do NOT touch outstanding_balance.
            // The increment fires in api/v1/invoices/send.php on draft -> sent.
            // (Historical comment "Trap 6 customer outstanding_balance" removed —
            //  the customer counter now follows Path B canonical truth.)

            // S-FIX-2 Bug #3: route the mileage credit overflow (if any) to a
            // credit_notes row with source='mileage_overpayment'. Created after
            // the invoice INSERT so source_invoice_id can reference the new
            // invoice. Same db_transaction → JE rolls back if either fails.
            // No tax: a customer credit liability is not a billable line.
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

                $cnId = db_insert('credit_notes', [
                    'credit_note_number' => $cnNumber,
                    'customer_id'        => $lease['customer_id'],
                    'lease_id'           => $leaseId,
                    'source'             => 'mileage_overpayment',
                    'source_invoice_id'  => $invoiceId,
                    'amount'             => $mileageOverflow,
                    'currency'           => $lease['currency'],
                    'amount_remaining'   => $mileageOverflow,
                    'status'             => 'active',
                    'reason'             => "Mileage credit exceeded final invoice subtotal — overflow routed to account credit (S-FIX-2 Bug #3, invoice {$invoiceNumber}).",
                    'created_by'         => $params['created_by'] ?? null,
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
                    'notes'        => "Auto-created from mileage credit overflow (S-FIX-2 Bug #3) — invoice {$invoiceNumber}, overflow {$lease['currency']} {$mileageOverflow}.",
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

            // Determine province for tax lookup — from lease → customer join
            $province = 'BC'; // WHY: default to BC (company location) if no data
            if ($orig['lease_id']) {
                $leaseRow = db_row(
                    "SELECT c.province FROM leases l
                     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
                     WHERE l.id = ? AND l.deleted_at IS NULL",
                    [$orig['lease_id']]
                );
                if ($leaseRow && !empty($leaseRow['province'])) {
                    $province = $leaseRow['province'];
                }
            }

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
                'customer_email_snapshot'        => $orig['customer_email_snapshot'],
                'gst_exempt_snapshot'            => $orig['gst_exempt_snapshot'],
                'pst_exempt_snapshot'            => $orig['pst_exempt_snapshot'],
                'tax_exempt_snapshot'            => $orig['tax_exempt_snapshot'],
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
}
