<?php
declare(strict_types=1);

/**
 * app/portal/notifications/index.php
 *
 * Customer portal — all notifications (S-PORTAL-REDESIGN; NOTIF-1 data).
 *
 * The signed-in portal user's own notifications (portal_user_id), newest
 * first, with All / Unread tabs and paging. Clicking one marks it read and
 * opens it; "Mark all read" clears the lot. Both go through the portal
 * endpoint app/portal/api/notifications/mark_read.php.
 *
 * Fixed here: marking one read never updated the topbar bell's count, the
 * list re-sorted unread-first (items jumped between pages as you read
 * them), severity/type were ignored (every icon "info"), and failures were
 * silent.
 *
 * @auth    portal session
 * @session NOTIF-1, S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$portalUserId = portal_user_id();

$filter  = ($_GET['filter'] ?? $_GET['is_read'] ?? 'all') === 'unread' ? 'unread' : 'all';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where  = 'portal_user_id = ? AND deleted_at IS NULL' . ($filter === 'unread' ? ' AND is_read = 0' : '');
$total  = db_count("SELECT COUNT(*) FROM notifications WHERE {$where}", [$portalUserId]);
$pages  = max(1, (int) ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;
$unread = db_count('SELECT COUNT(*) FROM notifications WHERE portal_user_id = ? AND is_read = 0 AND deleted_at IS NULL', [$portalUserId]);

$rows = db_select(
    "SELECT id, title, message, type, category, url, severity, is_read, created_at
       FROM notifications
      WHERE {$where}
      ORDER BY created_at DESC, id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    [$portalUserId]
);

$ago = static function (string $utc): string {
    $ts  = strtotime($utc . ' UTC') ?: time();
    $sec = time() - $ts;
    return match (true) {
        $sec < 60        => 'just now',
        $sec < 3600      => floor($sec / 60) . ' min ago',
        $sec < 86400     => floor($sec / 3600) . ' hr ago',
        $sec < 86400 * 7 => floor($sec / 86400) . ' day' . (floor($sec / 86400) == 1 ? '' : 's') . ' ago',
        default          => format_datetime($utc, 'M j, Y'),
    };
};
$iconFor = static function (array $n): array {
    $hay = strtolower(($n['type'] ?? '') . ' ' . ($n['category'] ?? '') . ' ' . ($n['title'] ?? ''));
    $tone = match ((string) ($n['severity'] ?? '')) { 'critical', 'error', 'danger' => 'danger', 'warning' => 'warning', 'success' => 'success', default => 'info' };
    $icon = match (true) {
        str_contains($hay, 'payment') || str_contains($hay, 'receipt') => 'banknotes',
        str_contains($hay, 'invoice') || str_contains($hay, 'statement') => 'document-text',
        str_contains($hay, 'request') || str_contains($hay, 'reply')  => 'chat-bubble-left-ellipsis',
        str_contains($hay, 'lease')                                   => 'clipboard-document-list',
        str_contains($hay, 'compliance') || str_contains($hay, 'expir') => 'shield-check',
        default                                                       => 'bell',
    };
    return [$icon, $tone];
};
$pageUrl = static fn (int $p): string => pt_url('notifications?filter=' . $filter . '&page=' . $p);

$pageTitle = 'Notifications';
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'eyebrow' => 'Account',
    'title'   => 'Notifications',
    'sub'     => $unread ? $unread . ' unread' : 'You\'re all caught up.',
]);
?>

<section class="pt-card" x-data="{
        unread: <?= (int) $unread ?>,
        async mark(id, href, el) {
            const r = await PT.post('portal/api/notifications/mark_read.php', { notification_id: id });
            if (r && r.success) {
                this.unread = r.data.unread_count;
                el.closest('[data-unread]')?.removeAttribute('data-unread');
                window.dispatchEvent(new CustomEvent('pt-notif-count', { detail: this.unread }));
            }
            if (href) window.location.href = href;
        },
        async markAll() {
            const r = await PT.post('portal/api/notifications/mark_read.php', { mark_all: true });
            if (r && r.success) { window.location.reload(); }
            else { PT.toast('danger', 'Couldn’t update notifications', (r && r.error && r.error.message) || ''); }
        }
    }">
    <div class="pt-toolbar">
        <div class="pt-tabs">
            <a class="pt-tab<?= $filter === 'all' ? ' is-active' : '' ?>" href="<?= e(pt_url('notifications')) ?>">All <span class="pt-tab-count"><?= $filter === 'all' ? (int) $total : '' ?></span></a>
            <a class="pt-tab<?= $filter === 'unread' ? ' is-active' : '' ?>" href="<?= e(pt_url('notifications?filter=unread')) ?>">Unread <span class="pt-tab-count" x-text="unread"><?= (int) $unread ?></span></a>
        </div>
        <div class="pt-toolbar-spacer"></div>
        <button type="button" class="pt-btn pt-btn--secondary pt-btn--sm" x-show="unread > 0" @click="markAll()"><?= pt_icon('check') ?> Mark all read</button>
    </div>

    <?php if (!$rows): ?>
        <?= pt_empty('bell', $filter === 'unread' ? 'No unread notifications' : 'No notifications yet', 'We\'ll let you know here about new invoices, payments, replies and anything that needs your attention.') ?>
    <?php else: ?>
        <ul class="pt-attn" style="padding:8px">
            <?php foreach ($rows as $n):
                [$icon, $tone] = $iconFor($n);
                $href = (string) ($n['url'] ?? '');
                if ($href !== '' && !preg_match('#^https?://#', $href)) {
                    $href = base_url(ltrim(preg_replace('#^/?fleetforge/#', '', $href), '/'));
                }
            ?>
                <li <?= !(int) $n['is_read'] ? 'data-unread' : '' ?>>
                    <a class="pt-attn-item" data-tone="<?= e($tone) ?>" href="<?= e($href !== '' ? $href : '#') ?>"
                       @click.prevent="mark(<?= (int) $n['id'] ?>, <?= e(json_encode($href)) ?>, $el)">
                        <span class="pt-attn-ic"><?= pt_icon($icon) ?></span>
                        <span class="pt-attn-body">
                            <span class="pt-attn-title" style="display:flex;gap:8px;align-items:center">
                                <?= e((string) $n['title']) ?>
                                <?php if (!(int) $n['is_read']): ?><i class="pt-dot pt-dot--brand" aria-label="Unread"></i><?php endif; ?>
                            </span>
                            <?php if (!empty($n['message'])): ?><span class="pt-attn-text" style="display:block"><?= e((string) $n['message']) ?></span><?php endif; ?>
                        </span>
                        <span class="pt-faint" style="font-size:12.5px;white-space:nowrap"><?= e($ago((string) $n['created_at'])) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($pages > 1): ?>
            <div class="pt-card-foot">
                <span>Page <?= $page ?> of <?= $pages ?></span>
                <div class="pt-btn-row">
                    <a class="pt-btn pt-btn--secondary pt-btn--sm<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= e($pageUrl($page - 1)) ?>"><?= pt_icon('chevron-left') ?> Newer</a>
                    <a class="pt-btn pt-btn--secondary pt-btn--sm<?= $page >= $pages ? ' is-disabled' : '' ?>" href="<?= e($pageUrl($page + 1)) ?>">Older <?= pt_icon('chevron-right') ?></a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
