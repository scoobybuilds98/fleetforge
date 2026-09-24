<?php
declare(strict_types=1);

namespace FleetForge\Billing\Cycle;

/**
 * lib/Billing/Cycle/CycleReadings.php
 *
 * S-BILLING-MODULE — period-end meter readings for a cycle.
 *
 * Why this exists: a lease in MANUAL mileage mode only bills distance when
 * the invoice is given an odometer reading, and engine/reefer hours are
 * always manual. The single-invoice form (invoices/create) asks for those
 * readings; batch generation never could, so a month billed in bulk either
 * skipped the mileage or forced the operator back to one-lease-at-a-time.
 * The cycle's Readings sheet collects them for every lease that needs one,
 * and every Billing generation path (workbench generate, dry run, approved
 * run) passes them to InvoiceGenerator::createFromLease() through
 * generatorParams() — the same parameters the single-invoice form sends:
 *
 *   odometer_at_period_start_km  previous reading (last invoice's period-end
 *                                odometer before this month, else the lease's
 *                                starting odometer) — same fallback the
 *                                invoice form auto-fills
 *   odometer_at_period_end_km    the reading entered here
 *   odometer_source              'manual'
 *   engine_hours_at_period_start / _end   the same pattern for hours
 *
 * A reading only applies to a generation whose period ENDS on the cycle's
 * last day (a full month, or a partial month from a mid-month start): a
 * period-end reading means nothing for a custom range ending mid-month.
 * Samsara-mode leases never need one — the generator fetches their distance.
 *
 * Readings are stored in km (the generator's canonical unit); entered_unit
 * remembers what the operator typed so the sheet shows it back the same way.
 *
 * @session S-BILLING-MODULE
 */
final class CycleReadings
{
    private function __construct() {}

    /** Leases in the universe whose invoice needs a manual reading. */
    private const NEEDS_READING_SQL = "(
            (l.mileage_tracking_mode = 'manual'
             AND (COALESCE(l.mileage_rate_km, 0) > 0 OR l.mileage_rate > 0 OR l.precharge_enabled = 1))
         OR COALESCE(l.hourly_rate, 0) > 0
        )";

    /**
     * Rows for the Readings sheet: every ACTIVE lease in the cycle that needs
     * a manual reading (a completed lease takes its final reading at close,
     * and its unbilled tail is billed by the lease's own Generate Invoice,
     * which asks for its reading itself), with the previous reading, the
     * saved reading (if any),
     * the Samsara cached odometer as a hint, and whether the month is billed.
     */
    public static function sheet(array $cycle): array
    {
        $rows = \db_select(
            "SELECT l.id AS lease_id, l.contract_number, l.customer_id, l.status AS lease_status,
                    l.mileage_tracking_mode, l.mileage_unit, l.miles_to_km_conversion, l.km_to_miles_conversion,
                    l.mileage_rate_km, l.mileage_rate, l.hourly_rate, l.precharge_enabled,
                    l.odometer_start_km, l.engine_hours_at_start, l.start_date, l.equipment_unit_id,
                    c.company_name,
                    COALESCE(eu.unit_number, l.unit_number_snapshot) AS unit_number,
                    eu.samsara_odometer_km, eu.samsara_last_synced_at, eu.samsara_vehicle_id,
                    r.id AS reading_id, r.odometer_km, r.entered_unit, r.engine_hours, r.reading_date,
                    r.notes, r.updated_at AS reading_updated_at, ru.name AS entered_by_name
               FROM leases l
               JOIN customers c ON c.id = l.customer_id
               LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
               LEFT JOIN billing_cycle_readings r ON r.cycle_id = ? AND r.lease_id = l.id
               LEFT JOIN users ru ON ru.id = r.entered_by
              WHERE l.deleted_at IS NULL
                AND l.status = 'active'
                AND l.start_date <= ?
                AND " . self::NEEDS_READING_SQL . "
              ORDER BY c.company_name, l.contract_number",
            [$cycle['id'], $cycle['period_end']]
        );
        if (!$rows) return [];

        $ids = array_map(static fn($r) => (int) $r['lease_id'], $rows);
        $prev = self::previousReadings($ids, $cycle['period_start']);

        // Is this month already billed for the lease? (a live in-scope invoice)
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $billed = [];
        foreach (\db_select(
            "SELECT i.lease_id, i.id, i.invoice_number, i.status, i.odometer_at_period_end_km, i.engine_hours_at_period_end
               FROM invoices i
              WHERE " . BillingCycles::scopeSql('i') . " AND i.status <> 'void' AND i.lease_id IN ({$ph})
              ORDER BY i.id",
            array_merge(BillingCycles::scopeParams($cycle), $ids)
        ) as $r) {
            $billed[(int) $r['lease_id']] = $r;
        }

        // S-BILLING-MODULE-2 (KNOWN ISSUE #115): the latest Mileage Logs
        // odometer for each lease's unit on or before the month end — offered
        // as a one-click suggestion, like the Samsara odometer. This sheet's
        // own write-backs (notes marker) are excluded so a saved reading never
        // "suggests" itself.
        $units = array_values(array_filter(array_map(static fn($r) => (int) $r['equipment_unit_id'], $rows)));
        $logs = [];
        if ($units) {
            $uph = implode(',', array_fill(0, count($units), '?'));
            foreach (\db_select(
                "SELECT ml.equipment_unit_id, ml.odometer_reading, ml.mileage_unit, ml.log_date, ml.log_type
                   FROM mileage_logs ml
                  WHERE ml.equipment_unit_id IN ({$uph}) AND ml.log_date <= ?
                    AND (ml.notes IS NULL OR ml.notes NOT LIKE 'Billing cycle %')
                  ORDER BY ml.log_date ASC, ml.id ASC",
                array_merge($units, [$cycle['period_end']])
            ) as $lg) {
                $logs[(int) $lg['equipment_unit_id']] = $lg; // ASC: last wins = latest
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $lid = (int) $r['lease_id'];
            $needsOdo   = $r['mileage_tracking_mode'] === 'manual'
                && (bccomp((string) ($r['mileage_rate_km'] ?? '0'), '0', 4) > 0
                    || bccomp((string) $r['mileage_rate'], '0', 4) > 0
                    || (int) $r['precharge_enabled'] === 1);
            $needsHours = bccomp((string) ($r['hourly_rate'] ?? '0'), '0', 4) > 0;
            $unit       = $r['mileage_unit'] === 'miles' ? 'miles' : 'km';
            $toUnit     = $unit === 'miles' ? (string) $r['km_to_miles_conversion'] : '1';

            $p = $prev[$lid] ?? ['odometer_km' => null, 'odometer_from' => null, 'hours' => null, 'hours_from' => null];
            if ($p['odometer_km'] === null && $r['odometer_start_km'] !== null) {
                $p['odometer_km'] = (string) $r['odometer_start_km'];
                $p['odometer_from'] = 'lease start';
            }
            if ($p['hours'] === null && $r['engine_hours_at_start'] !== null) {
                $p['hours'] = (string) $r['engine_hours_at_start'];
                $p['hours_from'] = 'lease start';
            }

            $entered = $r['reading_id'] !== null;
            $missing = ($needsOdo && ($r['odometer_km'] === null)) || ($needsHours && ($r['engine_hours'] === null));

            $out[] = [
                'lease_id'          => $lid,
                'contract_number'   => $r['contract_number'],
                'customer_id'       => (int) $r['customer_id'],
                'company_name'      => $r['company_name'],
                'unit_number'       => $r['unit_number'],
                'lease_status'      => $r['lease_status'],
                'needs_odometer'    => $needsOdo,
                'needs_hours'       => $needsHours,
                'mileage_unit'      => $unit,
                'km_to_unit'        => $toUnit,
                'prev_odometer_km'  => $p['odometer_km'],
                'prev_odometer_in_unit' => $p['odometer_km'] !== null ? bcmul($p['odometer_km'], $toUnit, 2) : null,
                'prev_odometer_from'=> $p['odometer_from'],
                'prev_hours'        => $p['hours'],
                'prev_hours_from'   => $p['hours_from'],
                'samsara_odometer_in_unit' => ($r['samsara_vehicle_id'] && $r['samsara_odometer_km'] !== null)
                    ? bcmul((string) $r['samsara_odometer_km'], $toUnit, 2) : null,
                'samsara_synced_at' => $r['samsara_last_synced_at'],
                'log_odometer_in_unit' => isset($logs[(int) $r['equipment_unit_id']])
                    ? self::toLeaseUnit((string) $logs[(int) $r['equipment_unit_id']]['odometer_reading'],
                        (string) $logs[(int) $r['equipment_unit_id']]['mileage_unit'], $unit,
                        (string) $r['km_to_miles_conversion'], (string) $r['miles_to_km_conversion'])
                    : null,
                'log_date'          => $logs[(int) $r['equipment_unit_id']]['log_date'] ?? null,
                'log_type'          => $logs[(int) $r['equipment_unit_id']]['log_type'] ?? null,
                'reading'           => $entered ? [
                    'odometer_km'      => $r['odometer_km'],
                    'odometer_in_unit' => $r['odometer_km'] !== null ? bcmul((string) $r['odometer_km'], $toUnit, 2) : null,
                    'engine_hours'     => $r['engine_hours'],
                    'reading_date'     => $r['reading_date'],
                    'notes'            => $r['notes'],
                    'entered_by_name'  => $r['entered_by_name'],
                    'updated_at'       => $r['reading_updated_at'],
                ] : null,
                'missing'           => $missing,
                'billed'            => isset($billed[$lid]) ? [
                    'invoice_id'     => (int) $billed[$lid]['id'],
                    'invoice_number' => $billed[$lid]['invoice_number'],
                    'status'         => $billed[$lid]['status'],
                    'had_odometer'   => $billed[$lid]['odometer_at_period_end_km'] !== null,
                    'had_hours'      => $billed[$lid]['engine_hours_at_period_end'] !== null,
                ] : null,
            ];
        }
        return $out;
    }

    /** Convert a logged odometer into the lease's unit (bcmath). */
    private static function toLeaseUnit(string $value, string $fromUnit, string $leaseUnit, string $kmToMiles, string $milesToKm): string
    {
        if ($fromUnit === $leaseUnit) return bcadd($value, '0', 2);
        return $leaseUnit === 'miles' ? bcmul($value, $kmToMiles, 2) : bcmul($value, $milesToKm, 2);
    }

    /**
     * {required, entered, missing} for the stepper. Only unbilled leases
     * count as missing — a reading after the invoice exists changes nothing.
     */
    public static function progress(array $cycle): array
    {
        $required = 0;
        $missing = 0;
        foreach (self::sheet($cycle) as $row) {
            if ($row['billed']) continue;
            $required++;
            if ($row['missing']) $missing++;
        }
        return ['required' => $required, 'entered' => $required - $missing, 'missing' => $missing];
    }

    /**
     * Most recent period-end odometer / hours strictly before $beforeDate,
     * from live invoices (void invoices never billed their reading).
     *
     * @param int[] $leaseIds
     * @return array<int, array{odometer_km: ?string, odometer_from: ?string, hours: ?string, hours_from: ?string}>
     */
    public static function previousReadings(array $leaseIds, string $beforeDate): array
    {
        if (!$leaseIds) return [];
        $ph = implode(',', array_fill(0, count($leaseIds), '?'));
        $out = [];
        foreach (\db_select(
            "SELECT i.lease_id, i.invoice_number, i.billing_period_end,
                    i.odometer_at_period_end_km, i.engine_hours_at_period_end
               FROM invoices i
              WHERE i.deleted_at IS NULL AND i.status <> 'void' AND i.lease_id IN ({$ph})
                AND i.billing_period_end < ?
                AND (i.odometer_at_period_end_km IS NOT NULL OR i.engine_hours_at_period_end IS NOT NULL)
              ORDER BY i.billing_period_end ASC, i.id ASC",
            array_merge($leaseIds, [$beforeDate])
        ) as $r) {
            $lid = (int) $r['lease_id'];
            $out[$lid] ??= ['odometer_km' => null, 'odometer_from' => null, 'hours' => null, 'hours_from' => null];
            // ASC order: later rows overwrite, so each ends as the latest.
            if ($r['odometer_at_period_end_km'] !== null) {
                $out[$lid]['odometer_km'] = (string) $r['odometer_at_period_end_km'];
                $out[$lid]['odometer_from'] = $r['invoice_number'] . ' (' . $r['billing_period_end'] . ')';
            }
            if ($r['engine_hours_at_period_end'] !== null) {
                $out[$lid]['hours'] = (string) $r['engine_hours_at_period_end'];
                $out[$lid]['hours_from'] = $r['invoice_number'] . ' (' . $r['billing_period_end'] . ')';
            }
        }
        return $out;
    }

    /**
     * Save readings for a cycle. Each input row:
     *   { lease_id, odometer (in the lease's unit) | null, engine_hours | null,
     *     reading_date | null, notes | null }
     * A row with neither value deletes the stored reading.
     *
     * @return array{saved: int, cleared: int, errors: array<int, array{lease_id:int, field:string, message:string}>}
     */
    public static function save(array $cycle, array $input, ?int $userId): array
    {
        $sheet = [];
        foreach (self::sheet($cycle) as $row) {
            $sheet[$row['lease_id']] = $row;
        }
        $leaseConv = [];
        if ($sheet) {
            $ph = implode(',', array_fill(0, count($sheet), '?'));
            foreach (\db_select(
                "SELECT id, miles_to_km_conversion FROM leases WHERE id IN ({$ph})",
                array_keys($sheet)
            ) as $r) {
                $leaseConv[(int) $r['id']] = (string) $r['miles_to_km_conversion'];
            }
        }

        $saved = 0;
        $cleared = 0;
        $errors = [];
        foreach ($input as $in) {
            $leaseId = (int) ($in['lease_id'] ?? 0);
            $row = $sheet[$leaseId] ?? null;
            if (!$row) {
                $errors[] = ['lease_id' => $leaseId, 'field' => 'lease_id', 'message' => 'This lease does not need a reading this cycle.'];
                continue;
            }
            $odoRaw   = $in['odometer'] ?? null;
            $hoursRaw = $in['engine_hours'] ?? null;
            $odo   = ($odoRaw === null || $odoRaw === '') ? null : \clean_decimal($odoRaw);
            $hours = ($hoursRaw === null || $hoursRaw === '') ? null : \clean_decimal($hoursRaw);

            if ($odoRaw !== null && $odoRaw !== '' && ($odo === null || bccomp((string) $odo, '0', 2) < 0)) {
                $errors[] = ['lease_id' => $leaseId, 'field' => 'odometer', 'message' => 'Enter the odometer as a number, 0 or more.'];
                continue;
            }
            if ($hoursRaw !== null && $hoursRaw !== '' && ($hours === null || bccomp((string) $hours, '0', 2) < 0)) {
                $errors[] = ['lease_id' => $leaseId, 'field' => 'engine_hours', 'message' => 'Enter the hours as a number, 0 or more.'];
                continue;
            }

            $odoKm = null;
            if ($odo !== null) {
                $odoKm = $row['mileage_unit'] === 'miles'
                    ? bcmul((string) $odo, $leaseConv[$leaseId] ?? '1.609344', 2)
                    : bcadd((string) $odo, '0', 2);
                if ($row['prev_odometer_km'] !== null && bccomp($odoKm, (string) $row['prev_odometer_km'], 2) < 0) {
                    $errors[] = ['lease_id' => $leaseId, 'field' => 'odometer',
                        'message' => 'Lower than the previous reading (' . $row['prev_odometer_in_unit'] . ' ' . $row['mileage_unit'] . ' from ' . $row['prev_odometer_from'] . ').'];
                    continue;
                }
            }
            if ($hours !== null && $row['prev_hours'] !== null && bccomp((string) $hours, (string) $row['prev_hours'], 2) < 0) {
                $errors[] = ['lease_id' => $leaseId, 'field' => 'engine_hours',
                    'message' => 'Lower than the previous hours (' . $row['prev_hours'] . ' from ' . $row['prev_hours_from'] . ').'];
                continue;
            }

            $date = \clean_date($in['reading_date'] ?? null);
            $notes = trim((string) ($in['notes'] ?? ''));

            if ($odoKm === null && $hours === null) {
                $n = \db_execute("DELETE FROM billing_cycle_readings WHERE cycle_id = ? AND lease_id = ?", [$cycle['id'], $leaseId]);
                if ($n > 0) $cleared++;
                self::syncMileageLog($cycle, $leaseId, null, $row['mileage_unit'], null, $userId);
                continue;
            }

            \db_execute(
                "INSERT INTO billing_cycle_readings
                    (cycle_id, lease_id, odometer_km, entered_unit, engine_hours, reading_date, notes, entered_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    odometer_km = VALUES(odometer_km), entered_unit = VALUES(entered_unit),
                    engine_hours = VALUES(engine_hours), reading_date = VALUES(reading_date),
                    notes = VALUES(notes), entered_by = VALUES(entered_by)",
                [$cycle['id'], $leaseId, $odoKm, $row['mileage_unit'], $hours, $date ?: null,
                 $notes !== '' ? mb_substr($notes, 0, 500) : null, $userId]
            );
            $saved++;
            self::syncMileageLog($cycle, $leaseId, $odo !== null ? (string) $odo : null, $row['mileage_unit'], $date ?: null, $userId);
        }

        if ($saved || $cleared) {
            BillingCycles::audit($cycle, 'update',
                "Readings sheet: {$saved} saved" . ($cleared ? ", {$cleared} cleared" : '') . '.');
        }
        return ['saved' => $saved, 'cleared' => $cleared, 'errors' => $errors];
    }

    /**
     * Mirror a month-end odometer reading into Mileage Logs (log_type
     * 'manual', notes "Billing cycle BC-YYYY-MM month-end reading") so the
     * unit's mileage history shows what billing used. One row per lease per
     * cycle: replaced on re-save, removed when the reading is cleared.
     * Best-effort — a log failure never blocks the reading.
     */
    private static function syncMileageLog(array $cycle, int $leaseId, ?string $odoInUnit, string $unit, ?string $date, ?int $userId): void
    {
        try {
            $marker = 'Billing cycle ' . $cycle['reference'] . ' month-end reading';
            \db_execute("DELETE FROM mileage_logs WHERE lease_id = ? AND notes = ?", [$leaseId, $marker]);
            if ($odoInUnit === null) return;
            $unitId = \db_row("SELECT equipment_unit_id FROM leases WHERE id = ?", [$leaseId])['equipment_unit_id'] ?? null;
            if (!$unitId) return;
            \db_insert('mileage_logs', [
                'equipment_unit_id' => (int) $unitId,
                'lease_id'          => $leaseId,
                'log_type'          => 'manual',
                'odometer_reading'  => (int) \bcround($odoInUnit, 0),
                'mileage_unit'      => $unit === 'miles' ? 'miles' : 'km',
                'log_date'          => $date ?: (string) $cycle['period_end'],
                'notes'             => $marker,
                'recorded_by'       => $userId,
            ]);
        } catch (\Throwable $e) {
            error_log('[CycleReadings] mileage log sync failed for lease #' . $leaseId . ': ' . $e->getMessage());
        }
    }

    /**
     * createFromLease() parameters for a lease's generation over a period,
     * from the cycle reading — [] when there is none or it does not apply.
     * Called by batch_generate, batch_runs/generate and BatchPreviewService;
     * best-effort (a lookup failure bills without readings, as before).
     */
    public static function generatorParams(int $leaseId, string $periodStart, string $periodEnd): array
    {
        try {
            $monthStart = substr($periodStart, 0, 7) . '-01';
            if ($periodEnd !== date('Y-m-t', strtotime($monthStart))) {
                return [];
            }
            $r = \db_row(
                "SELECT r.odometer_km, r.engine_hours, l.mileage_tracking_mode, l.hourly_rate,
                        l.odometer_start_km, l.engine_hours_at_start
                   FROM billing_cycle_readings r
                   JOIN billing_cycles bc ON bc.id = r.cycle_id AND bc.period_start = ?
                   JOIN leases l ON l.id = r.lease_id
                  WHERE r.lease_id = ?",
                [$monthStart, $leaseId]
            );
            if (!$r) return [];

            $prev = self::previousReadings([$leaseId], $periodStart)[$leaseId] ?? null;
            $params = [];
            if ($r['odometer_km'] !== null && $r['mileage_tracking_mode'] === 'manual') {
                $start = $prev['odometer_km'] ?? ($r['odometer_start_km'] !== null ? (string) $r['odometer_start_km'] : null);
                if ($start !== null) {
                    $params['odometer_at_period_start_km'] = $start;
                }
                $params['odometer_at_period_end_km'] = (string) $r['odometer_km'];
                $params['odometer_source'] = 'manual';
            }
            if ($r['engine_hours'] !== null && bccomp((string) ($r['hourly_rate'] ?? '0'), '0', 4) > 0) {
                $start = $prev['hours'] ?? ($r['engine_hours_at_start'] !== null ? (string) $r['engine_hours_at_start'] : null);
                if ($start !== null) {
                    $params['engine_hours_at_period_start'] = $start;
                }
                $params['engine_hours_at_period_end'] = (string) $r['engine_hours'];
            }
            return $params;
        } catch (\Throwable $e) {
            error_log('[CycleReadings] generatorParams failed for lease #' . $leaseId . ': ' . $e->getMessage());
            return [];
        }
    }
}
