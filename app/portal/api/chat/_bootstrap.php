<?php
declare(strict_types=1);

/**
 * app/portal/api/chat/_bootstrap.php
 *
 * Shared bootstrap for every portal chat API endpoint.
 * Mirrors app/portal/api/messenger/_bootstrap.php structure.
 *
 * Responsibilities:
 *   - Boot config + portal auth
 *   - Set JSON response headers
 *   - Enforce CSRF on POST/PUT/DELETE
 *
 * WHY: Portal users access customer conversation channels via portal session,
 *      not admin session — they need their own guarded endpoints.
 *
 * Spec: CHAT-2
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
    error_log('[FF Portal Chat API] Unhandled exception: ' . $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine());
    \FleetForge\Observability\Sentry::captureException($e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']]);
    exit;
});

function portal_chat_ok(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function portal_chat_err(string $code, string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

/**
 * Read JSON request body. Returns empty array on failure.
 * WHY: FF_Api.post() sends application/json — $_POST is empty for these requests.
 */
function portal_chat_input(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') return $cached = [];
    $decoded = json_decode($raw, true);
    return $cached = is_array($decoded) ? $decoded : [];
}

// ── Auth ────────────────────────────────────────────────────────────────
$portalUserId     = portal_user_id();
$portalCustomerId = portal_customer_id();
if (!$portalUserId || !$portalCustomerId) {
    portal_chat_err('UNAUTHORIZED', 'No authenticated portal user.', 401);
}

// Mid-session revocation: re-check customer + portal-user status every request
// (the session snapshot alone would keep a suspended account polling channels).
$portalRevoked = portal_status_revoked();
if ($portalRevoked !== null) {
    _portal_session_clear();
    portal_chat_err('UNAUTHORIZED', $portalRevoked, 401);
}

// ── CSRF (state-changing methods only) ──────────────────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    $sessionToken   = $_SESSION['csrf_token'] ?? '';
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        portal_chat_err('CSRF_INVALID', 'Invalid CSRF token.', 403);
    }
}
