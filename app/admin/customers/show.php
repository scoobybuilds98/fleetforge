<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Profile Page
 *
 * @file        app/admin/customers/show.php
 * @description Customer profile page. Header shows key summary (name, status,
 *              risk, tags). Body has 4 tabs: Overview (detail cards), Notes
 *              (pinned + all), Leases (stub), Invoices (stub). Notes are loaded
 *              via Alpine.js from the notes API. Delete button fires soft-delete
 *              via confirm modal.
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
<div class="page-header">
    <div>
        <div style="margin-bottom:8px;">
            <a href="<?= base_url('customers') ?>" class="btn btn-ghost btn-sm">← Customers</a>
        </div>
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

    <!-- Tab nav — inline styles since no tab CSS classes exist in app.css -->
    <div role="tablist"
         style="display:flex; border-bottom:1px solid var(--border-color); margin-bottom:24px; gap:0; overflow-x:auto;">

        <button role="tab"
                style="padding:10px 20px; background:none; border:none; border-bottom:2px solid transparent; cursor:pointer; font-size:0.875rem; font-weight:500; white-space:nowrap; transition:color 0.15s, border-color 0.15s;"
                :style="activeTab === 'overview'
                    ? 'border-bottom-color:var(--color-primary); color:var(--color-primary); margin-bottom:-1px;'
                    : 'color:var(--text-secondary); margin-bottom:-1px;'"
                @click="activeTab = 'overview'"
                :aria-selected="activeTab === 'overview'">
            Overview
        </button>

        <button role="tab"
                style="padding:10px 20px; background:none; border:none; border-bottom:2px solid transparent; cursor:pointer; font-size:0.875rem; font-weight:500; white-space:nowrap; transition:color 0.15s, border-color 0.15s;"
                :style="activeTab === 'notes'
                    ? 'border-bottom-color:var(--color-primary); color:var(--color-primary); margin-bottom:-1px;'
                    : 'color:var(--text-secondary); margin-bottom:-1px;'"
                @click="activeTab = 'notes'; loadNotes()"
                :aria-selected="activeTab === 'notes'">
            Notes
            <span x-show="noteCount > 0"
                  x-text="'(' + noteCount + ')'"
                  style="margin-left:4px; font-size:0.8125rem; opacity:0.75;"></span>
        </button>

        <button role="tab"
                style="padding:10px 20px; background:none; border:none; border-bottom:2px solid transparent; cursor:pointer; font-size:0.875rem; font-weight:500; white-space:nowrap; transition:color 0.15s, border-color 0.15s;"
                :style="activeTab === 'leases'
                    ? 'border-bottom-color:var(--color-primary); color:var(--color-primary); margin-bottom:-1px;'
                    : 'color:var(--text-secondary); margin-bottom:-1px;'"
                @click="activeTab = 'leases'"
                :aria-selected="activeTab === 'leases'">
            Leases
            <span style="margin-left:4px; font-size:0.8125rem; opacity:0.75;">(<?= e($customer['lease_count']) ?>)</span>
        </button>

        <button role="tab"
                style="padding:10px 20px; background:none; border:none; border-bottom:2px solid transparent; cursor:pointer; font-size:0.875rem; font-weight:500; white-space:nowrap; transition:color 0.15s, border-color 0.15s;"
                :style="activeTab === 'invoices'
                    ? 'border-bottom-color:var(--color-primary); color:var(--color-primary); margin-bottom:-1px;'
                    : 'color:var(--text-secondary); margin-bottom:-1px;'"
                @click="activeTab = 'invoices'"
                :aria-selected="activeTab === 'invoices'">
            Invoices
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

    <!-- ── TAB: LEASES (stub) ─────────────────────────────────── -->
    <div x-show="activeTab === 'leases'" role="tabpanel">
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/></svg>
                    </div>
                    <p class="empty-state-title">Leases</p>
                    <p class="empty-state-text">Lease list for this customer will be available in S006.</p>
                    <a href="<?= base_url('leases') ?>?customer_id=<?= $customerId ?>"
                       class="btn btn-secondary btn-sm">View All Leases</a>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: INVOICES (stub) ───────────────────────────────── -->
    <div x-show="activeTab === 'invoices'" role="tabpanel">
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/></svg>
                    </div>
                    <p class="empty-state-title">Invoices</p>
                    <p class="empty-state-text">Invoice list for this customer will be available in a later session.</p>
                    <a href="<?= base_url('invoices') ?>?customer_id=<?= $customerId ?>"
                       class="btn btn-secondary btn-sm">View All Invoices</a>
                </div>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_CustomerProfile() {
    return {
        activeTab:     'overview',
        notes:         [],
        noteCount:     0,
        notesLoaded:   false,
        notesLoading:  false,
        newNote:       '',
        newNotePinned: false,
        savingNote:    false,

        init() {
            // Pre-load note count for tab badge without loading full content
            this.loadNoteCount();
        },

        async loadNoteCount() {
            try {
                const res  = await fetch('<?= base_url('api/v1/customers/notes') ?>?customer_id=<?= $customerId ?>');
                const json = await res.json();
                if (json.success) {
                    this.noteCount = json.data.notes.length;
                }
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
            } catch (e) {
                /* silent — empty state shown */
            } finally {
                this.notesLoading = false;
            }
        },

        async saveNote() {
            if (!this.newNote.trim()) return;
            this.savingNote = true;

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const res  = await fetch('<?= base_url('api/v1/customers/notes/create') ?>', {
                    method:  'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-Token':     csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        customer_id: <?= $customerId ?>,
                        note:        this.newNote.trim(),
                        is_pinned:   this.newNotePinned,
                    }),
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

        formatDate(dt) {
            if (!dt) return '';
            try {
                return new Date(dt.replace(' ', 'T') + 'Z')
                    .toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
            } catch (e) {
                return dt;
            }
        },
    };
}

// Global: called from confirm modal callback
async function deleteCustomer(id) {
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const res  = await fetch('<?= base_url('api/v1/customers/delete') ?>', {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-Token':     csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
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
