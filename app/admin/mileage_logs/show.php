<?php
declare(strict_types=1);

// ============================================================
// /mileage_logs/show?id=N — Mileage Log detail
//
// Shows full detail for one mileage log entry.
// - Read-only for system types (gps_sync, lease_start, lease_end)
// - Edit mode available for manual and service types
// - Delete blocked for lease_start / lease_end types
//
// Permission: maintenance view
// ============================================================

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();
require_permission('maintenance', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    header('Location: ' . base_url('mileage_logs'));
    exit;
}

// ── Load record
$log = db_row(
    "SELECT
        ml.*,
        eu.unit_number,
        et.brand,
        et.model,
        et.category AS unit_category,
        u.name AS recorded_by_name
     FROM mileage_logs ml
     JOIN equipment_units eu ON eu.id = ml.equipment_unit_id AND eu.deleted_at IS NULL
     JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     LEFT JOIN users u ON u.id = ml.recorded_by
     WHERE ml.id = ?",
    [$id]
);

if (!$log) {
    header('Location: ' . base_url('mileage_logs'));
    exit;
}

// ── Load linked lease (if any)
$lease = null;
if ($log['lease_id']) {
    $lease = db_row(
        "SELECT l.id, l.contract_number, l.status, c.company_name
         FROM leases l
         JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
         WHERE l.id = ? AND l.deleted_at IS NULL",
        [$log['lease_id']]
    );
}

// ── Flags
$isEditable  = in_array($log['log_type'], ['manual', 'service'], true) && can('maintenance', 'edit');
$isDeletable = !in_array($log['log_type'], ['lease_start', 'lease_end'], true) && can('maintenance', 'delete');
$isImmutable = in_array($log['log_type'], ['gps_sync', 'lease_start', 'lease_end'], true);

$typeLabelMap = [
    'manual'      => 'Manual',
    'gps_sync'    => 'GPS Sync',
    'lease_start' => 'Lease Start',
    'lease_end'   => 'Lease End',
    'service'     => 'Service',
];
$typeBadgeMap = [
    'manual'      => 'badge-info',
    'gps_sync'    => 'badge-success',
    'lease_start' => 'badge-neutral',
    'lease_end'   => 'badge-neutral',
    'service'     => 'badge-warning',
];

$pageTitle = 'Mileage Log #' . $id;
require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-header-title">Mileage Log #<?= e($id) ?></h1>
        <nav style="font-size:.875rem;color:var(--text-secondary);">
            <a href="<?= base_url('mileage_logs') ?>" class="link">Mileage Logs</a>
            &rsaquo; #<?= e($id) ?>
        </nav>
    </div>
    <div style="display:flex;gap:.5rem;">
        <?php if ($isEditable): ?>
        <button class="btn btn-secondary" onclick="document.getElementById('edit-section').style.display='block';this.style.display='none';">
            Edit
        </button>
        <?php endif; ?>
        <?php if ($isDeletable): ?>
        <button class="btn btn-danger" onclick="document.getElementById('delete-modal').style.display='flex';">
            Delete
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Summary tiles -->
<div class="stat-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-value font-mono"><?= e(format_mileage((int)$log['odometer_reading'], $log['mileage_unit'])) ?></div>
        <div class="stat-label">Odometer Reading</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e(format_date($log['log_date'])) ?></div>
        <div class="stat-label">Log Date</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">
            <span class="badge <?= e($typeBadgeMap[$log['log_type']] ?? 'badge-neutral') ?>">
                <?= e($typeLabelMap[$log['log_type']] ?? $log['log_type']) ?>
            </span>
        </div>
        <div class="stat-label">Entry Type</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="font-size:1rem;"><?= e($log['unit_number']) ?></div>
        <div class="stat-label">Equipment Unit</div>
    </div>
</div>

<?php if ($isImmutable): ?>
<div class="alert alert-info" style="margin-bottom:1rem;">
    This entry was created automatically by the system (<?= e($typeLabelMap[$log['log_type']]) ?>)
    and cannot be edited.
</div>
<?php endif; ?>

<!-- Detail card -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <div class="card-title">Entry Details</div>
    </div>
    <div class="card-body">
        <dl class="detail-grid">
            <dt>Equipment Unit</dt>
            <dd>
                <a href="<?= base_url('equipment/show') ?>?id=<?= e($log['equipment_unit_id']) ?>" class="link">
                    <?= e($log['unit_number']) ?>
                </a>
                <span style="color:var(--text-secondary);"> — <?= e($log['brand']) ?> <?= e($log['model']) ?></span>
            </dd>

            <dt>Linked Lease</dt>
            <dd>
                <?php if ($lease): ?>
                <a href="<?= base_url('leases/show') ?>?id=<?= e($lease['id']) ?>" class="link">
                    <?= e($lease['contract_number']) ?>
                </a>
                <span style="color:var(--text-secondary);"> — <?= e($lease['company_name']) ?></span>
                <?php else: ?>
                <span style="color:var(--text-secondary);">—</span>
                <?php endif; ?>
            </dd>

            <dt>Odometer</dt>
            <dd class="font-mono"><?= e(format_mileage((int)$log['odometer_reading'], $log['mileage_unit'])) ?></dd>

            <dt>Entry Type</dt>
            <dd>
                <span class="badge <?= e($typeBadgeMap[$log['log_type']] ?? 'badge-neutral') ?>">
                    <?= e($typeLabelMap[$log['log_type']] ?? $log['log_type']) ?>
                </span>
            </dd>

            <dt>Log Date</dt>
            <dd><?= e(format_date($log['log_date'])) ?></dd>

            <dt>Recorded By</dt>
            <dd><?= e($log['recorded_by_name'] ?? '—') ?></dd>

            <dt>Notes</dt>
            <dd><?= $log['notes'] ? e($log['notes']) : '<span style="color:var(--text-secondary);">—</span>' ?></dd>

            <dt>Created At</dt>
            <dd><?= e(format_datetime($log['created_at'])) ?></dd>
        </dl>
    </div>
</div>

<?php if ($isEditable): ?>
<!-- Edit section (hidden by default) -->
<div id="edit-section" class="card" style="display:none;margin-bottom:1.5rem;">
    <div class="card-header">
        <div class="card-title">Edit Entry</div>
    </div>
    <div class="card-body">
        <div class="form-error-banner" id="edit-error" style="display:none;"></div>
        <form id="edit-form" novalidate>
            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label" for="edit_odometer">Odometer Reading</label>
                    <input type="number" class="form-control" id="edit_odometer" name="odometer_reading"
                           min="0" step="1" value="<?= e($log['odometer_reading']) ?>">
                    <div class="field-error" id="err-odometer_reading"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_unit">Unit</label>
                    <select class="form-control" id="edit_unit" name="mileage_unit">
                        <option value="km"    <?= $log['mileage_unit'] === 'km'    ? 'selected' : '' ?>>km</option>
                        <option value="miles" <?= $log['mileage_unit'] === 'miles' ? 'selected' : '' ?>>miles</option>
                    </select>
                    <div class="field-error" id="err-mileage_unit"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="edit_date">Log Date</label>
                <input type="date" class="form-control" id="edit_date" name="log_date"
                       value="<?= e($log['log_date']) ?>" max="<?= e(date('Y-m-d')) ?>">
                <div class="field-error" id="err-log_date"></div>
            </div>
            <div class="form-group">
                <label class="form-label" for="edit_notes">Notes</label>
                <textarea class="form-control" id="edit_notes" name="notes" rows="3"><?= e($log['notes'] ?? '') ?></textarea>
                <div class="field-error" id="err-notes"></div>
            </div>
            <!-- Optimistic lock: use created_at since no updated_at on this table -->
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="created_at" value="<?= e($log['created_at']) ?>">
            <div style="display:flex;gap:.75rem;padding-top:1rem;border-top:1px solid var(--border-color);">
                <button type="submit" class="btn btn-primary" id="edit-btn">Save Changes</button>
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('edit-section').style.display='none';document.querySelector('[onclick*=edit-section]').style.display='';">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>
<script>
// Clear/paint helpers for the edit form
function mlogEditClear() {
    const errEl = document.getElementById('edit-error');
    errEl.style.display = 'none';
    errEl.textContent = '';
    ['odometer_reading','mileage_unit','log_date','notes'].forEach(f => {
        const el = document.getElementById('err-' + f);
        if (el) { el.textContent = ''; el.style.display = 'none'; }
    });
}
function mlogEditPaint(fields, topMsg) {
    const errEl = document.getElementById('edit-error');
    errEl.textContent = topMsg || 'Please fix the errors below and try again.';
    errEl.style.display = 'block';
    for (const [f, msg] of Object.entries(fields || {})) {
        const el = document.getElementById('err-' + f);
        if (el) { el.textContent = msg; el.style.display = 'block'; }
    }
}

document.getElementById('edit-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    mlogEditClear();

    // Client-side validation
    const cErrs  = {};
    const odo    = document.getElementById('edit_odometer').value;
    const dt     = document.getElementById('edit_date').value;
    if (odo !== '' && (isNaN(parseInt(odo)) || parseInt(odo) < 0)) {
        cErrs.odometer_reading = 'Odometer cannot be negative.';
    }
    if (dt) {
        const today = new Date().toISOString().split('T')[0];
        if (dt > today) cErrs.log_date = 'Log date cannot be in the future.';
    }
    if (Object.keys(cErrs).length) {
        mlogEditPaint(cErrs, 'Please fix the errors below and try again.');
        return;
    }

    const btn = document.getElementById('edit-btn');
    btn.disabled = true; btn.textContent = 'Saving…';

    try {
        const res  = await fetch('<?= base_url('api/v1/mileage_logs/update') ?>', {
            method: 'POST',
            body: new FormData(this),
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });
        const data = await res.json();
        if (!data.success) {
            const err = data.error || {};
            const code = err.code || data.code;
            const fields = err.fields || {};
            if (code === 'STALE_DATA') {
                mlogEditPaint({}, 'This entry was modified by another user. Please refresh the page and try again.');
            } else {
                mlogEditPaint(fields, err.message || data.message || 'Update failed.');
            }
            btn.disabled = false; btn.textContent = 'Save Changes';
            return;
        }
        window.location.reload();
    } catch(err) {
        mlogEditPaint({}, 'Network error. Please try again.');
        btn.disabled = false; btn.textContent = 'Save Changes';
    }
});
</script>
<?php endif; ?>

<?php if ($isDeletable): ?>
<!-- Delete modal -->
<div id="delete-modal" class="modal-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div class="card" style="max-width:420px;width:90%;">
        <div class="card-header">
            <div class="card-title">Delete Mileage Entry?</div>
        </div>
        <div class="card-body">
            <p>This will permanently delete the mileage entry for
                <strong><?= e(format_mileage((int)$log['odometer_reading'], $log['mileage_unit'])) ?></strong>
                on <?= e(format_date($log['log_date'])) ?>. This action cannot be undone.</p>
            <div id="delete-error" class="alert alert-danger" style="display:none;"></div>
            <div style="display:flex;gap:.75rem;margin-top:1rem;">
                <button class="btn btn-danger" id="confirm-delete-btn" onclick="confirmDelete()">
                    Delete
                </button>
                <button class="btn btn-ghost" onclick="document.getElementById('delete-modal').style.display='none';">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>
<script>
async function confirmDelete() {
    const btn   = document.getElementById('confirm-delete-btn');
    const errEl = document.getElementById('delete-error');
    errEl.style.display = 'none';
    btn.disabled = true; btn.textContent = 'Deleting…';
    try {
        const form = new FormData();
        form.append('id', '<?= e($id) ?>');
        const res  = await fetch('<?= base_url('api/v1/mileage_logs/delete') ?>', {
            method: 'POST', body: form,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });
        const data = await res.json();
        if (!data.success) {
            const err = data.error || {};
            const fields = err.fields || {};
            const msgs = Object.values(fields);
            errEl.textContent = msgs.length ? msgs.join(' ') : (err.message || data.message || 'Delete failed.');
            errEl.style.display = 'block';
            btn.disabled = false; btn.textContent = 'Delete';
            return;
        }
        window.location.href = '<?= base_url('mileage_logs') ?>';
    } catch(err) {
        errEl.textContent = 'Network error. Please try again.';
        errEl.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Delete';
    }
}
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
