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
    <div class="batch-split">

        <!-- ────────────────────────── LEFT COLUMN ────────────────────────── -->
        <div class="batch-left">

            <!-- ── 1. Period ─────────────────────────────────────────── -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">1. Billing Period</span>
                </div>
                <div class="card-body">
                    <div class="batch-period-row">
                        <div class="tab-bar" style="margin-bottom:0;">
                            <button type="button" class="tab-btn" :class="{ 'is-active': periodMode === 'month' }" @click="periodMode = 'month'; updatePeriod(); loadEligible();">Calendar Month</button>
                            <button type="button" class="tab-btn" :class="{ 'is-active': periodMode === 'range' }" @click="periodMode = 'range'; updatePeriod(); loadEligible();">Custom Range</button>
                        </div>

                        <template x-if="periodMode === 'month'">
                            <div class="batch-period-inputs">
                                <button type="button" class="btn btn-ghost btn-sm" @click="shiftMonth(-1)" title="Previous month">&larr;</button>
                                <input type="month" class="form-control form-control-sm" x-model="monthValue" @change="updatePeriod(); loadEligible();" style="max-width:170px;">
                                <button type="button" class="btn btn-ghost btn-sm" @click="shiftMonth(1)" title="Next month">&rarr;</button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="applyPreset('this_month')">This Month</button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="applyPreset('last_month')">Last Month</button>
                            </div>
                        </template>
                        <template x-if="periodMode === 'range'">
                            <div class="batch-period-inputs">
                                <input type="date" class="form-control form-control-sm" x-model="rangeStart" @change="updatePeriod(); loadEligible();">
                                <span class="text-secondary">to</span>
                                <input type="date" class="form-control form-control-sm" x-model="rangeEnd" @change="updatePeriod(); loadEligible();">
                            </div>
                        </template>
                    </div>
                    <div class="text-secondary text-sm" style="margin-top:10px;">
                        Billing period: <strong x-text="periodStart"></strong> &rarr; <strong x-text="periodEnd"></strong>
                        <span x-show="periodIsFullMonth" class="badge badge-no-dot badge-info" style="margin-left:6px;">Full calendar month — bills as full_month</span>
                        <span x-show="!periodIsFullMonth" class="badge badge-no-dot badge-neutral" style="margin-left:6px;">Custom span — bills as single_period</span>
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

            <!-- ── 3. Recipient emails for selected customers ────────── -->
            <div class="card" x-show="selectedCustomerIds().length > 0">
                <div class="card-header">
                    <span class="card-title">3. Recipient Emails</span>
                    <span class="text-secondary text-sm">Applies to invoices generated in this run — for this batch only, not saved to the customer record</span>
                </div>
                <div class="card-body">
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

            <!-- ── Generate action bar ───────────────────────────────── -->
            <div class="batch-action-bar" x-show="canGenerate">
                <div>
                    <strong x-text="selectedLeaseIds().length"></strong> lease<span x-show="selectedLeaseIds().length !== 1">s</span> selected
                    <span class="text-secondary text-sm" x-show="selectedLeaseIds().length"> across <span x-text="selectedCustomerIds().length"></span> customer<span x-show="selectedCustomerIds().length !== 1">s</span></span>
                </div>
                <button type="button" class="btn btn-primary" :disabled="selectedLeaseIds().length === 0 || generating" @click="generate()">
                    <span x-show="!generating">Generate <span x-text="selectedLeaseIds().length"></span> Draft Invoice<span x-show="selectedLeaseIds().length !== 1">s</span></span>
                    <span x-show="generating">Generating…</span>
                </button>
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

                    <!-- Send options — deliberately their OWN row, not squeezed into
                         .table-toolbar-right alongside the search box above: that class
                         is shared by every other list page in the app (flex-shrink:0,
                         nowrap) and two long-label checkboxes there collided with and
                         visually overlapped the search input at normal card widths. -->
                    <div class="batch-send-options">
                        <label class="form-check" style="margin:0;font-size:13px;">
                            <input type="checkbox" class="form-check-input" x-model="sendEmailToo">
                            Also email the invoice_ready template on send
                        </label>
                        <label class="form-check" style="margin:0;font-size:13px;" x-show="sendEmailToo">
                            <input type="checkbox" class="form-check-input" x-model="attachPdf">
                            Attach PDF
                        </label>
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

                    <div class="batch-action-bar" x-show="reviewItems.length && canSend" style="margin-top:14px;">
                        <div>
                            <strong x-text="reviewSelectedIds().length"></strong> selected for sending
                        </div>
                        <button type="button" class="btn btn-primary" :disabled="reviewSelectedIds().length === 0 || sending" @click="bulkSend()">
                            <span x-show="!sending" x-text="sendEmailToo ? 'Send & Email ' + reviewSelectedIds().length : 'Mark ' + reviewSelectedIds().length + ' as Sent'"></span>
                            <span x-show="sending">Sending…</span>
                        </button>
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

        <!-- ────────────────────────── RIGHT COLUMN — PREVIEW ─────────────── -->
        <div class="batch-right">
            <div class="card batch-preview-card">
                <div class="card-header">
                    <span class="card-title">Preview</span>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <template x-if="previewInvoiceId">
                            <a :href="fullInvoiceUrl()" target="_blank" class="btn btn-ghost btn-sm">Open Full Page ↗</a>
                        </template>
                    </div>
                </div>
                <div class="batch-preview-body">
                    <template x-if="!previewInvoiceId">
                        <div class="batch-preview-empty text-secondary text-sm">
                            Select an invoice on the left to preview it here — no download needed.
                        </div>
                    </template>
                    <template x-if="previewInvoiceId">
                        <iframe :src="previewUrl" class="batch-preview-iframe" title="Invoice preview"></iframe>
                    </template>
                </div>
            </div>
        </div>

    </div>
</div>

<style>
    .batch-split { display: grid; grid-template-columns: minmax(0, 1fr) 460px; gap: 16px; align-items: start; }
    .batch-left { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
    .batch-right { position: sticky; top: 16px; }
    .batch-preview-card { display: flex; flex-direction: column; height: calc(100vh - 130px); min-height: 480px; }
    .batch-preview-body { flex: 1; min-height: 0; display: flex; }
    .batch-preview-empty { display: flex; align-items: center; justify-content: center; flex: 1; padding: 24px; text-align: center; }
    .batch-preview-iframe { flex: 1; width: 100%; border: 0; border-radius: 0 0 var(--radius-xl) var(--radius-xl); background: var(--bg-surface); }

    .batch-period-row { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; }
    .batch-period-inputs { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

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

    .batch-send-options { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 20px; margin-bottom: 14px; }

    .batch-add-results { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
    .batch-add-result-row { display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; background: var(--bg-surface-2); border-radius: var(--radius-md); font-size: 13px; }

    .batch-row-active { background: var(--color-primary-light); }

    @media (max-width: 1100px) {
        .batch-split { grid-template-columns: 1fr; }
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
            this.updatePeriod();
            this.loadEligible();
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
            this.updatePeriod();
            this.loadEligible();
        },
        shiftMonth(delta) {
            const [y, m] = this.monthValue.split('-').map(Number);
            const d = new Date(Date.UTC(y, m - 1 + delta, 1));
            this.monthValue = d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0');
            this.updatePeriod();
            this.loadEligible();
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
                    const stillValid = {};
                    this.customers.forEach(c => c.leases.forEach(l => {
                        if (l.billing_status === 'unbilled' && this.selected[l.id] !== false) {
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
            this.customers.forEach(c => c.leases.forEach(l => {
                if (l.billing_status === 'unbilled') this.selected[l.id] = true;
            }));
        },
        clearSelection() {
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
                            has_pdf: false,
                            pdfWorking: false,
                            sendingOne: false,
                        });
                        this.reviewSelected[inv.invoice_id] = true;
                        const overrideEmail = this.customerEmailOverrides[inv.customer_id];
                        if (overrideEmail) this.overrides[inv.invoice_id] = overrideEmail;
                    });

                    if (d.invoices.length) this.openPreview(d.invoices[0].invoice_id);

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
                has_pdf: false,
                pdfWorking: false,
                sendingOne: false,
            });
            this.reviewSelected[r.id] = true;
            this.addDraftResults = this.addDraftResults.filter(x => x.id !== r.id);
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
