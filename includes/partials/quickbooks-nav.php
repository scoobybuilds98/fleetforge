<?php declare(strict_types=1);

/**
 * includes/partials/quickbooks-nav.php
 *
 * Horizontal sub-navigation bar for QuickBooks admin pages. Mirrors the
 * accounting-nav.php pattern so QBO sub-pages have first-class navigation
 * regardless of sidebar state (collapsed sidebar hides children for every
 * parent group — without a top-nav, operators on QBO pages with the
 * sidebar collapsed had no way to reach the other QBO pages).
 *
 * Six destinations, all flat leaf links (no dropdowns since the QBO sub-
 * surface is small enough to fit on one row):
 *
 *   [Dashboard] [Sync Queue] [Sync Log] [Drift] [Customers] [Vendors] [Accounts] [Tax Codes]      [Settings]
 *
 * Settings is pinned right (matches the accounting-nav convention for
 * high-traffic admin destinations).
 *
 * Reuses the .acc-topnav* CSS classes from app.css (styles are generic;
 * the "acc-" prefix is convention not coupling).
 *
 * Usage: require_once FF_ROOT . '/includes/partials/quickbooks-nav.php';
 *
 * @session S-QBO-5 (post-ship UX patch — operator caught the gap when the
 *          sidebar was in collapsed mode and the QBO sub-pages had no
 *          alternative navigation surface)
 */

// ── Determine current page (for active-state highlighting) ─────
// Strip FF_BASE_PATH so matches work under any deployment subdir, strip
// /app/admin so direct file URLs (php -S) match the same way as front-
// controller URLs, and strip trailing /index.php or .php so we compare
// canonical paths only.
$_qboNavCurrent = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$_qboNavCurrent = str_replace(FF_BASE_PATH, '', $_qboNavCurrent);
if (str_starts_with($_qboNavCurrent, '/app/admin')) {
    $_qboNavCurrent = substr($_qboNavCurrent, strlen('/app/admin'));
}
if (str_ends_with($_qboNavCurrent, '/index.php')) {
    $_qboNavCurrent = substr($_qboNavCurrent, 0, -strlen('/index.php'));
} elseif (str_ends_with($_qboNavCurrent, '.php')) {
    $_qboNavCurrent = substr($_qboNavCurrent, 0, -strlen('.php'));
}

// ── Nav structure ──────────────────────────────────────────────
// Order matches the sidebar children order (Dashboard / Sync Queue /
// Sync Log / Drift / Customers / Vendors / Accounts / Tax Codes /
// Settings) so operators have a single mental model across both
// navigation surfaces.
$_qboNavGroups = [
    [
        'label' => 'Dashboard',
        'url'   => '/quickbooks/dashboard',
        'icon'  => 'home',
    ],
    [
        'label' => 'Sync Queue',
        'url'   => '/quickbooks/sync_queue',
        'icon'  => 'inbox-arrow-down',
    ],
    [
        'label' => 'Sync Log',
        'url'   => '/quickbooks/sync_log',
        'icon'  => 'clipboard-document-list',
    ],
    [
        'label' => 'Drift',
        'url'   => '/quickbooks/drift',
        'icon'  => 'exclamation-triangle',
    ],
    [
        'label' => 'Customers',
        'url'   => '/quickbooks/customers',
        'icon'  => 'users',
    ],
    [
        'label' => 'Vendors',
        'url'   => '/quickbooks/vendors',
        'icon'  => 'building-storefront',
    ],
    [
        'label' => 'Accounts',
        'url'   => '/quickbooks/accounts',
        'icon'  => 'book-open',
    ],
    [
        'label' => 'Tax Codes',
        'url'   => '/quickbooks/tax_codes',
        'icon'  => 'receipt-percent',
    ],
    [
        'label' => 'Settings',
        'url'   => '/quickbooks/settings',
        'icon'  => 'cog-6-tooth',
        'pin'   => 'right',
    ],
];

// ── Active-state computation ───────────────────────────────────
// A link is active when its URL is a prefix of the current path. Used
// for the highlighted-tab styling.
$_qboIsActive = static function (string $url) use ($_qboNavCurrent): bool {
    if ($url === '') return false;
    return $_qboNavCurrent === $url || str_starts_with($_qboNavCurrent, $url . '/');
};

// ── Inline SVG icon helper ─────────────────────────────────────
// Lifted verbatim from accounting-nav.php — we can't use the global
// heroicon() helper because it lives inside sidebar.php which isn't
// guaranteed to be loaded before this partial.
$_qboIcon = static function (string $name, int $size = 16): string {
    static $cache = [];
    $key = $name . ':' . $size;
    if (isset($cache[$key])) return $cache[$key];

    $file = FF_ROOT . '/public/assets/icons/' . $name . '.svg';
    if (!is_file($file)) return $cache[$key] = '';

    $svg = (string) file_get_contents($file);
    $svg = preg_replace('/(?<![\w-])width="[^"]*"/',  '', $svg);
    $svg = preg_replace('/(?<![\w-])height="[^"]*"/', '', $svg);
    $svg = preg_replace('/<svg\s/', '<svg width="' . $size . '" height="' . $size . '" ', $svg, 1);
    return $cache[$key] = $svg;
};
?>

<nav class="acc-topnav" aria-label="QuickBooks sub-navigation">
<?php foreach ($_qboNavGroups as $group):
    $isActive = $_qboIsActive($group['url']);
    $pinClass = ($group['pin'] ?? '') === 'right' ? ' acc-topnav-pin-right' : '';
?>
    <a href="<?= e(base_url(ltrim($group['url'], '/'))) ?>"
       class="acc-topnav-link<?= $isActive ? ' acc-topnav-active' : '' ?><?= $pinClass ?>">
        <?= $_qboIcon($group['icon']) ?>
        <span><?= e($group['label']) ?></span>
    </a>
<?php endforeach; ?>
</nav>
