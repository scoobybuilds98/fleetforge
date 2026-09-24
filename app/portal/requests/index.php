<?php
declare(strict_types=1);

/**
 * app/portal/requests/index.php
 *
 * Customer portal — Requests (S-PORTAL-REDESIGN).
 *
 * Every request anyone on the account has opened (extensions, returns,
 * repairs, billing questions, payment notices…), newest activity first,
 * with Open / Resolved / All tabs, search, who replied last, and a start-a-
 * request call to action. Types use the customer-facing labels from
 * pt_request_types() (was ucfirst(str_replace()) of the enum).
 *
 * Trap 8: scoped to portal_customer_id().
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid   = portal_customer_id();
$types = pt_request_types();

$rows = db_select(
    "SELECT psr.id, psr.request_type, psr.subject, psr.status, psr.created_at, psr.updated_at,
            pu.name AS submitter, l.contract_number, eu.unit_number,
            (SELECT m.sender_type FROM portal_service_request_messages m
              WHERE m.request_id = psr.id AND m.is_internal = 0 ORDER BY m.id DESC LIMIT 1) AS last_sender,
            (SELECT MAX(m.created_at) FROM portal_service_request_messages m
              WHERE m.request_id = psr.id AND m.is_internal = 0) AS last_message_at,
            (SELECT COUNT(*) FROM portal_service_request_messages m
              WHERE m.request_id = psr.id AND m.is_internal = 0) AS replies
       FROM portal_service_requests psr
       LEFT JOIN portal_users pu ON pu.id = psr.portal_user_id
       LEFT JOIN leases l ON l.id = psr.lease_id
       LEFT JOIN equipment_units eu ON eu.id = psr.equipment_unit_id
      WHERE psr.customer_id = ?
      ORDER BY COALESCE((SELECT MAX(m2.created_at) FROM portal_service_request_messages m2
                          WHERE m2.request_id = psr.id AND m2.is_internal = 0), psr.created_at) DESC
      LIMIT 300",
    [$cid]
);

$list = [];
$counts = ['open' => 0, 'done' => 0, 'all' => 0];
foreach ($rows as $r) {
    $open = in_array($r['status'], ['open', 'in_review'], true);
    $counts[$open ? 'open' : 'done']++;
    $counts['all']++;
    [$sl, $st] = match ($r['status']) {
        'open'      => ['Open', 'info'],
        'in_review' => ['In progress', 'warning'],
        'resolved'  => ['Resolved', 'success'],
        'closed'    => ['Closed', 'neutral'],
        default     => [ucfirst((string) $r['status']), 'neutral'],
    };
    $about = trim(($r['contract_number'] ? 'Lease ' . $r['contract_number'] : '') . ($r['unit_number'] ? ($r['contract_number'] ? ' · ' : '') . 'Unit ' . $r['unit_number'] : ''));
    $list[] = [
        'id'       => (int) $r['id'],
        'subject'  => (string) $r['subject'],
        'type'     => $types[$r['request_type']][0] ?? 'Request',
        'icon'     => pt_icon($types[$r['request_type']][1] ?? 'chat-bubble-left-ellipsis'),
        'group'    => $open ? 'open' : 'done',
        'status'   => $sl,
        'tone'     => $st,
        'about'    => $about,
        'by'       => (string) ($r['submitter'] ?? ''),
        'when'     => format_datetime($r['last_message_at'] ?: $r['created_at'], 'M j, Y'),
        'waiting'  => $r['last_sender'] === 'admin' && $open,
        'replies'  => (int) $r['replies'],
        'url'      => pt_url('requests/view?id=' . (int) $r['id']),
    ];
}

$pageTitle = 'Requests';
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'eyebrow' => 'Help',
    'title'   => 'Requests',
    'sub'     => 'Extensions, returns, repairs, billing questions — everything you\'ve asked us, and our replies.',
    'actions' => '<a class="pt-btn pt-btn--primary" href="' . e(pt_url('requests/create')) . '">' . pt_icon('plus') . ' New request</a>',
]);
?>

<section class="pt-card" x-data="{
        tab: <?= e(json_encode($counts['open'] > 0 ? 'open' : 'all')) ?>, q: '',
        rows: <?= e(json_encode($list)) ?>,
        get view() {
            const t = this.q.trim().toLowerCase();
            return this.rows.filter(r => (this.tab === 'all' || r.group === this.tab)
                && (!t || r.subject.toLowerCase().includes(t) || r.about.toLowerCase().includes(t) || r.type.toLowerCase().includes(t)));
        }
    }">
    <div class="pt-toolbar">
        <div class="pt-tabs" role="tablist">
            <?php foreach (['open' => 'Open', 'done' => 'Resolved', 'all' => 'All'] as $k => $label): ?>
                <button type="button" class="pt-tab" role="tab" :class="{ 'is-active': tab === '<?= $k ?>' }" @click="tab = '<?= $k ?>'"><?= e($label) ?> <span class="pt-tab-count"><?= (int) $counts[$k] ?></span></button>
            <?php endforeach; ?>
        </div>
        <div class="pt-toolbar-spacer"></div>
        <label class="pt-search-field"><?= pt_icon('magnifying-glass') ?><span class="pt-sr">Search requests</span><input type="search" class="pt-input" placeholder="Search requests" x-model="q"></label>
    </div>

    <?php if (!$list): ?>
        <?= pt_empty('wrench-screwdriver', 'No requests yet', 'Need an extension, a repair or a copy of a document? Start a request and we\'ll take it from there.', '<a class="pt-btn pt-btn--primary pt-btn--sm" href="' . e(pt_url('requests/create')) . '">Start a request</a>') ?>
    <?php else: ?>
        <ul class="pt-attn" style="padding:8px">
            <template x-for="r in view" :key="r.id">
                <li>
                    <a class="pt-attn-item" :href="r.url" :data-tone="r.waiting ? 'brand' : (r.group === 'open' ? 'info' : 'success')">
                        <span class="pt-attn-ic" x-html="r.icon"></span>
                        <span class="pt-attn-body">
                            <span class="pt-attn-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <span x-text="r.subject"></span>
                                <span class="pt-pill pt-pill--brand" x-show="r.waiting">New reply</span>
                            </span>
                            <span class="pt-attn-text" style="display:block" x-text="[r.type, r.about, r.by ? 'by ' + r.by : '', r.when].filter(Boolean).join(' · ')"></span>
                        </span>
                        <span class="pt-pill" :class="'pt-pill--' + r.tone" x-text="r.status"></span>
                        <span class="pt-attn-go"><?= pt_icon('chevron-right') ?></span>
                    </a>
                </li>
            </template>
        </ul>
        <div x-show="view.length === 0" x-cloak><?= pt_empty('magnifying-glass', 'Nothing here', 'No requests match this view.') ?></div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
