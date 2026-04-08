<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Admin Page Header
//
// Included by every admin page AFTER auth.php, require_auth(),
// and require_permission() have run, and AFTER $pageTitle is set.
//
//   $pageTitle = 'Customers';
//   require_once dirname(__DIR__, 2) . '/includes/header.php';
//
// This file:
//   1. Registers the global page exception handler [PASS-8:2]
//   2. Generates / reuses the CSRF token for this session
//   3. Outputs DOCTYPE → <head> → opens <body> and layout
//   4. Includes sidebar.php and topbar.php
//   5. Opens <main class="page-content"> (footer.php closes it)
// ============================================================

// ============================================================
// 1. Global exception handler — must be set before any output
//    [PASS-8:2] All unhandled exceptions → branded 500 page
// ============================================================
set_exception_handler(function (Throwable $e): void {
    error_log(
        '[FF Page Exception] ' . $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine()
    );

    // Discard any partial output already buffered
    if (ob_get_level() > 0) ob_end_clean();

    http_response_code(500);

    if (FF_DEBUG) {
        echo '<pre style="padding:2rem;font-family:monospace">';
        echo '<strong>' . htmlspecialchars($e->getMessage()) . '</strong>' . "\n\n";
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
    } else {
        $errorFile = FF_ROOT . '/app/errors/500.php';
        if (file_exists($errorFile)) {
            require $errorFile;
        } else {
            echo '<h1>500 — Internal Server Error</h1><p>Something went wrong. Please try again.</p>';
        }
    }

    exit;
});

// ============================================================
// 2. CSRF token — generate once per session, reuse thereafter
//    Stored in session; injected into the page as a meta tag
//    so app.js can read it and attach it to every API request.
// ============================================================
$_csrfToken = generate_csrf_token();

// ============================================================
// 3. Page-level variables
// ============================================================
$_user      = current_user();
$_theme     = $_user['theme'] ?? 'dark';
$_pageTitle = isset($pageTitle) ? trim($pageTitle) : 'FleetForge';
$_appName   = settings_get('company.name', 'FleetForge');
$_timezone  = settings_get('company.timezone', APP_TIMEZONE);

// PERM-1 — display settings (per-user font size + density).
// Read from session with conservative defaults so anonymous/legacy
// requests still render normally. Range-clamped to the validated
// 70..130 step set so injected CSS can never be hostile.
$_displayFontSize = (int) ($_user['display_font_size'] ?? 100);
if ($_displayFontSize < 70 || $_displayFontSize > 130) {
    $_displayFontSize = 100;
}
$_displayDensity = $_user['display_density'] ?? 'comfortable';
if (!in_array($_displayDensity, ['compact', 'comfortable', 'spacious'], true)) {
    $_displayDensity = 'comfortable';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($_theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- CSRF token — read by app.js for all API requests -->
    <meta name="csrf-token" content="<?= e($_csrfToken) ?>">

    <!-- MEDIA-1 — notification sound URL picked up by FF_Sound.init().
         Absent on the login/portal pages on purpose: the audio cue is
         admin-only and should never fire during sign-in. -->
    <meta name="notification-sound" content="<?= asset_url('media/notification.mp3') ?>">

    <title><?= e($_pageTitle) ?> — <?= e($_appName) ?></title>

    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">

    <!-- Google Fonts — DM Sans (UI) + DM Mono (numbers/data) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap">

    <!-- Application stylesheet -->
    <!-- D27: asset_url() has no /fleetforge prefix — assets served from public/ root under Herd -->
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

    <!-- ============================================================
         PERM-1 — per-user display font size injection.
         Scoped strictly to .page-content so the sidebar, topbar,
         footer, and modals are NEVER rescaled (those use their
         own fixed sizes for layout consistency).

         The range is server-clamped above (70..130 only).
         ============================================================ -->
    <style id="ff-display-font-size">
        .page-content { font-size: <?= $_displayFontSize ?>%; }
    </style>

    <!-- Company timezone for client-side date formatting -->
    <script>
        window.FF_TIMEZONE    = <?= json_encode($_timezone) ?>;
        window.FF_BASE_PATH   = <?= json_encode(FF_BASE_PATH) ?>;
        window.FF_ASSET_VERSION = <?= json_encode(FF_ASSET_VERSION) ?>;
        // PERM-1 — current display settings, read by topbar quick controls
        window.FF_DISPLAY = {
            font_size: <?= (int) $_displayFontSize ?>,
            density:   <?= json_encode($_displayDensity) ?>,
        };
    </script>
</head>
<body data-density="<?= e($_displayDensity) ?>">

<!-- Skip navigation — visually hidden, appears on keyboard focus (S025 / WCAG 2.4.1) -->
<a href="#main-content" class="skip-nav">Skip to main content</a>

<?php
// ============================================================
// 4. Layout shell — sidebar + main wrapper
//    x-data is processed by Alpine.js (loaded in footer.php)
// ============================================================
?>
<div class="app-layout"
     x-data="{ sidebarOpen: window.innerWidth >= 1024 }"
     @keydown.escape.window="sidebarOpen = false"
     x-cloak>

    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <!-- RESPONSIVE-1 mobile overlay — visible only when sidebar is open on mobile -->
    <div class="sidebar-overlay"
         :class="{ 'is-visible': sidebarOpen }"
         x-show="sidebarOpen"
         @click="sidebarOpen = false"
         aria-hidden="true"
         x-cloak></div>

    <div class="app-main" :class="{ 'sidebar-collapsed': !sidebarOpen }">

        <?php require_once __DIR__ . '/topbar.php'; ?>

        <!-- ============================================================
             5. Page content area — footer.php closes this
             ============================================================ -->
        <main id="main-content" class="page-content">

<?php
// Clean up local variables so they don't leak into page scope
unset($_theme, $_pageTitle, $_appName, $_timezone, $_displayFontSize, $_displayDensity);
// Note: $_user and $_csrfToken are intentionally kept — pages may need them.
?>
