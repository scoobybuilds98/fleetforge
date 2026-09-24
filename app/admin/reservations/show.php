<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Show / Edit Page
 *
 * @file        app/admin/reservations/show.php
 * @description Full reservation detail view with inline edit mode
 *              (S-RECORD-REDESIGN record layout).
 *
 *              Layout:
 *                - Entity hero: "Reservation #N" + status / priority badges,
 *                  company · pickup line, contact + purpose facts. The header
 *                  holds the status actions (Confirm when pending, Mark Out
 *                  when confirmed, Edit / Save); Cancel, Reverse Mark-Out,
 *                  Delete, AI Analysis and "+ New" sit in the More menu.
 *                - Key-numbers strip: pickup (date + days until / overdue),
 *                  units attached vs requested, pickup yard, status + next step.
 *                - Main column: compact lifecycle stepper (Pending → Confirmed
 *                  → Completed, with dates), Reservation Details (view/edit
 *                  toggle), Units table, Activity Log.
 *                - Right rail: Needs attention (pickup passed / today / not
 *                  confirmed, units short, reserved unit busy elsewhere,
 *                  customer on hold, no contact), Customer, Lease (the lease(s)
 *                  linked at mark-out — reservation_units.lease_id_linked),
 *                  Summary.
 *
 *              The whole page renders client-side from api/v1/reservations/
 *              show.php (status actions reload it in place), so the strip and
 *              rail are Alpine-bound; RecordUi only draws the rail card shells.
 *              Derived values (alerts, strip text, linked leases) are rebuilt
 *              by refreshDerived() after every load — plain data props, not
 *              getters, so bindings never go stale.
 *
 *              Status actions (permission-gated, unchanged endpoints):
 *                - Pending:   Confirm · Cancel · Delete
 *                - Confirmed: Mark Out (Chassis Out) · Cancel
 *                - Completed: Reverse Mark-Out (manager / super admin)
 *                - Cancelled: Delete (delete permission only)
 *
 *              Edit mode: inline toggle. D19 optimistic lock (hidden updated_at field).
 *              Conflict detection on update: handled server-side if pickup_date changes.
 *
 *              Units: lists reservation_units rows with current equipment status
 *              and the lease linked at mark-out.
 *
 *              Activity log: last 20 actions from audit_log for this reservation
 *              (inline — 'reservation' is not in the shared activity partial's
 *              api/v1/audit/history.php gate map).
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/reservations/show.php,
 *              api/v1/reservations/update.php, api/v1/reservations/update_status.php,
 *              api/v1/reservations/mark_out.php, api/v1/reservations/delete.php,
 *              api/v1/reservations/units_by_customer.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @decisions   D19 (optimistic lock), D5 (soft-delete)
 * @session     S018, S-RECORD-REDESIGN
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

// ── S-RECORD-REDESIGN: rail context the reservation API doesn't carry ──
// The customer link can't change from this page (the edit form re-sends the
// existing customer_id), so its account status is read once here for the
// "customer on hold" alert. Lease labels: contract numbers for the leases
// already linked at mark-out; a lease linked later in this visit falls back
// to "Lease #id" until the page is reloaded.
$_resCtx = db_row(
    "SELECT r.customer_id, c.status AS customer_status
       FROM reservations r
       LEFT JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL
      WHERE r.id = ? AND r.deleted_at IS NULL",
    [$resId]
) ?? [];
$resLeaseLabels = [];
foreach (db_select(
    "SELECT DISTINCT l.id, l.contract_number, l.status
       FROM reservation_units ru
       JOIN leases l ON l.id = ru.lease_id_linked AND l.deleted_at IS NULL
      WHERE ru.reservation_id = ?",
    [$resId]
) as $_l) {
    $resLeaseLabels[(int) $_l['id']] = ['number' => (string) $_l['contract_number'], 'status' => (string) $_l['status']];
}
// Company-local business day (never the browser's clock or SQL CURDATE()).
$resToday = ff_today();

$pageTitle      = 'Reservation #' . $resId;
$helpModuleSlug = 'reservations';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     RESERVATION SHOW — ALPINE COMPONENT. Opens ABOVE the hero so the
     header's status actions (Confirm / Mark Out / Edit …) call its
     methods (S-RECORD-REDESIGN).
     ============================================================ -->
<div x-data="FF_ReservationShow(<?= $resId ?>)">

    <!-- ── Page header — entity hero (S-MODULE-CHROME). Title block,
         live facts and action buttons are the page's own Alpine markup,
         placed unchanged inside the hero. ─────────────────────────── -->
<?php ob_start(); ?>
            <h1 class="page-header-title h4" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
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
            <?php /* Facts: who to call + why. Quantity and yard moved to the
                     key-numbers strip below (S-RECORD-REDESIGN). */ ?>
            <div class="ff-hero-facts" x-show="!loading" x-cloak>
                <span class="ff-hero-fact" x-show="res.contact_name"><?= \FleetForge\Sop\SopIcons::svg('users') ?><span x-text="res.contact_name"></span></span>
                <span class="ff-hero-fact" x-show="res.contact_phone"><?= \FleetForge\Sop\SopIcons::svg('phone') ?><span x-text="res.contact_phone"></span></span>
                <span class="ff-hero-fact" x-show="res.purpose"><?= \FleetForge\Sop\SopIcons::svg('clipboard-document-list') ?><span x-text="res.purpose"></span></span>
            </div>
<?php $heroOwn = ob_get_clean(); ?>
<?php ob_start(); /* secondary + destructive actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
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
            <a href="<?= base_url('reservations/create') ?>" class="btn btn-ghost btn-sm">+ New Reservation</a>
            <?php if (in_array($_SESSION['ff_user']['role_slug'] ?? '', ['super_admin', 'manager'])): ?>
            <button type="button" class="btn btn-warning btn-sm"
                    x-show="res.status === 'completed'"
                    :disabled="actionBusy"
                    @click="reverseMarkOut()"
                    title="Move back to Confirmed (manager only)">
                Reverse Mark-Out
            </button>
            <?php endif; ?>
            <?php if (can('reservations', 'edit')): ?>
            <button type="button" class="btn btn-danger btn-sm"
                    x-show="res.status === 'pending' || res.status === 'confirmed'"
                    :disabled="actionBusy"
                    @click="openCancelModal()">
                Cancel Reservation
            </button>
            <?php endif; ?>
            <?php if (can('reservations', 'delete')): ?>
            <button type="button" class="btn btn-danger btn-sm"
                    x-show="res.status === 'pending' || res.status === 'cancelled'"
                    :disabled="actionBusy"
                    @click="deleteReservation()">
                Delete Reservation
            </button>
            <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <div class="ff-contents" x-show="!loading">
            <?= help_button('reservations') ?>
            <?php if (can('reservations', 'edit')): ?>
            <?php /* The lifecycle's next step is the primary action — it used to
                     sit in a right-hand Actions card (S-RECORD-REDESIGN). */ ?>
            <button type="button" class="btn btn-success btn-sm"
                    x-show="!editing && res.status === 'pending'"
                    :disabled="actionBusy"
                    @click="confirmReservation()">
                Confirm Reservation
            </button>
            <button type="button" class="btn btn-primary btn-sm"
                    x-show="!editing && res.status === 'confirmed'"
                    :disabled="actionBusy"
                    @click="markOut()"
                    title="Record the unit as physically checked out">
                Mark Out (Chassis Out)
            </button>
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
            <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
        </div>
<?php $heroActions = ob_get_clean(); ?>
    <?= \FleetForge\Ui\ModuleHero::render([
        'entity'    => true,
        'accent'    => 'info',
        'icon'      => 'calendar',
        'mark'      => '#' . $resId,
        'crumbs'    => [['Dashboard', base_url('dashboard')], ['Reservations', base_url('reservations')], ['Reservation #' . $resId, null]],
        'eyebrow'   => 'Reservation',
        'main_html' => $heroOwn,
        'actions'   => $heroActions,
    ]) ?>

    <!-- ============================================================
         KEY NUMBERS — the summary strip (S-RECORD-REDESIGN): when the
         pickup is (and how far away / how late), how many of the
         requested units are attached, where it leaves from, and what the
         next step is. Alpine-bound: status actions reload in place.
         ============================================================ -->
    <div class="stat-grid ff-stats" x-show="!loading && !notFound" x-cloak>
        <div class="stat-card" :class="'stat-card--' + strip.pickupTone" title="Pickup date">
            <span class="stat-icon" :class="'stat-icon--' + strip.pickupTone"><svg><use href="#icon-clock"/></svg></span>
            <div class="stat-label">Pickup</div>
            <div class="stat-value" x-text="formatDate(res.pickup_date)"></div>
            <div class="stat-delta" x-text="strip.pickupRel"></div>
        </div>

        <div class="stat-card" :class="'stat-card--' + strip.unitsTone" title="Units attached to this reservation vs the quantity requested">
            <span class="stat-icon" :class="'stat-icon--' + strip.unitsTone"><svg><use href="#icon-truck"/></svg></span>
            <div class="stat-label">Units</div>
            <div class="stat-value font-mono" x-text="strip.unitsValue"></div>
            <div class="stat-delta" x-text="strip.unitsDelta"></div>
        </div>

        <div class="stat-card stat-card--slate" title="Pickup yard">
            <span class="stat-icon stat-icon--slate"><svg><use href="#icon-map-pin"/></svg></span>
            <div class="stat-label">Yard</div>
            <div class="stat-value" x-text="res.yard_location || 'Not set'"></div>
            <div class="stat-delta" x-text="res.pickup_time ? 'at ' + res.pickup_time.substring(0,5) : 'no pickup time'"></div>
        </div>

        <div class="stat-card" :class="'stat-card--' + strip.statusTone" title="Where the reservation is in its lifecycle">
            <span class="stat-icon" :class="'stat-icon--' + strip.statusTone"><svg><use href="#icon-check-circle"/></svg></span>
            <div class="stat-label">Status</div>
            <div class="stat-value" x-text="res.status ? res.status.charAt(0).toUpperCase() + res.status.slice(1) : '—'"></div>
            <div class="stat-delta" x-text="strip.statusNext"></div>
        </div>
    </div>

    <!-- Flash message -->
    <?php if ($flashMsg): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">
        <?= e($flashMsg) ?>
    </div>
    <?php endif; ?>

    <!-- Global error / success banners -->
    <div class="alert alert-danger"  x-show="actionError"   x-text="actionError"   style="margin-bottom:12px;" x-transition></div>
    <div class="alert alert-success" x-show="actionSuccess" x-text="actionSuccess" style="margin-bottom:12px;" x-transition></div>

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
        <div class="rec-layout">

            <!-- ── MAIN COLUMN ──────────────────────────────────── -->
            <div class="rec-main" style="display:flex;flex-direction:column;gap:16px;">

                <!-- ================================================
                     STATUS TIMELINE STEPPER — compact (S-RECORD-REDESIGN):
                     one slim row, each step with its date. Cancelled
                     reservations show a one-line banner instead.
                     ================================================ -->
                <template x-if="res.status === 'cancelled'">
                    <div class="rs-cancelled" role="status">
                        <span class="rs-cancelled-ic" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 18 18 6M6 6l12 12"/></svg>
                        </span>
                        <b>Cancelled</b>
                        <span class="text-secondary text-sm" x-show="res.cancel_reason" x-text="res.cancel_reason"></span>
                        <span class="text-secondary text-sm" style="margin-left:auto;" x-text="'Last updated ' + formatDatetime(res.updated_at)"></span>
                    </div>
                </template>

                <template x-if="res.status !== 'cancelled'">
                    <div class="rs-steps card" aria-label="Reservation lifecycle">
                        <!-- Step 1 — Pending -->
                        <div class="rs-step" :class="res.status === 'pending' ? 'is-active' : 'is-done'">
                            <span class="rs-dot">
                                <svg x-show="res.status !== 'pending'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <span x-show="res.status === 'pending'">1</span>
                            </span>
                            <span class="rs-step-text">
                                <span class="rs-step-label">Pending</span>
                                <span class="rs-step-sub" x-text="'Created ' + utcDay(res.created_at)"></span>
                            </span>
                        </div>
                        <div class="rs-line" :class="['confirmed','completed'].includes(res.status) ? 'is-done' : ''"></div>

                        <!-- Step 2 — Confirmed -->
                        <div class="rs-step" :class="res.status === 'confirmed' ? 'is-active' : (res.status === 'completed' ? 'is-done' : 'is-future')">
                            <span class="rs-dot">
                                <svg x-show="res.status === 'completed'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <span x-show="res.status !== 'completed'">2</span>
                            </span>
                            <span class="rs-step-text">
                                <span class="rs-step-label">Confirmed</span>
                                <span class="rs-step-sub" x-text="res.status === 'pending' ? 'Units get reserved' : (res.status === 'confirmed' ? 'Current' : 'Units were reserved')"></span>
                            </span>
                        </div>
                        <div class="rs-line" :class="res.status === 'completed' ? 'is-done' : ''"></div>

                        <!-- Step 3 — Completed -->
                        <div class="rs-step" :class="res.status === 'completed' ? 'is-done' : 'is-future'">
                            <span class="rs-dot">
                                <svg x-show="res.status === 'completed'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <span x-show="res.status !== 'completed'">3</span>
                            </span>
                            <span class="rs-step-text">
                                <span class="rs-step-label">Completed</span>
                                <span class="rs-step-sub" x-text="res.marked_out_at ? 'Chassis out ' + utcDay(res.marked_out_at) : 'Chassis out'"></span>
                            </span>
                        </div>
                    </div>
                </template>

                <!-- Detail card -->
                <div class="card rs-details">
                    <div class="card-header" style="display:flex;align-items:center;gap:8px;">
                        <h2 class="card-title h5" style="margin:0;">Reservation Details</h2>
                        <span class="badge badge-no-dot badge-neutral text-xs" x-show="editing"
                              style="margin-left:auto;">Editing</span>
                    </div>
                    <div class="card-body">

                        <?php /* The read-only Status field and the Created / Marked out /
                                 Last updated meta row moved out (S-RECORD-REDESIGN): status
                                 is in the header, strip and stepper; the audit facts are in
                                 the rail's Summary card. */ ?>
                        <div class="form-grid-2">

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

            </div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the reservation at a glance. RecordUi
// draws the card shells; the bodies are Alpine-bound (the page reloads the
// reservation in place after every status action). Replaces the old
// Actions card (those buttons now live in the header) and Summary card.
$R = \FleetForge\Ui\RecordUi::class;
$railR = [];

// 1. Needs attention — built by refreshDerived() from the loaded record.
$railR[] = $R::card('Needs attention',
    '<ul class="rec-alerts" x-show="alerts.length">'
    . '<template x-for="(a, i) in alerts" :key="i"><li :class="\'is-\' + a.tone"><span x-text="a.text"></span></li></template>'
    . '</ul>'
    . '<p class="rec-alerts-ok" x-show="!alerts.length" x-text="res.status === \'completed\' ? \'Marked out — nothing left to do.\' : \'All clear — nothing needs attention.\'"></p>',
    ['icon' => 'exclamation-triangle']);

// 2. Customer — the linked account (or the typed company) + how to reach them.
$custLink = !empty($_resCtx['customer_id'])
    ? ['link' => ['Open', base_url('customers/show') . '?id=' . (int) $_resCtx['customer_id']]] : [];
$railR[] = $R::card('Customer',
    '<template x-if="res.customer_id">'
    .   '<a class="rec-entity" :href="\'' . e(base_url('customers/show')) . '?id=\' + res.customer_id">'
    .     '<span class="rec-entity-av" aria-hidden="true" x-text="initials(res.customer_display_name || res.company_name)"></span>'
    .     '<span><span class="rec-entity-name" x-text="res.customer_display_name || res.company_name"></span><br>'
    .     '<span class="rec-entity-sub" x-text="res.contact_name || \'Customer\'"></span></span>'
    .   '</a>'
    . '</template>'
    . '<template x-if="!res.customer_id">'
    .   '<div class="rec-entity">'
    .     '<span class="rec-entity-av" aria-hidden="true" x-text="initials(res.company_name)"></span>'
    .     '<span><span class="rec-entity-name" x-text="res.company_name || \'—\'"></span><br>'
    .     '<span class="rec-entity-sub">Not linked to a customer account</span></span>'
    .   '</div>'
    . '</template>'
    . '<dl class="rec-kv" style="margin-top:10px;" x-show="res.contact_phone || res.contact_email">'
    .   '<div x-show="res.contact_phone"><dt>Phone</dt><dd><a :href="\'tel:\' + (res.contact_phone || \'\').replace(/[^0-9+]/g, \'\')" x-text="res.contact_phone"></a></dd></div>'
    .   '<div x-show="res.contact_email"><dt>Email</dt><dd><a :href="\'mailto:\' + res.contact_email" x-text="res.contact_email"></a></dd></div>'
    . '</dl>',
    ['icon' => 'user-group'] + $custLink);

// 3. Lease — what this reservation turned into (reservation_units.lease_id_linked,
//    written by mark_out.php). Contract numbers come from the server preload.
$railR[] = $R::card('Lease',
    '<div class="rec-links" x-show="linkedLeases.length">'
    . '<template x-for="l in linkedLeases" :key="l.id">'
    .   '<a :href="\'' . e(base_url('leases/show')) . '?id=\' + l.id">' . \FleetForge\Sop\SopIcons::svg('calendar-days')
    .   '<span x-text="l.label"></span><small x-text="l.sub"></small></a>'
    . '</template>'
    . '</div>'
    . '<p class="text-secondary" style="margin:0;font-size:12.5px;line-height:1.5;" x-show="!linkedLeases.length" '
    .   'x-text="res.status === \'completed\' ? \'Marked out without a lease link.\' '
    .   ': (res.status === \'cancelled\' ? \'No lease — the reservation was cancelled.\' : \'Linked when the reservation is marked out (Chassis Out).\')"></p>',
    ['icon' => 'calendar-days']);

// 4. Summary — the record's facts (the old detail meta row + Summary card).
$railR[] = $R::card('Summary',
    '<dl class="rec-kv">'
    .   '<div><dt>Reservation</dt><dd class="mono" x-text="\'#\' + res.id"></dd></div>'
    .   '<div><dt>Priority</dt><dd><span class="badge badge-no-dot text-xs" :class="priorityBadge(res.priority)" x-text="res.priority ? res.priority.charAt(0).toUpperCase() + res.priority.slice(1) : \'—\'"></span></dd></div>'
    .   '<div><dt>Requested</dt><dd class="mono" x-text="res.quantity + (Number(res.quantity) === 1 ? \' unit\' : \' units\')"></dd></div>'
    .   '<div><dt>Created</dt><dd x-text="formatDatetime(res.created_at) + (res.created_by_name ? \' · \' + res.created_by_name : \'\')"></dd></div>'
    .   '<div x-show="res.marked_out_at"><dt>Marked out</dt><dd x-text="formatDatetime(res.marked_out_at) + (res.marked_out_by_name ? \' · \' + res.marked_out_by_name : \'\')"></dd></div>'
    .   '<div><dt>Updated</dt><dd x-text="formatDatetime(res.updated_at) + (res.updated_by_name ? \' · \' + res.updated_by_name : \'\')"></dd></div>'
    . '</dl>',
    ['icon' => 'document-text']);
?>
            <aside class="rec-rail" aria-label="Reservation at a glance">
                <?= implode("\n                ", $railR) ?>
            </aside>

        </div><!-- /rec-layout -->
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

    <!-- ── Chassis Out Modal ─────────────────────────────────────────
         Offers the open (pending/active) leases on the reserved unit(s) so
         mark_out.php receives a lease_id — previously it was never sent, so
         the "Lease #" link in the Units table could never be populated. -->
    <div class="modal-overlay" x-show="markOutModal.open" x-cloak
         style="background:rgba(0,0,0,0.5);"
         @keydown.escape.window="markOutModal.open = false">
        <div class="card" style="width:520px;max-width:calc(100vw - 32px);padding:24px;">
            <h3 class="h5" style="margin-bottom:8px;">Mark Out (Chassis Out)</h3>
            <p class="text-secondary text-sm" style="margin-bottom:16px;">
                Records Reservation #<?= e($resId) ?> for
                <strong x-text="res.company_name"></strong> as physically leaving the yard.
            </p>
            <div class="form-group">
                <label class="form-label" for="markout-lease-show">Link to lease</label>
                <select id="markout-lease-show" class="form-select"
                        x-model="markOutModal.leaseId"
                        :disabled="markOutModal.loadingLeases || markOutModal.submitting">
                    <option value="">— No lease: release the unit to Available —</option>
                    <template x-for="l in markOutModal.leases" :key="l.id">
                        <option :value="String(l.id)" x-text="l.label"></option>
                    </template>
                </select>
                <p class="text-xs text-secondary" style="margin-top:6px;" x-show="markOutModal.loadingLeases">Looking up open leases on the reserved unit(s)…</p>
                <p class="text-xs text-secondary" style="margin-top:6px;" x-show="!markOutModal.loadingLeases && markOutModal.leases.length === 0">
                    No pending or active lease on the reserved unit(s). The unit will be released to Available.
                </p>
                <p class="text-xs text-secondary" style="margin-top:6px;" x-show="!markOutModal.loadingLeases && markOutModal.leases.length > 0">
                    A linked lease keeps the unit with that lease (Reserved until activated, On Lease once active).
                </p>
                <p class="form-error" x-show="markOutModal.error" x-text="markOutModal.error"></p>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button class="btn btn-ghost btn-sm" @click="markOutModal.open = false">Back</button>
                <button class="btn btn-primary btn-sm"
                        :disabled="markOutModal.submitting || markOutModal.loadingLeases"
                        @click="submitMarkOut()">
                    <span x-show="!markOutModal.submitting">Mark Out</span>
                    <span x-show="markOutModal.submitting">Saving…</span>
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

        // ── S-RECORD-REDESIGN: strip + rail ──────────────────────
        // Server context: the company-local business day (ff_today(), not the
        // browser clock), the linked customer's account status and the
        // contract numbers of already-linked leases.
        today:          <?= json_encode($resToday) ?>,
        customerStatus: <?= json_encode((string) ($_resCtx['customer_status'] ?? '')) ?>,
        leaseLabels:    <?= json_encode((object) $resLeaseLabels, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
        // Derived from `res` by refreshDerived() after every load — plain data
        // (not getters) so x-show / x-text bindings never go stale.
        alerts:       [],
        linkedLeases: [],
        strip: {
            pickupRel: '', pickupTone: 'blue',
            unitsValue: '', unitsDelta: '', unitsTone: 'blue',
            statusNext: '', statusTone: 'slate',
        },

        editForm:   {},
        editErrors: {},

        cancelModal: {
            open:       false,
            reason:     '',
            error:      '',
            submitting: false,
        },

        // Chassis Out modal — leases = [{id, customer_id, label}] open leases on the reserved unit(s).
        markOutModal: {
            open:          false,
            leases:        [],
            leaseId:       '',
            loadingLeases: false,
            error:         '',
            submitting:    false,
        },
        _markOutLookup: 0,   // bumps per open; stale lease lookups are dropped

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
                    this.refreshDerived();
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
                const today = FF_localDate();
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
        // Opens the Chassis Out modal and looks up the open leases on the
        // reservation's unit(s) so a lease_id can be sent to mark_out.php.
        async markOut() {
            this.markOutModal = { open: true, leases: [], leaseId: '',
                                  loadingLeases: true, error: '', submitting: false };
            // Token set BEFORE the first await: a lookup that resolves after the modal
            // was closed and reopened must not overwrite the newer candidate list.
            const lookup = ++this._markOutLookup;
            const unitIds = [...new Set((this.res.units || []).map(u => u.equipment_unit_id).filter(Boolean))];
            const found = [];
            try {
                const pages = await Promise.all(unitIds.map(uid =>
                    FF_Api.get('<?= base_url('api/v1/leases/index.php') ?>?unit_id=' + uid + '&statuses=pending,active&per_page=20')
                        .catch(() => null)
                ));
                for (const pg of pages) {
                    for (const l of ((pg && pg.data && pg.data.items) || [])) {
                        if (found.some(x => x.id === l.id)) continue;
                        found.push({
                            id: l.id,
                            customer_id: l.customer_id,
                            label: `${l.contract_number} · ${l.customer_display_name || '—'} · ${l.unit_display_number || ''} · ${l.status} from ${l.start_date}`,
                        });
                    }
                }
            } catch (e) { /* lookup is best-effort; mark-out without a lease still works */ }
            if (lookup !== this._markOutLookup) return;
            this.markOutModal.leases = found;
            this.markOutModal.loadingLeases = false;
            // Pre-select only an unambiguous match: the single open lease for this
            // reservation's customer (or the only candidate when no customer is set).
            const cust = this.res.customer_id;
            const mine = cust ? found.filter(l => String(l.customer_id) === String(cust)) : found;
            if (mine.length === 1) {
                const pick = String(mine[0].id);
                // Set after the x-for options render, or the <select> keeps showing the blank option.
                this.$nextTick(() => { this.markOutModal.leaseId = pick; });
            }
        },

        async submitMarkOut() {
            if (this.markOutModal.submitting) return;
            this.markOutModal.submitting = true;
            this.markOutModal.error = '';
            this.actionBusy  = true;
            this.actionError = '';
            try {
                const payload = { id: resId };
                if (this.markOutModal.leaseId) payload.lease_id = parseInt(this.markOutModal.leaseId, 10);
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/mark_out.php') ?>', payload);
                // FF_Api.post resolves on 4xx — gate on success.
                if (!res.success) throw new Error(res.error?.fields?.lease_id || res.error?.message || 'Failed');
                this.markOutModal.open = false;
                await this.loadReservation();
                this.actionSuccess = payload.lease_id
                    ? 'Reservation marked out — Chassis Out, linked to the lease.'
                    : 'Reservation marked out — Chassis Out.';
                setTimeout(() => this.actionSuccess = '', 4000);
            } catch (e) {
                this.markOutModal.error = e.message;
            } finally {
                this.markOutModal.submitting = false;
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

        // ── S-RECORD-REDESIGN: strip + rail derivations ───────────
        // Whole days from `today` to a YYYY-MM-DD date (negative = past).
        // Both parse as UTC midnight, so DST never skews the difference.
        daysFromToday(d) {
            if (!d) return null;
            return Math.round((Date.parse(d.substring(0, 10)) - Date.parse(this.today)) / 86400000);
        },

        // A stored UTC DATETIME as its company-local calendar day (header.php's
        // FF_formatUtc; bare 'Y-m-d H:i:s' strings are UTC — S-UTC-STAMPS).
        utcDay(v) {
            if (!v) return '—';
            return window.FF_formatUtc ? FF_formatUtc(v, { hour: undefined, minute: undefined }) : this.formatDate(String(v).substring(0, 10));
        },

        initials(name) {
            const words = String(name || '').trim().split(/[\s\-&.,]+/)
                .filter(w => w && !['inc','ltd','llc','corp','co','the','and'].includes(w.toLowerCase()));
            if (!words.length) return '?';
            return ((words[0][0] || '') + (words[1] ? words[1][0] : (words[0][1] || ''))).toUpperCase();
        },

        // Rebuilds alerts / strip / linkedLeases from this.res. Called after
        // every successful load (status actions and saves reload in place).
        refreshDerived() {
            const r      = this.res || {};
            const open   = r.status === 'pending' || r.status === 'confirmed';
            const units  = Array.isArray(r.units) ? r.units : [];
            const qty    = parseInt(r.quantity, 10) || 0;
            const days   = this.daysFromToday(r.pickup_date);
            const plural = (n, w) => n + ' ' + w + (n === 1 ? '' : 's');

            // Strip — pickup
            let rel = '—', tone = 'blue';
            if (days !== null) {
                if (r.status === 'completed') { rel = 'picked up'; tone = 'green'; }
                else if (r.status === 'cancelled') { rel = 'cancelled'; tone = 'slate'; }
                else if (days < 0) { rel = plural(-days, 'day') + ' overdue'; tone = 'red'; }
                else if (days === 0) { rel = 'today'; tone = 'amber'; }
                else { rel = 'in ' + plural(days, 'day'); tone = days <= 2 ? 'amber' : 'blue'; }
            }
            // Strip — units attached vs requested
            const short = open && units.length < qty;
            // Strip — status + next step
            const next = {
                pending:   ['confirm to reserve the units', 'amber'],
                confirmed: ['mark out at pickup', 'blue'],
                completed: [r.marked_out_at ? 'marked out ' + this.utcDay(r.marked_out_at) : 'marked out', 'green'],
                cancelled: ['no further action', 'slate'],
            }[r.status] || ['—', 'slate'];
            this.strip = {
                pickupRel:  rel,
                pickupTone: tone,
                unitsValue: units.length + ' / ' + qty,
                unitsDelta: short ? plural(qty - units.length, 'unit') + ' not attached' : (qty === 1 ? 'unit requested' : 'units requested'),
                unitsTone:  short ? 'amber' : (r.status === 'cancelled' ? 'slate' : 'green'),
                statusNext: next[0],
                statusTone: next[1],
            };

            // Rail — linked leases (one row per lease, listing its units)
            const byLease = {};
            for (const u of units) {
                if (!u.lease_id_linked) continue;
                const id = String(u.lease_id_linked);
                const known = this.leaseLabels[id];
                if (!byLease[id]) byLease[id] = { id: id, label: known ? known.number : 'Lease #' + id, status: known ? known.status : '', units: [] };
                byLease[id].units.push(u.unit_number);
            }
            this.linkedLeases = Object.values(byLease).map(l => ({
                id: l.id, label: l.label,
                sub: [l.status, l.units.filter(Boolean).join(', ')].filter(Boolean).join(' · '),
            }));

            // Rail — needs attention
            const a = [];
            if (open && days !== null && days < 0) {
                a.push({ tone: 'danger', text: 'The pickup date passed ' + plural(-days, 'day') + ' ago — mark it out, or cancel / move the date.' });
            } else if (open && days === 0) {
                a.push({ tone: 'warning', text: r.status === 'pending' ? 'Pickup is today and the reservation is still not confirmed.' : 'Pickup is today — mark it out when the unit leaves.' });
            } else if (r.status === 'pending' && days !== null && days <= 3) {
                a.push({ tone: 'warning', text: 'Not confirmed yet — pickup is in ' + plural(days, 'day') + '.' });
            }
            if (short) {
                a.push({ tone: 'warning', text: 'Only ' + units.length + ' of ' + qty + ' requested units ' + (units.length === 1 ? 'is' : 'are') + ' attached — the rest have no unit picked.' });
            }
            if (open) {
                const busy = { on_lease: 'on lease', maintenance: 'in maintenance', inactive: 'inactive', decommissioned: 'decommissioned' };
                for (const u of units) {
                    if (busy[u.current_unit_status]) {
                        a.push({ tone: 'warning', text: 'Unit ' + (u.unit_number || '') + ' is ' + busy[u.current_unit_status] + ' right now — check it will be free for pickup.' });
                    }
                }
                if (this.customerStatus === 'credit_hold' || this.customerStatus === 'suspended') {
                    a.push({ tone: 'warning', text: 'The customer account is on ' + this.customerStatus.replace('_', ' ') + '.' });
                }
                if (!r.contact_phone && !r.contact_email) {
                    a.push({ tone: 'info', text: 'No contact phone or email — add one so the yard can reach the driver.' });
                }
            }
            if (r.status === 'completed' && units.length > 0 && !this.linkedLeases.length) {
                a.push({ tone: 'info', text: 'Marked out without a lease link.' });
            }
            this.alerts = a;
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
     PAGE STYLES (S-RECORD-REDESIGN) — tokens only.
     ============================================================ -->
<style>
/* ── Compact lifecycle stepper: one slim row, dot + label + date ── */
.rs-steps {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 18px !important;
    margin: 0;
}
.rs-step { display: flex; align-items: center; gap: 9px; flex: 0 0 auto; min-width: 0; }
.rs-dot {
    width: 24px;
    height: 24px;
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    border: 2px solid var(--border-color);
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
    transition: background .3s, border-color .3s, box-shadow .3s;
}
.rs-dot svg { width: 12px; height: 12px; }
.rs-step-text { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
.rs-step-label { font-size: 12.5px; font-weight: 650; color: var(--text-muted); white-space: nowrap; }
.rs-step-sub { font-size: 11px; color: var(--text-tertiary); white-space: nowrap; }
.rs-step.is-active .rs-dot {
    background: var(--color-info);
    border-color: var(--color-info);
    color: var(--text-inverse);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--color-info) 18%, transparent);
}
.rs-step.is-active .rs-step-label { color: var(--color-info); }
.rs-step.is-done .rs-dot { background: var(--color-success); border-color: var(--color-success); color: var(--text-inverse); }
.rs-step.is-done .rs-step-label { color: var(--text-primary); }
.rs-line { flex: 1 1 24px; height: 2px; min-width: 16px; border-radius: 2px; background: var(--border-color); transition: background .5s ease; }
.rs-line.is-done { background: var(--color-success); }

/* Cancelled: a one-line banner instead of the stepper. */
.rs-cancelled {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 10px 16px;
    border-radius: var(--radius-xl);
    border: 1px solid color-mix(in srgb, var(--color-danger) 30%, var(--border-color));
    background: color-mix(in srgb, var(--color-danger) 9%, var(--bg-surface));
    color: var(--color-danger);
}
.rs-cancelled-ic {
    width: 24px; height: 24px; flex: 0 0 auto;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 50%;
    background: var(--color-danger);
    color: var(--text-inverse);
}
.rs-cancelled-ic svg { width: 13px; height: 13px; }
.rs-cancelled b { font-size: 13px; letter-spacing: .04em; text-transform: uppercase; }

/* Reservation Details: .form-grid-2 has no global definition (the fields
   stacked one per row); lay them out two-up, full-width rows keep their
   inline grid-column:1/-1. */
.rs-details .form-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 20px;
    row-gap: 4px;
}

@media (max-width: 640px) {
    .rs-step-sub { display: none; }
    .rs-details .form-grid-2 { grid-template-columns: minmax(0, 1fr); }
}
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
