<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Login Page
//
// Standalone page (no header.php / footer.php — no sidebar).
//
// Security:
//   • CSRF token validated on every POST
//   • Password compared with password_verify() (bcrypt)
//   • 5 failed attempts → 15-minute account lockout
//   • Lockout tracked in users.login_attempts + users.locked_until
//   • Redirect target validated against FF_BASE_PATH (no open redirect)
//   • Timing-safe — same error text for bad email vs bad password
// ============================================================

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

_ff_session_start();

// Already logged in → bounce to dashboard
if (current_user_id()) {
    header('Location: ' . base_url('dashboard'));
    exit;
}

// ── CSRF token (generate once per session) ──────────────────
$_csrfToken = generate_csrf_token();

// ── Flash message from previous redirects ──────────────────
// e.g. "Password reset successful — please log in."
$_flash = $_SESSION['auth_flash'] ?? '';
unset($_SESSION['auth_flash']);

// ── Process POST ────────────────────────────────────────────
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($submittedToken)) {
        http_response_code(403);
        $error = 'Invalid request token. Please refresh the page and try again.';

    } else {
        $email    = trim((string) ($_POST['email']    ?? ''));
        $password =       (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);

        if ($email === '') {
            $error = 'Email address is required.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Please enter a valid email address.';
        } elseif ($password === '') {
            $error = 'Password is required.';
        } else {
            $user = db_row(
                "SELECT u.id, u.name, u.email, u.password_hash,
                        u.role_id, r.slug AS role_slug,
                        u.theme_preference, u.status,
                        u.login_attempts, u.locked_until,
                        u.display_font_size, u.display_density
                 FROM users u
                 JOIN user_roles r ON r.id = u.role_id
                 WHERE u.email = ? AND u.deleted_at IS NULL",
                [$email]
            );

            // Determine lockout state before checking password
            $isLocked = false;
            if ($user && $user['locked_until'] !== null) {
                $lockTs = is_string($user['locked_until'])
                    ? strtotime($user['locked_until'])
                    : (int) $user['locked_until'];
                if ($lockTs > time()) {
                    $isLocked    = true;
                    $minutesLeft = (int) ceil(($lockTs - time()) / 60);
                    $error       = "Account locked due to too many failed attempts. "
                                 . "Please try again in {$minutesLeft} minute"
                                 . ($minutesLeft === 1 ? '' : 's') . '.';
                }
            }

            if (!$isLocked) {
                // Use constant-time comparison regardless of user existence
                $passwordHash = $user['password_hash'] ?? '$2y$12$invalid.hash.placeholder.00000000000000000000000000000000';
                $passwordOk   = password_verify($password, $passwordHash);

                $isActive = isset($user['status']) && $user['status'] === 'active';
                if (!$user || !$isActive || !$passwordOk) {
                    // Bad credentials (or inactive account) — same error always
                    if ($user && $isActive && !$passwordOk) {
                        // Real user, wrong password — increment attempt counter
                        $attempts = (int) ($user['login_attempts'] ?? 0) + 1;

                        if ($attempts >= 5) {
                            $lockedUntil = date('Y-m-d H:i:s', time() + 900); // 15 min
                            db_execute(
                                "UPDATE users
                                 SET login_attempts = ?, locked_until = ?
                                 WHERE id = ?",
                                [$attempts, $lockedUntil, $user['id']]
                            );
                            $error = 'Too many failed attempts. Account locked for 15 minutes.';
                        } else {
                            db_execute(
                                "UPDATE users SET login_attempts = ? WHERE id = ?",
                                [$attempts, $user['id']]
                            );
                            // FIX #35: don't leak remaining attempt count — it reveals
                            // that the email address is valid (timing-safe message).
                            $error = 'Invalid email or password.';
                        }

                        db_insert('audit_log', [
                            'user_id'      => $user['id'],
                            'user_name'    => $user['name'],
                            'action'       => 'login',
                            'module'       => 'auth',
                            'entity_type'  => 'user',
                            'entity_id'    => $user['id'],
                            'entity_label' => $user['email'],
                            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                            'notes'        => "Failed login attempt {$attempts}/5" . ($attempts >= 5 ? ' — account locked' : ''),
                        ]);
                    } else {
                        // No real user or inactive — don't leak info
                        $error = 'Invalid email or password.';
                    }

                } else {
                    // ── Successful login ────────────────────────────
                    db_execute(
                        "UPDATE users
                         SET login_attempts = 0, locked_until = NULL,
                             last_login_at = NOW(), last_login_ip = ?
                         WHERE id = ?",
                        [$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $user['id']]
                    );

                    db_insert('audit_log', [
                        'user_id'      => $user['id'],
                        'user_name'    => $user['name'],
                        'action'       => 'login',
                        'module'       => 'auth',
                        'entity_type'  => 'user',
                        'entity_id'    => $user['id'],
                        'entity_label' => $user['email'],
                        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    ]);

                    auth_login($user, $remember);

                    // Redirect to the page they originally tried to reach,
                    // or fall back to dashboard. Validate against FF_BASE_PATH
                    // to prevent open-redirect attacks.
                    $redirect = (string) ($_SESSION['redirect_after_login'] ?? '');
                    unset($_SESSION['redirect_after_login']);

                    if ($redirect !== '' && str_starts_with($redirect, FF_BASE_PATH . '/')) {
                        header('Location: ' . $redirect);
                    } else {
                        header('Location: ' . base_url('dashboard'));
                    }
                    exit;
                }
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
    <meta name="csrf-token" content="<?= e($_csrfToken) ?>">
    <meta name="robots" content="noindex, nofollow">

    <title>Sign In — FleetForge</title>

    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&display=swap">

    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

    <script>
        window.FF_BASE_PATH = <?= json_encode(FF_BASE_PATH) ?>;
        // Apply stored theme preference before first paint (prevents flash)
        (function () {
            try {
                var t = localStorage.getItem('ff-theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();
    </script>

    <style>
        /* Auth-page layout — centred card, no sidebar */
        .auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background-color: var(--bg-body);
        }

        .auth-card {
            width: 100%;
            max-width: 400px;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            padding: 36px 32px 32px;
        }

        .auth-logo {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 28px;
            text-align: center;
        }

        .auth-logo-mark {
            width: 48px;
            height: 48px;
            background: var(--color-primary);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .auth-logo-mark svg {
            width: 28px;
            height: 28px;
            color: #ffffff;
        }

        .auth-logo-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.01em;
        }

        .auth-heading {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .auth-subheading {
            font-size: 0.875rem;
            color: var(--text-tertiary);
            margin-bottom: 24px;
        }

        /* Password reveal toggle */
        .input-password-wrap {
            position: relative;
        }

        .input-password-wrap .form-control {
            padding-right: 44px;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-tertiary);
            background: none;
            border: none;
            padding: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            transition: color var(--transition-fast);
        }

        .password-toggle:hover { color: var(--text-secondary); }
        .password-toggle svg { width: 18px; height: 18px; pointer-events: none; }
        .password-toggle .icon-hide { display: none; }
        .password-toggle.is-visible .icon-show { display: none; }
        .password-toggle.is-visible .icon-hide { display: block; }

        .auth-footer-link {
            text-align: center;
            margin-top: 20px;
            font-size: 0.875rem;
            color: var(--text-tertiary);
        }
    </style>
</head>
<body>

<div class="auth-page">
    <div class="auth-card">

        <!-- Brand mark -->
        <div class="auth-logo">
            <div class="auth-logo-mark" aria-hidden="true">
                <!-- Truck icon (inline fallback if SVG file not yet present) -->
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
                </svg>
            </div>
            <div class="auth-logo-name">FleetForge</div>
        </div>

        <h1 class="auth-heading">Sign in to your account</h1>
        <p class="auth-subheading">Enter your credentials to continue.</p>

        <!-- Flash message (password reset success, session expired, etc.) -->
        <?php if ($_flash !== ''): ?>
            <div class="toast toast-success" role="status" style="position:relative;margin-bottom:16px;animation:none;">
                <span class="toast-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
                    </svg>
                </span>
                <div class="toast-body">
                    <div class="toast-message"><?= e($_flash) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error message -->
        <?php if ($error !== ''): ?>
            <div class="toast toast-danger" role="alert" style="position:relative;margin-bottom:16px;animation:none;">
                <span class="toast-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/>
                    </svg>
                </span>
                <div class="toast-body">
                    <div class="toast-message"><?= e($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Login form -->
        <form method="post"
              action="<?= e(base_url('auth/login')) ?>"
              autocomplete="on"
              novalidate>

            <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">

            <div class="form-group">
                <label class="form-label" for="email">Email address</label>
                <input type="email"
                       id="email"
                       name="email"
                       class="form-control<?= $error !== '' ? ' is-invalid' : '' ?>"
                       value="<?= e($email) ?>"
                       autocomplete="email"
                       autofocus
                       required
                       maxlength="254"
                       inputmode="email"
                       placeholder="you@example.com">
            </div>

            <div class="form-group">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <label class="form-label" for="password" style="margin-bottom:0">Password</label>
                    <a href="<?= e(base_url('auth/forgot-password')) ?>"
                       class="btn-link btn-sm"
                       tabindex="-1">
                        Forgot password?
                    </a>
                </div>
                <div class="input-password-wrap">
                    <input type="password"
                           id="password"
                           name="password"
                           class="form-control<?= $error !== '' ? ' is-invalid' : '' ?>"
                           autocomplete="current-password"
                           required
                           maxlength="255"
                           placeholder="••••••••">
                    <button type="button"
                            class="password-toggle"
                            id="password-toggle"
                            aria-label="Show password"
                            aria-pressed="false"
                            tabindex="-1">
                        <!-- Eye (show) -->
                        <svg class="icon-show" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                        </svg>
                        <!-- Eye-slash (hide) -->
                        <svg class="icon-hide" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-check">
                    <input type="checkbox"
                           name="remember"
                           id="remember"
                           class="form-check-input"
                           value="1"
                           <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                    <span class="form-check-label">Keep me signed in for 30 days</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary w-full btn-lg">
                Sign in
            </button>

        </form>

        <div class="auth-footer-link">
            <small>Internal tool — authorised personnel only.</small>
        </div>

    </div>
</div>

<script>
// Password show/hide toggle
(function () {
    var btn   = document.getElementById('password-toggle');
    var input = document.getElementById('password');
    if (!btn || !input) return;

    btn.addEventListener('click', function () {
        var visible = input.type === 'text';
        input.type  = visible ? 'password' : 'text';
        btn.classList.toggle('is-visible', !visible);
        btn.setAttribute('aria-label',   visible ? 'Show password' : 'Hide password');
        btn.setAttribute('aria-pressed', String(!visible));
    });
})();

// Apply stored theme (already done in <head> — this is a no-op safety net)
(function () {
    try {
        var t = localStorage.getItem('ff-theme');
        if (t === 'light' || t === 'dark') {
            document.documentElement.setAttribute('data-theme', t);
        }
    } catch (e) {}
})();
</script>

</body>
</html>
<?php
unset($_csrfToken, $_flash, $error, $email);
?>
