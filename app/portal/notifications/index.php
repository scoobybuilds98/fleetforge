<?php
declare(strict_types=1);

/**
 * app/portal/notifications/index.php
 *
 * Portal full notifications page. Customers see only their own notifications
 * (filtered by portal_user_id). Mirrors the admin layout but uses the
 * portal-only API endpoints under /portal/api/notifications/.
 *
 * @auth     portal session
 * @session  NOTIF-1
 * @depends  app/portal/includes/{auth,header,footer}.php
 *           app/portal/api/notifications/{mark_read,...}.php
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$portalUserId = portal_user_id();

// ── Filters ────────────────────────────────────────────────────────────────────
$isReadFilter = strtolower((string) ($_GET['is_read'] ?? 'all'));
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 25;

$where  = ['portal_user_id = ?', 'deleted_at IS NULL'];
$params = [$portalUserId];

if ($isReadFilter === 'unread') $where[] = 'is_read = 0';
if ($isReadFilter === 'read')   $where[] = 'is_read = 1';

$whereSql = implode(' AND ', $where);

$total       = db_count("SELECT COUNT(*) FROM notifications WHERE $whereSql", $params);
$totalPages  = max(1, (int) ceil($total / $perPage));
$page        = min($page, $totalPages);
$offset      = ($page - 1) * $perPage;

$unreadCount = db_count(
    'SELECT COUNT(*) FROM notifications
       WHERE portal_user_id = ? AND is_read = 0 AND deleted_at IS NULL',
    [$portalUserId]
);

$rows = db_select(
    "SELECT id, title, message, type, category, url, severity, is_read, read_at, created_at
       FROM notifications
      WHERE $whereSql
   ORDER BY is_read ASC, created_at DESC
      LIMIT $perPage OFFSET $offset",
    $params
);

function portal_notif_time_ago(string $createdAt): string {
    $now = time();
    $ts  = strtotime($createdAt . ' UTC') ?: $now;
    $sec = $now - $ts;
    if ($sec < 60)        return 'just now';
    if ($sec < 3600)      return floor($sec / 60) . ' min ago';
    if ($sec < 86400)     return floor($sec / 3600) . ' hr ago';
    if ($sec < 86400 * 7) return floor($sec / 86400) . ' days ago';
    return date('M j, Y', $ts);
}

function portal_notif_page_url(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return '?' . http_build_query($q);
}

$pageTitle = 'Notifications';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div class="notif-page-header-text">
        <h1 class="page-header-title">Notifications</h1>
        <p class="page-subtitle">
            <?= number_format($unreadCount) ?> unread notification<?= $unreadCount === 1 ? '' : 's' ?>
        </p>
    </div>
    <div class="page-header-actions">
        <button type="button" class="btn btn-secondary btn-sm" id="portal-notif-mark-all"
                <?= $unreadCount === 0 ? 'disabled' : '' ?>>
            Mark all read
        </button>
    </div>
</div>

<!-- Filter -->
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
            <div class="notif-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="<?= base_url('portal/notifications') ?>" class="btn btn-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- List -->
<div class="card notif-list-card">
    <div class="card-body notif-list-body">
        <?php if (empty($rows)): ?>
            <div class="notif-page-empty">
                <p class="notif-page-empty-title">No notifications yet</p>
                <p class="notif-page-empty-sub">Notifications will appear here when there are updates on your account.</p>
            </div>
        <?php else: ?>
            <ul class="notif-page-list">
                <?php foreach ($rows as $n): ?>
                    <?php $unread = (int) $n['is_read'] === 0; ?>
                    <li class="notif-page-item <?= $unread ? 'notif-page-item--unread' : '' ?>"
                        data-id="<?= (int) $n['id'] ?>">
                        <div class="notif-icon notif-icon--info">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
                        </div>
                        <div class="notif-page-content">
                            <div class="notif-page-title"><?= e($n['title']) ?></div>
                            <div class="notif-page-message"><?= e($n['message']) ?></div>
                            <div class="notif-page-meta"><?= e(portal_notif_time_ago((string) $n['created_at'])) ?></div>
                        </div>
                        <div class="notif-page-actions">
                            <?php if (!empty($n['url'])): ?>
                                <a href="<?= e($n['url']) ?>" class="btn btn-link btn-xs">Open</a>
                            <?php endif; ?>
                            <?php if ($unread): ?>
                                <button type="button" class="btn btn-link btn-xs portal-notif-mark"
                                        data-id="<?= (int) $n['id'] ?>">Mark read</button>
                            <?php endif; ?>
                        </div>
                    </li>
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
                <a href="<?= e(portal_notif_page_url($page - 1)) ?>" class="btn btn-secondary btn-sm">← Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?= e(portal_notif_page_url($page + 1)) ?>" class="btn btn-secondary btn-sm">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const endpoint = (path) => FF_Api.url('/portal/api/notifications/' + path);

    document.querySelectorAll('.portal-notif-mark').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = parseInt(btn.dataset.id, 10);
            try {
                const res = await FF_Api.post(endpoint('mark_read.php'), { notification_id: id });
                if (res?.success) {
                    btn.closest('.notif-page-item')?.classList.remove('notif-page-item--unread');
                    btn.remove();
                }
            } catch { /* silent */ }
        });
    });

    document.getElementById('portal-notif-mark-all')?.addEventListener('click', async () => {
        try {
            const res = await FF_Api.post(endpoint('mark_read.php'), { mark_all: true });
            if (res?.success) window.location.reload();
        } catch { /* silent */ }
    });
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
