<?php
declare(strict_types=1);

/**
 * app/admin/payments/create.php
 *
 * Payment recording form. Allows staff to select an outstanding invoice,
 * enter the amount, method, date, and reference details. Auto-fills the
 * invoice balance when an invoice is selected. Submits to api/v1/payments/create.php.
 *
 * Invoice picker pre-filters to status IN ('sent','partially_paid','overdue').
 * D18: Currency selector auto-matches the invoice currency on selection.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.8 Payments
 * @decisions D18 (currency match), D16 (bcmath on server), D30 (asset_url), D32 (CSS only)
 * @session  S009
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('payments', 'create');

// --- Pre-load outstanding invoices for the picker ---
// Only invoices that can still receive a payment
$outstandingInvoices = db_select(
    "SELECT i.id, i.invoice_number, i.invoice_type,
            i.company_name_snapshot, i.currency,
            i.total_amount, i.balance_due, i.due_date, i.status
     FROM invoices i
     WHERE i.deleted_at IS NULL
       AND i.status IN ('sent', 'partially_paid', 'overdue')
     ORDER BY i.due_date ASC, i.invoice_number ASC
     LIMIT 500",
    []
);

// If an invoice_id was passed in the query string (coming from invoice show page), pre-select it
$preselectedInvoiceId = clean_int($_GET['invoice_id'] ?? null);

$pageTitle = 'Record Payment';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb
     ============================================================ -->
<div class="breadcrumb" style="margin-bottom:20px;">
    <a href="<?= base_url('/payments') ?>" class="breadcrumb-item">Payments</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-item breadcrumb-current">Record Payment</span>
</div>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header" style="margin-bottom:24px;">
    <div>
        <h1 class="page-header-title h4">Record Payment</h1>
        <p style="margin:4px 0 0; color:var(--text-secondary); font-size:0.9rem;">Apply a customer payment against an outstanding invoice</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= base_url('/payments') ?>" class="btn btn-secondary btn-md">Cancel</a>
    </div>
</div>

<!-- ============================================================
     Form — Alpine.js component submits to API
     ============================================================ -->
<form @submit.prevent="submitPayment()" x-data="FF_CreatePayment()" novalidate>

    <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start;">

        <!-- ---- Main form card ---- -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Payment Details</h3></div>
            <div class="card-body" style="display:flex; flex-direction:column; gap:20px;">

                <!-- Invoice picker -->
                <div class="form-group">
                    <label class="form-label" for="invoice_id">
                        Invoice <span style="color:var(--color-danger);">*</span>
                    </label>
                    <select id="invoice_id" class="form-input" x-model="form.invoice_id"
                            @change="onInvoiceChange()" required>
                        <option value="">— Select an invoice —</option>
                        <?php foreach ($outstandingInvoices as $inv): ?>
                            <option
                                value="<?= (int) $inv['id'] ?>"
                                data-balance="<?= e($inv['balance_due']) ?>"
                                data-total="<?= e($inv['total_amount']) ?>"
                                data-currency="<?= e($inv['currency']) ?>"
                                data-customer="<?= e($inv['company_name_snapshot']) ?>"
                                data-status="<?= e($inv['status']) ?>"
                                data-due="<?= e($inv['due_date']) ?>"
                                <?= $preselectedInvoiceId === (int)$inv['id'] ? 'selected' : '' ?>
                            >
                                <?= e($inv['invoice_number']) ?>
                                — <?= e($inv['company_name_snapshot']) ?>
                                (<?= e($inv['currency']) ?> <?= format_currency($inv['balance_due']) ?> due)
                                <?= $inv['status'] === 'overdue' ? '[OVERDUE]' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint" x-show="selectedInvoice.balance">
                        Balance due:
                        <strong class="font-mono" x-text="selectedInvoice.currency + ' ' + formatCurrency(selectedInvoice.balance)"></strong>
                        <span x-show="selectedInvoice.status === 'overdue'"
                              style="color:var(--color-danger); margin-left:6px;">(Overdue)</span>
                    </p>
                    <p x-show="errors.invoice_id" x-text="errors.invoice_id"
                       style="color:var(--color-danger); font-size:0.85rem; margin-top:4px;"></p>
                </div>

                <!-- Amount + currency row -->
                <div style="display:grid; grid-template-columns:1fr auto; gap:12px;">
                    <div class="form-group">
                        <label class="form-label" for="amount">
                            Amount <span style="color:var(--color-danger);">*</span>
                        </label>
                        <input type="number" id="amount" class="form-input font-mono"
                               x-model="form.amount"
                               step="0.01" min="0.01"
                               placeholder="0.00"
                               @input="validateAmount()" required>
                        <!-- Quick-fill buttons -->
                        <div style="margin-top:6px; display:flex; gap:8px;" x-show="selectedInvoice.balance">
                            <button type="button" class="btn btn-secondary btn-xs"
                                    @click="fillBalance()">
                                Pay full balance (<?= '<?=' ?>  selectedInvoice.currency + ' ' + formatCurrency(selectedInvoice.balance) <?= '?>' ?>)
                            </button>
                        </div>
                        <p x-show="errors.amount" x-text="errors.amount"
                           style="color:var(--color-danger); font-size:0.85rem; margin-top:4px;"></p>
                    </div>
                    <div class="form-group" style="width:100px;">
                        <label class="form-label">Currency</label>
                        <select class="form-input" x-model="form.currency" :disabled="!!selectedInvoice.currency">
                            <option value="CAD">CAD</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                </div>

                <!-- Payment method -->
                <div class="form-group">
                    <label class="form-label" for="payment_method">
                        Payment Method <span style="color:var(--color-danger);">*</span>
                    </label>
                    <select id="payment_method" class="form-input" x-model="form.payment_method" required>
                        <option value="">— Select method —</option>
                        <option value="check">Cheque</option>
                        <option value="ach">ACH / Direct Deposit</option>
                        <option value="wire">Wire Transfer</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="cash">Cash</option>
                        <option value="e_transfer">e-Transfer</option>
                        <option value="account_credit">Account Credit</option>
                        <option value="other">Other</option>
                    </select>
                    <p x-show="errors.payment_method" x-text="errors.payment_method"
                       style="color:var(--color-danger); font-size:0.85rem; margin-top:4px;"></p>
                </div>

                <!-- Payment date -->
                <div class="form-group">
                    <label class="form-label" for="payment_date">
                        Payment Date <span style="color:var(--color-danger);">*</span>
                    </label>
                    <input type="date" id="payment_date" class="form-input"
                           x-model="form.payment_date" required>
                    <p x-show="errors.payment_date" x-text="errors.payment_date"
                       style="color:var(--color-danger); font-size:0.85rem; margin-top:4px;"></p>
                </div>

                <!-- Reference fields — conditional by method -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Reference / Confirmation #</label>
                        <input type="text" class="form-input font-mono"
                               x-model="form.reference_number" maxlength="100"
                               placeholder="e.g. TXN-12345">
                    </div>
                    <div class="form-group" x-show="form.payment_method === 'check'">
                        <label class="form-label">Cheque Number</label>
                        <input type="text" class="form-input font-mono"
                               x-model="form.check_number" maxlength="50">
                    </div>
                    <div class="form-group" x-show="['check','ach','wire'].includes(form.payment_method)">
                        <label class="form-label">Bank Name</label>
                        <input type="text" class="form-input"
                               x-model="form.bank_name" maxlength="100">
                    </div>
                    <div class="form-group" x-show="form.payment_method === 'credit_card'">
                        <label class="form-label">Card Last 4 Digits</label>
                        <input type="text" class="form-input font-mono"
                               x-model="form.card_last_four" maxlength="4" pattern="\d{4}"
                               placeholder="1234">
                    </div>
                </div>

                <!-- Notes -->
                <div class="form-group">
                    <label class="form-label">Notes (customer-visible)</label>
                    <textarea class="form-input" rows="3"
                              x-model="form.notes" maxlength="2000"
                              placeholder="Optional note on this payment…"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Internal Notes (staff only)</label>
                    <textarea class="form-input" rows="2"
                              x-model="form.internal_notes" maxlength="2000"
                              placeholder="Internal reference, dispute notes, etc."></textarea>
                </div>

            </div><!-- /card-body -->
        </div><!-- /main card -->

        <!-- ---- Summary sidebar ---- -->
        <div style="display:flex; flex-direction:column; gap:16px;">

            <!-- Invoice summary -->
            <div class="card" x-show="selectedInvoice.id">
                <div class="card-header"><h4 class="card-title" style="font-size:0.95rem;">Selected Invoice</h4></div>
                <div class="card-body">
                    <dl class="detail-grid" style="font-size:0.875rem;">
                        <dt>Invoice #</dt>
                        <dd class="font-mono" x-text="selectedInvoice.number || '—'"></dd>
                        <dt>Customer</dt>
                        <dd x-text="selectedInvoice.customer || '—'"></dd>
                        <dt>Invoice Total</dt>
                        <dd class="font-mono" x-text="selectedInvoice.currency + ' ' + formatCurrency(selectedInvoice.total)"></dd>
                        <dt>Balance Due</dt>
                        <dd class="font-mono" style="color:var(--color-warning);"
                            x-text="selectedInvoice.currency + ' ' + formatCurrency(selectedInvoice.balance)"></dd>
                        <dt>Due Date</dt>
                        <dd class="font-mono" x-text="selectedInvoice.due || '—'"></dd>
                        <dt>Status</dt>
                        <dd>
                            <span class="badge"
                                  :class="selectedInvoice.status === 'overdue' ? 'badge-danger' :
                                          selectedInvoice.status === 'partially_paid' ? 'badge-warning' :
                                          'badge-info'"
                                  x-text="selectedInvoice.status"></span>
                        </dd>
                    </dl>
                </div>
            </div>

            <!-- Submit card -->
            <div class="card">
                <div class="card-body" style="display:flex; flex-direction:column; gap:12px;">

                    <!-- Amount preview -->
                    <div x-show="form.amount > 0" style="background:var(--bg-muted); border-radius:8px; padding:12px;">
                        <div style="font-size:0.8rem; color:var(--text-muted);">You are recording</div>
                        <div class="font-mono" style="font-size:1.5rem; font-weight:700; color:var(--color-success);"
                             x-text="(form.currency || 'CAD') + ' ' + formatCurrency(form.amount)"></div>
                    </div>

                    <!-- Overpayment warning -->
                    <div x-show="isOverpayment()"
                         style="background:rgba(217,119,6,0.1); border:1px solid var(--color-warning);
                                border-radius:8px; padding:10px; font-size:0.85rem; color:var(--color-warning);">
                        ⚠ Amount exceeds invoice balance. The excess will be recorded as an overpayment.
                    </div>

                    <!-- API error -->
                    <p x-show="apiError" x-text="apiError"
                       style="color:var(--color-danger); font-size:0.875rem; background:rgba(220,38,38,0.08);
                              border-radius:6px; padding:8px;"></p>

                    <button type="submit" class="btn btn-primary btn-md" :disabled="submitting">
                        <?= heroicon('credit-card', 'icon-sm') ?>
                        <span x-text="submitting ? 'Recording…' : 'Record Payment'"></span>
                    </button>

                    <a href="<?= base_url('/payments') ?>" class="btn btn-secondary btn-md" style="text-align:center;">
                        Cancel
                    </a>

                </div>
            </div>

        </div><!-- /sidebar -->

    </div><!-- /grid -->

</form>

<script>
function FF_CreatePayment() {
    // Build invoice data map from PHP-rendered options
    const invoiceMap = {};
    <?php foreach ($outstandingInvoices as $inv): ?>
    invoiceMap[<?= (int)$inv['id'] ?>] = {
        id:       <?= (int)$inv['id'] ?>,
        number:   <?= json_encode($inv['invoice_number']) ?>,
        customer: <?= json_encode($inv['company_name_snapshot']) ?>,
        currency: <?= json_encode($inv['currency']) ?>,
        total:    <?= json_encode($inv['total_amount']) ?>,
        balance:  <?= json_encode($inv['balance_due']) ?>,
        status:   <?= json_encode($inv['status']) ?>,
        due:      <?= json_encode($inv['due_date']) ?>,
    };
    <?php endforeach; ?>

    return {
        form: {
            invoice_id:      <?= $preselectedInvoiceId ? (int)$preselectedInvoiceId : 'null' ?> || '',
            amount:          '',
            currency:        <?= $preselectedInvoiceId && isset($outstandingInvoices) ? json_encode(
                array_reduce($outstandingInvoices, function($carry, $inv) use ($preselectedInvoiceId) {
                    return ($inv['id'] == $preselectedInvoiceId) ? $inv['currency'] : $carry;
                }, 'CAD')
            ) : "'CAD'" ?>,
            payment_method:  '',
            payment_date:    new Date().toISOString().slice(0, 10),
            reference_number: '',
            bank_name:       '',
            check_number:    '',
            card_last_four:  '',
            notes:           '',
            internal_notes:  '',
        },
        selectedInvoice: <?php
            $pre = $preselectedInvoiceId
                ? array_filter($outstandingInvoices, fn($i) => $i['id'] == $preselectedInvoiceId)
                : [];
            $preInv = $pre ? array_values($pre)[0] : null;
            echo $preInv ? json_encode([
                'id'       => $preInv['id'],
                'number'   => $preInv['invoice_number'],
                'customer' => $preInv['company_name_snapshot'],
                'currency' => $preInv['currency'],
                'total'    => $preInv['total_amount'],
                'balance'  => $preInv['balance_due'],
                'status'   => $preInv['status'],
                'due'      => $preInv['due_date'],
            ]) : '{}';
        ?>,
        errors:     {},
        apiError:   '',
        submitting: false,

        onInvoiceChange() {
            const id = parseInt(this.form.invoice_id, 10);
            if (id && invoiceMap[id]) {
                this.selectedInvoice  = invoiceMap[id];
                this.form.currency    = invoiceMap[id].currency;  // D18: lock currency to invoice
            } else {
                this.selectedInvoice  = {};
            }
            this.errors.invoice_id = '';
        },

        fillBalance() {
            this.form.amount = this.selectedInvoice.balance;
        },

        validateAmount() {
            const a = parseFloat(this.form.amount);
            if (isNaN(a) || a <= 0) {
                this.errors.amount = 'Amount must be a positive number.';
            } else {
                this.errors.amount = '';
            }
        },

        isOverpayment() {
            if (!this.selectedInvoice.balance || !this.form.amount) return false;
            return parseFloat(this.form.amount) > parseFloat(this.selectedInvoice.balance);
        },

        validate() {
            this.errors = {};
            if (!this.form.invoice_id) this.errors.invoice_id = 'Please select an invoice.';
            if (!this.form.amount || parseFloat(this.form.amount) <= 0) this.errors.amount = 'Amount is required and must be positive.';
            if (!this.form.payment_method) this.errors.payment_method = 'Payment method is required.';
            if (!this.form.payment_date) this.errors.payment_date = 'Payment date is required.';
            return Object.keys(this.errors).length === 0;
        },

        async submitPayment() {
            if (!this.validate()) return;
            this.submitting = true;
            this.apiError   = '';

            const payload = {
                invoice_id:      parseInt(this.form.invoice_id, 10),
                amount:          this.form.amount,
                currency:        this.form.currency,
                payment_method:  this.form.payment_method,
                payment_date:    this.form.payment_date,
                reference_number: this.form.reference_number || null,
                bank_name:       this.form.bank_name || null,
                check_number:    this.form.check_number || null,
                card_last_four:  this.form.card_last_four || null,
                notes:           this.form.notes || null,
                internal_notes:  this.form.internal_notes || null,
            };

            const res = await FF_Api.post('<?= base_url('api/v1/payments/create.php') ?>', payload);
            this.submitting = false;

            if (res.success) {
                // Redirect to the new payment's detail page
                window.location.href = '<?= base_url('/payments/show') ?>?id=' + res.data.id;
            } else {
                this.apiError = res.message ?? 'Failed to record payment. Please try again.';
            }
        },

        formatCurrency(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '—';
            return '$' + n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
