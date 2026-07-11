<?php declare(strict_types=1);

/**
 * FleetForge — CapEx Requests
 *
 * @file        app/admin/accounting/capex/index.php
 * @description CapEx (capital expenditure) request management. KPI tiles for
 *              Total / Pending / Approved / In Progress, with budget vs.
 *              actual variance shown on each row. Workflow:
 *              create → approve → start → complete (auto-creates an asset).
 *              Manager+ guard on approve via API; create/start/complete
 *              available to anyone with fixed_assets:edit.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/capex/*
 *
 * @session     S029 — Fixed Assets module (CapEx UI)
 */

// dirname(__DIR__, 4): capex/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('fixed_assets', 'view');

// ── KPI tiles — server-side ───────────────────────────────────
$totalCapex     = db_count("SELECT COUNT(*) FROM acc_capex_requests");
$pendingCapex   = db_count("SELECT COUNT(*) FROM acc_capex_requests WHERE status = 'pending_approval'");
$approvedCapex  = db_count("SELECT COUNT(*) FROM acc_capex_requests WHERE status = 'approved'");
$inProgress     = db_count("SELECT COUNT(*) FROM acc_capex_requests WHERE status = 'in_progress'");
$totalBudget    = db_row("SELECT COALESCE(SUM(budget_amount),0) AS t FROM acc_capex_requests WHERE status NOT IN ('rejected','cancelled')")['t'] ?? '0.00';

$pageTitle = 'CapEx Requests';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────── -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">CapEx</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Capital Expenditure Requests</h1>
    <div class="page-header-actions">
        <?php if (can('fixed_assets', 'create')): ?>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-capex-create')">
            + New Request
        </button>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- TILES-1: KPI tiles dispatch `ff-capex-filter` to set filterStatus -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--blue" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-capex-filter',{detail:{status:''}}))">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Total Requests</div>
        <div class="stat-value font-mono"><?= e((string) $totalCapex) ?></div>
    </div>
    <div class="stat-card stat-card--amber" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-capex-filter',{detail:{status:'pending_approval'}}))">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Pending Approval</div>
        <div class="stat-value font-mono"><?= e((string) $pendingCapex) ?></div>
    </div>
    <div class="stat-card stat-card--green" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-capex-filter',{detail:{status:'approved'}}))">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Approved + In Progress</div>
        <div class="stat-value font-mono"><?= e((string) ($approvedCapex + $inProgress)) ?></div>
    </div>
    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-capex-filter',{detail:{status:''}}))">
        <span class="stat-icon"><svg><use href="#icon-banknotes"/></svg></span>
        <div class="stat-label">Active Budget</div>
        <div class="stat-value font-mono">$<?= number_format((float) $totalBudget, 2) ?></div>
    </div>
</div>

<!-- ============================================================
     CAPEX REQUESTS — ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_Capex()" x-init="init()"
     @open-capex-create.window="openCreate()"
     @ff-capex-filter.window="filterStatus = (filterStatus === $event.detail.status && filterStatus !== '') ? '' : $event.detail.status; load()">

    <!-- ============================================================
         FLAGGED WORK ORDERS PANEL
         Completed work orders whose total_cost > capex_threshold_cad
         that are not yet linked to a CapEx review row.
         ============================================================ -->
    <div class="card" style="margin-bottom:24px;border-left:4px solid var(--color-warning);padding:16px 20px;" x-show="flaggedWorkOrders.length > 0 || flaggedLoading">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div>
                <h3 class="h6" style="margin:0;">
                    <svg width="18" height="18" style="vertical-align:-3px;color:var(--color-warning);"><use href="#icon-exclamation-triangle"/></svg>
                    Flagged Work Orders
                    <span class="text-secondary text-sm" x-text="'(' + flaggedWorkOrders.length + ' awaiting review, threshold $' + parseFloat(flaggedThreshold).toLocaleString('en-CA', {minimumFractionDigits: 2}) + ')'"></span>
                </h3>
                <p class="text-secondary text-sm" style="margin:4px 0 0;">Completed work orders above the CapEx threshold need accounting review: capitalize as a fixed asset, or expense as repairs.</p>
            </div>
        </div>

        <template x-if="flaggedLoading">
            <p class="text-secondary text-sm">Loading flagged work orders…</p>
        </template>

        <template x-if="!flaggedLoading && flaggedWorkOrders.length > 0">
            <div style="overflow-x:auto;">
                <table class="data-table" style="font-size:0.875rem;">
                    <thead>
                        <tr>
                            <th>WO #</th>
                            <th>Title</th>
                            <th>Equipment</th>
                            <th>Vendor</th>
                            <th>Completed</th>
                            <th class="text-right">Cost</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="wo in flaggedWorkOrders" :key="wo.id">
                            <tr>
                                <td class="font-mono" x-text="wo.work_order_number"></td>
                                <td x-text="wo.title || '—'"></td>
                                <td class="text-sm">
                                    <template x-if="wo.unit_number">
                                        <span><span class="font-mono" x-text="wo.unit_number"></span>
                                        <span class="text-secondary" x-text="' (' + (wo.brand || '') + ' ' + (wo.model || '') + ')'"></span></span>
                                    </template>
                                    <template x-if="!wo.unit_number"><span class="text-secondary">—</span></template>
                                </td>
                                <td class="text-sm" x-text="wo.vendor_name || '—'"></td>
                                <td class="text-sm" x-text="(wo.completed_date || '').slice(0, 10)"></td>
                                <td class="text-right font-mono" x-text="formatMoney(wo.total_cost)"></td>
                                <td>
                                    <?php if (can('fixed_assets', 'edit')): ?>
                                    <button class="btn btn-success btn-xs" @click="openCapitalize(wo)">Capitalize</button>
                                    <button class="btn btn-warning btn-xs" @click="openExpense(wo)">Expense</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    <!-- ── Filter toolbar ────────────────────────────────────── -->
    <div class="table-toolbar" style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <input type="text" class="form-control form-control-sm" placeholder="Search by title or request #…"
               x-model.debounce.300ms="search" @input="load()" style="min-width:280px;">

        <select class="form-select form-control-sm" x-model="filterStatus" @change="load()" style="min-width:160px;">
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="pending_approval">Pending Approval</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
        </select>

        <select class="form-select form-control-sm" x-model="filterClass" @change="load()" style="min-width:160px;">
            <option value="">All classes</option>
            <option value="fleet_equipment">Fleet Equipment</option>
            <option value="vehicles">Vehicles</option>
            <option value="office_equipment">Office Equipment</option>
            <option value="leasehold_improvements">Leasehold Improvements</option>
            <option value="land">Land</option>
            <option value="building">Building</option>
            <option value="other">Other</option>
        </select>

        <span class="text-secondary text-sm" x-text="pagination.total + ' request' + (pagination.total !== 1 ? 's' : '')"></span>
    </div>

    <!-- ── Loading state ─────────────────────────────────────── -->
    <template x-if="loading">
        <div class="card"><div class="empty-state">Loading…</div></div>
    </template>

    <!-- ── Empty state ───────────────────────────────────────── -->
    <template x-if="!loading && requests.length === 0">
        <div class="card"><div class="empty-state">
            <p class="empty-state-title">No CapEx requests found</p>
            <p class="empty-state-text">Click "+ New Request" to submit one.</p>
        </div></div>
    </template>

    <!-- ── Requests table ────────────────────────────────────── -->
    <template x-if="!loading && requests.length > 0">
        <div class="card" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Request #</th>
                        <th>Title</th>
                        <th>Class</th>
                        <th>Status</th>
                        <th class="text-right">Budget</th>
                        <th class="text-right">Actual</th>
                        <th class="text-right">Variance</th>
                        <th>Linked Asset</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="r in requests" :key="r.id">
                        <tr>
                            <td class="font-mono" x-text="r.request_number"></td>
                            <td><strong x-text="r.title"></strong></td>
                            <td x-text="formatClass(r.asset_class)"></td>
                            <td><span class="badge badge-no-dot" :class="statusBadge(r.status)" x-text="formatStatus(r.status)"></span></td>
                            <td class="text-right font-mono" x-text="formatMoney(r.budget_amount)"></td>
                            <td class="text-right font-mono" x-text="r.actual_amount ? formatMoney(r.actual_amount) : '—'"></td>
                            <td class="text-right font-mono" :class="varianceClass(r)" x-text="r.actual_amount ? formatMoney(r.variance) : '—'"></td>
                            <td>
                                <template x-if="r.linked_asset_number">
                                    <span class="text-sm">
                                        <span class="font-mono" x-text="r.linked_asset_number"></span>
                                        <span class="text-secondary" x-text="' — ' + r.linked_asset_name"></span>
                                    </span>
                                </template>
                                <template x-if="!r.linked_asset_number">
                                    <span class="text-secondary text-sm">—</span>
                                </template>
                            </td>
                            <td>
                                <button class="btn btn-secondary btn-xs" @click="openDetail(r)">View</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <!-- ── Pagination ────────────────────────────────────────── -->
    <div class="table-pagination" x-show="pagination.total_pages > 1" style="margin-top:16px;text-align:center;">
        <button class="btn btn-secondary btn-sm" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">‹ Prev</button>
        <span x-text="'Page ' + pagination.page + ' of ' + pagination.total_pages" style="margin:0 12px;"></span>
        <button class="btn btn-secondary btn-sm" :disabled="!pagination.has_more" @click="goPage(pagination.page + 1)">Next ›</button>
    </div>

    <!-- ============================================================
         DETAIL MODAL
         ============================================================ -->
    <div class="modal-backdrop" x-show="detailOpen" x-transition @click.self="detailOpen = false" style="display:none;">
        <div class="modal" style="max-width:700px;width:95%;max-height:85vh;overflow-y:auto;">
            <div class="modal-header">
                <h2 class="h5" x-text="detailReq ? detailReq.request_number + ' — ' + detailReq.title : ''"></h2>
                <button class="modal-close-btn" aria-label="Close" @click="detailOpen = false">×</button>
            </div>
            <div class="modal-body">
                <template x-if="detailReq">
                    <div>
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px 24px;margin-bottom:18px;">
                            <div><strong>Status:</strong> <span class="badge badge-no-dot" :class="statusBadge(detailReq.status)" x-text="formatStatus(detailReq.status)"></span></div>
                            <div><strong>Class:</strong> <span x-text="formatClass(detailReq.asset_class)"></span></div>
                            <div><strong>Budget:</strong> <span class="font-mono" x-text="formatMoney(detailReq.budget_amount)"></span></div>
                            <div><strong>Actual:</strong> <span class="font-mono" x-text="detailReq.actual_amount ? formatMoney(detailReq.actual_amount) : '—'"></span></div>
                            <div><strong>Variance:</strong> <span class="font-mono" :class="varianceClass(detailReq)" x-text="detailReq.actual_amount ? formatMoney(detailReq.variance) : '—'"></span></div>
                            <div><strong>Requested:</strong> <span class="text-sm" x-text="(detailReq.requested_at || '').slice(0,10)"></span></div>
                            <template x-if="detailReq.approved_at">
                                <div><strong>Approved:</strong> <span class="text-sm" x-text="(detailReq.approved_at || '').slice(0,10)"></span></div>
                            </template>
                            <template x-if="detailReq.completed_at">
                                <div><strong>Completed:</strong> <span class="text-sm" x-text="(detailReq.completed_at || '').slice(0,10)"></span></div>
                            </template>
                            <template x-if="detailReq.linked_asset_number">
                                <div style="grid-column:1/-1;"><strong>Linked Asset:</strong>
                                    <span class="font-mono" x-text="detailReq.linked_asset_number"></span> —
                                    <span x-text="detailReq.linked_asset_name"></span>
                                </div>
                            </template>
                        </div>

                        <template x-if="detailReq.description">
                            <div style="margin-bottom:10px;"><strong>Description:</strong>
                                <p class="text-sm" x-text="detailReq.description"></p>
                            </div>
                        </template>
                        <template x-if="detailReq.justification">
                            <div style="margin-bottom:10px;"><strong>Justification:</strong>
                                <p class="text-sm" x-text="detailReq.justification"></p>
                            </div>
                        </template>
                        <template x-if="detailReq.rejected_reason">
                            <div class="alert alert-danger" style="margin-top:10px;"><strong>Rejected:</strong>
                                <span x-text="detailReq.rejected_reason"></span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <div class="modal-footer">
                <?php if (can('fixed_assets', 'edit')): ?>
                <button class="btn btn-success btn-sm"
                        x-show="detailReq && detailReq.status === 'pending_approval'"
                        @click="approveRequest()">Approve</button>
                <button class="btn btn-primary btn-sm"
                        x-show="detailReq && detailReq.status === 'approved'"
                        @click="startRequest()">Start Work</button>
                <button class="btn btn-success btn-sm"
                        x-show="detailReq && (detailReq.status === 'approved' || detailReq.status === 'in_progress')"
                        @click="openComplete()">Complete + Create Asset</button>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm" @click="detailOpen = false">Close</button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         CREATE MODAL
         ============================================================ -->
    <div class="modal-backdrop" x-show="createOpen" x-transition @click.self="createOpen = false" style="display:none;">
        <div class="modal" style="max-width:560px;width:95%;max-height:85vh;overflow-y:auto;">
            <div class="modal-header"><h2 class="h5">New CapEx Request</h2><button class="modal-close-btn" aria-label="Close" @click="createOpen = false">×</button></div>
            <div class="modal-body">
                <div class="form-error-banner" x-show="createFormError" x-cloak x-text="createFormError"></div>
                <div class="form-group"><label>Title *</label>
                    <input type="text" class="form-control form-control-sm" x-model="createForm.title"
                           :class="createErrors.title ? 'is-invalid' : ''" @input="createErrors.title = ''"
                           placeholder="e.g. New Trailer Purchase">
                    <div class="field-error" x-show="createErrors.title" x-cloak x-text="createErrors.title"></div>
                </div>
                <div class="form-group"><label>Asset Class *</label>
                    <select class="form-select form-control-sm" x-model="createForm.asset_class"
                            :class="createErrors.asset_class ? 'is-invalid' : ''" @change="createErrors.asset_class = ''">
                        <option value="fleet_equipment">Fleet Equipment</option>
                        <option value="vehicles">Vehicles</option>
                        <option value="office_equipment">Office Equipment</option>
                        <option value="leasehold_improvements">Leasehold Improvements</option>
                        <option value="land">Land</option>
                        <option value="building">Building</option>
                        <option value="other">Other</option>
                    </select>
                    <div class="field-error" x-show="createErrors.asset_class" x-cloak x-text="createErrors.asset_class"></div>
                </div>
                <div class="form-group"><label>Budget Amount ($) *</label>
                    <input type="number" step="0.01" min="0.01" class="form-control form-control-sm" x-model="createForm.budget_amount"
                           :class="createErrors.budget_amount ? 'is-invalid' : ''" @input="createErrors.budget_amount = ''">
                    <div class="field-error" x-show="createErrors.budget_amount" x-cloak x-text="createErrors.budget_amount"></div>
                </div>
                <div class="form-group"><label>Description</label>
                    <textarea class="form-control form-control-sm" rows="2" x-model="createForm.description"
                              :class="createErrors.description ? 'is-invalid' : ''" @input="createErrors.description = ''"></textarea>
                    <div class="field-error" x-show="createErrors.description" x-cloak x-text="createErrors.description"></div>
                </div>
                <div class="form-group"><label>Justification</label>
                    <textarea class="form-control form-control-sm" rows="3" x-model="createForm.justification"
                              :class="createErrors.justification ? 'is-invalid' : ''" @input="createErrors.justification = ''"
                              placeholder="Why is this purchase needed?"></textarea>
                    <div class="field-error" x-show="createErrors.justification" x-cloak x-text="createErrors.justification"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="createOpen = false">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="submitCreate()" :disabled="createBusy">
                    <span x-show="!createBusy">Submit Request</span><span x-show="createBusy">Saving…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         CAPITALIZE FLAGGED WO MODAL
         Creates a fixed asset from a flagged work order.
         ============================================================ -->
    <div class="modal-backdrop" x-show="capitalizeOpen" x-transition @click.self="capitalizeOpen = false" style="display:none;">
        <div class="modal" style="max-width:680px;width:95%;max-height:85vh;overflow-y:auto;">
            <div class="modal-header"><h2 class="h5">Capitalize Work Order</h2><button class="modal-close-btn" aria-label="Close" @click="capitalizeOpen = false">×</button></div>
            <div class="modal-body">
                <div class="form-error-banner" x-show="capitalizeFormError" x-cloak x-text="capitalizeFormError"></div>
                <p class="text-secondary text-sm" x-show="capitalizeWO">
                    Capitalizing <strong class="font-mono" x-text="capitalizeWO?.work_order_number"></strong>
                    — <span x-text="capitalizeWO?.title"></span>
                    (<span class="font-mono" x-text="formatMoney(capitalizeWO?.total_cost)"></span>)
                </p>
                <p class="text-info text-sm">A new fixed asset will be created and linked to this work order. The expense already posted via the WO bill stays in 6190; this entry only registers the asset for depreciation.</p>

                <h3 class="h6" style="margin-top:14px;">Asset Details</h3>
                <div class="form-group"><label>Asset Name *</label>
                    <input type="text" class="form-control form-control-sm" x-model="capitalizeForm.asset_data.name"
                           :class="capitalizeErrors.name ? 'is-invalid' : ''" @input="capitalizeErrors.name = ''">
                    <div class="field-error" x-show="capitalizeErrors.name" x-cloak x-text="capitalizeErrors.name"></div>
                </div>
                <div class="form-group"><label>Asset Class *</label>
                    <select class="form-select form-control-sm" x-model="capitalizeForm.asset_data.asset_class"
                            :class="capitalizeErrors.asset_class ? 'is-invalid' : ''" @change="capitalizeErrors.asset_class = ''">
                        <option value="fleet_equipment">Fleet Equipment</option>
                        <option value="vehicles">Vehicles</option>
                        <option value="office_equipment">Office Equipment</option>
                        <option value="leasehold_improvements">Leasehold Improvements</option>
                        <option value="building">Building</option>
                        <option value="other">Other</option>
                    </select>
                    <div class="field-error" x-show="capitalizeErrors.asset_class" x-cloak x-text="capitalizeErrors.asset_class"></div>
                </div>
                <div class="form-group"><label>Depreciation Method *</label>
                    <select class="form-select form-control-sm" x-model="capitalizeForm.asset_data.depreciation_method"
                            :class="capitalizeErrors.depreciation_method ? 'is-invalid' : ''" @change="capitalizeErrors.depreciation_method = ''">
                        <option value="straight_line">Straight Line</option>
                        <option value="declining_balance">Declining Balance (CRA CCA)</option>
                    </select>
                    <div class="field-error" x-show="capitalizeErrors.depreciation_method" x-cloak x-text="capitalizeErrors.depreciation_method"></div>
                </div>
                <div class="form-group" x-show="capitalizeForm.asset_data.depreciation_method === 'straight_line'">
                    <label>Useful Life (years) *</label>
                    <input type="number" step="0.5" min="0.5" class="form-control form-control-sm" x-model="capitalizeForm.asset_data.useful_life_years"
                           :class="capitalizeErrors.useful_life_years ? 'is-invalid' : ''" @input="capitalizeErrors.useful_life_years = ''">
                    <div class="field-error" x-show="capitalizeErrors.useful_life_years" x-cloak x-text="capitalizeErrors.useful_life_years"></div>
                </div>
                <div class="form-group" x-show="capitalizeForm.asset_data.depreciation_method === 'declining_balance'">
                    <label>CRA CCA Rate *</label>
                    <input type="number" step="0.01" min="0.01" max="1" class="form-control form-control-sm" x-model="capitalizeForm.asset_data.cra_cca_rate"
                           :class="capitalizeErrors.cra_cca_rate ? 'is-invalid' : ''" @input="capitalizeErrors.cra_cca_rate = ''"
                           placeholder="0.30 = 30%">
                    <div class="field-error" x-show="capitalizeErrors.cra_cca_rate" x-cloak x-text="capitalizeErrors.cra_cca_rate"></div>
                </div>
                <div class="form-group"><label>Salvage Value ($)</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="capitalizeForm.asset_data.salvage_value"
                           :class="capitalizeErrors.salvage_value ? 'is-invalid' : ''" @input="capitalizeErrors.salvage_value = ''">
                    <div class="field-error" x-show="capitalizeErrors.salvage_value" x-cloak x-text="capitalizeErrors.salvage_value"></div>
                </div>

                <h3 class="h6" style="margin-top:14px;">GL Accounts</h3>
                <?php
                $pickerId       = 'capex_cap_asset_account_id';
                $pickerLabel    = 'Asset Account';
                $pickerRequired = true;
                $pickerPlaceholder = 'Search code or name…';
                $pickerLabelHint = 'e.g. code 1210 — Fleet Equipment';
                $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'capitalizeForm.asset_data.asset_account_id'
}
JS;
                include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                ?>
                <?php
                $pickerId       = 'capex_cap_accum_depr_account_id';
                $pickerLabel    = 'Accumulated Depreciation Account';
                $pickerLabelHint = 'e.g. code 1220 — Accumulated Depr';
                $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'capitalizeForm.asset_data.accum_depr_account_id'
}
JS;
                include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                ?>
                <?php
                $pickerId       = 'capex_cap_depr_expense_account_id';
                $pickerLabel    = 'Depreciation Expense Account';
                $pickerLabelHint = 'e.g. code 5010 — Depreciation Expense';
                $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'capitalizeForm.asset_data.depr_expense_account_id'
}
JS;
                include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                ?>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="capitalizeOpen = false">Cancel</button>
                <button class="btn btn-success btn-sm" @click="submitCapitalize()" :disabled="capitalizeBusy">
                    <span x-show="!capitalizeBusy">Capitalize + Create Asset</span><span x-show="capitalizeBusy">Saving…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         EXPENSE FLAGGED WO MODAL
         Marks the WO as expensed (no asset, audit trail only).
         ============================================================ -->
    <div class="modal-backdrop" x-show="expenseOpen" x-transition @click.self="expenseOpen = false" style="display:none;">
        <div class="modal" style="max-width:520px;width:95%;">
            <div class="modal-header"><h2 class="h5">Expense Work Order</h2><button class="modal-close-btn" aria-label="Close" @click="expenseOpen = false">×</button></div>
            <div class="modal-body">
                <div class="form-error-banner" x-show="expenseFormError" x-cloak x-text="expenseFormError"></div>
                <p class="text-secondary text-sm" x-show="expenseWO">
                    Expensing <strong class="font-mono" x-text="expenseWO?.work_order_number"></strong>
                    — <span x-text="expenseWO?.title"></span>
                    (<span class="font-mono" x-text="formatMoney(expenseWO?.total_cost)"></span>)
                </p>
                <p class="text-info text-sm">This logs the decision to NOT capitalize this work order. No new asset, no JE — the spend stays in 6190 Repairs. The WO will disappear from the flagged list.</p>
                <div class="form-group"><label>Reason *</label>
                    <textarea class="form-control form-control-sm" rows="3" x-model="expenseForm.reason"
                              :class="expenseErrors.reason ? 'is-invalid' : ''" @input="expenseErrors.reason = ''"
                              placeholder="e.g. Repair to existing asset, no useful life extension"></textarea>
                    <div class="field-error" x-show="expenseErrors.reason" x-cloak x-text="expenseErrors.reason"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="expenseOpen = false">Cancel</button>
                <button class="btn btn-warning btn-sm" @click="submitExpense()" :disabled="expenseBusy">
                    <span x-show="!expenseBusy">Mark as Expense</span><span x-show="expenseBusy">Saving…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         COMPLETE MODAL — auto-create linked asset
         ============================================================ -->
    <div class="modal-backdrop" x-show="completeOpen" x-transition @click.self="completeOpen = false" style="display:none;">
        <div class="modal" style="max-width:680px;width:95%;max-height:85vh;overflow-y:auto;">
            <div class="modal-header"><h2 class="h5">Complete CapEx + Create Asset</h2><button class="modal-close-btn" aria-label="Close" @click="completeOpen = false">×</button></div>
            <div class="modal-body">
                <div class="form-error-banner" x-show="completeFormError" x-cloak x-text="completeFormError"></div>
                <p class="text-secondary text-sm" x-show="detailReq" x-text="'Completing ' + (detailReq?.request_number ?? '') + ' — ' + (detailReq?.title ?? '')"></p>

                <h3 class="h6" style="margin-top:8px;">Actuals</h3>
                <div class="form-group"><label>Actual Amount ($) *</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="completeForm.actual_amount"
                           :class="completeErrors.actual_amount ? 'is-invalid' : ''" @input="completeErrors.actual_amount = ''">
                    <div class="field-error" x-show="completeErrors.actual_amount" x-cloak x-text="completeErrors.actual_amount"></div>
                </div>

                <h3 class="h6" style="margin-top:16px;">New Asset Details</h3>
                <div class="form-group"><label>Asset Name *</label>
                    <input type="text" class="form-control form-control-sm" x-model="completeForm.asset_data.name"
                           :class="completeErrors.name ? 'is-invalid' : ''" @input="completeErrors.name = ''">
                    <div class="field-error" x-show="completeErrors.name" x-cloak x-text="completeErrors.name"></div>
                </div>
                <div class="form-group"><label>Acquisition Date *</label>
                    <input type="date" class="form-control form-control-sm" x-model="completeForm.asset_data.acquisition_date"
                           :class="completeErrors.acquisition_date ? 'is-invalid' : ''" @input="completeErrors.acquisition_date = ''">
                    <div class="field-error" x-show="completeErrors.acquisition_date" x-cloak x-text="completeErrors.acquisition_date"></div>
                </div>
                <div class="form-group"><label>Acquisition Cost ($) *</label>
                    <input type="number" step="0.01" min="0.01" class="form-control form-control-sm" x-model="completeForm.asset_data.acquisition_cost"
                           :class="completeErrors.acquisition_cost ? 'is-invalid' : ''" @input="completeErrors.acquisition_cost = ''">
                    <div class="field-error" x-show="completeErrors.acquisition_cost" x-cloak x-text="completeErrors.acquisition_cost"></div>
                </div>
                <div class="form-group"><label>Salvage Value ($)</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="completeForm.asset_data.salvage_value"
                           :class="completeErrors.salvage_value ? 'is-invalid' : ''" @input="completeErrors.salvage_value = ''">
                    <div class="field-error" x-show="completeErrors.salvage_value" x-cloak x-text="completeErrors.salvage_value"></div>
                </div>
                <div class="form-group"><label>Depreciation Method *</label>
                    <select class="form-select form-control-sm" x-model="completeForm.asset_data.depreciation_method"
                            :class="completeErrors.depreciation_method ? 'is-invalid' : ''" @change="completeErrors.depreciation_method = ''">
                        <option value="straight_line">Straight Line</option>
                        <option value="declining_balance">Declining Balance (CRA CCA)</option>
                        <option value="units_of_production">Units of Production</option>
                    </select>
                    <div class="field-error" x-show="completeErrors.depreciation_method" x-cloak x-text="completeErrors.depreciation_method"></div>
                </div>
                <div class="form-group" x-show="completeForm.asset_data.depreciation_method === 'straight_line'">
                    <label>Useful Life (years) *</label>
                    <input type="number" step="0.5" min="0.5" class="form-control form-control-sm" x-model="completeForm.asset_data.useful_life_years"
                           :class="completeErrors.useful_life_years ? 'is-invalid' : ''" @input="completeErrors.useful_life_years = ''">
                    <div class="field-error" x-show="completeErrors.useful_life_years" x-cloak x-text="completeErrors.useful_life_years"></div>
                </div>
                <div class="form-group" x-show="completeForm.asset_data.depreciation_method === 'declining_balance'">
                    <label>CRA CCA Rate *</label>
                    <input type="number" step="0.01" min="0.01" max="1" class="form-control form-control-sm" x-model="completeForm.asset_data.cra_cca_rate"
                           :class="completeErrors.cra_cca_rate ? 'is-invalid' : ''" @input="completeErrors.cra_cca_rate = ''"
                           placeholder="0.30 = 30%">
                    <div class="field-error" x-show="completeErrors.cra_cca_rate" x-cloak x-text="completeErrors.cra_cca_rate"></div>
                </div>
                <div class="form-group" x-show="completeForm.asset_data.depreciation_method === 'units_of_production'">
                    <label>Total Expected Units *</label>
                    <input type="number" step="1" min="1" class="form-control form-control-sm" x-model="completeForm.asset_data.total_expected_units"
                           :class="completeErrors.total_expected_units ? 'is-invalid' : ''" @input="completeErrors.total_expected_units = ''">
                    <div class="field-error" x-show="completeErrors.total_expected_units" x-cloak x-text="completeErrors.total_expected_units"></div>
                </div>
                <?php
                $pickerId       = 'capex_done_asset_account_id';
                $pickerLabel    = 'Asset Account';
                $pickerRequired = true;
                $pickerPlaceholder = 'Search code or name…';
                $pickerLabelHint = '';
                $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'completeForm.asset_data.asset_account_id'
}
JS;
                include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                ?>
                <?php
                $pickerId       = 'capex_done_accum_depr_account_id';
                $pickerLabel    = 'Accum Depr Account';
                $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'completeForm.asset_data.accum_depr_account_id'
}
JS;
                include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                ?>
                <?php
                $pickerId       = 'capex_done_depr_expense_account_id';
                $pickerLabel    = 'Depreciation Expense Account';
                $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'completeForm.asset_data.depr_expense_account_id'
}
JS;
                include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                ?>
                <div class="form-group"><label>Location</label>
                    <input type="text" class="form-control form-control-sm" x-model="completeForm.asset_data.location"
                           :class="completeErrors.location ? 'is-invalid' : ''" @input="completeErrors.location = ''">
                    <div class="field-error" x-show="completeErrors.location" x-cloak x-text="completeErrors.location"></div>
                </div>
                <div class="form-group"><label>Serial Number</label>
                    <input type="text" class="form-control form-control-sm" x-model="completeForm.asset_data.serial_number"
                           :class="completeErrors.serial_number ? 'is-invalid' : ''" @input="completeErrors.serial_number = ''">
                    <div class="field-error" x-show="completeErrors.serial_number" x-cloak x-text="completeErrors.serial_number"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="completeOpen = false">Cancel</button>
                <button class="btn btn-success btn-sm" @click="submitComplete()" :disabled="completeBusy">
                    <span x-show="!completeBusy">Complete + Create Asset</span><span x-show="completeBusy">Saving…</span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_Capex() {
    return {
        // ── State ──────────────────────────────────────────────
        requests: [],
        loading: true,
        search: '',
        filterStatus: '',
        filterClass: '',
        pagination: { total: 0, page: 1, per_page: 25, total_pages: 0, has_more: false },

        // ── Detail ─────────────────────────────────────────────
        detailOpen: false,
        detailReq: null,

        // ── Create ─────────────────────────────────────────────
        createOpen: false,
        createBusy: false,
        createForm: {},
        createFormError: '',
        createErrors: { title: '', asset_class: '', budget_amount: '', description: '', justification: '' },

        // ── Complete ───────────────────────────────────────────
        completeOpen: false,
        completeBusy: false,
        completeForm: {},
        completeFormError: '',
        completeErrors: {
            actual_amount: '', name: '', acquisition_date: '', acquisition_cost: '', salvage_value: '',
            depreciation_method: '', useful_life_years: '', cra_cca_rate: '', total_expected_units: '',
            asset_account_id: '', accum_depr_account_id: '', depr_expense_account_id: '', location: '', serial_number: ''
        },

        // ── Flagged work orders ────────────────────────────────
        flaggedWorkOrders: [],
        flaggedThreshold: '2500.00',
        flaggedLoading: false,

        // ── Capitalize / Expense modals ────────────────────────
        capitalizeOpen: false,
        capitalizeBusy: false,
        capitalizeWO: null,
        capitalizeForm: { asset_data: {} },
        capitalizeFormError: '',
        capitalizeErrors: {
            name: '', asset_class: '', depreciation_method: '', useful_life_years: '', cra_cca_rate: '',
            salvage_value: '', asset_account_id: '', accum_depr_account_id: '', depr_expense_account_id: ''
        },
        expenseOpen: false,
        expenseBusy: false,
        expenseWO: null,
        expenseForm: { reason: '' },
        expenseFormError: '',
        expenseErrors: { reason: '' },

        // ── VALID-2: Error helpers ──────────────────────────────
        _extractError(r, fallback) {
            if (r && r.error) {
                if (typeof r.error === 'string') return r.error;
                if (r.error.message) return r.error.message;
            }
            if (r && r.message) return r.message;
            return fallback || 'Something went wrong.';
        },
        _clearErrors(errorsObj, bannerKey) {
            this[bannerKey] = '';
            for (const k in errorsObj) {
                if (Object.prototype.hasOwnProperty.call(errorsObj, k)) errorsObj[k] = '';
            }
        },
        _paintErrors(errorsObj, bannerKey, r, fallback) {
            const fields = (r && r.error && r.error.fields) || (r && r.fields) || null;
            if (fields && typeof fields === 'object') {
                let any = false;
                for (const k in fields) {
                    if (!Object.prototype.hasOwnProperty.call(fields, k)) continue;
                    const bare = k.replace(/^asset_data\./, '');
                    if (Object.prototype.hasOwnProperty.call(errorsObj, bare)) {
                        errorsObj[bare] = fields[k];
                        any = true;
                    } else if (k === '_general' || k === '') {
                        this[bannerKey] = fields[k];
                        any = true;
                    }
                }
                if (!any) this[bannerKey] = this._extractError(r, fallback);
            } else {
                this[bannerKey] = this._extractError(r, fallback);
            }
        },

        // ── VALID-2: Client validators ──────────────────────────
        validateCreate() {
            this._clearErrors(this.createErrors, 'createFormError');
            let ok = true;
            const f = this.createForm;
            if (!f.title || !f.title.trim()) {
                this.createErrors.title = 'Title is required.';
                ok = false;
            }
            const classes = ['fleet_equipment','vehicles','office_equipment','leasehold_improvements','land','building','other'];
            if (!f.asset_class) {
                this.createErrors.asset_class = 'Please select an asset class.';
                ok = false;
            } else if (!classes.includes(f.asset_class)) {
                this.createErrors.asset_class = 'Please select a valid asset class.';
                ok = false;
            }
            const amt = String(f.budget_amount || '').trim();
            if (!amt) {
                this.createErrors.budget_amount = 'Budget amount is required.';
                ok = false;
            } else if (!/^\d+(\.\d{1,2})?$/.test(amt)) {
                this.createErrors.budget_amount = 'Budget amount must be a valid amount.';
                ok = false;
            } else if (parseFloat(amt) <= 0) {
                this.createErrors.budget_amount = 'Budget amount must be greater than zero.';
                ok = false;
            }
            return ok;
        },
        validateAssetData(errorsObj, data) {
            let ok = true;
            if (!data.name || !data.name.trim()) {
                errorsObj.name = 'Asset name is required.';
                ok = false;
            }
            const methods = ['straight_line','declining_balance','double_declining','sum_of_years','units_of_production'];
            if (!data.depreciation_method) {
                errorsObj.depreciation_method = 'Please select a depreciation method.';
                ok = false;
            } else if (!methods.includes(data.depreciation_method)) {
                errorsObj.depreciation_method = 'Please select a valid depreciation method.';
                ok = false;
            }
            if (data.depreciation_method === 'straight_line' || data.depreciation_method === 'sum_of_years') {
                const years = String(data.useful_life_years || '').trim();
                if (!years) {
                    errorsObj.useful_life_years = 'Useful life is required.';
                    ok = false;
                } else if (!/^\d+(\.\d{1,2})?$/.test(years) || parseFloat(years) <= 0) {
                    errorsObj.useful_life_years = 'Useful life must be greater than zero.';
                    ok = false;
                }
            }
            if (data.depreciation_method === 'declining_balance' || data.depreciation_method === 'double_declining') {
                const rate = String(data.cra_cca_rate || '').trim();
                if (!rate) {
                    errorsObj.cra_cca_rate = 'CCA rate is required for declining balance.';
                    ok = false;
                } else if (!/^\d+(\.\d{1,4})?$/.test(rate) || parseFloat(rate) <= 0 || parseFloat(rate) > 1) {
                    errorsObj.cra_cca_rate = 'CCA rate must be between 0 and 1 (e.g. 0.30 = 30%).';
                    ok = false;
                }
            }
            if (data.depreciation_method === 'units_of_production') {
                const units = String(data.total_expected_units || '').trim();
                if (!units) {
                    errorsObj.total_expected_units = 'Total expected units is required.';
                    ok = false;
                } else if (!/^\d+$/.test(units) || parseInt(units, 10) <= 0) {
                    errorsObj.total_expected_units = 'Total expected units must be a positive integer.';
                    ok = false;
                }
            }
            const salvage = String(data.salvage_value || '').trim();
            if (salvage !== '' && (!/^\d+(\.\d{1,2})?$/.test(salvage) || parseFloat(salvage) < 0)) {
                errorsObj.salvage_value = 'Salvage value cannot be negative.';
                ok = false;
            }
            if (!data.asset_account_id || !String(data.asset_account_id).trim()) {
                errorsObj.asset_account_id = 'Asset account is required.';
                ok = false;
            } else if (!/^\d+$/.test(String(data.asset_account_id).trim())) {
                errorsObj.asset_account_id = 'Asset account must be a valid ID.';
                ok = false;
            }
            if (!data.accum_depr_account_id || !String(data.accum_depr_account_id).trim()) {
                errorsObj.accum_depr_account_id = 'Accumulated depreciation account is required.';
                ok = false;
            } else if (!/^\d+$/.test(String(data.accum_depr_account_id).trim())) {
                errorsObj.accum_depr_account_id = 'Accumulated depreciation account must be a valid ID.';
                ok = false;
            }
            if (!data.depr_expense_account_id || !String(data.depr_expense_account_id).trim()) {
                errorsObj.depr_expense_account_id = 'Depreciation expense account is required.';
                ok = false;
            } else if (!/^\d+$/.test(String(data.depr_expense_account_id).trim())) {
                errorsObj.depr_expense_account_id = 'Depreciation expense account must be a valid ID.';
                ok = false;
            }
            return ok;
        },
        validateCapitalize() {
            this._clearErrors(this.capitalizeErrors, 'capitalizeFormError');
            return this.validateAssetData(this.capitalizeErrors, this.capitalizeForm.asset_data);
        },
        validateExpense() {
            this._clearErrors(this.expenseErrors, 'expenseFormError');
            let ok = true;
            if (!this.expenseForm.reason || !this.expenseForm.reason.trim()) {
                this.expenseErrors.reason = 'Reason is required.';
                ok = false;
            }
            return ok;
        },
        validateComplete() {
            this._clearErrors(this.completeErrors, 'completeFormError');
            let ok = true;
            const amt = String(this.completeForm.actual_amount || '').trim();
            if (!amt) {
                this.completeErrors.actual_amount = 'Actual amount is required.';
                ok = false;
            } else if (!/^\d+(\.\d{1,2})?$/.test(amt)) {
                this.completeErrors.actual_amount = 'Actual amount must be a valid amount.';
                ok = false;
            } else if (parseFloat(amt) < 0) {
                this.completeErrors.actual_amount = 'Actual amount cannot be negative.';
                ok = false;
            }
            const ad = this.completeForm.asset_data || {};
            if (!ad.acquisition_date) {
                this.completeErrors.acquisition_date = 'Acquisition date is required.';
                ok = false;
            }
            const cost = String(ad.acquisition_cost || '').trim();
            if (!cost) {
                this.completeErrors.acquisition_cost = 'Acquisition cost is required.';
                ok = false;
            } else if (!/^\d+(\.\d{1,2})?$/.test(cost)) {
                this.completeErrors.acquisition_cost = 'Acquisition cost must be a valid amount.';
                ok = false;
            } else if (parseFloat(cost) <= 0) {
                this.completeErrors.acquisition_cost = 'Acquisition cost must be greater than zero.';
                ok = false;
            }
            if (!this.validateAssetData(this.completeErrors, ad)) ok = false;
            return ok;
        },

        // ── Init ───────────────────────────────────────────────
        async init() {
            this.resetCreateForm();
            await Promise.all([this.load(), this.loadFlagged()]);
        },

        resetCreateForm() {
            this.createForm = { title: '', asset_class: 'fleet_equipment', budget_amount: '', description: '', justification: '' };
        },

        resetCompleteForm() {
            this.completeForm = {
                actual_amount: '',
                asset_data: {
                    name: '',
                    acquisition_date: new Date().toISOString().slice(0, 10),
                    acquisition_cost: '',
                    salvage_value: '0.00',
                    depreciation_method: 'straight_line',
                    useful_life_years: '',
                    cra_cca_rate: '',
                    total_expected_units: '',
                    asset_account_id: '',
                    accum_depr_account_id: '',
                    depr_expense_account_id: '',
                    location: '',
                    serial_number: '',
                },
            };
        },

        // ── Load ───────────────────────────────────────────────
        async load() {
            this.loading = true;
            const params = new URLSearchParams();
            if (this.search) params.set('search', this.search);
            if (this.filterStatus) params.set('status', this.filterStatus);
            if (this.filterClass) params.set('asset_class', this.filterClass);
            params.set('page', String(this.pagination.page));
            params.set('per_page', String(this.pagination.per_page));
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/capex/index.php') ?>?' + params.toString());
                if (r.success) {
                    this.requests = r.data.items;
                    this.pagination = r.data.pagination;
                } else {
                    FF_Toast.error(r.error?.message || 'Failed to load requests.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
            this.loading = false;
        },

        goPage(p) {
            this.pagination.page = p;
            this.load();
        },

        // ── Detail ─────────────────────────────────────────────
        openDetail(r) {
            this.detailReq = r;
            this.detailOpen = true;
        },

        // ── Workflow actions ───────────────────────────────────
        async approveRequest() {
            if (!this.detailReq) return;
            if (!(await FF_Confirm.ask('Approve CapEx request ' + this.detailReq.request_number + '?'))) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/capex/approve.php') ?>', { id: this.detailReq.id });
                if (r.success) {
                    FF_Toast.success('Request approved.');
                    this.detailOpen = false;
                    await this.load();
                } else {
                    FF_Toast.error(r.error?.message || 'Approve failed.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
        },

        async startRequest() {
            if (!this.detailReq) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/capex/start.php') ?>', { id: this.detailReq.id });
                if (r.success) {
                    FF_Toast.success('Marked in progress.');
                    this.detailOpen = false;
                    await this.load();
                } else {
                    FF_Toast.error(r.error?.message || 'Start failed.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
        },

        openComplete() {
            this.resetCompleteForm();
            this._clearErrors(this.completeErrors, 'completeFormError');
            // Pre-fill from request
            if (this.detailReq) {
                this.completeForm.actual_amount    = this.detailReq.budget_amount;
                this.completeForm.asset_data.name  = this.detailReq.title;
                this.completeForm.asset_data.acquisition_cost = this.detailReq.budget_amount;
            }
            this.completeOpen = true;
        },

        async submitComplete() {
            if (!this.detailReq) return;
            if (!this.validateComplete()) return;
            this.completeBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/capex/complete.php') ?>', {
                    id: this.detailReq.id,
                    actual_amount: this.completeForm.actual_amount,
                    asset_data: this.completeForm.asset_data,
                });
                if (r.success) {
                    FF_Toast.success('CapEx completed. Asset created.');
                    this.completeOpen = false;
                    this.detailOpen = false;
                    await this.load();
                } else {
                    this._paintErrors(this.completeErrors, 'completeFormError', r, 'Failed to complete CapEx.');
                }
            } catch (e) { this.completeFormError = 'Network error. Please try again.'; }
            this.completeBusy = false;
        },

        // ── Flagged work orders ────────────────────────────────
        async loadFlagged() {
            this.flaggedLoading = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/capex/flags.php') ?>');
                if (r.success) {
                    this.flaggedWorkOrders = r.data.rows || [];
                    this.flaggedThreshold  = r.data.threshold || '2500.00';
                }
            } catch (e) { /* non-fatal */ }
            this.flaggedLoading = false;
        },

        openCapitalize(wo) {
            this.capitalizeWO   = wo;
            this.capitalizeForm = {
                asset_data: {
                    name:                    wo.title || ('WO ' + wo.work_order_number),
                    asset_class:             'fleet_equipment',
                    depreciation_method:     'straight_line',
                    useful_life_years:       '5',
                    cra_cca_rate:            '',
                    salvage_value:           '0.00',
                    asset_account_id:        '',
                    accum_depr_account_id:   '',
                    depr_expense_account_id: '',
                },
            };
            this._clearErrors(this.capitalizeErrors, 'capitalizeFormError');
            this.capitalizeOpen = true;
        },

        async submitCapitalize() {
            if (!this.capitalizeWO) return;
            if (!this.validateCapitalize()) return;
            this.capitalizeBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/capex/capitalize.php') ?>', {
                    work_order_id: this.capitalizeWO.id,
                    asset_data:    this.capitalizeForm.asset_data,
                });
                if (r.success) {
                    FF_Toast.success('Capitalized as ' + r.data.asset.asset_number);
                    this.capitalizeOpen = false;
                    await Promise.all([this.load(), this.loadFlagged()]);
                } else {
                    this._paintErrors(this.capitalizeErrors, 'capitalizeFormError', r, 'Failed to capitalize work order.');
                }
            } catch (e) { this.capitalizeFormError = 'Network error. Please try again.'; }
            this.capitalizeBusy = false;
        },

        openExpense(wo) {
            this.expenseWO   = wo;
            this.expenseForm = { reason: '' };
            this._clearErrors(this.expenseErrors, 'expenseFormError');
            this.expenseOpen = true;
        },

        async submitExpense() {
            if (!this.expenseWO) return;
            if (!this.validateExpense()) return;
            this.expenseBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/capex/expense.php') ?>', {
                    work_order_id: this.expenseWO.id,
                    reason:        this.expenseForm.reason,
                });
                if (r.success) {
                    FF_Toast.success('Marked as expense.');
                    this.expenseOpen = false;
                    await Promise.all([this.load(), this.loadFlagged()]);
                } else {
                    this._paintErrors(this.expenseErrors, 'expenseFormError', r, 'Failed to mark as expense.');
                }
            } catch (e) { this.expenseFormError = 'Network error. Please try again.'; }
            this.expenseBusy = false;
        },

        // ── Create ─────────────────────────────────────────────
        openCreate() {
            this.resetCreateForm();
            this._clearErrors(this.createErrors, 'createFormError');
            this.createOpen = true;
        },
        async submitCreate() {
            if (!this.validateCreate()) return;
            this.createBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/capex/create.php') ?>', this.createForm);
                if (r.success) {
                    FF_Toast.success('Request submitted: ' + r.data.request_number);
                    this.createOpen = false;
                    await this.load();
                } else {
                    this._paintErrors(this.createErrors, 'createFormError', r, 'Failed to create CapEx request.');
                }
            } catch (e) { this.createFormError = 'Network error. Please try again.'; }
            this.createBusy = false;
        },

        // ── Display helpers ────────────────────────────────────
        formatMoney(s) {
            if (s === null || s === undefined) return '—';
            return '$' + parseFloat(s).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        formatClass(c) {
            const m = {
                fleet_equipment:'Fleet Equipment', vehicles:'Vehicles', office_equipment:'Office Equipment',
                leasehold_improvements:'Leasehold Improvements', land:'Land', building:'Building', other:'Other'
            };
            return m[c] || c;
        },
        formatStatus(s) {
            const m = {
                draft:'Draft', pending_approval:'Pending Approval', approved:'Approved',
                rejected:'Rejected', in_progress:'In Progress', completed:'Completed', cancelled:'Cancelled'
            };
            return m[s] || s;
        },
        statusBadge(s) {
            const m = {
                draft:'badge-neutral', pending_approval:'badge-amber', approved:'badge-blue',
                rejected:'badge-red', in_progress:'badge-blue', completed:'badge-green', cancelled:'badge-neutral'
            };
            return m[s] || 'badge-neutral';
        },
        varianceClass(r) {
            if (!r.actual_amount) return '';
            const v = parseFloat(r.variance);
            if (v >= 0) return 'text-success';   // under budget
            return 'text-danger';                // over budget
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
