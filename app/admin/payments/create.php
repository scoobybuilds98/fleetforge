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
<div x-data="FF_CreatePayment()">
<form @submit.prevent="submitPayment()" novalidate>

    <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start;">

        <!-- ---- Main form card ---- -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Payment Details</h3></div>
            <div class="card-body" style="display:flex; flex-direction:column; gap:20px;">

                <!-- Invoice picker (search-as-you-type) -->
                <div class="form-group">
                    <label class="form-label">
                        Invoice <span style="color:var(--color-danger);">*</span>
                    </label>
                    <?php
                    // SELECTOR-UNIFY: search invoices that can still receive a payment.
                    // The existing API returns the full row incl balance_due, status, due_date,
                    // which onInvoicePickerSelected() uses to populate selectedInvoice live.
                    $preselectedInvoice = null;
                    if ($preselectedInvoiceId) {
                        foreach ($outstandingInvoices as $inv) {
                            if ((int) $inv['id'] === $preselectedInvoiceId) {
                                $preselectedInvoice = $inv;
                                break;
                            }
                        }
                    }
                    $pickerConfig    = [
                        'endpoint'    => '/api/v1/invoices/index.php',
                        'searchParam' => 'q',
                        'resultKey'   => 'items',
                        'perPage'     => 15,
                        // WHY no status filter: invoices/index.php only accepts
                        //      a single status value. Client-side filter below.
                        'placeholder' => 'Search invoices by invoice #…',
                        'mapResult'   => "r => ({ id: r.id, label: r.invoice_number + ' — ' + (r.company_name_snapshot || ''), sublabel: [r.currency + ' ' + (r.balance_due || '0.00') + ' due', r.due_date ? ('due ' + r.due_date) : '', r.status].filter(Boolean).join(' · '), raw: r })",
                    ];
                    if ($preselectedInvoice) {
                        $pickerConfig['initialId']    = (int) $preselectedInvoice['id'];
                        $pickerConfig['initialLabel'] = $preselectedInvoice['invoice_number'] . ' — ' . $preselectedInvoice['company_name_snapshot'];
                    }
                    $pickerOnPicked  = 'form.invoice_id = $event.detail.id; onInvoicePickerSelected($event.detail.raw)';
                    $pickerOnCleared = "form.invoice_id = ''; selectedInvoice = {}";
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    ?>
                    <p class="form-hint" x-show="selectedInvoice.balance">
                        Balance due:
                        <strong class="font-mono" x-text="selectedInvoice.currency + ' ' + formatCurrency(selectedInvoice.balance)"></strong>
                        <span x-show="selectedInvoice.status === 'overdue'"
                              style="color:var(--color-danger); margin-left:6px;">(Overdue)</span>
                    </p>
                    <!-- VALID-2: FF_Validate slot -->
                    <div class="field-error" data-error-for="invoice_id"></div>
                </div>

                <!-- Amount + currency row -->
                <div style="display:grid; grid-template-columns:1fr auto; gap:12px;">
                    <div class="form-group">
                        <label class="form-label" for="amount">
                            Amount <span style="color:var(--color-danger);">*</span>
                        </label>
                        <input type="number" min="0" id="amount" name="amount" class="form-input font-mono"
                               x-model="form.amount"
                               step="0.01"
                               placeholder="0.00"
                               required>
                        <!-- Quick-fill buttons -->
                        <div style="margin-top:6px; display:flex; gap:8px;" x-show="selectedInvoice.balance">
                            <button type="button" class="btn btn-secondary btn-xs"
                                    @click="fillBalance()">
                                Pay full balance (<?= '<?=' ?>  selectedInvoice.currency + ' ' + formatCurrency(selectedInvoice.balance) <?= '?>' ?>)
                            </button>
                        </div>
                        <!-- VALID-2: FF_Validate slot -->
                        <div class="field-error" data-error-for="amount"></div>
                    </div>
                    <div class="form-group" style="width:100px;">
                        <label class="form-label" for="currency">Currency</label>
                        <select id="currency" name="currency" class="form-input" x-model="form.currency" :disabled="!!selectedInvoice.currency">
                            <option value="CAD">CAD</option>
                            <option value="USD">USD</option>
                        </select>
                        <div class="field-error" data-error-for="currency"></div>
                    </div>
                </div>

                <!-- Payment method -->
                <div class="form-group">
                    <label class="form-label" for="payment_method">
                        Payment Method <span style="color:var(--color-danger);">*</span>
                    </label>
                    <select id="payment_method" name="payment_method" class="form-input" x-model="form.payment_method" required>
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
                    <!-- VALID-2: FF_Validate slot -->
                    <div class="field-error" data-error-for="payment_method"></div>
                </div>

                <!-- Payment date -->
                <div class="form-group">
                    <label class="form-label" for="payment_date">
                        Payment Date <span style="color:var(--color-danger);">*</span>
                    </label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="date" id="payment_date" name="payment_date" class="form-input"
                               x-model="form.payment_date" required
                               max="<?= date('Y-m-d') ?>"
                               x-ref="pmtDate" style="flex:1;">
                        <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.pmtDate.showPicker ? $refs.pmtDate.showPicker() : $refs.pmtDate.click()">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                        </button>
                    </div>
                    <!-- VALID-2: FF_Validate slot -->
                    <div class="field-error" data-error-for="payment_date"></div>
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
                    <div style="font-size:0.75rem;color:var(--text-secondary);text-align:right;margin-top:2px;" x-text="(form.notes || '').length + ' / 2000'"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">Internal Notes (staff only)</label>
                    <textarea class="form-input" rows="2"
                              x-model="form.internal_notes" maxlength="2000"
                              placeholder="Internal reference, dispute notes, etc."></textarea>
                    <div style="font-size:0.75rem;color:var(--text-secondary);text-align:right;margin-top:2px;" x-text="(form.internal_notes || '').length + ' / 2000'"></div>
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

                    <!-- VALID-2: form-level error banner injected by FF_Validate.banner() -->
                    <div class="form-error-banner" data-form-error></div>

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

<?php
$overlayTitle    = 'Payment Recorded!';
$overlaySubtitle = 'Redirecting to payment details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

</div><!-- /x-data wrapper -->

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
        submitting:         false,
        showSuccessOverlay: false,

        onInvoiceChange() {
            const id = parseInt(this.form.invoice_id, 10);
            if (id && invoiceMap[id]) {
                this.selectedInvoice  = invoiceMap[id];
                this.form.currency    = invoiceMap[id].currency;  // D18: lock currency to invoice
            } else {
                this.selectedInvoice  = {};
            }
            // VALID-2: clear any prior invoice error on change
            const form = document.querySelector('form');
            if (form) FF_Validate.clear(form);
        },

        // SELECTOR-UNIFY: called by FF_RecordPicker @record-picked dispatch.
        // WHY: with the picker, the invoiceMap lookup doesn't apply — we use
        //      the raw row from the API response directly.
        onInvoicePickerSelected(rawInv) {
            if (!rawInv) { this.selectedInvoice = {}; return; }
            this.selectedInvoice = {
                id:       rawInv.id,
                number:   rawInv.invoice_number,
                customer: rawInv.company_name_snapshot,
                currency: rawInv.currency,
                total:    rawInv.total_amount,
                balance:  rawInv.balance_due,
                status:   rawInv.status,
                due:      rawInv.due_date,
            };
            this.form.currency = rawInv.currency;  // D18: lock currency to invoice
            const form = document.querySelector('form');
            if (form) FF_Validate.clear(form);
        },

        fillBalance() {
            this.form.amount = this.selectedInvoice.balance;
            // VALID-2: clear any prior amount error when auto-filling
            const form = document.querySelector('form');
            if (form) FF_Validate.clear(form);
        },

        // VALID-2: unified client-side validation — exact spec messages, all in one pass
        validate() {
            const form = document.querySelector('form');
            FF_Validate.clear(form);
            let ok = true;

            if (!this.form.invoice_id) {
                FF_Validate.field(form, 'invoice_id', 'Please select an invoice.');
                ok = false;
            }

            // Amount — exact spec messages for missing / negative / zero / exceed
            const rawAmt = this.form.amount;
            if (rawAmt === '' || rawAmt === null || rawAmt === undefined) {
                FF_Validate.field(form, 'amount', 'Please enter a payment amount.');
                ok = false;
            } else {
                const a = parseFloat(rawAmt);
                if (isNaN(a)) {
                    FF_Validate.field(form, 'amount', 'Please enter a valid payment amount.');
                    ok = false;
                } else if (a < 0) {
                    FF_Validate.field(form, 'amount', 'Payment amount cannot be negative.');
                    ok = false;
                } else if (a === 0) {
                    FF_Validate.field(form, 'amount', 'Payment amount must be greater than zero.');
                    ok = false;
                } else if (this.selectedInvoice.balance
                           && a > parseFloat(this.selectedInvoice.balance)) {
                    const bal = '$' + parseFloat(this.selectedInvoice.balance)
                        .toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    FF_Validate.field(form, 'amount',
                        `Payment amount exceeds invoice balance of ${bal}.`);
                    ok = false;
                }
            }

            // Currency mismatch — spec message
            if (this.selectedInvoice.currency
                && this.form.currency !== this.selectedInvoice.currency) {
                FF_Validate.field(form, 'currency',
                    `Payment currency must match invoice currency (${this.selectedInvoice.currency}).`);
                ok = false;
            }

            if (!this.form.payment_method) {
                FF_Validate.field(form, 'payment_method', 'Please select a payment method.');
                ok = false;
            }

            if (!this.form.payment_date) {
                FF_Validate.field(form, 'payment_date', 'Payment date is required.');
                ok = false;
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        async submitPayment() {
            if (!this.validate()) return;
            this.submitting = true;
            const form = document.querySelector('form');

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

            try {
                const res = await FF_Api.post('<?= base_url('api/v1/payments/create.php') ?>', payload);
                if (res.success) {
                    this.showSuccessOverlay = true;
                    const _newId = res.data.id;
                    setTimeout(() => {
                        window.location.href = '<?= base_url('/payments/show') ?>?id=' + _newId;
                    }, 3500);
                } else if (res.error?.code === 'VALIDATION_ERROR' && res.error?.fields) {
                    FF_Validate.applyApi(form, res.error);
                    FF_Validate.scrollToFirst(form);
                } else if (res.error?.fields) {
                    // Server returned specific field errors with a non-422 error code
                    FF_Validate.applyApi(form, res.error);
                    FF_Validate.scrollToFirst(form);
                } else {
                    FF_Validate.banner(form, res.error?.message || res.message || 'Failed to record payment. Please try again.');
                    FF_Validate.scrollToFirst(form);
                }
            } catch (e) {
                FF_Validate.banner(form, 'Network error. Please try again.');
                FF_Validate.scrollToFirst(form);
            }
            this.submitting = false;
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
