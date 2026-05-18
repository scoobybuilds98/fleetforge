<?php declare(strict_types=1);

/**
 * app/admin/accounting/budgets/show.php
 *
 * Budget detail view: header card + 12-month editable grid + variance
 * report drill-down. Editable amounts inline (Alpine $watch on each cell
 * → debounced save to update.php). Approve / archive controls. AI
 * "Explain Variance" button calls the budget_variance summary type.
 *
 * @session S036
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Budget Not Specified</h1>';
    exit;
}

$budget = db_row(
    "SELECT b.*, u.name AS created_by_name
       FROM acc_budgets b LEFT JOIN users u ON u.id = b.created_by
      WHERE b.id = ?",
    [$id]
);
if (!$budget) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Budget Not Found</h1>';
    exit;
}

// Pull existing lines for initial render
$lines = db_select(
    "SELECT bl.id, bl.account_id, a.code, a.name AS account_name, a.account_type,
            bl.`jan`, bl.`feb`, bl.`mar`, bl.`apr`, bl.`may`, bl.`jun`,
            bl.`jul`, bl.`aug`, bl.`sep`, bl.`oct`, bl.`nov`, bl.`dec`,
            bl.annual_total
       FROM acc_budget_lines bl
       JOIN acc_accounts a ON a.id = bl.account_id
      WHERE bl.budget_id = ?
      ORDER BY a.sort_order ASC, a.code ASC",
    [$id]
);

// All P&L-relevant accounts for the "Add account" dropdown
$availableAccounts = db_select(
    "SELECT id, code, name, account_type
       FROM acc_accounts
      WHERE is_active = 1 AND is_header = 0
        AND account_type IN ('revenue','cost_of_revenue','operating_expense','other_income','other_expense')
      ORDER BY sort_order ASC, code ASC",
    []
);

$canEdit = can('journal_entries', 'edit') && $budget['status'] !== 'archived';

$pageTitle = 'Budget ' . $budget['name'];
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/budgets') ?>">Budgets</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($budget['name']) ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">
        <?= e($budget['name']) ?>
        <span style="font-weight:400;color:var(--text-secondary);font-size:0.85rem;margin-left:6px;"><?= (int) $budget['year'] ?> · <?= e($budget['version']) ?></span>
    </h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/budgets') ?>">← Back</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="budgetShow(<?= (int) $id ?>, <?= htmlspecialchars(json_encode($budget['updated_at']), ENT_QUOTES) ?>)"
     x-init="init()">

    <div class="card" style="padding:18px;margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;align-items:start;">
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Status</div>
                <div><span class="badge" :class="statusBadge(status)" x-text="status"></span></div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Lines</div>
                <div class="font-mono" x-text="lines.length"></div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Created</div>
                <div><?= e($budget['created_by_name'] ?? 'system') ?> <span style="font-size:0.7rem;color:var(--text-secondary);"><?= e(substr((string) $budget['created_at'], 0, 10)) ?></span></div>
            </div>
            <div style="text-align:right;">
                <?php if ($canEdit): ?>
                    <button class="btn btn-success btn-sm" @click="setStatus('active')" x-show="status==='draft'">Approve</button>
                    <button class="btn btn-secondary btn-sm" @click="setStatus('draft')" x-show="status==='active'">Move to Draft</button>
                <?php endif; ?>
                <a class="btn btn-secondary btn-sm" :href="varianceUrl()">Variance Report</a>
                <button class="btn btn-secondary btn-sm" @click="aiVariance()" :disabled="aiLoading" x-text="aiLoading ? 'Thinking…' : 'Explain Variance'">Explain Variance</button>
            </div>
        </div>
        <div x-show="saveMsg" x-cloak style="margin-top:10px;font-size:0.75rem;color:var(--color-success);" x-text="saveMsg"></div>
    </div>

    <div x-show="aiText" x-cloak class="card" style="padding:14px;margin-bottom:14px;background:var(--bg-elev);border-left:3px solid var(--color-accent);">
        <div style="font-weight:600;font-size:0.85rem;margin-bottom:6px;">AI Narrative</div>
        <div style="white-space:pre-wrap;font-size:0.8125rem;line-height:1.5;" x-text="aiText"></div>
    </div>

    <div class="card" style="overflow-x:auto;">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.78rem;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-default);">
                    <th style="padding:6px 8px;text-align:left;min-width:160px;">Account</th>
                    <template x-for="m in months" :key="m">
                        <th style="padding:6px 8px;text-align:right;text-transform:uppercase;font-size:0.7rem;" x-text="m"></th>
                    </template>
                    <th style="padding:6px 8px;text-align:right;font-weight:700;">Annual</th>
                    <?php if ($canEdit): ?>
                    <th style="padding:6px 8px;text-align:center;">×</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <template x-for="(line, li) in lines" :key="line.account_id">
                    <tr style="border-bottom:1px solid var(--border-default);">
                        <td style="padding:6px 8px;font-family:var(--font-mono);" x-text="line.code + ' — ' + line.account_name"></td>
                        <template x-for="m in months" :key="m">
                            <td style="padding:2px;text-align:right;">
                                <input type="number" step="0.01" x-model.number="line[m]" @change="touchLine(li)" <?= $canEdit ? '' : 'disabled' ?> class="font-mono" style="width:80px;padding:4px 6px;border:1px solid var(--border-default);border-radius:3px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;text-align:right;">
                            </td>
                        </template>
                        <td class="font-mono" style="padding:6px 8px;text-align:right;font-weight:600;" x-text="fmt(annualTotal(line))"></td>
                        <?php if ($canEdit): ?>
                        <td style="padding:6px 8px;text-align:center;">
                            <button class="btn btn-ghost btn-xs" @click="removeLine(li)" title="Remove line">✕</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                </template>
                <template x-if="lines.length === 0">
                    <tr><td colspan="14" style="padding:24px;text-align:center;color:var(--text-secondary);">No line items. Add accounts below.</td></tr>
                </template>
            </tbody>
        </table>
    </div>

    <?php if ($canEdit): ?>
    <div class="card" style="padding:14px;margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <div style="flex:1;min-width:260px;">
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">Add account</label>
            <select x-model="newAccountId" class="form-input" style="width:100%;padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <option value="">— Select account —</option>
                <?php foreach ($availableAccounts as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" data-code="<?= e($a['code']) ?>" data-name="<?= e($a['name']) ?>" data-type="<?= e($a['account_type']) ?>"><?= e($a['code'] . ' — ' . $a['name']) ?> (<?= e($a['account_type']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" @click="addAccount()" :disabled="!newAccountId">+ Add Line</button>
        <button class="btn btn-success btn-sm" @click="saveAll()" :disabled="saving" x-text="saving ? 'Saving…' : 'Save All Changes'">Save All Changes</button>
    </div>
    <?php endif; ?>
</div>

<script>
function budgetShow(budgetId, updatedAt) {
    return {
        budgetId: budgetId,
        updatedAt: updatedAt,
        status: <?= htmlspecialchars(json_encode($budget['status']), ENT_QUOTES) ?>,
        year:   <?= (int) $budget['year'] ?>,
        months: ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'],
        lines: <?= htmlspecialchars(json_encode($lines), ENT_QUOTES) ?>,
        dirty: new Set(),
        saving: false,
        saveMsg: '',
        newAccountId: '',
        aiLoading: false,
        aiText: '',
        init() {
            // Coerce numeric monthly values for editing
            this.lines = this.lines.map(l => {
                this.months.forEach(m => { l[m] = parseFloat(l[m] || '0'); });
                return l;
            });
        },
        statusBadge(s) {
            return s === 'active' ? 'badge-green' : (s === 'archived' ? 'badge-red' : 'badge-neutral');
        },
        annualTotal(line) {
            return this.months.reduce((a, m) => a + (parseFloat(line[m] || '0') || 0), 0).toFixed(2);
        },
        touchLine(li) { this.dirty.add(li); },
        addAccount() {
            const opt = document.querySelector('option[value="' + this.newAccountId + '"]');
            if (!opt) return;
            if (this.lines.some(l => l.account_id === parseInt(this.newAccountId, 10))) {
                alert('That account is already on the budget.'); return;
            }
            const row = { account_id: parseInt(this.newAccountId, 10), code: opt.dataset.code, account_name: opt.dataset.name, account_type: opt.dataset.type };
            this.months.forEach(m => row[m] = 0);
            this.lines.push(row);
            this.dirty.add(this.lines.length - 1);
            this.newAccountId = '';
        },
        removeLine(li) {
            if (!confirm('Remove this account from the budget? Saved data will be lost when you Save All Changes.')) return;
            this.lines.splice(li, 1);
            // Remove the index and shift subsequent indexes (we re-build the dirty set)
            this.dirty = new Set([...this.dirty].filter(i => i !== li).map(i => i > li ? i - 1 : i));
            this.dirty.add('__delete__'); // forces a save round-trip
        },
        async saveAll() {
            this.saving = true; this.saveMsg = '';
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const linesPayload = this.lines.map(l => {
                    const row = { account_id: l.account_id };
                    this.months.forEach(m => row[m] = (parseFloat(l[m] || '0') || 0).toFixed(2));
                    return row;
                });
                const r = await fetch('<?= e(base_url('api/v1/accounting/budgets/update.php')) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ id: this.budgetId, updated_at: this.updatedAt, lines: linesPayload })
                });
                const j = await r.json();
                if (j && j.success) {
                    this.updatedAt = j.data.updated_at;
                    this.saveMsg = 'Saved.';
                    this.dirty.clear();
                    setTimeout(() => { this.saveMsg = ''; }, 3000);
                } else {
                    this.saveMsg = (j && j.error && j.error.message) || 'Save failed.';
                }
            } catch (e) { this.saveMsg = 'Save failed: ' + e.message; }
            this.saving = false;
        },
        async setStatus(newStatus) {
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch('<?= e(base_url('api/v1/accounting/budgets/update.php')) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ id: this.budgetId, updated_at: this.updatedAt, status: newStatus })
                });
                const j = await r.json();
                if (j && j.success) {
                    this.status = j.data.status;
                    this.updatedAt = j.data.updated_at;
                } else {
                    alert((j && j.error && j.error.message) || 'Status change failed.');
                }
            } catch (e) { alert('Status change failed: ' + e.message); }
        },
        varianceUrl() {
            const ys = this.year + '-01-01';
            const ye = this.year + '-12-31';
            return '<?= e(base_url('api/v1/accounting/budgets/variance.php')) ?>?budget_id=' + this.budgetId + '&period_start=' + ys + '&period_end=' + ye;
        },
        async aiVariance() {
            this.aiLoading = true; this.aiText = '';
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const ys = this.year + '-01-01';
                const ye = this.year + '-12-31';
                const r = await fetch('<?= e(base_url('api/v1/ai/summary.php')) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ entity_type: 'accounting', entity_id: this.budgetId, summary_type: 'budget_variance', context: { budget_id: this.budgetId, from: ys, to: ye } })
                });
                const j = await r.json();
                this.aiText = (j && j.summary) ? j.summary : ((j && j.error && j.error.message) || 'AI narrative not available.');
            } catch (e) { this.aiText = 'AI narrative failed: ' + e.message; }
            this.aiLoading = false;
        },
        fmt(s) {
            const n = parseFloat(s);
            if (!isFinite(n) || n === 0) return '—';
            return (n < 0 ? '-$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
