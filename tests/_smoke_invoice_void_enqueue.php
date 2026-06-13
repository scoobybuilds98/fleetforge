<?php
declare(strict_types=1);

/**
 * tests/_smoke_invoice_void_enqueue.php
 *
 * C6 (Wave 2) — invoice voids must enqueue a QBO 'void' sync job.
 *
 * InvoiceEnqueuer gate-0 was widened to accept status='void' for op='void', but
 * gate-3 still hard-rejected `if ($operation !== 'create') return false;`, so
 * void.php / bulk_void.php calling enqueue($id,'void') silently dropped the job
 * → every voided FF invoice left a stale OPEN invoice in QBO (overstated AR /
 * revenue). InvoicePusher::pushVoid was fully implemented but unreachable.
 *
 * This smoke forces an invoice to 'void', turns sync on, calls the REAL
 * InvoiceEnqueuer::enqueue($id,'void'), and asserts a acc_qbo_sync_queue row
 * with operation='void' was inserted. Non-vacuous: enqueue('void') on a 'sent'
 * invoice is still rejected by gate-0 (void requires status='void').
 *
 * PRE-FIX  : enqueue('void') → false, 0 queue rows → FAIL.
 * POST-FIX : true, 1 queue row with operation='void'.
 *
 * Real writes (invoice, queue rows, a temp setting) are removed/restored in
 * finally.
 *
 * Run:  php tests/_smoke_invoice_void_enqueue.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session C6-INVOICE-VOID-ENQUEUE
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\QboPushers\InvoiceEnqueuer;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();
$voidInvId = null;
$sentInvId = null;
$settingExisted = null;     // null = unknown; true/false after lookup
$settingOldValue = null;

$mkInvoice = static function (string $status) use ($PID): int {
    return db_insert('invoices', [
        'invoice_number'       => "INV-C6-{$PID}-" . substr($status, 0, 4),
        'customer_id'          => (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 1),
        'billing_period_start' => '2026-06-01',
        'billing_period_end'   => '2026-06-30',
        'billing_period_days'  => 30,
        'billing_type'         => 'single_period',
        'invoice_date'         => '2026-06-01',
        'due_date'             => '2026-06-30',
        'status'               => $status,
        'currency'             => 'CAD',
        'total_amount'         => '1000.00',
        'amount_paid'          => '0.00',
        'balance_due'          => $status === 'void' ? '0.00' : '1000.00',
    ]);
};

$cleanup = static function () use (&$voidInvId, &$sentInvId, &$settingExisted, &$settingOldValue) {
    foreach ([$voidInvId, $sentInvId] as $iid) {
        if ($iid) {
            db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id = ?", [$iid]);
            db_execute("DELETE FROM invoices WHERE id = ?", [$iid]);
        }
    }
    // Restore the sync_enabled setting to its pre-test state.
    if ($settingExisted === true) {
        db_execute("UPDATE settings SET `value` = ? WHERE `key` = 'quickbooks.sync_enabled'", [$settingOldValue]);
    } elseif ($settingExisted === false) {
        db_execute("DELETE FROM settings WHERE `key` = 'quickbooks.sync_enabled'");
    }
};

try {
    // Turn QBO sync on (gate 1), saving prior state to restore.
    $cur = db_row("SELECT `value` FROM settings WHERE `key` = 'quickbooks.sync_enabled'");
    if ($cur !== null) {
        $settingExisted  = true;
        $settingOldValue = $cur['value'];
        db_execute("UPDATE settings SET `value` = '1' WHERE `key` = 'quickbooks.sync_enabled'");
    } else {
        $settingExisted = false;
        db_insert('settings', [
            'key' => 'quickbooks.sync_enabled', 'value' => '1',
            'value_type' => 'boolean', 'group_name' => 'quickbooks',
        ]);
    }
    // Gate 2 default (quickbooks.sync_mode.invoice) is 'sync' → push allowed.

    $voidInvId = $mkInvoice('void');
    $sentInvId = $mkInvoice('sent');
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id IN (?, ?)", [$voidInvId, $sentInvId]);

    echo str_repeat('─', 72) . "\n";
    echo "C6 INVOICE VOID ENQUEUE — void inv #{$voidInvId}, sent inv #{$sentInvId}\n";
    echo str_repeat('─', 72) . "\n";

    // ── 1. void invoice → enqueue('void') must insert a void queue row ───────
    $ok  = InvoiceEnqueuer::enqueue($voidInvId, 'void');
    $row = db_row(
        "SELECT operation, status FROM acc_qbo_sync_queue
          WHERE entity_type='invoice' AND entity_id = ? ORDER BY id DESC LIMIT 1",
        [$voidInvId]
    );
    if ($ok === true && $row !== null && $row['operation'] === 'void') {
        $pass("1 void enqueue — queue row inserted with operation='void' (status={$row['status']})");
    } else {
        $fail("1 void enqueue — DROPPED: enqueue returned " . json_encode($ok)
            . ", queue row=" . json_encode($row) . " (pre-fix: false / no row → stale open invoice in QBO)");
    }

    // ── 2. non-vacuous: enqueue('void') on a 'sent' invoice is rejected ──────
    $ok2  = InvoiceEnqueuer::enqueue($sentInvId, 'void');
    $row2 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id = ?", [$sentInvId]);
    if ($ok2 === false && $row2 === null) {
        $pass("2 gate-0 intact — enqueue('void') on a 'sent' invoice rejected (void requires status='void')");
    } else {
        $fail("2 gate-0 — a 'sent' invoice was enqueued for void: returned " . json_encode($ok2) . " row=" . json_encode($row2));
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    echo "  removed C6 invoices + queue rows; restored sync_enabled\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("C6 INVOICE VOID ENQUEUE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
