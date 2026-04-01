<?php
declare(strict_types=1);

// ============================================================
// FleetForge — 404 Not Found
//
// Included by resolve_route() in public/index.php and by
// ff_not_found() helper. HTTP 404 is set by the caller.
// ============================================================

if (!defined('FF_LOADED')) {
    http_response_code(404);
    echo '<h1>404 — Not Found</h1>';
    echo '<p>The page you requested could not be found.</p>';
    return;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>404 — Not Found · FleetForge</title>
    <link rel="icon" href="<?= base_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&display=swap">
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
        .error-card { text-align: center; max-width: 480px; width: 100%; }
        .error-icon-wrap {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--bg-surface-2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .error-icon-wrap svg { width: 40px; height: 40px; color: var(--text-tertiary); }
        .error-code {
            font-size: 0.875rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-tertiary);
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
        .error-suggestions {
            margin-top: 40px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
            text-align: left;
        }
        .error-suggestions-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 12px;
        }
        .error-suggestions-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .error-suggestions-list a {
            font-size: 0.9rem;
            color: var(--color-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .error-suggestions-list a svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
<div class="error-page">
    <div class="error-card">

        <div class="error-icon-wrap" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
            </svg>
        </div>

        <div class="error-code">Error 404</div>
        <h1 class="error-title">Page not found</h1>
        <p class="error-message">
            The page you're looking for doesn't exist or may have been moved.<br>
            Double-check the URL or use the links below.
        </p>

        <div class="error-actions">
            <a href="<?= e(base_url('dashboard')) ?>" class="btn btn-primary btn-md">
                Go to dashboard
            </a>
            <button onclick="history.back()" class="btn btn-secondary btn-md">
                Go back
            </button>
        </div>

        <!-- Quick links to common sections -->
        <div class="error-suggestions">
            <div class="error-suggestions-label">Common pages</div>
            <div class="error-suggestions-list">
                <a href="<?= e(base_url('customers')) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                    Customers
                </a>
                <a href="<?= e(base_url('equipment')) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                    Equipment
                </a>
                <a href="<?= e(base_url('leases')) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/></svg>
                    Leases
                </a>
                <a href="<?= e(base_url('invoices')) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5z"/></svg>
                    Invoices
                </a>
            </div>
        </div>

    </div>
</div>
</body>
</html>
