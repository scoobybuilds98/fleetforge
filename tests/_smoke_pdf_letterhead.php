<?php
declare(strict_types=1);

/**
 * tests/_smoke_pdf_letterhead.php
 *
 * Stop-condition smoke for S-PDF-LETTERHEAD — every PDF the app produces is
 * reachable and carries the shared letterhead.
 *
 *   P1  PdfKit::addressLines(): company address that already carries the
 *       postal code is NOT followed by a duplicate city line; one that
 *       doesn't gets it
 *   P2  PdfKit::presentation(): real GD images — black canvas → band in that
 *       colour; white canvas → plain; transparent white ink → dark band;
 *       transparent dark ink → plain; photo-like corners → plain
 *   P3  money() (bcmath rounding, grouping, negatives), period(), filename()
 *   P4  render(): a PDF, with the logo embedded as an image; "Show logo on
 *       PDFs" OFF → no image, and brand() reads the pdf.* settings
 *   P5  InvoicePdfGenerator::generate(): an older-layout path is rebuilt into
 *       /v{LAYOUT}/, a good copy is reused, a copy missing from storage is
 *       rebuilt; pdfBytes() returns real PDF bytes
 *   P6  HTTP (signed-in admin): invoice PDF streams (inline + download),
 *       unknown id → 404; statement, P&L and balance sheet stream PDFs;
 *       dunning letter PDF streams, a missing file → 404 FILE_MISSING
 *   P7  credit application: stored file missing → rebuilt from the snapshot,
 *       re-stored under a _v{LAYOUT} key, documents row repaired
 *   P8  portal (signed-in customer): own invoice + own credit application
 *       stream; another customer's → 404; a non-advance draft → 404
 *   P9  source guards: no generator builds its own mPDF; the invoice page
 *       links the streaming endpoint (no pop-up after an await); the portal
 *       no longer links the non-existent invoices/download.php; the credit
 *       application pages no longer hand out presigned PDF URLs; Collections
 *       links each letter's PDF; the pdf.* settings are read by the kit and
 *       editable on Settings → Design
 *
 * Hermetic: every fixture row (zz-smoke customers, portal users, credit
 * application, documents row, dunning letter) and every stored file it
 * creates is deleted in `finally`; the one invoice P5 exercises gets its
 * pdf_path / pdf_generated_at / pdf_version restored; the pdf.show_logo
 * setting is restored.
 *
 * Needs the Herd dev site (fleetforge.test) for P6–P8.
 * Usage: php tests/_smoke_pdf_letterhead.php
 * @session S-PDF-LETTERHEAD
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Billing\InvoicePdfGenerator;
use FleetForge\Pdf\CreditApplicationPdf;
use FleetForge\Pdf\PdfKit;
use FleetForge\Storage\StorageClient;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  — $detail") . "\n";
}

/** GET with a session cookie; returns [code, content-type, content-disposition, body]. */
function get(string $url, string $sid): array
{
    $hdrs = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Cookie: ff_session=' . $sid],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $h) use (&$hdrs): int {
            $p = explode(':', $h, 2);
            if (count($p) === 2) {
                $hdrs[strtolower(trim($p[0]))] = trim($p[1]);
            }
            return strlen($h);
        },
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $hdrs['content-type'] ?? '', $hdrs['content-disposition'] ?? '', $body];
}

function isPdf(string $b): bool
{
    return str_starts_with($b, '%PDF-');
}

/** A small real PNG/JPEG → bytes. */
function img(int $w, int $h, callable $paint, bool $alpha = false): string
{
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
    }
    $paint($im);
    ob_start();
    imagepng($im);
    imagedestroy($im);
    return (string) ob_get_clean();
}

$base      = rtrim(APP_URL, '/') . '/' . ltrim(FF_BASE_PATH, '/');
$sessFiles = [];
$keys      = [];     // storage keys to delete
$custIds   = [];
$portalIds = [];
$letterIds = [];
$invRestore = null;
$logoSetting = settings_get('pdf.show_logo', '1');

// ── Admin session (the app's own auth_login — no password) ───────────────
// Minted before any output: auth_login() regenerates the session id, which
// PHP refuses once output (headers) has started.
$admin = db_row("SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id
                  WHERE r.slug = 'super_admin' AND u.deleted_at IS NULL AND u.status = 'active' ORDER BY u.id LIMIT 1");
$adminSid = '';
if ($admin) {
    auth_login($admin);
    $_SESSION['mfa_verified'] = true;
    $adminSid = session_id();
    session_write_close();
    $src = rtrim((string) session_save_path(), '/') ?: sys_get_temp_dir();
    @copy($src . '/sess_' . $adminSid, '/var/tmp/sess_' . $adminSid);
    $sessFiles[] = '/var/tmp/sess_' . $adminSid;
    $sessFiles[] = $src . '/sess_' . $adminSid;
}

try {
    check('P6 a super admin exists for the HTTP checks', $admin !== null);

    // ── P1 ────────────────────────────────────────────────────────────────
    $a = PdfKit::addressLines('9616 188 Street, Surrey, BC Canada V4N 3M2', 'Surrey', 'BC', 'V4N 3M2');
    check('P1 address already holding the postal code is not doubled', $a === ['9616 188 Street, Surrey, BC Canada V4N 3M2'], json_encode($a));
    $b = PdfKit::addressLines("Unit 4\n100 Main St", 'Delta', 'BC', 'V4K 1A1');
    check('P1 street-only address gets its city line', $b === ['Unit 4', '100 Main St', 'Delta, BC V4K 1A1'], json_encode($b));

    // ── P2 ────────────────────────────────────────────────────────────────
    $black = img(300, 90, static function ($im): void {
        imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
        imagefilledrectangle($im, 60, 30, 240, 60, imagecolorallocate($im, 255, 255, 255));
    });
    $p = PdfKit::presentation($black);
    check('P2 black canvas → band in the canvas colour', $p === ['mode' => 'band', 'band' => '#000000'], json_encode($p));
    $navy = img(300, 90, static function ($im): void {
        imagefill($im, 0, 0, imagecolorallocate($im, 16, 32, 64));
    });
    check('P2 navy canvas → navy band', PdfKit::presentation($navy)['band'] === '#102040');
    $white = img(300, 90, static function ($im): void {
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, 60, 30, 240, 60, imagecolorallocate($im, 20, 20, 90));
    });
    check('P2 white canvas → plain (white header)', PdfKit::presentation($white)['mode'] === 'plain');
    $clearLight = img(300, 90, static function ($im): void {
        imagefilledrectangle($im, 40, 20, 260, 70, imagecolorallocate($im, 250, 250, 250));
    }, true);
    check('P2 transparent + white ink → dark band (would vanish on paper)', PdfKit::presentation($clearLight)['mode'] === 'band');
    $clearDark = img(300, 90, static function ($im): void {
        imagefilledrectangle($im, 40, 20, 260, 70, imagecolorallocate($im, 30, 30, 30));
    }, true);
    check('P2 transparent + dark ink → plain', PdfKit::presentation($clearDark)['mode'] === 'plain');
    $photo = img(300, 90, static function ($im): void {
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, 150, 0, 299, 89, imagecolorallocate($im, 0, 0, 0));
    });
    check('P2 mismatched corners (photo) → plain', PdfKit::presentation($photo)['mode'] === 'plain');
    check('P2 unreadable bytes → plain, no crash', PdfKit::presentation('not an image')['mode'] === 'plain');

    // ── P3 ────────────────────────────────────────────────────────────────
    check('P3 money groups thousands', PdfKit::money('1234567.5') === '$1,234,567.50', PdfKit::money('1234567.5'));
    check('P3 money rounds half-up with bcmath', PdfKit::money('0.125') === '$0.13' && PdfKit::money('0.124') === '$0.12');
    check('P3 money negatives + currency', PdfKit::money('-42') === '-$42.00' && PdfKit::money('10', 'CAD') === '$10.00 CAD');
    check('P3 money rejects junk', PdfKit::money('abc') === '—' && PdfKit::money(null) === '—');
    check('P3 period same year', PdfKit::period('2026-01-01', '2026-08-31') === 'Jan 1 – Aug 31, 2026', PdfKit::period('2026-01-01', '2026-08-31'));
    check('P3 period across years', PdfKit::period('2025-12-01', '2026-01-31') === 'Dec 1, 2025 – Jan 31, 2026');
    check('P3 filename is safe', PdfKit::filename('INV/2026 02319') === 'INV_2026_02319.pdf' && PdfKit::filename('') === 'document.pdf');

    // ── P4 ────────────────────────────────────────────────────────────────
    PdfKit::resetBrand();
    $brand = PdfKit::brand();
    check('P4 brand reads the accent (pdf.accent_color → brand.primary_color)', (bool) preg_match('/^#[0-9a-f]{6}$/', $brand['accent']), $brand['accent']);
    check('P4 brand resolves a logo on this install', $brand['logo'] !== null && $brand['logo']['bytes'] !== '');
    $doc = PdfKit::render('<p>Body</p>', ['title' => 'Smoke', 'reference' => 'REF-1', 'meta' => ['Date' => 'Sep 1, 2026'], 'status' => ['label' => 'Sent', 'tone' => 'info']]);
    check('P4 render() returns a PDF', isPdf($doc));
    check('P4 the logo is embedded as an image', str_contains($doc, '/Subtype /Image'));
    check('P4 report variant renders', isPdf(PdfKit::render('<table class="rpt"><tr><td>x</td></tr></table>', ['title' => 'Report', 'variant' => 'report', 'orientation' => 'L'])));
    db_execute("UPDATE settings SET `value` = '0' WHERE `key` = 'pdf.show_logo'");
    PdfKit::resetBrand();
    $noLogo = PdfKit::render('<p>Body</p>', ['title' => 'Smoke']);
    check('P4 "Show logo on PDFs" off → no logo, wordmark instead', PdfKit::brand()['logo'] === null && !str_contains($noLogo, '/Subtype /Image') && isPdf($noLogo));
    db_execute("UPDATE settings SET `value` = ? WHERE `key` = 'pdf.show_logo'", [(string) $logoSetting]);
    PdfKit::resetBrand();

    // ── P5 ────────────────────────────────────────────────────────────────
    $inv = db_row("SELECT id, invoice_number, pdf_path, pdf_generated_at, pdf_version FROM invoices
                    WHERE deleted_at IS NULL AND status IN ('sent','overdue','partially_paid','paid')
                    ORDER BY id DESC LIMIT 1");
    check('P5 a sent invoice exists to exercise', $inv !== null);
    if ($inv) {
        $invRestore = $inv;
        $invId = (int) $inv['id'];
        db_execute("UPDATE invoices SET pdf_path = ? WHERE id = ?", ["generated/pdfs/invoices/{$invId}/{$inv['invoice_number']}.pdf", $invId]);
        $r1 = InvoicePdfGenerator::generate($invId);
        $keys[] = $r1['pdf_path'];
        check('P5 older-layout copy is rebuilt into /v' . PdfKit::LAYOUT . '/',
            $r1['regenerated'] && str_contains($r1['pdf_path'], '/v' . PdfKit::LAYOUT . '/') && StorageClient::exists($r1['pdf_path']), json_encode($r1));
        $r2 = InvoicePdfGenerator::generate($invId);
        check('P5 a good current copy is reused (sent invoice, D12)', !$r2['regenerated'] && $r2['pdf_path'] === $r1['pdf_path']);
        StorageClient::delete($r1['pdf_path']);
        $r3 = InvoicePdfGenerator::generate($invId);
        check('P5 a copy missing from storage is rebuilt', $r3['regenerated'] && StorageClient::exists($r3['pdf_path']));
        $bytes = InvoicePdfGenerator::pdfBytes($invId);
        check('P5 pdfBytes() returns the PDF + INV-… filename', isPdf($bytes['bytes']) && $bytes['filename'] === PdfKit::filename($inv['invoice_number']));
    }

    // ── P6 ────────────────────────────────────────────────────────────────
    if ($inv) {
        [$c, $ct, $cd, $body] = get($base . '/api/v1/invoices/pdf?id=' . (int) $inv['id'], $adminSid);
        check('P6 invoice PDF streams inline', $c === 200 && str_contains($ct, 'application/pdf') && isPdf($body) && str_starts_with($cd, 'inline'), "HTTP $c $ct");
        [$c, , $cd] = get($base . '/api/v1/invoices/pdf?id=' . (int) $inv['id'] . '&download=1', $adminSid);
        check('P6 download=1 forces a file save with the invoice number', $c === 200 && str_starts_with($cd, 'attachment') && str_contains($cd, $inv['invoice_number']), $cd);
    }
    [$c, $ct] = get($base . '/api/v1/invoices/pdf?id=999999999', $adminSid);
    check('P6 unknown invoice → 404 JSON', $c === 404 && str_contains($ct, 'json'), "HTTP $c");

    $stmtCust = db_row("SELECT customer_id FROM invoices WHERE deleted_at IS NULL AND status <> 'draft' AND status <> 'void' LIMIT 1");
    if ($stmtCust) {
        [$c, $ct, , $body] = get($base . '/api/v1/accounting/ar/statement?customer_id=' . (int) $stmtCust['customer_id'] . '&date_from=2026-01-01&date_to=2026-08-31', $adminSid);
        check('P6 customer statement streams a PDF', $c === 200 && isPdf($body), "HTTP $c $ct");
    }
    [$c, , , $body] = get($base . '/api/v1/accounting/reports/profit-loss.php?period_start=2026-01-01&period_end=2026-08-31&comparison=none&format=pdf', $adminSid);
    check('P6 P&L export streams a PDF', $c === 200 && isPdf($body), "HTTP $c");
    [$c, , , $body] = get($base . '/api/v1/accounting/reports/balance-sheet.php?as_of_date=2026-08-31&comparison=none&format=pdf', $adminSid);
    check('P6 balance sheet export streams a PDF', $c === 200 && isPdf($body), "HTTP $c");

    // Dunning letter: one with a stored file, one whose file is gone.
    $dunCust = db_insert('customers', ['company_name' => 'zz-smoke-pdf dunning ' . bin2hex(random_bytes(3)), 'status' => 'active']);
    $custIds[] = $dunCust;
    $tmp = tempnam(sys_get_temp_dir(), 'ffsm');
    file_put_contents($tmp, PdfKit::render('<p>Letter</p>', ['title' => 'Past due']));
    $dunKey = StorageClient::upload($tmp, 'dunning/' . $dunCust . '/zz_smoke_' . bin2hex(random_bytes(3)) . '.pdf');
    @unlink($tmp);
    $keys[] = $dunKey;
    $okLetter = db_insert('acc_dunning_letters', ['customer_id' => $dunCust, 'letter_type' => 'reminder_30', 'sent_date' => ff_today(),
        'sent_method' => 'mail', 'total_overdue' => '10.00', 'invoice_count' => 1, 'pdf_path' => $dunKey]);
    $goneLetter = db_insert('acc_dunning_letters', ['customer_id' => $dunCust, 'letter_type' => 'reminder_30', 'sent_date' => ff_today(),
        'sent_method' => 'mail', 'total_overdue' => '10.00', 'invoice_count' => 1, 'pdf_path' => 'dunning/' . $dunCust . '/gone.pdf']);
    $letterIds = [$okLetter, $goneLetter];
    [$c, , , $body] = get($base . '/api/v1/accounting/ar/dunning_letters/pdf.php?id=' . $okLetter, $adminSid);
    check('P6 dunning letter PDF streams', $c === 200 && isPdf($body), "HTTP $c");
    [$c, , , $body] = get($base . '/api/v1/accounting/ar/dunning_letters/pdf.php?id=' . $goneLetter, $adminSid);
    check('P6 dunning letter with a lost file → 404 FILE_MISSING (never re-rendered)', $c === 404 && str_contains($body, 'FILE_MISSING'), "HTTP $c");

    // ── P7 ────────────────────────────────────────────────────────────────
    $snapshot = (string) (db_row("SELECT rendered_html FROM customer_credit_applications WHERE rendered_html IS NOT NULL AND rendered_html <> '' ORDER BY id DESC LIMIT 1")['rendered_html'] ?? '');
    if ($snapshot === '') {
        require_once FF_ROOT . '/includes/partials/credit_application_render.php';
        $snapshot = cca_render_html(['app_id' => 1, 'customer_company' => 'Smoke', 'submitted_at' => ff_now_utc(), 'form_data' => ['company' => ['name' => 'Smoke']]]);
    }
    $ccaCust = db_insert('customers', ['company_name' => 'zz-smoke-pdf cca ' . bin2hex(random_bytes(3)), 'status' => 'active']);
    $custIds[] = $ccaCust;
    $docId = db_insert('documents', ['entity_type' => 'customer', 'entity_id' => $ccaCust, 'document_type' => 'credit_application',
        'title' => 'zz smoke', 'file_path' => 'credit_applications/zz-smoke/lost.pdf', 'file_name' => 'lost.pdf', 'mime_type' => 'application/pdf']);
    $appId = db_insert('customer_credit_applications', ['customer_id' => $ccaCust, 'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'status' => 'reviewed', 'rendered_html' => $snapshot,
        'submitted_at' => ff_now_utc(), 'generated_pdf_document_id' => $docId]);
    [$c, , , $body] = get($base . '/api/v1/credit_applications/pdf?id=' . $appId, $adminSid);
    check('P7 lost credit-application file → rebuilt from the snapshot and served', $c === 200 && isPdf($body), "HTTP $c");
    $newPath = (string) (db_row("SELECT file_path FROM documents WHERE id = ?", [$docId])['file_path'] ?? '');
    $keys[] = $newPath;
    check('P7 documents row repaired to a _v' . PdfKit::LAYOUT . ' copy that exists',
        str_ends_with($newPath, '_v' . PdfKit::LAYOUT . '.pdf') && StorageClient::exists($newPath), $newPath);
    $reread = CreditApplicationPdf::bytes($appId);
    check('P7 next open is a plain read of the stored copy', isPdf($reread['bytes']) && $newPath === (string) db_row("SELECT file_path FROM documents WHERE id = ?", [$docId])['file_path']);

    // ── P8 ────────────────────────────────────────────────────────────────
    $mkPortal = static function (int $customerId) use (&$portalIds, &$sessFiles): string {
        $pu = db_insert('portal_users', ['customer_id' => $customerId, 'name' => 'zz smoke', 'email' => 'zz-smoke-pdf-' . bin2hex(random_bytes(4)) . '@fleetforge.test',
            'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), 'status' => 'active', 'is_primary' => 1]);
        $portalIds[] = $pu;
        $sid = bin2hex(random_bytes(16));
        file_put_contents('/var/tmp/sess_' . $sid,
            'ff_portal_user|' . serialize(['id' => $pu, 'customer_id' => $customerId, 'name' => 'zz smoke', 'email' => 'x@fleetforge.test', 'is_primary' => true, 'company_name' => ''])
            . 'ff_portal_last_activity|' . serialize(time()) . 'csrf_token|' . serialize(bin2hex(random_bytes(16))));
        $sessFiles[] = '/var/tmp/sess_' . $sid;
        return $sid;
    };
    $ccaPortal = $mkPortal($ccaCust);
    [$c, , , $body] = get($base . '/api/v1/portal/credit_applications/pdf?id=' . $appId, $ccaPortal);
    check('P8 portal: own credit application streams', $c === 200 && isPdf($body), "HTTP $c");
    $foreignApp = db_row("SELECT id FROM customer_credit_applications WHERE customer_id <> ? AND status IN ('submitted','reviewed') AND deleted_at IS NULL LIMIT 1", [$ccaCust]);
    if ($foreignApp) {
        [$c] = get($base . '/api/v1/portal/credit_applications/pdf?id=' . (int) $foreignApp['id'], $ccaPortal);
        check("P8 portal: another customer's credit application → 404", $c === 404, "HTTP $c");
    }
    if ($inv) {
        [$c] = get($base . '/api/v1/portal/invoices/pdf?id=' . (int) $inv['id'], $ccaPortal);
        check("P8 portal: another customer's invoice → 404", $c === 404, "HTTP $c");
        $invCust = (int) db_row("SELECT customer_id FROM invoices WHERE id = ?", [(int) $inv['id']])['customer_id'];
        $invPortal = $mkPortal($invCust);
        [$c, , , $body] = get($base . '/api/v1/portal/invoices/pdf?id=' . (int) $inv['id'], $invPortal);
        check('P8 portal: own invoice streams (was a link to a file that never existed)', $c === 200 && isPdf($body), "HTTP $c");
        $draft = db_row("SELECT id FROM invoices WHERE customer_id = ? AND status = 'draft' AND COALESCE(generation_source,'') <> 'advance' AND deleted_at IS NULL LIMIT 1", [$invCust]);
        if ($draft) {
            [$c] = get($base . '/api/v1/portal/invoices/pdf?id=' . (int) $draft['id'], $invPortal);
            check('P8 portal: a non-advance draft stays hidden → 404', $c === 404, "HTTP $c");
        }
    }

    // ── P9 ────────────────────────────────────────────────────────────────
    $own = [];
    foreach (['app', 'api', 'lib', 'cron'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FF_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = substr($f->getPathname(), strlen(FF_ROOT) + 1);
            if (in_array($rel, ['lib/Pdf/PdfKit.php', 'api/v1/invoices/batch_download.php'], true)) continue;
            if (preg_match('/new\s+\\\\?(Mpdf\\\\)?Mpdf\s*\(/', (string) file_get_contents($f->getPathname()))) {
                $own[] = $rel;
            }
        }
    }
    check('P9 no generator builds its own mPDF (all go through PdfKit)', $own === [], implode(', ', $own));
    $show = (string) file_get_contents(FF_ROOT . '/app/admin/invoices/show.php');
    check('P9 invoice page links the streaming endpoint, no pop-up-after-await', str_contains($show, "base_url('api/v1/invoices/pdf')") && !str_contains($show, 'generatePdf('));
    $portalView = (string) file_get_contents(FF_ROOT . '/app/portal/invoices/view.php');
    check('P9 portal no longer links the non-existent invoices/download.php',
        !str_contains($portalView, "base_url('api/v1/invoices/download.php") && str_contains($portalView, "base_url('api/v1/portal/invoices/pdf')"));
    foreach (['app/admin/credit_applications/show.php', 'app/portal/credit-applications/view.php'] as $f) {
        check("P9 {$f} does not hand out a presigned PDF URL", !preg_match('/\$pdfUrl\s*=\s*StorageClient::url/', (string) file_get_contents(FF_ROOT . '/' . $f)));
    }
    check('P9 Collections links each dunning letter PDF', str_contains((string) file_get_contents(FF_ROOT . '/app/admin/accounting/collections/index.php'), 'dunning_letters/pdf.php'));
    $kit = (string) file_get_contents(FF_ROOT . '/lib/Pdf/PdfKit.php');
    check('P9 the PDF settings are read by the kit', str_contains($kit, "'pdf.show_logo'") && str_contains($kit, "'pdf.accent_color'") && str_contains($kit, "'pdf.invoice_footer_text'"));
    $design = (string) file_get_contents(FF_ROOT . '/app/admin/settings/design.php');
    check('P9 …and editable on Settings → Design (PDF documents card)', str_contains($design, "saveCard('pdf-docs'")
        && str_contains($design, 'name="pdf_show_logo"') && str_contains($design, 'name="pdf_accent_color"') && str_contains($design, 'name="pdf_invoice_footer_text"'));
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    db_execute("UPDATE settings SET `value` = ? WHERE `key` = 'pdf.show_logo'", [(string) $logoSetting]);
    if ($invRestore) {
        db_execute("UPDATE invoices SET pdf_path = ?, pdf_generated_at = ?, pdf_version = ? WHERE id = ?",
            [$invRestore['pdf_path'], $invRestore['pdf_generated_at'], $invRestore['pdf_version'], (int) $invRestore['id']]);
    }
    foreach ($letterIds as $id) db_execute('DELETE FROM acc_dunning_letters WHERE id = ?', [$id]);
    foreach ($portalIds as $id) db_execute('DELETE FROM portal_users WHERE id = ?', [$id]);
    foreach ($custIds as $id) {
        db_execute("DELETE FROM audit_log WHERE entity_type = 'credit_application' AND entity_id IN (SELECT id FROM customer_credit_applications WHERE customer_id = ?)", [$id]);
        db_execute('DELETE FROM customer_credit_applications WHERE customer_id = ?', [$id]);
        db_execute("DELETE FROM documents WHERE entity_type = 'customer' AND entity_id = ?", [$id]);
        db_execute('DELETE FROM customers WHERE id = ?', [$id]);
    }
    // The invoice's restored pdf_path may be one of the keys we wrote — keep that one.
    foreach (array_unique(array_filter($keys)) as $k) {
        if ($invRestore && $k === $invRestore['pdf_path']) continue;
        try { StorageClient::delete($k); } catch (\Throwable) {}
    }
    foreach ($sessFiles as $f) @unlink($f);
    $left = db_row("SELECT COUNT(*) AS n FROM customers WHERE company_name LIKE 'zz-smoke-pdf%'");
    check('cleanup: no zz-smoke-pdf customers left', (int) ($left['n'] ?? 1) === 0);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
