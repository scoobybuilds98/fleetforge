<?php
declare(strict_types=1);

/**
 * app/portal/invoices/view.php
 *
 * Customer portal — one invoice (S-PORTAL-REDESIGN).
 *
 * Left: the invoice as a document — who it's from, bill-to, rental details,
 * every charge with its period, and totals that follow the PDF line for line
 * (subtotal, discount, taxes with rates, total, payments, credits, late fee,
 * balance) plus the USD→CAD transparency block (CURRENCY-MARKUP-1 D-D).
 * Right (sticky): what's owed and when, Pay (opens the Pay drawer → secure
 * QuickBooks page in a new tab), PDF, "I've paid this", payments received,
 * and a one-click "Ask about this invoice" that opens a billing request
 * pre-filled with the invoice number.
 *
 * Visibility (unchanged, Trap 8): the customer's own invoice, never void,
 * drafts only when they are customer-facing advance bills.
 * Credit lines: capped credits show their real amounts + the account-credit
 * row (S-REFUND-ON-INVOICE, ff_expand_capped_invoice_lines).
 * Period end: the ACTUAL return date when the time-of-day rule trimmed the
 * billed extent (S-LEASE-CLOSE-ACTUAL-DATE, ff_invoice_display_period_end).
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid       = portal_customer_id();
$invoiceId = clean_int($_GET['id'] ?? null);
if (!$invoiceId) {
    header('Location: ' . pt_url('invoices'));
    exit;
}

$inv = db_row(
    "SELECT i.*, l.contract_number,
            l.actual_return_date, l.actual_return_time, l.start_time, l.billing_days_removed,
            qm.qbo_invoice_id
       FROM invoices i
       LEFT JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
       LEFT JOIN acc_qbo_invoice_map qm ON qm.ff_invoice_id = i.id
      WHERE i.id = ? AND i.customer_id = ? AND " . pt_invoice_visible_sql('i'),
    [$invoiceId, $cid]
);
if (!$inv) {
    header('Location: ' . pt_url('invoices'));
    exit;
}

$lineItems = db_select(
    "SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC",
    [$invoiceId]
);
$lineItems = ff_expand_capped_invoice_lines($lineItems, $invoiceId);

$payments = db_select(
    "SELECT pa.amount AS allocated, p.id AS payment_id, p.payment_number, p.payment_date,
            p.payment_method, p.reference_number, p.status
       FROM payment_allocations pa
       JOIN payments p ON p.id = pa.payment_id AND p.deleted_at IS NULL
      WHERE pa.invoice_id = ?
      ORDER BY p.payment_date DESC, p.id DESC",
    [$invoiceId]
);

$j        = pt_invoice_json($inv);
$cur      = (string) ($inv['currency'] ?? 'CAD');
$m        = static fn ($v): string => pt_money($v, $cur);
$periodEnd = ff_invoice_display_period_end($inv);
$today    = ff_today();

$itemLabel = static fn (string $t): string => match ($t) {
    'base_rental'        => 'Base rental',
    'gps'                => 'GPS tracking',
    'mileage'            => 'Mileage',
    'mileage_estimate'   => 'Mileage (estimate)',
    'mileage_usage'      => 'Mileage (actual)',
    'mileage_adjustment' => 'Mileage true-up',
    'mileage_credit'     => 'Mileage credit',
    'hourly_usage'       => 'Engine hours',
    'hours_estimate'     => 'Engine hours (estimate)',
    default              => ucfirst(str_replace('_', ' ', $t)),
};
$trim = static fn (string $n): string => str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
$rate = static function (string $price) use ($m): string {
    // Keep the precision a per-km/per-hour rate was set at ($0.0425), like the PDF.
    if (!preg_match('/^(-?\d+)\.(\d+)$/', $price, $mm) || strlen(rtrim($mm[2], '0')) <= 2) {
        return $m($price);
    }
    return (str_starts_with($mm[1], '-') ? '-' : '') . '$' . number_format((int) ltrim($mm[1], '-')) . '.' . rtrim($mm[2], '0');
};

// Company + bill-to blocks (the same data the PDF letterhead prints).
$brand   = \FleetForge\Pdf\PdfKit::brand();
$billTo  = trim((string) ($inv['billing_address_snapshot'] ?? ''));
$billName = (string) ($inv['company_name_snapshot'] ?: ($inv['customer_name_snapshot'] ?? ''));

// FX transparency (CURRENCY-MARKUP-1 D-D) — bcmath only.
$fx = null;
if ($cur !== 'CAD' && !empty($inv['exchange_rate_to_cad'])) {
    $bankRate  = (string) $inv['exchange_rate_to_cad'];
    $markupPct = (string) ($inv['currency_markup_pct'] ?? '0');
    $effRate   = bccomp($markupPct, '0', 4) > 0 ? bcmul($bankRate, bcadd('1', bcdiv($markupPct, '100', 10), 10), 6) : $bankRate;
    $fx = [
        'bank'   => rtrim(rtrim($bankRate, '0'), '.'),
        'markup' => bccomp($markupPct, '0', 4) > 0 ? rtrim(rtrim($markupPct, '0'), '.') : null,
        'eff'    => rtrim(rtrim($effRate, '0'), '.'),
        'cad'    => bcmul((string) $inv['total_amount'], $effRate, 2),
    ];
}

// Timeline: issued → (sent) → payments → paid.
$events = [];
$events[] = ['date' => $inv['invoice_date'], 'icon' => 'document-text', 'tone' => 'brand', 'title' => 'Invoice issued', 'meta' => format_date($inv['invoice_date'])];
if (!empty($inv['sent_at'])) {
    $events[] = ['date' => ff_utc_to_local((string) $inv['sent_at']), 'icon' => 'paper-airplane', 'tone' => 'info', 'title' => 'Sent to you', 'meta' => format_datetime($inv['sent_at'], 'M j, Y')];
}
foreach (array_reverse($payments) as $p) {
    $events[] = ['date' => $p['payment_date'], 'icon' => 'banknotes', 'tone' => 'success',
        'title' => 'Payment of ' . $m($p['allocated']) . ' applied', 'meta' => format_date($p['payment_date']) . ' · ' . pt_payment_method($p['payment_method'])];
}
if ($inv['status'] === 'paid') {
    $events[] = ['date' => $inv['paid_date'] ?: $today, 'icon' => 'check-circle', 'tone' => 'success', 'title' => 'Paid in full', 'meta' => $inv['paid_date'] ? format_date($inv['paid_date']) : ''];
} elseif ($j['payable']) {
    $late = $j['status_key'] === 'past_due';
    $events[] = ['date' => $inv['due_date'], 'icon' => $late ? 'exclamation-triangle' : 'clock', 'tone' => $late ? 'danger' : 'warning',
        'title' => $late ? 'Past due since ' . format_date($inv['due_date']) : 'Due ' . pt_relative_day($inv['due_date']), 'meta' => format_date($inv['due_date'])];
}

// Oldest first; the due/paid milestone stays last on a date tie.
usort($events, static fn ($a, $b) => strcmp(substr((string) $a['date'], 0, 10), substr((string) $b['date'], 0, 10)));

$askUrl = pt_url('requests/create?type=billing_inquiry'
    . '&subject=' . rawurlencode('Question about invoice ' . $inv['invoice_number'])
    . ($inv['lease_id'] ? '&lease_id=' . (int) $inv['lease_id'] : ''));
$pdfUrl = base_url('api/v1/portal/invoices/pdf') . '?id=' . $invoiceId;

$pageTitle    = 'Invoice ' . $inv['invoice_number'];
$ptHideRibbon = true;
require_once dirname(__DIR__) . '/includes/header.php';

$meta = pt_badge($j['status_label'], $j['status_tone'])
    . ($inv['contract_number'] ? '<span>Lease <a class="pt-link" href="' . e(pt_url('leases/view?id=' . (int) $inv['lease_id'])) . '">' . e($inv['contract_number']) . '</a></span>' : '')
    . '<span>Issued ' . e(format_date($inv['invoice_date'])) . '</span>'
    . ($inv['status'] === 'draft' ? '<span>Advance bill for an upcoming period</span>' : '');

ob_start(); ?>
    <a class="pt-btn pt-btn--secondary" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener"><?= pt_icon('arrow-down-tray') ?> PDF</a>
    <a class="pt-btn pt-btn--secondary" href="<?= e($askUrl) ?>"><?= pt_icon('question-mark-circle') ?> Ask about this invoice</a>
    <a class="pt-btn pt-btn--secondary" href="<?= e(pt_url('chat?attach=invoice:' . (int) $invoiceId)) ?>"><?= pt_icon('chat-bubble-left-right') ?> Message us</a><?php /* S-CHAT-REBUILD */ ?>
<?php
echo pt_page_head([
    'back'    => ['Invoices', pt_url('invoices')],
    'title'   => 'Invoice ' . $inv['invoice_number'],
    'meta'    => $meta,
    'actions' => ob_get_clean(),
]);
?>

<div class="pt-grid pt-grid--main">
    <article class="pt-card pt-doc" aria-label="Invoice document">
        <div class="pt-doc-top">
            <div class="pt-doc-from">
                <strong><?= e($brand['name']) ?></strong>
                <?php foreach ($brand['address_lines'] as $line): ?><?= e($line) ?><br><?php endforeach; ?>
                <?php if ($brand['contact_line'] !== ''): ?><span class="pt-faint"><?= e($brand['contact_line']) ?></span><?php endif; ?>
            </div>
            <div class="pt-doc-id">
                <div class="pt-doc-kind"><?= $inv['status'] === 'draft' ? 'Advance invoice' : 'Invoice' ?></div>
                <div class="pt-doc-num"><?= e($inv['invoice_number']) ?></div>
                <?php if (!empty($inv['po_number'])): ?><div class="pt-muted" style="font-size:13px;margin-top:4px">PO <?= e($inv['po_number']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="pt-doc-facts">
            <div><div class="pt-doc-fact-k">Bill to</div><div class="pt-doc-fact-v"><?= e($billName) ?></div>
                <?php if ($billTo !== ''): ?><div class="pt-muted" style="font-size:12.5px;margin-top:2px;line-height:1.45"><?= nl2br(e($billTo)) ?></div><?php endif; ?></div>
            <div><div class="pt-doc-fact-k">Issued</div><div class="pt-doc-fact-v"><?= e(format_date($inv['invoice_date'])) ?></div></div>
            <div><div class="pt-doc-fact-k">Due</div><div class="pt-doc-fact-v<?= $j['status_key'] === 'past_due' ? ' pt-danger-ink' : '' ?>"><?= e(format_date($inv['due_date'])) ?></div></div>
            <div><div class="pt-doc-fact-k">Rental period</div><div class="pt-doc-fact-v">
                <?= !empty($inv['billing_period_start']) ? e(format_date($inv['billing_period_start'])) . ' – ' . e(format_date($periodEnd)) : '—' ?></div>
                <?php if (!empty($inv['unit_number_invoice_snapshot'])): ?><div class="pt-muted" style="font-size:12.5px;margin-top:2px">Unit <?= e($inv['unit_number_invoice_snapshot']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="pt-doc-lines pt-table-wrap">
            <table data-no-auto-label class="pt-table">
                <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
                <tbody>
                <?php if (!$lineItems): ?>
                    <tr><td colspan="4" class="pt-muted" style="text-align:center;padding:24px">No charges on this invoice.</td></tr>
                <?php endif; ?>
                <?php foreach ($lineItems as $li):
                    $credit = !empty($li['is_credit']);
                    $unit   = trim((string) ($li['unit'] ?? ''));
                ?>
                    <tr>
                        <td>
                            <span class="pt-table-sub" style="margin:0 0 2px;font-size:11.5px;font-weight:650;letter-spacing:.04em;text-transform:uppercase"><?= e($itemLabel((string) ($li['item_type'] ?? ''))) ?></span>
                            <span style="font-weight:550"><?= e($li['description']) ?></span>
                            <?php if (!empty($li['period_start']) && !empty($li['period_end'])): ?>
                                <span class="pt-table-sub"><?= e(format_date($li['period_start'])) ?> – <?= e(format_date($li['period_end'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= e($trim((string) $li['quantity'])) ?><?= $unit !== '' ? ' <span class="pt-faint" style="font-size:12px">' . e($unit) . '</span>' : '' ?></td>
                        <td class="num"><?= e($rate((string) $li['unit_price'])) ?></td>
                        <td class="num<?= $credit ? ' pt-credit-line' : '' ?>"><strong><?= $credit ? '−' : '' ?><?= e($m($li['amount'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pt-doc-totals">
            <dl class="pt-kv">
                <div class="pt-kv-row"><dt>Subtotal</dt><dd><?= e($m($inv['subtotal'])) ?></dd></div>
                <?php if (bccomp((string) ($inv['discount_amount'] ?? '0'), '0', 2) > 0): ?>
                    <div class="pt-kv-row"><dt>Discount<?= $inv['discount_type'] === 'percentage' ? ' (' . e($trim((string) $inv['discount_value'])) . '%)' : '' ?></dt><dd>−<?= e($m($inv['discount_amount'])) ?></dd></div>
                <?php endif; ?>
                <?php foreach (['gst' => 'GST', 'pst' => 'PST', 'hst' => 'HST'] as $k => $label):
                    $amt = (string) ($inv["tax_{$k}_amount"] ?? '0');
                    if (bccomp($amt, '0', 2) <= 0) continue; ?>
                    <div class="pt-kv-row"><dt><?= $label ?> (<?= e($trim(bcmul((string) $inv["tax_{$k}_rate"], '100', 4))) ?>%)</dt><dd><?= e($m($amt)) ?></dd></div>
                <?php endforeach; ?>
                <div class="pt-kv-row pt-kv-row--total"><dt>Total</dt><dd><?= e($m($inv['total_amount'])) ?></dd></div>
                <?php if (bccomp((string) ($inv['amount_paid'] ?? '0'), '0', 2) > 0): ?>
                    <div class="pt-kv-row"><dt>Payments received</dt><dd>−<?= e($m($inv['amount_paid'])) ?></dd></div>
                <?php endif; ?>
                <?php if (bccomp((string) ($inv['credits_applied'] ?? '0'), '0', 2) > 0): ?>
                    <div class="pt-kv-row"><dt>Credits applied</dt><dd>−<?= e($m($inv['credits_applied'])) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($inv['late_fee_applied']) && bccomp((string) $inv['late_fee_amount'], '0', 2) > 0): ?>
                    <div class="pt-kv-row"><dt>Late fee</dt><dd><?= e($m($inv['late_fee_amount'])) ?></dd></div>
                <?php endif; ?>
                <div class="pt-kv-row pt-kv-row--total"><dt>Balance due</dt><dd<?= $j['payable'] ? '' : ' class="pt-success-ink"' ?>><?= e($m($inv['balance_due'])) ?></dd></div>
            </dl>
        </div>

        <?php if ($fx): ?>
            <div class="pt-note" style="margin-top:18px">
                <?= pt_icon('information-circle') ?>
                <span>
                    Billed in <?= e($cur) ?>.
                    <?php if ($fx['markup'] !== null): ?>
                        Bank rate 1 <?= e($cur) ?> = <?= e($fx['bank']) ?> CAD, plus a <?= e($fx['markup']) ?>% conversion markup (effective <?= e($fx['eff']) ?>).
                    <?php else: ?>
                        Exchange rate 1 <?= e($cur) ?> = <?= e($fx['bank']) ?> CAD.
                    <?php endif; ?>
                    CAD equivalent of the total: <strong><?= e(format_currency($fx['cad'])) ?> CAD</strong>.
                </span>
            </div>
        <?php endif; ?>

        <?php if (!empty($inv['notes'])): ?>
            <div class="pt-note" style="margin-top:14px"><?= pt_icon('document-text') ?><span><strong>Notes</strong><br><?= nl2br(e($inv['notes'])) ?></span></div>
        <?php endif; ?>
    </article>

    <aside class="pt-stack pt-sticky">
        <section class="pt-card pt-paybox">
            <?php if ($j['payable']): ?>
                <div class="pt-paybox-label">Balance due</div>
                <div class="pt-paybox-amt"><?= e($j['balance_fmt']) ?></div>
                <div class="pt-paybox-due<?= $j['status_key'] === 'past_due' ? ' is-late' : '' ?>">
                    <?php if ($j['status_key'] === 'past_due'): ?>
                        Past due — was due <?= e(format_date($inv['due_date'])) ?> (<?= $j['days_late'] ?> day<?= $j['days_late'] === 1 ? '' : 's' ?> ago)
                    <?php else: ?>
                        Due <?= e(format_date($inv['due_date'])) ?> · <?= e(pt_relative_day($inv['due_date'])) ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="pt-btn pt-btn--primary pt-btn--lg pt-btn--block" x-data @click="$store.checkout.start([<?= (int) $invoiceId ?>])">
                    <?= pt_icon('credit-card') ?> Pay <?= e($j['balance_fmt']) ?>
                </button>
                <button type="button" class="pt-btn pt-btn--secondary pt-btn--block" x-data @click="$store.notice.show({ ids: [<?= (int) $invoiceId ?>] })">
                    <?= pt_icon('paper-airplane') ?> I've paid this another way
                </button>
                <?php if (pt_online_pay_enabled()): ?>
                    <div class="pt-secure"><?= pt_icon('lock-closed') ?> Secure payment by QuickBooks — card or bank</div>
                <?php endif; ?>
            <?php elseif ($inv['status'] === 'paid'): ?>
                <div class="pt-paid-up" style="margin:0 0 14px"><?= pt_icon('check-circle') ?> Paid in full<?= $inv['paid_date'] ? ' on ' . e(format_date($inv['paid_date'])) : '' ?></div>
                <a class="pt-btn pt-btn--secondary pt-btn--block" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener"><?= pt_icon('arrow-down-tray') ?> Download PDF</a>
            <?php else: ?>
                <div class="pt-paybox-label">Invoice total</div>
                <div class="pt-paybox-amt"><?= e($j['total_fmt']) ?></div>
                <div class="pt-paybox-due"><?= $inv['status'] === 'draft' ? 'This advance bill isn\'t due yet — we\'ll send it when the period starts.' : 'Nothing to pay on this invoice.' ?></div>
                <a class="pt-btn pt-btn--secondary pt-btn--block" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener"><?= pt_icon('arrow-down-tray') ?> Download PDF</a>
            <?php endif; ?>
        </section>

        <section class="pt-card">
            <div class="pt-card-head"><h2 class="pt-card-title"><?= pt_icon('clock') ?> History</h2></div>
            <ul class="pt-timeline" style="padding-top:10px">
                <?php foreach ($events as $ev): ?>
                    <li class="pt-tl-item">
                        <span class="pt-tl-ic pt-tl-ic--<?= e($ev['tone']) ?>"><?= pt_icon($ev['icon']) ?></span>
                        <div class="pt-tl-body">
                            <div class="pt-tl-title"><?= e($ev['title']) ?></div>
                            <?php if ($ev['meta'] !== ''): ?><div class="pt-tl-meta"><?= e($ev['meta']) ?></div><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php if ($payments): ?>
        <section class="pt-card">
            <div class="pt-card-head"><h2 class="pt-card-title"><?= pt_icon('banknotes') ?> Payments</h2></div>
            <div class="pt-card-body" style="padding-top:6px">
                <?php foreach ($payments as $p): ?>
                    <div class="pt-kv-row">
                        <span>
                            <span style="display:block;font-weight:600"><?= e(format_date($p['payment_date'])) ?></span>
                            <span class="pt-faint" style="font-size:12.5px"><?= e(pt_payment_method($p['payment_method'])) ?><?= $p['reference_number'] ? ' · ' . e($p['reference_number']) : '' ?></span>
                        </span>
                        <span style="text-align:right">
                            <strong class="pt-success-ink pt-num"><?= e($m($p['allocated'])) ?></strong>
                            <?php if (in_array($p['status'], ['pending', 'cleared', 'refunded'], true)): ?>
                                <a class="pt-link" style="display:block;font-size:12.5px" href="<?= e(base_url('api/v1/portal/payments/receipt') . '?id=' . (int) $p['payment_id']) ?>" target="_blank" rel="noopener">Receipt</a>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="pt-card">
            <div class="pt-card-body">
                <p class="pt-card-title" style="font-size:14px;margin:0 0 4px">Something not look right?</p>
                <p class="pt-muted" style="font-size:13px;margin:0 0 12px">Ask our billing team about this invoice — they'll reply right here in the portal.</p>
                <a class="pt-btn pt-btn--soft pt-btn--sm" href="<?= e($askUrl) ?>"><?= pt_icon('chat-bubble-left-ellipsis') ?> Ask a question</a>
            </div>
        </section>
    </aside>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
