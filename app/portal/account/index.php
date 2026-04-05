<?php
declare(strict_types=1);

/**
 * app/portal/account/index.php
 *
 * Portal account settings — profile, password change, payment details.
 * Trap 8: all queries filter by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid  = portal_customer_id();
$puid = portal_user_id();

$user = db_row("SELECT * FROM portal_users WHERE id = ?", [$puid]);
$customer = db_row(
    "SELECT company_name, payment_terms, outstanding_balance, currency
     FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$cid]
);

$_csrfToken = portal_csrf_token();
$success = '';
$error   = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $action = clean_string($_POST['action'] ?? null);

    if (!portal_verify_csrf($submittedCsrf)) {
        $error = 'Invalid request token. Please refresh and try again.';
    } elseif ($action === 'update_profile') {
        $name = clean_string($_POST['name'] ?? null);
        if (!$name) {
            $error = 'Name is required.';
        } else {
            db_update('portal_users', ['name' => $name], 'id = ?', [$puid]);
            // Update session
            $_SESSION['ff_portal_user']['name'] = $name;
            $user['name'] = $name;
            $success = 'Profile updated successfully.';
        }
    } elseif ($action === 'change_password') {
        $currentPw = (string) ($_POST['current_password'] ?? '');
        $newPw     = (string) ($_POST['new_password'] ?? '');
        $confirmPw = (string) ($_POST['confirm_password'] ?? '');

        if (!$user['password_hash'] || !password_verify($currentPw, $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($newPw) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($newPw !== $confirmPw) {
            $error = 'New passwords do not match.';
        } else {
            db_update('portal_users', [
                'password_hash' => password_hash($newPw, PASSWORD_DEFAULT),
            ], 'id = ?', [$puid]);
            $success = 'Password changed successfully.';
        }
    }
}

$pageTitle = 'Account';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div x-data="{ tab: 'profile' }" x-cloak>

    <!-- Tabs -->
    <div class="portal-tabs" style="margin-bottom:20px;">
        <button class="portal-tab-btn" :class="{ 'is-active': tab === 'profile' }" @click="tab = 'profile'">Profile</button>
        <button class="portal-tab-btn" :class="{ 'is-active': tab === 'password' }" @click="tab = 'password'">Password</button>
        <?php if (portal_is_primary()): ?>
            <button class="portal-tab-btn" :class="{ 'is-active': tab === 'users' }" @click="tab = 'users'">
                Sub-Users
            </button>
        <?php endif; ?>
        <button class="portal-tab-btn" :class="{ 'is-active': tab === 'payment' }" @click="tab = 'payment'">Payment Details</button>
    </div>

    <?php if ($success): ?>
        <div class="portal-login-success" style="margin-bottom:16px;"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="portal-login-error" style="margin-bottom:16px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Profile Tab -->
    <div x-show="tab === 'profile'" style="max-width:520px;">
        <div class="portal-section">
            <div class="portal-section-header">
                <h2 class="portal-section-title">Profile Information</h2>
            </div>
            <div class="portal-section-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="portal-form-group">
                        <label class="portal-form-label" for="name">Display Name</label>
                        <input type="text" id="name" name="name"
                               class="portal-form-input"
                               value="<?= e($user['name'] ?? '') ?>"
                               required>
                    </div>

                    <div class="portal-form-group">
                        <label class="portal-form-label">Email Address</label>
                        <input type="email" class="portal-form-input" value="<?= e($user['email'] ?? '') ?>" disabled style="opacity:0.6;">
                        <p style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">Contact support to change your email address.</p>
                    </div>

                    <div class="portal-form-group">
                        <label class="portal-form-label">Company</label>
                        <input type="text" class="portal-form-input" value="<?= e($customer['company_name'] ?? '') ?>" disabled style="opacity:0.6;">
                    </div>

                    <div class="portal-form-group">
                        <label class="portal-form-label">Account Type</label>
                        <input type="text" class="portal-form-input"
                               value="<?= portal_is_primary() ? 'Primary Account Holder' : 'Sub-user' ?>"
                               disabled style="opacity:0.6;">
                    </div>

                    <button type="submit" class="btn btn-primary btn-md" style="margin-top:8px;">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Password Tab -->
    <div x-show="tab === 'password'" style="max-width:520px;">
        <div class="portal-section">
            <div class="portal-section-header">
                <h2 class="portal-section-title">Change Password</h2>
            </div>
            <div class="portal-section-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="portal-form-group">
                        <label class="portal-form-label" for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password"
                               class="portal-form-input"
                               autocomplete="current-password" required>
                    </div>

                    <div class="portal-form-group">
                        <label class="portal-form-label" for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password"
                               class="portal-form-input"
                               placeholder="At least 8 characters"
                               minlength="8"
                               autocomplete="new-password" required>
                    </div>

                    <div class="portal-form-group">
                        <label class="portal-form-label" for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               class="portal-form-input"
                               minlength="8"
                               autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-md" style="margin-top:8px;">Change Password</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Sub-Users Tab -->
    <?php if (portal_is_primary()): ?>
    <div x-show="tab === 'users'">
        <a href="<?= e(base_url('portal/account/users')) ?>" class="btn btn-primary btn-sm" style="margin-bottom:16px;">Manage Sub-Users</a>

        <?php
        $subUsers = db_select(
            "SELECT id, name, email, status, is_primary, last_login_at, created_at
             FROM portal_users WHERE customer_id = ?
             ORDER BY is_primary DESC, name ASC",
            [$cid]
        );
        ?>
        <div class="portal-section">
            <div class="portal-section-body--flush">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subUsers as $su): ?>
                        <tr>
                            <td style="font-weight:600;"><?= e($su['name']) ?></td>
                            <td><?= e($su['email']) ?></td>
                            <td>
                                <span class="badge <?= $su['status'] === 'active' ? 'badge-success' : ($su['status'] === 'invited' ? 'badge-info' : 'badge-neutral') ?>">
                                    <?= e(ucfirst($su['status'])) ?>
                                </span>
                            </td>
                            <td class="font-mono"><?= $su['last_login_at'] ? e(format_datetime($su['last_login_at'])) : 'Never' ?></td>
                            <td><?= $su['is_primary'] ? '<span class="badge badge-info">Primary</span>' : 'Sub-user' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payment Details Tab -->
    <div x-show="tab === 'payment'" style="max-width:520px;">
        <div class="portal-info-card">
            <div class="portal-info-card-header">Payment Information</div>
            <ul class="portal-info-list">
                <li>
                    <span class="portal-info-label">Payment Terms</span>
                    <span class="portal-info-value"><?= e(ucfirst(str_replace('_', ' ', $customer['payment_terms'] ?? 'net_30'))) ?></span>
                </li>
                <li>
                    <span class="portal-info-label">Outstanding Balance</span>
                    <span class="portal-info-value font-mono" style="font-weight:700;<?= bccomp($customer['outstanding_balance'] ?? '0', '0', 2) > 0 ? 'color:var(--color-danger)' : '' ?>">
                        <?= e(format_currency($customer['outstanding_balance'] ?? '0.00')) ?>
                    </span>
                </li>
                <li>
                    <span class="portal-info-label">Currency</span>
                    <span class="portal-info-value"><?= e($customer['currency'] ?? 'CAD') ?></span>
                </li>
            </ul>
        </div>
        <p style="margin-top:12px;font-size:0.8125rem;color:var(--text-muted);">
            <a href="<?= e(base_url('portal/invoices')) ?>" class="portal-form-link">View all invoices</a>
        </p>
    </div>

</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
