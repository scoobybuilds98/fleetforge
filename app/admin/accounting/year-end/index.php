<?php declare(strict_types=1);

/**
 * app/admin/accounting/year-end/index.php
 *
 * Single-page year-end close console:
 *   1. Fiscal-year selector
 *   2. Checklist (17 items, checkbox-toggle, progress bar)
 *   3. Pre-flight check status grid (with AR drift super-admin override)
 *   4. Close button (gated on checklist complete + preflight pass)
 *   5. Prior closures table (with super-admin reversal action)
 *
 * @session S037-YE
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$canCreate     = can('journal_entries', 'create');
$canEdit       = can('journal_entries', 'edit');
$isSuperAdmin  = (current_user()['role_slug'] ?? '') === 'super_admin';

// Available fiscal years = years with at least one acc_periods row
$availableYears = array_map(static fn($r) => (int) $r['year'], db_select(
    "SELECT DISTINCT `year` FROM acc_periods ORDER BY `year` DESC"
));
$defaultYear = (int) ($_GET['fiscal_year'] ?? ($availableYears[0] ?? date('Y')));

$priorClosures = db_select(
    "SELECT c.id, c.fiscal_year, c.closed_at, c.status, c.package_path, c.package_hash,
            c.closing_je_id, je.entry_number AS je_number, je.status AS je_status,
            u.name AS closed_by_name
       FROM acc_year_end_closures c
  LEFT JOIN acc_journal_entries je ON je.id = c.closing_je_id
  LEFT JOIN users u ON u.id = c.closed_by
      ORDER BY c.fiscal_year DESC"
);

$pageTitle = 'Year-End Close';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Year-End</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Year-End Close</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="yearEnd(<?= (int) $defaultYear ?>, <?= $isSuperAdmin ? 'true' : 'false' ?>)"
     x-init="init()">

    <!-- Fiscal year selector -->
    <div class="card" style="padding:14px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:12px;align-items:end;">
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">Fiscal Year</label>
            <select x-model.number="fiscalYear" @change="init()" class="form-input"
                    style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:120px;">
                <?php foreach ($availableYears as $y): ?>
                    <option value="<?= (int) $y ?>"><?= (int) $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-ghost btn-sm" @click="init()" :disabled="loading">Refresh</button>
    </div>

    <!-- Checklist + Preflight side by side -->
    <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:14px;margin-bottom:14px;">

        <!-- Checklist -->
        <div class="card" style="padding:18px;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px;">
                <div style="font-weight:600;font-size:0.95rem;">Year-End Checklist</div>
                <div style="font-size:0.78rem;color:var(--text-secondary);" x-text="checklistSummary.complete_count + ' / ' + checklistSummary.total_count + ' (' + checklistSummary.pct_complete + '%)'"></div>
            </div>
            <div style="height:6px;background:var(--bg-elev);border-radius:3px;margin-bottom:12px;overflow:hidden;">
                <div :style="'height:100%;background:var(--color-success);width:' + checklistSummary.pct_complete + '%;transition:width 0.3s;'"></div>
            </div>
            <template x-for="item in checklist" :key="item.id">
                <div style="display:flex;gap:10px;padding:7px 0;border-bottom:1px solid var(--border-default);font-size:0.8125rem;align-items:flex-start;">
                    <input type="checkbox" :checked="item.is_complete == 1" @change="toggleItem(item)" <?= $canEdit ? '' : 'disabled' ?> style="margin-top:2px;">
                    <div style="flex:1;">
                        <div :style="(item.is_complete == 1 ? 'text-decoration:line-through;color:var(--text-secondary);' : '')">
                            <span x-text="item.item_label"></span>
                            <!-- S-ACCT-LESSOR-6: deep-link the impairment-tests item to its workflow page -->
                            <template x-if="item.item_key === 'fleet_impairment_tests'">
                                <a :href="'<?= base_url('accounting/impairment') ?>?fiscal_year=' + fiscalYear"
                                   style="margin-left:6px;font-size:0.75rem;"
                                   x-show="item.is_complete != 1">→ Open</a>
                            </template>
                        </div>
                        <div x-show="item.is_complete == 1 && item.completed_by_name" x-cloak style="font-size:0.7rem;color:var(--text-secondary);margin-top:2px;">
                            ✓ <span x-text="item.completed_by_name"></span>
                            <span x-text="' · ' + (item.completed_at || '').substring(0, 16)"></span>
                        </div>
                    </div>
                </div>
            </template>
            <template x-if="checklist.length === 0 && !loading">
                <div style="padding:18px;text-align:center;color:var(--text-secondary);font-size:0.8125rem;">Loading checklist…</div>
            </template>
        </div>

        <!-- Preflight + Close -->
        <div class="card" style="padding:18px;">
            <div style="font-weight:600;font-size:0.95rem;margin-bottom:10px;">Pre-Flight Checks</div>
            <template x-for="check in preflightRows" :key="check.key">
                <div style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-default);font-size:0.8125rem;align-items:flex-start;">
                    <span style="font-weight:600;min-width:14px;" x-text="check.pass ? '✓' : (check.is_warning ? '⚠' : '✕')" :style="check.pass ? 'color:var(--color-success);' : (check.is_warning ? 'color:var(--color-warning);' : 'color:var(--color-danger);')"></span>
                    <div style="flex:1;">
                        <div style="font-weight:500;" x-text="check.label"></div>
                        <div style="font-size:0.72rem;color:var(--text-secondary);margin-top:2px;" x-text="check.detail"></div>
                    </div>
                </div>
            </template>

            <template x-if="preflight && preflight.requires_super_admin_override">
                <div class="alert alert-warning" style="margin-top:12px;font-size:0.78rem;">
                    <label style="display:flex;gap:6px;align-items:center;cursor:pointer;">
                        <input type="checkbox" x-model="arDriftOverride">
                        I acknowledge the AR drift and wish to proceed (super_admin override).
                    </label>
                </div>
            </template>

            <div style="margin-top:14px;border-top:1px solid var(--border-default);padding-top:12px;">
                <?php if ($canCreate): ?>
                <button class="btn btn-success btn-sm" @click="confirmAndClose()"
                        :disabled="!canClose() || closing"
                        x-text="closing ? 'Closing…' : ('Start Year-End Close for ' + fiscalYear)">Start Year-End Close</button>
                <?php endif; ?>
                <div x-show="closeMessage" x-cloak style="margin-top:10px;font-size:0.8125rem;" :style="closeIsError ? 'color:var(--color-danger);' : 'color:var(--color-success);'" x-text="closeMessage"></div>
            </div>
        </div>
    </div>

    <!-- Close result card -->
    <template x-if="lastClose">
        <div class="card" style="padding:18px;margin-bottom:14px;background:#e8f8f0;border-left:4px solid var(--color-success);">
            <div style="font-weight:700;font-size:0.95rem;margin-bottom:8px;">✅ Year-End Close Complete — FY <span x-text="lastClose.closure.fiscal_year"></span></div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;font-size:0.8125rem;">
                <div><span style="color:var(--text-secondary);">Net Income:</span> <strong class="font-mono" x-text="fmt(lastClose.net_income)"></strong></div>
                <div><span style="color:var(--text-secondary);">Closing JE:</span> <strong class="font-mono" x-text="lastClose.closing_je ? lastClose.closing_je.entry_number : '(zero balances — no JE)'"></strong></div>
                <div><span style="color:var(--text-secondary);">New Periods Created:</span> <strong x-text="lastClose.new_periods_created"></strong></div>
            </div>
            <div x-show="lastClose.package && lastClose.package.package_path" x-cloak style="margin-top:10px;">
                <a :href="'<?= e(base_url('api/v1/accounting/year-end/package_download.php')) ?>?fiscal_year=' + lastClose.closure.fiscal_year"
                   class="btn btn-primary btn-sm" target="_blank">Download Year-End Package (ZIP)</a>
            </div>
        </div>
    </template>

    <!-- Prior closures -->
    <div class="card" style="padding:18px;">
        <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Prior Year-End Closures</div>
        <?php if (empty($priorClosures)): ?>
            <div style="padding:18px;text-align:center;color:var(--text-secondary);font-size:0.8125rem;">No prior closures recorded.</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-default);">
                            <th style="padding:8px 10px;text-align:left;">FY</th>
                            <th style="padding:8px 10px;text-align:left;">Closed At</th>
                            <th style="padding:8px 10px;text-align:left;">Closed By</th>
                            <th style="padding:8px 10px;text-align:left;">JE #</th>
                            <th style="padding:8px 10px;text-align:center;">Status</th>
                            <th style="padding:8px 10px;text-align:center;">Package</th>
                            <th style="padding:8px 10px;text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($priorClosures as $c): ?>
                            <tr style="border-bottom:1px solid var(--border-default);" <?= $c['status'] === 'reversed' ? 'style="opacity:0.6;"' : '' ?>>
                                <td class="font-mono" style="padding:6px 10px;font-weight:600;"><?= (int) $c['fiscal_year'] ?></td>
                                <td class="font-mono" style="padding:6px 10px;font-size:0.78rem;"><?= e(substr((string) $c['closed_at'], 0, 16)) ?></td>
                                <td style="padding:6px 10px;"><?= e($c['closed_by_name'] ?? 'system') ?></td>
                                <td class="font-mono" style="padding:6px 10px;">
                                    <?php if ($c['closing_je_id']): ?>
                                        <a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $c['closing_je_id']) ?>"
                                           style="color:var(--color-accent);text-decoration:none;"><?= e($c['je_number']) ?></a>
                                    <?php else: ?>
                                        <span style="color:var(--text-secondary);">— (zero balances)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:6px 10px;text-align:center;">
                                    <span class="badge <?= $c['status'] === 'closed' ? 'badge-green' : 'badge-red' ?>"><?= e($c['status']) ?></span>
                                </td>
                                <td style="padding:6px 10px;text-align:center;">
                                    <?php if ($c['package_path']): ?>
                                        <a href="<?= base_url('api/v1/accounting/year-end/package_download.php?fiscal_year=' . (int) $c['fiscal_year']) ?>"
                                           target="_blank" class="btn btn-ghost btn-xs">Download</a>
                                    <?php else: ?>
                                        <span style="color:var(--text-secondary);font-size:0.72rem;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:6px 10px;text-align:center;">
                                    <?php if ($isSuperAdmin && $c['status'] === 'closed'): ?>
                                        <button class="btn btn-danger btn-xs"
                                                @click="reverseClosure(<?= (int) $c['fiscal_year'] ?>)">Reverse</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function yearEnd(initialYear, isSuperAdmin) {
    const apiBase = '<?= e(base_url('api/v1/accounting')) ?>';
    return {
        fiscalYear: initialYear,
        isSuperAdmin: isSuperAdmin,
        checklist: [],
        checklistSummary: { complete_count: 0, total_count: 0, pct_complete: 0 },
        preflight: null,
        arDriftOverride: false,
        closing: false,
        closeMessage: '',
        closeIsError: false,
        lastClose: null,
        loading: false,

        async init() {
            this.loading = true;
            this.closeMessage = '';
            this.lastClose = null;
            await Promise.all([this.loadChecklist(), this.loadPreflight()]);
            this.loading = false;
        },

        async loadChecklist() {
            try {
                const r = await fetch(apiBase + '/year-end-checklist/index.php?fiscal_year=' + this.fiscalYear);
                const j = await r.json();
                if (j && j.success) {
                    this.checklist = j.data.items;
                    this.checklistSummary = {
                        complete_count: j.data.complete_count,
                        total_count:    j.data.total_count,
                        pct_complete:   j.data.pct_complete,
                    };
                }
            } catch (e) { this.checklist = []; }
        },

        async loadPreflight() {
            try {
                const r = await fetch(apiBase + '/periods/year_end_preflight.php?fiscal_year=' + this.fiscalYear);
                const j = await r.json();
                if (j && j.success) this.preflight = j.data;
            } catch (e) { this.preflight = null; }
        },

        get preflightRows() {
            if (!this.preflight) return [];
            const c = this.preflight.checks;
            return [
                { key: 'periods_complete',
                  label: 'Periods complete (12 months)',
                  pass: c.periods_complete.pass,
                  is_warning: false,
                  detail: c.periods_complete.detail },
                { key: 'ar_drift',
                  label: 'AR subledger reconciled (≤ $1)',
                  pass: !c.ar_drift.is_blocking && !c.ar_drift.is_warning,
                  is_warning: c.ar_drift.is_warning,
                  detail: 'GL $' + Math.abs(parseFloat(c.ar_drift.gl_balance)).toFixed(2) +
                          ' vs subledger $' + Math.abs(parseFloat(c.ar_drift.subledger_balance)).toFixed(2) +
                          ' (drift ' + parseFloat(c.ar_drift.drift_amount).toFixed(2) + ')' +
                          (c.ar_drift.is_warning ? ' — super_admin override available' : '') },
                { key: 'ap_drift',
                  label: 'AP subledger reconciled (≤ $1)',
                  pass: !c.ap_drift.is_blocking,
                  is_warning: false,
                  detail: 'GL $' + Math.abs(parseFloat(c.ap_drift.gl_balance)).toFixed(2) +
                          ' vs subledger $' + Math.abs(parseFloat(c.ap_drift.subledger_balance)).toFixed(2) +
                          ' (drift ' + parseFloat(c.ap_drift.drift_amount).toFixed(2) + ')' },
                { key: 'unposted_jes',
                  label: 'No draft journal entries for the fiscal year',
                  pass: c.unposted_jes.pass,
                  is_warning: false,
                  detail: c.unposted_jes.count + ' draft JE(s) found' },
                { key: 'checklist_complete',
                  label: 'Checklist 100% complete',
                  pass: c.checklist_complete.pass,
                  is_warning: false,
                  detail: c.checklist_complete.incomplete_count + ' of ' + c.checklist_complete.total_count + ' items remaining' },
                { key: 'already_closed',
                  label: 'Not already closed (idempotency)',
                  pass: c.already_closed.pass,
                  is_warning: false,
                  detail: c.already_closed.closure_id ? 'Already closed — closure #' + c.already_closed.closure_id : 'No prior closure' },
            ];
        },

        canClose() {
            if (!this.preflight) return false;
            if (!this.preflight.can_proceed) return false;
            if (this.preflight.requires_super_admin_override && !this.arDriftOverride) return false;
            return true;
        },

        async toggleItem(item) {
            const newState = !(item.is_complete == 1);
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch(apiBase + '/year-end-checklist/update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ id: item.id, is_complete: newState })
                });
                const j = await r.json();
                if (j && j.success) {
                    await this.loadChecklist();
                    await this.loadPreflight();
                }
            } catch (e) { /* ignore */ }
        },

        async confirmAndClose() {
            if (!this.canClose()) return;
            const overrideNote = this.preflight.requires_super_admin_override ? ' with AR drift override' : '';
            if (!confirm('Close fiscal year ' + this.fiscalYear + overrideNote + '? This will: (1) post the closing JE; (2) lock all 12 periods; (3) create next year periods; (4) generate the package ZIP. Cannot be undone except by super_admin reversal.')) return;

            this.closing = true;
            this.closeMessage = '';
            this.closeIsError = false;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const body = { fiscal_year: this.fiscalYear };
                if (this.preflight.requires_super_admin_override && this.arDriftOverride) {
                    body.confirm_ar_drift_override = true;
                }
                const r = await fetch(apiBase + '/periods/year_end.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(body)
                });
                const j = await r.json();
                if (j && j.success) {
                    this.closeMessage = '✓ Year-end close completed.';
                    this.lastClose = j.data;
                    // Reload to refresh prior closures list (server-rendered table)
                    setTimeout(() => { window.location.href = '<?= e(base_url('accounting/year-end')) ?>?fiscal_year=' + this.fiscalYear; }, 1800);
                } else {
                    this.closeMessage = (j && j.error && j.error.message) || 'Close failed.';
                    this.closeIsError = true;
                }
            } catch (e) {
                this.closeMessage = 'Close failed: ' + e.message;
                this.closeIsError = true;
            }
            this.closing = false;
        },

        async reverseClosure(year) {
            const reason = prompt('Reason for reversing the FY ' + year + ' year-end close?');
            if (!reason || !reason.trim()) return;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch(apiBase + '/periods/year_end_reverse.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ fiscal_year: year, reason })
                });
                const j = await r.json();
                if (j && j.success) {
                    window.location.reload();
                } else {
                    alert((j && j.error && j.error.message) || 'Reverse failed.');
                }
            } catch (e) { alert('Reverse failed: ' + e.message); }
        },

        fmt(s) {
            const n = parseFloat(s);
            if (!isFinite(n)) return '$0.00';
            return (n < 0 ? '-$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
