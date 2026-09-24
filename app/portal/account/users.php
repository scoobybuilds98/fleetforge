<?php
declare(strict_types=1);

/**
 * app/portal/account/users.php
 *
 * Customer portal — Team members (S-PORTAL-REDESIGN). Main contact only.
 *
 * Invite colleagues, resend an invite, deactivate / reactivate a login.
 * Everyone on the account sees the same invoices, leases and requests.
 *
 * Fixed here: invites were NEVER emailed — the set-password link was only
 * appended to logs/mail.log, while the page told the customer "Invitation
 * sent". Invites now go through the shared Mailer (SES in production,
 * logs/mail.log in development — the same path as staff-created invites,
 * api/v1/portal_users/create.php) and the message says what really happened.
 * Actions are audited and redirect after POST (refresh no longer repeats
 * them).
 *
 * Rules (unchanged): only the main contact manages the team; the main
 * contact can't be deactivated; an invite link sets the password through
 * portal/auth/reset_password and expires in 7 days; password_reset_token
 * is used (invite_token is the remember-me hash).
 *
 * Trap 8: every target row is checked against portal_customer_id().
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

use FleetForge\Email\EmailService;
use FleetForge\Notifications\Mailer;
use FleetForge\Security\RateLimiter;

if (!portal_is_primary()) {
    header('Location: ' . pt_url('account'));
    exit;
}

$cid  = portal_customer_id();
$puid = (int) portal_user_id();
$me   = portal_user();

/**
 * Email a set-password invite. Returns true when the Mailer accepted it.
 */
$sendInvite = static function (string $email, string $name, string $plainToken) use ($me, $cid): bool {
    $operator = (string) settings_get('company.name', 'FleetForge');
    $customer = (string) (db_row("SELECT company_name FROM customers WHERE id = ?", [$cid])['company_name'] ?? '');
    $url      = base_url('portal/auth/reset_password') . '?token=' . $plainToken . '&email=' . urlencode($email);
    $color    = preg_match('/^#[0-9a-fA-F]{6}$/', (string) settings_get('brand.primary_color', '')) ? (string) settings_get('brand.primary_color') : '#2563eb';
    $html = EmailService::renderEmailHtml(
        '<h2 style="margin:0 0 16px;">You\'re invited to the ' . e($operator) . ' customer portal</h2>'
        . '<p>Hi ' . e($name) . ',</p>'
        . '<p>' . e((string) ($me['name'] ?? 'A colleague')) . ' added you to ' . e($customer)
        . '\'s account. In the portal you can see and pay invoices, check your rentals and message our team.</p>'
        . '<p style="margin:24px 0;"><a href="' . e($url) . '" style="display:inline-block;background:' . e($color)
        . ';color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;">Set your password</a></p>'
        . '<p style="color:#64748b;font-size:13px;">This link expires in 7 days. If the button doesn\'t work, paste this into your browser:<br>'
        . '<span style="word-break:break-all;">' . e($url) . '</span></p>'
    );
    try {
        return Mailer::send($email, $name, 'You\'re invited to the ' . $operator . ' customer portal', $html);
    } catch (\Throwable $e) {
        error_log('[portal/account/users invite] ' . $e->getMessage());
        return false;
    }
};
$audit = static function (string $action, int $targetId, string $label, string $notes) use ($puid): void {
    try {
        db_insert('audit_log', [
            'user_id' => null, 'user_name' => 'portal:' . $puid, 'action' => $action, 'module' => 'portal',
            'entity_type' => 'portal_user', 'entity_id' => $targetId, 'entity_label' => mb_substr($label, 0, 255),
            'notes' => $notes, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    } catch (\Throwable $e) {
        error_log('[portal/account/users audit] ' . $e->getMessage());
    }
};
$sentMsg = static fn (bool $ok, string $email): string => $ok
    ? (APP_ENV === 'production' ? 'Invitation emailed to ' . $email . '.' : 'Invitation created for ' . $email . ' (development: written to logs/mail.log).')
    : 'We saved the invitation for ' . $email . ', but the email didn\'t go out. Try "Resend" in a moment, or contact us.';

$flash = $_SESSION['pt_team_flash'] ?? null;
unset($_SESSION['pt_team_flash']);
$error = '';
$old   = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) clean_string($_POST['action'] ?? '', 30);
    $done   = null;

    if (!portal_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session token expired. Please try again.';
    } elseif ($action === 'invite') {
        $old = ['name' => trim((string) clean_string($_POST['invite_name'] ?? '', 255)), 'email' => trim((string) clean_string($_POST['invite_email'] ?? '', 255))];
        $rl  = RateLimiter::check('portal_team_invite:' . $puid, 20, 60);
        if (!$rl['allowed']) {
            $error = 'You\'ve sent a lot of invitations recently. Please try again later.';
        } elseif ($old['name'] === '') {
            $error = 'Enter their name.';
        } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (db_row("SELECT id FROM portal_users WHERE email = ?", [$old['email']])) {
            $error = 'That email address already has a portal login. If it should be moved to your account, contact us.';
        } else {
            $plain = bin2hex(random_bytes(32));
            try {
                $newId = (int) db_insert('portal_users', [
                    'customer_id'           => $cid,
                    'name'                  => $old['name'],
                    'email'                 => $old['email'],
                    'status'                => 'invited',
                    'is_primary'            => 0,
                    'password_reset_token'  => hash('sha256', $plain),
                    'password_reset_expiry' => ff_now_utc('+7 days'),
                    'invite_sent_at'        => ff_now_utc(),
                ]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000' && stripos($e->getMessage(), 'email') !== false) {
                    $error = 'That email address already has a portal login.';
                    $newId = 0;
                } else {
                    throw $e;
                }
            }
            if ($newId) {
                $ok = $sendInvite($old['email'], $old['name'], $plain);
                $audit('create', $newId, $old['name'] . ' <' . $old['email'] . '>', 'Main contact invited a team member (email ' . ($ok ? 'sent' : 'FAILED') . ').');
                $done = [$ok ? 'success' : 'warning', $sentMsg($ok, $old['email'])];
            }
        }
    } elseif (in_array($action, ['deactivate', 'reactivate', 'resend_invite'], true)) {
        $targetId = (int) (clean_int($_POST['user_id'] ?? null) ?? 0);
        $t = db_row("SELECT id, name, email, status, is_primary FROM portal_users WHERE id = ? AND customer_id = ?", [$targetId, $cid]);
        if (!$t) {
            $error = 'That team member wasn\'t found.';
        } elseif ($action === 'deactivate') {
            if ((int) $t['is_primary'] === 1 || (int) $t['id'] === $puid) {
                $error = 'The main contact can\'t be deactivated here.';
            } else {
                db_update('portal_users', ['status' => 'inactive', 'invite_token' => null], 'id = ? AND customer_id = ?', [$targetId, $cid]);
                $audit('update', $targetId, (string) $t['email'], 'Main contact deactivated this portal login.');
                $done = ['success', $t['name'] . ' can no longer sign in.'];
            }
        } elseif ($action === 'reactivate') {
            if ($t['status'] !== 'inactive') {
                $error = 'Only deactivated logins can be reactivated.';
            } else {
                db_update('portal_users', ['status' => 'active'], 'id = ? AND customer_id = ?', [$targetId, $cid]);
                $audit('update', $targetId, (string) $t['email'], 'Main contact reactivated this portal login.');
                $done = ['success', $t['name'] . ' can sign in again.'];
            }
        } else { // resend_invite
            if ($t['status'] !== 'invited') {
                $error = 'They\'ve already accepted their invitation.';
            } else {
                $plain = bin2hex(random_bytes(32));
                db_update('portal_users', [
                    'password_reset_token'  => hash('sha256', $plain),
                    'password_reset_expiry' => ff_now_utc('+7 days'),
                    'invite_sent_at'        => ff_now_utc(),
                ], 'id = ? AND customer_id = ?', [$targetId, $cid]);
                $ok = $sendInvite((string) $t['email'], (string) $t['name'], $plain);
                $audit('update', $targetId, (string) $t['email'], 'Main contact re-sent the portal invite (email ' . ($ok ? 'sent' : 'FAILED') . ').');
                $done = [$ok ? 'success' : 'warning', $sentMsg($ok, (string) $t['email'])];
            }
        }
    }

    if ($done !== null) {
        $_SESSION['pt_team_flash'] = $done;
        header('Location: ' . pt_url('account/users'));
        exit;
    }
}

$team = db_select(
    "SELECT id, name, email, status, is_primary, last_login_at, invite_sent_at
       FROM portal_users WHERE customer_id = ?
      ORDER BY is_primary DESC, status = 'inactive', name ASC",
    [$cid]
);

$pageTitle = 'Team members';
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'back'  => ['Settings', pt_url('account')],
    'title' => 'Team members',
    'sub'   => 'Give colleagues their own login. Everyone on your account sees the same invoices, leases and requests.',
]);
?>

<?php if ($flash): ?>
    <div class="pt-note pt-note--<?= e($flash[0]) ?>" style="margin-bottom:16px"><?= pt_icon($flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle') ?><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="pt-note pt-note--danger" style="margin-bottom:16px"><?= pt_icon('exclamation-triangle') ?><span><?= e($error) ?></span></div>
<?php endif; ?>

<div class="pt-grid pt-grid--main">
    <section class="pt-card">
        <div class="pt-card-head"><div><h2 class="pt-card-title"><?= pt_icon('users') ?> People with access</h2><p class="pt-card-sub"><?= count($team) ?> login<?= count($team) === 1 ? '' : 's' ?></p></div></div>
        <div class="pt-card-body--flush pt-table-wrap">
            <table data-no-auto-label class="pt-table pt-table--stack">
                <thead><tr><th>Name</th><th>Status</th><th>Last signed in</th><th class="shrink"><span class="pt-sr">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($team as $t):
                    [$sl, $st] = match ($t['status']) {
                        'active'   => ['Active', 'success'],
                        'invited'  => ['Invited', 'info'],
                        'inactive' => ['Deactivated', 'neutral'],
                        default    => [ucfirst((string) $t['status']), 'neutral'],
                    };
                ?>
                    <tr>
                        <td class="pt-cell-primary">
                            <div style="display:flex;gap:12px;align-items:center">
                                <span class="pt-avatar"><?= e(pt_initials((string) $t['name'])) ?></span>
                                <span><span class="pt-table-main"><?= e((string) $t['name']) ?><?= (int) $t['id'] === $puid ? ' <span class="pt-faint" style="font-weight:500">(you)</span>' : '' ?></span><span class="pt-table-sub"><?= e((string) $t['email']) ?></span></span>
                            </div>
                        </td>
                        <td data-label="Status"><?= pt_badge($sl, $st) ?><?= (int) $t['is_primary'] ? ' ' . pt_badge('Main contact', 'brand') : '' ?></td>
                        <td class="nw" data-label="Last signed in"><?= $t['last_login_at'] ? e(format_datetime($t['last_login_at'], 'M j, Y')) : ($t['status'] === 'invited' && $t['invite_sent_at'] ? '<span class="pt-faint">Invited ' . e(format_datetime($t['invite_sent_at'], 'M j')) . '</span>' : '<span class="pt-faint">Never</span>') ?></td>
                        <td class="shrink pt-cell-actions">
                            <?php if (!(int) $t['is_primary'] && (int) $t['id'] !== $puid): ?>
                                <form method="POST" style="display:inline" <?= $t['status'] === 'active' ? 'onsubmit="return confirm(\'Deactivate ' . e(addslashes((string) $t['name'])) . '? They won\\\'t be able to sign in.\')"' : '' ?>>
                                    <input type="hidden" name="csrf_token" value="<?= e(portal_csrf_token()) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
                                    <?php if ($t['status'] === 'invited'): ?>
                                        <button class="pt-btn pt-btn--secondary pt-btn--sm" name="action" value="resend_invite"><?= pt_icon('paper-airplane') ?> Resend</button>
                                        <button class="pt-btn pt-btn--ghost pt-btn--sm" name="action" value="deactivate">Cancel invite</button>
                                    <?php elseif ($t['status'] === 'active'): ?>
                                        <button class="pt-btn pt-btn--ghost pt-btn--sm" name="action" value="deactivate">Deactivate</button>
                                    <?php else: ?>
                                        <button class="pt-btn pt-btn--secondary pt-btn--sm" name="action" value="reactivate">Reactivate</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="pt-stack">
        <section class="pt-card">
            <div class="pt-card-head"><h2 class="pt-card-title"><?= pt_icon('plus') ?> Invite someone</h2></div>
            <form method="POST" class="pt-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(portal_csrf_token()) ?>">
                <input type="hidden" name="action" value="invite">
                <div class="pt-field"><label class="pt-label" for="tm-name">Name</label><input id="tm-name" name="invite_name" class="pt-input" maxlength="255" value="<?= e($old['name']) ?>" required></div>
                <div class="pt-field"><label class="pt-label" for="tm-email">Work email</label><input id="tm-email" name="invite_email" type="email" class="pt-input" maxlength="255" value="<?= e($old['email']) ?>" required></div>
                <p class="pt-hint" style="margin:12px 0 0">They'll get an email with a link to set their password (valid for 7 days).</p>
                <button type="submit" class="pt-btn pt-btn--primary pt-btn--block" style="margin-top:14px"><?= pt_icon('paper-airplane') ?> Send invitation</button>
            </form>
        </section>
    </aside>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
