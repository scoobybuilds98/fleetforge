<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Profile Page
 *
 * @file        app/admin/customers/show.php
 * @description Customer profile page. Header shows key summary (name, status,
 *              risk, tags). Body has tabs: Overview, Notes, Leases, Invoices,
 *              Damage Claims, Mileage Logs, Rates (customer rate overrides),
 *              Documents (uploaded files via polymorphic documents table).
 *              All tabs lazy-load on first activation. Rates tab supports inline
 *              Add/Edit/Delete of customer_equipment_rates via upsert + delete APIs.
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

// ── QBO mapping (S-QBO-6) ─────────────────────────────────────
// Drives the QuickBooks badge in the page header. Only renders
// when the connection is established (no point teasing the feature
// pre-setup). Separate query to keep the customer SELECT untouched.
$qboMapping = null;
if ((string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected') {
    $qboMapping = db_row(
        "SELECT id, qbo_customer_id, mapping_status, last_synced_at, last_push_at
           FROM acc_qbo_customer_map
          WHERE ff_customer_id = ?",
        [$customerId]
    );
}

// Load tags
$tagRows = db_select(
    "SELECT tag FROM customer_tags WHERE customer_id = ? ORDER BY tag",
    [$customerId]
);

// Pre-count rate overrides for tab badge
$rateOverridesCount = (int) db_row(
    "SELECT COUNT(*) AS n FROM customer_equipment_rates WHERE customer_id = ?",
    [$customerId]
)['n'];
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

$pageTitle      = $customer['company_name'];
$helpModuleSlug = 'customers';
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
            <?php /* QBO mapping badge — S-QBO-6. Only shown when the
                     connection is established AND a mapping row exists.
                     Status drives the color: mapped=success (linked both
                     sides), ff_only=warning (not pushed yet), qbo_only=
                     info (QBO has but FF doesn't link — operator should
                     resolve via /quickbooks/customers), ignored=neutral. */ ?>
            <?php if ($qboMapping !== null):
                $qm_status = (string) ($qboMapping['mapping_status'] ?? 'qbo_only');
                $qm_class  = match ($qm_status) {
                    'mapped'   => 'badge-success',
                    'ff_only'  => 'badge-warning',
                    'qbo_only' => 'badge-info',
                    'ignored'  => 'badge-neutral',
                    default    => 'badge-neutral',
                };
                $qm_label  = match ($qm_status) {
                    'mapped'   => 'QuickBooks: Synced',
                    'ff_only'  => 'QuickBooks: Not synced',
                    'qbo_only' => 'QuickBooks: Linked from QBO side',
                    'ignored'  => 'QuickBooks: Excluded',
                    default    => 'QuickBooks',
                };
                $qm_title = $qboMapping['qbo_customer_id'] ? 'qbo#' . $qboMapping['qbo_customer_id'] : '';
                if (!empty($qboMapping['last_synced_at'])) {
                    $qm_title .= ($qm_title !== '' ? ' · ' : '') . 'last synced ' . $qboMapping['last_synced_at'];
                }
            ?>
            <a href="<?= base_url('quickbooks/customers') ?>?q=<?= e(rawurlencode($customer['company_name'])) ?>"
               class="badge <?= $qm_class ?>"
               title="<?= e($qm_title) ?>"
               style="text-decoration:none;">
                <?= e($qm_label) ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <?= help_button('customers') ?>
        <?php if (can('customers', 'create')): /* EMAIL-1: send-email button */ ?>
        <button type="button"
                class="btn btn-secondary btn-sm"
                onclick="openEmailCompose({
                    customerId: <?= (int)$customer['id'] ?>,
                    toEmail:    <?= e(json_encode((string)($customer['email'] ?? ''))) ?>,
                    toName:     <?= e(json_encode((string)($customer['contact_name'] ?? $customer['company_name']))) ?>
                })"
                title="Send email to this customer">
            <?= heroicon('envelope', 'btn-icon') ?>
            Send Email
        </button>
        <?php endif; ?>
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

<!-- ── AI Summary Card ──────────────────────────────────────── -->
<?php
$aiSummaryEntityType = 'customer';
$aiSummaryEntityId   = $customer['id'];
$aiSummaryType       = 'customer_insights';
$aiSummaryTitle      = 'AI Customer Insights';
include FF_ROOT . '/includes/partials/ai-summary-card.php';
?>

<!-- ============================================================
     TABS — Alpine.js tab switcher
     STATS ROW is now INSIDE the Alpine scope so every tile can
     drive activeTab. TILES-2: each tile is a drill-down that
     switches to the matching profile tab (Leases / Invoices /
     Documents) instead of being display-only.
     ============================================================ -->
<div x-data="FF_CustomerProfile()" x-init="init()">

<!-- ============================================================
     STATS ROW — 4 clickable quick-stat tiles (TILES-2)
     ============================================================ -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card" style="cursor:pointer"
         :class="{ 'ring-active': activeTab === 'leases' }"
         @click="activeTab = 'leases'"
         title="View active leases for this customer">
        <div class="stat-label">Active Leases</div>
        <div class="stat-value font-mono"><?= e($customer['active_lease_count']) ?></div>
        <div class="stat-delta text-secondary">of <?= e($customer['lease_count']) ?> total</div>
    </div>

    <div class="stat-card" style="cursor:pointer"
         :class="{ 'ring-active': activeTab === 'invoices' }"
         @click="activeTab = 'invoices'"
         title="View outstanding invoices">
        <div class="stat-label">Outstanding Balance</div>
        <div class="stat-value currency"><?= e(format_currency($customer['outstanding_balance'])) ?></div>
    </div>

    <!-- Total Revenue drills to the system-wide revenue report scoped to
         this customer so the user can see the full payment history, not
         just what's visible on the profile page. -->
    <a class="stat-card"
       href="<?= base_url('reports') ?>?tab=customer&customer_id=<?= (int)$customer['id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="View this customer's revenue report">
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value currency"><?= e(format_currency($customer['total_revenue'])) ?></div>
    </a>

    <a class="stat-card"
       href="<?= base_url('credit_notes') ?>?customer_id=<?= (int)$customer['id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="View credit notes for this customer">
        <div class="stat-label">Account Credit</div>
        <div class="stat-value currency"><?= e(format_currency($customer['account_credit_balance'])) ?></div>
    </a>

</div>

    <!-- Tab nav -->
    <div class="tab-bar" role="tablist">
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'overview' }"
                @click="activeTab = 'overview'" :aria-selected="activeTab === 'overview'" role="tab">
            Overview
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'notes' }"
                @click="activeTab = 'notes'; loadNotes()" :aria-selected="activeTab === 'notes'" role="tab">
            Notes
            <span class="tab-badge" x-show="tabCounts.notes > 0" x-text="tabCounts.notes"></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'leases' }"
                @click="activeTab = 'leases'" :aria-selected="activeTab === 'leases'" role="tab">
            Leases
            <span class="tab-badge" x-show="tabCounts.leases > 0" x-text="tabCounts.leases"></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'invoices' }"
                @click="activeTab = 'invoices'" :aria-selected="activeTab === 'invoices'" role="tab">
            Invoices
            <span class="tab-badge" x-show="tabCounts.invoices > 0" x-text="tabCounts.invoices"></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'damage_claims' }"
                @click="activeTab = 'damage_claims'" :aria-selected="activeTab === 'damage_claims'" role="tab">
            Damage Claims
            <span class="tab-badge" x-show="tabCounts.damage_claims > 0" x-text="tabCounts.damage_claims"></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'mileage_logs' }"
                @click="activeTab = 'mileage_logs'" :aria-selected="activeTab === 'mileage_logs'" role="tab">
            Mileage Logs
            <span class="tab-badge" x-show="tabCounts.mileage_logs > 0" x-text="tabCounts.mileage_logs"></span>
        </button>
        <?php if (can('rates', 'view')): ?>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'rates' }"
                @click="activeTab = 'rates'" :aria-selected="activeTab === 'rates'" role="tab">
            Rates
            <span class="tab-badge" x-show="tabCounts.rates > 0" x-text="tabCounts.rates"></span>
        </button>
        <?php endif; ?>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'documents' }"
                @click="activeTab = 'documents'" :aria-selected="activeTab === 'documents'" role="tab">
            Documents
            <span class="tab-badge" x-show="tabCounts.documents > 0" x-text="tabCounts.documents"></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'emails' }"
                @click="activeTab = 'emails'; loadEmails()" :aria-selected="activeTab === 'emails'" role="tab">
            Email History
            <span class="tab-badge" x-show="tabCounts.emails > 0" x-text="tabCounts.emails"></span>
        </button>
    </div>

    <!-- ── TAB: OVERVIEW ──────────────────────────────────────── -->
    <div x-show="activeTab === 'overview'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" role="tabpanel">
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
                        <!-- S-ACCT-GPS: per-customer presentation policy (ASPE 3400). -->
                        <dt class="text-secondary text-sm">GPS Revenue Presentation</dt>
                        <dd style="margin:0;" x-data="gpsPresentationToggle(<?= (int) $customer['id'] ?>, <?= json_encode((string) ($customer['gps_revenue_presentation'] ?? 'net')) ?>)">
                            <span x-show="!editing" x-cloak>
                                <span :class="current === 'gross' ? 'badge badge-warning' : 'badge badge-success'"
                                      style="padding:2px 10px;text-transform:uppercase;font-size:0.6875rem;"
                                      x-text="current === 'gross' ? 'Gross (Principal)' : 'Net (Agent)'"></span>
                                <?php if (can('customers','edit')): ?>
                                <button type="button" class="btn btn-ghost btn-xs" @click="editing = true" style="margin-left:6px;">Edit</button>
                                <?php endif; ?>
                            </span>
                            <span x-show="editing" x-cloak>
                                <label style="display:inline-flex;align-items:center;gap:4px;font-size:0.8125rem;margin-right:8px;">
                                    <input type="radio" value="net" x-model="draft"> Net (Agent)
                                </label>
                                <label style="display:inline-flex;align-items:center;gap:4px;font-size:0.8125rem;margin-right:8px;">
                                    <input type="radio" value="gross" x-model="draft"> Gross (Principal)
                                </label>
                                <button type="button" class="btn btn-primary btn-xs" :disabled="saving" @click="save()">
                                    <span x-show="!saving">Save</span><span x-show="saving">Saving...</span>
                                </button>
                                <button type="button" class="btn btn-ghost btn-xs" @click="editing = false; draft = current;">Cancel</button>
                            </span>
                            <details style="margin-top:6px;font-size:0.7rem;color:var(--text-secondary);">
                                <summary style="cursor:pointer;">ASPE 3400 rule</summary>
                                <div style="margin-top:4px;max-width:520px;">
                                    Present as <strong>agent (net)</strong> when Mainland is primarily arranging GPS access via Samsara —
                                    only the margin is recognised as revenue. Present as <strong>principal (gross)</strong> if Mainland
                                    provides significant independent value (e.g. custom telematics services). Default is <strong>net</strong>
                                    per the standard's agent-favouring guidance.
                                </div>
                            </details>
                        </dd>
                    </dl>
                </div>
            </div>

            <script>
            // S-ACCT-GPS: inline toggle. Posts to customers/update.php; reloads on success.
            function gpsPresentationToggle(customerId, initial) {
                return {
                    customerId, current: initial, draft: initial,
                    editing: false, saving: false,
                    async save() {
                        this.saving = true;
                        try {
                            const r = await FF_Api.post('<?= base_url('api/v1/customers/update') ?>', {
                                id: this.customerId,
                                gps_revenue_presentation: this.draft,
                            });
                            if (r.success) {
                                this.current = this.draft;
                                this.editing = false;
                                FF_Toast.success('GPS presentation updated.');
                            } else {
                                FF_Toast.error((r.error && r.error.message) || 'Save failed.');
                            }
                        } catch (e) { FF_Toast.error('Network error.'); }
                        this.saving = false;
                    },
                };
            }
            </script>

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
    <div x-show="activeTab === 'notes'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" role="tabpanel">

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
    <div x-show="activeTab === 'leases'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" role="tabpanel">
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
                    <div class="table-responsive">
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
    <div x-show="activeTab === 'invoices'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" role="tabpanel">
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
                    <div class="table-responsive">
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
    <div x-show="activeTab === 'damage_claims'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" role="tabpanel">
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
                    <div class="table-responsive">
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
    <div x-show="activeTab === 'mileage_logs'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" role="tabpanel">
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
                    <div class="table-responsive">
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

    <!-- ── TAB: RATES ────────────────────────────────────────── -->
    <?php if (can('rates', 'view')): ?>
    <div x-show="activeTab === 'rates'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" role="tabpanel">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span class="card-title">Custom Rate Overrides</span>
                <?php if (can('rates', 'edit')): ?>
                <button type="button" class="btn btn-primary btn-sm"
                        @click="openRateModal()">+ Add Override</button>
                <?php endif; ?>
            </div>

            <!-- Loading -->
            <div x-show="rateOverridesLoading && rateOverrides.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading rate overrides…</span>
            </div>

            <!-- Empty state -->
            <div x-show="rateOverridesLoaded && !rateOverridesLoading && rateOverrides.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No custom rate overrides</p>
                    <p class="empty-state-text">
                        This customer uses the standard rate card. Add an override to set custom pricing per equipment type.
                    </p>
                </div>
            </div>

            <!-- Table -->
            <div x-show="rateOverrides.length > 0">
                <div class="tab-table-container">
                    <div class="table-responsive">
<table class="table">
                        <thead>
                            <tr>
                                <th>Equipment Type</th>
                                <th style="text-align:right;">Daily</th>
                                <th style="text-align:right;">Weekly</th>
                                <th style="text-align:right;">Monthly</th>
                                <th style="text-align:right;">Mileage</th>
                                <th>Currency</th>
                                <th>Effective</th>
                                <?php if (can('rates', 'edit') || can('rates', 'delete')): ?>
                                <th></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="r in rateOverrides" :key="r.id">
                                <tr>
                                    <td x-text="r.equipment_type"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="r.daily_rate ? '$' + parseFloat(r.daily_rate).toFixed(2) : '—'"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="r.weekly_rate ? '$' + parseFloat(r.weekly_rate).toFixed(2) : '—'"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="r.monthly_rate ? '$' + parseFloat(r.monthly_rate).toFixed(2) : '—'"></td>
                                    <td class="font-mono" style="text-align:right;">
                                        <span x-show="r.mileage_rate" x-text="'$' + parseFloat(r.mileage_rate).toFixed(4) + '/' + r.mileage_unit"></span>
                                        <span x-show="!r.mileage_rate">—</span>
                                    </td>
                                    <td x-text="r.currency"></td>
                                    <td class="text-sm text-secondary">
                                        <span x-text="r.effective_from"></span>
                                        <span x-show="r.effective_to"> → <span x-text="r.effective_to"></span></span>
                                        <span x-show="!r.effective_to"> → open</span>
                                    </td>
                                    <?php if (can('rates', 'edit') || can('rates', 'delete')): ?>
                                    <td style="white-space:nowrap;">
                                        <?php if (can('rates', 'edit')): ?>
                                        <button type="button" class="btn btn-sm btn-secondary"
                                                @click="openRateModal(r)">Edit</button>
                                        <?php endif; ?>
                                        <?php if (can('rates', 'delete')): ?>
                                        <button type="button" class="btn btn-sm btn-danger"
                                                @click="deleteRateOverride(r)"
                                                style="margin-left:4px;">Delete</button>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            </template>
                        </tbody>
                    </table>
</div>
                </div>
                <div class="tab-table-footer">
                    <span x-text="`${rateOverrides.length} override${rateOverrides.length !== 1 ? 's' : ''}`"></span>
                </div>
            </div>
        </div>
    </div><!-- /rates tab -->

    <!-- ── Rate Override Modal ──────────────────────────────── -->
    <?php if (can('rates', 'edit')): ?>
    <div class="modal-overlay" x-show="rateModal.open" x-cloak
         style="background:rgba(0,0,0,0.5);">
        <div class="modal" style="width:560px;max-width:95vw;max-height:90vh;overflow-y:auto;" @click.stop>
            <div class="modal-header">
                <h3 class="modal-title" x-text="rateModal.id ? 'Edit Rate Override' : 'Add Rate Override'"></h3>
                <button class="modal-close-btn" aria-label="Close" @click="rateModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <div x-show="rateModal.error" class="alert alert-danger" x-text="rateModal.error" style="margin-bottom:12px;"></div>

                <div class="form-grid">
                    <!-- Equipment Type -->
                    <div class="form-group form-group--full">
                        <label class="form-label">Equipment Type <span class="text-danger">*</span></label>
                        <select class="form-select" x-model="rateModal.form.equipment_type" :disabled="!!rateModal.id">
                            <option value="">— Select —</option>
                            <?php
                            $templates = db_select("SELECT name FROM equipment_templates WHERE is_active=1 AND deleted_at IS NULL ORDER BY name");
                            foreach ($templates as $t):
                            ?>
                            <option value="<?= e($t['name']) ?>"><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Effective From / To -->
                    <div class="form-group">
                        <label class="form-label">Effective From <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" x-model="rateModal.form.effective_from"
                               :disabled="!!rateModal.id">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Effective To</label>
                        <?php // [UI-AUDIT-1:M18] :min constrains the picker so the user can't choose a date before Effective From. ?>
                        <input type="date" class="form-control" x-model="rateModal.form.effective_to"
                               :min="rateModal.form.effective_from || ''">
                        <div class="form-hint">Leave blank for open-ended.</div>
                    </div>

                    <!-- Rates -->
                    <div class="form-group">
                        <label class="form-label">Daily Rate</label>
                        <input type="number" class="form-control font-mono" x-model="rateModal.form.daily_rate"
                               step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Weekly Rate</label>
                        <input type="number" class="form-control font-mono" x-model="rateModal.form.weekly_rate"
                               step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Monthly Rate</label>
                        <input type="number" class="form-control font-mono" x-model="rateModal.form.monthly_rate"
                               step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mileage Rate</label>
                        <input type="number" class="form-control font-mono" x-model="rateModal.form.mileage_rate"
                               step="0.0001" min="0" placeholder="0.0000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mileage Unit</label>
                        <select class="form-select" x-model="rateModal.form.mileage_unit">
                            <option value="km">km</option>
                            <option value="miles">miles</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Currency</label>
                        <select class="form-select" x-model="rateModal.form.currency">
                            <option value="CAD">CAD</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>

                    <!-- Notes -->
                    <div class="form-group form-group--full">
                        <label class="form-label">Notes</label>
                        <input type="text" class="form-control" x-model="rateModal.form.notes"
                               maxlength="2000" placeholder="Optional notes about this override">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" @click="rateModal.open = false">Cancel</button>
                <button type="button" class="btn btn-primary"
                        :disabled="rateModal.saving"
                        @click="saveRateOverride()"
                        x-text="rateModal.saving ? 'Saving…' : (rateModal.id ? 'Save Changes' : 'Add Override')">
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ── TAB: Documents ───────────────────────────────────────── -->
    <div x-show="activeTab === 'documents'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" role="tabpanel">

        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="card-title">Customer Documents</h3>
                <?php if (can('customers', 'edit')): ?>
                <button class="btn btn-sm btn-primary"
                        @click="openDocUploadModal('customer', <?= (int)$customerId ?>)">+ Upload</button>
                <?php endif; ?>
            </div>

            <!-- Loading -->
            <div x-show="docsLoading && documents.length === 0" class="card-body"
                 style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading documents…</span>
            </div>

            <!-- Empty -->
            <div x-show="docsLoaded && !docsLoading && documents.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No documents</p>
                    <p class="empty-state-text">Upload tax exemptions, credit agreements, or other files.</p>
                </div>
            </div>

            <!-- Table -->
            <div x-show="documents.length > 0" class="tab-table-container">
                <div class="table-responsive">
<table class="table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Title / File</th>
                            <th>Size</th>
                            <th>Expires</th>
                            <th>Uploaded</th>
                            <th>By</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="doc in documents" :key="doc.id">
                            <tr>
                                <td>
                                    <span :class="customerDocTypeBadge(doc.document_type)"
                                          x-text="customerDocTypeLabel(doc.document_type)"></span>
                                </td>
                                <td>
                                    <div x-text="doc.title"></div>
                                    <div class="font-mono text-sm text-secondary" x-text="doc.file_name"></div>
                                </td>
                                <td class="font-mono text-sm"
                                    x-text="doc.file_size_kb ? doc.file_size_kb + ' KB' : '—'"></td>
                                <td :class="docExpiryClass(doc.expiration_date)"
                                    x-text="doc.expiration_date ? formatDate(doc.expiration_date) : '—'"></td>
                                <td class="text-sm" x-text="formatDate(doc.uploaded_at)"></td>
                                <td class="text-sm text-secondary" x-text="doc.uploaded_by_name || '—'"></td>
                                <td style="white-space:nowrap;">
                                    <a :href="doc.url" target="_blank" rel="noopener"
                                       class="btn btn-xs btn-ghost">View</a>
                                    <?php if (can('customers', 'edit')): ?>
                                    <button class="btn btn-xs btn-outline-danger"
                                            @click="confirmDeleteDoc(doc)"
                                            style="margin-left:4px;">Remove</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
</div>
            </div>
            <div x-show="documents.length > 0" class="tab-table-footer">
                <span x-text="documents.length + ' document' + (documents.length !== 1 ? 's' : '')"></span>
            </div>
        </div>

    </div><!-- /documents tab -->

    <!-- ── TAB: EMAIL HISTORY (EMAIL-1) ─────────────────────────── -->
    <div x-show="activeTab === 'emails'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" role="tabpanel">

        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="card-title">Email History</h3>
                <?php if (can('customers', 'create')): ?>
                <button class="btn btn-sm btn-primary"
                        onclick="openEmailCompose({
                            customerId: <?= (int)$customer['id'] ?>,
                            toEmail:    <?= e(json_encode((string)($customer['email'] ?? ''))) ?>,
                            toName:     <?= e(json_encode((string)($customer['contact_name'] ?? $customer['company_name']))) ?>
                        })">
                    + Compose Email
                </button>
                <?php endif; ?>
            </div>

            <div x-show="emailsLoading && emails.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading email history…</span>
            </div>

            <div x-show="emailsLoaded && !emailsLoading && emails.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No emails sent</p>
                    <p class="empty-state-text">Use the Compose Email button above to send your first email to this customer.</p>
                </div>
            </div>

            <div x-show="emailsLoaded && emails.length > 0" class="tab-table-container">
                <div class="table-responsive">
<table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>To</th>
                            <th>Subject</th>
                            <th>Template</th>
                            <th>Status</th>
                            <th>Sent by</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="email in emails" :key="email.id">
                            <tr>
                                <td class="text-sm" x-text="formatEmailDate(email.created_at)"></td>
                                <td class="text-sm">
                                    <div x-text="email.to_name || email.to_email"></div>
                                    <div class="text-secondary text-sm" x-show="email.to_name" x-text="email.to_email"></div>
                                </td>
                                <td>
                                    <span x-text="email.subject"></span>
                                    <span class="badge badge-neutral ml-2" x-show="email.attachment_count > 0"
                                          x-text="email.attachment_count + ' file' + (email.attachment_count > 1 ? 's' : '')"></span>
                                </td>
                                <td class="text-sm text-secondary" x-text="email.template_name || '—'"></td>
                                <td>
                                    <span class="badge" :class="'badge-' + email.status_class" x-text="email.status"></span>
                                </td>
                                <td class="text-sm text-secondary" x-text="email.sent_by_name || '—'"></td>
                                <td>
                                    <button class="btn btn-xs btn-ghost" @click="viewEmail(email.id)">View</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
</div>
            </div>
        </div>

        <!-- Email view modal -->
        <div x-show="emailViewModal.open" x-cloak class="modal-overlay" style="z-index:var(--z-modal);">
            <div class="modal-backdrop" @click="emailViewModal.open = false"></div>
            <div class="modal modal-lg" @click.stop style="max-height:calc(100vh - 32px);">
                <div class="modal-header">
                    <h3 class="modal-title">Email Details</h3>
                    <button class="modal-close-btn" aria-label="Close" @click="emailViewModal.open = false">×</button>
                </div>
                <div class="modal-body">
                    <div x-show="emailViewModal.loading" class="text-center" style="padding:32px;">Loading…</div>
                    <template x-if="emailViewModal.log && !emailViewModal.loading">
                        <div>
                            <dl style="display:grid; grid-template-columns:max-content 1fr; gap:6px 16px; margin:0 0 12px 0;">
                                <dt class="text-secondary text-sm">From:</dt>
                                <dd style="margin:0;" x-text="emailViewModal.log.from_email"></dd>
                                <dt class="text-secondary text-sm">To:</dt>
                                <dd style="margin:0;" x-text="(emailViewModal.log.to_name ? emailViewModal.log.to_name + ' &lt;' : '') + emailViewModal.log.to_email + (emailViewModal.log.to_name ? '&gt;' : '')"></dd>
                                <dt class="text-secondary text-sm">Subject:</dt>
                                <dd style="margin:0; font-weight:600;" x-text="emailViewModal.log.subject"></dd>
                                <dt class="text-secondary text-sm">Status:</dt>
                                <dd style="margin:0;"><span class="badge" :class="'badge-' + (emailViewModal.log.status === 'sent' ? 'success' : (emailViewModal.log.status === 'failed' ? 'danger' : 'warning'))" x-text="emailViewModal.log.status"></span></dd>
                                <dt class="text-secondary text-sm">Sent at:</dt>
                                <dd style="margin:0;" x-text="formatEmailDate(emailViewModal.log.sent_at || emailViewModal.log.created_at)"></dd>
                                <template x-if="emailViewModal.log.error_message">
                                    <dt class="text-secondary text-sm">Error:</dt>
                                </template>
                                <template x-if="emailViewModal.log.error_message">
                                    <dd style="margin:0;color:var(--color-danger);" x-text="emailViewModal.log.error_message"></dd>
                                </template>
                            </dl>
                            <template x-if="emailViewModal.log.attachments && emailViewModal.log.attachments.length > 0">
                                <div style="margin-bottom:12px;">
                                    <div class="text-secondary text-sm" style="margin-bottom:4px;">Attachments:</div>
                                    <template x-for="att in emailViewModal.log.attachments">
                                        <div class="email-attachment-item">
                                            <span class="email-attachment-name" x-text="att.file_name"></span>
                                            <span class="email-attachment-size" x-text="att.file_size ? Math.round(att.file_size/1024) + ' KB' : ''"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <div class="email-preview-frame">
                                <div class="email-preview-label">Body</div>
                                <div class="email-preview-body" x-html="emailViewModal.log.body_html"></div>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary btn-sm" @click="emailViewModal.open = false">Close</button>
                </div>
            </div>
        </div>

    </div><!-- /emails tab -->

    <!-- ── Document Upload Modal ────────────────────────────────── -->
    <?php if (can('customers', 'edit')): ?>
    <div x-show="docUploadModal.open" x-cloak
         class="modal-overlay"
         style="background:rgba(0,0,0,0.5);"
         @click.self="docUploadModal.open = false">
        <div class="modal" style="width:480px;max-width:95vw;max-height:90vh;overflow-y:auto;" @click.stop>
            <div class="modal-header">
                <h3 class="modal-title">Upload Document</h3>
                <button class="modal-close-btn" aria-label="Close" @click="docUploadModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <div x-show="docUploadModal.error" class="alert alert-danger"
                     x-text="docUploadModal.error" style="margin-bottom:12px;"></div>

                <div class="form-grid">
                    <!-- Document Type -->
                    <div class="form-group form-group--full">
                        <label class="form-label">Document Type <span class="text-danger">*</span></label>
                        <select class="form-select" x-model="docUploadModal.document_type">
                            <option value="">— Select —</option>
                            <option value="tax_exemption">Tax Exemption Certificate</option>
                            <option value="credit_agreement">Credit Agreement</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <!-- Title -->
                    <div class="form-group form-group--full">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-control" x-model="docUploadModal.title"
                               maxlength="255" placeholder="Optional — defaults to document type name">
                    </div>
                    <!-- Expiration Date -->
                    <div class="form-group">
                        <label class="form-label">Expiration Date</label>
                        <input type="date" class="form-control" x-model="docUploadModal.expiration_date">
                    </div>
                    <!-- File -->
                    <div class="form-group">
                        <label class="form-label">File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control"
                               accept=".pdf,.jpg,.jpeg,.png"
                               @change="docUploadModal.file = $event.target.files[0]">
                        <div class="form-hint">PDF, JPEG, or PNG — max 20 MB.</div>
                    </div>
                    <!-- Notes -->
                    <div class="form-group form-group--full">
                        <label class="form-label">Notes</label>
                        <input type="text" class="form-control" x-model="docUploadModal.notes"
                               maxlength="500" placeholder="Optional">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        @click="docUploadModal.open = false">Cancel</button>
                <button type="button" class="btn btn-primary"
                        :disabled="docUploadModal.saving || !docUploadModal.entity_type || !docUploadModal.entity_id"
                        @click="submitDocUpload()"
                        x-text="docUploadModal.saving ? 'Uploading…' : 'Upload'">
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /x-data -->

<script>
function FF_CustomerProfile() {
    return {
        activeTab:           'overview',

        // Tab badge counts — loaded in init() from tab-counts API so all
        // badges are accurate on page load without clicking each tab.
        tabCounts: {
            notes:         0,
            leases:        <?= (int) $customer['lease_count'] ?>, // server-preloaded
            invoices:      0,
            damage_claims: 0,
            mileage_logs:  0,
            rates:         <?= (int) $rateOverridesCount ?>,      // server-preloaded
            documents:     0,
            emails:        0,
        },

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

        // ── Rate Overrides ────────────────────────────────────────
        rateOverrides:       [],
        rateOverridesLoaded: false,
        rateOverridesLoading: false,

        // ── Documents ─────────────────────────────────────────────
        documents:       [],
        docsLoaded:      false,
        docsLoading:     false,

        // ── Email History (EMAIL-1) ───────────────────────────────
        emails:          [],
        emailsLoaded:    false,
        emailsLoading:   false,
        emailViewModal: { open: false, log: null, loading: false },
        // S-DOC-UPLOAD-ENTITY-TYPE-FIX: entity_type + entity_id live in
        // modal state and flow into FormData from state. openDocUploadModal()
        // receives them from the caller — never hardcoded inside submit.
        docUploadModal: {
            open:            false,
            saving:          false,
            error:           null,
            entity_type:     '',
            entity_id:       '',
            document_type:   '',
            title:           '',
            expiration_date: '',
            notes:           '',
            file:            null,
        },
        rateModal: {
            open:  false,
            id:    null,
            updatedAt: null,
            saving: false,
            error:  null,
            form: {
                equipment_type: '',
                effective_from: '<?= date('Y-m-d') ?>',
                effective_to:   '',
                daily_rate:     '',
                weekly_rate:    '',
                monthly_rate:   '',
                mileage_rate:   '',
                mileage_unit:   'km',
                currency:       'CAD',
                notes:          '',
            },
        },

        init() {
            this.loadTabCounts();
            this.$watch('activeTab', (tab) => {
                if (tab === 'leases'        && !this.leasesLoaded)         this.loadLeases();
                if (tab === 'invoices'      && !this.invoicesLoaded)       this.loadInvoices();
                if (tab === 'damage_claims' && !this.damageClaimsLoaded)   this.loadDamageClaims();
                if (tab === 'mileage_logs'  && !this.mileageLogsLoaded)    this.loadMileageLogs();
                if (tab === 'rates'         && !this.rateOverridesLoaded)  this.loadRateOverrides();
                if (tab === 'documents'     && !this.docsLoaded)             this.loadDocuments();
                if (tab === 'emails'        && !this.emailsLoaded)            this.loadEmails();
            });
            // EMAIL-1: refresh email history when an email is sent globally
            window.addEventListener('ff-email-sent', (ev) => {
                if (ev.detail && Number(ev.detail.customer_id) === <?= (int)$customerId ?>) {
                    this.emailsLoaded = false;
                    this.loadEmails();
                    this.loadEmailCount();
                }
            });
        },

        // ── Email History (EMAIL-1) ───────────────────────────────
        // loadTabCounts — fetches all tab badge counts in one call on init.
        // Leases and Rates are server-preloaded in tabCounts defaults above.
        // After lazy-loading a tab, that tab's live array length takes over
        // automatically because tabCounts is updated in the loaders below.
        async loadTabCounts() {
            try {
                const res = await fetch('<?= base_url('api/v1/customers/tab-counts') ?>?id=<?= $customerId ?>', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const json = await res.json();
                if (json.success) {
                    const d = json.data;
                    this.tabCounts.notes         = d.notes         ?? 0;
                    this.tabCounts.invoices      = d.invoices      ?? 0;
                    this.tabCounts.damage_claims = d.damage_claims ?? 0;
                    this.tabCounts.mileage_logs  = d.mileage_logs  ?? 0;
                    this.tabCounts.documents     = d.documents     ?? 0;
                    this.tabCounts.emails        = d.emails        ?? 0;
                    // Keep legacy noteCount in sync (used internally)
                    this.noteCount = this.tabCounts.notes;
                }
            } catch (e) { /* silent — badges fall back to server-preloaded defaults */ }
        },
        async loadEmails() {
            if (this.emailsLoading) return;
            this.emailsLoading = true;
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/email/logs/?customer_id=<?= (int)$customerId ?>&per_page=50'));
                if (r.success) {
                    this.emails            = r.data.items;
                    this.tabCounts.emails  = r.data.pagination?.total ?? this.emails.length;
                    this.emailsLoaded      = true;
                }
            } catch (e) { console.error(e); }
            finally { this.emailsLoading = false; }
        },
        async viewEmail(id) {
            this.emailViewModal.open    = true;
            this.emailViewModal.loading = true;
            this.emailViewModal.log     = null;
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/email/logs/show.php?id=' + id));
                if (r.success) this.emailViewModal.log = r.data;
            } catch (e) { console.error(e); }
            finally { this.emailViewModal.loading = false; }
        },
        formatEmailDate(d) {
            if (!d) return '';
            const dt = new Date(d.replace(' ', 'T'));
            return dt.toLocaleString();
        },

        // ── Notes ──────────────────────────────────────────────────

        async loadNotes() {
            if (this.notesLoaded) return;
            this.notesLoading = true;
            try {
                const res  = await fetch('<?= base_url('api/v1/customers/notes') ?>?customer_id=<?= $customerId ?>');
                const json = await res.json();
                if (json.success) {
                    this.notes                = json.data.notes;
                    this.noteCount            = this.notes.length;
                    this.tabCounts.notes      = this.notes.length;
                    this.notesLoaded          = true;
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
                    FF_Toast.error(json.error?.message ?? 'Failed to save note.');
                }
            } catch (e) {
                FF_Toast.error('Network error. Please try again.');
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
                    const items              = json.data.items || [];
                    this.invoices            = append ? [...this.invoices, ...items] : items;
                    this.invoicesTotal       = json.data.pagination?.total ?? items.length;
                    this.tabCounts.invoices  = this.invoicesTotal;
                    this.invoicesLoaded      = true;
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
                    const items                  = json.data?.items ?? [];
                    this.damageClaims            = append ? [...this.damageClaims, ...items] : items;
                    this.damageClaimsTotal       = json.data.pagination?.total ?? items.length;
                    this.tabCounts.damage_claims = this.damageClaimsTotal;
                    this.damageClaimsLoaded      = true;
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
                    const items                  = json.data?.items ?? [];
                    this.mileageLogs             = append ? [...this.mileageLogs, ...items] : items;
                    this.mileageLogsTotal        = json.data.pagination?.total ?? items.length;
                    this.tabCounts.mileage_logs  = this.mileageLogsTotal;
                    this.mileageLogsLoaded       = true;
                }
            } catch (e) { /* silent */ }
            this.mileageLogsLoading = false;
        },
        loadMoreMileageLogs()     { this.mileageLogsPage++; this.loadMileageLogs(true); },
        applyMileageLogsFilters() { this.mileageLogs = []; this.mileageLogsPage = 1; this.mileageLogsTotal = 0; this.mileageLogsLoaded = false; this.loadMileageLogs(); },

        // ── Rate Overrides ─────────────────────────────────────────
        async loadRateOverrides() {
            this.rateOverridesLoading = true;
            try {
                const p = new URLSearchParams({ customer_id: <?= $customerId ?>, per_page: 100 });
                const json = await (await fetch('<?= base_url('api/v1/customer_equipment_rates/index') ?>?' + p)).json();
                if (json.success) {
                    this.rateOverrides       = json.data.items || [];
                    this.rateOverridesLoaded = true;
                }
            } catch (e) { /* silent */ }
            this.rateOverridesLoading = false;
        },

        openRateModal(existing = null) {
            this.rateModal.error  = null;
            this.rateModal.saving = false;
            if (existing) {
                this.rateModal.id        = existing.id;
                this.rateModal.updatedAt = existing.updated_at;
                this.rateModal.form = {
                    equipment_type: existing.equipment_type,
                    effective_from: existing.effective_from,
                    effective_to:   existing.effective_to ?? '',
                    daily_rate:     existing.daily_rate ?? '',
                    weekly_rate:    existing.weekly_rate ?? '',
                    monthly_rate:   existing.monthly_rate ?? '',
                    mileage_rate:   existing.mileage_rate ?? '',
                    mileage_unit:   existing.mileage_unit ?? 'km',
                    currency:       existing.currency ?? 'CAD',
                    notes:          existing.notes ?? '',
                };
            } else {
                this.rateModal.id        = null;
                this.rateModal.updatedAt = null;
                this.rateModal.form = {
                    equipment_type: '',
                    effective_from: '<?= date('Y-m-d') ?>',
                    effective_to:   '',
                    daily_rate: '', weekly_rate: '', monthly_rate: '', mileage_rate: '',
                    mileage_unit: 'km', currency: 'CAD', notes: '',
                };
            }
            this.rateModal.open = true;
        },

        async saveRateOverride() {
            if (!this.rateModal.form.equipment_type || !this.rateModal.form.effective_from) {
                this.rateModal.error = 'Equipment type and Effective From are required.';
                return;
            }
            this.rateModal.saving = true;
            this.rateModal.error  = null;
            try {
                const csrf    = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const payload = {
                    customer_id:    <?= $customerId ?>,
                    equipment_type: this.rateModal.form.equipment_type,
                    effective_from: this.rateModal.form.effective_from,
                    effective_to:   this.rateModal.form.effective_to   || null,
                    daily_rate:     this.rateModal.form.daily_rate     || null,
                    weekly_rate:    this.rateModal.form.weekly_rate    || null,
                    monthly_rate:   this.rateModal.form.monthly_rate   || null,
                    mileage_rate:   this.rateModal.form.mileage_rate   || null,
                    mileage_unit:   this.rateModal.form.mileage_unit,
                    currency:       this.rateModal.form.currency,
                    notes:          this.rateModal.form.notes          || null,
                };
                if (this.rateModal.id) {
                    payload.id         = this.rateModal.id;
                    payload.updated_at = this.rateModal.updatedAt;
                }
                const res  = await fetch('<?= base_url('api/v1/customer_equipment_rates/upsert') ?>', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body:    JSON.stringify(payload),
                });
                const json = await res.json();
                if (res.ok && json.success) {
                    this.rateModal.open      = false;
                    this.rateOverridesLoaded = false;
                    this.loadRateOverrides();
                } else {
                    this.rateModal.error = json.error?.message ?? 'Failed to save rate override.';
                }
            } catch (e) {
                this.rateModal.error = 'Network error. Please try again.';
            } finally {
                this.rateModal.saving = false;
            }
        },

        async deleteRateOverride(rate) {
            if (!(await FF_Confirm.ask(`Delete rate override for "${rate.equipment_type}"? This cannot be undone.`))) return;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const res  = await fetch('<?= base_url('api/v1/customer_equipment_rates/delete') ?>', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body:    JSON.stringify({ id: rate.id, updated_at: rate.updated_at }),
                });
                const json = await res.json();
                if (res.ok && json.success) {
                    this.rateOverrides = this.rateOverrides.filter(r => r.id !== rate.id);
                } else {
                    FF_Toast.error(json.error?.message ?? 'Failed to delete rate override.');
                }
            } catch (e) {
                FF_Toast.error('Network error. Please try again.');
            }
        },

        // ── Documents ─────────────────────────────────────────────
        async loadDocuments() {
            this.docsLoading = true;
            try {
                const url  = '<?= base_url('api/v1/documents') ?>?entity_type=customer&entity_id=<?= $customerId ?>';
                const json = await (await fetch(url)).json();
                if (json.success) {
                    this.documents           = json.data.items || [];
                    this.tabCounts.documents = this.documents.length;
                    this.docsLoaded          = true;
                }
            } catch (e) { /* silent */ }
            this.docsLoading = false;
        },

        // S-DOC-UPLOAD-ENTITY-TYPE-FIX: caller MUST pass entity_type+entity_id.
        // If either is missing, the modal opens with an inline error and the
        // submit button is disabled — we never silently default or guess a type.
        openDocUploadModal(entityType, entityId) {
            const hasCtx = !!entityType && !!entityId;
            this.docUploadModal = {
                open: true, saving: false,
                error: hasCtx ? null : 'Cannot upload — missing context.',
                entity_type: entityType || '',
                entity_id:   entityId   || '',
                document_type: '', title: '', expiration_date: '', notes: '', file: null,
            };
        },

        async submitDocUpload() {
            const m = this.docUploadModal;
            // S-DOC-UPLOAD-ENTITY-TYPE-FIX: refuse to submit if context missing.
            if (!m.entity_type || !m.entity_id) {
                m.error = 'Cannot upload — missing context.'; return;
            }
            if (!m.document_type) { m.error = 'Document type is required.'; return; }
            if (!m.file)          { m.error = 'Please select a file.';      return; }

            m.saving = true;
            m.error  = null;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const fd   = new FormData();
                fd.append('entity_type',   m.entity_type);
                fd.append('entity_id',     m.entity_id);
                fd.append('document_type', m.document_type);
                fd.append('document',      m.file);
                if (m.title)           fd.append('title',           m.title);
                if (m.expiration_date) fd.append('expiration_date', m.expiration_date);
                if (m.notes)           fd.append('notes',           m.notes);

                const res  = await fetch('<?= base_url('api/v1/documents/upload') ?>', {
                    method:  'POST',
                    headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body:    fd,
                });
                const json = await res.json();
                if (res.ok && json.success) {
                    m.open = false;
                    // Prepend the new document so it's immediately visible
                    this.documents.unshift(json.data);
                } else {
                    m.error = json.error?.message ?? 'Upload failed. Please try again.';
                }
            } catch (e) {
                m.error = 'Network error. Please try again.';
            } finally {
                m.saving = false;
            }
        },

        async confirmDeleteDoc(doc) {
            const label = doc.title || doc.file_name;
            if (!(await FF_Confirm.ask(`Remove "${label}"? This cannot be undone.`))) return;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const res  = await fetch('<?= base_url('api/v1/documents/delete') ?>', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json',
                               'X-CSRF-Token': csrf,
                               'X-Requested-With': 'XMLHttpRequest' },
                    body:    JSON.stringify({ id: doc.id }),
                });
                const json = await res.json();
                if (res.ok && json.success) {
                    this.documents = this.documents.filter(d => d.id !== doc.id);
                } else {
                    FF_Toast.error(json.error?.message ?? 'Failed to remove document.');
                }
            } catch (e) {
                FF_Toast.error('Network error. Please try again.');
            }
        },

        customerDocTypeLabel(t) {
            return { tax_exemption:'Tax Exemption', credit_agreement:'Credit Agr.', other:'Other' }[t] ?? t;
        },

        customerDocTypeBadge(t) {
            return { tax_exemption:'badge badge-warning', credit_agreement:'badge badge-info',
                     other:'badge badge-neutral' }[t] ?? 'badge badge-neutral';
        },

        docExpiryClass(date) {
            if (!date) return 'font-mono text-sm';
            const d    = new Date(date);
            const now  = new Date();
            const diff = (d - now) / (1000 * 60 * 60 * 24);
            if (diff < 0)  return 'text-danger font-mono text-sm';
            if (diff < 30) return 'text-warning font-mono text-sm';
            return 'font-mono text-sm';
        },

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
            FF_Toast.error(json.error?.message ?? 'Failed to delete customer.');
        }
    } catch (e) {
        FF_Toast.error('Network error. Please try again.');
    }
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
