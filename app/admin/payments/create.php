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
 * Overpayments are allowed (the API issues the excess as an 'overpayment' credit
 * note): the form shows the invoice/credit split and confirms before posting.
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

// SOP I10: the bank accounts money can be deposited to. The payment's ledger
// entry debits the chosen account's GL account; blank = the currency's
// default bank (or the Settings cash account when none is set up).
$depositBanks = db_select(
    "SELECT id, name, currency, is_default FROM acc_bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, name",
    []
);

// If an invoice_id was passed in the query string (coming from invoice show page), pre-select it
$preselectedInvoiceId = clean_int($_GET['invoice_id'] ?? null);

$pageTitle      = 'Record Payment';
$helpModuleSlug = 'payments';
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
        <?= help_button('payments') ?>
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
                        // WHY the statuses scope: only sent / partially_paid / overdue
                        //      invoices can take a payment (the API 422s everything
                        //      else). This picker used to carry a note saying the API
                        //      only accepted a single status and that a "client-side
                        //      filter below" would narrow it — that filter never
                        //      existed, so drafts, voids and paid invoices were all
                        //      offered and the user only found out on submit.
                        //      invoices/index.php has accepted `statuses=a,b,c` since
                        //      the Outstanding tab; scope server-side, oldest due first.
                        'extraParams' => 'statuses=sent,partially_paid,overdue&sort=due_date&dir=ASC',
                        'placeholder' => 'Search invoices by invoice # or customer…',
                        // Customer is omitted (not rendered as a dangling "— ") when the
                        // invoice carries no company_name_snapshot.
                        'mapResult'   => "r => ({ id: r.id, label: r.invoice_number + (r.company_name_snapshot ? ' — ' + r.company_name_snapshot : ''), sublabel: [r.currency + ' ' + (r.balance_due || '0.00') + ' due', r.due_date ? ('due ' + r.due_date) : '', r.status].filter(Boolean).join(' · '), raw: r })",
                    ];
                    if ($preselectedInvoice) {
                        $pickerConfig['initialId']    = (int) $preselectedInvoice['id'];
                        // Same label shape as mapResult above (no dangling " — " without a snapshot).
                        $pickerConfig['initialLabel'] = $preselectedInvoice['invoice_number']
                            . (($preselectedInvoice['company_name_snapshot'] ?? '') !== '' ? ' — ' . $preselectedInvoice['company_name_snapshot'] : '');
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
                            <!-- WHY x-text: the amount is Alpine state, not PHP. This used to
                                 echo a literal PHP short-echo open/close tag pair around the JS
                                 expression into the HTML; the browser parses that as a bogus
                                 comment and drops it, so the button read "Pay full balance ()".
                                 The whole label is one x-text because .btn is inline-flex with a
                                 gap — a nested <span> would render as a separately spaced item. -->
                            <button type="button" class="btn btn-secondary btn-xs"
                                    @click="fillBalance()"
                                    x-text="'Pay full balance (' + selectedInvoice.currency + ' ' + formatCurrency(selectedInvoice.balance) + ')'">
                                Pay full balance
                            </button>
                        </div>
                        <!-- Overpayment notice. The server (api/v1/payments/create.php,
                             S-FIX-2 Bug #2) allocates exactly the balance to the invoice and
                             issues the excess as an 'overpayment' credit note on the
                             customer's account, so the form WARNS instead of blocking.
                             Arguments are passed explicitly so Alpine tracks form.amount and
                             the selected balance directly (getter dep-tracking trap). -->
                        <div class="alert alert-warning" role="status"
                             style="margin-top:8px; font-size:0.85rem; padding:8px 12px;"
                             x-show="overpaymentCents(form.amount, selectedInvoice.balance) > 0" x-cloak>
                            <strong>Overpayment of
                                <span class="font-mono" x-text="selectedInvoice.currency + ' ' + centsToMoney(overpaymentCents(form.amount, selectedInvoice.balance))"></span></strong>
                            will be issued as account credit.
                            <span x-text="'Only ' + selectedInvoice.currency + ' ' + formatCurrency(selectedInvoice.balance) + ' is applied to ' + (selectedInvoice.number || 'the invoice') + '; the rest becomes a credit note the customer can use on a future invoice.'"></span>
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

                <!-- Payment method + date row -->
                <div class="form-row-2">
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
                </div><!-- /payment method + date row -->

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
                    <?php if ($depositBanks): ?>
                    <div class="form-group">
                        <label class="form-label">Deposited to</label>
                        <select class="form-input" x-model="form.deposit_bank_account_id">
                            <option value="">Default account for this currency</option>
                            <template x-for="b in depositBanks.filter(b => b.currency === form.currency)" :key="b.id">
                                <option :value="b.id" x-text="b.name + (Number(b.is_default) ? ' (default)' : '')"></option>
                            </template>
                        </select>
                        <div class="form-hint text-secondary text-sm">The FleetForge bank account the money went into. Its ledger account is debited.</div>
                    </div>
                    <?php endif; ?>
                    <div class="form-group" x-show="['check','ach','wire'].includes(form.payment_method)">
                        <label class="form-label">Customer's Bank</label>
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
                        <!-- Split preview — mirrors the server's allocation: balance to the
                             invoice, remainder to an overpayment credit note. -->
                        <!-- x-show on the wrapper: Alpine strips the inline `display` when it
                             re-shows an element, which would drop display:grid. -->
                        <div x-show="overpaymentCents(form.amount, selectedInvoice.balance) > 0" x-cloak>
                        <div style="margin-top:8px; font-size:0.8rem; color:var(--text-secondary); display:grid; grid-template-columns:1fr auto; gap:2px 8px;">
                            <span>Applied to invoice</span>
                            <span class="font-mono" x-text="formatCurrency(selectedInvoice.balance)"></span>
                            <span>Account credit</span>
                            <span class="font-mono" x-text="centsToMoney(overpaymentCents(form.amount, selectedInvoice.balance))"></span>
                        </div>
                        </div>
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
            // FF_localDate (includes/header.php): the company-timezone day. The old
            // toISOString() default was the UTC day — after 5pm Pacific it pre-filled
            // TOMORROW, past the date input's own max (server-rendered local today).
            payment_date:    FF_localDate(),
            reference_number: '',
            bank_name:       '',
            deposit_bank_account_id: '',
            check_number:    '',
            card_last_four:  '',
            notes:           '',
            internal_notes:  '',
        },
        depositBanks: <?= json_encode($depositBanks, JSON_HEX_TAG) ?>,
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
        _confirming:        false,   // overpayment confirm dialog open (see submitPayment)
        showSuccessOverlay: false,

        init() { // S-FORM-DRAFT-ROLLOUT
            if (window.FF_FormDraft) { // S-FORM-DRAFT-ROLLOUT
                this._draft = FF_FormDraft.attach({ // S-FORM-DRAFT-ROLLOUT
                    formId: 'payment-create', // S-FORM-DRAFT-ROLLOUT
                    entityId: 'new', // S-FORM-DRAFT-ROLLOUT
                    el: this.$root, // S-FORM-DRAFT-ROLLOUT
                    model: this.form, // S-FORM-DRAFT-ROLLOUT
                    version: '1', // S-FORM-DRAFT-ROLLOUT
                    exclude: ['invoice_id'], // S-FORM-DRAFT-ROLLOUT
                }); // S-FORM-DRAFT-ROLLOUT
            } // S-FORM-DRAFT-ROLLOUT
        }, // S-FORM-DRAFT-ROLLOUT

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
                }
                // WHY no "exceeds invoice balance" rejection: api/v1/payments/create.php
                // (S-FIX-2 Bug #2) accepts overpayments — it allocates exactly
                // balance_due to the invoice and books the excess as an 'overpayment'
                // credit note (3-line JE: DR cash / CR AR / CR 2060 customer credits).
                // Blocking here contradicted the server and forced staff to record a
                // real cheque at the wrong amount. The inline notice above warns
                // instead, and submitPayment() confirms before posting.
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
            // Re-entrancy guards set BEFORE the first await (the overpayment confirm
            // below suspends; a second click must not open a second dialog/post).
            // WHY a separate _confirming flag: `submitting` also drives the
            // full-screen "Saving…" overlay (includes/success_overlay.php, z-index
            // 9998), which would sit on top of the confirm modal and swallow its clicks.
            if (this.submitting || this._confirming) return;
            if (!this.validate()) return;
            const form = document.querySelector('form');

            // Overpayment: the server will mint a credit note for the excess — a
            // typo'd amount (10800 for 1080.00) would silently park real money as
            // account credit, so make the split explicit before posting.
            const overCents = this.overpaymentCents(this.form.amount, this.selectedInvoice.balance);
            if (overCents > 0) {
                this._confirming = true;
                const cur = this.selectedInvoice.currency || this.form.currency || 'CAD';
                let ok = false;
                try {
                    ok = await FF_Confirm.ask({
                        title:        'Record an overpayment?',
                        message:      `This payment is ${cur} ${this.centsToMoney(overCents)} more than the invoice balance. `
                                    + `${cur} ${this.formatCurrency(this.selectedInvoice.balance)} will be applied to ${this.selectedInvoice.number || 'the invoice'} `
                                    + `and ${cur} ${this.centsToMoney(overCents)} will be issued as account credit (a credit note for this customer).`,
                        confirmLabel: 'Record payment',
                        dangerMode:   false,
                    });
                } finally {
                    this._confirming = false;
                }
                if (!ok) return;
            }
            this.submitting = true;

            const payload = {
                invoice_id:      parseInt(this.form.invoice_id, 10),
                amount:          this.form.amount,
                currency:        this.form.currency,
                payment_method:  this.form.payment_method,
                payment_date:    this.form.payment_date,
                reference_number: this.form.reference_number || null,
                bank_name:       this.form.bank_name || null,
                deposit_bank_account_id: this.form.deposit_bank_account_id ? parseInt(this.form.deposit_bank_account_id, 10) : null,
                check_number:    this.form.check_number || null,
                card_last_four:  this.form.card_last_four || null,
                notes:           this.form.notes || null,
                internal_notes:  this.form.internal_notes || null,
            };

            try {
                const res = await FF_Api.post('<?= base_url('api/v1/payments/create.php') ?>', payload);
                if (res.success) {
                    if (this._draft) this._draft.clear(true); // S-FORM-DRAFT-ROLLOUT
                    // S-ANIMATIONS-PACK Bundle B: celebrate payment received.
                    // Confetti fires AS WELL AS the truck overlay so the moment
                    // feels distinct from a generic "saved" — money in the
                    // door deserves a little party.
                    if (window.FF_Confetti) window.FF_Confetti.burst({ count: 130, duration: 2600 });
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
                    FF_Validate.banner(form, res.error?.message || 'Failed to record payment. Please try again.');
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

        /**
         * Amount above the invoice balance, in integer cents (0 when not an overpayment).
         * Display-only mirror of the server split in api/v1/payments/create.php — the
         * server's bcmath result is authoritative. Integer cents avoid float drift
         * (e.g. 1080.10 - 1080.00 !== 0.10 in IEEE doubles).
         *
         * @param {string|number} amount   entered payment amount
         * @param {string|number} balance  selected invoice balance_due
         * @returns {number} cents over the balance, never negative
         */
        overpaymentCents(amount, balance) {
            if (balance === undefined || balance === null || balance === '') return 0;
            const a = parseFloat(amount), b = parseFloat(balance);
            if (isNaN(a) || isNaN(b)) return 0;
            return Math.max(0, Math.round(a * 100) - Math.round(b * 100));
        },

        /**
         * Format integer cents as "$1,234.56".
         *
         * @param {number} cents
         * @returns {string}
         */
        centsToMoney(cents) {
            return this.formatCurrency((cents || 0) / 100);
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
