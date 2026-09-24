<?php
declare(strict_types=1);

/**
 * app/admin/notifications/index.php
 *
 * The full Notifications page (S-ATTENTION-INBOX): the bell, with room.
 *
 * Tabs (?tab=):
 *   attention (default) — Needs attention: one shared item per problem,
 *                         urgent first, oldest first. Take / give to /
 *                         snooze / done (+ note) / add note, and each item's
 *                         history. Alpine component FF_AttentionPage below,
 *                         data from api/v1/attention/{index,show,act}.php.
 *   snoozed             — items someone pushed to a later date (wake now).
 *   closed              — done by a person or resolved by the system in the
 *                         last 60 days; a done item whose problem is still
 *                         there can be reopened.
 *   updates             — the activity feed (the old flat list): filters,
 *                         paging, mark read, delete. Server-rendered. Money
 *                         in the text is scrubbed for users without
 *                         payments:view, at serve time.
 *
 * Replaced the NOTIF-1 flat/grouped page, whose list was 99% repeats and
 * activity nobody read (7,000 unread per manager on production).
 *
 * @method  GET
 * @auth    require_auth
 * @session NOTIF-1, S-ATTENTION-INBOX
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php,
 *          lib/Attention/AttentionService.php, lib/Ui/ModuleHero.php,
 *          api/v1/attention/*, api/v1/notifications/{mark_read,delete}.php,
 *          public/assets/css/attention.css, public/assets/js/app.js (FF_Attention)
 */

use FleetForge\Attention\AttentionService;

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

$userId = current_user_id();
if (!$userId) {
    header('Location: ' . base_url('auth/login'));
    exit;
}
$role     = (string) (current_user()['role_slug'] ?? '');
$canMoney = can_view_financials();

$tab = (string) ($_GET['tab'] ?? 'attention');
if (!in_array($tab, ['attention', 'snoozed', 'closed', 'updates'], true)) {
    $tab = 'attention';
}

$badge = AttentionService::badge($userId, $role);

// ── Updates tab (server-rendered) ──────────────────────────────────────────────
$rows = [];
$total = 0;
$totalPages = 1;
$page = 1;
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
    'quickbooks'   => 'QuickBooks',
    'system'       => 'System',
];

if ($tab === 'updates') {
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
            // created_at is UTC — company-local midnight as a UTC instant.
            $where[]  = 'n.created_at >= ?';
            $params[] = ff_local_day_start_utc();
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

    $whereSQL   = implode(' AND ', $where);
    $perPage    = 25;
    $total      = db_count("SELECT COUNT(*) FROM notifications n WHERE $whereSQL", $params);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min(max(1, clean_int($_GET['page'] ?? 1) ?? 1), $totalPages);
    $offset     = ($page - 1) * $perPage;

    // Strictly newest first: a feed, not a to-do list.
    $rows = db_select(
        "SELECT n.id, n.title, n.message, n.type, n.category, n.url, n.group_count,
                n.severity, n.is_read, n.created_at
           FROM notifications n
          WHERE $whereSQL
          ORDER BY n.created_at DESC, n.id DESC
          LIMIT $perPage OFFSET $offset",
        $params
    );
}

/**
 * Relative time for a UTC stamp ("5 min ago", "3 days ago", "Sep 2, 2026").
 *
 * @param  string $createdAt UTC DATETIME
 * @return string
 */
function notif_time_ago(string $createdAt): string
{
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
 * Heroicon for an update category (falls back to "bell").
 *
 * @param  string|null $category
 * @return string
 */
function notif_category_icon(?string $category): string
{
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
        'accounting', 'quickbooks' => 'calculator',
        'system'       => 'cog-6-tooth',
        default        => 'bell',
    };
}

/**
 * Updates-tab pagination URL, keeping the current filters.
 *
 * @param  int $p
 * @return string
 */
function notif_page_url(int $p): string
{
    $q = $_GET;
    $q['page'] = $p;
    return '?' . http_build_query($q);
}

$tabUrl = static fn(string $t): string => base_url('notifications') . ($t === 'attention' ? '' : '?tab=' . $t);

$pageTitle      = 'Notifications';
$helpModuleSlug = 'notifications';
require_once FF_ROOT . '/includes/header.php';
?>

<?php ob_start(); ?>
        <?= help_button('notifications') ?>
        <?php if (is_super_admin()): ?>
        <a href="<?= e(base_url('settings') . '?tab=notifications') ?>" class="btn btn-secondary btn-sm">Notification settings</a>
        <?php endif; ?>
        <a href="<?= e(base_url('profile')) ?>#notifications" class="btn btn-secondary btn-sm">My notifications</a>
<?= \FleetForge\Ui\ModuleHero::render([
    'crumbs'   => [['Dashboard', base_url('dashboard')], ['Notifications', null]],
    'eyebrow'  => 'Workspace',
    'icon'     => 'bell',
    'accent'   => 'warning',   // the module's colour, not its state — urgency shows in the facts + tabs
    'title'    => 'Notifications',
    'subtitle' => 'What needs someone, shared by the team — each problem once, until it\'s fixed. Plus everything that\'s been happening.',
    'facts'    => array_values(array_filter([
        '<b>' . (int) $badge['total'] . '</b> open',
        $badge['urgent'] > 0 ? '<b>' . (int) $badge['urgent'] . '</b> urgent' : null,
        $badge['mine'] > 0 ? '<b>' . (int) $badge['mine'] . '</b> yours' : null,
    ])),
    'art'      => 'notifications',
    'actions'  => ob_get_clean(),
]) ?>

<nav class="att-page-tabs" aria-label="Notification views">
    <a class="att-tab" href="<?= e($tabUrl('attention')) ?>" aria-selected="<?= $tab === 'attention' ? 'true' : 'false' ?>">
        Needs attention
        <?php if ($badge['total'] > 0): ?>
            <span class="att-count <?= $badge['urgent'] > 0 ? 'att-count--urgent' : 'att-count--todo' ?>"><?= (int) $badge['total'] ?></span>
        <?php endif; ?>
    </a>
    <a class="att-tab" href="<?= e($tabUrl('snoozed')) ?>" aria-selected="<?= $tab === 'snoozed' ? 'true' : 'false' ?>">Snoozed</a>
    <a class="att-tab" href="<?= e($tabUrl('closed')) ?>" aria-selected="<?= $tab === 'closed' ? 'true' : 'false' ?>">Closed</a>
    <a class="att-tab" href="<?= e($tabUrl('updates')) ?>" aria-selected="<?= $tab === 'updates' ? 'true' : 'false' ?>">
        Updates
        <?php if ($badge['updates_unread'] > 0): ?><span class="att-newdot" aria-label="new"></span><?php endif; ?>
    </a>
</nav>

<?php if ($tab !== 'updates'): ?>
<!-- ============================================================
     NEEDS ATTENTION / SNOOZED / CLOSED — FF_AttentionPage (below)
     ============================================================ -->
<div x-data="FF_AttentionPage(<?= e(json_encode(['view' => $tab === 'attention' ? 'open' : $tab])) ?>)">

    <div class="att-page-toolbar">
        <template x-if="view === 'open'">
            <div class="att-filters" style="padding:0">
                <button type="button" class="att-chip" :aria-pressed="owner === 'all'"  @click="setOwner('all')">All</button>
                <button type="button" class="att-chip" :aria-pressed="owner === 'mine'" @click="setOwner('mine')">Mine</button>
                <button type="button" class="att-chip" :aria-pressed="owner === 'free'" @click="setOwner('free')">Nobody on it</button>
            </div>
        </template>
        <select class="form-select form-control-sm" x-model="kind" @change="load()" aria-label="Filter by kind">
            <option value="">Every kind</option>
            <template x-for="k in kinds" :key="k.key">
                <option :value="k.key" x-text="k.label"></option>
            </template>
        </select>
        <input type="search" class="form-control form-control-sm" placeholder="Search…" aria-label="Search items"
               x-model.debounce.400ms="q" @input.debounce.400ms="load()">
        <span class="text-secondary text-sm" x-show="loaded" x-text="total + (total === 1 ? ' item' : ' items')"></span>
    </div>

    <div class="att-list">
        <div class="notif-loading" x-show="!loaded">Loading…</div>

        <div class="att-empty" x-show="loaded && items.length === 0" x-cloak>
            <?= heroicon('check-circle', 'nav-icon') ?>
            <p class="att-empty-title" x-text="emptyTitle()"></p>
            <p class="att-empty-sub" x-text="emptySub()"></p>
        </div>

        <template x-for="grp in groups" :key="grp.key">
            <div>
                <div class="att-group-h" :class="'att-group-h--' + grp.key" x-show="grp.label">
                    <span x-text="grp.label"></span> · <span x-text="grp.items.length"></span>
                </div>
                <template x-for="it in grp.items" :key="it.id">
                    <div class="att-item" :class="'att-item--' + it.priority">
                        <span class="att-stripe" aria-hidden="true"></span>
                        <div class="att-body">
                            <a class="att-title" :href="it.url || '#'" x-text="it.title"></a>
                            <div class="att-facts" x-show="it.facts.length" x-text="it.facts.join(' · ')"></div>
                            <div class="att-pills">
                                <span class="att-pill" x-text="it.kind_label"></span>
                                <span class="att-pill att-pill--age" x-show="view !== 'closed'" x-text="'open ' + it.age"></span>
                                <span class="att-pill att-pill--esc" x-show="it.escalated">Escalated</span>
                                <span class="att-pill" x-show="view === 'snoozed'" x-text="'Until ' + (it.snoozed_label || '')"></span>
                                <span class="att-pill" :class="it.assigned ? 'att-pill--owner' : ''" x-show="view !== 'closed'" x-text="FF_Attention.ownerLabel(it)"></span>
                            </div>

                            <p class="att-closed-note" x-show="view === 'closed'">
                                <template x-if="it.closed_by">
                                    <span><b x-text="'Done by ' + it.closed_by"></b> · <span x-text="it.closed_label"></span><span x-show="it.close_note" x-text="' — “' + it.close_note + '”'"></span></span>
                                </template>
                                <template x-if="!it.closed_by">
                                    <span><b>Fixed</b> · closed by itself · <span x-text="it.closed_label"></span></span>
                                </template>
                            </p>

                            <div class="att-actions">
                                <a class="att-btn att-btn--primary" :href="it.url || '#'">Open</a>
                                <template x-if="view === 'open'">
                                    <span style="display:contents">
                                        <button type="button" class="att-btn" x-show="!it.assigned || !it.assigned.is_me" :disabled="!!busy[it.id]" @click="act(it, 'take')">Take it</button>
                                        <button type="button" class="att-btn" x-show="it.assigned && it.assigned.is_me" :disabled="!!busy[it.id]" @click="act(it, 'release')">Release</button>
                                        <button type="button" class="att-btn" :disabled="!!busy[it.id]" @click="setPanel(it.id, 'snooze')">Snooze</button>
                                        <button type="button" class="att-btn att-btn--ghost" :disabled="!!busy[it.id]" @click="setPanel(it.id, 'done')">Done</button>
                                    </span>
                                </template>
                                <template x-if="view === 'snoozed'">
                                    <button type="button" class="att-btn" :disabled="!!busy[it.id]" @click="act(it, 'wake')">Bring back now</button>
                                </template>
                                <template x-if="view === 'closed' && it.closed_by && it.is_live">
                                    <button type="button" class="att-btn" :disabled="!!busy[it.id]" @click="act(it, 'reopen')">Reopen</button>
                                </template>
                                <button type="button" class="att-btn att-btn--ghost" @click="toggleDetail(it)"
                                        :aria-expanded="!!detail[it.id]" x-text="detail[it.id] ? 'Hide history' : 'History'"></button>
                            </div>

                            <div class="att-inline" x-show="panel[it.id] === 'snooze'" x-cloak>
                                <template x-for="c in FF_Attention.snoozeChoices" :key="c.v">
                                    <button type="button" class="att-chip" :disabled="!!busy[it.id]" @click="snoozeTo(it, c.v)" x-text="c.label"></button>
                                </template>
                                <input type="date" class="att-date" :min="FF_Attention.tomorrowIso()" :aria-label="'Snooze until'" x-model="snoozeDate[it.id]">
                                <button type="button" class="att-btn" :disabled="!!busy[it.id]" @click="snoozeTo(it, snoozeDate[it.id])">Snooze to date</button>
                            </div>
                            <div class="att-inline" x-show="panel[it.id] === 'done'" x-cloak>
                                <input type="text" class="att-note" maxlength="500"
                                       :placeholder="it.done_needs_note ? 'What was done? e.g. Called, paying Friday' : 'Note (optional)'"
                                       :aria-label="'Note for ' + it.title" x-model="notes[it.id]" @keydown.enter.prevent="confirmDone(it)">
                                <button type="button" class="att-btn att-btn--primary" :disabled="!!busy[it.id]" @click="confirmDone(it)">Mark done</button>
                            </div>
                            <p class="att-error" x-show="errors[it.id]" x-text="errors[it.id]" role="alert"></p>

                            <div class="att-detail" x-show="detail[it.id]" x-cloak>
                                <ul class="att-history">
                                    <template x-for="(ev, i) in (detail[it.id] ? detail[it.id].events : [])" :key="i">
                                        <li><time x-text="ev.at_label"></time><span><b x-text="ev.who"></b> <span x-text="actionLabel(ev.action)"></span><span x-show="ev.note" x-text="': ' + ev.note"></span></span></li>
                                    </template>
                                </ul>
                                <template x-if="view === 'open' && detail[it.id]">
                                    <div class="att-inline" style="margin-top:0">
                                        <select class="form-select form-control-sm" style="width:auto" x-model="giveTo[it.id]" aria-label="Give to">
                                            <option value="">Give to…</option>
                                            <template x-for="p in detail[it.id].people" :key="p.id">
                                                <option :value="p.id" x-text="p.name"></option>
                                            </template>
                                        </select>
                                        <button type="button" class="att-btn" :disabled="!!busy[it.id] || !giveTo[it.id]" @click="give(it)">Give</button>
                                        <input type="text" class="att-note" maxlength="500" placeholder="Add a note for the team" aria-label="Add a note" x-model="noteDraft[it.id]" @keydown.enter.prevent="addNote(it)">
                                        <button type="button" class="att-btn" :disabled="!!busy[it.id]" @click="addNote(it)">Add note</button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <div class="notif-dropdown-footer" style="position:static" x-show="loaded && total > items.length">
            <button type="button" class="btn btn-link btn-sm" @click="loadMore()">Show more</button>
        </div>
    </div>
</div>

<script>
/**
 * FF_AttentionPage — the Needs attention / Snoozed / Closed tabs.
 * Shares FF_Attention (app.js) with the bell. Lists are computed into data
 * after each load/action (no function calls inside x-for — scale trap).
 */
function FF_AttentionPage(opts) {
    return {
        view: opts.view,
        owner: 'all',
        kind: '',
        q: '',
        items: [],
        groups: [],
        kinds: [],
        total: 0,
        loaded: false,
        busy: {}, panel: {}, notes: {}, snoozeDate: {}, errors: {},
        detail: {}, giveTo: {}, noteDraft: {},
        _limit: 50,

        init() {
            this.load();
        },

        async load() {
            const qs = new URLSearchParams({ view: this.view, owner: this.owner, kind: this.kind, q: this.q, limit: this._limit });
            try {
                const res = await FF_Api.get(FF_Api.url('/api/v1/attention/index.php?' + qs.toString()));
                if (res?.success) {
                    this.items = res.data.items || [];
                    this.total = res.data.total || 0;
                    this.kinds = res.data.kinds || [];
                    this.regroup();
                }
            } catch { /* keep what we had */ }
            this.loaded = true;
        },

        loadMore() {
            this._limit += 50;
            this.load();
        },

        setOwner(o) {
            this.owner = o;
            this.load();
        },

        regroup() {
            if (this.view !== 'open') {
                this.groups = this.items.length ? [{ key: 'all', label: '', items: this.items }] : [];
                return;
            }
            const urgent = this.items.filter(i => i.priority === 'urgent');
            const todo   = this.items.filter(i => i.priority !== 'urgent');
            this.groups = [
                { key: 'urgent', label: 'Urgent', items: urgent },
                { key: 'todo',   label: 'To do',  items: todo },
            ].filter(g => g.items.length);
        },

        emptyTitle() {
            if (this.view === 'snoozed') return 'Nothing is snoozed.';
            if (this.view === 'closed')  return 'Nothing closed in the last 60 days.';
            if (this.owner === 'mine')   return 'Nothing is yours right now.';
            if (this.owner === 'free')   return 'Everything has someone on it.';
            return (this.kind || this.q) ? 'Nothing matches.' : 'All clear.';
        },

        emptySub() {
            if (this.view !== 'open' || this.owner !== 'all' || this.kind || this.q) return '';
            return 'Nothing needs anyone right now. Problems appear here once, and leave when they’re fixed.';
        },

        setPanel(id, which) {
            this.panel = { ...this.panel, [id]: this.panel[id] === which ? null : which };
            this.errors = { ...this.errors, [id]: '' };
        },

        async act(it, action, extra = {}) {
            if (this.busy[it.id]) return;
            this.busy = { ...this.busy, [it.id]: true };
            this.errors = { ...this.errors, [it.id]: '' };
            try {
                const res = await FF_Attention.act(it.id, action, extra);
                if (!res?.success) {
                    this.errors = { ...this.errors, [it.id]: res?.error?.message || 'That didn’t work. Try again.' };
                    return;
                }
                const u = res.data.item;
                this.panel = { ...this.panel, [it.id]: null };
                const stays = (this.view === 'open' && u.status === 'open'
                        && !(this.owner === 'mine' && !(u.assigned && u.assigned.is_me))
                        && !(this.owner === 'free' && u.assigned))
                    || (this.view === 'snoozed' && u.status === 'snoozed')
                    || (this.view === 'closed' && (u.status === 'done' || u.status === 'resolved'));
                this.items = stays ? this.items.map(x => (x.id === it.id ? u : x)) : this.items.filter(x => x.id !== it.id);
                if (!stays) this.total = Math.max(0, this.total - 1);
                this.regroup();
                if (this.detail[it.id]) this.fetchDetail(it.id);
                FF_Attention.toast(action, u);
            } catch {
                this.errors = { ...this.errors, [it.id]: 'Network error. Try again.' };
            } finally {
                this.busy = { ...this.busy, [it.id]: false };
            }
        },

        confirmDone(it) {
            const note = (this.notes[it.id] || '').trim();
            if (it.done_needs_note && !note) {
                this.errors = { ...this.errors, [it.id]: 'Add a short note: the problem is still there, so say what was done.' };
                return;
            }
            this.act(it, 'done', { note });
        },

        snoozeTo(it, until) {
            if (!until) {
                this.errors = { ...this.errors, [it.id]: 'Pick a date.' };
                return;
            }
            this.act(it, 'snooze', { until });
        },

        give(it) {
            const uid = parseInt(this.giveTo[it.id] || '0', 10);
            if (uid > 0) this.act(it, 'assign', { user_id: uid });
        },

        async addNote(it) {
            const note = (this.noteDraft[it.id] || '').trim();
            if (!note) return;
            await this.act(it, 'note', { note });
            this.noteDraft = { ...this.noteDraft, [it.id]: '' };
        },

        async toggleDetail(it) {
            if (this.detail[it.id]) {
                this.detail = { ...this.detail, [it.id]: null };
                return;
            }
            await this.fetchDetail(it.id);
        },

        async fetchDetail(id) {
            try {
                const res = await FF_Api.get(FF_Api.url('/api/v1/attention/show.php?id=' + id));
                if (res?.success) {
                    this.detail = { ...this.detail, [id]: { events: res.data.events || [], people: res.data.people || [] } };
                }
            } catch { /* leave closed */ }
        },

        actionLabel(a) {
            return {
                opened: 'opened this', worse: 'marked it worse', taken: 'took it', released: 'released it',
                snoozed: 'snoozed it', woke: 'brought it back', done: 'marked it done', reopened: 'reopened it',
                resolved: 'closed it (fixed)', escalated: 'escalated it', note: 'added a note',
            }[a] || a;
        },
    };
}
</script>

<?php else: ?>
<!-- ============================================================
     UPDATES — the activity feed (server-rendered)
     ============================================================ -->
<form method="GET" action="" class="table-toolbar">
    <input type="hidden" name="tab" value="updates">
    <div class="table-toolbar-left table-toolbar-left--wrap">
        <input type="search" name="q" class="form-control form-control-sm"
               value="<?= e($search) ?>" maxlength="255" style="min-width:220px;"
               placeholder="Search updates…" aria-label="Search updates">

        <select name="is_read" class="form-select form-control-sm" onchange="this.form.submit()" aria-label="Filter by read status">
            <option value="all"    <?= $isReadFilter === 'all'    ? 'selected' : '' ?>>Read and unread</option>
            <option value="unread" <?= $isReadFilter === 'unread' ? 'selected' : '' ?>>Unread</option>
            <option value="read"   <?= $isReadFilter === 'read'   ? 'selected' : '' ?>>Read</option>
        </select>

        <select name="category" class="form-select form-control-sm" onchange="this.form.submit()" aria-label="Filter by category">
            <option value="">All categories</option>
            <?php foreach ($allowedCategories as $slug => $label): ?>
            <option value="<?= e($slug) ?>" <?= $category === $slug ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="date_range" class="form-select form-control-sm" onchange="this.form.submit()" aria-label="Filter by date range">
            <option value="all"   <?= $dateRange === 'all'   ? 'selected' : '' ?>>All time</option>
            <option value="today" <?= $dateRange === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="week"  <?= $dateRange === 'week'  ? 'selected' : '' ?>>This week</option>
            <option value="month" <?= $dateRange === 'month' ? 'selected' : '' ?>>This month</option>
        </select>

        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="<?= e($tabUrl('updates')) ?>" class="btn btn-secondary btn-sm">Reset</a>
    </div>
    <div class="table-toolbar-right">
        <button type="button" class="btn btn-secondary btn-sm" id="notif-mark-all-btn" <?= $badge['updates_unread'] === 0 ? 'disabled' : '' ?>>Mark all read</button>
        <button type="button" class="btn btn-secondary btn-sm" id="notif-clear-read-btn">Clear read</button>
    </div>
</form>

<div class="card notif-list-card">
    <div class="card-body notif-list-body">
        <?php if (empty($rows)): ?>
            <div class="notif-page-empty">
                <?= heroicon('bell', 'nav-icon') ?>
                <p class="notif-page-empty-title">No updates found</p>
                <p class="notif-page-empty-sub">Leases, invoices, payments and other activity show up here.</p>
            </div>
        <?php else: ?>
            <ul class="notif-page-list">
                <?php foreach ($rows as $n):
                    $unread = (int) $n['is_read'] === 0;
                    // Redact at serve time: roles without payments:view keep the
                    // update, minus the dollar amounts.
                    $nTitle   = $canMoney ? (string) $n['title'] : ff_scrub_money_text((string) $n['title']);
                    $nMessage = $canMoney ? (string) $n['message'] : ff_scrub_money_text((string) $n['message']);
                ?>
                <li class="notif-page-item <?= $unread ? 'notif-page-item--unread' : '' ?>" data-id="<?= (int) $n['id'] ?>">
                    <div class="notif-icon <?= e('notif-icon--' . ($n['category'] ?? 'system')) ?>">
                        <?= heroicon(notif_category_icon($n['category']), 'nav-icon') ?>
                    </div>
                    <div class="notif-page-content">
                        <div class="notif-page-title"><?= e($nTitle) ?></div>
                        <div class="notif-page-message"><?= e($nMessage) ?></div>
                        <div class="notif-page-meta">
                            <span title="<?= e($n['created_at']) ?> UTC"><?= e(notif_time_ago((string) $n['created_at'])) ?></span>
                            <?php if ((int) $n['group_count'] > 1): ?>
                                · <span class="badge badge-neutral" style="font-size:0.65rem;padding:2px 7px;"><?= (int) $n['group_count'] ?> grouped</span>
                            <?php endif; ?>
                            <?php if (!empty($n['category'])): ?>
                                · <span class="badge badge-neutral" style="font-size:0.65rem;padding:2px 7px;"><?= e($allowedCategories[$n['category']] ?? ucfirst((string) $n['category'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="notif-page-actions">
                        <?php if (!empty($n['url'])): ?>
                            <a href="<?= e($n['url']) ?>" class="btn btn-link btn-xs notif-page-link" data-id="<?= (int) $n['id'] ?>">Open</a>
                        <?php endif; ?>
                        <?php if ($unread): ?>
                            <button type="button" class="btn btn-link btn-xs notif-page-mark" data-id="<?= (int) $n['id'] ?>">Mark read</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-link btn-xs notif-page-delete" data-id="<?= (int) $n['id'] ?>">Delete</button>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer notif-page-footer">
        <span class="text-muted notif-page-footer-text">Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($total) ?> total</span>
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

<script>
// ── Updates actions (NOTIF-1) — POSTs go through FF_Api (CSRF + cookies). ──
(function () {
    const endpoint = (path) => FF_Api.url('/api/v1/notifications/' + path);

    document.querySelectorAll('.notif-page-mark').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                const res = await FF_Api.post(endpoint('mark_read.php'), { notification_id: parseInt(btn.dataset.id, 10) });
                if (res?.success) {
                    btn.closest('.notif-page-item')?.classList.remove('notif-page-item--unread');
                    btn.remove();
                }
            } catch { /* silent */ }
        });
    });

    document.querySelectorAll('.notif-page-link').forEach(link => {
        link.addEventListener('click', async () => {
            try { await FF_Api.post(endpoint('mark_read.php'), { notification_id: parseInt(link.dataset.id, 10) }); } catch {}
        });
    });

    document.querySelectorAll('.notif-page-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!(await FF_Confirm.ask('Delete this update? This cannot be undone.'))) return;
            try {
                const res = await FF_Api.post(endpoint('delete.php'), { notification_id: parseInt(btn.dataset.id, 10) });
                if (res?.success) {
                    btn.closest('.notif-page-item')?.remove();
                    if (window.FF_Toast) FF_Toast.success('Deleted', '');
                }
            } catch { /* silent */ }
        });
    });

    document.getElementById('notif-mark-all-btn')?.addEventListener('click', async () => {
        try {
            const res = await FF_Api.post(endpoint('mark_read.php'), { mark_all: true });
            if (res?.success) window.location.reload();
        } catch { /* silent */ }
    });

    document.getElementById('notif-clear-read-btn')?.addEventListener('click', async () => {
        if (!(await FF_Confirm.ask('Delete all read updates? This cannot be undone.'))) return;
        try {
            const res = await FF_Api.post(endpoint('delete.php'), { clear_all_read: true });
            if (res?.success) window.location.reload();
        } catch { /* silent */ }
    });
})();
</script>
<?php endif; ?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
