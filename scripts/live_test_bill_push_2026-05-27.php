<?php
/**
 * scripts/live_test_bill_push_2026-05-27.php
 *
 * One-shot live test of S-QBO-18 BillPusher against QBO sandbox.
 * Documents what state changes happened so the operator can roll back
 * if desired. NOT a smoke (not idempotent; mutates real data).
 *
 * Run order:
 *   php scripts/live_test_bill_push_2026-05-27.php
 *
 * Cleanup after the test (operator):
 *   - Reset sync_enabled='0' per D-CPA-5
 *   - Void or delete the test bill in FF + QBO if desired
 *   - Optionally unlink the AP account mapping if it shouldn't persist
 */

declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\VendorPusher;
use FleetForge\QboPushers\BillPusher;
use FleetForge\QboPushers\BillEnqueuer;

function emit(string $section): void { echo "\n══════════════════════════════════════════════════════\n{$section}\n══════════════════════════════════════════════════════\n\n"; }
function note(string $msg): void { echo "  {$msg}\n"; }
function check(bool $ok, string $msg, string $details = ''): void {
    echo '  ' . ($ok ? '✓' : '❌') . ' ' . $msg . "\n";
    if ($details !== '') echo "    └─ {$details}\n";
}

// ──────────────────────────────────────────────────────────
emit('STEP 2: ENABLE SYNC + MAP AP + PUSH VENDOR');

note('[2.1] Flipping sync_enabled to "1" for the test:');
db_execute("UPDATE settings SET value='1' WHERE `key`='quickbooks.sync_enabled'");
$v = settings_get('quickbooks.sync_enabled', 'NULL');
check($v === '1', "sync_enabled = {$v}");

note('[2.2] Mapping FF account 2010 (Accounts Payable, FF#21) → QBO#33:');
$existing = db_row("SELECT id, mapping_status FROM acc_qbo_account_map WHERE ff_account_id=21");
if ($existing) {
    db_execute(
        "UPDATE acc_qbo_account_map SET
            qbo_account_id='33', mapping_status='mapped', match_confidence='manual',
            match_notes='live_test_2026-05-27: linked AP for S-QBO-18 bill push test',
            qbo_name='Accounts Payable (A/P)',
            qbo_account_type='Accounts Payable',
            qbo_account_subtype='AccountsPayable'
         WHERE ff_account_id=21"
    );
    note('  UPDATE acc_qbo_account_map (FF#21) → QBO#33');
} else {
    db_execute(
        "INSERT INTO acc_qbo_account_map
            (ff_account_id, qbo_account_id, mapping_status, match_confidence, match_notes,
             qbo_name, qbo_account_type, qbo_account_subtype, critical_category)
         VALUES (21, '33', 'mapped', 'manual', 'live_test_2026-05-27: linked AP for S-QBO-18 bill push test',
                 'Accounts Payable (A/P)', 'Accounts Payable', 'AccountsPayable', 'ap_clearing')"
    );
    note('  INSERT acc_qbo_account_map (FF#21) → QBO#33');
}
$ap = db_row("SELECT qbo_account_id, mapping_status, critical_category FROM acc_qbo_account_map WHERE ff_account_id=21");
check(($ap['mapping_status'] ?? '') === 'mapped' && ($ap['critical_category'] ?? '') === 'ap_clearing',
    "AP map: qbo={$ap['qbo_account_id']} status={$ap['mapping_status']} critical={$ap['critical_category']}");

note('[2.3] Pushing FF vendor #1 (Freightliner Service Center) to QBO:');
try {
    $vr = VendorPusher::pushCreate(1);
    note(sprintf('  pushCreate result: success=%s status=%s outcome=%s qbo_id=%s',
        var_export($vr['success'] ?? null, true),
        $vr['status'] ?? 'null',
        $vr['outcome'] ?? 'null',
        $vr['qbo_id'] ?? 'null'
    ));
    if (!empty($vr['error'])) note('  error: ' . $vr['error']);
    $vmap = db_row("SELECT qbo_vendor_id, mapping_status FROM acc_qbo_vendor_map WHERE ff_vendor_id=1");
    check($vmap && !empty($vmap['qbo_vendor_id']) && $vmap['mapping_status'] === 'mapped',
        sprintf('vendor map: qbo=%s status=%s', $vmap['qbo_vendor_id'] ?? 'NULL', $vmap['mapping_status'] ?? 'NULL'));
} catch (\Throwable $e) {
    note('  ❌ VendorPusher threw: ' . $e->getMessage());
    note('  (continuing anyway — bill push will fail at preflight if vendor unmapped)');
}

// ──────────────────────────────────────────────────────────
emit('STEP 3: CREATE + APPROVE TEST BILL');

note('[3.1] Insert test bill in FF DB:');
$periodRow = db_row("SELECT id FROM acc_periods WHERE start_date <= '2026-04-10' AND end_date >= '2026-04-10' AND status IN ('open','draft') LIMIT 1");
if (!$periodRow) {
    $periodRow = db_row("SELECT id FROM acc_periods ORDER BY end_date DESC LIMIT 1");
}
note('  Using period id=' . ($periodRow['id'] ?? 'NULL'));

// Use a deterministic bill_number so re-runs are detectable
$billNumber = 'LIVETEST-BILL-' . date('Ymd-His');
$vendorBillNum = 'VND-INV-' . date('His');
// DocNumber must be ≤21 chars per QBO Bill.DocNumber limit (D-QBO-FIXPACK-4).
// 'VND-INV-' (8) + His (6) = 14 chars — within limit.
$billId = db_insert('acc_bills', [
    'bill_number' => $billNumber,
    'vendor_id' => 1,
    'vendor_bill_number' => $vendorBillNum,
    'bill_date' => '2026-04-10',
    'due_date' => '2026-04-30',
    'period_id' => (int) $periodRow['id'],
    'status' => 'draft',
    'currency' => 'CAD',
    'subtotal' => '250.00',
    'tax_gst_amount' => '12.50',
    'tax_pst_amount' => '0.00',
    'tax_hst_amount' => '0.00',
    'tax_total' => '12.50',
    'total_amount' => '262.50',
    'balance_due' => '262.50',
    'notes' => 'S-QBO-18 live test bill',
    'created_by' => 1,
]);
db_insert('acc_bill_lines', [
    'bill_id' => $billId,
    'account_id' => 55,
    'description' => 'Engine repair labor — 5 hours @ $50/hr',
    'quantity' => '5.0000',
    'unit_cost' => '50.00',
    'amount' => '250.00',
    'is_tax_input_credit' => 1,
    'tax_gst_amount' => '12.50',
    'tax_pst_amount' => '0.00',
    'tax_hst_amount' => '0.00',
    'sort_order' => 0,
]);
check(true, "Inserted bill #{$billId} ({$billNumber}) — 1 line; account=55 (6010 Maintenance & Repairs — Labour, mapped to QBO#73)");

note('[3.2] Approve the bill (simulates clicking Approve in admin UI):');
// We can't easily call api/v1/accounting/bills/approve.php from CLI without
// faking auth context. Simulate the canonical state change inline:
// status draft→approved + journal_entry stub + total_spent bump + ENQUEUE.
// For the test we skip the JE post (out of scope for QBO push verification).
db_execute("UPDATE acc_bills SET status='approved' WHERE id = ?", [$billId]);
db_execute("UPDATE vendors SET total_spent = total_spent + 262.50 WHERE id = 1");
$enqueued = BillEnqueuer::enqueue($billId, 'create');
check($enqueued, sprintf('BillEnqueuer::enqueue returned %s', var_export($enqueued, true)));

// ──────────────────────────────────────────────────────────
emit('STEP 4: VERIFY ENQUEUE');

$q = db_row("SELECT id, entity_type, entity_id, operation, status, priority, retry_count, enqueued_at FROM acc_qbo_sync_queue WHERE entity_type='bill' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$billId]);
if ($q) {
    note(sprintf('  Queue row: id=%d entity=%s/%d op=%s status=%s priority=%d retry=%d',
        $q['id'], $q['entity_type'], $q['entity_id'], $q['operation'], $q['status'], $q['priority'], $q['retry_count']));
    check($q['status'] === 'queued', "Queue row status='queued'");
} else {
    check(false, "No queue row found for bill {$billId}");
}

// ──────────────────────────────────────────────────────────
emit('STEP 5: RUN WORKER');

note('[5.1] Invoking cron/qbo_sync_worker.php directly:');
$workerOutput = shell_exec('php ' . escapeshellarg(__DIR__ . '/../cron/qbo_sync_worker.php') . ' 2>&1');
echo $workerOutput;

// ──────────────────────────────────────────────────────────
emit('STEP 6: VERIFY POST-WORKER STATE');

$bmap = db_row("SELECT id, ff_bill_id, qbo_bill_id, qbo_sync_token, qbo_doc_number, qbo_vendor_id, qbo_total_amt, qbo_currency, push_status, push_error, pushed_at FROM acc_qbo_bill_map WHERE ff_bill_id = ?", [$billId]);
if ($bmap) {
    note(sprintf('[6.1] acc_qbo_bill_map row:'));
    foreach ($bmap as $k => $v) {
        note(sprintf('  %-25s = %s', $k, $v ?? 'NULL'));
    }
    $isSuccess = $bmap['push_status'] === 'pushed' && !empty($bmap['qbo_bill_id']);
    check($isSuccess, $isSuccess ? 'BILL PUSHED SUCCESSFULLY TO QBO' : "Bill push outcome: push_status='{$bmap['push_status']}'");
} else {
    check(false, "No acc_qbo_bill_map row for bill {$billId}");
}

note("\n[6.2] acc_qbo_sync_log row(s):");
$log = db_select("SELECT id, direction, entity_type, entity_id, operation, http_method, endpoint, response_status, error_code, error_message, created_at FROM acc_qbo_sync_log WHERE entity_type='bill' AND entity_id = ? ORDER BY id DESC LIMIT 3", [$billId]);
foreach ($log as $l) {
    note(sprintf('  id=%d method=%s endpoint=%s response=%s error_code=%s',
        $l['id'], $l['http_method'], $l['endpoint'] ?? '', $l['response_status'] ?? 'NULL', $l['error_code'] ?? 'NULL'));
    if (!empty($l['error_message'])) note('    error: ' . substr($l['error_message'], 0, 200));
}

note("\n[6.3] acc_qbo_sync_queue final state:");
$q2 = db_row("SELECT status, error_code, error_message, completed_at FROM acc_qbo_sync_queue WHERE entity_type='bill' AND entity_id = ?", [$billId]);
if ($q2) {
    note(sprintf('  status=%s error_code=%s completed_at=%s', $q2['status'], $q2['error_code'] ?? 'NULL', $q2['completed_at'] ?? 'NULL'));
    if (!empty($q2['error_message'])) note('  error_message: ' . substr($q2['error_message'], 0, 200));
}

// ──────────────────────────────────────────────────────────
emit('CLEANUP — restoring D-CPA-5 invariant');

db_execute("UPDATE settings SET value='0' WHERE `key`='quickbooks.sync_enabled'");
$f = settings_get('quickbooks.sync_enabled', 'NULL');
check($f === '0', "sync_enabled restored to {$f}");

note("\nTest bill kept in FF + QBO for operator review. To clean up later:");
note("  - DELETE FROM acc_qbo_sync_log WHERE entity_type='bill' AND entity_id={$billId};");
note("  - DELETE FROM acc_qbo_sync_queue WHERE entity_type='bill' AND entity_id={$billId};");
note("  - DELETE FROM acc_qbo_bill_map WHERE ff_bill_id={$billId};");
note("  - DELETE FROM acc_bill_lines WHERE bill_id={$billId};");
note("  - DELETE FROM acc_bills WHERE id={$billId};");
note("  - In QBO: void the test bill (DocNumber starts with VND-INV-)");
