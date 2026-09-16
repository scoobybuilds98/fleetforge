<?php
declare(strict_types=1);

/**
 * lib/Accounting/VendorSpend.php
 *
 * Single source of truth for a vendor's "Total Spent" (vendors.total_spent).
 *
 * WHY THIS EXISTS (bug #7 — double-counted vendor spend):
 *   total_spent used to be a blind running counter bumped from two places for
 *   the SAME money — +total_cost when a work order completed
 *   (StatusActions::changeWorkOrderStatus) and +total_amount again when the
 *   vendor's bill for that work was approved (bills/approve.php, or
 *   bills/create.php with auto_approve). A $1,000 repair therefore showed as
 *   $2,000 spent. Void subtracted with a GREATEST(0, …) clamp, so the counter
 *   could also silently drift low.
 *
 * THE RULE (derived, never incremented):
 *   total_spent =
 *       Σ counted AP bills for the vendor
 *         (status approved / scheduled / partially_paid / paid — i.e. a posted
 *          AP liability; draft and void are not spend), converted to CAD with
 *          the bill's frozen exchange_rate_to_cad when the bill is non-CAD and
 *          a rate is on file (CAD is canonical per the reporting policy)
 *     + Σ completed, non-deleted work orders for the vendor that NO counted bill
 *          from the SAME vendor covers (acc_bills.work_order_id).
 *   Approved bills are the AP truth; a work order's cost is only a stand-in
 *   until the vendor's bill for it is approved, at which point the bill amount
 *   replaces it (never adds to it). A bill from a DIFFERENT vendor linked to the
 *   work order (e.g. a parts supplier) does not un-count the servicing vendor's
 *   work-order cost.
 *
 * The column stays (it is sortable in the vendor list and read by the AI tools),
 * but every writer now calls recompute() instead of adding/subtracting, so the
 * stored value can never double-count or drift, and recomputeAll() repairs any
 * value that drifted under the old counter logic (idempotent).
 *
 * GL note: work-order completion posts NO journal entry (only the AP bill
 * approval JE hits the ledger), so the double count was confined to this
 * counter — the general ledger was not double-posted.
 *
 * Used by: lib/AI/Actions/StatusActions.php (WO completion),
 *          api/v1/accounting/bills/{create,approve,void}.php,
 *          scripts/recompute_vendor_total_spent.php (drift repair CLI)
 *
 * Money: SUMs run on DECIMAL columns in MySQL (exact) and are combined with
 * bcmath strings — never floats (D16).
 */

namespace FleetForge\Accounting;

class VendorSpend
{
    /** Bill statuses that represent a posted AP liability (counted as spend). */
    public const COUNTED_BILL_STATUSES = ['approved', 'scheduled', 'partially_paid', 'paid'];

    /**
     * Compute a vendor's canonical total spend from the underlying records.
     *
     * @param int $vendorId vendors.id
     * @return array{bills:string, unbilled_work_orders:string, total:string}
     *         bcmath-safe 2dp strings
     */
    public static function breakdown(int $vendorId): array
    {
        $statusIn = self::statusPlaceholders();

        // Bills: non-CAD bills convert with their frozen rate when one is on file;
        // otherwise the face amount is used (same basis the AP JE posts at today).
        $bills = \db_row(
            "SELECT COALESCE(SUM(
                        CASE WHEN b.currency <> 'CAD' AND b.exchange_rate_to_cad IS NOT NULL
                                  AND b.exchange_rate_to_cad > 0
                             THEN ROUND(b.total_amount * b.exchange_rate_to_cad, 2)
                             ELSE b.total_amount END
                    ), 0.00) AS total
               FROM acc_bills b
              WHERE b.vendor_id = ?
                AND b.status IN ({$statusIn})",
            array_merge([$vendorId], self::COUNTED_BILL_STATUSES)
        );

        // Completed work orders not yet covered by one of this vendor's counted bills.
        $workOrders = \db_row(
            "SELECT COALESCE(SUM(w.total_cost), 0.00) AS total
               FROM maintenance_work_orders w
              WHERE w.vendor_id = ?
                AND w.status = 'completed'
                AND w.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM acc_bills b
                     WHERE b.work_order_id = w.id
                       AND b.vendor_id = w.vendor_id
                       AND b.status IN ({$statusIn})
                )",
            array_merge([$vendorId], self::COUNTED_BILL_STATUSES)
        );

        $billsTotal = bcadd((string) ($bills['total'] ?? '0'), '0', 2);
        $woTotal    = bcadd((string) ($workOrders['total'] ?? '0'), '0', 2);

        return [
            'bills'                => $billsTotal,
            'unbilled_work_orders' => $woTotal,
            'total'                => bcadd($billsTotal, $woTotal, 2),
        ];
    }

    /**
     * Canonical total spend for one vendor (see class docblock for the rule).
     *
     * @param int $vendorId vendors.id
     * @return string bcmath 2dp string, e.g. "1234.50"
     */
    public static function compute(int $vendorId): string
    {
        return self::breakdown($vendorId)['total'];
    }

    /**
     * Recompute and store vendors.total_spent for one vendor.
     * Call this (inside the writer's transaction) after ANY change that can move
     * spend: work-order completion, bill approve / auto-approve / void.
     *
     * @param int $vendorId vendors.id
     * @return array{before:string, after:string}
     */
    public static function recompute(int $vendorId): array
    {
        $row    = \db_row("SELECT total_spent FROM vendors WHERE id = ?", [$vendorId]);
        $before = bcadd((string) ($row['total_spent'] ?? '0'), '0', 2);
        $after  = self::compute($vendorId);

        if ($row !== null && bccomp($before, $after, 2) !== 0) {
            \db_execute("UPDATE vendors SET total_spent = ? WHERE id = ?", [$after, $vendorId]);
        }

        return ['before' => $before, 'after' => $after];
    }

    /**
     * Recompute every vendor (including soft-deleted rows, so a restored vendor
     * is never stale). Idempotent — running it twice changes nothing the second
     * time.
     *
     * @param bool $apply false = dry run (report only, no writes)
     * @return array<int, array{id:int, name:string, before:string, after:string,
     *                           bills:string, unbilled_work_orders:string}>
     *         only the vendors whose stored value differs from the canonical one
     */
    public static function recomputeAll(bool $apply = true): array
    {
        $changed = [];
        foreach (\db_select("SELECT id, name, total_spent FROM vendors ORDER BY id") as $v) {
            $id     = (int) $v['id'];
            $before = bcadd((string) $v['total_spent'], '0', 2);
            $parts  = self::breakdown($id);
            if (bccomp($before, $parts['total'], 2) === 0) {
                continue;
            }
            if ($apply) {
                \db_execute("UPDATE vendors SET total_spent = ? WHERE id = ?", [$parts['total'], $id]);
            }
            $changed[] = [
                'id'                   => $id,
                'name'                 => (string) $v['name'],
                'before'               => $before,
                'after'                => $parts['total'],
                'bills'                => $parts['bills'],
                'unbilled_work_orders' => $parts['unbilled_work_orders'],
            ];
        }
        return $changed;
    }

    /**
     * "?, ?, ?, ?" placeholder list sized to COUNTED_BILL_STATUSES.
     *
     * @return string
     */
    private static function statusPlaceholders(): string
    {
        return implode(', ', array_fill(0, count(self::COUNTED_BILL_STATUSES), '?'));
    }
}
