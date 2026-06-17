<?php
declare(strict_types=1);

/**
 * app/admin/rates/create.php
 *
 * Create a new rate card.
 * Optional customer_id links the card to a specific customer; NULL = global.
 * Equipment type stored as category slug (chassis|dry_van|…) matching
 * lookup_rates.php — fixes S-RATES-UI-CATEGORY-DEDUP.
 * No auto-added blank row on init — items added explicitly via button.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/rate_cards/create.php, includes/partials/record-picker.php
 * @decisions D7/D16/D30/D32
 * @session  S019, S-RATES-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('rates', 'create');

// Distinct equipment categories from active templates — one rate per category
$categories = db_select(
    "SELECT DISTINCT category FROM equipment_templates
     WHERE deleted_at IS NULL AND is_active = 1
     ORDER BY category ASC"
);

// Pre-fill customer if passed via query string (e.g. from customer profile)
$preCustomerId    = clean_int($_GET['customer_id'] ?? null);
$preCustomerLabel = '';
if ($preCustomerId) {
    $preCustomer = db_row("SELECT company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$preCustomerId]);
    if ($preCustomer) {
        $preCustomerLabel = $preCustomer['company_name'];
    } else {
        $preCustomerId = null;
    }
}

// Friendly label map for category slugs
$categoryLabels = [
    'chassis'   => 'Chassis',
    'dry_van'   => 'Dry Van',
    'reefer'    => 'Reefer',
    'container' => 'Container',
    'flatbed'   => 'Flatbed',
    'step_deck' => 'Step Deck',
    'lowboy'    => 'Lowboy',
    'tanker'    => 'Tanker',
    'dump'      => 'Dump',
    'other'     => 'Other',
];

$pageTitle      = 'New Rate Card';
$helpModuleSlug = 'rates';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('rates') ?>" class="btn btn-ghost btn-sm">← Back to Rates</a>
    <h1 class="page-header-title">New Rate Card</h1>
    <div class="page-header-actions">
        <?= help_button('rates') ?>
    </div>
</div>

<div x-data="FF_RateCardCreate()" x-init="init()">

    <!-- Global error -->
    <div class="alert alert-danger" x-show="globalError" x-text="globalError"
         style="margin-bottom:16px;" x-cloak></div>

    <form @submit.prevent="submit()">

        <!-- ── Card Details ──────────────────────────────────────────────── -->
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header">
                <h3 class="card-title">Rate Card Details</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">

                    <!-- Name -->
                    <div class="form-group">
                        <label class="form-label" for="name">Card Name <span class="text-danger">*</span></label>
                        <input type="text" id="name" class="form-control"
                               :class="errors.name ? 'is-invalid' : ''"
                               x-model="form.name" maxlength="255"
                               placeholder="e.g. Standard 2025 Rates">
                        <div class="form-hint" style="text-align:right;" x-text="(form.name || '').length + ' / 255'"></div>
                        <div class="invalid-feedback" x-show="errors.name" x-text="errors.name"></div>
                    </div>

                    <!-- Customer picker (optional) -->
                    <div class="form-group">
                        <label class="form-label">Customer <span class="text-secondary" style="font-weight:400;">(optional)</span></label>
                        <?php
                        $pickerName     = 'customerPicker';
                        $pickerConfig   = [
                            'endpoint'    => '/api/v1/customers/index.php',
                            'searchParam' => 'search',
                            'resultKey'   => 'items',
                            'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: (r.city ?? '') + (r.province ? ', ' + r.province : '') })",
                            'placeholder' => 'Leave blank for a global rate card…',
                            'initialId'   => $preCustomerId,
                            'initialLabel'=> $preCustomerLabel,
                        ];
                        $pickerOnPicked  = 'form.customer_id = $event.detail.id';
                        $pickerOnCleared = "form.customer_id = null";
                        $pickerError     = 'errors.customer_id';
                        require FF_ROOT . '/includes/partials/record-picker.php';
                        ?>
                        <div class="form-hint">Global rate cards apply to all customers. A customer-specific card takes priority for that customer.</div>
                        <div class="invalid-feedback" x-show="errors.customer_id" x-text="errors.customer_id"></div>
                    </div>

                    <!-- Effective From -->
                    <div class="form-group">
                        <label class="form-label" for="effective_from">Effective From <span class="text-danger">*</span></label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="effective_from" class="form-control"
                                   :class="errors.effective_from ? 'is-invalid' : ''"
                                   x-model="form.effective_from"
                                   x-ref="rateEffFrom" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;"
                                    @click="$refs.rateEffFrom.showPicker ? $refs.rateEffFrom.showPicker() : $refs.rateEffFrom.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
                        <div class="invalid-feedback" x-show="errors.effective_from" x-text="errors.effective_from"></div>
                    </div>

                    <!-- Effective To -->
                    <div class="form-group">
                        <label class="form-label" for="effective_to">Effective To</label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="effective_to" class="form-control"
                                   :class="errors.effective_to ? 'is-invalid' : ''"
                                   x-model="form.effective_to"
                                   :min="form.effective_from || ''"
                                   x-ref="rateEffTo" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;"
                                    @click="$refs.rateEffTo.showPicker ? $refs.rateEffTo.showPicker() : $refs.rateEffTo.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
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
                               x-model="form.description" maxlength="255"
                               placeholder="Optional notes about this rate card">
                        <div class="form-hint" style="text-align:right;" x-text="(form.description || '').length + ' / 255'"></div>
                    </div>

                    <!-- GPS Price — S-GPS-RATE-CARD -->
                    <div class="form-group">
                        <label class="form-label" for="gps_price">GPS Tracking Rate ($/day)</label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                            <input type="number" id="gps_price" class="form-control font-mono"
                                   style="padding-left:22px;"
                                   :class="errors.gps_price ? 'is-invalid' : ''"
                                   x-model="form.gps_price"
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-hint">Per-day GPS charge applied to leases using this card. Leave blank for no GPS charge.</div>
                        <div class="invalid-feedback" x-show="errors.gps_price" x-text="errors.gps_price"></div>
                    </div>

                </div>

                <div class="alert alert-warning" x-show="form.is_default" style="margin-top:8px;padding:10px 14px;" x-cloak>
                    Setting this as default will remove the default flag from any existing default card.
                </div>

            </div>
        </div>

        <!-- ── Rate Items ─────────────────────────────────────────────────── -->
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 class="card-title" style="margin:0;">Rate Items</h3>
                    <p class="text-secondary" style="font-size:0.8125rem;margin:2px 0 0;">
                        One card per equipment category. Rates are used when creating leases.
                    </p>
                </div>
                <button type="button" class="btn btn-secondary btn-sm"
                        x-show="items.length === 0"
                        @click="addItem()">+ Add Equipment Type</button>
            </div>

            <!-- Empty state -->
            <template x-if="items.length === 0">
                <div class="card-body" style="text-align:center;padding:40px 24px;">
                    <div style="width:44px;height:44px;border-radius:50%;background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:22px;height:22px;color:var(--text-secondary);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33"/></svg>
                    </div>
                    <p class="text-secondary" style="font-size:0.875rem;margin:0;">No rate items yet.</p>
                    <p class="text-secondary" style="font-size:0.8125rem;margin:4px 0 0;">Click <strong>+ Add Equipment Type</strong> to define rates per category.</p>
                </div>
            </template>

            <!-- Rate item cards -->
            <template x-if="items.length > 0">
                <div class="card-body" style="padding-top:8px;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;">
                        <template x-for="(item, idx) in items" :key="item._key">
                            <div :style="item._error ? 'border:1px solid var(--color-danger);' : 'border:1px solid var(--border-color);'"
                                 style="border-radius:10px;background:var(--bg-secondary);overflow:hidden;">

                                <!-- Header: equipment type + currency + remove -->
                                <div style="padding:14px 16px;border-bottom:1px solid var(--border-color);">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                        <label class="form-label" style="margin:0;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.03em;color:var(--text-secondary);">Equipment Type <span class="text-danger">*</span></label>
                                        <button type="button"
                                                style="background:none;border:none;cursor:pointer;color:var(--text-secondary);font-size:1.25rem;line-height:1;padding:0 2px;flex-shrink:0;"
                                                onmouseover="this.style.color='var(--color-danger)'"
                                                onmouseout="this.style.color='var(--text-secondary)'"
                                                @click="removeItem(idx)"
                                                title="Remove this equipment type">&times;</button>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <select class="form-select"
                                                x-model="item.equipment_type"
                                                :class="item._error ? 'is-invalid' : ''"
                                                @change="onTypeChange(idx)"
                                                style="flex:1;min-width:0;">
                                            <option value="">— Select type —</option>
                                            <?php foreach ($categories as $cat): ?>
                                            <option value="<?= e($cat['category']) ?>">
                                                <?= e($categoryLabels[$cat['category']] ?? ucfirst(str_replace('_', ' ', $cat['category']))) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select class="form-select"
                                                x-model="item.currency"
                                                style="width:90px;flex-shrink:0;">
                                            <option value="CAD">CAD</option>
                                            <option value="USD">USD</option>
                                        </select>
                                    </div>
                                    <div x-show="item._error" class="text-danger" style="font-size:0.8rem;margin-top:6px;"
                                         x-text="item._error"></div>
                                </div>

                                <!-- Body: rate inputs 2×2 grid -->
                                <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">

                                    <!-- Daily -->
                                    <div>
                                        <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Daily Rate</label>
                                        <div style="position:relative;">
                                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                            <input type="number" class="form-control font-mono"
                                                   style="padding-left:22px;"
                                                   x-model="item.daily_rate"
                                                   step="0.01" min="0" placeholder="0.00">
                                        </div>
                                    </div>

                                    <!-- Weekly -->
                                    <div>
                                        <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Weekly Rate</label>
                                        <div style="position:relative;">
                                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                            <input type="number" class="form-control font-mono"
                                                   style="padding-left:22px;"
                                                   x-model="item.weekly_rate"
                                                   step="0.01" min="0" placeholder="0.00">
                                        </div>
                                    </div>

                                    <!-- Monthly -->
                                    <div>
                                        <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Monthly Rate</label>
                                        <div style="position:relative;">
                                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                            <input type="number" class="form-control font-mono"
                                                   style="padding-left:22px;"
                                                   x-model="item.monthly_rate"
                                                   step="0.01" min="0" placeholder="0.00">
                                        </div>
                                    </div>

                                    <!-- Mileage -->
                                    <div>
                                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;height:18px;">
                                            <label class="form-label" style="font-size:0.75rem;margin:0;">Mileage Rate</label>
                                            <select class="form-select"
                                                    x-model="item.mileage_unit"
                                                    style="width:auto;height:20px;font-size:0.7rem;padding:0 18px 0 6px;">
                                                <option value="km">/ km</option>
                                                <option value="miles">/ mi</option>
                                            </select>
                                        </div>
                                        <div style="position:relative;">
                                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                            <input type="number" class="form-control font-mono"
                                                   style="padding-left:22px;"
                                                   x-model="item.mileage_rate"
                                                   step="0.0001" min="0" placeholder="0.0000">
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </template>
                    </div>

                </div>
            </template>
        </div>

        <!-- ── Submit ──────────────────────────────────────────────────────── -->
        <div style="display:flex;gap:12px;justify-content:flex-end;margin-bottom:32px;">
            <a href="<?= base_url('rates') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"
                    :disabled="submitting">
                <span x-text="submitting ? 'Creating…' : 'Create Rate Card'"></span>
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
            customer_id:    <?= $preCustomerId ? (int)$preCustomerId : 'null' ?>,
            gps_price:      '',
        },
        items:       [],
        _nextKey:    0,
        errors:      {},
        globalError: null,
        submitting:  false,

        init() {
            // No auto-added blank row — user adds explicitly
            if (window.FF_FormDraft) { // S-FORM-DRAFT-ROLLOUT
                this._draft = FF_FormDraft.attach({ // S-FORM-DRAFT-ROLLOUT
                    formId: 'rate-card-create', // S-FORM-DRAFT-ROLLOUT
                    entityId: 'new', // S-FORM-DRAFT-ROLLOUT
                    el: this.$root, // S-FORM-DRAFT-ROLLOUT
                    model: this.form, // S-FORM-DRAFT-ROLLOUT
                    version: '1', // S-FORM-DRAFT-ROLLOUT
                    exclude: ['customer_id'], // S-FORM-DRAFT-ROLLOUT
                }); // S-FORM-DRAFT-ROLLOUT
            } // S-FORM-DRAFT-ROLLOUT
        },

        addItem() {
            if (this.items.length >= 1) return;
            this.items.push({
                _key:           this._nextKey++,
                _error:         '',
                equipment_type: '',
                daily_rate:     '',
                weekly_rate:    '',
                monthly_rate:   '',
                mileage_rate:   '',
                mileage_unit:   'km',
                currency:       'CAD',
            });
            // Scroll table into view if it just appeared
            this.$nextTick(() => {
                const tbl = this.$el.querySelector('table');
                if (tbl) tbl.querySelector('tbody tr:last-child')?.scrollIntoView({ block: 'nearest' });
            });
        },

        removeItem(idx) {
            this.items.splice(idx, 1);
        },

        onTypeChange(idx) {
            // Clear any duplicate-type error on change
            this.items[idx]._error = '';
        },

        validate() {
            this.errors      = {};
            this.globalError = null;

            // Clear per-item errors
            this.items.forEach(i => i._error = '');

            let ok = true;

            if (!this.form.name?.trim()) {
                this.errors.name = 'Rate card name is required.';
                ok = false;
            }
            if (!this.form.effective_from) {
                this.errors.effective_from = 'Effective from date is required.';
                ok = false;
            }
            if (this.form.effective_to && this.form.effective_from &&
                this.form.effective_to < this.form.effective_from) {
                this.errors.effective_to = 'End date must be on or after the start date.';
                ok = false;
            }
            if (this.form.gps_price !== '' && this.form.gps_price != null) {
                const gn = parseFloat(this.form.gps_price);
                if (isNaN(gn) || gn < 0) {
                    this.errors.gps_price = 'GPS price must be a valid non-negative number.';
                    ok = false;
                }
            }

            const seen = new Set();
            const problems = [];
            for (let i = 0; i < this.items.length; i++) {
                const item = this.items[i];
                const num  = i + 1;

                if (!item.equipment_type) {
                    item._error = 'Select an equipment category.';
                    problems.push(`Item ${num}: equipment category required.`);
                    ok = false;
                    continue;
                }
                if (seen.has(item.equipment_type)) {
                    item._error = 'Duplicate category — each may appear only once.';
                    problems.push(`Item ${num}: '${item.equipment_type}' listed more than once.`);
                    ok = false;
                    continue;
                }
                seen.add(item.equipment_type);

                const rateFields = { daily_rate: 'Daily', weekly_rate: 'Weekly', monthly_rate: 'Monthly', mileage_rate: 'Mileage' };
                for (const [f, label] of Object.entries(rateFields)) {
                    const raw = item[f];
                    if (raw === '' || raw == null) continue;
                    const n = parseFloat(raw);
                    if (isNaN(n))  { problems.push(`Item ${num}: ${label} rate must be a number.`); ok = false; }
                    else if (n < 0){ problems.push(`Item ${num}: ${label} rate cannot be negative.`); ok = false; }
                }
            }

            if (problems.length > 0) {
                this.globalError = problems.join(' ');
            }

            return ok;
        },

        async submit() {
            if (!this.validate()) return;

            this.submitting  = true;
            this.globalError = null;

            const items = this.items.map(item => {
                const out = {
                    equipment_type: item.equipment_type,
                    mileage_unit:   item.mileage_unit,
                    currency:       item.currency,
                };
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
                customer_id:    this.form.customer_id || null,
                gps_price:      this.form.gps_price !== '' ? this.form.gps_price : null,
                items:          items,
            };

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/create') ?>', payload);
                if (!r.success) {
                    if (r.error?.fields) {
                        this.errors = r.error.fields;
                        if (r.error.fields.items) this.globalError = r.error.fields.items;
                    }
                    this.globalError = this.globalError || r.error?.message || 'Failed to create rate card.';
                    this.submitting  = false;
                    return;
                }
                if (this._draft) this._draft.clear(true); // S-FORM-DRAFT-ROLLOUT
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
