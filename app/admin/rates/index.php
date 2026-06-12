<?php
declare(strict_types=1);

/**
 * app/admin/rates/index.php
 *
 * Rates dashboard — rate cards only (S-RATES-CONSOLIDATE).
 * Customer pricing now lives entirely on rate cards: a card may be global
 * (customer_id NULL) or customer-specific (customer_id set). The former
 * per-customer "override" table is retired and no longer shown here.
 *
 * KPI tiles double as scope filters for the cards grid:
 *   All · Active · Customer-specific · Global.
 * The grid is paginated and searchable by card name or customer.
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
$globalCards = db_count(
    "SELECT COUNT(*) FROM rate_cards WHERE deleted_at IS NULL AND customer_id IS NULL"
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

<div x-data="FF_RatesManager()" x-init="init()">

<!-- KPI tiles — clickable scope filters for the cards grid -->
<div class="stat-grid" style="margin-bottom:20px;">
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         :style="scope === 'all' ? 'outline:2px solid var(--color-primary);outline-offset:-1px;' : ''"
         @click="setScope('all')" @keydown.enter="setScope('all')" @keydown.space.prevent="setScope('all')">
        <div class="stat-label">All Rate Cards</div>
        <div class="stat-value font-mono"><?= e($totalCards) ?></div>
        <div class="stat-delta">show everything</div>
    </div>
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         :style="scope === 'active' ? 'outline:2px solid var(--color-primary);outline-offset:-1px;' : ''"
         @click="setScope('active')" @keydown.enter="setScope('active')" @keydown.space.prevent="setScope('active')">
        <div class="stat-label">Active Today</div>
        <div class="stat-value font-mono"><?= e($activeCards) ?></div>
        <div class="stat-delta">within effective range</div>
    </div>
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         :style="scope === 'customer' ? 'outline:2px solid var(--color-primary);outline-offset:-1px;' : ''"
         @click="setScope('customer')" @keydown.enter="setScope('customer')" @keydown.space.prevent="setScope('customer')">
        <div class="stat-label">Customer Cards</div>
        <div class="stat-value font-mono"><?= e($customerCards) ?></div>
        <div class="stat-delta">customer-specific</div>
    </div>
    <div class="stat-card" role="button" tabindex="0" style="cursor:pointer;"
         :style="scope === 'global' ? 'outline:2px solid var(--color-primary);outline-offset:-1px;' : ''"
         @click="setScope('global')" @keydown.enter="setScope('global')" @keydown.space.prevent="setScope('global')">
        <div class="stat-label">Global Cards</div>
        <div class="stat-value font-mono"><?= e($globalCards) ?></div>
        <div class="stat-delta">apply to all customers</div>
    </div>
</div>

<!-- Cards section -->
<div class="card">
    <!-- Toolbar: title + search (single row) -->
    <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:nowrap;">
        <span style="font-weight:600;font-size:0.9375rem;white-space:nowrap;" x-text="scopeLabel()"></span>
        <button class="btn btn-ghost btn-sm" x-show="scope !== 'all'"
                style="padding:1px 8px;font-size:0.7rem;white-space:nowrap;"
                @click="setScope('all')">Show all</button>

        <div style="position:relative;flex:1;min-width:0;max-width:300px;margin-left:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px;color:var(--text-secondary);position:absolute;left:9px;top:50%;transform:translateY(-50%);pointer-events:none;"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
            <input type="text" class="form-control form-control-sm"
                   style="width:100%;padding-left:30px;"
                   placeholder="Search by card name or customer…"
                   x-model="q" @input.debounce.350ms="loadCards(1)">
        </div>
        <button class="btn btn-ghost btn-sm" x-show="q" style="white-space:nowrap;"
                @click="q='';loadCards(1)">Clear</button>

        <span class="text-secondary" style="margin-left:auto;white-space:nowrap;font-size:0.8125rem;"
              x-text="cardsTotal > 0 ? cardsTotal + ' card' + (cardsTotal === 1 ? '' : 's') : ''"></span>
    </div>

    <div class="card-body">
        <!-- Loading -->
        <div x-show="cardsLoading" style="text-align:center;padding:2rem;">
            <span class="text-secondary">Loading…</span>
        </div>

        <!-- Empty state -->
        <template x-if="!cardsLoading && cards.length === 0">
            <div class="empty-state">
                <p class="empty-state-title"
                   x-text="q ? 'No cards match “' + q + '”' : 'No ' + scopeLabel().toLowerCase()"></p>
                <p class="empty-state-text">Rate cards define pricing by equipment category — global or per-customer.</p>
                <?php if (can('rates', 'create')): ?>
                <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">+ New Rate Card</a>
                <?php endif; ?>
            </div>
        </template>

        <!-- Cards grid -->
        <template x-if="!cardsLoading && cards.length > 0">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
                <template x-for="card in cards" :key="card.id">
                    <div style="border:1px solid var(--border-color);border-radius:10px;background:var(--bg-secondary);padding:16px;display:flex;flex-direction:column;">
                        <!-- Name + status -->
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:8px;">
                            <span class="font-semibold" x-text="card.name"
                                  style="font-size:0.9375rem;font-weight:600;line-height:1.3;"></span>
                            <div style="display:flex;gap:4px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;">
                                <span class="badge" :class="card.is_active ? 'badge-success' : 'badge-neutral'"
                                      x-text="card.is_active ? 'Active' : 'Inactive'" style="font-size:0.7rem;"></span>
                                <span x-show="card.is_default" class="badge badge-info" style="font-size:0.7rem;">Default</span>
                            </div>
                        </div>
                        <!-- Scope: customer link or Global badge -->
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
                        <div style="display:flex;gap:8px;margin-top:14px;">
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

    <!-- Pagination -->
    <template x-if="!cardsLoading && cardsTotalPages > 1">
        <div class="card-footer" style="display:flex;justify-content:center;align-items:center;gap:8px;padding:12px;">
            <button class="btn btn-secondary btn-sm" :disabled="cardsPage <= 1" @click="loadCards(cardsPage - 1)">← Prev</button>
            <span class="text-secondary" style="font-size:0.875rem;"
                  x-text="'Page ' + cardsPage + ' of ' + cardsTotalPages"></span>
            <button class="btn btn-secondary btn-sm" :disabled="cardsPage >= cardsTotalPages" @click="loadCards(cardsPage + 1)">Next →</button>
        </div>
    </template>
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

</div><!-- /x-data -->

<script>
function FF_RatesManager() {
    return {
        // scope: 'all' | 'active' | 'customer' | 'global'
        scope:           'all',
        q:               '',
        cards:           [],
        cardsTotal:      0,
        cardsPage:       1,
        cardsTotalPages: 1,
        cardsLoading:    false,

        deleteCardModal: { open: false, id: null, name: '', updated_at: null, saving: false, error: '' },

        init() {
            this.loadCards();
        },

        scopeLabel() {
            return {
                all:      'All Rate Cards',
                active:   'Active Rate Cards',
                customer: 'Customer-Specific Rate Cards',
                global:   'Global Rate Cards',
            }[this.scope] ?? 'Rate Cards';
        },

        setScope(scope) {
            this.scope = scope;
            this.loadCards(1);
        },

        async loadCards(page = 1) {
            this.cardsLoading = true;
            this.cardsPage    = page;
            const params = new URLSearchParams({ page, per_page: 24, sort: 'effective_from', dir: 'DESC' });
            if (this.q)                      params.set('q', this.q);
            if (this.scope === 'active')     params.set('active', '1');
            if (this.scope === 'customer')   params.set('has_customer', '1');
            if (this.scope === 'global')     params.set('customer_id', '0');
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/rate_cards/index') ?>?${params}`);
                this.cards           = r.data?.items ?? [];
                this.cardsTotal      = r.data?.pagination?.total ?? 0;
                this.cardsTotalPages = r.data?.pagination?.total_pages ?? 1;
            } catch (e) {
                this.cards = [];
                this.cardsTotal = 0;
                this.cardsTotalPages = 1;
            } finally {
                this.cardsLoading = false;
            }
        },

        confirmDeleteCard(card) {
            this.deleteCardModal = { open: true, id: card.id, name: card.name, updated_at: card.updated_at, saving: false, error: '' };
        },

        async deleteCard() {
            this.deleteCardModal.saving = true;
            this.deleteCardModal.error  = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/delete') ?>', { id: this.deleteCardModal.id, updated_at: this.deleteCardModal.updated_at });
                if (r && r.success === false) {
                    this.deleteCardModal.error = r.error?.message || 'Delete failed.';
                    return;
                }
                this.deleteCardModal.open = false;
                this.loadCards(this.cardsPage);
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
