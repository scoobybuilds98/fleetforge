<?php
declare(strict_types=1);

/**
 * api/v1/portal/payments/receipt.php
 *
 * Customer portal — a PDF receipt for one of the customer's payments
 * (S-PORTAL-REDESIGN): amount, date, method, reference and exactly which
 * invoices it paid, on the shared letterhead (PdfKit). A GET stream, so the
 * "Receipt" link is a plain <a> (no pop-up blocking).
 *
 * Only real money gets a receipt: pending / cleared / refunded payments.
 * Void, failed and returned payments return 404 like a missing one.
 *
 * Trap 8: payments.customer_id must be the signed-in customer; allocations
 * are listed only against that customer's invoices.
 *
 * @method  GET
 * @query   id (payments.id), download (0|1)
 * @auth    portal session
 * @returns application/pdf
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/ui.php';

use FleetForge\Pdf\PdfKit;

require_method('GET');
require_portal_auth_api();

$id  = clean_int($_GET['id'] ?? null);
$cid = portal_customer_id();
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$p = db_row(
    "SELECT p.id, p.payment_number, p.payment_date, p.payment_method, p.reference_number,
            p.check_number, p.card_last_four, p.amount, p.currency, p.status,
            c.company_name, c.contact_name, c.email, c.billing_address, c.address,
            c.city, c.province, c.postal_code
       FROM payments p
       JOIN customers c ON c.id = p.customer_id
      WHERE p.id = ? AND p.customer_id = ? AND p.deleted_at IS NULL
        AND p.status IN ('pending','cleared','refunded')",
    [$id, $cid]
);
if (!$p) {
    json_error('NOT_FOUND', 'Payment not found.', 404);
}

$allocs = db_select(
    "SELECT i.invoice_number, i.invoice_date, i.total_amount, i.balance_due, i.currency, pa.amount AS applied
       FROM payment_allocations pa
       JOIN invoices i ON i.id = pa.invoice_id AND i.deleted_at IS NULL AND i.customer_id = ?
      WHERE pa.payment_id = ?
      ORDER BY i.invoice_number",
    [$cid, $id]
);

$applied = '0.00';
foreach ($allocs as $a) {
    $applied = bcadd($applied, (string) $a['applied'], 2);
}
$unapplied = bcsub((string) $p['amount'], $applied, 2);
$cur = (string) $p['currency'];
$m   = static fn (string $v): string => e(PdfKit::money($v, $cur !== 'CAD' ? $cur : ''));

$ref = trim((string) ($p['reference_number'] ?: ($p['check_number'] ? 'Cheque ' . $p['check_number'] : '')));
if ($ref === '' && $p['card_last_four']) {
    $ref = 'Card ending ' . $p['card_last_four'];
}

$addr = trim((string) ($p['billing_address'] ?: $p['address'] ?? ''));
$custLines = array_values(array_filter(array_map('trim', preg_split('/\R/', $addr) ?: [])));

$statusNote = match ((string) $p['status']) {
    'pending'  => 'Received — clearing with the bank.',
    'refunded' => 'This payment was later refunded.',
    default    => 'Received with thanks.',
};

$accent = e(PdfKit::brand()['accent']);
$html = '<table class="ff-panels"><tr>'
    . '<td class="ff-panel" width="48%"><div class="ff-panel-label">Received from</div>'
    . '<strong>' . e($p['company_name']) . '</strong><br>'
    . ($p['contact_name'] ? 'Attn: ' . e($p['contact_name']) . '<br>' : '')
    . implode('<br>', array_map('e', $custLines))
    . '</td><td class="ff-gap"></td>'
    . '<td class="ff-panel" width="48%"><div class="ff-panel-label">Payment</div>'
    . '<table class="ff-kv" width="100%">'
    . '<tr><td class="k">Date</td><td class="num">' . e(PdfKit::date($p['payment_date'])) . '</td></tr>'
    . '<tr><td class="k">Method</td><td class="num">' . e(pt_payment_method((string) $p['payment_method'])) . '</td></tr>'
    . ($ref !== '' ? '<tr><td class="k">Reference</td><td class="num">' . e($ref) . '</td></tr>' : '')
    . '</table>'
    . '<table width="100%" style="margin-top:2mm;"><tr>'
    . '<td style="background-color:' . $accent . ';color:#ffffff;font-weight:bold;padding:2mm 2.5mm;">Amount received</td>'
    . '<td style="background-color:' . $accent . ';color:#ffffff;font-weight:bold;font-size:11pt;text-align:right;padding:2mm 2.5mm;">' . $m((string) $p['amount']) . '</td>'
    . '</tr></table>'
    . '<div class="muted" style="margin-top:1.5mm;">' . e($statusNote) . '</div>'
    . '</td></tr></table>';

$html .= '<div class="ff-h">Applied to</div>';
if ($allocs) {
    $html .= '<table class="ff-grid"><thead><tr>'
        . '<th>Invoice</th><th>Invoice date</th><th class="num">Invoice total</th><th class="num">Paid by this payment</th><th class="num">Still owing</th>'
        . '</tr></thead><tbody>';
    foreach ($allocs as $a) {
        $html .= '<tr><td class="nw">' . e($a['invoice_number']) . '</td>'
            . '<td class="nw">' . e(PdfKit::date($a['invoice_date'])) . '</td>'
            . '<td class="num">' . $m((string) $a['total_amount']) . '</td>'
            . '<td class="num"><strong>' . $m((string) $a['applied']) . '</strong></td>'
            . '<td class="num">' . $m((string) $a['balance_due']) . '</td></tr>';
    }
    $html .= '<tr class="ff-sum"><td colspan="3">Total applied</td><td class="num">' . $m($applied) . '</td><td></td></tr>';
    $html .= '</tbody></table>';
} else {
    $html .= '<div class="ff-panel-label" style="padding:3mm 0;">Not yet applied to an invoice.</div>';
}
if (bccomp($unapplied, '0', 2) > 0) {
    $html .= '<div class="muted" style="margin-top:3mm;">' . $m($unapplied) . ' of this payment is held as a credit on your account.</div>';
}

try {
    $bytes = PdfKit::render($html, [
        'title'       => 'Payment receipt',
        'reference'   => (string) $p['payment_number'],
        'meta'        => [
            'Receipt no.' => (string) $p['payment_number'],
            'Date'        => PdfKit::date($p['payment_date']),
        ],
        'footer_note' => 'Thank you for your business.',
    ]);
} catch (\Throwable $e) {
    error_log('[portal/payments/receipt] payment ' . $id . ': ' . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Your receipt could not be produced right now. Please try again shortly.', 500);
}

PdfKit::stream($bytes, 'receipt_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $p['payment_number']), ($_GET['download'] ?? '') === '1');
