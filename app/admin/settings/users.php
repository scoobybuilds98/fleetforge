<?php
declare(strict_types=1);

/**
 * app/admin/settings/users.php
 *
 * Admin user management tab — included by settings/index.php.
 * Lists all admin users, allows invite, status change, role change.
 * Super_admin only for destructive actions; managers can view.
 *
 * Variables inherited from parent: $canEdit, $isSuperAdmin, $csrfToken
 */

// Fetch roles for dropdown
$roles = db_select("SELECT id, name, slug FROM user_roles ORDER BY id ASC");

// Fetch all admin users (soft-delete aware)
$users = db_select(
    "SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at,
            r.name AS role_name, r.slug AS role_slug, r.id AS role_id
     FROM users u
     JOIN user_roles r ON r.id = u.role_id
     WHERE u.deleted_at IS NULL
     ORDER BY r.id ASC, u.name ASC"
);

// Handle POST actions for user management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = clean_string($_POST['user_action'] ?? null);
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if ($action && hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {

        if ($action === 'invite_user' && $isSuperAdmin) {
            $invName  = clean_string($_POST['inv_name'] ?? null);
            $invEmail = clean_string($_POST['inv_email'] ?? null);
            $invRole  = clean_int($_POST['inv_role_id'] ?? null);

            if ($invName && $invEmail && $invRole) {
                $exists = db_row("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL", [$invEmail]);
                if ($exists) {
                    $saveError = 'A user with this email already exists.';
                } else {
                    $plainToken = bin2hex(random_bytes(32));
                    $tokenHash  = hash('sha256', $plainToken);
                    $expiry     = date('Y-m-d H:i:s', strtotime('+7 days'));

                    $newUserId = db_insert('users', [
                        'name'               => $invName,
                        'email'              => $invEmail,
                        'role_id'            => $invRole,
                        'status'             => 'invited',
                        'invite_token'       => $tokenHash,
                        'invite_token_expiry' => $expiry,
                        'invite_sent_at'     => date('Y-m-d H:i:s'),
                        'created_by'         => current_user_id(),
                    ]);

                    // Log invite URL (dev mode)
                    $resetUrl = base_url('auth/set_password?token=' . $plainToken);
                    $logDir = FF_ROOT . '/logs';
                    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                    file_put_contents($logDir . '/mail.log', sprintf(
                        "[%s] ADMIN INVITE: To: %s | Name: %s | Role: %s | Set Password URL: %s\n",
                        date('Y-m-d H:i:s'), $invEmail, $invName, $invRole, $resetUrl
                    ), FILE_APPEND);

                    db_insert('audit_log', [
                        'user_id'      => current_user_id(),
                        'user_name'    => current_user()['name'],
                        'action'       => 'create',
                        'module'       => 'users',
                        'entity_type'  => 'user',
                        'entity_id'    => $newUserId,
                        'entity_label' => $invName,
                        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                        'notes'        => "Invited user {$invEmail} with role {$invRole}",
                    ]);

                    $_SESSION['settings_flash'] = "Invitation sent to {$invEmail}.";
                    header('Location: ' . base_url('settings?tab=users'));
                    exit;
                }
            }
        }

        if ($action === 'change_status' && $isSuperAdmin) {
            $targetId  = clean_int($_POST['target_user_id'] ?? null);
            $newStatus = clean_string($_POST['new_status'] ?? null);
            $allowed   = ['active', 'inactive', 'suspended'];

            if ($targetId && $newStatus && in_array($newStatus, $allowed, true)) {
                // Cannot deactivate yourself
                if ($targetId === current_user_id()) {
                    $saveError = 'You cannot change your own status.';
                } else {
                    db_update('users', ['status' => $newStatus], 'id = ? AND deleted_at IS NULL', [$targetId]);

                    $target = db_row("SELECT name FROM users WHERE id = ?", [$targetId]);
                    db_insert('audit_log', [
                        'user_id'      => current_user_id(),
                        'user_name'    => current_user()['name'],
                        'action'       => 'status_change',
                        'module'       => 'users',
                        'entity_type'  => 'user',
                        'entity_id'    => $targetId,
                        'entity_label' => $target['name'] ?? '',
                        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                        'notes'        => "Status changed to {$newStatus}",
                    ]);

                    $_SESSION['settings_flash'] = 'User status updated.';
                    header('Location: ' . base_url('settings?tab=users'));
                    exit;
                }
            }
        }

        if ($action === 'change_role' && $isSuperAdmin) {
            $targetId = clean_int($_POST['target_user_id'] ?? null);
            $newRole  = clean_int($_POST['new_role_id'] ?? null);

            if ($targetId && $newRole && $targetId !== current_user_id()) {
                db_update('users', ['role_id' => $newRole], 'id = ? AND deleted_at IS NULL', [$targetId]);

                $target = db_row("SELECT name FROM users WHERE id = ?", [$targetId]);
                $roleName = db_row("SELECT name FROM user_roles WHERE id = ?", [$newRole]);

                db_insert('audit_log', [
                    'user_id'      => current_user_id(),
                    'user_name'    => current_user()['name'],
                    'action'       => 'update',
                    'module'       => 'users',
                    'entity_type'  => 'user',
                    'entity_id'    => $targetId,
                    'entity_label' => $target['name'] ?? '',
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    'notes'        => "Role changed to " . ($roleName['name'] ?? $newRole),
                ]);

                $_SESSION['settings_flash'] = 'User role updated.';
                header('Location: ' . base_url('settings?tab=users'));
                exit;
            }
        }

        if ($action === 'delete_user' && $isSuperAdmin) {
            $targetId = clean_int($_POST['target_user_id'] ?? null);
            if ($targetId && $targetId !== current_user_id()) {
                $target = db_row("SELECT name, email FROM users WHERE id = ? AND deleted_at IS NULL", [$targetId]);
                if ($target) {
                    db_update('users', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$targetId]);

                    db_insert('audit_log', [
                        'user_id'      => current_user_id(),
                        'user_name'    => current_user()['name'],
                        'action'       => 'delete',
                        'module'       => 'users',
                        'entity_type'  => 'user',
                        'entity_id'    => $targetId,
                        'entity_label' => $target['name'],
                        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                        'notes'        => "Soft-deleted user {$target['email']}",
                    ]);

                    $_SESSION['settings_flash'] = 'User deleted.';
                    header('Location: ' . base_url('settings?tab=users'));
                    exit;
                }
            }
        }
    }
}

// Re-fetch after possible changes
$users = db_select(
    "SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at,
            r.name AS role_name, r.slug AS role_slug, r.id AS role_id
     FROM users u
     JOIN user_roles r ON r.id = u.role_id
     WHERE u.deleted_at IS NULL
     ORDER BY r.id ASC, u.name ASC"
);

$statusBadge = [
    'active'    => 'badge-success',
    'inactive'  => 'badge-neutral',
    'invited'   => 'badge-info',
    'suspended' => 'badge-danger',
    'locked'    => 'badge-warning',
];
?>

<!-- Invite User Form -->
<?php if ($isSuperAdmin): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">Invite New Admin User</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="user_action" value="invite_user">
            <div style="display:grid;grid-template-columns:1fr 1fr 200px auto;gap:12px;align-items:end;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Name</label>
                    <input type="text" name="inv_name" class="form-control" placeholder="Full name" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Email</label>
                    <input type="email" name="inv_email" class="form-control" placeholder="user@company.com" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Role</label>
                    <select name="inv_role_id" class="form-control" required>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= e((string)$r['id']) ?>"><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Send Invite</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Users Table -->
<div class="card">
    <div class="card-header" style="font-weight:600;display:flex;justify-content:space-between;align-items:center;">
        <span>Admin Users</span>
        <span style="font-size:0.8125rem;color:var(--text-muted);"><?= e(count($users)) ?> user<?= count($users) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Joined</th>
                    <?php if ($isSuperAdmin): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td style="font-weight:600;">
                        <?= e($u['name']) ?>
                        <?php if ((int)$u['id'] === current_user_id()): ?>
                            <span style="font-size:0.7rem;color:var(--text-muted);margin-left:4px;">(you)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($u['email']) ?></td>
                    <td>
                        <?php if ($isSuperAdmin && (int)$u['id'] !== current_user_id()): ?>
                        <form method="POST" style="display:inline;" class="inline-role-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="user_action" value="change_role">
                            <input type="hidden" name="target_user_id" value="<?= e((string)$u['id']) ?>">
                            <select name="new_role_id" class="form-control" style="font-size:0.8125rem;padding:2px 6px;width:auto;display:inline-block;"
                                    onchange="this.form.submit()">
                                <?php foreach ($roles as $r): ?>
                                <option value="<?= e((string)$r['id']) ?>" <?= (int)$u['role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php else: ?>
                        <span class="badge badge-info"><?= e($u['role_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= e($statusBadge[$u['status']] ?? 'badge-neutral') ?>"><?= e(ucfirst($u['status'])) ?></span></td>
                    <td class="font-mono" style="font-size:0.8125rem;"><?= $u['last_login_at'] ? e(format_datetime($u['last_login_at'])) : '<span style="color:var(--text-muted);">Never</span>' ?></td>
                    <td class="font-mono" style="font-size:0.8125rem;"><?= e(format_date($u['created_at'])) ?></td>
                    <?php if ($isSuperAdmin): ?>
                    <td>
                        <?php if ((int)$u['id'] !== current_user_id()): ?>
                        <div style="display:flex;gap:4px;justify-content:flex-end;">
                            <?php if ($u['status'] === 'active'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="user_action" value="change_status">
                                <input type="hidden" name="target_user_id" value="<?= e((string)$u['id']) ?>">
                                <input type="hidden" name="new_status" value="suspended">
                                <button type="submit" class="btn btn-ghost btn-xs" style="color:var(--color-warning);"
                                        onclick="return confirm('Suspend this user?')">Suspend</button>
                            </form>
                            <?php elseif (in_array($u['status'], ['inactive', 'suspended', 'locked'], true)): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="user_action" value="change_status">
                                <input type="hidden" name="target_user_id" value="<?= e((string)$u['id']) ?>">
                                <input type="hidden" name="new_status" value="active">
                                <button type="submit" class="btn btn-ghost btn-xs" style="color:var(--color-success);">Activate</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="user_action" value="delete_user">
                                <input type="hidden" name="target_user_id" value="<?= e((string)$u['id']) ?>">
                                <button type="submit" class="btn btn-ghost btn-xs" style="color:var(--color-danger);"
                                        onclick="return confirm('Delete this user? This action is reversible (soft delete).')">Delete</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
