<?php declare(strict_types=1);

/**
 * app/admin/accounting/damage-claims/index.php
 *
 * Damage Claims Accounting Subledger per spec §23.11. Operator-facing
 * subledger view that surfaces per-claim repair cost, recovery billed/
 * collected, net P&L impact, and the three damage-related JE links
 * (recovery / repair / writeoff).
 *
 * NOTE: this is the ACCOUNTING subledger view. The operational
 * damage-claims page lives at app/admin/damage_claims/index.php and
 * remains the system of record for claim lifecycle + photos + status
 * transitions. This page is the read-only GL drill-down.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/partials/accounting-nav.php,
 *           api/v1/accounting/damage-claims/index.php
 * @session  S-ACCT-DMG
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$defaultStart = date('Y') . '-01-01';
$defaultEnd   = date('Y-m-d');

$pageTitle = 'Damage Claims Subledger';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Damage Claims Subledger</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Damage Claims — Accounting Subledger</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="damageSubledger()" x-init="periodStart = '<?= e($defaultStart) ?>'; periodEnd = '<?= e($defaultEnd) ?>'; load()">

    <!-- Controls -->
    <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:end;flex-wrap:wrap;">
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Period Start</label>
            <input type="date" x-model="periodStart" class="form-input"
                   style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Period End</label>
            <input type="date" x-model="periodEnd" class="form-input"
                   style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Status</label>
            <select x-model="statusFilter" class="form-input"
                    style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <option value="">All</option>
                <option value="reported">Reported</option>
                <option value="assessed">Assessed</option>
                <option value="repair_ordered">Repair Ordered</option>
                <option value="invoiced">Invoiced</option>
                <option value="resolved">Resolved</option>
                <option value="written_off">Written Off</option>
            </select>
        </div>
        <button @click="load()" :disabled="loading" class="btn btn-primary" style="height:36px;">
            <span x-show="!loading">Refresh</span>
            <span x-show="loading">Loading...</span>
        </button>
    </div>

    <template x-if="error">
        <div class="card" style="padding:18px;color:var(--color-danger);" x-text="error"></div>
    </template>

    <!-- Summary KPI bar -->
    <template x-if="report && report.totals">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
            <div class="card" style="padding:14px;text-align:center;">
                <div style="font-size:0.7rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;">Total Repair Cost</div>
                <div style="font-size:1.4rem;font-weight:600;margin-top:4px;font-family:var(--font-mono,monospace);" x-text="'$' + money(report.totals.total_repair_cost)"></div>
            </div>
            <div class="card" style="padding:14px;text-align:center;">
                <div style="font-size:0.7rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;">Recovery Billed</div>
                <div style="font-size:1.4rem;font-weight:600;margin-top:4px;font-family:var(--font-mono,monospace);" x-text="'$' + money(report.totals.total_recovery_billed)"></div>
            </div>
            <div class="card" style="padding:14px;text-align:center;">
                <div style="font-size:0.7rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;">Recovered</div>
                <div style="font-size:1.4rem;font-weight:600;margin-top:4px;font-family:var(--font-mono,monospace);" x-text="'$' + money(report.totals.total_recovery_collected)"></div>
            </div>
            <div class="card" style="padding:14px;text-align:center;"
                 :style="parseFloat(report.totals.total_net_pnl) > 0 ? 'border-color:#1e5e1e;' : (parseFloat(report.totals.total_net_pnl) < 0 ? 'border-color:#a30000;' : '')">
                <div style="font-size:0.7rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;">Net P&L</div>
                <div style="font-size:1.4rem;font-weight:600;margin-top:4px;font-family:var(--font-mono,monospace);"
                     :style="parseFloat(report.totals.total_net_pnl) > 0 ? 'color:#1e5e1e;' : (parseFloat(report.totals.total_net_pnl) < 0 ? 'color:#a30000;' : '')"
                     x-text="'$' + money(report.totals.total_net_pnl)"></div>
                <div style="font-size:0.7rem;color:var(--text-secondary);margin-top:2px;" x-text="report.totals.claim_count + ' claim' + (report.totals.claim_count === 1 ? '' : 's')"></div>
            </div>
        </div>
    </template>

    <!-- Empty state -->
    <template x-if="report && report.claims && report.claims.length === 0">
        <div class="card" style="padding:36px;text-align:center;color:var(--text-secondary);">
            No damage claims in the selected period.
        </div>
    </template>

    <!-- Claims table -->
    <template x-if="report && report.claims && report.claims.length > 0">
        <div class="card" style="padding:0;overflow:auto;">
            <table class="table" style="font-size:0.8125rem;">
                <thead>
                    <tr>
                        <th>Claim #</th>
                        <th>Date</th>
                        <th>Unit</th>
                        <th>Customer</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th class="text-right">Repair Cost</th>
                        <th class="text-right">Recovery Billed</th>
                        <th class="text-right">Recovered</th>
                        <th class="text-right">Net P&L</th>
                        <th>JE Links</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="c in report.claims" :key="c.claim_id">
                        <tr>
                            <td class="font-mono" x-text="c.claim_number"></td>
                            <td x-text="c.claim_date ? c.claim_date.substring(0,10) : ''"></td>
                            <td>
                                <span class="font-mono" x-text="c.unit_number"></span>
                                <span style="color:var(--text-secondary);font-size:0.7rem;" x-text="c.unit_label && c.unit_label !== c.unit_number ? ' — ' + c.unit_label : ''"></span>
                            </td>
                            <td x-text="c.customer_name || '—'"></td>
                            <td>
                                <span class="badge"
                                      :class="c.severity === 'total_loss' ? 'badge-danger' : (c.severity === 'major' ? 'badge-warning' : 'badge-info')"
                                      style="padding:1px 6px;font-size:0.625rem;text-transform:capitalize;"
                                      x-text="(c.severity || '').replace('_',' ')"></span>
                            </td>
                            <td>
                                <span class="badge"
                                      :class="c.status === 'resolved' ? 'badge-success' : (c.status === 'written_off' ? 'badge-danger' : 'badge-info')"
                                      style="padding:1px 6px;font-size:0.625rem;text-transform:capitalize;"
                                      x-text="(c.status || '').replace('_',' ')"></span>
                            </td>
                            <td class="font-mono text-right" x-text="'$' + money(c.repair_cost)"></td>
                            <td class="font-mono text-right" x-text="'$' + money(c.recovery_billed)"></td>
                            <td class="font-mono text-right" x-text="'$' + money(c.recovery_collected)"></td>
                            <td class="font-mono text-right" style="font-weight:600;"
                                :style="parseFloat(c.net_pnl_impact) > 0 ? 'color:#1e5e1e;' : (parseFloat(c.net_pnl_impact) < 0 ? 'color:#a30000;' : '')"
                                x-text="'$' + money(c.net_pnl_impact)"></td>
                            <td style="font-size:0.7rem;line-height:1.4;">
                                <template x-if="c.je_links.damage_recovery">
                                    <a :href="'<?= base_url('accounting/journal-entries/show') ?>?id=' + c.je_links.damage_recovery.je_id"
                                       style="display:block;color:#1e5e1e;" target="_blank"
                                       :title="'Damage recovery JE — ' + c.je_links.damage_recovery.status">
                                        REC <span x-text="c.je_links.damage_recovery.entry_number"></span>
                                    </a>
                                </template>
                                <template x-if="c.je_links.damage_repair">
                                    <a :href="'<?= base_url('accounting/journal-entries/show') ?>?id=' + c.je_links.damage_repair.je_id"
                                       style="display:block;color:#b8860b;" target="_blank"
                                       :title="'Damage repair JE — ' + c.je_links.damage_repair.status">
                                        REP <span x-text="c.je_links.damage_repair.entry_number"></span>
                                    </a>
                                </template>
                                <template x-if="c.je_links.damage_writeoff">
                                    <a :href="'<?= base_url('accounting/journal-entries/show') ?>?id=' + c.je_links.damage_writeoff.je_id"
                                       style="display:block;color:#a30000;" target="_blank"
                                       :title="'Damage write-off JE — ' + c.je_links.damage_writeoff.status">
                                        W/O <span x-text="c.je_links.damage_writeoff.entry_number"></span>
                                    </a>
                                </template>
                                <template x-if="!c.je_links.damage_recovery && !c.je_links.damage_repair && !c.je_links.damage_writeoff">
                                    <span style="color:var(--text-secondary);">—</span>
                                </template>
                            </td>
                            <td>
                                <a :href="'<?= base_url('damage_claims/show') ?>?id=' + c.claim_id"
                                   class="btn btn-ghost btn-xs" target="_blank">View</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-subtle);font-weight:600;">
                        <td colspan="6">Totals</td>
                        <td class="font-mono text-right" x-text="'$' + money(report.totals.total_repair_cost)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(report.totals.total_recovery_billed)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(report.totals.total_recovery_collected)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(report.totals.total_net_pnl)"></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </template>
</div>

<script>
function damageSubledger() {
    return {
        periodStart: '<?= e($defaultStart) ?>',
        periodEnd: '<?= e($defaultEnd) ?>',
        statusFilter: '',
        loading: false,
        error: '',
        report: null,

        money(v) {
            const n = parseFloat(v || 0);
            return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        async load() {
            this.error = '';
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    period_start: this.periodStart,
                    period_end: this.periodEnd,
                });
                if (this.statusFilter) params.set('status', this.statusFilter);

                const r = await fetch('<?= base_url('api/v1/accounting/damage-claims/index.php') ?>?' + params, {
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.success) throw new Error(j.message || 'load failed');
                this.report = j.data;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
