<?php
declare(strict_types=1);

/**
 * lib/Billing/CreditEngine.php
 *
 * Pure math engine for applying credit notes to an invoice total.
 * No DB access — receives inputs, returns computed amounts.
 * The caller (api/v1/credit_notes/apply.php) is responsible for all DB writes.
 *
 * Spec ref: FLEETFORGE_SPEC_FINAL.md §9 Billing Engine — CreditEngine
 * Decision: D16 (bcmath only — all params and returns are strings)
 * Decision: D18 (currency match enforced by caller before calling this class)
 *
 * @package FleetForge\Billing
 * @session S011
 */

namespace FleetForge\Billing;

class CreditEngine
{
    /**
     * Apply a list of credit notes to an invoice total.
     *
     * Each element of $credits is an associative array:
     *   'credit_note_id'  => int    — for reference in the return value
     *   'amount_available' => string — bcmath string, how much of this credit is usable
     *
     * Credits are consumed in the order provided (caller decides priority — e.g. oldest first).
     * Each credit is consumed up to min(amount_available, remaining_balance_due).
     *
     * WHY scale=6 intermediate: prevents sub-cent rounding errors when multiple credits
     * accumulate. Final values are rounded to scale=2 for storage.
     *
     * @param  string  $invoiceTotal  Invoice total_amount (bcmath string, positive)
     * @param  array   $credits       Array of ['credit_note_id'=>int, 'amount_available'=>string]
     * @return array {
     *     credits_applied: string       — total applied across all credits (2-decimal bcmath)
     *     balance_due:     string       — invoice total minus total credits applied (≥ 0.00)
     *     applied_per_credit: array     — per-credit breakdown for credit_note_applications rows
     *       each: ['credit_note_id'=>int, 'amount_applied'=>string]
     * }
     */
    public function apply(string $invoiceTotal, array $credits): array
    {
        // Running balance — how much of the invoice is still unpaid
        $remaining = $invoiceTotal;

        $totalApplied     = '0.000000';
        $appliedPerCredit = [];

        foreach ($credits as $credit) {
            // Stop early if invoice is already fully covered
            if (bccomp($remaining, '0', 6) <= 0) {
                break;
            }

            $available = (string) ($credit['amount_available'] ?? '0');

            // Cannot apply more than what the credit has OR what the invoice still owes
            $toApply = bccomp($available, $remaining, 6) <= 0
                ? $available
                : $remaining;

            // Only record this credit if it actually covers something
            if (bccomp($toApply, '0', 6) <= 0) {
                continue;
            }

            $totalApplied = bcadd($totalApplied, $toApply, 6);
            $remaining    = bcsub($remaining, $toApply, 6);

            $appliedPerCredit[] = [
                'credit_note_id' => (int) $credit['credit_note_id'],
                'amount_applied' => bcround($toApply, 2),
            ];
        }

        // Clamp — defensive guard against sub-cent float-like residuals
        if (bccomp($remaining, '0', 6) < 0) {
            $remaining = '0.000000';
        }

        return [
            'credits_applied'   => bcround($totalApplied, 2),
            'balance_due'       => bcround($remaining, 2),
            'applied_per_credit' => $appliedPerCredit,
        ];
    }
}
