<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Forgot Password
//
// Accepts an email address and sends a time-limited reset link
// if the address belongs to an active account.
//
// Security:
//   • CSRF token on form
//   • Same success message whether email exists or not
//     (prevents user enumeration)
//   • Existing tokens for the user are deleted before creating
//     a new one (one valid token at a time)
//   • Token stored as SHA-256 hash; plain token travels only in
//     the email link (never in the DB or logs)
//   • Token expires in 1 hour
// ============================================================

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Notifications\Mailer;
use FleetForge\Security\RateLimiter;

_ff_session_start();

// Already logged in — send them home
if (current_user_id()) {
    header('Location: ' . base_url('dashboard'));
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$_csrfToken = $_SESSION['csrf_token'];

$submitted = false;    // true once the form has been POSTed (show success state)
$error     = '';
$email     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken)) {
        $error = 'Invalid request token. Please refresh the page and try again.';

    } elseif (!($fpIpCheck = RateLimiter::check(
        'forgot_password:ip:' . RateLimiter::getClientIp(),
        (int) settings_get('security.rate_limit.forgot_password_ip_threshold', 5),
        (int) settings_get('security.rate_limit.forgot_password_ip_window_minutes', 60),
        (int) settings_get('security.rate_limit.forgot_password_ip_block_minutes', 60)
    ))['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . $fpIpCheck['retry_after_seconds']);
        $error = 'Too many requests. Please try again later.';

    } else {
        $email = trim((string) ($_POST['email'] ?? ''));

        if ($email === '') {
            $error = 'Email address is required.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Please enter a valid email address.';
        } else {
            // Look up the user — but never reveal whether it exists
            try {
                // S-PROD-1A-FIX T18: was is_active=1 (column not found); schema uses status enum
                $user = db_row(
                    "SELECT id, name, email
                     FROM users
                     WHERE email = ? AND status = 'active' AND deleted_at IS NULL",
                    [$email]
                );

                if ($user) {
                    // Delete any existing reset tokens for this user
                    db_execute(
                        "DELETE FROM password_reset_tokens WHERE user_id = ?",
                        [$user['id']]
                    );

                    // Generate token — plain version goes into the email link only
                    $plainToken = bin2hex(random_bytes(32));  // 64 hex chars
                    $tokenHash  = hash('sha256', $plainToken);
                    $expiresAt  = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                    db_execute(
                        "INSERT INTO password_reset_tokens
                             (user_id, token_hash, expires_at, created_at)
                         VALUES (?, ?, ?, NOW())",
                        [$user['id'], $tokenHash, $expiresAt]
                    );

                    $resetLink = base_url('auth/reset_password') . '?token=' . urlencode($plainToken);
                    $appName   = settings_get('company.name', 'FleetForge');

                    $htmlBody =
                        "<p>Hi " . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . ",</p>" .
                        "<p>We received a request to reset the password for your {$appName} account.</p>" .
                        "<p><a href=\"" . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . "\"" .
                        " style=\"background:#2563eb;color:#fff;padding:10px 20px;" .
                        "border-radius:6px;text-decoration:none;display:inline-block;" .
                        "font-weight:600;\">Reset my password</a></p>" .
                        "<p>This link expires in <strong>1 hour</strong>.</p>" .
                        "<p>If you did not request a password reset, you can safely ignore this email.</p>" .
                        "<p>— The {$appName} Team</p>";

                    $textBody =
                        "Hi {$user['name']},\n\n" .
                        "Reset your {$appName} password by visiting this link:\n{$resetLink}\n\n" .
                        "This link expires in 1 hour.\n\n" .
                        "If you did not request this, ignore this email.";

                    Mailer::send(
                        toEmail:  $user['email'],
                        toName:   $user['name'],
                        subject:  "Reset your {$appName} password",
                        htmlBody: $htmlBody,
                        textBody: $textBody,
                    );
                }

                // Always show success — don't reveal whether email exists
                $submitted = true;

            } catch (Throwable $e) {
                error_log('[ForgotPassword] ' . $e->getMessage());
                $error = 'An error occurred. Please try again in a moment.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Forgot Password — FleetForge</title>
    <?= ff_favicon_tags() ?>
    <!-- Fonts self-hosted via @font-face in public/assets/css/app.css (S-PROD-3 2026-05-14) -->
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <script>
        try { var t=localStorage.getItem('ff-theme'); if(t==='light'||t==='dark') document.documentElement.setAttribute('data-theme',t); } catch(e){}
    </script>
    <style>
        /* S-LUX-4: restrained dark stage — subtle radial brand glow over --bg-body. */
        .auth-page { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px 16px;
            background:radial-gradient(600px circle at 50% 22%, color-mix(in srgb, var(--color-primary) 6%, transparent), transparent 70%), var(--bg-body); }
        /* S-LUX-4: Atelier card recipe — radius-2xl + sheen + shadow-xl + entrance. */
        .auth-card { width:100%; max-width:400px; background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-2xl); box-shadow:var(--card-sheen),var(--shadow-xl); padding:36px 32px 32px; animation:auth-card-in 280ms cubic-bezier(0.25,1,0.5,1) both; }
        @keyframes auth-card-in { from{opacity:0;transform:translateY(8px);} to{opacity:1;transform:translateY(0);} }
        @media (prefers-reduced-motion:reduce){ .auth-card{animation:none;} }
        .auth-logo { display:flex; flex-direction:column; align-items:center; margin-bottom:28px; }
        .auth-logo-mark { width:48px; height:48px; background:var(--color-primary); border-radius:var(--radius-lg); display:flex; align-items:center; justify-content:center; margin-bottom:12px; }
        .auth-logo-mark svg { width:28px; height:28px; color:#fff; }
        .auth-logo-name { font-size:1.25rem; font-weight:700; letter-spacing:var(--tracking-tight); color:var(--text-primary); }
        .auth-heading { font-size:1rem; font-weight:600; color:var(--text-primary); margin-bottom:4px; }
        .auth-subheading { font-size:0.875rem; color:var(--text-tertiary); margin-bottom:24px; }
        .auth-back { text-align:center; margin-top:20px; font-size:0.875rem; }
    </style>
</head>
<body>
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="auth-logo-mark" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
                </svg>
            </div>
            <div class="auth-logo-name">FleetForge</div>
        </div>

        <?php if ($submitted): ?>

            <!-- ── Success state ── -->
            <div style="text-align:center;padding:8px 0;">
                <div style="width:48px;height:48px;background:var(--color-success-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-success)" style="width:26px;height:26px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
                    </svg>
                </div>
                <h1 class="auth-heading" style="margin-bottom:8px;">Check your email</h1>
                <p style="font-size:0.875rem;color:var(--text-secondary);line-height:1.5;">
                    If <strong><?= e($email) ?></strong> is associated with an active account,
                    a reset link has been sent. It expires in 1&nbsp;hour.
                </p>
                <p style="font-size:0.8125rem;color:var(--text-tertiary);margin-top:12px;">
                    Didn't receive it? Check your spam folder, or
                    <a href="<?= e(base_url('auth/forgot_password')) ?>">try again</a>.
                </p>
            </div>

        <?php else: ?>

            <!-- ── Request form ── -->
            <h1 class="auth-heading">Reset your password</h1>
            <p class="auth-subheading">Enter your email and we'll send you a reset link.</p>

            <?php if ($error !== ''): ?>
                <div class="toast toast-danger" role="alert" style="position:relative;margin-bottom:16px;animation:none;">
                    <span class="toast-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                    </span>
                    <div class="toast-body"><div class="toast-message"><?= e($error) ?></div></div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(base_url('auth/forgot_password')) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">

                <div class="form-group">
                    <label class="form-label" for="email">Email address</label>
                    <input type="email"
                           id="email"
                           name="email"
                           class="form-control"
                           value="<?= e($email) ?>"
                           autocomplete="email"
                           autofocus
                           required
                           maxlength="254"
                           inputmode="email"
                           placeholder="you@example.com">
                </div>

                <button type="submit" class="btn btn-primary w-full btn-lg">
                    Send reset link
                </button>
            </form>

        <?php endif; ?>

        <div class="auth-back">
            <a href="<?= e(base_url('auth/login')) ?>" class="btn-link btn-sm">
                &larr; Back to sign in
            </a>
        </div>

    </div>
</div>
</body>
</html>
<?php unset($_csrfToken, $submitted, $error, $email); ?>
