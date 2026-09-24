<?php
declare(strict_types=1);

/**
 * app/admin/billing/cycle.php
 *
 * S-BILLING-MODULE — one month's billing cycle, from preparation to close.
 *
 * Header: the cycle record (month, status, owner, targets) + the seven-step
 * stepper (Prepare → Readings → Generate → Review → Approve → Send → Close);
 * each step opens the tab where that step is done.
 *
 * Tabs:
 *   Overview   key numbers, lease coverage bar, targets/owner/notes, and the
 *              "what to do next" callout for the current step
 *   Readiness  BillingReadiness checks: blockers / warnings / info, each
 *              with why it matters, how to fix it and the items (linked to
 *              the page that fixes them); warnings can be acknowledged
 *   Readings   period-end odometer / engine-hours sheet for manual leases —
 *              generation picks the readings up (CycleReadings)
 *   Leases     every lease on rent this month and what happened to it
 *              (billed, held, exception, closed-unbilled, to bill…)
 *   Review     every invoice vs last month with its flags (double billing,
 *              double mileage, swings, $0, no tax…) + Reviewed / Query marks,
 *              and leases billed last month but missing now
 *   Delivery   did each invoice reach its customer (email log), with Send &
 *              Email for drafts and Email again for sent invoices
 *   Close      pre-close checks, close & lock with a frozen summary, the
 *              month's figures (categories, currencies, customers, vs last
 *              month), invoices added after close, reopen, billing register
 *   Activity   the cycle's audit trail
 *
 * Generation itself happens in the workbench (billing/run?cycle=ID) — this
 * page never re-implements it. Sending drafts reuses api/v1/invoices/bulk_send;
 * re-emailing sent invoices uses api/v1/billing/deliver (same delivery code).
 *
 * @depends api/v1/billing/cycles/*, api/v1/billing/readings/*,
 *          api/v1/billing/deliver, api/v1/invoices/bulk_send,
 *          includes/partials/activity-log.php, public/assets/css/billing.css
 * @session S-BILLING-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Billing\Cycle\BillingCycles;

require_auth();
require_permission('invoices', 'view');

$cycle = null;
if (($id = clean_int($_GET['id'] ?? null)) && $id > 0) {
    $cycle = BillingCycles::find($id);
} elseif (BillingCycles::isValidMonth((string) ($_GET['month'] ?? ''))) {
    $cycle = BillingCycles::findByMonth((string) $_GET['month']);
    if (!$cycle && can('invoices', 'create')) {
        // A link to a month (e.g. from a readiness item) opens its cycle.
        [$cycle] = BillingCycles::ensure((string) $_GET['month'], current_user_id());
    }
    if ($cycle) {
        header('Location: ' . base_url('billing/cycle') . '?id=' . $cycle['id'] . (isset($_GET['tab']) ? '#' . preg_replace('/[^a-z]/', '', (string) $_GET['tab']) : ''));
        exit;
    }
}
if (!$cycle) {
    http_response_code(404);
    $pageTitle = 'Billing cycle not found';
    require_once FF_ROOT . '/includes/header.php';
    echo '<div class="card"><div class="card-body"><p class="text-secondary">That billing cycle does not exist.</p>'
       . '<a class="btn btn-secondary btn-sm" href="' . e(base_url('billing')) . '">Back to Billing</a></div></div>';
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

$canCreate  = can('invoices', 'create');
$canEdit    = can('invoices', 'edit');
$canApprove = can('invoices', 'approve');
$canExport  = can('invoices', 'export');
$showMoney  = can_view_financials();
$isClosed   = $cycle['status'] === 'closed';

$pageTitle      = 'Billing — ' . $cycle['label'];
$helpModuleSlug = 'billing';
require_once FF_ROOT . '/includes/header.php';

$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('calendar-days') . e(format_date($cycle['period_start'])) . ' → ' . e(format_date($cycle['period_end'])),
    \FleetForge\Sop\SopIcons::svg('users') . 'Owner: ' . e($cycle['owner_name'] ?: 'everyone with invoice access'),
];
if ($cycle['send_by_date']) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . 'Send by ' . e(format_date($cycle['send_by_date']));
}
if ($isClosed) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('shield-check') . 'Closed ' . e(format_datetime($cycle['closed_at'], 'M j, Y')) . ' by ' . e($cycle['closed_by_name'] ?? '—');
}
$prevCycle = db_row("SELECT id, reference FROM billing_cycles WHERE period_start < ? ORDER BY period_start DESC LIMIT 1", [$cycle['period_start']]);
$nextCycle = db_row("SELECT id, reference FROM billing_cycles WHERE period_start > ? ORDER BY period_start ASC LIMIT 1", [$cycle['period_start']]);

/*
 * S-BILLING-MODULE-2 — the plain-language guide at the top of every tab:
 * what the step is, what to do, what the buttons do. Kept here (not in a
 * help page) so it is right where the work happens; each panel can be
 * collapsed and remembers that per person (localStorage, a convenience only).
 * `step` = the stepper step the tab belongs to (enables "Sign off this step").
 */
$guides = [
    'overview' => ['title' => 'The month at a glance', 'step' => null,
        'what' => 'A billing cycle is one month of billing: every lease on rent that month, every invoice it produces, and the seven steps from checking the month to closing it. Nothing here bills anyone by itself.',
        'do'   => ['Follow the steps left to right — the blue one is where you are.', 'Read "What to do next" below: it says exactly what is left.', 'Set an owner and target dates so everyone knows who runs the month and by when.'],
        'buttons' => ['Click a step to open its tab.', 'Open workbench = where the month\'s invoices are generated.', 'Sign off (on each step\'s tab) records who finished it.']],
    'readiness' => ['title' => 'Step 1 · Prepare — check the month before billing', 'step' => 'prepare',
        'what' => 'Automatic checks that look for anything that would put a wrong amount on an invoice, or stop it reaching the customer — missing rates, readings, emails, tax exemptions, earlier months never billed, US-dollar rate, and more.',
        'do'   => ['Fix every red Blocker — those leases cannot bill.', 'Fix each amber Warning, or Acknowledge it with a reason if it is fine.', 'Blue items are for information.', 'Re-run the checks after fixing things.'],
        'buttons' => ['Each name links to the page where you fix it.', 'Acknowledge — bill anyway: records who accepted the warning and why.', 'Nothing on this tab changes an invoice.']],
    'readings' => ['title' => 'Step 2 · Readings — month-end odometer and hours', 'step' => 'readings',
        'what' => 'Leases on Manual mileage (with a mileage rate) and leases with an hourly rate only bill their usage from a reading. Enter each one\'s odometer / hours at the end of the month here — before generating.',
        'do'   => ['Type the reading in the lease\'s own unit (km or miles), or click a suggestion (Samsara or the latest Mileage Log).', 'Press Save readings. A reading lower than the previous one is refused.'],
        'buttons' => ['Save readings stores them; generating the month uses them automatically.', 'Saved readings are also added to the unit\'s Mileage Logs.', 'A reading entered after the invoice exists changes nothing — use Regenerate on the draft.']],
    'charges' => ['title' => 'Charges — extra lines for the next invoice', 'step' => null,
        'what' => 'Anything to add to a lease\'s invoice besides the rent: a one-off (damage, an extra wash, an admin fee) or a standing monthly fee (yard parking, a second tracker).',
        'do'   => ['Add charge → pick the lease, describe it, price it, choose once or every month.', 'Generate the month as usual — the charge is added to the lease\'s invoice by itself.'],
        'buttons' => ['Charges are picked up by every way of creating an invoice (workbench, a lease\'s Generate Invoice, close).', 'Voiding or regenerating a draft puts its charges back in the queue.', 'Cancel stops a charge billing again; credits go on a credit note, not here.']],
    'leases' => ['title' => 'Step 3 · Generate — every lease on rent this month', 'step' => 'generate',
        'what' => 'Each lease on rent in the month and what happened to it: billed, covered by another invoice, on hold, an exception, billed at close, closed without its last invoice, or still To bill.',
        'do'   => ['Open the workbench to bill the To bill leases (dry run first).', 'For Closed, unbilled leases use the lease\'s Generate Invoice.', 'Put a hold on anything that should wait.'],
        'buttons' => ['Open workbench — generates drafts (nothing is sent).', 'The coloured chips filter the list.', 'Drafts count for nothing until they are sent.']],
    'customers' => ['title' => 'Customers — the month by customer', 'step' => null,
        'what' => 'Each customer with a lease on rent this month: how many leases, what is billed or still to bill, their invoices, where the invoices go, and whether they have been emailed.',
        'do'   => ['When a customer\'s invoices are ready, press Send month: their drafts are sent and ALL their invoices for the month go out in one email, one PDF per invoice, with Pay now links.'],
        'buttons' => ['Send month = send the drafts + one combined email.', 'Email again (when already sent) resends the combined email.', 'Customers billed by mail are marked on the Delivery tab.']],
    'review' => ['title' => 'Step 4 · Review — check every invoice before it goes out', 'step' => 'review',
        'what' => 'Every invoice of the month compared with the same lease last month and checked for mistakes that have happened before: double billing, double mileage, big changes, $0, no tax, no recipient.',
        'do'   => ['Clear the red flags first, then look at the amber ones.', 'Preview an invoice beside the list; fix the lease and Regenerate, or fix the draft\'s lines.', 'Mark each draft Reviewed. Query anything that needs a second look.'],
        'buttons' => ['Fix = removes a duplicate mileage overage line from a draft.', 'Regenerate = rebuild selected drafts from their lease (same numbers kept).', 'Void = cancel invoices. A queried invoice stops the month closing.']],
    'delivery' => ['title' => 'Step 6 · Send — make sure every invoice reaches its customer', 'step' => 'send',
        'what' => 'How each invoice reached (or did not reach) its customer: still a draft, emailed, sent but never emailed, email failed or bounced, printed and mailed, or on the portal.',
        'do'   => ['Tick drafts → Send & email (in stages if there are many).', 'Tick "Sent, never emailed" or failed ones → Email again.', 'For customers billed by mail: Download PDFs, print, post, then Mark as mailed.'],
        'buttons' => ['Send & email = the invoice becomes real (balance, ledger, QuickBooks) and is emailed.', 'Email again only emails — nothing else changes.', 'Mark as mailed / on portal records how it was delivered.']],
    'close' => ['title' => 'Step 7 · Close — sign off the month', 'step' => null,
        'what' => 'Closing says the month\'s billing is done: it freezes the figures and stops the workbench billing the month again. A late lease close can still add an invoice; it shows as a late addition.',
        'do'   => ['Clear everything marked Must fix.', 'If anything marked Check is fine, tick Close anyway and explain in the note.', 'Close, then download the billing register for the accountant.'],
        'buttons' => ['Close and lock — needs invoice edit rights.', 'Reopen — needs invoice approval rights and a reason.', 'The figures show the month frozen at close, or live.']],
];

/** Render a tab's guide panel. */
$renderGuide = static function (string $tab) use ($guides, $canEdit, $isClosed): void {
    $g = $guides[$tab] ?? null;
    if (!$g) return;
    ?>
    <div class="bc-guide">
        <div class="bc-guide-head" @click="toggleGuide('<?= e($tab) ?>')">
            <span class="bc-guide-ic">?</span>
            <h3><?= e($g['title']) ?></h3>
            <span class="text-sm bc-muted" x-text="guideOpen('<?= e($tab) ?>') ? 'Hide help' : 'How this works'"></span>
        </div>
        <div class="bc-guide-body" x-show="guideOpen('<?= e($tab) ?>')">
            <div><h4>What it is</h4><p><?= e($g['what']) ?></p></div>
            <div><h4>What you do</h4><ul><?php foreach ($g['do'] as $li): ?><li><?= e($li) ?></li><?php endforeach; ?></ul></div>
            <div><h4>What the buttons do</h4><ul><?php foreach ($g['buttons'] as $li): ?><li><?= e($li) ?></li><?php endforeach; ?></ul></div>
        </div>
        <?php if ($g['step'] && $canEdit && !$isClosed): ?>
        <div class="bc-guide-foot" x-show="guideOpen('<?= e($tab) ?>') || signoffFor('<?= e($g['step']) ?>')">
            <template x-if="signoffFor('<?= e($g['step']) ?>')">
                <span>✓ Signed off by <strong x-text="signoffFor('<?= e($g['step']) ?>').by"></strong>
                    <span x-text="FF_formatUtc(signoffFor('<?= e($g['step']) ?>').at)"></span><span x-show="signoffFor('<?= e($g['step']) ?>').note" x-text="' — ' + (signoffFor('<?= e($g['step']) ?>').note || '')"></span>
                    <button type="button" class="btn btn-ghost btn-xs" @click="signoff('<?= e($g['step']) ?>', false)">Withdraw</button></span>
            </template>
            <template x-if="!signoffFor('<?= e($g['step']) ?>')">
                <button type="button" class="btn btn-secondary btn-xs" @click="signoff('<?= e($g['step']) ?>', true)">✓ Sign off this step</button>
            </template>
            <span>Signing off records that you finished this step; the stepper still shows whether the work is really done.</span>
        </div>
        <?php endif; ?>
    </div>
    <?php
};
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/billing.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

<?php ob_start(); ?>
    <a href="<?= base_url('billing') ?>">All cycles</a>
    <?php if ($prevCycle): ?><a href="<?= base_url('billing/cycle') ?>?id=<?= (int) $prevCycle['id'] ?>">← <?= e($prevCycle['reference']) ?></a><?php endif; ?>
    <?php if ($nextCycle): ?><a href="<?= base_url('billing/cycle') ?>?id=<?= (int) $nextCycle['id'] ?>"><?= e($nextCycle['reference']) ?> →</a><?php endif; ?>
    <a href="<?= base_url('invoices') ?>">All invoices</a>
    <a href="<?= base_url('billing/settings') ?>">Billing settings</a>
    <?php if ($canExport && $showMoney): ?>
    <a href="<?= base_url('api/v1/billing/cycles/export') ?>?id=<?= (int) $cycle['id'] ?>">Download billing register (CSV)</a>
    <?php endif; ?>
    <?php if ($isClosed && $canApprove): ?>
    <button type="button" class="btn btn-danger btn-sm" @click="reopen()">Reopen this cycle</button>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <?= help_button('billing') ?>
    <?php if (!$isClosed && $canCreate): ?>
    <a class="btn btn-primary btn-sm" href="<?= base_url('billing/run') ?>?cycle=<?= (int) $cycle['id'] ?>">
        <?= heroicon('document-duplicate', 'icon-sm') ?> Open workbench
    </a>
    <?php endif; ?>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>

<div id="billing-cycle" x-data="FF_BillingCycle(<?= e(json_encode([
    'id'        => $cycle['id'],
    'reference' => $cycle['reference'],
    'month'     => $cycle['month'],
    'label'     => $cycle['label'],
    'status'    => $cycle['status'],
    'canCreate' => $canCreate,
    'canEdit'   => $canEdit,
    'canApprove'=> $canApprove,
    'showMoney' => $showMoney,
])) ?>)" x-cloak>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => $isClosed ? 'success' : 'primary',
    'icon'       => 'calendar-days',
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Billing', base_url('billing')], [$cycle['reference'], null]],
    'eyebrow'    => 'Billing cycle · ' . $cycle['reference'],
    'title_html' => e($cycle['label']) . ' <span class="bc-pill ' . ($isClosed ? 'bc-tone-success' : 'bc-tone-primary') . '" style="vertical-align:middle;font-size:12px;">' . ($isClosed ? 'Closed' : 'Open') . '</span>',
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

    <?php if ($isClosed): ?>
    <div class="bc-closed-banner">
        <span>✓</span>
        <div><strong>This month's billing is closed.</strong> The workbench cannot bill <?= e($cycle['label']) ?> until the cycle is reopened.
            A lease's own Generate Invoice or a lease close can still add an invoice — those show as late additions on the Close tab.</div>
    </div>
    <?php elseif ($cycle['reopened_at']): ?>
    <div class="bc-closed-banner is-reopened">
        <span>↺</span>
        <div><strong>Reopened</strong> <?= e(format_datetime($cycle['reopened_at'], 'M j, Y')) ?> by <?= e($cycle['reopened_by_name'] ?? '—') ?>: <?= e((string) $cycle['reopen_reason']) ?></div>
    </div>
    <?php endif; ?>

    <!-- ── Stepper ─────────────────────────────────────────────── -->
    <div class="bc-stepper" x-show="ov">
        <template x-for="(s, i) in (ov ? ov.stage.steps : [])" :key="s.key">
            <button type="button" class="bc-step" :data-state="s.state"
                    :class="{ 'is-attention': s.attention, 'is-active': tab === stepTab(s.key) }"
                    @click="goStep(s.key)">
                <div class="bc-step-top">
                    <span class="bc-step-dot" x-text="s.state === 'done' ? '✓' : (i + 1)"></span>
                    <span class="bc-step-label" x-text="s.label"></span>
                </div>
                <div class="bc-step-hint" x-text="s.hint"></div>
                <div class="bc-step-sign" x-show="s.signoff" x-text="s.signoff ? '✓ ' + s.signoff.by : ''" :title="s.signoff ? 'Signed off ' + FF_formatUtc(s.signoff.at) : ''"></div>
            </button>
        </template>
    </div>

    <!-- ── Tabs ────────────────────────────────────────────────── -->
    <div class="tab-bar tab-bar--sticky" role="tablist" aria-label="Cycle sections">
        <template x-for="t in tabList" :key="t.key">
            <button class="tab-btn" role="tab" :class="{ 'is-active': tab === t.key }" :aria-selected="tab === t.key" @click="setTab(t.key)">
                <span x-text="t.label"></span>
                <span class="badge badge-sm" :class="t.tone || 'badge-neutral'" x-show="t.badge" x-text="t.badge" style="margin-left:6px;"></span>
            </button>
        </template>
    </div>

    <!-- ===========================================================
         OVERVIEW
         =========================================================== -->
    <section x-show="tab === 'overview'">
        <?php $renderGuide('overview'); ?>
        <template x-if="!ov"><div><template x-for="n in 4" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
        <template x-if="ov">
        <div>
            <div class="stat-grid stat-grid--4 ff-stats">
                <div class="stat-card stat-card--blue">
                    <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
                    <div class="stat-label">Invoices this month</div>
                    <div class="stat-value font-mono" x-text="ov.stats.live"></div>
                    <div class="stat-delta" x-text="ov.stats.drafts + ' draft · ' + ov.stats.issued + ' sent' + (ov.stats.void ? ' · ' + ov.stats.void + ' void' : '')"></div>
                </div>
                <div class="stat-card stat-card--green">
                    <span class="stat-icon stat-icon--green"><svg><use href="#icon-currency-dollar"/></svg></span>
                    <div class="stat-label">Billed (CAD)</div>
                    <div class="stat-value font-mono" x-text="ov.stats.money ? money(ov.stats.money.total_cad) : '—'"></div>
                    <div class="stat-delta" x-text="ov.stats.money ? money(ov.stats.money.draft_cad) + ' still in draft' : 'amounts hidden for your role'"></div>
                </div>
                <div class="stat-card stat-card--purple">
                    <span class="stat-icon stat-icon--purple"><svg><use href="#icon-check-circle"/></svg></span>
                    <div class="stat-label">Leases accounted for</div>
                    <div class="stat-value font-mono" x-text="accounted() + ' / ' + totalLeases()"></div>
                    <div class="stat-delta" x-text="toBillCount() ? toBillCount() + ' still to bill' : 'none left to bill'"></div>
                </div>
                <div class="stat-card stat-card--amber">
                    <span class="stat-icon stat-icon--amber"><svg><use href="#icon-arrow-up-tray"/></svg></span>
                    <div class="stat-label">Emailed</div>
                    <div class="stat-value font-mono" x-text="ov.stats.emailed + ' / ' + ov.stats.issued"></div>
                    <div class="stat-delta" x-text="ov.stats.reviewed + ' reviewed' + (ov.stats.queried ? ' · ' + ov.stats.queried + ' queried' : '')"></div>
                </div>
            </div>

            <div class="bc-grid-2" style="margin-top:14px;">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">What to do next</h3></div>
                    <div class="card-body">
                        <p style="margin:0 0 10px; font-size:14px;" x-text="nextStep().text"></p>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <template x-for="a in nextStep().actions" :key="a.label">
                                <button type="button" class="btn btn-sm" :class="a.primary ? 'btn-primary' : 'btn-secondary'" @click="a.go()" x-text="a.label"></button>
                            </template>
                        </div>
                        <div x-show="ov.overdue.bill_by || ov.overdue.send_by" class="alert alert-warning" style="margin:12px 0 0;">
                            <span x-show="ov.overdue.send_by">The send-by date has passed with drafts still unsent.</span>
                            <span x-show="!ov.overdue.send_by && ov.overdue.bill_by">The review-by date has passed with leases still to bill or review.</span>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Leases this month</h3></div>
                    <div class="card-body">
                        <div class="bc-coverage-bar">
                            <template x-for="s in coverageStates" :key="s.key">
                                <span :class="s.tone + ' bc-bg-tone'" x-show="(ov.coverage[s.key] || 0) > 0"
                                      :style="'width:' + ((ov.coverage[s.key] || 0) / Math.max(1, totalLeases()) * 100) + '%'" :title="s.label"></span>
                            </template>
                        </div>
                        <div class="bc-legend">
                            <template x-for="s in coverageStates" :key="s.key">
                                <button type="button" x-show="(ov.coverage[s.key] || 0) > 0" @click="leases.filter = s.key; setTab('leases')">
                                    <span class="bc-swatch bc-bg-tone" :class="s.tone"></span>
                                    <span x-text="s.label + ' ' + (ov.coverage[s.key] || 0)"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top:14px;">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 class="card-title">Owner, targets and notes</h3>
                    <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-secondary btn-sm" x-show="!edit.open" @click="startEdit()">Edit</button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <template x-if="!edit.open">
                        <dl class="rec-kv">
                            <div><dt>Owner</dt><dd x-text="cycle.owner_name || 'Everyone with invoice access'"></dd></div>
                            <div><dt>Drafts reviewed by</dt><dd x-text="cycle.bill_by_date ? fmtDate(cycle.bill_by_date) : '—'"></dd></div>
                            <div><dt>Everything sent by</dt><dd x-text="cycle.send_by_date ? fmtDate(cycle.send_by_date) : '—'"></dd></div>
                            <div><dt>Opened</dt><dd x-text="FF_formatUtc(cycle.created_at) + (cycle.opened_by_name ? ' by ' + cycle.opened_by_name : ' by the scheduled job')"></dd></div>
                            <div><dt>Readiness last run</dt><dd x-text="cycle.readiness_checked_at ? FF_formatUtc(cycle.readiness_checked_at) : 'Never'"></dd></div>
                            <div style="grid-column:1/-1;"><dt>Notes</dt><dd style="white-space:pre-wrap;" x-text="cycle.notes || '—'"></dd></div>
                        </dl>
                    </template>
                    <template x-if="edit.open">
                        <div>
                            <div class="bc-grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="bc_owner">Owner</label>
                                    <select id="bc_owner" class="form-select" x-model="edit.owner_user_id" :disabled="cycle.status !== 'open'">
                                        <option value="">Everyone with invoice access</option>
                                        <template x-for="u in users" :key="u.id"><option :value="String(u.id)" x-text="u.name"></option></template>
                                    </select>
                                </div>
                                <div></div>
                                <div class="form-group">
                                    <label class="form-label" for="bc_billby">Drafts reviewed by</label>
                                    <input id="bc_billby" type="date" class="form-control" x-model="edit.bill_by_date" :disabled="cycle.status !== 'open'">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="bc_sendby">Everything sent by</label>
                                    <input id="bc_sendby" type="date" class="form-control" x-model="edit.send_by_date" :disabled="cycle.status !== 'open'">
                                    <div class="field-error" x-show="edit.errors.send_by_date" x-text="edit.errors.send_by_date"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="bc_notes">Notes</label>
                                <textarea id="bc_notes" class="form-control" rows="3" maxlength="5000" x-model="edit.notes"
                                          placeholder="Anything the next person needs to know about this month's billing"></textarea>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <button type="button" class="btn btn-primary btn-sm" :disabled="edit.saving" @click="saveEdit()" x-text="edit.saving ? 'Saving…' : 'Save'"></button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="edit.open = false">Cancel</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        </template>
    </section>

    <!-- ===========================================================
         READINESS
         =========================================================== -->
    <section x-show="tab === 'readiness'">
        <?php $renderGuide('readiness'); ?>
        <div class="bc-toolbar">
            <div class="bc-toolbar-left">
                <template x-if="ready.summary">
                    <span style="display:inline-flex; gap:6px; flex-wrap:wrap;">
                        <span class="bc-pill bc-tone-danger" x-text="ready.summary.blocker + ' blocker' + (ready.summary.blocker === 1 ? '' : 's')"></span>
                        <span class="bc-pill bc-tone-warning" x-text="ready.summary.warning + ' warning' + (ready.summary.warning === 1 ? '' : 's')"></span>
                        <span class="bc-pill bc-tone-info" x-text="ready.summary.info + ' to note'"></span>
                        <span class="bc-pill bc-tone-muted" x-show="ready.summary.acknowledged" x-text="ready.summary.acknowledged + ' acknowledged'"></span>
                        <span class="bc-pill bc-tone-success" x-text="ready.summary.passed + ' passed'"></span>
                    </span>
                </template>
                <span class="bc-muted text-sm" x-show="ready.checked_at" x-text="'Checked ' + FF_formatUtc(ready.checked_at)"></span>
            </div>
            <div class="bc-toolbar-right">
                <label class="text-sm" style="display:flex; gap:6px; align-items:center;"><input type="checkbox" x-model="ready.showPassed"> Show passed checks</label>
                <button type="button" class="btn btn-secondary btn-sm" @click="loadReadiness()" :disabled="ready.loading" x-text="ready.loading ? 'Checking…' : 'Re-run checks'"></button>
            </div>
        </div>
        <template x-if="ready.loading && !ready.checks.length"><div><template x-for="n in 5" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
        <div class="bc-checks">
            <template x-for="c in visibleChecks()" :key="c.key">
                <div class="bc-check" :class="{ 'is-passed': c.count === 0, 'is-acked': !!c.acknowledged,
                        'bc-tone-danger': c.count && c.severity === 'blocker', 'bc-tone-warning': c.count && c.severity === 'warning' && !c.acknowledged,
                        'bc-tone-info': c.count && c.severity === 'info' }">
                    <div class="bc-check-head" @click="ready.open[c.key] = !ready.open[c.key]">
                        <span class="bc-check-count" x-text="c.count === 0 ? '✓' : c.count"></span>
                        <div style="flex:1; min-width:0;">
                            <div class="bc-check-title">
                                <span x-text="c.title"></span>
                                <span class="bc-pill bc-tone-danger" x-show="c.count && c.severity === 'blocker'" style="margin-left:6px;">Blocker</span>
                                <span class="bc-pill bc-tone-muted" x-show="c.acknowledged" style="margin-left:6px;" x-text="c.acknowledged ? 'Acknowledged by ' + c.acknowledged.by : ''"></span>
                            </div>
                            <div class="bc-check-why" x-text="c.why"></div>
                        </div>
                        <span class="bc-muted" x-show="c.count" x-text="ready.open[c.key] ? '▾' : '▸'"></span>
                    </div>
                    <div class="bc-check-body" x-show="ready.open[c.key] && c.count">
                        <div class="bc-check-fix" x-show="c.fix"><strong>Fix:</strong> <span x-text="c.fix"></span></div>
                        <ul class="bc-check-items">
                            <template x-for="(it, idx) in c.items" :key="c.key + idx">
                                <li>
                                    <a :href="itemHref(it)" @click="itemClick($event, it)" x-text="it.label"></a>
                                    <span class="bc-item-detail" x-text="it.detail"></span>
                                </li>
                            </template>
                        </ul>
                        <div class="bc-muted text-sm" x-show="c.count > c.items.length" x-text="'…and ' + (c.count - c.items.length) + ' more'"></div>
                        <?php if ($canEdit): ?>
                        <div class="bc-check-actions" x-show="c.severity === 'warning' && cycle.status === 'open'">
                            <button type="button" class="btn btn-ghost btn-xs" x-show="!c.acknowledged" @click="acknowledge(c, true)">Acknowledge — bill anyway</button>
                            <button type="button" class="btn btn-ghost btn-xs" x-show="c.acknowledged" @click="acknowledge(c, false)">Withdraw acknowledgement</button>
                            <span x-show="c.acknowledged && c.acknowledged.note" x-text="c.acknowledged ? '“' + (c.acknowledged.note || '') + '”' : ''"></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </template>
        </div>
    </section>

    <!-- ===========================================================
         READINGS
         =========================================================== -->
    <section x-show="tab === 'readings'">
        <?php $renderGuide('readings'); ?>
        <div class="card">
            <div class="card-body" style="padding-bottom:6px;">
                <p class="bc-muted text-sm" style="margin:0;">
                    Manual-mileage and hourly leases bill usage only from a reading. Enter each lease's odometer (in the lease's own unit) and/or
                    engine hours at the end of <strong x-text="cycle.label"></strong>. Generating the month in the workbench — and the dry run —
                    pass them to the invoice exactly as the single-invoice form does. A reading entered after the invoice exists changes nothing
                    (use Regenerate from Lease on the draft).
                </p>
            </div>
            <div class="bc-toolbar" style="padding:0 16px;">
                <div class="bc-toolbar-left bc-filterchips">
                    <button type="button" class="bc-chip" :class="{ 'is-on': rd.filter === 'needed' }" @click="rd.filter = 'needed'">Needs a reading <b x-text="rd.rows.filter(r => !r.billed && r.missing).length"></b></button>
                    <button type="button" class="bc-chip" :class="{ 'is-on': rd.filter === 'unbilled' }" @click="rd.filter = 'unbilled'">Not billed yet <b x-text="rd.rows.filter(r => !r.billed).length"></b></button>
                    <button type="button" class="bc-chip" :class="{ 'is-on': rd.filter === 'entered' }" @click="rd.filter = 'entered'">Entered <b x-text="rd.rows.filter(r => r.reading).length"></b></button>
                    <button type="button" class="bc-chip" :class="{ 'is-on': rd.filter === 'all' }" @click="rd.filter = 'all'">All <b x-text="rd.rows.length"></b></button>
                    <input type="search" class="form-control form-control-sm" placeholder="Search lease, customer, unit…" x-model="rd.search" style="min-width:200px;">
                </div>
                <div class="bc-toolbar-right">
                    <?php if ($canCreate): ?>
                    <span class="bc-muted text-sm" x-show="dirtyReadings().length" x-text="dirtyReadings().length + ' unsaved'"></span>
                    <button type="button" class="btn btn-primary btn-sm" :disabled="!dirtyReadings().length || rd.saving || cycle.status !== 'open'" @click="saveReadings()"
                            x-text="rd.saving ? 'Saving…' : 'Save readings'"></button>
                    <?php endif; ?>
                </div>
            </div>
            <template x-if="rd.loading"><div><template x-for="n in 5" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!rd.loading && !rdVisible().length">
                <div class="bc-empty" x-text="rd.rows.length ? 'Nothing matches this filter.' : 'No lease this month bills from a manual reading.'"></div>
            </template>
            <template x-if="!rd.loading && rdVisible().length">
                <div style="overflow-x:auto;" class="bc-readings">
                <table class="table" aria-label="Period-end readings">
                    <thead><tr><th>Lease</th><th>Previous reading</th><th>Odometer at month end</th><th>Engine hours at month end</th><th>Reading date</th><th>Note</th></tr></thead>
                    <tbody>
                        <template x-for="r in rdVisible()" :key="r.lease_id">
                            <tr :class="{ 'bc-row-error': rd.errors[r.lease_id] }">
                                <td>
                                    <a :href="base + '/leases/show?id=' + r.lease_id" x-text="r.contract_number"></a>
                                    <div class="bc-sub" x-text="r.company_name + (r.unit_number ? ' · Unit ' + r.unit_number : '')"></div>
                                    <div x-show="r.billed" class="bc-sub">Billed on <a :href="base + '/invoices/show?id=' + (r.billed ? r.billed.invoice_id : '')" x-text="r.billed ? r.billed.invoice_number : ''"></a>
                                        <span x-text="r.billed && r.billed.had_odometer ? '(with a reading)' : '(no reading)'"></span></div>
                                </td>
                                <td>
                                    <div class="bc-prev" x-show="r.needs_odometer" x-text="r.prev_odometer_in_unit !== null ? num(r.prev_odometer_in_unit) + ' ' + r.mileage_unit : 'none on file'"></div>
                                    <div class="bc-sub" x-show="r.needs_odometer && r.prev_odometer_from" x-text="'from ' + r.prev_odometer_from"></div>
                                    <div class="bc-prev" x-show="r.needs_hours" x-text="r.prev_hours !== null ? num(r.prev_hours) + ' h' : 'no hours on file'"></div>
                                </td>
                                <td>
                                    <template x-if="r.needs_odometer">
                                        <div>
                                            <div style="display:flex; gap:6px; align-items:center;">
                                                <input type="text" inputmode="decimal" class="form-control form-control-sm" :disabled="!!r.billed || cycle.status !== 'open' || !cfg.canCreate"
                                                       :class="{ 'is-changed': rd.edit[r.lease_id] && rd.edit[r.lease_id].odometer !== rd.orig[r.lease_id].odometer }"
                                                       x-model="rd.edit[r.lease_id].odometer" :placeholder="r.mileage_unit">
                                                <span class="bc-sub" x-text="r.mileage_unit"></span>
                                            </div>
                                            <button type="button" class="btn btn-ghost btn-xs" x-show="r.samsara_odometer_in_unit !== null && !r.billed && cycle.status === 'open'"
                                                    @click="rd.edit[r.lease_id].odometer = r.samsara_odometer_in_unit"
                                                    :title="'Samsara last synced ' + FF_formatUtc(r.samsara_synced_at)"
                                                    x-text="'Use Samsara ' + num(r.samsara_odometer_in_unit)"></button>
                                            <button type="button" class="btn btn-ghost btn-xs" x-show="r.log_odometer_in_unit !== null && !r.billed && cycle.status === 'open'"
                                                    @click="rd.edit[r.lease_id].odometer = r.log_odometer_in_unit; rd.edit[r.lease_id].reading_date = r.log_date || rd.edit[r.lease_id].reading_date"
                                                    :title="'Latest Mileage Log (' + (r.log_type || '') + ') on ' + fmtDate(r.log_date)"
                                                    x-text="'Use log ' + num(r.log_odometer_in_unit) + ' (' + fmtDate(r.log_date) + ')'"></button>
                                            <div class="bc-inline-error" x-show="rd.errors[r.lease_id] && rd.errors[r.lease_id].field === 'odometer'" x-text="rd.errors[r.lease_id] ? rd.errors[r.lease_id].message : ''"></div>
                                        </div>
                                    </template>
                                    <span class="bc-sub" x-show="!r.needs_odometer">not needed</span>
                                </td>
                                <td>
                                    <template x-if="r.needs_hours">
                                        <div>
                                            <input type="text" inputmode="decimal" class="form-control form-control-sm" :disabled="!!r.billed || cycle.status !== 'open' || !cfg.canCreate"
                                                   :class="{ 'is-changed': rd.edit[r.lease_id] && rd.edit[r.lease_id].engine_hours !== rd.orig[r.lease_id].engine_hours }"
                                                   x-model="rd.edit[r.lease_id].engine_hours" placeholder="hours">
                                            <div class="bc-inline-error" x-show="rd.errors[r.lease_id] && rd.errors[r.lease_id].field === 'engine_hours'" x-text="rd.errors[r.lease_id] ? rd.errors[r.lease_id].message : ''"></div>
                                        </div>
                                    </template>
                                    <span class="bc-sub" x-show="!r.needs_hours">not needed</span>
                                </td>
                                <td><input type="date" class="form-control form-control-sm" style="width:150px;" :disabled="!!r.billed || cycle.status !== 'open' || !cfg.canCreate" x-model="rd.edit[r.lease_id].reading_date"></td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" style="width:180px;font-family:inherit;" maxlength="500" :disabled="!!r.billed || cycle.status !== 'open' || !cfg.canCreate" x-model="rd.edit[r.lease_id].notes" placeholder="optional">
                                    <div class="bc-sub" x-show="r.reading && r.reading.entered_by_name" x-text="r.reading ? 'by ' + (r.reading.entered_by_name || '—') : ''"></div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
    </section>

    <!-- ===========================================================
         CHARGES (S-BILLING-MODULE-2)
         =========================================================== -->
    <section x-show="tab === 'charges'">
        <?php $renderGuide('charges'); ?>
        <div class="bc-toolbar">
            <div class="bc-toolbar-left bc-filterchips">
                <button type="button" class="bc-chip" :class="{ 'is-on': ch.state === 'pending' }" @click="ch.state = 'pending'; loadCharges()">Waiting to bill this month</button>
                <button type="button" class="bc-chip" :class="{ 'is-on': ch.state === 'billed' }" @click="ch.state = 'billed'; loadCharges()">Billed this month</button>
                <button type="button" class="bc-chip" :class="{ 'is-on': ch.state === 'all' }" @click="ch.state = 'all'; loadCharges()">All for this month</button>
                <button type="button" class="bc-chip" :class="{ 'is-on': ch.state === 'cancelled' }" @click="ch.state = 'cancelled'; loadCharges()">Cancelled</button>
            </div>
            <div class="bc-toolbar-right">
                <?php if ($canCreate && $showMoney): ?>
                <button type="button" class="btn btn-primary btn-sm" @click="openChargeModal()">+ Add charge</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <template x-if="ch.loading"><div><template x-for="n in 3" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!ch.loading && !ch.rows.length">
                <div class="bc-empty" x-text="ch.state === 'pending' ? 'No charges waiting for this month. Add one to put it on a lease\'s next invoice.' : 'None.'"></div>
            </template>
            <template x-if="!ch.loading && ch.rows.length">
                <div style="overflow-x:auto;">
                <table class="table" aria-label="Charges">
                    <thead><tr><th>Lease</th><th>Charge</th><th>How often</th><?php if ($showMoney): ?><th class="text-right">Amount</th><?php endif; ?><th>State</th><th>Added</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="c in ch.rows" :key="c.id">
                            <tr>
                                <td class="bc-nowrap"><a :href="base + '/leases/show?id=' + c.lease_id" x-text="c.contract_number"></a>
                                    <div class="bc-sub" x-text="c.company_name + (c.unit_number ? ' · ' + c.unit_number : '')"></div></td>
                                <td><div x-text="c.description"></div><div class="bc-sub" x-text="c.item_label + (c.taxable ? ' · taxable' : ' · not taxed') + (c.notes ? ' · ' + c.notes : '')"></div></td>
                                <td class="bc-nowrap text-sm" x-text="c.recurrence === 'monthly' ? 'Every month from ' + fmtDate(c.bill_from) + (c.bill_until ? ' to ' + fmtDate(c.bill_until) : '') : 'Once, from ' + fmtDate(c.bill_from)"></td>
                                <?php if ($showMoney): ?><td class="bc-amount" x-text="money(c.amount, c.currency) + (Number(c.quantity) !== 1 ? ' (' + Number(c.quantity) + ' × ' + money(c.unit_price, c.currency) + ')' : '')"></td><?php endif; ?>
                                <td>
                                    <span class="bc-pill" :class="{ 'bc-tone-warning': c.state === 'pending', 'bc-tone-success': c.state === 'billed', 'bc-tone-muted': c.state === 'cancelled' || c.state === 'ended', 'bc-tone-info': c.state === 'recurring' }"
                                          x-text="{ pending: 'Waiting', billed: 'Billed', cancelled: 'Cancelled', ended: 'Ended', recurring: 'Monthly' }[c.state] || c.state"></span>
                                    <template x-for="b in c.billed_on" :key="b.invoice_id">
                                        <div class="bc-sub"><a :href="base + '/invoices/show?id=' + b.invoice_id" x-text="b.invoice_number"></a> <span x-text="b.status"></span></div>
                                    </template>
                                    <div class="bc-sub" x-show="c.cancel_reason" x-text="c.cancel_reason"></div>
                                </td>
                                <td class="text-sm bc-nowrap" x-text="(c.created_by_name || '—') + ' · ' + fmtDate(String(c.created_at).slice(0, 10))"></td>
                                <td class="text-right">
                                    <?php if ($canEdit): ?>
                                    <button type="button" class="btn btn-ghost btn-xs" x-show="c.status === 'active'" @click="cancelCharge(c)">Cancel</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
    </section>

    <!-- ===========================================================
         LEASES (coverage)
         =========================================================== -->
    <section x-show="tab === 'leases'">
        <?php $renderGuide('leases'); ?>
        <div class="bc-toolbar">
            <div class="bc-toolbar-left bc-filterchips">
                <button type="button" class="bc-chip" :class="{ 'is-on': leases.filter === '' }" @click="leases.filter = ''">All <b x-text="leases.rows.length"></b></button>
                <template x-for="s in coverageStates" :key="s.key">
                    <button type="button" class="bc-chip" x-show="(leases.counts[s.key] || 0) > 0" :class="{ 'is-on': leases.filter === s.key }" @click="leases.filter = s.key">
                        <span class="bc-swatch bc-bg-tone" :class="s.tone"></span> <span x-text="s.label"></span> <b x-text="leases.counts[s.key]"></b>
                    </button>
                </template>
                <input type="search" class="form-control form-control-sm" placeholder="Search lease, customer, unit…" x-model="leases.search" style="min-width:200px;">
            </div>
            <div class="bc-toolbar-right">
                <?php if (!$isClosed && $canCreate): ?>
                <a class="btn btn-primary btn-sm" x-show="toBillCount()" :href="base + '/billing/run?cycle=' + cycle.id">Bill the rest in the workbench</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <template x-if="leases.loading"><div><template x-for="n in 5" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!leases.loading && !leasesVisible().length"><div class="bc-empty">No leases match.</div></template>
            <template x-if="!leases.loading && leasesVisible().length">
                <div style="overflow-x:auto;">
                <table class="table" aria-label="Leases this month">
                    <thead><tr><th>Lease</th><th>Customer</th><th>Rented</th><th>This month</th><th>Invoices / detail</th></tr></thead>
                    <tbody>
                        <template x-for="r in leasesVisible()" :key="r.lease_id">
                            <tr>
                                <td class="bc-nowrap"><a :href="base + '/leases/show?id=' + r.lease_id" x-text="r.contract_number"></a>
                                    <div class="bc-sub" x-text="r.unit_number ? 'Unit ' + r.unit_number : ''"></div></td>
                                <td x-text="r.company_name"></td>
                                <td class="bc-nowrap text-sm" x-text="fmtDate(r.start_date) + ' → ' + (r.return_date ? fmtDate(r.return_date) + ' (returned)' : (r.end_date ? fmtDate(r.end_date) : 'open'))"></td>
                                <td><span class="bc-pill" :class="stateTone(r.status)" x-text="stateLabel(r.status)"></span></td>
                                <td class="text-sm">
                                    <template x-for="inv in r.invoices" :key="inv.id">
                                        <div><a :href="base + '/invoices/show?id=' + inv.id" x-text="inv.invoice_number"></a>
                                            <span class="bc-muted" x-text="inv.status + (inv.total_amount !== null ? ' · ' + money(inv.total_amount, inv.currency) : '')"></span></div>
                                    </template>
                                    <div x-show="r.covered_by" class="bc-muted" x-text="r.covered_by ? 'Covered by ' + r.covered_by.invoice_number + ' (' + r.covered_by.period.replace('..', ' – ') + ')' : ''"></div>
                                    <div x-show="r.hold" class="bc-muted" x-text="r.hold ? 'Hold: ' + r.hold.reason : ''"></div>
                                    <div x-show="r.exception" class="bc-muted" x-text="r.exception ? 'Exception: ' + r.exception.reason : ''"></div>
                                    <a x-show="r.status === 'closed_unbilled'" :href="base + '/invoices/create?lease_id=' + r.lease_id">Generate invoice →</a>
                                    <span x-show="r.status === 'bills_at_close'" class="bc-muted">Billed once, at close.</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
    </section>

    <!-- ===========================================================
         CUSTOMERS (S-BILLING-MODULE-2)
         =========================================================== -->
    <section x-show="tab === 'customers'">
        <?php $renderGuide('customers'); ?>
        <div class="bc-toolbar">
            <div class="bc-toolbar-left bc-filterchips">
                <button type="button" class="bc-chip" :class="{ 'is-on': cu.filter === '' }" @click="cu.filter = ''">All <b x-text="cu.rows.length"></b></button>
                <button type="button" class="bc-chip" :class="{ 'is-on': cu.filter === 'ready' }" @click="cu.filter = 'ready'">Ready to send <b x-text="cu.rows.filter(r => r.drafts > 0 && r.leases_to_bill === 0).length"></b></button>
                <button type="button" class="bc-chip" :class="{ 'is-on': cu.filter === 'tobill' }" @click="cu.filter = 'tobill'">Leases still to bill <b x-text="cu.rows.filter(r => r.leases_to_bill > 0).length"></b></button>
                <button type="button" class="bc-chip" :class="{ 'is-on': cu.filter === 'notemailed' }" @click="cu.filter = 'notemailed'">Sent, not emailed <b x-text="cu.rows.filter(r => r.sent > r.emailed).length"></b></button>
                <input type="search" class="form-control form-control-sm" placeholder="Search customer…" x-model="cu.search" style="min-width:200px;">
            </div>
            <div class="bc-toolbar-right"><label class="text-sm" style="display:flex; gap:6px; align-items:center;"><input type="checkbox" x-model="cu.attachPdf"> Attach the PDFs</label></div>
        </div>
        <div class="alert alert-info" x-show="cu.result" style="margin-bottom:10px;"><span x-text="cu.result"></span></div>
        <div class="card">
            <template x-if="cu.loading"><div><template x-for="n in 5" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!cu.loading && !cuVisible().length"><div class="bc-empty">No customers match.</div></template>
            <template x-if="!cu.loading && cuVisible().length">
                <div style="overflow-x:auto;">
                <table class="table" aria-label="Customers this month">
                    <thead><tr><th>Customer</th><th>Leases</th><th>Invoices</th><?php if ($showMoney): ?><th class="text-right">This month</th><?php endif; ?><th>Goes to</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="r in cuVisible()" :key="r.customer_id">
                            <tr>
                                <td><a :href="base + '/customers/show?id=' + r.customer_id" x-text="r.company_name"></a>
                                    <div class="bc-sub" x-text="(r.payment_terms ? 'Terms ' + r.payment_terms : 'Default terms') + (r.customer_status !== 'active' ? ' · ' + r.customer_status.replace('_', ' ') : '')"></div>
                                    <div class="bc-sub" x-show="r.hold" style="color:var(--color-warning);" x-text="'On hold: ' + r.hold"></div></td>
                                <td class="text-sm bc-nowrap">
                                    <span x-text="r.leases_billed + ' of ' + r.leases + ' billed'"></span>
                                    <div class="bc-sub" x-show="r.leases_to_bill" style="color:var(--color-warning);" x-text="r.leases_to_bill + ' still to bill'"></div>
                                    <div class="bc-sub" x-show="r.leases_held" x-text="r.leases_held + ' on hold'"></div>
                                </td>
                                <td class="text-sm bc-nowrap">
                                    <span x-text="r.invoices + ' invoice(s)'"></span>
                                    <div class="bc-sub" x-text="r.drafts + ' draft · ' + r.sent + ' sent · ' + r.emailed + ' emailed'"></div>
                                </td>
                                <?php if ($showMoney): ?><td class="bc-amount" x-text="moneyMap(r.total)"></td><?php endif; ?>
                                <td class="text-sm">
                                    <span x-text="r.delivery_pref === 'email' ? (r.recipient || 'no email on file') : ({ mail: 'Mail', portal: 'Portal only', none: 'Kept on file' }[r.delivery_pref] || r.delivery_pref)"
                                          :style="(r.delivery_pref === 'email' && (!r.recipient || r.email_disabled)) ? 'color:var(--color-danger)' : ''"></span>
                                    <div class="bc-sub" x-show="r.email_disabled" style="color:var(--color-danger);">email bounced — switched off</div>
                                    <div class="bc-sub" x-show="r.last_emailed" x-text="r.last_emailed ? 'last emailed ' + FF_formatUtc(r.last_emailed) : ''"></div>
                                </td>
                                <td class="text-right bc-nowrap">
                                    <?php if ($canEdit): ?>
                                    <button type="button" class="btn btn-primary btn-xs" x-show="r.invoices > 0 && r.delivery_pref === 'email'" :disabled="cu.working === r.customer_id"
                                            @click="sendCustomer(r)" x-text="cu.working === r.customer_id ? 'Sending…' : (r.drafts ? 'Send month' : 'Email again')"></button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-ghost btn-xs" @click="dl.search = r.company_name; setTab('delivery')">Invoices</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
    </section>

    <!-- ===========================================================
         REVIEW
         =========================================================== -->
    <section x-show="tab === 'review'">
        <?php $renderGuide('review'); ?>
        <div class="bc-toolbar">
            <div class="bc-toolbar-left bc-filterchips">
                <button type="button" class="bc-chip" :class="{ 'is-on': rv.filter === 'flagged' }" @click="rv.filter = 'flagged'">Flagged <b x-text="rv.rows.filter(r => r.worst === 'danger' || r.worst === 'warning').length"></b></button>
                <button type="button" class="bc-chip" :class="{ 'is-on': rv.filter === 'unreviewed' }" @click="rv.filter = 'unreviewed'">Not reviewed <b x-text="rv.rows.filter(r => !r.review).length"></b></button>
                <button type="button" class="bc-chip" :class="{ 'is-on': rv.filter === 'query' }" @click="rv.filter = 'query'">Queried <b x-text="rv.rows.filter(r => r.review && r.review.status === 'query').length"></b></button>
                <button type="button" class="bc-chip" :class="{ 'is-on': rv.filter === 'all' }" @click="rv.filter = 'all'">All <b x-text="rv.rows.length"></b></button>
                <template x-for="(n, k) in rv.flag_counts" :key="k">
                    <button type="button" class="bc-chip" :class="{ 'is-on': rv.filter === 'flag:' + k }" @click="rv.filter = 'flag:' + k"><span x-text="flagLabel(k)"></span> <b x-text="n"></b></button>
                </template>
            </div>
            <div class="bc-toolbar-right">
                <input type="search" class="form-control form-control-sm" placeholder="Search…" x-model="rv.search" style="min-width:180px;">
            </div>
        </div>
        <?php if ($canEdit): ?>
        <div class="ff-bulk-bar" x-show="rvSelected().length" x-cloak>
            <span class="ff-bulk-bar-count" x-text="rvSelected().length + ' selected'"></span>
            <div class="ff-bulk-bar-sep"></div>
            <button class="ff-bulk-btn" @click="markReview('reviewed')" :disabled="rv.working">Mark reviewed</button>
            <button class="ff-bulk-btn" @click="markReview('query')" :disabled="rv.working">Query…</button>
            <button class="ff-bulk-btn" @click="markReview('clear')" :disabled="rv.working">Clear mark</button>
            <div class="ff-bulk-bar-sep"></div>
            <button class="ff-bulk-btn" @click="regenerateSelected()" :disabled="rv.working" title="Rebuild the selected DRAFTS from their lease's current data (same invoice numbers)">Regenerate drafts</button>
            <button class="ff-bulk-btn ff-bulk-btn-delete" @click="voidSelected()" :disabled="rv.working" title="Void the selected invoices (draft or sent) — asks for a reason">Void…</button>
            <button class="ff-bulk-btn ff-bulk-btn-clear" @click="rv.selected = {}" title="Clear selection">×</button>
        </div>
        <?php endif; ?>
        <div class="alert alert-info" x-show="rv.result" style="margin-bottom:10px;">
            <span x-text="rv.result ? rv.result.text : ''"></span>
            <ul style="margin:6px 0 0; padding-left:18px;" x-show="rv.result && rv.result.errors.length">
                <template x-for="(er, i) in (rv.result ? rv.result.errors : [])" :key="i"><li x-text="er"></li></template>
            </ul>
        </div>
        <div class="card">
            <template x-if="rv.loading"><div><template x-for="n in 5" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!rv.loading && !rvVisible().length">
                <div class="bc-empty" x-text="rv.rows.length ? 'Nothing matches this filter.' : 'No invoices in this cycle yet.'"></div>
            </template>
            <template x-if="!rv.loading && rvVisible().length">
                <div style="overflow-x:auto;">
                <table class="table" aria-label="Invoice review">
                    <thead>
                        <tr>
                            <?php if ($canEdit): ?><th style="width:28px;"><input type="checkbox" @change="rvToggleAll($event.target.checked)" aria-label="Select all"></th><?php endif; ?>
                            <th>Invoice</th><th>Customer / lease</th><th>Period</th>
                            <?php if ($showMoney): ?><th class="text-right">Total</th><th class="text-right">Last month</th><?php endif; ?>
                            <th>Flags</th><th>Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in rvVisible()" :key="r.invoice_id">
                            <tr :class="{ 'bc-row-danger': r.worst === 'danger', 'bc-row-warning': r.worst === 'warning' }">
                                <?php if ($canEdit): ?><td><input type="checkbox" :checked="!!rv.selected[r.invoice_id]" @change="rv.selected[r.invoice_id] = $event.target.checked" :aria-label="'Select ' + r.invoice_number"></td><?php endif; ?>
                                <td class="bc-nowrap"><a :href="base + '/invoices/show?id=' + r.invoice_id" target="_blank" x-text="r.invoice_number"></a>
                                    <div class="bc-sub" x-text="r.status + (r.generation_source ? ' · ' + r.generation_source : '')"></div>
                                    <button type="button" class="btn btn-ghost btn-xs" @click="openPreview(r.invoice_id, r.invoice_number)">Preview</button></td>
                                <td><div x-text="r.company_name"></div><div class="bc-sub" x-text="r.contract_number + (r.unit_number ? ' · ' + r.unit_number : '')"></div></td>
                                <td class="bc-nowrap text-sm" x-text="fmtDate(r.period_start) + ' – ' + fmtDate(r.period_end)"></td>
                                <?php if ($showMoney): ?>
                                <td class="text-right bc-mono bc-nowrap" x-text="money(r.total_amount, r.currency)"></td>
                                <td class="text-right bc-mono bc-nowrap">
                                    <span x-text="r.previous_total !== null ? money(r.previous_total, r.currency) : '—'"></span>
                                    <div class="bc-sub" x-show="r.change !== null" :style="Number(r.change) > 0 ? 'color:var(--color-success)' : (Number(r.change) < 0 ? 'color:var(--color-danger)' : '')"
                                         x-text="r.change !== null ? (Number(r.change) > 0 ? '+' : '') + money(r.change, r.currency) : ''"></div>
                                </td>
                                <?php endif; ?>
                                <td style="max-width:420px;">
                                    <div class="bc-flags">
                                        <template x-for="(f, idx) in r.flags" :key="idx"><div class="bc-flag" :data-sev="f.severity" x-text="f.text"></div></template>
                                        <span class="bc-sub" x-show="!r.flags.length">No flags</span>
                                        <?php if ($canEdit): ?>
                                        <button type="button" class="btn btn-secondary btn-xs" style="align-self:flex-start;"
                                                x-show="r.status === 'draft' && r.flags.some(f => f.key === 'double_mileage') && cycle.status === 'open'"
                                                @click="fixDoubleMileage(r)">Fix: remove the overage line</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="bc-nowrap text-sm">
                                    <template x-if="r.review">
                                        <div>
                                            <span class="bc-pill" :class="r.review.status === 'reviewed' ? 'bc-tone-success' : 'bc-tone-warning'" x-text="r.review.status === 'reviewed' ? 'Reviewed' : 'Query'"></span>
                                            <div class="bc-sub" x-text="(r.review.by || '') + (r.review.note ? ': ' + r.review.note : '')"></div>
                                        </div>
                                    </template>
                                    <?php if ($canEdit): ?>
                                    <button type="button" class="btn btn-ghost btn-xs" x-show="!r.review && cycle.status === 'open'" @click="markOne(r, 'reviewed')">✓ Reviewed</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
        <div class="card" style="margin-top:14px;" x-show="rv.missing.length">
            <div class="card-header"><h3 class="card-title">Billed last month, nothing this month</h3></div>
            <div class="card-body" style="padding-top:0;">
                <p class="bc-muted text-sm" style="margin:0 0 8px;">These leases were billed in the previous month and are still on rent, but have no invoice, hold or exception this month.</p>
                <table class="table">
                    <thead><tr><th>Lease</th><th>Customer</th><th>Status</th></tr></thead>
                    <tbody>
                        <template x-for="m in rv.missing" :key="m.lease_id">
                            <tr>
                                <td><a :href="base + '/leases/show?id=' + m.lease_id" x-text="m.contract_number"></a></td>
                                <td x-text="m.company_name"></td>
                                <td><span class="bc-pill" :class="stateTone(m.status)" x-text="stateLabel(m.status)"></span></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ===========================================================
         DELIVERY
         =========================================================== -->
    <section x-show="tab === 'delivery'">
        <?php $renderGuide('delivery'); ?>
        <div class="bc-toolbar">
            <div class="bc-toolbar-left bc-filterchips">
                <button type="button" class="bc-chip" :class="{ 'is-on': dl.filter === '' }" @click="dl.filter = ''">All <b x-text="dl.rows.length"></b></button>
                <template x-for="s in deliveryStates" :key="s.key">
                    <button type="button" class="bc-chip" x-show="(dl.counts[s.key] || 0) > 0" :class="{ 'is-on': dl.filter === s.key }" @click="dl.filter = s.key">
                        <span class="bc-swatch bc-bg-tone" :class="s.tone"></span> <span x-text="s.label"></span> <b x-text="dl.counts[s.key]"></b>
                    </button>
                </template>
                <input type="search" class="form-control form-control-sm" placeholder="Search…" x-model="dl.search" style="min-width:180px;">
            </div>
            <div class="bc-toolbar-right">
                <label class="text-sm" style="display:flex; gap:6px; align-items:center;"><input type="checkbox" x-model="dl.attachPdf"> Attach the PDF</label>
            </div>
        </div>
        <?php if ($canEdit): ?>
        <div class="ff-bulk-bar" x-show="dlSelected().length" x-cloak>
            <span class="ff-bulk-bar-count" x-text="dlSelected().length + ' selected'"></span>
            <div class="ff-bulk-bar-sep"></div>
            <button class="ff-bulk-btn" x-show="dlSelected().some(r => r.state === 'draft')" @click="sendDrafts(true)" :disabled="dl.working"
                    x-text="'Send & email ' + dlSelected().filter(r => r.state === 'draft').length + ' draft(s)'"></button>
            <button class="ff-bulk-btn" x-show="dlSelected().some(r => r.state === 'draft')" @click="sendDrafts(false)" :disabled="dl.working"
                    x-text="'Mark ' + dlSelected().filter(r => r.state === 'draft').length + ' as sent (no email)'"></button>
            <button class="ff-bulk-btn" x-show="dlSelected().some(r => r.state !== 'draft')" @click="emailAgain()" :disabled="dl.working"
                    x-text="'Email ' + dlSelected().filter(r => r.state !== 'draft').length + ' sent invoice(s)'"></button>
            <button class="ff-bulk-btn" x-show="dlSelected().some(r => r.state !== 'draft')" @click="markDelivered('manual')" :disabled="dl.working">Mark as mailed</button>
            <button class="ff-bulk-btn" x-show="dlSelected().some(r => r.state !== 'draft')" @click="markDelivered('portal')" :disabled="dl.working">Mark as on portal</button>
            <div class="ff-bulk-bar-sep"></div>
            <button class="ff-bulk-btn" @click="downloadPdfs('pdf')" :disabled="dl.working" title="One PDF with every selected invoice — for printing">Download PDF</button>
            <button class="ff-bulk-btn" @click="downloadPdfs('zip')" :disabled="dl.working">ZIP</button>
            <button class="ff-bulk-btn ff-bulk-btn-clear" @click="dl.selected = {}" title="Clear selection">×</button>
        </div>
        <?php endif; ?>
        <div class="alert alert-info" x-show="dl.result" style="margin-bottom:10px;">
            <span x-text="dl.result ? dl.result.text : ''"></span>
            <ul style="margin:6px 0 0; padding-left:18px;" x-show="dl.result && dl.result.errors.length">
                <template x-for="(er, i) in (dl.result ? dl.result.errors : [])" :key="i"><li x-text="er"></li></template>
            </ul>
        </div>
        <div class="card">
            <template x-if="dl.loading"><div><template x-for="n in 5" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
            <template x-if="!dl.loading && !dlVisible().length">
                <div class="bc-empty" x-text="dl.rows.length ? 'Nothing matches this filter.' : 'No invoices in this cycle yet.'"></div>
            </template>
            <template x-if="!dl.loading && dlVisible().length">
                <div style="overflow-x:auto;">
                <table class="table" aria-label="Delivery">
                    <thead>
                        <tr>
                            <?php if ($canEdit): ?><th style="width:28px;"><input type="checkbox" @change="dlToggleAll($event.target.checked)" aria-label="Select all"></th><?php endif; ?>
                            <th>Invoice</th><th>Customer</th><?php if ($showMoney): ?><th class="text-right">Total</th><?php endif; ?>
                            <th>Delivery</th><th>Recipient</th><th>Last email</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in dlVisible()" :key="r.id">
                            <tr>
                                <?php if ($canEdit): ?><td><input type="checkbox" :checked="!!dl.selected[r.id]" @change="dl.selected[r.id] = $event.target.checked" :aria-label="'Select ' + r.invoice_number"></td><?php endif; ?>
                                <td class="bc-nowrap"><a :href="base + '/invoices/show?id=' + r.id" target="_blank" x-text="r.invoice_number"></a>
                                    <div class="bc-sub" x-text="r.status + (r.sent_at ? ' · sent ' + FF_formatUtc(r.sent_at, { hour: undefined, minute: undefined }) : '')"></div></td>
                                <td><div x-text="r.company_name"></div><div class="bc-sub" x-text="r.contract_number || ''"></div></td>
                                <?php if ($showMoney): ?><td class="text-right bc-mono bc-nowrap" x-text="money(r.total_amount, r.currency)"></td><?php endif; ?>
                                <td><span class="bc-pill" :class="deliveryTone(r.state)" x-text="deliveryLabel(r.state)"></span></td>
                                <td class="text-sm">
                                    <?php if ($canEdit): ?>
                                    <input type="email" class="form-control form-control-sm" style="min-width:200px;font-family:inherit;" x-model="dl.overrides[r.id]"
                                           :placeholder="r.recipient || 'no email on file'" :title="'Leave blank to use ' + (r.recipient || 'the customer\'s email')">
                                    <?php else: ?>
                                    <span x-text="r.recipient || '—'"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-sm">
                                    <template x-if="r.last_email">
                                        <div>
                                            <span :style="r.last_email.status === 'failed' ? 'color:var(--color-danger)' : ''" x-text="r.last_email.status + ' · ' + FF_formatUtc(r.last_email.at)"></span>
                                            <div class="bc-sub" x-text="r.last_email.to_email + (r.last_email.attempts > 1 ? ' · ' + r.last_email.attempts + ' attempts' : '')"></div>
                                            <div class="bc-inline-error" x-show="r.last_email.status === 'failed' && r.last_email.error" x-text="r.last_email.error"></div>
                                        </div>
                                    </template>
                                    <span class="bc-sub" x-show="!r.last_email">never emailed</span>
                                </td>
                                <td class="text-right bc-nowrap">
                                    <button type="button" class="btn btn-ghost btn-xs" @click="openPreview(r.id, r.invoice_number)">Preview</button>
                                    <a class="btn btn-ghost btn-xs" :href="base + '/api/v1/invoices/pdf?id=' + r.id" target="_blank">PDF</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </template>
        </div>
    </section>

    <!-- ===========================================================
         CLOSE
         =========================================================== -->
    <section x-show="tab === 'close'">
        <?php $renderGuide('close'); ?>
        <template x-if="!cl.data"><div><template x-for="n in 4" :key="n"><div class="skeleton skeleton-row"></div></template></div></template>
        <template x-if="cl.data">
        <div>
            <!-- Close / reopen -->
            <div class="card" style="margin-bottom:14px;">
                <div class="card-header"><h3 class="card-title" x-text="cycle.status === 'closed' ? 'Closed' : 'Close this month'"></h3></div>
                <div class="card-body">
                    <template x-if="cycle.status === 'open'">
                        <div>
                            <ul class="bc-check-list" style="margin-bottom:12px;">
                                <template x-for="c in cl.data.checks.hard" :key="c.key">
                                    <li><span class="bc-pill bc-tone-danger">Must fix</span><span x-text="c.text"></span></li>
                                </template>
                                <template x-for="c in cl.data.checks.soft" :key="c.key">
                                    <li><span class="bc-pill bc-tone-warning">Check</span><span x-text="c.text"></span></li>
                                </template>
                                <li x-show="!cl.data.checks.hard.length && !cl.data.checks.soft.length"><span class="bc-pill bc-tone-success">Ready</span><span>Every lease is accounted for and every invoice has gone out.</span></li>
                            </ul>
                            <?php if ($canEdit): ?>
                            <div class="form-group">
                                <label class="form-label" for="bc_close_note">Close note <span class="bc-muted" x-text="cl.data.checks.soft.length ? '(required to close anyway)' : '(optional)'"></span></label>
                                <textarea id="bc_close_note" class="form-control" rows="2" maxlength="2000" x-model="cl.note" placeholder="e.g. Two closed leases billed by hand next week"></textarea>
                            </div>
                            <label class="text-sm" style="display:flex; gap:6px; align-items:center; margin-bottom:10px;" x-show="cl.data.checks.soft.length && !cl.data.checks.hard.length">
                                <input type="checkbox" x-model="cl.override"> Close anyway — the checks above are known and explained in the note
                            </label>
                            <button type="button" class="btn btn-primary btn-sm" :disabled="cl.working || cl.data.checks.hard.length > 0 || (cl.data.checks.soft.length > 0 && (!cl.override || !cl.note.trim()))"
                                    @click="closeCycle()" x-text="cl.working ? 'Closing…' : 'Close and lock ' + cycle.label"></button>
                            <p class="form-hint" style="margin-top:8px;">Closing freezes this month's figures and stops the workbench billing it. It can be reopened by someone with invoice approval rights.</p>
                            <?php endif; ?>
                        </div>
                    </template>
                    <template x-if="cycle.status === 'closed'">
                        <div>
                            <p style="margin:0 0 6px;" x-text="'Closed ' + FF_formatUtc(cycle.closed_at) + ' by ' + (cycle.closed_by_name || '—') + '.'"></p>
                            <p class="bc-muted" style="margin:0 0 6px;" x-show="cycle.close_note" x-text="'Note: ' + (cycle.close_note || '')"></p>
                            <template x-if="cl.data.snapshot && cl.data.snapshot.closed_with_open_checks && cl.data.snapshot.closed_with_open_checks.length">
                                <p class="bc-muted text-sm" style="margin:0 0 6px;" x-text="'Closed with: ' + cl.data.snapshot.closed_with_open_checks.join(' ')"></p>
                            </template>
                            <?php if ($canApprove): ?>
                            <button type="button" class="btn btn-secondary btn-sm" @click="reopen()">Reopen…</button>
                            <?php endif; ?>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Figures -->
            <div class="bc-toolbar">
                <div class="bc-toolbar-left">
                    <h3 class="bc-section-title" style="margin:0;" x-text="figures() === cl.data.snapshot ? 'Figures frozen at close' : 'Figures (live)'"></h3>
                    <template x-if="cl.data.snapshot">
                        <div class="bc-filterchips">
                            <button type="button" class="bc-chip" :class="{ 'is-on': cl.view === 'snapshot' }" @click="cl.view = 'snapshot'">At close</button>
                            <button type="button" class="bc-chip" :class="{ 'is-on': cl.view === 'live' }" @click="cl.view = 'live'">Live now</button>
                        </div>
                    </template>
                </div>
                <div class="bc-toolbar-right">
                    <?php if ($canExport && $showMoney): ?>
                    <a class="btn btn-secondary btn-sm" :href="base + '/api/v1/billing/cycles/export?id=' + cycle.id">Download billing register (CSV)</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">Print</button>
                </div>
            </div>
            <div class="bc-sumgrid" style="margin-bottom:14px;">
                <div class="bc-sum"><div class="bc-sum-label">Invoices</div><div class="bc-sum-value" x-text="figures().invoices.live"></div>
                    <div class="bc-sum-sub" x-text="figures().invoices.issued + ' sent · ' + figures().invoices.drafts + ' draft · ' + figures().invoices.void + ' void'"></div></div>
                <template x-if="figures().money">
                    <div class="bc-sum"><div class="bc-sum-label">Billed (CAD)</div><div class="bc-sum-value" x-text="money(figures().money.total_cad)"></div>
                        <div class="bc-sum-sub" x-text="'incl. ' + money(figures().money.tax_cad) + ' tax'"></div></div>
                </template>
                <template x-if="figures().money">
                    <div class="bc-sum"><div class="bc-sum-label">Collected so far</div><div class="bc-sum-value" x-text="money(figures().money.paid_cad)"></div>
                        <div class="bc-sum-sub" x-text="money(figures().money.balance_cad) + ' still owed'"></div></div>
                </template>
                <template x-if="figures().previous">
                    <div class="bc-sum"><div class="bc-sum-label" x-text="'vs ' + figures().previous.label"></div>
                        <div class="bc-sum-value" :style="Number(figures().previous.change_cad) >= 0 ? 'color:var(--color-success)' : 'color:var(--color-danger)'"
                             x-text="(Number(figures().previous.change_cad) >= 0 ? '+' : '') + money(figures().previous.change_cad)"></div>
                        <div class="bc-sum-sub" x-text="(figures().previous.change_pct !== null ? figures().previous.change_pct + '% · ' : '') + figures().previous.live + ' invoices then'"></div></div>
                </template>
                <div class="bc-sum"><div class="bc-sum-label">Emailed</div><div class="bc-sum-value" x-text="figures().delivery.emailed + ' / ' + figures().delivery.issued"></div>
                    <div class="bc-sum-sub" x-text="figures().delivery.not_emailed + ' sent without email'"></div></div>
                <div class="bc-sum"><div class="bc-sum-label">Opened → all sent</div>
                    <div class="bc-sum-value" x-text="figures().timeline.days_open_to_sent !== null ? figures().timeline.days_open_to_sent + ' d' : '—'"></div>
                    <div class="bc-sum-sub" x-text="figures().timeline.last_sent ? 'last sent ' + FF_formatUtc(figures().timeline.last_sent, { hour: undefined, minute: undefined }) : 'nothing sent yet'"></div></div>
            </div>

            <div class="bc-grid-2" x-show="figures().money">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">What was billed (before tax, CAD)</h3></div>
                    <div class="card-body">
                        <template x-for="c in (figures().categories || [])" :key="c.key">
                            <div class="bc-catbar">
                                <span class="bc-catbar-label" x-text="c.label"></span>
                                <span class="bc-catbar-track"><span :style="'width:' + catPct(c) + '%'"></span></span>
                                <span class="bc-catbar-amt" x-text="money(c.amount_cad)"></span>
                            </div>
                        </template>
                        <div style="overflow-x:auto; margin-top:12px;">
                        <table class="table" x-show="figures().money">
                            <thead><tr><th>Currency</th><th class="text-right">Invoices</th><th class="text-right">Subtotal</th><th class="text-right">Tax</th><th class="text-right">Total</th><th class="text-right">Balance</th></tr></thead>
                            <tbody>
                                <template x-for="(v, cur) in (figures().money ? figures().money.by_currency : {})" :key="cur">
                                    <tr><td x-text="cur"></td><td class="text-right bc-mono" x-text="v.count"></td>
                                        <td class="text-right bc-mono" x-text="money(v.subtotal, cur)"></td><td class="text-right bc-mono" x-text="money(v.tax, cur)"></td>
                                        <td class="text-right bc-mono" x-text="money(v.total, cur)"></td><td class="text-right bc-mono" x-text="money(v.balance, cur)"></td></tr>
                                </template>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Largest customers this month</h3></div>
                    <div class="card-body" style="padding-top:0;">
                        <table class="table">
                            <thead><tr><th>Customer</th><th class="text-right">Invoices</th><th class="text-right">Billed (CAD)</th></tr></thead>
                            <tbody>
                                <template x-for="c in (figures().customers || [])" :key="c.customer_id">
                                    <tr><td><a :href="base + '/customers/show?id=' + c.customer_id" x-text="c.company_name"></a></td>
                                        <td class="text-right bc-mono" x-text="c.invoices"></td><td class="text-right bc-mono" x-text="money(c.total_cad)"></td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top:14px;" x-show="cl.data.late_additions.length">
                <div class="card-header"><h3 class="card-title">Added after the cycle closed</h3></div>
                <div class="card-body" style="padding-top:0;">
                    <table class="table">
                        <thead><tr><th>Invoice</th><th>Customer</th><th>Status</th><?php if ($showMoney): ?><th class="text-right">Total</th><?php endif; ?><th>Created</th><th>How</th></tr></thead>
                        <tbody>
                            <template x-for="a in cl.data.late_additions" :key="a.id">
                                <tr><td><a :href="base + '/invoices/show?id=' + a.id" x-text="a.invoice_number"></a></td><td x-text="a.company_name"></td><td x-text="a.status"></td>
                                    <?php if ($showMoney): ?><td class="text-right bc-mono" x-text="money(a.total_amount, a.currency)"></td><?php endif; ?>
                                    <td class="text-sm" x-text="FF_formatUtc(a.created_at)"></td><td class="text-sm" x-text="a.generation_source || '—'"></td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </template>
    </section>

    <!-- ===========================================================
         ACTIVITY
         =========================================================== -->
    <section x-show="tab === 'activity'">
        <?php
        $activityEntityType = 'billing_cycle';
        $activityEntityId   = (int) $cycle['id'];
        $activityOriginAt   = (string) $cycle['created_at'];
        $activityOriginBy   = $cycle['opened_by_name'] ?? 'scheduled job';
        require FF_ROOT . '/includes/partials/activity-log.php';
        ?>
    </section>

    <!-- ── Invoice preview drawer (Review / Delivery) ──────────── -->
    <template x-if="preview.id">
        <div>
            <div class="bc-drawer-backdrop" @click="preview.id = null"></div>
            <div class="bc-drawer" @keydown.escape.window="preview.id = null">
                <div class="bc-drawer-head">
                    <strong x-text="preview.label"></strong>
                    <a class="btn btn-secondary btn-xs" :href="base + '/invoices/show?id=' + preview.id" target="_blank">Open full page ↗</a>
                    <button type="button" class="btn btn-ghost btn-xs" @click="preview.id = null">Close</button>
                </div>
                <iframe :src="base + '/invoices/show?id=' + preview.id + '&embed=1'" title="Invoice preview"></iframe>
            </div>
        </div>
    </template>

    <!-- ── Add charge modal ────────────────────────────────────── -->
    <?php if ($canCreate && $showMoney): ?>
    <div x-show="chModal.open" x-cloak class="modal-overlay" style="z-index:var(--z-modal);">
        <div class="modal-backdrop" @click="chModal.open = false"></div>
        <div class="modal" @click.stop style="max-width:560px;">
            <div class="modal-header">
                <h3 class="modal-title">Add a charge</h3>
                <button type="button" class="modal-close-btn" aria-label="Close" @click="chModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <p class="form-hint" style="margin-top:0;">It is added to the lease's next invoice automatically — once, or on one invoice every month.</p>
                <div class="form-group">
                    <label class="form-label">Lease <span class="required">*</span></label>
                    <template x-if="chModal.open">
                        <div>
                            <?php
                            $pickerName      = 'bc_charge_lease_picker';
                            $pickerConfig    = [
                                'endpoint'    => '/api/v1/leases/index.php',
                                'searchParam' => 'search',
                                'resultKey'   => 'items',
                                'perPage'     => 10,
                                'extraParams' => 'status=active',
                                'placeholder' => 'Search leases by contract #, customer or unit…',
                                'mapResult'   => "r => ({ id: r.id, label: r.contract_number + ' — ' + (r.customer_display_name || ''), sublabel: 'Unit ' + (r.unit_display_number || '—') + ' · ' + r.status + ' · ' + (r.currency || ''), raw: r })",
                            ];
                            $pickerOnPicked  = 'chModal.lease_id = $event.detail.id';
                            $pickerOnCleared = 'chModal.lease_id = null';
                            $pickerError     = 'chModal.errors.lease_id';
                            require FF_ROOT . '/includes/partials/record-picker.php';
                            ?>
                        </div>
                    </template>
                    <div class="field-error" x-show="chModal.errors.lease_id" x-text="chModal.errors.lease_id"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="bc_ch_desc">Description (prints on the invoice) <span class="required">*</span></label>
                    <input id="bc_ch_desc" type="text" class="form-control" maxlength="255" x-model="chModal.description" placeholder="e.g. Tire replacement — driver side">
                    <div class="field-error" x-show="chModal.errors.description" x-text="chModal.errors.description"></div>
                </div>
                <div class="bc-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="bc_ch_type">Type</label>
                        <select id="bc_ch_type" class="form-select" x-model="chModal.item_type">
                            <template x-for="(label, key) in ch.types" :key="key"><option :value="key" x-text="label"></option></template>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display:flex; gap:6px; align-items:center; margin-top:26px;"><input type="checkbox" x-model="chModal.taxable"> Taxable</label>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bc_ch_qty">Quantity</label>
                        <input id="bc_ch_qty" type="text" inputmode="decimal" class="form-control" x-model="chModal.quantity">
                        <div class="field-error" x-show="chModal.errors.quantity" x-text="chModal.errors.quantity"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bc_ch_price">Price each (lease currency) <span class="required">*</span></label>
                        <input id="bc_ch_price" type="text" inputmode="decimal" class="form-control" x-model="chModal.unit_price" placeholder="0.00">
                        <div class="field-error" x-show="chModal.errors.unit_price" x-text="chModal.errors.unit_price"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">How often</label>
                    <div style="display:flex; gap:16px; flex-wrap:wrap;">
                        <label style="display:flex; gap:6px; align-items:center;"><input type="radio" value="once" x-model="chModal.recurrence"> Once — on the next invoice</label>
                        <label style="display:flex; gap:6px; align-items:center;"><input type="radio" value="monthly" x-model="chModal.recurrence"> Every month</label>
                    </div>
                </div>
                <div class="bc-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="bc_ch_from" x-text="chModal.recurrence === 'monthly' ? 'First month (from)' : 'Bill from'"></label>
                        <input id="bc_ch_from" type="date" class="form-control" x-model="chModal.bill_from">
                    </div>
                    <div class="form-group" x-show="chModal.recurrence === 'monthly'">
                        <label class="form-label" for="bc_ch_until">Last month (optional)</label>
                        <input id="bc_ch_until" type="date" class="form-control" x-model="chModal.bill_until">
                        <div class="field-error" x-show="chModal.errors.bill_until" x-text="chModal.errors.bill_until"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="bc_ch_notes">Internal note (optional)</label>
                    <input id="bc_ch_notes" type="text" class="form-control" maxlength="500" x-model="chModal.notes" placeholder="Not shown to the customer">
                </div>
                <p class="form-hint" style="margin:0;" x-show="chTotal()" x-text="'Adds ' + chTotal() + ' (before tax)' + (chModal.recurrence === 'monthly' ? ' every month.' : ' once.')"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" @click="chModal.open = false">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="chModal.saving" @click="saveCharge()" x-text="chModal.saving ? 'Saving…' : 'Add charge'"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
@media print {
    .sidebar, .sidebar-overlay, .topbar, .tab-bar, .bc-stepper, .ff-hero-actions, .bc-toolbar-right, .app-footer { display: none !important; }
    #billing-cycle section { display: block !important; }
    #billing-cycle section:not([x-show="tab === 'close'"]) { display: none !important; }
}
</style>

<script>
function FF_BillingCycle(cfg) {
    const base = <?= json_encode(rtrim(base_url(''), '/')) ?>;
    const api  = base + '/api/v1';
    const TABS = ['overview', 'readiness', 'readings', 'charges', 'leases', 'customers', 'review', 'delivery', 'close', 'activity'];
    const GUIDE_KEY = 'ff_billing_guide_hidden';
    return {
        base, cfg,
        tab: 'overview',
        cycle: { id: cfg.id, status: cfg.status, label: cfg.label },
        ov: null,
        users: [],
        edit: { open: false, saving: false, errors: {}, owner_user_id: '', bill_by_date: '', send_by_date: '', notes: '' },
        ready: { loading: false, loaded: false, checks: [], summary: null, checked_at: null, open: {}, showPassed: false },
        rd: { loading: false, loaded: false, rows: [], edit: {}, orig: {}, errors: {}, filter: 'needed', search: '', saving: false },
        leases: { loading: false, loaded: false, rows: [], counts: {}, filter: '', search: '' },
        rv: { loading: false, loaded: false, rows: [], missing: [], flag_counts: {}, filter: 'flagged', search: '', selected: {}, working: false, result: null },
        dl: { loading: false, loaded: false, rows: [], counts: {}, filter: '', search: '', selected: {}, overrides: {}, attachPdf: true, working: false, result: null },
        cl: { loaded: false, data: null, note: '', override: false, working: false, view: 'snapshot' },
        ch: { loading: false, loaded: false, rows: [], state: 'pending', types: {} },
        chModal: { open: false, saving: false, errors: {}, lease_id: null, description: '', item_type: 'other', quantity: '1', unit_price: '', taxable: true, recurrence: 'once', bill_from: '', bill_until: '', notes: '' },
        cu: { loading: false, loaded: false, rows: [], filter: '', search: '', attachPdf: true, working: null, result: '' },
        preview: { id: null, label: '' },
        guideHidden: (() => { try { return JSON.parse(localStorage.getItem(GUIDE_KEY) || '{}') || {}; } catch (e) { return {}; } })(),

        coverageStates: [
            { key: 'billed',            label: 'Billed',               tone: 'bc-tone-success' },
            { key: 'covered_elsewhere', label: 'Covered by another bill', tone: 'bc-tone-info' },
            { key: 'held',              label: 'On hold',              tone: 'bc-tone-muted' },
            { key: 'bills_at_close',    label: 'Bills at close',       tone: 'bc-tone-muted' },
            { key: 'exception',         label: 'Exception',            tone: 'bc-tone-danger' },
            { key: 'closed_unbilled',   label: 'Closed, unbilled',     tone: 'bc-tone-warning' },
            { key: 'void_rebillable',   label: 'Voided, rebill',       tone: 'bc-tone-warning' },
            { key: 'to_bill',           label: 'To bill',              tone: 'bc-tone-primary' },
        ],
        deliveryStates: [
            { key: 'draft',        label: 'Draft — not sent',    tone: 'bc-tone-primary' },
            { key: 'emailed',      label: 'Emailed',              tone: 'bc-tone-success' },
            { key: 'not_emailed',  label: 'Sent, never emailed',  tone: 'bc-tone-warning' },
            { key: 'email_failed', label: 'Email failed',         tone: 'bc-tone-danger' },
            { key: 'bounced',      label: 'Email bounced',        tone: 'bc-tone-danger' },
            { key: 'print',        label: 'Print & mail',         tone: 'bc-tone-info' },
            { key: 'mailed',       label: 'Mailed / handed over', tone: 'bc-tone-success' },
            { key: 'on_portal',    label: 'On the portal',        tone: 'bc-tone-success' },
            { key: 'portal',       label: 'Portal / no delivery', tone: 'bc-tone-muted' },
        ],

        get tabList() {
            const s = this.ov ? this.ov.stats : null;
            const rs = this.cycle.readiness_summary || null;
            return [
                { key: 'overview',  label: 'Overview' },
                { key: 'readiness', label: 'Readiness', badge: rs ? (rs.blocker || rs.warning || '') : '', tone: rs && rs.blocker ? 'badge-danger' : 'badge-warning' },
                { key: 'readings',  label: 'Readings',  badge: this.ov && this.ov.readings.missing ? this.ov.readings.missing : '', tone: 'badge-warning' },
                { key: 'charges',   label: 'Charges' },
                { key: 'leases',    label: 'Leases',    badge: this.toBillCount() || '', tone: 'badge-primary' },
                { key: 'customers', label: 'Customers' },
                { key: 'review',    label: 'Review',    badge: s ? (s.unreviewed_drafts || '') : '', tone: 'badge-neutral' },
                { key: 'delivery',  label: 'Delivery',  badge: s ? (s.drafts || '') : '', tone: 'badge-warning' },
                { key: 'close',     label: this.cycle.status === 'closed' ? 'Summary' : 'Close' },
                { key: 'activity',  label: 'Activity' },
            ];
        },

        init() {
            if (this._inited) return; this._inited = true;
            const h = (location.hash || '').replace('#', '');
            if (TABS.includes(h)) this.tab = h;
            this.loadOverview();
            this.loadTab();
        },
        setTab(t) {
            this.tab = t;
            history.replaceState(null, '', '#' + t);
            this.loadTab();
        },
        loadTab() {
            if (this.tab === 'readiness' && !this.ready.loaded) this.loadReadiness();
            if (this.tab === 'readings' && !this.rd.loaded) this.loadReadings();
            if (this.tab === 'leases' && !this.leases.loaded) this.loadLeases();
            if (this.tab === 'review' && !this.rv.loaded) this.loadReview();
            if (this.tab === 'delivery' && !this.dl.loaded) this.loadDelivery();
            if (this.tab === 'close' && !this.cl.loaded) this.loadClose();
            if (this.tab === 'charges' && !this.ch.loaded) this.loadCharges();
            if (this.tab === 'customers' && !this.cu.loaded) this.loadCustomers();
        },

        // ── guides + sign-offs ──
        guideOpen(t) { return !this.guideHidden[t]; },
        toggleGuide(t) {
            this.guideHidden = { ...this.guideHidden, [t]: !this.guideHidden[t] };
            try { localStorage.setItem(GUIDE_KEY, JSON.stringify(this.guideHidden)); } catch (e) { /* convenience only */ }
        },
        signoffFor(step) { return (this.cycle.step_signoffs && this.cycle.step_signoffs[step]) || null; },
        async signoff(step, on) {
            let note = '';
            if (on) {
                note = await FF_Confirm.askText({ title: 'Sign off this step', message: 'Records that you finished it. Add a note (optional).', confirmLabel: 'Sign off', placeholder: 'e.g. All readings entered from the yard sheet' });
                if (note === null) return;
            }
            const r = await FF_Api.post(api + '/billing/cycles/signoff', { id: this.cycle.id, step, signed: on, note });
            if (r.success) { this.cycle.step_signoffs = r.data.step_signoffs; this.loadOverview(); }
            else FF_Toast.error(r.error?.message || 'Could not update the sign-off.');
        },

        // ── preview drawer ──
        openPreview(id, label) { this.preview = { id, label: label || ('Invoice #' + id) }; },
        moneyMap(m) {
            if (!m) return '—';
            const parts = Object.entries(m).map(([cur, v]) => this.money(v, cur));
            return parts.length ? parts.join(' + ') : '—';
        },

        // ── charges ──
        async loadCharges() {
            this.ch.loading = true;
            const r = await FF_Api.get(api + '/billing/charges/index?month=' + this.cycle.month + '&state=' + this.ch.state);
            this.ch.loading = false;
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not load charges.'); return; }
            Object.assign(this.ch, { loaded: true, rows: r.data.charges, types: r.data.item_types });
        },
        openChargeModal() {
            if (!Object.keys(this.ch.types).length) this.loadCharges();
            const firstOfMonth = this.cycle.month + '-01';
            this.chModal = { open: true, saving: false, errors: {}, lease_id: null, description: '', item_type: 'other', quantity: '1', unit_price: '', taxable: true, recurrence: 'once', bill_from: firstOfMonth, bill_until: '', notes: '' };
        },
        chTotal() {
            const q = parseFloat(this.chModal.quantity), p = parseFloat(this.chModal.unit_price);
            return (q > 0 && p > 0) ? this.money((Math.round(q * p * 100) / 100).toFixed(2)) : '';
        },
        async saveCharge() {
            const m = this.chModal;
            m.saving = true; m.errors = {};
            const r = await FF_Api.post(api + '/billing/charges/create', {
                lease_id: m.lease_id, description: m.description, item_type: m.item_type, quantity: m.quantity, unit_price: m.unit_price,
                taxable: m.taxable, recurrence: m.recurrence, bill_from: m.bill_from, bill_until: m.recurrence === 'monthly' ? m.bill_until : '', notes: m.notes,
            });
            m.saving = false;
            if (r.success) { m.open = false; FF_Toast.success('Charge added — it will be on the lease\'s next invoice.'); this.ch.state = 'pending'; this.loadCharges(); }
            else { m.errors = r.error?.fields || {}; if (!r.error?.fields) FF_Toast.error(r.error?.message || 'Could not add the charge.'); }
        },
        async cancelCharge(c) {
            const reason = await FF_Confirm.askText({ title: 'Cancel this charge', message: 'It will not be billed again. Invoices that already carry it are not changed.', confirmLabel: 'Cancel charge', placeholder: 'Why?' });
            if (reason === null) return;
            const r = await FF_Api.post(api + '/billing/charges/cancel', { id: c.id, reason });
            if (r.success) { FF_Toast.success('Charge cancelled.'); this.loadCharges(); }
            else FF_Toast.error(r.error?.message || 'Could not cancel.');
        },

        // ── customers ──
        cuVisible() {
            const q = this.cu.search.trim().toLowerCase();
            return this.cu.rows.filter(r => {
                if (this.cu.filter === 'ready' && !(r.drafts > 0 && r.leases_to_bill === 0)) return false;
                if (this.cu.filter === 'tobill' && !(r.leases_to_bill > 0)) return false;
                if (this.cu.filter === 'notemailed' && !(r.sent > r.emailed)) return false;
                return !q || r.company_name.toLowerCase().includes(q);
            });
        },
        async loadCustomers() {
            this.cu.loading = true;
            const r = await FF_Api.get(api + '/billing/cycles/customers?id=' + this.cycle.id);
            this.cu.loading = false;
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not load customers.'); return; }
            Object.assign(this.cu, { loaded: true, rows: r.data.rows });
        },
        async sendCustomer(row) {
            const msg = row.drafts
                ? 'Send ' + row.company_name + '\'s ' + row.drafts + ' draft(s) and email all ' + row.invoices + ' of their ' + this.cycle.label + ' invoices in ONE email to ' + (row.recipient || 'their email') + '? Sending makes the drafts real (balance, ledger, QuickBooks).'
                : 'Email ' + row.company_name + '\'s ' + row.invoices + ' ' + this.cycle.label + ' invoice(s) again, together in one email, to ' + (row.recipient || 'their email') + '?';
            if (row.leases_to_bill > 0 && row.drafts) {
                if (!await FF_Confirm.ask(row.leases_to_bill + ' of their leases are not billed yet — send what is ready anyway?')) return;
            }
            if (!await FF_Confirm.ask(msg)) return;
            this.cu.working = row.customer_id;
            const r = await FF_Api.post(api + '/billing/cycles/send_customer', { id: this.cycle.id, customer_id: row.customer_id, send_drafts: true, attach_pdf: this.cu.attachPdf });
            this.cu.working = null;
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not send.'); return; }
            const d = r.data;
            this.cu.result = row.company_name + ': ' + d.sent + ' sent' + (d.emailed ? ', ' + d.count + ' emailed together to ' + d.to : ', not emailed — ' + (d.email_error || '')) + (d.send_errors.length ? '. Problems: ' + d.send_errors.map(e => e.reason).join('; ') : '.');
            d.emailed ? FF_Toast.success('Sent.') : FF_Toast.error('Not emailed: ' + (d.email_error || ''));
            this.cu.loaded = false; this.dl.loaded = false; this.rv.loaded = false;
            this.loadCustomers(); this.loadOverview();
        },
        stepTab(key) {
            return { prepare: 'readiness', readings: 'readings', generate: 'leases', review: 'review', approve: 'review', send: 'delivery', close: 'close' }[key] || 'overview';
        },
        goStep(key) {
            if (key === 'approve') { window.location.href = base + '/billing#approvals'; return; }
            this.setTab(this.stepTab(key));
        },
        refreshAll() {
            this.loadOverview();
            ['ready', 'rd', 'leases', 'rv', 'dl', 'cl', 'ch', 'cu'].forEach(k => this[k].loaded = false);
            this.loadTab();
        },

        // ── formatting ──
        money(v, cur) {
            if (v === null || v === undefined || v === '') return '—';
            try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency: cur || 'CAD' }).format(Number(v)); }
            catch (e) { return Number(v).toFixed(2); }
        },
        num(v) { return v === null || v === undefined ? '—' : Number(v).toLocaleString('en-CA', { maximumFractionDigits: 2 }); },
        fmtDate(d) { return d ? FF_formatUtc(d, { hour: undefined, minute: undefined }) : '—'; },
        stateLabel(k) { return (this.coverageStates.find(s => s.key === k) || { label: k }).label; },
        stateTone(k) { return (this.coverageStates.find(s => s.key === k) || { tone: '' }).tone; },
        deliveryLabel(k) { return (this.deliveryStates.find(s => s.key === k) || { label: k }).label; },
        deliveryTone(k) { return (this.deliveryStates.find(s => s.key === k) || { tone: '' }).tone; },
        flagLabel(k) {
            return { duplicate_period: 'Double billing', double_mileage: 'Double mileage', held: 'Billed while held', swing: 'Big change',
                     zero_total: '$0 invoice', no_tax: 'No tax', usd_no_rate: 'USD, no rate', no_recipient: 'No recipient',
                     credit_line: 'Credit line', usage_true_up: 'Usage true-up', first_invoice: 'First invoice', several: 'Several invoices',
                     charges: 'Has charges' }[k] || k;
        },

        // ── overview ──
        totalLeases() { return this.ov ? Object.values(this.ov.coverage).reduce((a, b) => a + b, 0) : 0; },
        toBillCount() { return this.ov ? (this.ov.coverage.to_bill || 0) + (this.ov.coverage.void_rebillable || 0) : 0; },
        accounted() { return this.totalLeases() - this.toBillCount() - (this.ov ? (this.ov.coverage.closed_unbilled || 0) : 0); },
        async loadOverview() {
            const r = await FF_Api.get(api + '/billing/cycles/show?id=' + this.cycle.id);
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not load the cycle.'); return; }
            this.cycle = r.data.cycle;
            this.ov = { stats: r.data.stats, coverage: r.data.coverage, readings: r.data.readings, stage: r.data.stage, overdue: r.data.overdue };
        },
        nextStep() {
            if (!this.ov) return { text: '', actions: [] };
            const go = (t) => () => this.setTab(t);
            const wb = () => { window.location.href = base + '/billing/run?cycle=' + this.cycle.id; };
            switch (this.ov.stage.current) {
                case 'prepare':  return { text: this.cycle.readiness_checked_at ? 'Fix the blockers the readiness checks found, then re-run them.' : 'Start by running the readiness checks — they look at rates, readings, recipients, tax, earlier gaps and more before anything is billed.', actions: [{ label: 'Open readiness', primary: true, go: go('readiness') }] };
                case 'readings': return { text: this.ov.readings.missing + ' manual lease(s) still need a period-end reading so their usage is billed this month.', actions: [{ label: 'Enter readings', primary: true, go: go('readings') }, { label: 'Skip to generating', go: wb }] };
                case 'generate': return { text: this.toBillCount() + ' lease(s) still need an invoice for ' + this.cycle.label + '. Generate them in the workbench (dry run first), or hold the ones that should wait.', actions: cfg.canCreate && this.cycle.status === 'open' ? [{ label: 'Open workbench', primary: true, go: wb }, { label: 'See the leases', go: go('leases') }] : [{ label: 'See the leases', go: go('leases') }] };
                case 'review':   return { text: 'Look over the flagged invoices (double billing, big changes, $0) and mark each draft Reviewed — or Query it for a second look.', actions: [{ label: 'Open review', primary: true, go: go('review') }] };
                case 'approve':  return { text: 'A batch run is waiting for approval or to be generated.', actions: [{ label: 'Open approvals', primary: true, go: () => { window.location.href = base + '/billing#approvals'; } }] };
                case 'send':     return { text: this.ov.stats.drafts + ' draft(s) still need sending. Send & email them from the Delivery tab (or the workbench), in stages if there are many.', actions: [{ label: 'Open delivery', primary: true, go: go('delivery') }] };
                default:         return this.cycle.status === 'closed'
                    ? { text: 'This month is closed. The Summary tab has the frozen figures and the billing register.', actions: [{ label: 'Open summary', primary: true, go: go('close') }] }
                    : { text: 'Everything is billed and sent. Close the month to freeze its figures and lock the workbench out of it.', actions: [{ label: 'Close the month', primary: true, go: go('close') }] };
            }
        },
        async startEdit() {
            if (!this.users.length) {
                const r = await FF_Api.get(api + '/billing/settings');
                if (r.success) this.users = r.data.users;
            }
            Object.assign(this.edit, {
                open: true, errors: {},
                owner_user_id: this.cycle.owner_user_id ? String(this.cycle.owner_user_id) : '',
                bill_by_date: this.cycle.bill_by_date || '', send_by_date: this.cycle.send_by_date || '', notes: this.cycle.notes || '',
            });
        },
        async saveEdit() {
            this.edit.saving = true; this.edit.errors = {};
            const body = { id: this.cycle.id, notes: this.edit.notes };
            if (this.cycle.status === 'open') Object.assign(body, { owner_user_id: this.edit.owner_user_id || null, bill_by_date: this.edit.bill_by_date, send_by_date: this.edit.send_by_date });
            const r = await FF_Api.post(api + '/billing/cycles/update', body);
            this.edit.saving = false;
            if (r.success) { this.edit.open = false; FF_Toast.success('Saved.'); this.loadOverview(); }
            else { this.edit.errors = r.error?.fields || {}; if (!r.error?.fields) FF_Toast.error(r.error?.message || 'Could not save.'); }
        },

        // ── readiness ──
        visibleChecks() { return this.ready.checks.filter(c => this.ready.showPassed || c.count > 0); },
        async loadReadiness() {
            this.ready.loading = true;
            const r = await FF_Api.get(api + '/billing/cycles/readiness?id=' + this.cycle.id);
            this.ready.loading = false;
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not run the checks.'); return; }
            Object.assign(this.ready, { loaded: true, checks: r.data.checks, summary: r.data.summary, checked_at: r.data.checked_at });
            // Blockers open by default.
            r.data.checks.forEach(c => { if (c.count && c.severity === 'blocker' && this.ready.open[c.key] === undefined) this.ready.open[c.key] = true; });
            this.cycle.readiness_summary = r.data.summary;
            this.cycle.readiness_checked_at = r.data.checked_at;
            this.loadOverview();
        },
        itemHref(it) { return it.url && it.url.startsWith('#') ? '#' : (it.url || '#'); },
        itemClick(ev, it) {
            if (it.url && it.url.startsWith('#')) { ev.preventDefault(); this.setTab(it.url.slice(1)); }
        },
        async acknowledge(c, on) {
            let note = '';
            if (on) {
                note = await FF_Confirm.askText({ title: 'Acknowledge: ' + c.title, message: 'Bill anyway — say why (kept on the cycle).', confirmLabel: 'Acknowledge', placeholder: 'e.g. Customer confirmed PO by email' });
                if (note === null) return;
            }
            const r = await FF_Api.post(api + '/billing/cycles/acknowledge', { id: this.cycle.id, key: c.key, acknowledged: on, note });
            if (r.success) { this.loadReadiness(); } else FF_Toast.error(r.error?.message || 'Could not update.');
        },

        // ── readings ──
        rdVisible() {
            const q = this.rd.search.trim().toLowerCase();
            return this.rd.rows.filter(r => {
                if (this.rd.filter === 'needed' && (r.billed || !r.missing)) return false;
                if (this.rd.filter === 'unbilled' && r.billed) return false;
                if (this.rd.filter === 'entered' && !r.reading) return false;
                if (q && !((r.contract_number + ' ' + r.company_name + ' ' + (r.unit_number || '')).toLowerCase().includes(q))) return false;
                return true;
            });
        },
        async loadReadings() {
            this.rd.loading = true;
            const r = await FF_Api.get(api + '/billing/readings/index?cycle_id=' + this.cycle.id);
            this.rd.loading = false;
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not load readings.'); return; }
            const edit = {}, orig = {};
            r.data.rows.forEach(row => {
                const v = {
                    odometer: row.reading && row.reading.odometer_in_unit !== null ? String(Number(row.reading.odometer_in_unit)) : '',
                    engine_hours: row.reading && row.reading.engine_hours !== null ? String(Number(row.reading.engine_hours)) : '',
                    reading_date: row.reading && row.reading.reading_date ? row.reading.reading_date : '',
                    notes: row.reading && row.reading.notes ? row.reading.notes : '',
                };
                edit[row.lease_id] = { ...v }; orig[row.lease_id] = { ...v };
            });
            Object.assign(this.rd, { loaded: true, rows: r.data.rows, edit, orig, errors: {} });
            if (this.rd.filter === 'needed' && !r.data.rows.some(x => !x.billed && x.missing)) this.rd.filter = 'all';
        },
        dirtyReadings() {
            return Object.keys(this.rd.edit).filter(id => {
                const a = this.rd.edit[id], b = this.rd.orig[id];
                return a.odometer !== b.odometer || a.engine_hours !== b.engine_hours || a.reading_date !== b.reading_date || a.notes !== b.notes;
            });
        },
        async saveReadings() {
            const ids = this.dirtyReadings();
            if (!ids.length) return;
            this.rd.saving = true; this.rd.errors = {};
            const readings = ids.map(id => ({ lease_id: Number(id), ...this.rd.edit[id] }));
            const r = await FF_Api.post(api + '/billing/readings/save', { cycle_id: this.cycle.id, readings });
            this.rd.saving = false;
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not save readings.'); return; }
            const errs = {};
            (r.data.errors || []).forEach(e => { errs[e.lease_id] = e; });
            const keepEdits = {};
            Object.keys(errs).forEach(id => { keepEdits[id] = { ...this.rd.edit[id] }; });
            await this.loadReadings();
            Object.assign(this.rd.edit, keepEdits);
            this.rd.errors = errs;
            if (r.data.saved || r.data.cleared) FF_Toast.success(r.data.saved + ' reading(s) saved' + (r.data.cleared ? ', ' + r.data.cleared + ' cleared' : '') + '.');
            if (r.data.errors.length) FF_Toast.error(r.data.errors.length + ' reading(s) need fixing — see the highlighted rows.');
            this.loadOverview();
        },

        // ── leases ──
        leasesVisible() {
            const q = this.leases.search.trim().toLowerCase();
            return this.leases.rows.filter(r => (!this.leases.filter || r.status === this.leases.filter)
                && (!q || (r.contract_number + ' ' + r.company_name + ' ' + (r.unit_number || '')).toLowerCase().includes(q)));
        },
        async loadLeases() {
            this.leases.loading = true;
            const r = await FF_Api.get(api + '/billing/cycles/coverage?id=' + this.cycle.id);
            this.leases.loading = false;
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not load leases.'); return; }
            Object.assign(this.leases, { loaded: true, rows: r.data.rows, counts: r.data.counts });
        },

        // ── review ──
        rvVisible() {
            const q = this.rv.search.trim().toLowerCase();
            const f = this.rv.filter;
            return this.rv.rows.filter(r => {
                if (f === 'flagged' && !(r.worst === 'danger' || r.worst === 'warning')) return false;
                if (f === 'unreviewed' && r.review) return false;
                if (f === 'query' && !(r.review && r.review.status === 'query')) return false;
                if (f.startsWith('flag:') && !r.flags.some(x => x.key === f.slice(5))) return false;
                if (q && !((r.invoice_number + ' ' + r.company_name + ' ' + r.contract_number).toLowerCase().includes(q))) return false;
                return true;
            });
        },
        rvSelected() { return Object.keys(this.rv.selected).filter(k => this.rv.selected[k]).map(Number); },
        rvToggleAll(on) { const s = {}; if (on) this.rvVisible().forEach(r => { s[r.invoice_id] = true; }); this.rv.selected = s; },
        async loadReview() {
            this.rv.loading = true;
            const r = await FF_Api.get(api + '/billing/cycles/review?id=' + this.cycle.id);
            this.rv.loading = false;
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not load the review.'); return; }
            Object.assign(this.rv, { loaded: true, rows: r.data.rows, missing: r.data.missing, flag_counts: r.data.flag_counts });
            if (this.rv.filter === 'flagged' && !r.data.rows.some(x => x.worst === 'danger' || x.worst === 'warning')) this.rv.filter = 'unreviewed';
        },
        async markReview(status, ids) {
            ids = ids || this.rvSelected();
            if (!ids.length) return;
            let note = null;
            if (status === 'query') {
                note = await FF_Confirm.askText({ title: 'Query ' + ids.length + ' invoice(s)', message: 'What needs checking? The cycle cannot close while an invoice is queried.', confirmLabel: 'Query', placeholder: 'e.g. Mileage looks double — check the odometer' });
                if (!note) return;
            }
            this.rv.working = true;
            const r = await FF_Api.post(api + '/billing/cycles/review_mark', { id: this.cycle.id, invoice_ids: ids, status, note });
            this.rv.working = false;
            if (r.success) { this.rv.selected = {}; await this.loadReview(); this.loadOverview(); }
            else FF_Toast.error(r.error?.message || 'Could not update.');
        },
        markOne(r, status) { this.markReview(status, [r.invoice_id]); },

        // ── delivery ──
        dlVisible() {
            const q = this.dl.search.trim().toLowerCase();
            return this.dl.rows.filter(r => (!this.dl.filter || r.state === this.dl.filter)
                && (!q || (r.invoice_number + ' ' + r.company_name + ' ' + (r.recipient || '')).toLowerCase().includes(q)));
        },
        dlSelected() { return this.dl.rows.filter(r => this.dl.selected[r.id]); },
        dlToggleAll(on) { const s = {}; if (on) this.dlVisible().forEach(r => { s[r.id] = true; }); this.dl.selected = s; },
        async loadDelivery() {
            this.dl.loading = true;
            const r = await FF_Api.get(api + '/billing/cycles/delivery?id=' + this.cycle.id);
            this.dl.loading = false;
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not load delivery.'); return; }
            Object.assign(this.dl, { loaded: true, rows: r.data.rows, counts: r.data.counts });
        },
        overridesFor(ids) {
            const o = {};
            ids.forEach(id => { const v = (this.dl.overrides[id] || '').trim(); if (v) o[id] = v; });
            return o;
        },
        async inChunks(ids, fn) {
            const out = [];
            for (let i = 0; i < ids.length; i += 100) out.push(await fn(ids.slice(i, i + 100)));
            return out;
        },
        async sendDrafts(withEmail) {
            const ids = this.dlSelected().filter(r => r.state === 'draft').map(r => r.id);
            if (!ids.length) return;
            const ok = await FF_Confirm.ask((withEmail ? 'Send and email ' : 'Mark as sent (no email) ') + ids.length + ' draft invoice(s)? Sending posts their revenue, adds them to each customer\'s balance and queues QuickBooks. This cannot be undone except by voiding.');
            if (!ok) return;
            this.dl.working = true;
            const res = await this.inChunks(ids, chunk => FF_Api.post(api + '/invoices/bulk_send', { ids: chunk, send_email: withEmail, attach_pdf: this.dl.attachPdf, email_overrides: this.overridesFor(chunk) }));
            this.dl.working = false;
            let sent = 0, emailed = 0; const errors = [];
            res.forEach(r => {
                if (!r.success) { errors.push(r.error?.message || 'A batch failed.'); return; }
                sent += r.data.actioned; emailed += r.data.emailed || 0;
                (r.data.errors || []).forEach(e => errors.push('#' + e.id + ': ' + e.reason));
                (r.data.email_errors || []).forEach(e => errors.push('#' + e.id + ' email: ' + e.reason));
            });
            this.dl.result = { text: sent + ' sent' + (withEmail ? ', ' + emailed + ' emailed' : '') + (errors.length ? ', ' + errors.length + ' problem(s):' : '.'), errors };
            this.dl.selected = {};
            this.refreshAll();
        },
        async emailAgain() {
            const ids = this.dlSelected().filter(r => r.state !== 'draft').map(r => r.id);
            if (!ids.length) return;
            const ok = await FF_Confirm.ask('Email ' + ids.length + ' already-sent invoice(s) to their customers' + (this.dl.attachPdf ? ' with the PDF' : '') + '? Nothing else changes.');
            if (!ok) return;
            this.dl.working = true;
            const res = await this.inChunks(ids, chunk => FF_Api.post(api + '/billing/deliver', { ids: chunk, attach_pdf: this.dl.attachPdf, email_overrides: this.overridesFor(chunk) }));
            this.dl.working = false;
            let emailed = 0; const errors = [];
            res.forEach(r => {
                if (!r.success) { errors.push(r.error?.message || 'A batch failed.'); return; }
                emailed += r.data.emailed;
                (r.data.errors || []).forEach(e => errors.push(e.reason));
            });
            this.dl.result = { text: emailed + ' emailed' + (errors.length ? ', ' + errors.length + ' problem(s):' : '.'), errors };
            this.dl.selected = {};
            this.dl.loaded = false; this.loadDelivery(); this.loadOverview();
        },

        async fixDoubleMileage(r) {
            if (!await FF_Confirm.ask('Remove the Mileage overage line from ' + r.invoice_number + '? The Mileage usage line (odometer-exact) stays and the totals are recomputed.')) return;
            const res = await FF_Api.post(api + '/billing/fixes/double_mileage', { invoice_id: r.invoice_id });
            if (res.success) { FF_Toast.success('Fixed: ' + this.money(res.data.old_total) + ' → ' + this.money(res.data.new_total)); this.rv.loaded = false; this.loadReview(); this.loadOverview(); }
            else FF_Toast.error(res.error?.message || 'Could not fix.');
        },
        async regenerateSelected() {
            const rows = this.rv.rows.filter(r => this.rv.selected[r.invoice_id]);
            const drafts = rows.filter(r => r.status === 'draft');
            if (!drafts.length) { FF_Toast.error('Only drafts can be regenerated — none selected.'); return; }
            if (!await FF_Confirm.ask('Regenerate ' + drafts.length + ' draft(s) from their lease\'s current data? Each keeps its invoice number; totals may change. Precharge and advance-billed drafts are refused.')) return;
            this.rv.working = true;
            let ok = 0; const errors = [];
            for (const r of drafts) {
                const res = await FF_Api.post(api + '/invoices/regenerate', { id: r.invoice_id }, { quiet: true });
                if (res.success) ok++; else errors.push(r.invoice_number + ': ' + (res.error?.message || 'failed'));
            }
            this.rv.working = false;
            this.rv.result = { text: ok + ' regenerated' + (errors.length ? ', ' + errors.length + ' not:' : '.'), errors };
            this.rv.selected = {};
            this.refreshAll();
        },
        async voidSelected() {
            const ids = this.rvSelected();
            if (!ids.length) return;
            const reason = await FF_Confirm.askText({ title: 'Void ' + ids.length + ' invoice(s)', message: 'Voiding reverses a sent invoice\'s balance and revenue and makes the month billable again. Paid or partly paid invoices are refused. Reason (required):', confirmLabel: 'Void', placeholder: 'e.g. Billed at the wrong rate' });
            if (!reason) return;
            this.rv.working = true;
            const res = await this.inChunks(ids, chunk => FF_Api.post(api + '/invoices/bulk_void', { ids: chunk, void_reason: reason }));
            this.rv.working = false;
            let n = 0; const errors = [];
            res.forEach(r => { if (!r.success) { errors.push(r.error?.message || 'failed'); return; } n += (r.data.actioned || 0); (r.data.errors || []).forEach(e => errors.push('#' + e.id + ': ' + e.reason)); });
            this.rv.result = { text: n + ' voided' + (errors.length ? ', ' + errors.length + ' not:' : '.'), errors };
            this.rv.selected = {};
            this.refreshAll();
        },
        async markDelivered(method) {
            const ids = this.dlSelected().filter(r => r.state !== 'draft').map(r => r.id);
            if (!ids.length) return;
            const note = await FF_Confirm.askText({ title: method === 'manual' ? 'Mark as mailed / handed over' : 'Mark as on the portal', message: ids.length + ' invoice(s). Add a note (optional).', confirmLabel: 'Mark', placeholder: method === 'manual' ? 'e.g. Posted Sep 30' : '' });
            if (note === null) return;
            this.dl.working = true;
            const r = await FF_Api.post(api + '/billing/mark_delivered', { ids, method, note });
            this.dl.working = false;
            if (r.success) { FF_Toast.success(r.data.updated + ' marked.'); this.dl.selected = {}; this.dl.loaded = false; this.loadDelivery(); }
            else FF_Toast.error(r.error?.message || 'Could not mark.');
        },
        async downloadPdfs(format) {
            const ids = this.dlSelected().map(r => r.id);
            if (!ids.length) return;
            if (ids.length > 100) { FF_Toast.error('At most 100 at a time — select fewer.'); return; }
            this.dl.working = true;
            try {
                const res = await fetch(api + '/invoices/batch_download', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ ids, format }),
                });
                const ctype = res.headers.get('content-type') || '';
                if (!res.ok || ctype.includes('application/json')) {
                    let m = 'Download failed.'; try { const j = await res.json(); m = j.error?.message || m; } catch (e) {}
                    FF_Toast.error(m); return;
                }
                const url = URL.createObjectURL(await res.blob());
                const a = document.createElement('a');
                a.href = url; a.download = 'invoices-' + this.cycle.month + (format === 'zip' ? '.zip' : '.pdf');
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 60000);
            } catch (e) { FF_Toast.error('Network error during download.'); }
            finally { this.dl.working = false; }
        },

        // ── close ──
        figures() {
            if (!this.cl.data) return {};
            return (this.cl.view === 'snapshot' && this.cl.data.snapshot) ? this.cl.data.snapshot : this.cl.data.live;
        },
        catPct(c) {
            const cats = this.figures().categories || [];
            const max = Math.max(1, ...cats.map(x => Math.abs(Number(x.amount_cad))));
            return Math.round(Math.abs(Number(c.amount_cad)) / max * 100);
        },
        async loadClose() {
            const r = await FF_Api.get(api + '/billing/cycles/summary?id=' + this.cycle.id);
            if (!r.success) { FF_Toast.error(r.error?.message || 'Could not load the summary.'); return; }
            this.cl.data = r.data; this.cl.loaded = true;
            this.cl.view = r.data.snapshot ? 'snapshot' : 'live';
        },
        async closeCycle() {
            const ok = await FF_Confirm.ask('Close ' + this.cycle.label + '? Its figures are frozen and the workbench can no longer bill it until it is reopened.');
            if (!ok) return;
            this.cl.working = true;
            const r = await FF_Api.post(api + '/billing/cycles/close', { id: this.cycle.id, note: this.cl.note, override: this.cl.override });
            this.cl.working = false;
            if (r.success) { FF_Toast.success(this.cycle.label + ' closed.'); setTimeout(() => location.reload(), 700); }
            else { FF_Toast.error(r.error?.message || 'Could not close.'); this.cl.loaded = false; this.loadClose(); }
        },
        async reopen() {
            const reason = await FF_Confirm.askText({ title: 'Reopen ' + this.cycle.label, message: 'The workbench will be able to bill this month again. The close figures are kept until it is closed again. Say why.', confirmLabel: 'Reopen', placeholder: 'e.g. Two leases were missed' });
            if (!reason) return;
            const r = await FF_Api.post(api + '/billing/cycles/reopen', { id: this.cycle.id, reason });
            if (r.success) { FF_Toast.success('Reopened.'); setTimeout(() => location.reload(), 700); }
            else FF_Toast.error(r.error?.message || 'Could not reopen.');
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
