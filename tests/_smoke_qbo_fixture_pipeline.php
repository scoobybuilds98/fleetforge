<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_fixture_pipeline.php
 *
 * S-QBO-OFFLINE-TESTBED — sanctioned boundary-crosser smoke. The QBO
 * smoke suite is otherwise built around the HTTP-trap rule (no test
 * crosses the cURL boundary in QuickBooksClient::executeRequest), but
 * THIS smoke deliberately exercises that boundary in fixture mode: the
 * whole point of the offline testbed is that the dispatch() chokepoint
 * + sync_log writes + classify-throw paths run end-to-end without real
 * Intuit traffic.
 *
 * Per-function sub-checks (operator-escalated extensive-coverage
 * discipline — every public seam gets a named sub-check):
 *
 *  F1: fixtureMode() default-off + refusal=null on a clean install.
 *  F2: fixtureMode() ON when settings + sandbox + permissive realm.
 *  F3: fixtureMode() HARD REFUSED when environment='production'.
 *  F4: fixtureMode() HARD REFUSED when realm_id looks like a real Intuit realm.
 *  F5: QboFixture::respond create → echoes payload + synthetic Id + SyncToken='0'.
 *  F6: QboFixture::respond update → bumps SyncToken (the `+` vs array_merge bug
 *       that surfaced in dispatch verification stays fixed).
 *  F7: QboFixture::respond void   → returns {Id, SyncToken, status:'Voided'}.
 *  F8: QboFixture::respond query  → returns canned collection per entity,
 *       honoring K-22 Trap #60 (bare object for 1-row; coerced to array
 *       by QuickBooksClient::normalizeQueryResponse).
 *  F9: QboFixture::respond get    → returns {Pascal: {Id, …, CurrencyRef}}.
 * F10: QboFixture::injectError → next matching call throws the configured
 *       QuickBooksException family; subsequent calls hit happy path.
 * F11: END-TO-END — seed FF customer, enqueue, run QboPusherDispatcher::dispatch
 *       in fixture mode, assert acc_qbo_customer_map.push_status='pushed'
 *       + acc_qbo_sync_log row with realm_id='FIXTURE-DEMO' + response_status=200.
 *       (FIRST smoke to cross the dispatch() boundary.)
 * F12: QboDemoSeed::load populates ≥1 row in every demo surface;
 *       returns summary with non-zero pushed/failed/skipped/pulled/drift_events.
 * F13: QboDemoSeed::wipe removes ONLY FIXTURE-DEMO-tagged rows; real-row
 *       counts before-load equal counts after-wipe (snapshot at start of
 *       smoke, compared at end).
 *
 * Self-cleaning: all DB writes use the QboDemoSeed range + FIXTURE-DEMO
 * realm tag, scrubbed in finally{}. Settings mutations bracketed by
 * save+restore so quickbooks.fixture_mode lands back at its original
 * value (must remain '0' after the smoke per D-CPA-5).
 *
 * @session S-QBO-OFFLINE-TESTBED
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §13 (error handling), §14 (rate limits)
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QuickBooksClient;
use FleetForge\QboFixture;
use FleetForge\QboDemoSeed;
use FleetForge\QboPusherDispatcher;
use FleetForge\QboPushers\CustomerEnqueuer;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Exceptions\QuickBooksValidationException;

$failures = [];
$pass     = 0;
$total    = 13;

/** Settings we mutated and must restore. */
$settingsToRestore = [];
function ff_fp_save(string $key): void {
    global $settingsToRestore;
    if (!array_key_exists($key, $settingsToRestore)) {
        $settingsToRestore[$key] = settings_get($key, null);
    }
}
function ff_fp_set(string $key, string $val): void {
    ff_fp_save($key);
    db_execute(
        "INSERT INTO settings (`key`, `value`, value_type, group_name)
              VALUES (?, ?, 'string', 'quickbooks')
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $val]
    );
}

// Snapshot real-row counts BEFORE we touch anything — F13 compares.
$rowCountsAtStart = QboDemoSeed::countRealRows();

try {

// ── F1: fixtureMode default-off + null refusal ─────────────────────
ff_fp_set('quickbooks.fixture_mode', '0');
ff_fp_set('quickbooks.environment', 'sandbox');
ff_fp_set('quickbooks.realm_id', '');
if (QuickBooksClient::fixtureMode() === false && QuickBooksClient::fixtureRefusalReason() === null) {
    echo "PASS F1  fixtureMode default-off + refusalReason=null (sandbox + empty realm)\n"; $pass++;
} else {
    echo "FAIL F1  expected fixtureMode=false + refusal=null; got mode=" . var_export(QuickBooksClient::fixtureMode(), true)
         . " refusal=" . var_export(QuickBooksClient::fixtureRefusalReason(), true) . "\n";
    $failures[] = 'F1';
}

// ── F2: fixtureMode ON under permissive settings ──────────────────
ff_fp_set('quickbooks.fixture_mode', '1');
ff_fp_set('quickbooks.realm_id', QboFixture::REALM_SENTINEL);
if (QuickBooksClient::fixtureMode() === true && QuickBooksClient::fixtureRefusalReason() === null) {
    echo "PASS F2  fixtureMode ON when sandbox + realm=FIXTURE-DEMO + fixture_mode='1'\n"; $pass++;
} else {
    echo "FAIL F2  expected fixtureMode=true; got mode=" . var_export(QuickBooksClient::fixtureMode(), true)
         . " refusal=" . var_export(QuickBooksClient::fixtureRefusalReason(), true) . "\n";
    $failures[] = 'F2';
}

// ── F3: HARD refuse in production ──────────────────────────────────
ff_fp_set('quickbooks.environment', 'production');
$mode = QuickBooksClient::fixtureMode();
$reason = QuickBooksClient::fixtureRefusalReason();
if ($mode === false && is_string($reason) && stripos($reason, 'production') !== false) {
    echo "PASS F3  fixtureMode HARD refused in production (reason: '" . substr($reason, 0, 60) . "...')\n"; $pass++;
} else {
    echo "FAIL F3  expected hard-refuse with 'production' in reason; got mode=" . var_export($mode, true)
         . " refusal=" . var_export($reason, true) . "\n";
    $failures[] = 'F3';
}

// ── F4: HARD refuse when real-realm connected ──────────────────────
ff_fp_set('quickbooks.environment', 'sandbox');
ff_fp_set('quickbooks.realm_id', '9341457119548719'); // looks like a real Intuit realm
$mode = QuickBooksClient::fixtureMode();
$reason = QuickBooksClient::fixtureRefusalReason();
if ($mode === false && is_string($reason) && stripos($reason, 'real realm') !== false) {
    echo "PASS F4  fixtureMode HARD refused with real realm connected (reason mentions 'real realm')\n"; $pass++;
} else {
    echo "FAIL F4  expected hard-refuse with 'real realm' in reason; got mode=" . var_export($mode, true)
         . " refusal=" . var_export($reason, true) . "\n";
    $failures[] = 'F4';
}

// Set permissive settings for the remaining tests
ff_fp_set('quickbooks.realm_id', QboFixture::REALM_SENTINEL);
ff_fp_set('quickbooks.fixture_mode', '1');

// ── F5: respond create → echoes + synthetic Id + SyncToken='0' ─────
QboFixture::reset();
$r = QboFixture::respond('POST', 'customer', ['entity_type' => 'customer', 'operation' => 'create', 'json' => ['DisplayName' => 'F5 Test', 'CurrencyRef' => ['value' => 'CAD']]]);
$body = json_decode($r['body'], true);
if ($r['status'] === 200
    && isset($body['Customer']['Id'])
    && str_starts_with($body['Customer']['Id'], QboFixture::ID_PREFIX . 'customer-')
    && ($body['Customer']['SyncToken'] ?? null) === '0'
    && ($body['Customer']['DisplayName'] ?? null) === 'F5 Test'
    && ($body['Customer']['CurrencyRef']['value'] ?? null) === 'CAD') {
    echo "PASS F5  QboFixture::respond create echoes payload + synthetic Id ('{$body['Customer']['Id']}') + SyncToken='0'\n"; $pass++;
} else {
    echo "FAIL F5  unexpected create body: " . json_encode($body) . "\n"; $failures[] = 'F5';
}

// ── F6: respond update bumps SyncToken ─────────────────────────────
$r = QboFixture::respond('POST', 'customer?operation=update', ['entity_type' => 'customer', 'operation' => 'update', 'json' => ['Id' => 'QBO-FIX-customer-99', 'SyncToken' => '4', 'DisplayName' => 'F6 Updated']]);
$body = json_decode($r['body'], true);
if ($r['status'] === 200
    && ($body['Customer']['Id'] ?? null) === 'QBO-FIX-customer-99'
    && ($body['Customer']['SyncToken'] ?? null) === '5'
    && ($body['Customer']['DisplayName'] ?? null) === 'F6 Updated') {
    echo "PASS F6  QboFixture::respond update bumps SyncToken (4→5) and echoes DisplayName\n"; $pass++;
} else {
    echo "FAIL F6  expected SyncToken=5 (bumped from 4); got " . json_encode($body) . "\n"; $failures[] = 'F6';
}

// ── F7: respond void returns Voided shape ──────────────────────────
$r = QboFixture::respond('POST', 'invoice?operation=void', ['entity_type' => 'invoice', 'operation' => 'void', 'json' => ['Id' => 'QBO-FIX-invoice-77', 'SyncToken' => '0']]);
$body = json_decode($r['body'], true);
if ($r['status'] === 200
    && ($body['Invoice']['Id'] ?? null) === 'QBO-FIX-invoice-77'
    && ($body['Invoice']['status'] ?? null) === 'Voided'
    && ($body['Invoice']['Voided'] ?? null) === true) {
    echo "PASS F7  QboFixture::respond void returns {Id, SyncToken, status:'Voided', Voided:true}\n"; $pass++;
} else {
    echo "FAIL F7  expected Voided shape; got " . json_encode($body) . "\n"; $failures[] = 'F7';
}

// ── F8: respond query + K-22 Trap #60 single-object coercion ───────
// Customer canned collection has 2 rows → returns array (no coercion needed).
$r = QboFixture::respond('GET', 'query', ['entity_type' => 'query', 'operation' => 'query', 'query' => ['query' => 'SELECT * FROM Customer MAXRESULTS 10']]);
$body = json_decode($r['body'], true);
$list = $body['QueryResponse']['Customer'] ?? [];
$multiRowOk = is_array($list) && count($list) === 2 && array_is_list($list);

// Invoice canned collection has 1 row → fixture returns bare object (no array
// wrap); QuickBooksClient::normalizeQueryResponse must coerce to array.
$rawSingle = QboFixture::respond('GET', 'query', ['entity_type' => 'query', 'operation' => 'query', 'query' => ['query' => 'SELECT * FROM Invoice MAXRESULTS 10']]);
$rawBody   = json_decode($rawSingle['body'], true);
$bareIsAssoc = isset($rawBody['QueryResponse']['Invoice']['Id']); // bare object before normalization

$normalized = QuickBooksClient::normalizeQueryResponse($rawBody);
$coercedList = $normalized['QueryResponse']['Invoice'] ?? [];
$coerceOk = is_array($coercedList) && array_is_list($coercedList) && count($coercedList) === 1;

if ($multiRowOk && $bareIsAssoc && $coerceOk) {
    echo "PASS F8  QboFixture::respond query → 2-row Customer array + 1-row Invoice bare-object coerced by normalizeQueryResponse (K-22 #60)\n"; $pass++;
} else {
    echo "FAIL F8  multi=" . var_export($multiRowOk, true) . " bareAssoc=" . var_export($bareIsAssoc, true) . " coerced=" . var_export($coerceOk, true) . "\n";
    $failures[] = 'F8';
}

// ── F9: respond get returns {Pascal:{Id,…}} ────────────────────────
$r = QboFixture::respond('GET', 'account/123', ['entity_type' => 'account', 'operation' => 'get']);
$body = json_decode($r['body'], true);
if ($r['status'] === 200
    && ($body['Account']['Id'] ?? null) === '123'
    && !empty($body['Account']['Name'])) {
    echo "PASS F9  QboFixture::respond get returns {Account:{Id:'123', Name:'…'}}\n"; $pass++;
} else {
    echo "FAIL F9  expected get shape; got " . json_encode($body) . "\n"; $failures[] = 'F9';
}

// ── F10: injectError single-shot → throws on next match ────────────
QboFixture::reset();
QboFixture::injectError('customer:create', 400, ['Fault' => ['type' => 'ValidationFault', 'Error' => [['code' => '610', 'Message' => 'F10 injected', 'Detail' => '']]]]);
$client = new QuickBooksClient();
$threw = null;
try {
    $client->createEntity('customer', ['DisplayName' => 'F10 Fail']);
} catch (QuickBooksException $e) {
    $threw = $e;
}
// Subsequent call must succeed (single-shot)
$happyResp = null;
try {
    $happyResp = $client->createEntity('customer', ['DisplayName' => 'F10 Happy', 'CurrencyRef' => ['value' => 'CAD']]);
} catch (\Throwable $e) {
    $happyResp = null;
}
if ($threw instanceof QuickBooksValidationException
    && stripos($threw->getMessage(), 'F10 injected') !== false
    && is_array($happyResp)
    && !empty($happyResp['Customer']['Id'])) {
    echo "PASS F10 injectError fires once (caught QuickBooksValidationException), next call succeeds\n"; $pass++;
} else {
    echo "FAIL F10 threw=" . (is_object($threw) ? get_class($threw) : 'NULL') . " happy=" . var_export(is_array($happyResp), true) . "\n"; $failures[] = 'F10';
}

// ── F11: END-TO-END dispatch through fixture ───────────────────────
// First smoke to cross the dispatch() boundary. Seed FF customer →
// enqueue → run QboPusherDispatcher::dispatch in fixture mode → assert
// map row + sync_log written by REAL Pusher code.
$ffId = QboDemoSeed::FF_ID_MIN + 50; // inside seed range so finally{} cleans up
db_execute("DELETE FROM customers WHERE id = ?", [$ffId]);
db_execute("DELETE FROM acc_qbo_customer_map WHERE ff_customer_id = ?", [$ffId]);
db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='customer' AND entity_id = ?", [$ffId]);
db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='customer' AND entity_id = ?", [$ffId]);

db_execute(
    "INSERT INTO customers (id, company_name, currency) VALUES (?, ?, 'CAD')",
    [$ffId, 'FIXTURE-DEMO F11 Boundary Customer']
);

ff_fp_set('quickbooks.sync_enabled',           '1');
ff_fp_set('quickbooks.sync_mode.customer',     'sync');
ff_fp_set('quickbooks.multi_currency_enabled', '1');
ff_fp_set('quickbooks.connection_status',      'connected');

CustomerEnqueuer::enqueue($ffId, 'create');
$queueRow = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='customer' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$ffId]);
$queueId  = $queueRow ? (int) $queueRow['id'] : null;

// Mirror the worker's setWorkerContext — without it, writeSyncLog has no
// entity_id source and the sync_log row would have entity_id=NULL
// (which would break the entity_id-keyed lookup below). This matches
// cron/qbo_sync_worker.php's setWorkerContext call before dispatch().
QuickBooksClient::setWorkerContext($queueId, 'customer', $ffId);
try {
    $result = QboPusherDispatcher::dispatch('customer', 'create', $ffId);
} finally {
    QuickBooksClient::setWorkerContext(null, null, null);
}
$mapRow = db_row("SELECT push_status, qbo_customer_id, qbo_sync_token FROM acc_qbo_customer_map WHERE ff_customer_id = ?", [$ffId]);
$logRow = db_row(
    "SELECT realm_id, response_status, http_method, endpoint FROM acc_qbo_sync_log
      WHERE entity_type='customer' AND entity_id = ? AND realm_id = ?
      ORDER BY id DESC LIMIT 1",
    [$ffId, QboFixture::REALM_SENTINEL]
);

$f11ok = ($result['outcome'] ?? null) === 'created'
    && $mapRow !== null
    && $mapRow['push_status'] === 'pushed'
    && str_starts_with((string) $mapRow['qbo_customer_id'], QboFixture::ID_PREFIX . 'customer-')
    && $logRow !== null
    && (int) $logRow['response_status'] === 200
    && $logRow['http_method'] === 'POST';

if ($f11ok) {
    echo "PASS F11 END-TO-END dispatch → fixture → map.push_status='pushed' + sync_log realm='FIXTURE-DEMO' status=200 (FIRST boundary-crosser)\n"; $pass++;
} else {
    echo "FAIL F11 result=" . json_encode($result) . " mapRow=" . json_encode($mapRow) . " logRow=" . json_encode($logRow) . "\n";
    $failures[] = 'F11';
}

// ── F12: QboDemoSeed::load populates every surface ─────────────────
// Clear out F11's seed first so load's sentinel ids don't collide.
db_execute("DELETE FROM acc_qbo_customer_map WHERE ff_customer_id = ?", [$ffId]);
db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='customer' AND entity_id = ?", [$ffId]);
db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='customer' AND entity_id = ?", [$ffId]);
db_execute("DELETE FROM customers WHERE id = ?", [$ffId]);

$summary = QboDemoSeed::load();
$nonZero = ($summary['pushed'] > 0)
    && ($summary['failed'] > 0)        // injection target
    && ($summary['skipped'] > 0)
    && ($summary['drift_events'] > 0)
    && ($summary['pulled']['account']  > 0)
    && ($summary['pulled']['item']     > 0)
    && ($summary['pulled']['tax_code'] > 0);

$pushedRows = (int) db_count("SELECT COUNT(*) FROM acc_qbo_customer_map WHERE qbo_customer_id LIKE 'QBO-FIX-%' AND push_status='pushed'", []);
$failedRows = (int) db_count("SELECT COUNT(*) FROM acc_qbo_customer_map WHERE ff_customer_id BETWEEN ? AND ? AND push_status='failed'", [QboDemoSeed::FF_ID_MIN, QboDemoSeed::FF_ID_MAX]);
$skipRows   = (int) db_count("SELECT COUNT(*) FROM acc_qbo_customer_map WHERE ff_customer_id BETWEEN ? AND ? AND push_status IN ('skipped_by_mode','skipped_soft_deleted')", [QboDemoSeed::FF_ID_MIN, QboDemoSeed::FF_ID_MAX]);
$fixtureLog = (int) db_count("SELECT COUNT(*) FROM acc_qbo_sync_log WHERE realm_id = ?", [QboFixture::REALM_SENTINEL]);

if ($nonZero && $pushedRows > 0 && $failedRows > 0 && $skipRows > 0 && $fixtureLog > 0) {
    echo "PASS F12 QboDemoSeed::load populates ≥1 row per surface (pushed={$pushedRows} failed={$failedRows} skipped={$skipRows} sync_log={$fixtureLog} drift={$summary['drift_events']})\n"; $pass++;
} else {
    echo "FAIL F12 nonZero=" . var_export($nonZero, true) . " pushed={$pushedRows} failed={$failedRows} skip={$skipRows} log={$fixtureLog} summary=" . json_encode($summary) . "\n";
    $failures[] = 'F12';
}

// ── F13: QboDemoSeed::wipe leaves real-row counts unchanged ────────
$wipeResult = QboDemoSeed::wipe();
$rowCountsAfterWipe = QboDemoSeed::countRealRows();
$diffs = [];
foreach ($rowCountsAtStart as $table => $count) {
    if (($rowCountsAfterWipe[$table] ?? -1) !== $count) {
        $diffs[$table] = ($rowCountsAfterWipe[$table] ?? -1) - $count;
    }
}
$fixtureRemnants = (int) db_count("SELECT COUNT(*) FROM acc_qbo_sync_log WHERE realm_id = ?", [QboFixture::REALM_SENTINEL])
                 + (int) db_count("SELECT COUNT(*) FROM acc_qbo_customer_map WHERE qbo_customer_id LIKE 'QBO-FIX-%'", [])
                 + (int) db_count("SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id BETWEEN ? AND ?", [QboDemoSeed::FF_ID_MIN, QboDemoSeed::FF_ID_MAX]);

if (empty($diffs) && $fixtureRemnants === 0) {
    echo "PASS F13 QboDemoSeed::wipe removes fixture-tagged rows only — real-row diff=0 across " . count($rowCountsAtStart) . " tables; 0 fixture remnants\n"; $pass++;
} else {
    echo "FAIL F13 real-row diffs=" . json_encode($diffs) . " fixtureRemnants={$fixtureRemnants}\n";
    $failures[] = 'F13';
}

} finally {
    // ── Defensive cleanup: settings restore + sentinel range scrub ──
    // (QboDemoSeed::wipe handles the bulk; this catches anything F1-F11
    // wrote outside the wipe's scope.)
    try {
        QboDemoSeed::wipe();
    } catch (\Throwable $e) {
        echo "WARN  defensive wipe failed: " . $e->getMessage() . "\n";
    }

    foreach ($settingsToRestore as $key => $originalValue) {
        try {
            if ($originalValue === null) {
                db_execute("DELETE FROM settings WHERE `key` = ?", [$key]);
            } else {
                db_execute(
                    "INSERT INTO settings (`key`, `value`, value_type, group_name)
                          VALUES (?, ?, 'string', 'quickbooks')
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                    [$key, (string) $originalValue]
                );
            }
        } catch (\Throwable $e) {
            echo "WARN  failed to restore setting '{$key}': " . $e->getMessage() . "\n";
        }
    }

    QboFixture::reset();
}

echo "\nqbo_fixture_pipeline_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\n";
exit(0);
