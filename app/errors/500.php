<?php
declare(strict_types=1);

// ============================================================
// FleetForge — 500 Internal Server Error
//
// Included by the global exception handler in header.php after
// ob_end_clean() discards any partial page output.
// Also used as fallback in public/index.php for unexpected errors.
//
// IMPORTANT: This file must not call any application helpers
// (db_*, settings_get, etc.) — the DB or autoloader may be the
// source of the 500 and calling them would cause a second fault.
// Only primitives and constants defined in config/app.php are safe.
// ============================================================

// Minimal safe output if bootstrapped without config/app.php
if (!defined('FF_LOADED')) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body><h1>500 Internal Server Error</h1>';
    echo '<p>Something went wrong. Please try again.</p></body></html>';
    return;
}

// Resolve asset base safely — APP_URL is a raw constant, no DB needed
$_baseUrl    = rtrim((string) APP_URL, '/') . FF_BASE_PATH;
$_cssUrl     = $_baseUrl . '/assets/css/app.css?v=' . FF_ASSET_VERSION;
$_faviconUrl = $_baseUrl . '/assets/icons/favicon.svg';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>500 — Server Error · FleetForge</title>
    <link rel="icon" href="<?= htmlspecialchars($_faviconUrl, ENT_QUOTES, 'UTF-8') ?>" type="image/svg+xml">
    <!-- Fonts self-hosted via @font-face in public/assets/css/app.css (S-PROD-3 2026-05-14) -->
    <!-- app.css may fail to load if the error is file-system related; inline fallback below -->
    <link rel="stylesheet" href="<?= htmlspecialchars($_cssUrl, ENT_QUOTES, 'UTF-8') ?>">
    <script>
        try { var t=localStorage.getItem('ff-theme'); if(t==='light'||t==='dark') document.documentElement.setAttribute('data-theme',t); } catch(e){}
    </script>
    <!-- Inline fallback: critical styles in case app.css fails -->
    <style>
        :root {
            --bg-body: #0f172a; --bg-surface: #1e293b; --text-primary: #f1f5f9;
            --text-secondary: #94a3b8; --border-color: #334155;
            --color-danger: #ef4444; --color-danger-light: #450a0a;
        }
        [data-theme="light"] {
            --bg-body: #f8fafc; --bg-surface: #ffffff; --text-primary: #0f172a;
            --text-secondary: #475569; --border-color: #e2e8f0;
            --color-danger-light: #fee2e2;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', system-ui, sans-serif; background: var(--bg-body); color: var(--text-primary); }
        .error-page { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:40px 24px; }
        .error-card { text-align:center; max-width:480px; width:100%; }
        .error-icon-wrap { width:80px; height:80px; border-radius:50%; background:var(--color-danger-light); display:flex; align-items:center; justify-content:center; margin:0 auto 24px; }
        .error-icon-wrap svg { width:40px; height:40px; color:var(--color-danger); }
        .error-code { font-size:0.875rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-danger); margin-bottom:8px; }
        .error-title { font-size:1.75rem; font-weight:700; color:var(--text-primary); margin-bottom:12px; line-height:1.2; }
        .error-message { font-size:0.9375rem; color:var(--text-secondary); line-height:1.6; margin-bottom:32px; }
        .error-actions { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; padding:9px 18px; border-radius:6px; font-size:0.875rem; font-weight:500; cursor:pointer; text-decoration:none; border:1px solid transparent; }
        .btn-primary { background:#2563eb; color:#fff; }
        .btn-secondary { background:var(--bg-surface); color:var(--text-secondary); border-color:var(--border-color); }
        .error-ref { margin-top:32px; font-size:0.8125rem; color:var(--text-secondary); padding:14px 16px; background:var(--bg-surface); border:1px solid var(--border-color); border-radius:8px; text-align:left; }
        .error-ref code { font-family:monospace; font-size:0.8em; }
    </style>
</head>
<body>
<div class="error-page">
    <div class="error-card">

        <div class="error-icon-wrap" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008z"/>
            </svg>
        </div>

        <div class="error-code">Error 500</div>
        <h1 class="error-title">Something went wrong</h1>
        <p class="error-message">
            An unexpected error occurred on our end.<br>
            The issue has been logged and we'll look into it.
        </p>

        <div class="error-actions">
            <a href="<?= htmlspecialchars($_baseUrl . '/dashboard', ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-primary">
                Go to dashboard
            </a>
            <button onclick="location.reload()" class="btn btn-secondary">
                Try again
            </button>
        </div>

        <!-- Reference ID for support (timestamp-based, no sensitive info) -->
        <div class="error-ref">
            <strong>Reference:</strong>
            <code><?= htmlspecialchars(date('Ymd-His') . '-' . substr(base_convert((string)mt_rand(), 10, 36), 0, 4), ENT_QUOTES, 'UTF-8') ?></code>
            &nbsp;&mdash;&nbsp;
            If you contact support, include this code.
        </div>

    </div>
</div>
</body>
</html>
<?php unset($_baseUrl, $_cssUrl, $_faviconUrl); ?>
