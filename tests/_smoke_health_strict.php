<?php
declare(strict_types=1);

/**
 * tests/_smoke_health_strict.php
 *
 * S-PERF-3 (batch B) — api/v1/health.php strict mode + anonymous session skip.
 *
 * The Sentry uptime monitor used to poll the login page, which renders HTTP
 * 200 even with MySQL down, so a DB outage never alerted. health?strict=1 now
 * answers 503 whenever status !== "ok"; the DEFAULT stays 200 in every state
 * because bin/deploy.sh's health gate (curl -f + JSON parse) depends on it.
 *
 * Executes the REAL endpoint under a real non-CLI SAPI (`php -S`), twice:
 *   Server 1 — healthy DB (the local dev DB, read-only use):
 *     - plain → 200; strict → 200 when status ok, 503 otherwise (consistent)
 *     - plain and strict bodies are identical apart from `time`
 *     - anonymous (no cookies) → NO Set-Cookie (no session file minted)
 *     - authenticated (real auth_login() session) → version/disk/schema.missing
 *       still present, strict status code identical to plain when ok
 *     - only EXACTLY strict=1 is strict (strict=0 / strict=true stay 200-only)
 *   Server 2 — same code with DB_PORT=1 (connection refused):
 *     - plain → 200 {status: degraded, db: false, migrations.pending: null}
 *     - strict → 503 with the same body
 *     - migrations check is skipped when the DB is down (one connect-retry
 *       cycle, not two): asserted as "strict DB-down answer < 2.5 s"
 *
 * Writes nothing to the DB. Sessions live in a private temp save path.
 *
 * Run:  php tests/_smoke_health_strict.php   Exit 0 = pass, 1 = fail, 2 = setup.
 *
 * @session S-PERF-3
 */

require_once dirname(__DIR__) . '/config/app.php';

// Output via fwrite(STDOUT), never echo: the session mint below must happen
// before PHP considers headers sent (true after any echo, even in CLI).

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass  = static function (string $m) use (&$passes): void { $passes++; fwrite(STDOUT, "  \033[32mPASS\033[0m — {$m}\n"); };
$fail  = static function (string $m) use (&$failures): void { $failures[] = $m; fwrite(STDOUT, "  \033[31mFAIL\033[0m — {$m}\n"); };
$check = static function (bool $c, string $m) use ($pass, $fail): void { $c ? $pass($m) : $fail($m); };

$tmp     = sys_get_temp_dir() . '/_ff_health_strict_' . getmypid();
$sessDir = $tmp . '/sess';
@mkdir($sessDir, 0700, true);

// Mint a real authenticated session in the private save path (reads only).
ini_set('session.save_path', $sessDir);
require_once $ROOT . '/includes/auth.php';
$user = db_row(
    "SELECT u.id, u.name, u.email, u.role_id, r.slug AS role_slug, u.theme_preference, u.status,
            u.display_font_size, u.display_density
       FROM users u JOIN user_roles r ON r.id = u.role_id
      WHERE u.status = 'active' AND u.deleted_at IS NULL
      ORDER BY (u.id = 55) DESC, u.id LIMIT 1"
);
if (!$user) { fwrite(STDOUT, "SETUP: no active user to mint a session for\n"); exit(2); }
@auth_login($user, false);
$SID = session_id();
session_write_close();

/** Start `php -S` on a free port with extra env; returns [proc, port]. */
$startServer = static function (array $extraEnv, string $log) use ($ROOT, $sessDir): array {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $env  = array_merge(getenv(), $extraEnv);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $sessDir, '-S', "127.0.0.1:{$port}", '-t', $ROOT],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes, $ROOT, $env
    );
    for ($i = 0; $i < 50; $i++) {
        usleep(100_000);
        if (@fsockopen('127.0.0.1', $port) !== false) return [$proc, $port];
    }
    return [$proc, 0];
};

/** GET with optional cookie; returns [status, headers(lowercased, multi), body, seconds]. */
$get = static function (int $port, string $path, string $cookie = ''): array {
    $t  = microtime(true);
    $fp = @fsockopen('127.0.0.1', $port, $en, $es, 10);
    if (!$fp) return [0, [], '', 0.0];
    fwrite($fp, "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\n" . ($cookie !== '' ? "Cookie: {$cookie}\r\n" : '') . "\r\n");
    stream_set_timeout($fp, 30);
    $raw = (string) stream_get_contents($fp);
    fclose($fp);
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    preg_match('~^HTTP/\S+\s+(\d+)~', $lines[0] ?? '', $m);
    $h = [];
    foreach (array_slice($lines, 1) as $l) {
        if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $h[strtolower(trim($k))][] = trim($v); }
    }
    return [(int) ($m[1] ?? 0), $h, $body, microtime(true) - $t];
};
$noTime = static fn (string $b): string => (string) preg_replace('~"time":"[^"]*"~', '"time":"-"', $b);

$procs = [];
try {
    // ── Server 1: healthy DB ────────────────────────────────────────────
    fwrite(STDOUT, "\n── Healthy DB ──────────────────────────────────────────────────\n");
    [$procs[], $port] = $startServer([], $tmp . '/srv1.log');
    if (!$port) { fwrite(STDOUT, "SETUP: php -S (healthy) did not start\n"); exit(2); }
    $H = '/api/v1/health.php';

    [$c1, $h1, $b1] = $get($port, $H);
    [$c2, $h2, $b2] = $get($port, $H . '?strict=1');
    $j1 = json_decode($b1, true);
    $status = $j1['data']['status'] ?? null;
    $check($c1 === 200 && ($j1['success'] ?? false) === true, "plain health → 200 success (status={$status})");
    $check($c2 === ($status === 'ok' ? 200 : 503), "strict health → {$c2}, consistent with status={$status}");
    $check($noTime($b1) === $noTime($b2), 'plain and strict bodies identical apart from `time`');
    $check(!isset($h1['set-cookie']) && !isset($h2['set-cookie']), 'anonymous health sets NO cookie (no session file minted)');
    $check(!isset($j1['data']['version']) && !isset($j1['data']['disk']), 'anonymous payload stays public-only (no version / disk)');

    foreach (['?strict=0', '?strict=true', '?strict[]=1'] as $q) {
        [$cq] = $get($port, $H . $q);
        $check($cq === 200, "only strict=1 is strict: {$q} → {$cq} (non-strict is always 200)");
    }

    [$c3, $h3, $b3] = $get($port, $H . '?strict=1', "ff_session={$SID}");
    $j3 = json_decode($b3, true);
    $check($c3 === $c2, "authenticated strict → {$c3} (same code as anonymous)");
    $check(isset($j3['data']['version'], $j3['data']['disk']) && array_key_exists('missing', $j3['data']['schema'] ?? []),
        'authenticated caller (ff_session cookie) still gets version / disk / schema.missing');

    // ── Server 2: DB down (connection refused) ──────────────────────────
    fwrite(STDOUT, "\n── DB down (DB_PORT=1) ─────────────────────────────────────────\n");
    [$procs[], $port2] = $startServer(['DB_PORT' => '1'], $tmp . '/srv2.log');
    if (!$port2) { fwrite(STDOUT, "SETUP: php -S (db-down) did not start\n"); exit(2); }

    [$d1, $dh1, $db1, $dt1] = $get($port2, $H);
    [$d2, , $db2, $dt2]     = $get($port2, $H . '?strict=1');
    $dj = json_decode($db1, true);
    $check($d1 === 200, "DB down, plain → 200 (deploy-gate/LB semantics unchanged) — got {$d1}");
    $check(($dj['data']['status'] ?? '') === 'degraded' && ($dj['data']['db'] ?? null) === false
        && array_key_exists('pending', $dj['data']['migrations'] ?? []) && $dj['data']['migrations']['pending'] === null
        && ($dj['data']['migrations']['ok'] ?? null) === false && ($dj['data']['schema']['ok'] ?? null) === false,
        'DB down body: degraded, db:false, migrations {pending:null, ok:false}, schema.ok:false');
    $check($d2 === 503, "DB down, strict → 503 (uptime monitor sees the outage) — got {$d2}");
    $check($noTime($db1) === $noTime($db2), 'DB down: strict body identical to plain apart from `time`');
    $check(!isset($dh1['set-cookie']), 'DB down: anonymous health still sets no cookie');
    $check($dt2 < 2.5, sprintf('DB down answer in %.2f s (< 2.5 s: migrations check skipped, one connect-retry cycle)', $dt2));
    fwrite(STDOUT, sprintf("        (plain %.2f s, strict %.2f s)\n", $dt1, $dt2));
} finally {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    foreach (glob($sessDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($sessDir);
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
}

fwrite(STDOUT, "\n----------------------------------------------------------------------\n");
fwrite(STDOUT, 'TOTAL: ' . $passes . ' pass / ' . count($failures) . " fail\n");
fwrite(STDOUT, "----------------------------------------------------------------------\n");
exit($failures === [] ? 0 : 1);
