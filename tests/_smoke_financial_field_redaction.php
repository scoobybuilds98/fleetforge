<?php
declare(strict_types=1);

/**
 * tests/_smoke_financial_field_redaction.php
 *
 * WAVE 5 — financial-field redaction predicate across customer/equipment reads.
 *
 * The dashboard authz fix introduced can_view_financials() (= payments:view) as
 * the shared "can see money" predicate. The audit flagged the same leak on the
 * customer/equipment surfaces: a dispatcher (payments=NONE) receives customer
 * outstanding_balance/revenue/credit and unit cost/revenue via the list/show
 * APIs. This applies the same serve-time redaction there.
 *
 * Drives the REAL endpoints via the subprocess harness with dispatcher vs
 * super_admin sessions and asserts the money KEYS are absent for the dispatcher
 * and present for super_admin (value irrelevant — redaction unsets the key):
 *   - customers/index.php       outstanding_balance / total_revenue
 *   - customers/show.php        outstanding_balance / credit_limit
 *   - customers/kpis.php        overdue_balance
 *   - equipment/units/index.php total_revenue
 *   - equipment/units/show.php  acquisition_cost / total_maintenance_cost
 *
 * PRE-FIX  : dispatcher sees the money keys → FAIL.
 * POST-FIX : dispatcher keys stripped; super_admin keeps them.
 *
 * S-CN-SHOW-REDACT adds credit notes — the JSON endpoints AND the server-rendered
 * app/admin/credit_notes/show.php, which rendered amount / remaining balance /
 * per-application amounts (and embedded CN_REMAINING_CENTS in its script) to
 * dispatchers although the API hid them. A tagged fixture credit note with two
 * applications (distinctive figures 4,321.87 / 1,234.56 / 1,987.65 / 1,099.66 /
 * 3,087.31) is inserted and removed in `finally`. The page is rendered in a CLI
 * subprocess for REAL users logged in through the app's own auth_login() (no DB
 * writes without remember-me), so config/permissions.php is exercised, not a
 * hand-built permission map:
 *   - credit_notes/index.php + show.php   amount / amount_remaining / amount_applied / invoice_total keys
 *   - credit_notes/kpis.php               dollar totals '0.00' for dispatcher, real for super_admin
 *   - credit_notes/show page, dispatcher  no figure, no cents constant, no money column/footer/tile;
 *                                         number, source, currency, invoice link, applied-by still shown
 *   - credit_notes/show page, super_admin every figure + Apply card + CN_REMAINING_CENTS present
 *   - credit_notes/show page, dispatcher + session override invoices:edit (no payments:view)
 *                                         Apply card withheld, void modal shows no figure
 *
 * Run:  php tests/_smoke_financial_field_redaction.php   Exit 0/1 (2 setup).
 *
 * @session WAVE-5-FINANCIAL-FIELD-REDACTION, S-CN-SHOW-REDACT
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$harnessFile = sys_get_temp_dir() . '/_ff_finredact_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$qs=\$argv[2]??''; \$sess=json_decode(base64_decode(\$argv[3]??''), true);
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);
$get = static function (string $endpoint, string $qs, array $sess) use ($harnessFile): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"'); if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => substr((string) $out, 0, 160)];
};

// Page harness — renders a server-rendered admin page for a REAL user logged in
// via the app's own auth_login() (config/permissions.php + DB overrides load
// exactly as at login). argv[4] is an optional base64 JSON of extra per-user
// overrides merged into the SESSION only (never written to the DB). The first
// output line reports can_view_financials() so the caller can assert the
// precondition before trusting any redaction result.
$pageHarnessFile = sys_get_temp_dir() . '/_ff_finredact_page_' . $PID . '.php';
file_put_contents($pageHarnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$page=\$argv[1]??''; \$qs=\$argv[2]??''; \$uid=(int)(\$argv[3]??0);
\$extra=json_decode(base64_decode(\$argv[4]??''), true) ?: [];
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
\$_SERVER['REQUEST_URI']='/' . \$page . '?' . \$qs;
require '{$ROOT}/config/app.php';
require_once FF_ROOT . '/includes/auth.php';
@session_start();
\$u = db_row("SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id WHERE u.id = ?", [\$uid]);
if (!\$u) { echo "FFSMOKE-FIN=missing-user\n"; exit; }
@auth_login(\$u);
foreach (\$extra as \$m => \$acts) { foreach (\$acts as \$a => \$g) { \$_SESSION['ff_user']['permission_overrides'][\$m][\$a] = (int) \$g; } }
echo 'FFSMOKE-FIN=' . (can_view_financials() ? '1' : '0') . "\n";
require '{$ROOT}/' . \$page;
PHP);
$renderPage = static function (string $page, string $qs, int $uid, array $extraOverrides = []) use ($pageHarnessFile): array {
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($pageHarnessFile) . ' ' . escapeshellarg($page)
        . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg((string) $uid)
        . ' ' . escapeshellarg(base64_encode(json_encode($extraOverrides))) . ' 2>/dev/null');
    $fin = preg_match('/^FFSMOKE-FIN=(\S+)/m', $out, $m) ? $m[1] : 'none';
    return ['fin' => $fin, 'html' => $out];
};

// True if $keys appear anywhere in the (possibly nested) response data.
$hasAnyKey = static function (array $resp, array $keys): bool {
    $json = json_encode($resp['data'] ?? $resp);
    foreach ($keys as $k) {
        if (str_contains((string) $json, "\"{$k}\"")) return true;
    }
    return false;
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $super = ['id' => (int) $admin['id'], 'name' => 'Fin Admin', 'role_slug' => 'super_admin'];

    // Real user id (FK-safe) + dispatcher perms (payments NONE, customers/equipment view).
    $ru = db_row("SELECT id FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$ru) { echo "SETUP FAIL no active user\n"; exit(2); }
    $disp = ['id' => (int) $ru['id'], 'name' => 'Fin Disp', 'role_slug' => 'dispatcher', 'role_id' => null,
             'permissions' => ['payments' => ['view' => 0], 'customers' => ['view' => 1], 'equipment' => ['view' => 1]],
             'permission_overrides' => [], 'role_permission_overrides' => []];

    $cust = db_row("SELECT id FROM customers WHERE deleted_at IS NULL LIMIT 1");
    $unit = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    if (!$cust || !$unit) { echo "SETUP FAIL need a customer + a unit\n"; exit(2); }
    $cid = (int) $cust['id']; $uid = (int) $unit['id'];

    echo str_repeat('─', 72) . "\n";
    echo "WAVE 5 FINANCIAL-FIELD REDACTION — customers + equipment reads\n";
    echo str_repeat('─', 72) . "\n";

    $cases = [
        ['customers/index',      'api/v1/customers/index.php',       '',                ['outstanding_balance', 'total_revenue']],
        ['customers/show',       'api/v1/customers/show.php',        "id={$cid}",       ['outstanding_balance', 'credit_limit']],
        ['customers/kpis',       'api/v1/customers/kpis.php',        '',                ['overdue_balance']],
        ['equipment/units/index','api/v1/equipment/units/index.php', '',                ['total_revenue']],
        ['equipment/units/show', 'api/v1/equipment/units/show.php',  "id={$uid}",       ['acquisition_cost', 'total_maintenance_cost']],
    ];

    foreach ($cases as [$label, $endpoint, $qs, $keys]) {
        $d = $get($endpoint, $qs, $disp);
        $s = $get($endpoint, $qs, $super);
        $dispLeaks  = $hasAnyKey($d, $keys);
        $superShows = $hasAnyKey($s, $keys);
        if (!$dispLeaks && $superShows) {
            $pass("{$label} — money keys [" . implode(',', $keys) . "] stripped for dispatcher, present for super_admin");
        } else {
            $fail("{$label} — dispatcher_has_money=" . ($dispLeaks ? 'YES(leak)' : 'no')
                . " super_has_money=" . ($superShows ? 'yes' : 'NO')
                . (isset($d['_raw']) ? " disp_raw={$d['_raw']}" : '')
                . (isset($s['_raw']) ? " super_raw={$s['_raw']}" : ''));
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // S-CN-SHOW-REDACT — credit notes: JSON endpoints + server-rendered page
    // ─────────────────────────────────────────────────────────────────────
    echo "\n" . str_repeat('─', 72) . "\n";
    echo "S-CN-SHOW-REDACT — credit notes API + app/admin/credit_notes/show.php\n";
    echo str_repeat('─', 72) . "\n";

    // Real dispatcher account (role default payments:NONE) — the smoke checks
    // can_view_financials() in the subprocess, so an override-granted dispatcher
    // is caught as a setup failure rather than a false pass.
    $dispUser = db_row("SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
                         WHERE r.slug = 'dispatcher' AND u.status = 'active' AND u.deleted_at IS NULL
                           AND NOT EXISTS (SELECT 1 FROM user_permission_overrides o
                                            WHERE o.user_id = u.id AND o.module = 'payments')
                         ORDER BY u.id LIMIT 1");
    $superUser = db_row("SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
                          WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL
                          ORDER BY u.id LIMIT 1");
    $inv = db_row("SELECT id, invoice_number FROM invoices WHERE deleted_at IS NULL AND invoice_number IS NOT NULL ORDER BY id LIMIT 1");
    if (!$dispUser || !$superUser || !$inv) { echo "SETUP FAIL need an active dispatcher, super_admin and an invoice\n"; exit(2); }
    $dispUid  = (int) $dispUser['id'];
    $superUid = (int) $superUser['id'];

    // Fixture: partially-used CAD note, two live applications. Figures are chosen
    // to be unique on the page so a string hit can only be this note's money.
    $cnTag = 'SMOKE-CNREDACT-' . $PID;
    $fixtureCnId = db_insert('credit_notes', [
        'credit_note_number' => $cnTag,
        'customer_id'        => $cid,
        'source'             => 'goodwill',
        'amount'             => '4321.87',
        'currency'           => 'CAD',
        'amount_remaining'   => '1234.56',
        'status'             => 'partially_used',
        'reason'             => $cnTag . ' fixture',
        'created_by'         => $superUid,
    ]);
    foreach (['1987.65', '1099.66'] as $amt) {
        db_insert('credit_note_applications', [
            'credit_note_id' => $fixtureCnId,
            'invoice_id'     => (int) $inv['id'],
            'amount_applied' => $amt,
            'status'         => 'applied',
            'applied_by'     => $superUid,
        ]);
    }

    // ---- JSON endpoints (synthetic dispatcher session + invoices:view) ----
    $dispCn = $disp;
    $dispCn['permissions']['invoices'] = ['view' => 1];
    $cnCases = [
        ['credit_notes/index', 'api/v1/credit_notes/index.php', 'per_page=100', ['amount', 'amount_remaining']],
        ['credit_notes/show',  'api/v1/credit_notes/show.php',  "id={$fixtureCnId}", ['amount', 'amount_remaining', 'amount_applied', 'invoice_total']],
    ];
    foreach ($cnCases as [$label, $endpoint, $qs, $keys]) {
        $d = $get($endpoint, $qs, $dispCn);
        $s = $get($endpoint, $qs, $super);
        $dispLeaks  = $hasAnyKey($d, $keys);
        $superShows = $hasAnyKey($s, $keys);
        $dispOk     = ($d['success'] ?? false) === true;
        if ($dispOk && !$dispLeaks && $superShows) {
            $pass("{$label} — money keys [" . implode(',', $keys) . "] stripped for dispatcher, present for super_admin");
        } else {
            $fail("{$label} — disp_success=" . ($dispOk ? 'yes' : 'NO') . " dispatcher_has_money=" . ($dispLeaks ? 'YES(leak)' : 'no')
                . " super_has_money=" . ($superShows ? 'yes' : 'NO'));
        }
    }
    $dk = $get('api/v1/credit_notes/kpis.php', '', $dispCn)['data'] ?? [];
    $sk = $get('api/v1/credit_notes/kpis.php', '', $super)['data'] ?? [];
    if (($dk['active_balance'] ?? null) === '0.00' && ($dk['issued_total'] ?? null) === '0.00'
        && isset($dk['active_cnt']) && (float) ($sk['active_balance'] ?? 0) >= 1234.56) {
        $pass('credit_notes/kpis — dollar totals zeroed for dispatcher (counts kept), real for super_admin');
    } else {
        $fail('credit_notes/kpis — disp=' . json_encode($dk) . ' super_active_balance=' . json_encode($sk['active_balance'] ?? null));
    }

    // ---- Server-rendered page ----
    $cnPage  = 'app/admin/credit_notes/show.php';
    $cnQs    = "id={$fixtureCnId}";
    // Every rendering of this note's money: tile/row figures, applied delta,
    // footer total, and the integer-cents constant embedded in applyForm().
    $moneyNeedles = ['4,321.87', '1,234.56', '1,987.65', '1,099.66', '3,087.31', '123456', 'CN_REMAINING_CENTS'];
    $moneyLabels  = ['Total Amount', 'Remaining Balance', 'Amount Applied', 'Total Applied', 'Remaining balance of'];
    $found = static function (string $html, array $needles): array {
        return array_values(array_filter($needles, static fn($n) => str_contains($html, $n)));
    };

    $pd = $renderPage($cnPage, $cnQs, $dispUid);
    $ps = $renderPage($cnPage, $cnQs, $superUid);
    // Dispatcher granted invoices:edit in-session only — the "editor without money
    // visibility" shape that only per-user overrides can produce.
    $pe = $renderPage($cnPage, $cnQs, $dispUid, ['invoices' => ['edit' => 1], 'payments' => ['view' => 0]]);

    if ($pd['fin'] !== '0' || $ps['fin'] !== '1' || $pe['fin'] !== '0') {
        $fail("page precondition — can_view_financials disp={$pd['fin']} super={$ps['fin']} disp+edit={$pe['fin']} (expected 0/1/0)");
    } else {
        $pass("page precondition — can_view_financials() is false for dispatcher #{$dispUid}, true for super_admin #{$superUid}");
    }

    $rendered = static fn(array $p): bool => str_contains($p['html'], $cnTag) && str_contains($p['html'], 'Application History');

    // Dispatcher: renders, no money anywhere in the page source.
    $leak = $found($pd['html'], array_merge($moneyNeedles, $moneyLabels));
    if ($rendered($pd) && !$leak) {
        $pass('show page (dispatcher) — no amount, remaining, applied figures, total, money labels or cents constant in page source');
    } else {
        $fail('show page (dispatcher) — rendered=' . ($rendered($pd) ? 'yes' : 'NO') . ' leaked=[' . implode(', ', $leak) . ']');
    }

    // Dispatcher: operational fields stay visible.
    $keep    = [$cnTag, 'Goodwill', 'CAD', (string) $inv['invoice_number'], 'invoices/show?id=' . (int) $inv['id'], 'Applied By', 'Source', 'Applications'];
    $missing = array_values(array_filter($keep, static fn($n) => !str_contains($pd['html'], $n)));
    if (!$missing && str_contains($pd['html'], 'stat-grid--2')) {
        $pass('show page (dispatcher) — number, source, currency, linked invoice, applied-by kept; tile grid resolves to --2');
    } else {
        $fail('show page (dispatcher) — missing non-money fields [' . implode(', ', $missing) . '] grid2=' . (str_contains($pd['html'], 'stat-grid--2') ? 'yes' : 'NO'));
    }

    // super_admin: every figure still rendered (the redaction didn't blanket-hide money).
    $absent = array_values(array_diff(array_merge($moneyNeedles, ['Total Amount', 'Remaining Balance', 'Amount Applied', 'Total Applied', 'x-data="applyForm()"']),
                                      $found($ps['html'], array_merge($moneyNeedles, ['Total Amount', 'Remaining Balance', 'Amount Applied', 'Total Applied', 'x-data="applyForm()"']))));
    if ($rendered($ps) && !$absent && str_contains($ps['html'], 'CN_REMAINING_CENTS = 123456') && str_contains($ps['html'], 'stat-grid--4')) {
        $pass('show page (super_admin) — all figures, Apply card, CN_REMAINING_CENTS = 123456 and --4 grid present');
    } else {
        $fail('show page (super_admin) — rendered=' . ($rendered($ps) ? 'yes' : 'NO') . ' absent=[' . implode(', ', $absent) . ']');
    }

    // Dispatcher + invoices:edit, no payments:view: Apply card withheld (every input
    // is a dollar figure), Edit Metadata offered instead, void modal names no figure.
    $leakE = $found($pe['html'], array_merge($moneyNeedles, $moneyLabels, ['x-data="applyForm()"', 'function applyForm']));
    if ($rendered($pe) && !$leakE && str_contains($pe['html'], 'Edit Metadata')
        && str_contains($pe['html'], 'Its remaining balance will be cancelled') && str_contains($pe['html'], 'Un-apply')) {
        $pass('show page (dispatcher + invoices:edit) — Apply card/script withheld, Edit Metadata + Un-apply + figure-free void modal shown');
    } else {
        $fail('show page (dispatcher + invoices:edit) — rendered=' . ($rendered($pe) ? 'yes' : 'NO') . ' leaked=[' . implode(', ', $leakE) . ']'
            . ' editMeta=' . (str_contains($pe['html'], 'Edit Metadata') ? 'yes' : 'NO')
            . ' voidText=' . (str_contains($pe['html'], 'Its remaining balance will be cancelled') ? 'yes' : 'NO'));
    }

} finally {
    if (!empty($fixtureCnId)) {
        // credit_note_applications cascades from credit_notes; delete explicitly anyway
        // so a future FK change can't strand fixture rows.
        db_execute("DELETE FROM credit_note_applications WHERE credit_note_id = ?", [$fixtureCnId]);
        db_execute("DELETE FROM credit_notes WHERE id = ? AND credit_note_number LIKE 'SMOKE-CNREDACT-%'", [$fixtureCnId]);
        db_execute("DELETE FROM audit_log WHERE entity_type = 'credit_note' AND entity_id = ?", [$fixtureCnId]);
    }
    if (file_exists($harnessFile)) @unlink($harnessFile);
    if (!empty($pageHarnessFile) && file_exists($pageHarnessFile)) @unlink($pageHarnessFile);
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("FINANCIAL-FIELD REDACTION — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
