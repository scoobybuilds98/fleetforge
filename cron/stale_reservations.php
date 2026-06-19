<?php
declare(strict_types=1);

/**
 * cron/stale_reservations.php
 *
 * Stale reservation cleanup — runs daily at 04:00 UTC.
 *
 * Finds reservations stuck in 'pending' status past a configurable threshold
 * and auto-cancels them so equipment units are not blocked indefinitely.
 *
 * A 'pending' reservation has been entered but not yet confirmed by staff.
 * If nobody confirms it within the threshold window, it is almost certainly
 * forgotten — the unit would remain in a 'reserved' state blocking future
 * reservations via the conflict check in reservations/create.php.
 *
 * Crontab (production): 0 4 * * *  /usr/bin/php /var/www/fleetforge/cron/stale_reservations.php >> /var/www/fleetforge/logs/cron.log 2>&1
 * Local test:           php /Users/avi/Documents/fleetforge/cron/stale_reservations.php
 *
 * Threshold: settings key 'reservations.stale_after_days' (default 14).
 * 14 days chosen because:
 *   - Fleet rental context: pickups are scheduled; 2 weeks with no confirmation
 *     is genuinely stale (not just slow approval).
 *   - Leaves room for busy operators without creating indefinite blocks.
 *   - Operators with faster workflows should lower this (e.g. 7 days).
 *
 * Unit flip logic:
 *   Equipment units linked to the reservation via reservation_units (entry_type='system')
 *   are flipped from 'reserved' → 'available' only when:
 *     - The unit is currently 'reserved' (not moved to on_lease or maintenance)
 *     - No other active (pending/confirmed) reservation holds that unit
 *
 * Schema note: reservations has no cancellation_reason column.
 *   Auto-cancel reason is appended to internal_notes with a timestamp.
 *
 * Decisions: D21 (advisory lock)
 * Audit findings resolved: #29 (stale reservations block units indefinitely)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// Operator on/off switch (Settings -> Intelligence -> Scheduled Jobs). S-CRON-TOGGLES.
if (!cron_enabled('stale_reservations')) { error_log('[CRON] stale_reservations disabled - skipping.'); exit(0); }

use FleetForge\Notifications\NotificationService;

// ── Advisory lock (D21) ──────────────────────────────────────────────────────
$lock = db_row("SELECT GET_LOCK('ff_cron_stale_reservations', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$start = time();

try {
    $thresholdDays = max(1, (int) settings_get('reservations.stale_after_days', '14'));
    $cutoff        = date('Y-m-d H:i:s', strtotime("-{$thresholdDays} days"));
    $now           = date('Y-m-d H:i:s');
    $cancelNote    = "Auto-cancelled by stale-reservation cron on {$now}"
        . " — reservation remained pending for {$thresholdDays}+ days with no confirmation.";

    // ── Find stale pending reservations ──────────────────────────────────────
    $stale = db_select(
        "SELECT id, status, company_name, contact_name, contact_email,
                pickup_date, internal_notes, created_by, created_at
           FROM reservations
          WHERE status     = 'pending'
            AND created_at < ?
            AND deleted_at IS NULL",
        [$cutoff]
    );

    if (empty($stale)) {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'reservations',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'stale_reservations',
            'notes'        => "Stale reservation check: 0 stale reservations found (threshold {$thresholdDays} days).",
            'ip_address'   => '127.0.0.1',
        ]);
        exit(0);
    }

    $cancelled  = 0;
    $errors     = 0;
    $unitsFreed = 0;

    foreach ($stale as $res) {
        $resId = (int) $res['id'];

        try {
            // ── Cancel the reservation ────────────────────────────────────────
            $updatedNotes = trim((string) ($res['internal_notes'] ?? ''));
            if ($updatedNotes !== '') {
                $updatedNotes .= "\n";
            }
            $updatedNotes .= $cancelNote;

            db_execute(
                "UPDATE reservations
                    SET status         = 'cancelled',
                        internal_notes = ?,
                        updated_at     = NOW()
                  WHERE id = ? AND status = 'pending' AND deleted_at IS NULL",
                [$updatedNotes, $resId]
            );

            // ── Free system-linked units ──────────────────────────────────────
            $resUnits = db_select(
                "SELECT equipment_unit_id
                   FROM reservation_units
                  WHERE reservation_id = ?
                    AND entry_type     = 'system'
                    AND equipment_unit_id IS NOT NULL",
                [$resId]
            );

            foreach ($resUnits as $ru) {
                $unitId = (int) $ru['equipment_unit_id'];

                $unit = db_row(
                    "SELECT id, status FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
                    [$unitId]
                );
                if (!$unit || $unit['status'] !== 'reserved') {
                    continue; // already moved to on_lease, maintenance, etc.
                }

                // Only flip if no other active reservation holds this unit
                $otherActive = db_count(
                    "SELECT COUNT(*) FROM reservation_units ru
                      JOIN reservations r ON r.id = ru.reservation_id
                     WHERE ru.equipment_unit_id = ?
                       AND ru.reservation_id    != ?
                       AND r.status IN ('pending', 'confirmed')
                       AND r.deleted_at IS NULL",
                    [$unitId, $resId]
                );

                if ($otherActive === 0) {
                    db_execute(
                        "UPDATE equipment_units
                            SET status     = 'available',
                                updated_at = NOW()
                          WHERE id = ? AND status = 'reserved'",
                        [$unitId]
                    );

                    db_insert('equipment_status_log', [
                        'equipment_unit_id'  => $unitId,
                        'reservation_id'     => $resId,
                        'old_status'         => 'reserved',
                        'new_status'         => 'available',
                        'reason'             => "Stale reservation #{$resId} auto-cancelled after {$thresholdDays} days pending",
                        'changed_by'         => 'system',
                        'changed_by_user_id' => null,
                    ]);

                    $unitsFreed++;
                }
            }

            // ── Notify: creator + super_admin users ───────────────────────────
            $specificIds = [];
            if (!empty($res['created_by'])) {
                $specificIds[] = (int) $res['created_by'];
            }
            $admins = db_select(
                "SELECT u.id
                   FROM users u
                   JOIN user_roles r ON r.id = u.role_id
                  WHERE r.slug = 'super_admin'
                    AND u.deleted_at IS NULL
                    AND u.status = 'active'",
                []
            );
            foreach ($admins as $a) {
                $specificIds[] = (int) $a['id'];
            }
            $specificIds = array_values(array_unique($specificIds));

            if (!empty($specificIds)) {
                NotificationService::notify(
                    'reservation.stale_cancelled',
                    "Reservation auto-cancelled: {$res['company_name']}",
                    "Reservation #{$resId} for {$res['company_name']} ({$res['contact_name']})"
                        . ", pickup {$res['pickup_date']}"
                        . ", was pending for {$thresholdDays}+ days and has been auto-cancelled.",
                    'reservation',
                    $resId,
                    '/fleetforge/reservations',
                    $specificIds,
                    'warning'
                );
            }

            // ── Audit log per reservation ─────────────────────────────────────
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'status_change',
                'module'       => 'reservations',
                'entity_type'  => 'reservation',
                'entity_id'    => $resId,
                'entity_label' => "#{$resId} — {$res['company_name']}",
                'old_values'   => json_encode(['status' => 'pending']),
                'new_values'   => json_encode(['status' => 'cancelled']),
                'notes'        => "Auto-cancelled by stale-reservation cron (pending {$thresholdDays}+ days, created {$res['created_at']})",
                'ip_address'   => '127.0.0.1',
            ]);

            $cancelled++;

        } catch (\Throwable $rowError) {
            $errors++;
            error_log("[CRON stale_reservations] Error on reservation #{$resId}: " . $rowError->getMessage());
        }
    }

    $duration = time() - $start;
    $notes    = "Stale reservation cleanup: cancelled={$cancelled}, units_freed={$unitsFreed}"
        . ($errors > 0 ? ", errors={$errors}" : '')
        . " (threshold {$thresholdDays} days, {$duration}s)";

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'reservations',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'stale_reservations',
        'notes'        => $notes,
        'ip_address'   => '127.0.0.1',
    ]);

    error_log("[CRON stale_reservations] {$notes}");

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    $msg = $e->getMessage();
    error_log("[CRON stale_reservations] FAILED: {$msg}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'reservations',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'stale_reservations',
        'notes'        => "Stale reservation cron FAILED: {$msg}",
        'ip_address'   => '127.0.0.1',
    ]);

    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_stale_reservations')", []);
}
