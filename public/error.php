<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Apache-level error handler
//
// Referenced by ErrorDocument directives in .htaccess.
// Apache sets $_SERVER['REDIRECT_STATUS'] to the HTTP code
// that triggered the error before routing here.
//
// This file must be self-sufficient: it may be invoked when
// the application itself has failed (PHP fatal, bad deploy,
// misconfiguration). Therefore:
//
//   1. First attempt: load config/app.php and delegate to the
//      appropriate app/errors/ page (richer UI, app.css).
//
//   2. Fallback: emit a minimal but complete standalone HTML
//      page with full inline styles — zero external deps.
//
// Never call db_*, settings_get, session_start, or any helper
// that could itself throw in the fallback path.
// ============================================================

// ── Determine error code ────────────────────────────────────
// Apache sets REDIRECT_STATUS; allow ?code= for direct testing.
$_rawCode = $_SERVER['REDIRECT_STATUS']
         ?? $_SERVER['HTTP_STATUS']
         ?? $_GET['code']
         ?? 500;

$_code = (int) $_rawCode;

// Clamp to recognised codes; default unknown codes to 500
$_knownCodes = [400, 403, 404, 405, 408, 410, 429, 500, 502, 503, 504];
if (!in_array($_code, $_knownCodes, true)) {
    $_code = 500;
}

// Set the HTTP response code (may already be set by Apache)
http_response_code($_code);

// ── Attempt 1: delegate to the app's error pages ───────────
$_appRoot = dirname(__DIR__);

$_appErrorMap = [
    403 => '/app/errors/403.php',
    404 => '/app/errors/404.php',
];
$_appErrorFile = $_appRoot . ($_appErrorMap[$_code] ?? '/app/errors/500.php');
$_configFile   = $_appRoot . '/config/app.php';

if (is_file($_configFile) && is_file($_appErrorFile)) {
    try {
        require_once $_configFile;
        require     $_appErrorFile;
        exit;
    } catch (Throwable $_bootErr) {
        error_log('[error.php] App boot failed for ' . $_code . ': ' . $_bootErr->getMessage());
        // Fall through to inline fallback
    }
}

// ── Attempt 2: minimal standalone inline page ───────────────

$_titles = [
    400 => ['Bad Request',             'The server could not understand the request.'],
    403 => ['Access Denied',           'You don\'t have permission to view this page.'],
    404 => ['Page Not Found',          'The page you\'re looking for doesn\'t exist.'],
    405 => ['Method Not Allowed',      'This request method is not supported here.'],
    408 => ['Request Timeout',         'The server timed out waiting for the request.'],
    410 => ['Gone',                    'This resource has been permanently removed.'],
    429 => ['Too Many Requests',       'You\'re making requests too quickly. Please slow down.'],
    500 => ['Internal Server Error',   'An unexpected error occurred on our end.'],
    502 => ['Bad Gateway',             'The server received an invalid response upstream.'],
    503 => ['Service Unavailable',     'The service is temporarily unavailable.'],
    504 => ['Gateway Timeout',         'The upstream server did not respond in time.'],
];

[$_title, $_message] = $_titles[$_code] ?? ['Error', 'An error occurred.'];

// Safe base URL from constants if available, otherwise empty
$_base = defined('APP_URL') && defined('FF_BASE_PATH')
    ? rtrim((string)APP_URL, '/') . (string)FF_BASE_PATH
    : '';

// Reference code: timestamp + short random suffix (no sensitive data)
$_ref = date('Ymd-His') . '-' . substr(base_convert((string)mt_rand(), 10, 36), 0, 4);

?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= (int)$_code ?> — <?= htmlspecialchars($_title, ENT_QUOTES, 'UTF-8') ?> · FleetForge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&display=swap">
    <?php if ($_base !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_base . '/assets/css/app.css', ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <script>
        try { var t=localStorage.getItem('ff-theme'); if(t==='light'||t==='dark') document.documentElement.setAttribute('data-theme',t); } catch(e){}
    </script>
    <!-- Complete inline fallback — works even if app.css fails to load -->
    <style>
        :root {
            --bg:      #0f172a; --surface: #1e293b; --border: #334155;
            --t1:      #f1f5f9; --t2:      #94a3b8; --t3:     #64748b;
            --primary: #2563eb; --danger:  #ef4444; --warn:   #d97706;
            --danger-bg: #450a0a; --warn-bg: #422006;
        }
        [data-theme="light"] {
            --bg: #f8fafc; --surface: #ffffff; --border: #e2e8f0;
            --t1: #0f172a; --t2: #475569; --t3: #94a3b8;
            --danger-bg: #fee2e2; --warn-bg: #fef3c7;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg); color: var(--t1);
            min-height: 100vh; display: flex;
            align-items: center; justify-content: center;
            padding: 40px 24px;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { text-align: center; max-width: 500px; width: 100%; }
        .icon-ring {
            width: 84px; height: 84px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 24px;
        }
        .icon-ring svg { width: 42px; height: 42px; }
        .icon-ring.red  { background: var(--danger-bg); color: var(--danger); }
        .icon-ring.warn { background: var(--warn-bg);   color: var(--warn);   }
        .icon-ring.grey { background: var(--surface);   color: var(--t3);     }
        .code-label {
            font-size: 0.8125rem; font-weight: 700; letter-spacing: 0.1em;
            text-transform: uppercase; margin-bottom: 10px;
        }
        .code-label.red  { color: var(--danger); }
        .code-label.warn { color: var(--warn);   }
        .code-label.grey { color: var(--t3);     }
        h1 { font-size: 1.875rem; font-weight: 700; margin-bottom: 12px; line-height: 1.2; }
        .msg { font-size: 1rem; color: var(--t2); line-height: 1.65; margin-bottom: 32px; }
        .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; padding: 9px 20px;
            border-radius: 6px; font-size: 0.875rem; font-weight: 600;
            cursor: pointer; text-decoration: none; border: 1px solid transparent;
            font-family: inherit;
        }
        .btn-p { background: var(--primary); color: #fff; border-color: var(--primary); }
        .btn-s { background: var(--surface); color: var(--t2); border-color: var(--border); }
        .ref-box {
            margin-top: 32px; padding: 14px 16px; background: var(--surface);
            border: 1px solid var(--border); border-radius: 8px;
            font-size: 0.8125rem; color: var(--t2); text-align: left;
        }
        .ref-box code { font-family: monospace; font-size: 0.85em; color: var(--t1); }
        .home-link { margin-top: 28px; font-size: 0.875rem; }
        .home-link a { color: var(--primary); }
    </style>
</head>
<body>
<div class="wrap">

    <?php
    // Choose icon and colour scheme by code category
    if ($_code === 403): ?>

    <div class="icon-ring warn">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z"/>
        </svg>
    </div>
    <div class="code-label warn">Error <?= (int)$_code ?></div>

    <?php elseif ($_code === 404): ?>

    <div class="icon-ring grey">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
        </svg>
    </div>
    <div class="code-label grey">Error <?= (int)$_code ?></div>

    <?php elseif ($_code === 503): ?>

    <div class="icon-ring grey">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l5.654-4.655m5.65-4.65 1.31-1.31a1.902 1.902 0 0 1 2.688 0 1.902 1.902 0 0 1 0 2.688l-1.31 1.31m-5.65 4.65 5.65-4.65"/>
        </svg>
    </div>
    <div class="code-label grey">Error <?= (int)$_code ?></div>

    <?php else: ?>

    <div class="icon-ring red">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008z"/>
        </svg>
    </div>
    <div class="code-label red">Error <?= (int)$_code ?></div>

    <?php endif; ?>

    <h1><?= htmlspecialchars($_title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="msg"><?= htmlspecialchars($_message, ENT_QUOTES, 'UTF-8') ?></p>

    <div class="actions">
        <?php if ($_base !== ''): ?>
            <a href="<?= htmlspecialchars($_base . '/dashboard', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-p">
                Go to dashboard
            </a>
        <?php endif; ?>
        <button onclick="history.back()" class="btn btn-s">Go back</button>
        <?php if ($_code >= 500): ?>
            <button onclick="location.reload()" class="btn btn-s">Try again</button>
        <?php endif; ?>
    </div>

    <?php if ($_code >= 500): ?>
        <div class="ref-box">
            <strong>Reference:</strong> <code><?= htmlspecialchars($_ref, ENT_QUOTES, 'UTF-8') ?></code>
            &nbsp;&mdash;&nbsp;Quote this code if you contact support.
        </div>
    <?php endif; ?>

    <?php if ($_base !== ''): ?>
        <p class="home-link">
            <a href="<?= htmlspecialchars($_base . '/auth/login', ENT_QUOTES, 'UTF-8') ?>">Sign in</a>
        </p>
    <?php endif; ?>

</div>
</body>
</html>
<?php
unset($_rawCode, $_code, $_knownCodes, $_appRoot, $_appErrorMap,
      $_appErrorFile, $_configFile, $_titles, $_title, $_message,
      $_base, $_ref, $_bootErr);
?>
