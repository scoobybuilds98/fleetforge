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
                        One rate per equipment category — or per specific unit type for custom overrides.
                    </p>
                </div>
                <button type="button" class="btn btn-secondary btn-sm"
                        @click="addItem()">+ Add Rate</button>
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
                                    <!-- S-RATE-CARD-TEMPLATE-ITEM: optional unit-type override -->
                                    <div style="margin-top:8px;">
                                        <div style="font-size:0.7rem;color:var(--text-muted);margin-bottom:3px;">
                                            Specific Unit Type
                                            <span style="font-weight:400;">(optional — leave blank to apply to all
                                                <span x-text="item.equipment_type ? '&quot;' + categoryLabel(item.equipment_type) + '&quot;' : 'this category'"></span>)
                                            </span>
                                        </div>
                                        <template x-if="item.equipment_template_id">
                                            <div style="display:flex;align-items:center;gap:6px;">
                                                <span class="badge badge-info" style="font-size:0.75rem;" x-text="item._templateName"></span>
                                                <button type="button"
                                                        style="background:none;border:none;cursor:pointer;color:var(--text-secondary);font-size:0.75rem;padding:0 4px;"
                                                        @click="clearTemplate(idx)">× clear</button>
                                            </div>
                                        </template>
                                        <template x-if="!item.equipment_template_id">
                                            <div style="position:relative;" @click.outside="item._templateOpen = false">
                                                <input type="text" class="form-control"
                                                       style="font-size:0.8125rem;height:34px;"
                                                       :disabled="!item.equipment_type"
                                                       :placeholder="item.equipment_type ? 'Search unit types…' : 'Select category first'"
                                                       x-model="item._templateSearch"
                                                       @input.debounce.300ms="searchTemplates(idx, $event.target.value)"
                                                       @focus="if(item._templateResults.length) item._templateOpen = true">
                                                <div x-show="item._templateOpen" x-cloak
                                                     style="position:absolute;top:100%;left:0;right:0;z-index:100;
                                                            background:var(--bg-primary);border:1px solid var(--border-color);
                                                            border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.12);
                                                            max-height:160px;overflow-y:auto;margin-top:2px;">
                                                    <template x-for="tmpl in item._templateResults" :key="tmpl.id">
                                                        <div style="padding:8px 12px;cursor:pointer;font-size:0.8125rem;"
                                                             onmouseover="this.style.background='var(--bg-secondary)'"
                                                             onmouseout="this.style.background=''"
                                                             @mousedown.prevent="pickTemplate(idx, tmpl)"
                                                             x-text="tmpl.name"></div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
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

                                    <!-- Hourly (reefer) -->
                                    <div>
                                        <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Hourly (reefer) $/hr</label>
                                        <div style="position:relative;">
                                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                            <input type="number" class="form-control font-mono"
                                                   style="padding-left:22px;"
                                                   x-model="item.hourly_rate"
                                                   step="0.0001" min="0" placeholder="0.0000">
                                        </div>
                                    </div>

                                    <!-- GPS rate ($/day) -->
                                    <div>
                                        <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">GPS $/day</label>
                                        <div style="position:relative;">
                                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                            <input type="number" class="form-control font-mono"
                                                   style="padding-left:22px;"
                                                   x-model="item.gps_price"
                                                   step="0.01" min="0" placeholder="0.00">
                                        </div>
                                    </div>

                                    <!-- S-LEASE-MIN-DAYS: per-equipment short-lease floor. When a lease's
                                         total billable duration is shorter than this many days, billing
                                         bills a flat N × daily rate instead of the actual short span.
                                         Blank = no minimum for this equipment. -->
                                    <div>
                                        <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Min days</label>
                                        <input type="number" class="form-control font-mono"
                                               x-model="item.minimum_days"
                                               step="1" min="0" placeholder="—">
                                        <div class="form-hint" style="font-size:0.7rem;margin-top:3px;">Short-lease floor for this equipment (blank = none)</div>
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
// Category slug → display label (mirrors PHP $categoryLabels)
const CATEGORY_LABELS_CREATE = {
    chassis: 'Chassis', dry_van: 'Dry Van', reefer: 'Reefer',
    container: 'Container', flatbed: 'Flatbed', step_deck: 'Step Deck',
    lowboy: 'Lowboy', tanker: 'Tanker', dump: 'Dump', other: 'Other',
};

function FF_RateCardCreate() {
    return {
        form: {
            name:           '',
            description:    '',
            effective_from: '<?= date('Y-m-d') ?>',
            effective_to:   '',
            is_default:     false,
            customer_id:    <?= $preCustomerId ? (int)$preCustomerId : 'null' ?>,
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

        categoryLabel(slug) {
            if (!slug) return '';
            return CATEGORY_LABELS_CREATE[slug] || slug.replace(/_/g, ' ');
        },

        addItem() {
            this.items.push({
                _key:                  this._nextKey++,
                _error:                '',
                equipment_type:        '',
                equipment_template_id: null,
                _templateName:         '',
                _templateSearch:       '',
                _templateResults:      [],
                _templateOpen:         false,
                daily_rate:            '',
                weekly_rate:           '',
                monthly_rate:          '',
                mileage_rate:          '',
                mileage_unit:          'km',
                hourly_rate:           '',
                gps_price:             '',
                minimum_days:          '', // S-LEASE-MIN-DAYS: short-lease floor (blank = none)
                currency:              'CAD',
            });
        },

        removeItem(idx) {
            this.items.splice(idx, 1);
        },

        onTypeChange(idx) {
            // Clear per-item error and any selected template when category changes
            this.items[idx]._error = '';
            this.clearTemplate(idx);
        },

        // S-RATE-CARD-TEMPLATE-ITEM: template search / pick / clear
        async searchTemplates(idx, query) {
            const item = this.items[idx];
            if (!query || query.length < 1 || !item.equipment_type) {
                item._templateResults = [];
                item._templateOpen    = false;
                return;
            }
            try {
                const params = new URLSearchParams({ search: query, category: item.equipment_type, per_page: 10, active: 1 });
                const r = await FF_Api.get(FF_Api.url('/api/v1/equipment/templates/index.php') + '?' + params.toString());
                if (r.success) {
                    item._templateResults = r.data.items || [];
                    item._templateOpen    = item._templateResults.length > 0;
                }
            } catch (e) { /* silent — search errors don't block form */ }
        },

        pickTemplate(idx, tmpl) {
            const item               = this.items[idx];
            item.equipment_template_id = tmpl.id;
            item._templateName       = tmpl.name;
            item._templateSearch     = tmpl.name;
            item._templateOpen       = false;
            // Auto-fill category from template if not already set
            if (!item.equipment_type && tmpl.category) item.equipment_type = tmpl.category;
        },

        clearTemplate(idx) {
            const item               = this.items[idx];
            item.equipment_template_id = null;
            item._templateName       = '';
            item._templateSearch     = '';
            item._templateResults    = [];
            item._templateOpen       = false;
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
            // S-RATE-CARD-TEMPLATE-ITEM: dedup key = "t:{templateId}" or "c:{equipmentType}"
            const seen     = new Set();
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

                const key = item.equipment_template_id
                    ? `t:${item.equipment_template_id}`
                    : `c:${item.equipment_type}`;
                if (seen.has(key)) {
                    item._error = 'Duplicate — this category / unit type combination is already listed.';
                    problems.push(`Item ${num}: duplicate combination.`);
                    ok = false;
                    continue;
                }
                seen.add(key);

                const rateFields = { daily_rate: 'Daily', weekly_rate: 'Weekly', monthly_rate: 'Monthly', mileage_rate: 'Mileage', hourly_rate: 'Hourly', gps_price: 'GPS rate' };
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
                    equipment_type:        item.equipment_type,
                    equipment_template_id: item.equipment_template_id || null,
                    mileage_unit:          item.mileage_unit,
                    currency:              item.currency,
                };
                if (item.daily_rate   !== '') out.daily_rate   = item.daily_rate;
                if (item.weekly_rate  !== '') out.weekly_rate  = item.weekly_rate;
                if (item.monthly_rate !== '') out.monthly_rate = item.monthly_rate;
                if (item.mileage_rate !== '') out.mileage_rate = item.mileage_rate;
                if (item.hourly_rate  !== '') out.hourly_rate  = item.hourly_rate;
                if (item.gps_price    !== '') out.gps_price    = item.gps_price;
                if (item.minimum_days !== '') out.minimum_days = item.minimum_days; // S-LEASE-MIN-DAYS
                return out;
            });

            const payload = {
                name:           this.form.name.trim(),
                description:    this.form.description.trim() || null,
                effective_from: this.form.effective_from,
                effective_to:   this.form.effective_to || null,
                is_default:     this.form.is_default ? 1 : 0,
                customer_id:    this.form.customer_id || null,
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
