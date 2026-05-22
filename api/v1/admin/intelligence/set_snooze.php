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
 *   YYYY-MM-DD HH:MM:SS — explicit datetime
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

    // Resolve snoozed_until value.
    $snoozedUntil = null;
    if ($raw !== null && $raw !== '') {
        $s = (string) $raw;
        if ($s === '1d') {
            $snoozedUntil = date('Y-m-d H:i:s', strtotime('+1 day'));
        } elseif ($s === '1w') {
            $snoozedUntil = date('Y-m-d H:i:s', strtotime('+1 week'));
        } elseif ($s === 'until_monday') {
            $snoozedUntil = date('Y-m-d 00:00:00', strtotime('next monday'));
        } elseif (preg_match('/^until_(\d{4}-\d{2}-\d{2})$/', $s, $m)) {
            $snoozedUntil = $m[1] . ' 00:00:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $s)) {
            $snoozedUntil = strlen($s) === 10 ? $s . ' 00:00:00' : $s;
        } else {
            json_error('VALIDATION_ERROR', "Invalid snoozed_until value: '{$s}'. Use null, '1d', '1w', 'until_monday', 'until_YYYY-MM-DD', or YYYY-MM-DD HH:MM:SS.", 422);
        }
    }

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
            : ($isSelf ? "Self-snoozed briefing until {$snoozedUntil}." : "Snoozed briefing until {$snoozedUntil} on behalf of user."),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success([
        'user_id'        => $userId,
        'snoozed_until'  => $snoozedUntil,
        'is_self'        => $isSelf,
        'cleared'        => $snoozedUntil === null,
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Set snooze failed: ' . $e->getMessage(), 500);
}
