<?php
declare(strict_types=1);

/**
 * includes/partials/credit_application_prefill.php
 *
 * Starting values for the public credit-application form, so the applicant
 * checks and corrects rather than retyping (S-CCA-PREFILL).
 *
 * Two sources, in priority order:
 *   1. The customer's most recent SUBMITTED application (form_data snapshot).
 *      This is the "needs info" case: they only fix what the reviewer asked
 *      about instead of redoing all nine sections.
 *   2. The customers row: name, email, phone, address, billing address,
 *      GST/PST. Fills any field the previous submission left blank, and is
 *      the whole pre-fill on a first send.
 *
 * Never pre-filled: signature, signed date, printed name, terms acceptance
 * (the applicant must re-attest every time) and file uploads (a browser
 * cannot pre-select files). The customer's internal fields (notes, credit
 * limit, risk, terms) are never read.
 *
 * Direction matters for D-CCA-4: this copies customer → form, never
 * applicant → customer. Whatever the applicant submits still lands only in
 * the form_data snapshot.
 *
 * Values are returned keyed by the form's POST input names, so the form's
 * existing `$old[...]` re-fill plumbing renders them unchanged — and all
 * escaping stays in the form's own e() calls.
 *
 * Required by: app/admin/credit-application.php, tests/_smoke_cca_prefill.php
 * Defines:     cca_prefill_from_form_data(), cca_prefill_from_customer(),
 *              cca_prefill_for_application()
 *
 * Decisions: D-CCA-4 (applicant data never written to customers)
 * @session S-CCA-PREFILL
 */

if (!function_exists('cca_prefill_from_form_data')) {

    /**
     * Map a stored form_data snapshot back onto the form's input names.
     *
     * Only non-empty values are returned, so inputs with a built-in default
     * (phone fields start as "+1 ") keep it when there is nothing to show.
     * Radio answers pass only as the exact 'Yes'/'No' the form accepts —
     * two of them are interpolated into the Alpine init script, so nothing
     * else may reach it.
     *
     * @param  array $fd Decoded customer_credit_applications.form_data.
     * @return array<string,string> POST-shaped values (input name => value).
     */
    function cca_prefill_from_form_data(array $fd): array
    {
        $c   = is_array($fd['company'] ?? null)    ? $fd['company']    : [];
        $ins = is_array($fd['insurance'] ?? null)  ? $fd['insurance']  : [];
        $eq  = is_array($fd['equipment'] ?? null)  ? $fd['equipment']  : [];
        $cr  = is_array($fd['credit'] ?? null)     ? $fd['credit']     : [];
        $pr  = is_array($fd['principals'] ?? null) ? array_values($fd['principals']) : [];
        $rf  = is_array($fd['references'] ?? null) ? array_values($fd['references']) : [];

        $out = [
            'company_name'          => $c['name'] ?? '',
            'company_email'         => $c['email'] ?? '',
            'company_phone'         => $c['phone'] ?? '',
            'physical_address'      => $c['physical_address'] ?? '',
            'physical_city'         => $c['physical_city'] ?? '',
            'physical_province'     => $c['physical_province'] ?? '',
            'physical_postal'       => $c['physical_postal'] ?? '',
            'same_as_physical'      => $c['same_as_physical'] ?? '',
            'billing_address'       => $c['billing_address'] ?? '',
            'billing_city'          => $c['billing_city'] ?? '',
            'billing_province'      => $c['billing_province'] ?? '',
            'billing_postal'        => $c['billing_postal'] ?? '',
            'business_type'         => $c['business_type'] ?? '',
            'incorporation'         => $c['incorporation'] ?? '',
            'duration'              => $c['duration'] ?? '',
            'wcb_number'            => $c['wcb_number'] ?? '',
            'gst_number'            => $c['gst_number'] ?? '',
            'pst_number'            => $c['pst_number'] ?? '',
            'has_trailer_insurance' => $ins['has_trailer_insurance'] ?? '',
            'insurance_company'     => $ins['company'] ?? '',
            'insurance_agent'       => $ins['agent'] ?? '',
            'insurance_phone'       => $ins['phone'] ?? '',
            'tractors_owned'        => $eq['tractors_owned'] ?? '',
            'tractors_leased'       => $eq['tractors_leased'] ?? '',
            'owner_operators'       => $eq['owner_operators'] ?? '',
            'credit_requested'      => $cr['credit_requested'] ?? '',
            'has_purchase_order'    => $cr['has_purchase_order'] ?? '',
        ];
        // The snapshot stores principals/references as lists; the form posts
        // them as numbered flat inputs (principal1_name, ref2_email, …).
        foreach ([1, 2] as $n) {
            $p = is_array($pr[$n - 1] ?? null) ? $pr[$n - 1] : [];
            $out["principal{$n}_name"]  = $p['name'] ?? '';
            $out["principal{$n}_title"] = $p['title'] ?? '';
        }
        foreach ([1, 2, 3] as $n) {
            $r = is_array($rf[$n - 1] ?? null) ? $rf[$n - 1] : [];
            $out["ref{$n}_company"] = $r['company'] ?? '';
            $out["ref{$n}_phone"]   = $r['phone'] ?? '';
            $out["ref{$n}_email"]   = $r['email'] ?? '';
        }

        return _cca_prefill_clean($out);
    }

    /**
     * Map a customers row onto the form's input names.
     *
     * Only contact/registration fields a customer would recognise as their
     * own. Credit limit is deliberately NOT mapped to "credit requested":
     * that field is the applicant's ask, not our decision.
     *
     * @param  array $cust customers row (company_name, email, billing_email,
     *                     phone, address, city, province, state, postal_code,
     *                     billing_address, gst_number, pst_number).
     * @return array<string,string> POST-shaped values (input name => value).
     */
    function cca_prefill_from_customer(array $cust): array
    {
        $str = static fn($v): string => trim((string) ($v ?? ''));

        $out = [
            'company_name'      => $str($cust['company_name'] ?? ''),
            // Same recipient rule as api/v1/credit_applications/send.php.
            'company_email'     => $str($cust['email'] ?? '') ?: $str($cust['billing_email'] ?? ''),
            'company_phone'     => $str($cust['phone'] ?? ''),
            'physical_address'  => $str($cust['address'] ?? ''),
            'physical_city'     => $str($cust['city'] ?? ''),
            // Older rows carry the province in `state`.
            'physical_province' => $str($cust['province'] ?? '') ?: $str($cust['state'] ?? ''),
            'physical_postal'   => $str($cust['postal_code'] ?? ''),
            'gst_number'        => $str($cust['gst_number'] ?? ''),
            'pst_number'        => $str($cust['pst_number'] ?? ''),
        ];

        // customers.billing_address is one free-text block, and blank means
        // "bill to the main address" (customer form rule). Only answer the
        // same-as-physical question when we actually hold a main address.
        $billing = $str($cust['billing_address'] ?? '');
        if ($billing !== '') {
            $out['same_as_physical'] = 'No';
            // Single-line input: a browser drops newlines inside value="",
            // gluing "PO Box 1" and "Surrey" together — join them instead.
            $out['billing_address'] = (string) preg_replace('/\s*[\r\n]+\s*/', ', ', $billing);
        } elseif ($out['physical_address'] !== '') {
            $out['same_as_physical'] = 'Yes';
        }

        return _cca_prefill_clean($out);
    }

    /**
     * Work out the starting values for one application's form.
     *
     * @param  int $customerId    customer_credit_applications.customer_id
     * @param  int $applicationId The application being filled in; excluded
     *                            from the "previous submission" lookup.
     * @return array{values: array<string,string>,
     *               source: 'previous'|'customer'|null,
     *               previous_had_uploads: bool}
     *         source 'previous' = a prior submission seeded the form (with
     *         blanks topped up from the customer record); 'customer' = only
     *         the customer record; null = nothing to pre-fill.
     */
    function cca_prefill_for_application(int $customerId, int $applicationId): array
    {
        $none = ['values' => [], 'source' => null, 'previous_had_uploads' => false];
        if ($customerId <= 0) {
            return $none; // admin preview uses customer_id 0
        }

        // Latest earlier submission for THIS customer only — the token proves
        // who the applicant is, so another customer's answers never qualify.
        // Soft-deleted rows were withdrawn by staff and are skipped.
        $prev = db_row(
            "SELECT form_data,
                    COALESCE(JSON_LENGTH(uploaded_document_ids), 0) AS upload_count
               FROM customer_credit_applications
              WHERE customer_id = ?
                AND id <> ?
                AND deleted_at IS NULL
                AND status IN ('submitted', 'reviewed')
                AND form_data IS NOT NULL
              ORDER BY submitted_at DESC, id DESC
              LIMIT 1",
            [$customerId, $applicationId]
        );

        $fromPrev = [];
        if ($prev) {
            $fd = json_decode((string) $prev['form_data'], true);
            if (is_array($fd)) {
                $fromPrev = cca_prefill_from_form_data($fd);
            }
        }

        $cust = db_row(
            "SELECT company_name, email, billing_email, phone, address, city,
                    province, state, postal_code, billing_address,
                    gst_number, pst_number
               FROM customers
              WHERE id = ? AND deleted_at IS NULL",
            [$customerId]
        );
        $fromCust = $cust ? cca_prefill_from_customer($cust) : [];

        // Previous answers win; the customer record only fills their gaps.
        // One exception: if the previous submission already answered the
        // billing question, don't let the customer record's billing block
        // contradict it (e.g. "Yes, same as physical" + a stray billing line).
        if (isset($fromPrev['same_as_physical'])) {
            unset($fromCust['same_as_physical'], $fromCust['billing_address']);
        }
        $values = $fromPrev + $fromCust;

        if ($values === []) {
            return $none;
        }
        return [
            'values'               => $values,
            'source'               => $fromPrev !== [] ? 'previous' : 'customer',
            'previous_had_uploads' => $fromPrev !== [] && (int) ($prev['upload_count'] ?? 0) > 0,
        ];
    }

    /**
     * Drop empty values and coerce radio answers to the form's exact
     * 'Yes'/'No' vocabulary (anything else is dropped, not guessed).
     *
     * @param  array $vals input name => raw value
     * @return array<string,string>
     */
    function _cca_prefill_clean(array $vals): array
    {
        $out = [];
        foreach ($vals as $k => $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $v = trim((string) $v);
            if (in_array($k, ['same_as_physical', 'has_trailer_insurance', 'has_purchase_order'], true)
                && !in_array($v, ['Yes', 'No'], true)) {
                continue;
            }
            // A bare "+1" is the phone inputs' own placeholder default that
            // got submitted untouched — not a real answer.
            if ($v === '' || $v === '+1') {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
