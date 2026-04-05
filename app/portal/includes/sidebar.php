<?php
declare(strict_types=1);

/**
 * app/portal/includes/sidebar.php
 *
 * Portal sidebar navigation — simpler than admin.
 * 7 nav items, customer branding, user footer.
 * Included by portal/includes/header.php only.
 */

$_portalUser  = portal_user();
$_companyName = settings_get('company.name', 'FleetForge');
$_customerName = $_portalUser['company_name'] ?? '';
$_currentPath  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$_portalBase   = FF_BASE_PATH . '/portal';

// User initials
$_initials = 'U';
if (!empty($_portalUser['name'])) {
    $parts = preg_split('/\s+/', trim($_portalUser['name']));
    $_initials = strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $_initials .= strtoupper(mb_substr(end($parts), 0, 1));
    }
}

// Badge counts — wrapped in try/catch for safety
$_cid = portal_customer_id();
$_overdueInvoiceBadge = 0;
$_openRequestBadge = 0;
try {
    $_overdueInvoiceBadge = db_count(
        "SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND status IN ('overdue','sent') AND deleted_at IS NULL",
        [$_cid]
    );
    $_openRequestBadge = db_count(
        "SELECT COUNT(*) FROM portal_service_requests WHERE customer_id = ? AND status IN ('open','in_review')",
        [$_cid]
    );
} catch (Throwable) {}

// Navigation items
$_navItems = [
    ['label' => 'Dashboard', 'url' => '/portal',          'icon' => 'home',       'badge' => 0],
    ['label' => 'Leases',    'url' => '/portal/leases',   'icon' => 'document',   'badge' => 0],
    ['label' => 'Invoices',  'url' => '/portal/invoices',  'icon' => 'banknotes',  'badge' => $_overdueInvoiceBadge],
    ['label' => 'Equipment', 'url' => '/portal/equipment', 'icon' => 'truck',      'badge' => 0],
    ['label' => 'Documents', 'url' => '/portal/documents', 'icon' => 'folder',     'badge' => 0],
    ['label' => 'Requests',  'url' => '/portal/requests',  'icon' => 'chat',       'badge' => $_openRequestBadge],
    ['label' => 'Account',   'url' => '/portal/account',   'icon' => 'user',       'badge' => 0],
];

// SVG icons inline — avoids dependency on icon files
$_icons = [
    'home' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="portal-nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>',
    'document' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="portal-nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>',
    'banknotes' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="portal-nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>',
    'truck' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="portal-nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0H21M3.375 14.25V3.75h8.25m0 0h4.875c.621 0 1.125.504 1.125 1.125v4.875m-6-6v6h6"/></svg>',
    'folder' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="portal-nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>',
    'chat' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="portal-nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg>',
    'user' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="portal-nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
];
?>

<aside class="portal-sidebar" :class="{ 'is-open': sidebarOpen, 'is-closed': !sidebarOpen }">

    <!-- Brand -->
    <div class="portal-brand">
        <div class="portal-brand-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0H21M3.375 14.25V3.75h8.25m0 0h4.875c.621 0 1.125.504 1.125 1.125v4.875m-6-6v6h6"/></svg>
        </div>
        <div class="portal-brand-text">
            <span class="portal-brand-name"><?= e($_companyName) ?></span>
            <span class="portal-brand-label">Customer Portal</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="portal-nav" aria-label="Portal navigation">
        <?php foreach ($_navItems as $_item): ?>
            <?php
            $_itemFullUrl = FF_BASE_PATH . $_item['url'];
            // Active: exact match for portal root, starts-with for sub-pages
            $_isActive = ($_item['url'] === '/portal')
                ? ($_currentPath === $_itemFullUrl || $_currentPath === $_itemFullUrl . '/')
                : str_starts_with($_currentPath, $_itemFullUrl);
            ?>
            <a href="<?= e(base_url(ltrim($_item['url'], '/'))) ?>"
               class="portal-nav-item<?= $_isActive ? ' is-active' : '' ?>"
               <?= $_isActive ? 'aria-current="page"' : '' ?>>
                <?= $_icons[$_item['icon']] ?>
                <span><?= e($_item['label']) ?></span>
                <?php if ($_item['badge'] > 0): ?>
                    <span class="portal-nav-badge"><?= e($_item['badge'] > 99 ? '99+' : (string) $_item['badge']) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- User footer -->
    <div class="portal-sidebar-footer">
        <div class="portal-user-avatar"><?= e($_initials) ?></div>
        <div class="portal-user-info">
            <span class="portal-user-name"><?= e($_portalUser['name'] ?? '') ?></span>
            <span class="portal-user-company"><?= e($_customerName) ?></span>
        </div>
    </div>

</aside>

<?php
unset($_portalUser, $_companyName, $_customerName, $_currentPath, $_portalBase,
      $_initials, $_cid, $_overdueInvoiceBadge, $_openRequestBadge,
      $_navItems, $_icons, $_item, $_itemFullUrl, $_isActive);
?>
