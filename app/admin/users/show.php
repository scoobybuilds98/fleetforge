<?php
declare(strict_types=1);

/**
 * app/admin/users/show.php
 *
 * User profile page (S-RECORD-REDESIGN layout).
 *
 *   Header  — entity hero (lib/Ui/ModuleHero.php): name + status / role /
 *             "You" badges, email / phone / timezone facts. Actions: Edit and
 *             the one status action that fits (Resend Invitation for an
 *             invite, Activate / Unlock for an inactive / suspended / locked
 *             user) plus Permissions stay visible; everything else — password
 *             reset email, Set password…, Deactivate, Suspend, Lock Out,
 *             Disable 2FA, Delete — sits in the More menu (RecordUi::more).
 *             This replaces the old right-hand stack of seven single-button
 *             cards; every handler, confirm and modal is the same.
 *   Strip   — last login (relative), logins in the last 30 days (failed
 *             ones called out), 2FA, status, custom permission overrides.
 *   Main    — action feedback line, User Details (view + inline edit),
 *             Login History, Activity.
 *   Rail    — Needs attention (invite pending / expired, locked, suspended,
 *             2FA required but not set up, 2FA off on an admin role, failed
 *             logins, temp lockout, dormant account, outstanding reset link),
 *             Access (role + the user's permission overrides), Security,
 *             Account.
 *
 * View/Edit mode: Alpine.js toggle with D19 optimistic lock via updated_at.
 * The page's x-data (userShow) opens ABOVE the hero so the header's Edit
 * button can drive it (same pattern as app/admin/leases/show.php).
 *
 * D5: SOFT_DELETE — deleted_at IS NULL guard.
 * D19: updated_at submitted with every save.
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css / records.css.
 *
 * S-USER-LOCKOUT: "Lock Out" (super_admin only, any non-self, non-already-
 * locked status) posts to api/v1/users/lock.php with a mandatory reason.
 * The primary control surface for this feature is the dedicated Settings →
 * Lockout tab (app/admin/settings/lockout.php); this page's menu item is a
 * convenience for acting on a user while already viewing their profile.
 * "Unlock" reuses the existing Activate button/update_status.php path.
 *
 * Time stamps (last_login_at, invite / reset / lock expiries, audit_log
 * created_at) are UTC DATETIMEs (S-UTC-STAMPS): format_datetime() shows them
 * in company time; "N days ago" and expiry checks run on the UTC clock.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/users/update.php, api/v1/users/update_status.php,
 *           api/v1/users/invite.php, api/v1/users/lock.php,
 *           api/v1/users/send_password_reset.php, api/v1/users/set_password.php,
 *           api/v1/users/disable_mfa.php, api/v1/users/delete.php,
 *           lib/Ui/ModuleHero.php, lib/Ui/RecordUi.php
 * @decisions D5/D7/D19/D30/D32
 * @session  S017, S-USER-LOCKOUT, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

// S-PERM-USERS-ACCESS-WALL — Users module is super_admin only.
// Render the custom developer-only access wall inside the normal
// admin shell so non-super_admin viewers see why they can't enter,
// instead of a bare 403. header.php already opens sidebar/topbar/
// <main class="page-content">; footer.php closes them.
if (!is_super_admin()) {
    $pageTitle = 'Access Restricted';
    require_once FF_ROOT . '/includes/header.php';
    ?>
    <div class="access-wall">
        <div class="access-wall-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5"
                 xmlns="http://www.w3.org/2000/svg">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h2 class="access-wall-title">Developer Access Only</h2>
        <p class="access-wall-message">
            The Users &amp; Roles module is restricted to the Developer account.<br>
            Contact your system administrator for user management assistance.
        </p>
        <a href="<?= base_url('dashboard') ?>" class="btn btn-secondary">
            Back to Dashboard
        </a>
    </div>
    <?php
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

// ── Resolve user ─────────────────────────────────────────────────────────────
$userId = clean_int($_GET['id'] ?? null);
if (!$userId) {
    header('Location: ' . base_url('users'));
    exit;
}

$user = db_row(
    "SELECT
         u.id, u.name, u.email, u.status, u.phone, u.timezone,
         u.theme_preference, u.last_login_at, u.last_login_ip,
         u.invite_sent_at, u.invite_token_expiry, u.created_at, u.updated_at,
         u.mfa_enabled, u.mfa_required, u.mfa_enabled_at,
         u.login_attempts, u.locked_until, u.password_reset_expiry,
         u.created_by,
         u.locked_at, u.lock_reason,
         creator.name AS created_by_name,
         locker.name AS locked_by_name,
         ur.id AS role_id, ur.name AS role_name, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     LEFT JOIN users creator ON creator.id = u.created_by
     LEFT JOIN users locker ON locker.id = u.locked_by
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$userId]
);

if (!$user) {
    header('Location: ' . base_url('users') . '?error=not_found');
    exit;
}

// All roles for the edit dropdown
$roles = db_select("SELECT id, name, slug FROM user_roles ORDER BY id ASC");

// Flash message from redirect (e.g. after invite)
$flashMsg = clean_string($_GET['flash'] ?? null, 500);

$isSelf     = (current_user_id() === $userId);
$canEdit    = can('users', 'edit');
$canCreate  = can('users', 'create');

// ── Load last 10 login history entries from audit_log ────────────────────────
$loginHistory = db_select(
    "SELECT created_at, ip_address, user_agent, notes
     FROM audit_log
     WHERE action = 'login' AND user_id = ?
     ORDER BY created_at DESC
     LIMIT 10",
    [$userId]
);

// Status badge map
$statusBadges = [
    'active'    => 'badge-success',
    'invited'   => 'badge-info',
    'inactive'  => 'badge-neutral',
    'suspended' => 'badge-danger',
    'locked'    => 'badge-danger',
];
$statusLabels = [
    'active'    => 'Active',
    'invited'   => 'Invited',
    'inactive'  => 'Inactive',
    'suspended' => 'Suspended',
    'locked'    => 'Locked',
];

// ── S-RECORD-REDESIGN: strip + rail data ─────────────────────────────────────
$nowUtc = ff_now_utc();
$nowTs  = time();
/** "3 days ago" for a UTC DATETIME; null when empty. */
$ago = static function (?string $utc) use ($nowTs): ?string {
    if ($utc === null || $utc === '') {
        return null;
    }
    $s = max(0, $nowTs - (int) strtotime($utc . ' UTC'));
    if ($s < 90) return 'just now';
    if ($s < 3600) return (int) round($s / 60) . ' min ago';
    if ($s < 86400) return (int) round($s / 3600) . ' h ago';
    $d = intdiv($s, 86400);
    if ($d < 60) return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    if ($d < 730) return intdiv($d, 30) . ' months ago';
    return intdiv($d, 365) . ' years ago';
};
$daysSinceLogin = $user['last_login_at'] ? intdiv(max(0, $nowTs - (int) strtotime($user['last_login_at'] . ' UTC')), 86400) : null;

// Logins in the last 30 days. Failures are logged under action='login' with a
// "Failed login attempt N/5" note — the same keyword test the history table
// uses decides success vs failure.
$failSql = "(LOWER(COALESCE(notes,'')) LIKE '%fail%' OR LOWER(COALESCE(notes,'')) LIKE '%lock%' OR LOWER(COALESCE(notes,'')) LIKE '%invalid%')";
$login30 = db_row(
    "SELECT SUM(CASE WHEN {$failSql} THEN 0 ELSE 1 END) AS ok_cnt,
            SUM(CASE WHEN {$failSql} THEN 1 ELSE 0 END) AS fail_cnt
       FROM audit_log
      WHERE action = 'login' AND user_id = ? AND created_at >= ?",
    [$userId, ff_now_utc('-30 days')]
) ?? [];
$ok30   = (int) ($login30['ok_cnt'] ?? 0);
$fail30 = (int) ($login30['fail_cnt'] ?? 0);

// Per-user permission overrides (PERM-1) — the exceptions to the role.
$overrides = $user['role_slug'] !== 'super_admin'
    ? db_select("SELECT module, action, granted FROM user_permission_overrides WHERE user_id = ? ORDER BY module, action", [$userId])
    : [];
$ovGranted = count(array_filter($overrides, static fn ($o) => (int) $o['granted'] === 1));
$ovRevoked = count($overrides) - $ovGranted;

$mfaOn        = (int) ($user['mfa_enabled'] ?? 0) === 1;
$mfaRequired  = (int) ($user['mfa_required'] ?? 0) === 1;
$isAdminRole  = in_array($user['role_slug'], ['super_admin', 'manager'], true);
$tempLocked   = !empty($user['locked_until']) && (string) $user['locked_until'] > $nowUtc;
$inviteExpired = $user['status'] === 'invited' && !empty($user['invite_token_expiry']) && (string) $user['invite_token_expiry'] < $nowUtc;
$resetPending = !empty($user['password_reset_expiry']) && (string) $user['password_reset_expiry'] > $nowUtc;
$permUrl      = base_url('users/permissions') . '?user_id=' . (int) $userId;

$pageTitle = e($user['name']);
require_once FF_ROOT . '/includes/header.php';
?>

<style>
/* User profile (S-RECORD-REDESIGN) — action feedback lines. The header /
   More-menu actions report back here; each script sets the colour. Tokens only. */
.user-msg {
    margin: 0 0 12px;
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-xl);
    background: var(--bg-surface);
    font-size: 13px;
}
.user-ov { display: flex; flex-direction: column; gap: 4px; margin: 0; padding: 0; list-style: none; }
.user-ov li { display: flex; justify-content: space-between; gap: 8px; font-size: 12.5px; }
.user-ov code { font-size: 12px; }
</style>

<?php ob_start(); ?>
    <span class="badge <?= e($statusBadges[$user['status']] ?? 'badge-neutral') ?>">
        <?= e($statusLabels[$user['status']] ?? ucfirst($user['status'])) ?>
    </span>
    <span class="badge badge-neutral badge-pill"><?= e($user['role_name']) ?></span>
    <?php if ($isSelf): ?>
    <span class="badge badge-info">You</span>
    <?php endif; ?>
<?php $heroBadges = ob_get_clean(); ?>
<?php ob_start(); /* secondary actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <?php if (is_super_admin() && $user['status'] === 'active' && !$isSelf): ?>
        <!-- Send Password Reset Email — super_admin only, active non-self users
             (link expires in 2 hours) -->
        <button id="btn-send-reset" type="button" class="btn btn-secondary btn-sm"
                onclick="sendPasswordReset()">
            Send Password Reset Email
        </button>
        <?php endif; ?>
        <?php if (is_super_admin() && !$isSelf): ?>
        <!-- Set Password — super_admin only, non-self users (opens a modal) -->
        <button type="button" class="btn btn-secondary btn-sm" onclick="openSetPassword()">
            Set Password…
        </button>
        <?php endif; ?>
        <?php if ($canEdit && !$isSelf): ?>
            <?php if ($user['status'] === 'active'): ?>
            <hr>
            <button type="button" class="btn btn-secondary btn-sm"
                    onclick="confirmStatus('inactive', 'Deactivate')">
                Deactivate
            </button>
            <button type="button" class="btn btn-danger btn-sm"
                    onclick="confirmStatus('suspended', 'Suspend')">
                Suspend
            </button>
            <?php endif; ?>
            <?php if ($user['status'] === 'suspended'): ?>
            <hr>
            <button type="button" class="btn btn-secondary btn-sm"
                    onclick="changeStatus('inactive')">
                Set Inactive
            </button>
            <?php endif; ?>
            <?php if (is_super_admin() && $user['status'] !== 'locked'): ?>
            <!-- Immediately blocks login and password reset, and ends any
                 session they have open. Requires a reason. -->
            <button type="button" class="btn btn-danger btn-sm"
                    onclick="lockUser()">
                Lock Out
            </button>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (is_super_admin() && !$isSelf && $mfaOn): ?>
        <!-- Two-Factor Authentication — emergency recovery only: the user lost
             both their authenticator app and backup codes. They re-enroll on
             next login if their role requires it. -->
        <button type="button" class="btn btn-danger btn-sm" id="btn-disable-mfa"
                onclick="disableUserMfa()">
            Disable 2FA
        </button>
        <?php endif; ?>
        <?php if (is_super_admin() && !$isSelf): ?>
        <!-- Delete User — super_admin only, non-self users -->
        <button type="button" class="btn btn-danger btn-sm"
                onclick="confirmDeleteUser()">
            Delete User
        </button>
        <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?php if ($canEdit): ?>
        <button id="btn-edit" type="button" class="btn btn-secondary btn-sm"
                x-show="!editMode"
                @click="showEdit()">Edit</button>
        <?php endif; ?>
        <?php if (is_super_admin() && $user['role_slug'] !== 'super_admin'): ?>
        <!-- Manage Permissions — PERM-1 — super_admin only, never on super_admin targets -->
        <a href="<?= e($permUrl) ?>" class="btn btn-secondary btn-sm">Permissions</a>
        <?php endif; ?>
        <?php if ($canCreate && $user['status'] === 'invited'): ?>
        <!-- Resend Invite -->
        <button id="btn-resend-invite" type="button"
                class="btn btn-primary btn-sm"
                onclick="resendInvite()">
            Resend Invitation
        </button>
        <?php endif; ?>
        <?php /* S-USER-LOCKOUT: 'locked' included so the existing Activate
                 button also reactivates a locked-out user — reusing
                 update_status.php, which S-USER-LOCKOUT taught to clear
                 locked_at/locked_by/lock_reason (and the unrelated
                 login_attempts/locked_until brute-force pair) whenever the
                 prior status was 'locked'. */ ?>
        <?php if ($canEdit && !$isSelf && in_array($user['status'], ['inactive', 'suspended', 'locked'], true)): ?>
        <button type="button" class="btn btn-primary btn-sm"
                onclick="changeStatus('active')">
            <?= $user['status'] === 'locked' ? 'Unlock' : 'Activate' ?>
        </button>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?php
$heroFacts = [\FleetForge\Sop\SopIcons::svg('envelope') . '<a href="mailto:' . e($user['email']) . '" style="color:inherit;text-decoration:none;">' . e($user['email']) . '</a>'];
if (!empty($user['phone'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('phone') . e($user['phone']);
}
if (!empty($user['timezone'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . e($user['timezone']);
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . 'Member since ' . e(format_datetime($user['created_at'], 'M j, Y'));
?>
<!-- ============================================================
     USER PROFILE — Alpine component. Opens ABOVE the hero
     (S-RECORD-REDESIGN) so the header's Edit button drives the
     User Details card's edit mode.
     ============================================================ -->
<div x-data="userShow()">
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'info',
    'icon'       => 'users',
    'avatar'     => \FleetForge\Ui\ModuleHero::initials((string) $user['name']),
    'mark'       => \FleetForge\Ui\ModuleHero::initials((string) $user['name']),
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Users', base_url('users')], [(string) $user['name'], null]],
    'eyebrow'    => 'Team member',
    'title_html' => e($user['name']) . $heroBadges,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<!-- ============================================================
     KEY NUMBERS — the summary strip (S-RECORD-REDESIGN): is this
     account in use, is it protected, what can it do.
     ============================================================ -->
<div class="stat-grid ff-stats">

    <a class="stat-card <?= $user['last_login_at'] ? (($daysSinceLogin ?? 0) > 90 ? 'stat-card--amber' : 'stat-card--blue') : 'stat-card--slate' ?>"
       href="#login-history" title="Login history">
        <span class="stat-icon <?= $user['last_login_at'] ? (($daysSinceLogin ?? 0) > 90 ? 'stat-icon--amber' : 'stat-icon--blue') : 'stat-icon--slate' ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Last login</div>
        <div class="stat-value"><?= $user['last_login_at'] ? e($ago($user['last_login_at'])) : 'Never' ?></div>
        <div class="stat-delta"><?= $user['last_login_at'] ? e(format_datetime($user['last_login_at'], 'M j, g:i A')) : ($user['status'] === 'invited' ? 'invite not accepted' : 'no login recorded') ?></div>
    </a>

    <a class="stat-card <?= $fail30 >= 3 ? 'stat-card--red' : 'stat-card--green' ?>"
       href="#login-history" title="Logins recorded in the last 30 days">
        <span class="stat-icon <?= $fail30 >= 3 ? 'stat-icon--red' : 'stat-icon--green' ?>"><svg><use href="#icon-<?= $fail30 >= 3 ? 'exclamation-triangle' : 'arrow-trending-up' ?>"/></svg></span>
        <div class="stat-label">Logins · 30 days</div>
        <div class="stat-value font-mono"><?= $ok30 ?></div>
        <div class="stat-delta"><?= $fail30 > 0 ? '<span' . ($fail30 >= 3 ? ' class="text-danger"' : '') . '>' . $fail30 . ' failed</span>' : 'no failed attempts' ?></div>
    </a>

    <?php $mfaTone = $mfaOn ? 'green' : ($mfaRequired ? 'red' : ($isAdminRole ? 'amber' : 'slate')); ?>
    <div class="stat-card stat-card--<?= $mfaTone ?>">
        <span class="stat-icon stat-icon--<?= $mfaTone ?>"><svg><use href="#icon-shield-check"/></svg></span>
        <div class="stat-label">Two-factor</div>
        <div class="stat-value"><?= $mfaOn ? 'On' : 'Off' ?></div>
        <div class="stat-delta"><?= $mfaOn ? ($user['mfa_enabled_at'] ? 'since ' . e(format_datetime($user['mfa_enabled_at'], 'M j, Y')) : 'enrolled') : ($mfaRequired ? 'required — not set up' : 'not required') ?></div>
    </div>

    <?php $stTone = match ($user['status']) { 'active' => 'green', 'invited' => 'blue', 'inactive' => 'slate', default => 'red' }; ?>
    <div class="stat-card stat-card--<?= $stTone ?>">
        <span class="stat-icon stat-icon--<?= $stTone ?>"><svg><use href="#icon-<?= in_array($user['status'], ['locked', 'suspended'], true) ? 'lock-open' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Status</div>
        <div class="stat-value"><?= e($statusLabels[$user['status']] ?? ucfirst($user['status'])) ?></div>
        <div class="stat-delta"><?php
            if ($user['status'] === 'locked' && $user['locked_at']) {
                echo 'since ' . e(format_datetime($user['locked_at'], 'M j, Y'));
            } elseif ($user['status'] === 'invited' && $user['invite_sent_at']) {
                echo 'invited ' . e($ago($user['invite_sent_at']));
            } elseif ($tempLocked) {
                echo '<span class="text-danger">temporarily locked</span>';
            } else {
                echo $user['status'] === 'active' ? 'can log in' : 'cannot log in';
            }
        ?></div>
    </div>

    <?php if ($user['role_slug'] !== 'super_admin'): ?>
    <a class="stat-card stat-card--purple" href="<?= e($permUrl) ?>" title="Manage this user's permissions">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-key"/></svg></span>
        <div class="stat-label">Custom permissions</div>
        <div class="stat-value font-mono"><?= count($overrides) ?></div>
        <div class="stat-delta"><?= $overrides ? $ovGranted . ' granted · ' . $ovRevoked . ' revoked' : 'role defaults only' ?></div>
    </a>
    <?php endif; ?>

</div>

<div class="rec-layout">
<div class="rec-main">

<?php if ($flashMsg): ?>
<div class="toast toast-success" style="position:relative;margin-bottom:16px;animation:none;">
    <span class="toast-icon">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        </svg>
    </span>
    <div class="toast-body"><div class="toast-message"><?= e($flashMsg) ?></div></div>
</div>
<?php endif; ?>

<!-- Action feedback — the header / More-menu actions write their result
     into these (ids unchanged from the old action cards). -->
<div id="invite-msg" class="user-msg" role="status" style="display:none;"></div>
<div id="reset-msg" class="user-msg" role="status" style="display:none;"></div>
<div id="status-msg" class="user-msg" role="status" style="display:none;"></div>
<div id="mfa-msg" class="user-msg" role="status" style="display:none;"></div>
<div id="delete-user-msg" class="user-msg" role="status" style="display:none;"></div>

<!-- ── Detail card ───────────────────────────────────────────────────────── -->
<div class="card" id="user-details">

    <div class="card-header">
        <h3 class="card-title" x-text="editMode ? 'Edit User' : 'User Details'">User Details</h3>
    </div>

    <!-- Error banner (edit mode) -->
    <div x-show="editError"
         class="toast toast-danger"
         style="position:relative;margin:0 16px 0;animation:none;display:none;"
         :style="editError ? '' : 'display:none'">
        <span class="toast-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
        </span>
        <div class="toast-body"><div class="toast-message" x-text="editError"></div></div>
    </div>

    <!-- VIEW MODE -->
    <div x-show="!editMode" class="card-body">
        <dl class="rec-dl">
            <dt>Name</dt>
            <dd><?= e($user['name']) ?></dd>

            <dt>Email</dt>
            <dd><a href="mailto:<?= e($user['email']) ?>"><?= e($user['email']) ?></a></dd>

            <dt>Phone</dt>
            <dd><?= $user['phone'] ? e($user['phone']) : '—' ?></dd>

            <dt>Timezone</dt>
            <dd><?= $user['timezone'] ? e($user['timezone']) : '—' ?></dd>

            <dt>Role</dt>
            <dd>
                <span class="badge badge-neutral badge-pill"><?= e($user['role_name']) ?></span>
            </dd>

            <dt>Last Login</dt>
            <dd>
                <?php if ($user['last_login_at']): ?>
                    <?= format_datetime($user['last_login_at']) ?>
                    <?php if ($user['last_login_ip']): ?>
                    <span class="text-muted font-mono" style="font-size:0.8125rem;">
                        (<?= e($user['last_login_ip']) ?>)
                    </span>
                    <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </dd>

            <?php if ($user['invite_sent_at']): ?>
            <dt>Invite Sent</dt>
            <dd><?= format_datetime($user['invite_sent_at']) ?></dd>
            <?php endif; ?>

            <dt>Created</dt>
            <dd><?= format_datetime($user['created_at']) ?><?= !empty($user['created_by_name']) ? ' by ' . e($user['created_by_name']) : '' ?></dd>
        </dl>
    </div>

    <!-- EDIT MODE -->
    <div x-show="editMode" class="card-body">
        <form @submit.prevent="saveUser()">

            <!-- D19 lock token -->
            <input type="hidden" x-ref="updatedAt" value="<?= e($user['updated_at']) ?>">

            <div class="form-group">
                <label class="form-label">Full Name <span class="required">*</span></label>
                <input type="text" class="form-control" x-model="form.name" x-ref="nameInput"
                       maxlength="255" placeholder="Jane Smith">
            </div>

            <div class="form-group">
                <label class="form-label">Email <span class="required">*</span></label>
                <input type="email" class="form-control" x-model="form.email"
                       maxlength="255">
            </div>

            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" x-model="form.phone"
                       maxlength="50">
            </div>

            <div class="form-group">
                <label class="form-label">Timezone</label>
                <input type="text" class="form-control" x-model="form.timezone"
                       maxlength="100" placeholder="America/Vancouver">
            </div>

            <div class="form-group">
                <label class="form-label">Role <span class="required">*</span></label>
                <select class="form-select" x-model="form.role_id">
                    <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:12px;padding-top:16px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary"
                        :disabled="saving">
                    <span x-show="!saving">Save Changes</span>
                    <span x-show="saving">Saving…</span>
                </button>
                <button type="button" class="btn btn-secondary"
                        @click="cancelEdit()">Cancel</button>
            </div>
        </form>
    </div>

</div><!-- /detail card -->

<!-- ══════════════════════════════════════════════════════════════
     Login History — last 10 login attempts for this user
     ══════════════════════════════════════════════════════════════ -->
<div class="card" id="login-history">
    <div class="card-header">
        <h3 class="card-title">Login History</h3>
        <span class="text-muted" style="font-size:0.8125rem;margin-left:8px;">Last 10 attempts</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($loginHistory)): ?>
        <div class="empty-state" style="padding:32px;">
            <div class="empty-state-title">No login history recorded yet.</div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
<table class="table table-hover">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>IP Address</th>
                    <th>User Agent</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loginHistory as $entry): ?>
                <?php
                    // WHY: check notes for failure/lock keywords to determine result badge
                    $notes  = strtolower($entry['notes'] ?? '');
                    $failed = str_contains($notes, 'fail') || str_contains($notes, 'lock') || str_contains($notes, 'invalid');
                ?>
                <tr>
                    <td class="font-mono" style="font-size:0.8125rem;white-space:nowrap;">
                        <?= e(format_datetime($entry['created_at'])) ?>
                    </td>
                    <td class="font-mono" style="font-size:0.8125rem;">
                        <?= $entry['ip_address'] ? e($entry['ip_address']) : '—' ?>
                    </td>
                    <td style="font-size:0.8125rem;color:var(--text-secondary);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= e((string) ($entry['user_agent'] ?? '')) ?>">
                        <?= $entry['user_agent'] ? e(substr($entry['user_agent'], 0, 60)) : '—' ?>
                    </td>
                    <td>
                        <?php if ($failed): ?>
                            <span class="badge badge-danger" title="<?= e((string) ($entry['notes'] ?? '')) ?>">Failed</span>
                        <?php else: ?>
                            <span class="badge badge-success">Success</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h3 class="card-title">Activity</h3></div>
    <div class="card-body">
        <?php
        $activityEntityType = 'user';
        $activityEntityId   = $userId;
        $activityOriginAt   = $user['created_at'];
        $activityOriginBy   = $user['created_by_name'] ?? null;
        ?>
        <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
    </div>
</div>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the account at a glance ──────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$railU = [];

// 1. Needs attention.
$alertsU = [];
if ($user['status'] === 'locked') {
    $alertsU[] = ['danger', '<b>Locked out</b>' . ($user['locked_at'] ? ' ' . e(format_datetime($user['locked_at'], 'M j, Y')) : '')
        . ' by ' . e($user['locked_by_name'] ?? 'unknown') . ($user['lock_reason'] ? ' — &ldquo;' . e($user['lock_reason']) . '&rdquo;' : '') . '.'];
} elseif ($user['status'] === 'suspended') {
    $alertsU[] = ['danger', '<b>Suspended</b> — cannot log in.'];
} elseif ($user['status'] === 'inactive') {
    $alertsU[] = ['info', '<b>Inactive</b> — cannot log in.'];
}
if ($user['status'] === 'invited') {
    $alertsU[] = $inviteExpired
        ? ['warning', 'The invite link expired ' . e(format_datetime($user['invite_token_expiry'], 'M j, Y')) . ($canCreate ? ' — <b>Resend Invitation</b>.' : '.')]
        : ['info', 'Invite not accepted yet' . ($user['invite_sent_at'] ? ' (sent ' . e($ago($user['invite_sent_at'])) . ')' : '') . '.'];
}
if ($tempLocked) {
    $alertsU[] = ['warning', 'Temporarily locked after ' . (int) $user['login_attempts'] . ' failed logins, until ' . e(format_datetime($user['locked_until'], 'M j, g:i A')) . '.'];
}
if (!$mfaOn && $mfaRequired) {
    $alertsU[] = ['warning', '2FA is required but not set up — they will be made to enrol at next login.'];
} elseif (!$mfaOn && $isAdminRole && $user['status'] === 'active') {
    $alertsU[] = ['info', '2FA is off on a ' . e($user['role_name']) . ' account.'];
}
if ($fail30 >= 3) {
    $alertsU[] = ['warning', '<a href="#login-history">' . $fail30 . ' failed login attempts</a> in the last 30 days.'];
}
if ($user['status'] === 'active' && !$user['last_login_at']) {
    $alertsU[] = ['info', 'Has never logged in.'];
} elseif ($user['status'] === 'active' && $daysSinceLogin !== null && $daysSinceLogin > 90) {
    $alertsU[] = ['info', 'No login in ' . $daysSinceLogin . ' days — consider deactivating.'];
}
if ($resetPending) {
    $alertsU[] = ['info', 'A password reset link is outstanding (expires ' . e(format_datetime($user['password_reset_expiry'], 'M j, g:i A')) . ').'];
}
if ($isSelf) {
    $alertsU[] = ['info', 'This is your own account — status, password and delete actions are hidden.'];
}
$railU[] = $R::card('Needs attention', $R::alerts($alertsU, 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// 2. Access — the role, and the per-user exceptions to it.
if ($user['role_slug'] === 'super_admin') {
    $accessBody = $R::kv([['Role', '<span class="badge badge-neutral badge-pill">' . e($user['role_name']) . '</span>']])
        . '<p class="text-secondary" style="margin:8px 0 0;font-size:12.5px;">Full access to every module — permissions can\'t be narrowed for a super admin.</p>';
    $railU[] = $R::card('Access', $accessBody, ['icon' => 'shield-check', 'class' => 'rec-card--accent']);
} else {
    $accessBody = $R::kv([
        ['Role', '<span class="badge badge-neutral badge-pill">' . e($user['role_name']) . '</span>'],
        ['Overrides', $overrides ? $ovGranted . ' granted · ' . $ovRevoked . ' revoked' : 'None — role defaults'],
    ]);
    if ($overrides) {
        $accessBody .= '<ul class="user-ov" style="margin-top:8px;">';
        foreach (array_slice($overrides, 0, 8) as $o) {
            $accessBody .= '<li><code>' . e($o['module'] . ':' . $o['action']) . '</code>'
                . ((int) $o['granted'] === 1 ? '<span class="badge badge-success">granted</span>' : '<span class="badge badge-danger">revoked</span>') . '</li>';
        }
        $accessBody .= '</ul>';
    }
    $railU[] = $R::card('Access', $accessBody, [
        'icon'  => 'shield-check',
        'class' => 'rec-card--accent',
        'link'  => ['Manage', $permUrl],
        'foot'  => count($overrides) > 8 ? '+' . (count($overrides) - 8) . ' more on the permissions page.' : '',
    ]);
}

// 3. Security.
$railU[] = $R::card('Security', $R::kv([
    ['Two-factor', $mfaOn ? '<span class="text-success">On</span>' . ($user['mfa_enabled_at'] ? ' · since ' . e(format_datetime($user['mfa_enabled_at'], 'M j, Y')) : '') : 'Off'],
    ['2FA required', $mfaRequired ? 'Yes' : 'No'],
    ['Last login', $user['last_login_at'] ? e(format_datetime($user['last_login_at'], 'M j, Y g:i A')) : 'Never'],
    ['Last IP', !empty($user['last_login_ip']) ? '<span class="mono">' . e($user['last_login_ip']) . '</span>' : null],
    ['Failed attempts', (int) $user['login_attempts'] > 0 ? (int) $user['login_attempts'] . ' in a row' : null],
    ['Locked until', $tempLocked ? '<span class="text-danger">' . e(format_datetime($user['locked_until'], 'M j, g:i A')) . '</span>' : null],
    ['Reset link', $resetPending ? 'expires ' . e(format_datetime($user['password_reset_expiry'], 'M j, g:i A')) : null],
]), ['icon' => 'shield-check']);

// 4. Account.
$railU[] = $R::card('Account', $R::kv([
    ['Created', e(format_datetime($user['created_at'], 'M j, Y')) . (!empty($user['created_by_name']) ? ' · ' . e($user['created_by_name']) : '')],
    ['Invite sent', $user['invite_sent_at'] ? e(format_datetime($user['invite_sent_at'], 'M j, Y')) : null],
    ['Invite expires', $user['status'] === 'invited' && $user['invite_token_expiry'] ? ($inviteExpired ? '<span class="text-danger">' : '') . e(format_datetime($user['invite_token_expiry'], 'M j, Y')) . ($inviteExpired ? ' (expired)</span>' : '') : null],
    ['Last updated', e(format_datetime($user['updated_at'], 'M j, Y g:i A'))],
    ['User ID', '<span class="mono">' . (int) $user['id'] . '</span>'],
]), ['icon' => 'users']);
?>
<aside class="rec-rail" aria-label="User at a glance">
    <?= implode("\n    ", $railU) ?>
</aside>
</div><!-- /rec-layout -->

</div><!-- /x-data userShow -->

<script>
function userShow() {
    return {
        editMode:  false,
        saving:    false,
        editError: null,
        form: {
            name:     <?= json_encode($user['name']) ?>,
            email:    <?= json_encode($user['email']) ?>,
            phone:    <?= json_encode($user['phone'] ?? '') ?>,
            timezone: <?= json_encode($user['timezone'] ?? '') ?>,
            role_id:  <?= json_encode((string)$user['role_id']) ?>,
        },

        init() {
            // Reset form to current server values
            this.form = {
                name:     <?= json_encode($user['name']) ?>,
                email:    <?= json_encode($user['email']) ?>,
                phone:    <?= json_encode($user['phone'] ?? '') ?>,
                timezone: <?= json_encode($user['timezone'] ?? '') ?>,
                role_id:  <?= json_encode((string)$user['role_id']) ?>,
            };
        },

        // The Edit button lives in the header (S-RECORD-REDESIGN); the form
        // is in the User Details card below — bring it into view.
        showEdit() {
            this.editMode  = true;
            this.editError = null;
            this.$nextTick(() => {
                document.getElementById('user-details')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                this.$refs.nameInput?.focus({ preventScroll: true });
            });
        },

        cancelEdit() {
            this.editMode  = false;
            this.editError = null;
        },

        async saveUser() {
            this.editError = null;

            if (!this.form.name.trim()) {
                this.editError = 'Name is required.';
                return;
            }
            if (!this.form.email.trim()) {
                this.editError = 'Email is required.';
                return;
            }

            const payload = {
                id:         <?= $userId ?>,
                updated_at: this.$refs.updatedAt.value,   // D19 optimistic lock
                name:       this.form.name.trim(),
                email:      this.form.email.trim(),
                phone:      this.form.phone.trim() || null,
                timezone:   this.form.timezone.trim() || null,
                role_id:    parseInt(this.form.role_id),
            };

            this.saving = true;
            try {
                await FF_Api.post('<?= base_url('api/v1/users/update.php') ?>', payload);
                window.location.reload();
            } catch (err) {
                this.editError = err?.data?.message ?? 'Save failed. Please try again.';
                this.saving = false;
            }
        },
    };
}

// ── Resend invite ─────────────────────────────────────────────────────────────
async function resendInvite() {
    const btn = document.getElementById('btn-resend-invite');
    const msg = document.getElementById('invite-msg');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Sending…';
    msg.style.display = 'none';
    try {
        await FF_Api.post('<?= base_url('api/v1/users/invite.php') ?>', { id: <?= $userId ?> });
        msg.textContent = '✓ Invitation resent successfully.';
        msg.style.color = 'var(--color-success)';
        msg.style.display = 'block';
        btn.textContent = 'Invite Sent';
    } catch (err) {
        msg.textContent = err?.data?.message ?? 'Failed to resend invite.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Resend Invitation';
    }
}

// ── Send password reset email ─────────────────────────────────────────────────
async function sendPasswordReset() {
    const btn = document.getElementById('btn-send-reset');
    const msg = document.getElementById('reset-msg');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Sending…';
    msg.style.display = 'none';
    try {
        const res = await FF_Api.post('<?= base_url('api/v1/users/send_password_reset.php') ?>', { id: <?= $userId ?> });
        msg.textContent = res?.data?.message ?? '✓ Password reset email sent.';
        msg.style.color = 'var(--color-success)';
        msg.style.display = 'block';
        btn.textContent = 'Email Sent';
    } catch (err) {
        msg.textContent = err?.data?.message ?? 'Failed to send reset email.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Send Password Reset Email';
    }
}

// ── Set password (Alpine component, in the Set Password modal) ───────────────
function setPasswordForm() {
    return {
        pwd: '',
        confirmPwd: '',
        saving: false,
        msg: '',
        isError: false,

        async submit() {
            this.msg = '';
            if (this.pwd.length < 10) {
                this.msg = 'Password must be at least 10 characters.';
                this.isError = true;
                return;
            }
            if (this.pwd !== this.confirmPwd) {
                this.msg = 'Passwords do not match.';
                this.isError = true;
                return;
            }
            this.saving = true;
            try {
                await FF_Api.post('<?= base_url('api/v1/users/set_password.php') ?>', {
                    id:               <?= (int)$userId ?>,
                    new_password:     this.pwd,
                    confirm_password: this.confirmPwd,
                });
                this.msg = '✓ Password updated.';
                this.isError = false;
                this.pwd = '';
                this.confirmPwd = '';
            } catch (err) {
                this.msg = err?.data?.message ?? 'Failed to set password.';
                this.isError = true;
            } finally {
                this.saving = false;
            }
        },
    };
}
function openSetPassword() {
    document.getElementById('set-password-modal').style.display = 'flex';
    setTimeout(() => document.getElementById('set-password-new')?.focus(), 50);
}
function closeSetPassword() {
    document.getElementById('set-password-modal').style.display = 'none';
}

// ── Disable 2FA (emergency recovery) ─────────────────────────────────────────
// Was an inline Alpine handler on its own card; now a menu item, so it is a
// plain function reporting into #mfa-msg. Same confirm, endpoint and reload.
async function disableUserMfa() {
    if (!confirm('Disable MFA for this user? They will need to re-enroll if their role requires it.')) return;
    const msg = document.getElementById('mfa-msg');
    const btn = document.getElementById('btn-disable-mfa');
    msg.style.display = 'none';
    if (btn) btn.disabled = true;
    try {
        const r = await fetch(window.FF_BASE_PATH + '/api/v1/users/disable_mfa.php', {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({ user_id: <?= $userId ?> }),
        });
        const d = await r.json();
        if (d.success) {
            msg.textContent = 'MFA disabled. Page will refresh…';
            msg.style.color = 'var(--color-success)';
            msg.style.display = 'block';
            setTimeout(() => location.reload(), 1500);
            return;
        }
        msg.textContent = d.error?.message ?? 'Failed to disable MFA.';
    } catch (e) {
        msg.textContent = 'Network error. Please try again.';
    }
    msg.style.color = 'var(--color-danger)';
    msg.style.display = 'block';
    if (btn) btn.disabled = false;
}

// ── Delete user ───────────────────────────────────────────────────────────────
function confirmDeleteUser() {
    document.getElementById('delete-user-modal').style.display = 'flex';
}
function closeDeleteModal() {
    document.getElementById('delete-user-modal').style.display = 'none';
}
async function executeDelete() {
    const btn = document.getElementById('btn-confirm-delete');
    const msg = document.getElementById('delete-user-msg');
    btn.disabled = true;
    btn.textContent = 'Deleting…';
    try {
        await FF_Api.post('<?= base_url('api/v1/users/delete.php') ?>', { id: <?= (int)$userId ?> });
        window.location.href = '<?= base_url('users') ?>?flash=User+deleted+successfully.';
    } catch (err) {
        closeDeleteModal();
        msg.textContent = err?.data?.message ?? 'Delete failed.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Delete';
    }
}

// ── Change status ─────────────────────────────────────────────────────────────
async function changeStatus(newStatus) {
    const msg = document.getElementById('status-msg');
    msg.style.display = 'none';
    try {
        await FF_Api.post('<?= base_url('api/v1/users/update_status.php') ?>', {
            id:     <?= $userId ?>,
            status: newStatus,
        });
        window.location.reload();
    } catch (err) {
        msg.textContent = err?.data?.message ?? 'Status change failed.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
    }
}
// Deactivate / Suspend moved from always-visible buttons into the More menu
// (S-RECORD-REDESIGN) — a confirm step guards the one-click lockout of a
// working account from a stray menu click.
async function confirmStatus(newStatus, verb) {
    const ok = await FF_Confirm.ask({
        title: verb + ' ' + <?= json_encode($user['name']) ?>,
        message: newStatus === 'suspended'
            ? 'They will not be able to log in until reactivated.'
            : 'They will not be able to log in until activated again.',
        confirmLabel: verb,
        dangerMode: newStatus === 'suspended',
    });
    if (ok) changeStatus(newStatus);
}

// ── Lock out (S-USER-LOCKOUT) ───────────────────────────────────────────────
// WHY: FF_Api.post resolves (never rejects) on a 4xx/5xx JSON error response
// -- unlike changeStatus() above, this checks res.success explicitly rather
// than relying on try/catch, which would otherwise silently treat a rejected
// lock request (e.g. "already locked") as a success.
async function lockUser() {
    const msg = document.getElementById('status-msg');
    msg.style.display = 'none';

    const reason = await FF_Confirm.askText({
        title: 'Lock out ' + <?= json_encode($user['name']) ?>,
        message: 'This immediately blocks login and password reset, and force-ends any session '
               + 'they currently have open. Reason (required):',
        confirmLabel: 'Lock Out',
        placeholder: 'e.g. Access revoked -- contract ended',
    });
    if (!reason || !reason.trim()) return;

    const res = await FF_Api.post('<?= base_url('api/v1/users/lock.php') ?>', {
        user_id: <?= $userId ?>,
        reason:  reason.trim(),
    });

    if (!res.success) {
        msg.textContent = res.error?.message ?? 'Lockout failed.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
        return;
    }
    window.location.reload();
}
</script>

<?php if (is_super_admin() && !$isSelf): ?>
<!-- Set Password Modal (was an inline rail card) — super_admin only, non-self.
     .modal (not the undefined .modal-dialog) gives it the standard panel. -->
<div id="set-password-modal"
     class="modal-backdrop"
     style="display:none;"
     role="dialog" aria-modal="true" aria-labelledby="setpw-modal-title"
     onclick="if (event.target === this) closeSetPassword()">
    <div class="modal modal-sm" x-data="setPasswordForm()" @keydown.escape="closeSetPassword()">
        <div class="modal-header">
            <span class="modal-title" id="setpw-modal-title">Set Password — <?= e($user['name']) ?></span>
        </div>
        <form class="modal-body" @submit.prevent="submit()">
            <div class="form-group">
                <label class="form-label" for="set-password-new">New Password</label>
                <input type="password" class="form-control" id="set-password-new" x-model="pwd"
                       placeholder="Min. 10 characters" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label" for="set-password-confirm">Confirm Password</label>
                <input type="password" class="form-control" id="set-password-confirm" x-model="confirmPwd"
                       placeholder="Repeat password" autocomplete="new-password">
            </div>
            <div x-show="msg" x-text="msg"
                 :style="{ color: isError ? 'var(--color-danger)' : 'var(--color-success)' }"
                 style="font-size:0.8125rem;margin-bottom:8px;display:none;"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary btn-md" onclick="closeSetPassword()">Close</button>
                <button type="submit" class="btn btn-primary btn-md" :disabled="saving">
                    <span x-show="!saving">Set Password</span>
                    <span x-show="saving">Saving…</span>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Delete User Confirmation Modal -->
<div id="delete-user-modal"
     class="modal-backdrop"
     style="display:none;"
     role="dialog" aria-modal="true" aria-labelledby="del-modal-title">
    <div class="modal modal-md">
        <div class="modal-header">
            <span class="modal-title" id="del-modal-title">Delete User</span>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete <strong><?= e($user['name']) ?></strong>?</p>
            <p style="color:var(--text-secondary);font-size:0.875rem;">
                This user will be soft-deleted and will no longer be able to log in.
                This action cannot be undone via the UI.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-md" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger btn-md" id="btn-confirm-delete" onclick="executeDelete()">Delete</button>
        </div>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
