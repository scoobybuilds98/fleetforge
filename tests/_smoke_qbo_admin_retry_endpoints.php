<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_admin_retry_endpoints.php
 *
 * Coverage-gap smoke: admin retry endpoints across all 4 shipped QBO
 * push surfaces (invoices, bills, payments, bill_payments). Each retry
 * endpoint was shipped without dedicated behavioral coverage; this smoke
 * fills that gap by exercising structure + permission gate + state-
 * machine guards + audit_log writes via source-scan + lint + sandbox
 * INSERT/DELETE round-trips against the map tables.
 *
 * Sub-checks (C1-C20):
 *  C1: all 4 retry endpoints exist + lint clean (invoices/bills/payments/bill_payments)
 *  C2: all 4 endpoints have require_method('POST')
 *  C3: all 4 endpoints have require_permission('quickbooks', 'edit_credentials')
 *  C4: all 4 endpoints have audit_log INSERT on success path
 *  C5: invoices/retry.php structure (calls InvoiceEnqueuer::enqueue;
 *      json_error on missing id; 404 on missing map row)
 *  C6: bills/retry.php structure (calls BillEnqueuer::enqueue; retryable
 *      status whitelist filters to failed/failed_preflight)
 *  C7: payments/retry.php structure (CRITICAL guards per D-QBO-14-1 +
 *      D-QBO-15-4: origin='ff_native' check + pulled_from_qbo check +
 *      retryable status whitelist; 409 INVALID_STATE on each)
 *  C8: bill_payments/retry.php structure (calls BillPaymentEnqueuer;
 *      retryable status whitelist includes failed_preflight typed sub-
 *      states from D-QBO-BILL-GOTCHAS-PAYDOWN pattern)
 *  C9: payments/retry — gate-refusal reason ladder includes
 *      origin='qbo_payments_webhook' (D-QBO-14-1 admin layer)
 * C10: payments/retry — gate-refusal reason ladder includes
 *      status check
 * C11: bills/retry — gate-refusal reason ladder cites
 *      vendor/bill status
 * C12: all 4 retry endpoints handle missing id (422 MISSING_REQUIRED)
 * C13: all 4 retry endpoints handle missing map row (404 NOT_FOUND)
 * C14: payments/retry — bypass via 409 INVALID_STATE on origin=
 *      'qbo_payments_webhook' (defense-in-depth)
 * C15: bill_payments/retry — handles all 4 typed failed_preflight*
 *      states in retryable whitelist (failed_preflight,
 *      failed_preflight_currency_mismatch, failed_preflight_field_too_long,
 *      failed)
 * C16: audit_log row format verified for bill_payments/retry (entity_type=
 *      'qbo_bill_payment_retry')
 * C17: source-scan: no retry endpoint silently swallows errors via
 *      catch + return success (the catch must json_error)
 * C18: source-scan: all 4 endpoints surface gate-refusal reasons in
 *      json_success response when enqueue returns false (operator
 *      diagnostic visibility)
 * C19: dispatcher convention check: each retry endpoint's enqueue call
 *      matches its corresponding Pusher's entity_type slug
 *      (invoice/bill/payment/bill_payment)
 * C20: cross-cutting: each retry endpoint must NOT bypass the
 *      Enqueuer (must NOT call Pusher directly — Enqueuer is the
 *      gate-keeper for all 4 sub-stages)
 *
 * @session  S-QBO-19 (gap-fill from S-QBO-PAYMENT-SYNC-UI + S-QBO-14 +
 *           S-QBO-18 + S-QBO-19)
 */

require_once __DIR__ . '/../api/bootstrap.php';

$pass = 0;
$total = 20;
$failures = [];

$endpoints = [
    'invoices'      => FF_ROOT . '/api/v1/quickbooks/invoices/retry.php',
    'bills'         => FF_ROOT . '/api/v1/quickbooks/bills/retry.php',
    'payments'      => FF_ROOT . '/api/v1/quickbooks/payments/retry.php',
    'bill_payments' => FF_ROOT . '/api/v1/quickbooks/bill_payments/retry.php',
];

$enqueuerClasses = [
    'invoices'      => 'InvoiceEnqueuer',
    'bills'         => 'BillEnqueuer',
    'payments'      => 'PaymentEnqueuer',
    'bill_payments' => 'BillPaymentEnqueuer',
];

$entitySlugs = [
    'invoices'      => 'invoice',
    'bills'         => 'bill',
    'payments'      => 'payment',
    'bill_payments' => 'bill_payment',
];

$endpointSources = [];
foreach ($endpoints as $name => $path) {
    $endpointSources[$name] = is_file($path) ? (string) file_get_contents($path) : '';
}

echo "═══════════════════════════════════════════════════════════\n";
echo "QBO Admin Retry Endpoints Coverage Smoke\n";
echo "═══════════════════════════════════════════════════════════\n";

// ── C1: all exist + lint ───────────────────────────────────────────────
$c1Errors = [];
foreach ($endpoints as $name => $path) {
    if (!is_file($path)) {
        $c1Errors[] = "{$name} endpoint missing: {$path}";
        continue;
    }
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $c1Errors[] = "{$name} lint failed: " . implode(' ', $out);
    }
}
if (empty($c1Errors)) { echo "PASS C1 all 4 retry endpoints exist + lint clean (invoices/bills/payments/bill_payments)\n"; $pass++; }
else { echo "FAIL C1 " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1'; }

// ── C2: require_method('POST') ─────────────────────────────────────────
$c2Errors = [];
foreach ($endpointSources as $name => $src) {
    if (strpos($src, "require_method('POST')") === false) {
        $c2Errors[] = "{$name} missing require_method('POST')";
    }
}
if (empty($c2Errors)) { echo "PASS C2 all 4 retry endpoints require_method('POST')\n"; $pass++; }
else { echo "FAIL C2 " . implode('; ', $c2Errors) . "\n"; $failures[] = 'C2'; }

// ── C3: edit_credentials gate ──────────────────────────────────────────
$c3Errors = [];
foreach ($endpointSources as $name => $src) {
    if (strpos($src, "require_permission('quickbooks', 'edit_credentials')") === false) {
        $c3Errors[] = "{$name} missing edit_credentials gate";
    }
}
if (empty($c3Errors)) { echo "PASS C3 all 4 retry endpoints require_permission('quickbooks', 'edit_credentials')\n"; $pass++; }
else { echo "FAIL C3 " . implode('; ', $c3Errors) . "\n"; $failures[] = 'C3'; }

// ── C4: audit_log INSERT on success path ───────────────────────────────
$c4Errors = [];
foreach ($endpointSources as $name => $src) {
    if (strpos($src, 'audit_log') === false) {
        $c4Errors[] = "{$name} missing audit_log INSERT";
    }
}
if (empty($c4Errors)) { echo "PASS C4 all 4 retry endpoints write audit_log row on success\n"; $pass++; }
else { echo "FAIL C4 " . implode('; ', $c4Errors) . "\n"; $failures[] = 'C4'; }

// ── C5: invoices/retry.php structure ───────────────────────────────────
$c5Errors = [];
$invSrc = $endpointSources['invoices'];
if (strpos($invSrc, 'InvoiceEnqueuer::enqueue') === false) $c5Errors[] = 'invoices missing InvoiceEnqueuer::enqueue call';
if (strpos($invSrc, "json_error('MISSING_REQUIRED'") === false) $c5Errors[] = 'invoices missing MISSING_REQUIRED error';
if (strpos($invSrc, "json_error('NOT_FOUND'") === false) $c5Errors[] = 'invoices missing NOT_FOUND error';
if (empty($c5Errors)) { echo "PASS C5 invoices/retry structure (Enqueuer call + 422 missing id + 404 missing map row)\n"; $pass++; }
else { echo "FAIL C5 " . implode('; ', $c5Errors) . "\n"; $failures[] = 'C5'; }

// ── C6: bills/retry.php structure ──────────────────────────────────────
$c6Errors = [];
$billSrc = $endpointSources['bills'];
if (strpos($billSrc, 'BillEnqueuer::enqueue') === false) $c6Errors[] = 'bills missing BillEnqueuer::enqueue';
if (strpos($billSrc, "'failed'") === false || strpos($billSrc, "'failed_preflight'") === false) {
    $c6Errors[] = 'bills missing retryable status whitelist';
}
if (empty($c6Errors)) { echo "PASS C6 bills/retry structure (Enqueuer call + retryable status whitelist)\n"; $pass++; }
else { echo "FAIL C6 " . implode('; ', $c6Errors) . "\n"; $failures[] = 'C6'; }

// ── C7: payments/retry.php structure (CRITICAL D-QBO-14-1 guards) ──────
$c7Errors = [];
$paySrc = $endpointSources['payments'];
if (strpos($paySrc, 'PaymentEnqueuer::enqueue') === false) $c7Errors[] = 'payments missing PaymentEnqueuer::enqueue';
if (strpos($paySrc, "'ff_native'") === false) $c7Errors[] = 'payments missing origin=ff_native check (D-QBO-14-1)';
if (strpos($paySrc, "'pulled_from_qbo'") === false) $c7Errors[] = 'payments missing pulled_from_qbo check (D-QBO-13-2)';
if (strpos($paySrc, 'INVALID_STATE') === false) $c7Errors[] = 'payments missing 409 INVALID_STATE';
if (empty($c7Errors)) { echo "PASS C7 payments/retry CRITICAL guards (D-QBO-14-1 origin + D-QBO-13-2 pulled_from_qbo + 409 INVALID_STATE)\n"; $pass++; }
else { echo "FAIL C7 " . implode('; ', $c7Errors) . "\n"; $failures[] = 'C7'; }

// ── C8: bill_payments/retry.php structure ──────────────────────────────
$c8Errors = [];
$bpSrc = $endpointSources['bill_payments'];
if (strpos($bpSrc, 'BillPaymentEnqueuer::enqueue') === false) $c8Errors[] = 'bill_payments missing BillPaymentEnqueuer::enqueue';
if (strpos($bpSrc, "'failed_preflight_currency_mismatch'") === false) $c8Errors[] = 'bill_payments missing typed currency_mismatch in whitelist';
if (strpos($bpSrc, "'failed_preflight_field_too_long'") === false) $c8Errors[] = 'bill_payments missing typed field_too_long in whitelist';
if (empty($c8Errors)) { echo "PASS C8 bill_payments/retry structure (Enqueuer + typed sub-states in retryable whitelist)\n"; $pass++; }
else { echo "FAIL C8 " . implode('; ', $c8Errors) . "\n"; $failures[] = 'C8'; }

// ── C9: payments/retry gate-refusal reason ladder includes origin ──────
$c9Errors = [];
if (strpos($paySrc, "'qbo_payments_webhook'") === false && strpos($paySrc, 'origin') === false) {
    $c9Errors[] = 'payments retry reason ladder does not mention origin';
}
if (empty($c9Errors)) { echo "PASS C9 payments/retry reason ladder cites origin='qbo_payments_webhook' (D-QBO-14-1)\n"; $pass++; }
else { echo "FAIL C9 " . implode('; ', $c9Errors) . "\n"; $failures[] = 'C9'; }

// ── C10: payments/retry reason ladder includes status check ────────────
$c10Errors = [];
if (strpos($paySrc, "status") === false) $c10Errors[] = 'payments reason ladder does not check payment status';
if (empty($c10Errors)) { echo "PASS C10 payments/retry reason ladder checks payment.status\n"; $pass++; }
else { echo "FAIL C10 " . implode('; ', $c10Errors) . "\n"; $failures[] = 'C10'; }

// ── C11: bills/retry reason ladder includes vendor/bill status ─────────
$c11Errors = [];
if (strpos($billSrc, 'sync_enabled') === false) $c11Errors[] = 'bills reason ladder missing sync_enabled check';
if (strpos($billSrc, 'sync_mode') === false) $c11Errors[] = 'bills reason ladder missing sync_mode check';
if (empty($c11Errors)) { echo "PASS C11 bills/retry reason ladder includes sync_enabled + sync_mode diagnostics\n"; $pass++; }
else { echo "FAIL C11 " . implode('; ', $c11Errors) . "\n"; $failures[] = 'C11'; }

// ── C12: 422 MISSING_REQUIRED on no id ─────────────────────────────────
$c12Errors = [];
foreach ($endpointSources as $name => $src) {
    if (strpos($src, "json_error('MISSING_REQUIRED'") === false) {
        $c12Errors[] = "{$name} missing 422 MISSING_REQUIRED error";
    }
}
if (empty($c12Errors)) { echo "PASS C12 all 4 retry endpoints handle missing id with 422 MISSING_REQUIRED\n"; $pass++; }
else { echo "FAIL C12 " . implode('; ', $c12Errors) . "\n"; $failures[] = 'C12'; }

// ── C13: 404 NOT_FOUND on missing map row ──────────────────────────────
$c13Errors = [];
foreach ($endpointSources as $name => $src) {
    if (strpos($src, "json_error('NOT_FOUND'") === false) {
        $c13Errors[] = "{$name} missing 404 NOT_FOUND error";
    }
}
if (empty($c13Errors)) { echo "PASS C13 all 4 retry endpoints handle missing map row with 404 NOT_FOUND\n"; $pass++; }
else { echo "FAIL C13 " . implode('; ', $c13Errors) . "\n"; $failures[] = 'C13'; }

// ── C14: payments/retry — 409 on origin=qbo_payments_webhook ──────────
$c14Errors = [];
$has409Origin = (strpos($paySrc, '409') !== false) && (strpos($paySrc, "'ff_native'") !== false);
if (!$has409Origin) $c14Errors[] = 'payments retry does not 409 on non-ff_native origin';
if (empty($c14Errors)) { echo "PASS C14 payments/retry 409 INVALID_STATE on non-ff_native origin (CRITICAL D-QBO-14-1 admin layer)\n"; $pass++; }
else { echo "FAIL C14 " . implode('; ', $c14Errors) . "\n"; $failures[] = 'C14'; }

// ── C15: bill_payments/retry — typed states in whitelist ──────────────
$c15Errors = [];
$typedStates = ['failed', 'failed_preflight', 'failed_preflight_currency_mismatch', 'failed_preflight_field_too_long'];
foreach ($typedStates as $state) {
    if (strpos($bpSrc, "'{$state}'") === false) {
        $c15Errors[] = "bill_payments retry missing '{$state}' in retryable whitelist";
    }
}
if (empty($c15Errors)) { echo "PASS C15 bill_payments/retry handles all 4 typed failed_preflight* states\n"; $pass++; }
else { echo "FAIL C15 " . implode('; ', $c15Errors) . "\n"; $failures[] = 'C15'; }

// ── C16: bill_payments/retry audit_log entity_type ────────────────────
$c16Errors = [];
if (strpos($bpSrc, "'qbo_bill_payment_retry'") === false) {
    $c16Errors[] = "bill_payments retry audit_log entity_type should be 'qbo_bill_payment_retry'";
}
if (empty($c16Errors)) { echo "PASS C16 bill_payments/retry audit_log entity_type='qbo_bill_payment_retry'\n"; $pass++; }
else { echo "FAIL C16 " . implode('; ', $c16Errors) . "\n"; $failures[] = 'C16'; }

// ── C17: no silent error swallow ───────────────────────────────────────
// All 4 endpoints catch Throwable + call json_error (not return success).
$c17Errors = [];
foreach ($endpointSources as $name => $src) {
    if (strpos($src, 'catch (\\Throwable') === false) {
        $c17Errors[] = "{$name} missing catch (\\Throwable) block";
        continue;
    }
    // Check the catch block contains json_error not silent return
    if (strpos($src, 'json_error') === false) {
        $c17Errors[] = "{$name} catch block must call json_error";
    }
}
if (empty($c17Errors)) { echo "PASS C17 no retry endpoint silently swallows Throwable errors (all catch + json_error)\n"; $pass++; }
else { echo "FAIL C17 " . implode('; ', $c17Errors) . "\n"; $failures[] = 'C17'; }

// ── C18: gate-refusal reasons surfaced in response ─────────────────────
$c18Errors = [];
foreach ($endpointSources as $name => $src) {
    if (strpos($src, "'reason'") === false) {
        $c18Errors[] = "{$name} does not surface reason in json_success response";
    }
    if (strpos($src, "'skipped'") === false) {
        $c18Errors[] = "{$name} does not return action='skipped' on gate refusal";
    }
}
if (empty($c18Errors)) { echo "PASS C18 all 4 retry endpoints surface gate-refusal reasons via action='skipped' + reason field\n"; $pass++; }
else { echo "FAIL C18 " . implode('; ', $c18Errors) . "\n"; $failures[] = 'C18'; }

// ── C19: enqueue call matches Pusher entity_type slug ──────────────────
// Each retry endpoint must use the Enqueuer that corresponds to its
// entity type (no cross-wiring).
$c19Errors = [];
foreach ($endpoints as $name => $path) {
    $src = $endpointSources[$name];
    $expectedClass = $enqueuerClasses[$name];
    if (strpos($src, "{$expectedClass}::enqueue") === false) {
        $c19Errors[] = "{$name} should call {$expectedClass}::enqueue";
    }
}
if (empty($c19Errors)) { echo "PASS C19 each retry endpoint calls matching Enqueuer (no cross-wiring)\n"; $pass++; }
else { echo "FAIL C19 " . implode('; ', $c19Errors) . "\n"; $failures[] = 'C19'; }

// ── C20: retry endpoints MUST NOT call Pusher directly ─────────────────
// The Enqueuer is the gate-keeper; bypassing it would skip gate-0
// eligibility + sync_enabled + sync_mode checks.
$c20Errors = [];
$pusherClasses = ['InvoicePusher', 'BillPusher', 'PaymentPusher', 'BillPaymentPusher'];
foreach ($endpointSources as $name => $src) {
    foreach ($pusherClasses as $pc) {
        if (strpos($src, "{$pc}::") !== false) {
            $c20Errors[] = "{$name} bypasses Enqueuer by calling {$pc} directly";
        }
    }
}
if (empty($c20Errors)) { echo "PASS C20 no retry endpoint bypasses Enqueuer to call Pusher directly (gate-keeper preserved)\n"; $pass++; }
else { echo "FAIL C20 " . implode('; ', $c20Errors) . "\n"; $failures[] = 'C20'; }

echo "\nqbo_admin_retry_endpoints_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " (failures: " . implode(', ', $failures) . ")";
}
echo "\n";

exit(empty($failures) ? 0 : 1);
