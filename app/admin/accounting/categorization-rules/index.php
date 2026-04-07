<?php declare(strict_types=1);

/**
 * app/admin/accounting/categorization-rules/index.php
 *
 * Auto-categorization rules management — create, edit, reorder, enable/disable rules.
 * Rules auto-suggest GL accounts when creating AP bills.
 * Includes global toggle and per-rule test capability.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @session S032
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('accounts_payable', 'view');

$vendors = db_select(
    "SELECT id, name, vendor_type FROM vendors WHERE deleted_at IS NULL ORDER BY name", []
);
$glAccounts = db_select(
    "SELECT id, code, name FROM acc_accounts WHERE is_active = 1 AND is_header = 0 AND account_type IN ('operating_expense','cost_of_revenue','other_expense') ORDER BY code", []
);
$vendorTypes = ['maintenance','repair','parts','inspection','towing','other'];

$canEdit = can('accounts_payable', 'edit');
$canDelete = can('accounts_payable', 'delete');

$pageTitle = 'Categorization Rules';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/bills') ?>">Bills</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Categorization Rules</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Auto-Categorization Rules</h1>
    <p style="font-size:0.8125rem;color:var(--text-secondary);margin-top:4px;">Rules auto-suggest GL expense accounts when creating vendor bills. Higher priority (lower number) rules match first.</p>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="rulesPage()" x-init="load()">
    <!-- Global Toggle -->
    <div class="card" style="padding:12px 16px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <span style="font-weight:600;font-size:0.875rem;">Auto-Categorization</span>
            <span style="font-size:0.8125rem;color:var(--text-secondary);margin-left:8px;" x-text="globalEnabled ? 'Enabled — rules run on new bills' : 'Disabled — no auto-suggestions'"></span>
        </div>
        <?php if ($canEdit): ?>
        <button class="btn btn-sm" :class="globalEnabled ? 'btn-success' : 'btn-secondary'" @click="toggleGlobal()" x-text="globalEnabled ? 'ON' : 'OFF'" style="min-width:60px;"></button>
        <?php endif; ?>
    </div>

    <!-- Toolbar -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
        <?php if ($canEdit): ?>
        <button class="btn btn-primary btn-sm" @click="openForm()">+ New Rule</button>
        <?php endif; ?>
    </div>

    <template x-if="loading">
        <div class="card" style="padding:48px;text-align:center;">
            <div class="spinner" style="margin:0 auto 12px;"></div>
        </div>
    </template>

    <!-- Rules Table -->
    <template x-if="!loading && rules.length > 0">
        <div class="card" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);width:60px;">Priority</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Name</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Conditions</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">GL Account</th>
                        <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Active</th>
                        <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="r in rules" :key="r.id">
                        <tr style="border-bottom:1px solid var(--border-default);" :style="!r.is_active ? 'opacity:0.5' : ''">
                            <td style="padding:8px 12px;text-align:center;" class="font-mono" x-text="r.priority"></td>
                            <td style="padding:8px 12px;font-weight:500;" x-text="r.name"></td>
                            <td style="padding:8px 12px;font-size:0.75rem;color:var(--text-secondary);">
                                <span x-show="r.vendor_name" x-text="'Vendor: ' + r.vendor_name" style="display:block;"></span>
                                <span x-show="r.vendor_name_pattern" x-text="'Name contains: ' + r.vendor_name_pattern" style="display:block;"></span>
                                <span x-show="r.vendor_type" x-text="'Type: ' + r.vendor_type" style="display:block;"></span>
                                <span x-show="r.description_keywords" x-text="'Keywords: ' + r.description_keywords" style="display:block;"></span>
                                <span x-show="r.amount_min || r.amount_max" x-text="'Amount: ' + (r.amount_min || '0') + ' – ' + (r.amount_max || '∞')" style="display:block;"></span>
                            </td>
                            <td style="padding:8px 12px;">
                                <span class="font-mono" x-text="r.account_code"></span>
                                <span style="color:var(--text-secondary);margin-left:4px;" x-text="r.account_name"></span>
                            </td>
                            <td style="padding:8px 12px;text-align:center;">
                                <span :style="r.is_active ? 'color:var(--color-success)' : 'color:var(--text-muted)'" x-text="r.is_active ? '●' : '○'"></span>
                            </td>
                            <td style="padding:8px 12px;text-align:center;">
                                <div style="display:flex;gap:4px;justify-content:center;">
                                    <?php if ($canEdit): ?>
                                    <button class="btn btn-ghost btn-xs" @click="editRule(r)" title="Edit">✎</button>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                    <button class="btn btn-ghost btn-xs" @click="deleteRule(r.id)" title="Delete" style="color:var(--color-danger);">✕</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <template x-if="!loading && rules.length === 0">
        <div class="card" style="padding:48px;text-align:center;">
            <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">No Rules</div>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">Create rules to auto-suggest GL accounts when entering bills.</div>
        </div>
    </template>

    <!-- Create/Edit Modal -->
    <div x-show="showForm" x-cloak style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);"
         @click.self="showForm = false">
            <div class="card" style="width:100%;max-width:600px;padding:24px;" x-show="showForm" x-transition>
                <div style="font-weight:700;font-size:1rem;margin-bottom:16px;" x-text="form.id ? 'Edit Rule' : 'New Rule'"></div>
                <div class="form-error-banner" x-show="formError" x-cloak x-text="formError" style="margin-bottom:12px;"></div>
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Rule Name *</label>
                        <input type="text" x-model="form.name" @input="errors.name = ''" :class="errors.name ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);" placeholder="e.g. Tire vendors → Tires expense">
                        <div class="field-error" x-show="errors.name" x-cloak x-text="errors.name"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Priority</label>
                        <input type="number" x-model="form.priority" @input="errors.priority = ''" :class="errors.priority ? 'is-invalid' : ''" min="0" class="form-input font-mono" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                        <div class="field-error" x-show="errors.priority" x-cloak x-text="errors.priority"></div>
                    </div>
                </div>
                <div style="font-weight:600;font-size:0.8125rem;margin-bottom:8px;color:var(--text-secondary);">Match Conditions (all non-empty conditions must match)</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Specific Vendor</label>
                        <select x-model="form.vendor_id" @change="errors.vendor_id = ''" :class="errors.vendor_id ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                            <option value="">Any vendor</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-error" x-show="errors.vendor_id" x-cloak x-text="errors.vendor_id"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Vendor Type</label>
                        <select x-model="form.vendor_type" @change="errors.vendor_type = ''" :class="errors.vendor_type ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                            <option value="">Any type</option>
                            <?php foreach ($vendorTypes as $vt): ?>
                            <option value="<?= e($vt) ?>"><?= e(ucfirst($vt)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-error" x-show="errors.vendor_type" x-cloak x-text="errors.vendor_type"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Vendor Name Contains</label>
                        <input type="text" x-model="form.vendor_name_pattern" @input="errors.vendor_name_pattern = ''" :class="errors.vendor_name_pattern ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);" placeholder="e.g. tire, brake">
                        <div class="field-error" x-show="errors.vendor_name_pattern" x-cloak x-text="errors.vendor_name_pattern"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Description Keywords (comma-sep)</label>
                        <input type="text" x-model="form.description_keywords" @input="errors.description_keywords = ''" :class="errors.description_keywords ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);" placeholder="e.g. oil change, filter">
                        <div class="field-error" x-show="errors.description_keywords" x-cloak x-text="errors.description_keywords"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Amount Min ($)</label>
                        <input type="number" x-model="form.amount_min" @input="errors.amount_min = ''" :class="errors.amount_min ? 'is-invalid' : ''" step="0.01" min="0" class="form-input font-mono" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                        <div class="field-error" x-show="errors.amount_min" x-cloak x-text="errors.amount_min"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Amount Max ($)</label>
                        <input type="number" x-model="form.amount_max" @input="errors.amount_max = ''" :class="errors.amount_max ? 'is-invalid' : ''" step="0.01" min="0" class="form-input font-mono" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                        <div class="field-error" x-show="errors.amount_max" x-cloak x-text="errors.amount_max"></div>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Target GL Account *</label>
                    <select x-model="form.account_id" @change="errors.account_id = ''" :class="errors.account_id ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                        <option value="">Select account...</option>
                        <?php foreach ($glAccounts as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' — ' . $a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-error" x-show="errors.account_id" x-cloak x-text="errors.account_id"></div>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.8125rem;cursor:pointer;">
                        <input type="checkbox" x-model="form.is_active" style="width:16px;height:16px;">
                        <span>Active</span>
                    </label>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button class="btn btn-secondary btn-sm" @click="showForm = false">Cancel</button>
                    <button class="btn btn-success btn-sm" @click="saveRule()" :disabled="saving">Save Rule</button>
                </div>
            </div>
    </div>
</div>

<script>
function rulesPage() {
    return {
        rules: [], loading: false, globalEnabled: true,
        showForm: false, saving: false, formError: '',
        form: {},
        errors: {
            name: '', priority: '', vendor_id: '', vendor_type: '',
            vendor_name_pattern: '', description_keywords: '',
            amount_min: '', amount_max: '', account_id: ''
        },

        _csrfHeaders() {
            return {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            };
        },

        _clearErrors() {
            for (const k in this.errors) this.errors[k] = '';
            this.formError = '';
        },

        _extractError(r, fallback) {
            if (!r) return fallback || 'An unexpected error occurred.';
            if (r.error && r.error.message) return r.error.message;
            if (r.message) return r.message;
            return fallback || 'An unexpected error occurred.';
        },

        _paintServerErrors(r, fallback) {
            const err = r && r.error ? r.error : r;
            const fields = (err && err.fields) || (r && r.fields) || {};
            let painted = false;
            for (const k in fields) {
                if (k === '_general') continue;
                if (k in this.errors) {
                    this.errors[k] = fields[k];
                    painted = true;
                }
            }
            if (fields._general) {
                this.formError = fields._general;
            } else if (!painted) {
                this.formError = this._extractError(r, fallback);
            } else {
                this.formError = this._extractError(r, 'Please correct the highlighted fields.');
            }
        },

        async load() {
            this.loading = true;
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/categorization-rules/index.php'));
                const j = await r.json();
                if (j.success) { this.rules = j.data.rules; this.globalEnabled = j.data.global_enabled; }
            } catch (e) { console.error(e); }
            this.loading = false;
        },

        async toggleGlobal() {
            this.globalEnabled = !this.globalEnabled;
            try {
                const fd = new FormData();
                fd.append('key', 'accounting.auto_categorization_enabled');
                fd.append('value', this.globalEnabled ? 'true' : 'false');
                await fetch(FF_Api.url('/api/v1/accounting/settings/update.php'), { method: 'POST', body: fd, headers: this._csrfHeaders() });
            } catch (e) { console.error(e); }
        },

        openForm(rule) {
            this.form = rule ? { ...rule, is_active: !!Number(rule.is_active) } : {
                id: null, name: '', vendor_id: '', vendor_name_pattern: '',
                description_keywords: '', amount_min: '', amount_max: '',
                vendor_type: '', account_id: '', priority: 0, is_active: true,
            };
            this._clearErrors();
            this.showForm = true;
        },

        editRule(r) { this.openForm(r); },

        validateRule() {
            this._clearErrors();
            let ok = true;
            if (!this.form.name || !String(this.form.name).trim()) {
                this.errors.name = 'Rule name is required.';
                ok = false;
            }
            if (!this.form.account_id) {
                this.errors.account_id = 'Please select a target GL account.';
                ok = false;
            }
            const priority = this.form.priority;
            if (priority !== '' && priority !== null && priority !== undefined) {
                const p = Number(priority);
                if (!Number.isInteger(p) || p < 0) {
                    this.errors.priority = 'Priority must be zero or a positive integer.';
                    ok = false;
                }
            }
            const min = this.form.amount_min;
            const max = this.form.amount_max;
            if (min !== '' && min !== null && min !== undefined) {
                if (isNaN(parseFloat(min)) || parseFloat(min) < 0) {
                    this.errors.amount_min = 'Minimum amount must be zero or positive.';
                    ok = false;
                }
            }
            if (max !== '' && max !== null && max !== undefined) {
                if (isNaN(parseFloat(max)) || parseFloat(max) < 0) {
                    this.errors.amount_max = 'Maximum amount must be zero or positive.';
                    ok = false;
                }
            }
            if (min !== '' && max !== '' && min != null && max != null
                && !isNaN(parseFloat(min)) && !isNaN(parseFloat(max))
                && parseFloat(min) > parseFloat(max)) {
                this.errors.amount_max = 'Maximum amount must be greater than or equal to minimum amount.';
                ok = false;
            }
            return ok;
        },

        async saveRule() {
            if (!this.validateRule()) {
                this.formError = 'Please correct the highlighted fields.';
                return;
            }
            this.saving = true; this.formError = '';
            try {
                const fd = new FormData();
                Object.entries(this.form).forEach(([k, v]) => {
                    if (k === 'is_active') fd.append(k, v ? '1' : '0');
                    else if (v !== null && v !== '') fd.append(k, String(v));
                });
                const r = await fetch(FF_Api.url('/api/v1/accounting/categorization-rules/save.php'), { method: 'POST', body: fd, headers: this._csrfHeaders() });
                const j = await r.json();
                if (j.success) { this.showForm = false; this.load(); }
                else this._paintServerErrors(j, 'Failed to save rule.');
            } catch (e) { this.formError = 'Network error. Please try again.'; }
            this.saving = false;
        },

        async deleteRule(id) {
            if (!confirm('Delete this rule?')) return;
            try {
                const fd = new FormData(); fd.append('id', id);
                const r = await fetch(FF_Api.url('/api/v1/accounting/categorization-rules/delete.php'), { method: 'POST', body: fd, headers: this._csrfHeaders() });
                const j = await r.json();
                if (j.success) this.load();
                else alert(j.error?.message || 'Delete failed.');
            } catch (e) { alert(e.message); }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
