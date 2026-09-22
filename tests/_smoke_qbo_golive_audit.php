<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_golive_audit.php
 *
 * S-QBO-GOLIVE-AUDIT — regression smoke for the go-live audit fixes to the
 * QuickBooks module (pre real-company connection). Runs OFFLINE: every
 * check either short-circuits before HTTP or uses the fixture layer.
 *
 * HERMETIC: the whole run happens inside ONE outer DB transaction that is
 * rolled back in `finally` — sentinel rows, settings flips, sync-log rows,
 * the destructive RealmGuard::resetMappings() call all vanish. Nested
 * db_transaction() calls join the outer one (includes/db.php).
 *
 * Sub-checks:
 *   A  CurrencyGuard            C1–C3   single-currency company blocks USD pushes
 *   B  RealmGuard               C4–C9   table coverage, connect verdicts, client
 *                                       refusal, reset wipes + refuses while syncing
 *   C  Invoice line Qty×Price   C10–C11 reconcile rule + builder omits Qty/UnitPrice
 *   D  JE counterparty          C12–C13 AR/AP party rule + Entity resolution
 *   E  Webhook                  C14–C16 legacy + CloudEvents normalize; FF echo skip
 *   F  Worker helpers           C17–C18 release + transient defer/fail policy
 *   G  Client internals         C19–C23 requestid determinism/epoch, failure memo,
 *                                       secrets at rest, minorversion, pay link
 *   H  Enqueuer gates           C24–C26 post-send invoice + applied credit create
 *   I  JE bridge filter         C27     damage_recovery/damage_repair excluded
 *   J  Drift live layer         C28     cutover filter + pagination in the query
 *   K  Queue dedupe + worker    C29–C30 pending-job dedupe (create vs update);
 *                                       preflight outcomes never "transient"
 *   L  Go-live linker           C31–C39 matcher confidences, link / refusals /
 *                                       unlink, linked docs never updated or
 *                                       voided, pre-go-live push guard, QBO
 *                                       payment import (origin qbo_other,
 *                                       paid_date = QBO date), realm reset keys
 *   M  Drift ownership          C40     shared file: only FF's customers /
 *                                       vendors / Class / Location count
 *   N  Canadian invoice tax     C41     per-rate: FF tax rate → mapped QBO code,
 *                                       exact GST/PST TaxLines, exempt lines
 *   O  Rounding residual        C42     QBO paid + FF ≤5¢ short → adjustment
 *                                       credit note, never pushed
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

require_once __DIR__ . '/../api/bootstrap.php';

// Worker helpers only (no worker body) — FF_QBO_WORKER_INCLUDE early return.
define('FF_QBO_WORKER_INCLUDE', true);
require_once FF_ROOT . '/cron/qbo_sync_worker.php';

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Exceptions\QuickBooksTransientException;
use FleetForge\Exceptions\QuickBooksRateLimitException;
use FleetForge\QboPushers\CurrencyGuard;
use FleetForge\QboPushers\RealmGuard;
use FleetForge\QboPushers\InvoiceLineBuilder;
use FleetForge\QboPushers\JournalEntryPusher;
use FleetForge\QboPushers\JournalEntryEnqueuer;
use FleetForge\QboPushers\PaymentWebhookHandler;
use FleetForge\QboPushers\InvoiceEnqueuer;
use FleetForge\QboPushers\CreditMemoEnqueuer;
use FleetForge\QboPushers\DriftChecker;
use FleetForge\QboPushers\InvoiceLinker;
use FleetForge\QboPushers\InvoicePusher;
use FleetForge\QboPushers\CreditMemoPusher;
use FleetForge\QboPushers\InvoiceTaxPerRate;
use FleetForge\QboPushers\RoundingSettler;

$pass     = 0;
$total    = 42;
$failures = [];

/** Record one sub-check. */
function ff_ga_check(string $id, string $label, array $errs): void
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

/** Raw settings write (bypasses settings_write_qbo encryption). */
function ff_ga_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`)
         VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-GOLIVE-AUDIT smoke ({$total} sub-checks; hermetic — rolled back)\n";
echo "═══════════════════════════════════════════════════════════\n";

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    // ── Shared fixture (sentinel ids 999970-999979) ───────────────────
    db_execute("INSERT INTO customers (id, company_name, currency, created_at) VALUES (999970, 'Smoke GoLive Customer', 'CAD', NOW())");
    db_execute("INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, mapping_status) VALUES (999970, 'SMOKE-GL-1', 'mapped')");
    db_execute(
        "INSERT INTO credit_notes (id, credit_note_number, customer_id, source, amount, currency, amount_remaining, status, reason, created_at)
         VALUES (999971, 'CN-GL-2026-999971', 999970, 'other', 100.00, 'CAD', 50.00, 'partially_used', 'Smoke go-live credit', NOW())"
    );
    db_execute(
        "INSERT INTO invoices (id, invoice_number, customer_id, currency, status,
                              billing_period_start, billing_period_end, billing_period_days,
                              billing_type, invoice_date, due_date,
                              total_amount, amount_paid, credits_applied, balance_due, created_at)
         VALUES (999972, 'INV-GL-999972', 999970, 'CAD', 'overdue',
                 '2026-05-01', '2026-05-31', 31, 'full_month', '2026-05-01', '2026-05-31',
                 200.00, 0.00, 50.00, 150.00, NOW())"
    );
    db_execute(
        "INSERT INTO credit_note_applications (id, credit_note_id, invoice_id, amount_applied, applied_by, applied_at)
         VALUES (999973, 999971, 999972, 50.00, NULL, NOW())"
    );
    ff_ga_set('quickbooks.multi_currency_enabled', '0');
    ff_ga_set('quickbooks.home_currency', 'CAD');
    ff_ga_set('quickbooks.realm_mismatch', '0');
    ff_ga_set('quickbooks.fixture_mode', '0');

    // ══ A — CurrencyGuard ═════════════════════════════════════════════
    $r = CurrencyGuard::blockReason('USD', 'Invoice X');
    ff_ga_check('C1', 'single-currency company blocks a USD record', (is_string($r) && str_contains($r, 'single-currency')) ? [] : ['got ' . var_export($r, true)]);

    $e = [];
    if (CurrencyGuard::blockReason('CAD', 'x') !== null) { $e[] = 'CAD blocked'; }
    if (CurrencyGuard::blockReason('', 'x') !== null)    { $e[] = "'' blocked"; }
    if (CurrencyGuard::blockReason(null, 'x') !== null)  { $e[] = 'null blocked'; }
    ff_ga_check('C2', 'home-currency / legacy-empty records pass', $e);

    ff_ga_set('quickbooks.multi_currency_enabled', '1');
    ff_ga_check('C3', 'multi-currency company lets USD through', CurrencyGuard::blockReason('USD', 'x') === null ? [] : ['USD blocked with multi-currency on']);
    ff_ga_set('quickbooks.multi_currency_enabled', '0');

    // ══ B — RealmGuard ════════════════════════════════════════════════
    $e = [];
    $schemaMaps = array_column(db_select(
        "SELECT TABLE_NAME AS t FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'acc\\_qbo\\_%\\_map'"
    ), 't');
    foreach ($schemaMaps as $t) {
        if (!in_array($t, RealmGuard::REALM_SCOPED_TABLES, true)) { $e[] = "{$t} not in REALM_SCOPED_TABLES"; }
    }
    foreach (RealmGuard::REALM_SCOPED_TABLES as $t) {
        if (!in_array($t, $schemaMaps, true)) { $e[] = "{$t} listed but absent from schema"; }
    }
    ff_ga_check('C4', 'REALM_SCOPED_TABLES == every acc_qbo_*_map table (' . count($schemaMaps) . ')', $e);

    ff_ga_set('quickbooks.mapped_realm_id', 'SMOKE-REALM-A');
    ff_ga_set('quickbooks.sync_enabled', '1');
    $v = RealmGuard::onConnect('SMOKE-REALM-B');
    $e = [];
    if ($v['mismatch'] !== true) { $e[] = 'verdict not mismatch'; }
    if ((string) settings_get('quickbooks.realm_mismatch', '') !== '1') { $e[] = 'realm_mismatch not 1'; }
    if ((string) settings_get('quickbooks.sync_enabled', '') !== '0')   { $e[] = 'sync_enabled not forced 0'; }
    ff_ga_check('C5', 'connect to a different company → mismatch + sync forced off', $e);

    ff_ga_set('quickbooks.realm_id', 'SMOKE-REALM-B');
    $e = [];
    if (QuickBooksClient::realmGuardReason() === null) { $e[] = 'realmGuardReason null'; }
    try {
        (new QuickBooksClient())->query('SELECT * FROM Customer');
        $e[] = 'query did not throw';
    } catch (QuickBooksException $ex) {
        if ($ex->errorCode !== 'realm_mismatch') { $e[] = 'errorCode=' . var_export($ex->errorCode, true); }
    }
    ff_ga_check('C6', 'client refuses every non-CompanyInfo call while mismatched (no HTTP)', $e);

    $v = RealmGuard::onConnect('SMOKE-REALM-A');
    $e = [];
    if ($v['mismatch'] !== false) { $e[] = 'reconnect to mapped company flagged mismatch'; }
    if ((string) settings_get('quickbooks.realm_mismatch', '') !== '0') { $e[] = 'realm_mismatch not cleared'; }
    ff_ga_check('C7', 'reconnect to the mapped company resumes cleanly', $e);

    ff_ga_set('quickbooks.sync_enabled', '0');
    ff_ga_set('quickbooks.connection_status', 'connected');
    ff_ga_set('quickbooks.realm_id', 'SMOKE-REALM-B');
    ff_ga_set('quickbooks.realm_mismatch', '1');
    ff_ga_set('quickbooks.tax_override_code_id', 'OLD-TAX-7');
    $e = [];
    $removed = RealmGuard::resetMappings(null, 'smoke');
    foreach (RealmGuard::REALM_SCOPED_TABLES as $t) {
        try {
            $n = db_count("SELECT COUNT(*) FROM `{$t}`");
            if ($n !== 0) { $e[] = "{$t} still has {$n} rows"; }
        } catch (\Throwable $ex) { /* absent table */ }
    }
    if ((string) settings_get('quickbooks.mapped_realm_id', '') !== 'SMOKE-REALM-B') { $e[] = 'mapped_realm_id not adopted'; }
    if ((string) settings_get('quickbooks.realm_mismatch', '') !== '0') { $e[] = 'realm_mismatch not cleared'; }
    if ((string) settings_get('quickbooks.tax_override_code_id', 'x') !== '') { $e[] = 'old tax override id kept'; }
    if (($removed['acc_qbo_customer_map'] ?? 0) < 1) { $e[] = 'customer map count not reported'; }
    if (QuickBooksClient::realmGuardReason() !== null) { $e[] = 'guard still active'; }
    ff_ga_check('C8', 'resetMappings wipes all realm-scoped rows + adopts the connected company', $e);

    ff_ga_set('quickbooks.sync_enabled', '1');
    $e = ['did not refuse'];
    try { RealmGuard::resetMappings(null, 'smoke'); } catch (\RuntimeException $ex) { $e = []; }
    ff_ga_set('quickbooks.sync_enabled', '0');
    ff_ga_check('C9', 'resetMappings refuses while master sync is on', $e);

    // Re-seed the customer mapping the reset removed (used by D/E below).
    db_execute("INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, mapping_status) VALUES (999970, 'SMOKE-GL-1', 'mapped')");

    // ══ C — Invoice line Qty × UnitPrice ══════════════════════════════
    $e = [];
    foreach ([
        ['17.0000', '45.33', '770.61', true],
        ['17.5000', '45.33', '793.28', true],   // 793.275 → 793.28
        ['17.5000', '45.33', '793.27', false],
        ['1234.5678', '0.12', '148.15', true],  // 148.148136 → 148.15
        ['1.0000', '99.99', '99.99', true],
        ['abc', '1', '1', false],
    ] as [$q, $p, $a, $want]) {
        if (InvoiceLineBuilder::qtyPriceReconciles($q, $p, $a) !== $want) { $e[] = "{$q}×{$p} vs {$a} expected " . var_export($want, true); }
    }
    ff_ga_check('C10', 'qtyPriceReconciles matches QBO Amount = Qty × UnitPrice rule', $e);

    db_execute("INSERT INTO acc_qbo_item_map (ff_item_type, ff_item_type_variant, qbo_item_id, qbo_name, mapping_status)
                VALUES ('base_rental', NULL, 'SMOKE-ITEM-1', 'Smoke Rental', 'mapped')");
    ff_ga_set('quickbooks.tax_override_code_id', 'SMOKE-TAX');
    $lines = InvoiceLineBuilder::build(['id' => 1], ['id' => 1], [
        ['item_type' => 'base_rental', 'quantity' => '2.0000', 'unit_price' => '50.00', 'amount' => '100.00', 'description' => 'ok'],
        ['item_type' => 'base_rental', 'quantity' => '17.5000', 'unit_price' => '45.33', 'amount' => '793.27', 'description' => 'prorated'],
        ['item_type' => 'base_rental', 'quantity' => '1.0000', 'unit_price' => '10.38', 'amount' => '10.38', 'is_credit' => 1, 'description' => 'reconciliation credit'],
    ]);
    $e = [];
    if (!isset($lines[0]['SalesItemLineDetail']['Qty'], $lines[0]['SalesItemLineDetail']['UnitPrice'])) { $e[] = 'reconciling line lost Qty/UnitPrice'; }
    if (isset($lines[1]['SalesItemLineDetail']['Qty']) || isset($lines[1]['SalesItemLineDetail']['UnitPrice'])) { $e[] = 'non-reconciling line still sends Qty/UnitPrice'; }
    if (($lines[1]['Amount'] ?? null) !== '793.27') { $e[] = 'Amount altered'; }
    // K-16: positive amount + is_credit=1 must reach QBO as a NEGATIVE line.
    if (($lines[2]['Amount'] ?? null) !== '-10.38') { $e[] = 'credit line Amount=' . json_encode($lines[2]['Amount'] ?? null) . ' (expected -10.38)'; }
    if (($lines[2]['SalesItemLineDetail']['UnitPrice'] ?? null) !== '-10.38') { $e[] = 'credit line UnitPrice not negated'; }
    ff_ga_check('C11', 'InvoiceLineBuilder: Qty/UnitPrice only when they reconcile; is_credit lines go negative', $e);

    // ══ D — JE counterparty ═══════════════════════════════════════════
    $e = [];
    if (JournalEntryPusher::requiredLineParty('Accounts Receivable') !== 'Customer') { $e[] = 'AR'; }
    if (JournalEntryPusher::requiredLineParty('Accounts Payable') !== 'Vendor')      { $e[] = 'AP'; }
    if (JournalEntryPusher::requiredLineParty('AccountsReceivable') !== 'Customer')  { $e[] = 'AR (no space)'; }
    if (JournalEntryPusher::requiredLineParty('Bank') !== null)                      { $e[] = 'Bank'; }
    ff_ga_check('C12', 'requiredLineParty: AR→Customer, AP→Vendor, other→none', $e);

    $e = [];
    $ent = JournalEntryPusher::lineEntity(['customer_id' => 999970, 'vendor_id' => null], null);
    if ($ent !== ['Type' => 'Customer', 'EntityRef' => ['value' => 'SMOKE-GL-1']]) { $e[] = 'customer entity ' . json_encode($ent); }
    if (JournalEntryPusher::lineEntity(['customer_id' => 999970], 'Vendor') !== null) { $e[] = 'Vendor demand satisfied by a customer'; }
    if (JournalEntryPusher::lineEntity(['customer_id' => null, 'vendor_id' => null], null) !== null) { $e[] = 'party-less line got an entity'; }
    ff_ga_check('C13', 'lineEntity resolves the mapped QBO customer as JournalEntryLineDetail.Entity', $e);

    // ══ E — Webhook ═══════════════════════════════════════════════════
    $legacy = PaymentWebhookHandler::normalizeEvents(['eventNotifications' => [[
        'realmId' => 'R1',
        'dataChangeEvent' => ['entities' => [['name' => 'Payment', 'id' => '55', 'operation' => 'Create']]],
    ]]]);
    ff_ga_check('C14', 'legacy eventNotifications envelope still parses',
        ($legacy === [['realm_id' => 'R1', 'name' => 'Payment', 'entity_id' => '55', 'operation' => 'Create', 'event_id' => '']]) ? [] : [json_encode($legacy)]);

    $ce = PaymentWebhookHandler::normalizeEvents([
        ['specversion' => '1.0', 'id' => 'evt-1', 'type' => 'qbo.payment.created.v1', 'intuitaccountid' => 'R2', 'intuitentityid' => '77'],
        ['specversion' => '1.0', 'id' => 'evt-2', 'type' => 'qbo.payment.voided.v1',  'intuitaccountid' => 'R2', 'intuitentityid' => '78'],
        ['specversion' => '1.0', 'id' => 'evt-3', 'type' => 'com.other.thing.v1'],
    ]);
    $e = [];
    if (count($ce) !== 2) { $e[] = 'expected 2 qbo events, got ' . count($ce); }
    if (($ce[0] ?? []) !== ['realm_id' => 'R2', 'name' => 'Payment', 'entity_id' => '77', 'operation' => 'Create', 'event_id' => 'evt-1']) { $e[] = 'created → ' . json_encode($ce[0] ?? null); }
    if (($ce[1]['operation'] ?? '') !== 'Void') { $e[] = 'voided not mapped to Void'; }
    ff_ga_check('C15', 'CloudEvents array normalizes to the handler\'s event shape', $e);

    ff_ga_set('quickbooks.realm_id', 'SMOKE-REALM-B');
    db_execute(
        "INSERT INTO acc_qbo_credit_application_map (ff_credit_application_id, ff_credit_note_id_snapshot, ff_invoice_id_snapshot, qbo_payment_id, push_status)
         VALUES (999973, 999971, 999972, 'SMOKE-CAPAY-1', 'pushed')"
    );
    $res = PaymentWebhookHandler::handle('SMOKE-CAPAY-1', 'Create', 'SMOKE-REALM-B', 'smoke-evt');
    ff_ga_check('C16', 'webhook echo of an FF credit application is not re-imported',
        ($res['result'] ?? '') === 'ff_origin_echo' ? [] : ['result=' . json_encode($res)]);

    // ══ F — Worker helpers ════════════════════════════════════════════
    $q1 = (int) db_insert('acc_qbo_sync_queue', ['entity_type' => 'invoice', 'entity_id' => 999972, 'operation' => 'create', 'status' => 'processing', 'picked_up_at' => ff_now_utc(), 'worker_id' => 'smoke']);
    $q2 = (int) db_insert('acc_qbo_sync_queue', ['entity_type' => 'invoice', 'entity_id' => 999972, 'operation' => 'create', 'status' => 'processing', 'picked_up_at' => ff_now_utc(), 'worker_id' => 'smoke']);
    $q3 = (int) db_insert('acc_qbo_sync_queue', ['entity_type' => 'invoice', 'entity_id' => 999972, 'operation' => 'create', 'status' => 'completed']);
    $released = qbo_worker_release([['id' => $q1], ['id' => $q2], ['id' => $q3]]);
    $e = [];
    if ($released !== 2) { $e[] = "released {$released}, expected 2"; }
    if ((string) db_row("SELECT status FROM acc_qbo_sync_queue WHERE id = ?", [$q1])['status'] !== 'queued') { $e[] = 'row not re-queued'; }
    if ((string) db_row("SELECT status FROM acc_qbo_sync_queue WHERE id = ?", [$q3])['status'] !== 'completed') { $e[] = 'completed row reopened'; }
    ff_ga_check('C17', 'qbo_worker_release hands processing rows back, never reopens finished ones', $e);

    $e = [];
    $row = ['id' => $q1, 'entity_type' => 'invoice', 'entity_id' => 999972, 'retry_count' => 0, 'max_retries' => 3];
    $rl  = new QuickBooksRateLimitException('429', 600, null, 429, null);
    $t   = new QuickBooksTransientException('Rate limit exhausted', null, 429, null, $rl);
    if (qbo_worker_defer_or_fail($row, $t, [], 'R', 'sandbox') !== 'deferred') { $e[] = 'budget left but not deferred'; }
    $qr = db_row("SELECT status, retry_count, TIMESTAMPDIFF(MINUTE, NOW(), next_retry_at) AS mins FROM acc_qbo_sync_queue WHERE id = ?", [$q1]);
    if ($qr['status'] !== 'queued' || (int) $qr['retry_count'] !== 1) { $e[] = 'deferred row state ' . json_encode($qr); }
    if ((int) $qr['mins'] < 9) { $e[] = "Retry-After 600s not honoured (next in {$qr['mins']}m)"; }
    $row['retry_count'] = 3;
    if (qbo_worker_defer_or_fail($row, new QuickBooksTransientException('503'), [], 'R', 'sandbox') !== 'failed') { $e[] = 'exhausted budget not failed'; }
    if ((string) db_row("SELECT status FROM acc_qbo_sync_queue WHERE id = ?", [$q1])['status'] !== 'failed') { $e[] = 'exhausted row not failed'; }
    ff_ga_check('C18', 'transient policy: defer with backoff (≥Retry-After) → fail when exhausted', $e);

    // ══ G — Client internals ══════════════════════════════════════════
    $client = new QuickBooksClient();
    $build  = new ReflectionMethod(QuickBooksClient::class, 'buildRequestId');
    $build->setAccessible(true);
    $e = [];
    QuickBooksClient::setWorkerContext(999975, 'invoice', 999972);
    $idA = $build->invoke($client, 'POST', 'invoice');
    $idA2 = $build->invoke($client, 'POST', 'invoice');          // 2nd write in the same row
    QuickBooksClient::setWorkerContext(999975, 'invoice', 999972); // re-dispatch of the same row
    $idB = $build->invoke($client, 'POST', 'invoice');
    if ($idA !== $idB)   { $e[] = 're-dispatch of same row changed the requestid'; }
    if ($idA === $idA2)  { $e[] = 'two writes in one row shared a requestid'; }
    if (strlen($idA) > 50) { $e[] = 'requestid longer than 50'; }
    db_insert('acc_qbo_sync_log', ['direction' => 'push', 'entity_type' => 'invoice', 'operation' => 'create', 'http_method' => 'POST',
        'endpoint' => 'invoice', 'response_status' => 400, 'queue_id' => 999975, 'realm_id' => 'SMOKE-REALM-B', 'environment' => 'sandbox']);
    QuickBooksClient::setWorkerContext(999975, 'invoice', 999972);
    $idC = $build->invoke($client, 'POST', 'invoice');
    if ($idC === $idA) { $e[] = 'retry after a definitive 4xx reused the requestid'; }
    QuickBooksClient::setWorkerContext(null, null, null);
    $r1 = $build->invoke($client, 'POST', 'invoice');
    $r2 = $build->invoke($client, 'POST', 'invoice');
    if ($r1 === $r2 || !str_starts_with($r1, 'ff-')) { $e[] = 'non-worker ids not random per call'; }
    ff_ga_check('C19', 'requestid: stable across re-dispatch, new after a 4xx, unique per write', $e);

    $e = [];
    $note = new ReflectionMethod(QuickBooksClient::class, 'noteFailure');
    $note->setAccessible(true);
    QuickBooksClient::setWorkerContext(999976, 'invoice', 1);
    $tx = new QuickBooksTransientException('boom');
    $note->invoke($client, $tx);
    if (QuickBooksClient::lastWorkerFailure() !== $tx) { $e[] = 'failure not remembered'; }
    QuickBooksClient::setWorkerContext(null, null, null);
    if (QuickBooksClient::lastWorkerFailure() !== null) { $e[] = 'not cleared between rows'; }
    ff_ga_check('C20', 'lastWorkerFailure records the swallowed exception per queue row', $e);

    $e = [];
    QuickBooksClient::settings_write_qbo('webhook_verifier_token', 'smoke-secret-123');
    $raw = (string) db_row("SELECT `value` FROM settings WHERE `key` = 'quickbooks.webhook_verifier_token'")['value'];
    if (!str_starts_with($raw, 'ENC:'))                                 { $e[] = 'stored in plaintext'; }
    if (QuickBooksClient::secret('webhook_verifier_token') !== 'smoke-secret-123') { $e[] = 'round-trip failed'; }
    ff_ga_set('quickbooks.webhook_verifier_token', 'legacy-plain');
    if (QuickBooksClient::secret('webhook_verifier_token') !== 'legacy-plain') { $e[] = 'legacy plaintext not passed through'; }
    ff_ga_check('C21', 'QBO secrets encrypted at rest; legacy plaintext still readable', $e);

    $mv = (new ReflectionClass(QuickBooksClient::class))->getConstant('QBO_MINORVERSION');
    ff_ga_check('C22', 'minorversion declared as 75 (Intuit floor since 2025-08-01)', $mv === '75' ? [] : ["got {$mv}"]);

    ff_ga_set('quickbooks.environment', 'sandbox');
    ff_ga_set('quickbooks.realm_id', \FleetForge\QboFixture::REALM_SENTINEL);
    ff_ga_set('quickbooks.fixture_mode', '1');
    $e = [];
    try {
        $link = (new QuickBooksClient())->generatePaymentsHostedUrl('4242', 'https://x/ok', 'https://x/cancel');
        if (!str_contains($link['url'] ?? '', 'scs-fixture-4242')) { $e[] = 'url=' . json_encode($link); }
        $log = db_row("SELECT http_method, endpoint, request_payload FROM acc_qbo_sync_log WHERE entity_type = 'payment_initiation' ORDER BY id DESC LIMIT 1");
        if (($log['http_method'] ?? '') !== 'GET' || !str_contains((string) ($log['request_payload'] ?? ''), 'invoiceLink')) { $e[] = 'not a GET …?include=invoiceLink: ' . json_encode($log); }
    } catch (\Throwable $ex) {
        $e[] = 'threw: ' . $ex->getMessage();
    }
    ff_ga_check('C23', 'pay-online link comes from GET invoice?include=invoiceLink (not /payments/charges)', $e);

    // ══ H — Enqueuer gates ════════════════════════════════════════════
    $e = [];
    foreach (['sent', 'partially_paid', 'paid', 'overdue'] as $s) {
        if (!in_array($s, InvoiceEnqueuer::POST_SEND_STATUSES, true)) { $e[] = "{$s} missing"; }
    }
    foreach (['draft', 'void', 'written_off'] as $s) {
        if (in_array($s, InvoiceEnqueuer::POST_SEND_STATUSES, true)) { $e[] = "{$s} present"; }
    }
    ff_ga_check('C24', 'POST_SEND_STATUSES = sent/partially_paid/paid/overdue', $e);

    ff_ga_set('quickbooks.sync_enabled', '1');
    ff_ga_set('quickbooks.sync_mode.invoice', 'sync');
    ff_ga_set('quickbooks.sync_mode.credit_memo', 'sync');
    // C17/C18 leave queue rows for 999972 (one re-queued create) — start
    // clean, or the enqueuer's pending-job dedupe (correctly) adds nothing.
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id=999972");
    $before = db_count("SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id=999972 AND status='queued' AND operation='create'");
    $ok = InvoiceEnqueuer::enqueue(999972, 'create');
    $after = db_count("SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id=999972 AND status='queued' AND operation='create'");
    ff_ga_check('C25', "an 'overdue' invoice can now be (re)queued for create", ($ok && $after === $before + 1) ? [] : ['enqueue=' . var_export($ok, true)]);

    $ok = CreditMemoEnqueuer::enqueue(999971, 'create');
    ff_ga_check('C26', "a 'partially_used' credit note can now be (re)queued for create", $ok ? [] : ['rejected']);

    // ══ K — Queue dedupe (runs while sync_enabled='1') ════════════════
    $e = [];
    $cnt = static fn(string $op, string $st): int => db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id=999972 AND operation=? AND status=?",
        [$op, $st]
    );
    InvoiceEnqueuer::enqueue(999972, 'create');                       // 2nd create while one is queued
    if ($cnt('create', 'queued') !== 1) { $e[] = 'duplicate queued create inserted'; }
    db_execute("UPDATE acc_qbo_sync_queue SET status='processing' WHERE entity_type='invoice' AND entity_id=999972 AND operation='create' AND status='queued'");
    InvoiceEnqueuer::enqueue(999972, 'create');                       // create while one is processing
    if ($cnt('create', 'queued') !== 0) { $e[] = 'create inserted while an identical one is processing'; }
    InvoiceEnqueuer::enqueue(999972, 'update');
    InvoiceEnqueuer::enqueue(999972, 'update');                       // 2nd update while one queued
    if ($cnt('update', 'queued') !== 1) { $e[] = 'updates not collapsed while queued'; }
    db_execute("UPDATE acc_qbo_sync_queue SET status='processing' WHERE entity_type='invoice' AND entity_id=999972 AND operation='update'");
    InvoiceEnqueuer::enqueue(999972, 'update');                       // edit during an in-flight update
    if ($cnt('update', 'queued') !== 1) { $e[] = 'update during an in-flight update was dropped'; }
    ff_ga_check('C29', 'enqueuers never duplicate a pending job (updates during in-flight still queue)', $e);
    ff_ga_set('quickbooks.sync_enabled', '0');

    $e = [];
    foreach (['failed_preflight', 'failed_preflight_currency_mismatch', 'failed_preflight_field_too_long', 'payload_build_failed'] as $st) {
        if (!qbo_worker_is_preflight_status($st)) { $e[] = "{$st} not treated as preflight"; }
    }
    foreach (['qbo_error', 'qbo_malformed_response', ''] as $st) {
        if (qbo_worker_is_preflight_status($st)) { $e[] = "{$st} wrongly treated as preflight"; }
    }
    ff_ga_check('C30', 'worker never requeues a preflight/payload failure as transient', $e);

    // ══ I — JE bridge filter ══════════════════════════════════════════
    $e = [];
    foreach ([JournalEntryPusher::class, JournalEntryEnqueuer::class] as $cls) {
        $c = (new ReflectionClass($cls))->getConstant('BRIDGE_DERIVED_SOURCE_TYPES');
        foreach (['damage_recovery', 'damage_repair'] as $st) {
            if (!in_array($st, (array) $c, true)) { $e[] = "{$cls} lacks {$st}"; }
        }
        if (in_array('damage_writeoff', (array) $c, true)) { $e[] = "{$cls} wrongly filters damage_writeoff"; }
    }
    ff_ga_check('C27', 'retagged damage JEs are treated as bridge-derived (no double-post)', $e);

    // ══ J — Drift live layer ══════════════════════════════════════════
    ff_ga_set('quickbooks.cutover_at', '2026-09-01T00:00:00+00:00');
    $stats = ['missing_in_ff' => 0];
    $e = [];
    try {
        DriftChecker::checkLive('invoice', DriftChecker::ENTITY_CHECKS['invoice'], $stats);
        $log = db_row("SELECT request_payload FROM acc_qbo_sync_log WHERE endpoint = 'query' ORDER BY id DESC LIMIT 1");
        $p = (string) ($log['request_payload'] ?? '');
        if (!str_contains($p, 'MetaData.CreateTime >=')) { $e[] = 'no cutover filter in query'; }
        if (!str_contains($p, 'STARTPOSITION 1'))        { $e[] = 'not paginated'; }
    } catch (\Throwable $ex) {
        $e[] = 'threw: ' . $ex->getMessage();
    }
    ff_ga_check('C28', 'drift live layer filters to post-cutover QBO records and paginates', $e);

    // ══ L — Go-live linker (cutover_at = 2026-09-01 from J) ═══════════
    $ffDoc = static fn(int $id, string $num, string $date, string $total): array => [
        'kind' => 'invoice', 'id' => $id, 'number' => $num, 'doc_date' => $date, 'total' => $total,
        'currency' => 'CAD', 'status' => 'sent', 'customer_id' => 1,
    ];
    $ffDocs = [
        $ffDoc(1, 'INV-A', '2026-06-01', '1500.00'),   // monthly, same amount as B
        $ffDoc(2, 'INV-B', '2026-07-01', '1500.00'),
        $ffDoc(3, 'Q-900', '2026-07-10', '80.00'),     // FF number typed into QBO
        $ffDoc(4, 'INV-D', '2026-08-01', '700.00'),    // two units, one rate, one day
        $ffDoc(5, 'INV-E', '2026-08-01', '700.00'),
        $ffDoc(6, 'Q-950', '2026-08-05', '99.00'),     // same number, amount differs
        $ffDoc(7, 'INV-G', '2026-08-20', '12.34'),     // QBO copy far outside the window
    ];
    $qboDocs = [
        ['Id' => '101', 'DocNumber' => '1001',  'TxnDate' => '2026-06-02', 'TotalAmt' => 1500],
        ['Id' => '102', 'DocNumber' => '1002',  'TxnDate' => '2026-07-03', 'TotalAmt' => 1500],
        ['Id' => '103', 'DocNumber' => 'q-900', 'TxnDate' => '2026-07-10', 'TotalAmt' => 80],
        ['Id' => '104', 'DocNumber' => '1004',  'TxnDate' => '2026-08-01', 'TotalAmt' => 700],
        ['Id' => '105', 'DocNumber' => '1005',  'TxnDate' => '2026-08-01', 'TotalAmt' => 700],
        ['Id' => '106', 'DocNumber' => 'Q-950', 'TxnDate' => '2026-08-06', 'TotalAmt' => 90],
        ['Id' => '107', 'DocNumber' => '1007',  'TxnDate' => '2026-09-30', 'TotalAmt' => 12.34],
    ];
    $m = InvoiceLinker::match($ffDocs, $qboDocs, 7);
    $want = [1 => ['101', 'amount_date'], 2 => ['102', 'amount_date'], 3 => ['103', 'exact'],
             4 => ['104', 'review'], 5 => ['105', 'review'], 6 => ['106', 'doc_number'], 7 => [null, 'none']];
    $e = [];
    foreach ($want as $id => [$qid, $conf]) {
        $got = [$m[$id]['proposal']['id'] ?? null, $m[$id]['confidence'] ?? '?'];
        if ($got !== [$qid, $conf]) { $e[] = "FF {$id}: want " . json_encode([$qid, $conf]) . ' got ' . json_encode($got); }
    }
    ff_ga_check('C31', 'matcher: exact / monthly amount+date / same-day review / number-only / none', $e);

    $user = ['id' => null, 'name' => 'smoke'];
    $qboInv = ['Id' => '88001', 'SyncToken' => '3', 'DocNumber' => 'Q-1001', 'TotalAmt' => 200.00, 'Balance' => 150.00,
               'CustomerRef' => ['value' => 'SMOKE-GL-1', 'name' => 'Smoke GoLive Customer'], 'TxnDate' => '2026-05-01'];
    $e = [];
    $r = InvoiceLinker::linkWithQboDoc('invoice', 999972, $qboInv, 'amount_date', $user);
    if (!$r['ok']) { $e[] = 'link failed: ' . ($r['error'] ?? '?'); }
    $mp = db_row("SELECT qbo_invoice_id, push_status, origin, link_method, pushed_at, qbo_sync_token FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999972");
    if (($mp['qbo_invoice_id'] ?? '') !== '88001' || ($mp['push_status'] ?? '') !== 'pushed' || ($mp['origin'] ?? '') !== 'cutover_link'
        || ($mp['link_method'] ?? '') !== 'amount_date' || $mp['pushed_at'] !== null || ($mp['qbo_sync_token'] ?? '') !== '3') {
        $e[] = 'map row ' . json_encode($mp);
    }
    if (db_count("SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id=999972 AND status='queued'") !== 0) {
        $e[] = 'queued push rows survived the link';
    }
    ff_ga_check('C32', 'link writes a pushed/cutover_link map row (no push) and clears queued pushes', $e);

    db_execute(
        "INSERT INTO invoices (id, invoice_number, customer_id, currency, status,
                              billing_period_start, billing_period_end, billing_period_days,
                              billing_type, invoice_date, due_date, sent_at,
                              total_amount, amount_paid, credits_applied, balance_due, created_at)
         VALUES (999974, 'INV-GL-999974', 999970, 'CAD', 'sent', '2026-08-01', '2026-08-31', 31, 'full_month',
                 '2026-08-01', '2026-08-31', '2026-08-01 17:00:00', 300.00, 0.00, 0.00, 300.00, NOW()),
                (999975, 'INV-GL-999975', 999970, 'CAD', 'sent', '2026-08-15', '2026-08-31', 17, 'partial_end',
                 '2026-08-15', '2026-09-15', NULL, 120.00, 0.00, 0.00, 120.00, NOW())"
    );
    $e = [];
    $codeOf = static fn(array $x): string => $x['ok'] ? 'ok' : (string) ($x['code'] ?? '?');
    if (($c = $codeOf(InvoiceLinker::linkWithQboDoc('invoice', 999972, ['Id' => '88009'] + $qboInv, 'manual', $user))) !== 'already_linked') { $e[] = "relink → {$c}"; }
    if (($c = $codeOf(InvoiceLinker::linkWithQboDoc('invoice', 999974, $qboInv, 'manual', $user))) !== 'qbo_taken') { $e[] = "taken → {$c}"; }
    $other = ['Id' => '88002', 'TotalAmt' => 300.00, 'CustomerRef' => ['value' => 'SOMEONE-ELSE']] + $qboInv;
    if (($c = $codeOf(InvoiceLinker::linkWithQboDoc('invoice', 999974, $other, 'manual', $user))) !== 'customer_mismatch') { $e[] = "customer → {$c}"; }
    $diff = ['Id' => '88002', 'TotalAmt' => 310.00] + $qboInv;
    if (($c = $codeOf(InvoiceLinker::linkWithQboDoc('invoice', 999974, $diff, 'manual', $user))) !== 'amount_differs') { $e[] = "amount → {$c}"; }
    if (($c = $codeOf(InvoiceLinker::linkWithQboDoc('invoice', 999974, $diff, 'manual', $user, true))) !== 'ok') { $e[] = "accepted amount → {$c}"; }
    if (($c = $codeOf(InvoiceLinker::unlink('invoice', 999974, $user))) !== 'ok') { $e[] = "unlink → {$c}"; }
    if (db_row("SELECT 1 AS x FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999974")) { $e[] = 'unlink left the map row'; }
    ff_ga_check('C33', 'link refuses relink / taken id / other customer / unconfirmed amount; unlink undoes', $e);

    ff_ga_set('quickbooks.sync_mode.invoice', 'sync');
    ff_ga_set('quickbooks.sync_mode.credit_memo', 'sync');
    $e = [];
    $r = InvoicePusher::pushUpdate(999972);
    if (($r['status'] ?? '') !== 'skipped_cutover_link' || ($r['outcome'] ?? '') !== 'skipped') { $e[] = 'update → ' . json_encode($r); }
    if (!db_row("SELECT 1 AS x FROM acc_qbo_sync_log WHERE entity_type='invoice' AND entity_id=999972 AND error_code='skipped_cutover_link' AND http_method='SKIP'")) { $e[] = 'no SKIP sync_log row'; }
    if (!db_row("SELECT 1 AS x FROM acc_qbo_drift_events WHERE entity_type='invoice' AND entity_id=999972 AND resolved_at IS NULL AND description LIKE '%by hand%'")) { $e[] = 'no drift event'; }
    InvoicePusher::pushUpdate(999972);
    // field_mismatch only: C18's exhausted-retry test leaves a push_failed
    // drift event on the same invoice.
    if (db_count("SELECT COUNT(*) FROM acc_qbo_drift_events WHERE entity_type='invoice' AND entity_id=999972 AND resolved_at IS NULL AND category='field_mismatch'") !== 1) { $e[] = 'repeat edit opened a second drift event'; }
    db_execute("UPDATE invoices SET status = 'void' WHERE id = 999972");
    $r = InvoicePusher::pushVoid(999972);
    if (($r['status'] ?? '') !== 'skipped_cutover_link') { $e[] = 'void → ' . json_encode($r); }
    db_execute("UPDATE invoices SET status = 'overdue' WHERE id = 999972");
    $cnLink = InvoiceLinker::linkWithQboDoc('credit_memo', 999971,
        ['Id' => '77001', 'SyncToken' => '0', 'TotalAmt' => 100.00, 'CustomerRef' => ['value' => 'SMOKE-GL-1']], 'manual', $user);
    if (!$cnLink['ok']) { $e[] = 'CN link: ' . ($cnLink['error'] ?? '?'); }
    $r = CreditMemoPusher::pushUpdate(999971);
    if (($r['status'] ?? '') !== 'skipped_cutover_link') { $e[] = 'CN update → ' . json_encode($r); }
    ff_ga_check('C34', 'linked invoice / credit memo: FF edit + void never reach QuickBooks (SKIP + one drift event)', $e);

    $e = [];
    $inv75 = db_row("SELECT * FROM invoices WHERE id = 999975");
    if (InvoiceLinker::cutoverBlockReason('invoice', $inv75) === null) { $e[] = 'pre-go-live invoice not blocked'; }
    $rel = InvoiceLinker::pushAsNew('invoice', 999975, $user);
    if (!$rel['ok']) { $e[] = 'push_new: ' . ($rel['error'] ?? '?'); }
    if (InvoiceLinker::cutoverBlockReason('invoice', $inv75) !== null) { $e[] = 'released invoice still blocked'; }
    $post = ['id' => 0, 'invoice_number' => 'X', 'invoice_date' => '2026-09-15', 'sent_at' => null];
    if (InvoiceLinker::cutoverBlockReason('invoice', $post) !== null) { $e[] = 'post-go-live invoice blocked'; }
    if (InvoiceLinker::cutoverBlockReason('invoice', ['sent_at' => '2026-08-31 23:00:00'] + $post) === null) { $e[] = 'sent-before-go-live invoice not blocked'; }
    $gateSrc = (string) file_get_contents(FF_ROOT . '/lib/QboPushers/InvoicePreflightGate.php')
             . (string) file_get_contents(FF_ROOT . '/lib/QboPushers/CreditMemoPusher.php');
    if (substr_count($gateSrc, 'InvoiceLinker::cutoverBlockReason(') !== 2) { $e[] = 'guard not wired into both preflights'; }
    ff_ga_check('C35', 'pre-go-live documents are held back from a NEW push until linked or released', $e);

    $e = [];
    $qboPay = ['Id' => '66001', 'SyncToken' => '0', 'TotalAmt' => 150.00, 'TxnDate' => '2026-06-15',
               'CurrencyRef' => ['value' => 'CAD'], 'PaymentMethodRef' => ['name' => 'Visa'],
               'Line' => [['Amount' => 150.00, 'LinkedTxn' => [['TxnId' => '88001', 'TxnType' => 'Invoice']]]]];
    $plan = PaymentWebhookHandler::planFromQbo($qboPay);
    if (($plan['targets'][0]['ff_invoice_id'] ?? 0) !== 999972 || $plan['ff_amount'] !== '150.00') { $e[] = 'plan ' . json_encode($plan); }
    $res = db_transaction(fn() => PaymentWebhookHandler::recordFromQbo($qboPay, $plan['targets'], $plan['ff_amount'], '66001', 'cutover-import', 'SMOKE', 'qbo_other'));
    if (($res['result'] ?? '') !== 'payment_created') { $e[] = 'record → ' . json_encode($res); }
    $pay = db_row("SELECT p.origin, p.payment_method, m.origin AS map_origin, m.push_status FROM payments p JOIN acc_qbo_payment_map m ON m.ff_payment_id = p.id WHERE m.qbo_payment_id = '66001'");
    if (($pay['origin'] ?? '') !== 'qbo_other' || ($pay['map_origin'] ?? '') !== 'qbo_other' || ($pay['push_status'] ?? '') !== 'pulled_from_qbo' || ($pay['payment_method'] ?? '') !== 'credit_card') {
        $e[] = 'payment ' . json_encode($pay);
    }
    $inv = db_row("SELECT status, balance_due, paid_date FROM invoices WHERE id = 999972");
    if (($inv['status'] ?? '') !== 'paid' || bccomp((string) $inv['balance_due'], '0', 2) !== 0 || ($inv['paid_date'] ?? '') !== '2026-06-15') {
        $e[] = 'invoice ' . json_encode($inv);
    }
    ff_ga_check('C36', "go-live payment import: origin qbo_other, invoice paid on QuickBooks' date", $e);

    $r = InvoiceLinker::unlink('invoice', 999972, $user);
    ff_ga_check('C37', 'unlink refused while QuickBooks payments are mirrored onto the invoice',
        (!$r['ok'] && ($r['code'] ?? '') === 'has_mirrored_payments') ? [] : ['got ' . json_encode($r)]);

    $e = [];
    $whSrc = (string) file_get_contents(FF_ROOT . '/lib/QboPushers/PaymentWebhookHandler.php');
    if (!str_contains($whSrc, "if (\$ffPay['origin'] === 'ff_native') {")) { $e[] = 'handleUpdate does not treat qbo_other as QuickBooks-owned'; }
    if (!str_contains($whSrc, '$origin = (string) $ffPay[\'origin\'];')) { $e[] = 're-sync does not keep provenance'; }
    ff_ga_check('C38', 'imported (qbo_other) payments re-sync like webhook ones and keep their origin', $e);

    $e = [];
    $rs = (array) (new ReflectionClass(RealmGuard::class))->getConstant('REALM_SCOPED_SETTINGS');
    foreach (['class_id', 'location_id', 'cutover_at', 'pref.custom_txn_numbers'] as $k) {
        if (!in_array($k, $rs, true)) { $e[] = "{$k} survives a company change"; }
    }
    ff_ga_check('C39', 'realm reset clears Class/Location ids, prefs and the go-live stamp', $e);

    // ══ M — Drift ownership in a shared company file ══════════════════
    $e = [];
    $parties = ['customer' => ['501' => true], 'vendor' => ['601' => true]];
    ff_ga_set('quickbooks.class_id', '');
    ff_ga_set('quickbooks.location_id', '');
    if (!DriftChecker::belongsToFleetForge('invoice', ['CustomerRef' => ['value' => '501']], $parties)) { $e[] = 'FF customer invoice not ours'; }
    if (DriftChecker::belongsToFleetForge('invoice', ['CustomerRef' => ['value' => '999']], $parties))  { $e[] = "other business's invoice counted"; }
    if (DriftChecker::belongsToFleetForge('customer', ['Id' => '777'], $parties))                     { $e[] = 'new QBO customer counted'; }
    if (!DriftChecker::belongsToFleetForge('bill', ['VendorRef' => ['value' => '601']], $parties))     { $e[] = 'untagged file: linked-vendor bill not ours'; }
    ff_ga_set('quickbooks.class_id', '42');
    if (DriftChecker::belongsToFleetForge('bill', ['VendorRef' => ['value' => '601']], $parties))      { $e[] = 'tagged file: shared-vendor bill without our class counted'; }
    $taggedBill = ['VendorRef' => ['value' => '999'], 'Line' => [['AccountBasedExpenseLineDetail' => ['ClassRef' => ['value' => '42']]]]];
    if (!DriftChecker::belongsToFleetForge('bill', $taggedBill, $parties))                             { $e[] = 'class-tagged bill not ours'; }
    $je = ['Line' => [['JournalEntryLineDetail' => ['ClassRef' => ['value' => '42']]]]];
    if (!DriftChecker::belongsToFleetForge('journal_entry', $je, $parties))                            { $e[] = 'class-tagged JE not ours'; }
    if (DriftChecker::belongsToFleetForge('journal_entry', ['Line' => []], $parties))                 { $e[] = 'untagged JE counted'; }
    ff_ga_set('quickbooks.class_id', '');
    ff_ga_check('C40', "drift ignores other businesses' records in a shared company file", $e);

    // ══ N — Canadian per-rate invoice tax (F80) ═══════════════════════
    $e = [];
    $refs = json_encode([
        ['TaxRateRef' => ['value' => '11', 'name' => 'GST (sales)']],
        ['TaxRateRef' => ['value' => '12', 'name' => 'PST (BC) Sales']],
    ]);
    $d = InvoiceTaxPerRate::taxDetail(['gst' => '5.00', 'pst' => '7.00', 'hst' => '0.00'], '12.00', $refs, ['subtotal_after_discount' => '100.00']);
    if (($d['TaxLine'][0]['TaxLineDetail']['TaxRateRef']['value'] ?? '') !== '11' || ($d['TaxLine'][0]['Amount'] ?? 0) != 5.0
        || ($d['TaxLine'][1]['TaxLineDetail']['TaxRateRef']['value'] ?? '') !== '12' || ($d['TaxLine'][1]['Amount'] ?? 0) != 7.0
        || ($d['TotalTax'] ?? 0) != 12.0) {
        $e[] = 'GST+PST detail ' . json_encode($d);
    }
    $twoGst = json_encode([['TaxRateRef' => ['value' => '11', 'name' => 'GST (sales)']], ['TaxRateRef' => ['value' => '13', 'name' => 'GST old']]]);
    if (InvoiceTaxPerRate::taxDetail(['gst' => '5.00', 'pst' => '0.00', 'hst' => '0.00'], '5.00', $twoGst, []) !== null) { $e[] = 'ambiguous rates not left to QBO'; }
    $hst = InvoiceTaxPerRate::taxDetail(['gst' => '0.00', 'pst' => '0.00', 'hst' => '13.00'], '13.00',
        json_encode([['TaxRateRef' => ['value' => '21', 'name' => 'HST ON']]]), []);
    if (($hst['TaxLine'][0]['TaxLineDetail']['TaxRateRef']['value'] ?? '') !== '21') { $e[] = 'HST detail ' . json_encode($hst); }

    db_execute("INSERT INTO tax_rates (id, name, province, country, gst_rate, pst_rate, hst_rate, effective_from)
                VALUES (999980, 'Smoke ZZ GST+PST', 'ZZ', 'CA', 0.05, 0.07, 0, '2020-01-01')");
    db_execute("INSERT INTO acc_qbo_tax_code_map (ff_tax_rate_id, qbo_tax_code_id, qbo_name, mapping_status, qbo_sales_rate_refs)
                VALUES (999980, 'SMOKE-TC-1', 'GST/PST ZZ', 'mapped', ?)", [$refs]);
    ff_ga_set('quickbooks.invoice.tax_mode', 'per_rate');
    ff_ga_set('quickbooks.invoice.tax_code_exempt', '');
    $inv = ['province_snapshot' => 'ZZ', 'tax_gst_rate' => '0.0500', 'tax_pst_rate' => '0.0700', 'tax_hst_rate' => '0.0000',
            'tax_gst_amount' => '5.00', 'tax_pst_amount' => '7.00', 'tax_hst_amount' => '0.00', 'subtotal_after_discount' => '100.00'];
    if (InvoiceTaxPerRate::resolve($inv)['ok']) { $e[] = 'resolved without a tax-free code'; }
    ff_ga_set('quickbooks.invoice.tax_code_exempt', 'SMOKE-TC-EX');
    $res = InvoiceTaxPerRate::resolve($inv);
    if (!$res['ok'] || $res['taxable_code'] !== 'SMOKE-TC-1' || ($res['txn_tax_detail']['TaxLine'][1]['TaxLineDetail']['TaxRateRef']['value'] ?? '') !== '12') {
        $e[] = 'resolve ' . json_encode($res);
    }
    $pstOnly = ['tax_gst_amount' => '0.00'] + $inv;
    if (InvoiceTaxPerRate::resolve($pstOnly)['ok']) { $e[] = 'PST-only sale matched the GST+PST code'; }
    // PST-exempt customer in ZZ = a GST-only 5% sale → falls back to any
    // MAPPED GST-only rate (another province's), i.e. QuickBooks' "GST" code.
    db_execute("INSERT INTO tax_rates (id, name, province, country, gst_rate, pst_rate, hst_rate, effective_from)
                VALUES (999981, 'Smoke YY GST', 'YY', 'CA', 0.05, 0, 0, '2020-01-01')");
    db_execute("INSERT INTO acc_qbo_tax_code_map (ff_tax_rate_id, qbo_tax_code_id, qbo_name, mapping_status, qbo_sales_rate_refs)
                VALUES (999981, 'SMOKE-TC-GST', 'GST', 'mapped', ?)", [json_encode([['TaxRateRef' => ['value' => '11', 'name' => 'GST']]])]);
    $gstOnly = InvoiceTaxPerRate::resolve(['tax_pst_amount' => '0.00'] + $inv);
    if (!$gstOnly['ok'] || $gstOnly['taxable_code'] !== 'SMOKE-TC-GST') { $e[] = 'PST-exempt sale did not fall back to the GST code: ' . json_encode($gstOnly); }
    $lines = InvoiceLineBuilder::build(['id' => 1], ['id' => 1], [
        ['item_type' => 'base_rental', 'quantity' => '1', 'unit_price' => '100.00', 'amount' => '100.00', 'taxable' => 1, 'description' => 'rent'],
        ['item_type' => 'base_rental', 'quantity' => '1', 'unit_price' => '25.00', 'amount' => '25.00', 'taxable' => 0, 'description' => 'tax-free fee'],
    ], $res);
    if (($lines[0]['SalesItemLineDetail']['TaxCodeRef']['value'] ?? '') !== 'SMOKE-TC-1')  { $e[] = 'taxable line code'; }
    if (($lines[1]['SalesItemLineDetail']['TaxCodeRef']['value'] ?? '') !== 'SMOKE-TC-EX') { $e[] = 'non-taxable line code'; }
    ff_ga_set('quickbooks.invoice.tax_mode', 'override');
    ff_ga_check('C41', 'per-rate invoice tax: mapped code per line, exact GST/PST TaxLines, exempt lines, held back when unmapped', $e);

    // ══ O — Rounding residual (per-line vs per-invoice tax rounding) ══
    $e = [];
    db_execute("UPDATE invoices SET status = 'partially_paid', amount_paid = 299.99, balance_due = 0.01 WHERE id = 999974");
    $r = RoundingSettler::settleIfQboPaid(999974, ['Balance' => 5.00, 'TotalAmt' => 299.99]);
    if ($r['settled']) { $e[] = 'settled while QuickBooks still shows a balance'; }
    $r = RoundingSettler::settleIfQboPaid(999974, ['Balance' => 0, 'TotalAmt' => 299.99]);
    if (!$r['settled'] || $r['amount'] !== '0.01') { $e[] = 'not settled: ' . json_encode($r); }
    $inv = db_row("SELECT status, balance_due, credits_applied FROM invoices WHERE id = 999974");
    if ($inv['status'] !== 'paid' || bccomp((string) $inv['balance_due'], '0', 2) !== 0 || bccomp((string) $inv['credits_applied'], '0.01', 2) !== 0) { $e[] = 'invoice ' . json_encode($inv); }
    $cn = db_row("SELECT id, source, status, internal_notes FROM credit_notes WHERE source_invoice_id = 999974 ORDER BY id DESC LIMIT 1");
    if (!$cn || !RoundingSettler::isRoundingNote($cn['internal_notes']) || $cn['status'] !== 'fully_used') { $e[] = 'credit note ' . json_encode($cn); }
    ff_ga_set('quickbooks.sync_enabled', '1');
    if ($cn && CreditMemoEnqueuer::enqueue((int) $cn['id'], 'create')) { $e[] = 'rounding credit note was queued for QuickBooks'; }
    ff_ga_set('quickbooks.sync_enabled', '0');
    db_execute("UPDATE invoices SET status = 'partially_paid', amount_paid = 299.90, credits_applied = 0, balance_due = 0.10 WHERE id = 999974");
    if (RoundingSettler::settleIfQboPaid(999974, ['Balance' => 0, 'TotalAmt' => 299.90])['settled']) { $e[] = 'settled a 10-cent balance (over the 5-cent tolerance)'; }
    ff_ga_check('C42', 'rounding residual ≤5¢ closed by an FF-only adjustment credit note once QuickBooks shows the invoice paid', $e);
} catch (\Throwable $fatal) {
    echo "FATAL " . get_class($fatal) . ': ' . $fatal->getMessage() . ' @ ' . $fatal->getFile() . ':' . $fatal->getLine() . "\n";
    $failures[] = 'FATAL';
} finally {
    QuickBooksClient::setWorkerContext(null, null, null);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    settings_cache_flush();
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_golive_audit_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
