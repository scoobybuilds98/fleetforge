<?php declare(strict_types=1);

/**
 * includes/partials/accounting-nav.php
 *
 * Horizontal sub-navigation bar for all accounting pages.
 *
 * The nav is grouped into 6 functional categories so the bar stays at a
 * manageable width even though the accounting module has ~20 pages. Items
 * with sub-pages render as Alpine.js dropdown menus; standalone destinations
 * (Dashboard, Periods, Settings) render as direct links.
 *
 * Layout:
 *   [Dashboard] [General Ledger ▾] [Receivables ▾] [Payables ▾]
 *   [Banking ▾] [Fixed Assets ▾]                    [Periods] [Settings]
 *
 * Settings and Periods are pushed right (margin-left:auto on Periods) since
 * they are high-traffic admin destinations and conventionally sit on the
 * right of a navigation bar.
 *
 * Active-state highlighting is computed server-side: a group is "active"
 * if the current URL matches any of its children, and a leaf link is
 * "active" if the current URL starts with its URL.
 *
 * Usage: require_once FF_ROOT . '/includes/partials/accounting-nav.php';
 *
 * @session S028-b (initial flat nav) → S029 (grouped redesign)
 */

// ── Determine current page (for active-state highlighting) ─────
// WHY: Strip the FF_BASE_PATH prefix so matches work the same way under
// any deployment subdir, and strip the /app/admin prefix as well so the
// match works whether we are hit through the front controller (production
// Apache) or directly via php -S (dev). Also strip a trailing /index.php
// because plain directory URLs and explicit index.php URLs both reach the
// same page.
$_accNavCurrent = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$_accNavCurrent = str_replace(FF_BASE_PATH, '', $_accNavCurrent);
if (str_starts_with($_accNavCurrent, '/app/admin')) {
    $_accNavCurrent = substr($_accNavCurrent, strlen('/app/admin'));
}
if (str_ends_with($_accNavCurrent, '/index.php')) {
    $_accNavCurrent = substr($_accNavCurrent, 0, -strlen('/index.php'));
} elseif (str_ends_with($_accNavCurrent, '.php')) {
    // Single-file page like accounting/reports/trial-balance.php
    $_accNavCurrent = substr($_accNavCurrent, 0, -strlen('.php'));
}

// ── Nav structure ──────────────────────────────────────────────
// Each top-level entry is either a leaf (has 'url') or a group (has 'children').
// 'pin' => 'right' pushes the item to the right side of the bar.
$_accNavGroups = [
    [
        'label' => 'Dashboard',
        'url'   => '/accounting/dashboard',
        'icon'  => 'home',
    ],
    [
        'label' => 'General Ledger',
        'icon'  => 'book-open',
        'children' => [
            ['label' => 'Chart of Accounts', 'url' => '/accounting/chart-of-accounts',       'icon' => 'list-bullet'],
            ['label' => 'Journal Entries',   'url' => '/accounting/journal-entries',         'icon' => 'pencil-square'],
            ['label' => 'Ledger',            'url' => '/accounting/ledger',                  'icon' => 'folder-open'],
        ],
    ],
    [
        'label' => 'Reports',
        'icon'  => 'chart-bar',
        'children' => [
            ['label' => 'Profit & Loss',   'url' => '/accounting/reports/profit-loss',     'icon' => 'chart-bar'],
            ['label' => 'Balance Sheet',   'url' => '/accounting/reports/balance-sheet',   'icon' => 'scale'],
            ['label' => 'Cash Flow',       'url' => '/accounting/reports/cash-flow',       'icon' => 'banknotes'],
            ['label' => 'Asset Schedule',  'url' => '/accounting/reports/asset-schedule',  'icon' => 'truck'],
            ['label' => 'Trial Balance',   'url' => '/accounting/reports/trial-balance',   'icon' => 'scale'],
            ['label' => 'Working Trial Balance', 'url' => '/accounting/reports/working-trial-balance', 'icon' => 'clipboard-document-check'],
            ['label' => 'Book vs Tax Differences', 'url' => '/accounting/reports/book-tax-differences', 'icon' => 'document-text'],
        ],
    ],
    [
        'label' => 'Budgets',
        'url'   => '/accounting/budgets',
        'icon'  => 'document-text',
    ],
    [
        'label' => 'FX Revaluation',
        'url'   => '/accounting/fx-revaluations',
        'icon'  => 'currency-dollar',
    ],
    [
        'label' => 'Year-End',
        'url'   => '/accounting/year-end',
        'icon'  => 'calendar-days',
    ],
    [
        'label' => 'Recurring JEs',
        'url'   => '/accounting/recurring-entries',
        'icon'  => 'arrow-path',
    ],
    [
        'label' => 'Receivables',
        'icon'  => 'inbox-arrow-down',
        'children' => [
            ['label' => 'AR Aging',    'url' => '/accounting/ar-aging',    'icon' => 'clock'],
            ['label' => 'Statements',  'url' => '/accounting/statements',  'icon' => 'document-text'],
            ['label' => 'Collections', 'url' => '/accounting/collections', 'icon' => 'phone'],
            ['label' => 'Deposits',    'url' => '/accounting/deposits',    'icon' => 'banknotes'],
        ],
    ],
    [
        'label' => 'Payables',
        'icon'  => 'document-text',
        'children' => [
            ['label' => 'Bills',          'url' => '/accounting/bills',          'icon' => 'document-text'],
            ['label' => 'Payments',       'url' => '/accounting/ap-payments',    'icon' => 'banknotes'],
            ['label' => 'AP Aging',       'url' => '/accounting/ap-aging',       'icon' => 'clock'],
            ['label' => 'Vendor Credits', 'url' => '/accounting/vendor-credits', 'icon' => 'banknotes'],
        ],
    ],
    [
        'label' => 'Banking',
        'icon'  => 'building-library',
        'children' => [
            ['label' => 'Bank Accounts',  'url' => '/accounting/bank-accounts',       'icon' => 'building-library'],
            ['label' => 'Transactions',   'url' => '/accounting/bank-transactions',   'icon' => 'list-bullet'],
            ['label' => 'Reconciliation', 'url' => '/accounting/bank-reconciliation', 'icon' => 'scale'],
        ],
    ],
    [
        'label' => 'Fixed Assets',
        'icon'  => 'truck',
        'children' => [
            ['label' => 'Asset Register',   'url' => '/accounting/fixed-assets',                'icon' => 'truck'],
            ['label' => 'Payoff Report',    'url' => '/accounting/fixed-assets/payoff-report',  'icon' => 'calculator'],
            ['label' => 'Depreciation',     'url' => '/accounting/depreciation',                'icon' => 'calculator'],
            ['label' => 'CCA Schedule 8',   'url' => '/accounting/cca',                         'icon' => 'document-text'],
            ['label' => 'CapEx Requests',   'url' => '/accounting/capex',                       'icon' => 'banknotes'],
        ],
    ],
    [
        // S035 — Tax Management module. GST/HST + PST filing periods,
        // calculation, mark-filed, and remittance posting on the main
        // tax page. S037-CRUD added the standalone Remittances list
        // for audit-trail visibility across all filed periods.
        'label' => 'Tax',
        'icon'  => 'receipt-percent',
        'children' => [
            ['label' => 'GST/HST Filing', 'url' => '/accounting/tax',              'icon' => 'receipt-percent'],
            ['label' => 'Remittances',    'url' => '/accounting/tax/remittances',  'icon' => 'banknotes'],
        ],
    ],
    [
        'label' => 'Periods',
        'url'   => '/accounting/periods',
        'icon'  => 'calendar-days',
        'pin'   => 'right',
    ],
    [
        'label' => 'Settings',
        'url'   => '/accounting/settings',
        'icon'  => 'cog-6-tooth',
    ],
];

// ── Active-state computation ───────────────────────────────────
// WHY: We compute active state in PHP so the correct item is highlighted
// on the first paint, not after Alpine hydrates. A group is active if any
// of its children match the current URL prefix.
$_accIsActive = static function (string $url) use ($_accNavCurrent): bool {
    if ($url === '') return false;
    return $_accNavCurrent === $url || str_starts_with($_accNavCurrent, $url . '/');
};

$_accGroupActive = static function (array $g) use ($_accIsActive): bool {
    if (isset($g['url']) && $_accIsActive($g['url'])) return true;
    foreach ($g['children'] ?? [] as $child) {
        if ($_accIsActive($child['url'])) return true;
    }
    return false;
};

// ── Inline SVG icon helper ─────────────────────────────────────
// WHY: We can't use the global heroicon() helper because it lives inside
// sidebar.php and may not be loaded yet on every page. Inline read is fine
// because the file is already cached by the OS after first read.
//
// The Heroicons source SVGs ship without explicit width/height attrs (they
// inherit the viewBox), and they DO have a stroke-width attribute. We must
// avoid touching stroke-width while still injecting the desired pixel size,
// so we insert width/height immediately after `<svg ` rather than trying to
// regex-replace existing attributes.
$_accIcon = static function (string $name, int $size = 16): string {
    static $cache = [];
    $key = $name . ':' . $size;
    if (isset($cache[$key])) return $cache[$key];

    $file = FF_ROOT . '/public/assets/icons/' . $name . '.svg';
    if (!is_file($file)) return $cache[$key] = '';

    $svg = (string) file_get_contents($file);
    // Strip any pre-existing width/height that aren't part of stroke-width
    $svg = preg_replace('/(?<![\w-])width="[^"]*"/',  '', $svg);
    $svg = preg_replace('/(?<![\w-])height="[^"]*"/', '', $svg);
    // Insert fresh width/height right after the opening <svg tag
    $svg = preg_replace('/<svg\s/', '<svg width="' . $size . '" height="' . $size . '" ', $svg, 1);
    return $cache[$key] = $svg;
};
?>

<nav class="acc-topnav" aria-label="Accounting sub-navigation">
<?php foreach ($_accNavGroups as $group):
    $hasChildren = !empty($group['children']);
    $isActive    = $_accGroupActive($group);
    $pinClass    = ($group['pin'] ?? '') === 'right' ? ' acc-topnav-pin-right' : '';
?>

    <?php if (!$hasChildren): ?>
        <!-- Leaf link (no dropdown) -->
        <a href="<?= e(base_url(ltrim($group['url'], '/'))) ?>"
           class="acc-topnav-link<?= $isActive ? ' acc-topnav-active' : '' ?><?= $pinClass ?>">
            <?= $_accIcon($group['icon']) ?>
            <span><?= e($group['label']) ?></span>
        </a>

    <?php else: ?>
        <!-- Group dropdown -->
        <div class="acc-topnav-group<?= $pinClass ?>"
             x-data="{ open: false }"
             @click.outside="open = false"
             @keydown.escape.window="open = false">

            <button type="button"
                    class="acc-topnav-link acc-topnav-trigger<?= $isActive ? ' acc-topnav-active' : '' ?>"
                    :class="{ 'acc-topnav-open': open }"
                    @click="open = !open"
                    :aria-expanded="open.toString()"
                    aria-haspopup="menu">
                <?= $_accIcon($group['icon']) ?>
                <span><?= e($group['label']) ?></span>
                <span class="acc-topnav-chevron" :class="{ 'acc-topnav-chevron-open': open }">
                    <?= $_accIcon('chevron-down', 14) ?>
                </span>
            </button>

            <div class="acc-topnav-dropdown"
                 x-show="open"
                 x-cloak
                 x-transition:enter="dropdown-enter"
                 x-transition:enter-start="dropdown-enter-start"
                 x-transition:enter-end="dropdown-enter-end"
                 x-transition:leave="dropdown-leave"
                 x-transition:leave-start="dropdown-leave-start"
                 x-transition:leave-end="dropdown-leave-end"
                 role="menu"
                 :aria-label="'<?= e($group['label']) ?> menu'">
                <p class="acc-topnav-dropdown-label"><?= e($group['label']) ?></p>
                <?php foreach ($group['children'] as $child):
                    $childActive = $_accIsActive($child['url']);
                ?>
                    <a href="<?= e(base_url(ltrim($child['url'], '/'))) ?>"
                       class="acc-topnav-dropdown-item<?= $childActive ? ' acc-topnav-dropdown-item-active' : '' ?>"
                       role="menuitem">
                        <?= $_accIcon($child['icon']) ?>
                        <span><?= e($child['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

<?php endforeach; ?>
</nav>
