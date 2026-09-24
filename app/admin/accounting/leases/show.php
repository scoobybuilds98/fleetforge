<?php declare(strict_types=1);

/**
 * app/admin/accounting/leases/show.php
 *
 * Capital lease detail page + amortization schedule viewer.
 * Only meaningful for classification IN ('sales_type','direct_financing').
 * Operating leases are managed on the main app/admin/leases/show.php page.
 *
 * Affordances:
 *   - Lease basics + classification badge + implicit rate + initial NI +
 *     term + status.
 *   - Schedule summary: total finance income, total principal,
 *     initial / final NI, posted-vs-scheduled period counts.
 *   - Schedule table:
 *       * If no schedule yet → "Preview Schedule" button (GET preview)
 *         → renders the projected table inline + "Generate & Save"
 *           (POST generate) button.
 *       * If schedule exists → render directly with status badges +
 *         JE drill-down placeholder (LESSOR-3 wires JE ids).
 *   - "Regenerate Schedule" (super_admin only, blocked when any row is
 *     posted) → confirm modal → POST generate.php?regenerate=1.
 *
 * Layout (S-RECORD-REDESIGN):
 *   The Alpine component (capitalLeaseShow) opens ABOVE the header so the
 *   schedule actions (Preview / Generate & Save / Re-preview / Regenerate)
 *   live in the header.
 *   header  — ModuleHero entity: contract # + classification + status
 *             badges; customer · unit · term · payment chips
 *   nav     — the accounting sub-nav, directly under the header
 *   strip   — monthly payment · initial net investment · implicit rate ·
 *             periods posted · net investment now (from the saved schedule)
 *   main    — flash banner · Schedule summary · NI current vs long-term ·
 *             Schedule table / empty state · Lease details · regen modal
 *   rail    — Needs attention (no schedule / regenerate blocked / behind on
 *             posting) · Customer + unit · Classification facts · Related
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/partials/accounting-nav.php, lib/Ui/RecordUi.php,
 *           api/v1/accounting/leases/amortization/{generate,preview,show}.php,
 *           api/v1/accounting/leases/ni-reclass-preview.php
 * @session  S-ACCT-LESSOR-2, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$leaseId = clean_positive_int($_GET['id'] ?? null);
if ($leaseId === null) {
    header('Location: ' . base_url('accounting/leases'));
    exit;
}

// WHY signer.name: users has no `full_name` column — `signer.full_name` threw
// SQLSTATE 42S22 and 500'd this page for EVERY capital lease (found while
// verifying S-RECORD-REDESIGN).
$lease = db_row(
    "SELECT l.id, l.contract_number, l.classification, l.status,
            l.customer_id, l.equipment_unit_id,
            l.start_date, l.end_date, l.monthly_rate, l.implicit_rate,
            l.initial_fair_value, l.initial_direct_costs,
            l.guaranteed_residual_value, l.unguaranteed_residual_value,
            l.bargain_purchase_option_amount, l.bargain_purchase_option_date,
            l.classification_signed_off_at,
            c.company_name, c.contact_name,
            u.unit_number,
            eb.label AS brand, t.model,
            alc.criterion_b_lease_term_months AS term_months,
            signer.name AS signoff_name
       FROM leases l
       LEFT JOIN customers           c      ON c.id     = l.customer_id          AND c.deleted_at IS NULL
       LEFT JOIN equipment_units     u      ON u.id     = l.equipment_unit_id    AND u.deleted_at IS NULL
       LEFT JOIN equipment_templates t      ON t.id     = u.template_id          AND t.deleted_at IS NULL
       LEFT JOIN equipment_brands    eb     ON eb.id    = u.brand_id
       LEFT JOIN acc_lease_classifications alc ON alc.lease_id = l.id
       LEFT JOIN users               signer ON signer.id = l.classification_signed_off_by AND signer.deleted_at IS NULL
      WHERE l.id = ? AND l.deleted_at IS NULL
      LIMIT 1",
    [$leaseId]
);

if (!$lease) {
    header('Location: ' . base_url('accounting/leases'));
    exit;
}

// Operating leases don't have a schedule — bounce to the operational
// lease show page where the regular workflow lives.
if ($lease['classification'] === 'operating') {
    header('Location: ' . base_url('leases/show') . '?id=' . (int) $lease['id']);
    exit;
}

$existing = db_select(
    "SELECT id, period_number, period_date, status, closing_net_investment
       FROM acc_lease_amortization_schedules
      WHERE lease_id = ? ORDER BY period_number ASC",
    [$leaseId]
);
$hasSchedule = !empty($existing);
$postedCount = 0;
foreach ($existing as $r) if ($r['status'] === 'posted') $postedCount++;
$canRegenerate = $hasSchedule && $postedCount === 0 && is_super_admin();

// ── Strip numbers from the saved schedule (S-RECORD-REDESIGN) ──────────────
// Net investment now = the closing NI of the last posted period (else the
// initial fair value). "Due to post" = scheduled periods dated on/before
// today that are not posted yet — the schedule is behind.
$today     = ff_today();
$niNow     = (string) ($lease['initial_fair_value'] ?? '0');
$duePosts  = 0;
$nextRow   = null;
foreach ($existing as $r) {
    if ($r['status'] === 'posted') {
        $niNow = (string) $r['closing_net_investment'];
    } elseif ($r['status'] === 'scheduled') {
        if ($r['period_date'] <= $today) {
            $duePosts++;
        }
        if ($nextRow === null) {
            $nextRow = $r;
        }
    }
}
$periodCount = count($existing);
$postedPct   = $periodCount > 0 ? round($postedCount / $periodCount * 100, 1) : 0.0;

$classBadge = $lease['classification'] === 'sales_type' ? 'badge-warning' : 'badge-info';
$classLabel = $lease['classification'] === 'sales_type' ? 'Sales-Type' : 'Direct Financing';
$annualRatePct = $lease['implicit_rate'] !== null
    ? number_format((float) $lease['implicit_rate'] * 100, 4) . '%'
    : '—';
$fairVal = $lease['initial_fair_value'] !== null
    ? format_currency($lease['initial_fair_value'])
    : '—';
$unitDisp = trim(($lease['unit_number'] ?? '') . ' — ' . trim(($lease['brand'] ?? '') . ' ' . ($lease['model'] ?? '')));

$pageTitle = 'Capital Lease — ' . $lease['contract_number'];
require_once FF_ROOT . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$heroTitle = e($lease['contract_number'])
    . ' <span class="badge ' . $classBadge . '">' . $classLabel . '</span>'
    . ' <span class="badge badge-neutral">' . e($lease['status']) . '</span>';
$heroFacts = [];
if (!empty($lease['company_name'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('user-group') . e($lease['company_name']);
}
if (!empty($lease['unit_number'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('truck') . 'Unit ' . e($lease['unit_number']);
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . e(format_date($lease['start_date'])) . ' → ' . ($lease['end_date'] ? e(format_date($lease['end_date'])) : 'open-ended');
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('currency-dollar') . '<b>' . e(format_currency($lease['monthly_rate'])) . '</b> / month';
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('accounting/leases') ?>">All capital leases</a>
    <?php if (can('leases', 'view')): ?>
    <a href="<?= base_url('leases/show') ?>?id=<?= (int) $lease['id'] ?>">Operational lease record</a>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <template x-if="!hasSchedule && !schedule.preview">
        <button @click="loadPreview()" :disabled="busy" class="btn btn-primary btn-sm">
            <span x-show="!busy">Preview Schedule</span>
            <span x-show="busy">Computing…</span>
        </button>
    </template>
    <template x-if="schedule.preview && !schedule.persisted">
        <span class="ff-contents">
            <button @click="generateSchedule(false)" :disabled="busy" class="btn btn-primary btn-sm">
                <span x-show="!busy">Generate &amp; Save</span>
                <span x-show="busy">Saving…</span>
            </button>
            <button @click="loadPreview()" :disabled="busy" class="btn btn-secondary btn-sm">Re-preview</button>
        </span>
    </template>
    <?php if ($canRegenerate): ?>
    <template x-if="hasSchedule">
        <button @click="confirmRegenerate()" :disabled="busy" class="btn btn-warning btn-sm">
            Regenerate Schedule
        </button>
    </template>
    <?php endif; ?>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>

<!-- The component opens ABOVE the header so the schedule actions live there. -->
<div x-data="capitalLeaseShow(<?= (int) $leaseId ?>, <?= $hasSchedule ? 'true' : 'false' ?>)">
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'success',
    'icon'       => 'calendar-days',
    'mark'       => (string) $lease['contract_number'],
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['Capital Leases', base_url('accounting/leases')], [(string) $lease['contract_number'], null]],
    'eyebrow'    => 'Capital lease · ASPE 3065',
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN) — from the saved schedule. -->
<div class="stat-grid stat-grid--5 ff-stats">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Monthly payment</div>
        <div class="stat-value font-mono"><?= e(format_currency($lease['monthly_rate'])) ?></div>
        <div class="stat-delta"><?= $lease['term_months'] ? (int) $lease['term_months'] . ' mo' : '' ?></div>
    </div>
    <div class="stat-card stat-card--purple">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-key"/></svg></span>
        <div class="stat-label">Initial net investment</div>
        <div class="stat-value font-mono"><?= e($fairVal) ?></div>
    </div>
    <div class="stat-card stat-card--teal">
        <span class="stat-icon stat-icon--teal"><svg><use href="#icon-arrow-trending-up"/></svg></span>
        <div class="stat-label">Implicit rate</div>
        <div class="stat-value font-mono"><?= e($annualRatePct) ?></div>
        <div class="stat-delta">annual</div>
    </div>
    <div class="stat-card <?= $duePosts > 0 ? 'stat-card--red' : 'stat-card--green' ?>">
        <span class="stat-icon <?= $duePosts > 0 ? 'stat-icon--red' : 'stat-icon--green' ?>"><svg><use href="#icon-<?= $duePosts > 0 ? 'exclamation-triangle' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Periods posted</div>
        <div class="stat-value font-mono"><?= $hasSchedule ? $postedCount . ' / ' . $periodCount : '—' ?></div>
        <div class="stat-delta"><?= !$hasSchedule ? 'no schedule' : ($duePosts > 0 ? $duePosts . ' due' : 'on track') ?></div>
    </div>
    <div class="stat-card stat-card--slate">
        <span class="stat-icon stat-icon--slate"><svg><use href="#icon-shield-check"/></svg></span>
        <div class="stat-label">Net investment now</div>
        <div class="stat-value font-mono"><?= $hasSchedule ? e(format_currency($niNow)) : '—' ?></div>
    </div>
</div>

<div x-show="banner" x-cloak :class="bannerClass" role="status" style="margin-bottom:14px;" x-text="banner"></div>

<div class="rec-layout">
<div class="rec-main">

    <!-- ── Schedule summary ────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Schedule summary</h3></div>
        <div class="card-body">
            <template x-if="!schedule.persisted && !schedule.preview">
                <p class="text-secondary" style="margin:0;font-size:0.9rem;">
                    No schedule yet. Click <strong>Preview Schedule</strong> above to compute the
                    effective-interest amortization without writing.
                </p>
            </template>
            <template x-if="schedule.persisted || schedule.preview">
                <dl class="rec-dl">
                    <dt>Initial net investment</dt>
                    <dd class="font-mono" x-text="'$' + fmt(schedule.summary.initial_ni)"></dd>
                    <dt>Final closing NI</dt>
                    <dd class="font-mono" x-text="'$' + fmt(schedule.summary.final_closing_ni)"></dd>
                    <dt>Total finance income</dt>
                    <dd class="font-mono" x-text="'$' + fmt(schedule.summary.total_finance_income)"></dd>
                    <dt>Total principal</dt>
                    <dd class="font-mono" x-text="'$' + fmt(schedule.summary.total_principal)"></dd>
                    <dt>Periods</dt>
                    <dd><span x-text="schedule.summary.period_count"></span> (<span x-text="schedule.summary.posted_count"></span> posted)<span x-show="schedule.preview && !schedule.persisted" class="badge badge-warning" style="margin-left:6px;">preview — not saved</span></dd>
                    <dt>Implicit rate (annual)</dt>
                    <dd class="font-mono" x-text="schedule.annual_rate ? (Number(schedule.annual_rate) * 100).toFixed(4) + '%' : '—'"></dd>
                </dl>
            </template>
        </div>
    </div>

    <!-- ── NI Current vs Long-Term Breakdown (S-ACCT-LESSOR-5) ── -->
    <div class="card" x-show="niBreakdown" x-cloak>
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
            <h3 class="card-title">NI current vs long-term (ASPE 3065.54)</h3>
            <button class="btn btn-ghost btn-sm" @click="loadNiBreakdown()" :disabled="niBusy">
                <span x-show="!niBusy">Refresh Preview</span>
                <span x-show="niBusy">Loading…</span>
            </button>
        </div>
        <div class="card-body">
            <template x-if="niBreakdown">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;font-size:0.9rem;">
                    <div>
                        <div style="font-weight:600;margin-bottom:4px;color:var(--text-secondary);">On-books now (GL trail)</div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>NI Current (1090):</span>
                            <strong class="font-mono">$<span x-text="fmt(niBreakdown.currentBalance1090)"></span></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>NI Long-Term (1600):</span>
                            <strong class="font-mono">$<span x-text="fmt(niBreakdown.currentBalance1600)"></span></strong>
                        </div>
                    </div>
                    <div>
                        <div style="font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Target after next reclass (next 12 mo split)</div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>Target 1090:</span>
                            <strong class="font-mono">$<span x-text="fmt(niBreakdown.target1090)"></span></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>Target 1600:</span>
                            <strong class="font-mono">$<span x-text="fmt(niBreakdown.target1600)"></span></strong>
                        </div>
                    </div>
                </div>
            </template>
            <template x-if="niBreakdown && niBreakdown.reclass_needed">
                <div class="alert alert-info" style="margin-top:10px;padding:8px 12px;font-size:0.875rem;">
                    Next reclass: <strong>$<span x-text="fmt(niBreakdown.delta1090)"></span></strong>
                    will shift between current and long-term on the 1st of next month
                    (cron <code>accounting_lease_ni_reclass.php</code>).
                </div>
            </template>
            <template x-if="niBreakdown && !niBreakdown.reclass_needed">
                <div class="alert alert-success" style="margin-top:10px;padding:8px 12px;font-size:0.875rem;">
                    No reclass needed — on-books NI matches target split.
                </div>
            </template>
            <template x-if="niBreakdown && Number(niBreakdown.integrity_drift) > 0.02">
                <div class="alert alert-warning" style="margin-top:10px;padding:8px 12px;font-size:0.875rem;">
                    ⚠ Integrity drift: $<span x-text="fmt(niBreakdown.integrity_drift)"></span>.
                    Schedule projection vs on-books NI out of sync. Investigate per
                    D-LESSOR-4-PERIOD-PRINCIPAL-DERIVATION + residual impairments.
                </div>
            </template>
        </div>
    </div>

    <!-- ── Schedule table ─────────────────────────────────────── -->
    <div class="card" x-show="schedule.preview || schedule.persisted">
        <div class="card-header"><h3 class="card-title">Amortization schedule <span x-show="schedule.preview && !schedule.persisted" class="badge badge-warning">preview</span></h3></div>
        <div class="card-body" style="padding:0;">
        <div style="overflow-x:auto;">
        <table class="table" style="width:100%;font-size:0.85rem;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th style="text-align:right;">Opening NI</th>
                    <th style="text-align:right;">Payment</th>
                    <th style="text-align:right;">Finance Income</th>
                    <th style="text-align:right;">Principal</th>
                    <th style="text-align:right;">Closing NI</th>
                    <th>Status</th>
                    <th>JE #</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="row in schedule.periods" :key="row.period_number">
                    <tr>
                        <td x-text="row.period_number"></td>
                        <td x-text="row.period_date"></td>
                        <td class="font-mono" style="text-align:right;" x-text="'$' + fmt(row.opening_net_investment)"></td>
                        <td class="font-mono" style="text-align:right;" x-text="'$' + fmt(row.cash_receipt)"></td>
                        <td class="font-mono" style="text-align:right;" x-text="'$' + fmt(row.finance_income)"></td>
                        <td class="font-mono" style="text-align:right;" x-text="'$' + fmt(row.principal_reduction)"></td>
                        <td class="font-mono" style="text-align:right;" x-text="'$' + fmt(row.closing_net_investment)"></td>
                        <td>
                            <span class="badge"
                                  :class="(row.status||'scheduled') === 'posted' ? 'badge-success'
                                       : (row.status||'scheduled') === 'reversed' ? 'badge-danger' : 'badge-neutral'"
                                  x-text="row.status || 'preview'"></span>
                        </td>
                        <td>
                            <template x-if="row.posted_je_id">
                                <a class="link" :href="'<?= base_url('accounting/journal-entries/show') ?>?id=' + row.posted_je_id"
                                   x-text="'JE #' + row.posted_je_id"></a>
                            </template>
                            <template x-if="!row.posted_je_id">
                                <span style="color:var(--text-secondary);">—</span>
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
            <tfoot x-show="schedule.summary">
                <tr style="font-weight:600;">
                    <td colspan="4" style="text-align:right;">Totals:</td>
                    <td class="font-mono" style="text-align:right;" x-text="'$' + fmt(schedule.summary && schedule.summary.total_finance_income)"></td>
                    <td class="font-mono" style="text-align:right;" x-text="'$' + fmt(schedule.summary && schedule.summary.total_principal)"></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
        </div>
        </div>
    </div>

    <!-- ── Empty state when no schedule yet AND no preview loaded ─ -->
    <div class="card" x-show="!schedule.preview && !schedule.persisted">
        <div class="card-body" style="padding:1.5rem;text-align:center;">
            <p class="text-secondary" style="margin:0;">
                This lease has been classified as <strong><?= $classLabel ?></strong> but no amortization
                schedule has been built yet. The schedule auto-generates on activation; you can also
                preview it now to validate the inputs before activating.
            </p>
        </div>
    </div>

    <!-- ── Lease details ───────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Lease details</h3></div>
        <div class="card-body">
            <dl class="rec-dl">
                <dt>Customer</dt>
                <dd><?= e($lease['company_name'] ?? '—') ?></dd>
                <dt>Unit</dt>
                <dd><?= e($unitDisp) ?></dd>
                <dt>Start date</dt>
                <dd class="font-mono"><?= e(format_date($lease['start_date'])) ?></dd>
                <dt>End date</dt>
                <dd class="font-mono"><?= e(format_date($lease['end_date'] ?? null)) ?></dd>
                <dt>Monthly payment</dt>
                <dd class="font-mono"><?= e(format_currency($lease['monthly_rate'])) ?></dd>
                <dt>Term (wizard)</dt>
                <dd><?= e($lease['term_months'] ?? '—') ?> months</dd>
                <dt>Initial fair value</dt>
                <dd class="font-mono"><?= e($fairVal) ?></dd>
                <?php if ($lease['initial_direct_costs'] !== null && bccomp((string) $lease['initial_direct_costs'], '0', 2) > 0): ?>
                <dt>Initial direct costs</dt>
                <dd class="font-mono"><?= e(format_currency($lease['initial_direct_costs'])) ?></dd>
                <?php endif; ?>
                <dt>Implicit rate (annual)</dt>
                <dd class="font-mono"><?= e($annualRatePct) ?></dd>
                <dt>Guaranteed residual</dt>
                <dd class="font-mono"><?= e(format_currency($lease['guaranteed_residual_value'] ?? '0')) ?></dd>
                <dt>Unguaranteed residual</dt>
                <dd class="font-mono"><?= e(format_currency($lease['unguaranteed_residual_value'] ?? '0')) ?></dd>
                <?php if ($lease['bargain_purchase_option_amount']): ?>
                <dt>BPO amount</dt>
                <dd class="font-mono"><?= e(format_currency($lease['bargain_purchase_option_amount'])) ?></dd>
                <dt>BPO date</dt>
                <dd class="font-mono"><?= e(format_date($lease['bargain_purchase_option_date'] ?? null)) ?></dd>
                <?php endif; ?>
                <dt>Status</dt>
                <dd><span class="badge badge-neutral"><?= e($lease['status']) ?></span></dd>
                <dt>Classified by</dt>
                <dd><?= e($lease['signoff_name'] ?? '—') ?><?php if (!empty($lease['classification_signed_off_at'])): ?> <span class="text-secondary">· <?= e(format_datetime($lease['classification_signed_off_at'])) ?></span><?php endif; ?></dd>
            </dl>
        </div>
    </div>

    <!-- ── Regenerate confirm modal ───────────────────────────── -->
    <div class="modal-overlay" x-show="showRegenModal" x-cloak
         style="background:rgba(0,0,0,0.55);"
         @click.self="showRegenModal = false">
        <div class="card" style="max-width:480px;padding:24px;">
            <div class="card-title" style="margin-bottom:10px;">Regenerate Schedule?</div>
            <p style="font-size:0.9rem;">
                This deletes the existing <strong x-text="schedule.summary?.period_count"></strong>
                scheduled rows and rebuilds from the lease's current rate and term inputs.
                Posted rows are not present (regenerate is blocked when any row is posted).
                This action is logged in <code>audit_log</code>.
            </p>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
                <button class="btn btn-ghost" @click="showRegenModal = false">Cancel</button>
                <button class="btn btn-warning" @click="generateSchedule(true)" :disabled="busy">
                    <span x-show="!busy">Regenerate</span>
                    <span x-show="busy">Working…</span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the capital lease at a glance ────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if (!$hasSchedule) {
    $alerts[] = ['warning', 'No amortization schedule saved — preview it, then <b>Generate &amp; Save</b>.'];
}
if ($duePosts > 0) {
    $alerts[] = ['danger', $duePosts . ' scheduled period' . ($duePosts === 1 ? ' is' : 's are') . ' dated on or before today but not posted.'];
}
if ($hasSchedule && $postedCount > 0) {
    $alerts[] = ['info', $postedCount . ' period' . ($postedCount === 1 ? '' : 's') . ' posted — regenerate is blocked.'];
}
if ($lease['implicit_rate'] === null) {
    $alerts[] = ['warning', 'No implicit rate on the lease — the schedule cannot be computed.'];
}
$rail[] = $R::card('Needs attention', $R::alerts($alerts, 'All clear — the schedule is on track.'), ['icon' => 'exclamation-triangle']);

// Progress through the schedule.
if ($hasSchedule) {
    $rail[] = $R::card('Schedule progress',
        $R::meter('Posted', $postedCount . ' of ' . $periodCount, (float) $postedPct, $duePosts > 0 ? 'danger' : 'info',
            $nextRow ? 'Next: period ' . (int) $nextRow['period_number'] . ' · ' . e(format_date($nextRow['period_date'])) : 'All periods posted')
        . $R::kv([['Net investment now', e(format_currency($niNow)), 'mono']]),
        ['icon' => 'chart-bar', 'class' => 'rec-card--accent']);
}

// Parties.
$partyBody = '';
if (!empty($lease['company_name'])) {
    $partyBody .= $R::entity((string) $lease['company_name'], can('customers', 'view') && !empty($lease['customer_id']) ? base_url('customers/show') . '?id=' . (int) $lease['customer_id'] : '',
        !empty($lease['contact_name']) ? e($lease['contact_name']) : 'Lessee', \FleetForge\Ui\ModuleHero::initials((string) $lease['company_name']));
}
if (!empty($lease['unit_number'])) {
    $partyBody .= ($partyBody !== '' ? '<div style="height:10px;"></div>' : '')
        . $R::entity('Unit ' . $lease['unit_number'], can('equipment', 'view') && !empty($lease['equipment_unit_id']) ? base_url('equipment/show') . '?id=' . (int) $lease['equipment_unit_id'] : '',
            e(trim(($lease['brand'] ?? '') . ' ' . ($lease['model'] ?? ''))) ?: 'Leased unit', '', 'truck');
}
if ($partyBody !== '') {
    $rail[] = $R::card('Lessee & unit', $partyBody, ['icon' => 'user-group']);
}

$rail[] = $R::card('Classification', $R::kv([
    ['Type', '<span class="badge ' . $classBadge . '">' . $classLabel . '</span>'],
    ['Term', $lease['term_months'] ? e((string) $lease['term_months']) . ' months' : null],
    ['Guaranteed residual', e(format_currency($lease['guaranteed_residual_value'] ?? '0')), 'mono'],
    ['Unguaranteed residual', e(format_currency($lease['unguaranteed_residual_value'] ?? '0')), 'mono'],
    ['Classified by', !empty($lease['signoff_name']) ? e($lease['signoff_name']) : null],
]), ['icon' => 'scale']);

$rel = [['All capital leases', base_url('accounting/leases'), 'list-bullet']];
if (can('leases', 'view')) {
    $rel[] = ['Operational lease record', base_url('leases/show') . '?id=' . (int) $lease['id'], 'calendar-days', (string) $lease['contract_number']];
}
$rail[] = $R::card('Related', $R::links($rel), ['icon' => 'document-duplicate']);
?>
<aside class="rec-rail" aria-label="Capital lease at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->
</div><!-- /x-data capitalLeaseShow -->

<script>
function capitalLeaseShow(leaseId, hasSchedule) {
    return {
        leaseId,
        hasSchedule,
        busy: false,
        showRegenModal: false,
        banner: '',
        bannerClass: 'alert alert-success',
        schedule: { preview: false, persisted: false, periods: [], summary: null, annual_rate: null },
        niBreakdown: null,
        niBusy: false,

        async init() {
            if (this.hasSchedule) await this.loadExisting();
            // Auto-load NI breakdown on page open — cheap (per-lease GL trail + 2 schedule sums)
            await this.loadNiBreakdown();
        },

        async loadNiBreakdown() {
            this.niBusy = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/leases/ni-reclass-preview') ?>?lease_id=' + this.leaseId);
                if (r.success && r.data.leases && r.data.leases[0]) {
                    this.niBreakdown = r.data.leases[0];
                }
            } catch (e) { /* non-fatal */ }
            this.niBusy = false;
        },

        fmt(v) {
            if (v === null || v === undefined) return '0.00';
            const n = Number(v);
            return n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        async loadExisting() {
            this.busy = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/leases/amortization/show') ?>?lease_id=' + this.leaseId);
                if (r.success) {
                    this.schedule = { ...r.data, preview: false, persisted: r.data.persisted };
                }
            } catch (e) {
                this.flash('Network error loading schedule.', 'danger');
            }
            this.busy = false;
        },

        async loadPreview() {
            this.busy = true;
            this.banner = '';
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/leases/amortization/preview') ?>?lease_id=' + this.leaseId);
                if (r.success) {
                    this.schedule = { ...r.data, preview: true, persisted: false };
                    this.flash('Preview ready. Click Generate & Save to commit.', 'info');
                } else {
                    this.flash(r.error?.message || 'Preview failed.', 'danger');
                }
            } catch (e) {
                this.flash('Network error.', 'danger');
            }
            this.busy = false;
        },

        async generateSchedule(regenerate) {
            this.busy = true;
            this.showRegenModal = false;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/leases/amortization/generate') ?>',
                    { lease_id: this.leaseId, regenerate });
                if (r.success) {
                    this.schedule = { ...r.data, preview: false, persisted: true };
                    this.hasSchedule = true;
                    this.flash(regenerate ? 'Schedule regenerated.' : 'Schedule generated and saved.', 'success');
                } else {
                    this.flash(r.error?.message || 'Generation failed.', 'danger');
                }
            } catch (e) {
                this.flash('Network error.', 'danger');
            }
            this.busy = false;
        },

        confirmRegenerate() {
            this.showRegenModal = true;
        },

        flash(msg, level) {
            this.banner = msg;
            this.bannerClass = 'alert alert-' + (level === 'success' ? 'success'
                : level === 'danger' ? 'danger'
                : level === 'info' ? 'info' : 'neutral');
            setTimeout(() => { this.banner = ''; }, 5000);
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
