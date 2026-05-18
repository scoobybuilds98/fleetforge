<?php declare(strict_types=1);

/**
 * app/admin/accounting/fx-revaluations/index.php
 *
 * Single-page console for the FX Revaluation engine. Top half = run
 * panel (period picker + Preview + Confirm + Post). Bottom half =
 * history table with Reverse action per posted row.
 *
 * @session S037-FX
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Accounting\AccountingService;

require_auth();
require_permission('journal_entries', 'view');

$canCreate = can('journal_entries', 'create');
$canEdit   = can('journal_entries', 'edit');

$fxEnabled  = (string) AccountingService::setting('accounting.fx_revaluation_enabled', '0') === '1';
$rateSource = (string) AccountingService::setting('accounting.fx_rate_source', 'bank_of_canada');

// Closed periods drive the dropdown — open periods cannot be revalued.
$closedPeriods = db_select(
    "SELECT id, name, end_date, status FROM acc_periods
      WHERE status = 'closed'
      ORDER BY end_date DESC
      LIMIT 36",
    []
);

$pageTitle = 'FX Revaluation';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">FX Revaluation</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">FX Revaluation</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<?php if (!$fxEnabled): ?>
<div style="padding:10px 14px;margin-bottom:14px;background:#fff7d6;border:1px solid #b8860b;color:#6b4900;border-radius:4px;font-size:0.85rem;">
    <strong>FX Revaluation is disabled.</strong>
    Enable it by setting <code>accounting.fx_revaluation_enabled = 1</code> in
    <a href="<?= base_url('accounting/settings') ?>">Accounting Settings</a>.
</div>
<?php endif; ?>

<div x-data="fxRevaluation()" x-init="loadHistory()">

    <!-- Run Panel -->
    <div class="card" style="padding:18px;margin-bottom:14px;">
        <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Run Revaluation</div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
            <div>
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">Period (closed only)</label>
                <select x-model.number="form.period_id" class="form-input"
                        style="width:100%;padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;"
                        <?= !$fxEnabled ? 'disabled' : '' ?>>
                    <option value="">— Select a closed period —</option>
                    <?php foreach ($closedPeriods as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (ends <?= e($p['end_date']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">Rate source</label>
                <div style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-elev);color:var(--text-secondary);font-size:0.8125rem;">
                    <?= $rateSource === 'manual' ? 'Manual (from settings)' : 'Bank of Canada (auto)' ?>
                </div>
            </div>
            <div>
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">Manual rate (optional override)</label>
                <input type="text" x-model="form.manual_rate" class="form-input font-mono"
                       style="width:100%;padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;"
                       placeholder="e.g. 1.365000"
                       <?= !$fxEnabled ? 'disabled' : '' ?>>
            </div>
        </div>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button class="btn btn-primary btn-sm" @click="runPreview()"
                    :disabled="!form.period_id || previewing || !<?= $fxEnabled ? 'true' : 'false' ?>"
                    x-text="previewing ? 'Loading…' : 'Preview'">Preview</button>
            <?php if ($canCreate): ?>
            <label style="display:flex;gap:6px;align-items:center;font-size:0.8125rem;cursor:pointer;">
                <input type="checkbox" x-model="confirmPost" :disabled="!preview">
                I have reviewed the preview and want to post
            </label>
            <button class="btn btn-success btn-sm" @click="post()"
                    :disabled="!preview || !confirmPost || posting"
                    x-text="posting ? 'Posting…' : 'Post Revaluation'">Post Revaluation</button>
            <?php endif; ?>
            <button class="btn btn-secondary btn-sm" @click="clearPreview()" x-show="preview" x-cloak>Clear</button>
        </div>

        <div x-show="previewError" x-cloak style="margin-top:10px;padding:8px 10px;background:#ffe6e6;border:1px solid #cc0000;color:#990000;border-radius:4px;font-size:0.8125rem;" x-text="previewError"></div>
        <div x-show="postError" x-cloak style="margin-top:10px;padding:8px 10px;background:#ffe6e6;border:1px solid #cc0000;color:#990000;border-radius:4px;font-size:0.8125rem;" x-text="postError"></div>
        <div x-show="postSuccess" x-cloak style="margin-top:10px;padding:8px 10px;background:#e6ffe6;border:1px solid #008800;color:#005500;border-radius:4px;font-size:0.8125rem;" x-text="postSuccess"></div>

        <!-- Preview Result -->
        <template x-if="preview">
            <div style="margin-top:14px;border-top:1px solid var(--border-default);padding-top:14px;">
                <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:12px;font-size:0.8125rem;">
                    <div><span style="color:var(--text-secondary);">Period:</span> <strong x-text="preview.period_name"></strong></div>
                    <div><span style="color:var(--text-secondary);">End date:</span> <strong class="font-mono" x-text="preview.period_end"></strong></div>
                    <div><span style="color:var(--text-secondary);">Rate:</span> <strong class="font-mono" x-text="preview.rate_used"></strong>
                        <span style="color:var(--text-secondary);font-size:0.75rem;" x-text="'(' + preview.rate_source + ')'"></span>
                    </div>
                    <div x-show="preview.existing_revaluation_id" x-cloak>
                        <span style="color:var(--color-warning);">⚠ Already posted: #</span><strong x-text="preview.existing_revaluation_id"></strong>
                    </div>
                </div>

                <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-default);">
                            <th style="padding:8px 10px;text-align:left;">Account</th>
                            <th style="padding:8px 10px;text-align:right;">USD Balance</th>
                            <th style="padding:8px 10px;text-align:right;">CAD Book</th>
                            <th style="padding:8px 10px;text-align:right;">CAD Revalued</th>
                            <th style="padding:8px 10px;text-align:right;">Δ Unrealized</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in preview.accounts" :key="r.account_id">
                            <tr style="border-bottom:1px solid var(--border-default);">
                                <td style="padding:6px 10px;font-family:var(--font-mono);font-size:0.78rem;" x-text="r.code + ' — ' + r.name"></td>
                                <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(r.usd_balance) + ' USD'"></td>
                                <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(r.cad_book)"></td>
                                <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(r.cad_revalued)"></td>
                                <td class="font-mono" style="padding:6px 10px;text-align:right;font-weight:600;"
                                    :style="(parseFloat(r.delta) > 0 ? 'color:var(--color-success);' : (parseFloat(r.delta) < 0 ? 'color:var(--color-danger);' : ''))"
                                    x-text="fmt(r.delta)"></td>
                            </tr>
                        </template>
                        <template x-if="preview.accounts.length === 0">
                            <tr><td colspan="5" style="padding:18px;text-align:center;color:var(--text-secondary);">No USD-monetary accounts to revalue.</td></tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--border-default);background:#d8e6ff;">
                            <td style="padding:9px 10px;font-weight:700;">Total Unrealized Gain / (Loss)</td>
                            <td class="font-mono" style="padding:9px 10px;text-align:right;font-weight:700;" x-text="fmt(preview.total_usd) + ' USD'"></td>
                            <td class="font-mono" style="padding:9px 10px;text-align:right;font-weight:700;" x-text="fmt(preview.total_cad_book)"></td>
                            <td class="font-mono" style="padding:9px 10px;text-align:right;font-weight:700;" x-text="fmt(preview.total_cad_revalued)"></td>
                            <td class="font-mono" style="padding:9px 10px;text-align:right;font-weight:700;" x-text="fmt(preview.total_unrealized_gain_loss)"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </template>
    </div>

    <!-- History -->
    <div class="card" style="padding:18px;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px;">
            <div style="font-weight:600;font-size:0.95rem;">Revaluation History</div>
            <button class="btn btn-ghost btn-xs" @click="loadHistory()" :disabled="historyLoading">Refresh</button>
        </div>
        <template x-if="historyLoading">
            <div style="padding:18px;text-align:center;color:var(--text-secondary);font-size:0.8125rem;">Loading…</div>
        </template>
        <template x-if="!historyLoading && history.length === 0">
            <div style="padding:18px;text-align:center;color:var(--text-secondary);font-size:0.8125rem;">No revaluations posted yet.</div>
        </template>
        <template x-if="!historyLoading && history.length > 0">
            <div style="overflow-x:auto;">
                <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-default);">
                            <th style="padding:8px 10px;text-align:left;">Period</th>
                            <th style="padding:8px 10px;text-align:left;">Date Run</th>
                            <th style="padding:8px 10px;text-align:right;">Rate</th>
                            <th style="padding:8px 10px;text-align:right;">Gain / (Loss)</th>
                            <th style="padding:8px 10px;text-align:left;">JE #</th>
                            <th style="padding:8px 10px;text-align:center;">Status</th>
                            <th style="padding:8px 10px;text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="h in history" :key="h.id">
                            <tr style="border-bottom:1px solid var(--border-default);" :style="h.status === 'reversed' ? 'opacity:0.6;' : ''">
                                <td style="padding:6px 10px;" x-text="h.period_name"></td>
                                <td class="font-mono" style="padding:6px 10px;" x-text="(h.run_at || h.created_at || '').replace('T', ' ').substring(0, 16)"></td>
                                <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="h.exchange_rate_used"></td>
                                <td class="font-mono" style="padding:6px 10px;text-align:right;font-weight:600;"
                                    :style="(parseFloat(h.unrealized_gain_loss) > 0 ? 'color:var(--color-success);' : (parseFloat(h.unrealized_gain_loss) < 0 ? 'color:var(--color-danger);' : ''))"
                                    x-text="fmt(h.unrealized_gain_loss)"></td>
                                <td class="font-mono" style="padding:6px 10px;">
                                    <template x-if="h.journal_entry_id">
                                        <a :href="'<?= e(base_url('accounting/journal-entries/show')) ?>?id=' + h.journal_entry_id"
                                           style="color:var(--color-accent);text-decoration:none;"
                                           x-text="h.je_number"></a>
                                    </template>
                                    <template x-if="!h.journal_entry_id">
                                        <span style="color:var(--text-secondary);">— (zero delta)</span>
                                    </template>
                                </td>
                                <td style="padding:6px 10px;text-align:center;">
                                    <span class="badge" :class="statusBadge(h.status)" x-text="h.status"></span>
                                </td>
                                <td style="padding:6px 10px;text-align:center;">
                                    <?php if ($canEdit): ?>
                                    <template x-if="h.status === 'posted'">
                                        <button class="btn btn-danger btn-xs" @click="reverse(h.id, h.period_name)">Reverse</button>
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

<script>
function fxRevaluation() {
    const apiBase = '<?= e(base_url('api/v1/accounting/fx-revaluations')) ?>';
    return {
        form: { period_id: '', manual_rate: '' },
        preview: null,
        previewing: false,
        previewError: '',
        confirmPost: false,
        posting: false,
        postError: '',
        postSuccess: '',
        history: [],
        historyLoading: true,

        statusBadge(s) {
            return s === 'posted' ? 'badge-green' : (s === 'reversed' ? 'badge-red' : 'badge-neutral');
        },

        async runPreview() {
            this.previewing = true;
            this.previewError = '';
            this.preview = null;
            this.confirmPost = false;
            try {
                const url = new URL(apiBase + '/preview.php', window.location.origin);
                url.searchParams.set('period_id', String(this.form.period_id));
                if (this.form.manual_rate) url.searchParams.set('manual_rate', this.form.manual_rate);
                const r = await fetch(url.toString());
                const j = await r.json();
                if (j && j.success) {
                    this.preview = j.data;
                } else {
                    this.previewError = (j && j.error && j.error.message) || 'Preview failed.';
                }
            } catch (e) { this.previewError = 'Preview failed: ' + e.message; }
            this.previewing = false;
        },

        clearPreview() {
            this.preview = null;
            this.confirmPost = false;
            this.postError = '';
            this.postSuccess = '';
        },

        async post() {
            if (!this.preview || !this.confirmPost) return;
            this.posting = true;
            this.postError = '';
            this.postSuccess = '';
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch(apiBase + '/post.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({
                        period_id: this.preview.period_id,
                        rate: this.preview.rate_used,
                        confirm: 1
                    })
                });
                const j = await r.json();
                if (j && j.success) {
                    const rev = j.data.revaluation;
                    this.postSuccess = 'Posted revaluation #' + rev.id + ' (gain/loss ' + this.fmt(rev.unrealized_gain_loss) + ').';
                    this.clearPreview();
                    await this.loadHistory();
                } else {
                    this.postError = (j && j.error && j.error.message) || 'Post failed.';
                }
            } catch (e) { this.postError = 'Post failed: ' + e.message; }
            this.posting = false;
        },

        async reverse(id, periodName) {
            if (!confirm('Reverse the revaluation for ' + periodName + '? This will reverse the backing journal entry.')) return;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch(apiBase + '/reverse.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ id })
                });
                const j = await r.json();
                if (j && j.success) {
                    await this.loadHistory();
                } else {
                    alert((j && j.error && j.error.message) || 'Reverse failed.');
                }
            } catch (e) { alert('Reverse failed: ' + e.message); }
        },

        async loadHistory() {
            this.historyLoading = true;
            try {
                const r = await fetch(apiBase + '/index.php?per_page=50');
                const j = await r.json();
                this.history = (j && j.success && Array.isArray(j.data.data)) ? j.data.data : [];
            } catch (e) { this.history = []; }
            this.historyLoading = false;
        },

        fmt(s) {
            const n = parseFloat(s);
            if (!isFinite(n) || n === 0) return '$0.00';
            return (n < 0 ? '-$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
