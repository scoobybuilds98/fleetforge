<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Logout
//
// Clears the session, removes the remember-me cookie and its
// DB token, then redirects to the login page.
//
// Accepts GET or POST. No CSRF check needed for logout
// (logging out is never harmful), but the action is only
// reachable by authenticated users via the sidebar link.
// ============================================================

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

_ff_session_start();

// ── Remove remember-me token from DB ───────────────────────
$cookieName = 'ff_remember';
$cookieVal  = $_COOKIE[$cookieName] ?? '';

if ($cookieVal !== '') {
    $parts = explode(':', $cookieVal, 2);

    if (count($parts) === 2) {
        [$userId, $plainToken] = $parts;
        $userId = (int) $userId;

        // Clear the remember_me token from the users row so the cookie
        // cannot be replayed after logout (auth.php stores hash there)
        if ($userId > 0) {
            try {
                db_execute(
                    "UPDATE users SET remember_token = NULL WHERE id = ?",
                    [$userId]
                );
            } catch (Throwable) {
                // DB unavailable — session destroy still proceeds
            }
        }
    }

    // Expire the cookie immediately.
    // WHY '/' . ltrim(FF_BASE_PATH, '/'):  the cookie was originally set in
    // auth_login() with this exact path formula (produces '/fleetforge', no
    // trailing slash). RFC 6265 requires an exact (name+domain+path) match
    // to delete a cookie — the previous 'FF_BASE_PATH . "/"' produced
    // '/fleetforge/', which browsers treated as a different path and left the
    // original cookie alive for up to 30 days after logout.
    setcookie(
        $cookieName,
        '',
        [
            'expires'  => time() - 3600,
            'path'     => '/' . ltrim(FF_BASE_PATH, '/'),
            'secure'   => APP_ENV === 'production',
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

// ── Audit log ───────────────────────────────────────────────
// Capture user info before the session is cleared
try {
    $__logoutUser = current_user();
    if ($__logoutUser) {
        db_insert('audit_log', [
            'user_id'      => $__logoutUser['id'],
            'user_name'    => $__logoutUser['name'],
            'action'       => 'logout',
            'module'       => 'auth',
            'entity_type'  => 'user',
            'entity_id'    => $__logoutUser['id'],
            'entity_label' => $__logoutUser['email'],
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    }
    unset($__logoutUser);
} catch (Throwable) {
    // audit_log insert failure must never block logout
}

// ── Destroy the session ─────────────────────────────────────
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 3600,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]
    );
}

session_destroy();

// ── Redirect to login with a flash message ──────────────────
// Start a fresh session just long enough to set the flash
session_start();
$_SESSION['auth_flash'] = 'You have been signed out successfully.';
session_write_close();

header('Location: ' . base_url('auth/login'));
exit;
