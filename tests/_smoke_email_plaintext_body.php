<?php
declare(strict_types=1);

/**
 * tests/_smoke_email_plaintext_body.php
 *
 * Guards bug #20b — a plain-text message typed into Compose / Bulk Email lost
 * every line break: the textarea value was dropped raw into the HTML email
 * shell, where "\n" collapses to a space, so customers got one run-on
 * paragraph (and the modal preview showed the same).
 *
 *   P1  isPlainTextBody(): typed text (incl. a bare "<" like "qty < 5") is
 *       plain; anything with a real tag (<p>, <br/>, <a href>) is HTML.
 *   P2  bodyToHtml() on plain text escapes (<, &, quotes) THEN turns each
 *       line break (LF and CRLF) into <br> — escaping first means a typed
 *       "<b>" cannot inject markup once it is no longer a real tag match.
 *   P3  bodyToHtml() leaves HTML (every seeded template) byte-identical.
 *   P4  EmailService::send() routes the body through bodyToHtml() BEFORE
 *       renderEmailHtml() (structural — send() is never executed here, so
 *       no email and no email_logs row is produced).
 *   P5  The client preview helper FF_emailBodyToHtml (public/assets/js/app.js)
 *       uses the SAME tag-detection regex as the server, so preview == sent.
 *   P6  Every active seeded template body is classified as HTML (so the fix
 *       cannot alter template emails).
 *
 * Hermetic: read-only (one SELECT on email_templates); no writes, no mail.
 *
 * Run: php tests/_smoke_email_plaintext_body.php
 *
 * @session bug #20b (email compose line breaks)
 */

$root = dirname(__DIR__);
require_once $root . '/config/app.php';

use FleetForge\Email\EmailService;

$pass = 0;
$fail = 0;

/**
 * Record one assertion result.
 *
 * @param string $name Human-readable check name
 * @param bool   $ok   Whether the check passed
 * @param string $info Extra detail printed on failure
 * @return void
 */
function check(string $name, bool $ok, string $info = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}" . ($info !== '' ? "  -> {$info}" : '') . "\n"; }
}

echo "bug #20b — plain-text email bodies keep line breaks\n\n";

// ── P1 ──────────────────────────────────────────────────────────────
check('P1 plain text detected',            EmailService::isPlainTextBody("Hi Bob,\nThanks"));
check('P1 bare "<" is still plain',        EmailService::isPlainTextBody("qty < 5 and x<3\nok"));
check('P1 <p> is HTML',                    !EmailService::isPlainTextBody("<p>Hello</p>"));
check('P1 <br/> is HTML',                  !EmailService::isPlainTextBody("a<br/>b"));
check('P1 <a href> is HTML',               !EmailService::isPlainTextBody('see <a href="https://x.test">x</a>'));

// ── P2 ──────────────────────────────────────────────────────────────
$out = EmailService::bodyToHtml("Hello Bob,\r\nqty < 5 & \"ok\"\n\nThanks");
check('P2 CRLF/LF -> <br>, escaped first',
    $out === 'Hello Bob,<br>' . "\n" . 'qty &lt; 5 &amp; &quot;ok&quot;<br>' . "\n" . '<br>' . "\n" . 'Thanks',
    var_export($out, true));
check('P2 renders no raw newline-only paragraph collapse (contains 3 <br>)', substr_count($out, '<br>') === 3, $out);

// ── P3 ──────────────────────────────────────────────────────────────
$html = "<p style=\"margin:0\">Dear {customer_name},</p>\n\n<p>Line</p>";
check('P3 HTML body untouched', EmailService::bodyToHtml($html) === $html);

// ── P4 ──────────────────────────────────────────────────────────────
$svc = (string) file_get_contents($root . '/lib/Email/EmailService.php');
$posConvert = strpos($svc, '$bodyHtml = self::bodyToHtml($bodyHtml);');
$posWrap    = strpos($svc, '$wrappedHtml = self::renderEmailHtml($bodyHtml);');
check('P4 send() converts body before wrapping it',
    $posConvert !== false && $posWrap !== false && $posConvert < $posWrap);

// ── P5 ──────────────────────────────────────────────────────────────
$js = (string) file_get_contents($root . '/public/assets/js/app.js');
check('P5 client helper uses the server regex',
    str_contains($js, 'window.FF_emailBodyToHtml = function')
    && str_contains($js, '/<\/?[a-z][a-z0-9]*(?:\s[^<>]*)?\/?>/i.test(s)')
    && str_contains($svc, "'/<\\/?[a-z][a-z0-9]*(?:\\s[^<>]*)?\\/?>/i'"));

// ── P6 ──────────────────────────────────────────────────────────────
$rows = db_select("SELECT slug, body_html FROM email_templates WHERE deleted_at IS NULL AND is_active = 1");
$plainTemplates = [];
foreach ($rows as $r) {
    if (EmailService::isPlainTextBody((string) $r['body_html'])) $plainTemplates[] = $r['slug'];
}
check('P6 every active template body is HTML (' . count($rows) . ' checked)',
    $plainTemplates === [], implode(',', $plainTemplates));

echo "\nEMAIL-PLAINTEXT-BODY " . ($fail === 0 ? 'OK' : 'FAIL') . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
