<?php
declare(strict_types=1);

/**
 * Smoke — holistic already_billed is period-ordered (S-HOLISTIC-ALREADY-BILLED-ORDER)
 *
 * Regenerating an EARLIER invoice while LATER invoices already exist must NOT zero
 * it. Before the fix, HolisticLeaseEngine::sumAlreadyBilled() counted EVERY non-void
 * invoice, so regenerating June with July/August present made already_billed exceed
 * June's cumulative → $0 base. Now sumAlreadyBilled is filtered to invoices whose
 * period starts on/before the one being billed, so each invoice's base is
 * order-independent. In-order generation is unchanged (later invoices don't exist
 * yet at generation time).
 *
 * Hermetic: BEGIN / ROLLBACK, no residue.
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/DbState.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

$pass = 0; $fail = 0;
function ok(bool $c, string $label): void { global $pass, $fail; if ($c) { echo "  \033[32m✓\033[0m {$label}\n"; $pass++; } else { echo "  \033[31m✗\033[0m {$label}\n"; $fail++; } }
function eqv(string $exp, string $act, string $label): void { ok(bccomp($exp, $act, 2) === 0, "{$label} (exp {$exp}, got {$act})"); }

// Push the invoice-number counter clear of any seeded rows for the year.
function bump(): void {
    $y = date('Y');
    $row = db_row("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$y}-%"]);
    $key = "invoice_counter_{$y}";
    $next = (int)($row['m'] ?? 0) + 50;
    if (db_row("SELECT `key` FROM settings WHERE `key`=?", [$key])) {
        db_execute("UPDATE settings SET `value`=? WHERE `key`=?", [(string)$next, $key]);
    }
}

function baseNet(int $invoiceId): string {
    return (string) (db_row(
        "SELECT COALESCE(SUM(CASE WHEN is_credit=1 THEN -amount ELSE amount END),'0.00') s
           FROM invoice_line_items WHERE invoice_id=? AND item_type IN ('base_rental','base_rental_reconciliation_credit')",
        [$invoiceId]
    )['s'] ?? '0.00');
}

function gen(InvoiceGenerator $g, int $lease, string $ps, string $pe, string $bt, ?string $forceNum = null): int {
    $params = ['lease_id' => $lease, 'period_start' => $ps, 'period_end' => $pe, 'billing_type' => $bt,
               'single_segment' => true, 'created_by' => 1, 'generation_source' => 'manual'];
    if ($forceNum !== null) $params['force_invoice_number'] = $forceNum;
    return (int) $g->createFromLease($params)['invoice_id'];
}

$gen = new InvoiceGenerator();

DbState::inTransaction(function () use ($gen) {
    bump();
    $cust  = Fixtures::createCustomer(['province' => 'BC']);
    $lease = Fixtures::createLease($cust, [
        'engine_version' => 'holistic', 'billing_cycle' => 'monthly', 'status' => 'active',
        'start_date' => '2026-06-01', 'end_date' => '2026-08-31',
        'daily_rate' => '100.00', 'weekly_rate' => '500.00', 'monthly_rate' => '1500.00',
        'gps_opt_in' => 0,
    ]);

    // Generate June, July, August IN ORDER (normal sequential billing).
    $jun = gen($gen, $lease, '2026-06-01', '2026-06-30', 'full_month');
    $jul = gen($gen, $lease, '2026-07-01', '2026-07-31', 'full_month');
    $aug = gen($gen, $lease, '2026-08-01', '2026-08-31', 'full_month');

    $junAmt = baseNet($jun); $julAmt = baseNet($jul); $augAmt = baseNet($aug);
    echo "In-order: June={$junAmt}  July={$julAmt}  Aug={$augAmt}\n";
    ok(bccomp($junAmt, '0', 2) > 0, "June billed a non-zero base in order ({$junAmt})");

    // Regenerate JUNE (earliest) while July + August exist — delete + recreate with
    // the same number, exactly as api/v1/invoices/regenerate.php does.
    $junNum = (string) db_row("SELECT invoice_number FROM invoices WHERE id=?", [$jun])['invoice_number'];
    db_execute("DELETE FROM lease_billing_periods WHERE invoice_id=?", [$jun]);
    db_execute("DELETE FROM invoice_line_items   WHERE invoice_id=?", [$jun]);
    db_execute("DELETE FROM invoices             WHERE id=?",        [$jun]);
    $junNew = gen($gen, $lease, '2026-06-01', '2026-06-30', 'full_month', $junNum);
    eqv($junAmt, baseNet($junNew), 'June keeps its amount when regenerated with July+August present (was $0 before the fix)');

    // And regenerating the MIDDLE month (July) with June+August present stays correct.
    $julNum = (string) db_row("SELECT invoice_number FROM invoices WHERE id=?", [$jul])['invoice_number'];
    db_execute("DELETE FROM lease_billing_periods WHERE invoice_id=?", [$jul]);
    db_execute("DELETE FROM invoice_line_items   WHERE invoice_id=?", [$jul]);
    db_execute("DELETE FROM invoices             WHERE id=?",        [$jul]);
    $julNew = gen($gen, $lease, '2026-07-01', '2026-07-31', 'full_month', $julNum);
    eqv($julAmt, baseNet($julNew), 'July keeps its amount when regenerated with June+August present');
});

echo "\n----------------------------------------------------------------------\n";
echo "HOLISTIC REGENERATE ORDER — {$pass} pass / {$fail} fail\n";
echo "----------------------------------------------------------------------\n";
exit($fail === 0 ? 0 : 1);
