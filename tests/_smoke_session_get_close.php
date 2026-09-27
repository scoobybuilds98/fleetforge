<?php
declare(strict_types=1);

/**
 * tests/_smoke_session_get_close.php
 *
 * S-PERF-3 (batch A) — GET/HEAD API requests release the PHP session lock.
 *
 * require_auth_api() now calls session_write_close() for GET/HEAD requests,
 * right after the live status + permission-freshness re-check. That lets a
 * page's parallel XHRs actually run in parallel (PHP's `files` handler holds
 * an exclusive flock on the session file until the script exits, so they used
 * to queue one behind another, and a slow GET held every sibling hostage).
 *
 * The price of that speed-up is a contract: a GET/HEAD API endpoint must never
 * write $_SESSION after require_auth_api() — the write would stay in memory and
 * be silently dropped. This smoke enforces the contract going forward.
 *
 * PART 1 — static guard (no DB, no server)
 *   Every $_SESSION write under api/, lib/, includes/ and config/ (assignment,
 *   compound assignment, [] push, unset(), by-reference &$_SESSION, array_push
 *   & co., or $_SESSION passed as a function's first argument — a by-ref
 *   parameter writes it invisibly) and every call to a session-mutating helper (generate_csrf_token,
 *   auth_login, auth_logout, _ff_session_destroy, _ff_refresh_user_permissions,
 *   session_regenerate_id, session_destroy/unset/reset, or requiring a page
 *   header template) must be EITHER
 *     - in an api/ file whose require_method(...) accepts only
 *       POST/PUT/PATCH/DELETE, OR
 *     - inside an allowlisted function that runs BEFORE the close (session
 *       start, login/logout, remember-me, the permission refresh) or only on
 *       page (require_auth) paths.
 *   It also pins that require_auth_api() is only called from api/ (so the scan
 *   scope is complete) and that the close sits AFTER the freshness re-check.
 *
 * PART 2 — real code, real schema, real (non-CLI) SAPI
 *   Spawns `php -S` on a free port against a private session dir, mints a
 *   Manager session with the real auth_login(), and drives the REAL
 *   api/bootstrap.php + require_auth_api() through a tiny harness endpoint and
 *   one real endpoint (attention/count.php, the topbar bell poller):
 *     - GET  → session closed, file lock FREE while the endpoint still runs
 *     - HEAD → session closed
 *     - POST → session still ACTIVE, file lock HELD (writes keep persisting)
 *     - GET still persists ff_last_activity and a refreshed permission map
 *       (both happen before the close)
 *     - a $_SESSION write after the close is NOT saved and the dev tripwire
 *       logs it
 *     - CLI callers of require_auth_api() keep their session open (unchanged)
 *   The harness never calls json_success() on POST, so no analytics-cache
 *   invalidation (a shared-DB write) happens. No DB rows are written.
 *
 * Run:  php tests/_smoke_session_get_close.php   Exit 0 = pass, 1 = fail, 2 = setup.
 *
 * @session S-PERF-3
 */

require_once dirname(__DIR__) . '/config/app.php';

// All output goes through fwrite(STDOUT) — NOT echo — because Part 2 calls
// session_id()/session_start() mid-run, and PHP refuses those once anything
// has been echoed (headers_sent() turns true even under the CLI SAPI).

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; fwrite(STDOUT, "  \033[32mPASS\033[0m — {$m}\n"); };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; fwrite(STDOUT, "  \033[31mFAIL\033[0m — {$m}\n"); };
$check = static function (bool $c, string $m) use ($pass, $fail): void { $c ? $pass($m) : $fail($m); };

// ============================================================================
// PART 1 — static guard
// ============================================================================
fwrite(STDOUT, "\n── Part 1: static session-write guard ─────────────────────────────\n");

/**
 * Tokenise a PHP file into code-only lines (comments and docblocks blanked,
 * newlines kept) plus a per-line map of the enclosing NAMED function.
 *
 * WHY tokens, not regex over raw text: many files carry WHY-comments that
 * quote `$_SESSION[...] = ...`; a text grep would flag the prose. Tokens also
 * give us exact function scoping for the allowlist.
 *
 * @return array{0: array<int,string>, 1: array<int,string>}  [line => code, line => function|'<top>']
 */
function ffs_code_and_scopes(string $path): array
{
    $src    = (string) file_get_contents($path);
    $tokens = token_get_all($src);
    $code   = '';
    $scopeAtLine = [];
    $stack  = [];          // [name, braceDepthAtOpen]
    $depth  = 0;
    $pendingName = null;   // a named function whose body `{` has not opened yet
    $expectName  = false;
    $line = 1;

    foreach ($tokens as $t) {
        if (is_array($t)) {
            [$id, $text, $line] = $t;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $code .= str_repeat("\n", substr_count($text, "\n"));
                continue;
            }
            if ($id === T_FUNCTION) { $expectName = true; }
            elseif ($expectName && $id === T_STRING) { $pendingName = $text; $expectName = false; }
            elseif ($expectName && $id !== T_WHITESPACE && $text !== '&') { $expectName = false; }
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; }
            $code .= $text;
        } else {
            if ($t === '(' && $expectName) { $expectName = false; } // closure
            if ($t === '{') {
                $depth++;
                if ($pendingName !== null) { $stack[] = [$pendingName, $depth]; $pendingName = null; }
            } elseif ($t === '}') {
                if ($stack && end($stack)[1] === $depth) { array_pop($stack); }
                $depth--;
            } elseif ($t === ';' && $pendingName !== null) {
                $pendingName = null; // abstract/interface signature, no body
            }
            $code .= $t;
        }
        $cur = $stack ? end($stack)[0] : '<top>';
        // Record the scope for every line this token touches.
        $scopeAtLine[$line] = $scopeAtLine[$line] ?? $cur;
        $end = $line + substr_count(is_array($t) ? $t[1] : $t, "\n");
        for ($l = $line; $l <= $end; $l++) { $scopeAtLine[$l] = $scopeAtLine[$l] ?? $cur; }
    }

    $lines = [];
    foreach (explode("\n", $code) as $i => $l) { $lines[$i + 1] = $l; }
    return [$lines, $scopeAtLine];
}

// Direct $_SESSION mutations.
$WRITE_PATTERNS = [
    'assign'   => '~\$_SESSION\s*(?:\[[^\]]*\]\s*)*(?:=(?![=>])|\+=|-=|\*=|/=|\.=|%=|\?\?=|\|=|&=|\^=)~',
    'incdec'   => '~(?:\+\+|--)\s*\$_SESSION|\$_SESSION\s*(?:\[[^\]]*\]\s*)+(?:\+\+|--)~',
    'unset'    => '~\bunset\s*\([^)]*\$_SESSION~',
    'byref'    => '~&\s*\$_SESSION~',
    'arrayfn'  => '~\barray_(?:push|pop|shift|unshift|splice)\s*\(\s*\$_SESSION~',
    // $_SESSION (or a slice of it) handed to a function as its first argument.
    // WHY: a by-reference parameter (`function f(array &$u)`) mutates the
    // session with no `$_SESSION ... =` anywhere at the call site — e.g.
    // _ff_refresh_permission_overrides_if_stale($_SESSION['ff_user']). The
    // negative lookahead exempts read-only builtins and control keywords;
    // method/static calls (->f( / ::f() are deliberately included.
    'argpass'  => '~(?<![\w$])\b(?!(?:isset|empty|serialize|json_encode|md5|is_array|is_string|is_int|count|'
        . 'print_r|var_export|var_dump|if|elseif|while|switch|match|foreach|for|return|echo|print|array|list)\b)'
        . '[A-Za-z_]\w*\s*\(\s*\$_SESSION~',
];
// Helpers that write the session (or regenerate/destroy it). A call from a
// GET API after the close would be dropped just the same.
$HELPER_PATTERN = '~(?<!function )(?<![\w>:$])\b(generate_csrf_token|csrf_token|auth_login|auth_logout|'
    . '_ff_session_destroy|_ff_refresh_user_permissions|session_regenerate_id|session_destroy|'
    . 'session_unset|session_reset)\s*\(~';
// Page templates call generate_csrf_token() at top level.
$TEMPLATE_PATTERN = '~(?:require|include)(?:_once)?\b[^;]*\b(?:header|header_embed)\.php~';

// Allowlist: file => [function => reason]. Every entry runs BEFORE the GET
// close in require_auth_api(), or only on page / login paths that never close.
$ALLOW = [
    'includes/auth.php' => [
        '_ff_session_start'              => 'session start: activity stamp + remember-me restore, before any close',
        'require_auth'                   => 'PAGE guard (redirect_after_login); pages never close the session early',
        'auth_login'                     => 'login / remember-me restore; runs inside _ff_session_start, before the close',
        'auth_logout'                    => 'logout page flow, not a GET API',
        'auth_check_remember_me'         => 'called from _ff_session_start, before the close',
        '_ff_session_destroy'            => 'timeout/revocation paths, all before the close',
        '_ff_refresh_user_permissions'   => 'called only from POST permission endpoints',
        '_ff_check_permission_freshness' => 'runs inside require_auth_api() BEFORE the close (order pinned below)',
    ],
    'includes/functions.php' => [
        'generate_csrf_token' => 'definition; callers are auth_login + page header templates',
    ],
    'includes/header.php'       => ['<top>' => 'page template (require_auth pages only)'],
    'includes/header_embed.php' => ['<top>' => 'page template (require_auth pages only)'],
];
$WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

$scanDirs = ['api', 'lib', 'includes', 'config'];
$files = [];
foreach ($scanDirs as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) { $files[] = $f->getPathname(); }
    }
}
sort($files);

$offenders   = [];
$apiWriters  = [];
$allowHits   = [];
$hitCount    = 0;
foreach ($files as $abs) {
    $rel = substr($abs, strlen($ROOT) + 1);
    [$lines, $scopes] = ffs_code_and_scopes($abs);
    $hits = [];
    foreach ($lines as $n => $l) {
        if (!str_contains($l, '$_SESSION') && !preg_match($HELPER_PATTERN, $l) && !preg_match($TEMPLATE_PATTERN, $l)) continue;
        $kind = null;
        foreach ($WRITE_PATTERNS as $k => $re) { if (preg_match($re, $l)) { $kind = $k; break; } }
        if ($kind === null && preg_match($HELPER_PATTERN, $l, $m)) { $kind = 'helper:' . $m[1]; }
        if ($kind === null && preg_match($TEMPLATE_PATTERN, $l)) { $kind = 'template'; }
        if ($kind !== null) { $hits[] = [$n, $kind, $scopes[$n] ?? '<top>']; }
    }
    if (!$hits) continue;
    $hitCount += count($hits);

    if (str_starts_with($rel, 'api/')) {
        // api/ file: must be a write-method-only endpoint.
        $srcCode = implode("\n", $lines);
        preg_match_all('~\brequire_method\s*\(([^)]*)\)~', $srcCode, $mm);
        $methods = [];
        foreach ($mm[1] as $args) {
            preg_match_all('~[\'"]([A-Za-z]+)[\'"]~', $args, $am);
            foreach ($am[1] as $x) { $methods[] = strtoupper($x); }
        }
        $methods = array_values(array_unique($methods));
        $writeOnly = $methods !== [] && array_diff($methods, $WRITE_METHODS) === [];
        if ($writeOnly) {
            $apiWriters[] = $rel . ' [' . implode(',', $methods) . ']';
        } else {
            foreach ($hits as [$n, $kind]) {
                $offenders[] = "{$rel}:{$n} ({$kind}) — endpoint accepts "
                    . ($methods ? implode(',', $methods) : 'ANY method (no require_method)')
                    . '; session writes are only allowed in POST/PUT/PATCH/DELETE-only endpoints';
            }
        }
        continue;
    }

    // lib/, includes/, config/: must be inside an allowlisted function.
    foreach ($hits as [$n, $kind, $fn]) {
        if (isset($ALLOW[$rel][$fn])) { $allowHits[$rel . '::' . $fn] = true; continue; }
        $offenders[] = "{$rel}:{$n} ({$kind}) in {$fn}() — not on the pre-close allowlist";
    }
}

$check($hitCount > 0, "scanner found session-write sites at all ({$hitCount}) — pattern set is live");
$check(count($apiWriters) >= 6, 'known POST-only session writers are recognised (' . count($apiWriters) . ': save_preference, display_settings, MFA, bank-import, permissions…)');
$check($offenders === [], 'no GET-reachable $_SESSION write outside the allowlist (' . count($offenders) . ' found)');
foreach ($offenders as $o) { fwrite(STDOUT, "        -> {$o}\n"); }

// Stale-allowlist check: every allowlisted function must still exist, so the
// list cannot silently widen into a blanket exemption.
$stale = [];
foreach ($ALLOW as $rel => $fns) {
    $src = (string) @file_get_contents($ROOT . '/' . $rel);
    foreach (array_keys($fns) as $fn) {
        if ($fn === '<top>') { if ($src === '') $stale[] = $rel; continue; }
        if (!preg_match('~\bfunction\s+' . preg_quote($fn, '~') . '\s*\(~', $src)) { $stale[] = "{$rel}::{$fn}"; }
    }
}
$check($stale === [], 'every allowlist entry still exists (' . implode(', ', $stale) . ')');

// Scope completeness: require_auth_api() must only be called from api/ (tests
// and its own definition aside) — otherwise an early-closing caller could live
// outside the scanned tree.
$outside = [];
foreach (['app', 'cron', 'scripts', 'lib', 'public', 'bin'] as $d) {
    if (!is_dir($ROOT . '/' . $d)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || !str_ends_with($f->getFilename(), '.php')) continue;
        [$lines] = ffs_code_and_scopes($f->getPathname());
        if (preg_match('~(?<!function )\brequire_auth_api\s*\(~', implode("\n", $lines))) {
            $outside[] = substr($f->getPathname(), strlen($ROOT) + 1);
        }
    }
}
$check($outside === [], 'require_auth_api() is only called from api/ (outside: ' . implode(', ', $outside) . ')');

// Order pin: the close must come AFTER the freshness re-check.
[$authLines] = ffs_code_and_scopes($ROOT . '/includes/auth.php');
$authCode = implode("\n", $authLines);
$bodyOk = false;
if (preg_match('~function\s+require_auth_api\s*\(\)\s*:\s*void\s*\{(.*?)\n\}~s', $authCode, $bm)) {
    $pFresh = strpos($bm[1], '_ff_check_permission_freshness(true)');
    $pClose = strpos($bm[1], '_ff_release_session_lock_for_read()');
    $bodyOk = $pFresh !== false && $pClose !== false && $pFresh < $pClose;
}
$check($bodyOk, 'require_auth_api(): session close sits AFTER _ff_check_permission_freshness(true)');

// ============================================================================
// PART 2 — real code under a real non-CLI SAPI
// ============================================================================
fwrite(STDOUT, "\n── Part 2: live php -S run (real bootstrap + require_auth_api) ────\n");

$tmp = sys_get_temp_dir() . '/_ff_sessclose_' . getmypid();
@mkdir($tmp . '/sess', 0700, true);
$sessDir = $tmp . '/sess';

// Private save path BEFORE auth.php loads (it session_start()s on include).
ini_set('session.save_path', $sessDir);
require_once $ROOT . '/includes/auth.php';

$USER_ID = 55; // test Manager (non-super-admin, permissions_updated_at set)
$user = db_row(
    "SELECT u.id, u.name, u.email, u.role_id, r.slug AS role_slug, u.theme_preference, u.status,
            u.display_font_size, u.display_density, u.permissions_updated_at
       FROM users u JOIN user_roles r ON r.id = u.role_id
      WHERE u.id = ? AND u.deleted_at IS NULL AND u.status = 'active'",
    [$USER_ID]
);
if (!$user || $user['role_slug'] === 'super_admin') {
    fwrite(STDOUT, "SETUP: test user {$USER_ID} missing/inactive/super_admin — cannot run Part 2\n");
    exit(2);
}

/** Read a session file's data through PHP's own decoder (no lock left behind). */
$readSess = static function (string $sid): array {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_id($sid);
    @session_start(['read_and_close' => true]);
    $data = $_SESSION ?? [];
    $_SESSION = [];
    return $data;
};
/** Rewrite a session file (setup only). */
$editSess = static function (string $sid, callable $fn): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_id($sid);
    @session_start();
    $fn($_SESSION);
    session_write_close();
    $_SESSION = [];
};

// Mint with the real auth_login() (reads only; no remember-me → no DB write).
@auth_login($user, false);
$SID  = session_id();
$CSRF = (string) ($_SESSION['csrf_token'] ?? '');
session_write_close();
$check($SID !== '' && is_file("{$sessDir}/sess_{$SID}") && $CSRF !== '', 'minted a real Manager session in the private save path');

// CLI behaviour unchanged: require_auth_api() in CLI leaves the session open.
$editSess($SID, static function (array &$s): void {});
session_id($SID); @session_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
require_auth_api();
$check(session_status() === PHP_SESSION_ACTIVE, 'CLI: require_auth_api() on GET leaves the session ACTIVE (CLI path unchanged)');
session_write_close(); $_SESSION = [];

// Harness endpoint + router, served by php -S (SAPI = cli-server, not cli).
$harness = $tmp . '/harness.php';
file_put_contents($harness, <<<PHP
<?php
require '{$ROOT}/api/bootstrap.php';
require_auth_api();
// Can a SECOND handle take the session file lock right now? Only if the
// request already released it.
\$file = rtrim(session_save_path(), '/') . '/sess_' . (\$_COOKIE['ff_session'] ?? '');
\$fh   = @fopen(\$file, 'r');
\$free = \$fh && flock(\$fh, LOCK_EX | LOCK_NB);
if (\$free) flock(\$fh, LOCK_UN);
if (\$fh) fclose(\$fh);
if ((\$_GET['write_after'] ?? '') === '1') { \$_SESSION['__smoke_after_close'] = 1; }
header('X-Smoke-Status: ' . session_status());
header('X-Smoke-Lock-Free: ' . (\$free ? '1' : '0'));
// Deliberately NOT json_success(): on POST it would invalidate the shared
// analytics cache (a DB write). Raw echo keeps this smoke read-only.
echo json_encode(['status' => session_status(), 'lock_free' => \$free]);
exit;
PHP);
$router = $tmp . '/router.php';
file_put_contents($router, <<<PHP
<?php
if (str_starts_with((string) parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH), '/__ff_smoke_harness')) {
    require '{$harness}';
    return true;
}
return false;
PHP);

// Free port.
$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$port  = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$logFile = $tmp . '/server.log';
$proc = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $sessDir, '-d', 'log_errors=1', '-d', 'error_log=' . $logFile,
     '-S', "127.0.0.1:{$port}", '-t', $ROOT, $router],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile . '.out', 'a'], 2 => ['file', $logFile . '.out', 'a']],
    $pipes
);

/**
 * Minimal HTTP client: returns [status, headers(lowercased), body].
 */
$http = static function (string $method, string $path, array $headers = []) use ($port): array {
    $fp = @fsockopen('127.0.0.1', $port, $en, $es, 5);
    if (!$fp) return [0, [], ''];
    $req = "{$method} {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\n";
    foreach ($headers as $k => $v) { $req .= "{$k}: {$v}\r\n"; }
    if ($method === 'POST') { $req .= "Content-Type: application/json\r\nContent-Length: 2\r\n\r\n{}"; }
    else { $req .= "\r\n"; }
    fwrite($fp, $req);
    stream_set_timeout($fp, 30);
    $raw = stream_get_contents($fp);
    fclose($fp);
    [$head, $body] = array_pad(explode("\r\n\r\n", (string) $raw, 2), 2, '');
    $hl = explode("\r\n", $head);
    preg_match('~^HTTP/\S+\s+(\d+)~', $hl[0] ?? '', $sm);
    $h = [];
    foreach (array_slice($hl, 1) as $line) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
    }
    return [(int) ($sm[1] ?? 0), $h, $body];
};

try {
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) { usleep(100_000); $up = (@fsockopen('127.0.0.1', $port) !== false); }
    if (!$up) { fwrite(STDOUT, "SETUP: php -S did not start on port {$port}\n"); exit(2); }

    $cookie = ['Cookie' => "ff_session={$SID}"];

    // GET → closed + lock free; ff_last_activity + refreshed overrides persisted.
    $editSess($SID, static function (array &$s): void {
        $s['ff_last_activity'] = time() - 120;
        $s['ff_user']['permissions_db_ts']    = '1999-01-01 00:00:00'; // force "stale"
        $s['ff_user']['permission_overrides'] = ['__smoke_sentinel' => ['view' => 1]];
    });
    $t0 = time();
    [$st, $h] = $http('GET', '/__ff_smoke_harness', $cookie);
    $check($st === 200, "GET harness answered 200 (got {$st})");
    $check(($h['x-smoke-status'] ?? '') === (string) PHP_SESSION_NONE, 'GET: session is CLOSED after require_auth_api()');
    $check(($h['x-smoke-lock-free'] ?? '') === '1', 'GET: session file lock is FREE while the endpoint is still running');
    $after = $readSess($SID);
    $check((int) ($after['ff_last_activity'] ?? 0) >= $t0 - 1, 'GET: ff_last_activity was still saved (stamped before the close)');
    // The refresh only fires when users.permissions_updated_at is set (NULL =
    // "no override ever applied" → nothing to reload), so only assert then.
    if ($user['permissions_updated_at'] === null) {
        fwrite(STDOUT, "  NOTE — user {$USER_ID} has NULL permissions_updated_at; override-refresh persistence not exercised\n");
    } else {
        $check(
            ($after['ff_user']['permissions_db_ts'] ?? null) === $user['permissions_updated_at']
            && !isset($after['ff_user']['permission_overrides']['__smoke_sentinel']),
            'GET: refreshed permission-override map was persisted (refresh runs before the close)'
        );
    }

    // HEAD → closed too.
    [$st, $h] = $http('HEAD', '/__ff_smoke_harness', $cookie);
    $check(($h['x-smoke-status'] ?? '') === (string) PHP_SESSION_NONE, "HEAD: session is CLOSED (status {$st})");

    // POST → still active, lock held (write endpoints unchanged).
    [$st, $h] = $http('POST', '/__ff_smoke_harness', $cookie + ['X-CSRF-Token' => $CSRF]);
    $check($st === 200, "POST harness passed CSRF + auth (got {$st})");
    $check(($h['x-smoke-status'] ?? '') === (string) PHP_SESSION_ACTIVE, 'POST: session stays ACTIVE (writes still persist)');
    $check(($h['x-smoke-lock-free'] ?? '') === '0', 'POST: session file lock is still HELD');

    // A write after the GET close is dropped — and the dev tripwire says so.
    @file_put_contents($logFile, '');
    [$st] = $http('GET', '/__ff_smoke_harness?write_after=1', $cookie);
    $after = $readSess($SID);
    $check(!isset($after['__smoke_after_close']), 'GET: a $_SESSION write after the close is NOT saved (the hazard the static guard prevents)');
    usleep(200_000);
    $log = (string) @file_get_contents($logFile) . (string) @file_get_contents($logFile . '.out');
    $check(APP_ENV === 'production' || str_contains($log, 'S-PERF-3: $_SESSION was modified after the GET session close'),
        'dev tripwire logged the dropped write');

    // A real endpoint still answers normally for the same session. The topbar
    // bell count is the hottest GET poller; fall back to the legacy count
    // endpoint only if it is ever renamed (it is on the cleanup list).
    $realEp = is_file($ROOT . '/api/v1/attention/count.php') ? '/api/v1/attention/count.php' : '/api/v1/notifications/count.php';
    [$st, , $body] = $http('GET', $realEp, $cookie);
    $j = json_decode($body, true);
    $check($st === 200 && ($j['success'] ?? false) === true && array_key_exists('data', $j),
        "real GET {$realEp} → 200 success envelope (got {$st})");
    [$st] = $http('GET', $realEp, $cookie);
    $check($st === 200, 'session survives consecutive GETs (no 401 after the early close)');

    // Logged-out GET still gets a clean 401 envelope.
    [$st, , $body] = $http('GET', $realEp);
    $j = json_decode($body, true);
    $check($st === 401 && (($j['error']['code'] ?? '') === 'UNAUTHORIZED'), "no session → 401 UNAUTHORIZED envelope (got {$st})");
    $check(!preg_match('~PHP (Warning|Notice|Fatal|Deprecated)~', (string) @file_get_contents($logFile) . (string) @file_get_contents($logFile . '.out')),
        'php -S log has no PHP warnings/notices/fatals');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    foreach (glob($sessDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($sessDir);
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
}

fwrite(STDOUT, "\n----------------------------------------------------------------------\n");
fwrite(STDOUT, 'TOTAL: ' . $passes . ' pass / ' . count($failures) . " fail\n");
fwrite(STDOUT, "----------------------------------------------------------------------\n");
exit($failures === [] ? 0 : 1);
