<?php
declare(strict_types=1);

/**
 * app/admin/users/index.php
 *
 * Users list page.
 * Server-renders 4 KPI tiles, then Alpine.js loads the filterable table.
 *
 * S-USERS-CONSOLIDATE C2 (2026-05-14): KPI tiles + columns enriched.
 *   - KPI tiles: Total / Active / Pending Invite / MFA Enrolled % (D-C).
 *   - Columns added: MFA status pill (green/amber/red), relative last-login
 *     time, colored role badge. status='invited' rows render muted+italic.
 *   - API at api/v1/users/index.php now returns mfa_enabled + mfa_required.
 *
 * Filters: q (search), role_id, status, mfa.
 * Sort: name, email, last_login_at, created_at, status.
 *
 * D5: SOFT_DELETE — queries include deleted_at IS NULL.
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/users/index.php
 * @decisions D5/D7/D30/D32 + S-USERS-CONSOLIDATE D-C
 * @session  S017 / S-USERS-CONSOLIDATE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('users', 'view');

// ── KPI tiles (server-rendered; D-C) ────────────────────────────────────────
// WHY this 4-tile set: total team headcount + active operators + pending
// invites + MFA enrolment % is the operator's daily compliance scan. The
// previous Total/Active/Invited/Suspended set duplicated Active across
// tiles 1+2 (Total includes Active) and surfaced Suspended which is rare
// and lower-priority. MFA % is now load-bearing post-S-AUTH-FIX.
$kpis = [
    'total'   => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status != 'locked'"),
    'active'  => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active'"),
    'invited' => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'invited'"),
    // MFA percentage uses only active users as the denominator — invited
    // users haven't accepted yet so they can't be enrolled; counting them
    // would deflate the metric and obscure compliance signal.
    'mfa_enrolled' => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active' AND mfa_enabled = 1"),
];
$kpis['mfa_pct'] = $kpis['active'] > 0
    ? (int) round(($kpis['mfa_enrolled'] / $kpis['active']) * 100)
    : 0;

// All roles for the filter dropdown
$roles = db_select("SELECT id, name, slug FROM user_roles ORDER BY id ASC");

$pageTitle = 'Users';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Users</h1>
    <?php if (can('users', 'create')): ?>
    <a href="<?= base_url('users/create') ?>" class="btn btn-primary btn-sm">
        + Invite New User
    </a>
    <?php endif; ?>
</div>

<!-- TILES-1: KPI tiles dispatch `ff-users-filter` to the Alpine component
     below. Each tile toggles the matching status filter. The MFA tile is
     non-clickable since there is no DB-backed "show only MFA-off users"
     filter wired in this commit (could be added if a real operator need
     surfaces — current scope ships the visibility, not the filter). -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-users-filter',{detail:{status:''}}))">
        <div class="stat-label">Total Team</div>
        <div class="stat-value font-mono"><?= e((string)$kpis['total']) ?></div>
        <div class="stat-delta">active + invited + inactive</div>
    </div>

    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-users-filter',{detail:{status:'active'}}))">
        <div class="stat-label">Active</div>
        <div class="stat-value font-mono"><?= e((string)$kpis['active']) ?></div>
        <div class="stat-delta">can log in</div>
    </div>

    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-users-filter',{detail:{status:'invited'}}))">
        <div class="stat-label">Pending Invite</div>
        <div class="stat-value font-mono"><?= e((string)$kpis['invited']) ?></div>
        <div class="stat-delta">awaiting acceptance</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">MFA Enrolled</div>
        <div class="stat-value font-mono"><?= e((string)$kpis['mfa_pct']) ?>%</div>
        <div class="stat-delta"><?= e((string)$kpis['mfa_enrolled']) ?> of <?= e((string)$kpis['active']) ?> active</div>
    </div>

</div>

<!-- ── Table (Alpine.js) ──────────────────────────────────────────────────── -->
<!-- TILES-1: @ff-users-filter.window toggles the status filter and reloads. -->
<div x-data="usersList()"
     x-init="init()"
     @ff-users-filter.window="
        filters.status = (filters.status === $event.detail.status && filters.status !== '') ? '' : $event.detail.status;
        goPage(1);
     ">

    <!-- Filter toolbar -->
    <div class="table-toolbar">
        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search name or email…"
                   x-model="filters.q"
                   @input.debounce.400ms="goPage(1)"
                   style="min-width:220px;"
                   aria-label="Search users">

            <select class="form-select form-control-sm"
                    x-model="filters.role_id"
                    @change="goPage(1)"
                    aria-label="Filter by role">
                <option value="">All Roles</option>
                <?php foreach ($roles as $role): ?>
                <option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.status"
                    @change="goPage(1)"
                    aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="invited">Invited</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
                <option value="locked">Locked</option>
            </select>

            <button class="btn btn-secondary btn-sm"
                    @click="resetFilters()">Reset</button>
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="total > 0 ? total + ' user' + (total === 1 ? '' : 's') : ''"></span>
        </div>
    </div>

    <!-- Table card -->
    <div class="card">

        <!-- Loading -->
        <template x-if="loading">
            <div class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading…</span>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && rows.length === 0">
            <div class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No users found</p>
                    <p class="empty-state-text">Try adjusting your filters, or invite a new user.</p>
                </div>
            </div>
        </template>

        <!-- Table -->
        <template x-if="!loading && rows.length > 0">
            <div class="table-wrapper">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th @click="setSort('name')" style="cursor:pointer;">
                                Name <span x-text="sortIcon('name')"></span>
                            </th>
                            <th @click="setSort('email')" style="cursor:pointer;">
                                Email <span x-text="sortIcon('email')"></span>
                            </th>
                            <th>Role</th>
                            <th @click="setSort('status')" style="cursor:pointer;">
                                Status <span x-text="sortIcon('status')"></span>
                            </th>
                            <th>MFA</th>
                            <th @click="setSort('last_login_at')" style="cursor:pointer;">
                                Last Login <span x-text="sortIcon('last_login_at')"></span>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in rows" :key="row.id">
                            <tr @click="window.location = '<?= base_url('users/show') ?>?id=' + row.id"
                                :class="row.status === 'invited' ? 'is-invited' : ''"
                                :style="(row.status === 'invited' ? 'opacity:0.78;font-style:italic;' : '') + 'cursor:pointer;'">
                                <td x-text="row.name"></td>
                                <td x-text="row.email"></td>
                                <td>
                                    <span class="badge badge-pill"
                                          :class="roleBadge(row.role_slug)"
                                          x-text="row.role_name"></span>
                                </td>
                                <td>
                                    <span class="badge"
                                          :class="statusBadge(row.status)"
                                          x-text="statusLabel(row.status)"></span>
                                </td>
                                <td>
                                    <span class="badge"
                                          :class="mfaBadge(row).cls"
                                          :title="mfaBadge(row).title"
                                          x-text="mfaBadge(row).label"></span>
                                </td>
                                <td x-text="relTime(row.last_login_at)"
                                    :title="row.last_login_at ? new Date(row.last_login_at).toLocaleString() : ''"></td>
                                <td @click.stop="">
                                    <a :href="'<?= base_url('users/show') ?>?id=' + row.id"
                                       class="btn btn-secondary btn-sm">View</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Pagination -->
        <template x-if="!loading && totalPages > 1">
            <div class="pagination">
                <span class="pagination-info"
                      x-text="'Page ' + page + ' of ' + totalPages"></span>
                <div class="pagination-controls">
                    <button class="page-btn"
                            :disabled="page <= 1"
                            @click="goPage(page - 1)">← Prev</button>
                    <button class="page-btn"
                            :disabled="page >= totalPages"
                            @click="goPage(page + 1)">Next →</button>
                </div>
            </div>
        </template>

    </div><!-- /card -->

</div><!-- /Alpine -->

<script>
function usersList() {
    return {
        rows:       [],
        total:      0,
        page:       1,
        perPage:    25,
        totalPages: 1,
        loading:    false,
        sort:       'name',
        dir:        'ASC',
        filters: {
            q:       '',
            role_id: '',
            status:  '',
        },

        init() { this.fetch(); },

        fetch() {
            this.loading = true;
            const p = new URLSearchParams({
                page:     this.page,
                per_page: this.perPage,
                sort:     this.sort,
                dir:      this.dir,
            });
            if (this.filters.q)       p.set('q',       this.filters.q);
            if (this.filters.role_id) p.set('role_id', this.filters.role_id);
            if (this.filters.status)  p.set('status',  this.filters.status);

            FF_Api.get('<?= base_url('api/v1/users/index.php') ?>?' + p.toString())
                .then(d => {
                    this.rows       = d.data?.items ?? [];
                    this.total      = d.data?.pagination?.total ?? 0;
                    this.totalPages = d.data?.pagination?.total_pages ?? 1;
                })
                .catch(() => { this.rows = []; })
                .finally(() => { this.loading = false; });
        },

        goPage(n) { this.page = n; this.fetch(); },

        setSort(col) {
            if (this.sort === col) { this.dir = this.dir === 'ASC' ? 'DESC' : 'ASC'; }
            else { this.sort = col; this.dir = 'ASC'; }
            this.page = 1;
            this.fetch();
        },

        sortIcon(col) { return this.sort === col ? (this.dir === 'ASC' ? ' ↑' : ' ↓') : ''; },

        resetFilters() {
            this.filters = { q: '', role_id: '', status: '' };
            this.page = 1;
            this.fetch();
        },

        statusBadge(s) {
            return {
                active:    'badge-success',
                invited:   'badge-info',
                inactive:  'badge-neutral',
                suspended: 'badge-danger',
                locked:    'badge-danger',
            }[s] ?? 'badge-neutral';
        },

        statusLabel(s) {
            return {
                active:    'Active',
                invited:   'Invited',
                inactive:  'Inactive',
                suspended: 'Suspended',
                locked:    'Locked',
            }[s] ?? s;
        },

        // S-USERS-CONSOLIDATE D-C: colored role pills.
        // Slug → badge class map. Slugs sourced from user_roles seed.
        roleBadge(slug) {
            return {
                super_admin: 'badge-danger',     // red — highest privilege
                manager:     'badge-warning',    // amber — operational lead
                dispatcher:  'badge-info',       // blue — operational
                accountant:  'badge-success',    // green — finance role
                read_only:   'badge-neutral',    // grey — observer
            }[slug] ?? 'badge-neutral';
        },

        // S-USERS-CONSOLIDATE D-C: MFA enrolment pill.
        //   mfa_enabled=1: green "MFA On".
        //   mfa_enabled=0 AND mfa_required=1: red "MFA Required" — role compels
        //     setup but user hasn't enrolled (compliance gap).
        //   mfa_enabled=0 AND mfa_required=0: amber "MFA Off" — opt-out role.
        mfaBadge(row) {
            const en = parseInt(row.mfa_enabled, 10) === 1;
            const req = parseInt(row.mfa_required, 10) === 1;
            if (en)        return { cls: 'badge-success', label: 'On',       title: 'MFA enrolled' };
            if (req)       return { cls: 'badge-danger',  label: 'Required', title: 'Role requires MFA but user is not enrolled' };
            return            { cls: 'badge-warning', label: 'Off',      title: 'MFA not enrolled (role does not require)' };
        },

        // S-USERS-CONSOLIDATE D-C: relative time helper for last_login_at.
        // Replaces the old toLocaleDateString render. Falls back to "Never"
        // when last_login_at is null (user has never logged in — e.g. invited
        // but unaccepted, or freshly created). The full timestamp is on
        // hover via the parent td's :title attribute.
        relTime(iso) {
            if (!iso) return 'Never';
            const then = new Date(iso).getTime();
            if (isNaN(then)) return '—';
            const diff = Math.floor((Date.now() - then) / 1000);
            if (diff < 60)        return 'just now';
            if (diff < 3600)      return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400)     return Math.floor(diff / 3600) + 'h ago';
            if (diff < 86400 * 30) return Math.floor(diff / 86400) + 'd ago';
            if (diff < 86400 * 365) return Math.floor(diff / (86400 * 30)) + 'mo ago';
            return Math.floor(diff / (86400 * 365)) + 'y ago';
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
