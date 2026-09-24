<?php
declare(strict_types=1);

/**
 * app/admin/rates/index.php
 *
 * Rates home (S-RATES-MODULE) — what every customer pays for every piece of
 * equipment, in one place.
 *
 * Top to bottom:
 *   hero      purpose + Export / Price check / + New rate card
 *   tiles     customers with their own prices · standard prices set ·
 *             prices ending in 30 days · things that need a look (each
 *             tile opens the matching view)
 *   needs a look   lines that would pre-fill a $0 or unsaveable lease,
 *             empty cards, prices ending soon, customer prices that ended
 *             without a renewal while the customer still rents, customers
 *             on rent with no card of their own … (RateInsights::attention)
 *   tabs
 *     Customer prices  one row per customer with their own card(s) — the
 *                      grouped-by-customer view the operator kept in
 *                      S-RATES-CONSOLIDATE-v2 — now showing the prices
 *                      themselves; a row opens every line with its card,
 *                      status and how it compares to the standard price
 *     Standard prices  what a customer WITHOUT their own card pays, per
 *                      equipment type (general card line, else the type's
 *                      default prices — editable in place), with how many
 *                      customer deals cover it and how many units are out
 *     All rate cards   every card, filterable by status / who / equipment;
 *                      select several → Change prices (one step: old cards
 *                      end, new cards start with the new prices) or Delete
 *     Price check      pick a customer + equipment type → the price a new
 *                      lease would get, why, and an estimate from the real
 *                      billing law for a rental period
 *
 * Every figure comes from api/v1/rate_cards/* (lib/RateCards/*); the lookup is
 * the same one the lease form uses, so the Price check can never disagree
 * with what a new lease pre-fills.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php,
 *           api/v1/rate_cards/{overview,customer_pricing,price_book,index,price_check,
 *           revise,bulk_delete,delete,export,sheet_pdf}, api/v1/equipment/templates/update,
 *           includes/partials/record-picker.php, public/assets/{css/rates.css,js/rates.js}
 * @decisions D5/D16/D30/D32
 * @session  S019, S-RATES-REDESIGN, S-RATES-CONSOLIDATE, S-RATES-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\RateCards\RateCardItems;
use FleetForge\RateCards\RateInsights;

require_auth();
require_permission('rates', 'view');

$canCreate    = can('rates', 'create');
$canEdit      = can('rates', 'edit');
$canDelete    = can('rates', 'delete');
$canExport    = can('rates', 'export');
$canEquipEdit = can('equipment', 'edit');
$canCustomers = can('customers', 'view');

// Equipment options for the filters and the Price check (server-side so the
// selects are ready before the first request returns).
$equipOptions = [];
$slugs = array_unique(array_merge(
    array_column(RateInsights::templates(), 'category'),
    array_column(db_select("SELECT DISTINCT equipment_type FROM rate_card_items"), 'equipment_type')
));
sort($slugs);
foreach ($slugs as $slug) {
    $equipOptions[] = ['value' => 'c:' . $slug, 'label' => RateCardItems::label((string) $slug) . ' (every type)', 'group' => 'Category'];
}
$templateOptions = [];
foreach (RateInsights::templates() as $id => $t) {
    $equipOptions[]    = ['value' => 't:' . $id, 'label' => $t['name'], 'group' => 'Equipment type'];
    $templateOptions[] = ['id' => $id, 'name' => $t['name'], 'group' => RateCardItems::label((string) $t['category']), 'active' => (int) $t['is_active'] === 1];
}
usort($templateOptions, static fn ($a, $b) => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

// Deep link into the Price check (?check_customer=N[&check_template=N]#check)
// from a rate card or the customer profile.
$checkPre = ['customer_id' => null, 'customer_name' => null, 'template_id' => ''];
if ($cc = clean_int($_GET['check_customer'] ?? null)) {
    $cr = db_row("SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$cc]);
    if ($cr) {
        $checkPre['customer_id']   = (int) $cr['id'];
        $checkPre['customer_name'] = $cr['company_name'];
    }
}
if (($ct = clean_int($_GET['check_template'] ?? null)) && isset(RateInsights::templates()[$ct])) {
    $checkPre['template_id'] = (string) $ct;
}

$pageTitle      = 'Rates';
$helpModuleSlug = 'rates';
require_once FF_ROOT . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/rates.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
<script src="<?= asset_url('assets/js/rates.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

<?php ob_start(); ?>
    <?= help_button('rates') ?>
    <?php if ($canExport): ?>
    <a href="<?= base_url('api/v1/rate_cards/export') ?>" class="btn btn-secondary btn-sm" title="Every price line in force today, as a spreadsheet">
        <?= \FleetForge\Sop\SopIcons::svg('arrow-down-tray', 'icon-sm') ?> Export
    </a>
    <?php endif; ?>
    <button type="button" class="btn btn-secondary btn-sm" onclick="window.dispatchEvent(new CustomEvent('rt-tab', { detail: 'check' }))">
        <?= \FleetForge\Sop\SopIcons::svg('magnifying-glass', 'icon-sm') ?> Price check
    </button>
    <?php if ($canCreate): ?>
    <a href="<?= base_url('rates/create') ?>" class="btn btn-primary btn-sm">+ New rate card</a>
    <?php endif; ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'crumbs'   => [['Dashboard', base_url('dashboard')], ['Rates', null]],
    'eyebrow'  => 'Billing',
    'icon'     => 'currency-dollar',
    'accent'   => 'primary',
    'title'    => 'Rates',
    'subtitle' => 'What every customer pays for every piece of equipment — standard prices, customer deals, and a price check that shows exactly which price a new lease will get.',
    'art'      => 'rates',
    'actions'  => ob_get_clean(),
]) ?>

<div x-data="FF_RatesHome()" @rt-tab.window="setTab($event.detail)">

    <!-- ── Key numbers ─────────────────────────────────────────────── -->
    <div class="stat-grid stat-grid--4 ff-stats">
        <button type="button" class="stat-card stat-card--purple" @click="setTab('customers'); cust.status = 'in_force'; loadCustomers(1)"
                :class="{ 'ring-active': tab === 'customers' }">
            <span class="stat-icon stat-icon--purple"><svg><use href="#icon-building"/></svg></span>
            <div class="stat-label">Customer deals</div>
            <div class="stat-value font-mono" x-text="kpis ? kpis.customers_with_prices : '—'">—</div>
            <div class="stat-delta" x-text="kpis ? 'customers · ' + kpis.customer_cards + ' cards' : ''"></div>
        </button>
        <button type="button" class="stat-card stat-card--blue" @click="setTab('standard')"
                :class="{ 'ring-active': tab === 'standard' }">
            <span class="stat-icon stat-icon--blue"><svg><use href="#icon-tag"/></svg></span>
            <div class="stat-label">Standard prices</div>
            <div class="stat-value font-mono" x-text="kpis ? kpis.types_priced + ' / ' + kpis.types_active : '—'">—</div>
            <div class="stat-delta">types priced</div>
        </button>
        <button type="button" class="stat-card stat-card--amber" @click="setTab('cards'); cards.status = 'ending'; loadCards(1)"
                :class="{ 'ring-active': tab === 'cards' && cards.status === 'ending' }">
            <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
            <div class="stat-label">Ending in 30 days</div>
            <div class="stat-value font-mono" x-text="kpis ? kpis.ending_soon : '—'">—</div>
            <div class="stat-delta" x-text="kpis ? (kpis.upcoming ? kpis.upcoming + ' starting later' : 'cards to renew') : ''"></div>
        </button>
        <button type="button" class="stat-card stat-card--red" @click="attnOpen = true; $nextTick(() => $refs.attn && $refs.attn.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
            <span class="stat-icon stat-icon--red"><svg><use href="#icon-exclamation-triangle"/></svg></span>
            <div class="stat-label">Needs a look</div>
            <div class="stat-value font-mono" x-text="attention ? attention.items.length : '—'">—</div>
            <div class="stat-delta" x-text="attention ? attnSummary() : ''"></div>
        </button>
    </div>

    <!-- ── Needs a look ────────────────────────────────────────────── -->
    <section class="rt-attn" x-ref="attn" x-show="attention" x-cloak aria-label="Rate cards that need a look">
        <div class="rt-attn-head">
            <h2>Needs a look</h2>
            <span class="rt-count" x-show="attention && attention.items.length" x-text="attention ? attention.items.length : ''"></span>
            <span class="rt-attn-ok" x-show="attention && !attention.items.length">All clear — every card prices correctly.</span>
            <span style="flex:1"></span>
            <button type="button" class="btn btn-ghost btn-sm" x-show="attention && attention.items.length" @click="attnOpen = !attnOpen"
                    x-text="attnOpen ? 'Hide' : 'Show'"></button>
        </div>
        <template x-if="attention && attention.items.length && attnOpen">
            <div>
                <ul class="rt-attn-list">
                    <template x-for="(it, i) in attnVisible()" :key="i">
                        <li class="rt-attn-item" :class="'rt-tone-' + it.tone">
                            <span class="rt-attn-dot" aria-hidden="true"></span>
                            <div style="min-width:0;">
                                <div class="rt-attn-title" x-text="it.title"></div>
                                <div class="rt-attn-text" x-text="it.text"></div>
                            </div>
                            <div style="display:flex;gap:6px;">
                                <?php if ($canCreate && $canEdit): ?>
                                <a class="btn btn-secondary btn-sm" x-show="it.action === 'revise'" :href="baseUrl + it.url + '&action=revise'">Change prices</a>
                                <?php endif; ?>
                                <a class="btn btn-ghost btn-sm" :href="baseUrl + it.url" x-text="it.kind === 'no_card' ? 'Create their card' : 'Open'"></a>
                            </div>
                        </li>
                    </template>
                </ul>
                <div class="rt-attn-foot" x-show="attention.items.length > 5">
                    <button type="button" @click="attnAll = !attnAll" x-text="attnAll ? 'Show the 5 most urgent' : 'Show all ' + attention.items.length"></button>
                </div>
            </div>
        </template>
    </section>

    <!-- ── Toolbar: views + filters ────────────────────────────────── -->
    <div class="table-toolbar">
        <div class="table-toolbar-left table-toolbar-left--wrap">
            <div class="tab-bar" role="tablist" aria-label="Rates views">
                <button class="tab-btn" role="tab" :class="{ 'is-active': tab === 'customers' }" :aria-selected="tab === 'customers'" @click="setTab('customers')">
                    Customer prices <span class="tab-badge" x-show="kpis" x-text="kpis ? kpis.customers_with_prices : ''"></span>
                </button>
                <button class="tab-btn" role="tab" :class="{ 'is-active': tab === 'standard' }" :aria-selected="tab === 'standard'" @click="setTab('standard')">
                    Standard prices
                </button>
                <button class="tab-btn" role="tab" :class="{ 'is-active': tab === 'cards' }" :aria-selected="tab === 'cards'" @click="setTab('cards')">
                    All rate cards <span class="tab-badge" x-show="kpis" x-text="kpis ? kpis.cards : ''"></span>
                </button>
                <button class="tab-btn" role="tab" :class="{ 'is-active': tab === 'check' }" :aria-selected="tab === 'check'" @click="setTab('check')">
                    Price check
                </button>
            </div>

            <template x-if="tab === 'customers'">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <input type="search" class="form-control form-control-sm" style="min-width:220px;" maxlength="255"
                           placeholder="Search customer or card…" x-model="cust.q" @input.debounce.350ms="loadCustomers(1)"
                           aria-label="Search customers">
                    <select class="form-select form-control-sm" x-model="cust.status" @change="loadCustomers(1)" aria-label="Card status">
                        <option value="in_force">In force today</option>
                        <option value="ending">Ending soon</option>
                        <option value="upcoming">Starting later</option>
                        <option value="expired">Ended</option>
                        <option value="all">Any status</option>
                    </select>
                    <select class="form-select form-control-sm" x-model="cust.equipment" @change="loadCustomers(1)" aria-label="Equipment">
                        <option value="">All equipment</option>
                        <?php foreach (['Category', 'Equipment type'] as $grp): ?>
                        <optgroup label="<?= e($grp) ?>">
                            <?php foreach ($equipOptions as $o): if ($o['group'] !== $grp) continue; ?>
                            <option value="<?= e($o['value']) ?>"><?= e($o['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </template>

            <template x-if="tab === 'cards'">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <input type="search" class="form-control form-control-sm" style="min-width:200px;" maxlength="255"
                           placeholder="Search card or customer…" x-model="cards.q" @input.debounce.350ms="loadCards(1)"
                           aria-label="Search rate cards">
                    <select class="form-select form-control-sm" x-model="cards.status" @change="loadCards(1)" aria-label="Status">
                        <option value="">Any status</option>
                        <option value="in_force">In force today</option>
                        <option value="ending">Ending in 30 days</option>
                        <option value="upcoming">Starting later</option>
                        <option value="expired">Ended</option>
                    </select>
                    <select class="form-select form-control-sm" x-model="cards.scope" @change="loadCards(1)" aria-label="Who the card is for">
                        <option value="">Customers &amp; everyone</option>
                        <option value="customer">One customer</option>
                        <option value="general">Everyone (standard)</option>
                    </select>
                    <select class="form-select form-control-sm" x-model="cards.equipment" @change="loadCards(1)" aria-label="Equipment">
                        <option value="">All equipment</option>
                        <?php foreach (['Category', 'Equipment type'] as $grp): ?>
                        <optgroup label="<?= e($grp) ?>">
                            <?php foreach ($equipOptions as $o): if ($o['group'] !== $grp) continue; ?>
                            <option value="<?= e($o['value']) ?>"><?= e($o['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </template>
        </div>

        <div class="table-toolbar-right">
            <template x-if="tab === 'customers'">
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="text-secondary text-sm" x-show="!cust.loading" x-text="cust.total + ' customer' + (cust.total === 1 ? '' : 's')"></span>
                    <select class="form-select form-control-sm" x-model="cust.sort" @change="loadCustomers(1)" aria-label="Sort customers">
                        <option value="name">Name A–Z</option>
                        <option value="ending">Ending first</option>
                        <option value="leases">Most on rent</option>
                    </select>
                    <div class="ff-pager" x-show="cust.total_pages > 1" x-cloak>
                        <button type="button" class="ff-pager-btn" :disabled="cust.page <= 1" @click="loadCustomers(cust.page - 1)" aria-label="Previous page">‹</button>
                        <span class="ff-pager-of" x-text="cust.page + ' / ' + cust.total_pages"></span>
                        <button type="button" class="ff-pager-btn" :disabled="cust.page >= cust.total_pages" @click="loadCustomers(cust.page + 1)" aria-label="Next page">›</button>
                    </div>
                </div>
            </template>
            <template x-if="tab === 'cards'">
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="text-secondary text-sm" x-show="!cards.loading" x-text="cards.total + ' card' + (cards.total === 1 ? '' : 's')"></span>
                    <select class="form-select form-control-sm" x-model="cards.sort" @change="loadCards(1)" aria-label="Sort cards">
                        <option value="updated_at">Recently changed</option>
                        <option value="customer_name">Customer A–Z</option>
                        <option value="name">Card name A–Z</option>
                        <option value="effective_from">Newest start</option>
                        <option value="effective_to">Ending first</option>
                    </select>
                    <div class="ff-pager" x-show="cards.total_pages > 1" x-cloak>
                        <button type="button" class="ff-pager-btn" :disabled="cards.page <= 1" @click="loadCards(cards.page - 1)" aria-label="Previous page">‹</button>
                        <span class="ff-pager-of" x-text="cards.page + ' / ' + cards.total_pages"></span>
                        <button type="button" class="ff-pager-btn" :disabled="cards.page >= cards.total_pages" @click="loadCards(cards.page + 1)" aria-label="Next page">›</button>
                    </div>
                </div>
            </template>
            <template x-if="tab === 'standard'">
                <div style="display:flex;gap:8px;align-items:center;">
                    <a class="btn btn-secondary btn-sm" href="<?= base_url('api/v1/rate_cards/sheet_pdf') ?>?standard=1" target="_blank" rel="noopener">Standard price sheet (PDF)</a>
                </div>
            </template>
        </div>
    </div>

    <!-- ══ CUSTOMER PRICES ═══════════════════════════════════════════ -->
    <div class="card" x-show="tab === 'customers'">
        <template x-if="cust.loading">
            <div aria-busy="true"><template x-for="n in 5" :key="n"><div class="skeleton skeleton-row"></div></template></div>
        </template>
        <template x-if="!cust.loading && cust.rows.length === 0">
            <div class="empty-state">
                <p class="empty-state-title" x-text="cust.q || cust.equipment || cust.status !== 'in_force' ? 'No customers match these filters' : 'No customer has their own prices yet'"></p>
                <p class="empty-state-text" x-show="!cust.q && !cust.equipment">A customer rate card sets negotiated prices for one customer; everyone else pays the standard price.</p>
                <?php if ($canCreate): ?>
                <a class="btn btn-primary btn-sm" href="<?= base_url('rates/create') ?>" x-show="!cust.q && !cust.equipment">+ New rate card</a>
                <?php endif; ?>
            </div>
        </template>
        <template x-if="!cust.loading && cust.rows.length > 0">
            <div style="overflow-x:auto;">
                <table class="table rt-cust-table" aria-label="Customer prices">
                    <thead>
                        <tr>
                            <th scope="col">Customer</th>
                            <th scope="col">Their prices</th>
                            <th scope="col">Cards</th>
                            <th scope="col" class="rt-hide-sm">In force until</th>
                            <th scope="col" class="rt-hide-sm" title="Active leases">On rent</th>
                            <th scope="col" style="width:28px;"></th>
                        </tr>
                    </thead>
                    <template x-for="row in cust.rows" :key="row.customer_id">
                        <tbody :class="{ 'is-open': cust.open[row.customer_id] }">
                            <tr class="rt-cust-row" @click="toggleCustomer(row)" @keydown.enter="toggleCustomer(row)" tabindex="0">
                                <td style="max-width:280px;">
                                    <div class="rt-cust-name">
                                        <span class="rt-av" x-text="FF_Rates.initials(row.customer_name)"></span>
                                        <span style="min-width:0;">
                                            <b x-text="row.customer_name"></b>
                                            <small x-text="row.lines.length + ' price line' + (row.lines.length === 1 ? '' : 's')"></small>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="rt-chips">
                                        <template x-for="l in row.lines.slice(0, 3)" :key="l.card_id + l.key">
                                            <span class="rt-chip" :class="{ 'rt-chip--upcoming': l.card_status === 'upcoming', 'rt-chip--expired': l.card_status === 'expired', 'rt-chip--issue': l.issues.length }"
                                                  :title="l.label + ' — ' + l.scope + ' · ' + l.card_name + ' (' + FF_Rates.statusLabel(l.card_status) + ')'">
                                                <b x-text="l.label"></b>
                                                <span class="rt-num" x-text="FF_Rates.chipPrices(l)"></span>
                                            </span>
                                        </template>
                                        <span class="rt-chip" x-show="row.lines.length > 3" x-text="'+' + (row.lines.length - 3) + ' more'"></span>
                                    </div>
                                </td>
                                <td class="rt-nowrap">
                                    <span class="rt-status" :class="'rt-status--' + row.summary.status" x-text="FF_Rates.statusLabel(row.summary.status)"></span>
                                    <span class="rt-faint text-sm" style="margin-left:6px;" x-text="row.cards.length + (row.cards.length === 1 ? ' card' : ' cards')"></span>
                                </td>
                                <td class="rt-hide-sm rt-nowrap">
                                    <span x-show="row.summary.next_end" x-text="FF_Rates.date(row.summary.next_end)"></span>
                                    <span class="rt-faint" x-show="!row.summary.next_end && row.summary.in_force_cards">Open-ended</span>
                                    <span class="rt-faint" x-show="!row.summary.in_force_cards">—</span>
                                </td>
                                <td class="rt-hide-sm rt-num" x-text="row.active_leases || '—'"></td>
                                <td><span class="rt-caret" aria-hidden="true">›</span></td>
                            </tr>
                            <tr class="rt-cust-detail" x-show="cust.open[row.customer_id]" x-cloak>
                                <td colspan="6">
                                    <div class="rt-cust-detail-inner">
                                        <div class="rt-cust-actions">
                                            <?php if ($canCustomers): ?>
                                            <a class="btn btn-ghost btn-sm" :href="baseUrl + 'customers/show?id=' + row.customer_id + '#rates'">Customer profile</a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-ghost btn-sm" @click="checkFor(row.customer_id, row.customer_name)">Price check</button>
                                            <a class="btn btn-ghost btn-sm" :href="baseUrl + 'api/v1/rate_cards/sheet_pdf?customer_id=' + row.customer_id" target="_blank" rel="noopener">Rate sheet (PDF)</a>
                                            <span class="rt-spacer"></span>
                                            <?php if ($canCreate): ?>
                                            <a class="btn btn-secondary btn-sm" :href="baseUrl + 'rates/create?customer_id=' + row.customer_id">+ New card for them</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="rt-grid-wrap">
                                            <table class="rt-grid">
                                                <thead>
                                                    <tr>
                                                        <th>Equipment</th>
                                                        <th class="num">Daily</th>
                                                        <th class="num">Weekly</th>
                                                        <th class="num">Monthly</th>
                                                        <th class="num">Distance</th>
                                                        <th class="num">GPS / day</th>
                                                        <th class="num">Min. days</th>
                                                        <th>Card</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="l in row.lines" :key="l.card_id + l.key">
                                                        <tr :class="{ 'is-upcoming': l.card_status === 'upcoming', 'is-expired': l.card_status === 'expired' }">
                                                            <td class="rt-eq">
                                                                <b x-text="l.label"></b>
                                                                <small x-text="l.scope"></small>
                                                                <template x-for="iss in l.issues" :key="iss"><span class="rt-issue" x-text="iss"></span></template>
                                                            </td>
                                                            <td class="num">
                                                                <span class="rt-num" x-text="FF_Rates.money(l.daily_rate)"></span>
                                                                <template x-if="FF_Rates.vs(l.daily_rate, l.standard.daily)">
                                                                    <span class="rt-vs rt-sub" :class="FF_Rates.vs(l.daily_rate, l.standard.daily).cls" x-text="FF_Rates.vs(l.daily_rate, l.standard.daily).text"></span>
                                                                </template>
                                                            </td>
                                                            <td class="num"><span class="rt-num" x-text="FF_Rates.money(l.weekly_rate)"></span></td>
                                                            <td class="num">
                                                                <span class="rt-num" x-text="FF_Rates.money(l.monthly_rate)"></span>
                                                                <template x-if="FF_Rates.vs(l.monthly_rate, l.standard.monthly)">
                                                                    <span class="rt-vs rt-sub" :class="FF_Rates.vs(l.monthly_rate, l.standard.monthly).cls" x-text="FF_Rates.vs(l.monthly_rate, l.standard.monthly).text"></span>
                                                                </template>
                                                                <template x-if="l.standard.range && l.standard.range.monthly">
                                                                    <span class="rt-sub" x-text="'standard ' + FF_Rates.short(l.standard.range.monthly[0]) + '–' + FF_Rates.short(l.standard.range.monthly[1])"></span>
                                                                </template>
                                                            </td>
                                                            <td class="num rt-nowrap">
                                                                <span class="rt-num" x-text="FF_Rates.money(l.mileage_rate, 4)"></span>
                                                                <span class="rt-sub" x-show="Number(l.mileage_rate) > 0" x-text="'per ' + (l.mileage_unit === 'miles' ? 'mile' : 'km')"></span>
                                                                <span class="rt-sub" x-show="Number(l.hourly_rate) > 0" x-text="FF_Rates.money(l.hourly_rate, 4) + ' / engine hr'"></span>
                                                            </td>
                                                            <td class="num"><span class="rt-num" x-text="FF_Rates.money(l.gps_price)"></span></td>
                                                            <td class="num"><span class="rt-num" x-text="l.minimum_days !== null && l.minimum_days !== '' ? l.minimum_days : '—'"></span></td>
                                                            <td style="min-width:180px;">
                                                                <a class="link" :href="baseUrl + 'rates/show?id=' + l.card_id" x-text="l.card_name"></a>
                                                                <span class="rt-sub">
                                                                    <span class="rt-status" :class="'rt-status--' + l.card_status" x-text="FF_Rates.statusLabel(l.card_status)"></span>
                                                                    <span x-text="' ' + FF_Rates.window(l.card_from, l.card_to)"></span>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </template>
                </table>
            </div>
        </template>
    </div>

    <!-- ══ STANDARD PRICES ═══════════════════════════════════════════ -->
    <div class="card" x-show="tab === 'standard'" x-cloak>
        <div class="rt-general">
            <span class="rt-muted text-sm" style="font-weight:600;">Standard price lists</span>
            <template x-for="g in (book.general_cards || [])" :key="g.id">
                <a class="rt-gcard" :href="baseUrl + 'rates/show?id=' + g.id">
                    <span x-text="g.name"></span>
                    <span class="rt-status" :class="'rt-status--' + g.status" x-text="FF_Rates.statusLabel(g.status)"></span>
                    <span class="badge badge-info" x-show="g.is_default">Main list</span>
                </a>
            </template>
            <span class="rt-faint text-sm" x-show="book.loaded && !(book.general_cards || []).length">None — equipment types use their own default prices.</span>
            <span style="flex:1"></span>
            <?php if ($canCreate): ?>
            <a class="btn btn-ghost btn-sm" href="<?= base_url('rates/create') ?>?for=everyone">+ New standard price list</a>
            <?php endif; ?>
        </div>
        <template x-if="book.loading">
            <div aria-busy="true"><template x-for="n in 5" :key="n"><div class="skeleton skeleton-row"></div></template></div>
        </template>
        <div class="alert alert-danger" x-show="book.error" x-text="book.error" style="margin:12px 16px;" x-cloak></div>
        <template x-if="!book.loading && book.loaded">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Standard prices by equipment type">
                    <thead>
                        <tr>
                            <th scope="col">Equipment type</th>
                            <th scope="col" class="num">Daily</th>
                            <th scope="col" class="num">Weekly</th>
                            <th scope="col" class="num">Monthly</th>
                            <th scope="col" class="num">Distance</th>
                            <th scope="col">Price comes from</th>
                            <th scope="col">Customer deals</th>
                            <th scope="col" class="num rt-hide-sm">On rent</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <template x-for="g in book.groups" :key="g.slug">
                        <tbody>
                            <tr class="rt-group-row"><td colspan="9" x-text="g.label"></td></tr>
                            <template x-for="t in g.types" :key="t.id">
                                <tr>
                                    <td style="min-width:190px;">
                                        <b x-text="t.name"></b>
                                        <span class="badge badge-neutral" style="margin-left:6px;" x-show="!t.is_active">Inactive</span>
                                    </td>
                                    <template x-if="book.editing !== t.id">
                                        <td class="num"><span class="rt-num" x-text="FF_Rates.money(t.standard.daily_rate)"></span></td>
                                    </template>
                                    <template x-if="book.editing !== t.id">
                                        <td class="num"><span class="rt-num" x-text="FF_Rates.money(t.standard.weekly_rate)"></span></td>
                                    </template>
                                    <template x-if="book.editing !== t.id">
                                        <td class="num"><span class="rt-num" x-text="FF_Rates.money(t.standard.monthly_rate)"></span></td>
                                    </template>
                                    <template x-if="book.editing !== t.id">
                                        <td class="num rt-nowrap">
                                            <span class="rt-num" x-text="FF_Rates.money(t.standard.mileage_rate, 4)"></span>
                                            <span class="rt-sub" x-show="Number(t.standard.mileage_rate) > 0" x-text="'per ' + (t.standard.mileage_unit === 'miles' ? 'mile' : 'km')"></span>
                                        </td>
                                    </template>
                                    <template x-if="book.editing === t.id">
                                        <td class="num"><div class="rt-money"><span>$</span><input class="form-control form-control-sm" type="number" min="0" step="0.01" x-model="book.form.default_daily_rate" aria-label="Default daily price"></div></td>
                                    </template>
                                    <template x-if="book.editing === t.id">
                                        <td class="num"><div class="rt-money"><span>$</span><input class="form-control form-control-sm" type="number" min="0" step="0.01" x-model="book.form.default_weekly_rate" aria-label="Default weekly price"></div></td>
                                    </template>
                                    <template x-if="book.editing === t.id">
                                        <td class="num"><div class="rt-money"><span>$</span><input class="form-control form-control-sm" type="number" min="0" step="0.01" x-model="book.form.default_monthly_rate" aria-label="Default monthly price"></div></td>
                                    </template>
                                    <template x-if="book.editing === t.id">
                                        <td class="num">
                                            <div style="display:flex;gap:4px;align-items:center;justify-content:flex-end;">
                                                <div class="rt-money" style="min-width:80px;"><span>$</span><input class="form-control form-control-sm" type="number" min="0" step="0.0001" x-model="book.form.default_mileage_rate" aria-label="Default distance price"></div>
                                                <select class="form-select rt-mini-select" x-model="book.form.default_mileage_unit" aria-label="Distance unit"><option value="km">/ km</option><option value="miles">/ mi</option></select>
                                            </div>
                                        </td>
                                    </template>
                                    <td style="min-width:170px;">
                                        <template x-if="t.standard.source === 'rate_card'">
                                            <span>
                                                <span class="rt-muted text-sm">General card</span>
                                                <a class="link" style="display:block;" :href="baseUrl + 'rates/show?id=' + t.standard.rate_card_id" x-text="cardNameOf(t.standard)"></a>
                                                <span class="rt-issue" x-show="!Number(t.standard.daily_rate) && !Number(t.standard.monthly_rate) && !Number(t.standard.hourly_rate) && !Number(t.standard.mileage_rate)">No prices on it — leases start at $0</span>
                                            </span>
                                        </template>
                                        <template x-if="t.standard.source === 'template'">
                                            <span class="rt-muted">Equipment type's own prices</span>
                                        </template>
                                        <template x-if="t.standard.source === 'none'">
                                            <span class="rt-issue" style="margin:0;">Not priced</span>
                                        </template>
                                    </td>
                                    <td class="rt-nowrap">
                                        <span x-show="t.deals.customers" x-text="t.deals.customers + ' customer' + (t.deals.customers === 1 ? '' : 's')"></span>
                                        <span class="rt-sub" x-show="t.deals.daily" x-text="t.deals.daily ? FF_Rates.short(t.deals.daily[0]) + (t.deals.daily[0] !== t.deals.daily[1] ? '–' + FF_Rates.short(t.deals.daily[1]) : '') + ' / day' : ''"></span>
                                        <span class="rt-faint" x-show="!t.deals.customers">—</span>
                                    </td>
                                    <td class="num rt-hide-sm rt-nowrap"><span class="rt-num" x-text="t.on_rent + ' / ' + t.units"></span></td>
                                    <td class="rt-nowrap" style="text-align:right;">
                                        <template x-if="book.editing === t.id">
                                            <span style="display:inline-flex;gap:6px;">
                                                <button type="button" class="btn btn-ghost btn-sm" @click="book.editing = null; book.error = ''">Cancel</button>
                                                <button type="button" class="btn btn-primary btn-sm" :disabled="book.saving" @click="saveTypeDefaults(t)" x-text="book.saving ? 'Saving…' : 'Save'"></button>
                                            </span>
                                        </template>
                                        <template x-if="book.editing !== t.id">
                                            <span style="display:inline-flex;gap:6px;">
                                                <button type="button" class="btn btn-ghost btn-sm" @click="checkForType(t.id)">Check</button>
                                                <?php if ($canEquipEdit): ?>
                                                <button type="button" class="btn btn-secondary btn-sm" @click="editTypeDefaults(t)"
                                                        :title="t.standard.source === 'rate_card' ? 'A general card sets this price — its own defaults apply only when no general card covers it' : 'Edit this equipment type\'s default prices'">Edit</button>
                                                <?php endif; ?>
                                            </span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </template>
                </table>
                <p class="rt-faint text-sm" style="margin:10px 16px 14px;">
                    A customer without a card of their own pays these prices. A general rate card line wins over the equipment type's own prices; a customer's card wins over both.
                    <?php if ($canEquipEdit): ?>Editing here changes the equipment type's default prices (also on Equipment → Equipment Types).<?php endif; ?>
                </p>
            </div>
        </template>
    </div>

    <!-- ══ ALL RATE CARDS ════════════════════════════════════════════ -->
    <div class="card" x-show="tab === 'cards'" x-cloak>
        <template x-if="cards.loading">
            <div aria-busy="true"><template x-for="n in 6" :key="n"><div class="skeleton skeleton-row"></div></template></div>
        </template>

        <!-- Bulk bar -->
        <div x-show="cards.selected.length > 0" x-cloak class="ff-bulk-bar"
             x-transition:enter="ff-bulk-enter" x-transition:enter-start="ff-bulk-enter-from" x-transition:enter-end="ff-bulk-enter-to">
            <span class="ff-bulk-bar-count" x-text="cards.selected.length + ' selected'"></span>
            <div class="ff-bulk-bar-sep"></div>
            <?php if ($canCreate && $canEdit): ?>
            <button class="ff-bulk-btn" style="background:var(--color-primary-light);color:var(--color-primary-text, var(--color-primary));" @click="openBulk(cards.selected)">
                Change prices…
            </button>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <button class="ff-bulk-btn ff-bulk-btn-delete" @click="confirmBulkDelete()">Delete</button>
            <?php endif; ?>
            <button class="ff-bulk-btn ff-bulk-btn-clear" @click="cards.selected = []" title="Clear selection" aria-label="Clear selection">✕</button>
        </div>

        <template x-if="!cards.loading && cards.rows.length === 0">
            <div class="empty-state">
                <p class="empty-state-title">No rate cards match</p>
                <p class="empty-state-text">Try another status or clear the search.</p>
            </div>
        </template>
        <template x-if="!cards.loading && cards.rows.length > 0">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Rate cards">
                    <thead>
                        <tr>
                            <?php if (($canCreate && $canEdit) || $canDelete): ?>
                            <th class="th-checkbox"><input type="checkbox" class="ff-checkbox" :checked="allCardsSelected()" @change="toggleAllCards()" title="Select all on this page"></th>
                            <?php endif; ?>
                            <th scope="col">Rate card</th>
                            <th scope="col">For</th>
                            <th scope="col">Covers</th>
                            <th scope="col">In force</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="rt-hide-sm">Changed</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="c in cards.rows" :key="c.id">
                            <tr>
                                <?php if (($canCreate && $canEdit) || $canDelete): ?>
                                <td class="th-checkbox"><input type="checkbox" class="ff-checkbox" :value="c.id" x-model.number="cards.selected" :aria-label="'Select ' + c.name"></td>
                                <?php endif; ?>
                                <td style="min-width:200px;">
                                    <a class="link" style="font-weight:600;" :href="baseUrl + 'rates/show?id=' + c.id" x-text="c.name"></a>
                                    <span class="badge badge-info" style="margin-left:6px;" x-show="Number(c.is_default)">Main list</span>
                                    <span class="rt-sub rt-faint" x-show="c.description" x-text="c.description && c.description.length > 70 ? c.description.slice(0, 70) + '…' : c.description"></span>
                                </td>
                                <td style="min-width:160px;">
                                    <template x-if="c.customer_id">
                                        <span x-text="c.customer_name || 'Archived customer'"></span>
                                    </template>
                                    <template x-if="!c.customer_id">
                                        <span class="rt-muted">Everyone</span>
                                    </template>
                                </td>
                                <td style="min-width:180px;">
                                    <div class="rt-chips">
                                        <template x-for="l in (c.lines || []).slice(0, 3)" :key="l.label">
                                            <span class="rt-chip" :class="{ 'rt-chip--issue': l.issues }" :title="l.scope"><b x-text="l.label"></b></span>
                                        </template>
                                        <span class="rt-chip" x-show="(c.lines || []).length > 3" x-text="'+' + ((c.lines || []).length - 3)"></span>
                                        <span class="rt-faint text-sm" x-show="!(c.lines || []).length">No prices</span>
                                    </div>
                                </td>
                                <td class="rt-nowrap text-sm"><span x-text="'from ' + FF_Rates.date(c.effective_from)"></span><span class="rt-sub rt-faint" x-text="c.effective_to ? 'to ' + FF_Rates.date(c.effective_to) : 'open-ended'"></span></td>
                                <td><span class="rt-status" :class="'rt-status--' + c.status" x-text="FF_Rates.statusLabel(c.status)"></span></td>
                                <td class="rt-hide-sm rt-nowrap text-sm rt-muted" x-text="FF_Rates.date(c.updated_at)"></td>
                                <td style="text-align:right;"><a class="btn btn-ghost btn-sm" :href="baseUrl + 'rates/show?id=' + c.id">Open</a></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    <!-- ══ PRICE CHECK ═══════════════════════════════════════════════ -->
    <div x-show="tab === 'check'" x-cloak>
        <div class="rt-check">
            <div>
                <div class="rt-panel">
                    <h3>What would this customer pay?</h3>
                    <div class="form-group">
                        <label class="form-label">Customer</label>
                        <?php
                        $pickerName     = 'rtCheckCustomer';
                        $pickerConfig   = [
                            'endpoint'    => '/api/v1/customers/index.php',
                            'searchParam' => 'search',
                            'resultKey'   => 'items',
                            'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: (r.city ?? '') + (r.province ? ', ' + r.province : '') })",
                            'placeholder' => 'Any customer (standard price)…',
                            'initialId'   => $checkPre['customer_id'],
                            'initialLabel'=> (string) $checkPre['customer_name'],
                        ];
                        $pickerOnPicked  = 'check.customer_id = $event.detail.id; check.customer_name = $event.detail.label; runCheck()';
                        $pickerOnCleared = 'check.customer_id = null; check.customer_name = null; runCheck()';
                        $pickerError     = 'false';
                        require FF_ROOT . '/includes/partials/record-picker.php';
                        ?>
                        <div class="form-hint" x-show="check.customer_name" x-text="'Checking ' + check.customer_name"></div>
                        <div class="form-hint" x-show="!check.customer_id">Leave empty to see the standard price.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="rt-check-type">Equipment type</label>
                        <select id="rt-check-type" class="form-select" x-model="check.template_id" @change="runCheck()">
                            <option value="">Choose…</option>
                            <?php $grp = null; foreach ($templateOptions as $t): if ($t['group'] !== $grp): if ($grp !== null) echo '</optgroup>'; $grp = $t['group']; echo '<optgroup label="' . e($grp) . '">'; endif; ?>
                            <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?><?= $t['active'] ? '' : ' (inactive)' ?></option>
                            <?php endforeach; if ($grp !== null) echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="rt-check-date">Price on</label>
                        <input id="rt-check-date" type="date" class="form-control" x-model="check.date" @change="runCheck()">
                        <div class="form-hint">A new lease takes the prices in force on the day it is created.</div>
                    </div>
                </div>
                <div class="rt-panel">
                    <h3 style="display:flex;align-items:center;gap:8px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
                            <input type="checkbox" class="form-check-input" x-model="check.quoteOn" @change="runCheck()">
                            Estimate a rental
                        </label>
                    </h3>
                    <template x-if="check.quoteOn">
                        <div>
                            <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                                <div class="form-group"><label class="form-label">Goes out</label><input type="date" class="form-control" x-model="check.start" @change="runCheck()"></div>
                                <div class="form-group"><label class="form-label">Comes back</label><input type="date" class="form-control" x-model="check.end" :min="check.start" @change="runCheck()"></div>
                            </div>
                            <div class="rt-quick" style="margin:-4px 0 12px;">
                                <template x-for="d in [3, 7, 14, 30, 90]" :key="d">
                                    <button type="button" @click="check.end = FF_Rates.ymd(d - 1, check.start); runCheck()" x-text="d + ' days'"></button>
                                </template>
                            </div>
                            <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                                <div class="form-group"><label class="form-label">Distance / day</label><input type="number" min="0" step="1" class="form-control" placeholder="0" x-model="check.distance" @input.debounce.400ms="runCheck()"></div>
                                <div class="form-group"><label class="form-label">Engine hours / day</label><input type="number" min="0" step="0.5" class="form-control" placeholder="0" x-model="check.hours" @input.debounce.400ms="runCheck()"></div>
                            </div>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                                <input type="checkbox" class="form-check-input" x-model="check.gps" @change="runCheck()"> Include GPS tracking
                            </label>
                        </div>
                    </template>
                    <p class="rt-faint text-sm" x-show="!check.quoteOn" style="margin:0;">Pick dates to see what the rental would bill, using the same rules as invoicing.</p>
                </div>
            </div>

            <div>
                <template x-if="!check.result && !check.loading">
                    <div class="rt-panel">
                        <div class="empty-state" style="padding:36px 12px;">
                            <p class="empty-state-title">Choose an equipment type</p>
                            <p class="empty-state-text">You'll see the exact price a new lease would pre-fill, where it comes from, and how it compares to the standard price.</p>
                        </div>
                    </div>
                </template>
                <div class="alert alert-danger" x-show="check.error" x-text="check.error" x-cloak></div>
                <template x-if="check.loading && !check.result">
                    <div class="rt-panel"><div class="skeleton skeleton-row"></div><div class="skeleton skeleton-row"></div></div>
                </template>
                <template x-if="check.result">
                    <div :style="check.loading ? 'opacity:.6' : ''">
                        <div class="rt-panel">
                            <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                                <h3 style="margin:0;font-size:15px;" x-text="(check.result.customer ? check.result.customer.name + ' pays' : 'Standard price') + ' for ' + check.result.template.name"></h3>
                                <span class="rt-faint text-sm" x-text="'on ' + FF_Rates.date(check.result.date)"></span>
                            </div>
                            <div class="rt-source" :class="sourceTone(check.result.price.source)">
                                <template x-if="check.result.price.source === 'customer'">
                                    <span>Their own card <a class="link" :href="baseUrl + 'rates/show?id=' + check.result.price.rate_card_id"><b x-text="check.result.price.card_name"></b></a>
                                        <span class="rt-muted" x-text="check.result.price.line_scope === 'type' ? '· line for this exact type' : '· ' + check.result.template.category_label + ' line (every type)'"></span></span>
                                </template>
                                <template x-if="check.result.price.source === 'rate_card'">
                                    <span>General card <a class="link" :href="baseUrl + 'rates/show?id=' + check.result.price.rate_card_id"><b x-text="check.result.price.card_name"></b></a>
                                        <span class="rt-muted" x-text="check.result.customer ? '· they have no line of their own for this' : ''"></span></span>
                                </template>
                                <template x-if="check.result.price.source === 'template'">
                                    <span>The equipment type's own default prices <span class="rt-muted" x-text="check.result.customer ? '· no card covers it for this customer' : '· no general card covers it'"></span></span>
                                </template>
                                <template x-if="check.result.price.source === 'none'">
                                    <span><b>No price set</b> — a new lease would start with empty prices.</span>
                                </template>
                            </div>
                            <dl class="rt-check-price" style="margin-top:12px;">
                                <template x-for="f in [['daily_rate','Daily',2,'daily'],['weekly_rate','Weekly',2,'weekly'],['monthly_rate','Monthly',2,'monthly']]" :key="f[0]">
                                    <div>
                                        <dt x-text="f[1]"></dt>
                                        <dd x-text="FF_Rates.money(check.result.price[f[0]], f[2])"></dd>
                                        <template x-if="check.result.price.source === 'customer' && FF_Rates.vs(check.result.price[f[0]], check.result.standard[f[0]])">
                                            <span class="rt-vs" :class="FF_Rates.vs(check.result.price[f[0]], check.result.standard[f[0]]).cls" x-text="FF_Rates.vs(check.result.price[f[0]], check.result.standard[f[0]]).text"></span>
                                        </template>
                                    </div>
                                </template>
                                <div>
                                    <dt>Distance</dt>
                                    <dd><span x-text="FF_Rates.money(check.result.price.mileage_rate, 4)"></span><small x-show="Number(check.result.price.mileage_rate) > 0" x-text="'/' + (check.result.price.mileage_unit === 'miles' ? 'mi' : 'km')"></small></dd>
                                </div>
                                <div>
                                    <dt>GPS / day</dt>
                                    <dd x-text="FF_Rates.money(check.result.price.gps_price)"></dd>
                                </div>
                                <div>
                                    <dt>Hourly · min. days</dt>
                                    <dd><span x-text="FF_Rates.money(check.result.price.hourly_rate, 4)"></span><small x-text="' · ' + (check.result.price.minimum_days !== null && check.result.price.minimum_days !== undefined ? check.result.price.minimum_days + ' d' : '—')"></small></dd>
                                </div>
                            </dl>
                            <template x-if="check.result.customer && check.result.price.source === 'customer'">
                                <p class="rt-faint text-sm" style="margin:0;">
                                    Standard price: <span class="rt-num" x-text="FF_Rates.money(check.result.standard.daily_rate) + ' / ' + FF_Rates.money(check.result.standard.weekly_rate) + ' / ' + FF_Rates.money(check.result.standard.monthly_rate)"></span> (day / week / month)
                                </p>
                            </template>
                        </div>

                        <template x-if="check.result.quote">
                            <div class="rt-panel">
                                <h3 x-text="'Estimate · ' + check.result.quote.days + ' day' + (check.result.quote.days === 1 ? '' : 's') + ' (' + FF_Rates.date(check.result.quote.start) + ' – ' + FF_Rates.date(check.result.quote.end) + ')'"></h3>
                                <table class="rt-quote">
                                    <template x-for="ln in check.result.quote.lines" :key="ln.key">
                                        <tr>
                                            <td><span x-text="ln.label"></span><template x-for="d in ln.detail" :key="d"><small x-text="d"></small></template></td>
                                            <td class="num" x-text="FF_Rates.money(ln.amount, 2, false)"></td>
                                        </tr>
                                    </template>
                                    <tr class="rt-total">
                                        <td>Total before tax <span class="rt-faint text-sm" x-text="'· ' + check.result.quote.currency"></span></td>
                                        <td class="num" x-text="FF_Rates.money(check.result.quote.total, 2, false)"></td>
                                    </tr>
                                </table>
                                <p class="rt-faint text-sm" style="margin:8px 0 0;">
                                    <span x-text="'About ' + FF_Rates.money(check.result.quote.per_day, 2, false) + ' a day. '"></span>
                                    <span x-show="check.result.quote.minimum_days >= 2" x-text="'A ' + check.result.quote.minimum_days + '-day minimum applies to this equipment. '"></span>
                                    Worked out with the same rules invoicing uses.
                                </p>
                                <template x-for="w in check.result.quote.warnings" :key="w"><div class="alert alert-warning" style="margin-top:8px;" x-text="w"></div></template>
                            </div>
                        </template>

                        <div class="rt-panel">
                            <h3>How this price was chosen</h3>
                            <ol class="rt-tiers">
                                <template x-for="t in check.result.tiers" :key="t.key">
                                    <li :class="{ 'is-used': t.state === 'used', 'is-skipped': t.state === 'skipped' || t.state === 'none' }">
                                        <span class="rt-tier-dot" aria-hidden="true"></span>
                                        <div><div class="rt-tier-label" x-text="t.label"></div><div class="rt-tier-detail" x-text="t.detail"></div></div>
                                        <span class="rt-status" :class="t.state === 'used' ? 'rt-status--active' : (t.state === 'available' ? 'rt-status--upcoming' : 'rt-status--expired')"
                                              x-text="{ used: 'Used', available: 'Not needed', none: 'Nothing here', skipped: 'Skipped' }[t.state]"></span>
                                    </li>
                                </template>
                            </ol>
                            <template x-if="check.result.candidates.length > 1">
                                <div class="rt-cands">
                                    <div class="rt-faint text-sm">Every card line that could have priced it, best first:</div>
                                    <template x-for="c in check.result.candidates" :key="c.item_id">
                                        <div class="rt-cand" :class="{ 'is-winner': c.rank === 1 }">
                                            <span><a class="link" :href="baseUrl + 'rates/show?id=' + c.rate_card_id" x-text="c.card_name"></a>
                                                <span class="rt-muted" x-text="(c.is_customer ? ' · their card' : ' · general') + (c.line_scope === 'type' ? ' · this type' : ' · whole category')"></span></span>
                                            <span class="rt-num" x-text="FF_Rates.money(c.prices.daily_rate) + '/day'"></span>
                                            <small x-text="c.why"></small>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ══ Change prices (several cards) ═════════════════════════════ -->
    <div class="modal-backdrop" x-show="bulk.open" x-cloak @keydown.escape.window="bulk.open && !bulk.working && (bulk.open = false)">
        <div class="modal modal-full" role="dialog" aria-modal="true" aria-labelledby="rt-bulk-title">
            <div class="modal-header">
                <h3 class="modal-title" id="rt-bulk-title" x-text="bulk.step === 'done' ? 'Prices changed' : 'Change prices on ' + bulk.ids.length + ' card' + (bulk.ids.length === 1 ? '' : 's')"></h3>
                <button class="modal-close-btn" aria-label="Close" @click="bulk.open = false" :disabled="bulk.working">×</button>
            </div>
            <div class="modal-body" style="overflow:auto;">
                <template x-if="bulk.step === 'setup'">
                    <div>
                        <p class="rt-muted" style="margin-top:0;">Each card keeps its current prices until the day before the new ones start; a new card carries the new prices from that date. Old prices stay on record, and leases already on rent keep the prices they have.</p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                            <div class="form-group">
                                <label class="form-label">New prices start</label>
                                <input type="date" class="form-control" x-model="bulk.from">
                                <div class="rt-quick">
                                    <button type="button" @click="bulk.from = FF_Rates.nextMonthStart()">1st of next month</button>
                                    <button type="button" @click="bulk.from = FF_Rates.ymd(1)">Tomorrow</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Change by</label>
                                <div class="rt-money rt-money--pct"><span>%</span><input type="number" class="form-control" step="0.1" x-model="bulk.percent" placeholder="e.g. 5 or -3"></div>
                                <div class="form-hint">Raises (or lowers) daily, weekly and monthly prices.</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Round new prices to</label>
                                <div class="rt-seg" role="group" aria-label="Rounding">
                                    <button type="button" :class="{ 'is-on': bulk.round === '0.01' }" @click="bulk.round = '0.01'">Cent</button>
                                    <button type="button" :class="{ 'is-on': bulk.round === '1' }" @click="bulk.round = '1'">$1</button>
                                    <button type="button" :class="{ 'is-on': bulk.round === '5' }" @click="bulk.round = '5'">$5</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">New cards end</label>
                                <select class="form-select" x-model="bulk.end">
                                    <option value="keep">Same end date as before</option>
                                    <option value="open">Open-ended</option>
                                    <option value="date">On a date…</option>
                                </select>
                                <input type="date" class="form-control" style="margin-top:6px;" x-show="bulk.end === 'date'" x-model="bulk.end_date" :min="bulk.from">
                            </div>
                        </div>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin:4px 0 10px;">
                            <input type="checkbox" class="form-check-input" x-model="bulk.all_prices"> Also change distance, hourly and GPS prices
                        </label>
                        <div class="form-group">
                            <label class="form-label">Note for the history <span class="rt-faint">(optional)</span></label>
                            <input type="text" class="form-control" maxlength="500" x-model="bulk.note" placeholder="e.g. 2027 price increase">
                        </div>
                    </div>
                </template>
                <template x-if="bulk.step !== 'setup' && bulk.preview">
                    <div>
                        <div class="alert" :class="bulk.preview.ok ? 'alert-info' : 'alert-danger'" style="margin-top:0;">
                            <span x-show="bulk.step === 'preview' && bulk.preview.ok" x-text="'Ready: ' + bulk.preview.results.length + ' card' + (bulk.preview.results.length === 1 ? '' : 's') + ' will change from ' + FF_Rates.date(bulk.from) + '. Nothing has been saved yet.'"></span>
                            <span x-show="!bulk.preview.ok" x-text="refusedCount() + ' card' + (refusedCount() === 1 ? ' cannot' : 's cannot') + ' change as asked (reasons below). Nothing will be saved until every card can — untick them and preview again.'"></span>
                            <span x-show="bulk.step === 'done'">Saved. Each card below links to its new version.</span>
                        </div>
                        <div class="rt-preview">
                            <table class="rt-grid">
                                <thead>
                                    <tr><th>Card</th><th>Old prices end</th><th>Equipment</th><th class="num">Daily</th><th class="num">Weekly</th><th class="num">Monthly</th><th>New card</th></tr>
                                </thead>
                                <tbody>
                                    <template x-for="row in previewRows()" :key="row.k">
                                        <tr>
                                            <td>
                                                <template x-if="row.li === 0">
                                                    <div>
                                                        <b x-text="row.r.name"></b>
                                                        <span class="rt-sub" x-text="row.r.customer_name || 'Everyone'"></span>
                                                        <span class="rt-issue" x-show="row.r.status === 'error'" x-text="row.r.error"></span>
                                                    </div>
                                                </template>
                                            </td>
                                            <td class="rt-nowrap text-sm"><span x-show="row.li === 0 && row.r.old_to_new" x-text="FF_Rates.date(row.r.old_to_new)"></span></td>
                                            <td><template x-if="row.ln"><span><span x-text="row.ln.label"></span><span class="rt-sub" x-text="row.ln.scope"></span></span></template></td>
                                            <td class="num"><template x-if="row.ln"><span><span class="rt-was" x-show="row.ln.before.daily_rate !== row.ln.after.daily_rate" x-text="FF_Rates.money(row.ln.before.daily_rate)"></span><span class="rt-num" x-text="FF_Rates.money(row.ln.after.daily_rate)"></span></span></template></td>
                                            <td class="num"><template x-if="row.ln"><span><span class="rt-was" x-show="row.ln.before.weekly_rate !== row.ln.after.weekly_rate" x-text="FF_Rates.money(row.ln.before.weekly_rate)"></span><span class="rt-num" x-text="FF_Rates.money(row.ln.after.weekly_rate)"></span></span></template></td>
                                            <td class="num"><template x-if="row.ln"><span><span class="rt-was" x-show="row.ln.before.monthly_rate !== row.ln.after.monthly_rate" x-text="FF_Rates.money(row.ln.before.monthly_rate)"></span><span class="rt-num" x-text="FF_Rates.money(row.ln.after.monthly_rate)"></span></span></template></td>
                                            <td class="text-sm">
                                                <template x-if="row.li === 0 && row.r.status === 'ok'">
                                                    <span>
                                                        <template x-if="row.r.new_card_id"><a class="link" :href="baseUrl + 'rates/show?id=' + row.r.new_card_id" x-text="row.r.new_name"></a></template>
                                                        <template x-if="!row.r.new_card_id"><span x-text="row.r.new_name"></span></template>
                                                        <span class="rt-sub" x-text="FF_Rates.window(row.r.new_from, row.r.new_to)"></span>
                                                    </span>
                                                </template>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
                <div class="alert alert-danger" x-show="bulk.error" x-text="bulk.error" style="margin-top:12px;" x-cloak></div>
            </div>
            <div class="modal-footer">
                <template x-if="bulk.step === 'setup'">
                    <div style="display:flex;gap:8px;justify-content:flex-end;width:100%;">
                        <button class="btn btn-secondary btn-sm" @click="bulk.open = false">Cancel</button>
                        <button class="btn btn-primary btn-sm" :disabled="bulk.working || !bulk.from" @click="previewBulk()" x-text="bulk.working ? 'Working it out…' : 'Preview'"></button>
                    </div>
                </template>
                <template x-if="bulk.step === 'preview'">
                    <div style="display:flex;gap:8px;justify-content:flex-end;width:100%;">
                        <button class="btn btn-secondary btn-sm" @click="bulk.step = 'setup'" :disabled="bulk.working">Back</button>
                        <button class="btn btn-primary btn-sm" :disabled="bulk.working || !bulk.preview || !bulk.preview.ok" @click="applyBulk()"
                                x-text="bulk.working ? 'Saving…' : 'Change prices on ' + (bulk.preview ? bulk.preview.results.length : 0) + ' card' + (bulk.preview && bulk.preview.results.length === 1 ? '' : 's')"></button>
                    </div>
                </template>
                <template x-if="bulk.step === 'done'">
                    <div style="display:flex;gap:8px;justify-content:flex-end;width:100%;">
                        <button class="btn btn-primary btn-sm" @click="bulk.open = false">Done</button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ══ Delete (bulk) ════════════════════════════════════════════ -->
    <div class="modal-backdrop" x-show="del.open" x-cloak>
        <div class="modal modal-sm" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h3 class="modal-title" x-text="'Delete ' + del.ids.length + ' rate card' + (del.ids.length === 1 ? '' : 's') + '?'"></h3>
                <button class="modal-close-btn" aria-label="Close" @click="del.open = false">×</button>
            </div>
            <div class="modal-body">
                <p style="margin-top:0;">New leases will stop picking up these prices. Leases already on rent keep theirs.</p>
                <p class="rt-muted text-sm">The main price list cannot be deleted — it is skipped.</p>
                <p class="text-danger text-sm" x-show="del.error" x-text="del.error"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="del.open = false">Cancel</button>
                <button class="btn btn-danger btn-sm" :disabled="del.working" @click="bulkDelete()" x-text="del.working ? 'Deleting…' : 'Delete'"></button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_RatesHome() {
    const API = '<?= e(base_url('api/v1/rate_cards')) ?>/';
    return {
        baseUrl: '<?= e(base_url('')) ?>'.replace(/\/?$/, '/'),
        tab: 'customers',

        kpis: null,
        attention: null,
        attnOpen: true,
        attnAll: false,

        cust: { q: '', status: 'in_force', equipment: '', sort: 'name', page: 1, rows: [], total: 0, total_pages: 1, loading: true, open: {} },
        book: { groups: [], general_cards: [], loading: false, loaded: false, error: '', editing: null, form: {}, saving: false },
        cards: { q: '', status: '', scope: '', equipment: '', sort: 'updated_at', page: 1, rows: [], total: 0, total_pages: 1, loading: false, loaded: false, selected: [] },
        check: { customer_id: <?= json_encode($checkPre['customer_id']) ?>, customer_name: <?= json_encode($checkPre['customer_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, template_id: <?= json_encode($checkPre['template_id']) ?>, date: '<?= e(ff_today()) ?>', quoteOn: false,
                 start: '<?= e(ff_today()) ?>', end: FF_Rates.ymd(6, '<?= e(ff_today()) ?>'), gps: true, distance: '', hours: '',
                 result: null, loading: false, error: '', seq: 0 },
        bulk: { open: false, step: 'setup', ids: [], from: '', percent: '', round: '1', all_prices: false, end: 'keep', end_date: '', note: '',
                preview: null, working: false, error: '' },
        del: { open: false, ids: [], working: false, error: '' },

        init() {
            this.tab = FF_TabHash.init(['customers', 'standard', 'cards', 'check'], 'customers');
            this.loadOverview();
            this.loadCustomers(1);
            this.onTab(this.tab);
            if (this.check.customer_id || this.check.template_id) {
                if (this.tab !== 'check') this.setTab('check');
                if (this.check.template_id) this.runCheck();
            }
        },

        setTab(t) {
            this.tab = t;
            history.replaceState(null, '', location.pathname + location.search + '#' + t);
            this.onTab(t);
        },
        onTab(t) {
            if (t === 'standard' && !this.book.loaded && !this.book.loading) this.loadBook();
            if (t === 'cards' && !this.cards.loaded && !this.cards.loading) this.loadCards(1);
        },

        // ── Overview ──────────────────────────────────────────────────
        async loadOverview() {
            try {
                const r = await FF_Api.get(API + 'overview');
                if (r.success) { this.kpis = r.data.kpis; this.attention = r.data.attention; }
            } catch (e) { /* the tiles stay at — */ }
        },
        attnVisible() {
            if (!this.attention) return [];
            return this.attnAll ? this.attention.items : this.attention.items.slice(0, 5);
        },
        attnSummary() {
            const c = this.attention.counts || {};
            const bad = (c.line_issue || 0) + (c.empty || 0);
            if (bad) return bad + ' card line' + (bad === 1 ? '' : 's') + ' would misprice';
            if (c.ending) return c.ending + ' ending soon';
            return this.attention.items.length ? 'items to review' : 'all clear';
        },

        // ── Customer prices ──────────────────────────────────────────
        async loadCustomers(page) {
            this.cust.loading = true;
            this.cust.page = page;
            const p = new URLSearchParams({ page, per_page: 20, status: this.cust.status, sort: this.cust.sort });
            if (this.cust.q) p.set('q', this.cust.q);
            if (this.cust.equipment) p.set('equipment', this.cust.equipment);
            try {
                const r = await FF_Api.get(API + 'customer_pricing?' + p);
                this.cust.rows = r.data?.items ?? [];
                this.cust.total = r.data?.pagination?.total ?? 0;
                this.cust.total_pages = r.data?.pagination?.total_pages ?? 1;
            } catch (e) {
                this.cust.rows = []; this.cust.total = 0;
            } finally {
                this.cust.loading = false;
            }
        },
        toggleCustomer(row) {
            this.cust.open = Object.assign({}, this.cust.open, { [row.customer_id]: !this.cust.open[row.customer_id] });
        },

        // ── Standard prices ──────────────────────────────────────────
        async loadBook() {
            this.book.loading = true;
            this.book.error = '';
            try {
                const r = await FF_Api.get(API + 'price_book');
                if (!r.success) throw new Error(r.error?.message || 'Could not load standard prices.');
                this.book.groups = r.data.groups;
                this.book.general_cards = r.data.general_cards;
                this.book.loaded = true;
            } catch (e) {
                this.book.error = e.message;
            } finally {
                this.book.loading = false;
            }
        },
        editTypeDefaults(t) {
            this.book.error = '';
            this.book.editing = t.id;
            const d = t.defaults;
            this.book.form = {
                default_daily_rate: d.daily_rate ?? '', default_weekly_rate: d.weekly_rate ?? '', default_monthly_rate: d.monthly_rate ?? '',
                default_mileage_rate: Number(d.mileage_rate) > 0 ? d.mileage_rate : '', default_mileage_unit: d.mileage_unit || 'km',
            };
        },
        async saveTypeDefaults(t) {
            this.book.saving = true;
            this.book.error = '';
            const f = this.book.form;
            try {
                const r = await FF_Api.post('<?= e(base_url('api/v1/equipment/templates/update')) ?>', {
                    id: t.id, updated_at: t.updated_at,
                    default_daily_rate: f.default_daily_rate === '' ? null : f.default_daily_rate,
                    default_weekly_rate: f.default_weekly_rate === '' ? null : f.default_weekly_rate,
                    default_monthly_rate: f.default_monthly_rate === '' ? null : f.default_monthly_rate,
                    default_mileage_rate: f.default_mileage_rate === '' ? '0' : f.default_mileage_rate,
                    default_mileage_unit: f.default_mileage_unit,
                });
                if (!r.success) {
                    this.book.error = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Could not save.');
                    return;
                }
                this.book.editing = null;
                FF_Toast && FF_Toast.show('success', 'Saved', t.name + ' default prices updated.');
                await this.loadBook();
                this.loadOverview();
            } catch (e) {
                this.book.error = e.message || 'Could not save.';
            } finally {
                this.book.saving = false;
            }
        },
        /** The card name out of a lookup label ('… card "X"'). */
        cardNameOf(price) {
            const m = /card "(.*)"$/.exec(price.source_label || '');
            return m ? m[1] : 'Open card';
        },
        checkForType(id) {
            this.check.template_id = String(id);
            this.setTab('check');
            this.runCheck();
        },

        // ── All rate cards ───────────────────────────────────────────
        async loadCards(page) {
            this.cards.loading = true;
            this.cards.page = page;
            const dir = ['customer_name', 'name', 'effective_to'].includes(this.cards.sort) ? 'ASC' : 'DESC';
            const p = new URLSearchParams({ page, per_page: 25, sort: this.cards.sort, dir });
            ['q', 'status', 'scope', 'equipment'].forEach(k => { if (this.cards[k]) p.set(k, this.cards[k]); });
            try {
                const r = await FF_Api.get(API + 'index?' + p);
                this.cards.rows = r.data?.items ?? [];
                this.cards.total = r.data?.pagination?.total ?? 0;
                this.cards.total_pages = r.data?.pagination?.total_pages ?? 1;
                this.cards.loaded = true;
            } catch (e) {
                this.cards.rows = []; this.cards.total = 0;
            } finally {
                this.cards.loading = false;
            }
        },
        allCardsSelected() {
            return this.cards.rows.length > 0 && this.cards.rows.every(c => this.cards.selected.includes(c.id));
        },
        toggleAllCards() {
            if (this.allCardsSelected()) {
                const ids = new Set(this.cards.rows.map(c => c.id));
                this.cards.selected = this.cards.selected.filter(id => !ids.has(id));
            } else {
                this.cards.selected = Array.from(new Set([...this.cards.selected, ...this.cards.rows.map(c => c.id)]));
            }
        },

        // ── Price check ──────────────────────────────────────────────
        checkFor(customerId, name) {
            this.check.customer_id = customerId;
            this.check.customer_name = name;
            this.setTab('check');
            // Reflect the choice in the picker's own input.
            this.$nextTick(() => {
                const el = document.querySelector('[x-data*="FF_RecordPicker"]');
                const pk = el && window.Alpine ? Alpine.$data(el) : null;
                if (pk) { pk.selected = { id: customerId, label: name }; pk.query = name; }
            });
            if (this.check.template_id) this.runCheck();
        },
        sourceTone(s) {
            return { customer: 'rt-tone-success', rate_card: 'rt-tone-info', template: 'rt-tone-muted', none: 'rt-tone-danger' }[s] || '';
        },
        async runCheck() {
            if (!this.check.template_id) { this.check.result = null; return; }
            const seq = ++this.check.seq;
            this.check.loading = true;
            this.check.error = '';
            const p = new URLSearchParams({ template_id: this.check.template_id, date: this.check.date || '' });
            if (this.check.customer_id) p.set('customer_id', this.check.customer_id);
            if (this.check.quoteOn && this.check.start && this.check.end) {
                p.set('start', this.check.start); p.set('end', this.check.end);
                if (this.check.gps) p.set('gps', '1');
                if (this.check.distance) p.set('distance_per_day', this.check.distance);
                if (this.check.hours) p.set('hours_per_day', this.check.hours);
            }
            try {
                const r = await FF_Api.get(API + 'price_check?' + p);
                if (seq !== this.check.seq) return; // a newer check is in flight
                if (!r.success) {
                    this.check.error = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Could not check that price.');
                    return;
                }
                this.check.result = r.data;
            } catch (e) {
                if (seq === this.check.seq) this.check.error = 'Could not check that price.';
            } finally {
                if (seq === this.check.seq) this.check.loading = false;
            }
        },

        // ── Change prices (several) ──────────────────────────────────
        openBulk(ids) {
            this.bulk = Object.assign(this.bulk, { open: true, step: 'setup', ids: ids.slice(), from: FF_Rates.nextMonthStart(),
                preview: null, working: false, error: '' });
        },
        bulkBody(dry) {
            return {
                card_ids: this.bulk.ids, effective_from: this.bulk.from, percent: this.bulk.percent === '' ? '0' : String(this.bulk.percent),
                round: this.bulk.round, all_prices: this.bulk.all_prices ? 1 : 0,
                effective_to: this.bulk.end === 'date' ? this.bulk.end_date : this.bulk.end,
                note: this.bulk.note || null, dry_run: dry ? 1 : 0,
            };
        },
        /** One row per preview line (a refused card gets one row for its reason). */
        previewRows() {
            const out = [];
            (this.bulk.preview ? this.bulk.preview.results : []).forEach(r => {
                const lines = r.lines && r.lines.length ? r.lines : [null];
                lines.forEach((ln, li) => out.push({ k: r.card_id + '-' + li, r, ln, li }));
            });
            return out;
        },
        refusedCount() {
            return this.bulk.preview ? this.bulk.preview.results.filter(r => r.status === 'error').length : 0;
        },
        async previewBulk() {
            this.bulk.working = true;
            this.bulk.error = '';
            try {
                const r = await FF_Api.post(API + 'revise', this.bulkBody(true));
                if (!r.success) {
                    this.bulk.error = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Could not preview.');
                    return;
                }
                this.bulk.preview = r.data;
                this.bulk.step = 'preview';
            } catch (e) {
                this.bulk.error = e.message || 'Could not preview.';
            } finally {
                this.bulk.working = false;
            }
        },
        async applyBulk() {
            this.bulk.working = true;
            this.bulk.error = '';
            try {
                const r = await FF_Api.post(API + 'revise', this.bulkBody(false));
                if (!r.success) {
                    this.bulk.error = r.error?.message || 'Could not change the prices.';
                    return;
                }
                this.bulk.preview = r.data;
                if (!r.data.applied) { this.bulk.step = 'preview'; return; }
                this.bulk.step = 'done';
                this.cards.selected = [];
                this.loadCards(this.cards.page);
                this.loadCustomers(this.cust.page);
                this.loadOverview();
            } catch (e) {
                this.bulk.error = e.message || 'Could not change the prices.';
            } finally {
                this.bulk.working = false;
            }
        },

        // ── Delete (several) ─────────────────────────────────────────
        confirmBulkDelete() {
            this.del = { open: true, ids: this.cards.selected.slice(), working: false, error: '' };
        },
        async bulkDelete() {
            this.del.working = true;
            this.del.error = '';
            try {
                const r = await FF_Api.post(API + 'bulk_delete', { ids: this.del.ids });
                if (!r.success) { this.del.error = r.error?.message || 'Delete failed.'; return; }
                const skipped = r.data.skipped || 0;
                FF_Toast && FF_Toast.show(skipped ? 'warning' : 'success', r.data.actioned + ' deleted',
                    skipped ? skipped + ' skipped: ' + (r.data.errors || []).map(e => e.reason).join(' ') : '');
                this.del.open = false;
                this.cards.selected = [];
                this.loadCards(this.cards.page);
                this.loadCustomers(this.cust.page);
                this.loadOverview();
            } catch (e) {
                this.del.error = e.message || 'Delete failed.';
            } finally {
                this.del.working = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
