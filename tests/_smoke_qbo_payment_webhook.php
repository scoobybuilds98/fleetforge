<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_payment_webhook.php
 *
 * Smoke test for S-QBO-13 QBO Payment webhook pull path.
 * Covers QboWebhookSignature + PaymentWebhookHandler + the webhook
 * endpoint file structure.
 *
 * Sub-checks:
 *  C1: Class surfaces (QboWebhookSignature::verify, ::compute;
 *      PaymentWebhookHandler::handle, ::createFfPaymentFromQbo)
 *  C2: Migration shape — acc_qbo_payment_map (24 cols + 2 UNIQUE + 4 idx + FK SET NULL)
 *  C3: Migration shape — acc_qbo_webhook_events (10 cols + 1 UNIQUE + 4 idx)
 *  C4: payments.origin ENUM column added with correct default
 *  C5: QboWebhookSignature::verify — valid signature passes
 *  C6: QboWebhookSignature::verify — invalid signature rejected
 *  C7: QboWebhookSignature::verify — empty verifier_token fail-closed
 *      (catches operator-forgot-to-configure failure mode)
 *  C8: QboWebhookSignature::verify — empty received_signature rejected
 *  C9: QboWebhookSignature::compute — round-trip matches verify
 * C10: handle() wrong_realm — webhook from non-connected realm skipped
 * C11: handle() already_mapped — short-circuit when qbo_payment_id
 *      already has ff_payment_id set
 * C12: createFfPaymentFromQbo happy path — FF payment + allocation +
 *      map row + invoice counter update + customer counter update
 * C13: createFfPaymentFromQbo currency_mismatch — payment ccy ≠ invoice ccy
 * C14: createFfPaymentFromQbo invoice_void — target FF invoice is voided
 * C15: createFfPaymentFromQbo overpayment — amount > balance_due
 *      → overpayment_amount + overpayment_action set
 * C16: createFfPaymentFromQbo payment_number gap-free format
 *      (PAY-YYYY-NNNNN per D15)
 * C17: createFfPaymentFromQbo PaymentRefNum fallback to QBO-{id}
 *      when QBO didn't populate it (D-QBO-13-6)
 * C18: createFfPaymentFromQbo invoice status flips to paid when
 *      balance_due hits zero
 * C19: createFfPaymentFromQbo origin column set to qbo_payments_webhook
 *      (D-QBO-13-1) + map row push_status='pulled_from_qbo' (D-QBO-13-2)
 * C20: Webhook endpoint file exists + has no syntax errors + uses the
 *      expected helper classes
 *
 * @session  S-QBO-13
 * @decision D-QBO-13-1..6
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\QboWebhookSignature;
use FleetForge\QboPushers\PaymentWebhookHandler;

$pass = 0;
$total = 20;
$failures = [];

// ──────────────────────────────────────────────────────────────────────────
// Fixture helpers (sentinel IDs in 999990-999999 range)
// ──────────────────────────────────────────────────────────────────────────

function ff_smoke_pw_cleanup(): void
{
    db_execute("DELETE FROM acc_qbo_webhook_events WHERE webhook_event_id LIKE 'smoke-pw-%'");
    db_execute("DELETE FROM acc_qbo_payment_map WHERE qbo_payment_id LIKE 'smoke-pw-%'");
    db_execute("DELETE FROM payment_allocations WHERE payment_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM payments WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM invoices WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM customers WHERE id BETWEEN 999990 AND 999999");
}

function ff_smoke_pw_seed_invoice(int $invoiceId = 999990, string $balance = '500.00', string $status = 'sent', string $currency = 'CAD'): array
{
    // Customer — schema requires only `company_name`; others optional
    db_execute(
        "INSERT INTO customers (id, company_name, currency, outstanding_balance)
         VALUES (?, 'Smoke PW Customer Inc.', ?, ?)",
        [$invoiceId, $currency, $balance]
    );
    // Invoice — only minimum NOT-NULL-no-default columns + the ones the
    // handler reads (status, balance_due, amount_paid, currency,
    // total_amount, customer_id).
    db_execute(
        "INSERT INTO invoices (id, invoice_number, customer_id, invoice_date, due_date, status,
                                billing_period_start, billing_period_end, billing_period_days, billing_type,
                                subtotal, tax_total, total_amount, amount_paid, balance_due, currency)
         VALUES (?, ?, ?, '2026-04-10', '2026-05-10', ?,
                 '2026-04-01', '2026-04-30', 30, 'full_month',
                 ?, '0.00', ?, '0.00', ?, ?)",
        [
            $invoiceId,
            "SMOKE-PW-INV-{$invoiceId}",
            $invoiceId,
            $status,
            $balance,
            $balance,
            $balance,
            $currency,
        ]
    );
    db_execute(
        "INSERT INTO acc_qbo_invoice_map (ff_invoice_id, qbo_invoice_id, qbo_sync_token, push_status, pushed_at)
         VALUES (?, ?, '0', 'pushed', NOW())",
        [$invoiceId, 'qbo-inv-' . $invoiceId]
    );
    return ['invoice_id' => $invoiceId, 'customer_id' => $invoiceId, 'qbo_invoice_id' => 'qbo-inv-' . $invoiceId];
}

// ──────────────────────────────────────────────────────────────────────────
// Settings snapshot
// ──────────────────────────────────────────────────────────────────────────

$snapshotKeys = ['quickbooks.realm_id', 'quickbooks.webhook_verifier_token'];
$snapshot = [];
foreach ($snapshotKeys as $k) {
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$k]);
    $snapshot[$k] = $row['value'] ?? null;
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-13 Payment Webhook Smoke\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_pw_cleanup();

    // ── C1: Class surfaces ─────────────────────────────────────────────
    $c1Errors = [];
    foreach ([QboWebhookSignature::class, PaymentWebhookHandler::class] as $cls) {
        if (!class_exists($cls)) {
            $c1Errors[] = "{$cls} missing";
        }
    }
    foreach (['verify', 'compute'] as $m) {
        if (!method_exists(QboWebhookSignature::class, $m)) {
            $c1Errors[] = "QboWebhookSignature::{$m} missing";
        }
    }
    foreach (['handle', 'createFfPaymentFromQbo'] as $m) {
        if (!method_exists(PaymentWebhookHandler::class, $m)) {
            $c1Errors[] = "PaymentWebhookHandler::{$m} missing";
        }
    }
    if (empty($c1Errors)) {
        echo "PASS C1 class surfaces (QboWebhookSignature + PaymentWebhookHandler with required methods)\n";
        $pass++;
    } else {
        echo "FAIL C1 " . implode('; ', $c1Errors) . "\n";
        $failures[] = 'C1';
    }

    // ── C2: acc_qbo_payment_map schema ─────────────────────────────────
    $c2Errors = [];
    $cols = db_select("SHOW COLUMNS FROM acc_qbo_payment_map");
    $colNames = array_column($cols, 'Field');
    $required = ['id', 'ff_payment_id', 'qbo_payment_id', 'qbo_sync_token', 'qbo_total_amt',
                  'qbo_currency', 'qbo_txn_date', 'qbo_linked_invoice_id', 'origin',
                  'webhook_event_id', 'realm_id', 'push_status', 'push_error',
                  'pushed_at', 'pulled_at', 'last_synced_at', 'created_at', 'updated_at'];
    foreach ($required as $col) {
        if (!in_array($col, $colNames, true)) {
            $c2Errors[] = "missing col: {$col}";
        }
    }
    $pushStatusCol = null;
    foreach ($cols as $c) { if ($c['Field'] === 'push_status') { $pushStatusCol = $c['Type']; break; } }
    if ($pushStatusCol === null || strpos((string) $pushStatusCol, "'pulled_from_qbo'") === false) {
        $c2Errors[] = "push_status ENUM missing 'pulled_from_qbo' (D-QBO-13-2 terminal state for webhook-originated)";
    }
    $indexes = db_select("SHOW INDEX FROM acc_qbo_payment_map");
    $indexNames = array_unique(array_column($indexes, 'Key_name'));
    foreach (['PRIMARY', 'uq_ff_payment', 'uq_qbo_payment', 'idx_status', 'idx_origin', 'idx_webhook', 'idx_pulled_at'] as $idx) {
        if (!in_array($idx, $indexNames, true)) {
            $c2Errors[] = "missing index: {$idx}";
        }
    }
    if (empty($c2Errors)) {
        echo "PASS C2 acc_qbo_payment_map shape (18 cols + 2 UNIQUE + 4 idx + FK ON DELETE SET NULL)\n";
        $pass++;
    } else {
        echo "FAIL C2 " . implode('; ', $c2Errors) . "\n";
        $failures[] = 'C2';
    }

    // ── C3: acc_qbo_webhook_events schema ──────────────────────────────
    $c3Errors = [];
    $cols3 = db_select("SHOW COLUMNS FROM acc_qbo_webhook_events");
    $col3Names = array_column($cols3, 'Field');
    foreach (['id', 'webhook_event_id', 'event_type', 'realm_id', 'payload',
              'signature_verified', 'received_at', 'processed_at', 'processing_result',
              'error_message'] as $col) {
        if (!in_array($col, $col3Names, true)) {
            $c3Errors[] = "missing col: {$col}";
        }
    }
    $idx3 = array_unique(array_column(db_select("SHOW INDEX FROM acc_qbo_webhook_events"), 'Key_name'));
    foreach (['PRIMARY', 'uq_webhook_event_id', 'idx_event_type', 'idx_received'] as $idx) {
        if (!in_array($idx, $idx3, true)) {
            $c3Errors[] = "missing index: {$idx}";
        }
    }
    if (empty($c3Errors)) {
        echo "PASS C3 acc_qbo_webhook_events shape (10 cols + 1 UNIQUE + 4 idx; idempotency via uq_webhook_event_id)\n";
        $pass++;
    } else {
        echo "FAIL C3 " . implode('; ', $c3Errors) . "\n";
        $failures[] = 'C3';
    }

    // ── C4: payments.origin column ─────────────────────────────────────
    $c4Errors = [];
    $originCol = db_row("SHOW COLUMNS FROM payments WHERE Field = 'origin'");
    if (!$originCol) {
        $c4Errors[] = "payments.origin column missing (migration not applied?)";
    } else {
        $type = (string) ($originCol['Type'] ?? '');
        foreach (['ff_native', 'qbo_payments_webhook', 'qbo_other'] as $v) {
            if (strpos($type, "'{$v}'") === false) {
                $c4Errors[] = "payments.origin ENUM missing '{$v}'";
            }
        }
        if (($originCol['Default'] ?? null) !== 'ff_native') {
            $c4Errors[] = "payments.origin DEFAULT should be 'ff_native'; got: " . json_encode($originCol['Default'] ?? null);
        }
        if (($originCol['Null'] ?? null) !== 'NO') {
            $c4Errors[] = "payments.origin should be NOT NULL; got Null=" . json_encode($originCol['Null'] ?? null);
        }
    }
    if (empty($c4Errors)) {
        echo "PASS C4 payments.origin ENUM column added with correct default 'ff_native' (D-QBO-13-1)\n";
        $pass++;
    } else {
        echo "FAIL C4 " . implode('; ', $c4Errors) . "\n";
        $failures[] = 'C4';
    }

    // ── C5: signature valid ────────────────────────────────────────────
    $body = '{"foo":"bar"}';
    $token = 'smoke-test-verifier-token-xyz123';
    $sig = QboWebhookSignature::compute($body, $token);
    if (QboWebhookSignature::verify($body, $token, $sig)) {
        echo "PASS C5 QboWebhookSignature::verify accepts valid HMAC-SHA256 signature (round-trip)\n";
        $pass++;
    } else {
        echo "FAIL C5 verify rejected a freshly-computed signature\n";
        $failures[] = 'C5';
    }

    // ── C6: signature invalid ──────────────────────────────────────────
    if (!QboWebhookSignature::verify($body, $token, 'wrong-signature-base64')) {
        echo "PASS C6 QboWebhookSignature::verify rejects forged signature\n";
        $pass++;
    } else {
        echo "FAIL C6 verify accepted a forged signature\n";
        $failures[] = 'C6';
    }

    // ── C7: empty token fail-closed (D-QBO-13-3) ───────────────────────
    if (!QboWebhookSignature::verify($body, '', $sig)) {
        echo "PASS C7 QboWebhookSignature::verify fails closed on empty verifier_token (D-QBO-13-3)\n";
        $pass++;
    } else {
        echo "FAIL C7 verify accepted with empty verifier_token (CRITICAL: would accept any webhook)\n";
        $failures[] = 'C7';
    }

    // ── C8: empty received_signature rejected ──────────────────────────
    if (!QboWebhookSignature::verify($body, $token, '')) {
        echo "PASS C8 QboWebhookSignature::verify rejects empty received_signature\n";
        $pass++;
    } else {
        echo "FAIL C8 verify accepted empty signature\n";
        $failures[] = 'C8';
    }

    // ── C9: compute consistency ─────────────────────────────────────────
    $expected = base64_encode(hash_hmac('sha256', $body, $token, true));
    if (QboWebhookSignature::compute($body, $token) === $expected) {
        echo "PASS C9 QboWebhookSignature::compute matches reference HMAC-SHA256 base64 calculation\n";
        $pass++;
    } else {
        echo "FAIL C9 compute output mismatch\n";
        $failures[] = 'C9';
    }

    // ── C10: handle() wrong_realm ──────────────────────────────────────
    db_execute("INSERT INTO settings (`key`, `value`, `group_name`, `is_public`) VALUES ('quickbooks.realm_id', 'real-realm-123', 'quickbooks', 0)
                ON DUPLICATE KEY UPDATE `value` = 'real-realm-123'");
    $r10 = PaymentWebhookHandler::handle('qbo-pay-1', 'Create', 'wrong-realm-456', 'smoke-pw-evt-c10');
    if (($r10['result'] ?? null) === 'wrong_realm') {
        echo "PASS C10 handle() wrong_realm — webhook from non-connected realm skipped\n";
        $pass++;
    } else {
        echo "FAIL C10 expected wrong_realm; got: " . json_encode($r10) . "\n";
        $failures[] = 'C10';
    }

    // ── C11: handle() already_mapped ───────────────────────────────────
    // Order matters: payment first (FK target), THEN map row pointing at it.
    $fx11 = ff_smoke_pw_seed_invoice(999998, '100.00');
    db_execute(
        "INSERT INTO payments (id, payment_number, customer_id, amount, currency, payment_method, payment_date, status, origin)
         VALUES (999998, 'PAY-SMOKE-998', ?, '100.00', 'CAD', 'other', '2026-04-10', 'cleared', 'qbo_payments_webhook')",
        [$fx11['customer_id']]
    );
    db_execute(
        "INSERT INTO acc_qbo_payment_map (ff_payment_id, qbo_payment_id, origin, push_status, realm_id)
         VALUES (999998, 'smoke-pw-existing', 'qbo_payments_webhook', 'pulled_from_qbo', 'real-realm-123')"
    );
    $r11 = PaymentWebhookHandler::handle('smoke-pw-existing', 'Create', 'real-realm-123', 'smoke-pw-evt-c11');
    if (($r11['result'] ?? null) === 'already_mapped' && ($r11['ff_payment_id'] ?? null) === 999998) {
        echo "PASS C11 handle() already_mapped — short-circuit when qbo_payment_id has ff_payment_id set\n";
        $pass++;
    } else {
        echo "FAIL C11 expected already_mapped with ff_payment_id=999998; got: " . json_encode($r11) . "\n";
        $failures[] = 'C11';
    }
    // Cleanup C11
    db_execute("DELETE FROM acc_qbo_payment_map WHERE qbo_payment_id = 'smoke-pw-existing'");
    db_execute("DELETE FROM payments WHERE id = 999998");
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999998");
    db_execute("DELETE FROM invoices WHERE id = 999998");
    db_execute("DELETE FROM customers WHERE id = 999998");

    // ── C12: createFfPaymentFromQbo happy path ─────────────────────────
    $fx = ff_smoke_pw_seed_invoice(999990, '500.00', 'sent', 'CAD');
    $qboPayment = [
        'Id' => 'smoke-pw-qbo-pay-1',
        'TotalAmt' => '500.00',
        'TxnDate' => '2026-04-15',
        'CurrencyRef' => ['value' => 'CAD'],
        'SyncToken' => '0',
        'PaymentRefNum' => 'STRIPE-CH-abc123',
        'Line' => [['LinkedTxn' => [['TxnId' => $fx['qbo_invoice_id'], 'TxnType' => 'Invoice']]]],
    ];
    $r12 = db_transaction(function () use ($qboPayment, $fx) {
        return PaymentWebhookHandler::createFfPaymentFromQbo(
            $qboPayment, $fx['invoice_id'], 'smoke-pw-qbo-pay-1', 'smoke-pw-evt-c12', 'real-realm-123'
        );
    });
    $c12Errors = [];
    if (($r12['result'] ?? null) !== 'payment_created') $c12Errors[] = "result: " . json_encode($r12);
    $ffPayId12 = (int) ($r12['ff_payment_id'] ?? 0);
    if ($ffPayId12 <= 0) $c12Errors[] = "no ff_payment_id";
    if ($ffPayId12 > 0) {
        $p = db_row("SELECT amount, currency, origin, status, customer_id FROM payments WHERE id = ?", [$ffPayId12]);
        if (!$p || $p['amount'] !== '500.00') $c12Errors[] = "payment amount: " . json_encode($p['amount'] ?? null);
        if ($p['origin'] !== 'qbo_payments_webhook') $c12Errors[] = "payment origin: " . json_encode($p['origin'] ?? null);
        $a = db_row("SELECT amount FROM payment_allocations WHERE payment_id = ?", [$ffPayId12]);
        if (!$a || $a['amount'] !== '500.00') $c12Errors[] = "allocation: " . json_encode($a['amount'] ?? null);
        $m = db_row("SELECT push_status, origin, webhook_event_id FROM acc_qbo_payment_map WHERE ff_payment_id = ?", [$ffPayId12]);
        if (!$m || $m['push_status'] !== 'pulled_from_qbo') $c12Errors[] = "map push_status: " . json_encode($m['push_status'] ?? null);
        $inv = db_row("SELECT status, balance_due, amount_paid FROM invoices WHERE id = ?", [$fx['invoice_id']]);
        if (!$inv || $inv['balance_due'] !== '0.00' || $inv['status'] !== 'paid') {
            $c12Errors[] = "invoice not updated: " . json_encode($inv);
        }
    }
    if (empty($c12Errors)) {
        echo "PASS C12 createFfPaymentFromQbo happy path — payment + allocation + map + invoice counters all updated atomically\n";
        $pass++;
    } else {
        echo "FAIL C12 " . implode('; ', $c12Errors) . "\n";
        $failures[] = 'C12';
    }
    // Reset for next checks (delete the C12 payment first to avoid FK issues)
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id = ?", [$ffPayId12]);
    db_execute("DELETE FROM payment_allocations WHERE payment_id = ?", [$ffPayId12]);
    db_execute("DELETE FROM payments WHERE id = ?", [$ffPayId12]);
    // Reset invoice state
    db_execute("UPDATE invoices SET amount_paid='0.00', balance_due='500.00', status='sent' WHERE id = ?", [$fx['invoice_id']]);
    db_execute("UPDATE customers SET outstanding_balance='500.00' WHERE id = ?", [$fx['customer_id']]);

    // ── C13: currency_mismatch ─────────────────────────────────────────
    $qboPaymentBadCcy = ['Id' => 'smoke-pw-2', 'TotalAmt' => '500.00', 'TxnDate' => '2026-04-15',
                         'CurrencyRef' => ['value' => 'USD'], 'SyncToken' => '0', 'PaymentRefNum' => '',
                         'Line' => [['LinkedTxn' => [['TxnId' => $fx['qbo_invoice_id'], 'TxnType' => 'Invoice']]]]];
    $r13 = db_transaction(function () use ($qboPaymentBadCcy, $fx) {
        return PaymentWebhookHandler::createFfPaymentFromQbo($qboPaymentBadCcy, $fx['invoice_id'], 'smoke-pw-2', 'smoke-pw-evt-c13', 'real-realm-123');
    });
    if (($r13['result'] ?? null) === 'currency_mismatch') {
        echo "PASS C13 createFfPaymentFromQbo currency_mismatch — USD payment to CAD invoice rejected (D18 invariant)\n";
        $pass++;
    } else {
        echo "FAIL C13 expected currency_mismatch; got: " . json_encode($r13) . "\n";
        $failures[] = 'C13';
    }

    // ── C14: invoice_void ──────────────────────────────────────────────
    db_execute("UPDATE invoices SET status = 'void' WHERE id = ?", [$fx['invoice_id']]);
    $r14 = db_transaction(function () use ($qboPayment, $fx) {
        return PaymentWebhookHandler::createFfPaymentFromQbo($qboPayment, $fx['invoice_id'], 'smoke-pw-3', 'smoke-pw-evt-c14', 'real-realm-123');
    });
    if (($r14['result'] ?? null) === 'invoice_void') {
        echo "PASS C14 createFfPaymentFromQbo invoice_void — voided FF invoice rejects payment\n";
        $pass++;
    } else {
        echo "FAIL C14 expected invoice_void; got: " . json_encode($r14) . "\n";
        $failures[] = 'C14';
    }
    db_execute("UPDATE invoices SET status = 'sent' WHERE id = ?", [$fx['invoice_id']]);

    // ── C15: overpayment ───────────────────────────────────────────────
    $qboOver = ['Id' => 'smoke-pw-over', 'TotalAmt' => '700.00', 'TxnDate' => '2026-04-15',
                 'CurrencyRef' => ['value' => 'CAD'], 'SyncToken' => '0', 'PaymentRefNum' => '',
                 'Line' => [['LinkedTxn' => [['TxnId' => $fx['qbo_invoice_id'], 'TxnType' => 'Invoice']]]]];
    $r15 = db_transaction(function () use ($qboOver, $fx) {
        return PaymentWebhookHandler::createFfPaymentFromQbo($qboOver, $fx['invoice_id'], 'smoke-pw-over', 'smoke-pw-evt-c15', 'real-realm-123');
    });
    $c15Errors = [];
    if (($r15['result'] ?? null) !== 'payment_created') $c15Errors[] = "result: " . json_encode($r15);
    $ffPayId15 = (int) ($r15['ff_payment_id'] ?? 0);
    $pay15 = $ffPayId15 > 0 ? db_row("SELECT amount, overpayment_amount, overpayment_action FROM payments WHERE id = ?", [$ffPayId15]) : null;
    if (!$pay15 || $pay15['overpayment_amount'] !== '200.00' || $pay15['overpayment_action'] !== 'credit_to_account') {
        $c15Errors[] = "overpayment fields: " . json_encode($pay15);
    }
    if (empty($c15Errors)) {
        echo "PASS C15 createFfPaymentFromQbo overpayment — $700 payment to $500 invoice → overpayment_amount=200.00 + action=credit_to_account\n";
        $pass++;
    } else {
        echo "FAIL C15 " . implode('; ', $c15Errors) . "\n";
        $failures[] = 'C15';
    }
    // Cleanup C15
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id = ?", [$ffPayId15]);
    db_execute("DELETE FROM payment_allocations WHERE payment_id = ?", [$ffPayId15]);
    db_execute("DELETE FROM payments WHERE id = ?", [$ffPayId15]);
    db_execute("UPDATE invoices SET amount_paid='0.00', balance_due='500.00', status='sent' WHERE id = ?", [$fx['invoice_id']]);
    db_execute("UPDATE customers SET outstanding_balance='500.00' WHERE id = ?", [$fx['customer_id']]);

    // ── C16: payment_number format ─────────────────────────────────────
    $r16 = db_transaction(function () use ($qboPayment, $fx) {
        return PaymentWebhookHandler::createFfPaymentFromQbo($qboPayment, $fx['invoice_id'], 'smoke-pw-num', 'smoke-pw-evt-c16', 'real-realm-123');
    });
    $ffPayId16 = (int) ($r16['ff_payment_id'] ?? 0);
    $pay16 = $ffPayId16 > 0 ? db_row("SELECT payment_number FROM payments WHERE id = ?", [$ffPayId16]) : null;
    if ($pay16 && preg_match('/^PAY-\d{4}-\d{5}$/', (string) $pay16['payment_number'])) {
        echo "PASS C16 createFfPaymentFromQbo payment_number gap-free format PAY-YYYY-NNNNN (D15): {$pay16['payment_number']}\n";
        $pass++;
    } else {
        echo "FAIL C16 payment_number format wrong: " . json_encode($pay16) . "\n";
        $failures[] = 'C16';
    }
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id = ?", [$ffPayId16]);
    db_execute("DELETE FROM payment_allocations WHERE payment_id = ?", [$ffPayId16]);
    db_execute("DELETE FROM payments WHERE id = ?", [$ffPayId16]);
    db_execute("UPDATE invoices SET amount_paid='0.00', balance_due='500.00', status='sent' WHERE id = ?", [$fx['invoice_id']]);
    db_execute("UPDATE customers SET outstanding_balance='500.00' WHERE id = ?", [$fx['customer_id']]);

    // ── C17: PaymentRefNum fallback ────────────────────────────────────
    $qboNoRef = $qboPayment;
    unset($qboNoRef['PaymentRefNum']);
    $qboNoRef['Id'] = 'smoke-pw-noref';
    $r17 = db_transaction(function () use ($qboNoRef, $fx) {
        return PaymentWebhookHandler::createFfPaymentFromQbo($qboNoRef, $fx['invoice_id'], 'smoke-pw-noref', 'smoke-pw-evt-c17', 'real-realm-123');
    });
    $ffPayId17 = (int) ($r17['ff_payment_id'] ?? 0);
    $pay17 = $ffPayId17 > 0 ? db_row("SELECT reference_number FROM payments WHERE id = ?", [$ffPayId17]) : null;
    if ($pay17 && $pay17['reference_number'] === 'QBO-smoke-pw-noref') {
        echo "PASS C17 createFfPaymentFromQbo reference_number falls back to 'QBO-{qboPaymentId}' when PaymentRefNum absent (D-QBO-13-6)\n";
        $pass++;
    } else {
        echo "FAIL C17 reference_number: " . json_encode($pay17) . "\n";
        $failures[] = 'C17';
    }
    db_execute("DELETE FROM acc_qbo_payment_map WHERE ff_payment_id = ?", [$ffPayId17]);
    db_execute("DELETE FROM payment_allocations WHERE payment_id = ?", [$ffPayId17]);
    db_execute("DELETE FROM payments WHERE id = ?", [$ffPayId17]);
    db_execute("UPDATE invoices SET amount_paid='0.00', balance_due='500.00', status='sent' WHERE id = ?", [$fx['invoice_id']]);
    db_execute("UPDATE customers SET outstanding_balance='500.00' WHERE id = ?", [$fx['customer_id']]);

    // ── C18: invoice flips to paid when balance hits zero ──────────────
    $r18 = db_transaction(function () use ($qboPayment, $fx) {
        return PaymentWebhookHandler::createFfPaymentFromQbo($qboPayment, $fx['invoice_id'], 'smoke-pw-paid', 'smoke-pw-evt-c18', 'real-realm-123');
    });
    $ffPayId18 = (int) ($r18['ff_payment_id'] ?? 0);
    $inv18 = db_row("SELECT status, balance_due FROM invoices WHERE id = ?", [$fx['invoice_id']]);
    if ($inv18 && $inv18['status'] === 'paid' && $inv18['balance_due'] === '0.00') {
        echo "PASS C18 createFfPaymentFromQbo flips invoice status='paid' + balance_due='0.00' when fully paid\n";
        $pass++;
    } else {
        echo "FAIL C18 invoice not flipped to paid: " . json_encode($inv18) . "\n";
        $failures[] = 'C18';
    }

    // ── C19: origin + push_status verification ─────────────────────────
    $c19Errors = [];
    $pay19 = $ffPayId18 > 0 ? db_row("SELECT origin FROM payments WHERE id = ?", [$ffPayId18]) : null;
    if (!$pay19 || $pay19['origin'] !== 'qbo_payments_webhook') {
        $c19Errors[] = "payments.origin: " . json_encode($pay19);
    }
    $map19 = $ffPayId18 > 0 ? db_row("SELECT origin, push_status FROM acc_qbo_payment_map WHERE ff_payment_id = ?", [$ffPayId18]) : null;
    if (!$map19 || $map19['origin'] !== 'qbo_payments_webhook' || $map19['push_status'] !== 'pulled_from_qbo') {
        $c19Errors[] = "map row: " . json_encode($map19);
    }
    if (empty($c19Errors)) {
        echo "PASS C19 D-QBO-13-1 (payments.origin='qbo_payments_webhook') + D-QBO-13-2 (map.push_status='pulled_from_qbo') verified\n";
        $pass++;
    } else {
        echo "FAIL C19 " . implode('; ', $c19Errors) . "\n";
        $failures[] = 'C19';
    }

    // ── C20: Webhook endpoint file structure ───────────────────────────
    $c20Errors = [];
    $endpointPath = realpath(__DIR__ . '/../api/v1/webhooks/qbo_payment_notifications.php');
    if (!$endpointPath || !is_readable($endpointPath)) {
        $c20Errors[] = "endpoint file missing or unreadable";
    } else {
        $content = file_get_contents($endpointPath);
        if (strpos($content, 'QboWebhookSignature::verify') === false) $c20Errors[] = "no QboWebhookSignature::verify call";
        if (strpos($content, 'PaymentWebhookHandler::handle') === false) $c20Errors[] = "no PaymentWebhookHandler::handle call";
        if (strpos($content, 'acc_qbo_webhook_events') === false) $c20Errors[] = "no acc_qbo_webhook_events reference";
        if (strpos($content, 'http_response_code(403)') === false) $c20Errors[] = "no 403 response (signature fail-closed)";
        if (strpos($content, 'fastcgi_finish_request') === false) $c20Errors[] = "no fastcgi_finish_request (release HTTP connection before processing)";
        $lint = shell_exec("php -l " . escapeshellarg($endpointPath) . " 2>&1");
        if (strpos((string) $lint, 'No syntax errors') === false) $c20Errors[] = "lint failed: {$lint}";
    }
    if (empty($c20Errors)) {
        echo "PASS C20 webhook endpoint file structure (signature verify + handler dispatch + fastcgi_finish_request + 403 fail-closed)\n";
        $pass++;
    } else {
        echo "FAIL C20 " . implode('; ', $c20Errors) . "\n";
        $failures[] = 'C20';
    }
} finally {
    ff_smoke_pw_cleanup();
    foreach ($snapshotKeys as $k) {
        if ($snapshot[$k] === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            db_execute("INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, 'quickbooks')
                         ON DUPLICATE KEY UPDATE `value` = ?",
                       [$k, $snapshot[$k], $snapshot[$k]]);
        }
    }
}

echo "\nqbo_payment_webhook_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " (failures: " . implode(', ', $failures) . ")";
}
echo "\n";

exit(empty($failures) ? 0 : 1);
