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

// Default tab from ?tab= query param. Allowed: team (default), portal, defaults.
$defaultTab = clean_string($_GET['tab'] ?? 'team');
if (!in_array($defaultTab, ['team', 'portal', 'defaults'], true)) $defaultTab = 'team';
if ($defaultTab === 'portal' && !$canViewPortal) $defaultTab = 'team';
if ($defaultTab === 'defaults' && !is_super_admin()) $defaultTab = 'team';

// Roles for the Default Permissions tab
$allRolesForDefaults = is_super_admin() ? db_select(
    "SELECT id, name, slug, description FROM user_roles WHERE is_system = 1 AND slug != 'super_admin' ORDER BY id", []
) : [];

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
    <?php if (is_super_admin()): ?>
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'defaults' }"
            @click="activeTab = 'defaults'; history.replaceState(null, '', '<?= base_url('users') ?>?tab=defaults')"
            role="tab">
        Default Permissions
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
            <select class="form-select form-control-sm"
                    x-model="sort"
                    @change="page = 1; load()"
                    aria-label="Sort by">
                <option value="name">Name (A→Z)</option>
                <option value="email">Email</option>
                <option value="role_name">Role</option>
                <option value="status">Status</option>
                <option value="last_login_at">Recently active</option>
                <option value="created_at">Newest member</option>
            </select>
            <select class="form-select form-control-sm"
                    x-model="dir"
                    @change="page = 1; load()"
                    aria-label="Sort direction"
                    style="width:auto;">
                <option value="ASC">↑ Asc</option>
                <option value="DESC">↓ Desc</option>
            </select>
        </div>
    </div>

    <!-- Bulk action bar (team) -->
    <div x-show="selectedIds.length > 0"
         x-transition:enter="ff-bulk-enter" x-transition:enter-start="ff-bulk-enter-from" x-transition:enter-end="ff-bulk-enter-to"
         x-transition:leave="ff-bulk-leave" x-transition:leave-start="ff-bulk-leave-from" x-transition:leave-end="ff-bulk-leave-to"
         class="ff-bulk-bar">
        <span class="ff-bulk-bar-count" x-text="selectedIds.length + ' selected'"></span>
        <div class="ff-bulk-bar-sep"></div>
        <button class="ff-bulk-btn" style="background:rgba(34,197,94,0.12);color:#4ade80;" @click="bulkSetStatus('active')" :disabled="bulkWorking">
            <svg width="11" height="12" viewBox="0 0 11 12" fill="currentColor"><path d="M1 1l9 5-9 5V1z"/></svg>
            Activate
        </button>
        <button class="ff-bulk-btn" style="background:rgba(107,114,128,0.15);color:#9ca3af;" @click="bulkSetStatus('inactive')" :disabled="bulkWorking">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><rect x="2" y="1" width="3" height="10" rx="1"/><rect x="7" y="1" width="3" height="10" rx="1"/></svg>
            Deactivate
        </button>
        <button class="ff-bulk-btn ff-bulk-btn-delete" @click="bulkDelete()" :disabled="bulkWorking">
            <svg width="12" height="13" viewBox="0 0 12 13" fill="currentColor" aria-hidden="true"><path d="M4.5 1h3a.5.5 0 0 1 .5.5v.5H4v-.5A.5.5 0 0 1 4.5 1ZM3 2h6l-.4 7.2A1.5 1.5 0 0 1 7.1 10.5H4.9a1.5 1.5 0 0 1-1.5-1.3L3 2Z"/><path d="M1 2h10" stroke="currentColor" stroke-width="1" stroke-linecap="round" fill="none"/></svg>
            Delete
        </button>
        <button class="ff-bulk-btn ff-bulk-btn-clear" @click="clearSelection()" title="Clear selection" aria-label="Clear selection">
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><line x1="1" y1="1" x2="9" y2="9"/><line x1="9" y1="1" x2="1" y2="9"/></svg>
        </button>
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
                            <th class="th-checkbox">
                                <input type="checkbox" class="ff-checkbox" :checked="selectAll" @change="toggleSelectAll()" title="Select all on this page">
                            </th>
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
                                :class="(row.status === 'invited' ? 'is-invited ' : '') + (selectedIds.includes(row.id) ? 'ff-row-selected' : '')"
                                :style="(row.status === 'invited' ? 'opacity:0.78;font-style:italic;' : '') + 'cursor:pointer;'">
                                <td class="td-checkbox" @click.stop>
                                    <input type="checkbox" class="ff-checkbox" :checked="selectedIds.includes(row.id)" @change="toggleSelect(row.id)">
                                </td>
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
        <div class="portal-create-grid">
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

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 3: DEFAULT PERMISSIONS (super_admin only)                            -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php if (is_super_admin()): ?>
<div x-show="activeTab === 'defaults'" x-transition:enter class="ff-tab-enter"
     x-data="FF_RoleDefaults()">

    <!-- Role selector cards -->
    <div class="rp-roles-card card" style="margin-bottom:18px;border-radius:16px;">
        <div class="perm-apple-card-header">
            <div class="perm-apple-card-icon" style="background:rgba(139,92,246,0.15);color:#a78bfa;width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div>
                <div class="perm-apple-card-title">Role Default Permissions</div>
                <div class="perm-apple-card-subtitle">Select a role to view and override its default permissions — affects every user with that role.</div>
            </div>
            <a href="<?= base_url('users/role_permissions') ?>" class="btn btn-secondary btn-sm" style="margin-left:auto;flex-shrink:0;">Full page view →</a>
        </div>
        <div class="perm-role-cards-row">
            <?php
            $roleIconMap = [
                'manager'    => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z"/></svg>',
                'dispatcher' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>',
                'accountant' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>',
                'read_only'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
            ];
            $roleColorMap = [
                'manager'    => ['bg' => 'rgba(59,130,246,0.15)',  'color' => '#60a5fa'],
                'dispatcher' => ['bg' => 'rgba(34,197,94,0.15)',   'color' => '#4ade80'],
                'accountant' => ['bg' => 'rgba(139,92,246,0.15)',  'color' => '#a78bfa'],
                'read_only'  => ['bg' => 'rgba(100,116,139,0.15)', 'color' => '#94a3b8'],
            ];
            foreach ($allRolesForDefaults as $r):
                $c = $roleColorMap[$r['slug']] ?? ['bg' => 'rgba(100,116,139,0.15)', 'color' => '#94a3b8'];
                $ic = $roleIconMap[$r['slug']] ?? '';
            ?>
            <div class="perm-role-card" :class="selectedRoleId === <?= (int)$r['id'] ?> ? 'is-active' : ''"
                 @click="selectRole(<?= (int)$r['id'] ?>, '<?= addslashes(e($r['name'])) ?>')">
                <div class="perm-role-card-icon" style="background:<?= e($c['bg']) ?>;color:<?= e($c['color']) ?>;"><?= $ic ?></div>
                <div class="perm-role-card-name"><?= e($r['name']) ?></div>
                <div class="perm-role-card-desc"><?= e($r['description']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Matrix area -->
    <div x-show="!selectedRoleId" class="rp-no-role-selected">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" style="width:40px;height:40px;opacity:.25;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
        <span>Select a role above to view and edit its default permissions</span>
    </div>

    <div x-show="loadingMatrix" class="rp-loading">
        <div class="rp-loading-spinner"></div>
        Loading matrix…
    </div>

    <!-- The role matrix (rendered after loading) -->
    <div x-show="selectedRoleId && !loadingMatrix && modules.length > 0">

        <!-- Matrix card -->
        <div class="perm-layout">
        <div class="card perm-matrix-card">
            <div class="perm-matrix-card-header">
                <div>
                    <div class="perm-apple-card-title" style="font-size:1rem;" x-text="selectedRoleName + ' — Default Permissions'"></div>
                    <div class="perm-apple-card-subtitle">Orange ring = override allow. Red = override deny. Green = config grants.</div>
                </div>
                <div class="perm-matrix-header-actions">
                    <span class="perm-override-pill" x-show="overrideCount > 0">
                        <span x-text="overrideCount"></span>&nbsp;override<span x-show="overrideCount !== 1">s</span>
                    </span>
                    <button class="btn btn-secondary btn-sm" @click="confirmReset()" :disabled="overrideCount === 0 || saving">Reset all</button>
                </div>
            </div>

            <!-- Grouped sections -->
            <template x-for="section in sections" :key="section.name">
                <div class="perm-section">
                    <div class="perm-section-header">
                        <div class="perm-section-title">
                            <span class="perm-section-dot" :style="'background:' + section.color"></span>
                            <span x-text="section.name"></span>
                        </div>
                        <div class="perm-section-bulk-actions" x-show="section.bulkGroup">
                            <button class="perm-bulk-btn" @click="openGroupMacro(section.bulkGroup, 'grant_view')" :disabled="saving">View</button>
                            <button class="perm-bulk-btn" @click="openGroupMacro(section.bulkGroup, 'grant_read_write')" :disabled="saving">Read+Write</button>
                            <button class="perm-bulk-btn perm-bulk-btn--danger" @click="openGroupMacro(section.bulkGroup, 'deny_all')" :disabled="saving">Deny All</button>
                            <button class="perm-bulk-btn perm-bulk-btn--muted" @click="openGroupMacro(section.bulkGroup, 'clear')" :disabled="saving">Clear</button>
                        </div>
                    </div>
                    <template x-for="slug in section.modules" :key="slug">
                        <template x-if="moduleBySlug(slug)">
                            <div class="perm-matrix-row">
                                <div class="perm-matrix-row-header">
                                    <span class="perm-matrix-row-label" x-text="moduleBySlug(slug).label"></span>
                                </div>
                                <div class="perm-matrix-row-cells">
                                    <template x-for="action in moduleBySlug(slug).actions" :key="action">
                                        <div class="perm-matrix-cell-col">
                                            <div class="perm-matrix-cell-header" x-text="action"></div>
                                            <div class="perm-ios-toggle"
                                                 :class="toggleClass(slug, action)"
                                                 @click="cycleCell(slug, action)"
                                                 :title="cellTooltip(slug, action)">
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </template>
                </div>
            </template>
        </div>

        <!-- Sidebar: active overrides -->
        <div class="perm-sidebar-col">
            <div class="card perm-sidebar-card">
                <div class="perm-apple-card-header perm-apple-card-header--sm">
                    <div class="perm-apple-card-icon perm-apple-card-icon--sm" style="background:rgba(249,115,22,0.14);color:#fb923c;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
                    </div>
                    <div class="perm-apple-card-title perm-apple-card-title--sm">Active Overrides</div>
                    <span class="perm-badge-count" x-show="overrideCount > 0" x-text="overrideCount"></span>
                </div>
                <div x-show="overrideCount === 0" class="perm-empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" style="width:28px;height:28px;opacity:.28;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <span>Using config defaults</span>
                </div>
                <ul x-show="overrideCount > 0" class="perm-override-list">
                    <template x-for="ovr in overrideList" :key="ovr.module + ':' + ovr.action">
                        <li class="perm-override-item">
                            <div class="perm-override-item-head">
                                <span class="perm-override-badge" :class="ovr.override===1?'perm-override-badge--allow':'perm-override-badge--deny'" x-text="ovr.override===1?'Allow':'Deny'"></span>
                                <span class="perm-override-target"><span x-text="ovr.label"></span><span class="perm-override-action" x-text="' · '+ovr.action"></span></span>
                                <button class="perm-override-clear-btn" @click="sendUpdate(ovr.module,ovr.action,null,null)" :disabled="saving">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:10px;height:10px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div x-show="ovr.reason" class="perm-override-reason" x-text="ovr.reason"></div>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
        </div><!-- /perm-layout -->

    </div><!-- /matrix area -->

    <!-- Reason modal -->
    <div x-show="reasonModal.open" x-cloak class="modal-overlay"
         @click.self="reasonModal.open=false" @keydown.escape.window="reasonModal.open&&(reasonModal.open=false)"
         style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);">
        <div class="modal" @click.stop>
            <div class="modal-header">
                <h3 class="modal-title" x-text="reasonModal.intent===1?'Grant to role':'Deny for role'"></h3>
                <button class="modal-close-btn" @click="reasonModal.open=false">×</button>
            </div>
            <div class="modal-body">
                <p style="margin:0 0 6px;font-size:0.875rem;color:var(--text-secondary);">
                    <span x-text="reasonModal.intent===1?'Grant':'Deny'"></span>
                    <strong x-text="reasonModal.label"></strong> · <strong x-text="reasonModal.action"></strong>
                    for all <strong x-text="selectedRoleName"></strong> users.
                </p>
                <div class="form-group" style="margin-top:12px;">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" rows="3" x-model="reasonModal.reason" maxlength="1000" placeholder="Why is this override needed?"></textarea>
                </div>
                <div x-show="reasonModal.error" x-text="reasonModal.error" style="color:var(--color-danger);font-size:0.8125rem;margin-top:8px;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" @click="reasonModal.open=false">Cancel</button>
                <button class="btn btn-primary" :class="reasonModal.intent===1?'':'btn-danger'"
                        @click="confirmReason()" :disabled="saving||!(reasonModal.reason||'').trim()">
                    <span x-show="!saving" x-text="reasonModal.intent===1?'Grant':'Deny'"></span>
                    <span x-show="saving">Saving…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Group macro modal -->
    <div x-show="groupMacroModal.open" x-cloak class="modal-overlay"
         @click.self="groupMacroModal.open=false" @keydown.escape.window="groupMacroModal.open&&(groupMacroModal.open=false)"
         style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);">
        <div class="modal" @click.stop>
            <div class="modal-header">
                <h3 class="modal-title">Bulk: <span x-text="groupMacroModal.macroLabel"></span> · <span x-text="groupMacroModal.groupLabel"></span></h3>
                <button class="modal-close-btn" @click="groupMacroModal.open=false">×</button>
            </div>
            <div class="modal-body">
                <p style="margin:0 0 12px;font-size:0.875rem;color:var(--text-secondary);" x-text="groupMacroModal.intro"></p>
                <div class="form-group">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" rows="3" x-model="groupMacroModal.reason" maxlength="1000" placeholder="Why is this bulk change needed?"></textarea>
                </div>
                <div x-show="groupMacroModal.error" x-text="groupMacroModal.error" style="color:var(--color-danger);font-size:0.8125rem;margin-top:8px;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" @click="groupMacroModal.open=false">Cancel</button>
                <button class="btn" :class="groupMacroModal.macro==='deny_all'?'btn-danger':'btn-primary'"
                        @click="confirmGroupMacro()" :disabled="saving||!(groupMacroModal.reason||'').trim()">
                    <span x-show="!saving" x-text="groupMacroModal.submitLabel"></span>
                    <span x-show="saving">Applying…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Reset modal -->
    <div x-show="resetModalOpen" x-cloak class="modal-overlay"
         @click.self="resetModalOpen=false" style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);">
        <div class="modal" @click.stop>
            <div class="modal-header">
                <h3 class="modal-title">Reset all overrides?</h3>
                <button class="modal-close-btn" @click="resetModalOpen=false">×</button>
            </div>
            <div class="modal-body">
                <p style="margin:0;font-size:0.875rem;">Remove all <strong x-text="overrideCount"></strong> overrides for <strong x-text="selectedRoleName"></strong> and revert to config defaults?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" @click="resetModalOpen=false">Cancel</button>
                <button class="btn btn-danger" @click="resetAll()" :disabled="saving"><span x-show="!saving">Reset all</span><span x-show="saving">Resetting…</span></button>
            </div>
        </div>
    </div>

</div><!-- /tab 3 defaults -->
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
        selectedIds: [],
        selectAll:   false,
        bulkWorking: false,

        init() {
            this.fetch();
            this.$watch('page', () => this.clearSelection());
        },

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

        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx === -1) this.selectedIds.push(id);
            else this.selectedIds.splice(idx, 1);
            this.selectAll = this.rows.length > 0 && this.selectedIds.length === this.rows.length;
        },
        toggleSelectAll() {
            if (this.selectAll) {
                this.selectedIds = [];
                this.selectAll = false;
            } else {
                this.selectedIds = this.rows.map(item => item.id);
                this.selectAll = true;
            }
        },
        clearSelection() {
            this.selectedIds = [];
            this.selectAll = false;
        },
        async bulkDelete() {
            if (this.selectedIds.length === 0 || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const confirmed = await FF_Confirm.ask(
                'Delete ' + count + ' item' + (count === 1 ? '' : 's') + '? This cannot be undone.'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/users/bulk_delete') ?>', { ids: this.selectedIds });
                if (res.success) {
                    const d = res.data;
                    if (d.deleted > 0) FF_Toast.success(d.deleted + ' deleted' + (d.skipped > 0 ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' could not be deleted: ' + d.errors.map(e => e.reason).join('; '));
                    this.clearSelection();
                    await this.fetch();
                } else {
                    FF_Toast.error(res.error?.message || 'Bulk delete failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error during bulk delete.');
            } finally {
                this.bulkWorking = false;
            }
        },
        async bulkSetStatus(newStatus) {
            if (this.selectedIds.length === 0 || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const label = newStatus === 'active' ? 'activate' : 'deactivate';
            const confirmed = await FF_Confirm.ask(
                label.charAt(0).toUpperCase() + label.slice(1) + ' ' + count + ' user' + (count === 1 ? '' : 's') + '? You cannot change your own status.'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/users/bulk_update_status') ?>', { ids: this.selectedIds, status: newStatus });
                if (res.success) {
                    const d = res.data;
                    if (d.actioned > 0) FF_Toast.success(d.actioned + ' user' + (d.actioned === 1 ? '' : 's') + ' ' + label + 'd' + (d.skipped > 0 ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' failed: ' + d.errors.slice(0,3).map(e => e.reason).join('; ') + (d.errors.length > 3 ? '…' : ''));
                    this.clearSelection();
                    await this.load();
                } else {
                    FF_Toast.error(res.error?.message || 'Status update failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error.');
            } finally {
                this.bulkWorking = false;
            }
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
                    this.msg = r.data?.message || 'Portal user created.';
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

<?php if (is_super_admin()): ?>
function FF_RoleDefaults() {
    const SECTION_DEFS = [
        { name:'Fleet Operations',         color:'#22c55e', bulkGroup:'fleet_ops',  modules:['customers','equipment','leases','reservations','rates'] },
        { name:'Maintenance & Compliance', color:'#f59e0b', bulkGroup:null,         modules:['maintenance','inspections','compliance'] },
        { name:'Financial',                color:'#3b82f6', bulkGroup:'accounting', modules:['invoices','payments','chart_of_accounts','journal_entries','accounts_payable','bank_accounts','fixed_assets','tax_management','financial_reports','budgets','period_management','accounting_settings'] },
        { name:'Analytics & Reports',      color:'#8b5cf6', bulkGroup:null,         modules:['reports','analytics','audit'] },
        { name:'System',                   color:'#64748b', bulkGroup:null,         modules:['ai','users','settings','settings_general','settings_design','settings_users','settings_portal','settings_audit','settings_system','settings_integrations','settings_intelligence','settings_customer_notifications'] },
        { name:'Integrations',             color:'#14b8a6', bulkGroup:'quickbooks', modules:['quickbooks'] },
    ];
    const PERM_GROUPS = {
        fleet_ops:  { key:'fleet_ops',  label:'Fleet Operations', modules:['customers','equipment','leases','reservations','rates'] },
        accounting: { key:'accounting', label:'Financial',        modules:['invoices','payments','chart_of_accounts','journal_entries','accounts_payable','bank_accounts','fixed_assets','tax_management','financial_reports','budgets','period_management','accounting_settings'] },
        quickbooks: { key:'quickbooks', label:'Integrations',     modules:['quickbooks'] },
    };
    return {
        selectedRoleId:   null,
        selectedRoleName: '',
        modules:          [],
        actionDescriptions: {},
        loadingMatrix:    false,
        saving:           false,
        sections:         SECTION_DEFS,
        reasonModal:      { open:false, module:null, action:null, label:'', intent:null, reason:'', error:null },
        groupMacroModal:  { open:false, group:null, groupLabel:'', macro:null, macroLabel:'', submitLabel:'Apply', intro:'', reason:'', error:null },
        resetModalOpen:   false,

        init() { /* role selected by clicking a card */ },

        async selectRole(roleId, roleName) {
            if (this.selectedRoleId === roleId) return;
            this.selectedRoleId   = roleId;
            this.selectedRoleName = roleName;
            this.modules          = [];
            this.loadingMatrix    = true;
            try {
                const res = await FF_Api.get(FF_Api.url('/api/v1/users/role_permissions/index.php?role_id=' + roleId));
                if (res.success && res.data) {
                    this.modules            = res.data.modules;
                    this.actionDescriptions = res.data.action_descriptions || {};
                }
            } catch(e) { FF_Toast.error('Error', 'Could not load permissions.'); }
            finally { this.loadingMatrix = false; }
        },

        moduleBySlug(slug) { return this.modules.find(m => m.slug === slug) || null; },

        toggleClass(slug, action) {
            const m = this.moduleBySlug(slug);
            if (!m) return 'is-off';
            const cell = m.permissions[action];
            if (cell.override === 1) return 'ovr-allow';
            if (cell.override === 0) return 'ovr-deny';
            return cell.config ? 'is-on' : 'is-off';
        },

        cellTooltip(slug, action) {
            const m = this.moduleBySlug(slug);
            if (!m) return '';
            const cell = m.permissions[action];
            const cfgTxt = cell.config ? 'grants' : 'denies';
            const desc   = this.actionDescriptions[action] || '';
            let head;
            if (cell.override === 1)      head = `Override: ALLOW (config ${cfgTxt}) — click to clear`;
            else if (cell.override === 0) head = `Override: DENY (config ${cfgTxt}) — click to clear`;
            else if (cell.effective)      head = `Config ${cfgTxt} — click to override: DENY`;
            else                          head = `Config ${cfgTxt} — click to override: ALLOW`;
            return desc ? `${head}\n${desc}` : head;
        },

        get overrideCount() {
            let n = 0;
            for (const row of this.modules) for (const a of row.actions) if (row.permissions[a].override !== null) n++;
            return n;
        },
        get overrideList() {
            const out = [];
            for (const row of this.modules) for (const a of row.actions) {
                const cell = row.permissions[a];
                if (cell.override !== null) out.push({ module:row.slug, label:row.label, action:a, override:cell.override, reason:cell.reason||'' });
            }
            return out;
        },

        cycleCell(slug, action) {
            if (this.saving) return;
            const m    = this.moduleBySlug(slug);
            const cell = m.permissions[action];
            if (cell.override !== null) {
                this.sendUpdate(slug, action, null, null);
                return;
            }
            const nextIntent = cell.effective ? 0 : 1;
            this.reasonModal = { open:true, module:slug, action:action, label:m.label, intent:nextIntent, reason:'', error:null };
        },
        cancelReason() { this.reasonModal.open = false; },
        async confirmReason() {
            const reason = (this.reasonModal.reason || '').trim();
            if (!reason) { this.reasonModal.error = 'A reason is required.'; return; }
            await this.sendUpdate(this.reasonModal.module, this.reasonModal.action, this.reasonModal.intent, reason);
        },
        async sendUpdate(slug, action, granted, reason) {
            this.saving = true;
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/users/role_permissions/update.php'), {
                    role_id: this.selectedRoleId, module: slug, action: action, granted: granted, reason: reason,
                });
                if (!res.success) {
                    const msg = (res.error && res.error.message) || 'Save failed.';
                    this.reasonModal.open ? (this.reasonModal.error = msg) : FF_Toast.error('Error', msg);
                    return;
                }
                const m    = this.moduleBySlug(slug);
                const cell = m.permissions[action];
                cell.override  = granted; cell.reason = reason;
                cell.effective = granted === null ? cell.config : Boolean(granted);
                this.reasonModal.open = false;
                FF_Toast.success('Saved', granted === null ? 'Override cleared.' : (granted === 1 ? 'Permission granted for role.' : 'Permission denied for role.'));
            } catch(e) {
                const msg = 'Network error. Please try again.';
                this.reasonModal.open ? (this.reasonModal.error = msg) : FF_Toast.error('Error', msg);
            } finally { this.saving = false; }
        },
        confirmReset() { if (this.overrideCount === 0) return; this.resetModalOpen = true; },
        async resetAll() {
            this.saving = true;
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/users/role_permissions/reset.php'), { role_id: this.selectedRoleId });
                if (!res.success) { FF_Toast.error('Error', (res.error && res.error.message) || 'Reset failed.'); return; }
                for (const row of this.modules) for (const a of row.actions) { row.permissions[a].override = null; row.permissions[a].effective = row.permissions[a].config; }
                this.resetModalOpen = false;
                FF_Toast.success('Reset', `Cleared ${res.data.cleared_count} override${res.data.cleared_count===1?'':'s'}.`);
            } catch(e) { FF_Toast.error('Error', 'Network error.'); }
            finally { this.saving = false; }
        },
        _macroLabel(macro) { return {grant_view:'Grant View',grant_read_write:'Grant Read+Write',deny_all:'Deny All',clear:'Clear Overrides'}[macro]||macro; },
        _macroSubmitLabel(macro) { return {grant_view:'Grant View',grant_read_write:'Grant Read+Write',deny_all:'Deny All',clear:'Clear'}[macro]||'Apply'; },
        openGroupMacro(groupKey, macro) {
            if (this.saving || !groupKey) return;
            const grp = PERM_GROUPS[groupKey];
            if (!grp) return;
            const n    = grp.modules.length, noun = n===1?'module':'modules';
            const role = this.selectedRoleName;
            const intros = {
                grant_view:       `Grant view on ${n} ${noun} in "${grp.label}" for all ${role} users.`,
                grant_read_write: `Grant view+create+edit on ${n} ${noun} in "${grp.label}" for all ${role} users.`,
                deny_all:         `Deny all actions on every module in "${grp.label}" for all ${role} users.`,
                clear:            `Clear all overrides on ${n} ${noun} in "${grp.label}" — reverts to config defaults.`,
            };
            this.groupMacroModal = { open:true, group:groupKey, groupLabel:grp.label, macro, macroLabel:this._macroLabel(macro), submitLabel:this._macroSubmitLabel(macro), intro:intros[macro]||'', reason:'', error:null };
        },
        async confirmGroupMacro() {
            const reason = (this.groupMacroModal.reason || '').trim();
            if (!reason) { this.groupMacroModal.error = 'A reason is required.'; return; }
            const grp  = PERM_GROUPS[this.groupMacroModal.group];
            if (!grp)  { this.groupMacroModal.error = 'Group not found.'; return; }
            const macro = this.groupMacroModal.macro;
            this.saving = true;
            try {
                let applied = 0, cleared = 0;
                for (const moduleName of grp.modules) {
                    const row = this.moduleBySlug(moduleName);
                    if (!row) continue;
                    const actions = macro==='grant_view' ? ['view'] : macro==='grant_read_write' ? ['view','create','edit'] : row.actions;
                    const granted = macro==='clear' ? null : (macro==='deny_all' ? 0 : 1);
                    for (const a of actions) {
                        const res = await FF_Api.post(FF_Api.url('/api/v1/users/role_permissions/update.php'), {
                            role_id: this.selectedRoleId, module: moduleName, action: a, granted, reason,
                        });
                        if (res.success) {
                            const cell = row.permissions[a];
                            cell.override = granted; cell.reason = reason;
                            cell.effective = granted===null ? cell.config : Boolean(granted);
                            if (granted===null) cleared++; else applied++;
                        }
                    }
                }
                this.groupMacroModal.open = false;
                FF_Toast.success('Bulk complete', macro==='clear'||cleared>0 ? `Cleared ${cleared} override${cleared===1?'':'s'}.` : `Applied ${applied} override${applied===1?'':'s'}.`);
            } catch(e) { this.groupMacroModal.error = 'Network error.'; }
            finally { this.saving = false; }
        },
    };
}

/* perm-* and rp-* CSS lives in app.css (S-PERM-APPLE-REDESIGN). */
<?php endif; ?>
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
