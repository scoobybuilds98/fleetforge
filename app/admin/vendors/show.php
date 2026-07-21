<?php
declare(strict_types=1);

/**
 * app/admin/vendors/show.php
 *
 * Vendor detail page.
 * Displays vendor profile (view + inline edit) and recent work orders.
 *
 * Edit mode: plain onclick toggle + raw fetch() to api/v1/vendors/update.php.
 * D19 optimistic lock: updated_at submitted with every save.
 * Delete: soft-delete via api/v1/vendors/delete.php (blocked if active WOs exist).
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
    header('Location: ' . base_url('vendors') . '?error=not_found');
    exit;
}

// Decode JSON specializations
$specializations = $vendor['specializations']
    ? json_decode($vendor['specializations'], true)
    : [];

// ── QBO mapping (S-QBO-7) ─────────────────────────────────────
// Drives the QuickBooks badge in the page header. Only renders when
// the connection is established (no point teasing the feature pre-
// setup). Separate query to keep the vendor SELECT untouched.
$qboMapping = null;
if ((string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected') {
    $qboMapping = db_row(
        "SELECT id, qbo_vendor_id, mapping_status, last_synced_at, last_push_at
           FROM acc_qbo_vendor_map
          WHERE ff_vendor_id = ?",
        [$vendorId]
    );
}

// Work order counts for KPI tiles
$woOpen      = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE vendor_id = ? AND status IN ('open','in_progress','waiting_parts') AND deleted_at IS NULL", [$vendorId]);
$woCompleted = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE vendor_id = ? AND status = 'completed' AND deleted_at IS NULL", [$vendorId]);
$woTotal     = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE vendor_id = ? AND deleted_at IS NULL", [$vendorId]);

// Equipment units this vendor has worked on (distinct, from work orders)
$unitHistory = db_select(
    "SELECT eu.id, eu.unit_number, eu.year, eb.label AS brand, et.model,
            COUNT(mwo.id) AS service_count,
            MAX(mwo.completed_date) AS last_service_date
     FROM maintenance_work_orders mwo
     JOIN equipment_units eu ON eu.id = mwo.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
     WHERE mwo.vendor_id = ? AND mwo.deleted_at IS NULL
     GROUP BY eu.id, eu.unit_number, eu.year, eb.label, et.model
     ORDER BY last_service_date DESC, eu.unit_number ASC",
    [$vendorId]
);

// Active / pending leases on units this vendor has worked on
$leaseExposure = db_select(
    "SELECT l.id AS lease_id, l.contract_number, l.status,
            l.start_date, l.end_date,
            c.id AS customer_id, c.company_name,
            eu.id AS unit_id, eu.unit_number
     FROM leases l
     JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
     JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     WHERE eu.id IN (
         SELECT DISTINCT mwo.equipment_unit_id
         FROM maintenance_work_orders mwo
         WHERE mwo.vendor_id = ? AND mwo.deleted_at IS NULL AND mwo.equipment_unit_id IS NOT NULL
     )
     AND l.status IN ('active','pending')
     AND l.deleted_at IS NULL
     ORDER BY l.status ASC, l.end_date ASC",
    [$vendorId]
);

// WHY: Work orders section converted to Alpine.js (paginated + filtered) in S018-UX.
//      $woTotal already loaded above for KPI tiles. No PHP pre-load needed.

// Vendor type label/badge maps — used in view mode
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

$pageTitle = e($vendor['name']);
$helpModuleSlug = 'vendors';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('vendors') ?>" class="btn btn-secondary btn-sm">← Vendors</a>
    <h1 class="page-header-title" style="margin:0 12px 0 8px;"><?= e($vendor['name']) ?></h1>
    <?php /* QBO mapping badge — S-QBO-7. Only shown when the
             connection is established AND a mapping row exists.
             Status drives the color: mapped=success (linked both
             sides), ff_only=warning (not pushed yet), qbo_only=
             info (QBO has but FF doesn't link — operator resolves
             via /quickbooks/vendors), ignored=neutral. */ ?>
    <?php if ($qboMapping !== null):
        $qm_status = (string) ($qboMapping['mapping_status'] ?? 'qbo_only');
        $qm_class  = match ($qm_status) {
            'mapped'   => 'badge-success',
            'ff_only'  => 'badge-warning',
            'qbo_only' => 'badge-info',
            'ignored'  => 'badge-neutral',
            default    => 'badge-neutral',
        };
        $qm_label  = match ($qm_status) {
            'mapped'   => 'QuickBooks: Synced',
            'ff_only'  => 'QuickBooks: Not synced',
            'qbo_only' => 'QuickBooks: Linked from QBO side',
            'ignored'  => 'QuickBooks: Excluded',
            default    => 'QuickBooks',
        };
        $qm_title = $qboMapping['qbo_vendor_id'] ? 'qbo#' . $qboMapping['qbo_vendor_id'] : '';
        if (!empty($qboMapping['last_synced_at'])) {
            $qm_title .= ($qm_title !== '' ? ' · ' : '') . 'last synced ' . $qboMapping['last_synced_at'];
        }
    ?>
    <a href="<?= base_url('quickbooks/vendors') ?>?q=<?= e(rawurlencode($vendor['name'])) ?>"
       class="badge <?= $qm_class ?>"
       title="<?= e($qm_title) ?>"
       style="text-decoration:none;">
        <?= e($qm_label) ?>
    </a>
    <?php endif; ?>
    <div style="display:flex;gap:8px;margin-left:auto;">
        <?php if (can('maintenance', 'edit')): ?>
        <button id="btn-edit" class="btn btn-secondary btn-sm"
                onclick="showEdit()">Edit</button>
        <?php endif; ?>
        <?php if (can('maintenance', 'delete')): ?>
        <button class="btn btn-danger btn-sm"
                onclick="document.getElementById('vendor-delete-modal').style.display='flex'">Delete</button>
        <?php endif; ?>
    </div>
    <div class="page-header-actions">
        <?= help_button('vendors') ?>
        <?php if (function_exists('can') && can('ai', 'view') && (bool)settings_get('ai.enabled', false) && (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''))): ?>
        <button type="button" class="btn btn-secondary btn-sm no-print"
                onclick="aiPanel_vendor_<?= (int)$vendorId ?>_vendor_summary_open()"
                title="Open AI Vendor Summary">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;margin-right:4px;vertical-align:-2px;" aria-hidden="true">
                <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="currentColor"/>
            </svg>
            AI Analysis
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- TILES-2: vendor-level tiles drill to the maintenance work orders list
     filtered by this vendor. Each tile applies a different status filter
     so the user lands on exactly the subset represented by the counter. -->
<div class="stat-grid" style="margin-bottom:24px;">

    <a class="stat-card"
       href="<?= base_url('maintenance_work_orders') ?>?vendor_id=<?= (int)$vendor['id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="View all work orders for this vendor">
        <div class="stat-label">Total Spent</div>
        <div class="stat-value font-mono"><?= format_currency($vendor['total_spent']) ?></div>
        <div class="stat-delta">all work orders</div>
    </a>

    <a class="stat-card"
       href="<?= base_url('maintenance_work_orders') ?>?vendor_id=<?= (int)$vendor['id'] ?>&status=open"
       style="cursor:pointer;text-decoration:none"
       title="View open / in-progress / waiting-parts work orders">
        <div class="stat-label">Active Work Orders</div>
        <div class="stat-value font-mono"><?= e($woOpen) ?></div>
        <div class="stat-delta">open / in progress / waiting parts</div>
    </a>

    <a class="stat-card"
       href="<?= base_url('maintenance_work_orders') ?>?vendor_id=<?= (int)$vendor['id'] ?>&status=completed"
       style="cursor:pointer;text-decoration:none"
       title="View completed work orders for this vendor">
        <div class="stat-label">Completed</div>
        <div class="stat-value font-mono"><?= e($woCompleted) ?></div>
        <div class="stat-delta">finished work orders</div>
    </a>

    <a class="stat-card"
       href="<?= base_url('maintenance_work_orders') ?>?vendor_id=<?= (int)$vendor['id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="View every work order for this vendor">
        <div class="stat-label">Total Work Orders</div>
        <div class="stat-value font-mono"><?= e($woTotal) ?></div>
        <div class="stat-delta">all time</div>
    </a>

</div>

<!-- ── Vendor Details card ───────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="font-weight:600;">Vendor Details</div>

    <!-- VIEW MODE — always server-rendered -->
    <div id="vendor-view-section" class="card-body">
        <dl class="detail-grid">
            <dt>Name</dt>
            <dd><?= e($vendor['name']) ?>
                <?php if ($vendor['is_preferred']): ?>
                <span class="badge badge-success" style="margin-left:8px;font-size:0.7rem;">Preferred</span>
                <?php endif; ?>
            </dd>

            <dt>Type</dt>
            <dd>
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
                $addr = array_filter([$vendor['address'], $vendor['city'], $vendor['state']]);
                echo $addr ? e(implode(', ', $addr)) : '—';
                ?>
            </dd>

            <dt>Hourly Rate</dt>
            <dd class="font-mono"><?= $vendor['hourly_rate'] ? format_currency($vendor['hourly_rate']) . '/hr' : '—' ?></dd>

            <dt>Currency</dt>
            <dd class="font-mono"><?= e($vendor['currency'] ?? 'CAD') ?></dd>

            <dt>Rating</dt>
            <dd>
                <?php if ($vendor['rating']): ?>
                <span style="color:var(--color-warning);"><?= str_repeat('★', (int)$vendor['rating']) ?></span>
                <span class="text-secondary"><?= e($vendor['rating']) ?>/5</span>
                <?php else: ?>—<?php endif; ?>
            </dd>

            <dt>Specializations</dt>
            <dd>
                <?php if ($specializations): ?>
                <?php foreach ($specializations as $spec): ?>
                <span class="badge badge-info" style="margin-right:4px;"><?= e($spec) ?></span>
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
    </div>

    <!-- EDIT MODE — hidden until showEdit() called -->
    <div id="vendor-edit-section" style="display:none;" class="card-body">

        <div id="vendor-edit-error" class="alert alert-danger"
             style="display:none;margin-bottom:16px;"></div>

        <form id="vendor-edit-form" onsubmit="return false;">

            <!-- D19 optimistic lock token -->
            <input type="hidden" id="edit-updated-at" value="<?= e($vendor['updated_at']) ?>">

            <!-- Identity -->
            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Vendor Name *</label>
                    <input type="text" id="edit-name" class="form-control"
                           value="<?= e($vendor['name']) ?>" maxlength="255">
                </div>
                <div>
                    <label class="form-label">Vendor Type *</label>
                    <select id="edit-vendor-type" class="form-control">
                        <option value="maintenance" <?= $vendor['vendor_type'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="repair"      <?= $vendor['vendor_type'] === 'repair'      ? 'selected' : '' ?>>Repair</option>
                        <option value="parts"       <?= $vendor['vendor_type'] === 'parts'       ? 'selected' : '' ?>>Parts</option>
                        <option value="inspection"  <?= $vendor['vendor_type'] === 'inspection'  ? 'selected' : '' ?>>Inspection</option>
                        <option value="towing"      <?= $vendor['vendor_type'] === 'towing'      ? 'selected' : '' ?>>Towing</option>
                        <option value="other"       <?= $vendor['vendor_type'] === 'other'       ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
            </div>

            <!-- Contact -->
            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Contact Name</label>
                    <input type="text" id="edit-contact-name" class="form-control"
                           value="<?= e($vendor['contact_name'] ?? '') ?>" maxlength="255">
                </div>
                <div>
                    <label class="form-label">Email</label>
                    <input type="email" id="edit-email" class="form-control"
                           value="<?= e($vendor['email'] ?? '') ?>" maxlength="255">
                </div>
            </div>

            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Phone</label>
                    <input type="text" id="edit-phone" class="form-control"
                           value="<?= e($vendor['phone'] ?? '') ?>" maxlength="50">
                </div>
                <div><!-- blank for grid alignment --></div>
            </div>

            <!-- Location -->
            <div style="margin-bottom:16px;">
                <label class="form-label">Address</label>
                <input type="text" id="edit-address" class="form-control"
                       value="<?= e($vendor['address'] ?? '') ?>" maxlength="500">
            </div>
            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">City</label>
                    <input type="text" id="edit-city" class="form-control"
                           value="<?= e($vendor['city'] ?? '') ?>" maxlength="100">
                </div>
                <div>
                    <label class="form-label">Province / State</label>
                    <input type="text" id="edit-state" class="form-control"
                           value="<?= e($vendor['state'] ?? '') ?>" maxlength="100">
                </div>
            </div>

            <!-- Rates & Rating -->
            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Hourly Rate ($)</label>
                    <input type="number" min="0" id="edit-hourly-rate" class="form-control"
                           value="<?= e($vendor['hourly_rate'] ?? '') ?>"
                           min="0" step="0.01">
                </div>
                <div>
                    <label class="form-label">Rating (1–5)</label>
                    <select id="edit-rating" class="form-control">
                        <option value="">— Not rated —</option>
                        <option value="1" <?= (string)$vendor['rating'] === '1' ? 'selected' : '' ?>>★ 1 — Poor</option>
                        <option value="2" <?= (string)$vendor['rating'] === '2' ? 'selected' : '' ?>>★★ 2 — Fair</option>
                        <option value="3" <?= (string)$vendor['rating'] === '3' ? 'selected' : '' ?>>★★★ 3 — Good</option>
                        <option value="4" <?= (string)$vendor['rating'] === '4' ? 'selected' : '' ?>>★★★★ 4 — Very Good</option>
                        <option value="5" <?= (string)$vendor['rating'] === '5' ? 'selected' : '' ?>>★★★★★ 5 — Excellent</option>
                    </select>
                </div>
            </div>

            <!-- Specializations -->
            <div style="margin-bottom:16px;">
                <label class="form-label">Specializations (hold Ctrl/Cmd to select multiple)</label>
                <select id="edit-specializations" class="form-control" multiple size="6">
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
            </div>

            <!-- Preferred + Notes -->
            <div style="margin-bottom:16px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="edit-is-preferred"
                           <?= $vendor['is_preferred'] ? 'checked' : '' ?>>
                    <span class="form-label" style="margin:0;">Mark as Preferred Vendor</span>
                </label>
            </div>

            <div style="margin-bottom:24px;">
                <label class="form-label">Notes</label>
                <textarea id="edit-notes" class="form-control" rows="4"><?= e($vendor['notes'] ?? '') ?></textarea>
            </div>

            <!-- Edit actions -->
            <div style="display:flex;gap:12px;padding-top:16px;border-top:1px solid var(--border-default);">
                <button id="btn-save" type="button" class="btn btn-primary"
                        onclick="saveVendor()">Save Changes</button>
                <button type="button" class="btn btn-secondary"
                        onclick="cancelEdit()">Cancel</button>
            </div>

        </form>
    </div><!-- /vendor-edit-section -->

</div><!-- /card -->

<!-- ── Work Orders (Alpine — paginated + filtered) ────────────────────────── -->
<div class="card" x-data="FF_VendorWorkOrders()" x-init="init()">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span style="font-weight:600;">
            Work Orders
            <span class="badge badge-neutral" style="margin-left:8px;"><?= e($woTotal) ?></span>
        </span>
        <?php if (can('maintenance', 'create')): ?>
        <a href="<?= base_url('maintenance_work_orders/create') ?>?vendor_id=<?= $vendorId ?>"
           class="btn btn-primary btn-sm">+ New Work Order</a>
        <?php endif; ?>
    </div>

    <!-- Filter bar -->
    <div class="tab-filter-bar">
        <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                x-model="filters.status" @change="applyFilters()">
            <option value="">All Statuses</option>
            <option value="open">Open</option>
            <option value="in_progress">In Progress</option>
            <option value="waiting_parts">Waiting Parts</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
        </select>
        <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                x-model="filters.work_type" @change="applyFilters()">
            <option value="">All Types</option>
            <option value="repair">Repair</option>
            <option value="preventive">Preventive</option>
            <option value="inspection">Inspection</option>
            <option value="emergency">Emergency</option>
            <option value="other">Other</option>
        </select>
        <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                x-model="filters.sort" @change="applyFilters()">
            <option value="created_at">Sort: Date Added</option>
            <option value="requested_date">Sort: Requested Date</option>
            <option value="total_cost">Sort: Total Cost</option>
        </select>
        <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                x-model="filters.dir" @change="applyFilters()">
            <option value="DESC">Newest First</option>
            <option value="ASC">Oldest First</option>
        </select>
    </div>

    <!-- Loading -->
    <div x-show="loading && items.length === 0" class="card-body" style="text-align:center;padding:32px;">
        <span class="text-secondary">Loading work orders…</span>
    </div>

    <!-- Empty state -->
    <div x-show="loaded && !loading && items.length === 0" class="card-body">
        <div class="empty-state">
            <p class="empty-state-title">No work orders found</p>
            <p class="empty-state-text">No work orders match the current filters.</p>
        </div>
    </div>

    <!-- Table + footer -->
    <div x-show="items.length > 0">
        <div class="tab-table-container">
            <div class="table-responsive">
<table class="table">
                <thead>
                    <tr>
                        <th>Work Order #</th>
                        <th>Unit</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th style="text-align:right;">Total Cost</th>
                        <th>Requested</th>
                        <th>Completed</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="wo in items" :key="wo.id">
                        <tr>
                            <td class="font-mono" x-text="wo.work_order_number"></td>
                            <td class="font-mono" x-text="wo.unit_number || '—'"></td>
                            <td x-text="wo.work_type ? wo.work_type.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) : '—'"></td>
                            <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="wo.title"></td>
                            <td>
                                <span class="badge" :class="woBadge(wo.status)" x-text="woLabel(wo.status)"></span>
                            </td>
                            <td class="font-mono" style="text-align:right;"
                                x-text="wo.total_cost > 0 ? '$' + parseFloat(wo.total_cost).toLocaleString('en-CA',{minimumFractionDigits:2}) : '—'"></td>
                            <td x-text="wo.requested_date || '—'"></td>
                            <td x-text="wo.completed_date || '—'"></td>
                            <td>
                                <a :href="'<?= base_url('maintenance_work_orders/show') ?>?id=' + wo.id"
                                   class="btn btn-secondary btn-sm">View</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
</div>
        </div>
        <div class="tab-table-footer">
            <span x-text="`Showing ${items.length} of ${total}`"></span>
            <button class="btn btn-secondary btn-sm"
                    x-show="items.length < total"
                    :disabled="loading"
                    @click="loadMore()"
                    x-text="loading ? 'Loading…' : 'Load more'">
            </button>
        </div>
    </div>
</div><!-- /work orders card -->

<!-- ── Equipment Worked On ───────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;margin-top:24px;">
    <div class="card-header" style="font-weight:600;">
        Equipment Worked On
        <span class="badge badge-neutral" style="margin-left:8px;"><?= e(count($unitHistory)) ?></span>
    </div>

    <?php if (empty($unitHistory)): ?>
    <div class="card-body">
        <div class="empty-state">
            <p class="empty-state-title">No equipment history</p>
            <p class="empty-state-text">Equipment units serviced by this vendor will appear here once work orders are assigned.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="tab-table-container">
        <div class="table-responsive">
<table class="table">
            <thead>
                <tr>
                    <th>Unit #</th>
                    <th>Equipment</th>
                    <th style="text-align:right;">Services</th>
                    <th>Last Service</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unitHistory as $u): ?>
                <tr>
                    <td>
                        <a href="<?= base_url('equipment/show') ?>?id=<?= e($u['id']) ?>"
                           class="link font-mono"><?= e($u['unit_number']) ?></a>
                    </td>
                    <td>
                        <?php
                        $unitDesc = implode(' ', array_filter([$u['year'], $u['brand'], $u['model']]));
                        echo $unitDesc ? e($unitDesc) : '—';
                        ?>
                    </td>
                    <td class="font-mono" style="text-align:right;"><?= e($u['service_count']) ?></td>
                    <td><?= $u['last_service_date'] ? format_date($u['last_service_date']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Active Lease Exposure ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Active Lease Exposure
        <span class="badge badge-neutral"><?= e(count($leaseExposure)) ?></span>
        <?php if (!empty($leaseExposure)): ?>
        <span class="badge badge-warning" style="font-size:0.7rem;">Units currently on lease</span>
        <?php endif; ?>
    </div>

    <?php if (empty($leaseExposure)): ?>
    <div class="card-body">
        <div class="empty-state">
            <p class="empty-state-title">No active lease exposure</p>
            <p class="empty-state-text">None of the units serviced by this vendor are currently on an active lease.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="tab-table-container">
        <div class="table-responsive">
<table class="table">
            <thead>
                <tr>
                    <th>Unit #</th>
                    <th>Customer</th>
                    <th>Lease #</th>
                    <th>Status</th>
                    <th>Start</th>
                    <th>End</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leaseExposure as $le): ?>
                <?php
                $leaseBadge = $le['status'] === 'active' ? 'badge-success' : 'badge-warning';
                ?>
                <tr>
                    <td>
                        <a href="<?= base_url('equipment/show') ?>?id=<?= e($le['unit_id']) ?>"
                           class="link font-mono"><?= e($le['unit_number']) ?></a>
                    </td>
                    <td>
                        <a href="<?= base_url('customers/show') ?>?id=<?= e($le['customer_id']) ?>"
                           class="link"><?= e($le['company_name']) ?></a>
                    </td>
                    <td>
                        <a href="<?= base_url('leases/show') ?>?id=<?= e($le['lease_id']) ?>"
                           class="link font-mono"><?= e($le['contract_number']) ?></a>
                    </td>
                    <td><span class="badge <?= e($leaseBadge) ?>"><?= e(ucfirst($le['status'])) ?></span></td>
                    <td><?= format_date($le['start_date']) ?></td>
                    <td><?= $le['end_date'] ? format_date($le['end_date']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Delete modal ─────────────────────────────────────────────────────── -->
<div id="vendor-delete-modal" class="modal-backdrop" style="display:none;"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title">Delete Vendor</h3>
            <button class="btn-icon"
                    onclick="document.getElementById('vendor-delete-modal').style.display='none'">✕</button>
        </div>
        <div class="modal-body">
            <div id="vendor-delete-error" class="alert alert-danger"
                 style="display:none;margin-bottom:12px;"></div>
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
                    onclick="document.getElementById('vendor-delete-modal').style.display='none'">Cancel</button>
            <button id="btn-delete-confirm" class="btn btn-danger"
                    onclick="confirmDelete()">Delete Vendor</button>
        </div>
    </div>
</div>

<script>
// ── Work Orders Alpine component ─────────────────────────────────────────────
function FF_VendorWorkOrders() {
    return {
        items:   [],
        total:   0,
        page:    1,
        loading: false,
        loaded:  false,
        filters: { status: '', work_type: '', sort: 'created_at', dir: 'DESC' },

        init() { this.load(); },

        async load(append = false) {
            this.loading = true;
            try {
                const p = new URLSearchParams({ vendor_id: <?= $vendorId ?>, per_page: 50, page: this.page, sort: this.filters.sort, dir: this.filters.dir });
                if (this.filters.status)    p.set('status',    this.filters.status);
                if (this.filters.work_type) p.set('work_type', this.filters.work_type);
                const r    = await FF_Api.get('<?= base_url('api/v1/maintenance_work_orders/index.php') ?>?' + p);
                if (r.success) {
                    const rows  = r.data?.items ?? [];
                    this.items  = append ? [...this.items, ...rows] : rows;
                    this.total  = r.data.pagination?.total ?? rows.length;
                    this.loaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.loading = false;
        },

        loadMore()    { this.page++; this.load(true); },
        applyFilters(){ this.items = []; this.page = 1; this.total = 0; this.loaded = false; this.load(); },

        woBadge(s) {
            return { open:'badge badge-info', in_progress:'badge badge-warning', waiting_parts:'badge badge-warning',
                     completed:'badge badge-success', cancelled:'badge badge-neutral' }[s] ?? 'badge badge-neutral';
        },
        woLabel(s) {
            return { open:'Open', in_progress:'In Progress', waiting_parts:'Waiting Parts',
                     completed:'Completed', cancelled:'Cancelled' }[s] ?? s;
        },
    };
}

// ── Edit / Cancel ────────────────────────────────────────────────────────────
function showEdit() {
    document.getElementById('vendor-view-section').style.display = 'none';
    document.getElementById('vendor-edit-section').style.display = 'block';
    document.getElementById('btn-edit').style.display = 'none';
    document.getElementById('vendor-edit-error').style.display = 'none';
}

function cancelEdit() {
    document.getElementById('vendor-edit-section').style.display = 'none';
    document.getElementById('vendor-view-section').style.display = 'block';
    document.getElementById('btn-edit').style.display = '';
}

// ── Save ─────────────────────────────────────────────────────────────────────
async function saveVendor() {
    const errEl  = document.getElementById('vendor-edit-error');
    const btnSave = document.getElementById('btn-save');
    errEl.style.display = 'none';

    const name = document.getElementById('edit-name').value.trim();
    const type = document.getElementById('edit-vendor-type').value;

    if (!name) {
        errEl.textContent = 'Vendor name is required.';
        errEl.style.display = 'block';
        return;
    }
    if (!type) {
        errEl.textContent = 'Vendor type is required.';
        errEl.style.display = 'block';
        return;
    }

    // Collect selected specializations
    const specSelect = document.getElementById('edit-specializations');
    const specializations = Array.from(specSelect.selectedOptions).map(o => o.value);

    const payload = {
        id:              <?= $vendorId ?>,
        updated_at:      document.getElementById('edit-updated-at').value,  // D19 lock
        name:            name,
        vendor_type:     type,
        contact_name:    document.getElementById('edit-contact-name').value.trim() || null,
        email:           document.getElementById('edit-email').value.trim() || null,
        phone:           document.getElementById('edit-phone').value.trim() || null,
        address:         document.getElementById('edit-address').value.trim() || null,
        city:            document.getElementById('edit-city').value.trim() || null,
        state:           document.getElementById('edit-state').value.trim() || null,
        specializations: specializations,
        hourly_rate:     document.getElementById('edit-hourly-rate').value || null,
        rating:          document.getElementById('edit-rating').value
                            ? parseInt(document.getElementById('edit-rating').value) : null,
        is_preferred:    document.getElementById('edit-is-preferred').checked ? 1 : 0,
        notes:           document.getElementById('edit-notes').value.trim() || null,
    };

    btnSave.disabled = true;
    btnSave.textContent = 'Saving…';

    try {
        const res = await fetch('<?= base_url('api/v1/vendors/update.php') ?>', {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token':     document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(payload),
        });
        const d = await res.json();
        if (!res.ok) {
            throw d;
        }
        // Refresh page to show new server-rendered values
        window.location.reload();
    } catch (err) {
        errEl.textContent = err?.data?.message ?? err?.message ?? 'Save failed. Please try again.';
        errEl.style.display = 'block';
        btnSave.disabled = false;
        btnSave.textContent = 'Save Changes';
    }
}

// ── Delete ───────────────────────────────────────────────────────────────────
async function confirmDelete() {
    const errEl  = document.getElementById('vendor-delete-error');
    const btnDel = document.getElementById('btn-delete-confirm');
    errEl.style.display = 'none';

    btnDel.disabled = true;
    btnDel.textContent = 'Deleting…';

    try {
        const res = await fetch('<?= base_url('api/v1/vendors/delete.php') ?>', {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token':     document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ id: <?= $vendorId ?> }),
        });
        const d = await res.json();
        if (!res.ok) {
            throw d;
        }
        window.location = '<?= base_url('vendors') ?>';
    } catch (err) {
        errEl.textContent = err?.data?.message ?? err?.message ?? 'Delete failed. Please try again.';
        errEl.style.display = 'block';
        btnDel.disabled = false;
        btnDel.textContent = 'Delete Vendor';
    }
}
</script>

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card" style="margin-top:24px;">
    <div class="card-header"><h3 class="card-title">Activity</h3></div>
    <div class="card-body">
        <?php $activityEntityType = 'vendor'; $activityEntityId = $vendorId; ?>
        <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
    </div>
</div>

<?php
// ── AI Vendor Summary panel (S-AI-SUMMARY-PANELS) ──
$aiSummaryEntityType = 'vendor';
$aiSummaryEntityId   = $vendorId;
$aiSummaryType       = 'vendor_summary';
$aiSummaryTitle      = 'Vendor Summary — ' . ($vendor['name'] ?? ''); // raw — ai-panel.php escapes
require_once FF_ROOT . '/includes/partials/ai-panel.php';
?>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
