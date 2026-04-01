<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Admin Sidebar
// Included by includes/header.php — do not include directly.
// ============================================================

// ============================================================
// heroicon() — render a Heroicons outline SVG inline
//
// Reads from public/assets/icons/{name}.svg.
// Uses per-request static cache to avoid repeated file reads.
// Returns a placeholder <span> if the file is not found.
// ============================================================
function heroicon(string $name, string $class = 'nav-icon'): string
{
    static $cache = [];

    if (isset($cache[$name])) {
        return $cache[$name];
    }

    $file = FF_ROOT . '/public/assets/icons/' . $name . '.svg';

    if (!file_exists($file)) {
        // Placeholder until icon files are created in the assets phase
        $cache[$name] = '<span class="' . e($class) . ' icon-missing" aria-hidden="true"></span>';
        return $cache[$name];
    }

    $svg = file_get_contents($file);
    if ($svg === false) {
        $cache[$name] = '<span class="' . e($class) . ' icon-missing" aria-hidden="true"></span>';
        return $cache[$name];
    }

    // Inject the class attribute into the <svg> tag
    $svg = preg_replace(
        '/<svg\b/',
        '<svg class="' . e($class) . '" aria-hidden="true"',
        $svg,
        1
    );

    $cache[$name] = (string) $svg;
    return $cache[$name];
}

// ============================================================
// sidebar_badge_count() — query counts for sidebar badges
// Copied verbatim from FLEETFORGE_DESIGN_DETAILS.md §3.
// Wrapped in try/catch — tables may not exist during early dev.
// ============================================================
function sidebar_badge_count(string $key): int
{
    try {
        return match ($key) {
            'overdue_invoices' => db_count(
                "SELECT COUNT(*) FROM invoices WHERE status = 'overdue' AND deleted_at IS NULL",
                []
            ),
            'compliance_alerts' => db_count(
                "SELECT COUNT(DISTINCT id) FROM equipment_units
                 WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
                 AND (cvi_expiry < CURDATE() + INTERVAL 30 DAY
                   OR registration_expiry < CURDATE() + INTERVAL 30 DAY
                   OR mvi_expiry < CURDATE() + INTERVAL 30 DAY
                   OR insurance_expiry < CURDATE() + INTERVAL 30 DAY)",
                []
            ),
            // Open damage claims: reported + assessed + repair_ordered (not yet resolved/written_off)
            'open_damage_claims' => db_count(
                "SELECT COUNT(*) FROM damage_claims
                 WHERE status IN ('reported','assessed','repair_ordered') AND deleted_at IS NULL",
                []
            ),
            default => 0,
        };
    } catch (Throwable) {
        return 0;
    }
}

// ============================================================
// Build the visible nav item list — two-pass approach:
//   Pass 1: flag each item as visible based on permissions.
//   Pass 2: flag each separator visible if any item in its
//           section is visible.
// ============================================================
$_nav    = require FF_ROOT . '/config/navigation.php';
$_vis    = [];
$_nCount = count($_nav);

// Pass 1: items
foreach ($_nav as $_i => $_item) {
    if (isset($_item['separator'])) {
        $_vis[$_i] = false; // tentative — resolved in pass 2
    } elseif (($_item['module'] ?? null) === null) {
        $_vis[$_i] = true;  // module=null → visible to all logged-in users
    } else {
        $_vis[$_i] = can($_item['module'], 'view');
    }
}

// Pass 2: separators — visible if at least one following item (before the
// next separator) is visible.
foreach ($_nav as $_i => $_item) {
    if (!isset($_item['separator'])) continue;

    for ($_j = $_i + 1; $_j < $_nCount; $_j++) {
        if (isset($_nav[$_j]['separator'])) break; // hit next separator
        if ($_vis[$_j]) {
            $_vis[$_i] = true;
            break;
        }
    }
}

// Current path for active-state detection (strip query string)
$_currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

// Company branding
$_companyName = settings_get('company.name', 'FleetForge');
$_logoUrl     = settings_get('company.logo_url', '');

$_sidebarUser = current_user();
?>

<aside class="sidebar" id="ff-sidebar" :class="{ 'is-open': sidebarOpen }">

    <!-- ── Brand / Logo ──────────────────────────────────── -->
    <div class="sidebar-brand">
        <a href="<?= e(base_url('dashboard')) ?>" class="sidebar-brand-link">
            <?php if ($_logoUrl): ?>
                <img src="<?= e($_logoUrl) ?>"
                     alt="<?= e($_companyName) ?>"
                     class="sidebar-logo"
                     loading="lazy">
            <?php else: ?>
                <span class="sidebar-brand-icon" aria-hidden="true">
                    <?= heroicon('truck', 'brand-icon') ?>
                </span>
            <?php endif; ?>
            <span class="sidebar-brand-name"><?= e($_companyName) ?></span>
        </a>

        <!-- Collapse toggle (visible on desktop) -->
        <button class="sidebar-collapse-btn"
                @click="sidebarOpen = !sidebarOpen"
                aria-label="Toggle sidebar"
                title="Toggle sidebar">
            <?= heroicon('chevron-left', 'collapse-icon') ?>
        </button>
    </div>

    <!-- ── Navigation ────────────────────────────────────── -->
    <nav class="sidebar-nav" aria-label="Main navigation">

        <?php foreach ($_nav as $_i => $_item): ?>
            <?php if (!$_vis[$_i]) continue; ?>

            <?php if (isset($_item['separator'])): ?>
                <!-- Section separator -->
                <div class="nav-section-label"><?= e($_item['label']) ?></div>

            <?php else: ?>
                <?php
                // Active state: current path starts with this item's full URL
                $_itemFullUrl = FF_BASE_PATH . $_item['url'];
                $_isActive    = $_currentPath !== '' &&
                                str_starts_with($_currentPath, $_itemFullUrl);

                // Badge count
                $_badgeCount = 0;
                if (!empty($_item['badge'])) {
                    $_badgeCount = sidebar_badge_count($_item['badge']);
                }
                ?>
                <a href="<?= e(base_url(ltrim($_item['url'], '/'))) ?>"
                   class="nav-item<?= $_isActive ? ' is-active' : '' ?>"
                   <?= $_isActive ? 'aria-current="page"' : '' ?>>

                    <span class="nav-item-icon">
                        <?= heroicon($_item['icon']) ?>
                    </span>

                    <span class="nav-item-label"><?= e($_item['label']) ?></span>

                    <?php if ($_badgeCount > 0): ?>
                        <span class="nav-badge" aria-label="<?= e($_badgeCount) ?> items">
                            <?= e($_badgeCount > 99 ? '99+' : (string) $_badgeCount) ?>
                        </span>
                    <?php endif; ?>
                </a>

            <?php endif; ?>
        <?php endforeach; ?>

    </nav>

    <!-- ── User footer ───────────────────────────────────── -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-info">
                <span class="sidebar-user-name"><?= e($_sidebarUser['name'] ?? '') ?></span>
                <span class="sidebar-user-role"><?= e(ucfirst(str_replace('_', ' ', $_sidebarUser['role_slug'] ?? ''))) ?></span>
            </div>
            <div class="sidebar-user-actions">
                <!-- Theme toggle -->
                <button class="btn-icon theme-toggle-btn"
                        onclick="FF_Theme.toggle()"
                        aria-label="Toggle dark/light mode"
                        title="Toggle theme">
                    <?= heroicon('sun', 'theme-icon theme-icon--light') ?>
                    <?= heroicon('moon', 'theme-icon theme-icon--dark') ?>
                </button>

                <!-- Logout -->
                <a href="<?= e(base_url('auth/logout')) ?>"
                   class="btn-icon"
                   aria-label="Log out"
                   title="Log out">
                    <?= heroicon('arrow-right-on-rectangle', 'nav-icon') ?>
                </a>
            </div>
        </div>
    </div>

</aside>

<?php
// Clean up sidebar-scoped variables
unset($_nav, $_vis, $_nCount, $_i, $_j, $_item, $_currentPath,
      $_itemFullUrl, $_isActive, $_badgeCount,
      $_companyName, $_logoUrl, $_sidebarUser);
?>
