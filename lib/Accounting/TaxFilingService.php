<?php
declare(strict_types=1);

/**
 * lib/Accounting/TaxFilingService.php
 *
 * Tax filing period lifecycle — create, calculate, file, remit.
 *
 * Each acc_tax_filing_periods row represents one filing window
 * (monthly / quarterly / annually) for one tax authority
 * (gst_hst / pst_bc / pst_sk / pst_mb). The lifecycle is:
 *
 *     open ──calculate──▶ calculated ──markFiled──▶ filed
 *                                                     │
 *                                              recordRemittance
 *                                                     ▼
 *                                                   remitted (terminal)
 *
 * On `recordRemittance`, the service creates a single consolidated JE
 *   DR  2030 GST/HST Payable        [amount]   (or 2040 PST Payable)
 *     CR 1010 Cash (or selected bank GL acct) [amount]
 * inside the same db_transaction as the acc_tax_remittances insert,
 * so a JE failure rolls back the remittance row and vice-versa.
 *
 * Required by: api/v1/accounting/tax/**, app/admin/accounting/tax/**
 * Depends on:  AccountingService (period lookup), JournalEntryService
 *
 * Decisions: A8 (JE backbone — nothing bypasses GL),
 *            §16 (closed-period redirect for auto-JEs)
 * Spec ref:  FLEETFORGE_ACCOUNTING_SPEC.md §2.9 (schema), §9 (workflow)
 *
 * Created in S035 — Tax Management module.
 */

namespace FleetForge\Accounting;

class TaxFilingService
{
    // ============================================================
    // CONSTANTS — GL account codes for tax payable / receivable
    // The codes are stable (locked in COA seed 010); the IDs are
    // resolved at runtime via accountIdByCode() to keep the service
    // robust against re-seeding.
    // ============================================================
    private const ACCT_CODE_CASH         = '1010';   // Cash — Operating
    private const ACCT_CODE_GST_ITC      = '1050';   // GST/HST Receivable (ITC)
    private const ACCT_CODE_GST_PAYABLE  = '2030';   // GST/HST Payable
    private const ACCT_CODE_PST_PAYABLE  = '2040';   // PST Payable

    // Province code for PST jurisdiction filtering. customers.province
    // is a free-text VARCHAR — the seed data uses two-letter codes.
    private const PROVINCE_FOR_TAX = [
        'pst_bc' => 'BC',
        'pst_sk' => 'SK',
        'pst_mb' => 'MB',
    ];

    // ============================================================
    // CREATE PERIOD
    // ============================================================

    /**
     * Create a new tax filing period.
     *
     * Validates tax_type / period_start / period_end / frequency,
     * computes filing_due_date per CRA rules, and inserts a row in
     * status='open' with audit_log inside a db_transaction.
     *
     * @param array $data
     *   - tax_type     ENUM('gst_hst','pst_bc','pst_sk','pst_mb')
     *   - period_start Y-m-d
     *   - period_end   Y-m-d
     *   - frequency    ENUM('monthly','quarterly','annually')
     *   - notes        optional free text
     * @param int|null $userId
     * @return int  new period id
     * @throws \RuntimeException on validation failure or duplicate
     */
    public static function createPeriod(array $data, ?int $userId = null): int
    {
        // ── Validation ──────────────────────────────────────
        $taxType = (string) ($data['tax_type'] ?? '');
        if (!in_array($taxType, ['gst_hst', 'pst_bc', 'pst_sk', 'pst_mb'], true)) {
            throw new \RuntimeException('Invalid tax_type. Must be one of: gst_hst, pst_bc, pst_sk, pst_mb.');
        }

        $start = (string) ($data['period_start'] ?? '');
        $end   = (string) ($data['period_end']   ?? '');
        if (!\clean_date($start) || !\clean_date($end)) {
            throw new \RuntimeException('period_start and period_end must be valid Y-m-d dates.');
        }
        if (strtotime($end) < strtotime($start)) {
            throw new \RuntimeException('period_end must be on or after period_start.');
        }

        $frequency = (string) ($data['frequency'] ?? '');
        if (!in_array($frequency, ['monthly', 'quarterly', 'annually'], true)) {
            throw new \RuntimeException('Invalid frequency. Must be monthly, quarterly, or annually.');
        }

        // ── Compute filing_due_date per CRA rules ───────────
        // monthly / quarterly: end of month following period_end
        // annually:            end of third month following period_end
        // WHY "last day of": Reporting periods always end on the last day of
        // a month. Naive `strtotime('2026-03-31 +1 month')` returns May 1
        // (rolls over because April has 30 days). Using "last day of"
        // clamps correctly: Mar 31 → Apr 30, Jan 31 → Feb 28, etc.
        try {
            $endDt = new \DateTimeImmutable($end);
        } catch (\Exception $e) {
            throw new \RuntimeException('Invalid period_end date.');
        }
        $modifier = $frequency === 'annually' ? 'last day of +3 months' : 'last day of +1 month';
        $dueDate = $endDt->modify($modifier)->format('Y-m-d');

        // ── Check uniqueness (UNIQUE constraint will catch race) ──
        $existing = \db_row(
            "SELECT id FROM acc_tax_filing_periods
             WHERE tax_type = ? AND period_start = ? AND period_end = ?",
            [$taxType, $start, $end]
        );
        if ($existing) {
            throw new \RuntimeException("A {$taxType} period from {$start} to {$end} already exists.");
        }

        // ── Insert + audit inside transaction ───────────────
        return \db_transaction(function () use ($taxType, $start, $end, $dueDate, $frequency, $data, $userId) {
            $newId = \db_insert('acc_tax_filing_periods', [
                'tax_type'        => $taxType,
                'period_start'    => $start,
                'period_end'      => $end,
                'filing_due_date' => $dueDate,
                'frequency'       => $frequency,
                'status'          => 'open',
                'notes'           => $data['notes'] ?? null,
            ]);

            self::audit(
                $userId,
                'create',
                $newId,
                "Tax period {$taxType} {$start}→{$end} ({$frequency})",
                "Created tax filing period: {$taxType}, {$start} to {$end}, due {$dueDate}"
            );

            return $newId;
        });
    }

    // ============================================================
    // CALCULATE PERIOD — recompute totals from GL
    // ============================================================

    /**
     * Recompute the period's totals from posted GL entries and the
     * underlying invoice / bill subledgers, then flip status to
     * 'calculated'.
     *
     * Reads the period FOR UPDATE (D20) so concurrent calculations
     * cannot race. Refuses if status is 'remitted' (terminal).
     *
     * Calculation rules:
     *   gst_hst:
     *     total_sales         = SUM(invoices.subtotal) for invoices
     *                           with a posted, non-reversal invoice JE
     *                           whose entry_date is in [start,end]
     *     total_tax_collected = SUM(GL credits to 2030) in [start,end]
     *     total_itc           = SUM(GL debits  to 1050) in [start,end]
     *     net_tax_owing       = total_tax_collected - total_itc
     *
     *   pst_bc / pst_sk / pst_mb:
     *     Same idea but PST has no input tax credits, and only invoices
     *     for customers in the matching province contribute. The PST
     *     payable account (2040) is shared across provinces, so we
     *     filter by joining the contributing JE → invoice → customer.
     *
     * @throws \RuntimeException if period is remitted, missing, or GL
     *                           accounts are not seeded
     */
    public static function calculatePeriod(int $periodId, ?int $userId = null): array
    {
        return \db_transaction(function () use ($periodId, $userId) {
            // ── Lock the row to serialize concurrent recalcs ────
            // D20: SELECT FOR UPDATE prevents two users from racing
            // a calculate while the totals are being written.
            $period = \db_row(
                "SELECT * FROM acc_tax_filing_periods WHERE id = ? FOR UPDATE",
                [$periodId]
            );
            if (!$period) {
                throw new \RuntimeException('Tax filing period not found.');
            }

            // Status guard: remitted is terminal — cannot recalculate.
            if ($period['status'] === 'remitted') {
                throw new \RuntimeException('PERIOD_LOCKED: cannot recalculate a remitted period.');
            }

            $taxType = $period['tax_type'];
            $start   = $period['period_start'];
            $end     = $period['period_end'];

            // ── Resolve GL account IDs (fail loudly if missing) ──
            if ($taxType === 'gst_hst') {
                $taxAccountId = self::accountIdByCode(self::ACCT_CODE_GST_PAYABLE);
                $itcAccountId = self::accountIdByCode(self::ACCT_CODE_GST_ITC);
            } else {
                $taxAccountId = self::accountIdByCode(self::ACCT_CODE_PST_PAYABLE);
                $itcAccountId = null;  // PST has no input tax credits
            }
            if (!$taxAccountId) {
                throw new \RuntimeException('GL tax account not found in chart of accounts.');
            }

            // Province filter for PST tax types — null for gst_hst
            $province = self::PROVINCE_FOR_TAX[$taxType] ?? null;

            // ── total_tax_collected ─────────────────────────────
            // Sum of CR side of GL entries to the tax payable account,
            // for posted (non-reversal) JEs in the period date range.
            // For PST types, restrict to invoices from the matching
            // province via JE.source_type='invoice' → invoices →
            // customers JOIN.
            $taxCollected = self::sumTaxCollected(
                $taxAccountId,
                $start,
                $end,
                $province
            );

            // ── total_itc (gst_hst only) ────────────────────────
            $totalItc = '0.00';
            if ($itcAccountId) {
                $totalItc = self::sumItc($itcAccountId, $start, $end);
            }

            // ── net_tax_owing ───────────────────────────────────
            $netOwing = bcsub($taxCollected, $totalItc, 2);

            // ── total_sales — sum invoices contributing in period ──
            $totalSales = self::sumSales($start, $end, $province);

            // ── Persist + flip status ───────────────────────────
            // status 'open' → 'calculated'  OR  'calculated' → 'calculated' (recalc)
            // We set updated_at = NOW() implicitly via ON UPDATE clause.
            $oldStatus = $period['status'];
            $newStatus = ($oldStatus === 'remitted')
                ? 'remitted'      // unreachable due to guard above
                : 'calculated';

            \db_update('acc_tax_filing_periods', [
                'total_sales'         => $totalSales,
                'total_tax_collected' => $taxCollected,
                'total_itc'           => $totalItc,
                'net_tax_owing'       => $netOwing,
                'status'              => $newStatus,
            ], 'id = ?', [$periodId]);

            self::audit(
                $userId,
                ($oldStatus === $newStatus ? 'update' : 'status_change'),
                $periodId,
                "Tax period {$taxType} {$start}→{$end}",
                "Calculated: sales={$totalSales}, collected={$taxCollected}, itc={$totalItc}, net={$netOwing}"
            );

            return \db_row("SELECT * FROM acc_tax_filing_periods WHERE id = ?", [$periodId]);
        });
    }

    // ============================================================
    // MARK FILED
    // ============================================================

    /**
     * Mark a calculated period as filed with the tax authority.
     *
     * Status guard: must be 'calculated'. Records who filed it and on
     * what date. Does NOT post any JE (filing is paperwork; payment
     * happens via recordRemittance).
     *
     * @throws \RuntimeException on bad state or invalid date
     */
    public static function markFiled(int $periodId, string $filedDate, ?int $userId = null): array
    {
        if (!\clean_date($filedDate)) {
            throw new \RuntimeException('filed_date must be a valid Y-m-d date.');
        }

        return \db_transaction(function () use ($periodId, $filedDate, $userId) {
            $period = \db_row(
                "SELECT * FROM acc_tax_filing_periods WHERE id = ? FOR UPDATE",
                [$periodId]
            );
            if (!$period) {
                throw new \RuntimeException('Tax filing period not found.');
            }
            if ($period['status'] !== 'calculated') {
                throw new \RuntimeException("INVALID_TRANSITION: cannot mark filed from status '{$period['status']}'.");
            }

            \db_update('acc_tax_filing_periods', [
                'status'     => 'filed',
                'filed_date' => $filedDate,
                'filed_by'   => $userId,
            ], 'id = ?', [$periodId]);

            self::audit(
                $userId,
                'status_change',
                $periodId,
                "Tax period #{$periodId}",
                "Filed on {$filedDate}",
                ['status' => 'calculated'],
                ['status' => 'filed', 'filed_date' => $filedDate]
            );

            return \db_row("SELECT * FROM acc_tax_filing_periods WHERE id = ?", [$periodId]);
        });
    }

    // ============================================================
    // RECORD REMITTANCE — inserts row + posts JE + flips status
    // ============================================================

    /**
     * Record a CRA / tax-authority remittance payment.
     *
     * Validates the period is in status='filed', amount > 0, and the
     * bank account exists. Inside one db_transaction:
     *   1. Insert acc_tax_remittances row
     *   2. Build the consolidated JE via JournalEntryService::create():
     *        DR 2030 GST/HST Payable      [amount]    (or 2040 for PST)
     *          CR <bank.gl_account_id>    [amount]    (or 1010 fallback)
     *      The JE date is the remittance_date — but if that period is
     *      closed/locked, §16 redirect kicks in via the same pattern as
     *      AutoEntryBridge::resolvePeriod() so the JE always lands in
     *      an open period.
     *   3. Link remittance.journal_entry_id ← new JE id
     *   4. Flip the period status filed → remitted
     *   5. audit_log inside the same transaction
     *
     * @param int   $periodId
     * @param array $data {remittance_date, amount, payment_method,
     *                     bank_account_id?, reference_number?, notes?}
     * @param int|null $userId
     * @return array ['remittance' => row, 'journal_entry_id' => int]
     * @throws \RuntimeException on validation / state failure
     */
    public static function recordRemittance(int $periodId, array $data, ?int $userId = null): array
    {
        // ── Pre-transaction input validation ────────────────
        $remitDate = (string) ($data['remittance_date'] ?? '');
        if (!\clean_date($remitDate)) {
            throw new \RuntimeException('remittance_date must be a valid Y-m-d date.');
        }

        $amount = (string) ($data['amount'] ?? '');
        if (!is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            throw new \RuntimeException('amount must be a positive number.');
        }
        // Normalize to 2dp string for bcmath consistency
        $amount = bcadd($amount, '0', 2);

        $paymentMethod = (string) ($data['payment_method'] ?? '');
        if (!in_array($paymentMethod, ['online_banking', 'check', 'wire', 'other'], true)) {
            throw new \RuntimeException('payment_method must be one of: online_banking, check, wire, other.');
        }

        $bankAccountId = isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        $bankAccount = null;
        if ($bankAccountId) {
            $bankAccount = \db_row(
                "SELECT id, name, gl_account_id FROM acc_bank_accounts WHERE id = ?",
                [$bankAccountId]
            );
            if (!$bankAccount) {
                throw new \RuntimeException("bank_account_id {$bankAccountId} not found.");
            }
        }

        return \db_transaction(function () use (
            $periodId, $remitDate, $amount, $paymentMethod,
            $bankAccountId, $bankAccount, $data, $userId
        ) {
            // Lock the period row — guard against double-remit race.
            $period = \db_row(
                "SELECT * FROM acc_tax_filing_periods WHERE id = ? FOR UPDATE",
                [$periodId]
            );
            if (!$period) {
                throw new \RuntimeException('Tax filing period not found.');
            }
            if ($period['status'] !== 'filed') {
                throw new \RuntimeException("INVALID_TRANSITION: cannot record remittance from status '{$period['status']}'. Period must be 'filed' first.");
            }

            $taxType = $period['tax_type'];

            // ── Resolve account IDs for the JE ──────────────
            // DR side = the relevant tax payable account.
            // CR side = the selected bank account's gl_account_id, or
            //           1010 Cash if no bank was selected (rare).
            $payableAccountId = self::accountIdByCode(
                $taxType === 'gst_hst' ? self::ACCT_CODE_GST_PAYABLE : self::ACCT_CODE_PST_PAYABLE
            );
            if (!$payableAccountId) {
                throw new \RuntimeException('Tax payable GL account not seeded — check chart of accounts.');
            }

            $cashAccountId = $bankAccount
                ? (int) $bankAccount['gl_account_id']
                : self::accountIdByCode(self::ACCT_CODE_CASH);
            if (!$cashAccountId) {
                throw new \RuntimeException('Cash GL account not seeded — check chart of accounts.');
            }

            // ── §16 closed-period redirect ─────────────────
            // The remittance JE date should normally equal the calendar
            // date the cheque cleared (remittance_date). But if that
            // period is closed/locked, the JE cannot land there and
            // would otherwise crash. Instead we silently redirect to
            // the earliest open period and log the discrepancy, the
            // same way AutoEntryBridge::resolvePeriod() does.
            $entryDate = self::resolveOpenEntryDate($remitDate, $userId);

            // ── Insert remittance row (stub the JE id, set later) ──
            $remittanceId = \db_insert('acc_tax_remittances', [
                'filing_period_id'  => $periodId,
                'remittance_date'   => $remitDate,
                'amount'            => $amount,
                'payment_method'    => $paymentMethod,
                'reference_number'  => $data['reference_number'] ?? null,
                'bank_account_id'   => $bankAccountId,
                'journal_entry_id'  => null,
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $userId,
            ]);

            // ── Build and post the JE ──────────────────────
            // Two-line entry: DR payable / CR cash. JournalEntryService
            // validates balance and refuses to post into a closed period
            // (we already redirected via resolveOpenEntryDate above).
            $taxLabel = ($taxType === 'gst_hst') ? 'GST/HST' : strtoupper(str_replace('_', ' ', $taxType));
            $bankLabel = $bankAccount ? $bankAccount['name'] : 'Cash';
            $jeDescription = "Tax remittance — {$taxLabel} {$period['period_start']} to {$period['period_end']}";

            $entry = JournalEntryService::create(
                [
                    'entry_date'       => $entryDate,
                    'description'      => $jeDescription,
                    'entry_type'       => 'system',
                    'reference'        => "TAX-REMIT-{$remittanceId}",
                    'source_type'      => 'tax_remittance',
                    'source_id'        => $remittanceId,
                    'post_immediately' => true,
                ],
                [
                    [
                        'account_id'  => $payableAccountId,
                        'debit'       => $amount,
                        'credit'      => '0.00',
                        'description' => "{$taxLabel} remittance",
                    ],
                    [
                        'account_id'  => $cashAccountId,
                        'debit'       => '0.00',
                        'credit'      => $amount,
                        'description' => "Paid from {$bankLabel}",
                    ],
                ],
                $userId
            );

            // Link the remittance row back to the JE
            \db_update('acc_tax_remittances', [
                'journal_entry_id' => $entry['id'],
            ], 'id = ?', [$remittanceId]);

            // Flip period status filed → remitted
            \db_update('acc_tax_filing_periods', [
                'status' => 'remitted',
            ], 'id = ?', [$periodId]);

            self::audit(
                $userId,
                'status_change',
                $periodId,
                "Tax period #{$periodId} ({$taxType})",
                "Remitted \${$amount} on {$remitDate} (JE #{$entry['id']}, ref TAX-REMIT-{$remittanceId})",
                ['status' => 'filed'],
                ['status' => 'remitted', 'remittance_id' => $remittanceId, 'je_id' => $entry['id']]
            );

            return [
                'remittance'        => \db_row("SELECT * FROM acc_tax_remittances WHERE id = ?", [$remittanceId]),
                'journal_entry_id'  => (int) $entry['id'],
            ];
        });
    }

    // ============================================================
    // LIST PERIODS — paginated with optional filters
    // ============================================================

    /**
     * List filing periods with optional filters and pagination.
     *
     * Supported filters: tax_type, status, year (matches YEAR(period_end)).
     *
     * @return array ['items' => rows, 'total' => int, 'page' => int, 'per_page' => int]
     */
    public static function listPeriods(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['tax_type'])) {
            $where[] = 'tax_type = ?';
            $params[] = (string) $filters['tax_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(period_end) = ?';
            $params[] = (int) $filters['year'];
        }

        $whereSql = implode(' AND ', $where);
        $page    = max(1, $page);
        $perPage = min(100, max(10, $perPage));
        $offset  = ($page - 1) * $perPage;

        $total = (int) \db_count(
            "SELECT COUNT(*) FROM acc_tax_filing_periods WHERE {$whereSql}",
            $params
        );
        $rows = \db_select(
            "SELECT p.*, u.name AS filed_by_name
             FROM acc_tax_filing_periods p
             LEFT JOIN users u ON u.id = p.filed_by
             WHERE {$whereSql}
             ORDER BY p.period_end DESC, p.tax_type ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    // ============================================================
    // GET PERIOD DETAIL — full row + remittances + drill-down
    // ============================================================

    /**
     * Return a tax filing period along with its remittance history
     * and a per-transaction drill-down (the invoices and bills that
     * contributed to total_tax_collected and total_itc respectively).
     *
     * Spec ref §9: "Report shows every transaction making up each
     * line — full drill-down."
     *
     * @return array|null  null if not found
     */
    public static function getPeriodDetail(int $periodId): ?array
    {
        $period = \db_row(
            "SELECT p.*, u.name AS filed_by_name
             FROM acc_tax_filing_periods p
             LEFT JOIN users u ON u.id = p.filed_by
             WHERE p.id = ?",
            [$periodId]
        );
        if (!$period) return null;

        $remittances = \db_select(
            "SELECT r.*, ba.name AS bank_account_name,
                    je.entry_number AS journal_entry_number, u.name AS created_by_name
             FROM acc_tax_remittances r
             LEFT JOIN acc_bank_accounts ba ON ba.id = r.bank_account_id
             LEFT JOIN acc_journal_entries je ON je.id = r.journal_entry_id
             LEFT JOIN users u ON u.id = r.created_by
             WHERE r.filing_period_id = ?
             ORDER BY r.remittance_date DESC, r.id DESC",
            [$periodId]
        );

        $taxType  = $period['tax_type'];
        $start    = $period['period_start'];
        $end      = $period['period_end'];
        $province = self::PROVINCE_FOR_TAX[$taxType] ?? null;

        // ── Drill-down: contributing invoices ──────────────
        $invoiceRows = self::drillDownInvoices($start, $end, $province);

        // ── Drill-down: contributing bills (gst_hst only) ──
        $billRows = ($taxType === 'gst_hst')
            ? self::drillDownBills($start, $end)
            : [];

        return [
            'period'      => $period,
            'remittances' => $remittances,
            'invoices'    => $invoiceRows,
            'bills'       => $billRows,
        ];
    }

    // ============================================================
    // PRIVATE — calculation helpers
    // ============================================================

    /**
     * Sum of GL credit lines posted to the tax payable account in
     * [start,end]. For PST tax types, results are filtered to invoices
     * whose customer.province matches the tax type's province (since
     * 2040 is shared across all PST jurisdictions in the COA).
     *
     * @return string  bcmath-safe 2dp total
     */
    private static function sumTaxCollected(
        int $taxAccountId,
        string $start,
        string $end,
        ?string $province
    ): string {
        // Province-restricted (PST): JOIN through invoice/customer.
        // Without the JOIN, all PST credits would be lumped together
        // regardless of jurisdiction.
        if ($province !== null) {
            $row = \db_row(
                "SELECT COALESCE(SUM(jel.credit), 0) AS total
                 FROM acc_journal_entry_lines jel
                 JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                 LEFT JOIN invoices  i ON i.id = je.source_id AND je.source_type = 'invoice'
                 LEFT JOIN customers c ON c.id = i.customer_id
                 WHERE jel.account_id = ?
                   AND jel.credit > 0
                   AND je.status = 'posted'
                   AND je.is_reversal = 0
                   AND je.entry_date BETWEEN ? AND ?
                   AND c.province = ?",
                [$taxAccountId, $start, $end, $province]
            );
        } else {
            $row = \db_row(
                "SELECT COALESCE(SUM(jel.credit), 0) AS total
                 FROM acc_journal_entry_lines jel
                 JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                 WHERE jel.account_id = ?
                   AND jel.credit > 0
                   AND je.status = 'posted'
                   AND je.is_reversal = 0
                   AND je.entry_date BETWEEN ? AND ?",
                [$taxAccountId, $start, $end]
            );
        }
        return bcadd((string) ($row['total'] ?? '0'), '0', 2);
    }

    /**
     * Sum of GL debit lines posted to the GST ITC account 1050 in
     * [start,end]. Used only for gst_hst — PST has no ITC.
     */
    private static function sumItc(int $itcAccountId, string $start, string $end): string
    {
        $row = \db_row(
            "SELECT COALESCE(SUM(jel.debit), 0) AS total
             FROM acc_journal_entry_lines jel
             JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
             WHERE jel.account_id = ?
               AND jel.debit > 0
               AND je.status = 'posted'
               AND je.is_reversal = 0
               AND je.entry_date BETWEEN ? AND ?",
            [$itcAccountId, $start, $end]
        );
        return bcadd((string) ($row['total'] ?? '0'), '0', 2);
    }

    /**
     * Sum of subtotals for invoices whose JE landed in the period.
     * Optionally filtered by customer province (for PST tax types).
     */
    private static function sumSales(string $start, string $end, ?string $province): string
    {
        $where = "i.deleted_at IS NULL
            AND i.id IN (
                SELECT je.source_id
                FROM acc_journal_entries je
                WHERE je.source_type = 'invoice'
                  AND je.status = 'posted'
                  AND je.is_reversal = 0
                  AND je.entry_date BETWEEN ? AND ?
            )";
        $params = [$start, $end];
        if ($province !== null) {
            $where .= " AND i.customer_id IN (SELECT id FROM customers WHERE province = ? AND deleted_at IS NULL)";
            $params[] = $province;
        }
        $row = \db_row(
            "SELECT COALESCE(SUM(i.subtotal), 0) AS total FROM invoices i WHERE {$where}",
            $params
        );
        return bcadd((string) ($row['total'] ?? '0'), '0', 2);
    }

    /**
     * Drill-down: every invoice that contributed to the period.
     * Each row carries the invoice's tax columns so the UI can show
     * exactly which dollars made it into total_tax_collected.
     */
    private static function drillDownInvoices(string $start, string $end, ?string $province): array
    {
        $where  = "i.deleted_at IS NULL
            AND i.id IN (
                SELECT DISTINCT je.source_id
                FROM acc_journal_entries je
                WHERE je.source_type = 'invoice'
                  AND je.status = 'posted'
                  AND je.is_reversal = 0
                  AND je.entry_date BETWEEN ? AND ?
            )";
        $params = [$start, $end];
        if ($province !== null) {
            $where .= " AND c.province = ?";
            $params[] = $province;
        }
        return \db_select(
            "SELECT i.id, i.invoice_number, i.invoice_date, i.subtotal,
                    i.tax_gst_amount, i.tax_pst_amount, i.tax_hst_amount,
                    c.id AS customer_id, c.company_name, c.province
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id
             WHERE {$where}
             ORDER BY i.invoice_date ASC, i.id ASC",
            $params
        );
    }

    /**
     * Drill-down: every vendor bill that contributed GST input tax
     * credits (DR 1050) to the period. Only used for gst_hst.
     */
    private static function drillDownBills(string $start, string $end): array
    {
        return \db_select(
            "SELECT b.id, b.bill_number, b.vendor_bill_number, b.bill_date,
                    b.subtotal, b.tax_gst_amount, b.tax_hst_amount,
                    v.id AS vendor_id, v.name AS vendor_name
             FROM acc_bills b
             LEFT JOIN vendors v ON v.id = b.vendor_id
             WHERE b.id IN (
                SELECT DISTINCT je.source_id
                FROM acc_journal_entries je
                WHERE je.source_type = 'ap_bill'
                  AND je.status = 'posted'
                  AND je.is_reversal = 0
                  AND je.entry_date BETWEEN ? AND ?
             )
             ORDER BY b.bill_date ASC, b.id ASC",
            [$start, $end]
        );
    }

    // ============================================================
    // PRIVATE — closed-period redirect (§16)
    // ============================================================

    /**
     * Resolve a date to a JE entry_date that is guaranteed to land
     * in an open accounting period.
     *
     * If the natural period is open, return the date unchanged. If it
     * is closed/locked or missing, fall back to the earliest open
     * period's start_date and write a 'status_change' audit entry so
     * the redirect is auditable.
     *
     * Mirrors AutoEntryBridge::resolvePeriod() — kept inline rather
     * than extracted because the audit entity_type is different.
     *
     * @throws \RuntimeException if no open period exists at all
     */
    private static function resolveOpenEntryDate(string $date, ?int $userId): string
    {
        $period = AccountingService::periodForDate($date);
        if ($period && $period['status'] === 'open') {
            return $date;
        }

        $openPeriod = AccountingService::currentOpenPeriod();
        if (!$openPeriod) {
            throw new \RuntimeException(
                "No open accounting period available. Cannot post tax remittance JE for date {$date}. " .
                "Open a period in Accounting → Periods."
            );
        }

        // Write a redirect audit entry. Use 'status_change' because the
        // audit_log.action ENUM does not include 'period_redirect'.
        \db_insert('audit_log', [
            'user_id'     => $userId,
            'action'      => 'status_change',
            'module'      => 'accounting',
            'entity_type' => 'tax_remittance',
            'entity_id'   => 0,
            'notes'       => "PERIOD REDIRECT: tax remittance JE for date {$date} redirected to "
                            . "{$openPeriod['name']} starting {$openPeriod['start_date']} "
                            . "(original period closed/missing).",
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        return $openPeriod['start_date'];
    }

    // ============================================================
    // PRIVATE — chart-of-accounts lookup helper
    // ============================================================

    /**
     * Resolve a GL account code (e.g. '2030') to its primary key.
     * Cached per-request because COA is effectively immutable at runtime.
     */
    private static function accountIdByCode(string $code): ?int
    {
        static $cache = [];
        if (array_key_exists($code, $cache)) return $cache[$code];

        $row = \db_row(
            "SELECT id FROM acc_accounts WHERE code = ? AND is_active = 1",
            [$code]
        );
        return $cache[$code] = $row ? (int) $row['id'] : null;
    }

    // ============================================================
    // PRIVATE — audit helper
    // ============================================================

    /**
     * Write an audit_log entry for a tax filing period or remittance.
     * Always called inside an existing db_transaction so a rollback
     * undoes the audit alongside the data change.
     *
     * @param array|null $oldValues  prior state, for status_change
     * @param array|null $newValues  new state, for status_change
     */
    private static function audit(
        ?int $userId,
        string $action,
        int $entityId,
        string $entityLabel,
        string $notes,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        \db_insert('audit_log', [
            'user_id'      => $userId,
            'action'       => $action,
            'module'       => 'accounting',
            'entity_type'  => 'tax_filing_period',
            'entity_id'    => $entityId,
            'entity_label' => $entityLabel,
            'notes'        => $notes,
            'old_values'   => $oldValues !== null ? json_encode($oldValues) : null,
            'new_values'   => $newValues !== null ? json_encode($newValues) : null,
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }
}
