<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/set_opt_in.php
 *
 * Toggle morning_briefing_opt_in for a single user. Used by:
 *   - The user's own profile/settings page (self-toggle without
 *     elevated permission — anyone can opt themselves in/out).
 *   - The Intelligence settings tab's "Manage Recipients" table
 *     where super_admin can flip the flag on behalf of any user.
 *
 * Authorization rule:
 *   - If user_id == current_user_id() → no extra check, anyone can
 *     toggle their own subscription.
 *   - Else → require settings.edit (super_admin). Toggling another
 *     user's preference is an admin action.
 *
 * @method  POST
 * @auth    require_auth_api (granular check inline)
 * @body    JSON: { user_id: int, opt_in: 0|1 }
 * @returns 200 { success: true, user_id, opt_in }
 *
 * @session  S-INTEL-TAB
 * @decision D-INTEL-1 (per-user opt-in granularity)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

$body  = json_body();
$userId = isset($body['user_id']) ? (int) $body['user_id'] : 0;
$optIn  = isset($body['opt_in']) ? (int) (bool) $body['opt_in'] : 0;

if ($userId <= 0) {
    json_error('VALIDATION_ERROR', 'user_id required', 422);
}

$currentId = (int) current_user_id();
$isSelf    = ($userId === $currentId);

if (!$isSelf) {
    // Toggling someone else's preference requires settings.edit.
    if (!can('settings', 'edit')) {
        json_error('FORBIDDEN', 'You can only change your own briefing preference. Settings edit permission is required to change other users.', 403);
    }
}

try {
    $target = db_row(
        "SELECT id, name, email FROM users WHERE id = ? AND deleted_at IS NULL",
        [$userId]
    );
    if (!$target) {
        json_error('NOT_FOUND', "User #{$userId} not found", 404);
    }

    db_execute(
        "UPDATE users SET morning_briefing_opt_in = ? WHERE id = ?",
        [$optIn, $userId]
    );

    db_insert('audit_log', [
        'user_id'      => $currentId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'settings',
        'entity_type'  => 'user_briefing_opt_in',
        'entity_id'    => $userId,
        'entity_label' => $target['name'] . ' <' . $target['email'] . '>',
        'notes'        => $isSelf
            ? ('Self-' . ($optIn ? 'subscribed' : 'unsubscribed') . ' from morning briefing.')
            : ('Set briefing opt_in=' . ($optIn ? '1' : '0') . ' on behalf of user.'),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success([
        'user_id' => $userId,
        'opt_in'  => $optIn,
        'is_self' => $isSelf,
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Set opt-in failed: ' . $e->getMessage(), 500);
}
