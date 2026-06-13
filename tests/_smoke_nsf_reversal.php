<?php
declare(strict_types=1);

/**
 * tests/_smoke_nsf_reversal.php
 *
 * H5 (Wave 3) — NSF reversal must not misclassify a partially-restored invoice.
 *
 * BankService::processNsf() restores an invoice on a bounced payment with:
 *   UPDATE invoices SET balance_due = balance_due + ?, amount_paid = amount_paid - ?,
 *          status = CASE WHEN balance_due + ? >= total_amount THEN 'overdue' ...
 * MySQL evaluates SET left-to-right, so `balance_due` in the CASE already holds
 * the RESTORED value — adding the amount again (`balance_due + ?`) double-counts
 * and flags a partially-restored invoice as 'overdue'. Fixed to compare the
 * restored balance_due directly.
 *
 * Scenario: invoice total 1000, fully paid (amount_paid 1000). A 700 payment
 * bounces. Reversal → balance_due 700, amount_paid 300 → 700 < 1000, so the
 * invoice is PARTIALLY paid, not overdue.
 *
 * PRE-FIX  : status='overdue' (700 + 700 = 1400 >= 1000) → FAIL.
 * POST-FIX : status='partially_paid', balance_due=700.00.
 *
 * Drives the REAL BankService::processNsf against real db + schema (posts the
 * reversal JE). All writes removed in finally.
 *
 * Run:  php tests/_smoke_nsf_reversal.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session H5-NSF-REVERSAL
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\BankService;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();
$GL = 999810; $BANK = 999810;
$custId = $invId = $payId = null;

$cleanup = static function () use (&$custId, &$invId, &$payId, $GL, $BANK) {
    if ($payId) {
        // Tear down the reversal JE (FK on lines→accounts blocks the GL delete otherwise).
        foreach (db_select("SELECT id FROM acc_journal_entries WHERE source_type='payment' AND source_id = ?", [$payId]) as $je) {
            db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [(int) $je['id']]);
            db_execute("DELETE FROM acc_journal_entries WHERE id = ?", [(int) $je['id']]);
        }
        db_execute("DELETE FROM payment_allocations WHERE payment_id = ?", [$payId]);
        db_execute("DELETE FROM payments WHERE id = ?", [$payId]);
    }
    if ($invId)  { db_execute("DELETE FROM invoices WHERE id = ?", [$invId]); }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
    db_execute("DELETE FROM acc_bank_transactions WHERE bank_account_id = ?", [$BANK]);
    db_execute("DELETE FROM acc_bank_accounts WHERE id = ?", [$BANK]);
    db_execute("DELETE FROM acc_accounts WHERE id = ?", [$GL]);
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $period = db_row("SELECT id FROM acc_periods WHERE status='open' AND start_date<=CURDATE() AND end_date>=CURDATE() LIMIT 1")
           ?? db_row("SELECT id FROM acc_periods WHERE status='open' ORDER BY id LIMIT 1");
    if (!$period) { echo "SETUP FAIL no open period\n"; exit(2); }

    $cleanup();

    // Bank account (cash GL) for the reversal JE credit side.
    db_execute("INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
                VALUES (?, '1010-NSF', 'NSF Smoke Bank GL', 'asset', 'current_asset', 0, 1)", [$GL]);
    db_execute("INSERT INTO acc_bank_accounts (id, name, account_type, currency, gl_account_id, is_active)
                VALUES (?, 'NSF Smoke Bank', 'checking', 'CAD', ?, 1)", [$BANK, $GL]);

    $custId = db_insert('customers', ['company_name' => "NSF Co {$PID}", 'currency' => 'CAD', 'outstanding_balance' => '0.00']);

    // Fully-paid invoice (total 1000, amount_paid 1000, balance 0).
    $invId = db_insert('invoices', [
        'invoice_number'       => "INV-NSF-{$PID}",
        'customer_id'          => $custId,
        'billing_period_start' => '2026-06-01',
        'billing_period_end'   => '2026-06-30',
        'billing_period_days'  => 30,
        'billing_type'         => 'single_period',
        'invoice_date'         => '2026-06-15',
        'due_date'             => '2026-06-30',
        'status'               => 'paid',
        'currency'             => 'CAD',
        'total_amount'         => '1000.00',
        'amount_paid'          => '1000.00',
        'balance_due'          => '0.00',
    ]);

    // The bouncing payment: 700 (one of the two payments that fully paid it).
    $payId = db_insert('payments', [
        'payment_number' => "PAY-NSF-{$PID}",
        'customer_id'    => $custId,
        'amount'         => '700.00',
        'currency'       => 'CAD',
        'payment_method' => 'cash',
        'payment_date'   => '2026-06-15',
        'status'         => 'cleared',
    ]);
    db_insert('payment_allocations', ['payment_id' => $payId, 'invoice_id' => $invId, 'amount' => '700.00', 'currency' => 'CAD', 'allocation_type' => 'manual']);

    echo str_repeat('─', 72) . "\n";
    echo "H5 NSF REVERSAL — invoice #{$invId} (total 1000, paid), bouncing payment #{$payId} (700)\n";
    echo str_repeat('─', 72) . "\n";

    BankService::processNsf($payId, '0.00', $BANK, (int) $admin['id']);

    $inv = db_row("SELECT balance_due, amount_paid, status FROM invoices WHERE id = ?", [$invId]);
    $balOk    = bccomp((string) $inv['balance_due'], '700.00', 2) === 0;
    $statusOk = $inv['status'] === 'partially_paid';
    if ($balOk && $statusOk) {
        $pass("NSF reversal — balance_due restored to 700.00 and status='partially_paid' (not 'overdue')");
    } else {
        $fail("NSF reversal — balance_due={$inv['balance_due']} status={$inv['status']} "
            . "(expected 700.00 / partially_paid; pre-fix status='overdue' from the double-counted CASE)");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    echo "  removed invoice + payment + JE + bank account\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("H5 NSF REVERSAL — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
