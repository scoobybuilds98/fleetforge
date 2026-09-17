<?php
declare(strict_types=1);

/**
 * tests/_smoke_audit_history_authz_redaction.php
 *
 * S-AUDIT-HISTORY-REDACT — KNOWN ISSUE #110. api/v1/audit/history.php (the
 * Activity card on every entity show page) only called require_auth_api(): any
 * signed-in user could read any entity type's audit trail, with money left in
 * `notes` and in the old/new value diffs. A dispatcher (payments:NONE) read
 * "Credit note CN-CR-2026-00188 (CAD 600.00) issued…" that the credit-note page
 * and API already hide.
 *
 * Contract asserted:
 *   A. Authorization mirrors each show page's own gate and FAILS CLOSED:
 *        credit_note→invoices, customer→customers, damage_claim/vendor/work_order
 *        →maintenance, equipment_unit→equipment, inspection→inspections,
 *        lease→leases, payment→payments, portal_user→settings, user→super_admin
 *        only; any other entity_type → 403 for EVERY role (incl. super_admin).
 *   B. Viewers failing can_view_financials(): money-named change fields dropped,
 *      money in free text (notes + text change values) replaced by
 *      "[amount hidden]"; operational text (numbers of records, dates, units,
 *      distances) and non-money numeric fields kept. super_admin: verbatim.
 *      EXCEPTION (operator decision 2026-09-17): lease contract-rate fields that
 *      api/v1/leases/show.php deliberately serves to dispatchers stay visible in
 *      lease history; lease AR totals / ASPE columns and every other entity's
 *      money fields stay hidden. Drift guard: the money-named fields visible in
 *      a dispatcher's lease history must EQUAL the money-named lease columns in a
 *      live leases/show.php dispatcher response.
 *   C. The two helpers (ff_is_money_field / ff_scrub_money_text) classify and
 *      scrub every money format the real audit writers emit (surveyed from the
 *      dev audit_log corpus: `$65.00`, `$-768.57`, `$0.5000/km`, `$0 mileage`,
 *      `CAD 1444.90`, `-= 1040.00`, `amount 700.00`, `80.00 → 90.00`,
 *      `Gain/loss=122373.64`).
 *
 * Endpoint runs in a CLI subprocess for REAL users logged in via the app's own
 * auth_login() (no DB writes without remember-me) so config/permissions.php is
 * exercised, and can_view_financials() is asserted per run before any
 * redaction result is trusted. Fixture audit rows carry module='smoke_ahr' and
 * a sentinel entity_id; removed in `finally`.
 *
 * PRE-FIX  : dispatcher reads payment/user/unknown histories and every figure → FAIL.
 * POST-FIX : all PASS.
 *
 * Run:  php tests/_smoke_audit_history_authz_redaction.php   Exit 0/1 (2 setup).
 *
 * @session S-AUDIT-HISTORY-REDACT
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID      = getmypid();
$TAG      = 'SMOKE-AHR-' . $PID;
// Sentinel id far above any real row (entity_id is INT UNSIGNED) — the endpoint
// never checks the entity exists, so no real record is needed or touched.
$SENTINEL = 4200000000 + ($PID % 1000000);
$HIDDEN   = '[amount hidden]';

// ---------------------------------------------------------------------------
// Subprocess harness — real login, then the real endpoint. First output line
// reports can_view_financials(); the JSON envelope follows.
// ---------------------------------------------------------------------------
$harnessFile = sys_get_temp_dir() . '/_ff_ahr_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$qs=\$argv[1]??''; \$uid=(int)(\$argv[2]??0); \$endpoint=\$argv[3]??'api/v1/audit/history.php';
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
\$_SERVER['REQUEST_URI']='/' . \$endpoint . '?' . \$qs;
require '{$ROOT}/config/app.php';
require_once FF_ROOT . '/includes/auth.php';
@session_start();
\$u = db_row("SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id WHERE u.id = ?", [\$uid]);
if (!\$u) { echo "FFSMOKE-FIN=missing-user\\n"; exit; }
@auth_login(\$u);
echo 'FFSMOKE-FIN=' . (can_view_financials() ? '1' : '0') . "\\n";
require '{$ROOT}/' . \$endpoint;
PHP);

/**
 * Run a GET endpoint as real user $uid.
 * @return array{fin:string, json:array, raw:string}
 */
$call = static function (string $endpoint, string $qs, int $uid) use ($harnessFile): array {
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' '
        . escapeshellarg($qs) . ' ' . escapeshellarg((string) $uid) . ' ' . escapeshellarg($endpoint) . ' 2>/dev/null');
    $fin  = preg_match('/^FFSMOKE-FIN=(\S+)/m', $out, $m) ? $m[1] : 'none';
    $s    = strpos($out, '{"');
    $json = $s !== false ? json_decode(trim(substr($out, $s)), true) : null;
    return ['fin' => $fin, 'json' => is_array($json) ? $json : [], 'raw' => substr($out, 0, 200)];
};
/** history.php as $uid for (entity_type, SENTINEL). */
$history = static fn(string $entityType, int $uid): array
    => $call('api/v1/audit/history.php', http_build_query(['entity_type' => $entityType, 'entity_id' => $SENTINEL]), $uid);

$isForbidden = static fn(array $r): bool => ($r['json']['success'] ?? null) === false
    && ($r['json']['error']['code'] ?? '') === 'FORBIDDEN';
$isOk        = static fn(array $r): bool => ($r['json']['success'] ?? null) === true
    && is_array($r['json']['data']['items'] ?? null);

try {
    $dispUser = db_row("SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
                         WHERE r.slug = 'dispatcher' AND u.status = 'active' AND u.deleted_at IS NULL
                           AND NOT EXISTS (SELECT 1 FROM user_permission_overrides o WHERE o.user_id = u.id)
                         ORDER BY u.id LIMIT 1");
    $superUser = db_row("SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
                          WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL
                          ORDER BY u.id LIMIT 1");
    if (!$dispUser || !$superUser) { echo "SETUP FAIL need an override-free active dispatcher + super_admin\n"; exit(2); }
    $dispUid  = (int) $dispUser['id'];
    $superUid = (int) $superUser['id'];

    // -----------------------------------------------------------------------
    // Fixtures — one tagged audit row per entity type under test.
    // -----------------------------------------------------------------------
    $insert = static function (string $entityType, string $action, ?string $notes, ?array $old = null, ?array $new = null)
        use ($SENTINEL): void {
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'Smoke AHR',
            'action'      => $action,
            'module'      => 'smoke_ahr',
            'entity_type' => $entityType,
            'entity_id'   => $SENTINEL,
            'notes'       => $notes,
            'old_values'  => $old !== null ? json_encode($old) : null,
            'new_values'  => $new !== null ? json_encode($new) : null,
            'ip_address'  => '127.0.0.1',
        ]);
    };

    $insert('credit_note', 'create', "Credit note CN-{$TAG} (CAD 600.00) issued against advance invoice INV-{$TAG} on lease close.");
    $insert('lease', 'lease_closed',
        "Lease L-{$TAG} closed 2026-06-15 — unit TR-7001 → available. Counter delta: total_invoiced -= 1040.00, "
        . "cumulative_correct=\$928.57, delta=\$-768.57; rate \$0.5000/km, \$0 mileage billed; 2,280.00 km driven.");
    $insert('lease', 'update', null,
        ['daily_rate' => '80.00', 'precharge_amount' => '0.00', 'total_invoiced' => '3100.00', 'initial_fair_value' => '41000.00',
         'status' => 'pending', 'odometer_start_km' => '45000.00', 'end_date' => '2026-09-30', 'cancel_reason' => null],
        ['daily_rate' => '90.00', 'precharge_amount' => '1250.00', 'total_invoiced' => '4700.00', 'initial_fair_value' => '42000.00',
         'status' => 'active', 'odometer_start_km' => '45120.50', 'end_date' => '2026-10-31',
         'cancel_reason' => "Customer paid \$1,250.00 deposit for L-{$TAG}"]);
    $insert('customer', 'update', null,
        ['credit_limit' => '5000.00', 'outstanding_balance' => '100.00', 'phone' => '555-0100'],
        ['credit_limit' => '9000.00', 'outstanding_balance' => '2200.00', 'phone' => '555-0199']);
    $insert('vendor', 'update', "total_spent recomputed (bug #7 drift repair): 0.00 → 10840.88 (bills 6326.88 + unbilled work orders 4514.00)");
    $insert('payment', 'payment_recorded', "Payment PAY-{$TAG} amount 700.00, fee 0.00");
    $insert('user', 'status_change', "Locked out by super admin. Reason: {$TAG}");
    $insert('portal_user', 'create', "Created portal user {$TAG}@smoke.local");
    $insert('invoice_holistic_reconciliation', 'create', "Holistic engine: delta=\$65.00 {$TAG}");

    echo str_repeat('─', 72) . "\n";
    echo "S-AUDIT-HISTORY-REDACT — api/v1/audit/history.php authz + money redaction\n";
    echo str_repeat('─', 72) . "\n";

    // Precondition: the two real accounts are what the test assumes.
    $pre = $history('credit_note', $dispUid);
    $preS = $history('credit_note', $superUid);
    if ($pre['fin'] !== '0' || $preS['fin'] !== '1') {
        // Throw rather than exit(): exit() skips `finally`, stranding the fixture rows.
        throw new RuntimeException("can_view_financials disp={$pre['fin']} super={$preS['fin']} (expected 0/1)");
    }
    $pass("precondition — can_view_financials() false for dispatcher #{$dispUid}, true for super_admin #{$superUid}");

    // -----------------------------------------------------------------------
    // A. Authorization — mirrors show-page gates, fail closed.
    // -----------------------------------------------------------------------
    // dispatcher: invoices/leases/maintenance view YES; payments/settings NONE; not super_admin.
    $authCases = [
        ['credit_note', true,  true],
        ['lease',       true,  true],
        ['vendor',      true,  true],
        ['customer',    true,  true],
        ['payment',     false, true],
        ['portal_user', false, true],
        ['user',        false, true],
        ['invoice_holistic_reconciliation', false, false], // unmapped → closed for everyone
    ];
    foreach ($authCases as [$type, $dispAllowed, $superAllowed]) {
        $d = $type === 'credit_note' ? $pre : $history($type, $dispUid);
        $s = $type === 'credit_note' ? $preS : $history($type, $superUid);
        $dOk = $dispAllowed ? $isOk($d) : $isForbidden($d);
        $sOk = $superAllowed ? $isOk($s) : $isForbidden($s);
        if ($dOk && $sOk) {
            $pass("authz {$type} — dispatcher " . ($dispAllowed ? '200' : '403') . ', super_admin ' . ($superAllowed ? '200' : '403'));
        } else {
            $fail("authz {$type} — dispatcher expected " . ($dispAllowed ? '200' : '403') . ' got ' . json_encode($d['json']['error'] ?? ($d['json']['success'] ?? $d['raw']))
                . '; super_admin expected ' . ($superAllowed ? '200' : '403') . ' got ' . json_encode($s['json']['error'] ?? ($s['json']['success'] ?? $s['raw'])));
        }
    }

    // -----------------------------------------------------------------------
    // B. Money redaction for the dispatcher; verbatim for super_admin.
    // -----------------------------------------------------------------------
    $blob = static fn(array $r): string => (string) json_encode($r['json']['data']['items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // B1 credit note note
    $d = $blob($pre); $s = $blob($preS);
    $leak = array_filter(['600.00'], static fn($n) => str_contains($d, $n));
    $keep = array_filter(["CN-{$TAG}", "INV-{$TAG}", 'on lease close', $HIDDEN], static fn($n) => !str_contains($d, $n));
    if (!$leak && !$keep && str_contains($s, 'CAD 600.00')) {
        $pass('credit_note notes — "CAD 600.00" hidden for dispatcher (CN/invoice numbers kept), verbatim for super_admin');
    } else {
        $fail('credit_note notes — leaked=[' . implode(',', $leak) . '] missing=[' . implode(',', $keep) . '] super_verbatim=' . (str_contains($s, 'CAD 600.00') ? 'yes' : 'NO'));
    }

    // B2 lease: mixed note + structured diff
    $dl = $history('lease', $dispUid); $sl = $history('lease', $superUid);
    // Notes only — the update row's changes legitimately carry visible contract rates.
    $noteOf = static function (array $r, string $action): string {
        foreach ($r['json']['data']['items'] ?? [] as $it) { if (($it['action'] ?? '') === $action) return (string) $it['notes']; }
        return '';
    };
    $d = $noteOf($dl, 'lease_closed');
    $moneyNeedles = ['1040.00', '928.57', '768.57', '0.5000', '$0 '];
    $leak = array_values(array_filter($moneyNeedles, static fn($n) => str_contains($d, $n)));
    $keepNeedles = ["L-{$TAG}", '2026-06-15', 'TR-7001', '2,280.00 km', $HIDDEN];
    $keep = array_values(array_filter($keepNeedles, static fn($n) => !str_contains($d, $n)));
    if (!$leak && !$keep) {
        $pass('lease notes — every figure hidden ($, $-, $/km, $0, bare -=), lease no./date/unit/distance kept');
    } else {
        $fail('lease notes — dispatcher leaked=[' . implode(', ', $leak) . '] missing=[' . implode(', ', $keep) . ']');
    }

    // Structured changes: find the update row's changes by field name.
    $fieldsOf = static function (array $r): array {
        foreach ($r['json']['data']['items'] ?? [] as $it) {
            if (($it['action'] ?? '') === 'update') {
                $out = [];
                foreach ($it['changes'] as $ch) { $out[$ch['field']] = $ch; }
                return $out;
            }
        }
        return [];
    };
    $df = $fieldsOf($dl); $sf = $fieldsOf($sl);
    $dispOk = ($df['daily_rate']['to'] ?? null) === '90.00' && ($df['precharge_amount']['to'] ?? null) === '1250.00'
        && !isset($df['total_invoiced']) && !isset($df['initial_fair_value'])
        && ($df['status']['to'] ?? null) === 'active'
        && ($df['odometer_start_km']['to'] ?? null) === '45120.50'
        && ($df['end_date']['to'] ?? null) === '2026-10-31'
        && is_string($df['cancel_reason']['to'] ?? null)
        && str_contains($df['cancel_reason']['to'], $HIDDEN)
        && str_contains($df['cancel_reason']['to'], "L-{$TAG}");
    $superOk = ($sf['daily_rate']['to'] ?? null) === '90.00' && ($sf['total_invoiced']['to'] ?? null) === '4700.00'
        && ($sf['initial_fair_value']['to'] ?? null) === '42000.00'
        && str_contains((string) ($sf['cancel_reason']['to'] ?? ''), '$1,250.00');
    if ($dispOk && $superOk) {
        $pass('lease changes — dispatcher keeps contract rates (daily_rate/precharge_amount), loses total_invoiced/initial_fair_value; status/end_date/odometer verbatim; free-text reason scrubbed; super_admin verbatim');
    } else {
        $fail('lease changes — dispatcher fields=' . json_encode($df) . ' super daily_rate=' . json_encode($sf['daily_rate'] ?? null));
    }

    // B3 vendor bare-decimal note (no currency marker at all)
    $dv = $blob($history('vendor', $dispUid)); $sv = $blob($history('vendor', $superUid));
    $leak = array_values(array_filter(['10840.88', '6326.88', '4514.00'], static fn($n) => str_contains($dv, $n)));
    if (!$leak && str_contains($dv, 'total_spent recomputed') && str_contains($dv, 'bug #7') && str_contains($sv, '10840.88')) {
        $pass('vendor notes — unmarked bare decimals hidden for dispatcher ("bug #7" kept), verbatim for super_admin');
    } else {
        $fail('vendor notes — leaked=[' . implode(', ', $leak) . '] super_verbatim=' . (str_contains($sv, '10840.88') ? 'yes' : 'NO'));
    }

    // B4 customer diff — no exemptions outside leases.
    $dc = $fieldsOf($history('customer', $dispUid)); $sc = $fieldsOf($history('customer', $superUid));
    if (!isset($dc['credit_limit']) && !isset($dc['outstanding_balance']) && ($dc['phone']['to'] ?? null) === '555-0199'
        && ($sc['credit_limit']['to'] ?? null) === '9000.00') {
        $pass('customer changes — credit_limit/outstanding_balance dropped for dispatcher, phone kept; super_admin verbatim');
    } else {
        $fail('customer changes — dispatcher=' . json_encode($dc) . ' super credit_limit=' . json_encode($sc['credit_limit'] ?? null));
    }

    // B5 DRIFT GUARD — lease history's visible money fields must equal what
    // api/v1/leases/show.php itself serves a dispatcher. One fixture row changes
    // EVERY money-named leases column; compare with a live show response.
    $leaseRow = db_row("SELECT id FROM leases WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$leaseRow) {
        $fail('drift guard — no lease to call leases/show.php with');
    } else {
        $moneyCols = [];
        foreach (db_select("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leases'") as $c) {
            if (function_exists('ff_is_money_field') && ff_is_money_field($c['c'])) $moneyCols[] = $c['c'];
        }
        $old = []; $new = [];
        foreach ($moneyCols as $i => $col) { $old[$col] = (string) (100 + $i) . '.00'; $new[$col] = (string) (200 + $i) . '.00'; }
        // Distinct action so $fieldsOf() (which reads the 'update' row) is unaffected.
        $insert('lease', 'restore', null, $old, $new);

        $show = $call('api/v1/leases/show.php', 'id=' . (int) $leaseRow['id'], $dispUid);
        $served = array_values(array_intersect($moneyCols, array_keys($show['json']['data'] ?? [])));
        $inHistory = [];
        foreach ($history('lease', $dispUid)['json']['data']['items'] ?? [] as $it) {
            if (($it['action'] ?? '') !== 'restore') continue;
            foreach ($it['changes'] as $ch) { if (in_array($ch['field'], $moneyCols, true)) $inHistory[] = $ch['field']; }
        }
        sort($served); sort($inHistory);
        if (($show['json']['success'] ?? false) === true && $moneyCols && $served === $inHistory) {
            $pass('drift guard — dispatcher lease history shows exactly the ' . count($served) . ' money-named lease fields leases/show.php serves them (of ' . count($moneyCols) . ' money-named columns)');
        } else {
            $fail('drift guard — show_ok=' . json_encode($show['json']['success'] ?? null)
                . ' served_not_in_history=[' . implode(', ', array_diff($served, $inHistory)) . ']'
                . ' in_history_not_served=[' . implode(', ', array_diff($inHistory, $served)) . ']');
        }
    }

    // -----------------------------------------------------------------------
    // C. Helper contract — classifier + scrubber over real writer formats.
    // -----------------------------------------------------------------------
    if (!function_exists('ff_is_money_field') || !function_exists('ff_scrub_money_text')) {
        $fail('helpers — ff_is_money_field()/ff_scrub_money_text() not defined in includes/functions.php');
    } else {
        $money    = ['amount', 'amount_remaining', 'daily_rate', 'mileage_rate_km', 'exchange_rate_to_cad', 'outstanding_balance',
                     'credit_limit', 'total_revenue', 'acquisition_cost', 'initial_direct_costs', 'total_spent', 'late_fee_value',
                     'discount_value', 'total_invoiced', 'total_paid', 'final_total_charge', 'currency_markup_pct', 'tax_rate_gst',
                     'precharge_balance', 'cartage_amount', 'unit_price', 'subtotal', 'deposit_amount'];
        $notMoney = ['status', 'end_date', 'odometer_start_km', 'estimated_mileage_km', 'km_to_miles_conversion', 'length_ft',
                     'engine_hours_at_end', 'total_distance_km', 'total_days', 'mileage_tracking_mode', 'unit_number', 'notes',
                     'credit_note_number', 'samsara_last_speed_kph', 'precharge_enabled'];
        $wrongM = array_values(array_filter($money, static fn($k) => !ff_is_money_field($k)));
        $wrongN = array_values(array_filter($notMoney, static fn($k) => ff_is_money_field($k)));
        if (!$wrongM && !$wrongN) {
            $pass('ff_is_money_field — ' . count($money) . ' money columns classified money, ' . count($notMoney) . ' operational columns not');
        } else {
            $fail('ff_is_money_field — missed money=[' . implode(', ', $wrongM) . '] false-positive=[' . implode(', ', $wrongN) . ']');
        }

        // [input, needles that must be GONE, needles that must REMAIN]
        $scrubCases = [
            ['cumulative_correct=$65.00, delta=$-768.57',                 ['65.00', '768.57'],          ['cumulative_correct=', 'delta=']],
            ['has a $0.5000/km rate — $0 mileage billed on this close',   ['0.5000', '$0 '],            ['/km rate', 'mileage billed']],
            ['Credit note CN-CR-2026-00187 (CAD 1444.90) issued',         ['1444.90'],                  ['CN-CR-2026-00187']],
            ['overflow 12.50 USD',                                        ['12.50'],                    ['overflow']],
            ['Counter delta: total_invoiced -= 1040.00 (Path B)',         ['1040.00'],                  ['total_invoiced -=', '(Path B)']],
            ['NSF processed: Payment PAY-NSF-50396, amount 700.00, fee 0',['700.00', 'fee 0'],          ['PAY-NSF-50396']],
            ['Rate amendment: daily_rate: 80.00 → 90.00',                 ['80.00', '90.00'],           ['daily_rate:']],
            ['AP Payment APAY-2027-00009 — $500.00 to OA Vendor via eft', ['500.00'],                   ['APAY-2027-00009', 'OA Vendor']],
            ['Rate=1.40. Gain/loss=122373.64',                            ['1.40', '122373.64'],        ['Gain/loss=']],
            ['Balance $1,234,567.89 remains; paid -$40.01',               ['1,234,567.89', '40.01'],    ['remains']],
            ['coverage through 2026-06-15, return 2026-06-15 (182ms)',    [],                           ['2026-06-15', '(182ms)']],
            ['odometer 45,000.00 km, 2,280.00 km driven, 12.50 hours',    [],                           ['45,000.00 km', '2,280.00 km', '12.50 hours']],
            ['Batch run BR-2026-000001 generated: 2 created, 1 skipped',  [],                           ['BR-2026-000001', '2 created']],
            ['client 127.0.0.1 v1.10.2 invoice INV-2026-00753 #999993',   [],                           ['127.0.0.1', 'v1.10.2', 'INV-2026-00753', '#999993']],
        ];
        $bad = [];
        foreach ($scrubCases as [$in, $gone, $stay]) {
            $out = ff_scrub_money_text($in);
            foreach ($gone as $g) { if (str_contains($out, $g)) $bad[] = "'{$in}' kept '{$g}' → '{$out}'"; }
            foreach ($stay as $k) { if (!str_contains($out, $k)) $bad[] = "'{$in}' lost '{$k}' → '{$out}'"; }
            if ($gone && !str_contains($out, $HIDDEN)) $bad[] = "'{$in}' no {$HIDDEN} marker → '{$out}'";
        }
        if (!$bad) {
            $pass('ff_scrub_money_text — ' . count($scrubCases) . ' writer formats: figures hidden, ids/dates/distances/IPs/versions kept');
        } else {
            $fail('ff_scrub_money_text — ' . implode(' | ', $bad));
        }
    }

} catch (RuntimeException $e) {
    echo "SETUP FAIL {$e->getMessage()}\n";
    $setupFailed = true;
} finally {
    db_execute("DELETE FROM audit_log WHERE module = 'smoke_ahr' AND entity_id = ?", [$SENTINEL]);
    if (file_exists($harnessFile)) @unlink($harnessFile);
}

if (!empty($setupFailed)) exit(2);

echo "\n" . str_repeat('─', 72) . "\n";
printf("AUDIT HISTORY AUTHZ + REDACTION — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
