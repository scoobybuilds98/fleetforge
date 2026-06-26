<?php
declare(strict_types=1);

/**
 * app/admin/equipment/categories/index.php
 *
 * S-EQTAX — operator manage screen for the two-level equipment taxonomy
 * (Category → Sub-category). Revamped UI (S-EQTAX-UI-REVAMP): summary stats,
 * search, collapsible add panel, and a structured card per category with a
 * billing-rule toggle, an actions bar (rename / move-under / activate / delete),
 * and a sub-types table with inline add. Slugs are immutable (labels editable);
 * delete is guarded server-side (in-use → deactivate instead).
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          api/v1/equipment/categories/*, api/v1/equipment/subcategories/*
 * @session S-EQTAX-7 / S-EQTAX-UI-REVAMP
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');

$pageTitle      = 'Equipment Categories';
$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';
?>

<style>
/* S-EQTAX manage screen — scoped polish on top of the design system. */
.eqtax-head-icon{width:40px;height:40px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;background:var(--color-primary-light);color:var(--color-primary-text);flex:none;}
.eqtax-head-icon svg{width:22px;height:22px;}
.eqtax-chip{display:inline-flex;align-items:center;gap:.25rem;font-family:var(--font-mono,monospace);font-size:.72rem;padding:.1rem .4rem;border-radius:var(--radius-sm);background:var(--bg-muted);color:var(--text-secondary,#64748b);border:1px solid var(--border-color);}
.eqtax-card{transition:box-shadow .15s,opacity .15s;}
.eqtax-card.is-inactive{opacity:.62;}
.eqtax-rule{display:flex;align-items:center;gap:.75rem;padding:.7rem .85rem;border-radius:var(--radius-md);border:1px solid var(--border-color);background:var(--bg-surface,var(--bg-card));margin:.9rem 0;}
.eqtax-rule.is-on{border-color:var(--color-success);background:var(--color-success-light);}
.eqtax-rule .rule-text{flex:1;min-width:0;}
.eqtax-rule .rule-title{font-weight:600;font-size:.9rem;}
.eqtax-rule .rule-sub{font-size:.78rem;color:var(--text-secondary,#64748b);}
/* toggle switch */
.eqtax-switch{position:relative;display:inline-flex;align-items:center;flex:none;cursor:pointer;width:42px;height:24px;}
.eqtax-switch input{position:absolute;opacity:0;width:0;height:0;}
.eqtax-switch .track{position:absolute;inset:0;border-radius:999px;background:var(--bg-muted);border:1px solid var(--border-color);transition:.15s;}
.eqtax-switch .knob{position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:var(--shadow-sm);transition:.15s;}
.eqtax-switch input:checked ~ .track{background:var(--color-success);border-color:var(--color-success);}
.eqtax-switch input:checked ~ .knob{transform:translateX(18px);}
.eqtax-switch input:disabled ~ .track{opacity:.5;}
.eqtax-sub-table{width:100%;border-collapse:collapse;font-size:.85rem;}
.eqtax-sub-table th{text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.03em;color:var(--text-secondary,#64748b);font-weight:600;padding:.35rem .5rem;border-bottom:1px solid var(--border-color);}
.eqtax-sub-table td{padding:.45rem .5rem;border-bottom:1px solid var(--border-color);vertical-align:middle;}
.eqtax-sub-table tr:last-child td{border-bottom:none;}
.eqtax-sub-empty{padding:.75rem .5rem;color:var(--text-secondary,#64748b);font-size:.84rem;font-style:italic;}
.eqtax-addsub{display:flex;gap:.5rem;align-items:center;margin-top:.6rem;}
.eqtax-actions{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;}
.eqtax-section-label{font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-secondary,#64748b);font-weight:700;margin:.4rem 0 .3rem;}
</style>

<div x-data="FF_EquipmentTaxonomy()" x-init="init()">

<!-- Page header -->
<div class="page-header">
    <div>
        <a href="<?= base_url('equipment/templates') ?>" class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">← Equipment Types</a>
        <h1 class="page-header-title h4">Equipment Categories</h1>
        <p class="text-secondary text-sm" style="max-width:60ch;">Organise equipment into categories and sub-types. Billing rules (like the short-lease minimum) attach to a <strong>category</strong> and are inherited by every <strong>sub-type</strong> under it.</p>
    </div>
    <div class="page-header-actions">
        <?= help_button('equipment') ?>
        <button class="btn btn-primary btn-sm" @click="showAdd = !showAdd">
            <?= heroicon('plus', 'btn-icon') ?> New category
        </button>
    </div>
</div>

    <!-- Summary stats -->
    <div class="stat-grid stat-grid--4" style="margin-bottom:1rem;" x-show="!loading">
        <div class="stat-card stat-card--blue">
            <div class="stat-label">Categories</div>
            <div class="stat-value font-mono" x-text="stats.categories"></div>
            <div class="stat-delta text-secondary">top-level types</div>
        </div>
        <div class="stat-card stat-card--teal">
            <div class="stat-label">Sub-types</div>
            <div class="stat-value font-mono" x-text="stats.subtypes"></div>
            <div class="stat-delta text-secondary">across all categories</div>
        </div>
        <div class="stat-card stat-card--slate">
            <div class="stat-label">Equipment types</div>
            <div class="stat-value font-mono" x-text="stats.types"></div>
            <div class="stat-delta text-secondary">templates classified</div>
        </div>
        <div class="stat-card stat-card--green">
            <div class="stat-label">Short-lease minimum</div>
            <div class="stat-value font-mono" x-text="stats.enforcing"></div>
            <div class="stat-delta text-secondary">categories applying it</div>
        </div>
    </div>

    <!-- Add-category panel (collapsible) -->
    <div class="card" x-show="showAdd" x-transition style="margin-bottom:1rem;border:1px solid var(--color-primary);">
        <div class="card-body">
            <div class="eqtax-section-label">New category</div>
            <div style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
                <div class="form-group" style="margin:0;flex:1;min-width:220px;">
                    <label class="form-label" for="newCatLabel">Name</label>
                    <input id="newCatLabel" type="text" class="form-control" maxlength="100"
                           placeholder="e.g. Chassis, Dry Van, Reefer…" x-model="newCat.label"
                           @keydown.enter="addCategory()" x-ref="newCatInput">
                </div>
                <label class="form-group" style="margin:0;display:flex;align-items:center;gap:0.5rem;cursor:pointer;padding-bottom:.5rem;">
                    <span class="eqtax-switch">
                        <input type="checkbox" x-model="newCat.enforce">
                        <span class="track"></span><span class="knob"></span>
                    </span>
                    <span class="text-sm">Apply short-lease minimum</span>
                </label>
                <div style="display:flex;gap:.4rem;padding-bottom:.1rem;">
                    <button class="btn btn-primary" @click="addCategory()" :disabled="!newCat.label.trim() || busy">Add category</button>
                    <button class="btn btn-ghost" @click="showAdd = false; newCat = { label:'', enforce:false }">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toolbar: search + show-inactive -->
    <div class="table-toolbar" x-show="!loading && categories.length">
        <div class="table-toolbar-left">
            <input type="search" class="form-control form-control-sm" style="min-width:240px;"
                   placeholder="Search categories or sub-types…" x-model="search">
        </div>
        <div class="table-toolbar-right">
            <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;" class="text-sm text-secondary">
                <input type="checkbox" x-model="showInactive"> Show inactive
            </label>
        </div>
    </div>

    <!-- Loading -->
    <template x-if="loading">
        <div class="card"><div class="card-body text-secondary">Loading…</div></div>
    </template>

    <!-- Empty: no categories at all -->
    <template x-if="!loading && categories.length === 0">
        <div class="card"><div class="card-body">
            <div class="empty-state">
                <div class="empty-state-icon"><?= heroicon('cube', '') ?></div>
                <p class="empty-state-title">No categories yet</p>
                <p class="empty-state-text">Add your first equipment category to get started.</p>
                <button class="btn btn-primary btn-sm" style="margin-top:.75rem;" @click="showAdd = true; $nextTick(() => $refs.newCatInput?.focus())">+ New category</button>
            </div>
        </div></div>
    </template>

    <!-- Empty: search matched nothing -->
    <template x-if="!loading && categories.length > 0 && filteredCategories.length === 0">
        <div class="card"><div class="card-body">
            <div class="empty-state">
                <div class="empty-state-icon"><?= heroicon('cube', '') ?></div>
                <p class="empty-state-title">No matches</p>
                <p class="empty-state-text">Nothing matches “<span x-text="search"></span>”<span x-show="!showInactive"> among active categories</span>.</p>
            </div>
        </div></div>
    </template>

    <!-- Category cards -->
    <template x-for="cat in filteredCategories" :key="cat.id">
        <div class="card eqtax-card" :class="{ 'is-inactive': cat.is_active !== 1 }" style="margin-bottom:1rem;">
            <div class="card-body">

                <!-- Header -->
                <div style="display:flex;gap:0.85rem;align-items:flex-start;">
                    <div class="eqtax-head-icon"><?= heroicon('cube', '') ?></div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                            <span style="font-weight:700;font-size:1.1rem;" x-text="cat.label"></span>
                            <span class="eqtax-chip" x-text="cat.slug"></span>
                            <span class="badge badge-success badge-sm" x-show="cat.enforce_minimum_billing_days === 1" title="Leases on this category honour the short-lease minimum">Minimum applies</span>
                            <span class="badge badge-gray badge-sm" x-show="cat.is_active !== 1">Inactive</span>
                        </div>
                        <div class="text-secondary text-sm" style="margin-top:.2rem;">
                            <span x-text="cat.subcategories.length"></span> sub-type<span x-text="cat.subcategories.length===1?'':'s'"></span>
                            · <span x-text="cat.template_count"></span> equipment type<span x-text="cat.template_count===1?'':'s'"></span>
                        </div>
                    </div>
                    <!-- Category actions -->
                    <div class="eqtax-actions" style="justify-content:flex-end;">
                        <button class="btn btn-ghost btn-sm" @click="renameCategory(cat)" title="Rename (slug stays the same)"><?= heroicon('pencil-square', 'btn-icon') ?> Rename</button>
                        <button class="btn btn-ghost btn-sm" @click="setActive(cat, cat.is_active !== 1)" x-text="cat.is_active === 1 ? 'Deactivate' : 'Activate'"></button>
                        <button class="btn btn-ghost btn-sm" style="color:var(--color-danger-text);" @click="deleteCategory(cat)" title="Delete (blocked while in use)"><?= heroicon('trash', 'btn-icon') ?></button>
                    </div>
                </div>

                <!-- Billing rule -->
                <div class="eqtax-rule" :class="{ 'is-on': cat.enforce_minimum_billing_days === 1 }">
                    <label class="eqtax-switch">
                        <input type="checkbox" :checked="cat.enforce_minimum_billing_days === 1" @change="setEnforce(cat, $event.target.checked)">
                        <span class="track"></span><span class="knob"></span>
                    </label>
                    <div class="rule-text">
                        <div class="rule-title">Short-lease minimum billing</div>
                        <div class="rule-sub" x-text="cat.enforce_minimum_billing_days === 1
                            ? 'On — short leases bill at least the minimum number of days. Every sub-type below inherits this.'
                            : 'Off — leases bill their actual days. Turn on to apply the short-lease minimum to all equipment in this category.'"></div>
                    </div>
                </div>

                <!-- Sub-types -->
                <div class="eqtax-section-label">Sub-types</div>
                <template x-if="cat.subcategories.length > 0">
                    <table class="eqtax-sub-table">
                        <thead><tr><th>Name</th><th>Slug</th><th style="text-align:right;">Equipment types</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
                        <tbody>
                            <template x-for="sub in cat.subcategories" :key="sub.id">
                                <tr :style="sub.is_active !== 1 ? 'opacity:.6;' : ''">
                                    <td x-text="sub.label" style="font-weight:500;"></td>
                                    <td><span class="eqtax-chip" x-text="sub.slug"></span></td>
                                    <td style="text-align:right;" class="font-mono" x-text="sub.template_count"></td>
                                    <td>
                                        <label class="text-sm text-secondary" style="display:inline-flex;align-items:center;gap:.35rem;cursor:pointer;">
                                            <input type="checkbox" :checked="sub.is_active === 1" @change="setSubActive(sub, $event.target.checked)">
                                            <span x-text="sub.is_active === 1 ? 'Active' : 'Inactive'"></span>
                                        </label>
                                    </td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        <button class="btn btn-ghost btn-sm" @click="renameSub(sub)" title="Rename">Rename</button>
                                        <button class="btn btn-ghost btn-sm" style="color:var(--color-danger-text);" @click="deleteSub(cat, sub)" title="Delete (blocked while in use)"><?= heroicon('trash', 'btn-icon') ?></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>
                <template x-if="cat.subcategories.length === 0">
                    <div class="eqtax-sub-empty">No sub-types yet — add specific types (e.g. “40' Tridem”, “Combo”) below.</div>
                </template>

                <!-- Add sub-type -->
                <div class="eqtax-addsub">
                    <input type="text" class="form-control form-control-sm" style="max-width:300px;" maxlength="100"
                           placeholder="Add a sub-type…" x-model="cat._newSub" @keydown.enter="addSub(cat)">
                    <button class="btn btn-secondary btn-sm" @click="addSub(cat)" :disabled="!(cat._newSub||'').trim() || busy"><?= heroicon('plus', 'btn-icon') ?> Add sub-type</button>
                </div>

                <!-- Move under (convert to sub-category) -->
                <div x-show="categories.length > 1" style="display:flex;gap:0.5rem;align-items:center;margin-top:.85rem;padding-top:.7rem;border-top:1px dashed var(--border-color);">
                    <span class="text-secondary text-sm">Reorganise — make this a sub-type of:</span>
                    <select class="form-control form-control-sm" style="max-width:200px;" x-model="cat._moveTo">
                        <option value="">— choose a parent —</option>
                        <template x-for="p in categories.filter(p => p.id !== cat.id)" :key="p.id">
                            <option :value="p.id" x-text="p.label"></option>
                        </template>
                    </select>
                    <button class="btn btn-ghost btn-sm" @click="convertToSub(cat)" :disabled="!cat._moveTo || busy">Move under</button>
                </div>

            </div>
        </div>
    </template>
</div>

<script>
function FF_EquipmentTaxonomy() {
    return {
        categories: [],
        loading:    true,
        busy:       false,
        showAdd:    false,
        search:     '',
        showInactive: false,
        newCat:     { label: '', enforce: false },

        async init() { await this.load(); },

        get stats() {
            const c = this.categories;
            return {
                categories: c.length,
                subtypes:   c.reduce((n, x) => n + (x.subcategories ? x.subcategories.length : 0), 0),
                types:      c.reduce((n, x) => n + (x.template_count || 0), 0),
                enforcing:  c.filter(x => x.enforce_minimum_billing_days === 1).length,
            };
        },

        get filteredCategories() {
            const q = (this.search || '').trim().toLowerCase();
            return this.categories.filter(c => {
                if (!this.showInactive && c.is_active !== 1) return false;
                if (!q) return true;
                if (c.label.toLowerCase().includes(q) || (c.slug || '').toLowerCase().includes(q)) return true;
                return (c.subcategories || []).some(s =>
                    s.label.toLowerCase().includes(q) || (s.slug || '').toLowerCase().includes(q));
            });
        },

        async load() {
            this.loading = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/categories') ?>');
                this.categories = (r.success && r.data) ? r.data.categories.map(c => ({ ...c, _newSub: '', _moveTo: '' })) : [];
            } catch (e) {
                FF_Toast.error('Could not load categories.');
            } finally {
                this.loading = false;
            }
        },

        async addCategory() {
            const label = this.newCat.label.trim();
            if (!label || this.busy) return;
            this.busy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/categories/create') ?>',
                    { label, enforce_minimum_billing_days: this.newCat.enforce ? 1 : 0 });
                if (r.success) { this.newCat = { label: '', enforce: false }; this.showAdd = false; FF_Toast.success('Category added.'); await this.load(); }
                else FF_Toast.error(r.error?.message || r.error?.fields?.label || 'Failed to add category.');
            } finally { this.busy = false; }
        },

        async renameCategory(cat) {
            const label = await FF_Confirm.askText({ title: 'Rename category', message: 'New name (the underlying slug stays the same):', defaultValue: cat.label, confirmLabel: 'Rename' });
            if (!label || label.trim() === cat.label) return;
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/categories/update') ?>', { id: cat.id, label: label.trim() });
            r.success ? (FF_Toast.success('Renamed.'), this.load()) : FF_Toast.error(r.error?.message || 'Rename failed.');
        },

        async setEnforce(cat, on) {
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/categories/update') ?>', { id: cat.id, enforce_minimum_billing_days: on ? 1 : 0 });
            r.success ? (FF_Toast.success(on ? 'Short-lease minimum turned on.' : 'Short-lease minimum turned off.'), this.load()) : (FF_Toast.error('Save failed.'), this.load());
        },

        async setActive(cat, on) {
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/categories/update') ?>', { id: cat.id, is_active: on ? 1 : 0 });
            r.success ? (FF_Toast.success(on ? 'Activated.' : 'Deactivated.'), this.load()) : (FF_Toast.error('Save failed.'), this.load());
        },

        async deleteCategory(cat) {
            if (!(await FF_Confirm.ask('Delete category "' + cat.label + '"? In-use categories cannot be deleted — deactivate instead.'))) return;
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/categories/delete') ?>', { id: cat.id });
            if (r.success) { FF_Toast.success('Category deleted.'); this.load(); }
            else FF_Toast.error(r.error?.message || 'Could not delete category.');
        },

        async convertToSub(cat) {
            const parent = this.categories.find(p => String(p.id) === String(cat._moveTo));
            if (!parent) return;
            const ruleNote = parent.enforce_minimum_billing_days === 1
                ? parent.label + ' applies the short-lease minimum, so this equipment will too.'
                : parent.label + ' does NOT apply the short-lease minimum, so this equipment will follow that.';
            if (!(await FF_Confirm.ask(
                'Make "' + cat.label + '" a sub-type under "' + parent.label + '"? Its equipment types move under '
                + parent.label + ' and "' + cat.label + '" stops being a top-level category. ' + ruleNote))) return;
            this.busy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/categories/convert_to_subcategory') ?>',
                    { id: cat.id, parent_category_id: parent.id });
                if (r.success) { FF_Toast.success('"' + cat.label + '" is now a sub-type of "' + parent.label + '" (' + (r.data?.moved_templates ?? 0) + ' type(s) moved).'); await this.load(); }
                else FF_Toast.error(r.error?.message || 'Could not convert category.');
            } finally { this.busy = false; }
        },

        async addSub(cat) {
            const label = (cat._newSub || '').trim();
            if (!label || this.busy) return;
            this.busy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/subcategories/create') ?>', { category_id: cat.id, label });
                if (r.success) { FF_Toast.success('Sub-type added.'); await this.load(); }
                else FF_Toast.error(r.error?.message || r.error?.fields?.label || 'Failed to add sub-type.');
            } finally { this.busy = false; }
        },

        async renameSub(sub) {
            const label = await FF_Confirm.askText({ title: 'Rename sub-category', message: 'New name (the underlying slug stays the same):', defaultValue: sub.label, confirmLabel: 'Rename' });
            if (!label || label.trim() === sub.label) return;
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/subcategories/update') ?>', { id: sub.id, label: label.trim() });
            r.success ? (FF_Toast.success('Renamed.'), this.load()) : FF_Toast.error(r.error?.message || 'Rename failed.');
        },

        async setSubActive(sub, on) {
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/subcategories/update') ?>', { id: sub.id, is_active: on ? 1 : 0 });
            r.success ? this.load() : (FF_Toast.error('Save failed.'), this.load());
        },

        async deleteSub(cat, sub) {
            if (!(await FF_Confirm.ask('Delete sub-type "' + sub.label + '"? In-use sub-types cannot be deleted.'))) return;
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/subcategories/delete') ?>', { id: sub.id });
            if (r.success) { FF_Toast.success('Sub-type deleted.'); this.load(); }
            else FF_Toast.error(r.error?.message || 'Could not delete sub-type.');
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
