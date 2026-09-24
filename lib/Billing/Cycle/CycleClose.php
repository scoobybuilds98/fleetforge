<?php
declare(strict_types=1);

namespace FleetForge\Billing\Cycle;

/**
 * lib/Billing/Cycle/CycleClose.php
 *
 * S-BILLING-MODULE — closing a month's billing, its summary, and the
 * billing register export.
 *
 * Closing a cycle says "this month's billing is done": every lease is
 * billed, held or explained, nothing is left in draft, and the figures are
 * frozen into close_snapshot so the month's billing can be reported exactly
 * as it stood — later payments, credits or a late lease-close invoice do not
 * rewrite it (late additions are listed separately against the snapshot).
 *
 * What a closed cycle locks: the Billing workbench refuses to generate or
 * submit a run for a period inside a closed month (batch_generate,
 * batch_runs/create + generate). A lease's own Generate Invoice and a lease
 * close still work — a unit returned late must still be billable — and such
 * invoices show on the cycle as late additions. Reopening needs the
 * invoices "approve" permission and a reason.
 *
 * Pre-close checks come in two strengths:
 *   hard  cannot be overridden: drafts still unsent, approval runs still in
 *         flight, queried invoices, unreviewed invoices when
 *         billing_cycle.close_requires_review is on.
 *   soft  can be overridden with a written reason: leases still to bill,
 *         open billing exceptions, readiness never run.
 *
 * @session S-BILLING-MODULE
 */
final class CycleClose
{
    private function __construct() {}

    /** Line item type → summary category. */
    public const CATEGORIES = [
        'rental'      => ['label' => 'Rental',         'types' => ['base_rental', 'base_rental_reconciliation_credit', 'early_return_credit']],
        'mileage'     => ['label' => 'Mileage',        'types' => ['mileage_precharge', 'mileage_adjustment', 'mileage_credit', 'mileage_usage', 'mileage_drawdown_credit', 'mileage', 'mileage_estimate']],
        'hours'       => ['label' => 'Engine hours',   'types' => ['hourly_usage', 'hours_estimate', 'hours_adjustment', 'hours_credit']],
        'services'    => ['label' => 'Services',       'types' => ['cartage', 'sweep', 'wash', 'fuel', 'damage']],
        'addons'      => ['label' => 'Add-ons',        'types' => ['insurance', 'warranty', 'gps']],
        'adjustments' => ['label' => 'Adjustments',    'types' => ['manual_adjustment', 'discount', 'account_credit_applied', 'late_fee', 'other']],
    ];

    /**
     * @return array{hard: array, soft: array, can_close: bool}
     */
    public static function preCloseChecks(array $cycle): array
    {
        $stats = BillingCycles::stats($cycle, false);
        $cov   = BillingCycles::coverage($cycle, false)['counts'];
        $hard = [];
        $soft = [];

        if ($cycle['status'] === 'closed') {
            $hard[] = ['key' => 'already_closed', 'text' => 'This cycle is already closed.'];
        }
        if ($stats['drafts'] > 0) {
            $hard[] = ['key' => 'drafts', 'text' => "{$stats['drafts']} invoice(s) are still drafts — send or void them."];
        }
        if ($stats['pending_runs'] > 0) {
            $hard[] = ['key' => 'runs', 'text' => "{$stats['pending_runs']} approval run(s) are still pending or approved-but-not-generated."];
        }
        if ($stats['queried'] > 0) {
            $hard[] = ['key' => 'queried', 'text' => "{$stats['queried']} invoice(s) are marked Query — resolve them first."];
        }
        if ((string) \settings_get('billing_cycle.close_requires_review', '0') === '1') {
            $unreviewed = $stats['live'] - $stats['reviewed'];
            if ($unreviewed > 0) {
                $hard[] = ['key' => 'unreviewed', 'text' => "{$unreviewed} invoice(s) are not marked Reviewed (required by Billing settings)."];
            }
        }

        $toBill = (int) ($cov['to_bill'] ?? 0) + (int) ($cov['void_rebillable'] ?? 0) + (int) ($cov['closed_unbilled'] ?? 0);
        if ($toBill > 0) {
            $soft[] = ['key' => 'to_bill', 'text' => "{$toBill} lease(s) on rent this month have no invoice, hold or exception."];
        }
        if ($stats['open_exceptions'] > 0) {
            $soft[] = ['key' => 'exceptions', 'text' => "{$stats['open_exceptions']} billing exception(s) for this month are still open."];
        }
        if ($cycle['readiness_checked_at'] === null) {
            $soft[] = ['key' => 'readiness', 'text' => 'The readiness checks were never run for this cycle.'];
        }
        if ($stats['live'] === 0) {
            $soft[] = ['key' => 'empty', 'text' => 'No invoices were billed in this cycle.'];
        }

        return ['hard' => $hard, 'soft' => $soft, 'can_close' => !$hard];
    }

    /**
     * Close the cycle. $override must be true (with a note) when soft
     * checks are outstanding.
     *
     * @throws \DomainException with a user-facing message when refused
     */
    public static function close(array $cycle, string $note, bool $override, ?int $userId): array
    {
        $checks = self::preCloseChecks($cycle);
        if ($checks['hard']) {
            throw new \DomainException(implode(' ', array_column($checks['hard'], 'text')));
        }
        if ($checks['soft'] && (!$override || trim($note) === '')) {
            throw new \DomainException('Some checks are outstanding: ' . implode(' ', array_column($checks['soft'], 'text'))
                . ' To close anyway, tick "Close anyway" and say why.');
        }

        $snapshot = self::summary($cycle, true);
        $snapshot['closed_with_open_checks'] = $checks['soft'] ? array_column($checks['soft'], 'text') : [];

        $now = \ff_now_utc();
        $n = \db_execute(
            "UPDATE billing_cycles
                SET status = 'closed', closed_by = ?, closed_at = ?, close_note = ?, close_snapshot = ?
              WHERE id = ? AND status = 'open'",
            [$userId, $now, trim($note) !== '' ? trim($note) : null, json_encode($snapshot), $cycle['id']]
        );
        if ($n === 0) {
            throw new \DomainException('The cycle was closed by someone else a moment ago.');
        }
        BillingCycles::audit($cycle, 'status_change',
            'Closed billing cycle ' . $cycle['reference']
            . ($checks['soft'] ? ' with open checks (' . implode('; ', array_column($checks['soft'], 'text')) . ')' : '')
            . (trim($note) !== '' ? " — {$note}" : '') . '.',
            ['status' => 'open'], ['status' => 'closed', 'live' => $snapshot['invoices']['live'], 'total_cad' => $snapshot['money']['total_cad'] ?? null]);
        return $snapshot;
    }

    /** Reopen a closed cycle (reason required). */
    public static function reopen(array $cycle, string $reason, ?int $userId): void
    {
        if ($cycle['status'] !== 'closed') {
            throw new \DomainException('Only a closed cycle can be reopened.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Say why the cycle is being reopened.');
        }
        $n = \db_execute(
            "UPDATE billing_cycles SET status = 'open', reopened_by = ?, reopened_at = ?, reopen_reason = ?
              WHERE id = ? AND status = 'closed'",
            [$userId, \ff_now_utc(), trim($reason), $cycle['id']]
        );
        if ($n === 0) {
            throw new \DomainException('The cycle was reopened by someone else a moment ago.');
        }
        BillingCycles::audit($cycle, 'status_change', 'Reopened billing cycle ' . $cycle['reference'] . ": {$reason}",
            ['status' => 'closed'], ['status' => 'open']);
    }

    /**
     * Is the month containing [$periodStart, $periodEnd] closed? Used by the
     * workbench endpoints to refuse generating into a closed month.
     * Returns the closed cycle (reference, closed_at) or null.
     */
    public static function closedCycleFor(string $periodStart, string $periodEnd): ?array
    {
        try {
            $row = \db_row(
                "SELECT id, reference, closed_at FROM billing_cycles
                  WHERE status = 'closed' AND period_start <= ? AND period_end >= ?
                  ORDER BY period_start LIMIT 1",
                [$periodEnd, $periodStart]
            );
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('[CycleClose] closedCycleFor failed: ' . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Summary
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The month's billing in figures. Live (from the invoices now); the
     * Close tab shows close_snapshot instead once closed, plus lateAdditions().
     */
    public static function summary(array $cycle, bool $withMoney): array
    {
        $scope  = BillingCycles::scopeSql('i');
        $params = BillingCycles::scopeParams($cycle);
        $stats  = BillingCycles::stats($cycle, $withMoney);
        $cov    = BillingCycles::coverage($cycle, false)['counts'];

        $out = [
            'reference'   => $cycle['reference'],
            'period'      => ['start' => $cycle['period_start'], 'end' => $cycle['period_end'], 'label' => $cycle['label']],
            'generated_at'=> \ff_now_utc(),
            'invoices'    => [
                'by_status' => $stats['by_status'],
                'live'      => $stats['live'],
                'drafts'    => $stats['drafts'],
                'issued'    => $stats['issued'],
                'void'      => $stats['void'],
            ],
            'coverage'    => $cov,
            'delivery'    => [
                'issued'      => $stats['issued'],
                'emailed'     => $stats['emailed'],
                'not_emailed' => max(0, $stats['issued'] - $stats['emailed']),
            ],
            'review'      => ['reviewed' => $stats['reviewed'], 'queried' => $stats['queried']],
            'money'       => null,
            'categories'  => null,
            'customers'   => null,
            'previous'    => null,
            'timeline'    => self::timeline($cycle),
        ];

        if (!$withMoney) {
            return $out;
        }

        $byCur = [];
        foreach (\db_select(
            "SELECT i.currency, COUNT(*) AS n,
                    SUM(i.subtotal) AS subtotal, SUM(i.discount_amount) AS discount,
                    SUM(i.tax_gst_amount) AS gst, SUM(i.tax_pst_amount) AS pst, SUM(i.tax_hst_amount) AS hst,
                    SUM(i.tax_total) AS tax, SUM(i.total_amount) AS total,
                    SUM(i.amount_paid) AS paid, SUM(i.credits_applied) AS credits, SUM(i.balance_due) AS balance
               FROM invoices i WHERE {$scope} AND i.status <> 'void'
              GROUP BY i.currency ORDER BY i.currency",
            $params
        ) as $r) {
            $row = ['count' => (int) $r['n']];
            foreach (['subtotal', 'discount', 'gst', 'pst', 'hst', 'tax', 'total', 'paid', 'credits', 'balance'] as $k) {
                $row[$k] = bcadd((string) $r[$k], '0', 2);
            }
            $byCur[$r['currency']] = $row;
        }
        $cad = \db_row(
            "SELECT COALESCE(SUM(" . BillingCycles::cadSql('total_amount') . "), 0) AS total,
                    COALESCE(SUM(" . BillingCycles::cadSql('tax_total') . "), 0) AS tax,
                    COALESCE(SUM(" . BillingCycles::cadSql('amount_paid') . "), 0) AS paid,
                    COALESCE(SUM(" . BillingCycles::cadSql('balance_due') . "), 0) AS balance
               FROM invoices i WHERE {$scope} AND i.status <> 'void'",
            $params
        );
        $out['money'] = [
            'by_currency' => $byCur,
            'total_cad'   => bcadd((string) $cad['total'], '0', 2),
            'tax_cad'     => bcadd((string) $cad['tax'], '0', 2),
            'paid_cad'    => bcadd((string) $cad['paid'], '0', 2),
            'balance_cad' => bcadd((string) $cad['balance'], '0', 2),
        ];

        // Categories (pre-tax, pre-discount; credit lines negative; CAD).
        $typeToCat = [];
        foreach (self::CATEGORIES as $key => $def) {
            foreach ($def['types'] as $t) $typeToCat[$t] = $key;
        }
        $cats = [];
        foreach (self::CATEGORIES as $key => $def) {
            $cats[$key] = ['key' => $key, 'label' => $def['label'], 'amount_cad' => '0.00'];
        }
        foreach (\db_select(
            "SELECT li.item_type,
                    SUM(CASE WHEN li.is_credit = 1 THEN -li.amount ELSE li.amount END
                        * CASE WHEN i.currency = 'USD' THEN COALESCE(i.exchange_rate_to_cad, 1) ELSE 1 END) AS cad
               FROM invoice_line_items li
               JOIN invoices i ON i.id = li.invoice_id
              WHERE {$scope} AND i.status <> 'void'
              GROUP BY li.item_type",
            $params
        ) as $r) {
            $cat = $typeToCat[$r['item_type']] ?? 'adjustments';
            $cats[$cat]['amount_cad'] = bcadd($cats[$cat]['amount_cad'], bcadd((string) $r['cad'], '0', 2), 2);
        }
        $out['categories'] = array_values($cats);

        $out['customers'] = array_map(static fn($r) => [
            'customer_id'  => (int) $r['customer_id'],
            'company_name' => $r['company_name'],
            'invoices'     => (int) $r['n'],
            'total_cad'    => bcadd((string) $r['cad'], '0', 2),
        ], \db_select(
            "SELECT i.customer_id, COALESCE(c.company_name, MAX(i.company_name_snapshot)) AS company_name,
                    COUNT(*) AS n, SUM(" . BillingCycles::cadSql('total_amount') . ") AS cad
               FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
              WHERE {$scope} AND i.status <> 'void'
              GROUP BY i.customer_id, c.company_name
              ORDER BY cad DESC
              LIMIT 25",
            $params
        ));

        $prevMonth = BillingCycles::shiftMonth(substr((string) $cycle['period_start'], 0, 7), -1);
        [$ps, $pe] = BillingCycles::monthBounds($prevMonth);
        $prev = \db_row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(" . BillingCycles::cadSql('total_amount') . "), 0) AS cad
               FROM invoices i WHERE {$scope} AND i.status <> 'void'",
            [$ps, $pe]
        );
        $prevCad = bcadd((string) $prev['cad'], '0', 2);
        $change  = bcsub($out['money']['total_cad'], $prevCad, 2);
        $out['previous'] = [
            'month'      => $prevMonth,
            'label'      => date('F Y', strtotime($ps)),
            'live'       => (int) $prev['n'],
            'total_cad'  => $prevCad,
            'change_cad' => $change,
            'change_pct' => bccomp($prevCad, '0', 2) !== 0 ? bcmul(bcdiv($change, $prevCad, 6), '100', 1) : null,
        ];

        return $out;
    }

    private static function timeline(array $cycle): array
    {
        $scope  = BillingCycles::scopeSql('i');
        $params = BillingCycles::scopeParams($cycle);
        $t = \db_row(
            "SELECT MIN(i.created_at) AS first_created, MAX(i.created_at) AS last_created,
                    MIN(i.sent_at) AS first_sent, MAX(i.sent_at) AS last_sent
               FROM invoices i WHERE {$scope} AND i.status <> 'void'",
            $params
        );
        $opened = (string) $cycle['created_at'];
        $lastSent = $t['last_sent'] ?? null;
        return [
            'opened_at'     => $opened,
            'first_invoice' => $t['first_created'] ?? null,
            'last_invoice'  => $t['last_created'] ?? null,
            'first_sent'    => $t['first_sent'] ?? null,
            'last_sent'     => $lastSent,
            'closed_at'     => $cycle['closed_at'],
            'days_open_to_sent' => $lastSent ? max(0, (int) floor((strtotime((string) $lastSent) - strtotime($opened)) / 86400)) : null,
        ];
    }

    /** Invoices that joined the cycle after it closed (late lease closes etc.). */
    public static function lateAdditions(array $cycle, bool $withMoney): array
    {
        if ($cycle['status'] !== 'closed' || !$cycle['closed_at']) return [];
        return array_map(static fn($r) => [
            'id'             => (int) $r['id'],
            'invoice_number' => $r['invoice_number'],
            'company_name'   => $r['company_name'],
            'status'         => $r['status'],
            'total_amount'   => $withMoney ? $r['total_amount'] : null,
            'currency'       => $r['currency'],
            'created_at'     => $r['created_at'],
            'generation_source' => $r['generation_source'],
        ], \db_select(
            "SELECT i.id, i.invoice_number, COALESCE(c.company_name, i.company_name_snapshot) AS company_name,
                    i.status, i.total_amount, i.currency, i.created_at, i.generation_source
               FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
              WHERE " . BillingCycles::scopeSql('i') . " AND i.created_at > ?
              ORDER BY i.created_at",
            array_merge(BillingCycles::scopeParams($cycle), [$cycle['closed_at']])
        ));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Billing register (CSV)
    // ─────────────────────────────────────────────────────────────────────

    /** Header + rows for the cycle's billing register, void rows included. */
    public static function registerRows(array $cycle): array
    {
        $rows = \db_select(
            "SELECT i.invoice_number, i.status, i.invoice_type, i.billing_type,
                    COALESCE(c.company_name, i.company_name_snapshot) AS company_name,
                    COALESCE(i.contract_number_snapshot, l.contract_number) AS contract_number,
                    i.unit_number_invoice_snapshot AS unit_number,
                    i.billing_period_start, i.billing_period_end, i.invoice_date, i.due_date,
                    i.currency, i.subtotal, i.discount_amount, i.tax_gst_amount, i.tax_pst_amount, i.tax_hst_amount,
                    i.tax_total, i.total_amount, i.exchange_rate_to_cad,
                    " . BillingCycles::cadSql('total_amount') . " AS total_cad,
                    i.amount_paid, i.credits_applied, i.balance_due,
                    i.sent_at, i.sent_to_email, i.generation_source, i.po_number,
                    (SELECT MAX(el.sent_at) FROM email_logs el
                      WHERE el.entity_type = 'invoice' AND el.entity_id = i.id AND el.status = 'sent') AS last_emailed_at,
                    r.status AS review_status
               FROM invoices i
               LEFT JOIN customers c ON c.id = i.customer_id
               LEFT JOIN leases l ON l.id = i.lease_id
               LEFT JOIN billing_cycle_reviews r ON r.cycle_id = ? AND r.invoice_id = i.id
              WHERE " . BillingCycles::scopeSql('i') . "
              ORDER BY i.invoice_number",
            array_merge([$cycle['id']], BillingCycles::scopeParams($cycle))
        );

        $header = ['Invoice #', 'Status', 'Type', 'Billing type', 'Customer', 'Contract', 'Unit',
            'Period start', 'Period end', 'Invoice date', 'Due date', 'PO', 'Currency',
            'Subtotal', 'Discount', 'GST', 'PST', 'HST', 'Tax total', 'Total', 'USD→CAD rate', 'Total (CAD)',
            'Paid', 'Credits applied', 'Balance', 'Sent at (UTC)', 'Sent to', 'Last emailed (UTC)', 'Review', 'Created by'];
        $out = [$header];
        foreach ($rows as $r) {
            $out[] = [
                $r['invoice_number'], $r['status'], $r['invoice_type'], $r['billing_type'],
                self::csvText($r['company_name']), self::csvText($r['contract_number']), self::csvText($r['unit_number']),
                $r['billing_period_start'], $r['billing_period_end'], $r['invoice_date'], $r['due_date'],
                self::csvText($r['po_number']), $r['currency'],
                $r['subtotal'], $r['discount_amount'], $r['tax_gst_amount'], $r['tax_pst_amount'], $r['tax_hst_amount'],
                $r['tax_total'], $r['total_amount'], $r['exchange_rate_to_cad'], bcadd((string) $r['total_cad'], '0', 2),
                $r['amount_paid'], $r['credits_applied'], $r['balance_due'],
                $r['sent_at'], self::csvText($r['sent_to_email']), $r['last_emailed_at'],
                $r['review_status'] ?? '', $r['generation_source'] ?? '',
            ];
        }
        return $out;
    }

    /** Neutralise spreadsheet formula injection in free-text cells. */
    private static function csvText(?string $v): string
    {
        $v = (string) $v;
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
    }
}
