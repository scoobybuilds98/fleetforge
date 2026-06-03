<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Auth & Session Guard
//
// Provides session management and permission enforcement
// for admin pages. Portal uses a separate auth file.
//
// Include pattern (from app/admin/module/page.php):
//   require_once dirname(__DIR__, 2) . '/includes/auth.php';
//   require_auth();
//   require_permission('module', 'view');
// ============================================================

require_once realpath(dirname(__DIR__) . '/config/app.php');

// S-PERM-EXPAND: load the permission registry helpers
// (get_actions_for_module / get_action_description / get_permission_groups).
// auth.php is loaded by every authenticated page + api/bootstrap.php, so
// these free functions are universally available without per-call requires.
require_once FF_ROOT . '/includes/permission_registry.php';

// ============================================================
// Session bootstrap — called once per request
// ============================================================

function _ff_session_start(): void
{
    static $started = false;
    if ($started) return;
    $started = true;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // --- Inactivity timeout ---
    // Enforce the SESSION_LIFETIME even if the cookie is still alive.
    //
    // S-AUTH-FIX: fall through to remember-me restoration after
    // destroying the session. The prior code did `return;` here, which
    // prevented auth_check_remember_me() (below) from ever running on
    // timeout — so users with a valid 30-day ff_remember cookie were
    // forced back to login + MFA every time their session aged out.
    // After destroy(), session_start() in the same request still has a
    // fresh, empty $_SESSION, so the remember-me check below can repopulate it.
    if (isset($_SESSION['ff_last_activity'])) {
        $lifetime = (int) env('SESSION_LIFETIME', 28800);
        if ((time() - (int) $_SESSION['ff_last_activity']) > $lifetime) {
            _ff_session_destroy();
            // session_start() again so auth_check_remember_me() can write
            // into $_SESSION (destroy() unsets $_SESSION and closes the session).
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
        }
    }

    // Refresh the activity timestamp on every request
    if (isset($_SESSION['ff_user'])) {
        $_SESSION['ff_last_activity'] = time();
    }

    // --- Remember-me restoration ---
    // Only attempt if there is no active admin session
    if (!isset($_SESSION['ff_user'])) {
        auth_check_remember_me();
    }
}

// Start the session immediately when auth.php is loaded
_ff_session_start();

// ============================================================
// PUBLIC GUARD FUNCTIONS
// ============================================================

// require_auth() — redirect to login if no valid admin session
// Stores the requested URL so login can redirect back.
function require_auth(): void
{
    if (!current_user()) {
        // Only store redirect URL for GET requests (not form submissions)
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        }
        header('Location: ' . base_url('auth/login'));
        exit;
    }
    // S-PERM-SESSION-REFRESH: auto-refresh the in-session override map if a
    // super_admin has changed this user's overrides since their login. The
    // function early-returns for super_admin (their can() short-circuits)
    // and caches within the request via a static guard — at most one
    // extra SELECT per authenticated page request.
    _ff_check_permission_freshness();
}

// require_auth_api() — return 401 JSON if no valid admin session
function require_auth_api(): void
{
    if (!current_user()) {
        json_error('UNAUTHORIZED', 'Authentication required.', 401);
    }
    // S-PERM-SESSION-REFRESH: same auto-refresh hook on the API side. The
    // static guard inside the function ensures only one DB hit per request
    // even when multiple endpoints chain (rare) or when a page invokes
    // both require_auth() + an inline API call within one PHP process.
    _ff_check_permission_freshness();
}

// require_permission() — 403 if the current user lacks the given permission
// Detects API vs page context via FF_API_CONTEXT constant.
function require_permission(string $module, string $action): void
{
    if (can($module, $action)) return;

    if (defined('FF_API_CONTEXT') && FF_API_CONTEXT === true) {
        json_error('FORBIDDEN', 'You do not have permission to perform this action.', 403);
    }

    http_response_code(403);
    $errorFile = FF_ROOT . '/app/errors/403.php';
    if (file_exists($errorFile)) {
        require $errorFile;
    } else {
        echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
    }
    exit;
}

// require_role() — 403 if the current user is not the specified role
function require_role(string $role): void
{
    $user = current_user();
    if (!$user || ($user['role_slug'] ?? '') !== $role) {
        if (defined('FF_API_CONTEXT') && FF_API_CONTEXT === true) {
            json_error('FORBIDDEN', 'Insufficient role for this action.', 403);
        }

        http_response_code(403);
        $errorFile = FF_ROOT . '/app/errors/403.php';
        if (file_exists($errorFile)) {
            require $errorFile;
        } else {
            echo '<h1>403 Forbidden</h1>';
        }
        exit;
    }
}

// can() — check if the current user has a permission
//
// PERM-1 layered permission resolution:
//   1. super_admin always wins (returns true unconditionally).
//   2. Per-user override (granted=1 → allow, granted=0 → deny).
//      Overrides are loaded into the session at login as
//      $_SESSION['ff_user']['permission_overrides'][module][action] = 0|1
//   3. Fall through to the role default from config/permissions.php.
//
// WHY: storing the resolved override in the session at login keeps can()
// O(1) on every page load — no per-request DB query. The override map
// is refreshed whenever an override row is added/removed/reset via the
// permissions API endpoints (which call _ff_refresh_user_permissions()).
function can(string $module, string $action): bool
{
    $user = current_user();
    if (!$user) return false;

    if (($user['role_slug'] ?? '') === 'super_admin') return true;

    // Per-user override takes precedence over role default.
    $override = $user['permission_overrides'][$module][$action] ?? null;
    if ($override !== null) {
        return (bool) $override;
    }

    return (bool) ($user['permissions'][$module][$action] ?? false);
}

// current_user() — return the ff_user session array, or null
function current_user(): ?array
{
    return $_SESSION['ff_user'] ?? null;
}

// current_user_id() — return the logged-in user's ID, or null
function current_user_id(): ?int
{
    $user = current_user();
    return $user ? (int) $user['id'] : null;
}

// is_super_admin() — true if the current user has the super_admin role
function is_super_admin(): bool
{
    $user = current_user();
    return $user && ($user['role_slug'] ?? '') === 'super_admin';
}

// ============================================================
// SESSION MANAGEMENT — used by login.php, logout.php, accept_invite.php
// ============================================================

// auth_login() — establish an authenticated session for $user
// Call this after credential verification succeeds.
// Regenerates the session ID to prevent fixation [PASS-4].
//
// $user must be a row from the users table joined with user_roles.
// $rememberMe = true sets a 30-day persistent cookie.
function auth_login(array $user, bool $rememberMe = false): void
{
    // Regenerate session ID (prevents session fixation) [PASS-4]
    session_regenerate_id(true);

    // Load this role's permissions from config
    $permissionsConfig = require FF_ROOT . '/config/permissions.php';
    $roleSlug          = $user['role_slug'] ?? '';
    $permissions       = $permissionsConfig[$roleSlug] ?? [];

    // PERM-1: load any per-user permission overrides for this user.
    // Stored as $overrides[module][action] = 0|1 — checked first by can().
    $overrides = _ff_load_user_overrides((int) $user['id']);

    $_SESSION['ff_user'] = [
        'id'                   => (int) $user['id'],
        'name'                 => $user['name'],
        'email'                => $user['email'],
        'role_id'              => (int) $user['role_id'],
        'role_slug'            => $roleSlug,
        'permissions'          => $permissions,
        'permission_overrides' => $overrides,
        // S-PERM-SESSION-REFRESH: identity-based freshness token.
        // _ff_check_permission_freshness() compares permissions_db_ts
        // (the exact users.permissions_updated_at value we last saw) by
        // string identity — avoiding the same-second timing bug of the
        // old strtotime() > comparison.  permissions_loaded_at kept for
        // backwards compat with existing sessions during rollout.
        'permissions_loaded_at' => date('Y-m-d H:i:s'),
        'permissions_db_ts'    => db_row(
            "SELECT permissions_updated_at FROM users WHERE id = ?",
            [(int) $user['id']]
        )['permissions_updated_at'] ?? null,
        'theme'                => $user['theme_preference'] ?? 'dark',
        // PERM-1 Feature 2: per-user display settings (font size + density).
        // Read by header.php to inject inline <style> + body data-density.
        'display_font_size'    => (int) ($user['display_font_size'] ?? 100),
        'display_density'      => $user['display_density'] ?? 'comfortable',
    ];

    $_SESSION['ff_last_activity'] = time();

    // FIX #36: always generate a fresh CSRF token when a session is established.
    // Without this, remember-me restoration would leave csrf_token empty, bypassing
    // the CSRF check in bootstrap.php for the entire window until a page load.
    generate_csrf_token();

    // --- Remember-me cookie (30 days) ---
    if ($rememberMe) {
        $token       = bin2hex(random_bytes(32)); // 64-char hex, cryptographically random
        $tokenHash   = hash('sha256', $token);
        $expiry      = time() + (30 * 24 * 3600);

        // Store the hash in the DB — never the plain token [PASS-4:1.5]
        db_update(
            'users',
            ['remember_token' => $tokenHash],
            'id = ?',
            [(int) $user['id']]
        );

        setcookie(
            'ff_remember',
            $user['id'] . ':' . $token,
            [
                'expires'  => $expiry,
                'path'     => '/' . ltrim(FF_BASE_PATH, '/'),
                'secure'   => APP_ENV === 'production',
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}

// auth_logout() — destroy the session and clear remember-me
function auth_logout(): void
{
    $userId = current_user_id();

    // Clear remember-me token from DB
    if ($userId) {
        db_update('users', ['remember_token' => null], 'id = ?', [$userId]);
    }

    // Clear the remember-me cookie
    setcookie('ff_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/' . ltrim(FF_BASE_PATH, '/'),
        'secure'   => APP_ENV === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    _ff_session_destroy();
}

// auth_check_remember_me() — restore session from a valid remember-me cookie
// Called automatically by _ff_session_start() when no session exists.
function auth_check_remember_me(): void
{
    $cookie = $_COOKIE['ff_remember'] ?? '';
    if ($cookie === '') return;

    // Cookie format: "{user_id}:{plain_token}"
    $parts = explode(':', $cookie, 2);
    if (count($parts) !== 2) {
        _ff_clear_remember_cookie();
        return;
    }

    [$userId, $plainToken] = $parts;
    $userId = clean_int($userId);
    if (!$userId) {
        _ff_clear_remember_cookie();
        return;
    }

    $user = db_row(
        "SELECT u.*, r.slug AS role_slug
         FROM users u
         JOIN user_roles r ON r.id = u.role_id
         WHERE u.id = ? AND u.deleted_at IS NULL AND u.status = 'active'",
        [$userId]
    );

    if (!$user || !$user['remember_token']) {
        _ff_clear_remember_cookie();
        return;
    }

    // Constant-time comparison — prevents timing attacks [PASS-4:1.4]
    $expectedHash = hash('sha256', $plainToken);
    if (!hash_equals($user['remember_token'], $expectedHash)) {
        _ff_clear_remember_cookie();
        return;
    }

    // S-AUTH-FIX: MFA-factor restoration check (D-C, D-D).
    // remember_token validates the password factor only. For users with
    // MFA enabled, we also need to confirm the MFA factor is still blessed
    // for this remember-me cycle. mfa_verified_until is stamped at
    // mfa_challenge.php on TOTP success when "Keep me signed in" was checked.
    //
    //   mfa_enabled = 0  → MFA never required; proceed.
    //   mfa_enabled = 1, mfa_verified_until NULL or expired → restore the
    //     session at password level (D-D), but set ff_mfa_pending so the
    //     next request hits the MFA challenge. ff_remember stays valid; only
    //     the MFA factor is re-verifying.
    //   mfa_enabled = 1, mfa_verified_until in the future → both factors
    //     blessed; restore the full session.
    $mfaEnabled = (int) ($user['mfa_enabled'] ?? 0) === 1;
    $mfaUntil   = $user['mfa_verified_until'] ?? null;
    $mfaValid   = $mfaUntil !== null && strtotime((string) $mfaUntil) > time();

    if ($mfaEnabled && !$mfaValid) {
        // Restore at password level — auth_login still runs so $_SESSION is
        // populated and subsequent require_auth() calls succeed. The MFA
        // gate is enforced by setting ff_mfa_pending below, which the global
        // request bootstrap is expected to detect (see app/auth/mfa_challenge.php).
        auth_login($user, rememberMe: false);
        $_SESSION['ff_mfa_pending'] = [
            'user_id'    => (int) $user['id'],
            'started_at' => time(),
            'remember'   => true, // remember cookie is still valid; stamp on re-verify
        ];
        return;
    }

    // Valid token (both factors) — restore the session
    auth_login($user, rememberMe: false); // re-sets session, does NOT re-issue cookie
}

// ============================================================
// INTERNAL HELPERS
// ============================================================

function _ff_session_destroy(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function _ff_clear_remember_cookie(): void
{
    setcookie('ff_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/' . ltrim(FF_BASE_PATH, '/'),
        'secure'   => APP_ENV === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ============================================================
// PERM-1 — per-user permission override helpers
// ============================================================

/**
 * Load all permission overrides for a single user from the DB.
 *
 * Returns a nested map: $overrides[module][action] = 0|1
 *   - 1 = explicitly granted (even if role denies)
 *   - 0 = explicitly denied  (even if role grants)
 *   - missing entry = no override (fall through to role default)
 *
 * Called by auth_login() and by _ff_refresh_user_permissions() after
 * an override row is added/removed/reset, so the next can() call
 * sees the new state without a re-login.
 */
function _ff_load_user_overrides(int $userId): array
{
    $rows = db_select(
        "SELECT module, action, granted
         FROM user_permission_overrides
         WHERE user_id = ?",
        [$userId]
    );

    $map = [];
    foreach ($rows as $r) {
        $map[$r['module']][$r['action']] = (int) $r['granted'];
    }
    return $map;
}

/**
 * Refresh the in-session permission_overrides map for the CURRENT user.
 *
 * Call this from any endpoint that mutates user_permission_overrides
 * for the user who is currently logged in (or after editing your own
 * overrides) so subsequent can() calls in the same request see the
 * updated state. The PERM-1 admin endpoints (which let a super_admin
 * edit *another* user's overrides) only need to call this if the
 * super_admin happens to be editing themselves — but it's harmless to
 * always call it.
 */
function _ff_refresh_user_permissions(int $userId): void
{
    if (current_user_id() !== $userId) return;
    $_SESSION['ff_user']['permission_overrides'] = _ff_load_user_overrides($userId);
}

/**
 * S-PERM-SESSION-REFRESH: detect + auto-refresh stale permission snapshots.
 *
 * Called from `require_auth()` + `require_auth_api()` at the top of every
 * authenticated request. Compares `users.permissions_updated_at` (DB) to
 * `$_SESSION['ff_user']['permissions_loaded_at']` (session, set at login).
 * If DB is newer, re-loads the override map from DB and stamps the session
 * with the new load timestamp — the current request then sees the fresh
 * overrides without requiring the user to log out + log back in.
 *
 * Performance: static `$checked` flag ensures at most ONE timestamp SELECT
 * per request (handles multiple require_auth* calls within a single PHP
 * process, plus the multiple can() calls inside a single page render).
 * Single-row primary-key lookup; negligible.
 *
 * Skipped paths:
 *   - No active session (`!isset($_SESSION['ff_user']['id'])`): no-op,
 *     protects cron files + CLI scripts that include auth.php.
 *   - super_admin: their can() short-circuits to true regardless of the
 *     override map (D-PERM-REFRESH-4); no point reloading data they
 *     don't consult.
 *
 * Resolves the stale-session UX hazard documented in
 * `audits/2026-05-20_permission_audit.md` §6 — the scenario where a
 * super_admin grants overrides to a user who's already logged in, and the
 * target user sees no change until they log out + log back in.
 */
function _ff_check_permission_freshness(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // No session → no-op. Protects cron/* and CLI scripts that include
    // auth.php without going through a web request.
    if (!isset($_SESSION['ff_user']['id'])) return;

    // super_admin short-circuits can() regardless of overrides (D-PERM-REFRESH-4).
    if (($_SESSION['ff_user']['role_slug'] ?? null) === 'super_admin') return;

    $userId = (int) $_SESSION['ff_user']['id'];
    $row = db_row(
        "SELECT permissions_updated_at FROM users WHERE id = ?",
        [$userId]
    );
    $dbTimestamp = $row['permissions_updated_at'] ?? null;

    // DB never stamped (column NULL) → no override change has happened;
    // nothing to refresh.
    if ($dbTimestamp === null) return;

    // WHY identity comparison, not strtotime() >:
    // Storing the ACTUAL DB timestamp we last saw and comparing by string
    // identity avoids the same-second timing bug where both the override
    // save and the session login happen within the same calendar second —
    // `strtotime(T) > strtotime(T)` is false, so the session would never
    // refresh. Comparing the exact string value we last read means ANY
    // change (even same-second) is detected reliably.
    //
    // Fall back to the old time-comparison for sessions that pre-date this
    // change (they store permissions_loaded_at, not permissions_db_ts).
    $sessionDbTs = $_SESSION['ff_user']['permissions_db_ts'] ?? null;

    if ($sessionDbTs !== null) {
        // New path: compare exact DB timestamp we last stored in session.
        $isStale = ($sessionDbTs !== $dbTimestamp);
    } else {
        // Legacy path: pre-existing sessions only have permissions_loaded_at.
        $sessionLoadedAt = $_SESSION['ff_user']['permissions_loaded_at'] ?? null;
        $isStale = ($sessionLoadedAt === null)
                || (strtotime($dbTimestamp) > strtotime($sessionLoadedAt));
    }

    if (!$isStale) return;

    // Refresh override map and store the exact DB timestamp so the next
    // request uses identity comparison (not time comparison).
    $_SESSION['ff_user']['permission_overrides'] = _ff_load_user_overrides($userId);
    $_SESSION['ff_user']['permissions_db_ts']    = $dbTimestamp;
    $_SESSION['ff_user']['permissions_loaded_at'] = date('Y-m-d H:i:s'); // keep for compat
}
