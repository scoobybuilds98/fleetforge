<?php
declare(strict_types=1);

/**
 * app/admin/portal_users/show.php
 *
 * S-USERS-CONSOLIDATE C3 — Portal user detail page, S-RECORD-REDESIGN layout
 * (mirrors app/admin/users/show.php).
 *
 *   Header  — entity hero (lib/Ui/ModuleHero.php): name + status / Primary /
 *             Email Disabled badges, email / company / last-login facts.
 *             Actions: the one fix that matters now (Re-enable Email when the
 *             SES bounce handler disabled it, Activate when inactive) and Send
 *             Reset Link stay visible; Deactivate, Delete (non-primary,
 *             super_admin) and the related links sit in the More menu. This
 *             replaces the old stack of Email / Password Reset / Status /
 *             Danger Zone cards — same endpoints, same confirms.
 *   Strip   — last login (relative), failed attempts / lock, email delivery,
 *             status, the customer's portal team size.
 *   Main    — action feedback line, Portal User Details, Recent login
 *             activity (only when audit rows exist), Activity.
 *   Rail    — Needs attention (email disabled, locked, failed attempts,
 *             invite pending / expired, customer blocked from the portal,
 *             dormant, outstanding reset link), Customer (entity + the other
 *             portal users on the account), Security.
 *
 * The actions share one Alpine component (FF_PortalUserShow) opened ABOVE the
 * hero so header + menu buttons can call it (was one inline x-data per card).
 * Email / name reach the confirm prompts through json_encode in the script
 * block — the old inline @click strings embedded them via e() inside an HTML
 * attribute, where a quote in a name broke (or injected into) the handler.
 *
 * Permissions: settings:view to load; settings:edit for write actions
 * (matches the API gates); settings:delete (super_admin) for delete.
 *
 * Time stamps (last_login_at, locked_until, invite / reset expiries,
 * email_disabled_at) are UTC (S-UTC-STAMPS): compared on the UTC clock,
 * displayed with format_datetime() in company time.
 *
 * @depends  api/v1/portal_users/*, includes/auth.php, includes/header.php,
 *           lib/Ui/ModuleHero.php, lib/Ui/RecordUi.php
 * @decisions D5 (no soft-delete on portal_users) / D7 / D-B
 * @session  S-USERS-CONSOLIDATE, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('settings', 'view');

// ── Resolve portal user ─────────────────────────────────────────────────────
$puId = clean_int($_GET['id'] ?? null);
if (!$puId) {
    header('Location: ' . base_url('users?tab=portal'));
    exit;
}

$pu = db_row(
    "SELECT pu.id, pu.name, pu.email, pu.status, pu.is_primary,
            pu.email_disabled, pu.email_disabled_reason, pu.email_disabled_at,
            pu.last_login_at, pu.last_login_ip, pu.login_attempts, pu.locked_until,
            pu.invite_sent_at, pu.invite_token_expiry, pu.password_reset_expiry,
            pu.created_at, pu.updated_at,
            c.id AS customer_id, c.company_name, c.status AS customer_status
     FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id
     WHERE pu.id = ? AND c.deleted_at IS NULL",
    [$puId]
);

if (!$pu) {
    header('Location: ' . base_url('users?tab=portal') . '&error=not_found');
    exit;
}

$canEdit       = can('settings', 'edit');
$canSuperAdmin = can('settings', 'delete');

// Recent login history — scope to action='login' + entity_type='portal_user'
// so admin-side login events don't leak into a portal-user audit view.
$loginHistory = db_select(
    "SELECT created_at, ip_address, user_agent, notes
     FROM audit_log
     WHERE action = 'login'
       AND entity_type = 'portal_user'
       AND entity_id   = ?
     ORDER BY created_at DESC
     LIMIT 10",
    [$puId]
);

$statusBadges = [
    'active'   => 'badge-success',
    'inactive' => 'badge-neutral',
    'invited'  => 'badge-info',
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
$daysSinceLogin = $pu['last_login_at'] ? intdiv(max(0, $nowTs - (int) strtotime($pu['last_login_at'] . ' UTC')), 86400) : null;
$isLocked      = !empty($pu['locked_until']) && (string) $pu['locked_until'] > $nowUtc;
$attempts      = (int) $pu['login_attempts'];
$emailOff      = (int) $pu['email_disabled'] === 1;
$isPrimary     = (int) $pu['is_primary'] === 1;
$inviteExpired = $pu['status'] === 'invited' && !empty($pu['invite_token_expiry']) && (string) $pu['invite_token_expiry'] < $nowUtc;
$resetPending  = !empty($pu['password_reset_expiry']) && (string) $pu['password_reset_expiry'] > $nowUtc;
// app/portal/auth/login.php lets a portal user in only while the customer is
// active / pending / credit_hold — anything else locks the whole team out.
$customerBlocked = !in_array($pu['customer_status'], ['active', 'pending', 'credit_hold'], true);

// The rest of the customer's portal team (who else can log in for them).
$team = db_select(
    "SELECT id, name, email, status, is_primary, last_login_at
       FROM portal_users
      WHERE customer_id = ?
      ORDER BY is_primary DESC, (status = 'active') DESC, name ASC",
    [(int) $pu['customer_id']]
);
$teamActive = count(array_filter($team, static fn ($t) => $t['status'] === 'active'));
$customerUrl = base_url('customers/show') . '?id=' . (int) $pu['customer_id'];

$pageTitle = e($pu['name']);
require_once FF_ROOT . '/includes/header.php';
?>

<?php ob_start(); ?>
    <span class="badge <?= e($statusBadges[$pu['status']] ?? 'badge-neutral') ?>">
        <?= e(ucfirst($pu['status'])) ?>
    </span>
    <?php if ($isPrimary): ?>
    <span class="badge badge-info">Primary</span>
    <?php endif; ?>
    <?php if ($emailOff): ?>
    <span class="badge badge-danger" title="<?= e($pu['email_disabled_reason'] ?? 'Email auto-disabled by SES bounce handler') ?>">
        Email Disabled
    </span>
    <?php endif; ?>
<?php $heroBadges = ob_get_clean(); ?>
<?php ob_start(); /* secondary actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <a href="<?= e($customerUrl) ?>" class="btn btn-ghost btn-sm">View customer</a>
        <?php /* S-PERM-USERS-SUPERADMIN-ONLY — gate hardcoded /users link to super_admin only. */ ?>
        <?php if (can('users', 'view')): ?>
        <a href="<?= base_url('users') ?>?tab=portal" class="btn btn-ghost btn-sm">All portal users</a>
        <?php endif; ?>
        <?php if ($canEdit && $pu['status'] === 'active'): ?>
        <hr>
        <button type="button" class="btn btn-secondary btn-sm"
                :disabled="busy !== ''"
                @click="setStatus('inactive')">Deactivate</button>
        <?php endif; ?>
        <?php if ($canSuperAdmin && !$isPrimary): ?>
        <!-- Delete (non-primary only — primary users must be deactivated).
             portal_users has no soft-delete: irreversible. -->
        <button type="button" class="btn btn-danger btn-sm"
                :disabled="busy !== ''"
                @click="deletePortalUser()">
            <span x-show="busy !== 'delete'">Delete Portal User</span>
            <span x-show="busy === 'delete'" x-cloak>Deleting…</span>
        </button>
        <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?php if ($canEdit && $emailOff): ?>
        <!-- Re-enable email — surfaces only when email_disabled=1 -->
        <button type="button" class="btn btn-primary btn-sm"
                :disabled="busy !== ''"
                @click="reenableEmail()">
            <span x-show="busy !== 'email'">Re-enable Email</span>
            <span x-show="busy === 'email'" x-cloak>Saving…</span>
        </button>
        <?php endif; ?>
        <?php if ($canEdit && $pu['status'] === 'inactive'): ?>
        <button type="button" class="btn btn-primary btn-sm"
                :disabled="busy !== ''"
                @click="setStatus('active')">Activate</button>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <!-- Send Password Reset — a new link valid for 24 hours -->
        <button type="button" class="btn btn-secondary btn-sm"
                :disabled="busy !== ''"
                title="<?= APP_ENV === 'production' ? 'Emails a reset link (valid 24 hours) to the portal user' : 'Generates a reset link (valid 24 hours) — in dev it is written to logs/mail.log' ?>"
                @click="sendReset()">
            <span x-show="busy !== 'reset'">Send Reset Link</span>
            <span x-show="busy === 'reset'" x-cloak>Generating…</span>
        </button>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?php
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('envelope') . '<a href="mailto:' . e($pu['email']) . '" style="color:inherit;text-decoration:none;">' . e($pu['email']) . '</a>',
    \FleetForge\Sop\SopIcons::svg('building-storefront') . e($pu['company_name']),
    \FleetForge\Sop\SopIcons::svg('user-group') . ($isPrimary ? 'Primary contact' : 'Sub-user'),
];
$heroCrumbs = [['Dashboard', base_url('dashboard')]];
if (can('users', 'view')) {
    $heroCrumbs[] = ['Portal users', base_url('users') . '?tab=portal'];
}
$heroCrumbs[] = [(string) $pu['company_name'], $customerUrl];
$heroCrumbs[] = [(string) $pu['name'], null];
?>
<!-- ============================================================
     PORTAL USER — Alpine component. Opens ABOVE the hero
     (S-RECORD-REDESIGN) so the header / More-menu actions share one
     scope and one feedback line.
     ============================================================ -->
<div x-data="FF_PortalUserShow()">
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'info',
    'icon'       => 'users',
    'avatar'     => \FleetForge\Ui\ModuleHero::initials((string) $pu['name']),
    'mark'       => \FleetForge\Ui\ModuleHero::initials((string) $pu['name']),
    'crumbs'     => $heroCrumbs,
    'eyebrow'    => 'Portal user',
    'title_html' => e($pu['name']) . $heroBadges,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<!-- ============================================================
     KEY NUMBERS — the summary strip (S-RECORD-REDESIGN): can this
     person get in and hear from us.
     ============================================================ -->
<div class="stat-grid ff-stats">

    <div class="stat-card <?= $pu['last_login_at'] ? (($daysSinceLogin ?? 0) > 90 ? 'stat-card--amber' : 'stat-card--blue') : 'stat-card--slate' ?>">
        <span class="stat-icon <?= $pu['last_login_at'] ? (($daysSinceLogin ?? 0) > 90 ? 'stat-icon--amber' : 'stat-icon--blue') : 'stat-icon--slate' ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Last login</div>
        <div class="stat-value"><?= $pu['last_login_at'] ? e($ago($pu['last_login_at'])) : 'Never' ?></div>
        <div class="stat-delta"><?= $pu['last_login_at'] ? e(format_datetime($pu['last_login_at'], 'M j, g:i A')) : ($pu['status'] === 'invited' ? 'invite not accepted' : 'no login recorded') ?></div>
    </div>

    <?php $atTone = $isLocked ? 'red' : ($attempts >= 3 ? 'amber' : 'green'); ?>
    <div class="stat-card stat-card--<?= $atTone ?>">
        <span class="stat-icon stat-icon--<?= $atTone ?>"><svg><use href="#icon-<?= $isLocked ? 'lock-open' : 'shield-check' ?>"/></svg></span>
        <div class="stat-label">Failed attempts</div>
        <div class="stat-value font-mono"><?= $attempts ?></div>
        <div class="stat-delta"><?= $isLocked ? '<span class="text-danger">locked until ' . e(format_datetime($pu['locked_until'], 'g:i A')) . '</span>' : ($attempts > 0 ? 'locks at 5' : 'none since last login') ?></div>
    </div>

    <div class="stat-card <?= $emailOff ? 'stat-card--red' : 'stat-card--green' ?>">
        <span class="stat-icon <?= $emailOff ? 'stat-icon--red' : 'stat-icon--green' ?>"><svg><use href="#icon-<?= $emailOff ? 'x-circle' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Email delivery</div>
        <div class="stat-value"><?= $emailOff ? 'Disabled' : 'On' ?></div>
        <div class="stat-delta"><?= $emailOff ? ($pu['email_disabled_at'] ? 'since ' . e(format_datetime($pu['email_disabled_at'], 'M j, Y')) : 'bounced') : 'receiving emails' ?></div>
    </div>

    <?php $stTone = $pu['status'] === 'active' ? ($customerBlocked ? 'red' : 'green') : ($pu['status'] === 'invited' ? 'blue' : 'slate'); ?>
    <div class="stat-card stat-card--<?= $stTone ?>">
        <span class="stat-icon stat-icon--<?= $stTone ?>"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Status</div>
        <div class="stat-value"><?= e(ucfirst($pu['status'])) ?></div>
        <div class="stat-delta"><?php
            if ($pu['status'] === 'invited') {
                echo $pu['invite_sent_at'] ? 'invited ' . e($ago($pu['invite_sent_at'])) : 'invite pending';
            } elseif ($pu['status'] === 'active' && $customerBlocked) {
                echo '<span class="text-danger">customer blocked</span>';
            } else {
                echo $pu['status'] === 'active' ? 'can log in' : 'cannot log in';
            }
        ?></div>
    </div>

    <a class="stat-card stat-card--purple" href="#customer-team" title="Everyone who can log in to the portal for this customer">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-building"/></svg></span>
        <div class="stat-label">Customer's portal team</div>
        <div class="stat-value font-mono"><?= count($team) ?></div>
        <div class="stat-delta"><?= $teamActive ?> active</div>
    </a>

</div>

<div class="rec-layout">
<div class="rec-main">

    <!-- Action feedback — every header / menu action reports here. -->
    <div x-show="msg" x-cloak role="status"
         :style="{ color: err ? 'var(--color-danger)' : 'var(--color-success)' }"
         style="margin:0 0 12px;padding:10px 14px;border:1px solid var(--border-color);border-radius:var(--radius-xl);background:var(--bg-surface);font-size:13px;"
         x-text="msg"></div>

    <!-- ── Detail card ──────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Portal User Details</h3></div>
        <div class="card-body">
            <dl class="rec-dl">
                <dt>Name</dt>
                <dd><?= e($pu['name']) ?></dd>

                <dt>Email</dt>
                <dd>
                    <a href="mailto:<?= e($pu['email']) ?>"><?= e($pu['email']) ?></a>
                    <?php if ($emailOff): ?>
                    <span class="badge badge-danger" style="margin-left:6px;">Delivery disabled</span>
                    <?php endif; ?>
                </dd>

                <dt>Customer</dt>
                <dd>
                    <a href="<?= e($customerUrl) ?>" class="link"><?= e($pu['company_name']) ?></a>
                    <span class="badge <?= $pu['customer_status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"
                          style="margin-left:6px;">
                        <?= e(ucfirst(str_replace('_', ' ', (string) $pu['customer_status']))) ?>
                    </span>
                </dd>

                <dt>Role</dt>
                <dd>
                    <?php if ($isPrimary): ?>
                        <span class="badge badge-info">Primary</span>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:0.875rem;">Sub-user</span>
                    <?php endif; ?>
                </dd>

                <dt>Status</dt>
                <dd><span class="badge <?= e($statusBadges[$pu['status']] ?? 'badge-neutral') ?>"><?= e(ucfirst($pu['status'])) ?></span></dd>

                <dt>Last Login</dt>
                <dd>
                    <?= $pu['last_login_at'] ? e(format_datetime($pu['last_login_at'])) : '<span class="text-muted">Never</span>' ?>
                    <?php if ($pu['last_login_ip']): ?>
                    <span class="text-muted font-mono" style="font-size:0.8125rem;">(<?= e($pu['last_login_ip']) ?>)</span>
                    <?php endif; ?>
                </dd>

                <dt>Invited</dt>
                <dd><?= $pu['invite_sent_at'] ? e(format_datetime($pu['invite_sent_at'])) : '<span class="text-muted">—</span>' ?></dd>

                <dt>Created</dt>
                <dd><?= e(format_datetime($pu['created_at'])) ?></dd>
            </dl>
        </div>
    </div>

    <!-- ── Login history ──────────────────────────────────────────── -->
    <?php if (!empty($loginHistory)): ?>
    <div class="card" id="login-activity">
        <div class="card-header"><h3 class="card-title">Recent Login Activity</h3></div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
            <table class="table" style="margin:0;">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>IP</th>
                        <th>User Agent</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loginHistory as $hist): ?>
                    <tr>
                        <td class="font-mono" style="font-size:0.8125rem;"><?= e(format_datetime($hist['created_at'])) ?></td>
                        <td class="font-mono" style="font-size:0.8125rem;"><?= e($hist['ip_address'] ?? '—') ?></td>
                        <?php /* Plain title attribute — the old :title="hist.user_agent"
                                 was an Alpine binding to a variable that only
                                 exists in PHP. */ ?>
                        <td style="font-size:0.75rem;color:var(--text-muted);max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?= e((string) ($hist['user_agent'] ?? '')) ?>"><?= e(substr($hist['user_agent'] ?? '', 0, 80)) ?></td>
                        <td style="font-size:0.8125rem;"><?= e($hist['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Activity Log ───────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Activity</h3></div>
        <div class="card-body">
            <?php $activityEntityType = 'portal_user'; $activityEntityId = $puId; ?>
            <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
        </div>
    </div>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the portal login at a glance ──────────────────
$R = \FleetForge\Ui\RecordUi::class;
$railP = [];

// 1. Needs attention.
$alertsP = [];
if ($emailOff) {
    $alertsP[] = ['danger', '<b>Email delivery disabled</b> — '
        . ($pu['email_disabled_reason'] ? e($pu['email_disabled_reason']) : 'auto-disabled by the SES bounce handler')
        . ($pu['email_disabled_at'] ? ' (' . e(format_datetime($pu['email_disabled_at'], 'M j, Y')) . ')' : '')
        . '. No invoices or notices reach them.'];
}
if ($customerBlocked) {
    $alertsP[] = ['danger', 'The customer is <b>' . e(str_replace('_', ' ', (string) $pu['customer_status'])) . '</b> — nobody on this account can log in to the portal.'];
}
if ($isLocked) {
    $alertsP[] = ['danger', 'Locked after ' . $attempts . ' failed logins until ' . e(format_datetime($pu['locked_until'], 'M j, g:i A')) . '.'];
} elseif ($attempts >= 3) {
    $alertsP[] = ['warning', $attempts . ' failed login attempts in a row — locks at 5.'];
}
if ($pu['status'] === 'invited') {
    $alertsP[] = $inviteExpired
        ? ['warning', 'The invite expired ' . e(format_datetime($pu['invite_token_expiry'], 'M j, Y')) . ($canEdit ? ' — send a reset link to let them in.' : '.')]
        : ['info', 'Invite not accepted yet' . ($pu['invite_sent_at'] ? ' (sent ' . e($ago($pu['invite_sent_at'])) . ')' : '') . '.'];
} elseif ($pu['status'] === 'inactive') {
    $alertsP[] = ['info', '<b>Inactive</b> — cannot log in.' . ($isPrimary && $teamActive === 0 ? ' No one else on this account can either.' : '')];
}
if ($pu['status'] === 'active' && !$pu['last_login_at']) {
    $alertsP[] = ['info', 'Has never logged in.'];
} elseif ($pu['status'] === 'active' && $daysSinceLogin !== null && $daysSinceLogin > 90) {
    $alertsP[] = ['info', 'No login in ' . $daysSinceLogin . ' days.'];
}
if ($resetPending) {
    $alertsP[] = ['info', 'A password reset link is outstanding (expires ' . e(format_datetime($pu['password_reset_expiry'], 'M j, g:i A')) . ').'];
}
$railP[] = $R::card('Needs attention', $R::alerts($alertsP, 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// 2. Customer + the rest of their portal team.
$custBody = $R::entity((string) $pu['company_name'], $customerUrl,
    e(ucfirst(str_replace('_', ' ', (string) $pu['customer_status']))) . ' · ' . ($isPrimary ? 'primary contact' : 'sub-user'),
    \FleetForge\Ui\ModuleHero::initials((string) $pu['company_name']));
$others = array_values(array_filter($team, static fn ($t) => (int) $t['id'] !== (int) $puId));
if ($others) {
    $teamLinks = [];
    foreach (array_slice($others, 0, 6) as $t) {
        $teamLinks[] = [(string) $t['name'], base_url('portal_users/show') . '?id=' . (int) $t['id'], 'users',
            ((int) $t['is_primary'] === 1 ? 'primary · ' : '') . $t['status']];
    }
    $custBody .= '<div style="margin-top:12px;">' . $R::links($teamLinks) . '</div>';
} else {
    $custBody .= '<p class="text-secondary" style="margin:10px 0 0;font-size:12.5px;">The only portal user on this account.</p>';
}
$railP[] = $R::card('Customer', $custBody, [
    'icon' => 'building-storefront',
    'class' => 'rec-card--accent',
    'id'   => 'customer-team',
    'foot' => count($others) > 6 ? '+' . (count($others) - 6) . ' more portal users.' : '',
]);

// 3. Security.
$railP[] = $R::card('Security', $R::kv([
    ['Last login', $pu['last_login_at'] ? e(format_datetime($pu['last_login_at'], 'M j, Y g:i A')) : 'Never'],
    ['Last IP', !empty($pu['last_login_ip']) ? '<span class="mono">' . e($pu['last_login_ip']) . '</span>' : null],
    ['Failed attempts', $attempts > 0 ? $attempts . ' in a row' : 'None'],
    ['Locked until', $isLocked ? '<span class="text-danger">' . e(format_datetime($pu['locked_until'], 'M j, g:i A')) . '</span>' : null],
    ['Email delivery', $emailOff ? '<span class="text-danger">Disabled</span>' : 'On'],
    ['Reset link', $resetPending ? 'expires ' . e(format_datetime($pu['password_reset_expiry'], 'M j, g:i A')) : null],
    ['Invite expires', $pu['status'] === 'invited' && $pu['invite_token_expiry'] ? ($inviteExpired ? '<span class="text-danger">' : '') . e(format_datetime($pu['invite_token_expiry'], 'M j, Y')) . ($inviteExpired ? ' (expired)</span>' : '') : null],
    ['Last updated', e(format_datetime($pu['updated_at'], 'M j, Y g:i A'))],
]), ['icon' => 'shield-check']);
?>
<aside class="rec-rail" aria-label="Portal user at a glance">
    <?= implode("\n    ", $railP) ?>
</aside>
</div><!-- /rec-layout -->

</div><!-- /x-data FF_PortalUserShow -->

<script>
// ── Portal user actions (S-RECORD-REDESIGN) ─────────────────────────────────
// One component for the header + More-menu buttons (they were one inline
// x-data per card). Same endpoints, confirms and reload behaviour.
// WHY r.success checks: FF_Api.post resolves (never rejects) on a 4xx/5xx
// JSON error, so try/catch alone would treat a refusal as success.
function FF_PortalUserShow() {
    const PU_ID    = <?= (int) $puId ?>;
    const PU_EMAIL = <?= json_encode((string) $pu['email'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const PU_NAME  = <?= json_encode((string) $pu['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const RESET_NOTE = <?= json_encode(APP_ENV === 'production'
        ? 'The link (valid 24 hours) is emailed to the portal user.'
        : 'The link is valid 24 hours. In dev it is written to logs/mail.log.') ?>;
    return {
        busy: '',     // '' | 'email' | 'reset' | 'status' | 'delete'
        msg:  '',
        err:  false,

        say(text, isErr) { this.msg = text; this.err = !!isErr; },

        async reenableEmail() {
            if (!(await FF_Confirm.ask('Re-enable email delivery to ' + PU_EMAIL + '?'))) return;
            this.busy = 'email'; this.msg = '';
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/portal_users/reenable_email.php'), { id: PU_ID });
                if (r.success) {
                    this.say('Email re-enabled. Refreshing…', false);
                    setTimeout(() => location.reload(), 800);
                    return;
                }
                this.say(r.error?.message ?? 'Failed.', true);
            } catch (e) { this.say('Network error.', true); }
            this.busy = '';
        },

        async sendReset() {
            if (!(await FF_Confirm.ask({ title: 'Send reset link', message: 'Send password reset link to ' + PU_EMAIL + '? ' + RESET_NOTE, confirmLabel: 'Send', dangerMode: false }))) return;
            this.busy = 'reset'; this.msg = '';
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/portal_users/reset_password.php'), { id: PU_ID });
                if (r.success) {
                    this.say(r.data?.message || 'Reset link generated.', false);
                } else {
                    this.say(r.error?.message ?? 'Failed.', true);
                }
            } catch (e) { this.say('Network error.', true); }
            this.busy = '';
        },

        async setStatus(status) {
            if (status === 'inactive' && !(await FF_Confirm.ask('Deactivate this portal user? They will not be able to log in.'))) return;
            this.busy = 'status'; this.msg = '';
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/portal_users/update_status.php'), { id: PU_ID, status: status });
                if (r.success) { location.reload(); return; }
                this.say(r.error?.message ?? 'Failed.', true);
            } catch (e) { this.say('Network error.', true); }
            this.busy = '';
        },

        async deletePortalUser() {
            if (!(await FF_Confirm.ask('Permanently delete ' + PU_NAME + '? This cannot be undone.'))) return;
            this.busy = 'delete'; this.msg = '';
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/portal_users/delete.php'), { id: PU_ID });
                if (r.success) {
                    window.location = '<?= base_url('users') ?>?tab=portal';
                    return;
                }
                this.say(r.error?.message ?? 'Failed.', true);
            } catch (e) { this.say('Network error.', true); }
            this.busy = '';
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
