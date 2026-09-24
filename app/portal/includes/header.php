<?php
declare(strict_types=1);

/**
 * app/portal/includes/header.php
 *
 * Customer portal shell — opening half (S-PORTAL-REDESIGN). Renders the
 * <head>, the grouped sidebar (sidebar.php), the topbar (search, Pay,
 * notifications, theme, account menu) and opens <main>. footer.php closes
 * it and adds the shared overlays (search palette, Pay drawer, payment
 * notice, statement picker) that any page can open.
 *
 * Usage:
 *   $pageTitle   = 'Invoices';        // <title> + topbar context
 *   $ptHideRibbon = true;             // optional: suppress the past-due ribbon
 *   require_once dirname(__DIR__) . '/includes/header.php';
 *
 * Theme: the portal follows the customer's stored choice (localStorage
 * 'ff-theme'), applied by an inline script in <head> BEFORE first paint —
 * the old footer-time apply flashed light before switching to dark. With no
 * stored choice the portal renders light (customer-facing default).
 *
 * White-label: the Settings → Design brand colour is injected as the
 * ff-brand-override block (S-LUX-4); portal.css derives every tint from
 * --color-primary, so a non-orange brand recolours the whole portal.
 */

require_once __DIR__ . '/ui.php';

// Init Sentry before the exception handler so portal crashes are captured (S-PROD-2 / #19).
\FleetForge\Observability\Sentry::init();

set_exception_handler(function (Throwable $e): void {
    error_log('[FF Portal Exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    \FleetForge\Observability\Sentry::captureException($e);
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
$_pageTitle   = isset($pageTitle) ? trim((string) $pageTitle) : 'Customer Portal';
$_companyName = (string) settings_get('company.name', 'FleetForge');
$_customerId  = portal_customer_id();
$_summary     = pt_account_summary($_customerId);
$_onlinePay   = pt_online_pay_enabled();

// [NOTIF-1] Portal unread notification count for the bell badge
$_portalUnread = 0;
try {
    $_pid = portal_user_id();
    if ($_pid) {
        $_portalUnread = db_count(
            'SELECT COUNT(*) FROM notifications
              WHERE portal_user_id = ? AND is_read = 0 AND deleted_at IS NULL',
            [$_pid]
        );
    }
    unset($_pid);
} catch (Throwable) {}

$_userName  = (string) ($_portalUser['name'] ?? '');
$_initials  = pt_initials($_userName !== '' ? $_userName : (string) ($_portalUser['email'] ?? ''));
$_hasBalance = bccomp($_summary['outstanding'], '0', 2) > 0;

// Quick links for the search palette's empty state (and keyboard users).
$_quickLinks = [
    ['group' => 'Go to', 'title' => 'Home',             'sub' => 'Your account at a glance',            'url' => pt_url(),                       'icon' => 'home'],
    ['group' => 'Go to', 'title' => 'Pay & payments',   'sub' => 'Pay invoices, receipts, statements',  'url' => pt_url('payments'),             'icon' => 'credit-card'],
    ['group' => 'Go to', 'title' => 'Invoices',         'sub' => 'Every invoice, with PDFs',            'url' => pt_url('invoices'),             'icon' => 'document-text'],
    ['group' => 'Go to', 'title' => 'Leases',           'sub' => 'Your rentals and contracts',          'url' => pt_url('leases'),               'icon' => 'clipboard-document-list'],
    ['group' => 'Go to', 'title' => 'Equipment',        'sub' => 'Units you have on rent',              'url' => pt_url('equipment'),            'icon' => 'truck'],
    ['group' => 'Go to', 'title' => 'Documents',        'sub' => 'Contracts, registrations, reports',   'url' => pt_url('documents'),            'icon' => 'folder-open'],
    ['group' => 'Go to', 'title' => 'New request',      'sub' => 'Extend, return, report damage, ask',  'url' => pt_url('requests/create'),      'icon' => 'plus'],
    ['group' => 'Go to', 'title' => 'Account settings', 'sub' => 'Profile, password, notifications',    'url' => pt_url('account'),              'icon' => 'user-circle'],
];
$_quickJs = [];
foreach ($_quickLinks as $_q) {
    $_quickJs[] = $_q + ['icon_svg' => pt_icon($_q['icon'], 'pt-ic')];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bg="<?= e(ff_background()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e($_csrfToken) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($_pageTitle) ?> — <?= e($_companyName) ?></title>
    <?= ff_favicon_tags() ?>
    <script>
        // Apply the stored theme before first paint (no light→dark flash).
        (function () { try { var t = localStorage.getItem('ff-theme'); if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t); } catch (e) {} })();
    </script>
    <!-- S-LUX-1: Geist variable fonts — self-hosted, preloaded to avoid FOUT. -->
    <link rel="preload" href="<?= asset_url('assets/fonts/Geist[wght].woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= asset_url('assets/fonts/GeistMono[wght].woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/backgrounds.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/portal.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <?php
    // S-LUX-4: the white-label brand colour (Settings → Design), read-only → CSS vars.
    $_ffBrand = (string) (settings_get('brand.primary_color') ?: '');
    if ($_ffBrand !== ''): ?>
    <style id="ff-brand-override">:root{--color-primary:<?= e($_ffBrand) ?>;--color-primary-hover:<?= e((string)(settings_get('brand.primary_hover') ?: '#1e7ea0')) ?>;--color-primary-light:<?= e((string)(settings_get('brand.primary_light') ?: '#e0f4fb')) ?>;}</style>
    <?php endif; ?>
    <script>
        window.FF_TIMEZONE  = <?= json_encode(settings_get('company.timezone', APP_TIMEZONE)) ?>;
        window.FF_BASE_PATH = <?= json_encode(FF_BASE_PATH) ?>;
        window.PT_CONFIG = <?= json_encode([
            'online_pay'  => $_onlinePay,
            'today'       => ff_today(),
            'quick_links' => $_quickJs,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        // UTC DATETIME helpers (S-UTC-STAMPS): stored DATETIMEs are UTC; parse with
        // an explicit Z and render in the company timezone. Date-only strings are
        // calendar days anchored at 12:00 UTC so they never slip a day.
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
    </script>
</head>
<body class="pt-body">
<a class="pt-sr" href="#pt-main">Skip to content</a>

<div class="pt-app" x-data="PT_Shell()" :class="{ 'is-nav-open': navOpen }" @keydown.escape.window="navOpen = false">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="pt-scrim" x-show="navOpen" x-cloak x-transition.opacity @click="navOpen = false"></div>

    <div class="pt-main">

        <header class="pt-top">
            <button type="button" class="pt-icon-btn pt-menu-btn" @click="navOpen = !navOpen" :aria-expanded="navOpen" aria-label="Open menu">
                <?= pt_icon('bars-3') ?>
            </button>

            <button type="button" class="pt-search-btn" @click="openSearch()" aria-label="Search invoices, leases and units">
                <?= pt_icon('magnifying-glass') ?>
                <span>Search invoices, leases, units…</span>
                <kbd class="pt-kbd" aria-hidden="true">⌘K</kbd>
            </button>

            <div class="pt-top-spacer"></div>

            <div class="pt-top-actions">
                <?php if ($_hasBalance): ?>
                <a href="<?= e(pt_url('payments')) ?>" class="pt-btn pt-btn--primary pt-btn--sm pt-top-pay">
                    <?= pt_icon('credit-card') ?>
                    <span>Pay <?= e(pt_money($_summary['outstanding'], $_summary['currency'])) ?></span>
                </a>
                <?php endif; ?>

                <!-- [NOTIF-1] Portal notifications bell — FF_PortalNotifications() (app.js) -->
                <div class="notif-wrapper"
                     x-data="FF_PortalNotifications()"
                     x-init="unreadCount = <?= (int) $_portalUnread ?>;"
                     @pt-notif-count.window="unreadCount = $event.detail"
                     @click.outside="open = false"
                     @keydown.escape.window="open = false">
                    <button type="button"
                            class="pt-icon-btn notif-bell-btn"
                            :class="{ 'has-unread': unreadCount > 0 }"
                            @click="toggleDropdown()"
                            :aria-expanded="open"
                            :aria-label="unreadCount > 0 ? 'Notifications (' + unreadCount + ' unread)' : 'Notifications'">
                        <?= pt_icon('bell') ?>
                        <span class="notif-badge" x-show="unreadCount > 0" x-text="unreadCount > 99 ? '99+' : unreadCount" aria-hidden="true"></span>
                    </button>
                    <div class="notif-dropdown" x-show="open" x-cloak x-transition.opacity.duration.150ms role="menu" aria-label="Notifications">
                        <div class="notif-dropdown-header">
                            <span class="notif-dropdown-title">Notifications</span>
                            <button type="button" class="notif-mark-all" @click="markAllRead()" x-show="unreadCount > 0">Mark all read</button>
                        </div>
                        <div class="notif-loading" x-show="loading" x-cloak>Loading…</div>
                        <div class="notif-empty" x-show="!loading && notifications.length === 0" x-cloak><p>You're all caught up</p></div>
                        <template x-for="n in notifications" :key="n.id">
                            <a :href="n.url || '#'" class="notif-item" :class="{ 'notif-item--unread': !n.is_read }" @click="markRead(n.id)">
                                <div class="notif-icon notif-icon--info"><?= pt_icon('bell', 'pt-ic pt-ic--sm') ?></div>
                                <div class="notif-content">
                                    <div class="notif-title" x-text="n.title"></div>
                                    <div class="notif-message" x-text="n.message"></div>
                                    <div class="notif-time" x-text="n.time_ago"></div>
                                </div>
                                <div class="notif-unread-dot" x-show="!n.is_read"></div>
                            </a>
                        </template>
                        <a href="<?= e(pt_url('notifications')) ?>" class="notif-dropdown-footer">See all notifications</a>
                    </div>
                </div>

                <!-- Theme toggle (stored in localStorage 'ff-theme', applied pre-paint in <head>) -->
                <div x-data="{ dark: document.documentElement.getAttribute('data-theme') === 'dark' }">
                    <button type="button" class="pt-icon-btn"
                            @click="dark = !dark; document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light'); try { localStorage.setItem('ff-theme', dark ? 'dark' : 'light') } catch (e) {}"
                            :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'" :title="dark ? 'Light mode' : 'Dark mode'">
                        <span x-show="!dark"><?= pt_icon('moon') ?></span>
                        <span x-show="dark" x-cloak><?= pt_icon('sun') ?></span>
                    </button>
                </div>

                <!-- Account menu -->
                <div class="pt-pop" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button" class="pt-user-btn" @click="open = !open" :aria-expanded="open" aria-haspopup="menu" aria-label="Account menu">
                        <span class="pt-avatar"><?= e($_initials) ?></span>
                        <?= pt_icon('chevron-down') ?>
                    </button>
                    <div class="pt-menu" x-show="open" x-cloak role="menu"
                         x-transition:enter="pt-pop-enter" x-transition:enter-start="pt-pop-enter-start" x-transition:enter-end="pt-pop-enter-end"
                         x-transition:leave="pt-pop-leave" x-transition:leave-start="pt-pop-leave-start" x-transition:leave-end="pt-pop-leave-end">
                        <div class="pt-menu-head">
                            <span class="pt-avatar pt-avatar--lg"><?= e($_initials) ?></span>
                            <div style="min-width:0">
                                <div class="pt-menu-name"><?= e($_userName) ?></div>
                                <div class="pt-menu-mail"><?= e((string) ($_portalUser['email'] ?? '')) ?></div>
                            </div>
                        </div>
                        <a class="pt-menu-item" role="menuitem" href="<?= e(pt_url('account')) ?>"><?= pt_icon('user-circle') ?> Account settings</a>
                        <?php if (portal_is_primary()): ?>
                        <a class="pt-menu-item" role="menuitem" href="<?= e(pt_url('account/users')) ?>"><?= pt_icon('users') ?> Team members</a>
                        <?php endif; ?>
                        <a class="pt-menu-item" role="menuitem" href="<?= e(pt_url('notifications')) ?>"><?= pt_icon('bell') ?> Notifications</a>
                        <div class="pt-menu-sep"></div>
                        <a class="pt-menu-item pt-menu-item--danger" role="menuitem" href="<?= e(pt_url('auth/logout')) ?>"><?= pt_icon('arrow-right-on-rectangle') ?> Sign out</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="pt-content" id="pt-main" tabindex="-1">
<?php if ($_summary['past_due_count'] > 0 && empty($ptHideRibbon)): ?>
            <div class="pt-ribbon" role="status">
                <?= pt_icon('exclamation-triangle') ?>
                <span class="pt-ribbon-text">
                    <strong><?= e(pt_money($_summary['past_due'], $_summary['currency'])) ?></strong>
                    is past due across <?= (int) $_summary['past_due_count'] ?> invoice<?= $_summary['past_due_count'] === 1 ? '' : 's' ?>.
                </span>
                <a href="<?= e(pt_url('payments')) ?>" class="pt-btn pt-btn--danger pt-btn--sm">Review &amp; pay</a>
            </div>
<?php endif; ?>
<?php
unset($_pageTitle, $_companyName, $_initials, $_portalUnread, $_quickLinks, $_quickJs, $_q, $_userName, $_hasBalance);
?>
