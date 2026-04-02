<?php
declare(strict_types=1);

/**
 * app/admin/vendors/show.php
 *
 * Vendor detail page.
 * Displays vendor profile (view + inline edit) and a Work Orders tab
 * showing maintenance_work_orders assigned to this vendor.
 *
 * Edit mode: saves via PATCH-style POST to api/v1/vendors/update.php.
 * D19 optimistic lock: updated_at submitted with every save.
 * Delete: soft-delete via api/v1/vendors/delete.php (blocked if active WOs exist).
 *
 * Work Orders tab: lazy-loaded via api/v1/maintenance_work_orders (built in S015).
 * For S014, we render recent_work_orders from the show API response.
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/vendors/show.php, api/v1/vendors/update.php, api/v1/vendors/delete.php
 * @decisions D5/D7/D19/D30/D32
 * @session  S014
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'view');

// ── Resolve vendor ───────────────────────────────────────────────────────────
$vendorId = clean_int($_GET['id'] ?? null);
if (!$vendorId) {
    header('Location: ' . base_url('vendors'));
    exit;
}

$vendor = db_row(
    "SELECT v.*, u.name AS created_by_name
     FROM vendors v
     LEFT JOIN users u ON u.id = v.created_by AND u.deleted_at IS NULL
     WHERE v.id = ? AND v.deleted_at IS NULL",
    [$vendorId]
);

if (!$vendor) {
    // Redirect with 404 message via query string (simple approach matching codebase pattern)
    header('Location: ' . base_url('vendors') . '?error=not_found');
    exit;
}

// Decode JSON specializations
$specializations = $vendor['specializations']
    ? json_decode($vendor['specializations'], true)
    : [];

// Work order counts for KPI tiles
$woOpen      = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE vendor_id = ? AND status IN ('open','in_progress','waiting_parts') AND deleted_at IS NULL", [$vendorId]);
$woCompleted = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE vendor_id = ? AND status = 'completed' AND deleted_at IS NULL", [$vendorId]);
$woTotal     = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE vendor_id = ? AND deleted_at IS NULL", [$vendorId]);

// Recent 5 work orders for the work orders tab (S015 will add full lazy-load)
$recentWo = db_select(
    "SELECT mwo.id, mwo.work_order_number, mwo.work_type, mwo.status,
            mwo.title, mwo.total_cost, mwo.scheduled_date, mwo.completed_date,
            eu.unit_number
     FROM maintenance_work_orders mwo
     LEFT JOIN equipment_units eu ON eu.id = mwo.equipment_unit_id AND eu.deleted_at IS NULL
     WHERE mwo.vendor_id = ? AND mwo.deleted_at IS NULL
     ORDER BY mwo.created_at DESC
     LIMIT 20",
    [$vendorId]
);

$pageTitle = e($vendor['name']);
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Main component — wraps everything so buttons access state directly ── -->
<div x-data="vendorShow()"
     x-init="init()">

<div class="page-header">
    <a href="<?= base_url('vendors') ?>" class="btn btn-secondary btn-sm">← Vendors</a>
    <h1 class="page-header-title"><?= e($vendor['name']) ?></h1>
    <div style="display:flex;gap:8px;margin-left:auto;">
        <?php if (can('maintenance', 'edit')): ?>
        <button class="btn btn-secondary btn-sm"
                @click="editing = !editing"
                x-text="editing ? 'Cancel Edit' : 'Edit'">Edit</button>
        <?php endif; ?>
        <?php if (can('maintenance', 'delete')): ?>
        <button class="btn btn-danger btn-sm"
                @click="deleteModalOpen = true">Delete</button>
        <?php endif; ?>
    </div>
</div>

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-label">Total Spent</div>
        <div class="stat-value font-mono"><?= format_currency($vendor['total_spent']) ?></div>
        <div class="stat-delta">all work orders</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Active Work Orders</div>
        <div class="stat-value font-mono"><?= e($woOpen) ?></div>
        <div class="stat-delta">open / in progress / waiting parts</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Completed</div>
        <div class="stat-value font-mono"><?= e($woCompleted) ?></div>
        <div class="stat-delta">finished work orders</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Work Orders</div>
        <div class="stat-value font-mono"><?= e($woTotal) ?></div>
        <div class="stat-delta">all time</div>
    </div>

</div>

    <!-- ── View / Edit card ─────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <span style="font-weight:600;">Vendor Details</span>
            <span x-show="editing"
                  class="badge badge-warning" style="font-size:0.75rem;">Editing</span>
        </div>

        <!-- Error banner (edit mode) -->
        <template x-if="error">
            <div class="alert alert-danger" x-text="error"
                 style="margin:12px 16px 0;"></div>
        </template>

        <div class="card-body">

            <!-- VIEW MODE -->
            <template x-if="!editing">
                <dl class="detail-grid">
                    <dt>Name</dt>
                    <dd><?= e($vendor['name']) ?>
                        <?php if ($vendor['is_preferred']): ?>
                        <span class="badge badge-success" style="margin-left:8px;font-size:0.7rem;">Preferred</span>
                        <?php endif; ?>
                    </dd>

                    <dt>Type</dt>
                    <dd>
                        <?php
                        $typeLabels = [
                            'maintenance' => 'Maintenance',
                            'repair'      => 'Repair',
                            'parts'       => 'Parts',
                            'inspection'  => 'Inspection',
                            'towing'      => 'Towing',
                            'other'       => 'Other',
                        ];
                        $typeBadges = [
                            'maintenance' => 'badge-warning',
                            'repair'      => 'badge-danger',
                            'parts'       => 'badge-info',
                            'inspection'  => 'badge-purple',
                            'towing'      => 'badge-neutral',
                            'other'       => 'badge-neutral',
                        ];
                        ?>
                        <span class="badge <?= e($typeBadges[$vendor['vendor_type']] ?? 'badge-neutral') ?>">
                            <?= e($typeLabels[$vendor['vendor_type']] ?? $vendor['vendor_type']) ?>
                        </span>
                    </dd>

                    <dt>Contact</dt>
                    <dd><?= $vendor['contact_name'] ? e($vendor['contact_name']) : '—' ?></dd>

                    <dt>Email</dt>
                    <dd>
                        <?php if ($vendor['email']): ?>
                        <a href="mailto:<?= e($vendor['email']) ?>"><?= e($vendor['email']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </dd>

                    <dt>Phone</dt>
                    <dd><?= $vendor['phone'] ? e($vendor['phone']) : '—' ?></dd>

                    <dt>Address</dt>
                    <dd>
                        <?php
                        $addr = array_filter([
                            $vendor['address'],
                            $vendor['city'],
                            $vendor['state'],
                        ]);
                        echo $addr ? e(implode(', ', $addr)) : '—';
                        ?>
                    </dd>

                    <dt>Hourly Rate</dt>
                    <dd class="font-mono"><?= $vendor['hourly_rate'] ? format_currency($vendor['hourly_rate']) . '/hr' : '—' ?></dd>

                    <dt>Rating</dt>
                    <dd>
                        <?php if ($vendor['rating']): ?>
                        <span style="color:var(--color-warning);">
                            <?= str_repeat('★', (int)$vendor['rating']) ?>
                        </span>
                        <span class="text-secondary"><?= e($vendor['rating']) ?>/5</span>
                        <?php else: ?>—<?php endif; ?>
                    </dd>

                    <dt>Specializations</dt>
                    <dd>
                        <?php if ($specializations): ?>
                        <?php foreach ($specializations as $spec): ?>
                        <span class="badge badge-info" style="margin-right:4px;">
                            <?= e($spec) ?>
                        </span>
                        <?php endforeach; ?>
                        <?php else: ?>—<?php endif; ?>
                    </dd>

                    <dt>Notes</dt>
                    <dd style="white-space:pre-wrap;"><?= $vendor['notes'] ? e($vendor['notes']) : '—' ?></dd>

                    <dt>Created</dt>
                    <dd><?= format_datetime($vendor['created_at']) ?>
                        <?php if ($vendor['created_by_name']): ?>
                        by <?= e($vendor['created_by_name']) ?>
                        <?php endif; ?>
                    </dd>
                </dl>
            </template>

            <!-- EDIT MODE -->
            <template x-if="editing">
                <div>
                    <!-- Name + Type -->
                    <div class="form-row-2" style="margin-bottom:16px;">
                        <div>
                            <label class="form-label">Vendor Name *</label>
                            <input type="text" class="form-control"
                                   x-model="form.name" maxlength="255">
                        </div>
                        <div>
                            <label class="form-label">Vendor Type *</label>
                            <select class="form-control" x-model="form.vendor_type">
                                <option value="maintenance">Maintenance</option>
                                <option value="repair">Repair</option>
                                <option value="parts">Parts</option>
                                <option value="inspection">Inspection</option>
                                <option value="towing">Towing</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="form-row-2" style="margin-bottom:16px;">
                        <div>
                            <label class="form-label">Contact Name</label>
                            <input type="text" class="form-control"
                                   x-model="form.contact_name" maxlength="255">
                        </div>
                        <div>
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control"
                                   x-model="form.email" maxlength="255">
                        </div>
                    </div>

                    <div class="form-row-2" style="margin-bottom:16px;">
                        <div>
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control"
                                   x-model="form.phone" maxlength="50">
                        </div>
                        <div>
                            <!-- blank for grid alignment -->
                        </div>
                    </div>

                    <!-- Location -->
                    <div style="margin-bottom:16px;">
                        <label class="form-label">Address</label>
                        <input type="text" class="form-control"
                               x-model="form.address" maxlength="500">
                    </div>
                    <div class="form-row-2" style="margin-bottom:16px;">
                        <div>
                            <label class="form-label">City</label>
                            <input type="text" class="form-control"
                                   x-model="form.city" maxlength="100">
                        </div>
                        <div>
                            <label class="form-label">Province / State</label>
                            <input type="text" class="form-control"
                                   x-model="form.state" maxlength="100">
                        </div>
                    </div>

                    <!-- Rates & Rating -->
                    <div class="form-row-2" style="margin-bottom:16px;">
                        <div>
                            <label class="form-label">Hourly Rate ($)</label>
                            <input type="number" class="form-control"
                                   x-model="form.hourly_rate"
                                   min="0" step="0.01">
                        </div>
                        <div>
                            <label class="form-label">Rating (1–5)</label>
                            <select class="form-control" x-model="form.rating">
                                <option value="">— Not rated —</option>
                                <option value="1">★ 1 — Poor</option>
                                <option value="2">★★ 2 — Fair</option>
                                <option value="3">★★★ 3 — Good</option>
                                <option value="4">★★★★ 4 — Very Good</option>
                                <option value="5">★★★★★ 5 — Excellent</option>
                            </select>
                        </div>
                    </div>

                    <!-- Specializations -->
                    <div style="margin-bottom:16px;">
                        <label class="form-label">Specializations</label>
                        <select class="form-control" multiple size="6"
                                @change="updateSpecs($event)">
                            <?php
                            $specOptions = ['Brakes','Diesel Engine','Electrical','Exhaust',
                                           'Hydraulics','HVAC','Suspension','Tires','Transmission','Welding'];
                            foreach ($specOptions as $opt):
                            ?>
                            <option value="<?= e($opt) ?>"
                                    <?= in_array($opt, $specializations, true) ? 'selected' : '' ?>>
                                <?= e($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-secondary" style="margin:4px 0 0;font-size:0.8125rem;">
                            Selected: <span x-text="form.specializations.length ? form.specializations.join(', ') : 'none'"></span>
                        </p>
                    </div>

                    <!-- Preferred + Notes -->
                    <div style="margin-bottom:16px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" x-model="form.is_preferred">
                            <span class="form-label" style="margin:0;">Mark as Preferred Vendor</span>
                        </label>
                    </div>

                    <div style="margin-bottom:24px;">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" rows="4"
                                  x-model="form.notes"></textarea>
                    </div>

                    <!-- Edit actions -->
                    <div style="display:flex;gap:12px;padding-top:16px;border-top:1px solid var(--border-default);">
                        <button class="btn btn-primary"
                                :disabled="saving"
                                @click="save()">
                            <span x-show="!saving">Save Changes</span>
                            <span x-show="saving">Saving…</span>
                        </button>
                        <button class="btn btn-secondary"
                                @click="cancelEdit()">Cancel</button>
                    </div>
                </div>
            </template>

        </div><!-- /card-body -->
    </div><!-- /card -->

    <!-- ── Work Orders tab ──────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header" style="font-weight:600;">
            Work Orders
            <span class="badge badge-neutral" style="margin-left:8px;"><?= e($woTotal) ?></span>
        </div>

        <?php if (empty($recentWo)): ?>
        <div class="card-body">
            <div class="empty-state">
                <p class="empty-state-title">No work orders yet</p>
                <p class="empty-state-text">Work orders assigned to this vendor will appear here.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Work Order #</th>
                        <th>Unit</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th style="text-align:right;">Total Cost</th>
                        <th>Scheduled</th>
                        <th>Completed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentWo as $wo): ?>
                    <?php
                    $woBadge = match($wo['status']) {
                        'open'           => 'badge-info',
                        'in_progress'    => 'badge-warning',
                        'waiting_parts'  => 'badge-warning',
                        'completed'      => 'badge-success',
                        'cancelled'      => 'badge-neutral',
                        default          => 'badge-neutral',
                    };
                    $woLabel = match($wo['status']) {
                        'open'           => 'Open',
                        'in_progress'    => 'In Progress',
                        'waiting_parts'  => 'Waiting Parts',
                        'completed'      => 'Completed',
                        'cancelled'      => 'Cancelled',
                        default          => $wo['status'],
                    };
                    ?>
                    <tr>
                        <td class="font-mono"><?= e($wo['work_order_number']) ?></td>
                        <td><?= $wo['unit_number'] ? e($wo['unit_number']) : '—' ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', $wo['work_type']))) ?></td>
                        <td><?= e($wo['title']) ?></td>
                        <td><span class="badge <?= e($woBadge) ?>"><?= e($woLabel) ?></span></td>
                        <td class="font-mono" style="text-align:right;"><?= format_currency($wo['total_cost']) ?></td>
                        <td><?= $wo['scheduled_date'] ? format_date($wo['scheduled_date']) : '—' ?></td>
                        <td><?= $wo['completed_date'] ? format_date($wo['completed_date']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($woTotal > 20): ?>
        <div class="card-footer text-secondary" style="padding:12px 16px;font-size:0.875rem;">
            Showing 20 of <?= e($woTotal) ?> work orders.
            Full work orders module coming in S015.
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div><!-- /work orders card -->

    <!-- ── Delete modal ─────────────────────────────────────────────────── -->
    <template x-if="deleteModalOpen">
        <div class="modal-backdrop" @click.self="deleteModalOpen = false">
            <div class="modal modal-sm">
                <div class="modal-header">
                    <h3 class="modal-title">Delete Vendor</h3>
                    <button class="btn-icon" @click="deleteModalOpen = false">✕</button>
                </div>
                <div class="modal-body">
                    <template x-if="deleteError">
                        <div class="alert alert-danger" x-text="deleteError"
                             style="margin-bottom:12px;"></div>
                    </template>
                    <p>Are you sure you want to delete
                        <strong><?= e($vendor['name']) ?></strong>?
                    </p>
                    <p class="text-secondary" style="font-size:0.875rem;">
                        This action cannot be undone. Vendors with active work orders
                        cannot be deleted.
                    </p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary"
                            @click="deleteModalOpen = false">Cancel</button>
                    <button class="btn btn-danger"
                            :disabled="deleting"
                            @click="confirmDelete()">
                        <span x-show="!deleting">Delete Vendor</span>
                        <span x-show="deleting">Deleting…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

</div><!-- /x-data vendorShow -->

<script>
function vendorShow() {
    return {
        editing:         false,
        saving:          false,
        error:           null,
        deleteModalOpen: false,
        deleting:        false,
        deleteError:     null,

        // Form state — mirrors vendor fields for edit mode
        form: {
            name:            <?= json_encode($vendor['name']) ?>,
            vendor_type:     <?= json_encode($vendor['vendor_type']) ?>,
            contact_name:    <?= json_encode($vendor['contact_name'] ?? '') ?>,
            email:           <?= json_encode($vendor['email'] ?? '') ?>,
            phone:           <?= json_encode($vendor['phone'] ?? '') ?>,
            address:         <?= json_encode($vendor['address'] ?? '') ?>,
            city:            <?= json_encode($vendor['city'] ?? '') ?>,
            state:           <?= json_encode($vendor['state'] ?? '') ?>,
            specializations: <?= json_encode($specializations) ?>,
            hourly_rate:     <?= json_encode($vendor['hourly_rate'] ?? '') ?>,
            rating:          <?= json_encode($vendor['rating'] ? (string)$vendor['rating'] : '') ?>,
            is_preferred:    <?= $vendor['is_preferred'] ? 'true' : 'false' ?>,
            notes:           <?= json_encode($vendor['notes'] ?? '') ?>,
            updated_at:      <?= json_encode($vendor['updated_at']) ?>,
        },

        init() {
            // Pre-select specializations in the <select multiple> if it exists in edit mode
        },

        updateSpecs(event) {
            this.form.specializations = Array.from(event.target.selectedOptions).map(o => o.value);
        },

        cancelEdit() {
            this.editing = false;
            this.error   = null;
        },

        save() {
            this.error = null;
            if (!this.form.name.trim()) {
                this.error = 'Vendor name is required.';
                return;
            }
            if (!this.form.vendor_type) {
                this.error = 'Vendor type is required.';
                return;
            }

            const payload = {
                id:              <?= $vendorId ?>,
                updated_at:      this.form.updated_at,  // D19 optimistic lock
                name:            this.form.name.trim(),
                vendor_type:     this.form.vendor_type,
                contact_name:    this.form.contact_name.trim() || null,
                email:           this.form.email.trim() || null,
                phone:           this.form.phone.trim() || null,
                address:         this.form.address.trim() || null,
                city:            this.form.city.trim() || null,
                state:           this.form.state.trim() || null,
                specializations: this.form.specializations,
                hourly_rate:     this.form.hourly_rate || null,
                rating:          this.form.rating ? parseInt(this.form.rating) : null,
                is_preferred:    this.form.is_preferred ? 1 : 0,
                notes:           this.form.notes.trim() || null,
            };

            this.saving = true;

            FF_Api.post('api/v1/vendors/update.php', payload)
                .then(d => {
                    // Update the lock token so next save uses the fresh updated_at
                    this.form.updated_at = d.data.updated_at;
                    this.editing = false;
                    // Reload page to reflect new server-rendered values
                    window.location.reload();
                })
                .catch(err => {
                    this.error = err?.data?.message ?? 'Save failed. Please try again.';
                    this.saving = false;
                });
        },

        confirmDelete() {
            this.deleteError = null;
            this.deleting    = true;

            FF_Api.post('api/v1/vendors/delete.php', { id: <?= $vendorId ?> })
                .then(() => {
                    window.location = '<?= base_url('vendors') ?>';
                })
                .catch(err => {
                    this.deleteError = err?.data?.message ?? 'Delete failed. Please try again.';
                    this.deleting    = false;
                });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
