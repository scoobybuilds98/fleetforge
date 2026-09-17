<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/set_snooze.php
 *
 * Set or clear the briefing snooze timestamp for a user (F8). Per
 * D-INTEL-V2-5, snoozed_until is a DATETIME; cron skips users whose
 * snoozed_until is in the future. Setting to NULL or past clears the
 * snooze.
 *
 * Authorization:
 *   - self → no extra permission
 *   - other → require settings.edit
 *
 * Special values for snoozed_until input:
 *   null       — clear the snooze
 *   "1d"       — 1 day from now (shorthand)
 *   "1w"       — 1 week from now
 *   "until_monday" — until next Monday 00:00 local
 *   "until_$date"  — explicit YYYY-MM-DD until midnight local
 *   YYYY-MM-DD HH:MM:SS — explicit datetime (company-local wall time)
 *
 * Time zones (S-LOCAL-DAY-TS): the column is compared against the UTC
 * session clock (cron/notification_digest.php `briefing_snoozed_until <= NOW()`),
 * so every value is STORED in UTC. Relative shorthands are now+offset in UTC;
 * date-only / wall-clock inputs mean company-local time (ff_business_timezone())
 * and are converted to UTC. The response echoes the stored UTC value in
 * `snoozed_until` plus a `snoozed_until_local` display string.
 *
 * @method  POST
 * @auth    require_auth_api (granular check inline)
 * @body    JSON: { user_id: int, snoozed_until: string|null }
 *
 * @session  S-INTEL-V2 Phase B
 * @decision D-INTEL-V2-5
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

$body   = json_body();
$userId = isset($body['user_id']) ? (int) $body['user_id'] : 0;
$raw    = $body['snoozed_until'] ?? null;

if ($userId <= 0) {
    json_error('VALIDATION_ERROR', 'user_id required', 422);
}

$currentId = (int) current_user_id();
$isSelf    = ($userId === $currentId);
if (!$isSelf && !can('settings', 'edit')) {
    json_error('FORBIDDEN', 'Snoozing other users requires settings.edit permission.', 403);
}

try {
    $target = db_row("SELECT id, name, email FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
    if (!$target) {
        json_error('NOT_FOUND', "User #{$userId} not found", 404);
    }

    // Resolve snoozed_until value — always to a UTC DATETIME string.
    // S-LOCAL-DAY-TS: these used to be PHP-local date() values (Pacific wall
    // time) compared by the digest cron against UTC NOW(), so a "1d" snooze
    // ended 7-8h early and "until Monday" lifted on Sunday afternoon.
    $invalid = static function (string $s): never {
        json_error('VALIDATION_ERROR', "Invalid snoozed_until value: '{$s}'. Use null, '1d', '1w', 'until_monday', 'until_YYYY-MM-DD', or YYYY-MM-DD HH:MM:SS.", 422);
    };
    $snoozedUntil = null;
    if ($raw !== null && $raw !== '') {
        $s = (string) $raw;
        if ($s === '1d') {
            // Relative: an instant 24h from now, no local-day meaning.
            $snoozedUntil = ff_now_utc('+1 day');
        } elseif ($s === '1w') {
            $snoozedUntil = ff_now_utc('+1 week');
        } elseif ($s === 'until_monday') {
            // "Next Monday 00:00" is a LOCAL midnight — pick the Monday on the
            // company calendar, then convert its local start to UTC.
            $monday = (new DateTimeImmutable('now', ff_business_timezone()))->modify('next monday')->format('Y-m-d');
            $snoozedUntil = ff_local_day_start_utc($monday);
        } elseif (preg_match('/^until_(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            // Explicit date = local midnight on that date. checkdate() guards
            // against DateTime silently rolling Feb 30 into March.
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $invalid($s);
            }
            $snoozedUntil = ff_local_day_start_utc("{$m[1]}-{$m[2]}-{$m[3]}");
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})( \d{2}:\d{2}(:\d{2})?)?$/', $s, $m)) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $invalid($s);
            }
            if (strlen($s) === 10) {
                // Date only (the settings "…" prompt) = local midnight.
                $snoozedUntil = ff_local_day_start_utc($s);
            } else {
                // Wall-clock datetime typed by an operator = company-local time.
                $local = DateTimeImmutable::createFromFormat(strlen($s) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s', $s, ff_business_timezone());
                if ($local === false || DateTimeImmutable::getLastErrors()) {
                    $invalid($s); // e.g. 25:00 — createFromFormat would roll it over
                }
                $snoozedUntil = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        } else {
            $invalid($s);
        }
    }
    // Human-facing local rendering for the audit note + response (the stored
    // value is UTC and would read 7-8h "late" to an operator).
    $snoozedUntilLocal = $snoozedUntil === null ? null : ff_utc_to_local($snoozedUntil, 'Y-m-d H:i T');

    db_execute(
        "UPDATE users SET briefing_snoozed_until = ? WHERE id = ?",
        [$snoozedUntil, $userId]
    );

    db_insert('audit_log', [
        'user_id'      => $currentId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'settings',
        'entity_type'  => 'user_briefing_snooze',
        'entity_id'    => $userId,
        'entity_label' => $target['name'] . ' <' . $target['email'] . '>',
        'notes'        => $snoozedUntil === null
            ? ($isSelf ? 'Self-cleared briefing snooze.' : 'Cleared briefing snooze on behalf of user.')
            : ($isSelf ? "Self-snoozed briefing until {$snoozedUntilLocal}." : "Snoozed briefing until {$snoozedUntilLocal} on behalf of user."),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success([
        'user_id'        => $userId,
        'snoozed_until'  => $snoozedUntil,       // stored value, UTC
        'snoozed_until_local' => $snoozedUntilLocal, // company-local display string
        'is_self'        => $isSelf,
        'cleared'        => $snoozedUntil === null,
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Set snooze failed: ' . $e->getMessage(), 500);
}
