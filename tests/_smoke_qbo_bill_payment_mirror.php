<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_bill_payment_mirror.php
 *
 * S-QBO-BILLPAY-MIRROR — bills paid in QuickBooks mirror into FleetForge.
 *
 * Drives BillPaymentWebhookHandler end to end against the REAL schema, with
 * QuickBooks answered by the fixture layer (QboFixture::cannedGet returns a
 * realistic BillPayment / Bill for GET billpayment/{id} / bill/{id}), so
 * the full handle() path runs: pull → plan → bank resolve → FF AP payment +
 * allocations + JE + map row; replays, edits, voids, echoes; the go-live
 * import + catch-up; the enqueue / push gates that keep a QuickBooks-owned
 * copy from being pushed back; and the shared ApPaymentService the Bill
 * Payments endpoints now use.
 *
 * HERMETIC: one outer DB transaction, rolled back in `finally` (nested
 * db_transaction() calls join it). Sentinel ids 999950–999959.
 *
 *   C1  planFromQbo — FF bills in line order; non-FF bill + vendor credit +
 *       unapplied money left out with warnings
 *   C2  resolveBankAccount — direct bank map, GL pivot, card account, unknown
 *   C3  Create → FF AP payment (origin, method, bank, amount), bills paid /
 *       partially paid, DR AP / CR bank JE, map row pulled_from_qbo, audit
 *   C4  replayed Create → already_mapped (nothing new written)
 *   C5  Update, same SyncToken → unchanged; new token + same money → unchanged,
 *       token remembered
 *   C6  Update with new amounts → FF copy voided + re-mirrored, map re-pointed
 *   C7  Void in QuickBooks → FF payment void, bills reopened, JE reversed
 *   C8  FF's own push echoing back (PrivateNote stamp) → ff_origin_echo
 *   C9  paid from an unlinked QuickBooks account → bank_unmapped + ONE drift
 *       event across repeats
 *   C10 $0 vendor-credit application on an FF bill → zero_amount + drift
 *   C11 FF-pushed payment edited in QuickBooks → drift_recorded, FF untouched
 *   C12 bill already paid in FF → bill_not_payable + drift
 *   C13 BillPaymentEnqueuer refuses QuickBooks-origin copies, queues ff_native
 *   C14 BillPaymentPusher skips QuickBooks-origin copies (skipped_non_ff_origin)
 *   C15 ApPaymentService::void refusals (missing / already void)
 *   C16 go-live import: a Bill's BillPaymentCheck LinkedTxn → imported
 *       (origin qbo_other); no payments → none
 *   C17 catch-up: open FF bill paid down in QuickBooks → imported
 *   C18 unlink of a linked bill refused while QuickBooks payments are mirrored
 *   C19 webhook receiver routes BillPayment (legacy + CloudEvents names)
 *
 * @session S-QBO-BILLPAY-MIRROR
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboFixture;
use FleetForge\Accounting\ApPaymentService;
use FleetForge\QboPushers\BillPaymentWebhookHandler;
use FleetForge\QboPushers\BillPaymentEnqueuer;
use FleetForge\QboPushers\BillPaymentPusher;
use FleetForge\QboPushers\InvoiceLinker;
use FleetForge\QboPushers\PaymentWebhookHandler;

$pass     = 0;
$total    = 19;
$failures = [];

/** Record one sub-check. */
function ff_bpm_check(string $id, string $label, array $errs): void
{
    global $pass, $failures;
    if ($errs === []) {
        echo "PASS {$id} {$label}\n";
        $pass++;
    } else {
        echo "FAIL {$id} {$label} — " . implode('; ', $errs) . "\n";
        $failures[] = $id;
    }
}

/** Raw settings write (inside the rolled-back transaction). */
function ff_bpm_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`)
         VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
    settings_cache_flush();
}

/** A QuickBooks BillPayment shaped like Intuit's (Check pay type unless $card). */
function ff_bpm_qbo_bp(string $id, string $syncToken, string $total, array $lines, string $account = 'SMK-BP-BANK', bool $card = false, string $note = '', string $docNumber = '5001'): array
{
    $bp = [
        'Id'          => $id,
        'SyncToken'   => $syncToken,
        'TxnDate'     => '2026-09-15',
        'TotalAmt'    => (float) $total,
        'VendorRef'   => ['value' => 'SMK-BP-VEND', 'name' => 'Smoke Mirror Vendor'],
        'CurrencyRef' => ['value' => 'CAD'],
        'PayType'     => $card ? 'CreditCard' : 'Check',
        'DocNumber'   => $docNumber,
        'PrivateNote' => $note,
        'Line'        => [],
    ];
    if ($card) {
        $bp['CreditCardPayment'] = ['CCAccountRef' => ['value' => $account, 'name' => 'Smoke Card']];
    } else {
        $bp['CheckPayment'] = ['BankAccountRef' => ['value' => $account, 'name' => 'Smoke Chequing']];
    }
    foreach ($lines as [$amount, $txnId, $type]) {
        $bp['Line'][] = ['Amount' => (float) $amount, 'LinkedTxn' => [['TxnId' => $txnId, 'TxnType' => $type]]];
    }
    return $bp;
}

/** Serve $bp for GET billpayment/{Id}. */
function ff_bpm_serve(array $bp): void
{
    QboFixture::cannedGet('billpayment/' . $bp['Id'], ['BillPayment' => $bp]);
}

/** @return array<string,mixed>|null */
function ff_bpm_bill(int $id): ?array
{
    return db_row("SELECT status, amount_paid, balance_due FROM acc_bills WHERE id = ?", [$id]);
}

function ff_bpm_drift_count(string $qboId): int
{
    return (int) (db_row("SELECT COUNT(*) AS n FROM acc_qbo_drift_events WHERE entity_type = 'bill_payment' AND qbo_entity_id = ?", [$qboId])['n'] ?? 0);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-BILLPAY-MIRROR smoke ({$total} sub-checks; hermetic — rolled back)\n";
echo "═══════════════════════════════════════════════════════════\n";

$pdo = db_pdo();
$pdo->beginTransaction();
QboFixture::reset();

try {
    // ── Fixture world ────────────────────────────────────────────────
    $apAccount = (int) (db_row("SELECT `value` FROM settings WHERE `key` = 'accounting.ap_account_id'")['value'] ?? 0);
    $cashGl    = (int) (db_row("SELECT id FROM acc_accounts WHERE code = '1010'")['id'] ?? 0);
    $period    = (int) (db_row("SELECT id FROM acc_periods WHERE '2026-09-15' BETWEEN start_date AND end_date")['id'] ?? 0);
    if ($apAccount === 0 || $cashGl === 0 || $period === 0) {
        throw new \RuntimeException("dev prerequisites missing (ap_account={$apAccount}, cash GL 1010={$cashGl}, Sep-2026 period={$period})");
    }
    db_execute("INSERT INTO vendors (id, name, vendor_type, created_at) VALUES (999950, 'Smoke Mirror Vendor', 'other', NOW())");
    db_execute("INSERT INTO vendors (id, name, vendor_type, created_at) VALUES (999959, 'Smoke Other Vendor', 'other', NOW())");
    db_execute("INSERT INTO acc_bank_accounts (id, name, account_type, currency, gl_account_id, is_active) VALUES (999951, 'Smoke Chequing', 'checking', 'CAD', ?, 1)", [$cashGl]);
    db_execute("INSERT INTO acc_qbo_bank_account_map (ff_bank_account_id, qbo_bank_account_id, mapping_status) VALUES (999951, 'SMK-BP-BANK', 'mapped')");
    $bills = [
        // id, number, vendor, status, total
        [999952, 'BILL-SMK-999952', 999950, 'approved', '300.00'],
        [999953, 'BILL-SMK-999953', 999950, 'scheduled', '500.00'],
        [999954, 'BILL-SMK-999954', 999950, 'paid', '80.00'],
        [999955, 'BILL-SMK-999955', 999950, 'approved', '120.00'],
        [999956, 'BILL-SMK-999956', 999950, 'approved', '60.00'],
        [999957, 'BILL-SMK-999957', 999950, 'approved', '45.00'],
    ];
    foreach ($bills as [$id, $num, $vendor, $status, $totalAmt]) {
        $paid = $status === 'paid' ? $totalAmt : '0.00';
        $bal  = $status === 'paid' ? '0.00' : $totalAmt;
        db_execute(
            "INSERT INTO acc_bills (id, bill_number, vendor_id, bill_date, due_date, period_id, status, currency,
                                    subtotal, total_amount, amount_paid, balance_due, created_at)
             VALUES (?, ?, ?, '2026-09-01', '2026-09-30', ?, ?, 'CAD', ?, ?, ?, ?, NOW())",
            [$id, $num, $vendor, $period, $status, $totalAmt, $totalAmt, $paid, $bal]
        );
        db_execute("INSERT INTO acc_qbo_bill_map (ff_bill_id, qbo_bill_id, push_status) VALUES (?, ?, 'pushed')", [$id, 'SMKB' . $id]);
    }
    ff_bpm_set('quickbooks.environment', 'sandbox');
    ff_bpm_set('quickbooks.realm_id', QboFixture::REALM_SENTINEL);
    ff_bpm_set('quickbooks.realm_mismatch', '0');
    ff_bpm_set('quickbooks.fixture_mode', '1');
    $realm = QboFixture::REALM_SENTINEL;

    // ══ C1 — plan ═════════════════════════════════════════════════════
    $plan = BillPaymentWebhookHandler::planFromQbo(ff_bpm_qbo_bp('1', '0', '370.00', [
        ['300.00', 'SMKB999952', 'Bill'],
        ['50.00', 'NOT-FF-BILL', 'Bill'],
        ['100.00', 'SMKB999953', 'Bill'],
        ['30.00', 'VC-1', 'VendorCredit'],
        ['0.00', 'x', 'Bill'],
    ]));
    // bills 450 − credit 30 = 420 to bills > total 370 → unapplied 0;
    // cash for FF = 370 − 50 non-FF = 320 → 300 on 999952, 20 on 999953.
    $e = [];
    $t = $plan['targets'];
    if (count($t) !== 2 || $t[0]['ff_bill_id'] !== 999952 || $t[0]['amount'] !== '300.00' || $t[1]['ff_bill_id'] !== 999953 || $t[1]['amount'] !== '20.00') { $e[] = 'targets ' . json_encode($t); }
    if ($plan['ff_amount'] !== '320.00') { $e[] = "ff_amount {$plan['ff_amount']}"; }
    if ($plan['linked_bills'] !== 4) { $e[] = "linked_bills {$plan['linked_bills']}"; }
    if (count($plan['warnings']) !== 1 || !str_contains($plan['warnings'][0], 'vendor credit')) { $e[] = 'warnings ' . json_encode($plan['warnings']); }
    $plan2 = BillPaymentWebhookHandler::planFromQbo(ff_bpm_qbo_bp('2', '0', '150.00', [['100.00', 'SMKB999955', 'Bill']]));
    if (count($plan2['warnings']) !== 1 || !str_contains($plan2['warnings'][0], 'not applied')) { $e[] = 'unapplied warning ' . json_encode($plan2['warnings']); }
    ff_bpm_check('C1', 'plan: FF bills in line order; non-FF bill, vendor credit and unapplied money left out with warnings', $e);

    // ══ C2 — bank resolution ═════════════════════════════════════════
    $e = [];
    $b = BillPaymentWebhookHandler::resolveBankAccount(ff_bpm_qbo_bp('3', '0', '1', []));
    if (($b['id'] ?? 0) !== 999951 || ($b['gl_account_id'] ?? 0) !== $cashGl) { $e[] = 'direct map ' . json_encode($b); }
    // GL pivot: a QuickBooks account mapped to the bank account's GL account.
    // (Use the live mapping when dev has one; otherwise map it for the run.)
    $pivotRow = db_row("SELECT id, qbo_account_id, mapping_status FROM acc_qbo_account_map WHERE ff_account_id = ?", [$cashGl]);
    $pivotQbo = ($pivotRow && $pivotRow['mapping_status'] === 'mapped' && $pivotRow['qbo_account_id'] !== null) ? $pivotRow['qbo_account_id'] : 'SMK-BP-PIVOT';
    if ($pivotRow && $pivotQbo === 'SMK-BP-PIVOT') {
        db_execute("UPDATE acc_qbo_account_map SET qbo_account_id = ?, mapping_status = 'mapped' WHERE id = ?", [$pivotQbo, (int) $pivotRow['id']]);
    } elseif (!$pivotRow) {
        db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (?, ?, 'mapped')", [$cashGl, $pivotQbo]);
    }
    $b = BillPaymentWebhookHandler::resolveBankAccount(ff_bpm_qbo_bp('4', '0', '1', [], (string) $pivotQbo));
    if (($b['gl_account_id'] ?? 0) !== $cashGl) { $e[] = 'GL pivot ' . json_encode($b); }
    $b = BillPaymentWebhookHandler::resolveBankAccount(ff_bpm_qbo_bp('5', '0', '1', [], 'SMK-BP-BANK', true));
    if (($b['id'] ?? 0) !== 999951) { $e[] = 'card account (CCAccountRef) ' . json_encode($b); }
    if (BillPaymentWebhookHandler::resolveBankAccount(ff_bpm_qbo_bp('6', '0', '1', [], 'NOPE')) !== null) { $e[] = 'unknown account resolved'; }
    ff_bpm_check('C2', 'bank account: direct map, GL-account pivot, card account; unknown → null', $e);

    // ══ C3 — Create ══════════════════════════════════════════════════
    $bp1 = ff_bpm_qbo_bp('9001', '0', '400.00', [['300.00', 'SMKB999952', 'Bill'], ['100.00', 'SMKB999953', 'Bill']]);
    ff_bpm_serve($bp1);
    $r = BillPaymentWebhookHandler::handle('9001', 'Create', $realm, 'evt-smk-1');
    $e = [];
    if (($r['result'] ?? '') !== 'payment_created') { $e[] = 'result ' . json_encode($r); }
    $ap1 = (int) ($r['ff_ap_payment_id'] ?? 0);
    $pay = db_row("SELECT * FROM acc_ap_payments WHERE id = ?", [$ap1]);
    if (!$pay) {
        $e[] = 'no AP payment row';
    } else {
        if ($pay['origin'] !== 'qbo_payments_webhook') { $e[] = "origin {$pay['origin']}"; }
        if ($pay['amount'] !== '400.00' || $pay['status'] !== 'cleared') { $e[] = "amount/status {$pay['amount']}/{$pay['status']}"; }
        if ((int) $pay['bank_account_id'] !== 999951 || (int) $pay['vendor_id'] !== 999950) { $e[] = 'bank/vendor'; }
        if ($pay['payment_method'] !== 'check' || $pay['check_number'] !== '5001' || $pay['payment_date'] !== '2026-09-15') { $e[] = "method {$pay['payment_method']} cheque {$pay['check_number']} date {$pay['payment_date']}"; }
        if ($pay['created_by'] !== null) { $e[] = 'created_by not null'; }
        $lines = db_select("SELECT account_id, debit, credit, vendor_id FROM acc_journal_entry_lines WHERE journal_entry_id = ? ORDER BY id", [(int) $pay['journal_entry_id']]);
        if (count($lines) !== 2 || (int) $lines[0]['account_id'] !== $apAccount || $lines[0]['debit'] !== '400.00'
            || (int) $lines[1]['account_id'] !== $cashGl || $lines[1]['credit'] !== '400.00') { $e[] = 'JE ' . json_encode($lines); }
        $je = db_row("SELECT status, source_type, entry_date FROM acc_journal_entries WHERE id = ?", [(int) $pay['journal_entry_id']]);
        if (($je['status'] ?? '') !== 'posted' || ($je['source_type'] ?? '') !== 'ap_payment' || ($je['entry_date'] ?? '') !== '2026-09-15') { $e[] = 'JE header ' . json_encode($je); }
    }
    $b52 = ff_bpm_bill(999952);
    $b53 = ff_bpm_bill(999953);
    if (($b52['status'] ?? '') !== 'paid' || ($b52['balance_due'] ?? '') !== '0.00') { $e[] = 'bill 52 ' . json_encode($b52); }
    if (($b53['status'] ?? '') !== 'partially_paid' || ($b53['balance_due'] ?? '') !== '400.00') { $e[] = 'bill 53 (was scheduled) ' . json_encode($b53); }
    $map = db_row("SELECT * FROM acc_qbo_bill_payment_map WHERE qbo_bill_payment_id = '9001'");
    if (!$map || (int) $map['ff_ap_payment_id'] !== $ap1 || $map['push_status'] !== 'pulled_from_qbo' || $map['origin'] !== 'qbo_payments_webhook'
        || $map['realm_id'] !== $realm || $map['webhook_event_id'] !== 'evt-smk-1' || $map['qbo_bank_account_id'] !== 'SMK-BP-BANK' || $map['pulled_at'] === null) { $e[] = 'map ' . json_encode($map); }
    $audit = db_row("SELECT user_name FROM audit_log WHERE entity_type = 'ap_payment' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$ap1]);
    if (($audit['user_name'] ?? '') !== 'QuickBooks') { $e[] = 'audit ' . json_encode($audit); }
    ff_bpm_check('C3', 'Create: FF AP payment + bills paid/partially paid + DR AP / CR bank JE + pulled_from_qbo map + audit', $e);

    // ══ C4 — replay ══════════════════════════════════════════════════
    $before = (int) db_row("SELECT COUNT(*) AS n FROM acc_ap_payments")['n'];
    $r = BillPaymentWebhookHandler::handle('9001', 'Create', $realm, 'evt-smk-1b');
    $after = (int) db_row("SELECT COUNT(*) AS n FROM acc_ap_payments")['n'];
    ff_bpm_check('C4', 'replayed Create → already_mapped, nothing written',
        (($r['result'] ?? '') === 'already_mapped' && $before === $after) ? [] : ['got ' . json_encode($r) . " rows {$before}→{$after}"]);

    // ══ C5 — Update without money change ═════════════════════════════
    $e = [];
    $r = BillPaymentWebhookHandler::handle('9001', 'Update', $realm, 'evt-smk-2');
    if (($r['result'] ?? '') !== 'unchanged') { $e[] = 'same token: ' . json_encode($r); }
    $bp1['SyncToken'] = '1';
    $bp1['DocNumber'] = '5001';
    ff_bpm_serve($bp1);
    $r = BillPaymentWebhookHandler::handle('9001', 'Update', $realm, 'evt-smk-3');
    if (($r['result'] ?? '') !== 'unchanged' || (int) ($r['ff_ap_payment_id'] ?? 0) !== $ap1) { $e[] = 'new token: ' . json_encode($r); }
    if ((db_row("SELECT qbo_sync_token FROM acc_qbo_bill_payment_map WHERE qbo_bill_payment_id = '9001'")['qbo_sync_token'] ?? '') !== '1') { $e[] = 'token not remembered'; }
    ff_bpm_check('C5', 'Update with no money change → unchanged (SyncToken remembered)', $e);

    // ══ C6 — Update with new amounts → re-mirror ═════════════════════
    $bp1 = ff_bpm_qbo_bp('9001', '2', '350.00', [['300.00', 'SMKB999952', 'Bill'], ['50.00', 'SMKB999953', 'Bill']]);
    ff_bpm_serve($bp1);
    $r = BillPaymentWebhookHandler::handle('9001', 'Update', $realm, 'evt-smk-4');
    $e = [];
    if (($r['result'] ?? '') !== 'payment_resynced') { $e[] = 'result ' . json_encode($r); }
    $ap1b = (int) ($r['ff_ap_payment_id'] ?? 0);
    if ((db_row("SELECT status FROM acc_ap_payments WHERE id = ?", [$ap1])['status'] ?? '') !== 'void') { $e[] = 'old copy not void'; }
    $newPay = db_row("SELECT amount, origin FROM acc_ap_payments WHERE id = ?", [$ap1b]);
    if (($newPay['amount'] ?? '') !== '350.00' || ($newPay['origin'] ?? '') !== 'qbo_payments_webhook') { $e[] = 'new copy ' . json_encode($newPay); }
    if ((ff_bpm_bill(999953)['balance_due'] ?? '') !== '450.00' || (ff_bpm_bill(999952)['status'] ?? '') !== 'paid') { $e[] = 'bills ' . json_encode([ff_bpm_bill(999952), ff_bpm_bill(999953)]); }
    if ((int) (db_row("SELECT ff_ap_payment_id FROM acc_qbo_bill_payment_map WHERE qbo_bill_payment_id = '9001'")['ff_ap_payment_id'] ?? 0) !== $ap1b) { $e[] = 'map not re-pointed'; }
    ff_bpm_check('C6', 'Update with new amounts → FF copy voided and re-mirrored; map re-pointed', $e);

    // ══ C7 — Void in QuickBooks ══════════════════════════════════════
    $jeId = (int) (db_row("SELECT journal_entry_id FROM acc_ap_payments WHERE id = ?", [$ap1b])['journal_entry_id'] ?? 0);
    $r = BillPaymentWebhookHandler::handle('9001', 'Void', $realm, 'evt-smk-5');
    $e = [];
    if (($r['result'] ?? '') !== 'payment_voided') { $e[] = 'result ' . json_encode($r); }
    if ((db_row("SELECT status FROM acc_ap_payments WHERE id = ?", [$ap1b])['status'] ?? '') !== 'void') { $e[] = 'FF copy not void'; }
    if ((ff_bpm_bill(999952)['status'] ?? '') !== 'approved' || (ff_bpm_bill(999952)['balance_due'] ?? '') !== '300.00') { $e[] = 'bill 52 not reopened ' . json_encode(ff_bpm_bill(999952)); }
    if ((ff_bpm_bill(999953)['balance_due'] ?? '') !== '500.00') { $e[] = 'bill 53 not reopened ' . json_encode(ff_bpm_bill(999953)); }
    $rev = db_row("SELECT reversed_by_id FROM acc_journal_entries WHERE id = ?", [$jeId]);
    if (empty($rev['reversed_by_id'])) { $e[] = 'JE not reversed'; }
    if ((db_row("SELECT push_status FROM acc_qbo_bill_payment_map WHERE qbo_bill_payment_id = '9001'")['push_status'] ?? '') !== 'voided') { $e[] = 'map not voided'; }
    if ((BillPaymentWebhookHandler::handle('9001', 'Void', $realm, 'evt-smk-6')['result'] ?? '') !== 'already_voided') { $e[] = 'second void not idempotent'; }
    ff_bpm_check('C7', 'Void in QuickBooks → FF copy void, bills reopened, JE reversed, map voided (idempotent)', $e);

    // ══ C8 — FF's own push echo ══════════════════════════════════════
    $bp = ff_bpm_qbo_bp('9002', '0', '120.00', [['120.00', 'SMKB999955', 'Bill']], 'SMK-BP-BANK', false, 'FF ap_payment #APAY-2026-00077 | ref=abc');
    ff_bpm_serve($bp);
    $r = BillPaymentWebhookHandler::handle('9002', 'Create', $realm, 'evt-smk-7');
    ff_bpm_check('C8', "FF's own push echoing back (PrivateNote stamp) → ff_origin_echo, bill untouched",
        (($r['result'] ?? '') === 'ff_origin_echo' && (ff_bpm_bill(999955)['status'] ?? '') === 'approved') ? [] : ['got ' . json_encode($r)]);

    // ══ C9 — unlinked bank account ═══════════════════════════════════
    $bp = ff_bpm_qbo_bp('9003', '0', '120.00', [['120.00', 'SMKB999955', 'Bill']], 'SMK-UNLINKED');
    ff_bpm_serve($bp);
    $r1 = BillPaymentWebhookHandler::handle('9003', 'Create', $realm, 'evt-smk-8');
    $r2 = BillPaymentWebhookHandler::handle('9003', 'Update', $realm, 'evt-smk-9');
    $e = [];
    if (($r1['result'] ?? '') !== 'bank_unmapped' || ($r2['result'] ?? '') !== 'bank_unmapped') { $e[] = json_encode([$r1, $r2]); }
    if (ff_bpm_drift_count('9003') !== 1) { $e[] = 'drift events ' . ff_bpm_drift_count('9003') . ' (want exactly 1)'; }
    if ((ff_bpm_bill(999955)['status'] ?? '') !== 'approved') { $e[] = 'bill changed'; }
    ff_bpm_check('C9', 'paid from an unlinked QuickBooks account → bank_unmapped, one drift event across repeats', $e);

    // ══ C10 — $0 vendor-credit application ═══════════════════════════
    $bp = ff_bpm_qbo_bp('9004', '0', '0', [['45.00', 'SMKB999957', 'Bill'], ['45.00', 'VC-9', 'VendorCredit']]);
    ff_bpm_serve($bp);
    $r = BillPaymentWebhookHandler::handle('9004', 'Create', $realm, 'evt-smk-10');
    ff_bpm_check('C10', '$0 vendor-credit application on an FF bill → zero_amount + drift',
        (($r['result'] ?? '') === 'zero_amount' && ff_bpm_drift_count('9004') === 1) ? [] : ['got ' . json_encode($r) . ' drift=' . ff_bpm_drift_count('9004')]);

    // ══ C11 — FF-pushed payment edited in QuickBooks ═════════════════
    $e = [];
    $native = db_transaction(function () use ($cashGl) {
        $je = ApPaymentService::postPaymentJournalEntry('APAY-SMK-NATIVE', '2026-09-15', 999950, 'Smoke Mirror Vendor', $cashGl, '60.00', null);
        $id = db_insert('acc_ap_payments', [
            'payment_number' => 'APAY-SMK-NATIVE', 'vendor_id' => 999950, 'bank_account_id' => 999951, 'payment_date' => '2026-09-15',
            'payment_method' => 'eft', 'amount' => '60.00', 'currency' => 'CAD', 'status' => 'cleared', 'journal_entry_id' => (int) $je['id'],
        ]);
        ApPaymentService::applyToBill($id, 999956, '60.00');
        db_insert('acc_qbo_bill_payment_map', ['ff_ap_payment_id' => $id, 'qbo_bill_payment_id' => '9005', 'qbo_sync_token' => '0', 'push_status' => 'pushed']);
        return $id;
    });
    if ((db_row("SELECT origin FROM acc_ap_payments WHERE id = ?", [$native])['origin'] ?? '') !== 'ff_native') { $e[] = 'default origin not ff_native'; }
    ff_bpm_serve(ff_bpm_qbo_bp('9005', '1', '55.00', [['55.00', 'SMKB999956', 'Bill']]));
    $r = BillPaymentWebhookHandler::handle('9005', 'Update', $realm, 'evt-smk-11');
    if (($r['result'] ?? '') !== 'drift_recorded' || ff_bpm_drift_count('9005') !== 1) { $e[] = 'got ' . json_encode($r); }
    if ((db_row("SELECT status, amount FROM acc_ap_payments WHERE id = ?", [$native])['amount'] ?? '') !== '60.00') { $e[] = 'FF payment changed'; }
    ff_bpm_check('C11', 'FF-pushed payment edited in QuickBooks → drift_recorded, FF payment untouched', $e);

    // ══ C12 — bill already paid in FF ════════════════════════════════
    ff_bpm_serve(ff_bpm_qbo_bp('9006', '0', '80.00', [['80.00', 'SMKB999954', 'Bill']]));
    $r = BillPaymentWebhookHandler::handle('9006', 'Create', $realm, 'evt-smk-12');
    ff_bpm_check('C12', 'bill already paid in FF → bill_not_payable + drift (possible duplicate)',
        (($r['result'] ?? '') === 'bill_not_payable' && ff_bpm_drift_count('9006') === 1) ? [] : ['got ' . json_encode($r) . ' drift=' . ff_bpm_drift_count('9006')]);

    // ══ C13 — enqueue gate ═══════════════════════════════════════════
    ff_bpm_set('quickbooks.sync_enabled', '1');
    ff_bpm_set('quickbooks.sync_mode.bill_payment', 'queue');
    $e = [];
    $mirrored = $ap1b;   // void QuickBooks copy — use a live one too
    ff_bpm_serve(ff_bpm_qbo_bp('9007', '0', '120.00', [['120.00', 'SMKB999955', 'Bill']]));
    $r = BillPaymentWebhookHandler::handle('9007', 'Create', $realm, 'evt-smk-13');
    $live = (int) ($r['ff_ap_payment_id'] ?? 0);
    if (($r['result'] ?? '') !== 'payment_created') { $e[] = 'setup ' . json_encode($r); }
    $q0 = (int) db_row("SELECT COUNT(*) AS n FROM acc_qbo_sync_queue WHERE entity_type = 'bill_payment' AND entity_id = ?", [$live])['n'];
    if (BillPaymentEnqueuer::enqueue($live, 'create') !== false || BillPaymentEnqueuer::enqueue($live, 'update') !== false) { $e[] = 'QuickBooks copy enqueued'; }
    if (BillPaymentEnqueuer::enqueue($mirrored, 'void') !== false) { $e[] = 'QuickBooks copy void enqueued'; }
    $q1 = (int) db_row("SELECT COUNT(*) AS n FROM acc_qbo_sync_queue WHERE entity_type = 'bill_payment' AND entity_id = ?", [$live])['n'];
    if ($q1 !== $q0) { $e[] = "queue rows {$q0}→{$q1}"; }
    if (BillPaymentEnqueuer::enqueue($native, 'update') !== true) { $e[] = 'ff_native payment not enqueued'; }
    ff_bpm_check('C13', 'enqueuer refuses QuickBooks-origin copies; still queues ff_native', $e);

    // ══ C14 — pusher gate ════════════════════════════════════════════
    $e = [];
    $p = BillPaymentPusher::pushCreate($live);
    if (($p['status'] ?? '') !== 'skipped_non_ff_origin') { $e[] = 'pushCreate ' . json_encode($p); }
    $p = BillPaymentPusher::pushVoid($mirrored);
    if (($p['status'] ?? '') !== 'skipped_non_ff_origin') { $e[] = 'pushVoid ' . json_encode($p); }
    if ((db_row("SELECT push_status FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = ?", [$live])['push_status'] ?? '') !== 'pulled_from_qbo') { $e[] = 'map status overwritten'; }
    ff_bpm_check('C14', 'pusher skips QuickBooks-origin copies (create + void), map stays pulled_from_qbo', $e);

    // ══ C15 — ApPaymentService::void refusals ════════════════════════
    $e = [];
    try { ApPaymentService::void(987654321, 'x', null, '127.0.0.1'); $e[] = 'missing: no throw'; }
    catch (\InvalidArgumentException $ex) { /* expected */ }
    catch (\Throwable $ex) { $e[] = 'missing: ' . get_class($ex); }
    try { ApPaymentService::void($ap1, 'x', null, '127.0.0.1'); $e[] = 'already void: no throw'; }
    catch (\DomainException $ex) { /* expected */ }
    catch (\Throwable $ex) { $e[] = 'already void: ' . get_class($ex); }
    ff_bpm_check('C15', 'ApPaymentService::void: missing → InvalidArgumentException, already void → DomainException', $e);

    // ══ C16 — go-live import from a Bill's LinkedTxn ═════════════════
    $e = [];
    ff_bpm_serve(ff_bpm_qbo_bp('9008', '0', '300.00', [['300.00', 'SMKB999952', 'Bill']]));
    $imp = InvoiceLinker::importBillPayments(999952, ['Id' => 'SMKB999952', 'Balance' => 0, 'LinkedTxn' => [['TxnId' => '9008', 'TxnType' => 'BillPaymentCheck']]]);
    if (($imp['status'] ?? '') !== 'imported' || ($imp['results'][0]['result'] ?? '') !== 'payment_created' || ($imp['ff_status'] ?? '') !== 'paid') { $e[] = 'import ' . json_encode($imp); }
    $o = db_row("SELECT p.origin FROM acc_ap_payments p JOIN acc_qbo_bill_payment_map m ON m.ff_ap_payment_id = p.id WHERE m.qbo_bill_payment_id = '9008'");
    if (($o['origin'] ?? '') !== 'qbo_other') { $e[] = 'origin ' . json_encode($o); }
    $none = InvoiceLinker::importBillPayments(999957, ['Id' => 'SMKB999957', 'Balance' => 45, 'LinkedTxn' => []]);
    if (($none['status'] ?? '') !== 'none') { $e[] = 'no payments ' . json_encode($none); }
    ff_bpm_check('C16', "go-live import: Bill LinkedTxn BillPaymentCheck → imported (origin qbo_other); none when QuickBooks has no payments", $e);

    // ══ C17 — catch-up sweep ═════════════════════════════════════════
    $e = [];
    ff_bpm_serve(ff_bpm_qbo_bp('9009', '0', '200.00', [['200.00', 'SMKB999953', 'Bill']]));
    QboFixture::cannedGet('bill/SMKB999953', ['Bill' => ['Id' => 'SMKB999953', 'SyncToken' => '0', 'Balance' => 300.00, 'TotalAmt' => 500.00,
        'LinkedTxn' => [['TxnId' => '9009', 'TxnType' => 'BillPaymentCheck']]]]);
    // Only this bill is open with a QuickBooks copy among the sentinels (57 has
    // Balance == FF balance via the generic fixture answer → unchanged).
    $sweep = InvoiceLinker::syncOpenBillPayments(1000, null, 999951);
    $d53 = array_values(array_filter($sweep['details'], static fn($d) => ($d['bill'] ?? '') === 'BILL-SMK-999953'));
    if (($d53[0]['status'] ?? '') !== 'imported') { $e[] = 'bill 53 ' . json_encode($d53); }
    if ((ff_bpm_bill(999953)['balance_due'] ?? '') !== '300.00' || (ff_bpm_bill(999953)['status'] ?? '') !== 'partially_paid') { $e[] = 'bill 53 after ' . json_encode(ff_bpm_bill(999953)); }
    if ($sweep['imported'] < 1) { $e[] = 'sweep ' . json_encode(array_diff_key($sweep, ['details' => 1])); }
    // Re-run: FF's balance now equals QuickBooks' → skipped before any
    // QuickBooks payment is even read; nothing new is written.
    $n0 = (int) db_row("SELECT COUNT(*) AS n FROM acc_ap_payments")['n'];
    $again = InvoiceLinker::syncOpenBillPayments(1000, null, 999951);
    $n1 = (int) db_row("SELECT COUNT(*) AS n FROM acc_ap_payments")['n'];
    $d53 = array_values(array_filter($again['details'], static fn($d) => ($d['bill'] ?? '') === 'BILL-SMK-999953'));
    if ($n1 !== $n0 || $d53 !== [] || $again['imported'] !== 0) { $e[] = "second sweep not a no-op (payments {$n0}→{$n1}) " . json_encode($d53); }
    ff_bpm_check('C17', 'catch-up: open FF bill paid down in QuickBooks → payment imported; re-run is a no-op', $e);

    // ══ C18 — unlink guard ═══════════════════════════════════════════
    db_execute("UPDATE acc_qbo_bill_map SET origin = 'cutover_link' WHERE ff_bill_id = 999953");
    $u = InvoiceLinker::unlink('bill', 999953, ['id' => null, 'name' => 'smoke']);
    ff_bpm_check('C18', 'unlink of a linked bill refused while QuickBooks bill payments are mirrored',
        (($u['ok'] ?? true) === false && ($u['code'] ?? '') === 'has_mirrored_payments') ? [] : ['got ' . json_encode($u)]);

    // ══ C19 — webhook routing ════════════════════════════════════════
    $e = [];
    $ev = PaymentWebhookHandler::normalizeEvents([['specversion' => '1.0', 'id' => 'ce-1', 'type' => 'qbo.billpayment.created.v1',
        'intuitaccountid' => $realm, 'intuitentityid' => '9010']]);
    if (strcasecmp($ev[0]['name'] ?? '', 'BillPayment') !== 0 || ($ev[0]['operation'] ?? '') !== 'Create') { $e[] = 'CloudEvents ' . json_encode($ev); }
    $ev = PaymentWebhookHandler::normalizeEvents(['eventNotifications' => [['realmId' => $realm, 'dataChangeEvent' => ['entities' => [
        ['name' => 'BillPayment', 'id' => '9011', 'operation' => 'Void']]]]]]);
    if (strcasecmp($ev[0]['name'] ?? '', 'BillPayment') !== 0 || ($ev[0]['operation'] ?? '') !== 'Void') { $e[] = 'legacy ' . json_encode($ev); }
    $src = (string) file_get_contents(FF_ROOT . '/api/v1/webhooks/qbo_payment_notifications.php');
    if (!str_contains($src, "strcasecmp(\$ev['name'], 'BillPayment')") || !str_contains($src, 'BillPaymentWebhookHandler::handle(')) { $e[] = 'receiver does not route BillPayment'; }
    ff_bpm_check('C19', 'webhook receiver routes BillPayment events (legacy + CloudEvents names)', $e);
} catch (\Throwable $fatal) {
    echo "FATAL " . get_class($fatal) . ': ' . $fatal->getMessage() . ' @ ' . $fatal->getFile() . ':' . $fatal->getLine() . "\n";
    $failures[] = 'FATAL';
} finally {
    QboFixture::reset();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    settings_cache_flush();
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_bill_payment_mirror_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
