<?php
declare(strict_types=1);

/**
 * api/v1/storage/logo.php
 *
 * Serves the sidebar-ready company logo built by FleetForge\Ui\BrandLogo:
 * the upload with its baked-in margins trimmed (kind=full, default) or its
 * left-hand icon (kind=mark, for the collapsed icon rail).
 *
 * WHY a separate endpoint (not api/v1/storage/serve.php): the derivatives are
 * cached on the app server's local disk whatever the storage driver is, so a
 * signed StorageClient URL (a local key or an S3 object) cannot reach them.
 *
 * Public, like branding/* on serve.php — the company logo is shown on the
 * login page to signed-out visitors anyway. Nothing but the current logo can
 * be requested (no key parameter), so there is nothing to enumerate.
 *
 * Caching: the sidebar links ?v=<hash of the storage key>, and a re-upload
 * gets a new key → a new URL, so responses are immutable for a year.
 *
 * @method  GET
 * @auth    none (public brand asset)
 * @params  kind (full|mark, default full), v (cache-buster, ignored)
 * @returns image/png bytes; 302 to the original upload when it could not be
 *          processed (SVG, too large…); 404 when no logo / no mark
 *
 * @session S-SIDEBAR-LOGO
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Storage\StorageClient;
use FleetForge\Ui\BrandLogo;

require_method('GET');

$kind = ($_GET['kind'] ?? 'full') === 'mark' ? 'mark' : 'full';
$key  = (string) (settings_get('brand.logo_path') ?? '');
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

header('Content-Type: image/png');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Disposition: inline');
readfile($path);
exit;
