<?php
declare(strict_types=1);
/**
 * lib/Reports/FleetUtilization.php
 *
 * THE fleet-utilization calculation. Every surface that shows a
 * "utilization over a period" number (Reports → Fleet, Analytics →
 * Utilization Efficiency Matrix, Dashboard → Utilization Trend) calls this
 * class so the three pages can no longer disagree.
 *
 * DEFINITION
 *   utilization = occupied unit-days ÷ available unit-days over the window
 *
 *   - OCCUPIED unit-days: for each unit, the calendar days inside the window on
 *     which at least one active/completed lease had it out. Overlapping leases
 *     on the same unit are MERGED, never summed — nothing validates a new
 *     lease's dates against the unit's other leases (leases/create.php gates on
 *     the unit's CURRENT status), so overlaps are real, and summing them is how
 *     Analytics once showed 357.9% and Reports a 1200% unit.
 *   - End-date ladder actual_return_date → end_date → today, days inclusive of
 *     both endpoints, status allowlist active+completed — identical to the
 *     S-DAYS-ON-RENT panel (api/v1/equipment/units/days-on-rent.php), which now
 *     merges through mergeSpells() below.
 *   - AVAILABLE unit-days: each non-deleted, non-decommissioned unit contributes
 *     the window days from its in-service date onward. In-service date =
 *     the EARLIEST of acquired_date, DATE(created_at) and the unit's first
 *     active/completed lease start. WHY not created_at alone: it is the row
 *     insert date — a deployment that imports its fleet and back-enters lease
 *     history would otherwise have leases on units that "did not exist yet"
 *     (14 such leases in the demo data). WHY not the whole window regardless:
 *     a unit bought in 2025 was not idle in 2023. Occupancy is clipped to the
 *     same availability window, so a unit can never exceed 100%.
 *   - The window END is capped at today: future days cannot have been on rent
 *     and would only dilute the denominator ("This Month" on the 16th).
 *
 * Known limitation: decommissioned / soft-deleted units are excluded from ALL
 * windows (there is no decommissioned_date to know when they left the fleet).
 * This matches the pre-existing Reports behaviour.
 *
 * TIMEZONE: "today" is PHP date('Y-m-d') (business date), bound as a parameter
 * — never CURDATE(), which is the UTC day on this MySQL session.
 *
 * Used by: api/v1/reports/fleet.php, api/v1/analytics/index.php
 *          (utilization_matrix), api/v1/dashboard/charts.php
 *          (utilization_trend), api/v1/equipment/units/days-on-rent.php
 *          (mergeSpells only)
 * Requires: includes/db.php (db_select), lib/Billing/HolisticLeaseEngine.php,
 *           lib/Reports/ReportBuilder.php
 */

namespace FleetForge\Reports;

use FleetForge\Billing\HolisticLeaseEngine;

final class FleetUtilization
{
    /**
     * Cap a window's end at today.
     *
     * @param string      $from  Y-m-d
     * @param string      $to    Y-m-d
     * @param string|null $today Y-m-d business date (defaults to date('Y-m-d'))
     * @return array{0:string,1:string}|null [from, cappedTo]; null when the
     *                                       window lies wholly in the future
     */
    public static function capWindow(string $from, string $to, ?string $today = null): ?array
    {
        $today ??= date('Y-m-d');
        if ($to > $today) {
            $to = $today;
        }
        return $from > $to ? null : [$from, $to];
    }

    /**
     * Ids of the units that make up "the fleet" for utilization purposes:
     * not soft-deleted, not decommissioned.
     *
     * @return int[]
     */
    public static function fleetUnitIds(): array
    {
        $rows = \db_select(
            "SELECT id FROM equipment_units
              WHERE deleted_at IS NULL AND status <> 'decommissioned'
              ORDER BY id"
        );
        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    /**
     * Merge [start, end] spells (Y-m-d, inclusive) that overlap OR touch.
     *
     * Adjacent spells (Jan 1–31 then Feb 1–28) merge into one continuous spell.
     * Either rule gives the same day total; adjacency just reads better when the
     * spells are displayed. Y-m-d strings compare chronologically, so no
     * DateTime objects are needed for ordering. Inverted spells are dropped.
     *
     * @param array<int, array{0:string,1:string}> $spells unsorted is fine
     * @return array<int, array{0:string,1:string}> merged, ascending
     */
    public static function mergeSpells(array $spells): array
    {
        $spells = array_values(array_filter($spells, static fn($s) => $s[1] >= $s[0]));
        usort($spells, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $merged = [];
        foreach ($spells as [$s, $e]) {
            $n = count($merged);
            if ($n > 0) {
                $gapFree = (new \DateTimeImmutable($merged[$n - 1][1]))->modify('+1 day')->format('Y-m-d');
                if ($s <= $gapFree) {
                    if ($e > $merged[$n - 1][1]) {
                        $merged[$n - 1][1] = $e;   // extend the open spell
                    }
                    continue;
                }
            }
            $merged[] = [$s, $e];                  // disjoint spell
        }
        return $merged;
    }

    /**
     * Inclusive days of the given (already merged, non-overlapping) spells that
     * fall inside [$from, $to].
     *
     * @param array<int, array{0:string,1:string}> $merged
     * @param string $from Y-m-d
     * @param string $to   Y-m-d
     * @return int
     */
    public static function clippedDays(array $merged, string $from, string $to): int
    {
        if ($from > $to) {
            return 0;
        }
        $days = 0;
        foreach ($merged as [$s, $e]) {
            $cs = max($s, $from);
            $ce = min($e, $to);
            if ($ce >= $cs) {
                $days += HolisticLeaseEngine::inclusiveDays($cs, $ce);
            }
        }
        return $days;
    }

    /**
     * Utilization for ONE window. Thin wrapper over forWindows().
     *
     * @param string      $from    Y-m-d window start
     * @param string      $to      Y-m-d window end (capped at today)
     * @param int[]|null  $unitIds units to include; null = fleetUnitIds()
     * @param string|null $today   Y-m-d business date
     * @return array{window_from:string, window_to:string, window_days:int,
     *               unit_count:int, occupied_unit_days:int,
     *               available_unit_days:int, raw_lease_day_sum:int,
     *               overlap_adjusted:bool, utilization_pct:string,
     *               units: array<int, array{available_days:int, days_on_rent:int,
     *                      raw_lease_day_sum:int, lease_count:int,
     *                      utilization_pct:string}>}
     */
    public static function forWindow(string $from, string $to, ?array $unitIds = null, ?string $today = null): array
    {
        return self::forWindows([[$from, $to]], $unitIds, $today)[0];
    }

    /**
     * Utilization for several windows (e.g. 12 months) with ONE lease query.
     *
     * Leases are fetched once over the union of the windows, clipped and merged
     * per unit, then each window clips the merged spells. Clipping commutes
     * with merging (clip of a union = union of clips), so this equals running
     * forWindow() per window.
     *
     * @param array<int, array{0:string,1:string}> $windows [from, to] pairs, Y-m-d
     * @param int[]|null  $unitIds units to include; null = fleetUnitIds()
     * @param string|null $today   Y-m-d business date
     * @return array<int, array> one forWindow()-shaped result per input window,
     *                           same order. A window wholly in the future
     *                           yields window_days 0 and 0.0%.
     */
    public static function forWindows(array $windows, ?array $unitIds = null, ?string $today = null): array
    {
        $today   ??= date('Y-m-d');
        $unitIds ??= self::fleetUnitIds();
        $unitIds   = array_values(array_unique(array_map('intval', $unitIds)));

        // Cap every window at today; remember which ones are empty.
        $capped = [];
        foreach ($windows as $i => [$wf, $wt]) {
            $capped[$i] = self::capWindow((string) $wf, (string) $wt, $today);
        }
        $live = array_filter($capped);

        $spellsByUnit = [];
        $rawByUnit    = [];
        $inService    = [];
        if ($unitIds !== [] && $live !== []) {
            $unionFrom = min(array_column($live, 0));
            $unionTo   = max(array_column($live, 1));
            [$spellsByUnit, $rawByUnit] = self::fetchSpells($unionFrom, $unionTo, $unitIds, $today);
            $inService = self::inServiceDates($unitIds);
        }

        $out = [];
        foreach ($capped as $i => $win) {
            $out[$i] = self::summarise($win, $unitIds, $spellsByUnit, $rawByUnit, $inService);
        }
        return $out;
    }

    /**
     * Fetch every active/completed lease touching [$from, $to] for the given
     * units, clipped to the window with the canonical end-date ladder.
     *
     * The inner GREATEST(..., l.start_date) guards an end < start inversion
     * reachable through leases/update.php (same guard as days-on-rent.php).
     *
     * @param string $from    Y-m-d (already capped)
     * @param string $to      Y-m-d (already capped)
     * @param int[]  $unitIds non-empty
     * @param string $today   Y-m-d
     * @return array{0: array<int, array<int, array{0:string,1:string}>>,
     *               1: array<int, array<int, array{0:string,1:string}>>}
     *         [merged spells by unit, raw (unmerged) clipped spells by unit]
     */
    private static function fetchSpells(string $from, string $to, array $unitIds, string $today): array
    {
        $raw = [];
        // Chunk the IN list so a 1000+ unit fleet never builds a giant statement.
        foreach (array_chunk($unitIds, 500) as $chunk) {
            $ph   = implode(',', array_fill(0, count($chunk), '?'));
            $rows = \db_select(
                "SELECT l.equipment_unit_id AS unit_id,
                        GREATEST(l.start_date, ?) AS eff_start,
                        LEAST(
                            GREATEST(COALESCE(l.actual_return_date, l.end_date, ?), l.start_date),
                            ?
                        ) AS eff_end
                   FROM leases l
                  WHERE l.deleted_at IS NULL
                    AND l.status IN ('active', 'completed')
                    AND l.start_date <= ?
                    AND COALESCE(l.actual_return_date, l.end_date, ?) >= ?
                    AND l.equipment_unit_id IN ({$ph})",
                array_merge([$from, $today, $to, $to, $today, $from], $chunk)
            );
            foreach ($rows as $r) {
                $s = (string) $r['eff_start'];
                $e = (string) $r['eff_end'];
                if ($e < $s) {
                    continue; // defensive — SQL already clamps
                }
                $raw[(int) $r['unit_id']][] = [$s, $e];
            }
        }

        $merged = [];
        foreach ($raw as $uid => $spells) {
            $merged[$uid] = self::mergeSpells($spells);
        }
        return [$merged, $raw];
    }

    /**
     * In-service date per unit: earliest of acquired_date, DATE(created_at) and
     * the first active/completed lease start (see class docblock for why).
     *
     * @param int[] $unitIds non-empty
     * @return array<int, string> unit_id => Y-m-d
     */
    private static function inServiceDates(array $unitIds): array
    {
        $out = [];
        foreach (array_chunk($unitIds, 500) as $chunk) {
            $ph   = implode(',', array_fill(0, count($chunk), '?'));
            $rows = \db_select(
                "SELECT eu.id,
                        DATE(eu.created_at) AS created_date,
                        eu.acquired_date,
                        (SELECT MIN(l.start_date) FROM leases l
                          WHERE l.equipment_unit_id = eu.id
                            AND l.deleted_at IS NULL
                            AND l.status IN ('active', 'completed')) AS first_lease_start
                   FROM equipment_units eu
                  WHERE eu.id IN ({$ph})",
                $chunk
            );
            foreach ($rows as $r) {
                $candidates = array_filter([
                    $r['created_date'] ?? null,
                    $r['acquired_date'] ?? null,
                    $r['first_lease_start'] ?? null,
                ]);
                $out[(int) $r['id']] = $candidates ? (string) min($candidates) : '1970-01-01';
            }
        }
        return $out;
    }

    /**
     * Roll per-unit spells up into one window's figures.
     *
     * @param array{0:string,1:string}|null $win capped window, null = future
     * @param int[] $unitIds
     * @param array<int, array<int, array{0:string,1:string}>> $spellsByUnit merged
     * @param array<int, array<int, array{0:string,1:string}>> $rawByUnit    unmerged
     * @param array<int, string> $inService
     * @return array forWindow()-shaped result
     */
    private static function summarise(?array $win, array $unitIds, array $spellsByUnit, array $rawByUnit, array $inService): array
    {
        $units     = [];
        $occupied  = 0;
        $available = 0;
        $rawTotal  = 0;

        foreach ($unitIds as $uid) {
            $unit = ['available_days' => 0, 'days_on_rent' => 0, 'raw_lease_day_sum' => 0,
                     'lease_count' => 0, 'utilization_pct' => '0.0'];
            if ($win !== null) {
                // Availability starts at the later of window start / in-service date.
                $availFrom = max($win[0], $inService[$uid] ?? $win[0]);
                if ($availFrom <= $win[1]) {
                    $unit['available_days'] = HolisticLeaseEngine::inclusiveDays($availFrom, $win[1]);
                    $unit['days_on_rent']   = self::clippedDays($spellsByUnit[$uid] ?? [], $availFrom, $win[1]);
                    foreach ($rawByUnit[$uid] ?? [] as $spell) {
                        $d = self::clippedDays([$spell], $availFrom, $win[1]);
                        if ($d > 0) {
                            $unit['raw_lease_day_sum'] += $d;
                            $unit['lease_count']++;
                        }
                    }
                    $unit['utilization_pct'] = ReportBuilder::pct(
                        (string) $unit['days_on_rent'], (string) $unit['available_days'], 1
                    );
                }
            }
            $occupied  += $unit['days_on_rent'];
            $available += $unit['available_days'];
            $rawTotal  += $unit['raw_lease_day_sum'];
            $units[$uid] = $unit;
        }

        return [
            'window_from'         => $win[0] ?? null,
            'window_to'           => $win[1] ?? null,
            'window_days'         => $win ? HolisticLeaseEngine::inclusiveDays($win[0], $win[1]) : 0,
            'unit_count'          => count($unitIds),
            'occupied_unit_days'  => $occupied,
            'available_unit_days' => $available,
            'raw_lease_day_sum'   => $rawTotal,
            'overlap_adjusted'    => $rawTotal > $occupied,
            'utilization_pct'     => ReportBuilder::pct((string) $occupied, (string) $available, 1),
            'units'               => $units,
        ];
    }
}
