<?php declare(strict_types=1);

/**
 * app/admin/accounting/budgets/show.php
 *
 * Budget detail view: header + 12-month editable grid + variance
 * report drill-down. Editable amounts inline (Alpine $watch on each cell
 * → debounced save to update.php). Approve / archive controls. AI
 * "Explain Variance" button calls the budget_variance summary type.
 *
 * Layout (S-RECORD-REDESIGN):
 *   The Alpine component (budgetShow) opens ABOVE the header so the status
 *   badge and actions are live there: Approve / Move to Draft (primary),
 *   Variance Report; Explain Variance (AI) in the More menu.
 *   header  — ModuleHero entity: budget name + live status badge; year ·
 *             version · created chips
 *   nav     — the accounting sub-nav, directly under the header
 *   strip   — revenue · expenses · budgeted net · lines — all LIVE (Alpine),
 *             so they move as cells are edited
 *   main    — AI narrative · the 12-month grid · Add account / Save bar
 *   rail    — Needs attention (unsaved changes, draft, empty, archived,
 *             net loss) · Quarterly shape (live revenue / expense / net per
 *             quarter) · Budget facts · Related links
 *
 * @session S036, S-RECORD-REDESIGN
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

// Other budgets for the same year (versions to compare against).
$siblings = db_select(
    "SELECT id, name, version, status FROM acc_budgets WHERE year = ? AND id <> ? ORDER BY is_active DESC, id ASC LIMIT 5",
    [(int) $budget['year'], $id]
);

$pageTitle = 'Budget ' . $budget['name'];
require_once FF_ROOT . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('calendar-days') . 'FY <b>' . (int) $budget['year'] . '</b>',
    \FleetForge\Sop\SopIcons::svg('chart-pie') . e(ucfirst((string) $budget['version'])) . ' version',
    // created_at is a UTC stamp (S-UTC-STAMPS) — show the company-local day.
    \FleetForge\Sop\SopIcons::svg('users') . e($budget['created_by_name'] ?? 'system') . ' · ' . e(format_datetime($budget['created_at'], 'M j, Y')),
];
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <button type="button" class="btn btn-secondary btn-sm" @click="aiVariance()" :disabled="aiLoading" x-text="aiLoading ? 'Thinking…' : 'Explain Variance'">Explain Variance</button>
    <a href="<?= base_url('accounting/budgets') ?>">All budgets</a>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <?php if ($canEdit): ?>
        <button class="btn btn-success btn-sm" @click="setStatus('active')" x-show="status==='draft'">Approve</button>
        <button class="btn btn-secondary btn-sm" @click="setStatus('draft')" x-show="status==='active'">Move to Draft</button>
    <?php endif; ?>
    <a class="btn btn-secondary btn-sm" :href="varianceUrl()">Variance Report</a>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>

<!-- The component opens ABOVE the header so the status badge + actions are live there. -->
<div x-data="budgetShow(<?= (int) $id ?>, <?= htmlspecialchars(json_encode($budget['updated_at']), ENT_QUOTES) ?>)">
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => match ((string) $budget['status']) { 'draft' => 'warning', 'archived' => 'info', default => 'success' },
    'icon'       => 'chart-pie',
    'mark'       => (string) (int) $budget['year'],
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['Budgets', base_url('accounting/budgets')], [(string) $budget['name'], null]],
    'eyebrow'    => 'Budget',
    'title_html' => e($budget['name']) . ' <span class="badge" :class="statusBadge(status)" x-text="status">' . e($budget['status']) . '</span>',
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN) — live: they follow the grid as cells
     are edited (before saving). Revenue = revenue + other income; expenses =
     cost of revenue + operating + other expense. -->
<div class="stat-grid stat-grid--4 ff-stats">
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-arrow-trending-up"/></svg></span>
        <div class="stat-label">Revenue</div>
        <div class="stat-value font-mono" x-text="money(sumType('rev'))">—</div>
    </div>
    <div class="stat-card stat-card--amber">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Expenses</div>
        <div class="stat-value font-mono" x-text="money(sumType('exp'))">—</div>
    </div>
    <div class="stat-card" :class="sumType('rev') - sumType('exp') < 0 ? 'stat-card--red' : 'stat-card--blue'">
        <span class="stat-icon" :class="sumType('rev') - sumType('exp') < 0 ? 'stat-icon--red' : 'stat-icon--blue'"><svg><use href="#icon-chart-bar"/></svg></span>
        <div class="stat-label">Budgeted net</div>
        <div class="stat-value font-mono" x-text="money(sumType('rev') - sumType('exp'))">—</div>
        <div class="stat-delta" x-text="sumType('rev') > 0 ? Math.round((sumType('rev') - sumType('exp')) / sumType('rev') * 100) + '% margin' : ''"></div>
    </div>
    <div class="stat-card stat-card--slate">
        <span class="stat-icon stat-icon--slate"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Lines</div>
        <div class="stat-value font-mono" x-text="lines.length"><?= count($lines) ?></div>
        <div class="stat-delta" x-text="dirty.size ? 'unsaved' : 'saved'"></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main">

    <div x-show="aiText" x-cloak class="card">
        <div class="card-header"><h3 class="card-title">AI narrative</h3></div>
        <div class="card-body" style="white-space:pre-wrap;font-size:0.8125rem;line-height:1.5;" x-text="aiText"></div>
    </div>

    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <h3 class="card-title">Monthly budget</h3>
            <span x-show="saveMsg" x-cloak style="font-size:0.75rem;color:var(--color-success);" x-text="saveMsg"></span>
        </div>
        <div class="card-body" style="padding:0;overflow-x:auto;">
        <table class="table" style="width:100%;font-size:0.78rem;">
            <thead>
                <tr>
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
                    <tr>
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
    </div>

    <?php if ($canEdit): ?>
    <div class="card">
        <div class="card-body" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
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
    </div>
    <?php endif; ?>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the budget at a glance. Mostly live (Alpine):
// the grid is edited in place, so the attention list and the quarterly
// shape read the component's state rather than the page-load numbers.
$R = \FleetForge\Ui\RecordUi::class;
$staticAlerts = [];
if ($budget['status'] === 'archived') {
    $staticAlerts[] = '<li class="is-info"><span>Archived — read-only.</span></li>';
}
$attention = '<ul class="rec-alerts">'
    . '<li class="is-warning" x-show="dirty.size" x-cloak><span><b>Unsaved changes</b> — press Save All Changes before leaving.</span></li>'
    . '<li class="is-info" x-show="status === \'draft\'"><span>Draft — <b>Approve</b> it to make it the active budget for FY ' . (int) $budget['year'] . '.</span></li>'
    . '<li class="is-warning" x-show="lines.length === 0" x-cloak><span>No lines yet — add revenue and expense accounts below the grid.</span></li>'
    . '<li class="is-danger" x-show="lines.length && sumType(\'rev\') - sumType(\'exp\') < 0" x-cloak><span>Budgets a <b>net loss</b> of <span x-text="money(sumType(\'exp\') - sumType(\'rev\'))"></span>.</span></li>'
    . implode('', $staticAlerts)
    . '</ul>'
    . '<p class="rec-alerts-ok" x-show="!dirty.size && status !== \'draft\' && lines.length && sumType(\'rev\') - sumType(\'exp\') >= 0' . ($budget['status'] === 'archived' ? ' && false' : '') . '" x-cloak>All clear — nothing needs attention.</p>';
$rail = [];
$rail[] = $R::card('Needs attention', $attention, ['icon' => 'exclamation-triangle']);

// Quarterly shape — revenue / expense / net per quarter, live.
$qRows = '';
foreach ([1, 2, 3, 4] as $q) {
    $qRows .= '<div><dt>Q' . $q . '</dt><dd class="mono"><span x-text="money(quarter(' . $q . ', \'rev\'))"></span> · <span x-text="money(quarter(' . $q . ', \'exp\'))"></span><br><b :style="quarter(' . $q . ', \'rev\') - quarter(' . $q . ', \'exp\') < 0 ? \'color:var(--color-danger)\' : \'\'" x-text="money(quarter(' . $q . ', \'rev\') - quarter(' . $q . ', \'exp\'))"></b></dd></div>';
}
$rail[] = $R::card('By quarter', '<p class="text-secondary" style="margin:0 0 6px;font-size:11.5px;">Revenue · expenses, then net</p><dl class="rec-kv">' . $qRows . '</dl>', ['icon' => 'chart-bar', 'class' => 'rec-card--accent']);

$rail[] = $R::card('Budget', $R::kv([
    ['Fiscal year', (string) (int) $budget['year']],
    ['Version', e(ucfirst((string) $budget['version']))],
    ['Status', '<span x-text="status">' . e($budget['status']) . '</span>'],
    ['Created', e($budget['created_by_name'] ?? 'system') . '<br><span class="text-secondary">' . e(format_datetime($budget['created_at'])) . '</span>'],
    ['Updated', !empty($budget['updated_at']) ? e(format_datetime($budget['updated_at'])) : null],
    ['Notes', !empty($budget['notes']) ? nl2br(e($budget['notes'])) : null],
]), ['icon' => 'chart-pie']);

$rel = [['All budgets', base_url('accounting/budgets'), 'list-bullet']];
foreach ($siblings as $s) {
    $rel[] = [$s['name'], base_url('accounting/budgets/show?id=' . (int) $s['id']), 'chart-pie', ucfirst((string) $s['version']) . ' · ' . $s['status']];
}
$rail[] = $R::card('FY ' . (int) $budget['year'] . ' budgets', $R::links($rel), ['icon' => 'document-duplicate']);
?>
<aside class="rec-rail" aria-label="Budget at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->
</div><!-- /x-data budgetShow -->

<script>
// WHY json_encode + JSON_HEX_* (not htmlspecialchars): these values land inside a
// <script> block, where the HTML parser does NOT decode entities — htmlspecialchars
// turned every JSON quote into &quot;, a JS syntax error ("Unexpected token '&'")
// that left the whole Alpine component undefined. The JSON_HEX_* flags escape
// < > & ' " as \u00XX so a budget name/note can't close the script tag.
function budgetShow(budgetId, updatedAt) {
    // S-RECORD-REDESIGN: account types behind the live strip / quarterly totals.
    const REV_TYPES = ['revenue', 'other_income'];
    const EXP_TYPES = ['cost_of_revenue', 'operating_expense', 'other_expense'];
    return {
        budgetId: budgetId,
        updatedAt: updatedAt,
        status: <?= json_encode($budget['status'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        year:   <?= (int) $budget['year'] ?>,
        months: ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'],
        lines: <?= json_encode($lines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
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
        // Sum of the lines of one side ('rev' | 'exp') over the given months
        // (display only — the saved amounts are recomputed server-side).
        sumType(side, months) {
            const types = side === 'rev' ? REV_TYPES : EXP_TYPES;
            const ms = months || this.months;
            let t = 0;
            for (const l of this.lines) {
                if (!types.includes(l.account_type)) continue;
                for (const m of ms) t += parseFloat(l[m] || '0') || 0;
            }
            return Math.round(t * 100) / 100;
        },
        quarter(q, side) {
            return this.sumType(side, this.months.slice((q - 1) * 3, q * 3));
        },
        money(n) {
            const v = Number(n) || 0;
            return (v < 0 ? '-$' : '$') + Math.abs(v).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        touchLine(li) { this.dirty.add(li); this.dirty = new Set(this.dirty); },
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
            this.dirty = new Set(this.dirty);
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
                    this.dirty = new Set();
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
