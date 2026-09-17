<?php
declare(strict_types=1);

/**
 * GET /api/v1/training/stream?id=N&kind=video|captions
 *
 * S-TRAINING-MODULE — plays a training chapter.
 *
 *   S3 (prod):   302 to a short-lived presigned URL; the browser then range-
 *                requests S3 directly, so no video bytes pass through PHP.
 *   local (dev): streams the file with HTTP Range support. The generic
 *                storage/serve endpoint cannot be used — it sends whole files
 *                with no Accept-Ranges, so <video> cannot seek or resume.
 *
 * The stable URL (id, not a signed key) lets the page's <video src> survive
 * longer than any presign expiry: each load re-authorises and re-signs.
 *
 * @method  GET
 * @auth    Any signed-in staff user
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Storage\StorageClient;

require_method('GET', 'HEAD');
require_auth_api();
// Release the session lock: a long stream must not block the progress
// heartbeats (PHP serialises requests that hold the same session).
session_write_close();

$id   = clean_int($_GET['id'] ?? null);
$kind = ($_GET['kind'] ?? 'video') === 'captions' ? 'captions' : 'video';

$video = $id ? db_row(
    "SELECT video_key, captions_key FROM training_videos WHERE id = ? AND is_active = 1",
    [$id]
) : null;
$key = $video[$kind === 'captions' ? 'captions_key' : 'video_key'] ?? null;
if (!$key) {
    json_error('NOT_FOUND', 'Training video not found.', 404);
}

// Captions are always proxied, never redirected: a <track> from another
// origin (S3) is silently dropped unless the bucket sends CORS headers.
if ($kind === 'captions') {
    $vtt = StorageClient::read($key);
    if ($vtt === null) {
        json_error('NOT_FOUND', 'Captions file is missing from storage.', 404);
    }
    header('Content-Type: text/vtt; charset=utf-8');
    header('Content-Length: ' . strlen($vtt));
    header('Cache-Control: private, max-age=300');
    echo $vtt;
    exit;
}

if (StorageClient::isRemote()) {
    header('Cache-Control: no-store');
    header('Location: ' . StorageClient::url($key, 4 * 3600), true, 302);
    exit;
}

$path = StorageClient::localPath($key);
if (!is_file($path)) {
    json_error('NOT_FOUND', 'Training video file is missing from storage.', 404);
}

$size  = filesize($path);
$start = 0;
$end   = $size - 1;

header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
    if ($m[1] === '' && $m[2] !== '') {          // suffix range: last N bytes
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') $end = min($end, (int) $m[2]);
    }
    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size, true, 416);
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

while (ob_get_level() > 0) ob_end_clean();
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $length;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $chunk = fread($fh, (int) min(1 << 16, $left));
    if ($chunk === false) break;
    echo $chunk;
    flush();
    $left -= strlen($chunk);
}
fclose($fh);
