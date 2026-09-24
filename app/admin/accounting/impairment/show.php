<?php declare(strict_types=1);

/**
 * app/admin/accounting/impairment/show.php
 *
 * Single ASPE 3063 impairment test detail page. Renders the full
 * test record: asset detail, fiscal year + triggering event, step 1
 * CF breakdown (the JSON estimator inputs/outputs), step 2 fair value
 * + computed impairment loss + JE link, operator notes.
 *
 * Layout (S-RECORD-REDESIGN):
 *   header  — ModuleHero entity: "Impairment test #N" + PASSED / FAILED
 *             badge; asset · FY · trigger · tested chips; "Open asset"
 *   nav     — the accounting sub-nav, directly under the header
 *   strip   — carrying amount · undiscounted cash flows · headroom
 *             (CF − carrying) · fair value · impairment loss
 *   main    — Step 1 recoverability (+ estimator breakdown) · Step 2
 *             measurement · Notes
 *   rail    — Needs attention (fair value pending / loss posted / passed) ·
 *             Asset (entity + cost / depreciation / NBV) · Test facts ·
 *             Related links
 *
 * @session S-ACCT-LESSOR-6, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$testId = clean_positive_int($_GET['test_id'] ?? null);
if ($testId === null) {
    header('Location: ' . base_url('accounting/impairment'));
    exit;
}

$test = \FleetForge\Accounting\ImpairmentTestService::getTest($testId);
if (!$test) {
    header('Location: ' . base_url('accounting/impairment'));
    exit;
}

$breakdown = [];
if (!empty($test['step_1_cf_breakdown_json'])) {
    $breakdown = json_decode($test['step_1_cf_breakdown_json'], true) ?: [];
}

$passed = (int) $test['step_1_passed'];
// Money in bcmath (S-RECORD-REDESIGN): headroom = undiscounted CF − carrying.
$lossStr   = $test['step_2_impairment_loss'] !== null ? (string) $test['step_2_impairment_loss'] : '0.00';
$hasLoss   = bccomp($lossStr, '0', 2) > 0;
$headroom  = bcsub((string) $test['step_1_undiscounted_cf'], (string) $test['step_1_carrying_amount'], 2);
$fvPending = !$passed && $test['step_2_fair_value'] === null;

$pageTitle = "Impairment Test #{$testId} — {$test['asset_number']}";
require_once FF_ROOT . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$heroTitle = 'Impairment test #' . (int) $testId
    . ' <span class="badge ' . ($passed ? 'badge-success' : 'badge-warning') . '">' . ($passed ? 'PASSED' : 'FAILED') . '</span>';
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('cube') . '<a href="' . e(base_url('accounting/fixed-assets/show')) . '?id=' . (int) $test['asset_id'] . '">' . e($test['asset_number']) . '</a> · ' . e($test['asset_name']),
    \FleetForge\Sop\SopIcons::svg('calendar-days') . 'FY <b>' . (int) $test['fiscal_year'] . '</b>',
    \FleetForge\Sop\SopIcons::svg('exclamation-triangle') . e(ucfirst(str_replace('_', ' ', (string) $test['triggering_event']))),
];
if (!empty($test['tested_at'])) {
    // tested_at is a UTC stamp (S-UTC-STAMPS) — the company-local day.
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . 'Tested ' . e(format_datetime($test['tested_at'], 'M j, Y')) . (!empty($test['tester_name']) ? ' · ' . e($test['tester_name']) : '');
}
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('accounting/impairment') ?>?fiscal_year=<?= (int) $test['fiscal_year'] ?>">FY <?= (int) $test['fiscal_year'] ?> tests</a>
    <?php if ($test['impairment_je_id']): ?>
    <a href="<?= base_url('accounting/journal-entries/show') ?>?id=<?= (int) $test['impairment_je_id'] ?>">Impairment JE <?= e($test['je_entry_number'] ?? '') ?></a>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/fixed-assets/show') ?>?id=<?= (int) $test['asset_id'] ?>"><?= \FleetForge\Sop\SopIcons::svg('cube') ?> Open asset</a>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => $passed ? 'success' : ($hasLoss ? 'danger' : 'warning'),
    'icon'       => 'scale',
    'mark'       => 'FY ' . (int) $test['fiscal_year'],
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['Impairment Tests', base_url('accounting/impairment')], ['Test #' . (int) $testId, null]],
    'eyebrow'    => 'Impairment test · ASPE 3063',
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN): the two sides of the recoverability
     test, the headroom between them, and — when step 1 failed — the fair
     value and the loss it produced. -->
<div class="stat-grid stat-grid--5 ff-stats">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Carrying amount</div>
        <div class="stat-value font-mono"><?= e(format_currency($test['step_1_carrying_amount'])) ?></div>
    </div>
    <div class="stat-card stat-card--purple">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-arrow-trending-up"/></svg></span>
        <div class="stat-label">Undiscounted CF</div>
        <div class="stat-value font-mono"><?= e(format_currency($test['step_1_undiscounted_cf'])) ?></div>
    </div>
    <?php $neg = bccomp($headroom, '0', 2) < 0; ?>
    <div class="stat-card <?= $neg ? 'stat-card--red' : 'stat-card--green' ?>">
        <span class="stat-icon <?= $neg ? 'stat-icon--red' : 'stat-icon--green' ?>"><svg><use href="#icon-<?= $neg ? 'exclamation-triangle' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Headroom</div>
        <div class="stat-value font-mono"<?= $neg ? ' style="color:var(--color-danger);"' : '' ?>><?= e(format_currency($headroom)) ?></div>
    </div>
    <div class="stat-card stat-card--teal">
        <span class="stat-icon stat-icon--teal"><svg><use href="#icon-tag"/></svg></span>
        <div class="stat-label">Fair value</div>
        <div class="stat-value font-mono"><?= $test['step_2_fair_value'] !== null ? e(format_currency($test['step_2_fair_value'])) : '—' ?></div>
        <?php if ($fvPending): ?><div class="stat-delta">pending</div><?php endif; ?>
    </div>
    <div class="stat-card <?= $hasLoss ? 'stat-card--red' : 'stat-card--slate' ?>">
        <span class="stat-icon <?= $hasLoss ? 'stat-icon--red' : 'stat-icon--slate' ?>"><svg><use href="#icon-fire"/></svg></span>
        <div class="stat-label">Impairment loss</div>
        <div class="stat-value font-mono"<?= $hasLoss ? ' style="color:var(--color-danger);"' : '' ?>><?= e(format_currency($lossStr)) ?></div>
        <div class="stat-delta"><?= $test['impairment_je_id'] ? 'posted' : '' ?></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main">

        <div class="card">
            <div class="card-header"><h3 class="card-title">Step 1 — Recoverability test</h3></div>
            <div class="card-body">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                    <div style="font-size:1.05rem;">
                        Carrying <span class="font-mono"><?= e(format_currency($test['step_1_carrying_amount'])) ?></span>
                        &nbsp;<strong>vs</strong>&nbsp;
                        Undiscounted CF <span class="font-mono"><?= e(format_currency($test['step_1_undiscounted_cf'])) ?></span>
                    </div>
                    <span class="badge <?= $passed ? 'badge-success' : 'badge-warning' ?>" style="font-size:1rem;padding:6px 14px;">
                        <?= $passed ? 'PASSED' : 'FAILED' ?>
                    </span>
                </div>
                <div style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:10px;">
                    CF source: <strong><?= e($test['step_1_cf_source']) ?></strong>
                </div>

                <?php if (!empty($breakdown)): ?>
                <div style="font-weight:600;margin:14px 0 8px;">CF estimator breakdown</div>
                <div style="overflow-x:auto;">
                <table class="table" style="width:100%;font-size:0.85rem;">
                    <tbody>
                    <?php foreach ($breakdown as $key => $val): ?>
                        <tr>
                            <td style="width:50%;color:var(--text-secondary);"><?= e($key) ?></td>
                            <td><?php
                                if (is_bool($val))      echo $val ? '✅ true' : '— false';
                                elseif (is_array($val)) echo '<code>' . e(json_encode($val)) . '</code>';
                                else                    echo e((string) $val);
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$passed): ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Step 2 — Measurement</h3></div>
            <div class="card-body">
                <?php if ($test['step_2_fair_value'] === null): ?>
                    <div class="alert alert-warning" style="padding:10px 14px;margin:0;">
                        Step 1 failed — operator fair_value pending. Re-run the test with a
                        <code>fair_value</code> parameter to complete step 2 and post the
                        impairment JE.
                    </div>
                <?php else: ?>
                    <dl class="rec-dl">
                        <dt>Fair value</dt>
                        <dd class="font-mono"><?= e(format_currency($test['step_2_fair_value'])) ?></dd>
                        <dt>Impairment loss</dt>
                        <dd class="font-mono"<?= $hasLoss ? ' style="color:var(--color-danger);font-weight:700;"' : '' ?>><?= e(format_currency($lossStr)) ?></dd>
                        <dt>Fair-value basis</dt>
                        <dd><?= e($test['step_2_fair_value_basis'] ?? '—') ?></dd>
                        <?php if ($test['impairment_je_id']): ?>
                        <dt>Impairment JE</dt>
                        <dd><a class="link font-mono" href="<?= base_url('accounting/journal-entries/show') ?>?id=<?= (int) $test['impairment_je_id'] ?>"><?= e($test['je_entry_number'] ?? ('JE #' . $test['impairment_je_id'])) ?></a></dd>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($test['notes']) || !empty($test['triggering_event_notes'])): ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Notes</h3></div>
            <div class="card-body">
                <?php if (!empty($test['triggering_event_notes'])): ?>
                    <div><strong>Triggering event:</strong> <?= nl2br(e($test['triggering_event_notes'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($test['notes'])): ?>
                    <div style="margin-top:8px;"><?= nl2br(e($test['notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the test at a glance ─────────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if ($fvPending) {
    $alerts[] = ['warning', 'Step 1 failed — a <b>fair value</b> is needed to finish step 2 and post the loss. Re-run the test from <a href="' . e(base_url('accounting/impairment')) . '?fiscal_year=' . (int) $test['fiscal_year'] . '">Impairment Tests</a>.'];
}
if (!$passed && $hasLoss && !$test['impairment_je_id']) {
    $alerts[] = ['danger', 'A loss of ' . e(format_currency($lossStr)) . ' is measured but no impairment JE is linked.'];
}
if ($hasLoss && $test['impairment_je_id']) {
    $alerts[] = ['info', 'Loss of ' . e(format_currency($lossStr)) . ' posted in <a href="' . e(base_url('accounting/journal-entries/show')) . '?id=' . (int) $test['impairment_je_id'] . '">' . e($test['je_entry_number'] ?? 'the impairment JE') . '</a>.'];
}
$rail[] = $R::card('Needs attention', $R::alerts($alerts, $passed ? 'Passed — the asset is recoverable; no loss.' : 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// Asset.
$rail[] = $R::card('Asset',
    $R::entity((string) $test['asset_number'], base_url('accounting/fixed-assets/show') . '?id=' . (int) $test['asset_id'], e($test['asset_name']) . (!empty($test['asset_class']) ? ' · ' . e(str_replace('_', ' ', (string) $test['asset_class'])) : ''), '', 'cube')
    . '<div style="margin-top:10px;">' . $R::kv([
        ['Acquisition cost', e(format_currency($test['acquisition_cost'])), 'mono'],
        ['Accum. depreciation', e(format_currency($test['accumulated_depreciation'])), 'mono'],
        ['Current NBV', e(format_currency($test['current_nbv'])), 'mono'],
        ['Useful life', $test['useful_life_years'] !== null ? e((string) (float) $test['useful_life_years']) . ' yrs' : null],
        ['Salvage', e(format_currency($test['salvage_value'])), 'mono'],
        ['Depr. start', !empty($test['depreciation_start_date']) ? e(format_date($test['depreciation_start_date'])) : null],
    ]) . '</div>',
    ['icon' => 'cube', 'class' => 'rec-card--accent']);

$rail[] = $R::card('Test', $R::kv([
    ['Fiscal year', (string) (int) $test['fiscal_year']],
    ['Trigger', e(ucfirst(str_replace('_', ' ', (string) $test['triggering_event'])))],
    ['CF source', e((string) $test['step_1_cf_source'])],
    ['Tested by', e($test['tester_name'] ?? '—')],
    ['Tested at', !empty($test['tested_at']) ? e(format_datetime($test['tested_at'])) : null],
]), ['icon' => 'scale']);

$rel = [['FY ' . (int) $test['fiscal_year'] . ' impairment tests', base_url('accounting/impairment') . '?fiscal_year=' . (int) $test['fiscal_year'], 'list-bullet']];
if ($test['impairment_je_id']) {
    $rel[] = ['Impairment JE', base_url('accounting/journal-entries/show') . '?id=' . (int) $test['impairment_je_id'], 'book-open', (string) ($test['je_entry_number'] ?? '')];
}
$rail[] = $R::card('Related', $R::links($rel), ['icon' => 'document-duplicate']);
?>
<aside class="rec-rail" aria-label="Impairment test at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
