<?php declare(strict_types=1);

/**
 * FleetForge — Accounting Settings
 *
 * @file        app/admin/accounting/settings/index.php
 * @description Accounting module settings with 5 tabs: General, GL Account
 *              Mapping, Revenue Mapping, Depreciation, Tax Filing. Each tab
 *              section loads current values from the settings table (keys
 *              prefixed with 'accounting.') and saves via AJAX POST. Settings
 *              use a key/value store pattern with per-tab save buttons.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/settings,
 *              api/v1/accounting/accounts
 * @session     S029
 */

// dirname(__DIR__, 4): settings/ → accounting/ → admin/ → app/ → root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('accounting_settings', 'view');

$canEdit = can('accounting_settings', 'edit');

// ── Pre-load current accounting settings ───────────────────────
// WHY: Server-render current values so forms are populated on initial load
// without an extra API round-trip.
// NOTE: settings table uses reserved word `key` — must backtick-quote.
$settingRows = db_select(
    "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'accounting.%'",
    []
);
$settings = [];
foreach ($settingRows as $row) {
    // WHY: Strip 'accounting.' prefix for cleaner key access in JS.
    $key = substr($row['key'], 11);
    $settings[$key] = $row['value'];
}

// ── Load GL accounts for mapping dropdowns ─────────────────────
$glAccounts = db_select(
    "SELECT id, code, name, account_type
       FROM acc_accounts
      WHERE is_active = 1 AND is_header = 0
      ORDER BY code",
    []
);

// ── Load invoice line types for revenue mapping ────────────────
// WHY: Pull distinct item_type from invoice_line_items so the
// Revenue Mapping dropdown lists every category the billing engine
// actually emits (base_rental, mileage_*, late_fee, damage, …).
$lineTypes = [];
try {
    $lineTypes = db_select(
        "SELECT DISTINCT item_type AS line_type FROM invoice_line_items WHERE item_type IS NOT NULL ORDER BY item_type",
        []
    );
} catch (\Throwable $e) {
    // Table may not exist yet — that's OK, revenue mapping will be empty
    $lineTypes = [];
}

$pageTitle = 'Accounting Settings';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────── -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Settings</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Accounting Settings</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ============================================================
     ACCOUNTING SETTINGS — ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_AcctSettings()" x-init="init()">

    <!-- ── TAB BAR ────────────────────────────────────────────── -->
    <div class="tab-bar" role="tablist">
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'general' }"
                @click="activeTab = 'general'" role="tab">
            General
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'gl_mapping' }"
                @click="activeTab = 'gl_mapping'" role="tab">
            GL Account Mapping
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'revenue_mapping' }"
                @click="activeTab = 'revenue_mapping'" role="tab">
            Revenue Mapping
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'depreciation' }"
                @click="activeTab = 'depreciation'" role="tab">
            Depreciation
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'tax_filing' }"
                @click="activeTab = 'tax_filing'" role="tab">
            Tax Filing
        </button>
    </div>

    <!-- ============================================================
         TAB: General
         ============================================================ -->
    <div x-show="activeTab === 'general'" class="card" style="padding:24px;">
        <h3 class="h6" style="margin:0 0 16px;">General Settings</h3>

        <div x-show="messages.general && messages.general.type === 'success'" class="alert alert-success"
             x-text="messages.general ? messages.general.text : ''" style="margin-bottom:16px;"></div>
        <div class="form-error-banner" x-show="formErrors.general" x-cloak x-text="formErrors.general" style="margin-bottom:16px;"></div>

        <div class="form-group">
            <label class="form-label" style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" x-model="general.enabled">
                Enable Accounting Module
            </label>
            <p class="text-secondary text-sm" style="margin:4px 0 0;">When disabled, accounting features are hidden from the sidebar and inaccessible.</p>
        </div>

        <div class="form-group" style="max-width:300px;">
            <label class="form-label">CapEx Threshold ($)</label>
            <input type="number" class="form-input" x-model.number="general.capex_threshold_cad"
                   :class="errors.capex_threshold_cad ? 'is-invalid' : ''"
                   @input="errors.capex_threshold_cad = ''"
                   min="0" step="100" placeholder="e.g. 5000">
            <div class="field-error" x-show="errors.capex_threshold_cad" x-cloak x-text="errors.capex_threshold_cad"></div>
            <p class="text-secondary text-sm" style="margin:4px 0 0;">Expenses above this amount are capitalized as assets rather than expensed immediately.</p>
        </div>

        <!-- WHY: fiscal_year_start not in DB yet — will be added when year-end close is built -->


        <div style="margin-top:20px;">
            <button class="btn btn-primary btn-sm" @click="saveGeneral()" :disabled="saving.general">
                <span x-show="!saving.general">Save General Settings</span>
                <span x-show="saving.general">Saving...</span>
            </button>
        </div>
    </div>

    <!-- ============================================================
         TAB: GL Account Mapping
         ============================================================ -->
    <div x-show="activeTab === 'gl_mapping'" class="card" style="padding:24px;">
        <h3 class="h6" style="margin:0 0 4px;">GL Account Mapping</h3>
        <p class="text-secondary text-sm" style="margin:0 0 16px;">Map system functions to their corresponding GL accounts. These are used for automatic journal entry generation.</p>

        <div x-show="messages.gl_mapping && messages.gl_mapping.type === 'success'" class="alert alert-success"
             x-text="messages.gl_mapping ? messages.gl_mapping.text : ''" style="margin-bottom:16px;"></div>
        <div class="form-error-banner" x-show="formErrors.gl_mapping" x-cloak x-text="formErrors.gl_mapping" style="margin-bottom:16px;"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <template x-for="mapping in glMappings" :key="mapping.key">
                <div class="form-group">
                    <label class="form-label" x-text="mapping.label"></label>
                    <select class="form-select" x-model="gl_mapping[mapping.key]">
                        <option value="">-- Not Mapped --</option>
                        <template x-for="acct in glAccountsByType(mapping.types)" :key="acct.id">
                            <option :value="acct.id" x-text="acct.code + ' — ' + acct.name"></option>
                        </template>
                    </select>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;" x-text="mapping.hint"></p>
                </div>
            </template>
        </div>

        <div style="margin-top:20px;">
            <button class="btn btn-primary btn-sm" @click="saveGlMapping()" :disabled="saving.gl_mapping">
                <span x-show="!saving.gl_mapping">Save GL Mappings</span>
                <span x-show="saving.gl_mapping">Saving...</span>
            </button>
        </div>
    </div>

    <!-- ============================================================
         TAB: Revenue Mapping
         ============================================================ -->
    <div x-show="activeTab === 'revenue_mapping'" class="card" style="padding:24px;">
        <h3 class="h6" style="margin:0 0 4px;">Revenue Account Mapping</h3>
        <p class="text-secondary text-sm" style="margin:0 0 16px;">Map each invoice line type to the GL revenue account it should post to.</p>

        <div x-show="messages.revenue_mapping && messages.revenue_mapping.type === 'success'" class="alert alert-success"
             x-text="messages.revenue_mapping ? messages.revenue_mapping.text : ''" style="margin-bottom:16px;"></div>
        <div class="form-error-banner" x-show="formErrors.revenue_mapping" x-cloak x-text="formErrors.revenue_mapping" style="margin-bottom:16px;"></div>

        <template x-if="lineTypes.length === 0">
            <div class="empty-state" style="padding:24px;">
                <p class="empty-state-title">No line types found</p>
                <p class="empty-state-text">Invoice line types will appear here once invoices are created.</p>
            </div>
        </template>

        <template x-if="lineTypes.length > 0">
            <div>
                <div class="table-responsive">
<table class="table" aria-label="Revenue mapping">
                    <thead>
                        <tr>
                            <th scope="col">Invoice Line Type</th>
                            <th scope="col">GL Revenue Account</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="lt in lineTypes" :key="lt">
                            <tr>
                                <td>
                                    <span class="badge badge-no-dot badge-neutral" x-text="lt"></span>
                                </td>
                                <td>
                                    <select class="form-select form-control-sm"
                                            x-model="revenue_mapping[lt]"
                                            style="max-width:400px;">
                                        <option value="">-- Not Mapped --</option>
                                        <template x-for="acct in revenueAccounts" :key="acct.id">
                                            <option :value="acct.id" x-text="acct.code + ' — ' + acct.name"></option>
                                        </template>
                                    </select>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
</div>

                <div style="margin-top:16px;">
                    <button class="btn btn-primary btn-sm" @click="saveRevenueMapping()" :disabled="saving.revenue_mapping">
                        <span x-show="!saving.revenue_mapping">Save Revenue Mappings</span>
                        <span x-show="saving.revenue_mapping">Saving...</span>
                    </button>
                </div>
            </div>
        </template>
    </div>

    <!-- ============================================================
         TAB: Depreciation
         ============================================================ -->
    <div x-show="activeTab === 'depreciation'" class="card" style="padding:24px;">
        <h3 class="h6" style="margin:0 0 4px;">Depreciation Defaults</h3>
        <p class="text-secondary text-sm" style="margin:0 0 16px;">Default values for new capitalized assets. Individual assets can override these.</p>

        <div x-show="messages.depreciation && messages.depreciation.type === 'success'" class="alert alert-success"
             x-text="messages.depreciation ? messages.depreciation.text : ''" style="margin-bottom:16px;"></div>
        <div class="form-error-banner" x-show="formErrors.depreciation" x-cloak x-text="formErrors.depreciation" style="margin-bottom:16px;"></div>

        <div class="form-row" style="max-width:600px;">
            <div class="form-group" style="flex:1;">
                <label class="form-label">Default Method</label>
                <select class="form-select" x-model="depreciation.default_depreciation_method"
                        :class="errors.default_depreciation_method ? 'is-invalid' : ''"
                        @change="errors.default_depreciation_method = ''">
                    <option value="straight_line">Straight Line</option>
                    <option value="declining_balance">Declining Balance</option>
                    <option value="double_declining">Double Declining Balance</option>
                    <option value="sum_of_years">Sum of Years Digits</option>
                    <option value="units_of_production">Units of Production</option>
                </select>
                <div class="field-error" x-show="errors.default_depreciation_method" x-cloak x-text="errors.default_depreciation_method"></div>
            </div>
            <div class="form-group" style="flex:1;">
                <label class="form-label">Default Useful Life (years)</label>
                <input type="number" class="form-input" x-model.number="depreciation.default_useful_life_years"
                       :class="errors.default_useful_life_years ? 'is-invalid' : ''"
                       @input="errors.default_useful_life_years = ''"
                       min="1" max="50" step="1" placeholder="e.g. 5">
                <div class="field-error" x-show="errors.default_useful_life_years" x-cloak x-text="errors.default_useful_life_years"></div>
            </div>
        </div>

        <div class="form-group" style="max-width:300px;">
            <label class="form-label">Default Salvage Value (%)</label>
            <input type="number" class="form-input" x-model="depreciation.default_salvage_pct"
                   :class="errors.default_salvage_pct ? 'is-invalid' : ''"
                   @input="errors.default_salvage_pct = ''"
                   min="0" max="100" step="1" placeholder="e.g. 10">
            <div class="field-error" x-show="errors.default_salvage_pct" x-cloak x-text="errors.default_salvage_pct"></div>
            <p class="text-secondary text-sm" style="margin:4px 0 0;">Percentage of original cost retained as residual value at end of useful life.</p>
        </div>

        <!-- WHY: Depreciation account mapping will be added in the Fixed Assets phase (S032) -->

        <div style="margin-top:20px;">
            <button class="btn btn-primary btn-sm" @click="saveDepreciation()" :disabled="saving.depreciation">
                <span x-show="!saving.depreciation">Save Depreciation Settings</span>
                <span x-show="saving.depreciation">Saving...</span>
            </button>
        </div>
    </div>

    <!-- ============================================================
         TAB: Tax Filing
         ============================================================ -->
    <div x-show="activeTab === 'tax_filing'" class="card" style="padding:24px;">
        <h3 class="h6" style="margin:0 0 4px;">Tax Filing</h3>
        <p class="text-secondary text-sm" style="margin:0 0 16px;">Configure tax filing frequencies and remittance settings.</p>

        <div x-show="messages.tax_filing && messages.tax_filing.type === 'success'" class="alert alert-success"
             x-text="messages.tax_filing ? messages.tax_filing.text : ''" style="margin-bottom:16px;"></div>
        <div class="form-error-banner" x-show="formErrors.tax_filing" x-cloak x-text="formErrors.tax_filing" style="margin-bottom:16px;"></div>

        <div class="form-row" style="max-width:600px;">
            <div class="form-group" style="flex:1;">
                <label class="form-label">GST Filing Frequency</label>
                <select class="form-select" x-model="tax_filing.gst_filing_frequency"
                        :class="errors.gst_filing_frequency ? 'is-invalid' : ''"
                        @change="errors.gst_filing_frequency = ''">
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="annually">Annually</option>
                </select>
                <div class="field-error" x-show="errors.gst_filing_frequency" x-cloak x-text="errors.gst_filing_frequency"></div>
            </div>
            <div class="form-group" style="flex:1;">
                <label class="form-label">PST Filing Frequency</label>
                <select class="form-select" x-model="tax_filing.pst_filing_frequency"
                        :class="errors.pst_filing_frequency ? 'is-invalid' : ''"
                        @change="errors.pst_filing_frequency = ''">
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="annually">Annually</option>
                </select>
                <div class="field-error" x-show="errors.pst_filing_frequency" x-cloak x-text="errors.pst_filing_frequency"></div>
            </div>
        </div>

        <!-- WHY: Tax rates and reminder settings will be added in the Tax Management phase (S033) -->

        <div style="margin-top:20px;">
            <button class="btn btn-primary btn-sm" @click="saveTaxFiling()" :disabled="saving.tax_filing">
                <span x-show="!saving.tax_filing">Save Tax Filing Settings</span>
                <span x-show="saving.tax_filing">Saving...</span>
            </button>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_AcctSettings() {
    return {
        activeTab: 'general',

        // ── GL accounts for dropdowns (server-rendered) ────────
        glAccounts: <?= json_encode($glAccounts, JSON_HEX_TAG) ?>,
        lineTypes:  <?= json_encode(array_column($lineTypes, 'line_type'), JSON_HEX_TAG) ?>,

        // ── Tab form data ──────────────────────────────────────
        // WHY: JS property names MUST match the DB key (minus 'accounting.' prefix).
        // DB keys: accounting.enabled, accounting.capex_threshold_cad, (no fiscal_year_start yet)
        general: {
            enabled:              <?= json_encode(($settings['enabled'] ?? 'true') === 'true' || ($settings['enabled'] ?? '1') === '1') ?>,
            capex_threshold_cad:  <?= json_encode((int)($settings['capex_threshold_cad'] ?? 2500)) ?>
        },

        gl_mapping: {
            ar_account_id:                  <?= json_encode($settings['ar_account_id'] ?? '') ?>,
            ap_account_id:                  <?= json_encode($settings['ap_account_id'] ?? '') ?>,
            default_cash_account_id:        <?= json_encode($settings['default_cash_account_id'] ?? '') ?>,
            gst_payable_account_id:         <?= json_encode($settings['gst_payable_account_id'] ?? '') ?>,
            pst_payable_account_id:         <?= json_encode($settings['pst_payable_account_id'] ?? '') ?>,
            gst_receivable_account_id:      <?= json_encode($settings['gst_receivable_account_id'] ?? '') ?>,
            bad_debt_expense_account_id:    <?= json_encode($settings['bad_debt_expense_account_id'] ?? '') ?>,
            fx_gain_account_id:             <?= json_encode($settings['fx_gain_account_id'] ?? '') ?>,
            fx_loss_account_id:             <?= json_encode($settings['fx_loss_account_id'] ?? '') ?>,
            retained_earnings_account_id:   <?= json_encode($settings['retained_earnings_account_id'] ?? '') ?>,
            current_year_ni_account_id:     <?= json_encode($settings['current_year_ni_account_id'] ?? '') ?>,
            customer_deposits_account_id:   <?= json_encode($settings['customer_deposits_account_id'] ?? '') ?>,
            customer_credits_account_id:    <?= json_encode($settings['customer_credits_account_id'] ?? '') ?>
        },

        revenue_mapping: <?= json_encode(
            (function() use ($settings) {
                $map = [];
                foreach ($settings as $k => $v) {
                    if (str_starts_with($k, 'revenue_mapping.')) {
                        $map[substr($k, 16)] = $v;
                    }
                }
                return (object)$map;
            })(),
            JSON_HEX_TAG | JSON_FORCE_OBJECT
        ) ?>,

        // WHY: DB keys are accounting.default_depreciation_method, etc.
        // Stripped prefix = default_depreciation_method, default_useful_life_years, default_salvage_pct
        depreciation: {
            default_depreciation_method: <?= json_encode($settings['default_depreciation_method'] ?? 'straight_line') ?>,
            default_useful_life_years:   <?= json_encode((int)($settings['default_useful_life_years'] ?? 10)) ?>,
            default_salvage_pct:         <?= json_encode($settings['default_salvage_pct'] ?? '10') ?>
        },

        // WHY: DB keys are accounting.gst_filing_frequency, accounting.pst_filing_frequency
        // No depreciation account or tax rate/reminder settings in DB yet
        tax_filing: {
            gst_filing_frequency:  <?= json_encode($settings['gst_filing_frequency'] ?? 'quarterly') ?>,
            pst_filing_frequency:  <?= json_encode($settings['pst_filing_frequency'] ?? 'quarterly') ?>
        },

        // ── Saving state per tab ───────────────────────────────
        saving: { general: false, gl_mapping: false, revenue_mapping: false, depreciation: false, tax_filing: false },

        // ── Success messages per tab (errors now shown via form-error-banner + field-errors) ─
        messages: { general: null, gl_mapping: null, revenue_mapping: null, depreciation: null, tax_filing: null },

        // ── VALID-2: Per-tab banner errors and per-field errors ───
        formErrors: { general: '', gl_mapping: '', revenue_mapping: '', depreciation: '', tax_filing: '' },
        errors: {
            // general
            capex_threshold_cad: '',
            // depreciation
            default_depreciation_method: '', default_useful_life_years: '', default_salvage_pct: '',
            // tax_filing
            gst_filing_frequency: '', pst_filing_frequency: ''
        },

        // ── VALID-2: Error helpers ──────────────────────────────
        _extractError(r, fallback) {
            if (r && r.error) {
                if (typeof r.error === 'string') return r.error;
                if (r.error.message) return r.error.message;
            }
            if (r && r.message) return r.message;
            return fallback || 'Something went wrong.';
        },
        _clearErrorsForTab(tab) {
            this.formErrors[tab] = '';
            if (tab === 'general') {
                this.errors.capex_threshold_cad = '';
            } else if (tab === 'depreciation') {
                this.errors.default_depreciation_method = '';
                this.errors.default_useful_life_years = '';
                this.errors.default_salvage_pct = '';
            } else if (tab === 'tax_filing') {
                this.errors.gst_filing_frequency = '';
                this.errors.pst_filing_frequency = '';
            }
        },
        _paintServerErrorsForTab(tab, r, fallback) {
            // Map possible server field keys back to UI fields by stripping 'accounting.' prefix
            const fields = (r && r.error && r.error.fields) || (r && r.fields) || null;
            if (fields && typeof fields === 'object') {
                let any = false;
                for (const k in fields) {
                    if (!Object.prototype.hasOwnProperty.call(fields, k)) continue;
                    const msg = fields[k];
                    const bare = k.replace(/^accounting\./, '').replace(/^settings\./, '');
                    if (Object.prototype.hasOwnProperty.call(this.errors, bare)) {
                        this.errors[bare] = msg;
                        any = true;
                    } else if (bare === '_general' || bare === '') {
                        this.formErrors[tab] = msg;
                        any = true;
                    }
                }
                if (!any) {
                    this.formErrors[tab] = this._extractError(r, fallback);
                }
            } else {
                this.formErrors[tab] = this._extractError(r, fallback);
            }
        },

        // ── VALID-2: Per-tab client-side validators ─────────────
        validateGeneral() {
            this._clearErrorsForTab('general');
            let ok = true;
            const v = this.general.capex_threshold_cad;
            if (v === '' || v === null || v === undefined || isNaN(v)) {
                this.errors.capex_threshold_cad = 'CapEx threshold is required.';
                ok = false;
            } else if (Number(v) < 0) {
                this.errors.capex_threshold_cad = 'CapEx threshold must be zero or greater.';
                ok = false;
            } else if (!Number.isInteger(Number(v))) {
                this.errors.capex_threshold_cad = 'CapEx threshold must be a whole number.';
                ok = false;
            }
            return ok;
        },
        validateDepreciation() {
            this._clearErrorsForTab('depreciation');
            let ok = true;
            const methods = ['straight_line','declining_balance','double_declining','sum_of_years','units_of_production'];
            if (!this.depreciation.default_depreciation_method) {
                this.errors.default_depreciation_method = 'Default depreciation method is required.';
                ok = false;
            } else if (!methods.includes(this.depreciation.default_depreciation_method)) {
                this.errors.default_depreciation_method = 'Please select a valid depreciation method.';
                ok = false;
            }
            const years = this.depreciation.default_useful_life_years;
            if (years === '' || years === null || years === undefined || isNaN(years)) {
                this.errors.default_useful_life_years = 'Useful life is required.';
                ok = false;
            } else if (!Number.isInteger(Number(years))) {
                this.errors.default_useful_life_years = 'Useful life must be a whole number.';
                ok = false;
            } else if (Number(years) < 1 || Number(years) > 50) {
                this.errors.default_useful_life_years = 'Useful life must be between 1 and 50 years.';
                ok = false;
            }
            const salvage = String(this.depreciation.default_salvage_pct || '').trim();
            if (salvage === '') {
                this.errors.default_salvage_pct = 'Salvage percentage is required.';
                ok = false;
            } else if (!/^\d+(\.\d{1,2})?$/.test(salvage)) {
                this.errors.default_salvage_pct = 'Salvage percentage must be a number.';
                ok = false;
            } else if (Number(salvage) < 0 || Number(salvage) > 100) {
                this.errors.default_salvage_pct = 'Salvage percentage must be between 0 and 100.';
                ok = false;
            }
            return ok;
        },
        validateTaxFiling() {
            this._clearErrorsForTab('tax_filing');
            let ok = true;
            const freqs = ['monthly','quarterly','annually'];
            if (!this.tax_filing.gst_filing_frequency) {
                this.errors.gst_filing_frequency = 'GST filing frequency is required.';
                ok = false;
            } else if (!freqs.includes(this.tax_filing.gst_filing_frequency)) {
                this.errors.gst_filing_frequency = 'GST filing frequency must be monthly, quarterly, or annually.';
                ok = false;
            }
            if (!this.tax_filing.pst_filing_frequency) {
                this.errors.pst_filing_frequency = 'PST filing frequency is required.';
                ok = false;
            } else if (!freqs.includes(this.tax_filing.pst_filing_frequency)) {
                this.errors.pst_filing_frequency = 'PST filing frequency must be monthly, quarterly, or annually.';
                ok = false;
            }
            return ok;
        },

        // ── GL Mapping definitions ─────────────────────────────
        // WHY: key values MUST match DB column (minus 'accounting.' prefix)
        glMappings: [
            { key: 'ar_account_id',                label: 'Accounts Receivable (AR)',       types: ['asset'],     hint: 'Trade receivables from invoices' },
            { key: 'ap_account_id',                label: 'Accounts Payable (AP)',          types: ['liability'], hint: 'Trade payables to vendors' },
            { key: 'default_cash_account_id',      label: 'Cash / Bank Account',            types: ['asset'],     hint: 'Primary operating bank account' },
            { key: 'gst_payable_account_id',       label: 'GST Payable',                   types: ['liability'], hint: 'GST collected on sales' },
            { key: 'pst_payable_account_id',       label: 'PST Payable',                   types: ['liability'], hint: 'PST collected on sales' },
            { key: 'gst_receivable_account_id',    label: 'GST Receivable (ITC)',           types: ['asset'],     hint: 'Input tax credits from purchases' },
            { key: 'bad_debt_expense_account_id',  label: 'Bad Debt Expense',              types: ['operating_expense'], hint: 'Written-off uncollectible receivables' },
            { key: 'fx_gain_account_id',           label: 'FX Gain',                       types: ['other_income'],     hint: 'Unrealized/realized foreign exchange gains' },
            { key: 'fx_loss_account_id',           label: 'FX Loss',                       types: ['other_expense'],    hint: 'Unrealized/realized foreign exchange losses' },
            { key: 'retained_earnings_account_id', label: 'Retained Earnings',             types: ['equity'],    hint: 'Accumulated net income from prior years' },
            { key: 'current_year_ni_account_id',   label: 'Current Year Net Income',       types: ['equity'],    hint: 'Current fiscal year earnings' },
            { key: 'customer_deposits_account_id', label: 'Customer Deposits',             types: ['liability'], hint: 'Deposits received from customers' },
            { key: 'customer_credits_account_id',  label: 'Customer Credits',              types: ['liability'], hint: 'Credit notes applied to customer accounts' }
        ],

        // ── Computed: revenue-type accounts ────────────────────
        get revenueAccounts() {
            return this.glAccounts.filter(a => a.account_type === 'revenue');
        },

        init() {
            // WHY: Component is fully initialized from server-rendered data.
            // No API call needed on load.
        },

        // ── Filter accounts by type for dropdowns ──────────────
        glAccountsByType(types) {
            return this.glAccounts.filter(a => types.includes(a.account_type));
        },

        // ── Generic save helper ────────────────────────────────
        async _save(tab, data) {
            this.saving[tab] = true;
            this.messages[tab] = null;

            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/accounting/settings/update'), {
                    settings: data
                });
                if (r.success) {
                    this._clearErrorsForTab(tab);
                    this.messages[tab] = { type: 'success', text: 'Settings saved successfully.' };
                } else {
                    this._paintServerErrorsForTab(tab, r, 'Failed to save settings.');
                }
            } catch(e) {
                this.formErrors[tab] = 'Network error. Please try again.';
            }

            this.saving[tab] = false;

            // WHY: Auto-clear success messages after 5 seconds to keep UI clean.
            if (this.messages[tab] && this.messages[tab].type === 'success') {
                setTimeout(() => { this.messages[tab] = null; }, 5000);
            }
        },

        // ── Tab-specific save methods ──────────────────────────
        // WHY: Save keys MUST exactly match DB `key` column values.
        // The API update endpoint matches on the exact key.
        saveGeneral() {
            if (!this.validateGeneral()) return;
            this._save('general', {
                'accounting.enabled':              this.general.enabled ? 'true' : 'false',
                'accounting.capex_threshold_cad':  String(this.general.capex_threshold_cad)
            });
        },

        saveGlMapping() {
            this._clearErrorsForTab('gl_mapping');
            const data = {};
            for (const m of this.glMappings) {
                data['accounting.' + m.key] = String(this.gl_mapping[m.key] || '');
            }
            this._save('gl_mapping', data);
        },

        saveRevenueMapping() {
            this._clearErrorsForTab('revenue_mapping');
            const data = {};
            for (const lt of this.lineTypes) {
                data['accounting.revenue_mapping.' + lt] = String(this.revenue_mapping[lt] || '');
            }
            this._save('revenue_mapping', data);
        },

        saveDepreciation() {
            if (!this.validateDepreciation()) return;
            this._save('depreciation', {
                'accounting.default_depreciation_method':  this.depreciation.default_depreciation_method,
                'accounting.default_useful_life_years':    String(this.depreciation.default_useful_life_years),
                'accounting.default_salvage_pct':          String(this.depreciation.default_salvage_pct)
            });
        },

        saveTaxFiling() {
            if (!this.validateTaxFiling()) return;
            this._save('tax_filing', {
                'accounting.gst_filing_frequency':  this.tax_filing.gst_filing_frequency,
                'accounting.pst_filing_frequency':  this.tax_filing.pst_filing_frequency
            });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
