<?php declare(strict_types=1);

/**
 * app/admin/accounting/impairment/show.php
 *
 * Single ASPE 3063 impairment test detail page. Renders the full
 * test record: asset detail, fiscal year + triggering event, step 1
 * CF breakdown (the JSON estimator inputs/outputs), step 2 fair value
 * + computed impairment loss + JE link, operator notes.
 *
 * @session S-ACCT-LESSOR-6
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
$loss   = $test['step_2_impairment_loss'] !== null ? (float) $test['step_2_impairment_loss'] : 0.0;

$pageTitle = "Impairment Test #{$testId} — {$test['asset_number']}";
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/impairment') ?>">Impairment Tests</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Test #<?= (int) $testId ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Impairment Test #<?= (int) $testId ?> — <?= e($test['asset_number']) ?></h1>
    <p class="page-header-subtitle">
        <?= e($test['asset_name']) ?> · FY <?= (int) $test['fiscal_year'] ?> ·
        <span class="badge badge-neutral"><?= e($test['triggering_event']) ?></span>
    </p>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;">

    <!-- Step 1 + Step 2 detail -->
    <div>
        <div class="card" style="margin-bottom:14px;">
            <div class="card-header"><div class="card-title">Step 1 — Recoverability Test</div></div>
            <div class="card-body">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <div style="font-size:1.1rem;">
                        Carrying $<?= number_format((float) $test['step_1_carrying_amount'], 2) ?>
                        &nbsp;<strong>vs</strong>&nbsp;
                        Undiscounted CF $<?= number_format((float) $test['step_1_undiscounted_cf'], 2) ?>
                    </div>
                    <span class="badge <?= $passed ? 'badge-success' : 'badge-warning' ?>" style="font-size:1rem;padding:6px 14px;">
                        <?= $passed ? 'PASSED' : 'FAILED' ?>
                    </span>
                </div>
                <div style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:10px;">
                    CF source: <strong><?= e($test['step_1_cf_source']) ?></strong>
                </div>

                <?php if (!empty($breakdown)): ?>
                <div style="font-weight:600;margin:14px 0 8px;">CF Estimator Breakdown</div>
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
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$passed): ?>
        <div class="card">
            <div class="card-header"><div class="card-title">Step 2 — Measurement</div></div>
            <div class="card-body">
                <?php if ($test['step_2_fair_value'] === null): ?>
                    <div class="alert alert-warning" style="padding:10px 14px;">
                        Step 1 failed — operator fair_value pending. Re-run the test with a
                        <code>fair_value</code> parameter to complete step 2 and post the
                        impairment JE.
                    </div>
                <?php else: ?>
                    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;font-size:0.95rem;">
                        <div><strong>Fair Value:</strong> $<?= number_format((float) $test['step_2_fair_value'], 2) ?></div>
                        <div><strong>Impairment Loss:</strong>
                            <span style="color:<?= $loss > 0 ? 'var(--danger)' : 'inherit' ?>;">
                                $<?= number_format($loss, 2) ?>
                            </span>
                        </div>
                        <div style="grid-column:span 2;"><strong>Fair-value basis:</strong>
                            <?= e($test['step_2_fair_value_basis'] ?? '—') ?>
                        </div>
                    </div>
                    <?php if ($test['impairment_je_id']): ?>
                        <div style="margin-top:14px;">
                            <strong>Impairment JE:</strong>
                            <a href="<?= base_url('accounting/journal-entries/show') ?>?id=<?= (int) $test['impairment_je_id'] ?>"><?= e($test['je_entry_number'] ?? ('JE #' . $test['impairment_je_id'])) ?></a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($test['notes']) || !empty($test['triggering_event_notes'])): ?>
        <div class="card" style="margin-top:14px;">
            <div class="card-header"><div class="card-title">Notes</div></div>
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
    </div>

    <!-- Right column: asset detail -->
    <div>
        <div class="card">
            <div class="card-header"><div class="card-title">Asset</div></div>
            <div class="card-body" style="font-size:0.875rem;">
                <div><strong>Asset #:</strong> <a href="<?= base_url('accounting/fixed-assets/show') ?>?id=<?= (int) $test['asset_id'] ?>"><?= e($test['asset_number']) ?></a></div>
                <div><strong>Name:</strong> <?= e($test['asset_name']) ?></div>
                <div><strong>Class:</strong> <?= e($test['asset_class'] ?? '—') ?></div>
                <div style="margin-top:8px;border-top:1px solid var(--border-default);padding-top:8px;">
                    <div><strong>Acquisition Cost:</strong> $<?= number_format((float) $test['acquisition_cost'], 2) ?></div>
                    <div><strong>Accum Depr:</strong> $<?= number_format((float) $test['accumulated_depreciation'], 2) ?></div>
                    <div><strong>Current NBV:</strong> $<?= number_format((float) $test['current_nbv'], 2) ?></div>
                </div>
                <div style="margin-top:8px;border-top:1px solid var(--border-default);padding-top:8px;color:var(--text-secondary);font-size:0.8rem;">
                    Useful life: <?= e($test['useful_life_years']) ?> yrs ·
                    Salvage: $<?= number_format((float) $test['salvage_value'], 2) ?><br>
                    Depr start: <?= e($test['depreciation_start_date'] ?? '—') ?>
                </div>
                <div style="margin-top:14px;color:var(--text-secondary);font-size:0.8rem;">
                    Tested by <?= e($test['tester_name'] ?? '—') ?>
                    on <?= e($test['tested_at']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
