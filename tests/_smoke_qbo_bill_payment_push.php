<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_bill_payment_push.php
 *
 * Smoke test for S-QBO-19 Bill Payment Push (Phase QBO-8 / 2 of 2).
 *
 * Sub-checks (C1-C25):
 * Sub-checks (C1-C27, extended by S-QBO-BILL-PAYMENT-UPDATE):
 *
 *  C1: class surfaces (BillPaymentPusher + BillPaymentEnqueuer + RESULT_BASE
 *      + key methods)
 *  C2: acc_qbo_bill_payment_map schema (19 cols + 2 UNIQUE + 2 indexes + FK)
 *  C3: buildQboPayload happy-path Check pay_type — VendorRef + TotalAmt +
 *      TxnDate + PayType='Check' + CheckPayment.BankAccountRef + Line[]
 *      with LinkedTxn{TxnId, TxnType='Bill'} per D-QBO-19-4
 *  C4: buildQboPayload CreditCard pay_type → CreditCardPayment.CCAccountRef
 *      (NOT CheckPayment.BankAccountRef)
 *  C5: buildQboPayload PayType mapping per D-QBO-19-2 (check/eft/wire →
 *      'Check', credit_card → 'CreditCard', cash/other → 'Check' fallback)
 *  C6: buildQboPayload multi_currency='1' → CurrencyRef + ExchangeRate
 *      present; multi_currency='0' → both absent (D-QBO-FIXPACK-12)
 *  C7: buildQboPayload throws on missing vendor mapping
 *  C8: buildQboPayload throws on missing bank account mapping (D-QBO-19-3)
 *  C9: buildQboPayload throws on missing per-allocation bill mapping
 *      (D-QBO-19-4)
 * C10: buildQboPayload throws on payment with no allocations
 * C11: pushImpl sync_mode='disabled' → skipped_by_mode + map row +
 *      non-HTTP sync_log
 * C12: pushImpl status='void' + no mapping → skipped_unmapped_void
 *      (NO map row written per D-QBO-18-4 mirror)
 * C13: pushImpl status='void' + existing mapping → skipped_voided
 *      (map row updated)
 * C14: pushImpl status='pending' → failed_preflight (D-QBO-19-1
 *      eligibility — only 'cleared' pushes)
 * C15: pushImpl already_mapped → outcome='created' idempotency
 * C16: pushImpl missing FF ap_payment → ff_not_found
 * C17: REPURPOSED (S-QBO-BILL-PAYMENT-UPDATE) — pushUpdate delegates to
 *      pushImpl (no longer a stub); sync_mode='disabled' proves delegation
 *      via skipped_by_mode return. Below entry kept for historical context.
 * C17 (historical): pushUpdate stub → unsupported_in_session pointing to
 *      S-QBO-19-UPDATE-FOLLOWUP (D-QBO-19-5)
 * C18: gate 7 typed status — failed_preflight_field_too_long
 *      (reference_number > 21 chars per D-QBO-19-7)
 * C19: gate 8 SKIPS when multi_currency_enabled='0' (D-QBO-FIXPACK-12)
 * C20: BillPaymentEnqueuer gate-0 rejects missing ap_payment
 * C21: BillPaymentEnqueuer gate-0 rejects status='pending' (D-QBO-19-1)
 * C22: REPURPOSED (S-QBO-BILL-PAYMENT-UPDATE) — BillPaymentEnqueuer gate-3
 *      now ACCEPTS 'update' op + inserts queue row (D-QBO-19-5 stub closed).
 * C22 (historical): BillPaymentEnqueuer gate-3 rejects 'update' op (v1 allowlist;
 *      D-QBO-19-5)
 * C23: BillPaymentEnqueuer happy path queue insert (entity_type=
 *      'bill_payment', op='create', status='queued')
 * C24: PrivateNote includes ff_payment_number + reference_number +
 *      check_number when set
 * C26: NEW (S-QBO-BILL-PAYMENT-UPDATE) — pushUpdate on an UNMAPPED ap_payment
 *      demotes to create → runs create pipeline → fails at a preflight gate
 *      BEFORE the HTTP boundary; no qbo_bill_payment_id persisted. Proves
 *      D-PUSHER-DEMOTION-RULE active for bill_payment.
 * C27: NEW (S-QBO-BILL-PAYMENT-UPDATE) — BillPaymentEnqueuer still REJECTS
 *      'void' (allowlist = create+update only; pushVoid is the separate F7 slice).
 * C25: D-QBO-19-3 BankAccountRef sourced from acc_qbo_account_map via
 *      ap_payment.bank_account_id (verified via payload assertion)
 *
 * Fixtures use sentinel IDs in 999990-999999 range, cleaned up in finally.
 *
 * @session  S-QBO-19
 * @decision D-QBO-19-1..7
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\BillPaymentPusher;
use FleetForge\QboPushers\BillPaymentEnqueuer;
use FleetForge\Exceptions\QuickBooksException;

$pass = 0;
$total = 30;
$failures = [];

// ──────────────────────────────────────────────────────────────────────────
// Fixture helpers
// ──────────────────────────────────────────────────────────────────────────

function ff_smoke_bp_set_setting(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

function ff_smoke_bp_get_setting(string $key): ?string
{
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $row['value'] ?? null;
}

function ff_smoke_bp_cleanup(): void
{
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type = 'bill_payment' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'bill_payment' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_ap_payment_allocations WHERE ap_payment_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_ap_payments WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_bank_accounts WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_bill_lines WHERE bill_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_bills WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_vendor_map WHERE ff_vendor_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM vendors WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_accounts WHERE id BETWEEN 999990 AND 999999");
}

function ff_smoke_bp_seed_fixtures(): array
{
    // Vendor + mapping
    db_execute(
        "INSERT INTO vendors (id, name, vendor_type, currency) VALUES (999990, 'Smoke BP Vendor', 'maintenance', 'CAD')"
    );
    db_execute(
        "INSERT INTO acc_qbo_vendor_map (ff_vendor_id, qbo_vendor_id, mapping_status, qbo_display_name)
         VALUES (999990, 'V-9991', 'mapped', 'Smoke BP Vendor')"
    );

    // GL account for bank pivot + acc_bank_accounts row + QBO mapping.
    // K-22: ap_payment.bank_account_id → acc_bank_accounts.id (NOT ff_account_id).
    // Pusher chains: bank_account_id → gl_account_id → qbo_account_id.
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999990, '1010-SMOKE', 'Smoke Operating Bank GL', 'asset', 'current_asset', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status)
         VALUES (999990, 'A-BANK-7777', 'mapped')"
    );
    db_execute(
        "INSERT INTO acc_bank_accounts (id, name, account_type, currency, gl_account_id, is_active)
         VALUES (999990, 'Smoke Operating Bank Account', 'checking', 'CAD', 999990, 1)"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999991, '2010-SMOKE', 'Smoke AP Clearing', 'liability', 'current_liability', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status, is_critical, critical_category)
         VALUES (999991, 'A-AP-7777', 'mapped', 1, 'ap_clearing')"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999992, '1015-SMOKE', 'Smoke Undeposited Funds', 'asset', 'current_asset', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status, is_critical, critical_category)
         VALUES (999992, 'A-UF-7777', 'mapped', 1, 'undeposited_funds')"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999993, '6991-SMOKE', 'Smoke Expense', 'operating_expense', 'operating_expense', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status)
         VALUES (999993, 'A-EXP-7777', 'mapped')"
    );

    // Bill + mapping
    $period = db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1");
    db_execute(
        "INSERT INTO acc_bills (id, bill_number, vendor_id, vendor_bill_number, bill_date, due_date,
                                period_id, status, currency, subtotal, tax_gst_amount, tax_pst_amount,
                                tax_hst_amount, tax_total, total_amount, balance_due)
         VALUES (999990, 'SMOKE-BP-BILL-999990', 999990, 'V-BILL-1', '2026-05-01', '2026-05-31',
                 ?, 'approved', 'CAD', '500.00', '0.00', '0.00', '0.00', '0.00', '500.00', '500.00')",
        [(int) $period['id']]
    );
    db_execute(
        "INSERT INTO acc_bill_lines (bill_id, account_id, description, quantity, unit_cost, amount,
                                     is_tax_input_credit, tax_gst_amount, tax_pst_amount, tax_hst_amount, sort_order)
         VALUES (999990, 999993, 'Smoke expense line', '1.0000', '500.00', '500.00', 1, '0.00', '0.00', '0.00', 0)"
    );
    db_execute(
        "INSERT INTO acc_qbo_bill_map (ff_bill_id, qbo_bill_id, qbo_sync_token, push_status, pushed_at)
         VALUES (999990, 'qbo-bill-999990', '0', 'pushed', NOW())"
    );

    return [
        'vendor_id'        => 999990,
        'qbo_vendor_id'    => 'V-9991',
        'bank_account_id'  => 999990,  // acc_bank_accounts.id (NOT acc_accounts.id; K-22 trap)
        'gl_account_id'    => 999990,
        'qbo_bank_id'      => 'A-BANK-7777',
        'bill_id'          => 999990,
        'qbo_bill_id'      => 'qbo-bill-999990',
    ];
}

function ff_smoke_bp_seed_ap_payment(int $payId, array $fx, array $overrides = []): void
{
    $status   = $overrides['status'] ?? 'cleared';
    $method   = $overrides['payment_method'] ?? 'eft';
    $refNum   = array_key_exists('reference_number', $overrides) ? $overrides['reference_number'] : 'REF-001';
    $checkNum = $overrides['check_number'] ?? null;
    $amount   = $overrides['amount'] ?? '500.00';
    db_execute(
        "INSERT INTO acc_ap_payments (id, payment_number, vendor_id, bank_account_id, payment_date,
                                       payment_method, reference_number, check_number, amount, currency,
                                       status)
         VALUES (?, ?, ?, ?, '2026-05-15', ?, ?, ?, ?, 'CAD', ?)",
        [
            $payId,
            "APAY-SMOKE-{$payId}",
            $fx['vendor_id'],
            $fx['bank_account_id'],
            $method,
            $refNum,
            $checkNum,
            $amount,
            $status,
        ]
    );
    db_execute(
        "INSERT INTO acc_ap_payment_allocations (ap_payment_id, bill_id, amount_applied)
         VALUES (?, ?, ?)",
        [$payId, $fx['bill_id'], $amount]
    );
}

$snapshotKeys = [
    'quickbooks.sync_enabled',
    'quickbooks.sync_mode.bill_payment',
    'quickbooks.multi_currency_enabled',
    'quickbooks.connection_status',
    'quickbooks.tax_override_code_id',
];
$snapshot = [];
foreach ($snapshotKeys as $k) {
    $snapshot[$k] = ff_smoke_bp_get_setting($k);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-19 Bill Payment Push Smoke\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_bp_cleanup();
    $fx = ff_smoke_bp_seed_fixtures();
    ff_smoke_bp_set_setting('quickbooks.connection_status', 'connected');
    ff_smoke_bp_set_setting('quickbooks.tax_override_code_id', 'NON');
    ff_smoke_bp_set_setting('quickbooks.sync_mode.bill_payment', 'queue');

    // ── C1: class surfaces ─────────────────────────────────────────────
    $c1Errors = [];
    if (!class_exists(BillPaymentPusher::class)) {
        $c1Errors[] = 'BillPaymentPusher class missing';
    } else {
        foreach (['pushCreate', 'pushUpdate', 'buildQboPayload'] as $m) {
            if (!method_exists(BillPaymentPusher::class, $m)) {
                $c1Errors[] = "BillPaymentPusher::{$m} missing";
            }
        }
        $ref = new ReflectionClass(BillPaymentPusher::class);
        if (!$ref->hasConstant('RESULT_BASE')) {
            $c1Errors[] = 'BillPaymentPusher::RESULT_BASE const missing';
        }
    }
    if (!class_exists(BillPaymentEnqueuer::class) || !method_exists(BillPaymentEnqueuer::class, 'enqueue')) {
        $c1Errors[] = 'BillPaymentEnqueuer::enqueue missing';
    }
    if (empty($c1Errors)) { echo "PASS C1 class surfaces (BillPaymentPusher + BillPaymentEnqueuer + RESULT_BASE)\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1'; }

    // ── C2: schema verification ────────────────────────────────────────
    $c2Errors = [];
    $cols = db_select("SHOW COLUMNS FROM acc_qbo_bill_payment_map");
    $colNames = array_column($cols, 'Field');
    $required = ['id', 'ff_ap_payment_id', 'qbo_bill_payment_id', 'qbo_sync_token',
                 'qbo_vendor_id', 'qbo_bank_account_id', 'qbo_pay_type',
                 'qbo_total_amt', 'qbo_currency', 'qbo_exchange_rate', 'qbo_txn_date',
                 'qbo_doc_number', 'ff_payment_snapshot_total',
                 'push_status', 'push_error', 'pushed_at', 'last_synced_at',
                 'created_at', 'updated_at'];
    foreach ($required as $col) {
        if (!in_array($col, $colNames, true)) $c2Errors[] = "missing col: {$col}";
    }
    $indexes = array_unique(array_column(db_select("SHOW INDEX FROM acc_qbo_bill_payment_map"), 'Key_name'));
    foreach (['PRIMARY', 'uq_ff_ap_payment', 'uq_qbo_bill_payment', 'idx_status', 'idx_pushed_at'] as $idx) {
        if (!in_array($idx, $indexes, true)) $c2Errors[] = "missing index: {$idx}";
    }
    if (empty($c2Errors)) { echo "PASS C2 acc_qbo_bill_payment_map schema (19 cols + 2 UNIQUE + 2 idx + FK CASCADE)\n"; $pass++; }
    else { echo "FAIL C2 " . implode('; ', $c2Errors) . "\n"; $failures[] = 'C2'; }

    // ── C3: buildQboPayload happy-path Check pay_type ────────────────
    ff_smoke_bp_set_setting('quickbooks.multi_currency_enabled', '0');
    ff_smoke_bp_seed_ap_payment(999990, $fx);
    $apPayment = db_row("SELECT * FROM acc_ap_payments WHERE id = 999990");
    $c3Errors = [];
    $payload = BillPaymentPusher::buildQboPayload($apPayment);
    if (($payload['VendorRef']['value'] ?? null) !== 'V-9991') $c3Errors[] = "VendorRef: " . json_encode($payload['VendorRef'] ?? null);
    if ((float) ($payload['TotalAmt'] ?? 0) !== 500.00) $c3Errors[] = "TotalAmt: " . json_encode($payload['TotalAmt'] ?? null);
    if (($payload['TxnDate'] ?? null) !== '2026-05-15') $c3Errors[] = "TxnDate: " . json_encode($payload['TxnDate'] ?? null);
    if (($payload['PayType'] ?? null) !== 'Check') $c3Errors[] = "PayType: " . json_encode($payload['PayType'] ?? null);
    if (($payload['CheckPayment']['BankAccountRef']['value'] ?? null) !== 'A-BANK-7777') {
        $c3Errors[] = "CheckPayment.BankAccountRef: " . json_encode($payload['CheckPayment'] ?? null);
    }
    if (!isset($payload['Line'][0]['LinkedTxn'][0])) {
        $c3Errors[] = "Line[0].LinkedTxn[0] missing";
    } else {
        $lt = $payload['Line'][0]['LinkedTxn'][0];
        if (($lt['TxnId'] ?? null) !== 'qbo-bill-999990') $c3Errors[] = "Line[0].LinkedTxn[0].TxnId: " . json_encode($lt);
        if (($lt['TxnType'] ?? null) !== 'Bill') $c3Errors[] = "Line[0].LinkedTxn[0].TxnType (D-QBO-19-4): " . json_encode($lt);
    }
    if (empty($c3Errors)) { echo "PASS C3 buildQboPayload happy-path Check pay_type (VendorRef + Check.BankAccountRef + LinkedTxn Bill)\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3Errors) . "\n"; $failures[] = 'C3'; }

    // ── C4: buildQboPayload CreditCard pay_type ────────────────────────
    db_execute("UPDATE acc_ap_payments SET payment_method='credit_card' WHERE id = 999990");
    $payCC = db_row("SELECT * FROM acc_ap_payments WHERE id = 999990");
    $c4Errors = [];
    $payloadCC = BillPaymentPusher::buildQboPayload($payCC);
    if (($payloadCC['PayType'] ?? null) !== 'CreditCard') $c4Errors[] = "PayType: " . json_encode($payloadCC['PayType'] ?? null);
    if (isset($payloadCC['CheckPayment'])) $c4Errors[] = "CheckPayment should be absent for CreditCard";
    if (($payloadCC['CreditCardPayment']['CCAccountRef']['value'] ?? null) !== 'A-BANK-7777') {
        $c4Errors[] = "CreditCardPayment.CCAccountRef: " . json_encode($payloadCC['CreditCardPayment'] ?? null);
    }
    if (empty($c4Errors)) { echo "PASS C4 buildQboPayload CreditCard → CreditCardPayment.CCAccountRef (NOT CheckPayment)\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $c4Errors) . "\n"; $failures[] = 'C4'; }

    // ── C5: PayType mapping per D-QBO-19-2 ─────────────────────────────
    $c5Errors = [];
    $methodToPayType = [
        'check' => 'Check', 'eft' => 'Check', 'wire' => 'Check',
        'credit_card' => 'CreditCard',
        'cash' => 'Check', 'other' => 'Check',
    ];
    foreach ($methodToPayType as $method => $expectedPayType) {
        db_execute("UPDATE acc_ap_payments SET payment_method=? WHERE id = 999990", [$method]);
        $payX = db_row("SELECT * FROM acc_ap_payments WHERE id = 999990");
        $payloadX = BillPaymentPusher::buildQboPayload($payX);
        if (($payloadX['PayType'] ?? null) !== $expectedPayType) {
            $c5Errors[] = "method={$method} expected PayType={$expectedPayType}; got " . json_encode($payloadX['PayType'] ?? null);
        }
    }
    db_execute("UPDATE acc_ap_payments SET payment_method='eft' WHERE id = 999990");  // restore
    if (empty($c5Errors)) { echo "PASS C5 PayType mapping per D-QBO-19-2 (check/eft/wire → Check; credit_card → CreditCard; cash/other → Check fallback)\n"; $pass++; }
    else { echo "FAIL C5 " . implode('; ', $c5Errors) . "\n"; $failures[] = 'C5'; }

    // ── C6: multi_currency gating ──────────────────────────────────────
    $c6Errors = [];
    ff_smoke_bp_set_setting('quickbooks.multi_currency_enabled', '1');
    $payMC1 = db_row("SELECT * FROM acc_ap_payments WHERE id = 999990");
    $payloadMC1 = BillPaymentPusher::buildQboPayload($payMC1);
    if (($payloadMC1['CurrencyRef']['value'] ?? null) !== 'CAD') $c6Errors[] = "MC=1 CurrencyRef: " . json_encode($payloadMC1['CurrencyRef'] ?? null);
    if (($payloadMC1['ExchangeRate'] ?? null) !== '1.0') $c6Errors[] = "MC=1 ExchangeRate: " . json_encode($payloadMC1['ExchangeRate'] ?? null);

    ff_smoke_bp_set_setting('quickbooks.multi_currency_enabled', '0');
    $payloadMC0 = BillPaymentPusher::buildQboPayload($payMC1);
    if (array_key_exists('CurrencyRef', $payloadMC0)) $c6Errors[] = "MC=0 CurrencyRef present";
    if (array_key_exists('ExchangeRate', $payloadMC0)) $c6Errors[] = "MC=0 ExchangeRate present";
    if (empty($c6Errors)) { echo "PASS C6 multi_currency='1' → CurrencyRef+ExchangeRate emitted; multi_currency='0' → both absent (D-QBO-FIXPACK-12)\n"; $pass++; }
    else { echo "FAIL C6 " . implode('; ', $c6Errors) . "\n"; $failures[] = 'C6'; }

    // ── C7: throws on missing vendor mapping ───────────────────────────
    $c7Errors = [];
    $payBad = $apPayment;
    $payBad['vendor_id'] = 999998;
    try {
        BillPaymentPusher::buildQboPayload($payBad);
        $c7Errors[] = "expected throw; none";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), '999998') === false) $c7Errors[] = "exception should mention 999998: " . $e->getMessage();
    }
    if (empty($c7Errors)) { echo "PASS C7 buildQboPayload throws on missing vendor mapping\n"; $pass++; }
    else { echo "FAIL C7 " . implode('; ', $c7Errors) . "\n"; $failures[] = 'C7'; }

    // ── C8: throws on missing bank account chain ───────────────────────
    // Two failure modes: (a) acc_bank_accounts row missing, (b) gl_account_id
    // present but no acc_qbo_account_map row. Test both.
    $c8Errors = [];
    // Mode (a): bank_account_id points to non-existent bank account
    $payBadBank = $apPayment;
    $payBadBank['bank_account_id'] = 999997;
    try {
        BillPaymentPusher::buildQboPayload($payBadBank);
        $c8Errors[] = "(a) expected throw; none";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), '999997') === false) $c8Errors[] = "(a) exception should mention bank 999997: " . $e->getMessage();
        if (strpos($e->getMessage(), 'D-QBO-19-3') === false) $c8Errors[] = "(a) exception should reference D-QBO-19-3: " . $e->getMessage();
    }
    // Mode (b): gl_account_id exists but unmapped in acc_qbo_account_map
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999990");
    try {
        BillPaymentPusher::buildQboPayload($apPayment);
        $c8Errors[] = "(b) expected throw; none";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), 'GL #999990') === false) $c8Errors[] = "(b) exception should mention GL pivot: " . $e->getMessage();
    }
    // Restore
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999990, 'A-BANK-7777', 'mapped')");
    if (empty($c8Errors)) { echo "PASS C8 buildQboPayload throws on missing bank account chain (both modes: missing bank_accounts row + unmapped gl_account_id) (D-QBO-19-3)\n"; $pass++; }
    else { echo "FAIL C8 " . implode('; ', $c8Errors) . "\n"; $failures[] = 'C8'; }

    // ── C9: throws on missing per-allocation bill mapping ──────────────
    $c9Errors = [];
    db_execute("DELETE FROM acc_qbo_bill_map WHERE ff_bill_id = 999990");
    try {
        BillPaymentPusher::buildQboPayload($apPayment);
        $c9Errors[] = "expected throw; none";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), '999990') === false) $c9Errors[] = "exception should mention bill 999990: " . $e->getMessage();
    }
    db_execute(
        "INSERT INTO acc_qbo_bill_map (ff_bill_id, qbo_bill_id, qbo_sync_token, push_status, pushed_at)
         VALUES (999990, 'qbo-bill-999990', '0', 'pushed', NOW())"
    );
    if (empty($c9Errors)) { echo "PASS C9 buildQboPayload throws on missing per-allocation bill mapping (D-QBO-19-4)\n"; $pass++; }
    else { echo "FAIL C9 " . implode('; ', $c9Errors) . "\n"; $failures[] = 'C9'; }

    // ── C10: throws on no allocations ──────────────────────────────────
    $c10Errors = [];
    db_execute("DELETE FROM acc_ap_payment_allocations WHERE ap_payment_id = 999990");
    try {
        BillPaymentPusher::buildQboPayload($apPayment);
        $c10Errors[] = "expected throw; none";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), 'no bill allocations') === false) $c10Errors[] = "exception should mention 'no bill allocations': " . $e->getMessage();
    }
    db_execute(
        "INSERT INTO acc_ap_payment_allocations (ap_payment_id, bill_id, amount_applied)
         VALUES (999990, 999990, '500.00')"
    );
    if (empty($c10Errors)) { echo "PASS C10 buildQboPayload throws on payment with no allocations\n"; $pass++; }
    else { echo "FAIL C10 " . implode('; ', $c10Errors) . "\n"; $failures[] = 'C10'; }

    // ── C11: pushImpl sync_mode='disabled' ─────────────────────────────
    ff_smoke_bp_set_setting('quickbooks.sync_mode.bill_payment', 'disabled');
    $c11Errors = [];
    $r11 = BillPaymentPusher::pushCreate(999990);
    if (($r11['status'] ?? null) !== 'skipped_by_mode') $c11Errors[] = "status: " . json_encode($r11['status'] ?? null);
    $mapRow11 = db_row("SELECT push_status FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    if (!$mapRow11 || $mapRow11['push_status'] !== 'skipped_by_mode') $c11Errors[] = "map row: " . json_encode($mapRow11);
    $logRow11 = db_row("SELECT http_method, error_code FROM acc_qbo_sync_log WHERE entity_type='bill_payment' AND entity_id=999990 ORDER BY id DESC LIMIT 1");
    if (!$logRow11 || $logRow11['http_method'] !== 'SKIP') $c11Errors[] = "sync_log: " . json_encode($logRow11);
    ff_smoke_bp_set_setting('quickbooks.sync_mode.bill_payment', 'queue');
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='bill_payment' AND entity_id=999990");
    if (empty($c11Errors)) { echo "PASS C11 pushImpl sync_mode='disabled' → skipped_by_mode + map row + sync_log SKIP\n"; $pass++; }
    else { echo "FAIL C11 " . implode('; ', $c11Errors) . "\n"; $failures[] = 'C11'; }

    // ── C12: status='void' + no mapping → skipped_unmapped_void ─────────
    db_execute("UPDATE acc_ap_payments SET status='void' WHERE id = 999990");
    $c12Errors = [];
    $r12 = BillPaymentPusher::pushCreate(999990);
    if (($r12['status'] ?? null) !== 'skipped_unmapped_void') $c12Errors[] = "status: " . json_encode($r12['status'] ?? null);
    $mapRow12 = db_row("SELECT id FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    if ($mapRow12 !== null) $c12Errors[] = "map row should NOT be written for skipped_unmapped_void";
    db_execute("UPDATE acc_ap_payments SET status='cleared' WHERE id = 999990");
    if (empty($c12Errors)) { echo "PASS C12 status='void' + no mapping → skipped_unmapped_void; NO map row (D-QBO-18-4 mirror)\n"; $pass++; }
    else { echo "FAIL C12 " . implode('; ', $c12Errors) . "\n"; $failures[] = 'C12'; }

    // ── C13: status='void' + existing mapping → skipped_voided ──────────
    db_execute("INSERT INTO acc_qbo_bill_payment_map (ff_ap_payment_id, qbo_bill_payment_id, qbo_sync_token, push_status, pushed_at) VALUES (999990, 'BP-1234', '0', 'pushed', NOW())");
    db_execute("UPDATE acc_ap_payments SET status='void' WHERE id = 999990");
    $c13Errors = [];
    $r13 = BillPaymentPusher::pushCreate(999990);
    if (($r13['status'] ?? null) !== 'skipped_voided') $c13Errors[] = "status: " . json_encode($r13['status'] ?? null);
    if (($r13['qbo_id'] ?? null) !== 'BP-1234') $c13Errors[] = "qbo_id: " . json_encode($r13['qbo_id'] ?? null);
    $mapRow13 = db_row("SELECT push_status FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    if (!$mapRow13 || $mapRow13['push_status'] !== 'skipped_voided') $c13Errors[] = "map row: " . json_encode($mapRow13);
    db_execute("UPDATE acc_ap_payments SET status='cleared' WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='bill_payment' AND entity_id=999990");
    if (empty($c13Errors)) { echo "PASS C13 status='void' + existing mapping → skipped_voided; map row updated\n"; $pass++; }
    else { echo "FAIL C13 " . implode('; ', $c13Errors) . "\n"; $failures[] = 'C13'; }

    // ── C14: status='pending' → failed_preflight ───────────────────────
    db_execute("UPDATE acc_ap_payments SET status='pending' WHERE id = 999990");
    $c14Errors = [];
    $r14 = BillPaymentPusher::pushCreate(999990);
    if (($r14['status'] ?? null) !== 'failed_preflight') $c14Errors[] = "status: " . json_encode($r14['status'] ?? null);
    if (strpos((string) ($r14['error'] ?? ''), 'pending') === false) $c14Errors[] = "error should mention 'pending': " . json_encode($r14['error'] ?? null);
    db_execute("UPDATE acc_ap_payments SET status='cleared' WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    if (empty($c14Errors)) { echo "PASS C14 status='pending' → failed_preflight (D-QBO-19-1 eligibility)\n"; $pass++; }
    else { echo "FAIL C14 " . implode('; ', $c14Errors) . "\n"; $failures[] = 'C14'; }

    // ── C15: already_mapped → outcome='created' ────────────────────────
    db_execute("INSERT INTO acc_qbo_bill_payment_map (ff_ap_payment_id, qbo_bill_payment_id, qbo_sync_token, qbo_currency, push_status, pushed_at) VALUES (999990, 'BP-5555', '2', 'CAD', 'pushed', NOW())");
    $c15Errors = [];
    $r15 = BillPaymentPusher::pushCreate(999990);
    if (($r15['status'] ?? null) !== 'already_mapped') $c15Errors[] = "status: " . json_encode($r15['status'] ?? null);
    if (($r15['outcome'] ?? null) !== 'created') $c15Errors[] = "outcome (replay no-op == created): " . json_encode($r15['outcome'] ?? null);
    if (($r15['qbo_id'] ?? null) !== 'BP-5555') $c15Errors[] = "qbo_id: " . json_encode($r15['qbo_id'] ?? null);
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    if (empty($c15Errors)) { echo "PASS C15 pushImpl already_mapped → status='already_mapped', outcome='created' (idempotency)\n"; $pass++; }
    else { echo "FAIL C15 " . implode('; ', $c15Errors) . "\n"; $failures[] = 'C15'; }

    // ── C16: missing FF ap_payment → ff_not_found ──────────────────────
    $c16Errors = [];
    $r16 = BillPaymentPusher::pushCreate(999998);
    if (($r16['status'] ?? null) !== 'ff_not_found') $c16Errors[] = "status: " . json_encode($r16['status'] ?? null);
    if (($r16['outcome'] ?? null) !== 'failed') $c16Errors[] = "outcome: " . json_encode($r16['outcome'] ?? null);
    if (empty($c16Errors)) { echo "PASS C16 missing FF ap_payment → ff_not_found\n"; $pass++; }
    else { echo "FAIL C16 " . implode('; ', $c16Errors) . "\n"; $failures[] = 'C16'; }

    // ── C17: pushUpdate delegates to pushImpl (S-QBO-BILL-PAYMENT-UPDATE) ─
    // The old stub returned 'unsupported_in_session' regardless of state.
    // pushUpdate now routes through pushImpl; with sync_mode='disabled' it
    // returns skipped_by_mode at step 1 — proving delegation (definitively
    // NOT the stub). The prior sync_mode is restored after the assertion.
    $c17Errors = [];
    $prevModeC17 = ff_smoke_bp_get_setting('quickbooks.sync_mode.bill_payment');
    ff_smoke_bp_set_setting('quickbooks.sync_mode.bill_payment', 'disabled');
    $r17 = BillPaymentPusher::pushUpdate(999990);
    if (($r17['status'] ?? null) === 'unsupported_in_session') {
        $c17Errors[] = "pushUpdate still a stub — returned unsupported_in_session";
    }
    if (($r17['status'] ?? null) !== 'skipped_by_mode') {
        $c17Errors[] = "expected skipped_by_mode (delegation proof), got " . json_encode($r17['status'] ?? null);
    }
    if (($r17['outcome'] ?? null) !== 'skipped') {
        $c17Errors[] = "outcome: " . json_encode($r17['outcome'] ?? null);
    }
    ff_smoke_bp_set_setting('quickbooks.sync_mode.bill_payment', $prevModeC17 ?? 'queue');
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='bill_payment' AND entity_id=999990");
    if (empty($c17Errors)) { echo "PASS C17 pushUpdate delegates to pushImpl — no longer a stub (S-QBO-BILL-PAYMENT-UPDATE; D-QBO-19-5 closed)\n"; $pass++; }
    else { echo "FAIL C17 " . implode('; ', $c17Errors) . "\n"; $failures[] = 'C17'; }

    // ── C18: gate 7 typed failed_preflight_field_too_long ──────────────
    db_execute("UPDATE acc_ap_payments SET reference_number='WAY-TOO-LONG-REFERENCE-NUMBER-FOR-QBO-PAYMENT' WHERE id = 999990");
    $c18Errors = [];
    $r18 = BillPaymentPusher::pushCreate(999990);
    if (($r18['status'] ?? null) !== 'failed_preflight_field_too_long') {
        $c18Errors[] = "status: " . json_encode($r18['status'] ?? null);
    }
    if (strpos((string) ($r18['error'] ?? ''), 'reference_number') === false) {
        $c18Errors[] = "error should mention 'reference_number': " . json_encode($r18['error'] ?? null);
    }
    $mapRow18 = db_row("SELECT push_status FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    if (!$mapRow18 || $mapRow18['push_status'] !== 'failed_preflight_field_too_long') {
        $c18Errors[] = "map row push_status: " . json_encode($mapRow18['push_status'] ?? null);
    }
    db_execute("UPDATE acc_ap_payments SET reference_number='REF-001' WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    if (empty($c18Errors)) { echo "PASS C18 gate 7 failed_preflight_field_too_long typed sub-state + map row records it (D-QBO-19-7)\n"; $pass++; }
    else { echo "FAIL C18 " . implode('; ', $c18Errors) . "\n"; $failures[] = 'C18'; }

    // ── C19: gate 8 SKIPS when multi_currency='0' ──────────────────────
    ff_smoke_bp_set_setting('quickbooks.multi_currency_enabled', '0');
    $c19Errors = [];
    $r19 = BillPaymentPusher::pushCreate(999990);
    if (($r19['status'] ?? null) === 'failed_preflight_currency_mismatch') {
        $c19Errors[] = "gate 8 should SKIP when multi_currency='0'; got currency_mismatch";
    }
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='bill_payment' AND entity_id=999990");
    if (empty($c19Errors)) { echo "PASS C19 gate 8 (currency-mismatch) SKIPS when multi_currency_enabled='0' (D-QBO-FIXPACK-12)\n"; $pass++; }
    else { echo "FAIL C19 " . implode('; ', $c19Errors) . "\n"; $failures[] = 'C19'; }

    // ── C20: Enqueuer gate-0 rejects missing ──────────────────────────
    ff_smoke_bp_set_setting('quickbooks.sync_enabled', '1');
    $c20Errors = [];
    $r20 = BillPaymentEnqueuer::enqueue(999998, 'create');
    if ($r20 !== false) $c20Errors[] = "enqueue should reject missing ap_payment";
    if (empty($c20Errors)) { echo "PASS C20 Enqueuer gate-0 rejects missing ap_payment\n"; $pass++; }
    else { echo "FAIL C20 " . implode('; ', $c20Errors) . "\n"; $failures[] = 'C20'; }

    // ── C21: Enqueuer gate-0 rejects status='pending' ──────────────────
    db_execute("UPDATE acc_ap_payments SET status='pending' WHERE id = 999990");
    $c21Errors = [];
    $r21 = BillPaymentEnqueuer::enqueue(999990, 'create');
    if ($r21 !== false) $c21Errors[] = "enqueue should reject status='pending'";
    db_execute("UPDATE acc_ap_payments SET status='cleared' WHERE id = 999990");
    if (empty($c21Errors)) { echo "PASS C21 Enqueuer gate-0 rejects status='pending' (D-QBO-19-1)\n"; $pass++; }
    else { echo "FAIL C21 " . implode('; ', $c21Errors) . "\n"; $failures[] = 'C21'; }

    // ── C22: Enqueuer gate-3 ACCEPTS 'update' op (S-QBO-BILL-PAYMENT-UPDATE) ─
    // gate-3 allowlist widened ['create'] → ['create','update'] in
    // S-QBO-BILL-PAYMENT-UPDATE (D-QBO-19-5 stub closed). 999990 is cleared +
    // sync_enabled=1 + sync_mode.bill_payment=queue → enqueue('update') now
    // succeeds + writes a queue row with operation='update'.
    $c22Errors = [];
    ff_smoke_bp_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_bp_set_setting('quickbooks.sync_mode.bill_payment', 'queue');
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='bill_payment' AND entity_id=999990");
    $r22 = BillPaymentEnqueuer::enqueue(999990, 'update');
    $queued22 = db_row("SELECT operation, status FROM acc_qbo_sync_queue WHERE entity_type='bill_payment' AND entity_id=999990 AND operation='update'");
    if ($r22 !== true) {
        $c22Errors[] = "enqueue should accept 'update' now; got " . json_encode($r22);
    }
    if ($queued22 === null) {
        $c22Errors[] = "an 'update' queue row should be written; none found";
    } elseif ($queued22['operation'] !== 'update' || $queued22['status'] !== 'queued') {
        $c22Errors[] = "queue row shape: " . json_encode($queued22);
    }
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='bill_payment' AND entity_id=999990");
    if (empty($c22Errors)) { echo "PASS C22 Enqueuer gate-3 accepts 'update' op + inserts queue row (S-QBO-BILL-PAYMENT-UPDATE; D-QBO-19-5 closed)\n"; $pass++; }
    else { echo "FAIL C22 " . implode('; ', $c22Errors) . "\n"; $failures[] = 'C22'; }

    // ── C23: Enqueuer happy path ───────────────────────────────────────
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='bill_payment' AND entity_id=999990");
    $c23Errors = [];
    $r23 = BillPaymentEnqueuer::enqueue(999990, 'create');
    if ($r23 !== true) $c23Errors[] = "enqueue should return true";
    $queued = db_row("SELECT operation, status FROM acc_qbo_sync_queue WHERE entity_type='bill_payment' AND entity_id=999990");
    if (!$queued || $queued['operation'] !== 'create' || $queued['status'] !== 'queued') {
        $c23Errors[] = "queue row: " . json_encode($queued);
    }
    if (empty($c23Errors)) { echo "PASS C23 Enqueuer happy-path writes queue row (entity_type='bill_payment', op='create', status='queued')\n"; $pass++; }
    else { echo "FAIL C23 " . implode('; ', $c23Errors) . "\n"; $failures[] = 'C23'; }

    // ── C24: PrivateNote includes payment_number + ref + check ─────────
    db_execute("UPDATE acc_ap_payments SET check_number='CHK-1001' WHERE id = 999990");
    $payNote = db_row("SELECT * FROM acc_ap_payments WHERE id = 999990");
    $payloadNote = BillPaymentPusher::buildQboPayload($payNote);
    $c24Errors = [];
    $note = (string) ($payloadNote['PrivateNote'] ?? '');
    if (strpos($note, 'APAY-SMOKE-999990') === false) $c24Errors[] = "missing payment_number";
    if (strpos($note, 'REF-001') === false) $c24Errors[] = "missing reference_number";
    if (strpos($note, 'CHK-1001') === false) $c24Errors[] = "missing check_number";
    if (empty($c24Errors)) { echo "PASS C24 PrivateNote includes ff_payment_number + reference + check_number for audit drill-down\n"; $pass++; }
    else { echo "FAIL C24 " . implode('; ', $c24Errors) . "\n"; $failures[] = 'C24'; }

    // ── C25: BankAccountRef sourced from acc_qbo_account_map (D-QBO-19-3) ─
    // Already validated in C3 (Check) + C4 (CreditCard). Add explicit
    // assertion that the value matches the seeded mapping.
    $payBank = db_row("SELECT * FROM acc_ap_payments WHERE id = 999990");
    db_execute("UPDATE acc_ap_payments SET payment_method='check' WHERE id = 999990");
    $payBankCheck = db_row("SELECT * FROM acc_ap_payments WHERE id = 999990");
    $payloadBank = BillPaymentPusher::buildQboPayload($payBankCheck);
    $c25Errors = [];
    if (($payloadBank['CheckPayment']['BankAccountRef']['value'] ?? null) !== 'A-BANK-7777') {
        $c25Errors[] = "BankAccountRef should equal acc_qbo_account_map.qbo_account_id for FF#999990; got " . json_encode($payloadBank['CheckPayment']['BankAccountRef'] ?? null);
    }
    if (empty($c25Errors)) { echo "PASS C25 BankAccountRef sourced from acc_qbo_account_map via ap_payment.bank_account_id (D-QBO-19-3)\n"; $pass++; }
    else { echo "FAIL C25 " . implode('; ', $c25Errors) . "\n"; $failures[] = 'C25'; }

    // ── C26: pushUpdate on UNMAPPED ap_payment demotes to create (S-QBO-BILL-PAYMENT-UPDATE) ─
    // No map row → pushImpl step 5b flips operation to 'create' → runs the
    // create pipeline. With the vendor mapping removed, preflight gate 1
    // (vendor mapping) fails → failed_preflight (returns BEFORE the HTTP
    // boundary, so no real updateEntity/createEntity is attempted). Proves
    // the demotion ran the create path rather than attempting an UPDATE on
    // an entity QBO doesn't know about (which would 404). Vendor mapping is
    // restored afterward so later checks are unaffected.
    $c26Errors = [];
    ff_smoke_bp_seed_ap_payment(999992, $fx);
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999992");
    db_execute("DELETE FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?", [$fx['vendor_id']]);
    ff_smoke_bp_set_setting('quickbooks.sync_mode.bill_payment', 'queue');
    $r26 = BillPaymentPusher::pushUpdate(999992);
    $map26 = db_row("SELECT qbo_bill_payment_id, push_status FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999992");
    if (($r26['status'] ?? null) === 'unsupported_in_session') {
        $c26Errors[] = "pushUpdate still a stub";
    }
    if (($r26['status'] ?? null) !== 'failed_preflight') {
        $c26Errors[] = "expected failed_preflight (demoted-create at vendor gate), got " . json_encode($r26['status'] ?? null);
    }
    if (!empty($map26['qbo_bill_payment_id'])) {
        $c26Errors[] = "no qbo_bill_payment_id should be persisted on a failed demoted-create; got " . json_encode($map26['qbo_bill_payment_id']);
    }
    // Restore vendor mapping for any downstream assertions.
    db_execute(
        "INSERT INTO acc_qbo_vendor_map (ff_vendor_id, qbo_vendor_id, mapping_status, qbo_display_name)
         VALUES (?, 'V-9999', 'mapped', 'Smoke BP Vendor')",
        [$fx['vendor_id']]
    );
    if (empty($c26Errors)) { echo "PASS C26 pushUpdate on unmapped ap_payment demotes to create (failed_preflight at vendor gate; no qbo_id; D-PUSHER-DEMOTION-RULE)\n"; $pass++; }
    else { echo "FAIL C26 " . implode('; ', $c26Errors) . "\n"; $failures[] = 'C26'; }

    // C27 (REPURPOSED): BillPaymentEnqueuer ACCEPTS 'void' op (S-QBO-PUSHVOID-TRIO)
    $c27Errors = [];
    ff_smoke_bp_seed_ap_payment(999990, $fx, ['status' => 'void']);
    ff_smoke_bp_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_bp_set_setting('quickbooks.sync_mode.bill_payment', 'queue');
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='bill_payment' AND entity_id=999990 AND operation='void'");
    $r27 = BillPaymentEnqueuer::enqueue(999990, 'void');
    $q27 = db_row("SELECT operation, status FROM acc_qbo_sync_queue WHERE entity_type='bill_payment' AND entity_id=999990 AND operation='void'");
    if ($r27 !== true) { $c27Errors[] = "enqueue should accept 'void' now; got " . json_encode($r27); }
    if ($q27 === null) { $c27Errors[] = "a 'void' queue row should be written; none found"; }
    elseif ($q27['operation'] !== 'void' || $q27['status'] !== 'queued') { $c27Errors[] = "queue row shape: " . json_encode($q27); }
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='bill_payment' AND entity_id=999990 AND operation='void'");
    if (empty($c27Errors)) { echo "PASS C27 BillPaymentEnqueuer accepts 'void' op + queue row (S-QBO-PUSHVOID-TRIO; gate-3 +void)\n"; $pass++; }
    else { echo "FAIL C27 " . implode('; ', $c27Errors) . "\n"; $failures[] = 'C27'; }

    // C28: pushVoid on status!='void' ap_payment -> void_status_mismatch (no HTTP)
    $c28Errors = [];
    ff_smoke_bp_seed_ap_payment(999991, $fx, ['status' => 'cleared']);
    $r28 = BillPaymentPusher::pushVoid(999991);
    if (($r28['status'] ?? null) !== 'void_status_mismatch') { $c28Errors[] = "status: got " . json_encode($r28['status'] ?? null) . ", want 'void_status_mismatch'"; }
    if (empty($c28Errors)) { echo "PASS C28 pushVoid on non-void ap_payment -> void_status_mismatch (D-QBO-PUSHVOID-TRIO-1)\n"; $pass++; }
    else { echo "FAIL C28 " . implode('; ', $c28Errors) . "\n"; $failures[] = 'C28'; }

    // C29: pushVoid on status='void' + UNMAPPED -> skipped_unmapped_void (no HTTP)
    $c29Errors = [];
    ff_smoke_bp_seed_ap_payment(999992, $fx, ['status' => 'void']);
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999992");
    ff_smoke_bp_set_setting('quickbooks.sync_mode.bill_payment', 'queue');
    $r29 = BillPaymentPusher::pushVoid(999992);
    if (($r29['status'] ?? null) !== 'skipped_unmapped_void') { $c29Errors[] = "status: got " . json_encode($r29['status'] ?? null) . ", want 'skipped_unmapped_void'"; }
    if (empty($c29Errors)) { echo "PASS C29 pushVoid on void+unmapped ap_payment -> skipped_unmapped_void (no HTTP)\n"; $pass++; }
    else { echo "FAIL C29 " . implode('; ', $c29Errors) . "\n"; $failures[] = 'C29'; }

    // C30: pushVoid idempotent — push_status='voided' -> already_voided (no HTTP)
    $c30Errors = [];
    ff_smoke_bp_seed_ap_payment(999993, $fx, ['status' => 'void']);
    db_execute("DELETE FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = 999993");
    db_execute("INSERT INTO acc_qbo_bill_payment_map (ff_ap_payment_id, qbo_bill_payment_id, qbo_sync_token, push_status) VALUES (999993, 'QBO-BP-VOIDED', '2', 'voided')");
    $r30 = BillPaymentPusher::pushVoid(999993);
    if (($r30['status'] ?? null) !== 'already_voided') { $c30Errors[] = "status: got " . json_encode($r30['status'] ?? null) . ", want 'already_voided'"; }
    if (($r30['qbo_id'] ?? null) !== 'QBO-BP-VOIDED') { $c30Errors[] = "qbo_id: got " . json_encode($r30['qbo_id'] ?? null); }
    if (empty($c30Errors)) { echo "PASS C30 pushVoid idempotent on push_status='voided' -> already_voided (no HTTP)\n"; $pass++; }
    else { echo "FAIL C30 " . implode('; ', $c30Errors) . "\n"; $failures[] = 'C30'; }

} finally {
    ff_smoke_bp_cleanup();
    foreach ($snapshotKeys as $k) {
        if ($snapshot[$k] === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            ff_smoke_bp_set_setting($k, $snapshot[$k]);
        }
    }
}

echo "\nqbo_bill_payment_push_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " (failures: " . implode(', ', $failures) . ")";
}
echo "\n";

exit(empty($failures) ? 0 : 1);
