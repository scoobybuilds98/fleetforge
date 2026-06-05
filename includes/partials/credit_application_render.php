<?php
declare(strict_types=1);

/**
 * includes/partials/credit_application_render.php
 *
 * Shared mPDF-safe HTML renderer for a submitted credit application.
 * Drives BOTH the stored rendered_html snapshot and the mPDF PDF input.
 *
 * Rules (D-CCA-3-B):
 *   - Table-based layout with inline styles — no flex/grid (mPDF safe).
 *   - Signature embedded as data:image/png;base64,… (D-CCA-3-C, Trap 7).
 *   - All user field values htmlspecialchars-escaped.
 *
 * cca_render_html(array $params): string
 *
 * @param array $params {
 *   app_id           int
 *   customer_company string   — FF customers.company_name (D-CCA-4: NOT applicant entry)
 *   submitted_at     string   — Y-m-d H:i:s
 *   submitted_ip     string
 *   print_name_first string
 *   print_name_last  string
 *   signed_date      string
 *   terms_accepted   int
 *   terms_version    string
 *   form_data        array    — decoded from JSON
 *   signature_b64    string   — raw base64 (without data:image/png;base64, prefix)
 *   disclaimer_html  string   — trusted HTML from settings
 *   min_req_html     string   — trusted HTML from settings
 *   company_name     string   — FF company name for branded header
 * }
 *
 * @session S-CCA-3
 */

if (!function_exists('_cca_e')) {
    function _cca_e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('_cca_val')) {
    function _cca_val(array $arr, string $key, string $fallback = '—'): string
    {
        $v = trim((string)($arr[$key] ?? ''));
        return _cca_e($v !== '' ? $v : $fallback);
    }
}

if (!function_exists('cca_render_html')) {
    function cca_render_html(array $p): string
    {
        ob_start();

        $fd         = $p['form_data']    ?? [];
        $company    = $fd['company']     ?? [];
        $principals = $fd['principals']  ?? [];
        $insurance  = $fd['insurance']   ?? [];
        $equipment  = $fd['equipment']   ?? [];
        $credit     = $fd['credit']      ?? [];
        $references = $fd['references']  ?? [];
        $sigB64     = $p['signature_b64'] ?? '';

        $submittedAt = $p['submitted_at'] ?? '';
        if ($submittedAt !== '') {
            $ts = strtotime($submittedAt);
            $submittedAt = $ts ? date('F j, Y \a\t g:i A', $ts) : $submittedAt;
        }

        $brandColor  = '#f97316';
        $headerBg    = '#1a1a1a';
        $sectionBg   = '#f8fafc';
        $borderColor = '#e2e8f0';
        $labelColor  = '#64748b';
        $valueColor  = '#0f172a';
        $headingColor = '#0f172a';

        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Application — <?= _cca_e($p['customer_company'] ?? '') ?></title>
<style>
body { font-family: Arial, sans-serif; font-size: 10pt; color: <?= $valueColor ?>; margin: 0; padding: 0; background: #ffffff; }
table { border-collapse: collapse; }
.page-wrap { max-width: 760px; margin: 0 auto; padding: 24px; }
.hdr-bar { background: <?= $headerBg ?>; color: #ffffff; padding: 14px 20px; border-radius: 6px 6px 0 0; }
.hdr-bar .co { font-size: 14pt; font-weight: bold; }
.hdr-bar .sub { font-size: 9pt; color: #cbd5e1; margin-top: 2px; }
.hdr-accent { height: 4px; background: <?= $brandColor ?>; margin-bottom: 18px; }
.meta-bar { background: <?= $sectionBg ?>; border: 1px solid <?= $borderColor ?>; border-radius: 4px; padding: 8px 14px; margin-bottom: 18px; font-size: 8.5pt; color: <?= $labelColor ?>; }
.meta-bar td { padding: 2px 14px 2px 0; }
.section { margin-bottom: 16px; }
.section-title { background: <?= $sectionBg ?>; border-left: 3px solid <?= $brandColor ?>; padding: 6px 12px; font-size: 9.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: <?= $headingColor ?>; margin-bottom: 8px; }
.ft { width: 100%; border-collapse: collapse; }
.ft td { padding: 4px 8px 4px 0; vertical-align: top; font-size: 9.5pt; }
.ft .lbl { width: 38%; color: <?= $labelColor ?>; white-space: nowrap; }
.ft .val { color: <?= $valueColor ?>; font-weight: 500; }
.ft .sep { border-top: 1px solid <?= $borderColor ?>; height: 1px; padding: 0; font-size: 0; }
.ref-block { border: 1px solid <?= $borderColor ?>; border-radius: 4px; padding: 8px 12px; margin-bottom: 8px; background: #ffffff; }
.ref-label { font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; color: <?= $labelColor ?>; margin-bottom: 4px; }
.disclaimer-box { border: 1px solid <?= $borderColor ?>; border-radius: 4px; padding: 12px 14px; font-size: 8pt; color: #475569; line-height: 1.6; background: #f8fafc; margin-bottom: 12px; }
.min-req-box { border: 1px solid <?= $borderColor ?>; border-radius: 4px; padding: 12px 14px; font-size: 8.5pt; color: #334155; line-height: 1.65; background: #fafafa; }
.sig-block { border: 1px solid <?= $borderColor ?>; border-radius: 4px; padding: 10px; background: #ffffff; text-align: center; max-width: 280px; }
.sig-block img { max-width: 260px; max-height: 80px; display: block; margin: 0 auto; }
.sig-meta { font-size: 8pt; color: <?= $labelColor ?>; margin-top: 6px; }
.footer-note { font-size: 7.5pt; color: #94a3b8; margin-top: 20px; border-top: 1px solid <?= $borderColor ?>; padding-top: 8px; text-align: center; }
</style>
</head>
<body>
<div class="page-wrap">

<!-- Branded header -->
<div class="hdr-bar">
  <div class="co"><?= _cca_e($p['company_name'] ?? 'FleetForge') ?></div>
  <div class="sub">Credit Application &mdash; <?= _cca_e($p['customer_company'] ?? '') ?></div>
</div>
<div class="hdr-accent"></div>

<!-- Metadata bar -->
<table class="meta-bar" width="100%"><tr>
  <td><strong>Application #<?= (int)($p['app_id'] ?? 0) ?></strong></td>
  <td>Submitted: <?= _cca_e($submittedAt) ?></td>
  <td>IP: <?= _cca_e($p['submitted_ip'] ?? '&mdash;') ?></td>
</tr></table>

<!-- §1 Company Information -->
<div class="section">
  <div class="section-title">§1 Company Information</div>
  <table class="ft" width="100%">
    <tr><td class="lbl">Legal Company Name</td><td class="val"><?= _cca_val($company, 'name') ?></td></tr>
    <tr><td class="lbl">Email</td><td class="val"><?= _cca_val($company, 'email') ?></td></tr>
    <tr><td class="lbl">Phone</td><td class="val"><?= _cca_val($company, 'phone') ?></td></tr>
    <tr><td class="sep" colspan="2"></td></tr>
    <tr><td class="lbl">Physical Address</td><td class="val"><?= _cca_val($company, 'physical_address') ?></td></tr>
    <tr><td class="lbl">City / Province / Postal</td>
      <td class="val"><?= _cca_val($company, 'physical_city') ?>, <?= _cca_val($company, 'physical_province') ?>&nbsp; <?= _cca_val($company, 'physical_postal') ?></td></tr>
    <tr><td class="lbl">Billing Same as Physical</td><td class="val"><?= _cca_val($company, 'same_as_physical') ?></td></tr>
    <?php if (trim((string)($company['billing_address'] ?? '')) !== ''): ?>
    <tr><td class="lbl">Billing Address</td><td class="val"><?= _cca_val($company, 'billing_address') ?></td></tr>
    <tr><td class="lbl">Billing City / Province / Postal</td>
      <td class="val"><?= _cca_val($company, 'billing_city') ?>, <?= _cca_val($company, 'billing_province') ?>&nbsp; <?= _cca_val($company, 'billing_postal') ?></td></tr>
    <?php endif; ?>
    <tr><td class="sep" colspan="2"></td></tr>
    <tr><td class="lbl">Business Type</td><td class="val"><?= _cca_val($company, 'business_type') ?></td></tr>
    <tr><td class="lbl">Incorporation #</td><td class="val"><?= _cca_val($company, 'incorporation') ?></td></tr>
    <tr><td class="lbl">Years in Business</td><td class="val"><?= _cca_val($company, 'duration') ?></td></tr>
    <tr><td class="lbl">WCB #</td><td class="val"><?= _cca_val($company, 'wcb_number') ?></td></tr>
    <tr><td class="lbl">GST #</td><td class="val"><?= _cca_val($company, 'gst_number') ?></td></tr>
    <tr><td class="lbl">PST #</td><td class="val"><?= _cca_val($company, 'pst_number') ?></td></tr>
  </table>
</div>

<!-- §2 Principals -->
<div class="section">
  <div class="section-title">§2 Principals</div>
  <table class="ft" width="100%">
    <?php foreach ($principals as $i => $pr): ?>
    <tr>
      <td class="lbl">Principal <?= (int)$i + 1 ?></td>
      <td class="val"><?= _cca_e(trim((string)($pr['name'] ?? ''))) ?: '&mdash;' ?>
        <?php if (trim((string)($pr['title'] ?? '')) !== ''): ?>&nbsp;(<?= _cca_e($pr['title']) ?>)<?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- §3 Insurance -->
<div class="section">
  <div class="section-title">§3 Insurance Information</div>
  <table class="ft" width="100%">
    <tr><td class="lbl">Has Trailer Insurance</td><td class="val"><?= _cca_val($insurance, 'has_trailer_insurance') ?></td></tr>
    <tr><td class="lbl">Insurance Company</td><td class="val"><?= _cca_val($insurance, 'company') ?></td></tr>
    <tr><td class="lbl">Agent Name</td><td class="val"><?= _cca_val($insurance, 'agent') ?></td></tr>
    <tr><td class="lbl">Agent Phone</td><td class="val"><?= _cca_val($insurance, 'phone') ?></td></tr>
  </table>
</div>

<!-- §4 Equipment -->
<div class="section">
  <div class="section-title">§4 Equipment Information</div>
  <table class="ft" width="100%">
    <tr><td class="lbl">Tractors Owned</td><td class="val"><?= _cca_val($equipment, 'tractors_owned') ?></td></tr>
    <tr><td class="lbl">Tractors Leased</td><td class="val"><?= _cca_val($equipment, 'tractors_leased') ?></td></tr>
    <tr><td class="lbl">Owner Operators</td><td class="val"><?= _cca_val($equipment, 'owner_operators') ?></td></tr>
  </table>
</div>

<!-- §5 Credit -->
<div class="section">
  <div class="section-title">§5 Credit Information</div>
  <table class="ft" width="100%">
    <tr><td class="lbl">Credit Amount Requested</td><td class="val"><?= _cca_val($credit, 'credit_requested') ?></td></tr>
    <tr><td class="lbl">Purchase Order</td><td class="val"><?= _cca_val($credit, 'has_purchase_order') ?></td></tr>
  </table>
</div>

<!-- §6 Trade References -->
<div class="section">
  <div class="section-title">§6 Trade References</div>
  <?php foreach ($references as $i => $ref): ?>
  <div class="ref-block">
    <div class="ref-label">Reference <?= (int)$i + 1 ?></div>
    <table class="ft" width="100%">
      <tr><td class="lbl" width="30%">Company</td><td class="val"><?= _cca_e(trim((string)($ref['company'] ?? ''))) ?: '&mdash;' ?></td></tr>
      <tr><td class="lbl">Phone</td><td class="val"><?= _cca_e(trim((string)($ref['phone'] ?? ''))) ?: '&mdash;' ?></td></tr>
      <tr><td class="lbl">Email</td><td class="val"><?= _cca_e(trim((string)($ref['email'] ?? ''))) ?: '&mdash;' ?></td></tr>
    </table>
  </div>
  <?php endforeach; ?>
</div>

<!-- §8 Disclaimer & Sign-Off -->
<div class="section">
  <div class="section-title">§8 Disclaimer &amp; Sign-Off</div>
  <?php if (!empty($p['disclaimer_html'])): ?>
  <div class="disclaimer-box"><?= $p['disclaimer_html'] /* trusted HTML from settings */ ?></div>
  <?php endif; ?>
  <table class="ft" width="100%">
    <tr><td class="lbl">Date Signed</td><td class="val"><?= _cca_e($p['signed_date'] ?? '&mdash;') ?></td></tr>
    <tr><td class="lbl">Name (Printed)</td>
      <td class="val"><?= _cca_e(trim(($p['print_name_first'] ?? '') . ' ' . ($p['print_name_last'] ?? ''))) ?></td></tr>
    <tr><td class="lbl">Terms Accepted</td><td class="val"><?= (int)($p['terms_accepted'] ?? 0) ? 'Yes' : 'No' ?></td></tr>
    <tr><td class="lbl">Terms Version</td><td class="val"><?= _cca_e($p['terms_version'] ?? '&mdash;') ?></td></tr>
  </table>

  <!-- Inline signature (D-CCA-3-C: embedded data URI; Trap 7 — no path exposed) -->
  <?php if ($sigB64 !== ''): ?>
  <div style="margin-top: 12px;">
    <div style="font-size: 8.5pt; color: <?= $labelColor ?>; margin-bottom: 6px;">Signature:</div>
    <div class="sig-block">
      <img src="data:image/png;base64,<?= htmlspecialchars($sigB64, ENT_QUOTES, 'UTF-8') ?>" alt="Applicant Signature">
      <div class="sig-meta">
        Signed by: <?= _cca_e(trim(($p['print_name_first'] ?? '') . ' ' . ($p['print_name_last'] ?? ''))) ?><br>
        Date: <?= _cca_e($p['signed_date'] ?? '') ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- §9 Minimum Requirements -->
<?php if (!empty($p['min_req_html'])): ?>
<div class="section">
  <div class="section-title">§9 Minimum Insurance Requirements</div>
  <div class="min-req-box"><?= $p['min_req_html'] /* trusted HTML from settings */ ?></div>
</div>
<?php endif; ?>

<div class="footer-note">
  This document is a legally binding credit application submitted to <?= _cca_e($p['company_name'] ?? 'FleetForge') ?>.
  Application #<?= (int)($p['app_id'] ?? 0) ?> &mdash; Generated <?= date('Y-m-d H:i') ?> &mdash; Confidential
</div>

</div><!-- .page-wrap -->
</body>
</html>
        <?php

        return (string)ob_get_clean();
    }
}
