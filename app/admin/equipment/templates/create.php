<?php
declare(strict_types=1);

/**
 * app/admin/equipment/templates/create.php
 *
 * New equipment template form. Captures name, category, brand/model,
 * default dimensions, default rates, and compliance intervals.
 * Submits to api/v1/equipment/templates/create and redirects to templates list.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/equipment/templates/create.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.3 Equipment Templates
 * @decisions D16 (rates stored as decimal strings), D30, D32
 * @session S006
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'create');

$pageTitle = 'New Equipment Template';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <a href="<?= base_url('equipment/templates') ?>" class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">
            ← Templates
        </a>
        <h1 class="page-header-title h4">New Equipment Template</h1>
    </div>
</div>

<!-- ============================================================
     CREATE TEMPLATE FORM (Alpine)
     ============================================================ -->
<div x-data="FF_CreateTemplate()" x-init="init()">

    <form @submit.prevent="submit()" novalidate>

        <!-- ── Section 1: Identity ─────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Template Identity</div></div>
            <div class="card-body">

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label required" for="name">Template Name</label>
                        <input type="text" id="name" class="form-control"
                               x-model="form.name"
                               :class="errors.name ? 'is-invalid' : ''"
                               placeholder="e.g. 53ft Dry Van"
                               maxlength="100">
                        <div class="form-hint">Must be unique. Used as the display name everywhere.</div>
                        <div class="form-error" x-show="errors.name" x-text="errors.name"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required" for="category">Category</label>
                        <select id="category" class="form-control form-select"
                                x-model="form.category"
                                :class="errors.category ? 'is-invalid' : ''">
                            <option value="">— Select category —</option>
                            <option value="chassis">Chassis</option>
                            <option value="dry_van">Dry Van</option>
                            <option value="reefer">Reefer</option>
                            <option value="container">Container</option>
                            <option value="flatbed">Flatbed</option>
                            <option value="step_deck">Step Deck</option>
                            <option value="lowboy">Lowboy</option>
                            <option value="tanker">Tanker</option>
                            <option value="dump">Dump</option>
                            <option value="other">Other</option>
                        </select>
                        <div class="form-error" x-show="errors.category" x-text="errors.category"></div>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="brand">Brand / Make</label>
                        <input type="text" id="brand" class="form-control"
                               x-model="form.brand" maxlength="100" placeholder="e.g. Wabash">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="model">Model</label>
                        <input type="text" id="model" class="form-control"
                               x-model="form.model" maxlength="100" placeholder="e.g. DuraPlate">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea id="description" class="form-control"
                              x-model="form.description" rows="2" maxlength="2000"
                              placeholder="Optional description shown on template detail."></textarea>
                    <div class="form-hint" style="text-align:right;" x-text="(form.description || '').length + ' / 2000'"></div>
                </div>

            </div>
        </div>

        <!-- ── Section 2: Default Dimensions ───────────────────── -->
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
                        <input type="number" id="default_length_ft" class="form-control font-mono"
                               x-model="form.default_length_ft" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_width_ft">Width (ft)</label>
                        <input type="number" id="default_width_ft" class="form-control font-mono"
                               x-model="form.default_width_ft" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_height_ft">Height (ft)</label>
                        <input type="number" id="default_height_ft" class="form-control font-mono"
                               x-model="form.default_height_ft" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="default_axle_count">Axle Count</label>
                        <input type="number" id="default_axle_count" class="form-control font-mono"
                               x-model="form.default_axle_count" min="1" max="20">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_weight_capacity_lbs">Weight Capacity (lbs)</label>
                        <input type="number" id="default_weight_capacity_lbs" class="form-control font-mono"
                               x-model="form.default_weight_capacity_lbs" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_ownership_type">Default Ownership</label>
                        <select id="default_ownership_type" class="form-control form-select"
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

        <!-- ── Section 3: Default Rates ────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Default Rental Rates</div></div>
            <div class="card-body">

                <div class="form-row-2" style="margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label" for="default_currency">Currency</label>
                        <select id="default_currency" class="form-control form-select"
                                x-model="form.default_currency">
                            <option value="CAD">CAD</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_mileage_unit">Mileage Unit</label>
                        <select id="default_mileage_unit" class="form-control form-select"
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
                            <input type="number" id="default_daily_rate" class="form-control font-mono"
                                   x-model="form.default_daily_rate" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_weekly_rate">Weekly Rate</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="default_weekly_rate" class="form-control font-mono"
                                   x-model="form.default_weekly_rate" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_monthly_rate">Monthly Rate</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="default_monthly_rate" class="form-control font-mono"
                                   x-model="form.default_monthly_rate" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="default_mileage_rate">Mileage Rate (per mile/km)</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="default_mileage_rate" class="form-control font-mono"
                                   x-model="form.default_mileage_rate" step="0.0001" min="0" placeholder="0.0000">
                        </div>
                        <div class="form-hint">Set to 0 to disable mileage billing for this template.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_tracking_provider">Default GPS Tracking</label>
                        <select id="default_tracking_provider" class="form-control form-select"
                                x-model="form.default_tracking_provider">
                            <option value="none">None</option>
                            <option value="samsara">Samsara</option>
                        </select>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Section 4: Compliance Intervals ─────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Compliance Renewal Intervals</div></div>
            <div class="card-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="default_cvi_interval_days">CVI Interval (days)</label>
                        <input type="number" id="default_cvi_interval_days" class="form-control font-mono"
                               x-model="form.default_cvi_interval_days" min="1" placeholder="365">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_mvi_interval_days">MVI Interval (days)</label>
                        <input type="number" id="default_mvi_interval_days" class="form-control font-mono"
                               x-model="form.default_mvi_interval_days" min="1" placeholder="365">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="default_registration_interval_days">Registration Interval (days)</label>
                        <input type="number" id="default_registration_interval_days" class="form-control font-mono"
                               x-model="form.default_registration_interval_days" min="1" placeholder="365">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_insurance_interval_days">Insurance Interval (days)</label>
                        <input type="number" id="default_insurance_interval_days" class="form-control font-mono"
                               x-model="form.default_insurance_interval_days" min="1" placeholder="365">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Form actions ─────────────────────────────────────── -->
        <div class="d-flex gap-3" style="justify-content:flex-end;margin-bottom:2rem;">
            <a href="<?= base_url('equipment/templates') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
                <span x-show="!submitting">Create Template</span>
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
function FF_CreateTemplate() {
    return {
        form: {
            name:                            '',
            category:                        '',
            description:                     '',
            brand:                           '',
            model:                           '',
            default_length_ft:               '',
            default_height_ft:               '',
            default_width_ft:                '',
            default_weight_capacity_lbs:     '',
            default_axle_count:              '',
            default_ownership_type:          '',
            default_tracking_provider:       'none',
            default_cvi_interval_days:       '',
            default_mvi_interval_days:       '',
            default_registration_interval_days: '',
            default_insurance_interval_days: '',
            default_daily_rate:              '',
            default_weekly_rate:             '',
            default_monthly_rate:            '',
            default_mileage_rate:            '',
            default_currency:                'CAD',
            default_mileage_unit:            'km',
            is_active:                       true,
        },
        errors:             {},
        globalError:        null,
        submitting:         false,
        showSuccessOverlay: false,

        init() {},

        validate() {
            this.errors = {};
            if (!this.form.name.trim()) this.errors.name = 'Template name is required.';
            if (!this.form.category)   this.errors.category = 'Category is required.';
            return Object.keys(this.errors).length === 0;
        },

        async submit() {
            if (!this.validate()) return;
            this.submitting  = true;
            this.globalError = null;
            // Build payload — omit empty strings
            const payload = {};
            Object.entries(this.form).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) payload[k] = v;
            });
            // Coerce numeric fields
            ['default_weight_capacity_lbs','default_axle_count',
             'default_cvi_interval_days','default_mvi_interval_days',
             'default_registration_interval_days','default_insurance_interval_days'].forEach(f => {
                if (payload[f]) payload[f] = parseInt(payload[f]);
            });
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/templates/create') ?>', payload);
                if (r.success) {
                    this.showSuccessOverlay = true;
                    setTimeout(() => { window.location.href = '<?= base_url('equipment/templates') ?>'; }, 3500);
                } else {
                    this.globalError = r.message || 'Failed to create template.';
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

<?php
$overlayTitle    = 'Template Created!';
$overlaySubtitle = 'Redirecting to template details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
