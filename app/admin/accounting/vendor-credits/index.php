<?php declare(strict_types=1);

/**
 * app/admin/accounting/vendor-credits/index.php
 *
 * Vendor credits management — list, create, and apply credits to bills.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @session S032
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('accounts_payable', 'view');

$vendors = db_select(
    "SELECT id, name FROM vendors WHERE deleted_at IS NULL ORDER BY name", []
);
$glAccounts = db_select(
    "SELECT id, code, name FROM acc_accounts WHERE is_active = 1 AND is_header = 0 ORDER BY code", []
);

$canCreate = can('accounts_payable', 'create');
$canEdit   = can('accounts_payable', 'edit');

$pageTitle = 'Vendor Credits';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Vendor Credits</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Vendor Credits</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="vendorCreditsPage()" x-init="load()">
    <div style="display:flex;justify-content:space-between;align-items:end;margin-bottom:16px;">
        <div style="display:flex;gap:6px;">
            <template x-for="t in tabs" :key="t.value">
                <button class="btn btn-sm" :class="tab === t.value ? 'btn-primary' : 'btn-secondary'" @click="tab = t.value; load()" x-text="t.label"></button>
            </template>
        </div>
        <div style="display:flex;gap:8px;">
            <select x-model="filterVendor" @change="load()" class="form-input" style="padding:7px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <option value="">All Vendors</option>
                <?php foreach ($vendors as $v): ?>
                <option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($canCreate): ?>
            <button class="btn btn-primary btn-sm" @click="showCreate = !showCreate">+ New Credit</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI Tiles -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Active Credits</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-accent);" x-text="fmtAmt(kpi.active)"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Applied</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-success);" x-text="fmtAmt(kpi.applied)"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Count</div>
            <div style="font-size:1.125rem;font-weight:700;color:var(--text-primary);" x-text="totalRows"></div>
        </div>
    </div>

    <!-- Create Form -->
    <template x-if="showCreate">
        <div class="card" style="padding:16px;margin-bottom:16px;">
            <div style="font-weight:600;font-size:0.875rem;margin-bottom:12px;">New Vendor Credit</div>
            <div class="form-error-banner" x-show="createFormError" x-cloak x-text="createFormError" style="margin-bottom:10px;"></div>
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr 2fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Vendor *</label>
                    <select x-model="createForm.vendor_id" :class="createErrors.vendor_id ? 'is-invalid' : ''" @change="createErrors.vendor_id = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                        <option value="">Select vendor...</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-error" x-show="createErrors.vendor_id" x-cloak x-text="createErrors.vendor_id"></div>
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Amount *</label>
                    <input type="number" x-model="createForm.amount" step="0.01" min="0.01" :class="createErrors.amount ? 'is-invalid' : ''" @input="createErrors.amount = ''" class="form-input font-mono" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                    <div class="field-error" x-show="createErrors.amount" x-cloak x-text="createErrors.amount"></div>
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Date *</label>
                    <input type="date" x-model="createForm.credit_date" :class="createErrors.credit_date ? 'is-invalid' : ''" @change="createErrors.credit_date = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                    <div class="field-error" x-show="createErrors.credit_date" x-cloak x-text="createErrors.credit_date"></div>
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Reason *</label>
                    <input type="text" x-model="createForm.reason" :class="createErrors.reason ? 'is-invalid' : ''" @input="createErrors.reason = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);" placeholder="e.g. Return, overcharge">
                    <div class="field-error" x-show="createErrors.reason" x-cloak x-text="createErrors.reason"></div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Expense Account (credit reversal)</label>
                    <select x-model="createForm.expense_account_id" :class="createErrors.expense_account_id ? 'is-invalid' : ''" @change="createErrors.expense_account_id = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                        <option value="">Auto (from AP)</option>
                        <?php foreach ($glAccounts as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' — ' . $a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-error" x-show="createErrors.expense_account_id" x-cloak x-text="createErrors.expense_account_id"></div>
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Notes</label>
                    <input type="text" x-model="createForm.notes" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-success btn-sm" @click="submitCreate()" :disabled="creating">Create Credit</button>
                <button class="btn btn-secondary btn-sm" @click="cancelCreate()">Cancel</button>
            </div>
        </div>
    </template>

    <template x-if="loading">
        <div class="card" style="padding:48px;text-align:center;">
            <div class="spinner" style="margin:0 auto 12px;"></div>
        </div>
    </template>

    <template x-if="!loading && credits.length > 0">
        <div class="card" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Credit #</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Vendor</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Date</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Reason</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Amount</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Remaining</th>
                        <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Status</th>
                        <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="c in credits" :key="c.id">
                        <tr style="border-bottom:1px solid var(--border-default);">
                            <td class="font-mono" style="padding:8px 12px;font-weight:500;" x-text="c.credit_number"></td>
                            <td style="padding:8px 12px;" x-text="c.vendor_name"></td>
                            <td class="font-mono" style="padding:8px 12px;" x-text="c.credit_date"></td>
                            <td style="padding:8px 12px;color:var(--text-secondary);" x-text="c.reason"></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="fmtAmt(c.amount)"></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;font-weight:600;" x-text="fmtAmt(c.amount_remaining)"></td>
                            <td style="padding:8px 12px;text-align:center;">
                                <span class="badge" :class="creditBadge(c.status)" x-text="c.status.replace('_',' ')"></span>
                            </td>
                            <td style="padding:8px 12px;text-align:center;">
                                <?php if ($canEdit): ?>
                                <template x-if="c.status === 'active' || c.status === 'partially_used'">
                                    <button class="btn btn-primary btn-xs" @click="openApply(c)">Apply to Bill</button>
                                </template>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <template x-if="!loading && credits.length === 0">
        <div class="card" style="padding:48px;text-align:center;">
            <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">No Vendor Credits</div>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">Record vendor credits as they are received.</div>
        </div>
    </template>

    <!-- Apply Modal -->
    <div x-show="showApply" x-cloak style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);"
         @click.self="showApply = false">
            <div class="card" style="width:100%;max-width:480px;padding:24px;" x-show="showApply" x-transition>
                <div style="font-weight:700;font-size:1rem;margin-bottom:12px;">Apply Credit to Bill</div>
                <div style="font-size:0.8125rem;color:var(--text-secondary);margin-bottom:12px;">
                    Credit: <span class="font-mono" x-text="applyForm.credit_number"></span> — Available: <span class="font-mono" x-text="fmtAmt(applyForm.amount_remaining)"></span>
                </div>
                <div class="form-error-banner" x-show="applyFormError" x-cloak x-text="applyFormError" style="margin-bottom:10px;"></div>
                <div style="margin-bottom:12px;">
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Bill *</label>
                    <select x-model="applyForm.bill_id" :class="applyErrors.bill_id ? 'is-invalid' : ''" @change="applyErrors.bill_id = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                        <option value="">Select bill...</option>
                        <template x-for="b in vendorBills" :key="b.id">
                            <option :value="b.id" x-text="b.bill_number + ' — $' + Number(b.balance_due).toFixed(2)"></option>
                        </template>
                    </select>
                    <div class="field-error" x-show="applyErrors.bill_id" x-cloak x-text="applyErrors.bill_id"></div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Amount to Apply *</label>
                    <input type="number" x-model="applyForm.amount_applied" step="0.01" min="0.01" :class="applyErrors.amount_applied ? 'is-invalid' : ''" @input="applyErrors.amount_applied = ''" class="form-input font-mono" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                    <div class="field-error" x-show="applyErrors.amount_applied" x-cloak x-text="applyErrors.amount_applied"></div>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button class="btn btn-secondary btn-sm" @click="showApply = false">Cancel</button>
                    <button class="btn btn-success btn-sm" @click="submitApply()" :disabled="applying">Apply</button>
                </div>
            </div>
    </div>
</div>

<script>
function vendorCreditsPage() {
    return {
        credits: [], loading: false, totalRows: 0,
        tab: '', filterVendor: '',
        kpi: { active: '0.00', applied: '0.00' },
        showCreate: false, creating: false,
        showApply: false, applying: false,
        vendorBills: [],
        createForm: { vendor_id: '', amount: '', credit_date: new Date().toISOString().slice(0,10), reason: '', expense_account_id: '', notes: '' },
        applyForm: {},

        // VALID-2 per-form error state
        createFormError: '',
        createErrors: { vendor_id: '', amount: '', credit_date: '', reason: '', expense_account_id: '' },
        applyFormError: '',
        applyErrors: { bill_id: '', amount_applied: '' },

        tabs: [
            { label: 'All', value: '' },
            { label: 'Active', value: 'active' },
            { label: 'Partial', value: 'partially_used' },
            { label: 'Used', value: 'fully_used' },
        ],

        _csrfHeaders() {
            return {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            };
        },

        // VALID-2 error helpers
        _extractError(r, fallback) {
            if (r && r.error) {
                if (typeof r.error === 'string') return r.error;
                if (r.error.message) return r.error.message;
            }
            return fallback || 'Request failed.';
        },
        _clearErrors(errorsObj, bannerKey) {
            this[bannerKey] = '';
            for (const k in errorsObj) errorsObj[k] = '';
        },
        _paintErrors(errorsObj, bannerKey, r, fallback) {
            this._clearErrors(errorsObj, bannerKey);
            const fields = r && r.error && r.error.fields;
            if (fields && typeof fields === 'object') {
                let mapped = false;
                for (const k in fields) {
                    if (k === '_general') {
                        this[bannerKey] = String(fields[k]);
                        mapped = true;
                    } else if (k in errorsObj) {
                        errorsObj[k] = String(fields[k]);
                        mapped = true;
                    }
                }
                if (mapped) return;
            }
            this[bannerKey] = this._extractError(r, fallback);
        },
        _isValidAmount(v) {
            if (v === null || v === undefined || v === '') return false;
            return /^\d+(\.\d{1,2})?$/.test(String(v).trim());
        },

        validateCreate() {
            this._clearErrors(this.createErrors, 'createFormError');
            let ok = true;
            if (!this.createForm.vendor_id) {
                this.createErrors.vendor_id = 'Please select a vendor.';
                ok = false;
            }
            if (!this._isValidAmount(this.createForm.amount)) {
                this.createErrors.amount = 'Amount must be a valid number with up to 2 decimal places.';
                ok = false;
            } else if (Number(this.createForm.amount) <= 0) {
                this.createErrors.amount = 'Amount must be greater than zero.';
                ok = false;
            }
            if (!this.createForm.credit_date) {
                this.createErrors.credit_date = 'Credit date is required.';
                ok = false;
            }
            if (!this.createForm.reason || this.createForm.reason.trim() === '') {
                this.createErrors.reason = 'Reason is required.';
                ok = false;
            }
            return ok;
        },

        validateApply() {
            this._clearErrors(this.applyErrors, 'applyFormError');
            let ok = true;
            if (!this.applyForm.bill_id) {
                this.applyErrors.bill_id = 'Please select a bill.';
                ok = false;
            }
            if (!this._isValidAmount(this.applyForm.amount_applied)) {
                this.applyErrors.amount_applied = 'Amount must be a valid number with up to 2 decimal places.';
                ok = false;
            } else if (Number(this.applyForm.amount_applied) <= 0) {
                this.applyErrors.amount_applied = 'Amount must be greater than zero.';
                ok = false;
            } else if (Number(this.applyForm.amount_applied) > Number(this.applyForm.amount_remaining)) {
                this.applyErrors.amount_applied = 'Amount cannot exceed credit remaining.';
                ok = false;
            }
            return ok;
        },

        cancelCreate() {
            this.showCreate = false;
            this._clearErrors(this.createErrors, 'createFormError');
        },

        async load() {
            this.loading = true;
            try {
                const p = new URLSearchParams();
                if (this.tab) p.set('status', this.tab);
                if (this.filterVendor) p.set('vendor_id', this.filterVendor);
                const r = await fetch(FF_Api.url('/api/v1/accounting/vendor-credits/index.php?' + p));
                const j = await r.json();
                if (j.success) {
                    const d = j.data;
                    this.credits = d.items ?? d;
                    this.totalRows = d.pagination?.total ?? j.pagination?.total ?? 0;
                }
            } catch (e) { console.error(e); }
            this.loading = false;
            this.calcKpis();
        },

        calcKpis() {
            this.kpi.active = this.credits.filter(c => c.status === 'active' || c.status === 'partially_used')
                .reduce((s, c) => (Number(s) + Number(c.amount_remaining)).toFixed(2), '0.00');
            this.kpi.applied = this.credits.reduce((s, c) => (Number(s) + Number(c.amount) - Number(c.amount_remaining)).toFixed(2), '0.00');
        },

        async submitCreate() {
            if (!this.validateCreate()) return;
            this.creating = true;
            try {
                const fd = new FormData();
                Object.entries(this.createForm).forEach(([k, v]) => fd.append(k, v));
                const r = await fetch(FF_Api.url('/api/v1/accounting/vendor-credits/create.php'), { method: 'POST', body: fd, headers: this._csrfHeaders() });
                const j = await r.json();
                if (j.success) {
                    this.showCreate = false;
                    this._clearErrors(this.createErrors, 'createFormError');
                    this.load();
                } else {
                    this._paintErrors(this.createErrors, 'createFormError', j, 'Failed to create credit.');
                }
            } catch (e) { this.createFormError = 'Network error. Please try again.'; }
            this.creating = false;
        },

        async openApply(credit) {
            this.applyForm = { vendor_credit_id: credit.id, credit_number: credit.credit_number, amount_remaining: credit.amount_remaining, bill_id: '', amount_applied: credit.amount_remaining };
            this._clearErrors(this.applyErrors, 'applyFormError');
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/bills/index.php?vendor_id=' + credit.vendor_id + '&status=approved&per_page=100'));
                const j = await r.json();
                const approved = j.success ? (j.data.items ?? j.data) : [];
                const r2 = await fetch(FF_Api.url('/api/v1/accounting/bills/index.php?vendor_id=' + credit.vendor_id + '&status=partially_paid&per_page=100'));
                const j2 = await r2.json();
                const partial = j2.success ? (j2.data.items ?? j2.data) : [];
                this.vendorBills = [...approved, ...partial];
            } catch (e) { this.vendorBills = []; }
            this.showApply = true;
        },

        async submitApply() {
            if (!this.validateApply()) return;
            this.applying = true;
            try {
                const fd = new FormData();
                fd.append('vendor_credit_id', this.applyForm.vendor_credit_id);
                fd.append('bill_id', this.applyForm.bill_id);
                fd.append('amount_applied', this.applyForm.amount_applied);
                const r = await fetch(FF_Api.url('/api/v1/accounting/vendor-credits/apply.php'), { method: 'POST', body: fd, headers: this._csrfHeaders() });
                const j = await r.json();
                if (j.success) {
                    this.showApply = false;
                    this._clearErrors(this.applyErrors, 'applyFormError');
                    this.load();
                } else {
                    this._paintErrors(this.applyErrors, 'applyFormError', j, 'Failed to apply credit.');
                }
            } catch (e) { this.applyFormError = 'Network error. Please try again.'; }
            this.applying = false;
        },

        creditBadge(s) {
            return { active: 'badge-success', partially_used: 'badge-warning', fully_used: 'badge-neutral', void: 'badge-neutral' }[s] || 'badge-neutral';
        },
        fmtAmt(v) { const n = Number(v); return n ? '$' + n.toLocaleString('en-CA',{minimumFractionDigits:2}) : '—'; },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
