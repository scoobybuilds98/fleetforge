<?php
declare(strict_types=1);

/**
 * tests/_smoke_oauth_state.php
 *
 * S-QBO-OAUTH-FIX — Structural + behavioural smoke for the DB-backed
 * OAuth state-token store + StateManager class. Runs OFFLINE: no
 * Intuit traffic, no live OAuth flow. Mints tokens, drives them
 * through verifyAndConsume's edge cases, and asserts the table shape
 * matches the migration.
 *
 * Self-cleaning: every state_token minted during the test is tracked
 * and DELETEd at the end (success or failure). Tokens are 64-hex-char
 * random values so collisions with real data are statistically nil,
 * but cleanup is by exact token match to be extra-safe.
 *
 * 9 sub-checks:
 *   C1: acc_oauth_states table shape — 9 expected columns present
 *   C2: StateManager class surface — class + 3 public statics + 2
 *       constants in the correct namespace (FleetForge\OAuth)
 *   C3: Round-trip — generate() → verifyAndConsume() returns the
 *       initiated_by_user_id captured at mint time (cast to int) +
 *       initiated_at timestamp
 *   C4: Single-use enforcement — second verifyAndConsume on the same
 *       token returns null (used_at WHERE clause filters it out)
 *   C5: Expiry enforcement — backdate a row's expires_at, then
 *       verifyAndConsume returns null
 *   C6: Provider mismatch — verifyAndConsume with provider != the one
 *       stored at mint time returns null
 *   C7: Tamper resistance — flip one hex char of the token; tampered
 *       lookup returns null AND original token is still consumable
 *       (the failed attempt didn't side-effect the row)
 *   C8: Cleanup — manually INSERT a stale row (expires_at past the
 *       24h forensic buffer), call cleanup(), assert row deleted
 *   C9: Callback structural — callback.php has no require_auth or
 *       require_permission lines, uses StateManager::verifyAndConsume,
 *       has ff_qbo_lookup_user_name helper; init.php uses
 *       StateManager::generate and still has require_auth
 *
 * Exit 0 on all PASS; exit 1 with diagnostic list on any FAIL.
 *
 * @session S-QBO-OAUTH-FIX
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §5.1
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\OAuth\StateManager;

$failures = [];
$pass     = 0;
$total    = 9;

/** Tokens we've minted/inserted — DELETEd in the finally block. */
$sentinelTokens = [];

/** Helper: pick a real user_id we can use as initiated_by_user_id.
 *  Falls back to null if no users exist (edge case in fresh test DBs). */
function ff_smoke_pick_user_id(): ?int {
    $row = db_row('SELECT id FROM users ORDER BY id LIMIT 1');
    return $row !== null ? (int) $row['id'] : null;
}

try {

// ── C1: table shape ──────────────────────────────────────────
$expectedCols = [
    'id', 'state_token', 'provider', 'initiated_by_user_id',
    'initiated_ip', 'initiated_at', 'expires_at', 'used_at', 'consumed_ip',
];
$c1Errors = [];
try {
    $rows = db_select("SHOW COLUMNS FROM acc_oauth_states");
    $present = array_map(fn($r) => $r['Field'], $rows);
    foreach ($expectedCols as $col) {
        if (!in_array($col, $present, true)) {
            $c1Errors[] = "missing column: {$col}";
        }
    }
} catch (Throwable $e) {
    $c1Errors[] = 'SHOW COLUMNS threw: ' . $e->getMessage();
}
if (empty($c1Errors)) {
    echo "PASS C1  acc_oauth_states has all 9 expected columns\n";
    $pass++;
} else {
    echo "FAIL C1  " . implode('; ', $c1Errors) . "\n";
    $failures[] = 'C1';
}

// ── C2: StateManager class surface ───────────────────────────
$c2Errors = [];
if (!class_exists(StateManager::class)) {
    $c2Errors[] = 'StateManager class not autoloaded under FleetForge\OAuth';
} else {
    $ref = new ReflectionClass(StateManager::class);
    foreach (['generate', 'verifyAndConsume', 'cleanup'] as $m) {
        if (!$ref->hasMethod($m)) {
            $c2Errors[] = "missing method: {$m}";
            continue;
        }
        $rm = $ref->getMethod($m);
        if (!$rm->isPublic() || !$rm->isStatic()) {
            $c2Errors[] = "{$m} must be public static";
        }
    }
    if (!defined(StateManager::class . '::DEFAULT_TTL_SECONDS') ||
        StateManager::DEFAULT_TTL_SECONDS !== 600) {
        $c2Errors[] = 'DEFAULT_TTL_SECONDS missing or != 600';
    }
    if (!defined(StateManager::class . '::CLEANUP_BUFFER_HOURS') ||
        StateManager::CLEANUP_BUFFER_HOURS !== 24) {
        $c2Errors[] = 'CLEANUP_BUFFER_HOURS missing or != 24';
    }
}
if (empty($c2Errors)) {
    echo "PASS C2  StateManager class surface correct (3 statics + 2 constants)\n";
    $pass++;
} else {
    echo "FAIL C2  " . implode('; ', $c2Errors) . "\n";
    $failures[] = 'C2';
}

// ── C3: round-trip ───────────────────────────────────────────
$userId = ff_smoke_pick_user_id();
$c3Errors = [];
try {
    $token = StateManager::generate('quickbooks', 600, $userId, '127.0.0.1');
    $sentinelTokens[] = $token;

    if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
        $c3Errors[] = "generated token not 64-hex (got len=" . strlen($token) . ")";
    }

    $ctx = StateManager::verifyAndConsume($token, 'quickbooks', '127.0.0.1');
    if ($ctx === null) {
        $c3Errors[] = 'verifyAndConsume returned null for a freshly-minted token';
    } else {
        if ($userId !== null && ($ctx['initiated_by_user_id'] ?? null) !== $userId) {
            $c3Errors[] = "initiated_by_user_id mismatch: got " .
                var_export($ctx['initiated_by_user_id'] ?? null, true) . ", want {$userId}";
        }
        if (!isset($ctx['initiated_at']) || $ctx['initiated_at'] === '') {
            $c3Errors[] = 'initiated_at missing from returned context';
        }
        // Confirm type normalisation — int|null, never string.
        if ($ctx['initiated_by_user_id'] !== null && !is_int($ctx['initiated_by_user_id'])) {
            $c3Errors[] = 'initiated_by_user_id not normalised to int';
        }
    }
} catch (Throwable $e) {
    $c3Errors[] = 'round-trip threw: ' . $e->getMessage();
}
if (empty($c3Errors)) {
    echo "PASS C3  generate → verifyAndConsume round-trip preserves user_id context\n";
    $pass++;
} else {
    echo "FAIL C3  " . implode('; ', $c3Errors) . "\n";
    $failures[] = 'C3';
}

// ── C4: single-use enforcement ───────────────────────────────
$c4Errors = [];
try {
    $token = StateManager::generate('quickbooks', 600, $userId, '127.0.0.1');
    $sentinelTokens[] = $token;

    $first  = StateManager::verifyAndConsume($token, 'quickbooks', '127.0.0.1');
    $second = StateManager::verifyAndConsume($token, 'quickbooks', '127.0.0.1');

    if ($first === null) {
        $c4Errors[] = 'first verifyAndConsume returned null (should succeed)';
    }
    if ($second !== null) {
        $c4Errors[] = 'second verifyAndConsume returned non-null (single-use broken)';
    }
} catch (Throwable $e) {
    $c4Errors[] = 'single-use test threw: ' . $e->getMessage();
}
if (empty($c4Errors)) {
    echo "PASS C4  single-use enforced — replayed token returns null\n";
    $pass++;
} else {
    echo "FAIL C4  " . implode('; ', $c4Errors) . "\n";
    $failures[] = 'C4';
}

// ── C5: expiry enforcement ───────────────────────────────────
$c5Errors = [];
try {
    $token = StateManager::generate('quickbooks', 600, $userId, '127.0.0.1');
    $sentinelTokens[] = $token;

    // Backdate expires_at to 1 minute ago — within the 24h buffer
    // so cleanup() won't sweep it, but past TTL so consume rejects.
    db_execute(
        "UPDATE acc_oauth_states SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE state_token = ?",
        [$token]
    );

    $ctx = StateManager::verifyAndConsume($token, 'quickbooks', '127.0.0.1');
    if ($ctx !== null) {
        $c5Errors[] = 'expired token verifyAndConsume returned non-null';
    }
} catch (Throwable $e) {
    $c5Errors[] = 'expiry test threw: ' . $e->getMessage();
}
if (empty($c5Errors)) {
    echo "PASS C5  expired token rejected by verifyAndConsume\n";
    $pass++;
} else {
    echo "FAIL C5  " . implode('; ', $c5Errors) . "\n";
    $failures[] = 'C5';
}

// ── C6: provider mismatch ────────────────────────────────────
// The provider ENUM currently has only 'quickbooks', but
// verifyAndConsume's WHERE clause must still reject any other
// string passed in by callers (future-proofing for new providers).
// MySQL ENUM string comparison: 'stripe' != 'quickbooks' returns
// no rows — exactly what we want.
$c6Errors = [];
try {
    $token = StateManager::generate('quickbooks', 600, $userId, '127.0.0.1');
    $sentinelTokens[] = $token;

    $wrongProvider = StateManager::verifyAndConsume($token, 'stripe', '127.0.0.1');
    if ($wrongProvider !== null) {
        $c6Errors[] = 'wrong-provider verifyAndConsume returned non-null';
    }

    // Confirm the row wasn't side-effected — original provider still works.
    $rightProvider = StateManager::verifyAndConsume($token, 'quickbooks', '127.0.0.1');
    if ($rightProvider === null) {
        $c6Errors[] = 'right-provider lookup failed after wrong-provider attempt (row was side-effected)';
    }
} catch (Throwable $e) {
    $c6Errors[] = 'provider mismatch test threw: ' . $e->getMessage();
}
if (empty($c6Errors)) {
    echo "PASS C6  provider mismatch rejected; original token still consumable\n";
    $pass++;
} else {
    echo "FAIL C6  " . implode('; ', $c6Errors) . "\n";
    $failures[] = 'C6';
}

// ── C7: tamper resistance ────────────────────────────────────
$c7Errors = [];
try {
    $token = StateManager::generate('quickbooks', 600, $userId, '127.0.0.1');
    $sentinelTokens[] = $token;

    // Flip one hex char of the token. Pick a deterministic position
    // (last char) and a hex digit guaranteed to differ.
    $lastChar  = substr($token, -1);
    $replaceTo = ($lastChar === 'a') ? 'b' : 'a';
    $tampered  = substr($token, 0, -1) . $replaceTo;
    if ($tampered === $token) {
        $c7Errors[] = 'tamper helper failed to mutate token';
    }

    $tamperedCtx = StateManager::verifyAndConsume($tampered, 'quickbooks', '127.0.0.1');
    if ($tamperedCtx !== null) {
        $c7Errors[] = 'tampered token verifyAndConsume returned non-null';
    }

    // Confirm the original token still works.
    $originalCtx = StateManager::verifyAndConsume($token, 'quickbooks', '127.0.0.1');
    if ($originalCtx === null) {
        $c7Errors[] = 'original token failed after tamper attempt (row was side-effected)';
    }
} catch (Throwable $e) {
    $c7Errors[] = 'tamper test threw: ' . $e->getMessage();
}
if (empty($c7Errors)) {
    echo "PASS C7  tampered token rejected; original still consumable\n";
    $pass++;
} else {
    echo "FAIL C7  " . implode('; ', $c7Errors) . "\n";
    $failures[] = 'C7';
}

// ── C8: cleanup ──────────────────────────────────────────────
$c8Errors = [];
try {
    // Manually INSERT a stale row 48h past expiry — past the 24h buffer
    // so cleanup() should sweep it. Use a sentinel-prefix token so we
    // can find it again even if cleanup runs concurrently.
    $staleToken = 'TEST-SMOKE-STALE-' . bin2hex(random_bytes(16));
    $sentinelTokens[] = $staleToken;

    db_insert('acc_oauth_states', [
        'state_token'          => $staleToken,
        'provider'             => 'quickbooks',
        'initiated_by_user_id' => $userId,
        'initiated_ip'         => '127.0.0.1',
        'expires_at'           => date('Y-m-d H:i:s', time() - 48 * 3600),
    ]);

    $beforeRow = db_row("SELECT id FROM acc_oauth_states WHERE state_token = ?", [$staleToken]);
    if ($beforeRow === null) {
        $c8Errors[] = 'stale row INSERT failed (not visible before cleanup)';
    }

    $deleted = StateManager::cleanup(24);
    if ($deleted < 1) {
        $c8Errors[] = "cleanup() reported {$deleted} deleted — expected ≥1";
    }

    $afterRow = db_row("SELECT id FROM acc_oauth_states WHERE state_token = ?", [$staleToken]);
    if ($afterRow !== null) {
        $c8Errors[] = 'stale row still present after cleanup(24)';
    }
} catch (Throwable $e) {
    $c8Errors[] = 'cleanup test threw: ' . $e->getMessage();
}
if (empty($c8Errors)) {
    echo "PASS C8  cleanup(24) deletes rows past the 24h forensic buffer\n";
    $pass++;
} else {
    echo "FAIL C8  " . implode('; ', $c8Errors) . "\n";
    $failures[] = 'C8';
}

// ── C9: callback.php + init.php structural ───────────────────
$c9Errors = [];
$callbackPath = realpath(__DIR__ . '/../app/admin/oauth/qbo/callback.php');
$initPath     = realpath(__DIR__ . '/../app/admin/oauth/qbo/init.php');

if ($callbackPath === false || !is_readable($callbackPath)) {
    $c9Errors[] = 'callback.php not found / not readable';
} else {
    $cb = file_get_contents($callbackPath);
    // D-QBO-OAUTH-FIX-2: callback must NOT call require_auth or require_permission.
    // We grep for lines that actually invoke them (parens), not docblock mentions.
    if (preg_match('/^\s*require_auth\s*\(/m', $cb)) {
        $c9Errors[] = 'callback.php still calls require_auth() — D-QBO-OAUTH-FIX-2 violated';
    }
    if (preg_match('/^\s*require_permission\s*\(/m', $cb)) {
        $c9Errors[] = 'callback.php still calls require_permission() — D-QBO-OAUTH-FIX-2 violated';
    }
    if (!str_contains($cb, 'StateManager::verifyAndConsume')) {
        $c9Errors[] = 'callback.php does not invoke StateManager::verifyAndConsume';
    }
    if (!str_contains($cb, 'ff_qbo_lookup_user_name')) {
        $c9Errors[] = 'callback.php missing ff_qbo_lookup_user_name helper';
    }
    // Both audit_log inserts must use the recovered initiator, not current_user_*.
    if (preg_match("/'user_id'\s*=>\s*current_user_id\(\)/", $cb)) {
        $c9Errors[] = "callback.php audit_log still references current_user_id() — should use \$initiatedUserId";
    }
}

if ($initPath === false || !is_readable($initPath)) {
    $c9Errors[] = 'init.php not found / not readable';
} else {
    $init = file_get_contents($initPath);
    if (!str_contains($init, 'StateManager::generate')) {
        $c9Errors[] = 'init.php does not invoke StateManager::generate';
    }
    if (str_contains($init, "\$_SESSION['qbo_oauth_state']")) {
        $c9Errors[] = 'init.php still writes to $_SESSION[qbo_oauth_state]';
    }
    // init is user-initiated — require_auth must remain.
    if (!preg_match('/^\s*require_auth\s*\(/m', $init)) {
        $c9Errors[] = 'init.php no longer calls require_auth() — must remain (init is user-initiated)';
    }
}

if (empty($c9Errors)) {
    echo "PASS C9  callback.php auth-context-free; init.php uses StateManager + retains require_auth\n";
    $pass++;
} else {
    echo "FAIL C9  " . implode('; ', $c9Errors) . "\n";
    $failures[] = 'C9';
}

} finally {
    // ── Self-cleaning: drop every token we touched ────────────────
    if (!empty($sentinelTokens)) {
        try {
            $placeholders = implode(',', array_fill(0, count($sentinelTokens), '?'));
            db_execute(
                "DELETE FROM acc_oauth_states WHERE state_token IN ({$placeholders})",
                $sentinelTokens
            );
        } catch (Throwable $cleanupErr) {
            // Don't fail the smoke on cleanup errors — surface and continue.
            echo "WARN  cleanup of sentinel tokens failed: " . $cleanupErr->getMessage() . "\n";
        }
    }
}

// ── Summary ──────────────────────────────────────────────────
echo "\noauth_state_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures);
    echo "\n";
    exit(1);
}
echo "\n";
exit(0);
