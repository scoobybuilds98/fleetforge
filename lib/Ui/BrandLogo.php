<?php
declare(strict_types=1);

/**
 * FleetForge — sidebar-ready company logo (S-SIDEBAR-LOGO)
 *
 * @file        lib/Ui/BrandLogo.php
 * @description Turns the uploaded company logo (settings brand.logo_path) into
 *              the two images the app sidebar shows:
 *
 *                full  the logo with its baked-in margins TRIMMED, then
 *                      re-padded evenly and capped at 720 px wide — so a JPG
 *                      exported on a big black/white canvas renders at a
 *                      readable size instead of as a small mark floating in a
 *                      box of its own background
 *                mark  the square icon at the logo's left edge, when there is
 *                      one (a mark, a gap, then the wordmark), for the
 *                      collapsed 64 px icon rail, where the full wordmark
 *                      would shrink to an unreadable sliver
 *
 *              WHY server-side: CSS cannot see an image's own margins, and a
 *              logo is uploaded once and shown on every page — so the work is
 *              done once and cached on local disk under storage/generated/brand/
 *              (keyed by the storage key; a re-upload gets a new key, so the
 *              cache never goes stale). The original upload is never modified —
 *              the login page, portal, PDFs and emails keep using it.
 *
 *              Driver-agnostic: reads the upload through StorageClient::read()
 *              (local disk or S3) and serves the derivatives through
 *              api/v1/storage/logo.php. Anything it cannot process (SVG, an
 *              image too large to decode safely, an unwritable cache, a
 *              missing file) falls back to the original upload's signed URL —
 *              the sidebar then looks exactly as it did before.
 *
 *              The background colour decides how the sidebar presents it:
 *                dark        blended into the (always dark) sidebar, so a
 *                            black canvas disappears (shell.css)
 *                light       a rounded badge — dark ink needs its light plate
 *                clear       a transparent PNG, shown as-is
 *                mixed       no uniform border (a photo, a full-bleed badge) —
 *                            not trimmed, only resized
 *
 * @session     S-SIDEBAR-LOGO
 */

namespace FleetForge\Ui;

use FleetForge\Storage\StorageClient;

final class BrandLogo
{
    /** Output caps — about 3× the widest CSS slot (the ~190 px brand row). */
    private const MAX_OUT_W  = 720;
    private const MAX_OUT_H  = 360;
    private const MARK_SIZE  = 192;

    /** Largest image decoded. GD holds ~5 bytes/pixel; bigger uploads fall back. */
    private const MAX_SRC_PX = 24_000_000;

    /** Working-copy width — trimming is measured on this, not the full upload. */
    private const WORK_W     = 1600;

    /** Per-channel distance that still counts as "background" (JPEG noise). */
    private const BG_TOL     = 40;

    /** Bump when the output changes, so every cached derivative rebuilds. */
    private const VERSION    = 1;

    /**
     * Fallbacks that may be transient (the file not there yet, a storage
     * blip) are retried after this many seconds; the rest (SVG, too large,
     * undecodable, blank) are final for that upload.
     */
    private const RETRY_SECS = 600;
    private const TRANSIENT  = ['unreadable', 'error'];

    /**
     * What the sidebar renders for the current logo, or null when none is set.
     *
     * @return array{url:string, markUrl:string, bg:string, wide:bool}|null
     *         bg = dark | light | clear | mixed | '' (untouched original)
     */
    public static function forSidebar(): ?array
    {
        $key = (string) (settings_get('brand.logo_path') ?? '');
        if ($key === '') {
            return null;
        }
        $meta = self::meta($key);
        if ($meta === null || !empty($meta['fallback'])) {
            return ['url' => StorageClient::url($key, 86400), 'markUrl' => '', 'bg' => '', 'wide' => false];
        }
        $base = base_url('api/v1/storage/logo') . '?v=' . rawurlencode((string) $meta['v']);
        return [
            'url'     => $base,
            'markUrl' => !empty($meta['mark']) ? $base . '&kind=mark' : '',
            'bg'      => (string) $meta['bg'],
            'wide'    => (int) $meta['w'] > 2 * (int) $meta['h'],
        ];
    }

    /**
     * The cached derivative's metadata for a storage key, building it on the
     * first call. Returns null only when nothing could be cached at all (the
     * caller then uses the original).
     *
     * @return array<string,mixed>|null
     */
    public static function meta(string $key): ?array
    {
        $base = self::cacheBase($key);
        if (is_file($base . '.json')) {
            $meta  = json_decode((string) @file_get_contents($base . '.json'), true);
            $stale = is_array($meta) && !empty($meta['fallback'])
                && in_array($meta['why'] ?? '', self::TRANSIENT, true)
                && time() - (int) @filemtime($base . '.json') > self::RETRY_SECS;
            if (is_array($meta) && !$stale) {
                return $meta;
            }
        }
        return self::build($key, $base);
    }

    /** Absolute path of a cached derivative ('full' | 'mark'), or null. */
    public static function file(string $key, string $kind): ?string
    {
        $meta = self::meta($key);
        if ($meta === null || !empty($meta['fallback'])) {
            return null;
        }
        if ($kind === 'mark' && empty($meta['mark'])) {
            return null;
        }
        $path = self::cacheBase($key) . ($kind === 'mark' ? '.mark.png' : '.png');
        return is_file($path) ? $path : null;
    }

    private static function cacheBase(string $key): string
    {
        return FF_ROOT . '/storage/generated/brand/' . sha1(self::VERSION . '|' . $key);
    }

    /**
     * Build + cache the derivatives. Any failure caches a fallback marker so
     * the (possibly expensive) attempt is not repeated on every page render.
     *
     * @return array<string,mixed>|null
     */
    private static function build(string $key, string $base): ?array
    {
        $dir = dirname($base);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        if (!is_writable($dir)) {
            return null;
        }
        $v = substr(sha1(self::VERSION . '|' . $key), 0, 12);

        try {
            $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                return self::save($base, ['fallback' => true, 'v' => $v, 'why' => 'type']);
            }
            $bytes = StorageClient::read($key);
            $info  = $bytes !== null ? @getimagesizefromstring($bytes) : false;
            if ($info === false) {
                return self::save($base, ['fallback' => true, 'v' => $v, 'why' => 'unreadable']);
            }
            $px = (int) $info[0] * (int) $info[1];
            if ($px > self::MAX_SRC_PX) {
                return self::save($base, ['fallback' => true, 'v' => $v, 'why' => 'too_large']);
            }
            // A large-but-allowed upload can outgrow the default 128M while
            // decoding; raise the ceiling for this one-off build only.
            $need = (int) ($px * 5 + 64 * 1024 * 1024);
            if (self::bytes((string) ini_get('memory_limit')) < $need) {
                @ini_set('memory_limit', (string) $need);
            }

            $src = @imagecreatefromstring($bytes);
            unset($bytes);
            if ($src === false) {
                return self::save($base, ['fallback' => true, 'v' => $v, 'why' => 'decode']);
            }
            $img = self::fit($src, self::WORK_W, self::WORK_W);
            imagedestroy($src);

            [$bg, $bgKind] = self::background($img);
            $box = $bgKind === 'mixed' ? [0, 0, imagesx($img) - 1, imagesy($img) - 1] : self::contentBox($img, $bg);
            if ($box === null) {
                // All background — nothing to show; keep the original.
                return self::save($base, ['fallback' => true, 'v' => $v, 'why' => 'blank']);
            }
            [$x0, $y0, $x1, $y1] = $box;
            $cw = $x1 - $x0 + 1;
            $ch = $y1 - $y0 + 1;

            // Even breathing room on every side — for a light badge this is
            // the plate's margin; for dark/clear it is invisible.
            $pad  = $bgKind === 'mixed' ? 0 : max(2, (int) round(min($cw, $ch) * 0.12));
            $full = self::canvas($cw + 2 * $pad, $ch + 2 * $pad, $bg);
            imagecopy($full, $img, $pad, $pad, $x0, $y0, $cw, $ch);
            $out = self::fit($full, self::MAX_OUT_W, self::MAX_OUT_H);
            imagedestroy($full);
            self::writePng($out, $base . '.png');
            $meta = ['v' => $v, 'bg' => $bgKind, 'w' => imagesx($out), 'h' => imagesy($out), 'mark' => false];
            imagedestroy($out);

            if ($bgKind !== 'mixed') {
                $mark = self::markBox($img, $bg, $x0, $y0, $x1, $y1);
                if ($mark !== null) {
                    [$mx0, $my0, $mx1, $my1] = $mark;
                    $mw   = $mx1 - $mx0 + 1;
                    $mh   = $my1 - $my0 + 1;
                    $side = max($mw, $mh);
                    $mpad = max(2, (int) round($side * 0.1));
                    $sq   = self::canvas($side + 2 * $mpad, $side + 2 * $mpad, $bg);
                    imagecopy($sq, $img, $mpad + intdiv($side - $mw, 2), $mpad + intdiv($side - $mh, 2), $mx0, $my0, $mw, $mh);
                    $sqOut = self::fit($sq, self::MARK_SIZE, self::MARK_SIZE);
                    imagedestroy($sq);
                    self::writePng($sqOut, $base . '.mark.png');
                    imagedestroy($sqOut);
                    $meta['mark'] = true;
                }
            }
            imagedestroy($img);
            return self::save($base, $meta);
        } catch (\Throwable $e) {
            error_log('[BrandLogo] ' . $key . ': ' . $e->getMessage());
            return self::save($base, ['fallback' => true, 'v' => $v, 'why' => 'error']);
        }
    }

    /**
     * The border colour, read from the four corners. They must agree (within
     * BG_TOL) to count as a background; transparent corners → 'clear'.
     *
     * @return array{0:array{int,int,int,int}, 1:string} [rgba (GD alpha 0–127), kind]
     */
    private static function background(\GdImage $img): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $corners = [];
        foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
            $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
            $corners[] = [$c['red'], $c['green'], $c['blue'], $c['alpha']];
        }
        if (min(array_column($corners, 3)) >= 100) {
            return [[0, 0, 0, 127], 'clear'];
        }
        [$r, $g, $b] = $corners[0];
        foreach ($corners as $c) {
            if ($c[3] >= 100 || max(abs($c[0] - $r), abs($c[1] - $g), abs($c[2] - $b)) > self::BG_TOL) {
                return [[0, 0, 0, 127], 'mixed'];
            }
        }
        $n  = count($corners);
        $bg = [
            (int) round(array_sum(array_column($corners, 0)) / $n),
            (int) round(array_sum(array_column($corners, 1)) / $n),
            (int) round(array_sum(array_column($corners, 2)) / $n),
            0,
        ];
        // Relative luminance (sRGB weights) — the sidebar blends a dark
        // canvas away and badges a light one.
        $lum = (0.2126 * $bg[0] + 0.7152 * $bg[1] + 0.0722 * $bg[2]) / 255;
        return [$bg, $lum < 0.35 ? 'dark' : 'light'];
    }

    /** Is the pixel foreground (visibly different from the background)? */
    private static function isInk(\GdImage $img, int $x, int $y, array $bg): bool
    {
        $c = imagecolorat($img, $x, $y);
        $a = ($c >> 24) & 0x7F;
        if ($a >= 100) {
            return false;               // (nearly) transparent
        }
        if ($bg[3] === 127) {
            return true;                // clear background: anything visible is ink
        }
        return max(
            abs((($c >> 16) & 0xFF) - $bg[0]),
            abs((($c >> 8) & 0xFF) - $bg[1]),
            abs(($c & 0xFF) - $bg[2])
        ) > self::BG_TOL;
    }

    /**
     * Bounding box of the ink, scanning inward from each edge (stops at the
     * first ink row/column, so a mostly-margin logo is cheap).
     *
     * @return array{int,int,int,int}|null [x0, y0, x1, y1] or null when blank
     */
    private static function contentBox(\GdImage $img, array $bg): ?array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $rowHas = static function (int $y) use ($img, $bg, $w): bool {
            for ($x = 0; $x < $w; $x++) {
                if (self::isInk($img, $x, $y, $bg)) {
                    return true;
                }
            }
            return false;
        };
        $y0 = 0;
        while ($y0 < $h && !$rowHas($y0)) {
            $y0++;
        }
        if ($y0 >= $h) {
            return null;
        }
        $y1 = $h - 1;
        while ($y1 > $y0 && !$rowHas($y1)) {
            $y1--;
        }
        $colHas = static function (int $x) use ($img, $bg, $y0, $y1): bool {
            for ($y = $y0; $y <= $y1; $y++) {
                if (self::isInk($img, $x, $y, $bg)) {
                    return true;
                }
            }
            return false;
        };
        $x0 = 0;
        while ($x0 < $w && !$colHas($x0)) {
            $x0++;
        }
        $x1 = $w - 1;
        while ($x1 > $x0 && !$colHas($x1)) {
            $x1--;
        }
        return [$x0, $y0, $x1, $y1];
    }

    /**
     * The logo's left-hand icon, if it has one: a roughly square block of ink
     * spanning most of the logo's height, followed by a clear gap before the
     * rest (the wordmark). A pure wordmark returns null — the collapsed rail
     * then keeps the app's own tile rather than a clipped first letter.
     *
     * @return array{int,int,int,int}|null
     */
    private static function markBox(\GdImage $img, array $bg, int $x0, int $y0, int $x1, int $y1): ?array
    {
        $ch     = $y1 - $y0 + 1;
        $minGap = max(2, (int) round($ch * 0.04));
        $colInk = static function (int $x) use ($img, $bg, $y0, $y1): bool {
            for ($y = $y0; $y <= $y1; $y++) {
                if (self::isInk($img, $x, $y, $bg)) {
                    return true;
                }
            }
            return false;
        };
        // Walk the first run of ink columns, then the gap after it.
        $x = $x0;
        while ($x <= $x1 && $colInk($x)) {
            $x++;
        }
        $segEnd = $x - 1;
        $gap    = 0;
        while ($x <= $x1 && !$colInk($x)) {
            $gap++;
            $x++;
        }
        if ($x > $x1 || $gap < $minGap) {
            return null;                // no gap, or nothing after it
        }
        $sw = $segEnd - $x0 + 1;
        if ($sw < $ch * 0.5 || $sw > $ch * 1.6) {
            return null;                // not square-ish — a letter, not a mark
        }
        // The segment's own vertical extent must cover most of the logo.
        $sy0 = $y0;
        $sy1 = $y1;
        $rowInk = static function (int $y) use ($img, $bg, $x0, $segEnd): bool {
            for ($xx = $x0; $xx <= $segEnd; $xx++) {
                if (self::isInk($img, $xx, $y, $bg)) {
                    return true;
                }
            }
            return false;
        };
        while ($sy0 < $sy1 && !$rowInk($sy0)) {
            $sy0++;
        }
        while ($sy1 > $sy0 && !$rowInk($sy1)) {
            $sy1--;
        }
        if (($sy1 - $sy0 + 1) < $ch * 0.6) {
            return null;
        }
        return [$x0, $sy0, $segEnd, $sy1];
    }

    /** A truecolor canvas filled with the background (transparent-capable). */
    private static function canvas(int $w, int $h, array $bg): \GdImage
    {
        $c = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagealphablending($c, false);
        imagesavealpha($c, true);
        imagefill($c, 0, 0, imagecolorallocatealpha($c, $bg[0], $bg[1], $bg[2], $bg[3]));
        return $c;
    }

    /** A copy scaled down (never up) to fit within $maxW × $maxH, alpha kept. */
    private static function fit(\GdImage $src, int $maxW, int $maxH): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $s = min(1.0, $maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $s));
        $nh = max(1, (int) round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    /** Write a PNG atomically (temp file + rename) — two first renders can race. */
    private static function writePng(\GdImage $img, string $path): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!imagepng($img, $tmp, 9) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('could not write ' . basename($path));
        }
    }

    /** @param array<string,mixed> $meta */
    private static function save(string $base, array $meta): array
    {
        $tmp = $base . '.json.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($meta)) !== false) {
            @rename($tmp, $base . '.json');
        }
        @unlink($tmp);
        return $meta;
    }

    /** "128M" → bytes; -1 (unlimited) → PHP_INT_MAX. */
    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        return match (strtoupper(substr($v, -1))) {
            'G'     => $n * 1024 ** 3,
            'M'     => $n * 1024 ** 2,
            'K'     => $n * 1024,
            default => $n,
        };
    }
}
