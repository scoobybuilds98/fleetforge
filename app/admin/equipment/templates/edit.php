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

$pageTitle = 'Edit Equipment Template';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb + Page header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('equipment') ?>">Equipment</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('equipment/templates') ?>">Templates</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current" x-show="!loading" x-text="form.name || 'Edit Template'">Edit Template</span>
    <span class="breadcrumb-current" x-show="loading">Loading…</span>
</nav>
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Edit Template</h1>
        <p class="text-secondary text-sm" x-show="!loading" x-text="form.name"></p>
    </div>
    <div class="page-header-actions">
        <a href="<?= base_url('equipment/templates') ?>" class="btn btn-ghost btn-sm">← Back</a>
    </div>
</div>

<!-- ============================================================
     EDIT TEMPLATE FORM (Alpine)
     ============================================================ -->
<div x-data="FF_EditTemplate(<?= $templateId ?>)" x-init="init()">

    <!-- Global error / stale-data banners -->
    <div class="alert alert-danger" x-show="globalError" x-text="globalError"
         style="margin-bottom:16px;" x-transition></div>
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
                    <div class="card-title">Template Identity</div>
                </div>
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
                            <label class="form-label">Units Using This Template</label>
                            <div class="field-value font-mono"
                                 x-text="unitCount + ' unit' + (unitCount !== 1 ? 's' : '')"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="description">Description</label>
                        <textarea id="description" class="form-control"
                                  x-model="form.description" rows="2" maxlength="5000"
                                  placeholder="Optional description shown on template detail."></textarea>
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

            <!-- ── Section 3: Default Rates ─────────────────────── -->
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

            <!-- ── Section 4: Compliance Intervals ─────────────── -->
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
        globalError: null,
        staleError:  false,
        unitCount:   0,
        updatedAt:   null,   // captured on load for D19 optimistic lock
        showSuccessOverlay: false,

        form: {
            name:                               '',
            category:                           '',
            description:                        '',
            brand:                              '',
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
        errors: {},

        // ── Init ──────────────────────────────────────────────────
        async init() {
            await this.load();
        },

        // ── Load template from API ─────────────────────────────────
        async load() {
            this.loading     = true;
            this.globalError = null;
            this.staleError  = false;
            try {
                const res  = await fetch('<?= base_url('api/v1/equipment/templates/show.php') ?>?id=<?= $templateId ?>');
                const data = await res.json();
                if (!data.success) throw new Error(data.error?.message || 'Template not found.');

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
                this.globalError = e.message || 'Failed to load template.';
            } finally {
                this.loading = false;
            }
        },

        // ── Reload after stale-data conflict ──────────────────────
        async reload() {
            this.staleError = false;
            await this.load();
        },

        // ── Validate ──────────────────────────────────────────────
        validate() {
            this.errors = {};
            if (!this.form.name.trim())  this.errors.name = 'Template name is required.';
            if (!this.form.category)     this.errors.category = 'Category is required.';
            return Object.keys(this.errors).length === 0;
        },

        // ── Submit ────────────────────────────────────────────────
        async submit() {
            if (!this.validate()) return;
            this.submitting  = true;
            this.globalError = null;
            this.staleError  = false;

            // Build payload — omit empty strings; always include id + updated_at
            const payload = { id: templateId, updated_at: this.updatedAt };
            Object.entries(this.form).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) payload[k] = v;
            });
            // Coerce integer fields
            ['default_weight_capacity_lbs', 'default_axle_count',
             'default_cvi_interval_days', 'default_mvi_interval_days',
             'default_registration_interval_days', 'default_insurance_interval_days'].forEach(f => {
                if (payload[f]) payload[f] = parseInt(payload[f], 10);
            });

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/templates/update.php') ?>', payload);

                if (r.success) {
                    this.showSuccessOverlay = true;
                    setTimeout(() => {
                        window.location.href = '<?= base_url('equipment/templates') ?>';
                    }, 3500);
                } else if (r.error?.code === 'STALE_DATA') {
                    // D19: another user saved first — prompt user to reload
                    this.staleError = true;
                } else {
                    this.globalError = r.error?.message || 'Failed to save template.';
                    if (r.error?.errors) this.errors = r.error.errors;
                }
            } catch (e) {
                this.globalError = 'Network error. Please try again.';
            } finally {
                this.submitting = false;
            }
        },
    };
}
</script>

<?php
$overlayTitle    = 'Template Saved!';
$overlaySubtitle = 'Redirecting to templates list…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
