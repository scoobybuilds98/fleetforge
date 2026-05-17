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

\FleetForge\Observability\Sentry::init();

// Top-level exception handler — login.php is a standalone page that
// does NOT include header.php, so the global handler installed by
// _ff_session_start() does not run on uncaught throws. Without this,
// a MySQL outage during ANY db call in this file (RateLimiter,
// audit_log inserts, lockout updates, the user lookup) would render
// a blank page (APP_DEBUG=false) or a full stack trace (APP_DEBUG=true).
// Capture to Sentry and serve a plain 503 page instead. (Resolves #82.)
set_exception_handler(static function (\Throwable $e): void {
    \FleetForge\Observability\Sentry::captureException($e);
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo '<!doctype html><html lang="en"><head>'
       . '<meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Sign-in unavailable — FleetForge</title>'
       . '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#262624;color:#fff;margin:0;padding:24px;display:flex;min-height:100vh;align-items:center;justify-content:center}main{max-width:420px;text-align:center}h1{font-size:1.25rem;margin:0 0 8px;font-weight:600}p{color:rgba(235,230,220,.7);font-size:.9375rem;line-height:1.5;margin:0}</style>'
       . '</head><body><main>'
       . '<h1>Sign-in is temporarily unavailable</h1>'
       . '<p>Please try again in a moment. If the problem persists, contact your administrator.</p>'
       . '</main></body></html>';
});

use FleetForge\Security\RateLimiter;

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

    } elseif (!($ipCheck = RateLimiter::check(
        'login:ip:' . RateLimiter::getClientIp(),
        (int) settings_get('security.rate_limit.login_ip_threshold', 20),
        (int) settings_get('security.rate_limit.login_ip_window_minutes', 15),
        (int) settings_get('security.rate_limit.login_ip_block_minutes', 60)
    ))['allowed']) {
        // IP-level rate limit hit
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'unknown',
            'action'       => 'login',
            'module'       => 'auth',
            'entity_type'  => 'user',
            'entity_id'    => null,
            'notes'        => 'Login IP rate limit hit',
            'ip_address'   => RateLimiter::getClientIp(),
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
        http_response_code(429);
        header('Retry-After: ' . $ipCheck['retry_after_seconds']);
        $error = 'Too many sign-in attempts from your network. Please try again later.';

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
            // Wrap user lookup in try/catch — login.php is standalone (no
            // bootstrap.php / header.php), so a MySQL outage here would
            // otherwise produce a blank page (APP_DEBUG=false) or a stack
            // trace (APP_DEBUG=true) without any audit trail. The catch
            // path forwards the real exception to Sentry, records the
            // event in audit_log, and surfaces a generic message that
            // does NOT reveal whether the email exists or leak any SQL
            // state. $dbLookupOk gates the password-verify block below
            // so the 503 message isn't overwritten by the credential
            // message. (Resolves issue #82.)
            $user        = null;
            $dbLookupOk  = false;
            try {
                $user = db_row(
                    "SELECT u.id, u.name, u.email, u.password_hash,
                            u.role_id, r.slug AS role_slug,
                            u.theme_preference, u.status,
                            u.login_attempts, u.locked_until,
                            u.display_font_size, u.display_density,
                            u.mfa_enabled, u.mfa_required
                     FROM users u
                     JOIN user_roles r ON r.id = u.role_id
                     WHERE u.email = ? AND u.deleted_at IS NULL",
                    [$email]
                );
                $dbLookupOk = true;
            } catch (\Throwable $dbErr) {
                \FleetForge\Observability\Sentry::captureException($dbErr);
                try {
                    db_insert('audit_log', [
                        'user_id'      => null,
                        'user_name'    => 'unknown',
                        'action'       => 'login',
                        'module'       => 'auth',
                        'entity_type'  => 'user',
                        'entity_id'    => null,
                        'notes'        => 'DB exception during login lookup: '
                                        . substr($dbErr->getMessage(), 0, 480),
                        'ip_address'   => RateLimiter::getClientIp(),
                        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    ]);
                } catch (\Throwable $logErr) {
                    // If audit_log itself is unreachable (same outage),
                    // the Sentry capture above is the only signal — that
                    // is intentional, do not let logging failures mask
                    // the original exception by re-throwing.
                    error_log('[login] audit_log insert failed in DB-error path: ' . $logErr->getMessage());
                }
                http_response_code(503);
                $error = 'Authentication is temporarily unavailable. Please try again in a moment.';
            }

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

            if (!$isLocked && $dbLookupOk) {
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
                    // ── Password verified — IP forgiven ─────────────
                    RateLimiter::reset('login:ip:' . RateLimiter::getClientIp());

                    // ── MFA intercept (step 4.5) ─────────────────────
                    if ((int) ($user['mfa_enabled'] ?? 0) === 1) {
                        // S-PROD-1A-FIX-4 T14: regenerate before writing ff_mfa_pending to
                        // close session-fixation window (attacker knows pw but not TOTP).
                        // A second regeneration fires in auth_login() after TOTP succeeds.
                        session_regenerate_id(true);
                        // User has MFA — gate login behind challenge
                        $_SESSION['ff_mfa_pending'] = [
                            'user_id'    => $user['id'],
                            'started_at' => time(),
                            'remember'   => $remember,
                        ];
                        // S-PROD-1A-FIX: underscore matches actual filename mfa_challenge.php
                        header('Location: ' . base_url('auth/mfa_challenge'));
                        exit;
                    }

                    if ((int) ($user['mfa_required'] ?? 0) === 1) {
                        // S-PROD-1A-FIX-4 T14: regenerate before writing ff_mfa_must_setup
                        // for same session-fixation defense as the ff_mfa_pending branch.
                        session_regenerate_id(true);
                        // Role requires MFA but user hasn't set it up
                        $_SESSION['ff_mfa_must_setup'] = $user['id'];
                        // S-PROD-1A-FIX: underscore matches actual filename mfa_required.php
                        header('Location: ' . base_url('auth/mfa_required'));
                        exit;
                    }

                    // ── Normal login (MFA not required/enabled) ──────
                    db_execute(
                        "UPDATE users
                         SET login_attempts = 0, locked_until = NULL,
                             last_login_at = NOW(), last_login_ip = ?
                         WHERE id = ?",
                        [RateLimiter::getClientIp(), $user['id']]
                    );

                    db_insert('audit_log', [
                        'user_id'      => $user['id'],
                        'user_name'    => $user['name'],
                        'action'       => 'login',
                        'module'       => 'auth',
                        'entity_type'  => 'user',
                        'entity_id'    => $user['id'],
                        'entity_label' => $user['email'],
                        'ip_address'   => RateLimiter::getClientIp(),
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
<?php
// ── S-DESIGN-SETTINGS-FOOTER-LOGIN — brand-aware login presentation ──
// Read company / brand rows so the page can render the customer's
// own name, tagline, logo and primary color. Every read falls back
// to a safe default so an un-seeded DB still renders a usable form.
$loginLogo    = (string) (settings_get('brand.logo_path')    ?? '');
$loginFavicon = (string) (settings_get('brand.favicon_path') ?? '');
$loginName    = (string) (settings_get('company.name')       ?: 'FleetForge');
$loginTagline = (string) (settings_get('company.tagline')    ?? '');
$loginColor   = (string) (settings_get('brand.primary_color') ?: '#2596be');
$loginHover   = (string) (settings_get('brand.primary_hover') ?: '#1e7ea0');
$loginLight   = (string) (settings_get('brand.primary_light') ?: '#e0f4fb');

// Signed storage URLs for logo/favicon — only when paths exist.
// Resolve the login logo URL with a two-source fallback:
//   1) brand.logo_path settings row → StorageClient signed URL
//      (this is what Settings → Design writes when an admin uploads a logo).
//   2) public/media/login-logo.{svg,png,jpg,jpeg} → asset_url() to the file
//      (drop-file workflow — no DB involvement, useful for environments
//      where the Design tab hasn't been touched yet or for one-off branding).
// First match wins. If neither resolves, the SVG truck placeholder below
// renders instead.
$loginLogoUrl = '';
if ($loginLogo !== '') {
    try {
        $loginLogoUrl = \FleetForge\Storage\StorageClient::url($loginLogo, 3600);
    } catch (\Throwable $e) {
        // Settings pointed at a missing/invalid storage key — fall through
        // to the static-file fallback rather than 500-ing the login page.
    }
}
if ($loginLogoUrl === '') {
    foreach (['svg', 'png', 'jpg', 'jpeg'] as $ext) {
        $candidate = FF_ROOT . '/public/media/login-logo.' . $ext;
        if (is_file($candidate)) {
            $loginLogoUrl = asset_url('media/login-logo.' . $ext);
            break;
        }
    }
}
$loginFaviconUrl = $loginFavicon !== '' ? \FleetForge\Storage\StorageClient::url($loginFavicon, 86400) : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e($_csrfToken) ?>">
    <meta name="robots" content="noindex, nofollow">

    <title>Sign In — <?= e($loginName) ?></title>

    <?php if ($loginFaviconUrl !== ''): ?>
    <link rel="icon" type="image/png" href="<?= e($loginFaviconUrl) ?>">
    <?php else: ?>
    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <?php endif; ?>

    <!-- Fonts self-hosted via @font-face in public/assets/css/app.css (S-PROD-3 2026-05-14) -->

    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

    <!-- Brand color injection — mirrors includes/header.php so the
         login card uses the customer's primary color for focus
         rings, primary buttons and links. -->
    <style id="ff-login-brand-override">
        :root {
            --color-primary:       <?= e($loginColor) ?>;
            --color-primary-hover: <?= e($loginHover) ?>;
            --color-primary-light: <?= e($loginLight) ?>;
        }
    </style>

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
        /* ══════════════════════════════════════════════════════════════
           S-LOGIN-AESTHETIC — Apple-inspired login surface.

           Aesthetic principles applied throughout this stylesheet:
             1. Heavy backdrop blur so the video reads as atmosphere,
                not as competing visual content
             2. Layered shadows + a 1px specular highlight on the card's
                top edge (Apple's "vibrancy material" silhouette)
             3. Generous internal padding so content breathes
             4. Confident typography: tight tracking on headlines,
                muted secondary text, sentence-case throughout
             5. Inputs that feel like soft chips with a brand-color
                focus ring + outer bloom
             6. The primary button has a subtle vertical gradient,
                scale-on-press, and a brand-color glow on hover
             7. Restraint: a single semantic accent (brand color),
                everything else neutral
           ══════════════════════════════════════════════════════════════ */

        /* ── Background video layer ──────────────────────────────────
           The wave video is now heavily blurred (24px) so it functions
           as ambient texture rather than literal imagery. A vertical
           dark gradient sits above it for legibility under the card,
           and a soft radial vignette darkens the corners. */
        .video-bg-wrapper {
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
            background: #0a0b0e; /* fallback if video fails */
        }
        .video-bg {
            position: absolute;
            top: 50%;
            left: 50%;
            min-width: 100%;
            min-height: 100%;
            width: auto;
            height: auto;
            transform: translate(-50%, -50%) scale(1.08);
            object-fit: cover;
            filter: blur(24px) saturate(1.15) brightness(0.55);
        }
        .video-bg-overlay {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse at center, rgba(0,0,0,0.20) 0%, rgba(0,0,0,0.55) 70%, rgba(0,0,0,0.75) 100%),
                linear-gradient(180deg, rgba(10,11,14,0.35) 0%, rgba(10,11,14,0.55) 100%);
        }
        @media (max-width: 767px) {
            .video-bg { filter: blur(16px) saturate(1.15) brightness(0.55); }
        }

        /* ── Page shell ── */
        .auth-page {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
            background-color: transparent;
        }

        /* ── Auth card ── vibrancy material.
           rgba surface allows the blurred video to bleed through at low
           opacity. The 1px white-alpha border + ::before highlight
           together produce the subtle specular edge Apple's translucent
           panels are known for. */
        .auth-card {
            position: relative;
            z-index: 11;
            width: 100%;
            max-width: 440px;
            background: rgba(20, 20, 19, 0.78);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            padding: 44px 44px 36px;
            backdrop-filter: blur(40px) saturate(1.4);
            -webkit-backdrop-filter: blur(40px) saturate(1.4);
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.05) inset,
                0 32px 64px -16px rgba(0, 0, 0, 0.55),
                0 12px 24px -8px rgba(0, 0, 0, 0.40),
                0 0 0 1px rgba(0, 0, 0, 0.10);
            overflow: hidden;
        }
        /* Specular highlight — a hairline of brighter alpha across the
           very top edge gives the surface a sense of physical light. */
        .auth-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.16) 50%, transparent 100%);
            pointer-events: none;
        }
        @media (max-width: 480px) {
            .auth-card { padding: 36px 28px 28px; border-radius: 16px; }
            .auth-card { backdrop-filter: blur(24px) saturate(1.3); -webkit-backdrop-filter: blur(24px) saturate(1.3); }
        }

        /* ── Brand block ── */
        .login-brand           { text-align: center; margin-bottom: 28px; }
        .login-logo-img        { max-height: 88px; max-width: 240px; object-fit: contain; display: block; margin: 0 auto; }
        .login-logo-placeholder{ display: flex; justify-content: center; margin: 0 auto 14px; }
        .login-company-name    {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ffffff;
            margin: 0 0 6px;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .login-company-tagline {
            font-size: 0.875rem;
            color: rgba(235, 230, 220, 0.55);
            margin: 4px 0 0;
            letter-spacing: -0.005em;
        }

        /* ── Headings ── */
        .auth-heading {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ffffff;
            margin: 0 0 4px;
            letter-spacing: -0.02em;
            line-height: 1.2;
            text-align: center;
        }
        .auth-subheading {
            font-size: 0.9375rem;
            color: rgba(235, 230, 220, 0.55);
            margin: 0 0 28px;
            text-align: center;
            letter-spacing: -0.005em;
        }

        /* ── Form fields ── */
        .auth-card .form-group { margin-bottom: 18px; }
        .auth-card .form-label {
            font-size: 0.75rem;
            font-weight: 500;
            letter-spacing: 0.01em;
            color: rgba(235, 230, 220, 0.62);
            margin-bottom: 7px;
            display: block;
        }
        .auth-card .form-control {
            height: 48px;
            border-radius: 10px;
            border: none;
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
            font-size: 0.9375rem;
            letter-spacing: -0.005em;
            padding: 0 14px;
            width: 100%;
            transition: background 180ms ease, box-shadow 180ms ease, transform 180ms ease;
        }
        .auth-card .form-control::placeholder {
            color: rgba(235, 230, 220, 0.32);
        }
        .auth-card .form-control:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        .auth-card .form-control:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.10);
            box-shadow:
                0 0 0 2px var(--color-primary, #2596be),
                0 0 0 6px color-mix(in srgb, var(--color-primary, #2596be) 22%, transparent);
        }
        .auth-card .form-control.is-invalid {
            box-shadow:
                0 0 0 2px var(--color-danger, #ef4444),
                0 0 0 6px color-mix(in srgb, var(--color-danger, #ef4444) 22%, transparent);
        }

        /* ── Password row label + Forgot link ── */
        .auth-pw-label-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 7px;
            gap: 12px;
        }
        .auth-pw-label-row .form-label { margin: 0; }
        .auth-pw-forgot {
            font-size: 0.75rem;
            color: rgba(235, 230, 220, 0.55);
            text-decoration: none;
            transition: color 150ms ease;
        }
        .auth-pw-forgot:hover { color: var(--color-primary, #2596be); }

        /* ── Password reveal toggle ── */
        .input-password-wrap { position: relative; }
        .input-password-wrap .form-control { padding-right: 46px; }
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(235, 230, 220, 0.40);
            background: none;
            border: none;
            padding: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: color 150ms ease, background 150ms ease;
        }
        .password-toggle:hover {
            color: rgba(235, 230, 220, 0.85);
            background: rgba(255, 255, 255, 0.05);
        }
        .password-toggle svg { width: 18px; height: 18px; pointer-events: none; }
        .password-toggle .icon-hide { display: none; }
        .password-toggle.is-visible .icon-show { display: none; }
        .password-toggle.is-visible .icon-hide { display: block; }

        /* ── Remember me — refined Apple-style checkbox ── */
        .auth-card .form-group--check { margin: 4px 0 24px; }
        .auth-card .form-check {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
            margin: 0;
        }
        .auth-card .form-check-input {
            appearance: none;
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.14);
            cursor: pointer;
            position: relative;
            flex-shrink: 0;
            transition: background 150ms ease, border-color 150ms ease, box-shadow 150ms ease;
        }
        .auth-card .form-check-input:hover {
            border-color: rgba(255, 255, 255, 0.28);
        }
        .auth-card .form-check-input:checked {
            background: var(--color-primary, #2596be);
            border-color: var(--color-primary, #2596be);
        }
        .auth-card .form-check-input:checked::after {
            content: "";
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='white' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='3.5,8.5 6.5,11.5 12.5,5'/></svg>");
            background-size: 14px 14px;
            background-position: center;
            background-repeat: no-repeat;
        }
        .auth-card .form-check-input:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary, #2596be) 35%, transparent);
        }
        .auth-card .form-check-label {
            font-size: 0.8125rem;
            color: rgba(235, 230, 220, 0.72);
            letter-spacing: -0.005em;
        }

        /* ── Sign in button ── subtle gradient + brand-color glow */
        .auth-card .btn-signin {
            width: 100%;
            height: 52px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(180deg,
                color-mix(in srgb, var(--color-primary, #2596be) 100%, white 6%) 0%,
                var(--color-primary, #2596be) 100%);
            color: #ffffff;
            font-size: 0.9375rem;
            font-weight: 600;
            letter-spacing: -0.005em;
            cursor: pointer;
            transition: transform 120ms ease, box-shadow 200ms ease, filter 150ms ease;
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.15) inset,
                0 4px 12px -2px color-mix(in srgb, var(--color-primary, #2596be) 35%, transparent),
                0 1px 2px rgba(0, 0, 0, 0.20);
        }
        .auth-card .btn-signin:hover {
            filter: brightness(1.06);
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.18) inset,
                0 6px 18px -2px color-mix(in srgb, var(--color-primary, #2596be) 45%, transparent),
                0 2px 4px rgba(0, 0, 0, 0.22);
        }
        .auth-card .btn-signin:active {
            transform: scale(0.985);
            filter: brightness(0.96);
        }
        .auth-card .btn-signin:focus-visible {
            outline: none;
            box-shadow:
                0 0 0 2px rgba(20, 20, 19, 1),
                0 0 0 4px var(--color-primary, #2596be),
                0 4px 12px -2px color-mix(in srgb, var(--color-primary, #2596be) 35%, transparent);
        }

        /* ── Below-button micro-copy ── */
        .auth-card .auth-footer-link {
            text-align: center;
            margin-top: 18px;
            font-size: 0.75rem;
            color: rgba(235, 230, 220, 0.42);
            letter-spacing: -0.005em;
        }
        .auth-card .auth-footer-link small { font-size: inherit; }

        /* ── Login card footer (legal + copyright) ──
           Lives inside the card, separated by a hairline divider. */
        .login-footer {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            text-align: center;
        }
        .login-footer-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px 18px;
            margin-bottom: 12px;
        }
        .login-footer-links a {
            font-size: 0.75rem;
            color: rgba(235, 230, 220, 0.50);
            text-decoration: none;
            transition: color 150ms ease;
            letter-spacing: -0.005em;
        }
        .login-footer-links a:hover {
            color: rgba(255, 255, 255, 0.90);
        }
        .login-footer-copy {
            font-size: 0.6875rem;
            color: rgba(235, 230, 220, 0.36);
            margin: 0;
            line-height: 1.55;
            letter-spacing: -0.005em;
        }
        .login-footer-copy strong {
            font-weight: 500;
            color: rgba(235, 230, 220, 0.62);
        }

        /* ── Toast positioning override for login (relative, not fixed) ── */
        .auth-card .toast {
            border-radius: 10px;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>

<!--
  MEDIA-1 — Background video layer.
  Sits behind everything via z-index:0; form card above via z-index:10+.
  Muted + playsinline + autoplay are required for modern browser
  auto-play policies. preload="auto" because the file is local and
  small-ish; if file grows, bump to preload="metadata".
  Fallback: if the <video> element can't play the source, the
  wrapper keeps its solid dark background color so the form stays
  readable.
-->
<div class="video-bg-wrapper" aria-hidden="true">
    <video class="video-bg"
           autoplay
           muted
           loop
           playsinline
           preload="auto">
        <source src="<?= asset_url('media/video1.mp4') ?>" type="video/mp4">
    </video>
    <div class="video-bg-overlay"></div>
</div>

<div class="auth-page">
    <div class="auth-card">

        <!-- Brand mark — branches on whether an uploaded logo exists:
             (a) Uploaded logo: show only the <img>. The wordmark logo already
                 carries the company name, so rendering an <h1> with the same
                 text below would be visual redundancy AND inflate vertical
                 space (any transparent padding inside the source PNG becomes
                 a visible gap).
             (b) No logo: show the SVG truck placeholder (which has no name)
                 PLUS an <h1> with the company name so the page still has a
                 clear brand identity.
             Tagline, when configured, renders in both branches because it
             is supplementary information not duplicated by either form. -->
        <div class="login-brand">
            <?php if ($loginLogoUrl !== ''): ?>
                <img src="<?= e($loginLogoUrl) ?>"
                     alt="<?= e($loginName) ?>"
                     class="login-logo-img">
            <?php else: ?>
                <div class="login-logo-placeholder">
                    <svg width="48" height="48" viewBox="0 0 32 32" fill="none"
                         xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect x="2" y="12" width="18" height="11" rx="2" fill="<?= e($loginColor) ?>"/>
                        <path d="M20 17 L20 23 L28 23 L28 19 L25 13 L22 13 L20 17Z" fill="<?= e($loginColor) ?>"/>
                        <path d="M22.5 14.5 L24.5 14.5 L26.5 18 L22.5 18Z" fill="<?= e($loginHover) ?>" opacity="0.7"/>
                        <circle cx="7"  cy="24" r="3"   fill="#1a1a1a" stroke="<?= e($loginColor) ?>" stroke-width="1.5"/>
                        <circle cx="7"  cy="24" r="1.2" fill="<?= e($loginColor) ?>"/>
                        <circle cx="23" cy="24" r="3"   fill="#1a1a1a" stroke="<?= e($loginColor) ?>" stroke-width="1.5"/>
                        <circle cx="23" cy="24" r="1.2" fill="<?= e($loginColor) ?>"/>
                    </svg>
                </div>
                <h1 class="login-company-name"><?= e($loginName) ?></h1>
            <?php endif; ?>
            <?php if ($loginTagline !== ''): ?>
                <p class="login-company-tagline"><?= e($loginTagline) ?></p>
            <?php endif; ?>
        </div>

        <?php
        // S-LOGIN-AESTHETIC: confident Apple-style heading hierarchy.
        // Primary line is a warm "Welcome back" rather than the generic
        // "Sign in to your account"; secondary line names the destination
        // ("Sign in to {Company}") which doubles as gentle brand reinforcement.
        ?>
        <h1 class="auth-heading">Welcome back</h1>
        <p class="auth-subheading">Sign in to <?= e($loginName) ?> to continue</p>

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
                <div class="auth-pw-label-row">
                    <label class="form-label" for="password">Password</label>
                    <a href="<?= e(base_url('auth/forgot_password')) ?>"
                       class="auth-pw-forgot"
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

            <div class="form-group form-group--check">
                <label class="form-check">
                    <input type="checkbox"
                           name="remember"
                           id="remember"
                           class="form-check-input"
                           value="1"
                           <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                    <span class="form-check-label">Stay signed in for 30 days</span>
                </label>
            </div>

            <button type="submit" class="btn-signin">
                Sign in
            </button>

        </form>

        <div class="auth-footer-link">
            <small>Internal tool — authorised personnel only.</small>
        </div>

        <!-- S-LEGAL-FOOTER-COMMERCIAL — public legal links + copyright +
             brand attribution. Mirrors the admin footer's information density
             at smaller scale appropriate to the login card width.
             Renders: /legal/terms, /legal/privacy, /legal/aup, /legal/security -->
        <footer class="login-footer">
            <div class="login-footer-links">
                <a href="<?= e(legal_url('terms')) ?>">Terms of Service</a>
                <a href="<?= e(legal_url('privacy')) ?>">Privacy Policy</a>
                <a href="<?= e(legal_url('aup')) ?>">Acceptable Use</a>
                <a href="<?= e(legal_url('security')) ?>">Security</a>
                <a href="mailto:<?= e(legal_config('company.email_support')) ?>">Support</a>
            </div>
            <p class="login-footer-copy">
                &copy; <?= date('Y') ?> <?= e($loginName) ?>. All rights reserved.
                &nbsp;·&nbsp;
                A software by <strong>Avi Technologies</strong>
            </p>
        </footer>

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
unset($_csrfToken, $_flash, $error, $email,
      $loginLogo, $loginFavicon, $loginName, $loginTagline,
      $loginColor, $loginHover, $loginLight,
      $loginLogoUrl, $loginFaviconUrl);
?>
