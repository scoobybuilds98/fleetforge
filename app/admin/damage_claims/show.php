<?php
declare(strict_types=1);

/**
 * app/admin/damage_claims/show.php
 *
 * Damage Claim detail page. Sections:
 *   - Summary header (claim #, status badge, severity, unit, customer)
 *   - Detail card (description, location, costs, linked records)
 *   - Photo gallery (with upload form and per-photo delete)
 *   - Status transition panel (inline form)
 *   - Notes / Resolution notes edit form
 *   - Delete button (reported/assessed only)
 *
 * All writes go through Alpine.js → API endpoints.
 * File upload uses FormData (multipart) to upload_photo.php.
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/damage_claims/show.php, update.php, upload_photo.php, delete_photo.php,
 *           delete.php
 * @decisions D5/D9/D19/D30/D32
 * @session  S012
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Storage\StorageClient;

require_auth();
require_permission('maintenance', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(400);
    die('Missing id.');
}

// Load claim
$claim = db_row(
    "SELECT
        dc.id,
        dc.claim_number,
        dc.equipment_unit_id,
        eu.unit_number,
        eu.year,
        et.brand,
        et.model,
        dc.lease_id,
        l.contract_number,
        dc.customer_id,
        dc.customer_name,
        c.company_name        AS customer_company_name,
        dc.inspection_id,
        dc.work_order_id,
        dc.invoice_id,
        dc.description,
        dc.damage_location,
        dc.severity,
        dc.estimated_repair_cost,
        dc.actual_repair_cost,
        dc.customer_liable_amount,
        dc.insurance_claim_amount,
        dc.status,
        dc.notes,
        dc.resolution_notes,
        dc.vendor_id,
        v.name                AS vendor_name,
        v.vendor_type         AS vendor_type,
        dc.reported_by,
        u.name                AS reported_by_name,
        dc.created_at,
        dc.updated_at
     FROM damage_claims dc
     LEFT JOIN equipment_units     eu ON eu.id = dc.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id        AND et.deleted_at IS NULL
     LEFT JOIN customers c            ON c.id  = dc.customer_id        AND c.deleted_at  IS NULL
     LEFT JOIN leases l            ON l.id  = dc.lease_id          AND l.deleted_at  IS NULL
     LEFT JOIN vendors v            ON v.id  = dc.vendor_id         AND v.deleted_at  IS NULL
     LEFT JOIN users u            ON u.id  = dc.reported_by
     WHERE dc.id = ? AND dc.deleted_at IS NULL",
    [$id]
);

if (!$claim) {
    http_response_code(404);
    die('Damage claim not found.');
}

// Vendors list for edit form dropdown
$vendorsList = db_select(
    "SELECT id, name FROM vendors WHERE deleted_at IS NULL ORDER BY name ASC"
);

// Customers list for edit form dropdown
$customersList = db_select(
    "SELECT id, company_name FROM customers WHERE status = 'active' AND deleted_at IS NULL ORDER BY company_name ASC"
);

// Load photos — serve signed URLs, never raw file_path
$photos = db_select(
    "SELECT id, claim_id, photo_type, caption, uploaded_at, file_path
     FROM damage_claim_photos
     WHERE claim_id = ?
     ORDER BY uploaded_at ASC",
    [$id]
);

$photoData = [];
foreach ($photos as $p) {
    $photoData[] = [
        'id'          => (int)$p['id'],
        'photo_type'  => $p['photo_type'],
        'caption'     => $p['caption'],
        'uploaded_at' => $p['uploaded_at'],
        'url'         => StorageClient::url($p['file_path']),
    ];
}

// State machine — valid next states
$allowedTransitions = [
    'reported'       => ['assessed', 'written_off'],
    'assessed'       => ['repair_ordered', 'written_off'],
    'repair_ordered' => ['invoiced', 'resolved', 'written_off'],
    'invoiced'       => ['resolved', 'written_off'],
    'resolved'       => [],
    'written_off'    => [],
];
$nextStates = $allowedTransitions[$claim['status']] ?? [];

// Badge helpers (PHP-rendered for header)
$statusBadgeClass = match($claim['status']) {
    'reported'       => 'badge badge-info',
    'assessed'       => 'badge badge-warning',
    'repair_ordered' => 'badge badge-warning',
    'invoiced'       => 'badge badge-purple',
    'resolved'       => 'badge badge-success',
    'written_off'    => 'badge badge-neutral',
    default          => 'badge badge-neutral',
};

$statusLabel = match($claim['status']) {
    'reported'       => 'Reported',
    'assessed'       => 'Assessed',
    'repair_ordered' => 'Repair Ordered',
    'invoiced'       => 'Invoiced',
    'resolved'       => 'Resolved',
    'written_off'    => 'Written Off',
    default          => $claim['status'],
};

$severityBadgeClass = match($claim['severity']) {
    'minor'      => 'badge badge-info',
    'moderate'   => 'badge badge-warning',
    'major'      => 'badge badge-danger',
    'total_loss' => 'badge badge-danger',
    default      => 'badge badge-neutral',
};

$severityLabel = match($claim['severity']) {
    'minor'      => 'Minor',
    'moderate'   => 'Moderate',
    'major'      => 'Major',
    'total_loss' => 'Total Loss',
    default      => $claim['severity'],
};

$pageTitle = e($claim['claim_number']) . ' — Damage Claim';
$helpModuleSlug = 'damage-claims';
require_once FF_ROOT . '/includes/header.php';
?>

<div x-data="damageClaimShow()" x-init="init()">

<!-- ── Page header ───────────────────────────────────────────────────────── -->
<div class="page-header" style="flex-wrap:wrap;gap:8px;">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <a href="<?= base_url('damage_claims') ?>" class="btn btn-secondary btn-sm">← Back</a>
        <h1 class="page-header-title" style="margin:0;"><?= e($claim['claim_number']) ?></h1>
        <span class="<?= $statusBadgeClass ?>"><?= $statusLabel ?></span>
        <span class="<?= $severityBadgeClass ?>"><?= $severityLabel ?></span>
    </div>
    <div class="page-header-actions">
        <?= help_button('damage-claims') ?>
        <?php if (can('maintenance', 'edit') && $nextStates): ?>
        <button class="btn btn-secondary btn-sm"
                @click="showStatusPanel = !showStatusPanel">
            Change Status
        </button>
        <?php endif; ?>
        <?php if (can('maintenance', 'delete') && in_array($claim['status'], ['reported', 'assessed'], true)): ?>
        <button class="btn btn-danger btn-sm"
                @click="confirmDelete = true">
            Delete
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ── Summary tiles ─────────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-label">Equipment Unit</div>
        <div class="stat-value">
            <?php if ($claim['equipment_unit_id']): ?>
            <a href="<?= base_url('equipment/show') ?>?id=<?= e($claim['equipment_unit_id']) ?>"
               class="link">
                <?= e($claim['unit_number'] ?? 'Unit #' . $claim['equipment_unit_id']) ?>
            </a>
            <?php else: ?>
            —
            <?php endif; ?>
        </div>
        <div class="stat-delta"><?= $claim['brand'] ? e(($claim['year'] ? $claim['year'] . ' ' : '') . $claim['brand'] . ($claim['model'] ? ' ' . $claim['model'] : '')) : '' ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Customer</div>
        <div class="stat-value">
            <?php if ($claim['customer_id']): ?>
            <a href="<?= base_url('customers/show') ?>?id=<?= e($claim['customer_id']) ?>"
               class="link">
                <?= e($claim['customer_company_name'] ?? 'Customer #' . $claim['customer_id']) ?>
            </a>
            <?php elseif ($claim['customer_name']): ?>
            <?= e($claim['customer_name']) ?>
            <?php else: ?>
            —
            <?php endif; ?>
        </div>
        <div class="stat-delta">
            <?php if ($claim['lease_id']): ?>
            Lease: <a href="<?= base_url('leases/show') ?>?id=<?= e($claim['lease_id']) ?>" class="link">
                <?= e($claim['contract_number'] ?? '#' . $claim['lease_id']) ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Est. Repair Cost</div>
        <div class="stat-value font-mono">
            <?= $claim['estimated_repair_cost'] ? format_currency($claim['estimated_repair_cost']) : '—' ?>
        </div>
        <div class="stat-delta">
            <?= $claim['actual_repair_cost'] ? 'Actual: ' . format_currency($claim['actual_repair_cost']) : 'Actual: pending' ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Customer Liable</div>
        <div class="stat-value font-mono">
            <?= $claim['customer_liable_amount'] ? format_currency($claim['customer_liable_amount']) : '—' ?>
        </div>
        <div class="stat-delta">
            <?= $claim['insurance_claim_amount'] ? 'Insurance: ' . format_currency($claim['insurance_claim_amount']) : '' ?>
        </div>
    </div>

</div>

<!-- ── Status transition panel ───────────────────────────────────────────── -->
<?php if (can('maintenance', 'edit') && $nextStates): ?>
<div class="card" x-show="showStatusPanel" style="margin-bottom:24px;display:none;">
    <div class="card-header">
        <h2 class="card-title">Change Status</h2>
    </div>
    <div class="card-body">
        <template x-if="statusError">
            <div class="alert alert-danger" style="margin-bottom:12px;" x-text="statusError"></div>
        </template>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="min-width:200px;">
                <label class="form-label">New Status</label>
                <select class="form-select" x-model="newStatus">
                    <option value="">— Select —</option>
                    <?php foreach ($nextStates as $s): ?>
                    <option value="<?= e($s) ?>"><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary"
                    :disabled="!newStatus || statusSaving"
                    @click="changeStatus()">
                <span x-text="statusSaving ? 'Saving…' : 'Apply'"></span>
            </button>
            <button class="btn btn-secondary"
                    @click="showStatusPanel = false; newStatus = ''; statusError = ''">
                Cancel
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Detail card ───────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title">Claim Details</h2>
        <?php if (can('maintenance', 'edit')): ?>
        <button class="btn btn-secondary btn-sm"
                @click="showEditForm = !showEditForm"
                x-text="showEditForm ? 'Cancel Edit' : 'Edit'"></button>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <!-- View mode -->
        <div x-show="!showEditForm">
            <dl class="detail-grid">
                <dt>Claim Number</dt>
                <dd class="font-mono"><?= e($claim['claim_number']) ?></dd>

                <dt>Damage Location</dt>
                <dd><?= $claim['damage_location'] ? e($claim['damage_location']) : '—' ?></dd>

                <dt>Description</dt>
                <dd style="white-space:pre-wrap;"><?= e($claim['description']) ?></dd>

                <dt>Work Order</dt>
                <dd>
                    <?php if ($claim['work_order_id']): ?>
                    <a href="<?= base_url('maintenance/show') ?>?id=<?= e($claim['work_order_id']) ?>"
                       class="link">#<?= e($claim['work_order_id']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </dd>

                <dt>Invoice</dt>
                <dd>
                    <?php if ($claim['invoice_id']): ?>
                    <a href="<?= base_url('invoices/show') ?>?id=<?= e($claim['invoice_id']) ?>"
                       class="link">#<?= e($claim['invoice_id']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </dd>

                <dt>Vendor Sent To</dt>
                <dd>
                    <?php if ($claim['vendor_id']): ?>
                    <a href="<?= base_url('vendors/show') ?>?id=<?= e($claim['vendor_id']) ?>"
                       class="link"><?= e($claim['vendor_name']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </dd>

                <dt>Reported By</dt>
                <dd><?= $claim['reported_by_name'] ? e($claim['reported_by_name']) : '—' ?></dd>

                <dt>Reported At</dt>
                <dd class="font-mono"><?= format_datetime($claim['created_at']) ?></dd>

                <dt>Last Updated</dt>
                <dd class="font-mono"><?= format_datetime($claim['updated_at']) ?></dd>
            </dl>

            <?php if ($claim['notes']): ?>
            <div style="margin-top:16px;">
                <strong>Notes</strong>
                <p style="white-space:pre-wrap;margin-top:4px;"><?= e($claim['notes']) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($claim['resolution_notes']): ?>
            <div style="margin-top:16px;">
                <strong>Resolution Notes</strong>
                <p style="white-space:pre-wrap;margin-top:4px;"><?= e($claim['resolution_notes']) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Edit form (visible when showEditForm) -->
        <?php if (can('maintenance', 'edit')): ?>
        <div x-show="showEditForm" style="display:none;">
            <template x-if="staleError">
                <div class="alert alert-danger" style="margin-bottom:12px;">
                    This damage claim was modified by another user. Please reload this page to get the latest version.
                </div>
            </template>
            <form id="dmg-edit-form" @submit.prevent="saveEdit()" novalidate>
                <div class="form-error-banner" data-form-error></div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label" for="edit_severity">Severity</label>
                        <select id="edit_severity" name="severity" class="form-select" x-model="editForm.severity">
                            <option value="minor">Minor</option>
                            <option value="moderate">Moderate</option>
                            <option value="major">Major</option>
                            <option value="total_loss">Total Loss</option>
                        </select>
                        <div class="field-error" data-error-for="severity"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_damage_location">Damage Location</label>
                        <input type="text" id="edit_damage_location" name="damage_location" class="form-control" x-model="editForm.damage_location" maxlength="255">
                        <div class="field-error" data-error-for="damage_location"></div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_description">Description</label>
                    <textarea id="edit_description" name="description" class="form-control" rows="4" x-model="editForm.description"></textarea>
                    <div class="field-error" data-error-for="description"></div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_customer_id">Customer</label>
                    <select id="edit_customer_id" name="customer_id" class="form-select" x-model="editForm.customer_id"
                            @change="if(editForm.customer_id) editForm.customer_name = ''">
                        <option value="">— Select existing —</option>
                        <?php foreach ($customersList as $c): ?>
                        <option value="<?= e($c['id']) ?>"><?= e($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="customer_name" class="form-control" style="margin-top:6px;"
                           placeholder="Or type customer name…"
                           x-model="editForm.customer_name"
                           maxlength="255"
                           @input="if(editForm.customer_name) editForm.customer_id = ''">
                    <div class="field-error" data-error-for="customer_id"></div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label" for="edit_estimated_repair_cost">Est. Repair Cost</label>
                        <input type="number" min="0" id="edit_estimated_repair_cost" name="estimated_repair_cost" class="form-control" step="0.01" x-model="editForm.estimated_repair_cost">
                        <div class="field-error" data-error-for="estimated_repair_cost"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_actual_repair_cost">Actual Repair Cost</label>
                        <input type="number" min="0" id="edit_actual_repair_cost" name="actual_repair_cost" class="form-control" step="0.01" x-model="editForm.actual_repair_cost">
                        <div class="field-error" data-error-for="actual_repair_cost"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_customer_liable_amount">Liable Amount ($)</label>
                        <input type="number" min="0" id="edit_customer_liable_amount" name="customer_liable_amount" class="form-control" step="0.01" x-model="editForm.customer_liable_amount">
                        <div class="field-error" data-error-for="customer_liable_amount"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_insurance_claim_amount">Insurance Claim</label>
                        <input type="number" min="0" id="edit_insurance_claim_amount" name="insurance_claim_amount" class="form-control" step="0.01" x-model="editForm.insurance_claim_amount">
                        <div class="field-error" data-error-for="insurance_claim_amount"></div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_notes">Notes</label>
                    <textarea id="edit_notes" name="notes" class="form-control" rows="3" x-model="editForm.notes"></textarea>
                    <div class="field-error" data-error-for="notes"></div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_resolution_notes">Resolution Notes</label>
                    <textarea id="edit_resolution_notes" name="resolution_notes" class="form-control" rows="3" x-model="editForm.resolution_notes"></textarea>
                    <div class="field-error" data-error-for="resolution_notes"></div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_vendor_id">Vendor Sent To</label>
                    <select id="edit_vendor_id" name="vendor_id" class="form-select" x-model="editForm.vendor_id">
                        <option value="">— None —</option>
                        <?php foreach ($vendorsList as $v): ?>
                        <option value="<?= e($v['id']) ?>"><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-error" data-error-for="vendor_id"></div>
                </div>

                <div style="display:flex;gap:12px;">
                    <button type="submit" class="btn btn-primary" :disabled="editSaving">
                        <span x-text="editSaving ? 'Saving…' : 'Save Changes'"></span>
                    </button>
                    <button type="button" class="btn btn-secondary"
                            @click="cancelEdit()">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ── Photo gallery ─────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title">Photos <span class="badge badge-neutral" x-text="photos.length"></span></h2>
        <?php if (can('maintenance', 'edit')): ?>
        <button class="btn btn-secondary btn-sm"
                @click="showUploadForm = !showUploadForm"
                x-text="showUploadForm ? 'Cancel Upload' : 'Add Photo'"></button>
        <?php endif; ?>
    </div>

    <!-- Upload form -->
    <?php if (can('maintenance', 'edit')): ?>
    <div x-show="showUploadForm" style="padding:16px;border-bottom:1px solid var(--border-color);display:none;">
        <template x-if="uploadError">
            <div class="alert alert-danger" style="margin-bottom:12px;" x-text="uploadError"></div>
        </template>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="flex:0 0 auto;">
                <label class="form-label">Photo Type</label>
                <select class="form-select form-select-sm" x-model="uploadPhotoType">
                    <option value="damage">Damage</option>
                    <option value="repair_before">Before Repair</option>
                    <option value="repair_after">After Repair</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group" style="flex:1;min-width:180px;">
                <label class="form-label">Caption (optional)</label>
                <input type="text" class="form-input form-input-sm" x-model="uploadCaption" maxlength="255">
            </div>
            <div class="form-group" style="flex:0 0 auto;">
                <label class="form-label">File (JPG, PNG, HEIC — max 10 MB)</label>
                <input type="file"
                       class="form-input form-input-sm"
                       accept="image/jpeg,image/png,image/heic"
                       @change="uploadFile = $event.target.files[0]">
            </div>
            <button class="btn btn-primary btn-sm"
                    :disabled="!uploadFile || uploading"
                    @click="uploadPhoto()">
                <span x-text="uploading ? 'Uploading…' : 'Upload'"></span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Photo grid -->
    <div class="card-body">
        <template x-if="photos.length === 0">
            <div class="empty-state">
                <p class="empty-state-title">No photos</p>
                <p class="empty-state-text">Upload photos to document the damage.</p>
            </div>
        </template>

        <div x-show="photos.length > 0"
             style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;">
            <template x-for="photo in photos" :key="photo.id">
                <div style="border:1px solid var(--border-color);border-radius:8px;overflow:hidden;">
                    <a :href="photo.url" target="_blank" rel="noopener">
                        <img :src="photo.url"
                             :alt="photo.caption ?? 'Damage photo'"
                             style="width:100%;height:140px;object-fit:cover;display:block;">
                    </a>
                    <div style="padding:8px;">
                        <span class="badge badge-neutral"
                              style="font-size:0.7rem;"
                              x-text="photoTypeLabel(photo.photo_type)"></span>
                        <template x-if="photo.caption">
                            <p style="font-size:0.75rem;margin:4px 0 0;color:var(--text-secondary);"
                               x-text="photo.caption"></p>
                        </template>
                        <?php if (can('maintenance', 'edit')): ?>
                        <button class="btn btn-danger btn-sm"
                                style="width:100%;margin-top:8px;font-size:0.75rem;"
                                @click="deletePhoto(photo.id)">
                            Delete
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<!-- ── Delete confirm modal ───────────────────────────────────────────────── -->
<template x-if="confirmDelete">
    <div class="modal-backdrop" style="z-index:1000;" @click.self="confirmDelete = false">
        <div class="modal modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">Delete Claim</h3>
            </div>
            <div class="modal-body">
                <p>Permanently delete claim <strong><?= e($claim['claim_number']) ?></strong>?
                This cannot be undone.</p>
                <template x-if="deleteError">
                    <div class="alert alert-danger" style="margin-top:12px;" x-text="deleteError"></div>
                </template>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" @click="confirmDelete = false">Cancel</button>
                <button class="btn btn-danger"
                        :disabled="deleting"
                        @click="deleteClaim()">
                    <span x-text="deleting ? 'Deleting…' : 'Delete'"></span>
                </button>
            </div>
        </div>
    </div>
</template>

</div><!-- /x-data -->

<script>
function damageClaimShow() {
    return {
        // State
        showStatusPanel: false,
        showEditForm:    false,
        showUploadForm:  false,
        confirmDelete:   false,

        // Status change
        newStatus:    '',
        statusSaving: false,
        statusError:  '',

        // Edit form
        editForm: {
            severity:               <?= json_encode($claim['severity']) ?>,
            damage_location:        <?= json_encode($claim['damage_location'] ?? '') ?>,
            description:            <?= json_encode($claim['description']) ?>,
            customer_id:            <?= json_encode($claim['customer_id'] ? (string)$claim['customer_id'] : '') ?>,
            customer_name:          <?= json_encode($claim['customer_name'] ?? '') ?>,
            notes:                  <?= json_encode($claim['notes'] ?? '') ?>,
            resolution_notes:       <?= json_encode($claim['resolution_notes'] ?? '') ?>,
            estimated_repair_cost:  <?= json_encode($claim['estimated_repair_cost'] ?? '') ?>,
            actual_repair_cost:     <?= json_encode($claim['actual_repair_cost'] ?? '') ?>,
            customer_liable_amount: <?= json_encode($claim['customer_liable_amount'] ?? '') ?>,
            insurance_claim_amount: <?= json_encode($claim['insurance_claim_amount'] ?? '') ?>,
            vendor_id:              <?= json_encode($claim['vendor_id'] ? (string)$claim['vendor_id'] : '') ?>,
        },
        editSaving: false,
        staleError: false,

        // Photo upload
        uploadFile:      null,
        uploadPhotoType: 'damage',
        uploadCaption:   '',
        uploading:       false,
        uploadError:     '',

        // Photos (pre-seeded server-side)
        photos: <?= json_encode($photoData) ?>,

        // Delete
        deleting:     false,
        deleteError:  '',

        // Optimistic lock — updated_at needed for all updates (D19)
        updatedAt: <?= json_encode($claim['updated_at']) ?>,

        init() {
            // Nothing async on load — photos pre-seeded from PHP
        },

        // ── Status change ────────────────────────────────────────────────
        changeStatus() {
            if (!this.newStatus) return;
            this.statusSaving = true;
            this.statusError  = '';

            FF_Api.post('<?= base_url('api/v1/damage_claims/update.php') ?>', {
                id:         <?= (int)$claim['id'] ?>,
                updated_at: this.updatedAt,
                status:     this.newStatus,
            }).then(r => {
                if (!r.success) {
                    this.statusError  = (r.error && r.error.message) || 'Failed to change status.';
                    this.statusSaving = false;
                } else {
                    window.location.reload();
                }
            }).catch(() => {
                this.statusError  = 'Network error. Please try again.';
                this.statusSaving = false;
            });
        },

        cancelEdit() {
            this.showEditForm = false;
            const form = document.getElementById('dmg-edit-form');
            if (form) FF_Validate.clear(form);
        },

        validateEdit(form) {
            FF_Validate.clear(form);
            let ok = true;

            if (!this.editForm.description || !this.editForm.description.trim()) {
                FF_Validate.field(form, 'description', 'Description is required.');
                ok = false;
            }
            if (!this.editForm.severity) {
                FF_Validate.field(form, 'severity', 'Please select a severity.');
                ok = false;
            }

            const moneyFields = {
                estimated_repair_cost:  'Estimated repair cost',
                actual_repair_cost:     'Actual repair cost',
                customer_liable_amount: 'Customer liable amount',
                insurance_claim_amount: 'Insurance claim amount',
            };
            for (const [field, label] of Object.entries(moneyFields)) {
                const raw = this.editForm[field];
                if (raw === '' || raw === null || raw === undefined) continue;
                const n = parseFloat(raw);
                if (isNaN(n) || n < 0) {
                    FF_Validate.field(form, field, `${label} cannot be negative.`);
                    ok = false;
                }
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        // ── Edit form save ───────────────────────────────────────────────
        saveEdit() {
            const form = document.getElementById('dmg-edit-form');
            if (!form) return;
            if (!this.validateEdit(form)) return;

            this.editSaving = true;

            const payload = {
                id:         <?= (int)$claim['id'] ?>,
                updated_at: this.updatedAt,
            };

            // Only send non-empty changed fields
            const fields = ['severity','damage_location','description','notes',
                            'resolution_notes','estimated_repair_cost',
                            'actual_repair_cost','customer_liable_amount','insurance_claim_amount'];
            fields.forEach(f => {
                payload[f] = this.editForm[f] !== '' ? this.editForm[f] : null;
            });
            // Integer FK fields + free-text customer name
            payload.customer_id   = this.editForm.customer_id   ? parseInt(this.editForm.customer_id)   : null;
            payload.customer_name = this.editForm.customer_name ? this.editForm.customer_name.trim() || null : null;
            payload.vendor_id     = this.editForm.vendor_id     ? parseInt(this.editForm.vendor_id)     : null;

            FF_Api.post('<?= base_url('api/v1/damage_claims/update.php') ?>', payload)
                .then(r => {
                    if (!r.success) {
                        if (r.error && r.error.code === 'STALE_DATA') {
                            this.staleError = true;
                        } else if (r.error && r.error.code === 'VALIDATION_ERROR') {
                            FF_Validate.applyApi(form, r.error);
                        } else {
                            FF_Validate.banner(form, (r.error && r.error.message) || 'Save failed.');
                        }
                        this.editSaving = false;
                        return;
                    }
                    window.location.reload();
                })
                .catch(() => {
                    FF_Validate.banner(form, 'Network error. Please try again.');
                    this.editSaving = false;
                });
        },

        // ── Photo upload ─────────────────────────────────────────────────
        uploadPhoto() {
            if (!this.uploadFile) return;
            this.uploading    = true;
            this.uploadError  = '';

            const fd = new FormData();
            fd.append('claim_id',   <?= (int)$claim['id'] ?>);
            fd.append('photo',      this.uploadFile);
            fd.append('photo_type', this.uploadPhotoType);
            fd.append('caption',    this.uploadCaption);

            // Use native fetch for multipart (FF_Api.post sends JSON)
            fetch('<?= base_url('api/v1/damage_claims/upload_photo.php') ?>', {
                method:      'POST',
                credentials: 'same-origin',
                body:        fd,
            })
            .then(r => r.json())
            .then(resp => {
                if (!resp.success) throw new Error(resp.error?.message ?? 'Upload failed.');
                this.photos.push(resp.data);
                this.uploadFile      = null;
                this.uploadCaption   = '';
                this.showUploadForm  = false;
                // Reset file input
                document.querySelector('input[type="file"]').value = '';
            })
            .catch(err => {
                this.uploadError = err.message ?? 'Upload failed.';
            })
            .finally(() => { this.uploading = false; });
        },

        // ── Delete photo ─────────────────────────────────────────────────
        // [UI-AUDIT-1:M13] async for FF_Confirm.ask().
        async deletePhoto(photoId) {
            if (!(await FF_Confirm.ask('Delete this photo?'))) return;

            FF_Api.post('<?= base_url('api/v1/damage_claims/delete_photo.php') ?>', {
                photo_id: photoId,
                claim_id: <?= (int)$claim['id'] ?>,
            }).then(d => {
                if (d && d.error) {
                    FF_Toast.error(d.message ?? d.data?.message ?? 'Failed to delete photo.');
                } else {
                    this.photos = this.photos.filter(p => p.id !== photoId);
                }
            }).catch(err => {
                FF_Toast.error(err?.message ?? 'Failed to delete photo.');
            });
        },

        // ── Delete claim ─────────────────────────────────────────────────
        deleteClaim() {
            this.deleting    = true;
            this.deleteError = '';

            FF_Api.post('<?= base_url('api/v1/damage_claims/delete.php') ?>', {
                id: <?= (int)$claim['id'] ?>,
            }).then(d => {
                if (d && d.error) {
                    this.deleteError = d.message ?? d.data?.message ?? 'Delete failed.';
                    this.deleting    = false;
                } else {
                    window.location = '<?= base_url('damage_claims') ?>';
                }
            }).catch(err => {
                this.deleteError = err?.message ?? 'Delete failed.';
                this.deleting    = false;
            });
        },

        // ── Helpers ──────────────────────────────────────────────────────
        photoTypeLabel(t) {
            return { damage:'Damage', repair_before:'Before Repair', repair_after:'After Repair', other:'Other' }[t] ?? t;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
