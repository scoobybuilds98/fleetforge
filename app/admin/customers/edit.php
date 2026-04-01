<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Edit Page
 *
 * @file        app/admin/customers/edit.php
 * @description Pre-populated edit form for an existing customer. Loads the
 *              customer from the database server-side and encodes it as a JSON
 *              literal for the Alpine.js component. Submits to
 *              api/v1/customers/update.php with the current updated_at for D19
 *              optimistic locking. On success, redirects to customer profile.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/customers/update.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @design      D19 — updated_at field for optimistic locking
 *              D22 — gst_exempt and pst_exempt are independent booleans
 * @session     S005
 */

// dirname(__DIR__, 3): app/admin/customers/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'edit');

// ── Resolve customer ID from query string ──────────────────────
$customerId = clean_int($_GET['id'] ?? null);
if (!$customerId || $customerId <= 0) {
    header('Location: ' . base_url('customers'));
    exit;
}

// ── Load customer (server-side for flicker-free initial render) ─
// WHY server-side: avoids Alpine blank-then-fill flash on page load
$customerRow = db_row(
    "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);

if (!$customerRow) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Not Found</h1>';
    exit;
}

// Load tags
$tagRows = db_select(
    "SELECT tag FROM customer_tags WHERE customer_id = ? ORDER BY tag",
    [$customerId]
);
$customerRow['tags'] = array_column($tagRows, 'tag');

// Decode JSON fields for form
if (isset($customerRow['invoice_cc_emails']) && $customerRow['invoice_cc_emails'] !== null) {
    $customerRow['invoice_cc_emails'] = json_decode($customerRow['invoice_cc_emails'], true) ?? [];
}

$pageTitle = 'Edit — ' . $customerRow['company_name'];
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <div style="margin-bottom:6px;">
            <a href="<?= base_url('customers/show') ?>?id=<?= $customerId ?>"
               class="btn btn-ghost btn-sm">
                ← <?= e($customerRow['company_name']) ?>
            </a>
        </div>
        <h1 class="page-header-title h4">Edit Customer</h1>
    </div>
</div>

<!-- ============================================================
     EDIT FORM — Alpine.js component with server-side prefill
     ============================================================ -->
<div x-data="FF_CustomerEditForm()" x-init="init()">

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
                               maxlength="255" required>
                        <div class="form-error" x-show="errors.company_name"
                             x-text="errors.company_name"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="contact_name">Primary Contact Name</label>
                        <input type="text" id="contact_name" class="form-control"
                               x-model="form.contact_name" maxlength="255">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" id="email" class="form-control"
                               x-model="form.email"
                               :class="{ 'is-invalid': errors.email }"
                               maxlength="255">
                        <div class="form-error" x-show="errors.email"
                             x-text="errors.email"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="tel" id="phone" class="form-control"
                               x-model="form.phone" maxlength="50">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="alt_phone">Alt Phone</label>
                        <input type="tel" id="alt_phone" class="form-control"
                               x-model="form.alt_phone" maxlength="50">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="website">Website</label>
                        <input type="url" id="website" class="form-control"
                               x-model="form.website" maxlength="500">
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
                               x-model="form.address" maxlength="500">
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
                               x-model="form.postal_code" maxlength="20">
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
                                <input type="date" class="form-control form-control-sm"
                                       x-model="form.gst_exempt_expiry">
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
                                <input type="date" class="form-control form-control-sm"
                                       x-model="form.pst_exempt_expiry">
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
                               x-model="form.billing_email" maxlength="255">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="billing_phone">Billing Phone</label>
                        <input type="tel" id="billing_phone" class="form-control"
                               x-model="form.billing_phone" maxlength="50">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="invoice_email">Invoice Email</label>
                        <input type="email" id="invoice_email" class="form-control"
                               x-model="form.invoice_email" maxlength="255">
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
                               x-model="form.payment_terms" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="credit_limit">Credit Limit</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="credit_limit" class="form-control"
                                   x-model="form.credit_limit" min="0" step="0.01">
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
                                   x-model="form.discount_value" min="0" step="0.0001">
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

        <!-- ── FORM ACTIONS ──────────────────────────────────── -->
        <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:32px;">
            <a href="<?= base_url('customers/show') ?>?id=<?= $customerId ?>"
               class="btn btn-secondary btn-md">Cancel</a>
            <button type="submit"
                    class="btn btn-primary btn-md"
                    :disabled="submitting"
                    x-text="submitting ? 'Saving…' : 'Save Changes'">
                Save Changes
            </button>
        </div>

        <!-- Submit error -->
        <div x-show="submitError"
             style="padding:12px 16px; background:var(--color-danger-light); color:var(--color-danger-text); border-radius:var(--radius-md); font-size:0.875rem; margin-bottom:24px;"
             x-text="submitError"
             role="alert">
        </div>

    </form>

</div>

<script>
function FF_CustomerEditForm() {
    // Server-side prefill — booleans cast explicitly to avoid PHP integer → JS issues
    const prefill = <?= json_encode([
        'id'                    => (int)    $customerRow['id'],
        'company_name'          =>          $customerRow['company_name'],
        'contact_name'          =>          $customerRow['contact_name'],
        'email'                 =>          $customerRow['email'],
        'phone'                 =>          $customerRow['phone'],
        'alt_phone'             =>          $customerRow['alt_phone'],
        'website'               =>          $customerRow['website'],
        'address'               =>          $customerRow['address'],
        'city'                  =>          $customerRow['city'],
        'province'              =>          $customerRow['province'],
        'postal_code'           =>          $customerRow['postal_code'],
        'country'               =>          $customerRow['country'] ?? 'Canada',
        'dot_number'            =>          $customerRow['dot_number'],
        'mc_number'             =>          $customerRow['mc_number'],
        'gst_number'            =>          $customerRow['gst_number'],
        'pst_number'            =>          $customerRow['pst_number'],
        'billing_contact_name'  =>          $customerRow['billing_contact_name'],
        'billing_email'         =>          $customerRow['billing_email'],
        'billing_phone'         =>          $customerRow['billing_phone'],
        'invoice_email'         =>          $customerRow['invoice_email'],
        'invoice_delivery'      =>          $customerRow['invoice_delivery'],
        'po_required'           => (bool)   $customerRow['po_required'],
        'default_po_number'     =>          $customerRow['default_po_number'],
        'gst_exempt'            => (bool)   $customerRow['gst_exempt'],
        'pst_exempt'            => (bool)   $customerRow['pst_exempt'],
        'gst_exempt_number'     =>          $customerRow['gst_exempt_number'],
        'pst_exempt_number'     =>          $customerRow['pst_exempt_number'],
        'gst_exempt_expiry'     =>          $customerRow['gst_exempt_expiry'],
        'pst_exempt_expiry'     =>          $customerRow['pst_exempt_expiry'],
        'currency'              =>          $customerRow['currency'],
        'mileage_unit'          =>          $customerRow['mileage_unit'],
        'billing_cycle'         =>          $customerRow['billing_cycle'],
        'discount_type'         =>          $customerRow['discount_type'],
        'discount_value'        =>          $customerRow['discount_value'],
        'payment_terms'         =>          $customerRow['payment_terms'],
        'credit_limit'          =>          $customerRow['credit_limit'],
        'status'                =>          $customerRow['status'],
        'risk_score'            =>          $customerRow['risk_score'],
        'tags'                  =>          $customerRow['tags'],
        'updated_at'            =>          $customerRow['updated_at'],
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    return {
        submitting:  false,
        submitError: null,
        errors:      {},
        form:        prefill,
        allTags: [
            'vip','preferred','owner-operator','fleet','net-30','net-45','net-60',
            'cod','tax-exempt','high-risk','watchlist','credit-hold','delinquent',
            'new','seasonal','government','broker'
        ],

        init() {},

        toggleTag(tag) {
            const idx = this.form.tags.indexOf(tag);
            if (idx === -1) this.form.tags.push(tag);
            else            this.form.tags.splice(idx, 1);
        },

        async submit() {
            this.submitting  = true;
            this.submitError = null;
            this.errors      = {};

            // Payload includes id and updated_at for D19 optimistic locking
            const payload = { ...this.form };

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const res  = await fetch('<?= base_url('api/v1/customers/update') ?>', {
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
                    window.location.href = '<?= base_url('customers/show') ?>?id=' + this.form.id;
                    return;
                }

                if (res.status === 409) {
                    this.submitError = json.error?.message
                        ?? 'This record was modified by another user. Please reload and try again.';
                } else if (res.status === 422 && json.errors) {
                    this.errors      = json.errors;
                    this.submitError = 'Please correct the errors above.';
                } else {
                    this.submitError = json.error?.message ?? 'Failed to save changes.';
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

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
