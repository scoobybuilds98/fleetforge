<?php declare(strict_types=1);

/**
 * FleetForge — Accounting Dashboard
 *
 * @file        app/admin/accounting/dashboard/index.php
 * @description Comprehensive accounting dashboard with 6 server-side KPI tiles
 *              (Current Period, Revenue YTD, Expenses YTD, Net Income YTD,
 *              AR Balance, AP Balance), fiscal period status bar, recent journal
 *              entries table (Alpine via API), alerts panel (AR/AP reconciliation,
 *              unposted drafts, periods needing close), and quick action links.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, lib/Accounting/AccountingService.php,
 *              api/v1/accounting/journal_entries/index.php
 * @session     S029
 */

// dirname(__DIR__, 4): dashboard/ → accounting/ → admin/ → app/ → root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

use FleetForge\Accounting\AccountingService;

// ── KPI Tiles — server-side for accuracy ─────────────────────────
// WHY: Accounting figures must be exact on page load, not async approximations.

// 1. Current open period
$currentPeriod = AccountingService::currentOpenPeriod();

// 2. YTD Revenue — sum credits minus debits on revenue-type accounts (credit-normal)
$ytdRevenue = db_row(
    "SELECT COALESCE(SUM(jel.credit) - SUM(jel.debit), 0) AS total
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     JOIN acc_accounts a ON a.id = jel.account_id
     WHERE je.status = 'posted'
       AND a.account_type = 'revenue'
       AND YEAR(je.entry_date) = YEAR(CURDATE())",
    []
);

// 3. YTD Expenses — sum debits minus credits on expense-type accounts (debit-normal)
// WHY: Expense accounts include both operating_expense and cost_of_revenue types
$ytdExpenses = db_row(
    "SELECT COALESCE(SUM(jel.debit) - SUM(jel.credit), 0) AS total
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     JOIN acc_accounts a ON a.id = jel.account_id
     WHERE je.status = 'posted'
       AND a.account_type IN ('operating_expense', 'cost_of_revenue', 'other_expense')
       AND YEAR(je.entry_date) = YEAR(CURDATE())",
    []
);

$revenueTotal  = $ytdRevenue['total'] ?? '0.00';
$expenseTotal  = $ytdExpenses['total'] ?? '0.00';
$netIncomeYtd  = bcsub($revenueTotal, $expenseTotal, 2);

// 4. AR Balance — from the configured AR control account
$arAccountId = AccountingService::setting('accounting.ar_account_id');
$arBalance   = $arAccountId ? AccountingService::accountBalance((int) $arAccountId) : '0.00';

// 5. AP Balance — from the configured AP control account
$apAccountId = AccountingService::setting('accounting.ap_account_id');
$apBalance   = $apAccountId ? AccountingService::accountBalance((int) $apAccountId) : '0.00';

// 6. Alerts data — computed server-side for accuracy
$arRecon = AccountingService::arReconciliationCheck();
$apRecon = AccountingService::apReconciliationCheck();

// Draft JE count
$draftCount = db_count(
    "SELECT COUNT(*) FROM acc_journal_entries WHERE status = 'draft'"
);

// Periods needing close (past months still open)
$periodsNeedingClose = db_count(
    "SELECT COUNT(*)
     FROM acc_periods
     WHERE status = 'open'
       AND end_date < CURDATE()"
);

// All periods for current year — for period status bar
$yearPeriods = db_select(
    "SELECT id, year, month, name, status
     FROM acc_periods
     WHERE year = YEAR(CURDATE())
     ORDER BY month ASC",
    []
);

$pageTitle = 'Accounting Dashboard';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────── -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Accounting Dashboard</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Accounting Dashboard</h1>
    <div class="page-header-actions">
        <?php if (can('journal_entries', 'create')): ?>
        <a href="<?= base_url('accounting/journal-entries') ?>" class="btn btn-primary btn-sm">
            + New Journal Entry
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
// WHY: Top navigation bar for the accounting section — makes key pages visible
// at a glance instead of buried in the sidebar with 10+ other items.
require_once FF_ROOT . '/includes/partials/accounting-nav.php';
?>

<!-- ============================================================
     KPI Stat Cards — 6 tiles, server-rendered for accuracy
     Each tile is a real <a> drilldown so accounting tiles behave
     like every other module's clickable KPI tiles. URLs map to
     the most relevant deep-link for that figure.
     ============================================================ -->
<div class="stat-grid">

    <!-- Current Period → Manage Periods -->
    <a href="<?= base_url('accounting/periods') ?>"
       class="stat-card stat-card--link stat-card--blue"
       aria-label="Current Period — open Manage Periods">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Current Period</div>
        <div class="stat-value">
            <?php if ($currentPeriod): ?>
                <?= e($currentPeriod['name']) ?>
            <?php else: ?>
                <span class="text-secondary">None open</span>
            <?php endif; ?>
        </div>
        <div class="stat-delta">
            <?php if ($currentPeriod): ?>
                <span class="badge badge-no-dot badge-success">Open</span>
            <?php else: ?>
                <span class="badge badge-no-dot badge-warning">No open period</span>
            <?php endif; ?>
        </div>
    </a>

    <!-- Revenue YTD → Income Statement -->
    <a href="<?= base_url('accounting/statements') ?>"
       class="stat-card stat-card--link stat-card--green"
       aria-label="Total Revenue YTD — open income statement">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Total Revenue YTD</div>
        <div class="stat-value currency font-mono">$<?= e(number_format((float)$revenueTotal, 2)) ?></div>
        <div class="stat-delta text-secondary">Posted journal entries</div>
    </a>

    <!-- Expenses YTD → Income Statement -->
    <a href="<?= base_url('accounting/statements') ?>"
       class="stat-card stat-card--link stat-card--red"
       aria-label="Total Expenses YTD — open income statement">
        <span class="stat-icon stat-icon--red"><svg><use href="#icon-chart-bar"/></svg></span>
        <div class="stat-label">Total Expenses YTD</div>
        <div class="stat-value currency font-mono">$<?= e(number_format((float)$expenseTotal, 2)) ?></div>
        <div class="stat-delta text-secondary">Posted journal entries</div>
    </a>

    <!-- Net Income YTD → Income Statement -->
    <a href="<?= base_url('accounting/statements') ?>"
       class="stat-card stat-card--link stat-card--<?= bccomp($netIncomeYtd, '0.00', 2) >= 0 ? 'green' : 'red' ?>"
       aria-label="Net Income YTD — open income statement">
        <span class="stat-icon stat-icon--<?= bccomp($netIncomeYtd, '0.00', 2) >= 0 ? 'green' : 'red' ?>">
            <svg><use href="#icon-document-text"/></svg>
        </span>
        <div class="stat-label">Net Income YTD</div>
        <div class="stat-value currency font-mono<?= bccomp($netIncomeYtd, '0.00', 2) < 0 ? ' text-danger' : '' ?>">
            <?php
            $absNet = bccomp($netIncomeYtd, '0.00', 2) < 0
                ? '-$' . number_format(abs((float)$netIncomeYtd), 2)
                : '$' . number_format((float)$netIncomeYtd, 2);
            echo e($absNet);
            ?>
        </div>
        <div class="stat-delta text-secondary">Revenue minus expenses</div>
    </a>

    <!-- AR Balance → AR Aging -->
    <a href="<?= base_url('accounting/ar-aging') ?>"
       class="stat-card stat-card--link stat-card--amber"
       aria-label="AR Balance — open AR aging report">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">AR Balance</div>
        <div class="stat-value currency font-mono">
            <?php
            $absAr = bccomp($arBalance, '0.00', 2) < 0
                ? '-$' . number_format(abs((float)$arBalance), 2)
                : '$' . number_format((float)$arBalance, 2);
            echo e($absAr);
            ?>
        </div>
        <div class="stat-delta text-secondary">Accounts receivable</div>
    </a>

    <!-- AP Balance → AP Aging -->
    <a href="<?= base_url('accounting/ap-aging') ?>"
       class="stat-card stat-card--link stat-card--amber"
       aria-label="AP Balance — open AP aging report">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-exclamation-triangle"/></svg></span>
        <div class="stat-label">AP Balance</div>
        <div class="stat-value currency font-mono">
            <?php
            $absAp = bccomp($apBalance, '0.00', 2) < 0
                ? '-$' . number_format(abs((float)$apBalance), 2)
                : '$' . number_format((float)$apBalance, 2);
            echo e($absAp);
            ?>
        </div>
        <div class="stat-delta text-secondary">Accounts payable</div>
    </a>

</div>

<!-- ============================================================
     Period Status Bar — color-coded months for current fiscal year
     ============================================================ -->
<div class="card" style="margin-bottom:24px;padding:16px 20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <h3 class="h6" style="margin:0;">Fiscal Period Status — <?= e((string)date('Y')) ?></h3>
        <?php if (can('accounting', 'edit')): ?>
        <a href="<?= base_url('accounting/periods') ?>" class="btn btn-ghost btn-xs">Manage Periods</a>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:4px;">
        <?php
        // WHY: Build all 12 months, matching to DB periods where available.
        // Months without a period row show as "not started" (grey).
        $monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $periodsByMonth = [];
        foreach ($yearPeriods as $p) {
            $periodsByMonth[(int)$p['month']] = $p;
        }

        foreach ($monthLabels as $idx => $label):
            $month = $idx + 1;
            $period = $periodsByMonth[$month] ?? null;
            $status = $period ? $period['status'] : 'not_started';

            // Color mapping: open=yellow, closed=green, locked=red, not_started=grey
            if ($status === 'open') {
                $bg    = 'var(--color-warning)';
                $color = 'var(--color-warning-text, #000)';
            } elseif ($status === 'closed') {
                $bg    = 'var(--color-success)';
                $color = 'var(--color-success-text, #fff)';
            } elseif ($status === 'locked') {
                $bg    = 'var(--color-danger)';
                $color = 'var(--color-danger-text, #fff)';
            } else {
                $bg    = 'var(--bg-surface-2)';
                $color = 'var(--text-secondary)';
            }
        ?>
        <div style="flex:1;text-align:center;">
            <div style="height:28px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;background:<?= $bg ?>;color:<?= $color ?>;<?= $status === 'not_started' ? 'border:1px solid var(--border-color);' : '' ?>"
                 title="<?= e($label . ' ' . date('Y') . ': ' . $status) ?>">
                <?= e($label) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:16px;margin-top:8px;font-size:12px;">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--color-warning);margin-right:4px;"></span> Open</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--color-success);margin-right:4px;"></span> Closed</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--color-danger);margin-right:4px;"></span> Locked</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--bg-surface-2);margin-right:4px;border:1px solid var(--border-color);"></span> Not Started</span>
    </div>
</div>

<!-- ============================================================
     ACCOUNTING DASHBOARD — ALPINE COMPONENT
     Handles: recent JEs table, quick-post, interactive elements.
     KPIs and alerts are server-rendered above and below.
     ============================================================ -->
<div x-data="FF_AccDashboard()" x-init="init()">

    <!-- ── Two-Column Layout: Recent JEs + Alerts / Quick Actions ── -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

        <!-- Left: Recent Journal Entries -->
        <div class="card" style="padding:0;">
            <div style="padding:16px 20px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;">
                <h3 class="h6" style="margin:0;">Recent Journal Entries</h3>
                <a href="<?= base_url('accounting/journal-entries') ?>" class="btn btn-ghost btn-xs">View All</a>
            </div>

            <!-- Loading skeleton -->
            <template x-if="loading">
                <div style="padding:16px;">
                    <template x-for="n in 5" :key="n">
                        <div class="skeleton skeleton-row"></div>
                    </template>
                </div>
            </template>

            <!-- Error state -->
            <template x-if="!loading && loadError">
                <div class="empty-state" style="padding:32px;">
                    <p class="empty-state-title">Failed to load journal entries</p>
                    <p class="empty-state-text" x-text="loadError"></p>
                    <button class="btn btn-secondary btn-sm" @click="loadRecentEntries()">Retry</button>
                </div>
            </template>

            <!-- Empty state -->
            <template x-if="!loading && !loadError && recentEntries.length === 0">
                <div class="empty-state" style="padding:32px;">
                    <p class="empty-state-title">No journal entries yet</p>
                    <p class="empty-state-text">Journal entries will appear here once created.</p>
                </div>
            </template>

            <!-- Table -->
            <template x-if="!loading && !loadError && recentEntries.length > 0">
                <div style="overflow-x:auto;">
                    <table class="table" aria-label="Recent Journal Entries">
                        <thead>
                            <tr>
                                <th scope="col">Entry #</th>
                                <th scope="col">Date</th>
                                <th scope="col">Description</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-right">Amount</th>
                                <th scope="col" style="width:1%;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="je in recentEntries" :key="je.id">
                                <tr>
                                    <td>
                                        <a :href="'<?= base_url('accounting/journal-entries') ?>?id=' + je.id"
                                           class="font-mono font-medium link"
                                           x-text="je.entry_number"></a>
                                    </td>
                                    <td class="text-sm" x-text="formatDate(je.entry_date)"></td>
                                    <td class="text-sm" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        x-text="je.description || '—'"></td>
                                    <td>
                                        <span class="badge badge-no-dot"
                                              :class="jeBadge(je.status)"
                                              x-text="capitalize(je.status)"></span>
                                    </td>
                                    <td class="font-mono text-sm text-right"
                                        x-text="fmt(je._total)"></td>
                                    <td style="white-space:nowrap;">
                                        <?php if (can('journal_entries', 'edit')): ?>
                                        <button x-show="je.status === 'draft'"
                                                class="btn btn-primary btn-xs"
                                                @click="quickPost(je)"
                                                :disabled="je._posting">
                                            <span x-show="!je._posting">Post</span>
                                            <span x-show="je._posting">...</span>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <!-- Right Column: Alerts + Quick Actions -->
        <div>

            <!-- Alerts Panel -->
            <div class="card" style="padding:0;margin-bottom:24px;">
                <div style="padding:16px 20px;border-bottom:1px solid var(--border-color);">
                    <h3 class="h6" style="margin:0;">Alerts</h3>
                </div>
                <div style="padding:12px 20px;">

                    <!-- AR Reconciliation -->
                    <div style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;">
                        <?php if ($arRecon['is_reconciled']): ?>
                        <span class="badge badge-no-dot badge-success" style="flex-shrink:0;margin-top:2px;">
                            <svg style="width:12px;height:12px;"><use href="#icon-check-circle"/></svg>
                        </span>
                        <div>
                            <div class="text-sm font-medium">AR Reconciled</div>
                            <div class="text-secondary text-sm">GL matches subledger</div>
                        </div>
                        <?php else: ?>
                        <span class="badge badge-no-dot badge-danger" style="flex-shrink:0;margin-top:2px;">!</span>
                        <div>
                            <div class="text-sm font-medium text-danger">AR Out of Balance</div>
                            <div class="text-secondary text-sm">
                                Difference: $<?= e(number_format(abs((float)$arRecon['difference']), 2)) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- AP Reconciliation -->
                    <div style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-top:1px solid var(--border-color);">
                        <?php if ($apRecon['is_reconciled']): ?>
                        <span class="badge badge-no-dot badge-success" style="flex-shrink:0;margin-top:2px;">
                            <svg style="width:12px;height:12px;"><use href="#icon-check-circle"/></svg>
                        </span>
                        <div>
                            <div class="text-sm font-medium">AP Reconciled</div>
                            <div class="text-secondary text-sm">GL matches subledger</div>
                        </div>
                        <?php else: ?>
                        <span class="badge badge-no-dot badge-danger" style="flex-shrink:0;margin-top:2px;">!</span>
                        <div>
                            <div class="text-sm font-medium text-danger">AP Out of Balance</div>
                            <div class="text-secondary text-sm">
                                Difference: $<?= e(number_format(abs((float)$apRecon['difference']), 2)) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Unposted Drafts -->
                    <div style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-top:1px solid var(--border-color);">
                        <?php if ($draftCount === 0): ?>
                        <span class="badge badge-no-dot badge-success" style="flex-shrink:0;margin-top:2px;">
                            <svg style="width:12px;height:12px;"><use href="#icon-check-circle"/></svg>
                        </span>
                        <div>
                            <div class="text-sm font-medium">No Unposted Drafts</div>
                            <div class="text-secondary text-sm">All entries are posted</div>
                        </div>
                        <?php else: ?>
                        <span class="badge badge-no-dot badge-warning" style="flex-shrink:0;margin-top:2px;">!</span>
                        <div>
                            <div class="text-sm font-medium"><?= e((string)$draftCount) ?> Unposted Draft<?= $draftCount !== 1 ? 's' : '' ?></div>
                            <div class="text-secondary text-sm">
                                <a href="<?= base_url('accounting/journal-entries') ?>" class="link">Review drafts</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Periods Needing Close -->
                    <div style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-top:1px solid var(--border-color);">
                        <?php if ($periodsNeedingClose === 0): ?>
                        <span class="badge badge-no-dot badge-success" style="flex-shrink:0;margin-top:2px;">
                            <svg style="width:12px;height:12px;"><use href="#icon-check-circle"/></svg>
                        </span>
                        <div>
                            <div class="text-sm font-medium">Periods Up to Date</div>
                            <div class="text-secondary text-sm">No past periods need closing</div>
                        </div>
                        <?php else: ?>
                        <span class="badge badge-no-dot badge-warning" style="flex-shrink:0;margin-top:2px;">!</span>
                        <div>
                            <div class="text-sm font-medium"><?= e((string)$periodsNeedingClose) ?> Period<?= $periodsNeedingClose !== 1 ? 's' : '' ?> Need Closing</div>
                            <div class="text-secondary text-sm">
                                <a href="<?= base_url('accounting/periods') ?>" class="link">Manage periods</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card" style="padding:0;">
                <div style="padding:16px 20px;border-bottom:1px solid var(--border-color);">
                    <h3 class="h6" style="margin:0;">Quick Actions</h3>
                </div>
                <div style="padding:8px 12px;">
                    <?php if (can('journal_entries', 'create')): ?>
                    <a href="<?= base_url('accounting/journal-entries') ?>"
                       class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;text-align:left;">
                        <svg style="width:16px;height:16px;margin-right:8px;flex-shrink:0;"><use href="#icon-document-text"/></svg>
                        New Journal Entry
                    </a>
                    <?php endif; ?>
                    <a href="<?= base_url('accounting/chart-of-accounts') ?>"
                       class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;text-align:left;">
                        <svg style="width:16px;height:16px;margin-right:8px;flex-shrink:0;"><use href="#icon-chart-bar"/></svg>
                        View Trial Balance
                    </a>
                    <?php if (can('accounting', 'edit')): ?>
                    <a href="<?= base_url('accounting/periods') ?>"
                       class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;text-align:left;">
                        <svg style="width:16px;height:16px;margin-right:8px;flex-shrink:0;"><use href="#icon-clock"/></svg>
                        Close Period
                    </a>
                    <?php endif; ?>
                    <a href="<?= base_url('accounting/chart-of-accounts') ?>"
                       class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;text-align:left;">
                        <svg style="width:16px;height:16px;margin-right:8px;flex-shrink:0;"><use href="#icon-currency-dollar"/></svg>
                        Chart of Accounts
                    </a>
                    <a href="<?= base_url('accounting/settings') ?>"
                       class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;text-align:left;">
                        <svg style="width:16px;height:16px;margin-right:8px;flex-shrink:0;"><use href="#icon-currency-dollar"/></svg>
                        Accounting Settings
                    </a>
                    <a href="<?= base_url('invoices') ?>"
                       class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;text-align:left;">
                        <svg style="width:16px;height:16px;margin-right:8px;flex-shrink:0;"><use href="#icon-document-text"/></svg>
                        Invoices (AR)
                    </a>
                </div>
            </div>

        </div>

    </div>

</div><!-- /x-data -->

<style>
/* WHY: Responsive fallback — stack columns on narrow screens */
@media (max-width: 900px) {
    div[style*="grid-template-columns:2fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
/**
 * FF_AccDashboard — Alpine component for the Accounting Dashboard.
 * Fetches recent journal entries via API and provides quick-post.
 * KPIs and alerts are rendered server-side (above) for accuracy.
 */
function FF_AccDashboard() {
    return {
        loading:       true,
        loadError:     null,
        recentEntries: [],

        async init() {
            await this.loadRecentEntries();
        },

        // ── Fetch recent JEs from API ─────────────────────────────
        async loadRecentEntries() {
            this.loading   = true;
            this.loadError = null;

            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/journal_entries') ?>?per_page=10&sort=entry_date&dir=DESC');
                if (r.success) {
                    // WHY: The list API does not include line totals per entry.
                    // Fetch each entry's total via a lightweight query of lines.
                    // For dashboard display, we show the total debits (= total credits
                    // on balanced entries) as the "amount".
                    this.recentEntries = (r.data.items || []).map(je => ({
                        ...je,
                        _posting: false,
                        _total:   '0.00'
                    }));

                    // WHY: Load totals for visible entries in parallel for performance.
                    await this._loadEntryTotals();
                } else {
                    this.loadError = r.message || 'Failed to load journal entries.';
                }
            } catch(e) {
                this.loadError = 'Network error. Please try again.';
            }
            this.loading = false;
        },

        /**
         * Fetch line totals for each recent entry.
         * WHY: The index endpoint returns entry metadata but not totals.
         * We fetch each entry's detail to get the sum of debit lines.
         */
        async _loadEntryTotals() {
            const promises = this.recentEntries.map(async (je) => {
                try {
                    const r = await FF_Api.get('<?= base_url('api/v1/accounting/journal_entries') ?>/' + je.id);
                    if (r.success && r.data && r.data.lines) {
                        let total = 0;
                        for (const line of r.data.lines) {
                            total += parseFloat(line.debit || 0);
                        }
                        je._total = total.toFixed(2);
                    }
                } catch(e) {
                    // WHY: Non-critical — show $0.00 if individual fetch fails
                }
            });
            await Promise.all(promises);
        },

        // ── Quick-post a draft JE ─────────────────────────────────
        async quickPost(je) {
            je._posting = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/journal_entries') ?>/' + je.id + '/post', {});
                if (r.success) {
                    je.status = 'posted';
                    FF_Toast.success('Journal entry ' + je.entry_number + ' posted.');
                } else {
                    FF_Toast.error(r.message || 'Failed to post journal entry.');
                }
            } catch(e) {
                FF_Toast.error('Network error. Please try again.');
            }
            je._posting = false;
        },

        // ── Display helpers ───────────────────────────────────────
        fmt(n) {
            const val = parseFloat(n);
            if (isNaN(val)) return '$0.00';
            const abs = Math.abs(val);
            const formatted = abs.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return (val < 0 ? '-$' : '$') + formatted;
        },

        formatDate(d) {
            if (!d) return '—';
            const dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('en-CA', { year:'numeric', month:'short', day:'numeric' });
        },

        capitalize(s) {
            return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
        },

        jeBadge(status) {
            const map = {
                draft:    'badge-neutral',
                posted:   'badge-success',
                reversed: 'badge-warning',
                void:     'badge-danger'
            };
            return map[status] || 'badge-neutral';
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
