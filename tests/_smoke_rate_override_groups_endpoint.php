<?php
declare(strict_types=1);

/**
 * tests/_smoke_rate_override_groups_endpoint.php
 *
 * S-RATES-CUSTOMER-PAGINATION — schema-real smoke for
 * api/v1/customer_equipment_rates/groups.php.
 *
 * WHY: the rates dashboard now paginates the CUSTOMER list (one row per
 * customer with an override count) instead of loading every override row
 * and grouping client-side, so it scales to thousands of customers. This
 * smoke EXECUTES the real endpoint in a subprocess with a simulated
 * super_admin session (php -l can't catch an undefined function or a SQL
 * regression in the GROUP BY / pagination), and asserts the response
 * shape, the per-customer grouping, and that the name search is wired.
 *
 * Read-only: invokes the endpoint via GET against the real DB+schema.
 * No writes. SKIPs gracefully when the DB has no overrides yet.
 *
 * Run:  php tests/_smoke_rate_override_groups_endpoint.php
 * Exit: 0 all pass (SKIPs allowed), 1 on failure.
 *
 * @session S-RATES-CUSTOMER-PAGINATION
 */

require_once __DIR__ . '/../config/app.php';

$ROOT     = rtrim(FF_ROOT, '/');
$passes   = 0;
$skips    = 0;
$failures = [];

$pass = static function (string $m) use (&$passes) { $passes++; fwrite(STDOUT, "  PASS  {$m}\n"); };
$skip = static function (string $m) use (&$skips)  { $skips++;  fwrite(STDOUT, "  SKIP  {$m}\n"); };
$fail = static function (string $m) use (&$failures) { $failures[] = $m; fwrite(STDOUT, "  FAIL  {$m}\n"); };

// ── Subprocess harness: run the endpoint with a super_admin session ─────────
$harnessFile = sys_get_temp_dir() . '/_ff_rate_groups_harness_' . getmypid() . '.php';
$harnessSrc = <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$qs       = \$argv[2] ?? '';
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['REQUEST_URI']    = '/' . \$endpoint . '?' . \$qs;
@session_start();
\$_SESSION['ff_user'] = ['id' => 1, 'role_slug' => 'super_admin'];
require '{$ROOT}/' . \$endpoint;
PHP;
file_put_contents($harnessFile, $harnessSrc);

$invoke = static function (string $endpoint, string $qs) use ($harnessFile): ?array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint) . ' ' . escapeshellarg($qs) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out) || $out === '') return null;
    $start = strpos($out, '{"success"');
    if ($start !== false) $out = substr($out, $start);
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : null;
};

$EP = 'api/v1/customer_equipment_rates/groups.php';

try {
    // ── 1. Endpoint runs and returns the paginated list shape ───────────────
    $r = $invoke($EP, 'page=1&per_page=15');
    if ($r === null) {
        $fail("1 endpoint returned no/invalid JSON (fatal in subprocess?)");
    } elseif (($r['success'] ?? null) !== true) {
        $fail("1 success!=true: " . json_encode($r['error'] ?? $r));
    } elseif (!isset($r['data']['items']) || !is_array($r['data']['items'])
              || !isset($r['data']['pagination'])) {
        $fail("1 shape — expected data.items[] + data.pagination");
    } else {
        $pass("1 endpoint executes; returns {items[], pagination}");

        $items = $r['data']['items'];

        // ── 2. Each row is one customer with the expected columns ───────────
        if ($items === []) {
            $skip("2 no customers with overrides in this DB — grouping columns unchecked");
            $skip("3 search wiring unchecked (no data)");
        } else {
            $first = $items[0];
            $haveCols = array_key_exists('customer_id', $first)
                     && array_key_exists('customer_name', $first)
                     && array_key_exists('override_count', $first);
            if ($haveCols && is_int($first['override_count']) && $first['override_count'] >= 1) {
                $pass("2 row shape — {customer_id, customer_name, override_count(int>=1)}");
            } else {
                $fail("2 row shape drift — got keys " . json_encode(array_keys($first))
                    . " override_count=" . json_encode($first['override_count'] ?? null));
            }

            // One row per customer — customer_ids must be distinct
            $ids = array_column($items, 'customer_id');
            if (count($ids) === count(array_unique($ids))) {
                $pass("2b one row per customer (distinct customer_ids)");
            } else {
                $fail("2b duplicate customer_id rows — GROUP BY regression");
            }

            // ── 3. Name search narrows the list ─────────────────────────────
            $name     = (string)$first['customer_name'];
            $needle   = substr($name, 0, max(3, (int)floor(strlen($name) / 2)));
            $rs       = $invoke($EP, 'q=' . rawurlencode($needle) . '&per_page=50');
            $matchOk  = $rs && ($rs['success'] ?? null) === true
                        && isset($rs['data']['items'])
                        && count(array_filter(
                               $rs['data']['items'],
                               static fn($it) => stripos((string)$it['customer_name'], $needle) !== false
                           )) === count($rs['data']['items'])
                        && count($rs['data']['items']) >= 1;
            if ($matchOk) {
                $pass("3 name search '{$needle}' returns only matching customers");
            } else {
                $fail("3 name search did not filter as expected for '{$needle}'");
            }

            // A guaranteed-no-match needle returns an empty list (search wired)
            $rz = $invoke($EP, 'q=zzqx_no_customer_zz&per_page=50');
            if ($rz && ($rz['success'] ?? null) === true && ($rz['data']['items'] ?? null) === []) {
                $pass("3b no-match search returns empty items[]");
            } else {
                $fail("3b no-match search did not return empty items[]");
            }
        }
    }

    // ── 4. per_page is clamped (>100 not honoured) ──────────────────────────
    $rc = $invoke($EP, 'page=1&per_page=9999');
    if ($rc && ($rc['success'] ?? null) === true) {
        $pp = $rc['data']['pagination']['per_page'] ?? null;
        if ($pp !== null && $pp <= 100) {
            $pass("4 per_page clamped to <=100 (got {$pp})");
        } else {
            $fail("4 per_page not clamped (got " . json_encode($pp) . ")");
        }
    } else {
        $fail("4 clamp check — endpoint failed on per_page=9999");
    }

} finally {
    @unlink($harnessFile);
}

fwrite(STDOUT, "\n" . ($failures
    ? count($failures) . " FAILURE(S), {$passes} passed, {$skips} skipped\n"
    : "ALL {$passes} CHECKS PASSED ({$skips} skipped)\n"));
foreach ($failures as $f) fwrite(STDOUT, "  - {$f}\n");
exit($failures ? 1 : 0);
