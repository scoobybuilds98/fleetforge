<?php
declare(strict_types=1);

/**
 * app/admin/equipment/templates/index.php
 *
 * Equipment templates list page. Shows all templates with unit_count, category
 * badge, and default rates. Filterable by category. Link to create new template.
 * Delete is blocked when unit_count > 0 (handled by API; shown via disabled button).
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/equipment/templates/index.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.3 Equipment Templates
 * @decisions D30, D32
 * @session S006
 */

// dirname(__DIR__, 4): app/admin/equipment/templates/ → equipment/ → admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');

$pageTitle      = 'Equipment Types';
$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <a href="<?= base_url('equipment') ?>" class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">
            ← Equipment
        </a>
        <h1 class="page-header-title h4">Equipment Types</h1>
    </div>
    <div class="page-header-actions">
        <?= help_button('equipment') ?>
        <?php if (can('equipment', 'create')): ?>
        <a href="<?= base_url('equipment/templates/create') ?>" class="btn btn-primary btn-sm">
            + Add new equipment type
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     TEMPLATES ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_Templates()" x-init="init()">

    <!-- ── FILTER TOOLBAR ────────────────────────────────────── -->
    <div class="table-toolbar">
        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search equipment type name…"
                   x-model="filters.search"
                   @input.debounce.400ms="resetPage()"
                   maxlength="255"
                   style="min-width:220px;"
                   aria-label="Search equipment types">
            <select class="form-select form-control-sm"
                    x-model="filters.category"
                    @change="resetPage()">
                <option value="">All Categories</option>
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
        </div>
        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="pagination.total !== undefined ? pagination.total + ' equipment type' + (pagination.total !== 1 ? 's' : '') : ''">
            </span>
        </div>
    </div>

    <!-- ── TABLE CARD ─────────────────────────────────────────── -->
    <div class="card">

        <template x-if="loading">
            <div>
                <template x-for="n in 6" :key="n">
                    <div class="skeleton skeleton-row"></div>
                </template>
            </div>
        </template>

        <template x-if="!loading && loadError">
            <div class="empty-state">
                <p class="empty-state-title">Failed to load equipment types</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="load()">Retry</button>
            </div>
        </template>

        <template x-if="!loading && !loadError && templates.length === 0">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                </div>
                <p class="empty-state-title">No equipment types yet</p>
                <p class="empty-state-text">Create equipment types to define the equipment categories in your fleet.</p>
                <?php if (can('equipment', 'create')): ?>
                <a href="<?= base_url('equipment/templates/create') ?>" class="btn btn-primary btn-sm">
                    + Add new equipment type
                </a>
                <?php endif; ?>
            </div>
        </template>

        <template x-if="!loading && !loadError && templates.length > 0">
            <div class="table-wrapper">
                <table class="table" aria-label="Equipment types">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Units</th>
                            <th>Default Rates</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="tpl in templates" :key="tpl.id">
                            <tr>
                                <td>
                                    <div class="font-medium" x-text="tpl.name"></div>
                                    <div class="text-sm text-secondary" x-text="tpl.brand && tpl.model ? tpl.brand + ' ' + tpl.model : (tpl.brand || tpl.model || '')"></div>
                                </td>
                                <td>
                                    <span class="badge badge-info badge-no-dot"
                                          x-text="tpl.category.replace(/_/g,' ')"
                                          style="text-transform:capitalize;">
                                    </span>
                                </td>
                                <td>
                                    <!-- Spec §13: unit_count > 0 means can't delete -->
                                    <a :href="'<?= base_url('equipment') ?>?template_id=' + tpl.id"
                                       class="font-mono font-medium link"
                                       x-text="tpl.unit_count"
                                       :title="tpl.unit_count + ' unit' + (tpl.unit_count !== 1 ? 's' : '') + ' using this equipment type'">
                                    </a>
                                </td>
                                <td class="font-mono text-sm">
                                    <template x-if="tpl.default_daily_rate">
                                        <span x-text="'$' + parseFloat(tpl.default_daily_rate).toFixed(2) + '/day'"></span>
                                    </template>
                                    <template x-if="!tpl.default_daily_rate && !tpl.default_monthly_rate">
                                        <span class="text-secondary">—</span>
                                    </template>
                                </td>
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="tpl.is_active ? 'badge-success' : 'badge-neutral'"
                                          x-text="tpl.is_active ? 'Active' : 'Inactive'">
                                    </span>
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <div style="display:flex;gap:6px;justify-content:flex-end;">
                                        <?php if (can('equipment', 'edit')): ?>
                                        <a :href="'<?= base_url('equipment/templates/edit') ?>?id=' + tpl.id"
                                           class="btn btn-secondary btn-sm">
                                            Edit
                                        </a>
                                        <?php endif; ?>
                                        <?php if (can('equipment', 'delete')): ?>
                                        <button class="btn btn-ghost btn-sm text-danger"
                                                :disabled="tpl.unit_count > 0"
                                                :title="tpl.unit_count > 0 ? 'Cannot delete: ' + tpl.unit_count + ' units use this equipment type' : 'Delete equipment type'"
                                                @click="deleteTemplate(tpl)">
                                            Delete
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

    </div>

    <!-- ── PAGINATION ─────────────────────────────────────────── -->
    <template x-if="!loading && pagination.total_pages > 1">
        <div class="pagination">
            <div class="pagination-info"
                 x-text="'Page ' + pagination.page + ' of ' + pagination.total_pages">
            </div>
            <div class="pagination-controls">
                <button class="btn btn-secondary btn-sm"
                        :disabled="pagination.page <= 1"
                        @click="goToPage(pagination.page - 1)">← Prev</button>
                <button class="btn btn-secondary btn-sm"
                        :disabled="pagination.page >= pagination.total_pages"
                        @click="goToPage(pagination.page + 1)">Next →</button>
            </div>
        </div>
    </template>

</div><!-- /x-data -->

<script>
function FF_Templates() {
    return {
        templates: [],
        loading:   true,
        loadError: null,
        pagination:{},
        filters: { search: '', category: '' },
        currentPage: 1,

        async init() { await this.load(); },

        async load() {
            this.loading   = true;
            this.loadError = null;
            const params = new URLSearchParams();
            if (this.filters.search)   params.set('search',   this.filters.search);
            if (this.filters.category) params.set('category', this.filters.category);
            params.set('page',     this.currentPage);
            params.set('per_page', 50);
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/templates') ?>?' + params);
                if (r.success) {
                    this.templates  = r.data.items;
                    this.pagination = r.data.pagination;
                } else {
                    this.loadError = r.message || 'Failed to load equipment types.';
                }
            } catch(e) {
                this.loadError = 'Network error.';
            }
            this.loading = false;
        },

        resetPage() { this.currentPage = 1; this.load(); },
        goToPage(p) { this.currentPage = p; this.load(); },

        async deleteTemplate(tpl) {
            if (tpl.unit_count > 0) return;
            if (!(await FF_Confirm.ask('Delete equipment type "' + tpl.name + '"? This cannot be undone.'))) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/templates/delete') ?>', { id: tpl.id });
                if (r.success) {
                    await this.load();
                    FF_Toast.success('Equipment type deleted.');
                } else {
                    FF_Toast.error(r.message || 'Failed to delete equipment type.');
                }
            } catch(e) {
                FF_Toast.error('Network error.');
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
