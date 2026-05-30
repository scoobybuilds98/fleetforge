<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_bill_push.php
 *
 * Smoke test for S-QBO-18 Bill Push (Phase QBO-8 / 1 of 2).
 *
 * Mirrors _smoke_qbo_invoice_push.php structure with bill-specific
 * adjustments: status enum (no 'sent', uses 'approved'); no engine
 * version; no item mapping (uses account mapping); AccountBasedExpense
 * lines instead of sales lines.
 *
 * Sub-checks:
 *  C1: BillPusher + BillEnqueuer class surfaces (public method presence
 *      + RESULT_BASE const exists)
 *  C2: acc_qbo_bill_map shape — 18 cols + 2 UNIQUE keys + 2 indexes + FK
 *  C3: buildQboPayload happy-path multi_currency='1' — VendorRef + DocNumber
 *      + CurrencyRef='CAD' + ExchangeRate='1.0' + Line array shape
 *      (AccountBasedExpenseLineDetail) + TxnTaxDetail.TotalTax
 *  C4: buildQboPayload multi_currency='0' — CurrencyRef + ExchangeRate
 *      omitted entirely (per D-QBO-FIXPACK-12 gate)
 *  C5: buildQboPayload DocNumber falls back to bill_number when
 *      vendor_bill_number is empty (per D-QBO-18-7)
 *  C6: buildQboPayload throws on missing vendor mapping
 *  C7: buildQboPayload throws on missing account mapping for any line
 *  C8: buildQboPayload throws on bill with no lines
 *  C9: buildPrivateNoteJson shape — ff_bill_id + ff_bill_number +
 *      ff_tax_breakdown + pushed_at + (ff_notes when set)
 * C10: pushImpl sync_mode='disabled' — skipped_by_mode persists map row +
 *      non-HTTP sync_log
 * C11: pushImpl status='void' + no mapping — skipped_unmapped_void
 *      (NO map row written per D-QBO-18-4 unmapped variant)
 * C12: pushImpl status='void' + existing mapping — skipped_voided
 *      (map row updated + non-HTTP sync_log written)
 * C13: pushImpl status='draft' — failed_preflight with actionable
 *      error message about approve-first
 * C14: pushImpl already_mapped — returns 'created' outcome
 * C15: pushImpl missing FF bill — ff_not_found
 * C16: pushUpdate delegates to pushImpl (no longer a stub) — S-QBO-BILL-UPDATE
 * C17: BillEnqueuer gate-0 — rejects missing bill
 * C18: BillEnqueuer gate-0 — rejects draft bill for 'create' op
 * C19: BillEnqueuer gate-3 — ACCEPTS 'update' op (S-QBO-BILL-UPDATE) + queue row
 * C20: BillEnqueuer happy path — approved bill + sync_enabled='1'
 *      writes queue row
 * C24: pushUpdate on unmapped bill demotes to create (S-QBO-BILL-UPDATE)
 * C25: BillEnqueuer still rejects 'void' (allowlist = create+update only)
 *
 * Fixtures use sentinel IDs in 999990-999999 range, cleaned up in finally.
 *
 * @session  S-QBO-18
 * @decision D-QBO-18-1..7
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\BillPusher;
use FleetForge\QboPushers\BillEnqueuer;
use FleetForge\Exceptions\QuickBooksException;

$pass = 0;
$total = 25;
$failures = [];

// ──────────────────────────────────────────────────────────────────────────
// Fixture helpers
// ──────────────────────────────────────────────────────────────────────────

function ff_smoke_b_set_setting(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

function ff_smoke_b_get_setting(string $key): ?string
{
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $row['value'] ?? null;
}

function ff_smoke_b_cleanup(): void
{
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type = 'bill' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'bill' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_bill_lines WHERE bill_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_bills WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_vendor_map WHERE ff_vendor_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM vendors WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_accounts WHERE id BETWEEN 999990 AND 999999");
}

/**
 * Insert a synthetic vendor + acc_qbo_vendor_map entry + synthetic
 * expense account + acc_qbo_account_map entry. Returns the IDs for
 * test wiring.
 */
function ff_smoke_b_seed_fixtures(): array
{
    db_execute(
        "INSERT INTO vendors (id, name, vendor_type, currency) VALUES (999990, 'Smoke Vendor #1', 'maintenance', 'CAD')"
    );
    db_execute(
        "INSERT INTO acc_qbo_vendor_map (ff_vendor_id, qbo_vendor_id, mapping_status, qbo_display_name)
         VALUES (999990, 'V-9999', 'mapped', 'Smoke Vendor #1')"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999990, '6991', 'Smoke Repairs Expense', 'operating_expense', 'operating_expense', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status)
         VALUES (999990, 'A-7777', 'mapped')"
    );
    // S-QBO-BILL-GOTCHAS-PAYDOWN: seed a synthetic ap_clearing-tagged account
    // so AccountValidator::assertReadyForBillPush passes during smoke (gate 2
    // of runPreflight). Without this, C21+ tests that exercise the deeper
    // gates can't reach gate 7/8 because gate 2 short-circuits when no
    // ap_clearing FF account is mapped. Sentinel FF#999991 + qbo_id A-9991.
    // Cleaned up in ff_smoke_b_cleanup via the 999990-999999 range DELETE.
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999991, '2010-SMOKE', 'Smoke AP Clearing', 'liability', 'current_liability', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status, is_critical, critical_category)
         VALUES (999991, 'A-9991', 'mapped', 1, 'ap_clearing')"
    );
    return ['vendor_id' => 999990, 'qbo_vendor_id' => 'V-9999', 'account_id' => 999990, 'qbo_account_id' => 'A-7777'];
}

/**
 * Insert a synthetic bill in the given status with one line.
 * period_id = whatever exists (smallest); we don't care about period
 * for push semantics — the bill row just needs to exist.
 */
function ff_smoke_b_seed_bill(int $billId, string $status, array $fixtures, array $overrides = []): void
{
    $period = db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1");
    if ($period === null) {
        throw new \RuntimeException("Smoke prerequisite missing: no acc_periods rows.");
    }
    $vendorBillNumber = $overrides['vendor_bill_number'] ?? 'VENDOR-INV-12345';
    db_execute(
        "INSERT INTO acc_bills (id, bill_number, vendor_id, vendor_bill_number, bill_date, due_date,
                                period_id, status, currency, exchange_rate_to_cad,
                                subtotal, tax_gst_amount, tax_pst_amount, tax_hst_amount, tax_total,
                                total_amount, balance_due)
         VALUES (?, ?, ?, ?, '2026-04-10', '2026-04-30', ?, ?, 'CAD', NULL,
                 '100.00', '5.00', '0.00', '0.00', '5.00',
                 '105.00', '105.00')",
        [
            $billId,
            $overrides['bill_number'] ?? "SMOKE-BILL-{$billId}",
            $fixtures['vendor_id'],
            $vendorBillNumber,
            (int) $period['id'],
            $status,
        ]
    );
    db_execute(
        "INSERT INTO acc_bill_lines (bill_id, account_id, description, quantity, unit_cost, amount,
                                     is_tax_input_credit, tax_gst_amount, tax_pst_amount, tax_hst_amount, sort_order)
         VALUES (?, ?, 'Engine repair labor — 2 hours', '2.0000', '50.00', '100.00',
                 1, '5.00', '0.00', '0.00', 0)",
        [$billId, $fixtures['account_id']]
    );
}

// ──────────────────────────────────────────────────────────────────────────
// Settings snapshot/restore so D-CPA-5 invariant survives smoke runs
// ──────────────────────────────────────────────────────────────────────────

$snapshotKeys = [
    'quickbooks.sync_enabled',
    'quickbooks.sync_mode.bill',
    'quickbooks.multi_currency_enabled',
    'quickbooks.tax_override_code_id',
    'quickbooks.connection_status',
];
$snapshot = [];
foreach ($snapshotKeys as $k) {
    $snapshot[$k] = ff_smoke_b_get_setting($k);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-18 Bill Push Smoke\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_b_cleanup();
    $fx = ff_smoke_b_seed_fixtures();
    ff_smoke_b_set_setting('quickbooks.tax_override_code_id', 'NON');
    ff_smoke_b_set_setting('quickbooks.connection_status', 'connected');

    // ── C1: Class surfaces ─────────────────────────────────────────
    $c1Errors = [];
    if (!class_exists(BillPusher::class)) {
        $c1Errors[] = 'BillPusher class missing';
    } else {
        foreach (['pushCreate', 'pushUpdate', 'buildQboPayload', 'buildPrivateNoteJson'] as $m) {
            if (!method_exists(BillPusher::class, $m)) {
                $c1Errors[] = "BillPusher::{$m} missing";
            }
        }
        $ref = new ReflectionClass(BillPusher::class);
        if (!$ref->hasConstant('RESULT_BASE')) {
            $c1Errors[] = 'BillPusher::RESULT_BASE const missing (§6.8 return-shape parity)';
        }
    }
    if (!class_exists(BillEnqueuer::class)) {
        $c1Errors[] = 'BillEnqueuer class missing';
    } elseif (!method_exists(BillEnqueuer::class, 'enqueue')) {
        $c1Errors[] = 'BillEnqueuer::enqueue missing';
    }
    if (empty($c1Errors)) {
        echo "PASS C1 class surfaces (BillPusher + BillEnqueuer + RESULT_BASE)\n";
        $pass++;
    } else {
        echo "FAIL C1 " . implode('; ', $c1Errors) . "\n";
        $failures[] = 'C1';
    }

    // ── C2: acc_qbo_bill_map shape ──────────────────────────────────
    $c2Errors = [];
    $cols = db_select("SHOW COLUMNS FROM acc_qbo_bill_map");
    $colNames = array_column($cols, 'Field');
    $required = [
        'id', 'ff_bill_id', 'qbo_bill_id', 'qbo_sync_token', 'qbo_doc_number',
        'qbo_vendor_id', 'qbo_total_amt', 'qbo_balance', 'qbo_status',
        'qbo_currency', 'qbo_exchange_rate', 'ff_bill_snapshot_total',
        'push_status', 'push_error', 'pushed_at', 'last_synced_at',
        'created_at', 'updated_at',
    ];
    foreach ($required as $col) {
        if (!in_array($col, $colNames, true)) {
            $c2Errors[] = "missing col: {$col}";
        }
    }
    // push_status ENUM scope per D-QBO-18-4
    $pushStatusCol = null;
    foreach ($cols as $c) {
        if ($c['Field'] === 'push_status') {
            $pushStatusCol = $c['Type'];
            break;
        }
    }
    foreach (['pending', 'pushed', 'voided', 'failed', 'skipped_voided', 'skipped_unmapped_void', 'skipped_by_mode', 'failed_preflight'] as $enum) {
        if ($pushStatusCol === null || strpos($pushStatusCol, "'{$enum}'") === false) {
            $c2Errors[] = "push_status ENUM missing '{$enum}'";
        }
    }
    $indexes = db_select("SHOW INDEX FROM acc_qbo_bill_map");
    $indexNames = array_unique(array_column($indexes, 'Key_name'));
    foreach (['PRIMARY', 'uq_ff_bill', 'uq_qbo_bill', 'idx_status', 'idx_pushed_at'] as $idx) {
        if (!in_array($idx, $indexNames, true)) {
            $c2Errors[] = "missing index: {$idx}";
        }
    }
    if (empty($c2Errors)) {
        echo "PASS C2 acc_qbo_bill_map schema shape\n";
        $pass++;
    } else {
        echo "FAIL C2 " . implode('; ', $c2Errors) . "\n";
        $failures[] = 'C2';
    }

    // ── C3: buildQboPayload happy-path multi_currency='1' ────────────
    ff_smoke_b_set_setting('quickbooks.multi_currency_enabled', '1');
    ff_smoke_b_seed_bill(999990, 'approved', $fx);
    $bill = db_row("SELECT * FROM acc_bills WHERE id = 999990");
    $c3Errors = [];
    try {
        $payload = BillPusher::buildQboPayload($bill);
        if (($payload['VendorRef']['value'] ?? null) !== 'V-9999') {
            $c3Errors[] = "VendorRef.value: got " . json_encode($payload['VendorRef']['value'] ?? null);
        }
        if (($payload['DocNumber'] ?? null) !== 'VENDOR-INV-12345') {
            $c3Errors[] = "DocNumber: got " . json_encode($payload['DocNumber'] ?? null);
        }
        if (($payload['CurrencyRef']['value'] ?? null) !== 'CAD') {
            $c3Errors[] = "CurrencyRef.value: got " . json_encode($payload['CurrencyRef']['value'] ?? null);
        }
        if (($payload['ExchangeRate'] ?? null) !== '1.0') {
            $c3Errors[] = "ExchangeRate: got " . json_encode($payload['ExchangeRate'] ?? null);
        }
        if (!isset($payload['Line']) || !is_array($payload['Line']) || count($payload['Line']) !== 1) {
            $c3Errors[] = "Line array shape: got " . json_encode($payload['Line'] ?? null);
        } else {
            $line = $payload['Line'][0];
            if (($line['DetailType'] ?? null) !== 'AccountBasedExpenseLineDetail') {
                $c3Errors[] = "Line[0].DetailType: got " . json_encode($line['DetailType'] ?? null);
            }
            if (($line['AccountBasedExpenseLineDetail']['AccountRef']['value'] ?? null) !== 'A-7777') {
                $c3Errors[] = "Line[0].AccountRef.value: got " . json_encode($line['AccountBasedExpenseLineDetail']['AccountRef']['value'] ?? null);
            }
            if (($line['AccountBasedExpenseLineDetail']['TaxCodeRef']['value'] ?? null) !== 'NON') {
                $c3Errors[] = "Line[0].TaxCodeRef.value: got " . json_encode($line['AccountBasedExpenseLineDetail']['TaxCodeRef']['value'] ?? null);
            }
            if ((float) ($line['Amount'] ?? 0) !== 100.00) {
                $c3Errors[] = "Line[0].Amount: got " . json_encode($line['Amount'] ?? null);
            }
        }
        if ((float) ($payload['TxnTaxDetail']['TotalTax'] ?? 0) !== 5.00) {
            $c3Errors[] = "TxnTaxDetail.TotalTax: got " . json_encode($payload['TxnTaxDetail']['TotalTax'] ?? null) . ", want 5.00 (bcmath gst+pst+hst sum)";
        }
    } catch (\Throwable $e) {
        $c3Errors[] = "unexpected throw: " . $e->getMessage();
    }
    if (empty($c3Errors)) {
        echo "PASS C3 buildQboPayload happy-path with multi_currency='1' (D-QBO-18-2/3/7 + tax-override)\n";
        $pass++;
    } else {
        echo "FAIL C3 " . implode('; ', $c3Errors) . "\n";
        $failures[] = 'C3';
    }

    // ── C4: buildQboPayload multi_currency='0' — CurrencyRef absent ──
    ff_smoke_b_set_setting('quickbooks.multi_currency_enabled', '0');
    $c4Errors = [];
    $payload4 = BillPusher::buildQboPayload($bill);
    if (array_key_exists('CurrencyRef', $payload4)) {
        $c4Errors[] = "CurrencyRef present when multi_currency_enabled='0'";
    }
    if (array_key_exists('ExchangeRate', $payload4)) {
        $c4Errors[] = "ExchangeRate present when multi_currency_enabled='0'";
    }
    if (empty($c4Errors)) {
        echo "PASS C4 buildQboPayload multi_currency='0' → CurrencyRef + ExchangeRate absent (D-QBO-FIXPACK-12)\n";
        $pass++;
    } else {
        echo "FAIL C4 " . implode('; ', $c4Errors) . "\n";
        $failures[] = 'C4';
    }

    // ── C5: DocNumber fallback to bill_number when vendor_bill_number empty ─
    ff_smoke_b_set_setting('quickbooks.multi_currency_enabled', '1');
    db_execute("UPDATE acc_bills SET vendor_bill_number = NULL WHERE id = 999990");
    $billC5 = db_row("SELECT * FROM acc_bills WHERE id = 999990");
    $c5Errors = [];
    $payloadC5 = BillPusher::buildQboPayload($billC5);
    if (($payloadC5['DocNumber'] ?? null) !== 'SMOKE-BILL-999990') {
        $c5Errors[] = "DocNumber fallback: got " . json_encode($payloadC5['DocNumber'] ?? null) . ", want 'SMOKE-BILL-999990' (fallback to bill_number per D-QBO-18-7)";
    }
    // Restore for later tests
    db_execute("UPDATE acc_bills SET vendor_bill_number = 'VENDOR-INV-12345' WHERE id = 999990");
    if (empty($c5Errors)) {
        echo "PASS C5 DocNumber falls back to bill_number when vendor_bill_number empty (D-QBO-18-7)\n";
        $pass++;
    } else {
        echo "FAIL C5 " . implode('; ', $c5Errors) . "\n";
        $failures[] = 'C5';
    }

    // ── C6: buildQboPayload throws on missing vendor mapping ───────────
    $c6Errors = [];
    try {
        $badBill = $bill;
        $badBill['vendor_id'] = 999991;  // no mapping for this vendor id
        BillPusher::buildQboPayload($badBill);
        $c6Errors[] = "expected throw on missing vendor mapping, none thrown";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), '999991') === false) {
            $c6Errors[] = "exception message should mention vendor id 999991; got: " . $e->getMessage();
        }
    } catch (\Throwable $e) {
        $c6Errors[] = "wrong exception type: " . get_class($e);
    }
    if (empty($c6Errors)) {
        echo "PASS C6 buildQboPayload throws QuickBooksException on missing vendor mapping\n";
        $pass++;
    } else {
        echo "FAIL C6 " . implode('; ', $c6Errors) . "\n";
        $failures[] = 'C6';
    }

    // ── C7: buildQboPayload throws on missing account mapping for any line ─
    $c7Errors = [];
    // Temporarily orphan the line's account mapping by deleting it
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999990");
    try {
        BillPusher::buildQboPayload($bill);
        $c7Errors[] = "expected throw on missing account mapping, none thrown";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), '999990') === false) {
            $c7Errors[] = "exception should mention account id 999990; got: " . $e->getMessage();
        }
    } catch (\Throwable $e) {
        $c7Errors[] = "wrong exception type: " . get_class($e);
    }
    // Restore
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999990, 'A-7777', 'mapped')");
    if (empty($c7Errors)) {
        echo "PASS C7 buildQboPayload throws QuickBooksException on missing account mapping\n";
        $pass++;
    } else {
        echo "FAIL C7 " . implode('; ', $c7Errors) . "\n";
        $failures[] = 'C7';
    }

    // ── C8: buildQboPayload throws on bill with no lines ────────────────
    $c8Errors = [];
    db_execute("DELETE FROM acc_bill_lines WHERE bill_id = 999990");
    try {
        BillPusher::buildQboPayload($bill);
        $c8Errors[] = "expected throw on no-lines bill, none thrown";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), 'no line items') === false) {
            $c8Errors[] = "exception should mention 'no line items'; got: " . $e->getMessage();
        }
    }
    // Restore line
    db_execute(
        "INSERT INTO acc_bill_lines (bill_id, account_id, description, quantity, unit_cost, amount,
                                     is_tax_input_credit, tax_gst_amount, tax_pst_amount, tax_hst_amount, sort_order)
         VALUES (999990, 999990, 'Engine repair labor — 2 hours', '2.0000', '50.00', '100.00',
                 1, '5.00', '0.00', '0.00', 0)"
    );
    if (empty($c8Errors)) {
        echo "PASS C8 buildQboPayload throws on bill with zero lines\n";
        $pass++;
    } else {
        echo "FAIL C8 " . implode('; ', $c8Errors) . "\n";
        $failures[] = 'C8';
    }

    // ── C9: buildPrivateNoteJson shape ──────────────────────────────────
    $c9Errors = [];
    $billC9 = ['id' => 42, 'bill_number' => 'BILL-2026-00042', 'tax_gst_amount' => '5.00', 'tax_pst_amount' => '7.50', 'tax_hst_amount' => '0.00', 'notes' => 'Some operator note'];
    $jsonStr = BillPusher::buildPrivateNoteJson($billC9);
    $decoded = json_decode($jsonStr, true);
    if (!is_array($decoded)) {
        $c9Errors[] = "buildPrivateNoteJson returned non-JSON: " . substr($jsonStr, 0, 200);
    } else {
        if (($decoded['ff_bill_id'] ?? null) !== 42) $c9Errors[] = "missing ff_bill_id";
        if (($decoded['ff_bill_number'] ?? null) !== 'BILL-2026-00042') $c9Errors[] = "missing ff_bill_number";
        if (!isset($decoded['ff_tax_breakdown'])) $c9Errors[] = "missing ff_tax_breakdown";
        if (($decoded['ff_tax_breakdown']['gst'] ?? null) !== '5.00') $c9Errors[] = "ff_tax_breakdown.gst incorrect";
        if (($decoded['ff_notes'] ?? null) !== 'Some operator note') $c9Errors[] = "ff_notes missing";
        if (!isset($decoded['pushed_at'])) $c9Errors[] = "missing pushed_at";
    }
    if (empty($c9Errors)) {
        echo "PASS C9 buildPrivateNoteJson shape (ff_bill_id + bill_number + tax_breakdown + notes + pushed_at)\n";
        $pass++;
    } else {
        echo "FAIL C9 " . implode('; ', $c9Errors) . "\n";
        $failures[] = 'C9';
    }

    // ── C10: pushImpl sync_mode='disabled' — skipped_by_mode ────────────
    ff_smoke_b_set_setting('quickbooks.sync_mode.bill', 'disabled');
    $c10Errors = [];
    $r10 = BillPusher::pushCreate(999990);
    if (($r10['status'] ?? null) !== 'skipped_by_mode') {
        $c10Errors[] = "status: got " . json_encode($r10['status'] ?? null);
    }
    if (($r10['outcome'] ?? null) !== 'skipped') {
        $c10Errors[] = "outcome: got " . json_encode($r10['outcome'] ?? null);
    }
    $mapRow = db_row("SELECT push_status FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    if ($mapRow === null || $mapRow['push_status'] !== 'skipped_by_mode') {
        $c10Errors[] = "map row push_status: got " . json_encode($mapRow['push_status'] ?? null) . " (expected skipped_by_mode)";
    }
    $logRow = db_row("SELECT http_method, response_status, error_code FROM acc_qbo_sync_log WHERE entity_type = 'bill' AND entity_id = 999990 ORDER BY id DESC LIMIT 1");
    if ($logRow === null || $logRow['http_method'] !== 'SKIP' || $logRow['response_status'] !== null) {
        $c10Errors[] = "sync_log SKIP sentinel: got " . json_encode($logRow);
    }
    ff_smoke_b_set_setting('quickbooks.sync_mode.bill', 'queue');
    // Reset map row for downstream tests
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type = 'bill' AND entity_id = 999990");
    if (empty($c10Errors)) {
        echo "PASS C10 pushImpl sync_mode='disabled' → skipped_by_mode + map row + non-HTTP sync_log\n";
        $pass++;
    } else {
        echo "FAIL C10 " . implode('; ', $c10Errors) . "\n";
        $failures[] = 'C10';
    }

    // ── C11: pushImpl status='void' + no mapping → skipped_unmapped_void ─
    db_execute("UPDATE acc_bills SET status = 'void' WHERE id = 999990");
    $c11Errors = [];
    $r11 = BillPusher::pushCreate(999990);
    if (($r11['status'] ?? null) !== 'skipped_unmapped_void') {
        $c11Errors[] = "status: got " . json_encode($r11['status'] ?? null);
    }
    if (($r11['outcome'] ?? null) !== 'skipped') {
        $c11Errors[] = "outcome: got " . json_encode($r11['outcome'] ?? null);
    }
    // No map row should be written (D-QBO-18-4 unmapped variant)
    $mapRow11 = db_row("SELECT id FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    if ($mapRow11 !== null) {
        $c11Errors[] = "map row should NOT exist for skipped_unmapped_void; found id=" . $mapRow11['id'];
    }
    db_execute("UPDATE acc_bills SET status = 'approved' WHERE id = 999990");
    if (empty($c11Errors)) {
        echo "PASS C11 pushImpl status='void' + no mapping → skipped_unmapped_void, no map row (D-QBO-18-4)\n";
        $pass++;
    } else {
        echo "FAIL C11 " . implode('; ', $c11Errors) . "\n";
        $failures[] = 'C11';
    }

    // ── C12: pushImpl status='void' + existing mapping → skipped_voided ─
    // Seed mapping row first
    db_execute(
        "INSERT INTO acc_qbo_bill_map (ff_bill_id, qbo_bill_id, qbo_sync_token, push_status, pushed_at)
         VALUES (999990, 'B-1234', '0', 'pushed', NOW())"
    );
    db_execute("UPDATE acc_bills SET status = 'void' WHERE id = 999990");
    $c12Errors = [];
    $r12 = BillPusher::pushCreate(999990);
    if (($r12['status'] ?? null) !== 'skipped_voided') {
        $c12Errors[] = "status: got " . json_encode($r12['status'] ?? null);
    }
    if (($r12['qbo_id'] ?? null) !== 'B-1234') {
        $c12Errors[] = "qbo_id: got " . json_encode($r12['qbo_id'] ?? null);
    }
    $mapRow12 = db_row("SELECT push_status FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    if ($mapRow12 === null || $mapRow12['push_status'] !== 'skipped_voided') {
        $c12Errors[] = "map row push_status: got " . json_encode($mapRow12['push_status'] ?? null);
    }
    // Cleanup for downstream
    db_execute("UPDATE acc_bills SET status = 'approved' WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type = 'bill' AND entity_id = 999990");
    if (empty($c12Errors)) {
        echo "PASS C12 pushImpl status='void' + existing mapping → skipped_voided + map row updated\n";
        $pass++;
    } else {
        echo "FAIL C12 " . implode('; ', $c12Errors) . "\n";
        $failures[] = 'C12';
    }

    // ── C13: pushImpl status='draft' → failed_preflight ─────────────────
    db_execute("UPDATE acc_bills SET status = 'draft' WHERE id = 999990");
    $c13Errors = [];
    $r13 = BillPusher::pushCreate(999990);
    if (($r13['status'] ?? null) !== 'failed_preflight') {
        $c13Errors[] = "status: got " . json_encode($r13['status'] ?? null);
    }
    if (($r13['outcome'] ?? null) !== 'failed') {
        $c13Errors[] = "outcome: got " . json_encode($r13['outcome'] ?? null);
    }
    if (strpos((string) ($r13['error'] ?? ''), 'draft') === false) {
        $c13Errors[] = "error should mention 'draft'; got: " . json_encode($r13['error'] ?? null);
    }
    db_execute("UPDATE acc_bills SET status = 'approved' WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    if (empty($c13Errors)) {
        echo "PASS C13 pushImpl status='draft' → failed_preflight with actionable error\n";
        $pass++;
    } else {
        echo "FAIL C13 " . implode('; ', $c13Errors) . "\n";
        $failures[] = 'C13';
    }

    // ── C14: pushImpl already_mapped → returns 'created' outcome ────────
    db_execute(
        "INSERT INTO acc_qbo_bill_map (ff_bill_id, qbo_bill_id, qbo_sync_token, qbo_currency, push_status, pushed_at)
         VALUES (999990, 'B-5555', '2', 'CAD', 'pushed', NOW())"
    );
    $c14Errors = [];
    $r14 = BillPusher::pushCreate(999990);
    if (($r14['status'] ?? null) !== 'already_mapped') {
        $c14Errors[] = "status: got " . json_encode($r14['status'] ?? null);
    }
    if (($r14['outcome'] ?? null) !== 'created') {
        $c14Errors[] = "outcome (replay no-op == created): got " . json_encode($r14['outcome'] ?? null);
    }
    if (($r14['qbo_id'] ?? null) !== 'B-5555') {
        $c14Errors[] = "qbo_id: got " . json_encode($r14['qbo_id'] ?? null);
    }
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    if (empty($c14Errors)) {
        echo "PASS C14 pushImpl already_mapped → status='already_mapped', outcome='created'\n";
        $pass++;
    } else {
        echo "FAIL C14 " . implode('; ', $c14Errors) . "\n";
        $failures[] = 'C14';
    }

    // ── C15: pushImpl missing FF bill → ff_not_found ────────────────────
    $c15Errors = [];
    $r15 = BillPusher::pushCreate(999998);  // doesn't exist
    if (($r15['status'] ?? null) !== 'ff_not_found') {
        $c15Errors[] = "status: got " . json_encode($r15['status'] ?? null);
    }
    if (($r15['outcome'] ?? null) !== 'failed') {
        $c15Errors[] = "outcome: got " . json_encode($r15['outcome'] ?? null);
    }
    if (empty($c15Errors)) {
        echo "PASS C15 pushImpl missing FF bill → ff_not_found\n";
        $pass++;
    } else {
        echo "FAIL C15 " . implode('; ', $c15Errors) . "\n";
        $failures[] = 'C15';
    }

    // ── C16: pushUpdate delegates to pushImpl (S-QBO-BILL-UPDATE, closes D-QBO-18-5) ─
    // The old stub returned 'unsupported_in_session' regardless of state.
    // pushUpdate now routes through pushImpl; with sync_mode='disabled' it
    // returns skipped_by_mode at step 1 — proving delegation (definitively
    // NOT the stub). Uses a fresh sentinel (999992) to avoid the mapped
    // 999990 from C14 (which would attempt a real updateEntity → HTTP). The
    // prior sync_mode is restored after.
    $c16Errors = [];
    ff_smoke_b_seed_bill(999992, 'approved', $fx);
    $prevModeC16 = ff_smoke_b_get_setting('quickbooks.sync_mode.bill');
    ff_smoke_b_set_setting('quickbooks.sync_mode.bill', 'disabled');
    $r16 = BillPusher::pushUpdate(999992);
    if (($r16['status'] ?? null) === 'unsupported_in_session') {
        $c16Errors[] = "pushUpdate still a stub — returned unsupported_in_session";
    }
    if (($r16['status'] ?? null) !== 'skipped_by_mode') {
        $c16Errors[] = "expected skipped_by_mode (delegation proof), got " . json_encode($r16['status'] ?? null);
    }
    ff_smoke_b_set_setting('quickbooks.sync_mode.bill', $prevModeC16 ?? 'queue');
    if (empty($c16Errors)) {
        echo "PASS C16 pushUpdate delegates to pushImpl — no longer a stub (S-QBO-BILL-UPDATE)\n";
        $pass++;
    } else {
        echo "FAIL C16 " . implode('; ', $c16Errors) . "\n";
        $failures[] = 'C16';
    }

    // ── C17: BillEnqueuer gate-0 — rejects missing bill ────────────────
    ff_smoke_b_set_setting('quickbooks.sync_enabled', '1');
    $c17Errors = [];
    $r17 = BillEnqueuer::enqueue(999998, 'create');
    if ($r17 !== false) {
        $c17Errors[] = "enqueue should return false for missing bill; got " . json_encode($r17);
    }
    $queued = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type = 'bill' AND entity_id = 999998");
    if ($queued !== null) {
        $c17Errors[] = "no queue row should be written for gate-0 reject; found id=" . $queued['id'];
    }
    if (empty($c17Errors)) {
        echo "PASS C17 BillEnqueuer gate-0 rejects missing bill (S-QBO-ENQUEUER-ELIGIBILITY-GATE)\n";
        $pass++;
    } else {
        echo "FAIL C17 " . implode('; ', $c17Errors) . "\n";
        $failures[] = 'C17';
    }

    // ── C18: BillEnqueuer gate-0 — rejects draft bill for create ──────
    db_execute("UPDATE acc_bills SET status = 'draft' WHERE id = 999990");
    $c18Errors = [];
    $r18 = BillEnqueuer::enqueue(999990, 'create');
    if ($r18 !== false) {
        $c18Errors[] = "enqueue should return false for draft bill on 'create'; got " . json_encode($r18);
    }
    $queued18 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type = 'bill' AND entity_id = 999990");
    if ($queued18 !== null) {
        $c18Errors[] = "no queue row should be written; found id=" . $queued18['id'];
    }
    db_execute("UPDATE acc_bills SET status = 'approved' WHERE id = 999990");
    if (empty($c18Errors)) {
        echo "PASS C18 BillEnqueuer gate-0 rejects draft bill for 'create' op\n";
        $pass++;
    } else {
        echo "FAIL C18 " . implode('; ', $c18Errors) . "\n";
        $failures[] = 'C18';
    }

    // ── C19: BillEnqueuer gate-3 ACCEPTS 'update' op (S-QBO-BILL-UPDATE) ─
    // gate-3 allowlist widened ['create']→['create','update']. 999990 is
    // approved + sync_enabled=1 + sync_mode.bill=queue → enqueue('update')
    // succeeds + inserts a queue row with operation='update'.
    $c19Errors = [];
    ff_smoke_b_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_b_set_setting('quickbooks.sync_mode.bill', 'queue');
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'bill' AND entity_id = 999990");
    $r19 = BillEnqueuer::enqueue(999990, 'update');
    $queued19 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type = 'bill' AND entity_id = 999990 AND operation = 'update'");
    if ($r19 !== true) {
        $c19Errors[] = "enqueue should accept 'update' now; got " . json_encode($r19);
    }
    if ($queued19 === null) {
        $c19Errors[] = "an 'update' queue row should be written; none found";
    }
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'bill' AND entity_id = 999990");
    if (empty($c19Errors)) {
        echo "PASS C19 BillEnqueuer gate-3 accepts 'update' op + inserts queue row (S-QBO-BILL-UPDATE)\n";
        $pass++;
    } else {
        echo "FAIL C19 " . implode('; ', $c19Errors) . "\n";
        $failures[] = 'C19';
    }

    // ── C20: BillEnqueuer happy path — approved bill + sync_enabled='1' ─
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'bill' AND entity_id = 999990");
    $c20Errors = [];
    $r20 = BillEnqueuer::enqueue(999990, 'create');
    if ($r20 !== true) {
        $c20Errors[] = "enqueue should return true; got " . json_encode($r20);
    }
    $queued20 = db_row("SELECT operation, status FROM acc_qbo_sync_queue WHERE entity_type = 'bill' AND entity_id = 999990");
    if ($queued20 === null) {
        $c20Errors[] = "queue row should be written; none found";
    } elseif ($queued20['operation'] !== 'create' || $queued20['status'] !== 'queued') {
        $c20Errors[] = "queue row shape: got " . json_encode($queued20);
    }
    if (empty($c20Errors)) {
        echo "PASS C20 BillEnqueuer happy-path writes queue row (entity_type='bill', op='create', status='queued')\n";
        $pass++;
    } else {
        echo "FAIL C20 " . implode('; ', $c20Errors) . "\n";
        $failures[] = 'C20';
    }

    // ── C24: pushUpdate on UNMAPPED bill demotes to create (S-QBO-BILL-UPDATE) ─
    // No map row → pushImpl step 5b flips operation to 'create' → runs the
    // create pipeline. With the vendor mapping removed, preflight gate 1
    // fails → failed_preflight (returns BEFORE the HTTP boundary). Proves
    // the demotion ran the create path rather than attempting an UPDATE on
    // an entity QBO doesn't know about (which would 404). Vendor mapping is
    // restored afterward so later checks are unaffected.
    $c24Errors = [];
    ff_smoke_b_seed_bill(999993, 'approved', $fx);
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id = 999993");
    db_execute("DELETE FROM acc_qbo_vendor_map WHERE ff_vendor_id = 999990");
    ff_smoke_b_set_setting('quickbooks.sync_mode.bill', 'queue');
    $r24 = BillPusher::pushUpdate(999993);
    $map24 = db_row("SELECT qbo_bill_id FROM acc_qbo_bill_map WHERE ff_bill_id = 999993");
    if (($r24['status'] ?? null) === 'unsupported_in_session') {
        $c24Errors[] = "pushUpdate still a stub";
    }
    if (($r24['status'] ?? null) !== 'failed_preflight') {
        $c24Errors[] = "expected failed_preflight (demoted-create at vendor gate), got " . json_encode($r24['status'] ?? null);
    }
    if (!empty($map24['qbo_bill_id'])) {
        $c24Errors[] = "no qbo_bill_id should be persisted on a failed demoted-create";
    }
    db_execute(
        "INSERT INTO acc_qbo_vendor_map (ff_vendor_id, qbo_vendor_id, mapping_status, qbo_display_name)
         VALUES (999990, 'V-9999', 'mapped', 'Smoke Vendor #1')"
    );
    if (empty($c24Errors)) {
        echo "PASS C24 pushUpdate on unmapped bill demotes to create (failed_preflight at vendor gate; no qbo_id)\n";
        $pass++;
    } else {
        echo "FAIL C24 " . implode('; ', $c24Errors) . "\n";
        $failures[] = 'C24';
    }

    // ── C25: BillEnqueuer still REJECTS 'void' (allowlist = create+update) ─
    $c25Errors = [];
    ff_smoke_b_seed_bill(999994, 'approved', $fx);
    ff_smoke_b_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_b_set_setting('quickbooks.sync_mode.bill', 'queue');
    $r25 = BillEnqueuer::enqueue(999994, 'void');
    $q25 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type = 'bill' AND entity_id = 999994 AND operation = 'void'");
    if ($r25 !== false) {
        $c25Errors[] = "expected false (void not in allowlist), got " . json_encode($r25);
    }
    if ($q25 !== null) {
        $c25Errors[] = "no 'void' queue row should be inserted";
    }
    if (empty($c25Errors)) {
        echo "PASS C25 BillEnqueuer rejects 'void' (gate-3 allowlist = create+update only)\n";
        $pass++;
    } else {
        echo "FAIL C25 " . implode('; ', $c25Errors) . "\n";
        $failures[] = 'C25';
    }

    // ── C21: gate 7 typed status — failed_preflight_field_too_long ──────
    // S-QBO-BILL-GOTCHAS-PAYDOWN D-QBO-BILL-GOTCHAS-2.
    // Set vendor_bill_number > 21 chars; gate 7 fires; map row records
    // typed sub-state (not the generic failed_preflight).
    ff_smoke_b_set_setting('quickbooks.sync_enabled', '0');  // back to default
    ff_smoke_b_set_setting('quickbooks.sync_mode.bill', 'queue');
    db_execute("UPDATE acc_bills SET vendor_bill_number='THIS-IS-A-WAY-TOO-LONG-VENDOR-BILL-NUMBER-FOR-QBO' WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    $c21Errors = [];
    $r21 = BillPusher::pushCreate(999990);
    if (($r21['status'] ?? null) !== 'failed_preflight_field_too_long') {
        $c21Errors[] = "status: got " . json_encode($r21['status'] ?? null) . ", want 'failed_preflight_field_too_long'";
    }
    if (strpos((string) ($r21['error'] ?? ''), 'exceeds QBO Bill.DocNumber limit') === false) {
        $c21Errors[] = "error should mention 'exceeds QBO Bill.DocNumber limit'; got: " . json_encode($r21['error'] ?? null);
    }
    $mapRow21 = db_row("SELECT push_status FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    if ($mapRow21 === null || $mapRow21['push_status'] !== 'failed_preflight_field_too_long') {
        $c21Errors[] = "map row push_status: got " . json_encode($mapRow21['push_status'] ?? null) . " (expected typed sub-state)";
    }
    // Restore for downstream
    db_execute("UPDATE acc_bills SET vendor_bill_number='VENDOR-INV-12345' WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    if (empty($c21Errors)) {
        echo "PASS C21 gate 7 returns failed_preflight_field_too_long typed sub-state + map row records it (D-QBO-BILL-GOTCHAS-2)\n";
        $pass++;
    } else {
        echo "FAIL C21 " . implode('; ', $c21Errors) . "\n";
        $failures[] = 'C21';
    }

    // ── C22: gate 8 SKIPS when multi_currency_enabled='0' (D-QBO-FIXPACK-12) ──
    // The currency-mismatch gate only fires when QBO is multi-currency
    // enabled. With multi-currency disabled, BillPusher omits CurrencyRef
    // entirely and there's nothing to mismatch against — gate must skip
    // (no wasted HTTP probe to QBO).
    ff_smoke_b_set_setting('quickbooks.multi_currency_enabled', '0');
    $c22Errors = [];
    // Use the standard happy-path bill (currency='CAD', vendor mapped CAD)
    // and verify gate 8 doesn't cause a preflight failure. Push will fail
    // at HTTP boundary (no real QBO) but should fail with QBO error, NOT
    // failed_preflight_currency_mismatch.
    $r22 = BillPusher::pushCreate(999990);
    if (($r22['status'] ?? null) === 'failed_preflight_currency_mismatch') {
        $c22Errors[] = "gate 8 should SKIP when multi_currency_enabled='0'; got status=failed_preflight_currency_mismatch";
    }
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='bill' AND entity_id=999990");
    if (empty($c22Errors)) {
        echo "PASS C22 gate 8 (currency-mismatch) SKIPS when multi_currency_enabled='0' (D-QBO-FIXPACK-12 gate)\n";
        $pass++;
    } else {
        echo "FAIL C22 " . implode('; ', $c22Errors) . "\n";
        $failures[] = 'C22';
    }

    // ── C23: ENUM verification — new typed sub-states present ──────────
    // S-QBO-BILL-GOTCHAS-PAYDOWN migration adds 2 new ENUM values.
    // Guards against migration revert + ensures BillPusher's typed
    // sub-state writes won't truncate.
    $c23Errors = [];
    $col23 = db_row("SHOW COLUMNS FROM acc_qbo_bill_map WHERE Field = 'push_status'");
    if (!$col23 || !isset($col23['Type'])) {
        $c23Errors[] = "acc_qbo_bill_map.push_status column not found";
    } else {
        foreach (['failed_preflight_currency_mismatch', 'failed_preflight_field_too_long'] as $newEnum) {
            if (strpos((string) $col23['Type'], "'{$newEnum}'") === false) {
                $c23Errors[] = "push_status ENUM missing '{$newEnum}' (migration S-QBO-BILL-GOTCHAS-PAYDOWN not applied?)";
            }
        }
    }
    if (empty($c23Errors)) {
        echo "PASS C23 acc_qbo_bill_map.push_status ENUM includes failed_preflight_currency_mismatch + failed_preflight_field_too_long (S-QBO-BILL-GOTCHAS-PAYDOWN migration)\n";
        $pass++;
    } else {
        echo "FAIL C23 " . implode('; ', $c23Errors) . "\n";
        $failures[] = 'C23';
    }
} finally {
    ff_smoke_b_cleanup();
    foreach ($snapshotKeys as $k) {
        if ($snapshot[$k] === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            ff_smoke_b_set_setting($k, $snapshot[$k]);
        }
    }
}

echo "\nqbo_bill_push_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " (failures: " . implode(', ', $failures) . ")";
}
echo "\n";

exit(empty($failures) ? 0 : 1);
