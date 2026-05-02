<?php
declare(strict_types=1);

/**
 * lib/Email/templates/customer_compliance_expiring.php
 *
 * Customer-facing compliance expiry digest email template.
 * Returns the inner HTML body only — wrap in
 * EmailService::renderEmailHtml() before passing to Mailer::send().
 *
 * @param array{
 *   customer_name: string,
 *   company_name:  string,
 *   units:         list<array{unit_number:string, unit_id:int, contract_number:string, docs:list<array{name:string, expiry:string}>}>,
 *   portal_url:    string,
 * } $data
 * @return string  Inner HTML body (inline-style only, no wrapper tags)
 * @session CVI-EMAIL-1
 */
function render_customer_compliance_email(array $data): string
{
    $customerName = htmlspecialchars((string)($data['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $companyName  = htmlspecialchars((string)($data['company_name']  ?? 'FleetForge'), ENT_QUOTES, 'UTF-8');
    $portalUrl    = htmlspecialchars((string)($data['portal_url']    ?? ''), ENT_QUOTES, 'UTF-8');
    $units        = is_array($data['units'] ?? null) ? $data['units'] : [];

    $todayDt = new \DateTime('today');

    $unitRowsHtml = '';
    foreach ($units as $unit) {
        $unitNumber      = htmlspecialchars((string)($unit['unit_number']      ?? ''), ENT_QUOTES, 'UTF-8');
        $contractNumber  = (string)($unit['contract_number'] ?? '');
        $unitHeading     = 'Unit ' . $unitNumber;
        if ($contractNumber !== '') {
            $unitHeading .= ' &mdash; Contract ' . htmlspecialchars($contractNumber, ENT_QUOTES, 'UTF-8');
        }
        $docsHtml = '';

        foreach ((array)($unit['docs'] ?? []) as $doc) {
            $docName = htmlspecialchars((string)($doc['name']   ?? ''), ENT_QUOTES, 'UTF-8');
            $expiry  = (string)($doc['expiry'] ?? '');
            $expiryDisplay = htmlspecialchars($expiry, ENT_QUOTES, 'UTF-8');

            // Compute day delta so the badge shows concrete day counts
            // instead of just a 3-state urgency label.
            try {
                $expiryDt = new \DateTime($expiry);
                $diffDays = (int) $todayDt->diff($expiryDt)->format('%r%a');
            } catch (\Throwable $e) {
                $diffDays = 0;
            }

            if ($diffDays < 0) {
                $n       = abs($diffDays);
                $bgColor = '#dc2626';
                $label   = 'EXPIRED ' . $n . ' ' . ($n === 1 ? 'day' : 'days') . ' ago';
            } elseif ($diffDays === 0) {
                $bgColor = '#dc2626';
                $label   = 'EXPIRES TODAY';
            } elseif ($diffDays <= 7) {
                $bgColor = '#dc2626';
                $label   = $diffDays . ' ' . ($diffDays === 1 ? 'day' : 'days') . ' remaining';
            } else {
                $bgColor = '#d97706';
                $label   = $diffDays . ' days remaining';
            }

            $docsHtml .=
                '<tr>'
                . '<td style="padding:4px 8px 4px 0;font-size:13px;color:#374151;">' . $docName . '</td>'
                . '<td style="padding:4px 8px;font-size:13px;font-family:monospace;color:#374151;">' . $expiryDisplay . '</td>'
                . '<td style="padding:4px 0 4px 8px;">'
                    . '<span style="display:inline-block;padding:2px 8px;border-radius:4px;'
                    . 'font-size:11px;font-weight:700;background:' . $bgColor . ';color:#fff;">' . $label . '</span>'
                . '</td>'
                . '</tr>';
        }

        $unitRowsHtml .=
            '<div style="margin-bottom:16px;padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">'
            . '<div style="font-size:14px;font-weight:600;color:#1c1c1a;margin-bottom:8px;">' . $unitHeading . '</div>'
            . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;">'
            . '<thead><tr>'
            . '<th style="text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;padding-bottom:6px;">Document</th>'
            . '<th style="text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;padding-bottom:6px;padding-left:8px;">Expiry Date</th>'
            . '<th style="text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;padding-bottom:6px;padding-left:8px;">Status</th>'
            . '</tr></thead>'
            . '<tbody>' . $docsHtml . '</tbody>'
            . '</table>'
            . '</div>';
    }

    $unitCount = count($units);
    $unitWord  = $unitCount === 1 ? 'unit' : 'units';

    return
        '<p style="margin:0 0 16px;">Hi ' . $customerName . ',</p>'
        . '<p style="margin:0 0 16px;">This is an automated compliance notice from <strong>' . $companyName . '</strong>.</p>'
        . '<p style="margin:0 0 16px;">The following ' . $unitWord . ' in your fleet '
        . 'have compliance documents that require attention:</p>'
        . $unitRowsHtml
        . '<p style="margin:16px 0;">Please renew these documents promptly to avoid disruption '
        . 'to your equipment operations.</p>'
        . '<p style="margin:0 0 16px;">'
        . '<a href="' . $portalUrl . '" style="display:inline-block;padding:10px 20px;'
        . 'background:#1c1c1a;color:#ffffff;text-decoration:none;border-radius:5px;'
        . 'font-size:14px;font-weight:600;">View Documents in Portal</a>'
        . '</p>'
        . '<p style="margin:16px 0 0;font-size:12px;color:#6b7280;">'
        . 'If you have already renewed these documents, please upload them to your portal account '
        . 'or contact us directly.</p>'
        . '<p style="margin:8px 0 0;font-size:12px;color:#6b7280;">'
        . 'To stop receiving these notifications, log in to your portal and update your '
        . '<a href="' . $portalUrl . '" style="color:#6b7280;">notification preferences</a>.</p>';
}
