<?php
declare(strict_types=1);

/**
 * app/admin/rates/index.php
 *
 * Rates module — index page with two tabs:
 *   Tab 1: Rate Cards — paginated list, create/edit/delete actions
 *   Tab 2: Customer Rate Overrides — paginated list across all customers
 *
 * Server-renders 3 KPI tiles (total cards, active cards, total overrides).
 * Alpine.js manages tables, filters, tab switching, and modal interactions.
 *
 * This file fixes the live 404 on the /rates sidebar link (S019 stop condition).
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 * D5:  rate_cards has deleted_at — all DB queries filter it.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/rate_cards/, api/v1/customer_equipment_rates/
 * @decisions D5/D7/D19/D30/D32
 * @session  S019
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('rates', 'view');

// ── KPI tiles (server-rendered) ──────────────────────────────────────────────
$today = date('Y-m-d');

$totalCards = db_count(
    "SELECT COUNT(*) FROM rate_cards WHERE deleted_at IS NULL"
);

$activeCards = db_count(
    "SELECT COUNT(*) FROM rate_cards
     WHERE deleted_at IS NULL
       AND effective_from <= ?
       AND (effective_to IS NULL OR effective_to >= ?)",
    [$today, $today]
);

$totalOverrides = db_count(
    "SELECT COUNT(*) FROM customer_equipment_rates"
);

$pageTitle = 'Rates';
$helpModuleSlug = 'rates';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Rates</h1>
    <?php if (can('rates', 'create')): ?>
    <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm">
        + New Rate Card
    </a>
    <?php endif; ?>
</div>

<!-- ── KPI tiles ───────────────────────────────────────────────────���─────── -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-label">Rate Cards</div>
        <div class="stat-value font-mono"><?= e($totalCards) ?></div>
        <div class="stat-delta">total in system</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Active Today</div>
        <div class="stat-value font-mono"><?= e($activeCards) ?></div>
        <div class="stat-delta">within effective date range</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Customer Overrides</div>
        <div class="stat-value font-mono"><?= e($totalOverrides) ?></div>
        <div class="stat-delta">custom rates across all customers</div>
    </div>

</div>

<!-- ── Tabs + Tables (Alpine.js) ─────────────────────────────────────────── -->
<div x-data="FF_RatesManager()" x-init="init()">

    <!-- Tab nav -->
    <div style="display:flex;gap:0;border-bottom:2px solid var(--border-color);margin-bottom:0;">
        <button class="btn btn-ghost btn-sm"
                :style="tab === 'cards' ? 'border-bottom:2px solid var(--color-primary);margin-bottom:-2px;border-radius:4px 4px 0 0;font-weight:600;' : ''"
                @click="tab = 'cards'; loadCards()">
            Rate Cards
        </button>
        <button class="btn btn-ghost btn-sm"
                :style="tab === 'overrides' ? 'border-bottom:2px solid var(--color-primary);margin-bottom:-2px;border-radius:4px 4px 0 0;font-weight:600;' : ''"
                @click="tab = 'overrides'; loadOverrides()">
            Customer Overrides
        </button>
    </div>

    <!-- ──── TAB 1: Rate Cards ──────────────────────────────────────────── -->
    <div x-show="tab === 'cards'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card" style="border-top:none;border-radius:0 0 8px 8px;">

            <!-- Filter bar -->
            <div class="card-header" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                <input type="text"
                       class="form-control"
                       style="max-width:220px;height:32px;font-size:0.875rem;"
                       placeholder="Search name…"
                       x-model="cardFilters.q"
                       @input.debounce.400ms="loadCards(1)">

                <select class="form-select form-select-sm"
                        x-model="cardFilters.active"
                        @change="loadCards(1)">
                    <option value="">All Cards</option>
                    <option value="1">Active Only</option>
                </select>

                <select class="form-select form-select-sm"
                        x-model="cardFilters.is_default"
                        @change="loadCards(1)">
                    <option value="">All</option>
                    <option value="1">Default Only</option>
                </select>

                <button class="btn btn-secondary btn-sm"
                        @click="cardFilters = {q:'',active:'',is_default:''}; loadCards(1)">Reset</button>

                <span class="text-secondary" style="margin-left:auto;font-size:0.875rem;"
                      x-text="cardTotal > 0 ? cardTotal + ' card' + (cardTotal === 1 ? '' : 's') : ''"></span>
            </div>

            <!-- Loading -->
            <div class="card-body" x-show="cardLoading" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading…</span>
            </div>

            <!-- Empty state -->
            <template x-if="!cardLoading && cardRows.length === 0">
                <div class="card-body">
                    <div class="empty-state">
                        <p class="empty-state-title">No rate cards found</p>
                        <p class="empty-state-text">Create a rate card to set standard rates by equipment type.</p>
                        <?php if (can('rates', 'create')): ?>
                        <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">
                            + New Rate Card
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </template>

            <!-- Cards table -->
            <template x-if="!cardLoading && cardRows.length > 0">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th @click="setCardSort('name')" style="cursor:pointer;">
                                    Name <span x-text="cardSortIcon('name')"></span>
                                </th>
                                <th>Items</th>
                                <th @click="setCardSort('effective_from')" style="cursor:pointer;">
                                    Effective From <span x-text="cardSortIcon('effective_from')"></span>
                                </th>
                                <th>Effective To</th>
                                <th>Status</th>
                                <th>Default</th>
                                <th>Created By</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in cardRows" :key="row.id">
                                <tr>
                                    <td>
                                        <a :href="'<?= base_url('rates/show') ?>?id=' + row.id"
                                           class="link font-medium" x-text="row.name"></a>
                                    </td>
                                    <td class="font-mono" x-text="row.item_count ?? 0"></td>
                                    <td class="font-mono" x-text="row.effective_from || '—'"></td>
                                    <td class="font-mono" x-text="row.effective_to || 'Open-ended'"></td>
                                    <td>
                                        <span class="badge"
                                              :class="row.is_active ? 'badge-success' : 'badge-neutral'"
                                              x-text="row.is_active ? 'Active' : 'Inactive'"></span>
                                    </td>
                                    <td>
                                        <span x-show="row.is_default" class="badge badge-info">Default</span>
                                        <span x-show="!row.is_default" class="text-secondary">—</span>
                                    </td>
                                    <td x-text="row.created_by_name || '—'"></td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        <a :href="'<?= base_url('rates/show') ?>?id=' + row.id"
                                           class="btn btn-secondary btn-sm">Edit</a>
                                        <?php if (can('rates', 'delete')): ?>
                                        <button class="btn btn-outline-danger btn-sm"
                                                @click.stop="confirmDeleteCard(row)"
                                                :disabled="row.is_default">Delete</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- Pagination -->
            <template x-if="!cardLoading && cardTotalPages > 1">
                <div class="card-footer" style="display:flex;justify-content:center;gap:8px;padding:12px;">
                    <button class="btn btn-secondary btn-sm"
                            :disabled="cardPage <= 1"
                            @click="loadCards(cardPage - 1)">← Prev</button>
                    <span class="text-secondary" style="line-height:32px;font-size:0.875rem;"
                          x-text="'Page ' + cardPage + ' of ' + cardTotalPages"></span>
                    <button class="btn btn-secondary btn-sm"
                            :disabled="cardPage >= cardTotalPages"
                            @click="loadCards(cardPage + 1)">Next →</button>
                </div>
            </template>

        </div>
    </div>

    <!-- ──── TAB 2: Customer Overrides ─────────────────────────────────── -->
    <div x-show="tab === 'overrides'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card" style="border-top:none;border-radius:0 0 8px 8px;">

            <!-- Filter bar -->
            <div class="card-header" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                <input type="text"
                       class="form-control"
                       style="max-width:220px;height:32px;font-size:0.875rem;"
                       placeholder="Equipment type…"
                       x-model="ovFilters.equipment_type"
                       @input.debounce.400ms="loadOverrides(1)">

                <button class="btn btn-secondary btn-sm"
                        @click="ovFilters = {equipment_type:''}; loadOverrides(1)">Reset</button>

                <span class="text-secondary" style="margin-left:auto;font-size:0.875rem;"
                      x-text="ovTotal > 0 ? ovTotal + ' override' + (ovTotal === 1 ? '' : 's') : ''"></span>
            </div>

            <!-- Loading -->
            <div class="card-body" x-show="ovLoading" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading…</span>
            </div>

            <!-- Empty state -->
            <template x-if="!ovLoading && ovRows.length === 0">
                <div class="card-body">
                    <div class="empty-state">
                        <p class="empty-state-title">No customer rate overrides</p>
                        <p class="empty-state-text">Custom rates can be set on each customer's profile page.</p>
                    </div>
                </div>
            </template>

            <!-- Overrides table -->
            <template x-if="!ovLoading && ovRows.length > 0">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Equipment Type</th>
                                <th style="text-align:right;">Daily</th>
                                <th style="text-align:right;">Weekly</th>
                                <th style="text-align:right;">Monthly</th>
                                <th style="text-align:right;">Mileage</th>
                                <th>Currency</th>
                                <th>Effective From</th>
                                <th>Effective To</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in ovRows" :key="row.id">
                                <tr>
                                    <td>
                                        <a :href="'<?= base_url('customers/show') ?>?id=' + row.customer_id"
                                           class="link" x-text="row.customer_name"></a>
                                    </td>
                                    <td x-text="row.equipment_type"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="row.daily_rate ? '$' + parseFloat(row.daily_rate).toFixed(2) : '—'"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="row.weekly_rate ? '$' + parseFloat(row.weekly_rate).toFixed(2) : '—'"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="row.monthly_rate ? '$' + parseFloat(row.monthly_rate).toFixed(2) : '—'"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="row.mileage_rate ? row.mileage_rate + '/' + row.mileage_unit : '—'"></td>
                                    <td x-text="row.currency"></td>
                                    <td class="font-mono" x-text="row.effective_from || '—'"></td>
                                    <td class="font-mono" x-text="row.effective_to || 'Open-ended'"></td>
                                    <td style="text-align:right;">
                                        <?php if (can('rates', 'delete')): ?>
                                        <button class="btn btn-danger btn-sm"
                                                @click.stop="confirmDeleteOverride(row)">Delete</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- Pagination -->
            <template x-if="!ovLoading && ovTotalPages > 1">
                <div class="card-footer" style="display:flex;justify-content:center;gap:8px;padding:12px;">
                    <button class="btn btn-secondary btn-sm"
                            :disabled="ovPage <= 1"
                            @click="loadOverrides(ovPage - 1)">← Prev</button>
                    <span class="text-secondary" style="line-height:32px;font-size:0.875rem;"
                          x-text="'Page ' + ovPage + ' of ' + ovTotalPages"></span>
                    <button class="btn btn-secondary btn-sm"
                            :disabled="ovPage >= ovTotalPages"
                            @click="loadOverrides(ovPage + 1)">Next →</button>
                </div>
            </template>

        </div>
    </div>

    <!-- ── Delete card modal ─────────────────────────────────────────────── -->
    <div class="modal-backdrop" x-show="deleteCardModal.open" x-cloak>
        <div class="modal modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">Delete Rate Card</h3>
                <button class="modal-close-btn" aria-label="Close" @click="deleteCardModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <p>Delete <strong x-text="deleteCardModal.name"></strong>?</p>
                <p class="text-secondary" style="font-size:0.875rem;margin-top:8px;">
                    This cannot be undone. Historical lease rates are unaffected.
                </p>
                <p class="text-danger" x-show="deleteCardModal.error" x-text="deleteCardModal.error"
                   style="font-size:0.875rem;margin-top:8px;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm"
                        @click="deleteCardModal.open = false">Cancel</button>
                <button class="btn btn-danger btn-sm"
                        :disabled="deleteCardModal.saving"
                        @click="deleteCard()">
                    <span x-text="deleteCardModal.saving ? 'Deleting…' : 'Delete'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Delete override modal ───────────────────────────────────���─────── -->
    <div class="modal-backdrop" x-show="deleteOvModal.open" x-cloak>
        <div class="modal modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">Delete Rate Override</h3>
                <button class="modal-close-btn" aria-label="Close" @click="deleteOvModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <p>Delete custom rates for <strong x-text="deleteOvModal.label"></strong>?</p>
                <p class="text-secondary" style="font-size:0.875rem;margin-top:8px;">
                    Rate history is preserved. This removes the active override only.
                </p>
                <p class="text-danger" x-show="deleteOvModal.error" x-text="deleteOvModal.error"
                   style="font-size:0.875rem;margin-top:8px;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm"
                        @click="deleteOvModal.open = false">Cancel</button>
                <button class="btn btn-danger btn-sm"
                        :disabled="deleteOvModal.saving"
                        @click="deleteOverride()">
                    <span x-text="deleteOvModal.saving ? 'Deleting…' : 'Delete'"></span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_RatesManager() {
    return {
        // ── Tab state
        tab: 'cards',

        // ── Rate Cards tab
        cardRows:       [],
        cardTotal:      0,
        cardPage:       1,
        cardTotalPages: 1,
        cardLoading:    false,
        cardSort:       'effective_from',
        cardDir:        'DESC',
        cardFilters:    { q: '', active: '', is_default: '' },

        // ── Overrides tab
        ovRows:       [],
        ovTotal:      0,
        ovPage:       1,
        ovTotalPages: 1,
        ovLoading:    false,
        ovFilters:    { equipment_type: '' },

        // ── Modals
        deleteCardModal: { open: false, id: null, name: '', updated_at: null, saving: false, error: '' },
        deleteOvModal:   { open: false, id: null, label: '', updated_at: null, saving: false, error: '' },

        init() {
            this.loadCards();
        },

        // ── Rate Cards ──────────────────────────────────────────────────────
        async loadCards(page = 1) {
            this.cardLoading = true;
            this.cardPage    = page;
            const params = new URLSearchParams({
                page:      page,
                per_page:  25,
                sort:      this.cardSort,
                dir:       this.cardDir,
                ...Object.fromEntries(
                    Object.entries(this.cardFilters).filter(([,v]) => v !== '')
                ),
            });
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/rate_cards/index') ?>?${params}`);
                this.cardRows       = r.data?.items ?? [];
                this.cardTotal      = r.data?.pagination?.total ?? 0;
                this.cardTotalPages = r.data?.pagination?.total_pages ?? 1;
            } catch (e) {
                this.cardRows = [];
            } finally {
                this.cardLoading = false;
            }
        },

        setCardSort(col) {
            if (this.cardSort === col) {
                this.cardDir = this.cardDir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.cardSort = col;
                this.cardDir  = 'ASC';
            }
            this.loadCards(1);
        },

        cardSortIcon(col) {
            if (this.cardSort !== col) return '';
            return this.cardDir === 'ASC' ? ' ↑' : ' ↓';
        },

        confirmDeleteCard(row) {
            this.deleteCardModal = { open: true, id: row.id, name: row.name, updated_at: row.updated_at, saving: false, error: '' };
        },

        async deleteCard() {
            this.deleteCardModal.saving = true;
            this.deleteCardModal.error  = '';
            try {
                await FF_Api.post('<?= base_url('api/v1/rate_cards/delete') ?>', { id: this.deleteCardModal.id, updated_at: this.deleteCardModal.updated_at });
                this.deleteCardModal.open = false;
                this.loadCards(this.cardPage);
            } catch (e) {
                this.deleteCardModal.error = e.message || 'Delete failed.';
            } finally {
                this.deleteCardModal.saving = false;
            }
        },

        // ── Customer Overrides ───────────────────────────────────────────────
        async loadOverrides(page = 1) {
            this.ovLoading = true;
            this.ovPage    = page;
            const params = new URLSearchParams({
                page:     page,
                per_page: 50,
                ...Object.fromEntries(
                    Object.entries(this.ovFilters).filter(([,v]) => v !== '')
                ),
            });
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/customer_equipment_rates/index') ?>?${params}`);
                this.ovRows       = r.data?.items ?? [];
                this.ovTotal      = r.data?.pagination?.total ?? 0;
                this.ovTotalPages = r.data?.pagination?.total_pages ?? 1;
            } catch (e) {
                this.ovRows = [];
            } finally {
                this.ovLoading = false;
            }
        },

        confirmDeleteOverride(row) {
            this.deleteOvModal = {
                open:       true,
                id:         row.id,
                updated_at: row.updated_at,
                label:      row.customer_name + ' — ' + row.equipment_type,
                saving:     false,
                error:      '',
            };
        },

        async deleteOverride() {
            this.deleteOvModal.saving = true;
            this.deleteOvModal.error  = '';
            try {
                await FF_Api.post('<?= base_url('api/v1/customer_equipment_rates/delete') ?>', { id: this.deleteOvModal.id, updated_at: this.deleteOvModal.updated_at });
                this.deleteOvModal.open = false;
                this.loadOverrides(this.ovPage);
            } catch (e) {
                this.deleteOvModal.error = e.message || 'Delete failed.';
            } finally {
                this.deleteOvModal.saving = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
