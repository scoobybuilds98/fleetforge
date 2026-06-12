<?php
declare(strict_types=1);

/**
 * app/admin/rates/index.php
 *
 * Rates dashboard — accordion grouped by customer.
 * Global rate card templates shown as a collapsible section at top.
 * Customer overrides grouped per customer; click header to expand cards.
 * Each override card mirrors the equipment unit card style.
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

    <!-- ── Search toolbar (single row) ───────────────────────────────────── -->
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:nowrap;">
            <input type="text" class="form-control form-control-sm"
                   style="flex:1;min-width:0;max-width:260px;"
                   placeholder="Search customer or equipment type…"
                   x-model="q"
                   @input.debounce.400ms="search()">
            <button class="btn btn-ghost btn-sm" style="white-space:nowrap;"
                    @click="q='';search()">Reset</button>
            <span class="text-secondary" style="margin-left:auto;white-space:nowrap;font-size:0.8125rem;"
                  x-text="ovTotal > 0 ? ovTotal + ' override' + (ovTotal === 1 ? '' : 's') + ' · ' + ovGroups.length + ' customer' + (ovGroups.length === 1 ? '' : 's') : ''"></span>
        </div>
    </div>

    <!-- ── Global Rate Cards accordion ───────────────────────────────────── -->
    <div class="card" style="margin-bottom:12px;">
        <!-- Header -->
        <div class="card-header"
             style="display:flex;align-items:center;gap:12px;cursor:pointer;user-select:none;"
             @click="globalOpen = !globalOpen">
            <span class="text-secondary" style="font-size:0.75rem;width:12px;flex-shrink:0;"
                  x-text="globalOpen ? '▼' : '▶'"></span>
            <span style="font-weight:600;font-size:0.9375rem;">Global Rate Cards</span>
            <span class="badge badge-neutral" style="margin-left:auto;font-size:0.75rem;"
                  x-text="globalCards.length + ' card' + (globalCards.length === 1 ? '' : 's')"></span>
        </div>

        <!-- Expanded body -->
        <div x-show="globalOpen" class="card-body">
            <div x-show="globalLoading" style="text-align:center;padding:1.5rem;">
                <span class="text-secondary">Loading…</span>
            </div>

            <template x-if="!globalLoading && globalCards.length === 0">
                <div class="empty-state" style="padding:1.5rem 0;">
                    <p class="empty-state-title">No global rate cards</p>
                    <p class="empty-state-text">Global cards apply to all customers unless overridden.</p>
                    <?php if (can('rates', 'create')): ?>
                    <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">+ New Rate Card</a>
                    <?php endif; ?>
                </div>
            </template>

            <template x-if="!globalLoading && globalCards.length > 0">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
                    <template x-for="card in globalCards" :key="card.id">
                        <div style="border:1px solid var(--border-color);border-radius:8px;padding:16px;">
                            <!-- Name + status -->
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:10px;">
                                <span class="font-semibold" x-text="card.name"
                                      style="font-size:0.9375rem;font-weight:600;line-height:1.3;"></span>
                                <span class="badge"
                                      :class="card.is_active ? 'badge-success' : 'badge-neutral'"
                                      x-text="card.is_active ? 'Active' : 'Inactive'"
                                      style="font-size:0.7rem;flex-shrink:0;"></span>
                            </div>
                            <!-- Divider -->
                            <div style="border-top:1px solid var(--border-color);margin-bottom:10px;"></div>
                            <!-- Info -->
                            <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 12px;font-size:0.8125rem;align-items:baseline;">
                                <span class="text-secondary">Types</span>
                                <span class="font-mono" x-text="card.item_count ?? 0"></span>
                                <span class="text-secondary">From</span>
                                <span class="font-mono" x-text="card.effective_from || '—'"></span>
                                <span class="text-secondary">To</span>
                                <span class="font-mono" x-text="card.effective_to || 'Open'"></span>
                            </div>
                            <!-- Actions -->
                            <div style="display:flex;gap:8px;margin-top:12px;">
                                <a :href="'<?= base_url('rates/show') ?>?id=' + card.id"
                                   class="btn btn-secondary btn-sm">Edit</a>
                                <?php if (can('rates', 'delete')): ?>
                                <button class="btn btn-outline-danger btn-sm"
                                        :disabled="card.is_default"
                                        @click.stop="confirmDeleteCard(card)">Delete</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <!-- ── Customer accordion groups ─────────────────────────────────────── -->
    <div x-show="ovLoading" style="text-align:center;padding:2rem;">
        <span class="text-secondary">Loading…</span>
    </div>

    <template x-if="!ovLoading && ovGroups.length === 0 && (q !== '')">
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No results for "<span x-text="q"></span>"</p>
                </div>
            </div>
        </div>
    </template>

    <template x-if="!ovLoading && ovGroups.length > 0">
        <div>
            <template x-for="group in ovGroups" :key="group.customer_id">
                <div class="card" style="margin-bottom:12px;">

                    <!-- Accordion header — click to expand/collapse -->
                    <div class="card-header"
                         style="display:flex;align-items:center;gap:12px;cursor:pointer;user-select:none;"
                         @click="toggleGroup(group.customer_id)">
                        <span class="text-secondary" style="font-size:0.75rem;width:12px;flex-shrink:0;"
                              x-text="openGroups[group.customer_id] ? '▼' : '▶'"></span>
                        <a :href="'<?= base_url('customers/show') ?>?id=' + group.customer_id"
                           class="link"
                           x-text="group.customer_name"
                           style="font-size:1rem;font-weight:600;"
                           @click.stop></a>
                        <span class="badge badge-neutral"
                              style="margin-left:auto;font-size:0.75rem;"
                              x-text="group.items.length + ' rate' + (group.items.length === 1 ? '' : 's')"></span>
                    </div>

                    <!-- Expanded: equipment rate cards grid -->
                    <div x-show="openGroups[group.customer_id]" class="card-body">
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
                            <template x-for="item in group.items" :key="item.id">
                                <div style="border:1px solid var(--border-color);border-radius:10px;background:var(--bg-secondary);overflow:hidden;">

                                    <!-- ── VIEW MODE ─────────────────────────── -->
                                    <template x-if="!item._editing">
                                        <div>
                                            <!-- Header: type + currency -->
                                            <div style="padding:14px 16px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                                <span class="font-semibold"
                                                      x-text="item.equipment_type.replace(/_/g,' ')"
                                                      style="font-size:0.9375rem;font-weight:600;text-transform:capitalize;line-height:1.3;"></span>
                                                <span class="badge badge-neutral"
                                                      x-text="item.currency || 'CAD'"
                                                      style="font-size:0.7rem;flex-shrink:0;"></span>
                                            </div>
                                            <!-- Body: rate pairs -->
                                            <div style="padding:16px;display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:0.875rem;align-items:baseline;">
                                                <span x-show="item.daily_rate" class="text-secondary">Daily</span>
                                                <span x-show="item.daily_rate" class="font-mono" style="text-align:right;"
                                                      x-text="item.daily_rate ? '$' + parseFloat(item.daily_rate).toFixed(2) : ''"></span>

                                                <span x-show="item.weekly_rate" class="text-secondary">Weekly</span>
                                                <span x-show="item.weekly_rate" class="font-mono" style="text-align:right;"
                                                      x-text="item.weekly_rate ? '$' + parseFloat(item.weekly_rate).toFixed(2) : ''"></span>

                                                <span x-show="item.monthly_rate" class="text-secondary">Monthly</span>
                                                <span x-show="item.monthly_rate" class="font-mono" style="text-align:right;"
                                                      x-text="item.monthly_rate ? '$' + parseFloat(item.monthly_rate).toFixed(2) : ''"></span>

                                                <span x-show="item.mileage_rate" class="text-secondary">Mileage</span>
                                                <span x-show="item.mileage_rate" class="font-mono" style="text-align:right;"
                                                      x-text="item.mileage_rate ? '$' + parseFloat(item.mileage_rate).toFixed(4) + ' / ' + item.mileage_unit : ''"></span>

                                                <template x-if="!item.daily_rate && !item.weekly_rate && !item.monthly_rate && !item.mileage_rate">
                                                    <span class="text-secondary" style="grid-column:1/-1;font-style:italic;">No rates set</span>
                                                </template>
                                            </div>
                                            <!-- Footer: edit/delete -->
                                            <?php if (can('rates', 'edit') || can('rates', 'delete')): ?>
                                            <div style="padding:12px 16px;border-top:1px solid var(--border-color);display:flex;gap:8px;">
                                                <?php if (can('rates', 'edit')): ?>
                                                <button class="btn btn-secondary btn-sm"
                                                        @click.stop="editOverride(item)">Edit</button>
                                                <?php endif; ?>
                                                <?php if (can('rates', 'delete')): ?>
                                                <button class="btn btn-outline-danger btn-sm"
                                                        @click.stop="confirmDeleteOverride(item)">Delete</button>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </template>

                                    <!-- ── EDIT MODE ─────────────────────────── -->
                                    <?php if (can('rates', 'edit')): ?>
                                    <template x-if="item._editing">
                                        <div>
                                            <!-- Header: type (read-only) + currency selector -->
                                            <div style="padding:14px 16px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                                <span class="font-semibold"
                                                      x-text="item.equipment_type.replace(/_/g,' ')"
                                                      style="font-size:0.9375rem;font-weight:600;text-transform:capitalize;"></span>
                                                <select class="form-select"
                                                        x-model="item.currency"
                                                        style="width:90px;flex-shrink:0;">
                                                    <option value="CAD">CAD</option>
                                                    <option value="USD">USD</option>
                                                </select>
                                            </div>
                                            <!-- Body: 2×2 inputs -->
                                            <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                                                <div>
                                                    <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Daily Rate</label>
                                                    <div style="position:relative;">
                                                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                        <input type="number" class="form-control font-mono" style="padding-left:22px;"
                                                               x-model="item.daily_rate" step="0.01" min="0" placeholder="0.00">
                                                    </div>
                                                </div>
                                                <div>
                                                    <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Weekly Rate</label>
                                                    <div style="position:relative;">
                                                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                        <input type="number" class="form-control font-mono" style="padding-left:22px;"
                                                               x-model="item.weekly_rate" step="0.01" min="0" placeholder="0.00">
                                                    </div>
                                                </div>
                                                <div>
                                                    <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Monthly Rate</label>
                                                    <div style="position:relative;">
                                                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                        <input type="number" class="form-control font-mono" style="padding-left:22px;"
                                                               x-model="item.monthly_rate" step="0.01" min="0" placeholder="0.00">
                                                    </div>
                                                </div>
                                                <div>
                                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;height:18px;">
                                                        <label class="form-label" style="font-size:0.75rem;margin:0;">Mileage Rate</label>
                                                        <select class="form-select" x-model="item.mileage_unit"
                                                                style="width:auto;height:20px;font-size:0.7rem;padding:0 18px 0 6px;">
                                                            <option value="km">/ km</option>
                                                            <option value="miles">/ mi</option>
                                                        </select>
                                                    </div>
                                                    <div style="position:relative;">
                                                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                        <input type="number" class="form-control font-mono" style="padding-left:22px;"
                                                               x-model="item.mileage_rate" step="0.0001" min="0" placeholder="0.0000">
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Error -->
                                            <div x-show="item._saveError" class="text-danger" style="font-size:0.8rem;padding:0 16px 8px;"
                                                 x-text="item._saveError"></div>
                                            <!-- Footer: save/cancel -->
                                            <div style="padding:12px 16px;border-top:1px solid var(--border-color);display:flex;gap:8px;justify-content:flex-end;">
                                                <button class="btn btn-secondary btn-sm"
                                                        @click.stop="cancelOverrideEdit(item)">Cancel</button>
                                                <button class="btn btn-primary btn-sm"
                                                        :disabled="item._saving"
                                                        @click.stop="saveOverride(item)">
                                                    <span x-text="item._saving ? 'Saving…' : 'Save'"></span>
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                    <?php endif; ?>

                                </div>
                            </template>
                        </div>
                    </div>

                </div>
            </template>
        </div>
    </template>

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
        // Search
        q: '',

        // Global rate cards (customer_id IS NULL)
        globalCards:   [],
        globalTotal:   0,
        globalLoading: false,
        globalOpen:    true,

        // Customer override groups
        ovGroups:  [],
        ovTotal:   0,
        ovLoading: false,
        openGroups: {},   // { customer_id: true/false }

        // Modals
        deleteCardModal: { open: false, id: null, name: '', updated_at: null, saving: false, error: '' },
        deleteOvModal:   { open: false, id: null, label: '', updated_at: null, saving: false, error: '' },

        init() {
            this.loadGlobalCards();
            this.loadOverrides();
        },

        // ── Search ──────────────────────────────────────────────────────────
        search() {
            this.loadOverrides();
        },

        // ── Global rate cards ───────────────────────────────────────────────
        async loadGlobalCards() {
            this.globalLoading = true;
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/rate_cards/index') ?>?customer_id=0&per_page=50&sort=effective_from&dir=DESC`);
                this.globalCards = r.data?.items ?? [];
                this.globalTotal = r.data?.pagination?.total ?? 0;
            } catch (e) {
                this.globalCards = [];
            } finally {
                this.globalLoading = false;
            }
        },

        // ── Customer override groups ─────────────────────────────────────────
        async loadOverrides() {
            this.ovLoading = true;
            const params = new URLSearchParams({ per_page: 100, sort: 'equipment_type', dir: 'ASC' });
            if (this.q) params.set('q', this.q);
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/customer_equipment_rates/index') ?>?${params}`);
                const items = r.data?.items ?? [];
                this.ovTotal  = r.data?.pagination?.total ?? 0;
                this.ovGroups = this._buildGroups(items);
                // Auto-expand first group on initial load
                if (Object.keys(this.openGroups).length === 0 && this.ovGroups.length > 0) {
                    this.openGroups[this.ovGroups[0].customer_id] = true;
                }
            } catch (e) {
                this.ovGroups = [];
            } finally {
                this.ovLoading = false;
            }
        },

        _buildGroups(items) {
            const map = {};
            for (const item of items) {
                // Inline-edit state flags (not part of the API payload)
                item._editing   = false;
                item._saving    = false;
                item._saveError = '';
                const k = item.customer_id;
                if (!map[k]) map[k] = { customer_id: k, customer_name: item.customer_name, items: [] };
                map[k].items.push(item);
            }
            return Object.values(map).sort((a, b) => a.customer_name.localeCompare(b.customer_name));
        },

        toggleGroup(customerId) {
            this.openGroups[customerId] = !this.openGroups[customerId];
        },

        // ── Delete: rate card ────────────────────────────────────────────────
        confirmDeleteCard(card) {
            this.deleteCardModal = { open: true, id: card.id, name: card.name, updated_at: card.updated_at, saving: false, error: '' };
        },

        async deleteCard() {
            this.deleteCardModal.saving = true;
            this.deleteCardModal.error  = '';
            try {
                await FF_Api.post('<?= base_url('api/v1/rate_cards/delete') ?>', { id: this.deleteCardModal.id, updated_at: this.deleteCardModal.updated_at });
                this.deleteCardModal.open = false;
                this.loadGlobalCards();
            } catch (e) {
                this.deleteCardModal.error = e.message || 'Delete failed.';
            } finally {
                this.deleteCardModal.saving = false;
            }
        },

        // ── Edit: override (inline upsert) ───────────────────────────────────
        editOverride(item) {
            // Snapshot current values so Cancel can restore them
            item._snapshot = {
                daily_rate:   item.daily_rate,
                weekly_rate:  item.weekly_rate,
                monthly_rate: item.monthly_rate,
                mileage_rate: item.mileage_rate,
                mileage_unit: item.mileage_unit,
                currency:     item.currency,
            };
            item._saveError = '';
            item._editing   = true;
        },

        cancelOverrideEdit(item) {
            if (item._snapshot) Object.assign(item, item._snapshot);
            item._saveError = '';
            item._editing   = false;
        },

        async saveOverride(item) {
            item._saving    = true;
            item._saveError = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/customer_equipment_rates/upsert') ?>', {
                    id:             item.id,
                    customer_id:    item.customer_id,
                    equipment_type: item.equipment_type,
                    effective_from: item.effective_from,
                    updated_at:     item.updated_at,
                    daily_rate:     item.daily_rate   === '' ? null : item.daily_rate,
                    weekly_rate:    item.weekly_rate  === '' ? null : item.weekly_rate,
                    monthly_rate:   item.monthly_rate === '' ? null : item.monthly_rate,
                    mileage_rate:   item.mileage_rate === '' ? null : item.mileage_rate,
                    mileage_unit:   item.mileage_unit,
                    currency:       item.currency,
                });
                if (!r.success) {
                    item._saveError = r.error?.fields
                        ? Object.values(r.error.fields).join(' ')
                        : (r.error?.message || 'Save failed.');
                    return;
                }
                item.updated_at = r.data.updated_at;
                item._editing   = false;
            } catch (e) {
                item._saveError = e.message || 'Save failed.';
            } finally {
                item._saving = false;
            }
        },

        // ── Delete: override ─────────────────────────────────────────────────
        confirmDeleteOverride(item) {
            this.deleteOvModal = {
                open:       true,
                id:         item.id,
                updated_at: item.updated_at,
                label:      item.customer_name + ' — ' + item.equipment_type.replace(/_/g, ' '),
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
                this.loadOverrides();
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
