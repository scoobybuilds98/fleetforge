<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_credit_app_unapply.php
 *
 * Smoke for F27 / S-QBO-CREDIT-APP-UNAPPLY — credit-application un-apply. The
 * crux is the apply→reverse counter round-trip: CreditApplicationReversal::
 * reverse() must restore the exact pre-apply state across all 5 counters. Also
 * covers CreditApplicationPusher::pushVoid + the Enqueuer void gate + the
 * unapply.php hook.
 *
 * Sub-check map:
 *   Module A — surfaces + schema
 *     C1 CreditApplicationReversal::reverse + ERR consts
 *     C2 credit_note_applications schema (status/reversed_at/reversed_by) +
 *        acc_qbo_credit_application_map push_status has 'voided'
 *     C3 CreditApplicationPusher::pushVoid + AutoEntryBridge::onCreditNoteUnapplied exist
 *
 *   Module B — reversal round-trip (financial crux)
 *     C4 credit note remaining + status restored (60→100, partially_used→active)
 *     C5 invoice credits_applied + balance_due + status restored (40→0, 160→200, →sent)
 *     C6 customer outstanding_balance restored (+40)
 *     C7 application marked reversed + reversed_at set
 *     C8 second reverse() throws ERR_ALREADY_REVERSED
 *     C9 reverse() on unknown id throws ERR_NOT_FOUND
 *     C10 voided-parent edge: credit left untouched, invoice+customer still reversed
 *
 *   Module C — pushVoid + Enqueuer + hook
 *     C11 pushVoid skipped_unmapped_void (no map row)
 *     C12 pushVoid already_voided (push_status='voided')
 *     C13 pushVoid skipped_by_mode (sync_mode disabled)
 *     C14 Enqueuer gate-0: 'void' rejected when status='applied'
 *     C15 Enqueuer gate-3: 'update' rejected; 'void' on reversed app inserts
 *     C16 unapply.php wires CreditApplicationReversal + enqueue('void')
 *
 * accounting.enabled forced off so the reversing GL JE is a no-op (hermetic).
 * Sentinel IDs 999990-999999; cleaned in finally.
 *
 * @session S-QBO-CREDIT-APP-UNAPPLY (F27)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\CreditApplicationReversal;
use FleetForge\QboPushers\CreditApplicationPusher;
use FleetForge\QboPushers\CreditApplicationEnqueuer;

$pass = 0; $total = 16; $failures = [];

function ff_smoke_ua_set(string $key, string $value): void {
    db_execute("INSERT INTO settings (`key`,`value`,`value_type`,`group_name`,`is_public`,`is_sensitive`) VALUES (?,?,'string','quickbooks',0,0) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$key,$value]);
}
function ff_smoke_ua_get(string $key): ?string { $r = db_row("SELECT `value` FROM settings WHERE `key`=?", [$key]); return $r['value'] ?? null; }

function ff_smoke_ua_cleanup(): void {
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='credit_application' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='credit_application' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_credit_application_map WHERE ff_credit_application_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM credit_note_applications WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM credit_notes WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM invoices WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM customers WHERE id BETWEEN 999990 AND 999999");
}

$snapKeys = ['accounting.enabled','quickbooks.sync_enabled','quickbooks.sync_mode.credit_application'];
$snap = []; foreach ($snapKeys as $k) { $snap[$k] = ff_smoke_ua_get($k); }

$invCols = "(id, invoice_number, customer_id, currency, status, billing_period_start, billing_period_end, billing_period_days, billing_type, invoice_date, due_date, total_amount, amount_paid, credits_applied, balance_due, created_at)";

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-CREDIT-APP-UNAPPLY Smoke ({$total} sub-checks; F27)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_ua_cleanup();
    ff_smoke_ua_set('accounting.enabled', '0'); // neutralize the GL JE
    ff_smoke_ua_set('quickbooks.sync_enabled', '1');
    ff_smoke_ua_set('quickbooks.sync_mode.credit_application', 'sync');

    // ── Module A ──────────────────────────────────────────────────────────
    $c1 = method_exists(CreditApplicationReversal::class,'reverse')
       && defined('FleetForge\CreditApplicationReversal::ERR_NOT_FOUND')
       && defined('FleetForge\CreditApplicationReversal::ERR_ALREADY_REVERSED');
    if ($c1) { echo "PASS C1 CreditApplicationReversal::reverse + ERR consts\n"; $pass++; } else { echo "FAIL C1\n"; $failures[]='C1'; }

    $cols = array_column(db_select("SHOW COLUMNS FROM credit_note_applications"),'Field');
    $mapDef = db_row("SHOW CREATE TABLE acc_qbo_credit_application_map")['Create Table'] ?? '';
    $c2 = in_array('status',$cols,true) && in_array('reversed_at',$cols,true) && in_array('reversed_by',$cols,true) && strpos($mapDef,"'voided'")!==false;
    if ($c2) { echo "PASS C2 schema (status/reversed_at/reversed_by + map voided)\n"; $pass++; } else { echo "FAIL C2 cols=".implode(',',$cols)."\n"; $failures[]='C2'; }

    $c3 = method_exists(CreditApplicationPusher::class,'pushVoid') && method_exists('FleetForge\Accounting\AutoEntryBridge','onCreditNoteUnapplied');
    if ($c3) { echo "PASS C3 pushVoid + onCreditNoteUnapplied exist\n"; $pass++; } else { echo "FAIL C3\n"; $failures[]='C3'; }

    // ── Module B — round-trip ─────────────────────────────────────────────
    // Seed POST-apply state (40 of a 100 credit applied to a 200 invoice).
    db_execute("INSERT INTO customers (id, company_name, currency, outstanding_balance, created_at) VALUES (999990,'Smoke UA Cust','CAD',160.00,NOW())");
    db_execute("INSERT INTO credit_notes (id, credit_note_number, customer_id, source, amount, currency, amount_remaining, status, reason, created_at) VALUES (999991,'CN-UA-999991',999990,'other',100.00,'CAD',60.00,'partially_used','smoke',NOW())");
    db_execute("INSERT INTO invoices {$invCols} VALUES (999992,'INV-UA-999992',999990,'CAD','partially_paid','2026-04-01','2026-04-30',30,'full_month','2026-04-01','2027-01-01',200.00,0,40.00,160.00,NOW())");
    db_execute("INSERT INTO credit_note_applications (id, credit_note_id, invoice_id, amount_applied, status, applied_by, applied_at) VALUES (999993,999991,999992,40.00,'applied',NULL,NOW())");

    $res = CreditApplicationReversal::reverse(999993, null);

    $cnAfter = db_row("SELECT amount_remaining, status FROM credit_notes WHERE id=999991");
    if (bccomp((string)$cnAfter['amount_remaining'],'100.00',2)===0 && $cnAfter['status']==='active') { echo "PASS C4 credit restored (100.00/active)\n"; $pass++; }
    else { echo "FAIL C4 ".json_encode($cnAfter)."\n"; $failures[]='C4'; }

    $invAfter = db_row("SELECT credits_applied, balance_due, status FROM invoices WHERE id=999992");
    if (bccomp((string)$invAfter['credits_applied'],'0.00',2)===0 && bccomp((string)$invAfter['balance_due'],'200.00',2)===0 && $invAfter['status']==='sent') { echo "PASS C5 invoice restored (0/200/sent)\n"; $pass++; }
    else { echo "FAIL C5 ".json_encode($invAfter)."\n"; $failures[]='C5'; }

    $custAfter = db_row("SELECT outstanding_balance FROM customers WHERE id=999990");
    if (bccomp((string)$custAfter['outstanding_balance'],'200.00',2)===0) { echo "PASS C6 customer OB restored (160+40=200)\n"; $pass++; }
    else { echo "FAIL C6 ".json_encode($custAfter)."\n"; $failures[]='C6'; }

    $appAfter = db_row("SELECT status, reversed_at FROM credit_note_applications WHERE id=999993");
    if (($appAfter['status']??'')==='reversed' && !empty($appAfter['reversed_at'])) { echo "PASS C7 application marked reversed\n"; $pass++; }
    else { echo "FAIL C7 ".json_encode($appAfter)."\n"; $failures[]='C7'; }

    try { CreditApplicationReversal::reverse(999993, null); echo "FAIL C8 should throw already_reversed\n"; $failures[]='C8'; }
    catch (\RuntimeException $e) { if ($e->getMessage()===CreditApplicationReversal::ERR_ALREADY_REVERSED) { echo "PASS C8 second reverse → already_reversed\n"; $pass++; } else { echo "FAIL C8 wrong: ".$e->getMessage()."\n"; $failures[]='C8'; } }

    try { CreditApplicationReversal::reverse(999900, null); echo "FAIL C9 should throw not_found\n"; $failures[]='C9'; }
    catch (\RuntimeException $e) { if ($e->getMessage()===CreditApplicationReversal::ERR_NOT_FOUND) { echo "PASS C9 unknown id → not_found\n"; $pass++; } else { echo "FAIL C9 wrong: ".$e->getMessage()."\n"; $failures[]='C9'; } }

    // C10 — voided-parent edge.
    db_execute("UPDATE credit_notes SET status='void', amount_remaining=0.00 WHERE id=999991");
    db_execute("INSERT INTO credit_note_applications (id, credit_note_id, invoice_id, amount_applied, status, applied_by, applied_at) VALUES (999994,999991,999992,25.00,'applied',NULL,NOW())");
    // Re-baseline invoice + customer for this second application.
    db_execute("UPDATE invoices SET credits_applied=25.00, balance_due=175.00, status='partially_paid' WHERE id=999992");
    db_execute("UPDATE customers SET outstanding_balance=175.00 WHERE id=999990");
    $res10 = CreditApplicationReversal::reverse(999994, null);
    $cnV = db_row("SELECT amount_remaining, status FROM credit_notes WHERE id=999991");
    $invV = db_row("SELECT credits_applied, balance_due FROM invoices WHERE id=999992");
    $custV = db_row("SELECT outstanding_balance FROM customers WHERE id=999990");
    if (($res10['credit_untouched_voided']??false)===true
        && $cnV['status']==='void' && bccomp((string)$cnV['amount_remaining'],'0.00',2)===0
        && bccomp((string)$invV['credits_applied'],'0.00',2)===0 && bccomp((string)$invV['balance_due'],'200.00',2)===0
        && bccomp((string)$custV['outstanding_balance'],'200.00',2)===0) {
        echo "PASS C10 voided-parent: credit untouched, invoice+customer reversed\n"; $pass++;
    } else { echo "FAIL C10 res=".json_encode($res10)." cn=".json_encode($cnV)." inv=".json_encode($invV)." cust=".json_encode($custV)."\n"; $failures[]='C10'; }

    // ── Module C — pushVoid + Enqueuer ────────────────────────────────────
    $r11 = CreditApplicationPusher::pushVoid(999993);
    if (($r11['status']??'')==='skipped_unmapped_void') { echo "PASS C11 pushVoid skipped_unmapped_void (no map)\n"; $pass++; } else { echo "FAIL C11 ".($r11['status']??'null')."\n"; $failures[]='C11'; }

    db_execute("INSERT INTO acc_qbo_credit_application_map (ff_credit_application_id, ff_credit_note_id_snapshot, ff_invoice_id_snapshot, qbo_payment_id, qbo_sync_token, push_status) VALUES (999993,999991,999992,'QBO-PAY-UA','3','voided')");
    $r12 = CreditApplicationPusher::pushVoid(999993);
    if (($r12['status']??'')==='already_voided' && ($r12['qbo_id']??'')==='QBO-PAY-UA') { echo "PASS C12 pushVoid already_voided\n"; $pass++; } else { echo "FAIL C12 ".json_encode($r12)."\n"; $failures[]='C12'; }

    ff_smoke_ua_set('quickbooks.sync_mode.credit_application','disabled');
    $r13 = CreditApplicationPusher::pushVoid(999993);
    if (($r13['status']??'')==='skipped_by_mode') { echo "PASS C13 pushVoid skipped_by_mode\n"; $pass++; } else { echo "FAIL C13 ".($r13['status']??'null')."\n"; $failures[]='C13'; }
    ff_smoke_ua_set('quickbooks.sync_mode.credit_application','sync');
    db_execute("DELETE FROM acc_qbo_credit_application_map WHERE ff_credit_application_id=999993");

    // C14 — gate-0: 'void' rejected when application status='applied'.
    db_execute("INSERT INTO credit_note_applications (id, credit_note_id, invoice_id, amount_applied, status, applied_by, applied_at) VALUES (999995,999991,999992,10.00,'applied',NULL,NOW())");
    $ok14 = CreditApplicationEnqueuer::enqueue(999995,'void');
    if ($ok14===false) { echo "PASS C14 Enqueuer gate-0 rejects void on 'applied'\n"; $pass++; } else { echo "FAIL C14\n"; $failures[]='C14'; }

    // C15 — gate-3 rejects 'update'; 'void' on a 'reversed' app inserts.
    $ok15a = CreditApplicationEnqueuer::enqueue(999993,'update'); // 999993 is reversed; update not whitelisted
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='credit_application' AND entity_id=999993");
    $ok15b = CreditApplicationEnqueuer::enqueue(999993,'void');   // 999993 status=reversed → accepted
    $q15 = db_row("SELECT operation FROM acc_qbo_sync_queue WHERE entity_type='credit_application' AND entity_id=999993 ORDER BY id DESC LIMIT 1");
    if ($ok15a===false && $ok15b===true && ($q15['operation']??'')==='void') { echo "PASS C15 gate-3 rejects update; void on reversed inserts\n"; $pass++; }
    else { echo "FAIL C15 update=".var_export($ok15a,true)." void=".var_export($ok15b,true)." q=".json_encode($q15)."\n"; $failures[]='C15'; }

    // C16 — unapply.php wiring.
    $src = (string) file_get_contents(__DIR__.'/../api/v1/credit_notes/unapply.php');
    if (strpos($src,'CreditApplicationReversal::reverse')!==false && strpos($src,"CreditApplicationEnqueuer::enqueue")!==false && strpos($src,"'void'")!==false) { echo "PASS C16 unapply.php wires reversal + void enqueue\n"; $pass++; }
    else { echo "FAIL C16 unapply.php missing wiring\n"; $failures[]='C16'; }

} finally {
    ff_smoke_ua_cleanup();
    foreach ($snap as $k=>$v) { if ($v===null) { db_execute("DELETE FROM settings WHERE `key`=?",[$k]); } else { ff_smoke_ua_set($k,(string)$v); } }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "credit_app_unapply_smoke: {$pass}/{$total} ".($pass===$total?'PASS':'FAIL')."\n";
if (!empty($failures)) { echo "Failed: ".implode(', ',$failures)."\n"; }
echo "═══════════════════════════════════════════════════════════\n";
exit($pass===$total?0:1);
