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
$helpModuleSlug = 'reservations';
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
                    <label class="form-label">Customer: <span class="text-danger">*</span></label>
                    <?php
                    // SELECTOR-UNIFY: search-as-you-type customer picker.
                    // WHY: replaces static <select> of 200-cap customers so the
                    //      selector scales past the hard-coded per_page limit.
                    $pickerConfig    = [
                        'endpoint'    => '/api/v1/customers/index.php',
                        'searchParam' => 'search',
                        'resultKey'   => 'items',
                        'perPage'     => 10,
                        'extraParams' => 'status=active',
                        'placeholder' => 'Search customers by name, DOT, MC…',
                        'mapResult'   => "r => ({ id: r.id, label: r.company_name + (r.contact_name ? ' (' + r.contact_name + ')' : ''), sublabel: [r.city, r.province].filter(Boolean).join(', '), raw: r })",
                    ];
                    $pickerOnPicked  = 'form.customer_id = $event.detail.id; onCustomerPickerSelected($event.detail.raw)';
                    $pickerOnCleared = "form.customer_id = ''; onCustomerChange()";
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    ?>
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
                                <!-- [SELECTOR-1] Status badge appended via ffUnitLabel() —
                                     mirrors the server-side ff_unit_selector_label() helper
                                     so every selector in the app reads the same way. -->
                                <template x-if="customerUnits.length > 0">
                                    <optgroup label="Customer's Leased Units">
                                        <template x-for="u in customerUnits" :key="'cu-'+u.id">
                                            <option :value="JSON.stringify({id:u.id, unit_number:u.unit_number, template_name:u.template_name, entry_type:'system'})"
                                                    x-text="ffUnitLabel(u) + ' (lease: ' + u.lease_status + ')'">
                                            </option>
                                        </template>
                                    </optgroup>
                                </template>
                                <template x-if="availableUnits.length > 0">
                                    <optgroup label="Available Units">
                                        <template x-for="u in availableUnits" :key="'au-'+u.id">
                                            <option :value="JSON.stringify({id:u.id, unit_number:u.unit_number, template_name:u.template_name, entry_type:'system'})"
                                                    x-text="ffUnitLabel(u)">
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

                <!-- LEFT: Pickup Date + Time (both in main form, time optional) -->
                <div class="form-group">
                    <label class="form-label" for="res-pickup">Pickup Date: <span class="text-danger">*</span></label>
                    <!-- WHY flex wrapper + showPicker button: type="date" native calendar
                         doesn't always trigger on label click in all browsers; the calendar
                         icon button provides an explicit, accessible trigger. -->
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input id="res-pickup" type="date"
                               class="form-input"
                               style="flex:1;"
                               :class="errors.pickup_date ? 'is-invalid' : ''"
                               x-model="form.pickup_date"
                               min="<?= date('Y-m-d') ?>"
                               x-ref="pickupDate">
                        <button type="button"
                                class="btn btn-ghost btn-sm"
                                style="padding:0 10px;height:38px;flex-shrink:0;"
                                title="Open calendar"
                                @click="$refs.pickupDate.showPicker ? $refs.pickupDate.showPicker() : $refs.pickupDate.click()">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/>
                            </svg>
                        </button>
                    </div>
                    <p class="form-error" x-show="errors.pickup_date" x-text="errors.pickup_date"></p>
                    <!-- Pickup time directly under date — optional -->
                    <div style="margin-top:8px;">
                        <label class="form-label text-sm" for="res-pickup-time"
                               style="margin-bottom:4px;font-weight:500;">
                            Pickup Time:
                            <span class="text-secondary" style="font-weight:400;">(optional)</span>
                        </label>
                        <input id="res-pickup-time" type="time" class="form-input"
                               x-model="form.pickup_time"
                               style="max-width:160px;">
                    </div>
                </div>

                <!-- RIGHT: Pickup Yard (promoted to main form — visible immediately) -->
                <div class="form-group">
                    <label class="form-label" for="res-yard">Pickup Yard:</label>
                    <!-- WHY select: yard_location is driven by the yards table.
                         Value stored is yard.name (string) to match historical text snapshots. -->
                    <select id="res-yard" class="form-select" x-model="form.yard_location">
                        <option value="">— Select yard —</option>
                        <template x-for="y in yards" :key="y.id">
                            <option :value="y.name"
                                    x-text="y.name + (y.city ? ' (' + y.city + ')' : '')">
                            </option>
                        </template>
                    </select>
                    <template x-if="yards.length === 0">
                        <p class="text-xs text-secondary" style="margin:4px 0 0;">
                            No active yards configured.
                            <a href="<?= base_url('yards') ?>" class="link" target="_blank">Manage Yards →</a>
                        </p>
                    </template>
                </div>

                <!-- Notes — full width below the two-col grid -->
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label" for="res-notes">Notes:</label>
                    <textarea id="res-notes" class="form-input" rows="3"
                              placeholder="Any special instructions or details…"
                              x-model="form.notes"></textarea>
                </div>

            </div><!-- /form-grid-2 -->

            <!-- ── Expanded fields (collapsible) ─────────────────── -->
            <details style="margin-top:8px;">
                <summary class="text-secondary text-sm" style="cursor:pointer;padding:8px 0;user-select:none;">
                    Additional Fields (priority, phone, email, internal notes)
                </summary>
                <div class="form-grid-2" style="margin-top:12px;">

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
                               :class="errors.contact_email ? 'is-invalid' : ''"
                               placeholder="e.g. bob@lotusterminals.com"
                               x-model="form.contact_email">
                        <p class="form-error" x-show="errors.contact_email" x-text="errors.contact_email"></p>
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

    <!-- ================================================================
         CONFLICT OVERRIDE MODAL
         Shows when the API returns 409 CONFLICT on submit.
         Dispatcher can choose to override and double-book.
         ================================================================ -->
    <template x-if="conflictModal.open">
        <div style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);padding:16px;"
             @keydown.escape.window="conflictModal.open = false">
            <div class="card" style="width:480px;max-width:100%;">
                <div class="card-header" style="background:var(--badge-danger-bg);border-radius:8px 8px 0 0;">
                    <h3 class="h5" style="margin:0;color:var(--badge-danger-text);display:flex;align-items:center;gap:8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                             stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;flex-shrink:0;">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                        </svg>
                        Booking Conflict Detected
                    </h3>
                </div>
                <div class="card-body">
                    <p style="margin-bottom:12px;" x-text="conflictModal.message"></p>
                    <div style="padding:12px;background:var(--bg-muted);border-radius:6px;border-left:3px solid var(--color-warning);font-size:0.875rem;">
                        <strong>Proceeding will double-book this unit.</strong>
                        The override will be flagged in internal notes for operational review.
                    </div>
                </div>
                <div class="card-footer" style="display:flex;justify-content:flex-end;gap:8px;">
                    <button class="btn btn-ghost btn-sm"
                            @click="conflictModal.open = false">
                        Cancel — Go Back
                    </button>
                    <button class="btn btn-danger btn-sm"
                            :disabled="submitting"
                            @click="submitWithOverride()">
                        <span x-show="!submitting">Override &amp; Double-Book</span>
                        <span x-show="submitting">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <?php
    // S-CREATE-FEEDBACK: include INSIDE the x-data scope. Previously
    // included AFTER </div> (outside scope) — Alpine couldn't bind
    // the host component's `submitting` / `showSuccessOverlay` from
    // outside the scope, so the partial was dead code. The reservations
    // page appeared to work because of an inline duplicate truck overlay
    // (now deleted) at lines 431-583 — that duplicate pre-dated S018-EXT.
    //
    // Now there's ONE overlay source: the partial. It renders the
    // saving spinner during the API wait and the truck on success.
    $overlayTitle    = 'Reservation Created!';
    $overlaySubtitle = 'Redirecting to reservation details…';
    require_once FF_ROOT . '/includes/success_overlay.php';
    ?>

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
        yards:          [],             // active yards for Pickup Yard dropdown
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
        showSuccessOverlay: false,  // full-screen truck animation on create

        // Conflict override modal — shown when API returns 409 CONFLICT
        conflictModal: {
            open:    false,
            message: '',
        },

        // ── Init ─────────────────────────────────────────────────
        async init() {
            await this.loadCustomers();
            await this.loadTemplates();
        },

        // ── [SELECTOR-1] Build the option label for a unit row ─
        // Mirrors the server-side ff_unit_selector_label() helper so
        // every selector in the app reads the same way:
        //   "12TR1301 — 53ft Dry Van  ·  AVAILABLE"
        // Falls back to year/brand/model if template_name is missing,
        // and uppercases a humanised status as a pseudo-badge suffix.
        ffUnitLabel(u) {
            if (!u) return '';
            const labels = {
                available:      'Available',
                reserved:       'Reserved',
                on_lease:       'On Lease',
                maintenance:    'Maintenance',
                inactive:       'Inactive',
                decommissioned: 'Decommissioned',
            };
            const parts = [u.unit_number || ''];
            let desc = '';
            if (u.template_name) {
                desc = u.template_name;
            } else {
                const ybm = [u.year, u.brand, u.model].filter(Boolean).join(' ').trim();
                if (ybm) desc = ybm;
            }
            if (desc) parts.push('— ' + desc);
            if (u.status) {
                const lbl = labels[u.status] || u.status.replace('_', ' ');
                parts.push(' · ' + lbl.toUpperCase());
            }
            return parts.join(' ');
        },

        // ── Load customers dropdown ───────────────────────────────
        async loadCustomers() {
            try {
                const res  = await fetch('<?= base_url('api/v1/customers/index.php') ?>?per_page=200&status=active&sort=company_name&dir=ASC');
                const data = await res.json();
                this.customers = data.data?.items || [];
            } catch {}
        },

        // ── Load templates + yards for dropdowns ──────────────────
        // WHY single call: units_by_customer.php returns templates, available
        // units, AND yards in one payload to avoid extra round-trips.
        async loadTemplates() {
            try {
                const res  = await fetch('<?= base_url('api/v1/reservations/units_by_customer.php') ?>');
                const data = await res.json();
                this.templates      = data.data?.templates      || [];
                this.availableUnits = data.data?.available_units || [];
                this.yards          = data.data?.yards          || [];
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
                this.yards          = data.data?.yards           || [];
            } catch {}
            this.loadingUnits = false;
        },

        // ── Customer picker selected → autofill + load units ──────
        // WHY: FF_RecordPicker dispatches a record-picked event carrying the
        //      full raw customer row. We use it directly to populate company
        //      + contact fields instead of relying on a pre-loaded lookup array.
        onCustomerPickerSelected(rawCustomer) {
            if (rawCustomer) {
                this.form.company_name = rawCustomer.company_name || '';
                this.form.contact_name = rawCustomer.contact_name || '';
                // Seed the pre-loaded cache so onCustomerChange() can find it too
                if (!this.customers.some(c => c.id === rawCustomer.id)) {
                    this.customers.push(rawCustomer);
                }
            }
            this.onCustomerChange();
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

        // ── Build API payload ─────────────────────────────────────
        buildPayload(forceOverride = false) {
            const units = this.selectedUnits.map(u => ({
                equipment_unit_id: u.id || null,
                unit_number:       u.unit_number,
                template_name:     u.template_name || null,
                entry_type:        u.entry_type || 'manual',
            }));
            return {
                status:          this.form.status,
                customer_id:     this.mode === 'customer' ? (this.form.customer_id || null) : null,
                contact_name:    this.form.contact_name,
                company_name:    this.form.company_name,
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
                force_override:  forceOverride,
            };
        },

        // ── Client-side validation ────────────────────────────────
        // Populates this.errors and returns true if valid.
        validate() {
            this.errors    = {};
            this.formError = '';
            let ok = true;

            // Contact name required
            if (!this.form.contact_name || !this.form.contact_name.trim()) {
                this.errors.contact_name = 'Contact name is required.';
                ok = false;
            }

            // Company name required
            if (!this.form.company_name || !this.form.company_name.trim()) {
                this.errors.company_name = 'Company name is required.';
                ok = false;
            }

            // Customer required when in customer mode
            if (this.mode === 'customer' && !this.form.customer_id) {
                this.errors.customer_id = 'Please select a customer.';
                ok = false;
            }

            // Pickup date required + not in the past
            if (!this.form.pickup_date) {
                this.errors.pickup_date = 'Pickup date is required.';
                ok = false;
            } else {
                const today = new Date().toISOString().split('T')[0];
                if (this.form.pickup_date < today) {
                    this.errors.pickup_date = 'Pickup date cannot be in the past.';
                    ok = false;
                }
            }

            // Quantity: 1–500
            const q = parseInt(this.form.quantity);
            if (isNaN(q) || q < 1) {
                this.errors.quantity = 'Quantity must be at least 1.';
                ok = false;
            } else if (q > 500) {
                this.errors.quantity = 'Quantity cannot exceed 500.';
                ok = false;
            }

            // Email format check (optional field)
            if (this.form.contact_email && this.form.contact_email.trim()) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(this.form.contact_email.trim())) {
                    this.errors.contact_email = 'Please enter a valid email address.';
                    ok = false;
                }
            }

            if (!ok) {
                this.formError = 'Please fix the errors below and try again.';
            }
            return ok;
        },

        // ── Submit (standard — no override) ───────────────────────
        async submit() {
            if (!this.validate()) return;

            this.submitting = true;

            try {
                const res = await FF_Api.post(
                    '<?= base_url('api/v1/reservations/create.php') ?>',
                    this.buildPayload(false)
                );

                if (!res.success) {
                    // WHY: 409 CONFLICT opens the override modal instead of
                    // showing a hard error — dispatcher can choose to override.
                    if (res.error?.code === 'CONFLICT') {
                        this.conflictModal = {
                            open:    true,
                            message: res.error.message,
                        };
                        return; // Don't set formError — modal handles it
                    }
                    // VALID-2: prefer .fields, fall back to legacy .errors
                    if (res.error?.fields) this.errors = res.error.fields;
                    else if (res.error?.errors) this.errors = res.error.errors;
                    throw new Error(res.error?.message || 'Failed to create reservation.');
                }

                this.showSuccessOverlay = true;
                const newId = res.data.id;
                setTimeout(() => {
                    window.location.href = '<?= base_url('reservations/show') ?>?id=' + newId;
                }, 3500);

            } catch (e) {
                this.formError = e.message;
            } finally {
                this.submitting = false;
            }
        },

        // ── Submit with force_override = true ─────────────────────
        // Called when dispatcher confirms the override modal.
        async submitWithOverride() {
            this.conflictModal.open = false;
            this.errors             = {};
            this.formError          = '';
            this.submitting         = true;

            try {
                const res = await FF_Api.post(
                    '<?= base_url('api/v1/reservations/create.php') ?>',
                    this.buildPayload(true)
                );

                if (!res.success) {
                    // VALID-2: prefer .fields, fall back to legacy .errors
                    if (res.error?.fields) this.errors = res.error.fields;
                    else if (res.error?.errors) this.errors = res.error.errors;
                    throw new Error(res.error?.message || 'Failed to create reservation.');
                }

                this.showSuccessOverlay = true;
                const newId = res.data.id;
                setTimeout(() => {
                    window.location.href = '<?= base_url('reservations/show') ?>?id=' + newId;
                }, 3500);

            } catch (e) {
                this.formError = e.message;
            } finally {
                this.submitting = false;
            }
        },
    };
}
</script>

<?php
// S-CREATE-FEEDBACK: overlay include moved INSIDE the x-data div
// above (so Alpine bindings resolve). The inline duplicate truck
// overlay that lived in this file was deleted as part of the same
// fix — one source of truth (the partial) now handles both the
// saving spinner and the success truck.
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
