<?php
declare(strict_types=1);

/**
 * app/admin/rates/index.php
 *
 * Rates dashboard — redesigned for S-RATES-REDESIGN.
 * Tab 1: Rate Cards — interactive table with customer column, filters.
 * Tab 2: Customer Rate Overrides — per-equipment-type overrides.
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 * D5:  rate_cards has deleted_at — all DB queries filter it.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/rate_cards/, api/v1/customer_equipment_rates/
 * @decisions D5/D7/D19/D30/D32
 * @session  S019, S-RATES-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('rates', 'view');

$today = date('Y-m-d');

$totalCards = db_count("SELECT COUNT(*) FROM rate_cards WHERE deleted_at IS NULL");
$activeCards = db_count(
    "SELECT COUNT(*) FROM rate_cards
     WHERE deleted_at IS NULL
       AND effective_from <= ?
       AND (effective_to IS NULL OR effective_to >= ?)",
    [$today, $today]
);
$customerCards = db_count(
    "SELECT COUNT(*) FROM rate_cards WHERE deleted_at IS NULL AND customer_id IS NOT NULL"
);
$totalOverrides = db_count("SELECT COUNT(*) FROM customer_equipment_rates");

$pageTitle      = 'Rates';
$helpModuleSlug = 'rates';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Rates</h1>
    <?php if (can('rates', 'create')): ?>
    <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm">+ New Rate Card</a>
    <?php endif; ?>
    <div class="page-header-actions">
        <?= help_button('rates') ?>
    </div>
</div>

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
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
        <div class="stat-label">Customer Cards</div>
        <div class="stat-value font-mono"><?= e($customerCards) ?></div>
        <div class="stat-delta">assigned to specific customers</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Rate Overrides</div>
        <div class="stat-value font-mono"><?= e($totalOverrides) ?></div>
        <div class="stat-delta">per-equipment-type overrides</div>
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

    <!-- ──── TAB 1: Rate Cards ─────────────────────────────────────────── -->
    <div x-show="tab === 'cards'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card" style="border-top:none;border-radius:0 0 8px 8px;">

            <!-- Filter bar -->
            <div class="card-header" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <!-- Name / customer search -->
                <input type="text" class="form-control"
                       style="max-width:220px;height:32px;font-size:0.875rem;"
                       placeholder="Search name or customer…"
                       x-model="cardFilters.q"
                       @input.debounce.400ms="loadCards(1)">

                <!-- Scope: All / Global / Customer-specific -->
                <select class="form-select form-select-sm" style="min-width:150px;"
                        x-model="cardFilters.scope"
                        @change="loadCards(1)">
                    <option value="">All Rate Cards</option>
                    <option value="global">Global Only</option>
                    <option value="customer">Customer-Specific</option>
                </select>

                <!-- Active status -->
                <select class="form-select form-select-sm"
                        x-model="cardFilters.active"
                        @change="loadCards(1)">
                    <option value="">All Statuses</option>
                    <option value="1">Active Only</option>
                </select>

                <button class="btn btn-secondary btn-sm"
                        @click="cardFilters = {q:'',scope:'',active:''}; loadCards(1)">Reset</button>

                <span class="text-secondary" style="margin-left:auto;font-size:0.8125rem;"
                      x-text="cardTotal > 0 ? cardTotal + ' card' + (cardTotal === 1 ? '' : 's') : ''"></span>
            </div>

            <!-- Bulk action bar -->
            <?php if (can('rates', 'delete')): ?>
            <div class="ff-bulk-bar"
                 x-show="selectedCardIds.length > 0"
                 x-transition:enter="ff-bulk-enter" x-transition:enter-start="ff-bulk-enter-from" x-transition:enter-end="ff-bulk-enter-to"
                 x-transition:leave="ff-bulk-leave" x-transition:leave-start="ff-bulk-leave-from" x-transition:leave-end="ff-bulk-leave-to"
                 x-cloak>
                <span class="ff-bulk-bar-count" x-text="selectedCardIds.length + ' selected'"></span>
                <span class="ff-bulk-bar-sep"></span>
                <button class="ff-bulk-btn ff-bulk-btn-delete"
                        :disabled="bulkWorking"
                        @click="bulkDeleteCards()">
                    <span x-text="bulkWorking ? 'Deleting…' : 'Delete selected'"></span>
                </button>
                <button class="ff-bulk-btn ff-bulk-btn-clear"
                        @click="clearCardSelection()">Clear selection</button>
            </div>
            <?php endif; ?>

            <!-- Loading -->
            <div class="card-body" x-show="cardLoading" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading…</span>
            </div>

            <!-- Empty state -->
            <template x-if="!cardLoading && cardRows.length === 0">
                <div class="card-body">
                    <div class="empty-state">
                        <p class="empty-state-title">No rate cards found</p>
                        <p class="empty-state-text">Rate cards define standard pricing by equipment category.</p>
                        <?php if (can('rates', 'create')): ?>
                        <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">+ New Rate Card</a>
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
                                <?php if (can('rates', 'delete')): ?>
                                <th class="th-checkbox">
                                    <label class="ff-checkbox">
                                        <input type="checkbox" :checked="selectAllCards" @change="toggleSelectAllCards()">
                                        <span></span>
                                    </label>
                                </th>
                                <?php endif; ?>
                                <th class="th-sortable" @click="setCardSort('name')">Rate Card <span x-show="cardSort === 'name'" x-text="cardDir === 'ASC' ? '↑' : '↓'"></span></th>
                                <th>Customer</th>
                                <th style="text-align:center;">Items</th>
                                <th class="th-sortable" @click="setCardSort('effective_from')">Effective Period <span x-show="cardSort === 'effective_from'" x-text="cardDir === 'ASC' ? '↑' : '↓'"></span></th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in cardRows" :key="row.id">
                                <tr :class="selectedCardIds.includes(row.id) ? 'ff-row-selected' : ''">
                                    <?php if (can('rates', 'delete')): ?>
                                    <td class="td-checkbox">
                                        <label class="ff-checkbox">
                                            <input type="checkbox"
                                                   :checked="selectedCardIds.includes(row.id)"
                                                   @change="toggleSelectCard(row.id)">
                                            <span></span>
                                        </label>
                                    </td>
                                    <?php endif; ?>
                                    <!-- Rate card name + badges -->
                                    <td>
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <a :href="'<?= base_url('rates/show') ?>?id=' + row.id"
                                               class="link font-medium" x-text="row.name"></a>
                                            <span x-show="row.is_default" class="badge badge-info" style="font-size:0.7rem;">Default</span>
                                        </div>
                                        <div class="text-secondary" style="font-size:0.775rem;margin-top:2px;"
                                             x-show="row.description" x-text="row.description"></div>
                                    </td>
                                    <!-- Customer column -->
                                    <td>
                                        <template x-if="row.customer_id">
                                            <a :href="'<?= base_url('customers/show') ?>?id=' + row.customer_id"
                                               class="link" x-text="row.customer_name"></a>
                                        </template>
                                        <template x-if="!row.customer_id">
                                            <span class="badge badge-neutral" style="font-size:0.75rem;">Global</span>
                                        </template>
                                    </td>
                                    <!-- Items count -->
                                    <td class="font-mono" style="text-align:center;">
                                        <span x-text="row.item_count ?? 0"
                                              :class="(row.item_count ?? 0) === 0 ? 'text-secondary' : ''"></span>
                                    </td>
                                    <!-- Effective period -->
                                    <td style="white-space:nowrap;">
                                        <span class="font-mono" style="font-size:0.8125rem;" x-text="row.effective_from || '—'"></span>
                                        <span class="text-secondary" style="font-size:0.8125rem;"> → </span>
                                        <span class="font-mono" style="font-size:0.8125rem;"
                                              x-text="row.effective_to || 'Open'"></span>
                                    </td>
                                    <!-- Status badge -->
                                    <td>
                                        <span class="badge"
                                              :class="row.is_active ? 'badge-success' : 'badge-neutral'"
                                              x-text="row.is_active ? 'Active' : 'Inactive'"></span>
                                    </td>
                                    <!-- Actions -->
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
                    <button class="btn btn-secondary btn-sm" :disabled="cardPage <= 1" @click="loadCards(cardPage - 1)">← Prev</button>
                    <span class="text-secondary" style="line-height:32px;font-size:0.875rem;"
                          x-text="'Page ' + cardPage + ' of ' + cardTotalPages"></span>
                    <button class="btn btn-secondary btn-sm" :disabled="cardPage >= cardTotalPages" @click="loadCards(cardPage + 1)">Next →</button>
                </div>
            </template>

        </div>
    </div>

    <!-- ──── TAB 2: Customer Overrides ──────────────────────────────────── -->
    <div x-show="tab === 'overrides'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card" style="border-top:none;border-radius:0 0 8px 8px;">

            <!-- Filter bar -->
            <div class="card-header" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <input type="text" class="form-control"
                       style="max-width:220px;height:32px;font-size:0.875rem;"
                       placeholder="Search customer or type…"
                       x-model="ovFilters.q"
                       @input.debounce.400ms="loadOverrides(1)">

                <button class="btn btn-secondary btn-sm"
                        @click="ovFilters = {q:''}; loadOverrides(1)">Reset</button>

                <span class="text-secondary" style="margin-left:auto;font-size:0.8125rem;"
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
                        <p class="empty-state-text">Per-equipment-type overrides can be set on each customer's Rates tab.</p>
                    </div>
                </div>
            </template>

            <!-- Overrides table — grouped by customer for readability -->
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
                                <th>Effective</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in ovRows" :key="row.id">
                                <tr>
                                    <td>
                                        <a :href="'<?= base_url('customers/show') ?>?id=' + row.customer_id + '#rates'"
                                           class="link font-medium" x-text="row.customer_name"></a>
                                    </td>
                                    <td>
                                        <span class="badge badge-neutral" style="font-size:0.75rem;text-transform:capitalize;"
                                              x-text="row.equipment_type.replace(/_/g, ' ')"></span>
                                    </td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="row.daily_rate ? '$' + parseFloat(row.daily_rate).toFixed(2) : '—'"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="row.weekly_rate ? '$' + parseFloat(row.weekly_rate).toFixed(2) : '—'"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="row.monthly_rate ? '$' + parseFloat(row.monthly_rate).toFixed(2) : '—'"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="row.mileage_rate ? '$' + parseFloat(row.mileage_rate).toFixed(4) + '/' + row.mileage_unit : '—'"></td>
                                    <td x-text="row.currency"></td>
                                    <td class="text-secondary" style="font-size:0.8125rem;white-space:nowrap;">
                                        <span x-text="row.effective_from"></span>
                                        <span x-show="row.effective_to"> → <span x-text="row.effective_to"></span></span>
                                        <span x-show="!row.effective_to" class="text-secondary"> → open</span>
                                    </td>
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
                    <button class="btn btn-secondary btn-sm" :disabled="ovPage <= 1" @click="loadOverrides(ovPage - 1)">← Prev</button>
                    <span class="text-secondary" style="line-height:32px;font-size:0.875rem;"
                          x-text="'Page ' + ovPage + ' of ' + ovTotalPages"></span>
                    <button class="btn btn-secondary btn-sm" :disabled="ovPage >= ovTotalPages" @click="loadOverrides(ovPage + 1)">Next →</button>
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
                    Historical lease rates are unaffected.
                </p>
                <p class="text-danger" x-show="deleteCardModal.error" x-text="deleteCardModal.error"
                   style="font-size:0.875rem;margin-top:8px;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="deleteCardModal.open = false">Cancel</button>
                <button class="btn btn-danger btn-sm"
                        :disabled="deleteCardModal.saving"
                        @click="deleteCard()">
                    <span x-text="deleteCardModal.saving ? 'Deleting…' : 'Delete'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Delete override modal ─────────────────────────────────────────── -->
    <div class="modal-backdrop" x-show="deleteOvModal.open" x-cloak>
        <div class="modal modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">Delete Rate Override</h3>
                <button class="modal-close-btn" aria-label="Close" @click="deleteOvModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <p>Delete override for <strong x-text="deleteOvModal.label"></strong>?</p>
                <p class="text-secondary" style="font-size:0.875rem;margin-top:8px;">
                    Rate history is preserved. This removes the active override only.
                </p>
                <p class="text-danger" x-show="deleteOvModal.error" x-text="deleteOvModal.error"
                   style="font-size:0.875rem;margin-top:8px;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="deleteOvModal.open = false">Cancel</button>
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
        tab: 'cards',

        // ── Rate Cards tab
        cardRows:        [],
        cardTotal:       0,
        cardPage:        1,
        cardTotalPages:  1,
        cardLoading:     false,
        cardSort:        'effective_from',
        cardDir:         'DESC',
        cardFilters:     { q: '', scope: '', active: '' },

        selectedCardIds: [],
        selectAllCards:  false,
        bulkWorking:     false,

        // ── Overrides tab
        ovRows:       [],
        ovTotal:      0,
        ovPage:       1,
        ovTotalPages: 1,
        ovLoading:    false,
        ovFilters:    { q: '' },

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
                page:     page,
                per_page: 25,
                sort:     this.cardSort,
                dir:      this.cardDir,
            });
            if (this.cardFilters.q)      params.set('q', this.cardFilters.q);
            if (this.cardFilters.active) params.set('active', this.cardFilters.active);
            // scope: '' = all, 'global' = customer_id=0, 'customer' = customer_id > 0
            if (this.cardFilters.scope === 'global')   params.set('customer_id', '0');
            if (this.cardFilters.scope === 'customer') params.set('has_customer', '1');

            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/rate_cards/index') ?>?${params}`);
                this.cardRows       = r.data?.items ?? [];
                this.cardTotal      = r.data?.pagination?.total ?? 0;
                this.cardTotalPages = r.data?.pagination?.total_pages ?? 1;
                this.selectAllCards =
                    this.cardRows.length > 0 &&
                    this.cardRows.every(r => this.selectedCardIds.includes(r.id));
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
                this.cardDir  = 'DESC';
            }
            this.loadCards(1);
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

        // ── Bulk select helpers ────────────────────────────────────────────
        toggleSelectCard(id) {
            const idx = this.selectedCardIds.indexOf(id);
            if (idx === -1) this.selectedCardIds.push(id);
            else            this.selectedCardIds.splice(idx, 1);
            this.selectAllCards = this.cardRows.length > 0 &&
                this.cardRows.every(r => this.selectedCardIds.includes(r.id));
        },

        toggleSelectAllCards() {
            if (this.selectAllCards) {
                const visibleIds = this.cardRows.map(r => r.id);
                this.selectedCardIds = this.selectedCardIds.filter(id => !visibleIds.includes(id));
                this.selectAllCards  = false;
            } else {
                this.cardRows.forEach(r => { if (!this.selectedCardIds.includes(r.id)) this.selectedCardIds.push(r.id); });
                this.selectAllCards = true;
            }
        },

        clearCardSelection() {
            this.selectedCardIds = [];
            this.selectAllCards  = false;
        },

        async bulkDeleteCards() {
            if (!this.selectedCardIds.length) return;
            if (!confirm(`Delete ${this.selectedCardIds.length} rate card(s)? This cannot be undone.`)) return;
            this.bulkWorking = true;
            try {
                await FF_Api.post('<?= base_url('api/v1/rate_cards/bulk_delete') ?>', { ids: this.selectedCardIds });
                this.clearCardSelection();
                this.loadCards(this.cardPage);
            } catch (e) {
                alert(e.message || 'Bulk delete failed.');
            } finally {
                this.bulkWorking = false;
            }
        },

        // ── Customer Overrides ─────────────────────────────────────────────
        async loadOverrides(page = 1) {
            this.ovLoading = true;
            this.ovPage    = page;
            const params = new URLSearchParams({ page, per_page: 50 });
            if (this.ovFilters.q) params.set('q', this.ovFilters.q);
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
                label:      row.customer_name + ' — ' + row.equipment_type.replace(/_/g, ' '),
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
