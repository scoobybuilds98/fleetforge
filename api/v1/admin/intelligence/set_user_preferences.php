<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/set_user_preferences.php
 *
 * Update per-user briefing preferences (F4 hour + F5 sections).
 *
 * Authorization:
 *   - self → no extra permission
 *   - other → require settings.edit
 *
 * @method  POST
 * @body    JSON: {
 *     user_id: int,
 *     briefing_hour: int|null,        // 0..23, or null to clear (use global)
 *     briefing_sections: array|null   // section keys; null = all
 * }
 * @returns 200 { user_id, briefing_hour, briefing_sections }
 *
 * @session  S-INTEL-V2 Phase C
 * @decision D-INTEL-V2-2 (per-user hour)
 * @decision D-INTEL-V2-4 (per-user sections)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

$body  = json_body();
$userId = isset($body['user_id']) ? (int) $body['user_id'] : 0;

if ($userId <= 0) {
    json_error('VALIDATION_ERROR', 'user_id required', 422);
}

$currentId = (int) current_user_id();
$isSelf    = ($userId === $currentId);
if (!$isSelf && !can('settings', 'edit')) {
    json_error('FORBIDDEN', 'Changing other users\' preferences requires settings.edit.', 403);
}

// Parse + validate hour.
$hourRaw = $body['briefing_hour'] ?? '__unset__';
$hour    = null;
$hourTouched = false;
if ($hourRaw !== '__unset__') {
    $hourTouched = true;
    if ($hourRaw === null || $hourRaw === '') {
        $hour = null; // clear → use global
    } else {
        $h = (int) $hourRaw;
        if ($h < 0 || $h > 23) {
            json_error('VALIDATION_ERROR', "briefing_hour must be 0..23 or null; got {$hourRaw}", 422);
        }
        $hour = $h;
    }
}

// Parse + validate sections.
$secRaw = $body['briefing_sections'] ?? '__unset__';
$secJson = null;
$secTouched = false;
$validSections = ['overdue', 'compliance', 'damage', 'risk_high', 'health_drops', 'brief'];
if ($secRaw !== '__unset__') {
    $secTouched = true;
    if ($secRaw === null) {
        $secJson = null; // clear → all sections
    } else {
        if (!is_array($secRaw)) {
            json_error('VALIDATION_ERROR', 'briefing_sections must be an array of section keys or null.', 422);
        }
        $clean = [];
        foreach ($secRaw as $k) {
            if (is_string($k) && in_array($k, $validSections, true)) $clean[] = $k;
        }
        $secJson = json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_SLASHES);
    }
}

try {
    $target = db_row("SELECT id, name, email FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
    if (!$target) {
        json_error('NOT_FOUND', "User #{$userId} not found", 404);
    }

    $sets = [];
    $params = [];
    if ($hourTouched) {
        $sets[] = 'briefing_hour = ?';
        $params[] = $hour;
    }
    if ($secTouched) {
        $sets[] = 'briefing_sections = ?';
        $params[] = $secJson;
    }
    if (!empty($sets)) {
        $params[] = $userId;
        db_execute("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }

    // Audit.
    $notes = [];
    if ($hourTouched) $notes[] = 'briefing_hour=' . ($hour === null ? 'NULL (global)' : (string) $hour);
    if ($secTouched)  $notes[] = 'briefing_sections=' . ($secJson === null ? 'NULL (all)' : $secJson);
    db_insert('audit_log', [
        'user_id'      => $currentId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'settings',
        'entity_type'  => 'user_briefing_preferences',
        'entity_id'    => $userId,
        'entity_label' => $target['name'] . ' <' . $target['email'] . '>',
        'notes'        => ($isSelf ? 'Self-update: ' : 'Set by admin: ') . implode('; ', $notes),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    // Read back current state.
    $now = db_row("SELECT briefing_hour, briefing_sections FROM users WHERE id = ?", [$userId]);

    json_success([
        'user_id'           => $userId,
        'briefing_hour'     => $now['briefing_hour'] !== null ? (int) $now['briefing_hour'] : null,
        'briefing_sections' => $now['briefing_sections'] !== null ? json_decode((string) $now['briefing_sections'], true) : null,
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Set preferences failed: ' . $e->getMessage(), 500);
}
