<?php
declare(strict_types=1);

/**
 * app/admin/equipment/categories/index.php
 *
 * S-EQTAX — operator manage screen for the two-level equipment taxonomy.
 * Add / rename / retire categories and sub-categories, toggle the per-category
 * "apply short-lease minimum" billing rule, and reorder. Slugs are immutable
 * (only labels are editable). Delete is guarded server-side: an in-use category
 * or sub-category can't be removed, only deactivated.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          api/v1/equipment/categories/*, api/v1/equipment/subcategories/*
 * @session S-EQTAX-7
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');

$pageTitle      = 'Equipment Categories';
$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= base_url('equipment/templates') ?>" class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">← Equipment Types</a>
        <h1 class="page-header-title h4">Equipment Categories</h1>
        <p class="text-secondary text-sm">Organise equipment into categories and sub-types. Billing rules (like the short-lease minimum) apply per category and are inherited by its sub-types.</p>
    </div>
    <div class="page-header-actions"><?= help_button('equipment') ?></div>
</div>

<div x-data="FF_EquipmentTaxonomy()" x-init="init()">

    <!-- Add category -->
    <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-body" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
            <div class="form-group" style="margin:0;flex:1;min-width:220px;">
                <label class="form-label" for="newCatLabel">New category</label>
                <input id="newCatLabel" type="text" class="form-control" maxlength="100"
                       placeholder="e.g. Chassis" x-model="newCat.label" @keydown.enter="addCategory()">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;">
                    <input type="checkbox" x-model="newCat.enforce"> Apply short-lease minimum
                </label>
            </div>
            <button class="btn btn-primary" @click="addCategory()" :disabled="!newCat.label.trim() || busy">+ Add Category</button>
        </div>
    </div>

    <template x-if="loading">
        <div class="card"><div class="card-body text-secondary">Loading…</div></div>
    </template>

    <template x-if="!loading && categories.length === 0">
        <div class="card"><div class="card-body text-secondary">No categories yet. Add one above.</div></div>
    </template>

    <!-- Category list -->
    <template x-for="cat in categories" :key="cat.id">
        <div class="card" style="margin-bottom:1rem;" :style="cat.is_active ? '' : 'opacity:0.6;'">
            <div class="card-body">
                <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                    <div style="flex:1;min-width:200px;">
                        <span style="font-weight:600;font-size:1.05rem;" x-text="cat.label"></span>
                        <span class="text-secondary text-sm" x-text="'· ' + cat.slug"></span>
                        <span class="badge" x-show="!cat.is_active" style="margin-left:0.4rem;">inactive</span>
                        <span class="text-secondary text-sm" x-text="cat.template_count + ' type' + (cat.template_count===1?'':'s')" style="margin-left:0.5rem;"></span>
                    </div>
                    <label class="text-sm" style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;" title="When on, leases on this category honour the short-lease minimum-billing-days floor. Sub-types inherit it.">
                        <input type="checkbox" :checked="cat.enforce_minimum_billing_days===1"
                               @change="setEnforce(cat, $event.target.checked)"> Short-lease minimum
                    </label>
                    <label class="text-sm" style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;">
                        <input type="checkbox" :checked="cat.is_active===1"
                               @change="setActive(cat, $event.target.checked)"> Active
                    </label>
                    <button class="btn btn-ghost btn-sm" @click="renameCategory(cat)">Rename</button>
                    <button class="btn btn-outline-danger btn-sm" @click="deleteCategory(cat)">Delete</button>
                </div>

                <!-- Sub-categories -->
                <div style="margin-top:0.85rem;padding-left:1rem;border-left:2px solid var(--border-color, #e5e7eb);">
                    <template x-for="sub in cat.subcategories" :key="sub.id">
                        <div style="display:flex;align-items:center;gap:0.6rem;padding:0.3rem 0;flex-wrap:wrap;" :style="sub.is_active ? '' : 'opacity:0.6;'">
                            <span style="flex:1;min-width:180px;">
                                <span x-text="sub.label"></span>
                                <span class="text-secondary text-sm" x-text="'· ' + sub.slug"></span>
                                <span class="badge" x-show="!sub.is_active" style="margin-left:0.3rem;">inactive</span>
                                <span class="text-secondary text-sm" x-text="sub.template_count + ' type' + (sub.template_count===1?'':'s')" style="margin-left:0.4rem;"></span>
                            </span>
                            <label class="text-sm" style="display:flex;align-items:center;gap:0.3rem;cursor:pointer;">
                                <input type="checkbox" :checked="sub.is_active===1" @change="setSubActive(sub, $event.target.checked)"> Active
                            </label>
                            <button class="btn btn-ghost btn-sm" @click="renameSub(sub)">Rename</button>
                            <button class="btn btn-outline-danger btn-sm" @click="deleteSub(cat, sub)">Delete</button>
                        </div>
                    </template>

                    <div style="display:flex;gap:0.5rem;align-items:center;margin-top:0.5rem;">
                        <input type="text" class="form-control form-control-sm" style="max-width:280px;" maxlength="100"
                               placeholder="Add a sub-type…" x-model="cat._newSub"
                               @keydown.enter="addSub(cat)">
                        <button class="btn btn-secondary btn-sm" @click="addSub(cat)" :disabled="!(cat._newSub||'').trim() || busy">+ Add sub-type</button>
                    </div>
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
        newCat:     { label: '', enforce: false },

        async init() { await this.load(); },

        async load() {
            this.loading = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/categories') ?>');
                this.categories = (r.success && r.data) ? r.data.categories.map(c => ({ ...c, _newSub: '' })) : [];
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
                if (r.success) { this.newCat = { label: '', enforce: false }; FF_Toast.success('Category added.'); await this.load(); }
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
            r.success ? (FF_Toast.success('Saved.'), this.load()) : (FF_Toast.error('Save failed.'), this.load());
        },

        async setActive(cat, on) {
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/categories/update') ?>', { id: cat.id, is_active: on ? 1 : 0 });
            r.success ? this.load() : (FF_Toast.error('Save failed.'), this.load());
        },

        async deleteCategory(cat) {
            if (!(await FF_Confirm.ask('Delete category "' + cat.label + '"? In-use categories cannot be deleted — deactivate instead.'))) return;
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/categories/delete') ?>', { id: cat.id });
            if (r.success) { FF_Toast.success('Category deleted.'); this.load(); }
            else FF_Toast.error(r.error?.message || 'Could not delete category.');
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
