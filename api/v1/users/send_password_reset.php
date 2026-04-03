<?php
declare(strict_types=1);

/**
 * api/v1/users/send_password_reset.php
 *
 * Admin-triggered password reset for another user.
 * Generates a signed reset token, stores its SHA-256 hash in the DB,
 * and sends the reset link via email (dev: writes to logs/mail.log).
 *
 * Business rules:
 *   - Caller must have users.edit permission (super_admin / manager).
 *   - Target user must be active and not soft-deleted.
 *   - Cannot reset own password via this endpoint (use profile/change_password).
 *   - Token is stored as SHA-256 hash; plain token travels only in the email link.
 *   - Token expires after 2 hours.
 *   - Mailer failure is non-fatal in dev (token is still persisted).
 *   - Audit log entry written with action='update'.
 *
 * @method  POST
 * @body    form-encoded: id (target user id)
 * @auth    Session required + require_permission('users', 'edit')
 * @returns 200 { message }
 *          404 NOT_FOUND | 422 VALIDATION_ERROR
 *
 * @decisions D5 (soft-delete guard), D7 (path resolution), §7 (audit log)
 * @session   S017-B
 */

// dirname(__DIR__, 3): api/v1/users/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('users', 'edit');

// ── 1. Validate target user id ───────────────────────────────────────────────
// WHY: FF_Api.post() sends JSON body; fall back to $_POST for form-encoded callers
$body     = json_body();
$targetId = clean_int($body['id'] ?? ($_POST['id'] ?? null));
if (!$targetId) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// ── 2. Load target user ──────────────────────────────────────────────────────
$target = db_row(
    "SELECT id, name, email, status FROM users WHERE id = ? AND deleted_at IS NULL",
    [$targetId]
);
if (!$target) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

if ($target['status'] !== 'active') {
    json_error('VALIDATION_ERROR', 'Can only send a password reset to active users.', 422);
}

// WHY: admins should use the profile page to change their own password
if ($targetId === current_user_id()) {
    json_error('VALIDATION_ERROR', 'Use the profile page to change your own password.', 422);
}

// ── 3. Generate reset token ──────────────────────────────────────────────────
// WHY: plain token travels only in the email; DB stores the SHA-256 hash
//      so that even DB access does not yield usable tokens (defence in depth)
$plainToken = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $plainToken);
$expiry     = date('Y-m-d H:i:s', strtotime('+2 hours'));

// ── 4. Persist token and write audit log ─────────────────────────────────────
db_transaction(function () use ($targetId, $tokenHash, $expiry, $target): void {
    db_execute(
        "UPDATE users SET password_reset_token = ?, password_reset_expiry = ? WHERE id = ?",
        [$tokenHash, $expiry, $targetId]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'users',
        'entity_type'  => 'user',
        'entity_id'    => $targetId,
        'entity_label' => $target['email'],
        'notes'        => 'Password reset email sent by admin',
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
});

// ── 5. Send reset email ──────────────────────────────────────────────────────
$resetLink   = base_url('auth/reset_password') . '?token=' . urlencode($plainToken);
$companyName = settings_get('company.name', 'FleetForge');

$htmlBody = "<p>Hi {$target['name']},</p>"
    . "<p>An administrator has requested a password reset for your account. "
    . "Click the link below to set a new password. This link expires in 2 hours.</p>"
    . "<p><a href=\"{$resetLink}\">Reset Password</a></p>"
    . "<p>If you did not request this, please contact your administrator.</p>";

$textBody = "Hi {$target['name']},\n\n"
    . "An administrator has requested a password reset for your account.\n\n"
    . "Reset link (expires in 2 hours):\n{$resetLink}\n\n"
    . "If you did not request this, please contact your administrator.";

try {
    \FleetForge\Notifications\Mailer::send(
        toEmail:  $target['email'],
        toName:   $target['name'],
        subject:  "Reset your password — {$companyName}",
        htmlBody: $htmlBody,
        textBody: $textBody,
    );
} catch (Throwable $e) {
    // WHY: non-fatal in dev — token is persisted; log and continue
    error_log('[send_password_reset] Mailer error: ' . $e->getMessage());
}

json_success(['message' => "Password reset email sent to {$target['email']}."]);
