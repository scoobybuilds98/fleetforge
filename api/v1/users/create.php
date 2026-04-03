<?php
declare(strict_types=1);

/**
 * api/v1/users/create.php
 *
 * Create a new user and send an invite email.
 *
 * Business rules:
 *   - name, email, role_id are required.
 *   - email must be unique among non-deleted users.
 *   - role_id must reference a valid user_roles record.
 *   - User is created with status='invited'.
 *   - Invite token: plain = bin2hex(random_bytes(32)), stored = SHA-256 hash.
 *   - Token expires in 7 days.
 *   - Invite email sent via Mailer (in dev, writes to logs/mail.log).
 *   - Audit log: action='create'.
 *
 * @method  POST
 * @body    JSON: name (required), email (required), role_id (required),
 *               phone?, timezone?
 * @auth    Session required; require_permission('users','create')
 * @returns 201 { id, name, email, status, invite_sent }
 *
 * Decisions: D5 (soft delete), D7 (routing), §7 (audit log)
 * Session: S017
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('users', 'create');

// -----------------------------------------------------------------------
// 1. Parse JSON body
// -----------------------------------------------------------------------
$body = json_body();

// -----------------------------------------------------------------------
// 2. Validate required fields
// -----------------------------------------------------------------------
$name = clean_string($body['name'] ?? null, 255);
if (!$name) {
    json_error('MISSING_REQUIRED', 'name is required.', 422);
}

$email = clean_email($body['email'] ?? null);
if (!$email) {
    json_error('MISSING_REQUIRED', 'A valid email address is required.', 422);
}

$roleId = clean_int($body['role_id'] ?? null);
if (!$roleId) {
    json_error('MISSING_REQUIRED', 'role_id is required.', 422);
}

// -----------------------------------------------------------------------
// 3. Uniqueness check — email must be unique among non-deleted users
// -----------------------------------------------------------------------
if (db_exists('users', 'email = ? AND deleted_at IS NULL', [$email])) {
    json_error('ALREADY_EXISTS', 'A user with that email already exists.', 409);
}

// -----------------------------------------------------------------------
// 4. Validate role_id exists
// -----------------------------------------------------------------------
$role = db_row("SELECT id, name, slug FROM user_roles WHERE id = ?", [$roleId]);
if (!$role) {
    json_error('VALIDATION_ERROR', 'Invalid role_id — role does not exist.', 422);
}

// -----------------------------------------------------------------------
// 5. Optional fields
// -----------------------------------------------------------------------
$phone    = clean_string($body['phone'] ?? null, 50);
$timezone = clean_string($body['timezone'] ?? null, 100);

// -----------------------------------------------------------------------
// 6. Generate invite token — plain stored in email, hash stored in DB
// -----------------------------------------------------------------------
$plainToken = bin2hex(random_bytes(32));        // 64-char hex string
$tokenHash  = hash('sha256', $plainToken);      // SHA-256 hash stored in DB

// -----------------------------------------------------------------------
// 7. Insert user inside transaction + audit log
// -----------------------------------------------------------------------
$newId = db_transaction(function () use (
    $name, $email, $roleId, $phone, $timezone, $tokenHash
) {
    $id = db_insert('users', [
        'name'                 => $name,
        'email'                => $email,
        'role_id'              => $roleId,
        'phone'                => $phone,
        'timezone'             => $timezone,
        'status'               => 'invited',
        'invite_token'         => $tokenHash,
        'invite_token_expiry'  => date('Y-m-d H:i:s', strtotime('+7 days')),
        'invite_sent_at'       => date('Y-m-d H:i:s'),
        'created_by'           => current_user_id(),
    ]);

    // Audit log — user creation
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'users',
        'entity_type'  => 'user',
        'entity_id'    => $id,
        'entity_label' => $email,
        'new_values'   => json_encode(['name' => $name, 'email' => $email, 'status' => 'invited']),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);

    return $id;
});

// -----------------------------------------------------------------------
// 8. Send invite email (dev: logs to logs/mail.log — no exception thrown)
// -----------------------------------------------------------------------
// WHY underscore: router maps URL segments to files, accept_invite.php uses underscore
$inviteLink = base_url('auth/accept_invite') . '?token=' . urlencode($plainToken);
$appName    = settings_get('company.name', 'FleetForge');

$htmlBody = "
<p>Hi {$name},</p>
<p>You have been invited to join <strong>{$appName}</strong> as <strong>{$role['name']}</strong>.</p>
<p>Click the link below to set up your account. This link expires in 7 days.</p>
<p><a href=\"{$inviteLink}\">{$inviteLink}</a></p>
<p>If you did not expect this invitation, you can safely ignore this email.</p>
<p>— The {$appName} Team</p>
";

$textBody = "Hi {$name},\n\nYou have been invited to join {$appName} as {$role['name']}.\n\n"
    . "Set up your account here (link expires in 7 days):\n{$inviteLink}\n\n"
    . "If you did not expect this invitation, you can safely ignore this email.\n\n"
    . "— The {$appName} Team";

\FleetForge\Notifications\Mailer::send(
    toEmail:  $email,
    toName:   $name,
    subject:  "You've been invited to {$appName}",
    htmlBody: $htmlBody,
    textBody: $textBody
);

json_success([
    'id'           => $newId,
    'name'         => $name,
    'email'        => $email,
    'status'       => 'invited',
    'invite_sent'  => true,
], 201);
