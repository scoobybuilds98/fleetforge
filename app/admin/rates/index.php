<?php
declare(strict_types=1);

/**
 * app/admin/rates/index.php
 *
 * Rates dashboard — unified card view.
 * Section 1: Rate Cards — card grid (global + customer-specific).
 * Section 2: Customer Rate Overrides — card grid per equipment type.
 *
 * D5:  rate_cards has deleted_at.
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
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

<!-- KPI tiles -->
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

<div x-data="FF_RatesManager()" x-init="init()">

    <!-- ── Section 1: Rate Cards ──────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:20px;">

        <!-- Filter bar — single row -->
        <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:nowrap;">
            <strong style="white-space:nowrap;font-size:0.9375rem;">Rate Cards</strong>
            <input type="text" class="form-control form-control-sm"
                   style="flex:1;min-width:0;max-width:200px;"
                   placeholder="Search…"
                   x-model="cardQ"
                   @input.debounce.400ms="loadCards(1)">
            <select class="form-select form-select-sm" style="width:auto;"
                    x-model="cardScope" @change="loadCards(1)">
                <option value="">All</option>
                <option value="global">Global</option>
                <option value="customer">Customer</option>
            </select>
            <select class="form-select form-select-sm" style="width:auto;"
                    x-model="cardActive" @change="loadCards(1)">
                <option value="">Any Status</option>
                <option value="1">Active</option>
            </select>
            <button class="btn btn-ghost btn-sm" style="white-space:nowrap;"
                    @click="cardQ='';cardScope='';cardActive='';loadCards(1)">Reset</button>
            <span class="text-secondary" style="margin-left:auto;white-space:nowrap;font-size:0.8125rem;"
                  x-text="cardTotal > 0 ? cardTotal + ' card' + (cardTotal === 1 ? '' : 's') : ''"></span>
        </div>

        <div class="card-body">

            <!-- Loading -->
            <div x-show="cardLoading" style="text-align:center;padding:2rem;">
                <span class="text-secondary">Loading…</span>
            </div>

            <!-- Empty state -->
            <template x-if="!cardLoading && cardRows.length === 0">
                <div class="empty-state">
                    <p class="empty-state-title">No rate cards found</p>
                    <p class="empty-state-text">Rate cards define standard pricing by equipment category.</p>
                    <?php if (can('rates', 'create')): ?>
                    <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">+ New Rate Card</a>
                    <?php endif; ?>
                </div>
            </template>

            <!-- Card grid -->
            <template x-if="!cardLoading && cardRows.length > 0">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
                    <template x-for="row in cardRows" :key="row.id">
                        <div class="rate-card-tile"
                             style="border:1px solid var(--border-color);border-radius:8px;padding:16px;display:flex;flex-direction:column;gap:10px;transition:border-color .15s,box-shadow .15s;"
                             @mouseenter="$el.style.borderColor='var(--color-primary)';$el.style.boxShadow='0 2px 8px rgba(0,0,0,.1)'"
                             @mouseleave="$el.style.borderColor='var(--border-color)';$el.style.boxShadow=''">

                            <!-- Name + status badges -->
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                                <a :href="'<?= base_url('rates/show') ?>?id=' + row.id"
                                   class="link font-medium"
                                   x-text="row.name"
                                   style="font-size:0.9375rem;line-height:1.3;"></a>
                                <div style="display:flex;gap:4px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;">
                                    <span class="badge"
                                          :class="row.is_active ? 'badge-success' : 'badge-neutral'"
                                          x-text="row.is_active ? 'Active' : 'Inactive'"
                                          style="font-size:0.7rem;"></span>
                                    <span x-show="row.is_default" class="badge badge-info" style="font-size:0.7rem;">Default</span>
                                </div>
                            </div>

                            <!-- Customer or Global badge -->
                            <div>
                                <template x-if="row.customer_id">
                                    <a :href="'<?= base_url('customers/show') ?>?id=' + row.customer_id"
                                       class="link text-secondary"
                                       style="font-size:0.875rem;"
                                       x-text="row.customer_name"></a>
                                </template>
                                <template x-if="!row.customer_id">
                                    <span class="badge badge-neutral" style="font-size:0.75rem;">Global</span>
                                </template>
                            </div>

                            <!-- Effective period -->
                            <div class="text-secondary" style="font-size:0.8125rem;">
                                <span class="font-mono" x-text="row.effective_from || '—'"></span>
                                <span> → </span>
                                <span class="font-mono" x-text="row.effective_to || 'Open'"></span>
                            </div>

                            <!-- Equipment type count -->
                            <div class="text-secondary" style="font-size:0.8125rem;">
                                <span x-text="(row.item_count ?? 0) + ' equipment type' + ((row.item_count ?? 0) === 1 ? '' : 's')"></span>
                            </div>

                            <!-- Actions -->
                            <div style="display:flex;gap:8px;margin-top:2px;">
                                <a :href="'<?= base_url('rates/show') ?>?id=' + row.id"
                                   class="btn btn-secondary btn-sm">Edit</a>
                                <?php if (can('rates', 'delete')): ?>
                                <button class="btn btn-outline-danger btn-sm"
                                        :disabled="row.is_default"
                                        @click.stop="confirmDeleteCard(row)">Delete</button>
                                <?php endif; ?>
                            </div>

                        </div>
                    </template>
                </div>
            </template>
        </div>

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

    <!-- ── Section 2: Customer Rate Overrides ─────────────────────────────── -->
    <div class="card">

        <!-- Filter bar — single row -->
        <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:nowrap;">
            <strong style="white-space:nowrap;font-size:0.9375rem;">Customer Rate Overrides</strong>
            <input type="text" class="form-control form-control-sm"
                   style="flex:1;min-width:0;max-width:200px;"
                   placeholder="Search…"
                   x-model="ovQ"
                   @input.debounce.400ms="loadOverrides(1)">
            <button class="btn btn-ghost btn-sm" style="white-space:nowrap;"
                    @click="ovQ='';loadOverrides(1)">Reset</button>
            <span class="text-secondary" style="margin-left:auto;white-space:nowrap;font-size:0.8125rem;"
                  x-text="ovTotal > 0 ? ovTotal + ' override' + (ovTotal === 1 ? '' : 's') : ''"></span>
        </div>

        <div class="card-body">

            <!-- Loading -->
            <div x-show="ovLoading" style="text-align:center;padding:2rem;">
                <span class="text-secondary">Loading…</span>
            </div>

            <!-- Empty state -->
            <template x-if="!ovLoading && ovRows.length === 0">
                <div class="empty-state">
                    <p class="empty-state-title">No customer rate overrides</p>
                    <p class="empty-state-text">Per-equipment-type overrides can be set on each customer's Rates tab.</p>
                </div>
            </template>

            <!-- Override card grid -->
            <template x-if="!ovLoading && ovRows.length > 0">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
                    <template x-for="row in ovRows" :key="row.id">
                        <div style="border:1px solid var(--border-color);border-radius:8px;padding:16px;display:flex;flex-direction:column;gap:8px;">

                            <!-- Customer name -->
                            <a :href="'<?= base_url('customers/show') ?>?id=' + row.customer_id + '#rates'"
                               class="link font-medium"
                               x-text="row.customer_name"
                               style="font-size:0.9375rem;"></a>

                            <!-- Equipment type -->
                            <span class="badge badge-neutral"
                                  x-text="row.equipment_type.replace(/_/g,' ')"
                                  style="font-size:0.75rem;text-transform:capitalize;align-self:flex-start;"></span>

                            <!-- Rate pairs — 2-column label/value grid -->
                            <div style="display:grid;grid-template-columns:auto 1fr;gap:3px 12px;font-size:0.8125rem;align-items:baseline;margin-top:2px;">
                                <span x-show="row.daily_rate" class="text-secondary">Daily</span>
                                <span x-show="row.daily_rate" class="font-mono"
                                      x-text="row.daily_rate ? '$' + parseFloat(row.daily_rate).toFixed(2) : ''"></span>

                                <span x-show="row.weekly_rate" class="text-secondary">Weekly</span>
                                <span x-show="row.weekly_rate" class="font-mono"
                                      x-text="row.weekly_rate ? '$' + parseFloat(row.weekly_rate).toFixed(2) : ''"></span>

                                <span x-show="row.monthly_rate" class="text-secondary">Monthly</span>
                                <span x-show="row.monthly_rate" class="font-mono"
                                      x-text="row.monthly_rate ? '$' + parseFloat(row.monthly_rate).toFixed(2) : ''"></span>

                                <span x-show="row.mileage_rate" class="text-secondary">Mileage</span>
                                <span x-show="row.mileage_rate" class="font-mono"
                                      x-text="row.mileage_rate ? '$' + parseFloat(row.mileage_rate).toFixed(4) + '/' + row.mileage_unit : ''"></span>
                            </div>

                            <!-- Effective period (if set) -->
                            <div x-show="row.effective_from" class="text-secondary" style="font-size:0.8rem;">
                                <span class="font-mono" x-text="row.effective_from"></span>
                                <span> → </span>
                                <span class="font-mono" x-text="row.effective_to || 'Open'"></span>
                            </div>

                            <?php if (can('rates', 'delete')): ?>
                            <div style="margin-top:4px;">
                                <button class="btn btn-outline-danger btn-sm"
                                        @click.stop="confirmDeleteOverride(row)">Delete</button>
                            </div>
                            <?php endif; ?>

                        </div>
                    </template>
                </div>
            </template>
        </div>

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

    <!-- ── Delete rate card modal ─────────────────────────────────────────── -->
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
        // Rate Cards section
        cardRows:       [],
        cardTotal:      0,
        cardPage:       1,
        cardTotalPages: 1,
        cardLoading:    false,
        cardQ:          '',
        cardScope:      '',
        cardActive:     '',

        // Overrides section
        ovRows:       [],
        ovTotal:      0,
        ovPage:       1,
        ovTotalPages: 1,
        ovLoading:    false,
        ovQ:          '',

        // Modals
        deleteCardModal: { open: false, id: null, name: '', updated_at: null, saving: false, error: '' },
        deleteOvModal:   { open: false, id: null, label: '', updated_at: null, saving: false, error: '' },

        init() {
            this.loadCards();
            this.loadOverrides();
        },

        // ── Rate Cards ──────────────────────────────────────────────────────
        async loadCards(page = 1) {
            this.cardLoading = true;
            this.cardPage    = page;

            const params = new URLSearchParams({ page, per_page: 24, sort: 'effective_from', dir: 'DESC' });
            if (this.cardQ)                        params.set('q', this.cardQ);
            if (this.cardActive)                   params.set('active', this.cardActive);
            if (this.cardScope === 'global')        params.set('customer_id', '0');
            if (this.cardScope === 'customer')      params.set('has_customer', '1');

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

        // ── Customer Overrides ─────────────────────────────────────────────
        async loadOverrides(page = 1) {
            this.ovLoading = true;
            this.ovPage    = page;

            const params = new URLSearchParams({ page, per_page: 48 });
            if (this.ovQ) params.set('q', this.ovQ);

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
