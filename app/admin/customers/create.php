<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Create Page
 *
 * @file        app/admin/customers/create.php
 * @description 7-section form to create a new customer. Alpine.js component
 *              POSTs JSON to api/v1/customers/create.php on submit, then
 *              redirects to the new customer's profile page on success.
 *              Sections: Identity, Address, Regulatory, Billing Contact,
 *              Commercial, Tags, Notes.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/customers/create.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @design      D22 — gst_exempt and pst_exempt are independent booleans
 * @session     S005
 */

// dirname(__DIR__, 3): app/admin/customers/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'create');

$pageTitle = 'New Customer';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <div style="margin-bottom:6px;">
            <a href="<?= base_url('customers') ?>" class="btn btn-ghost btn-sm">← Customers</a>
        </div>
        <h1 class="page-header-title h4">New Customer</h1>
    </div>
</div>

<!-- ============================================================
     CREATE FORM ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_CustomerForm()" x-init="init()">

    <form @submit.prevent="submit()" novalidate>

        <!-- ── § IDENTITY ────────────────────────────────────── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <span class="card-title">Identity</span>
            </div>
            <div class="card-body">
                <div class="grid-2">

                    <div class="form-group" style="grid-column:1 / -1;">
                        <label class="form-label" for="company_name">
                            Company Name <span style="color:var(--color-danger);">*</span>
                        </label>
                        <input type="text" id="company_name" class="form-control"
                               x-model="form.company_name"
                               :class="{ 'is-invalid': errors.company_name }"
                               maxlength="255" autocomplete="organization" required>
                        <div class="form-hint" style="text-align:right;" x-text="(form.company_name || '').length + ' / 255'"></div>
                        <div class="form-error" x-show="errors.company_name"
                             x-text="errors.company_name"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="contact_name">Primary Contact Name</label>
                        <input type="text" id="contact_name" class="form-control"
                               x-model="form.contact_name"
                               maxlength="255" autocomplete="name">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" id="email" class="form-control"
                               x-model="form.email"
                               :class="{ 'is-invalid': errors.email }"
                               maxlength="255" autocomplete="email"
                               @blur="if(form.email && !isValidEmail(form.email)) errors.email = 'Invalid email format.'; else if(errors.email === 'Invalid email format.') errors.email = '';">
                        <div class="form-error" x-show="errors.email"
                             x-text="errors.email"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="tel" id="phone" class="form-control"
                               x-model="form.phone"
                               maxlength="50" autocomplete="tel">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="alt_phone">Alt Phone</label>
                        <input type="tel" id="alt_phone" class="form-control"
                               x-model="form.alt_phone"
                               maxlength="50">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="website">Website</label>
                        <input type="url" id="website" class="form-control"
                               x-model="form.website"
                               maxlength="500" placeholder="https://">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="status">Status</label>
                        <select id="status" class="form-select" x-model="form.status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="pending">Pending</option>
                            <option value="suspended">Suspended</option>
                            <option value="credit_hold">Credit Hold</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="risk_score">Risk Level</label>
                        <select id="risk_score" class="form-select" x-model="form.risk_score">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── § ADDRESS ─────────────────────────────────────── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <span class="card-title">Address</span>
            </div>
            <div class="card-body">
                <div class="grid-2">

                    <div class="form-group" style="grid-column:1 / -1;">
                        <label class="form-label" for="address">Street Address</label>
                        <input type="text" id="address" class="form-control"
                               x-model="form.address"
                               maxlength="500" autocomplete="street-address">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="city">City</label>
                        <input type="text" id="city" class="form-control"
                               x-model="form.city" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="province">Province / State</label>
                        <input type="text" id="province" class="form-control"
                               x-model="form.province" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="postal_code">Postal / ZIP Code</label>
                        <input type="text" id="postal_code" class="form-control"
                               x-model="form.postal_code" maxlength="20"
                               autocomplete="postal-code">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="country">Country</label>
                        <input type="text" id="country" class="form-control"
                               x-model="form.country" maxlength="100">
                    </div>

                </div>
            </div>
        </div>

        <!-- ── § REGULATORY ──────────────────────────────────── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <span class="card-title">Regulatory &amp; Tax</span>
            </div>
            <div class="card-body">
                <div class="grid-2">

                    <div class="form-group">
                        <label class="form-label" for="dot_number">DOT Number</label>
                        <input type="text" id="dot_number" class="form-control"
                               x-model="form.dot_number" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="mc_number">MC Number</label>
                        <input type="text" id="mc_number" class="form-control"
                               x-model="form.mc_number" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="gst_number">GST Number</label>
                        <input type="text" id="gst_number" class="form-control"
                               x-model="form.gst_number" maxlength="50">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="pst_number">PST Number</label>
                        <input type="text" id="pst_number" class="form-control"
                               x-model="form.pst_number" maxlength="50">
                    </div>

                    <!-- D22: GST and PST exempt are independent booleans -->
                    <div class="form-group">
                        <label class="form-check">
                            <input type="checkbox" class="form-check-input"
                                   x-model="form.gst_exempt">
                            <span class="form-check-label">GST/HST Exempt</span>
                        </label>
                        <template x-if="form.gst_exempt">
                            <div style="margin-top:10px; display:flex; flex-direction:column; gap:8px;">
                                <input type="text" class="form-control form-control-sm"
                                       placeholder="GST exemption cert. number"
                                       x-model="form.gst_exempt_number" maxlength="100">
                                <div style="display:flex;gap:6px;align-items:center;">
                                    <input type="date" class="form-control form-control-sm"
                                           title="Expiry date (optional)"
                                           x-model="form.gst_exempt_expiry"
                                           min="<?= date('Y-m-d') ?>" style="flex:1;">
                                    <button type="button" class="btn btn-ghost btn-sm" style="padding:0 8px;height:32px;flex-shrink:0;" title="Open calendar" @click="$el.previousElementSibling.showPicker ? $el.previousElementSibling.showPicker() : $el.previousElementSibling.click()">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="form-group">
                        <label class="form-check">
                            <input type="checkbox" class="form-check-input"
                                   x-model="form.pst_exempt">
                            <span class="form-check-label">PST Exempt</span>
                        </label>
                        <template x-if="form.pst_exempt">
                            <div style="margin-top:10px; display:flex; flex-direction:column; gap:8px;">
                                <input type="text" class="form-control form-control-sm"
                                       placeholder="PST exemption cert. number"
                                       x-model="form.pst_exempt_number" maxlength="100">
                                <div style="display:flex;gap:6px;align-items:center;">
                                    <input type="date" class="form-control form-control-sm"
                                           title="Expiry date (optional)"
                                           x-model="form.pst_exempt_expiry"
                                           min="<?= date('Y-m-d') ?>" style="flex:1;">
                                    <button type="button" class="btn btn-ghost btn-sm" style="padding:0 8px;height:32px;flex-shrink:0;" title="Open calendar" @click="$el.previousElementSibling.showPicker ? $el.previousElementSibling.showPicker() : $el.previousElementSibling.click()">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── § BILLING CONTACT ─────────────────────────────── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <span class="card-title">Billing Contact</span>
            </div>
            <div class="card-body">
                <div class="grid-2">

                    <div class="form-group">
                        <label class="form-label" for="billing_contact_name">Billing Contact Name</label>
                        <input type="text" id="billing_contact_name" class="form-control"
                               x-model="form.billing_contact_name" maxlength="255">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="billing_email">Billing Email</label>
                        <input type="email" id="billing_email" class="form-control"
                               x-model="form.billing_email" maxlength="255"
                               @blur="if(form.billing_email && !isValidEmail(form.billing_email)) errors.billing_email = 'Invalid email format.'; else errors.billing_email = '';">
                        <div class="form-error" x-show="errors.billing_email" x-text="errors.billing_email"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="billing_phone">Billing Phone</label>
                        <input type="tel" id="billing_phone" class="form-control"
                               x-model="form.billing_phone" maxlength="50">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="invoice_email">Invoice Email</label>
                        <input type="email" id="invoice_email" class="form-control"
                               x-model="form.invoice_email" maxlength="255"
                               @blur="if(form.invoice_email && !isValidEmail(form.invoice_email)) errors.invoice_email = 'Invalid email format.'; else errors.invoice_email = '';">
                        <div class="form-error" x-show="errors.invoice_email" x-text="errors.invoice_email"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="invoice_delivery">Invoice Delivery</label>
                        <select id="invoice_delivery" class="form-select"
                                x-model="form.invoice_delivery">
                            <option value="email">Email</option>
                            <option value="mail">Mail</option>
                            <option value="portal">Portal</option>
                            <option value="none">None</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-check">
                            <input type="checkbox" class="form-check-input"
                                   x-model="form.po_required">
                            <span class="form-check-label">PO Number Required</span>
                        </label>
                        <template x-if="form.po_required">
                            <input type="text" class="form-control form-control-sm"
                                   style="margin-top:10px;"
                                   placeholder="Default PO number"
                                   x-model="form.default_po_number" maxlength="100">
                        </template>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── § COMMERCIAL ──────────────────────────────────── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <span class="card-title">Commercial Terms</span>
            </div>
            <div class="card-body">
                <div class="grid-2">

                    <div class="form-group">
                        <label class="form-label" for="currency">Currency</label>
                        <select id="currency" class="form-select" x-model="form.currency">
                            <option value="CAD">CAD</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="mileage_unit">Mileage Unit</label>
                        <select id="mileage_unit" class="form-select" x-model="form.mileage_unit">
                            <option value="km">Kilometres</option>
                            <option value="miles">Miles</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="payment_terms">Payment Terms</label>
                        <input type="text" id="payment_terms" class="form-control"
                               x-model="form.payment_terms" maxlength="100"
                               placeholder="e.g. Net 30">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="credit_limit">Credit Limit</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="credit_limit" class="form-control"
                                   x-model="form.credit_limit"
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="billing_cycle">Billing Cycle</label>
                        <select id="billing_cycle" class="form-select" x-model="form.billing_cycle">
                            <option value="monthly">Monthly</option>
                            <option value="on_close_only">On Close Only</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="discount_type">Discount Type</label>
                        <select id="discount_type" class="form-select" x-model="form.discount_type">
                            <option value="none">None</option>
                            <option value="percentage">Percentage (%)</option>
                            <option value="flat">Flat ($)</option>
                        </select>
                    </div>

                    <template x-if="form.discount_type !== 'none'">
                        <div class="form-group">
                            <label class="form-label" for="discount_value">Discount Value</label>
                            <input type="number" id="discount_value" class="form-control"
                                   x-model="form.discount_value"
                                   min="0" step="0.0001">
                        </div>
                    </template>

                </div>
            </div>
        </div>

        <!-- ── § TAGS ─────────────────────────────────────────── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <span class="card-title">Tags</span>
            </div>
            <div class="card-body">
                <!-- WHY: no tag-picker CSS class exists; render as togglable badge labels -->
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                    <template x-for="tag in allTags" :key="tag">
                        <label style="cursor:pointer; user-select:none;">
                            <input type="checkbox"
                                   style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;"
                                   :checked="form.tags.includes(tag)"
                                   @change="toggleTag(tag)">
                            <span class="badge"
                                  :class="form.tags.includes(tag) ? 'badge-primary' : 'badge-neutral'"
                                  x-text="tag"></span>
                        </label>
                    </template>
                </div>
            </div>
        </div>

        <!-- ── § INITIAL NOTE ────────────────────────────────── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <span class="card-title">Initial Note</span>
                <span class="text-secondary text-sm">Optional — added as the first customer note</span>
            </div>
            <div class="card-body">
                <textarea class="form-control"
                          x-model="form.notes"
                          rows="4"
                          maxlength="2000"
                          placeholder="Add any initial notes about this customer…"></textarea>
                <div class="form-hint" style="text-align:right;" x-text="(form.notes || '').length + ' / 2000'"></div>
            </div>
        </div>

        <!-- ── FORM ACTIONS ──────────────────────────────────── -->
        <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:32px;">
            <a href="<?= base_url('customers') ?>" class="btn btn-secondary btn-md">Cancel</a>
            <button type="submit"
                    class="btn btn-primary btn-md"
                    :disabled="submitting"
                    x-text="submitting ? 'Saving…' : 'Create Customer'">
                Create Customer
            </button>
        </div>

        <!-- Global submit error -->
        <div x-show="submitError"
             style="padding:12px 16px; background:var(--color-danger-light); color:var(--color-danger-text); border-radius:var(--radius-md); font-size:0.875rem; margin-bottom:24px;"
             x-text="submitError"
             role="alert">
        </div>

    </form>

</div>

<script>
function FF_CustomerForm() {
    return {
        submitting:         false,
        showSuccessOverlay: false,
        submitError: null,
        errors:      {},
        form: {
            company_name:          '',
            contact_name:          '',
            email:                 '',
            phone:                 '',
            alt_phone:             '',
            website:               '',
            address:               '',
            city:                  '',
            province:              '',
            postal_code:           '',
            country:               'Canada',
            dot_number:            '',
            mc_number:             '',
            gst_number:            '',
            pst_number:            '',
            billing_contact_name:  '',
            billing_email:         '',
            billing_phone:         '',
            invoice_email:         '',
            invoice_delivery:      'email',
            po_required:           false,
            default_po_number:     '',
            gst_exempt:            false,
            pst_exempt:            false,
            gst_exempt_number:     '',
            pst_exempt_number:     '',
            gst_exempt_expiry:     '',
            pst_exempt_expiry:     '',
            currency:              'CAD',
            mileage_unit:          'km',
            billing_cycle:         'monthly',
            discount_type:         'none',
            discount_value:        '',
            payment_terms:         '',
            credit_limit:          '',
            status:                'active',
            risk_score:            'low',
            tags:                  [],
            notes:                 '',
        },
        allTags: [
            'vip','preferred','owner-operator','fleet','net-30','net-45','net-60',
            'cod','tax-exempt','high-risk','watchlist','credit-hold','delinquent',
            'new','seasonal','government','broker'
        ],

        init() {},

        isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        validate() {
            this.errors = {};
            if (!this.form.company_name.trim()) {
                this.errors.company_name = 'Company name is required.';
            }
            if (this.form.email && !this.isValidEmail(this.form.email)) {
                this.errors.email = 'Invalid email format.';
            }
            if (this.form.billing_email && !this.isValidEmail(this.form.billing_email)) {
                this.errors.billing_email = 'Invalid email format.';
            }
            if (this.form.invoice_email && !this.isValidEmail(this.form.invoice_email)) {
                this.errors.invoice_email = 'Invalid email format.';
            }
            return Object.keys(this.errors).length === 0;
        },

        toggleTag(tag) {
            const idx = this.form.tags.indexOf(tag);
            if (idx === -1) this.form.tags.push(tag);
            else            this.form.tags.splice(idx, 1);
        },

        async submit() {
            this.submitError = null;
            if (!this.validate()) {
                this.submitError = 'Please correct the errors above.';
                return;
            }
            this.submitting  = true;
            this.errors      = {};

            // Strip empty strings so optional fields are omitted from the payload
            const payload = Object.fromEntries(
                Object.entries(this.form).filter(([k, v]) => v !== '' && v !== null)
            );

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const res  = await fetch('<?= base_url('api/v1/customers/create') ?>', {
                    method:  'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-Token':     csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();

                if (res.ok && json.success) {
                    this.showSuccessOverlay = true;
                    const _newId = json.data.id;
                    setTimeout(() => { window.location.href = '<?= base_url('customers/show') ?>?id=' + _newId; }, 3500);
                    return;
                }

                if (res.status === 422 && json.errors) {
                    this.errors      = json.errors;
                    this.submitError = 'Please correct the errors above.';
                } else {
                    this.submitError = json.error?.message ?? 'Failed to create customer.';
                }
            } catch (e) {
                this.submitError = 'Network error. Please try again.';
            } finally {
                this.submitting = false;
            }
        },
    };
}
</script>

<?php
$overlayTitle    = 'Customer Created!';
$overlaySubtitle = 'Redirecting to customer profile…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
