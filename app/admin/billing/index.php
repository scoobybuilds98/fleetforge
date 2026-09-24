<?php
declare(strict_types=1);

/**
 * app/admin/billing/index.php
 *
 * S-BILLING-MODULE — Billing home: where the monthly billing cycle is run.
 *
 * Top to bottom:
 *   - the cycle to work on now (per Billing → Settings "which month a cycle
 *     bills"), with its seven-step progress, or a button to start it;
 *   - four queue tiles: open billing exceptions, runs waiting for approval,
 *     active billing holds, the draft backlog of earlier months;
 *   - tabs:
 *       Cycles      every month's cycle + months with unsent drafts and no
 *                   cycle yet (open one to work the backlog)
 *       Exceptions  the "couldn't bill this" queue (was the Needs Attention
 *                   panel on Batch Invoicing) with open / resolved / ignored
 *                   history and Reopen
 *       Approvals   batch runs submitted for approval (was on Batch Invoicing)
 *       Holds       standing billing holds on leases / customers
 *
 * Nothing here computes billing: every figure comes from api/v1/billing/*,
 * api/v1/invoices/billing_exceptions/* and api/v1/invoices/batch_runs/*.
 * Amounts are shown only to can_view_financials() roles — the APIs redact.
 *
 * @depends api/v1/billing/{kpis,cycles/index,cycles/open,holds/*},
 *          api/v1/invoices/billing_exceptions/{index,resolve},
 *          api/v1/invoices/batch_runs/index, public/assets/css/billing.css
 * @session S-BILLING-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

$canCreate  = can('invoices', 'create');
$canEdit    = can('invoices', 'edit');
$showMoney  = can_view_financials();

$pageTitle      = 'Billing';
$helpModuleSlug = 'billing';
require_once FF_ROOT . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/billing.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

<?php ob_start(); ?>
    <?= help_button('billing') ?>
    <a href="<?= base_url('billing/settings') ?>" class="btn btn-secondary btn-sm">
        <?= heroicon('cog-6-tooth', 'icon-sm') ?> Settings
    </a>
    <a href="<?= base_url('billing/run') ?>" class="btn btn-secondary btn-sm">
        <?= heroicon('document-duplicate', 'icon-sm') ?> Workbench
    </a>
    <?php if ($canCreate): ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="window.dispatchEvent(new CustomEvent('bc-open-month'))">
        + Open a month
    </button>
    <?php endif; ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'crumbs'   => [['Dashboard', base_url('dashboard')], ['Billing', null]],
    'eyebrow'  => 'Billing',
    'icon'     => 'calendar-days',
    'accent'   => 'primary',
    'title'    => 'Monthly Billing',
    'subtitle' => 'Run each month from preparation to close — readiness, readings, generate, review, approve, send, close.',
    'art'      => 'invoices',
    'actions'  => ob_get_clean(),
]) ?>

<div id="billing-home" x-data="FF_BillingHome()" x-cloak>

    <!-- ── The cycle to work on now ─────────────────────────────── -->
    <div class="bc-spotlight" x-show="kpis">
        <div>
            <div class="bc-spotlight-eyebrow">Cycle to work on now</div>
            <div class="bc-spotlight-title" x-text="kpis ? kpis.target_label : ''"></div>
            <template x-if="kpis && kpis.target_cycle">
                <div>
                    <div class="bc-spotlight-meta">
                        <span class="bc-pill" :class="kpis.target_cycle.status === 'closed' ? 'bc-tone-success' : 'bc-tone-primary'"
                              x-text="kpis.target_cycle.status === 'closed' ? 'Closed' : 'Open'"></span>
                        <span x-text="kpis.target_cycle.reference"></span>
                        <span x-show="kpis.target_cycle.owner_name" x-text="'Owner: ' + kpis.target_cycle.owner_name"></span>
                        <span x-show="kpis.target_cycle.send_by_date" x-text="'Send by ' + fmtDate(kpis.target_cycle.send_by_date)"></span>
                    </div>
                    <div class="bc-progress" style="margin-top:10px;"><span :style="'width:' + (kpis.target_cycle.stage ? kpis.target_cycle.stage.percent : 0) + '%'"></span></div>
                    <div class="bc-spotlight-actions">
                        <a class="btn btn-primary btn-sm" :href="cycleUrl(kpis.target_cycle.id)">Continue the cycle →</a>
                    </div>
                </div>
            </template>
            <template x-if="kpis && !kpis.target_cycle">
                <div>
                    <div class="bc-spotlight-meta"><span>Not started yet.</span></div>
                    <div class="bc-spotlight-actions">
                        <?php if ($canCreate): ?>
                        <button type="button" class="btn btn-primary btn-sm" @click="openMonth(kpis.target_month)" :disabled="opening">
                            <span x-text="opening ? 'Opening…' : 'Start the ' + kpis.target_label + ' cycle'"></span>
                        </button>
                        <?php else: ?>
                        <span class="bc-muted text-sm">Someone who can create invoices starts the cycle.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </template>
        </div>
        <div>
            <template x-if="kpis && kpis.target_cycle && kpis.target_cycle.stage">
                <div class="bc-stepper">
                    <template x-for="(s, i) in kpis.target_cycle.stage.steps" :key="s.key">
                        <div class="bc-step" :data-state="s.state" :class="{ 'is-attention': s.attention }">
                            <div class="bc-step-top">
                                <span class="bc-step-dot" x-text="s.state === 'done' ? '✓' : (i + 1)"></span>
                                <span class="bc-step-label" x-text="s.label"></span>
                            </div>
                            <div class="bc-step-hint" x-text="s.hint"></div>
                        </div>
                    </template>
                </div>
            </template>
            <template x-if="kpis && !kpis.target_cycle">
                <p class="bc-muted" style="margin:0; font-size:13.5px; line-height:1.55;">
                    A cycle checks the month before anything is billed (rates, readings, recipients, tax, earlier gaps),
                    collects the manual meter readings, bills from the workbench, flags anything unusual against last month,
                    tracks what reached each customer, then closes and locks the month with a summary.
                </p>
            </template>
        </div>
    </div>

    <!-- ── Queue tiles ─────────────────────────────────────────── -->
    <div class="stat-grid stat-grid--4 ff-stats">
        <button type="button" class="stat-card stat-card--red" style="text-align:left;cursor:pointer;" @click="setTab('exceptions')">
            <span class="stat-icon stat-icon--red"><svg><use href="#icon-exclamation-triangle"/></svg></span>
            <div class="stat-label">Billing exceptions</div>
            <div class="stat-value font-mono" x-text="kpis ? kpis.open_exceptions : '—'"></div>
            <div class="stat-delta">leases that could not be billed</div>
        </button>
        <button type="button" class="stat-card stat-card--amber" style="text-align:left;cursor:pointer;" @click="setTab('approvals')">
            <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clipboard"/></svg></span>
            <div class="stat-label">Awaiting approval</div>
            <div class="stat-value font-mono" x-text="kpis ? kpis.pending_runs : '—'"></div>
            <div class="stat-delta" x-text="kpis && kpis.approved_runs ? kpis.approved_runs + ' approved, not generated' : 'batch runs'"></div>
        </button>
        <button type="button" class="stat-card stat-card--purple" style="text-align:left;cursor:pointer;" @click="setTab('holds')">
            <span class="stat-icon stat-icon--purple"><svg><use href="#icon-lock-open"/></svg></span>
            <div class="stat-label">Billing holds</div>
            <div class="stat-value font-mono" x-text="kpis ? kpis.active_holds : '—'"></div>
            <div class="stat-delta">leases / customers paused</div>
        </button>
        <button type="button" class="stat-card stat-card--slate" style="text-align:left;cursor:pointer;" @click="setTab('cycles')">
            <span class="stat-icon stat-icon--slate"><svg><use href="#icon-clock"/></svg></span>
            <div class="stat-label">Unsent from earlier months</div>
            <div class="stat-value font-mono" x-text="kpis ? kpis.backlog_drafts : '—'"></div>
            <div class="stat-delta" x-text="kpis ? (kpis.backlog_months + ' month' + (kpis.backlog_months === 1 ? '' : 's') + (kpis.backlog_total_cad !== null ? ' · ' + money(kpis.backlog_total_cad) : '')) : ''"></div>
        </button>
    </div>

    <!-- ── Tabs ────────────────────────────────────────────────── -->
    <div class="table-toolbar">
        <div class="table-toolbar-left">
            <div class="tab-bar" role="tablist" aria-label="Billing">
                <template x-for="t in tabs" :key="t.key">
                    <button class="tab-btn" role="tab" :class="{ 'is-active': tab === t.key }" :aria-selected="tab === t.key"
                            @click="setTab(t.key)">
                        <span x-text="t.label"></span>
                        <span class="badge badge-sm badge-neutral" x-show="t.count() > 0" x-text="t.count()" style="margin-left:6px;"></span>
                    </button>
                </template>
            </div>
        </div>
        <div class="table-toolbar-right">
            <template x-if="tab === 'exceptions'">
                <select class="form-select form-control-sm" x-model="exc.status" @change="loadExceptions()" aria-label="Exception status">
                    <option value="open">Open</option>
                    <option value="resolved">Resolved</option>
                    <option value="ignored">Ignored</option>
                </select>
            </template>
            <template x-if="tab === 'approvals'">
                <select class="form-select form-control-sm" x-model="runs.status" @change="loadRuns()" aria-label="Run status">
                    <option value="">All runs</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="generated">Generated</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </template>
            <template x-if="tab === 'holds'">
                <div style="display:flex; gap:8px;">
                    <select class="form-select form-control-sm" x-model="holds.state" @change="loadHolds()" aria-label="Hold state">
                        <option value="active">Active</option>
                        <option value="released">Released / ended</option>
                        <option value="all">All</option>
                    </select>
                    <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-primary btn-sm" @click="openHoldModal()">+ New hold</button>
                    <?php endif; ?>
                </div>
            </template>
        </div>
    </div>

    <!-- ── CYCLES ──────────────────────────────────────────────── -->
    <div x-show="tab === 'cycles'">
        <div class="card">
            <template x-if="cycles.loading"><div><template x-for="n in 5" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!cycles.loading && !cycles.rows.length">
                <div class="bc-empty">No billing cycles yet. Start this month's cycle above, or open a month.</div>
            </template>
            <template x-if="!cycles.loading && cycles.rows.length">
                <div style="overflow-x:auto;">
                <table class="table" aria-label="Billing cycles">
                    <thead>
                        <tr>
                            <th>Month</th><th>Status</th><th>Readiness</th>
                            <th class="text-right">Invoices</th><th class="text-right">Drafts</th><th class="text-right">Sent</th>
                            <?php if ($showMoney): ?><th class="text-right">Billed (CAD)</th><?php endif; ?>
                            <th>Owner</th><th>Send by</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="c in cycles.rows" :key="c.id">
                            <tr>
                                <td class="bc-nowrap"><a :href="cycleUrl(c.id)" x-text="c.label"></a> <span class="bc-muted text-sm" x-text="c.reference"></span></td>
                                <td><span class="bc-pill" :class="c.status === 'closed' ? 'bc-tone-success' : 'bc-tone-primary'" x-text="c.status === 'closed' ? 'Closed' : 'Open'"></span></td>
                                <td class="bc-nowrap">
                                    <template x-if="!c.readiness_summary"><span class="bc-muted text-sm">Not run</span></template>
                                    <template x-if="c.readiness_summary">
                                        <span style="display:inline-flex; gap:4px;">
                                            <span class="bc-pill bc-tone-danger" x-show="c.readiness_summary.blocker" x-text="c.readiness_summary.blocker + ' blocker'"></span>
                                            <span class="bc-pill bc-tone-warning" x-show="c.readiness_summary.warning" x-text="c.readiness_summary.warning + ' warn'"></span>
                                            <span class="bc-pill bc-tone-success" x-show="!c.readiness_summary.blocker && !c.readiness_summary.warning">Clear</span>
                                        </span>
                                    </template>
                                </td>
                                <td class="text-right bc-mono" x-text="c.stats.live"></td>
                                <td class="text-right bc-mono" :style="c.stats.drafts ? 'color:var(--color-warning);font-weight:600;' : ''" x-text="c.stats.drafts"></td>
                                <td class="text-right bc-mono" x-text="c.stats.issued"></td>
                                <?php if ($showMoney): ?><td class="text-right bc-mono" x-text="money(c.stats.total_cad)"></td><?php endif; ?>
                                <td class="bc-nowrap" x-text="c.owner_name || '—'"></td>
                                <td class="bc-nowrap" :style="c.status === 'open' && c.send_by_date && c.send_by_date < today && c.stats.drafts ? 'color:var(--color-danger);font-weight:600;' : ''"
                                    x-text="c.send_by_date ? fmtDate(c.send_by_date) : '—'"></td>
                                <td class="text-right"><a class="btn btn-ghost btn-xs" :href="cycleUrl(c.id)">View</a></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
        <div class="card" x-show="cycles.backlog.length" style="margin-top:14px;">
            <div class="card-header"><h3 class="card-title">Months with unsent drafts and no cycle yet</h3></div>
            <div class="card-body" style="padding-top:0;">
                <p class="bc-muted text-sm" style="margin:0 0 10px;">
                    These drafts have not been billed to anyone. Open the month as a cycle to review and send (or void) them —
                    work through a large backlog in stages, oldest first.
                </p>
                <div style="overflow-x:auto;">
                <table class="table">
                    <thead><tr><th>Month</th><th class="text-right">Unsent drafts</th><?php if ($showMoney): ?><th class="text-right">Draft total (CAD)</th><?php endif; ?><th></th></tr></thead>
                    <tbody>
                        <template x-for="b in (cycles.showAllBacklog ? cycles.backlog : cycles.backlog.slice(0, 6))" :key="b.month">
                            <tr>
                                <td class="bc-nowrap" x-text="b.label"></td>
                                <td class="text-right bc-mono" x-text="b.drafts"></td>
                                <?php if ($showMoney): ?><td class="text-right bc-mono" x-text="money(b.draft_total_cad)"></td><?php endif; ?>
                                <td class="text-right">
                                    <?php if ($canCreate): ?>
                                    <button class="btn btn-secondary btn-xs" @click="openMonth(b.month)" :disabled="opening">Open cycle</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" x-show="cycles.backlog.length > 6" @click="cycles.showAllBacklog = !cycles.showAllBacklog"
                        x-text="cycles.showAllBacklog ? 'Show fewer' : 'Show all ' + cycles.backlog.length + ' months'"></button>
            </div>
        </div>

    </div>

    <!-- ── EXCEPTIONS ──────────────────────────────────────────── -->
    <div x-show="tab === 'exceptions'">
        <div class="card">
            <div class="card-body" style="padding-bottom:0;">
                <p class="bc-muted text-sm" style="margin:0 0 10px;">
                    A lease lands here when a run could not bill it (no rate, lease changed, already billed…) or someone held it for review.
                    It clears itself when the lease bills for that period. <strong>Fixed</strong> resolves it; <strong>Ignore</strong> needs a note.
                </p>
            </div>
            <template x-if="exc.loading"><div><template x-for="n in 4" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!exc.loading && !exc.rows.length">
                <div class="bc-empty" x-text="exc.status === 'open' ? 'Nothing needs attention.' : 'None.'"></div>
            </template>
            <template x-if="!exc.loading && exc.rows.length">
                <div style="overflow-x:auto;">
                <table class="table" aria-label="Billing exceptions">
                    <thead><tr><th>Lease</th><th>Customer</th><th>Period</th><th>Reason</th><th class="text-right">Times</th><th x-text="exc.status === 'open' ? 'Last flagged' : 'Resolution'"></th><th></th></tr></thead>
                    <tbody>
                        <template x-for="x in exc.rows" :key="x.id">
                            <tr>
                                <td class="bc-nowrap"><a :href="base + '/leases/show?id=' + x.lease_id" x-text="x.contract_number || ('#' + x.lease_id)"></a>
                                    <div class="bc-muted text-sm" x-text="x.unit_number ? 'Unit ' + x.unit_number : ''"></div></td>
                                <td x-text="x.company_name || '—'"></td>
                                <td class="bc-nowrap" x-text="fmtDate(x.period_start) + ' – ' + fmtDate(x.period_end)"></td>
                                <td style="max-width:420px;" x-text="x.reason"></td>
                                <td class="text-right bc-mono" x-text="x.flagged_count"></td>
                                <td class="bc-nowrap text-sm">
                                    <template x-if="exc.status === 'open'"><span x-text="FF_formatUtc(x.last_flagged_at)"></span></template>
                                    <template x-if="exc.status !== 'open'"><span x-text="(x.resolved_by_name || '') + (x.resolution_note ? ': ' + x.resolution_note : '')"></span></template>
                                </td>
                                <td class="text-right bc-nowrap">
                                    <?php if ($canEdit): ?>
                                    <template x-if="exc.status === 'open'">
                                        <span style="display:inline-flex; gap:4px;">
                                            <button class="btn btn-secondary btn-xs" @click="resolveException(x, 'resolve')">Fixed</button>
                                            <button class="btn btn-ghost btn-xs" @click="resolveException(x, 'ignore')">Ignore</button>
                                            <button class="btn btn-ghost btn-xs" title="Stop billing this lease until released" @click="openHoldModal({ scope: 'lease', lease_id: x.lease_id, label: x.contract_number, reason: x.reason })">Hold</button>
                                        </span>
                                    </template>
                                    <template x-if="exc.status !== 'open'">
                                        <button class="btn btn-ghost btn-xs" @click="resolveException(x, 'reopen')">Reopen</button>
                                    </template>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
    </div>

    <!-- ── APPROVALS ───────────────────────────────────────────── -->
    <div x-show="tab === 'approvals'">
        <div class="card">
            <template x-if="runs.loading"><div><template x-for="n in 4" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!runs.loading && !runs.rows.length">
                <div class="bc-empty">No batch runs. Runs are submitted from the workbench when "Require approval before batch billing" is on (Billing → Settings).</div>
            </template>
            <template x-if="!runs.loading && runs.rows.length">
                <div style="overflow-x:auto;">
                <table class="table" aria-label="Batch runs">
                    <thead><tr><th>Run</th><th>Period</th><th>Status</th><th class="text-right">Invoices</th><?php if ($showMoney): ?><th class="text-right">Total</th><?php endif; ?><th>Submitted</th><th>Decided</th></tr></thead>
                    <tbody>
                        <template x-for="r in runs.rows" :key="r.id">
                            <tr>
                                <td class="bc-nowrap"><a :href="base + '/billing/approval?id=' + r.id" x-text="r.reference"></a></td>
                                <td class="bc-nowrap" x-text="fmtDate(r.period_start) + ' – ' + fmtDate(r.period_end)"></td>
                                <td><span class="bc-pill" :class="runTone(r.status)" x-text="r.status"></span></td>
                                <td class="text-right bc-mono" x-text="r.invoice_count"></td>
                                <?php if ($showMoney): ?><td class="text-right bc-mono bc-nowrap" x-text="runTotal(r)"></td><?php endif; ?>
                                <td class="text-sm bc-nowrap" x-text="(r.submitted_by_name || '—') + ' · ' + FF_formatUtc(r.submitted_at, { hour: undefined, minute: undefined })"></td>
                                <td class="text-sm bc-nowrap" x-text="r.decided_at ? (r.decided_by_name || '—') + ' · ' + FF_formatUtc(r.decided_at, { hour: undefined, minute: undefined }) : '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
    </div>

    <!-- ── HOLDS ───────────────────────────────────────────────── -->
    <div x-show="tab === 'holds'">
        <div class="card">
            <div class="card-body" style="padding-bottom:0;">
                <p class="bc-muted text-sm" style="margin:0 0 10px;">
                    A hold stops a lease (or every lease of a customer) being billed by the workbench and the monthly job until it is released.
                    Billing is deferred, not forgiven — released months bill as normal. A lease's own Generate Invoice is not blocked.
                </p>
            </div>
            <template x-if="holds.loading"><div><template x-for="n in 3" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!holds.loading && !holds.rows.length">
                <div class="bc-empty">No holds.</div>
            </template>
            <template x-if="!holds.loading && holds.rows.length">
                <div style="overflow-x:auto;">
                <table class="table" aria-label="Billing holds">
                    <thead><tr><th>On hold</th><th>Reason</th><th>From</th><th>Until</th><th>Placed by</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="h in holds.rows" :key="h.id">
                            <tr>
                                <td>
                                    <template x-if="h.scope === 'lease'">
                                        <div><a :href="base + '/leases/show?id=' + h.lease_id" x-text="h.contract_number"></a>
                                            <div class="bc-muted text-sm" x-text="h.company_name + (h.unit_number ? ' · Unit ' + h.unit_number : '')"></div></div>
                                    </template>
                                    <template x-if="h.scope === 'customer'">
                                        <div><a :href="base + '/customers/show?id=' + h.customer_id" x-text="h.company_name"></a>
                                            <div class="bc-muted text-sm" x-text="'Whole customer · ' + h.customer_active_leases + ' active lease(s)'"></div></div>
                                    </template>
                                </td>
                                <td style="max-width:380px;" x-text="h.reason"></td>
                                <td class="bc-nowrap" x-text="fmtDate(h.starts_on)"></td>
                                <td class="bc-nowrap" x-text="h.ends_on ? fmtDate(h.ends_on) : 'Until released'"></td>
                                <td class="text-sm bc-nowrap" x-text="(h.created_by_name || '—') + ' · ' + FF_formatUtc(h.created_at, { hour: undefined, minute: undefined })"></td>
                                <td>
                                    <span class="bc-pill" :class="h.active ? 'bc-tone-warning' : 'bc-tone-muted'" x-text="h.active ? 'Active' : (h.released_at ? 'Released' : 'Ended')"></span>
                                    <div class="bc-muted text-sm" x-show="h.released_at" x-text="(h.released_by_name || '') + (h.release_note ? ': ' + h.release_note : '')"></div>
                                </td>
                                <td class="text-right">
                                    <?php if ($canEdit): ?>
                                    <button class="btn btn-secondary btn-xs" x-show="h.active" @click="releaseHold(h)">Release</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
    </div>

    <!-- ── Open-a-month modal ──────────────────────────────────── -->
    <?php if ($canCreate): ?>
    <div x-show="monthModal.open" x-cloak class="modal-overlay" style="z-index:var(--z-modal);" @bc-open-month.window="monthModal.open = true; monthModal.month = kpis ? kpis.target_month : ''">
        <div class="modal-backdrop" @click="monthModal.open = false"></div>
        <div class="modal" @click.stop style="max-width:420px;">
            <div class="modal-header">
                <h3 class="modal-title">Open a billing cycle</h3>
                <button type="button" class="modal-close-btn" aria-label="Close" @click="monthModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="bc_month">Month to bill</label>
                <input id="bc_month" type="month" class="form-control" x-model="monthModal.month" :max="maxMonth">
                <p class="form-hint">Opening a month that already has a cycle just takes you to it. Past months can be opened to work a draft backlog.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" @click="monthModal.open = false">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="!monthModal.month || opening" @click="openMonth(monthModal.month)">
                    <span x-text="opening ? 'Opening…' : 'Open cycle'"></span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── New hold modal ──────────────────────────────────────── -->
    <?php if ($canEdit): ?>
    <div x-show="holdModal.open" x-cloak class="modal-overlay" style="z-index:var(--z-modal);">
        <div class="modal-backdrop" @click="holdModal.open = false"></div>
        <div class="modal" @click.stop style="max-width:540px;">
            <div class="modal-header">
                <h3 class="modal-title">New billing hold</h3>
                <button type="button" class="modal-close-btn" aria-label="Close" @click="holdModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Hold</label>
                    <div style="display:flex; gap:16px;">
                        <label style="display:flex; gap:6px; align-items:center;"><input type="radio" value="lease" x-model="holdModal.scope"> One lease</label>
                        <label style="display:flex; gap:6px; align-items:center;"><input type="radio" value="customer" x-model="holdModal.scope"> Every lease of a customer</label>
                    </div>
                </div>
                <div class="form-group" x-show="holdModal.scope === 'lease'">
                    <label class="form-label">Lease <span class="required">*</span></label>
                    <div x-show="holdModal.preset_label" class="form-control" style="background:var(--bg-muted);" x-text="holdModal.preset_label"></div>
                    <template x-if="holdModal.open && holdModal.scope === 'lease' && !holdModal.preset_label">
                        <div>
                            <?php
                            $pickerName      = 'bc_hold_lease_picker';
                            $pickerConfig    = [
                                'endpoint'    => '/api/v1/leases/index.php',
                                'searchParam' => 'search',
                                'resultKey'   => 'items',
                                'perPage'     => 10,
                                'extraParams' => 'status=active',
                                'placeholder' => 'Search leases by contract #, customer or unit…',
                                'mapResult'   => "r => ({ id: r.id, label: r.contract_number + ' — ' + (r.customer_display_name || ''), sublabel: 'Unit ' + (r.unit_display_number || '—') + ' · ' + r.status, raw: r })",
                            ];
                            $pickerOnPicked  = 'holdModal.lease_id = $event.detail.id';
                            $pickerOnCleared = 'holdModal.lease_id = null';
                            $pickerError     = 'holdModal.errors.lease_id';
                            require FF_ROOT . '/includes/partials/record-picker.php';
                            ?>
                        </div>
                    </template>
                    <div class="field-error" x-show="holdModal.errors.lease_id" x-text="holdModal.errors.lease_id"></div>
                </div>
                <div class="form-group" x-show="holdModal.scope === 'customer'">
                    <label class="form-label">Customer <span class="required">*</span></label>
                    <template x-if="holdModal.open && holdModal.scope === 'customer'">
                        <div>
                            <?php
                            $pickerName      = 'bc_hold_customer_picker';
                            $pickerConfig    = [
                                'endpoint'    => '/api/v1/customers/index.php',
                                'searchParam' => 'search',
                                'resultKey'   => 'items',
                                'perPage'     => 10,
                                'placeholder' => 'Search customers…',
                                'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: [r.city, r.province].filter(Boolean).join(', '), raw: r })",
                            ];
                            $pickerOnPicked  = 'holdModal.customer_id = $event.detail.id';
                            $pickerOnCleared = 'holdModal.customer_id = null';
                            $pickerError     = 'holdModal.errors.customer_id';
                            require FF_ROOT . '/includes/partials/record-picker.php';
                            ?>
                        </div>
                    </template>
                    <div class="field-error" x-show="holdModal.errors.customer_id" x-text="holdModal.errors.customer_id"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="bc_hold_reason">Reason <span class="required">*</span></label>
                    <textarea id="bc_hold_reason" class="form-control" rows="2" maxlength="500" x-model="holdModal.reason"
                              placeholder="e.g. Rate dispute — do not bill until settled"></textarea>
                    <div class="field-error" x-show="holdModal.errors.reason" x-text="holdModal.errors.reason"></div>
                </div>
                <div class="bc-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="bc_hold_from">From</label>
                        <input id="bc_hold_from" type="date" class="form-control" x-model="holdModal.starts_on">
                        <div class="field-error" x-show="holdModal.errors.starts_on" x-text="holdModal.errors.starts_on"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bc_hold_to">Until <span class="bc-muted">(optional)</span></label>
                        <input id="bc_hold_to" type="date" class="form-control" x-model="holdModal.ends_on">
                        <div class="field-error" x-show="holdModal.errors.ends_on" x-text="holdModal.errors.ends_on"></div>
                    </div>
                </div>
                <p class="form-hint" style="margin:0;">Any month that overlaps these dates is held. Leave "Until" blank to hold until someone releases it.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" @click="holdModal.open = false">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="holdModal.saving" @click="saveHold()">
                    <span x-text="holdModal.saving ? 'Saving…' : 'Place hold'"></span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function FF_BillingHome() {
    const base = <?= json_encode(rtrim(base_url(''), '/')) ?>;
    const api  = base + '/api/v1';
    return {
        base,
        today: FF_localDate(),
        // Latest month a cycle can be opened for: next month (company-local).
        maxMonth: (() => { const [y, m] = FF_localDate().split('-').map(Number); return new Date(Date.UTC(y, m, 1)).toISOString().slice(0, 7); })(),
        tab: 'cycles',
        kpis: null,
        opening: false,
        cycles: { loading: true, rows: [], backlog: [], showAllBacklog: false },
        exc: { loading: false, loaded: false, status: 'open', rows: [] },
        runs: { loading: false, loaded: false, status: '', rows: [] },
        holds: { loading: false, loaded: false, state: 'active', rows: [] },
        monthModal: { open: false, month: '' },
        holdModal: { open: false, saving: false, scope: 'lease', lease_id: null, customer_id: null, preset_label: '', reason: '', starts_on: '', ends_on: '', errors: {} },

        get tabs() {
            return [
                { key: 'cycles',     label: 'Cycles',     count: () => 0 },
                { key: 'exceptions', label: 'Exceptions', count: () => this.kpis ? this.kpis.open_exceptions : 0 },
                { key: 'approvals',  label: 'Approvals',  count: () => this.kpis ? this.kpis.pending_runs : 0 },
                { key: 'holds',      label: 'Holds',      count: () => this.kpis ? this.kpis.active_holds : 0 },
            ];
        },

        init() {
            if (this._inited) return; this._inited = true;
            const h = (location.hash || '').replace('#', '');
            if (['cycles', 'exceptions', 'approvals', 'holds'].includes(h)) this.tab = h;
            this.loadKpis();
            this.loadCycles();
            this.loadTab();
            window.addEventListener('hashchange', () => {
                const t = (location.hash || '').replace('#', '');
                if (t && t !== this.tab && ['cycles', 'exceptions', 'approvals', 'holds'].includes(t)) { this.tab = t; this.loadTab(); }
            });
        },

        setTab(t) {
            this.tab = t;
            history.replaceState(null, '', '#' + t);
            this.loadTab();
        },
        loadTab() {
            if (this.tab === 'exceptions' && !this.exc.loaded) this.loadExceptions();
            if (this.tab === 'approvals' && !this.runs.loaded) this.loadRuns();
            if (this.tab === 'holds' && !this.holds.loaded) this.loadHolds();
        },

        // ── formatting ──
        money(v) {
            if (v === null || v === undefined || v === '') return '—';
            return new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(Number(v));
        },
        fmtDate(d) { return d ? FF_formatUtc(d, { hour: undefined, minute: undefined }) : '—'; },
        cycleUrl(id) { return base + '/billing/cycle?id=' + id; },
        runTone(s) { return { pending: 'bc-tone-warning', approved: 'bc-tone-info', generated: 'bc-tone-success', rejected: 'bc-tone-danger', cancelled: 'bc-tone-muted' }[s] || ''; },
        runTotal(r) {
            if (!r.total_by_currency) return '—';
            const parts = Object.entries(r.total_by_currency).map(([cur, amt]) =>
                new Intl.NumberFormat('en-CA', { style: 'currency', currency: cur }).format(Number(amt)) + ' ' + cur);
            return parts.length ? parts.join(' + ') : '—';
        },

        // ── loads ──
        async loadKpis() {
            const r = await FF_Api.get(api + '/billing/kpis');
            if (r.success) this.kpis = r.data;
        },
        async loadCycles() {
            this.cycles.loading = true;
            const r = await FF_Api.get(api + '/billing/cycles/index');
            if (r.success) { this.cycles.rows = r.data.cycles; this.cycles.backlog = r.data.backlog; }
            else FF_Toast.error(r.error?.message || 'Could not load cycles.');
            this.cycles.loading = false;
        },
        async loadExceptions() {
            this.exc.loading = true;
            const r = await FF_Api.get(api + '/invoices/billing_exceptions/index?limit=200&status=' + this.exc.status);
            if (r.success) { this.exc.rows = r.data.exceptions; this.exc.loaded = true; }
            else FF_Toast.error(r.error?.message || 'Could not load exceptions.');
            this.exc.loading = false;
        },
        async loadRuns() {
            this.runs.loading = true;
            const r = await FF_Api.get(api + '/invoices/batch_runs/index?limit=100' + (this.runs.status ? '&status=' + this.runs.status : ''));
            if (r.success) { this.runs.rows = r.data.runs; this.runs.loaded = true; }
            else FF_Toast.error(r.error?.message || 'Could not load runs.');
            this.runs.loading = false;
        },
        async loadHolds() {
            this.holds.loading = true;
            const r = await FF_Api.get(api + '/billing/holds/index?state=' + this.holds.state);
            if (r.success) { this.holds.rows = r.data.holds; this.holds.loaded = true; }
            else FF_Toast.error(r.error?.message || 'Could not load holds.');
            this.holds.loading = false;
        },

        // ── actions ──
        async openMonth(month) {
            if (!month || this.opening) return;
            this.opening = true;
            const r = await FF_Api.post(api + '/billing/cycles/open', { month });
            this.opening = false;
            if (r.success) { window.location.href = r.data.url; }
            else FF_Toast.error(r.error?.message || 'Could not open the cycle.');
        },
        async resolveException(x, action) {
            let note = '';
            if (action === 'ignore') {
                note = await FF_Confirm.askText({ title: 'Ignore this exception', message: 'Say why this lease does not need billing for the period.', confirmLabel: 'Ignore', placeholder: 'e.g. Billed by hand on INV-…' });
                if (!note) return;
            }
            const r = await FF_Api.post(api + '/invoices/billing_exceptions/resolve', { id: x.id, action, note });
            if (r.success) {
                FF_Toast.success(action === 'reopen' ? 'Reopened.' : (action === 'ignore' ? 'Ignored.' : 'Marked fixed.'));
                this.loadExceptions(); this.loadKpis();
            } else FF_Toast.error(r.error?.message || 'Could not update the exception.');
        },
        openHoldModal(preset) {
            this.holdModal = {
                open: true, saving: false, errors: {},
                scope: preset?.scope || 'lease',
                lease_id: preset?.lease_id || null,
                customer_id: preset?.customer_id || null,
                preset_label: preset?.label || '',
                reason: preset?.reason ? 'Held from billing exception: ' + preset.reason.slice(0, 400) : '',
                starts_on: FF_localDate(), ends_on: '',
            };
        },
        async saveHold() {
            const m = this.holdModal;
            m.saving = true; m.errors = {};
            const r = await FF_Api.post(api + '/billing/holds/create', {
                scope: m.scope, lease_id: m.scope === 'lease' ? m.lease_id : null,
                customer_id: m.scope === 'customer' ? m.customer_id : null,
                reason: m.reason, starts_on: m.starts_on, ends_on: m.ends_on,
            });
            m.saving = false;
            if (r.success) {
                m.open = false;
                FF_Toast.success('Billing hold placed.');
                this.holds.loaded = false; this.loadKpis();
                if (this.tab === 'holds') this.loadHolds(); else this.setTab('holds');
            } else {
                m.errors = r.error?.fields || {};
                if (!r.error?.fields) FF_Toast.error(r.error?.message || 'Could not place the hold.');
            }
        },
        async releaseHold(h) {
            const note = await FF_Confirm.askText({ title: 'Release this hold', message: 'Billing resumes from the next run. Add a note (optional).', confirmLabel: 'Release', placeholder: 'e.g. Dispute settled' });
            if (note === null) return;
            const r = await FF_Api.post(api + '/billing/holds/release', { id: h.id, note });
            if (r.success) { FF_Toast.success('Hold released.'); this.loadHolds(); this.loadKpis(); }
            else FF_Toast.error(r.error?.message || 'Could not release the hold.');
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
