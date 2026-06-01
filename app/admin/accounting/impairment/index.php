<?php declare(strict_types=1);

/**
 * app/admin/accounting/impairment/index.php
 *
 * ASPE 3063 Fleet Impairment Two-Step Test workflow page. Fiscal-year
 * picker; "Run Annual Preview" runs step 1 across all active fleet
 * assets and surfaces the failing rows with an inline FV input. A
 * second "Run Step 2 / Post Impairments" submission posts the
 * impairment JEs for the rows the operator filled in.
 *
 * Below: history table of all tests for the selected fiscal year with
 * drill-down links to the per-test detail page.
 *
 * @session S-ACCT-LESSOR-6
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$fiscalYear = (int) ($_GET['fiscal_year'] ?? date('Y'));
if ($fiscalYear < 2000 || $fiscalYear > 2100) $fiscalYear = (int) date('Y');

$history = \FleetForge\Accounting\ImpairmentTestService::listForYear($fiscalYear);

$pageTitle = "Impairment Tests — FY {$fiscalYear}";
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/fixed-assets') ?>">Fixed Assets</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Impairment Tests — FY <?= (int) $fiscalYear ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Impairment Tests — FY <?= (int) $fiscalYear ?></h1>
    <p class="page-header-subtitle">
        ASPE 3063 fleet impairment two-step test. Step 1: carrying amount vs
        undiscounted future cash flows. Step 2: fair value (operator-input)
        when step 1 fails. <strong>Impairments are NEVER reversed per ASPE.</strong>
    </p>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<?php // F17: QBO sync hint — impairment JEs enqueue for QBO push on posting.
$qboFaNote = 'impairment';
require FF_ROOT . '/includes/partials/qbo-fa-sync-note.php'; ?>

<div x-data="impairmentWorkflow(<?= (int) $fiscalYear ?>)" x-init="init()">

    <!-- Fiscal year + run controls -->
    <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:end;flex-wrap:wrap;">
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Fiscal Year</label>
            <input type="number" min="2000" max="2100" x-model.number="year"
                   @change="window.location.href = '?fiscal_year=' + year"
                   style="width:120px;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
        </div>
        <button @click="runPreview()" :disabled="busy" class="btn btn-primary" style="height:36px;">
            <span x-show="!busy">Run Annual Preview (Step 1)</span>
            <span x-show="busy">Running…</span>
        </button>
        <template x-if="preview && pendingCount > 0">
            <button @click="postStep2()" :disabled="busy" class="btn btn-warning" style="height:36px;">
                <span x-show="!busy">Post Impairment JEs (<span x-text="pendingCount"></span>)</span>
                <span x-show="busy">Posting…</span>
            </button>
        </template>
        <div x-show="banner" :class="bannerClass" style="padding:6px 12px;border-radius:6px;font-size:0.875rem;" x-text="banner"></div>
    </div>

    <!-- Summary cards (after preview runs) -->
    <template x-if="preview">
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:16px;">
            <div class="card" style="padding:14px;">
                <div style="font-size:0.75rem;color:var(--text-secondary);">Total Fleet Assets</div>
                <div style="font-size:1.5rem;font-weight:600;" x-text="preview.total"></div>
            </div>
            <div class="card" style="padding:14px;background:var(--success-bg);">
                <div style="font-size:0.75rem;color:var(--text-secondary);">Step 1 Passed</div>
                <div style="font-size:1.5rem;font-weight:600;color:var(--success);" x-text="preview.step_1_passed"></div>
            </div>
            <div class="card" style="padding:14px;background:var(--warning-bg);">
                <div style="font-size:0.75rem;color:var(--text-secondary);">Pending FV Input</div>
                <div style="font-size:1.5rem;font-weight:600;color:var(--warning);" x-text="preview.pending_fair_value"></div>
            </div>
            <div class="card" style="padding:14px;background:var(--danger-bg);">
                <div style="font-size:0.75rem;color:var(--text-secondary);">Impairments Posted</div>
                <div style="font-size:1.5rem;font-weight:600;color:var(--danger);" x-text="preview.impairment_posted"></div>
            </div>
            <div class="card" style="padding:14px;">
                <div style="font-size:0.75rem;color:var(--text-secondary);">Errors</div>
                <div style="font-size:1.5rem;font-weight:600;" x-text="preview.errors"></div>
            </div>
        </div>
    </template>

    <!-- Per-asset preview table with inline FV input -->
    <template x-if="preview && preview.tests && preview.tests.length">
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header"><div class="card-title">Preview Results</div></div>
            <table class="table" style="width:100%;font-size:0.85rem;">
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th style="text-align:right;">Carrying Amount</th>
                        <th style="text-align:right;">Undiscounted CF</th>
                        <th>CF Source</th>
                        <th>Status</th>
                        <th style="text-align:right;">Fair Value $</th>
                        <th>Basis</th>
                    </tr>
                </thead>
                <tbody>
                <template x-for="t in preview.tests" :key="t.test_id || ('err-' + t.asset_id)">
                    <tr :style="rowStyle(t)">
                        <td>
                            <template x-if="!t.error">
                                <a :href="'<?= base_url('accounting/impairment/show') ?>?test_id=' + t.test_id"
                                   x-text="'#' + t.asset_id"></a>
                            </template>
                            <template x-if="t.error">
                                <span x-text="t.asset_number + ' (err)'"></span>
                            </template>
                        </td>
                        <td style="text-align:right;" x-text="t.carrying_amount ? '$' + fmt(t.carrying_amount) : '—'"></td>
                        <td style="text-align:right;" x-text="t.undiscounted_cf ? '$' + fmt(t.undiscounted_cf) : '—'"></td>
                        <td><span class="badge badge-neutral" x-text="t.cf_source || '—'"></span></td>
                        <td>
                            <span class="badge"
                                  :class="statusBadge(t.status)"
                                  x-text="statusLabel(t)"></span>
                        </td>
                        <td style="text-align:right;">
                            <template x-if="t.status === 'step_1_failed_pending_fv'">
                                <input type="number" min="0" step="0.01"
                                       x-model="fvInputs[t.asset_id]"
                                       style="width:120px;text-align:right;padding:4px 8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);">
                            </template>
                            <template x-if="t.status !== 'step_1_failed_pending_fv'">
                                <span x-text="t.fair_value ? '$' + fmt(t.fair_value) : '—'"></span>
                            </template>
                        </td>
                        <td>
                            <template x-if="t.status === 'step_1_failed_pending_fv'">
                                <input type="text"
                                       x-model="fvBases[t.asset_id]"
                                       placeholder="Appraisal / NRV / etc."
                                       style="width:160px;padding:4px 8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);">
                            </template>
                        </td>
                    </tr>
                </template>
                </tbody>
            </table>
        </div>
    </template>

    <!-- History table -->
    <div class="card">
        <div class="card-header"><div class="card-title">Test History — FY <?= (int) $fiscalYear ?></div></div>
        <table class="table" style="width:100%;font-size:0.85rem;">
            <thead>
                <tr>
                    <th>Tested</th>
                    <th>Asset</th>
                    <th>Event</th>
                    <th style="text-align:right;">Carrying</th>
                    <th style="text-align:right;">Undiscounted CF</th>
                    <th>Step 1</th>
                    <th style="text-align:right;">Fair Value</th>
                    <th style="text-align:right;">Loss</th>
                    <th>JE</th>
                    <th>Tester</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($history)): ?>
                <tr><td colspan="11" style="text-align:center;color:var(--text-secondary);padding:1.5rem;">No tests recorded for this fiscal year.</td></tr>
            <?php endif; ?>
            <?php foreach ($history as $h): ?>
                <?php
                    $passed = (int) $h['step_1_passed'];
                    $loss   = $h['step_2_impairment_loss'] !== null ? (float) $h['step_2_impairment_loss'] : 0.0;
                ?>
                <tr>
                    <td><?= e($h['tested_at']) ?></td>
                    <td><a href="<?= base_url('accounting/fixed-assets/show') ?>?id=<?= (int) $h['asset_id'] ?>"><?= e($h['asset_number'] ?? ('#' . $h['asset_id'])) ?></a></td>
                    <td><span class="badge badge-neutral"><?= e($h['triggering_event']) ?></span></td>
                    <td style="text-align:right;">$<?= number_format((float) $h['step_1_carrying_amount'], 2) ?></td>
                    <td style="text-align:right;">$<?= number_format((float) $h['step_1_undiscounted_cf'], 2) ?></td>
                    <td>
                        <span class="badge <?= $passed ? 'badge-success' : 'badge-warning' ?>">
                            <?= $passed ? 'PASSED' : 'FAILED' ?>
                        </span>
                    </td>
                    <td style="text-align:right;"><?= $h['step_2_fair_value'] !== null ? '$' . number_format((float) $h['step_2_fair_value'], 2) : '—' ?></td>
                    <td style="text-align:right;color:<?= $loss > 0 ? 'var(--danger)' : 'inherit' ?>;">
                        <?= $loss > 0 ? '$' . number_format($loss, 2) : '—' ?>
                    </td>
                    <td>
                        <?php if ($h['impairment_je_id']): ?>
                            <a href="<?= base_url('accounting/journal-entries/show') ?>?id=<?= (int) $h['impairment_je_id'] ?>"><?= e($h['je_entry_number'] ?? ('JE #' . $h['impairment_je_id'])) ?></a>
                        <?php else: ?>
                            <span style="color:var(--text-secondary);">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($h['tester_name'] ?? '—') ?></td>
                    <td><a href="<?= base_url('accounting/impairment/show') ?>?test_id=<?= (int) $h['id'] ?>">Detail</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function impairmentWorkflow(initialYear) {
    return {
        year: initialYear,
        busy: false,
        banner: '',
        bannerClass: 'alert alert-info',
        preview: null,
        fvInputs: {},
        fvBases:  {},

        async init() { /* history rendered server-side */ },

        get pendingCount() {
            if (!this.preview || !this.preview.tests) return 0;
            return this.preview.tests.filter(t => t.status === 'step_1_failed_pending_fv').length;
        },

        fmt(v) {
            if (v === null || v === undefined) return '0.00';
            return Number(v).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        statusBadge(s) {
            if (s === 'passed' || s === 'fv_recovery')          return 'badge-success';
            if (s === 'step_1_failed_pending_fv')               return 'badge-warning';
            if (s === 'impairment_posted')                      return 'badge-danger';
            return 'badge-neutral';
        },
        statusLabel(t) {
            if (t.error) return 'ERROR';
            return ({
                passed:                    'Passed',
                step_1_failed_pending_fv:  'Pending FV',
                impairment_posted:         'Impaired',
                fv_recovery:               'FV Recovery',
            })[t.status] || t.status || '—';
        },
        rowStyle(t) {
            if (t.error)                                    return 'background:var(--danger-bg);';
            if (t.status === 'step_1_failed_pending_fv')    return 'background:var(--warning-bg);';
            if (t.status === 'impairment_posted')           return 'background:var(--danger-bg);';
            return '';
        },

        async runPreview() {
            this.busy = true;
            this.banner = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/impairment/run-annual') ?>',
                    { fiscal_year: this.year });
                if (r.success) {
                    this.preview = r.data;
                    this.flash(`Preview complete — ${r.data.total} assets tested, ${r.data.pending_fair_value} pending FV.`, 'info');
                } else {
                    this.flash(r.error?.message || 'Preview failed.', 'danger');
                }
            } catch (e) {
                this.flash('Network error.', 'danger');
            }
            this.busy = false;
        },

        async postStep2() {
            this.busy = true;
            this.banner = '';
            const fairValueInputs = {};
            for (const [assetId, fv] of Object.entries(this.fvInputs)) {
                if (fv === '' || fv === null || fv === undefined) continue;
                fairValueInputs[assetId] = {
                    fair_value: String(fv),
                    basis: this.fvBases[assetId] || '',
                };
            }
            if (Object.keys(fairValueInputs).length === 0) {
                this.flash('No fair-value inputs provided.', 'danger');
                this.busy = false;
                return;
            }
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/impairment/run-annual') ?>',
                    { fiscal_year: this.year, fair_value_inputs: fairValueInputs });
                if (r.success) {
                    this.preview = r.data;
                    this.flash(`Step 2 complete — ${r.data.impairment_posted} impairment JE(s) posted.`, 'success');
                    setTimeout(() => window.location.reload(), 2500);
                } else {
                    this.flash(r.error?.message || 'Posting failed.', 'danger');
                }
            } catch (e) {
                this.flash('Network error.', 'danger');
            }
            this.busy = false;
        },

        flash(msg, level) {
            this.banner = msg;
            this.bannerClass = 'alert alert-' + (level === 'success' ? 'success'
                : level === 'danger' ? 'danger' : 'info');
            setTimeout(() => { this.banner = ''; }, 5000);
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
