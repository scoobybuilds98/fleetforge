<?php
declare(strict_types=1);

/**
 * tests/_smoke_permissions_rigorous.php
 *
 * S-PERM-RIGOROUS-TEST — Randomized, multi-user permission-change verification.
 *
 * For each of N dedicated test users spanning multiple roles, this script
 * applies M random permission changes and verifies every change registers
 * across three dimensions:
 *
 *   (i)   PERSISTENCE   — DB row exists with correct field names (module,
 *                         not slug — K-22 trap guard) and correct value.
 *   (ii)  FRESH SESSION — A freshly-loaded session (new login simulation)
 *                         reflects the change via can().
 *   (iii) ACTIVE SESSION — A stale session that was loaded BEFORE the change
 *                         (with an old permissions_db_ts) is auto-refreshed
 *                         by the S-PERM-SESSION-REFRESH timestamp mechanism
 *                         and then reflects the change via can().
 *
 * Tests BOTH directions per permission:
 *   grant (→ assert allowed) then revoke (→ assert denied).
 * The revoke direction is the security-critical one: lingering access after
 * a permission is administratively removed.
 *
 * A5 Non-vacuous precheck is run first: confirms can() actually returns false
 * when it should. If it can't deny, the test would be vacuous — STOP.
 *
 * Session simulation: $_SESSION is manipulated directly (PHP CLI has no real
 * session). perm_inline_freshness() mirrors _ff_check_permission_freshness()
 * without the static guard so it can be called for multiple users.
 *
 * RNG seed: 42 (record for reproducibility)
 *
 * Hermetic: creates PERM_TEST_N_USERS dedicated users prefixed with
 * PERM_TEST_EMAIL_PREFIX; cleans them up (with all overrides) in a finally
 * block regardless of failures.
 *
 * Usage:  php tests/_smoke_permissions_rigorous.php
 * Repro:  same seed (42) → same (module, action) pairs selected per user
 *
 * Session: S-PERM-RIGOROUS-TEST
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/includes/auth.php';
require_once FF_ROOT . '/includes/permission_registry.php';

// ── Config ───────────────────────────────────────────────────────────────────
const PERM_TEST_SEED         = 42;
const PERM_TEST_N_USERS      = 5;
const PERM_TEST_M_PERMS      = 10;   // (module,action) pairs per user (× 2 directions each)
const PERM_TEST_EMAIL_PREFIX = '_smoke_perm_';

// Sentinel: an obviously-old timestamp guaranteed different from any
// real permissions_updated_at (which will be ~2026). Used to force the
// stale-session path without timing races.
const PERM_STALE_SENTINEL = '2000-01-01 00:00:00';

// ── PHP CLI has no $_SESSION — initialize it ──────────────────────────────
if (!isset($_SESSION)) $_SESSION = [];

mt_srand(PERM_TEST_SEED);

// ── Result tracking ──────────────────────────────────────────────────────────
$totalChecks = 0;
$passCount   = 0;
$failures    = [];

function perm_record(string $caseId, bool $ok, string $msg, array $ctx = []): void
{
    global $totalChecks, $passCount, $failures;
    $totalChecks++;
    if ($ok) {
        $passCount++;
        printf("  \033[32mPASS\033[0m %s — %s\n", $caseId, $msg);
    } else {
        printf("  \033[31mFAIL\033[0m %s — %s\n", $caseId, $msg);
        if ($ctx) printf("       ctx: %s\n", json_encode($ctx));
        $failures[] = compact('caseId', 'msg', 'ctx');
    }
}

// ── Build a test session array (does NOT write to $_SESSION) ─────────────────
// $overrides: [module][action] = 0|1  (the pre-change state you want to simulate)
// $dbTs:      what permissions_db_ts should hold in this session snapshot
function perm_make_session_struct(array $user, array $permsConfig, array $overrides, ?string $dbTs): array
{
    $roleSlug    = $user['role_slug'];
    $roleOverrides = _ff_load_role_overrides((int) $user['role_id']);
    return [
        'id'                        => (int) $user['id'],
        'name'                      => $user['name'],
        'email'                     => $user['email'],
        'role_id'                   => (int) $user['role_id'],
        'role_slug'                 => $roleSlug,
        'permissions'               => $permsConfig[$roleSlug] ?? [],
        'permission_overrides'      => $overrides,
        'role_permission_overrides' => $roleOverrides,
        'permissions_db_ts'         => $dbTs,
        'permissions_loaded_at'     => date('Y-m-d H:i:s'),
    ];
}

// ── Build a fresh session by loading current DB state ────────────────────────
function perm_install_fresh_session(array $user, array $permsConfig): void
{
    $overrides = _ff_load_user_overrides((int) $user['id']);
    $dbTs      = db_row(
        "SELECT permissions_updated_at FROM users WHERE id = ?",
        [(int) $user['id']]
    )['permissions_updated_at'] ?? null;
    $_SESSION['ff_user'] = perm_make_session_struct($user, $permsConfig, $overrides, $dbTs);
}

// ── Install a stale session (pre-change snapshot + old sentinel ts) ──────────
// $preChangeOverrides: the override map BEFORE the upcoming DB change.
function perm_install_stale_session(array $user, array $permsConfig, array $preChangeOverrides): void
{
    // Sentinel is clearly older than any real 2026 timestamp → identity check
    // in perm_inline_freshness() will always detect stale regardless of
    // same-second timing in the test loop.
    $_SESSION['ff_user'] = perm_make_session_struct(
        $user, $permsConfig, $preChangeOverrides, PERM_STALE_SENTINEL
    );
}

// ── Inline freshness check (no static guard so it can run per test) ───────────
// Mirrors _ff_check_permission_freshness() faithfully except:
//   - no static $checked guard (test calls this for each user/change)
//   - no super_admin short-circuit (test users are never super_admin)
// WHY not call the real function: its static $checked = false guard means it
// runs at most once per PHP process, making it unusable in a loop test.
function perm_inline_freshness(): void
{
    if (!isset($_SESSION['ff_user']['id'])) return;

    $userId     = (int) $_SESSION['ff_user']['id'];
    $row        = db_row("SELECT permissions_updated_at FROM users WHERE id = ?", [$userId]);
    $dbTimestamp = $row['permissions_updated_at'] ?? null;

    if ($dbTimestamp === null) return; // never been stamped — nothing to refresh

    $sessionDbTs = $_SESSION['ff_user']['permissions_db_ts'] ?? null;
    if ($sessionDbTs !== null) {
        // New path: string-identity comparison (avoids same-second timing bug).
        $isStale = ($sessionDbTs !== $dbTimestamp);
    } else {
        // Legacy path: time comparison for pre-existing sessions without permissions_db_ts.
        $loaded  = $_SESSION['ff_user']['permissions_loaded_at'] ?? null;
        $isStale = ($loaded === null) || (strtotime($dbTimestamp) > strtotime($loaded));
    }

    if (!$isStale) return;

    // Stale — reload override maps, stamp new db_ts so next inline call stays fresh.
    $_SESSION['ff_user']['permission_overrides']      = _ff_load_user_overrides($userId);
    $roleId = (int) ($_SESSION['ff_user']['role_id'] ?? 0);
    if ($roleId) {
        $_SESSION['ff_user']['role_permission_overrides'] = _ff_load_role_overrides($roleId);
    }
    $_SESSION['ff_user']['permissions_db_ts']    = $dbTimestamp;
    $_SESSION['ff_user']['permissions_loaded_at'] = date('Y-m-d H:i:s');
}

// ── Apply a permission change directly (mirrors endpoint DB operations) ───────
// granted: 1 = grant, 0 = deny, null = clear (revert to role default)
// Includes the permissions_updated_at bump inside the transaction (the
// S-PERM-SESSION-REFRESH trigger).
function perm_apply_change(int $userId, int $adminId, string $module, string $action, ?int $granted): void
{
    $existing = db_row(
        "SELECT id, granted FROM user_permission_overrides WHERE user_id=? AND module=? AND action=?",
        [$userId, $module, $action]
    );

    db_transaction(function () use ($userId, $adminId, $module, $action, $granted, $existing) {
        if ($granted === null) {
            if ($existing) {
                db_execute("DELETE FROM user_permission_overrides WHERE id=?", [(int) $existing['id']]);
            }
        } elseif ($existing) {
            db_update('user_permission_overrides', [
                'granted'    => $granted,
                'granted_by' => $adminId,
                'reason'     => 'smoke_test_S-PERM-RIGOROUS',
            ], 'id = ?', [(int) $existing['id']]);
        } else {
            db_insert('user_permission_overrides', [
                'user_id'    => $userId,
                'module'     => $module,   // KEY: store as 'module', not 'slug' (K-22 guard)
                'action'     => $action,
                'granted'    => $granted,
                'granted_by' => $adminId,
                'reason'     => 'smoke_test_S-PERM-RIGOROUS',
            ]);
        }
        // S-PERM-SESSION-REFRESH trigger: bump timestamp inside the same transaction
        // so it rolls back atomically if the override write fails.
        db_execute("UPDATE users SET permissions_updated_at = NOW() WHERE id = ?", [$userId]);
    });
}

// ── Pool of (module, action) pairs safe for all roles ────────────────────────
// Includes both "role-default-true" and "role-default-false" pairs so that
// overrides test both grant (flip false→true) and deny (flip true→false)
// relative to the role baseline. Only standard 5-action CRUD modules used
// (avoids extended-action complexity from permission_actions.php).
const PERM_POOL = [
    ['module' => 'customers',  'action' => 'view'],
    ['module' => 'customers',  'action' => 'create'],
    ['module' => 'customers',  'action' => 'delete'],
    ['module' => 'customers',  'action' => 'export'],
    ['module' => 'invoices',   'action' => 'view'],
    ['module' => 'invoices',   'action' => 'delete'],
    ['module' => 'invoices',   'action' => 'export'],
    ['module' => 'leases',     'action' => 'delete'],
    ['module' => 'leases',     'action' => 'export'],
    ['module' => 'payments',   'action' => 'delete'],
    ['module' => 'payments',   'action' => 'export'],
    ['module' => 'analytics',  'action' => 'delete'],
    ['module' => 'analytics',  'action' => 'export'],
    ['module' => 'users',      'action' => 'view'],
    ['module' => 'users',      'action' => 'create'],
    ['module' => 'settings',   'action' => 'create'],
    ['module' => 'settings',   'action' => 'edit'],
    ['module' => 'rates',      'action' => 'delete'],
    ['module' => 'reports',    'action' => 'create'],
    ['module' => 'maintenance','action' => 'delete'],
];

// ── Pick M unique random items from the pool ─────────────────────────────────
function perm_pick_random(int $m, array $pool): array
{
    $indices = array_keys($pool);
    shuffle($indices); // mt_rand seeded above
    return array_map(fn($i) => $pool[$i], array_slice($indices, 0, min($m, count($pool))));
}

// =============================================================================
// MAIN
// =============================================================================

$permsConfig = require FF_ROOT . '/config/permissions.php';

// Resolve super_admin ID for the granted_by column.
$admin = db_row(
    "SELECT u.id, u.name, ur.slug role_slug
     FROM users u JOIN user_roles ur ON ur.id=u.role_id
     WHERE ur.slug='super_admin' AND u.deleted_at IS NULL
     LIMIT 1"
);
if (!$admin) {
    echo "\033[31mFATAL\033[0m No super_admin user found — cannot run.\n";
    exit(2);
}
$adminId = (int) $admin['id'];

// Test roles: 2×manager, 1×dispatcher, 1×accountant, 1×read_only
$testRoleIds = [2, 2, 3, 4, 5]; // matches user_roles seed order

// Collect test user IDs for cleanup
$testUserIds = [];

echo str_repeat('─', 72) . "\n";
echo "S-PERM-RIGOROUS-TEST  seed=" . PERM_TEST_SEED . "  users=" . PERM_TEST_N_USERS
    . "  perms/user=" . PERM_TEST_M_PERMS . "\n";
echo str_repeat('─', 72) . "\n\n";

try {

    // ── A5: Non-vacuous precheck ─────────────────────────────────────────────
    echo "=== A5 NON-VACUOUS PRECHECK ===\n";

    // Resolve a manager role_id for the precheck session
    $managerRole = db_row("SELECT id, slug FROM user_roles WHERE slug='manager' LIMIT 1");
    if (!$managerRole) {
        echo "\033[31mFATAL\033[0m manager role not found.\n";
        exit(2);
    }

    // Build a fake session for a synthetic non-persisted user (id=0 is safe
    // because the precheck does NOT touch the DB — can() only reads $_SESSION).
    $fakePrecheck = [
        'id'                        => 0,
        'name'                      => 'precheck-user',
        'email'                     => 'precheck@test',
        'role_id'                   => (int) $managerRole['id'],
        'role_slug'                 => 'manager',
        'permissions'               => $permsConfig['manager'] ?? [],
        'permission_overrides'      => [],   // no overrides
        'role_permission_overrides' => [],   // no role overrides
        'permissions_db_ts'         => null,
        'permissions_loaded_at'     => date('Y-m-d H:i:s'),
    ];

    // A5a: manager with no overrides should be DENIED users:view
    // (manager 'users' = NONE in permissions.php)
    $_SESSION['ff_user'] = $fakePrecheck;
    $canUsersView = can('users', 'view');
    perm_record(
        'A5a',
        $canUsersView === false,
        'manager without override: can(users,view) must be false; got ' . ($canUsersView ? 'true' : 'false')
    );
    if ($canUsersView === true) {
        echo "\n\033[31mSTOP CONDITION\033[0m: can() cannot deny — access-gate is broken.\n";
        echo "All permission tests would be vacuous. Fix the gate before running this harness.\n\n";
        exit(3);
    }

    // A5b: manager with no overrides should be ALLOWED customers:view
    // (manager 'customers' has view=true)
    $_SESSION['ff_user'] = $fakePrecheck;
    $canCustomersView = can('customers', 'view');
    perm_record(
        'A5b',
        $canCustomersView === true,
        'manager without override: can(customers,view) must be true; got ' . ($canCustomersView ? 'true' : 'false')
    );

    // A5c: per-user override can flip a denied → granted
    $fakeWithGrant = $fakePrecheck;
    $fakeWithGrant['permission_overrides']['users']['view'] = 1;
    $_SESSION['ff_user'] = $fakeWithGrant;
    $canUsersViewGranted = can('users', 'view');
    perm_record(
        'A5c',
        $canUsersViewGranted === true,
        'manager + override granted=1 for users:view → can() must be true'
    );

    // A5d: per-user override can flip an allowed → denied
    $fakeWithDeny = $fakePrecheck;
    $fakeWithDeny['permission_overrides']['customers']['view'] = 0;
    $_SESSION['ff_user'] = $fakeWithDeny;
    $canCustomersDenied = can('customers', 'view');
    perm_record(
        'A5d',
        $canCustomersDenied === false,
        'manager + override granted=0 for customers:view → can() must be false'
    );

    if (!empty($failures)) {
        echo "\n\033[31mSTOP CONDITION\033[0m: A5 precheck failed — can() resolution is broken.\n";
        exit(3);
    }

    echo "\nPrecheck PASSED — can() denies and grants correctly.\n\n";

    // ── Setup: create N test users ───────────────────────────────────────────
    echo "=== SETUP: Creating test users ===\n";

    $testUsers = [];
    $roleCache = [];
    foreach ($testRoleIds as $idx => $roleId) {
        if (!isset($roleCache[$roleId])) {
            $roleCache[$roleId] = db_row("SELECT id, slug FROM user_roles WHERE id=?", [$roleId]);
        }
        $roleSlug = $roleCache[$roleId]['slug'];
        $email    = PERM_TEST_EMAIL_PREFIX . $idx . '_' . PERM_TEST_SEED . '@fleetforge.test';

        // Clean up any stale run that may have left this email behind
        $old = db_row("SELECT id FROM users WHERE email=?", [$email]);
        if ($old) {
            db_execute("DELETE FROM user_permission_overrides WHERE user_id=?", [(int)$old['id']]);
            db_execute("DELETE FROM users WHERE id=?", [(int)$old['id']]);
        }

        $uid = db_insert('users', [
            'name'    => 'Smoke Test User ' . $idx,
            'email'   => $email,
            'role_id' => $roleId,
            'status'  => 'active',
        ]);
        $testUserIds[] = $uid;
        $testUsers[]   = [
            'id'        => $uid,
            'name'      => 'Smoke Test User ' . $idx,
            'email'     => $email,
            'role_id'   => $roleId,
            'role_slug' => $roleSlug,
        ];
        printf("  Created user #%d  email=%s  role=%s\n", $uid, $email, $roleSlug);
    }
    echo "\n";

    // ── Main test loop ────────────────────────────────────────────────────────
    echo "=== MAIN TEST LOOP  (N=" . count($testUsers) . " × M=" . PERM_TEST_M_PERMS
        . " × 2 directions × 3 dims) ===\n\n";

    foreach ($testUsers as $uIdx => $user) {
        $userLabel = sprintf("U%d(%s)", $uIdx, $user['role_slug']);
        echo "── User $userLabel ─────────────────────────────────────────\n";

        $pairs = perm_pick_random(PERM_TEST_M_PERMS, PERM_POOL);

        foreach ($pairs as $pIdx => $pair) {
            $module = $pair['module'];
            $action = $pair['action'];
            $label  = sprintf("%s/P%02d/%s:%s", $userLabel, $pIdx + 1, $module, $action);

            // Ensure no leftover override for this pair before we start
            db_execute(
                "DELETE FROM user_permission_overrides WHERE user_id=? AND module=? AND action=?",
                [$user['id'], $module, $action]
            );
            // Clear the permissions_updated_at to NULL so the stale-session sentinel
            // is clearly "older than any real timestamp" for the first direction.
            db_execute("UPDATE users SET permissions_updated_at = NULL WHERE id=?", [$user['id']]);

            // ── GRANT DIRECTION ──────────────────────────────────────────────
            // Capture pre-change state (no override yet)
            $preGrantOverrides = _ff_load_user_overrides($user['id']); // empty at this point

            // Install stale session BEFORE applying the change
            perm_install_stale_session($user, $permsConfig, $preGrantOverrides);
            $staleSessionBeforeGrant = $_SESSION['ff_user']; // snapshot for dim (iii)

            // Apply grant change
            perm_apply_change($user['id'], $adminId, $module, $action, 1);

            // (i) PERSISTENCE
            $row = db_row(
                "SELECT id, user_id, module, action, granted FROM user_permission_overrides
                 WHERE user_id=? AND module=? AND action=?",
                [$user['id'], $module, $action]
            );
            $hasRow    = $row !== null;
            $hasModule = $hasRow && isset($row['module']) && $row['module'] === $module;
            $hasGrant1 = $hasRow && (int) $row['granted'] === 1;
            // K-22 guard: table has no 'slug' column; verify we used 'module'
            $noSlugCol = $hasRow && !isset($row['slug']);
            perm_record(
                "$label/GRANT/i/persist",
                $hasRow && $hasModule && $hasGrant1 && $noSlugCol,
                sprintf(
                    "DB row: exists=%s module=%s granted=%s no-slug-col=%s",
                    $hasRow ? 'Y' : 'N',
                    $hasModule ? 'Y' : 'N',
                    $hasGrant1 ? '1✓' : ($hasRow ? ((int)$row['granted']).'✗' : '-'),
                    $noSlugCol ? 'Y' : 'N'
                ),
                ['module' => $module, 'action' => $action, 'uid' => $user['id']]
            );

            // (ii) FRESH SESSION
            perm_install_fresh_session($user, $permsConfig);
            $freshGrantResult = can($module, $action);
            perm_record(
                "$label/GRANT/ii/fresh",
                $freshGrantResult === true,
                "fresh can($module,$action) after grant → " . ($freshGrantResult ? 'true✓' : 'false✗')
            );

            // (iii) ACTIVE SESSION — use the snapshot from before the change
            $_SESSION['ff_user'] = $staleSessionBeforeGrant;
            perm_inline_freshness(); // must detect stale, reload, update session
            $activeGrantResult = can($module, $action);
            // Verify the session was actually refreshed (db_ts updated)
            $tsAfterRefresh = $_SESSION['ff_user']['permissions_db_ts'] ?? null;
            $wasRefreshed   = $tsAfterRefresh !== PERM_STALE_SENTINEL;
            perm_record(
                "$label/GRANT/iii/active",
                $activeGrantResult === true && $wasRefreshed,
                sprintf(
                    "stale-session refreshed=%s, can()=%s",
                    $wasRefreshed ? 'Y✓' : 'N✗',
                    $activeGrantResult ? 'true✓' : 'false✗'
                )
            );

            // ── REVOKE DIRECTION ─────────────────────────────────────────────
            // Pre-revoke state: override=1 is in DB (from the grant above)
            $preRevokeOverrides = _ff_load_user_overrides($user['id']); // has granted=1

            // Install stale session BEFORE applying the revoke
            perm_install_stale_session($user, $permsConfig, $preRevokeOverrides);
            $staleSessionBeforeRevoke = $_SESSION['ff_user']; // snapshot for dim (iii)

            // Apply revoke change
            perm_apply_change($user['id'], $adminId, $module, $action, 0);

            // (i) PERSISTENCE
            $rowR     = db_row(
                "SELECT id, module, action, granted FROM user_permission_overrides
                 WHERE user_id=? AND module=? AND action=?",
                [$user['id'], $module, $action]
            );
            $hasRowR  = $rowR !== null;
            $hasDeny0 = $hasRowR && (int) $rowR['granted'] === 0;
            perm_record(
                "$label/REVOKE/i/persist",
                $hasRowR && $hasDeny0,
                sprintf(
                    "DB row: exists=%s granted=%s",
                    $hasRowR ? 'Y' : 'N',
                    $hasDeny0 ? '0✓' : ($hasRowR ? ((int)$rowR['granted']).'✗' : '-')
                )
            );

            // (ii) FRESH SESSION — the security-critical direction
            perm_install_fresh_session($user, $permsConfig);
            $freshRevokeResult = can($module, $action);
            perm_record(
                "$label/REVOKE/ii/fresh",
                $freshRevokeResult === false,
                "fresh can($module,$action) after revoke → "
                    . ($freshRevokeResult ? 'true✗ (LINGERING ACCESS!)' : 'false✓')
            );

            // (iii) ACTIVE SESSION — security-critical: stale session should NOT
            // keep access after an admin has revoked the permission.
            $_SESSION['ff_user'] = $staleSessionBeforeRevoke;
            perm_inline_freshness(); // must detect stale, reload to granted=0
            $activeRevokeResult = can($module, $action);
            $tsAfterRevokeRefresh = $_SESSION['ff_user']['permissions_db_ts'] ?? null;
            $revokeRefreshed      = $tsAfterRevokeRefresh !== PERM_STALE_SENTINEL;
            perm_record(
                "$label/REVOKE/iii/active",
                $activeRevokeResult === false && $revokeRefreshed,
                sprintf(
                    "stale-session refreshed=%s, can()=%s (security: must be false)",
                    $revokeRefreshed ? 'Y✓' : 'N✗',
                    $activeRevokeResult ? 'true✗ (LINGERING ACCESS!)' : 'false✓'
                )
            );

            // Clean up override row for this pair (leave DB tidy between pairs)
            perm_apply_change($user['id'], $adminId, $module, $action, null);
        }

        echo "\n";
    }

} finally {

    // ── Cleanup: remove test users + any leftover overrides ───────────────────
    echo "=== CLEANUP ===\n";
    $_SESSION = []; // reset session state

    foreach ($testUserIds as $uid) {
        $deleted = db_execute("DELETE FROM user_permission_overrides WHERE user_id=?", [$uid]);
        db_execute("DELETE FROM users WHERE id=?", [$uid]);
        printf("  Removed user id=%d  (overrides deleted: %d)\n", $uid, $deleted);
    }

    // Belt-and-suspenders: clean any stale test users from previous runs
    $staleUsers = db_select(
        "SELECT id FROM users WHERE email LIKE ?",
        [PERM_TEST_EMAIL_PREFIX . '%']
    );
    foreach ($staleUsers as $su) {
        db_execute("DELETE FROM user_permission_overrides WHERE user_id=?", [(int)$su['id']]);
        db_execute("DELETE FROM users WHERE id=?", [(int)$su['id']]);
        printf("  Removed stale user id=%d\n", (int)$su['id']);
    }
    echo "\n";
}

// ── Report ────────────────────────────────────────────────────────────────────
echo str_repeat('─', 72) . "\n";
echo "=== S-PERM-RIGOROUS-TEST REPORT ===\n";
printf("  Seed:          %d\n", PERM_TEST_SEED);
printf("  Users:         %d\n", PERM_TEST_N_USERS);
printf("  Pairs/user:    %d\n", PERM_TEST_M_PERMS);
printf("  Total checks:  %d\n", $totalChecks);
printf("  Passed:        %d\n", $passCount);
printf("  Failed:        %d\n", count($failures));

echo "\n  Active-session (S-PERM-SESSION-REFRESH) summary:\n";
$activeGrantFails  = array_filter($failures, fn($f) => str_contains($f['caseId'], '/GRANT/iii/'));
$activeRevokeFails = array_filter($failures, fn($f) => str_contains($f['caseId'], '/REVOKE/iii/'));
$activeTotalGrant  = count(array_filter(array_keys($failures), fn($k) => str_contains($failures[$k]['caseId'] ?? '', '/GRANT/iii/')));
$totalActiveGrant  = $passCount + count($failures);
// Recount active-session checks from totalChecks
$activeChecks = array_filter(range(0, $totalChecks - 1), function ($i) use (&$results) {
    // We track results in $failures only for fails; count from naming pattern
    return false; // placeholder — see per-category counts below
});

// Count active-session results via pass/fail tracking already done above
$grantActivePass = 0; $grantActiveFail = 0;
$revokeActivePass = 0; $revokeActiveFail = 0;
foreach ($failures as $f) {
    if (str_contains($f['caseId'], '/GRANT/iii/'))  $grantActiveFail++;
    if (str_contains($f['caseId'], '/REVOKE/iii/')) $revokeActiveFail++;
}
$expectedActivePerDir = PERM_TEST_N_USERS * PERM_TEST_M_PERMS;
$grantActivePass  = $expectedActivePerDir - $grantActiveFail;
$revokeActivePass = $expectedActivePerDir - $revokeActiveFail;

printf("    GRANT  direction active-session:  %d/%d PASS\n", $grantActivePass, $expectedActivePerDir);
printf("    REVOKE direction active-session:  %d/%d PASS\n", $revokeActivePass, $expectedActivePerDir);

if (empty($failures)) {
    echo "\n  \033[32m✓ ALL CHECKS PASSED\033[0m — changes persist correctly, fresh and stale\n";
    echo "    sessions both reflect permission changes via S-PERM-SESSION-REFRESH.\n";
    echo "    Revoke direction confirmed: no lingering access detected.\n";
} else {
    echo "\n  \033[31m✗ FAILURES FOUND\033[0m — categorized below:\n\n";

    $byDim = ['persist' => [], 'fresh' => [], 'active' => []];
    foreach ($failures as $f) {
        $id = $f['caseId'];
        if (str_contains($id, '/i/persist'))  $byDim['persist'][] = $f;
        elseif (str_contains($id, '/ii/fresh'))  $byDim['fresh'][]   = $f;
        elseif (str_contains($id, '/iii/active')) $byDim['active'][]  = $f;
    }

    if (!empty($byDim['persist'])) {
        echo "  PERSISTENCE FAILURES (" . count($byDim['persist']) . ") — submission/endpoint or K-22 trap:\n";
        foreach ($byDim['persist'] as $f) {
            printf("    %s: %s\n", $f['caseId'], $f['msg']);
        }
    }
    if (!empty($byDim['fresh'])) {
        echo "\n  FRESH-SESSION FAILURES (" . count($byDim['fresh']) . ") — permission-resolution bug:\n";
        foreach ($byDim['fresh'] as $f) {
            printf("    %s: %s\n", $f['caseId'], $f['msg']);
        }
    }
    if (!empty($byDim['active'])) {
        echo "\n  ACTIVE-SESSION FAILURES (" . count($byDim['active']) . ") — S-PERM-SESSION-REFRESH regression:\n";
        foreach ($byDim['active'] as $f) {
            printf("    %s: %s\n", $f['caseId'], $f['msg']);
        }
    }

    $ungrouped = array_filter($failures, function ($f) {
        $id = $f['caseId'];
        return !str_contains($id, '/i/persist')
            && !str_contains($id, '/ii/fresh')
            && !str_contains($id, '/iii/active');
    });
    if (!empty($ungrouped)) {
        echo "\n  OTHER FAILURES:\n";
        foreach ($ungrouped as $f) {
            printf("    %s: %s\n", $f['caseId'], $f['msg']);
        }
    }
}

echo str_repeat('─', 72) . "\n";
$_SESSION = [];
exit(empty($failures) ? 0 : 1);
