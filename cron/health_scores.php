<?php
declare(strict_types=1);

/**
 * cron/health_scores.php
 *
 * Equipment health score recalculator — runs nightly at 03:00.
 *
 * Scans every active (non-decommissioned, non-deleted) equipment unit and
 * recomputes its health_score from the canonical formula (FLEETFORGE_SPEC_FINAL.md
 * §12, locked). Writes the score back to equipment_units.health_score and
 * equipment_units.health_score_updated_at. Notifies the equipment team when
 * a unit's color band drops to orange or red.
 *
 * Score formula (locked, S-CRON-3):
 *   Start at 100.
 *   Deduct, STACKING per doc (D-A):
 *     -30 expired compliance doc
 *     -15 expires ≤ 7 days
 *     -10 expires ≤ 30 days
 *     -5  expires ≤ 60 days
 *   Mutually exclusive (D-B), take the worse one only:
 *     -10 open work order > 14 days old
 *     -5  any open work order
 *   Other deductions:
 *     -15 open damage claim (status NOT IN 'resolved','written_off')
 *     -10 mileage > 1.5x average for template_id
 *     -5  inactive (status='inactive') > 90 days
 *   Floor at 0.
 *
 * Color bands (derived on read via equipment_health_color()):
 *   80-100 green | 50-79 yellow | 20-49 orange | 0-19 red
 *
 * Crontab (production): 0 3 * * * php /var/www/fleetforge/cron/health_scores.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/health_scores.php
 *
 * @session S-CRON-3
 * @audit   #3 (missing crons — health scoring)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// D21: Advisory lock prevents overlapping runs. Silent exit when held —
// a parallel invocation would double-count nothing (idempotent recompute)
// but would waste resources and spam audit_log.
$lock = db_row("SELECT GET_LOCK('ff_cron_health_scores', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$processed       = 0;
$updated         = 0;
$bandDrops       = 0;
$notificationsFired = 0;
$errors          = 0;
$bandCounts      = ['green' => 0, 'yellow' => 0, 'orange' => 0, 'red' => 0, 'unknown' => 0];
$scoreSum        = 0;

try {
    $today      = date('Y-m-d');
    $in7d       = date('Y-m-d', strtotime('+7 days'));
    $in30d      = date('Y-m-d', strtotime('+30 days'));
    $in60d      = date('Y-m-d', strtotime('+60 days'));
    $cutoff14d  = date('Y-m-d H:i:s', strtotime('-14 days'));
    $cutoff90d  = date('Y-m-d H:i:s', strtotime('-90 days'));

    // -----------------------------------------------------------------------
    // Pre-compute average odometer per template_id once for the mileage check.
    // WHY: O(N) lookup per-unit avoids re-aggregating on every iteration.
    // -----------------------------------------------------------------------
    $avgRows = db_select(
        "SELECT template_id,
                AVG(samsara_odometer_km) AS avg_km
         FROM equipment_units
         WHERE deleted_at IS NULL
           AND template_id IS NOT NULL
           AND samsara_odometer_km IS NOT NULL
         GROUP BY template_id"
    );
    $avgByTemplate = [];
    foreach ($avgRows as $row) {
        $avgByTemplate[(int)$row['template_id']] = (float)$row['avg_km'];
    }

    // -----------------------------------------------------------------------
    // Pull every scoreable unit with its compliance + mileage + status data.
    // We batch the open-WO and damage-claim subqueries so the per-unit loop
    // is pure math with no extra DB round-trips.
    // -----------------------------------------------------------------------
    $units = db_select(
        "SELECT
            eu.id,
            eu.unit_number,
            eu.status,
            eu.template_id,
            eu.samsara_odometer_km,
            eu.cvi_expiry,
            eu.registration_expiry,
            eu.mvi_expiry,
            eu.insurance_expiry,
            eu.health_score AS old_score,
            eu.updated_at,
            EXISTS(
                SELECT 1 FROM damage_claims dc
                WHERE dc.equipment_unit_id = eu.id
                  AND dc.deleted_at IS NULL
                  AND dc.status NOT IN ('resolved','written_off')
            ) AS has_open_damage,
            EXISTS(
                SELECT 1 FROM maintenance_work_orders wo
                WHERE wo.equipment_unit_id = eu.id
                  AND wo.deleted_at IS NULL
                  AND wo.status NOT IN ('completed','cancelled')
            ) AS has_open_wo,
            EXISTS(
                SELECT 1 FROM maintenance_work_orders wo
                WHERE wo.equipment_unit_id = eu.id
                  AND wo.deleted_at IS NULL
                  AND wo.status NOT IN ('completed','cancelled')
                  AND wo.created_at < ?
            ) AS has_old_wo
         FROM equipment_units eu
         WHERE eu.deleted_at IS NULL
           AND eu.status NOT IN ('decommissioned')",
        [$cutoff14d]
    );

    foreach ($units as $unit) {
        $unitId     = (int)$unit['id'];
        $unitNumber = $unit['unit_number'];
        $oldScore   = $unit['old_score'] !== null ? (int)$unit['old_score'] : null;
        $oldColor   = equipment_health_color($oldScore);

        try {
            $score = compute_unit_health_score($unit, $today, $in7d, $in30d, $in60d, $cutoff90d, $avgByTemplate);
            $newColor = equipment_health_color($score);

            $processed++;
            $scoreSum += $score;
            $bandCounts[$newColor]++;

            // Skip the UPDATE entirely when nothing changed. WHY: avoids
            // touching equipment_units.updated_at on no-op recomputes.
            if ($oldScore !== $score) {
                db_update('equipment_units', [
                    'health_score'             => $score,
                    'health_score_updated_at'  => date('Y-m-d H:i:s'),
                ], 'id = ?', [$unitId]);
                $updated++;
            }

            // audit_log only for material changes (>5 pt swing OR color
            // band crossed). WHY: minor 1-2 pt drifts are noise.
            $material = ($oldScore === null)
                || (abs($score - $oldScore) > 5)
                || ($oldColor !== $newColor);

            if ($material && $oldScore !== $score) {
                db_insert('audit_log', [
                    'user_id'      => null,
                    'user_name'    => 'system',
                    'action'       => 'update',
                    'module'       => 'equipment',
                    'entity_type'  => 'equipment_unit',
                    'entity_id'    => $unitId,
                    'entity_label' => (string)$unitNumber,
                    'old_values'   => json_encode(['health_score' => $oldScore, 'color' => $oldColor]),
                    'new_values'   => json_encode(['health_score' => $score, 'color' => $newColor]),
                    'notes'        => "Health score recalc: {$oldColor} {$oldScore} → {$newColor} {$score}",
                    'ip_address'   => '127.0.0.1',
                ]);
            }

            // Notify equipment team only when a unit DROPS to orange/red
            // from a healthier band. WHY: avoid daily spam — alert on
            // material degradation events only.
            $dropped = in_array($newColor, ['orange', 'red'], true)
                    && in_array($oldColor, ['green', 'yellow', 'unknown'], true);

            if ($dropped) {
                $bandDrops++;
                \FleetForge\Notifications\NotificationService::notify(
                    type:       $newColor === 'red' ? 'equipment.health_red' : 'equipment.health_orange',
                    title:      "Health score dropped: Unit {$unitNumber}",
                    message:    "Unit {$unitNumber} health score is now {$score} ({$newColor}). "
                              . "Previous: {$oldScore} ({$oldColor}). Review compliance, work orders, and damage claims.",
                    entityType: 'equipment_unit',
                    entityId:   $unitId,
                    url:        '/fleetforge/equipment/show?id=' . $unitId,
                    severity:   $newColor === 'red' ? 'critical' : 'warning'
                );
                $notificationsFired++;
            }

        } catch (\Throwable $e) {
            // Continue-on-error per row (D21 pattern). One bad unit must
            // not block the others.
            $errors++;
            error_log("[CRON health_scores] Unit #{$unitId} ({$unitNumber}): " . $e->getMessage());
        }
    }

    // Cron summary line.
    $avgScore = $processed > 0 ? round($scoreSum / $processed, 1) : 0;
    $summary = "health_scores completed: processed={$processed} updated={$updated} "
             . "bands(green={$bandCounts['green']} yellow={$bandCounts['yellow']} "
             . "orange={$bandCounts['orange']} red={$bandCounts['red']}) "
             . "avg_score={$avgScore} band_drops={$bandDrops} "
             . "notifications_fired={$notificationsFired} errors={$errors}";
    error_log("[CRON] {$summary}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'equipment',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'health_scores',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log("[CRON health_scores] Fatal: " . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'equipment',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'health_scores',
        'notes'        => 'health_scores fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_health_scores')", []);
}

/**
 * compute_unit_health_score()
 *
 * Pure function: given a unit row + today's window thresholds + a
 * pre-computed avg-mileage map, return the 0-100 health score per spec.
 *
 * No DB writes inside this function — caller persists the result.
 *
 * @param array<string,mixed> $unit          Joined row from equipment_units
 * @param string $today                      'Y-m-d' today
 * @param string $in7d, $in30d, $in60d       Future date thresholds
 * @param string $cutoff90d                  'Y-m-d H:i:s' 90 days ago
 * @param array<int,float> $avgByTemplate    template_id => avg samsara_odometer_km
 */
function compute_unit_health_score(
    array $unit,
    string $today,
    string $in7d,
    string $in30d,
    string $in60d,
    string $cutoff90d,
    array $avgByTemplate
): int {
    $score = 100;

    // Compliance: stack per doc (D-A). Each doc evaluated independently;
    // the worst applicable bucket fires for that doc, then move on.
    $docDates = [
        $unit['cvi_expiry'] ?? null,
        $unit['registration_expiry'] ?? null,
        $unit['mvi_expiry'] ?? null,
        $unit['insurance_expiry'] ?? null,
    ];
    foreach ($docDates as $expiry) {
        if ($expiry === null) continue;
        if ($expiry < $today)        { $score -= 30; continue; }
        if ($expiry <= $in7d)        { $score -= 15; continue; }
        if ($expiry <= $in30d)       { $score -= 10; continue; }
        if ($expiry <= $in60d)       { $score -= 5;  continue; }
    }

    // Open damage claim — single deduction regardless of count.
    if ((int)($unit['has_open_damage'] ?? 0) === 1) {
        $score -= 15;
    }

    // Open work orders — mutually exclusive deduction (D-B).
    // Old WO (>14d) takes precedence; otherwise any open WO costs -5.
    if ((int)($unit['has_old_wo'] ?? 0) === 1) {
        $score -= 10;
    } elseif ((int)($unit['has_open_wo'] ?? 0) === 1) {
        $score -= 5;
    }

    // Mileage > 1.5x average for this template_id.
    $tmplId  = $unit['template_id'] !== null ? (int)$unit['template_id'] : null;
    $odo     = $unit['samsara_odometer_km'] !== null ? (float)$unit['samsara_odometer_km'] : null;
    if ($tmplId !== null && $odo !== null && isset($avgByTemplate[$tmplId])) {
        $avg = $avgByTemplate[$tmplId];
        if ($avg > 0 && $odo > $avg * 1.5) {
            $score -= 10;
        }
    }

    // Inactive > 90 days. WHY: no last_status_change column exists; use
    // updated_at as a proxy. Imperfect (any update bumps it) but the only
    // signal available — surfaced in PROGRESS as a known limitation.
    if (($unit['status'] ?? '') === 'inactive'
        && !empty($unit['updated_at'])
        && $unit['updated_at'] < $cutoff90d) {
        $score -= 5;
    }

    // Floor at 0; ceiling at 100 (deductions only, but defensive).
    return max(0, min(100, $score));
}
