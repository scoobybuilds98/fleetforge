<?php
declare(strict_types=1);

/**
 * app/admin/notifications/index.php
 *
 * Full notifications page with flat + grouped views.
 *
 * Views:
 *   flat    — paginated chronological list (default)
 *   grouped — collapsible sections per category, unread-heavy
 *             groups first; up to 10 items shown per group with
 *             a "See all" link when more exist
 *
 * Filters: is_read (all|unread|read), category, date_range, search
 * Paging:  25 per page (flat only)
 *
 * @method  GET
 * @auth    require_auth + login session
 * @session NOTIF-1
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *          api/v1/notifications/{mark_read,delete}.php
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

$userId = current_user_id();
if (!$userId) {
    header('Location: ' . base_url('auth/login'));
    exit;
}

// ── Filters ────────────────────────────────────────────────────────────────────
$isReadFilter = strtolower((string) ($_GET['is_read'] ?? 'all'));
$category     = trim((string) ($_GET['category'] ?? ''));
$dateRange    = strtolower((string) ($_GET['date_range'] ?? 'all'));
$search       = trim((string) ($_GET['q'] ?? ''));

$allowedCategories = [
    'leases'       => 'Leases',
    'invoices'     => 'Invoices',
    'payments'     => 'Payments',
    'customers'    => 'Customers',
    'equipment'    => 'Equipment',
    'compliance'   => 'Compliance',
    'maintenance'  => 'Maintenance',
    'damage'       => 'Damage Claims',
    'reservations' => 'Reservations',
    'samsara'      => 'GPS / Samsara',
    'accounting'   => 'Accounting',
    'system'       => 'System',
];

$where  = ['n.user_id = ?', 'n.deleted_at IS NULL'];
$params = [$userId];

if ($isReadFilter === 'unread') { $where[] = 'n.is_read = 0'; }
if ($isReadFilter === 'read')   { $where[] = 'n.is_read = 1'; }

if ($category !== '' && array_key_exists($category, $allowedCategories)) {
    $where[]  = 'n.category = ?';
    $params[] = $category;
}

switch ($dateRange) {
    case 'today':
        $where[] = 'n.created_at >= UTC_DATE()';
        break;
    case 'week':
        $where[] = 'n.created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)';
        break;
    case 'month':
        $where[] = 'n.created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)';
        break;
}

if ($search !== '') {
    $where[]  = '(n.title LIKE ? OR n.message LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSQL = implode(' AND ', $where);

// ── View mode ──────────────────────────────────────────────────────────────────
$viewMode = ($_GET['view'] ?? '') === 'grouped' ? 'grouped' : 'flat';

// ── Pagination (flat only) ─────────────────────────────────────────────────────
$perPage = 25;
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$offset  = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM notifications n WHERE $whereSQL", $params);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Global unread count (ignores filters) — shown in the page subtitle
$unreadCount = db_count(
    'SELECT COUNT(*) FROM notifications
       WHERE user_id = ? AND is_read = 0 AND deleted_at IS NULL',
    [$userId]
);

// ── Fetch rows ─────────────────────────────────────────────────────────────────
$notifCols = "n.id, n.title, n.message, n.type, n.category, n.url,
              n.entity_type, n.entity_id, n.severity, n.is_read, n.read_at, n.created_at";

if ($viewMode === 'grouped') {
    // Summary: count + unread per category (respects active filters)
    $groupSummary = [];
    foreach (db_select(
        "SELECT n.category, COUNT(*) AS total, SUM(n.is_read = 0) AS unread
           FROM notifications n WHERE $whereSQL GROUP BY n.category",
        $params
    ) as $s) {
        $groupSummary[$s['category'] ?? 'system'] = [
            'total'  => (int) $s['total'],
            'unread' => (int) $s['unread'],
        ];
    }

    // Fetch up to 500 items; group in PHP (up to 10 shown per section)
    $allRows = db_select(
        "SELECT $notifCols FROM notifications n
          WHERE $whereSQL ORDER BY n.is_read ASC, n.created_at DESC LIMIT 500",
        $params
    );
    $grouped = [];
    foreach ($allRows as $r) {
        $grouped[$r['category'] ?? 'system'][] = $r;
    }
    // Sort groups: most unread first, then by total
    uksort($grouped, static function (string $a, string $b) use ($groupSummary): int {
        $au = $groupSummary[$a]['unread'] ?? 0;
        $bu = $groupSummary[$b]['unread'] ?? 0;
        if ($au !== $bu) return $bu <=> $au;
        return ($groupSummary[$b]['total'] ?? 0) <=> ($groupSummary[$a]['total'] ?? 0);
    });
    $rows = []; // flat rows unused in grouped mode
} else {
    $rows = db_select(
        "SELECT $notifCols FROM notifications n
          WHERE $whereSQL ORDER BY n.is_read ASC, n.created_at DESC
          LIMIT $perPage OFFSET $offset",
        $params
    );
    $grouped = [];
    $groupSummary = [];
}

// ── Helper: relative time string ───────────────────────────────────────────────
function notif_time_ago(string $createdAt): string {
    $now = time();
    $ts  = strtotime($createdAt . ' UTC') ?: $now;
    $sec = $now - $ts;
    if ($sec < 60)        return 'just now';
    if ($sec < 3600)      return floor($sec / 60) . ' min ago';
    if ($sec < 86400)     return floor($sec / 3600) . ' hr ago';
    if ($sec < 86400 * 7) return floor($sec / 86400) . ' days ago';
    return date('M j, Y', $ts);
}

/**
 * notif_category_icon — map a notification category slug to a heroicon name.
 * Falls back to "bell" so unknown categories still render an icon.
 */
function notif_category_icon(?string $category): string {
    return match ($category) {
        'leases'       => 'document-text',
        'invoices'     => 'currency-dollar',
        'payments'     => 'banknotes',
        'customers'    => 'user-group',
        'equipment'    => 'truck',
        'compliance'   => 'shield-check',
        'maintenance'  => 'wrench-screwdriver',
        'damage'       => 'exclamation-triangle',
        'reservations' => 'calendar',
        'samsara'      => 'map-pin',
        'accounting'   => 'calculator',
        'system'       => 'cog-6-tooth',
        default        => 'bell',
    };
}

// ── Helper: pagination URL ─────────────────────────────────────────────────────
function notif_page_url(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return '?' . http_build_query($q);
}

// ── Helper: view toggle URL ────────────────────────────────────────────────────
function notif_view_url(string $v): string {
    $q = $_GET;
    $q['view'] = $v;
    unset($q['page']);
    return '?' . http_build_query($q);
}

// ── Helper: "See all in category" URL (flat view, filtered) ───────────────────
function notif_cat_url(string $cat): string {
    $q = $_GET;
    $q['view']     = 'flat';
    $q['category'] = $cat;
    unset($q['page']);
    return '?' . http_build_query($q);
}

$pageTitle = 'Notifications';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <div class="notif-page-header-text">
        <h1 class="page-header-title">Notifications</h1>
        <p class="page-subtitle">
            <?= number_format($unreadCount) ?> unread notification<?= $unreadCount === 1 ? '' : 's' ?>
        </p>
    </div>
    <div class="page-header-actions">
        <!-- ── View toggle ── -->
        <div style="display:flex;gap:0;border:1px solid var(--border-color);border-radius:6px;overflow:hidden;">
            <a href="<?= e(notif_view_url('flat')) ?>"
               class="btn btn-xs <?= $viewMode === 'flat' ? 'btn-primary' : 'btn-secondary' ?>"
               style="border-radius:0;border:none;padding:5px 12px;">Flat</a>
            <a href="<?= e(notif_view_url('grouped')) ?>"
               class="btn btn-xs <?= $viewMode === 'grouped' ? 'btn-primary' : 'btn-secondary' ?>"
               style="border-radius:0;border:none;border-left:1px solid var(--border-color);padding:5px 12px;">Grouped</a>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" id="notif-mark-all-btn"
                <?= $unreadCount === 0 ? 'disabled' : '' ?>>
            Mark all read
        </button>
        <button type="button" class="btn btn-secondary btn-sm" id="notif-clear-read-btn">
            Clear all read
        </button>
    </div>
</div>

<!-- ── Filter form ──────────────────────────────────────────────────────────── -->
<div class="card notif-filter-card">
    <div class="card-body">
        <form method="GET" action="" class="notif-filter-form">

            <div class="notif-filter-field">
                <label class="form-label">Status</label>
                <select name="is_read" class="form-select form-select-sm">
                    <option value="all"    <?= $isReadFilter === 'all'    ? 'selected' : '' ?>>All</option>
                    <option value="unread" <?= $isReadFilter === 'unread' ? 'selected' : '' ?>>Unread</option>
                    <option value="read"   <?= $isReadFilter === 'read'   ? 'selected' : '' ?>>Read</option>
                </select>
            </div>

            <div class="notif-filter-field">
                <label class="form-label">Category</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($allowedCategories as $slug => $label): ?>
                    <option value="<?= e($slug) ?>" <?= $category === $slug ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="notif-filter-field">
                <label class="form-label">Date</label>
                <select name="date_range" class="form-select form-select-sm">
                    <option value="all"   <?= $dateRange === 'all'   ? 'selected' : '' ?>>All Time</option>
                    <option value="today" <?= $dateRange === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week"  <?= $dateRange === 'week'  ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $dateRange === 'month' ? 'selected' : '' ?>>This Month</option>
                </select>
            </div>

            <div class="notif-filter-field notif-filter-field--grow">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       value="<?= e($search) ?>"
                       placeholder="Search title or message…">
            </div>

            <div class="notif-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="<?= base_url('notifications') ?>" class="btn btn-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── List ─────────────────────────────────────────────────────────────────── -->

<?php
// Shared helper: render a single notification <li>
function notif_render_item(array $n, bool $showCatBadge = true): void {
    $unread   = (int) $n['is_read'] === 0;
    $cat      = $n['category'] ?? 'system';
    $catClass = 'notif-icon--' . $cat;
    $type     = (string) ($n['type'] ?? '');
    if ($type === 'compliance.expired' || $n['severity'] === 'critical') {
        $catClass = 'notif-icon--danger';
    } elseif ($type === 'compliance.expiring_7' || $n['severity'] === 'warning') {
        $catClass = 'notif-icon--warning';
    }
    ?>
    <li class="notif-page-item <?= $unread ? 'notif-page-item--unread' : '' ?>"
        data-id="<?= (int) $n['id'] ?>">
        <div class="notif-icon <?= e($catClass) ?>">
            <?= heroicon(notif_category_icon($n['category']), 'nav-icon') ?>
        </div>
        <div class="notif-page-content">
            <div class="notif-page-title"><?= e($n['title']) ?></div>
            <div class="notif-page-message"><?= e($n['message']) ?></div>
            <div class="notif-page-meta">
                <span title="<?= e($n['created_at']) ?> UTC">
                    <?= e(notif_time_ago((string) $n['created_at'])) ?>
                </span>
                <?php if ($showCatBadge && !empty($n['category'])): ?>
                    · <span class="badge badge-neutral" style="font-size:0.65rem;padding:2px 7px;">
                        <?= e(ucfirst($n['category'])) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="notif-page-actions">
            <?php if (!empty($n['url'])): ?>
                <a href="<?= e($n['url']) ?>" class="btn btn-link btn-xs notif-page-link"
                   data-id="<?= (int) $n['id'] ?>">Open</a>
            <?php endif; ?>
            <?php if ($unread): ?>
                <button type="button" class="btn btn-link btn-xs notif-page-mark"
                        data-id="<?= (int) $n['id'] ?>">Mark read</button>
            <?php endif; ?>
            <button type="button" class="btn btn-link btn-xs notif-page-delete"
                    data-id="<?= (int) $n['id'] ?>">Delete</button>
        </div>
    </li>
    <?php
}
?>

<?php if ($viewMode === 'grouped'): ?>

<!-- ── GROUPED VIEW ─────────────────────────────────────────────────────────── -->
<div class="card notif-list-card">
    <div class="card-body notif-list-body">
        <?php if (empty($grouped)): ?>
            <div class="notif-page-empty">
                <?= heroicon('bell', 'nav-icon') ?>
                <p class="notif-page-empty-title">No notifications found</p>
                <p class="notif-page-empty-sub">Notifications will appear here when events occur</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $grpCat => $grpRows): ?>
            <?php
                $grpLabel   = $allowedCategories[$grpCat] ?? ucfirst((string) $grpCat);
                $grpTotal   = $groupSummary[$grpCat]['total']  ?? count($grpRows);
                $grpUnread  = $groupSummary[$grpCat]['unread'] ?? 0;
                $grpVisible = array_slice($grpRows, 0, 10);
                $grpMore    = $grpTotal > 10;
            ?>
            <div class="notif-group" id="notif-group-<?= e($grpCat) ?>">
                <div class="notif-group-header"
                     onclick="notifToggleGroup(<?= json_encode($grpCat) ?>)"
                     role="button" tabindex="0"
                     onkeydown="if(event.key==='Enter'||event.key===' ')notifToggleGroup(<?= json_encode($grpCat) ?>)">
                    <div class="notif-icon <?= e('notif-icon--' . $grpCat) ?>" style="flex-shrink:0;">
                        <?= heroicon(notif_category_icon($grpCat), 'nav-icon') ?>
                    </div>
                    <span class="notif-group-label"><?= e($grpLabel) ?></span>
                    <?php if ($grpUnread > 0): ?>
                        <span class="badge badge-warning" style="font-size:0.7rem;padding:2px 8px;">
                            <?= $grpUnread ?> unread
                        </span>
                    <?php endif; ?>
                    <span class="text-muted" style="font-size:0.75rem;white-space:nowrap;">
                        <?= $grpTotal ?> total
                    </span>
                    <svg class="notif-group-chevron" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <ul class="notif-page-list notif-group-items" id="notif-group-items-<?= e($grpCat) ?>">
                    <?php foreach ($grpVisible as $n): ?>
                        <?php notif_render_item($n, false); ?>
                    <?php endforeach; ?>
                    <?php if ($grpMore): ?>
                    <li style="padding:12px 20px;text-align:center;border-top:1px solid var(--border-color);">
                        <a href="<?= e(notif_cat_url($grpCat)) ?>" class="btn btn-link btn-sm">
                            See all <?= $grpTotal ?> <?= e($grpLabel) ?> notifications →
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>

<!-- ── FLAT VIEW ────────────────────────────────────────────────────────────── -->
<div class="card notif-list-card">
    <div class="card-body notif-list-body">
        <?php if (empty($rows)): ?>
            <div class="notif-page-empty">
                <?= heroicon('bell', 'nav-icon') ?>
                <p class="notif-page-empty-title">No notifications found</p>
                <p class="notif-page-empty-sub">Notifications will appear here when events occur</p>
            </div>
        <?php else: ?>
            <ul class="notif-page-list">
                <?php foreach ($rows as $n): ?>
                    <?php notif_render_item($n); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer notif-page-footer">
        <span class="text-muted notif-page-footer-text">
            Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($total) ?> total
        </span>
        <div class="notif-page-footer-actions">
            <?php if ($page > 1): ?>
                <a href="<?= e(notif_page_url($page - 1)) ?>" class="btn btn-secondary btn-sm">← Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?= e(notif_page_url($page + 1)) ?>" class="btn btn-secondary btn-sm">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<style>
/* ── Grouped view ───────────────────────────────────────────── */
.notif-group { border-bottom: 1px solid var(--border-color); }
.notif-group:last-child { border-bottom: none; }

.notif-group-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 20px;
    cursor: pointer;
    background: var(--bg-surface-2, #161616);
    user-select: none;
    outline: none;
}
.notif-group-header:hover,
.notif-group-header:focus-visible {
    background: var(--bg-hover, #1d1d1d);
}
.notif-group-label {
    flex: 1;
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary);
}
.notif-group-chevron {
    width: 18px;
    height: 18px;
    color: var(--text-muted);
    transition: transform 0.18s ease;
    flex-shrink: 0;
}
.notif-group--collapsed .notif-group-chevron { transform: rotate(-90deg); }
.notif-group--collapsed .notif-group-items   { display: none; }

/* Items inside groups don't show a category badge (redundant) */
.notif-group-items .notif-page-item:last-child { border-bottom: none; }
</style>

<script>
// ── Group collapse toggle ─────────────────────────────────────────────────────
function notifToggleGroup(cat) {
    document.getElementById('notif-group-' + cat)?.classList.toggle('notif-group--collapsed');
}

// ── Notifications page actions (NOTIF-1) ──────────────────────────────────
// All POSTs go through FF_Api so the CSRF token + cookies travel correctly.
(function () {
    const endpoint = (path) => FF_Api.url('/api/v1/notifications/' + path);

    document.querySelectorAll('.notif-page-mark').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = parseInt(btn.dataset.id, 10);
            try {
                const res = await FF_Api.post(endpoint('mark_read.php'), { notification_id: id });
                if (res?.success) {
                    btn.closest('.notif-page-item')?.classList.remove('notif-page-item--unread');
                    btn.remove();
                    if (window.FF_Toast) FF_Toast.success('Done', 'Notification marked as read.');
                }
            } catch { /* silent */ }
        });
    });

    document.querySelectorAll('.notif-page-link').forEach(link => {
        link.addEventListener('click', async () => {
            const id = parseInt(link.dataset.id, 10);
            try { await FF_Api.post(endpoint('mark_read.php'), { notification_id: id }); } catch {}
        });
    });

    document.querySelectorAll('.notif-page-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!(await FF_Confirm.ask('Delete this notification? This cannot be undone.'))) return;
            const id = parseInt(btn.dataset.id, 10);
            try {
                const res = await FF_Api.post(endpoint('delete.php'), { notification_id: id });
                if (res?.success) {
                    btn.closest('.notif-page-item')?.remove();
                    if (window.FF_Toast) FF_Toast.success('Done', 'Notification deleted.');
                }
            } catch { /* silent */ }
        });
    });

    document.getElementById('notif-mark-all-btn')?.addEventListener('click', async () => {
        try {
            const res = await FF_Api.post(endpoint('mark_read.php'), { mark_all: true });
            if (res?.success) {
                if (window.FF_Toast) FF_Toast.success('Done', 'All notifications marked as read.');
                window.location.reload();
            }
        } catch { /* silent */ }
    });

    document.getElementById('notif-clear-read-btn')?.addEventListener('click', async () => {
        if (!(await FF_Confirm.ask('Delete all read notifications? This cannot be undone.'))) return;
        try {
            const res = await FF_Api.post(endpoint('delete.php'), { clear_all_read: true });
            if (res?.success) {
                if (window.FF_Toast) FF_Toast.success('Done', 'Read notifications cleared.');
                window.location.reload();
            }
        } catch { /* silent */ }
    });
})();
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
