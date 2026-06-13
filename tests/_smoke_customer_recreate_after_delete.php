<?php
declare(strict_types=1);

/**
 * tests/_smoke_customer_recreate_after_delete.php
 *
 * C2 (Wave 0) — Customer re-create after soft-delete + email-only edit collision.
 *
 * customers.uq_company_email (company_name, email) is a GLOBAL unique key that
 * spans soft-deleted rows, and customers.delete is a SOFT delete. Two bugs flow
 * from that (mirror of the FLEETFORGE-F users incident, fixed for users only):
 *
 *   C2 (CRITICAL) create.php — the duplicate pre-check queries
 *     `company_name = ? AND email = ?` with NO `deleted_at IS NULL`, so a
 *     tombstoned row permanently blocks re-adding that company+email with a
 *     misleading 422; and the INSERT has no 23000 catch.
 *
 *   H-twin (HIGH) update.php — the dup guard fires ONLY when company_name
 *     changes, so an email-only edit into an existing (company,email) pair
 *     skips the guard, hits the unique key and 500s (INTERNAL_ERROR), instead
 *     of a clean 422.
 *
 * This smoke drives the REAL endpoints (subprocess, real db.php + schema + CSRF
 * + auth). It distinguishes a clean validation error (VALIDATION_ERROR) from an
 * uncaught-1062 500 (INTERNAL_ERROR, emitted by bootstrap's exception handler).
 *
 * PRE-FIX  : re-create returns 422 "already exists" (lockout); email-only edit
 *            returns INTERNAL_ERROR 500 → FAIL.
 * POST-FIX : re-create REVIVES the soft-deleted row (same id, deleted_at NULL,
 *            no duplicate); email-only collision returns a clean VALIDATION_ERROR.
 *
 * Non-vacuous: a brand-new create succeeds, and a legitimate email-only edit to
 * a free email succeeds.
 *
 * Real writes are removed in the finally block (matched on a per-pid token).
 *
 * Run:  php tests/_smoke_customer_recreate_after_delete.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session C2-CUSTOMER-REVIVE
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/includes/auth.php';

if (!isset($_SESSION)) $_SESSION = [];

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $msg) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$msg}\n"; };
$fail = static function (string $msg) use (&$failures): void { $failures[] = $msg; echo "  \033[31mFAIL\033[0m — {$msg}\n"; };

$PID   = getmypid();
$TOKEN = '_smoke_recreate_' . $PID;

// ── POST harness ─────────────────────────────────────────────────────────────
// Drive a real POST JSON endpoint: feed php://input via a stream wrapper, inject
// a super_admin session + matching CSRF token, then require the endpoint.
$harnessFile = sys_get_temp_dir() . '/_ff_recreate_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$payload  = base64_decode(\$argv[2] ?? '');
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);

class FfRecreateInput {
    public \$context;
    private static string \$buf = '';
    private int \$pos = 0;
    public static function set(string \$s): void { self::\$buf = \$s; }
    public function stream_open(\$p, \$m, \$o, &\$opened): bool { \$this->pos = 0; return true; }
    public function stream_read(\$c) { \$ch = substr(self::\$buf, \$this->pos, \$c); \$this->pos += strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos >= strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o, \$w): bool { \$this->pos = \$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfRecreateInput::set(\$payload);
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'FfRecreateInput');

\$_SERVER['REQUEST_METHOD']    = 'POST';
\$_SERVER['CONTENT_TYPE']      = 'application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN'] = 'smoketoken';
\$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
\$_SERVER['HTTP_HOST']         = 'localhost';
\$_SERVER['HTTP_USER_AGENT']   = 'FleetForge-C2-Smoke/1.0';
@session_start();
\$_SESSION['csrf_token'] = 'smoketoken';
\$_SESSION['ff_user']    = \$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$adminSession = null; // set after we find the admin

$post = static function (string $endpoint, array $payload) use ($harnessFile, &$adminSession): ?array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSession)))
        . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out)) return null;
    $start = strpos($out, '{"success"');
    if ($start === false) $start = strpos($out, '{"');
    if ($start !== false) $out = substr($out, $start);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$cleanup = static function () use ($TOKEN): void {
    $rows = db_select("SELECT id FROM customers WHERE company_name LIKE ?", [$TOKEN . '%']);
    foreach ($rows as $r) {
        $cid = (int) $r['id'];
        db_execute("DELETE FROM customer_tags  WHERE customer_id = ?", [$cid]);
        db_execute("DELETE FROM customer_notes WHERE customer_id = ?", [$cid]);
        db_execute("DELETE FROM audit_log WHERE module = 'customers' AND entity_id = ?", [$cid]);
        db_execute("DELETE FROM customers WHERE id = ?", [$cid]);
    }
};

try {
    $admin = db_row(
        "SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id = u.role_id
          WHERE ur.slug = 'super_admin' AND u.deleted_at IS NULL LIMIT 1"
    );
    if (!$admin) { echo "\033[31mSETUP FAIL\033[0m no super_admin user.\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'role_slug' => 'super_admin'];

    $cleanup(); // clear any leftovers from a prior aborted run

    echo str_repeat('─', 72) . "\n";
    echo "C2 CUSTOMER REVIVE / EMAIL-ONLY EDIT — token {$TOKEN}\n";
    echo str_repeat('─', 72) . "\n";

    // ════════════════════════════════════════════════════════════════════════
    // PART 1 — create.php revive after soft-delete
    // ════════════════════════════════════════════════════════════════════════
    $coName = $TOKEN . ' Revive Co';
    $coMail = 'revive_' . $PID . '@smoke.local';

    // N0 non-vacuous: a brand-new create succeeds (201).
    $r = $post('api/v1/customers/create.php', ['company_name' => $coName, 'email' => $coMail]);
    if (($r['success'] ?? null) === true && !empty($r['data']['id'])) {
        $pass("N0 create — brand-new customer created (id={$r['data']['id']})");
        $origId = (int) $r['data']['id'];
    } else {
        $fail("N0 create — brand-new create failed: " . json_encode($r['error'] ?? $r));
        $origId = (int) (db_row("SELECT id FROM customers WHERE company_name=? AND email=?", [$coName, $coMail])['id'] ?? 0);
    }

    // Soft-delete it (the real delete path is a soft delete).
    db_execute("UPDATE customers SET deleted_at = NOW() WHERE id = ?", [$origId]);

    // 1. Re-create same company+email → must REVIVE (post-fix), not 422 (pre-fix).
    $r = $post('api/v1/customers/create.php', ['company_name' => $coName, 'email' => $coMail]);
    $matches = db_select("SELECT id, deleted_at FROM customers WHERE company_name=? AND email=?", [$coName, $coMail]);
    $rowCount = count($matches);
    $reviveOk = ($r['success'] ?? null) === true
        && $rowCount === 1
        && $matches[0]['deleted_at'] === null
        && (int) $matches[0]['id'] === $origId;
    if ($reviveOk) {
        $pass("1 create/revive — soft-deleted row revived in place (same id={$origId}, deleted_at NULL, no duplicate)");
    } else {
        $fail(sprintf("1 create/revive — LOCKOUT/duplicate: success=%s rows=%d deleted_at=%s id_match=%s err=%s",
            json_encode($r['success'] ?? null), $rowCount,
            json_encode($matches[0]['deleted_at'] ?? 'n/a'),
            isset($matches[0]) ? ((int) $matches[0]['id'] === $origId ? 'Y' : 'N') : 'n/a',
            json_encode($r['error'] ?? null)));
    }

    // ════════════════════════════════════════════════════════════════════════
    // PART 2 — update.php email-only collision
    // ════════════════════════════════════════════════════════════════════════
    // Two ACTIVE customers sharing a company_name but distinct emails (allowed:
    // the unique key is the PAIR). Editing A's email to B's email — company
    // unchanged — must be a clean 422, not a 500.
    $acme  = $TOKEN . ' Acme';
    $mailB = 'b_' . $PID . '@smoke.local';
    $mailA = 'a_' . $PID . '@smoke.local';

    $rb = $post('api/v1/customers/create.php', ['company_name' => $acme, 'email' => $mailB]);
    $ra = $post('api/v1/customers/create.php', ['company_name' => $acme, 'email' => $mailA]);
    $idA = (int) ($ra['data']['id'] ?? 0);
    if (($rb['success'] ?? null) !== true || $idA === 0) {
        $fail("2 setup — could not create the two same-company customers: B=" . json_encode($rb) . " A=" . json_encode($ra));
    } else {
        $updatedAtA = db_row("SELECT updated_at FROM customers WHERE id=?", [$idA])['updated_at'] ?? '';

        // 2. email-only edit of A → B's email (company unchanged).
        $r = $post('api/v1/customers/update.php', [
            'id'         => $idA,
            'updated_at' => $updatedAtA,
            'email'      => $mailB,            // collide; company_name NOT sent → guard skipped pre-fix
        ]);
        $code = $r['error']['code'] ?? null;
        if (($r['success'] ?? null) === false && $code === 'VALIDATION_ERROR') {
            $pass("2 update/email-only — clean VALIDATION_ERROR on collision (not a 500)");
        } elseif ($code === 'INTERNAL_ERROR') {
            $fail("2 update/email-only — UNCAUGHT 1062 → 500 INTERNAL_ERROR (" . ($r['error']['message'] ?? '') . ")");
        } else {
            $fail("2 update/email-only — unexpected response: " . json_encode($r));
        }

        // N3 non-vacuous: a legit email-only edit to a FREE email succeeds.
        $updatedAtA = db_row("SELECT updated_at FROM customers WHERE id=?", [$idA])['updated_at'] ?? '';
        $freeMail = 'a2_' . $PID . '@smoke.local';
        $r = $post('api/v1/customers/update.php', [
            'id'         => $idA,
            'updated_at' => $updatedAtA,
            'email'      => $freeMail,
        ]);
        if (($r['success'] ?? null) === true) {
            $pass("N3 update — legitimate email-only edit to a free email succeeds (non-vacuous)");
        } else {
            $fail("N3 update — legit email-only edit failed: " . json_encode($r['error'] ?? $r));
        }
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $_SESSION = [];
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed customers matching {$TOKEN}%\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("C2 CUSTOMER REVIVE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) {
    echo "\033[31m✗ FAILURES:\033[0m\n";
    foreach ($failures as $f) echo "  - {$f}\n";
} else {
    echo "\033[32m✓ ALL PASSED\033[0m\n";
}
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
