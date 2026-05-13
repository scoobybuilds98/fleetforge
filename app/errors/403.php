<?php
declare(strict_types=1);

// ============================================================
// FleetForge — 403 Forbidden
//
// Included by require_permission() in includes/auth.php.
// HTTP 403 is set by the caller before this file is required.
// ============================================================

// Ensure constants are available when this page is included
// from contexts where config/app.php is already loaded.
if (!defined('FF_LOADED')) {
    // Bare include without bootstrap — minimal safe output
    http_response_code(403);
    echo '<h1>403 — Access Denied</h1>';
    echo '<p>You do not have permission to view this page.</p>';
    return;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>403 — Access Denied · FleetForge</title>
    <link rel="icon" href="<?= base_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <!-- Fonts self-hosted via @font-face in public/assets/css/app.css (S-PROD-3 2026-05-14) -->
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <script>
        try { var t=localStorage.getItem('ff-theme'); if(t==='light'||t==='dark') document.documentElement.setAttribute('data-theme',t); } catch(e){}
    </script>
    <style>
        .error-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            background-color: var(--bg-body);
        }
        .error-card {
            text-align: center;
            max-width: 480px;
            width: 100%;
        }
        .error-icon-wrap {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--color-warning-light);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .error-icon-wrap svg {
            width: 40px;
            height: 40px;
            color: var(--color-warning);
        }
        .error-code {
            font-size: 0.875rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--color-warning);
            margin-bottom: 8px;
        }
        .error-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 12px;
            line-height: 1.2;
        }
        .error-message {
            font-size: 0.9375rem;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 32px;
        }
        .error-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
<div class="error-page">
    <div class="error-card">

        <div class="error-icon-wrap" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z"/>
            </svg>
        </div>

        <div class="error-code">Error 403</div>
        <h1 class="error-title">Access denied</h1>
        <p class="error-message">
            You don't have permission to view this page.<br>
            If you think this is a mistake, contact your administrator.
        </p>

        <div class="error-actions">
            <a href="<?= e(base_url('dashboard')) ?>" class="btn btn-primary btn-md">
                Go to dashboard
            </a>
            <button onclick="history.back()" class="btn btn-secondary btn-md">
                Go back
            </button>
        </div>

    </div>
</div>
</body>
</html>
