<?php
declare(strict_types=1);

/**
 * app/admin/rates/create.php
 *
 * Create a new rate card.
 *
 * Form collects: name, description, effective_from, effective_to, is_default,
 * and a dynamic items table (one row per equipment type with rate columns).
 *
 * On submit: POST to api/v1/rate_cards/create with items[] array.
 * On success: redirect to rates/show?id=<new_id>.
 *
 * D30: asset_url() / base_url().
 * D32: Only confirmed CSS classes.
 * D16: Rate fields use step="0.01" — bcmath validation happens server-side.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/rate_cards/create.php, api/v1/equipment/templates/index.php
 * @decisions D7/D16/D30/D32
 * @session  S019
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('rates', 'create');

// Load equipment templates for the items dropdown (active only)
$templates = db_select(
    "SELECT id, name, category, default_daily_rate, default_weekly_rate,
            default_monthly_rate, default_mileage_rate, default_currency, default_mileage_unit
     FROM equipment_templates
     WHERE deleted_at IS NULL AND is_active = 1
     ORDER BY name ASC"
);

$pageTitle = 'New Rate Card';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('rates') ?>" class="btn btn-ghost btn-sm">← Back to Rates</a>
    <h1 class="page-header-title">New Rate Card</h1>
</div>

<div x-data="FF_RateCardCreate()" x-init="init()">

    <!-- Global error -->
    <div class="alert alert-danger" x-show="globalError" x-text="globalError"
         style="margin-bottom:16px;" x-cloak></div>

    <form @submit.prevent="submit()">
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header">
                <h3 class="card-title">Rate Card Details</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">

                    <!-- Name -->
                    <div class="form-group">
                        <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                        <input type="text" id="name" class="form-control"
                               :class="errors.name ? 'is-invalid' : ''"
                               x-model="form.name" maxlength="255"
                               placeholder="e.g. Standard 2025 Rates">
                        <div class="invalid-feedback" x-show="errors.name" x-text="errors.name"></div>
                    </div>

                    <!-- Effective From -->
                    <div class="form-group">
                        <label class="form-label" for="effective_from">Effective From <span class="text-danger">*</span></label>
                        <input type="date" id="effective_from" class="form-control"
                               :class="errors.effective_from ? 'is-invalid' : ''"
                               x-model="form.effective_from">
                        <div class="invalid-feedback" x-show="errors.effective_from" x-text="errors.effective_from"></div>
                    </div>

                    <!-- Effective To -->
                    <div class="form-group">
                        <label class="form-label" for="effective_to">Effective To</label>
                        <input type="date" id="effective_to" class="form-control"
                               :class="errors.effective_to ? 'is-invalid' : ''"
                               x-model="form.effective_to">
                        <div class="form-hint">Leave blank for open-ended (no expiry).</div>
                        <div class="invalid-feedback" x-show="errors.effective_to" x-text="errors.effective_to"></div>
                    </div>

                    <!-- Is Default -->
                    <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:28px;">
                        <input type="checkbox" id="is_default" class="form-check-input"
                               x-model="form.is_default">
                        <label for="is_default" class="form-label" style="margin:0;">
                            Set as Default Rate Card
                        </label>
                    </div>

                    <!-- Description -->
                    <div class="form-group form-group--full">
                        <label class="form-label" for="description">Description</label>
                        <input type="text" id="description" class="form-control"
                               x-model="form.description" maxlength="1000"
                               placeholder="Optional notes about this rate card">
                    </div>

                </div>

                <div class="form-hint" x-show="form.is_default" style="margin-top:8px;">
                    ⚠ Setting this as default will remove default status from any existing default card.
                </div>

            </div>
        </div>

        <!-- ── Rate Items ─────────────────────────────────────────────────── -->
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="card-title">Rate Items</h3>
                <button type="button" class="btn btn-secondary btn-sm"
                        @click="addItem()">+ Add Equipment Type</button>
            </div>
            <div class="card-body">

                <template x-if="items.length === 0">
                    <p class="text-secondary" style="font-size:0.875rem;">
                        No items yet. Add at least one equipment type to define rates.
                    </p>
                </template>

                <template x-if="items.length > 0">
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Equipment Type <span class="text-danger">*</span></th>
                                    <th style="text-align:right;">Daily ($)</th>
                                    <th style="text-align:right;">Weekly ($)</th>
                                    <th style="text-align:right;">Monthly ($)</th>
                                    <th style="text-align:right;">Mileage Rate</th>
                                    <th>Unit</th>
                                    <th>Currency</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(item, idx) in items" :key="idx">
                                    <tr>
                                        <td>
                                            <select class="form-select form-select-sm"
                                                    x-model="item.equipment_type"
                                                    @change="onTypeChange(idx)">
                                                <option value="">— Select —</option>
                                                <?php foreach ($templates as $t): ?>
                                                <option value="<?= e($t['name']) ?>"
                                                        data-daily="<?= e($t['default_daily_rate'] ?? '') ?>"
                                                        data-weekly="<?= e($t['default_weekly_rate'] ?? '') ?>"
                                                        data-monthly="<?= e($t['default_monthly_rate'] ?? '') ?>"
                                                        data-mileage="<?= e($t['default_mileage_rate'] ?? '') ?>"
                                                        data-currency="<?= e($t['default_currency']) ?>"
                                                        data-unit="<?= e($t['default_mileage_unit']) ?>">
                                                    <?= e($t['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control font-mono"
                                                   style="width:100px;text-align:right;"
                                                   x-model="item.daily_rate"
                                                   step="0.01" min="0" placeholder="0.00">
                                        </td>
                                        <td>
                                            <input type="number" class="form-control font-mono"
                                                   style="width:100px;text-align:right;"
                                                   x-model="item.weekly_rate"
                                                   step="0.01" min="0" placeholder="0.00">
                                        </td>
                                        <td>
                                            <input type="number" class="form-control font-mono"
                                                   style="width:110px;text-align:right;"
                                                   x-model="item.monthly_rate"
                                                   step="0.01" min="0" placeholder="0.00">
                                        </td>
                                        <td>
                                            <input type="number" class="form-control font-mono"
                                                   style="width:90px;text-align:right;"
                                                   x-model="item.mileage_rate"
                                                   step="0.0001" min="0" placeholder="0.0000">
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm"
                                                    x-model="item.mileage_unit" style="width:80px;">
                                                <option value="km">km</option>
                                                <option value="miles">miles</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm"
                                                    x-model="item.currency" style="width:75px;">
                                                <option value="CAD">CAD</option>
                                                <option value="USD">USD</option>
                                            </select>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-danger btn-sm"
                                                    @click="removeItem(idx)">×</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>

        <!-- ── Submit ──────────────────────────────────────────────────────── -->
        <div style="display:flex;gap:12px;justify-content:flex-end;margin-bottom:32px;">
            <a href="<?= base_url('rates') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"
                    :disabled="submitting">
                <span x-text="submitting ? 'Saving…' : 'Create Rate Card'"></span>
            </button>
        </div>

    </form>
</div>

<script>
function FF_RateCardCreate() {
    return {
        form: {
            name:           '',
            description:    '',
            effective_from: '<?= date('Y-m-d') ?>',
            effective_to:   '',
            is_default:     false,
        },
        items:       [],
        errors:      {},
        globalError: null,
        submitting:  false,

        init() {
            // Start with one empty item row for convenience
            this.addItem();
        },

        addItem() {
            this.items.push({
                equipment_type: '',
                daily_rate:     '',
                weekly_rate:    '',
                monthly_rate:   '',
                mileage_rate:   '',
                mileage_unit:   'km',
                currency:       'CAD',
            });
        },

        removeItem(idx) {
            this.items.splice(idx, 1);
        },

        // Pre-fill rates when equipment type is selected from the dropdown
        onTypeChange(idx) {
            const item     = this.items[idx];
            const selectEl = document.querySelectorAll('select[x-model="item.equipment_type"]')[idx];
            if (!selectEl) return;
            const opt = selectEl.options[selectEl.selectedIndex];
            if (!opt || !opt.value) return;

            // Pre-fill from template defaults (user can override)
            if (opt.dataset.daily)    item.daily_rate    = opt.dataset.daily;
            if (opt.dataset.weekly)   item.weekly_rate   = opt.dataset.weekly;
            if (opt.dataset.monthly)  item.monthly_rate  = opt.dataset.monthly;
            if (opt.dataset.mileage)  item.mileage_rate  = opt.dataset.mileage;
            if (opt.dataset.currency) item.currency      = opt.dataset.currency;
            if (opt.dataset.unit)     item.mileage_unit  = opt.dataset.unit;
        },

        validate() {
            this.errors = {};
            if (!this.form.name.trim())           this.errors.name = 'Name is required.';
            if (!this.form.effective_from)        this.errors.effective_from = 'Effective From is required.';
            if (this.form.effective_to && this.form.effective_to < this.form.effective_from) {
                this.errors.effective_to = 'Effective To must be on or after Effective From.';
            }
            // Items: each must have an equipment_type selected
            const seen = new Set();
            for (let i = 0; i < this.items.length; i++) {
                if (!this.items[i].equipment_type) {
                    this.errors[`item_type_${i}`] = true;
                    this.globalError = 'All item rows must have an equipment type selected.';
                    return false;
                }
                if (seen.has(this.items[i].equipment_type)) {
                    this.globalError = `Duplicate equipment type: ${this.items[i].equipment_type}`;
                    return false;
                }
                seen.add(this.items[i].equipment_type);
            }
            return Object.keys(this.errors).length === 0;
        },

        async submit() {
            this.globalError = null;
            if (!this.validate()) return;

            this.submitting = true;

            // Build payload — omit empty rate strings
            const items = this.items.map(item => {
                const out = { equipment_type: item.equipment_type,
                               mileage_unit:   item.mileage_unit,
                               currency:       item.currency };
                if (item.daily_rate   !== '') out.daily_rate   = item.daily_rate;
                if (item.weekly_rate  !== '') out.weekly_rate  = item.weekly_rate;
                if (item.monthly_rate !== '') out.monthly_rate = item.monthly_rate;
                if (item.mileage_rate !== '') out.mileage_rate = item.mileage_rate;
                return out;
            });

            const payload = {
                name:           this.form.name.trim(),
                description:    this.form.description.trim() || null,
                effective_from: this.form.effective_from,
                effective_to:   this.form.effective_to || null,
                is_default:     this.form.is_default ? 1 : 0,
                items:          items,
            };

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/create') ?>', payload);
                window.location = '<?= base_url('rates/show') ?>?id=' + r.data.id;
            } catch (e) {
                this.globalError = e.message || 'An error occurred. Please try again.';
                this.submitting  = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
