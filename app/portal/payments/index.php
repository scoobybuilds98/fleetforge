<?php
declare(strict_types=1);

/**
 * app/portal/payments/index.php
 *
 * Portal payment history — all payments received from this customer.
 * Trap 8: all queries filter by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();

// Payments with their applied invoice numbers aggregated
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

// Total received (exclude void/failed/returned)
$totalReceived = (string) (db_row(
    "SELECT COALESCE(SUM(amount), 0) AS total
       FROM payments
      WHERE customer_id = ? AND status NOT IN ('void','failed','returned') AND deleted_at IS NULL",
    [$cid]
)['total'] ?? '0.00');

$pageTitle = 'Payment History';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<!-- Summary strip -->
<div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:500;margin-bottom:4px;">Total Received</div>
        <div style="font-size:1.75rem;font-weight:700;color:var(--color-success);font-family:'DM Mono',monospace;"><?= e(format_currency($totalReceived)) ?></div>
    </div>
    <div style="font-size:0.8125rem;color:var(--text-secondary);">
        <?= e(count($payments)) ?> payment<?= count($payments) !== 1 ? 's' : '' ?> on record
    </div>
</div>

<div class="portal-section">
    <div class="portal-section-body--flush">
        <?php if (empty($payments)): ?>
            <div class="portal-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>
                <p class="portal-empty-title">No payments on record</p>
                <p class="portal-empty-text">Payments received against your account will appear here.</p>
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

                        // Build applied-invoices links
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

                        // Show CAD equivalent when USD + conversion exists
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

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
