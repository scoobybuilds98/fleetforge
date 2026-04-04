<?php
declare(strict_types=1);

/**
 * app/admin/maintenance_work_orders/show.php
 *
 * Work order detail page — view, edit, status transitions, line items CRUD.
 *
 * Server-renders hero section (WO#, status badge, unit, vendor).
 * Alpine.js handles:
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
 * @session  S015
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
         et.brand, et.model, et.category AS unit_category,
         v.name AS vendor_name, v.contact_name AS vendor_contact, v.phone AS vendor_phone,
         uc.name AS created_by_name,
         ua.name AS assigned_to_name,
         ucb.name AS completed_by_name
     FROM maintenance_work_orders mwo
     JOIN equipment_units eu ON eu.id = mwo.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
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

// Vendors for edit dropdown
$vendors = db_select("SELECT id, name FROM vendors WHERE deleted_at IS NULL ORDER BY name ASC");

// Users for assigned_to dropdown
$assignableUsers = db_select("SELECT id, name FROM users WHERE deleted_at IS NULL ORDER BY name ASC");

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

$pageTitle = $wo['work_order_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<!-- Hero header ──────────────────────────────────────────────────────────── -->
<div class="page-header" style="align-items:flex-start;gap:16px;">
    <div>
        <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:4px;">
            <a href="<?= base_url('maintenance_work_orders') ?>" class="text-secondary">Work Orders</a>
            &rsaquo; <?= e($wo['work_order_number']) ?>
        </div>
        <h1 class="page-header-title" style="margin:0;">
            <?= e($wo['work_order_number']) ?>
            <span class="badge <?= e(statusBadgeClass($wo['status'])) ?>"
                  style="font-size:0.75rem;vertical-align:middle;margin-left:8px;">
                <?= e(statusLabel($wo['status'])) ?>
            </span>
        </h1>
        <div class="text-secondary" style="margin-top:4px;">
            <?= e($wo['title']) ?>
        </div>
    </div>

    <?php if (can('maintenance', 'delete') && in_array($wo['status'], ['open', 'cancelled'])): ?>
    <div style="margin-left:auto;">
        <button class="btn btn-danger btn-sm"
                onclick="document.getElementById('delete-modal').style.display='flex'">
            Delete
        </button>
    </div>
    <?php endif; ?>
</div>

<?php
// Helper functions used in server-rendered sections
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
?>

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-label">Priority</div>
        <div class="stat-value">
            <span class="badge <?= e(priorityBadgeClass($wo['priority'])) ?>">
                <?= e(ucfirst($wo['priority'])) ?>
            </span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Labour Cost</div>
        <div class="stat-value font-mono"><?= format_currency($wo['labor_cost']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Parts Cost</div>
        <div class="stat-value font-mono"><?= format_currency($wo['parts_cost']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Cost</div>
        <div class="stat-value font-mono"><?= format_currency($wo['total_cost']) ?></div>
    </div>

</div>

<!-- ── Main content: detail + line items ─────────────────────────────────── -->
<div x-data="woShow()" x-init="init()">

    <!-- Global error banner -->
    <template x-if="error">
        <div class="alert alert-danger" x-text="error" style="margin-bottom:16px;"></div>
    </template>
    <template x-if="success">
        <div class="alert alert-success" x-text="success" style="margin-bottom:16px;"></div>
    </template>

    <!-- ── Status Transitions ──────────────────────────────────────────── -->
    <?php if (!empty($nextStatuses) && can('maintenance', 'edit')): ?>
    <div class="card" style="margin-bottom:16px;">
        <div class="card-body" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <span class="text-secondary" style="font-size:0.875rem;">Transition:</span>

            <?php foreach ($nextStatuses as $next): ?>
            <?php
            $btnClass = match($next) {
                'in_progress'   => 'btn-primary',
                'completed'     => 'btn-success',
                'cancelled'     => 'btn-danger',
                'waiting_parts' => 'btn-warning',
                default         => 'btn-secondary',
            };
            $btnLabel = match($next) {
                'in_progress'   => '▶ Start Work',
                'completed'     => '✓ Complete',
                'cancelled'     => '✕ Cancel',
                'waiting_parts' => '⏸ Waiting Parts',
                default         => ucwords(str_replace('_', ' ', $next)),
            };
            ?>
            <button class="btn <?= e($btnClass) ?> btn-sm"
                    @click="transitionStatus('<?= e($next) ?>')">
                <?= e($btnLabel) ?>
            </button>
            <?php endforeach; ?>

            <!-- Completion notes prompt (shown only when completing) -->
            <template x-if="showResolutionNotes">
                <div style="width:100%;margin-top:8px;">
                    <label class="form-label">Resolution Notes (optional)</label>
                    <textarea class="form-control" rows="2"
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
            </template>

        </div>
    </div>
    <?php endif; ?>

    <!-- ── Work Order Details (View / Edit) ───────────────────────────── -->
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:1rem;">Work Order Details</h3>
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

            <!-- View mode -->
            <template x-if="!editing">
                <dl class="detail-grid">
                    <dt>Unit</dt>
                    <dd>
                        <a href="<?= base_url('equipment/show') ?>?id=<?= e($wo['equipment_unit_id']) ?>"
                           class="link font-mono">
                            <?= e($wo['unit_number']) ?>
                        </a>
                        <?php if ($wo['unit_year'] || $wo['brand'] || $wo['model']): ?>
                        <span class="text-secondary"> — <?= e(trim($wo['unit_year'] . ' ' . $wo['brand'] . ' ' . $wo['model'])) ?></span>
                        <?php endif; ?>
                    </dd>

                    <dt>Work Type</dt>
                    <dd><?= e(ucwords(str_replace('_', ' ', $wo['work_type']))) ?></dd>

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
                    <dd><?= e($wo['requested_date'] ?? '—') ?></dd>

                    <dt>Scheduled Date</dt>
                    <dd><?= e($wo['scheduled_date'] ?? '—') ?></dd>

                    <?php if ($wo['completed_date']): ?>
                    <dt>Completed Date</dt>
                    <dd><?= e($wo['completed_date']) ?></dd>
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

                    <dt>Created By</dt>
                    <dd><?= e($wo['created_by_name'] ?? 'System') ?></dd>

                    <dt>Created</dt>
                    <dd><?= format_datetime($wo['created_at']) ?></dd>

                    <?php if ($wo['completed_by_name']): ?>
                    <dt>Completed By</dt>
                    <dd><?= e($wo['completed_by_name']) ?></dd>
                    <?php endif; ?>
                </dl>
            </template>

            <!-- Edit mode -->
            <template x-if="editing">
                <div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Work Type</label>
                            <select class="form-select" x-model="editForm.work_type">
                                <option value="scheduled_service">Scheduled Service</option>
                                <option value="repair">Repair</option>
                                <option value="inspection">Inspection</option>
                                <option value="tire">Tire</option>
                                <option value="electrical">Electrical</option>
                                <option value="body_damage">Body Damage</option>
                                <option value="breakdown">Breakdown</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Priority</label>
                            <select class="form-select" x-model="editForm.priority">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="emergency">Emergency</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-control" x-model="editForm.title" maxlength="500">
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label">Vendor</label>
                        <select class="form-select" x-model="editForm.vendor_id">
                            <option value="">— No Vendor —</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?= e($v['id']) ?>"><?= e($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row-2" style="margin-top:12px;">
                        <div class="form-group">
                            <label class="form-label">Requested Date</label>
                            <input type="date" class="form-control" x-model="editForm.requested_date">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Scheduled Date</label>
                            <input type="date" class="form-control" x-model="editForm.scheduled_date">
                        </div>
                    </div>

                    <div class="form-row-2" style="margin-top:12px;">
                        <div class="form-group">
                            <label class="form-label">Odometer (km)</label>
                            <input type="number" class="form-control" x-model="editForm.mileage_at_service" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Assigned To</label>
                            <select class="form-select" x-model="editForm.assigned_to">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($assignableUsers as $u): ?>
                                <option value="<?= e($u['id']) ?>"><?= e($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" rows="3" x-model="editForm.description"></textarea>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label">Notes (visible to vendor)</label>
                        <textarea class="form-control" rows="2" x-model="editForm.notes"></textarea>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label">Internal Notes</label>
                        <textarea class="form-control" rows="2" x-model="editForm.internal_notes"></textarea>
                    </div>
                </div>
            </template>

        </div><!-- /card-body -->
    </div><!-- /card -->

    <!-- ── Line Items ─────────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:1rem;">Line Items</h3>
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
                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label">Type</label>
                                <select class="form-select" x-model="newItem.item_type">
                                    <option value="labor">Labour</option>
                                    <option value="part">Part</option>
                                    <option value="sublet">Sublet</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Part Number</label>
                                <input type="text" class="form-control" x-model="newItem.part_number" placeholder="Optional">
                            </div>
                        </div>
                        <div class="form-group" style="margin-top:10px;">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" x-model="newItem.description" placeholder="Item description…">
                        </div>
                        <div class="form-row-3" style="margin-top:10px;">
                            <div class="form-group">
                                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" x-model="newItem.quantity" min="0.01" step="0.01">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Unit Cost <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" x-model="newItem.unit_cost" min="0" step="0.01">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Line Total</label>
                                <input type="text" class="form-control" readonly
                                       :value="'$' + (parseFloat(newItem.quantity||0) * parseFloat(newItem.unit_cost||0)).toFixed(2)">
                            </div>
                        </div>
                        <div style="margin-top:10px;display:flex;gap:8px;">
                            <button class="btn btn-primary btn-sm"
                                    @click="addItem()"
                                    :disabled="addingItem">
                                <span x-text="addingItem ? 'Adding…' : 'Add Item'"></span>
                            </button>
                            <button class="btn btn-secondary btn-sm" @click="showAddItem = false">Cancel</button>
                        </div>
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
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    fetch('<?= base_url('api/v1/maintenance_work_orders/delete.php') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify({ id: <?= (int)$wo['id'] ?> }),
    })
    .then(r => r.json())
    .then(d => {
        if (d && d.error) {
            alert(d.message ?? 'Delete failed.');
            document.getElementById('delete-modal').style.display = 'none';
            return;
        }
        window.location.href = '<?= base_url('maintenance_work_orders') ?>';
    })
    .catch(() => { alert('Network error. Please try again.'); });
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
        error:   null,
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
            this.editing = true;
            this.error   = null;
            this.success = null;
        },

        cancelEdit() { this.editing = false; this.error = null; },

        saveEdit() {
            this.saving = true;
            this.error  = null;
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
                .then(d => {
                    if (d && d.error) { this.error = d.message ?? 'Save failed.'; return; }
                    this.updatedAt = d.data.updated_at;
                    this.editing   = false;
                    this.success   = 'Work order updated.';
                    setTimeout(() => { this.success = null; }, 3000);
                })
                .catch(() => { this.error = 'Network error. Please try again.'; })
                .finally(() => { this.saving = false; });
        },

        // ── Status transitions ─────────────────────────────────────────────
        transitionStatus(newStatus) {
            // For 'completed', show resolution notes prompt first
            if (newStatus === 'completed') {
                this.pendingStatus       = 'completed';
                this.showResolutionNotes = true;
                return;
            }
            this._doTransition(newStatus, null, null);
        },

        confirmComplete() {
            this._doTransition('completed', null, this.resolutionNotes || null);
        },

        _doTransition(newStatus, reason, resolutionNotes) {
            this.transitioning = true;
            this.error         = null;
            const payload = {
                id:               <?= (int)$wo['id'] ?>,
                new_status:       newStatus,
                reason:           reason,
                resolution_notes: resolutionNotes,
            };
            FF_Api.post('<?= base_url('api/v1/maintenance_work_orders/update_status.php') ?>', payload)
                .then(d => {
                    if (d && d.error) { this.error = d.message ?? 'Transition failed.'; return; }
                    // Reload page to reflect new status, buttons, and resolved data
                    window.location.reload();
                })
                .catch(() => { this.error = 'Network error. Please try again.'; })
                .finally(() => { this.transitioning = false; });
        },

        // ── Line items ─────────────────────────────────────────────────────
        addItem() {
            this.error = null;
            if (!this.newItem.description.trim()) { this.error = 'Description is required.'; return; }
            if (parseFloat(this.newItem.quantity) <= 0) { this.error = 'Quantity must be > 0.'; return; }

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
                .then(d => {
                    if (d && d.error) { this.error = d.message ?? 'Failed to add item.'; return; }
                    // Reload to get fresh line items + WO cost tiles
                    window.location.reload();
                })
                .catch(() => { this.error = 'Network error. Please try again.'; })
                .finally(() => { this.addingItem = false; });
        },

        deleteItem(lineItemId) {
            if (!confirm('Delete this line item?')) return;
            this.error = null;
            FF_Api.post('<?= base_url('api/v1/maintenance_work_orders/line_items/delete.php') ?>', { id: lineItemId })
                .then(d => {
                    if (d && d.error) { this.error = d.message ?? 'Delete failed.'; return; }
                    window.location.reload();
                })
                .catch(() => { this.error = 'Network error. Please try again.'; });
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

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
