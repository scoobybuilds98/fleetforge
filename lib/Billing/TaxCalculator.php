<?php
declare(strict_types=1);

/**
 * lib/Billing/TaxCalculator.php
 *
 * Tax calculation engine for invoices. Looks up current tax rates from
 * the tax_rates table at invoice time (D11 — never frozen on lease).
 * Respects independent gst_exempt and pst_exempt flags (D22).
 * All monetary values are bcmath strings (D16).
 *
 * Required by: lib/Billing/InvoiceGenerator.php
 * Requires: includes/db.php (db_row)
 * Defines: FleetForge\Billing\TaxCalculator
 *
 * Decisions: D11 (invoice-time tax lookup), D16 (bcmath), D22 (granular exemptions)
 * Spec ref: §9 Tax Rules, PASS-13:T1, PASS-13:T2
 */

namespace FleetForge\Billing;

class TaxCalculator
{
    /**
     * Calculate tax amounts for a given subtotal.
     *
     * Looks up tax rates from tax_rates table by province.
     * GST exemption suppresses both GST and HST (they are federal taxes).
     * PST exemption suppresses PST only.
     * Both flags are independent — a customer can be GST-exempt but PST-liable.
     *
     * @param string   $subtotal      Pre-tax amount after discounts, as bcmath string
     * @param string   $province      Two-letter province code (e.g. 'BC', 'ON', 'AB')
     * @param bool     $gstExempt     If true, GST and HST are zero
     * @param bool     $pstExempt     If true, PST is zero
     * @param string   $country       ISO country code for logging context (default 'CA')
     * @param int|null $customerId    Customer ID for logging context (optional)
     * @param string   $customerName  Customer name for logging context (optional)
     * @return array{
     *   gst_rate: string, pst_rate: string, hst_rate: string,
     *   gst: string, pst: string, hst: string, total: string,
     *   gst_exempt: bool, pst_exempt: bool
     * }
     */
    public function calculate(
        string $subtotal,
        string $province,
        bool $gstExempt,
        bool $pstExempt,
        string $country = 'CA',
        ?int $customerId = null,
        string $customerName = '',
        ?string $transactionDate = null
    ): array {
        // Look up current active tax rate for province (D11: at invoice time).
        // S-ACCT-POS: optional $transactionDate drives effective-window filter
        // so backdated invoices resolve to the correct historic rate (e.g. NS
        // HST 15% pre-2025-04-01 vs 14% from 2025-04-01). Default = today
        // preserves backward compatibility with all existing callers.
        $rates = $this->lookupRates($province, $country, $customerId, $customerName, $transactionDate);

        $gstRate = $rates['gst_rate'] ?? '0.0000';
        $pstRate = $rates['pst_rate'] ?? '0.0000';
        $hstRate = $rates['hst_rate'] ?? '0.0000';

        // Calculate each tax component
        // WHY: tax_rates stores rates as decimal fractions already (0.0500 = 5%),
        // so we multiply directly — no division by 100.
        // GST exemption suppresses both GST and HST (both are federal)
        $gstAmount = '0.00';
        if (!$gstExempt && bccomp($gstRate, '0', 4) > 0) {
            $gstAmount = bcround(bcmul($subtotal, $gstRate, 6), 2);
        }

        $hstAmount = '0.00';
        if (!$gstExempt && bccomp($hstRate, '0', 4) > 0) {
            $hstAmount = bcround(bcmul($subtotal, $hstRate, 6), 2);
        }

        // PST exemption is independent of GST (D22)
        $pstAmount = '0.00';
        if (!$pstExempt && bccomp($pstRate, '0', 4) > 0) {
            $pstAmount = bcround(bcmul($subtotal, $pstRate, 6), 2);
        }

        // Total tax
        $total = bcadd(bcadd($gstAmount, $pstAmount, 2), $hstAmount, 2);

        return [
            'gst_rate'   => $gstRate,
            'pst_rate'   => $pstRate,
            'hst_rate'   => $hstRate,
            'gst'        => $gstAmount,
            'pst'        => $pstAmount,
            'hst'        => $hstAmount,
            'total'      => $total,
            'gst_exempt' => $gstExempt,
            'pst_exempt' => $pstExempt,
        ];
    }

    /**
     * Look up active tax rates for a province from the database.
     *
     * Returns the most recently effective rate that is active.
     * Falls back to zero rates if no rate found, and logs a warning so
     * operators can catch missing provinces before they become CRA exposures.
     *
     * @param string   $province     Two-letter province code
     * @param string   $country      ISO country code for log context
     * @param int|null $customerId   Customer ID for log context
     * @param string   $customerName Customer name for log context
     * @return array{gst_rate: string, pst_rate: string, hst_rate: string}
     */
    private function lookupRates(
        string $province,
        string $country = 'CA',
        ?int $customerId = null,
        string $customerName = '',
        ?string $transactionDate = null
    ): array {
        // S-ACCT-POS: bind transaction_date for backdated-invoice correctness.
        // Also filter on effective_to so historic rates with a closed window
        // (NS HST 15% effective_to=2025-03-31) don't bleed into post-window
        // transactions. BC's row has effective_to=NULL so the IS NULL guard
        // keeps it eligible.
        $date = $transactionDate ?: date('Y-m-d');
        $row = db_row(
            "SELECT gst_rate, pst_rate, hst_rate
             FROM tax_rates
             WHERE province = ? AND is_active = 1
               AND (effective_from IS NULL OR effective_from <= ?)
               AND (effective_to   IS NULL OR effective_to   >= ?)
             ORDER BY effective_from DESC
             LIMIT 1",
            [$province, $date, $date]
        );

        if (!$row) {
            error_log(sprintf(
                'TAX_RATE_MISSING: province=%s country=%s customer_id=%s customer_name=%s'
                . ' — billed at $0 tax. This may be a CRA exposure.',
                $province,
                $country,
                $customerId !== null ? $customerId : 'unknown',
                $customerName !== '' ? $customerName : 'unknown'
            ));
            // S-AUDIT-BILLING-ENGINE-1 #24: a $0-tax fail-open is a CRA
            // exposure — alert, don't just log. Sentry must never break billing.
            try {
                \FleetForge\Observability\Sentry::captureMessage(
                    "TAX_RATE_MISSING: province={$province} — invoice billed at \$0 tax (fail-open)",
                    'warning'
                );
            } catch (\Throwable $ignored) {
            }
            return [
                'gst_rate' => '0.000000',
                'pst_rate' => '0.000000',
                'hst_rate' => '0.000000',
            ];
        }

        return [
            'gst_rate' => (string)$row['gst_rate'],
            'pst_rate' => (string)$row['pst_rate'],
            'hst_rate' => (string)$row['hst_rate'],
        ];
    }
}
