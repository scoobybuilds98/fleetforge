<?php declare(strict_types=1);

/**
 * FleetForge — smoke for the sidebar-ready company logo (S-SIDEBAR-LOGO).
 *
 * Exercises lib/Ui/BrandLogo.php against REAL image files pushed through
 * StorageClient (whatever the driver is), then checks the sidebar + CSS
 * wiring:
 *
 *   L1  a wordmark exported on a big BLACK canvas (the Mainland JPG shape):
 *       trimmed to its ink + even padding, bg 'dark', left icon found as the
 *       'mark', the full image wide
 *   L2  a navy wordmark on a big WHITE canvas: trimmed, bg 'light', no mark
 *       (a pure wordmark has no icon)
 *   L3  a transparent PNG: bg 'clear', trimmed
 *   L4  corners that disagree (a photo / full-bleed badge): bg 'mixed', NOT
 *       trimmed, only capped in size
 *   L5  fallbacks: SVG and a missing file fall back to the original upload;
 *       a missing file is retried once the retry window passes
 *   L6  api/v1/storage/logo.php serves the cached PNGs (full + mark) and the
 *       sidebar markup/CSS carry the classes shell.css keys off
 *
 * Hermetic: every storage key is zz-smoke-* and is deleted afterwards along
 * with its cached derivatives; the settings row is never touched.
 *
 * Usage: php tests/_smoke_sidebar_logo.php
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Storage\StorageClient;
use FleetForge\Ui\BrandLogo;

$pass = 0;
$fail = 0;
$keys = [];
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  — $detail") . "\n";
}

/** Write a GD image to a temp file, push it through StorageClient, return the key. */
function put(\GdImage $im, string $ext): string
{
    global $keys;
    $tmp = tempnam(sys_get_temp_dir(), 'ffl');
    $ext === 'png' ? imagepng($im, $tmp) : imagejpeg($im, $tmp, 92);
    $key = 'branding/zz-smoke-' . bin2hex(random_bytes(4)) . '.' . $ext;
    StorageClient::upload($tmp, $key);
    @unlink($tmp);
    $keys[] = $key;
    return $key;
}

function cacheBase(string $key): string
{
    $m = new ReflectionMethod(BrandLogo::class, 'cacheBase');
    $m->setAccessible(true);
    return $m->invoke(null, $key);
}

try {
    // ── L1: icon + wordmark on a big black canvas ─────────────────────────
    $im = imagecreatetruecolor(1200, 700);
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
    $blue  = imagecolorallocate($im, 60, 170, 230);
    $white = imagecolorallocate($im, 250, 250, 250);
    imagefilledrectangle($im, 200, 300, 300, 400, $blue);      // the icon (100×100)
    imagefilledrectangle($im, 330, 310, 1000, 370, $white);    // the wordmark
    imagefilledrectangle($im, 330, 382, 1000, 400, $white);    // the tagline
    $k1 = put($im, 'jpg');
    $m1 = BrandLogo::meta($k1);
    $f1 = BrandLogo::file($k1, 'full');
    $s1 = $f1 ? getimagesize($f1) : [0, 0];
    // ink 801×101 → pad 12 → 825×125 → capped to 720 wide ≈ 720×109 (JPEG
    // edges may shift a pixel or two). Untrimmed it would be 1200:700.
    check('L1 black canvas → bg dark', ($m1['bg'] ?? '') === 'dark', json_encode($m1));
    check('L1 trimmed to ink + even padding (720×~109)', $s1[0] === 720 && abs($s1[1] - 109) <= 3, "got {$s1[0]}×{$s1[1]}");
    check('L1 left icon found as the mark', !empty($m1['mark']) && BrandLogo::file($k1, 'mark') !== null);
    $mk = BrandLogo::file($k1, 'mark');
    $sm = $mk ? getimagesize($mk) : [0, 0];
    check('L1 mark is square', $sm[0] > 0 && $sm[0] === $sm[1], "got {$sm[0]}×{$sm[1]}");

    // ── L2: pure wordmark on a white canvas ───────────────────────────────
    $im = imagecreatetruecolor(1200, 600);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    $navy = imagecolorallocate($im, 20, 40, 90);
    imagefilledrectangle($im, 300, 250, 900, 330, $navy);
    $k2 = put($im, 'jpg');
    $m2 = BrandLogo::meta($k2);
    check('L2 white canvas → bg light', ($m2['bg'] ?? '') === 'light', json_encode($m2));
    check('L2 pure wordmark → no mark', empty($m2['mark']));
    check('L2 trimmed (canvas 1200×600 → ~620×100)', abs((int) ($m2['w'] ?? 0) - 620) <= 4 && abs((int) ($m2['h'] ?? 0) - 100) <= 4, json_encode($m2));

    // ── L3: transparent PNG ───────────────────────────────────────────────
    $im = imagecreatetruecolor(800, 800);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 100, 350, 700, 450, imagecolorallocatealpha($im, 200, 30, 30, 0));
    $k3 = put($im, 'png');
    $m3 = BrandLogo::meta($k3);
    check('L3 transparent → bg clear, trimmed', ($m3['bg'] ?? '') === 'clear' && (int) ($m3['h'] ?? 999) < 200, json_encode($m3));

    // ── L4: mixed corners → not trimmed ───────────────────────────────────
    $im = imagecreatetruecolor(1000, 500);
    for ($x = 0; $x < 1000; $x++) {
        imageline($im, $x, 0, $x, 499, imagecolorallocate($im, intdiv($x, 4), 100, 200 - intdiv($x, 5)));
    }
    $k4 = put($im, 'jpg');
    $m4 = BrandLogo::meta($k4);
    check('L4 mixed corners → untrimmed, capped at 720×360', ($m4['bg'] ?? '') === 'mixed' && (int) $m4['w'] === 720 && (int) $m4['h'] === 360, json_encode($m4));

    // ── L5: fallbacks ─────────────────────────────────────────────────────
    $svgTmp = tempnam(sys_get_temp_dir(), 'ffl');
    file_put_contents($svgTmp, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');
    $k5 = 'branding/zz-smoke-' . bin2hex(random_bytes(4)) . '.svg';
    StorageClient::upload($svgTmp, $k5);
    @unlink($svgTmp);
    $keys[] = $k5;
    $m5 = BrandLogo::meta($k5);
    check('L5 SVG → fallback to the original', !empty($m5['fallback']) && BrandLogo::file($k5, 'full') === null, json_encode($m5));

    $k6 = 'branding/zz-smoke-' . bin2hex(random_bytes(4)) . '.png';
    $keys[] = $k6;
    $m6 = BrandLogo::meta($k6);
    check('L5 missing file → fallback (transient)', !empty($m6['fallback']) && ($m6['why'] ?? '') === 'unreadable', json_encode($m6));
    // The file arrives; inside the retry window the fallback stands…
    $tmp = tempnam(sys_get_temp_dir(), 'ffl');
    copy((string) $f1, $tmp);
    StorageClient::upload($tmp, $k6);
    @unlink($tmp);
    check('L5 retry window respected', !empty(BrandLogo::meta($k6)['fallback']));
    // …and once it passes, the logo is built.
    touch(cacheBase($k6) . '.json', time() - 3600);
    clearstatcache();
    $m6b = BrandLogo::meta($k6);
    check('L5 rebuilt after the retry window', empty($m6b['fallback']) && BrandLogo::file($k6, 'full') !== null, json_encode($m6b));

    // ── L6: endpoint + wiring ─────────────────────────────────────────────
    $ep = (string) file_get_contents(FF_ROOT . '/api/v1/storage/logo.php');
    check('L6 endpoint serves the cached PNG, immutable', str_contains($ep, "BrandLogo::file(\$key, \$kind)")
        && str_contains($ep, 'image/png') && str_contains($ep, 'immutable'));
    check('L6 endpoint takes no key parameter (only the current logo)', !preg_match('/\$_GET\[[\'"]key/', $ep));
    $sb = (string) file_get_contents(FF_ROOT . '/includes/sidebar.php');
    check('L6 sidebar uses BrandLogo + mark + class hooks', str_contains($sb, 'BrandLogo::forSidebar()')
        && str_contains($sb, 'sidebar-logo-mark') && str_contains($sb, "' logo-bg-'") && str_contains($sb, 'is-logo-wide'));
    $css = (string) file_get_contents(FF_ROOT . '/public/assets/css/shell.css');
    check('L6 shell.css: blend, badge, collapsed-rail mark', str_contains($css, 'mix-blend-mode: lighten')
        && str_contains($css, '.sidebar-brand.logo-bg-light .sidebar-logo')
        && substr_count($css, 'has-logo-mark:not(.is-logo-broken) .sidebar-logo-mark') === 2);
    $fs = BrandLogo::forSidebar();
    check('L6 forSidebar() shape', $fs === null || (isset($fs['url'], $fs['markUrl'], $fs['bg'], $fs['wide'])));
} catch (\Throwable $e) {
    check('no exception', false, $e->getMessage());
} finally {
    foreach ($keys as $k) {
        try {
            StorageClient::delete($k);
        } catch (\Throwable) {
        }
        foreach (glob(cacheBase($k) . '*') ?: [] as $f) {
            @unlink($f);
        }
    }
}

echo "\nsidebar_logo_smoke: $pass/" . ($pass + $fail) . ($fail ? " — $fail FAILED" : ' PASS') . "\n";
exit($fail ? 1 : 0);
