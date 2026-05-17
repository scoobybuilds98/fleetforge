<?php
declare(strict_types=1);

/**
 * app/portal/auth/login.php
 *
 * Customer portal login page — standalone surface (no portal header/footer).
 *
 * Security (UNCHANGED — only the visual layer was redesigned in
 * S-PORTAL-LOGIN-AESTHETIC):
 *   - CSRF token on every POST
 *   - bcrypt password verification
 *   - 5 failed attempts → 15-minute lockout
 *   - Timing-safe — same error for bad email vs bad password
 *   - Portal session key: ff_portal_user (never ff_user)
 *
 * Visual design mirrors app/auth/login.php (admin) — wave-video
 * background, vibrancy-material card, soft Apple-style focus rings,
 * brand-aware logo block with the same 3-source resolution chain.
 * The "Looking for the admin panel?" cross-link mirrors the admin
 * login's "Are you a customer?" call-out.
 *
 * Session: S-PORTAL-LOGIN-AESTHETIC (2026-05-17)
 */

require_once dirname(__DIR__) . '/includes/auth.php';

use FleetForge\Storage\StorageClient;

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

// ── Brand resolution (3-source chain — mirrors app/auth/login.php) ──
//   1) brand.logo_path settings row → StorageClient signed URL
//   2) public/media/login-logo.{svg,png,jpg,jpeg} → asset_url() to the file
//   3) Empty → render the inline SVG truck placeholder below
$loginLogo    = (string) (settings_get('brand.logo_path') ?? '');
$loginTagline = (string) (settings_get('company.tagline') ?? '');
$loginColor   = (string) (settings_get('brand.primary_color') ?: '#2596be');
$loginHover   = (string) (settings_get('brand.primary_hover') ?: '#1e7ea0');
$loginLight   = (string) (settings_get('brand.primary_light') ?: '#e0f4fb');
$loginFavicon = (string) (settings_get('brand.favicon_path') ?? '');

$loginLogoUrl = '';
if ($loginLogo !== '') {
    try { $loginLogoUrl = StorageClient::url($loginLogo, 3600); }
    catch (\Throwable) { /* fall through */ }
}
if ($loginLogoUrl === '') {
    foreach (['svg', 'png', 'jpg', 'jpeg'] as $_ext) {
        $_candidate = FF_ROOT . '/public/media/login-logo.' . $_ext;
        if (is_file($_candidate)) {
            $loginLogoUrl = asset_url('media/login-logo.' . $_ext);
            break;
        }
    }
}
$loginFaviconUrl = $loginFavicon !== '' ? StorageClient::url($loginFavicon, 86400) : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e($_csrfToken) ?>">
    <meta name="robots" content="noindex, nofollow">

    <title>Sign In — <?= e($_companyName) ?> Portal</title>

    <?php if ($loginFaviconUrl !== ''): ?>
    <link rel="icon" type="image/png" href="<?= e($loginFaviconUrl) ?>">
    <?php else: ?>
    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <?php endif; ?>

    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

    <!-- Brand color injection — same pattern as admin login. -->
    <style id="ff-portal-login-brand-override">
        :root {
            --color-primary:       <?= e($loginColor) ?>;
            --color-primary-hover: <?= e($loginHover) ?>;
            --color-primary-light: <?= e($loginLight) ?>;
        }
    </style>

    <style>
        /* ══════════════════════════════════════════════════════════════
           S-PORTAL-LOGIN-AESTHETIC — Apple-inspired portal login.

           Mirrors app/auth/login.php (admin) so customers signing in
           see a brand-consistent surface. The only meaningful difference
           is the cross-link target ("Looking for the admin panel?")
           and the portal-specific session/auth code above.

           All selectors are written INLINE so they only affect this
           page — the existing .portal-form-* classes used by other
           portal pages (forgot_password, reset_password, requests,
           account) keep their global app.css definitions untouched.
           ══════════════════════════════════════════════════════════════ */

        /* ── Background video ── ambient atmosphere, not literal imagery */
        .video-bg-wrapper {
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
            background: #0a0b0e;
        }
        .video-bg {
            position: absolute;
            top: 50%;
            left: 50%;
            min-width: 100%;
            min-height: 100%;
            width: auto;
            height: auto;
            transform: translate(-50%, -50%) scale(1.06);
            object-fit: cover;
            filter: blur(10px) saturate(1.25) brightness(0.85);
        }
        .video-bg-overlay {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse at center, rgba(0,0,0,0.08) 0%, rgba(0,0,0,0.28) 70%, rgba(0,0,0,0.45) 100%),
                linear-gradient(180deg, rgba(10,11,14,0.10) 0%, rgba(10,11,14,0.22) 100%);
        }
        @media (max-width: 767px) {
            .video-bg { filter: blur(6px) saturate(1.25) brightness(0.85); }
        }

        /* ── Page shell + vibrancy card ── */
        .portal-login-page {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
            background: transparent;
        }
        .portal-login-card {
            position: relative;
            z-index: 11;
            width: 100%;
            max-width: 440px;
            background: rgba(20, 20, 19, 0.78);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            padding: 32px 44px 36px;
            backdrop-filter: blur(40px) saturate(1.4);
            -webkit-backdrop-filter: blur(40px) saturate(1.4);
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.05) inset,
                0 32px 64px -16px rgba(0, 0, 0, 0.55),
                0 12px 24px -8px rgba(0, 0, 0, 0.40),
                0 0 0 1px rgba(0, 0, 0, 0.10);
            overflow: hidden;
        }
        .portal-login-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.16) 50%, transparent 100%);
            pointer-events: none;
        }
        @media (max-width: 480px) {
            .portal-login-card {
                padding: 28px 22px 24px;
                border-radius: 16px;
                backdrop-filter: blur(24px) saturate(1.3);
                -webkit-backdrop-filter: blur(24px) saturate(1.3);
            }
            /* S-MOBILE-CHROME-CLEANUP: tighter mobile layout. Mirrors
               app/auth/login.php — less padding, less brand-block gap,
               more aggressive logo negative top margin. */
            .portal-login-brand { margin-bottom: 24px; }
            .portal-login-logo-img { margin-top: -22px; max-height: 88px; max-width: 180px; }
        }

        /* ── Brand block ──
           Asymmetric negative margins compensate for the transparent
           padding inside the source PNG: top padding > bottom padding
           in the operator's current logo, so we pull the image UP
           harder (-22px) than we pull the following content UP (-4px).
           The 14px brand-block margin-bottom then guarantees the
           heading never overlaps the visible logo. Mirrors admin
           login. Long-term clean fix is a tightly-cropped PNG. */
        .portal-login-brand   { text-align: center; margin-bottom: 40px; }
        .portal-login-logo    { display: flex; justify-content: center; margin: 0 auto; }
        .portal-login-logo-img {
            max-height: 100px;
            max-width: 200px;
            object-fit: contain;
            display: block;
            margin: -14px auto -4px;
        }
        .portal-login-logo-placeholder { display: flex; justify-content: center; margin: 0 auto 14px; }
        .portal-login-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ffffff;
            margin: 0 0 6px;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .portal-login-tagline {
            font-size: 0.875rem;
            color: rgba(235, 230, 220, 0.55);
            margin: 4px 0 0;
            letter-spacing: -0.005em;
        }

        /* ── Headings ── */
        .portal-auth-heading {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ffffff;
            margin: 0 0 4px;
            letter-spacing: -0.02em;
            line-height: 1.2;
            text-align: center;
        }
        .portal-auth-subheading {
            font-size: 0.875rem;
            color: rgba(235, 230, 220, 0.55);
            margin: 0 0 28px;
            text-align: center;
            letter-spacing: -0.005em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ── Form fields (inline overrides scoped to .portal-login-card) ── */
        .portal-login-card .portal-form-group { margin-bottom: 18px; }
        .portal-login-card .portal-form-label {
            font-size: 0.75rem;
            font-weight: 500;
            letter-spacing: 0.01em;
            color: rgba(235, 230, 220, 0.62);
            margin-bottom: 7px;
            display: block;
            text-transform: none;
        }
        .portal-login-card .portal-form-input {
            height: 48px;
            border-radius: 10px;
            border: none;
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
            font-size: 0.9375rem;
            letter-spacing: -0.005em;
            padding: 0 14px;
            width: 100%;
            transition: background 180ms ease, box-shadow 180ms ease;
        }
        .portal-login-card .portal-form-input::placeholder {
            color: rgba(235, 230, 220, 0.32);
        }
        .portal-login-card .portal-form-input:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        .portal-login-card .portal-form-input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.10);
            box-shadow:
                0 0 0 2px var(--color-primary, #2596be),
                0 0 0 6px color-mix(in srgb, var(--color-primary, #2596be) 22%, transparent);
        }

        /* ── Password reveal toggle ── */
        .portal-pw-wrap { position: relative; }
        .portal-pw-wrap .portal-form-input { padding-right: 46px; }
        .portal-pw-toggle {
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
        .portal-pw-toggle:hover {
            color: rgba(235, 230, 220, 0.85);
            background: rgba(255, 255, 255, 0.05);
        }
        .portal-pw-toggle svg { width: 18px; height: 18px; pointer-events: none; }
        .portal-pw-toggle .icon-hide { display: none; }
        .portal-pw-toggle.is-visible .icon-show { display: none; }
        .portal-pw-toggle.is-visible .icon-hide { display: block; }

        /* ── Remember me + Forgot row ── */
        .portal-login-card .portal-form-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 4px 0 24px;
            gap: 12px;
        }
        .portal-login-card .portal-form-check {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
            margin: 0;
            font-size: 0.8125rem;
            color: rgba(235, 230, 220, 0.72);
            letter-spacing: -0.005em;
        }
        .portal-login-card .portal-form-check input[type="checkbox"] {
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
            margin: 0;
            transition: background 150ms ease, border-color 150ms ease, box-shadow 150ms ease;
        }
        .portal-login-card .portal-form-check input[type="checkbox"]:hover {
            border-color: rgba(255, 255, 255, 0.28);
        }
        .portal-login-card .portal-form-check input[type="checkbox"]:checked {
            background: var(--color-primary, #2596be);
            border-color: var(--color-primary, #2596be);
        }
        .portal-login-card .portal-form-check input[type="checkbox"]:checked::after {
            content: "";
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='white' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='3.5,8.5 6.5,11.5 12.5,5'/></svg>");
            background-size: 14px 14px;
            background-position: center;
            background-repeat: no-repeat;
        }
        .portal-login-card .portal-form-link {
            font-size: 0.75rem;
            color: rgba(235, 230, 220, 0.55);
            text-decoration: none;
            transition: color 150ms ease;
            letter-spacing: -0.005em;
        }
        .portal-login-card .portal-form-link:hover {
            color: var(--color-primary, #2596be);
        }

        /* ── Sign in button ── */
        .portal-login-card .portal-login-btn {
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
        .portal-login-card .portal-login-btn:hover {
            filter: brightness(1.06);
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.18) inset,
                0 6px 18px -2px color-mix(in srgb, var(--color-primary, #2596be) 45%, transparent),
                0 2px 4px rgba(0, 0, 0, 0.22);
        }
        .portal-login-card .portal-login-btn:active {
            transform: scale(0.985);
            filter: brightness(0.96);
        }

        /* ── Flash/error blocks (Apple-style minimal alert cards) ── */
        .portal-login-success,
        .portal-login-error {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 0.875rem;
            line-height: 1.45;
            margin-bottom: 18px;
            letter-spacing: -0.005em;
        }
        .portal-login-success {
            background: color-mix(in srgb, #22c55e 14%, transparent);
            border: 1px solid color-mix(in srgb, #22c55e 24%, transparent);
            color: #86efac;
        }
        .portal-login-error {
            background: color-mix(in srgb, #ef4444 14%, transparent);
            border: 1px solid color-mix(in srgb, #ef4444 24%, transparent);
            color: #fca5a5;
        }
        .portal-login-error svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

        /* ── Admin cross-link chip ── two-line layout: muted question
           on top, brand-color CTA below. Mirrors .auth-portal-link
           on the admin login. */
        .portal-admin-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 20px;
            padding: 12px 16px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            text-decoration: none;
            transition: background 150ms ease, border-color 150ms ease;
        }
        .portal-admin-link:hover {
            background: rgba(255, 255, 255, 0.07);
            border-color: rgba(255, 255, 255, 0.12);
        }
        .portal-admin-link__text {
            display: flex;
            flex-direction: column;
            gap: 2px;
            text-align: left;
            min-width: 0;
        }
        .portal-admin-link__q {
            font-size: 0.75rem;
            color: rgba(235, 230, 220, 0.55);
            letter-spacing: -0.005em;
        }
        .portal-admin-link__cta {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--color-primary, #2596be);
            letter-spacing: -0.005em;
        }
        .portal-admin-link .arrow {
            color: rgba(235, 230, 220, 0.55);
            font-size: 1rem;
            flex-shrink: 0;
            transition: transform 150ms ease, color 150ms ease;
        }
        .portal-admin-link:hover .arrow {
            transform: translateX(3px);
            color: var(--color-primary, #2596be);
        }

        /* ── Legal footer inside the card ── */
        .portal-auth-footer {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            text-align: center;
        }
        .portal-auth-footer-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px 18px;
            margin-bottom: 12px;
        }
        .portal-auth-footer-links a {
            font-size: 0.75rem;
            color: rgba(235, 230, 220, 0.50);
            text-decoration: none;
            transition: color 150ms ease;
            letter-spacing: -0.005em;
        }
        .portal-auth-footer-links a:hover {
            color: rgba(255, 255, 255, 0.90);
        }
        .portal-auth-footer-copy {
            font-size: 0.6875rem;
            color: rgba(235, 230, 220, 0.36);
            margin: 0;
            line-height: 1.6;
            letter-spacing: -0.005em;
        }
        .portal-auth-footer-copy span { display: block; }
        .portal-auth-footer-copy strong {
            font-weight: 500;
            color: rgba(235, 230, 220, 0.62);
        }
    </style>
</head>
<body>

<!-- Background video — same wave footage as admin login for brand consistency -->
<div class="video-bg-wrapper" aria-hidden="true">
    <video class="video-bg" autoplay muted loop playsinline preload="auto">
        <source src="<?= asset_url('media/video1.mp4') ?>?v=<?= e(FF_ASSET_VERSION) ?>" type="video/mp4">
    </video>
    <div class="video-bg-overlay"></div>
</div>

<div class="portal-login-page">
    <div class="portal-login-card">

        <!-- Brand mark — branches the same way the admin login does:
             (a) Uploaded logo: <img> only (wordmark carries the name)
             (b) No logo: SVG truck placeholder + h1 with the company name -->
        <div class="portal-login-brand">
            <?php if ($loginLogoUrl !== ''): ?>
                <img src="<?= e($loginLogoUrl) ?>"
                     alt="<?= e($_companyName) ?>"
                     class="portal-login-logo-img">
            <?php else: ?>
                <div class="portal-login-logo-placeholder">
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
                <h1 class="portal-login-title"><?= e($_companyName) ?></h1>
            <?php endif; ?>
            <?php if ($loginTagline !== ''): ?>
                <p class="portal-login-tagline"><?= e($loginTagline) ?></p>
            <?php endif; ?>
        </div>

        <h1 class="portal-auth-heading">Welcome back</h1>
        <p class="portal-auth-subheading">Sign in to your customer portal</p>

        <?php if ($_flash): ?>
            <div class="portal-login-success"><?= e($_flash) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="portal-login-error" role="alert">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(base_url('portal/auth/login')) ?>" autocomplete="on" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">

            <div class="portal-form-group">
                <label class="portal-form-label" for="email">Email address</label>
                <input type="email" id="email" name="email"
                       class="portal-form-input"
                       value="<?= e($email) ?>"
                       placeholder="you@company.com"
                       autocomplete="email"
                       maxlength="254"
                       required autofocus>
            </div>

            <div class="portal-form-group">
                <label class="portal-form-label" for="password">Password</label>
                <div class="portal-pw-wrap">
                    <input type="password" id="password" name="password"
                           class="portal-form-input"
                           placeholder="••••••••"
                           autocomplete="current-password"
                           maxlength="255"
                           required>
                    <button type="button"
                            class="portal-pw-toggle"
                            id="portal-password-toggle"
                            aria-label="Show password"
                            aria-pressed="false"
                            tabindex="-1">
                        <svg class="icon-show" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                        </svg>
                        <svg class="icon-hide" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="portal-form-row">
                <label class="portal-form-check">
                    <input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                    <span>Stay signed in</span>
                </label>
                <a href="<?= e(base_url('portal/auth/forgot_password')) ?>" class="portal-form-link">
                    Forgot password?
                </a>
            </div>

            <button type="submit" class="portal-login-btn">Sign in</button>
        </form>

        <!-- Admin cross-link (inverse of the customer chip on the admin login).
             Two-line layout — muted question on top, brand-color CTA below. -->
        <a href="<?= e(base_url('auth/login')) ?>" class="portal-admin-link">
            <span class="portal-admin-link__text">
                <span class="portal-admin-link__q">Staff member?</span>
                <span class="portal-admin-link__cta">Sign in to the admin panel</span>
            </span>
            <span class="arrow" aria-hidden="true">→</span>
        </a>

        <!-- Legal footer — same surface as admin login -->
        <footer class="portal-auth-footer">
            <div class="portal-auth-footer-links">
                <a href="<?= e(legal_url('terms')) ?>">Terms of Service</a>
                <a href="<?= e(legal_url('privacy')) ?>">Privacy Policy</a>
                <a href="<?= e(legal_url('cookies')) ?>">Cookies</a>
                <a href="<?= e(legal_url('security')) ?>">Security</a>
                <a href="mailto:<?= e(legal_config('company.email_support')) ?>">Support</a>
            </div>
            <p class="portal-auth-footer-copy">
                <span>&copy; <?= date('Y') ?> <?= e($_companyName) ?>. All rights reserved.</span>
                <span>A software by <strong>Avi Technologies</strong></span>
            </p>
        </footer>

    </div>
</div>

<script>
// Password show/hide toggle (matches admin login)
(function () {
    var btn   = document.getElementById('portal-password-toggle');
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
</script>

</body>
</html>
<?php
unset($_csrfToken, $_flash, $error, $email, $_companyName,
      $loginLogo, $loginTagline, $loginColor, $loginHover, $loginLight,
      $loginFavicon, $loginLogoUrl, $loginFaviconUrl);
?>
