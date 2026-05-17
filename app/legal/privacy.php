<?php
declare(strict_types=1);

/**
 * app/legal/privacy.php — Privacy Policy (public route /legal/privacy)
 *
 * Session: S-LEGAL-FOOTER-COMMERCIAL (2026-05-17)
 */

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');

$pageTitle = 'Privacy Policy';
require FF_ROOT . '/includes/legal_header.php';
?>

<div class="legal-hero">
    <h1>Privacy Policy</h1>
    <p>Effective: January 1, 2026 &nbsp;·&nbsp; Last updated: May 17, 2026 &nbsp;·&nbsp; Jurisdiction: British Columbia, Canada (PIPEDA / BC&nbsp;PIPA)</p>
</div>

<div class="legal-highlight">
    Avi Technologies Inc. operates FleetForge in compliance with the Personal Information Protection and Electronic Documents Act (PIPEDA), the BC Personal Information Protection Act (BC&nbsp;PIPA), and — for individuals in the European Economic Area — the General Data Protection Regulation (GDPR).
</div>

<div class="legal-toc">
    <h3>Contents</h3>
    <ol>
        <li><a href="#intro">Introduction &amp; Scope</a></li>
        <li><a href="#collect">Information We Collect</a></li>
        <li><a href="#use">How We Use Your Information</a></li>
        <li><a href="#basis">Legal Basis for Processing</a></li>
        <li><a href="#gps">GPS &amp; Telematics Data</a></li>
        <li><a href="#share">How We Share Information</a></li>
        <li><a href="#retention">Data Retention</a></li>
        <li><a href="#security">Data Security</a></li>
        <li><a href="#rights">Your Rights</a></li>
        <li><a href="#cookies">Cookies &amp; Tracking</a></li>
        <li><a href="#children">Children's Privacy</a></li>
        <li><a href="#transfers">International Transfers</a></li>
        <li><a href="#changes">Changes to This Policy</a></li>
        <li><a href="#contact">Privacy Officer &amp; Contact</a></li>
    </ol>
</div>

<div class="legal-section" id="intro">
    <h2>1. Introduction &amp; Scope</h2>
    <p>This Privacy Policy explains how Avi Technologies Inc. ("Avi Technologies", "we") collects, uses, shares, and protects personal information processed through FleetForge. It applies to all visitors and authorized users of FleetForge, regardless of geography.</p>
    <p>Where Avi Technologies acts as a <em>data processor</em> on behalf of a Client (i.e., the company licensing FleetForge), that Client is the <em>data controller</em>. Our processing of personal data on behalf of Clients is governed by the <a href="<?= e(legal_url('dpa')) ?>">Data Processing Agreement</a>, which supplements this Policy.</p>
    <p>If you are an employee, contractor, driver, or customer of a Client using FleetForge, please direct questions about your data first to that Client (the controller). Avi Technologies will assist Clients in fulfilling data subject requests.</p>
</div>

<div class="legal-section" id="collect">
    <h2>2. Information We Collect</h2>
    <h3>2.1 Account &amp; Identity Data</h3>
    <p>Name, email address, role, employer (Client), preferred theme/density settings, and timestamps of account creation and last login.</p>
    <h3>2.2 Fleet Operations Data</h3>
    <p>Equipment unit numbers, makes, models, VINs, registration and inspection records, lease contracts and snapshots, customer records, reservation and pickup data, mileage logs, work orders, and inspection reports.</p>
    <h3>2.3 Financial Data</h3>
    <p>Invoices, payment records, credit notes, rate cards, and outstanding balances. Payment card details are <strong>never</strong> stored by Avi Technologies; if a payment processor integration is enabled, card data is tokenized by that processor.</p>
    <h3>2.4 GPS &amp; Telematics Data (when integration is enabled)</h3>
    <p>Vehicle location, speed, odometer readings, ignition state, fuel level, and engine diagnostics — pulled at intervals from third-party telematics providers (e.g. Samsara) at the Client's direction.</p>
    <h3>2.5 Usage Data</h3>
    <p>Login events, audit logs of state changes, feature usage statistics, and search queries within the application.</p>
    <h3>2.6 Communications</h3>
    <p>Support emails, in-app chat messages, and email templates you send through FleetForge to your customers.</p>
    <h3>2.7 Device &amp; Technical Data</h3>
    <p>IP address, browser user agent, session token, and timestamps of requests. Used for security and audit purposes; not used for advertising or cross-site tracking.</p>
</div>

<div class="legal-section" id="use">
    <h2>3. How We Use Your Information</h2>
    <ul>
        <li><strong>Service delivery:</strong> operating FleetForge, fulfilling Client agreements, providing customer support</li>
        <li><strong>Billing &amp; payment processing:</strong> generating invoices, processing payments to Avi Technologies, applying late fees</li>
        <li><strong>Security &amp; fraud prevention:</strong> account lockout, rate limiting, anomaly detection, audit logging</li>
        <li><strong>Compliance with legal obligations:</strong> tax records, CRA-required invoice retention (7 years), responding to lawful requests</li>
        <li><strong>Product improvement:</strong> aggregated and anonymized analytics about feature usage</li>
        <li><strong>Communications:</strong> service announcements, security notices, billing notifications</li>
    </ul>
    <p>We do <strong>not</strong> sell personal information, do <strong>not</strong> use it for advertising, and do <strong>not</strong> resell individual-level data to third parties under any circumstance.</p>
</div>

<div class="legal-section" id="basis">
    <h2>4. Legal Basis for Processing</h2>
    <p>We process personal information on the following legal bases under PIPEDA, BC&nbsp;PIPA, and (where applicable) GDPR Article 6:</p>
    <ul>
        <li><strong>Contractual necessity</strong> — to perform our agreement with the Client and provide FleetForge</li>
        <li><strong>Legitimate interests</strong> — to secure the platform, prevent fraud, and improve the service, where those interests are not overridden by your privacy rights</li>
        <li><strong>Legal obligation</strong> — to comply with Canadian tax, financial, and transportation regulations</li>
        <li><strong>Consent</strong> — for any processing where consent is the only available basis (rare in B2B context; typically captured by the Client from its end users)</li>
    </ul>
</div>

<div class="legal-section" id="gps">
    <h2>5. GPS &amp; Telematics Data</h2>
    <p>FleetForge integrates with third-party telematics providers (e.g. Samsara) at Client direction. When such integration is enabled, vehicle location, speed, odometer, and diagnostic data flow into FleetForge for fleet management purposes.</p>
    <h3>5.1 Client Responsibilities</h3>
    <p>The Client is responsible for ensuring that:</p>
    <ul>
        <li>Drivers and employees are informed of telematics monitoring (a BC&nbsp;PIPA requirement)</li>
        <li>Collection is reasonable and limited to business purposes</li>
        <li>Any applicable collective agreements or employment contracts are respected</li>
    </ul>
    <h3>5.2 Retention &amp; Use</h3>
    <p>Active GPS data is retained for 90 days; archived telematics data may be retained up to 1 year for trend analysis. Avi Technologies does <strong>not</strong> sell location data, share it with advertisers, or use it for any purpose other than providing FleetForge to the Client.</p>
</div>

<div class="legal-section" id="share">
    <h2>6. How We Share Information</h2>
    <h3>6.1 With Authorized Users</h3>
    <p>Within the Client's account, personal information is visible to other Authorized Users according to the role-based permissions configured by the Client's administrator.</p>
    <h3>6.2 Service Providers (Sub-processors)</h3>
    <p>We rely on a small number of trusted infrastructure providers:</p>
    <ul>
        <li><strong>Amazon Web Services (AWS)</strong> — application hosting, database, file storage. Data residency in Canada (ca-central-1) and the United States.</li>
        <li><strong>Samsara, Inc.</strong> — telematics data (only when Client enables the integration)</li>
        <li><strong>Anthropic PBC</strong> — AI inference for the AI assistant feature, when enabled. Prompts and responses are processed under Anthropic's zero-retention enterprise terms.</li>
        <li><strong>Sentry</strong> — error monitoring. Stack traces and user identifiers may be transmitted; PII is scrubbed before transmission where feasible.</li>
        <li><strong>Amazon Simple Email Service (SES)</strong> — transactional email delivery</li>
    </ul>
    <p>Each sub-processor is bound by contractual obligations equivalent to the protections in this Policy and the DPA.</p>
    <h3>6.3 Legal Requirements</h3>
    <p>We may disclose personal information when required by a valid court order, subpoena, or regulatory request. We will notify the Client where lawfully permitted and contest overbroad requests.</p>
    <h3>6.4 Business Transfers</h3>
    <p>If Avi Technologies is involved in a merger, acquisition, or sale of assets, personal information may transfer to the acquiring entity, subject to commercially reasonable confidentiality protections and prior notice to Clients.</p>
    <h3>6.5 Aggregated &amp; De-Identified Data</h3>
    <p>We may share aggregated or de-identified statistics that cannot reasonably be used to identify any individual.</p>
</div>

<div class="legal-section" id="retention">
    <h2>7. Data Retention</h2>
    <table>
        <thead>
            <tr><th>Category</th><th>Retention</th></tr>
        </thead>
        <tbody>
            <tr><td>Active account data</td><td>Duration of Subscription + 90 days</td></tr>
            <tr><td>Audit logs</td><td>1 year</td></tr>
            <tr><td>GPS / telematics data</td><td>90 days hot, up to 1 year archived</td></tr>
            <tr><td>Financial records (invoices, payments)</td><td>7 years (CRA requirement)</td></tr>
            <tr><td>Database backups</td><td>30 days rolling</td></tr>
            <tr><td>Email logs (delivery + bounce)</td><td>90 days</td></tr>
        </tbody>
    </table>
    <p>After the applicable retention period, personal information is deleted or irreversibly anonymized.</p>
</div>

<div class="legal-section" id="security">
    <h2>8. Data Security</h2>
    <p>We implement administrative, technical, and physical safeguards designed to protect personal information against unauthorized access, alteration, or destruction:</p>
    <ul>
        <li>Encryption at rest (AES-256) and in transit (TLS 1.2+)</li>
        <li>Role-based access controls and authentication (bcrypt password hashing, secure session cookies)</li>
        <li>Audit logging of every state-changing action</li>
        <li>Rate limiting on login and API endpoints; automatic account lockout after 5 failed login attempts</li>
        <li>Employee access on a need-to-know basis with periodic review</li>
        <li>Vulnerability scanning and patch management</li>
        <li>72-hour breach notification to Clients per PIPEDA obligations</li>
    </ul>
    <p>No system is perfectly secure. If we become aware of a breach affecting your personal information, we will notify the relevant Client (controller) without undue delay and assist with downstream notifications.</p>
</div>

<div class="legal-section" id="rights">
    <h2>9. Your Rights</h2>
    <p>Subject to applicable law (PIPEDA, BC&nbsp;PIPA, GDPR), you have the following rights with respect to your personal information:</p>
    <ul>
        <li><strong>Right of access</strong> — request a copy of the personal information we hold about you</li>
        <li><strong>Right to correction</strong> — request that we correct inaccurate or incomplete information</li>
        <li><strong>Right to withdraw consent</strong> — where processing relies on your consent</li>
        <li><strong>Right to data portability</strong> — receive a machine-readable copy of your personal information</li>
        <li><strong>Right to deletion / erasure</strong> — request deletion, subject to legal retention requirements</li>
        <li><strong>Right to object</strong> — to processing based on legitimate interests</li>
        <li><strong>Right to lodge a complaint</strong> — with the Office of the Privacy Commissioner of Canada, the BC Office of the Information &amp; Privacy Commissioner, or your local supervisory authority</li>
    </ul>
    <p>To exercise these rights, email <a href="mailto:privacy@avitechnologies.ca">privacy@avitechnologies.ca</a>. We will respond within 30 days. If you are an end user of a Client's FleetForge deployment, please contact the Client first; we will support the Client in responding to your request.</p>
</div>

<div class="legal-section" id="cookies">
    <h2>10. Cookies &amp; Tracking</h2>
    <p>FleetForge uses only the cookies strictly necessary to operate the service:</p>
    <ul>
        <li><code>ff_session</code> — authentication session (expires on browser close or after 8 hours of inactivity)</li>
        <li><code>ff_remember</code> — 30-day persistent login when the user selects "Keep me signed in"</li>
        <li><code>csrf_token</code> — CSRF protection on state-changing requests</li>
    </ul>
    <p>We do <strong>not</strong> use advertising cookies, third-party analytics (no Google Analytics, no Mixpanel), or cross-site tracking. Theme and display preferences are stored in your browser's <code>localStorage</code> — never transmitted to our servers.</p>
    <p>See the <a href="<?= e(legal_url('cookies')) ?>">Cookie Policy</a> for full details.</p>
</div>

<div class="legal-section" id="children">
    <h2>11. Children's Privacy</h2>
    <p>FleetForge is a B2B fleet management product not directed at individuals under 18. We do not knowingly collect personal information from minors. If we become aware that we have collected personal information from a minor without verifiable parental or guardian consent, we will delete it promptly.</p>
</div>

<div class="legal-section" id="transfers">
    <h2>12. International Transfers</h2>
    <p>FleetForge data is stored on Amazon Web Services infrastructure in Canada (ca-central-1) and the United States (us-east-1 / us-west-2). Transfers to the United States are governed by the US/Canada commercial relationship and, where applicable to EU data subjects, by appropriate safeguards such as the Standard Contractual Clauses (SCCs) adopted by the European Commission.</p>
</div>

<div class="legal-section" id="changes">
    <h2>13. Changes to This Policy</h2>
    <p>We may update this Privacy Policy from time to time. Material changes will be communicated to Clients via email at least 30 days before they take effect. The current version is always available at this URL and dated at the top.</p>
</div>

<div class="legal-section" id="contact">
    <h2>14. Privacy Officer &amp; Contact</h2>
    <p>Privacy Officer — Avi Technologies Inc.<br>Email: <a href="mailto:privacy@avitechnologies.ca">privacy@avitechnologies.ca</a></p>
    <p>For security disclosures: <a href="mailto:security@avitechnologies.ca">security@avitechnologies.ca</a></p>
    <p>Mailing address: Surrey, British Columbia, Canada</p>
    <p>Office of the Privacy Commissioner of Canada — <a href="https://www.priv.gc.ca" target="_blank" rel="noopener">priv.gc.ca</a><br>BC Office of the Information &amp; Privacy Commissioner — <a href="https://www.oipc.bc.ca" target="_blank" rel="noopener">oipc.bc.ca</a></p>
</div>

<?php require FF_ROOT . '/includes/legal_footer.php'; ?>
