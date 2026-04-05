<?php
declare(strict_types=1);

/**
 * app/portal/includes/auth.php
 *
 * Portal session guard — completely separate from admin auth.
 * Uses session key 'ff_portal_user' (admin uses 'ff_user').
 *
 * Include pattern (from app/portal/module/page.php):
 *   require_once dirname(__DIR__) . '/includes/auth.php';
 *   require_portal_auth();
 *
 * Security:
 *   - 24-hour session timeout (longer than admin — less frequent use)
 *   - Same cookie_secure/httponly/samesite as admin (set in config/app.php)
 *   - Portal queries MUST always filter by portal_customer_id() (Trap 8)
 */

require_once dirname(__DIR__, 3) . '/config/app.php';

// ============================================================
// Session bootstrap — starts the shared PHP session
// The session cookie is shared with admin ('ff_session')
// but portal data lives under a separate key ('ff_portal_user').
// ============================================================

function _portal_session_start(): void
{
    static $started = false;
    if ($started) return;
    $started = true;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // --- Inactivity timeout (24 hours for portal) ---
    if (isset($_SESSION['ff_portal_last_activity'])) {
        $lifetime = 86400; // 24 hours
        if ((time() - (int) $_SESSION['ff_portal_last_activity']) > $lifetime) {
            _portal_session_clear();
            return;
        }
    }

    // Refresh activity timestamp
    if (isset($_SESSION['ff_portal_user'])) {
        $_SESSION['ff_portal_last_activity'] = time();
    }

    // --- Remember-me restoration for portal ---
    if (!isset($_SESSION['ff_portal_user'])) {
        _portal_check_remember_me();
    }
}

// Start session immediately
_portal_session_start();

// ============================================================
// PUBLIC GUARD FUNCTIONS
// ============================================================

/**
 * require_portal_auth() — redirect to portal login if not authenticated.
 * Stores requested URL for post-login redirect.
 */
function require_portal_auth(): void
{
    if (!portal_user()) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $_SESSION['portal_redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        }
        header('Location: ' . base_url('portal/auth/login'));
        exit;
    }

    // WHY: verify the customer is still active — prevents continued access
    // after an admin deactivates the customer account mid-session.
    $cust = db_row(
        "SELECT status FROM customers WHERE id = ? AND deleted_at IS NULL",
        [portal_customer_id()]
    );
    if (!$cust || !in_array($cust['status'], ['active', 'pending', 'credit_hold'], true)) {
        _portal_session_clear();
        $_SESSION['portal_auth_flash'] = 'Your account has been suspended. Please contact support.';
        header('Location: ' . base_url('portal/auth/login'));
        exit;
    }
}

/**
 * portal_user() — return the portal session array or null.
 */
function portal_user(): ?array
{
    return $_SESSION['ff_portal_user'] ?? null;
}

/**
 * portal_user_id() — return the portal user's ID or null.
 */
function portal_user_id(): ?int
{
    $u = portal_user();
    return $u ? (int) $u['id'] : null;
}

/**
 * portal_customer_id() — return the logged-in customer's ID.
 * EVERY portal query MUST use this for data isolation (Trap 8).
 */
function portal_customer_id(): int
{
    $u = portal_user();
    return $u ? (int) $u['customer_id'] : 0;
}

/**
 * portal_is_primary() — true if this is the primary account holder.
 * Primary users can manage sub-users.
 */
function portal_is_primary(): bool
{
    $u = portal_user();
    return $u && !empty($u['is_primary']);
}

// ============================================================
// SESSION MANAGEMENT
// ============================================================

/**
 * portal_login() — establish a portal session.
 * $user must be a row from portal_users joined with customer info.
 */
function portal_login(array $user, bool $rememberMe = false): void
{
    // Regenerate session ID (prevents session fixation)
    session_regenerate_id(true);

    $_SESSION['ff_portal_user'] = [
        'id'            => (int) $user['id'],
        'customer_id'   => (int) $user['customer_id'],
        'name'          => $user['name'],
        'email'         => $user['email'],
        'is_primary'    => (bool) ($user['is_primary'] ?? false),
        'company_name'  => $user['company_name'] ?? '',
    ];

    $_SESSION['ff_portal_last_activity'] = time();

    // Generate CSRF token for portal forms
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Update last_login fields
    db_update('portal_users', [
        'last_login_at' => date('Y-m-d H:i:s'),
        'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'login_attempts' => 0,
        'locked_until'   => null,
    ], 'id = ?', [(int) $user['id']]);

    // Remember-me cookie (30 days)
    if ($rememberMe) {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiry    = time() + (30 * 24 * 3600);

        db_update('portal_users', [
            'invite_token' => $tokenHash, // reuse invite_token for remember-me
        ], 'id = ?', [(int) $user['id']]);

        setcookie('ff_portal_remember', $user['id'] . ':' . $token, [
            'expires'  => $expiry,
            'path'     => '/' . ltrim(FF_BASE_PATH, '/'),
            'secure'   => APP_ENV === 'production',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * portal_logout() — destroy portal session and clear cookies.
 */
function portal_logout(): void
{
    $userId = portal_user_id();

    if ($userId) {
        db_update('portal_users', ['invite_token' => null], 'id = ?', [$userId]);
    }

    // Clear remember-me cookie
    setcookie('ff_portal_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/' . ltrim(FF_BASE_PATH, '/'),
        'secure'   => APP_ENV === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    _portal_session_clear();
}

// ============================================================
// INTERNAL HELPERS
// ============================================================

/**
 * Clear portal-specific session keys without destroying the entire session
 * (admin may still be logged in on the same session).
 */
function _portal_session_clear(): void
{
    unset(
        $_SESSION['ff_portal_user'],
        $_SESSION['ff_portal_last_activity']
    );
}

/**
 * Restore portal session from remember-me cookie.
 */
function _portal_check_remember_me(): void
{
    $cookie = $_COOKIE['ff_portal_remember'] ?? '';
    if ($cookie === '') return;

    $parts = explode(':', $cookie, 2);
    if (count($parts) !== 2) {
        _portal_clear_remember_cookie();
        return;
    }

    [$userId, $plainToken] = $parts;
    $userId = clean_int($userId);
    if (!$userId) {
        _portal_clear_remember_cookie();
        return;
    }

    $user = db_row(
        "SELECT pu.*, c.company_name
         FROM portal_users pu
         JOIN customers c ON c.id = pu.customer_id AND c.deleted_at IS NULL
         WHERE pu.id = ? AND pu.status = 'active'",
        [$userId]
    );

    if (!$user || !$user['invite_token']) {
        _portal_clear_remember_cookie();
        return;
    }

    // Constant-time comparison
    $expectedHash = hash('sha256', $plainToken);
    if (!hash_equals($user['invite_token'], $expectedHash)) {
        _portal_clear_remember_cookie();
        return;
    }

    // Valid — restore session without re-issuing cookie
    portal_login($user, rememberMe: false);
}

function _portal_clear_remember_cookie(): void
{
    setcookie('ff_portal_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/' . ltrim(FF_BASE_PATH, '/'),
        'secure'   => APP_ENV === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * portal_csrf_token() — generate or reuse the CSRF token.
 */
function portal_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * portal_verify_csrf(string $token) — validate submitted CSRF token.
 */
function portal_verify_csrf(string $token): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || $token === '') return false;
    return hash_equals($expected, $token);
}
