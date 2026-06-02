<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Front Controller / Router  [D7]
//
// This is the ONLY PHP entry point for the application.
// All requests pass through here via mod_rewrite.
//
// Routing:
//   /fleetforge/api/...       → api/
//   /fleetforge/portal/...    → app/portal/
//   /fleetforge/webhooks/...  → webhooks/
//   /fleetforge/...           → app/admin/
//
// Security:
//   • Maintenance mode gated by MAINTENANCE_MODE env var
//   • Path traversal blocked — resolve_route() [PASS-7:G3]
//   • Only .php files are ever included
//   • realpath() confirms resolved file is inside its root
// ============================================================

// PHP built-in dev server (`php -S`) — let static files in
// public/ be served directly. Has NO effect under nginx/Apache
// because PHP_SAPI is 'fpm-fcgi' / 'apache2handler' there.
// `return false;` from a router script tells `php -S` to serve
// the requested file as-is. We only do this for files that
// actually exist inside the docroot, never for PHP files (the
// router still owns all .php dispatching).
if (PHP_SAPI === 'cli-server') {
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $diskPath = __DIR__ . $reqPath;
    if ($reqPath !== '/' && is_file($diskPath) && !str_ends_with($reqPath, '.php')) {
        return false;
    }
}

require_once realpath(__DIR__ . '/../config/app.php');

// ============================================================
// HELPERS
// Defined before dispatch so they are available to all code
// below and to any file that gets included via require.
// ============================================================

/**
 * Terminate the request with a 404 response.
 * Loads the error page if it exists, falls back to a plain string.
 */
function ff_not_found(): never
{
    http_response_code(404);
    $errFile = FF_ROOT . '/app/errors/404.php';
    if (is_file($errFile)) {
        require $errFile;
    } else {
        echo '404 Not Found';
    }
    exit;
}

/**
 * Resolve a URL path to an absolute PHP file path inside $root.
 *
 * Security contract [PASS-7:G3]:
 *   • Each segment must match /^[a-zA-Z0-9_\-.]+$/
 *   • '..' segments are rejected immediately
 *   • realpath() is used to canonicalise the candidate path
 *   • The canonical path must start with realpath($root)
 *     — no symlink escapes, no traversal
 *
 * Resolution order for a segment with no .php extension:
 *   1. {root}/{relative}.php
 *   2. {root}/{relative}/index.php
 *
 * Returns null if the path is invalid or the file does not exist.
 */
function resolve_route(string $root, string $localPath): ?string
{
    $segments = explode('/', ltrim($localPath, '/'));
    $clean    = [];

    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.') {
            continue; // skip empty segments and current-dir refs
        }

        if ($seg === '..') {
            return null; // path traversal — reject
        }

        // Only safe characters in path segments
        if (!preg_match('/^[a-zA-Z0-9_\-.]+$/', $seg)) {
            return null;
        }

        $clean[] = $seg;
    }

    $relative = implode('/', $clean);

    // Build candidate file path
    if ($relative === '') {
        $candidate = $root . '/index.php';
    } elseif (str_ends_with($relative, '.php')) {
        $candidate = $root . '/' . $relative;
    } else {
        $candidate = $root . '/' . $relative . '.php';
        if (!is_file($candidate)) {
            $candidate = $root . '/' . $relative . '/index.php';
        }
    }

    // Canonicalise — returns false if the file does not exist
    $realFile = realpath($candidate);
    $realRoot = realpath($root);

    if ($realFile === false || $realRoot === false) {
        return null;
    }

    // Verify the file sits strictly inside the route root
    if (!str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $realFile;
}

// ============================================================
// MAINTENANCE MODE
// Bypass allowed for IPs listed in MAINTENANCE_BYPASS_IPS.
// ============================================================
if ((string) env('MAINTENANCE_MODE', 'false') === 'true') {
    $bypassList = (string) env('MAINTENANCE_BYPASS_IPS', '');
    $bypassIps  = $bypassList !== ''
        ? array_filter(array_map('trim', explode(',', $bypassList)))
        : [];
    $clientIp   = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!in_array($clientIp, $bypassIps, true)) {
        http_response_code(503);
        $maintFile = FF_ROOT . '/app/errors/maintenance.php';
        if (is_file($maintFile)) {
            require $maintFile;
        } else {
            echo 'Service temporarily unavailable.';
        }
        exit;
    }

    unset($bypassList, $bypassIps, $clientIp, $maintFile);
}

// ============================================================
// PARSE REQUEST PATH
// ============================================================
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Guard: every request must start with our base path.
// Under normal operation .htaccess ensures this, but we
// verify defensively in case of misconfiguration.
if (!str_starts_with($requestPath, FF_BASE_PATH)) {
    ff_not_found();
}

// Strip the base path prefix.
// /fleetforge/api/v1/health → /api/v1/health
$localPath = substr($requestPath, strlen(FF_BASE_PATH));

if ($localPath === '' || $localPath === '/') {
    $localPath = '/';
}

// ============================================================
// DETERMINE ROUTE ROOT
// Each section maps to a distinct filesystem directory.
// ============================================================
if (str_starts_with($localPath, '/api/') || $localPath === '/api') {
    $routeRoot  = FF_ROOT . '/api';
    $routeLocal = substr($localPath, strlen('/api')) ?: '/';

} elseif (str_starts_with($localPath, '/auth/') || $localPath === '/auth') {
    $routeRoot  = FF_ROOT . '/app/auth';
    $routeLocal = substr($localPath, strlen('/auth')) ?: '/';

} elseif (str_starts_with($localPath, '/portal/') || $localPath === '/portal') {
    $routeRoot  = FF_ROOT . '/app/portal';
    $routeLocal = substr($localPath, strlen('/portal')) ?: '/';

} elseif (str_starts_with($localPath, '/legal/') || $localPath === '/legal') {
    // S-LEGAL-FOOTER-COMMERCIAL: public /legal/* pages — no auth required.
    // The resolved page files under app/legal/ deliberately do NOT call
    // require_auth() / require_auth_api() so they remain accessible to
    // anonymous visitors (linked from login page + portal + emails).
    $routeRoot  = FF_ROOT . '/app/legal';
    $routeLocal = substr($localPath, strlen('/legal')) ?: '/';

} elseif (str_starts_with($localPath, '/webhooks/') || $localPath === '/webhooks') {
    $routeRoot  = FF_ROOT . '/webhooks';
    $routeLocal = substr($localPath, strlen('/webhooks')) ?: '/';

} elseif (str_starts_with($localPath, '/help') && (strlen($localPath) === 5 || $localPath[5] === '/')) {
    // S-HELP-SYSTEM-FOUNDATION — in-app help center.
    // Unknown /help/{slug} paths fall back to _guide.php (graceful "guide coming soon")
    // instead of a hard 404, so module buttons can be wired before guides are authored.
    $routeRoot        = FF_ROOT . '/app/admin/help';
    $routeLocal       = strlen($localPath) > 5 ? substr($localPath, 5) : '/';
    $helpSlugFallback = true;

} else {
    // Everything else → admin
    $routeRoot  = FF_ROOT . '/app/admin';
    $routeLocal = $localPath;
}

// ============================================================
// DISPATCH
// ============================================================
$resolvedFile = resolve_route($routeRoot, $routeLocal);

// Help: unknown slugs use the guide renderer (shows "coming soon") rather than hard 404.
if ($resolvedFile === null && isset($helpSlugFallback) && $routeLocal !== '/') {
    $helpFallbackFile = FF_ROOT . '/app/admin/help/_guide.php';
    if (is_file($helpFallbackFile)) {
        $resolvedFile = realpath($helpFallbackFile);
    }
}

if ($resolvedFile === null) {
    ff_not_found();
}

require $resolvedFile;
