<?php
declare(strict_types=1);

/**
 * app/auth/mfa_challenge.php
 *
 * Second factor challenge, shown after password succeeds.
 * The user is NOT yet logged in — $_SESSION['ff_mfa_pending'] holds
 * their user_id. Only on successful MFA is $_SESSION['ff_user'] set.
 *
 * Accepts TOTP code OR a backup code (toggled via ?backup=1 link).
 *
 * Rate limiting:
 *   • mfa:user:{user_id} — 5 failures → forced re-login (D-D default)
 *   • mfa:ip:{ip}        — 10 failures across users → IP blocked 60 min
 *
 * Session: ff_mfa_pending expires after 10 minutes → forced re-login.
 *
 * @session S-PROD-1A
 */

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Auth\MfaService;
use FleetForge\Security\RateLimiter;

_ff_session_start();

// Already fully logged in — bounce
if (current_user_id()) {
    header('Location: ' . base_url('dashboard'));
    exit;
}

// ── Validate MFA pending session ─────────────────────────────────────────────
$pending = $_SESSION['ff_mfa_pending'] ?? null;
if (!$pending || !isset($pending['user_id'], $pending['started_at'])) {
    header('Location: ' . base_url('auth/login'));
    exit;
}

if ((time() - (int) $pending['started_at']) > 600) {
    unset($_SESSION['ff_mfa_pending']);
    $_SESSION['auth_flash'] = 'Your session expired. Please sign in again.';
    header('Location: ' . base_url('auth/login'));
    exit;
}

$pendingUserId = (int) $pending['user_id'];
$ip            = RateLimiter::getClientIp();

// Toggle: ?backup=1 or hidden form field selects backup code mode
$useBackup = !empty($_GET['backup']);

$error      = '';
$_csrfToken = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $useBackup = !empty($_POST['use_backup']);

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf_token($submittedToken)) {
        http_response_code(403);
        $error = 'Invalid request token. Please refresh and try again.';
    } else {

        $submitted = trim((string) ($_POST['code'] ?? ''));

        // ── IP-level rate limit (cross-user) ─────────────────────────────
        $ipCheck = RateLimiter::check(
            'mfa:ip:' . $ip,
            (int) settings_get('security.rate_limit.mfa_ip_threshold', 10),
            (int) settings_get('security.rate_limit.mfa_ip_window_minutes', 15),
            (int) settings_get('security.rate_limit.mfa_ip_block_minutes', 60)
        );

        if (!$ipCheck['allowed']) {
            db_insert('audit_log', [
                'user_id'      => $pendingUserId,
                'user_name'    => 'unknown',
                'action'       => 'login',
                'module'       => 'auth',
                'entity_type'  => 'user',
                'entity_id'    => $pendingUserId,
                'notes'        => 'MFA challenge IP rate limit hit',
                'ip_address'   => $ip,
            ]);
            unset($_SESSION['ff_mfa_pending']);
            $_SESSION['auth_flash'] = 'Too many failed attempts from your network. Please try again later.';
            header('Location: ' . base_url('auth/login'));
            exit;
        }

        // ── Per-user rate limit ───────────────────────────────────────────
        $userCheck = RateLimiter::check(
            'mfa:user:' . $pendingUserId,
            (int) settings_get('security.rate_limit.mfa_user_threshold', 5),
            (int) settings_get('security.rate_limit.mfa_user_window_minutes', 15)
        );

        if (!$userCheck['allowed']) {
            db_insert('audit_log', [
                'user_id'      => $pendingUserId,
                'user_name'    => 'unknown',
                'action'       => 'login',
                'module'       => 'auth',
                'entity_type'  => 'user',
                'entity_id'    => $pendingUserId,
                'notes'        => 'MFA challenge user rate limit exceeded — forced re-login',
                'ip_address'   => $ip,
            ]);
            unset($_SESSION['ff_mfa_pending']);
            $_SESSION['auth_flash'] = 'Too many failed MFA attempts. Please sign in again.';
            header('Location: ' . base_url('auth/login'));
            exit;
        }

        // ── Verify the code ───────────────────────────────────────────────
        $valid  = false;
        $method = 'totp';

        if ($useBackup) {
            $method = 'backup_code';
            $valid  = MfaService::consumeBackupCode($pendingUserId, strtoupper($submitted));
        } else {
            $userRow     = db_row("SELECT mfa_secret FROM users WHERE id = ?", [$pendingUserId]);
            $plainSecret = $userRow ? MfaService::decryptSecret($userRow['mfa_secret']) : null;
            if ($plainSecret !== null) {
                $valid = MfaService::verifyTotpCode($plainSecret, preg_replace('/\D/', '', $submitted));
            }
        }

        if ($valid) {
            // ── Complete login ────────────────────────────────────────────
            RateLimiter::reset('mfa:user:' . $pendingUserId);
            RateLimiter::reset('mfa:ip:' . $ip);

            $user = db_row(
                "SELECT u.*, r.slug AS role_slug
                 FROM users u JOIN user_roles r ON r.id = u.role_id
                 WHERE u.id = ? AND u.deleted_at IS NULL",
                [$pendingUserId]
            );

            if (!$user) {
                unset($_SESSION['ff_mfa_pending']);
                header('Location: ' . base_url('auth/login'));
                exit;
            }

            $remember = !empty($pending['remember']);

            // S-AUTH-FIX (D-E, D-F): stamp mfa_verified_until = NOW() + 30 days
            // ONLY when "Keep me signed in" was checked on the login form.
            // pending['remember'] carries that intent across the password →
            // MFA step (set in app/auth/login.php at $_SESSION['ff_mfa_pending']).
            // Without the checkbox there is no ff_remember cookie, so
            // auth_check_remember_me() never runs for this user — leaving
            // mfa_verified_until untouched is safe and saves a write.
            //
            // The combined UPDATE is atomic (single row, single X-lock per D20).
            if ($remember) {
                db_execute(
                    "UPDATE users
                       SET login_attempts = 0,
                           locked_until = NULL,
                           last_login_at = NOW(),
                           last_login_ip = ?,
                           mfa_verified_until = DATE_ADD(NOW(), INTERVAL 30 DAY)
                     WHERE id = ?",
                    [$ip, $pendingUserId]
                );
            } else {
                db_execute(
                    "UPDATE users SET login_attempts = 0, locked_until = NULL,
                                      last_login_at = NOW(), last_login_ip = ?
                     WHERE id = ?",
                    [$ip, $pendingUserId]
                );
            }

            unset($_SESSION['ff_mfa_pending']);
            auth_login($user, $remember);

            db_insert('audit_log', [
                'user_id'      => $pendingUserId,
                'user_name'    => $user['name'],
                'action'       => 'login',
                'module'       => 'auth',
                'entity_type'  => 'user',
                'entity_id'    => $pendingUserId,
                'entity_label' => $user['email'],
                'notes'        => 'MFA verified (' . $method . ')',
                'ip_address'   => $ip,
                'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);

            $redirect = (string) ($_SESSION['redirect_after_login'] ?? '');
            unset($_SESSION['redirect_after_login']);
            if ($redirect !== '' && str_starts_with($redirect, FF_BASE_PATH . '/')) {
                header('Location: ' . $redirect);
            } else {
                header('Location: ' . base_url('dashboard'));
            }
            exit;

        } else {
            db_insert('audit_log', [
                'user_id'      => $pendingUserId,
                'user_name'    => 'unknown',
                'action'       => 'login',
                'module'       => 'auth',
                'entity_type'  => 'user',
                'entity_id'    => $pendingUserId,
                'notes'        => 'MFA failed — invalid ' . ($useBackup ? 'backup code' : 'TOTP code'),
                'ip_address'   => $ip,
                'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);

            $error = $useBackup
                ? 'Invalid backup code. Check your code and try again.'
                : 'Invalid code. Check your authenticator app and try again.';
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
    <meta name="csrf-token" content="<?= e($_csrfToken) ?>">
    <title>Two-Factor Authentication — FleetForge</title>
    <?= ff_favicon_tags() ?>
    <!-- Fonts self-hosted via @font-face in public/assets/css/app.css (S-PROD-3 2026-05-14) -->
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <script>try{var t=localStorage.getItem('ff-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <style>
        .auth-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;background:var(--bg-body);}
        .auth-card{width:100%;max-width:400px;background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);padding:36px 32px 32px;}
        .auth-logo{display:flex;flex-direction:column;align-items:center;margin-bottom:28px;}
        .auth-logo-mark{width:48px;height:48px;background:var(--color-primary);border-radius:var(--radius-lg);display:flex;align-items:center;justify-content:center;margin-bottom:12px;}
        .auth-logo-mark svg{width:28px;height:28px;color:#fff;}
        .auth-logo-name{font-size:1.25rem;font-weight:700;color:var(--text-primary);}
        .auth-heading{font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;}
        .auth-subheading{font-size:0.875rem;color:var(--text-tertiary);margin-bottom:24px;}
        .code-input{font-size:1.5rem;letter-spacing:0.25em;text-align:center;font-variant-numeric:tabular-nums;}
        .switch-link{display:block;text-align:center;margin-top:16px;font-size:0.875rem;color:var(--text-tertiary);}
    </style>
</head>
<body>
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="auth-logo-mark" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z"/>
                </svg>
            </div>
            <div class="auth-logo-name">FleetForge</div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="toast toast-danger" role="alert" style="position:relative;margin-bottom:16px;animation:none;">
                <div class="toast-body"><div class="toast-message"><?= e($error) ?></div></div>
            </div>
        <?php endif; ?>

        <?php if (!$useBackup): ?>
        <!-- ── TOTP mode ─────────────────────────────────────────────────────── -->
        <h1 class="auth-heading">Two-factor authentication</h1>
        <p class="auth-subheading">Enter the 6-digit code from your authenticator app.</p>

        <form method="post" action="<?= e(base_url('auth/mfa_challenge')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
            <div class="form-group">
                <label class="form-label" for="code">Authenticator code</label>
                <input type="text" id="code" name="code"
                       class="form-control code-input"
                       inputmode="numeric"
                       autocomplete="one-time-code"
                       maxlength="6" pattern="\d{6}" autofocus required
                       placeholder="000000">
            </div>
            <button type="submit" class="btn btn-primary w-full btn-lg">Verify</button>
        </form>
        <a href="<?= e(base_url('auth/mfa_challenge')) ?>?backup=1" class="switch-link">
            Use a backup code instead
        </a>

        <?php else: ?>
        <!-- ── Backup code mode ──────────────────────────────────────────────── -->
        <h1 class="auth-heading">Use a backup code</h1>
        <p class="auth-subheading">Enter one of the 8-character backup codes you saved when setting up MFA.</p>

        <form method="post" action="<?= e(base_url('auth/mfa_challenge')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
            <input type="hidden" name="use_backup" value="1">
            <div class="form-group">
                <label class="form-label" for="code">Backup code</label>
                <input type="text" id="code" name="code"
                       class="form-control code-input"
                       autocomplete="off"
                       maxlength="8" autofocus required
                       style="letter-spacing:0.15em;"
                       placeholder="XXXXXXXX">
            </div>
            <button type="submit" class="btn btn-primary w-full btn-lg">Verify backup code</button>
        </form>
        <a href="<?= e(base_url('auth/mfa_challenge')) ?>" class="switch-link">
            Use authenticator app instead
        </a>

        <?php endif; ?>

        <div style="text-align:center;margin-top:20px;font-size:0.875rem;">
            <a href="<?= e(base_url('auth/login')) ?>" class="btn-link btn-sm">&larr; Back to sign in</a>
        </div>

    </div>
</div>
<script>
(function () {
    var input = document.getElementById('code');
    if (!input || input.maxLength !== 6) return;
    input.addEventListener('input', function () {
        if (/^\d{6}$/.test(this.value)) this.closest('form').submit();
    });
})();
</script>
</body>
</html>
<?php unset($_csrfToken, $error, $useBackup, $pending, $pendingUserId, $ip); ?>
