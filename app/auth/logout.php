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

        if ($userId > 0 && $plainToken !== '') {
            $storedHash = hash('sha256', $plainToken);

            // Find and delete the matching token row
            try {
                $row = db_row(
                    "SELECT id FROM user_remember_tokens
                     WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()",
                    [$userId, $storedHash]
                );
                if ($row) {
                    db_execute(
                        "DELETE FROM user_remember_tokens WHERE id = ?",
                        [$row['id']]
                    );
                }
            } catch (Throwable) {
                // Table may not exist during early dev — silently skip
            }
        }
    }

    // Expire the cookie immediately
    setcookie(
        $cookieName,
        '',
        [
            'expires'  => time() - 3600,
            'path'     => FF_BASE_PATH . '/',
            'secure'   => APP_ENV === 'production',
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
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
