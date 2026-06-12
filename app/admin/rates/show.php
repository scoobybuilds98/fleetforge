<?php
declare(strict_types=1);

/**
 * app/admin/rates/show.php
 *
 * Rate card detail / edit page.
 * Two-panel layout: card metadata (incl. customer) + rate items table.
 * Equipment type dropdown uses category slugs (S-RATES-UI-CATEGORY-DEDUP fix).
 *
 * D30: asset_url() / base_url().
 * D32: Only confirmed CSS classes.
 * D19: updated_at passed on every update.
 * D5:  rate_cards has deleted_at — 404 if card soft-deleted.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/rate_cards/show.php, api/v1/rate_cards/update.php
 *           includes/partials/record-picker.php
 * @decisions D5/D7/D16/D19/D30/D32
 * @session  S019, S-RATES-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('rates', 'view');

// ── Resolve rate card ────────────────────────────────────────────────────────
$cardId = clean_int($_GET['id'] ?? null);
if (!$cardId) { http_response_code(400); die('Missing id parameter.'); }

$card = db_row(
    "SELECT rc.*, c.company_name AS customer_name, u.name AS created_by_name
     FROM rate_cards rc
     LEFT JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users u ON u.id = rc.created_by AND u.deleted_at IS NULL
     WHERE rc.id = ? AND rc.deleted_at IS NULL",
    [$cardId]
);
if (!$card) { http_response_code(404); die('Rate card not found.'); }

$items = db_select(
    "SELECT id, equipment_type, daily_rate, weekly_rate, monthly_rate,
            mileage_rate, mileage_unit, currency, notes, updated_at
     FROM rate_card_items WHERE rate_card_id = ?
     ORDER BY equipment_type ASC",
    [$cardId]
);

// Distinct equipment categories for the items editor
$categories = db_select(
    "SELECT DISTINCT category FROM equipment_templates
     WHERE deleted_at IS NULL AND is_active = 1
     ORDER BY category ASC"
);

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

$today    = date('Y-m-d');
$isActive = ($card['effective_from'] <= $today &&
             ($card['effective_to'] === null || $card['effective_to'] >= $today));

$pageTitle = e($card['name']);
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('rates') ?>" class="btn btn-ghost btn-sm">← Back to Rates</a>
    <h1 class="page-header-title"><?= e($card['name']) ?></h1>
    <div style="display:flex;gap:8px;align-items:center;">
        <span class="badge <?= $isActive ? 'badge-success' : 'badge-neutral' ?>">
            <?= $isActive ? 'Active' : 'Inactive' ?>
        </span>
        <?php if ($card['is_default']): ?>
        <span class="badge badge-info">Default</span>
        <?php endif; ?>
        <?php if ($card['customer_id']): ?>
        <a href="<?= base_url('customers/show') ?>?id=<?= (int)$card['customer_id'] ?>" class="badge badge-neutral" style="text-decoration:none;">
            <?= e($card['customer_name'] ?? 'Customer') ?>
        </a>
        <?php else: ?>
        <span class="badge badge-neutral">Global</span>
        <?php endif; ?>
    </div>
</div>

<div x-data="FF_RateCardShow()" x-init="init()">

    <!-- Global messages -->
    <div class="alert alert-danger" x-show="globalError" x-text="globalError"
         style="margin-bottom:16px;" x-cloak></div>
    <div class="alert alert-success" x-show="saveSuccess"
         style="margin-bottom:16px;" x-cloak>Rate card saved successfully.</div>

    <!-- ── Card metadata ─────────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">Card Details</h3>
            <?php if (can('rates', 'edit')): ?>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-secondary btn-sm" x-show="!editMode" @click="editMode = true">Edit</button>
                <button class="btn btn-secondary btn-sm" x-show="editMode"  @click="cancelEdit()">Cancel</button>
                <button class="btn btn-primary btn-sm"   x-show="editMode"  :disabled="saving" @click="saveCard()">
                    <span x-text="saving ? 'Saving…' : 'Save Changes'"></span>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="form-grid">

                <!-- Name -->
                <div class="form-group">
                    <label class="form-label">Card Name</label>
                    <div x-show="!editMode" class="form-control-static" x-text="form.name"></div>
                    <input x-show="editMode" type="text" class="form-control" x-model="form.name" maxlength="255">
                </div>

                <!-- Customer -->
                <div class="form-group">
                    <label class="form-label">Customer
                        <span x-show="!editMode && !form.customer_id" class="text-secondary" style="font-weight:400;">(global)</span>
                    </label>
                    <!-- View mode -->
                    <template x-if="!editMode">
                        <div class="form-control-static">
                            <template x-if="form.customer_id">
                                <a :href="'<?= base_url('customers/show') ?>?id=' + form.customer_id"
                                   class="link" x-text="form.customer_name || '—'"></a>
                            </template>
                            <template x-if="!form.customer_id">
                                <span class="text-secondary">— Global rate card —</span>
                            </template>
                        </div>
                    </template>
                    <!-- Edit mode -->
                    <template x-if="editMode">
                        <div>
                            <?php
                            $pickerName     = 'customerPickerEdit';
                            $pickerConfig   = [
                                'endpoint'    => '/api/v1/customers/index.php',
                                'searchParam' => 'search',
                                'resultKey'   => 'items',
                                'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: (r.city ?? '') + (r.province ? ', ' + r.province : '') })",
                                'placeholder' => 'Leave blank for global…',
                                'initialId'   => (int)($card['customer_id'] ?? 0) ?: null,
                                'initialLabel'=> $card['customer_name'] ?? '',
                            ];
                            $pickerOnPicked  = 'form.customer_id = $event.detail.id; form.customer_name = $event.detail.label';
                            $pickerOnCleared = 'form.customer_id = null; form.customer_name = null';
                            $pickerError     = 'false';
                            require FF_ROOT . '/includes/partials/record-picker.php';
                            ?>
                            <div class="form-hint">Clear to make this a global rate card.</div>
                        </div>
                    </template>
                </div>

                <!-- Effective From -->
                <div class="form-group">
                    <label class="form-label">Effective From</label>
                    <div x-show="!editMode" class="form-control-static font-mono" x-text="form.effective_from"></div>
                    <input x-show="editMode" type="date" class="form-control" x-model="form.effective_from">
                </div>

                <!-- Effective To -->
                <div class="form-group">
                    <label class="form-label">Effective To</label>
                    <div x-show="!editMode" class="form-control-static font-mono"
                         x-text="form.effective_to || 'Open-ended'"></div>
                    <input x-show="editMode" type="date" class="form-control"
                           x-model="form.effective_to" :min="form.effective_from || ''">
                </div>

                <!-- Is Default -->
                <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:28px;">
                    <div x-show="!editMode">
                        <span class="badge badge-info" x-show="form.is_default">Default Card</span>
                        <span class="text-secondary" x-show="!form.is_default">Not default</span>
                    </div>
                    <template x-if="editMode">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" class="form-check-input" x-model="form.is_default">
                            <label class="form-label" style="margin:0;">Set as Default</label>
                        </div>
                    </template>
                </div>

                <!-- Description -->
                <div class="form-group form-group--full">
                    <label class="form-label">Description</label>
                    <div x-show="!editMode" class="form-control-static" x-text="form.description || '—'"></div>
                    <input x-show="editMode" type="text" class="form-control" x-model="form.description" maxlength="1000">
                </div>

            </div>

            <!-- Meta info -->
            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-color);
                        display:flex;gap:24px;font-size:0.8125rem;color:var(--text-secondary);">
                <span>Created by: <?= e($card['created_by_name'] ?? '—') ?></span>
                <span>Created: <?= e(format_datetime($card['created_at'])) ?></span>
                <span>Updated: <?= e(format_datetime($card['updated_at'])) ?></span>
            </div>
        </div>
    </div>

    <!-- ── Rate Items ─────────────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h3 class="card-title" style="display:inline;">Rate Items</h3>
                <span class="badge badge-neutral" style="font-size:0.75rem;margin-left:8px;"
                      x-text="items.length + ' item' + (items.length === 1 ? '' : 's')"></span>
            </div>
            <?php if (can('rates', 'edit')): ?>
            <button class="btn btn-secondary btn-sm" @click="addItem()">+ Add Row</button>
            <?php endif; ?>
        </div>

        <template x-if="items.length === 0">
            <div class="card-body" style="text-align:center;padding:40px 24px;">
                <p class="text-secondary" style="font-size:0.875rem;margin:0;">No rate items yet.</p>
                <?php if (can('rates', 'edit')): ?>
                <p class="text-secondary" style="font-size:0.8125rem;margin:4px 0 0;">
                    Click <strong>+ Add Row</strong> to define rates per equipment category.
                </p>
                <?php endif; ?>
            </div>
        </template>

        <template x-if="items.length > 0">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="min-width:130px;">Equipment Category</th>
                            <th style="text-align:right;min-width:100px;">Daily ($)</th>
                            <th style="text-align:right;min-width:100px;">Weekly ($)</th>
                            <th style="text-align:right;min-width:105px;">Monthly ($)</th>
                            <th style="text-align:right;min-width:90px;">Mileage</th>
                            <th style="min-width:70px;">Unit</th>
                            <th style="min-width:70px;">Currency</th>
                            <?php if (can('rates', 'edit')): ?>
                            <th style="text-align:right;white-space:nowrap;">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, idx) in items" :key="idx">
                            <tr>
                                <!-- Equipment category -->
                                <td>
                                    <span x-show="!item.editing" class="badge badge-neutral"
                                          style="font-size:0.8125rem;"
                                          x-text="categoryLabel(item.equipment_type)"></span>
                                    <template x-if="item.editing">
                                        <select class="form-select form-select-sm" x-model="item.equipment_type">
                                            <option value="">— Select —</option>
                                            <?php foreach ($categories as $cat): ?>
                                            <option value="<?= e($cat['category']) ?>">
                                                <?= e($categoryLabels[$cat['category']] ?? ucfirst(str_replace('_', ' ', $cat['category']))) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </template>
                                </td>
                                <!-- Daily -->
                                <td class="font-mono" style="text-align:right;">
                                    <span x-show="!item.editing"
                                          x-text="item.daily_rate ? '$' + parseFloat(item.daily_rate).toFixed(2) : '—'"></span>
                                    <input x-show="item.editing" type="number" class="form-control form-control-sm font-mono"
                                           style="text-align:right;" x-model="item.daily_rate" step="0.01" min="0">
                                </td>
                                <!-- Weekly -->
                                <td class="font-mono" style="text-align:right;">
                                    <span x-show="!item.editing"
                                          x-text="item.weekly_rate ? '$' + parseFloat(item.weekly_rate).toFixed(2) : '—'"></span>
                                    <input x-show="item.editing" type="number" class="form-control form-control-sm font-mono"
                                           style="text-align:right;" x-model="item.weekly_rate" step="0.01" min="0">
                                </td>
                                <!-- Monthly -->
                                <td class="font-mono" style="text-align:right;">
                                    <span x-show="!item.editing"
                                          x-text="item.monthly_rate ? '$' + parseFloat(item.monthly_rate).toFixed(2) : '—'"></span>
                                    <input x-show="item.editing" type="number" class="form-control form-control-sm font-mono"
                                           style="text-align:right;" x-model="item.monthly_rate" step="0.01" min="0">
                                </td>
                                <!-- Mileage -->
                                <td class="font-mono" style="text-align:right;">
                                    <span x-show="!item.editing"
                                          x-text="item.mileage_rate ? '$' + parseFloat(item.mileage_rate).toFixed(4) : '—'"></span>
                                    <input x-show="item.editing" type="number" class="form-control form-control-sm font-mono"
                                           style="text-align:right;" x-model="item.mileage_rate" step="0.0001" min="0">
                                </td>
                                <!-- Unit -->
                                <td>
                                    <span x-show="!item.editing" x-text="item.mileage_unit"></span>
                                    <select x-show="item.editing" class="form-select form-select-sm" x-model="item.mileage_unit">
                                        <option value="km">km</option>
                                        <option value="miles">miles</option>
                                    </select>
                                </td>
                                <!-- Currency -->
                                <td>
                                    <span x-show="!item.editing" x-text="item.currency"></span>
                                    <select x-show="item.editing" class="form-select form-select-sm" x-model="item.currency">
                                        <option value="CAD">CAD</option>
                                        <option value="USD">USD</option>
                                    </select>
                                </td>
                                <?php if (can('rates', 'edit')): ?>
                                <td style="text-align:right;white-space:nowrap;">
                                    <button class="btn btn-secondary btn-sm" x-show="!item.editing"
                                            @click="item.editing = true">Edit</button>
                                    <button class="btn btn-primary btn-sm"   x-show="item.editing"
                                            @click="saveItems()">Save All</button>
                                    <button class="btn btn-secondary btn-sm" x-show="item.editing"
                                            @click="cancelItemEdit(idx)">Cancel</button>
                                    <button class="btn btn-ghost btn-sm" style="color:var(--color-danger);"
                                            @click="removeItem(idx)">×</button>
                                </td>
                                <?php endif; ?>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <?php if (can('rates', 'edit')): ?>
        <div class="card-footer" style="text-align:right;">
            <button class="btn btn-primary btn-sm" :disabled="saving" @click="saveItems()">
                <span x-text="saving ? 'Saving…' : 'Save All Items'"></span>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Delete card button ─────────────────────────────────────────────── -->
    <?php if (can('rates', 'delete') && !$card['is_default']): ?>
    <div style="margin-bottom:32px;">
        <button class="btn btn-danger btn-sm" @click="deleteModal.open = true">Delete This Rate Card</button>
    </div>
    <?php endif; ?>

    <!-- ── Delete modal ───────────────────────────────────────────────────── -->
    <div class="modal-backdrop" x-show="deleteModal.open" x-cloak>
        <div class="modal modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">Delete Rate Card</h3>
                <button class="modal-close-btn" aria-label="Close" @click="deleteModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <p>Permanently delete <strong><?= e($card['name']) ?></strong>?</p>
                <p class="text-secondary" style="font-size:0.875rem;margin-top:8px;">Historical lease rates are unaffected.</p>
                <p class="text-danger" x-show="deleteModal.error" x-text="deleteModal.error"
                   style="font-size:0.875rem;margin-top:8px;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="deleteModal.open = false">Cancel</button>
                <button class="btn btn-danger btn-sm" :disabled="deleteModal.saving" @click="deleteCard()">
                    <span x-text="deleteModal.saving ? 'Deleting…' : 'Delete'"></span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_RateCardShow() {
    const initial = <?= json_encode([
        'id'            => $card['id'],
        'name'          => $card['name'],
        'description'   => $card['description'],
        'is_default'    => (bool)$card['is_default'],
        'effective_from'=> $card['effective_from'],
        'effective_to'  => $card['effective_to'],
        'customer_id'   => $card['customer_id'] ? (int)$card['customer_id'] : null,
        'customer_name' => $card['customer_name'],
        'updated_at'    => $card['updated_at'],
    ]) ?>;

    const initialItems = <?= json_encode(array_map(fn($i) => array_merge($i, ['editing' => false]), $items)) ?>;

    // Category slug → display label
    const CATEGORY_LABELS = {
        chassis: 'Chassis', dry_van: 'Dry Van', reefer: 'Reefer',
        container: 'Container', flatbed: 'Flatbed', step_deck: 'Step Deck',
        lowboy: 'Lowboy', tanker: 'Tanker', dump: 'Dump', other: 'Other',
    };

    return {
        form:          { ...initial },
        formSnapshot:  { ...initial },
        items:         initialItems.map(i => ({...i})),
        itemsSnapshot: initialItems.map(i => ({...i})),

        editMode:    false,
        saving:      false,
        saveSuccess: false,
        globalError: null,
        deleteModal: { open: false, saving: false, error: '' },

        init() {},

        categoryLabel(slug) {
            if (!slug) return '—';
            return CATEGORY_LABELS[slug] || slug.replace(/_/g, ' ');
        },

        cancelEdit() {
            this.form     = { ...this.formSnapshot };
            this.editMode = false;
            this.globalError = null;
        },

        async saveCard() {
            this.globalError = null;
            this.saveSuccess = false;

            if (!this.form.name?.trim()) { this.globalError = 'Rate card name is required.'; return; }
            if (!this.form.effective_from) { this.globalError = 'Effective from date is required.'; return; }
            if (this.form.effective_to && this.form.effective_to < this.form.effective_from) {
                this.globalError = 'End date must be on or after the start date.'; return;
            }

            this.saving = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/update') ?>', {
                    id:             this.form.id,
                    updated_at:     this.form.updated_at,
                    name:           this.form.name.trim(),
                    description:    this.form.description?.trim() || null,
                    effective_from: this.form.effective_from,
                    effective_to:   this.form.effective_to || null,
                    is_default:     this.form.is_default ? 1 : 0,
                    customer_id:    this.form.customer_id || null,
                });
                if (!r.success) {
                    if (r.error?.code === 'STALE_DATA') {
                        this.globalError = 'This rate card was modified by another user. Please reload and try again.';
                    } else {
                        this.globalError = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Save failed.');
                    }
                    return;
                }
                this.form.updated_at    = r.data.updated_at;
                this.form.customer_id   = r.data.customer_id ?? null;
                this.formSnapshot       = { ...this.form };
                this.editMode           = false;
                this.saveSuccess        = true;
                setTimeout(() => this.saveSuccess = false, 3000);
            } catch (e) {
                this.globalError = e.message || 'Save failed.';
            } finally {
                this.saving = false;
            }
        },

        addItem() {
            this.items.push({
                id: null, equipment_type: '', daily_rate: '', weekly_rate: '',
                monthly_rate: '', mileage_rate: '', mileage_unit: 'km', currency: 'CAD',
                notes: '', editing: true,
            });
        },

        removeItem(idx) { this.items.splice(idx, 1); },

        isBlankItem(item) {
            return !item.equipment_type
                && (item.daily_rate   === '' || item.daily_rate   == null)
                && (item.weekly_rate  === '' || item.weekly_rate  == null)
                && (item.monthly_rate === '' || item.monthly_rate == null)
                && (item.mileage_rate === '' || item.mileage_rate == null);
        },

        cancelItemEdit(idx) {
            const snapshot = this.itemsSnapshot[idx];
            if (snapshot) this.items[idx] = { ...snapshot, editing: false };
            else          this.items.splice(idx, 1);
        },

        async saveItems() {
            this.globalError = null;
            this.saveSuccess = false;

            // Validate
            const seen = new Set();
            const problems = [];
            for (let i = 0; i < this.items.length; i++) {
                const item = this.items[i];
                const num  = i + 1;
                if (this.isBlankItem(item)) continue;
                if (!item.equipment_type) { problems.push(`Item ${num}: select an equipment category.`); continue; }
                if (seen.has(item.equipment_type)) { problems.push(`Item ${num}: '${this.categoryLabel(item.equipment_type)}' listed more than once.`); continue; }
                seen.add(item.equipment_type);
                const fields = { daily_rate: 'Daily', weekly_rate: 'Weekly', monthly_rate: 'Monthly', mileage_rate: 'Mileage' };
                for (const [f, label] of Object.entries(fields)) {
                    const raw = item[f];
                    if (raw === '' || raw == null) continue;
                    const n = parseFloat(raw);
                    if (isNaN(n))  problems.push(`Item ${num}: ${label} must be a number.`);
                    else if (n < 0) problems.push(`Item ${num}: ${label} cannot be negative.`);
                }
            }
            if (problems.length) { this.globalError = problems.join(' '); return; }

            this.saving = true;
            try {
                const itemPayload = this.items.filter(i => !this.isBlankItem(i)).map(item => ({
                    equipment_type: item.equipment_type,
                    daily_rate:     item.daily_rate   || null,
                    weekly_rate:    item.weekly_rate  || null,
                    monthly_rate:   item.monthly_rate || null,
                    mileage_rate:   item.mileage_rate || null,
                    mileage_unit:   item.mileage_unit,
                    currency:       item.currency,
                    notes:          item.notes || null,
                }));

                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/update') ?>', {
                    id:         this.form.id,
                    updated_at: this.form.updated_at,
                    items:      itemPayload,
                });

                if (!r.success) {
                    if (r.error?.code === 'STALE_DATA') {
                        this.globalError = 'This rate card was modified by another user. Please reload and try again.';
                    } else {
                        this.globalError = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Save failed.');
                    }
                    return;
                }

                this.form.updated_at = r.data.updated_at;
                this.formSnapshot    = { ...this.form };
                this.items           = this.items.map(i => ({...i, editing: false}));
                this.itemsSnapshot   = this.items.map(i => ({...i}));
                this.saveSuccess     = true;
                setTimeout(() => this.saveSuccess = false, 3000);
            } catch (e) {
                this.globalError = e.message || 'Save failed.';
            } finally {
                this.saving = false;
            }
        },

        async deleteCard() {
            this.deleteModal.saving = true;
            this.deleteModal.error  = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/delete') ?>', { id: this.form.id, updated_at: this.form.updated_at });
                if (!r.success) { this.deleteModal.error = r.error?.message || 'Delete failed.'; this.deleteModal.saving = false; return; }
                window.location = '<?= base_url('rates') ?>';
            } catch (e) {
                this.deleteModal.error  = e.message || 'Delete failed.';
                this.deleteModal.saving = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
