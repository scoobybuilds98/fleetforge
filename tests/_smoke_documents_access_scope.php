<?php
declare(strict_types=1);

/**
 * tests/_smoke_documents_access_scope.php
 *
 * C1 (Wave 0) — Documents access-scope leak.
 *
 * Two surfaces let ANY authenticated user reach EVERY stored document,
 * bypassing the per-entity permission gate that the entity-scoped list
 * (Mode A) already enforces:
 *
 *   1. api/v1/documents/index.php  Mode B (global list, no entity_id) — has
 *      NO require_permission; returns every document + a signed URL to anyone.
 *   2. api/v1/storage/serve.php    — authorizes a download on session + HMAC
 *      signature alone, with no check on whether the caller may see the
 *      document's owning entity.
 *
 * This smoke proves the leak end-to-end against the REAL endpoints + real
 * db.php + schema + StorageClient, then asserts the post-fix contract:
 *
 *   - A user WITHOUT customers.view must NOT see a customer-owned document in
 *     the Mode-B global list, and must get 403 from serve.php for that file.
 *   - A privileged user (super_admin) must still see it and download it
 *     (non-vacuous — proves the smoke isn't trivially denying everything).
 *
 * The denied user is a real dispatcher with a real user_permission_overrides
 * row revoking customers.view (granted=0) — so can('customers','view') is
 * false whether or not the per-request freshness check reloads overrides.
 *
 * PRE-FIX  : Mode-B/denied SEES the doc and serve/denied STREAMS it → FAIL.
 * POST-FIX : both are blocked for the denied user, allowed for super_admin.
 *
 * Real writes (customer doc, storage file, test user, override) are all
 * removed in the finally block. Local STORAGE_DRIVER only (prod uses S3).
 *
 * Run:  php tests/_smoke_documents_access_scope.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session C1-DOCS-ACCESS-SCOPE
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/includes/auth.php';
require_once FF_ROOT . '/includes/permission_registry.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Storage\StorageClient;

if (!isset($_SESSION)) $_SESSION = [];

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;

$pass = static function (string $msg) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$msg}\n"; };
$fail = static function (string $msg) use (&$failures): void { $failures[] = $msg; echo "  \033[31mFAIL\033[0m — {$msg}\n"; };

// Local-driver guard — serve.php only exists for the local driver.
$driver = settings_get('storage.driver') ?: env('STORAGE_DRIVER', 'local');
if ($driver === 's3') {
    echo "SKIP — STORAGE_DRIVER=s3 (serve.php path not exercised on S3).\n";
    exit(0);
}

// ── Subprocess harness ──────────────────────────────────────────────────────
// Invoke a real endpoint with a chosen session. Pre-start the session and set
// ff_user; the endpoint's bootstrap _ff_session_start() is guarded so it keeps
// our injected user. Returns raw stdout (JSON for list/error, file bytes for a
// successful serve).
$harnessFile = sys_get_temp_dir() . '/_ff_docacc_harness_' . getmypid() . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$qs       = \$argv[2] ?? '';
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['REQUEST_URI']    = '/' . \$endpoint . '?' . \$qs;
\$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
\$_SERVER['HTTP_HOST']      = 'localhost';
@session_start();
\$_SESSION['ff_user'] = \$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$invoke = static function (string $endpoint, string $qs, array $session) use ($harnessFile): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint) . ' ' . escapeshellarg($qs)
        . ' ' . escapeshellarg(base64_encode(json_encode($session))) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    return is_string($out) ? $out : '';
};
/** Extract the JSON envelope from possibly-warning-prefixed output. */
$decodeJson = static function (string $out): ?array {
    $start = strpos($out, '{"success"');
    if ($start === false) $start = strpos($out, '{"');
    if ($start !== false) $out = substr($out, $start);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : null;
};

// ── Setup state to clean up ──────────────────────────────────────────────────
$testUserId = null;
$docId      = null;
$storageKey = null;
$SENTINEL   = '%PDF-1.4 _ff_docacc_smoke_' . getmypid() . " sensitive-credit-application\n";

try {
    $admin = db_row(
        "SELECT u.id, u.name, u.email, u.role_id, ur.slug role_slug
           FROM users u JOIN user_roles ur ON ur.id = u.role_id
          WHERE ur.slug = 'super_admin' AND u.deleted_at IS NULL LIMIT 1"
    );
    if (!$admin) { echo "\033[31mSETUP FAIL\033[0m no super_admin user.\n"; exit(2); }

    $dispatcherRole = db_row("SELECT id FROM user_roles WHERE slug = 'dispatcher' LIMIT 1");
    if (!$dispatcherRole) { echo "\033[31mSETUP FAIL\033[0m no dispatcher role.\n"; exit(2); }

    $customer = db_row("SELECT id, company_name FROM customers WHERE deleted_at IS NULL LIMIT 1");
    if (!$customer) { echo "\033[31mSETUP FAIL\033[0m no customer to own the test document.\n"; exit(2); }
    $customerId = (int) $customer['id'];

    // Test dispatcher user with customers.view REVOKED via a real override row.
    $email = '_smoke_docacc_' . getmypid() . '@fleetforge.test';
    $old = db_row("SELECT id FROM users WHERE email = ?", [$email]);
    if ($old) {
        db_execute("DELETE FROM user_permission_overrides WHERE user_id = ?", [(int) $old['id']]);
        db_execute("DELETE FROM users WHERE id = ?", [(int) $old['id']]);
    }
    $testUserId = db_insert('users', [
        'name'    => 'Smoke DocAcc Dispatcher',
        'email'   => $email,
        'role_id' => (int) $dispatcherRole['id'],
        'status'  => 'active',
    ]);
    db_insert('user_permission_overrides', [
        'user_id'    => $testUserId,
        'module'     => 'customers',
        'action'     => 'view',
        'granted'    => 0,
        'granted_by' => (int) $admin['id'],
        'reason'     => 'C1 docs access-scope smoke',
    ]);
    db_execute("UPDATE users SET permissions_updated_at = NOW() WHERE id = ?", [$testUserId]);

    // Real storage file + documents row owned by the customer.
    $storageKey = 'documents/_smoke_docacc/' . getmypid() . '.pdf';
    $tmp = tempnam(sys_get_temp_dir(), 'ffdoc_');
    file_put_contents($tmp, $SENTINEL);
    StorageClient::upload($tmp, $storageKey);

    $docId = db_insert('documents', [
        'entity_type'   => 'customer',
        'entity_id'     => $customerId,
        'title'         => 'Smoke Credit Application',
        'document_type' => 'credit_application',
        'file_path'     => $storageKey,
        'file_name'     => 'smoke_credit_app.pdf',
        'mime_type'     => 'application/pdf',
        'uploaded_by'   => (int) $admin['id'],
    ]);

    // Signed URL for serve.php (parse key/exp/sig out of it).
    $signed = StorageClient::url($storageKey, 3600);
    $qsServe = parse_url($signed, PHP_URL_QUERY) ?? '';
    parse_str($qsServe, $serveParams);

    echo str_repeat('─', 72) . "\n";
    echo "C1 DOCUMENTS ACCESS-SCOPE — doc #{$docId} (customer #{$customerId}), "
        . "denied user #{$testUserId} (dispatcher, customers.view=0)\n";
    echo str_repeat('─', 72) . "\n";

    // Build the DENIED session from DB (real overrides loaded; ts matched so the
    // freshness check is a no-op — but even a refresh reloads granted=0).
    $deniedSession = [
        'id'                        => $testUserId,
        'name'                      => 'Smoke DocAcc Dispatcher',
        'email'                     => $email,
        'role_id'                   => (int) $dispatcherRole['id'],
        'role_slug'                 => 'dispatcher',
        'permissions'               => (require FF_ROOT . '/config/permissions.php')['dispatcher'] ?? [],
        'permission_overrides'      => _ff_load_user_overrides($testUserId),
        'role_permission_overrides' => _ff_load_role_overrides((int) $dispatcherRole['id']),
        'permissions_db_ts'         => db_row("SELECT permissions_updated_at FROM users WHERE id=?", [$testUserId])['permissions_updated_at'] ?? null,
        'permissions_loaded_at'     => date('Y-m-d H:i:s'),
    ];
    $allowedSession = ['id' => (int) $admin['id'], 'role_slug' => 'super_admin'];

    // ── A0 non-vacuous precheck: the override really denies customers.view ────
    $_SESSION['ff_user'] = $deniedSession;
    $deniedCan = can('customers', 'view');
    $_SESSION['ff_user'] = null;
    if ($deniedCan === false) {
        $pass("A0 precheck — denied user's can('customers','view') === false");
    } else {
        $fail("A0 precheck — denied user can('customers','view') is TRUE; override not applied (test would be vacuous)");
    }

    // ── 1. MODE B (global list) — DENIED user must NOT see the customer doc ───
    $r = $decodeJson($invoke('api/v1/documents/index.php', 'per_page=100', $deniedSession));
    if ($r === null) {
        $fail("1 Mode-B/denied — no/invalid JSON from documents/index.php");
    } else {
        $items = $r['data']['items'] ?? ($r['items'] ?? []);
        $ids   = array_map(static fn($x) => (int) ($x['id'] ?? 0), is_array($items) ? $items : []);
        $forbidden = isset($r['error']) || (($r['success'] ?? true) === false);
        $leaked = in_array((int) $docId, $ids, true);
        if (!$leaked && (!$forbidden || $forbidden)) {
            $pass("1 Mode-B/denied — customer doc #{$docId} NOT in global list (forbidden=" . ($forbidden ? 'Y' : 'n') . ")");
        } else {
            $fail("1 Mode-B/denied — LEAK: customer doc #{$docId} returned in global list to a user without customers.view");
        }
    }

    // ── 2. MODE B — super_admin SEES the doc (non-vacuous) ────────────────────
    $r = $decodeJson($invoke('api/v1/documents/index.php', 'per_page=100', $allowedSession));
    if ($r === null) {
        $fail("2 Mode-B/allowed — no/invalid JSON");
    } else {
        $items = $r['data']['items'] ?? ($r['items'] ?? []);
        $ids   = array_map(static fn($x) => (int) ($x['id'] ?? 0), is_array($items) ? $items : []);
        if (in_array((int) $docId, $ids, true)) {
            $pass("2 Mode-B/allowed — super_admin sees doc #{$docId} (non-vacuous)");
        } else {
            $fail("2 Mode-B/allowed — super_admin did NOT see doc #{$docId}; endpoint/list broken, smoke would be vacuous");
        }
    }

    // ── 3. SERVE — DENIED user must NOT download the file ─────────────────────
    $out = $invoke('api/v1/storage/serve.php',
        http_build_query(['key' => $serveParams['key'], 'exp' => $serveParams['exp'], 'sig' => $serveParams['sig']]),
        $deniedSession);
    $served = str_contains($out, 'sensitive-credit-application');
    if (!$served) {
        $pass("3 serve/denied — file NOT streamed to a user without customers.view");
    } else {
        $fail("3 serve/denied — LEAK: serve.php streamed the customer document bytes to a user without customers.view");
    }

    // ── 4. SERVE — super_admin downloads it (non-vacuous) ─────────────────────
    $out = $invoke('api/v1/storage/serve.php',
        http_build_query(['key' => $serveParams['key'], 'exp' => $serveParams['exp'], 'sig' => $serveParams['sig']]),
        $allowedSession);
    if (str_contains($out, 'sensitive-credit-application')) {
        $pass("4 serve/allowed — super_admin downloads the file (non-vacuous)");
    } else {
        $fail("4 serve/allowed — super_admin could NOT download the file; serve broken, smoke would be vacuous");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $_SESSION = [];
    if ($docId)      { db_execute("DELETE FROM documents WHERE id = ?", [$docId]); echo "  removed document #{$docId}\n"; }
    if ($storageKey) { @StorageClient::delete($storageKey); echo "  removed storage key {$storageKey}\n"; }
    if ($testUserId) {
        db_execute("DELETE FROM user_permission_overrides WHERE user_id = ?", [$testUserId]);
        db_execute("DELETE FROM users WHERE id = ?", [$testUserId]);
        echo "  removed test user #{$testUserId}\n";
    }
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("C1 DOCS ACCESS-SCOPE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) {
    echo "\033[31m✗ FAILURES:\033[0m\n";
    foreach ($failures as $f) echo "  - {$f}\n";
} else {
    echo "\033[32m✓ ALL PASSED\033[0m\n";
}
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
