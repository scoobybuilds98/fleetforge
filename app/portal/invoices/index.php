<?php
declare(strict_types=1);

/**
 * app/portal/invoices/index.php
 *
 * Portal invoice list — Outstanding / Paid / All tabs.
 * Trap 8: all queries filter by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();

// ── AJAX handler (must run before header to avoid HTML output) ──
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');

    $tab = clean_string($_GET['tab'] ?? 'outstanding');

    $where  = ['i.customer_id = ?', 'i.deleted_at IS NULL'];
    $params = [$cid];

    if ($tab === 'outstanding') {
        $where[] = "i.status IN ('sent','partially_paid','overdue')";
    } elseif ($tab === 'paid') {
        $where[] = "i.status = 'paid'";
    }

    $whereSQL = implode(' AND ', $where);
    $orderCol = $tab === 'outstanding' ? 'i.due_date ASC' : 'i.invoice_date DESC';

    $rows = db_select(
        "SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
                i.total_amount, i.balance_due, i.status,
                (i.total_amount - i.balance_due) AS paid_amount
         FROM invoices i
         WHERE {$whereSQL}
         ORDER BY {$orderCol}
         LIMIT 100",
        $params
    );

    foreach ($rows as &$r) {
        $r['invoice_date_fmt'] = format_date($r['invoice_date']);
        $r['due_date_fmt']     = format_date($r['due_date']);
        $r['total_amount_fmt'] = format_currency($r['total_amount']);
        $r['balance_due_fmt']  = format_currency($r['balance_due']);
        $r['paid_fmt']         = format_currency($r['paid_amount']);
    }

    $counts = [
        'outstanding' => db_count("SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND status IN ('sent','partially_paid','overdue') AND deleted_at IS NULL", [$cid]),
        'paid'        => db_count("SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND status = 'paid' AND deleted_at IS NULL", [$cid]),
        'all'         => db_count("SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND deleted_at IS NULL AND status != 'draft'", [$cid]),
    ];

    echo json_encode(['success' => true, 'data' => ['invoices' => $rows, 'counts' => $counts]]);
    exit;
}

// Total outstanding (shown prominently)
$outstandingTotal = db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total
     FROM invoices WHERE customer_id = ? AND status IN ('sent','partially_paid','overdue') AND deleted_at IS NULL",
    [$cid]
)['total'] ?? '0.00';

$pageTitle = 'Invoices';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div x-data="PortalInvoices()" x-init="load()" x-cloak>

    <!-- Outstanding total -->
    <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:500;margin-bottom:4px;">Total Outstanding</div>
            <div style="font-size:1.75rem;font-weight:700;color:var(--text-primary);font-family:'DM Mono',monospace;"><?= e(format_currency($outstandingTotal)) ?></div>
        </div>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">
            Payment instructions are available on each invoice detail page.
        </div>
    </div>

    <!-- Tabs -->
    <div class="portal-tabs">
        <button class="portal-tab-btn" :class="{ 'is-active': tab === 'outstanding' }" @click="tab = 'outstanding'; load()">
            Outstanding <span class="portal-tab-count" x-show="counts.outstanding > 0" x-text="counts.outstanding"></span>
        </button>
        <button class="portal-tab-btn" :class="{ 'is-active': tab === 'paid' }" @click="tab = 'paid'; load()">
            Paid <span class="portal-tab-count" x-show="counts.paid > 0" x-text="counts.paid"></span>
        </button>
        <button class="portal-tab-btn" :class="{ 'is-active': tab === 'all' }" @click="tab = 'all'; load()">
            All <span class="portal-tab-count" x-show="counts.all > 0" x-text="counts.all"></span>
        </button>
    </div>

    <!-- Table -->
    <div class="portal-section">
        <div class="portal-section-body--flush">
            <template x-if="loading">
                <div class="portal-empty"><p class="portal-empty-text">Loading invoices...</p></div>
            </template>
            <template x-if="!loading && invoices.length === 0">
                <div class="portal-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <p class="portal-empty-title" x-text="tab === 'outstanding' ? 'All caught up!' : 'No invoices found'"></p>
                    <p class="portal-empty-text" x-text="tab === 'outstanding' ? 'No outstanding invoices at the moment.' : 'No invoices match this filter.'"></p>
                </div>
            </template>
            <table class="portal-table" x-show="!loading && invoices.length > 0">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Due Date</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="inv in invoices" :key="inv.id">
                        <tr>
                            <td><a :href="viewUrl(inv.id)" class="portal-table-link" x-text="inv.invoice_number"></a></td>
                            <td class="font-mono" x-text="inv.invoice_date_fmt"></td>
                            <td class="font-mono" :style="inv.status === 'overdue' ? 'color:var(--color-danger);font-weight:600' : ''" x-text="inv.due_date_fmt"></td>
                            <td class="text-right font-mono" x-text="inv.total_amount_fmt"></td>
                            <td class="text-right font-mono" x-text="inv.paid_fmt"></td>
                            <td class="text-right font-mono" style="font-weight:600;" x-text="inv.balance_due_fmt"></td>
                            <td><span class="badge" :class="badgeClass(inv.status)" x-text="statusLabel(inv.status)"></span></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function PortalInvoices() {
    return {
        tab: new URLSearchParams(location.search).get('tab') || 'outstanding',
        invoices: [],
        loading: true,
        counts: { outstanding: 0, paid: 0, all: 0 },

        load() {
            this.loading = true;
            FF_Api.get(FF_Api.url('/portal/invoices/index.php?ajax=1&tab=' + this.tab))
                .then(d => {
                    if (d.success) {
                        this.invoices = d.data.invoices || [];
                        this.counts = d.data.counts || this.counts;
                    }
                    this.loading = false;
                }).catch(() => { this.loading = false; });
        },

        viewUrl(id) { return (window.FF_BASE_PATH || '') + '/portal/invoices/view?id=' + id; },
        badgeClass(s) {
            return { paid: 'badge-success', overdue: 'badge-danger', sent: 'badge-info', partially_paid: 'badge-warning', void: 'badge-neutral', draft: 'badge-neutral' }[s] || 'badge-neutral';
        },
        statusLabel(s) { return s.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase()); },
    };
}
</script>


<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
