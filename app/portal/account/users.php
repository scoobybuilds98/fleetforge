<?php
declare(strict_types=1);

/**
 * app/portal/account/users.php
 *
 * Sub-user management — invite new sub-users, resend invites, deactivate/reactivate.
 * Only accessible to primary account holders (portal_is_primary()).
 * Trap 8: all queries filter by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

// Only primary account holder can manage sub-users
if (!portal_is_primary()) {
    header('Location: ' . base_url('portal/account'));
    exit;
}

$cid  = portal_customer_id();
$puid = portal_user_id();

$_csrfToken = portal_csrf_token();
$success = '';
$error   = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $action = clean_string($_POST['action'] ?? null);

    if (!portal_verify_csrf($submittedCsrf)) {
        $error = 'Invalid request token. Please refresh and try again.';
    } elseif ($action === 'invite') {
        $inviteName  = clean_string($_POST['invite_name'] ?? null);
        $inviteEmail = clean_string($_POST['invite_email'] ?? null);

        if (!$inviteName) {
            $error = 'Name is required.';
        } elseif (!$inviteEmail || !filter_var($inviteEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid email address is required.';
        } else {
            // Check if email already exists in portal_users
            $existing = db_row("SELECT id, status FROM portal_users WHERE email = ?", [$inviteEmail]);
            if ($existing) {
                $error = 'A user with this email address already exists.';
            } else {
                // Generate invite token
                $plainToken = bin2hex(random_bytes(32));
                $tokenHash  = hash('sha256', $plainToken);
                $expiry     = date('Y-m-d H:i:s', strtotime('+7 days'));

                $newId = db_insert('portal_users', [
                    'customer_id'        => $cid,
                    'name'               => $inviteName,
                    'email'              => $inviteEmail,
                    'status'             => 'invited',
                    'is_primary'         => 0,
                    'invite_token'       => $tokenHash,
                    'invite_token_expiry' => $expiry,
                    'invite_sent_at'     => date('Y-m-d H:i:s'),
                ]);

                // Log invite URL (dev mode — no real email sending)
                $resetUrl = base_url('portal/auth/reset_password?token=' . $plainToken);
                $logDir = dirname(__DIR__, 3) . '/logs';
                if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                file_put_contents(
                    $logDir . '/mail.log',
                    sprintf(
                        "[%s] PORTAL INVITE: To: %s | Name: %s | Set Password URL: %s\n",
                        date('Y-m-d H:i:s'),
                        $inviteEmail,
                        $inviteName,
                        $resetUrl
                    ),
                    FILE_APPEND
                );

                $success = 'Invitation sent to ' . $inviteEmail . '. They can set their password using the link sent to their email.';
            }
        }
    } elseif ($action === 'deactivate') {
        $targetId = clean_int($_POST['user_id'] ?? null);
        if ($targetId) {
            // Cannot deactivate self or other primary users
            $target = db_row(
                "SELECT id, is_primary FROM portal_users WHERE id = ? AND customer_id = ?",
                [$targetId, $cid]
            );
            if (!$target) {
                $error = 'User not found.';
            } elseif ($target['is_primary']) {
                $error = 'Cannot deactivate the primary account holder.';
            } else {
                db_update('portal_users', ['status' => 'inactive'], 'id = ? AND customer_id = ?', [$targetId, $cid]);
                $success = 'User has been deactivated.';
            }
        }
    } elseif ($action === 'reactivate') {
        $targetId = clean_int($_POST['user_id'] ?? null);
        if ($targetId) {
            $target = db_row(
                "SELECT id, status FROM portal_users WHERE id = ? AND customer_id = ? AND status = 'inactive'",
                [$targetId, $cid]
            );
            if (!$target) {
                $error = 'User not found or not inactive.';
            } else {
                db_update('portal_users', ['status' => 'active'], 'id = ? AND customer_id = ?', [$targetId, $cid]);
                $success = 'User has been reactivated.';
            }
        }
    } elseif ($action === 'resend_invite') {
        $targetId = clean_int($_POST['user_id'] ?? null);
        if ($targetId) {
            $target = db_row(
                "SELECT id, name, email, status FROM portal_users WHERE id = ? AND customer_id = ? AND status = 'invited'",
                [$targetId, $cid]
            );
            if (!$target) {
                $error = 'User not found or already activated.';
            } else {
                // Generate new invite token
                $plainToken = bin2hex(random_bytes(32));
                $tokenHash  = hash('sha256', $plainToken);
                $expiry     = date('Y-m-d H:i:s', strtotime('+7 days'));

                db_update('portal_users', [
                    'invite_token'        => $tokenHash,
                    'invite_token_expiry' => $expiry,
                    'invite_sent_at'      => date('Y-m-d H:i:s'),
                ], 'id = ? AND customer_id = ?', [$targetId, $cid]);

                // Log invite URL
                $resetUrl = base_url('portal/auth/reset_password?token=' . $plainToken);
                $logDir = dirname(__DIR__, 3) . '/logs';
                if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                file_put_contents(
                    $logDir . '/mail.log',
                    sprintf(
                        "[%s] PORTAL INVITE RESEND: To: %s | Name: %s | Set Password URL: %s\n",
                        date('Y-m-d H:i:s'),
                        $target['email'],
                        $target['name'],
                        $resetUrl
                    ),
                    FILE_APPEND
                );

                $success = 'Invitation resent to ' . $target['email'] . '.';
            }
        }
    }
}

// Fetch all sub-users for this customer
$subUsers = db_select(
    "SELECT id, name, email, status, is_primary, last_login_at, created_at, invite_sent_at
     FROM portal_users WHERE customer_id = ?
     ORDER BY is_primary DESC, name ASC",
    [$cid]
);

$pageTitle = 'Manage Sub-Users';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width:900px;">

    <a href="<?= e(base_url('portal/account')) ?>" class="portal-form-link" style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:4px;margin-bottom:16px;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
        Back to Account
    </a>

    <?php if ($success): ?>
        <div class="portal-login-success" style="margin-bottom:16px;"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="portal-login-error" style="margin-bottom:16px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Invite Form -->
    <div class="portal-section" style="margin-bottom:24px;">
        <div class="portal-section-header">
            <h2 class="portal-section-title">Invite New Sub-User</h2>
        </div>
        <div class="portal-section-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                <input type="hidden" name="action" value="invite">

                <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;">
                    <div class="portal-form-group" style="margin-bottom:0;">
                        <label class="portal-form-label" for="invite_name">Name</label>
                        <input type="text" id="invite_name" name="invite_name"
                               class="portal-form-input"
                               placeholder="Full name" required
                               value="<?= e($_POST['invite_name'] ?? '') ?>">
                    </div>
                    <div class="portal-form-group" style="margin-bottom:0;">
                        <label class="portal-form-label" for="invite_email">Email Address</label>
                        <input type="email" id="invite_email" name="invite_email"
                               class="portal-form-input"
                               placeholder="user@company.com" required
                               value="<?= e($_POST['invite_email'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-md">Send Invite</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Users List -->
    <div class="portal-section">
        <div class="portal-section-header">
            <h2 class="portal-section-title">Team Members</h2>
            <span style="font-size:0.8125rem;color:var(--text-muted);"><?= e(count($subUsers)) ?> user<?= count($subUsers) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="portal-section-body--flush">
            <table class="portal-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subUsers as $su): ?>
                    <tr>
                        <td style="font-weight:600;">
                            <?= e($su['name']) ?>
                            <?php if ((int)$su['id'] === $puid): ?>
                                <span style="font-size:0.7rem;color:var(--text-muted);margin-left:4px;">(you)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($su['email']) ?></td>
                        <td>
                            <?php if ($su['is_primary']): ?>
                                <span class="badge badge-info">Primary</span>
                            <?php else: ?>
                                Sub-user
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $su['status'] === 'active' ? 'badge-success' : ($su['status'] === 'invited' ? 'badge-info' : 'badge-neutral') ?>">
                                <?= e(ucfirst($su['status'])) ?>
                            </span>
                        </td>
                        <td class="font-mono"><?= $su['last_login_at'] ? e(format_datetime($su['last_login_at'])) : 'Never' ?></td>
                        <td>
                            <?php if (!$su['is_primary']): ?>
                                <div style="display:flex;gap:6px;justify-content:flex-end;">
                                    <?php if ($su['status'] === 'invited'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                                            <input type="hidden" name="action" value="resend_invite">
                                            <input type="hidden" name="user_id" value="<?= e((string)$su['id']) ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Resend Invite</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($su['status'] === 'active'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this user? They will no longer be able to log in.')">
                                            <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="user_id" value="<?= e((string)$su['id']) ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--color-danger);">Deactivate</button>
                                        </form>
                                    <?php elseif ($su['status'] === 'inactive'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                                            <input type="hidden" name="action" value="reactivate">
                                            <input type="hidden" name="user_id" value="<?= e((string)$su['id']) ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Reactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p style="margin-top:16px;font-size:0.8125rem;color:var(--text-muted);">
        Sub-users have the same read access as the primary account holder but cannot manage other users.
        Invited users will receive an email with a link to set their password.
    </p>

</div>

<style>
@media (max-width: 768px) {
    div[style*="grid-template-columns:1fr 1fr auto"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
