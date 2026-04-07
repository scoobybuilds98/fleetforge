<?php declare(strict_types=1);

/**
 * app/admin/accounting/deposits/index.php
 *
 * Customer deposits management page — list, create, apply, and refund deposits.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @session S031
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$customers = db_select(
    "SELECT id, company_name FROM customers WHERE deleted_at IS NULL AND status = 'active' ORDER BY company_name",
    []
);

$pageTitle = 'Customer Deposits';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Deposits</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Customer Deposits</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="depositsPage()" x-init="load()">
    <div style="margin-bottom:20px;display:flex;justify-content:flex-end;align-items:end;gap:8px;">
        <select x-model="filterStatus" @change="load()" class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            <option value="">All Statuses</option>
            <option value="held">Held</option>
            <option value="applied">Applied</option>
            <option value="refunded">Refunded</option>
            <option value="forfeited">Forfeited</option>
        </select>
        <?php if (can('journal_entries', 'create')): ?>
        <button class="btn btn-primary btn-sm" @click="showCreate = !showCreate">+ New Deposit</button>
        <?php endif; ?>
    </div>

    <!-- KPI Tiles -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Total Held</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-accent);" x-text="'$' + totalHeld"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Total Applied</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-success);" x-text="'$' + totalApplied"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Total Refunded</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-warning);" x-text="'$' + totalRefunded"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Count</div>
            <div style="font-size:1.125rem;font-weight:700;color:var(--text-primary);" x-text="deposits.length"></div>
        </div>
    </div>

    <!-- Create Form -->
    <template x-if="showCreate">
        <div class="card" style="padding:16px;margin-bottom:16px;">
            <div style="font-weight:600;font-size:0.875rem;margin-bottom:12px;">Record New Deposit</div>
            <div class="form-error-banner" x-show="formError" x-cloak x-text="formError" style="margin-bottom:12px;"></div>
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Customer</label>
                    <select x-model="createForm.customer_id" @change="errors.customer_id = ''" :class="errors.customer_id ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                        <option value="">Select...</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= e($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-error" x-show="errors.customer_id" x-cloak x-text="errors.customer_id"></div>
                </div>
                <div>
                    <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Amount</label>
                    <input type="number" step="0.01" min="0" x-model="createForm.amount" @input="errors.amount = ''" :class="errors.amount ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    <div class="field-error" x-show="errors.amount" x-cloak x-text="errors.amount"></div>
                </div>
                <div>
                    <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Date Received</label>
                    <input type="date" x-model="createForm.received_date" @change="errors.received_date = ''" :class="errors.received_date ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    <div class="field-error" x-show="errors.received_date" x-cloak x-text="errors.received_date"></div>
                </div>
                <div>
                    <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Type</label>
                    <select x-model="createForm.deposit_type" @change="errors.deposit_type = ''" :class="errors.deposit_type ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                        <option value="security">Security</option>
                        <option value="damage">Damage</option>
                        <option value="advance_payment">Advance Payment</option>
                        <option value="other">Other</option>
                    </select>
                    <div class="field-error" x-show="errors.deposit_type" x-cloak x-text="errors.deposit_type"></div>
                </div>
            </div>
            <div style="margin-bottom:10px;">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Notes</label>
                <input type="text" x-model="createForm.notes" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            </div>
            <button class="btn btn-primary btn-sm" @click="createDeposit()">Record Deposit</button>
        </div>
    </template>

    <!-- Table -->
    <div class="card" style="overflow-x:auto;">
        <template x-if="deposits.length > 0">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Deposit #</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Customer</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Type</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Amount</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Date</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Status</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Applied To</th>
                        <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="d in deposits" :key="d.id">
                        <tr style="border-bottom:1px solid var(--border-default);">
                            <td style="padding:8px 12px;font-weight:500;" x-text="d.deposit_number"></td>
                            <td style="padding:8px 12px;" x-text="d.company_name"></td>
                            <td style="padding:8px 12px;">
                                <span class="badge badge-neutral badge-sm" x-text="d.deposit_type.replace('_',' ')"></span>
                            </td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="'$' + Number(d.amount).toFixed(2)"></td>
                            <td style="padding:8px 12px;white-space:nowrap;" x-text="d.received_date"></td>
                            <td style="padding:8px 12px;">
                                <span class="badge badge-sm" :class="statusBadge(d.status)" x-text="d.status"></span>
                            </td>
                            <td style="padding:8px 12px;" x-text="d.applied_to_invoice_number || '—'"></td>
                            <td style="padding:8px 12px;text-align:center;">
                                <template x-if="d.status === 'held'">
                                    <div style="display:flex;gap:4px;justify-content:center;">
                                        <button class="btn btn-success btn-xs" @click="applyDeposit(d)">Apply</button>
                                        <button class="btn btn-warning btn-xs" @click="refundDeposit(d.id)">Refund</button>
                                    </div>
                                </template>
                                <template x-if="d.status !== 'held'">
                                    <span style="color:var(--text-tertiary);font-size:0.75rem;">—</span>
                                </template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </template>
        <template x-if="deposits.length === 0 && !loading">
            <div style="padding:48px;text-align:center;">
                <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">No Deposits</div>
                <div style="font-size:0.8125rem;color:var(--text-secondary);">Record customer deposits to track security and advance payments.</div>
            </div>
        </template>
    </div>
</div>

<script>
function depositsPage() {
    return {
        deposits: [],
        loading: false,
        showCreate: false,
        filterStatus: '',
        totalHeld: '0.00',
        totalApplied: '0.00',
        totalRefunded: '0.00',
        createForm: { customer_id: '', amount: '', received_date: new Date().toISOString().slice(0,10), deposit_type: 'security', notes: '' },
        formError: '',
        errors: { customer_id: '', amount: '', received_date: '', deposit_type: '' },

        _extractError(r, fallback) {
            if (!r) return fallback || 'An unexpected error occurred.';
            if (r.error && r.error.message) return r.error.message;
            if (r.message) return r.message;
            return fallback || 'An unexpected error occurred.';
        },

        _clearErrors() {
            for (const k in this.errors) this.errors[k] = '';
            this.formError = '';
        },

        _paintServerErrors(r, fallback) {
            const err = r && r.error ? r.error : r;
            const fields = (err && err.fields) || (r && r.fields) || {};
            let painted = false;
            for (const k in fields) {
                if (k === '_general') continue;
                if (k in this.errors) {
                    this.errors[k] = fields[k];
                    painted = true;
                }
            }
            if (fields._general) {
                this.formError = fields._general;
            } else if (!painted) {
                this.formError = this._extractError(r, fallback);
            } else {
                this.formError = this._extractError(r, 'Please correct the highlighted fields.');
            }
        },

        async load() {
            this.loading = true;
            try {
                let url = '/api/v1/accounting/ar/deposits/index.php?per_page=100';
                if (this.filterStatus) url += '&status=' + this.filterStatus;
                const r = await fetch(FF_Api.url(url));
                const j = await r.json();
                if (j.success) {
                    this.deposits = j.data.data || j.data;
                    this.calcTotals();
                }
            } catch(e) { console.error(e); }
            this.loading = false;
        },

        calcTotals() {
            let held = 0, applied = 0, refunded = 0;
            this.deposits.forEach(d => {
                const amt = parseFloat(d.amount);
                if (d.status === 'held') held += amt;
                else if (d.status === 'applied') applied += amt;
                else if (d.status === 'refunded') refunded += amt;
            });
            this.totalHeld = held.toFixed(2);
            this.totalApplied = applied.toFixed(2);
            this.totalRefunded = refunded.toFixed(2);
        },

        validateCreate() {
            this._clearErrors();
            let ok = true;
            if (!this.createForm.customer_id) {
                this.errors.customer_id = 'Please select a customer.';
                ok = false;
            }
            const amt = String(this.createForm.amount || '').trim();
            if (!amt) {
                this.errors.amount = 'Deposit amount is required.';
                ok = false;
            } else if (isNaN(parseFloat(amt))) {
                this.errors.amount = 'Deposit amount must be a valid number.';
                ok = false;
            } else if (parseFloat(amt) <= 0) {
                this.errors.amount = 'Deposit amount must be greater than zero.';
                ok = false;
            }
            if (!this.createForm.received_date) {
                this.errors.received_date = 'Received date is required.';
                ok = false;
            }
            const validTypes = ['security', 'damage', 'advance_payment', 'other'];
            if (!this.createForm.deposit_type || !validTypes.includes(this.createForm.deposit_type)) {
                this.errors.deposit_type = 'Deposit type must be one of: security, damage, advance_payment, other.';
                ok = false;
            }
            return ok;
        },

        async createDeposit() {
            if (!this.validateCreate()) {
                this.formError = 'Please correct the highlighted fields.';
                return;
            }
            this.formError = '';
            const body = new FormData();
            Object.entries(this.createForm).forEach(([k,v]) => { if (v) body.append(k, v); });
            body.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/deposits/create.php'), { method: 'POST', body });
                const j = await r.json();
                if (j.success) {
                    this.showCreate = false;
                    this._clearErrors();
                    this.load();
                    FF_Toast.success('Deposit recorded: ' + j.data.deposit_number);
                } else {
                    this._paintServerErrors(j, 'Failed to record deposit.');
                }
            } catch(e) { this.formError = 'Network error. Please try again.'; }
        },

        async applyDeposit(dep) {
            const invoiceId = prompt('Enter Invoice ID to apply deposit ' + dep.deposit_number + ' to:');
            if (!invoiceId) return;
            const body = new FormData();
            body.append('deposit_id', dep.id);
            body.append('invoice_id', invoiceId);
            body.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/deposits/apply.php'), { method: 'POST', body });
                const j = await r.json();
                if (j.success) { this.load(); FF_Toast.success('Deposit applied.'); }
                else FF_Toast.error(this._extractError(j, 'Failed to apply deposit.'));
            } catch(e) { FF_Toast.error('Network error.'); }
        },

        async refundDeposit(id) {
            if (!confirm('Refund this deposit?')) return;
            const body = new FormData();
            body.append('deposit_id', id);
            body.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/deposits/refund.php'), { method: 'POST', body });
                const j = await r.json();
                if (j.success) { this.load(); FF_Toast.success('Deposit refunded.'); }
                else FF_Toast.error(this._extractError(j, 'Failed to refund deposit.'));
            } catch(e) { FF_Toast.error('Network error.'); }
        },

        statusBadge(s) {
            return { held: 'badge-info', applied: 'badge-success', refunded: 'badge-warning', forfeited: 'badge-danger' }[s] || 'badge-neutral';
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
