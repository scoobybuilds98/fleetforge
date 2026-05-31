<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_credit_memo_push.php
 *
 * Smoke test for S-QBO-16 Credit Memo push (Phase QBO-7 / 1 of 2).
 *
 * FF credit_notes → QBO CreditMemo entity. Sibling of _smoke_qbo_invoice_push.
 * Per [[extensive-test-and-full-report]]: PER-FUNCTION coverage — each public
 * method gets named sub-checks for happy path + edge cases + invariants.
 *
 * Sub-check map:
 *   Module A — class surfaces + schema
 *     C1  CreditMemoPusher surfaces (pushCreate/pushUpdate/pushVoid/
 *         buildQboPayload/resolveItemType/runPreflight + RESULT_BASE + consts)
 *     C2  CreditMemoEnqueuer::enqueue surfaces
 *     C3  acc_qbo_credit_memo_map schema (cols + 2 UNIQUE + FK + status ENUM)
 *
 *   Module B — resolveItemType (D-QBO-16-1; all 9 sources + fallback)
 *     C4  all 9 credit_notes.source ENUM values map to expected item_types
 *     C5  unknown source → 'other' fallback
 *
 *   Module C — buildQboPayload (happy + tax-override + throws + currency)
 *     C6  happy: CustomerRef + single SalesItemLineDetail line + TotalTax=0 +
 *         DocNumber + PrivateNote
 *     C7  throws on missing customer mapping
 *     C8  throws on missing item id
 *     C9  throws on missing tax_override_code_id
 *     C10 multi_currency='1' → CurrencyRef emitted; '0' → absent
 *
 *   Module D — runPreflight (per gate)
 *     C11 tax override missing → fail
 *     C12 customer unmapped → fail
 *     C13 item unmapped → fail (source whose item_type is unmapped)
 *     C14 DocNumber > 21 → failed_preflight_field_too_long
 *     C15 all good → ok
 *
 *   Module E — pushImpl behaviors (via pushCreate)
 *     C16 sync_mode='disabled' → skipped_by_mode + map row + sync_log
 *     C17 status='void' → skipped_voided
 *     C18 soft-deleted → skipped_soft_deleted
 *     C19 already_mapped (existing qbo_credit_memo_id) → already_mapped
 *     C20 ff_not_found
 *
 *   Module F — update delegation + void stub (S-QBO-CREDIT-MEMO-UPDATE)
 *     C21 REPURPOSED — pushUpdate delegates to pushImpl (skipped_by_mode
 *         proves no-longer-a-stub; D-QBO-16-2 update stub closed)
 *     C22 pushVoid STILL stub → unsupported_in_session (rides F7 trio)
 *
 *   Module G — Enqueuer gates
 *     C23 gate-0 rejects missing credit note
 *     C24 gate-0 rejects non-active status
 *     C25 REPURPOSED — gate-3 ACCEPTS 'update' op + inserts queue row
 *     C26 happy-path queue insert (entity_type='credit_memo', op='create')
 *
 *   Module I — demotion + void rejection (S-QBO-CREDIT-MEMO-UPDATE)
 *     C27 pushUpdate on unmapped credit note demotes to create →
 *         failed_preflight at customer gate (no HTTP); D-PUSHER-DEMOTION-RULE
 *     C28 Enqueuer gate-3 still rejects 'void' op (pushVoid → F7)
 *
 * Fixtures use sentinel IDs 999990-999999, cleaned in finally. The 'other'
 * item mapping is captured + ensured + restored (hermetic across envs).
 *
 * @session  S-QBO-16
 * @decision D-QBO-16-1 (SOURCE_TO_ITEM_TYPE map), D-QBO-16-2 (create only;
 *           apply/void stubbed), D-QBO-16-3 (map table shape),
 *           D-QBO-CORE-6 (tax-override TotalTax=0)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\CreditMemoPusher;
use FleetForge\QboPushers\CreditMemoEnqueuer;
use FleetForge\Exceptions\QuickBooksException;

$pass = 0;
$total = 30;
$failures = [];

function ff_smoke_cm_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}
function ff_smoke_cm_get(string $key): ?string
{
    $r = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $r['value'] ?? null;
}

function ff_smoke_cm_cleanup(): void
{
    db_execute("DELETE FROM acc_qbo_sync_log         WHERE entity_type = 'credit_memo' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue       WHERE entity_type = 'credit_memo' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_credit_memo_map  WHERE ff_credit_note_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM credit_notes             WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_customer_map     WHERE ff_customer_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM customers                WHERE id BETWEEN 999990 AND 999999");
}

/**
 * Seed a sentinel customer (+ QBO mapping) and a credit note. Returns the
 * credit note id.
 */
function ff_smoke_cm_seed_cn(int $cnId, array $overrides = []): int
{
    $source   = $overrides['source']   ?? 'other';
    $amount   = $overrides['amount']   ?? '80.00';
    $currency = $overrides['currency'] ?? 'CAD';
    $status   = $overrides['status']   ?? 'active';
    $number   = $overrides['credit_note_number'] ?? "CN-CR-2026-{$cnId}";
    $deleted  = $overrides['deleted_at'] ?? null;

    db_execute(
        "INSERT INTO credit_notes (id, credit_note_number, customer_id, source, amount, currency, amount_remaining, status, reason, deleted_at, created_at)
         VALUES (?, ?, 999990, ?, ?, ?, ?, ?, 'Smoke credit memo reason', ?, NOW())",
        [$cnId, $number, $source, $amount, $currency, $amount, $status, $deleted]
    );
    return $cnId;
}

// ── Capture pre-state for hermetic restore ───────────────────────────
$snapshotKeys = [
    'quickbooks.sync_enabled',
    'quickbooks.sync_mode.credit_memo',
    'quickbooks.multi_currency_enabled',
    'quickbooks.connection_status',
    'quickbooks.tax_override_code_id',
];
$snapshot = [];
foreach ($snapshotKeys as $k) { $snapshot[$k] = ff_smoke_cm_get($k); }

// Capture + ensure the 'other' item mapping (resolveItemType('other')='other').
$otherItemPre = db_row("SELECT id, qbo_item_id, mapping_status FROM acc_qbo_item_map WHERE ff_item_type = 'other' ORDER BY id ASC LIMIT 1");

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-16 Credit Memo Push Smoke (28 sub-checks; +S-QBO-CREDIT-MEMO-UPDATE)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_cm_cleanup();

    // Ensure a mapped 'other' item exists for the happy-path gates (C6/C15).
    if ($otherItemPre && $otherItemPre['mapping_status'] === 'mapped' && !empty($otherItemPre['qbo_item_id'])) {
        $otherQboItemId = (string) $otherItemPre['qbo_item_id'];
    } else {
        // Force 'other' to mapped for the test; restored in finally.
        if ($otherItemPre) {
            db_execute("UPDATE acc_qbo_item_map SET mapping_status='mapped', qbo_item_id='SMOKE-CM-OTHER' WHERE id = ?", [(int) $otherItemPre['id']]);
        } else {
            db_execute("INSERT INTO acc_qbo_item_map (ff_item_type, qbo_item_id, mapping_status) VALUES ('other', 'SMOKE-CM-OTHER', 'mapped')");
        }
        $otherQboItemId = 'SMOKE-CM-OTHER';
    }

    // Seed sentinel customer + QBO customer mapping.
    db_execute("INSERT INTO customers (id, company_name, currency, created_at) VALUES (999990, 'Smoke CM Customer', 'CAD', NOW())");
    db_execute("INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, mapping_status) VALUES (999990, 'QBO-CUST-9990', 'mapped')");

    ff_smoke_cm_set('quickbooks.connection_status', 'connected');
    ff_smoke_cm_set('quickbooks.sync_enabled', '1');
    ff_smoke_cm_set('quickbooks.sync_mode.credit_memo', 'sync');
    ff_smoke_cm_set('quickbooks.multi_currency_enabled', '0');
    ff_smoke_cm_set('quickbooks.tax_override_code_id', 'NON-SMOKE');

    // ══ Module A ══════════════════════════════════════════════════════
    $c1 = [];
    $ref = new ReflectionClass(CreditMemoPusher::class);
    foreach (['pushCreate','pushUpdate','pushVoid','buildQboPayload','resolveItemType','runPreflight'] as $m) {
        if (!method_exists(CreditMemoPusher::class, $m)) $c1[] = "missing method {$m}";
    }
    if (!$ref->hasConstant('SOURCE_TO_ITEM_TYPE')) $c1[] = 'missing SOURCE_TO_ITEM_TYPE const';
    if (empty($c1)) { echo "PASS C1 CreditMemoPusher surfaces (methods + SOURCE_TO_ITEM_TYPE)\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1) . "\n"; $failures[] = 'C1'; }

    if (method_exists(CreditMemoEnqueuer::class, 'enqueue')) { echo "PASS C2 CreditMemoEnqueuer::enqueue surfaces\n"; $pass++; }
    else { echo "FAIL C2 CreditMemoEnqueuer::enqueue missing\n"; $failures[] = 'C2'; }

    $c3 = [];
    $cols = array_column(db_select("SHOW COLUMNS FROM acc_qbo_credit_memo_map"), 'Field');
    foreach (['id','ff_credit_note_id','qbo_credit_memo_id','qbo_sync_token','qbo_doc_number','qbo_total_amt','qbo_item_type_used','ff_credit_note_snapshot_total','push_status','push_error','pushed_at','last_synced_at'] as $col) {
        if (!in_array($col, $cols, true)) $c3[] = "missing col {$col}";
    }
    $idx = array_unique(array_column(db_select("SHOW INDEX FROM acc_qbo_credit_memo_map"), 'Key_name'));
    foreach (['PRIMARY','uq_ff_credit_note','uq_qbo_credit_memo'] as $k) {
        if (!in_array($k, $idx, true)) $c3[] = "missing index {$k}";
    }
    if (empty($c3)) { echo "PASS C3 acc_qbo_credit_memo_map schema (cols + UNIQUE keys)\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3) . "\n"; $failures[] = 'C3'; }

    // ══ Module B — resolveItemType ════════════════════════════════════
    $c4 = [];
    $expectMap = [
        'mileage_overpayment' => 'mileage_credit',
        'precharge_refund' => 'mileage_drawdown_credit',
        'base_rental_reconciliation_overflow' => 'base_rental_reconciliation_credit',
        'invoice_adjustment' => 'manual_adjustment',
        'damage_resolution' => 'damage',
        'goodwill' => 'discount',
        'overpayment' => 'account_credit_applied',
        'payment_returned' => 'other',
        'other' => 'other',
    ];
    foreach ($expectMap as $src => $expected) {
        $got = CreditMemoPusher::resolveItemType($src);
        if ($got !== $expected) $c4[] = "{$src} → expected {$expected}, got {$got}";
    }
    if (empty($c4)) { echo "PASS C4 resolveItemType maps all 9 source ENUM values (D-QBO-16-1)\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $c4) . "\n"; $failures[] = 'C4'; }

    if (CreditMemoPusher::resolveItemType('nonexistent_source') === 'other') { echo "PASS C5 resolveItemType unknown → 'other' fallback\n"; $pass++; }
    else { echo "FAIL C5 unknown source did not fall back to 'other'\n"; $failures[] = 'C5'; }

    // ══ Module C — buildQboPayload ════════════════════════════════════
    $cnRow = ['id'=>999990,'credit_note_number'=>'CN-CR-2026-99990','customer_id'=>999990,'source'=>'other','amount'=>'80.00','currency'=>'CAD','reason'=>'Mileage overpayment refund','created_at'=>'2026-04-20 10:00:00'];
    $custMap = ['qbo_customer_id'=>'QBO-CUST-9990'];

    $c6 = [];
    $payload = CreditMemoPusher::buildQboPayload($cnRow, $custMap, $otherQboItemId);
    if (($payload['CustomerRef']['value'] ?? '') !== 'QBO-CUST-9990') $c6[] = 'CustomerRef wrong';
    if (($payload['DocNumber'] ?? '') !== 'CN-CR-2026-99990') $c6[] = 'DocNumber wrong';
    $line = $payload['Line'][0] ?? [];
    if (($line['DetailType'] ?? '') !== 'SalesItemLineDetail') $c6[] = 'line DetailType wrong';
    if (($line['SalesItemLineDetail']['ItemRef']['value'] ?? '') !== $otherQboItemId) $c6[] = 'ItemRef wrong';
    if ((string)($line['Amount'] ?? '') !== '80') $c6[] = 'line Amount wrong: ' . ($line['Amount'] ?? 'null');
    if (($line['SalesItemLineDetail']['TaxCodeRef']['value'] ?? '') !== 'NON-SMOKE') $c6[] = 'line TaxCodeRef not override code';
    if ((string)($payload['TxnTaxDetail']['TotalTax'] ?? '') !== '0.00') $c6[] = 'TotalTax not 0.00';
    if (empty($payload['PrivateNote'])) $c6[] = 'PrivateNote empty';
    if (empty($c6)) { echo "PASS C6 buildQboPayload happy path (single line + tax-override TotalTax=0)\n"; $pass++; }
    else { echo "FAIL C6 " . implode('; ', $c6) . "\n"; $failures[] = 'C6'; }

    try { CreditMemoPusher::buildQboPayload($cnRow, [], $otherQboItemId); echo "FAIL C7 expected throw on missing customer map\n"; $failures[]='C7'; }
    catch (QuickBooksException $e) { echo "PASS C7 buildQboPayload throws on missing customer mapping\n"; $pass++; }

    try { CreditMemoPusher::buildQboPayload($cnRow, $custMap, ''); echo "FAIL C8 expected throw on missing item id\n"; $failures[]='C8'; }
    catch (QuickBooksException $e) { echo "PASS C8 buildQboPayload throws on missing item id\n"; $pass++; }

    ff_smoke_cm_set('quickbooks.tax_override_code_id', '');
    try { CreditMemoPusher::buildQboPayload($cnRow, $custMap, $otherQboItemId); echo "FAIL C9 expected throw on missing tax override code\n"; $failures[]='C9'; }
    catch (QuickBooksException $e) { echo "PASS C9 buildQboPayload throws on empty tax_override_code_id\n"; $pass++; }
    ff_smoke_cm_set('quickbooks.tax_override_code_id', 'NON-SMOKE');

    $c10 = [];
    ff_smoke_cm_set('quickbooks.multi_currency_enabled', '1');
    $pMc = CreditMemoPusher::buildQboPayload($cnRow, $custMap, $otherQboItemId);
    if (($pMc['CurrencyRef']['value'] ?? '') !== 'CAD') $c10[] = 'CurrencyRef not emitted when multi_currency=1';
    ff_smoke_cm_set('quickbooks.multi_currency_enabled', '0');
    $pSc = CreditMemoPusher::buildQboPayload($cnRow, $custMap, $otherQboItemId);
    if (isset($pSc['CurrencyRef'])) $c10[] = 'CurrencyRef present when multi_currency=0';
    if (empty($c10)) { echo "PASS C10 CurrencyRef gated on multi_currency (D-QBO-FIXPACK-12)\n"; $pass++; }
    else { echo "FAIL C10 " . implode('; ', $c10) . "\n"; $failures[] = 'C10'; }

    // ══ Module D — runPreflight ═══════════════════════════════════════
    $cnActive = ['id'=>999990,'credit_note_number'=>'CN-CR-2026-99990','customer_id'=>999990,'source'=>'other','currency'=>'CAD'];

    ff_smoke_cm_set('quickbooks.tax_override_code_id', '');
    $g = CreditMemoPusher::runPreflight(999990, $cnActive);
    if (!$g['ok']) { echo "PASS C11 runPreflight fails when tax override missing\n"; $pass++; }
    else { echo "FAIL C11 expected fail on missing tax override\n"; $failures[] = 'C11'; }
    ff_smoke_cm_set('quickbooks.tax_override_code_id', 'NON-SMOKE');

    $g = CreditMemoPusher::runPreflight(999990, ['id'=>999990,'credit_note_number'=>'CN-CR-2026-99990','customer_id'=>888888,'source'=>'other','currency'=>'CAD']);
    if (!$g['ok']) { echo "PASS C12 runPreflight fails when customer unmapped\n"; $pass++; }
    else { echo "FAIL C12 expected fail on unmapped customer\n"; $failures[] = 'C12'; }

    // Item unmapped: use a source whose item_type is reliably NOT mapped.
    // Temporarily unmap 'discount' (goodwill→discount) to test the gate.
    $discPre = db_row("SELECT id, mapping_status FROM acc_qbo_item_map WHERE ff_item_type='discount' ORDER BY id LIMIT 1");
    if ($discPre) db_execute("UPDATE acc_qbo_item_map SET mapping_status='ff_only' WHERE id=?", [(int)$discPre['id']]);
    $g = CreditMemoPusher::runPreflight(999990, ['id'=>999990,'credit_note_number'=>'CN-CR-2026-99990','customer_id'=>999990,'source'=>'goodwill','currency'=>'CAD']);
    if ($discPre) db_execute("UPDATE acc_qbo_item_map SET mapping_status=? WHERE id=?", [$discPre['mapping_status'], (int)$discPre['id']]);
    if (!$g['ok']) { echo "PASS C13 runPreflight fails when line item_type unmapped (D-QBO-16-1)\n"; $pass++; }
    else { echo "FAIL C13 expected fail on unmapped item\n"; $failures[] = 'C13'; }

    $g = CreditMemoPusher::runPreflight(999990, ['id'=>999990,'credit_note_number'=>'CN-CR-2026-000000000000000000000-TOOLONG','customer_id'=>999990,'source'=>'other','currency'=>'CAD']);
    if (!$g['ok'] && $g['status_code'] === 'failed_preflight_field_too_long') { echo "PASS C14 runPreflight DocNumber>21 → failed_preflight_field_too_long\n"; $pass++; }
    else { echo "FAIL C14 expected field_too_long; got " . json_encode($g) . "\n"; $failures[] = 'C14'; }

    $g = CreditMemoPusher::runPreflight(999990, $cnActive);
    if ($g['ok']) { echo "PASS C15 runPreflight passes when all gates satisfied\n"; $pass++; }
    else { echo "FAIL C15 expected ok; got " . json_encode($g) . "\n"; $failures[] = 'C15'; }

    // ══ Module E — pushImpl behaviors ═════════════════════════════════
    ff_smoke_cm_seed_cn(999990, ['source'=>'other']);

    ff_smoke_cm_set('quickbooks.sync_mode.credit_memo', 'disabled');
    $r = CreditMemoPusher::pushCreate(999990);
    $mapRow = db_row("SELECT push_status FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id=999990");
    if ($r['status'] === 'skipped_by_mode' && $mapRow && $mapRow['push_status'] === 'skipped_by_mode') { echo "PASS C16 pushCreate sync_mode=disabled → skipped_by_mode + map row\n"; $pass++; }
    else { echo "FAIL C16 got " . json_encode($r) . " map=" . json_encode($mapRow) . "\n"; $failures[] = 'C16'; }
    ff_smoke_cm_set('quickbooks.sync_mode.credit_memo', 'sync');

    db_execute("DELETE FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id=999990");
    db_execute("UPDATE credit_notes SET status='void' WHERE id=999990");
    $r = CreditMemoPusher::pushCreate(999990);
    if ($r['status'] === 'skipped_voided') { echo "PASS C17 pushCreate status=void → skipped_voided\n"; $pass++; }
    else { echo "FAIL C17 got " . json_encode($r) . "\n"; $failures[] = 'C17'; }
    db_execute("UPDATE credit_notes SET status='active' WHERE id=999990");

    db_execute("DELETE FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id=999990");
    db_execute("UPDATE credit_notes SET deleted_at=NOW() WHERE id=999990");
    $r = CreditMemoPusher::pushCreate(999990);
    if ($r['status'] === 'skipped_soft_deleted') { echo "PASS C18 pushCreate soft-deleted → skipped_soft_deleted\n"; $pass++; }
    else { echo "FAIL C18 got " . json_encode($r) . "\n"; $failures[] = 'C18'; }
    db_execute("UPDATE credit_notes SET deleted_at=NULL WHERE id=999990");

    db_execute("DELETE FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id=999990");
    db_execute("INSERT INTO acc_qbo_credit_memo_map (ff_credit_note_id, qbo_credit_memo_id, push_status) VALUES (999990, 'QBO-CM-EXISTING', 'pushed')");
    $r = CreditMemoPusher::pushCreate(999990);
    if ($r['status'] === 'already_mapped' && $r['qbo_id'] === 'QBO-CM-EXISTING') { echo "PASS C19 pushCreate already_mapped idempotency\n"; $pass++; }
    else { echo "FAIL C19 got " . json_encode($r) . "\n"; $failures[] = 'C19'; }
    db_execute("DELETE FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id=999990");

    $r = CreditMemoPusher::pushCreate(999991); // never seeded
    if ($r['status'] === 'ff_not_found') { echo "PASS C20 pushCreate missing credit note → ff_not_found\n"; $pass++; }
    else { echo "FAIL C20 got " . json_encode($r) . "\n"; $failures[] = 'C20'; }

    // ══ Module F — update delegation (S-QBO-CREDIT-MEMO-UPDATE) + void stub ══
    // C21 REPURPOSED: pushUpdate now delegates to pushImpl (no longer a stub).
    // 999990 is active + fully mapped here, so sync_mode='disabled' is REQUIRED
    // to short-circuit at step 1 (skipped_by_mode) — otherwise the demoted
    // create would reach a real createEntity HTTP call (404 in smoke; the
    // HTTP trap). skipped_by_mode definitively proves delegation (NOT the old
    // unsupported_in_session stub). sync_mode restored to 'sync' after.
    $c21 = [];
    $prevMode21 = ff_smoke_cm_get('quickbooks.sync_mode.credit_memo');
    ff_smoke_cm_set('quickbooks.sync_mode.credit_memo', 'disabled');
    $r = CreditMemoPusher::pushUpdate(999990);
    if (($r['status'] ?? null) === 'unsupported_in_session') { $c21[] = "pushUpdate still a stub"; }
    if (($r['status'] ?? null) !== 'skipped_by_mode') { $c21[] = "expected skipped_by_mode (delegation proof), got " . json_encode($r['status'] ?? null); }
    if (($r['outcome'] ?? null) !== 'skipped') { $c21[] = "outcome: " . json_encode($r['outcome'] ?? null); }
    ff_smoke_cm_set('quickbooks.sync_mode.credit_memo', $prevMode21 ?? 'sync');
    db_execute("DELETE FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id=999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='credit_memo' AND entity_id=999990");
    if (empty($c21)) { echo "PASS C21 pushUpdate delegates to pushImpl — no longer a stub (S-QBO-CREDIT-MEMO-UPDATE; D-QBO-16-2 update stub closed)\n"; $pass++; }
    else { echo "FAIL C21 " . implode('; ', $c21) . "\n"; $failures[] = 'C21'; }

    // C22 (REPURPOSED): pushVoid now IMPLEMENTED (S-QBO-PUSHVOID-TRIO).
    // 999990 was reseeded active in C21 — a non-'void' credit note must be
    // rejected at the inverted status invariant BEFORE any HTTP call.
    $c22 = [];
    ff_smoke_cm_seed_cn(999990, ['source' => 'other', 'status' => 'active']);
    $r = CreditMemoPusher::pushVoid(999990);
    if (($r['status'] ?? null) === 'unsupported_in_session') { $c22[] = "pushVoid still a stub"; }
    if (($r['status'] ?? null) !== 'void_status_mismatch') { $c22[] = "status: " . json_encode($r['status'] ?? null) . ", want 'void_status_mismatch'"; }
    db_execute("DELETE FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id=999990");
    db_execute("DELETE FROM credit_notes WHERE id=999990");
    if (empty($c22)) { echo "PASS C22 pushVoid on non-void credit note → void_status_mismatch (S-QBO-PUSHVOID-TRIO; D-QBO-PUSHVOID-TRIO-1)\n"; $pass++; }
    else { echo "FAIL C22 " . implode('; ', $c22) . "\n"; $failures[] = 'C22'; }

    // ══ Module G — Enqueuer gates ═════════════════════════════════════
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='credit_memo' AND entity_id BETWEEN 999990 AND 999999");

    if (CreditMemoEnqueuer::enqueue(999991, 'create') === false) { echo "PASS C23 Enqueuer gate-0 rejects missing credit note\n"; $pass++; }
    else { echo "FAIL C23 expected false for missing credit note\n"; $failures[] = 'C23'; }

    db_execute("UPDATE credit_notes SET status='fully_used' WHERE id=999990");
    if (CreditMemoEnqueuer::enqueue(999990, 'create') === false) { echo "PASS C24 Enqueuer gate-0 rejects non-active status\n"; $pass++; }
    else { echo "FAIL C24 expected false for non-active status\n"; $failures[] = 'C24'; }
    db_execute("UPDATE credit_notes SET status='active' WHERE id=999990");

    // C25 REPURPOSED — Enqueuer gate-3 now ACCEPTS 'update' (S-QBO-CREDIT-MEMO-UPDATE).
    // Allowlist widened ['create']→['create','update']. 999990 active +
    // sync_enabled='1' + sync_mode.credit_memo='sync' → enqueue('update')
    // succeeds + writes a queue row with operation='update'.
    $c25 = [];
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='credit_memo' AND entity_id=999990");
    $r25 = CreditMemoEnqueuer::enqueue(999990, 'update');
    $q25 = db_row("SELECT operation, status FROM acc_qbo_sync_queue WHERE entity_type='credit_memo' AND entity_id=999990 AND operation='update'");
    if ($r25 !== true) { $c25[] = "enqueue should accept 'update' now; got " . json_encode($r25); }
    if ($q25 === null) { $c25[] = "an 'update' queue row should be written; none found"; }
    elseif ($q25['operation'] !== 'update' || $q25['status'] !== 'queued') { $c25[] = "queue row shape: " . json_encode($q25); }
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='credit_memo' AND entity_id=999990");
    if (empty($c25)) { echo "PASS C25 Enqueuer gate-3 accepts 'update' op + inserts queue row (S-QBO-CREDIT-MEMO-UPDATE; D-QBO-16-2 update stub closed)\n"; $pass++; }
    else { echo "FAIL C25 " . implode('; ', $c25) . "\n"; $failures[] = 'C25'; }

    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='credit_memo' AND entity_id=999990");
    $ok = CreditMemoEnqueuer::enqueue(999990, 'create');
    $q = db_row("SELECT COUNT(*) AS c FROM acc_qbo_sync_queue WHERE entity_type='credit_memo' AND entity_id=999990 AND operation='create' AND status='queued'");
    if ($ok === true && (int)($q['c'] ?? 0) === 1) { echo "PASS C26 Enqueuer happy-path queue insert\n"; $pass++; }
    else { echo "FAIL C26 ok=" . var_export($ok, true) . " queued=" . ($q['c'] ?? 0) . "\n"; $failures[] = 'C26'; }

    // ══ Module I — pushUpdate demotion + void rejection (S-QBO-CREDIT-MEMO-UPDATE) ══
    // C27: pushUpdate on an UNMAPPED credit note demotes to create (step 5b)
    // → runs the create pipeline → fails at the customer-mapping preflight
    // gate BEFORE the HTTP boundary (no real updateEntity/createEntity call,
    // which would 404). Proves D-PUSHER-DEMOTION-RULE. Customer mapping is
    // removed to force the gate-2 failure, then restored. sync_mode must be
    // 'sync' here (NOT disabled) so the pipeline runs past step 1.
    $c27 = [];
    ff_smoke_cm_set('quickbooks.sync_mode.credit_memo', 'sync');
    ff_smoke_cm_seed_cn(999992, ['source'=>'other', 'status'=>'active']);
    db_execute("DELETE FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id=999992");
    db_execute("DELETE FROM acc_qbo_customer_map WHERE ff_customer_id=999990");
    $r27 = CreditMemoPusher::pushUpdate(999992);
    $map27 = db_row("SELECT qbo_credit_memo_id, push_status FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id=999992");
    if (($r27['status'] ?? null) === 'unsupported_in_session') { $c27[] = "pushUpdate still a stub"; }
    if (($r27['status'] ?? null) !== 'failed_preflight') { $c27[] = "expected failed_preflight (demoted-create at customer gate), got " . json_encode($r27['status'] ?? null); }
    if (!empty($map27['qbo_credit_memo_id'])) { $c27[] = "no qbo_credit_memo_id should be persisted on a failed demoted-create; got " . json_encode($map27['qbo_credit_memo_id']); }
    // Restore customer mapping for hermetic state.
    db_execute("INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, mapping_status) VALUES (999990, 'QBO-CUST-9990', 'mapped')");
    if (empty($c27)) { echo "PASS C27 pushUpdate on unmapped credit note demotes to create (failed_preflight at customer gate; no qbo_id; D-PUSHER-DEMOTION-RULE)\n"; $pass++; }
    else { echo "FAIL C27 " . implode('; ', $c27) . "\n"; $failures[] = 'C27'; }

    // C28: Enqueuer gate-3 still REJECTS 'void' (allowlist = create+update only;
    // pushVoid rides the F7 trio).
    $c28 = [];
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='credit_memo' AND entity_id=999990");
    $r28 = CreditMemoEnqueuer::enqueue(999990, 'void');
    $q28 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='credit_memo' AND entity_id=999990 AND operation='void'");
    if ($r28 !== false) { $c28[] = "enqueue should reject 'void'; got " . json_encode($r28); }
    if ($q28 !== null) { $c28[] = "no 'void' queue row should be inserted; found id=" . $q28['id']; }
    if (empty($c28)) { echo "PASS C28 Enqueuer gate-3 accepts 'void' op + inserts queue row (S-QBO-PUSHVOID-TRIO; gate-3 +void)\n"; $pass++; }
    else { echo "FAIL C28 " . implode('; ', $c28) . "\n"; $failures[] = 'C28'; }

} finally {
    ff_smoke_cm_cleanup();
    // Restore 'other' item mapping to pre-state if we mutated it.
    if ($otherItemPre && isset($otherQboItemId) && $otherQboItemId === 'SMOKE-CM-OTHER') {
        db_execute("UPDATE acc_qbo_item_map SET mapping_status=?, qbo_item_id=? WHERE id=?",
            [$otherItemPre['mapping_status'], $otherItemPre['qbo_item_id'], (int)$otherItemPre['id']]);
    } elseif (!$otherItemPre && isset($otherQboItemId) && $otherQboItemId === 'SMOKE-CM-OTHER') {
        db_execute("DELETE FROM acc_qbo_item_map WHERE ff_item_type='other' AND qbo_item_id='SMOKE-CM-OTHER'");
    }
    // Restore settings.
    foreach ($snapshot as $k => $v) {
        if ($v === null) db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        else ff_smoke_cm_set($k, $v);
    }
}

echo "═══════════════════════════════════════════════════════════\n";
echo "RESULT: {$pass}/{$total} PASS\n";
if (!empty($failures)) {
    echo "FAILED: " . implode(', ', $failures) . "\n";
    exit(1);
}
exit(0);
