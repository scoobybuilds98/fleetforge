<?php
declare(strict_types=1);

/**
 * tests/_verify_email_templates_redesign.php
 *
 * One-shot verification script for S-EMAIL-TEMPLATES-REDESIGN.
 * NOT part of the D131 gate — this is a K-21-spirit check that
 * the redesigned templates render through EmailService and the
 * new renderEmailHtml() shell without errors.
 *
 * What this verifies:
 *   1. All 10 redesigned templates compile (slug-based load).
 *   2. Variable substitution still works ({customer_name}, etc.).
 *   3. renderEmailHtml() wraps the body in the new shell:
 *      - logo image is referenced when company.logo_url set
 *      - orange #F97316 accent bar present
 *      - footer contains company name + address + phone + email
 *      - contact links use tel: / mailto: / https://
 *   4. Output is well-formed and contains expected markers.
 *
 * Usage:
 *   php tests/_verify_email_templates_redesign.php
 *   php tests/_verify_email_templates_redesign.php --dump-html=invoice_ready
 *     (writes the rendered HTML to /tmp/ff_email_<slug>.html for browser inspection)
 *
 * Exit code: 0 = all checks pass, 1 = any failure.
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/lib/Email/EmailService.php';

use FleetForge\Email\EmailService;

$argvSafe = $argv ?? [];
$dumpSlug = null;
foreach ($argvSafe as $a) {
    if (preg_match('/^--dump-html=(.+)$/', $a, $m)) {
        $dumpSlug = $m[1];
    }
}

$pass = 0;
$fail = 0;
$checks = [];

function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $checks;
    if ($ok) {
        $pass++;
        $checks[] = "  PASS  $name" . ($detail !== '' ? "  — $detail" : '');
    } else {
        $fail++;
        $checks[] = "  FAIL  $name" . ($detail !== '' ? "  — $detail" : '');
    }
}

echo "FleetForge — S-EMAIL-TEMPLATES-REDESIGN verification\n";
echo str_repeat('═', 78) . "\n";

// ── 1. All 10 slugs exist and compile ──────────────────────────
$slugs = [
    'invoice_ready', 'payment_reminder_7', 'payment_reminder_3',
    'payment_overdue', 'payment_received', 'lease_activation',
    'lease_closing', 'compliance_expiring', 'general', 'statement',
];

$sampleVars = [
    'customer_name'   => 'Acme Trucking Ltd',
    'invoice_number'  => 'INV-2026-00042',
    'invoice_date'    => 'May 21, 2026',
    'due_date'        => 'June 20, 2026',
    'amount_due'      => '$3,250.00',
    'amount'          => '$3,250.00',
    'days_overdue'    => '14',
    'payment_amount'  => '$3,250.00',
    'payment_date'    => 'May 21, 2026',
    'contract_number' => 'CN-2026-0007',
    'unit_number'     => 'TRL-184',
    'lease_start'     => 'Jan 15, 2026',
    'lease_end'       => 'Dec 31, 2026',
    'document_type'   => 'Annual Inspection',
    'expiry_date'     => 'June 30, 2026',
    'subject'         => 'Regarding your account',
    'month'           => 'May',
    'year'            => '2026',
];

foreach ($slugs as $slug) {
    $tpl = db_row("SELECT * FROM email_templates WHERE slug = ?", [$slug]);
    check("template_exists[$slug]", $tpl !== null,
        $tpl ? "id={$tpl['id']} html_len=" . strlen($tpl['body_html']) : 'not found');

    if ($tpl) {
        $compiled = EmailService::compileTemplate((int)$tpl['id'], $sampleVars);
        check("compile[$slug]",
            $compiled['body_html'] !== '' && $compiled['subject'] !== '',
            "subject_len=" . strlen($compiled['subject']) . " body_len=" . strlen($compiled['body_html']));

        // Confirm no {placeholder} remains for known vars (the resolver should fill them)
        // We allow unknown {*} to remain (intentional design — see substitute())
        $bodyHtml = $compiled['body_html'];
        $remainingKnowns = [];
        foreach (array_keys($sampleVars) as $k) {
            if (str_contains($bodyHtml, '{' . $k . '}')) {
                $remainingKnowns[] = $k;
            }
        }
        check("substituted[$slug]", count($remainingKnowns) === 0,
            $remainingKnowns ? 'leftover: ' . implode(',', $remainingKnowns) : 'all known vars substituted');
    }
}

// ── 2. renderEmailHtml shell injection ─────────────────────────
$sample = EmailService::compileTemplate(
    (int)db_row("SELECT id FROM email_templates WHERE slug='invoice_ready'")['id'],
    $sampleVars
);
$wrapped = EmailService::renderEmailHtml($sample['body_html']);

check('shell_doctype',
    str_starts_with($wrapped, '<!DOCTYPE html>'),
    'doctype prefix');
check('shell_orange_bar',
    str_contains($wrapped, 'background-color:#F97316;height:4px'),
    '4px orange accent bar present');
check('shell_company_name',
    str_contains($wrapped, 'Mainland Truck &amp; Trailer Sales &amp; Leasing'),
    'company name appears in shell (escaped)');
check('shell_address',
    str_contains($wrapped, '9616 188 Street, Surrey, BC Canada V4N 3M2'),
    'address present in footer');
check('shell_phone_link',
    str_contains($wrapped, 'href="tel:+18668886887"'),
    'tel: link constructed with stripped digits');
check('shell_email_link',
    str_contains($wrapped, 'href="mailto:info@mainlandtts.ca"'),
    'mailto: link constructed');
check('shell_website_link',
    str_contains($wrapped, 'href="https://mainlandrentals.com"'),
    'website link with https:// prefix added');
check('shell_logo_img',
    str_contains($wrapped, '<img src="https://mainlandrentals.com/assets/img/logo-email.png"'),
    'logo img tag with public URL (no /fleetforge prefix — asset_url() convention per includes/functions.php:54)');
check('shell_logo_alt',
    str_contains($wrapped, 'alt="Mainland Truck &amp; Trailer Sales &amp; Leasing"'),
    'logo alt text uses company name');
check('shell_copyright',
    str_contains($wrapped, '&copy; ' . date('Y') . ' Mainland Truck &amp; Trailer Sales &amp; Leasing'),
    'copyright line includes current year + company');
check('shell_closes_html',
    str_ends_with(trim($wrapped), '</body></html>'),
    'document closes cleanly');

// Body content should be inside the shell, not at the start/end
check('body_inside_shell',
    !str_starts_with($wrapped, '<h1') && str_contains($wrapped, '<h1 style='),
    'H1 from body appears inside shell, not at top of document');

// ── 3. Variable list still includes company-wide defaults ───────
$varsList = $sample['variables'];
$expectedDefaults = ['company_name', 'company_phone', 'company_email', 'sender_name', 'month', 'year'];
$missingDefaults = array_values(array_diff($expectedDefaults, $varsList));
check('variables_list_has_defaults',
    count($missingDefaults) === 0,
    $missingDefaults ? 'missing: ' . implode(',', $missingDefaults) : 'all defaults present');

// ── 4. Dump HTML if requested ──────────────────────────────────
if ($dumpSlug !== null) {
    $tpl = db_row("SELECT * FROM email_templates WHERE slug=?", [$dumpSlug]);
    if ($tpl) {
        $compiled = EmailService::compileTemplate((int)$tpl['id'], $sampleVars);
        $wrappedDump = EmailService::renderEmailHtml($compiled['body_html']);
        $path = '/tmp/ff_email_' . $dumpSlug . '.html';
        file_put_contents($path, $wrappedDump);
        echo "\nDumped: $path (open in browser to inspect)\n";
    } else {
        echo "\nFAIL: --dump-html=$dumpSlug not found\n";
    }
}

// ── Print results ──────────────────────────────────────────────
echo implode("\n", $checks) . "\n";
echo str_repeat('═', 78) . "\n";
echo ($fail === 0 ? 'VERIFY OK' : 'VERIFY FAIL') . " — $pass passed, $fail failed\n";

exit($fail === 0 ? 0 : 1);
