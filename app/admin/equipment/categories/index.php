<?php
declare(strict_types=1);

/**
 * app/admin/equipment/categories/index.php
 *
 * S-EQTAX — operator manage screen for equipment CATEGORIES (two-level model,
 * S-EQTAX-2LEVEL: Category → Equipment Type; the sub-category layer was retired).
 * Add / rename / retire categories, toggle the per-category short-lease-minimum
 * billing rule, and see the equipment types filed under each category (with quick
 * links to edit them or add a new one pre-filed into the category). Slugs are
 * immutable (labels editable); delete is guarded server-side (in-use → deactivate).
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          api/v1/equipment/categories/*
 * @session S-EQTAX-7 / S-EQTAX-UI-REVAMP / S-EQTAX-2LEVEL
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
.eqtax-card.is-inactive{opacity:.62;}
.eqtax-rule{display:flex;align-items:center;gap:.75rem;padding:.7rem .85rem;border-radius:var(--radius-md);border:1px solid var(--border-color);background:var(--bg-surface,var(--bg-card));margin:.9rem 0;}
.eqtax-rule.is-on{border-color:var(--color-success);background:var(--color-success-light);}
.eqtax-rule .rule-text{flex:1;min-width:0;}
.eqtax-rule .rule-title{font-weight:600;font-size:.9rem;}
.eqtax-rule .rule-sub{font-size:.78rem;color:var(--text-secondary,#64748b);}
.eqtax-switch{position:relative;display:inline-flex;align-items:center;flex:none;cursor:pointer;width:42px;height:24px;}
.eqtax-switch input{position:absolute;opacity:0;width:0;height:0;}
.eqtax-switch .track{position:absolute;inset:0;border-radius:999px;background:var(--bg-muted);border:1px solid var(--border-color);transition:.15s;}
.eqtax-switch .knob{position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:var(--shadow-sm);transition:.15s;}
.eqtax-switch input:checked ~ .track{background:var(--color-success);border-color:var(--color-success);}
.eqtax-switch input:checked ~ .knob{transform:translateX(18px);}
.eqtax-switch input:disabled ~ .track{opacity:.5;}
.eqtax-section-label{font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-secondary,#64748b);font-weight:700;margin:.4rem 0 .3rem;}
.eqtax-type-row{display:flex;align-items:center;gap:.6rem;padding:.45rem .15rem;border-bottom:1px solid var(--border-color);}
.eqtax-type-row:last-of-type{border-bottom:none;}
.eqtax-type-row.is-inactive{opacity:.55;}
.eqtax-type-name{font-weight:500;color:var(--text-primary,inherit);text-decoration:none;}
.eqtax-type-name:hover{color:var(--color-primary);text-decoration:underline;}
.eqtax-type-meta{font-size:.78rem;color:var(--text-secondary,#64748b);}
.eqtax-add-type{display:inline-flex;align-items:center;gap:.25rem;font-size:.82rem;margin-top:.6rem;padding:.3rem .6rem;border-radius:var(--radius-sm);border:1px dashed var(--border-color);color:var(--color-primary-text,var(--color-primary));text-decoration:none;}
.eqtax-add-type:hover{border-color:var(--color-primary);}
.eqtax-actions{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;}
</style>

<div x-data="FF_EquipmentCategories()" x-init="init()">

<!-- Page header -->
<div class="page-header">
    <div>
        <a href="<?= base_url('equipment/templates') ?>" class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">← Equipment Types</a>
        <h1 class="page-header-title h4">Equipment Categories</h1>
        <p class="text-secondary text-sm" style="max-width:64ch;">Categories are the broad classes of equipment (Chassis, Dry Van, …). Each <strong>equipment type</strong> is filed under one category, and billing rules — like the short-lease minimum — apply to everything in the category.</p>
    </div>
    <div class="page-header-actions">
        <?= help_button('equipment') ?>
        <button class="btn btn-primary btn-sm" @click="showAdd = !showAdd">
            <?= heroicon('plus', 'btn-icon') ?> New category
        </button>
    </div>
</div>

    <!-- Summary stats -->
    <div class="stat-grid stat-grid--3" style="margin-bottom:1rem;" x-show="!loading">
        <div class="stat-card stat-card--blue">
            <div class="stat-label">Categories</div>
            <div class="stat-value font-mono" x-text="stats.categories"></div>
            <div class="stat-delta text-secondary">broad classes of equipment</div>
        </div>
        <div class="stat-card stat-card--slate">
            <div class="stat-label">Equipment types</div>
            <div class="stat-value font-mono" x-text="stats.types"></div>
            <div class="stat-delta text-secondary">filed across all categories</div>
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

    <!-- Toolbar -->
    <div class="table-toolbar" x-show="!loading && categories.length">
        <div class="table-toolbar-left">
            <input type="search" class="form-control form-control-sm" style="min-width:240px;"
                   placeholder="Search categories or equipment types…" x-model="search">
        </div>
        <div class="table-toolbar-right">
            <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;" class="text-sm text-secondary">
                <input type="checkbox" x-model="showInactive"> Show inactive
            </label>
        </div>
    </div>

    <template x-if="loading">
        <div class="card"><div class="card-body text-secondary">Loading…</div></div>
    </template>

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
                            <span x-text="cat.equipment_types.length"></span> equipment type<span x-text="cat.equipment_types.length===1?'':'s'"></span>
                        </div>
                    </div>
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
                            ? 'On — short leases on any equipment in this category bill at least the minimum number of days.'
                            : 'Off — leases bill their actual days. Turn on to apply the short-lease minimum to all equipment in this category.'"></div>
                    </div>
                </div>

                <!-- Equipment types in this category -->
                <div class="eqtax-section-label">Equipment types in this category</div>
                <template x-for="t in cat.equipment_types" :key="t.id">
                    <div class="eqtax-type-row" :class="{ 'is-inactive': t.is_active !== 1 }">
                        <a class="eqtax-type-name" :href="'<?= base_url('equipment/templates/edit') ?>?id=' + t.id" x-text="t.name" :title="'Edit ' + t.name"></a>
                        <span class="eqtax-type-meta" x-text="t.unit_count + ' unit' + (t.unit_count === 1 ? '' : 's')"></span>
                        <span class="badge badge-gray badge-sm" x-show="t.is_active !== 1">inactive</span>
                        <span style="flex:1;"></span>
                        <a class="btn btn-ghost btn-sm" :href="'<?= base_url('equipment/templates/edit') ?>?id=' + t.id"><?= heroicon('pencil-square', 'btn-icon') ?> Edit</a>
                        <button class="btn btn-ghost btn-sm" style="color:var(--color-danger-text);"
                                @click="deleteType(cat, t)" :disabled="t.unit_count > 0"
                                :title="t.unit_count > 0 ? ('Cannot delete — ' + t.unit_count + ' unit(s) use this type; reassign or remove them first') : 'Delete equipment type'"><?= heroicon('trash', 'btn-icon') ?></button>
                    </div>
                </template>
                <div x-show="cat.equipment_types.length === 0" class="text-secondary text-sm" style="font-style:italic;padding:.45rem .15rem;">No equipment types yet.</div>
                <a class="eqtax-add-type" :href="'<?= base_url('equipment/templates/create') ?>?category_id=' + cat.id" title="Add a new equipment type filed under this category">+ Add equipment type</a>

            </div>
        </div>
    </template>
</div>

<script>
function FF_EquipmentCategories() {
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
                types:      c.reduce((n, x) => n + (x.equipment_types ? x.equipment_types.length : 0), 0),
                enforcing:  c.filter(x => x.enforce_minimum_billing_days === 1).length,
            };
        },

        get filteredCategories() {
            const q = (this.search || '').trim().toLowerCase();
            return this.categories.filter(c => {
                if (!this.showInactive && c.is_active !== 1) return false;
                if (!q) return true;
                if (c.label.toLowerCase().includes(q) || (c.slug || '').toLowerCase().includes(q)) return true;
                return (c.equipment_types || []).some(t => t.name.toLowerCase().includes(q));
            });
        },

        async load() {
            this.loading = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/categories') ?>');
                this.categories = (r.success && r.data) ? r.data.categories : [];
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

        // S-EQTAX: remove an equipment type (template) straight from the category card.
        // Server blocks deletion when units still reference it.
        async deleteType(cat, t) {
            if (t.unit_count > 0) {
                FF_Toast.error(t.unit_count + ' unit(s) use "' + t.name + '" — reassign or remove them first.');
                return;
            }
            if (!(await FF_Confirm.ask('Delete equipment type "' + t.name + '"? This cannot be undone.'))) return;
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/templates/delete') ?>', { id: t.id });
            if (r.success) { FF_Toast.success('Equipment type deleted.'); this.load(); }
            else FF_Toast.error(r.error?.message || 'Could not delete equipment type.');
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
