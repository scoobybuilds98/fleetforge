<?php
declare(strict_types=1);

/**
 * lib/Billing/CustomerStatement.php
 *
 * A customer's statement of account for a date range: opening balance,
 * every invoice, payment, credit and write-off in the range with a running
 * balance, the closing balance, and an aged summary of what is still open.
 *
 * WHY a class: the statement used to be built inline in the admin endpoint
 * (api/v1/accounting/ar/statement.php). S-PORTAL-REDESIGN lets customers
 * download their own statement from the portal, and the two copies must
 * never disagree about a balance — so both endpoints now call build() +
 * renderPdf() here. The queries and money math are exactly the ones the
 * admin endpoint used (bcmath throughout, D16).
 *
 * Scope rules (unchanged): drafts and voids never appear; payments count
 * through their allocations to this customer's invoices; credit-note
 * applications are bounded by the UTC instants of local midnight because
 * applied_at is a UTC DATETIME (S-UTC-STAMPS).
 *
 * Used by: api/v1/accounting/ar/statement.php (staff),
 *          api/v1/portal/statement.php (customer portal)
 *
 * @session S031 (original), S-PORTAL-REDESIGN (extracted + shared)
 */

namespace FleetForge\Billing;

use FleetForge\Pdf\PdfKit;

final class CustomerStatement
{
    private function __construct() {}

    /**
     * Build the statement data. Returns null when the customer doesn't exist.
     *
     * @param string $dateFrom company-local business date Y-m-d
     * @param string $dateTo   company-local business date Y-m-d
     * @return array{
     *   customer: array<string,mixed>, date_from: string, date_to: string,
     *   opening: string, closing: string, total_charges: string, total_credits: string,
     *   transactions: list<array{date:string,type:string,reference:string,description:string,debit:string,credit:string,balance:string}>,
     *   aging: array{current:string,days_1_30:string,days_31_60:string,days_61_90:string,days_90_plus:string},
     *   aging_total: string
     * }|null
     */
    public static function build(int $customerId, string $dateFrom, string $dateTo): ?array
    {
        $customer = db_row(
            "SELECT id, company_name, contact_name, email, phone,
                    address, billing_address, city, province, postal_code, country
               FROM customers
              WHERE id = ? AND deleted_at IS NULL",
            [$customerId]
        );
        if (!$customer) {
            return null;
        }

        // Credit applications carry UTC stamps; the range is local business dates.
        $fromStartUtc = ff_local_day_start_utc($dateFrom);
        $toEndUtc     = ff_local_day_start_utc(ff_local_date_add($dateTo, 1));

        // ── Opening balance: invoices dated before the range, less what was
        //    paid or credited against them before the range began ─────────
        $openingRow = db_row(
            "SELECT COALESCE(SUM(i.total_amount), 0) AS inv_total,
                    COALESCE(
                        (SELECT SUM(pa.amount)
                         FROM payment_allocations pa
                         JOIN payments p ON p.id = pa.payment_id AND p.deleted_at IS NULL
                         WHERE pa.invoice_id IN (
                             SELECT id FROM invoices
                             WHERE customer_id = ? AND deleted_at IS NULL
                               AND invoice_date < ?
                               AND status NOT IN ('draft','void')
                         ) AND p.payment_date < ?
                        ), 0) AS pay_total,
                    COALESCE(
                        (SELECT SUM(ca.amount_applied)
                         FROM credit_note_applications ca
                         JOIN credit_notes cn ON cn.id = ca.credit_note_id AND cn.deleted_at IS NULL
                         WHERE ca.invoice_id IN (
                             SELECT id FROM invoices
                             WHERE customer_id = ? AND deleted_at IS NULL
                               AND invoice_date < ?
                               AND status NOT IN ('draft','void')
                         ) AND ca.applied_at < ?
                        ), 0) AS credit_total
             FROM invoices i
             WHERE i.customer_id = ? AND i.deleted_at IS NULL
               AND i.invoice_date < ?
               AND i.status NOT IN ('draft','void')",
            [$customerId, $dateFrom, $dateFrom, $customerId, $dateFrom, $fromStartUtc, $customerId, $dateFrom]
        );
        $opening = bcsub(
            (string) ($openingRow['inv_total'] ?? '0.00'),
            bcadd((string) ($openingRow['pay_total'] ?? '0.00'), (string) ($openingRow['credit_total'] ?? '0.00'), 2),
            2
        );

        // ── Activity inside the range ────────────────────────────────────
        $invoices = db_select(
            "SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
                    i.total_amount, i.balance_due, i.status
               FROM invoices i
              WHERE i.customer_id = ? AND i.deleted_at IS NULL
                AND i.invoice_date BETWEEN ? AND ?
                AND i.status NOT IN ('draft','void')
              ORDER BY i.invoice_date ASC, i.id ASC",
            [$customerId, $dateFrom, $dateTo]
        );

        $payments = db_select(
            "SELECT p.id, p.payment_number, p.payment_date, p.amount,
                    p.payment_method, pa.invoice_id, pa.amount AS amount_applied,
                    inv.invoice_number AS applied_to_invoice
               FROM payments p
               JOIN payment_allocations pa ON pa.payment_id = p.id
               JOIN invoices inv ON inv.id = pa.invoice_id AND inv.deleted_at IS NULL
              WHERE p.deleted_at IS NULL
                AND inv.customer_id = ?
                AND p.payment_date BETWEEN ? AND ?
              ORDER BY p.payment_date ASC, p.id ASC",
            [$customerId, $dateFrom, $dateTo]
        );

        $credits = db_select(
            "SELECT cn.id, cn.credit_note_number, cn.amount AS cn_amount,
                    ca.amount_applied, ca.applied_at,
                    inv.invoice_number AS applied_to_invoice
               FROM credit_note_applications ca
               JOIN credit_notes cn ON cn.id = ca.credit_note_id AND cn.deleted_at IS NULL
               JOIN invoices inv ON inv.id = ca.invoice_id AND inv.deleted_at IS NULL
              WHERE cn.customer_id = ?
                AND ca.applied_at >= ? AND ca.applied_at < ?
              ORDER BY ca.applied_at ASC",
            [$customerId, $fromStartUtc, $toEndUtc]
        );

        $writeoffs = db_select(
            "SELECT bw.id, bw.writeoff_date, bw.amount, bw.reason,
                    inv.invoice_number
               FROM acc_bad_debt_writeoffs bw
               JOIN invoices inv ON inv.id = bw.invoice_id
              WHERE bw.customer_id = ?
                AND bw.writeoff_date BETWEEN ? AND ?
              ORDER BY bw.writeoff_date ASC",
            [$customerId, $dateFrom, $dateTo]
        );

        $transactions = [];
        foreach ($invoices as $inv) {
            $transactions[] = [
                'date'        => (string) $inv['invoice_date'],
                'type'        => 'Invoice',
                'reference'   => (string) $inv['invoice_number'],
                'description' => "Invoice {$inv['invoice_number']}",
                'debit'       => (string) $inv['total_amount'],
                'credit'      => '0.00',
            ];
        }
        foreach ($payments as $pay) {
            $transactions[] = [
                'date'        => (string) $pay['payment_date'],
                'type'        => 'Payment',
                'reference'   => (string) $pay['payment_number'],
                'description' => "Payment {$pay['payment_number']} → {$pay['applied_to_invoice']}",
                'debit'       => '0.00',
                'credit'      => (string) $pay['amount_applied'],
            ];
        }
        foreach ($credits as $cr) {
            $transactions[] = [
                // S-UTC-STAMPS: the statement date is the LOCAL business day of the UTC stamp.
                'date'        => ff_utc_to_local((string) $cr['applied_at']),
                'type'        => 'Credit',
                'reference'   => (string) $cr['credit_note_number'],
                'description' => "Credit {$cr['credit_note_number']} → {$cr['applied_to_invoice']}",
                'debit'       => '0.00',
                'credit'      => (string) $cr['amount_applied'],
            ];
        }
        foreach ($writeoffs as $wo) {
            $transactions[] = [
                'date'        => (string) $wo['writeoff_date'],
                'type'        => 'Write-off',
                'reference'   => (string) $wo['invoice_number'],
                'description' => "Bad debt write-off — {$wo['invoice_number']}",
                'debit'       => '0.00',
                'credit'      => (string) $wo['amount'],
            ];
        }

        usort($transactions, static fn ($a, $b) => strcmp($a['date'], $b['date']));

        $running = $opening;
        $totalCharges = '0.00';
        $totalCredits = '0.00';
        foreach ($transactions as &$txn) {
            $running = bcadd(bcsub($running, $txn['credit'], 2), $txn['debit'], 2);
            $txn['balance'] = $running;
            $totalCharges = bcadd($totalCharges, $txn['debit'], 2);
            $totalCredits = bcadd($totalCredits, $txn['credit'], 2);
        }
        unset($txn);

        // ── Aged balance of what is still open (as of date_to) ──────────
        $agingInvoices = db_select(
            "SELECT id, invoice_number, due_date, balance_due
               FROM invoices
              WHERE customer_id = ? AND deleted_at IS NULL
                AND status NOT IN ('paid','void','written_off','draft')
                AND balance_due > 0
              ORDER BY due_date ASC",
            [$customerId]
        );
        $aging = ['current' => '0.00', 'days_1_30' => '0.00', 'days_31_60' => '0.00', 'days_61_90' => '0.00', 'days_90_plus' => '0.00'];
        $asOf  = new \DateTime($dateTo);
        foreach ($agingInvoices as $ai) {
            $due      = new \DateTime((string) $ai['due_date']);
            $diff     = (int) $asOf->diff($due)->format('%r%a');
            $daysPast = $diff < 0 ? abs($diff) : 0;
            $bucket   = match (true) {
                $daysPast === 0 => 'current',
                $daysPast <= 30 => 'days_1_30',
                $daysPast <= 60 => 'days_31_60',
                $daysPast <= 90 => 'days_61_90',
                default         => 'days_90_plus',
            };
            $aging[$bucket] = bcadd($aging[$bucket], (string) $ai['balance_due'], 2);
        }
        $agingTotal = '0.00';
        foreach ($aging as $v) {
            $agingTotal = bcadd($agingTotal, $v, 2);
        }

        return [
            'customer'      => $customer,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'opening'       => $opening,
            'closing'       => $running,
            'total_charges' => $totalCharges,
            'total_credits' => $totalCredits,
            'transactions'  => $transactions,
            'aging'         => $aging,
            'aging_total'   => $agingTotal,
        ];
    }

    /**
     * Render build()'s data as the letterhead statement PDF (PdfKit).
     *
     * @param array<string,mixed> $s build() output
     * @return string PDF bytes
     */
    public static function renderPdf(array $s): string
    {
        $m        = static fn (string $v): string => e(PdfKit::money($v));
        $customer = $s['customer'];

        // Customer block: billing address when set (same rule as invoices), else
        // the main address + its city line — without doubling a city already in it.
        $custLines = [];
        $addr = trim((string) ($customer['billing_address'] ?: $customer['address'] ?? ''));
        if ($addr !== '') {
            $custLines = array_values(array_filter(array_map('trim', preg_split('/\R/', $addr) ?: [])));
        }
        if (!$customer['billing_address']) {
            $cityLine = trim((string) $customer['city']
                . (!empty($customer['province']) ? ((string) $customer['city'] !== '' ? ', ' : '') . $customer['province'] : '')
                . (!empty($customer['postal_code']) ? ' ' . $customer['postal_code'] : ''));
            $norm = static fn (string $v): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $v));
            if ($cityLine !== '' && !str_contains($norm($addr), $norm((string) ($customer['postal_code'] ?: $customer['city'])))) {
                $custLines[] = $cityLine;
            }
        }

        $accent = e(PdfKit::brand()['accent']);
        $html = '<table class="ff-panels"><tr>'
            . '<td class="ff-panel" width="48%"><div class="ff-panel-label">Statement for</div>'
            . '<strong>' . e($customer['company_name']) . '</strong><br>'
            . ($customer['contact_name'] ? 'Attn: ' . e($customer['contact_name']) . '<br>' : '')
            . implode('<br>', array_map('e', $custLines))
            . ($customer['email'] ? '<br><span class="muted">' . e($customer['email']) . '</span>' : '')
            . '</td><td class="ff-gap"></td>'
            . '<td class="ff-panel" width="48%"><div class="ff-panel-label">Account summary</div>'
            . '<table class="ff-kv" width="100%">'
            . '<tr><td class="k">Opening balance</td><td class="num">' . $m($s['opening']) . '</td></tr>'
            . '<tr><td class="k">Charges this period</td><td class="num">' . $m($s['total_charges']) . '</td></tr>'
            . '<tr><td class="k">Payments &amp; credits</td><td class="num">-' . $m($s['total_credits']) . '</td></tr>'
            . '</table>'
            . '<table width="100%" style="margin-top:2mm;"><tr>'
            . '<td style="background-color:' . $accent . ';color:#ffffff;font-weight:bold;padding:2mm 2.5mm;">Balance due</td>'
            . '<td style="background-color:' . $accent . ';color:#ffffff;font-weight:bold;font-size:11pt;text-align:right;padding:2mm 2.5mm;">' . $m($s['closing']) . '</td>'
            . '</tr></table>'
            . '</td></tr></table>';

        $html .= '<div class="ff-h">Account activity</div>';
        if (count($s['transactions']) > 0) {
            $html .= '<table class="ff-grid"><thead><tr>'
                . '<th width="13%">Date</th><th width="9%">Type</th><th width="17%">Reference</th><th>Description</th>'
                . '<th class="num" width="12%">Charges</th><th class="num" width="12%">Credits</th><th class="num" width="12%">Balance</th>'
                . '</tr></thead><tbody>'
                . '<tr><td colspan="4" class="muted"><em>Balance brought forward</em></td><td></td><td></td>'
                . '<td class="num"><strong>' . $m($s['opening']) . '</strong></td></tr>';
            foreach ($s['transactions'] as $txn) {
                $html .= '<tr>'
                    . '<td class="nw">' . e(PdfKit::date($txn['date'])) . '</td>'
                    . '<td>' . e($txn['type']) . '</td>'
                    . '<td class="nw">' . e($txn['reference']) . '</td>'
                    . '<td>' . e($txn['description']) . '</td>'
                    . '<td class="num">' . (bccomp($txn['debit'], '0', 2) > 0 ? $m($txn['debit']) : '') . '</td>'
                    . '<td class="num">' . (bccomp($txn['credit'], '0', 2) > 0 ? $m($txn['credit']) : '') . '</td>'
                    . '<td class="num">' . $m($txn['balance']) . '</td>'
                    . '</tr>';
            }
            $html .= '<tr class="ff-sum"><td colspan="4">Period totals</td>'
                . '<td class="num">' . $m($s['total_charges']) . '</td><td class="num">' . $m($s['total_credits']) . '</td>'
                . '<td class="num">' . $m($s['closing']) . '</td></tr>'
                . '</tbody></table>';
        } else {
            $html .= '<div class="ff-panel-label" style="text-align:center;padding:6mm 0;">No transactions in this period.</div>';
        }

        $a = $s['aging'];
        $html .= '<div class="ff-h">Aged balance</div>'
            . '<table class="ff-grid"><thead><tr>'
            . '<th class="num">Current</th><th class="num">1–30 days</th><th class="num">31–60 days</th>'
            . '<th class="num">61–90 days</th><th class="num">90+ days</th><th class="num">Total owing</th>'
            . '</tr></thead><tbody><tr>'
            . '<td class="num">' . $m($a['current']) . '</td>'
            . '<td class="num">' . $m($a['days_1_30']) . '</td>'
            . '<td class="num">' . $m($a['days_31_60']) . '</td>'
            . '<td class="num">' . $m($a['days_61_90']) . '</td>'
            . '<td class="num">' . $m($a['days_90_plus']) . '</td>'
            . '<td class="num"><strong>' . $m($s['aging_total']) . '</strong></td>'
            . '</tr></tbody></table>';

        return PdfKit::render($html, [
            'title'     => 'Statement',
            'reference' => (string) $customer['company_name'],
            'meta'      => [
                'Statement date' => PdfKit::date($s['date_to']),
                'Period'         => PdfKit::period($s['date_from'], $s['date_to']),
                'Account no.'    => (string) $customer['id'],
            ],
            'footer_note' => 'Please contact us with any questions about your account.',
        ]);
    }

    /** Download filename (without extension — PdfKit::stream adds it). */
    public static function filename(array $s): string
    {
        return 'statement_' . $s['customer']['id'] . '_' . $s['date_to'];
    }
}
