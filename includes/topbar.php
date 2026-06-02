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
// [NOTIF-1] Initial count for first paint. After load, FF_Notifications() Alpine
// factory polls /api/v1/notifications/count.php every 60s to refresh the badge.
// Wrapped in try/catch so the topbar renders even if the table is missing.
$_unreadCount = 0;
try {
    $_uid = current_user_id();
    if ($_uid) {
        $_unreadCount = db_count(
            'SELECT COUNT(*) FROM notifications
              WHERE user_id = ? AND is_read = 0 AND deleted_at IS NULL',
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
// S-ROLE-LABEL-SUPERADMIN: the display label for the super_admin
// slug renders as "Developer" (uppercased to "DEVELOPER" in the
// topbar pill via CSS text-transform). The underlying role slug
// 'super_admin' is unchanged — only the human-readable label.
$_roleMap = [
    'super_admin' => 'Developer',
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

        <?php
        // S-DESIGN-LOGO-TOPBAR: company name shown as a breadcrumb root.
        // Reads from settings_get so it tracks Design tab edits live.
        // Clicking returns to dashboard (matches existing topbar-home-btn behaviour).
        $_topbarCompany = settings_get('company.name', 'FleetForge');
        ?>
        <a href="<?= base_url('dashboard') ?>" class="topbar-company-name" title="<?= e($_topbarCompany) ?>">
            <?= e($_topbarCompany) ?>
        </a>

        <?php if ($_topbarTitle !== ''): ?>
            <span class="topbar-breadcrumb-sep" aria-hidden="true">›</span>
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
                <span class="topbar-create-btn__label">New</span>
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

        <!-- ── Mobile-only magnifying-glass icon ──
             Hidden on tablet+desktop where .search-wrapper is shown
             inline. On mobile (<768px) the wrapper hides and this
             icon takes over — tapping it opens the existing ⌘K
             global search modal via FF_Search.open(). -->
        <button type="button"
                class="btn-icon topbar-search-icon-btn"
                onclick="window.FF_Search && FF_Search.open()"
                aria-label="Search">
            <?= heroicon('magnifying-glass', 'nav-icon') ?>
        </button>

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

        <!--
          ── MEDIA-1 — Sound mute toggle ─────────────────────────
          Mutes / unmutes the FF_Sound notification cue. State is
          seeded from localStorage so the user's preference survives
          reloads. Calls FF_Sound.toggleMute() which persists the
          new state and returns the fresh boolean.
        -->
        <button type="button"
                class="btn-icon sound-toggle-btn"
                x-data="{ muted: (function(){ try { return localStorage.getItem('ff_sound_muted') === 'true'; } catch(e) { return false; } })() }"
                x-init="$el.classList.toggle('is-muted', muted)"
                @click="muted = (window.FF_Sound ? FF_Sound.toggleMute() : !muted); $el.classList.toggle('is-muted', muted)"
                :aria-label="muted ? 'Unmute notification sounds' : 'Mute notification sounds'"
                :title="muted ? 'Unmute sounds' : 'Mute sounds'">
            <!-- Speaker wave (unmuted) -->
            <svg x-show="!muted" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke-width="1.6"
                 stroke="currentColor" aria-hidden="true" class="nav-icon">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z"/>
            </svg>
            <!-- Speaker-x (muted) -->
            <svg x-show="muted" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke-width="1.6"
                 stroke="currentColor" aria-hidden="true" class="nav-icon">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M17.25 9.75 19.5 12m0 0 2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z"/>
            </svg>
        </button>

        <!--
          ── Chat icon (unified: Team Chat + Customer Messenger) ─────
          Single entry point for both the Slack-style internal team
          chat (CHAT-1) and the customer DM messenger (MSGR-1). Clicking
          dispatches a window event the combined FF_ChatHub widget
          listens for. Both sub-inboxes share one topbar icon with a
          combined unread badge — the per-inbox badges live inside the
          widget panel itself.
        -->
        <div class="chat-topbar"
             x-data="FF_ChatHubBadge()"
             x-init="init()">
            <!--
              @click uses Alpine.$data(el).toggle() directly instead of
              going through a window.FF_OpenChatHub global. WHY: a
              previous iteration stashed an arrow function inside
              x-init like `window.FF_OpenChatHub = () => { open = !open; }`,
              but Alpine's x-init evaluates inside a `with(scope)` block
              — bare identifiers like `open` resolve at EVAL time, not
              call time. When the arrow function was invoked later by
              the topbar click, `open` no longer referred to the
              reactive component property, so Alpine's x-show never
              fired and the panel stayed at display:none even though
              the open flag appeared to change. Calling toggle()
              through Alpine.$data() is the documented, scope-safe way
              to invoke a factory method from outside the component.
            -->
            <a href="<?= base_url('chat') ?>"
               class="chat-topbar-btn topbar-chat-btn"
               :class="{ 'has-unread': totalUnread > 0 }"
               @click.prevent.stop="(() => { const el = document.getElementById('ff-chat-widget'); if (el && window.Alpine) Alpine.$data(el).toggle(); })()"
               :aria-label="totalUnread > 0 ? 'Chat (' + totalUnread + ' unread)' : 'Chat'"
               title="Chat (team + customers)">
                <?= heroicon('chat-bubble-left-right', 'nav-icon') ?>
                <span class="chat-badge"
                      x-show="totalUnread > 0"
                      x-text="totalUnread > 99 ? '99+' : totalUnread"
                      aria-hidden="true"></span>
            </a>
        </div>

        <!--
          ── AI Assistant icon (global launcher) ──────────────────────
          Always visible on every admin page for users with ai:view.
          This is a PLAIN NAVIGATION LINK to the full /ai page — no
          floating widget, no Alpine wiring, no @click handler.

          WHY plain link: a previous iteration opened a floating
          ai-chat-widget panel via a @click global function, but that
          caused Alpine scope-chain collisions where clicking the
          theme toggle button would also open the AI panel. Removing
          the floating widget entirely + making this a normal anchor
          guarantees the bottom-right of every page is reserved for
          the team chat widget only, and no topbar button can ever
          accidentally summon the AI panel.
        -->
        <?php if (can('ai', 'view')): ?>
        <div class="chat-topbar">
            <a href="<?= base_url('ai') ?>"
               class="chat-topbar-btn topbar-ai-btn"
               aria-label="AI Assistant"
               title="AI Assistant">
                <!--
                  Heroicons "sparkles" 24 outline.
                  WHY this specific glyph: previously used a sun-like
                  radial icon that was visually confusable with the
                  dark/light mode moon button sitting a few pixels away.
                  Users were clicking the wrong one. Sparkles is unique
                  on the topbar, clearly signals "AI", and matches what
                  the AI page itself uses.
                -->
                <svg xmlns="http://www.w3.org/2000/svg" fill="none"
                     viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"
                     class="nav-icon" aria-hidden="true"
                     style="width:20px;height:20px">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/>
                </svg>
            </a>
        </div>
        <?php endif; ?>

        <!-- ── Notifications bell (NOTIF-1 — Alpine factory) ────────── -->
        <!-- FF_Notifications() factory is in public/assets/js/app.js.
             It owns: open, loading, notifications[], unreadCount, _pollTimer.
             Initial $_unreadCount is rendered server-side for first paint;
             Alpine.init() then refreshes it via /api/v1/notifications/count.php
             every 60s. -->
        <div class="notif-wrapper"
             x-data="FF_Notifications()"
             x-init="init(); unreadCount = <?= (int) $_unreadCount ?>;"
             @click.outside="open = false"
             @keydown.escape.window="open = false">

            <button type="button"
                    class="btn-icon topbar-bell-btn notif-bell-btn"
                    :class="{ 'has-unread': unreadCount > 0 }"
                    @click="toggleDropdown()"
                    :aria-expanded="open"
                    :aria-label="unreadCount > 0
                        ? 'Notifications (' + unreadCount + ' unread)'
                        : 'Notifications'">
                <?= heroicon('bell', 'nav-icon') ?>
                <span class="notif-badge"
                      x-show="unreadCount > 0"
                      x-text="unreadCount > 99 ? '99+' : unreadCount"
                      aria-hidden="true"></span>
            </button>

            <div class="notif-dropdown"
                 x-show="open"
                 x-cloak
                 x-transition:enter="dropdown-enter"
                 x-transition:enter-start="dropdown-enter-start"
                 x-transition:enter-end="dropdown-enter-end"
                 x-transition:leave="dropdown-leave"
                 x-transition:leave-start="dropdown-leave-start"
                 x-transition:leave-end="dropdown-leave-end"
                 role="menu"
                 aria-label="Notifications">

                <!-- Header -->
                <div class="notif-dropdown-header">
                    <span class="notif-dropdown-title">Notifications</span>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="notif-view-toggle">
                            <button @click="setView('flat')"
                                    :class="{ 'notif-view-toggle--active': viewMode === 'flat' }">Flat</button>
                            <button @click="setView('grouped')"
                                    :class="{ 'notif-view-toggle--active': viewMode === 'grouped' }">Grouped</button>
                        </div>
                        <button type="button"
                                class="notif-mark-all"
                                @click="markAllRead()"
                                x-show="unreadCount > 0">
                            Mark all read
                        </button>
                    </div>
                </div>

                <!-- Loading -->
                <div class="notif-loading" x-show="loading" x-cloak>
                    Loading notifications…
                </div>

                <!-- Empty (shown only when there are genuinely no notifications) -->
                <div class="notif-empty"
                     x-show="!loading && notifications.length === 0"
                     x-cloak>
                    <?= heroicon('bell', 'nav-icon') ?>
                    <p>No notifications yet</p>
                </div>

                <!-- ── FLAT view (x-show keeps it in DOM so x-for stays initialised) ── -->
                <div x-show="viewMode === 'flat'">
                    <template x-for="n in notifications" :key="n.id">
                        <a :href="n.url || '#'"
                           class="notif-item"
                           :class="{ 'notif-item--unread': !n.is_read }"
                           @click="markRead(n.id)">
                            <div class="notif-icon" :class="categoryClass(n)" x-html="iconFor(n.category)"></div>
                            <div class="notif-content">
                                <div class="notif-title" x-text="n.title"></div>
                                <div class="notif-message" x-text="n.message"></div>
                                <div class="notif-time" x-text="n.time_ago"></div>
                            </div>
                            <div class="notif-unread-dot" x-show="!n.is_read"></div>
                        </a>
                    </template>
                </div>

                <!-- ── GROUPED view ── -->
                <div x-show="viewMode === 'grouped'">
                    <template x-for="group in groupedEntries()" :key="group.cat">
                        <div class="notif-group-block">
                            <!-- Group header -->
                            <div class="notif-group-row" @click="toggleGroup(group.cat)">
                                <div class="notif-icon notif-icon--sm"
                                     :class="'notif-icon--' + group.cat"
                                     x-html="iconFor(group.cat)"></div>
                                <span class="notif-group-row-label" x-text="categoryLabel(group.cat)"></span>
                                <span class="notif-group-row-badge"
                                      x-show="group.unread > 0"
                                      x-text="group.unread"></span>
                                <svg class="notif-group-row-chevron"
                                     :class="{ 'is-collapsed': !expandedGroups[group.cat] }"
                                     viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                            <!-- Group items — x-show instead of x-if to keep x-for alive -->
                            <div x-show="expandedGroups[group.cat]">
                                <template x-for="n in group.items" :key="n.id">
                                    <a :href="n.url || '#'"
                                       class="notif-item notif-item--indented"
                                       :class="{ 'notif-item--unread': !n.is_read }"
                                       @click="markRead(n.id)">
                                        <div class="notif-content">
                                            <div class="notif-title" x-text="n.title"></div>
                                            <div class="notif-message" x-text="n.message"></div>
                                            <div class="notif-time" x-text="n.time_ago"></div>
                                        </div>
                                        <div class="notif-unread-dot" x-show="!n.is_read"></div>
                                    </a>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Footer -->
                <a href="<?= e(base_url('notifications')) ?>"
                   class="notif-dropdown-footer">
                    See all notifications
                    <span x-show="unreadCount > 0">
                        (<span x-text="unreadCount"></span> unread)
                    </span>
                </a>
            </div>
        </div>

        <!-- ── User avatar dropdown ──────────────────────────────────── -->
        <div class="topbar-user"
             x-data="{ open: false }"
             @click.outside="open = false"
             @keydown.escape.window="open = false">

            <!-- Avatar + name/role acts as the trigger button.
                 NOTIF-1 follow-up: show the logged-in user's name in the
                 topbar (was initials-only). Name + role are hidden on
                 mobile via .user-trigger-meta to save horizontal space. -->
            <button class="user-trigger"
                    @click="open = !open"
                    :aria-expanded="open"
                    aria-haspopup="true"
                    aria-label="Account menu for <?= e($_me['name'] ?? 'user') ?>">
                <span class="user-avatar" aria-hidden="true"><?= e($_initials) ?></span>
                <span class="user-trigger-meta">
                    <span class="user-trigger-name"><?= e($_me['name'] ?? 'User') ?></span>
                    <span class="user-trigger-role"><?= e($_roleLabel) ?></span>
                </span>
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
unset($_unreadCount, $_topbarTitle, $_topbarCompany, $_me, $_initials, $_roleMap, $_roleLabel, $_creates);
?>
