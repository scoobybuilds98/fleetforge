<?php
declare(strict_types=1);

/**
 * app/portal/auth/login.php
 *
 * Portal login page — separate from admin login.
 * Standalone page (no portal header/footer — own layout).
 *
 * Security:
 *   - CSRF token on every POST
 *   - bcrypt password verification
 *   - 5 failed attempts → 15-minute lockout
 *   - Timing-safe — same error for bad email vs bad password
 *   - Portal session key: ff_portal_user (never ff_user)
 */

require_once dirname(__DIR__) . '/includes/auth.php';

// Already logged in → bounce to portal dashboard
if (portal_user()) {
    header('Location: ' . base_url('portal'));
    exit;
}

$_csrfToken = portal_csrf_token();
$_flash = $_SESSION['portal_auth_flash'] ?? '';
unset($_SESSION['portal_auth_flash']);

$error = '';
$email = '';
$_companyName = settings_get('company.name', 'FleetForge');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!portal_verify_csrf($submittedToken)) {
        http_response_code(403);
        $error = 'Invalid request token. Please refresh and try again.';
    } else {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);

        if ($email === '') {
            $error = 'Email address is required.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Please enter a valid email address.';
        } elseif ($password === '') {
            $error = 'Password is required.';
        } else {
            // Look up portal user + customer info
            $user = db_row(
                "SELECT pu.*, c.company_name, c.status AS customer_status
                 FROM portal_users pu
                 JOIN customers c ON c.id = pu.customer_id AND c.deleted_at IS NULL
                 WHERE pu.email = ?",
                [$email]
            );

            if (!$user) {
                // Timing-safe: same error for non-existent email
                password_verify($password, '$2y$10$dummyhashfortimingnopurpose00000000000000000000');
                $error = 'Invalid email or password.';
            } elseif ($user['status'] === 'inactive') {
                $error = 'Your account has been deactivated. Please contact support.';
            } elseif ($user['status'] === 'invited') {
                $error = 'Please accept your invitation first. Check your email for the invite link.';
            } elseif (!in_array($user['customer_status'], ['active', 'pending', 'credit_hold'], true)) {
                $error = 'Your company account has been suspended. Please contact support.';
            } elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $remaining = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
                $error = "Account temporarily locked. Try again in {$remaining} minute" . ($remaining > 1 ? 's' : '') . '.';
            } elseif (!$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
                // Increment failed attempts
                $attempts = (int) $user['login_attempts'] + 1;
                $updateData = ['login_attempts' => $attempts];

                // Lock after 5 failed attempts for 15 minutes
                if ($attempts >= 5) {
                    $updateData['locked_until'] = date('Y-m-d H:i:s', time() + 900);
                }
                db_update('portal_users', $updateData, 'id = ?', [(int) $user['id']]);

                $error = 'Invalid email or password.';
            } else {
                // Success — establish portal session
                portal_login($user, $remember);

                // Redirect to stored URL or portal dashboard
                $redirect = $_SESSION['portal_redirect_after_login'] ?? '';
                unset($_SESSION['portal_redirect_after_login']);

                if ($redirect && str_starts_with($redirect, FF_BASE_PATH . '/portal')) {
                    header('Location: ' . $redirect);
                } else {
                    header('Location: ' . base_url('portal'));
                }
                exit;
            }
        }
    }
}

$_theme = 'light';
try {
    $stored = @$_COOKIE['ff-theme'] ?? null;
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e($_csrfToken) ?>">
    <title>Sign In — <?= e($_companyName) ?> Portal</title>
    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <!-- Fonts self-hosted via @font-face in public/assets/css/app.css (S-PROD-3 2026-05-14) -->
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
</head>
<body>
<script>
try { var t = localStorage.getItem('ff-theme'); if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark'); } catch(e) {}
</script>

<div class="portal-login-page">
    <div class="portal-login-card">

        <div class="portal-login-brand">
            <div class="portal-login-logo">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0H21M3.375 14.25V3.75h8.25m0 0h4.875c.621 0 1.125.504 1.125 1.125v4.875m-6-6v6h6"/></svg>
            </div>
            <h1 class="portal-login-title"><?= e($_companyName) ?></h1>
            <p class="portal-login-subtitle">Sign in to your customer portal</p>
        </div>

        <?php if ($_flash): ?>
            <div class="portal-login-success"><?= e($_flash) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="portal-login-error">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(base_url('portal/auth/login')) ?>" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">

            <div class="portal-form-group">
                <label class="portal-form-label" for="email">Email Address</label>
                <input type="email" id="email" name="email"
                       class="portal-form-input"
                       value="<?= e($email) ?>"
                       placeholder="you@company.com"
                       autocomplete="email"
                       required autofocus>
            </div>

            <div class="portal-form-group">
                <label class="portal-form-label" for="password">Password</label>
                <input type="password" id="password" name="password"
                       class="portal-form-input"
                       placeholder="Enter your password"
                       autocomplete="current-password"
                       required>
            </div>

            <div class="portal-form-row">
                <label class="portal-form-check">
                    <input type="checkbox" name="remember" value="1">
                    Remember me
                </label>
                <a href="<?= e(base_url('portal/auth/forgot_password')) ?>" class="portal-form-link">
                    Forgot password?
                </a>
            </div>

            <button type="submit" class="portal-login-btn">Sign In</button>
        </form>

        <p style="text-align:center;margin-top:24px;font-size:0.8125rem;color:var(--text-muted);">
            Looking for the admin panel?
            <a href="<?= e(base_url('auth/login')) ?>" class="portal-form-link">Sign in here</a>
        </p>

    </div>
</div>

</body>
</html>
