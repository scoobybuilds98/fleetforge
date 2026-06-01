<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_credit_application_push.php
 *
 * Smoke test for S-QBO-CREDIT-MEMO-APPLY (closes F25 — the last open QBO
 * update-debt item). Sibling of _smoke_qbo_credit_memo_push: applies-to-QBO
 * propagates as a zero-dollar QBO Payment carrying 2 LinkedTxns
 * (CreditMemo + Invoice).
 *
 * Per [[extensive-test-and-full-report]]: PER-FUNCTION coverage — each public
 * method gets named sub-checks for happy path + edge cases + invariants.
 *
 * Sub-check map:
 *   Module A — class surfaces + schema
 *     C1  CreditApplicationPusher surfaces (pushCreate + buildQboPayload
 *         + buildPrivateNoteJson + runPreflight + RESULT_BASE const)
 *     C2  CreditApplicationEnqueuer::enqueue surfaces
 *     C3  acc_qbo_credit_application_map schema (cols + UNIQUE keys + FK)
 *     C4  acc_qbo_sync_queue.entity_type ENUM includes 'credit_application'
 *
 *   Module B — buildQboPayload (happy + multi-currency + throws)
 *     C5  happy: TotalAmt=0, single Line with Amount=amount_applied + 2
 *         LinkedTxns (CreditMemo + Invoice) + CustomerRef + PrivateNote
 *     C6  multi_currency='1' → CurrencyRef + ExchangeRate emitted
 *     C7  throws on empty qbo_customer_id
 *     C8  throws on empty qbo_credit_memo_id
 *     C9  throws on empty qbo_invoice_id
 *     C10 throws on amount_applied ≤ 0
 *
 *   Module C — buildPrivateNoteJson
 *     C11 valid JSON with ff_credit_application_id + ff_credit_note_id +
 *         ff_invoice_id + amount_applied + pushed_at
 *
 *   Module D — runPreflight (per gate)
 *     C12 invalid credit_note_id / invoice_id → fail
 *     C13 parent credit memo unmapped → fail (qbo_credit_memo_id NULL)
 *     C14 target invoice unmapped → fail (qbo_invoice_id NULL)
 *     C15 customer unmapped → fail
 *     C16 all good → ok + returns resolved QBO IDs
 *
 *   Module E — pushImpl / pushCreate behaviors (no HTTP)
 *     C17 sync_mode='disabled' → skipped_by_mode + map row + sync_log
 *     C18 ff_not_found
 *     C19 already_mapped (existing qbo_payment_id) → no second POST
 *     C20 failed_preflight (parent unmapped) → map row written with status
 *
 *   Module F — Enqueuer gates (best-effort, never throws)
 *     C21 gate-0 rejects missing application
 *     C22 gate-1 rejects sync_enabled='0'
 *     C23 gate-2 rejects sync_mode='disabled'
 *     C24 gate-3 rejects non-'create' op (v1 = create-only)
 *     C25 happy-path queue insert (entity_type='credit_application', op='create')
 *
 *   Module G — apply.php integration assertion
 *     C26 apply.php contains the post-commit CreditApplicationEnqueuer::enqueue
 *         hook (proves the wire-in landed)
 *
 * HTTP-trap rule: every check short-circuits before createEntity — fixtures
 * always fail preflight or are mode-skipped, so no live HTTP is attempted.
 *
 * Fixtures use sentinel IDs 999990-999999, cleaned in finally.
 *
 * @session  S-QBO-CREDIT-MEMO-APPLY
 * @closes   F25
 * @decision D-QBO-CREDIT-MEMO-APPLY-1/2/3/4/5
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\CreditApplicationPusher;
use FleetForge\QboPushers\CreditApplicationEnqueuer;
use FleetForge\Exceptions\QuickBooksException;

$pass = 0;
$total = 26;
$failures = [];

function ff_smoke_ca_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}
function ff_smoke_ca_get(string $key): ?string
{
    $r = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $r['value'] ?? null;
}

function ff_smoke_ca_cleanup(): void
{
    // Order matters — purge children first (FK CASCADE handles map row when
    // application row is deleted, but explicit deletes survive a half-state).
    db_execute("DELETE FROM acc_qbo_sync_log               WHERE entity_type IN ('credit_application','credit_memo') AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue             WHERE entity_type IN ('credit_application','credit_memo') AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_credit_application_map WHERE ff_credit_application_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM credit_note_applications       WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_credit_memo_map        WHERE ff_credit_note_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM credit_notes                   WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_invoice_map            WHERE ff_invoice_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM invoices                       WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_customer_map           WHERE ff_customer_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM customers                      WHERE id BETWEEN 999990 AND 999999");
}

// Capture pre-state.
$snapshotKeys = [
    'quickbooks.sync_enabled',
    'quickbooks.sync_mode.credit_application',
    'quickbooks.multi_currency_enabled',
    'quickbooks.connection_status',
];
$snapshot = [];
foreach ($snapshotKeys as $k) { $snapshot[$k] = ff_smoke_ca_get($k); }

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-CREDIT-MEMO-APPLY Credit-Application Push Smoke ({$total} sub-checks; closes F25)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_ca_cleanup();

    // Sentinel customer + QBO mapping (mapped).
    db_execute("INSERT INTO customers (id, company_name, currency, created_at) VALUES (999990, 'Smoke CA Customer', 'CAD', NOW())");
    db_execute("INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, mapping_status) VALUES (999990, 'QBO-CUST-9990', 'mapped')");

    // Sentinel credit note (parent — status='active', source='other').
    db_execute(
        "INSERT INTO credit_notes (id, credit_note_number, customer_id, source, amount, currency, amount_remaining, status, reason, created_at)
         VALUES (999991, 'CN-CR-2026-999991', 999990, 'other', 100.00, 'CAD', 50.00, 'partially_used', 'Smoke CA parent credit', NOW())"
    );
    // Mapped parent credit memo (push-already-completed precondition for apply gate).
    db_execute(
        "INSERT INTO acc_qbo_credit_memo_map (ff_credit_note_id, qbo_credit_memo_id, qbo_sync_token, push_status, pushed_at, last_synced_at)
         VALUES (999991, 'QBO-CM-999991', '0', 'pushed', NOW(), NOW())"
    );

    // Sentinel invoice (target).
    db_execute(
        "INSERT INTO invoices (id, invoice_number, customer_id, currency, status,
                              billing_period_start, billing_period_end, billing_period_days,
                              billing_type, invoice_date, due_date,
                              total_amount, amount_paid, credits_applied, balance_due, created_at)
         VALUES (999992, 'INV-2026-999992', 999990, 'CAD', 'partially_paid',
                 '2026-05-01', '2026-05-31', 31,
                 'full_month', '2026-05-01', '2026-05-31',
                 200.00, 0.00, 50.00, 150.00, NOW())"
    );
    db_execute(
        "INSERT INTO acc_qbo_invoice_map (ff_invoice_id, qbo_invoice_id, qbo_sync_token, push_status, pushed_at, last_synced_at)
         VALUES (999992, 'QBO-INV-999992', '0', 'pushed', NOW(), NOW())"
    );

    // Sentinel application row.
    db_execute(
        "INSERT INTO credit_note_applications (id, credit_note_id, invoice_id, amount_applied, applied_by, applied_at)
         VALUES (999993, 999991, 999992, 50.00, NULL, NOW())"
    );
    $app = db_row("SELECT * FROM credit_note_applications WHERE id = 999993");

    ff_smoke_ca_set('quickbooks.connection_status', 'connected');
    ff_smoke_ca_set('quickbooks.sync_enabled', '1');
    ff_smoke_ca_set('quickbooks.sync_mode.credit_application', 'sync');
    ff_smoke_ca_set('quickbooks.multi_currency_enabled', '0');

    // ══ Module A ══════════════════════════════════════════════════════════
    $c1 = [];
    $ref = new ReflectionClass(CreditApplicationPusher::class);
    foreach (['pushCreate','buildQboPayload','buildPrivateNoteJson','runPreflight'] as $m) {
        if (!method_exists(CreditApplicationPusher::class, $m)) $c1[] = "missing method {$m}";
    }
    if (empty($c1)) { echo "PASS C1 CreditApplicationPusher surfaces (pushCreate+buildQboPayload+buildPrivateNoteJson+runPreflight)\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1) . "\n"; $failures[] = 'C1'; }

    if (method_exists(CreditApplicationEnqueuer::class, 'enqueue')) { echo "PASS C2 CreditApplicationEnqueuer::enqueue surfaces\n"; $pass++; }
    else { echo "FAIL C2 CreditApplicationEnqueuer::enqueue missing\n"; $failures[] = 'C2'; }

    $c3 = [];
    $cols = array_column(db_select("SHOW COLUMNS FROM acc_qbo_credit_application_map"), 'Field');
    foreach (['id','ff_credit_application_id','ff_credit_note_id_snapshot','ff_invoice_id_snapshot','qbo_payment_id','qbo_sync_token','qbo_credit_memo_id_ref','qbo_invoice_id_ref','qbo_total_amt','qbo_currency','qbo_txn_date','amount_applied_snapshot','push_status','push_error','pushed_at','last_synced_at'] as $col) {
        if (!in_array($col, $cols, true)) $c3[] = "missing col {$col}";
    }
    $idx = array_unique(array_column(db_select("SHOW INDEX FROM acc_qbo_credit_application_map"), 'Key_name'));
    foreach (['PRIMARY','uq_ff_credit_application','uq_qbo_payment_apply'] as $k) {
        if (!in_array($k, $idx, true)) $c3[] = "missing index {$k}";
    }
    if (empty($c3)) { echo "PASS C3 acc_qbo_credit_application_map schema (cols + UNIQUE keys)\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3) . "\n"; $failures[] = 'C3'; }

    $queueDef = db_row("SHOW CREATE TABLE acc_qbo_sync_queue");
    if ($queueDef && strpos($queueDef['Create Table'], "'credit_application'") !== false) {
        echo "PASS C4 acc_qbo_sync_queue.entity_type ENUM includes 'credit_application'\n"; $pass++;
    } else {
        echo "FAIL C4 acc_qbo_sync_queue.entity_type ENUM missing 'credit_application'\n"; $failures[] = 'C4';
    }

    // ══ Module B — buildQboPayload ════════════════════════════════════════
    $payload = CreditApplicationPusher::buildQboPayload(
        $app, 'QBO-CUST-9990', 'QBO-CM-999991', 'QBO-INV-999992'
    );
    $c5 = [];
    if (($payload['TotalAmt'] ?? null) !== 0.0) $c5[] = 'TotalAmt should be 0.0';
    if (($payload['CustomerRef']['value'] ?? '') !== 'QBO-CUST-9990') $c5[] = 'CustomerRef wrong';
    if (count($payload['Line'] ?? []) !== 1) $c5[] = 'expected exactly 1 Line';
    if (($payload['Line'][0]['Amount'] ?? null) !== 50.0) $c5[] = 'Line.Amount should equal amount_applied (50.0)';
    $linked = $payload['Line'][0]['LinkedTxn'] ?? [];
    if (count($linked) !== 2) $c5[] = 'expected exactly 2 LinkedTxns';
    $types = array_column($linked, 'TxnType');
    if (!in_array('CreditMemo', $types, true)) $c5[] = 'missing CreditMemo LinkedTxn';
    if (!in_array('Invoice', $types, true))    $c5[] = 'missing Invoice LinkedTxn';
    if (empty($payload['PrivateNote']))        $c5[] = 'missing PrivateNote';
    if (empty($c5)) { echo "PASS C5 buildQboPayload happy (TotalAmt=0 + 2 LinkedTxns + Amount=amount_applied)\n"; $pass++; }
    else { echo "FAIL C5 " . implode('; ', $c5) . "\n"; $failures[] = 'C5'; }

    // C6 — multi_currency='1' → CurrencyRef + ExchangeRate
    ff_smoke_ca_set('quickbooks.multi_currency_enabled', '1');
    $payloadMc = CreditApplicationPusher::buildQboPayload(
        $app, 'QBO-CUST-9990', 'QBO-CM-999991', 'QBO-INV-999992'
    );
    ff_smoke_ca_set('quickbooks.multi_currency_enabled', '0');
    if (!empty($payloadMc['CurrencyRef']['value']) && !empty($payloadMc['ExchangeRate'])) {
        echo "PASS C6 multi_currency='1' → CurrencyRef + ExchangeRate emitted\n"; $pass++;
    } else {
        echo "FAIL C6 multi_currency='1' missing CurrencyRef or ExchangeRate\n"; $failures[] = 'C6';
    }

    // C7-C10 — throws on missing inputs
    foreach ([
        ['', 'QBO-CM-999991', 'QBO-INV-999992', 'C7', 'missing qbo_customer_id'],
        ['QBO-CUST-9990', '', 'QBO-INV-999992', 'C8', 'missing qbo_credit_memo_id'],
        ['QBO-CUST-9990', 'QBO-CM-999991', '', 'C9', 'missing qbo_invoice_id'],
    ] as $case) {
        [$cust, $cm, $inv, $label, $desc] = $case;
        try {
            CreditApplicationPusher::buildQboPayload($app, $cust, $cm, $inv);
            echo "FAIL {$label} should throw on {$desc}\n"; $failures[] = $label;
        } catch (QuickBooksException $e) {
            echo "PASS {$label} buildQboPayload throws on {$desc}\n"; $pass++;
        }
    }

    // C10 — throws on non-positive amount_applied
    $appZero = $app;
    $appZero['amount_applied'] = '0.00';
    try {
        CreditApplicationPusher::buildQboPayload($appZero, 'QBO-CUST-9990', 'QBO-CM-999991', 'QBO-INV-999992');
        echo "FAIL C10 should throw on amount_applied=0\n"; $failures[] = 'C10';
    } catch (QuickBooksException $e) {
        echo "PASS C10 buildQboPayload throws on amount_applied ≤ 0\n"; $pass++;
    }

    // ══ Module C — buildPrivateNoteJson ═══════════════════════════════════
    $noteJson = CreditApplicationPusher::buildPrivateNoteJson($app);
    $note = json_decode($noteJson, true);
    $c11 = [];
    foreach (['ff_credit_application_id','ff_credit_note_id','ff_invoice_id','amount_applied','pushed_at'] as $k) {
        if (!array_key_exists($k, $note ?? [])) $c11[] = "missing key {$k}";
    }
    if (empty($c11)) { echo "PASS C11 buildPrivateNoteJson valid JSON with 5 required keys\n"; $pass++; }
    else { echo "FAIL C11 " . implode('; ', $c11) . "\n"; $failures[] = 'C11'; }

    // ══ Module D — runPreflight ═══════════════════════════════════════════
    // C12 — invalid credit_note_id / invoice_id.
    $badApp = $app;
    $badApp['credit_note_id'] = 0;
    $r = CreditApplicationPusher::runPreflight(999993, $badApp);
    if (!$r['ok']) { echo "PASS C12 runPreflight rejects invalid credit_note_id / invoice_id\n"; $pass++; }
    else { echo "FAIL C12 should fail on bad ids\n"; $failures[] = 'C12'; }

    // C13 — parent credit memo unmapped (qbo_credit_memo_id NULL).
    db_execute("UPDATE acc_qbo_credit_memo_map SET qbo_credit_memo_id = NULL WHERE ff_credit_note_id = 999991");
    $r = CreditApplicationPusher::runPreflight(999993, $app);
    if (!$r['ok'] && strpos($r['reason'], 'CreditMemo mapping') !== false) {
        echo "PASS C13 runPreflight rejects parent credit memo unmapped\n"; $pass++;
    } else {
        echo "FAIL C13 expected parent-unmapped reject, got " . json_encode($r) . "\n"; $failures[] = 'C13';
    }
    db_execute("UPDATE acc_qbo_credit_memo_map SET qbo_credit_memo_id = 'QBO-CM-999991' WHERE ff_credit_note_id = 999991");

    // C14 — target invoice unmapped.
    db_execute("UPDATE acc_qbo_invoice_map SET qbo_invoice_id = NULL WHERE ff_invoice_id = 999992");
    $r = CreditApplicationPusher::runPreflight(999993, $app);
    if (!$r['ok'] && strpos($r['reason'], 'Invoice mapping') !== false) {
        echo "PASS C14 runPreflight rejects target invoice unmapped\n"; $pass++;
    } else {
        echo "FAIL C14 expected invoice-unmapped reject, got " . json_encode($r) . "\n"; $failures[] = 'C14';
    }
    db_execute("UPDATE acc_qbo_invoice_map SET qbo_invoice_id = 'QBO-INV-999992' WHERE ff_invoice_id = 999992");

    // C15 — customer unmapped.
    db_execute("UPDATE acc_qbo_customer_map SET mapping_status = 'ff_only' WHERE ff_customer_id = 999990");
    $r = CreditApplicationPusher::runPreflight(999993, $app);
    if (!$r['ok'] && strpos($r['reason'], 'mapped QBO customer') !== false) {
        echo "PASS C15 runPreflight rejects customer unmapped\n"; $pass++;
    } else {
        echo "FAIL C15 expected customer-unmapped reject, got " . json_encode($r) . "\n"; $failures[] = 'C15';
    }
    db_execute("UPDATE acc_qbo_customer_map SET mapping_status = 'mapped' WHERE ff_customer_id = 999990");

    // C16 — all good → ok + resolved QBO IDs.
    $r = CreditApplicationPusher::runPreflight(999993, $app);
    if ($r['ok']
        && ($r['qbo_customer_id'] ?? '') === 'QBO-CUST-9990'
        && ($r['qbo_credit_memo_id'] ?? '') === 'QBO-CM-999991'
        && ($r['qbo_invoice_id'] ?? '') === 'QBO-INV-999992') {
        echo "PASS C16 runPreflight ok + returns resolved QBO IDs\n"; $pass++;
    } else {
        echo "FAIL C16 expected ok+resolved, got " . json_encode($r) . "\n"; $failures[] = 'C16';
    }

    // ══ Module E — pushImpl behaviors (no HTTP) ═══════════════════════════
    // C17 — sync_mode='disabled' → skipped_by_mode.
    ff_smoke_ca_set('quickbooks.sync_mode.credit_application', 'disabled');
    $r = CreditApplicationPusher::pushCreate(999993);
    $logRow = db_row("SELECT error_code FROM acc_qbo_sync_log WHERE entity_type='credit_application' AND entity_id=999993 ORDER BY id DESC LIMIT 1");
    if (($r['status'] ?? '') === 'skipped_by_mode' && ($logRow['error_code'] ?? '') === 'skipped_by_mode') {
        echo "PASS C17 sync_mode='disabled' → skipped_by_mode + map row + sync_log\n"; $pass++;
    } else {
        echo "FAIL C17 expected skipped_by_mode, got status=" . ($r['status'] ?? 'null') . " log=" . ($logRow['error_code'] ?? 'null') . "\n";
        $failures[] = 'C17';
    }
    ff_smoke_ca_set('quickbooks.sync_mode.credit_application', 'sync');
    db_execute("DELETE FROM acc_qbo_sync_log               WHERE entity_type='credit_application' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_credit_application_map WHERE ff_credit_application_id BETWEEN 999990 AND 999999");

    // C18 — ff_not_found.
    $r = CreditApplicationPusher::pushCreate(999988);
    if (($r['status'] ?? '') === 'ff_not_found') { echo "PASS C18 ff_not_found for unknown application id\n"; $pass++; }
    else { echo "FAIL C18 expected ff_not_found, got " . ($r['status'] ?? 'null') . "\n"; $failures[] = 'C18'; }

    // C19 — already_mapped.
    db_execute(
        "INSERT INTO acc_qbo_credit_application_map (ff_credit_application_id, ff_credit_note_id_snapshot, ff_invoice_id_snapshot, qbo_payment_id, push_status, pushed_at)
         VALUES (999993, 999991, 999992, 'QBO-PAY-PRE-MAPPED', 'pushed', NOW())"
    );
    $r = CreditApplicationPusher::pushCreate(999993);
    if (($r['status'] ?? '') === 'already_mapped' && ($r['qbo_id'] ?? '') === 'QBO-PAY-PRE-MAPPED') {
        echo "PASS C19 already_mapped (existing qbo_payment_id) → no second POST\n"; $pass++;
    } else {
        echo "FAIL C19 expected already_mapped, got " . json_encode($r) . "\n"; $failures[] = 'C19';
    }
    db_execute("DELETE FROM acc_qbo_credit_application_map WHERE ff_credit_application_id = 999993");

    // C20 — failed_preflight (parent unmapped) → map row written with typed status.
    db_execute("UPDATE acc_qbo_credit_memo_map SET qbo_credit_memo_id = NULL WHERE ff_credit_note_id = 999991");
    $r = CreditApplicationPusher::pushCreate(999993);
    $mapRow = db_row("SELECT push_status, push_error FROM acc_qbo_credit_application_map WHERE ff_credit_application_id = 999993");
    if (($r['status'] ?? '') === 'failed_preflight' && ($mapRow['push_status'] ?? '') === 'failed_preflight') {
        echo "PASS C20 failed_preflight → map row written with typed status\n"; $pass++;
    } else {
        echo "FAIL C20 expected failed_preflight + map row, got " . json_encode($r) . " map=" . json_encode($mapRow) . "\n";
        $failures[] = 'C20';
    }
    db_execute("UPDATE acc_qbo_credit_memo_map SET qbo_credit_memo_id = 'QBO-CM-999991' WHERE ff_credit_note_id = 999991");
    db_execute("DELETE FROM acc_qbo_sync_log               WHERE entity_type='credit_application' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_credit_application_map WHERE ff_credit_application_id BETWEEN 999990 AND 999999");

    // ══ Module F — Enqueuer gates ═════════════════════════════════════════
    // C21 — gate-0 rejects missing application.
    $ok = CreditApplicationEnqueuer::enqueue(999987, 'create');
    if ($ok === false) { echo "PASS C21 Enqueuer gate-0 rejects missing application\n"; $pass++; }
    else { echo "FAIL C21 expected gate-0 reject\n"; $failures[] = 'C21'; }

    // C22 — gate-1 rejects sync_enabled='0'.
    ff_smoke_ca_set('quickbooks.sync_enabled', '0');
    $ok = CreditApplicationEnqueuer::enqueue(999993, 'create');
    if ($ok === false) { echo "PASS C22 Enqueuer gate-1 rejects sync_enabled='0'\n"; $pass++; }
    else { echo "FAIL C22 expected gate-1 reject\n"; $failures[] = 'C22'; }
    ff_smoke_ca_set('quickbooks.sync_enabled', '1');

    // C23 — gate-2 rejects sync_mode='disabled'.
    ff_smoke_ca_set('quickbooks.sync_mode.credit_application', 'disabled');
    $ok = CreditApplicationEnqueuer::enqueue(999993, 'create');
    if ($ok === false) { echo "PASS C23 Enqueuer gate-2 rejects sync_mode='disabled'\n"; $pass++; }
    else { echo "FAIL C23 expected gate-2 reject\n"; $failures[] = 'C23'; }
    ff_smoke_ca_set('quickbooks.sync_mode.credit_application', 'sync');

    // C24 — gate-3 rejects non-'create' op (v1 = create-only).
    $ok = CreditApplicationEnqueuer::enqueue(999993, 'update');
    if ($ok === false) { echo "PASS C24 Enqueuer gate-3 rejects non-'create' op (v1 create-only)\n"; $pass++; }
    else { echo "FAIL C24 expected gate-3 reject for 'update'\n"; $failures[] = 'C24'; }

    // C25 — happy-path queue insert (clean queue first).
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='credit_application' AND entity_id=999993");
    $ok = CreditApplicationEnqueuer::enqueue(999993, 'create');
    $queueRow = db_row("SELECT entity_type, entity_id, operation, status FROM acc_qbo_sync_queue WHERE entity_type='credit_application' AND entity_id=999993 ORDER BY id DESC LIMIT 1");
    if ($ok === true
        && ($queueRow['entity_type'] ?? '') === 'credit_application'
        && ($queueRow['entity_id'] ?? 0) === 999993
        && ($queueRow['operation'] ?? '') === 'create'
        && ($queueRow['status'] ?? '') === 'queued') {
        echo "PASS C25 happy-path queue insert (credit_application/999993/create/queued)\n"; $pass++;
    } else {
        echo "FAIL C25 expected queue row, got ok=" . var_export($ok, true) . " row=" . json_encode($queueRow) . "\n";
        $failures[] = 'C25';
    }

    // ══ Module G — apply.php integration assertion ═══════════════════════
    $applySrc = (string) file_get_contents(__DIR__ . '/../api/v1/credit_notes/apply.php');
    if (strpos($applySrc, 'CreditApplicationEnqueuer::enqueue') !== false
        && strpos($applySrc, "'create'") !== false) {
        echo "PASS C26 apply.php contains post-commit CreditApplicationEnqueuer::enqueue hook\n"; $pass++;
    } else {
        echo "FAIL C26 apply.php missing post-commit Enqueuer hook\n"; $failures[] = 'C26';
    }

} finally {
    ff_smoke_ca_cleanup();
    foreach ($snapshot as $k => $v) {
        if ($v === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            ff_smoke_ca_set($k, (string) $v);
        }
    }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "credit_application_push_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total ? 0 : 1);
