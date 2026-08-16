<?php
declare(strict_types=1);

/**
 * app/admin/rates/index.php
 *
 * Rates dashboard — rate cards only (S-RATES-CONSOLIDATE).
 * Customer pricing lives entirely on rate cards: a card is either global
 * (customer_id NULL) or customer-specific (customer_id set). The retired
 * per-customer "override" table is no longer shown.
 *
 * Layout:
 *   • Customer Rate Cards — customers (tiles) that have customer-specific
 *     cards; selecting a tile reveals that customer's cards (lazy-loaded).
 *   • Global Rate Cards — collapsible (iOS toggle, hidden by default).
 *
 * D5:  rate_cards has deleted_at.
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/rate_cards/
 * @decisions D5/D7/D19/D30/D32
 * @session  S019, S-RATES-REDESIGN, S-RATES-CONSOLIDATE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('rates', 'view');

$today = date('Y-m-d');

// Count rate_card_items rows — each item is a standalone "rate card" in the UI.
$totalCards = db_count(
    "SELECT COUNT(*) FROM rate_card_items rci
     JOIN rate_cards rc ON rc.id = rci.rate_card_id AND rc.deleted_at IS NULL"
);
$activeCards = db_count(
    "SELECT COUNT(*) FROM rate_card_items rci
     JOIN rate_cards rc ON rc.id = rci.rate_card_id AND rc.deleted_at IS NULL
     WHERE rc.effective_from <= ?
       AND (rc.effective_to IS NULL OR rc.effective_to >= ?)",
    [$today, $today]
);
$customerCards = db_count(
    "SELECT COUNT(*) FROM rate_card_items rci
     JOIN rate_cards rc ON rc.id = rci.rate_card_id AND rc.deleted_at IS NULL
     WHERE rc.customer_id IS NOT NULL"
);
$globalCards = db_count(
    "SELECT COUNT(*) FROM rate_card_items rci
     JOIN rate_cards rc ON rc.id = rci.rate_card_id AND rc.deleted_at IS NULL
     WHERE rc.customer_id IS NULL"
);

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

<style>
/* Customer tiles — contrasting cream cards on the dark theme (Apple light-card look). */
.rate-cust-tile {
    cursor: pointer;
    background: #f7f5f0;                 /* warm cream */
    color: #16161a;
    border: 1px solid rgba(0,0,0,0.06);
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.30), 0 1px 2px rgba(0,0,0,0.18);
    transition: transform .18s ease, box-shadow .18s ease;
}
.rate-cust-tile:hover {
    transform: translateY(-2px);
    background: #fffefb;
    box-shadow: 0 12px 28px rgba(0,0,0,0.45), 0 3px 8px rgba(0,0,0,0.28);
}
.rate-cust-tile:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }
.rate-cust-tile.is-selected {
    background: #fffefb;
    box-shadow: 0 0 0 2px var(--color-primary), 0 12px 28px rgba(0,0,0,0.45);
}
.rate-cust-tile .rct-name  { font-weight: 600; font-size: 0.95rem; line-height: 1.3; color: #16161a;
                             white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rate-cust-tile .rct-count { font-size: 0.8125rem; font-weight: 500; margin-top: 8px; color: #44464c; }

/* (.rate-item-card styles now live in app.css — shared with rates/show.php) */

/* iOS-style toggle switch */
.ios-toggle { position: relative; display: inline-block; width: 48px; height: 29px; flex-shrink: 0; }
.ios-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.ios-toggle .track { position: absolute; inset: 0; border-radius: 999px; cursor: pointer;
                     background: rgba(120,120,128,0.32); transition: background .22s ease; }
.ios-toggle .knob  { position: absolute; top: 2px; left: 2px; width: 25px; height: 25px; border-radius: 50%;
                     background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.35), 0 1px 1px rgba(0,0,0,0.2);
                     transition: transform .22s ease; pointer-events: none; }
.ios-toggle input:checked ~ .track { background: #34c759; }   /* iOS green */
.ios-toggle input:checked ~ .knob  { transform: translateX(19px); }
.ios-toggle input:focus-visible ~ .track { box-shadow: 0 0 0 3px rgba(52,199,89,0.35); }

/* Day mode: cream blends into the light page, so the customer tiles flip to
   white + crisp shadow for contrast (.rate-item-card handled in app.css). */
[data-theme="light"] .rate-cust-tile {
    background: #ffffff;
    border-color: rgba(0,0,0,0.08);
    box-shadow: 0 1px 3px rgba(0,0,0,0.07), 0 6px 18px rgba(0,0,0,0.06);
}
[data-theme="light"] .rate-cust-tile:hover { background: #ffffff;
    box-shadow: 0 6px 16px rgba(0,0,0,0.12), 0 10px 30px rgba(0,0,0,0.09); }
[data-theme="light"] .rate-cust-tile.is-selected { background: #ffffff; }
</style>

<div x-data="FF_RatesManager()">

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
        <div class="stat-delta">within effective range</div>
    </div>
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         @click="scrollToCustomers()" @keydown.enter="scrollToCustomers()" @keydown.space.prevent="scrollToCustomers()">
        <div class="stat-label">Customer Cards</div>
        <div class="stat-value font-mono"><?= e($customerCards) ?></div>
        <div class="stat-delta">grouped by customer ↓</div>
    </div>
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         @click="globalOpen = true; scrollToGlobal()" @keydown.enter="globalOpen = true; scrollToGlobal()">
        <div class="stat-label">Global Cards</div>
        <div class="stat-value font-mono"><?= e($globalCards) ?></div>
        <div class="stat-delta">apply to all customers ↓</div>
    </div>
</div>

<!-- ── Customer Rate Cards (grouped by customer) ─────────────────────────── -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:nowrap;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:18px;height:18px;color:var(--text-secondary);flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
        <input type="text" class="form-control form-control-sm"
               style="flex:1;min-width:0;max-width:320px;"
               placeholder="Search customers by name…"
               x-model="q" @input.debounce.350ms="search()">
        <button class="btn btn-ghost btn-sm" style="white-space:nowrap;" x-show="q"
                @click="q='';search()">Clear</button>
        <span class="text-secondary" style="margin-left:auto;white-space:nowrap;font-size:0.8125rem;"
              x-text="groupsTotal > 0 ? groupsTotal + ' customer' + (groupsTotal === 1 ? '' : 's') + ' with custom cards' : ''"></span>
    </div>
</div>

<div x-ref="custSection" style="font-weight:600;font-size:0.9375rem;margin:4px 2px 12px;">Customer Rate Cards</div>

<div x-show="groupsLoading" style="text-align:center;padding:2rem;">
    <span class="text-secondary">Loading…</span>
</div>

<template x-if="!groupsLoading && groups.length === 0">
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <p class="empty-state-title" x-text="q ? 'No customers match “' + q + '”' : 'No customer-specific rate cards yet'"></p>
                <p class="empty-state-text" x-show="!q">Create a rate card and assign it to a customer to set their custom pricing.</p>
            </div>
        </div>
    </div>
</template>

<template x-if="!groupsLoading && groups.length > 0">
    <div>
        <!-- Customer tiles -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;">
            <template x-for="group in groups" :key="group.customer_id">
                <div class="rate-cust-tile" role="button" tabindex="0"
                     :class="selectedId === group.customer_id ? 'is-selected' : ''"
                     @click="selectGroup(group)"
                     @keydown.enter="selectGroup(group)" @keydown.space.prevent="selectGroup(group)">
                    <div class="rct-name" x-text="group.customer_name"></div>
                    <div class="rct-count" x-text="group.card_count + (group.card_count === 1 ? ' card' : ' cards')"></div>
                </div>
            </template>
        </div>

        <!-- Pagination -->
        <div x-show="groupsTotalPages > 1" style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:16px;">
            <button class="btn btn-secondary btn-sm" :disabled="groupsPage <= 1" @click="loadGroups(groupsPage - 1)">← Prev</button>
            <span class="text-secondary" style="font-size:0.875rem;" x-text="'Page ' + groupsPage + ' of ' + groupsTotalPages"></span>
            <button class="btn btn-secondary btn-sm" :disabled="groupsPage >= groupsTotalPages" @click="loadGroups(groupsPage + 1)">Next →</button>
        </div>

        <!-- Detail — selected customer's rate types (one tile per rate_card_item) -->
        <template x-if="selectedGroup">
            <div class="card" style="margin-top:18px;">
                <div class="card-header" style="display:flex;align-items:center;gap:10px;">
                    <a :href="'<?= base_url('customers/show') ?>?id=' + selectedGroup.customer_id"
                       class="link" style="font-weight:600;font-size:1rem;" x-text="selectedGroup.customer_name"></a>
                    <span class="badge badge-neutral" style="font-size:0.75rem;"
                          x-text="(selectedGroup.loaded ? selectedGroup.items.length : selectedGroup.card_count) + ((selectedGroup.loaded ? selectedGroup.items.length : selectedGroup.card_count) === 1 ? ' rate card' : ' rate cards')"></span>
                    <?php if (can('rates', 'create')): ?>
                    <a :href="'<?= base_url('rates/create') ?>?customer_id=' + selectedGroup.customer_id"
                       class="btn btn-secondary btn-sm" style="margin-left:auto;">+ New Card</a>
                    <button class="btn btn-ghost btn-sm" @click="selectedId = null">Close ✕</button>
                    <?php else: ?>
                    <button class="btn btn-ghost btn-sm" style="margin-left:auto;" @click="selectedId = null">Close ✕</button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div x-show="selectedGroup.loading" style="text-align:center;padding:1rem;">
                        <span class="text-secondary">Loading…</span>
                    </div>
                    <template x-if="selectedGroup.loaded && selectedGroup.items.length === 0">
                        <div class="empty-state" style="padding:1rem 0;">
                            <p class="empty-state-title">No rate cards yet</p>
                            <?php if (can('rates', 'create')): ?>
                            <p class="empty-state-text">Create a rate card for this customer to define their pricing.</p>
                            <?php endif; ?>
                        </div>
                    </template>
                    <template x-if="selectedGroup.loaded && selectedGroup.items.length > 0">
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
                            <template x-for="item in selectedGroup.items" :key="item.card_id + '-' + item.equipment_type">
                                <div class="rate-item-card">
                                    <div class="rate-item-card__head">
                                        <span class="rate-item-card__type rate-item-card__type--name" x-text="categoryLabel(item.equipment_type)"></span>
                                        <div style="display:flex;gap:5px;flex-shrink:0;align-items:center;">
                                            <span class="rate-item-card__cur" x-text="item.currency || 'CAD'"></span>
                                            <span class="rate-item-card__pill"
                                                  :class="item.is_active ? 'rate-item-card__pill--active' : 'rate-item-card__pill--inactive'"
                                                  x-text="item.is_active ? 'Active' : 'Inactive'"></span>
                                        </div>
                                    </div>
                                    <div class="rate-item-card__rows">
                                        <template x-if="item.daily_rate">
                                            <span class="rate-item-card__k">Daily</span>
                                        </template>
                                        <template x-if="item.daily_rate">
                                            <span class="rate-item-card__v font-mono" x-text="'$' + parseFloat(item.daily_rate).toFixed(2)"></span>
                                        </template>
                                        <template x-if="item.weekly_rate">
                                            <span class="rate-item-card__k">Weekly</span>
                                        </template>
                                        <template x-if="item.weekly_rate">
                                            <span class="rate-item-card__v font-mono" x-text="'$' + parseFloat(item.weekly_rate).toFixed(2)"></span>
                                        </template>
                                        <template x-if="item.monthly_rate">
                                            <span class="rate-item-card__k">Monthly</span>
                                        </template>
                                        <template x-if="item.monthly_rate">
                                            <span class="rate-item-card__v font-mono" x-text="'$' + parseFloat(item.monthly_rate).toFixed(2)"></span>
                                        </template>
                                        <template x-if="item.mileage_rate">
                                            <span class="rate-item-card__k">Mileage</span>
                                        </template>
                                        <template x-if="item.mileage_rate">
                                            <span class="rate-item-card__v font-mono" x-text="'$' + parseFloat(item.mileage_rate).toFixed(4) + ' / ' + (item.mileage_unit || 'km')"></span>
                                        </template>
                                        <template x-if="!item.daily_rate && !item.weekly_rate && !item.monthly_rate && !item.mileage_rate">
                                            <span class="rate-item-card__empty">No rates set</span>
                                        </template>
                                    </div>
                                    <div class="rate-item-card__foot">
                                        <a :href="'<?= base_url('rates/show') ?>?id=' + item.card_id" class="btn btn-secondary btn-sm">Edit</a>
                                        <?php if (can('rates', 'delete')): ?>
                                        <button class="btn btn-outline-danger btn-sm"
                                                :disabled="item.is_default"
                                                @click.stop="confirmDeleteCard({ id: item.card_id, name: item.card_name, updated_at: item.card_updated_at })">Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</template>

<!-- ── Global Rate Cards (collapsible, hidden by default) ────────────────── -->
<div x-ref="globalSection" style="display:flex;align-items:center;gap:12px;margin-top:28px;margin-bottom:12px;">
    <span style="font-weight:600;font-size:1rem;">Global Rate Cards</span>
    <span class="badge badge-neutral" style="font-size:0.75rem;" x-text="globalTotal"></span>
    <label class="ios-toggle" style="margin-left:auto;" title="Show / hide global rate cards">
        <input type="checkbox" x-model="globalOpen">
        <span class="track"></span>
        <span class="knob"></span>
    </label>
</div>

<div class="card" x-show="globalOpen" x-cloak style="margin-bottom:12px;">
    <div class="card-body">
        <div x-show="globalLoading" style="text-align:center;padding:1.5rem;">
            <span class="text-secondary">Loading…</span>
        </div>

        <template x-if="!globalLoading && globalCards.length === 0">
            <div class="empty-state" style="padding:1.5rem 0;">
                <p class="empty-state-title">No global rate cards</p>
                <p class="empty-state-text">Global cards apply to every customer unless a customer-specific card overrides them.</p>
                <?php if (can('rates', 'create')): ?>
                <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">+ New Rate Card</a>
                <?php endif; ?>
            </div>
        </template>

        <template x-if="!globalLoading && globalCards.length > 0">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
                <template x-for="card in globalCards" :key="card.id">
                    <div class="rate-item-card">
                        <div class="rate-item-card__head">
                            <span class="rate-item-card__type rate-item-card__type--name" x-text="card.name"></span>
                            <div style="display:flex;gap:5px;flex-shrink:0;">
                                <span class="rate-item-card__pill"
                                      :class="card.is_active ? 'rate-item-card__pill--active' : 'rate-item-card__pill--inactive'"
                                      x-text="card.is_active ? 'Active' : 'Inactive'"></span>
                                <span x-show="card.is_default" class="rate-item-card__pill rate-item-card__pill--default">Default</span>
                            </div>
                        </div>
                        <div class="rate-item-card__rows">
                            <span class="rate-item-card__k">Types</span>
                            <span class="rate-item-card__v" x-text="card.item_count ?? 0"></span>
                            <span class="rate-item-card__k">Effective</span>
                            <span class="rate-item-card__v">
                                <span x-text="card.effective_from || '—'"></span>
                                <span style="color:#8a8a8e;"> → </span>
                                <span x-text="card.effective_to || 'Open'"></span>
                            </span>
                        </div>
                        <div class="rate-item-card__foot">
                            <a :href="'<?= base_url('rates/show') ?>?id=' + card.id" class="btn btn-secondary btn-sm">Edit</a>
                            <?php if (can('rates', 'delete')): ?>
                            <button class="btn btn-outline-danger btn-sm" :disabled="card.is_default"
                                    @click.stop="confirmDeleteCard(card)">Delete</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>

<!-- ── Delete rate card modal ─────────────────────────────────────────────── -->
<div class="modal-backdrop" x-show="deleteCardModal.open" x-cloak>
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title">Delete Rate Card</h3>
            <button class="modal-close-btn" aria-label="Close" @click="deleteCardModal.open = false">×</button>
        </div>
        <div class="modal-body">
            <p>Delete <strong x-text="deleteCardModal.name"></strong>?</p>
            <p class="text-secondary" style="font-size:0.875rem;margin-top:8px;">Historical lease rates are unaffected.</p>
            <p class="text-danger" x-show="deleteCardModal.error" x-text="deleteCardModal.error"
               style="font-size:0.875rem;margin-top:8px;"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" @click="deleteCardModal.open = false">Cancel</button>
            <button class="btn btn-danger btn-sm" :disabled="deleteCardModal.saving" @click="deleteCard()">
                <span x-text="deleteCardModal.saving ? 'Deleting…' : 'Delete'"></span>
            </button>
        </div>
    </div>
</div>

</div><!-- /x-data -->

<script>
function FF_RatesManager() {
    return {
        // Customer search + grouped customer cards
        q:               '',
        groups:          [],
        groupsTotal:     0,
        groupsPage:      1,
        groupsTotalPages: 1,
        groupsLoading:   false,
        selectedId:      null,

        get selectedGroup() {
            return this.groups.find(g => g.customer_id === this.selectedId) || null;
        },

        // Global rate cards (collapsible)
        globalCards:   [],
        globalTotal:   0,
        globalLoading: false,
        globalOpen:    false,

        deleteCardModal: { open: false, id: null, name: '', updated_at: null, saving: false, error: '' },

        init() {
            this.loadGroups();
            this.loadGlobal();
        },

        search() { this.loadGroups(1); },

        categoryLabel(slug) {
            if (!slug) return '—';
            const labels = {
                chassis: 'Chassis', dry_van: 'Dry Van', reefer: 'Reefer',
                container: 'Container', flatbed: 'Flatbed', step_deck: 'Step Deck',
                lowboy: 'Lowboy', tanker: 'Tanker', dump: 'Dump', other: 'Other',
            };
            return labels[slug] || slug.replace(/_/g, ' ');
        },

        scrollToCustomers() { this.$refs.custSection?.scrollIntoView({ behavior: 'smooth', block: 'start' }); },
        scrollToGlobal()    { this.$nextTick(() => this.$refs.globalSection?.scrollIntoView({ behavior: 'smooth', block: 'start' })); },

        // ── Customers with customer-specific cards (paginated) ──────────────
        async loadGroups(page = 1) {
            this.groupsLoading = true;
            this.groupsPage    = page;
            const params = new URLSearchParams({ page, per_page: 15 });
            if (this.q) params.set('q', this.q);
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/rate_cards/customer_groups') ?>?${params}`);
                const rows = r.data?.items ?? [];
                this.groupsTotal      = r.data?.pagination?.total ?? 0;
                this.groupsTotalPages = r.data?.pagination?.total_pages ?? 1;
                this.groups = rows.map(row => ({
                    customer_id:   row.customer_id,
                    customer_name: row.customer_name,
                    card_count:    row.card_count,
                    loading: false, loaded: false, items: [],
                }));
                if (!this.groups.some(g => g.customer_id === this.selectedId)) this.selectedId = null;
            } catch (e) {
                this.groups = []; this.groupsTotal = 0; this.groupsTotalPages = 1; this.selectedId = null;
            } finally {
                this.groupsLoading = false;
            }
        },

        selectGroup(group) {
            if (this.selectedId === group.customer_id) { this.selectedId = null; return; }
            this.selectedId = group.customer_id;
            if (!group.loaded && !group.loading) this.loadGroupCards(group);
        },

        async loadGroupCards(group) {
            group.loading = true;
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/rate_cards/index') ?>?customer_id=${group.customer_id}&per_page=100&include_items=1&sort=effective_from&dir=DESC`);
                const cards = r.data?.items ?? [];
                // Flatten each card's items so each type appears as its own tile.
                const allItems = [];
                for (const card of cards) {
                    for (const item of (card.items || [])) {
                        allItems.push({
                            equipment_type:  item.equipment_type,
                            daily_rate:      item.daily_rate,
                            weekly_rate:     item.weekly_rate,
                            monthly_rate:    item.monthly_rate,
                            mileage_rate:    item.mileage_rate,
                            mileage_unit:    item.mileage_unit || 'km',
                            currency:        item.currency || 'CAD',
                            card_id:         card.id,
                            card_name:       card.name,
                            is_active:       !!card.is_active,
                            is_default:      !!card.is_default,
                            card_updated_at: card.updated_at,
                        });
                    }
                }
                group.items  = allItems;
                group.loaded = true;
            } catch (e) {
                group.items = [];
            } finally {
                group.loading = false;
            }
        },

        // ── Global cards ────────────────────────────────────────────────────
        async loadGlobal() {
            this.globalLoading = true;
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/rate_cards/index') ?>?customer_id=0&per_page=100&sort=effective_from&dir=DESC`);
                this.globalCards = r.data?.items ?? [];
                this.globalTotal = r.data?.pagination?.total ?? 0;
            } catch (e) {
                this.globalCards = []; this.globalTotal = 0;
            } finally {
                this.globalLoading = false;
            }
        },

        // ── Delete a rate card ──────────────────────────────────────────────
        confirmDeleteCard(card) {
            this.deleteCardModal = { open: true, id: card.id, name: card.name, updated_at: card.updated_at, saving: false, error: '' };
        },

        async deleteCard() {
            this.deleteCardModal.saving = true;
            this.deleteCardModal.error  = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/delete') ?>', { id: this.deleteCardModal.id, updated_at: this.deleteCardModal.updated_at });
                if (r && r.success === false) { this.deleteCardModal.error = r.error?.message || 'Delete failed.'; return; }
                this.deleteCardModal.open = false;
                // Refresh whichever sections could contain the deleted card.
                this.loadGlobal();
                const sel = this.selectedGroup;
                await this.loadGroups(this.groupsPage);
                if (sel) {
                    const still = this.groups.find(g => g.customer_id === sel.customer_id);
                    if (still) { this.selectedId = still.customer_id; this.loadGroupCards(still); }
                }
            } catch (e) {
                this.deleteCardModal.error = e.message || 'Delete failed.';
            } finally {
                this.deleteCardModal.saving = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
