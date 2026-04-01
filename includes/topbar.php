<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Admin Topbar
// Included by includes/header.php — do not include directly.
//
// Renders:
//   • Mobile sidebar toggle
//   • Page title (from $pageTitle set by the including page)
//   • Global search trigger (⌘K / Ctrl+K)
//   • Notifications bell with unread count
// ============================================================

// Unread notification count — wrapped in try/catch so this
// renders gracefully before the notifications table is created.
$_unreadCount = 0;
try {
    $__userId = current_user_id();
    if ($__userId) {
        $_unreadCount = db_count(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND is_read = 0",
            [$__userId]
        );
    }
    unset($__userId);
} catch (Throwable) {
    $_unreadCount = 0;
}

$_topbarTitle = isset($pageTitle) ? trim($pageTitle) : '';
?>

<header class="topbar" role="banner">

    <div class="topbar-left">

        <!-- Mobile sidebar toggle (hidden on desktop, shown on mobile) -->
        <button class="topbar-menu-btn btn-icon"
                @click="sidebarOpen = !sidebarOpen"
                aria-label="Toggle navigation menu"
                aria-expanded="sidebarOpen"
                aria-controls="ff-sidebar">
            <?= heroicon('bars-3', 'nav-icon') ?>
        </button>

        <!-- Page title -->
        <?php if ($_topbarTitle !== ''): ?>
            <h1 class="topbar-title"><?= e($_topbarTitle) ?></h1>
        <?php endif; ?>

    </div>

    <div class="topbar-right">

        <!-- Global search trigger — opens the search modal via app.js -->
        <button class="topbar-search-btn"
                id="ff-search-trigger"
                aria-label="Global search"
                title="Search (⌘K)">
            <?= heroicon('magnifying-glass', 'search-icon') ?>
            <span class="search-hint" aria-hidden="true">
                Search
                <kbd class="search-kbd">
                    <span class="mac-only">⌘K</span>
                    <span class="non-mac-only">Ctrl+K</span>
                </kbd>
            </span>
        </button>

        <!-- Notifications bell -->
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
                    <span class="notification-dot"
                          aria-hidden="true">
                        <?= e($_unreadCount > 99 ? '99+' : (string) $_unreadCount) ?>
                    </span>
                <?php endif; ?>
            </button>

            <!-- Notification dropdown — populated by app.js via API -->
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
                    <button class="btn-link btn-xs"
                            id="ff-mark-all-read"
                            type="button">
                        Mark all read
                    </button>
                </div>

                <!-- Populated by FF_Notifications.load() in app.js -->
                <div class="notification-list" id="ff-notification-list">
                    <div class="notification-empty">Loading…</div>
                </div>

                <div class="notification-footer">
                    <a href="<?= e(base_url('notifications')) ?>"
                       class="btn-link btn-sm">
                        View all notifications
                    </a>
                </div>

            </div>
        </div>

    </div>

</header>

<?php
unset($_unreadCount, $_topbarTitle);
?>
