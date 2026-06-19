<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/set_channels.php
 *
 * Update per-user briefing channels (F6).
 *
 * @method  POST
 * @body    JSON: {
 *     user_id: int,
 *     channels: array<string>,   // any of 'email', 'slack', 'sms'
 *     slack_user_id?: string|null,
 *     phone_e164?: string|null
 * }
 *
 * @session  S-INTEL-V2 Phase D
 * @decision D-INTEL-V2-6
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
    json_error('FORBIDDEN', 'Setting other users\' channels requires settings.edit.', 403);
}

$rawChannels = $body['channels'] ?? null;
if (!is_array($rawChannels)) {
    json_error('VALIDATION_ERROR', 'channels must be an array.', 422);
}
$valid = ['email', 'slack', 'sms'];
$channels = array_values(array_intersect($valid, array_map('strval', $rawChannels)));
if (empty($channels)) {
    // Allow empty (means: opted in but no channels enabled — effectively pause)
    $channels = [];
}

$slackId  = isset($body['slack_user_id']) ? (string) $body['slack_user_id'] : null;
$phoneE164 = isset($body['phone_e164']) ? (string) $body['phone_e164'] : null;

if ($phoneE164 !== null && $phoneE164 !== '' && !preg_match('/^\+[1-9]\d{6,14}$/', $phoneE164)) {
    json_error('VALIDATION_ERROR', 'phone_e164 must be E.164 (e.g. +14155551212) or empty/null.', 422);
}

// slack_user_id is users.slack_user_id varchar(50) — validate length so an
// over-long value returns a clean 422 instead of STRICT 1406 → generic 500.
// S-AI-AUDIT-HIGH-FIX.
if ($slackId !== null && strlen($slackId) > 50) {
    json_error('VALIDATION_ERROR', 'slack_user_id must be 50 characters or fewer.', 422);
}

try {
    $target = db_row("SELECT id, name, email FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
    if (!$target) {
        json_error('NOT_FOUND', "User #{$userId} not found", 404);
    }

    db_execute(
        "UPDATE users SET
            briefing_channels = ?,
            slack_user_id     = ?,
            phone_e164        = ?
          WHERE id = ?",
        [
            json_encode($channels, JSON_UNESCAPED_SLASHES),
            $slackId === '' ? null : $slackId,
            $phoneE164 === '' ? null : $phoneE164,
            $userId,
        ]
    );

    db_insert('audit_log', [
        'user_id'      => $currentId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'settings',
        'entity_type'  => 'user_briefing_channels',
        'entity_id'    => $userId,
        'entity_label' => $target['name'] . ' <' . $target['email'] . '>',
        'notes'        => 'Channels set to [' . implode(', ', $channels) . ']'
                        . ($slackId ? '; slack_user_id=' . $slackId : '')
                        . ($phoneE164 ? '; phone_e164=' . $phoneE164 : ''),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success(['user_id' => $userId, 'channels' => $channels, 'slack_user_id' => $slackId, 'phone_e164' => $phoneE164]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Set channels failed: ' . $e->getMessage(), 500);
}
