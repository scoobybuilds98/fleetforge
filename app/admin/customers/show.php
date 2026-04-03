<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Profile Page
 *
 * @file        app/admin/customers/show.php
 * @description Customer profile page. Header shows key summary (name, status,
 *              risk, tags). Body has 4 tabs: Overview (detail cards), Notes
 *              (pinned + all), Leases (lazy-loaded from API), Invoices
 *              (lazy-loaded from API). Notes are loaded via Alpine.js from the
 *              notes API. Delete button fires soft-delete via confirm modal.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/customers/show.php,
 *              api/v1/customers/notes/index.php, api/v1/customers/notes/create.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @session     S005
 */

// dirname(__DIR__, 3): app/admin/customers/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'view');

// ── Resolve customer ID ────────────────────────────────────────
$customerId = clean_int($_GET['id'] ?? null);
if (!$customerId || $customerId <= 0) {
    header('Location: ' . base_url('customers'));
    exit;
}

// ── Load customer ─────────────────────────────────────────────
$customer = db_row(
    "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);

if (!$customer) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Customer Not Found</h1>';
    exit;
}

// Load tags
$tagRows = db_select(
    "SELECT tag FROM customer_tags WHERE customer_id = ? ORDER BY tag",
    [$customerId]
);
$tags = array_column($tagRows, 'tag');

// WHY: badge classes per FLEETFORGE_DESIGN_DETAILS.md §9
$statusBadgeClass = match($customer['status']) {
    'active'      => 'badge-success',
    'inactive'    => 'badge-neutral',
    'pending'     => 'badge-info',
    'suspended'   => 'badge-danger',
    'credit_hold' => 'badge-warning',
    default       => 'badge-neutral',
};

$riskBadgeClass = match($customer['risk_score']) {
    'high'   => 'badge-danger',
    'medium' => 'badge-warning',
    'low'    => 'badge-success',
    default  => 'badge-neutral',
};

$pageTitle = $customer['company_name'];
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('customers') ?>">Customers</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($customer['company_name']) ?></span>
</nav>
<div class="page-header">
    <div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h1 class="page-header-title h4" style="margin:0;"><?= e($customer['company_name']) ?></h1>
            <span class="badge <?= $statusBadgeClass ?>">
                <?= e(str_replace('_', ' ', $customer['status'])) ?>
            </span>
            <span class="badge <?= $riskBadgeClass ?>">
                Risk: <?= e($customer['risk_score']) ?>
            </span>
            <?php foreach ($tags as $tag): ?>
            <span class="badge badge-neutral"><?= e($tag) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <?php if (can('customers', 'edit')): ?>
        <a href="<?= base_url('customers/edit') ?>?id=<?= $customerId ?>"
           class="btn btn-secondary btn-sm">Edit</a>
        <?php endif; ?>
        <?php if (can('customers', 'delete') && (int) $customer['active_lease_count'] === 0): ?>
        <button class="btn btn-danger btn-sm"
                onclick="FF_Confirm.show({
                    title: 'Delete Customer',
                    message: 'Delete <?= e(addslashes($customer['company_name'])) ?>? This cannot be undone.',
                    confirmLabel: 'Delete',
                    dangerMode: true,
                    onConfirm: () => deleteCustomer(<?= $customerId ?>)
                })">
            Delete
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     STATS ROW — 4 quick-stat tiles
     ============================================================ -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-label">Active Leases</div>
        <div class="stat-value font-mono"><?= e($customer['active_lease_count']) ?></div>
        <div class="stat-delta text-secondary">of <?= e($customer['lease_count']) ?> total</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Outstanding Balance</div>
        <div class="stat-value currency"><?= e(format_currency($customer['outstanding_balance'])) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value currency"><?= e(format_currency($customer['total_revenue'])) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Account Credit</div>
        <div class="stat-value currency"><?= e(format_currency($customer['account_credit_balance'])) ?></div>
    </div>

</div>

<!-- ============================================================
     TABS — Alpine.js tab switcher
     ============================================================ -->
<div x-data="FF_CustomerProfile()" x-init="init()">

    <!-- Tab nav -->
    <div class="tab-bar" role="tablist">
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'overview' }"
                @click="activeTab = 'overview'" :aria-selected="activeTab === 'overview'" role="tab">
            Overview
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'notes' }"
                @click="activeTab = 'notes'; loadNotes()" :aria-selected="activeTab === 'notes'" role="tab">
            Notes
            <span class="tab-badge" x-show="noteCount > 0" x-text="noteCount"></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'leases' }"
                @click="activeTab = 'leases'" :aria-selected="activeTab === 'leases'" role="tab">
            Leases
            <span class="tab-badge"><?= e($customer['lease_count']) ?></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'invoices' }"
                @click="activeTab = 'invoices'" :aria-selected="activeTab === 'invoices'" role="tab">
            Invoices
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'damage_claims' }"
                @click="activeTab = 'damage_claims'" :aria-selected="activeTab === 'damage_claims'" role="tab">
            Damage Claims
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'mileage_logs' }"
                @click="activeTab = 'mileage_logs'" :aria-selected="activeTab === 'mileage_logs'" role="tab">
            Mileage Logs
        </button>
    </div>

    <!-- ── TAB: OVERVIEW ──────────────────────────────────────── -->
    <div x-show="activeTab === 'overview'" role="tabpanel">
        <div class="grid-2">

            <!-- Contact Info -->
            <div class="card">
                <div class="card-header"><span class="card-title">Contact</span></div>
                <div class="card-body">
                    <dl style="display:grid; grid-template-columns:max-content 1fr; gap:8px 20px; margin:0;">
                        <dt class="text-secondary text-sm">Primary Contact</dt>
                        <dd style="margin:0;"><?= e($customer['contact_name'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Email</dt>
                        <dd style="margin:0;">
                            <?php if ($customer['email']): ?>
                            <a href="mailto:<?= e($customer['email']) ?>"
                               style="color:var(--color-primary);"><?= e($customer['email']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </dd>
                        <dt class="text-secondary text-sm">Phone</dt>
                        <dd style="margin:0;"><?= e($customer['phone'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Alt Phone</dt>
                        <dd style="margin:0;"><?= e($customer['alt_phone'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Website</dt>
                        <dd style="margin:0;">
                            <?php if ($customer['website']): ?>
                            <a href="<?= e($customer['website']) ?>" target="_blank" rel="noopener"
                               style="color:var(--color-primary);"><?= e($customer['website']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <!-- Address -->
            <div class="card">
                <div class="card-header"><span class="card-title">Address</span></div>
                <div class="card-body">
                    <dl style="display:grid; grid-template-columns:max-content 1fr; gap:8px 20px; margin:0;">
                        <dt class="text-secondary text-sm">Street</dt>
                        <dd style="margin:0;"><?= e($customer['address'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">City</dt>
                        <dd style="margin:0;"><?= e($customer['city'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Province / State</dt>
                        <dd style="margin:0;"><?= e($customer['province'] ?? $customer['state'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Postal / ZIP</dt>
                        <dd style="margin:0;"><?= e($customer['postal_code'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Country</dt>
                        <dd style="margin:0;"><?= e($customer['country'] ?? '—') ?></dd>
                    </dl>
                </div>
            </div>

            <!-- Regulatory -->
            <div class="card">
                <div class="card-header"><span class="card-title">Regulatory</span></div>
                <div class="card-body">
                    <dl style="display:grid; grid-template-columns:max-content 1fr; gap:8px 20px; margin:0;">
                        <dt class="text-secondary text-sm">DOT Number</dt>
                        <dd style="margin:0;" class="font-mono"><?= e($customer['dot_number'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">MC Number</dt>
                        <dd style="margin:0;" class="font-mono"><?= e($customer['mc_number'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">GST Number</dt>
                        <dd style="margin:0;" class="font-mono"><?= e($customer['gst_number'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">PST Number</dt>
                        <dd style="margin:0;" class="font-mono"><?= e($customer['pst_number'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">GST Exempt</dt>
                        <dd style="margin:0;">
                            <?= $customer['gst_exempt']
                                ? '<span class="badge badge-warning">Yes</span>'
                                : 'No' ?>
                        </dd>
                        <dt class="text-secondary text-sm">PST Exempt</dt>
                        <dd style="margin:0;">
                            <?= $customer['pst_exempt']
                                ? '<span class="badge badge-warning">Yes</span>'
                                : 'No' ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <!-- Billing Contact -->
            <div class="card">
                <div class="card-header"><span class="card-title">Billing Contact</span></div>
                <div class="card-body">
                    <dl style="display:grid; grid-template-columns:max-content 1fr; gap:8px 20px; margin:0;">
                        <dt class="text-secondary text-sm">Billing Contact</dt>
                        <dd style="margin:0;"><?= e($customer['billing_contact_name'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Billing Email</dt>
                        <dd style="margin:0;"><?= e($customer['billing_email'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Billing Phone</dt>
                        <dd style="margin:0;"><?= e($customer['billing_phone'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Invoice Email</dt>
                        <dd style="margin:0;"><?= e($customer['invoice_email'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Invoice Delivery</dt>
                        <dd style="margin:0;"><?= e($customer['invoice_delivery'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">PO Required</dt>
                        <dd style="margin:0;"><?= $customer['po_required'] ? 'Yes' : 'No' ?></dd>
                    </dl>
                </div>
            </div>

            <!-- Commercial Terms -->
            <div class="card">
                <div class="card-header"><span class="card-title">Commercial Terms</span></div>
                <div class="card-body">
                    <dl style="display:grid; grid-template-columns:max-content 1fr; gap:8px 20px; margin:0;">
                        <dt class="text-secondary text-sm">Currency</dt>
                        <dd style="margin:0;" class="font-mono"><?= e($customer['currency']) ?></dd>
                        <dt class="text-secondary text-sm">Mileage Unit</dt>
                        <dd style="margin:0;"><?= e($customer['mileage_unit']) ?></dd>
                        <dt class="text-secondary text-sm">Billing Cycle</dt>
                        <dd style="margin:0;"><?= e(str_replace('_', ' ', $customer['billing_cycle'])) ?></dd>
                        <dt class="text-secondary text-sm">Payment Terms</dt>
                        <dd style="margin:0;"><?= e($customer['payment_terms'] ?? '—') ?></dd>
                        <dt class="text-secondary text-sm">Credit Limit</dt>
                        <dd style="margin:0;" class="currency"><?= e(format_currency($customer['credit_limit'])) ?></dd>
                        <dt class="text-secondary text-sm">Discount</dt>
                        <dd style="margin:0;">
                            <?php
                            if ($customer['discount_type'] === 'none') {
                                echo 'None';
                            } elseif ($customer['discount_type'] === 'percentage') {
                                echo e($customer['discount_value']) . '%';
                            } else {
                                echo e(format_currency($customer['discount_value']));
                            }
                            ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <!-- Account -->
            <div class="card">
                <div class="card-header"><span class="card-title">Account</span></div>
                <div class="card-body">
                    <dl style="display:grid; grid-template-columns:max-content 1fr; gap:8px 20px; margin:0;">
                        <dt class="text-secondary text-sm">Created</dt>
                        <dd style="margin:0;" class="font-mono"><?= e(format_datetime($customer['created_at'])) ?></dd>
                        <dt class="text-secondary text-sm">Last Updated</dt>
                        <dd style="margin:0;" class="font-mono"><?= e(format_datetime($customer['updated_at'])) ?></dd>
                    </dl>
                </div>
            </div>

        </div>
    </div><!-- /overview tab -->

    <!-- ── TAB: NOTES ─────────────────────────────────────────── -->
    <div x-show="activeTab === 'notes'" role="tabpanel">

        <?php if (can('customers', 'create')): ?>
        <!-- Add note form -->
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header"><span class="card-title">Add Note</span></div>
            <div class="card-body">
                <textarea class="form-control"
                          x-model="newNote"
                          rows="3"
                          maxlength="10000"
                          placeholder="Add a note about this customer…"></textarea>
                <div style="margin-top:10px; display:flex; gap:12px; align-items:center;">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" x-model="newNotePinned">
                        <span class="form-check-label">Pin this note</span>
                    </label>
                    <button class="btn btn-primary btn-sm"
                            :disabled="!newNote.trim() || savingNote"
                            @click="saveNote()"
                            x-text="savingNote ? 'Saving…' : 'Add Note'">
                        Add Note
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notes loading skeleton -->
        <template x-if="notesLoading">
            <div>
                <template x-for="n in 3" :key="n">
                    <div class="skeleton skeleton-row" style="margin-bottom:12px;"></div>
                </template>
            </div>
        </template>

        <!-- Notes empty state -->
        <template x-if="!notesLoading && notes.length === 0">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
                </div>
                <p class="empty-state-title">No notes yet</p>
                <p class="empty-state-text">Notes about this customer will appear here.</p>
            </div>
        </template>

        <!-- Notes list -->
        <template x-if="!notesLoading && notes.length > 0">
            <div>
                <template x-for="note in notes" :key="note.id">
                    <div class="card"
                         style="margin-bottom:12px;"
                         :style="note.is_pinned ? 'border-left:3px solid var(--color-warning);' : ''">
                        <div class="card-body" style="padding:14px 20px;">
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                                <span class="badge badge-warning"
                                      x-show="note.is_pinned">Pinned</span>
                                <span class="text-secondary text-xs font-mono"
                                      x-text="(note.created_by_name || 'Unknown') + ' · ' + formatDate(note.created_at)">
                                </span>
                            </div>
                            <p style="margin:0;" x-text="note.note"></p>
                        </div>
                    </div>
                </template>
            </div>
        </template>

    </div><!-- /notes tab -->

    <!-- ── TAB: LEASES ──────────────────────────────────────────── -->
    <div x-show="activeTab === 'leases'" role="tabpanel">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span class="card-title">Leases</span>
                <a href="<?= base_url('leases') ?>?customer_id=<?= $customerId ?>"
                   class="btn btn-secondary btn-sm">View All</a>
            </div>

            <!-- Filter bar -->
            <div class="tab-filter-bar">
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="leasesFilters.status" @change="applyLeasesFilters()">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="leasesFilters.sort" @change="applyLeasesFilters()">
                    <option value="created_at">Sort: Date Created</option>
                    <option value="start_date">Sort: Start Date</option>
                    <option value="monthly_rate">Sort: Monthly Rate</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="leasesFilters.dir" @change="applyLeasesFilters()">
                    <option value="DESC">Newest First</option>
                    <option value="ASC">Oldest First</option>
                </select>
            </div>

            <!-- Loading -->
            <div x-show="leasesLoading && leases.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading leases…</span>
            </div>

            <!-- Empty state -->
            <div x-show="leasesLoaded && !leasesLoading && leases.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No leases found</p>
                    <p class="empty-state-text">No leases match the current filters.</p>
                </div>
            </div>

            <!-- Table + footer -->
            <div x-show="leases.length > 0">
                <div class="tab-table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Contract #</th>
                                <th>Unit</th>
                                <th>Status</th>
                                <th>Start</th>
                                <th>End</th>
                                <th class="text-right">Monthly Rate</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="ls in leases" :key="ls.id">
                                <tr>
                                    <td class="font-mono" x-text="ls.contract_number"></td>
                                    <td class="font-mono" x-text="ls.unit_display_number || ls.unit_number_snapshot || '—'"></td>
                                    <td>
                                        <span class="badge badge-no-dot"
                                              :class="leaseBadgeClass(ls.status)"
                                              x-text="ls.status.charAt(0).toUpperCase() + ls.status.slice(1)"></span>
                                    </td>
                                    <td x-text="formatDate(ls.start_date)"></td>
                                    <td x-text="ls.end_date ? formatDate(ls.end_date) : 'Open'"></td>
                                    <td class="text-right font-mono" x-text="'$' + parseFloat(ls.monthly_rate).toFixed(2)"></td>
                                    <td>
                                        <a :href="'<?= base_url('leases/show') ?>?id=' + ls.id"
                                           class="btn btn-sm btn-secondary">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="tab-table-footer">
                    <span x-text="`Showing ${leases.length} of ${leasesTotal}`"></span>
                    <button class="btn btn-secondary btn-sm"
                            x-show="leases.length < leasesTotal"
                            :disabled="leasesLoading"
                            @click="loadMoreLeases()"
                            x-text="leasesLoading ? 'Loading…' : 'Load more'">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: INVOICES ─────────────────────────────────────────── -->
    <div x-show="activeTab === 'invoices'" role="tabpanel">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span class="card-title">Invoices</span>
                <a href="<?= base_url('invoices') ?>?customer_id=<?= $customerId ?>"
                   class="btn btn-secondary btn-sm">View All</a>
            </div>

            <!-- Filter bar -->
            <div class="tab-filter-bar">
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="invoicesFilters.status" @change="applyInvoicesFilters()">
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="sent">Sent</option>
                    <option value="paid">Paid</option>
                    <option value="partially_paid">Partially Paid</option>
                    <option value="overdue">Overdue</option>
                    <option value="void">Void</option>
                    <option value="written_off">Written Off</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="invoicesFilters.sort" @change="applyInvoicesFilters()">
                    <option value="created_at">Sort: Date Created</option>
                    <option value="due_date">Sort: Due Date</option>
                    <option value="total_amount">Sort: Amount</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="invoicesFilters.dir" @change="applyInvoicesFilters()">
                    <option value="DESC">Newest First</option>
                    <option value="ASC">Oldest First</option>
                </select>
            </div>

            <!-- Loading -->
            <div x-show="invoicesLoading && invoices.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading invoices…</span>
            </div>

            <!-- Empty state -->
            <div x-show="invoicesLoaded && !invoicesLoading && invoices.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No invoices found</p>
                    <p class="empty-state-text">No invoices match the current filters.</p>
                </div>
            </div>

            <!-- Table + footer -->
            <div x-show="invoices.length > 0">
                <div class="tab-table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Period</th>
                                <th>Status</th>
                                <th>Due Date</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Balance Due</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="inv in invoices" :key="inv.id">
                                <tr>
                                    <td class="font-mono" x-text="inv.invoice_number"></td>
                                    <td x-text="inv.billing_period_start + ' → ' + inv.billing_period_end"></td>
                                    <td>
                                        <span class="badge badge-no-dot"
                                              :class="invoiceBadgeClass(inv.status)"
                                              x-text="inv.status.replace('_',' ')"></span>
                                    </td>
                                    <td x-text="formatDate(inv.due_date)"></td>
                                    <td class="text-right font-mono" x-text="'$' + parseFloat(inv.total_amount).toFixed(2)"></td>
                                    <td class="text-right font-mono" x-text="'$' + parseFloat(inv.balance_due).toFixed(2)"></td>
                                    <td>
                                        <a :href="'<?= base_url('invoices/show') ?>?id=' + inv.id"
                                           class="btn btn-sm btn-secondary">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="tab-table-footer">
                    <span x-text="`Showing ${invoices.length} of ${invoicesTotal}`"></span>
                    <button class="btn btn-secondary btn-sm"
                            x-show="invoices.length < invoicesTotal"
                            :disabled="invoicesLoading"
                            @click="loadMoreInvoices()"
                            x-text="invoicesLoading ? 'Loading…' : 'Load more'">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: DAMAGE CLAIMS ─────────────────────────────────────── -->
    <div x-show="activeTab === 'damage_claims'" role="tabpanel">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span class="card-title">Damage Claims</span>
                <?php if (can('maintenance', 'create')): ?>
                <a href="<?= base_url('damage_claims/create') ?>?customer_id=<?= $customerId ?>"
                   class="btn btn-primary btn-sm">+ New Claim</a>
                <?php endif; ?>
            </div>

            <!-- Filter bar -->
            <div class="tab-filter-bar">
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="damageClaimsFilters.severity" @change="applyDamageClaimsFilters()">
                    <option value="">All Severities</option>
                    <option value="minor">Minor</option>
                    <option value="moderate">Moderate</option>
                    <option value="major">Major</option>
                    <option value="total_loss">Total Loss</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="damageClaimsFilters.status" @change="applyDamageClaimsFilters()">
                    <option value="">All Statuses</option>
                    <option value="reported">Reported</option>
                    <option value="assessed">Assessed</option>
                    <option value="repair_ordered">Repair Ordered</option>
                    <option value="invoiced">Invoiced</option>
                    <option value="resolved">Resolved</option>
                    <option value="written_off">Written Off</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="damageClaimsFilters.sort" @change="applyDamageClaimsFilters()">
                    <option value="created_at">Sort: Date Reported</option>
                    <option value="estimated_repair_cost">Sort: Est. Cost</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="damageClaimsFilters.dir" @change="applyDamageClaimsFilters()">
                    <option value="DESC">Newest First</option>
                    <option value="ASC">Oldest First</option>
                </select>
            </div>

            <!-- Loading -->
            <div x-show="damageClaimsLoading && damageClaims.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading damage claims…</span>
            </div>

            <!-- Empty state -->
            <div x-show="damageClaimsLoaded && !damageClaimsLoading && damageClaims.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No damage claims found</p>
                    <p class="empty-state-text">No claims match the current filters.</p>
                </div>
            </div>

            <!-- Table + footer -->
            <div x-show="damageClaims.length > 0">
                <div class="tab-table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Claim #</th>
                                <th>Unit</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th style="text-align:right;">Est. Cost</th>
                                <th>Reported</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="dc in damageClaims" :key="dc.id">
                                <tr>
                                    <td class="font-mono" x-text="dc.claim_number"></td>
                                    <td class="font-mono" x-text="dc.unit_number ?? '—'"></td>
                                    <td>
                                        <span class="badge" :class="dcSeverityBadge(dc.severity)"
                                              x-text="dcSeverityLabel(dc.severity)"></span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="dcStatusBadge(dc.status)"
                                              x-text="dcStatusLabel(dc.status)"></span>
                                    </td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="dc.estimated_repair_cost ? '$' + parseFloat(dc.estimated_repair_cost).toLocaleString('en-CA', {minimumFractionDigits:2}) : '—'"></td>
                                    <td x-text="dc.created_at ? dc.created_at.substring(0,10) : '—'"></td>
                                    <td>
                                        <a :href="'<?= base_url('damage_claims/show') ?>?id=' + dc.id"
                                           class="btn btn-sm btn-secondary">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="tab-table-footer">
                    <span x-text="`Showing ${damageClaims.length} of ${damageClaimsTotal}`"></span>
                    <button class="btn btn-secondary btn-sm"
                            x-show="damageClaims.length < damageClaimsTotal"
                            :disabled="damageClaimsLoading"
                            @click="loadMoreDamageClaims()"
                            x-text="damageClaimsLoading ? 'Loading…' : 'Load more'">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: MILEAGE LOGS ─────────────────────────────────────── -->
    <div x-show="activeTab === 'mileage_logs'" role="tabpanel">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span class="card-title">Mileage Logs</span>
                <a href="<?= base_url('mileage_logs') ?>"
                   class="btn btn-secondary btn-sm">View All</a>
            </div>

            <!-- Filter bar -->
            <div class="tab-filter-bar">
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="mileageLogsFilters.log_type" @change="applyMileageLogsFilters()">
                    <option value="">All Types</option>
                    <option value="manual">Manual</option>
                    <option value="gps_sync">GPS Sync</option>
                    <option value="lease_start">Lease Start</option>
                    <option value="lease_end">Lease End</option>
                    <option value="service">Service</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="mileageLogsFilters.dir" @change="applyMileageLogsFilters()">
                    <option value="DESC">Newest First</option>
                    <option value="ASC">Oldest First</option>
                </select>
            </div>

            <!-- Loading -->
            <div x-show="mileageLogsLoading && mileageLogs.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading mileage logs…</span>
            </div>

            <!-- Empty state -->
            <div x-show="mileageLogsLoaded && !mileageLogsLoading && mileageLogs.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No mileage logs found</p>
                    <p class="empty-state-text">No records match the current filters.</p>
                </div>
            </div>

            <!-- Table + footer -->
            <div x-show="mileageLogs.length > 0">
                <div class="tab-table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Unit</th>
                                <th>Odometer</th>
                                <th>Type</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="ml in mileageLogs" :key="ml.id">
                                <tr>
                                    <td x-text="formatDate(ml.log_date)"></td>
                                    <td class="font-mono" x-text="ml.unit_number"></td>
                                    <td class="font-mono" x-text="Number(ml.odometer_reading).toLocaleString('en-CA') + ' ' + (ml.mileage_unit === 'miles' ? 'mi' : 'km')"></td>
                                    <td>
                                        <span class="badge" :class="mlTypeBadge(ml.log_type)"
                                              x-text="mlTypeLabel(ml.log_type)"></span>
                                    </td>
                                    <td>
                                        <a :href="'<?= base_url('mileage_logs/show') ?>?id=' + ml.id"
                                           class="btn btn-sm btn-secondary">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="tab-table-footer">
                    <span x-text="`Showing ${mileageLogs.length} of ${mileageLogsTotal}`"></span>
                    <button class="btn btn-secondary btn-sm"
                            x-show="mileageLogs.length < mileageLogsTotal"
                            :disabled="mileageLogsLoading"
                            @click="loadMoreMileageLogs()"
                            x-text="mileageLogsLoading ? 'Loading…' : 'Load more'">
                    </button>
                </div>
            </div>
        </div>
    </div><!-- /mileage_logs tab -->

</div><!-- /x-data -->

<script>
function FF_CustomerProfile() {
    return {
        activeTab:           'overview',
        notes:               [],
        noteCount:           0,
        notesLoaded:         false,
        notesLoading:        false,
        newNote:             '',
        newNotePinned:       false,
        savingNote:          false,

        // ── Leases ────────────────────────────────────────────────
        leases:              [],
        leasesTotal:         0,
        leasesPage:          1,
        leasesLoaded:        false,
        leasesLoading:       false,
        leasesFilters:       { status: '', sort: 'created_at', dir: 'DESC' },

        // ── Invoices ──────────────────────────────────────────────
        invoices:            [],
        invoicesTotal:       0,
        invoicesPage:        1,
        invoicesLoaded:      false,
        invoicesLoading:     false,
        invoicesFilters:     { status: '', sort: 'created_at', dir: 'DESC' },

        // ── Damage Claims ─────────────────────────────────────────
        damageClaims:        [],
        damageClaimsTotal:   0,
        damageClaimsPage:    1,
        damageClaimsLoaded:  false,
        damageClaimsLoading: false,
        damageClaimsFilters: { severity: '', status: '', sort: 'created_at', dir: 'DESC' },

        // ── Mileage Logs ──────────────────────────────────────────
        mileageLogs:         [],
        mileageLogsTotal:    0,
        mileageLogsPage:     1,
        mileageLogsLoaded:   false,
        mileageLogsLoading:  false,
        mileageLogsFilters:  { log_type: '', sort: 'log_date', dir: 'DESC' },

        init() {
            this.loadNoteCount();
            this.$watch('activeTab', (tab) => {
                if (tab === 'leases'        && !this.leasesLoaded)       this.loadLeases();
                if (tab === 'invoices'      && !this.invoicesLoaded)     this.loadInvoices();
                if (tab === 'damage_claims' && !this.damageClaimsLoaded) this.loadDamageClaims();
                if (tab === 'mileage_logs'  && !this.mileageLogsLoaded)  this.loadMileageLogs();
            });
        },

        // ── Notes ──────────────────────────────────────────────────
        async loadNoteCount() {
            try {
                const res  = await fetch('<?= base_url('api/v1/customers/notes') ?>?customer_id=<?= $customerId ?>');
                const json = await res.json();
                if (json.success) this.noteCount = json.data.notes.length;
            } catch (e) { /* silent */ }
        },

        async loadNotes() {
            if (this.notesLoaded) return;
            this.notesLoading = true;
            try {
                const res  = await fetch('<?= base_url('api/v1/customers/notes') ?>?customer_id=<?= $customerId ?>');
                const json = await res.json();
                if (json.success) {
                    this.notes       = json.data.notes;
                    this.noteCount   = this.notes.length;
                    this.notesLoaded = true;
                }
            } catch (e) { /* silent */ }
            finally { this.notesLoading = false; }
        },

        async saveNote() {
            if (!this.newNote.trim()) return;
            this.savingNote = true;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const res  = await fetch('<?= base_url('api/v1/customers/notes/create') ?>', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ customer_id: <?= $customerId ?>, note: this.newNote.trim(), is_pinned: this.newNotePinned }),
                });
                const json = await res.json();
                if (res.ok && json.success) {
                    const newItem = {
                        id:              json.data.id,
                        customer_id:     json.data.customer_id,
                        note:            json.data.note,
                        is_pinned:       json.data.is_pinned,
                        created_by:      json.data.created_by,
                        created_by_name: '<?= e(addslashes(current_user()['name'] ?? '')) ?>',
                        created_at:      json.data.created_at,
                    };
                    // WHY: insert after last pinned so pinned notes stay at top
                    if (newItem.is_pinned) {
                        this.notes.unshift(newItem);
                    } else {
                        const lastPinned = this.notes.findLastIndex(n => n.is_pinned);
                        this.notes.splice(lastPinned + 1, 0, newItem);
                    }
                    this.noteCount     = this.notes.length;
                    this.newNote       = '';
                    this.newNotePinned = false;
                    this.notesLoaded   = true;
                } else {
                    alert(json.error?.message ?? 'Failed to save note.');
                }
            } catch (e) {
                alert('Network error. Please try again.');
            } finally {
                this.savingNote = false;
            }
        },

        // ── Leases ─────────────────────────────────────────────────
        async loadLeases(append = false) {
            this.leasesLoading = true;
            try {
                const p = new URLSearchParams({ customer_id: <?= $customerId ?>, per_page: 50, page: this.leasesPage, sort: this.leasesFilters.sort, dir: this.leasesFilters.dir });
                if (this.leasesFilters.status) p.set('status', this.leasesFilters.status);
                const json = await (await fetch('<?= base_url('api/v1/leases') ?>?' + p)).json();
                if (json.success) {
                    const items      = json.data.items || [];
                    this.leases      = append ? [...this.leases, ...items] : items;
                    this.leasesTotal = json.data.pagination?.total ?? items.length;
                    this.leasesLoaded = true;
                }
            } catch (e) { /* silent */ }
            this.leasesLoading = false;
        },
        loadMoreLeases()     { this.leasesPage++; this.loadLeases(true); },
        applyLeasesFilters() { this.leases = []; this.leasesPage = 1; this.leasesTotal = 0; this.leasesLoaded = false; this.loadLeases(); },

        // ── Invoices ───────────────────────────────────────────────
        async loadInvoices(append = false) {
            this.invoicesLoading = true;
            try {
                const p = new URLSearchParams({ customer_id: <?= $customerId ?>, per_page: 50, page: this.invoicesPage, sort: this.invoicesFilters.sort, dir: this.invoicesFilters.dir });
                if (this.invoicesFilters.status) p.set('status', this.invoicesFilters.status);
                const json = await (await fetch('<?= base_url('api/v1/invoices') ?>?' + p)).json();
                if (json.success) {
                    const items       = json.data.items || [];
                    this.invoices     = append ? [...this.invoices, ...items] : items;
                    this.invoicesTotal = json.data.pagination?.total ?? items.length;
                    this.invoicesLoaded = true;
                }
            } catch (e) { /* silent */ }
            this.invoicesLoading = false;
        },
        loadMoreInvoices()     { this.invoicesPage++; this.loadInvoices(true); },
        applyInvoicesFilters() { this.invoices = []; this.invoicesPage = 1; this.invoicesTotal = 0; this.invoicesLoaded = false; this.loadInvoices(); },

        // ── Damage Claims ──────────────────────────────────────────
        async loadDamageClaims(append = false) {
            this.damageClaimsLoading = true;
            try {
                const p = new URLSearchParams({ customer_id: <?= $customerId ?>, per_page: 50, page: this.damageClaimsPage, sort: this.damageClaimsFilters.sort, dir: this.damageClaimsFilters.dir });
                if (this.damageClaimsFilters.severity) p.set('severity', this.damageClaimsFilters.severity);
                if (this.damageClaimsFilters.status)   p.set('status',   this.damageClaimsFilters.status);
                const json = await (await fetch('<?= base_url('api/v1/damage_claims') ?>?' + p)).json();
                if (json.success) {
                    const items          = json.data?.items ?? [];
                    this.damageClaims    = append ? [...this.damageClaims, ...items] : items;
                    this.damageClaimsTotal  = json.data.pagination?.total ?? items.length;
                    this.damageClaimsLoaded = true;
                }
            } catch (e) { /* silent */ }
            this.damageClaimsLoading = false;
        },
        loadMoreDamageClaims()     { this.damageClaimsPage++; this.loadDamageClaims(true); },
        applyDamageClaimsFilters() { this.damageClaims = []; this.damageClaimsPage = 1; this.damageClaimsTotal = 0; this.damageClaimsLoaded = false; this.loadDamageClaims(); },

        // ── Mileage Logs ───────────────────────────────────────────
        async loadMileageLogs(append = false) {
            this.mileageLogsLoading = true;
            try {
                const p = new URLSearchParams({ customer_id: <?= $customerId ?>, per_page: 50, page: this.mileageLogsPage, sort: this.mileageLogsFilters.sort, dir: this.mileageLogsFilters.dir });
                if (this.mileageLogsFilters.log_type) p.set('log_type', this.mileageLogsFilters.log_type);
                const json = await (await fetch('<?= base_url('api/v1/mileage_logs/index') ?>?' + p)).json();
                if (json.success) {
                    const items          = json.data?.items ?? [];
                    this.mileageLogs     = append ? [...this.mileageLogs, ...items] : items;
                    this.mileageLogsTotal  = json.data.pagination?.total ?? items.length;
                    this.mileageLogsLoaded = true;
                }
            } catch (e) { /* silent */ }
            this.mileageLogsLoading = false;
        },
        loadMoreMileageLogs()     { this.mileageLogsPage++; this.loadMileageLogs(true); },
        applyMileageLogsFilters() { this.mileageLogs = []; this.mileageLogsPage = 1; this.mileageLogsTotal = 0; this.mileageLogsLoaded = false; this.loadMileageLogs(); },

        // ── Badge / format helpers (unchanged) ────────────────────
        mlTypeBadge(type) {
            const m = { manual:'badge-info', gps_sync:'badge-success', lease_start:'badge-neutral', lease_end:'badge-neutral', service:'badge-warning' };
            return 'badge ' + (m[type] ?? 'badge-neutral');
        },
        mlTypeLabel(type) {
            const m = { manual:'Manual', gps_sync:'GPS Sync', lease_start:'Lease Start', lease_end:'Lease End', service:'Service' };
            return m[type] ?? type;
        },
        dcSeverityBadge(s) {
            return { minor:'badge badge-info', moderate:'badge badge-warning', major:'badge badge-danger', total_loss:'badge badge-danger' }[s] ?? 'badge badge-neutral';
        },
        dcSeverityLabel(s) {
            return { minor:'Minor', moderate:'Moderate', major:'Major', total_loss:'Total Loss' }[s] ?? s;
        },
        dcStatusBadge(s) {
            return { reported:'badge badge-info', assessed:'badge badge-warning', repair_ordered:'badge badge-warning',
                     invoiced:'badge badge-purple', resolved:'badge badge-success', written_off:'badge badge-neutral' }[s] ?? 'badge badge-neutral';
        },
        dcStatusLabel(s) {
            return { reported:'Reported', assessed:'Assessed', repair_ordered:'Repair Ordered',
                     invoiced:'Invoiced', resolved:'Resolved', written_off:'Written Off' }[s] ?? s;
        },
        leaseBadgeClass(status) {
            const m = { active:'badge-success', pending:'badge-info', completed:'badge-neutral', cancelled:'badge-danger' };
            return m[status] || 'badge-neutral';
        },
        invoiceBadgeClass(status) {
            const m = { draft:'badge-neutral', sent:'badge-info', paid:'badge-success',
                        partially_paid:'badge-warning', overdue:'badge-danger',
                        void:'badge-neutral', written_off:'badge-danger' };
            return m[status] || 'badge-neutral';
        },
        formatDate(dt) {
            if (!dt) return '';
            try {
                return new Date(dt.replace(' ', 'T') + 'Z')
                    .toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
            } catch (e) { return dt; }
        },
    };
}

// Global: called from confirm modal callback
async function deleteCustomer(id) {
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const res  = await fetch('<?= base_url('api/v1/customers/delete') ?>', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ id }),
        });
        const json = await res.json();
        if (res.ok && json.success) {
            window.location.href = '<?= base_url('customers') ?>';
        } else {
            alert(json.error?.message ?? 'Failed to delete customer.');
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
