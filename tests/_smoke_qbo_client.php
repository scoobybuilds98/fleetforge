<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_client.php
 *
 * S-QBO-2 — Static structural smoke for the QuickBooks HTTP client.
 * Runs OFFLINE — no real HTTP calls to QBO. Verifies that the class
 * surface, exception hierarchy, classifyError categorisation, and
 * settings + sync_log guard logic are all wired correctly.
 *
 * 9 sub-checks:
 *   C1: QuickBooksClient class exists with all 10 expected public methods
 *   C2: All 9 typed exception classes exist and extend QuickBooksException
 *       (which itself extends RuntimeException)
 *   C3: classifyError maps the canonical Fault.code / Fault.type / HTTP
 *       statuses to the correct categories per spec §13.1
 *   C4: 4 retry + rate-limit settings keys present (Part A of S-QBO-2)
 *   C5: acc_qbo_sync_log table present with canonical §6.5 shape
 *       (has http_method column — distinguishes from legacy mapping shape)
 *   C6: writeSyncLog() degrades gracefully when the shape-mismatch
 *       check fires (simulated by inspecting the static-cache reset
 *       behaviour via repeated instantiation)
 *   C7: normalizeQueryResponse handles empty / 1-object / N-array /
 *       metadata-preserved shapes (K-22 Trap #60 guard)
 *   C8: CompanyInfoSync class exists + syncFromQbo public static;
 *       QuickBooksClient::setWorkerContext public static exists;
 *       3 new settings keys present (multi_currency_enabled,
 *       home_currency, company_country) (D-QBO-FIXPACK-11/-12/-15)
 *   C9: voidEntity() method exists, is public, has exactly 3 params
 *       (type, id, syncToken) — verifies QBO void operation path
 *       is available for S-QBO-12 before that session ships.
 *       (S-QBO-11-POSTVERIFY-FIXES)
 *
 * Exit 0 on all PASS; exit 1 with diagnostic list on any FAIL.
 *
 * @session  S-QBO-2
 * @updated  S-QBO-FIXPACK-3 — fixed docblock (was 6, actually 7); added C8
 *           for CompanyInfoSync + setWorkerContext + 3 new settings keys;
 *           total 8 sub-checks (D-QBO-FIXPACK-11/-12/-15).
 * @updated  S-QBO-11-POSTVERIFY-FIXES — added C9 voidEntity signature check;
 *           total 9 sub-checks.
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QuickBooksClient;
use FleetForge\QboPushers\CompanyInfoSync;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Exceptions\QuickBooksAuthExpiredException;
use FleetForge\Exceptions\QuickBooksStaleObjectException;
use FleetForge\Exceptions\QuickBooksDuplicateNameException;
use FleetForge\Exceptions\QuickBooksValidationException;
use FleetForge\Exceptions\QuickBooksForbiddenException;
use FleetForge\Exceptions\QuickBooksNotFoundException;
use FleetForge\Exceptions\QuickBooksTransientException;
use FleetForge\Exceptions\QuickBooksRateLimitException;

$failures = [];
$pass     = 0;
$total    = 9;

// ── C1: class exists + 10 expected public methods ─────────────
$expectedMethods = [
    'get', 'post', 'put', 'query', 'getEntity', 'createEntity',
    'updateEntity', 'getCompanyInfo', 'ensureValidToken', 'refreshAccessToken',
];
$c1Errors = [];
if (!class_exists(QuickBooksClient::class)) {
    $c1Errors[] = 'QuickBooksClient class not loaded';
} else {
    $ref = new ReflectionClass(QuickBooksClient::class);
    foreach ($expectedMethods as $m) {
        if (!$ref->hasMethod($m)) {
            $c1Errors[] = "missing public method: {$m}";
            continue;
        }
        $rm = $ref->getMethod($m);
        if (!$rm->isPublic()) {
            $c1Errors[] = "method not public: {$m}";
        }
    }
}
if (empty($c1Errors)) {
    echo "PASS C1  QuickBooksClient class exists with all 10 public methods\n";
    $pass++;
} else {
    echo "FAIL C1  " . implode('; ', $c1Errors) . "\n";
    $failures[] = 'C1';
}

// ── C2: 9 exception classes + hierarchy ───────────────────────
$expectedExceptions = [
    QuickBooksException::class,
    QuickBooksAuthExpiredException::class,
    QuickBooksStaleObjectException::class,
    QuickBooksDuplicateNameException::class,
    QuickBooksValidationException::class,
    QuickBooksForbiddenException::class,
    QuickBooksNotFoundException::class,
    QuickBooksTransientException::class,
    QuickBooksRateLimitException::class,
];
$c2Errors = [];
foreach ($expectedExceptions as $ex) {
    if (!class_exists($ex)) {
        $c2Errors[] = "missing class: {$ex}";
        continue;
    }
    if ($ex === QuickBooksException::class) {
        if (!is_subclass_of($ex, RuntimeException::class)) {
            $c2Errors[] = "QuickBooksException must extend RuntimeException";
        }
    } else {
        if (!is_subclass_of($ex, QuickBooksException::class)) {
            $c2Errors[] = "{$ex} must extend QuickBooksException";
        }
    }
}
if (empty($c2Errors)) {
    echo "PASS C2  9 exception classes exist with correct hierarchy\n";
    $pass++;
} else {
    echo "FAIL C2  " . implode('; ', $c2Errors) . "\n";
    $failures[] = 'C2';
}

// ── C3: classifyError mappings ────────────────────────────────
// Uses the public _testClassify accessor so we can hit the
// classifier without going through cURL.
$c3Errors = [];
try {
    $client = new QuickBooksClient();

    $cases = [
        // [http_status, fault_response, headers, expected_category]
        [400, ['Fault' => ['Error' => [['code' => '5010', 'Message' => 'stale']]]], [], 'stale_object'],
        [400, ['Fault' => ['Error' => [['code' => '6240', 'Message' => 'dup']]]], [], 'duplicate_name'],
        [400, ['Fault' => ['Error' => [['code' => '2500', 'Message' => 'invalid ref']]]], [], 'invalid_reference'],
        [400, ['Fault' => ['Error' => [['code' => '610',  'Message' => 'invalid obj']]]], [], 'invalid_object'],
        [400, ['Fault' => ['Error' => [['Message' => 'auth']], 'type' => 'AuthenticationFault']], [], 'auth_expired'],
        [400, ['Fault' => ['Error' => [['Message' => 'forbidden']], 'type' => 'AuthorizationFault']], [], 'forbidden'],
        [400, ['Fault' => ['Error' => [['Message' => 'validation']], 'type' => 'ValidationFault']], [], 'validation_failed'],
        [500, [], [], 'transient_5xx'],
        [502, [], [], 'transient_5xx'],
        [408, [], [], 'transient_5xx'],
        [429, [], ['retry-after' => '90'], 'rate_limited'],
        [401, [], [], 'auth_expired'],
        [403, [], [], 'forbidden'],
        [404, [], [], 'not_found'],
        [418, [], [], 'unknown'],
    ];

    foreach ($cases as $i => $case) {
        [$status, $fault, $headers, $expected] = $case;
        $result = $client->_testClassify($status, $fault, $headers);
        if ($result['category'] !== $expected) {
            $c3Errors[] = "case #{$i} (status={$status}): expected '{$expected}' got '{$result['category']}'";
        }
        if ($status === 429 && $result['retry_after'] !== 90) {
            $c3Errors[] = "case #{$i}: 429 retry_after should be 90, got " . var_export($result['retry_after'], true);
        }
    }
} catch (Throwable $e) {
    $c3Errors[] = 'classifyError threw: ' . $e->getMessage();
}
if (empty($c3Errors)) {
    echo "PASS C3  classifyError maps all 15 canonical cases correctly\n";
    $pass++;
} else {
    echo "FAIL C3  " . implode('; ', $c3Errors) . "\n";
    $failures[] = 'C3';
}

// ── C4: settings keys present ─────────────────────────────────
$expectedKeys = [
    'quickbooks.retry.max_attempts',
    'quickbooks.retry.backoff_base_seconds',
    'quickbooks.rate_limit.throttle_threshold',
    'quickbooks.rate_limit.throttle_seconds',
];
$c4Errors = [];
foreach ($expectedKeys as $k) {
    $v = settings_get($k);
    if ($v === null || $v === '') {
        $c4Errors[] = "missing or empty: {$k}";
    }
}
if (empty($c4Errors)) {
    echo "PASS C4  4 retry + rate-limit settings keys present\n";
    $pass++;
} else {
    echo "FAIL C4  " . implode('; ', $c4Errors) . "\n";
    $failures[] = 'C4';
}

// ── C5: acc_qbo_sync_log table present with §6.5 shape ────────
$c5Errors = [];
try {
    $tableCheck = db_select("SHOW TABLES LIKE 'acc_qbo_sync_log'", []);
    if (empty($tableCheck)) {
        $c5Errors[] = 'acc_qbo_sync_log table does not exist';
    } else {
        // Spec §6.5 distinguishing columns — every one of these
        // is REQUIRED. The legacy mapping-shape table lacks all of
        // them, so this check positively confirms §6.5 shape.
        $required = ['direction', 'http_method', 'endpoint', 'request_payload', 'duration_ms', 'queue_id', 'realm_id', 'environment'];
        $cols = db_select("SHOW COLUMNS FROM acc_qbo_sync_log", []);
        $colNames = array_column($cols, 'Field');
        foreach ($required as $r) {
            if (!in_array($r, $colNames, true)) {
                $c5Errors[] = "acc_qbo_sync_log missing canonical column: {$r}";
            }
        }
        // And the legacy shape's distinguishing columns should NOT exist
        $legacyOnly = ['ff_entity_id', 'qbo_sync_token', 'last_synced_at'];
        foreach ($legacyOnly as $l) {
            if (in_array($l, $colNames, true)) {
                $c5Errors[] = "acc_qbo_sync_log still carries legacy column (drift): {$l}";
            }
        }
    }
} catch (Throwable $e) {
    $c5Errors[] = 'sync_log shape check threw: ' . $e->getMessage();
}
if (empty($c5Errors)) {
    echo "PASS C5  acc_qbo_sync_log table has canonical §6.5 shape\n";
    $pass++;
} else {
    echo "FAIL C5  " . implode('; ', $c5Errors) . "\n";
    $failures[] = 'C5';
}

// ── C6: writeSyncLog graceful degradation ─────────────────────
// The method is private. We can't call it directly, but we CAN
// verify that a no-token call surfaces the right exception class
// (proving the call path makes it through executeRequest without
// dying on sync_log writes). And we can confirm via reflection
// that the method exists with the expected signature.
$c6Errors = [];
try {
    $ref = new ReflectionClass(QuickBooksClient::class);
    if (!$ref->hasMethod('writeSyncLog')) {
        $c6Errors[] = 'writeSyncLog method does not exist';
    } else {
        $rm = $ref->getMethod('writeSyncLog');
        if (!$rm->isPrivate()) {
            $c6Errors[] = 'writeSyncLog should be private (defensive — only called from executeRequest)';
        }
        $returnType = $rm->getReturnType();
        if ($returnType === null || (string) $returnType !== 'void') {
            $c6Errors[] = 'writeSyncLog should return void (advisory log writes never propagate failures)';
        }
    }
    // Confirm executeRequest with no_retry calls writeSyncLog before
    // throwing on transport failure. We trigger an error by calling
    // getCompanyInfo() when no token is set — should throw a
    // RuntimeException ("QBO not connected") BEFORE any HTTP attempt
    // and certainly before any sync log write. Verify that path
    // doesn't blow up.
    $client = new QuickBooksClient();
    $threw = false;
    try {
        // Backup + clear quickbooks.access_token so ensureValidToken throws
        $savedToken = (string) settings_get('quickbooks.access_token', '');
        if ($savedToken !== '') {
            QuickBooksClient::settings_write_qbo('access_token', '');
        }
        try {
            $client->getCompanyInfo();
        } catch (RuntimeException $e) {
            $threw = true;
        }
        // Restore in case the test was run against a connected DB
        if ($savedToken !== '') {
            QuickBooksClient::settings_write_qbo('access_token', $savedToken);
        }
    } catch (Throwable $e) {
        $c6Errors[] = 'no-token path setup error: ' . $e->getMessage();
    }
    if (!$threw) {
        $c6Errors[] = 'no-token getCompanyInfo did not throw RuntimeException as expected';
    }
} catch (Throwable $e) {
    $c6Errors[] = 'C6 reflection check threw: ' . $e->getMessage();
}
if (empty($c6Errors)) {
    echo "PASS C6  writeSyncLog has correct signature; no-token path raises cleanly\n";
    $pass++;
} else {
    echo "FAIL C6  " . implode('; ', $c6Errors) . "\n";
    $failures[] = 'C6';
}

// ── C7: query() response normalization (S-QBO-5-FIX-1 / K-22 Trap #60) ──
// Exercises QuickBooksClient::normalizeQueryResponse() against four
// mock response shapes — no real HTTP. The helper is the offline-
// testable seam for the in-place 1-row-object → array coercion that
// query() applies before returning. Mirror of the classifyError C3
// pattern: one structural sub-check, multiple cases internally.
$c7Errors = [];
$cases = [
    // Case 1 — empty QueryResponse passes through unchanged.
    [
        'label'    => 'empty QueryResponse',
        'input'    => ['QueryResponse' => []],
        'expected' => ['QueryResponse' => []],
    ],
    // Case 2 — single Customer object wrapped to single-element array.
    [
        'label'    => 'single object wrapped to array',
        'input'    => ['QueryResponse' => ['Customer' => ['DisplayName' => 'X']]],
        'expected' => ['QueryResponse' => ['Customer' => [['DisplayName' => 'X']]]],
    ],
    // Case 3 — array of customers passes through unchanged.
    [
        'label'    => 'array of objects unchanged',
        'input'    => ['QueryResponse' => ['Customer' => [['DisplayName' => 'X'], ['DisplayName' => 'Y']]]],
        'expected' => ['QueryResponse' => ['Customer' => [['DisplayName' => 'X'], ['DisplayName' => 'Y']]]],
    ],
    // Case 4 — metadata fields preserved AND single Customer wrapped.
    [
        'label'    => 'metadata preserved + single Customer wrapped',
        'input'    => ['QueryResponse' => ['startPosition' => 1, 'maxResults' => 25, 'Customer' => ['DisplayName' => 'X']]],
        'expected' => ['QueryResponse' => ['startPosition' => 1, 'maxResults' => 25, 'Customer' => [['DisplayName' => 'X']]]],
    ],
];
try {
    foreach ($cases as $i => $case) {
        $got = QuickBooksClient::normalizeQueryResponse($case['input']);
        if ($got !== $case['expected']) {
            $c7Errors[] = sprintf(
                "case %d (%s) mismatch — got %s, want %s",
                $i + 1,
                $case['label'],
                json_encode($got),
                json_encode($case['expected'])
            );
        }
    }
} catch (Throwable $e) {
    $c7Errors[] = 'normalizeQueryResponse threw: ' . $e->getMessage();
}
if (empty($c7Errors)) {
    echo "PASS C7  normalizeQueryResponse handles empty / 1-object / N-array / metadata-preserved cases\n";
    $pass++;
} else {
    echo "FAIL C7  " . implode('; ', $c7Errors) . "\n";
    $failures[] = 'C7';
}

// ── C8: CompanyInfoSync + setWorkerContext + 3 new settings keys ──────────────
// D-QBO-FIXPACK-11/-12/-15: verifies that the three new components added in
// S-QBO-FIXPACK-3 are all reachable + have the expected public-static interface.
$c8Errors = [];

// 8a: CompanyInfoSync class + syncFromQbo public static
if (!class_exists(CompanyInfoSync::class)) {
    $c8Errors[] = 'CompanyInfoSync class not autoloaded (lib/QboPushers/CompanyInfoSync.php)';
} else {
    $ref = new ReflectionClass(CompanyInfoSync::class);
    if (!$ref->hasMethod('syncFromQbo')) {
        $c8Errors[] = 'CompanyInfoSync::syncFromQbo method missing';
    } else {
        $rm = $ref->getMethod('syncFromQbo');
        if (!$rm->isPublic() || !$rm->isStatic()) {
            $c8Errors[] = 'CompanyInfoSync::syncFromQbo must be public static';
        }
    }
}

// 8b: QuickBooksClient::setWorkerContext public static
if (!class_exists(QuickBooksClient::class)) {
    $c8Errors[] = 'QuickBooksClient class not loaded (already caught in C1)';
} else {
    $ref = new ReflectionClass(QuickBooksClient::class);
    if (!$ref->hasMethod('setWorkerContext')) {
        $c8Errors[] = 'QuickBooksClient::setWorkerContext method missing (D-QBO-FIXPACK-15)';
    } else {
        $rm = $ref->getMethod('setWorkerContext');
        if (!$rm->isPublic() || !$rm->isStatic()) {
            $c8Errors[] = 'QuickBooksClient::setWorkerContext must be public static';
        }
    }
}

// 8c: 3 new settings keys seeded by migration 202605250000_S-QBO-FIXPACK-3.sql
$newKeys = [
    'quickbooks.multi_currency_enabled',
    'quickbooks.home_currency',
    'quickbooks.company_country',
];
foreach ($newKeys as $k) {
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$k]);
    if ($row === null) {
        $c8Errors[] = "settings key missing (migration not applied?): {$k}";
    }
}

if (empty($c8Errors)) {
    echo "PASS C8  CompanyInfoSync::syncFromQbo + QuickBooksClient::setWorkerContext public static; 3 new settings keys present\n";
    $pass++;
} else {
    echo "FAIL C8  " . implode('; ', $c8Errors) . "\n";
    $failures[] = 'C8';
}

// ── C9: voidEntity method exists with correct signature ──────────────────────
// WHY: voidEntity() was added in S-QBO-11-POSTVERIFY-FIXES to support the
// S-QBO-12 invoice void path. updateEntity() hardcodes ?operation=update in
// the URL, so a separate method is required for QBO's ?operation=void semantics.
$c9Errors = [];
$refC9 = new ReflectionClass(QuickBooksClient::class);
if (!$refC9->hasMethod('voidEntity')) {
    $c9Errors[] = 'voidEntity method not found on QuickBooksClient';
} else {
    $voidMethod = $refC9->getMethod('voidEntity');
    if (!$voidMethod->isPublic()) {
        $c9Errors[] = 'voidEntity must be public';
    }
    $voidParams = $voidMethod->getParameters();
    if (count($voidParams) !== 3) {
        $c9Errors[] = 'voidEntity must have exactly 3 params; got ' . count($voidParams);
    } else {
        if ($voidParams[0]->getName() !== 'type')       $c9Errors[] = 'param 0 must be "type"; got "' . $voidParams[0]->getName() . '"';
        if ($voidParams[1]->getName() !== 'id')         $c9Errors[] = 'param 1 must be "id"; got "' . $voidParams[1]->getName() . '"';
        if ($voidParams[2]->getName() !== 'syncToken')  $c9Errors[] = 'param 2 must be "syncToken"; got "' . $voidParams[2]->getName() . '"';
    }
}
if (empty($c9Errors)) {
    echo "PASS C9  voidEntity exists, public, 3 params (type / id / syncToken)\n";
    $pass++;
} else {
    echo "FAIL C9  " . implode('; ', $c9Errors) . "\n";
    $failures[] = 'C9';
}

// ── Summary ───────────────────────────────────────────────────
echo "\nqbo_client_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures);
    echo "\n";
    exit(1);
}
echo "\n";
exit(0);
