<?php
declare(strict_types=1);

/**
 * app/admin/invoices/batch.php
 *
 * S-BATCH-INVOICING — Batch Invoicing.
 *
 * Generate draft invoices for a whole month (or a custom date range) across
 * every selected customer's active monthly leases in one operator-driven
 * run, review each generated invoice in a live split-screen preview
 * (an embedded, chrome-free render of invoices/show.php — see
 * includes/header_embed.php) without downloading anything, check/override
 * the email address each invoice would go to, then bulk mark-as-sent and
 * optionally dispatch the invoice_ready email to every selected recipient.
 *
 * Sections:
 *   1. Period & Recipients — month/custom-range picker, live eligibility
 *      table (customer -> leases, each tagged unbilled/billed/void for the
 *      chosen period), per-customer recipient email review + override.
 *   2. Generate — selection summary + "Generate N Draft Invoices".
 *   3. Review & Send — invoices generated this session (+ manual add-by-
 *      search for older drafts), split-screen preview pane, per-row email
 *      override, bulk "Mark as Sent" / "Send & Email".
 *
 * All actual generation/state-changing work happens server-side via:
 *   - api/v1/invoices/batch_eligible.php  (read-only enumeration)
 *   - api/v1/invoices/batch_generate.php  (draft creation, per-lease isolated)
 *   - api/v1/invoices/bulk_send.php       (draft->sent + optional email, per-id isolated)
 * This page is purely the orchestrating UI — see those files for the
 * generation/dedupe/isolation contracts (notably why batch_generate.php
 * calls InvoiceGenerator::createFromLease() directly instead of
 * generateForLease() — a json_error()/exit() landmine documented there).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/footer.php, api/v1/invoices/batch_eligible.php,
 *           api/v1/invoices/batch_generate.php, api/v1/invoices/bulk_send.php
 * @decisions D14 (inclusive days), D16 (bcmath)
 * @session  S-BATCH-INVOICING
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

$canGenerate = can('invoices', 'create');
// S-BATCH-APPROVAL: when approval is required, the direct Generate path is
// closed server-side in api/v1/invoices/batch_generate.php. Reflect that in
// the UI so the operator is pointed at "Submit for approval" instead of
// clicking a button that will only 409 — the server remains the gate.
$approvalRequired = (string) settings_get('invoices.approval_required', '0') === '1';
$canSend     = can('invoices', 'edit');

$defaultMonth = date('Y-m');
$todayIso     = date('Y-m-d');

$pageTitle      = 'Batch Invoicing';
$helpModuleSlug = 'invoices';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('invoices') ?>">Invoices</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Batch Invoicing</span>
</nav>
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Batch Invoicing</h1>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            Generate a month's (or a custom range's) draft invoices for every selected customer, review each one, then send.
        </div>
    </div>
    <div class="page-header-actions">
        <?= help_button('invoices') ?>
        <a href="<?= base_url('invoices') ?>" class="btn btn-secondary btn-sm">
            <?= heroicon('document-text', 'icon-sm') ?>
            All Invoices
        </a>
    </div>
</div>

<?php if (!$canGenerate && !$canSend): ?>
<div class="card">
    <div class="card-body">
        <p class="text-secondary">You have read-only access to invoices. Batch generation and sending require additional permissions — contact an administrator.</p>
    </div>
</div>
<?php endif; ?>

<div id="batch-invoicing-app"
     x-data="BatchInvoicing({ defaultMonth: <?= e(json_encode($defaultMonth)) ?>, today: <?= e(json_encode($todayIso)) ?>, canGenerate: <?= $canGenerate ? 'true' : 'false' ?>, canSend: <?= $canSend ? 'true' : 'false' ?> })"
     x-init="init()">

    <!-- ============================================================
         SPLIT LAYOUT — left: workflow, right: sticky invoice preview
         ============================================================ -->
    <div class="batch-split" :class="{ 'has-preview': previewInvoiceId }">

        <!-- ────────────────────────── LEFT COLUMN ────────────────────────── -->
        <div class="batch-left">

            <!-- ── 1. Period ─────────────────────────────────────────── -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">1. Billing Period</span>
                </div>
                <div class="card-body">
                    <!-- Mode + controls share ONE row. .tab-bar is width:100%
                         globally, which stretched a two-button switch across the
                         whole card and forced the date controls onto their own
                         line (and "Last Month" onto a third) — hence the local
                         width override on .batch-period-tabs. -->
                    <div class="batch-period-row">
                        <div class="tab-bar batch-period-tabs">
                            <button type="button" class="tab-btn" :class="{ 'is-active': periodMode === 'month' }" @click="periodMode = 'month'; onPeriodChanged();">Calendar Month</button>
                            <button type="button" class="tab-btn" :class="{ 'is-active': periodMode === 'range' }" @click="periodMode = 'range'; onPeriodChanged();">Custom Range</button>
                        </div>

                        <template x-if="periodMode === 'month'">
                            <div class="batch-period-inputs">
                                <!-- ← month → as one segmented stepper, so the
                                     arrows read as belonging to the field. -->
                                <div class="batch-month-stepper">
                                    <button type="button" class="batch-step-btn" @click="shiftMonth(-1)" title="Previous month" aria-label="Previous month">&larr;</button>
                                    <input type="month" class="batch-month-input" x-model="monthValue" @change="onPeriodChanged();" aria-label="Billing month">
                                    <button type="button" class="batch-step-btn" @click="shiftMonth(1)" title="Next month" aria-label="Next month">&rarr;</button>
                                </div>
                                <div class="batch-quick-months">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="applyPreset('this_month')">This Month</button>
                                    <button type="button" class="btn btn-secondary btn-sm" @click="applyPreset('last_month')">Last Month</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="periodMode === 'range'">
                            <div class="batch-period-inputs">
                                <input type="date" class="form-control form-control-sm" x-model="rangeStart" @change="onPeriodChanged();" aria-label="Period start">
                                <span class="text-secondary">to</span>
                                <input type="date" class="form-control form-control-sm" x-model="rangeEnd" @change="onPeriodChanged();" aria-label="Period end">
                            </div>
                        </template>
                    </div>

                    <div class="batch-period-summary">
                        <span class="text-secondary">Billing period</span>
                        <strong x-text="periodStart"></strong> &rarr; <strong x-text="periodEnd"></strong>
                        <span x-show="periodIsFullMonth" class="badge badge-no-dot badge-info">Full calendar month — bills as full_month</span>
                        <span x-show="!periodIsFullMonth" class="badge badge-no-dot badge-neutral">Custom span — bills as single_period</span>
                    </div>

                    <!-- Saved presets — a preset stores the SELECTION + send
                         options, never a period, so the same customer set can
                         be re-applied to whatever month is picked above. -->
                    <div class="batch-presets" x-show="presets.length || canGenerate">
                        <span class="batch-presets-label">Presets</span>
                        <template x-for="p in presets" :key="p.id">
                            <span class="batch-preset-chip">
                                <button type="button" class="batch-preset-apply" @click="applyPreset(p)"
                                        :title="p.lease_ids.length + ' lease(s) · saved ' + p.created_at" x-text="p.name"></button>
                                <button type="button" class="batch-preset-del" @click="deletePreset(p)" title="Delete preset">&times;</button>
                            </span>
                        </template>
                        <span x-show="!presets.length" class="text-secondary text-sm">None saved yet</span>
                        <button type="button" class="btn btn-ghost btn-sm" x-show="canGenerate"
                                :disabled="selectedLeaseIds().length === 0" @click="savePreset()">+ Save current selection</button>
                    </div>
                </div>
            </div>

            <!-- ── 2. Customers & Leases ─────────────────────────────── -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">2. Customers &amp; Leases</span>
                    <span class="text-secondary text-sm" x-show="!eligLoading" x-text="eligSummary.customers_count ? eligSummary.customers_count + ' customer' + (eligSummary.customers_count !== 1 ? 's' : '') + ' · ' + eligSummary.leases_count + ' lease' + (eligSummary.leases_count !== 1 ? 's' : '') : 'No eligible leases for this period'"></span>
                </div>
                <div class="card-body">

                    <div class="table-toolbar">
                        <div class="table-toolbar-left">
                            <input type="search" class="form-control form-control-sm" placeholder="Search customer…"
                                   x-model="search" @input.debounce.400ms="loadEligible()" style="min-width:200px;" maxlength="255">
                            <select class="form-select form-control-sm" x-model="statusFilter" @change="rebuildDisplay()" aria-label="Filter by billing status">
                                <option value="unbilled">Unbilled only</option>
                                <option value="all">All statuses</option>
                                <option value="billed">Already billed</option>
                                <option value="void">Void (rebillable)</option>
                            </select>
                        </div>
                        <div class="table-toolbar-right">
                            <button type="button" class="btn btn-ghost btn-sm" @click="selectAllUnbilled()">Select all unbilled</button>
                            <button type="button" class="btn btn-ghost btn-sm" @click="clearSelection()">Clear</button>
                        </div>
                    </div>

                    <div x-show="eligLoading" class="text-secondary text-sm" style="padding:16px 0;">Loading eligible leases…</div>
                    <div x-show="eligError" class="text-danger text-sm" style="padding:8px 0;" x-text="eligError"></div>

                    <div x-show="!eligLoading && displayCustomers.length === 0 && !eligError" class="text-secondary text-sm" style="padding:16px 0;">
                        No active monthly leases match this period and filter.
                    </div>

                    <!-- PERF (S-BATCH-INVOICING fix): iterates the PRECOMPUTED
                         displayCustomers array, never a function call like
                         filteredCustomers(). A function in x-for is re-evaluated on
                         every Alpine reactivity tick and returns a NEW array each
                         time, so x-for tears down and rebuilds every node on any
                         state change (including a single checkbox click). With a
                         real fleet (26 customers / 166 leases) that churn locked up
                         rendering and the list appeared to never load. Same reason
                         each row's leases are pre-filtered onto c.leases rather than
                         calling visibleLeases(c) per row. -->
                    <div class="batch-customer-list" x-show="!eligLoading">
                        <template x-for="c in displayCustomers" :key="c.id">
                            <div class="batch-customer-group">
                                <div class="batch-customer-row" @click="toggleCustomerAll(c)">
                                    <label class="form-check" style="margin:0;" @click.stop>
                                        <input type="checkbox" class="form-check-input"
                                               :checked="c.leases.length > 0 && c.leases.every(l => !!selected[l.id])"
                                               @change="toggleCustomerAll(c)">
                                    </label>
                                    <div class="batch-customer-name">
                                        <strong x-text="c.company_name"></strong>
                                        <span class="badge badge-no-dot badge-neutral batch-chip" x-text="c.status"></span>
                                        <span class="batch-lease-count" x-text="c.leases.length + (c.leases.length === 1 ? ' lease' : ' leases')"></span>
                                    </div>
                                    <div class="batch-customer-recipient">
                                        <span x-show="c.recipient.email" x-text="c.recipient.email"></span>
                                        <span x-show="!c.recipient.email" class="text-danger">no email on file</span>
                                        <span x-show="c.recipient.warnings.length" class="badge badge-no-dot badge-warning batch-chip" :title="c.recipient.warnings.join(' ')">&#9888;</span>
                                    </div>
                                </div>
                                <template x-for="l in c.leases" :key="l.id">
                                    <div class="batch-lease-row" :class="{ 'is-selected': !!selected[l.id] }" @click="toggleLease(l)">
                                        <label class="form-check" style="margin:0;" @click.stop>
                                            <input type="checkbox" class="form-check-input"
                                                   :checked="!!selected[l.id]"
                                                   @change="toggleLease(l)">
                                        </label>
                                        <div class="batch-lease-info">
                                            <span class="batch-lease-contract" x-text="l.contract_number"></span>
                                            <span class="text-secondary" x-show="l.unit_number">&middot; Unit <span x-text="l.unit_number"></span></span>
                                        </div>
                                        <div class="batch-lease-status">
                                            <span class="badge badge-no-dot"
                                                  :class="{'badge-warning': l.billing_status === 'unbilled', 'badge-success': l.billing_status === 'billed', 'badge-neutral': l.billing_status === 'void'}"
                                                  x-text="l.billing_status"></span>
                                            <template x-if="l.existing_invoice">
                                                <a href="#" class="link text-sm" style="margin-left:6px;" @click.stop.prevent="openPreview(l.existing_invoice.id)" x-text="l.existing_invoice.invoice_number"></a>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- ── Generate action bar ───────────────────────────────── -->
            <div class="batch-action-bar" x-show="canGenerate">
                <div>
                    <strong x-text="selectedLeaseIds().length"></strong> lease<span x-show="selectedLeaseIds().length !== 1">s</span> selected
                    <span class="text-secondary text-sm" x-show="selectedLeaseIds().length"> across <span x-text="selectedCustomerIds().length"></span> customer<span x-show="selectedCustomerIds().length !== 1">s</span></span>
                </div>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <?php if ($approvalRequired): ?>
                        <span class="badge badge-no-dot badge-warning" title="Settings → General → Invoices &amp; Billing">
                            Approval required
                        </span>
                    <?php endif; ?>
                    <!-- Reopen the LAST computed review without recomputing. The dry
                         run really generates+rolls back per lease, so re-running it
                         over a big selection is slow — closing the review to go check
                         something must not throw that work away. -->
                    <button type="button" class="btn btn-ghost" x-show="previewResult && !reviewOpen"
                            @click="reviewOpen = true" x-cloak>
                        Reopen review
                    </button>
                    <button type="button" class="btn btn-secondary" :disabled="selectedLeaseIds().length === 0 || previewing || generating" @click="dryRun()">
                        <span x-show="!previewing">Preview totals</span>
                        <span x-show="previewing">Calculating…</span>
                    </button>
                    <button type="button" class="btn <?= $approvalRequired ? 'btn-primary' : 'btn-secondary' ?>"
                            :disabled="selectedLeaseIds().length === 0 || submitting || generating" @click="submitForApproval()">
                        <span x-show="!submitting">Submit for approval</span>
                        <span x-show="submitting">Submitting…</span>
                    </button>
                    <?php if (!$approvalRequired): ?>
                    <button type="button" class="btn btn-primary" :disabled="selectedLeaseIds().length === 0 || generating" @click="generate()">
                        <span x-show="!generating">Generate <span x-text="selectedLeaseIds().length"></span> Draft Invoice<span x-show="selectedLeaseIds().length !== 1">s</span></span>
                        <span x-show="generating">Generating…</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Restored-selection notice: silently reinstating a selection would
                 be worse than losing it — the operator must know these choices
                 came from earlier and can throw them away in one click. -->
            <div class="batch-restored-note" x-show="restoredFrom" x-cloak>
                <span>
                    Restored your previous selection and review list from this tab.
                    Figures are <strong>not</strong> restored — re-run Preview totals to see current numbers.
                </span>
                <button type="button" class="btn btn-ghost btn-xs" @click="clearSavedState(); loadEligible();">Start fresh</button>
            </div>

            <!-- ── Delivery options ──────────────────────────────────────
                 Sits ABOVE Recipient Emails because it decides whether the
                 recipient addresses below are used at all — reading the two
                 in the other order leaves you configuring addresses without
                 knowing if anything gets emailed. Applies to this run only. -->
            <div class="card" x-show="selectedCustomerIds().length > 0 || reviewItems.length">
                <div class="card-header">
                    <span class="card-title">Delivery Options</span>
                    <span class="text-secondary text-sm">What happens when you press Send in step 4</span>
                </div>
                <div class="card-body">
                    <div class="batch-send-options">
                        <label class="form-check" style="margin:0;font-size:13px;">
                            <input type="checkbox" class="form-check-input" x-model="sendEmailToo">
                            Also email the invoice to the customer
                        </label>
                        <label class="form-check" style="margin:0;font-size:13px;" x-show="sendEmailToo">
                            <input type="checkbox" class="form-check-input" x-model="attachPdf">
                            Attach the invoice PDF
                        </label>
                    </div>

                    <div class="batch-option-notes">
                        <p>
                            <strong>Send</strong> always marks the invoice as <em>sent</em> and books it to the
                            customer's outstanding balance — that happens whether or not you email anything.
                        </p>
                        <p>
                            <strong>Also email the invoice to the customer</strong> — additionally sends the
                            <code>invoice_ready</code> email to the address in Recipient Emails below.
                            Leave this <em>off</em> to mark invoices as sent without contacting anyone
                            (e.g. you post or hand-deliver them, or you're catching up on back-billing).
                        </p>
                        <p x-show="sendEmailToo">
                            <strong>Attach the invoice PDF</strong> — generates each invoice's PDF and attaches it
                            to that email. Off means the customer gets the notification text only, with no
                            document attached.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── 3. Recipient emails for selected customers ──────────
                 Collapsed by default: with 26 customers this is a very tall
                 list, and the addresses on file are correct in the normal
                 case — it only needs opening to override one. The header
                 still surfaces the count and any missing-email warning so a
                 problem is visible WITHOUT expanding. -->
            <div class="card" x-show="selectedCustomerIds().length > 0">
                <button type="button" class="card-header batch-collapse-header" @click="recipientsOpen = !recipientsOpen"
                        :aria-expanded="recipientsOpen ? 'true' : 'false'">
                    <span class="batch-collapse-title">
                        <span class="batch-collapse-caret" :class="{ 'is-open': recipientsOpen }" aria-hidden="true">&rsaquo;</span>
                        <span class="card-title">3. Recipient Emails</span>
                        <span class="badge badge-no-dot badge-neutral" x-text="selectedCustomerIds().length + ' customer' + (selectedCustomerIds().length === 1 ? '' : 's')"></span>
                        <span class="badge badge-no-dot badge-warning" x-show="missingEmailCount() > 0" x-cloak
                              x-text="missingEmailCount() + ' with no email'"></span>
                    </span>
                    <span class="text-secondary text-sm" x-text="recipientsOpen ? 'Hide' : 'Review or override addresses'"></span>
                </button>
                <div class="card-body" x-show="recipientsOpen" x-cloak>
                    <p class="text-secondary text-sm" style="margin:0 0 12px;">
                        Applies to invoices generated in this run — for this batch only, not saved to the customer record.
                        Leave a row blank to use the address already on file.
                    </p>
                    <div class="batch-recipient-list">
                        <template x-for="cid in selectedCustomerIds()" :key="cid">
                            <div class="batch-recipient-row">
                                <div class="batch-recipient-name" x-text="customerById(cid).company_name"></div>
                                <input type="email" class="form-control form-control-sm" style="flex:1;"
                                       :placeholder="customerById(cid).recipient.email || 'no email on file'"
                                       x-model="customerEmailOverrides[cid]">
                                <span x-show="customerById(cid).recipient.warnings.length" class="text-warning text-sm" x-text="customerById(cid).recipient.warnings[0]"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="card" x-show="lastGenerateResult" x-cloak>
                <div class="card-header"><span class="card-title">Generation Result</span></div>
                <div class="card-body">
                    <p class="text-sm">
                        <span class="badge badge-no-dot badge-success" x-text="(lastGenerateResult?.actioned || 0) + ' created'"></span>
                        <span class="badge badge-no-dot badge-neutral" style="margin-left:6px;" x-text="(lastGenerateResult?.skipped || 0) + ' skipped'"></span>
                    </p>
                    <template x-if="lastGenerateResult?.errors?.length">
                        <ul class="text-sm text-secondary" style="margin:8px 0 0; padding-left:18px;">
                            <template x-for="(err, i) in lastGenerateResult.errors" :key="i">
                                <li><span x-text="'Lease #' + err.lease_id + ': ' + err.reason"></span></li>
                            </template>
                        </ul>
                    </template>
                </div>
            </div>

            <!-- ── Approval runs ─────────────────────────────────────── -->
            <div class="card" x-show="runs.length" x-cloak>
                <div class="card-header">
                    <span class="card-title">Approval Runs</span>
                    <span class="text-secondary text-sm">Frozen proposals — open one to review or sign off</span>
                </div>
                <div class="card-body">
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <thead><tr>
                                <th>Run</th><th>Period</th><th>Status</th>
                                <th class="text-right">Invoices</th><th class="text-right">Total</th><th>Submitted by</th>
                            </tr></thead>
                            <tbody>
                                <template x-for="r in runs" :key="r.id">
                                    <tr>
                                        <td><a class="link" :href="runUrl(r.id)" x-text="r.reference"></a></td>
                                        <td class="brc-nowrap"><span x-text="r.period_start"></span> → <span x-text="r.period_end"></span></td>
                                        <td>
                                            <span class="badge badge-no-dot"
                                                  :class="{
                                                    'badge-warning': r.status === 'pending',
                                                    'badge-success': r.status === 'approved',
                                                    'badge-danger':  r.status === 'rejected',
                                                    'badge-info':    r.status === 'generated',
                                                    'badge-neutral': r.status === 'cancelled'
                                                  }" x-text="r.status"></span>
                                        </td>
                                        <td class="text-right" x-text="r.invoice_count"></td>
                                        <td class="text-right currency" x-text="Object.keys(r.total_by_currency || {}).map(c => fmtMoney(r.total_by_currency[c]) + ' ' + c).join(' + ') || '—'"></td>
                                        <td class="text-sm text-secondary" x-text="r.submitted_by_name || '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ── 4. Review & Send ──────────────────────────────────── -->
            <div class="card" id="review-and-send">
                <div class="card-header">
                    <span class="card-title">4. Review &amp; Send</span>
                    <span class="text-secondary text-sm" x-text="reviewItems.length + ' invoice' + (reviewItems.length !== 1 ? 's' : '') + ' in this list'"></span>
                </div>
                <div class="card-body">

                    <div class="table-toolbar">
                        <div class="table-toolbar-left">
                            <input type="search" class="form-control form-control-sm" placeholder="Find an existing draft to add (invoice # or customer)…"
                                   x-model="addDraftQuery" @input.debounce.400ms="searchAddDraft()" style="min-width:280px; max-width:420px;" maxlength="255">
                        </div>
                    </div>

                    <div x-show="addDraftResults.length" class="batch-add-results">
                        <template x-for="r in addDraftResults" :key="r.id">
                            <div class="batch-add-result-row">
                                <span x-text="r.invoice_number + ' — ' + (r.company_name_snapshot || '')"></span>
                                <button type="button" class="btn btn-ghost btn-sm" @click="addDraftToReview(r)">Add</button>
                            </div>
                        </template>
                    </div>

                    <div x-show="reviewItems.length === 0" class="text-secondary text-sm" style="padding:16px 0;">
                        Nothing to review yet — generate invoices above, or search for an existing draft to add it here.
                    </div>

                    <div class="data-table-wrap" x-show="reviewItems.length">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:32px;"><input type="checkbox" class="form-check-input" :checked="allReviewSelected()" @change="toggleReviewAll()"></th>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th class="text-right">Amount</th>
                                    <th>Status</th>
                                    <th>Send To</th>
                                    <th>PDF</th>
                                    <th style="width:70px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="item in reviewItems" :key="item.invoice_id">
                                    <tr :class="{ 'batch-row-active': previewInvoiceId === item.invoice_id }">
                                        <td><input type="checkbox" class="form-check-input" :checked="!!reviewSelected[item.invoice_id]" @change="toggleReviewSelect(item.invoice_id)" :disabled="item.status !== 'draft'"></td>
                                        <td>
                                            <a href="#" class="link" @click.prevent="openPreview(item.invoice_id)" x-text="item.invoice_number"></a>
                                            <a href="#" class="link text-secondary" style="margin-left:6px;font-size:11px;"
                                               title="Open the full invoice in a new tab — your list here is kept"
                                               @click.prevent="openPreviewNewTab(item.invoice_id)">&#8599;</a>
                                        </td>
                                        <td x-text="item.company_name"></td>
                                        <td class="text-right currency" x-text="fmtMoney(item.total_amount)"></td>
                                        <td><span class="badge badge-no-dot" :class="item.status === 'draft' ? 'badge-neutral' : 'badge-success'" x-text="item.status"></span></td>
                                        <td>
                                            <input type="email" class="form-control form-control-sm" style="min-width:190px;"
                                                   :placeholder="item.recipient_email || 'no email on file'"
                                                   x-model="overrides[item.invoice_id]"
                                                   :disabled="item.status !== 'draft'">
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-ghost btn-sm" @click="viewOrGeneratePdf(item)" :disabled="item.pdfWorking">
                                                <span x-show="item.pdfWorking">…</span>
                                                <span x-show="!item.pdfWorking" x-text="item.has_pdf ? 'View' : 'Generate'"></span>
                                            </button>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-primary btn-xs" title="Send just this invoice"
                                                    @click="sendOne(item.invoice_id)"
                                                    x-show="item.status === 'draft'" :disabled="sending || item.sendingOne">
                                                <span x-show="!item.sendingOne">Send</span>
                                                <span x-show="item.sendingOne">…</span>
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Running totals for the review list -->
                    <div class="batch-total-strip" x-show="reviewItems.length" style="margin-top:14px;">
                        <div class="batch-total">
                            <div class="batch-total-label">Batch total</div>
                            <div class="batch-total-value" x-text="reviewTotalText()"></div>
                        </div>
                        <div class="batch-total">
                            <div class="batch-total-label">Drafts</div>
                            <div class="batch-total-value" x-text="reviewItems.filter(i => i.status === 'draft').length"></div>
                        </div>
                        <div class="batch-total">
                            <div class="batch-total-label">Sent</div>
                            <div class="batch-total-value" x-text="reviewItems.filter(i => i.status !== 'draft').length"></div>
                        </div>
                    </div>

                    <div class="batch-action-bar" x-show="reviewItems.length" style="margin-top:12px;">
                        <div>
                            <strong x-text="reviewSelectedIds().length"></strong> selected for sending
                        </div>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" :disabled="!reviewItems.length || downloading" @click="downloadBatch('zip')">
                                <span x-show="downloading !== 'zip'">Download ZIP</span>
                                <span x-show="downloading === 'zip'">Zipping…</span>
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" :disabled="!reviewItems.length || downloading" @click="downloadBatch('pdf')">
                                <span x-show="downloading !== 'pdf'">Download combined PDF</span>
                                <span x-show="downloading === 'pdf'">Merging…</span>
                            </button>
                            <button type="button" class="btn btn-primary" x-show="canSend" :disabled="reviewSelectedIds().length === 0 || sending" @click="bulkSend()">
                                <span x-show="!sending" x-text="sendEmailToo ? 'Send & Email ' + reviewSelectedIds().length : 'Mark ' + reviewSelectedIds().length + ' as Sent'"></span>
                                <span x-show="sending">Sending…</span>
                            </button>
                        </div>
                    </div>

                    <div class="card" x-show="lastSendResult" x-cloak style="margin-top:14px;">
                        <div class="card-body">
                            <p class="text-sm">
                                <span class="badge badge-no-dot badge-success" x-text="(lastSendResult?.actioned || 0) + ' sent'"></span>
                                <span class="badge badge-no-dot badge-neutral" style="margin-left:6px;" x-text="(lastSendResult?.skipped || 0) + ' skipped'"></span>
                                <template x-if="sendEmailToo">
                                    <span class="badge badge-no-dot badge-info" style="margin-left:6px;" x-text="(lastSendResult?.emailed || 0) + ' emailed'"></span>
                                </template>
                            </p>
                            <template x-if="lastSendResult?.errors?.length || lastSendResult?.email_errors?.length">
                                <ul class="text-sm text-secondary" style="margin:8px 0 0; padding-left:18px;">
                                    <template x-for="(err, i) in (lastSendResult.errors || [])" :key="'e'+i">
                                        <li><span x-text="'Invoice #' + err.id + ': ' + err.reason"></span></li>
                                    </template>
                                    <template x-for="(err, i) in (lastSendResult.email_errors || [])" :key="'m'+i">
                                        <li><span x-text="'Invoice #' + err.id + ' (email): ' + err.reason"></span></li>
                                    </template>
                                </ul>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ────────────────────────── RIGHT COLUMN — PREVIEW ───────────────
             Rendered ONLY while an invoice is being previewed. It used to hold
             a permanent ~40% of the width showing an empty-state message, which
             squeezed the workflow for the whole of steps 1–3 (and is what
             cropped the totals table). The pane earns its space when you are
             clicking through generated drafts; the rest of the time the
             selection UI gets the full width. -->
        <template x-if="previewInvoiceId">
        <div class="batch-right">
            <div class="card batch-preview-card">
                <div class="card-header">
                    <span class="card-title">Preview</span>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <a :href="fullInvoiceUrl()" target="_blank" class="btn btn-ghost btn-sm">Open Full Page ↗</a>
                        <button type="button" class="btn btn-ghost btn-sm" @click="closePreview()"
                                title="Close the preview and give the full width back to the workflow">Close</button>
                    </div>
                </div>
                <div class="batch-preview-body">
                    <iframe :src="previewUrl" class="batch-preview-iframe" title="Invoice preview"></iframe>
                </div>
            </div>
        </div>
        </template>

    </div>

    <!-- ============================================================
         FULL-SCREEN BILLING REVIEW (dry run)
         Its own full-width surface rather than a card inside the left
         column of the split layout — a manager checking a month's
         billing needs every rate, usage input, and line item visible
         at once, and the ~580px left column clipped the totals.
         ============================================================ -->
    <template x-if="previewResult && reviewOpen">
        <?php /* data-theme="light": the Billing Review is ALWAYS rendered in the
                 light palette regardless of the user's theme (operator call — it
                 reads best as a document, and it is the surface people print).
                 [data-theme="light"] in app.css is a bare attribute selector, not
                 :root-scoped, so putting it on this element re-binds the whole
                 light token set for this subtree only — no palette duplication.
                 The brand vars are re-asserted in the <style> block below because
                 that same token block resets --color-primary to the stock orange. */ ?>
        <div class="batch-review-overlay" data-theme="light" @keydown.escape.window="reviewOpen = false">
            <div class="batch-review-head">
                <div>
                    <div class="batch-review-title">
                        Billing Review
                        <span class="brc-dryrun-pill">Dry run</span>
                    </div>
                    <div class="batch-review-sub">
                        <span x-text="periodStart"></span> &rarr; <span x-text="periodEnd"></span>
                        &middot; nothing has been created yet
                    </div>
                </div>
                <div class="batch-review-head-actions">
                    <button type="button" class="btn btn-secondary btn-sm" @click="printReview()">Print / Save PDF</button>
                    <?php if ($approvalRequired): ?>
                    <button type="button" class="btn btn-primary btn-sm" x-show="canGenerate"
                            :disabled="submitting" @click="reviewOpen = false; submitForApproval()">
                        <span x-show="!submitting">Looks right — Submit for approval</span>
                        <span x-show="submitting">Submitting…</span>
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-primary btn-sm" x-show="canGenerate"
                            :disabled="generating" @click="reviewOpen = false; generate()">
                        <span x-show="!generating">Looks right — Generate <span x-text="previewResult.totals.ok_count"></span></span>
                        <span x-show="generating">Generating…</span>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost btn-sm" @click="reviewOpen = false">Close</button>
                </div>
            </div>

            <div class="batch-review-body">
                <!-- Summary -->
                <div class="batch-total-strip">
                    <div class="batch-total">
                        <div class="batch-total-label">Would bill</div>
                        <div class="batch-total-value" x-text="previewCurrencyText()"></div>
                    </div>
                    <div class="batch-total">
                        <div class="batch-total-label">Invoices</div>
                        <div class="batch-total-value" x-text="previewResult.totals.ok_count"></div>
                    </div>
                    <div class="batch-total">
                        <div class="batch-total-label">Customers</div>
                        <div class="batch-total-value" x-text="previewCustomerCount()"></div>
                    </div>
                    <div class="batch-total" x-show="previewResult.totals.error_count">
                        <div class="batch-total-label">Cannot bill</div>
                        <div class="batch-total-value text-danger" x-text="previewResult.totals.error_count"></div>
                    </div>
                </div>

                <!-- Problems first — these will be skipped by Generate -->
                <template x-if="previewResult.previews.some(p => !p.ok)">
                    <div class="batch-review-problems">
                        <div class="batch-review-section-title">Will be skipped</div>
                        <template x-for="p in previewResult.previews.filter(x => !x.ok)" :key="'e' + p.lease_id">
                            <div class="batch-problem-row">
                                <strong x-text="p.company_name || ('Lease #' + p.lease_id)"></strong>
                                <span class="batch-lease-contract" x-text="p.contract_number || ''"></span>
                                <span class="text-danger" x-text="p.error"></span>
                            </div>
                        </template>
                    </div>
                </template>

                <!-- One full card per invoice -->
                <template x-for="p in previewResult.previews.filter(x => x.ok)" :key="p.lease_id">
                    <div class="batch-review-card">
                        <div class="brc-head">
                            <div class="brc-head-main">
                                <div class="brc-customer" x-text="p.company_name"></div>
                                <div class="brc-meta">
                                    <span class="batch-lease-contract" x-text="p.contract_number"></span>
                                    <span x-show="p.unit_number">&middot; Unit <span x-text="p.unit_number"></span></span>
                                    <span x-show="p.po_number">&middot; PO <span x-text="p.po_number"></span></span>
                                </div>
                            </div>
                            <div class="brc-head-total">
                                <div class="batch-total-label">Total</div>
                                <div class="brc-total-value" x-text="fmtMoney(p.total_amount) + ' ' + p.currency"></div>
                            </div>
                        </div>

                        <!-- Facts grid: dates, days, rates, usage -->
                        <div class="brc-facts">
                            <div class="brc-fact"><span>Billing period</span><b><span x-text="p.period_start"></span> → <span x-text="p.period_end"></span></b></div>
                            <div class="brc-fact"><span>Billable days</span><b x-text="p.billing_days"></b></div>
                            <div class="brc-fact"><span>Rate method</span><b x-text="p.rate_method || '—'"></b></div>
                            <div class="brc-fact"><span>Billing type</span><b x-text="p.billing_type"></b></div>
                            <div class="brc-fact"><span>Invoice / due</span><b><span x-text="p.invoice_date"></span> → <span x-text="p.due_date"></span></b></div>
                            <div class="brc-fact"><span>Lease term</span><b><span x-text="p.terms.lease_start"></span> → <span x-text="p.terms.lease_end || 'open'"></span></b></div>

                            <div class="brc-fact" x-show="+p.terms.daily_rate > 0"><span>Daily rate</span><b x-text="fmtMoney(p.terms.daily_rate)"></b></div>
                            <div class="brc-fact" x-show="+p.terms.weekly_rate > 0"><span>Weekly rate</span><b x-text="fmtMoney(p.terms.weekly_rate)"></b></div>
                            <div class="brc-fact" x-show="+p.terms.monthly_rate > 0"><span>Monthly rate</span><b x-text="fmtMoney(p.terms.monthly_rate)"></b></div>
                            <div class="brc-fact" x-show="p.terms.hourly_rate && +p.terms.hourly_rate > 0"><span>Hourly rate</span><b x-text="fmtMoney(p.terms.hourly_rate)"></b></div>

                            <div class="brc-fact" x-show="mileageRateOf(p)"><span>Mileage rate</span><b x-text="mileageRateOf(p)"></b></div>
                            <div class="brc-fact" x-show="+p.terms.estimated_mileage_per_day > 0"><span>Est. per day</span><b><span x-text="p.terms.estimated_mileage_per_day"></span> <span x-text="p.terms.mileage_unit"></span></b></div>
                            <div class="brc-fact" x-show="+p.terms.estimated_engine_hours_per_day > 0"><span>Est. hours/day</span><b x-text="p.terms.estimated_engine_hours_per_day"></b></div>
                            <div class="brc-fact" x-show="p.terms.mileage_tracking_mode && p.terms.mileage_tracking_mode !== 'off'"><span>Mileage mode</span><b x-text="p.terms.mileage_tracking_mode"></b></div>

                            <div class="brc-fact" x-show="p.usage.odometer_start !== null || p.usage.odometer_end !== null">
                                <span>Odometer</span><b><span x-text="p.usage.odometer_start ?? '—'"></span> → <span x-text="p.usage.odometer_end ?? '—'"></span></b>
                            </div>
                            <div class="brc-fact" x-show="p.usage.hours_start !== null || p.usage.hours_end !== null">
                                <span>Engine hours</span><b><span x-text="p.usage.hours_start ?? '—'"></span> → <span x-text="p.usage.hours_end ?? '—'"></span></b>
                            </div>

                            <div class="brc-fact" x-show="p.terms.insurance"><span>Insurance</span><b x-text="fmtMoney(p.terms.insurance)"></b></div>
                            <div class="brc-fact" x-show="p.terms.warranty"><span>Warranty</span><b x-text="fmtMoney(p.terms.warranty)"></b></div>
                            <div class="brc-fact" x-show="p.terms.gps"><span>GPS</span><b x-text="fmtMoney(p.terms.gps)"></b></div>
                            <div class="brc-fact" x-show="p.terms.minimum_billing_days"><span>Min. billing days</span><b x-text="p.terms.minimum_billing_days"></b></div>
                            <div class="brc-fact" x-show="p.terms.billing_days_removed > 0"><span>Days removed</span><b x-text="p.terms.billing_days_removed"></b></div>
                            <div class="brc-fact" x-show="p.exchange_rate_to_cad"><span>FX to CAD</span><b x-text="p.exchange_rate_to_cad"></b></div>
                        </div>

                        <!-- Line items -->
                        <div class="data-table-wrap">
                            <table class="data-table brc-lines">
                                <thead><tr>
                                    <th>Type</th><th>Description</th><th>Period</th>
                                    <th class="text-right">Qty</th><th class="text-right">Unit price</th><th class="text-right">Amount</th>
                                </tr></thead>
                                <tbody>
                                    <template x-for="(l, li) in p.lines" :key="li">
                                        <tr>
                                            <td><span class="brc-line-type" :data-fam="lineFamily(l)" x-text="l.item_type.replace(/_/g,' ')"></span></td>
                                            <td>
                                                <div x-text="l.description"></div>
                                                <div class="brc-line-sub" x-show="l.billing_days || l.rate_method">
                                                    <span x-show="l.billing_days"><span x-text="l.billing_days"></span> days</span>
                                                    <span x-show="l.rate_method">&middot; <span x-text="l.rate_method"></span></span>
                                                </div>
                                                <div class="brc-line-sub" x-show="l.mileage_distance">
                                                    <span x-text="l.mileage_distance"></span> <span x-text="l.mileage_unit"></span>
                                                    <span x-show="l.mileage_rate">@ <span x-text="l.mileage_rate"></span></span>
                                                    <span x-show="l.mileage_estimated">&middot; est <span x-text="l.mileage_estimated"></span></span>
                                                    <span x-show="l.mileage_actual">&middot; actual <span x-text="l.mileage_actual"></span></span>
                                                </div>
                                            </td>
                                            <td class="brc-nowrap"><span x-text="l.period_start || '—'"></span><span x-show="l.period_end"> → <span x-text="l.period_end"></span></span></td>
                                            <td class="text-right"><span x-text="trimNum(l.quantity)"></span> <span class="text-secondary" x-show="l.unit" x-text="l.unit"></span></td>
                                            <td class="text-right currency" x-text="fmtMoney(l.unit_price)"></td>
                                            <td class="text-right currency" :class="{ 'text-danger': l.is_credit }" x-text="(l.is_credit ? '−' : '') + fmtMoney(l.amount)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Money summary -->
                        <div class="brc-summary">
                            <div><span>Subtotal</span><b x-text="fmtMoney(p.subtotal)"></b></div>
                            <div x-show="+p.discount_amount > 0"><span>Discount</span><b x-text="'−' + fmtMoney(p.discount_amount)"></b></div>
                            <div x-show="+p.tax_gst_amount > 0"><span>GST</span><b x-text="fmtMoney(p.tax_gst_amount)"></b></div>
                            <div x-show="+p.tax_pst_amount > 0"><span>PST</span><b x-text="fmtMoney(p.tax_pst_amount)"></b></div>
                            <div x-show="+p.tax_hst_amount > 0"><span>HST</span><b x-text="fmtMoney(p.tax_hst_amount)"></b></div>
                            <div class="brc-summary-total"><span>Total</span><b x-text="fmtMoney(p.total_amount) + ' ' + p.currency"></b></div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>

<style>
    /* Denser page + a wider preview pane (operator request): the preview is
       an actual invoice document, so it needs real width to be readable. */
    /* Single column by default — the preview column only exists while an
       invoice is open (see the right-column comment in the markup). */
    .batch-split { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; align-items: start; }
    .batch-split.has-preview { grid-template-columns: minmax(0, 1fr) clamp(460px, 38vw, 720px); }
    .batch-left { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
    .batch-right { position: sticky; top: 12px; }
    .batch-preview-card { display: flex; flex-direction: column; height: calc(100vh - 100px); min-height: 560px; }

    /* Density: tighten this page's cards without touching the global .card. */
    #batch-invoicing-app .card-body { padding: 14px 16px; }
    #batch-invoicing-app .card-header { padding: 10px 16px; }
    #batch-invoicing-app .data-table td { padding: 7px 10px; }
    #batch-invoicing-app .data-table thead th { padding: 8px 10px; }
    #batch-invoicing-app .table-toolbar { margin-bottom: 12px; }
    .batch-preview-body { flex: 1; min-height: 0; display: flex; }
    .batch-preview-empty { display: flex; align-items: center; justify-content: center; flex: 1; padding: 24px; text-align: center; }
    .batch-preview-iframe { flex: 1; width: 100%; border: 0; border-radius: 0 0 var(--radius-xl) var(--radius-xl); background: var(--bg-surface); }

    /* Collapsible card header — a real <button> so it is keyboard- and
       screen-reader-operable, restyled to sit flush like a .card-header. */
    .batch-collapse-header {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        width: 100%; text-align: left; cursor: pointer;
        background: var(--bg-surface-2); border: 0; border-bottom: 1px solid var(--border-color);
        font: inherit; color: inherit;
    }
    .batch-collapse-header:hover { background: var(--bg-surface-hover); }
    .batch-collapse-title { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
    .batch-collapse-caret {
        display: inline-block; font-size: 17px; line-height: 1; color: var(--text-secondary);
        transition: transform 150ms ease; transform: rotate(0deg);
    }
    .batch-collapse-caret.is-open { transform: rotate(90deg); }

    .batch-period-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; }
    .batch-period-inputs { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

    /* .tab-bar is width:100% globally — override so a 2-button mode switch
       hugs its content instead of spanning the card and wrapping everything
       after it onto new lines. */
    .batch-period-tabs { width: auto !important; flex: 0 0 auto; margin-bottom: 0 !important; padding: 4px; border-radius: 11px; }
    .batch-period-tabs .tab-btn { padding: 6px 14px; font-size: 13px; }

    /* ← [month] → as one segmented control so the arrows read as part of
       the field rather than as loose buttons. */
    .batch-month-stepper {
        display: inline-flex; align-items: stretch;
        border: 1px solid var(--border-color); border-radius: var(--radius-lg);
        background: var(--bg-input); overflow: hidden;
    }
    .batch-step-btn {
        display: flex; align-items: center; justify-content: center;
        width: 32px; border: 0; cursor: pointer; font-size: 13px; line-height: 1;
        background: transparent; color: var(--text-secondary);
        transition: background 120ms ease, color 120ms ease;
    }
    .batch-step-btn:hover { background: var(--bg-surface-hover); color: var(--text-primary); }
    .batch-month-input {
        border: 0; border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color);
        background: transparent; color: var(--text-primary);
        font-family: var(--font-sans); font-size: 13px;
        padding: 7px 10px; min-width: 148px;
    }
    .batch-month-input:focus { outline: none; box-shadow: none; }
    .batch-quick-months { display: flex; gap: 6px; }

    .batch-period-summary {
        display: flex; align-items: center; flex-wrap: wrap; gap: 6px 10px;
        margin-top: 14px; padding-top: 12px;
        border-top: 1px solid var(--border-color);
        font-size: 13px;
    }
    .batch-period-summary strong { font-family: var(--font-mono); font-size: 12.5px; }

    .batch-customer-list { display: flex; flex-direction: column; gap: 12px; max-height: 620px; overflow-y: auto; padding-right: 4px; }
    /* flex-shrink:0 is LOAD-BEARING: .batch-customer-list is a column flexbox
       with a max-height, so its children default to flex-shrink:1 and get
       SQUASHED to fit instead of overflowing into the scroll area. Combined
       with overflow:hidden here that collapsed each group to ~8px and clipped
       every row's text — the list rendered as blank bars (worse the more
       customers there were). Do not remove. */
    .batch-customer-group { flex-shrink: 0; border: 1px solid var(--border-color); border-radius: var(--radius-lg); overflow: hidden; background: var(--bg-surface); }
    .batch-customer-row { display: flex; align-items: center; gap: 12px; padding: 13px 16px; background: var(--bg-surface-2); cursor: pointer; transition: background 120ms ease; }
    .batch-customer-row:hover { background: var(--bg-surface-3, var(--bg-surface-2)); }
    .batch-customer-name { flex: 1; min-width: 0; display: flex; align-items: center; flex-wrap: wrap; gap: 6px; font-size: 14px; }
    .batch-chip { font-size: 10px; }
    .batch-lease-count { font-size: 11px; color: var(--text-secondary); }
    .batch-customer-recipient { font-size: 12px; color: var(--text-secondary); text-align: right; flex-shrink: 0; max-width: 40%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .batch-lease-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px 10px 42px; border-top: 1px solid var(--border-color); font-size: 13px; cursor: pointer; transition: background 120ms ease; }
    .batch-lease-row:hover { background: var(--bg-surface-2); }
    .batch-lease-row.is-selected { background: color-mix(in srgb, var(--color-primary) 9%, transparent); }
    .batch-lease-info { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .batch-lease-contract { font-family: var(--font-mono, monospace); font-size: 12px; }
    .batch-lease-status { flex-shrink: 0; display: flex; align-items: center; }

    .batch-recipient-list { display: flex; flex-direction: column; gap: 8px; }
    .batch-recipient-row { display: flex; align-items: center; gap: 10px; }
    .batch-recipient-name { min-width: 200px; font-size: 13px; }

    .batch-action-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; background: var(--bg-surface-2); border: 1px solid var(--border-color); border-radius: var(--radius-lg); }

    .batch-send-options { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 20px; }
    .batch-restored-note {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        flex-wrap: wrap; padding: 9px 14px; margin-bottom: 4px;
        border-radius: var(--radius-lg); font-size: 12.5px;
        color: var(--text-secondary);
        background: var(--color-info-light);
        border: 1px solid color-mix(in srgb, var(--color-info) 30%, transparent);
    }
    .batch-restored-note strong { color: var(--text-primary); }

    .batch-option-notes {
        margin-top: 12px; padding-top: 12px;
        border-top: 1px solid var(--border-color);
        font-size: 12.5px; color: var(--text-secondary); line-height: 1.55;
    }
    .batch-option-notes p { margin: 0 0 7px; }
    .batch-option-notes p:last-child { margin-bottom: 0; }
    .batch-option-notes strong { color: var(--text-primary); font-weight: 600; }
    .batch-option-notes code {
        font-family: var(--font-mono); font-size: 11.5px;
        padding: 1px 5px; border-radius: 4px;
        background: var(--bg-surface-2); border: 1px solid var(--border-color);
    }

    /* Totals strip — the batch as a financial number, not just a row count. */
    .batch-total-strip { display: flex; flex-wrap: wrap; gap: 10px; }
    .batch-total { flex: 1; min-width: 130px; padding: 10px 14px; background: var(--bg-surface-2); border: 1px solid var(--border-color); border-radius: var(--radius-lg); }
    .batch-total-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); }
    .batch-total-value { font-size: 17px; font-weight: 600; font-family: var(--font-mono); margin-top: 2px; }
    .batch-row-error td { opacity: 0.75; }

    /* ══════════════════════════════════════════════════════════════
       FULL-SCREEN BILLING REVIEW
       Every colour here comes from a design token so the surface works
       in BOTH themes (:root is dark, [data-theme="light"] overrides).
       Tints use color-mix() against --color-primary rather than baked
       rgba(), so they re-tint automatically under a brand override and
       stay legible on warm-paper light as well as atelier dark.
       NOTE: do NOT use var(--bg-base, <literal>) — that token does not
       exist, so the literal fallback would paint a near-black overlay in
       LIGHT mode (the exact "light palette missing a var" trap that hit
       the notification rows).
       ══════════════════════════════════════════════════════════════ */
    /* z-index above the AI chat widget (9999) — it is fixed bottom-right and
       otherwise floats over this overlay's per-invoice totals. */
<?php
/* Re-assert the runtime brand override INSIDE the forced-light overlay.
   Mirrors includes/header.php: the override rebinds exactly these three
   vars on :root, but the overlay's own [data-theme="light"] token block
   re-declares --color-primary (stock orange) at a more specific scope and
   would otherwise win, un-branding the totals and rental chips. Same
   fallbacks as header.php so the two can't drift apart silently. */
$_ffBrandPrimary = settings_get('brand.primary_color');
$_ffBrandHover   = settings_get('brand.primary_hover');
$_ffBrandLight   = settings_get('brand.primary_light');
if ($_ffBrandPrimary): ?>
    .batch-review-overlay[data-theme="light"] {
        --color-primary:       <?= e((string) $_ffBrandPrimary) ?>;
        --color-primary-hover: <?= e((string) ($_ffBrandHover ?: '#1e7ea0')) ?>;
        --color-primary-light: <?= e((string) ($_ffBrandLight ?: '#e0f4fb')) ?>;
    }
<?php endif; ?>
    /* app.css declares a set of "spec-name alias" tokens on :root as
       --alias: var(--real). A var() reference is substituted at the element
       that DECLARES it, so each alias computed against the DARK palette on
       :root and then inherits into this subtree as a frozen dark literal —
       re-binding --bg-surface here does NOT re-resolve --bg-card. Any app.css
       rule reaching for an alias therefore painted dark inside the forced-light
       overlay (the responsive .table-stack cards use var(--bg-card) and came
       out dark-on-dark). Re-declare the aliases here so they re-resolve. */
    .batch-review-overlay[data-theme="light"] {
        --bg-page:            var(--bg-body);
        --bg-card:            var(--bg-surface);
        --bg-elev:            var(--bg-surface-2);
        --bg-elevated:        var(--bg-surface-2);
        --bg-subtle:          var(--bg-surface-2);
        --bg-selected:        color-mix(in srgb, var(--color-primary) 12%, transparent);
        --border-default:     var(--border-color);
        --border-strong:      var(--border-color-strong);
        --text-muted:         var(--text-tertiary);
        --text-inverse:       var(--text-on-primary);
        --color-accent:       var(--color-primary);
        --color-accent-hover: var(--color-primary-hover);
        --card-sheen:         inset 0 1px 0 rgba(255, 255, 255, 0.9);
    }

    .batch-review-overlay {
        position: fixed; inset: 0; z-index: 10000;
        /* MUST re-assert color explicitly. `color` inherits as a COMPUTED
           value, so <body> already resolved --text-primary to the DARK ink
           and passes that down; re-binding the token on this subtree alone
           left near-white text on the now-white cards. Anything below that
           doesn't set its own colour inherits from here instead. */
        color: var(--text-primary);
        background:
            radial-gradient(1200px 600px at 12% -8%, color-mix(in srgb, var(--color-primary) 11%, transparent), transparent 70%),
            radial-gradient(900px 500px at 100% 0%, color-mix(in srgb, var(--color-info) 8%, transparent), transparent 65%),
            var(--bg-body);
        display: flex; flex-direction: column;
    }
    /* Hide the floating chat launcher while reviewing so nothing covers a total.
       :has() is well-supported in current browsers; the z-index above is the
       belt-and-braces fallback if it ever isn't. */
    body:has(.batch-review-overlay) .ff-chat-fab { display: none !important; }

    .batch-review-head {
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
        padding: 16px 28px; flex-shrink: 0;
        border-bottom: 1px solid var(--border-color);
        background: color-mix(in srgb, var(--bg-surface) 82%, transparent);
        backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
        box-shadow: var(--card-sheen), 0 1px 0 color-mix(in srgb, var(--border-color) 60%, transparent);
    }
    .batch-review-title {
        font-size: 20px; font-weight: 650; letter-spacing: -0.02em;
        display: flex; align-items: center; gap: 10px;
    }
    .brc-dryrun-pill {
        font-size: 9.5px; font-weight: 700; letter-spacing: 0.09em; text-transform: uppercase;
        padding: 3px 9px; border-radius: 999px; color: var(--color-warning-text);
        background: var(--color-warning-light);
        border: 1px solid color-mix(in srgb, var(--color-warning) 35%, transparent);
    }
    .batch-review-sub { font-size: 12.5px; color: var(--text-secondary); margin-top: 3px; }
    .batch-review-head-actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; }
    .batch-review-body { flex: 1; min-height: 0; overflow-y: auto; padding: 22px 28px 56px; }
    .batch-review-body > * { max-width: 1500px; margin-left: auto; margin-right: auto; }

    .batch-review-section-title {
        font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em;
        color: var(--text-secondary); margin-bottom: 8px; font-weight: 600;
    }
    .batch-review-problems {
        margin: 18px auto 0; padding: 14px 18px; border-radius: var(--radius-xl);
        border: 1px solid color-mix(in srgb, var(--color-danger) 40%, transparent);
        background: var(--color-danger-light);
    }
    .batch-problem-row {
        display: flex; flex-wrap: wrap; gap: 10px; align-items: baseline;
        font-size: 13px; padding: 5px 0;
        border-top: 1px solid color-mix(in srgb, var(--color-danger) 15%, transparent);
    }
    .batch-problem-row:first-of-type { border-top: 0; }

    /* ── Hero stat tiles ─────────────────────────────────────────── */
    .batch-review-body .batch-total-strip { gap: 14px; }
    .batch-review-body .batch-total {
        position: relative; overflow: hidden;
        padding: 16px 18px; border-radius: var(--radius-xl);
        background: linear-gradient(160deg,
            color-mix(in srgb, var(--bg-surface) 96%, var(--color-primary)) 0%,
            var(--bg-surface) 60%);
        border: 1px solid var(--border-color);
        box-shadow: var(--card-sheen), var(--shadow-md);
    }
    .batch-review-body .batch-total::before {
        content: ""; position: absolute; inset: 0 auto 0 0; width: 3px;
        background: linear-gradient(180deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 25%, transparent));
    }
    .batch-review-body .batch-total-label {
        font-size: 10px; letter-spacing: 0.09em; font-weight: 600;
    }
    .batch-review-body .batch-total-value {
        font-size: 24px; font-weight: 700; letter-spacing: -0.02em; margin-top: 4px;
        font-variant-numeric: tabular-nums;
    }

    /* ── Invoice cards ───────────────────────────────────────────── */
    .batch-review-card {
        margin-top: 18px; border-radius: var(--radius-xl);
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        box-shadow: var(--card-sheen), var(--shadow-md);
        overflow: hidden;
        transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease;
        animation: brcRise 320ms ease both;
    }
    .batch-review-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--card-sheen), var(--shadow-lg);
        border-color: color-mix(in srgb, var(--color-primary) 30%, var(--border-color));
    }
    @keyframes brcRise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) {
        .batch-review-card { animation: none; transition: none; }
        .batch-review-card:hover { transform: none; }
    }

    .brc-head {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
        padding: 16px 20px; position: relative;
        background: linear-gradient(135deg,
            color-mix(in srgb, var(--color-primary) 7%, var(--bg-surface-2)) 0%,
            var(--bg-surface-2) 55%);
        border-bottom: 1px solid var(--border-color);
    }
    .brc-customer { font-size: 16.5px; font-weight: 650; letter-spacing: -0.01em; }
    .brc-meta {
        font-size: 12px; color: var(--text-secondary); margin-top: 5px;
        display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
    }
    .brc-head-total { text-align: right; flex-shrink: 0; }
    .brc-total-value {
        font-size: 22px; font-weight: 700; letter-spacing: -0.02em;
        font-variant-numeric: tabular-nums;
        /* --color-primary (NOT --color-primary-text): header.php's runtime
           brand override rebinds only --color-primary/-hover/-light, so
           --color-primary-text keeps the palette default (orange) and would
           render an orange total on a blue-branded deployment. Large bold
           text, so the brand hue clears the 3:1 large-text bar in both themes. */
        color: var(--color-primary);
    }

    /* ── Facts: hairline grid that reads as one block ────────────── */
    .brc-facts {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr));
        gap: 1px; background: var(--border-color);
        border-bottom: 1px solid var(--border-color);
    }
    .brc-fact {
        background: var(--bg-surface); padding: 9px 16px; font-size: 12px;
        display: flex; justify-content: space-between; gap: 10px; align-items: baseline;
        transition: background 130ms ease;
    }
    .brc-fact:hover { background: var(--bg-surface-2); }
    .brc-fact > span { color: var(--text-secondary); white-space: nowrap; }
    .brc-fact > b {
        font-family: var(--font-mono); font-size: 11.5px; text-align: right;
        font-variant-numeric: tabular-nums; font-weight: 600;
    }

    /* ── Line items ──────────────────────────────────────────────── */
    .brc-lines { font-size: 12.5px; }
    .brc-lines thead th {
        font-size: 9.5px; letter-spacing: 0.08em;
        background: color-mix(in srgb, var(--bg-surface-2) 60%, transparent);
    }
    .brc-lines tbody tr { transition: background 130ms ease; }
    .brc-lines tbody tr:hover { background: color-mix(in srgb, var(--color-primary) 5%, transparent); }
    .brc-lines .currency { font-variant-numeric: tabular-nums; }
    .brc-line-type {
        font-size: 9.5px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase;
        padding: 3px 8px; border-radius: 999px; white-space: nowrap;
        color: var(--text-secondary); background: var(--bg-surface-2);
        border: 1px solid var(--border-color);
    }
    /* Semantic tint per line family so the eye can scan a long invoice. */
    /* rental uses the BRAND hue and must follow a brand override, so it mixes
       from --color-primary rather than the non-overridden --color-primary-text
       / --color-primary-light pair. Mixing the label toward --text-primary
       keeps this 9.5px chip legible on the tint in light AND dark. */
    .brc-line-type[data-fam="rental"] {
        color: color-mix(in srgb, var(--color-primary) 62%, var(--text-primary));
        background: color-mix(in srgb, var(--color-primary) 13%, transparent);
        border-color: color-mix(in srgb, var(--color-primary) 30%, transparent);
    }
    .brc-line-type[data-fam="usage"]  { color: var(--color-info-text);    background: var(--color-info-light);    border-color: color-mix(in srgb, var(--color-info) 30%, transparent); }
    .brc-line-type[data-fam="credit"] { color: var(--color-success-text); background: var(--color-success-light); border-color: color-mix(in srgb, var(--color-success) 30%, transparent); }
    .brc-line-type[data-fam="fee"]    { color: var(--color-warning-text); background: var(--color-warning-light); border-color: color-mix(in srgb, var(--color-warning) 30%, transparent); }
    .brc-line-sub { font-size: 11px; color: var(--text-secondary); margin-top: 3px; }
    .brc-nowrap { white-space: nowrap; }

    /* ── Money summary ───────────────────────────────────────────── */
    .brc-summary {
        display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center;
        gap: 10px 26px; padding: 14px 20px;
        border-top: 1px solid var(--border-color);
        background: linear-gradient(180deg, transparent, color-mix(in srgb, var(--color-primary) 4%, var(--bg-surface-2)));
    }
    .brc-summary > div { display: flex; gap: 9px; align-items: baseline; font-size: 12.5px; }
    .brc-summary span { color: var(--text-secondary); }
    .brc-summary b { font-family: var(--font-mono); font-variant-numeric: tabular-nums; }
    .brc-summary-total {
        padding-left: 22px; margin-left: 2px;
        border-left: 1px solid var(--border-color);
    }
    .brc-summary-total span { color: var(--text-primary); font-weight: 600; }
    .brc-summary-total b {
        font-size: 17px; font-weight: 700; letter-spacing: -0.01em;
        color: var(--color-primary);   /* brand-override safe — see .brc-total-value */
    }

    /* ── Print: force a light, ink-friendly document ─────────────── */
    @media print {
        /* Hide CHROME ONLY — never .app-layout / .app-main / .page-content.
           The review overlay is a DESCENDANT of all three (body > .app-layout
           > .app-main > main.page-content > … > .batch-review-overlay), so
           display:none on any of them hid the very thing we're printing and
           produced a blank page. Neutralise those wrappers instead. */
        .sidebar, .sidebar-overlay, .topbar, .app-footer, .ff-chat-fab,
        #ff-toast-container, .breadcrumb, .page-header { display: none !important; }

        .app-layout, .app-main, .page-content {
            display: block !important;
            margin: 0 !important; padding: 0 !important;
            max-width: none !important; width: auto !important;
        }

        /* The selection workflow behind the overlay must not print. */
        .batch-split { display: none !important; }

        /* Un-fix the overlay so it flows across as many pages as it needs —
           a position:fixed element only renders its first viewport in print. */
        .batch-review-overlay {
            position: static !important; inset: auto !important;
            z-index: auto !important; height: auto !important;
            background: #fff !important; color: #000 !important;
        }
        .batch-review-head { background: #fff !important; backdrop-filter: none; box-shadow: none; }
        .batch-review-head-actions { display: none !important; }
        .batch-review-body {
            overflow: visible !important; height: auto !important;
            max-height: none !important; padding: 0 !important;
        }
        .batch-review-card {
            break-inside: avoid; page-break-inside: avoid;
            border-color: #ccc; box-shadow: none; animation: none; margin-top: 12px;
        }
        .brc-head, .brc-summary, .batch-review-body .batch-total { background: #f6f6f6 !important; }
        .brc-total-value, .brc-summary-total b { color: #000 !important; }
    }

    /* Presets */
    .batch-presets { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color); }
    .batch-presets-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-right: 2px; }
    .batch-preset-chip { display: inline-flex; align-items: center; background: var(--bg-surface-2); border: 1px solid var(--border-color); border-radius: 999px; overflow: hidden; }
    .batch-preset-apply { background: none; border: 0; color: var(--text-primary); font-size: 12px; padding: 4px 4px 4px 12px; cursor: pointer; }
    .batch-preset-apply:hover { color: var(--color-primary); }
    .batch-preset-del { background: none; border: 0; color: var(--text-secondary); font-size: 14px; line-height: 1; padding: 4px 10px 4px 6px; cursor: pointer; }
    .batch-preset-del:hover { color: var(--color-danger); }

    .batch-add-results { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
    .batch-add-result-row { display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; background: var(--bg-surface-2); border-radius: var(--radius-md); font-size: 13px; }

    .batch-row-active { background: var(--color-primary-light); }

    @media (max-width: 1100px) {
        .batch-split, .batch-split.has-preview { grid-template-columns: 1fr; }
        .batch-right { position: static; }
        .batch-preview-card { height: 70vh; }
    }
</style>

<script>
function BatchInvoicing(cfg) {
    return {
        canGenerate: cfg.canGenerate,
        canSend: cfg.canSend,

        // ── Period ──────────────────────────────────────────────
        periodMode: 'month',
        monthValue: cfg.defaultMonth,
        rangeStart: cfg.today,
        rangeEnd: cfg.today,
        periodStart: '',
        periodEnd: '',
        periodIsFullMonth: true,

        // ── Eligibility ─────────────────────────────────────────
        eligLoading: false,
        eligError: '',
        customers: [],          // raw API payload
        displayCustomers: [],   // PRECOMPUTED render list (see rebuildDisplay())
        eligSummary: {},
        search: '',
        statusFilter: 'unbilled',
        selected: {},           // leaseId -> true
        customerEmailOverrides: {}, // customerId -> email string (this run only)

        // ── Presets / dry-run / download / approval ─────────────
        presets: [],
        previewing: false,
        previewResult: null,
        reviewOpen: false,
        recipientsOpen: false,
        downloading: '',
        submitting: false,
        runs: [],

        // ── Generation ──────────────────────────────────────────
        generating: false,
        lastGenerateResult: null,

        // ── Review & Send ───────────────────────────────────────
        reviewItems: [],        // [{invoice_id, invoice_number, customer_id, company_name, total_amount, status, recipient_email, has_pdf, pdfWorking, sendingOne}]
        reviewSelected: {},     // invoiceId -> true
        overrides: {},          // invoiceId -> email override string
        addDraftQuery: '',
        addDraftResults: [],
        sendEmailToo: true,
        attachPdf: true,
        sending: false,
        lastSendResult: null,

        // ── Preview ─────────────────────────────────────────────
        previewInvoiceId: null,
        previewUrl: '',

        init() {
            // Alpine can run init() twice — keep this idempotent (restore reads
            // sessionStorage, which is safe to re-read; the load* calls just
            // re-fetch). See the Alpine double-init note in app.js §07h.
            this.restoreState();
            this.updatePeriod();
            this.loadEligible();
            this.loadPresets();
            this.loadRuns();
            // Persist on unload as a catch-all, plus the explicit saves below.
            window.addEventListener('beforeunload', () => this.persistState());
        },

        // ==========================================================
        // Session persistence (S-BATCH-CROSSCHECK)
        //
        // Building a selection over 150+ leases and reviewing it is real
        // work, and cross-checking the maths means leaving this page to
        // open an invoice. Losing the whole list on the way back made that
        // check expensive enough to skip — which is the opposite of what a
        // review step is for. State is stashed in sessionStorage (per tab,
        // cleared when the tab closes) and restored on return.
        //
        // previewResult is deliberately NOT persisted: it is a dry run of
        // live data, it can be megabytes over a big selection, and silently
        // restoring stale figures after a round-trip is exactly the kind of
        // "approved numbers that no longer hold" problem this feature
        // exists to prevent. It survives closing the REVIEW (in memory),
        // not navigating away.
        // ==========================================================
        stateKey: 'ff_batch_state_v1',
        persistState() {
            try {
                sessionStorage.setItem(this.stateKey, JSON.stringify({
                    periodMode: this.periodMode,
                    monthValue: this.monthValue,
                    periodStart: this.periodStart,
                    periodEnd: this.periodEnd,
                    selected: this.selected,
                    customerEmailOverrides: this.customerEmailOverrides,
                    reviewItems: this.reviewItems,
                    reviewSelected: this.reviewSelected,
                    overrides: this.overrides,
                    sendEmailToo: this.sendEmailToo,
                    attachPdf: this.attachPdf,
                    recipientsOpen: this.recipientsOpen,
                    savedAt: new Date().toISOString(),
                }));
            } catch (e) { /* quota or private mode — persistence is a nicety */ }
        },
        restoreState() {
            try {
                const raw = sessionStorage.getItem(this.stateKey);
                if (!raw) return;
                const s = JSON.parse(raw);
                if (!s || typeof s !== 'object') return;
                if (s.periodMode)  this.periodMode  = s.periodMode;
                if (s.monthValue)  this.monthValue  = s.monthValue;
                if (s.periodStart) this.periodStart = s.periodStart;
                if (s.periodEnd)   this.periodEnd   = s.periodEnd;
                this.selected               = s.selected               || {};
                this.customerEmailOverrides = s.customerEmailOverrides || {};
                this.reviewItems            = s.reviewItems            || [];
                this.reviewSelected         = s.reviewSelected         || {};
                this.overrides              = s.overrides              || {};
                if (typeof s.sendEmailToo === 'boolean') this.sendEmailToo = s.sendEmailToo;
                if (typeof s.attachPdf      === 'boolean') this.attachPdf      = s.attachPdf;
                if (typeof s.recipientsOpen === 'boolean') this.recipientsOpen = s.recipientsOpen;
                this.restoredFrom = s.savedAt || null;
                this.selectionRestored = Object.keys(this.selected).length > 0;
            } catch (e) { /* corrupt payload — start clean rather than break the page */ }
        },
        restoredFrom: null,
        selectionRestored: false,
        clearSavedState() {
            try { sessionStorage.removeItem(this.stateKey); } catch (e) {}
            this.selected = {}; this.customerEmailOverrides = {};
            this.reviewItems = []; this.reviewSelected = {}; this.overrides = {};
            this.previewResult = null; this.reviewOpen = false; this.restoredFrom = null;
            this.selectionRestored = false;
        },

        // ==========================================================
        // Approval runs
        // ==========================================================
        async loadRuns() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/invoices/batch_runs') ?>?limit=10');
                if (r.success) this.runs = r.data.runs || [];
            } catch (e) { /* non-fatal */ }
        },
        runUrl(id) { return '<?= base_url('invoices/batch_run') ?>?id=' + id; },
        async submitForApproval() {
            const leaseIds = this.selectedLeaseIds();
            if (!leaseIds.length || this.submitting) return;
            const note = await FF_Confirm.askText({
                title: 'Submit ' + leaseIds.length + ' lease' + (leaseIds.length === 1 ? '' : 's') + ' for approval',
                message: 'The figures are frozen now and an approver reviews them before anything is created. Add a note for them (optional).',
                confirmLabel: 'Submit for approval',
                placeholder: 'e.g. September monthly run',
            });
            // askText returns '' when cancelled AND when submitted blank; treat
            // null/undefined as cancel, '' as "submitted with no note".
            if (note === null || note === undefined) return;

            this.submitting = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/batch_runs/create') ?>', {
                    period_start: this.periodStart,
                    period_end: this.periodEnd,
                    lease_ids: leaseIds,
                    note: note,
                });
                if (r.success) {
                    FF_Toast.success('Submitted as ' + r.data.reference + ' — awaiting approval.');
                    await this.loadRuns();
                    this.persistState();   // keep the selection for the trip back
                    window.location.href = this.runUrl(r.data.id);
                } else {
                    FF_Toast.error(r.error?.message || 'Could not submit for approval.');
                }
            } catch (e) {
                FF_Toast.error('Network error while submitting.');
            } finally {
                this.submitting = false;
            }
        },

        // ==========================================================
        // Presets
        // ==========================================================
        async loadPresets() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/invoices/batch_presets') ?>');
                if (r.success) this.presets = r.data.presets || [];
            } catch (e) { /* non-fatal — presets are a convenience */ }
        },
        async savePreset() {
            const leaseIds = this.selectedLeaseIds();
            if (!leaseIds.length) return;
            const name = await FF_Confirm.askText({
                title: 'Save preset',
                message: 'Name this selection so you can re-apply it next month.',
                confirmLabel: 'Save preset',
                placeholder: 'e.g. Monthly — all trucking accounts',
            });
            if (!name) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/batch_presets') ?>', {
                    name: name,
                    customer_ids: this.selectedCustomerIds(),
                    lease_ids: leaseIds,
                    status_filter: this.statusFilter,
                    send_email: this.sendEmailToo,
                    attach_pdf: this.attachPdf,
                });
                if (r.success) { this.presets = r.data.presets || []; FF_Toast.success('Preset saved.'); }
                else FF_Toast.error(r.error?.message || 'Could not save preset.');
            } catch (e) { FF_Toast.error('Network error saving preset.'); }
        },
        applyPreset(p) {
            // Re-apply the SELECTION only — the period stays whatever is picked
            // above, and any lease in the preset that isn't eligible for this
            // period simply won't be present in displayCustomers to select.
            const wanted = new Set(p.lease_ids || []);
            const next = {};
            let matched = 0;
            this.customers.forEach(c => c.leases.forEach(l => {
                if (wanted.has(l.id)) { next[l.id] = true; matched++; }
            }));
            this.selected = next;
            if (p.status_filter) { this.statusFilter = p.status_filter; this.rebuildDisplay(); }
            this.sendEmailToo = !!p.send_email;
            this.attachPdf = !!p.attach_pdf;
            const missing = (p.lease_ids || []).length - matched;
            FF_Toast.success('Preset "' + p.name + '" applied — ' + matched + ' lease' + (matched === 1 ? '' : 's') + ' selected'
                + (missing > 0 ? ' (' + missing + ' not eligible this period)' : '') + '.');
        },
        async deletePreset(p) {
            const ok = await FF_Confirm.ask('Delete preset "' + p.name + '"?');
            if (!ok) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/batch_presets') ?>', { _action: 'delete', id: p.id });
                if (r.success) { this.presets = r.data.presets || []; FF_Toast.success('Preset deleted.'); }
                else FF_Toast.error(r.error?.message || 'Could not delete preset.');
            } catch (e) { FF_Toast.error('Network error deleting preset.'); }
        },

        // ==========================================================
        // Dry run — computes exact totals without creating anything
        // ==========================================================
        async dryRun() {
            const leaseIds = this.selectedLeaseIds();
            if (!leaseIds.length || this.previewing) return;
            this.previewing = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/batch_preview') ?>', {
                    period_start: this.periodStart,
                    period_end: this.periodEnd,
                    lease_ids: leaseIds,
                });
                if (r.success) {
                    this.previewResult = r.data;
                    this.reviewOpen = true;
                    const t = r.data.totals;
                    if (t.error_count) FF_Toast.error(t.error_count + ' lease' + (t.error_count === 1 ? '' : 's') + ' cannot be billed — see the preview table.');
                    else FF_Toast.success('Preview ready — nothing was created.');
                } else {
                    FF_Toast.error(r.error?.message || 'Preview failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error during preview.');
            } finally {
                this.previewing = false;
            }
        },
        /** Selected customers with no address on file AND no override typed —
         *  surfaced on the collapsed header so a missing email is visible
         *  without expanding the section. */
        missingEmailCount() {
            let n = 0;
            this.selectedCustomerIds().forEach(cid => {
                const c = this.customerById(cid);
                if (!c) return;
                const override = (this.customerEmailOverrides[cid] || '').trim();
                if (!override && !(c.recipient && c.recipient.email)) n++;
            });
            return n;
        },
        previewCustomerCount() {
            const ids = new Set();
            (this.previewResult?.previews || []).forEach(p => { if (p.ok && p.customer_id) ids.add(p.customer_id); });
            return ids.size;
        },
        /** Show the rate in the lease's OWN unit — mileage_rate_km and
         *  mileage_rate_miles are separate columns and only one is the
         *  lease's billing unit (see the mileage-unit conversion rules). */
        mileageRateOf(p) {
            const t = p.terms || {};
            const unit = t.mileage_unit || 'km';
            const r = unit === 'miles' ? (t.mileage_rate_miles ?? t.mileage_rate) : (t.mileage_rate_km ?? t.mileage_rate);
            if (r === null || r === undefined || +r === 0) return '';
            return this.fmtMoney(r) + ' / ' + unit;
        },
        /** Group a line's item_type into a colour family so a long invoice
         *  can be scanned by eye. Credits win over everything (a credit
         *  mileage line should read green, not blue). */
        lineFamily(l) {
            const t = String(l.item_type || '');
            if (l.is_credit || t.includes('credit')) return 'credit';
            if (t === 'base_rental' || t === 'insurance' || t === 'warranty') return 'rental';
            if (t.startsWith('mileage') || t.startsWith('hours') || t === 'hourly_usage' || t === 'gps') return 'usage';
            if (t === 'late_fee' || t === 'damage' || t === 'cartage' || t === 'sweep' || t === 'wash' || t === 'fuel') return 'fee';
            return '';
        },
        trimNum(v) {
            const n = parseFloat(v);
            if (isNaN(n)) return v;
            return String(parseFloat(n.toFixed(4)));
        },
        printReview() { window.print(); },
        previewCurrencyText() {
            const by = this.previewResult?.totals?.by_currency || {};
            const parts = Object.keys(by).map(c => this.fmtMoney(by[c]) + ' ' + c);
            return parts.length ? parts.join('  +  ') : '—';
        },
        reviewTotalText() {
            const by = {};
            this.reviewItems.forEach(i => {
                const c = i.currency || 'CAD';
                by[c] = (by[c] || 0) + (parseFloat(i.total_amount) || 0);
            });
            const parts = Object.keys(by).map(c => this.fmtMoney(by[c].toFixed(2)) + ' ' + c);
            return parts.length ? parts.join('  +  ') : '—';
        },

        // ==========================================================
        // Bulk download (ZIP of PDFs, or one combined PDF)
        // ==========================================================
        async downloadBatch(format) {
            if (this.downloading) return;
            const ids = this.reviewItems.map(i => i.invoice_id);
            if (!ids.length) return;
            this.downloading = format;
            try {
                // Not FF_Api: this endpoint streams a binary body, not the JSON
                // envelope FF_Api unwraps. Same CSRF header contract though.
                const res = await fetch('<?= base_url('api/v1/invoices/batch_download') ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ ids: ids, format: format }),
                });
                const ctype = res.headers.get('content-type') || '';
                if (!res.ok || ctype.includes('application/json')) {
                    let msg = 'Download failed.';
                    try { const j = await res.json(); msg = j.error?.message || msg; } catch (e) {}
                    FF_Toast.error(msg);
                    return;
                }
                const blob = await res.blob();
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'invoices_' + this.periodStart + (format === 'zip' ? '.zip' : '.pdf');
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);
                FF_Toast.success(ids.length + ' invoice' + (ids.length === 1 ? '' : 's') + ' downloaded.');
            } catch (e) {
                FF_Toast.error('Network error during download.');
            } finally {
                this.downloading = '';
            }
        },

        // ==========================================================
        // Period helpers
        // ==========================================================
        applyPreset(p) {
            const [y, m] = cfg.today.split('-').map(Number);
            if (p === 'this_month') {
                this.periodMode = 'month';
                this.monthValue = cfg.today.slice(0, 7);
            } else if (p === 'last_month') {
                this.periodMode = 'month';
                const d = new Date(Date.UTC(y, m - 2, 1));
                this.monthValue = d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0');
            }
            this.onPeriodChanged();
        },
        shiftMonth(delta) {
            const [y, m] = this.monthValue.split('-').map(Number);
            const d = new Date(Date.UTC(y, m - 1 + delta, 1));
            this.monthValue = d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0');
            this.onPeriodChanged();
        },
        updatePeriod() {
            if (this.periodMode === 'month' && this.monthValue) {
                const [y, m] = this.monthValue.split('-').map(Number);
                this.periodStart = this.monthValue + '-01';
                this.periodEnd = new Date(Date.UTC(y, m, 0)).toISOString().slice(0, 10);
                this.periodIsFullMonth = true;
            } else {
                this.periodStart = this.rangeStart;
                this.periodEnd = this.rangeEnd;
                const startOfMonth = this.periodStart && this.periodStart.slice(8, 10) === '01';
                this.periodIsFullMonth = !!(startOfMonth && this.periodEnd === this.endOfMonth(this.periodStart));
            }
        },
        endOfMonth(dateStr) {
            const [y, m] = dateStr.split('-').map(Number);
            return new Date(Date.UTC(y, m, 0)).toISOString().slice(0, 10);
        },

        // ==========================================================
        // Eligibility
        // ==========================================================
        /** Period change means a different billing context — drop the restored
         *  selection so auto-select defaults apply to the new period. */
        onPeriodChanged() {
            this.selectionRestored = false;
            this.updatePeriod();
            this.loadEligible();
        },
        async loadEligible() {
            if (!this.periodStart || !this.periodEnd) return;
            this.eligLoading = true;
            this.eligError = '';
            try {
                const params = new URLSearchParams({ period_start: this.periodStart, period_end: this.periodEnd });
                if (this.search) params.set('search', this.search);
                const res = await FF_Api.get('<?= base_url('api/v1/invoices/batch_eligible') ?>?' + params.toString());
                if (res.success) {
                    this.customers = res.data.customers;
                    this.eligSummary = res.data.summary;
                    // Auto-select unbilled leases so "Generate" has sane defaults.
                    //
                    // EXCEPT on the first load after restoring a session: a
                    // deselected lease is stored as an ABSENT key, not false, so
                    // the `!== false` default below would happily re-select every
                    // lease the operator had just excluded — restoring 4 as 21.
                    // Honour the restored set verbatim once, then resume defaults.
                    const honourRestored = this.selectionRestored;

                    const stillValid = {};
                    this.customers.forEach(c => c.leases.forEach(l => {
                        if (l.billing_status !== 'unbilled') return;
                        if (honourRestored) {
                            if (this.selected[l.id]) stillValid[l.id] = true;
                        } else if (this.selected[l.id] !== false) {
                            stillValid[l.id] = true;
                        }
                    }));
                    this.selected = stillValid;
                    this.rebuildDisplay();
                } else {
                    this.customers = [];
                    this.displayCustomers = [];
                    this.eligError = res.error?.message || 'Failed to load eligible leases.';
                }
            } catch (e) {
                this.eligError = 'Network error while loading eligible leases.';
            } finally {
                this.eligLoading = false;
            }
        },

        /**
         * Rebuild the render list. Called EXPLICITLY whenever the inputs
         * change (new API payload, or the status filter) — never from a
         * template binding. Each entry carries its own already-filtered
         * `leases` array so the markup can iterate plain data and Alpine's
         * :key diffing actually reuses DOM nodes between ticks.
         */
        rebuildDisplay() {
            const f = this.statusFilter;
            const out = [];
            for (const c of this.customers) {
                const leases = (f === 'all') ? c.leases : c.leases.filter(l => l.billing_status === f);
                if (leases.length === 0) continue;
                out.push({ ...c, leases });
            }
            this.displayCustomers = out;
        },
        customerById(id) {
            return this.customers.find(c => c.id === id) || { company_name: '', recipient: { email: '', warnings: [] } };
        },

        // ==========================================================
        // Selection
        // ==========================================================
        toggleLease(l) {
            if (this.selected[l.id]) delete this.selected[l.id];
            else this.selected[l.id] = true;
        },
        toggleCustomerAll(c) {
            const allOn = c.leases.length > 0 && c.leases.every(l => !!this.selected[l.id]);
            c.leases.forEach(l => {
                if (allOn) delete this.selected[l.id];
                else this.selected[l.id] = true;
            });
        },
        selectAllUnbilled() {
            this.selectionRestored = false;
            this.customers.forEach(c => c.leases.forEach(l => {
                if (l.billing_status === 'unbilled') this.selected[l.id] = true;
            }));
        },
        clearSelection() {
            this.selectionRestored = false;
            this.selected = {};
        },
        selectedLeaseIds() {
            return Object.keys(this.selected).filter(id => this.selected[id]).map(Number);
        },
        selectedCustomerIds() {
            const ids = new Set();
            this.customers.forEach(c => c.leases.forEach(l => { if (this.selected[l.id]) ids.add(c.id); }));
            return Array.from(ids);
        },

        // ==========================================================
        // Generate
        // ==========================================================
        async generate() {
            const leaseIds = this.selectedLeaseIds();
            if (leaseIds.length === 0 || this.generating) return;
            const confirmed = await FF_Confirm.ask(
                'Generate ' + leaseIds.length + ' draft invoice' + (leaseIds.length === 1 ? '' : 's') +
                ' for ' + this.periodStart + ' to ' + this.periodEnd + '? Invoices are created as drafts — nothing is sent yet.'
            );
            if (!confirmed) return;

            this.generating = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/invoices/batch_generate') ?>', {
                    period_start: this.periodStart,
                    period_end: this.periodEnd,
                    lease_ids: leaseIds,
                });
                if (res.success) {
                    this.lastGenerateResult = res.data;
                    const d = res.data;
                    if (d.actioned > 0) FF_Toast.success(d.actioned + ' draft invoice' + (d.actioned === 1 ? '' : 's') + ' created.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' lease' + (d.errors.length === 1 ? '' : 's') + ' skipped — see details below.');

                    d.invoices.forEach(inv => {
                        const customer = this.customers.find(c => c.id === inv.customer_id);
                        this.reviewItems.push({
                            invoice_id: inv.invoice_id,
                            invoice_number: inv.invoice_number,
                            customer_id: inv.customer_id,
                            company_name: customer ? customer.company_name : ('Customer #' + inv.customer_id),
                            total_amount: inv.total_amount,
                            status: 'draft',
                            recipient_email: (customer && customer.recipient.email) || '',
                            currency: (customer && customer.currency) || 'CAD',
                            has_pdf: false,
                            pdfWorking: false,
                            sendingOne: false,
                        });
                        this.reviewSelected[inv.invoice_id] = true;
                        const overrideEmail = this.customerEmailOverrides[inv.customer_id];
                        if (overrideEmail) this.overrides[inv.invoice_id] = overrideEmail;
                    });

                    if (d.invoices.length) this.openPreview(d.invoices[0].invoice_id);
                    this.persistState();

                    await this.loadEligible(); // refresh billing_status badges (now 'billed')
                } else {
                    FF_Toast.error(res.error?.message || 'Batch generation failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error during batch generation.');
            } finally {
                this.generating = false;
            }
        },

        // ==========================================================
        // Review & Send
        // ==========================================================
        async searchAddDraft() {
            if (!this.addDraftQuery) { this.addDraftResults = []; return; }
            try {
                const params = new URLSearchParams({ status: 'draft', q: this.addDraftQuery, per_page: '10' });
                const res = await FF_Api.get('<?= base_url('api/v1/invoices') ?>?' + params.toString());
                if (res.success) {
                    const existingIds = new Set(this.reviewItems.map(i => i.invoice_id));
                    this.addDraftResults = (res.data.items || []).filter(r => !existingIds.has(r.id));
                }
            } catch (e) { /* non-fatal — leave results empty */ }
        },
        addDraftToReview(r) {
            // api/v1/invoices (list) does not return customer_email_snapshot
            // (Trap 7-style redaction — see that file's payload comment), so
            // there's no default recipient to prefill here; the operator can
            // still type one directly in the Send To column.
            this.reviewItems.push({
                invoice_id: r.id,
                invoice_number: r.invoice_number,
                customer_id: r.customer_id,
                company_name: r.company_name_snapshot || ('Customer #' + r.customer_id),
                total_amount: r.total_amount,
                status: r.status,
                recipient_email: '',
                currency: r.currency || 'CAD',
                has_pdf: false,
                pdfWorking: false,
                sendingOne: false,
            });
            this.reviewSelected[r.id] = true;
            this.addDraftResults = this.addDraftResults.filter(x => x.id !== r.id);
        },
        closePreview() {
            this.previewInvoiceId = null;
            this.previewUrl = '';
        },
        openPreviewNewTab(invoiceId) {
            // Cross-checking an invoice must never cost the operator their
            // list — a new tab leaves this page (and its state) untouched.
            window.open('<?= base_url('invoices/show') ?>?id=' + invoiceId, '_blank', 'noopener');
        },
        toggleReviewSelect(id) {
            if (this.reviewSelected[id]) delete this.reviewSelected[id];
            else this.reviewSelected[id] = true;
        },
        allReviewSelected() {
            const sendable = this.reviewItems.filter(i => i.status === 'draft');
            return sendable.length > 0 && sendable.every(i => !!this.reviewSelected[i.invoice_id]);
        },
        toggleReviewAll() {
            const allOn = this.allReviewSelected();
            this.reviewItems.filter(i => i.status === 'draft').forEach(i => {
                if (allOn) delete this.reviewSelected[i.invoice_id];
                else this.reviewSelected[i.invoice_id] = true;
            });
        },
        reviewSelectedIds() {
            return Object.keys(this.reviewSelected).filter(id => this.reviewSelected[id]).map(Number)
                .filter(id => (this.reviewItems.find(i => i.invoice_id === id) || {}).status === 'draft');
        },

        async bulkSend() {
            const ids = this.reviewSelectedIds();
            if (ids.length === 0 || this.sending) return;
            const label = this.sendEmailToo ? 'send and email' : 'mark as sent (no email)';
            const confirmed = await FF_Confirm.ask('Ready to ' + label + ' ' + ids.length + ' invoice' + (ids.length === 1 ? '' : 's') + '? Sent invoices are frozen and cannot be edited.');
            if (!confirmed) return;

            this.sending = true;
            try {
                await this.doSend(ids);
            } finally {
                this.sending = false;
            }
        },

        /** Send a single invoice from its row — same endpoint/options as
         *  bulkSend(), just scoped to one id, for the "individually send"
         *  workflow alongside the bulk action. */
        async sendOne(invoiceId) {
            const item = this.reviewItems.find(i => i.invoice_id === invoiceId);
            if (!item || item.sendingOne) return;
            const label = this.sendEmailToo ? 'send and email' : 'mark as sent (no email)';
            const confirmed = await FF_Confirm.ask('Ready to ' + label + ' ' + item.invoice_number + '? Sent invoices are frozen and cannot be edited.');
            if (!confirmed) return;

            item.sendingOne = true;
            try {
                await this.doSend([invoiceId]);
            } finally {
                item.sendingOne = false;
            }
        },

        /** Shared POST + response-handling core for bulkSend()/sendOne(). */
        async doSend(ids) {
            try {
                const emailOverrides = {};
                ids.forEach(id => { if (this.overrides[id]) emailOverrides[id] = this.overrides[id]; });

                const res = await FF_Api.post('<?= base_url('api/v1/invoices/bulk_send') ?>', {
                    ids: ids,
                    send_email: this.sendEmailToo,
                    attach_pdf: this.sendEmailToo && this.attachPdf,
                    email_overrides: emailOverrides,
                });
                if (res.success) {
                    this.lastSendResult = res.data;
                    const d = res.data;
                    if (d.actioned > 0) FF_Toast.success(d.actioned + ' invoice' + (d.actioned === 1 ? '' : 's') + ' sent' + (this.sendEmailToo ? ' (' + d.emailed + ' emailed)' : '') + '.');
                    if (d.errors?.length || d.email_errors?.length) FF_Toast.error('Some invoices had issues — see details below.');

                    this.reviewItems.forEach(item => {
                        if (ids.includes(item.invoice_id) && !(d.errors || []).some(e => e.id === item.invoice_id)) {
                            item.status = 'sent';
                            if (this.sendEmailToo && this.attachPdf) item.has_pdf = true;
                        }
                    });
                } else {
                    FF_Toast.error(res.error?.message || 'Send failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error while sending.');
            }
        },

        /** Generate (or re-open) a review-row invoice's PDF — mirrors the
         *  single-invoice page's own "Generate PDF" button. */
        async viewOrGeneratePdf(item) {
            if (item.pdfWorking) return;
            item.pdfWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/invoices/generate_pdf') ?>', { id: item.invoice_id });
                if (res.success) {
                    item.has_pdf = true;
                    window.open(res.data.download_url, '_blank');
                } else {
                    FF_Toast.error(res.error?.message || 'Failed to generate PDF.');
                }
            } catch (e) {
                FF_Toast.error('Network error generating PDF.');
            } finally {
                item.pdfWorking = false;
            }
        },

        // ==========================================================
        // Preview
        // ==========================================================
        openPreview(invoiceId) {
            this.previewInvoiceId = invoiceId;
            this.previewUrl = '<?= base_url('invoices/show') ?>?id=' + invoiceId + '&embed=1';
        },
        fullInvoiceUrl() {
            return '<?= base_url('invoices/show') ?>?id=' + this.previewInvoiceId;
        },

        // ==========================================================
        // Formatting
        // ==========================================================
        fmtMoney(v) {
            const n = parseFloat(v);
            if (isNaN(n)) return v;
            return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
