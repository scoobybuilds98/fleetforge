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
     * 3. Insurance/warranty add-ons
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

            // Load lease with all rate/tax/customer data
            $lease = db_row(
                "SELECT l.*, c.province, c.billing_address, c.email AS customer_email
                 FROM leases l
                 LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
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

            // --- SAMSARA-3: odometer + distance (optional) ---
            // Values come in from callers (api/v1/invoices/create.php or
            // api/v1/leases/close.php) via $params. They're optional —
            // when omitted, the four distance columns stay null and the
            // invoice behaves exactly like it did before this session.
            //
            // period_distance_km     = end - start            (this period)
            // cumulative_distance_km = end - lease.odometer_start_km
            //                                                  (since lease start)
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

            // --- Insert invoice ---
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
            db_execute(
                "UPDATE leases SET total_invoiced = total_invoiced + ?, outstanding_balance = outstanding_balance + ?, last_billed_date = ?, last_billed_invoice_id = ?, updated_at = NOW() WHERE id = ?",
                [$totalAmount, $balanceDue, $invoiceDate, $invoiceId, $leaseId]
            );

            // --- Update customer outstanding_balance (Trap 6) ---
            if ($lease['customer_id']) {
                db_execute(
                    "UPDATE customers SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
                    [$balanceDue, $lease['customer_id']]
                );
            }

            return [
                'invoice_id'     => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'total_amount'   => $totalAmount,
                'balance_due'    => $balanceDue,
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
            if ($orig['lease_id']) {
                db_execute(
                    "UPDATE leases
                     SET total_invoiced       = total_invoiced + ?,
                         outstanding_balance  = outstanding_balance + ?,
                         updated_at           = NOW()
                     WHERE id = ?",
                    [$totalAmount, $totalAmount, $orig['lease_id']]
                );
            }

            // Update denormalized counter on the customer (Trap 6: same transaction)
            if ($orig['customer_id']) {
                db_execute(
                    "UPDATE customers
                     SET outstanding_balance = outstanding_balance + ?,
                         updated_at          = NOW()
                     WHERE id = ?",
                    [$totalAmount, $orig['customer_id']]
                );
            }

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
