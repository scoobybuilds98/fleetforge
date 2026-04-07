<?php declare(strict_types=1);

/**
 * FleetForge — Tax Filing Periods (index)
 *
 * @file        app/admin/accounting/tax/index.php
 * @description Tax management dashboard. KPI tiles show open / calculated /
 *              filed / remitted period counts and YTD GST owed.
 *              Filter by tax_type, status, year. Data table lists every
 *              filing period with status, period span, totals, due date,
 *              and actions ("Calculate", "View", "Mark Filed", "Remit").
 *              "+ New Period" opens a modal that creates a new period
 *              (tax_type / period_start / period_end / frequency / notes).
 *              All state-changing actions go through TaxFilingService.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/tax/**
 *
 * @session     S035 — Tax Management module
 */

// dirname(__DIR__, 4): tax/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('tax_management', 'view');

// ── KPI tiles — server-side counts ────────────────────────────
// Fast SUM/COUNT queries on the small acc_tax_filing_periods table.
// We render numbers immediately so the page is meaningful even
// before Alpine resolves the API list call.
$openCount       = db_count("SELECT COUNT(*) FROM acc_tax_filing_periods WHERE status = 'open'");
$calculatedCount = db_count("SELECT COUNT(*) FROM acc_tax_filing_periods WHERE status = 'calculated'");
$filedCount      = db_count("SELECT COUNT(*) FROM acc_tax_filing_periods WHERE status = 'filed'");
$ytdNetOwing     = db_row(
    "SELECT COALESCE(SUM(net_tax_owing), 0) AS total
     FROM acc_tax_filing_periods
     WHERE tax_type = 'gst_hst'
       AND status IN ('calculated','filed','remitted')
       AND YEAR(period_end) = YEAR(CURDATE())"
)['total'] ?? '0.00';

// Distinct years for filter dropdown — covers all periods on file.
$years = db_select(
    "SELECT DISTINCT YEAR(period_end) AS y
     FROM acc_tax_filing_periods
     ORDER BY y DESC"
);

$pageTitle = 'Tax Management';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────── -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Tax Management</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Tax Management</h1>
    <div class="page-header-actions">
        <?php if (can('tax_management', 'create')): ?>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-tax-create')">
            + New Filing Period
        </button>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ── KPI Tiles ───────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Open Periods</div>
        <div class="stat-value font-mono"><?= e((string) $openCount) ?></div>
    </div>
    <div class="stat-card stat-card--amber">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Calculated</div>
        <div class="stat-value font-mono"><?= e((string) $calculatedCount) ?></div>
    </div>
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Filed (Awaiting Remit)</div>
        <div class="stat-value font-mono"><?= e((string) $filedCount) ?></div>
    </div>
    <div class="stat-card">
        <span class="stat-icon"><svg><use href="#icon-banknotes"/></svg></span>
        <div class="stat-label">YTD GST/HST Net Owing</div>
        <div class="stat-value font-mono">$<?= number_format((float) $ytdNetOwing, 2) ?></div>
    </div>
</div>

<!-- ============================================================
     TAX PERIODS — ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_TaxPeriods()" x-init="init()" @open-tax-create.window="openCreate()">

    <!-- ── Filter toolbar ────────────────────────────────────── -->
    <div class="table-toolbar" style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select class="form-select form-control-sm" x-model="filterTaxType" @change="reload()" style="min-width:160px;">
            <option value="">All tax types</option>
            <option value="gst_hst">GST / HST</option>
            <option value="pst_bc">PST — BC</option>
            <option value="pst_sk">PST — SK</option>
            <option value="pst_mb">PST — MB</option>
        </select>

        <select class="form-select form-control-sm" x-model="filterStatus" @change="reload()" style="min-width:140px;">
            <option value="">All statuses</option>
            <option value="open">Open</option>
            <option value="calculated">Calculated</option>
            <option value="filed">Filed</option>
            <option value="remitted">Remitted</option>
        </select>

        <select class="form-select form-control-sm" x-model="filterYear" @change="reload()" style="min-width:120px;">
            <option value="">All years</option>
            <?php foreach ($years as $y): ?>
                <option value="<?= e((string) $y['y']) ?>"><?= e((string) $y['y']) ?></option>
            <?php endforeach; ?>
        </select>

        <span class="text-secondary text-sm" x-text="pagination.total + ' period' + (pagination.total !== 1 ? 's' : '')"></span>
    </div>

    <!-- ── Loading state ─────────────────────────────────────── -->
    <template x-if="loading">
        <div class="card"><div class="empty-state">Loading…</div></div>
    </template>

    <!-- ── Empty state ───────────────────────────────────────── -->
    <template x-if="!loading && periods.length === 0">
        <div class="card"><div class="empty-state">
            <p class="empty-state-title">No tax filing periods yet</p>
            <p class="empty-state-text">Click "+ New Filing Period" to create your first GST/HST or PST period.</p>
        </div></div>
    </template>

    <!-- ── Periods table ─────────────────────────────────────── -->
    <template x-if="!loading && periods.length > 0">
        <div class="card" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tax Type</th>
                        <th>Period</th>
                        <th>Frequency</th>
                        <th>Status</th>
                        <th class="text-right">Sales</th>
                        <th class="text-right">Tax Collected</th>
                        <th class="text-right">ITC</th>
                        <th class="text-right">Net Owing</th>
                        <th>Due</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="p in periods" :key="p.id">
                        <tr>
                            <td><span class="badge badge-no-dot badge-blue" x-text="formatTaxType(p.tax_type)"></span></td>
                            <td class="text-sm" x-text="p.period_start + ' → ' + p.period_end"></td>
                            <td class="text-sm" x-text="p.frequency"></td>
                            <td>
                                <span class="badge badge-no-dot" :class="statusBadge(p.status)" x-text="p.status"></span>
                            </td>
                            <td class="text-right font-mono" x-text="formatMoney(p.total_sales)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(p.total_tax_collected)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(p.total_itc)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(p.net_tax_owing)"></td>
                            <td class="text-sm" x-text="p.filing_due_date || '—'"></td>
                            <td>
                                <a class="btn btn-secondary btn-xs"
                                   :href="'<?= base_url('accounting/tax/show.php') ?>?id=' + p.id">View</a>
                                <?php if (can('tax_management', 'edit')): ?>
                                <template x-if="p.status !== 'remitted'">
                                    <button class="btn btn-primary btn-xs" @click="calculate(p.id)">Calc</button>
                                </template>
                                <template x-if="p.status === 'calculated'">
                                    <button class="btn btn-success btn-xs" @click="openMarkFiled(p)">File</button>
                                </template>
                                <template x-if="p.status === 'filed'">
                                    <button class="btn btn-warning btn-xs" @click="openRemit(p)">Remit</button>
                                </template>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <!-- ── Pagination ────────────────────────────────────────── -->
    <div class="table-pagination" x-show="pagination.total_pages > 1" style="margin-top:16px;text-align:center;">
        <button class="btn btn-secondary btn-sm" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">‹ Prev</button>
        <span x-text="'Page ' + pagination.page + ' of ' + pagination.total_pages" style="margin:0 12px;"></span>
        <button class="btn btn-secondary btn-sm" :disabled="!pagination.has_more" @click="goPage(pagination.page + 1)">Next ›</button>
    </div>

    <!-- ============================================================
         CREATE MODAL — new tax filing period
         ============================================================ -->
    <div class="modal-backdrop" x-show="createOpen" x-transition @click.self="createOpen = false" style="display:none;">
        <div class="modal" style="max-width:560px;width:95%;">
            <div class="modal-header">
                <h2 class="h5">New Tax Filing Period</h2>
                <button class="modal-close" @click="createOpen = false">×</button>
            </div>
            <div class="modal-body">
                <div class="form-error-banner" x-show="createFormError" x-cloak x-text="createFormError"></div>
                <div class="form-group">
                    <label>Tax Type *</label>
                    <select class="form-select form-control-sm" x-model="createForm.tax_type"
                            :class="createErrors.tax_type ? 'is-invalid' : ''"
                            @change="createErrors.tax_type = ''">
                        <option value="">— Choose tax type —</option>
                        <option value="gst_hst">GST / HST (federal)</option>
                        <option value="pst_bc">PST — British Columbia</option>
                        <option value="pst_sk">PST — Saskatchewan</option>
                        <option value="pst_mb">PST — Manitoba (RST)</option>
                    </select>
                    <div class="field-error" x-show="createErrors.tax_type" x-cloak x-text="createErrors.tax_type"></div>
                </div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Period Start *</label>
                        <input type="date" class="form-control form-control-sm" x-model="createForm.period_start"
                               :class="createErrors.period_start ? 'is-invalid' : ''"
                               @input="createErrors.period_start = ''">
                        <div class="field-error" x-show="createErrors.period_start" x-cloak x-text="createErrors.period_start"></div>
                    </div>
                    <div class="form-group">
                        <label>Period End *</label>
                        <input type="date" class="form-control form-control-sm" x-model="createForm.period_end"
                               :class="createErrors.period_end ? 'is-invalid' : ''"
                               @input="createErrors.period_end = ''">
                        <div class="field-error" x-show="createErrors.period_end" x-cloak x-text="createErrors.period_end"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Frequency *</label>
                    <select class="form-select form-control-sm" x-model="createForm.frequency"
                            :class="createErrors.frequency ? 'is-invalid' : ''"
                            @change="createErrors.frequency = ''">
                        <option value="">— Choose frequency —</option>
                        <option value="monthly">Monthly (filing due 1 month after end)</option>
                        <option value="quarterly">Quarterly (filing due 1 month after end)</option>
                        <option value="annually">Annually (filing due 3 months after end)</option>
                    </select>
                    <div class="field-error" x-show="createErrors.frequency" x-cloak x-text="createErrors.frequency"></div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea class="form-control form-control-sm" rows="2" x-model="createForm.notes"
                              :class="createErrors.notes ? 'is-invalid' : ''"
                              @input="createErrors.notes = ''"
                              placeholder="Optional internal notes..."></textarea>
                    <div class="field-error" x-show="createErrors.notes" x-cloak x-text="createErrors.notes"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="createOpen = false">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="submitCreate()" :disabled="createBusy">
                    <span x-show="!createBusy">Create Period</span>
                    <span x-show="createBusy">Creating…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MARK FILED MODAL
         ============================================================ -->
    <div class="modal-backdrop" x-show="filedOpen" x-transition @click.self="filedOpen = false" style="display:none;">
        <div class="modal" style="max-width:480px;width:95%;">
            <div class="modal-header">
                <h2 class="h5">Mark Period as Filed</h2>
                <button class="modal-close" @click="filedOpen = false">×</button>
            </div>
            <div class="modal-body">
                <div class="form-error-banner" x-show="filedFormError" x-cloak x-text="filedFormError"></div>
                <p class="text-sm" x-text="filedTarget ? formatTaxType(filedTarget.tax_type) + ' ' + filedTarget.period_start + ' → ' + filedTarget.period_end : ''"></p>
                <p class="text-secondary text-sm">Records who filed it and when. Does <strong>not</strong> post any JE — payment is recorded separately via "Remit".</p>
                <div class="form-group">
                    <label>Filed Date *</label>
                    <input type="date" class="form-control form-control-sm" x-model="filedForm.filed_date"
                           :class="filedErrors.filed_date ? 'is-invalid' : ''"
                           @input="filedErrors.filed_date = ''">
                    <div class="field-error" x-show="filedErrors.filed_date" x-cloak x-text="filedErrors.filed_date"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="filedOpen = false">Cancel</button>
                <button class="btn btn-success btn-sm" @click="submitMarkFiled()" :disabled="filedBusy">
                    <span x-show="!filedBusy">Mark Filed</span>
                    <span x-show="filedBusy">Saving…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         REMIT MODAL — record CRA remittance + post JE
         ============================================================ -->
    <div class="modal-backdrop" x-show="remitOpen" x-transition @click.self="remitOpen = false" style="display:none;">
        <div class="modal" style="max-width:560px;width:95%;">
            <div class="modal-header">
                <h2 class="h5">Record Tax Remittance</h2>
                <button class="modal-close" @click="remitOpen = false">×</button>
            </div>
            <div class="modal-body">
                <div class="form-error-banner" x-show="remitFormError" x-cloak x-text="remitFormError"></div>
                <p class="text-sm" x-text="remitTarget ? formatTaxType(remitTarget.tax_type) + ' ' + remitTarget.period_start + ' → ' + remitTarget.period_end : ''"></p>
                <p class="text-secondary text-sm">Records the CRA / authority payment and posts a JE: <code>DR Tax Payable / CR Cash</code>. The period status flips to <strong>remitted</strong>.</p>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Remittance Date *</label>
                        <input type="date" class="form-control form-control-sm" x-model="remitForm.remittance_date"
                               :class="remitErrors.remittance_date ? 'is-invalid' : ''"
                               @input="remitErrors.remittance_date = ''">
                        <div class="field-error" x-show="remitErrors.remittance_date" x-cloak x-text="remitErrors.remittance_date"></div>
                    </div>
                    <div class="form-group">
                        <label>Amount *</label>
                        <input type="number" step="0.01" min="0.01" class="form-control form-control-sm" x-model="remitForm.amount"
                               :class="remitErrors.amount ? 'is-invalid' : ''"
                               @input="remitErrors.amount = ''">
                        <div class="field-error" x-show="remitErrors.amount" x-cloak x-text="remitErrors.amount"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Payment Method *</label>
                    <select class="form-select form-control-sm" x-model="remitForm.payment_method"
                            :class="remitErrors.payment_method ? 'is-invalid' : ''"
                            @change="remitErrors.payment_method = ''">
                        <option value="">— Choose method —</option>
                        <option value="online_banking">Online Banking</option>
                        <option value="check">Cheque</option>
                        <option value="wire">Wire Transfer</option>
                        <option value="other">Other</option>
                    </select>
                    <div class="field-error" x-show="remitErrors.payment_method" x-cloak x-text="remitErrors.payment_method"></div>
                </div>
                <div class="form-group">
                    <label>Bank Account</label>
                    <select class="form-select form-control-sm" x-model="remitForm.bank_account_id"
                            :class="remitErrors.bank_account_id ? 'is-invalid' : ''"
                            @change="remitErrors.bank_account_id = ''">
                        <option value="">— Default Cash 1010 —</option>
                        <template x-for="b in bankAccounts" :key="b.id">
                            <option :value="b.id" x-text="b.name"></option>
                        </template>
                    </select>
                    <div class="field-error" x-show="remitErrors.bank_account_id" x-cloak x-text="remitErrors.bank_account_id"></div>
                </div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Reference Number</label>
                        <input type="text" class="form-control form-control-sm" x-model="remitForm.reference_number"
                               :class="remitErrors.reference_number ? 'is-invalid' : ''"
                               @input="remitErrors.reference_number = ''"
                               placeholder="CRA confirmation #">
                        <div class="field-error" x-show="remitErrors.reference_number" x-cloak x-text="remitErrors.reference_number"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea class="form-control form-control-sm" rows="2" x-model="remitForm.notes"
                              :class="remitErrors.notes ? 'is-invalid' : ''"
                              @input="remitErrors.notes = ''"></textarea>
                    <div class="field-error" x-show="remitErrors.notes" x-cloak x-text="remitErrors.notes"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="remitOpen = false">Cancel</button>
                <button class="btn btn-warning btn-sm" @click="submitRemit()" :disabled="remitBusy">
                    <span x-show="!remitBusy">Record &amp; Post JE</span>
                    <span x-show="remitBusy">Posting…</span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_TaxPeriods() {
    return {
        // ── State ──────────────────────────────────────────────
        periods: [],
        bankAccounts: [],
        loading: true,
        filterTaxType: '',
        filterStatus: '',
        filterYear: '',
        pagination: { total: 0, page: 1, per_page: 25, total_pages: 0, has_more: false },

        // ── Modals ─────────────────────────────────────────────
        createOpen: false,
        createBusy: false,
        createForm: { tax_type: '', period_start: '', period_end: '', frequency: '', notes: '' },
        createFormError: '',
        createErrors: { tax_type: '', period_start: '', period_end: '', frequency: '', notes: '' },

        filedOpen: false,
        filedBusy: false,
        filedTarget: null,
        filedForm: { filed_date: new Date().toISOString().slice(0, 10) },
        filedFormError: '',
        filedErrors: { filed_date: '' },

        remitOpen: false,
        remitBusy: false,
        remitTarget: null,
        remitForm: {
            remittance_date: new Date().toISOString().slice(0, 10),
            amount: '',
            payment_method: '',
            bank_account_id: '',
            reference_number: '',
            notes: '',
        },
        remitFormError: '',
        remitErrors: { remittance_date: '', amount: '', payment_method: '', bank_account_id: '', reference_number: '', notes: '' },

        // ── VALID-2: Error helpers ──────────────────────────────
        _extractError(r, fallback) {
            if (r && r.error) {
                if (typeof r.error === 'string') return r.error;
                if (r.error.message) return r.error.message;
            }
            if (r && r.message) return r.message;
            return fallback || 'Something went wrong.';
        },
        _clearErrors(errorsObj, bannerKey) {
            this[bannerKey] = '';
            for (const k in errorsObj) {
                if (Object.prototype.hasOwnProperty.call(errorsObj, k)) errorsObj[k] = '';
            }
        },
        _paintErrors(errorsObj, bannerKey, r, fallback) {
            const fields = (r && r.error && r.error.fields) || (r && r.fields) || null;
            if (fields && typeof fields === 'object') {
                let any = false;
                for (const k in fields) {
                    if (!Object.prototype.hasOwnProperty.call(fields, k)) continue;
                    if (Object.prototype.hasOwnProperty.call(errorsObj, k)) {
                        errorsObj[k] = fields[k];
                        any = true;
                    } else if (k === '_general' || k === '') {
                        this[bannerKey] = fields[k];
                        any = true;
                    }
                }
                if (!any) this[bannerKey] = this._extractError(r, fallback);
            } else {
                this[bannerKey] = this._extractError(r, fallback);
            }
        },

        // ── VALID-2: Client-side validators ────────────────────
        validateCreate() {
            this._clearErrors(this.createErrors, 'createFormError');
            let ok = true;
            const f = this.createForm;
            const taxTypes = ['gst_hst','pst_bc','pst_sk','pst_mb'];
            if (!f.tax_type) {
                this.createErrors.tax_type = 'Tax type is required.';
                ok = false;
            } else if (!taxTypes.includes(f.tax_type)) {
                this.createErrors.tax_type = 'Tax type must be one of: gst_hst, pst_bc, pst_sk, pst_mb.';
                ok = false;
            }
            if (!f.period_start) {
                this.createErrors.period_start = 'Period start date is required.';
                ok = false;
            }
            if (!f.period_end) {
                this.createErrors.period_end = 'Period end date is required.';
                ok = false;
            }
            if (f.period_start && f.period_end && f.period_end < f.period_start) {
                this.createErrors.period_end = 'Period end must be on or after period start.';
                ok = false;
            }
            const freqs = ['monthly','quarterly','annually'];
            if (!f.frequency) {
                this.createErrors.frequency = 'Frequency is required.';
                ok = false;
            } else if (!freqs.includes(f.frequency)) {
                this.createErrors.frequency = 'Frequency must be monthly, quarterly, or annually.';
                ok = false;
            }
            return ok;
        },
        validateMarkFiled() {
            this._clearErrors(this.filedErrors, 'filedFormError');
            let ok = true;
            if (!this.filedForm.filed_date) {
                this.filedErrors.filed_date = 'Filed date is required.';
                ok = false;
            }
            return ok;
        },
        validateRemit() {
            this._clearErrors(this.remitErrors, 'remitFormError');
            let ok = true;
            const f = this.remitForm;
            if (!f.remittance_date) {
                this.remitErrors.remittance_date = 'Remittance date is required.';
                ok = false;
            }
            const amt = String(f.amount || '').trim();
            if (!amt) {
                this.remitErrors.amount = 'Amount is required.';
                ok = false;
            } else if (!/^\d+(\.\d{1,2})?$/.test(amt)) {
                this.remitErrors.amount = 'Amount must be a valid amount.';
                ok = false;
            } else if (parseFloat(amt) <= 0) {
                this.remitErrors.amount = 'Amount must be greater than zero.';
                ok = false;
            }
            const methods = ['online_banking','check','wire','other'];
            if (!f.payment_method) {
                this.remitErrors.payment_method = 'Payment method is required.';
                ok = false;
            } else if (!methods.includes(f.payment_method)) {
                this.remitErrors.payment_method = 'Payment method must be one of: online_banking, check, wire, other.';
                ok = false;
            }
            return ok;
        },

        // ── Init ───────────────────────────────────────────────
        async init() {
            await Promise.all([this.loadBankAccounts(), this.load()]);
        },

        async loadBankAccounts() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/bank-accounts/index.php') ?>');
                if (r.success) {
                    this.bankAccounts = (r.data.items || r.data || []).filter(b => b.is_active != 0);
                }
            } catch (e) { /* non-fatal */ }
        },

        // ── Load periods ───────────────────────────────────────
        async load() {
            this.loading = true;
            const params = new URLSearchParams();
            if (this.filterTaxType) params.set('tax_type', this.filterTaxType);
            if (this.filterStatus)  params.set('status', this.filterStatus);
            if (this.filterYear)    params.set('year', this.filterYear);
            params.set('page', String(this.pagination.page));
            params.set('per_page', String(this.pagination.per_page));
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/tax/periods/index.php') ?>?' + params.toString());
                if (r.success) {
                    this.periods = r.data.items;
                    this.pagination = r.data.pagination;
                } else {
                    FF_Toast.error(r.error?.message || 'Failed to load periods.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
            this.loading = false;
        },

        reload() { this.pagination.page = 1; this.load(); },
        goPage(p) { this.pagination.page = p; this.load(); },

        // ── Create ─────────────────────────────────────────────
        openCreate() {
            this.createForm = { tax_type: '', period_start: '', period_end: '', frequency: '', notes: '' };
            this._clearErrors(this.createErrors, 'createFormError');
            this.createOpen = true;
        },
        async submitCreate() {
            if (!this.validateCreate()) return;
            this.createBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/tax/periods/create.php') ?>', this.createForm);
                if (r.success) {
                    FF_Toast.success('Period created.');
                    this.createOpen = false;
                    await this.load();
                } else {
                    this._paintErrors(this.createErrors, 'createFormError', r, 'Failed to create period.');
                }
            } catch (e) { this.createFormError = 'Network error. Please try again.'; }
            this.createBusy = false;
        },

        // ── Calculate ──────────────────────────────────────────
        async calculate(id) {
            if (!confirm('Recalculate this period from posted GL entries? Filed periods can be recalculated until remitted.')) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/tax/periods/calculate.php') ?>', { id });
                if (r.success) {
                    FF_Toast.success('Recalculated. Net owing $' + parseFloat(r.data.net_tax_owing).toFixed(2));
                    await this.load();
                } else {
                    FF_Toast.error(r.error?.message || 'Calculation failed.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
        },

        // ── Mark filed ─────────────────────────────────────────
        openMarkFiled(p) {
            this.filedTarget = p;
            this.filedForm = { filed_date: new Date().toISOString().slice(0, 10) };
            this._clearErrors(this.filedErrors, 'filedFormError');
            this.filedOpen  = true;
        },
        async submitMarkFiled() {
            if (!this.validateMarkFiled()) return;
            this.filedBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/tax/periods/mark_filed.php') ?>', {
                    id:         this.filedTarget.id,
                    filed_date: this.filedForm.filed_date,
                    updated_at: this.filedTarget.updated_at,
                });
                if (r.success) {
                    FF_Toast.success('Marked filed.');
                    this.filedOpen = false;
                    await this.load();
                } else {
                    this._paintErrors(this.filedErrors, 'filedFormError', r, 'Failed to mark period filed.');
                }
            } catch (e) { this.filedFormError = 'Network error. Please try again.'; }
            this.filedBusy = false;
        },

        // ── Remit ──────────────────────────────────────────────
        openRemit(p) {
            this.remitTarget = p;
            // Pre-fill amount with the period's net_tax_owing as a convenience.
            this.remitForm = {
                remittance_date: new Date().toISOString().slice(0, 10),
                amount: parseFloat(p.net_tax_owing || 0).toFixed(2),
                payment_method: '',
                bank_account_id: '',
                reference_number: '',
                notes: '',
            };
            this._clearErrors(this.remitErrors, 'remitFormError');
            this.remitOpen = true;
        },
        async submitRemit() {
            if (!this.validateRemit()) return;
            this.remitBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/tax/remittances/create.php') ?>', {
                    filing_period_id: this.remitTarget.id,
                    remittance_date:  this.remitForm.remittance_date,
                    amount:           this.remitForm.amount,
                    payment_method:   this.remitForm.payment_method,
                    bank_account_id:  this.remitForm.bank_account_id || null,
                    reference_number: this.remitForm.reference_number,
                    notes:            this.remitForm.notes,
                    updated_at:       this.remitTarget.updated_at,
                });
                if (r.success) {
                    FF_Toast.success('Remittance recorded. JE #' + r.data.journal_entry_id);
                    this.remitOpen = false;
                    await this.load();
                } else {
                    this._paintErrors(this.remitErrors, 'remitFormError', r, 'Failed to record remittance.');
                }
            } catch (e) { this.remitFormError = 'Network error. Please try again.'; }
            this.remitBusy = false;
        },

        // ── Display helpers ────────────────────────────────────
        formatMoney(s) {
            if (s === null || s === undefined || s === '') return '—';
            return '$' + parseFloat(s).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        formatTaxType(t) {
            const map = { gst_hst: 'GST/HST', pst_bc: 'PST-BC', pst_sk: 'PST-SK', pst_mb: 'PST-MB' };
            return map[t] || t;
        },
        statusBadge(s) {
            const m = { open: 'badge-blue', calculated: 'badge-amber', filed: 'badge-green', remitted: 'badge-neutral' };
            return m[s] || 'badge-neutral';
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
