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
                               placeholder="e.g. bob@lotusterminals.com"
                               x-model="form.contact_email">
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

    <!-- ================================================================
         SUCCESS OVERLAY — Full-screen truck animation (Option A)
         Triggered when reservation is successfully created.
         Truck drives in from left, checkmark pops, text fades, then
         auto-redirects to the new reservation's show page.
         ================================================================ -->
    <template x-if="showSuccessOverlay">
        <div class="ff-success-overlay"
             style="position:fixed;inset:0;z-index:9999;overflow:hidden;background:rgba(10,15,28,0.97);">

            <!-- ── Upper area: checkmark + text ─────────────────── -->
            <div style="position:absolute;top:0;left:0;right:0;bottom:110px;
                        display:flex;flex-direction:column;align-items:center;
                        justify-content:center;gap:24px;">

                <!-- Checkmark circle -->
                <div class="ff-check-circle">
                    <div style="width:108px;height:108px;border-radius:50%;background:#22c55e;
                                display:flex;align-items:center;justify-content:center;
                                box-shadow:0 0 0 14px rgba(34,197,94,0.12),
                                           0 0 0 28px rgba(34,197,94,0.06);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                             fill="none" stroke="white" stroke-width="2.8"
                             stroke-linecap="round" stroke-linejoin="round"
                             style="width:56px;height:56px;">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </div>
                </div>

                <!-- Success text -->
                <div class="ff-success-text" style="text-align:center;">
                    <h2 style="color:#f8fafc;font-size:2rem;font-weight:700;margin:0 0 10px;
                                letter-spacing:-0.5px;">
                        Reservation Created!
                    </h2>
                    <p style="color:#64748b;font-size:1rem;margin:0;">
                        Redirecting to reservation details…
                    </p>
                </div>

            </div>

            <!-- ── Road ─────────────────────────────────────────── -->
            <div style="position:absolute;bottom:0;left:0;right:0;height:110px;
                        background:linear-gradient(to bottom,#1e293b 0%,#0f172a 100%);">
                <!-- Top curb line -->
                <div style="position:absolute;top:0;left:0;right:0;height:3px;background:#334155;"></div>
                <!-- Lane dash markings (animated scroll synced to truck arrival) -->
                <div class="ff-road-dashes"
                     style="position:absolute;top:52px;left:0;right:0;height:5px;opacity:0.55;"></div>
                <!-- Bottom curb -->
                <div style="position:absolute;bottom:0;left:0;right:0;height:4px;background:#334155;"></div>
            </div>

            <!-- ── Truck (drives in from left) ──────────────────── -->
            <div class="ff-truck-wrap"
                 style="position:absolute;bottom:14px;left:50%;margin-left:-195px;">
                <svg width="390" height="94" viewBox="0 0 390 94"
                     xmlns="http://www.w3.org/2000/svg">

                    <!-- ── Trailer body ── -->
                    <rect x="0" y="8" width="248" height="58" rx="4"
                          fill="#2563eb" stroke="#1e40af" stroke-width="1.5"/>
                    <!-- Trailer roof stripe -->
                    <rect x="0" y="8" width="248" height="11" rx="4" fill="#1d4ed8"/>
                    <!-- Trailer vertical ribs -->
                    <line x1="41"  y1="19" x2="41"  y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                    <line x1="82"  y1="19" x2="82"  y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                    <line x1="123" y1="19" x2="123" y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                    <line x1="164" y1="19" x2="164" y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                    <line x1="205" y1="19" x2="205" y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                    <!-- FLEETFORGE branding on trailer -->
                    <text x="20" y="49" font-family="Arial,Helvetica,sans-serif"
                          font-size="12" font-weight="700" fill="white"
                          letter-spacing="3" opacity="0.95">FLEETFORGE</text>
                    <!-- Rear running lights -->
                    <rect x="1"  y="22" width="5" height="11" rx="1.5" fill="#ef4444"/>
                    <rect x="1"  y="45" width="5" height="11" rx="1.5" fill="#ef4444"/>
                    <!-- Rear reflector -->
                    <rect x="2"  y="58" width="12" height="5" rx="1" fill="#fbbf24" opacity="0.8"/>

                    <!-- Trailer axle wheels (dual rear) -->
                    <circle cx="44"  cy="77" r="15" fill="#0f172a" stroke="#374151" stroke-width="2.5"/>
                    <circle cx="44"  cy="77" r="7"  fill="#1e293b"/>
                    <circle cx="44"  cy="77" r="2.5" fill="#6b7280"/>
                    <circle cx="70"  cy="77" r="15" fill="#0f172a" stroke="#374151" stroke-width="2.5"/>
                    <circle cx="70"  cy="77" r="7"  fill="#1e293b"/>
                    <circle cx="70"  cy="77" r="2.5" fill="#6b7280"/>

                    <!-- Fifth-wheel hitch -->
                    <rect x="243" y="56" width="22" height="7" rx="3.5" fill="#475569"/>
                    <rect x="248" y="59" width="12" height="4"  rx="2"   fill="#64748b"/>

                    <!-- ── Cab body ── -->
                    <rect x="265" y="24" width="118" height="42" rx="5"
                          fill="#1d4ed8" stroke="#1e40af" stroke-width="1.5"/>
                    <!-- Cab sleeper roof -->
                    <path d="M268 24 Q272 4 290 4 L364 4 Q377 4 379 17 L379 24 Z"
                          fill="#1e40af"/>
                    <!-- Roof accent strip -->
                    <rect x="288" y="4" width="76" height="5" rx="2" fill="#3b82f6"/>
                    <!-- Windshield -->
                    <path d="M306 6 L372 6 L378 24 L306 24 Z"
                          fill="#bfdbfe" opacity="0.88"/>
                    <!-- Windshield glare -->
                    <path d="M316 8 L338 8 L335 18 L313 18 Z"
                          fill="white" opacity="0.22"/>
                    <!-- Side window -->
                    <rect x="267" y="28" width="36" height="20" rx="3"
                          fill="#bfdbfe" opacity="0.75"/>
                    <!-- Door handle -->
                    <rect x="276" y="41" width="14" height="3" rx="1.5" fill="#60a5fa"/>
                    <!-- Door seam -->
                    <line x1="305" y1="24" x2="305" y2="66"
                          stroke="#1e40af" stroke-width="1.5"/>
                    <!-- Cab skirt -->
                    <rect x="265" y="61" width="118" height="5" rx="2" fill="#1e3a8a"/>
                    <!-- Exhaust stack -->
                    <rect x="267" y="0" width="8" height="26" rx="4" fill="#374151"/>
                    <rect x="270" y="0" width="2" height="24" fill="#4b5563"/>
                    <!-- Fuel tank -->
                    <rect x="268" y="54" width="32" height="14" rx="3"
                          fill="#1e3a8a" stroke="#1e40af" stroke-width="1"/>
                    <!-- Grill -->
                    <rect x="375" y="28" width="5" height="36" rx="2" fill="#374151"/>
                    <line x1="375" y1="34" x2="380" y2="34" stroke="#4b5563"/>
                    <line x1="375" y1="40" x2="380" y2="40" stroke="#4b5563"/>
                    <line x1="375" y1="46" x2="380" y2="46" stroke="#4b5563"/>
                    <line x1="375" y1="52" x2="380" y2="52" stroke="#4b5563"/>
                    <!-- Headlights -->
                    <rect x="378" y="28" width="10" height="14" rx="2"
                          fill="#fef9c3" stroke="#fcd34d" stroke-width="1"/>
                    <rect x="379" y="46" width="9"  height="8"  rx="2"
                          fill="#fef08a" opacity="0.65"/>
                    <!-- Position light -->
                    <circle cx="378" cy="25" r="3.5" fill="#fbbf24"/>
                    <!-- Brake light top corner -->
                    <circle cx="378" cy="67" r="3.5" fill="#f87171"/>

                    <!-- Cab front axle wheels -->
                    <circle cx="308" cy="77" r="15" fill="#0f172a" stroke="#374151" stroke-width="2.5"/>
                    <circle cx="308" cy="77" r="7"  fill="#1e293b"/>
                    <circle cx="308" cy="77" r="2.5" fill="#6b7280"/>
                    <circle cx="362" cy="77" r="15" fill="#0f172a" stroke="#374151" stroke-width="2.5"/>
                    <circle cx="362" cy="77" r="7"  fill="#1e293b"/>
                    <circle cx="362" cy="77" r="2.5" fill="#6b7280"/>

                </svg>
            </div><!-- /truck -->

        </div>
    </template>

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

        // ── Submit (standard — no override) ───────────────────────
        async submit() {
            this.errors        = {};
            this.formError     = '';
            this.submitting    = true;

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
                    if (res.error?.errors) this.errors = res.error.errors;
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
                    if (res.error?.errors) this.errors = res.error.errors;
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
// WHY include: success_overlay.php is the shared truck animation used
// across all create pages. Centralised so truck SVG and keyframes live
// in one place and all modules stay in sync.
$overlayTitle    = 'Reservation Created!';
$overlaySubtitle = 'Redirecting to reservation details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
