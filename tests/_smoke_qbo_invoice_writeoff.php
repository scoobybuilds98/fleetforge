<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_invoice_writeoff.php
 *
 * S-QBO-INVOICE-WRITEOFF — invoice write-offs (AR bad debt + damage claims)
 * close the invoice in FleetForge AND in QuickBooks (credit memo on the
 * Bad-debt item applied to the invoice).
 *
 * Real schema; QuickBooks answered by the fixture layer; payloads read back
 * from acc_qbo_sync_log.request_payload.body. HERMETIC: one outer
 * transaction, rolled back in `finally`. Sentinels 999910–999919.
 *
 *   C1  InvoiceWriteOff (bad debt): invoice written_off + balance 0 + stamps,
 *       customer balance down, write-off row, DR Bad Debt / CR AR entry
 *   C2  refusals: draft / nothing owed → DomainException; missing → InvalidArgumentException
 *   C3  damage-claim write-off: damage_writeoff JE on the claim, row carries
 *       the claim, no second AR credit from the bad-debt bridge
 *   C4  enqueuer gates: sync off / mode disabled / recovered refused; else queued
 *   C5  pusher preflight: invoice not in QuickBooks / Bad-debt item unmapped /
 *       dated before go-live → failed_preflight with the fix, no HTTP
 *   C6  push: CreditMemo (Bad-debt item, amount, write-off date, WO- number,
 *       ff_writeoff_id note) + $0 Payment linking invoice + memo; map pushed
 *   C7  re-push → already_mapped, nothing sent
 *   C8  apply step fails → memo id kept; retry sends ONLY the Payment
 *   C9  webhook: FF's apply Payment is an echo; its void in QBO → drift
 *   C10 live drift layer counts write-off memo + payment as FF's
 *   C11 damage_writeoff JE is bridge-derived — never pushed as a JournalEntry
 *   C12 Bad-debt item: listed on the Items page; created on the Bad Debt
 *       Expense account (never a revenue account)
 *
 * @session S-QBO-INVOICE-WRITEOFF
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboFixture;
use FleetForge\Accounting\InvoiceWriteOff;
use FleetForge\QboPushers\InvoiceWriteoffEnqueuer;
use FleetForge\QboPushers\InvoiceWriteoffPusher;
use FleetForge\QboPushers\PaymentWebhookHandler;
use FleetForge\QboPushers\DriftChecker;
use FleetForge\QboPushers\JournalEntryEnqueuer;
use FleetForge\QboPushers\ItemMatcher;
use FleetForge\QboPushers\ItemCreator;

$pass     = 0;
$total    = 12;
$failures = [];

function ff_wo_check(string $id, string $label, array $errs): void
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

function ff_wo_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`)
         VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
    settings_cache_flush();
}

/** An issued invoice for the sentinel customer. */
function ff_wo_invoice(int $id, string $status, string $total, string $paid = '0.00'): void
{
    $bal = bcsub($total, $paid, 2);
    db_execute(
        "INSERT INTO invoices (id, invoice_number, customer_id, currency, status, billing_period_start, billing_period_end, billing_period_days,
                               billing_type, invoice_date, due_date, total_amount, amount_paid, credits_applied, balance_due, created_at)
         VALUES (?, ?, 999910, 'CAD', ?, '2026-09-01', '2026-09-30', 30, 'full_month', '2026-09-01', '2026-09-30', ?, ?, 0, ?, NOW())",
        [$id, "INV-WO-{$id}", $status, $total, $paid, $bal]
    );
}

/** Bodies FF POSTed for this run's QuickBooks writes of $entity. */
function ff_wo_posts(string $entity, int $sinceLogId): array
{
    $out = [];
    foreach (db_select("SELECT request_payload FROM acc_qbo_sync_log WHERE id > ? AND http_method = 'POST' AND endpoint = ? ORDER BY id", [$sinceLogId, $entity]) as $r) {
        $out[] = json_decode((string) $r['request_payload'], true)['body'] ?? null;
    }
    return $out;
}

function ff_wo_log_max(): int
{
    return (int) (db_row("SELECT COALESCE(MAX(id), 0) AS m FROM acc_qbo_sync_log")['m'] ?? 0);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-INVOICE-WRITEOFF smoke ({$total} sub-checks; hermetic — rolled back)\n";
echo "═══════════════════════════════════════════════════════════\n";

$pdo = db_pdo();
$pdo->beginTransaction();
QboFixture::reset();

try {
    $badDebtAcct = (int) (db_row("SELECT `value` FROM settings WHERE `key` = 'accounting.bad_debt_expense_account_id'")['value'] ?? 0);
    $arAcct      = (int) (db_row("SELECT `value` FROM settings WHERE `key` = 'accounting.ar_account_id'")['value'] ?? 0);
    if ($badDebtAcct === 0 || $arAcct === 0) {
        throw new \RuntimeException('dev prerequisites missing: accounting.bad_debt_expense_account_id / accounting.ar_account_id');
    }
    ff_wo_set('accounting.enabled', 'true');
    db_execute("INSERT INTO customers (id, company_name, currency, outstanding_balance, created_at) VALUES (999910, 'Smoke Write-off Co', 'CAD', 1000.00, NOW())");

    // ══ C1 — bad-debt write-off ══════════════════════════════════════
    ff_wo_invoice(999911, 'overdue', '500.00', '100.00');   // owes 400
    $w1 = db_transaction(fn() => InvoiceWriteOff::writeOff(999911, 'Customer bankrupt', null));
    $e = [];
    $inv = db_row("SELECT status, balance_due, write_off_reason, written_off_at FROM invoices WHERE id = 999911");
    if (($inv['status'] ?? '') !== 'written_off' || ($inv['balance_due'] ?? '') !== '0.00' || ($inv['write_off_reason'] ?? '') !== 'Customer bankrupt' || empty($inv['written_off_at'])) { $e[] = 'invoice ' . json_encode($inv); }
    if ((db_row("SELECT outstanding_balance FROM customers WHERE id = 999910")['outstanding_balance'] ?? '') !== '600.00') { $e[] = 'customer balance not reduced by 400'; }
    $row = db_row("SELECT amount, damage_claim_id, journal_entry_id FROM acc_bad_debt_writeoffs WHERE id = ?", [$w1['writeoff_id']]);
    if (($row['amount'] ?? '') !== '400.00' || $row['damage_claim_id'] !== null) { $e[] = 'row ' . json_encode($row); }
    $lines = db_select("SELECT account_id, debit, credit FROM acc_journal_entry_lines WHERE journal_entry_id = ? ORDER BY id", [(int) ($row['journal_entry_id'] ?? 0)]);
    if (count($lines) !== 2 || (int) $lines[0]['account_id'] !== $badDebtAcct || $lines[0]['debit'] !== '400.00' || (int) $lines[1]['account_id'] !== $arAcct || $lines[1]['credit'] !== '400.00') { $e[] = 'JE ' . json_encode($lines); }
    ff_wo_check('C1', 'bad-debt write-off closes the FF invoice, lowers the customer balance, records the write-off + DR Bad Debt / CR AR', $e);

    // ══ C2 — refusals ════════════════════════════════════════════════
    $e = [];
    ff_wo_invoice(999912, 'draft', '50.00');
    ff_wo_invoice(999913, 'sent', '80.00', '80.00');
    foreach ([[999912, \DomainException::class], [999913, \DomainException::class], [987654321, \InvalidArgumentException::class]] as [$id, $cls]) {
        try { db_transaction(fn() => InvoiceWriteOff::writeOff($id, 'x', null)); $e[] = "{$id}: no throw"; }
        catch (\Throwable $ex) { if (!($ex instanceof $cls)) { $e[] = "{$id}: " . get_class($ex) . ' ' . $ex->getMessage(); } }
    }
    ff_wo_check('C2', 'draft / fully-paid invoices refused (DomainException); missing invoice → InvalidArgumentException', $e);

    // ══ C3 — damage-claim write-off ══════════════════════════════════
    ff_wo_invoice(999914, 'sent', '250.00');
    $unitId = (int) (db_row("SELECT MIN(id) AS id FROM equipment_units")['id'] ?? 0);
    db_execute("INSERT INTO damage_claims (id, claim_number, equipment_unit_id, customer_id, invoice_id, description, status, created_at)
                VALUES (999915, 'DC-SMK-999915', ?, 999910, 999914, 'Smoke dent', 'invoiced', NOW())", [$unitId]);
    $w3 = db_transaction(fn() => InvoiceWriteOff::writeOff(999914, 'Damage claim DC-SMK-999915 written off', null, 999915));
    $e = [];
    $je = db_row("SELECT source_type, source_id FROM acc_journal_entries WHERE id = ?", [(int) $w3['journal_entry_id']]);
    if (($je['source_type'] ?? '') !== 'damage_writeoff' || (int) ($je['source_id'] ?? 0) !== 999915) { $e[] = 'JE ' . json_encode($je); }
    if ((int) (db_row("SELECT damage_claim_id FROM acc_bad_debt_writeoffs WHERE id = ?", [$w3['writeoff_id']])['damage_claim_id'] ?? 0) !== 999915) { $e[] = 'row not tagged with the claim'; }
    if ((db_row("SELECT status FROM invoices WHERE id = 999914")['status'] ?? '') !== 'written_off') { $e[] = 'invoice left open'; }
    $arCredits = (int) db_row("SELECT COUNT(*) AS n FROM acc_journal_entries WHERE (source_type = 'invoice' AND source_id = 999914 AND description LIKE 'Bad debt%') OR (source_type = 'damage_writeoff' AND source_id = 999915)")['n'];
    if ($arCredits !== 1) { $e[] = "{$arCredits} AR write-off entries (want 1)"; }
    ff_wo_check('C3', 'damage write-off: damage_writeoff JE on the claim, invoice closed, AR credited once', $e);

    // ══ C4 — enqueuer gates ══════════════════════════════════════════
    $e = [];
    ff_wo_set('quickbooks.sync_enabled', '0');
    if (InvoiceWriteoffEnqueuer::enqueue($w1['writeoff_id'], 'create')) { $e[] = 'queued with sync off'; }
    ff_wo_set('quickbooks.sync_enabled', '1');
    ff_wo_set('quickbooks.sync_mode.invoice_writeoff', 'disabled');
    if (InvoiceWriteoffEnqueuer::enqueue($w1['writeoff_id'], 'create')) { $e[] = 'queued with mode disabled'; }
    ff_wo_set('quickbooks.sync_mode.invoice_writeoff', 'queue');
    db_execute("UPDATE acc_bad_debt_writeoffs SET recovered = 1 WHERE id = ?", [$w3['writeoff_id']]);
    if (InvoiceWriteoffEnqueuer::enqueue($w3['writeoff_id'], 'create')) { $e[] = 'recovered write-off queued'; }
    db_execute("UPDATE acc_bad_debt_writeoffs SET recovered = 0 WHERE id = ?", [$w3['writeoff_id']]);
    if (!InvoiceWriteoffEnqueuer::enqueue($w1['writeoff_id'], 'create')) { $e[] = 'eligible write-off not queued'; }
    if ((int) db_row("SELECT COUNT(*) AS n FROM acc_qbo_sync_queue WHERE entity_type = 'invoice_writeoff' AND entity_id = ?", [$w1['writeoff_id']])['n'] !== 1) { $e[] = 'queue row missing'; }
    ff_wo_check('C4', 'enqueuer: sync off / mode disabled / recovered refused; eligible write-off queued once', $e);

    // ── Fixture QuickBooks ────────────────────────────────────────────
    ff_wo_set('quickbooks.environment', 'sandbox');
    ff_wo_set('quickbooks.realm_id', QboFixture::REALM_SENTINEL);
    ff_wo_set('quickbooks.realm_mismatch', '0');
    ff_wo_set('quickbooks.fixture_mode', '1');
    ff_wo_set('quickbooks.cutover_at', '');
    ff_wo_set('quickbooks.push_from_date', '');
    ff_wo_set('quickbooks.multi_currency_enabled', '0');
    if ((string) settings_get('quickbooks.tax_override_code_id', '') === '') {
        ff_wo_set('quickbooks.tax_override_code_id', 'SMK-NON');
    }
    db_execute("INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, mapping_status, match_confidence) VALUES (999910, 'SMK-WO-CUST', 'mapped', 'manual')");

    // ══ C5 — preflight ═══════════════════════════════════════════════
    $e = [];
    $log0 = ff_wo_log_max();
    $r = InvoiceWriteoffPusher::pushCreate($w1['writeoff_id']);
    if (($r['status'] ?? '') !== 'failed_preflight' || !str_contains((string) ($r['error'] ?? ''), 'not in QuickBooks')) { $e[] = 'unmapped invoice: ' . json_encode($r); }
    db_execute("INSERT INTO acc_qbo_invoice_map (ff_invoice_id, qbo_invoice_id, push_status) VALUES (999911, 'SMK-WO-INV-1', 'pushed')");
    $r = InvoiceWriteoffPusher::pushCreate($w1['writeoff_id']);
    if (($r['status'] ?? '') !== 'failed_preflight' || !str_contains((string) ($r['error'] ?? ''), 'Bad Debt Write-off')) { $e[] = 'unmapped item: ' . json_encode($r); }
    db_execute("INSERT INTO acc_qbo_item_map (ff_item_type, qbo_item_id, qbo_name, mapping_status) VALUES ('bad_debt', 'SMK-BD-ITEM', 'Bad debt', 'mapped')");
    ff_wo_set('quickbooks.push_from_date', '2099-01-01');
    $r = InvoiceWriteoffPusher::pushCreate($w1['writeoff_id']);
    if (($r['status'] ?? '') !== 'failed_preflight' || !str_contains((string) ($r['error'] ?? ''), 'before QuickBooks go-live')) { $e[] = 'pre-go-live: ' . json_encode($r); }
    ff_wo_set('quickbooks.push_from_date', '');
    if (ff_wo_posts('creditmemo', $log0) !== [] || ff_wo_posts('payment', $log0) !== []) { $e[] = 'a blocked push reached QuickBooks'; }
    if ((db_row("SELECT push_status FROM acc_qbo_invoice_writeoff_map WHERE ff_writeoff_id = ?", [$w1['writeoff_id']])['push_status'] ?? '') !== 'failed_preflight') { $e[] = 'map not failed_preflight'; }
    ff_wo_check('C5', 'preflight: invoice not in QuickBooks / Bad-debt item unmapped / pre-go-live date → blocked with the fix, nothing sent', $e);

    // ══ C6 — push ════════════════════════════════════════════════════
    $e = [];
    $log0 = ff_wo_log_max();
    $r = InvoiceWriteoffPusher::pushCreate($w1['writeoff_id']);
    if (empty($r['success']) || ($r['status'] ?? '') !== 'created') { $e[] = 'push ' . json_encode($r); }
    $cm  = ff_wo_posts('creditmemo', $log0)[0] ?? null;
    $pay = ff_wo_posts('payment', $log0)[0] ?? null;
    $line = $cm['Line'][0] ?? [];
    if (($line['SalesItemLineDetail']['ItemRef']['value'] ?? '') !== 'SMK-BD-ITEM' || (string) ($line['Amount'] ?? '') !== '400') { $e[] = 'memo line ' . json_encode($line); }
    if (($cm['CustomerRef']['value'] ?? '') !== 'SMK-WO-CUST' || ($cm['TxnDate'] ?? '') !== ff_today()) { $e[] = 'memo header ' . json_encode([$cm['CustomerRef'] ?? null, $cm['TxnDate'] ?? null]); }
    if (!str_contains((string) ($cm['DocNumber'] ?? 'WO-INV-WO-999911'), 'WO-') || !str_contains((string) ($cm['PrivateNote'] ?? ''), '"ff_writeoff_id":' . $w1['writeoff_id'])) { $e[] = 'memo number/note ' . json_encode([$cm['DocNumber'] ?? null, $cm['PrivateNote'] ?? null]); }
    $linked = array_map(static fn($l) => ($l['LinkedTxn'][0]['TxnType'] ?? '') . ':' . ($l['Amount'] ?? ''), $pay['Line'] ?? []);
    if ((string) ($pay['TotalAmt'] ?? '') !== '0' || $linked !== ['Invoice:400.00', 'CreditMemo:400.00'] || ($pay['Line'][0]['LinkedTxn'][0]['TxnId'] ?? '') !== 'SMK-WO-INV-1') { $e[] = 'payment ' . json_encode($pay); }
    $map = db_row("SELECT * FROM acc_qbo_invoice_writeoff_map WHERE ff_writeoff_id = ?", [$w1['writeoff_id']]);
    if (($map['push_status'] ?? '') !== 'pushed' || empty($map['qbo_credit_memo_id']) || empty($map['qbo_payment_id']) || ($map['amount_snapshot'] ?? '') !== '400.00' || empty($map['pushed_at'])) { $e[] = 'map ' . json_encode($map); }
    ff_wo_check('C6', 'push: Bad-debt credit memo (400, write-off date, WO- number, FF note) + $0 Payment applying it to the invoice; map pushed', $e);

    // ══ C7 — idempotent ══════════════════════════════════════════════
    $log0 = ff_wo_log_max();
    $r = InvoiceWriteoffPusher::pushCreate($w1['writeoff_id']);
    ff_wo_check('C7', 're-push of a pushed write-off → already_mapped, nothing sent',
        (($r['status'] ?? '') === 'already_mapped' && ff_wo_posts('creditmemo', $log0) === [] && ff_wo_posts('payment', $log0) === []) ? [] : ['got ' . json_encode($r)]);

    // ══ C8 — apply step fails, retry resumes ═════════════════════════
    $e = [];
    db_execute("INSERT INTO acc_qbo_invoice_map (ff_invoice_id, qbo_invoice_id, push_status) VALUES (999914, 'SMK-WO-INV-3', 'pushed')");
    QboFixture::injectError('payment:create', 400, ['Fault' => ['Error' => [['Message' => 'Smoke apply failure', 'code' => '6000']], 'type' => 'ValidationFault']]);
    $log0 = ff_wo_log_max();
    $r = InvoiceWriteoffPusher::pushCreate($w3['writeoff_id']);
    $map = db_row("SELECT push_status, qbo_credit_memo_id, qbo_payment_id, push_error FROM acc_qbo_invoice_writeoff_map WHERE ff_writeoff_id = ?", [$w3['writeoff_id']]);
    if (!empty($r['success']) || ($map['push_status'] ?? '') !== 'failed' || empty($map['qbo_credit_memo_id']) || !empty($map['qbo_payment_id'])) { $e[] = 'after failure ' . json_encode([$r, $map]); }
    if (count(ff_wo_posts('creditmemo', $log0)) !== 1) { $e[] = 'memo POSTs ' . count(ff_wo_posts('creditmemo', $log0)); }
    $log1 = ff_wo_log_max();
    $r = InvoiceWriteoffPusher::pushCreate($w3['writeoff_id']);
    if (($r['status'] ?? '') !== 'created' || ff_wo_posts('creditmemo', $log1) !== [] || count(ff_wo_posts('payment', $log1)) !== 1) { $e[] = 'retry ' . json_encode($r) . ' memo=' . count(ff_wo_posts('creditmemo', $log1)); }
    ff_wo_check('C8', 'apply step fails → credit memo kept; retry sends only the apply Payment', $e);

    // ══ C9 — webhook echo ════════════════════════════════════════════
    $e = [];
    $payId = (string) db_row("SELECT qbo_payment_id FROM acc_qbo_invoice_writeoff_map WHERE ff_writeoff_id = ?", [$w1['writeoff_id']])['qbo_payment_id'];
    $r = PaymentWebhookHandler::handle($payId, 'Create', QboFixture::REALM_SENTINEL, 'evt-wo-1');
    if (($r['result'] ?? '') !== 'ff_origin_echo') { $e[] = 'create ' . json_encode($r); }
    $r = PaymentWebhookHandler::handle($payId, 'Void', QboFixture::REALM_SENTINEL, 'evt-wo-2');
    if (($r['result'] ?? '') !== 'drift_recorded') { $e[] = 'void ' . json_encode($r); }
    ff_wo_check('C9', "webhook: FF's own apply Payment is an echo; voiding it in QuickBooks raises drift", $e);

    // ══ C10 — drift ownership ════════════════════════════════════════
    $map = db_row("SELECT qbo_credit_memo_id, qbo_payment_id FROM acc_qbo_invoice_writeoff_map WHERE ff_writeoff_id = ?", [$w1['writeoff_id']]);
    $e = [];
    if (!in_array((string) $map['qbo_payment_id'], DriftChecker::otherFfIds('payment'), true)) { $e[] = 'payment id not FF-owned'; }
    if (!in_array((string) $map['qbo_credit_memo_id'], DriftChecker::otherFfIds('credit_memo'), true)) { $e[] = 'memo id not FF-owned'; }
    if (DriftChecker::otherFfIds('bill') !== []) { $e[] = 'unexpected ids for bills'; }
    ff_wo_check('C10', "live drift counts the write-off credit memo + apply Payment as FF's own", $e);

    // ══ C11 — JE not pushed ══════════════════════════════════════════
    $jeId = (int) db_row("SELECT id FROM acc_journal_entries WHERE source_type = 'damage_writeoff' AND source_id = 999915")['id'];
    ff_wo_set('quickbooks.sync_mode.journal_entry', 'queue');
    ff_wo_check('C11', 'damage_writeoff JE is bridge-derived — never queued as a QuickBooks JournalEntry',
        JournalEntryEnqueuer::enqueue($jeId, 'create') === false ? [] : ['damage_writeoff JE was queued']);

    // ══ C12 — the Bad-debt item ══════════════════════════════════════
    $e = [];
    if (!in_array('bad_debt', array_column(ItemMatcher::ffItemTypes(), 'ff_item_type'), true)) { $e[] = 'not on the Items page'; }
    db_execute("UPDATE acc_qbo_account_map SET qbo_account_id = NULL, mapping_status = 'ff_only' WHERE ff_account_id = ?", [$badDebtAcct]);
    try { ItemCreator::resolveIncomeAccount('bad_debt'); $e[] = 'unmapped Bad Debt Expense fell through to another account'; }
    catch (\FleetForge\Exceptions\ChartOfAccountsIncompleteException $ex) { /* expected */ }
    if (db_row("SELECT id FROM acc_qbo_account_map WHERE ff_account_id = ?", [$badDebtAcct])) {
        db_execute("UPDATE acc_qbo_account_map SET qbo_account_id = 'SMK-BD-ACCT', qbo_name = 'Bad debts', mapping_status = 'mapped' WHERE ff_account_id = ?", [$badDebtAcct]);
    } else {
        db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, qbo_name, mapping_status) VALUES (?, 'SMK-BD-ACCT', 'Bad debts', 'mapped')", [$badDebtAcct]);
    }
    $acct = ItemCreator::resolveIncomeAccount('bad_debt');
    if (($acct['qbo_id'] ?? '') !== 'SMK-BD-ACCT') { $e[] = 'resolved ' . json_encode($acct); }
    ff_wo_check('C12', 'Bad-debt item: on the Items page; created on Bad Debt Expense, never a revenue account', $e);
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
echo "qbo_invoice_writeoff_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
