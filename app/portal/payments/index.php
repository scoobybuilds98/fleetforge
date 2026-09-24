<?php
declare(strict_types=1);

/**
 * app/portal/payments/index.php
 *
 * Customer portal — Pay & payments (S-PORTAL-REDESIGN). The one place to
 * settle up:
 *
 *   Pay            every open invoice, oldest first, all ticked by default,
 *                  with a live total → "Pay $X" opens the Pay drawer, where
 *                  each invoice opens its own secure QuickBooks page in a new
 *                  tab and is ticked off as its payment lands (webhook →
 *                  status poll). "I've paid another way" sends a payment
 *                  notice to billing instead.
 *   Ways to pay    online (QuickBooks, when switched on) + bank / e-Transfer /
 *                  cheque details with copy buttons (Settings → invoice/company
 *                  payment instructions — same source as the invoice PDF)
 *   Statement      self-serve statement of account PDF for any range
 *   History        every payment with what it paid and a PDF receipt
 *
 * Online payment gate (unchanged, D-QBO-15-3): quickbooks.payments_enabled
 * + connected + the invoice already in QuickBooks; re-checked at click time
 * by app/portal/payments/go.php.
 *
 * Trap 8: all queries filter by portal_customer_id(). Money is bcmath (D16).
 *
 * @session S-PORTAL-PAYMENTS-CCA (original), S-PORTAL-REDESIGN (rebuilt)
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid      = portal_customer_id();
$summary  = pt_account_summary($cid);
$currency = $summary['currency'];
$online   = pt_online_pay_enabled();
$pay      = pt_offline_payment_details();

$open = array_map('pt_invoice_json', db_select(
    pt_invoice_select_sql() . " WHERE i.customer_id = ? AND " . pt_invoice_visible_sql('i') . "
       AND i.status IN (" . pt_open_statuses_sql() . ") AND i.balance_due > 0
     ORDER BY i.due_date ASC, i.id ASC LIMIT 100",
    [$cid]
));

$history = db_select(
    "SELECT p.id, p.payment_number, p.payment_date, p.payment_method, p.reference_number,
            p.check_number, p.amount, p.currency, p.amount_in_cad, p.status,
            GROUP_CONCAT(i.invoice_number ORDER BY i.id SEPARATOR ', ') AS applied_invoices,
            GROUP_CONCAT(i.id ORDER BY i.id SEPARATOR ',')               AS applied_invoice_ids
       FROM payments p
       LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
       LEFT JOIN invoices i ON i.id = pa.invoice_id AND i.deleted_at IS NULL AND i.customer_id = p.customer_id
      WHERE p.customer_id = ? AND p.deleted_at IS NULL
      GROUP BY p.id
      ORDER BY p.payment_date DESC, p.id DESC
      LIMIT 200",
    [$cid]
);

$paidTotal12 = '0.00';
$since = ff_local_date_add(ff_today(), -365);
foreach ($history as $h) {
    if (in_array($h['status'], ['pending', 'cleared'], true) && $h['payment_date'] >= $since && $h['currency'] === $currency) {
        $paidTotal12 = bcadd($paidTotal12, (string) $h['amount'], 2);
    }
}

$pageTitle    = 'Pay & payments';
$ptHideRibbon = true;
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'eyebrow' => 'Billing',
    'title'   => 'Pay & payments',
    'sub'     => $online
        ? 'Pay any open invoice online in a couple of clicks, or send it the way you usually do — then grab receipts and statements whenever you need them.'
        : 'Everything you need to settle up: what\'s owed, how to pay us, receipts and statements.',
]);

$pickerRows = array_map(static fn (array $r): array => [
    'id' => $r['id'], 'number' => $r['number'], 'due_date' => $r['due_date'], 'balance' => $r['balance'],
    'balance_fmt' => $r['balance_fmt'], 'currency' => $r['currency'], 'status_label' => $r['status_label'],
    'status_tone' => $r['status_tone'], 'days_late' => $r['days_late'], 'period' => $r['period'],
], $open);
?>

<div class="pt-grid pt-grid--main-wide">
    <div class="pt-stack">

        <!-- ── Pay ──────────────────────────────────────────────────────── -->
        <section class="pt-card" x-data="{
                rows: <?= e(json_encode($pickerRows)) ?>,
                sel: <?= e(json_encode(array_column($pickerRows, 'id'))) ?>,
                has(id) { return this.sel.includes(id); },
                flip(id) { const i = this.sel.indexOf(id); i === -1 ? this.sel.push(id) : this.sel.splice(i, 1); },
                get all() { return this.rows.length > 0 && this.sel.length === this.rows.length; },
                toggleAll() { this.sel = this.all ? [] : this.rows.map(r => r.id); },
                pastDue() { this.sel = this.rows.filter(r => r.days_late > 0).map(r => r.id); },
                get cents() { return this.rows.filter(r => this.sel.includes(r.id)).reduce((s, r) => s + PT.toCents(r.balance), 0); },
                get label() { const c = [...new Set(this.rows.filter(r => this.sel.includes(r.id)).map(r => r.currency))]; return c.length > 1 ? 'selected' : PT.moneyFromCents(this.cents, c[0] || '<?= e($currency) ?>'); }
            }">
            <div class="pt-card-head pt-card-head--line">
                <div>
                    <h2 class="pt-card-title"><?= pt_icon('credit-card') ?> Pay invoices</h2>
                    <p class="pt-card-sub">
                        <?php if ($open): ?>
                            <?= count($open) ?> open invoice<?= count($open) === 1 ? '' : 's' ?> · <?= e(pt_money($summary['outstanding'], $currency)) ?> in total<?= $summary['past_due_count'] ? ' · ' . e(pt_money($summary['past_due'], $currency)) . ' past due' : '' ?>
                        <?php else: ?>
                            Nothing to pay right now.
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($open && $summary['past_due_count'] > 0 && $summary['past_due_count'] < count($open)): ?>
                    <button type="button" class="pt-btn pt-btn--ghost pt-btn--sm" @click="pastDue()">Select past due</button>
                <?php endif; ?>
            </div>

            <?php if (!$open): ?>
                <div class="pt-card-body">
                    <div class="pt-paid-up" style="margin:0"><?= pt_icon('check-circle') ?> You're all paid up — thank you!</div>
                </div>
            <?php else: ?>
                <div class="pt-table-wrap" style="max-height:420px;overflow:auto">
                    <table data-no-auto-label class="pt-table pt-table--compact">
                        <thead>
                            <tr>
                                <th class="shrink"><input type="checkbox" class="pt-check" :checked="all" :indeterminate="sel.length > 0 && !all" @change="toggleAll()" aria-label="Select all"></th>
                                <th>Invoice</th><th>Due</th><th>Status</th><th class="num">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="r in rows" :key="r.id">
                                <tr :class="{ 'is-selected': has(r.id) }" class="pt-row-click" @click="if ($event.target.tagName !== 'A' && $event.target.tagName !== 'INPUT') flip(r.id)">
                                    <td class="shrink"><input type="checkbox" class="pt-check" :checked="has(r.id)" @change="flip(r.id)" :aria-label="'Pay invoice ' + r.number"></td>
                                    <td><a class="pt-table-main" :href="PT.url('portal/invoices/view?id=' + r.id)" x-text="r.number"></a><span class="pt-table-sub" x-text="r.period"></span></td>
                                    <td class="nw"><span x-text="PT.date(r.due_date)"></span><span class="pt-table-sub pt-danger-ink" x-show="r.days_late > 0" x-text="r.days_late + ' days late'"></span></td>
                                    <td><span class="pt-pill" :class="'pt-pill--' + r.status_tone" x-text="r.status_label"></span></td>
                                    <td class="num"><strong x-text="r.balance_fmt"></strong></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="pt-card-foot" style="flex-wrap:wrap">
                    <div>
                        <div style="font-size:12.5px;color:var(--text-tertiary);font-weight:600" x-text="sel.length + ' of ' + rows.length + ' selected'"></div>
                        <div style="font-size:22px;font-weight:700;color:var(--text-primary);letter-spacing:-.02em" class="pt-num" x-text="label"></div>
                    </div>
                    <div class="pt-btn-row">
                        <button type="button" class="pt-btn pt-btn--secondary" :disabled="sel.length === 0" @click="$store.notice.show({ ids: sel })"><?= pt_icon('paper-airplane') ?> I've paid another way</button>
                        <button type="button" class="pt-btn pt-btn--primary pt-btn--lg" :disabled="sel.length === 0" @click="$store.checkout.start(sel)">
                            <?= pt_icon('credit-card') ?> <span x-text="'<?= $online ? 'Pay ' : 'How to pay ' ?>' + label"></span>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- ── History ──────────────────────────────────────────────────── -->
        <section class="pt-card" id="history" x-data="{ showAll: false }">
            <div class="pt-card-head">
                <div>
                    <h2 class="pt-card-title"><?= pt_icon('banknotes') ?> Payment history</h2>
                    <p class="pt-card-sub"><?= $history ? count($history) . ' payment' . (count($history) === 1 ? '' : 's') . ' on record · ' . e(pt_money($paidTotal12, $currency)) . ' in the last 12 months' : 'Payments will appear here once received.' ?></p>
                </div>
            </div>
            <?php if (!$history): ?>
                <?= pt_empty('banknotes', 'No payments yet', 'When we receive a payment, it shows up here with a receipt.') ?>
            <?php else: ?>
                <div class="pt-card-body--flush pt-table-wrap">
                    <table data-no-auto-label class="pt-table pt-table--stack">
                        <thead><tr><th>Date</th><th>Method</th><th>Applied to</th><th>Status</th><th class="num">Amount</th><th class="shrink"><span class="pt-sr">Receipt</span></th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $idx => $p):
                            [$pl, $pt] = match ($p['status']) {
                                'cleared'  => ['Received', 'success'],
                                'pending'  => ['Clearing', 'warning'],
                                'refunded' => ['Refunded', 'info'],
                                'returned' => ['Returned', 'danger'],
                                'failed'   => ['Failed', 'danger'],
                                'void'     => ['Cancelled', 'neutral'],
                                default    => [ucfirst((string) $p['status']), 'neutral'],
                            };
                            $ref = $p['reference_number'] ?: ($p['check_number'] ? 'Cheque ' . $p['check_number'] : '');
                            $links = [];
                            if ($p['applied_invoices']) {
                                $nums = explode(', ', (string) $p['applied_invoices']);
                                $ids  = explode(',', (string) $p['applied_invoice_ids']);
                                foreach ($nums as $k => $num) {
                                    $iid = (int) ($ids[$k] ?? 0);
                                    $links[] = $iid > 0 ? '<a class="pt-link" href="' . e(pt_url('invoices/view?id=' . $iid)) . '">' . e($num) . '</a>' : e($num);
                                }
                            }
                            $receipt = in_array($p['status'], ['pending', 'cleared', 'refunded'], true);
                        ?>
                            <tr<?= $idx >= 12 ? ' x-show="showAll" x-cloak' : '' ?>>
                                <td class="pt-cell-primary nw">
                                    <span class="pt-table-main"><?= e(format_date($p['payment_date'])) ?></span>
                                    <span class="pt-table-sub"><?= e($p['payment_number']) ?></span>
                                </td>
                                <td data-label="Method"><?= e(pt_payment_method($p['payment_method'])) ?><?php if ($ref !== ''): ?><span class="pt-table-sub"><?= e($ref) ?></span><?php endif; ?></td>
                                <td data-label="Applied to" style="font-size:13px"><?= $links ? implode(', ', $links) : '<span class="pt-faint">—</span>' ?></td>
                                <td data-label="Status"><?= pt_badge($pl, $pt) ?></td>
                                <td class="num" data-label="Amount">
                                    <strong><?= e(pt_money($p['amount'], (string) $p['currency'])) ?></strong>
                                    <?php if ($p['currency'] !== 'CAD' && !empty($p['amount_in_cad'])): ?><span class="pt-table-sub">≈ <?= e(format_currency($p['amount_in_cad'])) ?> CAD</span><?php endif; ?>
                                </td>
                                <td class="shrink pt-cell-actions">
                                    <?php if ($receipt): ?>
                                        <a class="pt-btn pt-btn--ghost pt-btn--sm" href="<?= e(base_url('api/v1/portal/payments/receipt') . '?id=' . (int) $p['id']) ?>" target="_blank" rel="noopener"><?= pt_icon('document-arrow-down') ?> Receipt</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($history) > 12): ?>
                    <div class="pt-card-foot">
                        <span x-text="showAll ? 'Showing all <?= count($history) ?>' : 'Showing 12 of <?= count($history) ?>'"></span>
                        <button type="button" class="pt-btn pt-btn--ghost pt-btn--sm" @click="showAll = !showAll" x-text="showAll ? 'Show fewer' : 'Show all'"></button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <aside class="pt-stack">
        <section class="pt-card">
            <div class="pt-card-head"><h2 class="pt-card-title"><?= pt_icon('building-library') ?> Ways to pay</h2></div>
            <div class="pt-card-body" style="display:flex;flex-direction:column;gap:10px">
                <?php if ($online): ?>
                    <div class="pt-note pt-note--success"><?= pt_icon('lock-closed') ?>
                        <span><strong>Pay online</strong> by card or bank transfer through QuickBooks' secure page. Your payment is recorded on your account automatically.</span>
                    </div>
                <?php endif; ?>
                <?php if ($pay['bank_name'] !== '' || $pay['bank_account'] !== ''): ?>
                    <div class="pt-copy-row"><div><div class="pt-copy-k">Bank transfer (EFT / wire)</div>
                        <div class="pt-copy-v"><?= e(trim($pay['bank_name'] . ($pay['bank_account'] !== '' ? ' · ' . $pay['bank_account'] : ''))) ?></div></div>
                        <?php if ($pay['bank_account'] !== ''): ?><button type="button" class="pt-btn pt-btn--ghost pt-btn--sm" @click="PT.copy(<?= e(json_encode($pay['bank_account'])) ?>, 'Account number')"><?= pt_icon('clipboard') ?></button><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($pay['email'] !== ''): ?>
                    <div class="pt-copy-row"><div><div class="pt-copy-k">Interac e-Transfer / remittance</div><div class="pt-copy-v"><?= e($pay['email']) ?></div></div>
                        <button type="button" class="pt-btn pt-btn--ghost pt-btn--sm" @click="PT.copy(<?= e(json_encode($pay['email'])) ?>, 'Email')" aria-label="Copy email"><?= pt_icon('clipboard') ?></button></div>
                <?php endif; ?>
                <?php if ($pay['payable_to'] !== ''): ?>
                    <div class="pt-copy-row"><div><div class="pt-copy-k">Cheques payable to</div><div class="pt-copy-v"><?= e($pay['payable_to']) ?></div>
                        <?php if ($pay['remit_address'] !== ''): ?><div class="pt-hint" style="margin-top:2px"><?= e($pay['remit_address']) ?></div><?php endif; ?></div></div>
                <?php endif; ?>
                <?php if ($pay['instructions'] !== ''): ?>
                    <div class="pt-note"><?= pt_icon('information-circle') ?><span><?= nl2br(e($pay['instructions'])) ?></span></div>
                <?php endif; ?>
                <p class="pt-hint" style="margin:2px 0 0">Please put your invoice numbers in the payment reference.</p>
                <button type="button" class="pt-btn pt-btn--soft pt-btn--block" @click="$store.notice.show()"><?= pt_icon('paper-airplane') ?> Tell us about a payment</button>
            </div>
        </section>

        <section class="pt-card">
            <div class="pt-card-head"><h2 class="pt-card-title"><?= pt_icon('document-chart-bar') ?> Statement of account</h2></div>
            <div class="pt-card-body">
                <p class="pt-muted" style="font-size:13.5px;margin:0 0 14px;line-height:1.5">A PDF of every invoice, payment and credit in a period with a running balance — handy for your accounts team.</p>
                <button type="button" class="pt-btn pt-btn--secondary pt-btn--block" @click="$store.statement.show()"><?= pt_icon('arrow-down-tray') ?> Get a statement</button>
            </div>
        </section>

        <?php if (bccomp($summary['credit'], '0', 2) > 0): ?>
        <section class="pt-card">
            <div class="pt-card-body" style="display:flex;gap:14px;align-items:flex-start">
                <span class="pt-stat-ic pt-stat-ic--success" style="flex-shrink:0"><?= pt_icon('receipt-percent') ?></span>
                <div>
                    <p class="pt-card-title" style="font-size:14px;margin:0">You have <?= e(pt_money($summary['credit'], $currency)) ?> in credit</p>
                    <p class="pt-muted" style="font-size:13px;margin:4px 0 12px;line-height:1.5">Credits from refunds or adjustments. We can put them toward an open invoice.</p>
                    <a class="pt-btn pt-btn--soft pt-btn--sm" href="<?= e(pt_url('requests/create?type=billing_inquiry&subject=' . rawurlencode('Please apply my account credit') . '&message=' . rawurlencode('Please apply my available credit of ' . pt_money($summary['credit'], $currency) . ' to my open invoices.'))) ?>">Apply my credit</a>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </aside>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
