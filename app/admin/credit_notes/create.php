<?php
declare(strict_types=1);

/**
 * app/admin/credit_notes/create.php
 *
 * Create a new credit note. Allows selecting customer (required),
 * source type, amount, currency, reason, and optional linkage to
 * a lease, source invoice, or source payment.
 *
 * On successful submit → redirected to credit_notes/show?id={new_id}.
 *
 * D32: CSS classes verified in app.css before use.
 * D30: base_url() for all links.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @decisions D16/D18/D30/D32
 * @session  S011
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'create');

$pageTitle = 'New Credit Note';
$helpModuleSlug = 'credit-notes';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- Page header -->
<div class="page-header">
    <div style="display:flex; align-items:center; gap:0.75rem;">
        <a href="<?= base_url('credit_notes') ?>" class="btn btn-sm btn-secondary">
            <?= heroicon('arrow-left', 'icon-sm') ?> Back
        </a>
        <div>
            <h1 class="page-header-title h4">New Credit Note</h1>
            <p style="margin:4px 0 0; color:var(--text-secondary); font-size:0.9rem;">Issue a credit note against a customer account</p>
        </div>
    </div>
    <div class="page-header-actions">
        <?= help_button('credit-notes') ?>
    </div>
</div>

<!-- Form -->
<div x-data="createCreditNote()" x-init="init()">

<div class="card" style="max-width:680px;">
    <div class="card-body">

        <div x-show="error" class="alert alert-danger" x-text="error" style="margin-bottom:1.25rem;"></div>

        <!-- Customer (search-as-you-type picker) -->
        <div class="form-group" style="margin-bottom:1.25rem;">
            <label class="form-label">Customer <span style="color:var(--color-danger);">*</span></label>
            <?php
            // WHY picker: replaces static <select> of all customers with
            // debounced autocomplete so the list stays usable past ~50 rows.
            $pickerConfig    = [
                'endpoint'    => '/api/v1/customers/index.php',
                'searchParam' => 'search',
                'resultKey'   => 'items',
                'perPage'     => 10,
                'extraParams' => 'status=active',
                'placeholder' => 'Search customers by name, DOT, MC…',
                'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: [r.contact_name, r.city, r.province].filter(Boolean).join(' · '), raw: r })",
            ];
            $pickerOnPicked  = 'form.customer_id = $event.detail.id; onCustomerChange()';
            $pickerOnCleared = "form.customer_id = ''; onCustomerChange()";
            require FF_ROOT . '/includes/partials/record-picker.php';
            ?>
        </div>

        <!-- Source type + Expiry date -->
        <div class="form-row-2" style="margin-bottom:1.25rem;">
            <div class="form-group">
                <label class="form-label">Source Type <span style="color:var(--color-danger);">*</span></label>
                <select class="form-select" x-model="form.source" :disabled="submitting">
                    <option value="">— Select Source —</option>
                    <option value="goodwill">Goodwill</option>
                    <option value="invoice_adjustment">Invoice Adjustment</option>
                    <option value="damage_resolution">Damage Resolution</option>
                    <option value="mileage_overpayment">Mileage Overpayment</option>
                    <option value="payment_returned">Payment Returned</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Expiry Date <span style="color:var(--text-tertiary);">(optional)</span></label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input class="form-input" type="date" x-model="form.expires_at" :disabled="submitting"
                           min="<?= date('Y-m-d') ?>" x-ref="cnExpiry" style="flex:1;">
                    <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" :disabled="submitting" @click="$refs.cnExpiry.showPicker ? $refs.cnExpiry.showPicker() : $refs.cnExpiry.click()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                    </button>
                </div>
                <div class="form-hint">Leave blank for no expiry. Expired notes cannot be applied to invoices.</div>
            </div>
        </div>

        <!-- Amount + Currency -->
        <div style="display:grid; grid-template-columns:1fr 120px; gap:1rem; margin-bottom:1.25rem;">
            <div class="form-group">
                <label class="form-label">Amount <span style="color:var(--color-danger);">*</span></label>
                <input class="form-input font-mono" type="text" x-model="form.amount"
                       placeholder="0.00" :disabled="submitting">
            </div>
            <div class="form-group">
                <label class="form-label">Currency <span style="color:var(--color-danger);">*</span></label>
                <select class="form-select" x-model="form.currency" :disabled="submitting">
                    <option value="CAD">CAD</option>
                    <option value="USD">USD</option>
                </select>
            </div>
        </div>

        <!-- Reason -->
        <div class="form-group" style="margin-bottom:1.25rem;">
            <label class="form-label">Reason <span style="color:var(--color-danger);">*</span></label>
            <textarea class="form-input" rows="3" x-model="form.reason" :disabled="submitting"
                      placeholder="Describe why this credit is being issued…"></textarea>
            <div class="form-hint">Visible to the customer on statements and credit note document.</div>
        </div>

        <!-- Optional linkage (collapsed by default) -->
        <div style="margin-bottom:1.25rem;">
            <button type="button" class="btn btn-sm btn-secondary" @click="showLinks = !showLinks">
                <span x-text="showLinks ? '▾ Hide optional links' : '▸ Link to Lease / Invoice / Payment'"></span>
            </button>
        </div>

        <template x-if="showLinks">
            <div style="display:flex; flex-direction:column; gap:1rem; margin-bottom:1.25rem; padding:1rem; background:var(--bg-subtle); border-radius:6px;">
                <!-- Lease picker (optional) -->
                <div class="form-group">
                    <label class="form-label">Lease <span style="color:var(--text-tertiary);">(optional)</span></label>
                    <?php
                    $pickerConfig    = [
                        'endpoint'    => '/api/v1/leases/index.php',
                        'searchParam' => 'search',
                        'resultKey'   => 'items',
                        'perPage'     => 10,
                        'placeholder' => 'Search leases by contract #…',
                        'mapResult'   => "r => ({ id: r.id, label: r.contract_number, sublabel: [r.company_name_snapshot, r.unit_number_snapshot ? ('Unit ' + r.unit_number_snapshot) : '', r.status].filter(Boolean).join(' · '), raw: r })",
                    ];
                    $pickerOnPicked  = 'form.lease_id = $event.detail.id';
                    $pickerOnCleared = "form.lease_id = ''";
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    ?>
                </div>

                <!-- Source invoice picker (optional) -->
                <div class="form-group">
                    <label class="form-label">Source Invoice <span style="color:var(--text-tertiary);">(optional)</span></label>
                    <?php
                    $pickerConfig    = [
                        'endpoint'    => '/api/v1/invoices/index.php',
                        'searchParam' => 'q',
                        'resultKey'   => 'items',
                        'perPage'     => 10,
                        'placeholder' => 'Search invoices by invoice #…',
                        'mapResult'   => "r => ({ id: r.id, label: r.invoice_number, sublabel: [r.company_name, '\$' + (r.total_amount || '0.00'), r.status].filter(Boolean).join(' · '), raw: r })",
                    ];
                    $pickerOnPicked  = 'form.source_invoice_id = $event.detail.id';
                    $pickerOnCleared = "form.source_invoice_id = ''";
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    ?>
                </div>

                <!-- Source payment picker (optional) -->
                <div class="form-group">
                    <label class="form-label">Source Payment <span style="color:var(--text-tertiary);">(optional)</span></label>
                    <?php
                    $pickerConfig    = [
                        'endpoint'    => '/api/v1/payments/index.php',
                        'searchParam' => 'q',
                        'resultKey'   => 'items',
                        'perPage'     => 10,
                        'placeholder' => 'Search payments by payment #…',
                        'mapResult'   => "r => ({ id: r.id, label: r.payment_number || ('Payment #' + r.id), sublabel: [r.company_name, '\$' + (r.amount || '0.00'), r.status].filter(Boolean).join(' · '), raw: r })",
                    ];
                    $pickerOnPicked  = 'form.source_payment_id = $event.detail.id';
                    $pickerOnCleared = "form.source_payment_id = ''";
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    ?>
                </div>
            </div>
        </template>

        <!-- Internal notes -->
        <div class="form-group" style="margin-bottom:1.5rem;">
            <label class="form-label">Internal Notes <span style="color:var(--text-tertiary);">(staff only)</span></label>
            <textarea class="form-input" rows="2" x-model="form.internal_notes" :disabled="submitting"
                      placeholder="Internal context, approval notes, etc."></textarea>
        </div>

        <!-- Actions -->
        <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
            <a href="<?= base_url('credit_notes') ?>" class="btn btn-secondary btn-md">Cancel</a>
            <button class="btn btn-primary btn-md" @click="submit()"
                    :disabled="submitting || !form.customer_id || !form.source || !form.amount || !form.reason">
                <span x-show="!submitting"><?= heroicon('plus', 'icon-sm') ?> Issue Credit Note</span>
                <span x-show="submitting">Issuing…</span>
            </button>
        </div>

    </div>
</div><!-- /card -->

<?php
$overlayTitle    = 'Credit Note Created!';
$overlaySubtitle = 'Redirecting to credit note details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

</div><!-- /x-data wrapper -->

<script>
function createCreditNote() {
    return {
        form: {
            customer_id:       '',
            source:            '',
            amount:            '',
            currency:          'CAD',
            reason:            '',
            expires_at:        '',
            lease_id:          '',
            source_invoice_id: '',
            source_payment_id: '',
            internal_notes:    '',
        },
        showLinks:          false,
        submitting:         false,
        showSuccessOverlay: false,
        error: '',

        init() {
            // Pre-fill customer_id from query string if provided
            const params = new URLSearchParams(window.location.search);
            if (params.get('customer_id')) {
                this.form.customer_id = params.get('customer_id');
            }
            if (window.FF_FormDraft) { // S-FORM-DRAFT-ROLLOUT
                this._draft = FF_FormDraft.attach({ // S-FORM-DRAFT-ROLLOUT
                    formId: 'credit-note-create', // S-FORM-DRAFT-ROLLOUT
                    entityId: 'new', // S-FORM-DRAFT-ROLLOUT
                    el: this.$root, // S-FORM-DRAFT-ROLLOUT
                    model: this.form, // S-FORM-DRAFT-ROLLOUT
                    version: '1', // S-FORM-DRAFT-ROLLOUT
                    exclude: ['customer_id', 'lease_id', 'source_invoice_id', 'source_payment_id'], // S-FORM-DRAFT-ROLLOUT
                }); // S-FORM-DRAFT-ROLLOUT
            } // S-FORM-DRAFT-ROLLOUT
        },

        onCustomerChange() {
            // Reset linked IDs when customer changes — prevent stale cross-customer links
            this.form.lease_id          = '';
            this.form.source_invoice_id = '';
            this.form.source_payment_id = '';
        },

        submit() {
            this.error = '';
            this.submitting = true;

            const payload = {
                customer_id: parseInt(this.form.customer_id) || null,
                source:      this.form.source,
                amount:      this.form.amount,
                currency:    this.form.currency,
                reason:      this.form.reason,
            };

            // Optional fields — only include if non-empty
            if (this.form.expires_at)        payload.expires_at        = this.form.expires_at;
            if (this.form.internal_notes)    payload.internal_notes    = this.form.internal_notes;
            if (parseInt(this.form.lease_id))          payload.lease_id          = parseInt(this.form.lease_id);
            if (parseInt(this.form.source_invoice_id)) payload.source_invoice_id = parseInt(this.form.source_invoice_id);
            if (parseInt(this.form.source_payment_id)) payload.source_payment_id = parseInt(this.form.source_payment_id);

            FF_Api.post('<?= base_url('api/v1/credit_notes/create') ?>', payload)
                .then(data => {
                    if (data.success && this._draft) this._draft.clear(true); // S-FORM-DRAFT-ROLLOUT (only on confirmed success)
                    // Show success overlay then redirect to the new credit note's detail page
                    this.showSuccessOverlay = true;
                    const _newId = data.data.id;
                    setTimeout(() => { window.location.href = '<?= base_url('credit_notes/show') ?>?id=' + _newId; }, 3500);
                })
                .catch(err => {
                    this.error = err.message || 'Failed to create credit note.';
                    this.submitting = false;
                });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
