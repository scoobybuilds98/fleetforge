<?php
declare(strict_types=1);

/**
 * app/portal/api/notifications/_bootstrap.php
 *
 * Lightweight portal-API bootstrap. Standalone (does NOT use the admin
 * api/bootstrap.php) because portal sessions live under a different key
 * (`ff_portal_user`) and use portal_csrf_token() for state-changing calls.
 *
 * Loaded by every endpoint in app/portal/api/notifications/.
 *
 * Responsibilities:
 *   - Boot config + portal auth (which starts the session)
 *   - Set JSON response headers
 *   - Provide json_ok() / json_err() helpers in this file's local scope
 *   - Enforce CSRF on POST/PUT/DELETE
 *   - Reject unauthenticated callers with 401 JSON
 *
 * @session NOTIF-1
 */

require_once dirname(__DIR__, 4) . '/config/app.php';
require_once FF_ROOT . '/app/portal/includes/auth.php';

// ── Sentry error monitoring ───────────────────────────────────
// Idempotent — no-op if already initialised by config/app.php.
// No-op when SENTRY_DSN is blank.
\FleetForge\Observability\Sentry::init();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// ── Global exception handler ─────────────────────────────────
// Catches any unhandled Throwable and returns a JSON error
// instead of leaking an HTML stack trace or blank 500 body.
set_exception_handler(function (Throwable $e): void {
    error_log('[FF Portal Notifications API] Unhandled exception: ' . $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine());
    \FleetForge\Observability\Sentry::captureException($e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']]);
    exit;
});

/**
 * portal_json_ok — emit a 2xx success envelope and exit.
 */
function portal_json_ok(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

/**
 * portal_json_err — emit a 4xx/5xx error envelope and exit.
 */
function portal_json_err(string $code, string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'error'   => ['code' => $code, 'message' => $message],
    ]);
    exit;
}

// ── Auth ────────────────────────────────────────────────────
$portalUserId = portal_user_id();
if (!$portalUserId) {
    portal_json_err('UNAUTHORIZED', 'No authenticated portal user.', 401);
}

// ── CSRF (state-changing methods only) ──────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    $sessionToken   = $_SESSION['csrf_token'] ?? '';
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        portal_json_err('CSRF_INVALID', 'Invalid CSRF token.', 403);
    }
}
