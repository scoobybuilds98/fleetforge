<?php declare(strict_types=1);

/**
 * FleetForge — Chart of Accounts
 *
 * @file        app/admin/accounting/chart-of-accounts/index.php
 * @description Hierarchical chart of accounts with tree and flat views, search
 *              and type filters, KPI tiles (Total, Active, Header, Account Types),
 *              create/edit modals, and activate/deactivate controls. Account tree
 *              loaded via API with Alpine.js expand/collapse toggling.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/accounts
 * @session     S029
 */

// dirname(__DIR__, 4): chart-of-accounts/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('chart_of_accounts', 'view');

// -- KPI tiles -- server-side for accuracy on initial load --------
$totalAccounts = db_count(
    "SELECT COUNT(*) FROM acc_accounts"
);
$activeAccounts = db_count(
    "SELECT COUNT(*) FROM acc_accounts WHERE is_active = 1"
);
$headerAccounts = db_count(
    "SELECT COUNT(*) FROM acc_accounts WHERE is_header = 1"
);
$accountTypes = db_count(
    "SELECT COUNT(DISTINCT account_type) FROM acc_accounts"
);

$pageTitle = 'Chart of Accounts';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- -- Breadcrumb ------------------------------------------------- -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Chart of Accounts</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Chart of Accounts</h1>
    <div class="page-header-actions">
        <?php if (can('chart_of_accounts', 'create')): ?>
        <button class="btn btn-primary btn-sm"
                @click="$dispatch('open-coa-create')">
            + New Account
        </button>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- TILES-1: KPI tiles dispatch `ff-coa-filter` to set filters.active -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--blue" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-coa-filter',{detail:{active:''}}))">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Total Accounts</div>
        <div class="stat-value font-mono"><?= e((string) $totalAccounts) ?></div>
    </div>
    <div class="stat-card stat-card--green" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-coa-filter',{detail:{active:'1'}}))">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Active Accounts</div>
        <div class="stat-value font-mono"><?= e((string) $activeAccounts) ?></div>
    </div>
    <div class="stat-card stat-card--amber" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-coa-filter',{detail:{active:''}}))">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-tag"/></svg></span>
        <div class="stat-label">Header Accounts</div>
        <div class="stat-value font-mono"><?= e((string) $headerAccounts) ?></div>
    </div>
    <div class="stat-card stat-card--purple" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-coa-filter',{detail:{active:''}}))">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-collection"/></svg></span>
        <div class="stat-label">Account Types</div>
        <div class="stat-value font-mono"><?= e((string) $accountTypes) ?></div>
    </div>
</div>

<!-- ================================================================
     CHART OF ACCOUNTS -- ALPINE COMPONENT
     ================================================================ -->
<div x-data="FF_ChartOfAccounts()"
     @open-coa-create.window="openCreate()"
     @ff-coa-filter.window="filters.active = $event.detail.active">

    <!-- -- Search / Filter Bar ------------------------------------ -->
    <div class="table-toolbar">
        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search code or name..."
                   x-model="filters.search"
                   @input.debounce.300ms="applyFilters()"
                   maxlength="100"
                   style="min-width:260px;"
                   aria-label="Search accounts">

            <select class="form-select form-control-sm"
                    x-model="filters.type"
                    @change="applyFilters()"
                    aria-label="Filter by type">
                <option value="">All Types</option>
                <option value="asset">Asset</option>
                <option value="liability">Liability</option>
                <option value="equity">Equity</option>
                <option value="revenue">Revenue</option>
                <option value="expense">Expense</option>
                <option value="cogs">COGS</option>
                <option value="other_income">Other Income</option>
                <option value="other_expense">Other Expense</option>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.active"
                    @change="applyFilters()"
                    aria-label="Filter by status">
                <option value="1">Active Only</option>
                <option value="0">Inactive Only</option>
                <option value="">All</option>
            </select>
        </div>
        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-text="filteredCount + ' account' + (filteredCount !== 1 ? 's' : '')"></span>

            <!-- View mode toggle -->
            <button class="btn btn-sm"
                    :class="viewMode === 'tree' ? 'btn-primary' : 'btn-secondary'"
                    @click="viewMode = 'tree'"
                    title="Tree view">Tree</button>
            <button class="btn btn-sm"
                    :class="viewMode === 'flat' ? 'btn-primary' : 'btn-secondary'"
                    @click="viewMode = 'flat'"
                    title="Flat view">Flat</button>

            <template x-if="viewMode === 'tree'">
                <span>
                    <button class="btn btn-ghost btn-sm" @click="expandAll()">Expand All</button>
                    <button class="btn btn-ghost btn-sm" @click="collapseAll()">Collapse All</button>
                </span>
            </template>
        </div>
    </div>

    <!-- -- Table Card --------------------------------------------- -->
    <div class="card">

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div aria-busy="true" aria-label="Loading accounts...">
                <template x-for="n in 8" :key="n">
                    <div class="skeleton skeleton-row"></div>
                </template>
            </div>
        </template>

        <!-- Error state -->
        <template x-if="!loading && loadError">
            <div class="empty-state">
                <p class="empty-state-title">Failed to load accounts</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="loadAccounts()">Retry</button>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && !loadError && filteredCount === 0">
            <div class="empty-state">
                <p class="empty-state-title">No accounts found</p>
                <p class="empty-state-text"
                   x-text="filters.search || filters.type || filters.active !== '1'
                       ? 'Try adjusting your filters.'
                       : 'Create your first account to get started.'">
                </p>
            </div>
        </template>

        <!-- ============ TREE VIEW ============ -->
        <template x-if="!loading && !loadError && filteredCount > 0 && viewMode === 'tree'">
            <div style="overflow-x:auto;">
                <table class="data-table" aria-label="Chart of Accounts - Tree View">
                    <thead>
                        <tr>
                            <th scope="col" style="min-width:320px;">Account</th>
                            <th scope="col">Type</th>
                            <th scope="col">Normal Balance</th>
                            <th scope="col">Status</th>
                            <th scope="col" style="width:1%;white-space:nowrap;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="acct in visibleTreeAccounts" :key="acct.id">
                            <tr :class="{ 'bg-surface-2': acct.is_header }">
                                <!-- Account code + name with tree indentation -->
                                <td>
                                    <div class="flex items-center"
                                         :style="'padding-left:' + (acct._depth * 24) + 'px'">
                                        <!-- Expand/collapse toggle for parents -->
                                        <button x-show="acct._hasChildren"
                                                class="btn btn-ghost btn-xs"
                                                style="width:24px;height:24px;padding:0;margin-right:4px;flex-shrink:0;"
                                                @click="toggleNode(acct.id)"
                                                :aria-expanded="expanded[acct.id] ? 'true' : 'false'"
                                                :title="expanded[acct.id] ? 'Collapse' : 'Expand'">
                                            <span x-text="expanded[acct.id] ? '&#9660;' : '&#9654;'"
                                                  style="font-size:10px;"></span>
                                        </button>
                                        <!-- Spacer when no toggle -->
                                        <span x-show="!acct._hasChildren"
                                              style="width:28px;display:inline-block;flex-shrink:0;"></span>

                                        <span class="font-mono text-sm"
                                              style="margin-right:8px;white-space:nowrap;"
                                              x-text="acct.code"></span>
                                        <span :class="acct.is_header ? 'font-medium' : ''"
                                              x-text="acct.name"></span>
                                    </div>
                                </td>

                                <!-- Type badge -->
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="typeBadge(acct.account_type)"
                                          x-text="typeLabel(acct.account_type)"></span>
                                </td>

                                <!-- Normal balance -->
                                <td class="text-sm">
                                    <span x-text="acct.normal_balance === 'debit' ? 'DR' : 'CR'"
                                          :class="acct.normal_balance === 'debit' ? 'text-info' : 'text-warning'"
                                          class="font-mono font-medium"></span>
                                </td>

                                <!-- Active / Inactive badge -->
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="acct.is_active ? 'badge-success' : 'badge-neutral'"
                                          x-text="acct.is_active ? 'Active' : 'Inactive'"></span>
                                </td>

                                <!-- Actions -->
                                <td style="text-align:right;white-space:nowrap;">
                                    <?php if (can('chart_of_accounts', 'edit')): ?>
                                    <button class="btn btn-ghost btn-xs"
                                            @click="openEdit(acct)"
                                            title="Edit account">Edit</button>
                                    <?php endif; ?>
                                    <?php if (can('chart_of_accounts', 'delete')): ?>
                                    <button class="btn btn-ghost btn-xs"
                                            @click="toggleActive(acct)"
                                            x-text="acct.is_active ? 'Deactivate' : 'Activate'"></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ============ FLAT VIEW ============ -->
        <template x-if="!loading && !loadError && filteredCount > 0 && viewMode === 'flat'">
            <div style="overflow-x:auto;">
                <table class="data-table" aria-label="Chart of Accounts - Flat View">
                    <thead>
                        <tr>
                            <th scope="col">Code</th>
                            <th scope="col">Name</th>
                            <th scope="col">Type</th>
                            <th scope="col">Subtype</th>
                            <th scope="col">Normal Balance</th>
                            <th scope="col">Status</th>
                            <th scope="col" style="width:1%;white-space:nowrap;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="acct in flatFiltered" :key="acct.id">
                            <tr :class="{ 'bg-surface-2': acct.is_header }">
                                <td class="font-mono text-sm" x-text="acct.code"></td>
                                <td>
                                    <span :class="acct.is_header ? 'font-medium' : ''"
                                          x-text="acct.name"></span>
                                </td>
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="typeBadge(acct.account_type)"
                                          x-text="typeLabel(acct.account_type)"></span>
                                </td>
                                <td class="text-sm text-secondary"
                                    x-text="acct.account_subtype || '—'"></td>
                                <td class="text-sm">
                                    <span x-text="acct.normal_balance === 'debit' ? 'DR' : 'CR'"
                                          :class="acct.normal_balance === 'debit' ? 'text-info' : 'text-warning'"
                                          class="font-mono font-medium"></span>
                                </td>
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="acct.is_active ? 'badge-success' : 'badge-neutral'"
                                          x-text="acct.is_active ? 'Active' : 'Inactive'"></span>
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <?php if (can('chart_of_accounts', 'edit')): ?>
                                    <button class="btn btn-ghost btn-xs"
                                            @click="openEdit(acct)"
                                            title="Edit account">Edit</button>
                                    <?php endif; ?>
                                    <?php if (can('chart_of_accounts', 'delete')): ?>
                                    <button class="btn btn-ghost btn-xs"
                                            @click="toggleActive(acct)"
                                            x-text="acct.is_active ? 'Deactivate' : 'Activate'"></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

    </div>

    <!-- ============================================================
         CREATE / EDIT ACCOUNT MODAL
         ============================================================ -->
    <div class="modal-overlay" x-show="showModal" x-cloak @click.self="showModal = false">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="modal-title" x-text="editMode ? 'Edit Account' : 'Create Account'"></h3>
                <button class="modal-close-btn" aria-label="Close" @click="showModal = false">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-error-banner" x-show="formError" x-cloak
                     x-text="formError" style="margin-bottom:1rem;"></div>

                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Account Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control"
                               :class="errors.code ? 'is-invalid' : ''"
                               x-model="form.code"
                               @input="errors.code = ''"
                               maxlength="20"
                               placeholder="e.g. 1000"
                               :disabled="formSaving">
                        <div class="field-error" x-show="errors.code" x-cloak x-text="errors.code"></div>
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label class="form-label">Account Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control"
                               :class="errors.name ? 'is-invalid' : ''"
                               x-model="form.name"
                               @input="errors.name = ''"
                               maxlength="100"
                               placeholder="e.g. Cash"
                               :disabled="formSaving">
                        <div class="field-error" x-show="errors.name" x-cloak x-text="errors.name"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Account Type <span class="text-danger">*</span></label>
                        <select class="form-select"
                                :class="errors.account_type ? 'is-invalid' : ''"
                                x-model="form.account_type"
                                @change="errors.account_type = ''; autoNormalBalance()"
                                :disabled="formSaving">
                            <option value="">-- Select --</option>
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="revenue">Revenue</option>
                            <option value="expense">Expense</option>
                            <option value="cogs">COGS</option>
                            <option value="other_income">Other Income</option>
                            <option value="other_expense">Other Expense</option>
                        </select>
                        <div class="field-error" x-show="errors.account_type" x-cloak x-text="errors.account_type"></div>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Account Subtype</label>
                        <input type="text" class="form-control"
                               :class="errors.account_subtype ? 'is-invalid' : ''"
                               x-model="form.account_subtype"
                               @input="errors.account_subtype = ''"
                               maxlength="50"
                               placeholder="e.g. Current Asset"
                               :disabled="formSaving">
                        <div class="field-error" x-show="errors.account_subtype" x-cloak x-text="errors.account_subtype"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Normal Balance <span class="text-danger">*</span></label>
                        <select class="form-select"
                                :class="errors.normal_balance ? 'is-invalid' : ''"
                                x-model="form.normal_balance"
                                @change="errors.normal_balance = ''"
                                :disabled="formSaving">
                            <option value="debit">Debit</option>
                            <option value="credit">Credit</option>
                        </select>
                        <div class="field-error" x-show="errors.normal_balance" x-cloak x-text="errors.normal_balance"></div>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Parent Account</label>
                        <select class="form-select"
                                :class="errors.parent_id ? 'is-invalid' : ''"
                                x-model="form.parent_id"
                                @change="errors.parent_id = ''"
                                :disabled="formSaving">
                            <option value="">None (Top Level)</option>
                            <template x-for="opt in parentOptions" :key="opt.id">
                                <option :value="opt.id"
                                        x-text="opt.code + ' — ' + opt.name"
                                        :disabled="editMode && opt.id == form.id"></option>
                            </template>
                        </select>
                        <div class="field-error" x-show="errors.parent_id" x-cloak x-text="errors.parent_id"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" rows="2"
                              :class="errors.description ? 'is-invalid' : ''"
                              x-model="form.description"
                              @input="errors.description = ''"
                              maxlength="500"
                              :disabled="formSaving"
                              placeholder="Optional description of this account..."></textarea>
                    <div class="field-error" x-show="errors.description" x-cloak x-text="errors.description"></div>
                </div>

                <div class="form-row">
                    <label class="form-label" style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" x-model="form.is_header" :disabled="formSaving">
                        Header account (group label only, no posting)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" @click="showModal = false"
                        :disabled="formSaving">Cancel</button>
                <button class="btn btn-primary" @click="saveAccount()"
                        :disabled="formSaving">
                    <span x-show="!formSaving" x-text="editMode ? 'Save Changes' : 'Create Account'"></span>
                    <span x-show="formSaving" x-text="editMode ? 'Saving...' : 'Creating...'"></span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_ChartOfAccounts() {
    return {
        // -- State --------------------------------------------------
        accounts:      [],          // raw flat list from API
        treeAccounts:  [],          // nested tree structure
        flatAccounts:  [],          // sorted flat list for flat view
        parentOptions: [],          // header accounts for parent dropdown
        expanded:      {},
        filteredCount: 0,
        loading:       true,
        loadError:     null,
        viewMode:      'tree',      // 'tree' or 'flat'

        filters: {
            search: '',
            type:   '',
            active: '1'
        },

        // -- Modal state --------------------------------------------
        showModal:  false,
        editMode:   false,
        formSaving: false,
        formError:  '',
        errors: {
            code: '', name: '', account_type: '', account_subtype: '',
            normal_balance: '', parent_id: '', description: ''
        },
        form: {
            id:               null,
            code:             '',
            name:             '',
            account_type:     'asset',
            account_subtype:  '',
            parent_id:        '',
            normal_balance:   'debit',
            description:      '',
            is_header:        false
        },

        // -- Helper: normalize error from API response ---------------
        _extractError(r, fallback) {
            if (!r) return fallback || 'An unexpected error occurred.';
            if (r.error && r.error.message) return r.error.message;
            if (r.error?.message) return r.error?.message;
            return fallback || 'An unexpected error occurred.';
        },

        // -- Helper: reset field errors ------------------------------
        _clearErrors() {
            for (const k in this.errors) this.errors[k] = '';
            this.formError = '';
        },

        // -- Helper: paint server-side fields → errors state ---------
        _paintServerErrors(r) {
            const err = r && r.error ? r.error : r;
            const fields = (err && err.fields) || (r && r.fields) || {};
            for (const k in fields) {
                if (k === '_general') continue;
                if (k in this.errors) {
                    this.errors[k] = fields[k];
                } else {
                    // Unknown slot — surface in banner
                    this.formError = fields[k];
                }
            }
            if (fields._general) {
                this.formError = fields._general;
            } else if (!Object.keys(fields).length) {
                this.formError = this._extractError(r, 'Failed to save account.');
            } else if (!this.formError) {
                this.formError = this._extractError(r, 'Please correct the highlighted fields.');
            }
        },

        // -- Computed: visible tree accounts based on expand state ---
        get visibleTreeAccounts() {
            const result = [];
            const isSearching = this.filters.search || this.filters.type;
            const matchFn = (acct) => this._matchesFilter(acct);

            const walk = (nodes, depth) => {
                for (const acct of nodes) {
                    const enriched = {
                        ...acct,
                        _depth: depth,
                        _hasChildren: acct.children && acct.children.length > 0
                    };

                    if (isSearching) {
                        // WHY: In search mode, show matched accounts plus their
                        // parent chain so the user sees hierarchy context.
                        const selfMatch  = matchFn(acct);
                        const childMatch = acct.children
                            ? acct.children.some(c => this._treeHasMatch(c, matchFn))
                            : false;
                        if (selfMatch || childMatch) {
                            result.push(enriched);
                        }
                        if (acct.children && (selfMatch || childMatch)) {
                            walk(acct.children, depth + 1);
                        }
                    } else {
                        result.push(enriched);
                        if (acct.children && this.expanded[acct.id]) {
                            walk(acct.children, depth + 1);
                        }
                    }
                }
            };

            walk(this.treeAccounts, 0);
            this.filteredCount = result.length;
            return result;
        },

        // -- Computed: filtered flat accounts for flat view ----------
        get flatFiltered() {
            const filtered = this.flatAccounts.filter(a => this._matchesFilter(a));
            // WHY: Update filteredCount only when flat view is active so the
            // counter in the toolbar reflects whichever view is showing.
            if (this.viewMode === 'flat') {
                this.filteredCount = filtered.length;
            }
            return filtered;
        },

        // -- Init ---------------------------------------------------
        async init() {
            await this.loadAccounts();
        },

        // -- Load accounts from API ---------------------------------
        async loadAccounts() {
            this.loading   = true;
            this.loadError = null;

            try {
                const params = new URLSearchParams(this.filters);
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/accounts') ?>?' + params);
                if (r.success) {
                    const raw = r.data.accounts || r.data || [];
                    this.accounts = raw;
                    this.treeAccounts = this.buildTree(raw);
                    this._buildFlatList(raw);
                    this._buildParentOptions();
                    // WHY: Auto-expand first level so the tree is useful on load.
                    this.treeAccounts.forEach(a => { this.expanded[a.id] = true; });
                } else {
                    this.loadError = r.error?.message || 'Failed to load accounts.';
                }
            } catch (e) {
                this.loadError = 'Network error. Please try again.';
            }
            this.loading = false;
        },

        // -- Build nested tree from flat list -----------------------
        buildTree(accounts) {
            const map     = {};
            const roots   = [];

            // WHY: Two-pass approach -- first index by id, then wire
            // children under their parents. Accounts without a matching
            // parent go to root level.
            accounts.forEach(a => {
                map[a.id] = { ...a, children: [] };
            });
            accounts.forEach(a => {
                if (a.parent_id && map[a.parent_id]) {
                    map[a.parent_id].children.push(map[a.id]);
                } else {
                    roots.push(map[a.id]);
                }
            });

            return roots;
        },

        // -- Build sorted flat list for flat view -------------------
        _buildFlatList(accounts) {
            // WHY: Sort by code so the flat view gives a natural reading order.
            this.flatAccounts = [...accounts].sort((a, b) =>
                (a.code || '').localeCompare(b.code || '')
            );
        },

        // -- Build parent options for modal dropdown ----------------
        _buildParentOptions() {
            // WHY: Only header accounts and top-level accounts are valid parents.
            const opts = [];
            const walk = (nodes) => {
                for (const acct of nodes) {
                    opts.push({ id: acct.id, code: acct.code, name: acct.name });
                    if (acct.children) walk(acct.children);
                }
            };
            walk(this.treeAccounts);
            this.parentOptions = opts;
        },

        // -- Filter helpers -----------------------------------------
        _matchesFilter(acct) {
            if (this.filters.type && acct.account_type !== this.filters.type) return false;
            if (this.filters.active !== '') {
                const wantActive = this.filters.active === '1';
                if (!!acct.is_active !== wantActive) return false;
            }
            if (this.filters.search) {
                const q = this.filters.search.toLowerCase();
                return (acct.code || '').toLowerCase().includes(q) ||
                       (acct.name || '').toLowerCase().includes(q);
            }
            return true;
        },

        _treeHasMatch(node, matcher) {
            if (matcher(node)) return true;
            if (node.children) return node.children.some(c => this._treeHasMatch(c, matcher));
            return false;
        },

        applyFilters() {
            // WHY: Triggering reactivity -- Alpine re-evaluates computed
            // properties (visibleTreeAccounts / flatFiltered) automatically.
        },

        // -- Tree controls ------------------------------------------
        toggleNode(id) {
            this.expanded[id] = !this.expanded[id];
        },

        expandAll() {
            const walk = (nodes) => {
                for (const acct of nodes) {
                    if (acct.children && acct.children.length) {
                        this.expanded[acct.id] = true;
                        walk(acct.children);
                    }
                }
            };
            walk(this.treeAccounts);
        },

        collapseAll() {
            this.expanded = {};
        },

        // -- Display helpers ----------------------------------------
        typeBadge(type) {
            const map = {
                asset:         'badge-blue',
                liability:     'badge-amber',
                equity:        'badge-purple',
                revenue:       'badge-green',
                expense:       'badge-red',
                cogs:          'badge-red',
                other_income:  'badge-green',
                other_expense: 'badge-red'
            };
            return map[type] || 'badge-neutral';
        },

        typeLabel(type) {
            const map = {
                asset:         'Asset',
                liability:     'Liability',
                equity:        'Equity',
                revenue:       'Revenue',
                expense:       'Expense',
                cogs:          'COGS',
                other_income:  'Other Income',
                other_expense: 'Other Expense'
            };
            return map[type] || type;
        },

        // -- Auto-set normal balance when type changes --------------
        autoNormalBalance() {
            const debitTypes = ['asset', 'expense', 'cogs'];
            this.form.normal_balance = debitTypes.includes(this.form.account_type)
                ? 'debit'
                : 'credit';
        },

        // -- CRUD: Create -------------------------------------------
        openCreate() {
            this.form = {
                id:              null,
                code:            '',
                name:            '',
                account_type:    'asset',
                account_subtype: '',
                parent_id:       '',
                normal_balance:  'debit',
                description:     '',
                is_header:       false
            };
            this.editMode   = false;
            this._clearErrors();
            this.formSaving = false;
            this.showModal  = true;
        },

        // -- CRUD: Edit ---------------------------------------------
        openEdit(acct) {
            this.form = {
                id:              acct.id,
                code:            acct.code,
                name:            acct.name,
                account_type:    acct.account_type,
                account_subtype: acct.account_subtype || '',
                parent_id:       acct.parent_id || '',
                normal_balance:  acct.normal_balance,
                description:     acct.description || '',
                is_header:       !!acct.is_header
            };
            this.editMode   = true;
            this._clearErrors();
            this.formSaving = false;
            this.showModal  = true;
        },

        // -- Client-side validation mirror of API field rules -------
        validateAccount() {
            this._clearErrors();
            let ok = true;

            if (!this.form.code || !this.form.code.trim()) {
                this.errors.code = 'Account code is required.';
                ok = false;
            }
            if (!this.form.name || !this.form.name.trim()) {
                this.errors.name = 'Account name is required.';
                ok = false;
            }
            if (!this.form.account_type) {
                this.errors.account_type = 'Please select an account type.';
                ok = false;
            } else {
                const validTypes = ['asset', 'liability', 'equity', 'revenue',
                                    'expense', 'cogs', 'other_income', 'other_expense'];
                if (!validTypes.includes(this.form.account_type)) {
                    this.errors.account_type = 'Account type must be one of: asset, liability, equity, revenue, expense, cogs, other_income, other_expense.';
                    ok = false;
                }
            }
            if (this.form.normal_balance !== 'debit' && this.form.normal_balance !== 'credit') {
                this.errors.normal_balance = 'Normal balance must be debit or credit.';
                ok = false;
            }

            return ok;
        },

        // -- CRUD: Save (create or update) --------------------------
        async saveAccount() {
            if (!this.validateAccount()) {
                this.formError = 'Please correct the highlighted fields.';
                return;
            }
            this.formSaving = true;
            this.formError  = '';

            const url = this.editMode
                ? '<?= base_url('api/v1/accounting/accounts/update') ?>'
                : '<?= base_url('api/v1/accounting/accounts/create') ?>';

            const payload = {
                code:            this.form.code.trim(),
                name:            this.form.name.trim(),
                account_type:    this.form.account_type,
                account_subtype: this.form.account_subtype.trim(),
                parent_id:       this.form.parent_id || null,
                normal_balance:  this.form.normal_balance,
                description:     this.form.description.trim(),
                is_header:       this.form.is_header ? 1 : 0
            };

            if (this.editMode) {
                payload.id = this.form.id;
            }

            try {
                const r = await FF_Api.post(url, payload);
                if (r.success) {
                    this.showModal = false;
                    FF_Toast.success(
                        this.editMode
                            ? 'Account updated successfully.'
                            : 'Account created successfully.'
                    );
                    await this.loadAccounts();
                } else {
                    this._paintServerErrors(r);
                }
            } catch (e) {
                this.formError = 'Network error. Please try again.';
            }
            this.formSaving = false;
        },

        // -- CRUD: Toggle active/inactive ---------------------------
        async toggleActive(acct) {
            const action = acct.is_active ? 'deactivate' : 'activate';
            const label  = acct.is_active ? 'Deactivate' : 'Activate';

            FF_Confirm.show({
                title:        label + ' Account',
                message:      label + ' ' + e(acct.code) + ' — ' + e(acct.name) + '?',
                confirmLabel: label,
                dangerMode:   acct.is_active,
                onConfirm:    async () => {
                    try {
                        const r = await FF_Api.post(
                            '<?= base_url('api/v1/accounting/accounts') ?>/' + acct.id + '/' + action,
                            {}
                        );
                        if (r.success) {
                            FF_Toast.success('Account ' + action + 'd successfully.');
                            await this.loadAccounts();
                        } else {
                            FF_Toast.error(r.error?.message || 'Failed to ' + action + ' account.');
                        }
                    } catch (e) {
                        FF_Toast.error('Network error. Please try again.');
                    }
                }
            });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
