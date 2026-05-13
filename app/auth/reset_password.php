<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Reset Password
//
// Validates the token from the email link and, on valid POST,
// updates the user's password and invalidates the token.
//
// Flow:
//   GET  ?token=xxx  → validate token → show new-password form
//   POST ?token=xxx  → validate token (again) → update password
//                    → delete token → redirect to login with flash
//
// Security:
//   • Token validated with hash_equals() against SHA-256 hash
//   • Token re-validated on POST (double-check expiry)
//   • All tokens for the user deleted after successful reset
//     (invalidates any parallel reset links)
//   • Password minimum 10 characters; confirmed with second field
//   • Session regenerated via auth_login() is NOT called here —
//     user must log in fresh after reset (forces credential check)
// ============================================================

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

_ff_session_start();

// Already logged in → bounce
if (current_user_id()) {
    header('Location: ' . base_url('dashboard'));
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$_csrfToken = $_SESSION['csrf_token'];

// ── Helper: look up a valid token row ──────────────────────
// Returns the DB row (with user data) or null if invalid/expired.
function find_valid_reset_token(string $plainToken): ?array
{
    if ($plainToken === '' || strlen($plainToken) !== 64) {
        return null;
    }

    $tokenHash = hash('sha256', $plainToken);

    try {
        $row = db_row(
            "SELECT prt.id AS token_id, prt.user_id, prt.expires_at,
                    u.name, u.email
             FROM password_reset_tokens prt
             JOIN users u ON u.id = prt.user_id
             WHERE prt.token_hash = ?
               AND prt.expires_at > NOW()
               AND u.status = 'active'         -- S-PROD-1A-FIX-4 T34: users has no is_active column
               AND u.deleted_at IS NULL",
            [$tokenHash]
        );
    } catch (Throwable) {
        return null;
    }

    return $row ?: null;
}

// ── Read the token from GET or POST ────────────────────────
$plainToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenRow   = find_valid_reset_token($plainToken);
$tokenValid = $tokenRow !== null;

$error   = '';
$success = false;

// ── Handle POST (actual password update) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $error = 'Invalid request token. Please refresh and try again.';

    } elseif (!$tokenValid) {
        // Token expired or already used — error handled below in the HTML

    } else {
        $password        = (string) ($_POST['password']         ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($password === '') {
            $error = 'New password is required.';
        } elseif (strlen($password) < 10) {
            $error = 'Password must be at least 10 characters.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

                db_transaction(function () use ($tokenRow, $newHash) {
                    // Update the password
                    db_execute(
                        "UPDATE users
                         SET password_hash  = ?,
                             login_attempts = 0,
                             locked_until   = NULL
                         WHERE id = ?",
                        [$newHash, $tokenRow['user_id']]
                    );

                    // Invalidate ALL reset tokens for this user
                    db_execute(
                        "DELETE FROM password_reset_tokens WHERE user_id = ?",
                        [$tokenRow['user_id']]
                    );
                });

                // Set flash and redirect to login
                $_SESSION['auth_flash'] = 'Password updated successfully. Please sign in with your new password.';

                header('Location: ' . base_url('auth/login'));
                exit;

            } catch (Throwable $e) {
                error_log('[ResetPassword] ' . $e->getMessage());
                $error = 'An error occurred while updating your password. Please try again.';
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
    <title>Reset Password — FleetForge</title>
    <link rel="icon" href="<?= base_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <!-- Fonts self-hosted via @font-face in public/assets/css/app.css (S-PROD-3 2026-05-14) -->
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <script>
        try { var t=localStorage.getItem('ff-theme'); if(t==='light'||t==='dark') document.documentElement.setAttribute('data-theme',t); } catch(e){}
    </script>
    <style>
        .auth-page { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px 16px; background-color:var(--bg-body); }
        .auth-card { width:100%; max-width:420px; background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-xl); box-shadow:var(--shadow-lg); padding:36px 32px 32px; }
        .auth-logo { display:flex; flex-direction:column; align-items:center; margin-bottom:28px; }
        .auth-logo-mark { width:48px; height:48px; background:var(--color-primary); border-radius:var(--radius-lg); display:flex; align-items:center; justify-content:center; margin-bottom:12px; }
        .auth-logo-mark svg { width:28px; height:28px; color:#fff; }
        .auth-logo-name { font-size:1.25rem; font-weight:700; color:var(--text-primary); }
        .auth-heading { font-size:1rem; font-weight:600; color:var(--text-primary); margin-bottom:4px; }
        .auth-subheading { font-size:0.875rem; color:var(--text-tertiary); margin-bottom:24px; }
        .auth-back { text-align:center; margin-top:20px; font-size:0.875rem; }
        .input-password-wrap { position:relative; }
        .input-password-wrap .form-control { padding-right:44px; }
        .password-toggle { position:absolute; right:10px; top:50%; transform:translateY(-50%); color:var(--text-tertiary); background:none; border:none; padding:4px; cursor:pointer; display:flex; align-items:center; border-radius:var(--radius-sm); transition:color var(--transition-fast); }
        .password-toggle:hover { color:var(--text-secondary); }
        .password-toggle svg { width:18px; height:18px; pointer-events:none; }
        .password-toggle .icon-hide { display:none; }
        .password-toggle.is-visible .icon-show { display:none; }
        .password-toggle.is-visible .icon-hide { display:block; }
        /* Password strength meter */
        .pw-strength { margin-top:6px; height:4px; border-radius:2px; background:var(--border-color); overflow:hidden; }
        .pw-strength-bar { height:100%; width:0; border-radius:2px; transition:width 300ms ease, background 300ms ease; }
        .pw-hint { font-size:0.75rem; color:var(--text-tertiary); margin-top:4px; }
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

        <?php if (!$tokenValid && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

            <!-- ── Invalid / expired token ── -->
            <div style="text-align:center;padding:8px 0;">
                <div style="width:48px;height:48px;background:var(--color-danger-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-danger)" style="width:26px;height:26px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008z"/>
                    </svg>
                </div>
                <h1 class="auth-heading" style="margin-bottom:8px;">Link expired or invalid</h1>
                <p style="font-size:0.875rem;color:var(--text-secondary);line-height:1.5;">
                    This password reset link has expired or already been used.
                    Reset links are valid for <strong>1 hour</strong>.
                </p>
                <a href="<?= e(base_url('auth/forgot_password')) ?>"
                   class="btn btn-primary btn-md"
                   style="margin-top:20px;display:inline-flex;">
                    Request a new link
                </a>
            </div>

        <?php else: ?>

            <!-- ── New password form ── -->
            <h1 class="auth-heading">Set a new password</h1>
            <p class="auth-subheading">
                Choose a strong password for
                <strong><?= e($tokenRow['email'] ?? '') ?></strong>.
            </p>

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

            <form method="post"
                  action="<?= e(base_url('auth/reset_password')) ?>?token=<?= urlencode($plainToken) ?>"
                  novalidate
                  id="reset-form">

                <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                <input type="hidden" name="token"      value="<?= e($plainToken) ?>">

                <div class="form-group">
                    <label class="form-label" for="password">New password</label>
                    <div class="input-password-wrap">
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-control<?= $error !== '' ? ' is-invalid' : '' ?>"
                               autocomplete="new-password"
                               autofocus
                               required
                               minlength="10"
                               maxlength="255"
                               placeholder="At least 10 characters"
                               aria-describedby="pw-strength-hint">
                        <button type="button" class="password-toggle" id="pw-toggle-1"
                                aria-label="Show password" aria-pressed="false" tabindex="-1">
                            <svg class="icon-show" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                            <svg class="icon-hide" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                    <!-- Strength meter -->
                    <div class="pw-strength" aria-hidden="true">
                        <div class="pw-strength-bar" id="pw-strength-bar"></div>
                    </div>
                    <div class="pw-hint" id="pw-strength-hint" aria-live="polite"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password_confirm">Confirm new password</label>
                    <div class="input-password-wrap">
                        <input type="password"
                               id="password_confirm"
                               name="password_confirm"
                               class="form-control<?= $error !== '' ? ' is-invalid' : '' ?>"
                               autocomplete="new-password"
                               required
                               maxlength="255"
                               placeholder="Repeat your password">
                        <button type="button" class="password-toggle" id="pw-toggle-2"
                                aria-label="Show confirmation" aria-pressed="false" tabindex="-1">
                            <svg class="icon-show" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                            <svg class="icon-hide" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full btn-lg">
                    Set new password
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

<script>
(function () {
    // Password show/hide toggles
    function makeToggle(btnId, inputId) {
        var btn   = document.getElementById(btnId);
        var input = document.getElementById(inputId);
        if (!btn || !input) return;
        btn.addEventListener('click', function () {
            var visible = input.type === 'text';
            input.type  = visible ? 'password' : 'text';
            btn.classList.toggle('is-visible', !visible);
            btn.setAttribute('aria-pressed', String(!visible));
        });
    }
    makeToggle('pw-toggle-1', 'password');
    makeToggle('pw-toggle-2', 'password_confirm');

    // Password strength meter
    var pwInput = document.getElementById('password');
    var bar     = document.getElementById('pw-strength-bar');
    var hint    = document.getElementById('pw-strength-hint');

    if (pwInput && bar && hint) {
        pwInput.addEventListener('input', function () {
            var val    = this.value;
            var len    = val.length;
            var score  = 0;

            if (len >= 10) score++;
            if (len >= 16) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            var levels = [
                { pct: '0%',   color: 'transparent',             label: '' },
                { pct: '25%',  color: 'var(--color-danger)',      label: 'Weak' },
                { pct: '50%',  color: 'var(--color-warning)',     label: 'Fair' },
                { pct: '75%',  color: 'var(--color-info)',        label: 'Good' },
                { pct: '100%', color: 'var(--color-success)',     label: 'Strong' },
            ];

            var lvl = len === 0 ? 0 : Math.max(1, Math.min(4, score));
            bar.style.width      = levels[lvl].pct;
            bar.style.background = levels[lvl].color;
            hint.textContent     = levels[lvl].label;
        });
    }
})();
</script>

</body>
</html>
<?php unset($_csrfToken, $plainToken, $tokenRow, $tokenValid, $error); ?>
