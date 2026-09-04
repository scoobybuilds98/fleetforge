<?php
declare(strict_types=1);

/**
 * app/admin/settings/lockout.php
 *
 * S-USER-LOCKOUT: dedicated super-admin control panel for immediately
 * revoking a user's access to FleetForge -- distinct from the softer
 * Suspend/Deactivate actions already available in the Users module.
 * Locking a user sets users.status = 'locked', which blocks login, blocks
 * password-reset request/completion, and force-ends any already-open
 * session on that user's next authenticated request (see
 * api/v1/users/lock.php's docblock for the full mechanism).
 *
 * Deliberately hidden from the Settings tab bar entirely for any
 * non-super_admin session, rather than the app's usual greyed-out
 * "you don't have access" tab treatment -- this feature's existence
 * should not be advertised to the very accounts it might be used
 * against. See settings/index.php's tab-bar block for the `$isSuperAdmin`
 * wrapper around this tab's <button>.
 *
 * Normally included by settings/index.php only when $isSuperAdmin is
 * true; also independently gated below for direct-URL safety (mirrors
 * backup.php / users.php / system.php's standalone-safe bootstrap).
 *
 * Inherited from parent when included: $canEdit, $isSuperAdmin, $csrfToken.
 *
 * Writes: api/v1/users/lock.php (lock), api/v1/users/update_status.php
 *         (unlock -- status='active', which S-USER-LOCKOUT also taught
 *         that endpoint to clear the lock bookkeeping columns for).
 *
 * @session S-USER-LOCKOUT
 */

// Standalone execution guard -- mirror backup.php / users.php / system.php.
if (!isset($canEdit)) {
    require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
    require_once FF_ROOT . '/includes/auth.php';

    require_auth();
    require_permission('settings', 'view');

    $canEdit      = can('settings', 'edit');
    $isSuperAdmin = can('settings', 'delete'); // WHY: only super_admin has settings.delete
    $csrfToken    = generate_csrf_token();

    $pageTitle = 'User Lockout';
    require_once FF_ROOT . '/includes/header.php';
    $_ff_standalone_lockout = true;
}

// WHY: this tab is exclusively for super_admin, full stop -- unlike every
// other Settings tab, access here is never meant to be independently
// grantable via a permission override. Belt-and-suspenders in case a
// direct ?tab=lockout (or a direct hit on this file) ever reaches here
// outside the nav's own is_super_admin() gate in settings/index.php.
if (!is_super_admin()) {
    ?>
    <div class="card">
        <div class="card-body" style="padding:24px;text-align:center;color:var(--text-muted);">
            Developer access only.
        </div>
    </div>
    <?php
    if (!empty($_ff_standalone_lockout)) {
        require FF_ROOT . '/includes/footer.php';
    }
    return;
}

$lockoutUsers = db_select(
    "SELECT u.id, u.name, u.email, u.status,
            u.locked_at, u.lock_reason,
            r.name AS role_name, r.slug AS role_slug,
            lb.name AS locked_by_name
       FROM users u
       JOIN user_roles r ON r.id = u.role_id
       LEFT JOIN users lb ON lb.id = u.locked_by
      WHERE u.deleted_at IS NULL
      ORDER BY (u.status = 'locked') DESC, u.name ASC"
);

$lockedCount = 0;
foreach ($lockoutUsers as $lu) {
    if ($lu['status'] === 'locked') $lockedCount++;
}

// Same lock-glyph markup as the tab-bar's $_lockSvg in settings/index.php --
// there's no heroicon asset for a padlock (public/assets/icons/ has none),
// so this is inlined rather than routed through heroicon().
$lockGlyph = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    . 'stroke-width="2" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;">'
    . '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>'
    . '<path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
?>

<div class="card" style="margin-bottom:20px;border-left:3px solid var(--color-danger);">
    <div class="card-body" style="padding:16px 20px;">
        <div style="font-weight:600;font-size:0.9375rem;margin-bottom:6px;display:flex;align-items:center;gap:8px;color:var(--color-danger);">
            <?= $lockGlyph ?>
            User Lockout
        </div>
        <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0;">
            Locking a user immediately blocks them from logging in and from requesting or completing a
            password reset &mdash; they cannot recover access on their own. Any session they currently
            have open is force-ended the next time they load a page. This is separate from Suspend /
            Deactivate in the Users module: a lockout always requires a reason and is tracked here.
        </p>
    </div>
</div>

<div class="card" x-data="ffLockoutPanel()">
    <div class="card-header" style="font-weight:600;display:flex;justify-content:space-between;align-items:center;">
        <span>All Users</span>
        <span class="badge <?= $lockedCount > 0 ? 'badge-danger' : 'badge-neutral' ?>" style="font-size:0.75rem;">
            <?= (int) $lockedCount ?> locked
        </span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Lock Details</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lockoutUsers as $u): ?>
                <tr>
                    <td style="font-weight:600;">
                        <?= e($u['name']) ?>
                        <?php if ((int) $u['id'] === current_user_id()): ?>
                        <span style="font-size:0.7rem;color:var(--text-muted);margin-left:4px;">(you)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge badge-info"><?= e($u['role_name']) ?></span></td>
                    <td>
                        <span class="badge <?= $u['status'] === 'locked' ? 'badge-danger' : 'badge-success' ?>">
                            <?= e(ucfirst($u['status'])) ?>
                        </span>
                    </td>
                    <td style="font-size:0.75rem;color:var(--text-secondary);max-width:280px;">
                        <?php if ($u['status'] === 'locked'): ?>
                            <div><?= e(format_datetime($u['locked_at'])) ?> by <?= e($u['locked_by_name'] ?? 'unknown') ?></div>
                            <div style="color:var(--text-tertiary);"><?= e($u['lock_reason'] ?? '') ?></div>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        <?php if ((int) $u['id'] === current_user_id()): ?>
                            <span style="font-size:0.75rem;color:var(--text-muted);">&mdash;</span>
                        <?php elseif ($u['status'] === 'locked'): ?>
                            <button type="button" class="btn btn-secondary btn-xs"
                                    :disabled="busyId === <?= (int) $u['id'] ?>"
                                    @click="unlock(<?= (int) $u['id'] ?>, '<?= e(addslashes($u['name'])) ?>')">
                                <span x-show="busyId !== <?= (int) $u['id'] ?>">Unlock</span>
                                <span x-show="busyId === <?= (int) $u['id'] ?>" x-cloak>Unlocking&hellip;</span>
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-danger btn-xs"
                                    :disabled="busyId === <?= (int) $u['id'] ?>"
                                    @click="lock(<?= (int) $u['id'] ?>, '<?= e(addslashes($u['name'])) ?>')">
                                <span x-show="busyId !== <?= (int) $u['id'] ?>">Lock Out</span>
                                <span x-show="busyId === <?= (int) $u['id'] ?>" x-cloak>Locking&hellip;</span>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
function ffLockoutPanel() {
    return {
        busyId: null,

        async lock(id, name) {
            const reason = await FF_Confirm.askText({
                title: 'Lock out ' + name,
                message: 'This immediately blocks login and password reset, and force-ends any session '
                       + 'they currently have open. Reason (required):',
                confirmLabel: 'Lock Out',
                placeholder: 'e.g. Access revoked -- contract ended',
            });
            if (!reason || !reason.trim()) return;

            this.busyId = id;
            const res = await FF_Api.post(FF_Api.url('/api/v1/users/lock.php'), {
                user_id: id,
                reason: reason.trim(),
            });
            this.busyId = null;

            if (!res.success) {
                FF_Toast.error('Lockout failed', res.error?.message ?? 'Failed to lock this user.');
                return;
            }
            FF_Toast.success('Locked out', name + ' has been locked out.');
            window.location.reload();
        },

        async unlock(id, name) {
            if (!(await FF_Confirm.ask('Unlock ' + name + '? They will be able to log in again.'))) return;

            this.busyId = id;
            const res = await FF_Api.post(FF_Api.url('/api/v1/users/update_status.php'), {
                id: id,
                status: 'active',
            });
            this.busyId = null;

            if (!res.success) {
                FF_Toast.error('Unlock failed', res.error?.message ?? 'Failed to unlock this user.');
                return;
            }
            FF_Toast.success('Unlocked', name + ' has been unlocked.');
            window.location.reload();
        },
    };
}
</script>

<?php
if (!empty($_ff_standalone_lockout)) {
    require FF_ROOT . '/includes/footer.php';
}
?>
