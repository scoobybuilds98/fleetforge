<?php
declare(strict_types=1);

/**
 * app/admin/invoices/create.php
 *
 * Manual invoice creation form. Select an active/completed lease,
 * specify billing period, preview charges, and submit.
 * Submits to api/v1/invoices/create.php via Alpine.js.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.7 Invoices
 * @decisions D14 (inclusive days), D30 (asset_url), D32 (CSS classes)
 * @session  S008
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'create');

// Load active + completed leases for the dropdown
$leases = db_select(
    "SELECT l.id, l.contract_number, l.customer_id, l.company_name_snapshot,
            l.unit_number_snapshot, l.template_name_snapshot, l.status,
            l.daily_rate, l.weekly_rate, l.monthly_rate, l.currency,
            l.start_date, l.billing_cycle, l.discount_type, l.discount_value,
            l.gst_exempt, l.pst_exempt, l.tax_exempt
     FROM leases l
     WHERE l.status IN ('active','completed') AND l.deleted_at IS NULL
     ORDER BY l.contract_number ASC",
    []
);

$pageTitle = 'Create Invoice';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb + Header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('invoices') ?>">Invoices</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Create Invoice</span>
</nav>
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Create Invoice</h1>
    </div>
</div>

<!-- ============================================================
     CREATE INVOICE FORM
     ============================================================ -->
<!-- FIX #39: wrap in form tag so Enter-to-submit works -->
<form x-data="FF_InvoiceCreate()" @submit.prevent="submit()" class="card" style="padding:24px; max-width:800px;">

    <!-- Lease Selection -->
    <div style="margin-bottom:20px;">
        <label class="form-label">Lease <span class="text-danger">*</span></label>
        <select class="form-control" x-model="form.lease_id" @change="onLeaseChange()">
            <option value="">Select a lease…</option>
            <?php foreach ($leases as $lease): ?>
            <option value="<?= (int)$lease['id'] ?>"
                    data-daily="<?= e($lease['daily_rate']) ?>"
                    data-weekly="<?= e($lease['weekly_rate']) ?>"
                    data-monthly="<?= e($lease['monthly_rate']) ?>"
                    data-currency="<?= e($lease['currency']) ?>"
                    data-start="<?= e($lease['start_date']) ?>">
                <?= e($lease['contract_number']) ?> — <?= e($lease['company_name_snapshot']) ?>
                (Unit <?= e($lease['unit_number_snapshot']) ?>, <?= e($lease['status']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Lease info card (shown after selection) -->
    <template x-if="selectedLease">
        <div style="background:var(--bg-muted); border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:13px;">
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                <div>
                    <span class="text-secondary">Daily:</span>
                    <span class="font-mono" x-text="'$' + selectedLease.daily"></span>
                </div>
                <div>
                    <span class="text-secondary">Weekly:</span>
                    <span class="font-mono" x-text="'$' + selectedLease.weekly"></span>
                </div>
                <div>
                    <span class="text-secondary">Monthly:</span>
                    <span class="font-mono" x-text="'$' + selectedLease.monthly"></span>
                </div>
            </div>
        </div>
    </template>

    <!-- Period Dates -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
        <div>
            <label class="form-label">Period Start <span class="text-danger">*</span></label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" class="form-control" x-model="form.period_start" @change="updateDays()"
                       x-ref="invPeriodStart" style="flex:1;">
                <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.invPeriodStart.showPicker ? $refs.invPeriodStart.showPicker() : $refs.invPeriodStart.click()">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                </button>
            </div>
        </div>
        <div>
            <label class="form-label">Period End <span class="text-danger">*</span></label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" class="form-control" x-model="form.period_end" @change="updateDays()"
                       :min="form.period_start || ''"
                       x-ref="invPeriodEnd" style="flex:1;">
                <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.invPeriodEnd.showPicker ? $refs.invPeriodEnd.showPicker() : $refs.invPeriodEnd.click()">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Days + Billing Type -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
        <div>
            <label class="form-label">Billing Days</label>
            <input type="text" class="form-control" x-model="days" readonly
                   style="background:var(--bg-muted);">
        </div>
        <div>
            <label class="form-label">Billing Type <span class="text-danger">*</span></label>
            <select class="form-control" x-model="form.billing_type">
                <option value="partial_start">Partial Start</option>
                <option value="full_month">Full Month</option>
                <option value="partial_end">Partial End</option>
                <option value="single_period">Single Period</option>
            </select>
        </div>
    </div>

    <!-- Invoice Type -->
    <div style="margin-bottom:20px;">
        <label class="form-label">Invoice Type</label>
        <select class="form-control" x-model="form.invoice_type">
            <option value="regular">Regular</option>
            <option value="final">Final</option>
            <option value="mileage_only">Mileage Only</option>
            <option value="adjustment">Adjustment</option>
        </select>
    </div>

    <!-- PO Number -->
    <div style="margin-bottom:20px;">
        <label class="form-label">PO Number</label>
        <input type="text" class="form-control" x-model="form.po_number"
               placeholder="Optional" maxlength="100">
    </div>

    <!-- Notes -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">
        <div>
            <label class="form-label">Notes (customer-facing)</label>
            <textarea class="form-control" x-model="form.notes" rows="3"
                      placeholder="Appears on invoice" maxlength="2000"></textarea>
        </div>
        <div>
            <label class="form-label">Internal Notes</label>
            <textarea class="form-control" x-model="form.internal_notes" rows="3"
                      placeholder="Internal only" maxlength="2000"></textarea>
        </div>
    </div>

    <!-- Error display -->
    <template x-if="error">
        <div class="alert alert-danger" style="margin-bottom:16px;" x-text="error"></div>
    </template>

    <!-- Submit -->
    <div style="display:flex; gap:12px; align-items:center;">
        <button type="submit" class="btn btn-primary" :disabled="submitting || !form.lease_id || !form.period_start || !form.period_end">
            <span x-show="!submitting">Create Invoice</span>
            <span x-show="submitting">Creating…</span>
        </button>
        <a href="<?= base_url('invoices') ?>" class="btn btn-secondary">Cancel</a>
        <template x-if="result">
            <span class="text-sm" style="color:var(--color-success);" x-text="'✓ Created ' + result.invoice_number"></span>
        </template>
    </div>
</form>

<script>
function FF_InvoiceCreate() {
    return {
        form: {
            lease_id:       '',
            period_start:   '',
            period_end:     '',
            billing_type:   'partial_start',
            invoice_type:   'regular',
            po_number:      '',
            notes:          '',
            internal_notes: '',
        },
        selectedLease:      null,
        days:               0,
        submitting:         false,
        showSuccessOverlay: false,
        error:              null,
        result:             null,

        onLeaseChange() {
            const sel = this.$el.closest('[x-data]').querySelector('select');
            const opt = sel.options[sel.selectedIndex];
            if (!this.form.lease_id) {
                this.selectedLease = null;
                return;
            }
            this.selectedLease = {
                daily:    opt.dataset.daily   || '0.00',
                weekly:   opt.dataset.weekly  || '0.00',
                monthly:  opt.dataset.monthly || '0.00',
                currency: opt.dataset.currency || 'CAD',
                start:    opt.dataset.start   || '',
            };
            // Default period_start to lease start if empty
            if (!this.form.period_start && this.selectedLease.start) {
                this.form.period_start = this.selectedLease.start;
            }
            this.updateDays();
        },

        updateDays() {
            if (this.form.period_start && this.form.period_end) {
                const s = new Date(this.form.period_start + 'T00:00:00');
                const e = new Date(this.form.period_end + 'T00:00:00');
                const diff = Math.floor((e - s) / 86400000) + 1; // D14: inclusive
                this.days = diff > 0 ? diff : 0;
            } else {
                this.days = 0;
            }
        },

        async submit() {
            this.submitting = true;
            this.error = null;
            this.result = null;

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/create') ?>', this.form);
                if (r.success) {
                    this.result = r.data;
                    this.showSuccessOverlay = true;
                    const _newId = r.data.id;
                    // Redirect to invoice detail after overlay animation
                    setTimeout(() => {
                        window.location.href = '<?= base_url('invoices/show') ?>?id=' + _newId;
                    }, 3500);
                } else {
                    this.error = r.error?.message || 'Failed to create invoice.';
                }
            } catch(e) {
                this.error = 'Network error. Please try again.';
            }
            this.submitting = false;
        },
    };
}
</script>

<?php
$overlayTitle    = 'Invoice Created!';
$overlaySubtitle = 'Redirecting to invoice details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
