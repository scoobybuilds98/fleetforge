<?php declare(strict_types=1);

/**
 * app/admin/accounting/cca/index.php
 *
 * CCA Schedule 8 admin page per spec §23.3. Practitioner-facing UI for
 * computing, locking, drilling into, and exporting the T2 Schedule 8
 * continuity for any fiscal year with assets assigned to CCA classes.
 *
 * AIIP is computed as $0.00 in CCA-1 (placeholder). Full AIIP rules
 * + 2024 FES reinstatement are CCA-2 scope; banner discloses this on
 * every page load.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/footer.php, includes/partials/accounting-nav.php,
 *           api/v1/accounting/cca/{compute,show,classes,lock,export}.php
 * @session  S-ACCT-CCA-1
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

// Default fiscal year: most recent acc_periods year with posted JEs, or
// CURDATE() year otherwise.
$defaultYear = (int) (db_row(
    "SELECT p.year FROM acc_periods p
      WHERE EXISTS (SELECT 1 FROM acc_journal_entries je
                    WHERE je.period_id = p.id AND je.status = 'posted')
      ORDER BY p.year DESC LIMIT 1"
)['year'] ?? (int) date('Y'));

// Distinct years available in acc_periods for the year picker.
$availableYears = db_select(
    "SELECT DISTINCT year FROM acc_periods ORDER BY year DESC"
);

$pageTitle = 'CCA Schedule 8';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">CCA Schedule 8</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">CCA Schedule 8 (T2)</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="ccaSchedule()" x-init="fiscalYear = <?= (int) $defaultYear ?>; load()">

    <!-- AIIP placeholder banner — always visible per CCA-1 scope. -->
    <div class="banner-amber" style="background:#fff7d6;border:1px solid #b8860b;color:#6b4900;padding:10px 14px;margin-bottom:16px;font-size:0.8125rem;border-radius:6px;">
        <strong>⚠ AIIP placeholder:</strong>
        AIIP adjustment is $0.00 in this Phase C-3 release. Full AIIP phase-out
        rules and the proposed 2024 FES reinstatement are implemented in
        S-ACCT-CCA-2 (Phase C-4).
    </div>

    <!-- Controls -->
    <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:end;flex-wrap:wrap;">
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Fiscal Year</label>
            <select x-model.number="fiscalYear" class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <?php foreach ($availableYears as $y): ?>
                    <option value="<?= (int) $y['year'] ?>"><?= (int) $y['year'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label style="display:flex;align-items:center;gap:6px;font-size:0.8125rem;color:var(--text-secondary);">
            <input type="checkbox" x-model="recompute"
                   :disabled="schedule && schedule.rows && schedule.rows.some(r => r.is_locked == 1)">
            Recompute (delete existing rows)
        </label>
        <button @click="compute()" :disabled="loading" class="btn btn-primary" style="height:36px;">
            <span x-show="!loading">Compute</span>
            <span x-show="loading">Computing...</span>
        </button>
        <button @click="lockYear()" :disabled="loading || !schedule || !schedule.rows.length || allLocked()" class="btn btn-secondary" style="height:36px;">
            Lock Schedule
        </button>
        <button @click="downloadCsv()" :disabled="loading || !schedule" class="btn btn-secondary" style="height:36px;">
            Export CSV
        </button>
        <button @click="downloadPdf()" :disabled="loading || !schedule" class="btn btn-secondary" style="height:36px;">
            Export PDF
        </button>
        <template x-if="allLocked()">
            <span class="badge badge-warning" style="padding:6px 12px;font-size:0.75rem;">
                Locked — unlock requires super_admin
            </span>
        </template>
    </div>

    <!-- Error -->
    <template x-if="error">
        <div class="card" style="padding:18px;color:var(--color-danger);" x-text="error"></div>
    </template>

    <!-- Empty state -->
    <template x-if="!loading && !error && schedule && (!schedule.rows || schedule.rows.length === 0)">
        <div class="card" style="padding:36px;text-align:center;color:var(--text-secondary);">
            No CCA continuity rows for FY <span x-text="fiscalYear"></span>.
            <br>
            <span style="font-size:0.8125rem;">Click <strong>Compute</strong> to run the engine. If you have assets but they're excluded, check that each asset has a CCA class assigned on the Fixed Assets edit page.</span>
        </div>
    </template>

    <!-- Schedule table -->
    <template x-if="!loading && !error && schedule && schedule.rows && schedule.rows.length > 0">
        <div class="card" style="padding:0;overflow:auto;">
            <table class="table" style="font-size:0.8125rem;">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Description</th>
                        <th class="text-right">Rate</th>
                        <th class="text-right">Opening UCC</th>
                        <th class="text-right">Additions</th>
                        <th class="text-right">Disposals</th>
                        <th class="text-right">UCC Before CCA</th>
                        <th class="text-right">Half-Year</th>
                        <th class="text-right" title="AIIP placeholder — see banner">AIIP*</th>
                        <th class="text-right">Base for CCA</th>
                        <th class="text-right">CCA Claimed</th>
                        <th class="text-right">Recapture</th>
                        <th class="text-right">Terminal Loss</th>
                        <th class="text-right">Closing UCC</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in schedule.rows" :key="row.cca_class_id">
                        <template>
                            <tr :style="(parseFloat(row.recapture) > 0 || parseFloat(row.terminal_loss) > 0) ? 'background:#fff7d6;' : ''">
                                <td class="font-mono" x-text="row.class_number"></td>
                                <td x-text="row.class_description"></td>
                                <td class="font-mono text-right" x-text="(parseFloat(row.class_rate) * 100).toFixed(2) + '%'"></td>
                                <td class="font-mono text-right" x-text="'$' + money(row.opening_ucc)"></td>
                                <td class="font-mono text-right" x-text="'$' + money(row.cost_of_additions)"></td>
                                <td class="font-mono text-right" x-text="'$' + money(row.proceeds_of_disposition)"></td>
                                <td class="font-mono text-right" x-text="'$' + money(row.ucc_after_additions_dispositions)"></td>
                                <td class="font-mono text-right" x-text="'$' + money(row.half_year_adjustment)"></td>
                                <td class="font-mono text-right text-secondary">—</td>
                                <td class="font-mono text-right" x-text="'$' + money(row.base_amount_for_cca)"></td>
                                <td class="font-mono text-right" x-text="'$' + money(row.cca_claimed)"></td>
                                <td class="font-mono text-right" :style="parseFloat(row.recapture) > 0 ? 'font-weight:600;color:#a30000;' : ''" x-text="'$' + money(row.recapture)"></td>
                                <td class="font-mono text-right" :style="parseFloat(row.terminal_loss) > 0 ? 'font-weight:600;color:#a30000;' : ''" x-text="'$' + money(row.terminal_loss)"></td>
                                <td class="font-mono text-right" style="font-weight:600;" x-text="'$' + money(row.closing_ucc)"></td>
                                <td>
                                    <button class="btn btn-ghost btn-xs"
                                            @click="toggleDrill(row.cca_class_id)"
                                            :title="expanded[row.cca_class_id] ? 'Hide assets' : 'Show assets'">
                                        <span x-text="expanded[row.cca_class_id] ? '−' : '+'"></span>
                                    </button>
                                </td>
                            </tr>
                            <template x-if="expanded[row.cca_class_id]">
                                <tr>
                                    <td colspan="15" style="background:var(--bg-subtle);padding:14px 18px;">
                                        <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:6px;">
                                            <strong>Assets in Class <span x-text="row.class_number"></span></strong>
                                            (<span x-text="(schedule.assets_by_class[row.cca_class_id]?.assets || []).length"></span> total,
                                             <span x-text="(schedule.assets_by_class[row.cca_class_id]?.disposals || []).length"></span> disposed this year)
                                        </div>
                                        <table class="table" style="font-size:0.75rem;margin-bottom:8px;">
                                            <thead><tr>
                                                <th>Asset #</th><th>Name</th>
                                                <th>Acquired</th><th>Avail for Use</th>
                                                <th class="text-right">Cost</th>
                                                <th>Status</th><th>AIIP</th>
                                            </tr></thead>
                                            <tbody>
                                                <template x-for="a in (schedule.assets_by_class[row.cca_class_id]?.assets || [])" :key="a.id">
                                                    <tr>
                                                        <td class="font-mono" x-text="a.asset_number"></td>
                                                        <td x-text="a.name"></td>
                                                        <td x-text="a.acquisition_date"></td>
                                                        <td>
                                                            <span x-text="a.available_for_use_date || '(fallback to acq date)'"
                                                                  :style="!a.available_for_use_date ? 'color:#b8860b;font-style:italic;' : ''"></span>
                                                        </td>
                                                        <td class="font-mono text-right" x-text="'$' + money(a.acquisition_cost)"></td>
                                                        <td x-text="a.status"></td>
                                                        <td x-text="parseInt(a.is_aiip_eligible) === 1 ? '✓' : '—'"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                        <template x-if="(schedule.assets_by_class[row.cca_class_id]?.disposals || []).length > 0">
                                            <div>
                                                <div style="font-size:0.75rem;color:var(--text-secondary);margin:8px 0 4px;">
                                                    <strong>Dispositions this year</strong>
                                                </div>
                                                <table class="table" style="font-size:0.75rem;margin-bottom:0;">
                                                    <thead><tr>
                                                        <th>Asset #</th><th>Name</th>
                                                        <th>Disposed</th><th>Type</th>
                                                        <th class="text-right">Proceeds</th>
                                                        <th class="text-right">Acq Cost (cap)</th>
                                                    </tr></thead>
                                                    <tbody>
                                                        <template x-for="d in (schedule.assets_by_class[row.cca_class_id]?.disposals || [])" :key="d.disposal_id">
                                                            <tr>
                                                                <td class="font-mono" x-text="d.asset_number"></td>
                                                                <td x-text="d.name"></td>
                                                                <td x-text="d.disposal_date"></td>
                                                                <td x-text="d.disposal_type"></td>
                                                                <td class="font-mono text-right" x-text="'$' + money(d.proceeds)"></td>
                                                                <td class="font-mono text-right text-secondary" x-text="'$' + money(d.acquisition_cost)"></td>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </template>
                </tbody>
                <tfoot>
                    <tr style="font-weight:600;border-top:2px solid var(--border-default);">
                        <td colspan="3">TOTAL</td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().opening)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().additions)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().disposals)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().ucc)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().halfYear)"></td>
                        <td></td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().base)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().cca)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().recapture)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().terminal)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(totals().closing)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </template>

    <p style="margin-top:14px;font-size:0.75rem;color:var(--text-secondary);">
        * AIIP — Accelerated Investment Incentive — pending CCA-2 (full phase-out + reinstatement rules).
    </p>
</div>

<script>
function ccaSchedule() {
    return {
        fiscalYear: <?= (int) $defaultYear ?>,
        loading: false,
        error: null,
        schedule: null,
        recompute: false,
        expanded: {},

        async load() {
            this.loading = true; this.error = null;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/cca/show') ?>?fiscal_year=' + this.fiscalYear);
                this.schedule = r.success ? r.data : null;
            } catch (e) { this.error = 'Network error loading schedule.'; }
            this.loading = false;
        },

        async compute() {
            this.loading = true; this.error = null;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/cca/compute') ?>', {
                    fiscal_year: this.fiscalYear,
                    recompute:   this.recompute,
                });
                if (r.success) {
                    FF_Toast.success((r.data.computed ? 'Computed' : 'Loaded existing') + ' ' + (r.data.rows?.length || 0) + ' class rows.');
                    await this.load();
                } else {
                    this.error = (r.error && (r.error.message || JSON.stringify(r.error.fields || {}))) || 'Compute failed.';
                }
            } catch (e) { this.error = 'Network error during compute.'; }
            this.loading = false;
        },

        async lockYear() {
            if (!confirm('Lock CCA Schedule 8 for FY ' + this.fiscalYear + '? Unlock requires super_admin.')) return;
            this.loading = true; this.error = null;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/cca/lock') ?>', { fiscal_year: this.fiscalYear });
                if (r.success) {
                    FF_Toast.success('Locked FY ' + this.fiscalYear + '.');
                    await this.load();
                } else {
                    this.error = (r.error && r.error.message) || 'Lock failed.';
                }
            } catch (e) { this.error = 'Network error during lock.'; }
            this.loading = false;
        },

        downloadCsv() {
            window.open('<?= base_url('api/v1/accounting/cca/export') ?>?fiscal_year=' + this.fiscalYear + '&format=csv', '_blank');
        },
        downloadPdf() {
            window.open('<?= base_url('api/v1/accounting/cca/export') ?>?fiscal_year=' + this.fiscalYear + '&format=pdf', '_blank');
        },

        toggleDrill(classId) {
            this.expanded[classId] = !this.expanded[classId];
        },

        allLocked() {
            if (!this.schedule || !this.schedule.rows || !this.schedule.rows.length) return false;
            return this.schedule.rows.every(r => parseInt(r.is_locked) === 1);
        },

        totals() {
            const t = { opening:0, additions:0, disposals:0, ucc:0, halfYear:0, base:0, cca:0, recapture:0, terminal:0, closing:0 };
            if (!this.schedule) return t;
            for (const r of this.schedule.rows) {
                t.opening   += parseFloat(r.opening_ucc) || 0;
                t.additions += parseFloat(r.cost_of_additions) || 0;
                t.disposals += parseFloat(r.proceeds_of_disposition) || 0;
                t.ucc       += parseFloat(r.ucc_after_additions_dispositions) || 0;
                t.halfYear  += parseFloat(r.half_year_adjustment) || 0;
                t.base      += parseFloat(r.base_amount_for_cca) || 0;
                t.cca       += parseFloat(r.cca_claimed) || 0;
                t.recapture += parseFloat(r.recapture) || 0;
                t.terminal  += parseFloat(r.terminal_loss) || 0;
                t.closing   += parseFloat(r.closing_ucc) || 0;
            }
            return t;
        },

        money(v) {
            const n = parseFloat(v || 0);
            const sign = n < 0 ? '-' : '';
            return sign + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
