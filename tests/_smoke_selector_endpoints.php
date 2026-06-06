<?php
declare(strict_types=1);

/**
 * tests/_smoke_selector_endpoints.php
 *
 * S-FIX-SELECTORS-EMPTY — regression smoke for the "empty selectors" class.
 *
 * WHY THIS EXISTS — two distinct failure classes, both of which shipped
 * silently and rendered create/edit-form entity selectors empty on prod:
 *
 *   CLASS 1 (the bug this session fixed) — FRONT-END URL double-prefix.
 *     17 FF_RecordPicker configs passed `base_url('api/v1/…')` (a fully
 *     qualified URL) as `endpoint`. FF_RecordPicker wraps every endpoint in
 *     `FF_Api.url()`, which prepends `window.FF_BASE_PATH` AGAIN, producing
 *     `/fleetforgehttps://host/fleetforge/api/v1/…` → 404 (HTML) →
 *     `res.json()` throws → the picker's try/catch swallows it into an empty
 *     result set. The backend was 100% healthy; nothing server-side could
 *     have caught it. So PART A is a STATIC guard: no picker `endpoint` may
 *     use base_url(), and FF_Api.url() must keep its absolute-URL guard.
 *
 *   CLASS 2 (the SEARCH-1 / S017-UX shape-drift trap) — the global search
 *     endpoint returns GROUPED results ({results:{customers:[],…}}); a
 *     consumer parsing the old flat results[] renders nothing. PART B
 *     invokes the real endpoints and asserts the response SHAPE (grouped
 *     object for search.php; {items:[],pagination:{}} for list endpoints),
 *     plus NON-EMPTY for entities that actually have rows — so a future
 *     shape drift or a column/SQL regression fails the smoke instead of
 *     shipping a silently-empty selector.
 *
 * Read-only: PART B invokes endpoints via GET against the real DB+schema
 * through a CLI-guarded harness that simulates a super_admin session
 * (can() short-circuits, so endpoint output == its SQL). No writes.
 *
 * Run: php tests/_smoke_selector_endpoints.php
 * Exit: 0 all pass (SKIPs allowed where the DB has no rows), 1 on failure.
 *
 * @session S-FIX-SELECTORS-EMPTY
 * @decision D-PICKER-ENDPOINT-ROOT-RELATIVE (FF_RecordPicker endpoints are
 *           root-relative '/api/v1/…'; FF_Api.url() owns the base-path prefix
 *           and is idempotent against already-absolute URLs)
 */

require_once __DIR__ . '/../config/app.php';

$failures = [];
$passes   = 0;
$skips    = 0;

$pass = static function (string $msg) use (&$passes): void {
    $passes++;
    echo "  PASS — {$msg}\n";
};
$fail = static function (string $msg) use (&$failures): void {
    $failures[] = $msg;
    echo "  FAIL — {$msg}\n";
};
$skip = static function (string $msg) use (&$skips): void {
    $skips++;
    echo "  SKIP — {$msg}\n";
};

$ROOT = dirname(__DIR__);

// ════════════════════════════════════════════════════════════════════════
// PART A — FRONT-END static guards (catch CLASS 1: the actual shipped bug)
// ════════════════════════════════════════════════════════════════════════
echo "PART A — front-end picker-endpoint URL guards\n";

// A1: No FF_RecordPicker config may pass base_url() as `endpoint`. The picker
//     wraps endpoint in FF_Api.url(); base_url() would double-prefix → 404.
$adminDir = $ROOT . '/app';
$offenders = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($adminDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $src = file_get_contents($file->getPathname());
    if ($src === false) {
        continue;
    }
    // Match:  'endpoint' => base_url( ... )   (any whitespace)
    if (preg_match_all("/'endpoint'\\s*=>\\s*base_url\\s*\\(/", $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $offenders[] = str_replace($ROOT . '/', '', $file->getPathname()) . ':' . $line;
        }
    }
}
if ($offenders === []) {
    $pass("A1 no picker 'endpoint' => base_url() — all picker endpoints root-relative");
} else {
    $fail("A1 picker 'endpoint' => base_url() found (double-prefix → 404 → empty selector): "
        . implode(', ', $offenders));
}

// A2: FF_Api.url() must keep the idempotency guard so an absolute URL passed by
//     mistake is returned untouched instead of being re-prefixed.
$appJs = file_get_contents($ROOT . '/public/assets/js/app.js');
if ($appJs === false) {
    $fail("A2 cannot read public/assets/js/app.js");
} else {
    // Isolate the url(path) method body.
    if (preg_match('/url\s*\(\s*path\s*\)\s*\{(.*?)\}/s', $appJs, $um)
        && preg_match('#https\?:\\\\?/\\\\?/#', $um[1])) {
        $pass("A2 FF_Api.url() guards already-absolute (http/https) URLs");
    } else {
        $fail("A2 FF_Api.url() missing absolute-URL guard — a base_url() slip would 404 again");
    }
}

// ════════════════════════════════════════════════════════════════════════
// PART B — BACKEND shape + non-empty (catch CLASS 2: shape/SQL drift)
// ════════════════════════════════════════════════════════════════════════
echo "PART B — selector endpoint shape + non-empty\n";

// Write a CLI-only harness that invokes an endpoint with a simulated
// super_admin session and prints its raw JSON to stdout. The endpoint's
// _ff_session_start() is static-guarded + checks session_status(), so
// pre-starting the session here keeps the injected super_admin user alive.
$harnessFile = sys_get_temp_dir() . '/_ff_selector_smoke_harness_' . getmypid() . '.php';
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

/** Invoke an endpoint via the harness subprocess; return decoded JSON or null. */
$invoke = static function (string $endpoint, string $qs) use ($harnessFile): ?array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint) . ' ' . escapeshellarg($qs) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out) || $out === '') {
        return null;
    }
    // Dev boxes run display_errors=On, so pre-starting the session before
    // config/app.php's session ini_set() emits warnings ahead of the body.
    // The JSON envelope is the final thing echoed (one line, then exit), so
    // decode from the first `{"success"` to the end — robust on prod (clean
    // output, match at offset 0) and dev (skips the warning preamble).
    $start = strpos($out, '{"success"');
    if ($start !== false) {
        $out = substr($out, $start);
    }
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : null;
};

try {
    // ── B1: search.php returns the GROUPED shape (SEARCH-1) ──────────────
    // Use a query that matches nothing so the shape is asserted independent
    // of data: success=true, data.results is an associative object carrying
    // all five group keys (NOT a flat array — the S017-UX drift trap).
    $r = $invoke('api/v1/search.php', 'q=zzqx_nomatch_zz&limit=3');
    if ($r === null) {
        $fail("B1 search.php returned no/invalid JSON");
    } elseif (($r['success'] ?? null) !== true) {
        $fail("B1 search.php success!=true: " . json_encode($r['error'] ?? $r));
    } else {
        $res = $r['data']['results'] ?? null;
        $isList = is_array($res) && ($res === [] || array_keys($res) === range(0, count($res) - 1));
        $needKeys = ['customers', 'equipment', 'leases', 'invoices', 'reservations'];
        $hasKeys = is_array($res) && count(array_intersect($needKeys, array_keys($res))) === count($needKeys);
        if (is_array($res) && !$isList && $hasKeys) {
            $pass("B1 search.php grouped shape — data.results is keyed {customers,equipment,leases,invoices,reservations}");
        } else {
            $fail("B1 search.php shape drift — data.results is not the grouped object (got: "
                . json_encode(is_array($res) ? array_keys($res) : $res) . ")");
        }
    }

    // ── B2: customers list endpoint shape ───────────────────────────────
    $r = $invoke('api/v1/customers/index.php', 'search=&per_page=5');
    if ($r === null || ($r['success'] ?? null) !== true) {
        $fail("B2 customers list success!=true: " . json_encode($r['error'] ?? $r));
    } elseif (!isset($r['data']['items']) || !is_array($r['data']['items']) || !isset($r['data']['pagination'])) {
        $fail("B2 customers list shape — expected data.items[] + data.pagination");
    } else {
        $pass("B2 customers list shape — data.items[] + data.pagination present");
    }

    // ── B3: equipment-units list endpoint shape ─────────────────────────
    $r = $invoke('api/v1/equipment/units/index.php', 'search=&per_page=5&status=available');
    if ($r === null || ($r['success'] ?? null) !== true) {
        $fail("B3 equipment-units list success!=true: " . json_encode($r['error'] ?? $r));
    } elseif (!isset($r['data']['items']) || !is_array($r['data']['items']) || !isset($r['data']['pagination'])) {
        $fail("B3 equipment-units list shape — expected data.items[] + data.pagination");
    } else {
        $pass("B3 equipment-units list shape — data.items[] + data.pagination present");
    }

    // ── B4: NON-EMPTY when data exists — customers list ──────────────────
    $custCount = db_count("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL");
    if ($custCount === 0) {
        $skip("B4 customers list non-empty — 0 customers in DB (post-reset)");
    } else {
        $r = $invoke('api/v1/customers/index.php', 'search=&per_page=5');
        $n = count($r['data']['items'] ?? []);
        if ($n > 0) {
            $pass("B4 customers list non-empty — {$n} item(s) for {$custCount} customer(s)");
        } else {
            $fail("B4 customers list EMPTY despite {$custCount} customer(s) — filter/SQL regression");
        }
    }

    // ── B5: NON-EMPTY when data exists — available units ─────────────────
    $unitCount = db_count("SELECT COUNT(*) FROM equipment_units WHERE deleted_at IS NULL AND status='available'");
    if ($unitCount === 0) {
        $skip("B5 available-units list non-empty — 0 available units in DB");
    } else {
        $r = $invoke('api/v1/equipment/units/index.php', 'search=&per_page=5&status=available');
        $n = count($r['data']['items'] ?? []);
        if ($n > 0) {
            $pass("B5 available-units list non-empty — {$n} item(s) for {$unitCount} available unit(s)");
        } else {
            $fail("B5 available-units list EMPTY despite {$unitCount} available unit(s) — filter/SQL regression");
        }
    }

    // ── B6: search.php NON-EMPTY for a real customer (round-trips SQL) ────
    if ($custCount === 0) {
        $skip("B6 search.php non-empty — no customers to search for");
    } else {
        $cust = db_row("SELECT id, company_name FROM customers WHERE deleted_at IS NULL AND company_name <> '' ORDER BY id LIMIT 1");
        $term = $cust ? substr((string) $cust['company_name'], 0, 3) : '';
        if ($cust === null || strlen(trim($term)) < 2) {
            $skip("B6 search.php non-empty — no usable customer name to derive a >=2-char term");
        } else {
            $r = $invoke('api/v1/search.php', 'q=' . rawurlencode($term) . '&limit=10');
            $rows = $r['data']['results']['customers'] ?? [];
            $ids  = array_map(static fn($x) => (int) ($x['id'] ?? 0), is_array($rows) ? $rows : []);
            if (in_array((int) $cust['id'], $ids, true)) {
                $pass("B6 search.php non-empty — customer #{$cust['id']} found for q='{$term}'");
            } else {
                $fail("B6 search.php returned no/expected customer for q='{$term}' (got ids: "
                    . implode(',', $ids) . ") — shape or column regression");
            }
        }
    }
} finally {
    @unlink($harnessFile);
}

// ════════════════════════════════════════════════════════════════════════
echo "\n";
echo "selector-endpoints smoke: {$passes} passed, " . count($failures) . " failed, {$skips} skipped\n";
if ($failures !== []) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "OK\n";
exit(0);
