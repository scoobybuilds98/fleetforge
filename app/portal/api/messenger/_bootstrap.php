<?php
declare(strict_types=1);

/**
 * app/portal/api/messenger/_bootstrap.php
 *
 * Shared bootstrap for every portal messenger endpoint.
 * Structured identically to app/portal/api/notifications/_bootstrap.php so
 * both subsystems share the same conventions (session key, CSRF, envelope).
 *
 * Responsibilities:
 *   - Boot config + portal auth
 *   - Set JSON response headers
 *   - Enforce CSRF on POST/PUT/DELETE
 *   - Load MessengerService so endpoints can call shared helpers
 *
 * @session MSGR-1
 */

require_once dirname(__DIR__, 4) . '/config/app.php';
require_once FF_ROOT . '/app/portal/includes/auth.php';
require_once FF_ROOT . '/lib/Messenger/MessengerService.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/**
 * Emit a 2xx success envelope and exit.
 */
function portal_msgr_ok(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

/**
 * Emit a 4xx/5xx error envelope and exit.
 */
function portal_msgr_err(string $code, string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'error'   => ['code' => $code, 'message' => $message],
    ]);
    exit;
}

/**
 * Read the JSON request body. Returns an empty array on failure or empty
 * body so callers can use null-coalescing on individual fields.
 *
 * WHY: portal Alpine pages POST through FF_Api.post() which always sends
 * application/json — $_POST is empty for these requests.
 *
 * @return array<string, mixed>
 */
function portal_msgr_input(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return $cached = [];
    }
    $decoded = json_decode($raw, true);
    return $cached = is_array($decoded) ? $decoded : [];
}

// ── Auth ────────────────────────────────────────────────────
$portalUserId = portal_user_id();
$portalCustomerId = portal_customer_id();
if (!$portalUserId || !$portalCustomerId) {
    portal_msgr_err('UNAUTHORIZED', 'No authenticated portal user.', 401);
}

// ── CSRF (state-changing methods only) ──────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    $sessionToken   = $_SESSION['csrf_token'] ?? '';
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        portal_msgr_err('CSRF_INVALID', 'Invalid CSRF token.', 403);
    }
}
