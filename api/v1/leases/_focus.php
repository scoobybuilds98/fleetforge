<?php
declare(strict_types=1);

/**
 * FleetForge — lease "focus" windows (S-LIST-COMPACT)
 *
 * @file        api/v1/leases/_focus.php
 * @description The four lists a dispatcher works from, defined ONCE so the
 *              leases summary strip (api/v1/leases/kpis.php) and the list it
 *              filters (api/v1/leases/index.php ?focus=…) can never disagree
 *              on a count:
 *                ending    active, end date within 30 days (or already past)
 *                overdue   active, end date already past — the unit should
 *                          be back
 *                starting  pending, start date within 7 days (or already
 *                          past — activation is late)
 *                unbilled  active, invoice coverage behind today
 *              Not routable (leading underscore); included by the two
 *              endpoints above.
 *
 *              Dates: $today is the company-local business day (ff_today());
 *              lease start/end are DATE columns holding local days, so the
 *              windows are bound values — never SQL CURDATE() (the UTC day).
 *
 *              "unbilled" uses the same LIVE coverage as the list's Billed
 *              Thru column: MAX(billing_period_end) over non-void, non-deleted
 *              invoices — NOT leases.last_billed_date, which is not walked
 *              back when an invoice is voided (S-CLOSE-ZEROBILL).
 *
 * @session     S-LIST-COMPACT
 */

/** SQL for a lease's live invoice coverage (alias `l` = leases). */
const FF_LEASE_BILLED_THROUGH_SQL =
    "(SELECT MAX(i.billing_period_end)
        FROM invoices i
       WHERE i.lease_id = l.id
         AND i.deleted_at IS NULL
         AND i.status <> 'void'
         AND i.billing_period_end IS NOT NULL)";

/** The focus keys the list and the strip understand. */
const FF_LEASE_FOCUS_KEYS = ['ending', 'overdue', 'starting', 'unbilled'];

if (!function_exists('ff_lease_focus_sql')) {
    /**
     * WHERE fragment + bound params for one focus window, or null for an
     * unknown key (the caller then applies no focus filter).
     *
     * @return array{0:string,1:list<string>}|null
     */
    function ff_lease_focus_sql(string $focus, string $today): ?array
    {
        $in30 = date('Y-m-d', strtotime($today . ' +30 days'));
        $in7  = date('Y-m-d', strtotime($today . ' +7 days'));
        return match ($focus) {
            'ending'   => ["l.status = 'active' AND l.end_date IS NOT NULL AND l.end_date <= ?", [$in30]],
            'overdue'  => ["l.status = 'active' AND l.end_date IS NOT NULL AND l.end_date < ?", [$today]],
            'starting' => ["l.status = 'pending' AND l.start_date <= ?", [$in7]],
            'unbilled' => ["l.status = 'active' AND COALESCE(" . FF_LEASE_BILLED_THROUGH_SQL . ", '1000-01-01') < ?", [$today]],
            default    => null,
        };
    }
}
