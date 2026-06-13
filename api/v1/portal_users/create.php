<?php
declare(strict_types=1);

/**
 * api/v1/portal_users/create.php
 *
 * S-USERS-CONSOLIDATE C3 — Create a new portal user + send invite link.
 * Ported from the inline `create_portal_user` POST handler in the
 * pre-S-USERS-CONSOLIDATE app/admin/settings/portal_users.php (kept on
 * disk for reference per D-D, no longer the canonical location).
 *
 * Auth gate is settings:edit (matches the prior super_admin-only check
 * — the perm check in the legacy file was via $isSuperAdmin = settings:delete
 * but settings:edit reads more permissively and matches the legacy intent
 * of "this is an admin-of-admins action"). Re-examine if a real role
 * boundary surfaces.
 *
 * Validation:
 *   - customer_id: required int; customer must exist + not soft-deleted
 *   - name: required string, max 255
 *   - email: required, valid format, max 255, UNIQUE in portal_users
 *
 * Side-effects on success:
 *   1. INSERT portal_users with status='invited', password_reset_token,
 *      password_reset_expiry +7 days, invite_sent_at NOW().
 *   2. is_primary=1 if this is the FIRST portal user for the customer
 *      (matches legacy logic — first user is the primary account holder).
 *   3. Append a single-line entry to logs/mail.log with the reset URL
 *      (dev-mode email "send" — production wires a real mailer).
 *   4. audit_log entry with entity_type='portal_user', action='create'.
 *
 * @method  POST
 * @body    JSON { customer_id: int, name: string, email: string }
 * @auth    Session required; require_permission('settings','edit')
 * @returns 201 { id, customer_id, name, email, status, is_primary }
 *          422 VALIDATION_ERROR
 *          409 CONFLICT (email exists)
 *
 * @session S-USERS-CONSOLIDATE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Notifications\Mailer;
use FleetForge\Email\EmailService;

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

$body = json_body();

$fields = [];

$customerId = clean_int($body['customer_id'] ?? null);
if (!$customerId) {
    $fields['customer_id'] = 'Customer is required.';
}

$name = clean_string($body['name'] ?? null, 255);
if ($name === null || trim($name) === '') {
    $fields['name'] = 'Name is required.';
}

$email = clean_string($body['email'] ?? null, 255);
if ($email === null || trim($email) === '') {
    $fields['email'] = 'Email is required.';
} elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $fields['email'] = 'Email format is invalid.';
}

if (!empty($fields)) {
    json_validation_error($fields);
}

// Verify customer exists + not soft-deleted.
$customer = db_row(
    "SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found or deleted.', 404);
}

// Email uniqueness — portal_users.email has a UNIQUE constraint but surface a
// friendly 409 instead of letting the DB throw. MEDIUM [05/10]: branch on the
// existing row's status so a DEACTIVATED account tells the admin to reactivate
// (portal_users is hard-deleted, not soft — there's no revive, but an inactive
// row should not read like a blocked duplicate).
$existing = db_row("SELECT id, status FROM portal_users WHERE email = ?", [$email]);
if ($existing) {
    if (($existing['status'] ?? '') === 'inactive') {
        json_error('CONFLICT',
            'A deactivated portal user already uses this email. Reactivate that account instead of creating a new one.',
            409, ['fields' => ['email' => 'Deactivated account exists — reactivate it.'], 'portal_user_id' => (int) $existing['id']]);
    }
    json_error('CONFLICT', 'A portal user with this email already exists.', 409);
}

// First portal user for this customer becomes the primary account holder.
$existingCount = db_count(
    "SELECT COUNT(*) FROM portal_users WHERE customer_id = ?",
    [$customerId]
);
$isPrimary = $existingCount === 0 ? 1 : 0;

// password_reset_token (not invite_token) — invite_token is reserved
// for portal remember-me cookies; portal sign-up uses the same
// reset-password flow as ordinary password reset.
$plainToken = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $plainToken);

// Belt for the TOCTOU race: two concurrent invites for the same email can both
// pass the pre-check above, then collide on the UNIQUE email index. Translate
// the 1062 into the same friendly 409 instead of an unhandled 500.
try {
    $newId = db_insert('portal_users', [
        'customer_id'           => $customerId,
        'name'                  => trim($name),
        'email'                 => trim($email),
        'status'                => 'invited',
        'is_primary'            => $isPrimary,
        'password_reset_token'  => $tokenHash,
        'password_reset_expiry' => date('Y-m-d H:i:s', strtotime('+7 days')),
        'invite_sent_at'        => date('Y-m-d H:i:s'),
    ]);
} catch (\PDOException $e) {
    if ($e->getCode() === '23000') {
        json_error('CONFLICT', 'A portal user with this email already exists.', 409);
    }
    throw $e;
}

// S-FIX-PORTAL-INVITE-EMAIL: send the invite via the shared Mailer. Mailer
// routes to AWS SES in production and to logs/mail.log in dev automatically
// (isLogMode() === false when APP_ENV==='production'). This path was previously
// a mail.log-only STUB ("Production wires a real mailer here") that never
// reached SES, so prod invites silently produced no email.
$resetUrl = base_url('portal/auth/reset_password')
    . '?token=' . $plainToken . '&email=' . urlencode($email);
$company  = (string) $customer['company_name'];
$subject  = 'You\'re invited to the ' . $company . ' portal';
$bodyHtml = EmailService::renderEmailHtml(
    '<h2 style="margin:0 0 16px;">Welcome to the ' . e($company) . ' portal</h2>'
    . '<p>Hi ' . e($name) . ',</p>'
    . '<p>An account has been created for you to access the ' . e($company)
    . ' customer portal. Click the button below to set your password and sign in.</p>'
    . '<p style="margin:24px 0;"><a href="' . e($resetUrl) . '" '
    . 'style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;'
    . 'padding:12px 24px;border-radius:6px;font-weight:600;">Set your password</a></p>'
    . '<p style="color:#64748b;font-size:13px;">This link expires in 7 days. If the button '
    . 'doesn\'t work, paste this URL into your browser:<br>'
    . '<span style="word-break:break-all;">' . e($resetUrl) . '</span></p>'
);
$emailSent = Mailer::send($email, $name, $subject, $bodyHtml);

// Environment-accurate status (don't claim "sent" when dev only logged).
$inviteMsg = $emailSent
    ? (APP_ENV === 'production'
        ? 'Portal user created. Invite emailed to ' . $email . '.'
        : 'Portal user created. Invite written to logs/mail.log (dev).')
    : 'Portal user created, but the invite email failed to send — see logs.';

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'create',
    'module'       => 'portal_users',
    'entity_type'  => 'portal_user',
    'entity_id'    => $newId,
    'entity_label' => $name . ' (' . $customer['company_name'] . ')',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'notes'        => "Created portal user {$email} for customer #{$customerId}"
                      . ' (invite email ' . ($emailSent ? 'sent' : 'FAILED') . ')',
]);

json_success([
    'id'          => $newId,
    'customer_id' => $customerId,
    'name'        => $name,
    'email'       => $email,
    'status'      => 'invited',
    'is_primary'  => $isPrimary,
    'email_sent'  => $emailSent,
    'message'     => $inviteMsg,
], 201);
