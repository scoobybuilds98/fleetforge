<?php
declare(strict_types=1);

namespace FleetForge\Billing\Cycle;

/**
 * lib/Billing/Cycle/BillingCycles.php
 *
 * S-BILLING-MODULE — the monthly billing cycle record and everything that is
 * DERIVED from it: which invoices belong to it, which leases it has to bill,
 * the per-lease coverage picture, headline stats and the stage stepper.
 *
 * ── Membership is derived, never stamped ──────────────────────────────────
 * A cycle owns no invoice rows. An invoice belongs to the cycle whose month
 * contains its billing_period_start (lease invoices only; late-fee invoices
 * and credit notes are follow-up documents, not the month's billing). That
 * way every generation path — the Billing workbench, the monthly cron, a
 * lease's Generate Invoice, a lease close, activation — lands in the right
 * cycle without InvoiceGenerator knowing cycles exist, and a cycle opened
 * after the fact (a backlog month) immediately shows what is already there.
 *
 * ── The lease universe ────────────────────────────────────────────────────
 * The leases a cycle is responsible for: every non-deleted lease that was on
 * rent for at least one day of the month — active leases that started by the
 * month end, and completed leases whose return (else end) date reaches into
 * the month. Pending and cancelled leases never bill. Completed leases are
 * in the universe so a closed lease with an unbilled tail shows up instead of
 * slipping through (the workbench only bills ACTIVE monthly leases).
 *
 * @session S-BILLING-MODULE
 */
final class BillingCycles
{
    private function __construct() {}

    /** Invoice types that make up a month's billing (late fees / credit notes excluded). */
    public const CYCLE_INVOICE_TYPES = ['regular', 'final', 'mileage_only', 'adjustment'];

    /**
     * Billing types that bill RENTAL days. mileage_only / adjustment invoices
     * (close-time mileage true-ups, reclose adjustments) legitimately share
     * days with the rental invoice, so they never count as "the month is
     * billed" nor as a double bill.
     */
    public const RENTAL_BILLING_TYPES = ['partial_start', 'full_month', 'partial_end', 'single_period'];

    /** Statuses that are "live" (issued or about to be). void = not billed. */
    public const LIVE_STATUSES = ['draft', 'sent', 'partially_paid', 'paid', 'overdue', 'written_off'];

    // ─────────────────────────────────────────────────────────────────────
    // Month helpers
    // ─────────────────────────────────────────────────────────────────────

    /** True for a well-formed 'YYYY-MM' between 2000-01 and 2099-12. */
    public static function isValidMonth(?string $month): bool
    {
        if (!is_string($month) || !preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            return false;
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        return $y >= 2000 && $y <= 2099 && $mo >= 1 && $mo <= 12;
    }

    /** 'YYYY-MM' → ['YYYY-MM-01', 'YYYY-MM-<last>']. */
    public static function monthBounds(string $month): array
    {
        $start = $month . '-01';
        return [$start, date('Y-m-t', strtotime($start))];
    }

    /** Any Y-m-d → its 'YYYY-MM'. */
    public static function monthOf(string $date): string
    {
        return substr($date, 0, 7);
    }

    /** 'YYYY-MM' ± N months. */
    public static function shiftMonth(string $month, int $by): string
    {
        return date('Y-m', strtotime(sprintf('%s-01 %+d month', $month, $by)));
    }

    /** 'BC-2026-09'. */
    public static function reference(string $month): string
    {
        return 'BC-' . $month;
    }

    /** 'September 2026'. */
    public static function label(string $periodStart): string
    {
        return date('F Y', strtotime($periodStart));
    }

    /**
     * The month a cycle opened today should bill, per billing_cycle.mode:
     * arrears (default) = last month, advance = this month.
     */
    public static function targetMonth(?string $today = null): string
    {
        $today ??= \ff_today();
        $thisMonth = substr($today, 0, 7);
        $mode = (string) \settings_get('billing_cycle.mode', 'arrears');
        return $mode === 'advance' ? $thisMonth : self::shiftMonth($thisMonth, -1);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Record access
    // ─────────────────────────────────────────────────────────────────────

    public static function find(int $id): ?array
    {
        return self::hydrate(\db_row(
            "SELECT bc.*, ou.name AS owner_name, cu.name AS closed_by_name,
                    ru.name AS reopened_by_name, opu.name AS opened_by_name
               FROM billing_cycles bc
               LEFT JOIN users ou  ON ou.id  = bc.owner_user_id
               LEFT JOIN users cu  ON cu.id  = bc.closed_by
               LEFT JOIN users ru  ON ru.id  = bc.reopened_by
               LEFT JOIN users opu ON opu.id = bc.opened_by
              WHERE bc.id = ?",
            [$id]
        ));
    }

    public static function findByMonth(string $month): ?array
    {
        if (!self::isValidMonth($month)) return null;
        $row = \db_row("SELECT id FROM billing_cycles WHERE period_start = ?", [$month . '-01']);
        return $row ? self::find((int) $row['id']) : null;
    }

    /**
     * Return the cycle for a month, creating it if it does not exist yet.
     * Idempotent under concurrency: INSERT IGNORE on the unique period_start,
     * then re-select — two tabs opening the same month get the same row.
     *
     * @return array{0: array, 1: bool} [cycle, created]
     */
    public static function ensure(string $month, ?int $userId): array
    {
        if (!self::isValidMonth($month)) {
            throw new \InvalidArgumentException('Month must be YYYY-MM.');
        }
        $existing = self::findByMonth($month);
        if ($existing) {
            return [$existing, false];
        }

        [$start, $end] = self::monthBounds($month);
        [$billBy, $sendBy] = self::defaultTargets($start, $end);
        $owner = (int) \settings_get('billing_cycle.owner_user_id', '0');

        $inserted = \db_execute(
            "INSERT IGNORE INTO billing_cycles
                (reference, period_start, period_end, status, owner_user_id,
                 bill_by_date, send_by_date, opened_by)
             VALUES (?, ?, ?, 'open', ?, ?, ?, ?)",
            [self::reference($month), $start, $end, $owner > 0 ? $owner : null, $billBy, $sendBy, $userId]
        );
        $cycle = self::findByMonth($month);
        if (!$cycle) {
            throw new \RuntimeException('Billing cycle could not be created.');
        }
        if ($inserted > 0) {
            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userId ? self::actor()['name'] : 'system',
                'action'       => 'create',
                'module'       => 'billing',
                'entity_type'  => 'billing_cycle',
                'entity_id'    => $cycle['id'],
                'entity_label' => $cycle['reference'],
                'notes'        => 'Opened billing cycle ' . $cycle['reference'] . ' (' . self::label($start) . ')'
                                . ($userId ? '.' : ' — opened by the scheduled job.'),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        }
        return [$cycle, $inserted > 0];
    }

    /**
     * Target dates for a new cycle. The clock starts when the month can
     * actually be billed: in arrears that is the day after the month ends
     * (never before today); in advance it is the month's first day.
     *
     * @return array{0: string, 1: string} [bill_by, send_by]
     */
    public static function defaultTargets(string $periodStart, string $periodEnd): array
    {
        $today = \ff_today();
        $mode  = (string) \settings_get('billing_cycle.mode', 'arrears');
        $base  = $mode === 'advance'
            ? max($today, $periodStart)
            : max($today, date('Y-m-d', strtotime($periodEnd . ' +1 day')));
        $billDays = max(0, min(60, (int) \settings_get('billing_cycle.bill_by_days', '3')));
        $sendDays = max($billDays, min(60, (int) \settings_get('billing_cycle.send_by_days', '5')));
        return [
            date('Y-m-d', strtotime("{$base} +{$billDays} day")),
            date('Y-m-d', strtotime("{$base} +{$sendDays} day")),
        ];
    }

    private static function hydrate(?array $row): ?array
    {
        if (!$row) return null;
        $row['id'] = (int) $row['id'];
        $row['month'] = substr((string) $row['period_start'], 0, 7);
        $row['label'] = self::label((string) $row['period_start']);
        foreach (['readiness_ack', 'readiness_summary', 'close_snapshot', 'step_signoffs'] as $k) {
            $row[$k] = $row[$k] !== null ? (json_decode((string) $row[$k], true) ?: null) : null;
        }
        $row['readiness_ack'] ??= [];
        $row['step_signoffs'] ??= [];
        return $row;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invoice membership
    // ─────────────────────────────────────────────────────────────────────

    /** SQL predicate for "invoice alias $a belongs to the cycle" — bind scopeParams(). */
    public static function scopeSql(string $a = 'i'): string
    {
        $types = "'" . implode("','", self::CYCLE_INVOICE_TYPES) . "'";
        return "{$a}.deleted_at IS NULL AND {$a}.lease_id IS NOT NULL"
             . " AND {$a}.invoice_type IN ({$types})"
             . " AND {$a}.billing_period_start BETWEEN ? AND ?";
    }

    public static function scopeParams(array $cycle): array
    {
        return [$cycle['period_start'], $cycle['period_end']];
    }

    /** CAD equivalent of an invoice money column (reporting policy: CAD canonical). */
    public static function cadSql(string $col, string $a = 'i'): string
    {
        return "CASE WHEN {$a}.currency = 'USD' THEN ROUND({$a}.{$col} * COALESCE({$a}.exchange_rate_to_cad, 1), 2) ELSE {$a}.{$col} END";
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lease universe + coverage
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Leases the cycle is responsible for (see file docblock), with the
     * customer and unit context every tab needs.
     */
    public static function universe(array $cycle): array
    {
        return \db_select(
            "SELECT l.id, l.contract_number, l.customer_id, l.status, l.billing_cycle,
                    l.start_date, l.end_date, l.actual_return_date, l.currency,
                    l.mileage_tracking_mode, l.mileage_unit, l.monthly_rate, l.daily_rate, l.weekly_rate,
                    l.hourly_rate, l.mileage_rate, l.mileage_rate_km, l.po_number, l.precharge_enabled,
                    l.equipment_unit_id,
                    c.company_name, c.status AS customer_status,
                    COALESCE(eu.unit_number, l.unit_number_snapshot) AS unit_number
               FROM leases l
               JOIN customers c ON c.id = l.customer_id
               LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
              WHERE l.deleted_at IS NULL
                AND l.start_date <= ?
                AND (
                      l.status = 'active'
                   OR (l.status = 'completed'
                       AND COALESCE(l.actual_return_date, l.end_date, l.start_date) >= ?)
                )
              ORDER BY c.company_name, l.contract_number",
            [$cycle['period_end'], $cycle['period_start']]
        );
    }

    /**
     * Per-lease picture for the month. One status per lease, in priority:
     *   billed            live invoice(s) in this cycle
     *   covered_elsewhere a live invoice from outside the cycle's scope covers
     *                     the lease's days in the month (advance bill, a
     *                     custom range that started last month)
     *   held              on a billing hold for this period
     *   exception         an open billing exception for this month
     *   bills_at_close    active, billing_cycle = on_close_only
     *   closed_unbilled   completed lease with unbilled days in the month —
     *                     use the lease's Generate Invoice
     *   void_rebillable   only void invoices in the cycle
     *   to_bill           none of the above: the workbench should bill it
     *
     * @return array{rows: array, counts: array<string,int>}
     */
    public static function coverage(array $cycle, bool $withMoney = true): array
    {
        $leases = self::universe($cycle);
        if (!$leases) {
            return ['rows' => [], 'counts' => []];
        }
        $ids = array_map(static fn($l) => (int) $l['id'], $leases);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        // Every invoice touching the month for these leases, in or out of scope.
        $invRows = \db_select(
            "SELECT i.id, i.lease_id, i.invoice_number, i.status, i.invoice_type, i.billing_type, i.total_amount,
                    i.currency, i.billing_period_start, i.billing_period_end
               FROM invoices i
              WHERE i.deleted_at IS NULL AND i.lease_id IN ({$ph})
                AND i.billing_period_start <= ? AND i.billing_period_end >= ?
                AND i.invoice_type IN ('" . implode("','", self::CYCLE_INVOICE_TYPES) . "')
              ORDER BY i.billing_period_start, i.id",
            array_merge($ids, [$cycle['period_end'], $cycle['period_start']])
        );
        $byLease = [];
        foreach ($invRows as $r) {
            $byLease[(int) $r['lease_id']][] = $r;
        }

        $holds = BillingHolds::heldMap($ids, $cycle['period_start'], $cycle['period_end']);

        $excRows = \db_select(
            "SELECT id, lease_id, reason FROM invoice_billing_exceptions
              WHERE deleted_at IS NULL AND status = 'open' AND lease_id IN ({$ph})
                AND period_start <= ? AND period_end >= ?",
            array_merge($ids, [$cycle['period_end'], $cycle['period_start']])
        );
        $exc = [];
        foreach ($excRows as $r) {
            $exc[(int) $r['lease_id']] = $r;
        }

        $rows = [];
        $counts = [];
        foreach ($leases as $l) {
            $lid = (int) $l['id'];
            $inScope = [];
            $rentalInScope = 0;
            $voidInScope = 0;
            $coveredBy = null;
            $firstDay = max($cycle['period_start'], (string) $l['start_date']);
            foreach ($byLease[$lid] ?? [] as $inv) {
                $isScope = $inv['billing_period_start'] >= $cycle['period_start']
                        && $inv['billing_period_start'] <= $cycle['period_end'];
                $isRental = in_array($inv['billing_type'], self::RENTAL_BILLING_TYPES, true);
                if ($inv['status'] === 'void') {
                    if ($isScope && $isRental) $voidInScope++;
                    continue;
                }
                if ($isScope) {
                    $inScope[] = $inv;
                    if ($isRental) $rentalInScope++;
                } elseif ($isRental && $inv['billing_period_end'] >= $firstDay) {
                    $coveredBy ??= $inv;
                }
            }

            $status = 'to_bill';
            if ($rentalInScope > 0) {
                $status = 'billed';
            } elseif ($coveredBy) {
                $status = 'covered_elsewhere';
            } elseif (isset($holds[$lid])) {
                $status = 'held';
            } elseif (isset($exc[$lid])) {
                $status = 'exception';
            } elseif ($l['status'] === 'active' && $l['billing_cycle'] === 'on_close_only') {
                $status = 'bills_at_close';
            } elseif ($l['status'] === 'completed') {
                $status = 'closed_unbilled';
            } elseif ($voidInScope > 0) {
                $status = 'void_rebillable';
            }
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            $invoices = array_map(static function ($inv) use ($withMoney) {
                return [
                    'id'             => (int) $inv['id'],
                    'invoice_number' => $inv['invoice_number'],
                    'status'         => $inv['status'],
                    'total_amount'   => $withMoney ? $inv['total_amount'] : null,
                    'currency'       => $inv['currency'],
                    'period'         => $inv['billing_period_start'] . '..' . $inv['billing_period_end'],
                ];
            }, $inScope);

            $rows[] = [
                'lease_id'        => $lid,
                'contract_number' => $l['contract_number'],
                'customer_id'     => (int) $l['customer_id'],
                'company_name'    => $l['company_name'],
                'unit_number'     => $l['unit_number'],
                'lease_status'    => $l['status'],
                'billing_cycle'   => $l['billing_cycle'],
                'start_date'      => $l['start_date'],
                'end_date'        => $l['end_date'],
                'return_date'     => $l['actual_return_date'],
                'status'          => $status,
                'invoices'        => $invoices,
                'covered_by'      => $coveredBy ? [
                    'id' => (int) $coveredBy['id'], 'invoice_number' => $coveredBy['invoice_number'],
                    'period' => $coveredBy['billing_period_start'] . '..' . $coveredBy['billing_period_end'],
                ] : null,
                'hold'            => isset($holds[$lid]) ? [
                    'id' => (int) $holds[$lid]['id'], 'reason' => $holds[$lid]['reason'],
                    'scope' => $holds[$lid]['scope'],
                ] : null,
                'exception'       => isset($exc[$lid]) ? [
                    'id' => (int) $exc[$lid]['id'], 'reason' => $exc[$lid]['reason'],
                ] : null,
            ];
        }
        return ['rows' => $rows, 'counts' => $counts];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Stats + stage
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Headline numbers for a cycle. Money keys are null when $withMoney is
     * false (dispatchers see counts, never amounts).
     */
    public static function stats(array $cycle, bool $withMoney = true): array
    {
        $scope  = self::scopeSql('i');
        $params = self::scopeParams($cycle);

        $byStatus = [];
        foreach (\db_select(
            "SELECT i.status, COUNT(*) AS n FROM invoices i WHERE {$scope} GROUP BY i.status",
            $params
        ) as $r) {
            $byStatus[$r['status']] = (int) $r['n'];
        }
        $live   = array_sum(array_intersect_key($byStatus, array_flip(self::LIVE_STATUSES)));
        $drafts = $byStatus['draft'] ?? 0;

        $money = null;
        if ($withMoney) {
            $byCur = [];
            foreach (\db_select(
                "SELECT i.currency, COUNT(*) AS n, SUM(i.total_amount) AS total,
                        SUM(i.tax_total) AS tax, SUM(i.balance_due) AS balance
                   FROM invoices i WHERE {$scope} AND i.status <> 'void'
                  GROUP BY i.currency",
                $params
            ) as $r) {
                $byCur[$r['currency']] = [
                    'count'   => (int) $r['n'],
                    'total'   => bcadd((string) $r['total'], '0', 2),
                    'tax'     => bcadd((string) $r['tax'], '0', 2),
                    'balance' => bcadd((string) $r['balance'], '0', 2),
                ];
            }
            $cad = \db_row(
                "SELECT COALESCE(SUM(" . self::cadSql('total_amount') . "), 0) AS total_cad,
                        COALESCE(SUM(CASE WHEN i.status = 'draft' THEN " . self::cadSql('total_amount') . " ELSE 0 END), 0) AS draft_cad,
                        COALESCE(SUM(CASE WHEN i.status IN ('sent','partially_paid','overdue') THEN " . self::cadSql('balance_due') . " ELSE 0 END), 0) AS open_cad,
                        COALESCE(SUM(CASE WHEN i.status IN ('paid','partially_paid','sent','overdue') THEN " . self::cadSql('amount_paid') . " ELSE 0 END), 0) AS paid_cad
                   FROM invoices i WHERE {$scope} AND i.status <> 'void'",
                $params
            );
            $money = [
                'by_currency' => $byCur,
                'total_cad'   => bcadd((string) $cad['total_cad'], '0', 2),
                'draft_cad'   => bcadd((string) $cad['draft_cad'], '0', 2),
                'open_cad'    => bcadd((string) $cad['open_cad'], '0', 2),
                'paid_cad'    => bcadd((string) $cad['paid_cad'], '0', 2),
            ];
        }

        $emailed = (int) \db_count(
            "SELECT COUNT(DISTINCT i.id) FROM invoices i
               JOIN email_logs el ON el.entity_type = 'invoice' AND el.entity_id = i.id AND el.status = 'sent'
              WHERE {$scope} AND i.status NOT IN ('void','draft')",
            $params
        );
        $reviewed = (int) \db_count(
            "SELECT COUNT(*) FROM billing_cycle_reviews r
               JOIN invoices i ON i.id = r.invoice_id
              WHERE r.cycle_id = ? AND r.status = 'reviewed' AND {$scope} AND i.status <> 'void'",
            array_merge([$cycle['id']], $params)
        );
        $queried = (int) \db_count(
            "SELECT COUNT(*) FROM billing_cycle_reviews r
               JOIN invoices i ON i.id = r.invoice_id
              WHERE r.cycle_id = ? AND r.status = 'query' AND {$scope} AND i.status <> 'void'",
            array_merge([$cycle['id']], $params)
        );
        $unreviewedDrafts = (int) \db_count(
            "SELECT COUNT(*) FROM invoices i
               LEFT JOIN billing_cycle_reviews r ON r.invoice_id = i.id AND r.cycle_id = ? AND r.status = 'reviewed'
              WHERE {$scope} AND i.status = 'draft' AND r.id IS NULL",
            array_merge([$cycle['id']], $params)
        );
        $openExceptions = (int) \db_count(
            "SELECT COUNT(*) FROM invoice_billing_exceptions
              WHERE deleted_at IS NULL AND status = 'open' AND period_start <= ? AND period_end >= ?",
            [$cycle['period_end'], $cycle['period_start']]
        );
        $pendingRuns = (int) \db_count(
            "SELECT COUNT(*) FROM invoice_batch_runs
              WHERE deleted_at IS NULL AND status IN ('pending','approved')
                AND period_start <= ? AND period_end >= ?",
            [$cycle['period_end'], $cycle['period_start']]
        );

        return [
            'by_status'         => $byStatus,
            'live'              => $live,
            'drafts'            => $drafts,
            'issued'            => $live - $drafts,
            'void'              => $byStatus['void'] ?? 0,
            'emailed'           => $emailed,
            'reviewed'          => $reviewed,
            'queried'           => $queried,
            'unreviewed_drafts' => $unreviewedDrafts,
            'open_exceptions'   => $openExceptions,
            'pending_runs'      => $pendingRuns,
            'money'             => $money,
        ];
    }

    /**
     * The seven-step stepper. Each step: key, label, state
     * (done | current | todo | skipped | attention), hint.
     */
    public static function stage(array $cycle, array $stats, array $coverageCounts, array $readingsProgress): array
    {
        $closed      = $cycle['status'] === 'closed';
        $summary     = $cycle['readiness_summary'] ?? null;
        $blockers    = (int) ($summary['blocker'] ?? 0);
        $toBill      = (int) ($coverageCounts['to_bill'] ?? 0) + (int) ($coverageCounts['void_rebillable'] ?? 0);
        $approvalOn  = (string) \settings_get('invoices.approval_required', '0') === '1';

        $steps = [];
        $steps[] = [
            'key' => 'prepare', 'label' => 'Prepare',
            'done' => $cycle['readiness_checked_at'] !== null && $blockers === 0,
            'attention' => $blockers > 0,
            'hint' => $cycle['readiness_checked_at'] === null
                ? 'Run the readiness checks'
                : ($blockers > 0 ? "{$blockers} blocker(s) to fix" : 'Checks clear'),
        ];
        $steps[] = [
            'key' => 'readings', 'label' => 'Readings',
            'done' => $readingsProgress['required'] === 0 || $readingsProgress['missing'] === 0,
            'skipped' => $readingsProgress['required'] === 0,
            'hint' => $readingsProgress['required'] === 0
                ? 'No manual readings needed'
                : "{$readingsProgress['entered']} of {$readingsProgress['required']} entered",
        ];
        $steps[] = [
            'key' => 'generate', 'label' => 'Generate',
            'done' => $stats['live'] > 0 && $toBill === 0,
            'hint' => $toBill > 0 ? "{$toBill} lease(s) still to bill" : ($stats['live'] > 0 ? 'Every lease accounted for' : 'Nothing billed yet'),
        ];
        $steps[] = [
            'key' => 'review', 'label' => 'Review',
            'done' => $stats['live'] > 0 && $stats['unreviewed_drafts'] === 0 && $stats['queried'] === 0,
            'attention' => $stats['queried'] > 0 || $stats['open_exceptions'] > 0,
            'hint' => $stats['queried'] > 0
                ? "{$stats['queried']} queried"
                : ($stats['unreviewed_drafts'] > 0 ? "{$stats['unreviewed_drafts']} draft(s) not reviewed" : 'Reviewed'),
        ];
        $steps[] = [
            'key' => 'approve', 'label' => 'Approve',
            'done' => !$approvalOn || $stats['pending_runs'] === 0,
            'skipped' => !$approvalOn,
            'hint' => !$approvalOn ? 'Approval not required' : ($stats['pending_runs'] > 0 ? "{$stats['pending_runs']} run(s) waiting" : 'No runs waiting'),
        ];
        $steps[] = [
            'key' => 'send', 'label' => 'Send',
            'done' => $stats['live'] > 0 && $stats['drafts'] === 0,
            'hint' => $stats['drafts'] > 0 ? "{$stats['drafts']} draft(s) unsent" : ($stats['live'] > 0 ? 'All sent' : 'Nothing to send'),
        ];
        $steps[] = [
            'key' => 'close', 'label' => 'Close',
            'done' => $closed,
            'hint' => $closed ? 'Closed ' . substr((string) $cycle['closed_at'], 0, 10) : 'Close and lock the month',
        ];

        // First not-done, not-skipped step is "current". A manual sign-off
        // (step_signoffs) is shown alongside — it records who finished the
        // step; the derived state still says whether the work is really done.
        $signoffs = is_array($cycle['step_signoffs'] ?? null) ? $cycle['step_signoffs'] : [];
        $currentSet = false;
        foreach ($steps as &$s) {
            $s['signoff'] = $signoffs[$s['key']] ?? null;
            $s['skipped'] = !empty($s['skipped']);
            $s['attention'] = !empty($s['attention']);
            if ($s['done'] || $s['skipped']) {
                $s['state'] = $s['skipped'] ? 'skipped' : 'done';
            } elseif (!$currentSet && !$closed) {
                $s['state'] = 'current';
                $currentSet = true;
            } else {
                $s['state'] = 'todo';
            }
            // attention stays a separate flag: a step can be current AND need attention.
            if ($s['state'] === 'done') {
                $s['attention'] = false;
            }
        }
        unset($s);

        $doneCount = count(array_filter($steps, static fn($s) => in_array($s['state'], ['done', 'skipped'], true)));
        return [
            'steps'    => $steps,
            'percent'  => (int) round($doneCount / count($steps) * 100),
            'current'  => $closed ? 'close' : (current(array_filter($steps, static fn($s) => $s['state'] === 'current'))['key'] ?? 'close'),
        ];
    }

    /** Everything the cycle page header + overview need, in one call. */
    public static function overview(array $cycle, bool $withMoney): array
    {
        $stats    = self::stats($cycle, $withMoney);
        $coverage = self::coverage($cycle, false);
        $readings = CycleReadings::progress($cycle);
        return [
            'stats'    => $stats,
            'coverage' => $coverage['counts'],
            'readings' => $readings,
            'stage'    => self::stage($cycle, $stats, $coverage['counts'], $readings),
            'overdue'  => [
                'bill_by' => $cycle['status'] === 'open' && $cycle['bill_by_date'] && \ff_today() > $cycle['bill_by_date']
                             && ($stats['unreviewed_drafts'] > 0 || ($coverage['counts']['to_bill'] ?? 0) > 0),
                'send_by' => $cycle['status'] === 'open' && $cycle['send_by_date'] && \ff_today() > $cycle['send_by_date']
                             && $stats['drafts'] > 0,
            ],
        ];
    }

    /**
     * Who is acting: the signed-in user, or 'system' for the scheduled job /
     * CLI (includes/auth.php — current_user*() — is not loaded there).
     *
     * @return array{id: ?int, name: string}
     */
    public static function actor(): array
    {
        if (function_exists('current_user_id') && function_exists('current_user')) {
            $u = \current_user();
            return ['id' => \current_user_id(), 'name' => (string) ($u['name'] ?? 'system')];
        }
        return ['id' => null, 'name' => 'system'];
    }

    /** Steps a person can sign off. Close is not one: closing the cycle IS its sign-off. */
    public const SIGNOFF_STEPS = ['prepare', 'readings', 'generate', 'review', 'approve', 'send'];

    /**
     * Sign off one step of the cycle (who finished it, when, optional note),
     * or withdraw that sign-off. Stored in billing_cycles.step_signoffs and
     * shown on the stepper beside the derived "done" state — a record of who
     * did the work, never a gate. Audited. (S-BILLING-MODULE-2)
     *
     * @return array<string, array{by:string, by_id:?int, at:string, note:?string}> the new map
     * @throws \InvalidArgumentException on an unknown step
     */
    public static function signoff(array $cycle, string $step, bool $signed, ?string $note = null): array
    {
        if (!in_array($step, self::SIGNOFF_STEPS, true)) {
            throw new \InvalidArgumentException('Unknown step.');
        }
        // Read under a row lock so two people signing different steps at the
        // same moment cannot drop each other's entry (read-modify-write of one JSON column).
        return \db_transaction(static function () use ($cycle, $step, $signed, $note): array {
            $row = \db_row("SELECT step_signoffs FROM billing_cycles WHERE id = ? FOR UPDATE", [$cycle['id']]);
            $map = $row && $row['step_signoffs'] ? (json_decode((string) $row['step_signoffs'], true) ?: []) : [];
            if ($signed) {
                $actor = self::actor();
                $map[$step] = ['by' => $actor['name'], 'by_id' => $actor['id'], 'at' => \ff_now_utc(), 'note' => $note];
            } else {
                unset($map[$step]);
            }
            \db_execute("UPDATE billing_cycles SET step_signoffs = ? WHERE id = ?", [$map ? json_encode($map) : null, $cycle['id']]);
            self::audit($cycle, 'update', ($signed ? 'Signed off' : 'Withdrew sign-off of') . " step '{$step}'" . ($note ? ": {$note}" : '.'));
            return $map;
        });
    }

    /** Record an audit row against a cycle. */
    public static function audit(array $cycle, string $action, string $notes, $old = null, $new = null): void
    {
        $actor = self::actor();
        \db_insert('audit_log', [
            'user_id'      => $actor['id'],
            'user_name'    => $actor['name'],
            'action'       => $action,
            'module'       => 'billing',
            'entity_type'  => 'billing_cycle',
            'entity_id'    => $cycle['id'],
            'entity_label' => $cycle['reference'],
            'old_values'   => $old !== null ? json_encode($old) : null,
            'new_values'   => $new !== null ? json_encode($new) : null,
            'notes'        => $notes,
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }
}
