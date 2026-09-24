<?php
declare(strict_types=1);

/**
 * app/portal/invoices/index.php
 *
 * Customer portal — Invoices (S-PORTAL-REDESIGN).
 *
 * Tabs (Open / Past due / Paid / All) with live counts, search by invoice or
 * lease number, an issued-date range, paging, and multi-select:
 *   Pay selected        → the Pay drawer ($store.checkout)
 *   Download PDFs       → api/v1/portal/invoices/zip (one ZIP)
 *   I've paid these     → payment notice to billing ($store.notice)
 *   Export CSV          → api/v1/portal/invoices/export (current tab + filters)
 *
 * Data comes from api/v1/portal/invoices/list (PT_InvoiceList in portal.js).
 * Rows stack into cards on phones (.pt-table--stack + data-label).
 *
 * Trap 8: the endpoint scopes everything to portal_customer_id();
 * visibility = pt_invoice_visible_sql() (never voids / internal drafts).
 *
 * @session S-PORTAL-REDESIGN (was: tabbed table, 100-row cap, ?ajax=1 branch)
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid      = portal_customer_id();
$summary  = pt_account_summary($cid);
$currency = $summary['currency'];

$tab = (string) ($_GET['tab'] ?? 'open');
if (!in_array($tab, ['open', 'past_due', 'paid', 'all'], true)) {
    $tab = 'open';
}

$pageTitle    = 'Invoices';
$ptHideRibbon = true;
require_once dirname(__DIR__) . '/includes/header.php';

ob_start(); ?>
    <button type="button" class="pt-btn pt-btn--secondary" x-data @click="$store.statement.show()"><?= pt_icon('document-arrow-down') ?> Statement</button>
    <?php if (bccomp($summary['outstanding'], '0', 2) > 0): ?>
        <button type="button" class="pt-btn pt-btn--primary" x-data @click="$store.checkout.start([])"><?= pt_icon('credit-card') ?> Pay balance</button>
    <?php endif; ?>
<?php
echo pt_page_head([
    'eyebrow' => 'Billing',
    'title'   => 'Invoices',
    'sub'     => 'Every invoice on your account. Select several to pay them together or download them in one go.',
    'actions' => ob_get_clean(),
]);
?>

<div class="pt-stats">
    <div class="pt-stat">
        <div class="pt-stat-top"><span class="pt-stat-label">Balance due</span><span class="pt-stat-ic pt-stat-ic--brand"><?= pt_icon('banknotes') ?></span></div>
        <div class="pt-stat-value"><?= e(pt_money($summary['outstanding'], $currency)) ?></div>
        <div class="pt-stat-sub"><?= $summary['open_count'] ?> open invoice<?= $summary['open_count'] === 1 ? '' : 's' ?></div>
    </div>
    <div class="pt-stat">
        <div class="pt-stat-top"><span class="pt-stat-label">Past due</span><span class="pt-stat-ic <?= $summary['past_due_count'] ? 'pt-stat-ic--danger' : '' ?>"><?= pt_icon('exclamation-triangle') ?></span></div>
        <div class="pt-stat-value<?= $summary['past_due_count'] ? ' pt-danger-ink' : '' ?>"><?= e(pt_money($summary['past_due'], $currency)) ?></div>
        <div class="pt-stat-sub"><?= $summary['past_due_count'] ? $summary['past_due_count'] . ' invoice' . ($summary['past_due_count'] === 1 ? '' : 's') . ($summary['oldest_past_due_days'] ? ' · oldest ' . $summary['oldest_past_due_days'] . 'd' : '') : 'Nothing late' ?></div>
    </div>
    <div class="pt-stat">
        <div class="pt-stat-top"><span class="pt-stat-label">Due next 7 days</span><span class="pt-stat-ic pt-stat-ic--warning"><?= pt_icon('clock') ?></span></div>
        <div class="pt-stat-value"><?= e(pt_money($summary['due_soon'], $currency)) ?></div>
        <div class="pt-stat-sub"><?= $summary['next_due_date'] ? 'Next due ' . e(format_date($summary['next_due_date'])) : 'Nothing coming up' ?></div>
    </div>
    <div class="pt-stat">
        <div class="pt-stat-top"><span class="pt-stat-label">Credit on account</span><span class="pt-stat-ic pt-stat-ic--success"><?= pt_icon('receipt-percent') ?></span></div>
        <div class="pt-stat-value"><?= e(pt_money($summary['credit'], $currency)) ?></div>
        <div class="pt-stat-sub"><?= bccomp($summary['credit'], '0', 2) > 0 ? 'Ask us to apply it' : 'No credits' ?></div>
    </div>
</div>

<section class="pt-card" x-data="PT_InvoiceList(<?= e(json_encode(['tab' => $tab])) ?>)">
    <div class="pt-toolbar">
        <div class="pt-tabs" role="tablist" aria-label="Invoice status">
            <?php foreach (['open' => 'Open', 'past_due' => 'Past due', 'paid' => 'Paid', 'all' => 'All'] as $k => $label): ?>
                <button type="button" class="pt-tab" role="tab" :class="{ 'is-active': tab === '<?= $k ?>' }" :aria-selected="tab === '<?= $k ?>'" @click="tab = '<?= $k ?>'">
                    <?= e($label) ?> <span class="pt-tab-count" x-text="counts.<?= $k ?> ?? ''"></span>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="pt-toolbar-spacer"></div>
        <label class="pt-search-field">
            <?= pt_icon('magnifying-glass') ?>
            <span class="pt-sr">Search invoices</span>
            <input type="search" class="pt-input" placeholder="Invoice or lease #" x-model="q" @input="debounced()">
        </label>
        <div class="pt-daterange" title="Issued between">
            <input type="date" class="pt-input" x-model="from" @change="page = 1; load()" aria-label="Issued from">
            <span class="pt-faint" aria-hidden="true">–</span>
            <input type="date" class="pt-input" x-model="to" @change="page = 1; load()" aria-label="Issued to">
        </div>
        <button type="button" class="pt-btn pt-btn--ghost pt-btn--sm" x-show="hasFilters" x-cloak @click="clearFilters()">Clear</button>
        <a class="pt-btn pt-btn--secondary pt-btn--sm" :href="csvHref" title="Download this list as a spreadsheet"><?= pt_icon('table-cells') ?> CSV</a>
    </div>

    <div class="pt-table-wrap">
        <table data-no-auto-label class="pt-table pt-table--stack">
            <thead>
                <tr>
                    <th class="shrink"><input type="checkbox" class="pt-check" :checked="allSel" :indeterminate="someSel" @change="toggleAll()" aria-label="Select all on this page"></th>
                    <th>Invoice</th>
                    <th>Issued</th>
                    <th>Due</th>
                    <th>Status</th>
                    <th class="num">Total</th>
                    <th class="num">Balance</th>
                    <th class="shrink"><span class="pt-sr">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading && rows.length === 0">
                    <tr><td colspan="8" style="padding:0"><div class="pt-skel-row"><div class="pt-skel" style="width:30%"></div><div class="pt-skel" style="width:20%"></div><div class="pt-skel" style="width:15%"></div></div><div class="pt-skel-row"><div class="pt-skel" style="width:26%"></div><div class="pt-skel" style="width:18%"></div><div class="pt-skel" style="width:12%"></div></div><div class="pt-skel-row" style="border:0"><div class="pt-skel" style="width:32%"></div><div class="pt-skel" style="width:16%"></div></div></td></tr>
                </template>
                <template x-for="r in rows" :key="r.id">
                    <tr :class="{ 'is-selected': isSel(r.id) }" :style="loading ? 'opacity:.55' : ''">
                        <td class="shrink pt-cell-check"><input type="checkbox" class="pt-check" :checked="isSel(r.id)" @change="toggle(r.id)" :aria-label="'Select invoice ' + r.number"></td>
                        <td class="pt-cell-primary">
                            <a class="pt-table-main" :href="viewUrl(r.id)" x-text="r.number"></a>
                            <span class="pt-table-sub" x-text="[r.period, r.lease ? 'Lease ' + r.lease : ''].filter(Boolean).join(' · ') || '—'"></span>
                        </td>
                        <td class="nw" data-label="Issued" x-text="PT.date(r.invoice_date)"></td>
                        <td class="nw" data-label="Due">
                            <span x-text="PT.date(r.due_date)"></span>
                            <span class="pt-table-sub pt-danger-ink" x-show="r.days_late > 0" x-text="r.days_late + (r.days_late === 1 ? ' day' : ' days') + ' late'"></span>
                        </td>
                        <td data-label="Status"><span class="pt-pill" :class="'pt-pill--' + r.status_tone" x-text="r.status_label"></span></td>
                        <td class="num" data-label="Total" x-text="r.total_fmt"></td>
                        <td class="num" data-label="Balance"><strong x-text="r.payable ? r.balance_fmt : '—'"></strong></td>
                        <td class="shrink pt-cell-actions">
                            <div style="display:flex;gap:6px;justify-content:flex-end">
                                <button type="button" class="pt-btn pt-btn--soft pt-btn--sm" x-show="r.payable" @click="$store.checkout.start([r.id])">Pay</button>
                                <a class="pt-btn pt-btn--ghost pt-btn--sm" :href="pdfUrl(r.id)" target="_blank" rel="noopener" :aria-label="'PDF of invoice ' + r.number" title="PDF"><?= pt_icon('arrow-down-tray') ?></a>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <template x-if="!loading && rows.length === 0">
        <div>
            <div x-show="hasFilters"><?= pt_empty('magnifying-glass', 'No invoices match', 'Try a different number or date range.', '<button type="button" class="pt-btn pt-btn--secondary pt-btn--sm" @click="clearFilters()">Clear filters</button>') ?></div>
            <div x-show="!hasFilters && (tab === 'open' || tab === 'past_due')"><?= pt_empty('check-circle', 'You\'re all caught up', 'There are no open invoices on your account.') ?></div>
            <div x-show="!hasFilters && !(tab === 'open' || tab === 'past_due')"><?= pt_empty('document-text', 'No invoices yet', 'Invoices will appear here as soon as they\'re issued.') ?></div>
        </div>
    </template>

    <div class="pt-card-foot" x-show="total > perPage" x-cloak>
        <span x-text="'Showing ' + ((page - 1) * perPage + 1) + '–' + Math.min(page * perPage, total) + ' of ' + total"></span>
        <div class="pt-btn-row">
            <button type="button" class="pt-btn pt-btn--secondary pt-btn--sm" :disabled="page <= 1" @click="go(page - 1)"><?= pt_icon('chevron-left') ?> Previous</button>
            <button type="button" class="pt-btn pt-btn--secondary pt-btn--sm" :disabled="page >= pages" @click="go(page + 1)">Next <?= pt_icon('chevron-right') ?></button>
        </div>
    </div>

    <!-- Selection bar -->
    <div class="pt-selbar" x-show="selected.length > 0" x-cloak
         x-transition:enter="pt-slide-up-enter" x-transition:enter-start="pt-slide-up-enter-start" x-transition:enter-end="pt-slide-up-enter-end"
         x-transition:leave="pt-slide-up-leave" x-transition:leave-start="pt-slide-up-leave-start" x-transition:leave-end="pt-slide-up-leave-end">
        <div>
            <div class="pt-selbar-count" x-text="selected.length + ' selected'"></div>
            <div class="pt-selbar-sum" x-text="selPayable.length ? selSum : 'Nothing due on these'"></div>
        </div>
        <div class="pt-selbar-actions">
            <button type="button" class="pt-btn pt-btn--primary pt-btn--sm" x-show="selPayable.length" @click="paySelected()"><?= pt_icon('credit-card') ?> Pay</button>
            <a class="pt-btn pt-btn--secondary pt-btn--sm" :href="zipHref"><?= pt_icon('arrow-down-tray') ?> PDFs</a>
            <button type="button" class="pt-btn pt-btn--secondary pt-btn--sm" x-show="selPayable.length" @click="reportSelected()"><?= pt_icon('paper-airplane') ?> I've paid these</button>
            <button type="button" class="pt-icon-btn" style="width:32px;height:32px" @click="selected = []" aria-label="Clear selection"><?= pt_icon('x-mark', 'pt-ic pt-ic--sm') ?></button>
        </div>
    </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
