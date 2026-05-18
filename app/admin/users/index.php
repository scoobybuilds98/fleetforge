<?php
declare(strict_types=1);

/**
 * app/admin/users/index.php
 *
 * Unified Users module — two tabs:
 *   1. Team          — admin users (the FleetForge employees with login access)
 *   2. Portal Users  — customer-side users who can log into the portal
 *
 * S-USERS-CONSOLIDATE C3 (2026-05-14): Portal Users tab added per D-A
 *   label decision; consolidated from the prior settings/portal_users.php
 *   tab (now a link card per D195 follow-up to S-SETTINGS-CLEANUP).
 * S-USERS-CONSOLIDATE C2 (2026-05-14): Team tab enriched with MFA pill,
 *   colored role badge, relative last-login time, invited-row styling,
 *   refreshed KPI tiles (Total/Active/Pending/MFA%).
 *
 * Tab routing:
 *   /fleetforge/users           → Team tab (default)
 *   /fleetforge/users?tab=team
 *   /fleetforge/users?tab=portal → Portal Users tab (deep-link from
 *                                   Settings → Portal Users link card)
 *
 * D5: SOFT_DELETE — admin users have deleted_at; portal_users does NOT
 *     (hard-delete table; non-primary portal users can be removed via
 *     api/v1/portal_users/delete.php, primary users must be deactivated).
 * D19: optimistic locking on admin user updates (handled in users/show.php).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/users/index.php, api/v1/portal_users/*
 * @decisions D5/D7/D30/D32 + S-USERS-CONSOLIDATE D-A/D-B/D-C
 * @session  S017 / S-USERS-CONSOLIDATE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

// S-PERM-USERS-ACCESS-WALL — Users module is super_admin only.
// Render the custom developer-only access wall inside the normal
// admin shell so non-super_admin viewers see why they can't enter,
// instead of a bare 403. header.php already opens sidebar/topbar/
// <main class="page-content">; footer.php closes them.
if (!is_super_admin()) {
    $pageTitle = 'Access Restricted';
    require_once FF_ROOT . '/includes/header.php';
    ?>
    <div class="access-wall">
        <div class="access-wall-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5"
                 xmlns="http://www.w3.org/2000/svg">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h2 class="access-wall-title">Developer Access Only</h2>
        <p class="access-wall-message">
            The Users &amp; Roles module is restricted to the Developer account.<br>
            Contact your system administrator for user management assistance.
        </p>
        <a href="<?= base_url('dashboard') ?>" class="btn btn-secondary">
            Back to Dashboard
        </a>
    </div>
    <?php
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

// settings:view gate for the Portal Users tab — matches the pre-S-USERS-
// CONSOLIDATE permission on settings/portal_users.php. If the user has
// only users:view (no settings:view), the Portal Users tab nav button
// renders but the content shows a 403-style message. This keeps the tab
// bar consistent across permission tiers; alternative is to hide the
// tab nav for users:view-only viewers but that fragments the page shape.
$canViewPortal = can('settings', 'view');
$canEditPortal = can('settings', 'edit');
$isSuperAdmin  = can('settings', 'delete');

// ── KPI tiles (server-rendered; D-C) ────────────────────────────────────────
// Team tab KPIs (left column of the tab bar; default tab).
$teamKpis = [
    'total'   => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status != 'locked'"),
    'active'  => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active'"),
    'invited' => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'invited'"),
    'mfa_enrolled' => db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active' AND mfa_enabled = 1"),
];
$teamKpis['mfa_pct'] = $teamKpis['active'] > 0
    ? (int) round(($teamKpis['mfa_enrolled'] / $teamKpis['active']) * 100)
    : 0;

// Portal tab KPIs (server-rendered; loaded only when canViewPortal).
// Only count portal users whose customer is not soft-deleted — a portal
// user under a deleted customer is unreachable anyway.
$portalKpis = ['total' => 0, 'active' => 0, 'email_disabled' => 0, 'invited' => 0];
if ($canViewPortal) {
    $portalKpis = [
        'total'  => db_count(
            "SELECT COUNT(*) FROM portal_users pu
             JOIN customers c ON c.id = pu.customer_id
             WHERE c.deleted_at IS NULL"),
        'active' => db_count(
            "SELECT COUNT(*) FROM portal_users pu
             JOIN customers c ON c.id = pu.customer_id
             WHERE c.deleted_at IS NULL AND pu.status = 'active'"),
        'email_disabled' => db_count(
            "SELECT COUNT(*) FROM portal_users pu
             JOIN customers c ON c.id = pu.customer_id
             WHERE c.deleted_at IS NULL AND pu.email_disabled = 1"),
        'invited' => db_count(
            "SELECT COUNT(*) FROM portal_users pu
             JOIN customers c ON c.id = pu.customer_id
             WHERE c.deleted_at IS NULL AND pu.status = 'invited'"),
    ];
}

// All roles for the admin filter dropdown
$roles = db_select("SELECT id, name, slug FROM user_roles ORDER BY id ASC");

// Customers for the portal-user create dropdown.
$portalCustomers = $canEditPortal
    ? db_select(
        "SELECT id, company_name FROM customers
         WHERE deleted_at IS NULL AND status IN ('active','pending','credit_hold')
         ORDER BY company_name ASC")
    : [];

// Default tab from ?tab= query param. Allowed: team (default), portal.
$defaultTab = clean_string($_GET['tab'] ?? 'team');
if (!in_array($defaultTab, ['team', 'portal'], true)) $defaultTab = 'team';
if ($defaultTab === 'portal' && !$canViewPortal) $defaultTab = 'team';

$pageTitle = 'Users';
require_once FF_ROOT . '/includes/header.php';
?>

<div x-data="{ activeTab: '<?= e($defaultTab) ?>' }">

<div class="page-header">
    <h1 class="page-header-title">Users</h1>
    <div class="page-header-actions">
        <a href="<?= base_url('users/create') ?>"
           x-show="activeTab === 'team'"
           class="btn btn-primary btn-sm"
           <?php if (!can('users', 'create')) echo 'style="display:none;"'; ?>>
            + Invite New User
        </a>
    </div>
</div>

<!-- ── Tab bar ─────────────────────────────────────────────────────────────── -->
<div class="tab-bar" role="tablist" style="margin-bottom:24px;">
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'team' }"
            @click="activeTab = 'team'; history.replaceState(null, '', '<?= base_url('users') ?>')"
            role="tab">
        Team
        <span class="tab-badge" style="font-size:0.7rem;"><?= e((string) $teamKpis['total']) ?></span>
    </button>
    <?php if ($canViewPortal): ?>
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'portal' }"
            @click="activeTab = 'portal'; history.replaceState(null, '', '<?= base_url('users') ?>?tab=portal')"
            role="tab">
        Portal Users
        <span class="tab-badge" style="font-size:0.7rem;"><?= e((string) $portalKpis['total']) ?></span>
    </button>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: TEAM (admin users)                                                -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div x-show="activeTab === 'team'" x-transition:enter class="ff-tab-enter">

<!-- TILES-1: KPI tiles dispatch `ff-users-filter` to the Alpine component
     below. Each tile toggles the matching status filter. The MFA tile is
     non-clickable since there is no DB-backed "show only MFA-off users"
     filter wired in this commit. -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-users-filter',{detail:{status:''}}))">
        <div class="stat-label">Total Team</div>
        <div class="stat-value font-mono"><?= e((string)$teamKpis['total']) ?></div>
        <div class="stat-delta">active + invited + inactive</div>
    </div>

    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-users-filter',{detail:{status:'active'}}))">
        <div class="stat-label">Active</div>
        <div class="stat-value font-mono"><?= e((string)$teamKpis['active']) ?></div>
        <div class="stat-delta">can log in</div>
    </div>

    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-users-filter',{detail:{status:'invited'}}))">
        <div class="stat-label">Pending Invite</div>
        <div class="stat-value font-mono"><?= e((string)$teamKpis['invited']) ?></div>
        <div class="stat-delta">awaiting acceptance</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">MFA Enrolled</div>
        <div class="stat-value font-mono"><?= e((string)$teamKpis['mfa_pct']) ?>%</div>
        <div class="stat-delta"><?= e((string)$teamKpis['mfa_enrolled']) ?> of <?= e((string)$teamKpis['active']) ?> active</div>
    </div>

</div>

<!-- ── Team table (Alpine.js) ─────────────────────────────────────────────── -->
<div x-data="teamList()"
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

        <template x-if="loading">
            <div class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading…</span>
            </div>
        </template>

        <template x-if="!loading && rows.length === 0">
            <div class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No users found</p>
                    <p class="empty-state-text">Try adjusting your filters, or invite a new user.</p>
                </div>
            </div>
        </template>

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

</div><!-- /Alpine team -->

</div><!-- /tab 1 team -->

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2: PORTAL USERS                                                      -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php if ($canViewPortal): ?>
<div x-show="activeTab === 'portal'" x-transition:enter class="ff-tab-enter">

<!-- Portal KPI tiles -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card"
         onclick="window.dispatchEvent(new CustomEvent('ff-portal-filter',{detail:{status:''}}))"
         style="cursor:pointer">
        <div class="stat-label">Total Portal Users</div>
        <div class="stat-value font-mono"><?= e((string) $portalKpis['total']) ?></div>
        <div class="stat-delta">across all customers</div>
    </div>
    <div class="stat-card"
         onclick="window.dispatchEvent(new CustomEvent('ff-portal-filter',{detail:{status:'active'}}))"
         style="cursor:pointer">
        <div class="stat-label">Active</div>
        <div class="stat-value font-mono"><?= e((string) $portalKpis['active']) ?></div>
        <div class="stat-delta">can log in</div>
    </div>
    <div class="stat-card"
         onclick="window.dispatchEvent(new CustomEvent('ff-portal-filter',{detail:{status:'invited'}}))"
         style="cursor:pointer">
        <div class="stat-label">Pending Invite</div>
        <div class="stat-value font-mono"><?= e((string) $portalKpis['invited']) ?></div>
        <div class="stat-delta">awaiting first login</div>
    </div>
    <div class="stat-card"
         onclick="window.dispatchEvent(new CustomEvent('ff-portal-filter',{detail:{email_disabled:'1'}}))"
         style="cursor:pointer">
        <div class="stat-label">Email Disabled</div>
        <div class="stat-value font-mono"><?= e((string) $portalKpis['email_disabled']) ?></div>
        <div class="stat-delta">SES bounce — needs review</div>
    </div>
</div>

<!-- Create Portal User -->
<?php if ($canEditPortal): ?>
<div class="card" style="margin-bottom:20px;"
     x-data="portalCreate()">
    <div class="card-header" style="font-weight:600;">Create Portal User</div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:240px 1fr 1fr auto;gap:12px;align-items:end;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Customer</label>
                <select x-model="form.customer_id" class="form-control" required>
                    <option value="">Select…</option>
                    <?php foreach ($portalCustomers as $c): ?>
                    <option value="<?= e((string) $c['id']) ?>"><?= e($c['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Name</label>
                <input type="text" x-model="form.name" class="form-control" placeholder="Full name" required>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Email</label>
                <input type="email" x-model="form.email" class="form-control" placeholder="user@company.com" required>
            </div>
            <button class="btn btn-primary btn-sm"
                    :disabled="saving"
                    @click="submit()">
                <span x-show="!saving">Create &amp; Invite</span>
                <span x-show="saving" x-cloak>Saving…</span>
            </button>
        </div>
        <p class="text-muted" style="font-size:0.75rem;margin:8px 0 0;">
            First portal user for a customer becomes the primary account holder.
            Subsequent users are sub-users.
        </p>
        <div x-show="msg" x-text="msg" x-cloak
             :style="err ? 'color:var(--color-danger);' : 'color:var(--color-success);'"
             style="font-size:0.8125rem;margin-top:8px;"></div>
    </div>
</div>
<?php endif; ?>

<!-- Portal users list (Alpine) -->
<div x-data="portalList()"
     x-init="init()"
     @ff-portal-filter.window="applyKpiFilter($event.detail)">

    <div class="table-toolbar">
        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search name, email, or company…"
                   x-model="filters.q"
                   @input.debounce.400ms="goPage(1)"
                   style="min-width:280px;"
                   aria-label="Search portal users">

            <select class="form-select form-control-sm"
                    x-model="filters.status"
                    @change="goPage(1)"
                    aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="invited">Invited</option>
            </select>

            <button class="btn btn-secondary btn-sm"
                    @click="resetFilters()">Reset</button>
        </div>
        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="total > 0 ? total + ' portal user' + (total === 1 ? '' : 's') : ''"></span>
        </div>
    </div>

    <div class="card">

        <template x-if="loading">
            <div class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading…</span>
            </div>
        </template>

        <template x-if="!loading && rows.length === 0">
            <div class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No portal users found</p>
                    <p class="empty-state-text">Try adjusting your filters, or create one above.</p>
                </div>
            </div>
        </template>

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
                            <th @click="setSort('company_name')" style="cursor:pointer;">
                                Customer <span x-text="sortIcon('company_name')"></span>
                            </th>
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
                            <tr @click="window.location = '<?= base_url('portal_users/show') ?>?id=' + row.id"
                                :class="row.status === 'invited' ? 'is-invited' : ''"
                                :style="(row.status === 'invited' ? 'opacity:0.78;font-style:italic;' : '') + 'cursor:pointer;'">
                                <td>
                                    <span x-text="row.name"></span>
                                    <span x-show="parseInt(row.is_primary, 10) === 1"
                                          class="badge badge-info"
                                          style="font-size:0.65rem;margin-left:6px;">Primary</span>
                                </td>
                                <td>
                                    <span x-text="row.email"></span>
                                    <span x-show="parseInt(row.email_disabled, 10) === 1"
                                          class="badge badge-danger"
                                          style="font-size:0.65rem;margin-left:6px;"
                                          :title="row.email_disabled_reason || 'SES auto-disabled'">Email Off</span>
                                </td>
                                <td @click.stop="">
                                    <a :href="'<?= base_url('customers/show') ?>?id=' + row.customer_id"
                                       class="link"
                                       x-text="row.company_name"></a>
                                </td>
                                <td>
                                    <span class="badge"
                                          :class="portalStatusBadge(row.status)"
                                          x-text="portalStatusLabel(row.status)"></span>
                                </td>
                                <td x-text="relTime(row.last_login_at)"
                                    :title="row.last_login_at ? new Date(row.last_login_at).toLocaleString() : ''"></td>
                                <td @click.stop="">
                                    <a :href="'<?= base_url('portal_users/show') ?>?id=' + row.id"
                                       class="btn btn-secondary btn-sm">View</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

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

</div><!-- /Alpine portal -->

</div><!-- /tab 2 portal -->
<?php endif; ?>

</div><!-- /x-data root tab container -->

<script>
function teamList() {
    return {
        rows:       [],
        total:      0,
        page:       1,
        perPage:    25,
        totalPages: 1,
        loading:    false,
        sort:       'name',
        dir:        'ASC',
        filters: { q: '', role_id: '', status: '' },

        init() { this.fetch(); },

        fetch() {
            this.loading = true;
            const p = new URLSearchParams({
                page: this.page, per_page: this.perPage, sort: this.sort, dir: this.dir,
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
            this.page = 1; this.fetch();
        },
        sortIcon(col) { return this.sort === col ? (this.dir === 'ASC' ? ' ↑' : ' ↓') : ''; },
        resetFilters() { this.filters = { q: '', role_id: '', status: '' }; this.page = 1; this.fetch(); },

        statusBadge(s) {
            return {
                active:'badge-success', invited:'badge-info', inactive:'badge-neutral',
                suspended:'badge-danger', locked:'badge-danger',
            }[s] ?? 'badge-neutral';
        },
        statusLabel(s) {
            return {
                active:'Active', invited:'Invited', inactive:'Inactive',
                suspended:'Suspended', locked:'Locked',
            }[s] ?? s;
        },
        roleBadge(slug) {
            return {
                super_admin:'badge-danger', manager:'badge-warning', dispatcher:'badge-info',
                accountant:'badge-success', read_only:'badge-neutral',
            }[slug] ?? 'badge-neutral';
        },
        mfaBadge(row) {
            const en  = parseInt(row.mfa_enabled, 10) === 1;
            const req = parseInt(row.mfa_required, 10) === 1;
            if (en)  return { cls: 'badge-success', label: 'On',       title: 'MFA enrolled' };
            if (req) return { cls: 'badge-danger',  label: 'Required', title: 'Role requires MFA but user is not enrolled' };
            return       { cls: 'badge-warning', label: 'Off',      title: 'MFA not enrolled (role does not require)' };
        },
        relTime(iso) {
            if (!iso) return 'Never';
            const then = new Date(iso).getTime();
            if (isNaN(then)) return '—';
            const diff = Math.floor((Date.now() - then) / 1000);
            if (diff < 60)         return 'just now';
            if (diff < 3600)       return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400)      return Math.floor(diff / 3600) + 'h ago';
            if (diff < 86400 * 30) return Math.floor(diff / 86400) + 'd ago';
            if (diff < 86400 * 365) return Math.floor(diff / (86400 * 30)) + 'mo ago';
            return Math.floor(diff / (86400 * 365)) + 'y ago';
        },
    };
}

<?php if ($canViewPortal): ?>
function portalList() {
    return {
        rows:       [],
        total:      0,
        page:       1,
        perPage:    25,
        totalPages: 1,
        loading:    false,
        sort:       'company_name',
        dir:        'ASC',
        filters: { q: '', status: '', email_disabled: '' },

        init() { this.fetch(); },

        fetch() {
            this.loading = true;
            const p = new URLSearchParams({
                page: this.page, per_page: this.perPage, sort: this.sort, dir: this.dir,
            });
            if (this.filters.q)              p.set('q', this.filters.q);
            if (this.filters.status)         p.set('status', this.filters.status);
            if (this.filters.email_disabled) p.set('email_disabled', this.filters.email_disabled);

            FF_Api.get('<?= base_url('api/v1/portal_users/index.php') ?>?' + p.toString())
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
            this.page = 1; this.fetch();
        },
        sortIcon(col) { return this.sort === col ? (this.dir === 'ASC' ? ' ↑' : ' ↓') : ''; },
        resetFilters() { this.filters = { q: '', status: '', email_disabled: '' }; this.page = 1; this.fetch(); },

        // Called by ff-portal-filter event from KPI tile clicks.
        applyKpiFilter(detail) {
            if (detail.email_disabled !== undefined) {
                this.filters.email_disabled = (this.filters.email_disabled === detail.email_disabled) ? '' : detail.email_disabled;
                this.filters.status = '';
            } else if (detail.status !== undefined) {
                this.filters.status = (this.filters.status === detail.status && detail.status !== '') ? '' : detail.status;
                this.filters.email_disabled = '';
            }
            this.goPage(1);
        },

        portalStatusBadge(s) {
            return {
                active:'badge-success', inactive:'badge-neutral', invited:'badge-info',
            }[s] ?? 'badge-neutral';
        },
        portalStatusLabel(s) {
            return {
                active:'Active', inactive:'Inactive', invited:'Invited',
            }[s] ?? s;
        },
        // Shared with team tab — same relative-time helper, duplicated to
        // avoid coupling the two Alpine scopes.
        relTime(iso) {
            if (!iso) return 'Never';
            const then = new Date(iso).getTime();
            if (isNaN(then)) return '—';
            const diff = Math.floor((Date.now() - then) / 1000);
            if (diff < 60)         return 'just now';
            if (diff < 3600)       return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400)      return Math.floor(diff / 3600) + 'h ago';
            if (diff < 86400 * 30) return Math.floor(diff / 86400) + 'd ago';
            if (diff < 86400 * 365) return Math.floor(diff / (86400 * 30)) + 'mo ago';
            return Math.floor(diff / (86400 * 365)) + 'y ago';
        },
    };
}

<?php if ($canEditPortal): ?>
function portalCreate() {
    return {
        saving: false,
        msg:    '',
        err:    false,
        form:   { customer_id: '', name: '', email: '' },

        async submit() {
            this.msg = '';
            if (!this.form.customer_id || !this.form.name || !this.form.email) {
                this.err = true; this.msg = 'All three fields are required.'; return;
            }
            this.saving = true;
            try {
                const r = await FF_Api.post(
                    FF_Api.url('/api/v1/portal_users/create.php'),
                    this.form
                );
                if (r.success) {
                    this.err = false;
                    this.msg = 'Portal user created. Invite sent (check logs/mail.log in dev).';
                    this.form = { customer_id: '', name: '', email: '' };
                    // Reload the Alpine portal list scope by dispatching event.
                    // Caller-scoped reload is cleaner than poking into a sibling scope.
                    window.dispatchEvent(new CustomEvent('ff-portal-filter', { detail: { status: '' } }));
                } else {
                    this.err = true;
                    this.msg = r.error?.message ?? 'Create failed.';
                }
            } catch(e) {
                this.err = true;
                this.msg = 'Network error.';
            }
            this.saving = false;
        },
    };
}
<?php endif; ?>
<?php endif; ?>
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
