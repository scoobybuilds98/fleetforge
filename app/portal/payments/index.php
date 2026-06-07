<?php
declare(strict_types=1);

/**
 * app/portal/payments/index.php
 *
 * Customer portal payment hub — two sections:
 *   (A) Outstanding invoices with "Pay Now via QuickBooks" buttons
 *       (gated: quickbooks.payments_enabled=1 + invoice synced to QBO)
 *   (B) Payment history — all payments received from this customer
 *
 * Trap 8: all queries filter by portal_customer_id().
 *
 * @session S-PORTAL-PAYMENTS-CCA
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();

// ── Feature gate: QBO Payments enabled? ──────────────────────────────────────
$qboPaymentsEnabled = (string) settings_get('quickbooks.payments_enabled', '0') === '1';

// ── (A) Outstanding invoices ─────────────────────────────────────────────────
// LEFT JOIN acc_qbo_invoice_map so we know which invoices are synced to QBO
// without a separate N+1 query per row.
$outstanding = db_select(
    "SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
            i.total_amount, i.balance_due, i.currency, i.status,
            qm.qbo_invoice_id
       FROM invoices i
       LEFT JOIN acc_qbo_invoice_map qm ON qm.ff_invoice_id = i.id
      WHERE i.customer_id = ?
        AND i.status IN ('sent','overdue','partially_paid')
        AND i.deleted_at IS NULL
      ORDER BY
        CASE i.status WHEN 'overdue' THEN 0 WHEN 'partially_paid' THEN 1 ELSE 2 END,
        i.due_date ASC
      LIMIT 50",
    [$cid]
);

// Total outstanding balance
$outstandingTotal = (string) (db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total
       FROM invoices
      WHERE customer_id = ? AND status IN ('sent','overdue','partially_paid') AND deleted_at IS NULL",
    [$cid]
)['total'] ?? '0.00');

// ── (B) Payment history ───────────────────────────────────────────────────────
$payments = db_select(
    "SELECT p.id, p.payment_number, p.payment_date, p.payment_method,
            p.reference_number, p.check_number, p.amount, p.currency,
            p.amount_in_cad, p.status,
            GROUP_CONCAT(i.invoice_number ORDER BY i.id SEPARATOR ', ') AS applied_invoices,
            GROUP_CONCAT(i.id ORDER BY i.id SEPARATOR ',')               AS applied_invoice_ids
       FROM payments p
       LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
       LEFT JOIN invoices i ON i.id = pa.invoice_id AND i.deleted_at IS NULL
      WHERE p.customer_id = ? AND p.deleted_at IS NULL
      GROUP BY p.id
      ORDER BY p.payment_date DESC, p.id DESC
      LIMIT 200",
    [$cid]
);

// Payment instructions for non-QBO customers
$paymentInstructions = settings_get('company.payment_instructions', '');
$bankName            = settings_get('company.bank_name', '');
$bankAccount         = settings_get('company.bank_account', '');
$checkPayable        = settings_get('company.check_payable_to', '');

$pageTitle = 'Payments';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<!-- ── Summary strip ─────────────────────────────────────────────────────── -->
<div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
    <div>
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:500;margin-bottom:4px;">Total Outstanding</div>
        <div style="font-size:1.875rem;font-weight:700;color:<?= bccomp($outstandingTotal,'0.00',2) > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;font-family:'DM Mono',monospace;"><?= e(format_currency($outstandingTotal)) ?></div>
    </div>
    <?php if ($qboPaymentsEnabled && !empty($outstanding)): ?>
    <div style="font-size:0.8125rem;color:var(--text-secondary);display:flex;align-items:center;gap:6px;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px;flex-shrink:0;color:var(--color-success);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
        Online payment available via QuickBooks
    </div>
    <?php endif; ?>
</div>

<!-- ── (A) Outstanding invoices ──────────────────────────────────────────── -->
<div class="portal-section" style="margin-bottom:24px;">
    <div class="portal-section-header">
        <h2 class="portal-section-title">Outstanding Invoices</h2>
    </div>
    <div class="portal-section-body--flush">
        <?php if (empty($outstanding)): ?>
            <div class="portal-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <p class="portal-empty-title">All caught up!</p>
                <p class="portal-empty-text">No outstanding invoices at this time.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
<table class="portal-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Due Date</th>
                        <th class="text-right">Balance Due</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($outstanding as $inv):
                        $isOverdue   = $inv['status'] === 'overdue';
                        $badgeClass  = match($inv['status']) {
                            'overdue'        => 'badge-danger',
                            'partially_paid' => 'badge-warning',
                            default          => 'badge-info',
                        };
                        $daysOverdue = 0;
                        if ($isOverdue && $inv['due_date']) {
                            $daysOverdue = max(0, (int) floor((time() - strtotime($inv['due_date'])) / 86400));
                        }

                        // Show Pay Now if QBO payments enabled + invoice synced + has balance
                        $canPayOnline = $qboPaymentsEnabled
                            && !empty($inv['qbo_invoice_id'])
                            && bccomp($inv['balance_due'], '0.00', 2) > 0;
                    ?>
                    <tr>
                        <td>
                            <a href="<?= e(base_url('portal/invoices/view?id=' . $inv['id'])) ?>" class="portal-table-link">
                                <?= e($inv['invoice_number']) ?>
                            </a>
                            <?php if ($inv['currency'] !== 'CAD'): ?>
                                <span style="font-size:0.75rem;color:var(--text-muted);margin-left:4px;"><?= e($inv['currency']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono" style="<?= $isOverdue ? 'color:var(--color-danger);font-weight:600;' : '' ?>">
                            <?= e(format_date($inv['due_date'])) ?>
                            <?php if ($daysOverdue > 0): ?>
                                <div style="font-size:0.75rem;font-weight:400;"><?= e((string)$daysOverdue) ?>d overdue</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right font-mono" style="font-weight:700;font-size:1rem;<?= $isOverdue ? 'color:var(--color-danger);' : '' ?>">
                            <?= e(format_currency($inv['balance_due'])) ?>
                        </td>
                        <td><span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst(str_replace('_', ' ', $inv['status']))) ?></span></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <?php if ($canPayOnline): ?>
                                <div x-data="portalPayBtn(<?= (int)$inv['id'] ?>)">
                                    <button class="btn btn-success btn-sm" @click="pay()" :disabled="loading">
                                        <span x-show="!loading">Pay Now</span>
                                        <span x-show="loading" x-cloak>Redirecting…</span>
                                    </button>
                                    <div x-show="error" x-cloak style="font-size:0.75rem;color:var(--color-danger);margin-top:4px;max-width:200px;text-align:left;" x-text="error"></div>
                                </div>
                            <?php elseif (!$qboPaymentsEnabled): ?>
                                <a href="<?= e(base_url('portal/invoices/view?id=' . $inv['id'])) ?>" class="btn btn-secondary btn-sm">View Invoice</a>
                            <?php else: ?>
                                <!-- Invoice not yet synced to QBO — show link only -->
                                <a href="<?= e(base_url('portal/invoices/view?id=' . $inv['id'])) ?>" class="btn btn-secondary btn-sm">View Invoice</a>
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

<?php if (!$qboPaymentsEnabled && ($bankName || $paymentInstructions)): ?>
<!-- ── Payment instructions (shown when QBO Payments disabled) ────────────── -->
<div class="portal-section" style="margin-bottom:24px;">
    <div class="portal-section-header">
        <h2 class="portal-section-title">Payment Instructions</h2>
    </div>
    <div class="portal-section-body" style="padding:18px 20px;">
        <?php if ($bankName || $bankAccount || $checkPayable): ?>
        <ul class="portal-info-list" style="margin-bottom:<?= $paymentInstructions ? '12px' : '0' ?>;">
            <?php if ($bankName): ?>
            <li><span class="portal-info-label">Bank</span><span class="portal-info-value"><?= e($bankName) ?></span></li>
            <?php endif; ?>
            <?php if ($bankAccount): ?>
            <li><span class="portal-info-label">Account</span><span class="portal-info-value font-mono"><?= e($bankAccount) ?></span></li>
            <?php endif; ?>
            <?php if ($checkPayable): ?>
            <li><span class="portal-info-label">Checks Payable To</span><span class="portal-info-value"><?= e($checkPayable) ?></span></li>
            <?php endif; ?>
        </ul>
        <?php endif; ?>
        <?php if ($paymentInstructions): ?>
        <div style="font-size:0.8125rem;color:var(--text-secondary);line-height:1.6;"><?= nl2br(e($paymentInstructions)) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── (B) Payment history ────────────────────────────────────────────────── -->
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">Payment History</h2>
        <?php if (!empty($payments)): ?>
        <span style="font-size:0.8125rem;color:var(--text-secondary);"><?= e(count($payments)) ?> on record</span>
        <?php endif; ?>
    </div>
    <div class="portal-section-body--flush">
        <?php if (empty($payments)): ?>
            <div class="portal-empty" style="padding:32px 24px;">
                <p class="portal-empty-text">No payments received yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
<table class="portal-table">
                <thead>
                    <tr>
                        <th>Payment #</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Applied To</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $pmt):
                        $badgeClass = match($pmt['status']) {
                            'cleared'  => 'badge-success',
                            'pending'  => 'badge-warning',
                            'void'     => 'badge-neutral',
                            'failed'   => 'badge-danger',
                            'returned' => 'badge-danger',
                            'refunded' => 'badge-info',
                            default    => 'badge-neutral',
                        };
                        $methodLabel = ucfirst(str_replace('_', ' ', $pmt['payment_method']));
                        $ref = $pmt['reference_number'] ?: ($pmt['check_number'] ? 'Chq ' . $pmt['check_number'] : '—');

                        $appliedHtml = '—';
                        if ($pmt['applied_invoices']) {
                            $invNums = explode(', ', $pmt['applied_invoices']);
                            $invIds  = explode(',', $pmt['applied_invoice_ids']);
                            $links   = [];
                            foreach ($invNums as $k => $num) {
                                $iid = (int)($invIds[$k] ?? 0);
                                if ($iid > 0) {
                                    $links[] = '<a href="' . e(base_url('portal/invoices/view?id=' . $iid)) . '" class="portal-table-link">' . e($num) . '</a>';
                                } else {
                                    $links[] = e($num);
                                }
                            }
                            $appliedHtml = implode(', ', $links);
                        }

                        $showCad = $pmt['currency'] === 'USD' && !empty($pmt['amount_in_cad']);
                    ?>
                    <tr>
                        <td class="font-mono"><?= e($pmt['payment_number']) ?></td>
                        <td class="font-mono"><?= e(format_date($pmt['payment_date'])) ?></td>
                        <td><?= e($methodLabel) ?></td>
                        <td class="font-mono"><?= e($ref) ?></td>
                        <td style="font-size:0.8125rem;"><?= $appliedHtml ?></td>
                        <td class="text-right font-mono" style="font-weight:600;">
                            <?= e(format_currency($pmt['amount'])) ?>
                            <?php if ($pmt['currency'] !== 'CAD'): ?>
                                <span style="font-size:0.75rem;color:var(--text-muted);margin-left:2px;"><?= e($pmt['currency']) ?></span>
                            <?php endif; ?>
                            <?php if ($showCad): ?>
                                <div style="font-size:0.75rem;color:var(--text-muted);">≈ <?= e(format_currency($pmt['amount_in_cad'])) ?> CAD</div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst($pmt['status'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
</div>
        <?php endif; ?>
    </div>
</div>

<script>
/**
 * portalPayBtn(invoiceId) — Alpine.js component for each "Pay Now" button.
 * Calls the same initiate_qbo_payment endpoint used on invoice detail pages,
 * then redirects to the QBO hosted payment page.
 * Mirrors the payOnlineButton() component in portal/invoices/view.php.
 */
function portalPayBtn(invoiceId) {
    return {
        loading: false,
        error: '',
        async pay() {
            this.loading = true;
            this.error = '';
            try {
                const r = await FF_Api.post(
                    '<?= e(base_url('api/v1/portal/invoices/initiate_qbo_payment')) ?>',
                    { invoice_id: invoiceId }
                );
                if (r.success) {
                    window.location.href = r.url;
                } else {
                    this.error = r.error || 'Unable to start payment. Please try again or contact us.';
                    this.loading = false;
                }
            } catch (e) {
                this.error = 'Network error — please try again.';
                this.loading = false;
            }
        },
    };
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
