<?php
declare(strict_types=1);

/**
 * lib/Accounting/BankService.php
 *
 * Bank management service — CSV parsing, format detection, duplicate detection,
 * reconciliation math, NSF processing, and inter-account transfers.
 *
 * Required by: All bank-related API endpoints
 * Depends on: AccountingService, JournalEntryService
 *
 * Decisions: D16 (bcmath only), D20 (FOR UPDATE on bank account during reconciliation)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §7 (Bank & Cash Management)
 *
 * @session S033
 */

namespace FleetForge\Accounting;

class BankService
{
    // ============================================================
    // CSV FORMAT DEFINITIONS
    // WHY: Each Canadian bank exports CSV with different column layouts.
    // Auto-detect reads headers; user can override if detection fails.
    // ============================================================

    /**
     * Known bank CSV format definitions.
     * Each entry maps header keywords to column roles.
     *
     * @return array<string, array{name: string, headers: array, columns: array}>
     */
    public static function csvFormats(): array
    {
        return [
            'rbc' => [
                'name'    => 'RBC Royal Bank',
                'headers' => ['Account Type', 'Account Number', 'Transaction Date', 'Cheque Number', 'Description 1', 'Description 2', 'CAD$', 'USD$'],
                'detect'  => ['Description 1', 'Description 2', 'CAD$'],
                'columns' => [
                    'date'        => 2,  // Transaction Date
                    'description' => 4,  // Description 1
                    'amount'      => 6,  // CAD$ (negative = debit, positive = credit)
                    'reference'   => 3,  // Cheque Number
                    'balance'     => null,
                ],
                'date_format' => 'm/d/Y',
                'amount_mode' => 'single', // single column, negative = withdrawal
            ],
            'td' => [
                'name'    => 'TD Canada Trust',
                'headers' => ['Date', 'Description', 'Withdrawals', 'Deposits', 'Balance'],
                'detect'  => ['Withdrawals', 'Deposits', 'Balance'],
                'columns' => [
                    'date'        => 0,
                    'description' => 1,
                    'debit'       => 2,  // Withdrawals
                    'credit'      => 3,  // Deposits
                    'balance'     => 4,
                    'reference'   => null,
                ],
                'date_format' => 'm/d/Y',
                'amount_mode' => 'split', // separate debit/credit columns
            ],
            'bmo' => [
                'name'    => 'BMO Bank of Montreal',
                'headers' => ['First Bank Card', 'Transaction Type', 'Date Posted', 'Transaction Amount', 'Description'],
                'detect'  => ['First Bank Card', 'Transaction Type', 'Date Posted'],
                'columns' => [
                    'date'        => 2,  // Date Posted
                    'description' => 4,  // Description
                    'amount'      => 3,  // Transaction Amount
                    'reference'   => null,
                    'balance'     => null,
                ],
                'date_format' => 'Ymd',
                'amount_mode' => 'single',
            ],
            'scotiabank' => [
                'name'    => 'Scotiabank',
                'headers' => ['Date', 'Amount', 'Description', '*'],
                'detect'  => ['Date', 'Amount', 'Description'],
                'columns' => [
                    'date'        => 0,
                    'description' => 2,
                    'amount'      => 1,
                    'reference'   => null,
                    'balance'     => null,
                ],
                'date_format' => 'm/d/Y',
                'amount_mode' => 'single',
            ],
            'cibc' => [
                'name'    => 'CIBC',
                'headers' => ['Date', 'Description', 'Debit', 'Credit'],
                'detect'  => ['Date', 'Description', 'Debit', 'Credit'],
                'columns' => [
                    'date'        => 0,
                    'description' => 1,
                    'debit'       => 2,
                    'credit'      => 3,
                    'reference'   => null,
                    'balance'     => null,
                ],
                'date_format' => 'Y-m-d',
                'amount_mode' => 'split',
            ],
        ];
    }

    // ============================================================
    // CSV FORMAT DETECTION
    // WHY: User uploads a CSV and we try to figure out which bank it
    // came from by matching header keywords. If detection fails, user
    // manually selects the format.
    // ============================================================

    /**
     * Auto-detect bank format from CSV header row.
     *
     * @param array $headerRow  The first row of the CSV
     * @return string|null      Format key (e.g. 'rbc') or null if unknown
     */
    public static function detectCsvFormat(array $headerRow): ?string
    {
        // Normalize headers: trim whitespace, strip BOM
        $normalized = array_map(function ($h) {
            $h = trim((string) $h);
            // Strip UTF-8 BOM that Excel sometimes adds
            $h = ltrim($h, "\xEF\xBB\xBF");
            return $h;
        }, $headerRow);

        $formats = self::csvFormats();
        $bestMatch = null;
        $bestScore = 0;

        foreach ($formats as $key => $format) {
            $score = 0;
            foreach ($format['detect'] as $keyword) {
                foreach ($normalized as $header) {
                    if (stripos($header, $keyword) !== false) {
                        $score++;
                        break;
                    }
                }
            }
            // Require at least 2 keyword matches to qualify
            if ($score >= 2 && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $key;
            }
        }

        return $bestMatch;
    }

    // ============================================================
    // CSV PARSING
    // WHY: Parse CSV rows into a uniform transaction array structure
    // regardless of which bank format is used.
    // ============================================================

    /**
     * Parse CSV file content into transaction rows.
     *
     * @param string $csvContent  Raw CSV file content
     * @param string $formatKey   Bank format key (from csvFormats())
     * @param array|null $columnOverrides  Manual column mapping overrides
     * @return array{headers: array, transactions: array, format: string, errors: array}
     */
    public static function parseCsv(
        string $csvContent,
        string $formatKey,
        ?array $columnOverrides = null
    ): array {
        $formats = self::csvFormats();
        if (!isset($formats[$formatKey])) {
            return ['headers' => [], 'transactions' => [], 'format' => $formatKey, 'errors' => ['Unknown format: ' . $formatKey]];
        }

        $format = $formats[$formatKey];
        $columns = $columnOverrides ?? $format['columns'];
        $dateFormat = $format['date_format'];
        $amountMode = $format['amount_mode'];

        // Parse CSV content
        $lines = str_getcsv_rows($csvContent);
        if (count($lines) < 2) {
            return ['headers' => $lines[0] ?? [], 'transactions' => [], 'format' => $formatKey, 'errors' => ['CSV has no data rows.']];
        }

        $headers = $lines[0];
        $transactions = [];
        $errors = [];

        for ($i = 1; $i < count($lines); $i++) {
            $row = $lines[$i];
            // Skip empty rows
            if (count($row) <= 1 && empty(trim($row[0] ?? ''))) {
                continue;
            }

            try {
                $txn = self::parseRow($row, $columns, $dateFormat, $amountMode, $i + 1);
                if ($txn) {
                    $txn['row_number'] = $i + 1;
                    $transactions[] = $txn;
                }
            } catch (\Throwable $e) {
                $errors[] = "Row " . ($i + 1) . ": " . $e->getMessage();
            }
        }

        return [
            'headers'      => $headers,
            'transactions' => $transactions,
            'format'       => $formatKey,
            'format_name'  => $format['name'],
            'errors'       => $errors,
        ];
    }

    /**
     * Parse a single CSV row into a transaction array.
     *
     * @param array  $row        CSV row values
     * @param array  $columns    Column mapping
     * @param string $dateFormat PHP date format string
     * @param string $amountMode 'single' or 'split'
     * @param int    $rowNum     For error messages
     * @return array|null
     */
    private static function parseRow(
        array $row,
        array $columns,
        string $dateFormat,
        string $amountMode,
        int $rowNum
    ): ?array {
        // Extract date
        $dateIdx = $columns['date'] ?? null;
        if ($dateIdx === null || !isset($row[$dateIdx])) {
            throw new \RuntimeException('Missing date column.');
        }
        $rawDate = trim($row[$dateIdx]);
        $date = \DateTime::createFromFormat($dateFormat, $rawDate);
        if (!$date) {
            // Try common fallback formats
            foreach (['Y-m-d', 'm/d/Y', 'd/m/Y', 'Ymd'] as $fallback) {
                $date = \DateTime::createFromFormat($fallback, $rawDate);
                if ($date) break;
            }
        }
        if (!$date) {
            throw new \RuntimeException("Invalid date: {$rawDate}");
        }
        $formattedDate = $date->format('Y-m-d');

        // Extract description
        $descIdx = $columns['description'] ?? null;
        $description = ($descIdx !== null && isset($row[$descIdx]))
            ? trim($row[$descIdx])
            : '';

        // Extract amount — bcmath strings
        $amount = '0.00';
        if ($amountMode === 'single') {
            $amtIdx = $columns['amount'] ?? null;
            if ($amtIdx !== null && isset($row[$amtIdx])) {
                $amount = self::cleanAmount($row[$amtIdx]);
            }
        } else {
            // Split mode: separate debit/credit columns
            $debitIdx = $columns['debit'] ?? null;
            $creditIdx = $columns['credit'] ?? null;
            $debitAmt = '0.00';
            $creditAmt = '0.00';

            if ($debitIdx !== null && isset($row[$debitIdx]) && trim($row[$debitIdx]) !== '') {
                $debitAmt = self::cleanAmount($row[$debitIdx]);
                // WHY: Debits (withdrawals) are negative in our system
                if (bccomp($debitAmt, '0.00', 2) > 0) {
                    $debitAmt = bcmul($debitAmt, '-1', 2);
                }
            }
            if ($creditIdx !== null && isset($row[$creditIdx]) && trim($row[$creditIdx]) !== '') {
                $creditAmt = self::cleanAmount($row[$creditIdx]);
                // Credits (deposits) are positive
                if (bccomp($creditAmt, '0.00', 2) < 0) {
                    $creditAmt = bcmul($creditAmt, '-1', 2);
                }
            }
            $amount = bcadd($debitAmt, $creditAmt, 2);
        }

        // Extract optional fields
        $refIdx = $columns['reference'] ?? null;
        $reference = ($refIdx !== null && isset($row[$refIdx])) ? trim($row[$refIdx]) : null;

        $balIdx = $columns['balance'] ?? null;
        $balance = ($balIdx !== null && isset($row[$balIdx]) && trim($row[$balIdx]) !== '')
            ? self::cleanAmount($row[$balIdx])
            : null;

        // Determine transaction type from amount sign
        $type = bccomp($amount, '0.00', 2) >= 0 ? 'deposit' : 'withdrawal';

        return [
            'date'        => $formattedDate,
            'description' => $description,
            'amount'      => $amount,
            'abs_amount'  => bccomp($amount, '0.00', 2) < 0 ? bcmul($amount, '-1', 2) : $amount,
            'type'        => $type,
            'reference'   => $reference ?: null,
            'balance'     => $balance,
        ];
    }

    /**
     * Clean a raw amount string to a bcmath-safe value.
     * Strips currency symbols, commas, parentheses (for negatives).
     *
     * @param string $raw
     * @return string
     */
    private static function cleanAmount(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '0.00';

        // Handle parenthesized negatives: (123.45) → -123.45
        $negative = false;
        if (preg_match('/^\((.+)\)$/', $raw, $m)) {
            $raw = $m[1];
            $negative = true;
        }

        // Strip $, C$, US$, commas, spaces
        $raw = preg_replace('/[$ ,\x{00A0}]/u', '', $raw);
        $raw = str_ireplace(['cad', 'usd', 'c$', 'us$'], '', $raw);
        $raw = trim($raw);

        // Handle leading minus
        if (str_starts_with($raw, '-')) {
            $negative = !$negative; // double-negative cancels
            $raw = ltrim($raw, '-');
        }

        if (!is_numeric($raw)) return '0.00';

        // WHY: D16 — bcmath only, never float
        $val = bcadd($raw, '0', 2);
        return $negative ? bcmul($val, '-1', 2) : $val;
    }

    // ============================================================
    // DUPLICATE DETECTION
    // WHY: When importing bank transactions, skip any that already
    // exist by matching on date + amount + description.
    // ============================================================

    /**
     * Check for duplicate transactions already in the database.
     *
     * @param int   $bankAccountId
     * @param array $transactions  Parsed transaction array from parseCsv()
     * @return array Each transaction with 'is_duplicate' flag added
     */
    public static function detectDuplicates(int $bankAccountId, array $transactions): array
    {
        if (empty($transactions)) return $transactions;

        // Load existing transactions for this account within the date range
        $dates = array_column($transactions, 'date');
        $minDate = min($dates);
        $maxDate = max($dates);

        $existing = \db_select(
            "SELECT transaction_date, amount, description
             FROM acc_bank_transactions
             WHERE bank_account_id = ?
               AND transaction_date BETWEEN ? AND ?",
            [$bankAccountId, $minDate, $maxDate]
        );

        // Build a hash set for O(1) lookup
        $hashSet = [];
        foreach ($existing as $e) {
            $hash = self::transactionHash($e['transaction_date'], $e['amount'], $e['description']);
            $hashSet[$hash] = true;
        }

        // Check each imported transaction against the hash set
        foreach ($transactions as &$txn) {
            $hash = self::transactionHash($txn['date'], $txn['amount'], $txn['description']);
            $txn['is_duplicate'] = isset($hashSet[$hash]);
        }
        unset($txn);

        return $transactions;
    }

    /**
     * Create a hash for duplicate detection.
     *
     * @param string $date        Y-m-d
     * @param string $amount      bcmath amount
     * @param string $description
     * @return string
     */
    private static function transactionHash(string $date, string $amount, string $description): string
    {
        // Normalize: trim, lowercase description, round amount to 2 decimals
        $normDesc = strtolower(trim($description));
        $normAmount = bcadd($amount, '0', 2);
        return md5("{$date}|{$normAmount}|{$normDesc}");
    }

    // ============================================================
    // AUTO-MATCHING
    // WHY: Match imported bank transactions against existing records
    // (payments, AP payments, JEs) with confidence scoring.
    // ============================================================

    /**
     * Auto-match bank transactions against existing records.
     *
     * @param int   $bankAccountId
     * @param array $transactions  Array of parsed transactions (with date, amount, description)
     * @return array Each transaction with 'match' key added
     */
    public static function autoMatch(int $bankAccountId, array $transactions): array
    {
        $bankAccount = \db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$bankAccountId]);
        if (!$bankAccount) return $transactions;

        foreach ($transactions as &$txn) {
            $txn['match'] = self::findMatch($txn, $bankAccount);
        }
        unset($txn);

        return $transactions;
    }

    /**
     * Find the best match for a single transaction.
     *
     * @param array $txn         Parsed transaction
     * @param array $bankAccount Bank account row
     * @return array|null        Match info or null
     */
    private static function findMatch(array $txn, array $bankAccount): ?array
    {
        $amount = $txn['amount'];
        $date = $txn['date'];
        $absAmount = bccomp($amount, '0.00', 2) < 0
            ? bcmul($amount, '-1', 2)
            : $amount;

        // Deposits: match against customer payments
        if (bccomp($amount, '0.00', 2) > 0) {
            $payment = \db_row(
                "SELECT p.id, p.payment_number, p.amount, p.payment_date,
                        c.company_name AS customer_name
                 FROM payments p
                 LEFT JOIN customers c ON c.id = p.customer_id
                 WHERE p.deleted_at IS NULL
                   AND p.amount = ?
                   AND p.payment_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)
                   AND p.id NOT IN (
                       SELECT COALESCE(matched_id, 0) FROM acc_bank_transactions
                       WHERE matched_type = 'payment' AND status = 'matched'
                   )
                 ORDER BY ABS(DATEDIFF(p.payment_date, ?)) ASC
                 LIMIT 1",
                [$absAmount, $date, $date, $date]
            );

            if ($payment) {
                $daysDiff = abs((int) (strtotime($payment['payment_date']) - strtotime($date)) / 86400);
                $confidence = $daysDiff === 0 ? 'high' : ($daysDiff <= 1 ? 'medium' : 'low');
                return [
                    'type'       => 'payment',
                    'id'         => (int) $payment['id'],
                    'reference'  => $payment['payment_number'],
                    'label'      => "Payment {$payment['payment_number']} — {$payment['customer_name']}",
                    'amount'     => $payment['amount'],
                    'date'       => $payment['payment_date'],
                    'confidence' => $confidence,
                ];
            }
        }

        // Withdrawals: match against AP payments
        if (bccomp($amount, '0.00', 2) < 0) {
            $apPayment = \db_row(
                "SELECT ap.id, ap.payment_number, ap.amount, ap.payment_date,
                        v.name AS vendor_name
                 FROM acc_ap_payments ap
                 LEFT JOIN vendors v ON v.id = ap.vendor_id
                 WHERE ap.status != 'void'
                   AND ap.amount = ?
                   AND ap.payment_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)
                   AND ap.id NOT IN (
                       SELECT COALESCE(matched_id, 0) FROM acc_bank_transactions
                       WHERE matched_type = 'ap_payment' AND status = 'matched'
                   )
                 ORDER BY ABS(DATEDIFF(ap.payment_date, ?)) ASC
                 LIMIT 1",
                [$absAmount, $date, $date, $date]
            );

            if ($apPayment) {
                $daysDiff = abs((int) (strtotime($apPayment['payment_date']) - strtotime($date)) / 86400);
                $confidence = $daysDiff === 0 ? 'high' : ($daysDiff <= 1 ? 'medium' : 'low');
                return [
                    'type'       => 'ap_payment',
                    'id'         => (int) $apPayment['id'],
                    'reference'  => $apPayment['payment_number'],
                    'label'      => "AP Payment {$apPayment['payment_number']} — {$apPayment['vendor_name']}",
                    'amount'     => $apPayment['amount'],
                    'date'       => $apPayment['payment_date'],
                    'confidence' => $confidence,
                ];
            }

            // Check for bank fees / charges (small withdrawals with fee-like descriptions)
            $desc = strtolower($txn['description']);
            $feeKeywords = ['service charge', 'monthly fee', 'bank fee', 'bank charge',
                            'account fee', 'maintenance fee', 'overdraft', 'nsf fee'];
            foreach ($feeKeywords as $kw) {
                if (str_contains($desc, $kw)) {
                    return [
                        'type'       => 'bank_charge',
                        'id'         => null,
                        'reference'  => null,
                        'label'      => 'Bank charge / fee — will create expense entry',
                        'amount'     => $absAmount,
                        'date'       => $date,
                        'confidence' => 'medium',
                    ];
                }
            }
        }

        return null;
    }

    // ============================================================
    // RECONCILIATION MATH
    // WHY: Reconciliation compares the book balance (GL) against the
    // bank statement ending balance. The difference must reach $0.00.
    // ============================================================

    /**
     * Calculate reconciliation balances.
     *
     * @param int    $bankAccountId
     * @param int    $reconciliationId
     * @param string $statementEndingBalance
     * @return array Reconciliation summary
     */
    public static function reconciliationSummary(
        int $bankAccountId,
        int $reconciliationId,
        string $statementEndingBalance
    ): array {
        $bankAccount = \db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$bankAccountId]);
        if (!$bankAccount) {
            return ['error' => 'Bank account not found'];
        }

        $recon = \db_row("SELECT * FROM acc_bank_reconciliations WHERE id = ?", [$reconciliationId]);
        $statementDate = $recon ? (string) $recon['statement_date'] : \ff_today();

        // ── The reconciliation itself (SOP I11) ─────────────────────
        // Statement-side, like QuickBooks:
        //   beginning  = the previous completed reconciliation's statement
        //                ending balance, else the account's opening balance
        //   cleared    = beginning + ticked deposits − ticked withdrawals
        //   difference = statement ending balance − cleared   (must be 0.00)
        // The old formula added outstanding items to the BOOK balance
        // (should subtract), so any uncleared item doubled the difference
        // and a month with a cheque in transit could never complete.
        // Rows mirrored from QuickBooks (is_readonly) are QuickBooks' own
        // register, not statement lines — never part of a reconciliation.
        $beginning = self::reconciliationBeginningBalance($bankAccountId, $reconciliationId, $statementDate);

        $cleared = \db_row(
            "SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS deposits,
                    COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS withdrawals
               FROM acc_bank_transactions
              WHERE bank_account_id = ?
                AND reconciliation_id = ?
                AND is_cleared = 1
                AND is_readonly = 0",
            [$bankAccountId, $reconciliationId]
        );
        $clearedDeposits    = bcadd((string) ($cleared['deposits'] ?? '0'), '0', 2);
        $clearedWithdrawals = bcadd((string) ($cleared['withdrawals'] ?? '0'), '0', 2);
        $clearedBalance     = bcsub(bcadd($beginning, $clearedDeposits, 2), $clearedWithdrawals, 2);
        $difference         = bcsub($statementEndingBalance, $clearedBalance, 2);

        // ── Book check (information only) ───────────────────────────
        // The ledger balance on the statement date, less deposits in
        // transit, plus cheques not yet cleared, should equal the
        // statement. Receipts posted without a bank line (FleetForge
        // payments are not bank lines) make this differ — it guides the
        // search for a missing entry; it never blocks Complete.
        $bookBalance = AccountingService::accountBalance((int) $bankAccount['gl_account_id'], $statementDate);
        $outstanding = \db_row(
            "SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS deposits,
                    COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS checks
               FROM acc_bank_transactions
              WHERE bank_account_id = ?
                AND is_cleared = 0
                AND is_readonly = 0
                AND status != 'excluded'
                AND transaction_date <= ?",
            [$bankAccountId, $statementDate]
        );
        $outstandingDeposits = bcadd((string) ($outstanding['deposits'] ?? '0'), '0', 2);
        $outstandingChecks   = bcadd((string) ($outstanding['checks'] ?? '0'), '0', 2);
        $adjustedBookBalance = bcadd(bcsub($bookBalance, $outstandingDeposits, 2), $outstandingChecks, 2);

        return [
            'beginning_balance'        => $beginning,
            'cleared_deposits'         => $clearedDeposits,
            'cleared_withdrawals'      => $clearedWithdrawals,
            'cleared_balance'          => $clearedBalance,
            'statement_ending_balance' => $statementEndingBalance,
            'difference'               => $difference,
            'is_balanced'              => bccomp($difference, '0.00', 2) === 0,
            'book_balance'             => $bookBalance,
            'outstanding_deposits'     => $outstandingDeposits,
            'outstanding_checks'       => $outstandingChecks,
            'adjusted_book_balance'    => $adjustedBookBalance,
            'book_difference'          => bcsub($adjustedBookBalance, $statementEndingBalance, 2),
        ];
    }

    /**
     * Where a reconciliation starts (SOP I11): the latest earlier completed
     * reconciliation's statement ending balance for this bank account, else
     * the account's opening balance.
     */
    public static function reconciliationBeginningBalance(int $bankAccountId, int $reconciliationId, string $statementDate): string
    {
        $prev = \db_row(
            "SELECT statement_ending_balance FROM acc_bank_reconciliations
              WHERE bank_account_id = ? AND id <> ? AND status IN ('completed','locked')
                AND statement_date < ?
              ORDER BY statement_date DESC, id DESC LIMIT 1",
            [$bankAccountId, $reconciliationId, $statementDate]
        );
        if ($prev) {
            return bcadd((string) $prev['statement_ending_balance'], '0', 2);
        }
        $bank = \db_row("SELECT opening_balance FROM acc_bank_accounts WHERE id = ?", [$bankAccountId]);
        return bcadd((string) ($bank['opening_balance'] ?? '0'), '0', 2);
    }

    // ============================================================
    // NSF PROCESSING
    // WHY: When a customer payment bounces, we must reverse the original
    // payment JE, reopen the invoice, and optionally charge an NSF fee.
    // Manual confirmation required — never auto-reverse. [Spec §7]
    // ============================================================

    /**
     * Process an NSF (returned) payment.
     *
     * @param int         $paymentId   FleetForge payment ID
     * @param string      $nsfFee      NSF fee amount (bcmath string, can be '0.00')
     * @param int         $bankAccountId
     * @param int|null    $userId
     * @return array      Result with JE IDs and bank transaction IDs
     * @throws \RuntimeException
     */
    public static function processNsf(
        int $paymentId,
        string $nsfFee,
        int $bankAccountId,
        ?int $userId = null
    ): array {
        return \db_transaction(function () use ($paymentId, $nsfFee, $bankAccountId, $userId) {
            // Lock the payment row
            $payment = \db_row(
                "SELECT p.*, c.company_name
                 FROM payments p
                 LEFT JOIN customers c ON c.id = p.customer_id
                 WHERE p.id = ? AND p.deleted_at IS NULL FOR UPDATE",
                [$paymentId]
            );

            if (!$payment) {
                throw new \RuntimeException('Payment not found.');
            }
            if (in_array($payment['status'], ['failed', 'returned'], true)) {
                throw new \RuntimeException('Payment is already marked as returned/NSF.');
            }

            $bankAccount = \db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$bankAccountId]);
            if (!$bankAccount) {
                throw new \RuntimeException('Bank account not found.');
            }

            // Get GL account IDs
            $arAccountId = AccountingService::setting('accounting.ar_account_id');
            $bankChargeAccountId = self::glAccountIdByCode('6170'); // Bank Charges
            // SOP I10: the bounced money leaves the GL account the receipt
            // DEBITED — the payment's own deposit bank, else the Settings cash
            // account — not whichever bank is picked here, or both accounts
            // end up wrong. ($bankAccount still owns the bank-transaction row.)
            $cashAccountId = AutoEntryBridge::cashAccountForBank(
                !empty($payment['deposit_bank_account_id']) ? (int) $payment['deposit_bank_account_id'] : null
            );

            if (!$arAccountId) {
                throw new \RuntimeException('AR account not configured in settings.');
            }

            $paymentAmount = $payment['amount'];
            $totalCr = bcadd($paymentAmount, $nsfFee, 2);

            // S-AUDIT-BILLING-ENGINE-1 #21: CAD-canonical GL. The bounced cash
            // reverses at the PAYMENT's frozen rate; each invoice's AR restores
            // at ITS frozen rate (what the original payment JE relieved); the
            // NSF fee is bank-charged in CAD; any residue is the reversal of
            // the original realized FX gain/loss.
            $payFxRate = 'CAD' === ($payment['currency'] ?? 'CAD')
                ? '1'
                : (string) ($payment['exchange_rate_to_cad'] ?? '');
            if ($payFxRate !== '1' && (trim($payFxRate) === '' || bccomp($payFxRate, '0', 6) <= 0)) {
                throw new \RuntimeException("USD payment {$payment['payment_number']} has no frozen exchange_rate_to_cad — cannot post NSF JE.");
            }
            $toCadNsf = static function (string $amt) use ($payFxRate): string {
                return $payFxRate === '1' ? bcround($amt, 2) : bcround(bcmul($amt, $payFxRate, 6), 2);
            };

            // S-AUDIT-BILLING-ENGINE-1 #9: the payment's OVERPAYMENT slice never
            // touched AR — its credit was booked to 2060 (Customer Credits) by
            // the 3-line overpayment JE. Debiting AR for the FULL payment
            // over-stated AR by the excess and left 2060 credited for money
            // that bounced. Split the debit: allocated portion → AR,
            // overpayment portion → 2060. The linked overpayment CN is voided
            // below (its liability is being debited back here).
            $overpaySlice = (string) ($payment['overpayment_amount'] ?? '0.00');
            if (bccomp($overpaySlice, $paymentAmount, 2) > 0) {
                $overpaySlice = $paymentAmount; // defensive clamp
            }
            $arSlice = bcsub($paymentAmount, $overpaySlice, 2);

            // CAD figures for the JE legs (#21): AR restores at each invoice's
            // OWN frozen rate (sum over allocations); cash + 2060 at the
            // payment's rate; fee is CAD.
            $arSliceCad = '0.00';
            foreach (\db_select(
                "SELECT pa.amount, i.currency, i.exchange_rate_to_cad, i.invoice_number
                   FROM payment_allocations pa JOIN invoices i ON i.id = pa.invoice_id
                  WHERE pa.payment_id = ?", [$paymentId]) as $fxAlloc) {
                if (($fxAlloc['currency'] ?? 'CAD') !== 'USD') {
                    $arSliceCad = bcadd($arSliceCad, (string) $fxAlloc['amount'], 2);
                } else {
                    $r = (string) ($fxAlloc['exchange_rate_to_cad'] ?? '');
                    if (trim($r) === '' || bccomp($r, '0', 6) <= 0) {
                        throw new \RuntimeException("USD invoice {$fxAlloc['invoice_number']} has no frozen exchange_rate_to_cad — cannot post NSF JE.");
                    }
                    $arSliceCad = bcadd($arSliceCad, bcround(bcmul((string) $fxAlloc['amount'], $r, 6), 2), 2);
                }
            }
            $overpayCad = $toCadNsf($overpaySlice);
            $cashCad    = bcadd($toCadNsf($paymentAmount), $nsfFee, 2); // fee already CAD

            // Build JE lines per spec:
            // DR  1030 Accounts Receivable  [allocated portion]
            // DR  2060 Customer Credits     [overpayment portion, if any]
            // DR  6170 Bank Charges         [NSF fee, if any]
            //   CR 1010 Cash               [total]
            $lines = [];
            if (bccomp($arSliceCad, '0.00', 2) > 0) {
                $lines[] = [
                    'account_id'  => (int) $arAccountId,
                    'debit'       => $arSliceCad,
                    'credit'      => '0.00',
                    'description' => "NSF reversal — Payment {$payment['payment_number']}",
                    'customer_id' => $payment['customer_id'],
                ];
            }
            if (bccomp($overpaySlice, '0.00', 2) > 0) {
                $creditsLiabilityId = AccountingService::setting('accounting.customer_credits_account_id');
                if (!$creditsLiabilityId) {
                    throw new \RuntimeException('Customer Credits (2060) account not configured in settings — required to reverse this payment\'s overpayment slice.');
                }
                $lines[] = [
                    'account_id'  => (int) $creditsLiabilityId,
                    'debit'       => $overpayCad,
                    'credit'      => '0.00',
                    'description' => "NSF reversal (overpayment credit released) — Payment {$payment['payment_number']}",
                    'customer_id' => $payment['customer_id'],
                ];
            }

            if (bccomp($nsfFee, '0.00', 2) > 0 && $bankChargeAccountId) {
                $lines[] = [
                    'account_id'  => $bankChargeAccountId,
                    'debit'       => $nsfFee,
                    'credit'      => '0.00',
                    'description' => "NSF fee — Payment {$payment['payment_number']}",
                ];
            }

            $lines[] = [
                'account_id'  => $cashAccountId,
                'debit'       => '0.00',
                'credit'      => $cashCad,
                'description' => "NSF reversal — Payment {$payment['payment_number']}",
            ];

            // FX balancer (#21): the difference between the cash reversal (at
            // the payment rate) and the AR/2060 restorations (at their booked
            // rates) reverses the realized FX from the original receipt.
            $fxResidue = bcsub(bcadd(bcadd($arSliceCad, $overpayCad, 2), $nsfFee, 2), $cashCad, 2);
            // Positive residue → debits exceed cash credit → post extra CR (gain).
            if (bccomp($fxResidue, '0', 2) !== 0) {
                if (bccomp($fxResidue, '0', 2) > 0) {
                    $fxGainId = (int) AccountingService::setting('accounting.fx_gain_account_id', 0)
                        ?: (int) (\db_row("SELECT id FROM acc_accounts WHERE code='7030' AND is_active=1 LIMIT 1")['id'] ?? 0);
                    if (!$fxGainId) throw new \RuntimeException('FX gain account not configured (7030).');
                    $lines[] = ['account_id' => $fxGainId, 'debit' => '0.00', 'credit' => $fxResidue,
                                'description' => "Realized FX reversal — NSF {$payment['payment_number']}"];
                } else {
                    $fxLossId = (int) AccountingService::setting('accounting.fx_loss_account_id', 0)
                        ?: (int) (\db_row("SELECT id FROM acc_accounts WHERE code='7040' AND is_active=1 LIMIT 1")['id'] ?? 0);
                    if (!$fxLossId) throw new \RuntimeException('FX loss account not configured (7040).');
                    $lines[] = ['account_id' => $fxLossId, 'debit' => bcmul($fxResidue, '-1', 2), 'credit' => '0.00',
                                'description' => "Realized FX reversal — NSF {$payment['payment_number']}"];
                }
            }

            // Create the reversal JE
            $je = JournalEntryService::create([
                'entry_date'       => date('Y-m-d'),
                'description'      => "NSF reversal: Payment {$payment['payment_number']} from {$payment['company_name']}",
                'entry_type'       => 'system',
                'source_type'      => 'payment',
                'source_id'        => $paymentId,
                'post_immediately' => true,
            ], $lines, $userId);

            // Mark payment as RETURNED (spec §7: "mark payment as returned").
            // S-AUDIT-BILLING-ENGINE-1 #24: the 'returned' ENUM value and
            // returned_reason/returned_date columns were writer-less while the
            // code wrote 'failed' — aligned to spec; readers/guards accept both
            // for legacy rows.
            \db_update('payments', [
                'status'          => 'returned',
                'returned_reason' => 'NSF',
                'returned_date'   => date('Y-m-d'),
            ], 'id = ?', [$paymentId]);

            // S-AUDIT-BILLING-ENGINE-1 #9: the overpayment CN dies with the
            // bounced money. Drawn CN blocks the NSF (customer already spent
            // credit that traces to this payment — resolve that first).
            $nsfLinkedCns = \db_select(
                "SELECT id, credit_note_number, amount, amount_remaining, status
                   FROM credit_notes
                  WHERE source_payment_id = ? AND status <> 'void' AND voided_at IS NULL AND deleted_at IS NULL",
                [$paymentId]
            );
            foreach ($nsfLinkedCns as $cn) {
                if (bccomp((string) $cn['amount_remaining'], (string) $cn['amount'], 2) !== 0
                    || in_array($cn['status'], ['partially_used', 'fully_used'], true)) {
                    throw new \RuntimeException(
                        "Cannot process NSF: overpayment credit {$cn['credit_note_number']} from this payment has already been applied. Unapply it first."
                    );
                }
                // GL deliberately handled by the 2060 debit in the NSF JE above —
                // no onCreditNoteVoided call (would double-debit 2060).
                \db_update('credit_notes', [
                    'status'           => 'void',
                    'amount_remaining' => '0.00',
                    'voided_by'        => $userId,
                    'voided_at'        => \ff_now_utc(), // S-UTC-STAMPS: UTC audit stamp
                    'internal_notes'   => "Auto-voided: source payment {$payment['payment_number']} returned NSF (S-AUDIT-BILLING-ENGINE-1).",
                ], 'id = ?', [(int) $cn['id']]);
                \db_insert('audit_log', [
                    'user_id' => $userId, 'user_name' => 'system', 'action' => 'status_change',
                    'module' => 'accounting', 'entity_type' => 'credit_note', 'entity_id' => (int) $cn['id'],
                    'entity_label' => $cn['credit_note_number'],
                    'notes' => "Credit note {$cn['credit_note_number']} auto-voided by NSF on payment {$payment['payment_number']} (2060 released in the NSF JE).",
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
            }

            // Reverse payment allocations — reopen invoices.
            // S-FIX-2 D-E: status guard. If an allocated invoice is now void / written_off
            // / soft-deleted, those events already reversed the counters; do NOT re-INC
            // OB on this NSF reversal. Otherwise we get a phantom positive balance.
            $allocations = \db_select(
                "SELECT pa.*, i.status AS invoice_status, i.deleted_at AS invoice_deleted_at, i.lease_id
                 FROM payment_allocations pa
                 JOIN invoices i ON i.id = pa.invoice_id
                 WHERE pa.payment_id = ?",
                [$paymentId]
            );

            $obReinflateAmount = '0.00';

            foreach ($allocations as $alloc) {
                $invoiceTerminal = in_array($alloc['invoice_status'], ['void', 'written_off'], true)
                                || $alloc['invoice_deleted_at'] !== null;

                if ($invoiceTerminal) {
                    // Invoice is in a terminal state — counters already reversed by the
                    // void/writeoff/delete event. Skip both the invoice update and the
                    // OB re-INC for this allocation.
                    continue;
                }

                // Restore balance_due on invoice.
                // H5: MySQL evaluates SET left-to-right, so by the time the
                // `status` CASE runs, `balance_due` ALREADY holds the restored
                // value from the first assignment. The old code added the amount
                // a SECOND time (`balance_due + ?`), inflating the comparison and
                // flagging partially-restored invoices as 'overdue'. Compare the
                // restored balance directly — the same already-applied semantics
                // ap-payments/create.php documents.
                // S-AUDIT-BILLING-ENGINE-1 #24/11: recompute from source
                // (total − credits − paid) instead of incremental ±, derive the
                // status from what remains + due_date, and clear paid_date —
                // the old CASE ignored credits_applied (a credited invoice
                // could never reach the right state) and marked 'overdue'
                // regardless of due_date.
                // Business DATE vs company-local today: due_date is a Pacific
                // calendar day but SQL CURDATE() is the UTC day — an evening NSF
                // would flag an invoice due today 'overdue' (ff_today). Params
                // follow textual order: amount, today, invoice id.
                \db_execute(
                    "UPDATE invoices SET
                        amount_paid = GREATEST(0, amount_paid - ?),
                        balance_due = GREATEST(0, total_amount - credits_applied - GREATEST(0, amount_paid)),
                        paid_date   = NULL,
                        status = CASE
                            WHEN total_amount - credits_applied - amount_paid <= 0 THEN 'paid'
                            WHEN amount_paid > 0 OR credits_applied > 0 THEN 'partially_paid'
                            WHEN due_date < ? THEN 'overdue'
                            ELSE 'sent'
                        END
                     WHERE id = ? AND deleted_at IS NULL",
                    [$alloc['amount'], \ff_today(), $alloc['invoice_id']]
                );

                // S-AUDIT-BILLING-ENGINE-1 #9: leases.total_paid was never
                // reversed on NSF (voidPayment reverses it; NSF didn't).
                if (!empty($alloc['lease_id'])) {
                    \db_execute(
                        "UPDATE leases SET total_paid = GREATEST(0, total_paid - ?), updated_at = NOW() WHERE id = ?",
                        [$alloc['amount'], $alloc['lease_id']]
                    );
                }

                $obReinflateAmount = bcadd($obReinflateAmount, (string) $alloc['amount'], 2);
            }

            // Update customer outstanding balance.
            // Path B: only re-INC by the sum of allocations whose invoice is still in a
            // payable state. Allocations against void/written_off/deleted invoices are
            // skipped above and excluded from $obReinflateAmount.
            if ($payment['customer_id'] && bccomp($obReinflateAmount, '0', 2) > 0) {
                \db_execute(
                    "UPDATE customers SET outstanding_balance = outstanding_balance + ? WHERE id = ? AND deleted_at IS NULL",
                    [$obReinflateAmount, $payment['customer_id']]
                );
            }

            // Create bank transaction record for the NSF
            $bankTxnId = \db_insert('acc_bank_transactions', [
                'bank_account_id'  => $bankAccountId,
                'transaction_date' => date('Y-m-d'),
                'description'      => "NSF — Payment {$payment['payment_number']} returned",
                'reference'        => $payment['payment_number'],
                'amount'           => bcmul($totalCr, '-1', 2), // Negative = withdrawal
                'transaction_type' => 'nsf',
                'source'           => 'system',
                'status'           => 'matched',
                'matched_type'     => 'payment',
                'matched_id'       => $paymentId,
                // S-UTC-STAMPS: matched_at is a UTC DATETIME (transaction_date stays a business date).
                'matched_at'       => \ff_now_utc(),
                'matched_by'       => $userId,
                'journal_entry_id' => (int) $je['id'],
                'created_by'       => $userId,
            ]);

            // Audit log
            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'create',
                'module'      => 'accounting',
                'entity_type' => 'nsf',
                'entity_id'   => $paymentId,
                'notes'       => "NSF processed: Payment {$payment['payment_number']}, amount {$paymentAmount}, fee {$nsfFee}",
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return [
                'payment_id'       => $paymentId,
                'journal_entry_id' => (int) $je['id'],
                'bank_transaction_id' => $bankTxnId,
                'amount_reversed'  => $paymentAmount,
                'nsf_fee'          => $nsfFee,
            ];
        });
    }

    // ============================================================
    // TRANSFERS BETWEEN ACCOUNTS
    // WHY: Moving money between bank accounts (e.g. CAD → USD) generates
    // two JE sides: DR destination / CR source. FX gain/loss if cross-currency.
    // ============================================================

    /**
     * Record a transfer between two bank accounts.
     *
     * @param int         $fromAccountId
     * @param int         $toAccountId
     * @param string      $fromAmount     Amount leaving source account (bcmath)
     * @param string      $toAmount       Amount arriving in destination (bcmath)
     * @param string      $transferDate   Y-m-d
     * @param string|null $exchangeRate   FX rate (null = same currency)
     * @param string|null $reference      Reference number
     * @param int|null    $userId
     * @return array
     * @throws \RuntimeException
     */
    public static function recordTransfer(
        int $fromAccountId,
        int $toAccountId,
        string $fromAmount,
        string $toAmount,
        string $transferDate,
        ?string $exchangeRate = null,
        ?string $reference = null,
        ?int $userId = null
    ): array {
        if ($fromAccountId === $toAccountId) {
            throw new \RuntimeException('Cannot transfer to the same account.');
        }

        return \db_transaction(function () use (
            $fromAccountId, $toAccountId, $fromAmount, $toAmount,
            $transferDate, $exchangeRate, $reference, $userId
        ) {
            $from = \db_row("SELECT * FROM acc_bank_accounts WHERE id = ? FOR UPDATE", [$fromAccountId]);
            $to = \db_row("SELECT * FROM acc_bank_accounts WHERE id = ? FOR UPDATE", [$toAccountId]);

            if (!$from) throw new \RuntimeException('Source bank account not found.');
            if (!$to) throw new \RuntimeException('Destination bank account not found.');

            $fromGlId = (int) $from['gl_account_id'];
            $toGlId = (int) $to['gl_account_id'];

            // ── CAD value of each leg (SOP I5) ─────────────────────
            // The ledger is CAD. A USD leg is valued at the USD→CAD rate —
            // the rate entered, else the rate the two amounts imply (the
            // bank's own conversion) — and carries its USD figure as
            // foreign_amount so FX revaluation sees the USD balance. Any
            // difference between the two CAD legs is realized FX. This used
            // to post the raw numbers on both sides (1,000 USD booked as
            // 1,000 CAD, the rest a fake FX loss).
            $fromCur = (string) $from['currency'];
            $toCur   = (string) $to['currency'];
            if ($fromCur === $toCur && bccomp($fromAmount, $toAmount, 2) !== 0) {
                throw new \RuntimeException("Both accounts are {$fromCur}: the amount sent and the amount received must be the same.");
            }
            $rate = ($exchangeRate !== null && $exchangeRate !== '') ? $exchangeRate : null;
            if ($fromCur !== $toCur && $rate === null) {
                // USD→CAD rate implied by the transfer itself.
                $rate = $fromCur === 'USD'
                    ? bcdiv($toAmount, $fromAmount, 6)
                    : bcdiv($fromAmount, $toAmount, 6);
            }
            if ($fromCur === 'USD' && $toCur === 'USD' && $rate === null) {
                $rateRow = \db_row(
                    "SELECT rate FROM exchange_rates WHERE rate_date <= ? ORDER BY rate_date DESC LIMIT 1",
                    [$transferDate]
                );
                if (!$rateRow) {
                    throw new \RuntimeException('No USD→CAD exchange rate on file for this date. Enter the exchange rate.');
                }
                $rate = (string) $rateRow['rate'];
            }
            $cadOf = static function (string $amount, string $currency) use ($rate): string {
                return $currency === 'CAD' ? bcadd($amount, '0', 2) : bcround(bcmul($amount, (string) $rate, 6), 2);
            };
            $fromCad = $cadOf($fromAmount, $fromCur);
            $toCad   = $cadOf($toAmount, $toCur);

            // Build JE: DR destination / CR source (CAD), foreign figures on USD legs.
            $lines = [
                [
                    'account_id'  => $toGlId,
                    'debit'       => $toCad,
                    'credit'      => '0.00',
                    'description' => "Transfer from {$from['name']}",
                ] + AutoEntryBridge::foreignLeg($toCur, $toAmount, (string) $rate),
                [
                    'account_id'  => $fromGlId,
                    'debit'       => '0.00',
                    'credit'      => $fromCad,
                    'description' => "Transfer to {$to['name']}",
                ] + AutoEntryBridge::foreignLeg($fromCur, $fromAmount, (string) $rate),
            ];

            // Realized FX: CAD received ≠ CAD given up.
            $diff = bcsub($toCad, $fromCad, 2);
            if (bccomp($diff, '0.00', 2) > 0) {
                $fxGainId = self::glAccountIdByCode('7030');
                if (!$fxGainId) throw new \RuntimeException('FX Gain account 7030 not found.');
                $lines[] = ['account_id' => $fxGainId, 'debit' => '0.00', 'credit' => $diff, 'description' => 'FX gain on transfer'];
            } elseif (bccomp($diff, '0.00', 2) < 0) {
                $fxLossId = self::glAccountIdByCode('7040');
                if (!$fxLossId) throw new \RuntimeException('FX Loss account 7040 not found.');
                $lines[] = ['account_id' => $fxLossId, 'debit' => bcmul($diff, '-1', 2), 'credit' => '0.00', 'description' => 'FX loss on transfer'];
            }

            $je = JournalEntryService::create([
                'entry_date'       => $transferDate,
                'description'      => "Bank transfer: {$from['name']} → {$to['name']}",
                'entry_type'       => 'system',
                'source_type'      => 'bank_transfer',
                'reference'        => $reference,
                'post_immediately' => true,
            ], $lines, $userId);

            // Create bank transactions on both sides
            $fromTxnId = \db_insert('acc_bank_transactions', [
                'bank_account_id'  => $fromAccountId,
                'transaction_date' => $transferDate,
                'description'      => "Transfer to {$to['name']}",
                'reference'        => $reference,
                'amount'           => bcmul($fromAmount, '-1', 2),
                'transaction_type' => 'transfer',
                'source'           => 'system',
                'status'           => 'matched',
                'matched_type'     => 'bank_transfer',
                'matched_id'       => $toAccountId,
                // S-UTC-STAMPS: matched_at is a UTC DATETIME (transaction_date stays a business date).
                'matched_at'       => \ff_now_utc(),
                'matched_by'       => $userId,
                'journal_entry_id' => (int) $je['id'],
                'created_by'       => $userId,
            ]);

            $toTxnId = \db_insert('acc_bank_transactions', [
                'bank_account_id'  => $toAccountId,
                'transaction_date' => $transferDate,
                'description'      => "Transfer from {$from['name']}",
                'reference'        => $reference,
                'amount'           => $toAmount,
                'transaction_type' => 'transfer',
                'source'           => 'system',
                'status'           => 'matched',
                'matched_type'     => 'bank_transfer',
                'matched_id'       => $fromAccountId,
                // S-UTC-STAMPS: matched_at is a UTC DATETIME (transaction_date stays a business date).
                'matched_at'       => \ff_now_utc(),
                'matched_by'       => $userId,
                'journal_entry_id' => (int) $je['id'],
                'created_by'       => $userId,
            ]);

            // Trace the JE to its source (the outgoing leg's bank transaction).
            \db_update('acc_journal_entries', ['source_id' => $fromTxnId], 'id = ?', [(int) $je['id']]);

            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'create',
                'module'      => 'accounting',
                'entity_type' => 'bank_transfer',
                'entity_id'   => $fromTxnId,
                'notes'       => "Transfer: {$from['name']} ({$fromAmount} {$fromCur}) → {$to['name']} ({$toAmount} {$toCur})"
                                . ($fromCur !== $toCur || $fromCur === 'USD' ? " at {$rate} (CAD {$fromCad} → {$toCad})" : ''),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return [
                'journal_entry_id'  => (int) $je['id'],
                'from_transaction_id' => $fromTxnId,
                'to_transaction_id'   => $toTxnId,
                'from_amount'       => $fromAmount,
                'to_amount'         => $toAmount,
                'exchange_rate'     => $rate,
            ];
        });
    }

    // ============================================================
    // GL ACCOUNT LOOKUP HELPER
    // ============================================================

    /**
     * Keep a bank account's opening-balance journal entry in step with its
     * Opening Balance / date / GL account (SOP I8 — the field used to post
     * nothing, so the ledger's bank balance started at zero and could never
     * tie to the statement).
     *
     * Reverses the account's current opening entry (dated its own date) and,
     * when the balance is non-zero, posts a new one dated the opening date:
     * the bank GL account on its normal side (DR for a bank account, CR for a
     * credit card / line of credit), 3050 Opening Balance Equity opposite. A
     * USD account is valued at the USD→CAD rate on file for that date and
     * carries the USD figure as foreign_amount. Not pushed to QuickBooks
     * (bank_opening_balance is bridge-derived — QuickBooks has its own).
     * Call inside the caller's transaction.
     *
     * @return int|null  the new JE id, or null when the balance is zero
     * @throws \RuntimeException on a closed period, missing rate or setting
     */
    public static function syncOpeningBalanceEntry(int $bankAccountId, ?int $userId = null): ?int
    {
        $bank = \db_row("SELECT * FROM acc_bank_accounts WHERE id = ? FOR UPDATE", [$bankAccountId]);
        if (!$bank) {
            throw new \RuntimeException('Bank account not found.');
        }

        // 1. Undo the current opening entry, if any.
        foreach (\db_select(
            "SELECT id, entry_date FROM acc_journal_entries
              WHERE source_type = 'bank_opening_balance' AND source_id = ?
                AND status = 'posted' AND is_reversal = 0 AND reversed_by_id IS NULL",
            [$bankAccountId]
        ) as $old) {
            JournalEntryService::reverse((int) $old['id'], (string) $old['entry_date'], $userId);
        }

        $balance = bcadd((string) $bank['opening_balance'], '0', 2);
        if (bccomp($balance, '0', 2) === 0) {
            return null;
        }
        $date = (string) ($bank['opening_balance_date'] ?? '');
        if ($date === '') {
            throw new \RuntimeException('An opening balance needs an opening balance date.');
        }

        $equityId = (int) AccountingService::setting('accounting.opening_balance_equity_account_id', 0);
        if ($equityId <= 0) {
            $equityId = (int) (self::glAccountIdByCode('3050') ?? 0);
        }
        if ($equityId <= 0) {
            throw new \RuntimeException('Opening Balance Equity (3050) is not set up — see Accounting → Settings.');
        }

        $gl = \db_row("SELECT id, normal_balance FROM acc_accounts WHERE id = ?", [(int) $bank['gl_account_id']]);
        if (!$gl) {
            throw new \RuntimeException('The bank account\'s GL account was not found.');
        }

        // CAD value (USD accounts at the rate on file for the opening date).
        $currency = (string) $bank['currency'];
        $rate = '1';
        $cad = $balance;
        if ($currency !== 'CAD') {
            $rateRow = \db_row(
                "SELECT rate FROM exchange_rates WHERE from_currency = ? AND to_currency = 'CAD' AND rate_date <= ?
                  ORDER BY rate_date DESC LIMIT 1",
                [$currency, $date]
            );
            if (!$rateRow) {
                throw new \RuntimeException("No {$currency}→CAD exchange rate on file on or before {$date} — add one before entering a {$currency} opening balance.");
            }
            $rate = (string) $rateRow['rate'];
            $cad  = bcround(bcmul($balance, $rate, 6), 2);
        }

        // A positive balance sits on the account's normal side.
        $bankDebit = (($gl['normal_balance'] ?? 'debit') === 'debit') === (bccomp($cad, '0', 2) > 0);
        $abs = bccomp($cad, '0', 2) < 0 ? bcmul($cad, '-1', 2) : $cad;
        $absForeign = bccomp($balance, '0', 2) < 0 ? bcmul($balance, '-1', 2) : $balance;

        $je = JournalEntryService::create([
            'entry_date'       => $date,
            'description'      => "Opening balance — {$bank['name']}",
            'entry_type'       => 'system',
            'reference'        => "OPEN-BANK-{$bankAccountId}",
            'source_type'      => 'bank_opening_balance',
            'source_id'        => $bankAccountId,
            'post_immediately' => true,
        ], [
            [
                'account_id'  => (int) $gl['id'],
                'debit'       => $bankDebit ? $abs : '0.00',
                'credit'      => $bankDebit ? '0.00' : $abs,
                'description' => "Opening balance {$balance} {$currency}",
            ] + AutoEntryBridge::foreignLeg($currency, $absForeign, $rate),
            [
                'account_id'  => $equityId,
                'debit'       => $bankDebit ? '0.00' : $abs,
                'credit'      => $bankDebit ? $abs : '0.00',
                'description' => "Opening balance — {$bank['name']}",
            ],
        ], $userId);

        return (int) $je['id'];
    }

    /**
     * The bank account money is received into (SOP I10), validated.
     *
     * An explicit id must be an active bank account in the same currency as
     * the money. With none, the currency's default bank account (is_default)
     * is used; with no default either, null — the posting then falls back to
     * the Settings "Cash / Bank Account" (AutoEntryBridge::cashAccountForBank).
     *
     * @return array{id:?int, error:?string}
     */
    public static function resolveReceivingBank(?int $bankAccountId, string $currency): array
    {
        if ($bankAccountId) {
            $bank = \db_row(
                "SELECT id, name, currency, is_active FROM acc_bank_accounts WHERE id = ?",
                [$bankAccountId]
            );
            if (!$bank || (int) $bank['is_active'] !== 1) {
                return ['id' => null, 'error' => 'Choose an active bank account.'];
            }
            if ((string) $bank['currency'] !== $currency) {
                return ['id' => null, 'error' => "{$bank['name']} is a {$bank['currency']} account; this money is in {$currency}."];
            }
            return ['id' => (int) $bank['id'], 'error' => null];
        }
        $default = \db_row(
            "SELECT id FROM acc_bank_accounts WHERE is_active = 1 AND is_default = 1 AND currency = ? ORDER BY id LIMIT 1",
            [$currency]
        );
        return ['id' => $default ? (int) $default['id'] : null, 'error' => null];
    }

    /**
     * Get GL account ID by account code.
     *
     * @param string $code e.g. '1010', '6170'
     * @return int|null
     */
    public static function glAccountIdByCode(string $code): ?int
    {
        static $cache = [];
        if (isset($cache[$code])) return $cache[$code];

        $row = \db_row("SELECT id FROM acc_accounts WHERE code = ? AND is_active = 1", [$code]);
        $cache[$code] = $row ? (int) $row['id'] : null;
        return $cache[$code];
    }
}

/**
 * Parse CSV content into rows (handles multiline quoted fields).
 * WHY: PHP's built-in str_getcsv() only handles one line at a time.
 * We need to split the content into lines first, handling quoted newlines.
 *
 * @param string $content Raw CSV content
 * @return array Array of row arrays
 */
function str_getcsv_rows(string $content): array
{
    // Normalize line endings
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    // Strip BOM
    $content = ltrim($content, "\xEF\xBB\xBF");

    $rows = [];
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);

    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);
    return $rows;
}
