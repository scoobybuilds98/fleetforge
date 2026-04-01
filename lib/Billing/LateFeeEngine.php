<?php
declare(strict_types=1);

/**
 * lib/Billing/LateFeeEngine.php
 *
 * Pure math engine for late fee calculation. No DB access, no side effects.
 * Accepts invoice balance and a rule array; returns the fee amount to charge.
 *
 * Called by: lib/Billing/InvoiceGenerator::generateLateFeeInvoice()
 * Requires: ext-bcmath (D16: all monetary math via bcmath, never float)
 * Defines: FleetForge\Billing\LateFeeEngine
 *
 * Decisions: D3 (pure math isolated here), D16 (bcmath only)
 * Spec ref: §9 Late Fees, lib/Billing/LateFeeEngine.php signature
 */

namespace FleetForge\Billing;

class LateFeeEngine
{
    /**
     * Calculate a late fee for an overdue invoice.
     *
     * Rule array keys (from late_fee_rules table):
     *   fee_type       string   'percentage' | 'flat'
     *   fee_value      string   Rate as decimal fraction (e.g. '0.0200' = 2%) or flat amount
     *   max_fee_amount ?string  Maximum fee cap, NULL = no cap
     *
     * D16: All monetary values are strings (bcmath). Never cast to float.
     * D3:  Pure math only — no DB reads, no side effects, fully unit-testable.
     *
     * @param  string $invoiceBalance  Balance due on the overdue invoice (bcmath string)
     * @param  array  $rule            Rule config (fee_type, fee_value, max_fee_amount)
     * @return array{fee_amount: string, fee_type: string}
     */
    public function calculate(string $invoiceBalance, array $rule): array
    {
        $feeType  = $rule['fee_type'];
        $feeValue = (string)($rule['fee_value'] ?? '0');
        $maxFee   = isset($rule['max_fee_amount']) && $rule['max_fee_amount'] !== null
            ? (string)$rule['max_fee_amount']
            : null;

        // Guard: negative or zero balance → no fee
        if (bccomp($invoiceBalance, '0.00', 2) <= 0) {
            return ['fee_amount' => '0.00', 'fee_type' => $feeType];
        }

        if ($feeType === 'percentage') {
            // fee_value is stored as a decimal fraction (e.g. 0.0200 = 2%)
            // Intermediate at scale 6, final rounded to 2
            $feeAmount = bcround(bcmul($invoiceBalance, $feeValue, 6), 2);
        } elseif ($feeType === 'flat') {
            // Flat amount — fee_value is the dollar amount directly
            $feeAmount = bcround($feeValue, 2);
        } else {
            // Unknown fee_type — no fee (defensive; should never reach here)
            $feeAmount = '0.00';
        }

        // Apply maximum cap if configured
        if ($maxFee !== null && bccomp($feeAmount, $maxFee, 2) > 0) {
            $feeAmount = bcround($maxFee, 2);
        }

        // Guard: fee must not be negative
        if (bccomp($feeAmount, '0.00', 2) < 0) {
            $feeAmount = '0.00';
        }

        return [
            'fee_amount' => $feeAmount,
            'fee_type'   => $feeType,
        ];
    }
}
