<?php
declare(strict_types=1);

/**
 * tests/_smoke_cca_prefill.php
 *
 * Stop-condition smoke for S-CCA-PREFILL — the public credit-application
 * form opens pre-filled instead of blank.
 *
 * Drives the REAL public page over HTTP (dev: fleetforge.test) with real
 * tokens against the real schema, so the SQL in the helper, the form's
 * $old plumbing and the escaping are all exercised together.
 *
 *   P1  cca_prefill_from_form_data(): nested snapshot → flat input names;
 *       blanks, bare "+1" phone defaults and non Yes/No radio values dropped
 *   P2  cca_prefill_from_customer(): contact fields, province←state fallback,
 *       email←billing_email fallback, billing block → 'No' + one line,
 *       blank billing → 'Yes'; credit limit never mapped
 *   P3  first send (GET): customer record pre-fills, HTML-escaped, banner
 *       says "on file", signature / printed name / terms NOT pre-filled
 *   P4  needs-info re-send (GET): previous answers win, customer record
 *       fills the gaps, "last application" banner + uploads note
 *   P5  POST with errors re-fills what the applicant TYPED — a field they
 *       cleared stays cleared (pre-fill is GET-only); no banner
 *   P6  another customer's submission never leaks into this form
 *   P7  a soft-deleted previous application is ignored
 *   P8  an expired link shows no customer data at all
 *   P9  customer_id 0 (admin preview) pre-fills nothing
 *
 * Hermetic: every row is created here under a zz-smoke customer and hard-
 * deleted in `finally` (the application rows go with the customer's FK
 * CASCADE); the one rate-limit bucket P5 touches is removed too.
 *
 * Usage: php tests/_smoke_cca_prefill.php   (needs the Herd dev site up)
 * @session S-CCA-PREFILL
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/partials/credit_application_prefill.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  — $detail") . "\n";
}

$cookieJar = tempnam(sys_get_temp_dir(), 'ffcca');

/** GET/POST the public form; returns [http code, body]. Shares one cookie jar (CSRF lives in the session). */
function http(string $url, ?array $post = null): array
{
    global $cookieJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post); // array → multipart, like the real form
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

/** value="" of the text input with this id, or null when the input is missing. */
function inputValue(string $html, string $id): ?string
{
    if (!preg_match('/<input id="' . preg_quote($id, '/') . '"[^>]*?value="([^"]*)"/s', $html, $m)) {
        return null;
    }
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

/** True when the radio name=value carries the server-side `checked`. */
function radioChecked(string $html, string $name, string $value): bool
{
    return (bool) preg_match(
        '/<input type="radio" name="' . preg_quote($name, '/') . '" value="' . preg_quote($value, '/') . '"[^>]*\bchecked\b/s',
        $html
    );
}

/** Create a 'sent' application with a fresh raw token; returns [id, raw token]. */
function newApp(int $customerId, string $expiresAt = '+30 days'): array
{
    $raw = bin2hex(random_bytes(32));
    $id  = db_insert('customer_credit_applications', [
        'customer_id'      => $customerId,
        'token_hash'       => hash('sha256', $raw),
        'token_expires_at' => gmdate('Y-m-d H:i:s', strtotime($expiresAt)),
        'status'           => 'sent',
        'sent_at'          => ff_now_utc(),
    ]);
    return [$id, $raw];
}

$formUrl  = base_url('credit-application');
$tag      = 'zz-smoke-prefill-' . bin2hex(random_bytes(3));
$custA    = 0;
$custB    = 0;
$rlBucket = null;

try {
    // ── P1: snapshot → input names (pure) ────────────────────────────────
    $p1 = cca_prefill_from_form_data([
        'company'    => ['name' => ' Acme Haul ', 'phone' => '+1', 'same_as_physical' => 'maybe', 'gst_number' => ''],
        'principals' => [['name' => 'Pat', 'title' => 'Owner'], ['name' => '', 'title' => '']],
        'insurance'  => ['has_trailer_insurance' => 'Yes', 'company' => 'InsureCo'],
        'credit'     => ['has_purchase_order' => 'No'],
        'references' => [[], ['company' => 'Ref Two', 'email' => 'r2@example.test']],
    ]);
    check('P1 nested company.name → company_name (trimmed)', ($p1['company_name'] ?? null) === 'Acme Haul', json_encode($p1));
    check('P1 principals[0] → principal1_name/title', ($p1['principal1_name'] ?? '') === 'Pat' && ($p1['principal1_title'] ?? '') === 'Owner');
    check('P1 references[1] → ref2_company/ref2_email', ($p1['ref2_company'] ?? '') === 'Ref Two' && ($p1['ref2_email'] ?? '') === 'r2@example.test');
    check('P1 radios keep exact Yes/No', ($p1['has_trailer_insurance'] ?? '') === 'Yes' && ($p1['has_purchase_order'] ?? '') === 'No');
    check('P1 non Yes/No radio dropped', !array_key_exists('same_as_physical', $p1));
    check('P1 blanks + bare "+1" phone dropped', !array_key_exists('company_phone', $p1)
        && !array_key_exists('gst_number', $p1) && !array_key_exists('principal2_name', $p1));

    // ── P2: customer row → input names (pure) ────────────────────────────
    $p2a = cca_prefill_from_customer([
        'company_name' => 'Row Co', 'email' => '', 'billing_email' => 'ap@row.test', 'phone' => '604',
        'address' => '1 Main', 'city' => 'Delta', 'province' => '', 'state' => 'BC', 'postal_code' => 'V4K',
        'billing_address' => "PO Box 9\r\nDelta BC", 'gst_number' => 'G1', 'pst_number' => '', 'credit_limit' => '5000.00',
    ]);
    check('P2 email falls back to billing_email', ($p2a['company_email'] ?? '') === 'ap@row.test');
    check('P2 province falls back to state', ($p2a['physical_province'] ?? '') === 'BC');
    check('P2 billing block → No + joined on one line', ($p2a['same_as_physical'] ?? '') === 'No'
        && ($p2a['billing_address'] ?? '') === 'PO Box 9, Delta BC', json_encode($p2a));
    check('P2 credit limit is never mapped to credit_requested', !array_key_exists('credit_requested', $p2a));
    $p2b = cca_prefill_from_customer(['company_name' => 'X', 'address' => '1 Main', 'billing_address' => '']);
    check('P2 blank billing + main address → same_as_physical Yes', ($p2b['same_as_physical'] ?? '') === 'Yes');
    $p2c = cca_prefill_from_customer(['company_name' => 'X', 'address' => '', 'billing_address' => '']);
    check('P2 no main address → billing question left unanswered', !array_key_exists('same_as_physical', $p2c));

    // ── Fixtures: customer A (the applicant) + customer B (the leak canary)
    $custA = db_insert('customers', [
        'company_name'    => $tag . ' "Prefill" & <Co>',
        'email'           => $tag . '@example.test',
        'phone'           => '+1 604-555-0100',
        'address'         => '100 Smoke St',
        'city'            => 'Surrey',
        'province'        => 'BC',
        'postal_code'     => 'V4N 3M2',
        'billing_address' => '',
        'gst_number'      => '123456789RT0001',
        'pst_number'      => 'PST-1234-5678',
    ]);
    $custB = db_insert('customers', ['company_name' => $tag . ' other', 'email' => $tag . '-b@example.test']);
    [$appB] = newApp($custB);
    db_update('customer_credit_applications', [
        'status'       => 'submitted',
        'submitted_at' => ff_now_utc(),
        'form_data'    => json_encode(['company' => ['name' => 'LEAK-MARKER-' . $tag, 'wcb_number' => 'LEAK-WCB']]),
    ], 'id = ?', [$appB]);

    // ── P3: first send — pre-filled from the customer record ─────────────
    [$app1, $tok1] = newApp($custA);
    [$c3, $h3] = http($formUrl . '?token=' . $tok1);
    check('P3 form renders (200)', $c3 === 200 && str_contains($h3, 'id="cca-form"'), "HTTP $c3");
    check('P3 company name pre-filled from the customer', inputValue($h3, 'company_name') === $tag . ' "Prefill" & <Co>',
        var_export(inputValue($h3, 'company_name'), true));
    check('P3 value is HTML-escaped (no raw <Co> in the attribute)', str_contains($h3, '&quot;Prefill&quot; &amp; &lt;Co&gt;')
        && !str_contains($h3, '"Prefill" & <Co>'));
    check('P3 email / phone / address / postal pre-filled',
        inputValue($h3, 'company_email') === $tag . '@example.test'
        && inputValue($h3, 'company_phone') === '+1 604-555-0100'
        && inputValue($h3, 'physical_address') === '100 Smoke St'
        && inputValue($h3, 'physical_postal') === 'V4N 3M2');
    check('P3 GST / PST pre-filled', inputValue($h3, 'gst_number') === '123456789RT0001' && inputValue($h3, 'pst_number') === 'PST-1234-5678');
    check('P3 blank billing → "same as physical: Yes" checked + Alpine state agrees',
        radioChecked($h3, 'same_as_physical', 'Yes') && str_contains($h3, "sameAsPhysical: 'Yes'"));
    check('P3 banner says "on file"', str_contains($h3, 'id="cca-prefill-note"') && str_contains($h3, 'already have on file'));
    check('P3 printed name NOT pre-filled', inputValue($h3, 'print_name_first') === '' && inputValue($h3, 'print_name_last') === '');
    check('P3 signed date is today, not carried over', inputValue($h3, 'signed_date') === ff_today());
    check('P3 terms box NOT pre-ticked', !preg_match('/id="terms_accepted"[^>]*\bchecked\b/', $h3));
    check('P3 no uploads note on a first send', !str_contains($h3, 'already on file. Only add new'));
    check('P3 opening still flips sent → opened', (db_row('SELECT status FROM customer_credit_applications WHERE id = ?', [$app1])['status'] ?? '') === 'opened');

    // ── P4: needs-info re-send — previous answers first ──────────────────
    db_update('customer_credit_applications', [
        'status'                => 'reviewed',
        'review_outcome'        => 'needs_info',
        'submitted_at'          => ff_now_utc(),
        'reviewed_at'           => ff_now_utc(),
        'uploaded_document_ids' => json_encode([987654321]),
        'form_data'             => json_encode([
            'company'    => [
                'name' => 'Applicant Typed Name Ltd', 'email' => 'applicant@example.test', 'phone' => '+1 778-555-0199',
                'physical_address' => '200 Applicant Ave', 'physical_city' => 'Langley', 'physical_province' => 'BC',
                'physical_postal' => 'V2Y 1A1', 'same_as_physical' => 'No',
                'billing_address' => 'PO Box 77', 'billing_city' => 'Langley', 'billing_province' => 'BC', 'billing_postal' => 'V2Y 9Z9',
                'business_type' => 'Carrier', 'gst_number' => '', 'pst_number' => '',
            ],
            'principals' => [['name' => 'Pat Owner', 'title' => 'President'], ['name' => '', 'title' => '']],
            'insurance'  => ['has_trailer_insurance' => 'Yes', 'company' => 'Smoke Insurance Inc', 'agent' => 'Ann Agent', 'phone' => '+1'],
            'equipment'  => ['tractors_owned' => '12', 'tractors_leased' => '', 'owner_operators' => '3'],
            'credit'     => ['credit_requested' => '25,000', 'has_purchase_order' => 'Yes'],
            'references' => [
                ['company' => 'Ref One Freight', 'phone' => '+1 604-555-0001', 'email' => ''],
                ['company' => 'Ref Two Logistics', 'phone' => '+1', 'email' => 'ref2@example.test'],
                ['company' => '', 'phone' => '+1', 'email' => ''],
            ],
        ]),
    ], 'id = ?', [$app1]);
    [$app2, $tok2] = newApp($custA);
    [$c4, $h4] = http($formUrl . '?token=' . $tok2);
    check('P4 form renders (200)', $c4 === 200 && str_contains($h4, 'id="cca-form"'), "HTTP $c4");
    check('P4 previous answer wins over the customer record (company name)', inputValue($h4, 'company_name') === 'Applicant Typed Name Ltd',
        var_export(inputValue($h4, 'company_name'), true));
    check('P4 principal, insurance, equipment, credit carried over',
        inputValue($h4, 'principal1_name') === 'Pat Owner'
        && inputValue($h4, 'insurance_company') === 'Smoke Insurance Inc'
        && inputValue($h4, 'tractors_owned') === '12'
        && inputValue($h4, 'credit_requested') === '25,000');
    check('P4 references carried over', inputValue($h4, 'ref1_company') === 'Ref One Freight' && inputValue($h4, 'ref2_email') === 'ref2@example.test');
    check('P4 empty phone keeps the "+1 " default', inputValue($h4, 'insurance_phone') === '+1 ' && inputValue($h4, 'ref3_phone') === '+1 ');
    check('P4 radios carried over (insurance Yes, PO Yes, billing No) + Alpine state',
        radioChecked($h4, 'has_trailer_insurance', 'Yes') && radioChecked($h4, 'has_purchase_order', 'Yes')
        && radioChecked($h4, 'same_as_physical', 'No') && str_contains($h4, "hasInsurance:   'Yes'"));
    check('P4 billing fields from the previous answers', inputValue($h4, 'billing_address') === 'PO Box 77' && inputValue($h4, 'billing_postal') === 'V2Y 9Z9');
    check('P4 gap (blank GST last time) topped up from the customer record', inputValue($h4, 'gst_number') === '123456789RT0001');
    check('P4 banner says "last application"', str_contains($h4, 'from your last application'));
    check('P4 uploads note shown (last application had files)', str_contains($h4, 'already on file. Only add new'));
    check('P4 printed name still blank', inputValue($h4, 'print_name_first') === '');

    // ── P6: no cross-customer leak ───────────────────────────────────────
    check('P6 other customer\'s submission never appears', !str_contains($h3, 'LEAK-') && !str_contains($h4, 'LEAK-'));

    // ── P5: POST with errors re-fills what was typed, not the pre-fill ───
    $csrf = preg_match('/name="csrf_token" value="([^"]+)"/', $h4, $m) ? $m[1] : '';
    check('P5 CSRF token found on the page', $csrf !== '');
    $rlBucket = 'cca:submit:' . substr(hash('sha256', $tok2), 0, 24) . ':';
    [$c5, $h5] = http($formUrl . '?token=' . urlencode($tok2), [
        'csrf_token'      => $csrf,
        'token'           => $tok2,
        'company_name'    => 'Typed Fresh Name',
        'principal1_name' => '',          // applicant deliberately cleared it
        // everything else required is missing → validation errors
    ]);
    check('P5 invalid POST re-renders the form (no redirect)', $c5 === 200 && str_contains($h5, 'Please correct the errors below'), "HTTP $c5");
    check('P5 typed value re-filled', inputValue($h5, 'company_name') === 'Typed Fresh Name');
    check('P5 cleared field stays cleared (pre-fill is GET-only)', inputValue($h5, 'principal1_name') === '',
        var_export(inputValue($h5, 'principal1_name'), true));
    check('P5 no pre-fill banner on the error page', !str_contains($h5, 'id="cca-prefill-note"'));
    check('P5 application not submitted', (db_row('SELECT status FROM customer_credit_applications WHERE id = ?', [$app2])['status'] ?? '') === 'opened');

    // ── P7: soft-deleted previous application ignored ────────────────────
    db_execute('UPDATE customer_credit_applications SET deleted_at = ? WHERE id = ?', [ff_now_utc(), $app1]);
    [$app3, $tok3] = newApp($custA);
    [, $h7] = http($formUrl . '?token=' . $tok3);
    check('P7 deleted previous ignored → customer record used', inputValue($h7, 'company_name') === $tag . ' "Prefill" & <Co>'
        && str_contains($h7, 'already have on file') && inputValue($h7, 'principal1_name') === '');

    // ── P8: expired link shows nothing ───────────────────────────────────
    [, $tok8] = newApp($custA, '-1 day');
    [$c8, $h8] = http($formUrl . '?token=' . $tok8);
    check('P8 expired link shows the expired state', str_contains($h8, 'cca-state-title') && !str_contains($h8, 'id="cca-form"'), "HTTP $c8");
    check('P8 expired link reveals no customer data', !str_contains($h8, '123456789RT0001') && !str_contains($h8, '100 Smoke St')
        && !str_contains($h8, $tag . '@example.test'));

    // ── P9: admin preview (customer_id 0) ────────────────────────────────
    $p9 = cca_prefill_for_application(0, 0);
    check('P9 customer_id 0 pre-fills nothing', $p9['values'] === [] && $p9['source'] === null);
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    // Customers' FK CASCADE removes their application rows.
    foreach ([$custA, $custB] as $cid) {
        if ($cid > 0) {
            db_execute('DELETE FROM customer_credit_applications WHERE customer_id = ?', [$cid]);
            db_execute('DELETE FROM customers WHERE id = ?', [$cid]);
        }
    }
    if ($rlBucket !== null) {
        db_execute('DELETE FROM rate_limit_attempts WHERE bucket_key LIKE ?', [$rlBucket . '%']);
    }
    @unlink($cookieJar);
    $left = db_row("SELECT COUNT(*) AS n FROM customers WHERE company_name LIKE ?", [$tag . '%']);
    check('cleanup: no zz-smoke customers left', (int) ($left['n'] ?? 1) === 0);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
