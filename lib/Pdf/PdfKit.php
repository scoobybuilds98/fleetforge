<?php
declare(strict_types=1);

/**
 * FleetForge — shared PDF letterhead + page kit (S-PDF-LETTERHEAD)
 *
 * @file        lib/Pdf/PdfKit.php
 * @description ONE look for every PDF the app produces (invoices, statements,
 *              dunning letters, credit applications, accounting reports, the
 *              year-end package). Before this, each of eight generators built
 *              its own header from `company.*` settings — none showed the
 *              logo (the Settings → Brand "Show logo on PDFs", accent colour
 *              and invoice footer text were saved but never read), the
 *              address printed twice when `company.address` already held the
 *              city/postal code, and every report stamped a raw server clock.
 *
 *              What the kit provides:
 *                - brand()      company name, de-duplicated address lines,
 *                               contact + tax lines, accent colour, footer
 *                               text, and the logo with its presentation
 *                - render()     body HTML + a document description → PDF
 *                               bytes: letterhead band on page 1, a slim
 *                               running header from page 2, "Page X of Y"
 *                               footer, optional DRAFT/VOID watermark
 *                - stream()     send PDF bytes to the browser (inline or
 *                               download) — replaces the pop-up-after-fetch
 *                               pattern that browsers silently block
 *                - money() / date() / period() — display formatting that
 *                               matches the screens (money is bcmath, D16)
 *
 *              Logo presentation: company logos are often exported on a
 *              coloured canvas (Mainland's is white ink on black). Printed on
 *              white paper that is a black box, and a transparent white-ink
 *              logo is invisible. So the letterhead samples the logo itself:
 *                dark canvas        → a band in THAT colour, so the logo
 *                                     blends in edge to edge
 *                transparent, light ink → a neutral dark band
 *                light / mixed / transparent dark ink → a white header with
 *                                     an accent rule
 *              The trimmed copy from FleetForge\Ui\BrandLogo is preferred (no
 *              baked-in margins); the raw upload, then public/media/login-logo
 *              are fallbacks. No logo (or "Show logo" off) → the company name
 *              is set as a wordmark in the band.
 *
 *              Paper is US Letter (North American standard) for every
 *              document, so a merged batch PDF never mixes page sizes.
 *
 * Required by: lib/Billing/InvoicePdfGenerator.php, lib/Accounting/ReportPdfRenderer.php,
 *              lib/Accounting/DunningLetterGenerator.php, lib/Accounting/YearEndService.php,
 *              lib/Pdf/CreditApplicationPdf.php, api/v1/accounting/ar/statement.php,
 *              api/v1/invoices/batch_download.php, api/v1/invoices/pdf.php,
 *              api/v1/portal/invoices/pdf.php
 * Defines:     FleetForge\Pdf\PdfKit
 *
 * Decisions: D-PDF-LETTERHEAD-1 (one kit, logo presentation sampled from the logo)
 * @session S-PDF-LETTERHEAD
 */

namespace FleetForge\Pdf;

use FleetForge\Storage\StorageClient;
use FleetForge\Ui\BrandLogo;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class PdfKit
{
    /**
     * Bump when the printed look changes. Stored invoice PDFs live under a
     * /v{LAYOUT}/ folder, so a bump makes every invoice re-render once in
     * the new look instead of serving a stale design forever.
     */
    public const LAYOUT = 2;

    /** Every document uses the same paper so merged PDFs never mix sizes. */
    public const PAPER = 'Letter';

    /** Neutral band for a light-ink transparent logo or the no-logo wordmark. */
    private const DEFAULT_BAND = '#111827';
    private const DEFAULT_ACCENT = '#2563eb';

    /** @var array<string,mixed>|null memoised per request (settings don't change mid-request) */
    private static ?array $brand = null;

    private function __construct() {}

    // ─────────────────────────────────────────────────────────────────
    // Brand
    // ─────────────────────────────────────────────────────────────────

    /**
     * Everything a letterhead needs, resolved once per request.
     *
     * @return array{
     *   name:string, address_lines:list<string>, contact_line:string,
     *   tax_line:string, website:string, phone:string, email:string,
     *   accent:string, footer_text:string,
     *   logo:?array{bytes:string, mode:string, band:string}
     * }
     *   logo.mode 'band' = print on a dark band of colour logo.band;
     *   'plain' = print on white. logo is null when there is no usable logo
     *   or Settings → Brand → "Show logo on PDFs" is off.
     */
    public static function brand(): array
    {
        if (self::$brand !== null) {
            return self::$brand;
        }
        $s = static fn (string $k): string => trim((string) (\settings_get($k, '') ?? ''));

        $name    = $s('company.name') ?: 'FleetForge';
        $address = $s('company.address');
        $city    = $s('company.city');
        $prov    = $s('company.province');
        $postal  = $s('company.postal_code');

        $lines = self::addressLines($address, $city, $prov, $postal);

        $phone   = $s('company.phone');
        $email   = $s('company.email');
        $website = $s('company.website');
        $contact = implode('  ·  ', array_filter([$phone, $email, $website]));

        $gst = $s('company.gst_number');
        $pst = $s('company.pst_number');
        $tax = implode('  ·  ', array_filter([
            $gst !== '' ? 'GST/HST No. ' . $gst : '',
            $pst !== '' ? 'PST No. ' . $pst : '',
        ]));

        // Accent: the PDF override from Settings → Brand, else the brand
        // colour. Validated so a typo can't inject CSS into every document.
        $accent = '';
        foreach ([$s('pdf.accent_color'), $s('brand.primary_color')] as $candidate) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $candidate)) {
                $accent = strtolower($candidate);
                break;
            }
        }

        $logo = null;
        if ($s('pdf.show_logo') !== '0') {
            $logo = self::resolveLogo($s('brand.logo_path'));
        }

        return self::$brand = [
            'name'          => $name,
            'address_lines' => $lines,
            'contact_line'  => $contact,
            'tax_line'      => $tax,
            'website'       => $website,
            'phone'         => $phone,
            'email'         => $email,
            'accent'        => $accent !== '' ? $accent : self::DEFAULT_ACCENT,
            'footer_text'   => $s('pdf.invoice_footer_text'),
            'logo'          => $logo,
        ];
    }

    /**
     * Address lines for a letterhead or recipient block.
     *
     * The address field is free text and often already ends with the city
     * and postal code ("9616 188 Street, Surrey, BC Canada V4N 3M2") —
     * printing the structured city line under it doubled the address on
     * every invoice. The city line is added only when the address doesn't
     * already carry the postal code (or, with no postal code, the city).
     *
     * @return list<string>
     */
    public static function addressLines(string $address, string $city, string $prov, string $postal): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $address) ?: [])));
        $cityLine = trim($city . ($prov !== '' ? ($city !== '' ? ', ' : '') . $prov : '') . ($postal !== '' ? ' ' . $postal : ''));
        $norm = static fn (string $v): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $v));
        $haystack = $norm($address);
        $already = $postal !== '' ? str_contains($haystack, $norm($postal))
                                  : ($city !== '' && str_contains($haystack, $norm($city)));
        if ($cityLine !== '' && !$already) {
            $lines[] = $cityLine;
        }
        return $lines;
    }

    /** Forget the memoised brand (tests, or a long CLI run after a settings change). */
    public static function resetBrand(): void
    {
        self::$brand = null;
    }

    /**
     * Find the logo bytes and decide how to present them.
     *
     * @return array{bytes:string, mode:string, band:string}|null
     */
    private static function resolveLogo(string $key): ?array
    {
        $bytes = null;
        if ($key !== '') {
            // 1. BrandLogo's trimmed copy: margins removed, capped at 720 px.
            try {
                $path = BrandLogo::file($key, 'full');
                if ($path !== null && is_file($path)) {
                    $bytes = (string) file_get_contents($path);
                }
            } catch (\Throwable $e) {
                error_log('[PdfKit] BrandLogo copy unavailable: ' . $e->getMessage());
            }
            // 2. The upload itself (any storage driver).
            if ($bytes === null || $bytes === '') {
                try {
                    $bytes = StorageClient::read($key);
                } catch (\Throwable $e) {
                    error_log('[PdfKit] logo read failed for ' . $key . ': ' . $e->getMessage());
                    $bytes = null;
                }
            }
        }
        // 3. The login-page logo shipped with the install.
        if ($bytes === null || $bytes === '') {
            foreach (['png', 'jpg', 'jpeg'] as $ext) {
                $f = FF_ROOT . '/public/media/login-logo.' . $ext;
                if (is_file($f)) {
                    $bytes = (string) file_get_contents($f);
                    break;
                }
            }
        }
        if ($bytes === null || $bytes === '') {
            return null;
        }

        return ['bytes' => $bytes] + self::presentation($bytes);
    }

    /**
     * Sample the image to choose band vs white. See the file docblock.
     *
     * @return array{mode:string, band:string}
     */
    public static function presentation(string $bytes): array
    {
        $plain = ['mode' => 'plain', 'band' => ''];
        if (!function_exists('imagecreatefromstring')) {
            return $plain;
        }
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return $plain; // SVG or unreadable — white header is always legible for dark ink
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 4 || $h < 4) {
            imagedestroy($im);
            return $plain;
        }

        $px = static function (int $x, int $y) use ($im): array {
            $c = imagecolorat($im, $x, $y);
            // imagecolorat on a palette image returns an index, not packed RGB.
            $rgba = imagecolorsforindex($im, $c);
            return [$rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']];
        };
        $lum = static fn (array $p): float => (0.2126 * $p[0] + 0.7152 * $p[1] + 0.0722 * $p[2]) / 255;

        $corners = [$px(1, 1), $px($w - 2, 1), $px(1, $h - 2), $px($w - 2, $h - 2)];
        $transparent = count(array_filter($corners, static fn (array $p): bool => $p[3] >= 100)) >= 3;

        if ($transparent) {
            // What colour is the ink? Average the opaque pixels on a grid.
            $sum = 0.0;
            $n = 0;
            for ($gy = 0; $gy < 24; $gy++) {
                for ($gx = 0; $gx < 48; $gx++) {
                    $p = $px((int) (($gx + 0.5) * $w / 48), (int) (($gy + 0.5) * $h / 24));
                    if ($p[3] < 40) {
                        $sum += $lum($p);
                        $n++;
                    }
                }
            }
            imagedestroy($im);
            // White/pale ink would vanish on paper — give it a dark band.
            return ($n > 0 && $sum / $n > 0.62) ? ['mode' => 'band', 'band' => self::DEFAULT_BAND] : $plain;
        }

        $spread = 0;
        foreach ([0, 1, 2] as $ch) {
            $vals = array_column($corners, $ch);
            $spread = max($spread, max($vals) - min($vals));
        }
        if ($spread > 40) {
            imagedestroy($im);
            return $plain; // no uniform canvas (photo, full-bleed badge)
        }
        $avg = [
            (int) round(array_sum(array_column($corners, 0)) / 4),
            (int) round(array_sum(array_column($corners, 1)) / 4),
            (int) round(array_sum(array_column($corners, 2)) / 4),
        ];
        imagedestroy($im);
        if ($lum($avg) < 0.35) {
            // Band in the canvas colour itself, so the logo's edges vanish.
            return ['mode' => 'band', 'band' => sprintf('#%02x%02x%02x', $avg[0], $avg[1], $avg[2])];
        }
        return $plain;
    }

    // ─────────────────────────────────────────────────────────────────
    // Rendering
    // ─────────────────────────────────────────────────────────────────

    /**
     * A configured mPDF instance on the kit's paper and margins. render()
     * uses it; batch_download uses it directly to merge stored invoices.
     */
    public static function newMpdf(string $orientation = 'P'): Mpdf
    {
        $tmpDir = FF_ROOT . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        return new Mpdf([
            'mode'          => 'utf-8',
            'format'        => self::PAPER . ($orientation === 'L' ? '-L' : ''),
            'margin_left'   => 14,
            'margin_right'  => 14,
            // Pages 2+: room for the running header. Page 1 overrides this
            // in CSS (@page :first) because the letterhead is in the body.
            'margin_top'    => 22,
            'margin_bottom' => 20,
            'margin_header' => 8,
            'margin_footer' => 8,
            'default_font'  => 'dejavusans',
            'tempDir'       => $tmpDir,
        ]);
    }

    /**
     * Render a document to PDF bytes.
     *
     * @param string $body HTML for the page body (use the kit's CSS classes)
     * @param array{
     *   title:string, reference?:string, variant?:string, orientation?:string,
     *   meta?:array<string,string>, status?:array{label:string,tone:string},
     *   footer_note?:string, watermark?:string, generated?:bool, css?:string
     * } $doc
     *   title       big heading in the band ("Invoice", "Profit & Loss")
     *   reference   second line ("INV-2026-02319", a period)
     *   variant     'document' (customer-facing: company block + meta table)
     *               or 'report' (internal: compact, meta as one line)
     *   meta        label => plain text (escaped here)
     *   status      a coloured badge; tone good|warn|bad|info|neutral
     *   footer_note one centred line above the footer (e.g. "Thank you…")
     *   watermark   faint diagonal text (DRAFT / VOID)
     *   generated   add "Generated <local time>" to the footer
     *   css         extra CSS appended after the kit's (document-specific)
     */
    public static function render(string $body, array $doc): string
    {
        $brand = self::brand();
        $mpdf  = self::newMpdf($doc['orientation'] ?? 'P');
        $mpdf->SetTitle(trim(($doc['title'] ?? 'Document') . ' ' . ($doc['reference'] ?? '')));
        $mpdf->SetAuthor($brand['name']);
        $mpdf->SetCreator('FleetForge');
        if (($brand['logo']['bytes'] ?? '') !== '') {
            // imageVars keeps the logo out of the HTML (no temp file, no
            // megabyte data: URI parsed for every page header).
            $mpdf->imageVars['fflogo'] = $brand['logo']['bytes'];
        }
        if (!empty($doc['watermark'])) {
            $mpdf->SetWatermarkText((string) $doc['watermark'], 0.06);
            $mpdf->showWatermarkText = true;
        }

        $html = '<style>' . self::css($brand) . ($doc['css'] ?? '') . '</style>'
              . self::chrome($doc, $brand)
              . self::letterhead($doc, $brand)
              . $body;
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * Send PDF bytes to the browser and end the request.
     *
     * Inline by default so the browser's viewer opens it (the user can
     * still save); $download forces a file save.
     */
    public static function stream(string $bytes, string $filename, bool $download = false): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . self::filename($filename) . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }

    /** A safe download name: ASCII letters, digits, dot, dash, underscore; always .pdf. */
    public static function filename(string $name): string
    {
        $base = preg_replace('/\.pdf$/i', '', trim($name)) ?? '';
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $base), '._-');
        return ($base !== '' ? $base : 'document') . '.pdf';
    }

    // ─────────────────────────────────────────────────────────────────
    // Formatting helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * "$1,234.50" — bcmath rounding (half-up on the 3rd decimal), never a
     * float (D16). Negative → "-$1,234.50", like the screens. Non-numeric → "—".
     */
    public static function money(mixed $amount, string $currency = ''): string
    {
        $v = trim((string) ($amount ?? ''));
        if ($v === '' || !preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return '—';
        }
        $neg = str_starts_with($v, '-');
        $abs = bcadd(ltrim($v, '-'), '0.005', 2); // bcadd truncates → +0.005 rounds half-up
        [$int, $frac] = explode('.', $abs) + [1 => '00'];
        $int = strrev(implode(',', str_split(strrev($int), 3)));
        $out = '$' . $int . '.' . str_pad($frac, 2, '0');
        if ($neg && $out !== '$0.00') {
            $out = '-' . $out;
        }
        return $out . ($currency !== '' ? ' ' . $currency : '');
    }

    /** "Sep 17, 2026" for a Y-m-d business date; "—" when empty. */
    public static function date(mixed $ymd): string
    {
        return \format_date($ymd);
    }

    /** "Jan 1 – Aug 31, 2026" / "Dec 1, 2025 – Jan 31, 2026". */
    public static function period(mixed $from, mixed $to): string
    {
        $f = (string) ($from ?? '');
        $t = (string) ($to ?? '');
        if ($f === '' || $t === '') {
            return trim(self::date($f) . ' – ' . self::date($t), ' –');
        }
        try {
            $df = new \DateTime($f);
            $dt = new \DateTime($t);
        } catch (\Throwable) {
            return $f . ' – ' . $t;
        }
        return $df->format('Y') === $dt->format('Y')
            ? $df->format('M j') . ' – ' . $dt->format('M j, Y')
            : $df->format('M j, Y') . ' – ' . $dt->format('M j, Y');
    }

    /** Now, in the company's timezone ("Sep 24, 2026 3:15 AM"). */
    public static function generatedAt(): string
    {
        return \format_datetime(\ff_now_utc(), 'M j, Y g:i A');
    }

    /** Coloured status pill. tone: good|warn|bad|info|neutral. */
    public static function badge(string $label, string $tone = 'neutral'): string
    {
        $tone = in_array($tone, ['good', 'warn', 'bad', 'info', 'neutral'], true) ? $tone : 'neutral';
        return '<span class="ff-badge ff-badge-' . $tone . '">' . \e($label) . '</span>';
    }

    // ─────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────

    /**
     * Named page header/footer blocks. Page 1 shows no running header (the
     * letterhead is in the body); pages 2+ repeat company · title.
     */
    private static function chrome(array $doc, array $brand): string
    {
        $e = static fn ($v): string => \e((string) $v);
        $title = $e($doc['title'] ?? '');
        $ref   = $e($doc['reference'] ?? '');

        $footLeft = array_filter([$brand['name'], $brand['website'], $brand['phone']]);
        $footText = $e(implode('  ·  ', $footLeft));
        if (!empty($doc['generated'])) {
            $footText .= '<br>Generated ' . $e(self::generatedAt());
        }
        $note = trim((string) ($doc['footer_note'] ?? ''));

        return '<htmlpageheader name="ffNone"></htmlpageheader>'
            . '<htmlpageheader name="ffRun"><table class="ff-run" width="100%"><tr>'
            . '<td>' . $e($brand['name']) . '  —  ' . $title . '</td>'
            . '<td class="r">' . $ref . '</td>'
            . '</tr></table></htmlpageheader>'
            . '<htmlpagefooter name="ffFoot">'
            . ($note !== '' ? '<div class="ff-foot-note">' . $e($note) . '</div>' : '')
            . '<table class="ff-foot" width="100%"><tr>'
            . '<td>' . $footText . '</td>'
            . '<td class="r">Page {PAGENO} of {nbpg}</td>'
            . '</tr></table></htmlpagefooter>';
    }

    /** The page-1 letterhead: band (logo + title) and, for documents, the company/meta row. */
    private static function letterhead(array $doc, array $brand): string
    {
        $e       = static fn ($v): string => \e((string) $v);
        $variant = ($doc['variant'] ?? 'document') === 'report' ? 'report' : 'document';
        $logo    = $brand['logo'];
        $onBand  = $logo === null || $logo['mode'] === 'band';
        $bandBg  = $logo !== null && $logo['mode'] === 'band' ? $logo['band'] : self::DEFAULT_BAND;

        $mark = $logo !== null
            ? '<img src="var:fflogo" class="ff-logo">'
            : '<div class="ff-wordmark">' . $e($brand['name']) . '</div>';

        $band = '<div class="' . ($onBand ? 'ff-band' : 'ff-band ff-band-plain') . '"'
              . ($onBand ? ' style="background-color:' . $e($bandBg) . ';"' : '') . '>'
              . '<table width="100%" class="ff-band-t"><tr>'
              . '<td class="ff-band-logo">' . $mark . '</td>'
              . '<td class="ff-band-title">'
              // Long titles ("Credit Application", "Working Trial Balance")
              // step down a size so they stay on one line beside the logo.
              . '<div class="ff-doc-title' . (mb_strlen((string) ($doc['title'] ?? '')) > 13 ? ' ff-doc-title-long' : '') . '">' . $e($doc['title'] ?? '') . '</div>'
              . (($doc['reference'] ?? '') !== '' ? '<div class="ff-doc-ref">' . $e($doc['reference']) . '</div>' : '')
              . '</td></tr></table></div>';

        $meta = $doc['meta'] ?? [];
        $status = $doc['status'] ?? null;

        if ($variant === 'report') {
            $bits = [];
            foreach ($meta as $k => $v) {
                $bits[] = '<span class="ff-rmeta-k">' . $e($k) . '</span> ' . $e($v);
            }
            $bits[] = '<span class="ff-rmeta-k">Generated</span> ' . $e(self::generatedAt());
            // mPDF ignores horizontal padding on inline spans — space the
            // separators with non-breaking spaces instead.
            $sep = '&nbsp;&nbsp;<span class="ff-rmeta-sep">·</span>&nbsp;&nbsp;';
            return $band . '<div class="ff-rmeta">' . $e($brand['name']) . $sep . implode($sep, $bits) . '</div>';
        }

        $co = '<div class="ff-co-name">' . $e($brand['name']) . '</div><div class="ff-co-lines">';
        $coLines = $brand['address_lines'];
        if ($brand['contact_line'] !== '') $coLines[] = $brand['contact_line'];
        if ($brand['tax_line'] !== '')     $coLines[] = $brand['tax_line'];
        $co .= implode('<br>', array_map($e, $coLines)) . '</div>';

        $rows = '';
        foreach ($meta as $k => $v) {
            $rows .= '<tr><td class="k">' . $e($k) . '</td><td class="v">' . $e($v) . '</td></tr>';
        }
        if ($status !== null) {
            $rows .= '<tr><td class="k">Status</td><td class="v">' . self::badge((string) $status['label'], (string) ($status['tone'] ?? 'neutral')) . '</td></tr>';
        }

        return $band
            . '<table width="100%" class="ff-lh"><tr>'
            . '<td class="ff-lh-co">' . $co . '</td>'
            . '<td class="ff-lh-meta">' . ($rows !== '' ? '<table class="ff-meta" align="right">' . $rows . '</table>' : '') . '</td>'
            . '</tr></table>';
    }

    /** The kit stylesheet. Documents append their own via $doc['css']. */
    private static function css(array $brand): string
    {
        $accent = $brand['accent'];
        return <<<CSS
@page { header: html_ffRun; footer: html_ffFoot; }
@page :first { header: html_ffNone; margin-top: 12mm; }
body { font-family: dejavusans; font-size: 9pt; color: #1f2937; line-height: 1.45; }
table { border-collapse: collapse; }
a { color: {$accent}; }
.r { text-align: right; }
.num { text-align: right; white-space: nowrap; }
.nw { white-space: nowrap; }
.muted { color: #6b7280; }
.small { font-size: 7.5pt; }

/* Band */
.ff-band { border-radius: 2.5mm; padding: 4.5mm 6mm; margin-bottom: 5mm; }
.ff-band-plain { background-color: #ffffff; border-radius: 0; padding: 0 0 3.5mm 0; border-bottom: 1.4pt solid {$accent}; }
.ff-band-t td { vertical-align: middle; }
.ff-band-logo { width: 45%; }
.ff-logo { height: 15mm; }
.ff-wordmark { font-size: 15pt; font-weight: bold; color: #ffffff; }
.ff-band-plain .ff-wordmark { color: #111827; }
.ff-band-title { text-align: right; }
.ff-doc-title { font-size: 19pt; color: #ffffff; letter-spacing: 1.5pt; text-transform: uppercase; }
.ff-doc-ref { font-size: 9.5pt; color: #cbd5e1; margin-top: 1mm; }
.ff-doc-title-long { font-size: 14.5pt; letter-spacing: 1pt; }
.ff-band-plain .ff-doc-title { color: #111827; }
.ff-band-plain .ff-doc-ref { color: {$accent}; }

/* Company + meta row (documents) */
.ff-lh { margin-bottom: 6mm; }
.ff-lh td { vertical-align: top; }
.ff-lh-co { width: 58%; }
.ff-co-name { font-size: 11pt; font-weight: bold; color: #111827; }
.ff-co-lines { font-size: 8pt; color: #4b5563; line-height: 1.55; margin-top: 0.8mm; }
.ff-lh-meta { width: 42%; }
.ff-meta td { padding: 0.6mm 0 0.6mm 4mm; font-size: 8.5pt; }
.ff-meta td.k { color: #6b7280; text-align: right; }
.ff-meta td.v { color: #111827; font-weight: bold; text-align: right; }

/* Report meta line */
.ff-rmeta { font-size: 8pt; color: #4b5563; margin: -2mm 0 5mm; }
.ff-rmeta-k { color: #9ca3af; text-transform: uppercase; font-size: 6.8pt; letter-spacing: 0.4pt; }
.ff-rmeta-sep { color: #9ca3af; }

/* Badges */
.ff-badge { font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5pt; padding: 1mm 3mm; }
.ff-badge-good { background-color: #bbf7d0; color: #14532d; }
.ff-badge-warn { background-color: #fde68a; color: #78350f; }
.ff-badge-bad { background-color: #fecaca; color: #7f1d1d; }
.ff-badge-info { background-color: #bae6fd; color: #0c4a6e; }
.ff-badge-neutral { background-color: #e5e7eb; color: #1f2937; }

/* Running header / footer */
.ff-run td { font-size: 7.5pt; color: #6b7280; border-bottom: 0.4pt solid #e5e7eb; padding-bottom: 1.5mm; }
.ff-foot td { font-size: 7pt; color: #9ca3af; border-top: 0.4pt solid #e5e7eb; padding-top: 1.5mm; vertical-align: top; }
.ff-foot-note { font-size: 8pt; color: #4b5563; text-align: center; margin-bottom: 1.5mm; }

/* Building blocks for bodies */
.ff-h { font-size: 7.5pt; font-weight: bold; color: {$accent}; text-transform: uppercase; letter-spacing: 0.6pt; margin: 5mm 0 1.8mm; }
/* Panels are TABLE CELLS: mPDF paints a div's background line by line inside a cell. */
table.ff-panels { width: 100%; }
td.ff-panel { background-color: #f8fafc; border: 0.5pt solid #e2e8f0; padding: 3mm 3.5mm; vertical-align: top; font-size: 8.5pt; }
td.ff-gap { width: 4mm; }
.ff-panel-label { font-size: 6.8pt; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5pt; margin-bottom: 1mm; }
table.ff-kv td { padding: 0.5mm 3mm 0.5mm 0; font-size: 8.5pt; vertical-align: top; }
table.ff-kv td.k { color: #6b7280; white-space: nowrap; }
table.ff-grid { width: 100%; }
table.ff-grid th { background-color: #f1f5f9; color: #475569; font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4pt; text-align: left; padding: 2mm 2.2mm; border-bottom: 0.8pt solid #cbd5e1; }
table.ff-grid td { padding: 2mm 2.2mm; border-bottom: 0.4pt solid #e5e7eb; vertical-align: top; font-size: 8.5pt; }
table.ff-grid th.num, table.ff-grid td.num { text-align: right; }
table.ff-grid tr.ff-sum td { font-weight: bold; border-top: 1pt solid #334155; border-bottom: none; background-color: #f8fafc; }
.ff-sub { font-size: 7.3pt; color: #6b7280; }
.ff-kicker { font-size: 6.6pt; color: {$accent}; text-transform: uppercase; letter-spacing: 0.4pt; font-weight: bold; }
table.ff-totals td { padding: 1.2mm 2.2mm; font-size: 9pt; }
table.ff-totals td.l { color: #4b5563; }
table.ff-totals td.v { text-align: right; white-space: nowrap; }
table.ff-totals tr.ff-total td { border-top: 1pt solid #334155; font-weight: bold; font-size: 10pt; padding-top: 2mm; }
table.ff-totals tr.ff-due td { background-color: {$accent}; color: #ffffff; font-weight: bold; font-size: 11pt; padding: 2.5mm 2.2mm; }
.ff-note { background-color: #f8fafc; border-left: 1.2mm solid {$accent}; padding: 2.5mm 3.5mm; margin-top: 5mm; font-size: 8.5pt; }

/* Accounting report tables (ReportPdfRenderer / YearEndService bodies) */
table.rpt { width: 100%; }
table.rpt th { background-color: #f1f5f9; color: #475569; font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3pt; text-align: left; padding: 1.8mm 2mm; border-bottom: 0.8pt solid #cbd5e1; }
table.rpt th.amt, table.rpt th.num { text-align: right; }
table.rpt td { padding: 1.3mm 2mm; border-bottom: 0.4pt solid #eef0f3; vertical-align: top; font-size: 8.5pt; }
table.rpt td.amt { text-align: right; white-space: nowrap; }
table.rpt td.acct { padding-left: 5mm; }
table.rpt td.indent { padding-left: 7mm; }
table.rpt tr.group td { background-color: #f1f5f9; color: #0f172a; font-weight: bold; padding-top: 2mm; padding-bottom: 2mm; border-bottom: 0.4pt solid #cbd5e1; }
table.rpt tr.total td { background-color: #fafbfc; }
table.rpt tr.subtotal td { border-top: 0.8pt solid #334155; background-color: #f8fafc; }
table.rpt tr.grand td { background-color: #eef2f7; border-top: 1.2pt solid #0f172a; border-bottom: 1.2pt solid #0f172a; padding-top: 2mm; padding-bottom: 2mm; }
.banner-red { background-color: #fee2e2; border-left: 1.2mm solid #dc2626; color: #991b1b; padding: 2.5mm 3.5mm; margin-bottom: 3mm; font-size: 8.5pt; }
.banner-amber { background-color: #fef3c7; border-left: 1.2mm solid #d97706; color: #92400e; padding: 2.5mm 3.5mm; margin-bottom: 3mm; font-size: 8.5pt; }
CSS;
    }
}
