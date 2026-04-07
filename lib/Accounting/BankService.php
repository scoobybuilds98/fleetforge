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

        // Book balance = GL balance for the linked cash account
        $bookBalance = AccountingService::accountBalance((int) $bankAccount['gl_account_id']);

        // Outstanding deposits: uncleared positive transactions
        $outDeposits = \db_row(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM acc_bank_transactions
             WHERE bank_account_id = ?
               AND amount > 0
               AND is_cleared = 0
               AND status != 'excluded'",
            [$bankAccountId]
        );

        // Outstanding checks/withdrawals: uncleared negative transactions (use absolute value)
        $outChecks = \db_row(
            "SELECT COALESCE(SUM(ABS(amount)), 0) AS total
             FROM acc_bank_transactions
             WHERE bank_account_id = ?
               AND amount < 0
               AND is_cleared = 0
               AND status != 'excluded'",
            [$bankAccountId]
        );

        $outstandingDeposits = $outDeposits['total'] ?? '0.00';
        $outstandingChecks = $outChecks['total'] ?? '0.00';

        // Adjusted book balance = book balance + outstanding deposits - outstanding checks
        // WHY: Spec formula: Adjusted book balance = Book balance + deposits in transit - outstanding checks ± bank errors
        $adjustedBookBalance = bcadd($bookBalance, $outstandingDeposits, 2);
        $adjustedBookBalance = bcsub($adjustedBookBalance, $outstandingChecks, 2);

        // Difference must be $0.00 to close
        $difference = bcsub($adjustedBookBalance, $statementEndingBalance, 2);

        // Cleared totals
        $clearedDeposits = \db_row(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM acc_bank_transactions
             WHERE bank_account_id = ?
               AND reconciliation_id = ?
               AND amount > 0
               AND is_cleared = 1",
            [$bankAccountId, $reconciliationId]
        );

        $clearedWithdrawals = \db_row(
            "SELECT COALESCE(SUM(ABS(amount)), 0) AS total
             FROM acc_bank_transactions
             WHERE bank_account_id = ?
               AND reconciliation_id = ?
               AND amount < 0
               AND is_cleared = 1",
            [$bankAccountId, $reconciliationId]
        );

        return [
            'book_balance'            => $bookBalance,
            'statement_ending_balance'=> $statementEndingBalance,
            'outstanding_deposits'    => $outstandingDeposits,
            'outstanding_checks'      => $outstandingChecks,
            'adjusted_book_balance'   => $adjustedBookBalance,
            'difference'              => $difference,
            'cleared_deposits'        => $clearedDeposits['total'] ?? '0.00',
            'cleared_withdrawals'     => $clearedWithdrawals['total'] ?? '0.00',
            'is_balanced'             => bccomp($difference, '0.00', 2) === 0,
        ];
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
            if ($payment['status'] === 'failed') {
                throw new \RuntimeException('Payment is already marked as failed/NSF.');
            }

            $bankAccount = \db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$bankAccountId]);
            if (!$bankAccount) {
                throw new \RuntimeException('Bank account not found.');
            }

            // Get GL account IDs
            $arAccountId = AccountingService::setting('accounting.ar_account_id');
            $bankChargeAccountId = self::glAccountIdByCode('6170'); // Bank Charges
            $cashAccountId = (int) $bankAccount['gl_account_id'];

            if (!$arAccountId) {
                throw new \RuntimeException('AR account not configured in settings.');
            }

            $paymentAmount = $payment['amount'];
            $totalCr = bcadd($paymentAmount, $nsfFee, 2);

            // Build JE lines per spec:
            // DR  1030 Accounts Receivable  [original payment amount]
            // DR  6170 Bank Charges         [NSF fee, if any]
            //   CR 1010 Cash               [total]
            $lines = [
                [
                    'account_id'  => (int) $arAccountId,
                    'debit'       => $paymentAmount,
                    'credit'      => '0.00',
                    'description' => "NSF reversal — Payment {$payment['payment_number']}",
                    'customer_id' => $payment['customer_id'],
                ],
            ];

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
                'credit'      => $totalCr,
                'description' => "NSF reversal — Payment {$payment['payment_number']}",
            ];

            // Create the reversal JE
            $je = JournalEntryService::create([
                'entry_date'       => date('Y-m-d'),
                'description'      => "NSF reversal: Payment {$payment['payment_number']} from {$payment['company_name']}",
                'entry_type'       => 'system',
                'source_type'      => 'payment',
                'source_id'        => $paymentId,
                'post_immediately' => true,
            ], $lines, $userId);

            // Mark payment as failed
            \db_update('payments', [
                'status' => 'failed',
            ], 'id = ?', [$paymentId]);

            // Reverse payment allocations — reopen invoices
            $allocations = \db_select(
                "SELECT * FROM payment_allocations WHERE payment_id = ?",
                [$paymentId]
            );

            foreach ($allocations as $alloc) {
                // Restore balance_due on invoice
                \db_execute(
                    "UPDATE invoices SET
                        balance_due = balance_due + ?,
                        amount_paid = amount_paid - ?,
                        status = CASE
                            WHEN balance_due + ? >= total_amount THEN 'overdue'
                            WHEN balance_due + ? > 0 THEN 'partially_paid'
                            ELSE status
                        END
                     WHERE id = ? AND deleted_at IS NULL",
                    [
                        $alloc['amount'], $alloc['amount'],
                        $alloc['amount'], $alloc['amount'],
                        $alloc['invoice_id'],
                    ]
                );
            }

            // Update customer outstanding balance
            if ($payment['customer_id']) {
                \db_execute(
                    "UPDATE customers SET outstanding_balance = outstanding_balance + ? WHERE id = ? AND deleted_at IS NULL",
                    [$paymentAmount, $payment['customer_id']]
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
                'matched_at'       => date('Y-m-d H:i:s'),
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

            // Build JE: DR destination / CR source
            $lines = [
                [
                    'account_id'  => $toGlId,
                    'debit'       => $toAmount,
                    'credit'      => '0.00',
                    'description' => "Transfer from {$from['name']}",
                ],
                [
                    'account_id'  => $fromGlId,
                    'debit'       => '0.00',
                    'credit'      => $fromAmount,
                    'description' => "Transfer to {$to['name']}",
                ],
            ];

            // FX gain/loss if amounts differ (cross-currency transfer)
            $diff = bcsub($toAmount, $fromAmount, 2);
            if (bccomp($diff, '0.00', 2) !== 0) {
                $fxGainId = self::glAccountIdByCode('7030'); // FX Gain
                $fxLossId = self::glAccountIdByCode('7040'); // FX Loss
                if (bccomp($diff, '0.00', 2) > 0) {
                    // Gain: debit amount > credit amount — need more credit
                    $lines[1]['credit'] = $toAmount; // Match the debit side
                    // Actually, for cross-currency: amounts differ, need FX line
                    $lines = [
                        ['account_id' => $toGlId, 'debit' => $toAmount, 'credit' => '0.00', 'description' => "Transfer from {$from['name']}"],
                        ['account_id' => $fromGlId, 'debit' => '0.00', 'credit' => $fromAmount, 'description' => "Transfer to {$to['name']}"],
                    ];
                    $fxAmount = $diff;
                    if ($fxGainId) {
                        $lines[] = ['account_id' => $fxGainId, 'debit' => '0.00', 'credit' => $fxAmount, 'description' => 'FX gain on transfer'];
                    }
                } else {
                    // Loss
                    $fxAmount = bcmul($diff, '-1', 2);
                    if ($fxLossId) {
                        $lines[] = ['account_id' => $fxLossId, 'debit' => $fxAmount, 'credit' => '0.00', 'description' => 'FX loss on transfer'];
                    }
                }
            }

            $je = JournalEntryService::create([
                'entry_date'       => $transferDate,
                'description'      => "Bank transfer: {$from['name']} → {$to['name']}",
                'entry_type'       => 'system',
                'source_type'      => 'bank_transaction',
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
                'matched_at'       => date('Y-m-d H:i:s'),
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
                'matched_at'       => date('Y-m-d H:i:s'),
                'matched_by'       => $userId,
                'journal_entry_id' => (int) $je['id'],
                'created_by'       => $userId,
            ]);

            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'create',
                'module'      => 'accounting',
                'entity_type' => 'bank_transfer',
                'entity_id'   => $fromTxnId,
                'notes'       => "Transfer: {$from['name']} ({$fromAmount}) → {$to['name']} ({$toAmount})",
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return [
                'journal_entry_id'  => (int) $je['id'],
                'from_transaction_id' => $fromTxnId,
                'to_transaction_id'   => $toTxnId,
                'from_amount'       => $fromAmount,
                'to_amount'         => $toAmount,
                'exchange_rate'     => $exchangeRate,
            ];
        });
    }

    // ============================================================
    // GL ACCOUNT LOOKUP HELPER
    // ============================================================

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
