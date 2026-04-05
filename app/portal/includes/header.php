<?php
declare(strict_types=1);

/**
 * app/portal/includes/header.php
 *
 * Portal page header — separate from admin layout.
 * Clean, consumer-grade layout. No dark sidebar.
 *
 * Usage:
 *   $pageTitle = 'Dashboard';
 *   require_once dirname(__DIR__) . '/includes/header.php';
 */

// Global exception handler for portal pages
set_exception_handler(function (Throwable $e): void {
    error_log('[FF Portal Exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    if (FF_DEBUG) {
        echo '<pre style="padding:2rem;font-family:monospace">';
        echo '<strong>' . htmlspecialchars($e->getMessage()) . '</strong>' . "\n\n";
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
    } else {
        echo '<h1>Something went wrong</h1><p>Please try again or contact support.</p>';
    }
    exit;
});

$_csrfToken   = portal_csrf_token();
$_portalUser  = portal_user();
$_theme       = 'light'; // Portal defaults to light theme
$_pageTitle   = isset($pageTitle) ? trim($pageTitle) : 'Customer Portal';
$_companyName = settings_get('company.name', 'FleetForge');
$_customerId  = portal_customer_id();

// Check for overdue invoices — show banner on every page
$_overdueCount = 0;
$_overdueTotal = '0.00';
try {
    $ov = db_row(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due), 0) AS total
         FROM invoices WHERE customer_id = ? AND status = 'overdue' AND deleted_at IS NULL",
        [$_customerId]
    );
    if ($ov) {
        $_overdueCount = (int) $ov['cnt'];
        $_overdueTotal = $ov['total'];
    }
} catch (Throwable) {}

// User initials for avatar
$_initials = 'U';
if (!empty($_portalUser['name'])) {
    $parts = preg_split('/\s+/', trim($_portalUser['name']));
    $_initials = strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $_initials .= strtoupper(mb_substr(end($parts), 0, 1));
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($_theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e($_csrfToken) ?>">
    <title><?= e($_pageTitle) ?> — <?= e($_companyName) ?> Portal</title>
    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap">
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <script>
        window.FF_TIMEZONE  = <?= json_encode(settings_get('company.timezone', APP_TIMEZONE)) ?>;
        window.FF_BASE_PATH = <?= json_encode(FF_BASE_PATH) ?>;
    </script>
</head>
<body>

<div class="portal-layout" x-data="{ sidebarOpen: window.innerWidth >= 1024 }" x-cloak>

    <!-- Mobile overlay -->
    <div class="portal-sidebar-overlay"
         :class="{ 'is-visible': sidebarOpen }"
         @click="sidebarOpen = false"></div>

    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="portal-main" :class="{ 'sidebar-hidden': !sidebarOpen && window.innerWidth >= 1024 }">

        <!-- Topbar -->
        <header class="portal-topbar">
            <div class="portal-topbar-left">
                <button class="portal-mobile-toggle" @click="sidebarOpen = !sidebarOpen" aria-label="Toggle menu">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                    </svg>
                </button>
                <h1 class="portal-topbar-title"><?= e($_pageTitle) ?></h1>
            </div>
            <div class="portal-topbar-right">
                <!-- Theme toggle -->
                <div x-data="{ dark: document.documentElement.getAttribute('data-theme') === 'dark' }">
                    <button class="btn-icon" @click="dark = !dark; document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light'); try { localStorage.setItem('ff-theme', dark ? 'dark' : 'light') } catch(e) {}" :title="dark ? 'Light mode' : 'Dark mode'" :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'">
                        <template x-if="!dark">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg>
                        </template>
                        <template x-if="dark">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg>
                        </template>
                    </button>
                </div>
                <!-- Logout -->
                <a href="<?= e(base_url('portal/auth/logout')) ?>" class="btn-icon" title="Sign out" aria-label="Sign out">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>
                </a>
            </div>
        </header>

        <?php if ($_overdueCount > 0): ?>
        <div class="portal-alert-banner portal-alert-banner--danger">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            You have <?= e((string) $_overdueCount) ?> overdue invoice<?= $_overdueCount > 1 ? 's' : '' ?> totaling <?= e(format_currency($_overdueTotal)) ?>.
            <a href="<?= e(base_url('portal/invoices?tab=outstanding')) ?>" style="color:inherit;font-weight:700;text-decoration:underline;margin-left:4px;">View invoices</a>
        </div>
        <?php endif; ?>

        <div class="portal-content">
<?php
unset($_theme, $_pageTitle, $_companyName, $_initials, $_overdueCount, $_overdueTotal);
?>
