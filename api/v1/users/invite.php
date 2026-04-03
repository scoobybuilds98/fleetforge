<?php
declare(strict_types=1);

/**
 * api/v1/users/invite.php
 *
 * Resend an invite to a user who has not yet activated their account.
 *
 * Business rules:
 *   - User must exist and not be deleted.
 *   - User must have status='invited' (not already active/suspended/etc).
 *   - Regenerates a fresh token (new 64-char plain, new SHA-256 hash).
 *   - Resets invite_token_expiry to NOW() + 7 days.
 *   - Resends invite email via Mailer (dev: logs/mail.log).
 *   - Audit log: action='update', notes='Invite resent'.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('users','create')
 * @returns 200 { invite_resent: true }
 *          404 NOT_FOUND | 422 VALIDATION_ERROR
 *
 * Decisions: D5 (soft delete), D7 (routing), §7 (audit log)
 * Session: S017
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('users', 'create');

// -----------------------------------------------------------------------
// 1. Parse body
// -----------------------------------------------------------------------
$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// -----------------------------------------------------------------------
// 2. Fetch user
// -----------------------------------------------------------------------
$user = db_row(
    "SELECT u.id, u.name, u.email, u.status, ur.name AS role_name
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$id]
);
if (!$user) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

// -----------------------------------------------------------------------
// 3. Only 'invited' users can receive a resent invite
// -----------------------------------------------------------------------
if ($user['status'] !== 'invited') {
    json_error('VALIDATION_ERROR', 'Invite can only be resent to users with status "invited".', 422);
}

// -----------------------------------------------------------------------
// 4. Regenerate invite token — new plain + new SHA-256 hash
// -----------------------------------------------------------------------
$plainToken = bin2hex(random_bytes(32));        // fresh 64-char hex token
$tokenHash  = hash('sha256', $plainToken);      // stored in DB

// -----------------------------------------------------------------------
// 5. Update token + expiry inside transaction + audit log
// -----------------------------------------------------------------------
db_transaction(function () use ($id, $tokenHash, $user) {
    db_execute(
        "UPDATE users
         SET invite_token        = ?,
             invite_token_expiry = DATE_ADD(NOW(), INTERVAL 7 DAY),
             invite_sent_at      = NOW()
         WHERE id = ?",
        [$tokenHash, $id]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'users',
        'entity_type'  => 'user',
        'entity_id'    => $id,
        'entity_label' => $user['email'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'notes'        => 'Invite resent',
    ]);
});

// -----------------------------------------------------------------------
// 6. Resend invite email (dev: logs to logs/mail.log)
// -----------------------------------------------------------------------
// WHY underscore: router maps URL segments to files, accept_invite.php uses underscore
$inviteLink = base_url('auth/accept_invite') . '?token=' . urlencode($plainToken);
$appName    = settings_get('company.name', 'FleetForge');

$htmlBody = "
<p>Hi {$user['name']},</p>
<p>Your invitation to <strong>{$appName}</strong> as <strong>{$user['role_name']}</strong> has been resent.</p>
<p>Click the link below to set up your account. This link expires in 7 days.</p>
<p><a href=\"{$inviteLink}\">{$inviteLink}</a></p>
<p>If you did not expect this invitation, you can safely ignore this email.</p>
<p>— The {$appName} Team</p>
";

$textBody = "Hi {$user['name']},\n\nYour invitation to {$appName} as {$user['role_name']} has been resent.\n\n"
    . "Set up your account here (link expires in 7 days):\n{$inviteLink}\n\n"
    . "If you did not expect this invitation, you can safely ignore this email.\n\n"
    . "— The {$appName} Team";

\FleetForge\Notifications\Mailer::send(
    toEmail:  $user['email'],
    toName:   $user['name'],
    subject:  "Your invitation to {$appName} (resent)",
    htmlBody: $htmlBody,
    textBody: $textBody
);

json_success(['invite_resent' => true]);
