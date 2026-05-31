<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_payment_push.php
 *
 * Smoke test for S-QBO-14 Payment Push (Phase QBO-6 / 2 of 3).
 *
 * Mirrors _smoke_qbo_bill_push.php structure with payment-specific
 * adjustments: status='cleared' (not 'approved'); CustomerRef (not
 * VendorRef); LinkedTxn (not AccountBasedExpenseLineDetail);
 * DepositToAccountRef from critical_category='undeposited_funds';
 * bidirectional dedup gate (origin='ff_native' only) at both Enqueuer
 * gate 0 AND Pusher pushImpl step 5 (defense-in-depth per D-QBO-14-1).
 *
 * Sub-checks (C1-C23, extended by S-QBO-PAYMENT-UPDATE):
 *  C1: class surfaces — PaymentPusher + PaymentEnqueuer + RESULT_BASE
 *      + key methods
 *  C2: acc_qbo_payment_map schema verified (shipped S-QBO-13 — asserts
 *      the columns S-QBO-14 needs are present, no migration this session)
 *  C3: buildQboPayload happy-path multi_currency='1' — CustomerRef +
 *      TotalAmt + TxnDate + CurrencyRef='CAD' + ExchangeRate='1.0' +
 *      DepositToAccountRef + Line[].LinkedTxn[].TxnId/TxnType + PaymentRefNum
 *  C4: buildQboPayload multi_currency='0' → CurrencyRef + ExchangeRate
 *      absent (D-QBO-FIXPACK-12 gate)
 *  C5: buildQboPayload throws on missing customer mapping
 *  C6: buildQboPayload throws on missing linked-invoice mapping
 *  C7: buildQboPayload throws on missing UndepositedFunds account
 *  C8: buildQboPayload PaymentRefNum from payment.reference_number
 *      (and absent when reference_number is empty)
 *  C9: pushImpl skipped_by_mode (sync_mode='disabled') — map row +
 *      non-HTTP sync_log written
 * C10: pushImpl skipped_non_ff_origin — payment.origin='qbo_payments_
 *      webhook' → skip + no push (D-QBO-14-1 invariant; closes
 *      D-QBO-13-1/2 loop at the dispatch layer)
 * C11: pushImpl status='pending' → failed_preflight (D-QBO-14-2)
 * C12: pushImpl already_mapped → outcome='created'
 * C13: pushImpl missing FF payment → ff_not_found
 * C14: pushImpl pulled_from_qbo defensive skip — even if origin somehow
 *      gets set to ff_native, a map row with push_status='pulled_from_qbo'
 *      still skips (defense-in-depth)
 * C15: PaymentEnqueuer gate-0 rejects missing payment
 * C16: PaymentEnqueuer gate-0 rejects payment.origin='qbo_payments_webhook'
 *      (CRITICAL — closes D-QBO-13-1/2 loop at the enqueue layer too)
 * C17: PaymentEnqueuer gate-0 rejects payment.status='pending'
 * C18: REPURPOSED (S-QBO-PAYMENT-UPDATE) — PaymentEnqueuer gate-3 ACCEPTS
 *      'update' op + inserts queue row (D-QBO-14-5 stub closed)
 * C19: PaymentEnqueuer happy path queue insert (status='cleared' +
 *      origin='ff_native' + sync_enabled='1')
 * C20: REPURPOSED (S-QBO-PAYMENT-UPDATE) — pushUpdate delegates to pushImpl
 *      (no longer a stub); sync_mode='disabled' proves the delegation
 *      (skipped_by_mode return rather than unsupported_in_session)
 * C21: NEW (S-QBO-PAYMENT-UPDATE) — pushUpdate on an UNMAPPED payment
 *      demotes to create → runs create pipeline → fails at customer-
 *      mapping preflight gate (BEFORE HTTP); no qbo_payment_id persisted.
 *      Proves D-PUSHER-DEMOTION-RULE active for payment.
 * C22: NEW (S-QBO-PAYMENT-UPDATE) — PaymentEnqueuer still REJECTS 'void'
 *      (allowlist = create+update only; pushVoid is a separate F7 slice)
 * C23: NEW (S-QBO-PAYMENT-UPDATE) — pushUpdate of a webhook-originated
 *      payment (origin='qbo_payments_webhook') still returns
 *      skipped_non_ff_origin. CRITICAL — proves D-QBO-14-1 dedup guard
 *      covers the update verb too (steps 5+6 run BEFORE the operation
 *      branch). Bidirectional dedup invariant survives update path.
 *
 * Fixtures use sentinel IDs in 999990-999999 range, cleaned up in finally.
 *
 * @session  S-QBO-14 (original) + S-QBO-PAYMENT-UPDATE (extension)
 * @decision D-QBO-14-1..7, D-QBO-PAYMENT-UPDATE-1, D-PUSHER-DEMOTION-RULE
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\PaymentPusher;
use FleetForge\QboPushers\PaymentEnqueuer;
use FleetForge\Exceptions\QuickBooksException;

$pass = 0;
$total = 26;
$failures = [];

// ──────────────────────────────────────────────────────────────────────────
// Fixture helpers
// ──────────────────────────────────────────────────────────────────────────

function ff_smoke_pp_set_setting(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

function ff_smoke_pp_get_setting(string $key): ?string
{
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $row['value'] ?? null;
}

function ff_smoke_pp_cleanup(): void
{
    // FK order: child rows first.
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type = 'payment' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM payment_allocations WHERE payment_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM payments WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM invoices WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_customer_map WHERE ff_customer_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM customers WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_accounts WHERE id BETWEEN 999990 AND 999999");
}

/**
 * Seed all FK dependencies for a payment-push test:
 *   - customer + acc_qbo_customer_map (mapped)
 *   - invoice + acc_qbo_invoice_map (mapped, qbo_invoice_id='I-7777')
 *   - AR account + acc_qbo_account_map tagged critical_category='ar_clearing'
 *   - UF account + acc_qbo_account_map tagged critical_category=
 *     'undeposited_funds' (CRITICAL — D-QBO-14-4 requires this mapping)
 *
 * K-22 trap notes:
 *   - customers needs only `company_name` (others have defaults)
 *   - invoices needs invoice_number/customer_id/dates AS WELL AS
 *     billing_period_start/billing_period_end/billing_period_days/
 *     billing_type='full_month' (S-QBO-13 K-22 catch)
 */
function ff_smoke_pp_seed_fixtures(): array
{
    db_execute(
        "INSERT INTO customers (id, company_name, currency, outstanding_balance)
         VALUES (999990, 'Smoke PP Customer Inc.', 'CAD', '500.00')"
    );
    db_execute(
        "INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, mapping_status, qbo_display_name)
         VALUES (999990, 'C-9999', 'mapped', 'Smoke PP Customer Inc.')"
    );
    db_execute(
        "INSERT INTO invoices (id, invoice_number, customer_id, invoice_date, due_date, status,
                                billing_period_start, billing_period_end, billing_period_days, billing_type,
                                subtotal, tax_total, total_amount, amount_paid, balance_due, currency)
         VALUES (999990, 'SMOKE-PP-INV-999990', 999990, '2026-04-10', '2026-05-10', 'sent',
                 '2026-04-01', '2026-04-30', 30, 'full_month',
                 '500.00', '0.00', '500.00', '0.00', '500.00', 'CAD')"
    );
    db_execute(
        "INSERT INTO acc_qbo_invoice_map (ff_invoice_id, qbo_invoice_id, qbo_sync_token, push_status, pushed_at)
         VALUES (999990, 'I-7777', '0', 'pushed', NOW())"
    );
    // AR clearing account (1030) — required by AccountValidator gate.
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999990, '1030-SMOKE', 'Smoke AR Clearing', 'asset', 'current_asset', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status, is_critical, critical_category)
         VALUES (999990, 'A-AR-7777', 'mapped', 1, 'ar_clearing')"
    );
    // UndepositedFunds account — D-QBO-14-4 requires this mapping for payment push.
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999991, '1015-SMOKE', 'Smoke Undeposited Funds', 'asset', 'current_asset', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status, is_critical, critical_category)
         VALUES (999991, 'A-UF-8888', 'mapped', 1, 'undeposited_funds')"
    );
    return [
        'customer_id'     => 999990,
        'qbo_customer_id' => 'C-9999',
        'invoice_id'      => 999990,
        'qbo_invoice_id'  => 'I-7777',
        'qbo_uf_id'       => 'A-UF-8888',
    ];
}

/**
 * Seed a payment with an allocation pointing at the seeded invoice.
 * FK order: payment row first, then payment_allocations.
 */
function ff_smoke_pp_seed_payment(int $paymentId, array $fx, array $overrides = []): void
{
    $status = $overrides['status'] ?? 'cleared';
    $origin = $overrides['origin'] ?? 'ff_native';
    $refNum = array_key_exists('reference_number', $overrides) ? $overrides['reference_number'] : 'PAY-REF-001';
    db_execute(
        "INSERT INTO payments (id, payment_number, customer_id, amount, currency, payment_method,
                                reference_number, payment_date, status, origin)
         VALUES (?, ?, ?, '500.00', 'CAD', 'e_transfer', ?, '2026-04-15', ?, ?)",
        [
            $paymentId,
            "PAY-SMOKE-{$paymentId}",
            $fx['customer_id'],
            $refNum,
            $status,
            $origin,
        ]
    );
    db_execute(
        "INSERT INTO payment_allocations (payment_id, invoice_id, amount, currency, allocation_type)
         VALUES (?, ?, '500.00', 'CAD', 'auto')",
        [$paymentId, $fx['invoice_id']]
    );
}

// ──────────────────────────────────────────────────────────────────────────
// Settings snapshot/restore so D-CPA-5 invariant survives smoke runs
// ──────────────────────────────────────────────────────────────────────────

$snapshotKeys = [
    'quickbooks.sync_enabled',
    'quickbooks.sync_mode.payment',
    'quickbooks.multi_currency_enabled',
    'quickbooks.tax_override_code_id',
    'quickbooks.connection_status',
    'quickbooks.realm_id',
];
$snapshot = [];
foreach ($snapshotKeys as $k) {
    $snapshot[$k] = ff_smoke_pp_get_setting($k);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-14 Payment Push Smoke\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_pp_cleanup();
    $fx = ff_smoke_pp_seed_fixtures();
    ff_smoke_pp_set_setting('quickbooks.tax_override_code_id', 'NON');
    ff_smoke_pp_set_setting('quickbooks.connection_status', 'connected');
    ff_smoke_pp_set_setting('quickbooks.sync_mode.payment', 'queue');

    // ── C1: Class surfaces ─────────────────────────────────────────
    $c1Errors = [];
    if (!class_exists(PaymentPusher::class)) {
        $c1Errors[] = 'PaymentPusher class missing';
    } else {
        foreach (['pushCreate', 'pushUpdate', 'buildQboPayload', 'buildPrivateNoteJson'] as $m) {
            if (!method_exists(PaymentPusher::class, $m)) {
                $c1Errors[] = "PaymentPusher::{$m} missing";
            }
        }
        $ref = new ReflectionClass(PaymentPusher::class);
        if (!$ref->hasConstant('RESULT_BASE')) {
            $c1Errors[] = 'PaymentPusher::RESULT_BASE const missing (§6.8 return-shape parity)';
        }
    }
    if (!class_exists(PaymentEnqueuer::class)) {
        $c1Errors[] = 'PaymentEnqueuer class missing';
    } elseif (!method_exists(PaymentEnqueuer::class, 'enqueue')) {
        $c1Errors[] = 'PaymentEnqueuer::enqueue missing';
    }
    if (empty($c1Errors)) {
        echo "PASS C1 class surfaces (PaymentPusher + PaymentEnqueuer + RESULT_BASE)\n";
        $pass++;
    } else {
        echo "FAIL C1 " . implode('; ', $c1Errors) . "\n";
        $failures[] = 'C1';
    }

    // ── C2: acc_qbo_payment_map schema verified (no migration this session) ─
    $c2Errors = [];
    $cols = db_select("SHOW COLUMNS FROM acc_qbo_payment_map");
    $colNames = array_column($cols, 'Field');
    $required = [
        'id', 'ff_payment_id', 'qbo_payment_id', 'qbo_sync_token',
        'qbo_total_amt', 'qbo_currency', 'qbo_txn_date',
        'qbo_linked_invoice_id', 'origin', 'webhook_event_id', 'realm_id',
        'push_status', 'push_error', 'pushed_at', 'pulled_at',
        'last_synced_at', 'created_at', 'updated_at',
    ];
    foreach ($required as $col) {
        if (!in_array($col, $colNames, true)) {
            $c2Errors[] = "missing col (S-QBO-13 schema): {$col}";
        }
    }
    // Verify push_status ENUM includes the states PaymentPusher writes.
    $pushStatusCol = null;
    foreach ($cols as $c) {
        if ($c['Field'] === 'push_status') { $pushStatusCol = (string) $c['Type']; break; }
    }
    foreach (['pending', 'pushed', 'failed', 'skipped_by_mode', 'failed_preflight', 'pulled_from_qbo'] as $enum) {
        if ($pushStatusCol === null || strpos($pushStatusCol, "'{$enum}'") === false) {
            $c2Errors[] = "push_status ENUM missing '{$enum}'";
        }
    }
    // Verify origin ENUM includes the states PaymentPusher / PaymentEnqueuer touch.
    $originCol = null;
    foreach ($cols as $c) {
        if ($c['Field'] === 'origin') { $originCol = (string) $c['Type']; break; }
    }
    foreach (['ff_native', 'qbo_payments_webhook', 'qbo_other'] as $enum) {
        if ($originCol === null || strpos($originCol, "'{$enum}'") === false) {
            $c2Errors[] = "origin ENUM missing '{$enum}'";
        }
    }
    if (empty($c2Errors)) {
        echo "PASS C2 acc_qbo_payment_map shape verified (S-QBO-13 schema reused; no new migration)\n";
        $pass++;
    } else {
        echo "FAIL C2 " . implode('; ', $c2Errors) . "\n";
        $failures[] = 'C2';
    }

    // ── C3: buildQboPayload happy-path multi_currency='1' ────────────
    ff_smoke_pp_set_setting('quickbooks.multi_currency_enabled', '1');
    ff_smoke_pp_seed_payment(999990, $fx);
    $payment = db_row("SELECT * FROM payments WHERE id = 999990");
    $c3Errors = [];
    try {
        $payload = PaymentPusher::buildQboPayload($payment);
        if (($payload['CustomerRef']['value'] ?? null) !== 'C-9999') {
            $c3Errors[] = "CustomerRef.value: got " . json_encode($payload['CustomerRef']['value'] ?? null);
        }
        if ((float) ($payload['TotalAmt'] ?? 0) !== 500.00) {
            $c3Errors[] = "TotalAmt: got " . json_encode($payload['TotalAmt'] ?? null);
        }
        if (($payload['TxnDate'] ?? null) !== '2026-04-15') {
            $c3Errors[] = "TxnDate: got " . json_encode($payload['TxnDate'] ?? null);
        }
        if (($payload['CurrencyRef']['value'] ?? null) !== 'CAD') {
            $c3Errors[] = "CurrencyRef.value: got " . json_encode($payload['CurrencyRef']['value'] ?? null);
        }
        if (($payload['ExchangeRate'] ?? null) !== '1.0') {
            $c3Errors[] = "ExchangeRate: got " . json_encode($payload['ExchangeRate'] ?? null);
        }
        if (($payload['DepositToAccountRef']['value'] ?? null) !== 'A-UF-8888') {
            $c3Errors[] = "DepositToAccountRef.value: got " . json_encode($payload['DepositToAccountRef']['value'] ?? null);
        }
        if (($payload['PaymentRefNum'] ?? null) !== 'PAY-REF-001') {
            $c3Errors[] = "PaymentRefNum: got " . json_encode($payload['PaymentRefNum'] ?? null);
        }
        if (!isset($payload['Line']) || !is_array($payload['Line']) || count($payload['Line']) !== 1) {
            $c3Errors[] = "Line array shape: got " . json_encode($payload['Line'] ?? null);
        } else {
            $line = $payload['Line'][0];
            if ((float) ($line['Amount'] ?? 0) !== 500.00) {
                $c3Errors[] = "Line[0].Amount: got " . json_encode($line['Amount'] ?? null);
            }
            $linkedTxn = $line['LinkedTxn'][0] ?? null;
            if (($linkedTxn['TxnId'] ?? null) !== 'I-7777') {
                $c3Errors[] = "Line[0].LinkedTxn[0].TxnId: got " . json_encode($linkedTxn['TxnId'] ?? null);
            }
            if (($linkedTxn['TxnType'] ?? null) !== 'Invoice') {
                $c3Errors[] = "Line[0].LinkedTxn[0].TxnType: got " . json_encode($linkedTxn['TxnType'] ?? null) . " (D-QBO-14-3 requires 'Invoice')";
            }
        }
    } catch (\Throwable $e) {
        $c3Errors[] = "unexpected throw: " . $e->getMessage();
    }
    if (empty($c3Errors)) {
        echo "PASS C3 buildQboPayload happy-path multi_currency='1' (CustomerRef + DepositToAccountRef + LinkedTxn + PaymentRefNum)\n";
        $pass++;
    } else {
        echo "FAIL C3 " . implode('; ', $c3Errors) . "\n";
        $failures[] = 'C3';
    }

    // ── C4: buildQboPayload multi_currency='0' — CurrencyRef + ExchangeRate absent ──
    ff_smoke_pp_set_setting('quickbooks.multi_currency_enabled', '0');
    $c4Errors = [];
    $payload4 = PaymentPusher::buildQboPayload($payment);
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

    // ── C5: buildQboPayload throws on missing customer mapping ───────────
    ff_smoke_pp_set_setting('quickbooks.multi_currency_enabled', '1');
    $c5Errors = [];
    try {
        $badPayment = $payment;
        $badPayment['customer_id'] = 999998;  // no mapping for this customer id
        PaymentPusher::buildQboPayload($badPayment);
        $c5Errors[] = "expected throw on missing customer mapping, none thrown";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), '999998') === false) {
            $c5Errors[] = "exception should mention customer id 999998; got: " . $e->getMessage();
        }
    } catch (\Throwable $e) {
        $c5Errors[] = "wrong exception type: " . get_class($e);
    }
    if (empty($c5Errors)) {
        echo "PASS C5 buildQboPayload throws QuickBooksException on missing customer mapping\n";
        $pass++;
    } else {
        echo "FAIL C5 " . implode('; ', $c5Errors) . "\n";
        $failures[] = 'C5';
    }

    // ── C6: buildQboPayload throws on missing linked-invoice mapping ─────
    $c6Errors = [];
    // Temporarily orphan the invoice mapping by deleting it
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999990");
    try {
        PaymentPusher::buildQboPayload($payment);
        $c6Errors[] = "expected throw on missing invoice mapping, none thrown";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), '999990') === false) {
            $c6Errors[] = "exception should mention invoice id 999990; got: " . $e->getMessage();
        }
    }
    // Restore
    db_execute(
        "INSERT INTO acc_qbo_invoice_map (ff_invoice_id, qbo_invoice_id, qbo_sync_token, push_status, pushed_at)
         VALUES (999990, 'I-7777', '0', 'pushed', NOW())"
    );
    if (empty($c6Errors)) {
        echo "PASS C6 buildQboPayload throws QuickBooksException on missing linked-invoice mapping (D-QBO-14-3)\n";
        $pass++;
    } else {
        echo "FAIL C6 " . implode('; ', $c6Errors) . "\n";
        $failures[] = 'C6';
    }

    // ── C7: buildQboPayload throws on missing UndepositedFunds account ───
    $c7Errors = [];
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999991");
    try {
        PaymentPusher::buildQboPayload($payment);
        $c7Errors[] = "expected throw on missing UF account, none thrown";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), 'undeposited_funds') === false) {
            $c7Errors[] = "exception should mention 'undeposited_funds'; got: " . $e->getMessage();
        }
    }
    // Restore
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status, is_critical, critical_category)
         VALUES (999991, 'A-UF-8888', 'mapped', 1, 'undeposited_funds')"
    );
    if (empty($c7Errors)) {
        echo "PASS C7 buildQboPayload throws QuickBooksException on missing UndepositedFunds account (D-QBO-14-4)\n";
        $pass++;
    } else {
        echo "FAIL C7 " . implode('; ', $c7Errors) . "\n";
        $failures[] = 'C7';
    }

    // ── C8: buildQboPayload PaymentRefNum from reference_number; absent when empty ──
    $c8Errors = [];
    // Test: when reference_number is empty/null, PaymentRefNum is omitted.
    db_execute("UPDATE payments SET reference_number = NULL WHERE id = 999990");
    $paymentC8 = db_row("SELECT * FROM payments WHERE id = 999990");
    $payloadC8 = PaymentPusher::buildQboPayload($paymentC8);
    if (array_key_exists('PaymentRefNum', $payloadC8)) {
        $c8Errors[] = "PaymentRefNum should be absent when payment.reference_number is NULL; got: " . json_encode($payloadC8['PaymentRefNum'] ?? null);
    }
    // Restore reference_number for downstream tests
    db_execute("UPDATE payments SET reference_number = 'PAY-REF-001' WHERE id = 999990");
    if (empty($c8Errors)) {
        echo "PASS C8 buildQboPayload PaymentRefNum from reference_number (omitted when empty per D-QBO-14-6)\n";
        $pass++;
    } else {
        echo "FAIL C8 " . implode('; ', $c8Errors) . "\n";
        $failures[] = 'C8';
    }

    // ── C9: pushImpl sync_mode='disabled' — skipped_by_mode ─────────────
    ff_smoke_pp_set_setting('quickbooks.sync_mode.payment', 'disabled');
    $c9Errors = [];
    $r9 = PaymentPusher::pushCreate(999990);
    if (($r9['status'] ?? null) !== 'skipped_by_mode') {
        $c9Errors[] = "status: got " . json_encode($r9['status'] ?? null);
    }
    if (($r9['outcome'] ?? null) !== 'skipped') {
        $c9Errors[] = "outcome: got " . json_encode($r9['outcome'] ?? null);
    }
    $mapRow = db_row("SELECT push_status FROM acc_qbo_payment_map WHERE ff_payment_id = 999990");
    if ($mapRow === null || $mapRow['push_status'] !== 'skipped_by_mode') {
        $c9Errors[] = "map row push_status: got " . json_encode($mapRow['push_status'] ?? null) . " (expected skipped_by_mode)";
    }
    $logRow = db_row("SELECT http_method, response_status, error_code FROM acc_qbo_sync_log WHERE entity_type = 'payment' AND entity_id = 999990 ORDER BY id DESC LIMIT 1");
    if ($logRow === null || $logRow['http_method'] !== 'SKIP' || $logRow['response_status'] !== null) {
        $c9Errors[] = "sync_log SKIP sentinel: got " . json_encode($logRow);
    }
    ff_smoke_pp_set_setting('quickbooks.sync_mode.payment', 'queue');
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type = 'payment' AND entity_id = 999990");
    if (empty($c9Errors)) {
        echo "PASS C9 pushImpl sync_mode='disabled' → skipped_by_mode + map row + non-HTTP sync_log\n";
        $pass++;
    } else {
        echo "FAIL C9 " . implode('; ', $c9Errors) . "\n";
        $failures[] = 'C9';
    }

    // ── C10: pushImpl skipped_non_ff_origin (D-QBO-14-1 invariant) ──────
    // Flip origin to qbo_payments_webhook and verify Pusher skips. Map
    // row is NOT written (mirrors BillPusher skipped_unmapped_void
    // D-QBO-18-4 pattern — avoids 'skipped_non_ff_origin' ENUM value
    // that would require a migration this session). sync_log captures
    // the diagnostic via error_code (no ENUM constraint there).
    db_execute("UPDATE payments SET origin = 'qbo_payments_webhook' WHERE id = 999990");
    $c10Errors = [];
    $r10 = PaymentPusher::pushCreate(999990);
    if (($r10['status'] ?? null) !== 'skipped_non_ff_origin') {
        $c10Errors[] = "status: got " . json_encode($r10['status'] ?? null) . ", want 'skipped_non_ff_origin'";
    }
    if (($r10['outcome'] ?? null) !== 'skipped') {
        $c10Errors[] = "outcome: got " . json_encode($r10['outcome'] ?? null);
    }
    if (strpos((string) ($r10['error'] ?? ''), 'qbo_payments_webhook') === false) {
        $c10Errors[] = "error should mention 'qbo_payments_webhook'; got: " . json_encode($r10['error'] ?? null);
    }
    // NO map row should be written for this skip class.
    $mapRow10 = db_row("SELECT id FROM acc_qbo_payment_map WHERE ff_payment_id = 999990");
    if ($mapRow10 !== null) {
        $c10Errors[] = "map row should NOT be written for skipped_non_ff_origin; found id=" . $mapRow10['id'];
    }
    // sync_log should have the SKIP sentinel + error_code='skipped_non_ff_origin'.
    $logRow10 = db_row("SELECT http_method, error_code FROM acc_qbo_sync_log WHERE entity_type = 'payment' AND entity_id = 999990 ORDER BY id DESC LIMIT 1");
    if ($logRow10 === null || $logRow10['http_method'] !== 'SKIP' || $logRow10['error_code'] !== 'skipped_non_ff_origin') {
        $c10Errors[] = "sync_log skipped_non_ff_origin: got " . json_encode($logRow10);
    }
    db_execute("UPDATE payments SET origin = 'ff_native' WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type = 'payment' AND entity_id = 999990");
    if (empty($c10Errors)) {
        echo "PASS C10 pushImpl skipped_non_ff_origin — webhook-originated payment not re-pushed (D-QBO-14-1; closes D-QBO-13-1/2 loop)\n";
        $pass++;
    } else {
        echo "FAIL C10 " . implode('; ', $c10Errors) . "\n";
        $failures[] = 'C10';
    }

    // ── C11: pushImpl status='pending' → failed_preflight (D-QBO-14-2) ──
    db_execute("UPDATE payments SET status = 'pending' WHERE id = 999990");
    $c11Errors = [];
    $r11 = PaymentPusher::pushCreate(999990);
    if (($r11['status'] ?? null) !== 'failed_preflight') {
        $c11Errors[] = "status: got " . json_encode($r11['status'] ?? null);
    }
    if (($r11['outcome'] ?? null) !== 'failed') {
        $c11Errors[] = "outcome: got " . json_encode($r11['outcome'] ?? null);
    }
    if (strpos((string) ($r11['error'] ?? ''), 'pending') === false) {
        $c11Errors[] = "error should mention 'pending'; got: " . json_encode($r11['error'] ?? null);
    }
    db_execute("UPDATE payments SET status = 'cleared' WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id = 999990");
    if (empty($c11Errors)) {
        echo "PASS C11 pushImpl status='pending' → failed_preflight (D-QBO-14-2 eligibility gate)\n";
        $pass++;
    } else {
        echo "FAIL C11 " . implode('; ', $c11Errors) . "\n";
        $failures[] = 'C11';
    }

    // ── C12: pushImpl already_mapped → 'created' outcome ────────────────
    db_execute(
        "INSERT INTO acc_qbo_payment_map (ff_payment_id, qbo_payment_id, qbo_sync_token, qbo_currency, origin, push_status, pushed_at)
         VALUES (999990, 'P-5555', '2', 'CAD', 'ff_native', 'pushed', NOW())"
    );
    $c12Errors = [];
    $r12 = PaymentPusher::pushCreate(999990);
    if (($r12['status'] ?? null) !== 'already_mapped') {
        $c12Errors[] = "status: got " . json_encode($r12['status'] ?? null);
    }
    if (($r12['outcome'] ?? null) !== 'created') {
        $c12Errors[] = "outcome (replay no-op == created): got " . json_encode($r12['outcome'] ?? null);
    }
    if (($r12['qbo_id'] ?? null) !== 'P-5555') {
        $c12Errors[] = "qbo_id: got " . json_encode($r12['qbo_id'] ?? null);
    }
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id = 999990");
    if (empty($c12Errors)) {
        echo "PASS C12 pushImpl already_mapped → status='already_mapped', outcome='created' (idempotency)\n";
        $pass++;
    } else {
        echo "FAIL C12 " . implode('; ', $c12Errors) . "\n";
        $failures[] = 'C12';
    }

    // ── C13: pushImpl missing FF payment → ff_not_found ─────────────────
    $c13Errors = [];
    $r13 = PaymentPusher::pushCreate(999998);  // doesn't exist
    if (($r13['status'] ?? null) !== 'ff_not_found') {
        $c13Errors[] = "status: got " . json_encode($r13['status'] ?? null);
    }
    if (($r13['outcome'] ?? null) !== 'failed') {
        $c13Errors[] = "outcome: got " . json_encode($r13['outcome'] ?? null);
    }
    if (empty($c13Errors)) {
        echo "PASS C13 pushImpl missing FF payment → ff_not_found\n";
        $pass++;
    } else {
        echo "FAIL C13 " . implode('; ', $c13Errors) . "\n";
        $failures[] = 'C13';
    }

    // ── C14: pushImpl pulled_from_qbo defensive skip ────────────────────
    // Even if origin is ff_native, a map row with push_status=
    // 'pulled_from_qbo' should cause skip (defense-in-depth — catches
    // origin column drift / migration edge cases).
    db_execute(
        "INSERT INTO acc_qbo_payment_map (ff_payment_id, qbo_payment_id, origin, push_status, pulled_at)
         VALUES (999990, 'P-PULLED', 'qbo_payments_webhook', 'pulled_from_qbo', NOW())"
    );
    $c14Errors = [];
    $r14 = PaymentPusher::pushCreate(999990);
    if (($r14['status'] ?? null) !== 'skipped_non_ff_origin') {
        $c14Errors[] = "status: got " . json_encode($r14['status'] ?? null) . ", want 'skipped_non_ff_origin' (defensive)";
    }
    if (($r14['qbo_id'] ?? null) !== 'P-PULLED') {
        $c14Errors[] = "qbo_id should be returned from existing map row: got " . json_encode($r14['qbo_id'] ?? null);
    }
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id = 999990");
    if (empty($c14Errors)) {
        echo "PASS C14 pushImpl defensive skip when map row push_status='pulled_from_qbo' (defense-in-depth)\n";
        $pass++;
    } else {
        echo "FAIL C14 " . implode('; ', $c14Errors) . "\n";
        $failures[] = 'C14';
    }
    // Reset origin to ff_native (C14 didn't change it but cleanup map row above)

    // ── C15: PaymentEnqueuer gate-0 rejects missing payment ─────────────
    ff_smoke_pp_set_setting('quickbooks.sync_enabled', '1');
    $c15Errors = [];
    $r15 = PaymentEnqueuer::enqueue(999998, 'create');
    if ($r15 !== false) {
        $c15Errors[] = "enqueue should return false for missing payment; got " . json_encode($r15);
    }
    $queued15 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id = 999998");
    if ($queued15 !== null) {
        $c15Errors[] = "no queue row should be written for gate-0 reject; found id=" . $queued15['id'];
    }
    if (empty($c15Errors)) {
        echo "PASS C15 PaymentEnqueuer gate-0 rejects missing payment\n";
        $pass++;
    } else {
        echo "FAIL C15 " . implode('; ', $c15Errors) . "\n";
        $failures[] = 'C15';
    }

    // ── C16: PaymentEnqueuer gate-0 rejects origin='qbo_payments_webhook' ──
    // CRITICAL — closes D-QBO-13-1/2 invariant at the enqueue layer.
    db_execute("UPDATE payments SET origin = 'qbo_payments_webhook' WHERE id = 999990");
    $c16Errors = [];
    $r16 = PaymentEnqueuer::enqueue(999990, 'create');
    if ($r16 !== false) {
        $c16Errors[] = "enqueue should return false for origin='qbo_payments_webhook'; got " . json_encode($r16);
    }
    $queued16 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id = 999990");
    if ($queued16 !== null) {
        $c16Errors[] = "no queue row should be written; found id=" . $queued16['id'];
    }
    db_execute("UPDATE payments SET origin = 'ff_native' WHERE id = 999990");
    if (empty($c16Errors)) {
        echo "PASS C16 PaymentEnqueuer gate-0 rejects origin='qbo_payments_webhook' (CRITICAL D-QBO-14-1 invariant; closes D-QBO-13-1/2 loop)\n";
        $pass++;
    } else {
        echo "FAIL C16 " . implode('; ', $c16Errors) . "\n";
        $failures[] = 'C16';
    }

    // ── C17: PaymentEnqueuer gate-0 rejects status='pending' ────────────
    db_execute("UPDATE payments SET status = 'pending' WHERE id = 999990");
    $c17Errors = [];
    $r17 = PaymentEnqueuer::enqueue(999990, 'create');
    if ($r17 !== false) {
        $c17Errors[] = "enqueue should return false for status='pending'; got " . json_encode($r17);
    }
    $queued17 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id = 999990");
    if ($queued17 !== null) {
        $c17Errors[] = "no queue row should be written; found id=" . $queued17['id'];
    }
    db_execute("UPDATE payments SET status = 'cleared' WHERE id = 999990");
    if (empty($c17Errors)) {
        echo "PASS C17 PaymentEnqueuer gate-0 rejects payment.status='pending' (D-QBO-14-2)\n";
        $pass++;
    } else {
        echo "FAIL C17 " . implode('; ', $c17Errors) . "\n";
        $failures[] = 'C17';
    }

    // ── C18: PaymentEnqueuer gate-3 ACCEPTS 'update' op (S-QBO-PAYMENT-UPDATE) ─
    // gate-3 allowlist widened ['create'] → ['create','update'] in
    // S-QBO-PAYMENT-UPDATE (D-QBO-14-5 stub closed). 999990 is cleared +
    // ff_native + sync_enabled='1' + sync_mode.payment='queue' →
    // enqueue('update') now succeeds + writes a queue row with
    // operation='update'.
    $c18Errors = [];
    ff_smoke_pp_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_pp_set_setting('quickbooks.sync_mode.payment', 'queue');
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id = 999990");
    $r18 = PaymentEnqueuer::enqueue(999990, 'update');
    $queued18 = db_row("SELECT operation, status FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id = 999990 AND operation = 'update'");
    if ($r18 !== true) {
        $c18Errors[] = "enqueue should accept 'update' now; got " . json_encode($r18);
    }
    if ($queued18 === null) {
        $c18Errors[] = "an 'update' queue row should be written; none found";
    } elseif ($queued18['operation'] !== 'update' || $queued18['status'] !== 'queued') {
        $c18Errors[] = "queue row shape: got " . json_encode($queued18);
    }
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id = 999990");
    if (empty($c18Errors)) {
        echo "PASS C18 PaymentEnqueuer gate-3 accepts 'update' op + inserts queue row (S-QBO-PAYMENT-UPDATE; D-QBO-14-5 closed)\n";
        $pass++;
    } else {
        echo "FAIL C18 " . implode('; ', $c18Errors) . "\n";
        $failures[] = 'C18';
    }

    // ── C19: PaymentEnqueuer happy path ─────────────────────────────────
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id = 999990");
    $c19Errors = [];
    $r19 = PaymentEnqueuer::enqueue(999990, 'create');
    if ($r19 !== true) {
        $c19Errors[] = "enqueue should return true; got " . json_encode($r19);
    }
    $queued19 = db_row("SELECT operation, status FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id = 999990");
    if ($queued19 === null) {
        $c19Errors[] = "queue row should be written; none found";
    } elseif ($queued19['operation'] !== 'create' || $queued19['status'] !== 'queued') {
        $c19Errors[] = "queue row shape: got " . json_encode($queued19);
    }
    if (empty($c19Errors)) {
        echo "PASS C19 PaymentEnqueuer happy-path writes queue row (entity_type='payment', op='create', status='queued')\n";
        $pass++;
    } else {
        echo "FAIL C19 " . implode('; ', $c19Errors) . "\n";
        $failures[] = 'C19';
    }

    // ── C20: pushUpdate delegates to pushImpl (S-QBO-PAYMENT-UPDATE) ────
    // The old stub returned 'unsupported_in_session' regardless of state.
    // pushUpdate now routes through pushImpl; with sync_mode='disabled' it
    // returns skipped_by_mode at step 1 — proving delegation (definitively
    // NOT the stub). Uses a fresh sentinel (999991) to avoid the existing
    // 999990 fixture (which would otherwise reach the HTTP call boundary
    // when no map row exists and the demotion-to-create path is taken).
    // The prior sync_mode is restored after the assertion.
    $c20Errors = [];
    ff_smoke_pp_seed_payment(999991, $fx);
    $prevModeC20 = ff_smoke_pp_get_setting('quickbooks.sync_mode.payment');
    ff_smoke_pp_set_setting('quickbooks.sync_mode.payment', 'disabled');
    $r20 = PaymentPusher::pushUpdate(999991);
    if (($r20['status'] ?? null) === 'unsupported_in_session') {
        $c20Errors[] = "pushUpdate still a stub — returned unsupported_in_session";
    }
    if (($r20['status'] ?? null) !== 'skipped_by_mode') {
        $c20Errors[] = "expected skipped_by_mode (delegation proof), got " . json_encode($r20['status'] ?? null);
    }
    if (($r20['outcome'] ?? null) !== 'skipped') {
        $c20Errors[] = "outcome: got " . json_encode($r20['outcome'] ?? null);
    }
    ff_smoke_pp_set_setting('quickbooks.sync_mode.payment', $prevModeC20 ?? 'queue');
    if (empty($c20Errors)) {
        echo "PASS C20 pushUpdate delegates to pushImpl — no longer a stub (S-QBO-PAYMENT-UPDATE; D-QBO-14-5 closed)\n";
        $pass++;
    } else {
        echo "FAIL C20 " . implode('; ', $c20Errors) . "\n";
        $failures[] = 'C20';
    }

    // ── C21: pushUpdate on UNMAPPED payment demotes to create (S-QBO-PAYMENT-UPDATE) ─
    // No map row → pushImpl step 7b flips operation to 'create' → runs the
    // create pipeline. With the customer mapping removed, preflight gate 1
    // (customer mapping) fails → failed_preflight (returns BEFORE the HTTP
    // boundary, so no real updateEntity/createEntity call is attempted).
    // Proves the demotion ran the create path rather than attempting an
    // UPDATE on an entity QBO doesn't know about (which would 404).
    // Customer mapping is restored afterward so later checks are unaffected.
    $c21Errors = [];
    ff_smoke_pp_seed_payment(999992, $fx);
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id = 999992");
    db_execute("DELETE FROM acc_qbo_customer_map WHERE ff_customer_id = 999990");
    ff_smoke_pp_set_setting('quickbooks.sync_mode.payment', 'queue');
    $r21 = PaymentPusher::pushUpdate(999992);
    $map21 = db_row("SELECT qbo_payment_id, push_status FROM acc_qbo_payment_map WHERE ff_payment_id = 999992");
    if (($r21['status'] ?? null) === 'unsupported_in_session') {
        $c21Errors[] = "pushUpdate still a stub";
    }
    if (($r21['status'] ?? null) !== 'failed_preflight') {
        $c21Errors[] = "expected failed_preflight (demoted-create at customer gate), got " . json_encode($r21['status'] ?? null);
    }
    if (!empty($map21['qbo_payment_id'])) {
        $c21Errors[] = "no qbo_payment_id should be persisted on a failed demoted-create; got " . json_encode($map21['qbo_payment_id']);
    }
    // Restore customer mapping for any downstream assertions.
    db_execute(
        "INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, mapping_status, qbo_display_name)
         VALUES (999990, 'C-9999', 'mapped', 'Smoke PP Customer Inc.')"
    );
    if (empty($c21Errors)) {
        echo "PASS C21 pushUpdate on unmapped payment demotes to create (failed_preflight at customer gate; no qbo_id; D-PUSHER-DEMOTION-RULE)\n";
        $pass++;
    } else {
        echo "FAIL C21 " . implode('; ', $c21Errors) . "\n";
        $failures[] = 'C21';
    }

    // ── C22: PaymentEnqueuer still REJECTS 'void' (S-QBO-PAYMENT-UPDATE) ──
    // gate-3 allowlist is now ['create','update'] — 'void' remains
    // unsupported (separate F7 pushVoid trio slice).
    $c22Errors = [];
    ff_smoke_pp_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_pp_set_setting('quickbooks.sync_mode.payment', 'queue');
    $r22 = PaymentEnqueuer::enqueue(999990, 'void');
    $queued22 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type = 'payment' AND entity_id = 999990 AND operation = 'void'");
    if ($r22 !== false) {
        $c22Errors[] = "expected false (void not in allowlist), got " . json_encode($r22);
    }
    if ($queued22 !== null) {
        $c22Errors[] = "no 'void' queue row should be inserted; found id=" . $queued22['id'];
    }
    if (empty($c22Errors)) {
        echo "PASS C22 PaymentEnqueuer rejects 'void' op (gate-3 allowlist = create+update only; pushVoid → F7)\n";
        $pass++;
    } else {
        echo "FAIL C22 " . implode('; ', $c22Errors) . "\n";
        $failures[] = 'C22';
    }

    // ── C23: pushUpdate of webhook-originated payment → skipped_non_ff_origin ──
    // CRITICAL — proves the D-QBO-14-1 bidirectional dedup invariant covers
    // the UPDATE verb too, not just CREATE. pushImpl steps 5+6 (origin and
    // pulled_from_qbo gates) run BEFORE the operation branch, so an update
    // of a webhook-originated payment is rejected exactly like a create
    // would be. Uses sentinel 999993 with origin='qbo_payments_webhook'
    // and NO map row — ensures we're hitting the origin gate, not the
    // pulled_from_qbo defensive gate (those are distinct code paths).
    $c23Errors = [];
    ff_smoke_pp_seed_payment(999993, $fx, ['origin' => 'qbo_payments_webhook']);
    $r23 = PaymentPusher::pushUpdate(999993);
    if (($r23['status'] ?? null) !== 'skipped_non_ff_origin') {
        $c23Errors[] = "status: got " . json_encode($r23['status'] ?? null) . ", want 'skipped_non_ff_origin'";
    }
    if (($r23['outcome'] ?? null) !== 'skipped') {
        $c23Errors[] = "outcome: got " . json_encode($r23['outcome'] ?? null);
    }
    if (strpos((string) ($r23['error'] ?? ''), 'qbo_payments_webhook') === false) {
        $c23Errors[] = "error should mention 'qbo_payments_webhook'; got: " . json_encode($r23['error'] ?? null);
    }
    // No map row should be written for skipped_non_ff_origin (mirrors C10).
    $map23 = db_row("SELECT id FROM acc_qbo_payment_map WHERE ff_payment_id = 999993");
    if ($map23 !== null) {
        $c23Errors[] = "no map row should be written for skipped_non_ff_origin; found id=" . $map23['id'];
    }
    // sync_log SKIP sentinel with error_code='skipped_non_ff_origin'.
    $log23 = db_row("SELECT http_method, error_code FROM acc_qbo_sync_log WHERE entity_type = 'payment' AND entity_id = 999993 AND operation = 'update' ORDER BY id DESC LIMIT 1");
    if ($log23 === null || $log23['http_method'] !== 'SKIP' || $log23['error_code'] !== 'skipped_non_ff_origin') {
        $c23Errors[] = "sync_log SKIP sentinel (operation=update): got " . json_encode($log23);
    }
    if (empty($c23Errors)) {
        echo "PASS C23 pushUpdate of webhook-originated payment → skipped_non_ff_origin (CRITICAL — D-QBO-14-1 covers update verb too; smoke for S-QBO-PAYMENT-UPDATE risk surface)\n";
        $pass++;
    } else {
        echo "FAIL C23 " . implode('; ', $c23Errors) . "\n";
        $failures[] = 'C23';
    }
} finally {
    ff_smoke_pp_cleanup();
    foreach ($snapshotKeys as $k) {
        if ($snapshot[$k] === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            ff_smoke_pp_set_setting($k, $snapshot[$k]);
        }
    }
}

echo "\nqbo_payment_push_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " (failures: " . implode(', ', $failures) . ")";
}
echo "\n";

exit(empty($failures) ? 0 : 1);
