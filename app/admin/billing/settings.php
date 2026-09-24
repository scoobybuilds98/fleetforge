<?php
declare(strict_types=1);

/**
 * app/admin/billing/settings.php
 *
 * S-BILLING-MODULE — Billing → Settings. The settings that shape the
 * monthly cycle, in one place:
 *   - Schedule: which month a cycle bills (arrears / advance), the day the
 *     scheduled job opens it, review-by / send-by targets, default owner
 *   - Review: the variance thresholds the Review tab flags on; whether
 *     closing requires every invoice reviewed
 *   - Approval: "Require approval before batch billing" + "Allow
 *     self-approval" (MOVED here from Settings → General; same keys)
 *   - USD→CAD exchange rate: the rate US-dollar invoices freeze (nothing
 *     else in the app could enter one)
 *   - Elsewhere: links (not copies) to the billing-adjacent settings that
 *     live in their own modules — scheduled jobs, late fees, customer
 *     reminder emails, invoice numbering/terms, email templates
 *
 * Reads need invoices:view; writes need settings_general:edit (checked by
 * the APIs; the page hides the Save buttons for everyone else).
 *
 * @depends api/v1/billing/settings, api/v1/billing/exchange_rates
 * @session S-BILLING-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

$canEditSettings = can('settings_general', 'edit');

$pageTitle      = 'Billing settings';
$helpModuleSlug = 'billing';
require_once FF_ROOT . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/billing.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

<?php ob_start(); ?>
    <?= help_button('billing') ?>
    <a href="<?= base_url('billing') ?>" class="btn btn-secondary btn-sm">Back to Billing</a>
<?= \FleetForge\Ui\ModuleHero::render([
    'crumbs'   => [['Dashboard', base_url('dashboard')], ['Billing', base_url('billing')], ['Settings', null]],
    'eyebrow'  => 'Billing',
    'icon'     => 'cog-6-tooth',
    'accent'   => 'primary',
    'title'    => 'Billing settings',
    'subtitle' => 'How the monthly cycle runs: schedule, review thresholds, approval and the US-dollar rate.',
    'art'      => 'settings',
    'actions'  => ob_get_clean(),
]) ?>

<div x-data="FF_BillingSettings()" x-cloak>
    <?php if (!$canEditSettings): ?>
    <div class="alert alert-info" style="margin-bottom:14px;">You can view these settings. Changing them needs the Settings (General) edit permission.</div>
    <?php endif; ?>

    <template x-if="!loaded"><div><template x-for="n in 4" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>

    <template x-if="loaded">
    <div class="bc-grid-2">
        <!-- Schedule -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Cycle schedule</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" for="bs_mode">Which month a cycle bills</label>
                    <select id="bs_mode" class="form-select" x-model="s['billing_cycle.mode']" :disabled="!canEdit">
                        <option value="arrears">Last month — bill what has happened (arrears)</option>
                        <option value="advance">This month — bill ahead (advance)</option>
                    </select>
                    <p class="form-hint">Decides the "cycle to work on now" on Billing and the month the scheduled job opens.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="bs_day">Open the cycle on day</label>
                    <input id="bs_day" type="number" min="1" max="28" class="form-control" style="max-width:120px;" x-model="s['billing_cycle.open_day']" :disabled="!canEdit">
                    <div class="field-error" x-show="errors['billing_cycle.open_day']" x-text="errors['billing_cycle.open_day']"></div>
                    <p class="form-hint">The "Open billing cycles" scheduled job (Settings → Intelligence → Scheduled Jobs) opens the cycle on this day and tells the owner. It creates no invoices.</p>
                </div>
                <div class="bc-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="bs_billby">Drafts reviewed within (days)</label>
                        <input id="bs_billby" type="number" min="0" max="60" class="form-control" x-model="s['billing_cycle.bill_by_days']" :disabled="!canEdit">
                        <div class="field-error" x-show="errors['billing_cycle.bill_by_days']" x-text="errors['billing_cycle.bill_by_days']"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bs_sendby">Everything sent within (days)</label>
                        <input id="bs_sendby" type="number" min="0" max="60" class="form-control" x-model="s['billing_cycle.send_by_days']" :disabled="!canEdit">
                        <div class="field-error" x-show="errors['billing_cycle.send_by_days']" x-text="errors['billing_cycle.send_by_days']"></div>
                    </div>
                </div>
                <p class="form-hint" style="margin-top:-6px;">Counted from the day the month can be billed. The owner is reminded weekly while a cycle is past its send-by date with drafts unsent.</p>
                <div class="form-group">
                    <label class="form-label" for="bs_owner">Default owner of new cycles</label>
                    <select id="bs_owner" class="form-select" x-model="s['billing_cycle.owner_user_id']" :disabled="!canEdit">
                        <option value="">Everyone who can see invoices</option>
                        <template x-for="u in users" :key="u.id"><option :value="String(u.id)" x-text="u.name"></option></template>
                    </select>
                </div>
            </div>
        </div>

        <!-- Review + approval -->
        <div>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Review</h3></div>
                <div class="card-body">
                    <p class="form-hint" style="margin-top:0;">The Review tab flags an invoice whose total moved against the same lease last month by more than BOTH of these.</p>
                    <div class="bc-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="bs_pct">Change larger than (%)</label>
                            <input id="bs_pct" type="text" inputmode="decimal" class="form-control" x-model="s['billing_cycle.variance_pct']" :disabled="!canEdit">
                            <div class="field-error" x-show="errors['billing_cycle.variance_pct']" x-text="errors['billing_cycle.variance_pct']"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bs_min">…and larger than ($)</label>
                            <input id="bs_min" type="text" inputmode="decimal" class="form-control" x-model="s['billing_cycle.variance_min_amount']" :disabled="!canEdit">
                            <div class="field-error" x-show="errors['billing_cycle.variance_min_amount']" x-text="errors['billing_cycle.variance_min_amount']"></div>
                        </div>
                    </div>
                    <label style="display:flex; gap:8px; align-items:flex-start;">
                        <input type="checkbox" :checked="s['billing_cycle.close_requires_review'] === '1'" @change="s['billing_cycle.close_requires_review'] = $event.target.checked ? '1' : '0'" :disabled="!canEdit">
                        <span>Closing a cycle requires every invoice marked Reviewed</span>
                    </label>
                </div>
            </div>
            <div class="card" style="margin-top:14px;">
                <div class="card-header"><h3 class="card-title">Approval</h3></div>
                <div class="card-body">
                    <label style="display:flex; gap:8px; align-items:flex-start; margin-bottom:10px;">
                        <input type="checkbox" :checked="s['invoices.approval_required'] === '1'" @change="s['invoices.approval_required'] = $event.target.checked ? '1' : '0'" :disabled="!canEdit">
                        <span><strong>Require approval before batch billing.</strong> The workbench cannot generate directly — a run is submitted, a manager approves it, then it is generated. A lease's own Generate Invoice and the monthly job are not affected.</span>
                    </label>
                    <label style="display:flex; gap:8px; align-items:flex-start;">
                        <input type="checkbox" :checked="s['invoices.approval_allow_self'] === '1'" @change="s['invoices.approval_allow_self'] = $event.target.checked ? '1' : '0'" :disabled="!canEdit">
                        <span><strong>Allow self-approval.</strong> When off, whoever submitted a run cannot approve it — a second person with the invoice "approve" permission must. Super admins are not exempt.</span>
                    </label>
                </div>
            </div>
            <?php if ($canEditSettings): ?>
            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="save()" x-text="saving ? 'Saving…' : 'Save settings'"></button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Exchange rate -->
        <div class="card" id="exchange-rate">
            <div class="card-header"><h3 class="card-title">US-dollar exchange rate (USD → CAD)</h3></div>
            <div class="card-body">
                <p class="form-hint" style="margin-top:0;">
                    Every US-dollar invoice freezes the <strong>latest</strong> rate on file when it is generated, plus the markup set under
                    Settings → General (<span x-text="fx.markup_pct"></span>%). Enter the day's rate before generating US-dollar leases.
                    Payments and banking read the same rate.
                </p>
                <?php if ($canEditSettings): ?>
                <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; margin-bottom:12px;">
                    <div>
                        <label class="form-label" for="fx_date">Date</label>
                        <input id="fx_date" type="date" class="form-control" x-model="fx.date" :max="today">
                        <div class="field-error" x-show="fx.errors.rate_date" x-text="fx.errors.rate_date"></div>
                    </div>
                    <div>
                        <label class="form-label" for="fx_rate">1 USD = ? CAD</label>
                        <input id="fx_rate" type="text" inputmode="decimal" class="form-control" style="max-width:140px;font-family:var(--font-mono);" x-model="fx.rate" placeholder="1.3650">
                        <div class="field-error" x-show="fx.errors.rate" x-text="fx.errors.rate"></div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" :disabled="fx.saving" @click="saveRate()" x-text="fx.saving ? 'Saving…' : 'Save rate'"></button>
                </div>
                <?php endif; ?>
                <table class="table">
                    <thead><tr><th>Date</th><th class="text-right">Rate</th><th>Source</th></tr></thead>
                    <tbody>
                        <template x-for="r in fx.rates" :key="r.rate_date">
                            <tr><td x-text="r.rate_date"></td><td class="text-right bc-mono" x-text="Number(r.rate).toFixed(4)"></td><td x-text="r.source"></td></tr>
                        </template>
                        <tr x-show="!fx.rates.length"><td colspan="3" class="bc-muted">No USD→CAD rate on file — US-dollar invoices cannot be converted.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Elsewhere -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Billing settings that live elsewhere</h3></div>
            <div class="card-body">
                <p class="form-hint" style="margin-top:0;">Kept in their own modules so there is only one place to change each.</p>
                <ul class="bc-check-list">
                    <li><a href="<?= base_url('settings') ?>?tab=intelligence">Scheduled Jobs</a><span class="bc-muted">— turn "Open billing cycles", "Monthly invoice generation" (ships off), overdue marking and late fees on or off.</span></li>
                    <li><a href="<?= base_url('settings') ?>?tab=general">General → Invoices &amp; Billing</a><span class="bc-muted">— invoice number prefix, default payment terms, advance billing limit, USD markup.</span></li>
                    <li><a href="<?= base_url('settings/late_fees') ?>">Late Fees</a><span class="bc-muted">— the global late-fee rule and per-customer overrides.</span></li>
                    <li><a href="<?= base_url('settings') ?>?tab=customer_notifications">Customer Emails</a><span class="bc-muted">— due-soon, overdue, receipt and statement reminders.</span></li>
                    <li><a href="<?= base_url('settings/email_templates') ?>">Email Templates</a><span class="bc-muted">— the "invoice_ready" email sent with each invoice.</span></li>
                    <li><a href="<?= base_url('accounting/collections') ?>">Collections</a><span class="bc-muted">— dunning letters, promises to pay and notes after invoices go out.</span></li>
                </ul>
            </div>
        </div>
    </div>
    </template>
</div>

<script>
function FF_BillingSettings() {
    const api = <?= json_encode(rtrim(base_url(''), '/')) ?> + '/api/v1';
    return {
        loaded: false, saving: false, canEdit: <?= $canEditSettings ? 'true' : 'false' ?>,
        s: {}, users: [], errors: {},
        today: FF_localDate(),
        fx: { rates: [], markup_pct: '0', date: FF_localDate(), rate: '', saving: false, errors: {} },
        async init() {
            if (this._inited) return; this._inited = true;
            const [a, b] = await Promise.all([FF_Api.get(api + '/billing/settings'), FF_Api.get(api + '/billing/exchange_rates')]);
            if (a.success) { this.s = a.data.settings; this.users = a.data.users; this.canEdit = a.data.can_edit; }
            else FF_Toast.error(a.error?.message || 'Could not load settings.');
            if (b.success) { this.fx.rates = b.data.rates; this.fx.markup_pct = b.data.markup_pct; }
            this.loaded = true;
            if (location.hash === '#exchange-rate') this.$nextTick(() => document.getElementById('exchange-rate')?.scrollIntoView({ behavior: 'smooth' }));
        },
        async save() {
            this.saving = true; this.errors = {};
            const r = await FF_Api.post(api + '/billing/settings', this.s);
            this.saving = false;
            if (r.success) { this.s = r.data.settings; FF_Toast.success('Billing settings saved.'); }
            else { this.errors = r.error?.fields || {}; if (!r.error?.fields) FF_Toast.error(r.error?.message || 'Could not save.'); }
        },
        async saveRate() {
            this.fx.saving = true; this.fx.errors = {};
            const r = await FF_Api.post(api + '/billing/exchange_rates', { rate_date: this.fx.date, rate: this.fx.rate });
            this.fx.saving = false;
            if (r.success) { this.fx.rates = r.data.rates; this.fx.rate = ''; FF_Toast.success('Rate saved.'); }
            else { this.fx.errors = r.error?.fields || {}; if (!r.error?.fields) FF_Toast.error(r.error?.message || 'Could not save the rate.'); }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
