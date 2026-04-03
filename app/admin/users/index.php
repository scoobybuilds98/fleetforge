<?php
declare(strict_types=1);

/**
 * app/admin/users/index.php
 *
 * Users list page.
 * Server-renders 4 KPI tiles, then Alpine.js loads the filterable table.
 *
 * KPI tiles: Total, Active, Invited (pending), Suspended.
 * Filters: q (search), role_id, status.
 * Sort: name, email, last_login_at, created_at, status.
 *
 * D5: SOFT_DELETE — queries include deleted_at IS NULL.
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/users/index.php
 * @decisions D5/D7/D30/D32
 * @session  S017
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('users', 'view');

// ── KPI tiles (server-rendered) ──────────────────────────────────────────────
$kpis = [
    'total'     => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status != 'locked'"),
    'active'    => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active'"),
    'invited'   => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'invited'"),
    'suspended' => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'suspended'"),
];

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

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-label">Total Users</div>
        <div class="stat-value font-mono"><?= e($kpis['total']) ?></div>
        <div class="stat-delta">active, invited &amp; suspended</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Active</div>
        <div class="stat-value font-mono"><?= e($kpis['active']) ?></div>
        <div class="stat-delta">can log in</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Invited</div>
        <div class="stat-value font-mono"><?= e($kpis['invited']) ?></div>
        <div class="stat-delta">pending activation</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Suspended</div>
        <div class="stat-value font-mono"><?= e($kpis['suspended']) ?></div>
        <div class="stat-delta">access revoked</div>
    </div>

</div>

<!-- ── Table (Alpine.js) ──────────────────────────────────────────────────── -->
<div class="card"
     x-data="usersList()"
     x-init="init()">

    <!-- Filter bar -->
    <div class="card-header" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">

        <input type="text"
               class="form-control"
               style="max-width:220px;height:32px;font-size:0.875rem;"
               placeholder="Search name or email…"
               x-model="filters.q"
               @input.debounce.400ms="goPage(1)">

        <select class="form-select form-select-sm"
                x-model="filters.role_id"
                @change="goPage(1)">
            <option value="">All Roles</option>
            <?php foreach ($roles as $role): ?>
            <option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select class="form-select form-select-sm"
                x-model="filters.status"
                @change="goPage(1)">
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="invited">Invited</option>
            <option value="inactive">Inactive</option>
            <option value="suspended">Suspended</option>
            <option value="locked">Locked</option>
        </select>

        <button class="btn btn-secondary btn-sm"
                @click="resetFilters()">Reset</button>

        <span class="text-secondary" style="margin-left:auto;font-size:0.875rem;"
              x-text="total > 0 ? total + ' user' + (total === 1 ? '' : 's') : ''"></span>
    </div>

    <!-- Loading -->
    <div class="card-body" x-show="loading" style="text-align:center;padding:32px;">
        <span class="text-secondary">Loading…</span>
    </div>

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
                        <th @click="setSort('last_login_at')" style="cursor:pointer;">
                            Last Login <span x-text="sortIcon('last_login_at')"></span>
                        </th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" :key="row.id">
                        <tr @click="window.location = '<?= base_url('users/show') ?>?id=' + row.id"
                            style="cursor:pointer;">
                            <td x-text="row.name"></td>
                            <td x-text="row.email"></td>
                            <td>
                                <span class="badge badge-neutral badge-pill"
                                      x-text="row.role_name"></span>
                            </td>
                            <td>
                                <span class="badge"
                                      :class="statusBadge(row.status)"
                                      x-text="statusLabel(row.status)"></span>
                            </td>
                            <td x-text="row.last_login_at ? new Date(row.last_login_at).toLocaleDateString('en-CA') : '—'"></td>
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
        <div class="card-footer" style="display:flex;justify-content:center;gap:8px;padding:12px;">
            <button class="btn btn-secondary btn-sm"
                    :disabled="page <= 1"
                    @click="goPage(page - 1)">← Prev</button>
            <span class="text-secondary" style="line-height:32px;font-size:0.875rem;"
                  x-text="'Page ' + page + ' of ' + totalPages"></span>
            <button class="btn btn-secondary btn-sm"
                    :disabled="page >= totalPages"
                    @click="goPage(page + 1)">Next →</button>
        </div>
    </template>

</div><!-- /card -->

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
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
