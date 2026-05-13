<?php
declare(strict_types=1);

/**
 * app/portal/auth/forgot_password.php
 *
 * Portal password reset request — sends reset link email.
 * Token stored hashed in portal_users.password_reset_token.
 * 2-hour expiry per spec.
 */

require_once dirname(__DIR__) . '/includes/auth.php';

if (portal_user()) {
    header('Location: ' . base_url('portal'));
    exit;
}

$_csrfToken = portal_csrf_token();
$_companyName = settings_get('company.name', 'FleetForge');
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!portal_verify_csrf($submittedToken)) {
        http_response_code(403);
        $error = 'Invalid request token. Please refresh and try again.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Please enter a valid email address.';
        } else {
            // Always show success — prevents email enumeration
            $success = true;

            $user = db_row(
                "SELECT id, name, email FROM portal_users WHERE email = ? AND status = 'active'",
                [$email]
            );

            if ($user) {
                $token     = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiry    = date('Y-m-d H:i:s', time() + 7200); // 2 hours

                db_update('portal_users', [
                    'password_reset_token'  => $tokenHash,
                    'password_reset_expiry' => $expiry,
                ], 'id = ?', [(int) $user['id']]);

                // Build reset URL
                $resetUrl = base_url('portal/auth/reset_password') . '?token=' . $token . '&email=' . urlencode($user['email']);

                // Log email (dev mode — no SES configured)
                $mailLog = FF_ROOT . '/logs/mail.log';
                $logDir  = dirname($mailLog);
                if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

                $logEntry = sprintf(
                    "[%s] PORTAL PASSWORD RESET\nTo: %s\nName: %s\nReset URL: %s\n---\n",
                    date('Y-m-d H:i:s'),
                    $user['email'],
                    $user['name'],
                    $resetUrl
                );
                @file_put_contents($mailLog, $logEntry, FILE_APPEND | LOCK_EX);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — <?= e($_companyName) ?> Portal</title>
    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <!-- Fonts self-hosted via @font-face in public/assets/css/app.css (S-PROD-3 2026-05-14) -->
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
            <h1 class="portal-login-title">Reset Password</h1>
            <p class="portal-login-subtitle">Enter your email and we'll send you a reset link</p>
        </div>

        <?php if ($success): ?>
            <div class="portal-login-success">
                If an account exists with that email, you'll receive a password reset link shortly. Check your inbox (and spam folder).
            </div>
            <p style="text-align:center;margin-top:20px;">
                <a href="<?= e(base_url('portal/auth/login')) ?>" class="portal-form-link">Back to sign in</a>
            </p>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="portal-login-error">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?= e(base_url('portal/auth/forgot_password')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">

                <div class="portal-form-group">
                    <label class="portal-form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email"
                           class="portal-form-input"
                           placeholder="you@company.com"
                           autocomplete="email"
                           required autofocus>
                </div>

                <button type="submit" class="portal-login-btn" style="margin-bottom:16px;">Send Reset Link</button>
            </form>

            <p style="text-align:center;font-size:0.8125rem;color:var(--text-muted);">
                <a href="<?= e(base_url('portal/auth/login')) ?>" class="portal-form-link">Back to sign in</a>
            </p>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
