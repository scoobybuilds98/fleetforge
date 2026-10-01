<?php
declare(strict_types=1);

/**
 * lib/Billing/OdometerChain.php
 *
 * Where a lease's odometer stands at the end of its billed periods — the ONE
 * definition of "the previous reading" that every mileage biller counts from.
 *
 * A live invoice carries a lease's mileage in one of two shapes:
 *   • a real end reading (odometer_at_period_end_km) — typed, from the billing
 *     cycle's Readings tab, a manual-lease distance turned into a reading, or
 *     the closing odometer; and
 *   • a DISTANCE with no end reading (period_distance_km only) — the Samsara
 *     GPS fallback in InvoiceGenerator, or a distance typed on a Samsara lease
 *     (S-INVOICE-DISTANCE-ENTRY).
 * The odometer position after an invoice is therefore the latest real end
 * reading PLUS the distance of every later distance-only invoice. Counting
 * from the real reading alone (what every lookup did before
 * S-SAMSARA-CLOSE-DISTANCE-CHAIN) skipped the distance-only months, so the
 * next reading — the closing odometer at lease close, a reading typed on
 * Generate Invoice, the monthly cron's cached odometer — billed those months'
 * driving a SECOND time.
 *
 * Rules (each invoice: live = not void, not deleted):
 *   • a real end reading resets the position to that reading;
 *   • a distance-only invoice adds its distance to the position — but only a
 *     rental invoice: an 'adjustment' / 'credit_note' / 'mileage_only' invoice
 *     never bills a mileage_usage line, so a GPS distance stored on it (the
 *     close's return-day closeout adjustment fetches one) was never billed and
 *     must not move the odometer;
 *   • before any real reading the position starts at the lease's starting
 *     odometer. When that start was CAPTURED LATE (odometer_start_fetched_at —
 *     a Samsara activation reads the live odometer, even for a back-dated
 *     lease, and "Fetch from Samsara" on the lease does the same), distance-only
 *     invoices whose period ended before the capture date are already inside
 *     the start and are skipped. With no starting odometer a distance-only
 *     invoice has nothing to add to and is skipped until the first real reading;
 *   • invoices with neither (e.g. activation Invoice 1, advance invoices)
 *     don't move it.
 * Each position also carries floor_km: the last REAL value under it (reading
 * or start) — the hard lower bound for a new reading, since a GPS-summed
 * position can run a little above the true odometer.
 * Ordering is billing_period_end, then id — the order the periods were driven.
 *
 * Required by: lib/Billing/Cycle/CycleReadings.php (previousReadings),
 *              api/v1/leases/close.php, api/v1/leases/show.php,
 *              cron/invoice_generate_monthly.php
 * Defines:     FleetForge\Billing\OdometerChain
 *
 * Decisions: D16 (bcmath — billed distance), D-ODOMETER-CHAIN-1
 * Session:   S-SAMSARA-CLOSE-DISTANCE-CHAIN
 */

namespace FleetForge\Billing;

final class OdometerChain
{
    /**
     * Each lease's odometer position at the end of its last live invoice
     * whose period ended BEFORE $before (exclusive) — the reading the next
     * period counts from. Leases with no qualifying invoice are absent (the
     * caller falls back to the lease's starting odometer, as before).
     *
     * @param  int[]  $leaseIds
     * @param  string $before   Y-m-d; only invoices with billing_period_end < this count.
     * @return array<int, array{km: string, from: string, invoice_number: string, derived: bool, floor_km: string}>
     *         km = position (2dp); from = "INV-… (Y-m-d)" of the last contributing
     *         invoice; derived = true when distance-only invoices were added to a
     *         reading (the value is not one anybody read off the odometer);
     *         floor_km = the last real reading/start under it.
     */
    public static function positionsBefore(array $leaseIds, string $before): array
    {
        $out = [];
        foreach (self::walk($leaseIds, $before) as $leaseId => $entries) {
            if ($entries) {
                $out[$leaseId] = $entries[count($entries) - 1];
                unset($out[$leaseId]['period_end']);
            }
        }
        return $out;
    }

    /**
     * The whole running chain for one lease, oldest first: one entry per live
     * invoice that moved the position. Generate Invoice picks, client-side,
     * the last entry before the period it is billing.
     *
     * @param  int $leaseId
     * @return array<int, array{period_end: string, km: string, from: string, invoice_number: string, derived: bool, floor_km: string}>
     */
    public static function chain(int $leaseId): array
    {
        return self::walk([$leaseId], null)[$leaseId] ?? [];
    }

    /**
     * Walk each lease's live invoices in period order and record the position
     * after every invoice that moves it (see the class docblock for the rules).
     *
     * @param  int[]       $leaseIds
     * @param  string|null $before   exclusive billing_period_end bound, or null for all
     * @return array<int, array<int, array{period_end: string, km: string, from: string, invoice_number: string, derived: bool, floor_km: string}>>
     */
    private static function walk(array $leaseIds, ?string $before): array
    {
        $leaseIds = array_values(array_unique(array_map('intval', $leaseIds)));
        if (!$leaseIds) {
            return [];
        }
        $ph     = implode(',', array_fill(0, count($leaseIds), '?'));
        $params = $leaseIds;
        $bound  = '';
        if ($before !== null) {
            $bound    = ' AND i.billing_period_end < ?';
            $params[] = $before;
        }

        // Lease starting odometers seed the position before the first reading,
        // with the business date they were captured on (UTC stamp → local day).
        $starts   = [];
        $captured = [];
        foreach (\db_select("SELECT id, odometer_start_km, odometer_start_fetched_at FROM leases WHERE id IN ({$ph})", $leaseIds) as $l) {
            $starts[(int) $l['id']]   = $l['odometer_start_km'] !== null ? (string) $l['odometer_start_km'] : null;
            $captured[(int) $l['id']] = !empty($l['odometer_start_fetched_at'])
                ? \ff_utc_to_local((string) $l['odometer_start_fetched_at'], 'Y-m-d')
                : null;
        }

        $rows = \db_select(
            "SELECT i.lease_id, i.invoice_number, i.billing_period_end, i.billing_type,
                    i.odometer_at_period_end_km, i.period_distance_km
               FROM invoices i
              WHERE i.deleted_at IS NULL AND i.status <> 'void'
                AND i.lease_id IN ({$ph}){$bound}
                AND (i.odometer_at_period_end_km IS NOT NULL OR i.period_distance_km IS NOT NULL)
              ORDER BY i.lease_id ASC, i.billing_period_end ASC, i.id ASC",
            $params
        );

        $out = [];
        // leaseId => ['km' => ?string, 'derived' => bool, 'floor' => ?string, 'on_start' => bool]
        $pos = [];
        foreach ($rows as $r) {
            $lid = (int) $r['lease_id'];
            $out[$lid] ??= [];
            $pos[$lid] ??= [
                'km' => $starts[$lid] ?? null, 'derived' => false,
                'floor' => $starts[$lid] ?? null, 'on_start' => true,
            ];

            if ($r['odometer_at_period_end_km'] !== null) {
                // A real reading is authoritative: it already includes every
                // kilometre driven up to it, distance-only months included.
                $reading   = (string) $r['odometer_at_period_end_km'];
                $pos[$lid] = ['km' => $reading, 'derived' => false, 'floor' => $reading, 'on_start' => false];
            } elseif ($pos[$lid]['km'] === null) {
                // No starting odometer and no reading yet — nothing to add to.
                continue;
            } elseif (in_array((string) $r['billing_type'], ['adjustment', 'credit_note', 'mileage_only'], true)) {
                // Stored but never billed as usage (see the class docblock).
                continue;
            } elseif ($pos[$lid]['on_start'] && $captured[$lid] !== null
                && (string) $r['billing_period_end'] < $captured[$lid]) {
                // Driven before the starting odometer was read — already in it.
                continue;
            } else {
                // Distance-only month: the odometer moved by the billed distance.
                $pos[$lid]['km']      = bcadd($pos[$lid]['km'], (string) $r['period_distance_km'], 2);
                $pos[$lid]['derived'] = true;
            }

            $out[$lid][] = [
                'period_end'     => (string) $r['billing_period_end'],
                'km'             => bcadd($pos[$lid]['km'], '0', 2),
                'from'           => $r['invoice_number'] . ' (' . $r['billing_period_end'] . ')',
                'invoice_number' => (string) $r['invoice_number'],
                'derived'        => $pos[$lid]['derived'],
                'floor_km'       => bcadd((string) $pos[$lid]['floor'], '0', 2),
            ];
        }
        return $out;
    }
}
