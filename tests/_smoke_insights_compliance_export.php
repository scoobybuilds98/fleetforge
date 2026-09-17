<?php
/**
 * tests/_smoke_insights_compliance_export.php
 *
 * S-SQL-LOCAL-DATE-FOLLOWUPS regression — two pre-existing bugs reported by the
 * S-SQL-LOCAL-DATE verifiers, each proven by EXECUTING the real script.
 *
 *   I  api/v1/admin/intelligence/insights.php selected `created_at` from
 *      report_cache, which has no such column (it is `generated_at`). Every
 *      call threw 42S22 and the endpoint 500'd.
 *        I.1 endpoint returns success
 *        I.2 latest_brief is the fixture brief, and its generated_at falls back
 *            to the ROW timestamp when the payload carries none
 *        I.3 no report_cache reader in api/ lib/ cron/ selects created_at
 *
 *   X  app/admin/compliance/index.php ?export=csv ignored `q` and `expired_only`,
 *      so exporting from a search or from the Expired tile produced more rows than
 *      the grid showed. The CSV unit set must equal the grid API's for every filter.
 *        X.1–X.4 CSV units == api/v1/compliance/index.php units (yard / +expired_only /
 *                +q / +q+expired_only)
 *        X.5 a document expiring TODAY is "Expiring Soon" in the CSV, not "Expired"
 *        X.6 the grid's JS cell colour uses the same strict rule (`date < today`)
 *            and exportCsv() forwards expired_only
 *
 * HERMETIC: each scenario runs in a CLI subprocess that opens an outer
 * transaction, inserts its fixture, captures the script output and ROLLS BACK.
 * X.7 asserts no fixture unit survived.
 *
 * USAGE: php tests/_smoke_insights_compliance_export.php
 * EXIT:  0 = all pass, 1 = any failure, 2 = setup error.
 *
 * @session S-SQL-LOCAL-DATE-FOLLOWUPS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;

/**
 * Record one assertion.
 *
 * @param string $label  what is being checked
 * @param bool   $ok     outcome
 * @param string $detail shown on failure only
 * @return void
 */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "  \033[32mPASS\033[0m — {$label}\n";
    } else {
        $failures[] = $label;
        echo "  \033[31mFAIL\033[0m — {$label}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
    }
}

// ── Harness: fixture + real script as super_admin inside a rolled-back txn ────
$harnessFile = sys_get_temp_dir() . '/_ff_insights_compliance_harness_' . getmypid() . '.php';
file_put_contents($harnessFile, <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
[$_, $root, $target, $query, $fixture] = $argv;

ob_start();
require $root . '/config/app.php';
require_once FF_ROOT . '/includes/auth.php';

$pdo = db_pdo();
// Outer transaction FIRST so nothing the fixture or the script writes survives.
$pdo->beginTransaction();

$user = db_row(
    "SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id
      WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1"
);
$tag   = 'SMOKE-INSCMP-' . getmypid();
$state = ['tag' => $tag];

if ($fixture === 'brief') {
    // Payload deliberately has NO generated_at → forces the row-timestamp fallback.
    $state['cache_id'] = db_insert('report_cache', [
        'report_type' => 'ai_fleet_brief', 'parameters_hash' => $tag, 'parameters' => '{}',
        'result_data' => json_encode(['brief' => $tag]),
        'generated_at' => '2026-01-02 03:04:05', 'expires_at' => '2099-01-01 00:00:00',
    ]);
} elseif ($fixture === 'units') {
    $tpl = db_row('SELECT id FROM equipment_templates WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
    $d   = new DateTimeImmutable(date('Y-m-d'));            // company-local today, as the page uses
    $mk  = static function (string $num, string $cvi) use ($tpl, $user, $tag): void {
        db_insert('equipment_units', [
            'template_id' => (int) $tpl['id'], 'unit_number' => $tag . '-' . $num,
            'tracking_provider' => 'none', 'ownership_type' => 'owned', 'status' => 'available',
            'yard_location' => $tag . '-YARD', 'cvi_expiry' => $cvi, 'registration_expiry' => null,
            'created_by' => $user['id'], 'updated_by' => $user['id'],
        ]);
    };
    $mk('QX-EXPIRED', $d->modify('-1 day')->format('Y-m-d'));
    $mk('DUE-TODAY', $d->format('Y-m-d'));
    $mk('QX-VALID', $d->modify('+100 days')->format('Y-m-d'));
}

register_shutdown_function(static function () use ($pdo, &$state) {
    $state['output'] = (string) ob_get_clean();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();                                        // hermetic
    }
    echo '@@STATE@@' . json_encode($state);
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
parse_str(str_replace('{yard}', $tag . '-YARD', $query), $_GET);
auth_login($user);
require $root . '/' . $target;
PHP);

/**
 * Run one script through the harness.
 *
 * @param string $target  repo-relative script path
 * @param string $query   query string ({yard} → the fixture yard)
 * @param string $fixture 'brief' | 'units' | 'none'
 * @return array decoded harness state ('output' = captured body)
 */
function run_scenario(string $target, string $query, string $fixture): array
{
    global $harnessFile, $ROOT;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' '
        . escapeshellarg($ROOT) . ' ' . escapeshellarg($target) . ' '
        . escapeshellarg($query) . ' ' . escapeshellarg($fixture) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@STATE@@');
    if ($pos === false) {
        fwrite(STDERR, "harness produced no state for {$target}?{$query}:\n{$out}\n");
        exit(2);
    }
    return json_decode(substr($out, $pos + 9), true) ?: [];
}

echo "S-SQL-LOCAL-DATE-FOLLOWUPS — insights endpoint + compliance CSV/grid parity\n";
echo str_repeat('=', 78) . "\n\n";

try {
    // ── I: insights endpoint ─────────────────────────────────────────────────
    $ins  = run_scenario('api/v1/admin/intelligence/insights.php', '', 'brief');
    $body = json_decode((string) ($ins['output'] ?? ''), true);
    check('I.1 insights endpoint returns success (was 42S22 on report_cache.created_at)',
        ($body['success'] ?? false) === true, substr((string) ($ins['output'] ?? ''), 0, 300));
    $lb = $body['data']['latest_brief'] ?? [];
    check('I.2 latest_brief = fixture, generated_at falls back to the row timestamp',
        (int) ($lb['cache_id'] ?? 0) === (int) ($ins['cache_id'] ?? -1)
            && ($lb['text'] ?? '') === $ins['tag']
            && ($lb['generated_at'] ?? '') === '2026-01-02 03:04:05',
        'latest_brief=' . json_encode($lb));

    // Static sweep: a SELECT on report_cache must never name created_at unaliased.
    $offenders = [];
    foreach (['api', 'lib', 'cron', 'includes', 'app'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $src = (string) file_get_contents($f->getPathname());
            // Each SQL string literal that reads report_cache.
            if (preg_match_all('/"(?:[^"\\\\]|\\\\.)*report_cache(?:[^"\\\\]|\\\\.)*"/s', $src, $m)) {
                foreach ($m[0] as $sql) {
                    $noAlias = preg_replace('/generated_at\s+AS\s+created_at/i', '', $sql);
                    if (preg_match('/\bSELECT\b/i', $sql) && preg_match('/\bcreated_at\b/i', $noAlias)) {
                        $offenders[] = substr($f->getPathname(), strlen($ROOT) + 1);
                    }
                }
            }
        }
    }
    check('I.3 no report_cache SELECT names the nonexistent created_at column',
        $offenders === [], 'offenders=' . json_encode(array_values(array_unique($offenders))));

    // ── X: compliance CSV export vs grid API ─────────────────────────────────
    $strip = static fn(string $tag, string $n): string => str_replace($tag . '-', '', $n);
    $n = 1;
    $todayStatus = null;
    foreach (['yard={yard}', 'yard={yard}&expired_only=1', 'yard={yard}&q=QX', 'yard={yard}&q=QX&expired_only=1'] as $q) {
        $api   = run_scenario('api/v1/compliance/index.php', $q . '&per_page=100', 'units');
        $items = json_decode((string) ($api['output'] ?? ''), true)['data']['items'] ?? null;
        $a     = is_array($items) ? array_map(static fn($r) => $strip($api['tag'], $r['unit_number']), $items) : ['__error__'];
        sort($a);

        $csv   = run_scenario('app/admin/compliance/index.php', $q . '&export=csv', 'units');
        $lines = array_values(array_filter(explode("\n", trim((string) ($csv['output'] ?? '')))));
        $head  = str_getcsv((string) array_shift($lines), ',', '"', '\\');
        $c = [];
        foreach ($lines as $line) {
            $r   = str_getcsv($line, ',', '"', '\\');
            $num = $strip($csv['tag'], (string) $r[0]);
            $c[] = $num;
            if ($num === 'DUE-TODAY') $todayStatus = $r[6] ?? null;
        }
        sort($c);
        check("X.{$n} CSV rows == grid rows for {$q}",
            $a === $c && ($head[0] ?? '') === 'Unit Number',
            'grid=' . json_encode($a) . ' csv=' . json_encode($c));
        $n++;
    }
    check('X.5 a CVI expiring TODAY is "Expiring Soon" in the CSV (expired = before today)',
        $todayStatus === 'Expiring Soon', 'status=' . json_encode($todayStatus));

    $page = (string) file_get_contents($ROOT . '/app/admin/compliance/index.php');
    check('X.6 grid cell colour uses the strict rule and exportCsv() forwards expired_only',
        (bool) preg_match('/if \(date < today\)\s+return \'expired\'/', $page)
            && !preg_match('/date <= today/', $page)
            && (bool) preg_match('/exportCsv\(\)\s*\{.*?params\.set\(\'expired_only\', \'1\'\).*?window\.location\.href/s', $page),
        'expiryStatus()/exportCsv() in app/admin/compliance/index.php drifted');

    $leaked = db_count("SELECT COUNT(*) FROM equipment_units WHERE unit_number LIKE 'SMOKE-INSCMP-%'")
            + db_count("SELECT COUNT(*) FROM report_cache WHERE parameters_hash LIKE 'SMOKE-INSCMP-%'");
    check('X.7 hermetic — no fixture rows survived', $leaked === 0, "leaked={$leaked}");
} catch (\Throwable $e) {
    echo "\nEXCEPTION: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $failures[] = 'exception';
} finally {
    @unlink($harnessFile);
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%s — %d passed, %d failed\n", $failures ? 'FAILURES' : 'ALL PASS', $passes, count($failures));
exit($failures ? 1 : 0);
