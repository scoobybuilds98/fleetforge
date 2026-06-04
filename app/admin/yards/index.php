<?php
declare(strict_types=1);

/**
 * FleetForge — Yards Management Page
 *
 * @file        app/admin/yards/index.php
 * @description Full CRUD management page for yards. Lists all yards (active and
 *              inactive). Managers can create, edit, and deactivate/reactivate
 *              yards via in-page modals backed by the yards API.
 *
 *              Guard: deactivating a yard that has upcoming pending/confirmed
 *              reservations is blocked by the API (api/v1/yards/delete.php).
 *
 *              Layout: KPI tiles (server-rendered) + Alpine.js table with
 *              Create modal and Edit modal.
 *
 *              Bulk actions: Activate and Deactivate (no bulk delete — yards
 *              use soft is_active toggling). Sort dropdown with direction
 *              selector passes sort/dir params to api/v1/yards/index.php.
 *
 * @method      GET
 * @auth        Session required; require_permission('reservations','view')
 *              (S-YARDS-PERM-FIX 2026-05-19: realigned from 'settings' to
 *              match the sidebar's 'reservations' module gate — dispatcher/
 *              accountant/read_only saw the link but got 403 on click.)
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/yards/index.php,
 *              api/v1/yards/create.php, api/v1/yards/update.php,
 *              api/v1/yards/delete.php, api/v1/yards/bulk_activate.php,
 *              api/v1/yards/bulk_deactivate.php
 * @decisions   D19 (optimistic lock on edit), D32 (confirmed CSS classes only)
 * @session     S018-EXT
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('reservations', 'view');

$canEdit = can('settings', 'edit') ||
           in_array($_SESSION['ff_user']['role_slug'] ?? '', ['super_admin', 'manager']);

// ── KPI tiles (server-rendered — yards is a tiny table) ─────────────────────
$totalYards    = db_count("SELECT COUNT(*) FROM yards");
$activeYards   = db_count("SELECT COUNT(*) FROM yards WHERE is_active = 1");
$inactiveYards = db_count("SELECT COUNT(*) FROM yards WHERE is_active = 0");

$pageTitle = 'Yards';
$helpModuleSlug = 'yards';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Yards</h1>
    <?php if ($canEdit): ?>
    <button class="btn btn-primary btn-sm"
            @click="openCreate()"
            x-data=""
            x-on:click="$dispatch('open-create-yard')">
        + New Yard
    </button>
    <?php endif; ?>
    <div class="page-header-actions">
        <?= help_button('yards') ?>
    </div>
</div>

<!-- TILES-1: KPI tiles dispatch `ff-yards-filter` to the Alpine component
     below. Total Yards → showAll=true, Active → showAll=false, Inactive →
     showAll=true + a second inactive-only hint consumed by the table. -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-yards-filter',{detail:{view:'all'}}))">
        <div class="stat-label">Total Yards</div>
        <div class="stat-value font-mono"><?= e($totalYards) ?></div>
        <div class="stat-delta">configured in system</div>
    </div>

    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-yards-filter',{detail:{view:'active'}}))">
        <div class="stat-label">Active</div>
        <div class="stat-value font-mono" style="color:var(--color-success);"><?= e($activeYards) ?></div>
        <div class="stat-delta">available for reservations</div>
    </div>

    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-yards-filter',{detail:{view:'inactive'}}))">
        <div class="stat-label">Inactive</div>
        <div class="stat-value font-mono" style="color:var(--text-secondary);"><?= e($inactiveYards) ?></div>
        <div class="stat-delta">hidden from dropdowns</div>
    </div>

</div>

<!-- ── Yards Table + Modals — Alpine.js ──────────────────────────────────── -->
<!-- TILES-1: @ff-yards-filter.window reacts to KPI tile clicks. view='all' →
     showAll=true + quickFilter=''; view='active' → showAll=false;
     view='inactive' → showAll=true + quickFilter='inactive' which the
     filteredYards getter uses to show only deactivated yards. -->
<div x-data="FF_YardsManager()"
     x-init="init()"
     @open-create-yard.window="openCreate()"
     @ff-yards-filter.window="
        const v = $event.detail.view;
        if (v === 'active')    { showAll = false; quickFilter = ''; }
        else if (v === 'inactive') { showAll = true;  quickFilter = 'inactive'; }
        else                    { showAll = true;  quickFilter = ''; }
     ">

    <!-- Global feedback banners -->
    <div class="alert alert-success" x-show="actionSuccess" x-text="actionSuccess"
         style="margin-bottom:16px;" x-transition
         x-effect="actionSuccess && setTimeout(() => actionSuccess = '', 4000)"></div>
    <div class="alert alert-danger"  x-show="actionError"   x-text="actionError"
         style="margin-bottom:16px;" x-transition
         x-effect="actionError && setTimeout(() => actionError = '', 6000)"></div>

    <!-- ── Yards table card ─────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <span style="font-weight:600;">All Yards</span>

            <!-- Active/All toggle -->
            <div style="display:flex;gap:0;border:1px solid var(--border-color);border-radius:6px;overflow:hidden;font-size:0.8125rem;">
                <button type="button"
                        class="btn btn-sm"
                        :class="showAll ? 'btn-ghost' : 'btn-primary'"
                        @click="showAll = false"
                        style="border-radius:0;border:none;">
                    Active Only
                </button>
                <button type="button"
                        class="btn btn-sm"
                        :class="showAll ? 'btn-primary' : 'btn-ghost'"
                        @click="showAll = true"
                        style="border-radius:0;border:none;border-left:1px solid var(--border-color);">
                    All Yards
                </button>
            </div>

            <!-- Sort controls -->
            <div style="display:flex;align-items:center;gap:6px;margin-left:auto;">
                <select class="form-input form-input-sm" x-model="sort" @change="load()"
                        style="width:auto;font-size:0.8125rem;">
                    <optgroup label="Sort by">
                        <option value="name">Name</option>
                        <option value="is_active">Status</option>
                        <option value="created_at">Created</option>
                    </optgroup>
                </select>
                <select class="form-input form-input-sm" x-model="dir" @change="load()"
                        style="width:auto;font-size:0.8125rem;">
                    <option value="ASC">&#8593; Asc</option>
                    <option value="DESC">&#8595; Desc</option>
                </select>
            </div>

            <span class="text-secondary" style="font-size:0.875rem;"
                  x-text="filteredYards.length + ' yard' + (filteredYards.length === 1 ? '' : 's')"></span>
        </div>

        <!-- Loading -->
        <div class="card-body" x-show="loading" style="text-align:center;padding:32px;">
            <span class="text-secondary">Loading yards…</span>
        </div>

        <!-- Empty state -->
        <template x-if="!loading && filteredYards.length === 0">
            <div class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No yards found</p>
                    <p class="empty-state-text">
                        <template x-if="!showAll">
                            <span>No active yards. <span x-show="yards.length > 0">Toggle "All Yards" to see inactive yards.</span></span>
                        </template>
                        <template x-if="showAll">
                            <span>No yards configured yet. Create your first yard to get started.</span>
                        </template>
                    </p>
                    <?php if ($canEdit): ?>
                    <button class="btn btn-primary btn-sm" style="margin-top:12px;" @click="openCreate()">
                        + New Yard
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </template>

        <!-- Bulk action bar -->
        <div x-show="selectedIds.length > 0"
             x-transition:enter="ff-bulk-enter"
             x-transition:enter-start="ff-bulk-enter-from"
             x-transition:enter-end="ff-bulk-enter-to"
             x-transition:leave="ff-bulk-leave"
             x-transition:leave-start="ff-bulk-leave-from"
             x-transition:leave-end="ff-bulk-leave-to"
             class="ff-bulk-bar">
            <span class="ff-bulk-bar-count" x-text="selectedIds.length + ' selected'"></span>
            <div class="ff-bulk-bar-sep"></div>
            <!-- Activate selected yards (re-enable) -->
            <button class="ff-bulk-btn" style="color:var(--color-success);"
                    @click="bulkActivate()" :disabled="bulkWorking">
                Activate
            </button>
            <!-- Deactivate selected yards (is_active=0) -->
            <button class="ff-bulk-btn" style="color:var(--text-secondary);"
                    @click="bulkDeactivate()" :disabled="bulkWorking">
                Deactivate
            </button>
            <div class="ff-bulk-bar-sep"></div>
            <!-- Permanently delete selected yards -->
            <button class="ff-bulk-btn ff-bulk-btn-delete" @click="bulkDeleteYards()" :disabled="bulkWorking">
                <svg width="12" height="13" viewBox="0 0 12 13" fill="currentColor"><path d="M4.5 1h3a.5.5 0 0 1 .5.5v.5H4v-.5A.5.5 0 0 1 4.5 1ZM3 2h6l-.4 7.2A1.5 1.5 0 0 1 7.1 10.5H4.9a1.5 1.5 0 0 1-1.5-1.3L3 2Z"/><path d="M1 2h10" stroke="currentColor" stroke-width="1" stroke-linecap="round" fill="none"/></svg>
                Delete
            </button>
            <button class="ff-bulk-btn ff-bulk-btn-clear" @click="clearSelection()"
                    title="Clear" aria-label="Clear selection">
                <svg width="10" height="10" viewBox="0 0 10 10" fill="none"
                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <line x1="1" y1="1" x2="9" y2="9"/>
                    <line x1="9" y1="1" x2="1" y2="9"/>
                </svg>
            </button>
        </div>

        <!-- Table -->
        <template x-if="!loading && filteredYards.length > 0">
            <div class="table-wrapper">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th class="th-checkbox">
                                <input type="checkbox" class="ff-checkbox"
                                       :checked="selectAll"
                                       @change="toggleSelectAll()"
                                       title="Select all">
                            </th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Capacity</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <?php if ($canEdit): ?>
                            <th style="width:160px;">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="yard in filteredYards" :key="yard.id">
                            <tr :style="!yard.is_active ? 'opacity:0.65;' : ''"
                                :class="{ 'ff-row-selected': selectedIds.includes(yard.id) }">
                                <td class="td-checkbox" @click.stop>
                                    <input type="checkbox" class="ff-checkbox"
                                           :checked="selectedIds.includes(yard.id)"
                                           @change="toggleSelect(yard.id)">
                                </td>
                                <td>
                                    <div class="font-medium" x-text="yard.name"></div>
                                    <div class="text-xs text-secondary" x-show="yard.notes"
                                         x-text="yard.notes && yard.notes.length > 60
                                             ? yard.notes.substring(0, 60) + '…'
                                             : yard.notes"></div>
                                </td>
                                <td class="text-sm">
                                    <span x-text="[yard.city, yard.state].filter(Boolean).join(', ') || '—'"></span>
                                    <div class="text-xs text-secondary" x-show="yard.address"
                                         x-text="yard.address"></div>
                                </td>
                                <td class="font-mono text-sm">
                                    <span x-text="yard.capacity ? yard.capacity + ' units' : '—'"></span>
                                </td>
                                <td class="text-sm">
                                    <template x-if="yard.phone">
                                        <a :href="'tel:' + yard.phone" class="link" x-text="yard.phone"></a>
                                    </template>
                                    <span x-show="!yard.phone" class="text-secondary">—</span>
                                </td>
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="yard.is_active ? 'badge-success' : 'badge-neutral'"
                                          x-text="yard.is_active ? 'Active' : 'Inactive'"></span>
                                </td>
                                <?php if ($canEdit): ?>
                                <td @click.stop="">
                                    <div style="display:flex;gap:4px;align-items:center;">
                                        <button class="btn btn-secondary btn-sm"
                                                @click="openEdit(yard)"
                                                title="Edit yard">
                                            Edit
                                        </button>
                                        <!-- Deactivate (active yards) -->
                                        <template x-if="yard.is_active">
                                            <button class="btn btn-ghost btn-sm"
                                                    style="color:var(--color-danger);"
                                                    :disabled="actionBusy === yard.id"
                                                    @click="deactivate(yard)"
                                                    title="Deactivate yard">
                                                Deactivate
                                            </button>
                                        </template>
                                        <!-- Activate (inactive yards) -->
                                        <template x-if="!yard.is_active">
                                            <button class="btn btn-ghost btn-sm"
                                                    style="color:var(--color-success);"
                                                    :disabled="actionBusy === yard.id"
                                                    @click="activate(yard)"
                                                    title="Reactivate yard">
                                                Activate
                                            </button>
                                        </template>
                                        <!-- Delete (permanent, soft-delete) -->
                                        <button class="btn btn-ghost btn-xs"
                                                style="color:var(--color-danger);opacity:0.7;"
                                                :disabled="actionBusy === yard.id"
                                                @click="deleteYard(yard)"
                                                title="Permanently delete yard">
                                            <svg width="13" height="13" viewBox="0 0 12 13" fill="currentColor"><path d="M4.5 1h3a.5.5 0 0 1 .5.5v.5H4v-.5A.5.5 0 0 1 4.5 1ZM3 2h6l-.4 7.2A1.5 1.5 0 0 1 7.1 10.5H4.9a1.5 1.5 0 0 1-1.5-1.3L3 2Z"/><path d="M1 2h10" stroke="currentColor" stroke-width="1" stroke-linecap="round" fill="none"/></svg>
                                        </button>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div><!-- /card -->

    <!-- ================================================================
         CREATE YARD MODAL
         ================================================================ -->
    <template x-if="createModal.open">
        <div style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);padding:16px;"
             @keydown.escape.window="createModal.open = false">
            <div class="card" style="width:560px;max-width:100%;max-height:90vh;overflow-y:auto;">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <h3 class="h5" style="margin:0;">New Yard</h3>
                    <button type="button"
                            class="btn btn-ghost btn-sm"
                            @click="createModal.open = false"
                            style="padding:4px 8px;font-size:1.1rem;line-height:1;"
                            aria-label="Close">×</button>
                </div>
                <div class="card-body">

                    <!-- Modal error -->
                    <div class="alert alert-danger" x-show="createModal.error"
                         x-text="createModal.error" style="margin-bottom:12px;"></div>

                    <div class="form-grid-2">

                        <div class="form-group" style="grid-column:1/-1;">
                            <label class="form-label" for="create-name">
                                Yard Name <span class="text-danger">*</span>
                            </label>
                            <input id="create-name" type="text" class="form-input"
                                   :class="createModal.errors.name ? 'is-invalid' : ''"
                                   placeholder="e.g. Delta Yard"
                                   x-model="createModal.form.name"
                                   maxlength="255">
                            <p class="form-error" x-show="createModal.errors.name"
                               x-text="createModal.errors.name"></p>
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label class="form-label" for="create-address">Address</label>
                            <input id="create-address" type="text" class="form-input"
                                   placeholder="e.g. 1234 River Rd"
                                   x-model="createModal.form.address"
                                   maxlength="500">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="create-city">City</label>
                            <input id="create-city" type="text" class="form-input"
                                   placeholder="e.g. Delta"
                                   x-model="createModal.form.city"
                                   maxlength="100">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="create-state">Province / State</label>
                            <input id="create-state" type="text" class="form-input"
                                   placeholder="e.g. BC"
                                   x-model="createModal.form.state"
                                   maxlength="100">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="create-postal">Postal Code</label>
                            <input id="create-postal" type="text" class="form-input"
                                   placeholder="e.g. V4K 0A1"
                                   x-model="createModal.form.postal_code"
                                   maxlength="20">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="create-capacity">Capacity (units)</label>
                            <input id="create-capacity" type="number" class="form-input"
                                   placeholder="e.g. 150"
                                   min="0"
                                   x-model.number="createModal.form.capacity">
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label class="form-label" for="create-phone">Phone</label>
                            <input id="create-phone" type="tel" class="form-input"
                                   placeholder="e.g. 604-555-0100"
                                   x-model="createModal.form.phone"
                                   maxlength="50">
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label class="form-label" for="create-notes">Notes</label>
                            <textarea id="create-notes" class="form-input" rows="3"
                                      placeholder="Access instructions, hours, contacts…"
                                      x-model="createModal.form.notes"></textarea>
                        </div>

                    </div><!-- /form-grid-2 -->

                </div><!-- /card-body -->
                <div class="card-footer" style="display:flex;justify-content:flex-end;gap:8px;">
                    <button class="btn btn-ghost btn-sm"
                            @click="createModal.open = false"
                            :disabled="createModal.submitting">Cancel</button>
                    <button class="btn btn-primary btn-sm"
                            @click="submitCreate()"
                            :disabled="createModal.submitting">
                        <span x-show="!createModal.submitting">Create Yard</span>
                        <span x-show="createModal.submitting">Creating…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- ================================================================
         EDIT YARD MODAL
         ================================================================ -->
    <template x-if="editModal.open">
        <div style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);padding:16px;"
             @keydown.escape.window="editModal.open = false">
            <div class="card" style="width:560px;max-width:100%;max-height:90vh;overflow-y:auto;">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <h3 class="h5" style="margin:0;">Edit Yard — <span x-text="editModal.form.name"></span></h3>
                    <button type="button"
                            class="btn btn-ghost btn-sm"
                            @click="editModal.open = false"
                            style="padding:4px 8px;font-size:1.1rem;line-height:1;"
                            aria-label="Close">×</button>
                </div>
                <div class="card-body">

                    <!-- Modal error -->
                    <div class="alert alert-danger" x-show="editModal.error"
                         x-text="editModal.error" style="margin-bottom:12px;"></div>

                    <div class="form-grid-2">

                        <div class="form-group" style="grid-column:1/-1;">
                            <label class="form-label" for="edit-yard-name">
                                Yard Name <span class="text-danger">*</span>
                            </label>
                            <input id="edit-yard-name" type="text" class="form-input"
                                   :class="editModal.errors.name ? 'is-invalid' : ''"
                                   x-model="editModal.form.name"
                                   maxlength="255">
                            <p class="form-error" x-show="editModal.errors.name"
                               x-text="editModal.errors.name"></p>
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label class="form-label" for="edit-yard-address">Address</label>
                            <input id="edit-yard-address" type="text" class="form-input"
                                   x-model="editModal.form.address"
                                   maxlength="500">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit-yard-city">City</label>
                            <input id="edit-yard-city" type="text" class="form-input"
                                   x-model="editModal.form.city"
                                   maxlength="100">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit-yard-state">Province / State</label>
                            <input id="edit-yard-state" type="text" class="form-input"
                                   x-model="editModal.form.state"
                                   maxlength="100">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit-yard-postal">Postal Code</label>
                            <input id="edit-yard-postal" type="text" class="form-input"
                                   x-model="editModal.form.postal_code"
                                   maxlength="20">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit-yard-capacity">Capacity (units)</label>
                            <input id="edit-yard-capacity" type="number" class="form-input"
                                   min="0"
                                   x-model.number="editModal.form.capacity">
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label class="form-label" for="edit-yard-phone">Phone</label>
                            <input id="edit-yard-phone" type="tel" class="form-input"
                                   x-model="editModal.form.phone"
                                   maxlength="50">
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label class="form-label" for="edit-yard-notes">Notes</label>
                            <textarea id="edit-yard-notes" class="form-input" rows="3"
                                      x-model="editModal.form.notes"></textarea>
                        </div>

                        <!-- Active toggle (only in edit) -->
                        <div class="form-group" style="grid-column:1/-1;padding-top:8px;border-top:1px solid var(--border-color);">
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                <input type="checkbox"
                                       x-model="editModal.form.is_active">
                                <span class="form-label" style="margin:0;">Yard is Active</span>
                            </label>
                            <p class="text-xs text-secondary" style="margin:4px 0 0 24px;">
                                Inactive yards are hidden from reservation dropdowns but historical data is preserved.
                            </p>
                        </div>

                    </div><!-- /form-grid-2 -->

                </div><!-- /card-body -->
                <div class="card-footer" style="display:flex;justify-content:flex-end;gap:8px;">
                    <button class="btn btn-ghost btn-sm"
                            @click="editModal.open = false"
                            :disabled="editModal.submitting">Cancel</button>
                    <button class="btn btn-primary btn-sm"
                            @click="submitEdit()"
                            :disabled="editModal.submitting">
                        <span x-show="!editModal.submitting">Save Changes</span>
                        <span x-show="editModal.submitting">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

</div><!-- /x-data -->

<!-- ================================================================
     ALPINE COMPONENT
     ================================================================ -->
<script>
function FF_YardsManager() {
    return {
        // ── State ────────────────────────────────────────────────────
        yards:         [],
        loading:       false,
        showAll:       true,       // toggle: active only vs all
        actionBusy:    null,       // stores yard.id of in-progress action
        actionError:   '',
        actionSuccess: '',

        // Sort state — mirrors api/v1/yards/index.php allowed sorts
        sort: 'name',
        dir:  'ASC',

        // Bulk selection state
        selectedIds: [],
        selectAll:   false,
        bulkWorking: false,

        // Create modal state
        createModal: {
            open:       false,
            submitting: false,
            error:      '',
            errors:     {},
            form:       {},
        },

        // Edit modal state
        editModal: {
            open:       false,
            submitting: false,
            error:      '',
            errors:     {},
            form:       {},
        },

        // TILES-1: extra quick-filter driven by the Inactive KPI tile.
        // Value is '' (no filter) or 'inactive' (show only deactivated).
        quickFilter: '',

        // ── Computed: filtered yards list ─────────────────────────────
        get filteredYards() {
            let list = this.showAll ? this.yards : this.yards.filter(y => y.is_active);
            if (this.quickFilter === 'inactive') {
                list = list.filter(y => !y.is_active);
            }
            return list;
        },

        // ── Init ─────────────────────────────────────────────────────
        async init() {
            await this.load();
        },

        // ── Load yards from API ───────────────────────────────────────
        async load() {
            // WHY clearSelection: stale selections from a previous sort/filter
            // pass wrong IDs to bulk actions after the list changes order.
            this.clearSelection();
            this.loading = true;
            try {
                // WHY all=1: we manage inactive yards here too
                const params = new URLSearchParams({
                    all:  '1',
                    sort: this.sort,
                    dir:  this.dir,
                });
                const res  = await fetch('<?= base_url('api/v1/yards/index.php') ?>?' + params);
                const data = await res.json();
                if (data.success) {
                    this.yards = data.data?.yards ?? [];
                } else {
                    this.actionError = data.error?.message || 'Failed to load yards.';
                }
            } catch (e) {
                this.actionError = 'Network error loading yards.';
            } finally {
                this.loading = false;
            }
        },

        // ── Bulk selection helpers ────────────────────────────────────
        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx === -1) this.selectedIds.push(id);
            else this.selectedIds.splice(idx, 1);
            // WHY: keep selectAll checkbox in sync with manual row toggling
            this.selectAll = this.filteredYards.length > 0 &&
                             this.selectedIds.length === this.filteredYards.length;
        },
        toggleSelectAll() {
            if (this.selectAll) {
                this.selectedIds = [];
                this.selectAll   = false;
            } else {
                this.selectedIds = this.filteredYards.map(y => y.id);
                this.selectAll   = true;
            }
        },
        clearSelection() {
            this.selectedIds = [];
            this.selectAll   = false;
        },

        // ── Bulk deactivate selected yards ────────────────────────────
        async bulkDeactivate() {
            if (!this.selectedIds.length || this.bulkWorking) return;
            const confirmed = await FF_Confirm.ask(
                'Deactivate ' + this.selectedIds.length + ' yard(s)?\n\n' +
                'Yards with upcoming reservations will be skipped by the API.'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post(
                    '<?= base_url('api/v1/yards/bulk_deactivate') ?>',
                    { ids: this.selectedIds }
                );
                if (res.success) {
                    FF_Toast.success(res.data.actioned + ' deactivated.' +
                        (res.data.skipped > 0 ? ' ' + res.data.skipped + ' skipped.' : ''));
                    this.clearSelection();
                    await this.load();
                } else {
                    FF_Toast.error(res.error?.message || 'Bulk deactivate failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error.');
            } finally {
                this.bulkWorking = false;
            }
        },

        // ── Bulk activate selected yards ──────────────────────────────
        async bulkActivate() {
            if (!this.selectedIds.length || this.bulkWorking) return;
            const confirmed = await FF_Confirm.ask(
                'Activate ' + this.selectedIds.length + ' yard(s)?'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post(
                    '<?= base_url('api/v1/yards/bulk_activate') ?>',
                    { ids: this.selectedIds }
                );
                if (res.success) {
                    FF_Toast.success(res.data.actioned + ' activated.' +
                        (res.data.skipped > 0 ? ' ' + res.data.skipped + ' skipped.' : ''));
                    this.clearSelection();
                    await this.load();
                } else {
                    FF_Toast.error(res.error?.message || 'Bulk activate failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error.');
            } finally {
                this.bulkWorking = false;
            }
        },

        // ── Open create modal with blank form ─────────────────────────
        openCreate() {
            this.createModal = {
                open:       true,
                submitting: false,
                error:      '',
                errors:     {},
                form: {
                    name:        '',
                    address:     '',
                    city:        '',
                    state:       '',
                    postal_code: '',
                    capacity:    null,
                    phone:       '',
                    notes:       '',
                },
            };
        },

        // ── Submit create ─────────────────────────────────────────────
        async submitCreate() {
            this.createModal.submitting = true;
            this.createModal.error      = '';
            this.createModal.errors     = {};
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/yards/create.php') ?>',
                    this.createModal.form);

                if (!res.success) {
                    if (res.error?.errors) this.createModal.errors = res.error.errors;
                    throw new Error(res.error?.message || 'Failed to create yard.');
                }

                this.createModal.open = false;
                await this.load();
                this.actionSuccess = `Yard '${this.createModal.form.name}' created.`;

            } catch (e) {
                this.createModal.error = e.message;
            } finally {
                this.createModal.submitting = false;
            }
        },

        // ── Open edit modal pre-populated with yard data ──────────────
        openEdit(yard) {
            this.editModal = {
                open:       true,
                submitting: false,
                error:      '',
                errors:     {},
                form: {
                    id:          yard.id,
                    updated_at:  yard.updated_at,  // D19 optimistic lock token
                    name:        yard.name         || '',
                    address:     yard.address      || '',
                    city:        yard.city         || '',
                    state:       yard.state        || '',
                    postal_code: yard.postal_code  || '',
                    capacity:    yard.capacity     ?? null,
                    phone:       yard.phone        || '',
                    notes:       yard.notes        || '',
                    is_active:   !!yard.is_active,
                },
            };
        },

        // ── Submit edit ───────────────────────────────────────────────
        async submitEdit() {
            this.editModal.submitting = true;
            this.editModal.error      = '';
            this.editModal.errors     = {};
            const yardName = this.editModal.form.name;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/yards/update.php') ?>',
                    this.editModal.form);

                if (!res.success) {
                    if (res.error?.errors) this.editModal.errors = res.error.errors;
                    throw new Error(res.error?.message || 'Failed to update yard.');
                }

                this.editModal.open = false;
                await this.load();
                this.actionSuccess = `Yard '${yardName}' updated.`;

            } catch (e) {
                this.editModal.error = e.message;
            } finally {
                this.editModal.submitting = false;
            }
        },

        // ── Deactivate yard ───────────────────────────────────────────
        async deactivate(yard) {
            if (!(await FF_Confirm.ask(`Deactivate yard '${yard.name}'?\n\nIt will be hidden from reservation dropdowns. This cannot be done if the yard has upcoming reservations.`))) return;
            this.actionBusy  = yard.id;
            this.actionError = '';
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/yards/delete.php') ?>',
                    { id: yard.id });

                if (!res.success) throw new Error(res.error?.message || 'Failed to deactivate yard.');
                await this.load();
                this.actionSuccess = `Yard '${yard.name}' deactivated.`;

            } catch (e) {
                this.actionError = e.message;
            } finally {
                this.actionBusy = null;
            }
        },

        // ── Activate yard (re-enable) ─────────────────────────────────
        async activate(yard) {
            if (!(await FF_Confirm.ask(`Reactivate yard '${yard.name}'?\n\nIt will appear in reservation dropdowns again.`))) return;
            this.actionBusy  = yard.id;
            this.actionError = '';
            try {
                // WHY: we use update.php with is_active=1 to reactivate — no dedicated endpoint
                const res = await FF_Api.post('<?= base_url('api/v1/yards/update.php') ?>', {
                    id:          yard.id,
                    updated_at:  yard.updated_at,
                    name:        yard.name,
                    address:     yard.address     || null,
                    city:        yard.city        || null,
                    state:       yard.state       || null,
                    postal_code: yard.postal_code || null,
                    capacity:    yard.capacity    || null,
                    phone:       yard.phone       || null,
                    notes:       yard.notes       || null,
                    is_active:   1,
                });

                if (!res.success) throw new Error(res.error?.message || 'Failed to activate yard.');
                await this.load();
                this.actionSuccess = `Yard '${yard.name}' reactivated.`;

            } catch (e) {
                this.actionError = e.message;
            } finally {
                this.actionBusy = null;
            }
        },

        // ── Permanently delete a single yard ──────────────────────────
        async deleteYard(yard) {
            const confirmed = await FF_Confirm.ask(
                `Permanently delete yard '${yard.name}'?\n\nThis cannot be undone. Active or upcoming reservations will block deletion.`
            );
            if (!confirmed) return;
            this.actionBusy  = yard.id;
            this.actionError = '';
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/yards/destroy') ?>', { id: yard.id });
                if (!res.success) throw new Error(res.error?.message || 'Failed to delete yard.');
                await this.load();
                FF_Toast.success(`Yard '${yard.name}' deleted.`);
            } catch (e) {
                this.actionError = e.message;
                FF_Toast.error(e.message);
            } finally {
                this.actionBusy = null;
            }
        },

        // ── Bulk permanent delete ──────────────────────────────────────
        async bulkDeleteYards() {
            if (!this.selectedIds.length || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const confirmed = await FF_Confirm.ask(
                `Permanently delete ${count} yard${count === 1 ? '' : 's'}? This cannot be undone.\n\nYards with active reservations will be skipped.`
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            let deleted = 0, skipped = 0;
            const errors = [];
            for (const id of this.selectedIds) {
                try {
                    const res = await FF_Api.post('<?= base_url('api/v1/yards/destroy') ?>', { id });
                    if (res.success) deleted++;
                    else { skipped++; errors.push(res.error?.message || 'Unknown error'); }
                } catch (e) {
                    skipped++;
                    errors.push(e.message);
                }
            }
            this.clearSelection();
            await this.load();
            if (deleted > 0) FF_Toast.success(deleted + ' yard' + (deleted === 1 ? '' : 's') + ' deleted' + (skipped > 0 ? ', ' + skipped + ' skipped' : '') + '.');
            if (errors.length) FF_Toast.error(errors.slice(0, 2).join('; ') + (errors.length > 2 ? '…' : ''));
            this.bulkWorking = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
