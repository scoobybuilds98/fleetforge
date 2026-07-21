<?php
declare(strict_types=1);

/**
 * FleetForge — Equipment Template Edit Page
 *
 * @file        app/admin/equipment/templates/edit.php
 * @description Full edit form for an equipment template. Loads the template via
 *              api/v1/equipment/templates/show.php on init, pre-populates all
 *              fields, and submits to api/v1/equipment/templates/update.php.
 *
 *              Uses D19 optimistic locking — updated_at captured on load and
 *              sent with the update payload to detect concurrent edits.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/equipment/templates/show.php,
 *              api/v1/equipment/templates/update.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.3 Equipment Templates
 * @decisions   D16 (rates as decimal strings), D19 (optimistic lock), D30, D32
 * @session     S018-EXT
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'edit');

$templateId = (int) ($_GET['id'] ?? 0);
if (!$templateId) {
    header('Location: ' . base_url('equipment/templates'));
    exit;
}

// S-EQTAX (two-level): the categories an equipment type can be filed under.
$eqCategories = db_select(
    "SELECT id, slug, label FROM equipment_categories
      WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order, label"
);

$pageTitle = 'Edit Equipment Type';
$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb + Page header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('equipment') ?>">Equipment</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('equipment/templates') ?>">Equipment Types</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current" x-show="!loading" x-text="form.name || 'Edit Equipment Type'">Edit Equipment Type</span>
    <span class="breadcrumb-current" x-show="loading">Loading…</span>
</nav>
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Edit Equipment Type</h1>
        <p class="text-secondary text-sm" x-show="!loading" x-text="form.name"></p>
    </div>
    <div class="page-header-actions">
        <?= help_button('equipment') ?>
        <a href="<?= base_url('equipment/templates') ?>" class="btn btn-ghost btn-sm">← Back</a>
    </div>
</div>

<!-- ============================================================
     EDIT TEMPLATE FORM (Alpine)
     ============================================================ -->
<div x-data="FF_EditTemplate(<?= $templateId ?>)" x-init="init()">

    <!-- Stale-data reload banner (distinct from form-level banner) -->
    <div class="alert alert-warning" x-show="staleError" style="margin-bottom:16px;" x-transition>
        ⚠ This template was modified by another user while you were editing.
        <button class="btn btn-warning btn-sm" style="margin-left:8px;" @click="reload()">
            Reload Latest
        </button>
    </div>

    <!-- Loading skeleton -->
    <template x-if="loading">
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-body" style="padding:32px;">
                <div class="skeleton skeleton-text" style="width:50%;height:28px;margin-bottom:16px;"></div>
                <div class="skeleton skeleton-text" style="width:80%;height:18px;margin-bottom:8px;"></div>
                <div class="skeleton skeleton-text" style="width:60%;height:18px;"></div>
            </div>
        </div>
    </template>

    <!-- Form (shown after load) -->
    <template x-if="!loading">
        <form @submit.prevent="submit()" novalidate>

            <!-- ── Section 1: Identity ──────────────────────────── -->
            <div class="card" style="margin-bottom:1.5rem;">
                <div class="card-header">
                    <div class="card-title">Equipment Type Identity</div>
                </div>
                <div class="card-body">

                    <div class="form-group">
                        <label class="form-label required" for="name">Equipment Type Name</label>
                        <input type="text" id="name" name="name" class="form-control"
                               x-model="form.name"
                               placeholder="e.g. 53ft Dry Van"
                               maxlength="100">
                        <div class="form-hint">Must be unique. Used as the display name everywhere.</div>
                        <div class="field-error" data-error-for="name"></div>
                    </div>

                    <!-- S-EQTAX (two-level): an equipment type is filed directly under a category. -->
                    <div class="form-group">
                        <label class="form-label required" for="category_id">Category</label>
                        <select id="category_id" name="category_id" class="form-control form-select"
                                x-model="form.category_id">
                            <option value="">— Select category —</option>
                            <template x-for="c in categories" :key="c.id">
                                <option :value="c.id" x-text="c.label"></option>
                            </template>
                        </select>
                        <div class="form-hint">The broad class this equipment belongs to. Billing rules like the short-lease minimum apply per category. Add or edit categories under <a href="<?= base_url('equipment/categories') ?>">Manage Categories</a>.</div>
                        <div class="field-error" data-error-for="category_id"></div>
                    </div>

                    <!-- S-UNIT-BRAND: Brand / Make removed from the TEMPLATE — it is
                         now a per-UNIT field (see the unit create/edit form). -->
                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label" for="model">Model</label>
                            <input type="text" id="model" name="model" class="form-control"
                                   x-model="form.model" maxlength="100" placeholder="e.g. DuraPlate">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label" for="is_active">Status</label>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:6px;">
                                <input type="checkbox" id="is_active" x-model="form.is_active"
                                       style="width:16px;height:16px;cursor:pointer;">
                                <label for="is_active" class="text-sm" style="cursor:pointer;margin:0;">
                                    Active (visible in unit selector and reports)
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Units Using This Equipment Type</label>
                            <div class="field-value font-mono"
                                 x-text="unitCount + ' unit' + (unitCount !== 1 ? 's' : '')"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="description">Description</label>
                        <textarea id="description" name="description" class="form-control"
                                  x-model="form.description" rows="2" maxlength="5000"
                                  placeholder="Optional description shown on equipment type detail."></textarea>
                    </div>

                </div>
            </div>

            <!-- ── Section 2: Default Dimensions ───────────────── -->
            <div class="card" style="margin-bottom:1.5rem;">
                <div class="card-header">
                    <div class="card-title">Default Dimensions</div>
                    <div class="card-body" style="padding:0;font-size:0.8125rem;color:var(--text-secondary);">
                        Units created from this template inherit these values but can override them.
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-row-3">
                        <div class="form-group">
                            <label class="form-label" for="default_length_ft">Length (ft)</label>
                            <input type="number" min="0" id="default_length_ft" name="default_length_ft" class="form-control font-mono"
                                   x-model="form.default_length_ft" step="0.01">
                            <div class="field-error" data-error-for="default_length_ft"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_width_ft">Width (ft)</label>
                            <input type="number" min="0" id="default_width_ft" name="default_width_ft" class="form-control font-mono"
                                   x-model="form.default_width_ft" step="0.01">
                            <div class="field-error" data-error-for="default_width_ft"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_height_ft">Height (ft)</label>
                            <input type="number" min="0" id="default_height_ft" name="default_height_ft" class="form-control font-mono"
                                   x-model="form.default_height_ft" step="0.01">
                            <div class="field-error" data-error-for="default_height_ft"></div>
                        </div>
                    </div>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label class="form-label" for="default_axle_count">Axle Count</label>
                            <input type="number" min="0" id="default_axle_count" name="default_axle_count" class="form-control font-mono"
                                   x-model="form.default_axle_count">
                            <div class="field-error" data-error-for="default_axle_count"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_weight_capacity_lbs">Weight Capacity (lbs)</label>
                            <input type="number" min="0" id="default_weight_capacity_lbs" name="default_weight_capacity_lbs" class="form-control font-mono"
                                   x-model="form.default_weight_capacity_lbs">
                            <div class="field-error" data-error-for="default_weight_capacity_lbs"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_ownership_type">Default Ownership</label>
                            <select id="default_ownership_type" name="default_ownership_type" class="form-control form-select"
                                    x-model="form.default_ownership_type">
                                <option value="">— Not set —</option>
                                <option value="owned">Owned</option>
                                <option value="leased">Leased</option>
                                <option value="brokered">Brokered</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section 3: Default Rates ─────────────────────── -->
            <div class="card" style="margin-bottom:1.5rem;">
                <div class="card-header"><div class="card-title">Default Rental Rates</div></div>
                <div class="card-body">

                    <div class="form-row-2" style="margin-bottom:1rem;">
                        <div class="form-group">
                            <label class="form-label" for="default_currency">Currency</label>
                            <select id="default_currency" name="default_currency" class="form-control form-select"
                                    x-model="form.default_currency">
                                <option value="CAD">CAD</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_mileage_unit">Mileage Unit</label>
                            <select id="default_mileage_unit" name="default_mileage_unit" class="form-control form-select"
                                    x-model="form.default_mileage_unit">
                                <option value="km">Kilometres</option>
                                <option value="miles">Miles</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-3">
                        <div class="form-group">
                            <label class="form-label" for="default_daily_rate">Daily Rate</label>
                            <div class="input-group">
                                <span class="input-group-prefix">$</span>
                                <input type="number" min="0" id="default_daily_rate" name="default_daily_rate" class="form-control font-mono"
                                       x-model="form.default_daily_rate" step="0.01" placeholder="0.00">
                            </div>
                            <div class="field-error" data-error-for="default_daily_rate"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_weekly_rate">Weekly Rate</label>
                            <div class="input-group">
                                <span class="input-group-prefix">$</span>
                                <input type="number" min="0" id="default_weekly_rate" name="default_weekly_rate" class="form-control font-mono"
                                       x-model="form.default_weekly_rate" step="0.01" placeholder="0.00">
                            </div>
                            <div class="field-error" data-error-for="default_weekly_rate"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_monthly_rate">Monthly Rate</label>
                            <div class="input-group">
                                <span class="input-group-prefix">$</span>
                                <input type="number" min="0" id="default_monthly_rate" name="default_monthly_rate" class="form-control font-mono"
                                       x-model="form.default_monthly_rate" step="0.01" placeholder="0.00">
                            </div>
                            <div class="field-error" data-error-for="default_monthly_rate"></div>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label" for="default_mileage_rate">Mileage Rate (per mile/km)</label>
                            <div class="input-group">
                                <span class="input-group-prefix">$</span>
                                <input type="number" min="0" id="default_mileage_rate" name="default_mileage_rate" class="form-control font-mono"
                                       x-model="form.default_mileage_rate" step="0.0001" placeholder="0.0000">
                            </div>
                            <div class="form-hint">Set to 0 to disable mileage billing for this equipment type.</div>
                            <div class="field-error" data-error-for="default_mileage_rate"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_tracking_provider">Default GPS Tracking</label>
                            <select id="default_tracking_provider" name="default_tracking_provider" class="form-control form-select"
                                    x-model="form.default_tracking_provider">
                                <option value="none">None</option>
                                <option value="samsara">Samsara</option>
                            </select>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ── Section 4: Compliance Intervals ─────────────── -->
            <div class="card" style="margin-bottom:1.5rem;">
                <div class="card-header"><div class="card-title">Compliance Renewal Intervals</div></div>
                <div class="card-body">
                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label" for="default_cvi_interval_days">CVI Interval (days)</label>
                            <input type="number" min="0" id="default_cvi_interval_days" name="default_cvi_interval_days" class="form-control font-mono"
                                   x-model="form.default_cvi_interval_days" placeholder="365">
                            <div class="field-error" data-error-for="default_cvi_interval_days"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_mvi_interval_days">MVI Interval (days)</label>
                            <input type="number" min="0" id="default_mvi_interval_days" name="default_mvi_interval_days" class="form-control font-mono"
                                   x-model="form.default_mvi_interval_days" placeholder="365">
                            <div class="field-error" data-error-for="default_mvi_interval_days"></div>
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label" for="default_registration_interval_days">Registration Interval (days)</label>
                            <input type="number" min="0" id="default_registration_interval_days" name="default_registration_interval_days" class="form-control font-mono"
                                   x-model="form.default_registration_interval_days" placeholder="365">
                            <div class="field-error" data-error-for="default_registration_interval_days"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="default_insurance_interval_days">Insurance Interval (days)</label>
                            <input type="number" min="0" id="default_insurance_interval_days" name="default_insurance_interval_days" class="form-control font-mono"
                                   x-model="form.default_insurance_interval_days" placeholder="365">
                            <div class="field-error" data-error-for="default_insurance_interval_days"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Form-level error banner (VALID-2) ────────────── -->
            <div class="form-error-banner" data-form-error></div>

            <!-- ── Form actions ─────────────────────────────────── -->
            <div class="d-flex gap-3" style="justify-content:flex-end;margin-bottom:2rem;">
                <a href="<?= base_url('equipment/templates') ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary" :disabled="submitting">
                    <span x-show="!submitting">Save Changes</span>
                    <span x-show="submitting">Saving…</span>
                </button>
            </div>

        </form>
    </template>

</div><!-- /x-data -->

<!-- ============================================================
     ALPINE COMPONENT
     ============================================================ -->
<script>
function FF_EditTemplate(templateId) {
    return {
        // ── State ─────────────────────────────────────────────────
        loading:    true,
        submitting: false,
        staleError: false,
        unitCount:  0,
        updatedAt:  null,   // captured on load for D19 optimistic lock
        showSuccessOverlay: false,

        // S-EQTAX (two-level): the categories an equipment type can be filed under.
        categories: <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'label' => $c['label']], $eqCategories), JSON_HEX_TAG | JSON_HEX_AMP) ?>,

        form: {
            name:                               '',
            category_id:                        '',
            description:                        '',
            model:                              '',
            default_length_ft:                  '',
            default_height_ft:                  '',
            default_width_ft:                   '',
            default_weight_capacity_lbs:        '',
            default_axle_count:                 '',
            default_ownership_type:             '',
            default_tracking_provider:          'none',
            default_cvi_interval_days:          '',
            default_mvi_interval_days:          '',
            default_registration_interval_days: '',
            default_insurance_interval_days:    '',
            default_daily_rate:                 '',
            default_weekly_rate:                '',
            default_monthly_rate:               '',
            default_mileage_rate:               '',
            default_currency:                   'CAD',
            default_mileage_unit:               'km',
            is_active:                          true,
        },

        // ── Init ──────────────────────────────────────────────────
        async init() {
            await this.load();
            // S-FORM-DRAFT-ROLLOUT: opt into the shared autosave helper after the
            // server prefill loads. updated_at lives in component state (this.updatedAt),
            // not in form, so the lock token is never persisted.
            if (window.FF_FormDraft) {
                this._draft = FF_FormDraft.attach({
                    formId: 'equipment-template-edit', entityId: templateId,
                    el: this.$root, model: this.form, version: '1',
                });
            }
        },

        // ── Load template from API ─────────────────────────────────
        async load() {
            this.loading    = true;
            this.staleError = false;
            try {
                const res  = await fetch('<?= base_url('api/v1/equipment/templates/show.php') ?>?id=<?= $templateId ?>');
                const data = await res.json();
                if (!data.success) throw new Error(data.error?.message || 'Equipment type not found.');

                const t = data.data;
                this.updatedAt = t.updated_at;
                this.unitCount = parseInt(t.unit_count || '0', 10);

                // WHY loop: map API response fields directly onto form state;
                // avoids having to manually assign each of the 20+ fields.
                const formKeys = Object.keys(this.form);
                formKeys.forEach(key => {
                    if (t[key] !== undefined && t[key] !== null) {
                        // Boolean coercion for is_active (DB returns "0"/"1" strings)
                        if (key === 'is_active') {
                            this.form[key] = t[key] == '1' || t[key] === true;
                        } else {
                            this.form[key] = t[key];
                        }
                    }
                });
            } catch (e) {
                // Defer banner display until next tick so form is mounted
                this.$nextTick(() => {
                    const form = document.querySelector('form');
                    if (form) FF_Validate.banner(form, e.message || 'Failed to load equipment type.');
                });
            } finally {
                this.loading = false;
            }
        },

        // ── Reload after stale-data conflict ──────────────────────
        async reload() {
            this.staleError = false;
            await this.load();
        },

        // VALID-2: spec-exact per-field validation with FF_Validate
        validate() {
            const form = document.querySelector('form');
            FF_Validate.clear(form);
            let ok = true;

            if (!this.form.name || !this.form.name.trim()) {
                FF_Validate.field(form, 'name', 'Equipment type name is required.');
                ok = false;
            }
            if (!this.form.category_id) {
                FF_Validate.field(form, 'category_id', 'Please select a category.');
                ok = false;
            }

            // Dimensions must be > 0 if provided
            const dims = {
                default_length_ft: 'Length',
                default_width_ft:  'Width',
                default_height_ft: 'Height',
            };
            for (const [field, label] of Object.entries(dims)) {
                const raw = this.form[field];
                if (raw !== '' && raw !== null && raw !== undefined) {
                    const n = parseFloat(raw);
                    if (isNaN(n) || n <= 0) {
                        FF_Validate.field(form, field, `${label} must be greater than zero.`);
                        ok = false;
                    }
                }
            }

            // Weight capacity & axle count must be > 0 if provided
            const posInts = {
                default_weight_capacity_lbs: 'Weight capacity',
                default_axle_count:          'Axle count',
            };
            for (const [field, label] of Object.entries(posInts)) {
                const raw = this.form[field];
                if (raw !== '' && raw !== null && raw !== undefined) {
                    const n = parseInt(raw, 10);
                    if (isNaN(n) || n <= 0) {
                        FF_Validate.field(form, field, `${label} must be greater than zero.`);
                        ok = false;
                    }
                }
            }

            // Rates must be >= 0 — spec-exact "cannot be negative"
            const rates = {
                default_daily_rate:   'Daily rate',
                default_weekly_rate:  'Weekly rate',
                default_monthly_rate: 'Monthly rate',
                default_mileage_rate: 'Mileage rate',
            };
            for (const [field, label] of Object.entries(rates)) {
                const raw = this.form[field];
                if (raw !== '' && raw !== null && raw !== undefined) {
                    const n = parseFloat(raw);
                    if (isNaN(n) || n < 0) {
                        FF_Validate.field(form, field, `${label} cannot be negative.`);
                        ok = false;
                    }
                }
            }

            // Intervals must be > 0 if provided
            const intervals = {
                default_cvi_interval_days:          'CVI interval',
                default_mvi_interval_days:          'MVI interval',
                default_registration_interval_days: 'Registration interval',
                default_insurance_interval_days:    'Insurance interval',
            };
            for (const [field, label] of Object.entries(intervals)) {
                const raw = this.form[field];
                if (raw !== '' && raw !== null && raw !== undefined) {
                    const n = parseInt(raw, 10);
                    if (isNaN(n) || n <= 0) {
                        FF_Validate.field(form, field, `${label} must be greater than zero.`);
                        ok = false;
                    }
                }
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        // ── Submit ────────────────────────────────────────────────
        async submit() {
            if (!this.validate()) return;
            this.submitting = true;
            this.staleError = false;
            const form = document.querySelector('form');

            // Build payload — omit empty strings; always include id + updated_at
            const payload = { id: templateId, updated_at: this.updatedAt };
            Object.entries(this.form).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) payload[k] = v;
            });
            // Coerce integer fields
            ['category_id',
             'default_weight_capacity_lbs', 'default_axle_count',
             'default_cvi_interval_days', 'default_mvi_interval_days',
             'default_registration_interval_days', 'default_insurance_interval_days'].forEach(f => {
                if (payload[f] !== undefined) payload[f] = parseInt(payload[f], 10);
            });

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/templates/update.php') ?>', payload);

                if (r.success) {
                    if (this._draft) this._draft.clear(true);   // S-FORM-DRAFT-ROLLOUT: wipe draft on confirmed save
                    this.showSuccessOverlay = true;
                    setTimeout(() => {
                        window.location.href = '<?= base_url('equipment/templates') ?>';
                    }, 3500);
                } else if (r.error?.code === 'STALE_DATA') {
                    // D19: another user saved first — prompt user to reload
                    this.staleError = true;
                } else if (r.error?.code === 'VALIDATION_ERROR') {
                    FF_Validate.applyApi(form, r.error);
                    FF_Validate.scrollToFirst(form);
                } else {
                    FF_Validate.banner(form, r.error?.message || 'Failed to save equipment type.');
                    if (r.error?.fields) FF_Validate.applyApi(form, r.error);
                }
            } catch (e) {
                FF_Validate.banner(form, 'Network error. Please try again.');
            } finally {
                this.submitting = false;
            }
        },
    };
}
</script>

<?php
$overlayTitle    = 'Equipment Type Saved!';
$overlaySubtitle = 'Redirecting to equipment types list…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
