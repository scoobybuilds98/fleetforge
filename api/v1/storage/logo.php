<?php
declare(strict_types=1);

/**
 * api/v1/storage/logo.php
 *
 * Serves the sidebar-ready company logo built by FleetForge\Ui\BrandLogo:
 * the upload with its baked-in margins trimmed (kind=full, default) or its
 * left-hand icon (kind=mark, for the collapsed icon rail) — and, since
 * S-PERF-3, the uploaded favicon byte-for-byte (kind=favicon).
 *
 * WHY a separate endpoint (not api/v1/storage/serve.php): the derivatives are
 * cached on the app server's local disk whatever the storage driver is, so a
 * signed StorageClient URL (a local key or an S3 object) cannot reach them.
 *
 * Public, like branding/* on serve.php — the company logo is shown on the
 * login page to signed-out visitors anyway, and the favicon is in the <head>
 * of every login / portal / legal page. Nothing but the CURRENT logo/favicon
 * can be requested (no key parameter), so there is nothing to enumerate.
 *
 * Caching: pages link ?v=<hash of the storage key>, and a re-upload gets a new
 * key → a new URL, so a 200 is immutable for a year. S-PERF-3: the response
 * also carries an ETag (the same hash) and answers If-None-Match with a 304,
 * and the Pragma/Expires headers that session_start() adds are stripped —
 * `Pragma: no-cache` alone made Chromium re-download the logo (48 KB + 16 KB)
 * on EVERY navigation despite the one-year max-age. Error / fallback answers
 * are never immutable (404 no-store from api/bootstrap.php; 302 no-store).
 *
 * @method  GET
 * @auth    none (public brand asset)
 * @params  kind (full|mark|favicon, default full), v (cache-buster, ignored)
 * @returns image/png (or image/x-icon for an .ico favicon) bytes; 304 when the
 *          browser's ETag matches; 302 to the original upload when it could
 *          not be processed / cached (SVG, too large, storage blip…); 404 when
 *          no logo / no mark / no favicon
 *
 * @session S-SIDEBAR-LOGO, S-PERF-3
 */

// S-PERF-3: a logged-out visitor (login page favicon/logo) has no session to
// read — skip creating one, so a public+immutable image response never
// carries a Set-Cookie. Requests that already have a cookie are unaffected.
if (!defined('FF_SKIP_ANON_SESSION')) {
    define('FF_SKIP_ANON_SESSION', true);
}

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Storage\StorageClient;
use FleetForge\Ui\BrandLogo;

// S-PERF-3: this endpoint never reads or writes $_SESSION, but bootstrap.php
// started the session (flock on the session file). Release it now so the two
// sidebar images don't queue behind — or hold up — the page's own data
// requests for the same session. Activity/remember-me were already handled
// by the bootstrap and are persisted by this close.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_method('GET');

/**
 * Does the browser's If-None-Match cover this ETag? Handles a list of tags,
 * weak W/ prefixes and '*'.
 *
 * @param string $etag The quoted ETag this response would carry.
 */
function ff_logo_etag_matches(string $etag): bool
{
    $inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($inm === '') {
        return false;
    }
    if ($inm === '*') {
        return true;
    }
    foreach (explode(',', $inm) as $tag) {
        $tag = trim($tag);
        if (str_starts_with($tag, 'W/')) {
            $tag = substr($tag, 2);
        }
        if ($tag === $etag) {
            return true;
        }
    }
    return false;
}

/**
 * Send an image file with immutable caching + ETag, or a bodyless 304 when
 * the browser already holds it. Never returns.
 *
 * @param string $path Absolute path of the bytes to send.
 * @param string $type Content-Type.
 * @param string $etag Quoted ETag (derived from the storage-key hash).
 */
function ff_logo_send(string $path, string $type, string $etag): never
{
    // session_start()'s default cache limiter ("nocache") emitted
    // `Pragma: no-cache` + `Expires: Thu, 19 Nov 1981` — Chromium honours the
    // Pragma over Cache-Control and re-downloads on every navigation.
    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    if (ff_logo_etag_matches($etag)) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline');
    readfile($path);
    exit;
}

$kindParam = (string) ($_GET['kind'] ?? 'full');
$kind      = in_array($kindParam, ['mark', 'favicon'], true) ? $kindParam : 'full';

// ── Favicon (S-PERF-3) ───────────────────────────────────────────────────────
// ff_favicon_tags() links ?kind=favicon&v=substr(sha1(key),0,12); the same
// hash is the ETag. Bytes come from BrandLogo's local-disk copy (filled from
// storage once), so steady state is a readfile — no S3 call, no S3Client.
if ($kind === 'favicon') {
    $key = (string) (settings_get('brand.favicon_path') ?? '');
    if ($key === '') {
        json_error('NOT_FOUND', 'No favicon is set.', 404);
    }
    $ext  = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    $type = $ext === 'ico' ? 'image/x-icon' : 'image/png'; // same rule as ff_favicon_tags()
    $etag = '"' . substr(sha1($key), 0, 12) . '-favicon"';

    try {
        $path = BrandLogo::faviconFile($key);
    } catch (\Throwable $e) {
        // Storage blip / unwritable cache: hand back the original upload, but
        // NEVER cacheably — an immutable error would pin for a year.
        error_log('[storage/logo favicon] ' . $key . ': ' . $e->getMessage());
        try {
            $orig = StorageClient::url($key, 3600);
        } catch (\Throwable) {
            json_error('NOT_FOUND', 'The favicon is unavailable.', 404);
        }
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: no-store');
        header('Location: ' . $orig, true, 302);
        exit;
    }
    if ($path === null) {
        json_error('NOT_FOUND', 'The favicon file is missing.', 404);
    }
    ff_logo_send($path, $type, $etag);
}

// ── Sidebar logo (full | mark) ───────────────────────────────────────────────
$key = (string) (settings_get('brand.logo_path') ?? '');
if ($key === '') {
    json_error('NOT_FOUND', 'No company logo is set.', 404);
}

$path = BrandLogo::file($key, $kind);
if ($path === null) {
    if ($kind === 'mark') {
        json_error('NOT_FOUND', 'This logo has no separate icon.', 404);
    }
    // Could not be processed — hand back the original upload unchanged.
    header('Cache-Control: no-store');
    header('Location: ' . StorageClient::url($key, 3600), true, 302);
    exit;
}

// file() only returns a path for a successfully built (non-fallback) meta,
// whose 'v' is the same hash BrandLogo::forSidebar() put in the ?v= URL.
$meta = BrandLogo::meta($key) ?? [];
ff_logo_send($path, 'image/png', '"' . (string) ($meta['v'] ?? substr(sha1($key), 0, 12)) . '-' . $kind . '"');
