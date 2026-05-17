<?php
declare(strict_types=1);

/**
 * app/legal/dpa.php — Data Processing Agreement (public route /legal/dpa)
 *
 * Session: S-LEGAL-FOOTER-COMMERCIAL (2026-05-17)
 */

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');

$pageTitle = 'Data Processing Agreement';
require FF_ROOT . '/includes/legal_header.php';
?>

<div class="legal-hero">
    <h1>Data Processing Agreement</h1>
    <p>Effective: January 1, 2026 &nbsp;·&nbsp; Last updated: May 17, 2026 &nbsp;·&nbsp; Supplements the <a href="<?= e(legal_url('terms')) ?>">Terms of Service</a></p>
</div>

<div class="legal-highlight">
    This Data Processing Agreement ("DPA") supplements the Terms of Service and governs Avi Technologies' processing of personal data on behalf of the Client. Where required by GDPR, BC&nbsp;PIPA, or other applicable law, this DPA serves as the contractual mechanism between data controller (Client) and data processor (Avi Technologies).
</div>

<div class="legal-toc">
    <h3>Contents</h3>
    <ol>
        <li><a href="#intro">Introduction &amp; Parties</a></li>
        <li><a href="#scope">Scope of Processing</a></li>
        <li><a href="#obligations">Processor Obligations</a></li>
        <li><a href="#subprocessors">Sub-processors</a></li>
        <li><a href="#security">Security Measures</a></li>
        <li><a href="#rights">Data Subject Rights</a></li>
        <li><a href="#breach">Breach Notification</a></li>
        <li><a href="#audit">Audit Rights</a></li>
        <li><a href="#transfers">International Transfers</a></li>
        <li><a href="#term">Term &amp; Termination</a></li>
        <li><a href="#liability">Liability</a></li>
        <li><a href="#contact">Contact</a></li>
    </ol>
</div>

<div class="legal-section" id="intro">
    <h2>1. Introduction &amp; Parties</h2>
    <p>This DPA is entered into between the <strong>Client</strong> (acting as data controller) and <strong>Avi Technologies Inc.</strong> (acting as data processor) and forms part of the Terms of Service governing the Client's use of FleetForge.</p>
    <p>This DPA applies whenever Client Data uploaded to or generated within FleetForge includes personal data, as defined under the Personal Information Protection and Electronic Documents Act (PIPEDA), the BC Personal Information Protection Act (BC&nbsp;PIPA), or — for individuals in the European Economic Area — the General Data Protection Regulation (GDPR).</p>
</div>

<div class="legal-section" id="scope">
    <h2>2. Scope of Processing</h2>
    <h3>2.1 Categories of Personal Data</h3>
    <ul>
        <li>Employee and driver records (name, contact details, role, licence numbers)</li>
        <li>Customer contacts (company representatives, billing contacts)</li>
        <li>Authorized User accounts (admin staff using FleetForge)</li>
        <li>GPS / telematics data linked to driver-operated vehicles (when integration enabled)</li>
        <li>Communications records (support messages, in-app chat, emails sent through FleetForge)</li>
    </ul>
    <h3>2.2 Categories of Data Subjects</h3>
    <ul>
        <li>Client's employees, contractors, and drivers</li>
        <li>Client's customers and their representatives</li>
        <li>Visitors to the Client's customer portal</li>
    </ul>
    <h3>2.3 Purposes of Processing</h3>
    <p>To provide FleetForge as described in the Terms of Service: fleet management, lease administration, billing, compliance tracking, reservations, customer portal access, and any reasonable supporting functions.</p>
    <h3>2.4 Duration</h3>
    <p>For the duration of the Subscription, plus the post-termination data export period (typically 30 days) and the deletion period (typically 90 days) defined in Section 5 of the Terms of Service.</p>
</div>

<div class="legal-section" id="obligations">
    <h2>3. Processor Obligations</h2>
    <p>Avi Technologies, as data processor, agrees to:</p>
    <ul>
        <li>Process personal data only on documented instructions from the Client, including with regard to international transfers, unless required by Canadian law (in which case Avi Technologies will inform the Client of the legal requirement before processing, unless prohibited by law)</li>
        <li>Ensure that persons authorized to process personal data are bound by confidentiality obligations</li>
        <li>Implement and maintain the technical and organizational security measures described in Section 5</li>
        <li>Not engage another processor (sub-processor) without compliance with Section 4</li>
        <li>Assist the Client, taking into account the nature of processing, in fulfilling its obligations to respond to data subject rights requests</li>
        <li>Assist the Client in ensuring compliance with security, breach notification, data protection impact assessment, and prior consultation obligations</li>
        <li>At the choice of the Client, delete or return all personal data after the end of the provision of services, except where Canadian law requires storage</li>
        <li>Make available to the Client all information necessary to demonstrate compliance with this DPA</li>
    </ul>
</div>

<div class="legal-section" id="subprocessors">
    <h2>4. Sub-processors</h2>
    <p>The Client provides general authorization for Avi Technologies to engage the sub-processors listed below, subject to the conditions in this section.</p>
    <h3>4.1 Current Sub-processors</h3>
    <table>
        <thead><tr><th>Sub-processor</th><th>Purpose</th><th>Location</th></tr></thead>
        <tbody>
            <tr><td>Amazon Web Services, Inc.</td><td>Application hosting, database, storage</td><td>Canada (ca-central-1), USA (us-east-1 / us-west-2)</td></tr>
            <tr><td>Samsara, Inc.</td><td>GPS / telematics integration (Client-enabled)</td><td>USA</td></tr>
            <tr><td>Anthropic, PBC</td><td>AI inference for AI assistant (Client-enabled)</td><td>USA</td></tr>
            <tr><td>Functional Software, Inc. (Sentry)</td><td>Error monitoring</td><td>USA</td></tr>
            <tr><td>Amazon SES</td><td>Transactional email delivery</td><td>Canada (ca-central-1)</td></tr>
        </tbody>
    </table>
    <h3>4.2 New Sub-processors</h3>
    <p>Avi Technologies will provide at least 30 days' written notice before adding a new sub-processor that will process personal data on behalf of the Client. The Client may object to the addition on reasonable grounds related to data protection. If the parties cannot resolve the objection within 30 days of notice, the Client may terminate the Subscription without penalty, with a pro-rated refund of prepaid fees for unused periods.</p>
    <h3>4.3 Flow-down Obligations</h3>
    <p>Avi Technologies imposes data protection obligations on each sub-processor through written agreements that are substantially equivalent to those set out in this DPA, and remains liable to the Client for the sub-processor's performance.</p>
</div>

<div class="legal-section" id="security">
    <h2>5. Security Measures</h2>
    <p>Avi Technologies implements the following technical and organizational measures:</p>
    <h3>5.1 Encryption</h3>
    <ul>
        <li>AES-256 encryption at rest for databases, file storage, and backups</li>
        <li>TLS 1.2+ for all data in transit</li>
        <li>HMAC-SHA256 signed URLs for time-limited file access</li>
    </ul>
    <h3>5.2 Access Controls</h3>
    <ul>
        <li>Role-based access control with granular permissions</li>
        <li>bcrypt password hashing (cost factor 12)</li>
        <li>Session management with httpOnly + SameSite cookies</li>
        <li>Account lockout after 5 failed login attempts</li>
        <li>IP rate limiting on authentication endpoints</li>
        <li>Audit logging of every state-changing action</li>
    </ul>
    <h3>5.3 Infrastructure</h3>
    <ul>
        <li>Hosted on AWS — physical security per AWS ISO 27001 / SOC 2 Type II certifications</li>
        <li>Network isolation, security groups, and least-privilege IAM policies</li>
        <li>Automated daily backups with 30-day retention</li>
        <li>Disaster recovery testing performed at least annually</li>
    </ul>
    <h3>5.4 Personnel</h3>
    <ul>
        <li>Background checks for employees with production access</li>
        <li>Confidentiality agreements signed by all employees and contractors</li>
        <li>Security awareness training annually</li>
        <li>Access to Client Data on a need-to-know basis with periodic review</li>
    </ul>
    <h3>5.5 Vulnerability Management</h3>
    <ul>
        <li>Automated dependency scanning on every build</li>
        <li>Penetration testing conducted at least once per year by a qualified third party</li>
        <li>Coordinated vulnerability disclosure program (<a href="mailto:security@avitechnologies.ca">security@avitechnologies.ca</a>)</li>
    </ul>
</div>

<div class="legal-section" id="rights">
    <h2>6. Data Subject Rights</h2>
    <p>Where a data subject contacts Avi Technologies directly with a rights request relating to Client Data, Avi Technologies will, where lawfully permitted, redirect the data subject to the Client and notify the Client within 5 business days.</p>
    <p>Avi Technologies will provide the Client with reasonable assistance — through technical functionality in FleetForge (data export, search, deletion features) — to enable the Client to respond to data subject access, correction, deletion, portability, and objection requests within statutory time limits.</p>
</div>

<div class="legal-section" id="breach">
    <h2>7. Breach Notification</h2>
    <p>Avi Technologies will notify the Client without undue delay, and in any event within <strong>72 hours</strong> after becoming aware, of any Personal Data Breach affecting Client Data. The notification will include, to the extent known:</p>
    <ul>
        <li>The nature of the breach, including categories and approximate numbers of data subjects and records concerned</li>
        <li>The name and contact details of the data protection point of contact</li>
        <li>The likely consequences of the breach</li>
        <li>The measures taken or proposed to address the breach and mitigate harm</li>
    </ul>
    <p>If complete information is not available within 72 hours, Avi Technologies will provide initial information and follow up with details as they become available.</p>
</div>

<div class="legal-section" id="audit">
    <h2>8. Audit Rights</h2>
    <p>The Client may audit Avi Technologies' compliance with this DPA once per calendar year, upon at least 30 days' written notice. Avi Technologies may satisfy the audit obligation by providing copies of recent third-party audit reports (SOC 2 Type II, ISO 27001) where available.</p>
    <p>Audits must be conducted during business hours, must not unreasonably interfere with Avi Technologies' operations, must comply with confidentiality obligations, and are at the Client's expense (except where the audit reveals material non-compliance, in which case reasonable costs are borne by Avi Technologies).</p>
</div>

<div class="legal-section" id="transfers">
    <h2>9. International Transfers</h2>
    <p>Client Data is primarily processed in Canada (AWS ca-central-1) with backup and redundancy infrastructure in the United States. For Client Data subject to GDPR, transfers outside the EEA are governed by the Standard Contractual Clauses (Commission Decision 2021/914), incorporated into this DPA by reference where applicable.</p>
</div>

<div class="legal-section" id="term">
    <h2>10. Term &amp; Termination</h2>
    <p>This DPA takes effect at the same time as the Terms of Service and continues until those Terms terminate or expire. Upon termination, Avi Technologies will, at the Client's choice, delete or return all Client Data within 90 days, except where Canadian law requires longer retention (e.g. financial records under CRA rules).</p>
</div>

<div class="legal-section" id="liability">
    <h2>11. Liability</h2>
    <p>Each party's liability arising out of or in connection with this DPA is subject to the limitations of liability set out in the Terms of Service.</p>
</div>

<div class="legal-section" id="contact">
    <h2>12. Contact</h2>
    <p>Data Protection Point of Contact — Avi Technologies Inc.<br>
    Email: <a href="mailto:privacy@avitechnologies.ca">privacy@avitechnologies.ca</a><br>
    Address: Surrey, British Columbia, Canada</p>
</div>

<?php require FF_ROOT . '/includes/legal_footer.php'; ?>
