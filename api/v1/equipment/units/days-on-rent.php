<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/days-on-rent.php
 *
 * Returns how many days a single equipment unit was ON RENT over a
 * date window, plus utilization %, idle days and the contributing
 * lease count. Backs the "Days on Rent" panel on the Lease History
 * tab of the unit command center (app/admin/equipment/show.php →
 * loadDaysOnRent()).
 *
 * WHY A SERVER ENDPOINT AND NOT A CLIENT-SIDE SUM
 * The Lease History table this panel sits above is paginated
 * (per_page=50) and status-filtered by an operator dropdown. Summing
 * `leaseHistory` in JS would silently under-count on units with >50
 * leases and would change whenever the operator touched an unrelated
 * filter. The window must be resolved and aggregated server-side.
 *
 * SEMANTICS (S-DAYS-ON-RENT, operator-locked 2026-07-21):
 *
 *  - CALENDAR OCCUPANCY, not billed days. This answers "was the unit
 *    out of the yard", not "did we invoice for it". Deliberately
 *    ignores `leases.billing_days_removed` (S-LEASE-CLOSE-REMOVE-DAYS)
 *    and HolisticLeaseEngine::effectiveBillableEndDate()'s return-grace
 *    day-drop — both shorten the BILLED extent without moving any
 *    displayed date, so an operator reconciling this tile against an
 *    invoice may see a legitimate 1..N day difference. Billed-days is a
 *    materially different metric; do not quietly fold it in here.
 *
 *  - END-DATE LADDER: actual_return_date → end_date → today. This is
 *    the billing engine's canonical precedence (InvoiceGenerator.php
 *    :296-315, _close_reconciliation.php:47-65). Three older surfaces
 *    disagree and are NOT copied: api/v1/reports/fleet.php:91-108 omits
 *    end_date, app/admin/equipment/payoff.php:278-302 omits
 *    actual_return_date. The ladder is load-bearing — completed leases
 *    with a NULL actual_return_date exist, and under fleet.php's ladder
 *    they would read as still-on-rent through the window end.
 *
 *  - DAYS ARE INCLUSIVE OF BOTH ENDPOINTS. A lease 2026-03-01 →
 *    2026-03-01 is 1 day. Matches HolisticLeaseEngine::inclusiveDays()
 *    (the D14 canonical) and the DATEDIFF(end,start)+1 idiom used
 *    throughout.
 *
 *  - INTERVALS ARE MERGED, NOT SUMMED. Nothing in the app validates a
 *    new lease's date range against the unit's other leases —
 *    api/v1/leases/create.php gates on the unit's CURRENT status, which
 *    is date-blind, so back-dated leases can overlap. A unit cannot be
 *    on rent twice on the same day, so overlapping spells are merged;
 *    this also makes days_on_rent <= window_days structural rather than
 *    something to clamp after the fact (payoff.php:299 clamps). The
 *    unmerged total ships as `raw_lease_day_sum` + `overlap_adjusted`
 *    so the UI can explain why the number is lower than what an
 *    operator gets adding up the rows in the table below it.
 *
 *  - STATUS ALLOWLIST active+completed. `pending` has not started;
 *    `cancelled` never happened. (payoff.php uses status != 'cancelled',
 *    which silently counts pending — not copied.)
 *
 *  - "Since added" anchors on DATE(equipment_units.created_at), which is
 *    NOT NULL on every unit. `acquired_date` would be the truer "since
 *    we bought it" anchor but is NULL fleet-wide until backfilled.
 *
 * TIMEZONE: "today" is PHP date('Y-m-d') — the Vancouver business date
 * per config/app.php:148 — bound as a parameter. NEVER CURDATE(): the
 * MySQL session is pinned to UTC (includes/db.php:144) and the two
 * diverge 7-8h daily. Mixing them is a recurring bug class here (S010,
 * S021, S-RATELIMITER-TZ-FIX).
 *
 * @method  GET
 * @params  unit_id (required, int) — equipment unit ID
 *          preset (optional) — since_added|last_year|this_year|custom,
 *                              default since_added
 *          date_from, date_to (Y-m-d) — required when preset=custom
 * @auth    Session required; require_permission('equipment','view')
 * @returns 200 { unit_id, unit_number, preset, date_from, date_to,
 *          window_days, days_on_rent, idle_days, utilization_pct,
 *          lease_count, raw_lease_day_sum, overlap_adjusted,
 *          anchor_date, anchor_source, periods: [{start,end,days}] }
 *
 * @depends api/bootstrap.php, lib/Billing/HolisticLeaseEngine.php
 * @session S-DAYS-ON-RENT
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Billing\HolisticLeaseEngine;

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

$unitId = clean_int($_GET['unit_id'] ?? null);
if (!$unitId) {
    json_error('VALIDATION_ERROR', 'unit_id is required.', 422);
}

$unit = db_row(
    "SELECT id, unit_number, created_at, acquired_date
       FROM equipment_units
      WHERE id = ? AND deleted_at IS NULL",
    [$unitId]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

/* ── Resolve the window ──────────────────────────────────────────────
   $today is the ceiling for every preset. "This year" from
   ReportBuilder would otherwise end 2026-12-31 — a denominator that is
   mostly unelapsed, which would read as a bogus low utilization. */
$today = date('Y-m-d');

/**
 * Validates a Y-m-d query param, returning the normalized date or null.
 *
 * WHY strict: the value lands in a date comparison and in the response,
 * so a half-parsed '2026-13-45' must fail loudly at 422 rather than
 * silently resolving to some other month via DateTime's rollover.
 */
function ff_dor_valid_date(mixed $val): ?string
{
    $s = clean_string($val, 10);
    if ($s === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return $s;
}

$preset = clean_string($_GET['preset'] ?? '', 32) ?? '';
if (!in_array($preset, ['since_added', 'last_year', 'this_year', 'custom'], true)) {
    $preset = 'since_added';
}

$anchorDate   = substr((string) $unit['created_at'], 0, 10);
$anchorSource = 'created_at';

$thisYear = (int) date('Y');

switch ($preset) {
    case 'this_year':
        $from = $thisYear . '-01-01';
        $to   = $today;
        break;

    case 'last_year':
        $from = ($thisYear - 1) . '-01-01';
        $to   = ($thisYear - 1) . '-12-31';
        break;

    case 'custom':
        $from = ff_dor_valid_date($_GET['date_from'] ?? null);
        $to   = ff_dor_valid_date($_GET['date_to'] ?? null);
        if ($from === null || $to === null) {
            json_error('VALIDATION_ERROR', 'date_from and date_to must be valid YYYY-MM-DD dates.', 422);
        }
        if ($from > $to) {
            json_error('VALIDATION_ERROR', 'date_from must not be after date_to.', 422);
        }
        // Future days can't have been on rent; they'd only dilute the
        // utilization denominator.
        if ($to > $today) {
            $to = $today;
        }
        break;

    case 'since_added':
    default:
        $from = $anchorDate;
        $to   = $today;
        break;
}

// A unit added today (or a back-dated lease predating the unit row)
// must not produce an inverted window.
if ($from > $to) {
    $from = $to;
}

/* ── Fetch leases clipped to the window ──────────────────────────────
   The SQL only CLIPS; merging happens in PHP because MySQL can't merge
   intervals without window functions, and the merged spells are wanted
   in the payload anyway.

   The inner GREATEST(..., l.start_date) guards a real inversion case:
   api/v1/leases/update.php lets end_date be edited post-activation with
   no re-validation against start_date, so end < start is reachable. */
$rows = db_select(
    "SELECT
        l.id,
        l.contract_number,
        l.status,
        l.start_date,
        l.end_date,
        l.actual_return_date,
        GREATEST(l.start_date, ?) AS eff_start,
        LEAST(
            GREATEST(COALESCE(l.actual_return_date, l.end_date, ?), l.start_date),
            ?
        ) AS eff_end
       FROM leases l
      WHERE l.equipment_unit_id = ?
        AND l.deleted_at IS NULL
        AND l.status IN ('active', 'completed')
        AND l.start_date <= ?
        AND COALESCE(l.actual_return_date, l.end_date, ?) >= ?
      ORDER BY eff_start ASC, eff_end ASC",
    [$from, $today, $to, $unitId, $to, $today, $from]
);

/* ── Merge overlapping / adjacent spells ─────────────────────────────
   Y-m-d strings compare lexicographically == chronologically, so the
   ordering needs no DateTime objects.

   Adjacency (+1 day) rather than strict overlap: back-to-back leases
   (Jan 1-31, Feb 1-28) are one continuous 59-day occupancy spell, and
   presenting them as two spells in `periods` would be misleading. Both
   rules yield the same day TOTAL — the difference is only in how
   `periods` reads. */
$merged        = [];
$rawDaySum     = 0;
$contributing  = 0;

foreach ($rows as $r) {
    $s = (string) $r['eff_start'];
    $e = (string) $r['eff_end'];
    if ($e < $s) {
        continue; // defensive; SQL already clamps
    }
    $contributing++;
    $rawDaySum += HolisticLeaseEngine::inclusiveDays($s, $e);

    $n = count($merged);
    if ($n === 0) {
        $merged[] = [$s, $e];
        continue;
    }

    $gapFree = (new DateTimeImmutable($merged[$n - 1][1]))->modify('+1 day')->format('Y-m-d');
    if ($s <= $gapFree) {
        if ($e > $merged[$n - 1][1]) {
            $merged[$n - 1][1] = $e;   // extend the open spell
        }
    } else {
        $merged[] = [$s, $e];          // disjoint spell
    }
}

$periods    = [];
$daysOnRent = 0;
foreach ($merged as [$s, $e]) {
    $d = HolisticLeaseEngine::inclusiveDays($s, $e);
    $daysOnRent += $d;
    $periods[] = ['start' => $s, 'end' => $e, 'days' => $d];
}

$windowDays = HolisticLeaseEngine::inclusiveDays($from, $to);

json_success([
    'unit_id'           => (int) $unit['id'],
    'unit_number'       => $unit['unit_number'],
    'preset'            => $preset,
    'date_from'         => $from,
    'date_to'           => $to,
    'window_days'       => $windowDays,
    'days_on_rent'      => $daysOnRent,
    'idle_days'         => max(0, $windowDays - $daysOnRent),
    'utilization_pct'   => $windowDays > 0 ? round($daysOnRent / $windowDays * 100, 1) : 0.0,
    'lease_count'       => $contributing,
    // Only differs from days_on_rent when leases overlap — the UI shows
    // a note so the number reconciles against the table below.
    'raw_lease_day_sum' => $rawDaySum,
    'overlap_adjusted'  => $rawDaySum > $daysOnRent,
    'anchor_date'       => $anchorDate,
    'anchor_source'     => $anchorSource,
    'periods'           => $periods,
]);
