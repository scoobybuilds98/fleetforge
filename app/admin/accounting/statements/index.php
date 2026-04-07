<?php declare(strict_types=1);

/**
 * app/admin/accounting/statements/index.php
 *
 * Customer statement generation page — select customer, date range,
 * and generate/download PDF statement.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @session S031
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$customers = db_select(
    "SELECT id, company_name, outstanding_balance
     FROM customers WHERE deleted_at IS NULL AND status = 'active'
     ORDER BY company_name",
    []
);

$pageTitle = 'Customer Statements';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Statements</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Customer Statements</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="statementsPage()">

    <div class="card" style="padding:20px;margin-bottom:20px;">
        <div class="form-error-banner" x-show="formError" x-cloak x-text="formError" style="margin-bottom:12px;"></div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:14px;align-items:end;">
            <div>
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:4px;">Customer</label>
                <select x-model="customerId" :class="errors.customer_id ? 'is-invalid' : ''" @change="errors.customer_id = ''" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    <option value="">Select customer...</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['company_name']) ?> (<?= format_currency($c['outstanding_balance']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <div class="field-error" x-show="errors.customer_id" x-cloak x-text="errors.customer_id"></div>
            </div>
            <div>
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:4px;">From</label>
                <input type="date" x-model="dateFrom" :class="errors.date_from ? 'is-invalid' : ''" @change="errors.date_from = ''" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <div class="field-error" x-show="errors.date_from" x-cloak x-text="errors.date_from"></div>
            </div>
            <div>
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:4px;">To</label>
                <input type="date" x-model="dateTo" :class="errors.date_to ? 'is-invalid' : ''" @change="errors.date_to = ''" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <div class="field-error" x-show="errors.date_to" x-cloak x-text="errors.date_to"></div>
            </div>
            <div>
                <button class="btn btn-primary btn-md" @click="generate()" :disabled="busy" style="white-space:nowrap;">
                    <span x-show="!busy">Generate PDF</span>
                    <span x-show="busy">Generating...</span>
                </button>
            </div>
        </div>
    </div>

    <div class="card" style="padding:48px;text-align:center;" x-show="!customerId">
        <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">Generate a Statement</div>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">Select a customer and date range, then click Generate PDF to download their account statement.</div>
    </div>
</div>

<script>
function statementsPage() {
    const now = new Date();
    const threeMonthsAgo = new Date(now.getFullYear(), now.getMonth() - 3, 1);
    return {
        customerId: '',
        dateFrom: threeMonthsAgo.toISOString().slice(0, 10),
        dateTo: now.toISOString().slice(0, 10),
        busy: false,

        // VALID-2 error state
        formError: '',
        errors: { customer_id: '', date_from: '', date_to: '' },

        _extractError(r, fallback) {
            if (r && r.error) {
                if (typeof r.error === 'string') return r.error;
                if (r.error.message) return r.error.message;
            }
            return fallback || 'Request failed.';
        },
        _clearErrors() {
            this.formError = '';
            for (const k in this.errors) this.errors[k] = '';
        },
        _paintErrors(r, fallback) {
            this._clearErrors();
            const fields = r && r.error && r.error.fields;
            if (fields && typeof fields === 'object') {
                let mapped = false;
                for (const k in fields) {
                    if (k === '_general') {
                        this.formError = String(fields[k]);
                        mapped = true;
                    } else if (k in this.errors) {
                        this.errors[k] = String(fields[k]);
                        mapped = true;
                    }
                }
                if (mapped) return;
            }
            this.formError = this._extractError(r, fallback);
        },

        validate() {
            this._clearErrors();
            let ok = true;
            if (!this.customerId) {
                this.errors.customer_id = 'Please select a customer.';
                ok = false;
            }
            if (!this.dateFrom) {
                this.errors.date_from = 'Start date is required.';
                ok = false;
            }
            if (!this.dateTo) {
                this.errors.date_to = 'End date is required.';
                ok = false;
            }
            if (this.dateFrom && this.dateTo && this.dateTo < this.dateFrom) {
                this.errors.date_to = 'End date must be on or after start date.';
                ok = false;
            }
            return ok;
        },

        async generate() {
            if (!this.validate()) return;
            this.busy = true;
            try {
                const params = new URLSearchParams({
                    customer_id: this.customerId,
                    date_from: this.dateFrom,
                    date_to: this.dateTo,
                });
                const url = FF_Api.url('/api/v1/accounting/ar/statement.php?' + params);
                const resp = await fetch(url);
                if (!resp.ok) {
                    const j = await resp.json().catch(() => ({}));
                    this._paintErrors(j, 'Failed to generate statement.');
                    this.busy = false;
                    return;
                }
                const blob = await resp.blob();
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = 'statement_' + this.customerId + '_' + this.dateTo + '.pdf';
                link.click();
                URL.revokeObjectURL(link.href);
                FF_Toast.success('Statement generated.');
            } catch (e) {
                this.formError = 'Network error. Please try again.';
            }
            this.busy = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
