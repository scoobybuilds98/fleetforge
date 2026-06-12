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

<div x-data="FF_RatesManager()" x-init="init()">

<!-- KPI tiles — clickable filters for the Rate Cards section -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         :style="cardScope === 'all' ? 'outline:2px solid var(--color-primary);outline-offset:-1px;' : ''"
         @click="setScope('all')" @keydown.enter="setScope('all')" @keydown.space.prevent="setScope('all')">
        <div class="stat-label">Rate Cards</div>
        <div class="stat-value font-mono"><?= e($totalCards) ?></div>
        <div class="stat-delta">show all cards</div>
    </div>
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         :style="cardScope === 'active' ? 'outline:2px solid var(--color-primary);outline-offset:-1px;' : ''"
         @click="setScope('active')" @keydown.enter="setScope('active')" @keydown.space.prevent="setScope('active')">
        <div class="stat-label">Active Today</div>
        <div class="stat-value font-mono"><?= e($activeCards) ?></div>
        <div class="stat-delta">show active only</div>
    </div>
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         :style="cardScope === 'customer' ? 'outline:2px solid var(--color-primary);outline-offset:-1px;' : ''"
         @click="setScope('customer')" @keydown.enter="setScope('customer')" @keydown.space.prevent="setScope('customer')">
        <div class="stat-label">Customer Cards</div>
        <div class="stat-value font-mono"><?= e($customerCards) ?></div>
        <div class="stat-delta">customer-specific only</div>
    </div>
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         @click="scrollToOverrides()" @keydown.enter="scrollToOverrides()" @keydown.space.prevent="scrollToOverrides()">
        <div class="stat-label">Rate Overrides</div>
        <div class="stat-value font-mono"><?= e($totalOverrides) ?></div>
        <div class="stat-delta">jump to overrides ↓</div>
    </div>
</div>


    <!-- ── Customer search toolbar (single row) ──────────────────────────── -->
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:nowrap;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:18px;height:18px;color:var(--text-secondary);flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
            <input type="text" class="form-control form-control-sm"
                   style="flex:1;min-width:0;max-width:320px;"
                   placeholder="Search customers by name…"
                   x-model="q"
                   @input.debounce.350ms="search()">
            <button class="btn btn-ghost btn-sm" style="white-space:nowrap;" x-show="q"
                    @click="q='';search()">Clear</button>
            <span class="text-secondary" style="margin-left:auto;white-space:nowrap;font-size:0.8125rem;"
                  x-text="groupsTotal > 0 ? groupsTotal + ' customer' + (groupsTotal === 1 ? '' : 's') + ' with overrides' : ''"></span>
        </div>
    </div>

    <!-- ── Rate Cards accordion (scope-filtered by the tiles) ────────────── -->
    <div class="card" style="margin-bottom:12px;">
        <!-- Header -->
        <div class="card-header"
             style="display:flex;align-items:center;gap:12px;cursor:pointer;user-select:none;"
             @click="cardsOpen = !cardsOpen">
            <span class="text-secondary" style="font-size:0.75rem;width:12px;flex-shrink:0;"
                  x-text="cardsOpen ? '▼' : '▶'"></span>
            <span style="font-weight:600;font-size:0.9375rem;" x-text="scopeLabel()"></span>
            <button class="btn btn-ghost btn-sm" x-show="cardScope !== 'all'"
                    style="padding:1px 8px;font-size:0.7rem;"
                    @click.stop="setScope('all')">Show all</button>
            <span class="badge badge-neutral" style="margin-left:auto;font-size:0.75rem;"
                  x-text="cardsTotal + ' card' + (cardsTotal === 1 ? '' : 's')"></span>
        </div>

        <!-- Expanded body -->
        <div x-show="cardsOpen" class="card-body">
            <div x-show="cardsLoading" style="text-align:center;padding:1.5rem;">
                <span class="text-secondary">Loading…</span>
            </div>

            <template x-if="!cardsLoading && cards.length === 0">
                <div class="empty-state" style="padding:1.5rem 0;">
                    <p class="empty-state-title" x-text="'No ' + scopeLabel().toLowerCase()"></p>
                    <p class="empty-state-text">Rate cards define standard pricing by equipment category.</p>
                    <?php if (can('rates', 'create')): ?>
                    <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">+ New Rate Card</a>
                    <?php endif; ?>
                </div>
            </template>

            <template x-if="!cardsLoading && cards.length > 0">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
                    <template x-for="card in cards" :key="card.id">
                        <div style="border:1px solid var(--border-color);border-radius:8px;padding:16px;">
                            <!-- Name + status -->
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:8px;">
                                <span class="font-semibold" x-text="card.name"
                                      style="font-size:0.9375rem;font-weight:600;line-height:1.3;"></span>
                                <span class="badge"
                                      :class="card.is_active ? 'badge-success' : 'badge-neutral'"
                                      x-text="card.is_active ? 'Active' : 'Inactive'"
                                      style="font-size:0.7rem;flex-shrink:0;"></span>
                            </div>
                            <!-- Scope: Global or customer link -->
                            <div style="margin-bottom:10px;">
                                <template x-if="card.customer_id">
                                    <a :href="'<?= base_url('customers/show') ?>?id=' + card.customer_id"
                                       class="link" style="font-size:0.8125rem;" x-text="card.customer_name"></a>
                                </template>
                                <template x-if="!card.customer_id">
                                    <span class="badge badge-neutral" style="font-size:0.7rem;">Global</span>
                                </template>
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

    <!-- ── Customer accordion groups (paginated + lazy-loaded) ───────────── -->
    <div x-ref="ovSection" style="display:flex;align-items:center;gap:8px;margin:4px 0 12px;">
        <span style="font-weight:600;font-size:0.9375rem;">Customer Rate Overrides</span>
        <span class="badge badge-neutral" style="font-size:0.75rem;"
              x-show="groupsTotal > 0" x-text="groupsTotal"></span>
    </div>

    <div x-show="groupsLoading" style="text-align:center;padding:2rem;">
        <span class="text-secondary">Loading…</span>
    </div>

    <template x-if="!groupsLoading && groups.length === 0">
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title" x-text="q ? 'No customers match “' + q + '”' : 'No customer rate overrides yet'"></p>
                    <p class="empty-state-text" x-show="!q">Per-equipment-type overrides can be set on each customer's Rates tab.</p>
                </div>
            </div>
        </div>
    </template>

    <template x-if="!groupsLoading && groups.length > 0">
        <div>
            <template x-for="group in groups" :key="group.customer_id">
                <div class="card" style="margin-bottom:12px;">

                    <!-- Accordion header — click to expand/collapse (lazy-loads) -->
                    <div class="card-header"
                         style="display:flex;align-items:center;gap:12px;cursor:pointer;user-select:none;"
                         @click="toggleGroup(group)">
                        <span class="text-secondary" style="font-size:0.75rem;width:12px;flex-shrink:0;"
                              x-text="group.open ? '▼' : '▶'"></span>
                        <a :href="'<?= base_url('customers/show') ?>?id=' + group.customer_id"
                           class="link"
                           x-text="group.customer_name"
                           style="font-size:1rem;font-weight:600;"
                           @click.stop></a>
                        <span class="badge badge-neutral"
                              style="margin-left:auto;font-size:0.75rem;"
                              x-text="group.override_count + ' rate' + (group.override_count === 1 ? '' : 's')"></span>
                    </div>

                    <!-- Expanded body -->
                    <div x-show="group.open" class="card-body">

                        <!-- Per-group loading -->
                        <div x-show="group.loading" style="text-align:center;padding:1rem;">
                            <span class="text-secondary">Loading rates…</span>
                        </div>

                        <!-- Equipment rate cards grid -->
                        <div x-show="group.loaded"
                             style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
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
                                                        @click.stop="confirmDeleteOverride(item, group)">Delete</button>
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

            <!-- Pagination — customer pages -->
            <div x-show="groupsTotalPages > 1"
                 style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:4px;">
                <button class="btn btn-secondary btn-sm" :disabled="groupsPage <= 1"
                        @click="loadGroups(groupsPage - 1)">← Prev</button>
                <span class="text-secondary" style="font-size:0.875rem;"
                      x-text="'Page ' + groupsPage + ' of ' + groupsTotalPages"></span>
                <button class="btn btn-secondary btn-sm" :disabled="groupsPage >= groupsTotalPages"
                        @click="loadGroups(groupsPage + 1)">Next →</button>
            </div>
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

        // Rate cards — scope-filtered by the KPI tiles.
        // scope: 'all' | 'active' | 'customer'
        cards:        [],
        cardsTotal:   0,
        cardsLoading: false,
        cardsOpen:    true,
        cardScope:    'all',

        // Customer override groups — one page of customers at a time,
        // overrides lazy-loaded per customer on accordion expand.
        groups:          [],
        groupsTotal:     0,
        groupsPage:      1,
        groupsTotalPages: 1,
        groupsLoading:   false,

        // Modals
        deleteCardModal: { open: false, id: null, name: '', updated_at: null, saving: false, error: '' },
        deleteOvModal:   { open: false, id: null, label: '', updated_at: null, group: null, saving: false, error: '' },

        init() {
            this.loadCards();
            this.loadGroups();
        },

        // ── Search (customer name) ──────────────────────────────────────────
        search() {
            this.loadGroups(1);
        },

        // ── Rate cards (scope-filtered) ─────────────────────────────────────
        scopeLabel() {
            return {
                all:      'All Rate Cards',
                active:   'Active Rate Cards',
                customer: 'Customer-Specific Rate Cards',
            }[this.cardScope] ?? 'Rate Cards';
        },

        // A KPI tile sets the scope; reload the cards and make sure the
        // section is open so the result is visible.
        setScope(scope) {
            this.cardScope = scope;
            this.cardsOpen = true;
            this.loadCards();
        },

        scrollToOverrides() {
            this.$refs.ovSection?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        async loadCards() {
            this.cardsLoading = true;
            const params = new URLSearchParams({ per_page: 100, sort: 'effective_from', dir: 'DESC' });
            if (this.cardScope === 'active')   params.set('active', '1');
            if (this.cardScope === 'customer') params.set('has_customer', '1');
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/rate_cards/index') ?>?${params}`);
                this.cards      = r.data?.items ?? [];
                this.cardsTotal = r.data?.pagination?.total ?? 0;
            } catch (e) {
                this.cards = [];
                this.cardsTotal = 0;
            } finally {
                this.cardsLoading = false;
            }
        },

        // ── Customer list (paginated, searchable) ───────────────────────────
        async loadGroups(page = 1) {
            this.groupsLoading = true;
            this.groupsPage    = page;
            const params = new URLSearchParams({ page, per_page: 15 });
            if (this.q) params.set('q', this.q);
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/customer_equipment_rates/groups') ?>?${params}`);
                const rows = r.data?.items ?? [];
                this.groupsTotal      = r.data?.pagination?.total ?? 0;
                this.groupsTotalPages = r.data?.pagination?.total_pages ?? 1;
                // Map to accordion groups with lazy-load state
                this.groups = rows.map(row => ({
                    customer_id:    row.customer_id,
                    customer_name:  row.customer_name,
                    override_count: row.override_count,
                    open:    false,
                    loading: false,
                    loaded:  false,
                    items:   [],
                }));
            } catch (e) {
                this.groups = [];
                this.groupsTotal = 0;
                this.groupsTotalPages = 1;
            } finally {
                this.groupsLoading = false;
            }
        },

        // ── Expand/collapse a customer; lazy-load its overrides once ─────────
        toggleGroup(group) {
            group.open = !group.open;
            if (group.open && !group.loaded && !group.loading) {
                this.loadGroupItems(group);
            }
        },

        async loadGroupItems(group) {
            group.loading = true;
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/customer_equipment_rates/index') ?>?customer_id=${group.customer_id}&per_page=100&sort=equipment_type&dir=ASC`);
                const items = r.data?.items ?? [];
                items.forEach(it => { it._editing = false; it._saving = false; it._saveError = ''; });
                group.items          = items;
                group.override_count = items.length;
                group.loaded         = true;
            } catch (e) {
                group.items = [];
            } finally {
                group.loading = false;
            }
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
                this.loadCards();
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
        confirmDeleteOverride(item, group) {
            this.deleteOvModal = {
                open:       true,
                id:         item.id,
                updated_at: item.updated_at,
                label:      item.customer_name + ' — ' + item.equipment_type.replace(/_/g, ' '),
                group:      group,
                saving:     false,
                error:      '',
            };
        },

        async deleteOverride() {
            this.deleteOvModal.saving = true;
            this.deleteOvModal.error  = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/customer_equipment_rates/delete') ?>', { id: this.deleteOvModal.id, updated_at: this.deleteOvModal.updated_at });
                if (r && r.success === false) {
                    this.deleteOvModal.error = r.error?.message || 'Delete failed.';
                    return;
                }
                // Remove the override from its group in place (no full reload)
                const group = this.deleteOvModal.group;
                if (group) {
                    group.items = group.items.filter(i => i.id !== this.deleteOvModal.id);
                    group.override_count = group.items.length;
                    // If the customer no longer has any overrides, refresh the
                    // page so they drop off the list (and counts stay correct).
                    if (group.override_count === 0) {
                        this.loadGroups(this.groupsPage);
                    }
                }
                this.deleteOvModal.open = false;
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
