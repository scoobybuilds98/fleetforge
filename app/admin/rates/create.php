<?php
declare(strict_types=1);

/**
 * app/admin/rates/create.php
 *
 * New rate card — a guided page (S-RATES-MODULE).
 *
 *   1  Who is it for?     one customer (picker) or everyone (a standard price
 *                         list). Picking a customer loads what they pay today
 *                         for every equipment type, the prices on their leases,
 *                         and the cards they already have.
 *   2  Which equipment?   tick a whole category or one equipment type; each
 *                         line starts from the price that customer pays today
 *                         (or the standard price), so only what is different
 *                         needs typing — or from their lease prices in one click
 *   3  Prices             the same line editor as the card page
 *                         (includes/partials/rates-line-editor.php): adjust all
 *                         by %, fill blanks from the standard price
 *   4  When               starts (today by default) and an end date or open
 *   5  Name               suggested from the customer + equipment, editable
 *
 * The summary on the right says in plain words what will change, and a live
 * check (api/v1/rate_cards/create with dry_run=1 — every rule including the
 * one-card-per-equipment conflict guard) flags problems before Create.
 *
 * Deep links: ?customer_id=N (from a customer / lease), ?for=everyone,
 * ?template_id=N (pre-tick that equipment type — the lease form's "set up a
 * rate card" link), ?from_card=N (duplicate a card's lines, pick who for).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php,
 *           api/v1/rate_cards/{create,customer_prices}, includes/partials/record-picker.php,
 *           lib/RateCards/*, public/assets/{css/rates.css,js/rates.js}
 * @decisions D7/D16/D30/D32
 * @session  S019, S-RATES-REDESIGN, S-RATES-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\RateCards\RateCardItems;
use FleetForge\RateCards\RateInsights;

require_auth();
require_permission('rates', 'create');

$today = ff_today();

// ── Line options (whole category / one equipment type) + their standards ──
$lineOptions = [];
$standards   = [];
$slugs = array_unique(array_column(RateInsights::templates(), 'category'));
sort($slugs);
foreach ($slugs as $slug) {
    $lbl = RateCardItems::label((string) $slug);
    $lineOptions[] = ['value' => 'c:' . $slug, 'label' => $lbl, 'scope' => 'every ' . $lbl . ' type', 'category' => $slug, 'template_id' => null, 'group' => 'Whole category'];
    $standards['c:' . $slug] = RateInsights::lineStandard(['equipment_type' => $slug, 'equipment_template_id' => null], $today);
}
// Inactive equipment types stay rateable (S-AUDIT-BILLING-ENGINE-1 #19); the
// picker marks them "(inactive)".
foreach (RateInsights::templates() as $tid => $t) {
    $lineOptions[] = ['value' => 't:' . $tid, 'label' => $t['name'], 'scope' => RateCardItems::label((string) $t['category']) . ' · this type only',
                      'category' => $t['category'], 'template_id' => $tid, 'group' => 'One equipment type', 'active' => (int) $t['is_active'] === 1];
    $standards['t:' . $tid] = RateInsights::lineStandard(['equipment_type' => $t['category'], 'equipment_template_id' => $tid], $today);
}

// ── Prefill ────────────────────────────────────────────────────────────────
$pre = ['scope' => '', 'customer_id' => null, 'customer_name' => '', 'lines' => [], 'description' => '', 'from_card' => null, 'template_key' => null];

$preCustomerId = clean_int($_GET['customer_id'] ?? null);
if ($preCustomerId) {
    $c = db_row("SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$preCustomerId]);
    if ($c) {
        $pre['scope'] = 'customer';
        $pre['customer_id'] = (int) $c['id'];
        $pre['customer_name'] = $c['company_name'];
    }
}
if (($_GET['for'] ?? '') === 'everyone') {
    $pre['scope'] = 'everyone';
}
$fromCard = clean_int($_GET['from_card'] ?? null);
if ($fromCard) {
    $src = db_row("SELECT id, name, description, customer_id FROM rate_cards WHERE id = ? AND deleted_at IS NULL", [$fromCard]);
    if ($src) {
        $pre['from_card']   = ['id' => (int) $src['id'], 'name' => $src['name']];
        $pre['description'] = (string) ($src['description'] ?? '');
        $pre['lines']       = RateInsights::decorateLines(RateInsights::itemsByCard([(int) $src['id']])[(int) $src['id']] ?? [], $today);
        if ($pre['scope'] === '') {
            $pre['scope'] = $src['customer_id'] !== null ? 'customer' : 'everyone';
        }
    }
}
$preTemplate = clean_int($_GET['template_id'] ?? null);
if ($preTemplate && isset(RateInsights::templates()[$preTemplate])) {
    $pre['template_key'] = 't:' . $preTemplate;
}

$pageTitle      = 'New rate card';
$helpModuleSlug = 'rates';
require_once FF_ROOT . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/rates.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
<script src="<?= asset_url('assets/js/rates.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

<?php ob_start(); ?>
    <?= help_button('rates') ?>
    <a href="<?= base_url('rates') ?>" class="btn btn-secondary btn-sm">Cancel</a>
<?= \FleetForge\Ui\ModuleHero::render([
    'crumbs'   => [['Dashboard', base_url('dashboard')], ['Rates', base_url('rates')], ['New rate card', null]],
    'eyebrow'  => 'Rates',
    'icon'     => 'currency-dollar',
    'accent'   => 'primary',
    'title'    => $pre['from_card'] ? 'Duplicate a rate card' : 'New rate card',
    'subtitle' => $pre['from_card']
        ? 'Starting from “' . e($pre['from_card']['name']) . '” — choose who the copy is for, adjust, and save.'
        : 'Set prices for one customer, or a standard price list for everyone. Each line starts from today\'s price, so you only change what is different.',
    'art'      => 'rates',
    'actions'  => ob_get_clean(),
]) ?>

<div x-data="FF_RateCardCreate()" class="rt-create">
<div>

    <div class="alert alert-danger" x-show="error" x-cloak style="margin-bottom:14px;"><span x-text="error"></span></div>

    <!-- 1 · Who -->
    <section class="rt-step" :class="{ 'is-done': scope && (scope === 'everyone' || customer_id) }">
        <header class="rt-step-head">
            <span class="rt-step-num">1</span>
            <div><h2>Who are these prices for?</h2><p>A customer's card wins over the standard prices for that customer only.</p></div>
        </header>
        <div class="rt-step-body">
            <div class="rt-choices">
                <button type="button" class="rt-choice" :class="{ 'is-on': scope === 'customer' }" @click="setScope('customer')">
                    <span class="rt-choice-ic"><?= \FleetForge\Sop\SopIcons::svg('user-group') ?></span>
                    <span><b>One customer</b><small>Negotiated prices. New leases for this customer pre-fill with them.</small></span>
                </button>
                <button type="button" class="rt-choice" :class="{ 'is-on': scope === 'everyone' }" @click="setScope('everyone')">
                    <span class="rt-choice-ic"><?= \FleetForge\Sop\SopIcons::svg('users') ?></span>
                    <span><b>Everyone</b><small>A standard price list for customers without their own card.</small></span>
                </button>
            </div>
            <div x-show="scope === 'customer'" x-cloak style="margin-top:14px;">
                <label class="form-label">Customer</label>
                <?php
                $pickerName     = 'rtNewCustomer';
                $pickerConfig   = [
                    'endpoint'    => '/api/v1/customers/index.php',
                    'searchParam' => 'search',
                    'resultKey'   => 'items',
                    'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: (r.city ?? '') + (r.province ? ', ' + r.province : '') })",
                    'placeholder' => 'Search customers…',
                    'initialId'   => $pre['customer_id'],
                    'initialLabel'=> $pre['customer_name'],
                ];
                $pickerOnPicked  = 'pickCustomer($event.detail.id, $event.detail.label)';
                $pickerOnCleared = 'pickCustomer(null, null)';
                $pickerError     = 'false';
                require FF_ROOT . '/includes/partials/record-picker.php';
                ?>
                <template x-if="cust.loading"><div class="skeleton skeleton-row" style="margin-top:10px;"></div></template>
                <template x-if="customer_id && cust.data">
                    <div style="margin-top:12px;">
                        <div class="rt-muted text-sm" x-show="cust.data.cards.length">
                            <span x-text="customer_name + ' already has: '"></span>
                            <template x-for="(c, i) in cust.data.cards" :key="c.id">
                                <span><a class="link" :href="'<?= e(base_url('rates/show')) ?>?id=' + c.id" target="_blank" x-text="c.name"></a>
                                    <span class="rt-faint" x-text="'(' + FF_Rates.statusLabel(c.status).toLowerCase() + ')' + (i < cust.data.cards.length - 1 ? ', ' : '')"></span></span>
                            </template>
                        </div>
                        <div class="rt-muted text-sm" x-show="!cust.data.cards.length" x-text="customer_name + ' has no rate card yet — today they pay standard prices' + (cust.data.on_rent ? ', and ' + cust.data.on_rent + ' lease' + (cust.data.on_rent === 1 ? '' : 's') + ' on rent carry their own typed-in prices.' : '.')"></div>
                    </div>
                </template>
            </div>
        </div>
    </section>

    <!-- 2 · Which equipment -->
    <section class="rt-step" :class="{ 'is-done': lines.length > 0 }" x-show="scopeReady()" x-cloak>
        <header class="rt-step-head">
            <span class="rt-step-num">2</span>
            <div><h2>Which equipment?</h2><p>A line for one equipment type wins over a whole-category line.</p></div>
            <span class="rt-spacer"></span>
            <button type="button" class="btn btn-ghost btn-sm" x-show="customer_id && cust.data && cust.data.on_rent" @click="pickRented()">Pick what they rent</button>
        </header>
        <div class="rt-step-body">
            <div class="rt-group-label">One equipment type</div>
            <div class="rt-picks">
                <template x-for="o in lineOptions.filter(o => o.group === 'One equipment type')" :key="o.value">
                    <button type="button" class="rt-pick" :class="{ 'is-on': hasLine(o.value) }" @click="toggleLine(o.value)">
                        <span class="rt-box" x-text="hasLine(o.value) ? '✓' : ''"></span>
                        <span>
                            <b x-text="o.label + (o.active === false ? ' (inactive)' : '')"></b>
                            <small x-text="pickHint(o)"></small>
                        </span>
                    </button>
                </template>
            </div>
            <div class="rt-group-label">Whole category</div>
            <div class="rt-picks">
                <template x-for="o in lineOptions.filter(o => o.group === 'Whole category')" :key="o.value">
                    <button type="button" class="rt-pick" :class="{ 'is-on': hasLine(o.value) }" @click="toggleLine(o.value)">
                        <span class="rt-box" x-text="hasLine(o.value) ? '✓' : ''"></span>
                        <span><b x-text="o.label"></b><small x-text="o.scope"></small></span>
                    </button>
                </template>
            </div>
        </div>
    </section>

    <!-- 3 · Prices -->
    <section class="rt-step" x-show="lines.length > 0" x-cloak>
        <header class="rt-step-head">
            <span class="rt-step-num">3</span>
            <div><h2>Prices</h2><p x-text="customer_id ? 'Pre-filled with what ' + customer_name + ' pays today — change what is different.' : 'Pre-filled with today\'s standard prices.'"></p></div>
            <span class="rt-spacer"></span>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <div class="rt-money rt-money--pct" style="width:88px;min-width:0;"><span>%</span><input type="number" step="0.1" class="form-control form-control-sm" x-model="adjustPct" placeholder="±%" aria-label="Adjust rent prices by percent"></div>
                <select class="form-select form-control-sm" style="width:auto;" x-model="adjustRound" aria-label="Round to">
                    <option value="0.01">to the cent</option><option value="1">to $1</option><option value="5">to $5</option>
                </select>
                <button type="button" class="btn btn-secondary btn-sm" :disabled="!adjustPct" @click="applyAdjust()">Adjust</button>
            </div>
        </header>
        <div class="rt-step-body" style="padding:0;">
            <?php $rtEditorLeaseHelp = true; $rtEditorVs = true; require FF_ROOT . '/includes/partials/rates-line-editor.php'; ?>
            <p class="rt-faint text-sm" style="margin:0;padding:10px 14px 14px;">Daily, weekly and monthly go together — set all three, or leave all three blank for an hourly- or distance-only line. Min. days: a shorter rental is charged that many days (where the equipment's category uses minimums).</p>
        </div>
    </section>

    <!-- 4 · When -->
    <section class="rt-step" x-show="lines.length > 0" x-cloak>
        <header class="rt-step-head">
            <span class="rt-step-num">4</span>
            <div><h2>When do they apply?</h2><p>New leases take the prices in force on the day they are created.</p></div>
        </header>
        <div class="rt-step-body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="rt-from">Starts</label>
                    <input id="rt-from" type="date" class="form-control" x-model="form.effective_from">
                    <div class="rt-quick">
                        <button type="button" @click="form.effective_from = '<?= e($today) ?>'">Today</button>
                        <button type="button" @click="form.effective_from = FF_Rates.nextMonthStart()">1st of next month</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="rt-to">Ends</label>
                    <input id="rt-to" type="date" class="form-control" x-model="form.effective_to" :min="form.effective_from" :disabled="openEnded">
                    <div class="rt-quick">
                        <button type="button" :class="{ 'is-on': openEnded }" @click="openEnded = true; form.effective_to = ''">Open-ended</button>
                        <button type="button" @click="setEnd(6)">6 months</button>
                        <button type="button" @click="setEnd(12)">1 year</button>
                        <button type="button" @click="openEnded = false; form.effective_to = (form.effective_from || '<?= e($today) ?>').slice(0, 4) + '-12-31'">End of year</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 5 · Name -->
    <section class="rt-step" x-show="lines.length > 0" x-cloak>
        <header class="rt-step-head">
            <span class="rt-step-num">5</span>
            <div><h2>Name it</h2><p>How the card shows in lists and on the lease form's price banner.</p></div>
        </header>
        <div class="rt-step-body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="rt-name">Card name</label>
                    <input id="rt-name" type="text" class="form-control" maxlength="255" x-model="form.name" :placeholder="suggestedName()">
                    <div class="form-hint" x-show="!form.name">Leave blank to use “<span x-text="suggestedName()"></span>”.</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="rt-desc">Notes <span class="rt-faint">(optional)</span></label>
                    <input id="rt-desc" type="text" class="form-control" maxlength="1000" x-model="form.description" placeholder="e.g. Signed deal, reviewed every January">
                </div>
                <template x-if="scope === 'everyone'">
                    <div class="form-group form-group--full">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" class="form-check-input" x-model="form.is_default"> Make this the main price list
                        </label>
                        <div class="form-hint">When two general cards price the same equipment, the main list wins. This takes the flag from the current main list.</div>
                    </div>
                </template>
            </div>
        </div>
    </section>
</div>

<!-- Summary -->
<aside>
    <div class="rt-panel rt-summary">
        <h3>What happens when you create it</h3>
        <template x-if="!scopeReady() || !lines.length">
            <p class="rt-muted text-sm" style="margin:0;">Choose who the prices are for and at least one piece of equipment.</p>
        </template>
        <template x-if="scopeReady() && lines.length">
            <div>
                <ul>
                    <li x-html="summaryWho()"></li>
                    <template x-for="l in lines.slice(0, 6)" :key="l._k">
                        <li><b x-text="l.label"></b>: <span x-text="summaryPrice(l)"></span></li>
                    </template>
                    <li x-show="lines.length > 6" x-text="'…and ' + (lines.length - 6) + ' more'"></li>
                    <li>Leases already on rent keep their prices.</li>
                </ul>
                <template x-if="problems().length">
                    <div class="rt-issue-list"><template x-for="p in problems()" :key="p"><div x-text="p"></div></template></div>
                </template>
                <template x-if="!problems().length && check.state === 'ok'"><p class="rt-ok-line">✓ Ready — every check passed.</p></template>
                <template x-if="!problems().length && check.state === 'checking'"><p class="rt-faint text-sm" style="margin:10px 0 0;">Checking…</p></template>
            </div>
        </template>
        <button type="button" class="btn btn-primary" style="width:100%;margin-top:14px;" :disabled="saving || !canSubmit()" @click="submit()"
                x-text="saving ? 'Creating…' : 'Create rate card'"></button>
        <a href="<?= base_url('rates') ?>" class="btn btn-ghost btn-sm" style="width:100%;margin-top:6px;">Cancel</a>
    </div>
</aside>
</div>

<script>
function FF_RateCardCreate() {
    const API = '<?= e(base_url('api/v1/rate_cards')) ?>/';
    const PRE = <?= json_encode($pre, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const TODAY = '<?= e($today) ?>';
    const grid = FF_Rates.gridMixin({
        options:   <?= json_encode($lineOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        standards: <?= json_encode($standards, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    });

    return Object.assign(grid, {
        scope: PRE.scope,
        customer_id: PRE.customer_id,
        customer_name: PRE.customer_name,
        lines: [],
        form: { name: '', description: PRE.description || '', effective_from: TODAY, effective_to: '', is_default: false },
        openEnded: true,
        cust: { loading: false, data: null },
        check: { state: 'idle', seq: 0, timer: null, fields: {} },
        saving: false,
        error: '',

        init() {
            (PRE.lines || []).forEach(l => { const ln = this.makeLine(l); ln._orig = null; this.lines.push(ln); });
            if (this.customer_id) this.loadCustomer();
            if (PRE.template_key && !this.hasLine(PRE.template_key)) {
                // Wait for the customer's prices so the line starts from them.
                const add = () => this.toggleLine(PRE.template_key);
                this.customer_id ? this.$watch('cust.data', (v) => { if (v && !this.hasLine(PRE.template_key)) add(); }) : add();
            }
            if (window.FF_FormDraft) {
                this._draft = FF_FormDraft.attach({
                    formId: 'rate-card-create', entityId: PRE.from_card ? 'dup-' + PRE.from_card.id : 'new', el: this.$root, version: '2',
                    get: () => ({ form: this.form, openEnded: this.openEnded }),
                    set: (d) => { if (d.form) Object.assign(this.form, d.form); if ('openEnded' in d) this.openEnded = d.openEnded; },
                });
            }
            this.$watch('lines', () => this.queueCheck(), { deep: true });
            this.$watch('form', () => this.queueCheck(), { deep: true });
            this.$watch('openEnded', () => this.queueCheck());
        },

        // ── Who ──────────────────────────────────────────────────────
        setScope(s) {
            this.scope = s;
            if (s === 'everyone') { this.customer_id = null; this.customer_name = ''; this.cust.data = null; }
            this.queueCheck();
        },
        scopeReady() { return this.scope === 'everyone' || (this.scope === 'customer' && !!this.customer_id); },
        pickCustomer(id, name) {
            this.customer_id = id;
            this.customer_name = name || '';
            this.cust.data = null;
            if (id) this.loadCustomer();
            this.queueCheck();
        },
        async loadCustomer() {
            this.cust.loading = true;
            try {
                const r = await FF_Api.get(API + 'customer_prices?all_types=1&customer_id=' + this.customer_id);
                if (r.success) this.cust.data = r.data;
            } catch (e) { /* the page still works on standard prices */ }
            finally { this.cust.loading = false; }
        },
        priceFor(o) {
            // What this customer pays today for an equipment type (t:id) — from customer_prices.
            if (!this.cust.data || !o.template_id) return null;
            return this.cust.data.prices.find(p => p.template_id === o.template_id) || null;
        },
        pickHint(o) {
            const p = this.priceFor(o);
            if (p) {
                const src = { customer: 'their card', rate_card: 'standard', template: 'standard', none: 'no price' }[p.price.source];
                const lease = p.lease ? ' · ' + p.lease.count + ' on rent' : '';
                return (p.price.source === 'none' ? 'no price today' : 'today ' + FF_Rates.chipPrices(p.price) + ' (' + src + ')') + lease;
            }
            const s = this.standards[o.value];
            return s && s.daily ? 'standard ' + FF_Rates.short(s.daily) + '/day' : o.scope;
        },

        // ── Equipment ────────────────────────────────────────────────
        hasLine(key) { return this.lines.some(l => l.key === key); },
        toggleLine(key) {
            const existing = this.lines.find(l => l.key === key);
            if (existing) { this.removeLine(existing); return; }
            const o = this.lineOptions.find(x => x.value === key) || {};
            const today = this.priceFor(o);
            let seed = null;
            if (today && today.price.source !== 'none') {
                seed = Object.assign({}, today.price);
            } else {
                const s = this.standards[key];
                if (s && !s.range && (s.daily || s.weekly || s.monthly)) seed = { daily_rate: s.daily, weekly_rate: s.weekly, monthly_rate: s.monthly };
            }
            if (seed) { delete seed.source; delete seed.source_label; delete seed.rate_card_id; }
            const l = this.addLine(key, seed);
            if (l) l._orig = null;
        },
        pickRented() {
            (this.cust.data?.prices || []).filter(p => p.lease).forEach(p => {
                if (!this.hasLine('t:' + p.template_id)) this.toggleLine('t:' + p.template_id);
            });
        },
        leasePricesFor(l) {
            if (!this.cust.data || !l.equipment_template_id) return null;
            const p = this.cust.data.prices.find(x => x.template_id === l.equipment_template_id);
            return p && p.lease ? p.lease : null;
        },
        useLeasePrices(l) {
            const p = this.leasePricesFor(l);
            if (!p) return;
            ['daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate', 'hourly_rate', 'gps_price'].forEach(f => {
                l[f] = p[f] === null || p[f] === undefined || Number(p[f]) === 0 ? '' : FF_Rates.clean(p[f], FF_Rates.FIELD_DP[f]);
            });
            l.mileage_unit = p.mileage_unit || l.mileage_unit;
            l.currency = p.currency || l.currency;
            if (p.minimum_days !== null && p.minimum_days !== undefined && Number(p.minimum_days) >= 2) l.minimum_days = String(p.minimum_days);
        },

        // ── When / name ──────────────────────────────────────────────
        setEnd(months) {
            const d = new Date((this.form.effective_from || TODAY) + 'T12:00:00');
            d.setMonth(d.getMonth() + months);
            d.setDate(d.getDate() - 1);
            this.openEnded = false;
            this.form.effective_to = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        },
        suggestedName() {
            const eq = this.lines.map(l => l.label).slice(0, 2).join(', ') + (this.lines.length > 2 ? ' +' + (this.lines.length - 2) : '');
            if (this.scope === 'everyone') return 'Standard prices' + (eq ? ' — ' + eq : '') + ' ' + (this.form.effective_from || TODAY).slice(0, 4);
            return (eq || 'Prices') + (this.customer_name ? ' - ' + this.customer_name : '');
        },
        finalName() { return (this.form.name || '').trim() || this.suggestedName(); },

        // ── Summary + checks ─────────────────────────────────────────
        summaryWho() {
            const who = this.scope === 'everyone' ? 'Customers without their own card' : '<b>' + this.customer_name.replace(/[<>&]/g, '') + '</b>';
            return 'From <b>' + FF_Rates.date(this.form.effective_from) + '</b>' + (this.openEnded || !this.form.effective_to ? ', open-ended,' : ' to <b>' + FF_Rates.date(this.form.effective_to) + '</b>,') + ' ' + who + ' pay' + (this.scope === 'everyone' ? '' : 's') + ':';
        },
        summaryPrice(l) {
            const p = FF_Rates.chipPrices(l);
            const s = this.stdFor(l);
            const v = s && !s.range ? FF_Rates.vs(l.daily_rate, s.daily) : null;
            return p + (v && this.scope === 'customer' ? ' (' + v.text + ')' : '');
        },
        problems() {
            const out = [];
            if (!this.form.effective_from) out.push('Choose the day the prices start.');
            if (!this.openEnded && this.form.effective_to && this.form.effective_to < this.form.effective_from) out.push('The end date must be on or after the start date.');
            return out.concat(this.gridProblems(), Object.values(this.check.fields || {}));
        },
        canSubmit() { return this.scopeReady() && this.lines.length > 0 && !this.problems().length; },
        body(dry) {
            return {
                name: this.finalName(), description: (this.form.description || '').trim() || null,
                effective_from: this.form.effective_from, effective_to: this.openEnded ? null : (this.form.effective_to || null),
                is_default: this.scope === 'everyone' && this.form.is_default ? 1 : 0,
                customer_id: this.scope === 'customer' ? this.customer_id : null,
                items: this.gridPayload(), dry_run: dry ? 1 : 0,
            };
        },
        queueCheck() {
            clearTimeout(this.check.timer);
            if (!this.scopeReady() || !this.lines.length || this.gridProblems().length) { this.check.state = 'idle'; this.check.fields = {}; return; }
            this.check.state = 'checking';
            this.check.timer = setTimeout(() => this.runCheck(), 600);
        },
        async runCheck() {
            const seq = ++this.check.seq;
            try {
                const r = await FF_Api.post(API + 'create', this.body(true), { quiet: true });
                if (seq !== this.check.seq) return;
                if (r.success) { this.check.state = 'ok'; this.check.fields = {}; }
                else { this.check.state = 'error'; this.check.fields = r.error?.fields || { _: r.error?.message || 'Something is not right.' }; }
            } catch (e) { if (seq === this.check.seq) this.check.state = 'idle'; }
        },
        async submit() {
            if (!this.canSubmit()) { this.error = this.problems()[0] || 'Complete the steps first.'; return; }
            this.saving = true;
            this.error = '';
            try {
                const r = await FF_Api.post(API + 'create', this.body(false));
                if (!r.success) {
                    this.error = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Could not create the rate card.');
                    return;
                }
                if (this._draft) this._draft.clear(true);
                window.location = '<?= e(base_url('rates/show')) ?>?id=' + r.data.id + '&saved=1';
            } catch (e) {
                this.error = e.message || 'Could not create the rate card.';
            } finally {
                this.saving = false;
            }
        },
    });
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
