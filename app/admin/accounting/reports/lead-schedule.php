<?php declare(strict_types=1);

/**
 * app/admin/accounting/reports/lead-schedule.php
 *
 * Lead schedule per-code drill-down per spec §23.2. Renders the
 * continuity detail for all accounts carrying lead_schedule_code = $code,
 * including opening / activity / closing / reconciliation per account
 * and annotations attached to this lead schedule + period.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/footer.php, includes/partials/accounting-nav.php,
 *           api/v1/accounting/reports/lead-schedule.php,
 *           api/v1/accounting/workpaper-annotations/{create,index}.php
 * @session  S-ACCT-WTB
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$code     = $_GET['code'] ?? '';
$periodId = (int) ($_GET['period_id'] ?? 0);

// Tickmark legend for annotation modal dropdown.
$legendRaw = (string) settings_get('accounting.tickmark_legend', '{}');
$tickmarkLegend = json_decode($legendRaw, true) ?: [];

$pageTitle = 'Lead Schedule — ' . htmlspecialchars($code);
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/reports/working-trial-balance') ?>?period_id=<?= (int) $periodId ?>">Working Trial Balance</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Lead <?= htmlspecialchars($code) ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Lead Schedule — <?= htmlspecialchars($code) ?></h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="leadSchedule()" x-init="code = <?= json_encode($code) ?>; periodId = <?= (int) $periodId ?>; load()">

    <template x-if="loading">
        <div class="card" style="padding:48px;text-align:center;">Loading lead schedule...</div>
    </template>
    <template x-if="error">
        <div class="card" style="padding:24px;color:var(--color-danger);" x-text="error"></div>
    </template>

    <template x-if="!loading && !error && data">
        <div>
            <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
                <div style="font-weight:600;font-size:0.875rem;">Period: <span x-text="data.period.name"></span></div>
                <div style="font-size:0.8125rem;color:var(--text-secondary);">
                    <span x-text="data.accounts.length"></span> account(s) in this lead schedule
                </div>
                <div style="margin-left:auto;">
                    <a class="btn btn-secondary btn-sm" :href="'<?= base_url('accounting/reports/working-trial-balance') ?>?period_id=' + periodId">← Back to WTB</a>
                </div>
            </div>

            <template x-for="acct in data.accounts" :key="acct.account_id">
                <div class="card" style="margin-bottom:20px;">
                    <div style="padding:14px 18px;border-bottom:1px solid var(--border-default);display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <strong x-text="acct.code + ' — ' + acct.name"></strong>
                            <span style="font-size:0.75rem;color:var(--text-secondary);margin-left:8px;" x-text="acct.account_type"></span>
                        </div>
                        <div style="display:flex;gap:18px;align-items:center;font-size:0.8125rem;">
                            <div>
                                <span style="color:var(--text-secondary);">Opening:</span>
                                <span class="font-mono" style="margin-left:4px;" x-text="'$' + money(acct.opening_balance)"></span>
                            </div>
                            <div>
                                <span style="color:var(--text-secondary);">Closing:</span>
                                <span class="font-mono" style="font-weight:600;margin-left:4px;" x-text="'$' + money(acct.closing_balance)"></span>
                            </div>
                            <template x-if="!acct.is_reconciled">
                                <span class="badge badge-warning" x-text="'Recon diff: $' + money(acct.recon_diff)"></span>
                            </template>
                            <template x-if="acct.is_reconciled">
                                <span class="badge badge-success">Reconciled</span>
                            </template>
                            <button class="btn btn-ghost btn-xs" @click="openAnnotationModal(acct.account_id)" title="Annotate">+</button>
                        </div>
                    </div>

                    <template x-if="acct.activity.length === 0">
                        <div style="padding:24px;text-align:center;color:var(--text-secondary);">No activity in this period.</div>
                    </template>
                    <template x-if="acct.activity.length > 0">
                        <div style="overflow-x:auto;">
                            <table class="table" style="font-size:0.8125rem;margin-bottom:0;">
                                <thead><tr>
                                    <th>Date</th>
                                    <th>JE #</th>
                                    <th>Source</th>
                                    <th>Description</th>
                                    <th class="text-right">Debit</th>
                                    <th class="text-right">Credit</th>
                                    <th class="text-right">Running</th>
                                </tr></thead>
                                <tbody>
                                    <template x-for="line in acct.activity" :key="line.je_id + '-' + line.description">
                                        <tr>
                                            <td x-text="line.entry_date"></td>
                                            <td class="font-mono" x-text="line.entry_number"></td>
                                            <td x-text="line.source_type || 'manual'"></td>
                                            <td x-text="line.description"></td>
                                            <td class="font-mono text-right" x-text="parseFloat(line.debit) > 0 ? '$' + money(line.debit) : ''"></td>
                                            <td class="font-mono text-right" x-text="parseFloat(line.credit) > 0 ? '$' + money(line.credit) : ''"></td>
                                            <td class="font-mono text-right" x-text="'$' + money(line.running_balance)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot>
                                    <tr style="font-weight:600;">
                                        <td colspan="4">Activity totals</td>
                                        <td class="font-mono text-right" x-text="'$' + money(acct.total_debit)"></td>
                                        <td class="font-mono text-right" x-text="'$' + money(acct.total_credit)"></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Annotations -->
            <div class="card" style="padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <h3 style="margin:0;font-size:1rem;font-weight:600;">Annotations</h3>
                    <button class="btn btn-secondary btn-sm" @click="openAnnotationModal(null)">+ Add Annotation</button>
                </div>
                <template x-if="!data.annotations || data.annotations.length === 0">
                    <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0;">No annotations on this lead schedule.</p>
                </template>
                <template x-if="data.annotations && data.annotations.length > 0">
                    <table class="table" style="font-size:0.8125rem;">
                        <thead><tr><th style="width:80px;">Tick</th><th>Account</th><th>Note</th><th style="width:140px;">By</th><th style="width:140px;">At</th></tr></thead>
                        <tbody>
                            <template x-for="a in data.annotations" :key="a.id">
                                <tr>
                                    <td><span class="badge badge-blue" x-show="a.tickmark" x-text="a.tickmark"></span></td>
                                    <td x-text="a.account_code ? (a.account_code + ' — ' + a.account_name) : '(lead-level)'"></td>
                                    <td x-text="a.note || ''"></td>
                                    <td x-text="a.created_by_name || ('user #' + a.created_by)"></td>
                                    <td x-text="a.created_at"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>
            </div>
        </div>
    </template>

    <!-- Annotation modal (mirrors WTB page) -->
    <div x-show="annotationModal.open" x-cloak class="modal-backdrop" @click.self="annotationModal.open = false" style="position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:1000;">
        <div class="card" style="padding:24px;width:min(560px,95vw);">
            <h3 style="margin-top:0;font-size:1rem;font-weight:600;">Add Annotation</h3>
            <div style="display:grid;gap:12px;">
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Tickmark</label>
                    <select x-model="annotationModal.tickmark" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;">
                        <option value="">— None —</option>
                        <?php foreach ($tickmarkLegend as $sym => $meaning): ?>
                            <option value="<?= e($sym) ?>"><?= e($sym . ' — ' . $meaning) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Note</label>
                    <textarea x-model="annotationModal.note" rows="4" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;"></textarea>
                </div>
                <p style="font-size:0.75rem;color:var(--text-secondary);margin:0;">
                    Account: <strong x-text="annotationModal.account_id ? lookupAccountLabel(annotationModal.account_id) : '(lead-schedule-level)'"></strong>
                </p>
                <p x-show="annotationModal.error" x-cloak style="font-size:0.75rem;color:var(--color-danger);margin:0;" x-text="annotationModal.error"></p>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                <button class="btn btn-ghost" @click="annotationModal.open = false">Cancel</button>
                <button class="btn btn-primary" :disabled="annotationModal.saving" @click="saveAnnotation()">
                    <span x-show="!annotationModal.saving">Save Annotation</span>
                    <span x-show="annotationModal.saving">Saving...</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function leadSchedule() {
    return {
        code: '',
        periodId: 0,
        loading: false,
        error: null,
        data: null,
        annotationModal: { open: false, saving: false, error: null, account_id: null, tickmark: '', note: '' },

        async load() {
            if (!this.code || !this.periodId) {
                this.error = 'Both code and period_id are required.';
                return;
            }
            this.loading = true; this.error = null;
            try {
                const url = '<?= base_url('api/v1/accounting/reports/lead-schedule') ?>?code=' + encodeURIComponent(this.code) + '&period_id=' + this.periodId;
                const r = await FF_Api.get(url);
                if (r.success) this.data = r.data;
                else this.error = r.message || 'Failed to load lead schedule.';
            } catch (e) { this.error = 'Network error.'; }
            this.loading = false;
        },

        money(v) {
            const n = parseFloat(v || 0);
            const sign = n < 0 ? '-' : '';
            return sign + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        openAnnotationModal(accountId) {
            this.annotationModal = { open: true, saving: false, error: null, account_id: accountId, tickmark: '', note: '' };
        },

        lookupAccountLabel(accountId) {
            if (!this.data) return '#' + accountId;
            const r = this.data.accounts.find(x => x.account_id === accountId);
            return r ? (r.code + ' — ' + r.name) : '#' + accountId;
        },

        async saveAnnotation() {
            const m = this.annotationModal;
            if (!m.tickmark && !m.note.trim()) { m.error = 'Provide tickmark or note.'; return; }
            m.saving = true; m.error = null;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/workpaper-annotations/create') ?>', {
                    workpaper_type: 'lead_schedule',
                    workpaper_ref:  this.code,
                    period_id:      this.periodId,
                    account_id:     m.account_id,
                    tickmark:       m.tickmark || null,
                    note:           m.note ? m.note.trim() : null,
                });
                if (r.success) {
                    this.annotationModal.open = false;
                    await this.load();
                } else {
                    m.error = (r.error && (r.error.message || JSON.stringify(r.error.fields || {}))) || 'Save failed.';
                }
            } catch (e) { m.error = 'Network error.'; }
            m.saving = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
