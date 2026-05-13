<?php
declare(strict_types=1);

// ============================================================
// FleetForge — 503 Maintenance Mode
//
// Included by public/index.php when MAINTENANCE_MODE=true and
// the requesting IP is not in MAINTENANCE_BYPASS_IPS.
// HTTP 503 and Retry-After are set by the caller.
//
// Must not call DB, session, or any helper that could fail —
// maintenance mode is often active precisely because those
// subsystems are unavailable.
// ============================================================

// Set Retry-After if not already set by the caller
if (!headers_sent()) {
    header('Retry-After: 3600'); // suggest retry in 1 hour
}

// Safe asset base from constants only — no DB, no functions
$_baseUrl = defined('APP_URL') && defined('FF_BASE_PATH')
    ? rtrim((string) APP_URL, '/') . FF_BASE_PATH
    : '';

$_cssUrl     = $_baseUrl !== '' ? $_baseUrl . '/assets/css/app.css?v=' . (defined('FF_ASSET_VERSION') ? FF_ASSET_VERSION : '1') : '';
$_faviconUrl = $_baseUrl !== '' ? $_baseUrl . '/assets/icons/favicon.svg' : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="300"> <!-- Auto-refresh every 5 minutes -->
    <title>Scheduled Maintenance · FleetForge</title>
    <?php if ($_faviconUrl !== ''): ?>
        <link rel="icon" href="<?= htmlspecialchars($_faviconUrl, ENT_QUOTES, 'UTF-8') ?>" type="image/svg+xml">
    <?php endif; ?>
    <!-- Fonts self-hosted via @font-face in public/assets/css/app.css (S-PROD-3 2026-05-14) -->
    <?php if ($_cssUrl !== ''): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($_cssUrl, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <script>
        try { var t=localStorage.getItem('ff-theme'); if(t==='light'||t==='dark') document.documentElement.setAttribute('data-theme',t); } catch(e){}
    </script>
    <!-- Full inline styles — app.css is likely unavailable during maintenance -->
    <style>
        :root {
            --bg-body: #0f172a; --bg-surface: #1e293b; --text-primary: #f1f5f9;
            --text-secondary: #94a3b8; --text-tertiary: #64748b;
            --border-color: #334155; --color-primary: #2563eb;
            --color-info: #0891b2; --color-info-light: #083344;
        }
        [data-theme="light"] {
            --bg-body: #f8fafc; --bg-surface: #ffffff; --text-primary: #0f172a;
            --text-secondary: #475569; --text-tertiary: #94a3b8;
            --border-color: #e2e8f0; --color-info-light: #cffafe;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            -webkit-font-smoothing: antialiased;
        }
        .maint-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            text-align: center;
        }
        .maint-icon-wrap {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: var(--color-info-light);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
        }
        .maint-icon-wrap svg { width: 44px; height: 44px; color: var(--color-info); }
        .maint-badge {
            display: inline-block;
            font-size: 0.8125rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--color-info);
            margin-bottom: 12px;
        }
        .maint-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 14px;
            line-height: 1.2;
        }
        .maint-message {
            font-size: 1rem;
            color: var(--text-secondary);
            line-height: 1.65;
            max-width: 420px;
            margin: 0 auto 36px;
        }
        .maint-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px 24px;
            max-width: 380px;
            width: 100%;
            margin: 0 auto;
            text-align: left;
        }
        .maint-card-title {
            font-size: 0.8125rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-tertiary);
            margin-bottom: 12px;
        }
        .maint-progress {
            height: 6px;
            background: var(--border-color);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 12px;
        }
        .maint-progress-bar {
            height: 100%;
            width: 60%;
            background: var(--color-info);
            border-radius: 3px;
            animation: maint-pulse 2s ease-in-out infinite;
        }
        @keyframes maint-pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.5; }
        }
        .maint-eta {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        .maint-footer {
            margin-top: 40px;
            font-size: 0.8125rem;
            color: var(--text-tertiary);
        }
        .maint-footer a { color: var(--color-primary); text-decoration: none; }
        .maint-footer a:hover { text-decoration: underline; }
        .maint-refresh-hint {
            margin-top: 24px;
            font-size: 0.8125rem;
            color: var(--text-tertiary);
        }
    </style>
</head>
<body>
<div class="maint-page">

    <div class="maint-icon-wrap" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l5.654-4.655m5.65-4.65 1.31-1.31a1.902 1.902 0 0 1 2.688 0 1.902 1.902 0 0 1 0 2.688l-1.31 1.31m-5.65 4.65 5.65-4.65m-5.65 4.65-.008-.005-.007-.005"/>
        </svg>
    </div>

    <span class="maint-badge">Scheduled maintenance</span>
    <h1 class="maint-title">We'll be right back</h1>
    <p class="maint-message">
        FleetForge is temporarily offline for scheduled maintenance.
        We're working to get everything back up as quickly as possible.
    </p>

    <div class="maint-card" role="status" aria-label="Maintenance progress">
        <div class="maint-card-title">Work in progress</div>
        <div class="maint-progress" aria-hidden="true">
            <div class="maint-progress-bar"></div>
        </div>
        <p class="maint-eta">
            Estimated return: check back shortly.<br>
            This page refreshes automatically every 5&nbsp;minutes.
        </p>
    </div>

    <p class="maint-refresh-hint">
        <button onclick="location.reload()"
                style="background:none;border:none;cursor:pointer;color:inherit;font:inherit;text-decoration:underline;padding:0;">
            Refresh now
        </button>
    </p>

    <footer class="maint-footer">
        <p>FleetForge &mdash; Fleet Operations Platform</p>
    </footer>

</div>
</body>
</html>
<?php unset($_baseUrl, $_cssUrl, $_faviconUrl); ?>
