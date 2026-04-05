<?php
declare(strict_types=1);

/**
 * app/portal/auth/reset_password.php
 *
 * Portal password reset — validates token, sets new password.
 * Token is hashed in DB (sha256). 2-hour expiry.
 */

require_once dirname(__DIR__) . '/includes/auth.php';

if (portal_user()) {
    header('Location: ' . base_url('portal'));
    exit;
}

$_csrfToken = portal_csrf_token();
$_companyName = settings_get('company.name', 'FleetForge');
$error = '';
$success = false;
$tokenValid = false;

$tokenParam = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$emailParam = trim((string) ($_GET['email'] ?? $_POST['email'] ?? ''));

// Validate token exists and is not expired
if ($tokenParam !== '' && $emailParam !== '') {
    $tokenHash = hash('sha256', $tokenParam);
    $user = db_row(
        "SELECT id, name, email, password_reset_token, password_reset_expiry
         FROM portal_users
         WHERE email = ? AND password_reset_token = ? AND status IN ('active','invited')",
        [$emailParam, $tokenHash]
    );

    if ($user && $user['password_reset_expiry'] && strtotime($user['password_reset_expiry']) > time()) {
        $tokenValid = true;
    } else {
        $error = 'This reset link has expired or is invalid. Please request a new one.';
    }
} else {
    $error = 'Invalid reset link. Please request a new password reset.';
}

// Process POST
if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if (!portal_verify_csrf($submittedCsrf)) {
        http_response_code(403);
        $error = 'Invalid request token. Please refresh and try again.';
    } else {
        $newPassword    = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirm'] ?? '');

        if (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            db_update('portal_users', [
                'password_hash'         => $passwordHash,
                'password_reset_token'  => null,
                'password_reset_expiry' => null,
                'login_attempts'        => 0,
                'locked_until'          => null,
                'status'                => 'active', // activate invited users on password set
            ], 'id = ?', [(int) $user['id']]);

            $_SESSION['portal_auth_flash'] = 'Password updated successfully. Please sign in with your new password.';
            header('Location: ' . base_url('portal/auth/login'));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — <?= e($_companyName) ?> Portal</title>
    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap">
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
</head>
<body>
<script>try { var t = localStorage.getItem('ff-theme'); if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark'); } catch(e) {}</script>

<div class="portal-login-page">
    <div class="portal-login-card">

        <div class="portal-login-brand">
            <div class="portal-login-logo">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
            </div>
            <h1 class="portal-login-title">Set New Password</h1>
            <p class="portal-login-subtitle">Choose a strong password for your account</p>
        </div>

        <?php if ($error): ?>
            <div class="portal-login-error">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                <?= e($error) ?>
            </div>
            <?php if (!$tokenValid): ?>
                <p style="text-align:center;margin-top:16px;">
                    <a href="<?= e(base_url('portal/auth/forgot_password')) ?>" class="portal-form-link">Request a new reset link</a>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($tokenValid): ?>
            <form method="POST" action="<?= e(base_url('portal/auth/reset_password')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                <input type="hidden" name="token" value="<?= e($tokenParam) ?>">
                <input type="hidden" name="email" value="<?= e($emailParam) ?>">

                <div class="portal-form-group">
                    <label class="portal-form-label" for="password">New Password</label>
                    <input type="password" id="password" name="password"
                           class="portal-form-input"
                           placeholder="At least 8 characters"
                           minlength="8"
                           autocomplete="new-password"
                           required autofocus>
                </div>

                <div class="portal-form-group">
                    <label class="portal-form-label" for="password_confirm">Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm"
                           class="portal-form-input"
                           placeholder="Re-enter your password"
                           minlength="8"
                           autocomplete="new-password"
                           required>
                </div>

                <button type="submit" class="portal-login-btn" style="margin-bottom:16px;">Set Password</button>
            </form>
        <?php endif; ?>

        <p style="text-align:center;font-size:0.8125rem;color:var(--text-muted);">
            <a href="<?= e(base_url('portal/auth/login')) ?>" class="portal-form-link">Back to sign in</a>
        </p>

    </div>
</div>

</body>
</html>
