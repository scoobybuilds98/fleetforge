<?php declare(strict_types=1);

/**
 * tests/_smoke_auth_failopen.php
 *
 * S-AUTH-FAILOPEN-1 behavioral test — a missing/lagging permission-override
 * table must DEGRADE to factory defaults, never fatal auth.
 *
 * Context: on 2026-06-05 prod 500'd site-wide because _ff_load_role_overrides()
 * queried a missing `role_permission_overrides` table on every session start and
 * the PDOException escaped uncaught. D-FAILOPEN-1 wraps the two override loaders
 * (_ff_load_role_overrides + _ff_load_user_overrides) in try/catch → log + Sentry
 * → return []. Permission resolution then falls back to config/permissions.php.
 *
 * Checks:
 *   SC2a HEALTHY role override still loads (no behavior change) — hermetic
 *        INSERT inside a transaction that is ALWAYS rolled back (non-destructive).
 *   SC2b HEALTHY user override still loads — same transactional pattern.
 *   SC2c HEALTHY can() still enforces an override (DENY wins over factory grant).
 *   SC3a DEGRADED: with the override tables absent (an EMPTY scratch DB, pointed
 *        at via a subprocess + DB_DATABASE env — NON-DESTRUCTIVE to the dev DB),
 *        BOTH loaders return [] and the subprocess EXITS 0 (a PHP fatal would be
 *        the CLI equivalent of the 500 — proving the crash is gone).
 *   SC3b DEGRADED resolution: with overrides=[], can() returns the config factory
 *        default (and a would-be DENY override is NOT enforced — the documented,
 *        operator-approved availability-favoring tradeoff).
 *   SC4  super_admin remains always-true in the degraded path.
 *   SC5  the fail-open path is LOUD — the subprocess stderr carries the
 *        "[auth] FAIL-OPEN ..." error_log lines; Sentry::captureException is a
 *        callable no-op in dev (blank DSN).
 *
 * Hermetic + non-destructive: dev DB is only ever written inside a rolled-back
 * transaction; the degraded case uses a throwaway scratch DB created + dropped
 * here (try/finally guarantees the drop).
 *
 * Final summary line is grep-able for CI:
 *   "AUTH-FAILOPEN OK — N/N checks pass"  /  "AUTH-FAILOPEN FAIL — N failure(s)"
 *
 * Exit code: 0 all pass, 1 any failure.
 *
 * @session S-AUTH-FAILOPEN-1
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$failures = [];
$checks   = 0;

function rec(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    echo ($ok ? "  ✓ " : "  ✗ ") . $name . ($detail !== '' ? " — {$detail}" : "") . "\n";
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? " — {$detail}" : "");
    }
}

echo "S-AUTH-FAILOPEN-1 — override-loader fail-open (D-FAILOPEN-1/2/3)\n\n";

$cfg      = require FF_ROOT . '/config/permissions.php';
$roleId   = 2;            // manager (verified non-super_admin)
$roleSlug = 'manager';
$userId   = 1;

// ── SC2a HEALTHY role override loads (transactional, rolled back) ──
$pdo = db_pdo();
$pdo->beginTransaction();
try {
    db_insert('role_permission_overrides', [
        'role_id' => $roleId, 'module' => '__failopen_probe__',
        'action'  => 'view', 'granted' => 1, 'updated_by' => $userId,
    ]);
    $map = _ff_load_role_overrides($roleId);
    rec('SC2a healthy role override loads from DB',
        isset($map['__failopen_probe__']['view']) && $map['__failopen_probe__']['view'] === 1,
        'map=' . json_encode($map['__failopen_probe__'] ?? null));
} finally {
    $pdo->rollBack();   // never persist the probe row
}

// ── SC2b HEALTHY user override loads (transactional, rolled back) ──
$pdo->beginTransaction();
try {
    db_insert('user_permission_overrides', [
        'user_id' => $userId, 'module' => '__failopen_probe__',
        'action'  => 'edit', 'granted' => 0, 'granted_by' => $userId,
    ]);
    $map = _ff_load_user_overrides($userId);
    rec('SC2b healthy user override loads from DB',
        isset($map['__failopen_probe__']['edit']) && $map['__failopen_probe__']['edit'] === 0,
        'map=' . json_encode($map['__failopen_probe__'] ?? null));
} finally {
    $pdo->rollBack();
}

// ── SC2c HEALTHY can() enforces a DENY override over a factory grant ──
$_SESSION['ff_user'] = [
    'id' => $userId, 'role_slug' => $roleSlug,
    'permissions' => $cfg[$roleSlug],
    'permission_overrides' => [],
    'role_permission_overrides' => ['customers' => ['view' => 0]],  // DENY
];
rec('SC2c healthy can() honors DENY override (false) over factory grant',
    can('customers', 'view') === false,
    'factory default was ' . var_export($cfg[$roleSlug]['customers']['view'] ?? null, true));

// ── SC3a DEGRADED: loaders return [] against an EMPTY scratch DB (no fatal) ──
$scratch = 'fleetforge_failopen_probe';
db_execute("DROP DATABASE IF EXISTS `{$scratch}`");
db_execute("CREATE DATABASE `{$scratch}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $runner =
        'require ' . var_export(FF_ROOT . '/config/app.php', true) . '; '
      . 'require_once ' . var_export(FF_ROOT . '/includes/auth.php', true) . '; '
      . '$r = _ff_load_role_overrides(2); $u = _ff_load_user_overrides(1); '
      . 'fwrite(STDOUT, json_encode(["r_empty"=>($r===[]),"u_empty"=>($u===[]),'
      . '"r_type"=>gettype($r),"u_type"=>gettype($u)]));';

    $childEnv = getenv();
    $childEnv['DB_DATABASE'] = $scratch;   // process env wins over .env default

    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $runner],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, FF_ROOT, $childEnv
    );
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    $decoded = json_decode($stdout ?: 'null', true);

    rec('SC3a degraded subprocess exits 0 (no fatal = no 500)',
        $exit === 0, "exit={$exit}; stderr=" . trim(substr($stderr, 0, 200)));
    rec('SC3a degraded _ff_load_role_overrides returns [] (fail-open)',
        is_array($decoded) && ($decoded['r_empty'] ?? false) === true && ($decoded['r_type'] ?? '') === 'array');
    rec('SC3a degraded _ff_load_user_overrides returns [] (fail-open)',
        is_array($decoded) && ($decoded['u_empty'] ?? false) === true && ($decoded['u_type'] ?? '') === 'array');

    // ── SC5 LOUD: error_log fired for BOTH loaders in the degraded subprocess ──
    rec('SC5 fail-open is LOUD — error_log fired for role loader',
        strpos($stderr, '[auth] FAIL-OPEN _ff_load_role_overrides') !== false);
    rec('SC5 fail-open is LOUD — error_log fired for user loader',
        strpos($stderr, '[auth] FAIL-OPEN _ff_load_user_overrides') !== false);
} finally {
    db_execute("DROP DATABASE IF EXISTS `{$scratch}`");   // guaranteed cleanup
}

// ── SC3b DEGRADED resolution: empty overrides → config factory default ──
$_SESSION['ff_user'] = [
    'id' => $userId, 'role_slug' => $roleSlug,
    'permissions' => $cfg[$roleSlug],
    'permission_overrides' => [],            // degraded: loader returned []
    'role_permission_overrides' => [],       // degraded: loader returned []
];
$factory = (bool) ($cfg[$roleSlug]['customers']['view'] ?? false);
rec('SC3b degraded can() falls back to factory default (DENY not enforced)',
    can('customers', 'view') === $factory && $factory === true,
    'factory=' . var_export($factory, true));

// ── SC4 super_admin always-true in the degraded path ──
$_SESSION['ff_user'] = [
    'id' => 999, 'role_slug' => 'super_admin',
    'permissions' => [], 'permission_overrides' => [], 'role_permission_overrides' => [],
];
rec('SC4 super_admin remains always-true with empty everything',
    can('whatever_module', 'delete') === true);

// ── SC5b Sentry::captureException is a callable no-op in dev (blank DSN) ──
$sentryOk = true;
try {
    \FleetForge\Observability\Sentry::captureException(new \RuntimeException('failopen-probe'));
} catch (\Throwable $e) {
    $sentryOk = false;
}
rec('SC5b Sentry::captureException callable (no-op, blank DSN) — no throw', $sentryOk);

// ── Surface ──
echo "\n";
$pass = $checks - count($failures);
if (empty($failures)) {
    echo "AUTH-FAILOPEN OK — {$pass}/{$checks} checks pass\n";
    exit(0);
}
echo "AUTH-FAILOPEN FAIL — " . count($failures) . " failure(s):\n";
foreach ($failures as $f) {
    echo "  ✗ {$f}\n";
}
exit(1);
