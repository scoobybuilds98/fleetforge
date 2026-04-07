<?php
declare(strict_types=1);

// ============================================================
// /mileage_logs/create — Record a mileage log entry
//
// Supports pre-population from query string:
//   ?equipment_unit_id=N  — pre-select unit
//   ?lease_id=N           — pre-select lease
//
// Permission: maintenance create
// ============================================================

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();
require_permission('maintenance', 'create');

// Pre-population from query string (e.g. from unit or lease profile)
$preUnitId  = clean_int($_GET['equipment_unit_id'] ?? null);
$preLeaseId = clean_int($_GET['lease_id'] ?? null);

// ── Load active equipment units for dropdown (joins template for label)
$units = db_select(
    "SELECT eu.id, eu.unit_number, et.brand, et.model
     FROM equipment_units eu
     JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE eu.deleted_at IS NULL
     ORDER BY eu.unit_number ASC",
    []
);

// ── Load active leases for dropdown (only active ones make sense for mileage)
$leases = db_select(
    "SELECT l.id, l.contract_number, eu.unit_number, c.company_name
     FROM leases l
     JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
     JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     WHERE l.status IN ('active','pending') AND l.deleted_at IS NULL
     ORDER BY l.contract_number ASC",
    []
);

$pageTitle = 'Record Mileage';
require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-header-title">Record Mileage</h1>
        <nav style="font-size:.875rem;color:var(--text-secondary);">
            <a href="<?= base_url('mileage_logs') ?>" class="link">Mileage Logs</a>
            &rsaquo; Record Mileage
        </nav>
    </div>
</div>

<div id="mileage-page-wrapper" x-data="{ showSuccessOverlay: false }">

<div class="card" style="max-width:640px;">
    <div class="card-header">
        <div class="card-title">New Mileage Entry</div>
    </div>
    <div class="card-body">

        <div class="form-error-banner" id="js-error" style="display:none;"></div>

        <form id="mileage-form" novalidate>
            <!-- Equipment Unit -->
            <div class="form-group">
                <label class="form-label" for="equipment_unit_id">Equipment Unit <span style="color:var(--danger);">*</span></label>
                <select class="form-control" id="equipment_unit_id" name="equipment_unit_id" required>
                    <option value="">— Select unit —</option>
                    <?php foreach ($units as $u): ?>
                    <option value="<?= e($u['id']) ?>"
                        <?= ($preUnitId === (int)$u['id']) ? 'selected' : '' ?>>
                        <?= e($u['unit_number']) ?> — <?= e($u['brand']) ?> <?= e($u['model']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-error" id="err-equipment_unit_id"></div>
            </div>

            <!-- Lease (optional) -->
            <div class="form-group">
                <label class="form-label" for="lease_id">
                    Linked Lease
                    <span style="color:var(--text-secondary);font-weight:400;">(optional)</span>
                </label>
                <select class="form-control" id="lease_id" name="lease_id">
                    <option value="">— None —</option>
                    <?php foreach ($leases as $l): ?>
                    <option value="<?= e($l['id']) ?>"
                        <?= ($preLeaseId === (int)$l['id']) ? 'selected' : '' ?>>
                        <?= e($l['contract_number']) ?> — <?= e($l['unit_number']) ?> (<?= e($l['company_name']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:.8rem;color:var(--text-secondary);margin-top:.25rem;">
                    Only active/pending leases shown. Links this reading to the lease's mileage history.
                </div>
                <div class="field-error" id="err-lease_id"></div>
            </div>

            <!-- Log Type -->
            <div class="form-group">
                <label class="form-label" for="log_type">Entry Type <span style="color:var(--danger);">*</span></label>
                <select class="form-control" id="log_type" name="log_type" required>
                    <option value="manual" selected>Manual — admin entry</option>
                    <option value="service">Service — recorded at maintenance</option>
                </select>
                <div style="font-size:.8rem;color:var(--text-secondary);margin-top:.25rem;">
                    System types (GPS Sync, Lease Start/End) are recorded automatically.
                </div>
                <div class="field-error" id="err-log_type"></div>
            </div>

            <!-- Odometer + Unit row -->
            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label" for="odometer_reading">Odometer Reading <span style="color:var(--danger);">*</span></label>
                    <input type="number" class="form-control" id="odometer_reading" name="odometer_reading"
                           min="0" step="1" placeholder="e.g. 84200" required>
                    <div class="field-error" id="err-odometer_reading"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="mileage_unit">Unit</label>
                    <select class="form-control" id="mileage_unit" name="mileage_unit">
                        <option value="km" selected>km (kilometres)</option>
                        <option value="miles">mi (miles)</option>
                    </select>
                    <div class="field-error" id="err-mileage_unit"></div>
                </div>
            </div>

            <!-- Log Date -->
            <div class="form-group">
                <label class="form-label" for="log_date">Date <span style="color:var(--danger);">*</span></label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="date" class="form-control" id="log_date" name="log_date"
                           value="<?= e(date('Y-m-d')) ?>"
                           max="<?= e(date('Y-m-d')) ?>"
                           required style="flex:1;">
                    <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" onclick="(function(e){e.showPicker?e.showPicker():e.click()})(document.getElementById('log_date'))">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                    </button>
                </div>
                <div class="field-error" id="err-log_date"></div>
            </div>

            <!-- Notes -->
            <div class="form-group">
                <label class="form-label" for="notes">Notes <span style="color:var(--text-secondary);font-weight:400;">(optional)</span></label>
                <textarea class="form-control" id="notes" name="notes"
                          rows="3" maxlength="1000"
                          oninput="document.getElementById('notes-count').textContent=this.value.length+' / 1000'"
                          placeholder="e.g. Reading taken at yard inspection"></textarea>
                <div class="form-hint" style="text-align:right;" id="notes-count">0 / 1000</div>
                <div class="field-error" id="err-notes"></div>
            </div>

            <!-- Submit -->
            <div style="display:flex;gap:.75rem;padding-top:1rem;border-top:1px solid var(--border-color);">
                <button type="submit" class="btn btn-primary" id="submit-btn">
                    Save Entry
                </button>
                <a href="<?= base_url('mileage_logs') ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </form>

    </div>
</div><!-- /card -->

<?php
$overlayTitle    = 'Mileage Log Created!';
$overlaySubtitle = 'Redirecting to log details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

</div><!-- /mileage-page-wrapper -->

<script>
// ── Clear all inline errors + banner
function mileageClearErrors() {
    document.getElementById('js-error').style.display = 'none';
    document.getElementById('js-error').textContent   = '';
    ['equipment_unit_id','lease_id','log_type','odometer_reading','mileage_unit','log_date','notes'].forEach(f => {
        const el = document.getElementById('err-' + f);
        if (el) { el.textContent = ''; el.style.display = 'none'; }
        const inp = document.getElementById(f);
        if (inp) inp.classList.remove('is-invalid');
    });
}

// ── Paint errors into their inline slots
function mileagePaintErrors(fieldMap, topMessage) {
    const errEl = document.getElementById('js-error');
    errEl.textContent = topMessage || 'Please fix the errors below and try again.';
    errEl.style.display = 'block';

    for (const [field, msg] of Object.entries(fieldMap || {})) {
        const slot = document.getElementById('err-' + field);
        if (slot) { slot.textContent = msg; slot.style.display = 'block'; }
        const inp = document.getElementById(field);
        if (inp) inp.classList.add('is-invalid');
    }
}

// ── Client-side validation — mirrors api/v1/mileage_logs/create.php
function mileageValidate() {
    const errs = {};
    const f = {
        equipment_unit_id: document.getElementById('equipment_unit_id').value,
        odometer_reading:  document.getElementById('odometer_reading').value,
        log_date:          document.getElementById('log_date').value,
    };
    if (!f.equipment_unit_id) errs.equipment_unit_id = 'Please select an equipment unit.';
    if (f.odometer_reading === '' || f.odometer_reading === null || f.odometer_reading === undefined) {
        errs.odometer_reading = 'Odometer reading is required.';
    } else {
        const n = parseInt(f.odometer_reading);
        if (isNaN(n)) errs.odometer_reading = 'Odometer reading must be a whole number.';
        else if (n < 0) errs.odometer_reading = 'Odometer cannot be negative.';
    }
    if (!f.log_date) {
        errs.log_date = 'Log date is required.';
    } else {
        const today = new Date().toISOString().split('T')[0];
        if (f.log_date > today) errs.log_date = 'Log date cannot be in the future.';
    }
    return errs;
}

document.getElementById('mileage-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    mileageClearErrors();

    const clientErrs = mileageValidate();
    if (Object.keys(clientErrs).length) {
        mileagePaintErrors(clientErrs, 'Please fix the errors below and try again.');
        return;
    }

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const form = new FormData(this);
    // Remove empty lease_id so API treats it as null
    if (!form.get('lease_id')) form.delete('lease_id');

    try {
        const res  = await fetch('<?= base_url('api/v1/mileage_logs/create') ?>', {
            method: 'POST',
            body: form,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });
        const data = await res.json();

        if (!data.success) {
            // VALID-2 envelope: data.error = {code, message, fields}
            const err = data.error || {};
            const fields = err.fields || data.data?.fields || {};
            const top = err.message || data.message || 'Failed to save. Please check your input.';
            mileagePaintErrors(fields, top);
            btn.disabled = false;
            btn.textContent = 'Save Entry';
            return;
        }

        // Show success overlay then redirect to the new entry
        const _newId = data.data.id;
        Alpine.$data(document.getElementById('mileage-page-wrapper')).showSuccessOverlay = true;
        setTimeout(() => { window.location.href = '<?= base_url('mileage_logs/show') ?>?id=' + _newId; }, 3500);

    } catch (err) {
        const errEl = document.getElementById('js-error');
        errEl.textContent = 'Network error. Please try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Save Entry';
    }
});
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
