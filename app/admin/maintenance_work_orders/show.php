<?php
declare(strict_types=1);

/**
 * app/admin/maintenance_work_orders/show.php
 *
 * Work order detail page — view, edit, status transitions, line items CRUD.
 *
 * Layout (S-RECORD-REDESIGN):
 *   - Entity hero: WO number + status + priority badges, the job title, and
 *     unit / vendor / work type / requested-date facts. The header holds the
 *     status transitions (Start Work, Waiting Parts, Resume, Complete); Cancel
 *     work order (now behind a confirm), "New work order for this unit" and
 *     Delete sit in the More menu.
 *   - Key-numbers strip: total cost, labour, parts & other, days open (or how
 *     long it took), scheduled date (late / in N days).
 *   - Main column: Complete prompt (resolution notes, shown when Complete is
 *     clicked), Work Order Details (view/edit), Line Items, Activity.
 *   - Right rail: Needs attention (late vs schedule, waiting on parts,
 *     emergency, no vendor / nobody assigned, completed with no costs, unit on
 *     lease, other open work orders on the unit, no vendor bill), Unit (links
 *     equipment/show + the lease it is on), Vendor (links vendors/show),
 *     Related (damage claims raised against this work order, vendor bills —
 *     money roles with Payables access only —, the unit's work orders),
 *     Summary (type, dates, people).
 *
 * Server-renders the hero, strip and rail. Alpine.js (woShow(), opened ABOVE
 * the hero so header buttons reach it) handles:
 *   - Inline edit mode (D19 optimistic lock via updated_at)
 *   - Status transition buttons per state machine
 *   - Line items panel: add / update / delete
 *   - Delete work order modal (soft delete)
 *
 * All FF_Api.post() calls use base_url() — never bare relative strings.
 * FF_Api.post always resolves — every .then() checks d.error.
 * Raw fetch() not used — all calls go through FF_Api.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/maintenance_work_orders/{show,update,update_status,delete}.php
 *           api/v1/maintenance_work_orders/line_items/{add,update,delete}.php
 * @decisions D5/D7/D19/D30/D32
 * @session  S015, S-DROPDOWN-RETROFIT-2B-FINISH-FORMS, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'view');

// ── Resolve work order ────────────────────────────────────────────────────────
$woId = clean_int($_GET['id'] ?? null);
if (!$woId) {
    header('Location: ' . base_url('maintenance_work_orders'));
    exit;
}

$wo = db_row(
    "SELECT
         mwo.*,
         eu.unit_number, eu.year AS unit_year, eu.status AS unit_status,
         eb.label AS brand, et.model, et.category AS unit_category,
         v.name AS vendor_name, v.contact_name AS vendor_contact, v.phone AS vendor_phone,
         uc.name AS created_by_name,
         ua.name AS assigned_to_name,
         ucb.name AS completed_by_name
     FROM maintenance_work_orders mwo
     JOIN equipment_units eu ON eu.id = mwo.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
     LEFT JOIN vendors v ON v.id = mwo.vendor_id AND v.deleted_at IS NULL
     LEFT JOIN users uc ON uc.id = mwo.created_by AND uc.deleted_at IS NULL
     LEFT JOIN users ua ON ua.id = mwo.assigned_to AND ua.deleted_at IS NULL
     LEFT JOIN users ucb ON ucb.id = mwo.completed_by AND ucb.deleted_at IS NULL
     WHERE mwo.id = ? AND mwo.deleted_at IS NULL",
    [$woId]
);

if (!$wo) {
    header('Location: ' . base_url('maintenance_work_orders') . '?error=not_found');
    exit;
}

// Line items
$lineItems = db_select(
    "SELECT id, item_type, description, quantity, unit_cost, total_cost, part_number
     FROM maintenance_line_items WHERE work_order_id = ? ORDER BY id ASC",
    [$woId]
);


// Status machine: valid next states
$transitions = [
    'open'          => ['in_progress', 'cancelled'],
    'in_progress'   => ['waiting_parts', 'completed'],
    'waiting_parts' => ['in_progress'],
    'completed'     => [],
    'cancelled'     => [],
];
$nextStatuses = $transitions[$wo['status']] ?? [];

$isEditable = !in_array($wo['status'], ['completed', 'cancelled']);

// ── S-RECORD-REDESIGN: strip + rail context ──────────────────────────────────
$today    = ff_today();   // company-local business day (never SQL CURDATE())
$isOpenWo = in_array($wo['status'], ['open', 'in_progress', 'waiting_parts'], true);
// Days open: requested → completed (or today while still open). Calendar days.
$woStart   = $wo['requested_date'] ?: ff_utc_to_local((string) $wo['created_at']);  // created_at is UTC
$woEnd     = $wo['status'] === 'completed' ? ($wo['completed_date'] ?: $today) : $today;
$daysOpen  = $woStart ? max(0, (int) round((strtotime($woEnd) - strtotime($woStart)) / 86400)) : null;
// Schedule: how late (negative) / how far away a still-open job is.
$schedDays = ($wo['scheduled_date'] && $isOpenWo)
    ? (int) round((strtotime($wo['scheduled_date']) - strtotime($today)) / 86400) : null;
$isLate    = $schedDays !== null && $schedDays < 0;
// Line-item counts per strip segment (labour vs parts/sublet/other — the same
// split line_items/add.php uses to roll up labor_cost / parts_cost).
$labourLines = count(array_filter($lineItems, static fn ($li) => $li['item_type'] === 'labor'));
$partLines   = count($lineItems) - $labourLines;
// The lease the unit is on right now (maintenance on a rented unit needs the
// customer looped in).
$unitLease = db_row(
    "SELECT l.id, l.contract_number, COALESCE(c.company_name, l.company_name_snapshot) AS customer_name
       FROM leases l
       LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
      WHERE l.equipment_unit_id = ? AND l.status = 'active' AND l.deleted_at IS NULL
      ORDER BY l.start_date DESC LIMIT 1",
    [(int) $wo['equipment_unit_id']]
);
// Other open work orders on the same unit.
$otherOpenWos = (int) (db_row(
    "SELECT COUNT(*) AS n FROM maintenance_work_orders
      WHERE equipment_unit_id = ? AND id <> ? AND deleted_at IS NULL
        AND status IN ('open','in_progress','waiting_parts')",
    [(int) $wo['equipment_unit_id'], $woId]
)['n'] ?? 0);
// Damage claims raised against this work order (damage_claims.work_order_id).
$woClaims = db_select(
    "SELECT id, claim_number, status FROM damage_claims
      WHERE work_order_id = ? AND deleted_at IS NULL ORDER BY id ASC",
    [$woId]
);
// Vendor bills linked to this job (acc_bills.work_order_id) — money, so only
// for financial roles that can open Payables.
$canSeeWoBills = can_view_financials() && can('accounts_payable', 'view');
$woBills = $canSeeWoBills ? db_select(
    "SELECT id, bill_number, status, total_amount, balance_due, currency
       FROM acc_bills WHERE work_order_id = ? AND status <> 'void' ORDER BY id ASC",
    [$woId]
) : [];

// Helper functions used in server-rendered sections (moved above the header
// so the hero can use them — S-RECORD-REDESIGN).
function statusBadgeClass(string $s): string {
    return [
        'open'          => 'badge-info',
        'in_progress'   => 'badge-warning',
        'waiting_parts' => 'badge-warning',
        'completed'     => 'badge-success',
        'cancelled'     => 'badge-neutral',
    ][$s] ?? 'badge-neutral';
}
function statusLabel(string $s): string {
    return [
        'open'          => 'Open',
        'in_progress'   => 'In Progress',
        'waiting_parts' => 'Waiting Parts',
        'completed'     => 'Completed',
        'cancelled'     => 'Cancelled',
    ][$s] ?? $s;
}
function priorityBadgeClass(string $p): string {
    return ['emergency' => 'badge-danger', 'high' => 'badge-warning', 'medium' => 'badge-info', 'low' => 'badge-neutral'][$p] ?? 'badge-neutral';
}

$pageTitle = $wo['work_order_number'];
$helpModuleSlug = 'maintenance';
require_once FF_ROOT . '/includes/header.php';
?>

<?php
// ── Header (S-RECORD-REDESIGN) ────────────────────────────────────────────────
$unitLabel  = trim(($wo['unit_year'] ?? '') . ' ' . ($wo['brand'] ?? '') . ' ' . ($wo['model'] ?? ''));
$heroFacts  = [];
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('truck') . '<a href="' . e(base_url('equipment/show')) . '?id=' . (int) $wo['equipment_unit_id'] . '" style="color:inherit;">Unit <b>' . e($wo['unit_number']) . '</b></a>' . ($unitLabel !== '' ? ' · ' . e($unitLabel) : '');
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('building-storefront') . ($wo['vendor_id'] ? e($wo['vendor_name']) : 'No vendor');
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('wrench-screwdriver') . e(ucwords(str_replace('_', ' ', (string) $wo['work_type'])));
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . 'Requested ' . e(format_date($wo['requested_date']));
?>
<?php ob_start(); /* secondary + destructive actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <?php if (can('maintenance', 'create')): ?>
        <a href="<?= base_url('maintenance_work_orders/create') ?>?unit_id=<?= (int) $wo['equipment_unit_id'] ?>" class="btn btn-secondary btn-sm">New work order for this unit</a>
        <?php endif; ?>
        <?php if (in_array('cancelled', $nextStatuses, true) && can('maintenance', 'edit')): ?>
        <?php /* Cancelled is terminal (no transitions out), so this now asks
                 first — it used to fire on one click from the Transition bar. */ ?>
        <button type="button" class="btn btn-danger btn-sm" :disabled="transitioning"
                @click="FF_Confirm.ask('Cancel this work order? A cancelled work order cannot be reopened.').then(ok => { if (ok) transitionStatus('cancelled'); })">
            Cancel work order
        </button>
        <?php endif; ?>
        <?php if (can('maintenance', 'delete') && in_array($wo['status'], ['open', 'cancelled'])): ?>
        <button type="button" class="btn btn-danger btn-sm"
                onclick="document.getElementById('delete-modal').style.display='flex'">
            Delete
        </button>
        <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?= help_button('maintenance') ?>
        <?php if (!empty($nextStatuses) && can('maintenance', 'edit')): ?>
        <?php foreach ($nextStatuses as $next):
            if ($next === 'cancelled') { continue; } // → More menu
            $btnClass = match($next) {
                'in_progress'   => 'btn-primary',
                'completed'     => 'btn-success',
                'waiting_parts' => 'btn-warning',
                default         => 'btn-secondary',
            };
            $btnLabel = match($next) {
                'in_progress'   => $wo['status'] === 'waiting_parts' ? '▶ Resume Work' : '▶ Start Work',
                'completed'     => '✓ Complete',
                'waiting_parts' => '⏸ Waiting Parts',
                default         => ucwords(str_replace('_', ' ', $next)),
            };
        ?>
        <button type="button" class="btn <?= e($btnClass) ?> btn-sm"
                :disabled="transitioning"
                @click="transitionStatus('<?= e($next) ?>')">
            <?= e($btnLabel) ?>
        </button>
        <?php endforeach; ?>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>

<!-- ── Alpine component — opens ABOVE the hero (S-RECORD-REDESIGN) so the
     header's status buttons call woShow()'s methods. ───────────────────── -->
<div x-data="woShow()">

<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'warning',
    'icon'       => 'wrench-screwdriver',
    'mark'       => (string) $wo['work_order_number'],
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Work Orders', base_url('maintenance_work_orders')], [(string) $wo['work_order_number'], null]],
    'eyebrow'    => 'Work order',
    'title_html' => e($wo['work_order_number'])
        . ' <span class="badge badge-no-dot ' . e(statusBadgeClass($wo['status'])) . '" style="font-size:0.75rem;vertical-align:middle;margin-left:6px;">' . e(statusLabel($wo['status'])) . '</span>'
        . ' <span class="badge badge-no-dot ' . e(priorityBadgeClass($wo['priority'])) . '" style="font-size:0.75rem;vertical-align:middle;" title="Priority">' . e(ucfirst($wo['priority'])) . '</span>',
    'subtitle'   => e($wo['title']),
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<!-- ============================================================
     KEY NUMBERS — the summary strip (S-RECORD-REDESIGN): what the job
     costs so far (and how it splits), how long it has been open, and
     whether it is on schedule. Priority moved to a badge in the title
     (it is not a number). Cost figures keep the page's existing
     visibility (maintenance:view).
     ============================================================ -->
<div class="stat-grid stat-grid--5 ff-stats">
    <a class="stat-card stat-card--blue" href="#wo-line-items" title="All line items">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Total cost</div>
        <div class="stat-value font-mono"><?= e(format_currency($wo['total_cost'])) ?></div>
        <div class="stat-delta"><?= count($lineItems) ?> line item<?= count($lineItems) === 1 ? '' : 's' ?></div>
    </a>

    <div class="stat-card stat-card--amber">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-wrench"/></svg></span>
        <div class="stat-label">Labour</div>
        <div class="stat-value font-mono"><?= e(format_currency($wo['labor_cost'])) ?></div>
        <div class="stat-delta"><?= $labourLines ?> line<?= $labourLines === 1 ? '' : 's' ?></div>
    </div>

    <div class="stat-card stat-card--purple" title="Parts, sublet and other charges">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-tag"/></svg></span>
        <div class="stat-label">Parts &amp; other</div>
        <div class="stat-value font-mono"><?= e(format_currency($wo['parts_cost'])) ?></div>
        <div class="stat-delta"><?= $partLines ?> line<?= $partLines === 1 ? '' : 's' ?></div>
    </div>

    <?php if ($wo['status'] === 'completed'): ?>
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Took</div>
        <div class="stat-value font-mono"><?= $daysOpen !== null ? $daysOpen . ' day' . ($daysOpen === 1 ? '' : 's') : '—' ?></div>
        <div class="stat-delta">done <?= $wo['completed_date'] ? e(format_date($wo['completed_date'])) : '—' ?></div>
    </div>
    <?php elseif ($wo['status'] === 'cancelled'): ?>
    <div class="stat-card stat-card--slate">
        <span class="stat-icon stat-icon--slate"><svg><use href="#icon-x-circle"/></svg></span>
        <div class="stat-label">Days open</div>
        <div class="stat-value">Cancelled</div>
        <div class="stat-delta">requested <?= e(format_date($wo['requested_date'])) ?></div>
    </div>
    <?php else: ?>
    <div class="stat-card <?= $daysOpen !== null && $daysOpen > 14 ? 'stat-card--red' : 'stat-card--teal' ?>">
        <span class="stat-icon <?= $daysOpen !== null && $daysOpen > 14 ? 'stat-icon--red' : 'stat-icon--teal' ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Days open</div>
        <div class="stat-value font-mono"><?= $daysOpen !== null ? number_format($daysOpen) : '—' ?></div>
        <div class="stat-delta">since <?= e(format_date($woStart)) ?></div>
    </div>
    <?php endif; ?>

    <div class="stat-card <?= $isLate ? 'stat-card--red' : 'stat-card--slate' ?>">
        <span class="stat-icon <?= $isLate ? 'stat-icon--red' : 'stat-icon--slate' ?>"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Scheduled</div>
        <div class="stat-value"<?= $isLate ? ' style="color:var(--color-danger);"' : '' ?>><?= $wo['scheduled_date'] ? e(format_date($wo['scheduled_date'])) : 'Not scheduled' ?></div>
        <div class="stat-delta"><?php
            if ($schedDays === null) {
                echo $wo['scheduled_date'] ? e(statusLabel($wo['status'])) : 'no date set';
            } elseif ($schedDays < 0) {
                echo '<span class="text-danger">' . (-$schedDays) . ' day' . ($schedDays === -1 ? '' : 's') . ' late</span>';
            } elseif ($schedDays === 0) {
                echo 'today';
            } else {
                echo 'in ' . $schedDays . ' day' . ($schedDays === 1 ? '' : 's');
            }
        ?></div>
    </div>
</div>

    <!-- Global error banners -->
    <template x-if="staleError">
        <div class="alert alert-danger" style="margin-bottom:16px;">
            This work order was modified by another user. Please reload this page to get the latest version.
        </div>
    </template>
    <template x-if="success">
        <div class="alert alert-success" x-text="success" style="margin-bottom:16px;"></div>
    </template>

<div class="rec-layout">
<div class="rec-main" style="display:flex;flex-direction:column;gap:16px;">

    <?php if (in_array('completed', $nextStatuses, true) && can('maintenance', 'edit')): ?>
    <!-- ── Complete prompt — shown when the header's "Complete" is clicked
         (it used to open inside the old Transition card). ─────────────── -->
    <template x-if="showResolutionNotes">
        <div class="card wo-complete">
            <div class="card-header"><h2 class="card-title">Complete this work order</h2></div>
            <div class="card-body">
                <label class="form-label" for="wo-resolution-notes">Resolution Notes (optional)</label>
                <textarea id="wo-resolution-notes" class="form-control" rows="2"
                          x-model="resolutionNotes"
                          placeholder="Describe what was done…"></textarea>
                <div style="margin-top:8px;display:flex;gap:8px;">
                    <button class="btn btn-success btn-sm"
                            @click="confirmComplete()"
                            :disabled="transitioning">
                        <span x-text="transitioning ? 'Completing…' : 'Confirm Complete'"></span>
                    </button>
                    <button class="btn btn-secondary btn-sm"
                            @click="showResolutionNotes = false; pendingStatus = null;">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </template>
    <?php endif; ?>

    <!-- ── Work Order Details (View / Edit) ───────────────────────────── -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2 class="card-title">Work Order Details</h2>
            <?php if ($isEditable && can('maintenance', 'edit')): ?>
            <button class="btn btn-secondary btn-sm"
                    x-show="!editing"
                    @click="startEdit()">Edit</button>
            <div x-show="editing" style="display:flex;gap:8px;">
                <button class="btn btn-primary btn-sm"
                        @click="saveEdit()"
                        :disabled="saving">
                    <span x-text="saving ? 'Saving…' : 'Save'"></span>
                </button>
                <button class="btn btn-secondary btn-sm" @click="cancelEdit()">Cancel</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-body">

            <!-- View mode (S-RECORD-REDESIGN: hairline rows; who/when moved to
                 the rail's Summary card) -->
            <template x-if="!editing">
                <dl class="rec-dl">
                    <dt>Unit</dt>
                    <dd>
                        <a href="<?= base_url('equipment/show') ?>?id=<?= e($wo['equipment_unit_id']) ?>"
                           class="link font-mono">
                            <?= e($wo['unit_number']) ?>
                        </a>
                        <?php
                        // S-UNIT-STATUS-COLOR 2026-05-14: live equipment_unit.status
                        // badge next to the unit number (eu.status AS unit_status in
                        // the page query). Routes through the shared helper at
                        // includes/functions.php so the color mapping stays in
                        // lockstep with DESIGN_DETAILS.md §9.
                        if (!empty($wo['unit_status'])):
                        ?>
                        <span class="badge badge-no-dot text-xs <?= unit_status_badge_class($wo['unit_status']) ?>"
                              style="margin-left:0.5rem;">
                            <?= e(str_replace('_', ' ', $wo['unit_status'])) ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($unitLabel !== ''): ?>
                        <span class="text-secondary"> — <?= e($unitLabel) ?></span>
                        <?php endif; ?>
                    </dd>

                    <dt>Title</dt>
                    <dd><?= e($wo['title']) ?></dd>

                    <dt>Work Type</dt>
                    <dd><?= e(ucwords(str_replace('_', ' ', $wo['work_type']))) ?></dd>

                    <dt>Priority</dt>
                    <dd><span class="badge badge-no-dot <?= e(priorityBadgeClass($wo['priority'])) ?>"><?= e(ucfirst($wo['priority'])) ?></span></dd>

                    <dt>Vendor</dt>
                    <dd>
                        <?php if ($wo['vendor_id']): ?>
                        <a href="<?= base_url('vendors/show') ?>?id=<?= e($wo['vendor_id']) ?>" class="link">
                            <?= e($wo['vendor_name']) ?>
                        </a>
                        <?php if ($wo['vendor_contact']): ?>
                        <span class="text-secondary"> — <?= e($wo['vendor_contact']) ?></span>
                        <?php endif; ?>
                        <?php else: ?>
                        —
                        <?php endif; ?>
                    </dd>

                    <dt>Requested Date</dt>
                    <dd><?= $wo['requested_date'] ? e(format_date($wo['requested_date'])) : '—' ?></dd>

                    <dt>Scheduled Date</dt>
                    <dd><?= $wo['scheduled_date'] ? e(format_date($wo['scheduled_date'])) : '—' ?></dd>

                    <?php if ($wo['completed_date']): ?>
                    <dt>Completed Date</dt>
                    <dd><?= e(format_date($wo['completed_date'])) ?></dd>
                    <?php endif; ?>

                    <dt>Odometer</dt>
                    <dd><?= $wo['mileage_at_service'] ? e(number_format((int)$wo['mileage_at_service'])) . ' km' : '—' ?></dd>

                    <dt>Assigned To</dt>
                    <dd><?= e($wo['assigned_to_name'] ?? '—') ?></dd>

                    <?php if ($wo['description']): ?>
                    <dt>Description</dt>
                    <dd style="white-space:pre-wrap;"><?= e($wo['description']) ?></dd>
                    <?php endif; ?>

                    <?php if ($wo['notes']): ?>
                    <dt>Notes</dt>
                    <dd style="white-space:pre-wrap;"><?= e($wo['notes']) ?></dd>
                    <?php endif; ?>

                    <?php if ($wo['internal_notes']): ?>
                    <dt>Internal Notes</dt>
                    <dd style="white-space:pre-wrap;"><?= e($wo['internal_notes']) ?></dd>
                    <?php endif; ?>

                    <?php if ($wo['resolution_notes']): ?>
                    <dt>Resolution Notes</dt>
                    <dd style="white-space:pre-wrap;"><?= e($wo['resolution_notes']) ?></dd>
                    <?php endif; ?>
                </dl>
            </template>
            <!-- Edit mode -->
            <template x-if="editing">
                <form id="wo-edit-form" @submit.prevent="saveEdit()" novalidate>
                    <div class="form-error-banner" data-form-error></div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label" for="edit_work_type">Work Type</label>
                            <select id="edit_work_type" name="work_type" class="form-select" x-model="editForm.work_type">
                                <option value="scheduled_service">Scheduled Service</option>
                                <option value="repair">Repair</option>
                                <option value="inspection">Inspection</option>
                                <option value="tire">Tire</option>
                                <option value="electrical">Electrical</option>
                                <option value="body_damage">Body Damage</option>
                                <option value="breakdown">Breakdown</option>
                                <option value="other">Other</option>
                            </select>
                            <div class="field-error" data-error-for="work_type"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="edit_priority">Priority</label>
                            <select id="edit_priority" name="priority" class="form-select" x-model="editForm.priority">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="emergency">Emergency</option>
                            </select>
                            <div class="field-error" data-error-for="priority"></div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label" for="edit_title">Title</label>
                        <input type="text" id="edit_title" name="title" class="form-control" x-model="editForm.title" maxlength="500">
                        <div class="field-error" data-error-for="title"></div>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label" for="edit_vendor_id">Vendor</label>
                        <?php
                        $pickerConfig   = [
                            'endpoint'    => '/api/v1/vendors/index.php',
                            'searchParam' => 'q',
                            'resultKey'   => 'items',
                            'perPage'     => 10,
                            'placeholder' => 'Search vendors…',
                            'mapResult'   => "r => ({ id: r.id, label: r.name, sublabel: r.vendor_type || '', raw: r })",
                        ];
                        if ($wo['vendor_id'] && $wo['vendor_name']) {
                            $pickerConfig['initialId']    = (int) $wo['vendor_id'];
                            $pickerConfig['initialLabel'] = $wo['vendor_name'];
                        }
                        $pickerOnPicked  = 'editForm.vendor_id = $event.detail.id';
                        $pickerOnCleared = "editForm.vendor_id = ''";
                        $pickerError     = 'false';
                        require FF_ROOT . '/includes/partials/record-picker.php';
                        ?>
                        <div class="field-error" data-error-for="vendor_id"></div>
                    </div>

                    <div class="form-row-2" style="margin-top:12px;">
                        <div class="form-group">
                            <label class="form-label" for="edit_requested_date">Requested Date</label>
                            <input type="date" id="edit_requested_date" name="requested_date" class="form-control" x-model="editForm.requested_date">
                            <div class="field-error" data-error-for="requested_date"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="edit_scheduled_date">Scheduled Date</label>
                            <input type="date" id="edit_scheduled_date" name="scheduled_date" class="form-control" x-model="editForm.scheduled_date">
                            <div class="field-error" data-error-for="scheduled_date"></div>
                        </div>
                    </div>

                    <div class="form-row-2" style="margin-top:12px;">
                        <div class="form-group">
                            <label class="form-label" for="edit_mileage_at_service">Odometer (km)</label>
                            <input type="number" min="0" id="edit_mileage_at_service" name="mileage_at_service" class="form-control" x-model="editForm.mileage_at_service">
                            <div class="field-error" data-error-for="mileage_at_service"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="edit_assigned_to">Assigned To</label>
                            <?php
                            // D-PICKER-USER-VARIANT: user picker — no role/status filter,
                            // matching the original dropdown which showed all non-deleted users.
                            $pickerConfig   = [
                                'endpoint'    => '/api/v1/users/index.php',
                                'searchParam' => 'q',
                                'resultKey'   => 'items',
                                'perPage'     => 10,
                                'placeholder' => 'Search users…',
                                'mapResult'   => "r => ({ id: r.id, label: r.name, sublabel: r.role_name || '', raw: r })",
                            ];
                            if ($wo['assigned_to'] && $wo['assigned_to_name']) {
                                $pickerConfig['initialId']    = (int) $wo['assigned_to'];
                                $pickerConfig['initialLabel'] = $wo['assigned_to_name'];
                            }
                            $pickerOnPicked  = 'editForm.assigned_to = $event.detail.id';
                            $pickerOnCleared = "editForm.assigned_to = ''";
                            $pickerError     = 'false';
                            require FF_ROOT . '/includes/partials/record-picker.php';
                            ?>
                            <div class="field-error" data-error-for="assigned_to"></div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label" for="edit_description">Description</label>
                        <textarea id="edit_description" name="description" class="form-control" rows="3" x-model="editForm.description"></textarea>
                        <div class="field-error" data-error-for="description"></div>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label" for="edit_notes">Notes (visible to vendor)</label>
                        <textarea id="edit_notes" name="notes" class="form-control" rows="2" x-model="editForm.notes"></textarea>
                        <div class="field-error" data-error-for="notes"></div>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label" for="edit_internal_notes">Internal Notes</label>
                        <textarea id="edit_internal_notes" name="internal_notes" class="form-control" rows="2" x-model="editForm.internal_notes"></textarea>
                        <div class="field-error" data-error-for="internal_notes"></div>
                    </div>
                </form>
            </template>

        </div><!-- /card-body -->
    </div><!-- /card -->

    <!-- ── Line Items ─────────────────────────────────────────────────── -->
    <div class="card" id="wo-line-items">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2 class="card-title">Line Items</h2>
            <?php if ($isEditable && can('maintenance', 'edit')): ?>
            <button class="btn btn-secondary btn-sm" @click="showAddItem = !showAddItem">
                + Add Item
            </button>
            <?php endif; ?>
        </div>

        <div class="card-body">

            <!-- Add item form -->
            <?php if ($isEditable && can('maintenance', 'edit')): ?>
            <template x-if="showAddItem">
                <div class="card" style="background:var(--bg-muted);margin-bottom:16px;">
                    <div class="card-body">
                        <h4 style="margin:0 0 12px;font-size:0.875rem;">Add Line Item</h4>
                        <form id="wo-add-item-form" @submit.prevent="addItem()" novalidate>
                            <div class="form-error-banner" data-form-error></div>

                            <div class="form-row-2">
                                <div class="form-group">
                                    <label class="form-label" for="add_item_type">Type</label>
                                    <select id="add_item_type" name="item_type" class="form-select" x-model="newItem.item_type">
                                        <option value="labor">Labour</option>
                                        <option value="part">Part</option>
                                        <option value="sublet">Sublet</option>
                                        <option value="other">Other</option>
                                    </select>
                                    <div class="field-error" data-error-for="item_type"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="add_part_number">Part Number</label>
                                    <input type="text" id="add_part_number" name="part_number" class="form-control" x-model="newItem.part_number" placeholder="Optional">
                                    <div class="field-error" data-error-for="part_number"></div>
                                </div>
                            </div>
                            <div class="form-group" style="margin-top:10px;">
                                <label class="form-label" for="add_description">Description <span class="text-danger">*</span></label>
                                <input type="text" id="add_description" name="description" class="form-control" x-model="newItem.description" placeholder="Item description…">
                                <div class="field-error" data-error-for="description"></div>
                            </div>
                            <div class="form-row-3" style="margin-top:10px;">
                                <div class="form-group">
                                    <label class="form-label" for="add_quantity">Quantity <span class="text-danger">*</span></label>
                                    <input type="number" min="0" id="add_quantity" name="quantity" class="form-control" x-model="newItem.quantity" step="0.01">
                                    <div class="field-error" data-error-for="quantity"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="add_unit_cost">Unit Cost <span class="text-danger">*</span></label>
                                    <input type="number" min="0" id="add_unit_cost" name="unit_cost" class="form-control" x-model="newItem.unit_cost" step="0.01">
                                    <div class="field-error" data-error-for="unit_cost"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Line Total</label>
                                    <input type="text" class="form-control" readonly
                                           :value="'$' + (parseFloat(newItem.quantity||0) * parseFloat(newItem.unit_cost||0)).toFixed(2)">
                                </div>
                            </div>
                            <div style="margin-top:10px;display:flex;gap:8px;">
                                <button type="submit" class="btn btn-primary btn-sm" :disabled="addingItem">
                                    <span x-text="addingItem ? 'Adding…' : 'Add Item'"></span>
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="showAddItem = false">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </template>
            <?php endif; ?>

            <!-- Line items table -->
            <template x-if="lineItems.length === 0">
                <p class="text-secondary" style="font-size:0.875rem;">No line items yet.
                    <?php if ($isEditable && can('maintenance', 'edit')): ?>
                    Click "+ Add Item" to add parts, labour, and other charges.
                    <?php endif; ?>
                </p>
            </template>

            <template x-if="lineItems.length > 0">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Part #</th>
                                <th style="text-align:right;">Qty</th>
                                <th style="text-align:right;">Unit Cost</th>
                                <th style="text-align:right;">Total</th>
                                <?php if ($isEditable && can('maintenance', 'edit')): ?>
                                <th></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, idx) in lineItems" :key="item.id">
                                <tr>
                                    <td>
                                        <span class="badge" :class="itemTypeBadge(item.item_type)"
                                              x-text="itemTypeLabel(item.item_type)"></span>
                                    </td>
                                    <td x-text="item.description"></td>
                                    <td x-text="item.part_number || '—'"></td>
                                    <td class="font-mono" style="text-align:right;" x-text="parseFloat(item.quantity).toFixed(2)"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="'$' + parseFloat(item.unit_cost).toFixed(2)"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="'$' + parseFloat(item.total_cost).toLocaleString('en-CA', {minimumFractionDigits:2})"></td>
                                    <?php if ($isEditable && can('maintenance', 'edit')): ?>
                                    <td>
                                        <button class="btn btn-danger btn-sm"
                                                @click="deleteItem(item.id)"
                                                style="padding:2px 8px;font-size:0.75rem;">
                                            ✕
                                        </button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:600;border-top:2px solid var(--border-strong);">
                                <td colspan="<?= $isEditable && can('maintenance', 'edit') ? 5 : 4 ?>">Total</td>
                                <td class="font-mono" style="text-align:right;"
                                    x-text="'$' + lineItems.reduce((s,i) => s + parseFloat(i.total_cost), 0).toLocaleString('en-CA', {minimumFractionDigits:2})">
                                </td>
                                <?php if ($isEditable && can('maintenance', 'edit')): ?><td></td><?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </template>

        </div>
    </div><!-- /line items card -->

    <!-- ── Activity Log ───────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h2 class="card-title">Activity</h2></div>
        <div class="card-body">
            <?php $activityEntityType = 'work_order'; $activityEntityId = $woId; ?>
            <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
        </div>
    </div>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the work order at a glance ──────────────────
$R = \FleetForge\Ui\RecordUi::class;
$railW = [];

// 1. Needs attention.
$alertsW = [];
if ($isLate) {
    $alertsW[] = ['danger', 'Scheduled for ' . e(format_date($wo['scheduled_date'])) . ' — ' . (-$schedDays) . ' day' . ($schedDays === -1 ? '' : 's') . ' late.'];
}
if ($wo['status'] === 'waiting_parts') {
    $alertsW[] = ['warning', 'Waiting on parts — <b>Resume Work</b> when they arrive.'];
}
if ($isOpenWo && $wo['priority'] === 'emergency') {
    $alertsW[] = ['danger', 'Emergency priority — the unit may be off the road.'];
}
if ($isOpenWo && !$wo['vendor_id']) {
    $alertsW[] = ['info', 'No vendor assigned — set one if the work goes to an outside shop.'];
}
if ($isOpenWo && !$wo['assigned_to']) {
    $alertsW[] = ['info', 'Nobody is assigned to this work order.'];
}
if ($wo['status'] === 'completed' && count($lineItems) === 0) {
    $alertsW[] = ['warning', 'Completed with no line items — the job shows no cost.'];
}
if ($isOpenWo && $unitLease) {
    $alertsW[] = ['info', 'The unit is on lease <a href="' . e(base_url('leases/show')) . '?id=' . (int) $unitLease['id'] . '">' . e($unitLease['contract_number']) . '</a>' . ($unitLease['customer_name'] ? ' (' . e($unitLease['customer_name']) . ')' : '') . ' — coordinate the repair with the customer.'];
}
if ($otherOpenWos > 0) {
    $alertsW[] = ['info', '<a href="' . e(base_url('maintenance_work_orders')) . '?equipment_unit_id=' . (int) $wo['equipment_unit_id'] . '">' . $otherOpenWos . ' other open work order' . ($otherOpenWos === 1 ? '' : 's') . '</a> on this unit.'];
}
if ($canSeeWoBills && $wo['status'] === 'completed' && $wo['vendor_id'] && !$woBills && bccomp((string) $wo['total_cost'], '0', 2) > 0) {
    $alertsW[] = ['info', 'No vendor bill is linked to this job yet (Payables → Bills).'];
}
$railW[] = $R::card('Needs attention', $R::alerts($alertsW, $wo['status'] === 'completed' ? 'Done — nothing needs attention.' : 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// 2. Unit.
$unitBody = $R::entity(
    'Unit ' . (string) $wo['unit_number'],
    base_url('equipment/show') . '?id=' . (int) $wo['equipment_unit_id'],
    e($unitLabel !== '' ? $unitLabel : ucwords(str_replace('_', ' ', (string) ($wo['unit_category'] ?? ''))))
        . (!empty($wo['unit_status']) ? ' · ' . e(str_replace('_', ' ', (string) $wo['unit_status'])) : ''),
    '',
    'truck'
);
$unitKv = $R::kv([
    ['Odometer', $wo['mileage_at_service'] ? e(number_format((int) $wo['mileage_at_service'])) . ' km' : null, 'mono'],
    ['On lease', $unitLease ? '<a href="' . e(base_url('leases/show')) . '?id=' . (int) $unitLease['id'] . '">' . e($unitLease['contract_number']) . '</a>' : 'No', ''],
    ['Customer', $unitLease && $unitLease['customer_name'] ? e($unitLease['customer_name']) : null],
]);
$railW[] = $R::card('Unit', $unitBody . ($unitKv !== '' ? '<div style="margin-top:10px;">' . $unitKv . '</div>' : ''),
    ['icon' => 'truck', 'link' => ['History', base_url('equipment/show') . '?id=' . (int) $wo['equipment_unit_id'] . '#maintenance']]);

// 3. Vendor.
if ($wo['vendor_id']) {
    $vendBody = $R::entity(
        (string) $wo['vendor_name'],
        base_url('vendors/show') . '?id=' . (int) $wo['vendor_id'],
        $wo['vendor_contact'] ? e($wo['vendor_contact']) : 'Vendor',
        \FleetForge\Ui\ModuleHero::initials((string) $wo['vendor_name'])
    );
    $vendKv = $R::kv([
        ['Phone', $wo['vendor_phone'] ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', (string) $wo['vendor_phone'])) . '">' . e($wo['vendor_phone']) . '</a>' : null],
    ]);
    $railW[] = $R::card('Vendor', $vendBody . ($vendKv !== '' ? '<div style="margin-top:10px;">' . $vendKv . '</div>' : ''), ['icon' => 'building-storefront']);
} else {
    $railW[] = $R::card('Vendor', '<p class="text-secondary" style="margin:0;font-size:12.5px;">No vendor — in-house work' . ($isEditable && can('maintenance', 'edit') ? ' (Edit to set one)' : '') . '.</p>', ['icon' => 'building-storefront']);
}

// 4. Related records.
$relW = [];
foreach ($woClaims as $cl) {
    $relW[] = ['Damage claim ' . $cl['claim_number'], base_url('damage_claims/show') . '?id=' . (int) $cl['id'], 'exclamation-triangle', ucwords(str_replace('_', ' ', (string) $cl['status']))];
}
foreach ($woBills as $b) {
    $relW[] = ['Bill ' . $b['bill_number'], base_url('accounting/bills/show') . '?id=' . (int) $b['id'], 'receipt-percent',
        str_replace('_', ' ', (string) $b['status']) . ' · ' . format_currency($b['total_amount'], $b['currency'] === 'USD' ? 'US$' : '$')];
}
$relW[] = ['Work orders for this unit', base_url('maintenance_work_orders') . '?equipment_unit_id=' . (int) $wo['equipment_unit_id'], 'clipboard-document-list', $otherOpenWos > 0 ? $otherOpenWos . ' other open' : ''];
if (can('maintenance', 'create') && in_array($wo['work_type'], ['repair', 'body_damage', 'breakdown'], true) && !$woClaims) {
    $relW[] = ['Report damage for this unit', base_url('damage_claims/create') . '?unit_id=' . (int) $wo['equipment_unit_id'] . ($unitLease ? '&lease_id=' . (int) $unitLease['id'] : ''), 'plus'];
}
$railW[] = $R::card('Related', $R::links($relW), ['icon' => 'document-duplicate']);

// 5. Summary — type, dates and people (the old detail rows' who/when).
$railW[] = $R::card('Summary', $R::kv([
    ['Requested', $wo['requested_date'] ? e(format_date($wo['requested_date'])) : null],
    ['Scheduled', $wo['scheduled_date'] ? e(format_date($wo['scheduled_date'])) : 'Not scheduled'],
    ['Completed', $wo['completed_date'] ? e(format_date($wo['completed_date'])) . ($wo['completed_by_name'] ? ' · ' . e($wo['completed_by_name']) : '') : null],
    ['Assigned to', $wo['assigned_to_name'] ? e($wo['assigned_to_name']) : 'Nobody'],
    ['Created', e(format_datetime($wo['created_at'])) . ' · ' . e($wo['created_by_name'] ?? 'System')],
]), ['icon' => 'document-text']);
?>
<aside class="rec-rail" aria-label="Work order at a glance">
    <?= implode("\n    ", $railW) ?>
</aside>
</div><!-- /rec-layout -->

</div><!-- /Alpine component -->

<!-- ── Delete modal ──────────────────────────────────────────────────────── -->
<?php if (can('maintenance', 'delete') && in_array($wo['status'], ['open', 'cancelled'])): ?>
<div id="delete-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div class="card" style="width:400px;max-width:90vw;">
        <div class="card-header">
            <h3 style="margin:0;">Delete Work Order?</h3>
        </div>
        <div class="card-body">
            <p>Are you sure you want to delete <strong><?= e($wo['work_order_number']) ?></strong>?
               This cannot be undone.</p>
        </div>
        <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn btn-secondary btn-sm"
                    onclick="document.getElementById('delete-modal').style.display='none'">
                Cancel
            </button>
            <button class="btn btn-danger btn-sm" onclick="confirmDelete()">
                Delete Work Order
            </button>
        </div>
    </div>
</div>

<script>
function confirmDelete() {
    FF_Api.post('<?= base_url('api/v1/maintenance_work_orders/delete.php') ?>', { id: <?= (int)$wo['id'] ?> })
        .then(r => {
            if (!r.success) {
                FF_Toast.error((r.error && r.error.message) || 'Delete failed.');
                document.getElementById('delete-modal').style.display = 'none';
                return;
            }
            window.location.href = '<?= base_url('maintenance_work_orders') ?>';
        })
        .catch(() => { FF_Toast.error('Network error. Please try again.'); });
}
</script>
<?php endif; ?>

<script>
function woShow() {
    return {
        // Edit state
        editing:      false,
        saving:       false,
        editForm:     {},
        updatedAt:    '<?= addslashes($wo['updated_at']) ?>',
        staleError:   false,

        // Status transition state
        transitioning:       false,
        pendingStatus:       null,
        showResolutionNotes: false,
        resolutionNotes:     '',

        // Line items
        lineItems:  <?= json_encode(array_values($lineItems)) ?>,
        showAddItem: false,
        addingItem:  false,
        newItem: {
            item_type:   'labor',
            description: '',
            quantity:    '1',
            unit_cost:   '0.00',
            part_number: '',
        },

        // Feedback
        success: null,

        init() {},

        // ── Edit ──────────────────────────────────────────────────────────
        startEdit() {
            this.editForm = {
                id:               <?= (int)$wo['id'] ?>,
                title:            '<?= addslashes(e($wo['title'])) ?>',
                work_type:        '<?= e($wo['work_type']) ?>',
                priority:         '<?= e($wo['priority']) ?>',
                vendor_id:        '<?= e((string)($wo['vendor_id'] ?? '')) ?>',
                requested_date:   '<?= e($wo['requested_date'] ?? '') ?>',
                scheduled_date:   '<?= e($wo['scheduled_date'] ?? '') ?>',
                mileage_at_service: '<?= e((string)($wo['mileage_at_service'] ?? '')) ?>',
                assigned_to:      '<?= e((string)($wo['assigned_to'] ?? '')) ?>',
                description:      '<?= addslashes(e($wo['description'] ?? '')) ?>',
                notes:            '<?= addslashes(e($wo['notes'] ?? '')) ?>',
                internal_notes:   '<?= addslashes(e($wo['internal_notes'] ?? '')) ?>',
                updated_at:       this.updatedAt,
            };
            this.editing    = true;
            this.staleError = false;
            this.success    = null;
        },

        cancelEdit() {
            this.editing    = false;
            const form = document.getElementById('wo-edit-form');
            if (form) FF_Validate.clear(form);
        },

        validateEdit(form) {
            FF_Validate.clear(form);
            let ok = true;

            if (!this.editForm.title || !this.editForm.title.trim()) {
                FF_Validate.field(form, 'title', 'Title is required.');
                ok = false;
            }
            if (!this.editForm.work_type) {
                FF_Validate.field(form, 'work_type', 'Please select a work type.');
                ok = false;
            }
            if (!this.editForm.priority) {
                FF_Validate.field(form, 'priority', 'Please select a priority.');
                ok = false;
            }
            if (this.editForm.mileage_at_service !== '' && this.editForm.mileage_at_service !== null) {
                const mi = parseInt(this.editForm.mileage_at_service);
                if (isNaN(mi) || mi < 0) {
                    FF_Validate.field(form, 'mileage_at_service', 'Odometer cannot be negative.');
                    ok = false;
                }
            }
            if (this.editForm.scheduled_date && this.editForm.requested_date &&
                this.editForm.scheduled_date < this.editForm.requested_date) {
                FF_Validate.field(form, 'scheduled_date', 'Scheduled date cannot be before requested date.');
                ok = false;
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        saveEdit() {
            const form = document.getElementById('wo-edit-form');
            if (!form) return;
            if (!this.validateEdit(form)) return;

            this.saving = true;
            const payload = {
                id:                parseInt(this.editForm.id),
                updated_at:        this.updatedAt,
                title:             this.editForm.title,
                work_type:         this.editForm.work_type,
                priority:          this.editForm.priority,
                vendor_id:         this.editForm.vendor_id ? parseInt(this.editForm.vendor_id) : null,
                requested_date:    this.editForm.requested_date || null,
                scheduled_date:    this.editForm.scheduled_date || null,
                mileage_at_service: this.editForm.mileage_at_service ? parseInt(this.editForm.mileage_at_service) : null,
                assigned_to:       this.editForm.assigned_to ? parseInt(this.editForm.assigned_to) : null,
                description:       this.editForm.description || null,
                notes:             this.editForm.notes || null,
                internal_notes:    this.editForm.internal_notes || null,
            };
            FF_Api.post('<?= base_url('api/v1/maintenance_work_orders/update.php') ?>', payload)
                .then(r => {
                    if (!r.success) {
                        if (r.error && r.error.code === 'STALE_DATA') {
                            this.staleError = true;
                            this.editing    = false;
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        } else if (r.error && r.error.code === 'VALIDATION_ERROR') {
                            FF_Validate.applyApi(form, r.error);
                        } else {
                            FF_Validate.banner(form, (r.error && r.error.message) || 'Save failed.');
                        }
                        return;
                    }
                    this.updatedAt = r.data.updated_at;
                    this.editing   = false;
                    this.success   = 'Work order updated.';
                    setTimeout(() => { this.success = null; }, 3000);
                })
                .catch(() => {
                    FF_Validate.banner(form, 'Network error. Please try again.');
                })
                .finally(() => { this.saving = false; });
        },

        // ── Status transitions ─────────────────────────────────────────────
        transitionStatus(newStatus) {
            // For 'completed', show resolution notes prompt first
            if (newStatus === 'completed') {
                this.pendingStatus       = 'completed';
                this.showResolutionNotes = true;
                // The prompt renders at the top of the main column (the button
                // lives in the header — S-RECORD-REDESIGN): bring it into view.
                this.$nextTick(() => {
                    const ta = document.getElementById('wo-resolution-notes');
                    if (ta) { ta.scrollIntoView({ block: 'center' }); ta.focus({ preventScroll: true }); }
                });
                return;
            }
            this._doTransition(newStatus, null, null);
        },

        confirmComplete() {
            this._doTransition('completed', null, this.resolutionNotes || null);
        },

        _doTransition(newStatus, reason, resolutionNotes) {
            this.transitioning = true;
            const payload = {
                id:               <?= (int)$wo['id'] ?>,
                new_status:       newStatus,
                reason:           reason,
                resolution_notes: resolutionNotes,
            };
            FF_Api.post('<?= base_url('api/v1/maintenance_work_orders/update_status.php') ?>', payload)
                .then(r => {
                    if (!r.success) {
                        FF_Toast.error((r.error && r.error.message) || 'Transition failed.');
                        return;
                    }
                    window.location.reload();
                })
                .catch(() => { FF_Toast.error('Network error. Please try again.'); })
                .finally(() => { this.transitioning = false; });
        },

        // ── Line items ─────────────────────────────────────────────────────
        validateNewItem(form) {
            FF_Validate.clear(form);
            let ok = true;

            if (!this.newItem.description || !this.newItem.description.trim()) {
                FF_Validate.field(form, 'description', 'Description is required.');
                ok = false;
            }
            const q = parseFloat(this.newItem.quantity);
            if (isNaN(q) || q <= 0) {
                FF_Validate.field(form, 'quantity', 'Quantity must be greater than zero.');
                ok = false;
            }
            const uc = parseFloat(this.newItem.unit_cost);
            if (isNaN(uc) || uc < 0) {
                FF_Validate.field(form, 'unit_cost', 'Unit cost cannot be negative.');
                ok = false;
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        addItem() {
            const form = document.getElementById('wo-add-item-form');
            if (!form) return;
            if (!this.validateNewItem(form)) return;

            this.addingItem = true;
            const payload = {
                work_order_id: <?= (int)$wo['id'] ?>,
                item_type:     this.newItem.item_type,
                description:   this.newItem.description.trim(),
                quantity:      this.newItem.quantity,
                unit_cost:     this.newItem.unit_cost,
                part_number:   this.newItem.part_number || null,
            };
            FF_Api.post('<?= base_url('api/v1/maintenance_work_orders/line_items/add.php') ?>', payload)
                .then(r => {
                    if (!r.success) {
                        if (r.error && r.error.code === 'VALIDATION_ERROR') {
                            FF_Validate.applyApi(form, r.error);
                        } else {
                            FF_Validate.banner(form, (r.error && r.error.message) || 'Failed to add item.');
                        }
                        return;
                    }
                    window.location.reload();
                })
                .catch(() => {
                    FF_Validate.banner(form, 'Network error. Please try again.');
                })
                .finally(() => { this.addingItem = false; });
        },

        // [UI-AUDIT-1:M13] async for FF_Confirm.ask().
        async deleteItem(lineItemId) {
            if (!(await FF_Confirm.ask('Delete this line item?'))) return;
            FF_Api.post('<?= base_url('api/v1/maintenance_work_orders/line_items/delete.php') ?>', { id: lineItemId })
                .then(r => {
                    if (!r.success) {
                        FF_Toast.error((r.error && r.error.message) || 'Delete failed.');
                        return;
                    }
                    window.location.reload();
                })
                .catch(() => { FF_Toast.error('Network error. Please try again.'); });
        },

        // ── Badge helpers ──────────────────────────────────────────────────
        itemTypeBadge(t) {
            return { labor: 'badge badge-warning', part: 'badge badge-info', sublet: 'badge badge-neutral', other: 'badge badge-neutral' }[t] ?? 'badge badge-neutral';
        },
        itemTypeLabel(t) {
            return { labor: 'Labour', part: 'Part', sublet: 'Sublet', other: 'Other' }[t] ?? t;
        },
    };
}
</script>

<!-- ── Page styles (S-RECORD-REDESIGN) — tokens only ─────────────────────── -->
<style>
/* The Complete prompt opens at the top of the main column when the
   header's "Complete" is clicked — tint it so it reads as the next step. */
.rec-main .card.wo-complete {
    border-color: color-mix(in srgb, var(--color-success) 45%, var(--border-color));
    background:
        radial-gradient(360px 120px at 0% 0%, color-mix(in srgb, var(--color-success) 10%, transparent), transparent 70%),
        var(--bg-surface);
}
/* Hero fact chip links (unit) inherit the chip colour; underline on hover. */
.ff-hero-fact a { text-decoration: none; }
.ff-hero-fact a:hover { text-decoration: underline; }
</style>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
