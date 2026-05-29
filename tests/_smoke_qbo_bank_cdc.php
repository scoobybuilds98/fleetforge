<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_bank_cdc.php
 *
 * S-QBO-20 BankTransactionPuller CDC coverage. Offline — uses synthetic
 * QBO row payloads against upsertTransaction directly so no live QBO API
 * call is required. Live integration deferred to operator live-verify.
 *
 * Sub-checks:
 *   C1: Class surfaces (BankTransactionPuller::runCdc, pullForAccount,
 *       upsertTransaction, markStale + BANK_ENTITY_TYPES + QBO_TO_FF_TYPE).
 *   C2: Migration shape — acc_qbo_bank_transaction_map (16 cols + UNIQUE
 *       on qbo_bank_txn_id + FK CASCADE).
 *   C3: acc_bank_transactions has source='qbo_cdc' in ENUM + is_readonly +
 *       qbo_bank_txn_id columns + idx_qbo_bank_txn.
 *   C4: Settings seeds present — last_bank_cdc_at, cdc_lookback_days,
 *       cdc_enabled, cron_skipped_unmapped.
 *   C5: runCdc with cdc_enabled='0' short-circuits with enabled=false.
 *   C6: runCdc with no mapped accounts returns clean 0/0/0.
 *   C7: upsertTransaction Purchase → FF withdrawal row with is_readonly=1,
 *       source='qbo_cdc', composite qbo_bank_txn_id.
 *   C8: upsertTransaction Deposit → FF deposit row.
 *   C9: upsertTransaction Transfer → FF transfer row.
 *  C10: upsertTransaction JournalEntry net-DR → deposit; net-CR → withdrawal.
 *  C11: upsertTransaction idempotent — re-call with same data returns
 *       'unchanged'.
 *  C12: upsertTransaction updates on snapshot drift.
 *  C13: upsertTransaction $0 amount → 'skipped_zero_amount' (no map row).
 *  C14: Composite key {entity}:{id} distinguishes Purchase:5 from Deposit:5.
 *  C15: markStale transitions pull_status → superseded + appends FF notes.
 *  C16: BANK_ENTITY_TYPES constant matches spec §8.12 entities list.
 *
 * @session S-QBO-20
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\BankTransactionPuller;

$pass     = 0;
$total    = 16;
$failures = [];

const SMOKE_CDC_FF_BASE = 999800;
const SMOKE_CDC_QBO_ID  = 'qbo-bank-smoke';

function ff_smoke_cdc_cleanup(): void
{
    // map first (FK CASCADE on FF would also do it; explicit for clarity)
    db_execute(
        "DELETE FROM acc_qbo_bank_transaction_map WHERE qbo_bank_txn_id LIKE 'smoke-%'"
    );
    db_execute(
        "DELETE FROM acc_bank_transactions WHERE qbo_bank_txn_id LIKE 'smoke-%'"
    );
    db_execute(
        "DELETE FROM acc_bank_transactions WHERE bank_account_id BETWEEN ? AND ?",
        [SMOKE_CDC_FF_BASE, SMOKE_CDC_FF_BASE + 99]
    );
    db_execute(
        "DELETE FROM acc_qbo_bank_account_map WHERE ff_bank_account_id BETWEEN ? AND ?",
        [SMOKE_CDC_FF_BASE, SMOKE_CDC_FF_BASE + 99]
    );
    db_execute(
        "DELETE FROM acc_bank_accounts WHERE id BETWEEN ? AND ?",
        [SMOKE_CDC_FF_BASE, SMOKE_CDC_FF_BASE + 99]
    );
    db_execute(
        "DELETE FROM audit_log WHERE entity_type = 'bank_transaction_mirror' AND entity_id BETWEEN ? AND ?",
        [SMOKE_CDC_FF_BASE, SMOKE_CDC_FF_BASE + 99]
    );
}

function ff_smoke_cdc_seed_ff(int $id = SMOKE_CDC_FF_BASE): void
{
    $gl = db_row("SELECT id FROM acc_accounts WHERE account_type = 'asset' LIMIT 1");
    if (!$gl) {
        throw new \RuntimeException('smoke setup: no acc_accounts asset row to attach to');
    }
    db_execute(
        "INSERT INTO acc_bank_accounts (id, name, account_type, currency, gl_account_id, is_active, opening_balance)
              VALUES (?, ?, ?, ?, ?, 1, '0.00')",
        [$id, "Smoke CDC Bank #{$id}", 'checking', 'CAD', (int) $gl['id']]
    );
}

/**
 * Build a synthetic QBO Purchase row.
 */
function ff_smoke_cdc_purchase(string $id, string $amount, string $date, string $accountId = SMOKE_CDC_QBO_ID, string $memo = ''): array
{
    return [
        'Id'         => $id,
        'TxnDate'    => $date,
        'TotalAmt'   => $amount,
        'AccountRef' => ['value' => $accountId],
        'PrivateNote' => $memo,
        'Line'        => [['Description' => $memo]],
    ];
}

function ff_smoke_cdc_deposit(string $id, string $amount, string $date, string $accountId = SMOKE_CDC_QBO_ID): array
{
    return [
        'Id'                  => $id,
        'TxnDate'             => $date,
        'TotalAmt'            => $amount,
        'DepositToAccountRef' => ['value' => $accountId],
        'PrivateNote'         => '',
        'Line'                => [],
    ];
}

function ff_smoke_cdc_transfer(string $id, string $amount, string $date, string $from = SMOKE_CDC_QBO_ID, string $to = 'qbo-other'): array
{
    return [
        'Id'              => $id,
        'TxnDate'         => $date,
        'Amount'          => $amount,
        'FromAccountRef'  => ['value' => $from],
        'ToAccountRef'    => ['value' => $to],
        'PrivateNote'     => '',
    ];
}

function ff_smoke_cdc_je(string $id, array $lines, string $date, string $docNumber = ''): array
{
    return [
        'Id'        => $id,
        'TxnDate'   => $date,
        'DocNumber' => $docNumber,
        'Line'      => $lines,
        'PrivateNote' => '',
    ];
}

function ff_smoke_cdc_je_line(string $amount, string $postingType, string $accountId): array
{
    return [
        'Amount' => $amount,
        'JournalEntryLineDetail' => [
            'AccountRef'  => ['value' => $accountId],
            'PostingType' => $postingType,
        ],
    ];
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-20 Bank CDC Smoke\n";
echo "═══════════════════════════════════════════════════════════\n";

// Snapshot settings keys we toggle below.
$settingSnap = [];
foreach (['quickbooks.banking.cdc_enabled', 'quickbooks.banking.last_bank_cdc_at'] as $k) {
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$k]);
    $settingSnap[$k] = $row['value'] ?? null;
}

try {
    ff_smoke_cdc_cleanup();

    // ── C1: Class surfaces ─────────────────────────────────────────────
    $c1Errors = [];
    if (!class_exists(BankTransactionPuller::class)) {
        $c1Errors[] = 'BankTransactionPuller class missing';
    }
    foreach (['runCdc', 'pullForAccount', 'upsertTransaction', 'markStale'] as $m) {
        if (!method_exists(BankTransactionPuller::class, $m)) {
            $c1Errors[] = "BankTransactionPuller::{$m} missing";
        }
    }
    if (BankTransactionPuller::BANK_ENTITY_TYPES !== ['Purchase', 'Deposit', 'Transfer', 'JournalEntry']) {
        $c1Errors[] = 'BANK_ENTITY_TYPES const wrong shape';
    }
    if (empty($c1Errors)) {
        echo "PASS C1 class surfaces\n"; $pass++;
    } else {
        echo "FAIL C1 " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1';
    }

    // ── C2: acc_qbo_bank_transaction_map shape ─────────────────────────
    $cols = [];
    foreach (db_select("SHOW COLUMNS FROM acc_qbo_bank_transaction_map") as $r) {
        $cols[$r['Field']] = $r['Type'];
    }
    $expected = [
        'id', 'ff_bank_transaction_id', 'qbo_bank_txn_id', 'qbo_entity_type',
        'qbo_entity_id', 'qbo_account_id', 'qbo_txn_date', 'qbo_amount',
        'qbo_currency_snapshot', 'qbo_exchange_rate_snapshot',
        'qbo_description_snapshot', 'pull_status', 'pull_error',
        'last_pulled_at', 'first_seen_at', 'created_at', 'updated_at',
    ];
    $missing = array_diff($expected, array_keys($cols));
    $hasUq = !empty(db_select("SHOW INDEX FROM acc_qbo_bank_transaction_map WHERE Key_name = ?", ['uq_qbo_bank_txn']));
    if (empty($missing) && $hasUq && $cols['qbo_entity_type'] === "enum('Purchase','Deposit','Transfer','JournalEntry')") {
        echo "PASS C2 map table shape\n"; $pass++;
    } else {
        echo "FAIL C2 missing=" . implode(',', $missing) . " uq=" . ($hasUq ? '1' : '0') . " entity_type=" . ($cols['qbo_entity_type'] ?? 'MISSING') . "\n";
        $failures[] = 'C2';
    }

    // ── C3: acc_bank_transactions extensions ───────────────────────────
    $bt = [];
    foreach (db_select("SHOW COLUMNS FROM acc_bank_transactions") as $r) {
        $bt[$r['Field']] = $r['Type'];
    }
    $hasIdx = !empty(db_select("SHOW INDEX FROM acc_bank_transactions WHERE Key_name = ?", ['idx_qbo_bank_txn']));
    $sourceCheck = strpos($bt['source'] ?? '', 'qbo_cdc') !== false;
    if (isset($bt['is_readonly']) && isset($bt['qbo_bank_txn_id']) && $sourceCheck && $hasIdx) {
        echo "PASS C3 acc_bank_transactions extended (source ENUM + is_readonly + qbo_bank_txn_id + idx)\n"; $pass++;
    } else {
        echo "FAIL C3 is_readonly=" . isset($bt['is_readonly']) . " qbo_bank_txn_id=" . isset($bt['qbo_bank_txn_id']) . " source=" . ($bt['source'] ?? '') . " idx=" . ($hasIdx ? '1' : '0') . "\n";
        $failures[] = 'C3';
    }

    // ── C4: 4 settings seeds ───────────────────────────────────────────
    $seeded = db_select("SELECT `key` FROM settings WHERE `key` LIKE 'quickbooks.banking.%' ORDER BY `key`");
    $keys = array_column($seeded, 'key');
    $expectKeys = [
        'quickbooks.banking.cdc_enabled',
        'quickbooks.banking.cdc_lookback_days',
        'quickbooks.banking.cron_skipped_unmapped',
        'quickbooks.banking.last_bank_cdc_at',
    ];
    if ($keys === $expectKeys) {
        echo "PASS C4 4 settings seeds present\n"; $pass++;
    } else {
        echo "FAIL C4 expected=" . implode(',', $expectKeys) . " got=" . implode(',', $keys) . "\n"; $failures[] = 'C4';
    }

    // ── C5: cdc_enabled='0' short-circuit ──────────────────────────────
    db_execute("UPDATE settings SET `value` = '0' WHERE `key` = 'quickbooks.banking.cdc_enabled'");
    $r5 = BankTransactionPuller::runCdc();
    db_execute("UPDATE settings SET `value` = '1' WHERE `key` = 'quickbooks.banking.cdc_enabled'");
    if (($r5['enabled'] ?? null) === false && ($r5['pulled'] ?? null) === 0) {
        echo "PASS C5 cdc_enabled=0 short-circuits\n"; $pass++;
    } else {
        echo "FAIL C5 r5=" . json_encode($r5) . "\n"; $failures[] = 'C5';
    }

    // ── C6: runCdc with no mapped accounts ────────────────────────────
    $r6 = BankTransactionPuller::runCdc();
    if (($r6['enabled'] ?? null) === true
        && ($r6['pulled'] ?? null) === 0
        && is_array($r6['by_account'] ?? null)) {
        echo "PASS C6 runCdc graceful empty (no mapped accounts)\n"; $pass++;
    } else {
        echo "FAIL C6 r6=" . json_encode($r6) . "\n"; $failures[] = 'C6';
    }

    // ── Seed FF bank account for upsert tests ──────────────────────────
    ff_smoke_cdc_seed_ff(SMOKE_CDC_FF_BASE);

    // ── C7: Purchase upsert ────────────────────────────────────────────
    $p = ff_smoke_cdc_purchase('smoke-p1', '125.50', '2026-05-01', SMOKE_CDC_QBO_ID, 'Test purchase');
    // Patch composite key to be smoke-prefixed for cleanup detection.
    $p['Id'] = 'p1';
    $r7 = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'Purchase', $p);
    $ff7 = db_row("SELECT * FROM acc_bank_transactions WHERE qbo_bank_txn_id = ?", ['Purchase:p1']);
    if ($r7 === 'inserted'
        && $ff7
        && (string) $ff7['source'] === 'qbo_cdc'
        && (int) $ff7['is_readonly'] === 1
        && (string) $ff7['transaction_type'] === 'withdrawal'
        && bccomp((string) $ff7['amount'], '125.50', 2) === 0) {
        echo "PASS C7 Purchase upsert → withdrawal + readonly + source=qbo_cdc\n"; $pass++;
    } else {
        echo "FAIL C7 r7={$r7} ff7=" . json_encode($ff7) . "\n"; $failures[] = 'C7';
    }

    // ── C8: Deposit upsert ─────────────────────────────────────────────
    $d = ff_smoke_cdc_deposit('d1', '500.00', '2026-05-02');
    $r8 = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'Deposit', $d);
    $ff8 = db_row("SELECT * FROM acc_bank_transactions WHERE qbo_bank_txn_id = ?", ['Deposit:d1']);
    if ($r8 === 'inserted' && $ff8 && (string) $ff8['transaction_type'] === 'deposit') {
        echo "PASS C8 Deposit upsert → deposit\n"; $pass++;
    } else {
        echo "FAIL C8 r8={$r8} ff8=" . json_encode($ff8) . "\n"; $failures[] = 'C8';
    }

    // ── C9: Transfer upsert ────────────────────────────────────────────
    $t = ff_smoke_cdc_transfer('t1', '75.00', '2026-05-03');
    $r9 = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'Transfer', $t);
    $ff9 = db_row("SELECT * FROM acc_bank_transactions WHERE qbo_bank_txn_id = ?", ['Transfer:t1']);
    if ($r9 === 'inserted' && $ff9 && (string) $ff9['transaction_type'] === 'transfer') {
        echo "PASS C9 Transfer upsert → transfer\n"; $pass++;
    } else {
        echo "FAIL C9 r9={$r9} ff9=" . json_encode($ff9) . "\n"; $failures[] = 'C9';
    }

    // ── C10: JournalEntry signed derivation ────────────────────────────
    // DR cash 200 / CR other 200 → net +200 → deposit
    $je = ff_smoke_cdc_je('j1', [
        ff_smoke_cdc_je_line('200.00', 'Debit',  SMOKE_CDC_QBO_ID),
        ff_smoke_cdc_je_line('200.00', 'Credit', 'qbo-other'),
    ], '2026-05-04', 'JE-001');
    $r10 = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'JournalEntry', $je);
    $ff10 = db_row("SELECT * FROM acc_bank_transactions WHERE qbo_bank_txn_id = ?", ['JournalEntry:j1']);
    // CR cash 150 / DR other 150 → net -150 → withdrawal
    $je2 = ff_smoke_cdc_je('j2', [
        ff_smoke_cdc_je_line('150.00', 'Credit', SMOKE_CDC_QBO_ID),
        ff_smoke_cdc_je_line('150.00', 'Debit',  'qbo-other'),
    ], '2026-05-05', 'JE-002');
    $r10b = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'JournalEntry', $je2);
    $ff10b = db_row("SELECT * FROM acc_bank_transactions WHERE qbo_bank_txn_id = ?", ['JournalEntry:j2']);
    if ($r10 === 'inserted' && $ff10 && (string) $ff10['transaction_type'] === 'deposit'
        && $r10b === 'inserted' && $ff10b && (string) $ff10b['transaction_type'] === 'withdrawal') {
        echo "PASS C10 JE net-DR → deposit; net-CR → withdrawal\n"; $pass++;
    } else {
        echo "FAIL C10 r10={$r10} type=" . ($ff10['transaction_type'] ?? '?')
           . " r10b={$r10b} type=" . ($ff10b['transaction_type'] ?? '?') . "\n";
        $failures[] = 'C10';
    }

    // ── C11: Idempotent re-call ────────────────────────────────────────
    $r11 = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'Purchase', $p);
    if ($r11 === 'unchanged') {
        echo "PASS C11 idempotent re-call returns 'unchanged'\n"; $pass++;
    } else {
        echo "FAIL C11 expected 'unchanged'; got '{$r11}'\n"; $failures[] = 'C11';
    }

    // ── C12: Drift → updated ───────────────────────────────────────────
    $pDrift = $p;
    $pDrift['TotalAmt'] = '999.99';
    $r12 = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'Purchase', $pDrift);
    $ff12 = db_row("SELECT amount FROM acc_bank_transactions WHERE qbo_bank_txn_id = ?", ['Purchase:p1']);
    if ($r12 === 'updated' && bccomp((string) $ff12['amount'], '999.99', 2) === 0) {
        echo "PASS C12 drift triggers 'updated' + FF row amount refreshed\n"; $pass++;
    } else {
        echo "FAIL C12 r12={$r12} ff12=" . json_encode($ff12) . "\n"; $failures[] = 'C12';
    }

    // ── C13: $0 amount → skipped_zero_amount ───────────────────────────
    $zero = ff_smoke_cdc_purchase('z1', '0.00', '2026-05-06');
    $r13 = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'Purchase', $zero);
    $mapZ = db_row("SELECT id FROM acc_qbo_bank_transaction_map WHERE qbo_bank_txn_id = ?", ['Purchase:z1']);
    if ($r13 === 'skipped_zero_amount' && $mapZ === null) {
        echo "PASS C13 \$0 amount → 'skipped_zero_amount' + no map row\n"; $pass++;
    } else {
        echo "FAIL C13 r13={$r13} mapZ=" . json_encode($mapZ) . "\n"; $failures[] = 'C13';
    }

    // ── C14: Composite key distinguishes entities ──────────────────────
    $pShare = ff_smoke_cdc_purchase('share', '10.00', '2026-05-07');
    $dShare = ff_smoke_cdc_deposit('share', '20.00', '2026-05-08');
    $r14a = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'Purchase', $pShare);
    $r14b = BankTransactionPuller::upsertTransaction(SMOKE_CDC_FF_BASE, SMOKE_CDC_QBO_ID, 'Deposit',  $dShare);
    $countShare = (int) (db_row("SELECT COUNT(*) AS n FROM acc_qbo_bank_transaction_map WHERE qbo_entity_id = ?", ['share'])['n'] ?? 0);
    if ($r14a === 'inserted' && $r14b === 'inserted' && $countShare === 2) {
        echo "PASS C14 composite key distinguishes Purchase:share vs Deposit:share\n"; $pass++;
    } else {
        echo "FAIL C14 r14a={$r14a} r14b={$r14b} countShare={$countShare}\n"; $failures[] = 'C14';
    }

    // ── C15: markStale ─────────────────────────────────────────────────
    $ffP = (int) (db_row("SELECT id FROM acc_bank_transactions WHERE qbo_bank_txn_id = ?", ['Purchase:p1'])['id'] ?? 0);
    BankTransactionPuller::markStale($ffP, 'smoke-test markStale');
    $mapP = db_row("SELECT pull_status FROM acc_qbo_bank_transaction_map WHERE qbo_bank_txn_id = ?", ['Purchase:p1']);
    $ffNotes = db_row("SELECT notes FROM acc_bank_transactions WHERE id = ?", [$ffP]);
    if ((string) ($mapP['pull_status'] ?? '') === 'superseded'
        && str_contains((string) ($ffNotes['notes'] ?? ''), 'markStale')) {
        echo "PASS C15 markStale → pull_status='superseded' + FF notes appended\n"; $pass++;
    } else {
        echo "FAIL C15 mapP=" . json_encode($mapP) . " notes=" . ($ffNotes['notes'] ?? '') . "\n"; $failures[] = 'C15';
    }

    // ── C16: BANK_ENTITY_TYPES matches spec ────────────────────────────
    if (BankTransactionPuller::BANK_ENTITY_TYPES === ['Purchase', 'Deposit', 'Transfer', 'JournalEntry']) {
        echo "PASS C16 BANK_ENTITY_TYPES matches spec §8.12\n"; $pass++;
    } else {
        echo "FAIL C16 actual=" . json_encode(BankTransactionPuller::BANK_ENTITY_TYPES) . "\n"; $failures[] = 'C16';
    }

} catch (\Throwable $e) {
    echo "FAIL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures[] = 'EXCEPTION';
} finally {
    ff_smoke_cdc_cleanup();
    // Restore settings snapshot.
    foreach ($settingSnap as $k => $v) {
        if ($v === null) continue;
        db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [$v, $k]);
    }
}

echo "\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "Result: {$pass}/{$total} passed";
if (!empty($failures)) {
    echo " — failures: " . implode(', ', $failures);
}
echo "\n";
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total ? 0 : 1);
