<?php declare(strict_types=1);

/**
 * FleetForge — Journal Entries
 *
 * @file        app/admin/accounting/journal-entries/index.php
 * @description Journal entry list with KPI tiles (Total, Drafts, Posted, Today),
 *              tab bar (Draft / Posted / Reversed / All), filter toolbar (search,
 *              entry type, date range, sort, direction), paginated data table with
 *              status + type badges, view detail modal showing entry lines, and
 *              create journal entry modal with dynamic debit/credit line rows,
 *              auto-clear opposing side, and live balance indicator.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/journal_entries,
 *              api/v1/accounting/accounts
 */

// dirname(__DIR__, 4): journal-entries/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

// -- KPI tiles -- server-side for accuracy on initial load ----------------------
$totalJE   = db_count("SELECT COUNT(*) FROM acc_journal_entries");
$draftJE   = db_count("SELECT COUNT(*) FROM acc_journal_entries WHERE status = 'draft'");
$postedJE  = db_count("SELECT COUNT(*) FROM acc_journal_entries WHERE status = 'posted'");
$pendingJE = db_count("SELECT COUNT(*) FROM acc_journal_entries WHERE entry_status = 'submitted'");

// S-ACCT-AJE workflow context — exposed to Alpine for per-row action gating.
$ajeReviewRequired = ((string) settings_get('accounting.aje_review_required', '0')) === '1';
$currentRole       = current_user()['role_slug'] ?? '';
$currentUserId     = (int) (current_user_id() ?? 0);
$canApprove        = in_array($currentRole, ['super_admin', 'manager', 'accountant'], true);

$pageTitle = 'Journal Entries';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb + Page Header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Journal Entries</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Journal Entries</h1>
    <div class="page-header-actions">
        <?php if (can('journal_entries', 'create')): ?>
        <button class="btn btn-primary btn-sm"
                @click="$dispatch('open-je-create')">
            + New Journal Entry
        </button>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ============================================================
     KPI Tiles
     ============================================================ -->
<!-- TILES-1: KPI tiles dispatch `ff-je-filter` to set the JE tab -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--blue" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-je-filter',{detail:{tab:'all'}}))">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Total Entries</div>
        <div class="stat-value font-mono"><?= e((string) $totalJE) ?></div>
    </div>
    <div class="stat-card stat-card--amber" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-je-filter',{detail:{tab:'draft'}}))">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-pencil-square"/></svg></span>
        <div class="stat-label">Drafts</div>
        <div class="stat-value font-mono"><?= e((string) $draftJE) ?></div>
    </div>
    <div class="stat-card stat-card--green" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-je-filter',{detail:{tab:'posted'}}))">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Posted</div>
        <div class="stat-value font-mono"><?= e((string) $postedJE) ?></div>
    </div>
    <div class="stat-card stat-card--orange" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-je-filter',{detail:{tab:'pending'}}))"
         title="AJE entries awaiting approval">
        <span class="stat-icon stat-icon--orange"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Pending Review</div>
        <div class="stat-value font-mono"><?= e((string) $pendingJE) ?></div>
    </div>
</div>

<!-- ============================================================
     JOURNAL ENTRIES -- ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_JournalEntries()" x-init="init()"
     @open-je-create.window="openCreate()"
     @ff-je-filter.window="
        if ($event.detail.date) { filters.date_from = $event.detail.date; filters.date_to = $event.detail.date; }
        else { filters.date_from = ''; filters.date_to = ''; }
        if (typeof setTab === 'function') setTab($event.detail.tab);
        else { activeTab = $event.detail.tab; load(); }
     ">

    <!-- -- TAB BAR ------------------------------------------------ -->
    <!-- S-ACCT-AJE: "Drafts & Pending" filters entry_status IN ('draft','submitted') -
         the AJE workflow queue. "Draft" still filters status='draft' for all
         non-AJE drafts and legacy entries. -->
    <div class="tab-bar" role="tablist">
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'pending' }"
                @click="setTab('pending')" :aria-selected="activeTab === 'pending'" role="tab"
                title="AJE workflow: draft + submitted entries">
            Drafts &amp; Pending
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'draft' }"
                @click="setTab('draft')" :aria-selected="activeTab === 'draft'" role="tab">
            Draft
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'posted' }"
                @click="setTab('posted')" :aria-selected="activeTab === 'posted'" role="tab">
            Posted
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'reversed' }"
                @click="setTab('reversed')" :aria-selected="activeTab === 'reversed'" role="tab">
            Reversed
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'all' }"
                @click="setTab('all')" :aria-selected="activeTab === 'all'" role="tab">
            All
        </button>
    </div>

    <!-- -- FILTER TOOLBAR ----------------------------------------- -->
    <div class="table-toolbar">

        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search entry #, description, reference..."
                   x-model="filters.search"
                   @input.debounce.400ms="resetPage()"
                   maxlength="255"
                   style="min-width:240px;"
                   aria-label="Search journal entries">

            <select class="form-select form-control-sm"
                    x-model="filters.type"
                    @change="resetPage()"
                    aria-label="Filter by entry type">
                <option value="">All Types</option>
                <option value="manual">Manual</option>
                <option value="system">System</option>
                <option value="recurring">Recurring</option>
                <option value="reversing">Reversing</option>
                <option value="year_end">Year-End</option>
                <option value="adjusting">Adjusting</option>
                <option value="reclassifying">Reclassifying</option>
                <option value="prior_period">Prior Period</option>
                <option value="closing">Closing</option>
            </select>

            <input type="date"
                   class="form-control form-control-sm"
                   x-model="filters.date_from"
                   @change="resetPage()"
                   aria-label="Date from"
                   title="From date">

            <input type="date"
                   class="form-control form-control-sm"
                   x-model="filters.date_to"
                   @change="resetPage()"
                   aria-label="Date to"
                   title="To date">
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="pagination.total !== undefined
                      ? pagination.total + ' entr' + (pagination.total !== 1 ? 'ies' : 'y')
                      : ''">
            </span>

            <select class="form-select form-control-sm"
                    x-model="filters.sort"
                    @change="resetPage()"
                    aria-label="Sort by">
                <option value="entry_date">Entry Date</option>
                <option value="entry_number">Entry #</option>
                <option value="created_at">Created</option>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.dir"
                    @change="resetPage()"
                    aria-label="Direction">
                <option value="DESC">&darr; Desc</option>
                <option value="ASC">&uarr; Asc</option>
            </select>
        </div>

    </div>

    <!-- -- TABLE CARD --------------------------------------------- -->
    <div class="card">

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div aria-busy="true" aria-label="Loading journal entries...">
                <template x-for="n in 6" :key="n">
                    <div class="skeleton skeleton-row"></div>
                </template>
            </div>
        </template>

        <!-- Error state -->
        <template x-if="!loading && loadError">
            <div class="empty-state">
                <p class="empty-state-title">Failed to load journal entries</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="loadEntries()">Retry</button>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && !loadError && entries.length === 0">
            <div class="empty-state">
                <p class="empty-state-title">No journal entries found</p>
                <p class="empty-state-text"
                   x-text="hasActiveFilters() ? 'Try adjusting your filters.' : 'Create your first journal entry to get started.'">
                </p>
            </div>
        </template>

        <!-- Data table -->
        <template x-if="!loading && !loadError && entries.length > 0">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Journal Entries">
                    <thead>
                        <tr>
                            <th scope="col">Entry #</th>
                            <th scope="col">Date</th>
                            <th scope="col">Type</th>
                            <th scope="col">Description</th>
                            <th scope="col" class="text-right">Debit Total</th>
                            <th scope="col" class="text-right">Credit Total</th>
                            <th scope="col">Status</th>
                            <th scope="col" style="width:1%;white-space:nowrap;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="je in entries" :key="je.id">
                            <tr>
                                <td>
                                    <button class="font-mono font-medium link"
                                            style="background:none;border:none;padding:0;cursor:pointer;color:var(--color-primary);"
                                            @click="viewEntryDetail(je.id)"
                                            x-text="je.entry_number"></button>
                                </td>
                                <td class="text-sm" x-text="formatDate(je.entry_date)"></td>
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="typeBadge(je.entry_type)"
                                          x-text="capitalize(je.entry_type)"></span>
                                </td>
                                <td class="text-sm"
                                    style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                    x-text="je.description || '—'"></td>
                                <td class="font-mono text-sm text-right"
                                    x-text="'$' + formatMoney(je.debit_total)"></td>
                                <td class="font-mono text-sm text-right"
                                    x-text="'$' + formatMoney(je.credit_total)"></td>
                                <td>
                                    <!-- Workflow badge: shows entry_status when distinct from posted/reversed terminal state. -->
                                    <span class="badge badge-no-dot"
                                          :class="workflowBadge(je)"
                                          x-text="workflowLabel(je)"></span>
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <button class="btn btn-ghost btn-xs"
                                            @click="viewEntryDetail(je.id)"
                                            title="View details">View</button>
                                    <?php if (can('journal_entries', 'edit')): ?>
                                    <!-- AJE draft, review required -> Submit for Review.
                                         AJE draft, review off OR non-AJE draft -> Post.
                                         AJE submitted -> Approve & Post (role-gated) + Recall (submitter/super_admin).
                                         Posted -> Reverse. -->
                                    <template x-if="canSubmit(je)">
                                        <button class="btn btn-ghost btn-xs"
                                                @click="submitEntry(je.id)"
                                                title="Submit for review">Submit for Review</button>
                                    </template>
                                    <template x-if="canPost(je)">
                                        <button class="btn btn-ghost btn-xs"
                                                @click="postEntry(je.id)"
                                                title="Post entry">Post</button>
                                    </template>
                                    <template x-if="canApproveRow(je)">
                                        <button class="btn btn-primary btn-xs"
                                                @click="approveEntry(je.id)"
                                                title="Approve and post">Approve &amp; Post</button>
                                    </template>
                                    <template x-if="canRecall(je)">
                                        <button class="btn btn-ghost btn-xs"
                                                @click="recallEntry(je.id)"
                                                title="Recall to draft">Recall</button>
                                    </template>
                                    <template x-if="je.status === 'posted'">
                                        <button class="btn btn-ghost btn-xs"
                                                @click="reverseEntry(je.id)"
                                                title="Reverse entry">Reverse</button>
                                    </template>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Pagination -->
        <template x-if="!loading && pagination.total_pages > 1">
            <div class="pagination">
                <span class="pagination-info"
                      x-text="'Page ' + pagination.page + ' of ' + pagination.total_pages">
                </span>
                <div class="pagination-controls">
                    <button class="page-btn"
                            :disabled="pagination.page <= 1"
                            @click="goToPage(pagination.page - 1)">&larr; Prev</button>
                    <button class="page-btn"
                            :disabled="!pagination.has_more"
                            @click="goToPage(pagination.page + 1)">Next &rarr;</button>
                </div>
            </div>
        </template>

    </div>

    <!-- ============================================================
         VIEW ENTRY DETAIL MODAL
         ============================================================ -->
    <div x-show="showViewModal" x-cloak>
        <div class="modal-backdrop" @click.self="showViewModal = false">
            <div class="modal modal-lg">
                <div class="modal-header">
                    <h3 class="modal-title">
                        Journal Entry
                        <span class="font-mono" x-text="viewEntry ? viewEntry.entry_number : ''"></span>
                        <template x-if="viewEntry">
                            <span class="badge badge-no-dot"
                                  :class="workflowBadge(viewEntry)"
                                  x-text="workflowLabel(viewEntry)"
                                  style="margin-left:8px;vertical-align:middle;"></span>
                        </template>
                    </h3>
                    <button class="modal-close-btn" aria-label="Close" @click="showViewModal = false">&times;</button>
                </div>
                <div class="modal-body" x-show="viewEntry">
                    <!-- Entry metadata -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;margin-bottom:20px;">
                        <div>
                            <span class="text-secondary text-sm">Date</span>
                            <div class="font-medium" x-text="viewEntry ? formatDate(viewEntry.entry_date) : ''"></div>
                        </div>
                        <div>
                            <span class="text-secondary text-sm">Type</span>
                            <div>
                                <span class="badge badge-no-dot"
                                      :class="viewEntry ? typeBadge(viewEntry.entry_type) : ''"
                                      x-text="viewEntry ? capitalize(viewEntry.entry_type) : ''"></span>
                            </div>
                        </div>
                        <div>
                            <span class="text-secondary text-sm">Description</span>
                            <div x-text="viewEntry ? (viewEntry.description || '—') : ''"></div>
                        </div>
                        <div>
                            <span class="text-secondary text-sm">Reference</span>
                            <div x-text="viewEntry ? (viewEntry.reference || '—') : ''"></div>
                        </div>
                        <template x-if="viewEntry && viewEntry.source">
                            <div>
                                <span class="text-secondary text-sm">Source</span>
                                <div x-text="viewEntry.source"></div>
                            </div>
                        </template>
                    </div>

                    <!-- Lines table -->
                    <div style="overflow-x:auto;">
                        <table class="table" aria-label="Journal Entry Lines">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:50px;">#</th>
                                    <th scope="col">Account Code</th>
                                    <th scope="col">Account Name</th>
                                    <th scope="col">Description</th>
                                    <th scope="col" class="text-right">Debit</th>
                                    <th scope="col" class="text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(line, idx) in viewLines" :key="idx">
                                    <tr>
                                        <td class="text-secondary text-sm" x-text="idx + 1"></td>
                                        <td class="font-mono text-sm" x-text="line.account_code || '—'"></td>
                                        <td class="text-sm" x-text="line.account_name || '—'"></td>
                                        <td class="text-sm" x-text="line.description || '—'"></td>
                                        <td class="font-mono text-sm text-right"
                                            x-text="parseFloat(line.debit) > 0 ? '$' + formatMoney(line.debit) : ''"></td>
                                        <td class="font-mono text-sm text-right"
                                            x-text="parseFloat(line.credit) > 0 ? '$' + formatMoney(line.credit) : ''"></td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight:600;border-top:2px solid var(--border-color);">
                                    <td colspan="4" class="text-right">Totals</td>
                                    <td class="font-mono text-right"
                                        x-text="'$' + formatMoney(viewLines.reduce(function(s, l) { return s + (parseFloat(l.debit) || 0); }, 0))"></td>
                                    <td class="font-mono text-right"
                                        x-text="'$' + formatMoney(viewLines.reduce(function(s, l) { return s + (parseFloat(l.credit) || 0); }, 0))"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php if (can('journal_entries', 'edit')): ?>
                    <template x-if="viewEntry && canSubmit(viewEntry)">
                        <button class="btn btn-primary btn-sm"
                                @click="submitEntry(viewEntry.id); showViewModal = false;">
                            Submit for Review
                        </button>
                    </template>
                    <template x-if="viewEntry && canPost(viewEntry)">
                        <button class="btn btn-primary btn-sm"
                                @click="postEntry(viewEntry.id); showViewModal = false;">
                            Post Entry
                        </button>
                    </template>
                    <template x-if="viewEntry && canApproveRow(viewEntry)">
                        <button class="btn btn-primary btn-sm"
                                @click="approveEntry(viewEntry.id); showViewModal = false;">
                            Approve &amp; Post
                        </button>
                    </template>
                    <template x-if="viewEntry && canRecall(viewEntry)">
                        <button class="btn btn-secondary btn-sm"
                                @click="recallEntry(viewEntry.id); showViewModal = false;">
                            Recall
                        </button>
                    </template>
                    <button class="btn btn-danger btn-sm"
                            x-show="viewEntry && viewEntry.status === 'posted'"
                            @click="reverseEntry(viewEntry.id); showViewModal = false;">
                        Reverse Entry
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-secondary btn-sm" @click="showViewModal = false">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         CREATE JOURNAL ENTRY MODAL
         ============================================================ -->
    <div x-show="showCreateModal" x-cloak>
        <div class="modal-backdrop" @click.self="showCreateModal = false">
            <div class="modal modal-lg">
                <div class="modal-header">
                    <h3 class="modal-title">New Journal Entry</h3>
                    <button class="modal-close-btn" aria-label="Close" @click="showCreateModal = false">&times;</button>
                </div>
                <div class="modal-body" style="max-height:70vh;overflow-y:auto;">
                    <div class="form-error-banner" x-show="createFormError" x-cloak x-text="createFormError" style="margin-bottom:1rem;"></div>

                    <!-- Entry header fields -->
                    <div class="form-row" style="display:flex;gap:16px;margin-bottom:16px;">
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">Entry Date <span class="text-danger">*</span></label>
                            <input type="date"
                                   class="form-control"
                                   x-model="form.entry_date"
                                   :class="createErrors.entry_date ? 'is-invalid' : ''"
                                   @input="createErrors.entry_date = ''"
                                   :disabled="saving">
                            <div class="field-error" x-show="createErrors.entry_date" x-cloak x-text="createErrors.entry_date"></div>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">Entry Type <span class="text-danger">*</span></label>
                            <select class="form-select"
                                    x-model="form.entry_type"
                                    :class="createErrors.entry_type ? 'is-invalid' : ''"
                                    @change="createErrors.entry_type = ''"
                                    :disabled="saving">
                                <option value="manual">Manual</option>
                                <option value="adjusting">Adjusting (AJE — workflow)</option>
                                <option value="reclassifying">Reclassifying (AJE — workflow)</option>
                                <option value="prior_period">Prior Period (AJE — workflow)</option>
                            </select>
                            <div class="field-error" x-show="createErrors.entry_type" x-cloak x-text="createErrors.entry_type"></div>
                            <!-- WHY: AJE types route through draft/submit/approve workflow -
                                 the "Post immediately" checkbox is force-overridden server-side. -->
                            <small class="text-secondary" x-show="isAjeType(form.entry_type)" x-cloak>
                                Adjusting entries begin as drafts and require review per accounting settings.
                            </small>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control"
                               x-model="form.description"
                               :class="createErrors.description ? 'is-invalid' : ''"
                               @input="createErrors.description = ''"
                               maxlength="255"
                               placeholder="Describe the purpose of this entry..."
                               :disabled="saving">
                        <div class="field-error" x-show="createErrors.description" x-cloak x-text="createErrors.description"></div>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label">Reference</label>
                        <input type="text"
                               class="form-control"
                               x-model="form.reference"
                               :class="createErrors.reference ? 'is-invalid' : ''"
                               @input="createErrors.reference = ''"
                               maxlength="100"
                               placeholder="Optional invoice #, PO #, etc."
                               :disabled="saving">
                        <div class="field-error" x-show="createErrors.reference" x-cloak x-text="createErrors.reference"></div>
                    </div>

                    <!-- Dynamic lines table -->
                    <div style="overflow-x:auto;margin-bottom:16px;">
                        <table class="table" aria-label="Journal Entry Lines">
                            <thead>
                                <tr>
                                    <th scope="col" style="min-width:200px;">Account <span class="text-danger">*</span></th>
                                    <th scope="col" style="min-width:160px;">Description</th>
                                    <th scope="col" style="min-width:120px;" class="text-right">Debit</th>
                                    <th scope="col" style="min-width:120px;" class="text-right">Credit</th>
                                    <th scope="col" style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(line, idx) in form.lines" :key="idx">
                                    <tr>
                                        <td>
                                            <select class="form-select form-control-sm"
                                                    x-model="line.account_id"
                                                    :class="(lineErrors[idx] && lineErrors[idx].account_id) ? 'is-invalid' : ''"
                                                    @change="if (lineErrors[idx]) lineErrors[idx].account_id = ''"
                                                    :disabled="saving">
                                                <option value="">-- Select Account --</option>
                                                <template x-for="acct in accounts" :key="acct.id">
                                                    <option :value="acct.id"
                                                            x-text="acct.account_code + ' — ' + acct.account_name"></option>
                                                </template>
                                            </select>
                                            <div class="field-error" x-show="lineErrors[idx] && lineErrors[idx].account_id" x-cloak x-text="lineErrors[idx] ? lineErrors[idx].account_id : ''"></div>
                                        </td>
                                        <td>
                                            <input type="text"
                                                   class="form-control form-control-sm"
                                                   x-model="line.description"
                                                   :class="(lineErrors[idx] && lineErrors[idx].description) ? 'is-invalid' : ''"
                                                   @input="if (lineErrors[idx]) lineErrors[idx].description = ''"
                                                   maxlength="255"
                                                   placeholder="Line memo"
                                                   :disabled="saving">
                                            <div class="field-error" x-show="lineErrors[idx] && lineErrors[idx].description" x-cloak x-text="lineErrors[idx] ? lineErrors[idx].description : ''"></div>
                                        </td>
                                        <td>
                                            <!-- WHY: Auto-clear credit when user types a debit amount
                                                 to enforce single-sided entry per line -->
                                            <input type="number" min="0"
                                                   class="form-control form-control-sm text-right font-mono"
                                                   x-model="line.debit"
                                                   :class="(lineErrors[idx] && lineErrors[idx].debit) ? 'is-invalid' : ''"
                                                   @input="if (parseFloat(line.debit) > 0) line.credit = ''; if (lineErrors[idx]) { lineErrors[idx].debit = ''; lineErrors[idx].amounts = ''; }"
                                                   step="0.01"
                                                   min="0"
                                                   placeholder="0.00"
                                                   :disabled="saving">
                                            <div class="field-error" x-show="lineErrors[idx] && lineErrors[idx].debit" x-cloak x-text="lineErrors[idx] ? lineErrors[idx].debit : ''"></div>
                                        </td>
                                        <td>
                                            <!-- WHY: Auto-clear debit when user types a credit amount
                                                 to enforce single-sided entry per line -->
                                            <input type="number" min="0"
                                                   class="form-control form-control-sm text-right font-mono"
                                                   x-model="line.credit"
                                                   :class="(lineErrors[idx] && lineErrors[idx].credit) ? 'is-invalid' : ''"
                                                   @input="if (parseFloat(line.credit) > 0) line.debit = ''; if (lineErrors[idx]) { lineErrors[idx].credit = ''; lineErrors[idx].amounts = ''; }"
                                                   step="0.01"
                                                   min="0"
                                                   placeholder="0.00"
                                                   :disabled="saving">
                                            <div class="field-error" x-show="lineErrors[idx] && (lineErrors[idx].credit || lineErrors[idx].amounts)" x-cloak x-text="lineErrors[idx] ? (lineErrors[idx].credit || lineErrors[idx].amounts) : ''"></div>
                                        </td>
                                        <td style="text-align:center;vertical-align:middle;">
                                            <button class="btn btn-ghost btn-xs"
                                                    style="color:var(--color-danger);"
                                                    :disabled="form.lines.length <= 2 || saving"
                                                    @click="removeLine(idx)"
                                                    title="Remove line">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot>
                                <!-- Running totals row -->
                                <tr style="font-weight:600;border-top:2px solid var(--border-color);">
                                    <td colspan="2" class="text-right">Totals</td>
                                    <td class="font-mono text-right" x-text="'$' + formatMoney(totalDebit)"></td>
                                    <td class="font-mono text-right" x-text="'$' + formatMoney(totalCredit)"></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Balance indicator + add line -->
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                        <button class="btn btn-secondary btn-sm"
                                @click="addLine()"
                                :disabled="saving">
                            + Add Line
                        </button>

                        <div style="display:flex;align-items:center;gap:12px;">
                            <span class="text-sm font-medium"
                                  :style="isBalanced
                                      ? 'color:var(--color-success);'
                                      : 'color:var(--color-danger);'">
                                <span x-show="isBalanced">Balanced</span>
                                <span x-show="!isBalanced">
                                    Out of balance:
                                    <span class="font-mono"
                                          x-text="'$' + formatMoney(Math.abs(totalDebit - totalCredit))"></span>
                                </span>
                            </span>
                            <!-- WHY: Visual dot indicator gives at-a-glance balance status -->
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;"
                                  :style="isBalanced
                                      ? 'background:var(--color-success);'
                                      : 'background:var(--color-danger);'"></span>
                        </div>
                    </div>

                    <!-- Post immediately checkbox (AJE types ignore this — see PHP create.php gate). -->
                    <label class="form-label" style="display:flex;align-items:center;gap:8px;"
                           :style="isAjeType(form.entry_type) ? 'opacity:0.5;' : ''">
                        <input type="checkbox"
                               x-model="form.post_immediately"
                               :disabled="saving || isAjeType(form.entry_type)">
                        <span x-show="!isAjeType(form.entry_type)">Post immediately (skip draft)</span>
                        <span x-show="isAjeType(form.entry_type)" x-cloak>
                            Post immediately disabled — AJE workflow always begins as draft
                        </span>
                    </label>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="showCreateModal = false" :disabled="saving">Cancel</button>
                    <button class="btn btn-primary"
                            @click="createEntry()"
                            :disabled="saving || !isBalanced">
                        <span x-show="!saving">Save Journal Entry</span>
                        <span x-show="saving">Saving...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
// S-ACCT-AJE — workflow context passed from PHP to gate per-row actions.
window.FF_JE_CTX = window.FF_JE_CTX || {
    reviewRequired: <?= $ajeReviewRequired ? 'true' : 'false' ?>,
    role:           <?= json_encode($currentRole, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    userId:         <?= (int) $currentUserId ?>,
    canApprove:     <?= $canApprove ? 'true' : 'false' ?>,
};

function FF_JournalEntries() {
    return {
        // -- List state ---------------------------------------------------
        entries:     [],
        loading:     true,
        loadError:   null,
        activeTab:   'pending',
        pagination:  {},
        page:        1,

        // S-ACCT-AJE context (mirror of window.FF_JE_CTX for template access)
        ctx: window.FF_JE_CTX,
        ajeTypes: ['adjusting','reclassifying','prior_period'],

        filters: {
            search:    '',
            type:      '',
            date_from: '',
            date_to:   '',
            sort:      'entry_date',
            dir:       'DESC',
        },

        // -- View modal ---------------------------------------------------
        showViewModal: false,
        viewEntry:     null,
        viewLines:     [],

        // -- Create modal -------------------------------------------------
        showCreateModal: false,
        saving:          false,
        createError:     null,     // legacy — kept for backwards compat
        accounts:        [],

        // VALID-2: per-form banner and per-field errors
        createFormError: '',
        createErrors: { entry_date: '', entry_type: '', description: '', reference: '' },
        lineErrors:   [],  // parallel array to form.lines: each entry {account_id, description, debit, credit, amounts}

        form: {
            entry_date:       new Date().toISOString().split('T')[0],
            description:      '',
            entry_type:       'manual',
            reference:        '',
            post_immediately: false,
            lines: [
                { account_id: '', description: '', debit: '', credit: '' },
                { account_id: '', description: '', debit: '', credit: '' },
            ]
        },

        // -- Computed: running totals for create form ---------------------
        get totalDebit() {
            return this.form.lines.reduce(function(sum, l) {
                return sum + (parseFloat(l.debit) || 0);
            }, 0);
        },
        get totalCredit() {
            return this.form.lines.reduce(function(sum, l) {
                return sum + (parseFloat(l.credit) || 0);
            }, 0);
        },
        // WHY: 0.005 tolerance avoids floating-point rounding edge cases
        // while still catching real imbalances. totalDebit > 0 prevents
        // saving a journal entry with no amounts entered.
        get isBalanced() {
            return Math.abs(this.totalDebit - this.totalCredit) < 0.005
                && this.totalDebit > 0;
        },

        // -- Init ---------------------------------------------------------
        async init() {
            this.loadEntries();
            this.loadAccounts();
        },

        // -- Load journal entries list ------------------------------------
        async loadEntries() {
            this.loading   = true;
            this.loadError = null;

            var params = new URLSearchParams();
            params.set('page',     this.page);
            params.set('per_page', 25);
            params.set('sort',     this.filters.sort);
            params.set('dir',      this.filters.dir);

            // S-ACCT-AJE: 'pending' tab is an entry_status filter, not a status filter.
            // Other tabs continue to filter by the GL status column.
            if (this.activeTab === 'pending') {
                params.set('entry_status', 'draft,submitted');
            } else if (this.activeTab !== 'all') {
                params.set('status', this.activeTab);
            }
            if (this.filters.search)    params.set('q',         this.filters.search);
            if (this.filters.type)      params.set('type',      this.filters.type);
            if (this.filters.date_from) params.set('date_from', this.filters.date_from);
            if (this.filters.date_to)   params.set('date_to',   this.filters.date_to);

            try {
                var r = await FF_Api.get('<?= base_url('api/v1/accounting/journal_entries') ?>?' + params);
                if (r.success) {
                    this.entries    = r.data.items || r.data || [];
                    this.pagination = r.data.pagination || r.pagination || {};
                } else {
                    this.loadError = r.message || 'Failed to load journal entries.';
                }
            } catch(e) {
                this.loadError = 'Network error. Please try again.';
            }
            this.loading = false;
        },

        // -- Load GL accounts for line dropdowns --------------------------
        async loadAccounts() {
            try {
                var r = await FF_Api.get('<?= base_url('api/v1/accounting/accounts') ?>?flat=1&active=1');
                if (r.success) {
                    this.accounts = r.data.accounts || r.data || [];
                }
            } catch(e) {
                // WHY: Non-critical failure — create modal will still render
                // with an empty dropdown; user can retry by reopening.
            }
        },

        // -- Tab + filter helpers -----------------------------------------
        setTab(tab) {
            this.activeTab = tab;
            this.page      = 1;
            this.loadEntries();
        },

        resetPage() {
            this.page = 1;
            this.loadEntries();
        },

        goToPage(p) {
            this.page = p;
            this.loadEntries();
        },

        hasActiveFilters() {
            return this.filters.search || this.filters.type
                || this.filters.date_from || this.filters.date_to;
        },

        // -- VALID-2 helpers ----------------------------------------------
        _emptyLineErr() {
            return { account_id: '', description: '', debit: '', credit: '', amounts: '' };
        },
        _syncLineErrors() {
            // Make lineErrors length match form.lines length
            while (this.lineErrors.length < this.form.lines.length) {
                this.lineErrors.push(this._emptyLineErr());
            }
            while (this.lineErrors.length > this.form.lines.length) {
                this.lineErrors.pop();
            }
        },
        _clearCreateErrors() {
            this.createFormError = '';
            for (const k in this.createErrors) {
                if (Object.prototype.hasOwnProperty.call(this.createErrors, k)) this.createErrors[k] = '';
            }
            this.lineErrors = this.form.lines.map(() => this._emptyLineErr());
        },
        _paintCreateServerErrors(r, fallback) {
            const fields = (r && r.error && r.error.fields) || (r && r.fields) || null;
            if (fields && typeof fields === 'object') {
                let any = false;
                for (const k in fields) {
                    if (!Object.prototype.hasOwnProperty.call(fields, k)) continue;
                    const msg = fields[k];
                    if (Object.prototype.hasOwnProperty.call(this.createErrors, k)) {
                        this.createErrors[k] = msg;
                        any = true;
                    } else if (k === '_general' || k === '') {
                        this.createFormError = msg;
                        any = true;
                    } else {
                        // line-specific keys like lines.0.account_id or lines[0].debit
                        const m = k.match(/^lines[\.\[](\d+)[\]\.](.+)$/);
                        if (m) {
                            const idx = parseInt(m[1], 10);
                            const f = m[2];
                            if (this.lineErrors[idx] && Object.prototype.hasOwnProperty.call(this.lineErrors[idx], f)) {
                                this.lineErrors[idx][f] = msg;
                                any = true;
                            } else if (this.lineErrors[idx]) {
                                this.lineErrors[idx].amounts = msg;
                                any = true;
                            }
                        }
                    }
                }
                if (!any) this.createFormError = this.extractError(r, fallback);
            } else {
                this.createFormError = this.extractError(r, fallback);
            }
        },

        // -- Line management (create form) --------------------------------
        addLine() {
            this.form.lines.push({ account_id: '', description: '', debit: '', credit: '' });
            this._syncLineErrors();
        },

        removeLine(idx) {
            // WHY: Minimum 2 lines enforced — a journal entry always needs
            // at least one debit side and one credit side.
            if (this.form.lines.length > 2) {
                this.form.lines.splice(idx, 1);
                this._syncLineErrors();
            }
        },

        // -- Create entry -------------------------------------------------
        openCreate() {
            this.form = {
                entry_date:       new Date().toISOString().split('T')[0],
                description:      '',
                entry_type:       'manual',
                reference:        '',
                post_immediately: false,
                lines: [
                    { account_id: '', description: '', debit: '', credit: '' },
                    { account_id: '', description: '', debit: '', credit: '' },
                ]
            };
            this.createError     = null;
            this._clearCreateErrors();
            this.saving          = false;
            this.showCreateModal = true;
        },

        // -- Extract error message from VALID-2 envelope ------------------
        extractError(r, fallback) {
            if (!r || !r.error) return fallback;
            var f = r.error.fields || r.error.errors || {};
            var keys = Object.keys(f);
            if (keys.length) return keys.map(function(k){ return f[k]; }).join(' ');
            return r.error.message || r.message || fallback;
        },

        validateCreateEntry() {
            this._clearCreateErrors();
            this._syncLineErrors();
            let ok = true;

            if (!this.form.entry_date) {
                this.createErrors.entry_date = 'Entry date is required.';
                ok = false;
            }
            const types = ['manual','adjusting','reclassifying','prior_period'];
            if (!this.form.entry_type) {
                this.createErrors.entry_type = 'Entry type is required.';
                ok = false;
            } else if (!types.includes(this.form.entry_type)) {
                this.createErrors.entry_type = 'Entry type must be one of: manual, adjusting, reclassifying, prior_period.';
                ok = false;
            }
            if (!this.form.description || !this.form.description.trim()) {
                this.createErrors.description = 'Description is required.';
                ok = false;
            }
            if (!this.form.lines || this.form.lines.length < 2) {
                this.createFormError = 'Journal entry must have at least 2 lines.';
                ok = false;
            }

            let totalDebit  = 0;
            let totalCredit = 0;
            for (let i = 0; i < this.form.lines.length; i++) {
                const l  = this.form.lines[i];
                const le = this.lineErrors[i] || this._emptyLineErr();
                if (!l.account_id) {
                    le.account_id = 'Please select an account.';
                    ok = false;
                }
                const dStr = String(l.debit  || '').trim();
                const cStr = String(l.credit || '').trim();
                if (dStr !== '' && !/^\d+(\.\d{1,2})?$/.test(dStr)) {
                    le.debit = 'Debit must be a valid amount.';
                    ok = false;
                }
                if (cStr !== '' && !/^\d+(\.\d{1,2})?$/.test(cStr)) {
                    le.credit = 'Credit must be a valid amount.';
                    ok = false;
                }
                const d = parseFloat(dStr || '0') || 0;
                const c = parseFloat(cStr || '0') || 0;
                if (d < 0) { le.debit = 'Debit cannot be negative.'; ok = false; }
                if (c < 0) { le.credit = 'Credit cannot be negative.'; ok = false; }
                if (d > 0 && c > 0) { le.amounts = 'Line cannot have both debit and credit.'; ok = false; }
                if (d === 0 && c === 0 && l.account_id) { le.amounts = 'Line must have either a debit or a credit.'; ok = false; }
                this.lineErrors[i] = le;
                totalDebit  += d;
                totalCredit += c;
            }

            if (ok && Math.abs(totalDebit - totalCredit) > 0.009) {
                this.createFormError = 'Entry is unbalanced: debits (' + totalDebit.toFixed(2) + ') must equal credits (' + totalCredit.toFixed(2) + ').';
                ok = false;
            }
            if (ok && totalDebit === 0) {
                this.createFormError = 'Entry total cannot be zero.';
                ok = false;
            }
            return ok;
        },

        async createEntry() {
            if (!this.validateCreateEntry()) return;

            this.saving = true;

            try {
                var r = await FF_Api.post('<?= base_url('api/v1/accounting/journal_entries/create') ?>', this.form);
                if (r.success) {
                    this.showCreateModal = false;
                    FF_Toast.success('Journal entry created successfully.');
                    this.loadEntries();
                } else {
                    this._paintCreateServerErrors(r, 'Failed to create journal entry.');
                }
            } catch(e) {
                this.createFormError = 'Network error. Please try again.';
            }
            this.saving = false;
        },

        // -- Post a draft entry -------------------------------------------
        postEntry(id) {
            FF_Confirm.show({
                title:        'Post Journal Entry',
                message:      'Post this journal entry? Posted entries affect account balances and cannot be edited.',
                confirmLabel: 'Post',
                dangerMode:   false,
                onConfirm:    async () => {
                    try {
                        var r = await FF_Api.post('<?= base_url('api/v1/accounting/journal_entries/post') ?>', { id: id });
                        if (r.success) {
                            FF_Toast.success('Journal entry posted.');
                            this.loadEntries();
                        } else {
                            FF_Toast.error(this.extractError(r, 'Failed to post entry.'));
                        }
                    } catch(e) {
                        FF_Toast.error('Network error. Please try again.');
                    }
                }
            });
        },

        // -- Reverse a posted entry ---------------------------------------
        reverseEntry(id) {
            FF_Confirm.show({
                title:        'Reverse Journal Entry',
                message:      'Create a reversing entry? This will generate a new entry with opposite debits and credits.',
                confirmLabel: 'Reverse',
                dangerMode:   true,
                onConfirm:    async () => {
                    try {
                        var r = await FF_Api.post('<?= base_url('api/v1/accounting/journal_entries/reverse') ?>', { id: id });
                        if (r.success) {
                            FF_Toast.success('Journal entry reversed.');
                            this.loadEntries();
                        } else {
                            FF_Toast.error(this.extractError(r, 'Failed to reverse entry.'));
                        }
                    } catch(e) {
                        FF_Toast.error('Network error. Please try again.');
                    }
                }
            });
        },

        // -- View entry detail --------------------------------------------
        async viewEntryDetail(id) {
            try {
                var r = await FF_Api.get('<?= base_url('api/v1/accounting/journal_entries/show') ?>?id=' + id);
                if (r.success) {
                    this.viewEntry     = r.data;
                    this.viewLines     = r.data.lines || [];
                    this.showViewModal = true;
                } else {
                    FF_Toast.error(r.message || 'Failed to load entry details.');
                }
            } catch(e) {
                FF_Toast.error('Network error. Please try again.');
            }
        },

        // -- Display helpers ----------------------------------------------
        statusBadge(status) {
            var map = {
                draft:    'badge-amber',
                posted:   'badge-success',
                reversed: 'badge-danger',
            };
            return map[status] || 'badge-neutral';
        },

        // S-ACCT-AJE: workflowBadge / workflowLabel surface entry_status when
        // it's distinct from the terminal posted/reversed state, otherwise
        // fall back to the GL status. Reversed/posted are terminal — they
        // already match between status + entry_status.
        workflowBadge(je) {
            if (!je) return 'badge-neutral';
            if (je.status === 'reversed')     return 'badge-danger';
            if (je.status === 'posted')       return 'badge-success';
            var es = je.entry_status || 'posted';
            if (es === 'submitted')           return 'badge-amber';
            if (es === 'approved')            return 'badge-blue';
            if (es === 'draft')               return 'badge-neutral';
            return this.statusBadge(je.status);
        },
        workflowLabel(je) {
            if (!je) return '';
            if (je.status === 'reversed')     return 'Reversed';
            if (je.status === 'posted')       return 'Posted';
            var es = je.entry_status || 'posted';
            if (es === 'submitted')           return 'Pending Review';
            if (es === 'approved')            return 'Approved';
            if (es === 'draft')               return 'Draft';
            return this.capitalize(je.status);
        },

        isAjeType(type) {
            return this.ajeTypes.indexOf(type) !== -1;
        },

        // -- AJE workflow per-row action gating ---------------------------
        // canSubmit: AJE draft + review required + still status='draft'.
        canSubmit(je) {
            if (!je || je.status !== 'draft') return false;
            if (!this.isAjeType(je.entry_type)) return false;
            if ((je.entry_status || 'posted') !== 'draft') return false;
            return !!this.ctx.reviewRequired;
        },
        // canPost: status='draft' AND not in an AJE workflow gate.
        // Non-AJE draft (entry_status='posted'): always postable.
        // AJE draft + review off: postable directly (single-step approval).
        // AJE submitted/approved: NOT postable here — use approveEntry instead.
        canPost(je) {
            if (!je || je.status !== 'draft') return false;
            var es = je.entry_status || 'posted';
            if (!this.isAjeType(je.entry_type)) return es !== 'submitted';
            // AJE row: only postable when no review required AND still in draft.
            return !this.ctx.reviewRequired && es === 'draft';
        },
        // canApproveRow: entry_status='submitted' + caller has approver role
        // + (when review required) caller != submitter.
        canApproveRow(je) {
            if (!je || (je.entry_status || 'posted') !== 'submitted') return false;
            if (!this.ctx.canApprove) return false;
            if (this.ctx.reviewRequired
                && je.submitted_by_id != null
                && Number(je.submitted_by_id) === Number(this.ctx.userId)) {
                return false;
            }
            return true;
        },
        // canRecall: entry_status='submitted' + (caller is submitter OR super_admin).
        canRecall(je) {
            if (!je || (je.entry_status || 'posted') !== 'submitted') return false;
            var isSubmitter = je.submitted_by_id != null
                && Number(je.submitted_by_id) === Number(this.ctx.userId);
            return isSubmitter || this.ctx.role === 'super_admin';
        },

        // -- AJE workflow actions -----------------------------------------
        submitEntry(id) {
            FF_Confirm.show({
                title:        'Submit Adjusting Entry',
                message:      'Submit this entry for review? Another user will need to approve before it posts to the GL.',
                confirmLabel: 'Submit',
                dangerMode:   false,
                onConfirm:    async () => {
                    try {
                        var r = await FF_Api.post('<?= base_url('api/v1/accounting/journal_entries/submit') ?>', { id: id });
                        if (r.success) {
                            FF_Toast.success('Adjusting entry submitted for review.');
                            this.loadEntries();
                        } else {
                            FF_Toast.error(this.extractError(r, 'Failed to submit entry.'));
                        }
                    } catch(e) {
                        FF_Toast.error('Network error. Please try again.');
                    }
                }
            });
        },

        approveEntry(id) {
            FF_Confirm.show({
                title:        'Approve & Post Adjusting Entry',
                message:      'Approve this adjusting entry and post it to the GL? This action affects account balances.',
                confirmLabel: 'Approve & Post',
                dangerMode:   false,
                onConfirm:    async () => {
                    try {
                        var r = await FF_Api.post('<?= base_url('api/v1/accounting/journal_entries/approve') ?>', { id: id });
                        if (r.success) {
                            FF_Toast.success('Adjusting entry approved and posted.');
                            this.loadEntries();
                        } else {
                            FF_Toast.error(this.extractError(r, 'Failed to approve entry.'));
                        }
                    } catch(e) {
                        FF_Toast.error('Network error. Please try again.');
                    }
                }
            });
        },

        recallEntry(id) {
            FF_Confirm.show({
                title:        'Recall Adjusting Entry',
                message:      'Recall this entry back to draft? The submission stamps will be cleared.',
                confirmLabel: 'Recall',
                dangerMode:   false,
                onConfirm:    async () => {
                    try {
                        var r = await FF_Api.post('<?= base_url('api/v1/accounting/journal_entries/recall') ?>', { id: id });
                        if (r.success) {
                            FF_Toast.success('Adjusting entry recalled to draft.');
                            this.loadEntries();
                        } else {
                            FF_Toast.error(this.extractError(r, 'Failed to recall entry.'));
                        }
                    } catch(e) {
                        FF_Toast.error('Network error. Please try again.');
                    }
                }
            });
        },

        typeBadge(type) {
            var map = {
                manual:        'badge-blue',
                system:        'badge-purple',
                auto:          'badge-purple',
                recurring:     'badge-purple',
                reversing:     'badge-amber',
                year_end:      'badge-neutral',
                adjustment:    'badge-blue',
                adjusting:     'badge-blue',
                reclassifying: 'badge-blue',
                prior_period:  'badge-amber',
                closing:       'badge-neutral',
            };
            return map[type] || 'badge-neutral';
        },

        capitalize(s) {
            if (!s) return '';
            // Underscore-aware: "prior_period" → "Prior Period".
            return String(s).split('_').map(function(part) {
                return part ? part.charAt(0).toUpperCase() + part.slice(1) : '';
            }).join(' ');
        },

        formatDate(d) {
            if (!d) return '—';
            var dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
        },

        formatMoney(val) {
            var n = parseFloat(val);
            if (isNaN(n)) return '0.00';
            return n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
