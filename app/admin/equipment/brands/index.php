<?php
declare(strict_types=1);

/**
 * app/admin/equipment/brands/index.php
 *
 * S-UNIT-BRAND — operator manage screen for equipment BRANDS (manufacturers).
 * Add / rename / reorder / retire the brands offered in the unit create+edit
 * brand picker.
 *
 * Brand moved off the equipment TEMPLATE because a template is a TYPE ("53' Dry
 * Van") and one type is built by many manufacturers — the operator's example was
 * a 53ft dry van from five or six different brands. It is now a per-UNIT field.
 *
 * Slugs are immutable (labels editable). Delete is guarded server-side: a brand
 * still on live units returns 409 IN_USE and must be deactivated instead —
 * deactivating keeps it rendering on the units that carry it while removing it
 * from the picker for new ones.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          api/v1/equipment/brands/*
 * @session S-UNIT-BRAND
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');

$pageTitle      = 'Equipment Brands';
$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('equipment') ?>">Equipment</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Brands</span>
</nav>

<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Equipment Brands</h1>
        <p class="text-secondary text-sm">
            Manufacturers offered in the Brand / Make picker when adding or editing a unit.
        </p>
    </div>
    <div class="page-header-actions">
        <?= help_button('equipment') ?>
        <a href="<?= base_url('equipment/templates') ?>" class="btn btn-ghost btn-sm">Equipment Types</a>
    </div>
</div>

<div x-data="FF_EquipmentBrands()" x-init="load()">

    <!-- Add -->
    <?php if (can('equipment', 'create')): ?>
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><div class="card-title">Add a brand</div></div>
        <div class="card-body">
            <form @submit.prevent="create()" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">
                <div style="flex:1 1 260px;min-width:0;">
                    <input type="text" class="form-control" x-model="newLabel"
                           maxlength="100" placeholder="e.g. Great Dane" aria-label="New brand name">
                    <div class="form-hint" x-show="createError" x-text="createError"
                         style="color:var(--color-danger-text);"></div>
                </div>
                <button type="submit" class="btn btn-primary" :disabled="saving || !newLabel.trim()">
                    <span x-show="!saving">Add brand</span><span x-show="saving">Adding…</span>
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                Brands <span class="text-secondary" x-show="brands.length"
                              x-text="'(' + brands.length + ')'"></span>
            </div>
        </div>

        <div x-show="loading" class="card-body" style="text-align:center;padding:32px;">
            <span class="text-secondary">Loading brands…</span>
        </div>

        <div x-show="!loading && brands.length === 0" class="card-body">
            <div class="empty-state">
                <p class="empty-state-title">No brands yet</p>
                <p class="empty-state-text">Add a manufacturer above to offer it in the unit form.</p>
            </div>
        </div>

        <div x-show="!loading && brands.length > 0" class="tab-table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Brand</th>
                        <th>Slug</th>
                        <th class="text-right" title="Live units currently using this brand">Units</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="b in brands" :key="b.id">
                        <tr :style="b.is_active ? '' : 'opacity:.6;'">
                            <td>
                                <template x-if="editingId !== b.id">
                                    <span x-text="b.label" style="font-weight:600;"></span>
                                </template>
                                <template x-if="editingId === b.id">
                                    <input type="text" class="form-control" style="max-width:260px;"
                                           x-model="editLabel" maxlength="100"
                                           @keydown.enter.prevent="saveEdit(b)"
                                           @keydown.escape="cancelEdit()"
                                           aria-label="Brand name">
                                </template>
                            </td>
                            <td class="font-mono text-secondary" style="font-size:.78rem;" x-text="b.slug"></td>
                            <td class="text-right font-mono" x-text="b.unit_count"></td>
                            <td>
                                <span class="badge badge-no-dot"
                                      :class="b.is_active ? 'badge-success' : 'badge-secondary'"
                                      x-text="b.is_active ? 'Active' : 'Inactive'"></span>
                            </td>
                            <td style="text-align:right;white-space:nowrap;">
                                <?php if (can('equipment', 'edit')): ?>
                                <template x-if="editingId !== b.id">
                                    <span>
                                        <button class="btn btn-sm btn-secondary" @click="startEdit(b)">Rename</button>
                                        <button class="btn btn-sm btn-secondary"
                                                @click="toggleActive(b)"
                                                x-text="b.is_active ? 'Deactivate' : 'Activate'"></button>
                                    </span>
                                </template>
                                <template x-if="editingId === b.id">
                                    <span>
                                        <button class="btn btn-sm btn-primary" @click="saveEdit(b)">Save</button>
                                        <button class="btn btn-sm btn-secondary" @click="cancelEdit()">Cancel</button>
                                    </span>
                                </template>
                                <?php endif; ?>
                                <?php if (can('equipment', 'delete')): ?>
                                <button class="btn btn-sm btn-danger"
                                        x-show="editingId !== b.id"
                                        :disabled="b.unit_count > 0"
                                        :title="b.unit_count > 0
                                            ? 'In use by ' + b.unit_count + ' unit(s) — deactivate instead'
                                            : 'Remove this brand'"
                                        @click="destroy(b)">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="tab-table-footer" x-show="!loading && brands.length > 0">
            <span class="text-secondary">
                A brand in use can’t be deleted — deactivate it and it stays on those units
                while dropping out of the picker for new ones.
            </span>
        </div>
    </div>
</div>

<script>
function FF_EquipmentBrands() {
    return {
        brands: [], loading: true, saving: false,
        newLabel: '', createError: '',
        editingId: null, editLabel: '',

        async load() {
            this.loading = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/brands') ?>');
                if (r.success) this.brands = r.data.brands || [];
            } catch (e) { /* non-fatal */ }
            this.loading = false;
        },

        async create() {
            const label = (this.newLabel || '').trim();
            if (!label || this.saving) return;
            this.saving = true; this.createError = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/brands/create') ?>', { label });
                // FF_Api.post RESOLVES on 422 — gate on .success or a duplicate
                // name would look like it saved.
                if (r.success) {
                    this.newLabel = '';
                    await this.load();
                    FF_Toast?.show?.('Brand added', 'success');
                } else {
                    this.createError = r.error?.fields?.label || r.error?.message || 'Could not add that brand.';
                }
            } catch (e) { this.createError = 'Could not add that brand.'; }
            this.saving = false;
        },

        startEdit(b)  { this.editingId = b.id; this.editLabel = b.label; },
        cancelEdit()  { this.editingId = null; this.editLabel = ''; },

        async saveEdit(b) {
            const label = (this.editLabel || '').trim();
            if (!label) return;
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/brands/update') ?>', { id: b.id, label });
            if (r.success) { this.cancelEdit(); await this.load(); }
            else { FF_Toast?.show?.(r.error?.fields?.label || r.error?.message || 'Rename failed', 'error'); }
        },

        async toggleActive(b) {
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/brands/update') ?>',
                                        { id: b.id, is_active: b.is_active ? 0 : 1 });
            if (r.success) await this.load();
            else FF_Toast?.show?.(r.error?.message || 'Could not update that brand', 'error');
        },

        async destroy(b) {
            if (b.unit_count > 0) return;   // server also refuses; this is just the affordance
            if (!confirm('Remove the brand "' + b.label + '"?')) return;
            const r = await FF_Api.post('<?= base_url('api/v1/equipment/brands/delete') ?>', { id: b.id });
            if (r.success) await this.load();
            else FF_Toast?.show?.(r.error?.message || 'Could not delete that brand', 'error');
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
