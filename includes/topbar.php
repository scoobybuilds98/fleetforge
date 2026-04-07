<?php
declare(strict_types=1);

/**
 * includes/topbar.php
 *
 * Admin topbar — sticky header rendered inside every authenticated page.
 * Included by includes/header.php; never include directly.
 *
 * Renders (left → right):
 *   LEFT  : mobile sidebar toggle · page title
 *   RIGHT : Quick-create (+) dropdown · search ⌘K · theme toggle ·
 *           notifications bell · user-avatar dropdown (profile, settings, sign out)
 *
 * Alpine.js handles all dropdown open/close state.
 * Theme toggle calls FF_Theme.toggle() from public/assets/js/app.js.
 * Logout uses GET to app/auth/logout.php — no CSRF required (D29).
 *
 * heroicon() is defined in includes/sidebar.php and accepts (name, class) only.
 * heroicon() caches by $name; always use 'nav-icon' class for consistency.
 *
 * @depends  includes/auth.php  (current_user, can, current_user_id)
 *           includes/helpers.php (heroicon, base_url, e)
 *           public/assets/js/app.js (FF_Theme.toggle)
 *           public/assets/css/app.css (topbar-* · user-avatar-* · topbar-create-*)
 * @session  S009 (enhanced)
 */

// ── Notification unread count ─────────────────────────────────────────────────
// Wrapped in try/catch so the topbar renders before the notifications table exists.
$_unreadCount = 0;
try {
    $_uid = current_user_id();
    if ($_uid) {
        $_unreadCount = db_count(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
            [$_uid]
        );
    }
    unset($_uid);
} catch (Throwable) {
    $_unreadCount = 0;
}

// ── Current user ──────────────────────────────────────────────────────────────
$_me = current_user() ?? [];

// Avatar initials: first char of first word + first char of last word.
$_initials = 'U';
if (!empty($_me['name'])) {
    $__parts   = preg_split('/\s+/', trim($_me['name']));
    $_initials = strtoupper(mb_substr($__parts[0], 0, 1));
    if (count($__parts) > 1) {
        $_initials .= strtoupper(mb_substr(end($__parts), 0, 1));
    }
    unset($__parts);
}

// Human-readable role label.
$_roleMap = [
    'super_admin' => 'Super Admin',
    'manager'     => 'Manager',
    'dispatcher'  => 'Dispatcher',
    'accountant'  => 'Accountant',
];
$_roleLabel = $_roleMap[$_me['role_slug'] ?? '']
           ?? ucwords(str_replace('_', ' ', $_me['role_slug'] ?? 'User'));

// Quick-create items: permission-gated shortcuts shown in the "+" dropdown.
// Icons must exist in public/assets/icons/ (checked against disk, S009).
$_creates = [];
if (can('customers', 'create')) {
    $_creates[] = ['New Customer',   base_url('customers/create'),  'users'];
}
if (can('leases', 'create')) {
    $_creates[] = ['New Lease',      base_url('leases/create'),     'clipboard-document-list'];
}
if (can('invoices', 'create')) {
    $_creates[] = ['New Invoice',    base_url('invoices/create'),   'document-text'];
}
if (can('payments', 'create')) {
    $_creates[] = ['Record Payment', base_url('payments/create'),   'credit-card'];
}

$_topbarTitle = isset($pageTitle) ? trim($pageTitle) : '';
?>

<header class="topbar" role="banner">

    <!-- ================================================================
         LEFT: mobile sidebar toggle + page title
         ================================================================ -->
    <div class="topbar-left">

        <!-- Legacy hamburger (kept for backward compatibility; hidden on tablet
             and mobile by RESPONSIVE-1 rules — replaced by .hamburger-btn). -->
        <button class="topbar-menu-btn btn-icon"
                @click="sidebarOpen = !sidebarOpen"
                aria-label="Toggle navigation menu"
                aria-expanded="sidebarOpen"
                aria-controls="ff-sidebar">
            <?= heroicon('bars-3', 'nav-icon') ?>
        </button>

        <!-- RESPONSIVE-1 hamburger — visible on mobile (<768px) only, 44px tap target -->
        <button class="hamburger-btn"
                @click="sidebarOpen = !sidebarOpen"
                :aria-expanded="sidebarOpen"
                aria-controls="ff-sidebar"
                aria-label="Open navigation menu">
            <?= heroicon('bars-3', 'nav-icon') ?>
        </button>

        <a href="<?= base_url('dashboard') ?>" class="btn-icon topbar-home-btn" aria-label="Dashboard">
            <?= heroicon('home', 'nav-icon') ?>
        </a>

        <?php if ($_topbarTitle !== ''): ?>
            <h1 class="topbar-title"><?= e($_topbarTitle) ?></h1>
        <?php endif; ?>

    </div>

    <!-- ================================================================
         RIGHT: quick-create · search · theme · bell · user avatar
         ================================================================ -->
    <div class="topbar-right">

        <!-- ── Quick-create "+" dropdown ─────────────────────────────── -->
        <?php if (!empty($_creates)): ?>
        <div class="topbar-create"
             x-data="{ open: false }"
             @click.outside="open = false"
             @keydown.escape.window="open = false">

            <button class="topbar-create-btn"
                    @click="open = !open"
                    :aria-expanded="open"
                    aria-haspopup="true"
                    aria-label="Quick create">
                <!-- Inline "+" SVG — no file dependency -->
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                     fill="currentColor" aria-hidden="true"
                     style="width:15px;height:15px;flex-shrink:0;">
                    <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"/>
                </svg>
                <span>New</span>
            </button>

            <div class="topbar-create-dropdown"
                 x-show="open"
                 x-transition:enter="dropdown-enter"
                 x-transition:enter-start="dropdown-enter-start"
                 x-transition:enter-end="dropdown-enter-end"
                 x-transition:leave="dropdown-leave"
                 x-transition:leave-start="dropdown-leave-start"
                 x-transition:leave-end="dropdown-leave-end"
                 role="menu"
                 aria-label="Quick create">

                <p class="topbar-dropdown-label">Create</p>

                <?php foreach ($_creates as [$_label, $_url, $_icon]): ?>
                    <a href="<?= e($_url) ?>"
                       class="topbar-create-item"
                       role="menuitem">
                        <?= heroicon($_icon, 'nav-icon') ?>
                        <?= e($_label) ?>
                    </a>
                <?php endforeach; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- ── Global search (SEARCH-1 — inline input with dropdown) ─── -->
        <!-- WHY FF_SearchWidget: Alpine component owns query/open/loading/groups
             state; @input.debounce.300ms throttles API calls; Enter + icon-click
             force-fire search; Escape/click-outside close the dropdown. -->
        <div class="search-wrapper"
             x-data="FF_SearchWidget()"
             @click.outside="close()">

            <div class="search-input-wrap">
                <button type="button"
                        class="search-input-icon"
                        @click="search()"
                        aria-label="Search">
                    <?= heroicon('magnifying-glass', 'search-icon') ?>
                </button>

                <input type="text"
                       class="search-input"
                       placeholder="Search customers, units, leases…"
                       x-model="query"
                       @input.debounce.300ms="search()"
                       @keydown.enter.prevent="search()"
                       @keydown.escape="close()"
                       @focus="if (total > 0) open = true"
                       autocomplete="off"
                       spellcheck="false"
                       aria-label="Global search"
                       aria-controls="ff-search-results-inline">

                <span class="search-spinner"
                      x-show="loading"
                      aria-hidden="true"></span>

                <kbd class="search-kbd search-kbd--inline" aria-hidden="true">
                    <span class="mac-only">⌘K</span>
                    <span class="non-mac-only">Ctrl+K</span>
                </kbd>
            </div>

            <!-- Results dropdown -->
            <div class="search-dropdown"
                 id="ff-search-results-inline"
                 x-show="open"
                 x-transition.opacity.duration.150ms
                 x-cloak
                 role="listbox"
                 aria-label="Search results">

                <!-- Loading state -->
                <div class="search-loading"
                     x-show="loading && total === 0">
                    Searching…
                </div>

                <!-- No results -->
                <div class="search-empty"
                     x-show="!loading && total === 0">
                    No results for &ldquo;<span x-text="query"></span>&rdquo;
                </div>

                <!-- Grouped results -->
                <template x-if="!loading && total > 0">
                    <div>
                        <template x-for="group in groups" :key="group.type">
                            <div class="search-group" x-show="group.items.length > 0">
                                <div class="search-group-label" x-text="group.label"></div>
                                <template x-for="item in group.items" :key="item.type + '-' + item.id">
                                    <a :href="item.url"
                                       class="search-result-item"
                                       @click="close()"
                                       role="option">
                                        <div class="search-result-main">
                                            <span class="search-result-title" x-text="item.title"></span>
                                            <span x-show="item.badge"
                                                  :class="'badge ' + item.badge_class"
                                                  x-text="item.badge"></span>
                                        </div>
                                        <div class="search-result-sub"
                                             x-show="item.subtitle"
                                             x-text="item.subtitle"></div>
                                    </a>
                                </template>
                            </div>
                        </template>

                        <!-- See-all footer: reuses the ⌘K modal for the full list view -->
                        <button type="button"
                                class="search-see-all"
                                @click="openFullSearch()">
                            See all <span x-text="total"></span>
                            result<span x-show="total !== 1">s</span>
                            for &ldquo;<span x-text="query"></span>&rdquo;
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <!-- ── Theme toggle ──────────────────────────────────────────── -->
        <!-- Initialises from <html data-theme>; tracks state locally so  -->
        <!-- the icon flips immediately without waiting for a DOM read.    -->
        <div x-data="{
                dark: document.documentElement.getAttribute('data-theme') === 'dark',
                toggle() {
                    FF_Theme.toggle();
                    this.dark = !this.dark;
                    // WHY: persist preference to DB so it survives logout/login (S017-B)
                    const newTheme = this.dark ? 'dark' : 'light';
                    FF_Api.post('<?= base_url('api/v1/users/save_preference.php') ?>', { theme: newTheme }).catch(() => {});
                }
             }">
            <button class="btn-icon topbar-theme-btn"
                    @click="toggle()"
                    :title="dark ? 'Switch to light mode' : 'Switch to dark mode'"
                    :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'">
                <!-- Moon shown in light mode → click to go dark -->
                <template x-if="!dark">
                    <?= heroicon('moon', 'nav-icon') ?>
                </template>
                <!-- Sun shown in dark mode → click to go light -->
                <template x-if="dark">
                    <?= heroicon('sun', 'nav-icon') ?>
                </template>
            </button>
        </div>

        <!-- ── PERM-1 — Display settings popover (font size + density) ─ -->
        <!-- Affects ONLY .page-content; sidebar/topbar/footer are unchanged.
             State is seeded from window.FF_DISPLAY (set in header.php) and
             persisted to users.display_font_size / users.display_density
             via /api/v1/users/display_settings/update.php on every change. -->
        <div class="topbar-display"
             x-data="ffDisplaySettings()"
             @click.outside="open = false"
             @keydown.escape.window="open = false">

            <button class="btn-icon topbar-display-btn"
                    @click="open = !open"
                    :aria-expanded="open"
                    aria-haspopup="true"
                    aria-label="Display settings (text size and density)"
                    title="Display settings">
                <!-- Inline "text size" icon (letter A with up-arrow arc) -->
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke-width="1.7" stroke="currentColor" aria-hidden="true"
                     class="nav-icon">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 19 9 5l6 14M5.7 14h6.6M15.5 13h5M18 10.5v5"/>
                </svg>
            </button>

            <div class="topbar-display-dropdown"
                 x-show="open"
                 x-transition:enter="dropdown-enter"
                 x-transition:enter-start="dropdown-enter-start"
                 x-transition:enter-end="dropdown-enter-end"
                 x-transition:leave="dropdown-leave"
                 x-transition:leave-start="dropdown-leave-start"
                 x-transition:leave-end="dropdown-leave-end"
                 role="menu"
                 aria-label="Display settings"
                 style="display:none;">

                <p class="topbar-dropdown-label">Text size</p>

                <div class="topbar-display-row">
                    <button type="button"
                            class="btn-icon"
                            @click="decFont()"
                            :disabled="saving || fontSize <= 70"
                            aria-label="Decrease text size">
                        <span style="font-size:0.875rem;font-weight:600;">A−</span>
                    </button>
                    <span class="topbar-display-value" x-text="fontSize + '%'"></span>
                    <button type="button"
                            class="btn-icon"
                            @click="incFont()"
                            :disabled="saving || fontSize >= 130"
                            aria-label="Increase text size">
                        <span style="font-size:1.0625rem;font-weight:600;">A+</span>
                    </button>
                </div>

                <div class="user-dropdown-divider"></div>

                <p class="topbar-dropdown-label">Density</p>

                <div class="topbar-display-density">
                    <button type="button"
                            class="topbar-display-chip"
                            :class="density === 'compact' ? 'is-active' : ''"
                            @click="setDensity('compact')"
                            :disabled="saving">
                        Compact
                    </button>
                    <button type="button"
                            class="topbar-display-chip"
                            :class="density === 'comfortable' ? 'is-active' : ''"
                            @click="setDensity('comfortable')"
                            :disabled="saving">
                        Cozy
                    </button>
                    <button type="button"
                            class="topbar-display-chip"
                            :class="density === 'spacious' ? 'is-active' : ''"
                            @click="setDensity('spacious')"
                            :disabled="saving">
                        Spacious
                    </button>
                </div>

                <div class="user-dropdown-divider"></div>

                <button type="button"
                        class="topbar-display-reset"
                        @click="reset()"
                        :disabled="saving || (fontSize === 100 && density === 'comfortable')">
                    Reset to default
                </button>
            </div>
        </div>

        <!-- ── Notifications bell ────────────────────────────────────── -->
        <div class="topbar-notifications"
             x-data="{ open: false }"
             @click.outside="open = false"
             @keydown.escape.window="open = false">

            <button class="btn-icon topbar-bell-btn"
                    @click="open = !open"
                    aria-label="Notifications<?= $_unreadCount > 0 ? ' (' . e((string)$_unreadCount) . ' unread)' : '' ?>"
                    :aria-expanded="open">
                <?= heroicon('bell', 'nav-icon') ?>
                <?php if ($_unreadCount > 0): ?>
                    <span class="notification-dot" aria-hidden="true">
                        <?= e($_unreadCount > 99 ? '99+' : (string) $_unreadCount) ?>
                    </span>
                <?php endif; ?>
            </button>

            <!-- Populated by FF_Notifications.load() in app.js -->
            <div class="notification-dropdown"
                 x-show="open"
                 x-transition:enter="dropdown-enter"
                 x-transition:enter-start="dropdown-enter-start"
                 x-transition:enter-end="dropdown-enter-end"
                 x-transition:leave="dropdown-leave"
                 x-transition:leave-start="dropdown-leave-start"
                 x-transition:leave-end="dropdown-leave-end"
                 role="menu"
                 aria-label="Notifications">

                <div class="notification-header">
                    <span class="notification-heading">Notifications</span>
                    <button class="btn-link btn-xs" id="ff-mark-all-read" type="button">
                        Mark all read
                    </button>
                </div>

                <div class="notification-list" id="ff-notification-list">
                    <div class="notification-empty">Loading…</div>
                </div>

                <div class="notification-footer">
                    <a href="<?= e(base_url('notifications')) ?>" class="btn-link btn-sm">
                        View all notifications
                    </a>
                </div>

            </div>
        </div>

        <!-- ── User avatar dropdown ──────────────────────────────────── -->
        <div class="topbar-user"
             x-data="{ open: false }"
             @click.outside="open = false"
             @keydown.escape.window="open = false">

            <!-- Initials circle acts as the trigger button -->
            <button class="user-avatar"
                    @click="open = !open"
                    :aria-expanded="open"
                    aria-haspopup="true"
                    aria-label="Account menu for <?= e($_me['name'] ?? 'user') ?>">
                <?= e($_initials) ?>
            </button>

            <div class="user-dropdown"
                 x-show="open"
                 x-transition:enter="dropdown-enter"
                 x-transition:enter-start="dropdown-enter-start"
                 x-transition:enter-end="dropdown-enter-end"
                 x-transition:leave="dropdown-leave"
                 x-transition:leave-start="dropdown-leave-start"
                 x-transition:leave-end="dropdown-leave-end"
                 role="menu"
                 aria-label="Account menu">

                <!-- Identity header: large avatar + name + role badge + email -->
                <div class="user-dropdown-header">
                    <div class="user-avatar user-avatar--lg" aria-hidden="true">
                        <?= e($_initials) ?>
                    </div>
                    <div class="user-dropdown-identity">
                        <span class="user-dropdown-name"><?= e($_me['name'] ?? '') ?></span>
                        <span class="user-dropdown-meta">
                            <span class="badge badge-info"
                                  style="font-size:0.65rem; padding:2px 7px; line-height:1.4;">
                                <?= e($_roleLabel) ?>
                            </span>
                        </span>
                        <span class="user-dropdown-email"><?= e($_me['email'] ?? '') ?></span>
                    </div>
                </div>

                <div class="user-dropdown-divider"></div>

                <!-- Settings — permission-gated to super_admin / manager -->
                <?php if (can('settings', 'view')): ?>
                    <a href="<?= e(base_url('settings')) ?>"
                       class="user-dropdown-item"
                       role="menuitem">
                        <?= heroicon('cog-6-tooth', 'nav-icon') ?>
                        Settings
                    </a>
                <?php endif; ?>

                <!-- Profile — always visible; no permission check needed -->
                <a href="<?= e(base_url('profile')) ?>"
                   class="user-dropdown-item"
                   role="menuitem">
                    <!-- user-circle outline (no SVG file exists; inline is safe) -->
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke-width="1.5" stroke="currentColor" aria-hidden="true"
                         class="nav-icon">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                    </svg>
                    My Profile
                </a>

                <div class="user-dropdown-divider"></div>

                <!-- Sign Out — GET accepted by app/auth/logout.php; no CSRF needed -->
                <a href="<?= e(base_url('auth/logout')) ?>"
                   class="user-dropdown-item user-dropdown-item--danger"
                   role="menuitem">
                    <?= heroicon('arrow-right-on-rectangle', 'nav-icon') ?>
                    Sign Out
                </a>

            </div>
        </div><!-- /topbar-user -->

    </div><!-- /topbar-right -->

</header>

<?php
unset($_unreadCount, $_topbarTitle, $_me, $_initials, $_roleMap, $_roleLabel, $_creates);
?>
