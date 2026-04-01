<?php
declare(strict_types=1);

/**
 * lib/Billing/InvoiceGenerator.php
 *
 * Invoice orchestrator — the ONLY billing class that reads/writes the database.
 * Calls ProRateCalculator for rental math and TaxCalculator for tax computation.
 * Handles invoice number generation (D15: gap-free, sequential, FOR UPDATE lock D20).
 *
 * Required by: api/v1/invoices/create.php, cron/invoice_generate_monthly.php
 * Requires: includes/db.php, lib/Billing/ProRateCalculator.php, lib/Billing/TaxCalculator.php
 * Defines: FleetForge\Billing\InvoiceGenerator
 *
 * Decisions: D11 (tax at invoice time), D12 (immutability after send), D14 (inclusive days),
 *            D15 (sequential invoice numbers), D16 (bcmath), D20 (FOR UPDATE),
 *            D22 (granular tax exemptions)
 * Spec ref: §9 Invoice Generation Flow, §9 Invoice Calculation Order
 */

namespace FleetForge\Billing;

class InvoiceGenerator
{
    private ProRateCalculator $proRate;
    private TaxCalculator $taxCalc;

    public function __construct()
    {
        $this->proRate = new ProRateCalculator();
        $this->taxCalc = new TaxCalculator();
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
     *   extra_lines: ?array (manual line items)
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
            $lineItems = [];
            $sortOrder = 0;

            if ($billingType === 'full_month') {
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

            // --- Step 3: Insurance add-on ---
            if ($lease['insurance_opt_in'] && bccomp((string)$lease['insurance_cost'], '0', 2) > 0) {
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
            if ($lease['warranty_opt_in'] && bccomp((string)$lease['warranty_cost'], '0', 2) > 0) {
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

            // Exchange rate for USD invoices
            $exchangeRate = null;
            if ($lease['currency'] === 'USD') {
                $fxRow = db_row(
                    "SELECT rate FROM exchange_rates WHERE from_currency = 'USD' AND to_currency = 'CAD' AND is_active = 1 ORDER BY effective_date DESC LIMIT 1",
                    []
                );
                $exchangeRate = $fxRow ? $fxRow['rate'] : null;
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

        // FOR UPDATE lock on the counter row (D20)
        $row = db_row(
            "SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE",
            [$key]
        );

        $next = $row ? (int)$row['value'] : 1;
        $invoiceNumber = sprintf("INV-%s-%05d", $year, $next);

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
