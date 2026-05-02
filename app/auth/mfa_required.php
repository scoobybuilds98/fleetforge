<?php
declare(strict_types=1);

/**
 * app/auth/mfa_required.php
 *
 * Shown when a user's role requires MFA but they haven't set it up yet.
 * The user is NOT logged in — $_SESSION['ff_mfa_must_setup'] holds
 * their user_id. They cannot reach the dashboard until MFA is configured.
 *
 * Only a Set Up link is shown. Navigating away clears the pending state
 * and forces them back to login.
 *
 * @session S-PROD-1A
 */

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

_ff_session_start();

// Already logged in → dashboard
if (current_user_id()) {
    header('Location: ' . base_url('dashboard'));
    exit;
}

// Must have the must-setup flag
if (empty($_SESSION['ff_mfa_must_setup'])) {
    header('Location: ' . base_url('auth/login'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>MFA Setup Required — FleetForge</title>
    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&display=swap">
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <script>try{var t=localStorage.getItem('ff-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <style>
        .auth-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;background:var(--bg-body);}
        .auth-card{width:100%;max-width:440px;background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);padding:36px 32px 32px;}
        .auth-logo{display:flex;flex-direction:column;align-items:center;margin-bottom:28px;}
        .auth-logo-mark{width:48px;height:48px;background:var(--color-warning,#f59e0b);border-radius:var(--radius-lg);display:flex;align-items:center;justify-content:center;margin-bottom:12px;}
        .auth-logo-mark svg{width:28px;height:28px;color:#fff;}
        .auth-logo-name{font-size:1.25rem;font-weight:700;color:var(--text-primary);}
    </style>
</head>
<body>
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="auth-logo-mark" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>
                </svg>
            </div>
            <div class="auth-logo-name">FleetForge</div>
        </div>

        <h1 style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:8px;">
            Two-factor authentication required
        </h1>
        <p style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:24px;line-height:1.6;">
            Your role requires two-factor authentication. You must set it up before
            accessing the dashboard.
        </p>
        <p style="font-size:0.875rem;color:var(--text-tertiary);margin-bottom:24px;line-height:1.6;">
            You'll need an authenticator app on your phone — Google Authenticator,
            Authy, or 1Password all work. The setup takes about two minutes.
        </p>

        <a href="<?= e(base_url('account/mfa-setup')) ?>" class="btn btn-primary w-full btn-lg">
            Set up two-factor authentication
        </a>

        <div style="text-align:center;margin-top:16px;font-size:0.875rem;">
            <a href="<?= e(base_url('auth/login')) ?>" class="btn-link btn-sm">&larr; Back to sign in</a>
        </div>

    </div>
</div>
</body>
</html>
