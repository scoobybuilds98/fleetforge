<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Create Page
 *
 * @file        app/admin/reservations/create.php
 * @description Reservation create form. Two-column layout matching screenshot:
 *              Left column:  Status, Company Name, Quantity, Pickup Date
 *              Right column: Contact Name, Trailer Type, Unit#, Notes
 *
 *              Mode selector: "Existing Customer" vs "Manual Entry".
 *              - Existing Customer: customer dropdown; auto-loads leased units
 *                via units_by_customer.php; Trailer Type dropdown from templates.
 *              - Manual Entry: free-text Company/Contact/Unit fields.
 *
 *              Unit selector: supports multiple unit selection. Each selected
 *              unit appears in a preview list below the selector. Quantity
 *              auto-updates to match the count of selected units (user can
 *              override for manual entries).
 *
 *              Conflict detection: create.php checks server-side on submit;
 *              409 response shown inline as an error banner.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/reservations/create.php,
 *              api/v1/reservations/units_by_customer.php,
 *              api/v1/customers/index.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @design      FLEETFORGE_DESIGN_DETAILS.md — form layout patterns
 * @session     S018
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('reservations', 'create');

$pageTitle = 'New Reservation';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb + Page header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('reservations') ?>">Reservations</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">New Reservation</span>
</nav>
<div class="page-header">
    <h1 class="page-header-title h4">
        <span style="font-size:1.1em;margin-right:6px;">+</span> Reservation Form
    </h1>
</div>

<!-- ============================================================
     CREATE FORM — ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_ReservationCreate()" x-init="init()">

    <!-- Error banner -->
    <div class="alert alert-danger" x-show="formError" x-text="formError"
         style="margin-bottom:16px;" x-transition></div>

    <!-- Success banner -->
    <div class="alert alert-success" x-show="success"
         style="margin-bottom:16px;" x-transition>
        Reservation created! Redirecting…
    </div>

    <!-- ── Mode toggle ──────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-body" style="padding:16px 20px;">
            <div style="display:flex;gap:0;border:1px solid var(--border-color);border-radius:6px;overflow:hidden;width:fit-content;">
                <button type="button"
                        class="btn btn-sm"
                        :class="mode === 'customer' ? 'btn-primary' : 'btn-ghost'"
                        @click="switchMode('customer')"
                        style="border-radius:0;border:none;">
                    Existing Customer
                </button>
                <button type="button"
                        class="btn btn-sm"
                        :class="mode === 'manual' ? 'btn-primary' : 'btn-ghost'"
                        @click="switchMode('manual')"
                        style="border-radius:0;border:none;border-left:1px solid var(--border-color);">
                    Manual Entry
                </button>
            </div>
            <p class="text-secondary text-sm" style="margin:8px 0 0;"
               x-text="mode === 'customer'
                   ? 'Select a customer to auto-populate company info and see their leased units.'
                   : 'Enter contact and company info manually. Unit numbers are free-text.'">
            </p>
        </div>
    </div>

    <!-- ── Main form card ───────────────────────────────────────── -->
    <div class="card">
        <div class="card-body">

            <!-- ── Two-column form grid ──────────────────────────── -->
            <div class="form-grid-2">

                <!-- LEFT: Status -->
                <div class="form-group">
                    <label class="form-label" for="res-status">Status:</label>
                    <select id="res-status" class="form-select" x-model="form.status">
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                    </select>
                </div>

                <!-- RIGHT: Contact Name -->
                <div class="form-group">
                    <label class="form-label" for="res-contact">Contact Name:</label>
                    <input id="res-contact" type="text" class="form-input"
                           :class="errors.contact_name ? 'is-invalid' : ''"
                           placeholder="e.g. BOB"
                           x-model="form.contact_name"
                           :readonly="mode === 'customer' && form.customer_id">
                    <p class="form-error" x-show="errors.contact_name" x-text="errors.contact_name"></p>
                </div>

                <!-- LEFT: Customer selector (existing mode) -->
                <div class="form-group" x-show="mode === 'customer'">
                    <label class="form-label" for="res-customer">Customer: <span class="text-danger">*</span></label>
                    <select id="res-customer"
                            class="form-select"
                            :class="errors.customer_id ? 'is-invalid' : ''"
                            x-model="form.customer_id"
                            @change="onCustomerChange()">
                        <option value="">— Select customer —</option>
                        <template x-for="c in customers" :key="c.id">
                            <option :value="c.id" x-text="c.company_name + (c.contact_name ? ' (' + c.contact_name + ')' : '')"></option>
                        </template>
                    </select>
                    <p class="form-error" x-show="errors.customer_id" x-text="errors.customer_id"></p>
                </div>

                <!-- LEFT: Company Name (manual mode or auto-filled) -->
                <div class="form-group" x-show="mode === 'manual'">
                    <label class="form-label" for="res-company">Company Name: <span class="text-danger">*</span></label>
                    <input id="res-company" type="text" class="form-input"
                           :class="errors.company_name ? 'is-invalid' : ''"
                           placeholder="e.g. LOTUS TERMINALS"
                           x-model="form.company_name">
                    <p class="form-error" x-show="errors.company_name" x-text="errors.company_name"></p>
                </div>

                <!-- RIGHT: Trailer Type -->
                <div class="form-group">
                    <label class="form-label" for="res-trailer-type">Trailer Type:</label>
                    <select id="res-trailer-type" class="form-select" x-model="form.trailer_type_id">
                        <option value="">— Select type —</option>
                        <template x-for="t in templates" :key="t.id">
                            <option :value="t.id" x-text="t.name"></option>
                        </template>
                    </select>
                </div>

                <!-- LEFT: Quantity -->
                <div class="form-group">
                    <label class="form-label" for="res-qty">Quantity: <span class="text-danger">*</span></label>
                    <input id="res-qty" type="number" min="1" max="99"
                           class="form-input"
                           :class="errors.quantity ? 'is-invalid' : ''"
                           x-model.number="form.quantity">
                    <p class="form-error" x-show="errors.quantity" x-text="errors.quantity"></p>
                </div>

                <!-- RIGHT: Unit# selector -->
                <div class="form-group">
                    <label class="form-label" for="res-unit">Unit#:</label>

                    <!-- Existing customer: dropdown of available + leased units -->
                    <template x-if="mode === 'customer'">
                        <div>
                            <select id="res-unit"
                                    class="form-select"
                                    @change="addUnit($event.target.value); $event.target.value = ''">
                                <option value="">— Add a unit —</option>
                                <template x-if="customerUnits.length > 0">
                                    <optgroup label="Customer's Leased Units">
                                        <template x-for="u in customerUnits" :key="'cu-'+u.id">
                                            <option :value="JSON.stringify({id:u.id, unit_number:u.unit_number, template_name:u.template_name, entry_type:'system'})"
                                                    x-text="u.unit_number + (u.template_name ? ' — ' + u.template_name : '') + ' (' + u.lease_status + ')'">
                                            </option>
                                        </template>
                                    </optgroup>
                                </template>
                                <template x-if="availableUnits.length > 0">
                                    <optgroup label="Available Units">
                                        <template x-for="u in availableUnits" :key="'au-'+u.id">
                                            <option :value="JSON.stringify({id:u.id, unit_number:u.unit_number, template_name:u.template_name, entry_type:'system'})"
                                                    x-text="u.unit_number + (u.template_name ? ' — ' + u.template_name : '')">
                                            </option>
                                        </template>
                                    </optgroup>
                                </template>
                            </select>
                            <p class="text-xs text-secondary" style="margin-top:4px;"
                               x-show="loadingUnits">Loading units…</p>
                        </div>
                    </template>

                    <!-- Manual mode: free-text add -->
                    <template x-if="mode === 'manual'">
                        <div style="display:flex;gap:6px;">
                            <input type="text"
                                   class="form-input"
                                   placeholder="Unit number, then Add"
                                   x-model="manualUnitInput"
                                   @keydown.enter.prevent="addManualUnit()">
                            <button type="button" class="btn btn-secondary btn-sm"
                                    @click="addManualUnit()">Add</button>
                        </div>
                    </template>

                    <!-- Selected units preview list -->
                    <template x-if="selectedUnits.length > 0">
                        <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;">
                            <template x-for="(u, i) in selectedUnits" :key="i">
                                <span style="display:inline-flex;align-items:center;gap:4px;background:var(--bg-muted);border:1px solid var(--border-color);border-radius:4px;padding:2px 8px;font-size:0.8125rem;">
                                    <span class="font-mono" x-text="u.unit_number"></span>
                                    <span class="text-secondary" x-show="u.template_name" x-text="'(' + u.template_name + ')'"></span>
                                    <button type="button"
                                            @click="removeUnit(i)"
                                            style="margin-left:4px;color:var(--color-danger);background:none;border:none;cursor:pointer;line-height:1;font-size:1rem;"
                                            title="Remove unit">×</button>
                                </span>
                            </template>
                        </div>
                    </template>

                </div>

                <!-- LEFT: Pickup Date -->
                <div class="form-group">
                    <label class="form-label" for="res-pickup">Pickup Date: <span class="text-danger">*</span></label>
                    <input id="res-pickup" type="date"
                           class="form-input"
                           :class="errors.pickup_date ? 'is-invalid' : ''"
                           x-model="form.pickup_date">
                    <p class="form-error" x-show="errors.pickup_date" x-text="errors.pickup_date"></p>
                </div>

                <!-- RIGHT: Notes -->
                <div class="form-group">
                    <label class="form-label" for="res-notes">Notes:</label>
                    <textarea id="res-notes" class="form-input" rows="4"
                              placeholder="Any special instructions or details…"
                              x-model="form.notes"></textarea>
                </div>

            </div><!-- /form-grid-2 -->

            <!-- ── Expanded fields (collapsible) ─────────────────── -->
            <details style="margin-top:8px;">
                <summary class="text-secondary text-sm" style="cursor:pointer;padding:8px 0;user-select:none;">
                    Additional Fields (pickup time, yard, priority, phone, email, internal notes)
                </summary>
                <div class="form-grid-2" style="margin-top:12px;">

                    <div class="form-group">
                        <label class="form-label" for="res-pickup-time">Pickup Time:</label>
                        <input id="res-pickup-time" type="time" class="form-input"
                               x-model="form.pickup_time">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="res-priority">Priority:</label>
                        <select id="res-priority" class="form-select" x-model="form.priority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="res-phone">Contact Phone:</label>
                        <input id="res-phone" type="tel" class="form-input"
                               placeholder="e.g. 604-555-0100"
                               x-model="form.contact_phone">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="res-email">Contact Email:</label>
                        <input id="res-email" type="email" class="form-input"
                               placeholder="e.g. bob@lotusterminals.com"
                               x-model="form.contact_email">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="res-yard">Yard Location:</label>
                        <input id="res-yard" type="text" class="form-input"
                               placeholder="e.g. Delta Yard"
                               x-model="form.yard_location">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="res-purpose">Purpose:</label>
                        <input id="res-purpose" type="text" class="form-input"
                               placeholder="e.g. Container import run"
                               x-model="form.purpose">
                    </div>

                    <div class="form-group" style="grid-column:1/-1;">
                        <label class="form-label" for="res-internal">Internal Notes:</label>
                        <textarea id="res-internal" class="form-input" rows="3"
                                  placeholder="Staff-only notes not visible to customer…"
                                  x-model="form.internal_notes"></textarea>
                    </div>

                </div>
            </details>

        </div><!-- /card-body -->

        <!-- ── Submit button ────────────────────────────────────── -->
        <div class="card-footer" style="display:flex;justify-content:stretch;">
            <button type="button"
                    class="btn btn-primary"
                    style="width:100%;"
                    :disabled="submitting"
                    @click="submit()">
                <span x-show="!submitting">Submit Reservation</span>
                <span x-show="submitting">Saving…</span>
            </button>
        </div>

    </div><!-- /card -->

</div><!-- /x-data -->

<!-- ============================================================
     ALPINE COMPONENT
     ============================================================ -->
<script>
function FF_ReservationCreate() {
    return {
        // ── State ────────────────────────────────────────────────
        mode:           'customer',     // 'customer' | 'manual'
        customers:      [],
        templates:      [],
        customerUnits:  [],
        availableUnits: [],
        selectedUnits:  [],
        manualUnitInput: '',
        loadingUnits:   false,

        form: {
            status:         'confirmed',
            customer_id:    '',
            contact_name:   '',
            company_name:   '',
            trailer_type_id: '',
            quantity:       1,
            pickup_date:    '',
            pickup_time:    '',
            yard_location:  '',
            purpose:        '',
            priority:       'medium',
            notes:          '',
            internal_notes: '',
            contact_phone:  '',
            contact_email:  '',
        },

        errors:    {},
        formError: '',
        success:   false,
        submitting: false,

        // ── Init ─────────────────────────────────────────────────
        async init() {
            await this.loadCustomers();
            await this.loadTemplates();
        },

        // ── Load customers dropdown ───────────────────────────────
        async loadCustomers() {
            try {
                const res  = await fetch('<?= base_url('api/v1/customers/index.php') ?>?per_page=200&status=active&sort=company_name&dir=ASC');
                const data = await res.json();
                this.customers = data.data?.items || [];
            } catch {}
        },

        // ── Load templates (for Trailer Type dropdown) ────────────
        async loadTemplates() {
            try {
                const res  = await fetch('<?= base_url('api/v1/reservations/units_by_customer.php') ?>');
                const data = await res.json();
                this.templates      = data.data?.templates || [];
                this.availableUnits = data.data?.available_units || [];
            } catch {}
        },

        // ── Switch entry mode ─────────────────────────────────────
        switchMode(m) {
            this.mode          = m;
            this.selectedUnits = [];
            this.form.customer_id   = '';
            this.form.company_name  = '';
            this.form.contact_name  = '';
            this.customerUnits      = [];
        },

        // ── Customer changed → load their units ───────────────────
        async onCustomerChange() {
            const cId = this.form.customer_id;
            if (!cId) {
                this.form.company_name = '';
                this.form.contact_name = '';
                this.customerUnits = [];
                return;
            }

            // Auto-fill company + contact from customer record
            const customer = this.customers.find(c => c.id == cId);
            if (customer) {
                this.form.company_name = customer.company_name;
                this.form.contact_name = customer.contact_name || '';
            }

            this.loadingUnits = true;
            try {
                const res  = await fetch(`<?= base_url('api/v1/reservations/units_by_customer.php') ?>?customer_id=${cId}`);
                const data = await res.json();
                this.customerUnits  = data.data?.customer_units  || [];
                this.availableUnits = data.data?.available_units || [];
                this.templates      = data.data?.templates       || [];
            } catch {}
            this.loadingUnits = false;
        },

        // ── Add unit from dropdown ────────────────────────────────
        addUnit(jsonStr) {
            if (!jsonStr) return;
            try {
                const u = JSON.parse(jsonStr);
                // Prevent duplicates
                if (this.selectedUnits.some(s => s.id === u.id)) return;
                this.selectedUnits.push(u);
                this.syncQuantity();
            } catch {}
        },

        // ── Add unit from manual text input ───────────────────────
        addManualUnit() {
            const num = this.manualUnitInput.trim();
            if (!num) return;
            if (this.selectedUnits.some(s => s.unit_number === num)) return;
            this.selectedUnits.push({
                id:            null,
                unit_number:   num,
                template_name: '',
                entry_type:    'manual',
            });
            this.manualUnitInput = '';
            this.syncQuantity();
        },

        removeUnit(i) {
            this.selectedUnits.splice(i, 1);
            this.syncQuantity();
        },

        // Auto-set quantity to match selected unit count
        syncQuantity() {
            if (this.selectedUnits.length > 0) {
                this.form.quantity = this.selectedUnits.length;
            }
        },

        // ── Submit ────────────────────────────────────────────────
        async submit() {
            this.errors    = {};
            this.formError = '';
            this.submitting = true;

            // Build units array for API
            const units = this.selectedUnits.map(u => ({
                equipment_unit_id: u.id || null,
                unit_number:       u.unit_number,
                template_name:     u.template_name || null,
                entry_type:        u.entry_type || 'manual',
            }));

            // Company name for manual mode
            const companyName = this.mode === 'customer'
                ? this.form.company_name
                : this.form.company_name;

            const payload = {
                status:          this.form.status,
                customer_id:     this.mode === 'customer' ? (this.form.customer_id || null) : null,
                contact_name:    this.form.contact_name,
                company_name:    companyName,
                contact_phone:   this.form.contact_phone || null,
                contact_email:   this.form.contact_email || null,
                quantity:        this.form.quantity,
                pickup_date:     this.form.pickup_date,
                pickup_time:     this.form.pickup_time || null,
                yard_location:   this.form.yard_location || null,
                purpose:         this.form.purpose || null,
                priority:        this.form.priority,
                notes:           this.form.notes || null,
                internal_notes:  this.form.internal_notes || null,
                units:           units,
            };

            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/create.php') ?>', payload);

                if (!res.success) {
                    // Field-level errors
                    if (res.error?.errors) {
                        this.errors = res.error.errors;
                    }
                    throw new Error(res.error?.message || 'Failed to create reservation.');
                }

                this.success = true;
                setTimeout(() => {
                    window.location.href = '<?= base_url('reservations/show') ?>?id=' + res.data.id;
                }, 800);

            } catch (e) {
                this.formError = e.message;
            } finally {
                this.submitting = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
