<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Embedded Page Header (chrome-free)
//
// S-BATCH-INVOICING: a stripped sibling of includes/header.php for
// pages rendered inside an <iframe> (the batch-invoicing split-screen
// invoice preview). Skips the sidebar, topbar, and the .app-layout /
// .app-main wrapper divs entirely — those add ~260px of chrome that
// makes zero sense inside a narrow embedded pane and would force an
// awkward nested-scrollbar UI.
//
// WHY a separate file instead of an `if ($isEmbed)` branch inside
// header.php: header.php is included by EVERY admin page and its
// sidebar/topbar output is deeply order-dependent (opens divs that
// footer.php closes). Branching it in place would risk every other
// page in the app for a feature only one page needs. A parallel file
// keeps the blast radius to the pages that opt in.
//
// Contract — mirrors header.php's public surface so a page can switch
// straight from a header.php-based include to this one with almost no
// changes:
//   - Sets $_csrfToken (meta tag app.js reads for API calls)
//   - Opens <main class="page-content page-content--embed"> — footer_embed.php closes it
//   - $pageTitle / $helpModuleSlug are honored (title only; the help
//     drawer itself is a footer.php partial and is intentionally
//     omitted — an embedded invoice preview has no help affordance)
//
// Usage (see app/admin/invoices/show.php):
//   $pageTitle = '...';
//   require_once FF_ROOT . '/includes/' . ($isEmbed ? 'header_embed.php' : 'header.php');
//   ... require_once FF_ROOT . '/includes/' . ($isEmbed ? 'footer_embed.php' : 'footer.php');
// ============================================================

// heroicon() is normally defined by includes/sidebar.php — which this
// shell deliberately never requires (that file's tail emits the actual
// <aside> sidebar markup, so requiring it would print the sidebar we're
// trying to avoid). Page BODIES (e.g. invoices/show.php) call heroicon()
// directly and independently of any sidebar/nav context, so it must be
// defined here too. Duplicated verbatim from includes/sidebar.php rather
// than extracted to a shared file — smallest possible diff, zero risk to
// the many pages that already depend on sidebar.php's exact behavior.
// Guarded so this is a no-op if that ever changes.
if (!function_exists('heroicon')) {
    function heroicon(string $name, string $class = 'nav-icon'): string
    {
        static $cache = [];

        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $file = FF_ROOT . '/public/assets/icons/' . $name . '.svg';

        if (!file_exists($file)) {
            foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                $imgFile = FF_ROOT . '/public/assets/icons/' . $name . '.' . $ext;
                if (file_exists($imgFile)) {
                    $src = asset_url('assets/icons/' . $name . '.' . $ext);
                    $cache[$name] = '<img src="' . e($src) . '" class="' . e($class) . '" alt="" aria-hidden="true" loading="lazy">';
                    return $cache[$name];
                }
            }
            $cache[$name] = '<span class="' . e($class) . ' icon-missing" aria-hidden="true"></span>';
            return $cache[$name];
        }

        $svg = file_get_contents($file);
        if ($svg === false) {
            $cache[$name] = '<span class="' . e($class) . ' icon-missing" aria-hidden="true"></span>';
            return $cache[$name];
        }

        $svg = preg_replace(
            '/<svg\b/',
            '<svg class="' . e($class) . '" aria-hidden="true"',
            $svg,
            1
        );

        $cache[$name] = (string) $svg;
        return $cache[$name];
    }
}

\FleetForge\Observability\Sentry::init();

set_exception_handler(function (Throwable $e): void {
    error_log(
        '[FF Embed Page Exception] ' . $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine()
    );
    \FleetForge\Observability\Sentry::captureException($e);
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    if (FF_DEBUG) {
        echo '<pre style="padding:2rem;font-family:monospace">';
        echo '<strong>' . htmlspecialchars($e->getMessage()) . '</strong>' . "\n\n";
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
    } else {
        echo '<h1>500 — Internal Server Error</h1><p>Something went wrong. Please try again.</p>';
    }
    exit;
});

$_csrfToken = generate_csrf_token();
$_user      = current_user();
$_theme     = $_user['theme'] ?? 'dark';
$_pageTitle = isset($pageTitle) ? trim($pageTitle) : 'FleetForge';
$_appName   = settings_get('company.name', 'FleetForge');
$_timezone  = settings_get('company.timezone', APP_TIMEZONE);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($_theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">

    <!-- CSRF token — read by app.js for any in-page API calls -->
    <meta name="csrf-token" content="<?= e($_csrfToken) ?>">

    <title><?= e($_pageTitle) ?> — <?= e($_appName) ?></title>

    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/animations.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <!-- S-MODULE-CHROME: embedded record pages (batch invoicing) render the same hero + KPI tiles -->
    <link rel="stylesheet" href="<?= asset_url('assets/css/module-chrome.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

    <?php
    // Brand override — same as header.php, so an embedded invoice matches
    // the company's brand color instead of looking like a different app.
    $_ff_primary = settings_get('brand.primary_color');
    $_ff_hover   = settings_get('brand.primary_hover');
    $_ff_light   = settings_get('brand.primary_light');
    if ($_ff_primary):
    ?>
    <style id="ff-brand-override">
        :root {
            --color-primary:       <?= e((string) $_ff_primary) ?>;
            --color-primary-hover: <?= e((string) ($_ff_hover ?: '#1e7ea0')) ?>;
            --color-primary-light: <?= e((string) ($_ff_light ?: '#e0f4fb')) ?>;
        }
    </style>
    <?php endif; ?>

    <style id="ff-embed-shell">
        /* No sidebar/topbar/app-footer here, so .page-content owns the
           full viewport directly instead of sitting inside .app-main. */
        html, body { height: 100%; }
        body { margin: 0; background: var(--color-bg, #0b0f14); }
        .page-content.page-content--embed {
            max-width: none;
            margin: 0;
            padding: 20px;
        }
    </style>

    <script>
        window.FF_TIMEZONE      = <?= json_encode($_timezone) ?>;
        window.FF_BASE_PATH     = <?= json_encode(FF_BASE_PATH) ?>;
        window.FF_ASSET_VERSION = <?= json_encode(FF_ASSET_VERSION) ?>;
        window.FF_EMBED         = true;
        // UTC DATETIME helpers (S-UTC-STAMPS). Stored DATETIMEs are UTC
        // (includes/db.php pins the session to '+00:00'), but a bare
        // 'YYYY-MM-DD HH:MM:SS' string passed to new Date() is parsed as the
        // BROWSER's local time — every such stamp rendered 7–8h off. Parse with an
        // explicit Z, render in the company timezone. Strings that already carry a
        // zone (…Z / ±HH:MM) are honoured as-is; a date-only 'YYYY-MM-DD' is a
        // calendar day, anchored at 12:00 UTC so it never slips a day when shown.
        window.FF_parseUtc = function (v) {
            if (v === null || v === undefined || v === '') return null;
            if (v instanceof Date) return isNaN(v.getTime()) ? null : v;
            var s = String(v).trim(), d;
            if (/^\d{4}-\d{2}-\d{2}$/.test(s)) d = new Date(s + 'T12:00:00Z');
            else if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(s)) d = new Date(s.replace(' ', 'T') + 'Z');
            else d = new Date(s);
            return isNaN(d.getTime()) ? null : d;
        };
        window.FF_formatUtc = function (v, opts) {
            var d = window.FF_parseUtc(v);
            if (!d) return '—';
            var o = Object.assign({ year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }, opts || {});
            try { o.timeZone = window.FF_TIMEZONE || undefined; return d.toLocaleString('en-CA', o); }
            catch (e) { delete o.timeZone; return d.toLocaleString('en-CA', o); }
        };
        // Business-day date helper. `new Date().toISOString().slice(0, 10)` is the
        // UTC day, which in Pacific time rolls to TOMORROW after 5pm — so default
        // form dates (lease start, return date, payment date…) were off by one every
        // evening. Format in the COMPANY timezone instead; en-CA yields YYYY-MM-DD.
        // Accepts an optional Date / timestamp; falls back to browser-local parts.
        window.FF_localDate = function (d) {
            var dt = d instanceof Date ? d : (d === undefined || d === null ? new Date() : new Date(d));
            try {
                return new Intl.DateTimeFormat('en-CA', {
                    timeZone: window.FF_TIMEZONE || undefined,
                    year: 'numeric', month: '2-digit', day: '2-digit'
                }).format(dt);
            } catch (e) {
                var p = function (n) { return (n < 10 ? '0' : '') + n; };
                return dt.getFullYear() + '-' + p(dt.getMonth() + 1) + '-' + p(dt.getDate());
            }
        };
    </script>
</head>
<body data-density="<?= e($_user['display_density'] ?? 'comfortable') ?>">

<main id="main-content" class="page-content page-content--embed">

<?php
unset($_theme, $_pageTitle, $_appName, $_timezone, $_ff_primary, $_ff_hover, $_ff_light);
// $_user and $_csrfToken intentionally kept — the embedded page may need them.
?>
