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
// Build the nav accessibility map.
//
// S-SIDEBAR-LOCK-ALL-RESTRICTED — semantic change: $_vis[$_i] now
// means "is this item accessible to the current user". Inaccessible
// items still render (as locked greyed-out spans), so the old
// "hide if false" semantics is gone. Separators always render.
// Pass 2 (separator visibility from child visibility) deleted —
// every separator's section now always has visible items below it.
// ============================================================
$_nav    = require FF_ROOT . '/config/navigation.php';
$_vis    = [];
$_nCount = count($_nav);

foreach ($_nav as $_i => $_item) {
    if (isset($_item['separator'])) {
        $_vis[$_i] = true; // separators always render (locked items appear below)
    } elseif (($_item['module'] ?? null) === null) {
        $_vis[$_i] = true;  // module=null → accessible to all logged-in users
    } else {
        $_vis[$_i] = can($_item['module'], 'view');
    }

    // Per-child accessibility flag (same semantic flip as parent).
    if (!empty($_item['children'])) {
        foreach ($_item['children'] as $_ci => $_child) {
            $_nav[$_i]['children'][$_ci]['_visible'] =
                (($_child['module'] ?? null) === null) || can($_child['module'], 'view');
        }
    }
}

// Current path for active-state detection (strip query string)
$_currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

// Company branding
// S-DESIGN-SETTINGS-DEBUG: prior code read 'company.logo_url' which was
// never written anywhere. The Design tab (S-DESIGN-SETTINGS-FOOTER-LOGIN)
// saves the upload as a StorageClient key under brand.logo_path; we sign
// it here so the sidebar serves it via the same signed-URL pathway as
// every other uploaded file.
$_companyName = settings_get('company.name', 'FleetForge');
$_logoKey     = (string) (settings_get('brand.logo_path') ?? '');
$_logoUrl     = $_logoKey !== ''
    ? \FleetForge\Storage\StorageClient::url($_logoKey, 86400)
    : '';

$_sidebarUser = current_user();
?>

<aside class="sidebar" id="ff-sidebar" :class="{ 'is-open': sidebarOpen }">

    <!-- ── Brand / Logo ──────────────────────────────────── -->
    <!-- S-DESIGN-LOGO-TOPBAR: company name moved to topbar. Sidebar
         now shows only the logo (or fallback truck icon) so the upload
         can render at a readable size without competing with text. -->
    <div class="sidebar-brand">
        <a href="<?= e(base_url('dashboard')) ?>"
           class="sidebar-brand-link"
           aria-label="<?= e($_companyName) ?> — Dashboard">
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

            <?php if (isset($_item['separator'])): ?>
                <!-- Section separator (S-SIDEBAR-LOCK-ALL-RESTRICTED: always rendered) -->
                <div class="nav-section-label"><?= e($_item['label']) ?></div>

            <?php elseif (!$_vis[$_i]): ?>
                <?php /* S-SIDEBAR-LOCK-ALL-RESTRICTED — locked variant.
                         pointer-events:none in CSS makes it non-clickable;
                         the .nav-lock-icon span is the small lock badge,
                         pinned bottom-right of the icon in collapsed mode
                         and right-aligned via margin-left:auto in expanded
                         mode. Parent items with children that are locked
                         only render this top-level span — the children
                         block is skipped since the user can't expand the
                         group anyway. */ ?>
                <span class="nav-item nav-item--locked"
                      title="You don't have access to <?= e($_item['label']) ?>">
                    <span class="nav-item-icon nav-icon--locked">
                        <?= heroicon($_item['icon']) ?>
                    </span>
                    <span class="nav-item-label"><?= e($_item['label']) ?></span>
                    <span class="nav-lock-icon" aria-hidden="true">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5"
                             xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </span>
                </span>

            <?php else: ?>
                <?php
                // ── Active-state detection ─────────────────────────
                // WHY: We support an optional `match_prefix` field (string or
                // array of strings) so a single nav item can "claim" multiple
                // URL prefixes. Example: a "General Ledger" group item links
                // to /accounting/ledger as its primary URL but also highlights
                // when the user is on /accounting/chart-of-accounts or
                // /accounting/journal-entries. This is essential for the
                // accounting sidebar which mirrors the grouped topnav.
                //
                // Falls back to $_item['url'] when match_prefix is not set,
                // preserving backward compatibility with all existing items.
                $_matchPrefixes = (array) ($_item['match_prefix'] ?? $_item['url']);
                $_isActive      = false;
                if ($_currentPath !== '') {
                    foreach ($_matchPrefixes as $_mp) {
                        if (str_starts_with($_currentPath, FF_BASE_PATH . $_mp)) {
                            $_isActive = true;
                            break;
                        }
                    }
                }

                // Check if any child is active (expands the parent group)
                $_hasChildren  = !empty($_item['children']);
                $_childActive  = false;
                if ($_hasChildren && $_currentPath !== '') {
                    foreach ($_item['children'] as $_child) {
                        $_childPrefixes = (array) ($_child['match_prefix'] ?? $_child['url']);
                        foreach ($_childPrefixes as $_cp) {
                            if (str_starts_with($_currentPath, FF_BASE_PATH . $_cp)) {
                                $_childActive = true;
                                break 2;
                            }
                        }
                    }
                }
                $_groupOpen = $_isActive || $_childActive;

                // Badge count
                $_badgeCount = 0;
                if (!empty($_item['badge'])) {
                    $_badgeCount = sidebar_badge_count($_item['badge']);
                }
                ?>

                <?php if ($_hasChildren): ?>
                    <!-- Parent item with children — collapsible group -->
                    <div class="nav-group<?= $_groupOpen ? ' is-open' : '' ?>">
                        <a href="<?= e(base_url(ltrim($_item['url'], '/'))) ?>"
                           class="nav-item<?= $_isActive ? ' is-active' : '' ?>"
                           aria-label="<?= e($_item['label']) ?>"
                           <?= $_isActive ? 'aria-current="page"' : '' ?>>

                            <span class="nav-item-icon">
                                <?= heroicon($_item['icon']) ?>
                            </span>

                            <span class="nav-item-label"><?= e($_item['label']) ?></span>

                            <span class="nav-group-arrow" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="14" height="14">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                                </svg>
                            </span>
                        </a>

                        <div class="nav-children">
                            <?php foreach ($_item['children'] as $_child): ?>
                                <?php if (empty($_child['_visible'])): ?>
                                    <?php /* S-SIDEBAR-LOCK-ALL-RESTRICTED — locked child variant. */ ?>
                                    <span class="nav-item nav-item--child nav-item--locked"
                                          title="You don't have access to <?= e($_child['label']) ?>">
                                        <span class="nav-item-icon nav-icon--locked">
                                            <?= heroicon($_child['icon']) ?>
                                        </span>
                                        <span class="nav-item-label"><?= e($_child['label']) ?></span>
                                        <span class="nav-lock-icon" aria-hidden="true">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                                                 stroke="currentColor" stroke-width="2.5"
                                                 xmlns="http://www.w3.org/2000/svg">
                                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                            </svg>
                                        </span>
                                    </span>
                                <?php else:
                                    // Children also honor match_prefix (see note above)
                                    $_childPrefixes = (array) ($_child['match_prefix'] ?? $_child['url']);
                                    $_childIsActive = false;
                                    if ($_currentPath !== '') {
                                        foreach ($_childPrefixes as $_cp) {
                                            if (str_starts_with($_currentPath, FF_BASE_PATH . $_cp)) {
                                                $_childIsActive = true;
                                                break;
                                            }
                                        }
                                    }
                                    $_childBadge = 0;
                                    if (!empty($_child['badge'])) {
                                        $_childBadge = sidebar_badge_count($_child['badge']);
                                    }
                                ?>
                                    <a href="<?= e(base_url(ltrim($_child['url'], '/'))) ?>"
                                       class="nav-item nav-item--child<?= $_childIsActive ? ' is-active' : '' ?>"
                                       aria-label="<?= e($_child['label']) ?>"
                                       <?= $_childIsActive ? 'aria-current="page"' : '' ?>>

                                        <span class="nav-item-icon">
                                            <?= heroicon($_child['icon']) ?>
                                        </span>

                                        <span class="nav-item-label"><?= e($_child['label']) ?></span>

                                        <?php if ($_childBadge > 0): ?>
                                            <span class="nav-badge" aria-label="<?= e($_childBadge) ?> items">
                                                <?= e($_childBadge > 99 ? '99+' : (string) $_childBadge) ?>
                                            </span>
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Regular nav item -->
                    <a href="<?= e(base_url(ltrim($_item['url'], '/'))) ?>"
                       class="nav-item<?= $_isActive ? ' is-active' : '' ?>"
                       aria-label="<?= e($_item['label']) ?>"
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

<script>
// ============================================================
// SIDEBAR-SCROLL-1 — scroll active nav item into view
//
// On short viewports the active item lives below the fold of
// .sidebar-nav so the user can't see which page they're on.
// Chrome's `scrollIntoView({block:'nearest'})` silently no-ops
// in some layouts when the element is below a scrollable flex
// child — so we compute the target scrollTop manually (center
// the active item in the nav slot) which works reliably in
// every engine.
//
// Runs once after DOM is ready. `behavior: auto` (instant)
// avoids a visible scroll animation on initial paint while
// Alpine is still mounting group state.
// ============================================================
(function () {
    var scrollActiveIntoView = function () {
        var nav = document.querySelector('#ff-sidebar .sidebar-nav');
        if (!nav) return;
        var active = nav.querySelector('.nav-item.is-active, .nav-child-item.is-active');
        if (!active) return;

        // If the active item is already fully visible inside the nav's
        // scroll viewport, bail — no need to scroll.
        var navBox    = nav.getBoundingClientRect();
        var activeBox = active.getBoundingClientRect();
        if (activeBox.top >= navBox.top && activeBox.bottom <= navBox.bottom) {
            return;
        }

        // Compute a scrollTop that centers the active item in the nav.
        // Clamp to [0, maxScroll] so we never overshoot.
        var targetTop = active.offsetTop - (nav.clientHeight / 2) + (active.offsetHeight / 2);
        var maxScroll = nav.scrollHeight - nav.clientHeight;
        targetTop = Math.max(0, Math.min(targetTop, maxScroll));

        var prevBehavior = nav.style.scrollBehavior;
        nav.style.scrollBehavior = 'auto';
        nav.scrollTop = targetTop;
        nav.style.scrollBehavior = prevBehavior;
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scrollActiveIntoView, { once: true });
    } else {
        scrollActiveIntoView();
    }
})();

// ============================================================
// S-SIDEBAR-COLLAPSED-TOOLTIP — show module name on hover when
// the sidebar is collapsed.
//
// Why JS and not pure CSS: the outer .sidebar has overflow:hidden
// and the inner .sidebar-nav has overflow-x:hidden (required for
// the vertical scroll setup). A CSS-positioned tooltip inside a
// .nav-item gets clipped at the sidebar edge. We escape every
// clipping context by appending the tooltip to <body> and using
// position:fixed with coordinates from the nav-item's
// getBoundingClientRect() — same pattern Linear / Notion / Figma
// use for their sidebar tooltips.
//
// Only fires when the sidebar is in collapsed state, evaluated
// per hover (so toggling expand/collapse mid-session is handled):
//   - Desktop (>=1024px): collapsed = .sidebar without .is-open
//   - Tablet  (768-1023px): always collapsed (permanent 64px rail)
//   - Mobile  (<768px): sidebar is an overlay drawer with the full
//     label visible when open, no tooltip needed.
// ============================================================
(function setupSidebarTooltips() {
    var sidebar = document.getElementById('ff-sidebar');
    if (!sidebar) return;

    var tooltip = null;
    var hideTimer = null;

    function isCollapsed() {
        var w = window.innerWidth;
        if (w >= 1024) return !sidebar.classList.contains('is-open');
        if (w >= 768)  return true;   // tablet — permanent rail
        return false;                  // mobile — full-label drawer
    }

    function ensureTip() {
        if (tooltip) return tooltip;
        tooltip = document.createElement('div');
        tooltip.className = 'ff-nav-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        document.body.appendChild(tooltip);
        return tooltip;
    }

    function readLabel(item) {
        var labelEl = item.querySelector('.nav-item-label');
        return labelEl ? labelEl.textContent.trim() : '';
    }

    function show(e) {
        if (!isCollapsed()) return;
        var item = e.currentTarget;
        var text = readLabel(item);
        if (!text) return;

        var tip = ensureTip();
        if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }

        tip.textContent = text;

        // Position to the right of the nav-item, vertically centered.
        // 8px gap from the sidebar edge so the tooltip doesn't touch
        // the icon's hover surface.
        var rect = item.getBoundingClientRect();
        tip.style.left = (rect.right + 8) + 'px';
        tip.style.top  = (rect.top + rect.height / 2) + 'px';
        tip.classList.add('is-visible');
    }

    function hide() {
        if (!tooltip) return;
        // Tiny delay so tooltip doesn't flicker if the mouse moves
        // between adjacent nav-items in quick succession.
        hideTimer = setTimeout(function () {
            tooltip.classList.remove('is-visible');
        }, 40);
    }

    sidebar.querySelectorAll('.nav-item').forEach(function (item) {
        item.addEventListener('mouseenter', show);
        item.addEventListener('mouseleave', hide);
        item.addEventListener('focus',  show);
        item.addEventListener('blur',   hide);
    });
})();
</script>

<?php
// Clean up sidebar-scoped variables
unset($_nav, $_vis, $_nCount, $_i, $_j, $_item, $_currentPath,
      $_matchPrefixes, $_mp, $_isActive, $_badgeCount, $_hasChildren,
      $_childActive, $_groupOpen, $_child, $_childPrefixes, $_cp,
      $_childIsActive, $_childBadge, $_ci,
      $_companyName, $_logoKey, $_logoUrl, $_sidebarUser);
?>
