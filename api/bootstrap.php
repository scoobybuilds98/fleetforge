<?php
declare(strict_types=1);

// ============================================================
// FleetForge — API Bootstrap
//
// Required at the top of every API endpoint file.
// Must be loaded before any output is sent.
//
// Responsibilities:
//   1. Load application config (constants, env, autoloader)
//   2. Start session (needed for CSRF + auth checks)
//   3. Define FF_API_CONTEXT — tells auth.php to return JSON
//      errors instead of HTML redirects
//   4. Set universal response headers
//   5. Register JSON exception handler
//   6. Enforce CSRF on state-changing requests from
//      authenticated sessions
//   7. Define API response helpers:
//        json_success()    — 200/201 success envelope
//        json_error()      — 4xx/5xx error envelope
//        json_paginated()  — paginated list envelope
//        require_method()  — method guard
//
// Response envelope shape:
//   Success:    { "success": true,  "data": ... }
//   Error:      { "success": false, "error": { "code": "...", "message": "..." } }
//   Paginated:  { "success": true,  "data": { "items": [...], "pagination": {...} } }
// ============================================================

require_once realpath(dirname(__DIR__) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

// ── Session ─────────────────────────────────────────────────
// Must start before any output and before CSRF is checked.
_ff_session_start();

// ── API context flag ─────────────────────────────────────────
// Checked by require_auth() and require_permission() in auth.php
// to determine whether to redirect (page) or JSON-respond (API).
if (!defined('FF_API_CONTEXT')) {
    define('FF_API_CONTEXT', true);
}

// ── Universal response headers ───────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

// ── Global exception handler ─────────────────────────────────
// Catches any unhandled Throwable from an API endpoint and
// returns a JSON error instead of leaking an HTML stack trace.
set_exception_handler(function (Throwable $e): void {
    error_log(
        '[FF API] Unhandled exception: ' . $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine()
    );

    // Never expose internal details in production
    $message = (defined('FF_DEBUG') && FF_DEBUG)
        ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : 'An unexpected error occurred. Please try again.';

    http_response_code(500);
    // json_error() may not be defined if the error occurred before this
    // file finished loading; use raw json_encode as a safe fallback.
    echo json_encode([
        'success' => false,
        'error'   => [
            'code'    => 'INTERNAL_ERROR',
            'message' => $message,
        ],
    ]);
    exit;
});

// ── CSRF enforcement ─────────────────────────────────────────
// GET, HEAD, and OPTIONS are safe (idempotent, no state changes).
// POST / PUT / PATCH / DELETE require the X-CSRF-Token header to
// match the session token — but only when a session exists.
// Unauthenticated endpoints (e.g. /health) have no session CSRF
// token and are exempt (no cookies → no CSRF attack surface).
(function (): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return; // safe methods — no check needed
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if ($sessionToken === '') {
        return; // no authenticated session — unauthenticated endpoint, exempt
    }

    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => 'CSRF_INVALID',
                'message' => 'Invalid or missing CSRF token.',
            ],
        ]);
        exit;
    }
})();


// ============================================================
// API RESPONSE HELPERS
// ============================================================

/**
 * Terminate the request with a JSON success response.
 *
 * @param mixed $data    Payload placed in the "data" key.
 * @param int   $status  HTTP status code (default 200).
 */
function json_success(mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        ['success' => true, 'data' => $data],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Terminate the request with a JSON error response.
 *
 * $code should be a SCREAMING_SNAKE_CASE string that client code
 * can switch on (e.g. 'NOT_FOUND', 'VALIDATION_ERROR').
 *
 * @param string $code     Machine-readable error identifier.
 * @param string $message  Human-readable description.
 * @param int    $status   HTTP status code (default 400).
 * @param array  $extra    Optional extra fields merged into "error".
 */
function json_error(
    string $code,
    string $message,
    int    $status = 400,
    array  $extra  = []
): never {
    http_response_code($status);
    echo json_encode(
        [
            'success' => false,
            'error'   => array_merge(
                ['code' => $code, 'message' => $message],
                $extra
            ),
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
    exit;
}

/**
 * Terminate the request with a paginated JSON list response.
 *
 * Response shape:
 *   {
 *     "success": true,
 *     "data": {
 *       "items":      [...],
 *       "pagination": {
 *         "total":       <int>,   total matching rows
 *         "page":        <int>,   current page (1-based)
 *         "per_page":    <int>,
 *         "total_pages": <int>,
 *         "has_more":    <bool>
 *       }
 *     }
 *   }
 *
 * @param array $items    The current page of results.
 * @param int   $total    Total number of matching rows across all pages.
 * @param int   $page     Current page number (1-based).
 * @param int   $perPage  Items per page.
 * @param int   $status   HTTP status code (default 200).
 */
function json_paginated(
    array $items,
    int   $total,
    int   $page,
    int   $perPage,
    int   $status = 200
): never {
    $perPage     = max(1, $perPage);
    $totalPages  = (int) ceil($total / $perPage);

    http_response_code($status);
    echo json_encode(
        [
            'success' => true,
            'data'    => [
                'items'      => $items,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total_pages' => $totalPages,
                    'has_more'    => $page < $totalPages,
                ],
            ],
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Enforce an allowed set of HTTP methods for an endpoint.
 * Sends 405 Method Not Allowed with an Allow header if the
 * request method is not in the list.
 *
 * Usage:  require_method('GET');
 *         require_method('POST', 'PUT');
 *
 * @param string ...$methods  Allowed methods (case-insensitive).
 */
function require_method(string ...$methods): void
{
    $allowed  = array_map('strtoupper', $methods);
    $incoming = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (!in_array($incoming, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_error(
            'METHOD_NOT_ALLOWED',
            "Method {$incoming} is not allowed. Accepted: " . implode(', ', $allowed) . '.',
            405
        );
    }
}

/**
 * Read and decode the raw JSON request body.
 * Returns an empty array on failure (malformed JSON or empty body).
 *
 * Use this instead of $_POST for endpoints that accept JSON bodies.
 *
 * @return array<string, mixed>
 */
function json_body(): array
{
    static $parsed = null;

    if ($parsed !== null) {
        return $parsed;
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || $raw === '') {
        $parsed = [];
        return $parsed;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $parsed  = is_array($decoded) ? $decoded : [];
    } catch (\JsonException $e) {
        // FIX #43: return 400 INVALID_JSON instead of silently returning [] so callers
        // get a useful error rather than a confusing "field is required" cascade.
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => 'INVALID_JSON',
                'message' => 'Request body is not valid JSON.',
            ],
        ]);
        exit;
    }

    return $parsed;
}

// ============================================================
// require_id() — assert a route parameter is a positive integer
//
// Call at the top of any endpoint that needs an :id segment.
// Responds with 400 and exits if the value is missing or not
// a positive integer.
//
//   $id = require_id($_GET['id'] ?? null);
// ============================================================
function require_id(mixed $val): int
{
    $id = clean_int($val);

    if ($id === null || $id <= 0) {
        // FIX #42: arguments were swapped (code/message); correct order is code then message.
        json_error('INVALID_ID', 'Invalid or missing ID. Must be a positive integer.', 400);
    }

    return $id;
}

// ============================================================
// require_input() — validate required fields in the request body
//
// Accepts an array of field names (or name => label pairs).
// Responds with 422 and a per-field errors object if any are
// missing or empty. Never throws.
//
//   require_input(['name', 'email', 'role_id']);
//   require_input(['customer_id' => 'Customer', 'start_date' => 'Start date']);
// ============================================================
function require_input(array $fields): void
{
    $body   = json_body();
    $errors = [];

    foreach ($fields as $key => $label) {
        // If the array is sequential (numeric keys), key and label are the same
        if (is_int($key)) {
            $key   = $label;
            $label = ucfirst(str_replace('_', ' ', (string) $label));
        }

        $val = $body[$key] ?? null;

        if ($val === null || $val === '') {
            $errors[$key] = $label . ' is required.';
        }
    }

    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
