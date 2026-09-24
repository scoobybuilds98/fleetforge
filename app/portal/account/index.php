<?php
declare(strict_types=1);

/**
 * app/portal/account/index.php
 *
 * Customer portal — Account settings (S-PORTAL-REDESIGN).
 *
 *   Profile          your name (email is the sign-in and set by us)
 *   Password         change it (current password required)
 *   Email reminders  opt out of any customer reminder type — invoice due
 *                    soon, overdue, payment receipts, monthly statement,
 *                    lease ending, pickup, compliance expiry. Honoured by
 *                    CustomerReminders::customerAllowed(), which reads the
 *                    PRIMARY contact's preferences (so only they edit them).
 *                    Keys = CustomerReminders::portalPrefKey() (compliance
 *                    keeps its legacy 'compliance_expiring' key).
 *   Company          terms, currency, billing contact — read-only, with a
 *                    request link to change them
 *
 * Fixed here: POSTs re-rendered in place (a refresh re-submitted, and the
 * page always jumped back to the Profile tab); a password change didn't
 * refresh the session id, didn't revoke "stay signed in" tokens, wasn't
 * audited or rate-limited. Now: post → redirect → the right section.
 *
 * Trap 8: the portal user + customer come from the session only.
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

use FleetForge\Security\RateLimiter;

$cid  = portal_customer_id();
$puid = (int) portal_user_id();

$user = db_row("SELECT * FROM portal_users WHERE id = ?", [$puid]);
$customer = db_row(
    "SELECT company_name, payment_terms, currency, billing_contact_name, billing_email, billing_phone,
            invoice_email, invoice_delivery, address, city, province, postal_code, phone, email
       FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$cid]
) ?: [];
$primary = db_row(
    "SELECT name, email FROM portal_users WHERE customer_id = ? AND is_primary = 1 AND status = 'active' ORDER BY id LIMIT 1",
    [$cid]
);

// Reminder types the customer can opt out of: [pref key, label, blurb].
$reminders = [
    ['invoice_due_soon',    'Invoice due soon',        'A heads-up a few days before an invoice is due.'],
    ['invoice_overdue',     'Overdue reminders',       'A reminder when an invoice is past due.'],
    ['payment_receipt',     'Payment receipts',        'A confirmation each time we record a payment.'],
    ['statement',           'Monthly statement',       'An account summary once a month.'],
    ['lease_ending_soon',   'Lease ending soon',       'A reminder before a lease reaches its end date.'],
    ['reservation_pickup',  'Pickup reminders',        'A reminder before a reserved pickup.'],
    ['compliance_expiring', 'Paperwork expiring',      'When a unit\'s CVI or registration is about to expire.'],
];

$section = (string) ($_GET['section'] ?? 'profile');
$saved   = (string) ($_GET['saved'] ?? '');
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = (string) clean_string($_POST['action'] ?? '', 40);
    $section = match ($action) { 'change_password' => 'password', 'update_notifications' => 'email', default => 'profile' };

    if (!portal_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session token expired. Please try again.';
    } elseif ($action === 'update_profile') {
        $name = trim((string) clean_string($_POST['name'] ?? '', 255));
        if ($name === '') {
            $error = 'Please enter your name.';
        } else {
            db_update('portal_users', ['name' => $name], 'id = ?', [$puid]);
            $_SESSION['ff_portal_user']['name'] = $name;
            header('Location: ' . pt_url('account?section=profile&saved=profile'));
            exit;
        }
    } elseif ($action === 'update_notifications') {
        if (!portal_is_primary()) {
            $error = 'Only your account\'s main contact can change email reminders.';
        } else {
            $prefs = json_decode((string) ($user['notification_preferences'] ?? '{}'), true);
            if (!is_array($prefs)) $prefs = [];
            foreach ($reminders as [$key]) {
                $prefs[$key] = isset($_POST['pref'][$key]);
            }
            db_update('portal_users', ['notification_preferences' => json_encode($prefs)], 'id = ?', [$puid]);
            header('Location: ' . pt_url('account?section=email&saved=email'));
            exit;
        }
    } elseif ($action === 'change_password') {
        $rl = RateLimiter::check('portal_pw_change:' . $puid, 5, 15, 15);
        $currentPw = (string) ($_POST['current_password'] ?? '');
        $newPw     = (string) ($_POST['new_password'] ?? '');
        $confirmPw = (string) ($_POST['confirm_password'] ?? '');
        if (!$rl['allowed']) {
            $error = 'Too many attempts. Please wait a few minutes and try again.';
        } elseif (empty($user['password_hash']) || !password_verify($currentPw, (string) $user['password_hash'])) {
            $error = 'Your current password isn\'t right.';
        } elseif (strlen($newPw) < 8) {
            $error = 'Your new password needs at least 8 characters.';
        } elseif ($newPw !== $confirmPw) {
            $error = 'The new passwords don\'t match.';
        } elseif (password_verify($newPw, (string) $user['password_hash'])) {
            $error = 'Choose a password you haven\'t used here before.';
        } else {
            // New hash; revoke every "stay signed in" token; fresh session id.
            db_update('portal_users', [
                'password_hash' => password_hash($newPw, PASSWORD_DEFAULT),
                'invite_token'  => null,
            ], 'id = ?', [$puid]);
            session_regenerate_id(true);
            try {
                db_insert('audit_log', [
                    'user_id' => null, 'user_name' => 'portal:' . $puid, 'action' => 'update', 'module' => 'portal',
                    'entity_type' => 'portal_user', 'entity_id' => $puid, 'entity_label' => (string) $user['email'],
                    'notes' => 'Portal user changed their password.', 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
            } catch (\Throwable $e) {
                error_log('[portal/account password audit] ' . $e->getMessage());
            }
            header('Location: ' . pt_url('account?section=password&saved=password'));
            exit;
        }
    }
}

$prefs = json_decode((string) ($user['notification_preferences'] ?? '{}'), true);
if (!is_array($prefs)) $prefs = [];
$on = static fn (string $k): bool => !array_key_exists($k, $prefs) || $prefs[$k] !== false;

if (!in_array($section, ['profile', 'password', 'email', 'company'], true)) $section = 'profile';

$pageTitle = 'Account settings';
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'eyebrow' => 'Account',
    'title'   => 'Settings',
    'sub'     => 'Your profile, password, and which reminder emails you receive.',
]);

$nav = [
    'profile'  => ['Profile', 'user-circle'],
    'password' => ['Password', 'lock-closed'],
    'email'    => ['Email reminders', 'envelope'],
    'company'  => ['Company & billing', 'building-office'],
];
?>

<div class="pt-grid" style="grid-template-columns:240px minmax(0,1fr);align-items:start" x-data="{ s: <?= e(json_encode($section)) ?> }">
    <nav class="pt-card" style="padding:8px" aria-label="Settings sections">
        <?php foreach ($nav as $k => [$label, $icon]): ?>
            <a href="<?= e(pt_url('account?section=' . $k)) ?>" class="pt-nav-item" :class="{ 'is-active': s === '<?= $k ?>' }" @click.prevent="s = '<?= $k ?>'; history.replaceState(null, '', '?section=<?= $k ?>')">
                <?= pt_icon($icon) ?><span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (portal_is_primary()): ?>
            <div class="pt-menu-sep"></div>
            <a href="<?= e(pt_url('account/users')) ?>" class="pt-nav-item"><?= pt_icon('users') ?><span>Team members</span><?= pt_icon('chevron-right', 'pt-ic pt-ic--sm') ?></a>
        <?php endif; ?>
    </nav>

    <div>
        <?php if ($error !== ''): ?>
            <div class="pt-note pt-note--danger" style="margin-bottom:16px"><?= pt_icon('exclamation-triangle') ?><span><?= e($error) ?></span></div>
        <?php elseif ($saved !== ''): ?>
            <div class="pt-note pt-note--success" style="margin-bottom:16px"><?= pt_icon('check-circle') ?><span><?= e(match ($saved) { 'password' => 'Password changed. You\'ll use the new one next time you sign in.', 'email' => 'Email reminder preferences saved.', default => 'Profile saved.' }) ?></span></div>
        <?php endif; ?>

        <!-- Profile -->
        <section class="pt-card" x-show="s === 'profile'" x-cloak>
            <div class="pt-card-head pt-card-head--line"><div><h2 class="pt-card-title">Profile</h2><p class="pt-card-sub">How you appear to our team.</p></div></div>
            <form method="POST" class="pt-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(portal_csrf_token()) ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="pt-form-grid">
                    <div class="pt-field"><label class="pt-label" for="ac-name">Full name</label><input id="ac-name" name="name" class="pt-input" maxlength="255" value="<?= e((string) ($user['name'] ?? '')) ?>" required></div>
                    <div class="pt-field"><label class="pt-label" for="ac-email">Email <small>(your sign-in)</small></label><input id="ac-email" class="pt-input" value="<?= e((string) ($user['email'] ?? '')) ?>" readonly></div>
                    <div class="pt-field"><span class="pt-label">Role</span><span class="pt-muted" style="font-size:14px"><?= portal_is_primary() ? 'Main contact — can manage team members and reminders' : 'Team member' ?></span></div>
                    <div class="pt-field"><span class="pt-label">Last signed in</span><span class="pt-muted" style="font-size:14px"><?= !empty($user['last_login_at']) ? e(format_datetime($user['last_login_at'])) : '—' ?></span></div>
                </div>
                <div class="pt-btn-row" style="justify-content:flex-end;margin-top:18px"><button type="submit" class="pt-btn pt-btn--primary">Save profile</button></div>
            </form>
        </section>

        <!-- Password -->
        <section class="pt-card" x-show="s === 'password'" x-cloak>
            <div class="pt-card-head pt-card-head--line"><div><h2 class="pt-card-title">Password</h2><p class="pt-card-sub">Changing it signs out "stay signed in" on your other devices.</p></div></div>
            <form method="POST" class="pt-card-body" x-data="{ show: false }">
                <input type="hidden" name="csrf_token" value="<?= e(portal_csrf_token()) ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="pt-form-grid">
                    <div class="pt-field span-2"><label class="pt-label" for="pw-cur">Current password</label><input id="pw-cur" name="current_password" :type="show ? 'text' : 'password'" class="pt-input" autocomplete="current-password" required></div>
                    <div class="pt-field"><label class="pt-label" for="pw-new">New password <small>(8+ characters)</small></label><input id="pw-new" name="new_password" :type="show ? 'text' : 'password'" class="pt-input" minlength="8" autocomplete="new-password" required></div>
                    <div class="pt-field"><label class="pt-label" for="pw-conf">Confirm new password</label><input id="pw-conf" name="confirm_password" :type="show ? 'text' : 'password'" class="pt-input" minlength="8" autocomplete="new-password" required></div>
                </div>
                <div class="pt-btn-row" style="justify-content:space-between;margin-top:18px">
                    <label style="display:flex;gap:8px;align-items:center;font-size:13.5px;cursor:pointer"><input type="checkbox" class="pt-check" x-model="show"> Show passwords</label>
                    <button type="submit" class="pt-btn pt-btn--primary">Change password</button>
                </div>
            </form>
        </section>

        <!-- Email reminders -->
        <section class="pt-card" x-show="s === 'email'" x-cloak>
            <div class="pt-card-head pt-card-head--line"><div><h2 class="pt-card-title">Email reminders</h2>
                <p class="pt-card-sub">Reminders go to your company's billing email<?= !empty($customer['invoice_email']) ? ' (' . e($customer['invoice_email']) . ')' : (!empty($customer['billing_email']) ? ' (' . e($customer['billing_email']) . ')' : '') ?>. Turn off any you don't want.</p></div></div>
            <form method="POST" class="pt-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(portal_csrf_token()) ?>">
                <input type="hidden" name="action" value="update_notifications">
                <?php if (!portal_is_primary()): ?>
                    <div class="pt-note" style="margin-bottom:14px"><?= pt_icon('information-circle') ?><span>Your account's main contact<?= $primary ? ', <strong>' . e($primary['name']) . '</strong>,' : '' ?> manages these for your company.</span></div>
                <?php endif; ?>
                <div style="display:flex;flex-direction:column">
                    <?php foreach ($reminders as [$key, $label, $blurb]): ?>
                        <label style="display:flex;gap:14px;align-items:flex-start;padding:14px 0;border-bottom:1px solid var(--border-color);cursor:pointer">
                            <input type="checkbox" class="pt-check" style="margin-top:2px" name="pref[<?= e($key) ?>]" value="1" <?= $on($key) ? 'checked' : '' ?> <?= portal_is_primary() ? '' : 'disabled' ?>>
                            <span><span style="display:block;font-weight:600;font-size:14px"><?= e($label) ?></span><span class="pt-muted" style="font-size:13px"><?= e($blurb) ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="pt-hint" style="margin:12px 0 0">Invoices themselves, and messages from our team about your account, are always sent.</p>
                <?php if (portal_is_primary()): ?>
                    <div class="pt-btn-row" style="justify-content:flex-end;margin-top:18px"><button type="submit" class="pt-btn pt-btn--primary">Save preferences</button></div>
                <?php endif; ?>
            </form>
        </section>

        <!-- Company -->
        <section class="pt-card" x-show="s === 'company'" x-cloak>
            <div class="pt-card-head pt-card-head--line"><div><h2 class="pt-card-title">Company &amp; billing</h2><p class="pt-card-sub">What we have on file for <?= e((string) ($customer['company_name'] ?? '')) ?>.</p></div></div>
            <div class="pt-card-body">
                <dl class="pt-kv">
                    <div class="pt-kv-row"><dt>Company</dt><dd><?= e((string) ($customer['company_name'] ?? '')) ?></dd></div>
                    <div class="pt-kv-row"><dt>Payment terms</dt><dd><?= e((string) ($customer['payment_terms'] ?: 'Standard')) ?></dd></div>
                    <div class="pt-kv-row"><dt>Billing currency</dt><dd><?= e((string) ($customer['currency'] ?? 'CAD')) ?></dd></div>
                    <div class="pt-kv-row"><dt>Invoices sent to</dt><dd><?= e((string) ($customer['invoice_email'] ?: $customer['billing_email'] ?: $customer['email'] ?: '—')) ?></dd></div>
                    <div class="pt-kv-row"><dt>Billing contact</dt><dd><?= e(trim((string) ($customer['billing_contact_name'] ?? '') . ((string) ($customer['billing_phone'] ?? '') !== '' ? ' · ' . $customer['billing_phone'] : '')) ?: '—') ?></dd></div>
                    <div class="pt-kv-row"><dt>Address</dt><dd><?= e(trim(implode(', ', array_filter([(string) ($customer['address'] ?? ''), (string) ($customer['city'] ?? ''), trim((string) ($customer['province'] ?? '') . ' ' . (string) ($customer['postal_code'] ?? ''))]))) ?: '—') ?></dd></div>
                </dl>
                <div class="pt-btn-row" style="margin-top:18px">
                    <a class="pt-btn pt-btn--secondary" href="<?= e(pt_url('requests/create?type=general&subject=' . rawurlencode('Update our company details'))) ?>"><?= pt_icon('pencil-square') ?> Ask us to update these</a>
                </div>
            </div>
        </section>
    </div>
</div>

<style>
@media (max-width: 1023px) { .pt-content .pt-grid[style*="240px"] { grid-template-columns: minmax(0, 1fr) !important; } }
</style>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
