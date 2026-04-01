<?php
declare(strict_types=1);

/**
 * FleetForge — Edit Lease Form
 *
 * @file        app/admin/leases/edit.php
 * @description Edit lease metadata. Only allows editing fields that don't require
 *              an amendment record (dates, notes, add-ons, po_number, mileage).
 *              Rates are shown read-only — rate changes require a lease_amendment
 *              (not yet built). Status changes excluded — use activate/close endpoints.
 *              Server pre-populates form via json_encode(). D19 optimistic lock:
 *              passes updated_at from initial load to update API.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/leases/update.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D19 (optimistic lock), D30 (asset_url), D32 (CSS classes)
 * @session     S007
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('leases', 'edit');

$leaseId = clean_int($_GET['id'] ?? null);
if (!$leaseId) {
    header('Location: ' . base_url('leases'));
    exit;
}

$lease = db_row(
    "SELECT l.*,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_display_name,
            COALESCE(u.unit_number, l.unit_number_snapshot)   AS unit_display_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.id = ? AND l.deleted_at IS NULL",
    [$leaseId]
);

if (!$lease) {
    header('Location: ' . base_url('leases'));
    exit;
}

// Only pending leases are fully editable — active leases have limited editing
$isActive = $lease['status'] === 'active';

$pageTitle = 'Edit ' . $lease['contract_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <a href="<?= base_url('leases/show') ?>?id=<?= $leaseId ?>"
           class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">
            ← <?= e($lease['contract_number']) ?>
        </a>
        <h1 class="page-header-title h4">Edit <?= e($lease['contract_number']) ?></h1>
        <div class="text-secondary text-sm">
            <?= e($lease['customer_display_name']) ?> &nbsp;·&nbsp;
            Unit <?= e($lease['unit_display_number']) ?>
        </div>
    </div>
</div>

<?php if ($isActive): ?>
<div class="card card-body" style="background:var(--color-warning-light);color:var(--color-warning-text);margin-bottom:1.5rem;">
    <strong>Active Lease</strong> — Rate changes and customer/unit changes require an amendment record.
    Only dates, add-ons, notes, and mileage can be edited here.
</div>
<?php endif; ?>

<!-- ============================================================
     EDIT FORM (Alpine) — server pre-populated
     ============================================================ -->
<div x-data="FF_EditLease()" x-init="init()">

    <form @submit.prevent="submit()" novalidate>

        <!-- ── Section 1: Identity (read-only) ──────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Lease Identity</div></div>
            <div class="card-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Contract Number</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            <?= e($lease['contract_number']) ?>
                        </div>
                        <div class="form-hint">Contract number cannot be changed after creation.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="po_number">PO Number</label>
                        <input type="text" id="po_number" class="form-control"
                               x-model="form.po_number" maxlength="100">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Customer</label>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;">
                            <?= e($lease['customer_display_name']) ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            <?= e($lease['unit_display_number']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 2: Dates ────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Dates</div></div>
            <div class="card-body">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;">
                            <?= e($lease['start_date']) ?>
                        </div>
                        <div class="form-hint">Start date cannot be changed.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_date">End Date</label>
                        <input type="date" id="end_date" class="form-control"
                               x-model="form.end_date"
                               :class="errors.end_date ? 'is-invalid' : ''">
                        <div class="form-error" x-show="errors.end_date" x-text="errors.end_date"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="minimum_end_date">Minimum End Date</label>
                        <input type="date" id="minimum_end_date" class="form-control"
                               x-model="form.minimum_end_date">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 3: Rates (read-only for active leases) ──── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <div class="card-title">Rental Rates</div>
                <div style="font-size:0.8125rem;color:var(--text-secondary);">
                    Rate changes require an amendment record (coming in a future session).
                </div>
            </div>
            <div class="card-body">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Daily Rate</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            $<?= e(number_format((float)$lease['daily_rate'], 2)) ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Weekly Rate</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            $<?= e(number_format((float)$lease['weekly_rate'], 2)) ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Monthly Rate</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            $<?= e(number_format((float)$lease['monthly_rate'], 2)) ?>
                        </div>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="rate_notes">Rate Notes</label>
                        <input type="text" id="rate_notes" class="form-control"
                               x-model="form.rate_notes" maxlength="5000">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 4: Mileage ──────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Mileage</div></div>
            <div class="card-body">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="mileage_at_start">Starting Mileage</label>
                        <input type="number" id="mileage_at_start" class="form-control font-mono"
                               x-model="form.mileage_at_start" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mileage_at_end">Ending Mileage</label>
                        <input type="number" id="mileage_at_end" class="form-control font-mono"
                               x-model="form.mileage_at_end" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="estimated_mileage">Estimated Mileage</label>
                        <input type="number" id="estimated_mileage" class="form-control font-mono"
                               x-model="form.estimated_mileage" min="0">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 5: Add-ons ───────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Add-ons</div></div>
            <div class="card-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" class="form-check-input" x-model="form.insurance_opt_in">
                            Insurance Add-on
                        </label>
                        <div class="input-group" x-show="form.insurance_opt_in">
                            <span class="input-group-prefix">$</span>
                            <input type="number" class="form-control font-mono"
                                   x-model="form.insurance_cost" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" class="form-check-input" x-model="form.warranty_opt_in">
                            Warranty Add-on
                        </label>
                        <div class="input-group" x-show="form.warranty_opt_in">
                            <span class="input-group-prefix">$</span>
                            <input type="number" class="form-control font-mono"
                                   x-model="form.warranty_cost" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 6: Notes ─────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Notes</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea id="notes" class="form-control"
                              x-model="form.notes" rows="2" maxlength="5000"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="internal_notes">Internal Notes</label>
                    <textarea id="internal_notes" class="form-control"
                              x-model="form.internal_notes" rows="2" maxlength="5000"></textarea>
                </div>
            </div>
        </div>

        <!-- ── Form actions ─────────────────────────────────────── -->
        <div class="d-flex gap-3" style="justify-content:flex-end;margin-bottom:2rem;">
            <a href="<?= base_url('leases/show') ?>?id=<?= $leaseId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
                <span x-show="!submitting">Save Changes</span>
                <span x-show="submitting">Saving…</span>
            </button>
        </div>

        <template x-if="globalError">
            <div class="card card-body" style="background:var(--color-danger-light);color:var(--color-danger-text);margin-bottom:1rem;">
                <strong>Error:</strong> <span x-text="globalError"></span>
            </div>
        </template>

    </form>
</div>

<script>
function FF_EditLease() {
    return {
        form: {
            id:                 <?= $leaseId ?>,
            updated_at:         <?= json_encode($lease['updated_at']) ?>,
            po_number:          <?= json_encode($lease['po_number'] ?? '') ?>,
            end_date:           <?= json_encode($lease['end_date'] ?? '') ?>,
            minimum_end_date:   <?= json_encode($lease['minimum_end_date'] ?? '') ?>,
            rate_notes:         <?= json_encode($lease['rate_notes'] ?? '') ?>,
            mileage_at_start:   <?= json_encode($lease['mileage_at_start'] ?? '') ?>,
            mileage_at_end:     <?= json_encode($lease['mileage_at_end'] ?? '') ?>,
            estimated_mileage:  <?= json_encode($lease['estimated_mileage'] ?? '') ?>,
            insurance_opt_in:   <?= $lease['insurance_opt_in'] ? 'true' : 'false' ?>,
            insurance_cost:     <?= json_encode($lease['insurance_cost'] ?? '0.00') ?>,
            warranty_opt_in:    <?= $lease['warranty_opt_in'] ? 'true' : 'false' ?>,
            warranty_cost:      <?= json_encode($lease['warranty_cost'] ?? '0.00') ?>,
            notes:              <?= json_encode($lease['notes'] ?? '') ?>,
            internal_notes:     <?= json_encode($lease['internal_notes'] ?? '') ?>,
        },
        errors:      {},
        globalError: null,
        submitting:  false,

        init() {},

        validate() {
            this.errors = {};
            const startDate = <?= json_encode($lease['start_date']) ?>;
            if (this.form.end_date && this.form.end_date < startDate) {
                this.errors.end_date = 'End date must be on or after start date.';
            }
            return Object.keys(this.errors).length === 0;
        },

        async submit() {
            if (!this.validate()) return;
            this.submitting  = true;
            this.globalError = null;

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/leases/update') ?>', this.form);
                if (r.success) {
                    window.location.href = '<?= base_url('leases/show') ?>?id=<?= $leaseId ?>';
                } else if (r.code === 'STALE_DATA') {
                    this.globalError = r.message + ' Reload this page to get the latest version.';
                } else {
                    this.globalError = r.message || 'Failed to save changes.';
                    if (r.errors) this.errors = r.errors;
                }
            } catch(e) {
                this.globalError = 'Network error. Please try again.';
            }
            this.submitting = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
