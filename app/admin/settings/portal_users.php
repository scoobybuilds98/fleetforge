<?php
declare(strict_types=1);

/**
 * app/admin/settings/portal_users.php
 *
 * Portal user management tab — normally included by settings/index.php.
 * Super_admin can view, manage, reset passwords for ALL customer portal users.
 * Shows portal_users grouped by customer with status, login info, actions.
 *
 * Variables inherited from parent (when included): $canEdit, $isSuperAdmin, $csrfToken
 *
 * [C0-FIX] Standalone-safe bootstrap — see settings/users.php for the full
 * rationale. Without this guard, hitting /settings/portal_users directly
 * exposes portal user data (emails, customer associations) to ANY visitor,
 * authenticated or not, because the db_select() runs before the Undefined
 * variable warning.
 */

// [C0-FIX] Detect standalone execution (see users.php).
if (!isset($canEdit)) {
    require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
    require_once FF_ROOT . '/includes/auth.php';

    require_auth();
    require_permission('settings', 'view');

    $canEdit      = can('settings', 'edit');
    $isSuperAdmin = can('settings', 'delete');
    $csrfToken    = generate_csrf_token();

    $pageTitle = 'Portal Users';
    require_once FF_ROOT . '/includes/header.php';
    $_ff_standalone_portal_users = true;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSuperAdmin) {
    $action = clean_string($_POST['portal_action'] ?? null);
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if ($action && hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {

        if ($action === 'change_portal_status') {
            $targetId  = clean_int($_POST['target_pu_id'] ?? null);
            $newStatus = clean_string($_POST['new_status'] ?? null);
            $allowed   = ['active', 'inactive'];

            if ($targetId && $newStatus && in_array($newStatus, $allowed, true)) {
                db_update('portal_users', ['status' => $newStatus], 'id = ?', [$targetId]);

                $target = db_row("SELECT pu.name, c.company_name FROM portal_users pu JOIN customers c ON c.id = pu.customer_id WHERE pu.id = ?", [$targetId]);
                db_insert('audit_log', [
                    'user_id'      => current_user_id(),
                    'user_name'    => current_user()['name'],
                    'action'       => 'status_change',
                    'module'       => 'settings',
                    'entity_type'  => 'portal_user',
                    'entity_id'    => $targetId,
                    'entity_label' => ($target['name'] ?? '') . ' (' . ($target['company_name'] ?? '') . ')',
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    'notes'        => "Portal user status changed to {$newStatus}",
                ]);

                $_SESSION['settings_flash'] = 'Portal user status updated.';
                header('Location: ' . base_url('settings?tab=portal_users'));
                exit;
            }
        }

        if ($action === 'reset_portal_password') {
            $targetId = clean_int($_POST['target_pu_id'] ?? null);
            if ($targetId) {
                $target = db_row(
                    "SELECT pu.name, pu.email, c.company_name
                     FROM portal_users pu JOIN customers c ON c.id = pu.customer_id WHERE pu.id = ?",
                    [$targetId]
                );

                if ($target) {
                    $plainToken = bin2hex(random_bytes(32));
                    $tokenHash  = hash('sha256', $plainToken);
                    $expiry     = date('Y-m-d H:i:s', strtotime('+24 hours'));

                    db_update('portal_users', [
                        'password_reset_token'  => $tokenHash,
                        'password_reset_expiry' => $expiry,
                    ], 'id = ?', [$targetId]);

                    // Log reset URL (dev mode)
                    // WHY: include email so reset_password.php has context for display;
                    // token lookup itself doesn't require it but it helps UX.
                    $resetUrl = base_url('portal/auth/reset_password') . '?token=' . $plainToken . '&email=' . urlencode($target['email']);
                    $logDir = FF_ROOT . '/logs';
                    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                    file_put_contents($logDir . '/mail.log', sprintf(
                        "[%s] ADMIN-INITIATED PORTAL PASSWORD RESET: To: %s | Name: %s | Company: %s | URL: %s\n",
                        date('Y-m-d H:i:s'), $target['email'], $target['name'], $target['company_name'], $resetUrl
                    ), FILE_APPEND);

                    db_insert('audit_log', [
                        'user_id'      => current_user_id(),
                        'user_name'    => current_user()['name'],
                        'action'       => 'update',
                        'module'       => 'settings',
                        'entity_type'  => 'portal_user',
                        'entity_id'    => $targetId,
                        'entity_label' => $target['name'] . ' (' . $target['company_name'] . ')',
                        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                        'notes'        => "Admin initiated password reset for portal user {$target['email']}",
                    ]);

                    $_SESSION['settings_flash'] = "Password reset link generated for {$target['email']}. Check logs/mail.log.";
                    header('Location: ' . base_url('settings?tab=portal_users'));
                    exit;
                }
            }
        }

        if ($action === 'delete_portal_user') {
            $targetId = clean_int($_POST['target_pu_id'] ?? null);
            if ($targetId) {
                $target = db_row(
                    "SELECT pu.name, pu.email, pu.is_primary, c.company_name
                     FROM portal_users pu JOIN customers c ON c.id = pu.customer_id WHERE pu.id = ?",
                    [$targetId]
                );

                if ($target) {
                    if ($target['is_primary']) {
                        $_SESSION['settings_error'] = 'Cannot delete the primary portal user. Deactivate instead.';
                    } else {
                        // WHY: portal_users is NOT a soft-delete table — hard delete
                        db_execute("DELETE FROM portal_users WHERE id = ?", [$targetId]);

                        db_insert('audit_log', [
                            'user_id'      => current_user_id(),
                            'user_name'    => current_user()['name'],
                            'action'       => 'delete',
                            'module'       => 'settings',
                            'entity_type'  => 'portal_user',
                            'entity_id'    => $targetId,
                            'entity_label' => $target['name'] . ' (' . $target['company_name'] . ')',
                            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                            'notes'        => "Permanently deleted portal user {$target['email']}",
                        ]);

                        $_SESSION['settings_flash'] = "Portal user {$target['name']} deleted.";
                    }
                    header('Location: ' . base_url('settings?tab=portal_users'));
                    exit;
                }
            }
        }

        if ($action === 'create_portal_user') {
            $custId  = clean_int($_POST['pu_customer_id'] ?? null);
            $puName  = clean_string($_POST['pu_name'] ?? null);
            $puEmail = clean_string($_POST['pu_email'] ?? null);

            if ($custId && $puName && $puEmail && filter_var($puEmail, FILTER_VALIDATE_EMAIL)) {
                $exists = db_row("SELECT id FROM portal_users WHERE email = ?", [$puEmail]);
                if ($exists) {
                    $_SESSION['settings_error'] = 'A portal user with this email already exists.';
                } else {
                    // Check if customer has any portal users (first = primary)
                    $existingCount = db_count("SELECT COUNT(*) FROM portal_users WHERE customer_id = ?", [$custId]);
                    $isPrimary = $existingCount === 0 ? 1 : 0;

                    $plainToken = bin2hex(random_bytes(32));
                    $tokenHash  = hash('sha256', $plainToken);

                    // WHY: use password_reset_token (not invite_token) so reset_password.php
                    // can validate the link. invite_token is reserved for remember-me cookies.
                    $newId = db_insert('portal_users', [
                        'customer_id'           => $custId,
                        'name'                  => $puName,
                        'email'                 => $puEmail,
                        'status'                => 'invited',
                        'is_primary'            => $isPrimary,
                        'password_reset_token'  => $tokenHash,
                        'password_reset_expiry' => date('Y-m-d H:i:s', strtotime('+7 days')),
                        'invite_sent_at'        => date('Y-m-d H:i:s'),
                    ]);

                    $resetUrl = base_url('portal/auth/reset_password') . '?token=' . $plainToken . '&email=' . urlencode($puEmail);
                    $logDir = FF_ROOT . '/logs';
                    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                    file_put_contents($logDir . '/mail.log', sprintf(
                        "[%s] ADMIN PORTAL INVITE: To: %s | Name: %s | Customer: %s | Set Password URL: %s\n",
                        date('Y-m-d H:i:s'), $puEmail, $puName, $custId, $resetUrl
                    ), FILE_APPEND);

                    $cust = db_row("SELECT company_name FROM customers WHERE id = ?", [$custId]);
                    db_insert('audit_log', [
                        'user_id'      => current_user_id(),
                        'user_name'    => current_user()['name'],
                        'action'       => 'create',
                        'module'       => 'settings',
                        'entity_type'  => 'portal_user',
                        'entity_id'    => $newId,
                        'entity_label' => $puName . ' (' . ($cust['company_name'] ?? '') . ')',
                        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                        'notes'        => "Created portal user {$puEmail} for customer #{$custId}",
                    ]);

                    $_SESSION['settings_flash'] = "Portal user {$puEmail} created and invitation sent.";
                }
                header('Location: ' . base_url('settings?tab=portal_users'));
                exit;
            }
        }
    }
}

// Fetch all portal users with customer info
$portalUsers = db_select(
    "SELECT pu.id, pu.name, pu.email, pu.status, pu.is_primary,
            pu.last_login_at, pu.login_attempts, pu.locked_until, pu.created_at,
            c.id AS customer_id, c.company_name, c.status AS cust_status
     FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id
     WHERE c.deleted_at IS NULL
     ORDER BY c.company_name ASC, pu.is_primary DESC, pu.name ASC"
);

// Group by customer
$byCustomer = [];
foreach ($portalUsers as $pu) {
    $byCustomer[$pu['customer_id']]['company'] = $pu['company_name'];
    $byCustomer[$pu['customer_id']]['cust_status'] = $pu['cust_status'];
    $byCustomer[$pu['customer_id']]['users'][] = $pu;
}

// Customer list for create form dropdown
$customers = db_select(
    "SELECT id, company_name FROM customers WHERE deleted_at IS NULL AND status IN ('active','pending','credit_hold') ORDER BY company_name ASC"
);

$statusBadge = [
    'active'   => 'badge-success',
    'inactive' => 'badge-neutral',
    'invited'  => 'badge-info',
];
?>

<!-- Create Portal User -->
<?php if ($isSuperAdmin): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">Create Portal User</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="portal_action" value="create_portal_user">
            <div style="display:grid;grid-template-columns:200px 1fr 1fr auto;gap:12px;align-items:end;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Customer</label>
                    <select name="pu_customer_id" class="form-control" required>
                        <option value="">Select...</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= e((string)$c['id']) ?>"><?= e($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Name</label>
                    <input type="text" name="pu_name" class="form-control" placeholder="Full name" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Email</label>
                    <input type="email" name="pu_email" class="form-control" placeholder="user@company.com" required>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Create & Invite</button>
            </div>
            <p class="text-muted" style="font-size:0.75rem;margin:8px 0 0;">First user for a customer becomes the primary account holder automatically.</p>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
    <div class="card" style="padding:16px;text-align:center;">
        <div style="font-size:1.5rem;font-weight:700;"><?= e((string)count($portalUsers)) ?></div>
        <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Total Portal Users</div>
    </div>
    <div class="card" style="padding:16px;text-align:center;">
        <div style="font-size:1.5rem;font-weight:700;color:var(--color-success);"><?= e((string)count(array_filter($portalUsers, fn($p) => $p['status'] === 'active'))) ?></div>
        <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Active</div>
    </div>
    <div class="card" style="padding:16px;text-align:center;">
        <div style="font-size:1.5rem;font-weight:700;color:var(--color-info);"><?= e((string)count(array_filter($portalUsers, fn($p) => $p['status'] === 'invited'))) ?></div>
        <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Pending Invite</div>
    </div>
    <div class="card" style="padding:16px;text-align:center;">
        <div style="font-size:1.5rem;font-weight:700;"><?= e((string)count($byCustomer)) ?></div>
        <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Companies</div>
    </div>
</div>

<!-- Portal Users by Customer -->
<?php if (empty($byCustomer)): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted);">
        No portal users yet. Create one above to give a customer access to the portal.
    </div>
</div>
<?php else: ?>

<?php foreach ($byCustomer as $custId => $data): ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-weight:600;"><?= e($data['company']) ?></span>
            <span class="badge <?= $data['cust_status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>" style="font-size:0.65rem;"><?= e(ucfirst($data['cust_status'])) ?></span>
        </div>
        <a href="<?= e(base_url('customers/show?id=' . $custId)) ?>" class="btn btn-ghost btn-xs">View Customer</a>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
<table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Security</th>
                    <?php if ($isSuperAdmin): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['users'] as $pu): ?>
                <tr>
                    <td style="font-weight:600;"><?= e($pu['name']) ?></td>
                    <td><?= e($pu['email']) ?></td>
                    <td>
                        <?php if ($pu['is_primary']): ?>
                        <span class="badge badge-info">Primary</span>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:0.8125rem;">Sub-user</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= e($statusBadge[$pu['status']] ?? 'badge-neutral') ?>"><?= e(ucfirst($pu['status'])) ?></span></td>
                    <td class="font-mono" style="font-size:0.8125rem;"><?= $pu['last_login_at'] ? e(format_datetime($pu['last_login_at'])) : '<span style="color:var(--text-muted);">Never</span>' ?></td>
                    <td style="font-size:0.8125rem;">
                        <?php if ($pu['locked_until'] && $pu['locked_until'] > date('Y-m-d H:i:s')): ?>
                            <span class="badge badge-danger">Locked</span>
                        <?php elseif ((int)$pu['login_attempts'] >= 3): ?>
                            <span class="badge badge-warning"><?= e((string)$pu['login_attempts']) ?> attempts</span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">OK</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($isSuperAdmin): ?>
                    <td>
                        <div style="display:flex;gap:4px;justify-content:flex-end;flex-wrap:wrap;">
                            <!-- Reset Password -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="portal_action" value="reset_portal_password">
                                <input type="hidden" name="target_pu_id" value="<?= e((string)$pu['id']) ?>">
                                <button type="submit" class="btn btn-ghost btn-xs" onclick="return (await FF_Confirm.ask('Send password reset link to <?= e($pu['email']) ?>?'))">Reset PW</button>
                            </form>

                            <!-- Status toggle -->
                            <?php if ($pu['status'] === 'active'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="portal_action" value="change_portal_status">
                                <input type="hidden" name="target_pu_id" value="<?= e((string)$pu['id']) ?>">
                                <input type="hidden" name="new_status" value="inactive">
                                <button type="submit" class="btn btn-ghost btn-xs" style="color:var(--color-warning);"
                                        onclick="return (await FF_Confirm.ask('Deactivate this portal user?'))">Deactivate</button>
                            </form>
                            <?php elseif ($pu['status'] === 'inactive'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="portal_action" value="change_portal_status">
                                <input type="hidden" name="target_pu_id" value="<?= e((string)$pu['id']) ?>">
                                <input type="hidden" name="new_status" value="active">
                                <button type="submit" class="btn btn-ghost btn-xs" style="color:var(--color-success);">Activate</button>
                            </form>
                            <?php endif; ?>

                            <!-- Delete (non-primary only) -->
                            <?php if (!$pu['is_primary']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="portal_action" value="delete_portal_user">
                                <input type="hidden" name="target_pu_id" value="<?= e((string)$pu['id']) ?>">
                                <button type="submit" class="btn btn-ghost btn-xs" style="color:var(--color-danger);"
                                        onclick="return (await FF_Confirm.ask('Permanently delete this portal user? This cannot be undone.'))">Delete</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php
// [C0-FIX] Standalone footer — only when running as top-level route.
if (!empty($_ff_standalone_portal_users)) {
    require FF_ROOT . '/includes/footer.php';
}
?>
