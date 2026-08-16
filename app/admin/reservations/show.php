<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Show / Edit Page
 *
 * @file        app/admin/reservations/show.php
 * @description Full reservation detail view with inline edit mode.
 *
 *              Layout:
 *                - Breadcrumb + page header with status badge + action buttons
 *                - Left column (2/3): Detail card (view/edit toggle), Units sub-table
 *                - Right column (1/3): Actions panel, Audit log
 *
 *              Actions panel (context-sensitive, permission-gated):
 *                - Pending:   [Confirm] [Cancel]
 *                - Confirmed: [Mark Out / Chassis Out] [Cancel]
 *                - Completed: [Reverse] (manager only)
 *                - Cancelled: [Delete] (delete permission only)
 *
 *              Edit mode: inline toggle. D19 optimistic lock (hidden updated_at field).
 *              Conflict detection on update: handled server-side if pickup_date changes.
 *
 *              Units tab: lists reservation_units rows. Shows current equipment status.
 *              For confirmed reservations: each unit row shows a "Link to Lease" button.
 *
 *              Audit log tab: last 20 actions from audit_log for this reservation.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/reservations/show.php,
 *              api/v1/reservations/update.php, api/v1/reservations/update_status.php,
 *              api/v1/reservations/mark_out.php, api/v1/reservations/delete.php,
 *              api/v1/reservations/units_by_customer.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @decisions   D19 (optimistic lock), D5 (soft-delete)
 * @session     S018
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('reservations', 'view');

// Server-side: validate id param and fetch reservation for initial render
$resId = (int) ($_GET['id'] ?? 0);
if (!$resId) {
    header('Location: ' . base_url('reservations'));
    exit;
}

// Flash message from create redirect
$flashMsg = clean_string($_GET['flash'] ?? null, 200);

$pageTitle      = 'Reservation #' . $resId;
$helpModuleSlug = 'reservations';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('reservations') ?>">Reservations</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Reservation #<?= e($resId) ?></span>
</nav>

<!-- ============================================================
     RESERVATION SHOW — ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_ReservationShow(<?= $resId ?>)">

    <!-- Flash message -->
    <?php if ($flashMsg): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">
        <?= e($flashMsg) ?>
    </div>
    <?php endif; ?>

    <!-- Global error / success banners -->
    <div class="alert alert-danger"  x-show="actionError"   x-text="actionError"   style="margin-bottom:12px;" x-transition></div>
    <div class="alert alert-success" x-show="actionSuccess" x-text="actionSuccess" style="margin-bottom:12px;" x-transition></div>

    <!-- ── Page header (status badge + action row) ─────────────── -->
    <div class="page-header" style="align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 class="page-header-title h4" style="display:flex;align-items:center;gap:10px;">
                Reservation
                <span class="font-mono" x-show="!loading" x-text="'#' + res.id"></span>

                <!-- Status badge -->
                <span class="badge badge-no-dot"
                      x-show="!loading"
                      :class="statusBadge(res.status)"
                      x-text="res.status ? res.status.charAt(0).toUpperCase() + res.status.slice(1) : ''">
                </span>

                <!-- Priority badge (if high/urgent) -->
                <span class="badge badge-no-dot badge-danger"
                      x-show="!loading && (res.priority === 'urgent' || res.priority === 'high')"
                      x-text="res.priority ? res.priority.toUpperCase() : ''">
                </span>
            </h1>

            <p class="text-secondary text-sm" x-show="!loading">
                <span x-text="res.company_name"></span>
                <span class="text-muted"> · Pickup: </span>
                <span class="font-medium" x-text="formatDate(res.pickup_date)"></span>
                <span x-show="res.pickup_time" x-text="' at ' + (res.pickup_time ? res.pickup_time.substring(0,5) : '')"></span>
            </p>
        </div>

        <!-- Quick action buttons (header level) -->
        <div class="page-header-actions" x-show="!loading" style="flex-wrap:wrap;gap:6px;">
            <?= help_button('reservations') ?>
            <?php if (function_exists('can') && can('ai', 'view') && (bool)settings_get('ai.enabled', false) && (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''))): ?>
            <button type="button" class="btn btn-secondary btn-sm no-print"
                    onclick="aiPanel_reservation_<?= (int)$resId ?>_reservation_summary_open()"
                    title="Open AI Reservation Summary">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;margin-right:4px;vertical-align:-2px;" aria-hidden="true">
                    <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="currentColor"/>
                </svg>
                AI Analysis
            </button>
            <?php endif; ?>
            <a href="<?= base_url('reservations') ?>" class="btn btn-ghost btn-sm">← Back</a>
            <a href="<?= base_url('reservations/create') ?>" class="btn btn-ghost btn-sm">+ New</a>
            <?php if (can('reservations', 'edit')): ?>
            <button class="btn btn-secondary btn-sm"
                    x-show="!editing && res.status !== 'completed' && res.status !== 'cancelled'"
                    @click="startEdit()">Edit</button>
            <button class="btn btn-ghost btn-sm"
                    x-show="editing"
                    @click="cancelEdit()">Discard Changes</button>
            <button class="btn btn-primary btn-sm"
                    x-show="editing"
                    :disabled="saving"
                    @click="saveEdit()">
                <span x-show="!saving">Save Changes</span>
                <span x-show="saving">Saving…</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================================================================
         STATUS TIMELINE STEPPER
         Shows the reservation lifecycle: Pending → Confirmed → Completed.
         Cancelled reservations show a dedicated banner instead.
         WHY x-show (not x-if): avoids flicker on fast loads; the stepper
         is always in the DOM but hidden until the reservation loads.
         ================================================================ -->
    <div x-show="!loading && !notFound" style="margin-bottom:16px;">

        <!-- Cancelled banner (shown instead of stepper) -->
        <template x-if="res.status === 'cancelled'">
            <div style="background:var(--badge-danger-bg);border:1px solid rgba(239,68,68,0.22);
                        border-radius:8px;padding:14px 20px;
                        display:flex;align-items:center;gap:14px;">
                <div style="width:40px;height:40px;border-radius:50%;background:#ef4444;flex-shrink:0;
                            display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="white" stroke-width="2.5" stroke-linecap="round"
                         style="width:20px;height:20px;">
                        <path d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </div>
                <div>
                    <div style="font-weight:700;color:var(--badge-danger-text);font-size:0.9375rem;
                                letter-spacing:.03em;">
                        CANCELLED
                    </div>
                    <div class="text-secondary text-sm"
                         x-show="res.cancel_reason"
                         x-text="res.cancel_reason"></div>
                </div>
            </div>
        </template>

        <!-- Lifecycle stepper (pending / confirmed / completed) -->
        <template x-if="res.status !== 'cancelled'">
            <div class="card">
                <div class="card-body" style="padding:18px 28px;">
                    <div class="ff-stepper-track">

                        <!-- Step 1 — Pending -->
                        <div class="ff-step"
                             :class="res.status === 'pending' ? 'ff-step-active' : 'ff-step-done'">
                            <div class="ff-step-circle">
                                <template x-if="res.status !== 'pending'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                                         style="width:14px;height:14px;">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                </template>
                                <template x-if="res.status === 'pending'">
                                    <span>1</span>
                                </template>
                            </div>
                            <div class="ff-step-label">Pending</div>
                            <div class="ff-step-sublabel" x-show="res.status === 'pending'">
                                Current
                            </div>
                        </div>

                        <!-- Connector 1→2 -->
                        <div class="ff-connector"
                             :class="['confirmed','completed'].includes(res.status) ? 'ff-connector-done' : ''">
                        </div>

                        <!-- Step 2 — Confirmed -->
                        <div class="ff-step"
                             :class="res.status === 'confirmed' ? 'ff-step-active'
                                   : res.status === 'completed' ? 'ff-step-done'
                                   : 'ff-step-future'">
                            <div class="ff-step-circle">
                                <template x-if="res.status === 'completed'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                                         style="width:14px;height:14px;">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                </template>
                                <template x-if="res.status !== 'completed'">
                                    <span>2</span>
                                </template>
                            </div>
                            <div class="ff-step-label">Confirmed</div>
                            <div class="ff-step-sublabel" x-show="res.status === 'confirmed'">
                                Current
                            </div>
                        </div>

                        <!-- Connector 2→3 -->
                        <div class="ff-connector"
                             :class="res.status === 'completed' ? 'ff-connector-done' : ''">
                        </div>

                        <!-- Step 3 — Completed -->
                        <div class="ff-step"
                             :class="res.status === 'completed' ? 'ff-step-done' : 'ff-step-future'">
                            <div class="ff-step-circle">
                                <template x-if="res.status === 'completed'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                                         style="width:14px;height:14px;">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                </template>
                                <template x-if="res.status !== 'completed'">
                                    <span>3</span>
                                </template>
                            </div>
                            <div class="ff-step-label">Completed</div>
                            <div class="ff-step-sublabel" x-show="res.status === 'completed'"
                                 x-text="res.marked_out_at ? formatDate(res.marked_out_at) : 'Chassis Out'">
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </template>

    </div><!-- /stepper -->

    <!-- ── Loading skeleton ──────────────────────────────────────── -->
    <template x-if="loading">
        <div class="card">
            <div class="card-body" style="padding:32px;">
                <div class="skeleton skeleton-text" style="width:60%;height:28px;margin-bottom:16px;"></div>
                <div class="skeleton skeleton-text" style="width:40%;height:18px;margin-bottom:8px;"></div>
                <div class="skeleton skeleton-text" style="width:80%;height:18px;margin-bottom:8px;"></div>
                <div class="skeleton skeleton-text" style="width:50%;height:18px;"></div>
            </div>
        </div>
    </template>

    <!-- ── Not found ─────────────────────────────────────────────── -->
    <template x-if="!loading && notFound">
        <div class="card">
            <div class="empty-state" style="padding:48px;">
                <p class="empty-state-title">Reservation not found</p>
                <p class="empty-state-text">Reservation #<?= e($resId) ?> does not exist or has been deleted.</p>
                <a href="<?= base_url('reservations') ?>" class="btn btn-secondary btn-sm">← Back to Reservations</a>
            </div>
        </div>
    </template>

    <!-- ── Main content ──────────────────────────────────────────── -->
    <template x-if="!loading && !notFound">
        <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">

            <!-- ── LEFT COLUMN ──────────────────────────────────── -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Detail card -->
                <div class="card">
                    <div class="card-header" style="display:flex;align-items:center;gap:8px;">
                        <h2 class="card-title h5" style="margin:0;">Reservation Details</h2>
                        <span class="badge badge-no-dot badge-neutral text-xs" x-show="editing"
                              style="margin-left:auto;">Editing</span>
                    </div>
                    <div class="card-body">

                        <div class="form-grid-2">

                            <!-- Status (read-only in detail — changed via Actions panel) -->
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <div x-show="!editing">
                                    <span class="badge badge-no-dot"
                                          :class="statusBadge(res.status)"
                                          x-text="res.status ? res.status.charAt(0).toUpperCase() + res.status.slice(1) : '—'">
                                    </span>
                                </div>
                                <div x-show="editing" class="text-secondary text-sm">
                                    Use the Actions panel to change status.
                                </div>
                            </div>

                            <!-- Contact Name -->
                            <div class="form-group">
                                <label class="form-label" :for="editing ? 'edit-contact' : ''">Contact Name</label>
                                <div x-show="!editing" class="field-value" x-text="res.contact_name || '—'"></div>
                                <input x-show="editing" id="edit-contact" type="text"
                                       class="form-input"
                                       :class="editErrors.contact_name ? 'is-invalid' : ''"
                                       x-model="editForm.contact_name">
                                <p class="form-error" x-show="editErrors.contact_name" x-text="editErrors.contact_name"></p>
                            </div>

                            <!-- Company Name -->
                            <div class="form-group">
                                <label class="form-label" :for="editing ? 'edit-company' : ''">Company Name</label>
                                <div x-show="!editing" class="field-value" x-text="res.company_name || '—'"></div>
                                <input x-show="editing" id="edit-company" type="text"
                                       class="form-input"
                                       :class="editErrors.company_name ? 'is-invalid' : ''"
                                       x-model="editForm.company_name">
                                <p class="form-error" x-show="editErrors.company_name" x-text="editErrors.company_name"></p>
                            </div>

                            <!-- Contact Phone -->
                            <div class="form-group">
                                <label class="form-label" :for="editing ? 'edit-phone' : ''">Contact Phone</label>
                                <div x-show="!editing" class="field-value" x-text="res.contact_phone || '—'"></div>
                                <input x-show="editing" id="edit-phone" type="tel"
                                       class="form-input" x-model="editForm.contact_phone">
                            </div>

                            <!-- Contact Email -->
                            <div class="form-group">
                                <label class="form-label" :for="editing ? 'edit-email' : ''">Contact Email</label>
                                <div x-show="!editing" class="field-value">
                                    <template x-if="res.contact_email">
                                        <a :href="'mailto:' + res.contact_email"
                                           class="link text-sm"
                                           x-text="res.contact_email"></a>
                                    </template>
                                    <span x-show="!res.contact_email" class="text-secondary">—</span>
                                </div>
                                <input x-show="editing" id="edit-email" type="email"
                                       class="form-input"
                                       :class="editErrors.contact_email ? 'is-invalid' : ''"
                                       x-model="editForm.contact_email">
                                <p class="form-error" x-show="editErrors.contact_email" x-text="editErrors.contact_email"></p>
                            </div>

                            <!-- Pickup Date -->
                            <div class="form-group">
                                <label class="form-label" :for="editing ? 'edit-pickup' : ''">Pickup Date</label>
                                <div x-show="!editing" class="field-value font-medium"
                                     x-text="formatDate(res.pickup_date)"></div>
                                <input x-show="editing" id="edit-pickup" type="date"
                                       class="form-input"
                                       :class="editErrors.pickup_date ? 'is-invalid' : ''"
                                       x-model="editForm.pickup_date">
                                <p class="form-error" x-show="editErrors.pickup_date" x-text="editErrors.pickup_date"></p>
                            </div>

                            <!-- Pickup Time -->
                            <div class="form-group">
                                <label class="form-label" :for="editing ? 'edit-time' : ''">Pickup Time</label>
                                <div x-show="!editing" class="field-value"
                                     x-text="res.pickup_time ? res.pickup_time.substring(0,5) : '—'"></div>
                                <input x-show="editing" id="edit-time" type="time"
                                       class="form-input" x-model="editForm.pickup_time">
                            </div>

                            <!-- Quantity -->
                            <div class="form-group">
                                <label class="form-label" :for="editing ? 'edit-qty' : ''">Quantity</label>
                                <div x-show="!editing" class="field-value font-mono" x-text="res.quantity"></div>
                                <input x-show="editing" id="edit-qty" type="number" min="1"
                                       class="form-input"
                                       :class="editErrors.quantity ? 'is-invalid' : ''"
                                       x-model.number="editForm.quantity">
                                <p class="form-error" x-show="editErrors.quantity" x-text="editErrors.quantity"></p>
                            </div>

                            <!-- Priority -->
                            <div class="form-group">
                                <label class="form-label" :for="editing ? 'edit-priority' : ''">Priority</label>
                                <div x-show="!editing">
                                    <span class="badge badge-no-dot"
                                          :class="priorityBadge(res.priority)"
                                          x-text="res.priority ? res.priority.charAt(0).toUpperCase() + res.priority.slice(1) : '—'">
                                    </span>
                                </div>
                                <select x-show="editing" id="edit-priority" class="form-select"
                                        x-model="editForm.priority">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>

                            <!-- Yard Location -->
                            <div class="form-group">
                                <label class="form-label" :for="editing ? 'edit-yard' : ''">Pickup Yard</label>
                                <div x-show="!editing" class="field-value" x-text="res.yard_location || '—'"></div>
                                <!-- WHY select: yard_location is now driven by the yards table.
                                     Value stored is yard.name to match historical snapshots. -->
                                <select x-show="editing" id="edit-yard"
                                        class="form-select" x-model="editForm.yard_location">
                                    <option value="">— Select yard —</option>
                                    <template x-for="y in yards" :key="y.id">
                                        <option :value="y.name"
                                                x-text="y.name + (y.city ? ' (' + y.city + ')' : '')">
                                        </option>
                                    </template>
                                    <!-- WHY: if the saved yard_location doesn't match any active yard
                                         (e.g. old free-text entry or deactivated yard), show it as
                                         a fallback option so the field isn't silently cleared. -->
                                    <template x-if="editForm.yard_location && !yards.some(y => y.name === editForm.yard_location)">
                                        <option :value="editForm.yard_location"
                                                x-text="editForm.yard_location + ' (inactive/legacy)'">
                                        </option>
                                    </template>
                                </select>
                            </div>

                            <!-- Purpose -->
                            <div class="form-group" style="grid-column:1/-1;">
                                <label class="form-label" :for="editing ? 'edit-purpose' : ''">Purpose</label>
                                <div x-show="!editing" class="field-value" x-text="res.purpose || '—'"></div>
                                <input x-show="editing" id="edit-purpose" type="text"
                                       class="form-input" x-model="editForm.purpose">
                            </div>

                            <!-- Notes -->
                            <div class="form-group" style="grid-column:1/-1;">
                                <label class="form-label" :for="editing ? 'edit-notes' : ''">Notes</label>
                                <div x-show="!editing" class="field-value"
                                     style="white-space:pre-wrap;"
                                     x-text="res.notes || '—'"></div>
                                <textarea x-show="editing" id="edit-notes" class="form-input" rows="3"
                                          x-model="editForm.notes"></textarea>
                            </div>

                            <!-- Internal Notes (staff only) -->
                            <div class="form-group" style="grid-column:1/-1;">
                                <label class="form-label">Internal Notes <span class="text-muted text-xs">(staff only)</span></label>
                                <div x-show="!editing" class="field-value"
                                     style="white-space:pre-wrap;font-size:0.875rem;color:var(--text-secondary);"
                                     x-text="res.internal_notes || '—'"></div>
                                <textarea x-show="editing" class="form-input" rows="3"
                                          x-model="editForm.internal_notes"></textarea>
                            </div>

                        </div><!-- /form-grid-2 -->

                        <!-- Meta row -->
                        <div style="display:flex;gap:24px;padding-top:12px;border-top:1px solid var(--border-color);margin-top:12px;font-size:0.8125rem;color:var(--text-secondary);">
                            <span>Created: <span class="text-primary" x-text="res.created_by_name || '—'"></span></span>
                            <span x-show="res.marked_out_at">
                                Marked Out: <span class="text-primary" x-text="formatDatetime(res.marked_out_at)"></span>
                                by <span class="text-primary" x-text="res.marked_out_by_name || '—'"></span>
                            </span>
                            <span style="margin-left:auto;">
                                Last updated: <span x-text="formatDatetime(res.updated_at)"></span>
                            </span>
                        </div>

                        <!-- Hidden updated_at for D19 optimistic lock -->
                        <input type="hidden" x-model="editForm.updated_at">

                    </div><!-- /card-body -->
                </div><!-- /detail card -->

                <!-- ── Units sub-table ──────────────────────────── -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title h5" style="margin:0;">
                            Units
                            <span class="badge badge-no-dot badge-neutral text-xs"
                                  style="margin-left:6px;"
                                  x-text="res.units ? res.units.length : 0"></span>
                        </h2>
                    </div>

                    <template x-if="!res.units || res.units.length === 0">
                        <div class="empty-state" style="padding:24px;">
                            <p class="empty-state-title" style="font-size:0.9rem;">No units linked</p>
                            <p class="empty-state-text">No equipment units were attached to this reservation.</p>
                        </div>
                    </template>

                    <template x-if="res.units && res.units.length > 0">
                        <div style="overflow-x:auto;">
                            <table class="table" aria-label="Reservation Units">
                                <thead>
                                    <tr>
                                        <th>Unit #</th>
                                        <th>Type</th>
                                        <th>VIN / Details</th>
                                        <th>Status at Reservation</th>
                                        <th>Current Status</th>
                                        <th>Entry Type</th>
                                        <th>Linked Lease</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="u in res.units" :key="u.id">
                                        <tr>
                                            <td class="font-mono font-medium">
                                                <template x-if="u.equipment_unit_id">
                                                    <a :href="'<?= base_url('equipment/show') ?>?id=' + u.equipment_unit_id"
                                                       class="link" x-text="u.unit_number"></a>
                                                </template>
                                                <template x-if="!u.equipment_unit_id">
                                                    <span x-text="u.unit_number"></span>
                                                </template>
                                            </td>
                                            <td class="text-sm" x-text="u.template_name || '—'"></td>
                                            <td class="text-xs text-secondary">
                                                <span x-text="[u.year, u.make, u.model].filter(Boolean).join(' ') || '—'"></span>
                                                <span x-show="u.vin" class="font-mono" x-text="u.vin ? ' · ' + u.vin : ''"></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-no-dot badge-neutral text-xs"
                                                      x-text="u.status_at_reservation || '—'"></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-no-dot text-xs"
                                                      :class="unitStatusBadge(u.current_unit_status)"
                                                      x-text="u.current_unit_status || '—'"></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-no-dot text-xs"
                                                      :class="u.entry_type === 'system' ? 'badge-info' : 'badge-neutral'"
                                                      x-text="u.entry_type"></span>
                                            </td>
                                            <td>
                                                <template x-if="u.lease_id_linked">
                                                    <a :href="'<?= base_url('leases/show') ?>?id=' + u.lease_id_linked"
                                                       class="link font-mono text-sm"
                                                       x-text="'Lease #' + u.lease_id_linked"></a>
                                                </template>
                                                <template x-if="!u.lease_id_linked">
                                                    <span class="text-secondary text-sm">—</span>
                                                </template>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>

                </div><!-- /units card -->

                <!-- ── Audit Log tab ───────────────────────────── -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title h5" style="margin:0;">Activity Log</h2>
                    </div>

                    <template x-if="!res.audit_log || res.audit_log.length === 0">
                        <div style="padding:16px 20px;color:var(--text-secondary);font-size:0.875rem;">
                            No activity recorded yet.
                        </div>
                    </template>

                    <template x-if="res.audit_log && res.audit_log.length > 0">
                        <div style="overflow-x:auto;">
                            <table class="table" style="font-size:0.8125rem;" aria-label="Audit Log">
                                <thead>
                                    <tr>
                                        <th>When</th>
                                        <th>Who</th>
                                        <th>Action</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="entry in res.audit_log" :key="entry.id">
                                        <tr>
                                            <td class="text-secondary" x-text="formatDatetime(entry.created_at)"></td>
                                            <td x-text="entry.user_name || 'System'"></td>
                                            <td>
                                                <span class="badge badge-no-dot text-xs"
                                                      :class="actionBadge(entry.action)"
                                                      x-text="entry.action"></span>
                                            </td>
                                            <td class="text-secondary" x-text="entry.description"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>

                </div><!-- /audit log card -->

            </div><!-- /left column -->

            <!-- ── RIGHT COLUMN — Actions panel ─────────────────── -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Actions Card -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title h5" style="margin:0;">Actions</h2>
                    </div>
                    <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">

                        <!-- ── PENDING actions ─────────────────────── -->
                        <template x-if="res.status === 'pending'">
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <?php if (can('reservations', 'edit')): ?>
                                <button class="btn btn-success"
                                        :disabled="actionBusy"
                                        @click="confirmReservation()">
                                    Confirm Reservation
                                </button>
                                <button class="btn btn-danger btn-outline"
                                        :disabled="actionBusy"
                                        @click="openCancelModal()">
                                    Cancel Reservation
                                </button>
                                <?php endif; ?>
                                <?php if (can('reservations', 'delete')): ?>
                                <button class="btn btn-ghost btn-sm"
                                        :disabled="actionBusy"
                                        @click="deleteReservation()"
                                        style="color:var(--color-danger);margin-top:4px;">
                                    Delete Reservation
                                </button>
                                <?php endif; ?>
                            </div>
                        </template>

                        <!-- ── CONFIRMED actions ───────────────────── -->
                        <template x-if="res.status === 'confirmed'">
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <?php if (can('reservations', 'edit')): ?>
                                <button class="btn btn-primary"
                                        :disabled="actionBusy"
                                        @click="markOut()">
                                    Mark Out (Chassis Out)
                                </button>
                                <p class="text-xs text-secondary" style="margin:0;">
                                    Records unit as physically checked out.
                                </p>
                                <hr style="border-color:var(--border-color);margin:4px 0;">
                                <button class="btn btn-danger btn-outline"
                                        :disabled="actionBusy"
                                        @click="openCancelModal()">
                                    Cancel Reservation
                                </button>
                                <?php endif; ?>
                            </div>
                        </template>

                        <!-- ── COMPLETED actions ───────────────────── -->
                        <template x-if="res.status === 'completed'">
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <div style="padding:8px 12px;background:var(--bg-muted);border-radius:6px;font-size:0.875rem;color:var(--text-secondary);">
                                    Marked out: <span class="text-primary font-medium"
                                                      x-text="res.marked_out_at ? formatDatetime(res.marked_out_at) : '—'"></span>
                                </div>
                                <?php if (in_array($_SESSION['ff_user']['role_slug'] ?? '', ['super_admin', 'manager'])): ?>
                                <button class="btn btn-warning btn-outline btn-sm"
                                        :disabled="actionBusy"
                                        @click="reverseMarkOut()">
                                    Reverse Mark-Out
                                </button>
                                <p class="text-xs text-secondary" style="margin:0;">
                                    Move back to Confirmed (manager only).
                                </p>
                                <?php endif; ?>
                            </div>
                        </template>

                        <!-- ── CANCELLED ───────────────────────────── -->
                        <template x-if="res.status === 'cancelled'">
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <div style="padding:8px 12px;background:var(--badge-danger-bg);border-radius:6px;font-size:0.875rem;color:var(--badge-danger-text);">
                                    This reservation has been cancelled.
                                </div>
                                <?php if (can('reservations', 'delete')): ?>
                                <button class="btn btn-ghost btn-sm"
                                        :disabled="actionBusy"
                                        @click="deleteReservation()"
                                        style="color:var(--color-danger);">
                                    Delete Reservation
                                </button>
                                <?php endif; ?>
                            </div>
                        </template>

                        <div x-show="actionBusy" class="text-secondary text-sm text-center"
                             style="padding:4px;">Processing…</div>

                    </div><!-- /card-body -->
                </div><!-- /Actions card -->

                <!-- Reservation Summary card -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title h5" style="margin:0;">Summary</h2>
                    </div>
                    <div class="card-body" style="font-size:0.875rem;">
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            <div style="display:flex;justify-content:space-between;">
                                <span class="text-secondary">Reservation ID</span>
                                <span class="font-mono" x-text="'#' + res.id"></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span class="text-secondary">Units</span>
                                <span x-text="res.units ? res.units.length : 0"></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span class="text-secondary">Quantity</span>
                                <span class="font-mono" x-text="res.quantity"></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span class="text-secondary">Pickup Date</span>
                                <span class="font-medium" x-text="formatDate(res.pickup_date)"></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;" x-show="res.yard_location">
                                <span class="text-secondary">Yard</span>
                                <span x-text="res.yard_location"></span>
                            </div>
                            <template x-if="res.customer_id">
                                <div style="display:flex;justify-content:space-between;">
                                    <span class="text-secondary">Customer</span>
                                    <a :href="'<?= base_url('customers/show') ?>?id=' + res.customer_id"
                                       class="link text-sm" x-text="res.customer_display_name"></a>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

            </div><!-- /right column -->

        </div><!-- /grid -->
    </template>

    <!-- ── Cancel Modal ──────────────────────────────────────────── -->
    <div class="modal-overlay" x-show="cancelModal.open"
         style="background:rgba(0,0,0,0.5);"
         @keydown.escape.window="cancelModal.open = false">
        <div class="card" style="width:480px;max-width:calc(100vw - 32px);padding:24px;">
            <h3 class="h5" style="margin-bottom:8px;">Cancel Reservation</h3>
            <p class="text-secondary text-sm" style="margin-bottom:16px;">
                Cancelling Reservation #<?= e($resId) ?> for
                <strong x-text="res.company_name"></strong>.
            </p>
            <div class="form-group">
                <label class="form-label" for="cancel-reason-show">Reason <span class="text-danger">*</span></label>
                <textarea id="cancel-reason-show"
                          class="form-input" rows="3"
                          placeholder="e.g. Customer called to cancel…"
                          x-model="cancelModal.reason"></textarea>
                <p class="form-error" x-show="cancelModal.error" x-text="cancelModal.error"></p>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button class="btn btn-ghost btn-sm"
                        @click="cancelModal.open = false; cancelModal.reason = ''; cancelModal.error = ''">
                    Back
                </button>
                <button class="btn btn-danger btn-sm"
                        :disabled="cancelModal.submitting"
                        @click="submitCancel()">
                    <span x-show="!cancelModal.submitting">Cancel Reservation</span>
                    <span x-show="cancelModal.submitting">Cancelling…</span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<!-- ============================================================
     ALPINE COMPONENT
     ============================================================ -->
<script>
function FF_ReservationShow(resId) {
    return {
        // ── State ────────────────────────────────────────────────
        res:           {},
        loading:       true,
        notFound:      false,
        editing:       false,
        saving:        false,
        actionBusy:    false,
        actionError:   '',
        actionSuccess: '',
        yards:         [],   // active yards for Pickup Yard dropdown in edit mode

        editForm:   {},
        editErrors: {},

        cancelModal: {
            open:       false,
            reason:     '',
            error:      '',
            submitting: false,
        },

        // ── Init ─────────────────────────────────────────────────
        async init() {
            // Load reservation and yards in parallel for faster render
            await Promise.all([this.loadReservation(), this.loadYards()]);
        },

        // ── Load active yards for Pickup Yard dropdown ────────────
        // WHY: yards are needed only when editing — but we load them
        // upfront (lightweight call, <20 rows) so the select is ready
        // immediately when the user clicks Edit.
        async loadYards() {
            try {
                const res  = await fetch('<?= base_url('api/v1/yards/index.php') ?>');
                const data = await res.json();
                this.yards = data.data?.yards ?? [];
            } catch {}
        },

        // ── Load reservation from API ─────────────────────────────
        async loadReservation() {
            this.loading = true;
            try {
                const res  = await fetch(`<?= base_url('api/v1/reservations/show.php') ?>?id=${resId}`);
                const data = await res.json();
                if (!data.success) {
                    this.notFound = true;
                } else {
                    this.res = data.data;
                }
            } catch {
                this.notFound = true;
            } finally {
                this.loading = false;
            }
        },

        // ── Edit mode ─────────────────────────────────────────────
        startEdit() {
            // Copy current values into edit form
            this.editForm = {
                id:             this.res.id,
                updated_at:     this.res.updated_at,
                contact_name:   this.res.contact_name   || '',
                company_name:   this.res.company_name   || '',
                contact_phone:  this.res.contact_phone  || '',
                contact_email:  this.res.contact_email  || '',
                quantity:       this.res.quantity,
                pickup_date:    this.res.pickup_date     || '',
                pickup_time:    this.res.pickup_time     || '',
                yard_location:  this.res.yard_location   || '',
                purpose:        this.res.purpose         || '',
                priority:       this.res.priority        || 'medium',
                notes:          this.res.notes           || '',
                internal_notes: this.res.internal_notes  || '',
                customer_id:    this.res.customer_id     || null,
            };
            this.editErrors = {};
            this.editing    = true;
        },

        cancelEdit() {
            this.editing    = false;
            this.editErrors = {};
        },

        // ── Client-side validation for edit form ─────────────────
        validateEdit() {
            this.editErrors = {};
            let ok = true;

            if (!this.editForm.contact_name || !this.editForm.contact_name.trim()) {
                this.editErrors.contact_name = 'Contact name is required.';
                ok = false;
            }
            if (!this.editForm.company_name || !this.editForm.company_name.trim()) {
                this.editErrors.company_name = 'Company name is required.';
                ok = false;
            }
            if (!this.editForm.pickup_date) {
                this.editErrors.pickup_date = 'Pickup date is required.';
                ok = false;
            } else {
                const today = new Date().toISOString().split('T')[0];
                if (this.editForm.pickup_date < today) {
                    this.editErrors.pickup_date = 'Pickup date cannot be in the past.';
                    ok = false;
                }
            }
            const q = parseInt(this.editForm.quantity);
            if (isNaN(q) || q < 1) {
                this.editErrors.quantity = 'Quantity must be at least 1.';
                ok = false;
            } else if (q > 500) {
                this.editErrors.quantity = 'Quantity cannot exceed 500.';
                ok = false;
            }
            if (this.editForm.contact_email && this.editForm.contact_email.trim()) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(this.editForm.contact_email.trim())) {
                    this.editErrors.contact_email = 'Please enter a valid email address.';
                    ok = false;
                }
            }

            return ok;
        },

        async saveEdit() {
            if (!this.validateEdit()) {
                this.actionError = 'Please fix the errors below and try again.';
                return;
            }
            this.saving     = true;
            this.editErrors = {};
            this.actionError = '';
            try {
                const res  = await FF_Api.post('<?= base_url('api/v1/reservations/update.php') ?>', this.editForm);
                if (!res.success) {
                    // VALID-2: prefer .fields, fall back to legacy .errors
                    if (res.error?.fields) this.editErrors = res.error.fields;
                    else if (res.error?.errors) this.editErrors = res.error.errors;

                    // STALE_DATA — tell user to reload
                    if (res.error?.code === 'STALE_DATA') {
                        this.actionError = 'This reservation was modified by another user. Please reload this page and try again.';
                        return;
                    }
                    throw new Error(res.error?.message || 'Failed to save');
                }
                // Refresh and exit edit mode
                await this.loadReservation();
                this.editing      = false;
                this.actionSuccess = 'Reservation updated.';
                setTimeout(() => this.actionSuccess = '', 3000);
            } catch (e) {
                this.actionError = e.message;
            } finally {
                this.saving = false;
            }
        },

        // ── Action: Confirm ───────────────────────────────────────
        async confirmReservation() {
            if (!(await FF_Confirm.ask('Confirm this reservation? System-linked units will be marked as Reserved.'))) return;
            this.actionBusy  = true;
            this.actionError = '';
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/update_status.php') ?>', {
                    id: resId, status: 'confirmed',
                });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                await this.loadReservation();
                this.actionSuccess = 'Reservation confirmed.';
                setTimeout(() => this.actionSuccess = '', 3000);
            } catch (e) {
                this.actionError = e.message;
            } finally {
                this.actionBusy = false;
            }
        },

        // ── Action: Mark Out ──────────────────────────────────────
        async markOut() {
            if (!(await FF_Confirm.ask('Mark this reservation as Chassis Out?\n\nThis records the unit as physically leaving the yard.'))) return;
            this.actionBusy  = true;
            this.actionError = '';
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/mark_out.php') ?>', { id: resId });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                await this.loadReservation();
                this.actionSuccess = 'Reservation marked out — Chassis Out.';
                setTimeout(() => this.actionSuccess = '', 4000);
            } catch (e) {
                this.actionError = e.message;
            } finally {
                this.actionBusy = false;
            }
        },

        // ── Action: Reverse mark-out ──────────────────────────────
        async reverseMarkOut() {
            if (!(await FF_Confirm.ask('Reverse the mark-out? This will move the reservation back to Confirmed.'))) return;
            this.actionBusy  = true;
            this.actionError = '';
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/update_status.php') ?>', {
                    id: resId, status: 'confirmed',
                });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                await this.loadReservation();
                this.actionSuccess = 'Mark-out reversed — reservation is now Confirmed.';
                setTimeout(() => this.actionSuccess = '', 3000);
            } catch (e) {
                this.actionError = e.message;
            } finally {
                this.actionBusy = false;
            }
        },

        // ── Action: Cancel ────────────────────────────────────────
        openCancelModal() {
            this.cancelModal = { open: true, reason: '', error: '', submitting: false };
        },

        async submitCancel() {
            if (!this.cancelModal.reason.trim()) {
                this.cancelModal.error = 'Please provide a reason for cancellation.';
                return;
            }
            this.cancelModal.submitting = true;
            this.cancelModal.error = '';
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/update_status.php') ?>', {
                    id:            resId,
                    status:        'cancelled',
                    cancel_reason: this.cancelModal.reason,
                });
                if (!res.success) {
                    // Prefer the per-field message when available
                    const fieldMsg = res.error?.fields?.cancel_reason || res.error?.fields?.status;
                    throw new Error(fieldMsg || res.error?.message || 'Failed');
                }
                this.cancelModal.open = false;
                await this.loadReservation();
                this.actionSuccess = 'Reservation cancelled.';
                setTimeout(() => this.actionSuccess = '', 3000);
            } catch (e) {
                this.cancelModal.error = e.message;
            } finally {
                this.cancelModal.submitting = false;
            }
        },

        // ── Action: Delete ────────────────────────────────────────
        async deleteReservation() {
            if (!(await FF_Confirm.ask('Permanently delete this reservation?\n\nThis cannot be undone.'))) return;
            this.actionBusy  = true;
            this.actionError = '';
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/delete.php') ?>', { id: resId });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                window.location.href = '<?= base_url('reservations') ?>';
            } catch (e) {
                this.actionError = e.message;
                this.actionBusy  = false;
            }
        },

        // ── Helpers ───────────────────────────────────────────────
        formatDate(d) {
            if (!d) return '—';
            const [y, m, day] = d.split('-');
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return `${months[parseInt(m, 10) - 1]} ${parseInt(day, 10)}, ${y}`;
        },

        formatDatetime(dt) {
            if (!dt) return '—';
            const d = new Date(dt.replace(' ', 'T'));
            return d.toLocaleString('en-CA', {
                month: 'short', day: 'numeric', year: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: false,
            });
        },

        statusBadge(s) {
            return {
                'pending':   'badge-warning',
                'confirmed': 'badge-success',
                'completed': 'badge-info',
                'cancelled': 'badge-danger',
            }[s] || 'badge-neutral';
        },

        priorityBadge(p) {
            return {
                'urgent': 'badge-danger',
                'high':   'badge-warning',
                'medium': 'badge-neutral',
                'low':    'badge-neutral',
            }[p] || 'badge-neutral';
        },

        // S-UNIT-STATUS-COLOR 2026-05-14: corrected to canonical 6-status
        // mapping per DESIGN_DETAILS.md §9 + includes/functions.php
        // unit_status_badge_class(). Pre-fix drift: reserved→warning (now
        // purple), maintenance→danger (now warning), decommissioned→
        // neutral (now danger). The PHP-side helper is the source of truth;
        // this JS-side mirror exists because Alpine templates render
        // client-side from API data.
        unitStatusBadge(s) {
            return {
                'available':     'badge-success',
                'reserved':      'badge-purple',
                'on_lease':      'badge-info',
                'maintenance':   'badge-warning',
                'inactive':      'badge-neutral',
                'decommissioned':'badge-danger',
            }[s] || 'badge-neutral';
        },

        actionBadge(action) {
            if (['created'].includes(action))                 return 'badge-success';
            if (['updated'].includes(action))                 return 'badge-info';
            if (['deleted'].includes(action))                 return 'badge-danger';
            if (['status_changed'].includes(action))          return 'badge-warning';
            return 'badge-neutral';
        },
    };
}
</script>

<!-- ============================================================
     STATUS TIMELINE STEPPER — CSS
     ============================================================ -->
<style>
/* ── Track: flex row containing steps + connectors ──── */
.ff-stepper-track {
    display: flex;
    align-items: center;
}

/* ── Each step: circle above, label below ────────────── */
.ff-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    flex-shrink: 0;
}

.ff-step-circle {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    font-weight: 700;
    border: 2px solid;
    transition: background 0.3s, border-color 0.3s, box-shadow 0.3s;
}

/* Active (current step) — blue pulse ring */
.ff-step-active .ff-step-circle {
    background: #3b82f6;
    border-color: #3b82f6;
    color: white;
    box-shadow: 0 0 0 5px rgba(59,130,246,0.15);
}
.ff-step-active .ff-step-label { color: #3b82f6; font-weight: 600; }

/* Done (past step) — green checkmark */
.ff-step-done .ff-step-circle {
    background: #22c55e;
    border-color: #22c55e;
    color: white;
}
.ff-step-done .ff-step-label { color: var(--text-primary); font-weight: 600; }

/* Future (upcoming step) — muted empty circle */
.ff-step-future .ff-step-circle {
    background: transparent;
    border-color: var(--border-color);
    color: var(--text-muted);
}
.ff-step-future .ff-step-label { color: var(--text-muted); }

.ff-step-label    { font-size: 0.8125rem; white-space: nowrap; }
.ff-step-sublabel { font-size: 0.7rem; color: var(--text-muted); white-space: nowrap; min-height: 14px; }

/* ── Connector line between steps ────────────────────── */
.ff-connector {
    flex: 1;
    height: 2px;
    background: var(--border-color);
    margin: 0 10px;
    margin-bottom: 28px;   /* visually aligns with circle centers */
    transition: background 0.5s ease;
}
.ff-connector-done { background: #22c55e; }
</style>

<?php
// ── AI Reservation Summary panel (S-AI-SUMMARY-PANELS) ──
$aiSummaryEntityType = 'reservation';
$aiSummaryEntityId   = $resId;
$aiSummaryType       = 'reservation_summary';
$aiSummaryTitle      = 'Reservation Summary — #' . (int)$resId;
require_once FF_ROOT . '/includes/partials/ai-panel.php';
?>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
