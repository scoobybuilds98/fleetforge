<?php
declare(strict_types=1);

/**
 * tests/_smoke_mailer_attachments.php
 *
 * S-INVOICE-AUDIT-FIX-2 / finding I14 (CLAUDE_2026-06-20_invoices) —
 * guards that email ATTACHMENTS are actually delivered, not just logged.
 *
 * Before this fix, Mailer::send() used the SES `sendEmail` API, which has
 * NO attachment support: invoice/dunning PDFs were persisted to
 * email_attachments and the history tab claimed "attached", but the
 * customer never received the file. The fix routes attachment-bearing
 * mail through SES `sendRawEmail` with a hand-built multipart/mixed MIME
 * message, and threads EmailService's resolved bytes through to it.
 *
 * Live SES delivery cannot be exercised here (prod SES is sandbox/
 * read-only for the agent) — the operator does the final real-send check.
 * These checks prove the MIME is BUILT CORRECTLY so that real send works:
 *
 *   T1  StorageClient::read() byte round-trip + null-on-missing.
 *   T2  buildRawMimeMessage() structure: multipart/mixed wrapping a
 *       multipart/alternative (text+html) plus a base64 application/pdf
 *       part with Content-Disposition: attachment.
 *   T3  Attachment base64 round-trips to the EXACT original bytes
 *       (incl. bytes that look like a MIME boundary — base64 protects them).
 *   T4  Header-injection guard: CR/LF in subject / from-name / filename
 *       cannot inject new headers (no smuggled Bcc:).
 *   T5  Boundaries are unique per-build and mixed≠alt within a message.
 *   T6  RFC 2047 / 2231 encoding for non-ASCII subject + filename.
 *   T7  EmailService::send() threads a real stored file through end-to-end
 *       (dev log-mode): success, and mail.log reflects the attachment.
 *   T8  EmailService::send() is fail-closed: an unreadable attachment key
 *       fails the send (no misleading "attached" email is delivered).
 *
 * @session S-INVOICE-AUDIT-FIX-2 (I14)
 */

$root = dirname(__DIR__);
require_once $root . '/config/app.php';

use FleetForge\Notifications\Mailer;
use FleetForge\Email\EmailService;
use FleetForge\Storage\StorageClient;

$failures = [];
$pass     = 0;

function ck(string $id, bool $cond, string $msg, array &$failures, int &$pass): void
{
    if ($cond) { echo "PASS {$id} {$msg}\n"; $pass++; }
    else       { echo "FAIL {$id} {$msg}\n"; $failures[] = $id; }
}

// Reflection handle to the private MIME builder — lets us validate the
// produced message without a live SES connection.
$build = new ReflectionMethod(Mailer::class, 'buildRawMimeMessage');
$build->setAccessible(true);
$buildMime = static function (
    string $toEmail, string $toName, string $subject, string $html, string $text,
    string $fromEmail, string $fromName, array $replyTo, array $attachments
) use ($build): string {
    return (string) $build->invoke(
        null, $toEmail, $toName, $subject, $html, $text, $fromEmail, $fromName, $replyTo, $attachments
    );
};

// ── T1: StorageClient::read round-trip ──────────────────────────────
$key  = 'test_smoke/mailer_attach_' . getmypid() . '.bin';
$dest = $root . '/storage/' . $key;
@mkdir(dirname($dest), 0755, true);
$payload = "%PDF-1.4\n" . random_bytes(2048) . "\n--not-a-real-boundary--\n%%EOF";
file_put_contents($dest, $payload);

$readBack = StorageClient::read($key);
ck('T1a', $readBack === $payload, 'StorageClient::read returns exact stored bytes', $failures, $pass);
ck('T1b', StorageClient::read('test_smoke/does_not_exist_' . getmypid() . '.bin') === null,
   'StorageClient::read returns null for a missing key', $failures, $pass);

// ── Build a representative attachment message ───────────────────────
$pdfBytes = $payload; // reuse the binary payload as the "PDF"
$raw = $buildMime(
    'customer@example.com', 'Acme Trucking',
    'Your Invoice INV-2026-00042',
    '<p>Please find your invoice attached.</p>',
    'Please find your invoice attached.',
    'billing@fleetforge.test', 'FleetForge Billing',
    [['email' => 'ar@fleetforge.test', 'name' => 'AR Desk']],
    [['content' => $pdfBytes, 'name' => 'INV-2026-00042.pdf', 'type' => 'application/pdf']]
);

// Split headers / body at the first blank line.
$split    = explode("\r\n\r\n", $raw, 2);
$headerBlk = $split[0];

// ── T2: structural assertions ───────────────────────────────────────
ck('T2a', (bool) preg_match('/^Content-Type: multipart\/mixed; boundary="([^"]+)"/m', $headerBlk),
   'top-level Content-Type is multipart/mixed with a boundary', $failures, $pass);
ck('T2b', str_contains($raw, 'Content-Type: multipart/alternative; boundary="'),
   'body wraps a multipart/alternative part', $failures, $pass);
ck('T2c', str_contains($raw, 'Content-Type: text/plain; charset=UTF-8') &&
          str_contains($raw, 'Content-Type: text/html; charset=UTF-8'),
   'alternative part carries both text/plain and text/html', $failures, $pass);
ck('T2d', str_contains($raw, 'Content-Type: application/pdf; name="INV-2026-00042.pdf"'),
   'attachment part declares application/pdf with a name', $failures, $pass);
ck('T2e', str_contains($raw, 'Content-Disposition: attachment; filename="INV-2026-00042.pdf"'),
   'attachment part has Content-Disposition: attachment; filename', $failures, $pass);
ck('T2f', str_contains($raw, 'From: "FleetForge Billing" <billing@fleetforge.test>') &&
          str_contains($raw, 'To: "Acme Trucking" <customer@example.com>') &&
          str_contains($raw, 'Reply-To: "AR Desk" <ar@fleetforge.test>'),
   'From / To / Reply-To headers are well-formed', $failures, $pass);
ck('T2g', str_contains($raw, 'MIME-Version: 1.0'), 'MIME-Version header present', $failures, $pass);

// ── T3: attachment base64 round-trips to EXACT bytes ────────────────
preg_match('/^Content-Type: multipart\/mixed; boundary="([^"]+)"/m', $headerBlk, $m);
$mixed = $m[1] ?? '';
$ok3 = false;
if ($mixed !== '') {
    // Grab the base64 block following the attachment's disposition header,
    // up to the next mixed boundary marker.
    $re = '/Content-Disposition: attachment; filename="INV-2026-00042\.pdf"\r\n\r\n(.*?)\r\n--' . preg_quote($mixed, '/') . '/s';
    if (preg_match($re, $raw, $am)) {
        $decoded = base64_decode(preg_replace('/\s+/', '', $am[1]) ?? '', true);
        $ok3 = ($decoded === $pdfBytes);
    }
}
ck('T3', $ok3, 'attachment base64 decodes back to the exact original bytes', $failures, $pass);

// Bodies are base64 with no line over the 998-char SMTP limit.
$longest = 0;
foreach (explode("\r\n", $raw) as $line) { $longest = max($longest, strlen($line)); }
ck('T3b', $longest <= 998, 'no MIME line exceeds the 998-char SMTP limit (longest=' . $longest . ')', $failures, $pass);

// ── T4: header-injection guard (CR/LF cannot smuggle headers) ───────
$evil = $buildMime(
    'customer@example.com', "Evil\r\nBcc: attacker@evil.test",
    "Invoice\r\nBcc: attacker@evil.test",
    '<p>x</p>', 'x',
    'billing@fleetforge.test', 'FleetForge',
    [],
    [['content' => 'data', 'name' => "evil\r\nContent-Type: text/x-injected\r\nname.pdf", 'type' => 'application/pdf']]
);
$evilHeaderBlk = explode("\r\n\r\n", $evil, 2)[0];
// No header line may BE a Bcc — the injected CRLF must have been stripped.
$hasBccHeader = (bool) preg_match('/^Bcc:/mi', $evil);
$singleSubject = substr_count($evilHeaderBlk, "\r\nSubject:") + (str_starts_with($evilHeaderBlk, 'Subject:') ? 1 : 0);
ck('T4a', !$hasBccHeader, 'CR/LF in subject/from-name does not inject a Bcc header', $failures, $pass);
ck('T4b', !str_contains($evil, 'Content-Type: text/x-injected'),
   'CR/LF in a filename cannot inject a forged MIME header', $failures, $pass);
ck('T4c', $singleSubject === 1, 'exactly one Subject header is emitted', $failures, $pass);

// ── T5: boundary uniqueness ─────────────────────────────────────────
$raw2 = $buildMime('c@example.com', 'C', 'S', '<p>h</p>', 't', 'f@fleetforge.test', 'F', [],
                   [['content' => 'd', 'name' => 'a.pdf', 'type' => 'application/pdf']]);
preg_match('/multipart\/mixed; boundary="([^"]+)"/', $raw,  $b1);
preg_match('/multipart\/mixed; boundary="([^"]+)"/', $raw2, $b2);
preg_match('/multipart\/alternative; boundary="([^"]+)"/', $raw, $a1);
ck('T5a', !empty($b1[1]) && !empty($b2[1]) && $b1[1] !== $b2[1],
   'mixed boundary differs across two builds (random)', $failures, $pass);
ck('T5b', !empty($a1[1]) && $a1[1] !== $b1[1],
   'mixed and alternative boundaries differ within a message', $failures, $pass);

// ── T6: non-ASCII subject + filename encoding ───────────────────────
$uni = $buildMime('c@example.com', 'Café Ltée', 'Facture éché — €1 234',
                  '<p>é</p>', 'e', 'f@fleetforge.test', 'Sociéte', [],
                  [['content' => 'd', 'name' => 'Facture-éché.pdf', 'type' => 'application/pdf']]);
$uniHeaderBlk = explode("\r\n\r\n", $uni, 2)[0];
// RFC 2047 keeps leading ASCII words plain and only encodes the non-ASCII
// run, so the encoded-word may appear mid-line — assert the Subject header
// CONTAINS a UTF-8 base64 encoded-word, not that it starts with one.
ck('T6a', (bool) preg_match('/^Subject:.*=\?UTF-8\?B\?/mi', $uniHeaderBlk),
   'non-ASCII subject carries an RFC 2047 base64 encoded-word', $failures, $pass);
ck('T6b', str_contains($uni, "filename*=UTF-8''"),
   'non-ASCII filename emits an RFC 2231 filename* parameter', $failures, $pass);

// ── T7 + T8: EmailService end-to-end threading (dev log-mode) ───────
// Wrap DB writes in a transaction we roll back so no test rows persist.
$pdo = db_pdo();
$inTx = false;
try {
    $pdo->beginTransaction();
    $inTx = true;

    $logFile = $root . '/logs/mail.log';
    $before  = is_file($logFile) ? (int) filesize($logFile) : 0;

    $res = EmailService::send([
        'to_email'   => 'smoke_i14@fleetforge.test',
        'to_name'    => 'I14 Smoke',
        'subject'    => '[SMOKE] I14 attachment threading',
        'body_html'  => '<p>attachment threading smoke</p>',
        'sent_by'    => 1,
        'attachments'=> [[
            'path'        => $key,                 // the file written in T1
            'name'        => 'INV-2026-00042.pdf',
            'type'        => 'application/pdf',
            'source_type' => 'invoice_pdf',
        ]],
    ]);

    $after = is_file($logFile) ? (int) filesize($logFile) : 0;
    $appended = ($after > $before && is_file($logFile))
        ? (string) file_get_contents($logFile, false, null, $before, $after - $before)
        : '';

    ck('T7a', ($res['success'] ?? false) === true,
       'EmailService::send succeeds with a readable stored attachment', $failures, $pass);
    ck('T7b', str_contains($appended, 'ATTACHMENTS:') && str_contains($appended, 'INV-2026-00042.pdf'),
       'mail.log records the attachment name + size (bytes were resolved)', $failures, $pass);

    // T8: unreadable attachment → fail-closed
    $res2 = EmailService::send([
        'to_email'   => 'smoke_i14@fleetforge.test',
        'to_name'    => 'I14 Smoke',
        'subject'    => '[SMOKE] I14 missing attachment',
        'body_html'  => '<p>missing</p>',
        'sent_by'    => 1,
        'attachments'=> [[
            'path' => 'test_smoke/definitely_missing_' . getmypid() . '.pdf',
            'name' => 'ghost.pdf',
            'type' => 'application/pdf',
        ]],
    ]);
    ck('T8a', ($res2['success'] ?? true) === false,
       'EmailService::send fails when an attachment cannot be read', $failures, $pass);
    ck('T8b', str_contains((string) ($res2['error'] ?? ''), 'could not be read'),
       'fail-closed error message names the unreadable attachment', $failures, $pass);
} finally {
    if ($inTx && $pdo->inTransaction()) { $pdo->rollBack(); }
}

// ── Cleanup ─────────────────────────────────────────────────────────
@unlink($dest);
@rmdir(dirname($dest));

echo "\n" . ($failures ? 'FAILURES: ' . implode(', ', $failures) : "ALL PASS ({$pass} checks)") . "\n";
exit($failures ? 1 : 0);
