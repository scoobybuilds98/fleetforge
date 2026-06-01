<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_refund_receipt_push.php
 *
 * Smoke test for S-QBO-17 Refund Receipt push (Phase QBO-7 / 2 of 2 — CLOSES
 * Phase QBO-7). FF cash precharge refunds → QBO RefundReceipt. Sibling of
 * _smoke_qbo_credit_memo_push. Per-function coverage per
 * [[extensive-test-and-full-report]].
 *
 * Sub-check map:
 *   Module A — surfaces + schema
 *     C1  RefundReceiptPusher surfaces (pushCreate/buildQboPayload/
 *         buildPrivateNoteJson/runPreflight/docNumber + REFUND_ITEM_TYPE)
 *     C2  RefundReceiptEnqueuer::enqueue surfaces
 *     C3  acc_qbo_refund_receipt_map schema (cols + UNIQUE keys)
 *     C4  acc_qbo_sync_queue.entity_type ENUM includes 'refund_receipt'
 *
 *   Module B — buildQboPayload (happy + multi-currency + throws)
 *     C5  happy: single SalesItemLineDetail line (Amount=precharge_balance) +
 *         PaymentMethodRef + DepositToAccountRef + ItemRef + TaxCodeRef +
 *         TotalTax=0 + DocNumber=RFD-<id> + PrivateNote
 *     C6  multi_currency='1' → CurrencyRef + ExchangeRate
 *     C7  throws on empty customer  C8 item  C9 deposit  C10 payment method
 *     C11 throws on empty tax code  C12 throws on precharge_balance ≤ 0
 *
 *   Module C — buildPrivateNoteJson
 *     C13 valid JSON with required keys
 *
 *   Module D — runPreflight (per gate)
 *     C14 customer unmapped  C15 item unmapped  C16 tax override empty
 *     C17 deposit account empty  C18 payment method empty  C19 all good → ok
 *
 *   Module E — pushImpl (no HTTP)
 *     C20 sync_mode='disabled' → skipped_by_mode
 *     C21 ff_not_found
 *     C22 not eligible (unsettled) → skipped_not_eligible
 *     C23 already_mapped (existing qbo_refund_receipt_id)
 *     C24 failed_preflight (customer unmapped) → map row written
 *
 *   Module F — Enqueuer gates
 *     C25 gate-0 missing lease  C26 gate-0 not cash  C27 gate-0 unsettled
 *     C28 gate-1 sync_enabled='0'  C29 gate-3 non-create  C30 happy insert
 *
 *   Module G — hook integration
 *     C31 mark_refund_settled.php contains the post-commit enqueue hook
 *
 * HTTP-trap rule: every check short-circuits before createEntity.
 * Sentinel IDs 999990-999999; cleaned in finally.
 *
 * @session  S-QBO-17
 * @decision D-QBO-17-1/-2/-3/-4/-5/-6
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\RefundReceiptPusher;
use FleetForge\QboPushers\RefundReceiptEnqueuer;
use FleetForge\Exceptions\QuickBooksException;

$pass = 0;
$total = 31;
$failures = [];

function ff_smoke_rr_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}
function ff_smoke_rr_get(string $key): ?string
{
    $r = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $r['value'] ?? null;
}

function ff_smoke_rr_cleanup(): void
{
    db_execute("DELETE FROM acc_qbo_sync_log          WHERE entity_type = 'refund_receipt' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue        WHERE entity_type = 'refund_receipt' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_refund_receipt_map WHERE ff_lease_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM leases                    WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_customer_map      WHERE ff_customer_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM customers                 WHERE id BETWEEN 999990 AND 999999");
}

$snapshotKeys = [
    'quickbooks.sync_enabled',
    'quickbooks.sync_mode.refund_receipt',
    'quickbooks.multi_currency_enabled',
    'quickbooks.tax_override_code_id',
    'quickbooks.refund.deposit_account_id',
    'quickbooks.refund.payment_method_id',
];
$snapshot = [];
foreach ($snapshotKeys as $k) { $snapshot[$k] = ff_smoke_rr_get($k); }

// Capture + ensure the 'mileage_credit' item mapping.
$itemPre = db_row("SELECT id, qbo_item_id, mapping_status FROM acc_qbo_item_map WHERE ff_item_type = 'mileage_credit' ORDER BY id ASC LIMIT 1");

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-17 Refund Receipt Push Smoke ({$total} sub-checks; CLOSES Phase QBO-7)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_rr_cleanup();

    if ($itemPre && $itemPre['mapping_status'] === 'mapped' && !empty($itemPre['qbo_item_id'])) {
        $qboItemId = (string) $itemPre['qbo_item_id'];
    } else {
        if ($itemPre) {
            db_execute("UPDATE acc_qbo_item_map SET mapping_status='mapped', qbo_item_id='SMOKE-RR-ITEM' WHERE id = ?", [(int) $itemPre['id']]);
        } else {
            db_execute("INSERT INTO acc_qbo_item_map (ff_item_type, qbo_item_id, mapping_status) VALUES ('mileage_credit', 'SMOKE-RR-ITEM', 'mapped')");
        }
        $qboItemId = 'SMOKE-RR-ITEM';
    }

    // Sentinel customer + mapping.
    db_execute("INSERT INTO customers (id, company_name, currency, created_at) VALUES (999990, 'Smoke RR Customer', 'CAD', NOW())");
    db_execute("INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, mapping_status) VALUES (999990, 'QBO-CUST-9990', 'mapped')");

    // Sentinel lease — completed, cash refund, settled, balance 125.00.
    db_execute(
        "INSERT INTO leases (id, contract_number, customer_id, currency, status, start_date,
                             precharge_enabled, precharge_amount, precharge_balance, precharge_refund_method, precharge_refund_settled_at, created_at)
         VALUES (999991, 'LSE-RR-999991', 999990, 'CAD', 'completed', '2026-01-01',
                 1, 125.00, 125.00, 'cash', NOW(), NOW())"
    );
    $lease = db_row("SELECT * FROM leases WHERE id = 999991");

    ff_smoke_rr_set('quickbooks.sync_enabled', '1');
    ff_smoke_rr_set('quickbooks.sync_mode.refund_receipt', 'sync');
    ff_smoke_rr_set('quickbooks.multi_currency_enabled', '0');
    ff_smoke_rr_set('quickbooks.tax_override_code_id', 'NON-SMOKE');
    ff_smoke_rr_set('quickbooks.refund.deposit_account_id', 'QBO-ACCT-RFD');
    ff_smoke_rr_set('quickbooks.refund.payment_method_id', 'QBO-PM-CHEQUE');

    // ══ Module A ══════════════════════════════════════════════════════════
    $c1 = [];
    $ref = new ReflectionClass(RefundReceiptPusher::class);
    foreach (['pushCreate','buildQboPayload','buildPrivateNoteJson','runPreflight','docNumber'] as $m) {
        if (!method_exists(RefundReceiptPusher::class, $m)) $c1[] = "missing method {$m}";
    }
    if (!$ref->hasConstant('REFUND_ITEM_TYPE')) $c1[] = 'missing REFUND_ITEM_TYPE const';
    if (empty($c1)) { echo "PASS C1 RefundReceiptPusher surfaces\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1) . "\n"; $failures[] = 'C1'; }

    if (method_exists(RefundReceiptEnqueuer::class, 'enqueue')) { echo "PASS C2 RefundReceiptEnqueuer::enqueue surfaces\n"; $pass++; }
    else { echo "FAIL C2 missing\n"; $failures[] = 'C2'; }

    $c3 = [];
    $cols = array_column(db_select("SHOW COLUMNS FROM acc_qbo_refund_receipt_map"), 'Field');
    foreach (['id','ff_lease_id','ff_customer_id_snapshot','ff_contract_number_snapshot','qbo_refund_receipt_id','qbo_sync_token','qbo_doc_number','qbo_total_amt','qbo_currency','qbo_txn_date','qbo_deposit_account_id','qbo_payment_method_id','ff_refund_amount_snapshot','push_status','push_error','pushed_at','last_synced_at'] as $col) {
        if (!in_array($col, $cols, true)) $c3[] = "missing col {$col}";
    }
    $idx = array_unique(array_column(db_select("SHOW INDEX FROM acc_qbo_refund_receipt_map"), 'Key_name'));
    foreach (['PRIMARY','uq_ff_lease','uq_qbo_refund_receipt'] as $k) {
        if (!in_array($k, $idx, true)) $c3[] = "missing index {$k}";
    }
    if (empty($c3)) { echo "PASS C3 acc_qbo_refund_receipt_map schema\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3) . "\n"; $failures[] = 'C3'; }

    $queueDef = db_row("SHOW CREATE TABLE acc_qbo_sync_queue");
    if ($queueDef && strpos($queueDef['Create Table'], "'refund_receipt'") !== false) {
        echo "PASS C4 queue ENUM includes 'refund_receipt'\n"; $pass++;
    } else { echo "FAIL C4 ENUM missing 'refund_receipt'\n"; $failures[] = 'C4'; }

    // ══ Module B — buildQboPayload ════════════════════════════════════════
    $payload = RefundReceiptPusher::buildQboPayload($lease, 'QBO-CUST-9990', $qboItemId, 'QBO-ACCT-RFD', 'QBO-PM-CHEQUE', 'NON-SMOKE');
    $c5 = [];
    if (($payload['CustomerRef']['value'] ?? '') !== 'QBO-CUST-9990') $c5[] = 'CustomerRef wrong';
    if (($payload['PaymentMethodRef']['value'] ?? '') !== 'QBO-PM-CHEQUE') $c5[] = 'PaymentMethodRef wrong';
    if (($payload['DepositToAccountRef']['value'] ?? '') !== 'QBO-ACCT-RFD') $c5[] = 'DepositToAccountRef wrong';
    if (($payload['DocNumber'] ?? '') !== 'RFD-999991') $c5[] = 'DocNumber should be RFD-999991';
    if (count($payload['Line'] ?? []) !== 1) $c5[] = 'expected 1 Line';
    if (($payload['Line'][0]['Amount'] ?? null) !== 125.0) $c5[] = 'Line.Amount should equal precharge_balance (125.0)';
    if (($payload['Line'][0]['SalesItemLineDetail']['ItemRef']['value'] ?? '') !== $qboItemId) $c5[] = 'ItemRef wrong';
    if (($payload['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value'] ?? '') !== 'NON-SMOKE') $c5[] = 'line TaxCodeRef wrong';
    if (($payload['TxnTaxDetail']['TotalTax'] ?? null) !== '0.00') $c5[] = 'TotalTax should be 0.00';
    if (empty($payload['PrivateNote'])) $c5[] = 'missing PrivateNote';
    if (empty($c5)) { echo "PASS C5 buildQboPayload happy (1 line + refs + TotalTax=0 + DocNumber)\n"; $pass++; }
    else { echo "FAIL C5 " . implode('; ', $c5) . "\n"; $failures[] = 'C5'; }

    ff_smoke_rr_set('quickbooks.multi_currency_enabled', '1');
    $payloadMc = RefundReceiptPusher::buildQboPayload($lease, 'QBO-CUST-9990', $qboItemId, 'QBO-ACCT-RFD', 'QBO-PM-CHEQUE', 'NON-SMOKE');
    ff_smoke_rr_set('quickbooks.multi_currency_enabled', '0');
    if (!empty($payloadMc['CurrencyRef']['value']) && !empty($payloadMc['ExchangeRate'])) {
        echo "PASS C6 multi_currency → CurrencyRef + ExchangeRate\n"; $pass++;
    } else { echo "FAIL C6 missing CurrencyRef/ExchangeRate\n"; $failures[] = 'C6'; }

    foreach ([
        ['', $qboItemId, 'QBO-ACCT-RFD', 'QBO-PM-CHEQUE', 'NON-SMOKE', 'C7', 'customer'],
        ['QBO-CUST-9990', '', 'QBO-ACCT-RFD', 'QBO-PM-CHEQUE', 'NON-SMOKE', 'C8', 'item'],
        ['QBO-CUST-9990', $qboItemId, '', 'QBO-PM-CHEQUE', 'NON-SMOKE', 'C9', 'deposit account'],
        ['QBO-CUST-9990', $qboItemId, 'QBO-ACCT-RFD', '', 'NON-SMOKE', 'C10', 'payment method'],
        ['QBO-CUST-9990', $qboItemId, 'QBO-ACCT-RFD', 'QBO-PM-CHEQUE', '', 'C11', 'tax code'],
    ] as $case) {
        [$cust, $item, $dep, $pm, $tax, $label, $desc] = $case;
        try {
            RefundReceiptPusher::buildQboPayload($lease, $cust, $item, $dep, $pm, $tax);
            echo "FAIL {$label} should throw on missing {$desc}\n"; $failures[] = $label;
        } catch (QuickBooksException $e) {
            echo "PASS {$label} buildQboPayload throws on missing {$desc}\n"; $pass++;
        }
    }

    $leaseZero = $lease;
    $leaseZero['precharge_balance'] = '0.00';
    try {
        RefundReceiptPusher::buildQboPayload($leaseZero, 'QBO-CUST-9990', $qboItemId, 'QBO-ACCT-RFD', 'QBO-PM-CHEQUE', 'NON-SMOKE');
        echo "FAIL C12 should throw on precharge_balance=0\n"; $failures[] = 'C12';
    } catch (QuickBooksException $e) {
        echo "PASS C12 buildQboPayload throws on precharge_balance ≤ 0\n"; $pass++;
    }

    // ══ Module C ══════════════════════════════════════════════════════════
    $note = json_decode(RefundReceiptPusher::buildPrivateNoteJson($lease), true);
    $c13 = [];
    foreach (['ff_lease_id','ff_contract_number','refund_method','refund_amount','settled_at','pushed_at'] as $k) {
        if (!array_key_exists($k, $note ?? [])) $c13[] = "missing {$k}";
    }
    if (empty($c13)) { echo "PASS C13 buildPrivateNoteJson valid JSON with required keys\n"; $pass++; }
    else { echo "FAIL C13 " . implode('; ', $c13) . "\n"; $failures[] = 'C13'; }

    // ══ Module D — runPreflight ═══════════════════════════════════════════
    db_execute("UPDATE acc_qbo_customer_map SET mapping_status='ff_only' WHERE ff_customer_id=999990");
    $r = RefundReceiptPusher::runPreflight(999991, $lease);
    if (!$r['ok'] && strpos($r['reason'], 'mapped QBO customer') !== false) { echo "PASS C14 runPreflight rejects customer unmapped\n"; $pass++; }
    else { echo "FAIL C14 " . json_encode($r) . "\n"; $failures[] = 'C14'; }
    db_execute("UPDATE acc_qbo_customer_map SET mapping_status='mapped' WHERE ff_customer_id=999990");

    // item unmapped: temporarily flip the mileage_credit mapping.
    db_execute("UPDATE acc_qbo_item_map SET mapping_status='ff_only' WHERE ff_item_type='mileage_credit'");
    $r = RefundReceiptPusher::runPreflight(999991, $lease);
    if (!$r['ok'] && strpos($r['reason'], "item_type 'mileage_credit'") !== false) { echo "PASS C15 runPreflight rejects item unmapped\n"; $pass++; }
    else { echo "FAIL C15 " . json_encode($r) . "\n"; $failures[] = 'C15'; }
    db_execute("UPDATE acc_qbo_item_map SET mapping_status='mapped' WHERE ff_item_type='mileage_credit'");

    ff_smoke_rr_set('quickbooks.tax_override_code_id', '');
    $r = RefundReceiptPusher::runPreflight(999991, $lease);
    if (!$r['ok'] && strpos($r['reason'], 'Tax override') !== false) { echo "PASS C16 runPreflight rejects tax override empty\n"; $pass++; }
    else { echo "FAIL C16 " . json_encode($r) . "\n"; $failures[] = 'C16'; }
    ff_smoke_rr_set('quickbooks.tax_override_code_id', 'NON-SMOKE');

    ff_smoke_rr_set('quickbooks.refund.deposit_account_id', '');
    $r = RefundReceiptPusher::runPreflight(999991, $lease);
    if (!$r['ok'] && strpos($r['reason'], 'deposit account') !== false) { echo "PASS C17 runPreflight rejects deposit account empty\n"; $pass++; }
    else { echo "FAIL C17 " . json_encode($r) . "\n"; $failures[] = 'C17'; }
    ff_smoke_rr_set('quickbooks.refund.deposit_account_id', 'QBO-ACCT-RFD');

    ff_smoke_rr_set('quickbooks.refund.payment_method_id', '');
    $r = RefundReceiptPusher::runPreflight(999991, $lease);
    if (!$r['ok'] && strpos($r['reason'], 'payment method') !== false) { echo "PASS C18 runPreflight rejects payment method empty\n"; $pass++; }
    else { echo "FAIL C18 " . json_encode($r) . "\n"; $failures[] = 'C18'; }
    ff_smoke_rr_set('quickbooks.refund.payment_method_id', 'QBO-PM-CHEQUE');

    $r = RefundReceiptPusher::runPreflight(999991, $lease);
    if ($r['ok'] && ($r['qbo_customer_id'] ?? '') === 'QBO-CUST-9990' && ($r['qbo_deposit_account_id'] ?? '') === 'QBO-ACCT-RFD' && ($r['qbo_payment_method_id'] ?? '') === 'QBO-PM-CHEQUE') {
        echo "PASS C19 runPreflight ok + resolved refs\n"; $pass++;
    } else { echo "FAIL C19 " . json_encode($r) . "\n"; $failures[] = 'C19'; }

    // ══ Module E — pushImpl ═══════════════════════════════════════════════
    ff_smoke_rr_set('quickbooks.sync_mode.refund_receipt', 'disabled');
    $r = RefundReceiptPusher::pushCreate(999991);
    $logRow = db_row("SELECT error_code FROM acc_qbo_sync_log WHERE entity_type='refund_receipt' AND entity_id=999991 ORDER BY id DESC LIMIT 1");
    if (($r['status'] ?? '') === 'skipped_by_mode' && ($logRow['error_code'] ?? '') === 'skipped_by_mode') { echo "PASS C20 sync_mode='disabled' → skipped_by_mode\n"; $pass++; }
    else { echo "FAIL C20 status=" . ($r['status'] ?? 'null') . "\n"; $failures[] = 'C20'; }
    ff_smoke_rr_set('quickbooks.sync_mode.refund_receipt', 'sync');
    db_execute("DELETE FROM acc_qbo_sync_log          WHERE entity_type='refund_receipt' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_refund_receipt_map WHERE ff_lease_id BETWEEN 999990 AND 999999");

    $r = RefundReceiptPusher::pushCreate(999988);
    if (($r['status'] ?? '') === 'ff_not_found') { echo "PASS C21 ff_not_found\n"; $pass++; }
    else { echo "FAIL C21 " . ($r['status'] ?? 'null') . "\n"; $failures[] = 'C21'; }

    // C22 — not eligible (unsettled).
    db_execute("UPDATE leases SET precharge_refund_settled_at=NULL WHERE id=999991");
    $r = RefundReceiptPusher::pushCreate(999991);
    if (($r['status'] ?? '') === 'skipped_not_eligible') { echo "PASS C22 unsettled → skipped_not_eligible\n"; $pass++; }
    else { echo "FAIL C22 " . ($r['status'] ?? 'null') . "\n"; $failures[] = 'C22'; }
    db_execute("UPDATE leases SET precharge_refund_settled_at=NOW() WHERE id=999991");
    db_execute("DELETE FROM acc_qbo_sync_log          WHERE entity_type='refund_receipt' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_refund_receipt_map WHERE ff_lease_id BETWEEN 999990 AND 999999");

    // C23 — already_mapped.
    db_execute("INSERT INTO acc_qbo_refund_receipt_map (ff_lease_id, ff_customer_id_snapshot, qbo_refund_receipt_id, push_status, pushed_at) VALUES (999991, 999990, 'QBO-RR-PRE', 'pushed', NOW())");
    $r = RefundReceiptPusher::pushCreate(999991);
    if (($r['status'] ?? '') === 'already_mapped' && ($r['qbo_id'] ?? '') === 'QBO-RR-PRE') { echo "PASS C23 already_mapped → no second POST\n"; $pass++; }
    else { echo "FAIL C23 " . json_encode($r) . "\n"; $failures[] = 'C23'; }
    db_execute("DELETE FROM acc_qbo_refund_receipt_map WHERE ff_lease_id=999991");

    // C24 — failed_preflight (customer unmapped) → map row.
    db_execute("UPDATE acc_qbo_customer_map SET mapping_status='ff_only' WHERE ff_customer_id=999990");
    $r = RefundReceiptPusher::pushCreate(999991);
    $mapRow = db_row("SELECT push_status FROM acc_qbo_refund_receipt_map WHERE ff_lease_id=999991");
    if (($r['status'] ?? '') === 'failed_preflight' && ($mapRow['push_status'] ?? '') === 'failed_preflight') { echo "PASS C24 failed_preflight → map row\n"; $pass++; }
    else { echo "FAIL C24 " . json_encode($r) . " map=" . json_encode($mapRow) . "\n"; $failures[] = 'C24'; }
    db_execute("UPDATE acc_qbo_customer_map SET mapping_status='mapped' WHERE ff_customer_id=999990");
    db_execute("DELETE FROM acc_qbo_sync_log          WHERE entity_type='refund_receipt' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_refund_receipt_map WHERE ff_lease_id BETWEEN 999990 AND 999999");

    // ══ Module F — Enqueuer gates ═════════════════════════════════════════
    if (RefundReceiptEnqueuer::enqueue(999987, 'create') === false) { echo "PASS C25 gate-0 rejects missing lease\n"; $pass++; }
    else { echo "FAIL C25\n"; $failures[] = 'C25'; }

    db_execute("UPDATE leases SET precharge_refund_method='credit' WHERE id=999991");
    if (RefundReceiptEnqueuer::enqueue(999991, 'create') === false) { echo "PASS C26 gate-0 rejects non-cash\n"; $pass++; }
    else { echo "FAIL C26\n"; $failures[] = 'C26'; }
    db_execute("UPDATE leases SET precharge_refund_method='cash' WHERE id=999991");

    db_execute("UPDATE leases SET precharge_refund_settled_at=NULL WHERE id=999991");
    if (RefundReceiptEnqueuer::enqueue(999991, 'create') === false) { echo "PASS C27 gate-0 rejects unsettled\n"; $pass++; }
    else { echo "FAIL C27\n"; $failures[] = 'C27'; }
    db_execute("UPDATE leases SET precharge_refund_settled_at=NOW() WHERE id=999991");

    ff_smoke_rr_set('quickbooks.sync_enabled', '0');
    if (RefundReceiptEnqueuer::enqueue(999991, 'create') === false) { echo "PASS C28 gate-1 rejects sync_enabled='0'\n"; $pass++; }
    else { echo "FAIL C28\n"; $failures[] = 'C28'; }
    ff_smoke_rr_set('quickbooks.sync_enabled', '1');

    if (RefundReceiptEnqueuer::enqueue(999991, 'update') === false) { echo "PASS C29 gate-3 rejects non-'create'\n"; $pass++; }
    else { echo "FAIL C29\n"; $failures[] = 'C29'; }

    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='refund_receipt' AND entity_id=999991");
    $ok = RefundReceiptEnqueuer::enqueue(999991, 'create');
    $qr = db_row("SELECT entity_type, entity_id, operation, status FROM acc_qbo_sync_queue WHERE entity_type='refund_receipt' AND entity_id=999991 ORDER BY id DESC LIMIT 1");
    if ($ok && ($qr['entity_type'] ?? '') === 'refund_receipt' && (int) ($qr['entity_id'] ?? 0) === 999991 && ($qr['operation'] ?? '') === 'create' && ($qr['status'] ?? '') === 'queued') {
        echo "PASS C30 happy queue insert (refund_receipt/999991/create/queued)\n"; $pass++;
    } else { echo "FAIL C30 ok=" . var_export($ok, true) . " row=" . json_encode($qr) . "\n"; $failures[] = 'C30'; }

    // ══ Module G — hook integration ══════════════════════════════════════
    $hookSrc = (string) file_get_contents(__DIR__ . '/../api/v1/leases/mark_refund_settled.php');
    if (strpos($hookSrc, 'RefundReceiptEnqueuer::enqueue') !== false && strpos($hookSrc, "'create'") !== false) {
        echo "PASS C31 mark_refund_settled.php contains post-commit enqueue hook\n"; $pass++;
    } else { echo "FAIL C31 hook missing\n"; $failures[] = 'C31'; }

} finally {
    ff_smoke_rr_cleanup();
    // Restore item mapping.
    if ($itemPre) {
        if ($itemPre['mapping_status'] === 'mapped' && !empty($itemPre['qbo_item_id'])) {
            db_execute("UPDATE acc_qbo_item_map SET mapping_status=?, qbo_item_id=? WHERE id=?", [$itemPre['mapping_status'], $itemPre['qbo_item_id'], (int) $itemPre['id']]);
        } else {
            db_execute("UPDATE acc_qbo_item_map SET mapping_status=?, qbo_item_id=? WHERE id=?", [$itemPre['mapping_status'], $itemPre['qbo_item_id'], (int) $itemPre['id']]);
        }
    } else {
        db_execute("DELETE FROM acc_qbo_item_map WHERE ff_item_type='mileage_credit' AND qbo_item_id='SMOKE-RR-ITEM'");
    }
    foreach ($snapshot as $k => $v) {
        if ($v === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            ff_smoke_rr_set($k, (string) $v);
        }
    }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "refund_receipt_push_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total ? 0 : 1);
